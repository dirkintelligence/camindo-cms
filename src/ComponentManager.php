<?php
/**
 * Components: modules, processors, extensions - discover them, determine their state,
 * reconcile the table `component`, write the runtime lists.
 *
 * A component is a directory with install/settings.php under
 *
 *     {core_dir}/Modules|Processors|Extensions      core component (kind, core = true)
 *     {camindo_dir}/Modules|Processors|Extensions   add-on component (Composer target)
 *
 * STATES (developer docs, chapter 09 (Installation and update))
 * --------------------
 *     core        core component: always installed and active, version = CAMINDO_VERSION
 *     discovered  directory present, no row (or row with version = '')
 *     inactive    row with version, status = 0
 *     active      status = 1, no update pending
 *     update      update_version > version - counts as inactive at runtime
 *     orphaned    row present, directory missing (composer remove without uninstall)
 *     error       requires violated, name conflict, invalid name, settings.php missing
 *
 * RUNTIME LISTS
 * --------------------
 * var/config/components/{modules,processors,extensions}.php contain ONLY the components
 * active at runtime (core + state active); afterwards the index of the
 * manuals is rebuilt (manuals of the components, Modules\Docs\Docs). Format:
 *
 *     $this->modules['Record'] = ['type' => …, 'core' => true, 'path' => '/…/Core/Modules/Record',
 *                                 'namespace' => 'Camindo\Core\Modules\Record', 'version' => '9.1.0', …];
 *
 * Cms includes them in its constructor; whoever does not find a component there treats
 * it as nonexistent - no module button, no backend path, no processor in the
 * converter list. This is the rule "update pending = inactive" from developer docs, chapter 08 (Components and extensions), without
 * every consumer having to check a flag.
 *
 * Processors: an add-on component with the same name REPLACES the core processor
 * ('overrides' => true, ProcessorFactory). Modules: name conflict = error.
 *
 * LIFECYCLE (developer docs, chapter 08 (Components and extensions))
 * --------------------
 *     install(name)       schema.sql, seed.sql, Installer::install(); row with version,
 *                         tables (for --purge); snippet cache and assets - then INACTIVE
 *     activate(name)      requires (dependencies must be active), status = 1,
 *                         Installer::activate(), runtime lists, InstanceCache
 *     deactivate(name)    status = 0; dependents only with force (they are deactivated too)
 *     update(name)        migrations/ version -> update_version, Installer::migrate()
 *     uninstall(name)     deactivate, Installer::uninstall(), purge: drop tables,
 *                         row gone, caches and assets gone - also for orphans
 *
 * Before install/update/uninstall a backup runs, unless $backup = false.
 * All writing methods take a logger - CLI: STDOUT, backend: Aside -
 * and throw a RuntimeException on errors; the caller reports them.
 *
 * Installer.php of a component: class {namespace}\Install\Installer, which
 * implements Install\ComponentInstallerInterface; loaded via require_once.
 *
 * @see Camindo\Core\Cli\ComponentCommand, Camindo\Core\Cli\UpgradeCommand
 * @see Camindo\Core\Modules\Component\ComponentBackend
 */
namespace Camindo\Core;

use Camindo\Core\Install\ComponentInstallerInterface;
use Camindo\Core\Install\InstallContext;
use Camindo\Core\Install\MigrationRunner;
use Camindo\Core\Install\VersionConstraint;

class ComponentManager {

    public const KINDS = [
        'modules'    => 'Modules',
        'processors' => 'Processors',
        'extensions' => 'Extensions',
    ];

    // Keys from settings.php that are taken over into the runtime list
    // (allow list - a foreign settings.php writes nothing arbitrary into it)
    private const SETTINGS_KEYS = [
        'type', 'label', 'description', 'icon', 'version', 'listed',
        'position',      // sort value for backend lists (the higher, the further down)
        'access',        // additional rights of the module (developer docs, chapter 11 (Permissions))
        'shared',        // scripts that other modules may call as lookup
        'personal',      // scripts that every admin may call without module access (own settings)
        'script_access', // script => required additional right
        'entries',       // entry scripts for the module tile
        'requires',      // prerequisites (developer docs, chapter 08 (Components and extensions))
        'tables',        // tables of the component (for --purge)
        'commands',      // module: file with (CMS:...) command definitions, relative to the module (developer docs, chapter 06 (Template compiler))
        'services',      // module: loadable services of the commands, name => class (Page::$lazyServices)
        'button',        // module: false = no tile on the start page (default true)
        'superuser',     // module: true = superusers only, regardless of the group rights
        'widget',        // module: start page widget, ['class','method','label','icon'] (Camindo\Core\Widgets)
        'api',           // module: API classes relative to the module namespace (Camindo\Core\Api\Api)
        'retention',     // module: ['Class::method'] run by the scheduler task "apply retention periods" (SchedulerRunner)
        'display_types', 'field_types',  // extensions (developer docs, chapter 08 (Components and extensions))
        'assets',        // extension: editor files; module: JS/CSS that every backend page loads
        'options_provider', // extension: class with clientOptions(array): array (Backend::fieldExtension())
    ];

    // Kinds with assets => directory under public/backend/
    private const ASSET_DIRS = ['extensions' => 'ext', 'modules' => 'mod'];

    private ?array $discovered = null;

