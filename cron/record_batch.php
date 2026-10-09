<?php
/**
 * record_batch.php - batch processing of records in the background
 *
 * NOT called by a cron job, but by RecordBatch::start() when a
 * job in the backend covers more records than admin.mass_delete_threshold - or
 * the editor chose the background.
 *
 * The script calls exactly the same method as the web request:
 * RecordBatch::run(). There is no second processing path - the background only
 * moves the work out of the request. The job lies as a JSON file
 * in var/tmp (RecordBatch::start()); it is removed when read.
 *
 * Progress to the backend via the table `bgproc`; at the end
 * RecordBatch::report() writes the result to var/log/{Y-m-d}_batch-{token}.log and
 * sends the triggering admin an internal message (module Messaging).
 *
 * Arguments:
 *     --job=/path/var/tmp/batch-{token}.json   job file (required)
 *     --token=...                              token of the bgproc entry
 */
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\Modules\Record\RecordBatch;

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
    exit("usage: record_batch.php --job=/path/to/batch-{token}.json --token=...\n");
}

// Provide the container
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
    // Store the triggering administrator: changed_by of the records, sender and
    // recipient of the result message - and their backend language/time zone, so that
    // messages and dates in the report look the same as in their backend
    $adminId = (int)($job['admin_id'] ?? 0);
    $app->get(Session::class)->setCliAdmin($adminId, withAccess: true);   // rights of the triggering admin
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
