<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Instance\InstanceBackend;

$instanceBackend = $app->get(InstanceBackend::class);
$instanceBackend->info();

