<?php
// @var Camindo\Core\ContainerInterface $app
// Infoseite einer Lösch-Policy (Modul Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->info();
