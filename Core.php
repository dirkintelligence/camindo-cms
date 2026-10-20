<?php
//Bootstrap File

if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    die('Error: PHP 8.3 or later is required.');
}

// SemVer, identical to the Git tag of the release (developer docs, chapter 09 (Installation and update)).
// If the version stamp in var/config/core.php differs from it, `upgrade` is pending.
define('CAMINDO_VERSION', '9.1.0');

// Composer Autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Container + Interface
// Dependency injection container with autowiring and shared services (singletons) 
use Camindo\Core\Container;

// Classes that cannot be resolved automatically
use Camindo\Core\Config;

// 1. Initialize Container
$container = new Container();

// 2. Register services
// Classes that cannot be resolved automatically
$container->set(Config::class, function() use ($_cfg) {
    return new Config($_cfg);
});

$_cfg = null; 
unset($_cfg);

// 3. Initialize backend services (if needed)
if (defined('CAMINDO_BACKEND') && CAMINDO_BACKEND === true) {
    // Classes that cannot be resolved automatically
    // ...
}

// Make Container available
$GLOBALS['app'] = $container;

// 4. Fixed backend domain: backend entry points under another domain -> 404
if (defined('CAMINDO_BACKEND') && CAMINDO_BACKEND === true) {
    $container->get(\Camindo\Core\BackendDomain::class)->enforce();
}

// 5. Security headers (clickjacking, nosniff, referrer, HSTS) sent before any output
$container->get(\Camindo\Core\SecurityHeaders::class)->send();
