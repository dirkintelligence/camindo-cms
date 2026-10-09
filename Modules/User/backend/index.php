<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\User\UserBackend;

$userBackend = $app->get(UserBackend::class);
$userBackend->list();

