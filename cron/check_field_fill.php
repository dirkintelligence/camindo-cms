<?php
/**
 * check_field_fill.php - self-test for new fields in an area with records
 * (Field, class comment "NEW FIELD IN AN AREA WITH RECORDS")
 *
 *     php Core/cron/check_field_fill.php
 *
 * Creates a test area in the first instance with two records (one of them in the
 * trash), then adds fields and removes the area again (Area::purge()):
 *
 *  1. Every record gets one data_* row per language of the instance with the empty
 *     value of the type: '' (chars, text), [] (json), 0 (integer, bit, decimal),
 *     1000-01-01 (datetime, timestampl), 00:00:00 (time).
 *  2. Multilingual: only the row of the primary language is no fallback (`empty`).
 *  3. Encrypted: one row per record and language, hash of ''.
 *  4. Password and upload fields get no rows.
 *  5. record_cache of the area is discarded; select() finds the records with the
 *     filter "= 0" and "(empty)".
 *  6. id: NULL rows; saving a record without a reference keeps NULL (not 0, which
 *     would violate the foreign key of data_rid), a reference is stored and removed.
 *  7. API field.create: a date field is created (with the calendar preselected, as
 *     in the input mask, if the extension is active) and gets its empty rows.
 *
 * Exit code 1 on deviations.
 */
use Camindo\Core\Api\Api;
use Camindo\Core\Api\ApiSession;
use Camindo\Core\Cms;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
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

$languageIds = array_map('intval', array_keys($app->get(InstanceCache::class)->languages($instanceId)))
    ?: [$app->get(InstanceCache::class)->primaryLanguageId($instanceId)];
$primaryId = $languageIds[0];

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

$newField = function (int $areaId, string $name, string $type, string $display, array $extra = []) use ($field, $fail): int {
    $data = ['area_id' => $areaId, 'name' => $name, 'label' => $name, 'type' => $type,
             'display_type' => $display, 'visibility_input' => 1] + $extra;
    if (!$id = (int)$field->save([], $data)) $fail("field $name");
    return $id;
};

// data_* rows of a field: "language_id:record_id" => [data, empty]
$rows = function (string $suffix, int $fieldId, bool $withEmpty = true) use ($db): array {
    $db->query("SELECT `language_id`, `record_id`, `data`" . ($withEmpty ? ", `empty` + 0 AS `e`" : '')
        . " FROM `data_{$suffix}` WHERE `field_id` = ? ORDER BY `record_id`, `language_id`", 'i', [$fieldId]);
    $out = [];
    while ($row = $db->fetch()) {
        $out[$row['language_id'] . ':' . $row['record_id']] = [(string)$row['data'], (int)($row['e'] ?? 0)];
    }
    return $out;
};

// expected rows: every record, every language, $value, fallback flag if multilingual
$expected = function (array $recordIds, string $value, bool $multilingual) use ($languageIds, $primaryId): array {
    $out = [];
    foreach ($recordIds as $recordId) {
        foreach ($languageIds as $languageId) {
            $out[$languageId . ':' . $recordId] = [$value, ($multilingual && $languageId !== $primaryId) ? 1 : 0];
        }
    }
    ksort($out);
    return $out;
};
$sorted = function (array $a): array { ksort($a); return $a; };

$ids = function (int $areaId, array $filter) use ($cms): array {
    $cms->errorsReset();
    $cms->select(['filter' => ['area_id' => $areaId] + $filter, 'sort' => 'record_id', 'order' => 'ASC',
                  'limit' => 50, 'published' => 'all']);
    $found = [];
    while ($row = $cms->fetch()) $found[] = (int)$row['record_id'];
    return $found;
};

