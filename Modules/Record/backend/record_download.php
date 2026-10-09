<?php
// @var Camindo\Core\ContainerInterface $app
// Zip-Datei eines Exports aus var/export ausliefern (Link auf der Ergebnisseite
// von record_export.php, öffnet in einem neuen Fenster)
use Camindo\Core\Modules\Record\RecordBackendExport;

$recordBackendExport = $app->get(RecordBackendExport::class);
$recordBackendExport->download();
