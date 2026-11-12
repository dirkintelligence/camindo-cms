<?php
/**
 * check_field_convert_relation.php - self-test for relation <-> pointer
 * (Field::convert(), Field::switchRelationKind())
 *
 *     php Core/cron/check_field_convert_relation.php
 *
 * Creates three test areas A, B and C in the first instance and removes them again
 * (Area::purge()):
 *
 *  1. Targets: relation offers pointer, relation1 offers pointer1; a pointer with
 *     one target area offers relation, with two target areas or the own area not.
 *  2. relation -> pointer (new name ptr_…): the links stay, the counter field in B
 *     and its rows and name disappear, B becomes the target area. A working copy of
 *     a record in A keeps its links through the conversion and its check-in.
 *  3. pointer -> relation (back to rel_…): the counter field in B is created, its
 *     mirror rows are positioned per target record, filters work from both sides.
 *  4. relation1 -> pointer1 -> relation1: the counter field shows as 1:n.
 *  5. Counter name `rel_{area}` bound to another type in `fieldname` (a name from
 *     before the reserved prefixes): the new counter field takes the next free name.
 *  6. The prefixes are exclusive: a relation1 field cannot be called `rel_…`, and a
 *     prefix alone (`rel1_`, as the form prefills it) is no name.
 *
 * Exit code 1 on deviations.
 */
use Camindo\Core\Cms;
use Camindo\Core\Database;
use Camindo\Core\Session;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Area\Field;
use Camindo\Core\Modules\Record\Record;

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

$db->query("SELECT `admin_id` FROM `admin` WHERE `deleted` = 0 AND `group_id` = 1 ORDER BY `admin_id` LIMIT 1");
$adminId = (int)($db->fetchNum()[0] ?? 0);
$db->query("SELECT `instance_id` FROM `instance` ORDER BY `instance_id` LIMIT 1");
$instanceId = (int)($db->fetchNum()[0] ?? 0);
if ($adminId < 1 || $instanceId < 1) exit("no superuser / instance\n");
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

$value = function (string $sql, string $types = '', array $params = []) use ($db): mixed {
    $db->query($sql, $types, $params);
    return $db->fetchNum()[0] ?? null;
};

$column = function (string $sql, string $types = '', array $params = []) use ($db): array {
    $db->query($sql, $types, $params);
    return array_map(fn($row) => is_array($row) ? implode(':', $row) : (string)$row, $db->fetchAll());
};

$newField = function (int $areaId, string $name, string $type, string $display, array $extra = []) use ($field, $fail): int {
    $data = ['area_id' => $areaId, 'name' => $name, 'label' => $name, 'type' => $type,
             'display_type' => $display, 'visibility_input' => 1] + $extra;
    if (!$id = (int)$field->save([], $data)) $fail("field $name");
    return $id;
};

$newArea = function (string $name) use ($area, $instanceId, $fail, $newField): int {
    $data = ['instance_id' => $instanceId, 'type' => 'record', 'name' => $name, 'label' => $name,
             'icon' => '', 'details' => '', 'note' => ''];
    if (!$areaId = (int)$area->save([], $data)) $fail("area $name");
    $newField($areaId, 'title', 'chars', 'text');
    return $areaId;
};

$create = function (int $areaId, string $title) use ($record, $fail): int {
    $data = $record->tmpInit(['area_id' => $areaId]) + [];
    $data['title'] = $title;
    if (!$id = (int)$record->save(['title'], $data)) $fail('create record');
    return $id;
};

$fieldRow = function (int $fieldId) use ($db): array {
    $db->query("SELECT `name`, `type`, `rel_area_id`, `rel_field_id` FROM `field` WHERE `field_id` = ?", 'i', [$fieldId]);
    return $db->fetch() ?: [];
};

// Links src -> dest of a relation (with mirror rows) or a pointer
$link = function (int $fieldId, array $pairs) use ($db, $cms, $fieldRow): void {
    $row = $fieldRow($fieldId);
    $fn = (int)$cms->resolveVirtualField((string)$row['name'])['fieldname_id'];
    $counterId = (int)($row['rel_field_id'] ?? 0);
    $counterFn = $counterId ? (int)$cms->resolveVirtualField((string)$fieldRow($counterId)['name'])['fieldname_id'] : 0;
    foreach ($pairs as $i => [$src, $dest]) {
        $db->query("INSERT INTO `data_rel` (`field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`) VALUES (?, ?, ?, ?, ?)",
            'iiiii', [$fieldId, $fn, $src, $dest, $i]);
        if ($counterId) {
            $db->query("INSERT INTO `data_rel` (`field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`) VALUES (?, ?, ?, ?, ?)",
                'iiiii', [$counterId, $counterFn, $dest, $src, $i]);
        }
    }
};

