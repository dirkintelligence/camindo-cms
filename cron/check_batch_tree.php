<?php
/**
 * check_batch_tree.php - self-test for the tree fields of the batch processing
 * (RecordBatch, TREE FIELDS)
 *
 *     php Core/cron/check_batch_tree.php
 *
 * Creates two test areas in the first instance (tree with `parent_id` + `position`,
 * flat with `position`), runs batch jobs on them and removes them again
 * (Area::purge()):
 *
 *  1. parent_id only: the records are appended as the last children of the new
 *     parent record, in their current order (tree order, not the order of the ids).
 *  2. position (with parent_id): the records follow the chosen predecessor in
 *     their current order; the predecessor itself stays in place if it is in the scope.
 *  3. position without parent_id: the level of the predecessor applies.
 *  4. A parent record in the subtree of a record is refused for that record
 *     (also in the simulation); the others are moved.
 *  5. Simulation changes nothing.
 *  6. Flat area: position after a predecessor and "at the start" (0).
 *
 * Exit code 1 on deviations.
 */
use Camindo\Core\Cms;
use Camindo\Core\Database;
use Camindo\Core\Session;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Area\Field;
use Camindo\Core\Modules\Record\Record;
use Camindo\Core\Modules\Record\RecordBatch;

// Reachable only on the command line, not via the web server
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Load bootstrap
require_once dirname(__DIR__, 2) . '/load_camindo.php';

/** @var \Camindo\Core\Container $app */
$app = $GLOBALS['app'];

$cms    = $app->get(Cms::class);
$db     = $app->get(Database::class);
$area   = $app->get(Area::class);
$field  = $app->get(Field::class);
$record = $app->get(Record::class);
$batch  = $app->get(RecordBatch::class);

// Saving needs an admin (changed_by): the first superuser
$db->query("SELECT `admin_id` FROM `admin` WHERE `deleted` = 0 ORDER BY `admin_id` LIMIT 1");
$adminId = (int)($db->fetchNum()[0] ?? 0);
$db->query("SELECT `instance_id` FROM `instance` ORDER BY `instance_id` LIMIT 1");
$instanceId = (int)($db->fetchNum()[0] ?? 0);
if ($adminId < 1 || $instanceId < 1) exit("no admin / instance\n");
$app->get(Session::class)->setCliAdmin($adminId);

$failures = 0;
$check = function (string $label, $actual, $expected) use (&$failures): void {
    $ok = ($actual === $expected);
    if (!$ok) $failures++;
    printf("%-4s %s%s\n", $ok ? 'ok' : 'FAIL', $label,
        $ok ? '' : '  expected ' . json_encode($expected) . ', got ' . json_encode($actual));
};

$fail = function (string $what) use ($cms): never {
    throw new \RuntimeException($what . ': ' . $cms->errorMsgLast());
};

$newArea = function (string $name, bool $tree) use ($area, $field, $instanceId, $fail): int {
    $data = ['instance_id' => $instanceId, 'type' => 'record', 'name' => $name, 'label' => $name,
             'icon' => '', 'details' => '', 'note' => ''];
    if (!$areaId = (int)$area->save([], $data)) $fail("area $name");
    $fields = [['title', 'Title', 'chars', 'text', 0, 1], ['position', 'Position', 'integer', 'select', 1, 10]];
    if ($tree) $fields[] = ['parent_id', 'Parent', 'id', 'select', 1, 11];
    foreach ($fields as [$fname, $label, $type, $display, $meta, $pos]) {
        $data = ['area_id' => $areaId, 'name' => $fname, 'label' => $label, 'type' => $type,
                 'display_type' => $display, 'meta' => $meta, 'position' => $pos, 'visibility_input' => 1];
        if (!(int)$field->save([], $data)) $fail("field $fname");
    }
    return $areaId;
};

// New record at the end of its level (under $parentId)
$create = function (int $areaId, string $title, int $parentId = 0) use ($record, $fail): int {
    $data = $record->tmpInit(['area_id' => $areaId]);
    $data['title'] = $title;
    $data['previous_id'] = $record->lastChildId($parentId, $areaId);
    $fields = ['title'];
    if ($parentId > 0) {
        $data['parent_id'] = $parentId;
        $fields[] = 'parent_id';
    }
    if (!$id = (int)$record->save($fields, $data)) $fail("create $title");
    return $id;
};

// Titles of a level in position order
$level = function (int $areaId, int $parentId = 0) use ($db): string {
    $sql = "SELECT (SELECT `c`.`data` FROM `data_chr` AS `c` JOIN `field` AS `f` USING (`field_id`)"
         . "         WHERE `c`.`record_id` = `r`.`record_id` AND `f`.`name` = 'title'"
         . "         ORDER BY `c`.`language_id` LIMIT 1) AS `title`"
         . " FROM `record` AS `r`"
         . " WHERE `r`.`area_id` = ? AND `r`.`tmp` = 0 AND `r`.`deleted` = 0 AND `r`.`parent_id` " . ($parentId ? "= ?" : "IS NULL")
         . " ORDER BY `r`.`position`, `r`.`record_id`";
    $db->query($sql, $parentId ? 'ii' : 'i', $parentId ? [$areaId, $parentId] : [$areaId]);
    return implode(' ', $db->fetchAll());
};

// Batch job 'edit' with the tree fields
$run = function (int $areaId, array $ids, array $fields, array $data, bool $simulate = false) use ($batch): array {
    return $batch->run(['mode' => 'edit', 'area_id' => $areaId, 'ids' => $ids, 'fields' => $fields,
                        'data' => $data, 'simulate' => $simulate]);
};

