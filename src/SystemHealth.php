<?php
/**
 * Operational checks for the start page of the backend: what is not running in the
 * background as it should? Instance name in the code: $systemHealth.
 *
 * The start page shows the result below the module tiles - only to superusers, because
 * it is fixed on the server or in the scheduler (StartBackend::_healthWarnings()).
 *
 * WHAT IS CHECKED
 * --------------------
 *     cron          is Core/cron/cron.php running? TriggerCron::tick1m() writes
 *                   var/tmp/cron-heartbeat.stamp every minute; older than CRON_MAX_AGE:
 *                   warning. Without cron neither the scheduler nor thumbnails run,
 *                   and var/tmp is not emptied.
 *     tmp           entries in var/tmp that are older than admin.tmp_retention_hours
 *                   plus tolerance - the scheduler task tmpcleanup does not catch them.
 *     maintenance   maintenance tasks (Scheduler::MAINTENANCE_TYPES) for which there is
 *                   no enabled task in the scheduler (updatecheck only with
 *                   paths.composer, auditverify only with the audit log enabled)
 *     backup        there is an enabled task of type dbbackup; its last run
 *                   has not failed; backup-1.sql is not older than
 *                   BACKUP_MAX_DAYS and usable (Backup::verify(): minimum size,
 *                   final line "-- Dump completed").
 *     scheduler     other tasks whose last run failed
 *     logcleanup    there is an enabled task of type logcleanup (note:
 *                   without it, logs and stored IP addresses grow without limit)
 *     cachecleanup  var/cache/include is not empty (templates use CMS:INCLUDE
 *                   caching=…), but no task of type cachecleanup is
 *                   enabled (note: expired files remain)
 *     disk          free space under var/ (DISK_WARN_BYTES / DISK_DANGER_BYTES)
 *     writable      var/tmp, var/log and the media directory are writable
 *     mail          email.noreply is missing, but two-factor sign-in needs it
 *     development   system.development_version is enabled
 *     https         admin.cookie_secure off: the session cookie also travels over http
 *     securitytxt   public/.well-known/security.txt is missing (reporting route for vulnerabilities)
 *     audit         result of the daily check of the audit log (HMAC chain, comparison
 *                   with the database, scheduler task auditverify): findings = danger
 *     updates       result of the daily update check (UpdateCheck): known
 *                   security vulnerabilities (danger), new version of camindo/cms
 *                   (warning); other packages only on the info page (link)
 *
 * Each check yields at most one message. A check that itself fails
 * (database gone) fails silently - the start page must not hang on it.
 *
 * @see Camindo\Core\Modules\Start\StartBackend::start()
 * @see Camindo\Core\cron\TriggerCron::tick1m()   Herzschlag des Cronjobs
 */
namespace Camindo\Core;

use Camindo\Core\Modules\Scheduler\Scheduler;

class SystemHealth {

    /** Heartbeat file in var/tmp (TriggerCron::tick1m()) */
    public const CRON_HEARTBEAT = 'cron-heartbeat.stamp';

    /** Heartbeat older than this many seconds: cron counts as failed */
    public const CRON_MAX_AGE = 900;

    /** Tolerance beyond admin.tmp_retention_hours (hours) */
    public const TMP_TOLERANCE_HOURS = 2;

    /** backup-1.sql older than this many days: warning */
    public const BACKUP_MAX_DAYS = 2;

