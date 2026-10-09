<?php
/**
 * Login devices: recognizing the browser and a notice for a new device
 * (developer docs, chapter 13 (Security: authentication)). For admins (backend) and frontend users.
 *
 * HOW A DEVICE IS RECOGNIZED
 * --------------------
 * After every successful login the browser gets a cookie
 * (security.device_cookie, default 'c9dev') with a random value; `login_device`
 * stores only its hash() (HMAC with .camindo.key) per account - whoever reads the table
 * cannot build a cookie from it. A cookie applies to all accounts that log in
 * in this browser; a device is known per account.
 *
 *     cookie present, row for the account present   known device - update last_seen_at, ip, label
 *     otherwise                                     new device - create row; if the account
 *                                                   already has other devices, notification
 *                                                   (LoginNotifierInterface, event 'new_device')
 * The very first login of an account (no row yet) reports nothing: otherwise everyone
 * would get a notice after registration or after this table was introduced.
 *
 * Devices that were not used for security.device_days are deleted and count
 * as new again afterwards; the cookie runs just as long and is extended with every
 * login.
 *
 * No second factor: a known device does NOT skip the two-factor login
 * (decision 2026-09-24). The cookie is a recognition feature, not a login secret.
 *
 * Whoever deletes cookies or works in a private window appears as a
 * new device every time - that is intended: an attacker would look exactly the same.
 *
 * The column `ip` holds the IP truncated from the start (ClientIp::anonymize(),
 * privacy.ip_v4_octets) - for "roughly from where" the network is enough. The full address
 * is only in the notification to the owner of the account.
 *
 * MANAGING DEVICES (backend)
 * --------------------
 * Every admin session hangs on the device from which it was created
 * (admin_session.device_id, Admin::_loginComplete()). listFor() shows the devices
 * of an account, remove()/removeAll() delete them - and with them, via foreign key,
 * the sessions on them. A removed device counts as new again at the next login
 * (notice by email). Interface: AdminBackend::devices().
 */
namespace Camindo\Core;

class LoginDevices {

    public function __construct(
        private Config $config,
        private Database $db,
        private Crypt $crypt,
        private System $system,
        private ContainerInterface $container,
    )
    {
    }

