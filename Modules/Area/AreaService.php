<?php
/**
 * Bereiche anlegen und kopieren - der gemeinsame Weg von Oberfläche (AreaBackend)
 * und API (AreaApi). Beides braucht mehr als Area::save():
 *
 *     create()  Bereich speichern; mit Bauplan danach dessen Felder und Bezüge
 *               anlegen (RecordImport::importBlueprint())
 *     copy()    Bereich mit den Einstellungen des Originals speichern, dessen
 *               Bereichsrechte übernehmen und die Felder über einen Bauplan des
 *               Originals anlegen (RecordExport::areaBlueprintFile())
 *
 * Fehler im zweiten Schritt lassen den Bereich bestehen: Rückgabe ist dann die neue
 * Id, die Meldungen stehen in Cms::$errors (blueprintFailed()).
 *
 * BAUPLÄNE
 * --------------------
 * Dateien blueprints/{type}/{name}.xml (Exportformat: ein Bereich mit Einstellungen
 * und Feldern, keine Datensätze) - für die Typen BLUEPRINT_TYPES. Eigene Baupläne
 * einer Installation liegen in var/area_blueprints/{name}.xml (blueprintFiles()).
 * Label und Notiz des <area> sind Beschriftung und Erklärung in der Auswahl beim
 * Anlegen (Text oder Snippet-Verweis #…).
 */
namespace Camindo\Core\Modules\Area;

use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Modules\Record\RecordExport;
use Camindo\Core\Modules\Record\RecordImport;

class AreaService
{
    /** Content-Typen, für die es Baupläne geben kann (Unterordner von blueprints/) */
    public const BLUEPRINT_TYPES = ['record', 'profile', 'ugc'];

    /** Eigene Baupläne einer Installation, relativ zu paths.var_dir */
    public const CUSTOM_BLUEPRINT_DIR = 'area_blueprints';

    /** Felder, die eine Kopie aus der Eingabe nimmt - alles andere vom Original */
    public const COPY_FIELDS = ['instance_id', 'parent_id', 'previous_id', 'label', 'name', 'icon', 'note'];

    private bool $blueprintFailed = false;

    public function __construct(
        private Area $area,
        private Cms $cms,
        private Config $config,
        private RecordImport $import,
        private RecordExport $export,
    )
    {
    }

    // ====================
    // Baupläne

    /**
     * Baupläne eines Typs: Name (Datei ohne .xml) => Bereich der Datei
     * (RecordImport::readBlueprint()), alphabetisch.
     */
    public function blueprints(string $type): array
    {
        $blueprints = array_map(fn($file) => $file['area'], $this->blueprintFiles($type));
        ksort($blueprints, SORT_NATURAL | SORT_FLAG_CASE);
        return $blueprints;
    }

    /** Ein Bauplan des Typs - null, wenn es ihn nicht gibt */
    public function blueprint(string $type, string $name): ?array
    {
        if ($name === '' || !preg_match('/^[a-z0-9_\-]+$/i', $name)) return null;
        return $this->blueprintFiles($type)[$name]['area'] ?? null;
    }

    /**
     * Vorgaben eines Bauplans für einen neuen Bereich: name, icon, sort_order,
     * details.tabs - Label und Notiz bleiben leer, die Feldbezüge (default_field_id …)
     * entstehen erst mit den Feldern.
     */
    public function blueprintDefaults(?array $blueprint): array
    {
        $defaults = [];
        if ($blueprint === null) return $defaults;
        foreach (['name', 'icon', 'sort_order'] as $key) {
            if (isset($blueprint[$key]) && (string)$blueprint[$key] !== '') $defaults[$key] = (string)$blueprint[$key];
        }
        if (isset($blueprint['details']['tabs'])) $defaults['details'] = ['tabs' => (int)$blueprint['details']['tabs']];
        return $defaults;
    }

    /**
     * Bauplan-Dateien eines Typs: Name => ['path' => Pfad, 'area' => Bereich der
     * Datei]. Erst die des Moduls
     * (blueprints/{type}/*.xml), dann die eigenen der Installation
     * (var/area_blueprints/*.xml, ohne Unterordner - der Typ steht im <area> der
     * Datei). Ein eigener Bauplan mit dem Namen eines mitgelieferten ersetzt
     * diesen. Dateien, deren Bereich einen anderen Typ hat, fallen heraus.
     */
    private function blueprintFiles(string $type): array
    {
        if (!in_array($type, self::BLUEPRINT_TYPES, true)) return [];
        $files = [];
        $paths = array_merge(
            glob(__DIR__ . '/blueprints/' . $type . '/*.xml') ?: [],
            glob($this->customBlueprintDir() . '/*.xml') ?: []
        );
        foreach ($paths as $path) {
            $name = basename($path, '.xml');
            if (!preg_match('/^[a-z0-9_\-]+$/i', $name)) continue;
            $area = $this->import->readBlueprint($path);
            if ($area === null || (string)($area['type'] ?? '') !== $type) continue;
            $files[$name] = ['path' => $path, 'area' => $area];
        }
        return $files;
    }

