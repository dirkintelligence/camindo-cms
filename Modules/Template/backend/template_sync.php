<?php
// Refresh-Knopf der Template-Listen: erst var/templates mit der Datenbank abgleichen
// (TemplateFiles::sync()), danach lädt das Javascript die Ansicht neu.
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Template\TemplateFiles;

// Abgleich aller Template-Bereiche - nur für Superuser (12-group-rights.md).
// sync() schreibt, also nur per POST (17-csrf.md §2.7).
$backend = $app->get(\Camindo\Core\Backend::class);
if (!$backend->isSuperuser() || !$backend->requirePost()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'access denied']);
    return;
}
$result = $app->get(TemplateFiles::class)->sync();
header('Content-Type: application/json');
echo json_encode($result);
