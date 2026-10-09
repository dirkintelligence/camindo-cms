<?php
// @var Camindo\Core\ContainerInterface $app
// Deletion policies of the content areas (module Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->list();
