<?php
/**
 * record_export.php - Export von Datensätzen im Hintergrund
 *
 * Wird NICHT per Cronjob aufgerufen, sondern von RecordExport::start(), wenn ein
 * Export mehr Datensätze umfasst als admin.export_background_threshold - oder der
 * Redakteur den Hintergrund gewählt hat.
 *
 * Das Skript ruft exakt dieselbe Methode auf wie der Web-Request:
 * RecordExport::run(). Der Auftrag liegt als JSON-Datei in var/tmp
 * (RecordExport::start()), sie wird beim Lesen entfernt. Am Ende schickt
 * RecordExport::report() dem auslösenden Admin eine interne Nachricht mit dem
 * Download-Link (Modul Messaging) und dem Hinweis, wie lange er gilt.
 *
 * Argumente:
 *     --job=/pfad/var/tmp/export-{token}.json   Auftragsdatei (Pflicht)
 *     --token=...                               Token des bgproc-Eintrags
 */
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\Modules\Record\RecordExport;

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
    exit("usage: record_export.php --job=/path/to/export-{token}.json --token=...\n");
}

// Container bereitstellen
$app = $GLOBALS['app'];
$system = $app->get(System::class);
$bgproc = $app->get(BackgroundProcess::class);
$export = $app->get(RecordExport::class);

$success = false;
try {
    $job = $export->loadJob($jobFile);
    if ($job === null) {
        throw new \RuntimeException("job file not readable: {$jobFile}");
    }
    // Auslösenden Administrator hinterlegen: Name im XML-Header, Empfänger der
    // Nachricht - und seine Backend-Sprache/Zeitzone für die Texte der Nachricht
    $adminId = (int)($job['admin_id'] ?? 0);
    $app->get(Session::class)->setCliAdmin($adminId, withAccess: true);   // Rechte des auslösenden Admins
    if ($adminData = $app->get(Admin::class)->load($adminId)) {
        $app->get(Cms::class)->applyAdminSettings((array)($adminData['settings'] ?? []));
    }

    $result = $export->run($job);
    $subject = $export->report($job, $result);
    if (!empty($result['error'])) {
        throw new \RuntimeException((string)$result['error']);
    }
    echo $subject . "\n" . $export->summaryText($result) . "\n" . $result['zip'] . "\n";
    $success = true;
} catch (\Throwable $e) {
    $system->log('export-errors', "record_export {$token}: " . $e->getMessage());
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
