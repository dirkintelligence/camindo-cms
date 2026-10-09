<?php
// @var Camindo\Core\ContainerInterface $app
// Deliver the zip file of an export from var/export (link on the result page
// of record_export.php, opens in a new window)
use Camindo\Core\Modules\Record\RecordBackendExport;

$recordBackendExport = $app->get(RecordBackendExport::class);
$recordBackendExport->download();
