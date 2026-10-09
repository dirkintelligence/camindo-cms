<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordBackendExport;

$recordBackendExport = $app->get(RecordBackendExport::class);
$recordBackendExport->export();
