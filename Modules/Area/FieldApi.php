<?php
/**
 * API of the input fields (module Structure): field types, read a field, create,
 * change, convert type or multilingualism.
 *
 * As the mask (FieldEditBackend): create and change write all fields
 * (save([], …)), the three visibilities and the pattern without ^/$ are prepared as there.
 * The API checks display type and flags against the same lists,
 * from which the mask builds its selection (Backend::$displayTypes, processingFlags(),
 * $validationFlags) - Field itself does not check them.
 *
 * References with several target areas (pointer, link): if a target area
 * is deselected that still has links attached, the mask asks - here this requires
 * remove_links = true, otherwise update answers with the affected counts.
 *
 * Only field.convert (Field::convert()) changes type and multilingualism.
 *
 * @see Camindo\Core\Api\DatasetApi
 */
namespace Camindo\Core\Modules\Area;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Api\ApiContext;
use Camindo\Core\Api\ApiException;
use Camindo\Core\Api\ApiMethod;
use Camindo\Core\Api\DatasetApi;
use Camindo\Core\Api\DatasetWriter;

class FieldApi extends DatasetApi
{
    protected const MODULE = 'Area';
    protected const SCRIPTS = ['list' => 'field_list.php', 'info' => 'field_info.php', 'edit' => 'field_edit.php'];
    protected const FIELDS = [
        'area_id', 'type', 'name', 'label', 'display_type', 'note', 'tooltip', 'height', 'prefix', 'suffix', 'pattern',
        'min', 'min_op', 'max', 'max_op', 'position', 'default', 'flags', 'multilingual', 'default_field',
        'visibility_input', 'visibility_search', 'visibility_list', 'rel_area_id', 'rel_area_ids', 'custom_order',
        'parent_field_id', 'converter_ids', 'details',
    ];
    /** Change: without area and type; multilingual only via field.convert */
    protected const WRITABLE = [
        'name', 'label', 'display_type', 'note', 'tooltip', 'height', 'prefix', 'suffix', 'pattern',
        'min', 'min_op', 'max', 'max_op', 'position', 'default', 'flags', 'default_field',
        'visibility_input', 'visibility_search', 'visibility_list', 'rel_area_id', 'rel_area_ids', 'custom_order',
        'parent_field_id', 'converter_ids', 'details',
    ];
    protected const CREATE_ONLY = ['multilingual'];
    protected const HINTS = [
        'area_id' => 'parameter area_id', 'type' => 'parameter type of field.create; change it with field.convert',
        'multilingual' => 'change it with field.convert',
    ];

    public function __construct(
        private Field $field,
        DatasetWriter $writer,
        private Area $area,
        private Backend $backend,
        private Cms $cms,
        private ApiContext $context,
    )
    {
        parent::__construct($field, $writer);
    }

    /** Usage: source fields of a full-text field, possible conversions */
    protected function extra(int $id, array $data): array
    {
        $extra = [];
        if ((string)$data['type'] === 'fulltext') $extra['source_fields'] = array_values((array)($data['source_fields'] ?? []));
        $extra['conversion_targets'] = $this->field->conversionTargets($data);
        return $extra;
    }

    /**
     * The field types with display types and flags (flags) that match them.
     */
    #[ApiMethod('field.types', '#area.api_field_types')]
    public function types(): array
    {
        $this->writer->requireScript(self::MODULE, 'field_create.php');
        $list = [];
        foreach (array_diff($this->cms->types, $this->cms->noColumnTypes) as $type) {
            $label = $this->cms->snippet('area.label_field_type_' . $type);
            $note = $this->cms->snippet('area.note_field_type_' . $type);
            $list[] = [
                'type'          => $type,
                'label'         => $label !== 'area.label_field_type_' . $type ? $this->context->plain($label) : $type,
                'note'          => $note !== 'area.note_field_type_' . $type ? $this->context->plain($note) : null,
                'display_types' => array_values((array)($this->backend->displayTypes[$type] ?? [])),
                'flags'         => $this->allowedFlags($type),
                'multilingual'  => in_array($type, $this->cms->multilingualTypes, true),
            ];
        }
        return ['types' => $list];
    }

    /**
     * An input field with all settings, possible conversions.
     *
     * @param int  $fieldId     field (field_id from area.fields)
     * @param bool $withSchema  plus the description of the settings
     */
    #[ApiMethod('field.info', '#area.api_field_info')]
    public function info(int $fieldId, bool $withSchema = false): array
    {
        return $this->apiInfo($fieldId, $withSchema);
    }

