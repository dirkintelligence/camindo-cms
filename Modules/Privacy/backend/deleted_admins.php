<?php
// @var Camindo\Core\ContainerInterface $app
// Gelöschte Benutzer (Modul Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->deletedAdmins();
