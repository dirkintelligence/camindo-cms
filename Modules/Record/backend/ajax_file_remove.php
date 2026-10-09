<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordBackendUpload;

$recordBackendUpload = $app->get(RecordBackendUpload::class);
$recordBackendUpload->fileRemove();
