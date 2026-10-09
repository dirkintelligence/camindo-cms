<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Converter\ConverterBackend;

$converterBackend = $app->get(ConverterBackend::class);
$converterBackend->chooseProcessor();

