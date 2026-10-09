<?php
// Kompilierte Frontend-Templates neu schreiben (var/php/{area_id}/{name}.php) und
// die Quelltext-Dateien abgleichen (var/templates/{instance_id}/{bereich}/{name}.cms.php).
// Beides liest die Dateien in var/templates - nie die Spalte `template`.`code`.
// Normalerweise nicht nötig: Template::hookOnSaved() schreibt beide Dateien bei jedem
// Speichern, TemplateFiles::sync() läuft minütlich über Core/cron. Gedacht für die
// Umstellung des Bestands und für den Fall, dass Dateien von Hand verändert wurden.
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
