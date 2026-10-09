<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordBackendServerFile;

$recordBackendServerFile = $app->get(RecordBackendServerFile::class);
$recordBackendServerFile->serverFileSelect();
