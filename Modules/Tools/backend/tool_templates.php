<?php
// Rewrite the compiled frontend templates (var/php/{area_id}/{name}.php) and
// reconcile the source files (var/templates/{instance_id}/{area}/{name}.cms.php).
// Both read the files in var/templates - never the column `template`.`code`.
// Normally not needed: Template::hookOnSaved() writes both files on every
// saving, TemplateFiles::sync() runs every minute via Core/cron. Intended for the
// conversion of the existing stock and for the case that files were edited by hand.
use Camindo\Core\Modules\Template\Template;
use Camindo\Core\Modules\Template\TemplateFiles;

$varDir = $app->get(Camindo\Core\Config::class)->get('paths.var_dir');
$count = $app->get(Template::class)->rebuildFiles();
echo "<strong>{$varDir}/php</strong><br>\n";
echo "&gt; {$count} Templates<br>\n";

$result = $app->get(TemplateFiles::class)->sync();
echo "<strong>{$varDir}/templates</strong><br>\n";
if ($result['locked']) {
    echo "&gt; sync already running<br>\n";
}
else {
    echo "&gt; areas created: {$result['areas_created']}, areas deleted: {$result['areas_deleted']},"
        . " templates created: {$result['templates_created']}, updated: {$result['templates_updated']},"
        . " deleted: {$result['templates_deleted']}<br>\n";
    foreach ($result['errors'] as $error) echo '&gt; Error: ' . htmlspecialchars($error) . "<br>\n";
}
