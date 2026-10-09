<?php
// -- Skelett camindo/project: liegt in der Projektwurzel neben composer.json --
// Keine $_SERVER-Variablen verwenden!

// Konfiguration dieser Installation. Fehlt sie, ist camindo nicht installiert:
// Frontend und Backend antworten 503, `php Core/cron/camindo.php install` legt sie an.
$_cfg = [];
if (is_file(__DIR__ . '/.camindo.php')) {
    require_once __DIR__ . '/.camindo.php';
}
require_once __DIR__ . '/Core/Core.php';
