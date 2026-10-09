<?php
/**
 * scheduler.php - zeitgesteuerte Aufgaben (Modul Scheduler)
 *
 *     php scheduler.php                 fällige Aufgaben starten (je eine als eigener
 *                                       Hintergrundprozess) - ruft TriggerCron::tick1m()
 *                                       jede Minute auf
 *     php scheduler.php --id=N          EINE Aufgabe jetzt ausführen
 *     php scheduler.php --id=N --token=T   dito, aus dem Backend gestartet: der
 *                                       bgproc-Eintrag mit diesem Token wird abgeschlossen
 *
 * @see Camindo\Core\Modules\Scheduler\SchedulerRunner
 */
use Camindo\Core\Modules\Scheduler\SchedulerRunner;

// Nur auf der Kommandozeile erreichbar, nicht per Webserver
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

set_time_limit(0);
ignore_user_abort(true);

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

// Wartungsmodus / Datenbank nicht auf dem Stand der Dateien: keine Hintergrundjobs
// auf halbem Schema (SystemState)
$state = $GLOBALS['app']->get(\Camindo\Core\SystemState::class);
if (($reason = $state->blockReason()) !== null) {
    exit('skipped: ' . $state->blockMessage($reason) . "\n");
}

$options = getopt('', ['id::', 'token::']);
$id = (int)($options['id'] ?? 0);
$token = isset($options['token']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$options['token']) : null;

$app = $GLOBALS['app'];
$runner = $app->get(SchedulerRunner::class);

if ($id > 0) {
    $ok = $runner->run($id, $token !== '' ? $token : null);
    echo $ok ? "ok\n" : "failed\n";
    exit($ok ? 0 : 1);
}

$started = $runner->dispatch();
echo "started: $started\n";
exit(0);
