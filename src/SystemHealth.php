<?php
/**
 * Betriebsprüfungen für die Startseite des Backends: Was läuft im Hintergrund
 * nicht, wie es soll? Instanzname im Code: $systemHealth.
 *
 * Die Startseite zeigt das Ergebnis unter den Modulkacheln - nur Superusern, denn
 * behoben wird es auf dem Server bzw. im Zeitplaner (StartBackend::_healthWarnings()).
 *
 * WAS GEPRÜFT WIRD
 * --------------------
 *     cron          läuft Core/cron/cron.php? TriggerCron::tick1m() schreibt jede
 *                   Minute var/tmp/cron-heartbeat.stamp; älter als CRON_MAX_AGE:
 *                   Warnung. Ohne Cron laufen weder Zeitplaner noch Thumbnails,
 *                   und var/tmp wird nicht geleert.
 *     tmp           Einträge in var/tmp, die älter sind als admin.tmp_retention_hours
 *                   plus Toleranz - die Aufgabe tmpcleanup des Zeitplaners greift nicht.
 *     maintenance   Wartungsaufgaben (Scheduler::MAINTENANCE_TYPES), für die es keine
 *                   eingeschaltete Aufgabe im Zeitplaner gibt (updatecheck nur mit
 *                   paths.composer, auditverify nur mit eingeschaltetem Audit-Log)
 *     backup        eine eingeschaltete Aufgabe vom Typ dbbackup gibt es; ihr letzter
 *                   Lauf ist nicht fehlgeschlagen; backup-1.sql ist nicht älter als
 *                   BACKUP_MAX_DAYS und brauchbar (Backup::verify(): Mindestgröße,
 *                   Endzeile "-- Dump completed").
 *     scheduler     andere Aufgaben, deren letzter Lauf fehlschlug
 *     logcleanup    eine eingeschaltete Aufgabe vom Typ logcleanup gibt es (Hinweis:
 *                   ohne sie wachsen Logs und gespeicherte IP-Adressen unbegrenzt)
 *     cachecleanup  var/cache/include ist nicht leer (Templates nutzen CMS:INCLUDE
 *                   caching=…), aber keine Aufgabe vom Typ cachecleanup ist
 *                   eingeschaltet (Hinweis: abgelaufene Dateien bleiben liegen)
 *     disk          freier Platz unter var/ (DISK_WARN_BYTES / DISK_DANGER_BYTES)
 *     writable      var/tmp, var/log und das Medienverzeichnis beschreibbar
 *     mail          email.noreply fehlt, aber die Zwei-Faktor-Anmeldung braucht es
 *     development   system.development_version eingeschaltet
 *     https         admin.cookie_secure aus: das Sitzungs-Cookie geht auch über http
 *     securitytxt   public/.well-known/security.txt fehlt (Meldeweg für Lücken)
 *     audit         Ergebnis der täglichen Prüfung des Audit-Logs (HMAC-Kette, Abgleich
 *                   mit der Datenbank, Zeitplaner-Aufgabe auditverify): Befunde = danger
 *     updates       Ergebnis der täglichen Update-Prüfung (UpdateCheck): bekannte
 *                   Sicherheitslücken (danger), neue Version von camindo/cms
 *                   (warning), andere Pakete mit Updates (info); Link zur Info-Seite
 *
 * Jede Prüfung liefert höchstens eine Meldung. Eine Prüfung, die selbst scheitert
 * (Datenbank weg), fällt still aus - die Startseite darf daran nicht hängen.
 *
 * @see Camindo\Core\Modules\Start\StartBackend::start()
 * @see Camindo\Core\cron\TriggerCron::tick1m()   Herzschlag des Cronjobs
 */
namespace Camindo\Core;

use Camindo\Core\Modules\Scheduler\Scheduler;

class SystemHealth {

    /** Herzschlag-Datei in var/tmp (TriggerCron::tick1m()) */
    public const CRON_HEARTBEAT = 'cron-heartbeat.stamp';

    /** Herzschlag älter als so viele Sekunden: Cron gilt als ausgefallen */
    public const CRON_MAX_AGE = 900;

    /** Toleranz über admin.tmp_retention_hours hinaus (Stunden) */
    public const TMP_TOLERANCE_HOURS = 2;

    /** backup-1.sql älter als so viele Tage: Warnung */
    public const BACKUP_MAX_DAYS = 2;

    /** Freier Platz unter var/: Warnung bzw. Alarm */
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
     * Alle Meldungen, die wichtigste zuerst.
     *
     * @return array  Liste aus ['check' => string, 'level' => 'danger'|'warning'|'info',
     *                'text' => string (Klartext, noch nicht maskiert),
     *                'action' => string (Javascript für den Link, optional)]
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

    // ==== Die Prüfungen ====

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
            // dieselben Ausnahmen wie SchedulerRunner::cleanupTmp()
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
        // Nur ein Verzeichnislisting bis zum ersten Eintrag - die Startseite zählt nicht
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
            // Audit-Log (AuditStore): nur wenn es das Verzeichnis schon gibt bzw. geben muss
            'var/audit' => $this->config->get('audit.enabled') === false ? '' : $varDir . '/audit',
            'media'   => (string)$this->config->get('paths.media_dir'),
        ];
        $failed = [];
        foreach ($dirs as $label => $dir) {
            if ($dir === '') continue;
            if ($label === 'var/audit' && !is_dir($dir)) {
                // Legt der erste Eintrag an - schreibbar muss dann var/ sein
                if (!is_writable($varDir)) $failed[] = $label;
                continue;
            }
            if (!is_dir($dir) || !is_writable($dir)) $failed[] = $label;
        }
        if (!$failed) return null;
        return $this->warning('danger', 'health_not_writable', ['dirs' => implode(', ', $failed)]);
    }

    /** CAMINDO_KEY enthält nicht den aktuellen Schlüssel (nach `key rotate` nicht nachgezogen) */
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

    /** Keine feste Backend-Domain (admin.backend_domain) - Hinweis, nicht im Entwicklungsmodus */
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
        if (!empty($result['core']['latest'])) {
            return $this->warning('warning', 'health_updates_core', ['version' => $result['core']['latest']], $action);
        }
        if (!empty($result['outdated'])) {
            return $this->warning('info', 'health_updates_packages', ['count' => count($result['outdated'])], $action);
        }
        return null;
    }

    // ====================

    /**
     * Eine Meldung aus dem Snippet start.{key} bauen.
     */
    private function warning(string $level, string $key, array $vars = [], string $action = ''): array
    {
        // Einfache Platzhalter {name} ersetzt Cms::snippet() nicht (nur ICU-Konstrukte)
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
