<?php
/**
 * Stylesheet aus var/extensions/htmlvisual/ ausliefern (13 §3.8) - var/ liegt nicht im
 * Docroot. Aufruf: backendExtensions/HTMLVisual/css.php?file={name}.css; Session ist
 * durch Backend::backendPath() geprüft.
 */
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Backend;

$file = basename((string)($_GET['file'] ?? ''));
$path = $app->get(Backend::class)->extensionVarDir('HTMLVisual') . '/' . $file;
if ($file === '' || !preg_match('/^[A-Za-z0-9_\-.]+\.css$/', $file) || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not found';
    exit;
}
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($path);
exit;
