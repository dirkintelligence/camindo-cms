<?php
/**
 * Ausführung der zeitgesteuerten Aufgaben (Modul Scheduler).
 *
 * Zwei Einstiege, beide über Core/cron/scheduler.php auf der Kommandozeile:
 *
 *     dispatch()    ohne Argument - jede Minute von TriggerCron::tick1m() gestartet.
 *                   Sucht die fälligen Aufgaben (`enabled`, `next_run` erreicht) und
 *                   startet je Aufgabe einen eigenen Hintergrundprozess
 *                   (scheduler.php --id=N), damit eine lange Aufgabe die anderen
 *                   nicht aufhält. Vorher wird `next_run` schon weitergesetzt und
 *                   `last_status` auf "läuft" - so wird nichts doppelt gestartet.
 *     run($id)      --id=N - führt EINE Aufgabe aus und hält das Ergebnis fest
 *                   (`last_run`, `last_status`, `last_message`), bei gesetztem
 *                   `log` auch im System-Logfile var/log/{datum}_scheduler.log.
 *                   Mit --token=... wird zusätzlich der bgproc-Eintrag abgeschlossen
 *                   (Button "Jetzt ausführen" im Backend).
 *
 * UNTER WELCHEM ADMIN
 * --------------------
 * Jede Aufgabe läuft unter ihrem Admin (`scheduler`.`admin_id`, Vorgabe der Admin
 * 'scheduler'): run() öffnet für ihn eine eigene Session (ApiSession::runAs(),
 * Oberfläche Kommandozeile, Kanal 'scheduler') und schließt sie danach. Es gelten
 * seine Rechte; im Audit-Log erscheint er mit der Herkunft 'scheduler', im Logfile
 * des Zeitplaners seine admin_id. Ein php-Script bekommt die Session als
 * CAMINDO_API_SESSION (runPhp()), ein Template arbeitet im selben Prozess darin.
 * Ist der Admin gelöscht, gesperrt oder darf seine Gruppe die Kommandozeile nicht
 * benutzen, scheitert der Lauf mit einer Meldung.
 *
 * BERICHTE
 * --------------------
 * Eine url- oder php-Aufgabe mit details.report ist ein wählbarer Bericht: Admins
 * der Gruppen in details.report_group_ids abonnieren ihn in ihren persönlichen
 * Einstellungen (settings.reports). Nach jedem ERFOLGREICHEN Lauf geht die Ausgabe
 * als interne Nachricht im HTML-Format an die Abonnenten (sendReport()).
 *
 * ZEITÜBERSCHREITUNG
 * --------------------
 * `max_minutes` ist die Obergrenze je Lauf: Kommandos laufen über Execute::execute()
 * mit Timeout, der URL-Abruf über CURLOPT_TIMEOUT. Eine Aufgabe, die trotzdem länger
 * als max_minutes + 5 als "läuft" steht, gilt für dispatch() als abgebrochen und
 * wird beim nächsten Termin wieder gestartet.
 *
 * DIE AUFGABEN
 * --------------------
 *     url        curl-Abruf der Adresse, wahlweise mit HTTP-Auth (Basic); jeder
 *                HTTP-Status ab 400 ist ein Fehler. Die Antwort wird unter
 *                var/custom/html/{output_file} abgelegt oder verworfen.
 *     php        {paths.php} var/custom/php/{script} - Exit-Code ungleich 0 ist ein
 *                Fehler, die Ausgabe (stdout) wird abgelegt oder verworfen.
 *     dbbackup   mysqldump nach var/dbbackup/backup-1.sql. Vorher rücken die
 *                vorhandenen Sicherungen um eins (backup-1 -> backup-2 ...), was
 *                über `details.backups` hinausgeht, wird gelöscht. Höchstens EIN
 *                Lauf am Tag: liegt backup-1.sql von heute vor, passiert nichts.
 *                Das Kommando selbst steht in Camindo\Core\Backup (Passwort aus
 *                paths.sqlpwd_file, nie auf der Kommandozeile).
 *     logcleanup Logdateien var/log/{Y-m-d}_{name}.log löschen, deren Tag älter ist
 *                als `details.log_days` (Sicherheitsprotokolle Scheduler::SECURITY_LOGS:
 *                `details.security_days`), dazu die Zeilen in admin_login_attempt /
 *                user_login_attempt älter als `details.attempt_days` (Name + IP).
 *                Maßgeblich ist das Datum im Dateinamen, nicht filemtime().
 *     cachecleanup IncludeCache::cleanup() - abgelaufene Dateien des Ausgabe-Caches
 *                von CMS:INCLUDE unter var/cache/include löschen, dazu Dateien zu
 *                gelöschten oder geänderten Templates und liegen gebliebene Sperr-
 *                und temporäre Dateien. Keine Einstellungen.
 *     template   Page::renderScheduled() - ein Frontend-Template (Verwendung
 *                "zeitgesteuert") in der Umgebung seiner Instanz und der gewählten
 *                Sprache. Zeitfenster: vom Ende des letzten ERFOLGREICHEN Laufs
 *                (details.window_end, UTC) bis jetzt; beim ersten Lauf ist es leer
 *                (Beginn = Ende). Nach einem Fehler bleibt window_end stehen, der
 *                nächste Lauf holt das ganze Fenster nach. Meldungen des Templates
 *                (Cms-Fehler, z. B. von CMS:EMAIL) machen den Lauf zum Fehler.
 *                Die Ausgabe wird wie bei url/php abgelegt oder verworfen.
 *     fulltext   RecordService::rebuildFulltext() für details.area_ids (leer = alle
 *                Datensatzbereiche), mit details.only_stale nur die als veraltet
 *                markierten (FulltextState). Bereiche, die gerade ein anderer Prozess
 *                neu aufbaut, werden übersprungen. Reicht max_minutes nicht, bricht der Lauf
 *                ab und gilt als fehlgeschlagen - der nächste beginnt von vorn.
 *     trash      ToolDatabase::purgeTrash() mit admin.trash_retention_days; Sperre
 *                'trash' gegen parallele Läufe, Herkunft im Audit-Log 'trash'.
 *                Fehler einzelner Datensätze halten den Lauf nicht an, machen ihn
 *                aber zum Fehler (Meldung mit den ersten Fehlern).
 *     tmpcleanup var/tmp (älter als admin.tmp_retention_hours; *.lock, *.stamp und
 *                cron.log.txt bleiben) und var/export (RecordExport::cleanup()).
 *     privacy    PrivacyJobs::runDue() in Runden zu RUN_LIMIT Aufträgen, bis keine
 *                fälligen mehr da sind, max_minutes abläuft oder ein Auftrag scheitert
 *                (er bleibt fällig, der nächste Lauf versucht ihn erneut).
 *     retention  IpAnonymizer, AuditLog::purge(), Message::purgeExpired(),
 *                RecordUser::purgeUnconfirmed() - jeder Schritt für sich: ein Fehler
 *                hält die übrigen nicht auf, macht den Lauf aber zum Fehler.
 *     auditverify AuditLog::verifyAll() + storeVerifyResult(); Befunde = Fehler.
 *     updatecheck UpdateCheck::run(); ohne paths.composer: nichts zu tun.
 *     messagedigest MessageDigest::run() - "neu" sind Nachrichten seit dem vorigen
 *                Lauf ($task['last_run'] vor dem Start, ohne vorigen Lauf: 24 Stunden);
 *                ein gescheiterter Versand macht den Lauf zum Fehler.
 *
 * @see Camindo\Core\Modules\Scheduler\Scheduler          Aufgaben, Cron-Rechnung
 * @see Camindo\Core\Modules\Scheduler\SchedulerBackend   "Jetzt ausführen"
 * @see Camindo\Core\cron\TriggerCron
 */