    public function __construct(
        private Config $config,
        private Database $db,
        private System $system,
        private Backup $backup,
        private SnippetCache $snippetCache,
        private HtmlCache $htmlCache,
        private InstanceCache $instanceCache,
    )
    {
    }

    // ==== Lesen ====

    /**
     * Sort value of a component from its settings.php ('position', default 0).
     * For runtime lists (Cms::$modules …) the entry itself is enough.
     */
    public static function position(array $settingsOrEntry): int
    {
        $settings = $settingsOrEntry['settings'] ?? $settingsOrEntry;
        return (int)($settings['position'] ?? 0);
    }

    /**
     * Read directories - files only, no database.
     *
     * @return array  "kind/Name" => ['name', 'kind', 'core', 'path', 'namespace',
     *                'version', 'settings', 'errors' => [], 'overrides' => bool]
     */
    public function discover(): array
    {
        if ($this->discovered !== null) return $this->discovered;

        $coreDir = (string)$this->config->get('paths.core_dir');
        $mainDir = (string)$this->config->get('paths.camindo_dir');
        $list = [];

        foreach (self::KINDS as $kind => $subDir) {
            // Core first, so that an add-on component sees the conflict
            foreach ([[true, $coreDir], [false, $mainDir]] as [$isCore, $baseDir]) {
                $dir = $baseDir . '/' . $subDir;
                if (!is_dir($dir)) continue;
                foreach (scandir($dir) ?: [] as $name) {
                    if ($name === '' || $name[0] === '.' || !is_dir("$dir/$name")) continue;
                    $entry = $this->readComponent($kind, $name, "$dir/$name", $isCore);
                    $key = "$kind/$name";
                    if (isset($list[$key]) && !$isCore) {
                        if ($kind === 'processors') {
                            $entry['overrides'] = true;        // add-on replaces core
                        }
                        else {
                            $entry['errors'][] = 'name conflict with core component';
                            $key .= '#conflict';
                        }
                    }
                    $list[$key] = $entry;
                }
            }
        }
        // Order of the backend lists and runtime lists: kind, then 'position' from
        // settings.php (the higher, the further down; missing = 0), then name
        uksort($list, function (string $a, string $b) use ($list): int {
            return [$list[$a]['kind'], self::position($list[$a]), $a]
                <=> [$list[$b]['kind'], self::position($list[$b]), $b];
        });
        return $this->discovered = $list;
    }

    /**
     * State of all components: files + table `component`. Reads, writes nothing.
     *
     * @return array  "kind/Name" => discover()-Eintrag + 'state', 'row' (?array),
     *                'installed_version', 'update_version'
     */
    public function status(): array
    {
        $list = $this->discover();
        $rows = $this->rows();

        // Orphans: row without directory
        foreach ($rows as $key => $row) {
            if (isset($list[$key])) continue;
            $list[$key] = [
                'name' => $row['name'], 'kind' => $row['component_type'], 'core' => (bool)$row['core'],
                'path' => '', 'namespace' => '', 'version' => '', 'settings' => [],
                'errors' => [], 'overrides' => false,
            ];
        }

        foreach ($list as $key => &$entry) {
            $row = $rows[$key] ?? null;
            $entry['row'] = $row;
            $entry['installed_version'] = (string)($row['version'] ?? '');
            $entry['update_version'] = $entry['path'] !== '' && $row !== null
                && version_compare($entry['version'], $entry['installed_version'], '>')
                ? $entry['version'] : '';
            $entry['state'] = $this->stateOf($entry);
        }
        unset($entry);

        // requires.components needs the states of the others - second pass
        foreach ($list as $key => &$entry) {
            if ($entry['path'] === '') continue;
            $errors = $this->checkRequires($entry, $list);
            if ($errors) {
                $entry['errors'] = array_merge($entry['errors'], $errors);
                $entry['state'] = 'error';
            }
        }
        unset($entry);
        return $list;
    }

    /**
     * One entry from status().
     */
    public function find(string $kind, string $name): ?array
    {
        return $this->status()["$kind/$name"] ?? null;
    }

    /**
     * Entry for a name from the command line: "Blog" or "modules/Blog".
     * Without kind the name must be unique across all kinds.
     *
     * @throws \RuntimeException  unknown or ambiguous
     */
    public function resolve(string $name): array
    {
        $status = $this->status();
        if (str_contains($name, '/')) {
            [$kind, $n] = explode('/', $name, 2);
            $kind = strtolower($kind);
            if (!isset($status["$kind/$n"])) throw new \RuntimeException("unknown component $name");
            return $status["$kind/$n"];
        }
        $found = [];
        foreach (self::KINDS as $kind => $x) {
            if (isset($status["$kind/$name"])) $found[] = $status["$kind/$name"];
        }
        if (!$found) throw new \RuntimeException("unknown component $name");
        if (count($found) > 1) {
            throw new \RuntimeException("$name is ambiguous: " . implode(', ', array_map(fn($e) => $e['kind'] . "/$name", $found)));
        }
        return $found[0];
    }

    // ==== Schreiben ====

