<?php
// @var Camindo\Core\ContainerInterface $app
// Datenexport personenbezogener Daten - Website-User und Benutzer (Modul Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->export();
