<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Area\FieldAjaxBackend;

$FieldAjaxBackend = $app->get(FieldAjaxBackend::class);
$FieldAjaxBackend->updateVisibility();
