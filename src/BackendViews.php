<?php
/**
 * Directory of the backend views that can be opened - for tools that open a layer
 * for the admin (the assistant of the Ai module, ui__open).
 *
 * CATALOG
 * --------------------
 * Each component describes its views in install/views.php - only scripts that
 * change nothing when opened (lists, detail views, forms that only take effect
 * on submit), no actions and no ajax scripts:
 *
 *     return [
 *         'field_list.php' => [
 *             'description' => 'Input fields of an area …',     // English, for an LLM
 *             'params'      => ['area_id' => 'int'],            // erlaubte Parameter
 *         ],
 *         'field_edit.php' => [
 *             'description' => '…',
 *             'params'      => ['id' => 'int', 'area_id' => 'int', 'function' => ['create', 'copy', 'delete']],
 *         ],
 *     ];
 *
 * Parameter types: 'int' (positive integer), 'string' (text up to 200 characters) or a
 * list of allowed values. Other parameters are discarded.
 *
 * PERMISSIONS
 * --------------------
 * available() only shows what the logged-in admin may open - the same check as on
 * the call (Access::script(): module access, additional rights from 'script_access',
 * personal scripts). Rights within a view (area, record) are checked by the view
 * itself.
 */
namespace Camindo\Core;

class BackendViews
{
    /** Catalog file in the component's directory */
    public const FILE = 'install/views.php';

    private ?array $all = null;

    public function __construct(
        private Cms $cms,
        private Access $access,
    )
    {
    }

    /**
     * All views of the active modules.
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

    /** The views the logged-in admin may open */
    public function available(): array
    {
        return array_filter($this->all(), fn($view) => $this->access->script($view['module'], $view['script']));
    }

    /**
     * Address of a view for cApp.layers.add() - only with allowed parameters.
     *
     * @param string $view    'Modul/script.php'
     * @param array  $params  Parameters (unknown and invalid ones are discarded)
     * @return ?string        null = unknown or not allowed
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
