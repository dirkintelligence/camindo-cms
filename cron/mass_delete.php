<?php
/**
 * mass_delete.php - mass deletion / restoration in the background
 *
 * NOT called by a cron job, but started by Camindo\Core\MassDeletion
 * when an operation in the backend covers more records than admin.mass_delete_threshold.
 *
 * The script calls exactly the same Dataset method that the web request
 * would use:
 *
 *     mode=delete   ->  $dataset->delete($id, $withChilds)     Soft-Delete
 *     mode=restore  ->  $dataset->restore($id, $withChilds)    Wiederherstellen
 *     mode=purge    ->  $dataset->purge($id, $withChilds)      delete permanently
 *
 * So there is no second deletion path - the background process only moves the work
 * out of the request. Every affected record runs individually through its
 * hooks, exactly as in the foreground.
 *
 * Arguments (all required except --token):
 *     --table=area|record     table of the clicked record
 *     --id=123                its ID
 *     --mode=delete|restore|purge
 *     --withchilds=0|1        include child records
 *     --admin=10              ID of the triggering administrator (ends up in changed_by)
 *     --token=...             token of the bgproc entry, if present
 *
 * Feedback to the backend runs via the table `bgproc` (progress display in the
 * header). Errors also end up in var/log/{Y-m-d}_massdelete-errors.log.
 */
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Record\Record;

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

$table      = (string)($args['table'] ?? '');
$id         = (int)($args['id'] ?? 0);
$mode       = (string)($args['mode'] ?? '');
$withChilds = (bool)(int)($args['withchilds'] ?? 0);
$adminId    = (int)($args['admin'] ?? 0);
$token      = (string)($args['token'] ?? '');

if (!in_array($table, ['area', 'record'], true)
    || !in_array($mode, ['delete', 'restore', 'purge'], true)
    || $id < 1
) {
    exit("usage: mass_delete.php --table=area|record --id=N --mode=delete|restore|purge --withchilds=0|1 --admin=N\n");
}

// Provide the container
$app = $GLOBALS['app'];

$system = $app->get(System::class);
$cms    = $app->get(Cms::class);
$bgproc = $app->get(BackgroundProcess::class);

// Store the triggering administrator so that changed_by is correct
$app->get(Session::class)->setCliAdmin($adminId, withAccess: true);   // rights of the triggering admin

// Get the matching dataset
$dataset = ($table === 'area')
    ? $app->get(Area::class)
    : $app->get(Record::class);

$success = false;
try {
    $success = match ($mode) {
        'delete'  => $dataset->delete($id, $withChilds),
        'restore' => $dataset->restore($id, $withChilds),
        'purge'   => $dataset->purge($id, $withChilds),
    };

    if (!$success) {
        $message = "{$mode} {$table} {$id}: " . $cms->errorMsg(' | ');
        $system->log('massdelete-errors', $message);
        echo "error: {$message}\n";
    }
} catch (\Throwable $e) {
    $system->log('massdelete-errors', "{$mode} {$table} {$id}: " . $e->getMessage());
    echo "exception: " . $e->getMessage() . "\n";
} finally {
    // Close own bgproc entry so that the display in the backend cleans up
    if ($token !== '') {
        $bgproc->setStatusByToken(
            $token,
            $success ? BackgroundProcess::STATUS_FINISHED : BackgroundProcess::STATUS_FAILED
        );
    }
}

echo $success ? "done.\n" : "failed.\n";
exit($success ? 0 : 1);
