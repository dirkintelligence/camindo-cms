<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Page;
use Camindo\Core\SystemState;

    define ('CAMINDO_STARTED', true);

    // Bootstrap laden
    require_once dirname(__DIR__, 2) . '/load_camindo.php';

    // Maintenance mode / database not at the state of the files: 503, without DB access
    $app->get(SystemState::class)->guardFrontend();

    $page = $app->get(Page::class);
    $page->resolveRequest();