<?php
/**
 * record_export.php - export of records in the background
 *
 * NOT called by a cron job, but by RecordExport::start() when an
 * export covers more records than admin.export_background_threshold - or the
 * editor chose the background.
 *
 * The script calls exactly the same method as the web request:
 * RecordExport::run(). The job lies as a JSON file in var/tmp
 * (RecordExport::start()); it is removed when read. At the end
 * RecordExport::report() sends the triggering admin an internal message with the
 * download link (module Messaging) and the note how long it is valid.
 *
 * Arguments:
 *     --job=/path/var/tmp/export-{token}.json   job file (required)
 *     --token=...                               token of the bgproc entry
 */
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\Modules\Record\RecordExport;

// Reachable only on the command line, not via the web server
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

// --------------------

set_time_limit(0);
ignore_user_abort(true);

define ('CAMINDO_STARTED', true);
define ('CAMINDO_CLI', true);

// Load bootstrap
require_once dirname(__DIR__, 2) . '/load_camindo.php';

// --------------------
// Read arguments (--name=value)

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

// Provide the container
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
    // Store the triggering administrator: name in the XML header, recipient of the
    // message - and their backend language/time zone for the texts of the message
    $adminId = (int)($job['admin_id'] ?? 0);
    $app->get(Session::class)->setCliAdmin($adminId, withAccess: true);   // rights of the triggering admin
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