    /**
     * Create an input field.
     *
     * @param int    $areaId        Bereich
     * @param string $type          Feldtyp (field.types)
     * @param array  $data          field => value: name, label, display_type (required), further see field.info with_schema
     * @param array  $sourceFields  only type fulltext: field_ids of the source fields
     * @param bool   $simulate      only check, create nothing
     */
    #[ApiMethod('field.create', '#area.api_field_create', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function create(int $areaId, string $type, array $data, array $sourceFields = [], bool $simulate = false): array
    {
        $this->writer->requireScript(self::MODULE, 'field_edit.php');
        $this->writer->load($this->area, $areaId);
        // the first field of an area becomes the title field without asking (as FieldCreateBackend)
        if (!array_key_exists('default_field', $data) && $this->area->fieldsNumber($areaId) < 1) $data['default_field'] = 1;
        $data = $this->prepareInput($type, $data);
        // Date field: pre-select the calendar as in the input mask
        $preset = ['type' => $type] + $data;
        $this->field->presetExtension($preset);
        if (isset($preset['details'])) $data['details'] = $preset['details'];
        $options = ['defaults' => ['area_id' => $areaId, 'type' => $type]] + ($sourceFields ? ['extra' => ['source_fields' => $this->sourceKeys($sourceFields)]] : []);
        [$prepared, , $fields] = $this->writer->prepare($this->field, 0, $data, $this->writableOnCreate(), $this->writeOptions($options));
        if ($simulate) {
            $this->writer->check($this->field, [], $prepared);
            return ['id' => 0, 'simulated' => true, 'fields' => $fields, 'values' => null];
        }
        $this->cms->errorsReset();
        $id = $this->field->save([], $prepared);
        if ($id === null) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        $this->cms->errorsReset();
        return $this->result(['id' => (int)$id, 'simulated' => false, 'fields' => $fields]);
    }

    /**
     * Change an input field.
     *
     * @param int   $fieldId       Feld
     * @param array $data          field => value, only those to change (see field.info)
     * @param array $sourceFields  only type fulltext: field_ids of the source fields (empty = unchanged)
     * @param bool  $removeLinks   deselect target areas, even if links are still attached there (they are removed)
     * @param bool  $simulate      only check, change nothing
     */
    #[ApiMethod('field.update', '#area.api_field_update', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function update(int $fieldId, array $data, array $sourceFields = [], bool $removeLinks = false, bool $simulate = false): array
    {
        $this->writer->requireScript(self::MODULE, 'field_edit.php');
        $current = $this->writer->load($this->field, $fieldId);
        $type = (string)$current['type'];
        $data = $this->prepareInput($type, $data, (string)$current['display_type']);
        $options = ['all' => true] + ($sourceFields ? ['extra' => ['source_fields' => $this->sourceKeys($sourceFields)]] : []);
        [$prepared, , $fields] = $this->writer->prepare($this->field, $fieldId, $data, self::WRITABLE, $this->writeOptions($options));

        // deselected target areas with links: only with remove_links
        $multiArea = in_array($type, $this->cms->multiAreaTypes, true);
        if ($multiArea && array_key_exists('rel_area_ids', $data)) {
            $counts = $this->field->countRelationsOutside($fieldId, array_map('intval', (array)$prepared['rel_area_ids']));
            if ($counts && !$removeLinks) {
                throw new ApiException(ApiException::VALIDATION, 'Records of the removed target areas are still linked: '
                    . json_encode($counts) . ' - call again with remove_links = true to remove these links.');
            }
        }
        if ($simulate) {
            $this->writer->check($this->field, [], $prepared);
            return ['id' => $fieldId, 'simulated' => true, 'fields' => $fields, 'values' => null];
        }
        $this->cms->errorsReset();
        if ($this->field->save([], $prepared) === null) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        if ($multiArea && $removeLinks) $this->field->removeRelationsOutside($fieldId);
        $this->cms->errorsReset();
        return $this->result(['id' => $fieldId, 'simulated' => false, 'fields' => $fields]);
    }

