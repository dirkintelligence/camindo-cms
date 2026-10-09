<?php
/**
 * Snippets suchen bzw. eine Snippet-Id auflösen - JSON für snippets.ext.js (13 §3.6):
 * backendExtensions/Snippets/search.php; Session ist durch Backend::backendPath()
 * geprüft, Datensätze werden nicht geliefert.
 *
 *     ?q=such begriffe      Treffer aus dem Snippet-Cache der Backend-Sprache des
 *                           Admins: alle Präfixe, Frontend- UND Backend-Dateien.
 *                           Jeder Begriff muss in Id oder Text vorkommen (ohne
 *                           Groß/Klein). Antwort: {hits: [{id, text, backend,
 *                           icu}], total}
 *     ?resolve=record.x     Text zur Id, wie Cms::snippet() ihn liefert:
 *                           {id, text, found}
 *     ?texts=1              nur die Texte der Oberfläche: {texts}
 *
 * Die Id ist das, was hinter dem '#' steht: {prefix}.{key}, für Core-Snippets nur
 * {key} (Cms::snippet() ohne Punkt = Core). ICU-Snippets (Closures im Cache) werden
 * mit leeren Argumenten ausgewertet und als solche markiert.
 *
 * Instanz-Snippets (custom.{instance_id}.*, Modul Snippets) erscheinen nur für
 * Instanzen, auf die die Gruppe des Admins Zugriff hat (Snippets::allowedPrefixes());
 * resolve liefert für andere "nicht gefunden".
 */
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Modules\Snippets\Snippets;
use Camindo\Core\SnippetCache;

$cms = $app->get(Cms::class);
$config = $app->get(Config::class);

// Präfix custom.{id} erlaubt? (Modul Snippets nicht aktiv: keine Instanz-Snippets)
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

// Cache der aktuellen Sprache einlesen: {prefix}.php und {prefix}.backend.php.
// Ein Schlüssel, der in beiden steht, zählt einmal (Frontend gewinnt, wie zur
// Laufzeit).
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
