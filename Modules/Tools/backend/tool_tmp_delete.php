<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Tools\ToolsBackend;

$toolsBackend = $app->get(ToolsBackend::class);
$toolsBackend->tmpPurge();
