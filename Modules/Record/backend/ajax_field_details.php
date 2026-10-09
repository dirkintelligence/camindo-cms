<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordBackendInfo;

$recordBackendInfo = $app->get(RecordBackendInfo::class);
$recordBackendInfo->fieldDetails();