    /**
     * Reconcile files -> table `component` and runtime lists (writes no
     * schemas, installs nothing):
     *
     *   - core components: create/update the row (version = CAMINDO_VERSION,
     *     status = 1), delete vanished ones
     *   - new add-on components: row with version = '' (discovered, not installed)
     *   - known ones: update_version if the files are newer; label/description/type
     *
     * @param callable|null $log  function(string $line): void
     * @return array  state list (status()) after the reconciliation
     */
    public function refresh(?callable $log = null): array
    {
        $log ??= fn(string $l) => null;
        $now = gmdate('Y-m-d H:i:s');

        foreach ($this->status() as $key => $entry) {
            $s = $entry['settings'];
            $label = (string)($s['label'] ?? $entry['name']);
            $description = (string)($s['description'] ?? '');
            $icon = (string)($s['icon'] ?? 'draft');
            $type = (string)($s['type'] ?? '');
            $row = $entry['row'];

            if ($entry['core']) {
                if ($entry['path'] === '') {
                    $this->db->query("DELETE FROM `component` WHERE `component_id` = ?", 'i', [(int)$row['component_id']]);
                    // the published assets (public/backend/ext|mod/{name}/) go with it
                    $this->removeAssets($entry);
                    $log("$key: core row removed (directory gone)");
                    continue;
                }
                if ($row === null) {
                    $this->db->query("INSERT INTO `component` SET `name` = ?, `component_type` = ?, `type` = ?, `label` = ?,"
                        . " `description` = ?, `icon` = ?, `version` = ?, `update_version` = '', `core` = 1, `status` = 1, `installed_at` = ?",
                        'ssssssss',
                        [$entry['name'], $entry['kind'], $type, $label, $description, $icon, CAMINDO_VERSION, $now]
                    );
                    $log("$key: core row added");
                }
                elseif ($row['version'] !== CAMINDO_VERSION || $row['update_version'] !== '' || (int)$row['status'] !== 1
                    || $row['label'] !== $label || $row['description'] !== $description || $row['type'] !== $type || $row['icon'] !== $icon) {
                    $this->db->query("UPDATE `component` SET `type` = ?, `label` = ?, `description` = ?, `icon` = ?, `version` = ?,"
                        . " `update_version` = '', `status` = 1 WHERE `component_id` = ?",
                        'sssssi',
                        [$type, $label, $description, $icon, CAMINDO_VERSION, (int)$row['component_id']]
                    );
                    $log("$key: core row updated");
                }
                continue;
            }

            if ($entry['path'] === '') {
                $log("$key: orphaned (directory missing) - component uninstall " . $entry['name']);
                continue;
            }
            if ($entry['errors'] && $row === null) {
                // faulty (name conflict, invalid name, requires): do not create a row
                $log("$key: error - " . implode('; ', $entry['errors']));
                continue;
            }
            if ($row === null) {
                $this->db->query("INSERT INTO `component` SET `name` = ?, `component_type` = ?, `type` = ?, `label` = ?,"
                    . " `description` = ?, `icon` = ?, `version` = '', `update_version` = ?, `core` = 0, `status` = 0",
                    'sssssss',
                    [$entry['name'], $entry['kind'], $type, $label, $description, $icon, $entry['version']]
                );
                $log("$key: discovered " . $entry['version'] . ' - component install ' . $entry['name']);
                continue;
            }
            $updateVersion = $entry['installed_version'] === '' ? $entry['version'] : $entry['update_version'];
            if ($row['update_version'] !== $updateVersion || $row['label'] !== $label
                || $row['description'] !== $description || $row['type'] !== $type || $row['icon'] !== $icon) {
                $this->db->query("UPDATE `component` SET `type` = ?, `label` = ?, `description` = ?, `icon` = ?, `update_version` = ?"
                    . " WHERE `component_id` = ?",
                    'sssssi',
                    [$type, $label, $description, $icon, $updateVersion, (int)$row['component_id']]
                );
                $log("$key: row updated" . ($updateVersion !== '' && $entry['installed_version'] !== ''
                    ? " - update pending {$entry['installed_version']} -> $updateVersion" : ''));
            }
        }

        $this->discovered = null;   // re-read the states
        $status = $this->status();
        // Re-publish the assets of all running extensions and modules - core components
        // have no install step, and after a change to an add-on component
        // `refresh` is enough this way
        foreach ($status as $key => $entry) {
            if (isset(self::ASSET_DIRS[$entry['kind']]) && $this->isRuntimeActive($entry)) {
                $n = $this->publishAssets($entry);
                if ($n > 0) $log("$key: $n assets published");
            }
        }
        $this->writeRuntimeLists($status);
        $log('runtime lists written');
        return $status;
    }

    /**
     * var/config/components/{modules,processors,extensions}.php - only active components.
     *
     * @param array|null $status  result of status(), otherwise it is fetched
     */
    public function writeRuntimeLists(?array $status = null): void
    {
        $status ??= $this->status();
        $varDir = (string)$this->config->get('paths.var_dir');
        $relDir = '/config/components';
        if (!$this->system->createDir($varDir, $relDir)) {
            throw new \RuntimeException("cannot create $varDir$relDir");
        }

        foreach (self::KINDS as $kind => $subDir) {
            $php = "<?php\n// Generiert von Camindo\\Core\\ComponentManager - nicht von Hand ändern.\n"
                . "// Aktive Komponenten ($kind); Cms bindet die Datei im Konstruktor ein.\n";
            foreach ($status as $entry) {
                if ($entry['kind'] !== $kind || !$this->isRuntimeActive($entry)) continue;
                $data = $entry['settings'];
                $data['version']   = $entry['version'];
                $data['core']      = $entry['core'];
                $data['path']      = $entry['path'];
                $data['namespace'] = $entry['namespace'];
                if ($entry['overrides']) $data['overrides'] = true;
                if (isset(self::ASSET_DIRS[$kind])) {
                    // Manifest of the published assets (developer docs, chapter 08 (Components and extensions)):
                    // paths relative to the backend docroot, version parameter against browser caches
                    $data['assets_published'] = $this->publishedAssets($entry);
                }
                $php .= "\$this->{$kind}[" . var_export($entry['name'], true) . '] = '
                    . var_export($data, true) . ";\n";
            }
            $this->system->filePutContents($varDir . $relDir, "/$kind.php", $php, lock: true);
        }
        // The manuals of the components (docs/) come and go with them: rebuild the
        // search index. If the table is still missing (early installation), the installer
        // builds it later anyway.
        try {
            (new Modules\Docs\Docs($this->config, $this->db))->rebuildIndex();
        }
        catch (\Throwable) {
        }
    }

