<?php
// @var Camindo\Core\ContainerInterface $app
// Export of personal data - website users and users (module Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->export();
