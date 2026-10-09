<?php
/**
 * API der Eingabefelder (Modul Struktur): Feldtypen, ein Feld lesen, anlegen,
 * ändern, Typ bzw. Mehrsprachigkeit umwandeln.
 *
 * Wie die Maske (FieldEditBackend): Anlegen und Ändern schreiben alle Felder
 * (save([], …)), die drei Sichtbarkeiten und das Muster ohne ^/$ werden wie dort
 * aufbereitet. Darstellungsart und Kennzeichen prüft die API gegen dieselben Listen,
 * aus denen die Maske ihre Auswahl baut (Backend::$displayTypes, processingFlags(),
 * $validationFlags) - Field selbst prüft sie nicht.
 *
 * Verweise mit mehreren Zielbereichen (pointer, link): Wird ein Zielbereich
 * abgewählt, an dem noch Verknüpfungen hängen, fragt die Maske nach - hier braucht es
 * dafür remove_links = true, sonst antwortet update mit den betroffenen Anzahlen.
 *
 * Typ und Mehrsprachigkeit ändert nur field.convert (Field::convert()).
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
    /** Ändern: ohne Bereich und Typ; multilingual nur über field.convert */
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

    /** Verwendung: Quellfelder eines Volltextfeldes, mögliche Umwandlungen */
    protected function extra(int $id, array $data): array
    {
        $extra = [];
        if ((string)$data['type'] === 'fulltext') $extra['source_fields'] = array_values((array)($data['source_fields'] ?? []));
        $extra['conversion_targets'] = $this->field->conversionTargets($data);
        return $extra;
    }

    /**
     * Die Feldtypen mit Darstellungsarten und Kennzeichen (flags), die zu ihnen passen.
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
     * Ein Eingabefeld mit allen Einstellungen, mögliche Umwandlungen.
     *
     * @param int  $fieldId     Feld (field_id aus area.fields)
     * @param bool $withSchema  dazu die Beschreibung der Einstellungen
     */
    #[ApiMethod('field.info', '#area.api_field_info')]
    public function info(int $fieldId, bool $withSchema = false): array
    {
        return $this->apiInfo($fieldId, $withSchema);
    }

    /**
     * Ein Eingabefeld anlegen.
     *
     * @param int    $areaId        Bereich
     * @param string $type          Feldtyp (field.types)
     * @param array  $data          Feld => Wert: name, label, display_type (Pflicht), weitere siehe field.info with_schema
     * @param array  $sourceFields  nur Typ fulltext: field_ids der Quellfelder
     * @param bool   $simulate      nur prüfen, nichts anlegen
     */
    #[ApiMethod('field.create', '#area.api_field_create', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function create(int $areaId, string $type, array $data, array $sourceFields = [], bool $simulate = false): array
    {
        $this->writer->requireScript(self::MODULE, 'field_edit.php');
        $this->writer->load($this->area, $areaId);
        // das erste Feld eines Bereichs wird ungefragt zum Titelfeld (wie FieldCreateBackend)
        if (!array_key_exists('default_field', $data) && $this->area->fieldsNumber($areaId) < 1) $data['default_field'] = 1;
        $data = $this->prepareInput($type, $data);
        // Datumsfeld: Kalender vorauswählen wie in der Eingabemaske
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
     * Ein Eingabefeld ändern.
     *
     * @param int   $fieldId       Feld
     * @param array $data          Feld => Wert, nur die zu ändernden (siehe field.info)
     * @param array $sourceFields  nur Typ fulltext: field_ids der Quellfelder (leer = unverändert)
     * @param bool  $removeLinks   Zielbereiche abwählen, auch wenn dort noch Verknüpfungen hängen (sie werden entfernt)
     * @param bool  $simulate      nur prüfen, nichts ändern
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

        // abgewählte Zielbereiche mit Verknüpfungen: nur mit remove_links
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
     * Typ oder Mehrsprachigkeit eines Feldes umwandeln - der Bestand aller Datensätze
     * wird umkopiert. Der Probelauf nennt die möglichen Ziele und ob Inhalte verloren
     * gehen können.
     *
     * @param int    $fieldId         Feld
     * @param string $toType          Zieltyp (conversion_targets aus field.info)
     * @param bool   $toMultilingual  mehrsprachig danach
     * @param bool   $simulate        nur prüfen, nichts umwandeln
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
        // Offene Arbeitskopien: ihr Arbeitsstand wird mit umgewandelt, die Sperre zählt nicht
        $open = count($this->field->workingCopies((int)$data['area_id']));
        $result['open_working_copies'] = $open;
        if ($open > 0) {
            $this->context->warn("{$open} record(s) of the area are open for editing - their working state is converted as well, the edit lock is not respected");
        }
        if ($simulate) {
            // Vorbedingungen wie convert() (Ziel, Name, Präfix) - umkopiert wird nichts
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
     * Quellfelder eines Volltextfeldes: field_id bzw. über ein link-Feld
     * "{link_field_id}:{field_id}" (Field::sourceKey()) - geprüft wird in Field::hookOnSave().
     */
    private function sourceKeys(array $sourceFields): array
    {
        return array_map(fn($key) => Field::sourceKey(...Field::parseSourceKey($key)), array_values($sourceFields));
    }

    /** Ist das Ziel unter conversionTargets()? (Liste aus type/multilingual bzw. Typ => …) */
    private function isTarget(array $targets, string $type, int $multilingual): bool
    {
        foreach ($targets as $key => $target) {
            $targetType = is_array($target) ? (string)($target['type'] ?? $key) : (string)$target;
            if ($targetType !== $type) continue;
            if (!is_array($target) || !array_key_exists('multilingual', $target) || (int)$target['multilingual'] === $multilingual) return true;
        }
        return false;
    }

    /** Kennzeichen, die zum Typ passen (Aufbereitung und Prüfung) */
    private function allowedFlags(string $type): array
    {
        return array_values(array_unique(array_merge($this->backend->processingFlags($type), (array)($this->backend->validationFlags[$type] ?? []))));
    }

    /**
     * Eingabe wie die Maske aufbereiten und Darstellungsart/Kennzeichen prüfen.
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
