<?php
/**
 * Admin session management
 *
 * DETAILS: ADMIN AND GROUP DATA IN THE SESSION
 * --------------------
 * create() stores once per session the data every backend request needs,
 * without reading `admin` and `group` again: the name, the group and the
 * personal settings (`admin`.`settings`: backend language, time zone,
 * preferred language version, frontend editing). If the settings change,
 * refreshDetails() rewrites all sessions of the admin (Admin::hookOnSaved()).
 *
 * As soon as a session is loaded or created, the settings go to
 * Cms::applyAdminSettings() - from then on snippets and time specifications of the request run in
 * the language and time zone of the admin (see Cms, "Language and time zone").
 *
 * RIGHTS OF THE GROUP
 * --------------------
 * Also per request, applyAccess() loads the group file of the logged-in group
 * into Camindo\Core\Access (var/cache/group-{group_id}.php, see InstanceCache) and
 * restricts, for non-superusers, the database queries of Cms::select() to the
 * assigned instances (Cms::setInstanceAccess()) and the readable areas
 * (Cms::setAreaAccess()). Everything else - modules, rights per area, languages -
 * the modules ask Access.
 *
 * WHAT IS CHECKED PER REQUEST (Integrity)
 * --------------------
 * load() does not rely on the session row alone. It carries a signature
 * over session_id + admin_id, and the admin row is read fresh every time and
 * checked against its signature; group_id and status_id come from there, not from the
 * cached details. Whoever inserts a session into the database, enters
 * another group in the details or tampers with the admin gets no session (see Integrity).
 *
 * INTERFACES
 * --------------------
 * A session belongs to exactly one interface (`interface`: backend, cli, php -
 * Access::INTERFACES). The backend entry points only load backend sessions (load() without
 * further specification), the API only its own (ApiSession::resume()). Sessions of the
 * command line and of the PHP API have their own inactivity limit
 * (api.max_inactive_sec) and a fixed maximum lifetime (`expires_at`,
 * api.max_lifetime). Every time a session is loaded, it is checked whether the group
 * may still use the interface.
 *
 * EVERY LOGIN IS A NEW SESSION
 * --------------------
 * create() always creates a new session id (no session fixation). If the
 * cookie still holds a session of the SAME admin - e.g. after admin.max_inactive_sec -,
 * only its interface states (expanded instances and folders,
 * details.ui - see uiState()) are taken over and the old row is deleted.
 * Working copies hang on the admin, not on the session, and survive
 * the new login anyway.
 */
namespace Camindo\Core;

class Session {
    /*
    data[
        'sessionid'
        'admin_id'
        'access_date'
        'details' => [admin name, settings and group data]
    ]
    */
    private array $data = [];

    public function __construct(
        protected Config $config,
        protected Database $db,
        protected Crypt $crypt,
        protected Integrity $integrity,
        protected ContainerInterface $container,
    )
    {
    }

    /**
     * Getter
     * @param string $index           assoc index of $this->data
     * @return null|int|string|array  $this->data[$index]
     */
    public function get(string $index): null|int|string|array
    {
        return $this->data[$index] ?? null;
    }

    /**
     * Setter
     * @param string $field   name of the database field in admin_session
     * @param string $value   value to store
     * @return                void
     */
    public function set(string $field, string $value): void
    {
        $fieldEscaped = preg_replace('/[^a-z0-9_\\-]/', '', $field);
        $sessionId = $this->data['session_id'] ?? null;
        if (empty($sessionId)) return;
        $this->data[$fieldEscaped] = $value;
        $sql = "UPDATE `admin_session` SET `$fieldEscaped` = ? WHERE `session_id` = ?";
        $this->db->query($sql, 'ss', [$value, $sessionId]);
    }

    /**
     * Read the interface state of the session (`details`.`ui`), e.g. the languages
     * shown in the Snippets module.
     *
     * @param string $name     key, e.g. 'snippets_languages'
     * @param mixed  $default  return value if nothing is stored
     */
    public function uiState(string $name, mixed $default = null): mixed
    {
        return $this->data['details']['ui'][$name] ?? $default;
    }

