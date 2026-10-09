<?php
use Camindo\Core\Backend;
use Camindo\Core\Session;
use Camindo\Core\SystemState;

define ('CAMINDO_STARTED', true);
define ('CAMINDO_BACKEND', true);

// Bootstrap laden
require_once dirname(__DIR__, 3) . '/load_camindo.php';

// Container laden
$app = $GLOBALS['app'];

// Maintenance mode / database not at the state of the files: only superusers may
// continue (notice bar in index.php); all others see the maintenance page.
// Without a session, on to the login - only then is it clear who is asking.
$state = $app->get(SystemState::class);
$state->guardNotInstalled();
if ($state->blockReason() !== null) {
    $session = $app->get(Session::class);
    if ($session->load() && !$app->get(Backend::class)->isSuperuser()) {
        $state->sendUnavailable();
    }
}

// Klasse laden
$backend = $app->get(Backend::class);

// Load the requested backend page: backendModules/{module}/{script} or
// backendExtensions/{extension}/{script} (Ajax endpoints of extensions, developer docs, chapter 08 (Components and extensions))
if (isset($_REQUEST['_ext'])) {
    require_once $backend->backendPath((string)$_REQUEST['_ext'], 'extensions');
}
else {
    require_once $backend->backendPath((string)($_REQUEST['_path'] ?? ''));
}

