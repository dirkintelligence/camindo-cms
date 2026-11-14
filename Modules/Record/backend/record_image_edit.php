<?php
// @var Camindo\Core\ContainerInterface $app
// Layer "Image editing" (upload field) / "Define section" (thumbnail field) of the info page
use Camindo\Core\Modules\Record\RecordBackendImageEdit;

$app->get(RecordBackendImageEdit::class)->open();
