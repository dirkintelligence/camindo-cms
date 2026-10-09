<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Template\TemplateBackend;

$templateBackend = $app->get(TemplateBackend::class);

$templateBackend->searchCrossArea();
