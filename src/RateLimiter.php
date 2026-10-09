<?php
/**
 * Throttling: how often may something happen within a time window?
 * Developer docs, chapter 14 (Security: web).
 *
 * Unlike LoginGuard, which only counts FAILED attempts and clears on success,
 * the RateLimiter counts EVERY request - meant for actions that already cause costs
 * on success: an email to a freely entered address ("forgot password",
 * registration, contact form), an entry in the log.
 *
 * BUCKETS
 * --------------------
 * A bucket is a rule with a limit and a time window from user.throttle.{bucket}
 * ([at most, seconds], at most 0 = off). Buckets with the prefix 'admin_' belong
 * to the backend and are in admin.throttle.{rest} (admin_login_ip →
 * admin.throttle.login_ip). Within a bucket, counting is done per key - the
 * entered address, the origin (ClientIp::rateKey()), the instance.
 *
 * ONLY HASHES IN THE TABLE
 * --------------------
 * `rate_limit` stores the key as an HMAC (Crypt::hash("{bucket}|{key}")) -
 * neither IP addresses nor entered email addresses are stored in plain text in it.
 * Anyone reading the table cannot tell who clicked "forgot password".
 *
 * SLIDING WINDOW, LEAN TABLE
 * --------------------
 * One row per counted request. Before each count, the rows of the bucket that are
 * older than its window disappear; occasionally (1 %) everything older than the
 * longest window. A rejected request is NOT counted - whoever knocks against the
 * lock neither fills the table nor extends the lock.
 *
 * Only the transition into the lock is logged (log 'security'), as with
 * LoginGuard.
 *
 * @see Camindo\Core\ClientIp
 * @see Camindo\Core\LoginGuard
 */
namespace Camindo\Core;

class RateLimiter
{
    public function __construct(
        private Config $config,
        private Database $db,
        private Crypt $crypt,
        private System $system,
    ) {}

    /**
     * Count the request if it is allowed.
     *
     * @return bool  true = allowed (and counted), false = limit reached
     */
    public function hit(string $bucket, string $key): bool
    {
        [$max, $seconds] = $this->limits($bucket);
        if ($max < 1) return true;
        $hash = $this->keyHash($bucket, $key);
        $this->db->pushResult();
        try {
            $this->purge($bucket, $seconds);
            $count = $this->count($bucket, $hash, $seconds);
            if ($count >= $max) return false;
            $this->insert($bucket, $hash);
            if ($count + 1 === $max) $this->logLimit($bucket, $max, $seconds);
            return true;
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Would the next request be allowed? (does not count) */
    public function allows(string $bucket, string $key): bool
    {
        [$max, $seconds] = $this->limits($bucket);
        if ($max < 1) return true;
        $this->db->pushResult();
        try {
            $this->purge($bucket, $seconds);
            return $this->count($bucket, $this->keyHash($bucket, $key), $seconds) < $max;
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Count a request without checking it (e.g. a failed attempt after allows()) */
    public function record(string $bucket, string $key): void
    {
        [$max, $seconds] = $this->limits($bucket);
        if ($max < 1) return;
        $hash = $this->keyHash($bucket, $key);
        $this->db->pushResult();
        try {
            $this->insert($bucket, $hash);
            if ($this->count($bucket, $hash, $seconds) === $max) $this->logLimit($bucket, $max, $seconds);
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Clear the counter of a key */
    public function clear(string $bucket, string $key): void
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                "DELETE FROM `rate_limit` WHERE `bucket` = ? AND `key_hash` = ?",
                'ss', [$bucket, $this->keyHash($bucket, $key)]
            );
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Limit and time window of a bucket from user.throttle.{bucket}, or
     * admin.throttle.{rest} for buckets 'admin_…'.
     *
     * @return array{0:int,1:int}  [at most, seconds]
     */
    public function limits(string $bucket): array
    {
        $value = $this->config->get(self::configKey($bucket));
        if (!is_array($value)) return [0, 1];
        $value = array_values($value);
        return [(int)($value[0] ?? 0), max(1, (int)($value[1] ?? 0))];
    }

    /**
     * Does the bucket exist in user.throttle? The backend buckets ('admin_…') do not
     * count - CMS:EMAIL throttle=name must not be able to use them.
     */
    public function hasBucket(string $bucket): bool
    {
        return !str_starts_with($bucket, 'admin_') && is_array($this->config->get('user.throttle.' . $bucket));
    }

    private static function configKey(string $bucket): string
    {
        return str_starts_with($bucket, 'admin_')
            ? 'admin.throttle.' . substr($bucket, 6)
            : 'user.throttle.' . $bucket;
    }

    private function keyHash(string $bucket, string $key): string
    {
        return $this->crypt->hash($bucket . '|' . $key, true);
    }

    private function count(string $bucket, string $hash, int $seconds): int
    {
        $this->db->query(
            "SELECT COUNT(*) FROM `rate_limit` WHERE `bucket` = ? AND `key_hash` = ?"
            . " AND `created_at` >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ? SECOND)",
            'ssi', [$bucket, $hash, $seconds]
        );
        return (int)($this->db->fetchNum()[0] ?? 0);
    }

    private function insert(string $bucket, string $hash): void
    {
        $this->db->query(
            "INSERT INTO `rate_limit` (`bucket`, `key_hash`, `created_at`) VALUES (?, ?, UTC_TIMESTAMP(3))",
            'ss', [$bucket, $hash]
        );
    }

    /**
     * Delete expired rows of the bucket; occasionally also everything older than
     * the longest window (buckets that nobody is using at the moment).
     */
    private function purge(string $bucket, int $seconds): void
    {
        $this->db->query(
            "DELETE FROM `rate_limit` WHERE `bucket` = ? AND `created_at` < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ? SECOND)",
            'si', [$bucket, $seconds]
        );
        if (random_int(1, 100) === 1) {
            $longest = 86400;
            $all = array_merge(array_values((array)$this->config->get('user.throttle')), array_values((array)$this->config->get('admin.throttle')));
            foreach ($all as $limits) {
                if (is_array($limits)) $longest = max($longest, (int)(array_values($limits)[1] ?? 0));
            }
            $this->db->query(
                "DELETE FROM `rate_limit` WHERE `created_at` < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ? SECOND)",
                'i', [$longest]
            );
        }
    }

    private function logLimit(string $bucket, int $max, int $seconds): void
    {
        $this->system->log('security', sprintf(
            'Rate limit reached: bucket "%s", %d requests within %d seconds. URI: %s',
            $bucket, $max, $seconds, (string)($_SERVER['REQUEST_URI'] ?? '')
        ));
    }
}
