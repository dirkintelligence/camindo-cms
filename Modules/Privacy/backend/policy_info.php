<?php
// @var Camindo\Core\ContainerInterface $app
// Info page of a deletion policy (module Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->info();
