<?php
// @var Camindo\Core\ContainerInterface $app
// Vorlagen der Datenauskunft je Instanz und Sprache (Modul Privacy)
use Camindo\Core\Modules\Privacy\PrivacyBackend;

$app->get(PrivacyBackend::class)->templateList();
