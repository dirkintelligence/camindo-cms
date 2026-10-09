<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Audit\AuditBackend;

$app->get(AuditBackend::class)->list();
