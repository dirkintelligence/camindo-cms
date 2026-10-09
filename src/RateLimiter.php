<?php
/**
 * Drosselung: wie oft darf etwas in einem Zeitfenster passieren?
 * developer_docs/20-frontend-schutz.md A.
 *
 * Anders als LoginGuard, der nur FEHLVERSUCHE zählt und nach dem Erfolg leert,
 * zählt der RateLimiter JEDE Anfrage - gedacht für Aktionen, die schon im Erfolgsfall
 * Kosten verursachen: eine E-Mail an eine frei eingegebene Adresse ("Passwort
 * vergessen", Registrierung, Kontaktformular), ein Eintrag im Log.
 *
 * BUCKETS
 * --------------------
 * Ein Bucket ist eine Regel mit Grenze und Zeitfenster aus user.throttle.{bucket}
 * ([höchstens, Sekunden], höchstens 0 = aus). Buckets mit dem Präfix 'admin_' gehören
 * dem Backend und stehen in admin.throttle.{Rest} (admin_login_ip →
 * admin.throttle.login_ip). Innerhalb eines Buckets wird je Schlüssel gezählt - der
 * eingegebenen Adresse, der Herkunft (ClientIp::rateKey()), der Instanz.
 *
 * NUR HASHES IN DER TABELLE
 * --------------------
 * `rate_limit` speichert den Schlüssel als HMAC (Crypt::hash("{bucket}|{key}")) -
 * weder IP-Adressen noch eingegebene E-Mail-Adressen stehen im Klartext darin. Wer
 * die Tabelle liest, erfährt nicht, wer "Passwort vergessen" angeklickt hat.
 *
 * GLEITENDES FENSTER, SCHLANKE TABELLE
 * --------------------
 * Eine Zeile je gezählter Anfrage. Vor jeder Zählung verschwinden die Zeilen des
 * Buckets, die älter als sein Fenster sind; gelegentlich (1 %) alles, was älter als
 * das längste Fenster ist. Eine abgewiesene Anfrage wird NICHT gezählt - wer gegen die
 * Sperre klopft, füllt weder die Tabelle noch verlängert er die Sperre.
 *
 * Protokolliert wird nur der Übergang in die Sperre (Log 'security'), wie bei
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
     * Anfrage zählen, wenn sie erlaubt ist.
     *
     * @return bool  true = erlaubt (und gezählt), false = Grenze erreicht
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

    /** Wäre die nächste Anfrage erlaubt? (zählt nicht) */
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

    /** Anfrage zählen, ohne zu prüfen (z. B. einen Fehlversuch nach allows()) */
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

    /** Zähler eines Schlüssels leeren */
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
     * Grenze und Zeitfenster eines Buckets aus user.throttle.{bucket} bzw.
     * admin.throttle.{Rest} für Buckets 'admin_…'.
     *
     * @return array{0:int,1:int}  [höchstens, Sekunden]
     */
    public function limits(string $bucket): array
    {
        $value = $this->config->get(self::configKey($bucket));
        if (!is_array($value)) return [0, 1];
        $value = array_values($value);
        return [(int)($value[0] ?? 0), max(1, (int)($value[1] ?? 0))];
    }

    /**
     * Gibt es den Bucket in user.throttle? Die Backend-Buckets ('admin_…') zählen
     * nicht mit - CMS:EMAIL throttle=name soll sie nicht mitbenutzen können.
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
     * Abgelaufene Zeilen des Buckets löschen; gelegentlich auch alles, was älter als
     * das längste Fenster ist (Buckets, die gerade niemand benutzt).
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
