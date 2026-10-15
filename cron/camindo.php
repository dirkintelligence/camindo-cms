<?php
/**
 * camindo.php - command line of the CMS
 *
 *     php Core/cron/camindo.php install [--no-interaction --fixtures ...]
 *     php Core/cron/camindo.php status
 *     php Core/cron/camindo.php upgrade
 *     php Core/cron/camindo.php maintenance on|off|status
 *     php Core/cron/camindo.php backup | backup prune --keep=5
 *     php Core/cron/camindo.php schema export
 *     php Core/cron/camindo.php cache snippets [--prefix=… | --remove=…] | list
 *     php Core/cron/camindo.php cache records [--instance=ID] [--area=ID,…]
 *     php Core/cron/camindo.php component list | install NAME | activate NAME | deactivate NAME
 *                                        | update NAME | uninstall NAME [--purge]
 *     php Core/cron/camindo.php refresh [components]
 *     php Core/cron/camindo.php refresh snippets | html [--prefix=…] | records [--instance=ID] [--area=ID,…]
 *     php Core/cron/camindo.php htaccess [status] | renew
 *     php Core/cron/camindo.php api login --admin=NAME | call METHOD '{…}' --session=ID | logout
 *
 * Commands: Camindo\Core\Cli\Cli (dispatcher) and Camindo\Core\Cli\{Name}Command.
 * Runs without session (CAMINDO_CLI); background jobs check the maintenance mode,
 * this entry point does not - that is the reason for it.
 *
 * @see developer docs, chapter 09 (Installation and update)
 */
use Camindo\Core\Cli\Cli;

// Reachable only on the command line, not via the web server
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

set_time_limit(0);
define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Load bootstrap
require_once dirname(__DIR__, 2) . '/load_camindo.php';

exit((new Cli($GLOBALS['app']))->run($argv));
