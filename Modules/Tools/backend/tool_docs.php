<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Tools\ToolDeveloper;

// Rebuilds files - POST only (developer docs, chapter 14 (Security: web))
if (!$app->get(Camindo\Core\Backend::class)->requirePost()) $app->get(Camindo\Core\Access::class)->denied();

$app->get(ToolDeveloper::class)->docsIndex();
