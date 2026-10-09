<?php
use Camindo\Core\Modules\Tools\ToolHtmlTemplates;

$tool = $app->get(ToolHtmlTemplates::class);
$tool->buildTemplateCache();