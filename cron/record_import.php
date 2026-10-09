<?php
/**
 * record_import.php - Import von Datensätzen im Hintergrund
 *
 * Wird NICHT per Cronjob aufgerufen, sondern von RecordImport::start(), wenn ein
 * Import mehr Datensätze umfasst als admin.import_background_threshold - oder der
 * Redakteur den Hintergrund gewählt hat.
 *
 * Das Skript ruft exakt dieselbe Methode auf wie der Web-Request:
 * RecordImport::run(). Der Auftrag liegt als JSON-Datei in var/tmp
 * (RecordImport::start()), sie wird beim Lesen entfernt. Am Ende schickt
 * RecordImport::report() dem auslösenden Admin eine interne Nachricht mit dem
 * Protokoll (Modul Messaging); das Importverzeichnis wird danach entfernt.
 *
 * Argumente:
 *     --job=/pfad/var/tmp/import-job-{token}.json   Auftragsdatei (Pflicht)
 *     --token=...                               Token des bgproc-Eintrags
 */
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\Modules\Record\RecordImport;

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
    exit("usage: record_import.php --job=/path/to/import-job-{token}.json --token=...\n");
}

// Container bereitstellen
$app = $GLOBALS['app'];
$system = $app->get(System::class);
$bgproc = $app->get(BackgroundProcess::class);
$import = $app->get(RecordImport::class);

$success = false;
try {
    $job = $import->loadJob($jobFile);
    if ($job === null) {
        throw new \RuntimeException("job file not readable: {$jobFile}");
    }
    // Auslösenden Administrator hinterlegen: changed_by der Datensätze, Empfänger
    // der Nachricht - und seine Backend-Sprache/Zeitzone für die Texte des Protokolls
    $adminId = (int)($job['admin_id'] ?? 0);
    $app->get(Session::class)->setCliAdmin($adminId, withAccess: true);   // Rechte des auslösenden Admins
    if ($adminData = $app->get(Admin::class)->load($adminId)) {
        $app->get(Cms::class)->applyAdminSettings((array)($adminData['settings'] ?? []));
    }

    $result = $import->run($job, function (int $done, int $total) use ($bgproc, $token) {
        if ($token === '') return;
        $bgproc->setProgressByToken($token, $total > 0 ? (int)floor($done * 100 / $total) : 100, "{$done}/{$total}");
    });
    $import->removeDir((string)($job['dir'] ?? ''));
    $subject = $import->report($job, $result);
    if (!empty($result['error'])) {
        throw new \RuntimeException((string)$result['error']);
    }
    echo $subject . "\n" . $import->summaryText($job, $result) . "\n";
    $success = true;
} catch (\Throwable $e) {
    $system->log('import-errors', "record_import {$token}: " . $e->getMessage());
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
