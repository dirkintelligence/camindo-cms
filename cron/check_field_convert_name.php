<?php
/**
 * check_field_convert_name.php - self-test for the new name on a type change
 * (Field::convert() with $newName, Field::conversionName())
 *
 *     php Core/cron/check_field_convert_name.php
 *
 * Creates two test areas A and B in the first instance and removes them again
 * (Area::purge()):
 *
 *  1. Reserved prefixes: one per type - `rel_` relation, `rn1_` relation1, `ptr_`
 *     pointer, `pn1_` pointer1.
 *  2. The suggested name swaps a prefix the target type may not use
 *     (int_ -> txt_, rel_ -> rn1_), adds one to a name other fields still carry,
 *     other names stay.
 *  3. integer -> text keeping `int_…` is refused; with the new name `txt_…` the
 *     field is converted and renamed in one step: `fieldname` holds only the new
 *     name (type text), the rows carry its fieldname_id, filter and cache use it.
 *  4. A name carried by fields in two areas: the conversion is refused without a new
 *     name and succeeds with one - the other field keeps name and type.
 *  5. Invalid new names: other type in `fieldname`, taken in the area, wrong prefix,
 *     format.
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

$newField = function (int $areaId, string $name, string $type, string $display) use ($field, $fail): int {
    $data = ['area_id' => $areaId, 'name' => $name, 'label' => $name, 'type' => $type,
             'display_type' => $display, 'visibility_input' => 1];
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

$ids = function (int $areaId, array $filter) use ($cms): array {
    $cms->errorsReset();
    $cms->select(['filter' => ['area_id' => $areaId] + $filter, 'sort' => 'record_id', 'order' => 'ASC',
                  'limit' => 50, 'published' => 'all']);
    $found = [];
    while ($row = $cms->fetch()) $found[] = (int)$row['record_id'];
    return $found;
};

// convert() with a fresh error list; returns true or the last message
$convert = function (int $fieldId, string $toType, string $newName = '') use ($field, $cms): bool|string {
    $cms->errorsReset();
    return $field->convert($fieldId, $toType, 0, $newName) ?: $cms->errorMsgLast();
};
$refused = fn($result): bool => $result !== true;

$typeOf = fn(string $name): ?string => $value("SELECT `type` FROM `fieldname` WHERE `name` = ?", 's', [$name]);

$prefixOf = function (string $name, string $type) use ($field): string {
    return (new \ReflectionMethod($field, 'reservedPrefix'))->invoke($field, $name, $type);
};

$suffix = substr(md5((string)microtime(true)), 0, 6);
$areaA = $areaB = 0;
try {
    $areaA = $newArea("fconv_a_$suffix");
    $areaB = $newArea("fconv_b_$suffix");

    echo "-- 1. reserved prefixes\n";
    $check('rel_ for relation', $prefixOf('rel_x', 'relation'), '');
    $check('rel_ not for relation1', $prefixOf('rel_x', 'relation1'), 'rel_');
    $check('rn1_ for relation1', $prefixOf('rn1_x', 'relation1'), '');
    $check('rn1_ not for relation', $prefixOf('rn1_x', 'relation'), 'rn1_');
    $check('ptr_ not for pointer1', $prefixOf('ptr_x', 'pointer1'), 'ptr_');
    $check('pn1_ for pointer1', $prefixOf('pn1_x', 'pointer1'), '');
    $check('rel_ not for link', $prefixOf('rel_x', 'link'), 'rel_');

    echo "-- 2. suggested name\n";
    $check('int_ -> txt_', $field->conversionName('int_price', 'text'), 'txt_price');
    $check('rn1_ -> rel_', $field->conversionName('rn1_topic', 'relation'), 'rel_topic');
    $check('rel_ -> rn1_', $field->conversionName('rel_topic', 'relation1'), 'rn1_topic');
    $check('ptr_ -> pn1_', $field->conversionName('ptr_topic', 'pointer1'), 'pn1_topic');
    $check('no prefix stays', $field->conversionName('price', 'text'), 'price');

    echo "-- 3. rename with the type change\n";
    $intName = "int_fconv_$suffix";
    $txtName = "txt_fconv_$suffix";
    $numId = $newField($areaA, $intName, 'integer', 'text');
    $a1 = $create($areaA, ['title' => 'Alpha', $intName => 42]);
    $check('keeping int_ refused', $refused($convert($numId, 'text')), true);
    $check('still integer', $value("SELECT `type` FROM `field` WHERE `field_id` = ?", 'i', [$numId]), 'integer');
    $check('converted with txt_ name', $convert($numId, 'text', $txtName), true);
    $check('field name', $value("SELECT `name` FROM `field` WHERE `field_id` = ?", 'i', [$numId]), $txtName);
    $check('field type', $value("SELECT `type` FROM `field` WHERE `field_id` = ?", 'i', [$numId]), 'text');
    $check('old fieldname gone', $typeOf($intName), null);
    $check('new fieldname text', $typeOf($txtName), 'text');
    $check('rows carry new fieldname_id',
        (int)$value("SELECT COUNT(*) FROM `data_txt` `d` JOIN `fieldname` `n` ON `n`.`fieldname_id` = `d`.`fieldname_id`"
            . " WHERE `d`.`field_id` = ? AND `n`.`name` = ?", 'is', [$numId, $txtName]) > 0, true);
    $check('no rows left in data_int', (int)$value("SELECT COUNT(*) FROM `data_int` WHERE `field_id` = ?", 'i', [$numId]), 0);
    $check('filter by new name', $ids($areaA, [$txtName => '42']), [$a1]);
    $cms->select(['filter' => ['record_id' => $a1], 'published' => 'all', 'limit' => 1]);
    $row = $cms->fetch() ?: [];
    $check('record value under new name', (string)($row[$txtName] ?? ''), '42');

    echo "-- 4. name shared by two areas\n";
    $shared = "fconv_shared_$suffix";
    $sharedA = $newField($areaA, $shared, 'integer', 'text');
    $sharedB = $newField($areaB, $shared, 'integer', 'text');
    $check('without new name refused', $refused($convert($sharedA, 'text')), true);
    $check('suggestion adds prefix', $field->conversionName($shared, 'text', $sharedA), "txt_$shared");
    $loneId = $newField($areaA, "fconv_lone_$suffix", 'integer', 'text');
    $check('unshared name stays', $field->conversionName("fconv_lone_$suffix", 'text', $loneId), "fconv_lone_$suffix");
    $check('with new name converted', $convert($sharedA, 'text', "{$shared}_t"), true);
    $check('other field keeps name', $value("SELECT `name` FROM `field` WHERE `field_id` = ?", 'i', [$sharedB]), $shared);
    $check('shared name stays integer', $typeOf($shared), 'integer');
    $check('new name text', $typeOf("{$shared}_t"), 'text');

    echo "-- 5. invalid new names\n";
    $chkId = $newField($areaA, "fconv_x_$suffix", 'integer', 'text');
    $check('name of another type', $refused($convert($chkId, 'text', $shared)), true);
    $check('name taken in area', $refused($convert($chkId, 'text', "title")), true);
    $check('wrong prefix', $refused($convert($chkId, 'text', "int_y_$suffix")), true);
    $check('format', $refused($convert($chkId, 'text', "9abc")), true);
    $check('reserved', $refused($convert($chkId, 'text', Field::FULLTEXT)), true);
    $check('prefix alone', $refused($convert($chkId, 'text', 'txt_')), true);
    $check('unchanged after refusals', $value("SELECT CONCAT(`name`, ':', `type`) FROM `field` WHERE `field_id` = ?", 'i', [$chkId]),
        "fconv_x_$suffix:integer");
    $otherId = $newField($areaB, "fconv_y_$suffix", 'integer', 'text');
    $check('name of same type in another area', $convert($otherId, 'text', $txtName), true);
    $check('shared fieldname kept', $typeOf($txtName), 'text');
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