    /** Verzeichnis der eigenen Baupläne einer Installation */
    public function customBlueprintDir(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/') . '/' . self::CUSTOM_BLUEPRINT_DIR;
    }

    /** Pfad des Bauplans $name für den Typ ('' = gibt es nicht) */
    private function blueprintPath(string $type, string $name): string
    {
        if ($name === '' || !preg_match('/^[a-z0-9_\-]+$/i', $name)) return '';
        return $this->blueprintFiles($type)[$name]['path'] ?? '';
    }

    // ====================
    // Anlegen und kopieren

    /**
     * Neuen Bereich speichern, mit Bauplan danach dessen Felder anlegen.
     *
     * @param array  $data       Daten wie Area::save() (area_id 0)
     * @param string $blueprint  Name eines Bauplans des Typs ('' = keiner)
     * @return ?int              neue Id; null: Bereich nicht gespeichert (Cms::$errors)
     */
    public function create(array $data, string $blueprint = ''): ?int
    {
        $this->blueprintFailed = false;
        $areaId = (int)$this->area->save([], $data);
        if ($areaId < 1) return null;
        if ($blueprint !== '') {
            $this->cms->errorsReset();
            $path = $this->blueprintPath((string)($data['type'] ?? ''), $blueprint);
            $count = $path !== '' ? $this->import->importBlueprint($path, $areaId) : -1;
            if ($count < 0) $this->cms->error($this->cms->snippet('area.error_blueprint_not_found'));
            $this->blueprintFailed = !empty($this->cms->errors);
        }
        return $areaId;
    }

    /**
     * Kopie eines Bereichs: Label, Name, Icon, Instanz, Vater, Vorgänger und Hinweis
     * aus $input (COPY_FIELDS), Typ, Sortierrichtung, details und Lösch-Policy vom Original. Danach
     * die Bereichsrechte des Originals und - über den Bauplan-Import - seine Felder
     * und Feldbezüge (Datensatzbereiche; Ordner und Template-Bereiche nur die
     * Template-Zuweisungen).
     *
     * @param array $source  Area::load() des Originals
     * @param array $input   Werte aus COPY_FIELDS
     * @return ?int          neue Id; null: nicht gespeichert (Cms::$errors)
     */
    public function copy(array $source, array $input): ?int
    {
        $this->blueprintFailed = false;
        $data = $this->copyData($source, $input);
        $areaId = (int)$this->area->save([], $data);
        if ($areaId < 1) return null;
        $sourceId = (int)$source['area_id'];
        $this->area->copyAccess($sourceId, $areaId);

        $this->cms->errorsReset();
        $path = $this->export->areaBlueprintFile($sourceId);
        $count = ($path !== '') ? $this->import->importBlueprint($path, $areaId) : -1;
        if ($path !== '') @unlink($path);
        if ($count < 0) $this->cms->error($this->cms->snippet('area.error_blueprint_not_found'));
        $this->blueprintFailed = !empty($this->cms->errors);
        return $areaId;
    }

    /** Die Daten der Kopie (erster Schritt von copy()) - auch für die Prüfung */
    public function copyData(array $source, array $input): array
    {
        $data = [
            'area_id'     => 0,
            'type'        => (string)$source['type'],
            'instance_id' => (int)($input['instance_id'] ?? $source['instance_id']),
            'parent_id'   => (int)($input['parent_id'] ?? 0),
            'previous_id' => (int)($input['previous_id'] ?? 0),
            'label'       => (string)($input['label'] ?? ''),
            'name'        => (string)($input['name'] ?? ''),
            'icon'        => (string)($input['icon'] ?? ''),
            'note'        => (string)($input['note'] ?? ''),
            'sort_order'  => (string)($source['sort_order'] ?? ''),
            'details'     => (array)($source['details'] ?? []),
            // Lösch-Policy des Originals (passt sie nicht zur Ziel-Instanz, setzt
            // Area::hookOnSave() die Standard-Policy des Typs)
            'privacy_policy_id' => (int)($source['privacy_policy_id'] ?? 0),
        ];
        // Der Slug-Präfix gehört genau einem Bereich (Area::slugPrefixErrors()) -
        // die Kopie startet ohne
        unset($data['details']['slug_prefix']);
        return $data;
    }

    /** Ist beim letzten create()/copy() der zweite Schritt (Felder) gescheitert? */
    public function blueprintFailed(): bool
    {
        return $this->blueprintFailed;
    }
}
