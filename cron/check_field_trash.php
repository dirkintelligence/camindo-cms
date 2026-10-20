<?php
/**
 * check_field_trash.php - self-test for the trash of input fields
 * (Field, class comment "TRASH")
 *
 *     php Core/cron/check_field_trash.php
 *
 * Creates two test areas A and B in the first instance (A with a character field and a
 * relation to B) and removes them again (Area::purge()):
 *
 *  1. Deleting a field only marks it: the data_* rows stay, record_cache of the area
 *     is cleared and rebuilt without the field, filter and virtualSchema() ignore it,
 *     the default field of the area is cleared.
 *  2. A new field with the name of a field in the trash is refused.
 *  3. The trash lists the field; restore() brings field, filter, cache and default
 *     field back.
 *  4. A relation field takes its counter field along (same timestamp); the trash
 *     shows the pair once, restore brings both back.
 *  5. Deleting area B takes its fields and the relation field of A along; restoring
 *     B brings them back.
 *  6. Final deletion (trash cron job): field row, data_* rows and the name in
 *     `fieldname` disappear.
 *
 * Exit code 1 on deviations.
 */
use Camindo\Core\Cms;
use Camindo\Core\Database;
use Camindo\Core\Session;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Area\Field;
use Camindo\Core\Modules\Record\Record;
use Camindo\Core\Modules\Tools\ToolDatabase;
use Camindo\Core\Modules\Trash\Trash;

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

// A superuser (the trash lists fields only for them)
$db->query("SELECT `admin_id` FROM `admin` WHERE `deleted` = 0 AND `group_id` = 1 ORDER BY `admin_id` LIMIT 1");
$adminId = (int)($db->fetchNum()[0] ?? 0);
$db->query("SELECT `instance_id` FROM `instance` ORDER BY `instance_id` LIMIT 1");
$instanceId = (int)($db->fetchNum()[0] ?? 0);
if ($adminId < 1 || $instanceId < 1) exit("no superuser / instance\n");
$app->get(Session::class)->setCliAdmin($adminId);
$trash = $app->get(Trash::class);

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

$create = function (int $areaId, array $values) use ($record, $fail): int {
    $data = $record->tmpInit(['area_id' => $areaId]) + [];
    foreach ($values as $k => $v) $data[$k] = $v;
    if (!$id = (int)$record->save(array_keys($values), $data)) $fail('create record');
    return $id;
};

// Record ids of a filtered selection
$ids = function (int $areaId, array $filter) use ($cms): array {
    $cms->errorsReset();
    $cms->select(['filter' => ['area_id' => $areaId] + $filter, 'sort' => 'record_id', 'order' => 'ASC',
                  'limit' => 50, 'published' => 'all']);
    if ($cms->errorMsgLast() !== '') echo "     (select: " . $cms->errorMsgLast() . ")\n";
    $found = [];
    while ($row = $cms->fetch()) $found[] = (int)$row['record_id'];
    return $found;
};

$cacheHas = function (int $recordId, string $name) use ($value): bool {
    $json = (string)$value("SELECT `cache` FROM `record_cache` WHERE `record_id` = ? LIMIT 1", 'i', [$recordId]);
    return array_key_exists($name, json_decode($json, true) ?: []);
};

$deleted = fn(int $fieldId): int => (int)$value("SELECT `deleted` + 0 FROM `field` WHERE `field_id` = ?", 'i', [$fieldId]);

$trashIds = fn(): array => array_map(fn($row) => (int)$row['field_id'], $trash->fields());

