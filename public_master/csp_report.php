<?php
/**
 * Receive violations of the frontend script policy (report-uri of the
 * policy from SecurityHeaders::frontendPolicy(), developer docs, chapter 14 (Security: web).
 * The checks are in Camindo\Core\CspReport.
 */
use Camindo\Core\CspReport;
use Camindo\Core\SystemState;

define ('CAMINDO_STARTED', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

// Maintenance mode / database not at the state of the files: 503, without DB access
$app->get(SystemState::class)->guardFrontend();

$app->get(CspReport::class)->receive();
