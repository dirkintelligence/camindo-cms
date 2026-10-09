<?php
// @var Camindo\Core\ContainerInterface $app
// Zip eines Datenexports aus var/export ausliefern und löschen (Modul Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->download();
