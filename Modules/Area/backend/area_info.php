<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Area\AreaBackend;

$areaBackend = $app->get(AreaBackend::class);
$areaBackend->info();
