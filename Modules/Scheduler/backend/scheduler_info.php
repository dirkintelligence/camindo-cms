<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Scheduler\SchedulerBackend;

$schedulerBackend = $app->get(SchedulerBackend::class);
$schedulerBackend->info();
