<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Page;
use Camindo\Core\SystemState;

    define ('CAMINDO_STARTED', true);

    // Bootstrap laden
    require_once dirname(__DIR__, 2) . '/load_camindo.php';

    // Wartungsmodus / Datenbank nicht auf dem Stand der Dateien: 503, ohne DB-Zugriff
    $app->get(SystemState::class)->guardFrontend();

    $page = $app->get(Page::class);
    $page->resolveRequest();