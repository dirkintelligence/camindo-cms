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

// Wartungsmodus / Datenbank nicht auf dem Stand der Dateien: nur Superuser dürfen
// weiter (Hinweisbalken in index.php); alle anderen sehen die Wartungsseite.
// Ohne Session weiter zum Login - erst danach ist klar, wer fragt.
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

// Angeforderte Backend-Seite laden: backendModules/{Modul}/{script} bzw.
// backendExtensions/{Extension}/{script} (Ajax-Endpunkte von Extensions, 13 §3.6)
if (isset($_REQUEST['_ext'])) {
    require_once $backend->backendPath((string)$_REQUEST['_ext'], 'extensions');
}
else {
    require_once $backend->backendPath((string)($_REQUEST['_path'] ?? ''));
}

