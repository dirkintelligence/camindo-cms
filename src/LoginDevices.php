<?php
/**
 * Anmeldegeräte: Wiedererkennen des Browsers und Hinweis bei einem neuen Gerät
 * (16-sicherheit.md §11). Für Admins (Backend) und Frontend-User.
 *
 * WIE EIN GERÄT ERKANNT WIRD
 * --------------------
 * Nach jeder erfolgreichen Anmeldung bekommt der Browser ein Cookie
 * (security.device_cookie, Vorgabe 'c9dev') mit einem Zufallswert; `login_device`
 * speichert nur dessen hash() (HMAC mit .camindo.key) je Konto - wer die Tabelle
 * liest, kann damit kein Cookie bauen. Ein Cookie gilt für alle Konten, die sich
 * in diesem Browser anmelden; bekannt ist ein Gerät je Konto.
 *
 *     Cookie da, Zeile zum Konto da   bekanntes Gerät - last_seen_at, ip, label fortschreiben
 *     sonst                           neues Gerät - Zeile anlegen; hat das Konto
 *                                     schon andere Geräte, Benachrichtigung
 *                                     (LoginNotifierInterface, Ereignis 'new_device')
 * Die allererste Anmeldung eines Kontos (noch keine Zeile) meldet nichts: sonst bekäme
 * jeder nach der Registrierung bzw. nach dem Einführen dieser Tabelle einen Hinweis.
 *
 * Geräte, die security.device_days nicht benutzt wurden, werden gelöscht und gelten
 * danach wieder als neu; das Cookie läuft genauso lange und wird bei jeder
 * Anmeldung verlängert.
 *
 * Kein zweiter Faktor: ein bekanntes Gerät überspringt die Zwei-Faktor-Anmeldung
 * NICHT (Entscheidung 2026-09-24). Das Cookie ist ein Wiedererkennungsmerkmal, kein
 * Anmeldegeheimnis.
 *
 * Wer Cookies löscht oder im privaten Fenster arbeitet, erscheint jedes Mal als
 * neues Gerät - das ist gewollt: genau so sähe auch ein Angreifer aus.
 *
 * Die Spalte `ip` hält die IP von Beginn an gekürzt (ClientIp::anonymize(),
 * privacy.ip_v4_octets) - für "von wo ungefähr" reicht das Netz. Die volle Adresse
 * steht nur in der Benachrichtigung an den Inhaber des Kontos.
 *
 * GERÄTE VERWALTEN (Backend)
 * --------------------
 * Jede Admin-Session hängt an dem Gerät, von dem aus sie entstand
 * (admin_session.device_id, Admin::_loginComplete()). listFor() zeigt die Geräte
 * eines Kontos, remove()/removeAll() löschen sie - und mit ihnen per Fremdschlüssel
 * die Sessions darauf. Ein entferntes Gerät gilt bei der nächsten Anmeldung wieder
 * als neu (Hinweis per E-Mail). Oberfläche: AdminBackend::devices().
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
     * Gerät, IP und Zeit des laufenden Requests - für eine Benachrichtigung
     * (notify()) und register(). Die IP über ClientIp: hinter einem Proxy aus
     * security.trusted_proxies die des Besuchers, nicht die des Proxys.
     *
     * @return array  label, ip (ungekürzt), time (UTC)
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
     * Gerät zu einer erfolgreichen Anmeldung verbuchen, Cookie setzen, ggf.
     * benachrichtigen. Muss laufen, bevor Ausgabe gesendet wurde (Cookie) - im
     * Frontend also am Template-Anfang wie USER_LOGIN selbst.
     *
     * @param string $kind       'admin' oder 'user'
     * @param int    $accountId  admin_id bzw. record_id des Profils
     * @param array  $account    Datensatz für den Notifier (E-Mail, Name, Einstellungen)
     * @return array  new (bool: unbekanntes Gerät eines Kontos, das schon Geräte
     *                hatte), first (bool: erstes Gerät des Kontos), label, ip, time
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
        // Gespeichert wird nur die gekürzte IP (privacy.ip_v4_octets) - die volle
        // geht allein in die Benachrichtigung an den Inhaber des Kontos
        $storedIp = ClientIp::anonymize($device['ip'], ClientIp::v4Octets($this->config));

        $days = max(1, (int)$this->config->get('security.device_days'));
        $token = $this->cookieToken();
        if ($token === '') $token = $this->crypt->randomKey(32);
        $this->setCookie($token, $kind, $days);
        $hash = $this->crypt->hash($token, true);

        $this->db->pushResult();
        try {
            // Verfallene Geräte (aller Konten) abräumen
            $this->db->query(
                "DELETE FROM `login_device` WHERE `last_seen_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)",
                'i', [$days]
            );
            // Kennung unter allen Schlüsseln suchen (nach einer Schlüsselrotation
            // steht noch der HMAC des alten da) und auf den aktuellen umschreiben
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
     * Kennt das Konto dieses Gerät (Cookie + Zeile, innerhalb von
     * security.device_days)? Verbucht nichts - für die Entscheidung, ob eine
     * Anmeldung einen zweiten Faktor braucht (Profil-Bereiche mit second_factor
     * 'new_device', 20-frontend-schutz.md B). Auch das erste Gerät eines Kontos ist
     * unbekannt.
     */
    public function isKnown(string $kind, int $accountId): bool
    {
        return $this->knownDeviceId($kind, $accountId) > 0;
    }

    /**
     * Wie isKnown(), liefert aber die device_id (0 = unbekannt) - für einen eigenen
     * Fehlversuchs-Zähler je Gerät (Admin::guardKey()).
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
     * Bekannte Geräte eines Kontos, zuletzt benutzte zuerst.
     *
     * @param string $kind  'admin' oder 'user'
     * @return array        Liste aus device_id, label, ip, created_at, last_seen_at (UTC)
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
     * Ein Gerät eines Kontos entfernen - seine Sessions enden mit (Fremdschlüssel).
     *
     * @return bool  false: gibt es nicht bzw. gehört einem anderen Konto
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
     * Alle Geräte eines Kontos entfernen.
     *
     * @return int  Anzahl entfernter Geräte
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
     * Ereignis an den Notifier (security.login_notifier) weiterreichen. Fehler landen
     * im Log 'security', die Anmeldung bzw. Speicherung läuft weiter.
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
     * Kurzbeschreibung aus dem User-Agent: "Firefox auf macOS". Grob mit Absicht -
     * sie soll dem Empfänger der Benachrichtigung das Gerät erkennbar machen, nicht
     * Versionen verfolgen.
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
     * Wert des Geräte-Cookies, wenn er das Format eines randomKey(32) hat, sonst ''.
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
     * Geräte-Cookie setzen bzw. verlängern: HttpOnly, SameSite Lax, 'secure' wie das
     * Session-Cookie des Bereichs (admin.cookie_secure / user.cookie_secure).
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
