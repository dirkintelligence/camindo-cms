<?php
//Bootstrap File

if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    die('Error: PHP 8.3 or later is required.');
}

// SemVer, identisch mit dem Git-Tag des Releases (docs/developers/14-installation-und-update.md, 2.1).
// Weicht der Versionsstempel var/config/core.php davon ab, steht `upgrade` aus.
define('CAMINDO_VERSION', '9.0.1');

// Composer Autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Container + Interface
// Dependency Injection Container mit Autowiring und Shared Services (Singletons) 
use Camindo\Core\Container;

// Klassen, die nicht automatisch aufgelöst werden können
use Camindo\Core\Config;

// 1. Initialize Container
$container = new Container();

// 2. Registriere Services
// Klassen die nicht automatisch aufgelöst werden können
$container->set(Config::class, function() use ($_cfg) {
    return new Config($_cfg);
});

$_cfg = null; 
unset($_cfg);

// 3. Backend Services Initialisierung (falls benötigt)
if (defined('CAMINDO_BACKEND') && CAMINDO_BACKEND === true) {
    // Klassen die nicht automatisch aufgelöst werden können
    // ...
}

// Make Container available
$GLOBALS['app'] = $container;

// 4. Feste Backend-Domain: Backend-Einstiege unter einer anderen Domain -> 404
if (defined('CAMINDO_BACKEND') && CAMINDO_BACKEND === true) {
    $container->get(\Camindo\Core\BackendDomain::class)->enforce();
}

// 5. Sicherheits-Header (Clickjacking, nosniff, Referrer, HSTS) vor jeder Ausgabe
$container->get(\Camindo\Core\SecurityHeaders::class)->send();
