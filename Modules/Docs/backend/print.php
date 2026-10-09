<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Docs\DocsBackend;

$app->get(DocsBackend::class)->printBook();
