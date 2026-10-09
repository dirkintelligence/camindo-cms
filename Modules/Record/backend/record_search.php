<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordBackendSearch;

$recordBackendSearch = $app->get(RecordBackendSearch::class);
$recordBackendSearch->search();
