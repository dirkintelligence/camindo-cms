<?php
use Camindo\Core\Modules\Tools\ToolInfo;

$tool = $app->get(ToolInfo::class);
$tool->debugSessionData();