    /**
     * Does the component count at runtime?
     */
    public function isRuntimeActive(array $entry): bool
    {
        return in_array($entry['state'], ['core', 'active'], true);
    }


    // ==== Lebenszyklus ====

    /**
     * Install an add-on component (developer docs, chapter 08 (Components and extensions)): schema.sql, seed.sql, Installer::install(),
     * row in `component` (status = 0), snippet cache, assets, runtime lists.
     * Afterwards the component is INACTIVE - unless with $activate.
     *
     * @param callable|null $log  function(string $line): void
     * @return array  entry from status() after the installation
     */
    public function install(string $name, ?callable $log = null, bool $activate = false, bool $backup = true): array
    {
        $log ??= fn(string $l) => null;
        $entry = $this->resolve($name);
        $key = $entry['kind'] . '/' . $entry['name'];
        if ($entry['core']) throw new \RuntimeException("$key is a core component");
        if ($entry['state'] !== 'discovered') {
            throw new \RuntimeException("$key is {$entry['state']} - install needs a discovered component"
                . ($entry['errors'] ? ': ' . implode('; ', $entry['errors']) : ''));
        }
        $errors = $this->checkRequires($entry, $this->status(), true);
        if ($errors) throw new \RuntimeException("$key: " . implode('; ', $errors));

        if ($backup) $this->backupBefore("install-{$entry['name']}", $log);
        $ctx = $this->context($entry, $log);

        foreach (['schema.sql', 'seed.sql'] as $file) {
            $path = $entry['path'] . '/install/' . $file;
            if (!is_file($path)) continue;
            $n = $ctx->runSqlFile($path);
            $log("$key: $file - $n statements");
        }
        $installer = $this->installer($entry);
        if ($installer !== null) {
            $installer->install($ctx);
            $log("$key: Installer::install() done");
        }

        $now = gmdate('Y-m-d H:i:s');
        $tables = $this->tablesJson($entry);
        $s = $entry['settings'];
        $fields = [
            'type' => (string)($s['type'] ?? ''),
            'label' => (string)($s['label'] ?? $entry['name']),
            'description' => (string)($s['description'] ?? ''),
            'icon' => (string)($s['icon'] ?? 'draft'),
        ];
        if ($entry['row'] === null) {
            $this->db->query("INSERT INTO `component` SET `name` = ?, `component_type` = ?, `type` = ?, `label` = ?,"
                . " `description` = ?, `icon` = ?, `version` = ?, `update_version` = '', `core` = 0, `status` = 0, `tables` = ?, `installed_at` = ?",
                'sssssssss',
                [$entry['name'], $entry['kind'], $fields['type'], $fields['label'], $fields['description'], $fields['icon'],
                $entry['version'], $tables, $now]
            );
        }
        else {
            $this->db->query("UPDATE `component` SET `type` = ?, `label` = ?, `description` = ?, `icon` = ?, `version` = ?,"
                . " `update_version` = '', `status` = 0, `tables` = ?, `installed_at` = ?, `updated_at` = NULL WHERE `component_id` = ?",
                'sssssssi',
                [$fields['type'], $fields['label'], $fields['description'], $entry['icon'], $entry['version'], $tables, $now,
                (int)$entry['row']['component_id']]
            );
        }
        $log("$key: installed {$entry['version']}");

        $this->reload();
        $this->runtimeFiles($entry, $log);
        if ($activate) {
            return $this->activate($key, $log);
        }
        $this->writeRuntimeLists();
        $log('runtime lists written');
        return $this->status()[$key];
    }

    /**
     * Install all discovered add-on components - in dependency order.
     *
     * @return string[]  installed "kind/Name"
     */
    public function installAll(?callable $log = null, bool $activate = false, bool $backup = true): array
    {
        $log ??= fn(string $l) => null;
        $done = [];
        $pending = array_filter($this->status(), fn($e) => $e['state'] === 'discovered');
        if ($pending && $backup) {
            $this->backupBefore('install-all', $log);
        }
        foreach ($this->dependencyOrder($pending) as $key) {
            $this->install($key, $log, $activate, false);
            $done[] = $key;
        }
        return $done;
    }

