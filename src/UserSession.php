<?php
/**
 * Frontend sessions of the community (table `user_session`). Instance name: $userSession.
 *
 * The counterpart to Camindo\Core\Session, which only knows administrators in the backend.
 * A "user" here is a website visitor; their profile is a `record` in an
 * area of type 'profile' (see Modules\Area\Area). The session holds the id of
 * this profile and is resumed via a cookie or - for calls without a cookie, e.g. from
 * an app - via an explicitly passed session id
 * (parameter 'sessionid' of CMS:USER_AUTH / CMS:USER_PROTECT).
 *
 * TWO KINDS OF SESSIONS
 * --------------------
 *     signed in    record_id > 0: CMS:USER_LOGIN has checked name and password
 *     anonymous    record_id NULL: only a basket (CMS:USER_BASKET creates it when
 *                  needed, so that a visitor who is not signed in can also collect
 *                  items). isLoggedIn() tells them apart; USER_AUTH and
 *                  USER_PROTECT only accept signed-in sessions.
 *
 * COOKIE
 * --------------------
 * The cookie name can be chosen per command ('cookiename', default 'usess'), so that two
 * sites under the same domain can keep separate sign-ins. Only
 * [a-z0-9] are allowed. The cookie is HttpOnly; 'secure' and SameSite come from the
 * configuration (user.cookie_secure, user.cookie_samesite).
 *
 * Cookies must be set BEFORE the first output. The CMS:USER_* commands
 * therefore belong sensibly at the start of a template; setCookie() checks
 * headers_sent() and otherwise leaves the session without a cookie - the caller
 * still gets the session id and can pass it on.
 *
 * LIFETIME
 * --------------------
 * A session expires after user.max_inactive_sec without access (load() counts as
 * access and updates access_date). Expired sessions are cleaned up by create() -
 * once per new session, the same reasoning as with Session::create().
 * Anonymous basket sessions get their own time limit (basket_expires in
 * `details`, CMS:USER_BASKET 'duration'); they only expire after that.
 *
 * DETAILS
 * --------------------
 * `details` (JSON) holds what the commands need per request without
 * loading the profile record again: the area of the profile (area_id also exists as a
 * column) and any additional data of the commands (basket time limit, sign-in time).
 *
 * @see Camindo\Core\Modules\Record\RecordUser   the CMS:USER_* commands
 * @see Camindo\Core\MediaDelivery                checks the session before file delivery
 * @see Camindo\Core\Session                      admin sessions (backend)
 */
namespace Camindo\Core;

class UserSession {

    /** Default cookie name, see parameter 'cookiename' of the CMS:USER_* commands */
    public const COOKIE_DEFAULT = 'usess';

    /** Cookie from which the CSRF token of the frontend forms is derived */
    public const CSRF_COOKIE = 'ucsrf';

    /** Length of a session id (Crypt::randomKey(32) -> 43 characters) */
    private const ID_LENGTH = 43;

    /*
        data[
            'session_id'
            'record_id'     profile record, 0 = anonymous
            'area_id'       area of the profile
            'instance_id'   instance on which the session was created
            'created_at'
            'access_date'
            'details'       array
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

    /** Is a session loaded (signed in or anonymous)? */
    public function isActive(): bool
    {
        return !empty($this->data['session_id']);
    }

    /** Is a user signed in (session with a profile record)? */
    public function isLoggedIn(): bool
    {
        return $this->isActive() && (int)($this->data['record_id'] ?? 0) > 0;
    }

    public function id(): string
    {
        return (string)($this->data['session_id'] ?? '');
    }

    /** Profile record of the signed-in user, 0 if not signed in */
    public function recordId(): int
    {
        return (int)($this->data['record_id'] ?? 0);
    }

    /** Area (type 'profile') of the signed-in user, 0 if not signed in */
    public function areaId(): int
    {
        return (int)($this->data['area_id'] ?? 0);
    }

    public function instanceId(): int
    {
        return (int)($this->data['instance_id'] ?? 0);
    }

