<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Start\StartBackend;

$startBackend = $app->get(StartBackend::class);
$startBackend->login();
