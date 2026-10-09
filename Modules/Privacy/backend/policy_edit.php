<?php
// @var Camindo\Core\ContainerInterface $app
// Create, change, delete deletion policy (module Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->edit();
