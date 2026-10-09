<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordBackendMediaImport;

$recordBackendMediaImport = $app->get(RecordBackendMediaImport::class);
$recordBackendMediaImport->import();
