<?php
// @var Camindo\Core\ContainerInterface $app
// Create, change, delete the template of the data disclosure (module Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->templateEdit();
