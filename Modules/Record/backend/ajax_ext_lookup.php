<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordBackendEdit;

$recordBackendEdit = $app->get(RecordBackendEdit::class);
$recordBackendEdit->extLookupPreview();
