<?php
use Camindo\Core\Modules\Record\RecordBackendServerFile;

define ('CAMINDO_STARTED', true);
define ('CAMINDO_BACKEND', true);

// Bootstrap laden
require_once dirname(__DIR__, 3) . '/load_camindo.php';

// Container laden
$app = $GLOBALS['app'];

// Klasse laden und Methode starten
$recordBackendServerFile = $app->get(RecordBackendServerFile::class);
$recordBackendServerFile->filePreview();

