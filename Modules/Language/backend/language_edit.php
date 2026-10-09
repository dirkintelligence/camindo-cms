<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Language\LanguageBackend;

$languageBackend = $app->get(LanguageBackend::class);
$languageBackend->edit();

