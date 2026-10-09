<?php
// @var Camindo\Core\ContainerInterface $app
// Deliver and delete the zip of a data export from var/export (module Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->download();
