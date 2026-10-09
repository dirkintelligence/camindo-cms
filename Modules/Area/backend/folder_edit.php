<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Area\AreaBackend;

$adminBackend = $app->get(AreaBackend::class);
$adminBackend->folderEdit();
