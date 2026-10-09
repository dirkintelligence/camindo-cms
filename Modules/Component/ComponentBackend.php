<?php
/**
 * Backend-Oberfläche der Komponentenverwaltung - die GUI zu ComponentManager.
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     component_list.php       -> list()      drei Tabs (Module, Prozessoren, Extensions),
 *                                             Zustand je Komponente, Kontextmenü mit den
 *                                             im Zustand zulässigen Aktionen
 *     component_info.php       -> info()      Detailansicht mit Aktionsbuttons
 *     component_action.php     -> action()    Bestätigung + Optionen, dann Ausführung mit
 *                                             Protokoll (install, activate, deactivate,
 *                                             update, uninstall)
 *     refresh_components.php   -> refresh()   Verzeichnisse abgleichen (im Aside)
 *
 * Es gibt keine Eingabemaske: Komponenten entstehen durch Hinlegen eines Verzeichnisses
 * (Composer), der Abgleich entdeckt sie, die Aktionen wechseln den Zustand. Dieselben
 * Methoden ruft die Kommandozeile (`php Core/cron/camindo.php component …`); nur der
 * Logger ist ein anderer - hier eine Liste im Layer bzw. Aside.
 *
 * Komponenten werden über ihren Schlüssel "kind/Name" angesprochen (Parameter name),
 * nicht über component_id - eine entdeckte Komponente hat noch keine Zeile.
 *
 * ZULÄSSIGE AKTIONEN JE ZUSTAND (14 §5.2)
 * --------------------
 *     core        keine
 *     discovered  install
 *     inactive    activate, uninstall
 *     active      deactivate, uninstall
 *     update      update, uninstall
 *     orphaned    uninstall (nur Zeile, Caches; --purge über component.tables)
 *     error       deactivate (falls aktiv), uninstall (falls installiert)
 *
 * @see Camindo\Core\ComponentManager
 * @see docs/developers/14-installation-und-update.md, Kapitel 5 und 6
 */
namespace Camindo\Core\Modules\Component;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\ComponentManager;

class ComponentBackend {

    private const ACTIONS = ['install', 'activate', 'deactivate', 'update', 'uninstall'];

    public function __construct(
        private Backend $backend,
        private Cms $cms,
        private ComponentManager $manager,
    )
    {
    }

    /**
     * Komponentenliste (backend/component_list.php).
     *
     * Request: type = modules|processors|extensions (Standard: modules)
     */
    public function list(): void
    {
        $type = (string)($_REQUEST['type'] ?? 'modules');
        if (!isset(ComponentManager::KINDS[$type])) $type = 'modules';

        $this->backend->setNaviLabel('#component.module_label');
        echo $this->backend->template('container_header');
        echo $this->backend->headline('#component.headline_component_list');

        // Tabs
        $this->backend->tabBarReset();
        foreach (ComponentManager::KINDS as $kind => $x) {
            $this->backend->tabAdd('#component.headline_' . $kind,
                $kind === $type ? '' : "cApp.layers.replace('Component/component_list.php?type=$kind')", $kind === $type);
        }
        echo $this->backend->tabBar();

        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_refresh', "cApp.aside.openPost('Component/refresh_components.php');");
        echo $this->backend->buttonBar();

        // Tabelle
        $headColumns = $this->backend->template('component.component_table_head_columns', [
            '{label_label}'   => $this->cms->snippet('component.field_label'),
            '{label_version}' => $this->cms->snippet('component.field_version'),
            '{label_core}'    => $this->cms->snippet('component.field_core'),
            '{label_state}'   => $this->cms->snippet('component.field_state'),
        ]);
        $icon = $this->_kindIcon($type);
        $tplRow = $this->backend->template('component.component_table_row');
        $rows = '';
        foreach ($this->manager->status() as $key => $entry) {
            if ($entry['kind'] !== $type) continue;
            $label = htmlspecialchars($this->cms->textOrSnippet((string)($entry['settings']['label'] ?? $entry['row']['label'] ?? $entry['name'])));
            if ($label !== $entry['name']) $label .= $this->backend->template('component.name_suffix', ['{name}' => $this->backend->escape($entry['name'])]);
            $hide = array_diff(self::ACTIONS, $this->_allowedActions($entry));
            $rows .= strtr($tplRow, [
                '{data_id}'      => htmlspecialchars($key),
                '{ctx_hide}'     => implode(',', $hide),
                '{data_label}'   => $icon . ' ' . $label,
                '{data_version}' => $this->_versionCell($entry),
                '{data_core}'    => $this->cms->snippet('component.label_' . ($entry['core'] ? 'is_core' : 'not_core')),
                '{data_state}'   => $this->_stateCell($entry),
            ]) . "\n";
        }
        echo $this->backend->template('component.component_table_with_head', [
            '{head_columns}' => $headColumns,
            '{table_rows}'   => $rows,
        ]);
        echo $this->backend->template('component.component_context_menu');
        echo $this->backend->template('container_footer');
    }

