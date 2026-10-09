<?php
/**
 * Frontend-Sessions der Community (Tabelle `user_session`). Instanzname: $userSession.
 *
 * Das Gegenstück zu Camindo\Core\Session, die nur Administratoren im Backend kennt.
 * Ein "User" ist hier ein Website-Besucher; sein Profil ist ein `record` in einem
 * Bereich vom Typ 'profile' (siehe Modules\Area\Area). Die Session hält die Id
 * dieses Profils und wird über ein Cookie oder - für Aufrufe ohne Cookie, z. B. aus
 * einer App - über eine explizit übergebene Session-Id wieder aufgenommen
 * (Parameter 'sessionid' von CMS:USER_AUTH / CMS:USER_PROTECT).
 *
 * ZWEI ARTEN VON SESSIONS
 * --------------------
 *     angemeldet   record_id > 0: CMS:USER_LOGIN hat Name und Passwort geprüft
 *     anonym       record_id NULL: nur ein Warenkorb (CMS:USER_BASKET legt sie bei
 *                  Bedarf an, damit auch ein nicht angemeldeter Besucher sammeln
 *                  kann). isLoggedIn() unterscheidet beides; USER_AUTH und
 *                  USER_PROTECT akzeptieren nur angemeldete Sessions.
 *
 * COOKIE
 * --------------------
 * Der Cookiename ist je Befehl wählbar ('cookiename', Standard 'usess'), damit zwei
 * Auftritte unter derselben Domain getrennte Anmeldungen führen können. Erlaubt sind
 * nur [a-z0-9]. Das Cookie ist HttpOnly; 'secure' und SameSite kommen aus der
 * Konfiguration (user.cookie_secure, user.cookie_samesite).
 *
 * Cookies müssen VOR der ersten Ausgabe gesetzt werden. Die CMS:USER_*-Befehle
 * stehen deshalb sinnvollerweise am Anfang eines Templates; setCookie() prüft
 * headers_sent() und lässt die Session sonst ohne Cookie stehen - der Aufrufer
 * bekommt die Session-Id trotzdem und kann sie weiterreichen.
 *
 * LEBENSDAUER
 * --------------------
 * Eine Session verfällt nach user.max_inactive_sec ohne Zugriff (load() zählt als
 * Zugriff und schreibt access_date fort). Verfallene Sessions räumt create() mit
 * ab - einmal je neuer Session, dieselbe Überlegung wie bei Session::create().
 * Anonyme Warenkorb-Sessions bekommen ihre eigene Frist (basket_expires in
 * `details`, CMS:USER_BASKET 'duration'); sie fallen erst dann.
 *
 * DETAILS
 * --------------------
 * `details` (JSON) hält, was die Befehle je Request brauchen, ohne den
 * Profil-Datensatz erneut zu laden: den Bereich des Profils (area_id steht auch als
 * Spalte) und beliebige Zusatzangaben der Befehle (Warenkorb-Frist, Anmeldezeit).
 *
 * @see Camindo\Core\Modules\Record\RecordUser   die CMS:USER_*-Befehle
 * @see Camindo\Core\MediaDelivery                prüft die Session vor der Dateiausgabe
 * @see Camindo\Core\Session                      Admin-Sessions (Backend)
 */
namespace Camindo\Core;

class UserSession {

    /** Standard-Cookiename, siehe Parameter 'cookiename' der CMS:USER_*-Befehle */
    public const COOKIE_DEFAULT = 'usess';

    /** Cookie, aus dem das CSRF-Token der Frontend-Formulare abgeleitet wird */
    public const CSRF_COOKIE = 'ucsrf';

    /** Länge einer Session-Id (Crypt::randomKey(32) -> 43 Zeichen) */
    private const ID_LENGTH = 43;

    /*
        data[
            'session_id'
            'record_id'     Profil-Datensatz, 0 = anonym
            'area_id'       Bereich des Profils
            'instance_id'   Instanz, auf der die Session entstand
            'created_at'
            'access_date'
            'details'       Array
        ]
    */
    private array $data = [];

    public function __construct(
        private Config $config,
        private Database $db,
        private Crypt $crypt,
        private System $system
    )
    {
    }

    // ====================
    // Zustand
    // ====================

    /** Ist eine Session geladen (angemeldet oder anonym)? */
    public function isActive(): bool
    {
        return !empty($this->data['session_id']);
    }

    /** Ist ein User angemeldet (Session mit Profil-Datensatz)? */
    public function isLoggedIn(): bool
    {
        return $this->isActive() && (int)($this->data['record_id'] ?? 0) > 0;
    }

    public function id(): string
    {
        return (string)($this->data['session_id'] ?? '');
    }

    /** Profil-Datensatz des angemeldeten Users, 0 wenn nicht angemeldet */
    public function recordId(): int
    {
        return (int)($this->data['record_id'] ?? 0);
    }