$suffix = substr(md5((string)microtime(true)), 0, 6);
$areaA = $areaB = 0;
try {
    $areaA = $newArea("ftrash_a_$suffix");
    $areaB = $newArea("ftrash_b_$suffix");
    $noteName = "chr_ftrash_$suffix";
    $relName  = "rel_ftrash_$suffix";
    $noteId = $newField($areaA, $noteName, 'chars', 'text');
    $relId  = $newField($areaA, $relName, 'relation', 'lookup', ['rel_area_id' => $areaB]);
    $counterId = (int)$value("SELECT `rel_field_id` FROM `field` WHERE `field_id` = ?", 'i', [$relId]);
    $counterName = (string)$value("SELECT `name` FROM `field` WHERE `field_id` = ?", 'i', [$counterId]);

    $a1 = $create($areaA, ['title' => 'Alpha', $noteName => 'needle']);
    $a2 = $create($areaA, ['title' => 'Beta', $noteName => 'other']);
    $b1 = $create($areaB, ['title' => 'Bravo']);
    // link a1 -> b1 (and the counter edge) directly
    $relFn = (int)$cms->resolveVirtualField($relName)['fieldname_id'];
    $counterFn = (int)$cms->resolveVirtualField($counterName)['fieldname_id'];
    $db->query("INSERT INTO `data_rel` (`field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`) VALUES (?, ?, ?, ?, 0), (?, ?, ?, ?, 0)",
        'iiiiiiii', [$relId, $relFn, $a1, $b1, $counterId, $counterFn, $b1, $a1]);
    $db->query("UPDATE `area` SET `default_field_id` = ? WHERE `area_id` = ?", 'ii', [$noteId, $areaA]);

    echo "-- baseline\n";
    $check('filter note=needle', $ids($areaA, [$noteName => 'needle']), [$a1]);
    $check('filter relation=b1', $ids($areaA, [$relName => (string)$b1]), [$a1]);
    $check('cache has note', $cacheHas($a1, $noteName), true);

    echo "-- 1. soft delete of a field\n";
    $check('delete()', $field->delete($noteId), true);
    $check('field marked', $deleted($noteId), 1);
    $check('data_chr rows kept', (int)$value("SELECT COUNT(*) FROM `data_chr` WHERE `field_id` = ?", 'i', [$noteId]) > 0, true);
    $check('record_cache of the area cleared', (int)$value("SELECT COUNT(*) FROM `record_cache` c JOIN `record` r USING (`record_id`) WHERE r.`area_id` = ? AND r.`tmp` = 0", 'i', [$areaA]), 0);
    $check('default field cleared', $value("SELECT `default_field_id` FROM `area` WHERE `area_id` = ?", 'i', [$areaA]), null);
    $check('filter note=needle finds nothing', $ids($areaA, [$noteName => 'needle']), []);
    // like a field the area does not have: no row, no hit (also not for a negation)
    $check('filter note=!needle finds nothing', $ids($areaA, [$noteName => '!needle']), []);
    $cms->errorsReset();
    $cms->select(['filter' => ['area_id' => $areaA], 'sort' => $noteName, 'published' => 'all']);
    $sorted = 0;
    while ($cms->fetch()) $sorted++;
    $check('sort by note: all records, no error', [$sorted, $cms->errorMsgLast()], [2, '']);
    $check('cache rebuilt without note', $cacheHas($a1, $noteName), false);
    $check('virtualSchema without note', isset($record->virtualSchema($areaA)[$noteName]), false);

    echo "-- 2. name of a field in the trash\n";
    $cms->errorsReset();
    $data = ['area_id' => $areaA, 'name' => $noteName, 'label' => 'x', 'type' => 'chars', 'display_type' => 'text'];
    $check('new field with the name refused', (int)$field->save([], $data), 0);
    $check('message', $cms->errorMsgLast() !== '', true);

    echo "-- 3. trash and restore\n";
    $check('trash lists the field', in_array($noteId, $trashIds(), true), true);
    $check('Trash::restore()', $trash->restore('field', $noteId), 'restored');
    $check('field live', $deleted($noteId), 0);
    $check('default field back', (int)$value("SELECT `default_field_id` FROM `area` WHERE `area_id` = ?", 'i', [$areaA]), $noteId);
    $check('filter note=needle again', $ids($areaA, [$noteName => 'needle']), [$a1]);
    $check('cache has note again', $cacheHas($a1, $noteName), true);

    echo "-- 4. relation field with counter field\n";
    $check('delete() relation', $field->delete($relId), true);
    $check('relation marked', $deleted($relId), 1);
    $check('counter field marked', $deleted($counterId), 1);
    $check('same timestamp', $value("SELECT COUNT(DISTINCT `changed_at`) FROM `field` WHERE `field_id` IN (?, ?)", 'ii', [$relId, $counterId]), 1);
    $listed = array_values(array_intersect($trashIds(), [$relId, $counterId]));
    $check('trash shows the pair once', $listed, [min($relId, $counterId)]);
    $check('filter relation=b1 finds nothing', $ids($areaA, [$relName => (string)$b1]), []);
    $check('filter counter=a1 finds nothing', $ids($areaB, [$counterName => (string)$a1]), []);
    $check('Trash::restore() pair', $trash->restore('field', $listed[0] ?? 0), 'restored');
    $check('relation live', $deleted($relId) + $deleted($counterId), 0);
    $check('filter relation=b1 again', $ids($areaA, [$relName => (string)$b1]), [$a1]);

    echo "-- 5. area deletion\n";
    $check('delete() area B', $area->delete($areaB), true);
    $check('fields of B marked', (int)$value("SELECT COUNT(*) FROM `field` WHERE `area_id` = ? AND `deleted` = 0", 'i', [$areaB]), 0);
    $check('relation of A to B marked', $deleted($relId), 1);
    $check('trash does not list it on its own', in_array($relId, $trashIds(), true) || in_array($counterId, $trashIds(), true), false);
    $check('restore() area B', $area->restore($areaB), true);
    $check('fields of B live', (int)$value("SELECT COUNT(*) FROM `field` WHERE `area_id` = ? AND `deleted` = 1", 'i', [$areaB]), 0);
    $check('relation of A live', $deleted($relId), 0);

    echo "-- 6. final deletion\n";
    $check('delete() again', $field->delete($noteId), true);
    // far in the past: the run below only reaches this field
    $db->query("UPDATE `field` SET `changed_at` = '1999-12-31 00:00:00' WHERE `field_id` = ?", 'i', [$noteId]);
    $tool = $app->get(ToolDatabase::class);
    $purge = new \ReflectionMethod($tool, 'purgeTable');
    $errors = [];
    $check('purged', $purge->invoke($tool, $field, '2000-01-01 00:00:00', $errors), 1);
    $check('no errors', $errors, []);
    $check('field row gone', (int)$value("SELECT COUNT(*) FROM `field` WHERE `field_id` = ?", 'i', [$noteId]), 0);
    $check('data_chr rows gone', (int)$value("SELECT COUNT(*) FROM `data_chr` WHERE `field_id` = ?", 'i', [$noteId]), 0);
    $check('fieldname gone', (int)$value("SELECT COUNT(*) FROM `fieldname` WHERE `name` = ?", 's', [$noteName]), 0);
}
catch (\Throwable $e) {
    $failures++;
    echo "FAIL " . $e->getMessage() . "\n";
}
finally {
    foreach ([$areaA, $areaB] as $id) {
        if ($id > 0 && !$area->purge($id)) echo "cleanup area $id: " . $cms->errorMsgLast() . "\n";
    }
}

echo $failures === 0 ? "all ok\n" : "$failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
