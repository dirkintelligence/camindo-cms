<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Area\FieldConvertBackend;

$fieldConvertBackend = $app->get(FieldConvertBackend::class);
$fieldConvertBackend->convert();
