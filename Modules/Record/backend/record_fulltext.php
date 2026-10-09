<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordBackendInfo;

$app->get(RecordBackendInfo::class)->fulltext();
