<?php
/**
 * class LoginGuard
 *
 * Brute-force protection of the backend login. Counts failed attempts per
 * login name and blocks the name if there are too many in too short a time.
 *
 * Counted are wrong passwords AND wrong codes of the second factor (email,
 * authenticator app, recovery code - Admin::loginByCode(), AdminBackend::
 * totp()) on the same name. The counter is only cleared after the complete
 * login, not already after the correct password: otherwise a
 * six-digit TOTP code could be guessed arbitrarily often by alternating password + code.
 *
 * WHY UNKNOWN NAMES ARE COUNTED TOO
 * --------------------
 * The entered name is counted, not the account found - even a name that does
 * not exist at all ends up in the table. Otherwise the login would behave differently
 * for an existing account after three attempts than for an invented one, and
 * exactly that would reveal which accounts exist. For the same reason
 * Admin::loginByName() never states the reason for the failure.
 *
 * THE TABLE KEEPS ITSELF LEAN
 * --------------------
 * One row per failed attempt, no counter field. Before every evaluation everything
 * older than admin.bruteforce_delay is removed (purge()) - the table therefore
 * always contains only the running time window. A sliding window thus results
 * by itself: older attempts expire individually, the newer ones keep counting.
 *
 * CONFIGURATION (.camindo.php, defaults see Config)
 * --------------------
 *     admin.bruteforce_max     allowed failed attempts in the time window (3).
 *                              0 or less switches the protection off.
 *     admin.bruteforce_delay   length of the time window in seconds (600).
 *     admin.bruteforce_ip      also record and log the IP address (false).
 *                              Off by default, because the IP is personal
 *                              data - counting is done per name anyway.
 *     user.bruteforce_*        the same for the frontend login (forUsers())
 *
 * WHAT IS LOGGED
 * --------------------
 * System::log('security', ...) gets a line when a block STARTS - not
 * on every rejected attempt. Otherwise an attacker could fill the log file alone
 * by continuing to knock against the block.
 *
 * TWO LOGINS, ONE CLASS
 * --------------------
 * The frontend login of the community (CMS:USER_LOGIN, RecordUser) needs
 * the same protection, but keeps its failed attempts in a separate table
 * `user_login_attempt` with its own limits (user.bruteforce_*). forUsers()
 * returns a second instance with the switched table and configuration for this;
 * the code is the same.
 *
 * @see Camindo\Core\Modules\Admin\Admin::loginByName()  Aufruferin (Backend)
 * @see Camindo\Core\Modules\Record\RecordUser::cmsLogin() Aufruferin (Frontend)
 * @see Camindo\Core\Session                             Sitzungsverwaltung
 */
namespace Camindo\Core;

class LoginGuard {

    /** Table with the failed attempts of the running time window */
    private string $table = 'admin_login_attempt';
    /** Configuration section of the limits: 'admin' or 'user' */
    private string $configSection = 'admin';

    /** Column width of `name` - longer input is truncated, not rejected */
    private const NAME_MAX = 64;

    public function __construct(
        private Config $config,
        private Database $db,
        private System $system,
        private ClientIp $clientIp
    )
    {
    }

    /**
     * Variant for the frontend login: table `user_login_attempt`,
     * limits from user.bruteforce_* (see class comment).
     */
    public function forUsers(): static
    {
        $guard = clone $this;
        $guard->table = 'user_login_attempt';
        $guard->configSection = 'user';
        return $guard;
    }

