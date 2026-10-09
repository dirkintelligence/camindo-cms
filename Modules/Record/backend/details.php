<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordDetails;

$recordDetails = $app->get(RecordDetails::class);
$recordDetails->details();
