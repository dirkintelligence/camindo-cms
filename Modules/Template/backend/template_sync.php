<?php
// Refresh button of the template lists: first reconcile var/templates with the database
// (TemplateFiles::sync()), then the JavaScript reloads the view.
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Template\TemplateFiles;

// Reconcile all template areas - superuser only (developer docs, chapter 11 (Permissions)).
// sync() writes, so only via POST (developer docs, chapter 14 (Security: web)).
$backend = $app->get(\Camindo\Core\Backend::class);
if (!$backend->isSuperuser() || !$backend->requirePost()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'access denied']);
    return;
}
$result = $app->get(TemplateFiles::class)->sync();
header('Content-Type: application/json');
echo json_encode($result);
