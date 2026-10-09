<?php
// @var Camindo\Core\ContainerInterface $app
// Gelöschten Benutzer anonymisieren (Modul Privacy, Zusatzrecht admin_anonymize)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->adminAnonymize();