$ids = function (int $areaId, array $filter) use ($cms): array {
    $cms->errorsReset();
    $cms->clearFieldCaches();
    $cms->select(['filter' => ['area_id' => $areaId] + $filter, 'sort' => 'record_id', 'order' => 'ASC',
                  'limit' => 50, 'published' => 'all']);
    $found = [];
    while ($row = $cms->fetch()) $found[] = (int)$row['record_id'];
    return $found;
};

$convert = function (int $fieldId, string $toType, string $newName = '') use ($field, $cms): bool|string {
    $cms->errorsReset();
    return $field->convert($fieldId, $toType, 0, $newName) ?: $cms->errorMsgLast();
};

$targets = function (int $fieldId) use ($field): array {
    return array_values(array_unique(array_map(fn($t) => $t['type'], $field->conversionTargets($field->load($fieldId)))));
};

$suffix = substr(md5((string)microtime(true)), 0, 6);
$areaA = $areaB = $areaC = 0;
try {
    $nameA = "fcr_a_$suffix";
    $areaA = $newArea($nameA);
    $areaB = $newArea("fcr_b_$suffix");
    $areaC = $newArea("fcr_c_$suffix");
    $a1 = $create($areaA, 'A1');
    $a2 = $create($areaA, 'A2');
    $b1 = $create($areaB, 'B1');
    $b2 = $create($areaB, 'B2');

    echo "-- 1. targets\n";
    $relName = "rel_x_$suffix";
    $relId = $newField($areaA, $relName, 'relation', 'lookup', ['rel_area_id' => $areaB]);
    $check('relation -> relation1, pointer', $targets($relId), ['relation1', 'pointer']);
    $ptrTwo = $newField($areaA, "ptr_two_$suffix", 'pointer', 'lookup', ['rel_area_ids' => [$areaB, $areaC]]);
    $check('pointer, two areas: no relation', $targets($ptrTwo), ['pointer1']);
    $ptrOwn = $newField($areaA, "ptr_own_$suffix", 'pointer', 'lookup', ['rel_area_ids' => [$areaA]]);
    $check('pointer, own area: no relation', $targets($ptrOwn), ['pointer1']);

    echo "-- 2. relation -> pointer\n";
    $link($relId, [[$a1, $b1], [$a1, $b2], [$a2, $b1]]);
    $counterId = (int)$fieldRow($relId)['rel_field_id'];
    $counterName = (string)$fieldRow($counterId)['name'];
    $check('counter field in B', $counterName, 'rel_' . $nameA);
    $check('filter B by counter', $ids($areaB, [$counterName => (string)$a1]), [$b1, $b2]);
    // working copy of a1 open during the conversion
    $data = $record->load($a1);
    if (!$data || !$tmpId = (int)$record->checkOut($data)) $fail('checkOut a1');
    $ptrName = "ptr_x_$suffix";
    $check('keeping rel_ refused', $convert($relId, 'pointer') !== true, true);
    $check('converted', $convert($relId, 'pointer', $ptrName), true);
    $row = $fieldRow($relId);
    $check('type, name', [$row['type'], $row['name']], ['pointer', $ptrName]);
    $check('no rel_area_id / rel_field_id', [$row['rel_area_id'], $row['rel_field_id']], [null, null]);
    $check('target area list', $column("SELECT `area_id` FROM `rel_field_area` WHERE `field_id` = ?", 'i', [$relId]), [(string)$areaB]);
    $check('counter field gone', (int)$value("SELECT COUNT(*) FROM `field` WHERE `field_id` = ?", 'i', [$counterId]), 0);
    $check('counter rows gone', (int)$value("SELECT COUNT(*) FROM `data_rel` WHERE `field_id` = ?", 'i', [$counterId]), 0);
    $check('counter name gone', $value("SELECT `type` FROM `fieldname` WHERE `name` = ?", 's', [$counterName]), null);
    $check('filter A by pointer', $ids($areaA, [$ptrName => (string)$b1]), [$a1, $a2]);
    $data['title'] = 'A1 checked in';
    $check('check-in of the working copy', (bool)$record->save(['title'], $data), true);
    $check('links of a1 after check-in', $column("SELECT `dest_id` FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? ORDER BY `position`", 'ii', [$relId, $a1]),
        [(string)$b1, (string)$b2]);

    echo "-- 3. pointer -> relation\n";
    $check('pointer -> pointer1, relation', $targets($relId), ['pointer1', 'relation']);
    $check('converted back', $convert($relId, 'relation', $relName), true);
    $row = $fieldRow($relId);
    $check('type, name, target area', [$row['type'], $row['name'], (int)$row['rel_area_id']], ['relation', $relName, $areaB]);
    $check('target area list cleared', (int)$value("SELECT COUNT(*) FROM `rel_field_area` WHERE `field_id` = ?", 'i', [$relId]), 0);
    $newCounterId = (int)$row['rel_field_id'];
    $counter = $fieldRow($newCounterId);
    $check('counter field', [$counter['name'], $counter['type'], (int)$counter['rel_field_id']], ['rel_' . $nameA, 'relation', $relId]);
    $check('mirror rows', $column("SELECT `src_id`, `dest_id`, `position` FROM `data_rel` WHERE `field_id` = ? ORDER BY `src_id`, `position`", 'i', [$newCounterId]),
        ["$b1:$a1:0", "$b1:$a2:1", "$b2:$a1:0"]);
    $check('filter A by relation', $ids($areaA, [$relName => (string)$b2]), [$a1]);
    $check('filter B by counter', $ids($areaB, [(string)$counter['name'] => (string)$a2]), [$b1]);

    echo "-- 4. relation1 -> pointer1 -> relation1\n";
    $one = "rel1_one_$suffix";
    $oneId = $newField($areaA, $one, 'relation1', 'lookup', ['rel_area_id' => $areaB]);
    $link($oneId, [[$a1, $b2], [$a2, $b2]]);
    $check('relation1 -> relation, pointer1', $targets($oneId), ['relation', 'pointer1']);
    $check('keeping rel1_ refused', $convert($oneId, 'pointer1') !== true, true);
    $check('to pointer1', $convert($oneId, 'pointer1', "ptr1_one_$suffix"), true);
    $check('links kept', (int)$value("SELECT COUNT(*) FROM `data_rel` WHERE `field_id` = ?", 'i', [$oneId]), 2);
    $check('back to relation1', $convert($oneId, 'relation1', $one), true);
    $oneCounter = (int)$fieldRow($oneId)['rel_field_id'];
    $check('counter shows 1:n', $field->typeLabelKey($fieldRow($oneCounter) + ['rel_field_id' => $oneId]), 'area.label_field_type_relation_1n');
    $check('counter rows', (int)$value("SELECT COUNT(*) FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ?", 'ii', [$oneCounter, $b2]), 2);

    echo "-- 5. counter name bound to another type\n";
    // B already holds the counter fields rel_{A} (section 3) and rel_{A}_1 (section 4);
    // the next free name rel_{A}_2 is bound to 'text' (legacy entry, removed below)
    $blocked = 'rel_' . $nameA . '_2';
    $db->query("INSERT INTO `fieldname` (`name`, `type`) VALUES (?, 'text')", 's', [$blocked]);
    $ptrBlock = $newField($areaA, "ptr_blk_$suffix", 'pointer', 'lookup', ['rel_area_ids' => [$areaB]]);
    $check('converted', $convert($ptrBlock, 'relation', "rel_blk_$suffix"), true);
    $blockCounter = (string)$fieldRow((int)$fieldRow($ptrBlock)['rel_field_id'])['name'];
    $check('counter name skips the bound name', $blockCounter, 'rel_' . $nameA . '_3');
    $check('fieldname of counter is relation', $value("SELECT `type` FROM `fieldname` WHERE `name` = ?", 's', [$blockCounter]), 'relation');

    echo "-- 6. exclusive prefixes\n";
    $cms->errorsReset();
    $bad = ['area_id' => $areaA, 'name' => "rel_bad_$suffix", 'label' => 'bad', 'type' => 'relation1',
            'display_type' => 'lookup', 'visibility_input' => 1, 'rel_area_id' => $areaB];
    $check('relation1 named rel_ refused', (int)$field->save([], $bad), 0);
    $check('message names the prefix', str_contains($cms->errorMsgLast(), 'rel_'), true);
    $check('prefixes per type', array_map([$field, 'typePrefix'], ['relation', 'relation1', 'pointer', 'pointer1', 'link']),
        ['rel_', 'rel1_', 'ptr_', 'ptr1_', 'lnk_']);
    $cms->errorsReset();
    $onlyPrefix = ['area_id' => $areaA, 'name' => 'rel1_', 'label' => 'bad', 'type' => 'relation1',
                   'display_type' => 'lookup', 'visibility_input' => 1, 'rel_area_id' => $areaB];
    $check('prefix alone refused', (int)$field->save([], $onlyPrefix), 0);
    $check('message names the prefix alone', str_contains($cms->errorMsgLast(), 'rel1_'), true);
}
catch (\Throwable $e) {
    $failures++;
    echo "FAIL " . $e->getMessage() . "\n";
}
finally {
    if (isset($blocked)) {
        $db->query("DELETE FROM `fieldname` WHERE `name` = ? AND NOT EXISTS (SELECT 1 FROM `field` WHERE `field`.`name` = ?)", 'ss', [$blocked, $blocked]);
    }
    foreach ([$areaA, $areaB, $areaC] as $id) {
        if ($id > 0 && !$area->purge($id)) echo "cleanup area $id: " . $cms->errorMsgLast() . "\n";
    }
}

echo $failures === 0 ? "all ok\n" : "$failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
