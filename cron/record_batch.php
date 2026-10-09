<?php
/**
 * record_batch.php - Stapelverarbeitung von Datensätzen im Hintergrund
 *
 * Wird NICHT per Cronjob aufgerufen, sondern von RecordBatch::start(), wenn ein
 * Auftrag im Backend mehr Datensätze umfasst als admin.mass_delete_threshold - oder
 * der Redakteur den Hintergrund gewählt hat.
 *
 * Das Skript ruft exakt dieselbe Methode auf wie der Web-Request:
 * RecordBatch::run(). Es gibt keinen zweiten Verarbeitungspfad - der Hintergrund
 * verlagert die Arbeit nur aus dem Request heraus. Der Auftrag liegt als JSON-Datei
 * in var/tmp (RecordBatch::start()), sie wird beim Lesen entfernt.
 *
 * Fortschritt an das Backend über die Tabelle `bgproc`; am Ende schreibt
 * RecordBatch::report() das Ergebnis nach var/log/{Y-m-d}_batch-{token}.log und
 * schickt dem auslösenden Admin eine interne Nachricht (Modul Messaging).
 *
 * Argumente:
 *     --job=/pfad/var/tmp/batch-{token}.json   Auftragsdatei (Pflicht)
 *     --token=...                              Token des bgproc-Eintrags
 */
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\Modules\Record\RecordBatch;

// Nur auf der Kommandozeile erreichbar, nicht per Webserver
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

// --------------------

set_time_limit(0);
ignore_user_abort(true);

define ('CAMINDO_STARTED', true);
define ('CAMINDO_CLI', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

// --------------------
// Argumente einlesen (--name=wert)

$args = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!preg_match('/^--([a-z_]+)=(.*)$/', $argument, $matches)) continue;
    $args[$matches[1]] = $matches[2];
}
$jobFile = (string)($args['job'] ?? '');
$token   = (string)($args['token'] ?? '');
if ($jobFile === '') {
    exit("usage: record_batch.php --job=/path/to/batch-{token}.json --token=...\n");
}

// Container bereitstellen
$app = $GLOBALS['app'];
$system = $app->get(System::class);
$bgproc = $app->get(BackgroundProcess::class);
$batch  = $app->get(RecordBatch::class);

$success = false;
try {
    $job = $batch->loadJob($jobFile);
    if ($job === null) {
        throw new \RuntimeException("job file not readable: {$jobFile}");
    }
    // Auslösenden Administrator hinterlegen: changed_by der Datensätze, Absender und
    // Empfänger der Ergebnis-Nachricht - und seine Backend-Sprache/Zeitzone, damit
    // Meldungen und Datumsangaben im Bericht so aussehen wie in seinem Backend
    $adminId = (int)($job['admin_id'] ?? 0);
    $app->get(Session::class)->setCliAdmin($adminId, withAccess: true);   // Rechte des auslösenden Admins
    if ($adminData = $app->get(Admin::class)->load($adminId)) {
        $app->get(Cms::class)->applyAdminSettings((array)($adminData['settings'] ?? []));
    }

    $result = $batch->run($job, function (int $done, int $total) use ($bgproc, $token) {
        if ($token === '') return;
        $bgproc->setProgressByToken($token, $total > 0 ? (int)floor($done * 100 / $total) : 100, "{$done}/{$total}");
    });
    $logFile = $batch->report($job, $result);
    echo $batch->summaryText($job, $result) . "\n" . $logFile . "\n";
    $success = true;
} catch (\Throwable $e) {
    $system->log('batch-errors', "record_batch {$token}: " . $e->getMessage());
    echo "exception: " . $e->getMessage() . "\n";
} finally {
    if ($token !== '') {
        $bgproc->setStatusByToken(
            $token,
            $success ? BackgroundProcess::STATUS_FINISHED : BackgroundProcess::STATUS_FAILED
        );
    }
}

echo $success ? "done.\n" : "failed.\n";
exit($success ? 0 : 1);
