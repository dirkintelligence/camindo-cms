<?php
// Dateicache der Instanzen neu schreiben (var/cache/instances.php, instance-{id}.php)
// Normalerweise nicht nötig: die Module Instance und Language schreiben ihn bei
// jeder Änderung selbst, und ein fehlender Cache wird beim ersten Zugriff neu erzeugt.
use Camindo\Core\InstanceCache;

$cache = $app->get(InstanceCache::class);
$cache->rebuild();
$all = $cache->all();
echo '<strong>' . $app->get(Camindo\Core\Config::class)->get('paths.var_dir') . "/cache</strong><br>\n";
echo "&gt; instances.php<br>\n";
foreach ((array)($all['instance_ids'] ?? []) as $instanceId) {
    echo "&gt; instance-{$instanceId}.php<br>\n";
}
