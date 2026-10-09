<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Status\StatusBackend;

$statusBackend = $app->get(StatusBackend::class);
$statusBackend->edit();

