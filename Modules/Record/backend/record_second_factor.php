<?php
// @var Camindo\Core\ContainerInterface $app
// Zweiter Faktor eines Profil-Datensatzes anzeigen und zurücksetzen (UserSecondFactor)
use Camindo\Core\Modules\Record\RecordBackendInfo;

$app->get(RecordBackendInfo::class)->secondFactor();