namespace Camindo\Core\Modules\Scheduler;

use Camindo\Core\Api\ApiSession;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\Execute;
use Camindo\Core\System;
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Backup;
use Camindo\Core\Cms;
use Camindo\Core\IncludeCache;
use Camindo\Core\Page;
use Camindo\Core\ContainerInterface;
use Camindo\Core\AuditLog;
use Camindo\Core\IpAnonymizer;
use Camindo\Core\UpdateCheck;
use Camindo\Core\Modules\Messaging\Message;
use Camindo\Core\Modules\Messaging\MessageDigest;
use Camindo\Core\Modules\Privacy\PrivacyJobs;
use Camindo\Core\Modules\Record\RecordExport;
use Camindo\Core\Modules\Record\RecordService;
use Camindo\Core\Modules\Record\RecordUser;
use Camindo\Core\Modules\Tools\ToolDatabase;

class SchedulerRunner {

    // Nachlauf, ab dem ein "laufender" Eintrag als abgebrochen gilt (Minuten)
    private const STALE_MINUTES = 5;

    // Größter Bericht, der als Nachricht verschickt wird (Bytes); darüber geht nur
    // ein Hinweis hinaus
    private const REPORT_MAX_BYTES = 2 * 1024 * 1024;

    /** Ausgabe des laufenden url-/php-Laufs - Inhalt eines Berichts (sendReport()) */
    private ?string $output = null;

    public function __construct(
        private Config $config,
        private Database $db,
        private System $system,
        private Execute $execute,
        private BackgroundProcess $bgproc,
        private Scheduler $scheduler,
        private Backup $backup,
        private ContainerInterface $container,
    )
    {}

