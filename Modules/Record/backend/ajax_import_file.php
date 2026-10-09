<?php
// @var Camindo\Core\ContainerInterface $app
// Ajax: put the selected server file (var/upload) into the import mask
use Camindo\Core\Modules\Record\RecordBackendImport;

$recordBackendImport = $app->get(RecordBackendImport::class);
$recordBackendImport->serverFilePreview();
