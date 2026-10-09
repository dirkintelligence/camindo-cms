<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Tools\ToolDeveloper;

$app->get(ToolDeveloper::class)->docsIndex();
