<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Admin\GroupBackend;

$groupBackend = $app->get(GroupBackend::class);
$groupBackend->info();
