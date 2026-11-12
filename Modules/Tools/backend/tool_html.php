<?php
use Camindo\Core\Modules\Tools\ToolHtmlTemplates;

// Rebuilds files - POST only (developer docs, chapter 14 (Security: web))
if (!$app->get(Camindo\Core\Backend::class)->requirePost()) $app->get(Camindo\Core\Access::class)->denied();

$tool = $app->get(ToolHtmlTemplates::class);
$tool->buildTemplateCache();