<?php
/**
 * Dataset-Kindklasse für die Tabelle `scheduler` - zeitgesteuerte Aufgaben.
 *
 * Eine Aufgabe ist eine Zeile: was zu tun ist (`type`), wann (sechs Spalten in
 * Cron-Syntax) und wie lange es höchstens dauern darf (`max_minutes`). Ausgeführt
 * wird sie nicht hier, sondern von SchedulerRunner (Core/cron/scheduler.php), den
 * TriggerCron (Core/cron/cron.php, der einzige Cronjob) jede Minute anstößt.
 *
 * AUFGABENTYPEN
 * --------------------
 *     url        eine Adresse abrufen (curl), wahlweise mit HTTP-Auth; die Antwort
 *                wird unter var/custom/html abgelegt oder verworfen
 *     php        ein PHP-Script aus var/custom/php auf der Kommandozeile ausführen;
 *                Ausgabe wie oben
 *
 * Ausgaben (var/custom/html) und Scripte (var/custom/php) liegen getrennt: was eine
 * Aufgabe abholt oder erzeugt, kann nie als Script ausgeführt werden. Ausgabedateien
 * mit PHP-Endung sind zusätzlich gesperrt, Pfade dürfen nicht aufsteigen, und beide
 * Seiten prüfen den aufgelösten Pfad (realpath) - ein Symlink führt nicht hinaus.
 *     dbbackup   mysqldump nach var/dbbackup, backup-1.sql ist immer das jüngste;
 *                höchstens einmal am Tag, ältere über die Anzahl hinaus fallen weg
 *     logcleanup alte Protokolle löschen: Logdateien unter var/log (Sicherheits-
 *                protokolle mit eigener Frist) und die protokollierten Anmelde-
 *                versuche samt IP-Adresse in der Datenbank
 *     cachecleanup den Ausgabe-Cache von CMS:INCLUDE aufräumen (IncludeCache::cleanup()):
 *                abgelaufene Dateien und solche zu gelöschten oder geänderten
 *                Templates, liegen gebliebene Sperr- und temporäre Dateien
 *     template   ein Frontend-Template mit der Verwendung "zeitgesteuert"
 *                (template.type = 'scheduler') in einer Sprache seiner Instanz
 *                ausführen (Page::renderScheduled()) - mit dem Zeitfenster seit dem
 *                letzten erfolgreichen Lauf (details.window_end); Ausgabe wie oben
 *     fulltext   den Volltext der gewählten Bereiche (details.area_ids, leer = alle;
 *                details.only_stale: nur die als veraltet markierten)
 *                neu aufbauen (RecordService::rebuildFulltext()) - nach einer
 *                Änderung der Quellfelder eines Volltextfelds
 *
 * Wartungsaufgaben (MAINTENANCE_TYPES, ohne Einstellungen) - früher fest im
 * Cronjob verdrahtet, jetzt hier terminierbar:
 *     trash       Papierkorb leeren (ToolDatabase::purgeTrash(), admin.trash_retention_days)
 *     tmpcleanup  var/tmp und abgelaufene Exportdateien aufräumen
 *                 (admin.tmp_retention_hours, admin.export_retention_hours)
 *     privacy     fällige Aufträge der Lösch-Policies ausführen (PrivacyJobs::runDue())
 *     retention   Aufbewahrungsfristen: IP-Adressen kürzen (IpAnonymizer), abgelaufene
 *                 Audit-Einträge (AuditLog::purge()), interne Nachrichten
 *                 (Message::purgeExpired()), nie bestätigte Registrierungen
 *                 (RecordUser::purgeUnconfirmed())
 *     auditverify Audit-Log prüfen (AuditLog::verifyAll(), Ergebnis für SystemHealth)
 *     updatecheck nach Updates und Sicherheitslücken suchen (UpdateCheck::run())
 *
 * Ebenfalls ohne Einstellungen, aber keine Wartung (fehlt sie, warnt die Startseite
 * nicht):
 *     messagedigest  Zusammenfassung der internen Nachrichten per E-Mail an die
 *                 Benutzer, die sie eingeschaltet haben (MessageDigest)
 *
 * VORGABE-AUFGABEN
 * --------------------
 * DEFAULT_TASKS legt der Installer an (createDefaults(), nachts zwischen 2 und 4 Uhr
 * verteilt, tmpcleanup stündlich, messagedigest morgens um 7); `camindo.php scheduler defaults` ergänzt fehlende Typen in einer
 * bestehenden Installation. Gibt es einen Typ schon, bleibt er unangetastet.
 *
 * Die typabhängigen Einstellungen liegen im JSON `details` (Punktschreibweise im
 * Schema, wie bei Field und Converter). Das Passwort der HTTP-Auth wird
 * verschlüsselt abgelegt (hookOnSave()/hookOnLoaded()); ein leeres Passwortfeld
 * beim Speichern bedeutet "bisheriges behalten".
 *
 * UNTER WELCHEM ADMIN
 * --------------------
 * `admin_id`: jede Aufgabe läuft unter einem Admin (SchedulerRunner) - neue unter
 * dem Admin 'scheduler' (DEFAULT_ADMIN, vom Installer angelegt). Er muss aktiv sein
 * und seine Gruppe die Oberfläche Kommandozeile benutzen dürfen (adminError()).
 * Wird er gelöscht, bleibt die Aufgabe ohne Admin stehen und läuft nicht mehr.
 *
 * ZEITSTEUERUNG
 * --------------------
 * Je Spalte ein Cron-Ausdruck: `*`, ein Wert, ein Bereich `1-5`, ein Schritt `* / 10`
 * oder `1-5/2`, und davon Listen mit Komma (`1,15,30`). Aus den sechs Ausdrücken
 * rechnet nextRun() den nächsten Lauf - in der Zeitzone system.timezone, in der
 * auch der Cronjob läuft - und legt ihn in `next_run` ab; der Runner vergleicht nur
 * noch mit der Uhr. Ein Ausdruck, der nie zutrifft (31. Februar), lässt `next_run`
 * leer, die Aufgabe läuft dann nicht.
 *
 * @see Camindo\Core\Modules\Scheduler\SchedulerRunner   Ausführung
 * @see Camindo\Core\Modules\Scheduler\SchedulerBackend  Backend-Oberfläche
 * @see Camindo\Core\cron\TriggerCron::tick1m()          der Anstoß je Minute
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Scheduler;

use Camindo\Core\Modules\Instance\Instance;
use Camindo\Core\Dataset;
use Camindo\Core\Modules\Area\Area;

class Scheduler extends Dataset {

    public const TYPES = ['url', 'php', 'dbbackup', 'logcleanup', 'cachecleanup', 'template', 'fulltext',
        'trash', 'tmpcleanup', 'privacy', 'retention', 'auditverify', 'updatecheck', 'messagedigest'];

    /** Wartungsaufgaben ohne eigene Einstellungen (siehe Klassenkommentar) */
    public const MAINTENANCE_TYPES = ['trash', 'tmpcleanup', 'privacy', 'retention', 'auditverify', 'updatecheck'];

    /**
     * Aufgaben einer neuen Installation (createDefaults()): Typ => Minute, Stunde und
     * Details - täglich, nachts zwischen 2 und 4 Uhr verteilt. Die Sicherung läuft
     * zuerst, also vor dem Leeren des Papierkorbs. Ausnahme tmpcleanup: stündlich -
     * Exportdateien (auch Datenauskünfte) sollen nicht bis zu einem Tag über
     * admin.export_retention_hours hinaus liegen bleiben - und messagedigest
     * morgens, zu Beginn des Arbeitstags.
     */
    public const DEFAULT_TASKS = [
        'dbbackup'     => ['minute' => 0,  'hour' => 2, 'details' => ['backups' => 7]],
        'tmpcleanup'   => ['minute' => 10, 'hour' => '*'],
        'trash'        => ['minute' => 20, 'hour' => 2],
        'privacy'      => ['minute' => 30, 'hour' => 2, 'max_minutes' => 60],
        'retention'    => ['minute' => 45, 'hour' => 2, 'max_minutes' => 30],
        'logcleanup'   => ['minute' => 0,  'hour' => 3, 'details' => ['log_days' => 90, 'security_days' => 365, 'attempt_days' => 30]],
        'cachecleanup' => ['minute' => 10, 'hour' => 3],
        'fulltext'     => ['minute' => 20, 'hour' => 3, 'max_minutes' => 60, 'details' => ['only_stale' => 1]],
        'auditverify'  => ['minute' => 40, 'hour' => 3, 'max_minutes' => 60],
        'updatecheck'  => ['minute' => 55, 'hour' => 3],
        'messagedigest' => ['minute' => 0, 'hour' => 7],
    ];

    /** Typen, deren Ausgabe als Bericht abonniert werden kann (details.report) */
    public const REPORT_TYPES = ['url', 'php'];

    /** Name des Admins, unter dem neue Aufgaben laufen (Installer::createSchedulerAdmin()) */
    public const DEFAULT_ADMIN = 'scheduler';

    /** Logdateien mit eigener, meist längerer Frist (details.security_days) */
    public const SECURITY_LOGS = ['security', 'csp'];

    // Ergebnis des letzten Laufs (`last_status`)
    public const STATUS_OK      = 0;
    public const STATUS_RUNNING = 1;
    public const STATUS_ERROR   = 2;

    /** Unterverzeichnisse von var/custom: Ausgaben der Aufgaben bzw. PHP-Scripte (Typ 'php') */
    public const OUTPUT_DIR = 'html';
    public const SCRIPT_DIR = 'php';

    // Die sechs Cron-Spalten mit ihrem Wertebereich
    public const CRON_FIELDS = [
        'cron_minute'  => [0, 59],
        'cron_hour'    => [0, 23],
        'cron_day'     => [1, 31],
        'cron_month'   => [1, 12],
        'cron_year'    => [2026, 2036],
        'cron_weekday' => [0, 6],
    ];

    // Wie weit nextRun() höchstens vorausrechnet (Tage)
    private const NEXT_RUN_HORIZON = 366 * 11;

    // Audit-Log: der Laufzeitstand gehört dem Runner, nicht der Maske
    protected array $auditIgnore = ['next_run', 'last_run', 'last_status', 'last_message'];

    protected function hookDefinition(): void
    {
        $this->tableName  = 'scheduler';
        $this->moduleName = 'Scheduler';
        $this->defaultSortOrder = 'label ASC';
        $this->schema = [
            'scheduler_id' => [
                'type'  => 'integer',
                'label' => 'scheduler_id',
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#scheduler.field_label',
                'max'   => 64,
                'extension' => 'Snippets',   // Core-Extension: Snippet auswählen (#prefix.key)
                'flags' => ['trim', 'not_empty'],
            ],
            'type' => [
                'type'  => 'chars',
                'label' => '#scheduler.field_type',
                'max'   => 16,
                'flags' => ['not_empty'],
            ],
            // Admin, unter dem die Aufgabe läuft (SchedulerRunner, ApiSession::runAs()):
            // seine Rechte gelten, im Audit-Log erscheint er mit der Herkunft
            // 'scheduler'; seine Gruppe braucht die Oberfläche Kommandozeile
            'admin_id' => [
                'type'  => 'id',
                'display_type' => 'select',
                'label' => '#scheduler.field_admin_id',
                'tooltip' => '#scheduler.tooltip_field_admin_id',
                'table' => 'admin',
            ],
            'enabled' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#scheduler.field_enabled',
                'default' => 1,
            ],
            'max_minutes' => [
                'type'  => 'integer',
                'label' => '#scheduler.field_max_minutes',
                'suffix' => '#scheduler.unit_minutes',
                'min'   => 1,
                'max'   => 1440,
                'default' => 10,
                'flags' => ['not_empty'],
            ],
            'log' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#scheduler.field_log',
                'tooltip' => '#scheduler.tooltip_field_log',
            ],
            'cron_minute' => [
                'type'  => 'chars',
                'label' => '#scheduler.field_cron_minute',
                'note'  => '0 - 59',
                'max'   => 64,
                'default' => '*',
                'flags' => ['trim', 'not_empty'],
            ],
            'cron_hour' => [
                'type'  => 'chars',
                'label' => '#scheduler.field_cron_hour',
                'note'  => '0 - 23',
                'max'   => 64,
                'default' => '*',
                'flags' => ['trim', 'not_empty'],
            ],
            'cron_day' => [
                'type'  => 'chars',
                'label' => '#scheduler.field_cron_day',
                'note'  => '1 - 31',
                'max'   => 64,
                'default' => '*',
                'flags' => ['trim', 'not_empty'],
            ],
            'cron_month' => [
                'type'  => 'chars',
                'label' => '#scheduler.field_cron_month',
                'note'  => '1 - 12',
                'max'   => 64,
                'default' => '*',
                'flags' => ['trim', 'not_empty'],
            ],
            'cron_year' => [
                'type'  => 'chars',
                'label' => '#scheduler.field_cron_year',
                'note'  => '2026 - 2036',
                'max'   => 64,
                'default' => '*',
                'flags' => ['trim', 'not_empty'],
            ],
            'cron_weekday' => [
                'type'  => 'chars',
                'label' => '#scheduler.field_cron_weekday',
                'note'  => '0 - 6',
                'tooltip' => '#scheduler.tooltip_field_cron_weekday',
                'max'   => 64,
                'default' => '*',
                'flags' => ['trim', 'not_empty'],
            ],
            'details' => [
                'type' => 'json',
                'display_type' => 'none',
                'label' => 'details',
            ],
            // url
            'details.url' => [
                'type'  => 'chars',
                'label' => '#scheduler.field_url',
                'max'   => 255,
                'flags' => ['trim'],
            ],
            'details.auth' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#scheduler.field_auth',
            ],
            'details.user' => [
                'type'  => 'chars',
                'label' => '#scheduler.field_user',
                'max'   => 128,
                'flags' => ['trim'],
            ],
            'details.password' => [
                'type'  => 'password',
                'display_type' => 'password',
                'label' => '#scheduler.field_password',
                'note'  => '#scheduler.note_field_password',
                'max'   => 255,
            ],
            // template
            'details.template_id' => [
                'type'  => 'integer',
                'display_type' => 'select',
                'label' => '#scheduler.field_template_id',
                'tooltip' => '#scheduler.tooltip_field_template_id',
            ],
            'details.language_id' => [
                'type'  => 'integer',
                'display_type' => 'select',
                'label' => '#scheduler.field_language_id',
                'tooltip' => '#scheduler.tooltip_field_language_id',
            ],
            // fulltext
            'details.area_ids' => [
                'type'  => 'json',
                'display_type' => 'multiselect',
                'label' => '#scheduler.field_area_ids',
                'tooltip' => '#scheduler.tooltip_field_area_ids',
                'height' => 'auto',
            ],
            'details.only_stale' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#scheduler.field_only_stale',
                'tooltip' => '#scheduler.tooltip_field_only_stale',
            ],
            // url + php: wählbarer Bericht (Abo in den persönlichen Einstellungen)
            'details.report' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#scheduler.field_report',
                'tooltip' => '#scheduler.tooltip_field_report',
            ],
            'details.report_group_ids' => [
                'type'  => 'json',
                'display_type' => 'multiselect',
                'label' => '#scheduler.field_report_group_ids',
                'tooltip' => '#scheduler.tooltip_field_report_group_ids',
                'height' => 'auto',
            ],
            // url + php + template
            'details.output' => [
                'type'  => 'chars',
                'display_type' => 'radio',
                'label' => '#scheduler.field_output',
                'default' => 'save',
            ],
            'details.output_file' => [
                'type'  => 'chars',
                'label' => '#scheduler.field_output_file',
                'prefix' => 'var/custom/' . self::OUTPUT_DIR . '/',
                'max'   => 255,
                'flags' => ['trim'],
            ],
            // php
            'details.script' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#scheduler.field_script',
                'prefix' => 'var/custom/' . self::SCRIPT_DIR . '/',
                'max'   => 255,
            ],
            // dbbackup
            'details.backups' => [
                'type'  => 'integer',
                'label' => '#scheduler.field_backups',
                'tooltip' => '#scheduler.tooltip_field_backups',
                'min'   => 1,
                'max'   => 365,
                'default' => 7,
            ],
            // logcleanup
            'details.log_days' => [
                'type'  => 'integer',
                'label' => '#scheduler.field_log_days',
                'tooltip' => '#scheduler.tooltip_field_log_days',
                'suffix' => '#scheduler.unit_days',
                'min'   => 1,
                'max'   => 3650,
                'default' => 90,
            ],
            'details.security_days' => [
                'type'  => 'integer',
                'label' => '#scheduler.field_security_days',
                'tooltip' => '#scheduler.tooltip_field_security_days',
                'suffix' => '#scheduler.unit_days',
                'min'   => 1,
                'max'   => 3650,
                'default' => 365,
            ],
            'details.attempt_days' => [
                'type'  => 'integer',
                'label' => '#scheduler.field_attempt_days',
                'tooltip' => '#scheduler.tooltip_field_attempt_days',
                'suffix' => '#scheduler.unit_days',
                'min'   => 1,
                'max'   => 3650,
                'default' => 30,
            ],
            // Laufzeitstand - schreibt nur SchedulerRunner (direkt per SQL)
            'next_run' => [
                'type'  => 'datetime',
                'label' => '#scheduler.field_next_run',
            ],
            'last_run' => [
                'type'  => 'datetime',
                'label' => '#scheduler.field_last_run',
            ],
            'last_status' => [
                'type'  => 'integer',
                'label' => '#scheduler.field_last_status',
            ],
            'last_message' => [
                'type'  => 'text',
                'label' => '#scheduler.field_last_message',
            ],
        ];
    }

    /**
     * Felder, die die Eingabemaske speichert - ohne den Laufzeitstand (next_run,
     * last_*), den ausschließlich der Runner schreibt.
     *
     * @return string[]
     */
    /**
     * Kann die Aufgabe unter diesem Admin laufen? null = ja, sonst die Snippet-Id der
     * Meldung (Speichern, SchedulerRunner).
     */
    public function adminError(int $adminId): ?string
    {
        if ($adminId < 1) return 'scheduler.error_admin_missing';
        $admin = $this->container->get(\Camindo\Core\Integrity::class)->verifyAdmin($adminId);
        if ($admin === null || $admin['status_id'] !== 0 || $admin['deleted'] !== 0) return 'scheduler.error_admin_missing';
        if (!$this->container->get(\Camindo\Core\Access::class)->groupInterface($admin['group_id'], 'cli')) {
            return 'scheduler.error_admin_interface';
        }
        return null;
    }

    /**
     * Admins, unter denen Aufgaben laufen können (Auswahl der Maske):
     * admin_id => Name - aktiv und mit der Oberfläche Kommandozeile.
     */
    public function adminOptions(): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `admin_id`, `name` FROM `admin` WHERE `deleted` = 0 AND `status_id` = 0 ORDER BY `name`");
            $rows = $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
        $options = [];
        foreach ($rows as $row) {
            if ($this->adminError((int)$row['admin_id']) === null) $options[(int)$row['admin_id']] = (string)$row['name'];
        }
        return $options;
    }

    /**
     * Vorgabe-Aufgaben anlegen (DEFAULT_TASKS) - nur Typen, für die es noch keine
     * Aufgabe gibt (auch keine ausgeschaltete). Beschriftung ist das Snippet des Typs,
     * Admin der des Zeitplaners (hookOnCreate()); mit Protokoll.
     *
     * @return array  Typ => scheduler_id der angelegten Aufgaben; Fehler in Cms::errors
     */
    public function createDefaults(): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT DISTINCT `type` FROM `scheduler`");
            $existing = array_map('strval', $this->db->fetchColumn());
        }
        finally {
            $this->db->popResult();
        }
        $created = [];
        foreach (self::DEFAULT_TASKS as $type => $task) {
            if (in_array($type, $existing, true)) continue;
            $data = $this->init([
                'label' => '#scheduler.label_type_' . $type,
                'type' => $type,
                'enabled' => 1,
                'log' => 1,
                'max_minutes' => (int)($task['max_minutes'] ?? 10),
                'cron_minute' => (string)$task['minute'],
                'cron_hour' => (string)$task['hour'],
                'details' => (array)($task['details'] ?? []),
            ]);
            unset($data['scheduler_id']);
            $id = $this->save($this->editableFields(), $data);
            if ($id !== null) $created[$type] = $id;
        }
        return $created;
    }

    public function editableFields(): array
    {
        return [
            'label', 'type', 'admin_id', 'enabled', 'max_minutes', 'log',
            'cron_minute', 'cron_hour', 'cron_day', 'cron_month', 'cron_year', 'cron_weekday',
            'details',
        ];
    }

    /**
     * Audit-Log: das HTTP-Auth-Passwort liegt verschlüsselt (bei jedem Speichern ein
     * neuer Geheimtext) - verglichen wird der Klartext (siehe Dataset::auditSecret()).
     */
    protected function auditSecret(string $key, string $value): string
    {
        if ($key !== 'details.password' || $value === '') return $value;
        try {
            return $this->crypt->decrypt($value);
        }
        catch (\Throwable) {
            return $value;
        }
    }

    /**
     * Nach dem Laden: Passwort entschlüsseln (für den Runner - die Maske zeigt es nie).
     */
    protected function hookOnLoaded(array &$data): void
    {
        $password = (string)($data['details']['password'] ?? '');
        if ($password !== '') {
            $data['details']['password'] = $this->crypt->decrypt($password);
        }
    }

    /**
     * Vor dem Speichern: Typ prüfen und festhalten, Cron-Ausdrücke prüfen,
     * typabhängige Pflichtangaben, Passwort verschlüsseln.
     *
     * Der Typ steht nach dem Anlegen fest - die Details passen nur zu ihm.
     */
    /**
     * Neue Aufgabe: läuft unter dem Admin des Zeitplaners (DEFAULT_ADMIN), sofern es
     * ihn gibt.
     */
    protected function hookOnCreate(array &$data): void
    {
        if (!empty($data['admin_id'])) return;
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `admin_id` FROM `admin` WHERE `name` = ? AND `deleted` = 0", 's', [self::DEFAULT_ADMIN]);
            $data['admin_id'] = (int)($this->db->fetchNum()[0] ?? 0);
        }
        finally {
            $this->db->popResult();
        }
    }

    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        $schema = $this->schema;
        $isNew = empty($data['scheduler_id']);
        $type = (string)($data['type'] ?? '');

        // Typ
        if (!$isNew) {
            $type = (string)($previousData['type'] ?? $type);
            $data['type'] = $type;
        }
        if (!in_array($type, self::TYPES, true)) {
            $this->createFieldError('scheduler.error_unknown_type', 'type', $schema['type']['label'], $type);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // Admin: vorhanden, aktiv, Gruppe mit der Oberfläche Kommandozeile
        // (ein Feld vom Typ 'id' läuft nicht in die not_empty-Prüfung)
        if (in_array('admin_id', $fields, true) && ($error = $this->adminError((int)($data['admin_id'] ?? 0))) !== null) {
            $this->createFieldError($error, 'admin_id', $schema['admin_id']['label']);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // Cron-Ausdrücke
        foreach (self::CRON_FIELDS as $name => [$min, $max]) {
            if (!in_array($name, $fields, true)) continue;
            $expr = str_replace(' ', '', (string)($data[$name] ?? ''));
            $data[$name] = $expr;
            if (!$this->cronValid($expr, $min, $max)) {
                $this->createFieldError('scheduler.error_cron_syntax', $name, $schema[$name]['label'], "$min - $max");
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }

        // Details je Typ
        if (in_array('details', $fields, true)) {
            $details = (array)($data['details'] ?? []);
            $previous = (array)($previousData['details'] ?? []);
            $output = (string)($details['output'] ?? 'save');
            $details['output'] = ($output === 'discard') ? 'discard' : 'save';
            // Angaben anderer Typen neutral halten - die Subfelder werden alle
            // geprüft, auch die, die zu diesem Typ nicht gehören
            $details['backups'] = max(1, (int)($details['backups'] ?? 7));
            foreach (['log_days' => 90, 'security_days' => 365, 'attempt_days' => 30] as $key => $default) {
                $details[$key] = max(1, (int)($details[$key] ?? $default));
            }
            if ($type !== 'url') {
                $details['url'] = '';
                $details['auth'] = 0;
                $details['user'] = '';
                $details['password'] = '';
            }
            if ($type !== 'php') $details['script'] = '';
            // Wählbarer Bericht (url, php): mindestens eine Benutzergruppe
            if (in_array($type, self::REPORT_TYPES, true) && !empty($details['report'])) {
                $details['report'] = 1;
                $details['report_group_ids'] = array_values(array_intersect(
                    $this->system->sanitizeIds((array)($details['report_group_ids'] ?? []), true),
                    array_keys($this->groupOptions())
                ));
                if (empty($details['report_group_ids'])) {
                    $this->createFieldError('scheduler.error_report_groups', 'details.report_group_ids', $schema['details.report_group_ids']['label']);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }
            else {
                $details['report'] = 0;
                $details['report_group_ids'] = [];
            }
            if ($type !== 'template') {
                $details['template_id'] = 0;
                $details['language_id'] = 0;
            }
            // Bereiche (fulltext): nur bekannte, leer = alle
            $details['area_ids'] = ($type === 'fulltext')
                ? array_values(array_intersect($this->system->sanitizeIds((array)($details['area_ids'] ?? []), true), array_keys($this->fulltextAreas())))
                : [];
            // nur Bereiche, deren Volltext als veraltet markiert ist (FulltextState)
            $details['only_stale'] = ($type === 'fulltext' && !empty($details['only_stale'])) ? 1 : 0;
            // Ende des letzten Zeitfensters: schreibt nur der Runner, die Maske
            // kennt es nicht - beim Speichern behalten
            $details['window_end'] = (string)($previous['window_end'] ?? '');

            if ($type === 'url') {
                if ((string)($details['url'] ?? '') === '') {
                    $this->createFieldError('error_value_mandatory', 'details.url', $schema['details.url']['label']);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                if (!preg_match('~^https?://~i', (string)$details['url'])) {
                    $this->createFieldError('error_invalid_format', 'details.url', $schema['details.url']['label']);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                $details['auth'] = (int)!empty($details['auth']);
                // Passwort: leer = bisheriges behalten (das liegt entschlüsselt in
                // previousData, siehe hookOnLoaded()); gespeichert wird verschlüsselt
                $password = (string)($details['password'] ?? '');
                if ($password === '') $password = (string)($previous['password'] ?? '');
                $details['password'] = ($password !== '') ? $this->crypt->encrypt($password) : '';
                if ($details['auth'] === 1 && (string)($details['user'] ?? '') === '') {
                    $this->createFieldError('error_value_mandatory', 'details.user', $schema['details.user']['label']);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }
            elseif ($type === 'template') {
                $templateId = (int)($details['template_id'] ?? 0);
                $templates = $this->schedulerTemplates();
                if (!isset($templates[$templateId])) {
                    $this->createFieldError('scheduler.error_template_invalid', 'details.template_id', $schema['details.template_id']['label']);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                $details['template_id'] = $templateId;
                // Sprache der Instanz des Templates; 0 = deren Hauptsprache
                $languageId = (int)($details['language_id'] ?? 0);
                $details['language_id'] = isset($templates[$templateId]['languages'][$languageId]) ? $languageId : 0;
            }
            elseif ($type === 'php') {
                $script = (string)($details['script'] ?? '');
                if ($this->scriptFile($script) === null) {
                    $this->createFieldError('scheduler.error_script_not_found', 'details.script', $schema['details.script']['label'], $script);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }

            if (in_array($type, ['url', 'php', 'template'], true)) {
                if ($details['output'] === 'save') {
                    $file = $this->outputPath((string)($details['output_file'] ?? ''));
                    if ($file === null) {
                        $this->createFieldError('scheduler.error_output_file', 'details.output_file', $schema['details.output_file']['label']);
                        throw new \Exception('#' . $this->cms->errorMsgLast());
                    }
                    $details['output_file'] = $file;
                }
            }
            $data['details'] = $details;
        }
    }

    /**
     * Nach dem Speichern: nächsten Lauf berechnen und eintragen.
     *
     * Hier und nicht in hookOnSave(), weil `next_run` nicht zu den Feldern der
     * Maske gehört (editableFields()); ein direktes UPDATE, noch in der Transaktion.
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $id = (int)($data['scheduler_id'] ?? 0);
        if ($id < 1) return;
        $row = $data + $previousData;
        $nextRun = !empty($row['enabled']) ? $this->nextRun($row) : null;
        $this->db->query(
            "UPDATE `scheduler` SET `next_run` = ? WHERE `scheduler_id` = ?",
            'si', [$nextRun !== null ? $this->systemTime($nextRun) : null, $id]
        );
    }

    // Cron-Syntax
    // ====================

    /**
     * Ist ein Cron-Ausdruck gültig? Liste (Komma) von `*`, `n`, `a-b`, `* /s`, `a-b/s`,
     * alle Zahlen im erlaubten Bereich.
     */
    public function cronValid(string $expr, int $min, int $max): bool
    {
        if ($expr === '') return false;
        foreach (explode(',', $expr) as $part) {
            if (!preg_match('/^(\*|(\d+)(?:-(\d+))?)(?:\/(\d+))?$/', $part, $m)) return false;
            if ($m[1] !== '*') {
                $a = (int)$m[2];
                $b = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : $a;
                if ($a < $min || $a > $max || $b < $min || $b > $max || $b < $a) return false;
            }
            if (isset($m[4]) && (int)$m[4] < 1) return false;
        }
        return true;
    }

    /**
     * Trifft ein Cron-Ausdruck auf einen Wert zu?
     */
    public function cronMatches(string $expr, int $value, int $min): bool
    {
        foreach (explode(',', $expr) as $part) {
            if (!preg_match('/^(\*|(\d+)(?:-(\d+))?)(?:\/(\d+))?$/', $part, $m)) continue;
            if ($m[1] === '*') {
                $a = $min;
                $b = PHP_INT_MAX;
            }
            else {
                $a = (int)$m[2];
                $b = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : $a;
            }
            if ($value < $a || $value > $b) continue;
            $step = isset($m[4]) ? max(1, (int)$m[4]) : 1;
            if (($value - $a) % $step === 0) return true;
        }
        return false;
    }

    /**
     * Nächster Lauf einer Aufgabe nach einem Zeitpunkt - als Unix-Zeit, oder null,
     * wenn die Ausdrücke im Horizont (rund elf Jahre) nie zutreffen.
     *
     * Gerechnet wird in der Zeitzone des Systems (system.timezone), in der der
     * Cronjob läuft: erst der Tag (Jahr, Monat, Tag, Wochentag), darin die Stunde,
     * darin die Minute - so bleibt die Suche auch bei seltenen Ausdrücken kurz.
     *
     * @param array    $data  Zeile aus `scheduler` (die sechs cron_*-Spalten)
     * @param int|null $from  Zeitpunkt, nach dem gesucht wird (null = jetzt)
     */
    public function nextRun(array $data, ?int $from = null): ?int
    {
        $from ??= time();
        $start = (new \DateTimeImmutable('@' . $from))->setTimezone($this->systemTimezone());
        $start = $start->setTime((int)$start->format('G'), (int)$start->format('i'))->modify('+1 minute');
        $day = $start->setTime(0, 0);
        $f = fn(string $name) => (string)($data[$name] ?? '*');

        for ($d = 0; $d < self::NEXT_RUN_HORIZON; $d++, $day = $day->modify('+1 day')) {
            if (!$this->cronMatches($f('cron_year'), (int)$day->format('Y'), self::CRON_FIELDS['cron_year'][0])) continue;
            if (!$this->cronMatches($f('cron_month'), (int)$day->format('n'), 1)) continue;
            if (!$this->cronMatches($f('cron_day'), (int)$day->format('j'), 1)) continue;
            if (!$this->cronMatches($f('cron_weekday'), (int)$day->format('w'), 0)) continue;
            for ($h = 0; $h < 24; $h++) {
                if (!$this->cronMatches($f('cron_hour'), $h, 0)) continue;
                for ($i = 0; $i < 60; $i++) {
                    if (!$this->cronMatches($f('cron_minute'), $i, 0)) continue;
                    $candidate = $day->setTime($h, $i);
                    if ($candidate >= $start) return $candidate->getTimestamp();
                }
            }
        }
        return null;
    }

    /**
     * Zeitzone des Systems (system.timezone) - die des Cronjobs.
     */
    public function systemTimezone(): \DateTimeZone
    {
        return new \DateTimeZone((string)($this->config->get('system.timezone') ?: date_default_timezone_get()));
    }

    /**
     * Unix-Zeit als "Y-m-d H:i:s" in der Zeitzone des Systems - das Format der
     * Spalten next_run / last_run (unabhängig von der Zeitzone des Admins).
     */
    public function systemTime(?int $timestamp = null): string
    {
        return (new \DateTimeImmutable('@' . ($timestamp ?? time())))
            ->setTimezone($this->systemTimezone())->format('Y-m-d H:i:s');
    }

    // Dateien unter var/custom
    // ====================

    /**
     * Wählbare Templates für den Aufgabentyp template: Verwendung "zeitgesteuert"
     * (template.type = 'scheduler'), aus Instanzen, auf die der Admin Zugriff hat
     * (auf der Kommandozeile ohne Admin: alle).
     *
     * @return array  template_id => ['label' => "Instanz · bereich.name",
     *                'instance_id' => int, 'languages' => [language_id => Name]]
     */
    public function schedulerTemplates(): array
    {
        $this->db->pushResult();
        $this->db->query(
            "SELECT `t`.`template_id`, `t`.`name`, `t`.`instance_id`, `a`.`name` AS `area_name`, `i`.`label` AS `instance_label`"
            . " FROM `template` `t`"
            . " INNER JOIN `area` `a` ON `a`.`area_id` = `t`.`area_id` AND `a`.`deleted` = 0"
            . " INNER JOIN `instance` `i` ON `i`.`instance_id` = `t`.`instance_id`"
            . " WHERE `t`.`type` = 'scheduler' ORDER BY " . Instance::orderSql('i') . ", `a`.`name`, `t`.`name`"
        );
        $rows = $this->db->fetchAll();
        $this->db->popResult();

        $access = $this->container->get(\Camindo\Core\Access::class);
        $cache = $this->container->get(\Camindo\Core\InstanceCache::class);
        $list = [];
        foreach ($rows as $row) {
            $instanceId = (int)$row['instance_id'];
            if ($access->isLoaded() && !$access->instance($instanceId)) continue;
            $languages = [];
            foreach ($cache->languagesOf([$instanceId]) as $languageId => $language) {
                $languages[(int)$languageId] = (string)($language['label'] ?? $language['code'] ?? $languageId);
            }
            $list[(int)$row['template_id']] = [
                'label' => $this->cms->textOrSnippet((string)$row['instance_label']) . ' · ' . $row['area_name'] . '.' . $row['name'],
                'instance_id' => $instanceId,
                'languages' => $languages,
            ];
        }
        return $list;
    }

    /**
     * Bereiche für den Typ 'fulltext': die Inhaltsbereiche (Area::CONTENT_TYPES),
     * in Baumreihenfolge - für die Detailansicht und die Prüfung beim Speichern.
     *
     * @return array  area_id => "Instanz · Bezeichnung (name)"
     */
    public function fulltextAreas(): array
    {
        $this->db->pushResult();
        $this->db->query(
            "SELECT `a`.`area_id`, `a`.`label`, `a`.`name`, `i`.`label` AS `instance_label`"
            . " FROM `area` `a` INNER JOIN `instance` `i` ON `i`.`instance_id` = `a`.`instance_id`"
            . " WHERE `a`.`deleted` = 0 AND `a`.`type` IN ('" . implode("','", Area::CONTENT_TYPES) . "')"
            . " ORDER BY " . Instance::orderSql('i') . ", `a`.`lft`"
        );
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        $list = [];
        foreach ($rows as $row) {
            $list[(int)$row['area_id']] = $this->cms->_strip_tags($this->cms->textOrSnippet((string)$row['instance_label'])
                . ' · ' . $this->cms->textOrSnippet((string)$row['label'])) . ' (' . $row['name'] . ')';
        }
        return $list;
    }

    /**
     * Auswahl der Bereiche für den Typ 'fulltext' (Mehrfachauswahl der Maske) -
     * hierarchisch wie der Bereichsbaum im Modul Record
     * (RecordBackendFolderList::folderList()): Ordner nur, wenn darunter ein
     * Inhaltsbereich liegt (Area::loadAreas()), eingerückt nach `depth`; sind
     * Mandanten eingerichtet, je Instanz ein Hauptordner darüber.
     *
     * Mandanten und Ordner sind nicht wählbar: ihre Schlüssel tragen den Präfix
     * 'disabled' (Backend::formField() rendert sie als disabled).
     *
     * @return array  area_id|'disabled_'.area_id|'disabled_i'.instance_id => Label
     */
    public function fulltextAreaOptions(): array
    {
        $area = $this->container->get(Area::class);
        $instanceCache = $this->container->get(\Camindo\Core\InstanceCache::class);
        $options = [];
        $this->db->pushResult();
        try {
            if ($instanceCache->hasMultipleInstances()) {
                foreach ($instanceCache->instanceLabels() as $instanceId => $label) {
                    $area->loadAreas(Area::CONTENT_TYPES, (int)$instanceId);
                    $rows = $this->db->fetchAll();
                    if (empty($rows)) continue;
                    $options['disabled_i' . (int)$instanceId] = $this->cms->textOrSnippet($label);
                    $options += $this->areaTreeOptions($rows, 1);
                }
            }
            else {
                $area->loadAreas(Area::CONTENT_TYPES);
                $options = $this->areaTreeOptions($this->db->fetchAll(), 0);
            }
        }
        finally {
            $this->db->popResult();
        }
        return $options;
    }

    /**
     * Zeilen von Area::loadAreas() als eingerückte Optionen; Ordner gesperrt.
     *
     * @param int $depthOffset  1 unter einem Mandanten-Hauptordner
     */
    private function areaTreeOptions(array $rows, int $depthOffset): array
    {
        $options = [];
        foreach ($rows as $row) {
            $areaId = (int)$row['area_id'];
            $label = str_repeat('&nbsp; ', ((int)$row['depth'] + $depthOffset) * 2) . $this->cms->textOrSnippet((string)$row['label']);
            $options[$row['type'] === 'folder' ? 'disabled_' . $areaId : (string)$areaId] = $label;
        }
        return $options;
    }

    // Berichte
    // ====================

    /**
     * Benutzergruppen für details.report_group_ids.
     *
     * @return array  group_id => Bezeichnung
     */
    public function groupOptions(): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `group_id`, `label` FROM `group` ORDER BY `label`");
            $rows = $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
        $options = [];
        foreach ($rows as $row) {
            $options[(int)$row['group_id']] = $this->cms->_strip_tags($this->cms->textOrSnippet((string)$row['label']));
        }
        return $options;
    }

    /**
     * Berichte, die Admins einer Gruppe abonnieren können (persönliche
     * Einstellungen): Aufgaben vom Typ url/php mit details.report und der Gruppe in
     * details.report_group_ids - auch ausgesetzte.
     *
     * @return array  scheduler_id => Bezeichnung
     */
    public function reportsForGroup(int $groupId): array
    {
        if ($groupId < 1) return [];
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `scheduler_id`, `label`, `details` FROM `scheduler`"
                . " WHERE `type` IN ('" . implode("','", self::REPORT_TYPES) . "') ORDER BY `label`"
            );
            $rows = $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
        $reports = [];
        foreach ($rows as $row) {
            $details = json_decode((string)($row['details'] ?? ''), true) ?: [];
            if (empty($details['report'])) continue;
            if (!in_array($groupId, array_map('intval', (array)($details['report_group_ids'] ?? [])), true)) continue;
            $reports[(int)$row['scheduler_id']] = $this->cms->_strip_tags($this->cms->textOrSnippet((string)$row['label']));
        }
        return $reports;
    }

    /**
     * Abonnenten eines Berichts: aktive Admins, deren Gruppe (noch) zugelassen ist
     * und die ihn in ihren Einstellungen (settings.reports) gewählt haben.
     *
     * @return int[]  admin_ids
     */
    public function reportSubscribers(array $task): array
    {
        $details = (array)($task['details'] ?? []);
        if (!in_array((string)($task['type'] ?? ''), self::REPORT_TYPES, true) || empty($details['report'])) return [];
        $groupIds = $this->system->sanitizeIds((array)($details['report_group_ids'] ?? []), true);
        if (empty($groupIds)) return [];
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `admin_id` FROM `admin` WHERE `deleted` = 0 AND `status_id` = 0"
                . " AND `group_id` IN (" . implode(',', $groupIds) . ")"
                // settings ist '' bei Benutzern, die ihre Einstellungen nie gespeichert
                // haben - JSON_EXTRACT('') wäre ein Fehler für die ganze Abfrage
                . " AND JSON_CONTAINS(COALESCE(JSON_EXTRACT(IF(JSON_VALID(`settings`), `settings`, '{}'), '$.reports'), JSON_ARRAY()), CAST(? AS JSON))",
                's', [(string)(int)$task['scheduler_id']]
            );
            return array_map('intval', $this->db->fetchColumn());
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * PHP-Scripte in var/custom/php (auch in Unterverzeichnissen), Pfade relativ
     * dazu - die Auswahl für den Typ 'php'. Nur Dateien, die scriptFile() annimmt.
     *
     * @return string[]
     */
    public function customScripts(): array
    {
        $dir = $this->scriptDir();
        if (!is_dir($dir)) return [];
        $scripts = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
            $script = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            if ($this->scriptFile($script) !== null) $scripts[] = $script;
        }
        sort($scripts);
        return $scripts;
    }

    /**
     * Absoluter Pfad eines Scripts (relativ zu var/custom/php) - nur, wenn der Pfad
     * sauber ist (cleanPath()), auf .php endet, die Datei existiert und ihr
     * aufgelöster Pfad innerhalb von var/custom/php liegt. Sonst null.
     */
    public function scriptFile(string $script): ?string
    {
        $script = self::cleanPath($script);
        if ($script === null || strtolower(pathinfo($script, PATHINFO_EXTENSION)) !== 'php') return null;
        $base = realpath($this->scriptDir());
        $file = $base === false ? false : realpath($base . '/' . $script);
        if ($file === false || !is_file($file) || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) return null;
        return $file;
    }

    /**
     * Ausgabepfad relativ zu var/custom/html bereinigen (cleanPath()); keine Datei
     * mit einer Endung, die ein Webserver als PHP ausführen könnte. null, wenn
     * unbrauchbar.
     */
    public function outputPath(string $path): ?string
    {
        $path = self::cleanPath($path);
        if ($path === null) return null;
        if (preg_match('!\.(php\d*|phtml|phar|pht|phps)$!i', $path)) return null;
        return $path;
    }

    /**
     * Relativen Pfad bereinigen: keine Aufstiege (. und ..), nur gewöhnliche
     * Zeichen, ein Dateiname am Ende. null, wenn unbrauchbar.
     */
    public static function cleanPath(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = (string)preg_replace('!/+!', '/', ltrim($path, '/'));
        if ($path === '' || str_ends_with($path, '/')) return null;
        if (preg_match('!(^|/)\.\.?(/|$)!', $path)) return null;
        if (!preg_match('!^[a-zA-Z0-9._ \-/]+$!', $path)) return null;
        return $path;
    }

    /**
     * Absoluter Pfad von var/custom/html - Ablage der Ausgaben.
     */
    public function outputDir(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/') . '/custom/' . self::OUTPUT_DIR;
    }

    /**
     * Absoluter Pfad von var/custom/php - die ausführbaren Scripte.
     */
    public function scriptDir(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/') . '/custom/' . self::SCRIPT_DIR;
    }
}
