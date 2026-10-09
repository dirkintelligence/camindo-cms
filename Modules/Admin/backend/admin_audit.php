<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Admin\AdminBackend;

$adminBackend = $app->get(AdminBackend::class);
$adminBackend->audit();
