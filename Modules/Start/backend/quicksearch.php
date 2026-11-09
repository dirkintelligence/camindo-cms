<?php
// Quick search (Ctrl+G / Cmd+G, backend-quicksearch.js): prefixes and hits as JSON
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Start\StartQuickSearch;

$app->get(StartQuickSearch::class)->handle();