    /**
     * Detailansicht (backend/component_info.php). Request: name = kind/Name
     */
    public function info(): void
    {
        $this->backend->setNaviLabel('#button_info');
        echo $this->backend->template('container_header');

        $entry = $this->_entry();
        if ($entry === null) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->snippet('component.error_component_not_found')]);
            echo $this->backend->template('container_footer');
            return;
        }
        $key = $entry['kind'] . '/' . $entry['name'];
        $s = $entry['settings'];
        $row = $entry['row'];

        echo $this->backend->headline($this->_kindIcon($entry['kind']) . ' '
            . htmlspecialchars($this->cms->textOrSnippet((string)($s['label'] ?? $row['label'] ?? $entry['name']))));

        // Buttons: Schließen + zulässige Aktionen
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_close', 'cApp.layers.close()');
        foreach ($this->_allowedActions($entry) as $action) {
            $this->backend->buttonAdd('button_secondary',
                "cApp.layers.add('Component/component_action.php?action=$action&name=" . rawurlencode($key) . "')",
                $this->cms->snippet('component.button_' . $action));
        }
        echo $this->backend->buttonBar();

        // Reiter: Info | Änderungen (Audit-Log) - nur mit Zeile in `component`
        $componentId = (int)($row['component_id'] ?? 0);
        if ($componentId > 0
            && $this->backend->infoAuditTabs('Component/component_info.php?name=' . rawurlencode($key), 'component', $componentId)) {
            return;
        }

        // Daten
        $this->backend->infoReset();
        $this->backend->infoStatic('#component.field_name', htmlspecialchars($entry['name']));
        $this->backend->infoStatic('#component.field_kind', $this->cms->snippet('component.kind_' . $entry['kind']));
        $type = (string)($s['type'] ?? $row['type'] ?? '');
        if ($type !== '') $this->backend->infoStatic('#component.field_type', htmlspecialchars($type));
        $description = $this->cms->textOrSnippet((string)($s['description'] ?? $row['description'] ?? ''));
        if ($description !== '') $this->backend->infoStatic('#component.field_description', htmlspecialchars($description));
        $this->backend->infoStatic('#component.field_core', $this->cms->snippet('component.label_' . ($entry['core'] ? 'is_core' : 'not_core')));
        $this->backend->infoStatic('#component.field_state', $this->_stateCell($entry));
        if ($entry['errors']) {
            $this->backend->infoStatic('#component.field_errors',
                $this->backend->template('component.error_list', ['{items}' => '<li>' . implode('</li><li>', array_map([$this->backend, 'escape'], $entry['errors'])) . '</li>']));
        }
        if ($entry['path'] !== '') $this->backend->infoStatic('#component.field_version', htmlspecialchars($entry['version']));
        if (!$entry['core']) {
            $this->backend->infoStatic('#component.field_installed_version',
                $entry['installed_version'] !== '' ? htmlspecialchars($entry['installed_version']) : $this->cms->snippet('component.label_none'));
            if ($entry['update_version'] !== '') {
                $this->backend->infoStatic('#component.field_update_version', htmlspecialchars($entry['update_version']));
            }
        }
        if ($entry['path'] !== '') {
            $this->backend->infoStatic('#component.field_path', htmlspecialchars($entry['path']), ['monospace']);
            $this->backend->infoStatic('#component.field_namespace', htmlspecialchars($entry['namespace']), ['monospace']);
        }
        $requires = $this->_requiresList($entry);
        if ($requires !== '') $this->backend->infoStatic('#component.headline_requires', $requires);
        $tables = $this->_tables($entry);
        if ($tables) $this->backend->infoStatic('#component.field_tables', htmlspecialchars(implode(', ', $tables)), ['monospace']);
        if (!empty($row['installed_at'])) $this->backend->infoStatic('#component.field_installed_at', htmlspecialchars($row['installed_at']) . ' UTC');
        if (!empty($row['updated_at'])) $this->backend->infoStatic('#component.field_updated_at', htmlspecialchars($row['updated_at']) . ' UTC');
        echo $this->backend->infoTable();

        echo $this->backend->template('container_footer');
    }

    /**
     * Aktion bestätigen und ausführen (backend/component_action.php).
     *
     * Request: name = kind/Name, action = install|activate|deactivate|update|uninstall,
     *          function = '' (Formular) | run (ausführen; Optionen activate, purge,
     *          force, backup aus dem Formular)
     */
    public function action(): void
    {
        $action = (string)($_REQUEST['action'] ?? '');
        $function = (string)($_REQUEST['function'] ?? '');

        $this->backend->setNaviLabel('#component.button_' . $action);
        echo $this->backend->template('container_header');

        $entry = $this->_entry();
        if ($entry === null) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->snippet('component.error_component_not_found')]);
            echo $this->backend->template('container_footer');
            return;
        }
        if (!in_array($action, self::ACTIONS, true)) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->snippet('component.error_unknown_action')]);
            echo $this->backend->template('container_footer');
            return;
        }
        $key = $entry['kind'] . '/' . $entry['name'];
        echo $this->backend->headline($this->_kindIcon($entry['kind']) . ' '
            . $this->cms->snippet('component.headline_action_' . $action) . ': <strong>' . htmlspecialchars($entry['name']) . '</strong>');

        if (!in_array($action, $this->_allowedActions($entry), true)) {
            echo $this->backend->template('alert_danger', ['{text}' => str_replace('{state}',
                $this->cms->snippet('component.state_' . $entry['state']), $this->cms->snippet('component.error_action_not_allowed'))]);
            echo $this->backend->template('container_footer');
            return;
        }

        if ($function === 'run') {
            $this->_actionRun($entry, $action);
        }
        else {
            $this->_actionForm($entry, $action);
        }
        echo $this->backend->template('container_footer');
    }

    /**
     * Verzeichnisse abgleichen (backend/refresh_components.php) - im Aside, Fortschritt
     * des ComponentManagers als Liste; danach wird die Liste neu geladen.
     */
    public function refresh(): void
    {
        echo $this->backend->template('headline4', ['{text}' => $this->cms->snippet('component.headline_refresh')]);
        // Schreibt den Zustand der Komponenten fort - nur per POST (17-csrf.md §2.7)
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }
        $lines = [];
        $status = $this->manager->refresh(function (string $line) use (&$lines): void {
            $lines[] = htmlspecialchars($line);
        });
        if ($lines) {
            echo $this->backend->template('ul', ['{items}' => '<li>' . implode("</li>\n<li>", $lines) . '</li>']);
        }
        foreach ($status as $entry) {
            if ($entry['errors']) {
                echo $this->backend->template('alert_danger', [
                    '{text}' => htmlspecialchars($entry['kind'] . '/' . $entry['name'] . ': ' . implode('; ', $entry['errors']))
                ]);
            }
        }
        echo $this->backend->template('alert_success', ['{text}' => $this->cms->snippet('component.message_refreshed')]);
        $this->backend->script("$('#aside-content').scrollTop(9999);");
        $this->backend->balloon('success', $this->cms->snippet('component.message_refreshed'));
        $this->backend->script("cApp.layers.refresh();");
    }

    // ====================
    // action() Hilfsfunktionen

    /**
     * Bestätigungsformular: Erklärung, Hinweise, Optionen je Aktion.
     */
    private function _actionForm(array $entry, string $action): void
    {
        $key = $entry['kind'] . '/' . $entry['name'];
        $explain = $this->cms->snippet('component.explain_' . ($entry['state'] === 'orphaned' ? 'orphaned' : $action));
        $explain = strtr($explain, ['{from}' => htmlspecialchars($entry['installed_version']), '{to}' => htmlspecialchars($entry['update_version'])]);

        $notes = '';
        $options = '';
        $dependents = $action === 'deactivate' || $action === 'uninstall' ? $this->manager->dependents($entry) : [];
        if ($dependents && $action === 'uninstall') {
            $notes .= $this->backend->template('alert_warning', ['{text}' => str_replace('{dependents}',
                htmlspecialchars(implode(', ', $dependents)), $this->cms->snippet('component.note_dependents'))]);
        }
        if ($action === 'install') {
            $options .= $this->_checkbox('activate', $this->cms->snippet('component.option_activate'), true);
        }
        if ($action === 'deactivate' && $dependents) {
            $notes .= $this->backend->template('alert_warning', ['{text}' => str_replace('{dependents}',
                htmlspecialchars(implode(', ', $dependents)), $this->cms->snippet('component.note_dependents'))]);
            $options .= $this->_checkbox('force', str_replace('{dependents}', htmlspecialchars(implode(', ', $dependents)),
                $this->cms->snippet('component.option_force')), false);
        }
        if ($action === 'uninstall') {
            $tables = $this->_tables($entry);
            if ($tables) {
                $options .= $this->_checkbox('purge', str_replace('{tables}', htmlspecialchars(implode(', ', $tables)),
                    $this->cms->snippet('component.option_purge')), false)
                    . $this->backend->template('component.note_purge');
            }
        }
        if (in_array($action, ['install', 'update', 'uninstall'], true)) {
            $options .= $this->_checkbox('backup', $this->cms->snippet('component.option_backup'), true);
        }
        if ($options !== '') {
            $options = $this->backend->template('component.options_headline') . $options;
        }

        echo '<form action="Component/component_action.php" method="post">';
        $this->backend->formInit('component.action_form', [
            '{explain}'      => $explain,
            '{notes}'        => $notes,
            '{options}'      => $options,
            '{button_class}' => $action === 'uninstall' ? 'btn-danger' : 'btn-primary',
            '{button_icon}'  => ['install' => 'download', 'activate' => 'check', 'deactivate' => 'block', 'update' => 'build', 'uninstall' => 'delete'][$action],
            '{button_label}' => $this->cms->snippet('component.button_' . $action),
        ]);
        $this->backend->formHidden('name', $key);
        $this->backend->formHidden('action', $action);
        $this->backend->formHidden('function', 'run');
        echo $this->backend->form();
        echo '</form>';
    }

    /**
     * Ausführen: ComponentManager-Methode mit Listen-Logger, Ergebnis als Alert,
     * darunter das Protokoll; die Liste darunter wird neu geladen.
     */
    private function _actionRun(array $entry, string $action): void
    {
        $key = $entry['kind'] . '/' . $entry['name'];
        $lines = [];
        $log = function (string $line) use (&$lines): void { $lines[] = htmlspecialchars($line); };
        $backup = !empty($_REQUEST['backup']);
        $error = '';
        try {
            switch ($action) {
                case 'install':    $this->manager->install($key, $log, !empty($_REQUEST['activate']), $backup); break;
                case 'activate':   $this->manager->activate($key, $log); break;
                case 'deactivate': $this->manager->deactivate($key, $log, !empty($_REQUEST['force'])); break;
                case 'update':     $this->manager->update($key, $log, $backup); break;
                case 'uninstall':  $this->manager->uninstall($key, $log, !empty($_REQUEST['purge']), $backup); break;
            }
        }
        catch (\Throwable $e) {
            $error = $e->getMessage();
            $lines[] = $this->backend->template('text-danger', ['{text}' => $this->backend->escape(get_class($e) . ': ' . $error)]);
        }

        $replace = ['{action}' => $this->cms->snippet('component.button_' . $action), '{name}' => htmlspecialchars($entry['name'])];
        if ($error === '') {
            $message = strtr($this->cms->snippet('component.message_action_done'), $replace);
            echo $this->backend->template('alert_success', ['{text}' => $message]);
            $this->backend->balloon('success', $message);
        }
        else {
            $message = strtr($this->cms->snippet('component.message_action_failed'), $replace);
            echo $this->backend->template('alert_danger', ['{text}' => $message . '<br>' . htmlspecialchars($error)]);
            $this->backend->balloon('danger', $message);
        }
        echo $this->backend->template('component.action_log', [
            '{items}' => $lines ? '<li>' . implode("</li>\n<li>", $lines) . '</li>' : '',
        ]);
        $this->backend->script('cApp.layers.invalidate();');
    }

    private function _checkbox(string $name, string $label, bool $checked): string
    {
        return $this->backend->template('edit_form_checkbox_with_label', [
            '{name}'    => $name,
            '{value}'   => '1',
            '{id}'      => 'component_' . $name,
            '{label}'   => $label,
            '{checked}' => $checked ? ' checked' : '',
        ]);
    }

    // ====================
    // Darstellung

    /**
     * Eintrag zum Request-Parameter name (kind/Name), null wenn unbekannt.
     */
    private function _entry(): ?array
    {
        $name = (string)($_REQUEST['name'] ?? '');
        if (!preg_match('~^[a-z]+/[A-Za-z0-9]+(#conflict)?$~', $name)) return null;
        return $this->manager->status()[$name] ?? null;
    }

    /**
     * Zulässige Aktionen im Zustand (Klassenkommentar).
     *
     * @return string[]
     */
    private function _allowedActions(array $entry): array
    {
        if ($entry['core']) return [];
        $installed = $entry['installed_version'] !== '';
        $active = (int)($entry['row']['status'] ?? 0) === 1;
        return match ($entry['state']) {
            'discovered' => ['install'],
            'inactive'   => ['activate', 'uninstall'],
            'active'     => ['deactivate', 'uninstall'],
            'update'     => ['update', 'uninstall'],
            'orphaned'   => ['uninstall'],
            'error'      => array_merge($active ? ['deactivate'] : [], $installed ? ['uninstall'] : []),
            default      => [],
        };
    }

    private function _kindIcon(string $kind): string
    {
        return $this->backend->template('component.icon_' . ['modules' => 'module', 'processors' => 'processor', 'extensions' => 'extension'][$kind]);
    }

    private function _stateCell(array $entry): string
    {
        $html = $this->backend->template('component.icon_state_' . $entry['state']);
        if ($entry['state'] === 'error' && $entry['errors']) {
            $html .= $this->backend->template('component.state_errors', ['{text}' => $this->backend->escape(implode('; ', $entry['errors']))]);
        }
        return $html;
    }

    /**
     * Versionsspalte: installierte Version, dazu die der Dateien, wenn sie abweicht.
     */
    private function _versionCell(array $entry): string
    {
        if ($entry['core']) return htmlspecialchars($entry['version']);
        $installed = $entry['installed_version'];
        $files = $entry['version'];
        if ($installed === '') return htmlspecialchars($files);
        $html = htmlspecialchars($installed);
        if ($entry['update_version'] !== '') {
            $html .= $this->backend->template('note_warning', ['{text}' => str_replace('{version}', htmlspecialchars($files),
                $this->cms->snippet('component.note_update_pending'))]);
        }
        elseif ($files !== '' && version_compare($files, $installed, '<')) {
            $html .= $this->backend->template('note_warning', ['{text}' => str_replace('{version}', htmlspecialchars($files),
                $this->cms->snippet('component.note_files_older'))]);
        }
        return $html;
    }

    /**
     * requires aus settings.php als Liste.
     */
    private function _requiresList(array $entry): string
    {
        $req = (array)($entry['settings']['requires'] ?? []);
        $items = [];
        if (isset($req['core'])) {
            $items[] = str_replace('{constraint}', htmlspecialchars((string)$req['core']), $this->cms->snippet('component.requires_core'));
        }
        foreach ((array)($req['php'] ?? []) as $ext) {
            $items[] = str_replace('{name}', htmlspecialchars((string)$ext), $this->cms->snippet('component.requires_php'));
        }
        foreach ((array)($req['bin'] ?? []) as $bin) {
            $items[] = str_replace('{name}', htmlspecialchars((string)$bin), $this->cms->snippet('component.requires_bin'));
        }
        foreach ((array)($req['components'] ?? []) as $name => $constraint) {
            $items[] = strtr($this->cms->snippet('component.requires_component'),
                ['{name}' => htmlspecialchars((string)$name), '{constraint}' => htmlspecialchars((string)$constraint)]);
        }
        return $items ? $this->backend->template('component.requires_list', ['{items}' => '<li>' . implode('</li><li>', $items) . '</li>']) : '';
    }

    /**
     * Tabellen der Komponente: aus settings.php, für Verwaiste aus component.tables.
     *
     * @return string[]
     */
    private function _tables(array $entry): array
    {
        $tables = (array)($entry['settings']['tables'] ?? []);
        if (!$tables && !empty($entry['row']['tables'])) {
            $tables = (array)json_decode((string)$entry['row']['tables'], true);
        }
        return array_values(array_filter(array_map('strval', $tables)));
    }
}
