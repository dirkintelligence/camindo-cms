<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Start\StartCspReport;

$app->get(StartCspReport::class)->report();
