<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordBackendImport;

$recordBackendImport = $app->get(RecordBackendImport::class);
$recordBackendImport->import();
