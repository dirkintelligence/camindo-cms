<?php
/**
 * API for area administration (module Structure): read settings, create areas
 * (also from a blueprint), change, copy, read and set area rights.
 *
 * Everything here requires access to module Structure (the scripts area_edit.php
 * or area_access.php) - unlike AreaApi, whose read methods everyone may use,
 * who sees the records of an area. Create, change and copy go via
 * AreaService or Area::save(), i.e. the same checks as the mask.
 *
 * @see AreaService
 * @see Camindo\Core\Api\DatasetApi
 */
namespace Camindo\Core\Modules\Area;

use Camindo\Core\Cms;
use Camindo\Core\InstanceCache;
use Camindo\Core\Api\ApiContext;
use Camindo\Core\Api\ApiException;
use Camindo\Core\Api\ApiMethod;
use Camindo\Core\Api\DatasetApi;
use Camindo\Core\Api\DatasetWriter;

class AreaAdminApi extends DatasetApi
{
    protected const MODULE = 'Area';
    protected const SCRIPTS = ['list' => 'area_list.php', 'info' => 'area_info.php', 'edit' => 'area_edit.php'];
    protected const FIELDS = [
        'type', 'instance_id', 'parent_id', 'position', 'name', 'label', 'icon', 'note', 'dependent',
        'default_field_id', 'sort_field_id', 'sort_order', 'default_template_id', 'list_template_id',
        'trigger_template_ids', 'overview_template_id', 'login_name_field_id', 'login_password_field_id',
        'privacy_policy_id', 'details', 'access_group_ids',
    ];
    /** Change: without type (fixed since creation) and without access_group_ids (area.access_update) */
    protected const WRITABLE = [
        'instance_id', 'parent_id', 'name', 'label', 'icon', 'note', 'dependent',
        'default_field_id', 'sort_field_id', 'sort_order', 'default_template_id', 'list_template_id',
        'trigger_template_ids', 'overview_template_id', 'login_name_field_id', 'login_password_field_id',
        'privacy_policy_id', 'details',
    ];
    /** Create: plus type and the groups that may use the area right away */
    protected const CREATE_ONLY = ['type', 'access_group_ids'];
    protected const HINTS = ['type' => 'fixed since creation', 'access_group_ids' => 'change it with area.access_update',
        'position' => 'set it with previous_id'];
    /** Bereichsrechte je Gruppe (Zeile = Leserecht) */
    private const ACCESS_FLAGS = ['group_write', 'group_delete', 'other_write', 'other_delete', 'set_owner'];

    public function __construct(
        private Area $area,
        DatasetWriter $writer,
        private AreaService $service,
        private Cms $cms,
        private InstanceCache $instanceCache,
        private ApiContext $context,
    )
    {
        parent::__construct($area, $writer);
    }

    protected function options(): array
    {
        return ['type' => ['folder', 'record', 'profile', 'ugc', 'template'], 'sort_order' => ['ASC', 'DESC']];
    }

    /**
     * All settings of an area or folder - the values that area.update
     * writes (ids of fields and templates, details).
     *
     * @param int  $areaId      area or folder
     * @param bool $withSchema  plus the description of the fields
     */
    #[ApiMethod('area.settings', '#area.api_settings')]
    public function settings(int $areaId, bool $withSchema = false): array
    {
        return $this->apiInfo($areaId, $withSchema);
    }

    /**
     * The blueprints of an area type (prepared areas with fields).
     *
     * @param string $type  record | profile | ugc
     */
    #[ApiMethod('area.blueprints', '#area.api_blueprints')]
    public function blueprints(string $type): array
    {
        $this->writer->requireScript(self::MODULE, 'area_create.php');
        $list = [];
        foreach ($this->service->blueprints($type) as $name => $area) {
            $list[] = [
                'blueprint' => $name,
                'label'     => $this->context->plain($this->cms->textOrSnippet((string)($area['label'] ?? $name))),
                'name'      => (string)($area['name'] ?? ''),
                'fields'    => array_values(array_map(fn($field) => (string)($field['name'] ?? ''), (array)($area['fields'] ?? []))),
            ];
        }
        return ['type' => $type, 'blueprints' => $list];
    }

