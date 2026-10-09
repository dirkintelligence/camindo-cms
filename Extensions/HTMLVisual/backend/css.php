<?php
/**
 * Serves the stylesheet from var/extensions/htmlvisual/ (developer docs, chapter 08 (Components and extensions)) - var/ is not in the
 * docroot. Call: backendExtensions/HTMLVisual/css.php?file={name}.css; the session is
 * checked by Backend::backendPath().
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