    /**
     * Device, IP and time of the running request - for a notification
     * (notify()) and register(). The IP via ClientIp: behind a proxy from
     * security.trusted_proxies the one of the visitor, not that of the proxy.
     *
     * @return array  label, ip (untruncated), time (UTC)
     */
    public function context(): array
    {
        return [
            'label' => self::label((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'ip'    => mb_substr($this->container->get(ClientIp::class)->ip(), 0, 45),
            'time'  => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Record a device for a successful login, set the cookie, notify if needed.
     * Must run before output is sent (cookie) - in the frontend therefore at the template
     * start, just like USER_LOGIN itself.
     *
     * @param string $kind       'admin' or 'user'
     * @param int    $accountId  admin_id or record_id of the profile
     * @param array  $account    record for the notifier (email, name, settings)
     * @return array  new (bool: unknown device of an account that already had devices),
     *                first (bool: first device of the account), label, ip, time
     */
    public function register(string $kind, int $accountId, array $account): array
    {
        $column = $kind === 'admin' ? 'admin_id' : 'record_id';
        $time = gmdate('Y-m-d H:i:s');
        $device = [
            'device_id' => 0,
            'new'   => false,
            'first' => false,
        ] + $this->context();
        $device['time'] = $time;
        if ($accountId < 1) return $device;
        // Only the truncated IP is stored (privacy.ip_v4_octets) - the full one
        // goes solely into the notification to the owner of the account
        $storedIp = ClientIp::anonymize($device['ip'], ClientIp::v4Octets($this->config));

        $days = max(1, (int)$this->config->get('security.device_days'));
        $token = $this->cookieToken();
        if ($token === '') $token = $this->crypt->randomKey(32);
        $this->setCookie($token, $kind, $days);
        $hash = $this->crypt->hash($token, true);

        $this->db->pushResult();
        try {
            // Clear out expired devices (of all accounts)
            $this->db->query(
                "DELETE FROM `login_device` WHERE `last_seen_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)",
                'i', [$days]
            );
            // Look up the identifier under all keys (after a key rotation the HMAC
            // of the old key is still there) and rewrite it to the current one
            $hashes = $this->crypt->hashAll($token, true);
            $this->db->query(
                "SELECT `device_id` FROM `login_device` WHERE `$column` = ? AND `token_hash` IN ("
                . implode(',', array_fill(0, count($hashes), '?')) . ")",
                'i' . str_repeat('s', count($hashes)), array_merge([$accountId], $hashes)
            );
            $deviceId = (int)($this->db->fetchNum()[0] ?? 0);
            if ($deviceId > 0) {
                $this->db->query(
                    "UPDATE `login_device` SET `last_seen_at` = UTC_TIMESTAMP(), `ip` = ?, `label` = ?, `token_hash` = ? WHERE `device_id` = ?",
                    'sssi', [$storedIp, $device['label'], $hash, $deviceId]
                );
                $device['device_id'] = $deviceId;
                return $device;
            }
            $this->db->query("SELECT COUNT(*) FROM `login_device` WHERE `$column` = ?", 'i', [$accountId]);
            $device['first'] = (int)($this->db->fetchNum()[0] ?? 0) === 0;
            $device['new'] = !$device['first'];
            $this->db->query(
                "INSERT INTO `login_device` (`$column`, `token_hash`, `label`, `ip`, `created_at`, `last_seen_at`)"
                . " VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                'isss', [$accountId, $hash, $device['label'], $storedIp]
            );
            $device['device_id'] = (int)$this->db->insert_id;
        }
        finally {
            $this->db->popResult();
        }

        if ($device['new'] && $this->config->get('security.device_notify')) {
            $this->notify('new_device', $kind, $account, $device);
        }
        return $device;
    }

    /**
     * Does the account know this device (cookie + row, within
     * security.device_days)? Records nothing - for the decision whether a
     * login needs a second factor (profile areas with second_factor
     * 'new_device', developer docs, chapter 14 (Security: web). The first device of an account
     * is also unknown.
     */
    public function isKnown(string $kind, int $accountId): bool
    {
        return $this->knownDeviceId($kind, $accountId) > 0;
    }

    /**
     * Like isKnown(), but returns the device_id (0 = unknown) - for a separate
     * failed-attempt counter per device (Admin::guardKey()).
     */
    public function knownDeviceId(string $kind, int $accountId): int
    {
        $token = $this->cookieToken();
        if ($token === '' || $accountId < 1) return 0;
        $column = $kind === 'admin' ? 'admin_id' : 'record_id';
        $days = max(1, (int)$this->config->get('security.device_days'));
        $hashes = $this->crypt->hashAll($token, true);
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `device_id` FROM `login_device` WHERE `$column` = ? AND `token_hash` IN ("
                . implode(',', array_fill(0, count($hashes), '?')) . ")"
                . " AND `last_seen_at` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY) LIMIT 1",
                'i' . str_repeat('s', count($hashes)) . 'i', array_merge([$accountId], $hashes, [$days])
            );
            return $this->db->num_rows > 0 ? (int)($this->db->fetchNum()[0] ?? 0) : 0;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Known devices of an account, most recently used first.
     *
     * @param string $kind  'admin' or 'user'
     * @return array        list of device_id, label, ip, created_at, last_seen_at (UTC)
     */
    public function listFor(string $kind, int $accountId): array
    {
        $column = $kind === 'admin' ? 'admin_id' : 'record_id';
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `device_id`, `label`, `ip`, `created_at`, `last_seen_at` FROM `login_device`"
                . " WHERE `$column` = ? ORDER BY `last_seen_at` DESC",
                'i', [$accountId]
            );
            $rows = [];
            while ($row = $this->db->fetch()) {
                $row['device_id'] = (int)$row['device_id'];
                $rows[] = $row;
            }
            return $rows;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Remove a device of an account - its sessions end with it (foreign key).
     *
     * @return bool  false: does not exist or belongs to another account
     */
    public function remove(string $kind, int $accountId, int $deviceId): bool
    {
        $column = $kind === 'admin' ? 'admin_id' : 'record_id';
        $this->db->pushResult();
        try {
            $this->db->query("DELETE FROM `login_device` WHERE `device_id` = ? AND `$column` = ?",
                'ii', [$deviceId, $accountId]);
            return $this->db->affected_rows === 1;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Remove all devices of an account.
     *
     * @return int  number of removed devices
     */
    public function removeAll(string $kind, int $accountId): int
    {
        $column = $kind === 'admin' ? 'admin_id' : 'record_id';
        $this->db->pushResult();
        try {
            $this->db->query("DELETE FROM `login_device` WHERE `$column` = ?", 'i', [$accountId]);
            return (int)$this->db->affected_rows;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Pass the event on to the notifier (security.login_notifier). Errors end up
     * in the log 'security', the login or storage continues.
     */
    public function notify(string $event, string $kind, array $account, array $context): void
    {
        $class = trim((string)$this->config->get('security.login_notifier')) ?: LoginNotifier::class;
        try {
            $notifier = $this->container->get($class);
            if (!$notifier instanceof LoginNotifierInterface) {
                throw new \RuntimeException("$class does not implement LoginNotifierInterface");
            }
            $notifier->notify($event, $kind, $account, $context);
        }
        catch (\Throwable $e) {
            $this->system->log('security', "login notifier ($event, $kind): " . $e->getMessage());
        }
    }

    /**
     * Short description from the User-Agent: "Firefox on macOS". Deliberately rough -
     * it should make the device recognizable to the recipient of the notification, not
     * track versions.
     */
    public static function label(string $userAgent): string
    {
        $browser = match (true) {
            (bool)preg_match('~Edg(e|A|iOS)?/~', $userAgent)       => 'Edge',
            (bool)preg_match('~OPR/|Opera~', $userAgent)            => 'Opera',
            (bool)preg_match('~SamsungBrowser/~', $userAgent)       => 'Samsung Internet',
            (bool)preg_match('~Firefox/|FxiOS/~', $userAgent)       => 'Firefox',
            (bool)preg_match('~Chrome/|CriOS/~', $userAgent)        => 'Chrome',
            (bool)preg_match('~Safari/~', $userAgent)               => 'Safari',
            (bool)preg_match('~curl/|Wget/|python~i', $userAgent)   => 'Script',
            default                                                 => '',
        };
        $os = match (true) {
            (bool)preg_match('~iPhone|iPad|iPod~', $userAgent)      => 'iOS',
            (bool)preg_match('~Android~', $userAgent)               => 'Android',
            (bool)preg_match('~Windows~', $userAgent)               => 'Windows',
            (bool)preg_match('~Mac OS X|Macintosh~', $userAgent)    => 'macOS',
            (bool)preg_match('~CrOS~', $userAgent)                  => 'ChromeOS',
            (bool)preg_match('~Linux~', $userAgent)                 => 'Linux',
            default                                                 => '',
        };
        $label = trim($browser . ($browser !== '' && $os !== '' ? ' / ' : '') . $os);
        if ($label === '') $label = mb_substr(trim($userAgent), 0, 60);
        return $label !== '' ? $label : '?';
    }

    /**
     * Value of the device cookie if it has the format of a randomKey(32), otherwise ''.
     */
    private function cookieToken(): string
    {
        $value = (string)($_COOKIE[$this->cookieName()] ?? '');
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $value) ? $value : '';
    }

    private function cookieName(): string
    {
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$this->config->get('security.device_cookie')));
        return $name !== '' ? $name : 'c9dev';
    }

    /**
     * Set or extend the device cookie: HttpOnly, SameSite Lax, 'secure' like the
     * session cookie of the area (admin.cookie_secure / user.cookie_secure).
     */
    private function setCookie(string $token, string $kind, int $days): void
    {
        $_COOKIE[$this->cookieName()] = $token;
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        setcookie($this->cookieName(), $token, [
            'expires'  => time() + $days * 86400,
            'path'     => '/',
            'secure'   => !empty($this->config->get(($kind === 'admin' ? 'admin' : 'user') . '.cookie_secure')),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