$areaIds = [];
try {
    // ====================
    // Tree
    // ====================
    $treeId = $newArea('check_batch_tree', true);
    $areaIds[] = $treeId;
    $r = [];
    foreach (['R1', 'R2', 'R3'] as $t) $r[$t] = $create($treeId, $t);
    foreach (['A', 'B', 'C'] as $t) $r[$t] = $create($treeId, $t, $r['R1']);
    foreach (['D', 'E'] as $t) $r[$t] = $create($treeId, $t, $r['R2']);
    $r['X'] = $create($treeId, 'X', $r['R3']);

    // 1. parent_id only - ids deliberately in a different order
    $result = $run($treeId, [$r['E'], $r['C'], $r['A']], ['parent_id'], ['parent_id' => $r['R3']]);
    $check('parent_id: done', $result['done'], 3);
    $check('parent_id: appended in tree order', $level($treeId, $r['R3']), 'X A C E');
    $check('parent_id: old level closed', $level($treeId, $r['R1']), 'B');

    // 2. position with parent_id: behind B under R1
    $result = $run($treeId, [$r['E'], $r['D']], ['parent_id', 'position'], ['parent_id' => $r['R1'], 'previous_id' => $r['B']]);
    $check('position: done', $result['done'], 2);
    $check('position: behind the predecessor', $level($treeId, $r['R1']), 'B D E');
    $check('position: R2 empty', $level($treeId, $r['R2']), '');

    // ... predecessor in the scope stays, the others follow it
    $result = $run($treeId, [$r['A'], $r['D'], $r['B']], ['parent_id', 'position'], ['parent_id' => $r['R1'], 'previous_id' => $r['B']]);
    $check('position: predecessor in scope - done', $result['done'], 3);
    $check('position: predecessor in scope', $level($treeId, $r['R1']), 'B D A E');

    // ... at the start of the level
    $result = $run($treeId, [$r['E']], ['parent_id', 'position'], ['parent_id' => $r['R1'], 'previous_id' => 0]);
    $check('position: at the start', $level($treeId, $r['R1']), 'E B D A');

    // 3. position without parent_id: level of the predecessor (X under R3)
    $result = $run($treeId, [$r['B'], $r['E']], ['position'], ['previous_id' => $r['X']]);
    $check('position only: level of the predecessor', $level($treeId, $r['R3']), 'X E B C');

    // 4. cycle: R1 under its own child D - refused, R2 is moved
    $result = $run($treeId, [$r['R1'], $r['R2']], ['parent_id'], ['parent_id' => $r['D']], true);
    $check('cycle (simulation): refused', [$result['done'], $result['failed']], [1, 1]);
    $result = $run($treeId, [$r['R1'], $r['R2']], ['parent_id'], ['parent_id' => $r['D']]);
    $check('cycle: refused', [$result['done'], $result['failed'], array_keys($result['errors'])], [1, 1, [$r['R1']]]);
    $check('cycle: R2 moved under D', $level($treeId, $r['D']), 'R2');
    $check('cycle: root level', $level($treeId), 'R1 R3');

    // ... also when saving a single record (TreeManagerTrait::updateInTree())
    $data = $record->load($r['R1']);
    if (!$data || !$tmpId = (int)$record->checkOut($data)) $fail('checkOut R1');
    $data['parent_id'] = $r['R2'];
    $cms->errorsReset();
    $saved = $record->save(['parent_id'], $data);
    $check('cycle: single save refused', [(bool)$saved, isset($cms->errors['parent_id'])], [false, true]);
    $record->cancel($tmpId);
    $check('cycle: single save - root level', $level($treeId), 'R1 R3');

    // 5. simulation changes nothing
    $before = $level($treeId, $r['R3']);
    $run($treeId, [$r['E']], ['parent_id'], ['parent_id' => $r['R1']], true);
    $check('simulation: unchanged', $level($treeId, $r['R3']), $before);

    // lft/rgt consistent (rebuildTree)
    $db->query("SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 0 ORDER BY `lft`", 'i', [$treeId]);
    $order = array_map('intval', $db->fetchAll());
    $check('tree: nested set order', $order, [$r['R1'], $r['D'], $r['R2'], $r['A'], $r['R3'], $r['X'], $r['E'], $r['B'], $r['C']]);

    // ====================
    // Flat area
    // ====================
    $flatId = $newArea('check_batch_flat', false);
    $areaIds[] = $flatId;
    $f = [];
    foreach (['F1', 'F2', 'F3', 'F4', 'F5'] as $t) $f[$t] = $create($flatId, $t);
    $run($flatId, [$f['F5'], $f['F2']], ['position'], ['previous_id' => $f['F3']]);
    $check('flat: behind the predecessor', $level($flatId), 'F1 F3 F2 F5 F4');
    $run($flatId, [$f['F4']], ['position'], ['previous_id' => 0]);
    $check('flat: at the start', $level($flatId), 'F4 F1 F3 F2 F5');
    $check('flat: parent_id not editable', isset($batch->batchFields($record->virtualSchema($flatId, false))['parent_id']), false);
}
catch (\Throwable $e) {
    $failures++;
    echo "FAIL exception: " . $e->getMessage() . "\n";
}
finally {
    foreach ($areaIds as $areaId) $area->purge($areaId);
}

echo $failures ? "{$failures} failure(s)\n" : "all passed\n";
exit($failures ? 1 : 0);
