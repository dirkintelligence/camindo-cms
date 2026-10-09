<?php
/**
 * Admin-Session Verwaltung
 *
 * DETAILS: ADMIN- UND GRUPPENDATEN IN DER SESSION
 * --------------------
 * create() legt zu jeder Session einmalig die Angaben ab, die jeder Backend-Request
 * braucht, ohne `admin` und `group` erneut zu lesen: den Namen, die Gruppe und die
 * persönlichen Einstellungen (`admin`.`settings`: Backend-Sprache, Zeitzone,
 * bevorzugte Sprachversion, Frontend-Editing). Ändern sich die Einstellungen,
 * schreibt refreshDetails() alle Sessions des Admins nach (Admin::hookOnSaved()).
 *
 * Sobald eine Session geladen oder erzeugt ist, gehen die Einstellungen an
 * Cms::applyAdminSettings() - ab da laufen Snippets und Zeitangaben des Requests in
 * der Sprache und Zeitzone des Admins (siehe Cms, "Sprache und Zeitzone").
 *
 * RECHTE DER GRUPPE
 * --------------------
 * Ebenfalls je Request lädt applyAccess() die Gruppendatei der angemeldeten Gruppe
 * in Camindo\Core\Access (var/cache/group-{group_id}.php, siehe InstanceCache) und
 * beschränkt für Nicht-Superuser die Datenbankabfragen von Cms::select() auf die
 * zugewiesenen Instanzen (Cms::setInstanceAccess()) und die lesbaren Bereiche
 * (Cms::setAreaAccess()). Alles Weitere - Module, Rechte je Bereich, Sprachen -
 * fragen die Module bei Access nach.
 *
 * WAS JE REQUEST GEPRÜFT WIRD (Integrity)
 * --------------------
 * load() verlässt sich nicht auf die Session-Zeile allein. Sie trägt eine Signatur
 * über session_id + admin_id, und die Admin-Zeile wird jedes Mal frisch gelesen und
 * gegen ihre Signatur geprüft; group_id und status_id kommen von dort, nicht aus den
 * gecachten details. Wer in der Datenbank eine Session einfügt, in den details eine
 * andere Gruppe einträgt oder am Admin dreht, bekommt keine Session (siehe Integrity).
 *
 * OBERFLÄCHEN
 * --------------------
 * Eine Session gehört zu genau einer Oberfläche (`interface`: backend, cli, php -
 * Access::INTERFACES). Die Backend-Einstiege laden nur Backend-Sessions (load() ohne
 * weitere Angabe), die API nur ihre eigenen (ApiSession::resume()). Sessions der
 * Kommandozeile und der PHP-API haben eine eigene Frist für Untätigkeit
 * (api.max_inactive_sec) und eine feste Höchstlaufzeit (`expires_at`,
 * api.max_lifetime). Bei jedem Laden wird geprüft, ob die Gruppe die Oberfläche
 * (noch) benutzen darf.
 *
 * JEDE ANMELDUNG IST EINE NEUE SESSION
 * --------------------
 * create() erzeugt immer eine neue Session-Id (kein Session-Fixation). Steht im
 * Cookie noch eine Session DESSELBEN Admins - etwa nach admin.max_inactive_sec -,
 * werden nur ihre Oberflächen-Zustände (aufgeklappte Instanzen und Ordner,
 * details.ui - siehe uiState()) übernommen und die alte Zeile gelöscht.
 * Arbeitskopien hängen am Admin, nicht an der Session, und überstehen die
 * Neuanmeldung ohnehin.
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
     * @param string $field   name of the database field in admin_sesssion
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
     * Oberflächen-Zustand der Session lesen (`details`.`ui`), z. B. die im Modul
     * Snippets angezeigten Sprachen.
     *
     * @param string $name     Schlüssel, z. B. 'snippets_languages'
     * @param mixed  $default  Rückgabe, wenn nichts gespeichert ist
     */
    public function uiState(string $name, mixed $default = null): mixed
    {
        return $this->data['details']['ui'][$name] ?? $default;
    }

    /**
     * Oberflächen-Zustand in der Session speichern (`details`.`ui`). Geschrieben wird
     * nur dieser eine Wert (JSON_SET) - der Rest von details bleibt, wie er ist.
     * refreshDetails() erhält die ui-Werte, create() übernimmt sie aus der vorigen
     * Session desselben Admins.
     *
     * @param string $name   Schlüssel (a-z, 0-9, _)
     * @param mixed  $value  JSON-fähiger Wert
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
     * Administrator-Id ohne Session setzen - ausschließlich für CLI-Prozesse.
     *
     * Hintergrundprozesse (Core/cron/*.php) haben keine Session, schreiben aber über
     * Dataset::markDeleted() das Feld `changed_by`. Damit dort nicht 0 landet, reicht
     * der auslösende Web-Request die Administrator-Id als Kommandozeilen-Argument durch
     * und der CLI-Prozess hinterlegt sie hier.
     *
     * Bewusst eng gehalten: setzt admin_id und die Details (Name, Einstellungen,
     * Gruppe - damit Backend::isSuperuser() und die Backend-Sprache auch im CLI
     * stimmen), legt keine Session an und schreibt nichts in die Datenbank. Ein
     * Aufruf außerhalb von CLI wird ignoriert - sonst ließe sich damit eine
     * Anmeldung umgehen.
     *
     * Die Gruppenrechte werden nur mit $withAccess geladen: Hintergrundjobs, die
     * ein Admin aus dem Backend anstößt (Stapelverarbeitung, Import, Export,
     * Massenlöschung), laufen damit unter denselben Rechten wie im Request - sonst
     * ließe sich über "Hintergrundprozess" jede Prüfung umgehen. Systemjobs
     * (Scheduler, Papierkorb, Template-Sync) und Tests laden keine Rechte
     * (12-group-rights.md, A7).
     *
     * @param int  $adminId     admin.admin_id, 0 = unbekannt
     * @param bool $withAccess  Gruppenrechte des Admins laden (Access)
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
     * Neue Session erzeugen - immer mit neuer Session-Id (siehe Klassenkommentar).
     *
     * @param int      $adminId      admin.admin_id (Anmeldung ist geprüft)
     * @param string   $interface    backend | cli | php (Access::INTERFACES)
     * @param int|null $maxLifetime  cli/php: Höchstlaufzeit in Sekunden, begrenzt auf
     *                               api.max_lifetime (null = api.max_lifetime)
     * @return string                Session-Id
     */
    public function create(int $adminId, string $interface = 'backend', ?int $maxLifetime = null): string
    {
        if (!in_array($interface, Access::INTERFACES, true)) {
            throw new \InvalidArgumentException("invalid interface \"{$interface}\"");
        }
        // Datenbank bereinigen: Sessions älter als 24 Std und abgelaufene löschen,
        // dazu die Zuordnung ihrer Arbeitskopien (registerWorkingCopy())
        $seconds = 24 * 3600;
        $this->db->query('DELETE FROM `admin_session`'
            .' WHERE `access_date` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)'
            .' OR `expires_at` < UTC_TIMESTAMP()',
            'i',
            [$seconds]
        );
        $this->db->query('DELETE FROM `admin_session_record`'
            . ' WHERE `session_id` NOT IN (SELECT `session_id` FROM `admin_session`)');

        // Admin- und Gruppen- Daten zusammenstellen
        $details = $this->adminDetails($adminId);
        if ($details === null) {
            // Kein Admin gefunden dann Total-Abbruch
            throw new \RuntimeException("Admin with admin_id=$adminId does not exist.");
        }

        // Oberflächen-Zustände einer abgelaufenen Session desselben Admins übernehmen
        // (nur im Backend - dort steht die Session im Cookie)
        $isBackend = ($interface === 'backend');
        $previous = $isBackend
            ? $this->previousSessionState($adminId)
            : ['expanded_instance_ids' => '', 'expanded_folder_ids' => '', 'ui' => [], 'session_id' => ''];
        if ($previous['ui']) $details['ui'] = $previous['ui'];

        // Höchstlaufzeit (cli/php)
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

        // Arbeitskopien der abgelösten Session gehören jetzt dieser (die Maske steht
        // nach dem Re-Login noch, StartBackend)
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
            // Die Anmeldung prüft das vorher (Admin::loginByName(), ApiSession) -
            // hier nur, damit create() nie eine unzulässige Session hinterlässt
            $this->close($sessionId);
            throw new \RuntimeException("group of admin {$adminId} may not use interface {$interface}");
        }
        return $sessionId;
    }

    /**
     * Steht im Cookie eine Session dieses Admins (abgelaufen oder nicht), ihre
     * Oberflächen-Zustände holen und die Zeile löschen - sie wird durch die neue
     * ersetzt. Eine Session eines ANDEREN Admins bleibt unangetastet und
     * unbeachtet: sie gehört nicht zu dieser Anmeldung.
     *
     * @return array  expanded_instance_ids, expanded_folder_ids ('' wenn nichts da),
     *                ui (details.ui, [] wenn nichts da)
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
     * Details eines Admins für die Session zusammenstellen: Name, persönliche
     * Einstellungen (`admin`.`settings` als Array) und die Spalten seiner Gruppe.
     *
     * @param int $adminId
     * @return array|null  null, wenn es den Admin nicht gibt
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
     * Session-Details ALLER Sessions eines Admins neu schreiben - nach einer Änderung
     * seiner persönlichen Einstellungen (Admin::hookOnSaved()). Betrifft es die
     * laufende Session, gelten die neuen Einstellungen sofort.
     *
     * @param int $adminId
     */
    public function refreshDetails(int $adminId): void
    {
        if ($adminId < 1) return;
        $details = $this->adminDetails($adminId);
        if ($details === null) return;
        // Oberflächen-Zustände (ui) jeder Session bleiben erhalten
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
     * Persönliche Einstellungen des Session-Admins an Cms weiterreichen
     * (Backend-Sprache, Zeitzone) - siehe Klassenkommentar.
     */
    private function applySettings(): void
    {
        $settings = (array)($this->data['details']['settings'] ?? []);
        if (empty($settings)) return;
        $this->container->get(Cms::class)->applyAdminSettings($settings);
    }

    /**
     * Rechte der Gruppe des Session-Admins laden (siehe Klassenkommentar "Rechte
     * der Gruppe"). Für Nicht-Superuser werden die Abfragen von Cms::select() auf
     * die zugewiesenen Instanzen beschränkt - ohne zugewiesene Instanz auf keine.
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
        // Bereichsebene: Datensätze und Templates nur aus lesbaren Bereichen
        $cms->setAreaAccess($access->readableAreaIds());
        // Inhaltssprache der Listen: nur lesbare Sprachen
        $cms->restrictLanguageMap($access->readableLanguageIds());
    }

    /**
     * Session laden (jeder Backend-Request, ApiSession::resume()).
     *
     * Geprüft werden Frist, Höchstlaufzeit, Oberfläche, die Signatur der Zeile, die
     * Admin-Zeile (Signatur, Status) und - nach dem Laden der Rechte - ob die Gruppe
     * die Oberfläche benutzen darf.
     *
     * @param string   $sessionId   Session-Id; leer = aus dem Cookie 'sessid'
     * @param string[] $interfaces  zulässige Oberflächen der Session (Backend-Einstiege:
     *                              nur 'backend')
     * @return bool                 false: keine gültige Session
     */
    public function load(string $sessionId = '', array $interfaces = ['backend']): bool
    {
        if (empty($sessionId)) {
            $sessionId = (string) ($_COOKIE['sessid'] ?? '');
        }
        
        // ungültige Länge? (latin)
        if (strlen($sessionId) != 43) return false;

        // Session laden
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

        // Frist für Untätigkeit - im Backend admin.max_inactive_sec, sonst api.*
        $seconds = (int)$this->config->get($interface === 'backend' ? 'admin.max_inactive_sec' : 'api.max_inactive_sec');
        if (strtotime((string)$data['access_date'] . ' UTC') < time() - $seconds) return false;

        // Signatur der Session-Zeile
        if (!$this->integrity->verifySession($sessionId, $adminId, (string)$data['sig'], $interface)) {
            return false;
        }
        // Admin-Zeile: Signatur, Status, gelöscht? Gruppe und Status gelten von hier,
        // nicht aus den gecachten details.
        $admin = $this->integrity->verifyAdmin($adminId);
        if ($admin === null || $admin['status_id'] !== 0 || $admin['deleted'] !== 0) {
            return false;
        }

        $this->data = $data;
        $this->data['details'] = json_decode((string)$data['details'], true) ?: [];
        $this->data['details']['group_id'] = $admin['group_id'];

        $this->applySettings();
        $this->applyAccess();

        // Darf die Gruppe die Oberfläche (noch) benutzen? (Access, "Oberflächen")
        if (!$this->container->get(Access::class)->interface($interface)) {
            $this->container->get(Access::class)->unload();
            $this->data = [];
            return false;
        }

        // Bei jedem Zugriff access_date aktualisieren
        // ("change" bezieht sich auf Aktivität des Administrators im Backend).
        // Nicht bei Abfragen im Hintergrund (Polling: Nachrichten-Abzeichen,
        // Hintergrundprozesse - Header X-Camindo-Background, cLoadContent-Option
        // 'background'): sie kommen auch von einem unbeaufsichtigten Fenster und
        // würden die Abmeldung nach admin.max_inactive_sec sonst endlos aufschieben.
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
     * Session beenden
     * @param string $sessionId  Session-Id; wenn leer dann in COOKIE['sessid'] 
     */
    public function close(string $sessionId = '')
    {
        if (empty($sessionId)) {
            $sessionId = (string) ($_COOKIE['sessid'] ?? '');
        }
        // Session aus der Datenbank löschen (sicherster Weg)
        $this->db->query('DELETE FROM `admin_session`'
            .' WHERE `session_id`=? ',
            's',
            [$sessionId]
        );
        $this->db->query('DELETE FROM `admin_session_record` WHERE `session_id` = ?', 's', [$sessionId]);
        // Rechte und Cookie nur, wenn es die laufende bzw. die des Browsers ist - eine
        // API-Session, die in einem Backend-Request endet, lässt beide stehen
        if ($sessionId === (string)($this->data['session_id'] ?? '') || empty($this->data['session_id'])) {
            $this->container->get(Access::class)->unload();
        }
        if ($sessionId === (string)($_COOKIE['sessid'] ?? '')) {
            $this->setCookie('', true);
        }
    }

    /**
     * Arbeitskopie dieser Session zuordnen (Eingabemaske, RecordBackendEdit) - beim
     * Abmelden verwirft Record::cancelSession() genau diese, nicht die offenen Masken
     * auf anderen Geräten desselben Admins.
     */
    public function registerWorkingCopy(int $recordId): void
    {
        $sessionId = (string)($this->data['session_id'] ?? '');
        if ($sessionId === '' || $recordId < 1) return;
        $this->db->query('INSERT IGNORE INTO `admin_session_record` (`session_id`, `record_id`) VALUES (?, ?)',
            'si', [$sessionId, $recordId]);
    }

    /**
     * Session an ein Anmeldegerät hängen (LoginDevices, `login_device`). Wird das
     * Gerät entfernt, endet die Session mit (Fremdschlüssel ON DELETE CASCADE).
     */
    public function attachDevice(string $sessionId, int $deviceId): void
    {
        if ($sessionId === '' || $deviceId < 1) return;
        $this->db->query('UPDATE `admin_session` SET `device_id` = ? WHERE `session_id` = ?',
            'is', [$deviceId, $sessionId]);
        if (($this->data['session_id'] ?? '') === $sessionId) $this->data['device_id'] = $deviceId;
    }

    /**
     * Alle Sessions eines Admins beenden - nach einem neuen Passwort, beim Entfernen
     * aller Geräte oder auf Wunsch ("andere Sitzungen beenden", AdminBackend::devices()).
     *
     * @param bool $keepCurrent  die laufende Session bleibt, wenn sie diesem Admin gehört
     * @return int               Anzahl beendeter Sessions
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
        // Zuordnung der Arbeitskopien beendeter Sessions (die Kopien selbst bleiben -
        // ihre Sperre verfällt, oder Record::cancelAll() verwirft sie)
        $this->db->query('DELETE FROM `admin_session_record`'
            . ' WHERE `session_id` NOT IN (SELECT `session_id` FROM `admin_session`)');
        return $count;
    }

    /**
     * Laufende (nicht abgelaufene) Sessions eines Admins, neueste zuerst.
     *
     * @return array  Liste aus session_id, interface, device_id (0 = ohne Gerät),
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
     * CSRF-Token dieser Session - leer ohne Session (17-csrf.md §2.4).
     *
     * Wird in Core/public_master/backend/index.php als cConfig.csrf_token ausgespielt
     * und nach einer Anmeldung von StartBackend::_loginComplete() nachgereicht (die
     * Seite wird dabei nicht neu geladen, das Token der alten Session gilt nicht mehr).
     */
    public function csrfToken(): string
    {
        $sessionId = (string)($this->data['session_id'] ?? '');
        return $this->integrity->csrfToken($sessionId);
    }

    /**
     * Das Token des laufenden Requests prüfen (17-csrf.md §2.4).
     *
     * Nur bei verändernden Methoden: GET und HEAD dürfen keinen Seiteneffekt haben,
     * und die Stylesheet- und Download-Endpunkte des Backends können gar keinen
     * Header tragen (backendExtensions/{Ext}/css.php als <link>,
     * Record/record_download.php als Fenster). Was schreibt, geht über POST.
     *
     * Das Token kommt als Header 'X-Camindo-Token' (cLoadContent() setzt ihn bei
     * jedem Request) oder als Feld '_token' im POST-Body - letzteres für Formulare,
     * die nicht über das Javascript laufen. Der Query-String ist bewusst KEINE
     * Quelle: dort landete das Token in Logs und Referern.
     *
     * Ohne Session gibt es nichts zu schützen - die Anmeldung selbst (Start/login.php,
     * step1/step2) läuft per POST, bevor eine Session existiert.
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
     * Verlangt die Konfiguration ein Secure-Cookie, obwohl der Request über http://
     * kommt? Dann verwirft der Browser das Cookie (außer Chrome/Firefox auf
     * localhost) und die Anmeldung scheitert ohne erkennbaren Grund - der
     * Anmeldedialog zeigt deshalb einen Hinweis (StartBackend::login()).
     * X-Forwarded-Proto zählt hier mit: es geht nur um den Hinweis, nicht um Rechte.
     */
    public function secureCookieOverHttp(): bool
    {
        if (empty($this->config->get('admin.cookie_secure'))) return false;
        $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        $https = $https || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return !$https;
    }

    /**
     * Session-Cookie 'sessid' setzen bzw. löschen (wie UserSession::setCookie()).
     *
     * HttpOnly immer: Javascript - auch eingeschleustes - kommt nicht an die
     * Session-Id. 'secure' und SameSite aus admin.cookie_secure / admin.cookie_samesite.
     * Kein Ablaufdatum: das Cookie lebt bis zum Schließen des Browsers, serverseitig
     * gilt admin.max_inactive_sec.
     *
     * @param string $sessionId  Session-Id aus create()
     * @param bool   $delete     Cookie löschen (Abmeldung)
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