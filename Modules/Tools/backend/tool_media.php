<?php
// @var Camindo\Core\ContainerInterface $app
// Sperrdateien des Medienverzeichnisses neu schreiben: .htaccess je
// Instanz-Verzeichnis (Domain-Sperre) und je Feld mit details.user_auth
use Camindo\Core\MediaAccess;

$mediaAccess = $app->get(MediaAccess::class);
$mediaAccess->rebuildAll();
$count = $mediaAccess->rebuildFieldProtection();
echo "media: instance .htaccess files written, {$count} protected field(s)<br>\n";
