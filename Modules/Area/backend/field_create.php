<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Area\FieldCreateBackend;

$FieldCreateBackend = $app->get(FieldCreateBackend::class);
$FieldCreateBackend->create();
