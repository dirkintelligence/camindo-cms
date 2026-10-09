<?php
/**
 * Start page widgets of the modules: which ones exist, who may see them, how they are
 * rendered. Instance name in the code: $widgets.
 *
 * THE START PAGE HAS FOUR SLOTS
 * --------------------
 * Position 1 (top left) is permanently filled with the module tiles. Slots 2 to 4
 * are filled by each admin themselves. In their personal settings (`admin`.`settings`)
 * there is a LIST under 'widgets' for this:
 *
 *     [['module' => 'Logfiles', 'position' => 2],
 *      ['module' => 'User',     'position' => 2], …]
 *
 * So any number of widgets fit into a slot; they appear there in the order of the
 * list, i.e. in the order in which they were added. A module occurs at most once -
 * that is why the selection list of the settings only offers what is not in yet.
 * StartBackend::start() asks here for the finished HTML.
 *
 * WHAT A MODULE MUST PROVIDE
 * --------------------
 * A section 'widget' in its install/settings.php:
 *
 *     'widget' => [
 *         'class'  => 'UserBackend',            // relative to the module namespace
 *         'method' => 'widget',                 // Standard: 'widget'
 *         'label'  => '#user.widget_label',     // Standard: '#{modul}.module_label'
 *         'icon'   => 'rate_review',            // Material-Symbol, optional
 *     ],
 *
 * The class name is deliberately RELATIVE: this way a foreign settings.php cannot
 * instantiate an arbitrary class of the system, only one from its own
 * module. The container fetches the class (autowiring), the method takes no
 * parameters and RETURNS the HTML of the widget content as a string - it does not
 * output anything. The card around it (heading, frame) comes from here.
 *
 * PERMISSIONS
 * --------------------
 * A widget is only shown to whoever may also use the module (Access::module(), which
 * includes the superuser rule from settings.php 'superuser'). This applies to the
 * selection list in the settings as well as to rendering: a setting that is no longer
 * allowed after a group change simply yields nothing.
 *
 * ERRORS OF A WIDGET STAY IN THE WIDGET
 * --------------------
 * render() catches every Throwable and turns it into an error message in place -
 * a broken widget must not take down the start page.
 *
 * @see Camindo\Core\Modules\Start\StartBackend::start()      Output of the four slots
 * @see Camindo\Core\Modules\Admin\AdminBackend::settings()   Selection per slot
 * @see Camindo\Core\ComponentManager                         'widget' in settings.php
 */
namespace Camindo\Core;

class Widgets {

    /** Start page slots that can be filled freely (1 = module tiles) */
    public const POSITIONS = [2, 3, 4];

    /** Key of the personal setting (`admin`.`settings`) */
    public const SETTINGS_KEY = 'widgets';

    public function __construct(
        private Cms $cms,
        private Backend $backend,
        private ContainerInterface $container,
    )
    {}

    /**
     * All widgets the logged-in admin may add.
     *
     * @return array  Module name => ['label' => string, 'icon' => string]
     */
    public function available(array $exclude = []): array
    {
        $list = [];
        $access = $this->backend->access();
        foreach ($this->cms->modules as $moduleName => $moduleSettings) {
            $moduleName = (string)$moduleName;
            if (in_array($moduleName, $exclude, true)) continue;
            $widget = $this->definition($moduleName);
            if ($widget === null) continue;
            if (!$access->module($moduleName)) continue;
            $list[$moduleName] = [
                'label' => $this->cms->textOrSnippet($widget['label']),
                'icon'  => $widget['icon'],
            ];
        }
        // Order of the selection list: by label, not by directory
        uasort($list, fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        return $list;
    }

    /**
     * Selection list "Widget" of the personal settings.
     *
     * @param array $exclude  Module names that are already added - they are missing from
     *                        the list, because a module may only occur once
     * @return array          Module name => label
     */
    public function options(array $exclude = []): array
    {
        $options = [];
        foreach ($this->available($exclude) as $moduleName => $widget) {
            $options[$moduleName] = $widget['label'];
        }
        return $options;
    }

    /**
     * Selection list "Position": the slots that can be filled freely.
     *
     * @return array  Slot => label (snippet start.position_{n})
     */
    public function positionOptions(): array
    {
        $options = [];
        foreach (self::POSITIONS as $position) {
            $options[$position] = $this->cms->snippet('start.position_' . $position);
        }
        return $options;
    }

    /**
     * Bring the stored list into shape: only well-formed entries, each module
     * at most once, order is kept.
     *
     * What is dropped here is either nonsense (unknown slot, empty name) or
     * has since disappeared (module uninstalled). An entry whose module no longer
     * exists must NOT make saving other settings fail - that is why the existence check
     * can be switched off: Admin::hookOnSave() then only checks the form,
     * since only what exists is shown later anyway.
     *
     * @param array $list           Raw value from the settings or the form
     * @param bool  $requireExists  false: modules that no longer exist (or do not yet) stay in
     * @return array                List of ['module' => string, 'position' => int]
     */
    public function normalize(array $list, bool $requireExists = true): array
    {
        $result = [];
        $seen = [];
        foreach ($list as $item) {
            if (!is_array($item)) continue;
            $module = trim((string)($item['module'] ?? ''));
            $position = (int)($item['position'] ?? 0);
            if ($module === '' || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $module)) continue;
            if (!in_array($position, self::POSITIONS, true)) continue;
            if (isset($seen[$module])) continue;
            if ($requireExists && !$this->exists($module)) continue;
            $seen[$module] = true;
            $result[] = ['module' => $module, 'position' => $position];
        }
        return $result;
    }

