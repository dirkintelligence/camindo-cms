<?php
/**
 * Execution of the time-controlled tasks (module Scheduler).
 *
 * Two entry points, both via Core/cron/scheduler.php on the command line:
 *
 *     dispatch()    without argument - started every minute by TriggerCron::tick1m().
 *                   Looks up the due tasks (`enabled`, `next_run` reached) and
 *                   starts a background process for each task
 *                   (scheduler.php --id=N), so that a long task does not hold up the others.
 *                   Before that, `next_run` is already advanced and
 *                   `last_status` set to "running" - so nothing is started twice.
 *     run($id)      --id=N - runs ONE task and records the result
 *                   (`last_run`, `last_status`, `last_message`), and with `log` set
 *                   also in the system log file var/log/{date}_scheduler.log.
 *                   With --token=... the bgproc entry is additionally closed
 *                   (button "Run now" in the backend).
 *
 * UNDER WHICH ADMIN
 * --------------------
 * Each task runs under its admin (`scheduler`.`admin_id`, default the admin
 * 'scheduler'): run() opens its own session for it (ApiSession::runAs(),
 * command-line interface, channel 'scheduler') and closes it afterwards. Its
 * rights apply; in the audit log it appears with the origin 'scheduler', in the log file
 * of the scheduler its admin_id. A PHP script gets the session as
 * CAMINDO_API_SESSION (runPhp()); a template works in the same process within it.
 * If the admin is deleted, locked, or its group may not use the command line,
 * the run fails with a message.
 *
 * REPORTS
 * --------------------
 * A url or php task with details.report is a selectable report: admins of the
 * groups in details.report_group_ids subscribe to it in their personal settings
 * (settings.reports). After every SUCCESSFUL run the output goes as an internal message in
 * HTML format to the subscribers (sendReport()).
 *
 * TIME OVERRUN
 * --------------------
 * `max_minutes` is the upper limit per run: commands run via Execute::execute()
 * with timeout, the URL fetch via CURLOPT_TIMEOUT. A task that still shows as "running"
 * for longer than max_minutes + 5 counts as aborted for dispatch() and
 * is started again at the next due time.
 *
 * THE TASKS
 * --------------------
 *     url        curl fetch of the address, optionally with HTTP auth (Basic); every
 *                HTTP status from 400 on is an error. The response is stored under
 *                var/custom/html/{output_file} or discarded.
 *     php        {paths.php} var/custom/php/{script} - exit code not 0 is an
 *                error, the output (stdout) is stored or discarded.
 *     dbbackup   mysqldump to var/dbbackup/backup-1.sql. Before that the
 *                existing backups move up by one (backup-1 -> backup-2 ...), whatever
 *                goes beyond `details.backups` is deleted. At most ONE run per day:
 *                if backup-1.sql of today exists, nothing happens.
 *                The command itself is in Camindo\Core\Backup (password from
 *                paths.sqlpwd_file, never on the command line).
 *     logcleanup delete log files var/log/{Y-m-d}_{name}.log whose day is older
 *                than `details.log_days` (security logs Scheduler::SECURITY_LOGS:
 *                `details.security_days`), plus the rows in admin_login_attempt /
 *                user_login_attempt older than `details.attempt_days` (name + IP).
 *                What counts is the date in the file name, not filemtime().
 *     cachecleanup IncludeCache::cleanup() - delete expired files of the output cache
 *                of CMS:INCLUDE under var/cache/include, plus files of
 *                deleted or changed templates and leftover lock and
 *                temporary files. No settings.
 *     template   Page::renderScheduled() - a frontend template (usage
 *                "scheduled") in the environment of its instance and the chosen
 *                language. Time window: from the end of the last SUCCESSFUL run
 *                (details.window_end, UTC) until now; on the first run it is empty
 *                (start = end). After an error, window_end stays, and the
 *                next run catches up the whole window. Messages of the template
 *                (CMS errors, e.g. from CMS:EMAIL) make the run an error.
 *                The output is stored or discarded as with url/php.
 *     fulltext   RecordService::rebuildFulltext() for details.area_ids (empty = all
 *                record areas), with details.only_stale only those marked as stale
 *                (FulltextState). Areas that another process is currently rebuilding
 *                are skipped. If max_minutes is not enough, the run aborts and
 *                counts as failed - the next one starts over.
 *     trash      ToolDatabase::purgeTrash() with admin.trash_retention_days; lock
 *                'trash' against parallel runs, origin in the audit log 'trash'.
 *                Errors of individual records do not stop the run, but make it an
 *                error (message with the first errors).
 *     tmpcleanup var/tmp (older than admin.tmp_retention_hours; *.lock, *.stamp and
 *                cron.log.txt remain) and var/export (RecordExport::cleanup()).
 *     privacy    PrivacyJobs::runDue() in rounds of RUN_LIMIT orders, until none
 *                are due, max_minutes expires or an order fails
 *                (it remains due, the next run tries it again).
 *     retention  IpAnonymizer, AuditLog::purge(), Message::purgeExpired(),
 *                RecordUser::purgeUnconfirmed(), then the retention of the active
 *                modules (settings.php 'retention': ['Class::method'], method without
 *                parameters returning a summary) - each step on its own: an error
 *                does not stop the others, but makes the run an error.
 *     auditverify AuditLog::verifyAll() + storeVerifyResult(); findings = errors.
 *     updatecheck UpdateCheck::run(); without paths.composer: nothing to do.
 *     messagedigest MessageDigest::run() - "new" are messages since the previous
 *                run ($task['last_run'] before the start; without a previous run: 24 hours);
 *                a failed sending makes the run an error.
 *
 * @see Camindo\Core\Modules\Scheduler\Scheduler          tasks, cron calculation
 * @see Camindo\Core\Modules\Scheduler\SchedulerBackend   "Run now"
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

    // Grace period after which a "running" entry counts as aborted (minutes)
    private const STALE_MINUTES = 5;

    // Largest report that is sent as a message (bytes); above it only
    // a note goes out
    private const REPORT_MAX_BYTES = 2 * 1024 * 1024;

    /** Output of the running url/php run - content of a report (sendReport()) */
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
     * Absolute path of the CLI script.
     */
    public function scriptPath(): string
    {
        return $this->config->get('paths.core_dir') . '/cron/scheduler.php';
    }

    /**
     * Start a task immediately as a background process of the logged-in admin
     * ("Run now" in the UI, API scheduler.run).
     *
     * @param string $error  reason if the start fails
     * @return ?string       token of the background process; null = not started
     */
    public function startNow(int $id, string &$error = ''): ?string
    {
        $token = $this->container->get(\Camindo\Core\Crypt::class)->randomKey(16);
        $command = [(string)$this->config->get('paths.php'), $this->scriptPath(), '--id=' . $id, '--token=' . $token];
        return $this->bgproc->start($command, 'scheduler', $token, null, null, $error) ? $token : null;
    }

    /**
     * Start due tasks - one each as its own background process.
     *
     * @return int  number of started tasks
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

            // Note the next due time and mark as running - BEFORE the
            // process starts, otherwise the next pass would find the same task
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
     * Is the task running right now (and not already suspiciously long)?
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
     * Run ONE task and record the result.
     *
     * @param int         $id     scheduler_id
     * @param string|null $token  bgproc token if started from the backend
     * @return bool  true if the task passed without errors
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

        // Under the admin of the task: own session (command-line interface,
        // channel 'scheduler') - its rights apply, in the audit log it appears
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
            // Selectable report: result to the subscribers - still in the session of the
            // task, its admin is the sender. An error when sending does not make the
            // run an error; it is noted in the message.
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
     * Enter and log the result.
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

    // The tasks
    // ====================

    /**
     * URL call with curl.
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
     * PHP script from var/custom/php on the command line. The script gets the session
     * of the task as environment variable CAMINDO_API_SESSION and works with it via the
     * API under its admin:
     *
 *     require '/path/to/project/load_camindo.php';
     *     $app->get(\Camindo\Core\Api\ApiSession::class)->resume(getenv('CAMINDO_API_SESSION'), 'scheduler');
     *     $result = $app->get(\Camindo\Core\Api\Api::class)->call('record.search', [...]);
     */
    private function runPhp(array $task, int $timeout, string $sessionId = ''): string
    {
        $details = (array)($task['details'] ?? []);
        $script = (string)($details['script'] ?? '');
        // resolved path within var/custom/php (Scheduler::scriptFile())
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
     * Time-controlled template (type template) - see class comment.
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

        // Own process per task - the error collection belongs to this run
        $cms = $this->container->get(Cms::class);
        $cms->errorsReset();
        $output = $this->container->get(Page::class)->renderScheduled(
            $templateId, (int)($details['language_id'] ?? 0), $lastRun, $thisRun, (int)$task['scheduler_id']
        );
        if ($cms->hasErrors) throw new \Exception('template: ' . $cms->errorMsg(' | '));

        // Only advance the window after success (directly, without mask and audit)
        $this->db->query(
            "UPDATE `scheduler` SET `details` = JSON_SET(COALESCE(`details`, '{}'), '$.window_end', ?) WHERE `scheduler_id` = ?",
            'si', [gmdate('Y-m-d H:i:s', $thisRun), (int)$task['scheduler_id']]
        );
        $saved = $this->saveOutput($details, $output);
        return sprintf('window %s - %s UTC, %d bytes%s', gmdate('Y-m-d H:i:s', $lastRun), gmdate('Y-m-d H:i:s', $thisRun), strlen($output), $saved);
    }

    /**
     * Selectable report (details.report, type url/php): the output as internal
     * message (Message::sendSystem(), only in the inbox) to each subscriber
     * (Scheduler::reportSubscribers()). The output goes as HTML into the message:
     * for a whole document only the content of <body>, active content removed
     * (HtmlSanitizer), text without tags preformatted. Larger than REPORT_MAX_BYTES:
     * only a note.
     *
     * @return string  addition to the message of the run ('' without subscribers)
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
     * Output as HTML for the message (see sendReport()).
     */
    private function reportHtml(string $output): string
    {
        if (trim($output) === '') return '';
        if (!preg_match('/<[a-z!\/][^>]*>/i', $output)) {
            return '<pre>' . htmlspecialchars($output) . '</pre>';
        }
        // Whole document: only the body; head and styles do not belong in the backend
        if (preg_match('~<body\b[^>]*>(.*?)(?:</body>|$)~is', $output, $m)) $output = $m[1];
        $output = (string)preg_replace('~<(head|style|title)\b[^>]*>.*?</\1\s*>~is', '', $output);
        $output = (string)preg_replace('~<!doctype[^>]*>|</?(html|body)\b[^>]*>~i', '', $output);
        return $this->container->get(\Camindo\Core\HtmlSanitizer::class)->clean(trim($output));
    }

    /**
     * Store a task's output under var/custom/html - or discard it. The target
     * folder must resolve (realpath, after creation) within
     * var/custom/html, the file itself must not be a symlink.
     *
     * @return string  addition to the message
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
     * Database backup, once a day - the mysqldump command is in
     * Camindo\Core\Backup (also used by camindo.php upgrade/component).
     */
    private function runDbBackup(array $task, int $timeout): string
    {
        $details = (array)($task['details'] ?? []);
        $keep = max(1, (int)($details['backups'] ?? 7));

        // At most once a day
        $first = $this->backup->dir() . '/backup-1.sql';
        if (is_file($first) && date('Y-m-d', (int)filemtime($first)) === date('Y-m-d')) {
            return 'skipped, backup-1.sql is from today';
        }

        $file = $this->backup->rotate($keep, $timeout);
        return 'backup-1.sql written, ' . $this->system->convertSize((float)filesize($file));
    }

    /**
     * Rebuild the full text (type fulltext) - see class comment.
     */
    private function runFulltext(array $task, int $timeout): string
    {
        $details = (array)($task['details'] ?? []);
        $areaIds = (array)($details['area_ids'] ?? []);
        if (!empty($details['only_stale'])) {
            // only those (of the selected) areas marked as stale
            $stale = $this->container->get(\Camindo\Core\FulltextState::class)->staleAreaIds();
            $areaIds = $areaIds ? array_values(array_intersect(array_map('intval', $areaIds), $stale)) : $stale;
            if (empty($areaIds)) return 'no outdated areas';
        }
        $result = $this->container->get(RecordService::class)->rebuildFulltext($areaIds, time() + $timeout);
        $message = sprintf('%d records in %d areas', $result['records'], $result['areas']);
        // Areas that another process is currently rebuilding (FulltextState lock)
        if ($result['skipped']) $message .= ', skipped (already running): ' . implode(', ', $result['skipped']);
        if (!$result['complete']) throw new \Exception("timeout after $timeout s, $message done");
        return $message;
    }

    /**
     * Clean up the output cache of CMS:INCLUDE (type cachecleanup).
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
     * Delete old logs (type logcleanup) - see class comment.
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

    // Maintenance tasks (Scheduler::MAINTENANCE_TYPES)
    // ====================

    /**
     * Empty the trash (type trash) - see class comment.
     */
    private function runTrash(): string
    {
        if (!$this->bgproc->lock('trash')) return 'already running';
        $auditLog = $this->container->get(AuditLog::class);
        $context = $auditLog->context();
        try {
            // Audit log: mark the final deletion from the trash as 'trash'
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
     * Delete temporary files and expired exports (type tmpcleanup).
     */
    private function runTmpCleanup(): string
    {
        $exports = $this->container->get(RecordExport::class)->cleanup();
        return sprintf('%d tmp entries, %d exports deleted', $this->cleanupTmp(), $exports);
    }

    /**
     * Clean up var/tmp: everything older than admin.tmp_retention_hours -
     * left-over import directories, job files, progress files of
     * aborted processes. Lock files (*.lock), stamp files (*.stamp) and
     * cron.log.txt remain - they are used continuously (SystemHealth::checkTmp()
     * counts with the same exceptions).
     *
     * @return int  number of removed entries
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
     * Due orders of the deletion policies (type privacy) - in rounds, until none
     * are due or the time runs out.
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
            // Continue only after a full round without errors: a failed order
            // remains due and would otherwise come up again immediately - its attempts
            // (PrivacyJobs::MAX_ATTEMPTS) should be spread over several runs
        } while ($result['done'] >= PrivacyJobs::RUN_LIMIT && $result['failed'] === 0 && time() < $deadline);
        $message = sprintf('%d jobs done, %d failed', $done, $failed);
        if ($failed > 0) throw new \Exception($message . ' (details: log privacy)');
        return $message;
    }

    /**
     * Retention periods (type retention) - each step on its own.
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
                // in rounds: purgeUnconfirmed() takes at most PURGE_UNCONFIRMED_LIMIT,
                // a round with errors (fewer deleted) ends the loop
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
        // modules: 'retention' => ['Class::method'] in settings.php (class relative to the
        // namespace of the module)
        foreach ($this->container->get(Cms::class)->modules as $name => $settings) {
            $namespace = (string)($settings['namespace'] ?? '');
            foreach ((array)($settings['retention'] ?? []) as $callable) {
                [$class, $method] = array_pad(explode('::', (string)$callable, 2), 2, '');
                if ($namespace === '' || $class === '' || $method === '') continue;
                $steps[$name . ':' . $method] = fn() => (string)$this->container->get($namespace . '\\' . trim($class, '\\'))->$method();
            }
        }
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
     * Summarize messages by e-mail (type messagedigest). last_run is in
     * the time zone of the system, message.created_at in UTC.
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
     * Check the audit log (type auditverify) - the result is shown by the start page
     * (SystemHealth, check 'audit').
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
     * Search for updates and security vulnerabilities (type updatecheck) - the result
     * is shown by the info page and the start page.
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
