<?php
/**
 * Icon-Index und Texte für die Icon-Auswahl als JSON (13 §3.6) - Aufruf aus
 * iconpicker.ext.js: backendExtensions/IconPicker/index.php; Session ist durch
 * Backend::backendPath() geprüft.
 *
 * Quelle ist data/reduced-index.json (erzeugt von tools/reduce-search-index.py aus
 * dem Suchindex der Google-Material-Icons-Website). Antwort:
 *     texts       Snippets ext.iconpicker.* für die Oberfläche (Sprache des Admins)
 *     categories  [{name, label}] in der Reihenfolge des Index, Label aus
 *                 ext.iconpicker.category_{name} (fehlt das Snippet: der Name)
 *     icons       [{name, categories, tags}] wie im Index
 */
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Cms;

$cms = $app->get(Cms::class);
$file = dirname(__DIR__) . '/data/reduced-index.json';
$index = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
if (!is_array($index) || !isset($index['icons'])) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $cms->snippet('ext.iconpicker.error_load')]);
    exit;
}

$categories = [];
foreach ($index['icons'] as $icon) {
    foreach ((array)($icon['categories'] ?? []) as $category) {
        $category = (string)$category;
        if (isset($categories[$category])) continue;
        $snippetId = 'ext.iconpicker.category_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($category));
        $label = $cms->snippet($snippetId);
        $categories[$category] = ['name' => $category, 'label' => $label === $snippetId ? $category : $label];
    }
}
ksort($categories);

$texts = [];
foreach (['speak_open', 'speak_close', 'speak_prev', 'speak_next', 'placeholder_search', 'label_results', 'label_no_results', 'label_none'] as $key) {
    $texts[$key] = $cms->snippet('ext.iconpicker.' . $key);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=3600');
echo json_encode([
    'texts'      => $texts,
    'categories' => array_values($categories),
    'icons'      => $index['icons'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
