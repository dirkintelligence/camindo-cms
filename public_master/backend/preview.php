<?php
/**
 * Vorschau aus dem Backend: einen Datensatz bzw. ein Standalone-Template so
 * ausliefern, wie es das Frontend täte - nur mit Admin-Session und auch für
 * unveröffentlichte Datensätze (Page::resolvePreview(), Backend-Modus - siehe
 * Klassenkommentar von Page). Läuft das Backend nicht unter einer Domain der
 * Instanz, geht es per Token weiter auf deren Hauptdomain
 * (FrontendHandover::previewUrl()) - dort gibt es auch die Bilder.
 *
 * Aufruf (Backend::buttonAddPreview()):
 *     preview.php?instance={id}&request={slug|id}[,{template}][&lang={code}]
 */
use Camindo\Core\Page;

define ('CAMINDO_STARTED', true);
define ('CAMINDO_BACKEND', true);
// Ausgabe ist ein Frontend-Template: Sicherheits-Header des Frontends (SecurityHeaders)
define ('CAMINDO_PREVIEW', true);

// Bootstrap laden
require_once dirname(__DIR__, 3) . '/load_camindo.php';

// Container laden
$app = $GLOBALS['app'];

$page = $app->get(Page::class);
$page->resolvePreview();
