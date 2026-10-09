<?php
/**
 * Die Schleife hinter Core/cron/cron.php - dem EINZIGEN Cronjob von camindo CMS.
 *
 * Läuft ~10 Minuten am Stück (der Cronjob startet jede Minute, die Sperre 'cron'
 * verhindert parallele Läufe) und startet nur eigenständige Hintergrundprozesse:
 *
 *     alle 2 s   trigger_ff.php, trigger_magick.php   Medienumwandlung
 *     jede Min.  Herzschlag (SystemHealth), scheduler.php (fällige Aufgaben des
 *                Zeitplaners), template_sync.php (var/templates abgleichen)
 *
 * Alles Zeitgesteuerte darüber hinaus - auch die Wartung (Papierkorb leeren, var/tmp,
 * Lösch-Policies, Aufbewahrungsfristen, Audit-Prüfung, Update-Prüfung, Logs, Backup)
 * - sind Aufgaben des Zeitplaners (Modul Scheduler), angelegt vom Installer.
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

    /** Hintergrundprozess-Typ */
    private const BGPROC_TYPE = 'cron';

    /** Fehlermeldungen je Typ höchstens einmal je Intervall (Sekunden) */
    private const ERROR_LOG_INTERVAL = 3600;

    /** Stempeldatei in var/tmp: Zeitpunkt der letzten Meldung je Typ (JSON) */
    private const ERROR_STAMP = 'cron-errors.stamp';

    /** Zeitpunkt der letzten Meldung je Typ - Inhalt von ERROR_STAMP */
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
     * Fehler melden - höchstens einmal je Stunde und Typ.
     *
     * Die Schleife läuft im Sekundentakt; schlägt ein Aufruf dauerhaft fehl (php
     * nicht ausführbar, Trigger-Script defekt), stünde dieselbe Meldung sonst
     * tausendfach in var/log/{Y-m-d}_cron-errors.log und in der Ausgabe (die der
     * Cronjob meist in eine Datei umleitet). Der Zeitpunkt der letzten Meldung
     * je Typ steht in var/tmp/cron-errors.stamp und überdauert so die 10-Minuten-
     * Läufe von cron.php (die Aufgabe tmpcleanup lässt *.stamp in Ruhe).
     *
     * @param string $type     Kennung der Fehlerquelle, z. B. 'php', 'trigger_ff'
     * @param string $message  Meldung
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
     * Ein Cron-Script als eigenen Hintergrundprozess starten; Fehler gedrosselt
     * melden (logError()).
     *
     * @param string $script  Dateiname in Core/cron, zugleich der Fehler-Typ
     * @return bool           true: gestartet
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
     * Intervallfunktion alle 2 Sek
     */
    private function tick2s(): bool {
        // php executable testen
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
     * Intervallfunktion alle 10 Sek
     */
    private function tick10s(): bool {
        // Code für alle 10 Sekunden
        // echo "[" . date('H:i:s') . "] tick10s executed\n";

        return true;
    }

    /**
     * Intervallfunktion alle 60 Sek
     */
    private function tick1m(): bool {
        // ---- Herzschlag: die Startseite warnt, wenn er ausbleibt (SystemHealth) ----
        @touch($this->config->get('paths.tmp_dir') . '/' . SystemHealth::CRON_HEARTBEAT);

        // ---- Scheduler: fällige Aufgaben starten (Modul Scheduler) ----
        $this->startScript('scheduler.php');

        // ---- Template-Quelltexte: var/templates in die Datenbank übernehmen ----
        // (angelegte Dateien/Verzeichnisse werden Templates/Bereiche, geänderte
        //  Dateien werden neu kompiliert, gelöschte räumt es ab - siehe
        //  TemplateFiles::sync())
        $this->startScript('template_sync.php');

        // Tägliche und stündliche Wartung (Papierkorb, var/tmp, Lösch-Policies,
        // Aufbewahrungsfristen, Audit-Prüfung, Update-Prüfung …) sind Aufgaben des
        // Zeitplaners (Scheduler::MAINTENANCE_TYPES) - terminierbar im Modul Zeitplaner

        return true;
    }

    public function run(): void
    {
        // Keine parallelen Durchläufe erlauben
        if (!$this->bgproc->lock(self::BGPROC_TYPE)) {
            echo "Cron is already running. Exit.\n";
            exit(0); // läuft bereits
        }

        // ---- Hauptschleife, Zeitsteuerung ----
        $exitCode = 0;
        $startTime = microtime(true);
        $maxRunTime = 597; // 9 Minuten 57 Sekunden
                           // 3 Sek Sicherheitsabstand sollten reichen - es werden ja nur
                           // eigenständige Hintergrundprozesse gestartet
        $last2s = 0;
        $last10s = 0;
        $last1m = 0;

        while (true) {
            $now = microtime(true);
            $elapsed = $now - $startTime;

            // Abbruchbedingung: Maximale Laufzeit erreicht
            if ($elapsed >= $maxRunTime) {
                break;
            }

            // Intervallfunktionen aufrufen
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

            // CPU-Last schonen (100ms Schlafen)
            usleep(100000); 
        }


        $this->bgproc->unlock();
        echo "done.\n";

        $exitCode = max($exitCode, 1);
        exit($exitCode);
    }
}