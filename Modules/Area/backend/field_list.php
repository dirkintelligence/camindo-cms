<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Area\FieldListBackend;

$FieldListBackend = $app->get(FieldListBackend::class);
$FieldListBackend->list();