$areaId = 0;
try {
    $name = 'check_fill_' . bin2hex(random_bytes(3));
    $data = ['instance_id' => $instanceId, 'type' => 'record', 'name' => $name, 'label' => $name,
             'icon' => '', 'details' => '', 'note' => ''];
    if (!$areaId = (int)$area->save([], $data)) $fail('area');
    $newField($areaId, 'title', 'chars', 'text');

    $recordIds = [];
    foreach (['one', 'two'] as $title) {
        $rec = $record->tmpInit(['area_id' => $areaId]);
        $rec['title'] = $title;
        if (!$id = (int)$record->save(['title'], $rec)) $fail('record');
        $recordIds[] = $id;
    }
    if (!$record->delete($recordIds[1])) $fail('delete record');   // trash: still gets rows

    // 1. + 2. empty values per type
    // --------------------
    $cases = [
        // name, type, display, suffix, empty value, multilingual
        ['fill_chars', 'chars', 'text', 'chr', '', true],
        ['fill_text', 'text', 'textarea', 'txt', '', false],
        ['fill_json', 'json', 'checkbox', 'jsn', '[]', false],
        ['fill_int', 'integer', 'text', 'int', '0', false],
        ['fill_bit', 'bit', 'switch', 'bit', '0', true],
        ['fill_dec', 'decimal', 'text', 'dec', '0.0000000000', false],
        ['fill_date', 'datetime', 'text', 'dat', Cms::DATE_MIN, true],
        ['fill_tsl', 'timestampl', 'text', 'tsl', Cms::DATE_MIN . '.000', false],
        ['fill_time', 'time', 'text', 'tim', '00:00:00', false],
    ];
    foreach ($cases as [$fieldName, $type, $display, $suffix, $emptyValue, $multilingual]) {
        $fieldId = $newField($areaId, $fieldName, $type, $display, ['multilingual' => (int)$multilingual]);
        $check("$type: one empty row per record and language", $sorted($rows($suffix, $fieldId)),
            $expected($recordIds, $emptyValue, $multilingual));
    }

    // 3. encrypted
    // --------------------
    $fieldId = $newField($areaId, 'fill_enc', 'encrypted', 'text');
    $db->query("SELECT COUNT(*), SUM(`hash` = ?) FROM `data_enc` WHERE `field_id` = ?", 'si', [$cms->encryptedHash(''), $fieldId]);
    $row = $db->fetchNum();
    $check('encrypted: rows with the hash of the empty text', [(int)$row[0], (int)$row[1]],
        [count($recordIds) * count($languageIds), count($recordIds) * count($languageIds)]);

    // 4. no rows without a value
    // --------------------
    $fieldId = $newField($areaId, 'fill_pwd', 'password', 'password');
    $check('password: no rows', $rows('pwd', $fieldId, false), []);
    $fieldId = $newField($areaId, 'fill_file', 'file', 'upload');
    $db->query("SELECT COUNT(*) FROM `data_fil` WHERE `field_id` = ?", 'i', [$fieldId]);
    $check('upload: no rows', (int)($db->fetchNum()[0] ?? -1), 0);

    // 5. cache and filter
    // --------------------
    $db->query("SELECT COUNT(*) FROM `record_cache` WHERE `record_id` IN (?, ?)", 'ii', $recordIds);
    $check('record_cache of the area discarded', (int)($db->fetchNum()[0] ?? -1), 0);
    $check('filter int = 0 finds the live record', $ids($areaId, ['fill_int' => '0']), [$recordIds[0]]);
    $check('filter date (empty) finds the live record', $ids($areaId, ['fill_date' => '(empty)']), [$recordIds[0]]);

    // 6. id: NULL, also when a record is saved without a reference (data_rid.data is
    //     a foreign key to `record` - 0 would violate it)
    // --------------------
    $idFieldId = $newField($areaId, 'fill_id', 'id', 'select');
    $idValues = function () use ($db, $idFieldId, $recordIds): array {
        $db->query("SELECT `data` FROM `data_rid` WHERE `field_id` = ? AND `record_id` = ? ORDER BY `language_id`",
            'ii', [$idFieldId, $recordIds[0]]);
        return array_map(fn($v) => $v === null ? null : (int)$v, $db->fetchColumn());
    };
    $nulls = array_fill(0, count($languageIds), null);
    $check('id: NULL rows for the existing records', $idValues(), $nulls);
    $saveId = function (mixed $value) use ($record, $recordIds, $fail): void {
        $data = $record->load($recordIds[0]);
        if (!$data) $fail('load record');
        $data['fill_id'] = $value;
        $data['title'] = 'one ' . bin2hex(random_bytes(2));
        if (!$record->save(['title', 'fill_id'], $data)) $fail('save id ' . json_encode($value));
    };
    $saveId('');
    $check('id: saving without a reference keeps NULL', $idValues(), $nulls);
    $saveId((string)$recordIds[0]);
    $check('id: a reference is stored', $idValues(), array_fill(0, count($languageIds), $recordIds[0]));
    $saveId('');
    $check('id: an emptied reference is NULL again', $idValues(), $nulls);

    // 7. API: field.create with a date field
    // --------------------
    $apiSession = $app->get(ApiSession::class);
    $apiSession->runAs($adminId);
    try {
        $result = $app->get(Api::class)->call('field.create', ['area_id' => $areaId, 'type' => 'datetime',
            'data' => ['name' => 'fill_api_date', 'label' => 'fill_api_date', 'display_type' => 'text']]);
    }
    finally {
        $apiSession->logout();
    }
    $check('api: field.create datetime', [$result['ok'], $result['message'] ?? ''], [true, '']);
    $apiFieldId = (int)($result['data']['id'] ?? 0);
    $db->query("SELECT `details` FROM `field` WHERE `field_id` = ?", 'i', [$apiFieldId]);
    $details = json_decode((string)($db->fetchNum()[0] ?? ''), true) ?: [];
    $check('api: calendar preset (if active)', in_array($details['extension'] ?? '', ['', 'Calendar'], true), true);
    $check('api: empty rows for the existing records', count($rows('dat', $apiFieldId)), count($recordIds) * count($languageIds));
}
catch (\Throwable $e) {
    $failures++;
    echo "FAIL exception: " . $e->getMessage() . "\n";
}
finally {
    if ($areaId > 0) $area->purge($areaId);
}

echo $failures === 0 ? "all ok\n" : "$failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