    /**
     * Create an area or folder, optionally from a blueprint (including fields).
     *
     * @param array  $data       field => value; required: type, instance_id, name, label (see area.settings with_schema)
     * @param string $blueprint  blueprint of the type (area.blueprints); empty = without fields
     * @param int    $previousId preceding area in the tree (position; 0 = at the start)
     * @param bool   $simulate   only check, create nothing
     */
    #[ApiMethod('area.create', '#area.api_create', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function create(array $data, string $blueprint = '', int $previousId = 0, bool $simulate = false): array
    {
        $this->writer->requireScript(self::MODULE, 'area_edit.php');
        $type = (string)($data['type'] ?? '');
        $defaults = ['type' => $type];
        if ($blueprint !== '') {
            $plan = $this->service->blueprint($type, $blueprint);
            if ($plan === null) throw new ApiException(ApiException::INVALID_PARAM, "unknown blueprint \"{$blueprint}\" for type \"{$type}\" (see area.blueprints)");
            $defaults += $this->service->blueprintDefaults($plan);
        }
        [$prepared, , $fields] = $this->writer->prepare($this->area, 0, $data, $this->writableOnCreate(),
            $this->writeOptions(['defaults' => $defaults, 'extra' => ['previous_id' => $previousId]]));
        if ($simulate) {
            $this->writer->check($this->area, [], $prepared);
            return ['id' => 0, 'simulated' => true, 'fields' => $fields, 'values' => null];
        }
        $areaId = $this->service->create($prepared, $blueprint);
        if ($areaId === null) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        if ($this->service->blueprintFailed()) $this->context->warn('The area was created, but not all fields of the blueprint: ' . $this->cms->errorMsg(' '));
        $this->cms->errorsReset();
        return $this->result(['id' => $areaId, 'simulated' => false, 'fields' => $fields]);
    }

    /**
     * Change settings of an area or folder.
     *
     * @param int   $areaId      area or folder
     * @param array $data        field => value, only those to change (see area.settings)
     * @param int   $previousId  preceding area (position; -1 = unchanged, 0 = at the start)
     * @param bool  $simulate    only check, change nothing
     */
    #[ApiMethod('area.update', '#area.api_update', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function update(int $areaId, array $data, int $previousId = -1, bool $simulate = false): array
    {
        return $this->apiUpdate($areaId, $data, $simulate, $previousId >= 0 ? ['extra' => ['previous_id' => $previousId], 'also' => ['parent_id']] : []);
    }

    /**
     * Copy an area: settings, area rights and input fields (without
     * records).
     *
     * @param int   $areaId    Original
     * @param array $data      label, name (Pflicht, eindeutig), icon, note, instance_id, parent_id, previous_id
     * @param bool  $simulate  only check, create nothing
     */
    #[ApiMethod('area.copy', '#area.api_copy', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function copy(int $areaId, array $data, bool $simulate = false): array
    {
        $this->writer->requireScript(self::MODULE, 'area_edit.php');
        $source = $this->writer->load($this->area, $areaId);
        $unknown = array_diff(array_keys($data), AreaService::COPY_FIELDS);
        if ($unknown) throw new ApiException(ApiException::INVALID_PARAM, 'field "' . implode('", "', $unknown) . '" cannot be set when copying (allowed: ' . implode(', ', AreaService::COPY_FIELDS) . ')');
        if ($simulate) {
            $this->writer->check($this->area, [], $this->service->copyData($source, $data));
            return ['id' => 0, 'simulated' => true, 'fields' => array_keys($data), 'values' => null];
        }
        $this->cms->errorsReset();
        $newId = $this->service->copy($source, $data);
        if ($newId === null) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        if ($this->service->blueprintFailed()) $this->context->warn('The area was copied, but not all fields: ' . $this->cms->errorMsg(' '));
        $this->cms->errorsReset();
        return $this->result(['id' => $newId, 'simulated' => false, 'fields' => array_keys($data)]);
    }

