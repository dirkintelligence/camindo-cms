<?php
/**
 * Komponenten: Module, Prozessoren, Extensions - entdecken, Zustand bestimmen,
 * Tabelle `component` abgleichen, Laufzeitlisten schreiben.
 *
 * Eine Komponente ist ein Verzeichnis mit install/settings.php unter
 *
 *     {core_dir}/Modules|Processors|Extensions      Core-Komponente (kind, core = true)
 *     {camindo_dir}/Modules|Processors|Extensions   Zusatzkomponente (Composer-Ziel)
 *
 * ZUSTÄNDE (docs/developers/14-installation-und-update.md, 5.2)
 * --------------------
 *     core        Core-Komponente: immer installiert und aktiv, Version = CAMINDO_VERSION
 *     discovered  Verzeichnis da, keine Zeile (oder Zeile mit version = '')
 *     inactive    Zeile mit version, status = 0
 *     active      status = 1, kein wartendes Update
 *     update      update_version > version - gilt zur Laufzeit als inaktiv
 *     orphaned    Zeile da, Verzeichnis fehlt (composer remove ohne uninstall)
 *     error       requires verletzt, Namenskonflikt, ungültiger Name, settings.php fehlt
 *
 * LAUFZEITLISTEN
 * --------------------
 * var/config/components/{modules,processors,extensions}.php enthalten NUR die zur
 * Laufzeit aktiven Komponenten (Core + Zustand active); danach wird der Index der
 * Handbücher neu aufgebaut (Handbücher der Komponenten, Modules\Docs\Docs). Format:
 *
 *     $this->modules['Record'] = ['type' => …, 'core' => true, 'path' => '/…/Core/Modules/Record',
 *                                 'namespace' => 'Camindo\Core\Modules\Record', 'version' => '9.1.0', …];
 *
 * Cms bindet sie im Konstruktor ein; wer eine Komponente dort nicht findet, behandelt
 * sie als nicht vorhanden - kein Modulbutton, kein Backend-Pfad, kein Prozessor in
 * der Konverterliste. Das ist die Regel "update wartet = inaktiv" aus 13 §2.6, ohne
 * dass jeder Verbraucher ein Flag prüfen muss.
 *
 * Prozessoren: eine Zusatzkomponente gleichen Namens ERSETZT den Core-Prozessor
 * ('overrides' => true, ProcessorFactory). Module: Namenskonflikt = Fehler.
 *
 * LEBENSZYKLUS (14 §6.3-6.5)
 * --------------------
 *     install(name)       schema.sql, seed.sql, Installer::install(); Zeile mit version,
 *                         tables (für --purge); Snippet-Cache und Assets - danach INAKTIV
 *     activate(name)      requires (Abhängigkeiten müssen aktiv sein), status = 1,
 *                         Installer::activate(), Laufzeitlisten, InstanceCache
 *     deactivate(name)    status = 0; Abhängige nur mit force (werden mit deaktiviert)
 *     update(name)        migrations/ version -> update_version, Installer::migrate()
 *     uninstall(name)     deactivate, Installer::uninstall(), purge: Tabellen droppen,
 *                         Zeile weg, Caches und Assets weg - auch für Verwaiste
 *
 * Vor install/update/uninstall läuft eine Sicherung (Backup), außer $backup = false.
 * Alle schreibenden Methoden nehmen einen Logger entgegen - CLI: STDOUT, Backend: Aside -
 * und werfen bei Fehlern eine RuntimeException; der Aufrufer meldet sie.
 *
 * Installer.php einer Komponente: Klasse {namespace}\Install\Installer, die
 * Install\ComponentInstallerInterface implementiert; wird per require_once geladen.
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

    // Schlüssel aus settings.php, die in die Laufzeitliste übernommen werden
    // (Weißliste - eine fremde settings.php schreibt nichts Beliebiges hinein)
    private const SETTINGS_KEYS = [
        'type', 'label', 'description', 'icon', 'version', 'listed',
        'position',      // Sortierwert für Backend-Listen (je höher, desto weiter unten)
        'access',        // Zusatzrechte des Moduls (12-group-rights.md)
        'shared',        // Scripte, die andere Module als Lookup aufrufen dürfen
        'personal',      // Scripte, die jeder Admin ohne Modulzugriff aufrufen darf (eigene Einstellungen)
        'script_access', // Script => nötiges Zusatzrecht
        'entries',       // Einstiegsscripte für die Modulkachel
        'requires',      // Voraussetzungen (14 §5.3)
        'tables',        // Tabellen der Komponente (für --purge)
        'commands',      // Modul: Datei mit (CMS:...)-Befehlsdefinitionen, relativ zum Modul (06-templates.md)
        'services',      // Modul: nachladbare Dienste der Befehle, Name => Klasse (Page::$lazyServices)
        'button',        // Modul: false = keine Kachel auf der Startseite (Standard true)
        'superuser',     // Modul: true = nur fuer Superuser, unabhaengig von den Gruppenrechten
        'widget',        // Modul: Startseiten-Widget, ['class','method','label','icon'] (Camindo\Core\Widgets)
        'api',           // Modul: API-Klassen relativ zum Modul-Namespace (Camindo\Core\Api\Api)
        'display_types', 'field_types',  // Extensions (13 §3)
        'assets',        // Extension: Editor-Dateien; Modul: JS/CSS, das jede Backend-Seite lädt
        'options_provider', // Extension: Klasse mit clientOptions(array): array (Backend::fieldExtension())
    ];

    // Arten mit Assets => Verzeichnis unter public/backend/
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
     * Sortierwert einer Komponente aus ihrer settings.php ('position', Standard 0).
     * Für Laufzeitlisten (Cms::$modules …) genügt der Eintrag selbst.
     */
    public static function position(array $settingsOrEntry): int
    {
        $settings = $settingsOrEntry['settings'] ?? $settingsOrEntry;
        return (int)($settings['position'] ?? 0);
    }

    /**
     * Verzeichnisse lesen - nur Dateien, keine Datenbank.
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
            // Core zuerst, damit eine Zusatzkomponente den Konflikt sieht
            foreach ([[true, $coreDir], [false, $mainDir]] as [$isCore, $baseDir]) {
                $dir = $baseDir . '/' . $subDir;
                if (!is_dir($dir)) continue;
                foreach (scandir($dir) ?: [] as $name) {
                    if ($name === '' || $name[0] === '.' || !is_dir("$dir/$name")) continue;
                    $entry = $this->readComponent($kind, $name, "$dir/$name", $isCore);
                    $key = "$kind/$name";
                    if (isset($list[$key]) && !$isCore) {
                        if ($kind === 'processors') {
                            $entry['overrides'] = true;        // Zusatz ersetzt Core
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
        // Reihenfolge der Backend-Listen und Laufzeitlisten: Art, dann 'position' aus
        // settings.php (je höher, desto weiter unten; fehlend = 0), dann Name
        uksort($list, function (string $a, string $b) use ($list): int {
            return [$list[$a]['kind'], self::position($list[$a]), $a]
                <=> [$list[$b]['kind'], self::position($list[$b]), $b];
        });
        return $this->discovered = $list;
    }

    /**
     * Zustand aller Komponenten: Dateien + Tabelle `component`. Liest, schreibt nichts.
     *
     * @return array  "kind/Name" => discover()-Eintrag + 'state', 'row' (?array),
     *                'installed_version', 'update_version'
     */
    public function status(): array
    {
        $list = $this->discover();
        $rows = $this->rows();

        // Verwaiste: Zeile ohne Verzeichnis
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

        // requires.components braucht die Zustände der anderen - zweiter Durchlauf
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
     * Ein Eintrag aus status().
     */
    public function find(string $kind, string $name): ?array
    {
        return $this->status()["$kind/$name"] ?? null;
    }

    /**
     * Eintrag zu einem Namen von der Kommandozeile: "Blog" oder "modules/Blog".
     * Ohne Art muss der Name über alle Arten eindeutig sein.
     *
     * @throws \RuntimeException  unbekannt oder mehrdeutig
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
     * Abgleich Dateien -> Tabelle `component` und Laufzeitlisten (schreibt keine
     * Schemata, installiert nichts):
     *
     *   - Core-Komponenten: Zeile anlegen/nachziehen (version = CAMINDO_VERSION,
     *     status = 1), verschwundene löschen
     *   - neue Zusatzkomponenten: Zeile mit version = '' (entdeckt, nicht installiert)
     *   - bekannte: update_version, wenn die Dateien neuer sind; label/description/type
     *
     * @param callable|null $log  function(string $line): void
     * @return array  Zustandsliste (status()) nach dem Abgleich
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
                    // mit ihr gehen die veröffentlichten Assets (public/backend/ext|mod/{name}/)
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
                // fehlerhaft (Namenskonflikt, ungültiger Name, requires): keine Zeile anlegen
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

        $this->discovered = null;   // Zustände neu lesen
        $status = $this->status();
        // Assets aller laufenden Extensions und Module neu publizieren - Core-Komponenten
        // haben keinen install-Schritt, und nach einer Änderung an einer Zusatzkomponente
        // genügt so `refresh`
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
     * var/config/components/{modules,processors,extensions}.php - nur aktive Komponenten.
     *
     * @param array|null $status  Ergebnis von status(), sonst wird es geholt
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
                    // Manifest der publizierten Assets (13 §3.5): Pfade relativ zum
                    // Backend-Docroot, Versionsparameter gegen Browser-Caches
                    $data['assets_published'] = $this->publishedAssets($entry);
                }
                $php .= "\$this->{$kind}[" . var_export($entry['name'], true) . '] = '
                    . var_export($data, true) . ";\n";
            }
            $this->system->filePutContents($varDir . $relDir, "/$kind.php", $php, lock: true);
        }
        // Handbücher der Komponenten (docs/) kommen und gehen mit ihnen: Suchindex
        // neu aufbauen. Fehlt die Tabelle noch (frühe Installation), baut der Installer
        // ihn später ohnehin auf.
        try {
            (new Modules\Docs\Docs($this->config, $this->db))->rebuildIndex();
        }
        catch (\Throwable) {
        }
    }

    /**
     * Zählt die Komponente zur Laufzeit?
     */
    public function isRuntimeActive(array $entry): bool
    {
        return in_array($entry['state'], ['core', 'active'], true);
    }


    // ==== Lebenszyklus ====

    /**
     * Zusatzkomponente installieren (14 §6.3): schema.sql, seed.sql, Installer::install(),
     * Zeile in `component` (status = 0), Snippet-Cache, Assets, Laufzeitlisten.
     * Danach ist die Komponente INAKTIV - außer mit $activate.
     *
     * @param callable|null $log  function(string $line): void
     * @return array  Eintrag aus status() nach der Installation
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
     * Alle entdeckten Zusatzkomponenten installieren - in Abhängigkeitsreihenfolge.
     *
     * @return string[]  installierte "kind/Name"
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
     * Scharfschalten: requires (Abhängigkeiten müssen aktiv sein), status = 1,
     * Installer::activate(), Laufzeitlisten, InstanceCache (Modulrechte).
     *
     * @return array  Eintrag aus status()
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
     * Abschalten: status = 0, Installer::deactivate(), Laufzeitlisten, InstanceCache.
     * Hängen aktive Komponenten von dieser ab, ist $force nötig - sie werden dann
     * zuerst deaktiviert (Reihenfolge im Ergebnis).
     *
     * @return string[]  deaktivierte "kind/Name", die Abhängigen zuerst
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
     * Migrationen von component.version auf update_version (14 §6.4), Installer::migrate(),
     * Zeiger je Version; danach version = update_version, tables neu, Caches und
     * Assets neu. Der Status bleibt, wie er war.
     *
     * @return array  Eintrag aus status()
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
     * Alle Komponenten mit wartendem Update - in Abhängigkeitsreihenfolge
     * (requires.components, topologisch). Teil von `upgrade`.
     *
     * @return string[]  aktualisierte "kind/Name"
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
     * Entfernen (14 §6.5): deactivate (falls aktiv), Installer::uninstall(), mit $purge
     * die Tabellen aus component.tables droppen, Zeile löschen, Snippet-Cache und
     * Assets der Komponente entfernen, Laufzeitlisten. Läuft auch für Verwaiste
     * (Dateien weg) - dann ohne Installer, --purge über die gespeicherte Tabellenliste.
     *
     * @return string[]  gedroppte Tabellen
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
     * Snippet-Präfix wie SnippetCache::sourceDirs(): Module 'blog', Prozessoren
     * 'proc.blog', Extensions 'ext.blog'.
     */
    public function prefix(array $entry): string
    {
        $prefixes = ['modules' => '', 'processors' => 'proc.', 'extensions' => 'ext.'];
        return ($prefixes[$entry['kind']] ?? '') . preg_replace('/[^a-z0-9_]/', '', strtolower($entry['name']));
    }

    /**
     * settings.php['tables'] als JSON für component.tables (nur gültige Bezeichner).
     */
    private function tablesJson(array $entry): ?string
    {
        $tables = array_values(array_filter(array_map('strval', (array)($entry['settings']['tables'] ?? [])),
            fn($t) => preg_match('/^[A-Za-z0-9_]+$/', $t)));
        return $tables ? json_encode($tables) : null;
    }

    /**
     * Dateien, die zu einer installierten Komponente gehören: Snippet- und HTML-Cache
     * (nur ihr Präfix) und publizierte Assets.
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
     * Assets nach public/backend/ext/{name}/ (Extensions, 13 §3.5) bzw.
     * public/backend/mod/{name}/ (Module) kopieren - flach, Dateiname bleibt. Vorher
     * wird das Verzeichnis geleert. Pfade in settings.php['assets'] sind relativ zu
     * public/ der Komponente; 'vendor:…'
     * zeigt in vendor/ des Projekts (Drittbibliotheken aus der composer.json der
     * Extension). Kopieren statt Symlink - vendor/ bleibt außerhalb des Docroots.
     * 'dirs' nennt ganze Verzeichnisse, die rekursiv unter ihrem Namen kopiert
     * werden (Bibliotheken, die ihre Teile relativ zum Script nachladen - TinyMCE:
     * skins/, themes/, plugins/ …); sie stehen nicht im Manifest.
     *
     * @return int  kopierte Dateien
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
     * Verzeichnis rekursiv kopieren (nur reguläre Dateien, keine Symlinks).
     *
     * @return int  kopierte Dateien
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
     * Quelle eines Asset-Eintrags (Datei, mit $dir ein Verzeichnis), null wenn
     * ungültig oder nicht vorhanden.
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
     * Verzeichnis unter public/backend/: ext/{name} bzw. mod/{name}, der Name
     * kleingeschrieben wie der Snippet-Präfix - so heißt die Extension auch in
     * data-c9-ext. '' für Arten ohne Assets.
     */
    private function assetDir(array $entry): string
    {
        $base = self::ASSET_DIRS[$entry['kind']] ?? '';
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($entry['name']));
        return $base === '' || $name === '' ? '' : "$base/$name";
    }

    /**
     * Manifest für die Laufzeitliste: ['js' => ['ext/calendar/calendar.ext.js?v=9.1.0'], 'css' => […]]
     * bzw. 'mod/{name}/…' für Module
     * (Pfade relativ zu public/backend/) - nur Dateien, die es wirklich gibt.
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
                // Version + Zeitpunkt des Publizierens: nach `refresh` holt der Browser
                // geänderte Dateien neu, auch ohne neue Versionsnummer (cApp.extensions
                // lädt sie dynamisch - ein "harter" Reload erfasst sie nicht immer)
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
     * Aktive Zusatzkomponenten, deren requires.components diese Komponente nennen.
     *
     * @param array|null $all  status(), sonst wird es geholt
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
     * Schlüssel der Einträge so sortiert, dass Abhängigkeiten (requires.components)
     * vor den Abhängigen stehen. Zyklen werden aufgebrochen (alphabetische Reihenfolge).
     *
     * @param array $entries  "kind/Name" => Eintrag
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
     * settings.php 'requires' prüfen (14 §5.3). Für installierte Komponenten -
     * eine 'discovered' prüft nur core/php/bin, die Abhängigkeiten zu anderen
     * Komponenten erst bei install/activate (dann sind sie Pflicht).
     *
     * @return string[]  Fehler
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
     * Tabelle `component`: "kind/Name" => Zeile
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
