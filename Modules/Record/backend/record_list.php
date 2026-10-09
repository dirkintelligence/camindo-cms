<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordBackendList;

$recordBackendList = $app->get(RecordBackendList::class);
$recordBackendList->list();
