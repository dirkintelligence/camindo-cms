<?php
// @var Camindo\Core\ContainerInterface $app
// Image editing: preview, save, cancel (POST), temporary preview (GET)
use Camindo\Core\Modules\Record\RecordBackendImageEdit;

$app->get(RecordBackendImageEdit::class)->ajax();
