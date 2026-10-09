<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordBackendMove;

$recordBackendMove = $app->get(RecordBackendMove::class);
$recordBackendMove->move();
