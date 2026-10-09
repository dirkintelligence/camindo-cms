<?php
/**
 * Delivery of protected and downloadable media files in the frontend.
 *
 *     /file.php?file={field_id}/{record_id}[/{language_id}][&download={name}]
 *
 * CMS:MEDIA links here when the field is marked as "only for logged-in users"
 * (field.details.user_auth) or a download name is requested.
 * The checks are in Camindo\Core\MediaDelivery.
 */
use Camindo\Core\MediaDelivery;
use Camindo\Core\SystemState;

define ('CAMINDO_STARTED', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

// Maintenance mode / database not at the state of the files: 503, without DB access
$app->get(SystemState::class)->guardFrontend();

$app->get(MediaDelivery::class)->deliver();
