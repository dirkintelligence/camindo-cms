<?php
// @var Camindo\Core\ContainerInterface $app
// Anonymize a deleted user (module Privacy, additional right admin_anonymize)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->adminAnonymize();
