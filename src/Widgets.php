<?php
/**
 * Startseiten-Widgets der Module: welche es gibt, wer sie sehen darf, wie sie
 * gerendert werden. Instanzname im Code: $widgets.
 *
 * DIE STARTSEITE HAT VIER PLÄTZE
 * --------------------
 * Position 1 (links oben) ist fest mit den Modulkacheln belegt. Die Plätze 2 bis 4
 * füllt jeder Admin selbst. In seinen persönlichen Einstellungen (`admin`.`settings`)
 * steht dafür unter 'widgets' eine LISTE:
 *
 *     [['module' => 'Logfiles', 'position' => 2],
 *      ['module' => 'User',     'position' => 2], …]
 *
 * In einen Platz passen also beliebig viele Widgets; sie erscheinen dort in der
 * Reihenfolge der Liste, also in der, in der sie hinzugefügt wurden. Ein Modul kommt
 * höchstens einmal vor - deshalb bietet die Auswahlliste der Einstellungen nur an,
 * was noch nicht drin ist. StartBackend::start() fragt hier nach dem fertigen HTML.
 *
 * WAS EIN MODUL MITBRINGEN MUSS
 * --------------------
 * Einen Abschnitt 'widget' in seiner install/settings.php:
 *
 *     'widget' => [
 *         'class'  => 'UserBackend',            // relativ zum Modul-Namespace
 *         'method' => 'widget',                 // Standard: 'widget'
 *         'label'  => '#user.widget_label',     // Standard: '#{modul}.module_label'
 *         'icon'   => 'rate_review',            // Material-Symbol, optional
 *     ],
 *
 * Der Klassenname ist bewusst RELATIV: so kann eine fremde settings.php nicht
 * irgendeine Klasse des Systems instanziieren, sondern nur eine aus dem eigenen
 * Modul. Die Klasse holt der Container (Autowiring), die Methode bekommt keine
 * Parameter und gibt das HTML des Widget-Inhalts als String ZURÜCK - sie gibt
 * nichts aus. Die Karte drumherum (Überschrift, Rahmen) kommt von hier.
 *
 * RECHTE
 * --------------------
 * Ein Widget zeigt nur, wer auch das Modul darf (Access::module(), damit auch die
 * Superuser-Regel aus settings.php 'superuser'). Das gilt für die Auswahlliste in
 * den Einstellungen wie für das Rendern: eine Einstellung, die nach einem
 * Gruppenwechsel nicht mehr erlaubt ist, liefert einfach nichts.
 *
 * FEHLER EINES WIDGETS BLEIBEN IM WIDGET
 * --------------------
 * render() fängt jeden Throwable ab und macht daraus eine Fehlermeldung an Ort und
 * Stelle - ein kaputtes Widget darf die Startseite nicht mitreißen.
 *
 * @see Camindo\Core\Modules\Start\StartBackend::start()      Ausgabe der vier Plätze
 * @see Camindo\Core\Modules\Admin\AdminBackend::settings()   Auswahl je Platz
 * @see Camindo\Core\ComponentManager                         'widget' in settings.php
 */
namespace Camindo\Core;

class Widgets {

    /** Plätze der Startseite, die frei belegt werden können (1 = Modulkacheln) */
    public const POSITIONS = [2, 3, 4];

    /** Schlüssel der persönlichen Einstellung (`admin`.`settings`) */
    public const SETTINGS_KEY = 'widgets';

    public function __construct(
        private Cms $cms,
        private Backend $backend,
        private ContainerInterface $container,
    )
    {}

    /**
     * Alle Widgets, die der angemeldete Admin einbinden darf.
     *
     * @return array  Modulname => ['label' => string, 'icon' => string]
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
        // Reihenfolge der Auswahlliste: nach Beschriftung, nicht nach Verzeichnis
        uasort($list, fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        return $list;
    }

    /**
     * Auswahlliste "Widget" der persönlichen Einstellungen.
     *
     * @param array $exclude  Modulnamen, die schon eingehängt sind - sie fehlen in
     *                        der Liste, weil ein Modul nur einmal vorkommen darf
     * @return array          Modulname => Beschriftung
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
     * Auswahlliste "Position": die frei belegbaren Plätze.
     *
     * @return array  Platz => Beschriftung (Snippet start.position_{n})
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
     * Die gespeicherte Liste in Form bringen: nur wohlgeformte Einträge, jedes Modul
     * höchstens einmal, Reihenfolge bleibt.
     *
     * Was hier wegfällt, ist entweder unsinnig (unbekannter Platz, leerer Name) oder
     * inzwischen verschwunden (Modul deinstalliert). Ein Eintrag, dessen Modul es
     * nicht mehr gibt, soll das Speichern anderer Einstellungen NICHT scheitern
     * lassen - deshalb lässt sich die Existenzprüfung abschalten: Admin::hookOnSave()
     * prüft damit nur die Form, gezeigt wird später ohnehin nur, was es gibt.
     *
     * @param array $list           Rohwert aus den Einstellungen bzw. dem Formular
     * @param bool  $requireExists  false: Module, die es nicht (mehr) gibt, bleiben drin
     * @return array                Liste aus ['module' => string, 'position' => int]
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
     * Die eingehängten Widgets eines Admins nach Platz sortiert - für die Startseite.
     *
     * @param array $settings  `admin`.`settings` (Backend::adminSettings())
     * @return array           Platz => Liste von Modulnamen, jeder Platz vorhanden
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
     * Beschriftung eines Widgets - für die Liste in den Einstellungen.
     * Unbekanntes Modul: der Modulname selbst.
     */
    public function label(string $module): string
    {
        $widget = $this->definition($module);
        return $widget === null ? $module : $this->cms->textOrSnippet($widget['label']);
    }

    /**
     * Gibt es dieses Widget überhaupt? Ohne Rechteprüfung - für das Prüfen einer
     * gespeicherten Einstellung (Admin::hookOnSave()), die auch ein Superuser für
     * einen anderen Admin setzen darf. Ob sie sich zeigt, entscheidet render().
     */
    public function exists(string $module): bool
    {
        return $this->definition($module) !== null;
    }

    /**
     * Gibt es dieses Widget und darf der angemeldete Admin es sehen?
     */
    public function allowed(string $module): bool
    {
        if ($this->definition($module) === null) return false;
        return $this->backend->access()->module($module);
    }

    /**
     * Ein Widget als fertige Karte.
     *
     * @param string $module  Modulname aus der Einstellung - ungeprüft
     * @return string         HTML, leer wenn es das Widget nicht (mehr) gibt oder
     *                        der Admin das Modul nicht darf
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
            // Ein Widget darf die Startseite nicht mitreißen (siehe Klassenkommentar).
            // Die Ausnahme selbst gehört ins Log, nicht in die Oberfläche.
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
     * Die 'widget'-Angabe eines Moduls, auf Vollständigkeit gebracht.
     *
     * @return ?array  'class' (voller Klassenname), 'method', 'label', 'icon';
     *                 null, wenn das Modul kein Widget anbietet
     */
    private function definition(string $module): ?array
    {
        $settings = $this->cms->modules[$module] ?? null;
        if ($settings === null) return null;
        $widget = $settings['widget'] ?? null;
        if (!is_array($widget) || empty($widget['class'])) return null;

        // Nur Klassen des eigenen Moduls - der Name ist relativ (Klassenkommentar)
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
     * Den Inhalt eines Widgets erzeugen.
     *
     * @throws \RuntimeException  Klasse oder Methode fehlt
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
