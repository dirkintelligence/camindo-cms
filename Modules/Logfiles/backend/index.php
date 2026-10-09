<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Logfiles\LogfilesBackend;

$app->get(LogfilesBackend::class)->list();
