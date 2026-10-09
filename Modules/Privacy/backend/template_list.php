<?php
// @var Camindo\Core\ContainerInterface $app
// Templates of the data disclosure per instance and language (module Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->templateList();