    /**
     * Value from the session: a column or an entry from `details`.
     */
    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->data)) return $this->data[$key];
        return $this->data['details'][$key] ?? null;
    }

    /**
     * Write an entry in `details` (straight to the database).
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
     * Resume a session - from the passed id, otherwise from the cookie.
     *
     * Expired sessions (access_date older than user.max_inactive_sec) count as
     * invalid; an anonymous basket session with its own limit (details.basket_expires)
     * stays valid until then. Every successful access updates access_date.
     *
     * @param string $sessionId   explicit session id ('' = cookie)
     * @param string $cookieName  cookie name ('' = default)
     * @return bool               true if a session is loaded
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
     * Create a new session and take it over as the running session.
     *
     * A NEW id is ALWAYS created - even if a session is already running (against
     * session fixation: whoever slips the victim a cookie with a known id
     * must not end up in a signed-in session after the victim signs in; cf.
     * Session::create() in the backend, developer docs, chapter 13 (Security: authentication).
     *
     * If the running session (from this request or the cookie) is anonymous or
     * belongs to the same profile, its content moves into the new one: basket
     * (`user_basket`) and `details` - so the basket survives the sign-in. The
     * old row is deleted, its id is worthless afterwards. A session of a
     * DIFFERENT profile remains untouched (it does not belong to this sign-in) and
     * expires after its time limit.
     *
     * @param int    $recordId    profile record, 0 = anonymous session
     * @param int    $areaId      area of the profile
     * @param int    $instanceId  instance of the call
     * @param array  $details     additional data (merged with the ones taken over)
     * @param bool   $setCookie   set the cookie?
     * @param string $cookieName  cookie name ('' = default)
     * @return string             session id
     */
    public function create(int $recordId, int $areaId, int $instanceId, array $details = [], bool $setCookie = true, string $cookieName = ''): string
    {
        $this->purge();

        // Running session: from this request, otherwise from the cookie - USER_LOGIN
        // often comes before every USER_BASKET/USER_AUTH of the template
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
                // Re-link the basket (the foreign key has no ON UPDATE
                // CASCADE), then delete the old session
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
     * Ensure an anonymous session (basket without sign-in): the running one if
     * there is one, otherwise a new one.
     *
     * @return string  session id
     */
    public function ensure(int $instanceId, string $cookieName = ''): string
    {
        if ($this->load('', $cookieName)) return $this->id();
        return $this->create(0, 0, $instanceId, [], true, $cookieName);
    }

    /**
     * End the session: delete the row (including the basket via the foreign key),
     * delete the cookie.
     *
     * @param bool   $deleteCookie  false: end it on the server side only
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
     * End all sessions of a profile - after deleting the profile, a password
     * change, or when an administrator locks the user.
     *
     * @param int    $recordId  profile record
     * @param string $exceptId  keep this session alive (password change: the
     *                          session in which it happened)
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
    // CSRF token of the frontend forms (developer docs, chapter 14 (Security: web))
    // ====================

    /**
     * Token for a form (CMS:USER_TOKEN) - HMAC over the cookie 'ucsrf'.
     *
     * Deliberately NOT bound to the session: sign-in, registration and
     * password reset run without a session, and USER_LOGIN issues a new id -
     * a second tab that is still open would then hold a dead token. The cookie is
     * a random value (HttpOnly, SameSite like usess); the token cannot be computed
     * from it without .camindo.key; a foreign website knows neither the
     * cookie nor the token (signed double submit).
     *
     * Creates the cookie on the first call - so call it BEFORE the first output
     * (CMS:USER_TOKEN set=… at the start of the template). Later calls in the same request
     * read the cookie from $_COOKIE. If it is missing and the headers have already been sent,
     * there is no token ('') - a made-up one would be worthless without the cookie.
     */
    public function csrfToken(): string
    {
        $seed = $this->csrfSeed(true);
        return $seed === '' ? '' : $this->crypt->hash('ucsrf|' . $seed);
    }

    /**
     * Random value from the cookie 'ucsrf' - basis of the CSRF token and of the
     * timestamp of FormGuard. $create: if it is missing, create a new one (only possible
     * before the first output). '' = none.
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
     * Does the request contain a valid token? Source: POST field '_token' or
     * header X-Camindo-Token (Ajax), never the query string (logs, referer).
     * Failure -> log 'security'.
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
     * Sanitize the cookie name: only [a-z0-9], empty = default.
     */
    public function cookieName(string $name = ''): string
    {
        $name = preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($name)));
        return $name === '' ? self::COOKIE_DEFAULT : $name;
    }

    /**
     * Set or delete the session cookie (value '' and $delete).
     *
     * No expiry date: the cookie lives until the browser is closed, on the server
     * user.max_inactive_sec applies. Nothing works after headers_sent() - then the
     * session stays without a cookie (see class comment).
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
     * Expired? Signed-in sessions after user.max_inactive_sec, anonymous ones with
     * their own basket time limit only after that.
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
     * Delete expired sessions of all users (see isExpired()). Anonymous sessions
     * with a basket time limit stay until their limit is reached.
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
