<?php
/**
 * The loop behind Core/cron/cron.php - the ONLY cron job of camindo CMS.
 *
 * Runs about 10 minutes at a stretch (the cron job starts every minute, the lock 'cron'
 * prevents parallel runs) and only starts independent background processes:
 *
 *     every 2 s   trigger_ff.php, trigger_magick.php   media conversion
 *     every min.  heartbeat (SystemHealth), scheduler.php (due tasks of the
 *                 scheduler), template_sync.php (reconcile var/templates)
 *
 * Everything else time-controlled - including maintenance (empty trash, var/tmp,
 * deletion policies, retention periods, audit check, update check, logs, backup)
 * - are tasks of the scheduler (module Scheduler), created by the installer.
 *
 * @see Camindo\Core\Modules\Scheduler\SchedulerRunner
 */
namespace Camindo\Core\cron;

use Camindo\Core\Config;
use Camindo\Core\System;
use Camindo\Core\Execute;
use Camindo\Core\BackgroundProcess;
use Camindo\Core\SystemHealth;

class TriggerCron {

    /** Background process type */
    private const BGPROC_TYPE = 'cron';

    /** Error messages per type at most once per interval (seconds) */
    private const ERROR_LOG_INTERVAL = 3600;

    /** Stamp file in var/tmp: time of the last message per type (JSON) */
    private const ERROR_STAMP = 'cron-errors.stamp';

    /** Time of the last message per type - content of ERROR_STAMP */
    private ?array $errorStamps = null;

    public function __construct(
        private Config $config,
        private System $system,
        private BackgroundProcess $bgproc,
        private Execute $execute,
    )
    {
    }        

    /**
     * Report errors - at most once per hour and type.
     *
     * The loop runs at a per-second rhythm; if a call fails permanently (php
     * not executable, trigger script defective), the same message would otherwise stand
     * a thousand times in var/log/{Y-m-d}_cron-errors.log and in the output (which the
     * cron job mostly redirects to a file). The time of the last message
     * per type is in var/tmp/cron-errors.stamp and thus survives the 10-minute
     * runs of cron.php (the task tmpcleanup leaves *.stamp alone).
     *
     * @param string $type     identifier of the error source, e.g. 'php', 'trigger_ff'
     * @param string $message  message
     */
    private function logError(string $type, string $message): void
    {
        $stampFile = $this->config->get('paths.tmp_dir') . '/' . self::ERROR_STAMP;
        if ($this->errorStamps === null) {
            $stamps = is_file($stampFile) ? json_decode((string)file_get_contents($stampFile), true) : null;
            $this->errorStamps = is_array($stamps) ? $stamps : [];
        }
        $now  = time();
        $last = (int)($this->errorStamps[$type] ?? 0);
        if ($now - $last < self::ERROR_LOG_INTERVAL) return;

        $this->errorStamps[$type] = $now;
        @file_put_contents($stampFile, json_encode($this->errorStamps));

        $this->system->log('cron-errors', "[$type] $message");
        echo "Error [$type]: $message\n";
    }

    /**
     * Start a cron script as its own background process; report errors throttled
     * (logError()).
     *
     * @param string $script  file name in Core/cron, also the error type
     * @return bool           true: started
     */
    private function startScript(string $script): bool
    {
        $phpExec = (string)$this->config->get('paths.php');
        $command = [$phpExec, __DIR__ . DIRECTORY_SEPARATOR . $script];
        $error = '';
        if ($this->execute->executeBackground($command, error:$error)) return true;
        $this->logError(pathinfo($script, PATHINFO_FILENAME), $error);
        return false;
    }

    /**
     * Interval function every 2 sec
     */
    private function tick2s(): bool {
        // test php executable
        $phpExec = $this->config->get('paths.php');
        if ($phpExec === '' || !is_executable($phpExec)) {
            $this->logError('php', "'php' is not executable ($phpExec)");
            return false;
        }

        // ---- TriggerFFmpeg.php ----
        $this->startScript('trigger_ff.php');

        // ---- Trigger Magick.php ----
        $this->startScript('trigger_magick.php');

        return true;
    }

    /**
     * Interval function every 10 sec
     */
    private function tick10s(): bool {
        // Code for every 10 seconds
        // echo "[" . date('H:i:s') . "] tick10s executed\n";

        return true;
    }

    /**
     * Interval function every 60 sec
     */
    private function tick1m(): bool {
        // ---- Heartbeat: the start page warns if it fails to appear (SystemHealth) ----
        @touch($this->config->get('paths.tmp_dir') . '/' . SystemHealth::CRON_HEARTBEAT);

        // ---- Scheduler: start due tasks (module Scheduler) ----
        $this->startScript('scheduler.php');

        // ---- Template source texts: take var/templates into the database ----
        // (created files/directories become templates/areas, changed
        //  files are recompiled, deleted ones are cleared away - see
        //  TemplateFiles::sync())
        $this->startScript('template_sync.php');

        // Daily and hourly maintenance (trash, var/tmp, deletion policies,
        // retention periods, audit check, update check …) are tasks of the
        // scheduler (Scheduler::MAINTENANCE_TYPES) - schedulable in the module Scheduler

        return true;
    }

    public function run(): void
    {
        // Do not allow parallel runs
        if (!$this->bgproc->lock(self::BGPROC_TYPE)) {
            echo "Cron is already running. Exit.\n";
            exit(0); // already running
        }

        // ---- Main loop, time control ----
        $exitCode = 0;
        $startTime = microtime(true);
        $maxRunTime = 597; // 9 minutes 57 seconds
                           // a 3-second safety margin should be enough - only
                           // independent background processes are started
        $last2s = 0;
        $last10s = 0;
        $last1m = 0;

        while (true) {
            $now = microtime(true);
            $elapsed = $now - $startTime;

            // Termination condition: maximum run time reached
            if ($elapsed >= $maxRunTime) {
                break;
            }

            // Call interval functions
            if ($now - $last2s >= 2.0) {
                $exitCode += $this->tick2s();
                $last2s = $now;
            }

            if ($now - $last10s >= 10.0) {
                $exitCode += $this->tick10s();
                $last10s = $now;
            }

            if ($now - $last1m >= 60.0) {
                $exitCode += $this->tick1m();
                $last1m = $now;
            }

            // Spare the CPU (sleep 100 ms)
            usleep(100000); 
        }


        $this->bgproc->unlock();
        echo "done.\n";

        $exitCode = max($exitCode, 1);
        exit($exitCode);
    }
}