    /**
     * Store the interface state in the session (`details`.`ui`). Only this one value is
     * written (JSON_SET) - the rest of details stays as it is.
     * refreshDetails() keeps the ui values, create() takes them over from the previous
     * session of the same admin.
     *
     * @param string $name   key (a-z, 0-9, _)
     * @param mixed  $value  JSON-compatible value
     */
    public function setUiState(string $name, mixed $value): void
    {
        $name = preg_replace('/[^a-z0-9_]/', '', $name);
        $sessionId = $this->data['session_id'] ?? null;
        if ($name === '' || empty($sessionId)) return;
        $this->data['details']['ui'][$name] = $value;
        $this->db->query("UPDATE `admin_session`"
            . " SET `details` = JSON_SET(`details`, '$.ui', CAST(? AS JSON))"
            . " WHERE `session_id` = ?",
            'ss',
            [json_encode((object)$this->data['details']['ui'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $sessionId]
        );
    }

    /**
     * Set the administrator id without a session - exclusively for CLI processes.
     *
     * Background processes (Core/cron/*.php) have no session, but write the field
     * `changed_by` via Dataset::markDeleted(). So that 0 does not end up there, the
     * triggering web request passes the administrator id on as a command-line argument
     * and the CLI process stores it here.
     *
     * Deliberately kept narrow: sets admin_id and the details (name, settings,
     * group - so that Backend::isSuperuser() and the backend language also match in the CLI),
     * creates no session and writes nothing to the database. A call outside
     * CLI is ignored - otherwise this could be used to bypass a
     * login.
     *
     * The group rights are only loaded with $withAccess: background jobs that
     * an admin starts from the backend (batch processing, import, export,
     * mass deletion) thus run under the same rights as in the request - otherwise
     * "background process" could be used to bypass every check. System jobs
     * (scheduler, trash, template sync) and tests load no rights
     * (12-group-rights.md, A7).
     *
     * @param int  $adminId     admin.admin_id, 0 = unknown
     * @param bool $withAccess  load the group rights of the admin (Access)
     */
    public function setCliAdmin(int $adminId, bool $withAccess = false): void
    {
        if (PHP_SAPI !== 'cli') return;
        if ($adminId < 1) return;
        $this->data['admin_id'] = $adminId;
        $details = $this->adminDetails($adminId);
        if ($details !== null) {
            $this->data['details'] = $details;
            $this->applySettings();
            if ($withAccess) $this->applyAccess();
        }
    }

    /**
     * Create a new session - always with a new session id (see class comment).
     *
     * @param int      $adminId      admin.admin_id (login is checked)
     * @param string   $interface    backend | cli | php (Access::INTERFACES)
     * @param int|null $maxLifetime  cli/php: maximum lifetime in seconds, limited to
     *                               api.max_lifetime (null = api.max_lifetime)
     * @return string                session id
     */
    public function create(int $adminId, string $interface = 'backend', ?int $maxLifetime = null): string
    {
        if (!in_array($interface, Access::INTERFACES, true)) {
            throw new \InvalidArgumentException("invalid interface \"{$interface}\"");
        }
        // Clean up the database: delete sessions older than 24 h and expired ones,
        // plus the assignment of their working copies (registerWorkingCopy())
        $seconds = 24 * 3600;
        $this->db->query('DELETE FROM `admin_session`'
            .' WHERE `access_date` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)'
            .' OR `expires_at` < UTC_TIMESTAMP()',
            'i',
            [$seconds]
        );
        $this->db->query('DELETE FROM `admin_session_record`'
            . ' WHERE `session_id` NOT IN (SELECT `session_id` FROM `admin_session`)');

        // Assemble admin and group data
        $details = $this->adminDetails($adminId);
        if ($details === null) {
            // No admin found: abort completely
            throw new \RuntimeException("Admin with admin_id=$adminId does not exist.");
        }

        // Take over the interface states of an expired session of the same admin
        // (only in the backend - there the session is in the cookie)
        $isBackend = ($interface === 'backend');
        $previous = $isBackend
            ? $this->previousSessionState($adminId)
            : ['expanded_instance_ids' => '', 'expanded_folder_ids' => '', 'ui' => [], 'session_id' => ''];
        if ($previous['ui']) $details['ui'] = $previous['ui'];

        // Maximum lifetime (cli/php)
        $expiresAt = null;
        if (!$isBackend) {
            $limit = max(60, (int)$this->config->get('api.max_lifetime'));
            $lifetime = ($maxLifetime === null || $maxLifetime < 1) ? $limit : min($maxLifetime, $limit);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + $lifetime);
        }

        // Neue Session erstellen
        $sessionId = $this->crypt->randomKey(32);
        $this->db->query('INSERT INTO `admin_session`'
            .' (`session_id`, `admin_id`, `interface`, `sig`, `access_date`, `expires_at`, `details`, `expanded_instance_ids`, `expanded_folder_ids`)'
            .' VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?);',
            'sissssss',
            [
                $sessionId, $adminId, $interface,
                $this->integrity->sessionSignature($sessionId, $adminId, $interface),
                $expiresAt,
                $this->encodeDetails($details),
                $previous['expanded_instance_ids'], $previous['expanded_folder_ids'],
            ]
        );

        // Working copies of the replaced session now belong to this one (the mask is still
        // there after the re-login, StartBackend)
        if ($previous['session_id'] !== '') {
            $this->db->query('UPDATE `admin_session_record` SET `session_id` = ? WHERE `session_id` = ?',
                'ss', [$sessionId, $previous['session_id']]);
        }

        $this->data = [
            'admin_id' => $adminId,
            'session_id' => $sessionId,
            'interface' => $interface,
            'expires_at' => $expiresAt,
            'details' => $details,
            'expanded_instance_ids' => $previous['expanded_instance_ids'],
            'expanded_folder_ids' => $previous['expanded_folder_ids'],
        ];

        $this->applySettings();
        $this->applyAccess();
        if (!$this->container->get(Access::class)->interface($interface)) {
            // The login checks this beforehand (Admin::loginByName(), ApiSession) -
            // here only so that create() never leaves an invalid session behind
            $this->close($sessionId);
            throw new \RuntimeException("group of admin {$adminId} may not use interface {$interface}");
        }
        return $sessionId;
    }

    /**
     * If the cookie holds a session of this admin (expired or not), fetch its
     * interface states and delete the row - it is replaced by the new one. A session of
     * ANOTHER admin stays untouched and
     * ignored: it does not belong to this login.
     *
     * @return array  expanded_instance_ids, expanded_folder_ids ('' if nothing there),
     *                ui (details.ui, [] if nothing there)
     */
    private function previousSessionState(int $adminId): array
    {
        $state = ['expanded_instance_ids' => '', 'expanded_folder_ids' => '', 'ui' => [], 'session_id' => ''];
        $sessionId = (string)($_COOKIE['sessid'] ?? '');
        if (strlen($sessionId) !== 43) return $state;
        $this->db->query("SELECT `admin_id`, `sig`, `expanded_instance_ids`, `expanded_folder_ids`,"
            . " JSON_EXTRACT(`details`, '$.ui') AS `ui` FROM `admin_session`"
            .' WHERE `session_id` = ? AND `interface` = \'backend\'',
            's',
            [$sessionId]
        );
        if ($this->db->num_rows < 1) return $state;
        $row = $this->db->fetch();
        if ((int)$row['admin_id'] !== $adminId) return $state;
        if (!$this->integrity->verifySession($sessionId, $adminId, (string)$row['sig'])) return $state;
        $state['expanded_instance_ids'] = (string)$row['expanded_instance_ids'];
        $state['expanded_folder_ids'] = (string)$row['expanded_folder_ids'];
        $state['ui'] = json_decode((string)($row['ui'] ?? ''), true) ?: [];
        $state['session_id'] = $sessionId;
        $this->db->query('DELETE FROM `admin_session` WHERE `session_id` = ?', 's', [$sessionId]);
        return $state;
    }

    /**
     * Assemble the details of an admin for the session: name, personal
     * settings (`admin`.`settings` as an array) and the columns of its group.
     *
     * @param int $adminId
     * @return array|null  null if the admin does not exist
     */
    private function adminDetails(int $adminId): ?array
    {
        $this->db->pushResult();
        $this->db->query('SELECT `admin`.`name`, `admin`.`settings`, `group`.* FROM `admin`'
            .' LEFT JOIN `group` USING (`group_id`)'
            .' WHERE `admin_id` =?',
            'i',
            [$adminId]
        );
        $details = ($this->db->num_rows > 0) ? $this->db->fetch() : null;
        $this->db->popResult();
        if ($details === null) return null;
        $details['settings'] = json_decode((string)($details['settings'] ?? ''), true) ?: [];
        return $details;
    }

    private function encodeDetails(array $details): string
    {
        return json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Rewrite the session details of ALL sessions of an admin - after a change to
     * its personal settings (Admin::hookOnSaved()). If it concerns the running
     * session, the new settings apply immediately.
     *
     * @param int $adminId
     */
    public function refreshDetails(int $adminId): void
    {
        if ($adminId < 1) return;
        $details = $this->adminDetails($adminId);
        if ($details === null) return;
        // The interface states (ui) of every session are kept
        $this->db->query("UPDATE `admin_session`"
            . " SET `details` = JSON_SET(?, '$.ui', COALESCE(JSON_EXTRACT(`details`, '$.ui'), JSON_OBJECT()))"
            . " WHERE `admin_id` = ?",
            'si',
            [$this->encodeDetails($details), $adminId]
        );
        if ((int)($this->data['admin_id'] ?? 0) === $adminId) {
            $details['ui'] = $this->data['details']['ui'] ?? [];
            $this->data['details'] = $details;
            $this->applySettings();
            $this->applyAccess();
        }
    }

    /**
     * Pass the personal settings of the session admin on to Cms
     * (backend language, time zone) - see class comment.
     */
    private function applySettings(): void
    {
        $settings = (array)($this->data['details']['settings'] ?? []);
        if (empty($settings)) return;
        $this->container->get(Cms::class)->applyAdminSettings($settings);
    }

    /**
     * Load the rights of the group of the session admin (see class comment "Rights
     * of the group"). For non-superusers the queries of Cms::select() are restricted to
     * the assigned instances - with no assigned instance to none.
     */
    private function applyAccess(): void
    {
        $groupId = (int)($this->data['details']['group_id'] ?? 0);
        $adminId = (int)($this->data['admin_id'] ?? 0);
        $access = $this->container->get(Access::class);
        if ($groupId < 1 || !$access->load($groupId, $adminId)) {
            $access->unload();
            return;
        }
        if ($access->isSuperuser()) return;
        $cms = $this->container->get(Cms::class);
        $instanceIds = $access->instanceIds();
        if (empty($instanceIds)) {
            $cms->setInstanceAccessNone();
        }
        else {
            $cms->setInstanceAccess($instanceIds);
        }
        // Area level: records and templates only from readable areas
        $cms->setAreaAccess($access->readableAreaIds());
        // Content language of the lists: only readable languages
        $cms->restrictLanguageMap($access->readableLanguageIds());
    }

    /**
     * Load a session (every backend request, ApiSession::resume()).
     *
     * Checked are the time limit, maximum lifetime, interface, the signature of the row, the
     * admin row (signature, status) and - after loading the rights - whether the group
     * may use the interface.
     *
     * @param string   $sessionId   session id; empty = from the cookie 'sessid'
     * @param string[] $interfaces  permitted interfaces of the session (backend entry points:
     *                              only 'backend')
     * @return bool                 false: no valid session
     */
    public function load(string $sessionId = '', array $interfaces = ['backend']): bool
    {
        if (empty($sessionId)) {
            $sessionId = (string) ($_COOKIE['sessid'] ?? '');
        }
        
        // invalid length? (latin)
        if (strlen($sessionId) != 43) return false;

        // Load session
        $this->db->query('SELECT * FROM `admin_session`'
            .' WHERE `session_id` = ? AND (`expires_at` IS NULL OR `expires_at` > UTC_TIMESTAMP())',
            's',
            [$sessionId]
        );
        if ($this->db->num_rows < 1) return false;
        $data = $this->db->fetch();
        $adminId = (int)$data['admin_id'];
        $interface = (string)$data['interface'];
        if (!in_array($interface, $interfaces, true)) return false;

        // Inactivity limit - in the backend admin.max_inactive_sec, otherwise api.*
        $seconds = (int)$this->config->get($interface === 'backend' ? 'admin.max_inactive_sec' : 'api.max_inactive_sec');
        if (strtotime((string)$data['access_date'] . ' UTC') < time() - $seconds) return false;

        // Signature of the session row
        if (!$this->integrity->verifySession($sessionId, $adminId, (string)$data['sig'], $interface)) {
            return false;
        }
        // Admin row: signature, status, deleted? Group and status apply from here,
        // not from the cached details.
        $admin = $this->integrity->verifyAdmin($adminId);
        if ($admin === null || $admin['status_id'] !== 0 || $admin['deleted'] !== 0) {
            return false;
        }

        $this->data = $data;
        $this->data['details'] = json_decode((string)$data['details'], true) ?: [];
        $this->data['details']['group_id'] = $admin['group_id'];

        $this->applySettings();
        $this->applyAccess();

        // May the group (still) use the interface? (Access, "interfaces")
        if (!$this->container->get(Access::class)->interface($interface)) {
            $this->container->get(Access::class)->unload();
            $this->data = [];
            return false;
        }

        // Update access_date on every access
        // (refers to the activity of the administrator in the backend).
        // Not for queries in the background (polling: message badges,
        // background processes - header X-Camindo-Background, cLoadContent option
        // 'background'): they also come from an unattended window and
        // would otherwise postpone the logout after admin.max_inactive_sec endlessly.
        if (($_SERVER['HTTP_X_CAMINDO_BACKGROUND'] ?? '') === '1') return true;
        $this->db->query('UPDATE `admin_session`'
            .' SET `access_date`=UTC_TIMESTAMP()'
            .' WHERE `session_id` =?',
            's',
            [$sessionId]
        );
        return true;
    }

    /**
     * End a session
     * @param string $sessionId  session id; if empty then from COOKIE['sessid']
     */
    public function close(string $sessionId = '')
    {
        if (empty($sessionId)) {
            $sessionId = (string) ($_COOKIE['sessid'] ?? '');
        }
        // Delete the session from the database (safest way)
        $this->db->query('DELETE FROM `admin_session`'
            .' WHERE `session_id`=? ',
            's',
            [$sessionId]
        );
        $this->db->query('DELETE FROM `admin_session_record` WHERE `session_id` = ?', 's', [$sessionId]);
        // Rights and cookie only if it is the running session or the browser's one - an
        // API session that ends in a backend request leaves both in place
        if ($sessionId === (string)($this->data['session_id'] ?? '') || empty($this->data['session_id'])) {
            $this->container->get(Access::class)->unload();
        }
        if ($sessionId === (string)($_COOKIE['sessid'] ?? '')) {
            $this->setCookie('', true);
        }
    }

    /**
     * Assign a working copy to this session (input mask, RecordBackendEdit) - on
     * logout Record::cancelSession() discards exactly these, not the open masks
     * on other devices of the same admin.
     */
    public function registerWorkingCopy(int $recordId): void
    {
        $sessionId = (string)($this->data['session_id'] ?? '');
        if ($sessionId === '' || $recordId < 1) return;
        $this->db->query('INSERT IGNORE INTO `admin_session_record` (`session_id`, `record_id`) VALUES (?, ?)',
            'si', [$sessionId, $recordId]);
    }

    /**
     * Attach a session to a login device (LoginDevices, `login_device`). If the
     * device is removed, the session ends with it (foreign key ON DELETE CASCADE).
     */
    public function attachDevice(string $sessionId, int $deviceId): void
    {
        if ($sessionId === '' || $deviceId < 1) return;
        $this->db->query('UPDATE `admin_session` SET `device_id` = ? WHERE `session_id` = ?',
            'is', [$deviceId, $sessionId]);
        if (($this->data['session_id'] ?? '') === $sessionId) $this->data['device_id'] = $deviceId;
    }

    /**
     * End all sessions of an admin - after a new password, when removing
     * all devices, or on request ("end other sessions", AdminBackend::devices()).
     *
     * @param bool $keepCurrent  the running session stays if it belongs to this admin
     * @return int               number of ended sessions
     */
    public function closeAllOf(int $adminId, bool $keepCurrent = false): int
    {
        if ($adminId < 1) return 0;
        $current = (string)($this->data['session_id'] ?? '');
        if ($keepCurrent && $current !== '' && (int)($this->data['admin_id'] ?? 0) === $adminId) {
            $this->db->query('DELETE FROM `admin_session` WHERE `admin_id` = ? AND `session_id` <> ?',
                'is', [$adminId, $current]);
        }
        else {
            $this->db->query('DELETE FROM `admin_session` WHERE `admin_id` = ?', 'i', [$adminId]);
        }
        $count = (int)$this->db->affected_rows;
        // Assignment of the working copies of ended sessions (the copies themselves remain -
        // their lock expires, or Record::cancelAll() discards them)
        $this->db->query('DELETE FROM `admin_session_record`'
            . ' WHERE `session_id` NOT IN (SELECT `session_id` FROM `admin_session`)');
        return $count;
    }

    /**
     * Running (not expired) sessions of an admin, newest first.
     *
     * @return array  list of session_id, interface, device_id (0 = without device),
     *                access_date (UTC)
     */
    public function activeSessionsOf(int $adminId): array
    {
        $backendSeconds = (int)$this->config->get('admin.max_inactive_sec');
        $apiSeconds = (int)$this->config->get('api.max_inactive_sec');
        $this->db->pushResult();
        try {
            $this->db->query('SELECT `session_id`, `interface`, `device_id`, `access_date` FROM `admin_session`'
                . ' WHERE `admin_id` = ? AND (`expires_at` IS NULL OR `expires_at` > UTC_TIMESTAMP())'
                . ' AND `access_date` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL IF(`interface` = \'backend\', ?, ?) SECOND)'
                . ' ORDER BY `access_date` DESC',
                'iii', [$adminId, $backendSeconds, $apiSeconds]);
            $rows = [];
            while ($row = $this->db->fetch()) {
                $rows[] = [
                    'session_id' => (string)$row['session_id'],
                    'interface' => (string)$row['interface'],
                    'device_id' => (int)$row['device_id'],
                    'access_date' => (string)$row['access_date'],
                ];
            }
            return $rows;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * CSRF token of this session - empty without session (developer docs, chapter 14 (Security: web)).
     *
     * Output in Core/public_master/backend/index.php as cConfig.csrf_token and
     * added after a login by StartBackend::_loginComplete() (the page is not reloaded
     * in the process; the token of the old session no longer applies).
     */
    public function csrfToken(): string
    {
        $sessionId = (string)($this->data['session_id'] ?? '');
        return $this->integrity->csrfToken($sessionId);
    }

    /**
     * Check the token of the running request (developer docs, chapter 14 (Security: web)).
     *
     * Only for state-changing methods: GET and HEAD must have no side effect,
     * and the stylesheet and download endpoints of the backend cannot carry any
     * header at all (backendExtensions/{Ext}/css.php as <link>,
     * Record/record_download.php as window). What writes goes via POST.
     *
     * The token comes as header 'X-Camindo-Token' (cLoadContent() sets it on
     * every request) or as field '_token' in the POST body - the latter for forms
     * that do not run via the JavaScript. The query string is deliberately NOT a
     * source: there the token ended up in logs and referers.
     *
     * Without a session there is nothing to protect - the login itself (Start/login.php,
     * step1/step2) runs via POST before a session exists.
     */
    public function verifyCsrfRequest(): bool
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'GET' || $method === 'HEAD') return true;

        $expected = $this->csrfToken();
        if ($expected === '') return true;

        $sent = (string)($_SERVER['HTTP_X_CAMINDO_TOKEN'] ?? '');
        if ($sent === '') $sent = (string)($_POST['_token'] ?? '');
        if (hash_equals($expected, $sent)) return true;

        $adminId = (int)($this->data['admin_id'] ?? 0);
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '-');
        $this->container->get(System::class)->log(
            'security',
            "CSRF token missing or wrong for admin {$adminId}: {$method} "
            . (string)($_SERVER['REQUEST_URI'] ?? '') . " origin={$origin}"
        );
        return false;
    }

    /**
     * Does the configuration require a Secure cookie although the request comes via http://?
     * Then the browser discards the cookie (except Chrome/Firefox on
     * localhost) and the login fails without an apparent reason - the
     * login dialog therefore shows a notice (StartBackend::login()).
     * X-Forwarded-Proto counts here: it is only about the notice, not about rights.
     */
    public function secureCookieOverHttp(): bool
    {
        if (empty($this->config->get('admin.cookie_secure'))) return false;
        $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        $https = $https || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return !$https;
    }

    /**
     * Set or delete the session cookie 'sessid' (like UserSession::setCookie()).
     *
     * Always HttpOnly: JavaScript - also injected - cannot reach the
     * session id. 'secure' and SameSite from admin.cookie_secure / admin.cookie_samesite.
     * No expiry date: the cookie lives until the browser is closed; server-side
     * admin.max_inactive_sec applies.
     *
     * @param string $sessionId  session id from create()
     * @param bool   $delete     delete the cookie (logout)
     */
    public function setCookie(string $sessionId, bool $delete = false): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        $options = [
            'expires'  => $delete ? time() - 86400 : 0,
            'path'     => '/',
            'secure'   => !empty($this->config->get('admin.cookie_secure')),
            'httponly' => true,
            'samesite' => (string)($this->config->get('admin.cookie_samesite') ?: 'Lax'),
        ];
        setcookie('sessid', $delete ? '' : $sessionId, $options);
        if ($delete) unset($_COOKIE['sessid']);
        else $_COOKIE['sessid'] = $sessionId;
    }
}