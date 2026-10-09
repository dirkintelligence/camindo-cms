<?php
// Rewrite the instance cache files (var/cache/instances.php, instance-{id}.php)
// Normally not needed: the modules Instance and Language write it on
// every change themselves, and a missing cache is rebuilt on first access.
use Camindo\Core\InstanceCache;

$cache = $app->get(InstanceCache::class);
$cache->rebuild();
$all = $cache->all();
echo '<strong>' . $app->get(Camindo\Core\Config::class)->get('paths.var_dir') . "/cache</strong><br>\n";
echo "&gt; instances.php<br>\n";
foreach ((array)($all['instance_ids'] ?? []) as $instanceId) {
    echo "&gt; instance-{$instanceId}.php<br>\n";
}
