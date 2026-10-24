<?php
/**
 * OSS Scanner image: after `camindo.php install`, creates the instance "Scanner" and the
 * restricted backend user "editor" (group "Editor": module Record, instance "Scanner",
 * its languages read and write) - via the Dataset classes like the backend masks.
 *
 *     php /src/.oss-scanner/setup.php      (in the project directory)
 */
if (PHP_SAPI !== 'cli') exit(1);

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);
require getcwd() . '/load_camindo.php';

use Camindo\Core\Database;
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\Modules\Admin\Group;
use Camindo\Core\Modules\Instance\Instance;
use Camindo\Core\Session;

$db = $app->get(Database::class);
$db->query("SELECT `admin_id` FROM `admin` WHERE `deleted` = 0 AND `group_id` = 1 ORDER BY `admin_id` LIMIT 1");
$app->get(Session::class)->setCliAdmin((int)($db->fetchNum()[0] ?? 0));

$db->query("SELECT `language_id` FROM `language` ORDER BY `language_id` LIMIT 1");
$languageId = (int)($db->fetchNum()[0] ?? 0);

$data = [
    'instance_id' => 0,
    'label' => 'Scanner',
    'primary_language_id' => $languageId,
    'additional_languages' => [],
    'primary_domain' => '127.0.0.1',
];
$instanceId = $app->get(Instance::class)->save(['label', 'primary_language_id', 'additional_languages', 'primary_domain'], $data);
if (!$instanceId) {
    fwrite(STDERR, "instance not created\n");
    exit(1);
}
echo "instance $instanceId created\n";

$data = [
    'group_id' => 0,
    'label' => 'Editor',
    'modules' => ['Record' => ['access' => 1]],
    'instance_ids' => [$instanceId],
    'languages' => [$languageId => Group::LANGUAGE_WRITE],
];
$groupId = $app->get(Group::class)->save(['label', 'details', 'instance_ids', 'languages'], $data);
if (!$groupId) {
    fwrite(STDERR, "group not created\n");
    exit(1);
}
echo "group $groupId created\n";

$data = [
    'admin_id' => 0,
    'name' => 'editor',
    'password' => 'Scanner-Editor-2026!x',
    'group_id' => $groupId,
    'email' => 'editor@example.com',
    'status_id' => 0,
];
$adminId = $app->get(Admin::class)->save(['name', 'password', 'group_id', 'email', 'status_id'], $data);
if (!$adminId) {
    fwrite(STDERR, "admin not created\n");
    exit(1);
}
echo "admin $adminId (editor) created\n";
