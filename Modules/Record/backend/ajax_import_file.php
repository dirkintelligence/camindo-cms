<?php
// @var Camindo\Core\ContainerInterface $app
// Ajax: gewählte Server-Datei (var/upload) in die Import-Maske eintragen
use Camindo\Core\Modules\Record\RecordBackendImport;

$recordBackendImport = $app->get(RecordBackendImport::class);
$recordBackendImport->serverFilePreview();
