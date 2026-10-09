<?php
/**
 * camindo.php - Kommandozeile des CMS
 *
 *     php Core/cron/camindo.php install [--no-interaction --fixtures ...]
 *     php Core/cron/camindo.php status
 *     php Core/cron/camindo.php upgrade
 *     php Core/cron/camindo.php maintenance on|off|status
 *     php Core/cron/camindo.php backup | backup prune --keep=5
 *     php Core/cron/camindo.php schema export
 *     php Core/cron/camindo.php cache snippets [--prefix=… | --remove=…] | list
 *     php Core/cron/camindo.php component list | install NAME | activate NAME | deactivate NAME
 *                                        | update NAME | uninstall NAME [--purge]
 *     php Core/cron/camindo.php refresh [components]
 *     php Core/cron/camindo.php refresh snippets | html [--prefix=…]
 *     php Core/cron/camindo.php api login --admin=NAME | call METHOD '{…}' --session=ID | logout
 *
 * Befehle: Camindo\Core\Cli\Cli (Dispatcher) und Camindo\Core\Cli\{Name}Command.
 * Läuft ohne Session (CAMINDO_CLI); Hintergrundjobs prüfen den Wartungsmodus,
 * dieser Einstieg nicht - er ist der Grund dafür.
 *
 * @see docs/developers/14-installation-und-update.md, Kapitel 8
 */
use Camindo\Core\Cli\Cli;

// Nur auf der Kommandozeile erreichbar, nicht per Webserver
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

set_time_limit(0);
define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

exit((new Cli($GLOBALS['app']))->run($argv));
