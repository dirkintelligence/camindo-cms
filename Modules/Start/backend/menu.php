<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Start\StartBackend;

$app->get(StartBackend::class)->menu();
