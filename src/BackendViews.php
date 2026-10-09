<?php
/**
 * Verzeichnis der Backend-Ansichten, die man öffnen kann - für Werkzeuge, die dem
 * Admin einen Layer öffnen (der Assistent des Moduls Ai, ui__open).
 *
 * KATALOG
 * --------------------
 * Jede Komponente beschreibt ihre Ansichten in install/views.php - nur Scripte, die
 * beim Öffnen nichts ändern (Listen, Detailansichten, Formulare, die erst beim
 * Absenden wirken), keine Aktionen und keine ajax-Scripte:
 *
 *     return [
 *         'field_list.php' => [
 *             'description' => 'Input fields of an area …',     // englisch, für ein LLM
 *             'params'      => ['area_id' => 'int'],            // erlaubte Parameter
 *         ],
 *         'field_edit.php' => [
 *             'description' => '…',
 *             'params'      => ['id' => 'int', 'area_id' => 'int', 'function' => ['create', 'copy', 'delete']],
 *         ],
 *     ];
 *
 * Parametertypen: 'int' (positive Ganzzahl), 'string' (Text bis 200 Zeichen) oder eine
 * Liste erlaubter Werte. Andere Parameter werden verworfen.
 *
 * RECHTE
 * --------------------
 * available() zeigt nur, was der angemeldete Admin öffnen darf - dieselbe Prüfung wie
 * beim Aufruf (Access::script(): Modulzugriff, Zusatzrechte aus 'script_access',
 * persönliche Scripte). Rechte innerhalb einer Ansicht (Bereich, Datensatz) prüft die
 * Ansicht selbst.
 */
namespace Camindo\Core;

class BackendViews
{
    /** Datei des Katalogs im Verzeichnis der Komponente */
    public const FILE = 'install/views.php';

    private ?array $all = null;

    public function __construct(
        private Cms $cms,
        private Access $access,
    )
    {
    }

    /**
     * Alle Ansichten der aktiven Module.
     *
     * @return array  'Modul/script.php' => module, script, label (Modul, Klartext),
     *                description, params
     */
    public function all(): array
    {
        if ($this->all !== null) return $this->all;
        $views = [];
        foreach ($this->cms->modules as $module => $settings) {
            $file = rtrim((string)($settings['path'] ?? ''), '/') . '/' . self::FILE;
            if (!is_file($file)) continue;
            $catalog = include $file;
            if (!is_array($catalog)) continue;
            $label = trim(strip_tags($this->cms->textOrSnippet((string)($settings['label'] ?? $module))));
            foreach ($catalog as $script => $entry) {
                if (!is_string($script) || !preg_match('/^[a-z0-9_]+\.php$/', $script)) continue;
                $views[$module . '/' . $script] = [
                    'module'      => (string)$module,
                    'script'      => $script,
                    'label'       => $label,
                    'description' => (string)($entry['description'] ?? ''),
                    'params'      => (array)($entry['params'] ?? []),
                ];
            }
        }
        ksort($views);
        return $this->all = $views;
    }

    /** Die Ansichten, die der angemeldete Admin öffnen darf */
    public function available(): array
    {
        return array_filter($this->all(), fn($view) => $this->access->script($view['module'], $view['script']));
    }

    /**
     * Adresse einer Ansicht für cApp.layers.add() - nur mit erlaubten Parametern.
     *
     * @param string $view    'Modul/script.php'
     * @param array  $params  Parameter (unbekannte und ungültige werden verworfen)
     * @return ?string        null = unbekannt oder nicht erlaubt
     */
    public function url(string $view, array $params = []): ?string
    {
        $entry = $this->available()[$view] ?? null;
        if ($entry === null) return null;
        $query = [];
        foreach ($entry['params'] as $name => $type) {
            if (!array_key_exists($name, $params) || !is_scalar($params[$name])) continue;
            $value = (string)$params[$name];
            $ok = match (true) {
                is_array($type) => in_array($value, array_map('strval', $type), true),
                $type === 'int' => ctype_digit($value) && (int)$value > 0,
                default         => $value !== '' && mb_strlen($value) <= 200,
            };
            if ($ok) $query[$name] = $value;
        }
        return $view . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }
}
