<?php
/**
 * template_sync.php - Quelltext-Dateien der Templates in die Datenbank übernehmen
 * (var/templates/{instance_id}/{bereich}/{name}.cms.php, siehe TemplateFiles).
 *
 * Wird von TriggerCron::tick1m() jede Minute als eigener Prozess gestartet; manuell:
 *     php template_sync.php
 *
 * @see Camindo\Core\Modules\Template\TemplateFiles
 */
use Camindo\Core\Modules\Template\TemplateFiles;

// Nur auf der Kommandozeile erreichbar, nicht per Webserver
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

set_time_limit(0);
ignore_user_abort(true);

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

$app = $GLOBALS['app'];
$result = $app->get(TemplateFiles::class)->sync();
if ($result['locked']) {
    echo "skipped (sync already running)\n";
    exit(0);
}
echo "areas created: {$result['areas_created']}, deleted: {$result['areas_deleted']};"
    . " templates created: {$result['templates_created']}, updated: {$result['templates_updated']},"
    . " deleted: {$result['templates_deleted']}\n";
foreach ($result['errors'] as $error) echo "Error: $error\n";
exit(0);