    /**
     * The area rights: per group that comes into question, whether it may read and the
     * remaining rights.
     *
     * @param int $areaId  Bereich
     */
    #[ApiMethod('area.access', '#area.api_access')]
    public function access(int $areaId): array
    {
        $this->writer->requireScript(self::MODULE, 'area_access.php');
        $data = $this->writer->load($this->area, $areaId);
        $rows = $this->area->accessRows($areaId);
        $groups = [];
        foreach ($this->area->accessCandidates((int)$data['instance_id']) as $groupId) {
            $group = $this->instanceCache->group($groupId) ?? [];
            $groups[] = ['group_id' => $groupId, 'label' => $this->context->plain($this->cms->textOrSnippet((string)($group['label'] ?? $groupId))),
                'read' => isset($rows[$groupId])] + array_map('boolval', $rows[$groupId] ?? array_fill_keys(self::ACCESS_FLAGS, 0));
        }
        return ['area_id' => $areaId, 'type' => (string)$data['type'], 'flags' => self::ACCESS_FLAGS, 'groups' => $groups];
    }

    /**
     * Set area rights - for the named groups; unnamed ones remain as they
     * are. Superusers may always do everything.
     *
     * @param int   $areaId    Bereich
     * @param array $access    group_id => {read, group_write, group_delete, other_write, other_delete, set_owner} (bool); read false = no access
     * @param bool  $simulate  only check, change nothing
     */
    #[ApiMethod('area.access_update', '#area.api_access_update', write: true, level: ApiMethod::LEVEL_ADMIN)]
    public function accessUpdate(int $areaId, array $access, bool $simulate = false): array
    {
        $this->writer->requireScript(self::MODULE, 'area_access.php');
        $data = $this->writer->load($this->area, $areaId);
        if ((string)$data['type'] === 'folder') throw new ApiException(ApiException::INVALID_PARAM, 'folders have no access rights');
        $candidates = $this->area->accessCandidates((int)$data['instance_id']);
        $rows = [];
        foreach ($this->area->accessRows($areaId) as $groupId => $flags) $rows[$groupId] = ['read' => 1] + $flags;
        $changed = [];
        foreach ($access as $groupId => $flags) {
            $groupId = (int)$groupId;
            if (!in_array($groupId, $candidates, true)) {
                throw new ApiException(ApiException::INVALID_PARAM, "group {$groupId} cannot get rights for this area (see area.access)");
            }
            if (!is_array($flags)) throw new ApiException(ApiException::INVALID_PARAM, "access.{$groupId} must be an object of flags");
            $unknown = array_diff(array_keys($flags), array_merge(['read'], self::ACCESS_FLAGS));
            if ($unknown) throw new ApiException(ApiException::INVALID_PARAM, 'unknown flag "' . implode('", "', $unknown) . '"');
            $row = ($rows[$groupId] ?? ['read' => 0] + array_fill_keys(self::ACCESS_FLAGS, 0));
            foreach ($flags as $flag => $value) $row[$flag] = (int)filter_var($value, FILTER_VALIDATE_BOOLEAN);
            $rows[$groupId] = $row;
            $changed[] = $groupId;
        }
        if (!$simulate) {
            $this->cms->errorsReset();
            if (!$this->area->saveAccess($areaId, $rows)) throw $this->context->exceptionFromCms(ApiException::VALIDATION);
        }
        return ['area_id' => $areaId, 'simulated' => $simulate, 'changed_groups' => $changed] + ($simulate ? [] : ['access' => $this->access($areaId)['groups']]);
    }
}
