<?php
/**
 * check_tree_positions.php - Selbsttest für Positionen/Baum in Dataset::save()
 * und den Volltext-Neuaufbau
 *
 *     php Core/cron/check_tree_positions.php
 *
 * Legt zwei Test-Bereiche in der ersten Instanz an (flach mit `position`, Baum mit
 * `parent_id` + `position`), prüft und entfernt sie wieder (Area::purge()):
 *
 *  1. load() + checkOut() + save($fields, $data) OHNE previous_id lässt den
 *     Datensatz an seinem Platz - mit und ohne `parent_id` in $fields
 *     (früher: an den Anfang seiner Ebene, z. B. RecordUser::saveRecord()).
 *  2. Umsortieren per previous_id in einem flachen Bereich, dessen Positionen
 *     Doppelbelegungen, Lücken oder 0 tragen, ergibt 1..n in der gewünschten
 *     Reihenfolge (früher: z. B. 0,0,0,1).
 *  3. Ein anderer Vaterdatensatz (parent_id in $fields) hängt weiterhin um.
 *  4. refreshFulltext($fieldId) ohne Datensatz-Id baut alle Datensätze neu auf
 *     (früher: Fatal Error, Database::pushStack() gibt es nicht).
 *
 * Exit-Code 1 bei Abweichungen.
 */
use Camindo\Core\Cms;
use Camindo\Core\Database;
use Camindo\Core\Session;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Area\Field;
use Camindo\Core\Modules\Record\Record;

// Nur auf der Kommandozeile erreichbar, nicht per Webserver
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

/** @var \Camindo\Core\Container $app */
$app = $GLOBALS['app'];

$cms    = $app->get(Cms::class);
$db     = $app->get(Database::class);
$area   = $app->get(Area::class);
$field  = $app->get(Field::class);
$record = $app->get(Record::class);

// Speichern braucht einen Admin (changed_by): der erste Superuser
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

$newArea = function (string $name, bool $tree) use ($area, $field, $instanceId, $fail): array {
    $data = ['instance_id' => $instanceId, 'type' => 'record', 'name' => $name, 'label' => $name,
             'icon' => '', 'details' => '', 'note' => ''];
    if (!$areaId = (int)$area->save([], $data)) $fail("area $name");
    $fields = [['title', 'Title', 'chars', 'text', 0, 1], ['position', 'Position', 'integer', 'select', 1, 10]];
    if ($tree) $fields[] = ['parent_id', 'Parent', 'id', 'select', 1, 11];
    $ids = [];
    foreach ($fields as [$fname, $label, $type, $display, $meta, $pos]) {
        $data = ['area_id' => $areaId, 'name' => $fname, 'label' => $label, 'type' => $type,
                 'display_type' => $display, 'meta' => $meta, 'position' => $pos];
        if (!$ids[$fname] = (int)$field->save([], $data)) $fail("field $fname");
    }
    return [$areaId, $ids];
};

// Neuer Datensatz hinter $previousId (unter $parentId)
$create = function (int $areaId, string $title, int $previousId = 0, int $parentId = 0) use ($record, $fail): int {
    $data = $record->tmpInit(['area_id' => $areaId]);
    $data['title'] = $title;
    $data['previous_id'] = $previousId;
    $fields = ['title'];
    if ($parentId > 0) {
        $data['parent_id'] = $parentId;
        $fields[] = 'parent_id';
    }
    if (!$id = (int)$record->save($fields, $data)) $fail("create $title");
    return $id;
};

// Wie RecordUser::saveRecord() / Skripte: laden, auschecken, ändern, speichern
$resave = function (int $id, array $fields, array $set = []) use ($record, $fail): void {
    $data = $record->load($id);
    if (!$data || !$record->checkOut($data)) $fail("checkOut $id");
    foreach ($set as $k => $v) $data[$k] = $v;
    if (!$record->save($fields, $data)) $fail("save $id");
};

// Reihenfolge einer Ebene als "Titel:Position"
// (Titel in der ersten Sprache - data_chr trägt eine Zeile je Sprache der Instanz)
$level = function (int $areaId, int $parentId = 0) use ($db): string {
    $sql = "SELECT (SELECT `c`.`data` FROM `data_chr` AS `c` JOIN `field` AS `f` USING (`field_id`)"
         . "         WHERE `c`.`record_id` = `r`.`record_id` AND `f`.`name` = 'title'"
         . "         ORDER BY `c`.`language_id` LIMIT 1) AS `title`, `r`.`position`"
         . " FROM `record` AS `r`"
         . " WHERE `r`.`area_id` = ? AND `r`.`tmp` = 0 AND `r`.`parent_id` " . ($parentId ? "= ?" : "IS NULL")
         . " ORDER BY `r`.`position`, `r`.`record_id`";
    $db->query($sql, $parentId ? 'ii' : 'i', $parentId ? [$areaId, $parentId] : [$areaId]);
    return implode(' ', array_map(fn($r) => $r['title'] . ':' . $r['position'], $db->fetchAll()));
};