    /**
     * Is this name currently blocked?
     *
     * Called BEFORE the check of name and password - a block should apply
     * without checking the credentials at all.
     *
     * @param string $name  entered login name, raw
     * @return int          remaining block time in seconds, 0 = not blocked
     */
    public function blockedFor(string $name): int
    {
        $max = $this->maxAttempts();
        if ($max < 1) return 0;   // protection switched off

        $name = $this->normalizeName($name);
        if ($name === '') return 0;

        $this->db->pushResult();
        try {
            $this->purge();
            $attempts = $this->countAttempts($name);

            // Blocked only ABOVE the allowed number: at exactly $max
            // failed attempts one last attempt is still free (see the class comment
            // of Admin and the note in loginByName()).
            if ($attempts <= $max) return 0;

            // The block ends as soon as so many rows have expired that at most
            // $max remain. What matters is therefore not the oldest row
            // but the ($attempts - $max)-oldest one - its expiry brings the counter
            // down to exactly $max.
            $offset = $attempts - $max - 1;
            $this->db->query(
                "SELECT TIMESTAMPDIFF(SECOND, `attempt_date`, UTC_TIMESTAMP()) AS `age`"
                . " FROM `" . $this->table . "` WHERE `name` = ?"
                . " ORDER BY `attempt_date` ASC LIMIT 1 OFFSET ?",
                'si',
                [$name, $offset]
            );
            $row = $this->db->fetch();
            if ($row === null) return 0;

            $remaining = $this->delaySeconds() - (int)$row['age'];

            // Never return 0 while the block is in place: 0 means "free".
            return $remaining > 0 ? $remaining : 1;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Record a failed attempt.
     *
     * @param string $name  entered login name, raw
     * @return int          failed attempts in the time window INCLUDING this one
     */
    public function recordFailure(string $name): int
    {
        $max = $this->maxAttempts();
        if ($max < 1) return 0;   // protection switched off

        $name = $this->normalizeName($name);
        if ($name === '') return 0;

        $ip = $this->clientIp();

        $this->db->pushResult();
        try {
            $this->db->query(
                "INSERT INTO `" . $this->table . "` (`name`, `ip`, `attempt_date`)"
                . " VALUES (?, ?, UTC_TIMESTAMP())",
                'ss',
                [$name, $ip]
            );
            $attempts = $this->countAttempts($name);
        }
        finally {
            $this->db->popResult();
        }

        // Only the transition is logged - the attempt that triggers the block.
        // Every further one bounces off blockedFor() and writes nothing more.
        if ($attempts == $max + 1) {
            $this->system->log('security', sprintf(
                'Login blocked for %s seconds. Name: "%s", %d failed attempts within %d seconds.%s',
                $this->delaySeconds(),
                $name,
                $attempts,
                $this->delaySeconds(),
                $ip === '' ? '' : ' IP: ' . $ip
            ));
        }

        return $attempts;
    }

    /**
     * Clear the counter of a name - after a successful login (with a second factor:
     * only after the correct code).
     *
     * Without this, someone who has mistyped twice would stay close to the block
     * until the time window expires.
     */
    public function clear(string $name): void
    {
        $name = $this->normalizeName($name);
        if ($name === '') return;

        $this->db->pushResult();
        try {
            $this->db->query("DELETE FROM `" . $this->table . "` WHERE `name` = ?", 's', [$name]);
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Failed attempts of a name in the running time window.
     *
     * Relies on purge() having already removed the expired rows -
     * hence without its own time condition. If the caller only calls recordFailure(),
     * the counter is at most too large for the duration of one request, because blockedFor()
     * runs first on every login attempt.
     */
    private function countAttempts(string $name): int
    {
        $this->db->query(
            "SELECT COUNT(*) FROM `" . $this->table . "` WHERE `name` = ?",
            's',
            [$name]
        );
        $row = $this->db->fetchNum();
        return (int)($row[0] ?? 0);
    }

    /**
     * Delete expired failed attempts - for ALL names, not only the one checked.
     *
     * This is the only cleanup point: the table has no cron job and would otherwise
     * keep growing with every typo. Since one deletion happens on every login attempt,
     * it stays as large as the time window - not larger.
     */
    private function purge(): void
    {
        $this->db->query(
            "DELETE FROM `" . $this->table . "`"
            . " WHERE `attempt_date` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)",
            'i',
            [$this->delaySeconds()]
        );
    }

    /**
     * Bring the name into the form in which it is stored in the table.
     *
     * Truncated to the column width: an overlong name should not run into an
     * SQL error but simply count as a failed attempt. Lowercase, because the column
     * `admin`.`name` is compared case-insensitively - otherwise
     * "Dirk" and "dirk" would be the same user for the login, but two different
     * names for the block.
     */
    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') return '';
        return mb_strtolower(mb_substr($name, 0, self::NAME_MAX));
    }

    /**
     * IP of the caller - empty if admin.bruteforce_ip is not set.
     *
     * X-Forwarded-For only counts from proxies in security.trusted_proxies (ClientIp) -
     * the header can be set freely, and whoever sets it could otherwise invent a
     * new origin for every attempt.
     */
    private function clientIp(): string
    {
        if (empty($this->config->get($this->configSection . '.bruteforce_ip'))) return '';
        return mb_substr($this->clientIp->ip(), 0, 45);
    }

    /** Allowed failed attempts in the time window; less than 1 switches the protection off. */
    private function maxAttempts(): int
    {
        return (int)$this->config->get($this->configSection . '.bruteforce_max');
    }

    /** Length of the time window in seconds, at least 1. */
    private function delaySeconds(): int
    {
        $delay = (int)$this->config->get($this->configSection . '.bruteforce_delay');
        return $delay > 0 ? $delay : 1;
    }
}
