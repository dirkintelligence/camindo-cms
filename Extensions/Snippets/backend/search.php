<?php
/**
 * Search snippets or resolve a snippet id - JSON for snippets.ext.js (developer docs, chapter 08 (Components and extensions)):
 * backendExtensions/Snippets/search.php; the session is checked by Backend::backendPath(),
 * no records are returned.
 *
 *     ?q=such begriffe      hits from the snippet cache of the backend language of the
 *                           administrator: all prefixes, frontend AND backend files.
 *                           Each term must occur in id or text (case
 *                           insensitive). Response: {hits: [{id, text, backend,
 *                           icu}], total}
 *     ?resolve=record.x     text for the id, as Cms::snippet() returns it:
 *                           {id, text, found}
 *     ?texts=1              only the UI texts: {texts}
 *
 * The id is what follows the '#': {prefix}.{key}, for core snippets only
 * {key} (Cms::snippet() without a dot = core). ICU snippets (closures in the cache) are
 * evaluated with empty arguments and marked as such.
 *
 * Instance snippets (custom.{instance_id}.*, module Snippets) only appear for
 * instances the administrator's group has access to (Snippets::allowedPrefixes());
 * resolve returns "not found" for others.
 */
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Modules\Snippets\Snippets;
use Camindo\Core\SnippetCache;

$cms = $app->get(Cms::class);
$config = $app->get(Config::class);

// Prefix custom.{id} allowed? (module Snippets not active: no instance snippets)
$customPrefixes = $cms->componentPath('modules', 'Snippets') !== null ? $app->get(Snippets::class)->allowedPrefixes() : [];
$customAllowed = function (string $prefix) use ($customPrefixes): bool {
    if ($prefix !== SnippetCache::CUSTOM_PREFIX && !str_starts_with($prefix, SnippetCache::CUSTOM_PREFIX . '.')) return true;
    return in_array($prefix, $customPrefixes, true);
};

header('Content-Type: application/json; charset=utf-8');
$json = fn(array $data) => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (!empty($_REQUEST['texts'])) {
    $texts = [];
    foreach (['speak_open', 'speak_close', 'placeholder_search', 'label_results', 'label_more', 'label_no_results', 'label_backend', 'label_icu', 'label_not_found', 'label_none'] as $key) {
        $texts[$key] = $cms->snippet('ext.snippets.' . $key);
    }
    echo $json(['texts' => $texts]);
    exit;
}

$resolve = trim((string)($_REQUEST['resolve'] ?? ''));
if ($resolve !== '') {
    $resolve = ltrim($resolve, '#');
    $pos = strrpos($resolve, '.');
    $ok = preg_match('/^[a-z0-9_.]+$/', $resolve) && $customAllowed($pos === false ? '' : substr($resolve, 0, $pos));
    $text = $ok ? $cms->snippet($resolve) : $resolve;
    echo $json(['id' => $resolve, 'text' => $text, 'found' => $text !== $resolve]);
    exit;
}

$q = mb_strtolower(trim((string)($_REQUEST['q'] ?? '')));
$terms = $q === '' ? [] : preg_split('/\s+/', $q);
$limit = 50;

// Read the cache of the current language: {prefix}.php and {prefix}.backend.php.
// A key that appears in both counts once (frontend wins, as at
// runtime).
$lang = $cms->getLang();
$dir = $config->get('paths.var_dir') . '/cache/lang/' . $lang;
$index = [];
foreach ((array)glob($dir . '/*.php') as $file) {
    $name = basename($file, '.php');
    $backend = str_ends_with($name, '.backend');
    $prefix = $backend ? substr($name, 0, -8) : $name;
    if (!$customAllowed($prefix)) continue;
    $snippets = (include $file);
    if (!is_array($snippets)) continue;
    foreach ($snippets as $key => $text) {
        $id = ($prefix === Cms::CORE_PREFIX ? '' : $prefix . '.') . $key;
        if (isset($index[$id]) && $backend) continue;
        $icu = $text instanceof \Closure;
        if ($icu) $text = (string)$text([]);
        $index[$id] = ['id' => $id, 'text' => (string)$text, 'backend' => $backend, 'icu' => $icu];
    }
}
ksort($index, SORT_STRING);

$hits = [];
$total = 0;
foreach ($index as $id => $entry) {
    if ($terms) {
        $haystack = mb_strtolower($id . ' ' . $entry['text']);
        foreach ($terms as $term) {
            if (!str_contains($haystack, $term)) continue 2;
        }
    }
    $total++;
    if (count($hits) < $limit) $hits[] = $entry;
}

echo $json(['hits' => $hits, 'total' => $total]);
exit;
