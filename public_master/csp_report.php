<?php
/**
 * Verstöße gegen die Script-Policy des Frontends entgegennehmen (report-uri der
 * Policy aus SecurityHeaders::frontendPolicy(), developer_docs/19-csp.md §9).
 * Die Prüfungen stehen in Camindo\Core\CspReport.
 */
use Camindo\Core\CspReport;
use Camindo\Core\SystemState;

define ('CAMINDO_STARTED', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

// Wartungsmodus / Datenbank nicht auf dem Stand der Dateien: 503, ohne DB-Zugriff
$app->get(SystemState::class)->guardFrontend();

$app->get(CspReport::class)->receive();
