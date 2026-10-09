<?php
/**
 * CI: legt in einer frischen Installation eine Instanz an - über Instance::save() wie
 * die Backend-Maske. Selbsttests wie check_tree_positions.php brauchen eine.
 *
 *     php Core/.github/ci/create-instance.php      (im Projektverzeichnis)
 */
if (PHP_SAPI !== 'cli') exit(1);

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);
require getcwd() . '/load_camindo.php';

use Camindo\Core\Database;
use Camindo\Core\Modules\Instance\Instance;
use Camindo\Core\Session;

$db = $app->get(Database::class);
$db->query("SELECT `admin_id` FROM `admin` WHERE `deleted` = 0 ORDER BY `admin_id` LIMIT 1");
$app->get(Session::class)->setCliAdmin((int)($db->fetchNum()[0] ?? 0));

$db->query("SELECT `language_id` FROM `language` ORDER BY `language_id` LIMIT 1");
$data = [
    'instance_id' => 0,
    'label' => 'CI',
    'primary_language_id' => (int)($db->fetchNum()[0] ?? 0),
    'additional_languages' => [],
    'primary_domain' => 'ci.example.com',
];
$id = $app->get(Instance::class)->save(['label', 'primary_language_id', 'additional_languages', 'primary_domain'], $data);
if (!$id) {
    fwrite(STDERR, "instance not created\n");
    exit(1);
}
echo "instance $id created\n";
