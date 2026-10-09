<?php
/**
 * Preview from the backend: deliver a record or a standalone template
 * as the frontend would - only with administrator session and also for
 * unpublished records (Page::resolvePreview(), backend mode - see
 * class comment of Page). If the backend does not run under a domain of the
 * instance, it continues via token to its main domain
 * (FrontendHandover::previewUrl()) - the images are there too.
 *
 * Call (Backend::buttonAddPreview()):
 *     preview.php?instance={id}&request={slug|id}[,{template}][&lang={code}]
 */
use Camindo\Core\Page;

define ('CAMINDO_STARTED', true);
define ('CAMINDO_BACKEND', true);
// Output is a frontend template: security headers of the frontend (SecurityHeaders)
define ('CAMINDO_PREVIEW', true);

// Bootstrap laden
require_once dirname(__DIR__, 3) . '/load_camindo.php';

// Container laden
$app = $GLOBALS['app'];

$page = $app->get(Page::class);
$page->resolvePreview();
