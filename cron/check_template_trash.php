<?php
/**
 * check_template_trash.php - self-test for template areas in the trash
 * (TemplateFiles, class comment "THE FILES LEAD")
 *
 *     php Core/cron/check_template_trash.php
 *
 * Creates a template area in the first instance, deletes it and creates a new area
 * with the same name (as an import does), then removes both again (Area::purge()):
 *
 *  1. Deleting the area moves its directory to var/templates/.trash/{area_id}.
 *  2. A template of the deleted area reads its source code from there - not from
 *     the directory of the new area of the same name.
 *  3. Saving a template of the deleted area is refused (neither in place nor moved
 *     to another area); the files of the new area stay untouched.
 *  4. The final deletion of the old area removes only its trash copy - directory
 *     and files of the new area stay.
 *
 * Exit code 1 on deviations.
 */
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\Session;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Template\Template;
use Camindo\Core\Modules\Template\TemplateFiles;

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

$cms      = $app->get(Cms::class);
$db       = $app->get(Database::class);
$area     = $app->get(Area::class);
$template = $app->get(Template::class);
$files    = $app->get(TemplateFiles::class);

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

$newArea = function (string $name) use ($area, $instanceId, $fail): int {
    $data = $area->init();
    $data['area_id'] = 0;
    $data['instance_id'] = $instanceId;
    $data['type'] = 'template';
    $data['name'] = $name;
    $data['label'] = $name;
    $data['icon'] = Area::DEFAULT_ICONS['template'];
    $data['parent_id'] = 0;
    $data['default_field_id'] = 0;
    $data['sort_field_id'] = 0;
    $data['default_template_id'] = 0;
    if (!$areaId = (int)$area->save([], $data)) $fail("area $name");
    return $areaId;
};

$newTemplate = function (int $areaId, string $name, string $code) use ($template, $fail): int {
    $data = ['template_id' => 0, 'area_id' => $areaId, 'name' => $name, 'label' => '',
             'code' => $code, 'type' => '', 'standalone' => 0];
    if (!$id = (int)$template->save([], $data)) $fail("template $name");
    return $id;
};

$name = 'zz_check_template_trash';
$base = $app->get(Config::class)->get('paths.var_dir') . '/templates';
$dir = $base . '/' . $instanceId . '/' . $name;
$oldAreaId = $newAreaId = $otherAreaId = 0;

try {
    $oldAreaId = $newArea($name);
    $oldId = $newTemplate($oldAreaId, 'header', 'old header');
    $otherAreaId = $newArea($name . '_other');

    // 1. delete -> directory to the trash
    $check('delete() old area', $area->delete($oldAreaId), true);
    $check('directory moved to the trash', is_file($base . '/.trash/' . $oldAreaId . '/header.cms.php'), true);
    $check('live directory gone', is_dir($dir), false);

    // new area with the same name (import)
    $newAreaId = $newArea($name);
    $newTemplate($newAreaId, 'header', 'new header');
    $check('new area has its file', file_get_contents($dir . '/header.cms.php'), 'new header');

    // 2. reading the old template uses the trash copy
    $check('old template reads the trash copy', $files->readTemplate($oldAreaId, 'header'), 'old header');
    $check('load() of the old template', (string)($template->load($oldId)['code'] ?? ''), 'old header');

    // 3. saving the old template is refused - in place and moved
    $data = ['template_id' => $oldId, 'area_id' => $oldAreaId, 'name' => 'header', 'label' => '', 'code' => 'changed'];
    $check('save in deleted area refused', $template->save(['area_id', 'name', 'label', 'code'], $data), null);
    $cms->errorsReset();
    $data = ['template_id' => $oldId, 'area_id' => $otherAreaId, 'name' => 'header', 'label' => '', 'code' => 'changed'];
    $check('move out of deleted area refused', $template->save(['area_id', 'name', 'label', 'code'], $data), null);
    $cms->errorsReset();
    $check('old template stays in its area', (int)$template->load($oldId)['area_id'], $oldAreaId);
    $check('new file untouched', file_get_contents($dir . '/header.cms.php'), 'new header');
    $check('nothing written to the other area', is_file($base . '/' . $instanceId . '/' . $name . '_other/header.cms.php'), false);

    // 4. final deletion of the old area leaves the new one alone
    $purgedId = $oldAreaId;
    $check('purge() old area', $area->purge($purgedId), true);
    $oldAreaId = 0;
    $check('trash copy removed', is_dir($base . '/.trash/' . $purgedId), false);
    $check('new directory kept', is_file($dir . '/header.cms.php'), true);
    $check('new file content kept', file_get_contents($dir . '/header.cms.php'), 'new header');
}
catch (\Throwable $e) {
    $failures++;
    echo "FAIL " . $e->getMessage() . "\n";
}
finally {
    foreach ([$oldAreaId, $newAreaId, $otherAreaId] as $id) {
        if ($id > 0 && !$area->purge($id)) echo "cleanup area $id: " . $cms->errorMsgLast() . "\n";
    }
}

echo $failures === 0 ? "all ok\n" : "$failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
