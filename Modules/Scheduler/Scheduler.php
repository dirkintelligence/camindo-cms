<?php
/**
 * Dataset child class for the table `scheduler` - time-controlled tasks.
 *
 * A task is one row: what is to be done (`type`), when (six columns in
 * cron syntax) and how long it may take at most (`max_minutes`). It is executed
 * not here but by SchedulerRunner (Core/cron/scheduler.php), which the
 * TriggerCron (Core/cron/cron.php, the only cron job) starts every minute.
 *
 * TASK TYPES
 * --------------------
 *     url        fetch an address (curl), optionally with HTTP auth; the response
 *                is stored under var/custom/html or discarded
 *     php        run a PHP script from var/custom/php on the command line;
 *                output as above
 *
 * Outputs (var/custom/html) and scripts (var/custom/php) are kept separate: what a
 * task fetches or creates can never be run as a script. Output files
 * with a PHP extension are additionally blocked, paths may not ascend, and both
 * sides check the resolved path (realpath) - a symlink does not lead out.
 *     dbbackup   mysqldump to var/dbbackup, backup-1.sql is always the newest;
 *                at most once a day, older ones beyond the count are removed
 *     logcleanup delete old logs: log files under var/log (security logs
 *                with their own retention) and the logged login attempts
 *                including IP address in the database
 *     cachecleanup clean up the output cache of CMS:INCLUDE (IncludeCache::cleanup()):
 *                expired files and those of deleted or changed
 *                templates, leftover lock and temporary files
 *     template   run a frontend template with the usage "scheduled"
 *                (template.type = 'scheduler') in a language of its instance
 *                (Page::renderScheduled()) - with the time window since the
 *                last successful run (details.window_end); output as above
 *     fulltext   rebuild the full text of the selected areas (details.area_ids, empty = all;
 *                details.only_stale: only those marked as stale)
 *                (RecordService::rebuildFulltext()) - after a change of the source
 *                fields of a full-text field
 *
 * Maintenance tasks (MAINTENANCE_TYPES, without settings) - previously hard-wired in the
 * cron job, now schedulable here:
 *     trash       empty the trash (ToolDatabase::purgeTrash(), admin.trash_retention_days)
 *     tmpcleanup  clean up var/tmp and expired export files
 *                 (admin.tmp_retention_hours, admin.export_retention_hours)
 *     privacy     run due orders of the deletion policies (PrivacyJobs::runDue())
 *     retention   retention periods: shorten IP addresses (IpAnonymizer), expired
 *                 audit entries (AuditLog::purge()), internal messages
 *                 (Message::purgeExpired()), never confirmed registrations
 *                 (RecordUser::purgeUnconfirmed())
 *     auditverify check the audit log (AuditLog::verifyAll(), result for SystemHealth)
 *     updatecheck search for updates and security vulnerabilities (UpdateCheck::run())
 *
 * Also without settings, but not maintenance (if one is missing, the start page does
 * not warn):
 *     messagedigest  summary of the internal messages by e-mail to the
 *                 users who switched it on (MessageDigest)
 *
 * DEFAULT TASKS
 * --------------------
 * DEFAULT_TASKS are created by the installer (createDefaults(), spread at night between 2 and 4 o'clock,
 * tmpcleanup hourly, messagedigest in the morning at 7); `camindo.php scheduler defaults` adds missing types to an
 * existing installation. If a type already exists, it stays untouched.
 *
 * The type-dependent settings are in the JSON `details` (dot notation in the
 * schema, as with Field and Converter). The password of the HTTP auth is
 * stored encrypted (hookOnSave()/hookOnLoaded()); an empty password field
 * when saving means "keep the previous one".
 *
 * UNDER WHICH ADMIN
 * --------------------
 * `admin_id`: every task runs under an admin (SchedulerRunner) - new ones under
 * the admin 'scheduler' (DEFAULT_ADMIN, created by the installer). It must be active
 * and its group must be allowed to use the command-line interface (adminError()).
 * If it is deleted, the task remains without an admin and no longer runs.
 *
 * SCHEDULING
 * --------------------
 * One cron expression per column: `*`, a value, a range `1-5`, a step `* / 10`
 * or `1-5/2`, and lists of these separated by commas (`1,15,30`). From the six expressions
 * nextRun() computes the next run - in the time zone system.timezone, in which
 * the cron job runs too - and stores it in `next_run`; the runner only compares
 * with the clock. An expression that never matches (31 February) leaves `next_run`
 * empty, and the task then does not run.
 *
 * @see Camindo\Core\Modules\Scheduler\SchedulerRunner   execution
 * @see Camindo\Core\Modules\Scheduler\SchedulerBackend  backend UI
 * @see Camindo\Core\cron\TriggerCron::tick1m()          triggers it every minute
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Scheduler;

use Camindo\Core\Modules\Instance\Instance;
use Camindo\Core\Dataset;
use Camindo\Core\Modules\Area\Area;

class Scheduler extends Dataset {

    public const TYPES = ['url', 'php', 'dbbackup', 'logcleanup', 'cachecleanup', 'template', 'fulltext',
        'trash', 'tmpcleanup', 'privacy', 'retention', 'auditverify', 'updatecheck', 'messagedigest'];

    /** Maintenance tasks without own settings (see class comment) */
    public const MAINTENANCE_TYPES = ['trash', 'tmpcleanup', 'privacy', 'retention', 'auditverify', 'updatecheck'];

    /**
     * Tasks of a new installation (createDefaults()): type => minute, hour and
     * details - daily, spread at night between 2 and 4 o'clock. The backup runs
     * first, i.e. before emptying the trash. Exception tmpcleanup: hourly -
     * export files (also data disclosures) should not remain up to a day beyond
     * admin.export_retention_hours - and messagedigest
     * in the morning, at the start of the working day.
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

    /** Types whose output can be subscribed to as a report (details.report) */
    public const REPORT_TYPES = ['url', 'php'];

    /** Name of the admin under which new tasks run (Installer::createSchedulerAdmin()) */
    public const DEFAULT_ADMIN = 'scheduler';

    /** Log files with their own, usually longer retention (details.security_days) */
    public const SECURITY_LOGS = ['security', 'csp'];

    // Result of the last run (`last_status`)
    public const STATUS_OK      = 0;
    public const STATUS_RUNNING = 1;
    public const STATUS_ERROR   = 2;

    /** Subdirectories of var/custom: outputs of the tasks resp. PHP scripts (type 'php') */
    public const OUTPUT_DIR = 'html';
    public const SCRIPT_DIR = 'php';

    // The six cron columns with their value range
    public const CRON_FIELDS = [
        'cron_minute'  => [0, 59],
        'cron_hour'    => [0, 23],
        'cron_day'     => [1, 31],
        'cron_month'   => [1, 12],
        'cron_year'    => [2026, 2036],
        'cron_weekday' => [0, 6],
    ];

    // How far nextRun() calculates ahead at most (days)
    private const NEXT_RUN_HORIZON = 366 * 11;

    // Audit log: the runtime state belongs to the runner, not to the mask
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
                'extension' => 'Snippets',   // Core extension: select snippet (#prefix.key)
                'flags' => ['trim', 'not_empty'],
            ],
            'type' => [
                'type'  => 'chars',
                'label' => '#scheduler.field_type',
                'max'   => 16,
                'flags' => ['not_empty'],
            ],
            // Admin under which the task runs (SchedulerRunner, ApiSession::runAs()):
            // its rights apply, in the audit log it appears with the origin
            // 'scheduler'; its group needs the command-line interface
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
            // url + php: selectable report (subscription in personal settings)
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
            // Runtime state - only SchedulerRunner writes it (directly via SQL)
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
     * Fields the input mask saves - without the runtime state (next_run,
     * last_*), which only the runner writes.
     *
     * @return string[]
     */
    /**
     * May the task run under this admin? null = yes, otherwise the snippet ID of the
     * message (saving, SchedulerRunner).
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
     * Admins under which tasks can run (choice of the mask):
     * admin_id => name - active and with the command-line interface.
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
     * Create default tasks (DEFAULT_TASKS) - only types for which there is no
     * task yet (not even a switched-off one). The label is the snippet of the type,
     * admin that of the scheduler (hookOnCreate()); with log.
     *
     * @return array  type => scheduler_id of the created tasks; errors in Cms::errors
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
     * Audit log: the HTTP auth password is stored encrypted (a new ciphertext on each
     * saving) - the plaintext is compared (see Dataset::auditSecret()).
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
     * After loading: decrypt the password (for the runner - the mask never shows it).
     */
    protected function hookOnLoaded(array &$data): void
    {
        $password = (string)($data['details']['password'] ?? '');
        if ($password !== '') {
            $data['details']['password'] = $this->crypt->decrypt($password);
        }
    }

    /**
     * Before saving: check and record the type, check cron expressions,
     * type-dependent required details, encrypt the password.
     *
     * The type is fixed after creation - the details only fit it.
     */
    /**
     * New task: runs under the admin of the scheduler (DEFAULT_ADMIN), if
     * it exists.
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

        // Type
        if (!$isNew) {
            $type = (string)($previousData['type'] ?? $type);
            $data['type'] = $type;
        }
        if (!in_array($type, self::TYPES, true)) {
            $this->createFieldError('scheduler.error_unknown_type', 'type', $schema['type']['label'], $type);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // Admin: present, active, group with the command-line interface
        // (a field of type 'id' does not run through the not_empty check)
        if (in_array('admin_id', $fields, true) && ($error = $this->adminError((int)($data['admin_id'] ?? 0))) !== null) {
            $this->createFieldError($error, 'admin_id', $schema['admin_id']['label']);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // Cron expressions
        foreach (self::CRON_FIELDS as $name => [$min, $max]) {
            if (!in_array($name, $fields, true)) continue;
            $expr = str_replace(' ', '', (string)($data[$name] ?? ''));
            $data[$name] = $expr;
            if (!$this->cronValid($expr, $min, $max)) {
                $this->createFieldError('scheduler.error_cron_syntax', $name, $schema[$name]['label'], "$min - $max");
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }

        // Details per type
        if (in_array('details', $fields, true)) {
            $details = (array)($data['details'] ?? []);
            $previous = (array)($previousData['details'] ?? []);
            $output = (string)($details['output'] ?? 'save');
            $details['output'] = ($output === 'discard') ? 'discard' : 'save';
            // Keep details of other types neutral - the subfields are all
            // checked, including those that do not belong to this type
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
            // Selectable report (url, php): at least one user group
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
            // Areas (fulltext): only known ones, empty = all
            $details['area_ids'] = ($type === 'fulltext')
                ? array_values(array_intersect($this->system->sanitizeIds((array)($details['area_ids'] ?? []), true), array_keys($this->fulltextAreas())))
                : [];
            // only areas whose full text is marked as stale (FulltextState)
            $details['only_stale'] = ($type === 'fulltext' && !empty($details['only_stale'])) ? 1 : 0;
            // End of the last time window: only the runner writes it, the mask
            // does not know it - keep it when saving
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
                // Password: empty = keep previous (which is stored decrypted in
                // previousData, see hookOnLoaded()); saved encrypted
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
                // Language of the template's instance; 0 = its main language
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
     * After saving: calculate the next run and enter it.
     *
     * Here and not in hookOnSave(), because `next_run` does not belong to the fields of the
     * mask (editableFields()); a direct UPDATE, still inside the transaction.
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

    // Cron syntax
    // ====================

    /**
     * Is a cron expression valid? List (comma) of `*`, `n`, `a-b`, `* /s`, `a-b/s`,
     * all numbers within the allowed range.
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
     * Does a cron expression match a value?
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
     * Next run of a task after a point in time - as Unix time, or null,
     * if the expressions never match within the horizon (about eleven years).
     *
     * Calculated in the time zone of the system (system.timezone), in which the
     * cron job runs: first the day (year, month, day, weekday), within it the hour,
     * within it the minute - so the search stays short even for rare expressions.
     *
     * @param array    $data  row from `scheduler` (the six cron_* columns)
     * @param int|null $from  point in time after which to search (null = now)
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
     * Time zone of the system (system.timezone) - that of the cron job.
     */
    public function systemTimezone(): \DateTimeZone
    {
        return new \DateTimeZone((string)($this->config->get('system.timezone') ?: date_default_timezone_get()));
    }

    /**
     * Unix time as "Y-m-d H:i:s" in the time zone of the system - the format of the
     * columns next_run / last_run (independent of the admin's time zone).
     */
    public function systemTime(?int $timestamp = null): string
    {
        return (new \DateTimeImmutable('@' . ($timestamp ?? time())))
            ->setTimezone($this->systemTimezone())->format('Y-m-d H:i:s');
    }

    // Files under var/custom
    // ====================

    /**
     * Selectable templates for the task type template: usage "scheduled"
     * (template.type = 'scheduler'), from instances to which the admin has access
     * (on the command line without admin: all).
     *
     * @return array  template_id => ['label' => "Instance · area.name",
     *                'instance_id' => int, 'languages' => [language_id => name]]
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
     * Areas for the type 'fulltext': the content areas (Area::CONTENT_TYPES),
     * in tree order - for the detail view and the check when saving.
     *
     * @return array  area_id => "Instance · label (name)"
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
     * Selection of areas for the type 'fulltext' (multi-select of the mask) -
     * hierarchical like the area tree in the module Record
     * (RecordBackendFolderList::folderList()): folder only if a content area lies below it
     * (Area::loadAreas()), indented by `depth`; if
     * tenants are set up, one main folder above per instance.
     *
     * Tenants and folders cannot be selected: their keys carry the prefix
     * 'disabled' (Backend::formField() renders them as disabled).
     *
     * @return array  area_id|'disabled_'.area_id|'disabled_i'.instance_id => label
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
     * Rows of Area::loadAreas() as indented options; folders locked.
     *
     * @param int $depthOffset  1 under a tenant main folder
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
     * User groups for details.report_group_ids.
     *
     * @return array  group_id => label
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
     * Reports that admins of a group can subscribe to (personal
     * settings): tasks of type url/php with details.report and the group in
     * details.report_group_ids - also suspended ones.
     *
     * @return array  scheduler_id => label
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
     * Subscribers of a report: active admins whose group is (still) allowed
     * and who selected it in their settings (settings.reports).
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
                // settings is '' for users who never saved their settings
                // - JSON_EXTRACT('') would be an error for the whole query
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
     * PHP scripts in var/custom/php (also in subdirectories), paths relative
     * to it - the choice for the type 'php'. Only files that scriptFile() accepts.
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
     * Absolute path of a script (relative to var/custom/php) - only if the path
     * is clean (cleanPath()), ends in .php, the file exists and its
     * resolved path lies within var/custom/php. Otherwise null.
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
     * Clean up an output path relative to var/custom/html (cleanPath()); no file
     * with an extension a web server could execute as PHP. null if
     * unusable.
     */
    public function outputPath(string $path): ?string
    {
        $path = self::cleanPath($path);
        if ($path === null) return null;
        if (preg_match('!\.(php\d*|phtml|phar|pht|phps)$!i', $path)) return null;
        return $path;
    }

    /**
     * Clean up a relative path: no ascents (. and ..), only ordinary
     * characters, a file name at the end. null if unusable.
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
     * Absolute path of var/custom/html - storage of the outputs.
     */
    public function outputDir(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/') . '/custom/' . self::OUTPUT_DIR;
    }

    /**
     * Absolute path of var/custom/php - the executable scripts.
     */
    public function scriptDir(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/') . '/custom/' . self::SCRIPT_DIR;
    }
}
