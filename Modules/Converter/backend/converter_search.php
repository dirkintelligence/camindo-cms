<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Converter\converterBackend;

// Page parameters
$function = (string) ($_POST['function'] ?? '');

$converterBackend = $app->get(converterBackend::class);

if ($function == 'search') {
    $converterBackend->search();
}

if ($function == '') {
    $converterBackend->searchForm();
}
