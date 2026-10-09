<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Area\FieldInfoBackend;

$FieldInfoBackend = $app->get(FieldInfoBackend::class);
$FieldInfoBackend->info();