    /** Bereich (type 'profile') des angemeldeten Users, 0 wenn nicht angemeldet */
    public function areaId(): int
    {
        return (int)($this->data['area_id'] ?? 0);
    }

    public function instanceId(): int
    {
        return (int)($this->data['instance_id'] ?? 0);
    }

    /**
     * Wert aus der Session: eine Spalte oder ein Eintrag aus `details`.
     */
    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->data)) return $this->data[$key];
        return $this->data['details'][$key] ?? null;
    }

    /**
     * Eintrag in `details` schreiben (sofort in die Datenbank).
     */
    public function setDetail(string $key, mixed $value): void
    {
        if (!$this->isActive()) return;
        $this->data['details'][$key] = $value;
        $this->db->query(
            'UPDATE `user_session` SET `details` = ? WHERE `session_id` = ?',
            'ss',
            [$this->encodeDetails($this->data['details']), $this->id()]
        );
    }

    // ====================
    // Laden / Anlegen / Beenden
    // ====================

    /**
     * Session wieder aufnehmen - aus der übergebenen Id, sonst aus dem Cookie.
     *
     * Verfallene Sessions (access_date älter als user.max_inactive_sec) gelten als
     * ungültig; eine anonyme Warenkorb-Session mit eigener Frist (details.basket_expires)
     * bleibt bis dahin gültig. Jeder erfolgreiche Zugriff schreibt access_date fort.
     *
     * @param string $sessionId   explizite Session-Id ('' = Cookie)
     * @param string $cookieName  Cookiename ('' = Standard)
     * @return bool               true, wenn eine Session geladen ist
     */
    public function load(string $sessionId = '', string $cookieName = ''): bool
    {
        if ($this->isActive() && ($sessionId === '' || $sessionId === $this->id())) {
            return true;
        }
        if ($sessionId === '') {
            $sessionId = (string)($_COOKIE[$this->cookieName($cookieName)] ?? '');
        }
        if (!$this->isValidId($sessionId)) return false;

        $this->db->pushResult();
        try {
            $this->db->query(
                'SELECT * FROM `user_session` WHERE `session_id` = ?',
                's',
                [$sessionId]
            );
            $row = $this->db->fetch();
            if ($row === null) return false;

            $row['details'] = json_decode((string)($row['details'] ?? ''), true) ?: [];
            if ($this->isExpired($row)) {
                $this->db->query('DELETE FROM `user_session` WHERE `session_id` = ?', 's', [$sessionId]);
                return false;
            }
            $this->db->query(
                'UPDATE `user_session` SET `access_date` = UTC_TIMESTAMP() WHERE `session_id` = ?',
                's',
                [$sessionId]
            );
            $row['record_id'] = (int)($row['record_id'] ?? 0);
            $this->data = $row;
            return true;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Neue Session anlegen und als laufende Session übernehmen.
     *
     * Es entsteht IMMER eine neue Id - auch wenn schon eine Session läuft (gegen
     * Session-Fixation: wer dem Opfer ein Cookie mit bekannter Id unterschiebt,
     * darf nach dessen Anmeldung nicht in einer angemeldeten Session sitzen; vgl.
     * Session::create() im Backend, developer_docs/16-sicherheit.md §3 und §9).
     *
     * Ist die laufende Session (aus diesem Request oder dem Cookie) anonym oder
     * gehört sie demselben Profil, zieht ihr Inhalt in die neue um: Warenkorb
     * (`user_basket`) und `details` - der Warenkorb überlebt so die Anmeldung. Die
     * alte Zeile wird gelöscht, ihre Id ist danach wertlos. Eine Session eines
     * ANDEREN Profils bleibt unberührt (sie gehört nicht zu dieser Anmeldung) und
     * verfällt nach ihrer Frist.
     *
     * @param int    $recordId    Profil-Datensatz, 0 = anonyme Session
     * @param int    $areaId      Bereich des Profils
     * @param int    $instanceId  Instanz des Aufrufs
     * @param array  $details     Zusatzangaben (werden mit übernommenen zusammengeführt)
     * @param bool   $setCookie   Cookie setzen?
     * @param string $cookieName  Cookiename ('' = Standard)
     * @return string             Session-Id
     */
    public function create(int $recordId, int $areaId, int $instanceId, array $details = [], bool $setCookie = true, string $cookieName = ''): string
    {
        $this->purge();

        // Laufende Session: aus diesem Request, sonst aus dem Cookie - USER_LOGIN
        // steht oft vor jedem USER_BASKET/USER_AUTH des Templates
        if (!$this->isActive()) $this->load('', $cookieName);
        $previousId = '';
        if ($this->isActive() && ($this->recordId() === 0 || $this->recordId() === $recordId)) {
            $previousId = $this->id();
            $details = array_replace((array)($this->data['details'] ?? []), $details);
        }

        $sessionId = $this->crypt->randomKey(32);
        $this->db->pushResult();
        try {
            $this->db->query(
                'INSERT INTO `user_session` (`session_id`, `record_id`, `area_id`, `instance_id`, `created_at`, `access_date`, `details`)'
                . ' VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)',
                'siiis',
                [$sessionId, $recordId > 0 ? $recordId : null, $areaId, $instanceId, $this->encodeDetails($details)]
            );
            if ($previousId !== '') {
                // Warenkorb umhängen (der Fremdschlüssel kennt kein ON UPDATE
                // CASCADE), dann die alte Session löschen
                $this->db->query(
                    'UPDATE `user_basket` SET `session_id` = ? WHERE `session_id` = ?',
                    'ss',
                    [$sessionId, $previousId]
                );
                $this->db->query('DELETE FROM `user_session` WHERE `session_id` = ?', 's', [$previousId]);
            }
        }
        finally {
            $this->db->popResult();
        }

        $this->data = [
            'session_id'  => $sessionId,
            'record_id'   => $recordId,
            'area_id'     => $areaId,
            'instance_id' => $instanceId,
            'details'     => $details,
        ];
        if ($setCookie) $this->setCookie($sessionId, $cookieName);
        return $sessionId;
    }

    /**
     * Anonyme Session sicherstellen (Warenkorb ohne Anmeldung): die laufende, wenn
     * es eine gibt, sonst eine neue.
     *
     * @return string  Session-Id
     */
    public function ensure(int $instanceId, string $cookieName = ''): string
    {
        if ($this->load('', $cookieName)) return $this->id();
        return $this->create(0, 0, $instanceId, [], true, $cookieName);
    }

    /**
     * Session beenden: Zeile löschen (samt Warenkorb per Fremdschlüssel), Cookie
     * löschen.
     *
     * @param bool   $deleteCookie  false: nur serverseitig beenden
     * @param string $cookieName
     */
    public function close(bool $deleteCookie = true, string $cookieName = ''): void
    {
        $sessionId = $this->id();
        if ($sessionId === '') {
            $sessionId = (string)($_COOKIE[$this->cookieName($cookieName)] ?? '');
        }
        if ($this->isValidId($sessionId)) {
            $this->db->pushResult();
            $this->db->query('DELETE FROM `user_session` WHERE `session_id` = ?', 's', [$sessionId]);
            $this->db->popResult();
        }
        $this->data = [];
        if ($deleteCookie) $this->setCookie('', $cookieName, true);
    }

    /**
     * Alle Sessions eines Profils beenden - nach dem Löschen des Profils, einem
     * Passwortwechsel oder wenn ein Administrator den User sperrt.
     *
     * @param int    $recordId  Profil-Datensatz
     * @param string $exceptId  diese Session bestehen lassen (Passwortwechsel: die
     *                          Session, in der er geschah)
     */
    public function closeAllOf(int $recordId, string $exceptId = ''): void
    {
        if ($recordId < 1) return;
        $this->db->pushResult();
        $this->db->query(
            'DELETE FROM `user_session` WHERE `record_id` = ? AND `session_id` <> ?',
            'is',
            [$recordId, $exceptId]
        );
        $this->db->popResult();
        if ($this->recordId() === $recordId && $this->id() !== $exceptId) $this->data = [];
    }

    // ====================
    // Cookie
    // ====================
    // CSRF-Token der Frontend-Formulare (17-csrf.md §2.5)
    // ====================

    /**
     * Token für ein Formular (CMS:USER_TOKEN) - HMAC über das Cookie 'ucsrf'.
     *
     * Bewusst NICHT an die Session gebunden: Anmeldung, Registrierung und
     * Passwort-Reset laufen ohne Session, und USER_LOGIN vergibt eine neue Id -
     * ein zweiter, noch offener Tab hätte danach ein totes Token. Das Cookie ist
     * ein Zufallswert (HttpOnly, SameSite wie usess), das Token lässt sich ohne
     * .camindo.key nicht daraus berechnen; eine fremde Website kennt weder das
     * Cookie noch das Token (signiertes Double-Submit).
     *
     * Legt das Cookie beim ersten Aufruf an - also VOR der ersten Ausgabe aufrufen
     * (CMS:USER_TOKEN set=… am Template-Anfang). Spätere Aufrufe im selben Request
     * lesen das Cookie aus $_COOKIE. Fehlt es und sind die Header schon gesendet,
     * gibt es kein Token ('') - ein ausgedachtes wäre ohne Cookie wertlos.
     */
    public function csrfToken(): string
    {
        $seed = $this->csrfSeed(true);
        return $seed === '' ? '' : $this->crypt->hash('ucsrf|' . $seed);
    }

    /**
     * Zufallswert aus dem Cookie 'ucsrf' - Grundlage des CSRF-Tokens und des
     * Zeitstempels von FormGuard. $create: fehlt er, einen neuen anlegen (nur vor
     * der ersten Ausgabe möglich). '' = keiner.
     */
    public function csrfSeed(bool $create = false): string
    {
        $seed = (string)($_COOKIE[self::CSRF_COOKIE] ?? '');
        if ($this->isValidId($seed)) return $seed;
        if (!$create || (PHP_SAPI !== 'cli' && headers_sent())) return '';
        $seed = $this->crypt->randomKey(32);
        $this->setCookie($seed, self::CSRF_COOKIE);
        if (PHP_SAPI === 'cli') $_COOKIE[self::CSRF_COOKIE] = $seed;
        return $seed;
    }

    /**
     * Enthält der Request ein gültiges Token? Quelle: POST-Feld '_token' oder
     * Header X-Camindo-Token (Ajax), nie der Query-String (Logs, Referer).
     * Fehlschlag -> Log 'security'.
     */
    public function verifyCsrfRequest(): bool
    {
        $seed = (string)($_COOKIE[self::CSRF_COOKIE] ?? '');
        $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CAMINDO_TOKEN'] ?? '';
        $ok = is_string($token) && $token !== '' && $this->isValidId($seed)
            && hash_equals($this->crypt->hash('ucsrf|' . $seed), $token);
        if (!$ok) {
            $this->system->log('security', 'Frontend CSRF token missing or invalid: ' . ($_SERVER['REQUEST_URI'] ?? ''));
        }
        return $ok;
    }

    /**
     * Cookiename bereinigen: nur [a-z0-9], leer = Standard.
     */
    public function cookieName(string $name = ''): string
    {
        $name = preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($name)));
        return $name === '' ? self::COOKIE_DEFAULT : $name;
    }

    /**
     * Session-Cookie setzen bzw. löschen (Wert '' und $delete).
     *
     * Kein Ablaufdatum: das Cookie lebt bis zum Schließen des Browsers, serverseitig
     * gilt user.max_inactive_sec. Nach headers_sent() geht nichts mehr - dann bleibt
     * die Session ohne Cookie (siehe Klassenkommentar).
     */
    private function setCookie(string $value, string $cookieName = '', bool $delete = false): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        $name = $this->cookieName($cookieName);
        $options = [
            'expires'  => $delete ? time() - 86400 : 0,
            'path'     => '/',
            'secure'   => !empty($this->config->get('user.cookie_secure')),
            'httponly' => true,
            'samesite' => (string)($this->config->get('user.cookie_samesite') ?: 'Lax'),
        ];
        setcookie($name, $delete ? '' : $value, $options);
        if ($delete) unset($_COOKIE[$name]);
        else $_COOKIE[$name] = $value;
    }

    // ====================
    // Hilfsfunktionen
    // ====================

    private function isValidId(string $sessionId): bool
    {
        return strlen($sessionId) === self::ID_LENGTH && preg_match('/^[A-Za-z0-9_\-]+$/', $sessionId) === 1;
    }

    /**
     * Verfallen? Angemeldete Sessions nach user.max_inactive_sec, anonyme mit
     * eigener Warenkorb-Frist erst danach.
     */
    private function isExpired(array $row): bool
    {
        $accessDate = (string)($row['access_date'] ?? '');
        $basketExpires = (string)($row['details']['basket_expires'] ?? '');
        $now = gmdate('Y-m-d H:i:s');
        if ($basketExpires !== '' && (int)($row['record_id'] ?? 0) === 0) {
            return $basketExpires < $now;
        }
        $maxInactive = (int)$this->config->get('user.max_inactive_sec');
        if ($maxInactive < 1) return false;
        return $accessDate < gmdate('Y-m-d H:i:s', time() - $maxInactive);
    }

    /**
     * Verfallene Sessions aller User löschen (siehe isExpired()). Anonyme Sessions
     * mit Warenkorb-Frist bleiben bis zu ihrer Frist stehen.
     */
    private function purge(): void
    {
        $maxInactive = (int)$this->config->get('user.max_inactive_sec');
        if ($maxInactive < 1) return;
        $this->db->pushResult();
        $this->db->query(
            'DELETE FROM `user_session`'
            . ' WHERE `access_date` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)'
            . " AND (`record_id` IS NOT NULL OR `details` NOT LIKE '%basket_expires%')",
            'i',
            [$maxInactive]
        );
        $this->db->query(
            "DELETE FROM `user_session` WHERE `record_id` IS NULL"
            . " AND JSON_UNQUOTE(JSON_EXTRACT(`details`, '$.basket_expires')) < ?",
            's',
            [gmdate('Y-m-d H:i:s')]
        );
        $this->db->popResult();
    }

    private function encodeDetails(array $details): string
    {
        return json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