    /**
     * Absoluter Pfad des CLI-Scripts.
     */
    public function scriptPath(): string
    {
        return $this->config->get('paths.core_dir') . '/cron/scheduler.php';
    }

    /**
     * Eine Aufgabe sofort als Hintergrundprozess des angemeldeten Admins starten
     * ("Jetzt ausführen" in der Oberfläche, API scheduler.run).
     *
     * @param string $error  Grund, wenn der Start scheitert
     * @return ?string       Token des Hintergrundprozesses; null = nicht gestartet
     */
    public function startNow(int $id, string &$error = ''): ?string
    {
        $token = $this->container->get(\Camindo\Core\Crypt::class)->randomKey(16);
        $command = [(string)$this->config->get('paths.php'), $this->scriptPath(), '--id=' . $id, '--token=' . $token];
        return $this->bgproc->start($command, 'scheduler', $token, null, null, $error) ? $token : null;
    }

    /**
     * Fällige Aufgaben starten - je eine als eigener Hintergrundprozess.
     *
     * @return int  Anzahl gestarteter Aufgaben
     */
    public function dispatch(): int
    {
        $now = time();
        $nowSystem = $this->scheduler->systemTime($now);
        $this->db->query(
            "SELECT * FROM `scheduler` WHERE `enabled` = 1 AND `next_run` IS NOT NULL AND `next_run` <= ?"
            . " ORDER BY `next_run` ASC",
            's', [$nowSystem]
        );
        $tasks = $this->db->fetchAll();
        $started = 0;
        foreach ($tasks as $task) {
            $id = (int)$task['scheduler_id'];
            if ($this->isRunning($task, $now)) continue;

            // Nächsten Termin vormerken und als laufend kennzeichnen - BEVOR der
            // Prozess startet, sonst fände der nächste Durchlauf dieselbe Aufgabe
            $nextRun = $this->scheduler->nextRun($task, $now);
            $this->db->query(
                "UPDATE `scheduler` SET `next_run` = ?, `last_run` = ?, `last_status` = ?, `last_message` = ''"
                . " WHERE `scheduler_id` = ?",
                'ssii', [$nextRun !== null ? $this->scheduler->systemTime($nextRun) : null, $nowSystem, Scheduler::STATUS_RUNNING, $id]
            );

            $error = '';
            $command = [(string)$this->config->get('paths.php'), $this->scriptPath(), '--id=' . $id];
            if ($this->execute->executeBackground($command, null, null, $error) > 0) {
                $started++;
            }
            else {
                $this->finish($task, false, 'start failed: ' . $error);
            }
        }
        return $started;
    }

    /**
     * Läuft die Aufgabe gerade (und nicht schon verdächtig lange)?
     */
    private function isRunning(array $task, int $now): bool
    {
        if ((int)$task['last_status'] !== Scheduler::STATUS_RUNNING) return false;
        $lastRun = (string)($task['last_run'] ?? '');
        if ($lastRun === '') return false;
        $startedAt = (new \DateTimeImmutable($lastRun, $this->scheduler->systemTimezone()))->getTimestamp();
        $limit = ((int)$task['max_minutes'] + self::STALE_MINUTES) * 60;
        return ($now - $startedAt) < $limit;
    }

