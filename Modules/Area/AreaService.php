<?php
/**
 * Create and copy areas - the common path of the interface (AreaBackend)
 * and API (AreaApi). Both need more than Area::save():
 *
 *     create()  save the area; with blueprint, afterwards its fields and references
 *               anlegen (RecordImport::importBlueprint())
 *     copy()    save the area with the settings of the original, take over its
 *               area rights, and the fields via a blueprint of the
 *               Originals anlegen (RecordExport::areaBlueprintFile())
 *
 * Errors in the second step leave the area in place: the return value is then the new
 * id, the messages are in Cms::$errors (blueprintFailed()).
 *
 * BLUEPRINTS
 * --------------------
 * files blueprints/{type}/{name}.xml (export format: one area with settings
 * and fields, no records) - for the types BLUEPRINT_TYPES. Own blueprints
 * einer Installation liegen in var/area_blueprints/{name}.xml (blueprintFiles()).
 * Label and note of the <area> are the label and explanation in the selection when
 * creating (text or snippet reference #…).
 */
namespace Camindo\Core\Modules\Area;

use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Modules\Record\RecordExport;
use Camindo\Core\Modules\Record\RecordImport;

class AreaService
{
    /** Content types for which blueprints can exist (subfolders of blueprints/) */
    public const BLUEPRINT_TYPES = ['record', 'profile', 'ugc'];

    /** Own blueprints of an installation, relative to paths.var_dir */
    public const CUSTOM_BLUEPRINT_DIR = 'area_blueprints';

    /** Fields that a copy takes from the input - everything else from the original */
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
    // Blueprints

    /**
     * blueprints of a type: name (file without .xml) => area of the file
     * (RecordImport::readBlueprint()), alphabetisch.
     */
    public function blueprints(string $type): array
    {
        $blueprints = array_map(fn($file) => $file['area'], $this->blueprintFiles($type));
        ksort($blueprints, SORT_NATURAL | SORT_FLAG_CASE);
        return $blueprints;
    }

    /** A blueprint of the type - null if it does not exist */
    public function blueprint(string $type, string $name): ?array
    {
        if ($name === '' || !preg_match('/^[a-z0-9_\-]+$/i', $name)) return null;
        return $this->blueprintFiles($type)[$name]['area'] ?? null;
    }

    /**
     * Defaults of a blueprint for a new area: name, icon, sort_order,
     * details.tabs - label and note stay empty, the field references (default_field_id …)
     * only arise together with the fieldsn.
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
     * blueprint files of a type: name => ['path' => path, 'area' => area of the
     * file]. First those of the module
     * (blueprints/{type}/*.xml), then the installation's own
     * (var/area_blueprints/*.xml, without subfolders - the type is in the <area> of the
     * file). An own blueprint with the name of a bundled one replaces
     * it. Files whose area has a different type are dropped.
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

    /** Directory of the own blueprints of an installation */
    public function customBlueprintDir(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/') . '/' . self::CUSTOM_BLUEPRINT_DIR;
    }

    /** Path of blueprint $name for the type ('' = does not exist) */
    private function blueprintPath(string $type, string $name): string
    {
        if ($name === '' || !preg_match('/^[a-z0-9_\-]+$/i', $name)) return '';
        return $this->blueprintFiles($type)[$name]['path'] ?? '';
    }

    // ====================
    // Create and copy

    /**
     * Save a new area; with blueprint, create its fields afterwards.
     *
     * @param array  $data       data as Area::save() (area_id 0)
     * @param string $blueprint  name of a blueprint of the type ('' = none)
     * @return ?int              new id; null: area not saved (Cms::$errors)
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
     * Copy of an area: label, name, icon, instance, parent, predecessor and note
     * from $input (COPY_FIELDS), type, sort direction, details and deletion policy from the original. Afterwards
     * the area rights of the original and - via the blueprint import - its fields
     * and field references (record areas; folders and template areas only the
     * Template-Zuweisungen).
     *
     * @param array $source  Area::load() of the original
     * @param array $input   values from COPY_FIELDS
     * @return ?int          new id; null: not saved (Cms::$errors)
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

    /** The data of the copy (first step of copy()) - also for the check */
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
            // Deletion policy of the original (if it does not fit the target instance, sets
            // Area::hookOnSave() the default policy of the type)
            'privacy_policy_id' => (int)($source['privacy_policy_id'] ?? 0),
        ];
        // The slug prefix belongs to exactly one area (Area::slugPrefixErrors()) -
        // the copy starts without it
        unset($data['details']['slug_prefix']);
        return $data;
    }

    /** Did the second step (fields) fail in the last create()/copy()? */
    public function blueprintFailed(): bool
    {
        return $this->blueprintFailed;
    }
}
