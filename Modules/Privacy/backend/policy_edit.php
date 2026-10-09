<?php
// @var Camindo\Core\ContainerInterface $app
// Lösch-Policy anlegen, ändern, löschen (Modul Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->edit();
