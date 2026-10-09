<?php
/**
 * API of module Area (Structure): read content areas and their input fields.
 *
 *     area.list    the readable content areas with the rights of the group
 *     area.info    an area: instance, languages, default and sort field, tree …
 *     area.fields  the input fields of an area - e.g. to plan the field assignment when
 *                  moving a record (source and target area)
 *
 * Reading only needs the read right on the area (like the list and mask of module
 * Record), not module access to Structure - the field list of an area is seen
 * by everyone who sees its records.
 *
 * @see Camindo\Core\Api\Api
 */
namespace Camindo\Core\Modules\Area;

use Camindo\Core\Access;
use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\Api\ApiContext;
use Camindo\Core\Api\ApiException;
use Camindo\Core\Api\ApiMethod;
use Camindo\Core\Modules\Record\Record;

class AreaApi
{
    public function __construct(
        private Cms $cms,
        private Database $db,
        private Backend $backend,
        private InstanceCache $instanceCache,
        private Access $access,
        private Record $record,
        private ApiContext $context,
    )
    {
    }

    /**
     * The readable content areas.
     *
     * @param int   $instanceId  only areas of this instance (0 = all assigned)
     * @param array $types       area types: record, profile, ugc (empty = all three)
     * @return array  list of area_id, name, label, type, instance_id, parent_id, depth,
     *                dependent, tree, records (Anzahl), can (create, write_own, write_other,
     *                delete_own, delete_other, set_owner)
     */
    #[ApiMethod('area.list', '#area.api_list')]
    public function list(int $instanceId = 0, array $types = []): array
    {
        $this->context->require();
        $types = array_values(array_intersect(array_map('strval', $types), Area::CONTENT_TYPES)) ?: Area::CONTENT_TYPES;
        $areaIds = $this->access->readableAreaIds($types);
        if (!$areaIds) return [];

        // Number of live records per area (one query)
        $counts = [];
        $this->db->pushResult();
        $this->db->query(
            'SELECT `area_id`, COUNT(*) AS `n` FROM `record` WHERE `tmp` = 0 AND `deleted` = 0'
            . ' AND `area_id` IN (' . implode(',', array_map('intval', $areaIds)) . ') GROUP BY `area_id`'
        );
        while ($row = $this->db->fetch()) $counts[(int)$row['area_id']] = (int)$row['n'];
        $this->db->popResult();

        $list = [];
        foreach ($areaIds as $areaId) {
            $data = $this->backend->areaData($areaId);
            if (!$data) continue;
            if ($instanceId > 0 && (int)$data['instance_id'] !== $instanceId) continue;
            $list[] = [
                'area_id'     => $areaId,
                'name'        => (string)$data['name'],
                'label'       => $this->plain((string)$data['label']),
                'type'        => (string)$data['type'],
                'instance_id' => (int)$data['instance_id'],
                'parent_id'   => (int)($data['parent_id'] ?? 0) ?: null,
                'depth'       => (int)($data['depth'] ?? 0),
                'dependent'   => !empty($data['dependent']),
                'tree'        => $this->record->isTreeArea($areaId),
                'records'     => $counts[$areaId] ?? 0,
                'can'         => $this->rights($areaId),
            ];
        }
        return $list;
    }

    /**
     * A content area.
     *
     * @param int $areaId  Bereich
     * @return array  area_id, name, label, note, type, instance, languages (codes, main language first),
     *                default_field, sort (field, order), tree, dependent, language_tabs,
     *                slug_prefix (code => prefix), fields (count), can
     */
    #[ApiMethod('area.info', '#area.api_info')]
    public function info(int $areaId): array
    {
        $this->context->require();
        $data = $this->readable($areaId);
        $details = is_array($data['details'] ?? null) ? $data['details'] : (json_decode((string)($data['details'] ?? ''), true) ?: []);
        $instanceId = (int)$data['instance_id'];
        $instance = $this->instanceCache->instance($instanceId) ?? [];

        $languages = [];
        foreach ($this->instanceCache->languages($instanceId) as $languageId => $row) {
            if (!$this->access->languageRead((int)$languageId)) continue;
            $languages[] = ['code' => (string)$row['code'], 'write' => $this->access->languageWrite((int)$languageId)];
        }
        $slugPrefix = [];
        foreach ((array)($details['slug_prefix'] ?? []) as $languageId => $prefix) {
            if ((string)$prefix === '') continue;
            $slugPrefix[$this->context->languageCode((int)$languageId) ?: (string)$languageId] = (string)$prefix;
        }

        return [
            'area_id'       => $areaId,
            'name'          => (string)$data['name'],
            'label'         => $this->plain((string)$data['label']),
            'note'          => $this->plain((string)($data['note'] ?? '')),
            'type'          => (string)$data['type'],
            'instance'      => ['instance_id' => $instanceId, 'label' => $this->plain((string)($instance['label'] ?? ''))],
            'languages'     => $languages,
            'default_field' => (string)($data['default_field_name'] ?? '') ?: null,
            'sort'          => ['field' => (string)($data['sort_field_name'] ?? 'changed_at'), 'order' => (string)($data['sort_order'] ?? 'DESC') ?: 'DESC'],
            'tree'          => $this->record->isTreeArea($areaId),
            'dependent'     => !empty($data['dependent']),
            'language_tabs' => (int)($details['tabs'] ?? 0) === 1,
            'slug_prefix'   => $slugPrefix,
            'fields'        => count($this->record->virtualSchema($areaId, false)),
            'can'           => $this->rights($areaId),
        ];
    }