$areaIds = [];
try {
    // ====================
    // Flacher Bereich
    // ====================
    [$flatId, $flatFields] = $newArea('check_tree_flat', false);
    $areaIds[] = $flatId;
    $ids = [];
    $prev = 0;
    foreach (['A', 'B', 'C', 'D'] as $t) $ids[$t] = $prev = $create($flatId, $t, $prev);
    $check('flat: create appends via previous_id', $level($flatId), 'A:1 B:2 C:3 D:4');

    // 1. ohne previous_id bleibt der Datensatz stehen
    $resave($ids['C'], ['title'], ['title' => 'C']);
    $check('flat: load+save without previous_id keeps position', $level($flatId), 'A:1 B:2 C:3 D:4');

    // 2. verschmutzte Positionen, dann per previous_id umsortieren (D C B A)
    foreach ([[1, 1, 1, 1], [0, 2, 3, 4], [0, 0, 0, 1], [3, 5, 9, 9]] as $start) {
        $i = 0;
        foreach ($ids as $id) $db->query("UPDATE `record` SET `position` = ? WHERE `record_id` = ?", 'ii', [$start[$i++], $id]);
        $prev = 0;
        foreach (['D', 'C', 'B', 'A'] as $t) {
            $resave($ids[$t], ['title'], ['previous_id' => $prev]);
            $prev = $ids[$t];
        }
        $check('flat: resort from positions ' . implode(',', $start), $level($flatId), 'D:1 C:2 B:3 A:4');
    }

    // ====================
    // Baum
    // ====================
    [$treeId, $treeFields] = $newArea('check_tree_tree', true);
    $areaIds[] = $treeId;
    $r1 = $create($treeId, 'R1');
    $r2 = $create($treeId, 'R2', $r1);
    $prev = 0;
    $kids = [];
    foreach (['K1', 'K2', 'K3'] as $t) $kids[$t] = $prev = $create($treeId, $t, $prev, $r1);
    $check('tree: children created', $level($treeId, $r1), 'K1:1 K2:2 K3:3');

    // 1. load+save ohne previous_id - parent_id nicht in $fields / in $fields / alle Felder
    $resave($kids['K3'], ['title']);
    $check('tree: load+save([title]) keeps position', $level($treeId, $r1), 'K1:1 K2:2 K3:3');
    $resave($kids['K3'], ['title', 'parent_id']);
    $check('tree: load+save([title,parent_id]) unchanged parent keeps position', $level($treeId, $r1), 'K1:1 K2:2 K3:3');
    $resave($kids['K2'], []);
    $check('tree: load+save([]) keeps position', $level($treeId, $r1), 'K1:1 K2:2 K3:3');
    $resave($r2, ['title']);
    $check('tree: root load+save keeps position', $level($treeId), 'R1:1 R2:2');

    // previous_id sortiert weiterhin um
    $resave($kids['K1'], ['title'], ['previous_id' => $kids['K3']]);
    $check('tree: previous_id moves', $level($treeId, $r1), 'K2:1 K3:2 K1:3');

    // 3. anderer Vaterdatensatz ohne previous_id: an den Anfang der neuen Ebene
    $resave($kids['K3'], ['title', 'parent_id'], ['parent_id' => $r2]);
    $check('tree: new parent_id moves (old level)', $level($treeId, $r1), 'K2:1 K1:2');
    $check('tree: new parent_id moves (new level)', $level($treeId, $r2), 'K3:1');
    // ... parent_id nur in $data, nicht in $fields: kein Umhängen
    $resave($kids['K2'], ['title'], ['parent_id' => $r2]);
    $check('tree: parent_id outside $fields is ignored', $level($treeId, $r1), 'K2:1 K1:2');

    // lft/rgt nach allen Bewegungen konsistent (rebuildTree)
    $db->query("SELECT `record_id`, `lft`, `rgt` FROM `record` WHERE `area_id` = ? AND `tmp` = 0 ORDER BY `lft`", 'i', [$treeId]);
    $order = array_map(fn($r) => (int)$r['record_id'], $db->fetchAll());
    $check('tree: nested set order', $order, [$r1, $kids['K2'], $kids['K1'], $r2, $kids['K3']]);

    // ====================
    // 4. Volltext-Neuaufbau über alle Datensätze
    // ====================
    $data = ['area_id' => $flatId, 'name' => 'search', 'label' => 'Search', 'type' => 'fulltext',
             'display_type' => 'text', 'position' => 20, 'source_fields' => [$flatFields['title']]];
    if (!$ftxId = (int)$field->save([], $data)) $fail('field search');
    $db->query("DELETE FROM `data_ftx` WHERE `field_id` = ?", 'i', [$ftxId]);
    $record->refreshFulltext($ftxId);
    $db->query("SELECT COUNT(DISTINCT `record_id`) FROM `data_ftx` WHERE `field_id` = ? AND `data` != ''", 'i', [$ftxId]);
    $check('fulltext: full rebuild fills every record', (int)($db->fetchNum()[0] ?? 0), count($ids));
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
