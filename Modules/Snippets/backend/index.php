<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Snippets\SnippetsBackend;

$app->get(SnippetsBackend::class)->list();
