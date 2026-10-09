<?php
/**
 * cron.php
 *
 *
 * Aufruf per Cronjob, sinnvoll im Minutentakt:
 *     * * * * * /usr/local/bin/php /var/www/html/camindo/Core/cron/cron.php >/dev/null 2>&1
 */
use Camindo\Core\cron\TriggerCron;

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

// Wartungsmodus / Datenbank nicht auf dem Stand der Dateien: keine Hintergrundjobs
// auf halbem Schema (SystemState)
$state = $GLOBALS['app']->get(\Camindo\Core\SystemState::class);
if (($reason = $state->blockReason()) !== null) {
    exit('skipped: ' . $state->blockMessage($reason) . "\n");
}

// Container bereitstellen
$app = $GLOBALS['app'];
$triggerCron = $app->get(TriggerCron::class);
$triggerCron->run();