    /**
     * The input fields of an area.
     *
     * @param int  $areaId    Bereich
     * @param bool $withMeta  also the meta fields (columns of record: record_id, status_id, created_at …)
     * @return array  list of name, field_id, label, type, display_type, multilingual, required, unique,
     *                min, max, pattern, options, default, rel_areas, parent_field, visible (list, input, search),
     *                default_field, note, tooltip, meta
     */
    #[ApiMethod('area.fields', '#area.api_fields')]
    public function fields(int $areaId, bool $withMeta = false): array
    {
        $this->context->require();
        $this->readable($areaId);
        $list = [];
        foreach ($this->record->virtualSchema($areaId, $withMeta) as $name => $colDef) {
            // Link fields without a readable target area are not shown by the mask
            if (!$this->record->relationTargetReadable($colDef)) continue;
            $type = (string)($colDef['type'] ?? '');
            $displayType = (string)($colDef['display_type'] ?? '');
            $flags = (array)($colDef['flags'] ?? []);
            $fieldId = (int)($colDef['field_id'] ?? 0);

            $relAreas = [];
            if (in_array($type, $this->cms->relationTypes, true) && $fieldId > 0) {
                $ids = str_starts_with($type, 'relation')
                    ? [(int)($colDef['rel_area_id'] ?? 0)]
                    : array_map('intval', $this->record->getRelAreaIds($fieldId));
                foreach ($ids as $id) {
                    if ($id < 1 || !($relData = $this->backend->areaData($id))) continue;
                    $relAreas[] = ['area_id' => $id, 'name' => (string)$relData['name'], 'label' => $this->plain((string)$relData['label']),
                        'readable' => $this->access->areaRead($id)];
                }
            }
            $options = null;
            if (in_array($displayType, ['select', 'multiselect', 'radio', 'checkbox'], true)
                && !in_array($type, $this->cms->relationTypes, true) && is_string($colDef['default'] ?? null)) {
                $options = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$colDef['default'])), fn($o) => $o !== ''));
            }

            $list[] = [
                'name'          => (string)$name,
                'field_id'      => $fieldId ?: null,
                'label'         => $this->plain((string)($colDef['label'] ?? $name)),
                'type'          => $type,
                'display_type'  => $displayType,
                'multilingual'  => $this->cms->isMultilingualField($colDef),
                'required'      => in_array('not_empty', $flags, true),
                'unique'        => in_array('unique', $flags, true) || in_array('unique_in_area', $flags, true),
                'min'           => ($colDef['min'] ?? '') === '' ? null : $colDef['min'],
                'max'           => ($colDef['max'] ?? '') === '' ? null : $colDef['max'],
                'pattern'       => (string)($colDef['pattern'] ?? '') ?: null,
                'options'       => $options,
                'default'       => $options === null && is_scalar($colDef['default'] ?? null) && (string)$colDef['default'] !== '' ? (string)$colDef['default'] : null,
                'rel_areas'     => $relAreas ?: null,
                'parent_field'  => (int)($colDef['parent_field_id'] ?? 0) ?: null,
                'visible'       => [
                    'list'   => (int)($colDef['visibility_list'] ?? 0) === 1,
                    'input'  => (int)($colDef['visibility_input'] ?? 0) === 1,
                    'search' => (int)($colDef['visibility_search'] ?? 0) === 1,
                ],
                'default_field' => (int)($colDef['default_field'] ?? 0) === 1,
                'note'          => $this->plain((string)($colDef['note'] ?? '')) ?: null,
                'tooltip'       => $this->plain((string)($colDef['tooltip'] ?? '')) ?: null,
                'meta'          => (int)($colDef['meta'] ?? 0) === 1,
            ];
        }
        return $list;
    }

    // ====================

    /**
     * Area data with read right - otherwise not_found or access_denied.
     */
    private function readable(int $areaId): array
    {
        $data = $areaId > 0 ? $this->backend->areaData($areaId) : null;
        if (!$data || !Area::isContentType((string)$data['type'])) {
            throw new ApiException(ApiException::NOT_FOUND, $this->cms->snippet('area.error_area_not_found'));
        }
        if (!$this->access->areaRead($areaId)) {
            throw new ApiException(ApiException::ACCESS_DENIED, $this->cms->snippet('error_access_denied'));
        }
        return $data;
    }

    /**
     * Rights of the group in the area - for records of the own or other groups.
     */
    private function rights(int $areaId): array
    {
        if ($this->access->isSuperuser()) {
            return ['create' => true, 'write_own' => true, 'write_other' => true,
                'delete_own' => true, 'delete_other' => true, 'set_owner' => true];
        }
        $flags = $this->access->areaFlags($areaId);
        return [
            'create'       => $this->access->areaCreate($areaId),
            'write_own'    => !empty($flags['group_write']),
            'write_other'  => !empty($flags['other_write']),
            'delete_own'   => !empty($flags['group_delete']),
            'delete_other' => !empty($flags['other_delete']),
            'set_owner'    => !empty($flags['set_owner']),
        ];
    }

    private function plain(string $text): string
    {
        return $this->context->plain($this->cms->textOrSnippet($text));
    }
}