    /**
     * Convert the type or multilingualism of a field - the stock of all records
     * is copied over. The trial run names the possible targets and whether content can be lost.
     *
     * @param int    $fieldId         Feld
     * @param string $toType          target type (conversion_targets from field.info)
     * @param bool   $toMultilingual  mehrsprachig danach
     * @param bool   $simulate        only check, convert nothing
     */
    #[ApiMethod('field.convert', '#area.api_field_convert', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function convert(int $fieldId, string $toType, bool $toMultilingual, bool $simulate = false): array
    {
        $this->writer->requireScript(self::MODULE, 'field_convert.php');
        $data = $this->writer->load($this->field, $fieldId);
        $targets = $this->field->conversionTargets($data);
        $fromType = (string)$data['type'];
        $fromMultilingual = (int)$data['multilingual'];
        if ($toType === $fromType && (int)$toMultilingual === $fromMultilingual) {
            throw new ApiException(ApiException::INVALID_PARAM, 'nothing to convert');
        }
        if (!$this->isTarget($targets, $toType, (int)$toMultilingual)) {
            throw new ApiException(ApiException::INVALID_PARAM, "\"{$fromType}\" cannot be converted like this - possible: " . json_encode($targets));
        }
        $lossy = $this->field->conversionLossy($fromType, $toType, $fromMultilingual, (int)$toMultilingual);
        $result = ['id' => $fieldId, 'from' => ['type' => $fromType, 'multilingual' => (bool)$fromMultilingual],
            'to' => ['type' => $toType, 'multilingual' => $toMultilingual], 'lossy' => $lossy, 'simulated' => $simulate];
        // Open working copies: their working state is converted along, the lock does not count
        $open = count($this->field->workingCopies((int)$data['area_id']));
        $result['open_working_copies'] = $open;
        if ($open > 0) {
            $this->context->warn("{$open} record(s) of the area are open for editing - their working state is converted as well, the edit lock is not respected");
        }
        if ($simulate) {
            // Preconditions as in convert() (target, name, prefix) - nothing is copied over
            $this->cms->errorsReset();
            if (!$this->field->conversionAllowed($data, $toType, (int)$toMultilingual)) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
            return $result;
        }
        $this->cms->errorsReset();
        if (!$this->field->convert($fieldId, $toType, (int)$toMultilingual)) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        $this->cms->errorsReset();
        return $result;
    }

    // ====================

    /**
     * Source fields of a full-text field: field_id or via a link field
     * "{link_field_id}:{field_id}" (Field::sourceKey()) - checked in Field::hookOnSave().
     */
    private function sourceKeys(array $sourceFields): array
    {
        return array_map(fn($key) => Field::sourceKey(...Field::parseSourceKey($key)), array_values($sourceFields));
    }

    /** Is the target among conversionTargets()? (list of type/multilingual or type => …) */
    private function isTarget(array $targets, string $type, int $multilingual): bool
    {
        foreach ($targets as $key => $target) {
            $targetType = is_array($target) ? (string)($target['type'] ?? $key) : (string)$target;
            if ($targetType !== $type) continue;
            if (!is_array($target) || !array_key_exists('multilingual', $target) || (int)$target['multilingual'] === $multilingual) return true;
        }
        return false;
    }

    /** Flags that match the type (preparation and check) */
    private function allowedFlags(string $type): array
    {
        return array_values(array_unique(array_merge($this->backend->processingFlags($type), (array)($this->backend->validationFlags[$type] ?? []))));
    }

    /**
     * Prepare input like the mask and check display type/flags.
     *
     * @throws ApiException  invalid_param
     */
    private function prepareInput(string $type, array $data, string $currentDisplayType = ''): array
    {
        if (!in_array($type, array_diff($this->cms->types, $this->cms->noColumnTypes), true)) {
            throw new ApiException(ApiException::INVALID_PARAM, "unknown field type \"{$type}\" (see field.types)");
        }
        $displayTypes = array_values((array)($this->backend->displayTypes[$type] ?? []));
        if (array_key_exists('display_type', $data) && $type !== 'file' && !in_array((string)$data['display_type'], $displayTypes, true)) {
            throw new ApiException(ApiException::INVALID_PARAM, "display_type must be one of " . implode(', ', $displayTypes));
        }
        if ($currentDisplayType === '' && !array_key_exists('display_type', $data) && $displayTypes) $data['display_type'] = $displayTypes[0];
        if (array_key_exists('flags', $data)) {
            $unknown = array_diff((array)$data['flags'], $this->allowedFlags($type));
            if ($unknown) throw new ApiException(ApiException::INVALID_PARAM, 'flag "' . implode('", "', $unknown) . '" not allowed for ' . $type . ' (allowed: ' . implode(', ', $this->allowedFlags($type)) . ')');
            $data['flags'] = array_values((array)$data['flags']);
        }
        if (isset($data['pattern']) && is_string($data['pattern'])) $data['pattern'] = rtrim(ltrim($data['pattern'], '^'), '$');
        return $data;
    }
}
