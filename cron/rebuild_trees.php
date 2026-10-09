<?php
/**
 * rebuild_trees.php - recalculate tree fields (repair and migration)
 *
 * Recalculates `lft`, `rgt`, `depth` and `parent_ids` from `parent_id` + `position` -
 * for the area tree (`area`) and for each content area (`record`) individually.
 *
 * TWO OCCASIONS
 * --------------------
 * 1. REPAIR: the nested-set bounds no longer match (interrupted transaction,
 *    direct interventions in the database). `parent_id` and `position` are the
 *    leading fields, everything else can be derived from them.
 *
 * 2. MIGRATION of a stock to the per-area tree handling:
 *      - `lft`/`rgt` now count from 1 per content area (previously table-wide)
 *      - `depth` is 0-based (previously root nodes started at 1)
 *      - content areas WITHOUT input field `parent_id` no longer keep a tree:
 *        their records are flattened (parent_id = NULL, lft/rgt/depth = 0,
 *        parent_ids = []); the previous display order remains as `position`
 *        preserved.
 *    The script is to be called ONCE after the update for this. Without the run
 *    legacy data with 1-based depth and table-wide lft values remain.
 *
 * Repeated runs are harmless - the result depends only on parent_id/position.
 *
 * Call:
 *     php /var/www/html/camindo/Core/cron/rebuild_trees.php
 *
 * @see Camindo\Core\TreeManagerTrait
 */
use Camindo\Core\Database;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Record\Record;

// Reachable only on the command line, not via the web server
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

set_time_limit(0);
ignore_user_abort(true);

define ('CAMINDO_STARTED', true);
define ('CAMINDO_CLI', true);

// Load bootstrap
require_once dirname(__DIR__, 2) . '/load_camindo.php';

/** @var \Camindo\Core\Container $app */
$app = $GLOBALS['app'];

$db     = $app->get(Database::class);
$area   = $app->get(Area::class);
$record = $app->get(Record::class);

// 1. Area tree (`area`) - one table, one tree
$area->externalRebuildTree();
echo "area: tree rebuilt\n";

// 2. Content areas individually
$db->query("SELECT `area_id`, `name` FROM `area` WHERE `type` IN ('record','profile','ugc') ORDER BY `lft` ASC");
$areas = $db->fetchAll();

$trees = 0;
$flat  = 0;
foreach ($areas as $row) {
    $areaId = (int)$row['area_id'];
    $name   = (string)$row['name'];

    if ($record->isTreeArea($areaId)) {
        // Area with input field `parent_id`: make positions per parent node gapless
        // (legacy data counted table-wide) and recalculate the tree
        $record->normalizePositions($areaId);
        $record->externalRebuildTree($areaId);
        echo "record area {$areaId} ({$name}): tree rebuilt\n";
        $trees++;
    }
    else {
        // Area without tree: neutralize tree fields, keep the order
        $record->resetTreeFields($areaId);
        echo "record area {$areaId} ({$name}): flattened\n";
        $flat++;
    }
}

echo "done. areas with tree: {$trees}, flat areas: {$flat}\n";
exit(0);
