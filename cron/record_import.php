<?php
/**
 * record_import.php - import of records in the background
 *
 * NOT called by a cron job, but by RecordImport::start() when an
 * import covers more records than admin.import_background_threshold - or the
 * editor chose the background.
 *
 * The script calls exactly the same method as the web request:
 * RecordImport::run(). The job lies as a JSON file in var/tmp
 * (RecordImport::start()); it is removed when read. At the end
 * RecordImport::report() sends the triggering admin an internal message with the
 * protocol (module Messaging); the import directory is removed afterwards.
 *
 * Arguments:
 *     --job=/path/var/tmp/import-job-{token}.json   job file (required)
 *     --token=...                                   token of the bgproc entry
 */
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\Modules\Record\RecordImport;

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
    exit("usage: record_import.php --job=/path/to/import-job-{token}.json --token=...\n");
}

// Provide the container
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
    // Store the triggering administrator: changed_by of the records, recipient
    // of the message - and their backend language/time zone for the texts of the protocol
    $adminId = (int)($job['admin_id'] ?? 0);
    $app->get(Session::class)->setCliAdmin($adminId, withAccess: true);   // rights of the triggering admin
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