    /**
     * EINE Aufgabe ausführen und das Ergebnis festhalten.
     *
     * @param int         $id     scheduler_id
     * @param string|null $token  bgproc-Token, wenn aus dem Backend gestartet
     * @return bool  true, wenn die Aufgabe ohne Fehler durchlief
     */
    public function run(int $id, ?string $token = null): bool
    {
        if (!$task = $this->scheduler->load($id)) {
            if ($token !== null) $this->bgproc->setStatusByToken($token, BackgroundProcess::STATUS_FAILED);
            return false;
        }
        $this->db->query(
            "UPDATE `scheduler` SET `last_run` = ?, `last_status` = ?, `last_message` = '' WHERE `scheduler_id` = ?",
            'sii', [$this->scheduler->systemTime(), Scheduler::STATUS_RUNNING, $id]
        );
        $timeout = max(60, (int)$task['max_minutes'] * 60);
        set_time_limit($timeout + 60);

        // Unter dem Admin der Aufgabe: eigene Session (Oberfläche Kommandozeile,
        // Kanal 'scheduler') - seine Rechte gelten, im Audit-Log erscheint er
        $apiSession = $this->container->get(ApiSession::class);
        $sessionId = '';
        try {
            if (($adminError = $this->scheduler->adminError((int)($task['admin_id'] ?? 0))) !== null) {
                throw new \Exception($this->container->get(Cms::class)->snippet($adminError));
            }
            $sessionId = (string)$apiSession->runAs((int)$task['admin_id'], 'scheduler', $timeout + 300)['session_id'];
            $message = match ((string)$task['type']) {
                'url'      => $this->runUrl($task, $timeout),
                'php'      => $this->runPhp($task, $timeout, $sessionId),
                'dbbackup' => $this->runDbBackup($task, $timeout),
                'logcleanup' => $this->runLogCleanup($task),
                'cachecleanup' => $this->runCacheCleanup(),
                'template' => $this->runTemplate($task),
                'fulltext' => $this->runFulltext($task, $timeout),
                'trash'       => $this->runTrash(),
                'tmpcleanup'  => $this->runTmpCleanup(),
                'privacy'     => $this->runPrivacy($timeout),
                'retention'   => $this->runRetention(),
                'auditverify' => $this->runAuditVerify(),
                'updatecheck' => $this->runUpdateCheck(),
                'messagedigest' => $this->runMessageDigest($task),
                default    => throw new \Exception('unknown type "' . $task['type'] . '"'),
            };
            $ok = true;
            // Wählbarer Bericht: Ergebnis an die Abonnenten - noch in der Session der
            // Aufgabe, ihr Admin ist der Absender. Ein Fehler beim Versand macht den
            // Lauf nicht zum Fehler, er steht in der Meldung.
            if ($this->output !== null) {
                try {
                    $message .= $this->sendReport($task, $this->output);
                }
                catch (\Throwable $e) {
                    $message .= ', report not sent: ' . $e->getMessage();
                }
            }
        }
        catch (\Throwable $e) {
            $message = $e->getMessage();
            $ok = false;
        }
        finally {
            if ($sessionId !== '') $apiSession->logout();
        }
        $this->finish($task, $ok, $message);
        if ($token !== null) {
            $this->bgproc->setStatusByToken($token, $ok ? BackgroundProcess::STATUS_FINISHED : BackgroundProcess::STATUS_FAILED);
        }
        return $ok;
    }

    /**
     * Ergebnis eintragen und protokollieren.
     */
    private function finish(array $task, bool $ok, string $message): void
    {
        $message = mb_substr(trim($message), 0, 2000);
        $this->db->query(
            "UPDATE `scheduler` SET `last_status` = ?, `last_message` = ? WHERE `scheduler_id` = ?",
            'isi', [$ok ? Scheduler::STATUS_OK : Scheduler::STATUS_ERROR, $message, (int)$task['scheduler_id']]
        );
        if (!empty($task['log']) || !$ok) {
            $this->system->log('scheduler', sprintf(
                '#%d %s [%s, admin %d]: %s%s',
                (int)$task['scheduler_id'], (string)$task['label'], (string)$task['type'], (int)($task['admin_id'] ?? 0),
                $ok ? 'ok' : 'ERROR', $message !== '' ? ' - ' . str_replace(["\r", "\n"], ' ', $message) : ''
            ));
        }
    }

    // Die Aufgaben
    // ====================

