<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordBackendBatch;

$recordBackendBatch = $app->get(RecordBackendBatch::class);
$recordBackendBatch->batch();
