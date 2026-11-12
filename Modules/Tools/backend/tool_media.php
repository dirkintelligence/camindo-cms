<?php
// @var Camindo\Core\ContainerInterface $app
// Rewrite the lock files of the media directory: .htaccess per
// instance directory (domain lock) and per field with details.user_auth
use Camindo\Core\MediaAccess;

// Rebuilds files - POST only (developer docs, chapter 14 (Security: web))
if (!$app->get(Camindo\Core\Backend::class)->requirePost()) $app->get(Camindo\Core\Access::class)->denied();

$mediaAccess = $app->get(MediaAccess::class);
$mediaAccess->rebuildAll();
$count = $mediaAccess->rebuildFieldProtection();
echo "media: instance .htaccess files written, {$count} protected field(s)<br>\n";
