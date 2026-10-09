<?php
/**
 * Auslieferung geschützter und herunterzuladender Mediendateien im Frontend.
 *
 *     /file.php?file={field_id}/{record_id}[/{language_id}][&download={name}]
 *
 * CMS:MEDIA verlinkt hierher, wenn das Feld als "nur für angemeldete User"
 * markiert ist (field.details.user_auth) oder ein Download-Name gewünscht ist.
 * Die Prüfungen stehen in Camindo\Core\MediaDelivery.
 */
use Camindo\Core\MediaDelivery;
use Camindo\Core\SystemState;

define ('CAMINDO_STARTED', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

// Wartungsmodus / Datenbank nicht auf dem Stand der Dateien: 503, ohne DB-Zugriff
$app->get(SystemState::class)->guardFrontend();

$app->get(MediaDelivery::class)->deliver();
