<?php
ini_set('xdebug.var_display_max_depth', '10');
$commands = include dirname(__DIR__) . '/commands.php';
var_dump($commands);
?>