    /** Free space under var/: warning or alarm */
    public const DISK_WARN_BYTES = 2 * 1024 ** 3;
    public const DISK_DANGER_BYTES = 512 * 1024 ** 2;

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private System $system,
        private Backup $backup,
        private UpdateCheck $updateCheck,
        private Crypt $crypt,
    )
    {}

    /**
     * All messages, most important first.
     *
     * @return array  list of ['check' => string, 'level' => 'danger'|'warning'|'info',
     *                'text' => string (plain text, not yet escaped),
     *                'action' => string (JavaScript for the link, optional)]
     */
    public function warnings(): array
    {
        $warnings = [];
        foreach ([
            'cron', 'tmp', 'maintenance', 'backup', 'scheduler', 'logcleanup', 'cachecleanup', 'disk',
            'writable', 'keyenv', 'mail', 'development', 'https', 'backenddomain', 'securitytxt', 'audit', 'updates',
        ] as $check) {
            try {
                $warning = $this->{'check' . ucfirst($check)}();
            }
            catch (\Throwable $e) {
                $this->system->log('widget-errors', "SystemHealth $check: " . $e->getMessage());
                $warning = null;
            }
            if ($warning !== null) $warnings[] = ['check' => $check] + $warning;
        }
        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($warnings, fn(array $a, array $b): int => $rank[$a['level']] <=> $rank[$b['level']]);
        return $warnings;
    }

    // ==== The checks ====

    private function checkCron(): ?array
    {
        $file = $this->tmpDir() . '/' . self::CRON_HEARTBEAT;
        if (!is_file($file)) {
            return $this->warning('danger', 'health_cron_never');
        }
        $age = time() - (int)filemtime($file);
        if ($age <= self::CRON_MAX_AGE) return null;
        return $this->warning('danger', 'health_cron_stopped', [
            'since' => $this->cms->_formatDate(date('Y-m-d H:i:s', (int)filemtime($file))),
        ]);
    }

    private function checkTmp(): ?array
    {
        $dir = $this->tmpDir();
        if (!is_dir($dir)) return null;
        $hours = max(1, (int)($this->config->get('admin.tmp_retention_hours') ?? 24)) + self::TMP_TOLERANCE_HOURS;
        $limit = time() - $hours * 3600;
        $old = 0;
        foreach (scandir($dir) ?: [] as $entry) {
            // same exceptions as SchedulerRunner::cleanupTmp()
            if ($entry === '.' || $entry === '..' || $entry === 'cron.log.txt') continue;
            if (str_ends_with($entry, '.lock') || str_ends_with($entry, '.stamp')) continue;
            if ((int)@filemtime("$dir/$entry") < $limit) $old++;
        }
        if ($old === 0) return null;
        return $this->warning('warning', 'health_tmp_old', ['count' => $old, 'hours' => $hours]);
    }

    private function checkBackup(): ?array
    {
        $this->db->query(
            "SELECT `scheduler_id`, `label`, `last_status`, `last_message` FROM `scheduler`"
            . " WHERE `type` = 'dbbackup' AND `enabled` = 1 ORDER BY `scheduler_id`"
        );
        $tasks = [];
        while ($row = $this->db->fetch()) $tasks[] = $row;
        $schedulerLink = "cApp.layers.add('Scheduler/scheduler_list.php');";
        if (!$tasks) {
            return $this->warning('danger', 'health_backup_missing', [], $schedulerLink);
        }
        foreach ($tasks as $task) {
            if ((int)$task['last_status'] === 2) {
                return $this->warning('danger', 'health_backup_failed', [
                    'message' => $this->shorten((string)$task['last_message']),
                ], "cApp.layers.add('Scheduler/scheduler_info.php?id=" . (int)$task['scheduler_id'] . "');");
            }
        }
        $file = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/dbbackup/backup-1.sql';
        if (!is_file($file)) {
            return $this->warning('warning', 'health_backup_none_yet', [], $schedulerLink);
        }
        if (($error = $this->backup->verify($file)) !== null) {
            return $this->warning('danger', 'health_backup_invalid', ['reason' => $this->shorten($error)], $schedulerLink);
        }
        $days = (time() - (int)filemtime($file)) / 86400;
        if ($days > self::BACKUP_MAX_DAYS) {
            return $this->warning('danger', 'health_backup_old', [
                'date' => $this->cms->_formatDate(date('Y-m-d H:i:s', (int)filemtime($file))),
            ], $schedulerLink);
        }
        return null;
    }

    private function checkScheduler(): ?array
    {
        $this->db->query(
            "SELECT `label` FROM `scheduler` WHERE `enabled` = 1 AND `last_status` = 2"
            . " AND `type` <> 'dbbackup' ORDER BY `label`"
        );
        $labels = [];
        while ($row = $this->db->fetch()) {
            $labels[] = $this->cms->textOrSnippet((string)$row['label']);
        }
        if (!$labels) return null;
        return $this->warning('warning', 'health_scheduler_failed', [
            'tasks' => implode(', ', $labels),
        ], "cApp.layers.add('Scheduler/scheduler_list.php');");
    }

    private function checkMaintenance(): ?array
    {
        $types = Scheduler::MAINTENANCE_TYPES;
        if (!$this->updateCheck->enabled()) $types = array_diff($types, ['updatecheck']);
        if ($this->config->get('audit.enabled') === false) $types = array_diff($types, ['auditverify']);
        $this->db->query("SELECT DISTINCT `type` FROM `scheduler` WHERE `enabled` = 1");
        $missing = array_diff($types, array_map('strval', $this->db->fetchColumn()));
        if (!$missing) return null;
        $labels = array_map(fn($type) => $this->cms->snippet('scheduler.label_type_' . $type), $missing);
        return $this->warning('warning', 'health_maintenance_missing', [
            'tasks' => implode(', ', $labels),
        ], "cApp.layers.add('Scheduler/scheduler_list.php');");
    }

    private function checkLogcleanup(): ?array
    {
        $this->db->query("SELECT COUNT(*) FROM `scheduler` WHERE `type` = 'logcleanup' AND `enabled` = 1");
        if ((int)($this->db->fetchNum()[0] ?? 0) > 0) return null;
        return $this->warning('info', 'health_logcleanup_missing', [], "cApp.layers.add('Scheduler/scheduler_list.php');");
    }

    private function checkCachecleanup(): ?array
    {
        // Only a directory listing up to the first entry - the start page does not count
        $dir = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/cache/' . IncludeCache::DIR;
        if (!is_dir($dir) || (new \FilesystemIterator($dir))->valid() === false) return null;
        $this->db->query("SELECT COUNT(*) FROM `scheduler` WHERE `type` = 'cachecleanup' AND `enabled` = 1");
        if ((int)($this->db->fetchNum()[0] ?? 0) > 0) return null;
        return $this->warning('info', 'health_cachecleanup_missing', [], "cApp.layers.add('Scheduler/scheduler_list.php');");
    }

    private function checkDisk(): ?array
    {
        $free = @disk_free_space((string)$this->config->get('paths.var_dir'));
        if ($free === false) return null;
        $level = match (true) {
            $free < self::DISK_DANGER_BYTES => 'danger',
            $free < self::DISK_WARN_BYTES   => 'warning',
            default                         => null,
        };
        if ($level === null) return null;
        return $this->warning($level, 'health_disk_low', ['free' => $this->system->convertSize((float)$free)]);
    }

    private function checkWritable(): ?array
    {
        $varDir = rtrim((string)$this->config->get('paths.var_dir'), '/');
        $dirs = [
            'var/tmp' => $this->tmpDir(),
            'var/log' => $varDir . '/log',
            // Audit log (AuditStore): only if the directory already exists or must exist
            'var/audit' => $this->config->get('audit.enabled') === false ? '' : $varDir . '/audit',
            'media'   => (string)$this->config->get('paths.media_dir'),
        ];
        $failed = [];
        foreach ($dirs as $label => $dir) {
            if ($dir === '') continue;
            if ($label === 'var/audit' && !is_dir($dir)) {
                // The first entry creates it - then var/ must be writable
                if (!is_writable($varDir)) $failed[] = $label;
                continue;
            }
            if (!is_dir($dir) || !is_writable($dir)) $failed[] = $label;
        }
        if (!$failed) return null;
        return $this->warning('danger', 'health_not_writable', ['dirs' => implode(', ', $failed)]);
    }

    /** CAMINDO_KEY does not contain the current key (not updated after `key rotate`) */
    private function checkKeyenv(): ?array
    {
        if (!in_array($this->crypt->envKeyState(), ['retired', 'other'], true)) return null;
        return $this->warning('warning', 'health_key_env');
    }

    private function checkMail(): ?array
    {
        if (!$this->config->get('admin.2_factor')) return null;
        if ((string)$this->config->get('email.noreply') !== '' || (string)$this->config->get('email.reply') !== '') return null;
        return $this->warning('danger', 'health_mail_missing');
    }

    private function checkDevelopment(): ?array
    {
        if (!$this->config->get('system.development_version')) return null;
        return $this->warning('warning', 'health_development');
    }

    private function checkHttps(): ?array
    {
        if ($this->config->get('admin.cookie_secure')) return null;
        return $this->warning('warning', 'health_cookie_insecure');
    }

    /** No fixed backend domain (admin.backend_domain) - note, not in development mode */
    private function checkBackenddomain(): ?array
    {
        if ((string)$this->config->get('admin.backend_domain') !== '') return null;
        if ($this->config->get('system.development_version')) return null;
        return $this->warning('info', 'health_backend_domain_missing');
    }

    private function checkSecuritytxt(): ?array
    {
        $file = rtrim((string)$this->config->get('paths.document_root'), '/') . '/.well-known/security.txt';
        if (is_file($file)) return null;
        return $this->warning('info', 'health_securitytxt_missing');
    }

    private function checkAudit(): ?array
    {
        $file = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/cache/' . AuditLog::VERIFY_FILE;
        if (!is_file($file)) return null;
        $result = json_decode((string)file_get_contents($file), true);
        $failed = array_keys((array)($result['failed'] ?? []));
        if (!$failed) return null;
        return $this->warning('danger', 'health_audit_failed', [
            'count' => count($failed),
            'objects' => implode(', ', array_slice($failed, 0, 5)) . (count($failed) > 5 ? ' …' : ''),
        ]);
    }

    private function checkUpdates(): ?array
    {
        $result = $this->updateCheck->result();
        if (!$result || empty($result['checked_at'])) return null;
        $action = "cApp.layers.addOnce('Start/info.php');";
        if (!empty($result['advisories'])) {
            $packages = array_unique(array_column($result['advisories'], 'package'));
            return $this->warning('danger', 'health_updates_advisories', ['packages' => implode(', ', $packages)], $action);
        }
        // Other packages only on the info page - libraries publish new versions
        // almost daily, the start page would never be quiet
        if (!empty($result['core']['latest'])) {
            return $this->warning('warning', 'health_updates_core', ['version' => $result['core']['latest']], $action);
        }
        return null;
    }

    // ====================

    /**
     * Build a message from the snippet start.{key}.
     */
    private function warning(string $level, string $key, array $vars = [], string $action = ''): array
    {
        // Simple placeholders {name} are not replaced by Cms::snippet() (only ICU constructs)
        $replace = [];
        foreach ($vars as $name => $value) $replace['{' . $name . '}'] = (string)$value;
        return [
            'level'  => $level,
            'text'   => strtr($this->cms->snippet('start.' . $key, $vars), $replace),
            'action' => $action,
        ];
    }

    private function tmpDir(): string
    {
        return rtrim((string)$this->config->get('paths.tmp_dir'), '/');
    }

    private function shorten(string $text, int $max = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max - 1) . '…';
    }
}
