<?php
/**
 * template_sync.php - take the source files of the templates into the database
 * (var/templates/{instance_id}/{area}/{name}.cms.php, see TemplateFiles).
 *
 * Started every minute as its own process by TriggerCron::tick1m(); manually:
 *     php template_sync.php
 *
 * @see Camindo\Core\Modules\Template\TemplateFiles
 */
use Camindo\Core\Modules\Template\TemplateFiles;

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