    /**
     * The widgets added by an admin, sorted by slot - for the start page.
     *
     * @param array $settings  `admin`.`settings` (Backend::adminSettings())
     * @return array           Slot => list of module names, every slot present
     */
    public function byPosition(array $settings): array
    {
        $byPosition = array_fill_keys(self::POSITIONS, []);
        foreach ($this->normalize((array)($settings[self::SETTINGS_KEY] ?? [])) as $item) {
            $byPosition[$item['position']][] = $item['module'];
        }
        return $byPosition;
    }

    /**
     * Label of a widget - for the list in the settings.
     * Unknown module: the module name itself.
     */
    public function label(string $module): string
    {
        $widget = $this->definition($module);
        return $widget === null ? $module : $this->cms->textOrSnippet($widget['label']);
    }

    /**
     * Does this widget exist at all? Without a permission check - for checking a
     * stored setting (Admin::hookOnSave()), which a superuser may also set for
     * another admin. Whether it is shown is decided by render().
     */
    public function exists(string $module): bool
    {
        return $this->definition($module) !== null;
    }

    /**
     * Does this widget exist and may the logged-in admin see it?
     */
    public function allowed(string $module): bool
    {
        if ($this->definition($module) === null) return false;
        return $this->backend->access()->module($module);
    }

    /**
     * A widget as a finished card.
     *
     * @param string $module  Module name from the setting - unchecked
     * @return string         HTML, empty if the widget does not (or no longer) exist or
     *                        the admin may not use the module
     */
    public function render(string $module): string
    {
        $widget = $this->definition($module);
        if ($widget === null || !$this->backend->access()->module($module)) return '';

        $title = $this->cms->textOrSnippet($widget['label']);
        $icon = $widget['icon'] !== ''
            ? $this->backend->template('icon', ['{name}' => $this->backend->escape($widget['icon'])]) . ' '
            : '';

        try {
            $body = $this->body($module, $widget);
        }
        catch (\Throwable $e) {
            // A widget must not take down the start page (see class comment).
            // The exception itself belongs in the log, not in the user interface.
            $this->container->get(System::class)->log('widget-errors', "$module: " . $e->getMessage());
            $body = $this->backend->template('alert_danger', [
                '{text}' => $this->cms->snippet('start.error_widget_failed'),
            ]);
        }

        return $this->backend->template('start.widget', [
            '{title}' => $icon . htmlspecialchars($title),
            '{body}'  => $body,
        ]);
    }

    // ====================

    /**
     * The 'widget' entry of a module, completed.
     *
     * @return ?array  'class' (full class name), 'method', 'label', 'icon';
     *                 null if the module offers no widget
     */
    private function definition(string $module): ?array
    {
        $settings = $this->cms->modules[$module] ?? null;
        if ($settings === null) return null;
        $widget = $settings['widget'] ?? null;
        if (!is_array($widget) || empty($widget['class'])) return null;

        // Only classes of the module itself - the name is relative (class comment)
        $class = trim((string)$widget['class'], '\\');
        if ($class === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) return null;
        $namespace = (string)($settings['namespace'] ?? '');
        if ($namespace === '') return null;

        return [
            'class'  => $namespace . '\\' . $class,
            'method' => (string)($widget['method'] ?? 'widget') ?: 'widget',
            'label'  => (string)($widget['label'] ?? '') ?: '#' . strtolower($module) . '.module_label',
            'icon'   => (string)($widget['icon'] ?? ''),
        ];
    }

    /**
     * Build the content of a widget.
     *
     * @throws \RuntimeException  class or method is missing
     */
    private function body(string $module, array $widget): string
    {
        if (!class_exists($widget['class'])) {
            throw new \RuntimeException("widget class {$widget['class']} not found");
        }
        $instance = $this->container->get($widget['class']);
        if (!is_callable([$instance, $widget['method']])) {
            throw new \RuntimeException("widget method {$widget['class']}::{$widget['method']}() not callable");
        }
        return (string)$instance->{$widget['method']}();
    }
}