    /**
     * Activate: requires (dependencies must be active), status = 1,
     * Installer::activate(), runtime lists, InstanceCache (module rights).
     *
     * @return array  entry from status()
     */
    public function activate(string $name, ?callable $log = null): array
    {
        $log ??= fn(string $l) => null;
        $entry = $this->resolve($name);
        $key = $entry['kind'] . '/' . $entry['name'];
        if ($entry['core']) throw new \RuntimeException("$key is a core component");
        if ($entry['state'] === 'active') {
            $log("$key: already active");
            return $entry;
        }
        if ($entry['state'] !== 'inactive') {
            throw new \RuntimeException("$key is {$entry['state']} - activate needs an installed, inactive component"
                . ($entry['state'] === 'update' ? ' (component update first)' : '')
                . ($entry['errors'] ? ': ' . implode('; ', $entry['errors']) : ''));
        }
        $errors = $this->checkRequires($entry, $this->status(), true);
        if ($errors) throw new \RuntimeException("$key: " . implode('; ', $errors));

        $this->db->query("UPDATE `component` SET `status` = 1 WHERE `component_id` = ?", 'i', [(int)$entry['row']['component_id']]);
        $installer = $this->installer($entry);
        if ($installer !== null) {
            $installer->activate($this->context($entry, $log));
            $log("$key: Installer::activate() done");
        }
        $log("$key: active");
        $this->reload();
        $this->writeRuntimeLists();
        $this->instanceCache->rebuild();
        $log('runtime lists written, instance cache rebuilt');
        return $this->status()[$key];
    }

    /**
     * Deactivate: status = 0, Installer::deactivate(), runtime lists, InstanceCache.
     * If active components depend on this one, $force is needed - they are then
     * deactivated first (order in the result).
     *
     * @return string[]  deactivated "kind/Name", the dependents first
     */
    public function deactivate(string $name, ?callable $log = null, bool $force = false): array
    {
        $log ??= fn(string $l) => null;
        $entry = $this->resolve($name);
        $key = $entry['kind'] . '/' . $entry['name'];
        if ($entry['core']) throw new \RuntimeException("$key is a core component");
        if ($entry['row'] === null || (int)$entry['row']['status'] !== 1) {
            $log("$key: not active");
            return [];
        }
        $dependents = $this->dependents($entry, $this->status());
        if ($dependents && !$force) {
            throw new \RuntimeException("$key: active components depend on it (" . implode(', ', $dependents)
                . ') - deactivate them first or use --force');
        }
        $done = [];
        foreach ($dependents as $dep) {
            $done = array_merge($done, $this->deactivate($dep, $log, true));
        }
        $this->db->query("UPDATE `component` SET `status` = 0 WHERE `component_id` = ?", 'i', [(int)$entry['row']['component_id']]);
        if ($entry['path'] !== '') {
            $installer = $this->installer($entry);
            if ($installer !== null) {
                $installer->deactivate($this->context($entry, $log));
                $log("$key: Installer::deactivate() done");
            }
        }
        $log("$key: inactive");
        $done[] = $key;
        $this->reload();
        $this->writeRuntimeLists();
        $this->instanceCache->rebuild();
        $log('runtime lists written, instance cache rebuilt');
        return $done;
    }

    /**
     * Migrations from component.version to update_version (developer docs, chapter 08 (Components and extensions)),
     * Installer::migrate(), pointer per version; afterwards version = update_version, tables
     * anew, caches and assets anew. The status stays as it was.
     *
     * @return array  entry from status()
     */
    public function update(string $name, ?callable $log = null, bool $backup = true): array
    {
        $log ??= fn(string $l) => null;
        $entry = $this->resolve($name);
        $key = $entry['kind'] . '/' . $entry['name'];
        if ($entry['core']) throw new \RuntimeException("$key is a core component");
        if ($entry['state'] !== 'update') {
            throw new \RuntimeException("$key is {$entry['state']} - no update pending"
                . ($entry['errors'] ? ': ' . implode('; ', $entry['errors']) : ''));
        }
        $from = $entry['installed_version'];
        $to = $entry['update_version'];
        $rowId = (int)$entry['row']['component_id'];

        if ($backup) $this->backupBefore("update-{$entry['name']}-$from-$to", $log);
        $ctx = $this->context($entry, $log);
        $dir = $entry['path'] . '/install/migrations';
        $done = (new MigrationRunner())->run($dir, $from, $to, $ctx, function (string $version) use ($rowId): void {
            $this->db->query("UPDATE `component` SET `version` = ? WHERE `component_id` = ?", 'si', [$version, $rowId]);
        });
        $log("$key: " . count($done) . ' migration(s)');
        $installer = $this->installer($entry);
        if ($installer !== null) {
            $installer->migrate($from, $to, $ctx);
            $log("$key: Installer::migrate($from, $to) done");
        }
        $s = $entry['settings'];
        $this->db->query("UPDATE `component` SET `type` = ?, `label` = ?, `description` = ?, `icon` = ?, `version` = ?,"
            . " `update_version` = '', `tables` = ?, `updated_at` = ? WHERE `component_id` = ?",
            'sssssssi',
            [(string)($s['type'] ?? ''), (string)($s['label'] ?? $entry['name']), (string)($s['description'] ?? ''), (string)($s['icon'] ?? 'draft'),
            $to, $this->tablesJson($entry), gmdate('Y-m-d H:i:s'), $rowId]
        );
        $log("$key: updated $from -> $to");

        $this->reload();
        $this->runtimeFiles($entry, $log);
        $this->writeRuntimeLists();
        $this->instanceCache->rebuild();
        $log('runtime lists written, instance cache rebuilt');
        return $this->status()[$key];
    }

