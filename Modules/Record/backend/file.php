<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordFileBackend;

$recordFileBackend = $app->get(RecordFileBackend::class);
$recordFileBackend->filePreview();
