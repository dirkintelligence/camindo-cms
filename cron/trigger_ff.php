<?php
/**
 * trigger_ff.php - Fortschritt der ffmpeg-Hintergrundprozesse nachführen
 *
 * ffmpeg schreibt mit "-progress DATEI" fortlaufend Blöcke aus "schlüssel=wert"-Zeilen
 * in eine temporäre Datei unter paths.tmp_dir. Der Dateiname ergibt sich aus dem Token
 * des Hintergrundprozesses, siehe TriggerMedia::progressFileName().
 *
 * Dieses Skript
 *   - sucht alle Einträge in `bgproc` mit `type` = 'ffmpeg',
 *   - öffnet die zugehörige Fortschrittsdatei und liest deren letzte Zeilen,
 *   - ermittelt daraus den aktuellen Frame,
 *   - vergleicht ihn mit der Gesamtzahl der Frames aus `message`,
 *   - trägt den Fortschritt in Prozent in `progress` ein,
 *   - bricht überfällige Prozesse ab und räumt fertige Fortschrittsdateien weg.
 *
 * Aufruf per Cronjob, sinnvoll im Minutentakt:
 *     * * * * * /usr/bin/php ...trigger_ff.php >/dev/null 2>&1
 *  
 * Das Skript ist absichtlich genügsam: es liest nur das Ende jeder Datei.
 * Es hält sich mit einer Sperrdatei selbst davon ab, sich bei langen Läufen zu überholen.
 * Es startet nur weitere Hintergrundprozesse, wenn es abhängige Thumbnails von den 
 * fertiggestellten Dateien gibt.
 */
use Camindo\Core\cron\TriggerFFmpeg;

// --------------------

// Nur auf der Kommandozeile erreichbar, nicht per Webserver
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

// --------------------

define ('CAMINDO_STARTED', true);
define ('CAMINDO_CLI', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

// Container bereitstellen
$app = $GLOBALS['app'];
$triggerFFmpeg = $app->get(TriggerFFmpeg::class);
$triggerFFmpeg->check();