    /**
     * All components with a pending update - in dependency order
     * (requires.components, topological). Part of `upgrade`.
     *
     * @return string[]  updated "kind/Name"
     */
    public function updateAll(?callable $log = null, bool $backup = true): array
    {
        $log ??= fn(string $l) => null;
        $done = [];
        $pending = array_filter($this->status(), fn($e) => $e['state'] === 'update');
        if ($pending && $backup) {
            $this->backupBefore('update-all', $log);
        }
        foreach ($this->dependencyOrder($pending) as $key) {
            $this->update($key, $log, false);
            $done[] = $key;
        }
        return $done;
    }

    /**
     * Remove (developer docs, chapter 08 (Components and extensions)): deactivate (if active), Installer::uninstall(), with $purge
     * drop the tables from component.tables, delete the row, remove the snippet cache and
     * assets of the component, runtime lists. Also runs for orphans
     * (files gone) - then without installer, --purge via the stored table list.
     *
     * @return string[]  dropped tables
     */
    public function uninstall(string $name, ?callable $log = null, bool $purge = false, bool $backup = true): array
    {
        $log ??= fn(string $l) => null;
        $entry = $this->resolve($name);
        $key = $entry['kind'] . '/' . $entry['name'];
        if ($entry['core']) throw new \RuntimeException("$key is a core component");
        if ($entry['row'] === null) throw new \RuntimeException("$key is not installed");
        $row = $entry['row'];
        $installed = (string)$row['version'] !== '';

        if ($backup && $installed) $this->backupBefore("uninstall-{$entry['name']}", $log);
        if ((int)$row['status'] === 1) {
            $this->deactivate($key, $log, true);
            $entry = $this->status()[$key];
        }
        if ($installed && $entry['path'] !== '') {
            $installer = $this->installer($entry);
            if ($installer !== null) {
                $installer->uninstall($this->context($entry, $log), $purge);
                $log("$key: Installer::uninstall() done");
            }
        }
        $dropped = [];
        if ($purge) {
            $tables = json_decode((string)($row['tables'] ?? ''), true);
            foreach (is_array($tables) ? $tables : [] as $table) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) continue;
                $this->db->query("DROP TABLE IF EXISTS `$table`");
                $dropped[] = $table;
            }
            $log("$key: " . ($dropped ? 'dropped ' . implode(', ', $dropped) : 'no tables to drop'));
        }
        $this->db->query("DELETE FROM `component` WHERE `component_id` = ?", 'i', [(int)$row['component_id']]);
        $n = $this->snippetCache->remove($this->prefix($entry)) + $this->htmlCache->remove($this->prefix($entry));
        $this->removeAssets($entry);
        $log("$key: row removed, $n cache files removed"
            . ($entry['path'] !== '' ? ' - now discovered; composer remove removes the files' : ' (was orphaned)'));

        $this->reload();
        $this->writeRuntimeLists();
        $this->instanceCache->rebuild();
        $log('runtime lists written, instance cache rebuilt');
        return $dropped;
    }

    // ==== Lebenszyklus-Helfer ====

    private function reload(): void
    {
        $this->discovered = null;
    }

    private function backupBefore(string $reason, callable $log): void
    {
        $file = $this->backup->beforeChange($reason);
        $log('backup ' . basename($file));
    }

    private function context(array $entry, callable $log): InstallContext
    {
        return new InstallContext($this->db, $this->config, $this->system, $entry['name'], $entry['path'],
            \Closure::fromCallable($log));
    }

    /**
     * install/Installer.php laden - Klasse {namespace}\Install\Installer.
     */
    private function installer(array $entry): ?ComponentInstallerInterface
    {
        $file = $entry['path'] . '/install/Installer.php';
        if ($entry['path'] === '' || !is_file($file)) return null;
        require_once $file;
        $class = $entry['namespace'] . '\\Install\\Installer';
        if (!class_exists($class, false)) {
            throw new \RuntimeException("$file does not define $class");
        }
        $installer = new $class();
        if (!$installer instanceof ComponentInstallerInterface) {
            throw new \RuntimeException("$class must implement " . ComponentInstallerInterface::class);
        }
        return $installer;
    }

    /**
     * Snippet prefix as SnippetCache::sourceDirs(): modules 'blog', processors
     * 'proc.blog', extensions 'ext.blog'.
     */
    public function prefix(array $entry): string
    {
        $prefixes = ['modules' => '', 'processors' => 'proc.', 'extensions' => 'ext.'];
        return ($prefixes[$entry['kind']] ?? '') . preg_replace('/[^a-z0-9_]/', '', strtolower($entry['name']));
    }

    /**
     * settings.php['tables'] as JSON for component.tables (valid identifiers only).
     */
    private function tablesJson(array $entry): ?string
    {
        $tables = array_values(array_filter(array_map('strval', (array)($entry['settings']['tables'] ?? [])),
            fn($t) => preg_match('/^[A-Za-z0-9_]+$/', $t)));
        return $tables ? json_encode($tables) : null;
    }

    /**
     * Files that belong to an installed component: snippet and HTML cache
     * (only its prefix) and published assets.
     */
    private function runtimeFiles(array $entry, callable $log): void
    {
        $prefix = $this->prefix($entry);
        $result = $this->snippetCache->build($prefix);
        $html = $this->htmlCache->build($prefix);
        $log("cache '$prefix': {$result['files']} snippet files, {$html['files']} html files");
        $n = $this->publishAssets($entry);
        if ($n > 0) $log("assets: $n files published");
    }

    /**
     * Copy assets to public/backend/ext/{name}/ (extensions, developer docs, chapter 08 (Components and extensions)) or
     * public/backend/mod/{name}/ (modules) - flat, the file name stays. Beforehand
     * the directory is emptied. Paths in settings.php['assets'] are relative to
     * the component's public/; 'vendor:…'
     * points into the project's vendor/ (third-party libraries from the composer.json of the
     * extension). Copy instead of symlink - vendor/ stays outside the docroot.
     * 'dirs' names whole directories that are copied recursively under their name
     * (libraries that load their parts relative to the script - TinyMCE:
     * skins/, themes/, plugins/ …); they are not in the manifest.
     *
     * @return int  copied files
     */
    private function publishAssets(array $entry): int
    {
        $this->removeAssets($entry);
        if (!isset(self::ASSET_DIRS[$entry['kind']])) return 0;
        $assets = (array)($entry['settings']['assets'] ?? []);
        $files = [];
        foreach (['js', 'css'] as $type) {
            foreach ((array)($assets[$type] ?? []) as $rel) $files[] = (string)$rel;
        }
        $dirs = array_map('strval', (array)($assets['dirs'] ?? []));
        if (!$files && !$dirs) return 0;
        $backendDir = (string)$this->config->get('paths.backend_dir');
        $rel = '/' . $this->assetDir($entry);
        if (!$this->system->createDir($backendDir, $rel)) {
            throw new \RuntimeException("cannot create $backendDir$rel");
        }
        $n = 0;
        foreach ($files as $file) {
            $src = $this->assetSource($entry, $file);
            if ($src === null) continue;
            if (copy($src, $backendDir . $rel . '/' . basename($file))) $n++;
        }
        foreach ($dirs as $dir) {
            $src = $this->assetSource($entry, rtrim($dir, '/'), true);
            if ($src === null) continue;
            $n += $this->copyDir($src, $backendDir . $rel . '/' . basename($src));
        }
        return $n;
    }

    /**
     * Copy a directory recursively (regular files only, no symlinks).
     *
     * @return int  copied files
     */
    private function copyDir(string $src, string $dest): int
    {
        if (!is_dir($dest) && !mkdir($dest, 0755, true)) {
            throw new \RuntimeException("cannot create $dest");
        }
        $n = 0;
        foreach (scandir($src) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $from = "$src/$name";
            if (is_link($from)) continue;
            if (is_dir($from)) $n += $this->copyDir($from, "$dest/$name");
            elseif (is_file($from) && copy($from, "$dest/$name")) $n++;
        }
        return $n;
    }

    /**
     * Source of an asset entry (file, with $dir a directory), null if
     * invalid or not present.
     */
    private function assetSource(array $entry, string $file, bool $dir = false): ?string
    {
        if (str_contains($file, '..')) return null;
        if (str_starts_with($file, 'vendor:')) {
            $src = (string)$this->config->get('paths.camindo_dir') . '/vendor/' . ltrim(substr($file, 7), '/');
        }
        else {
            $src = $entry['path'] . '/public/' . ltrim($file, '/');
        }
        return ($dir ? is_dir($src) : is_file($src)) ? $src : null;
    }

    /**
     * Directory under public/backend/: ext/{name} or mod/{name}, the name in lowercase
     * like the snippet prefix - this is also how the extension is named in
     * data-c9-ext. '' for kinds without assets.
     */
    private function assetDir(array $entry): string
    {
        $base = self::ASSET_DIRS[$entry['kind']] ?? '';
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($entry['name']));
        return $base === '' || $name === '' ? '' : "$base/$name";
    }

    /**
     * Manifest for the runtime list: ['js' => ['ext/calendar/calendar.ext.js?v=9.1.0'], 'css' => […]]
     * or 'mod/{name}/…' for modules
     * (paths relative to public/backend/) - only files that really exist.
     */
    private function publishedAssets(array $entry): array
    {
        $manifest = ['js' => [], 'css' => []];
        $dir = $this->assetDir($entry);
        $backendDir = (string)$this->config->get('paths.backend_dir');
        foreach (['js', 'css'] as $type) {
            foreach ((array)($entry['settings']['assets'][$type] ?? []) as $file) {
                $name = basename((string)$file);
                if (!is_file("$backendDir/$dir/$name")) continue;
                // Version + time of publishing: after `refresh` the browser fetches
                // changed files anew, even without a new version number (cApp.extensions
                // loads them dynamically - a "hard" reload does not always catch them)
                $manifest[$type][] = "$dir/$name?v=" . rawurlencode($entry['version'] . '.' . (int)filemtime("$backendDir/$dir/$name"));
            }
        }
        return $manifest;
    }

    private function removeAssets(array $entry): void
    {
        $sub = $this->assetDir($entry);
        $dir = (string)$this->config->get('paths.backend_dir') . '/' . $sub;
        if ($sub === '' || !is_dir($dir)) return;
        $this->removeDir($dir);
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = "$dir/$name";
            if (is_dir($path) && !is_link($path)) $this->removeDir($path);
            else unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Active add-on components whose requires.components name this component.
     *
     * @param array|null $all  status(), otherwise it is fetched
     * @return string[]  "kind/Name"
     */
    public function dependents(array $entry, ?array $all = null): array
    {
        $list = [];
        foreach ($all ?? $this->status() as $key => $other) {
            if ($other['core'] || ($other['row']['status'] ?? 0) != 1) continue;
            $req = (array)($other['settings']['requires']['components'] ?? []);
            if (isset($req[$entry['name']])) $list[] = $key;
        }
        return $list;
    }

    /**
     * Keys of the entries sorted so that dependencies (requires.components)
     * come before the dependents. Cycles are broken (alphabetical order).
     *
     * @param array $entries  "kind/Name" => entry
     * @return string[]
     */
    private function dependencyOrder(array $entries): array
    {
        $byName = [];
        foreach ($entries as $key => $e) $byName[$e['name']] = $key;
        $order = [];
        $visiting = [];
        $visit = function (string $key) use (&$visit, &$order, &$visiting, $entries, $byName): void {
            if (isset($order[$key]) || isset($visiting[$key])) return;
            $visiting[$key] = true;
            foreach (array_keys((array)($entries[$key]['settings']['requires']['components'] ?? [])) as $dep) {
                if (isset($byName[$dep])) $visit($byName[$dep]);
            }
            $order[$key] = true;
        };
        foreach (array_keys($entries) as $key) $visit($key);
        return array_keys($order);
    }

    // ==== Helfer ====

    private function readComponent(string $kind, string $name, string $path, bool $isCore): array
    {
        $subDir = self::KINDS[$kind];
        $entry = [
            'name'      => $name,
            'kind'      => $kind,
            'core'      => $isCore,
            'path'      => $path,
            'namespace' => ($isCore ? 'Camindo\\Core\\' : 'Camindo\\') . $subDir . '\\' . $name,
            'version'   => '',
            'settings'  => [],
            'errors'    => [],
            'overrides' => false,
        ];
        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            $entry['errors'][] = 'directory name is not a valid component name (StudlyCase)';
        }
        $file = "$path/install/settings.php";
        if (!is_file($file)) {
            $entry['errors'][] = 'install/settings.php missing';
            return $entry;
        }
        $settings = include $file;
        if (!is_array($settings)) {
            $entry['errors'][] = 'install/settings.php does not return an array';
            return $entry;
        }
        $entry['settings'] = array_intersect_key($settings, array_flip(self::SETTINGS_KEYS));
        $version = VersionConstraint::normalize((string)($settings['version'] ?? ''));
        if ($version === null) {
            $entry['errors'][] = 'version missing or not SemVer in settings.php';
        }
        $entry['version'] = $isCore ? CAMINDO_VERSION : (string)$version;
        return $entry;
    }

    private function stateOf(array $entry): string
    {
        if ($entry['errors']) return 'error';
        if ($entry['core']) return $entry['path'] === '' ? 'orphaned' : 'core';
        if ($entry['path'] === '') return 'orphaned';
        $row = $entry['row'];
        if ($row === null || (string)$row['version'] === '') return 'discovered';
        if ($entry['update_version'] !== '') return 'update';
        return (int)$row['status'] === 1 ? 'active' : 'inactive';
    }

    /**
     * Check settings.php 'requires' (developer docs, chapter 08 (Components and extensions)). For installed components -
     * a 'discovered' one only checks core/php/bin; dependencies on other
     * components only at install/activate (then they are mandatory).
     *
     * @return string[]  errors
     */
    public function checkRequires(array $entry, array $all, bool $strictComponents = false): array
    {
        $req = (array)($entry['settings']['requires'] ?? []);
        $errors = [];

        if (isset($req['core']) && !VersionConstraint::satisfies(CAMINDO_VERSION, (string)$req['core'])) {
            $errors[] = "requires core {$req['core']} (is " . CAMINDO_VERSION . ')';
        }
        foreach ((array)($req['php'] ?? []) as $ext) {
            if (!extension_loaded((string)$ext)) $errors[] = "requires PHP extension $ext";
        }
        foreach ((array)($req['bin'] ?? []) as $bin) {
            $path = (string)$this->config->get('paths.' . $bin);
            if ($path === '' || !is_executable($path)) $errors[] = "requires program $bin (paths.$bin)";
        }
        $checkComponents = $strictComponents || in_array($entry['state'] ?? '', ['active', 'inactive', 'update'], true);
        if ($checkComponents) {
            foreach ((array)($req['components'] ?? []) as $name => $constraint) {
                $other = null;
                foreach (self::KINDS as $kind => $x) {
                    if (isset($all["$kind/$name"])) { $other = $all["$kind/$name"]; break; }
                }
                if ($other === null || !in_array($other['state'], ['core', 'active'], true)) {
                    $errors[] = "requires component $name (not active)";
                }
                elseif (!VersionConstraint::satisfies($other['core'] ? CAMINDO_VERSION : $other['installed_version'], (string)$constraint)) {
                    $errors[] = "requires component $name $constraint";
                }
            }
        }
        return $errors;
    }

    /**
     * Table `component`: "kind/Name" => row
     */
    private function rows(): array
    {
        $this->db->query("SELECT * FROM `component` ORDER BY `component_type`, `name`");
        $rows = [];
        foreach ($this->db->fetchAll() as $row) {
            $row['core'] = (int)$row['core'] === 1 || $row['core'] === "\x01";
            $rows[$row['component_type'] . '/' . $row['name']] = $row;
        }
        return $rows;
    }
}
