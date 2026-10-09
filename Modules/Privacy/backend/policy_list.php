<?php
// @var Camindo\Core\ContainerInterface $app
// Lösch-Policies der Inhaltsbereiche (Modul Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->list();
