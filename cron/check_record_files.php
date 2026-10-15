<?php
/**
 * check_record_files.php - self-test for working copies and file fields
 *
 *     php Core/cron/check_record_files.php
 *
 * Creates a test area in the first instance (title + upload field), runs the
 * paths of the record mask and removes the area again (Area::purge()):
 *
 *  1. tmpInit() creates a working copy - `parent_ids` must be valid JSON
 *     (earlier: left out of the INSERT; MariaDB stores '' there and its
 *     implicit CHECK (json_valid()) fails).
 *  2. Saving with a file creates the `data_fil` row (version 1).
 *  3. Check-out, replace the file, save: the row is updated in place via
 *     INSERT … ON DUPLICATE KEY UPDATE (version 2) - earlier with the row alias
 *     VALUES … AS new, which only MySQL 8.0.19+ understands.
 *  4. Check-out and cancel() leave no working copy behind.
 *
 * Runs in the CI against MySQL and MariaDB. Exit code 1 on deviations.
 */
use Camindo\Core\Cms;
use Camindo\Core\Config;
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

// Import files must lie in paths.tmp_dir (see VirtualFieldsTrait, 'importfile')
$tmpDir = rtrim((string)$app->get(Config::class)->get('paths.tmp_dir'), '/');
$tmpFiles = [];
$png = function (string $name) use ($tmpDir, &$tmpFiles): string {
    $file = $tmpDir . '/check_record_files_' . $name;
    $img = imagecreatetruecolor(2, 2);
    imagepng($img, $file);
    $tmpFiles[] = $file;
    return $file;
};

// data_fil row of the upload field (empty if there is none)
$fileRow = function (int $recordId, int $fieldId) use ($db): array {
    $db->query("SELECT `version`, `filename` FROM `data_fil` WHERE `record_id` = ? AND `field_id` = ?"
        . " ORDER BY `language_id` LIMIT 1", 'ii', [$recordId, $fieldId]);
    return $db->fetchAll()[0] ?? [];
};

$areaId = 0;
try {
    $data = ['instance_id' => $instanceId, 'type' => 'record', 'name' => 'check_record_files',
             'label' => 'check_record_files', 'icon' => '', 'details' => '', 'note' => ''];
    if (!$areaId = (int)$area->save([], $data)) $fail('area');
    $fieldIds = [];
    foreach ([['title', 'Title', 'chars', 'text', 1], ['doc', 'File', 'file', 'upload', 2]] as [$fname, $label, $type, $display, $pos]) {
        $data = ['area_id' => $areaId, 'name' => $fname, 'label' => $label, 'type' => $type,
                 'display_type' => $display, 'meta' => 0, 'position' => $pos];
        if (!$fieldIds[$fname] = (int)$field->save([], $data)) $fail("field $fname");
    }

    // 1. working copy
    $data = $record->tmpInit(['area_id' => $areaId]);
    if (!$data) $fail('tmpInit');
    $tmpId = (int)$data['record_id'];
    $db->query("SELECT JSON_VALID(`parent_ids`) FROM `record` WHERE `record_id` = ?", 'i', [$tmpId]);
    $check('tmpInit: parent_ids is valid JSON', (int)($db->fetchNum()[0] ?? 0), 1);

    // 2. save with a file -> data_fil row
    $data['title'] = 'A';
    $data['doc'] = ['importfile' => $png('a.png'), 'filename' => 'a.png'];
    if (!$id = (int)$record->save(['title', 'doc'], $data)) $fail('create');
    $row = $fileRow($id, $fieldIds['doc']);
    $check('create: data_fil version', (int)($row['version'] ?? 0), 1);
    $check('create: data_fil filename', (string)($row['filename'] ?? ''), 'a.png');

    // 3. replace the file -> same row, version 2
    $data = $record->load($id);
    if (!$data || !$record->checkOut($data)) $fail("checkOut $id");
    $data['doc'] = ['importfile' => $png('b.png'), 'filename' => 'b.png'];
    if (!$record->save(['doc'], $data)) $fail("save $id");
    $row = $fileRow($id, $fieldIds['doc']);
    $check('replace: data_fil version', (int)($row['version'] ?? 0), 2);
    $check('replace: data_fil filename', (string)($row['filename'] ?? ''), 'b.png');

    // 4. check out and cancel
    $data = $record->load($id);
    if (!$data || !$record->checkOut($data)) $fail("checkOut $id");
    $record->cancel((int)$data['record_id']);
    $db->query("SELECT COUNT(*) FROM `record` WHERE `area_id` = ? AND `tmp` = 1", 'i', [$areaId]);
    $check('cancel: no working copy left', (int)($db->fetchNum()[0] ?? -1), 0);
}
catch (\Throwable $e) {
    $failures++;
    echo "FAIL ", $e->getMessage(), "\n";
}
finally {
    if ($areaId) $area->purge($areaId);
    foreach ($tmpFiles as $file) @unlink($file);
}

echo $failures ? "\n$failures failure(s)\n" : "\nall ok\n";
exit($failures ? 1 : 0);
