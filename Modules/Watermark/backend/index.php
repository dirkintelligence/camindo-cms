<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Watermark\WatermarkBackend;

$app->get(WatermarkBackend::class)->index();