    /**
     * URL-Aufruf mit curl.
     */
    private function runUrl(array $task, int $timeout): string
    {
        $details = (array)($task['details'] ?? []);
        $url = (string)($details['url'] ?? '');
        if ($url === '') throw new \Exception('no url');
        if (!function_exists('curl_init')) throw new \Exception('curl extension missing');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT      => 'camindo scheduler',
        ]);
        if (!empty($details['auth'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, (string)($details['user'] ?? '') . ':' . (string)($details['password'] ?? ''));
        }
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) throw new \Exception('curl: ' . $curlError);
        if ($httpCode >= 400) throw new \Exception("HTTP $httpCode");

        $this->output = (string)$body;
        $saved = $this->saveOutput($details, (string)$body);
        return "HTTP $httpCode, " . strlen((string)$body) . ' bytes' . $saved;
    }

    /**
     * PHP-Script aus var/custom/php auf der Kommandozeile. Das Script bekommt die Session
     * der Aufgabe als Umgebungsvariable CAMINDO_API_SESSION und arbeitet damit über
     * die API unter ihrem Admin:
     *
     *     require '/pfad/zum/projekt/load_camindo.php';
     *     $app->get(\Camindo\Core\Api\ApiSession::class)->resume(getenv('CAMINDO_API_SESSION'), 'scheduler');
     *     $result = $app->get(\Camindo\Core\Api\Api::class)->call('record.search', [...]);
     */
    private function runPhp(array $task, int $timeout, string $sessionId = ''): string
    {
        $details = (array)($task['details'] ?? []);
        $script = (string)($details['script'] ?? '');
        // aufgelöster Pfad innerhalb von var/custom/php (Scheduler::scriptFile())
        $path = $this->scheduler->scriptFile($script);
        if ($path === null) {
            throw new \Exception("script not found: $script");
        }
        $stdout = '';
        $stderr = '';
        $exitCode = $this->execute->execute([(string)$this->config->get('paths.php'), $path], $stdout, $stderr, $timeout,
            $sessionId !== '' ? ['CAMINDO_API_SESSION' => $sessionId] : []);
        if ($exitCode === Execute::EXEC_TIMEOUT) throw new \Exception("timeout after $timeout s");
        if ($exitCode !== 0) throw new \Exception("exit code $exitCode: " . trim($stderr));

        $this->output = $stdout;
        $saved = $this->saveOutput($details, $stdout);
        return strlen($stdout) . ' bytes' . $saved;
    }

    /**
     * Zeitgesteuertes Template (Typ template) - siehe Klassenkommentar.
     */
    private function runTemplate(array $task): string
    {
        $details = (array)($task['details'] ?? []);
        $templateId = (int)($details['template_id'] ?? 0);
        if ($templateId < 1) throw new \Exception('no template');

        $thisRun = time();
        $windowEnd = (string)($details['window_end'] ?? '');
        $lastRun = $windowEnd !== '' ? (int)strtotime($windowEnd . ' UTC') : $thisRun;
        if ($lastRun <= 0 || $lastRun > $thisRun) $lastRun = $thisRun;

        // Eigener Prozess je Aufgabe - die Fehlersammlung gehört diesem Lauf
        $cms = $this->container->get(Cms::class);
        $cms->errorsReset();
        $output = $this->container->get(Page::class)->renderScheduled(
            $templateId, (int)($details['language_id'] ?? 0), $lastRun, $thisRun, (int)$task['scheduler_id']
        );
        if ($cms->hasErrors) throw new \Exception('template: ' . $cms->errorMsg(' | '));

        // Fenster erst nach Erfolg weiterschieben (direkt, ohne Maske und Audit)
        $this->db->query(
            "UPDATE `scheduler` SET `details` = JSON_SET(COALESCE(`details`, '{}'), '$.window_end', ?) WHERE `scheduler_id` = ?",
            'si', [gmdate('Y-m-d H:i:s', $thisRun), (int)$task['scheduler_id']]
        );
        $saved = $this->saveOutput($details, $output);
        return sprintf('window %s - %s UTC, %d bytes%s', gmdate('Y-m-d H:i:s', $lastRun), gmdate('Y-m-d H:i:s', $thisRun), strlen($output), $saved);
    }

    /**
     * Wählbarer Bericht (details.report, Typ url/php): die Ausgabe als interne
     * Nachricht (Message::sendSystem(), nur im Posteingang) an jeden Abonnenten
     * (Scheduler::reportSubscribers()). Die Ausgabe geht als HTML in die Nachricht:
     * bei einem ganzen Dokument nur der Inhalt von <body>, aktiver Inhalt entfernt
     * (HtmlSanitizer), Text ohne Tags vorformatiert. Größer als REPORT_MAX_BYTES:
     * nur ein Hinweis.
     *
     * @return string  Zusatz für die Meldung des Laufs ('' ohne Abonnenten)
     */
    private function sendReport(array $task, string $output): string
    {
        $subscribers = $this->scheduler->reportSubscribers($task);
        if (empty($subscribers)) return '';

        $cms = $this->container->get(Cms::class);
        $label = $cms->_strip_tags($cms->textOrSnippet((string)$task['label']));
        $date = $cms->_formatDate($this->scheduler->systemTime());
        $subject = mb_substr(strtr($cms->snippet('scheduler.report_subject'), ['{label}' => $label, '{date}' => $date]), 0, 255);
        $body = strtr($cms->snippet('scheduler.report_body'), ['{label}' => $label, '{date}' => $date]);

        if (strlen($output) > self::REPORT_MAX_BYTES) {
            $body .= "\n\n" . strtr($cms->snippet('scheduler.report_too_large'), [
                '{size}' => $this->system->convertSize((float)strlen($output)),
            ]);
            $html = '';
        }
        else {
            $html = $this->reportHtml($output);
        }

        $message = $this->container->get(\Camindo\Core\Modules\Messaging\Message::class);
        $sent = 0;
        foreach ($subscribers as $adminId) {
            if ($message->sendSystem($adminId, $subject, $body, $html) !== null) $sent++;
        }
        return ", report sent to $sent of " . count($subscribers);
    }

    /**
     * Ausgabe als HTML für die Nachricht (siehe sendReport()).
     */
    private function reportHtml(string $output): string
    {
        if (trim($output) === '') return '';
        if (!preg_match('/<[a-z!\/][^>]*>/i', $output)) {
            return '<pre>' . htmlspecialchars($output) . '</pre>';
        }
        // Ganzes Dokument: nur der Körper; Kopf und Stile gehören nicht ins Backend
        if (preg_match('~<body\b[^>]*>(.*?)(?:</body>|$)~is', $output, $m)) $output = $m[1];
        $output = (string)preg_replace('~<(head|style|title)\b[^>]*>.*?</\1\s*>~is', '', $output);
        $output = (string)preg_replace('~<!doctype[^>]*>|</?(html|body)\b[^>]*>~i', '', $output);
        return $this->container->get(\Camindo\Core\HtmlSanitizer::class)->clean(trim($output));
    }

    /**
     * Ausgabe einer Aufgabe unter var/custom/html ablegen - oder verwerfen. Der
     * Zielordner muss aufgelöst (realpath, nach dem Anlegen) innerhalb von
     * var/custom/html liegen, die Datei selbst darf kein Symlink sein.
     *
     * @return string  Zusatz für die Meldung
     */
    private function saveOutput(array $details, string $content): string
    {
        if (($details['output'] ?? 'save') !== 'save') return ', discarded';
        $file = $this->scheduler->outputPath((string)($details['output_file'] ?? ''));
        if ($file === null) throw new \Exception('invalid output file');
        $dir = $this->scheduler->outputDir();
        $subDir = dirname($file);
        if ((!is_dir($dir) && !$this->system->createDir(dirname($dir, 2), 'custom/' . Scheduler::OUTPUT_DIR))
            || ($subDir !== '.' && !$this->system->createDir($dir, $subDir))) {
            throw new \Exception("could not create var/custom/html/$subDir");
        }
        $base = realpath($dir);
        $target = realpath($dir . '/' . $subDir);
        if ($base === false || $target === false || ($target !== $base && !str_starts_with($target, $base . DIRECTORY_SEPARATOR))
            || is_link($dir . '/' . $file)) {
            throw new \Exception("output file outside var/custom/html: $file");
        }
        if (!$this->system->filePutContents($dir, $file, $content)) {
            throw new \Exception("could not write var/custom/html/$file");
        }
        return ", saved to var/custom/html/$file";
    }

    /**
     * Datenbank-Sicherung, einmal am Tag - das mysqldump-Kommando liegt in
     * Camindo\Core\Backup (auch von camindo.php upgrade/component benutzt).
     */
    private function runDbBackup(array $task, int $timeout): string
    {
        $details = (array)($task['details'] ?? []);
        $keep = max(1, (int)($details['backups'] ?? 7));

        // Höchstens einmal am Tag
        $first = $this->backup->dir() . '/backup-1.sql';
        if (is_file($first) && date('Y-m-d', (int)filemtime($first)) === date('Y-m-d')) {
            return 'skipped, backup-1.sql is from today';
        }

        $file = $this->backup->rotate($keep, $timeout);
        return 'backup-1.sql written, ' . $this->system->convertSize((float)filesize($file));
    }

    /**
     * Volltext neu aufbauen (Typ fulltext) - siehe Klassenkommentar.
     */
    private function runFulltext(array $task, int $timeout): string
    {
        $details = (array)($task['details'] ?? []);
        $areaIds = (array)($details['area_ids'] ?? []);
        if (!empty($details['only_stale'])) {
            // nur die als veraltet markierten (der gewählten) Bereiche
            $stale = $this->container->get(\Camindo\Core\FulltextState::class)->staleAreaIds();
            $areaIds = $areaIds ? array_values(array_intersect(array_map('intval', $areaIds), $stale)) : $stale;
            if (empty($areaIds)) return 'no outdated areas';
        }
        $result = $this->container->get(RecordService::class)->rebuildFulltext($areaIds, time() + $timeout);
        $message = sprintf('%d records in %d areas', $result['records'], $result['areas']);
        // Bereiche, die gerade ein anderer Prozess neu aufbaut (FulltextState-Sperre)
        if ($result['skipped']) $message .= ', skipped (already running): ' . implode(', ', $result['skipped']);
        if (!$result['complete']) throw new \Exception("timeout after $timeout s, $message done");
        return $message;
    }

    /**
     * Ausgabe-Cache von CMS:INCLUDE aufräumen (Typ cachecleanup).
     */
    private function runCacheCleanup(): string
    {
        $result = $this->container->get(IncludeCache::class)->cleanup();
        if ($result['failed']) {
            throw new \Exception('could not delete: ' . implode(', ', array_slice($result['failed'], 0, 10)));
        }
        return sprintf('%d cache files (%s) deleted, %d kept', $result['files'],
            $this->system->convertSize((float)$result['bytes']), $result['kept']);
    }

    /**
     * Alte Protokolle löschen (Typ logcleanup) - siehe Klassenkommentar.
     */
    private function runLogCleanup(array $task): string
    {
        $details = (array)($task['details'] ?? []);
        $logDays = max(1, (int)($details['log_days'] ?? 90));
        $securityDays = max(1, (int)($details['security_days'] ?? 365));
        $attemptDays = max(1, (int)($details['attempt_days'] ?? 30));

        $dir = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/log';
        $today = new \DateTimeImmutable('today');
        $files = 0;
        $bytes = 0;
        $failed = [];
        foreach ((array)glob($dir . '/*.log') as $path) {
            $path = (string)$path;
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})_(.+)\.log$/', basename($path), $m)) continue;
            $days = in_array($m[2], Scheduler::SECURITY_LOGS, true) ? $securityDays : $logDays;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1]);
            if ($date === false || $date > $today->modify("-$days days")) continue;
            $size = (int)filesize($path);
            if (@unlink($path)) {
                $files++;
                $bytes += $size;
            }
            else {
                $failed[] = basename($path);
            }
        }

        $attempts = 0;
        foreach (['admin_login_attempt', 'user_login_attempt'] as $table) {
            $this->db->query(
                "DELETE FROM `$table` WHERE `attempt_date` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)",
                'i', [$attemptDays]
            );
            $attempts += max(0, (int)$this->db->affected_rows);
        }

        if ($failed) {
            throw new \Exception('could not delete: ' . implode(', ', array_slice($failed, 0, 10)));
        }
        return sprintf('%d log files (%s), %d login attempts deleted', $files,
            $this->system->convertSize((float)$bytes), $attempts);
    }

    // Wartungsaufgaben (Scheduler::MAINTENANCE_TYPES)
    // ====================

    /**
     * Papierkorb leeren (Typ trash) - siehe Klassenkommentar.
     */
    private function runTrash(): string
    {
        if (!$this->bgproc->lock('trash')) return 'already running';
        $auditLog = $this->container->get(AuditLog::class);
        $context = $auditLog->context();
        try {
            // Audit-Log: endgültiges Löschen aus dem Papierkorb als 'trash' kennzeichnen
            $auditLog->setContext('trash');
            $result = $this->container->get(ToolDatabase::class)->purgeTrash();
        }
        finally {
            $auditLog->setContext($context);
            $this->bgproc->unlock();
        }
        $message = sprintf('%d records, %d areas deleted', $result['record'], $result['area']);
        if ($result['errors']) {
            foreach ($result['errors'] as $error) $this->system->log('trash', $error);
            throw new \Exception($message . ', ' . count($result['errors']) . ' errors: '
                . implode(' | ', array_slice($result['errors'], 0, 5)));
        }
        return $message;
    }

    /**
     * Temporäre Dateien und abgelaufene Exporte löschen (Typ tmpcleanup).
     */
    private function runTmpCleanup(): string
    {
        $exports = $this->container->get(RecordExport::class)->cleanup();
        return sprintf('%d tmp entries, %d exports deleted', $this->cleanupTmp(), $exports);
    }

    /**
     * var/tmp aufräumen: alles, was älter ist als admin.tmp_retention_hours -
     * liegen gebliebene Importverzeichnisse, Auftragsdateien, Fortschrittsdateien
     * abgebrochener Prozesse. Sperrdateien (*.lock), Stempeldateien (*.stamp) und
     * cron.log.txt bleiben - sie werden laufend benutzt (SystemHealth::checkTmp()
     * zählt mit denselben Ausnahmen).
     *
     * @return int  Anzahl der entfernten Einträge
     */
    public function cleanupTmp(): int
    {
        $dir = (string)$this->config->get('paths.tmp_dir');
        if (!is_dir($dir)) return 0;
        $hours = max(1, (int)($this->config->get('admin.tmp_retention_hours') ?? 24));
        $limit = time() - $hours * 3600;
        $removed = 0;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'cron.log.txt') continue;
            if (str_ends_with($entry, '.lock') || str_ends_with($entry, '.stamp')) continue;
            $path = "$dir/$entry";
            if ((int)filemtime($path) > $limit) continue;
            if (is_dir($path)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iterator as $item) {
                    $item->isDir() ? @rmdir((string)$item->getPathname()) : @unlink((string)$item->getPathname());
                }
                if (@rmdir($path)) $removed++;
            }
            elseif (@unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Fällige Aufträge der Lösch-Policies (Typ privacy) - in Runden, bis keine mehr
     * fällig sind oder die Zeit abläuft.
     */
    private function runPrivacy(int $timeout): string
    {
        $jobs = $this->container->get(PrivacyJobs::class);
        $deadline = time() + $timeout - 30;
        $done = 0;
        $failed = 0;
        do {
            $result = $jobs->runDue();
            $done += $result['done'];
            $failed += $result['failed'];
            // Weiter nur nach einer vollen Runde ohne Fehler: ein gescheiterter Auftrag
            // bleibt fällig und käme sonst sofort wieder dran - seine Versuche
            // (PrivacyJobs::MAX_ATTEMPTS) sollen sich auf mehrere Läufe verteilen
        } while ($result['done'] >= PrivacyJobs::RUN_LIMIT && $result['failed'] === 0 && time() < $deadline);
        $message = sprintf('%d jobs done, %d failed', $done, $failed);
        if ($failed > 0) throw new \Exception($message . ' (details: log privacy)');
        return $message;
    }

    /**
     * Aufbewahrungsfristen (Typ retention) - jeder Schritt für sich.
     */
    private function runRetention(): string
    {
        $parts = [];
        $errors = [];
        $steps = [
            'ip' => function () {
                $result = $this->container->get(IpAnonymizer::class)->run();
                return sprintf('%d IP addresses in the database, %d log files, %d audit entries shortened',
                    array_sum((array)$result['rows']), (int)$result['files'], (int)($result['audit'] ?? 0));
            },
            'audit' => function () {
                $result = $this->container->get(AuditLog::class)->purge();
                return sprintf('audit: %d deleted objects, %d changes, %d logins removed',
                    $result['deleted'], $result['changes'], $result['logins']);
            },
            'messages' => fn() => sprintf('%d messages deleted', $this->container->get(Message::class)->purgeExpired()),
            'unconfirmed' => function () {
                // in Runden: purgeUnconfirmed() nimmt höchstens PURGE_UNCONFIRMED_LIMIT,
                // eine Runde mit Fehlern (weniger gelöscht) beendet die Schleife
                $users = $this->container->get(RecordUser::class);
                $total = 0;
                for ($round = 0; $round < 50; $round++) {
                    $count = $users->purgeUnconfirmed();
                    $total += $count;
                    if ($count < RecordUser::PURGE_UNCONFIRMED_LIMIT) break;
                }
                return sprintf('%d unconfirmed registrations deleted', $total);
            },
        ];
        foreach ($steps as $name => $step) {
            try {
                $parts[] = $step();
            }
            catch (\Throwable $e) {
                $errors[] = "$name: " . $e->getMessage();
            }
        }
        $message = implode(', ', $parts);
        if ($errors) throw new \Exception(implode(' | ', $errors) . ($message !== '' ? ' - ' . $message : ''));
        return $message;
    }

    /**
     * Nachrichten per E-Mail zusammenfassen (Typ messagedigest). last_run steht in
     * der Zeitzone des Systems, message.created_at in UTC.
     */
    private function runMessageDigest(array $task): string
    {
        $last = trim((string)($task['last_run'] ?? ''));
        $since = $last !== ''
            ? (new \DateTimeImmutable($last, $this->scheduler->systemTimezone()))->getTimestamp()
            : time() - 86400;
        $result = $this->container->get(MessageDigest::class)->run(gmdate('Y-m-d H:i:s', $since));
        $message = sprintf('%d digests sent, %d without new messages', $result['sent'], $result['skipped']);
        if ($result['failed']) {
            throw new \Exception($message . ', ' . count($result['failed']) . ' failed: '
                . implode(' | ', array_slice($result['failed'], 0, 5)));
        }
        return $message;
    }

    /**
     * Audit-Log prüfen (Typ auditverify) - das Ergebnis zeigt die Startseite
     * (SystemHealth, Prüfung 'audit').
     */
    private function runAuditVerify(): string
    {
        $auditLog = $this->container->get(AuditLog::class);
        if (!$auditLog->enabled()) return 'audit log disabled';
        $result = $auditLog->verifyAll();
        $auditLog->storeVerifyResult($result);
        $message = sprintf('%d objects checked, %d with findings', $result['checked'], count($result['failed']));
        if ($result['failed']) throw new \Exception($message);
        return $message;
    }

    /**
     * Nach Updates und Sicherheitslücken suchen (Typ updatecheck) - das Ergebnis
     * zeigen die Info-Seite und die Startseite.
     */
    private function runUpdateCheck(): string
    {
        $check = $this->container->get(UpdateCheck::class);
        if (!$check->enabled()) return 'disabled (paths.composer)';
        $result = $check->run();
        $message = sprintf('outdated: %d, advisories: %d, abandoned: %d',
            count($result['outdated']), count($result['advisories']), count($result['abandoned']));
        if ($result['error'] !== '') throw new \Exception($message . ', error: ' . $result['error']);
        return $message;
    }
}
