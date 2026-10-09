<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Messaging\MessagingBackend;

$messagingBackend = $app->get(MessagingBackend::class);
$messagingBackend->edit();
