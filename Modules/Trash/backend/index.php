<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Trash\TrashBackend;

$app->get(TrashBackend::class)->list();
