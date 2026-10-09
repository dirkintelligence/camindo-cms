<?php
// @var Camindo\Core\ContainerInterface $app
// Deleted users (module Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->deletedAdmins();
