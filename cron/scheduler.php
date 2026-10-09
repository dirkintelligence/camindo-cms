<?php
/**
 * scheduler.php - time-controlled tasks (module Scheduler)
 *
 *     php scheduler.php                 start due tasks (each as its own
 *                                       background process) - calls TriggerCron::tick1m()
 *                                       every minute
 *     php scheduler.php --id=N          run ONE task now
 *     php scheduler.php --id=N --token=T   same, started from the backend: the
 *                                       bgproc entry with this token is closed
 *
 * @see Camindo\Core\Modules\Scheduler\SchedulerRunner
 */
use Camindo\Core\Modules\Scheduler\SchedulerRunner;

// Reachable only on the command line, not via the web server
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

set_time_limit(0);
ignore_user_abort(true);

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Load bootstrap
require_once dirname(__DIR__, 2) . '/load_camindo.php';

// Maintenance mode / database not in step with the files: no background jobs
// on a half-finished schema (SystemState)
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
