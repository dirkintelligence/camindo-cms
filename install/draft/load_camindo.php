<?php
// -- Skeleton camindo/project: is located in the project root next to composer.json --
// Keine $_SERVER-Variablen verwenden!

// configuration of this installation. If it is missing, camindo is not installed:
// frontend and backend respond with 503, `php Core/cron/camindo.php install` creates it.
$_cfg = [];
if (is_file(__DIR__ . '/.camindo.php')) {
    require_once __DIR__ . '/.camindo.php';
}
require_once __DIR__ . '/Core/Core.php';
