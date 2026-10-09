<?php
use Camindo\Core\Modules\Tools\ToolServer;

$toolServer = $app->get(ToolServer::class);
$toolServer->mysqlInfo();
