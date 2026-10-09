<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Converter\ConverterBackend;
use Camindo\Core\Modules\Converter\ProcessorFactory;

$converterBackend = $app->get(ConverterBackend::class);
$converterBackend->edit();
