<?php
// @var Camindo\Core\ContainerInterface $app
// Show and reset the second factor of a profile record (UserSecondFactor)
use Camindo\Core\Modules\Record\RecordBackendInfo;

$app->get(RecordBackendInfo::class)->secondFactor();
