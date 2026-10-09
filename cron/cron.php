<?php
/**
 * cron.php
 *
 *
 * Call via cron job, sensibly every minute:
 *     * * * * * /usr/local/bin/php /var/www/html/camindo/Core/cron/cron.php >/dev/null 2>&1
 */
use Camindo\Core\cron\TriggerCron;

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

// Maintenance mode / database not in step with the files: no background jobs
// on a half-finished schema (SystemState)
$state = $GLOBALS['app']->get(\Camindo\Core\SystemState::class);
if (($reason = $state->blockReason()) !== null) {
    exit('skipped: ' . $state->blockMessage($reason) . "\n");
}

// Provide the container
$app = $GLOBALS['app'];
$triggerCron = $app->get(TriggerCron::class);
$triggerCron->run();
