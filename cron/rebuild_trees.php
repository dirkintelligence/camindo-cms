<?php
/**
 * rebuild_trees.php - Baumfelder neu berechnen (Reparatur und Migration)
 *
 * Berechnet `lft`, `rgt`, `depth` und `parent_ids` aus `parent_id` + `position` neu -
 * für den Bereichsbaum (`area`) und für jeden Inhaltsbereich (`record`) einzeln.
 *
 * ZWEI ANLÄSSE
 * --------------------
 * 1. REPARATUR: die Nested-Set-Grenzen stimmen nicht mehr (abgebrochene Transaktion,
 *    direkte Eingriffe in die Datenbank). `parent_id` und `position` sind die
 *    führenden Felder, alles andere ist daraus ableitbar.
 *
 * 2. MIGRATION eines Bestands auf die bereichsweise Baumführung:
 *      - `lft`/`rgt` zählen jetzt je Inhaltsbereich ab 1 (vorher tabellenweit)
 *      - `depth` ist 0-basiert (vorher begannen Wurzelknoten bei 1)
 *      - Inhaltsbereiche OHNE Eingabefeld `parent_id` führen keinen Baum mehr:
 *        ihre Datensätze werden flach gemacht (parent_id = NULL, lft/rgt/depth = 0,
 *        parent_ids = []); die bisherige Anzeigereihenfolge bleibt als `position`
 *        erhalten.
 *    Das Skript ist dafür EINMAL nach dem Update aufzurufen. Ohne den Lauf bleiben
 *    Altbestände mit 1-basiertem depth und tabellenweiten lft-Werten stehen.
 *
 * Mehrfache Läufe sind unschädlich - das Ergebnis hängt nur von parent_id/position ab.
 *
 * Aufruf:
 *     php /var/www/html/camindo/Core/cron/rebuild_trees.php
 *
 * @see Camindo\Core\TreeManagerTrait
 */
use Camindo\Core\Database;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Record\Record;

// Nur auf der Kommandozeile erreichbar, nicht per Webserver
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

set_time_limit(0);
ignore_user_abort(true);

define ('CAMINDO_STARTED', true);
define ('CAMINDO_CLI', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

/** @var \Camindo\Core\Container $app */
$app = $GLOBALS['app'];

$db     = $app->get(Database::class);
$area   = $app->get(Area::class);
$record = $app->get(Record::class);

// 1. Bereichsbaum (`area`) - eine Tabelle, ein Baum
$area->externalRebuildTree();
echo "area: tree rebuilt\n";

// 2. Inhaltsbereiche einzeln
$db->query("SELECT `area_id`, `name` FROM `area` WHERE `type` IN ('record','profile','ugc') ORDER BY `lft` ASC");
$areas = $db->fetchAll();

$trees = 0;
$flat  = 0;
foreach ($areas as $row) {
    $areaId = (int)$row['area_id'];
    $name   = (string)$row['name'];

    if ($record->isTreeArea($areaId)) {
        // Bereich mit Eingabefeld `parent_id`: Positionen je Elternknoten lückenlos
        // machen (Altbestände zählten tabellenweit) und Baum neu berechnen
        $record->normalizePositions($areaId);
        $record->externalRebuildTree($areaId);
        echo "record area {$areaId} ({$name}): tree rebuilt\n";
        $trees++;
    }
    else {
        // Bereich ohne Baum: Baumfelder neutralisieren, Reihenfolge behalten
        $record->resetTreeFields($areaId);
        echo "record area {$areaId} ({$name}): flattened\n";
        $flat++;
    }
}

echo "done. areas with tree: {$trees}, flat areas: {$flat}\n";
exit(0);
