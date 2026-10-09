<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Area\FieldEditBackend;

$FieldEditBackend = $app->get(FieldEditBackend::class);
$FieldEditBackend->edit();
