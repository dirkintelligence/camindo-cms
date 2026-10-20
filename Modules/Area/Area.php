<?php
/**
 * Dataset subclass for the table `area` - the content areas.
 *
 * A content area is the container that defines WHICH input fields its
 * records have. The editor builds their data model themselves with it:
 * create an area, define fields (table `field`), enter records
 * (Tabelle `record`).
 *
 * FIVE KINDS OF AREAS (column `type`)
 * --------------------
 *     record     area for content records - the normal case
 *     profile    content records that are the user profiles of the frontend community:
 *                one record per user; login_name_field_id and
 *                login_password_field_id name the two input fields
 *                with which the user logs in to the website (CMS:USER_LOGIN)
 *     ugc        content records that users create in the frontend ("user generated
 *                content", CMS:USER_INPUT): details.profile_area_ids says which
 *                profile areas the authors may come from
 *     template   area for frontend templates
 *     folder     plain folder for structuring the tree navigation, without its own content
 *
 * record, profile and ugc are the same for the input mask, lists, search and Cms::select():
 * record areas (CONTENT_TYPES). Where checks used to be made for 'record'
 * now isContentType() applies.
 *
 * BAUMSTRUKTUR
 * --------------------
 * Areas form a tree. Via TreeManagerTrait, a nested set
 * (lft/rgt) and an adjacency list (parent_id/position/depth/parent_ids) are kept in parallel -
 * hence the default sort order 'lft ASC', which returns the tree in display order.
 *
 * NAME
 * --------------------
 * `name` (Pflichtfeld, Kleinbuchstaben/Ziffern/Unterstrich, eindeutig innerhalb
 * of the instance and its readable instances - see InstanceCache::uniqueScope()) is
 * the identifier by which templates address the area: CMS:SELECT translates
 * filter[area]=artikel and sort=area via the instance file cache into area_id. For this
 * the translation to be correct, hookOnSaved() and hookOnDeleted() rewrite the cache
 * (InstanceCache::rebuild()) - just like the modules Instance and Language.
 *
 * DEFAULTS FOR THE RECORDS OF THE AREA
 * --------------------
 *     default_field_id      which field counts as "title". Its content also goes
 *                           into data_dft and can be filtered and sorted via the pseudo field name
 *                           _default.
 *     default_template_id   template if the record has not selected one
 *     list_template_id      template that adds a column to the record list in the backend
 *                           (RecordBackendList) and completes the detailed
 *                           lookup preview (RecordBackendEdit) - per
 *                           record, executed as $cms with that record's contents
 *     trigger_template_ids  templates with event methods (onCreate, onSave ...),
 *                           which run in sequence when records are created, loaded, saved, deleted and
 *                           restored
 *                           (TriggerTemplates) - table `rel_area_trigger` with
 *                           `position`, not a field of the table `area`
 *     overview_template_id  overview template: the address that consists only of the
 *                           slug prefix of the area (/de/modelle) shows
 *                           this template without a record (Page::resolveRequest(),
 *                           Cms::areaUrl()) - without prefix or template: 404
 *     sort_field_id/-order  default sort order of the record list
 *
 * Every record area has the full text `fulltext` over all text and upload fields -
 * without its own input field (VirtualFieldsTrait::refreshStandardFulltext()).
 *
 * DELETION IN TWO STAGES
 * --------------------
 * Soft delete marks the area AND its records with the same changed_at timestamp
 * (hookOnSoftDelete); hookOnRestore reverts exactly this one operation
 * and leaves earlier deletions alone. The area is only permanently deleted, together with
 * its records, by the system tool (hookOnDelete).
 *
 * All three hooks send the records ONE BY ONE through the matching Record function
 * (softDelete / restoreOne / finalDelete), so that each record's own hooks
 * run - in particular moving the media files to the trash and back.
 * Mass UPDATEs via area_id are deliberately no longer used here.
 *
 * SLUG PREFIX (details.slug_prefix)
 * --------------------
 * A record area can put a prefix in front of the URLs of its records:
 * area Colors with "farben", record with the slug "blau" -> /farben/blau. This way
 * slugs with the same name may occur in different areas (/farben/rot and
 * /rot). The prefix is multilingual (language_id => prefix, falling back to the
 * main language of the instance), a single path part without a slash. Folders and
 * template areas have none.
 *
 * Within the instance and its linked instances (InstanceCache::
 * uniqueScope()) gilt je Sprache (slugPrefixErrors()):
 *     - no other area uses the same prefix
 *     - no record of an area WITHOUT a prefix has a slug whose first
 *       path part is the new prefix ("farben" or "farben/gelb")
 *     - if the prefix is dropped, none of the own records may then have the same
 *       path as a record of an area without a prefix or with the prefix
 *       eines anderen Bereichs beginnen
 * The reverse direction (a record gets a slug that begins with a prefix)
 * is checked by Record::slugPathOk(). Paths are resolved in
 * Cms::recordIdsBySlugPath(), generated in Cms::recordUrl(); the instance file cache
 * keeps the prefixes per language (InstanceCache::slugPrefix()).
 *
 * AREA RIGHTS (rel_group_area, developer docs, chapter 11 (Permissions))
 * --------------------
 * Which user group may read an area and what it may do with its records is
 * stored in `rel_group_area` (row = read right + flags). This is maintained
 * in the "Access" tab of the area (AreaBackend::infoAccess() -> saveAccess(): all
 * groups, one area) and in the group (GroupBackend::access(): all areas,
 * one group). A NEW area gets its rows in hookOnSaved(): for the groups
 * selected in the form (field access_group_ids) - if the field is missing (import,
 * copy, CLI), for all groups to which the instance is assigned. Only groups with
 * the instance of the area get a row; folders have no rights.
 * A copy takes over the rights of the original (copyAccess()).
 *
 * @see Camindo\Core\Modules\Area\Field         input fields of the area
 * @see Camindo\Core\Modules\Area\AreaBackend   Backend interface
 * @see Camindo\Core\TreeManagerTrait            Baumverwaltung
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Area;

use Camindo\Core\Modules\Record\Record;
use Camindo\Core\Modules\Record\UserSecondFactor;
use Camindo\Core\Dataset;
use Camindo\Core\InstanceCache;
use Camindo\Core\Integrity;
use Camindo\Core\MediaAccess;
use Camindo\Core\TreeManagerTrait;
use Camindo\Core\Modules\Template\TemplateFiles;

/**
 * @property-read Field $field  Loaded on demand, see $lazyServices
 */
class Area extends Dataset {
    
    use TreeManagerTrait;

    /** Area types that contain records (`record`) - see class comment */
    public const CONTENT_TYPES = ['record', 'profile', 'ugc'];

    /** Standard-Icon je Bereichstyp (hookOnSave()) */
    public const DEFAULT_ICONS = [
        'record'   => 'draft',
        'profile'  => 'face',
        'ugc'      => 'chat_bubble',
        'template' => 'extension',
        'folder'   => 'folder',
    ];

    /**
     * Is it an area type with records (record, profile, ugc)?
     */
    public static function isContentType(string $type): bool
    {
        return in_array($type, self::CONTENT_TYPES, true);
    }

    /**
     * Lazy-loaded services - hookOnDelete() clears via Field::cleanupFieldname() the
     * field names that are no longer in use. Without this entry the access
     * $this->field would run into the exception of LazyServicesTrait.
     */
    protected array $lazyServices = [
        'field' => Field::class,
    ];

    protected function hookDefinition(): void
    {
        $this->tableName  = 'area';
        $this->moduleName = 'Area';
        $this->defaultSortOrder = 'instance ASC, lft ASC';
        $this->schema = [
            'area_id' => [
                'type'  => 'integer',
                'label' => 'area_id',
            ],
            // To which website does this container belong?
            // Applies to all three types (record, template, folder). The contents of the
            // container inherit the assignment: hookOnSaved() carries it into the
            // `record` rows of the area, the templates attach via
            // fk_template_area_id to the area anyway.
            // 0 is the default instance and always exists.
            'instance_id' => [
                'type'  => 'integer', // FK to `instance`, NOT NULL -> not 'id'
                'label' => '#area.field_area_instance_id',
                'tooltip' => '#area.tooltip_field_area_instance_id',
                'display_type' => 'select',
                'min'   => 0,
            ],
            'type' => [
                'type' => 'chars', // enum
                'label' => '#area.field_area_type',
                'display_type' => 'select',
                'options' => ['record' => 'record', 'profile' => 'profile', 'ugc' => 'ugc', 'template' => 'template', 'folder' => 'folder'],
                'flags' => ['not_empty'],
            ],
            // Identifier for templates (CMS:SELECT filter[area]=..., sort=area),
            // see class comment "Name". Column: char(32).
            'name' => [
                'type'  => 'chars',
                'label' => '#area.field_area_name',
                'max'   => 32,
                'flags' => ['trim', 'not_empty', 'name', 'unique', 'ft'],
            ],
            'icon' => [
                'type'  => 'chars',
                'label' => '#area.field_area_icon',
                'max'   => 64,
                'flags' => ['trim', 'name'],
                'extension' => 'IconPicker',   // Core extension: select Material icon
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#area.field_area_label',
                'max'   => 64,
                'extension' => 'Snippets',   // Core extension: select snippet (#prefix.key)
                'flags' => ['trim', 'not_empty', 'ft'],
            ],
            'details' => [
                'type'  => 'json',
                'label' => '#area.field_area_details',
            ],
            // Input mask for multilingual fields (only type 'record'):
            //     0  all languages one below the other on one page
            //     1  one tab per language (plus "All")
            // Without further languages in the instance, no effect.
            'details.tabs' => [
                'type'  => 'bit',
                'display_type' => 'radio',
                'label' => '#area.field_area_tabs',
                'tooltip' => '#area.tooltip_field_area_tabs',
            ],
            // Slug prefix (record areas only): stands in the URL before the slug of the
            // records - /farben/blau instead of /blau. Per language of the instance
            // (language_id => prefix, empty = that of the main language), without slash.
            // See class comment "Slug prefix".
            'details.slug_prefix' => [
                'type'  => 'json',
                'label' => '#area.field_area_slug_prefix',
                'tooltip' => '#area.tooltip_field_area_slug_prefix',
            ],
            // Dependent area (record areas only): its records belong
            // exclusively to one source record each, which holds them via a field of type 'link'
            // (Camindo\Core\LinkTrait). Only such areas can be selected as
            // the target area of a 'link' field. The area is missing from the
            // area list of module Record (RecordBackendFolderList); its
            // records are reached via the input mask of the source record
            // or the "Records" tab of the area info.
            'dependent' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_area_dependent',
                'tooltip' => '#area.tooltip_field_area_dependent',
            ],
            // Only when creating: user groups that may read the new area
            // (`rel_group_area`, all rights except set_owner) - see class comment
            // "Area rights". Not a field of the table ('reltable').
            'access_group_ids' => [
                'type'  => 'reltable',
                'display_type' => 'checkbox',
                'label' => '#area.field_area_access_group_ids',
                'tooltip' => '#area.tooltip_field_area_access_group_ids',
            ],
            // Content areas only: deletion policy (module Privacy, DeletePolicy) - what
            // happens when a record is deleted. Required; empty = the
            // default policy of the area type (hookOnSave())
            'privacy_policy_id' => [
                'type'  => 'id',
                'display_type' => 'select',
                'label' => '#area.field_area_privacy_policy_id',
                'tooltip' => '#area.tooltip_field_area_privacy_policy_id',
                'table' => 'privacy_policy',
            ],
            // Only type 'profile': input fields with which the user logs in at the
            // website (CMS:USER_LOGIN) - name: a field of type 'chars' or
            // 'encrypted', password: a field of type 'password'. Both belong to the
            // area itself (AreaBackend::_editForm()). Own columns with
            // foreign keys to `field` (ON DELETE SET NULL) - since 2026-09-14,
            // vorher in details (Migration 2026, Script inzwischen entfernt).
            'login_name_field_id' => [
                'type'  => 'id',
                'display_type' => 'select',
                'label' => '#area.field_area_login_name_field_id',
                'tooltip' => '#area.tooltip_field_login_name_field_id',
                'table' => 'field',
            ],
            'login_password_field_id' => [
                'type'  => 'id',
                'display_type' => 'select',
                'label' => '#area.field_area_login_password_field_id',
                'tooltip' => '#area.tooltip_field_login_password_field_id',
                'table' => 'field',
            ],
            // Only type 'profile': second factor of the login (Camindo\Core\Modules\
            // Record\UserSecondFactor, developer docs, chapter 14 (Security: web):
            // none | optional | new_device | required
            'details.second_factor' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#area.field_area_second_factor',
                'tooltip' => '#area.tooltip_field_second_factor',
            ],
            // Only type 'ugc': profile areas (type 'profile' of the own instance
            // and its readable instances) whose users may create records here
            // (CMS:USER_INPUT). Empty = no user may write.
            'details.profile_area_ids' => [
                'type'  => 'json',
                'display_type' => 'checkbox',
                'label' => '#area.field_area_profile_area_ids',
                'tooltip' => '#area.tooltip_field_profile_area_ids',
            ],
            'note' => [
                'type'  => 'text',
                'display_type' => 'textarea',
                'label' => '#area.field_area_note',
                'tooltip' => '#area.tooltip_field_area_note',
                'max' => '500',
                'extension' => 'Snippets',   // like label: also a snippet (#custom.…)
            ],
            'default_field_id' => [
                'type'  => 'id',
                'label' => '#area.field_area_default_field_id',
                'tooltip' => '#area.tooltip_field_default_field',
            ],
            'sort_field_id' => [
                'type'  => 'id',
                'label' => '#area.field_area_sort_field_id',
                'tooltip' => '#area.tooltip_field_sort_field_id',
            ],
            'sort_order' => [
                'type'  => 'chars',
                'label' => '#area.field_area_sort_order',
                'max' => '4',
            ],
            // template access: table `rel_area_template`
            'default_template_id' => [
                'type'  => 'id',
                'label' => '#area.field_area_default_template_id',
                'tooltip' => '#area.tooltip_field_default_template_id',
                'display_type' => 'lookup',
                'table' => 'template',
                'max' => 1
            ],
            // Display in the backend (record areas only): formats per record
            // the last column of the list and the end of the detailed
            // Lookup-Vorschau. FK auf `template`, ON DELETE SET NULL.
            'list_template_id' => [
                'type'  => 'id',
                'label' => '#area.field_area_list_template_id',
                'tooltip' => '#area.tooltip_field_list_template_id',
                'display_type' => 'lookup',
                'table' => 'template',
                'max' => 1
            ],
            // Event methods for the records of the area (TriggerTemplates,
            // called from the hooks of Record) - several, in execution order.
            // Not a field of the table ('reltable'): `rel_area_trigger`, FK to
            // `template` ON DELETE CASCADE. Laden: hookOnLoaded(), Schreiben:
            // hookOnSaved() - only if the field is passed.
            'trigger_template_ids' => [
                'type'  => 'reltable',
                'label' => '#area.field_area_trigger_template_ids',
                'tooltip' => '#area.tooltip_field_trigger_template_ids',
                'table' => 'template',
            ],
            // Overview template for the prefix address (/de/modelle) - see
            // Klassenkommentar. FK auf `template`, ON DELETE SET NULL.
            'overview_template_id' => [
                'type'  => 'id',
                'label' => '#area.field_area_overview_template_id',
                'tooltip' => '#area.tooltip_field_overview_template_id',
                'display_type' => 'lookup',
                'table' => 'template',
                'max' => 1
            ],
            'parent_id' => [
                'type'  => 'id',
                'label' => '#area.field_area_parent_id'
            ],
            'parent_ids' => [
                'type'  => 'json',
                'label' => '#area.field_area_parent_ids',
            ],
            'position' => [
                'type'  => 'integer',
                'label' => '#area.field_area_position',
                'display_type' => 'select',
            ],
            'depth' => [
                'type'  => 'integer',
                'label' => '#area.field_area_depth',
                'min' => 0,
            ],
            'lft' => [
                'type'  => 'integer',
                'label' => '#area.field_area_lft',
                'min' => 0,
            ],
            'rgt' => [
                'type' => 'integer',
                'min' => 0,
            ],
            'changed_at' => [
                'type'  => 'timestampl',
                'label' => '#area.field_area_changed_at',
            ],
            'changed_by' => [
                'type'  => 'id',
                'label' => '#area.field_area_changed_by',
                'table' => 'admin'
            ],
            'deleted' => [
                'type' => 'bit'
            ]
        ];
    }

    /**
     * Before creating: place the new area at the end of its siblings.
     *
     * COALESCE(MAX(position), -1) + 1 starts at 0 if there are no siblings yet.
     * Deleted areas do not count - their positions may be assigned again.
     */
    protected function hookOnCreate(array &$data): void
    {
        // New position = MAX(position under the same parent) + 1
        // For root records (parent_id = 0): MAX over all roots
        $parentId = (int)($data['parent_id'] ?? 0);
        $this->db->query(
            "SELECT COALESCE(MAX(`position`), -1) + 1 FROM `area` WHERE `parent_id` = ? AND `deleted`=0",
            'i', [$parentId]
        );
        $data['position'] = (int)($this->db->fetchNum()[0] ?? 0);
    }

    /**
     * After loading: trigger templates in their order (`rel_area_trigger`).
     */
    protected function hookOnLoaded(array &$data): void
    {
        $areaId = (int)($data['area_id'] ?? 0);
        if ($areaId < 1) return;
        $this->db->pushResult();
        $this->db->query("SELECT `template_id` FROM `rel_area_trigger` WHERE `area_id` = ? ORDER BY `position`, `template_id`", 'i', [$areaId]);
        $data['trigger_template_ids'] = array_map('intval', $this->db->fetchAll()); // flache Liste
        $this->db->popResult();
    }

    /**
     * Before saving: set the default icon, clean up community data.
     * An area without an icon would not be displayed clickable in the tree navigation.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        $type = (string)($data['type'] ?? $previousData['type'] ?? 'record');
        // Only known types - the selection list does not protect against the API and manipulated
        // forms, and an area without a valid type would be usable nowhere
        if (array_key_exists('type', $data) && !isset(self::DEFAULT_ICONS[$type])) {
            $this->createFieldError('error_invalid_format', 'type', $this->schema['type']['label'] ?? 'type');
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        if (empty($data['icon'])) {
            $data['icon'] = self::DEFAULT_ICONS[$type] ?? 'draft';
        }

        $data['sort_order'] = strtoupper($data['sort_order'] ?? 'DESC');
        if ($data['sort_order'] != 'DESC') $data['sort_order'] = 'ASC';

        // Each tenant keeps its own tree: the parent must belong to the same instance
        // otherwise the area would stand in the tree of a foreign tenant
        // (Backend: RecordBackendFolderList, AreaBackend::list() zeigen je Instanz)
        $parentId = (int)($data['parent_id'] ?? $previousData['parent_id'] ?? 0);
        if ($parentId > 0 && (array_key_exists('parent_id', $data) || array_key_exists('instance_id', $data))) {
            $instanceId = (int)($data['instance_id'] ?? $previousData['instance_id'] ?? 0);
            $parentInstanceId = $this->areaInfo($parentId)['instance_id'] ?? -1;
            if ($parentInstanceId >= 0 && $parentInstanceId !== $instanceId) {
                $this->createFieldError('area.error_parent_instance', 'parent_id', $this->schema['parent_id']['label'] ?? 'parent_id');
                throw new \Exception($this->cms->errorMsgLast());
            }
        }

        // Trigger templates: order of input, without 0 and duplicates; only
        // vorhandene Templates
        if (array_key_exists('trigger_template_ids', $data)) {
            $ids = is_array($data['trigger_template_ids']) ? $data['trigger_template_ids'] : [];
            $data['trigger_template_ids'] = array_values($this->system->sanitizeIds($ids, true));
            if (!empty($data['trigger_template_ids'])) {
                $placeholders = implode(', ', array_fill(0, count($data['trigger_template_ids']), '?'));
                $this->db->pushResult();
                $this->db->query("SELECT COUNT(*) FROM `template` WHERE `template_id` IN ($placeholders)",
                    str_repeat('i', count($data['trigger_template_ids'])), $data['trigger_template_ids']);
                $found = (int)($this->db->fetchNum()[0] ?? 0);
                $this->db->popResult();
                if ($found !== count($data['trigger_template_ids'])) {
                    $this->createFieldError('error_invalid_format', 'trigger_template_ids', $this->schema['trigger_template_ids']['label'] ?? 'trigger_template_ids');
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }
        }

        // Groups of the new area (only evaluated when creating, see hookOnSaved())
        if (array_key_exists('access_group_ids', $data)) {
            $ids = $this->system->sanitizeIds((array)$data['access_group_ids'], true);
            $data['access_group_ids'] = array_values(array_diff($ids, [InstanceCache::SUPERUSER_GROUP_ID]));
        }

        // Deletion policy (module Privacy): content areas need one - without specification
        // the default policy of their type; selectable are those for all instances and those
        // of the own instance. Other area types have none.
        $isNew = empty($previousData);
        if ($isNew || array_key_exists('privacy_policy_id', $data)) {
            $policyId = (int)($data['privacy_policy_id'] ?? 0);
            if (self::isContentType($type)) {
                $policies = $this->container->get(\Camindo\Core\Modules\Privacy\DeletePolicy::class);
                $instanceId = (int)($data['instance_id'] ?? $previousData['instance_id'] ?? 0);
                if ($policyId < 1 || !array_key_exists($policyId, $policies->optionsFor($instanceId))) {
                    $policyId = $policies->standardId($type);
                }
                $data['privacy_policy_id'] = $policyId ?: null;
            }
            else {
                $data['privacy_policy_id'] = null;
            }
            if (!in_array('privacy_policy_id', $fields, true) && $fields) $fields[] = 'privacy_policy_id';
        }

        // Dependent area: only record areas; a switched-off switch is not
        // in the POST
        if (in_array('dependent', $fields, true)) {
            $data['dependent'] = (self::isContentType($type) && !empty($data['dependent'])) ? 1 : 0;
        }

        // Login fields (type 'profile'): only fields of this area with a matching
        // type - the value comes from a selection list; an outdated form must not
        // enter a foreign field either
        $areaId = (int)($data['area_id'] ?? 0);
        foreach (['login_name_field_id' => ['chars', 'encrypted'], 'login_password_field_id' => ['password']] as $key => $types) {
            if (!array_key_exists($key, $data)) continue;
            $fieldId = (int)$data[$key];
            if ($fieldId > 0 && $type === 'profile' && $areaId > 0) {
                $placeholders = implode(',', array_fill(0, count($types), '?'));
                $this->db->query(
                    "SELECT 1 FROM `field` WHERE `field_id` = ? AND `area_id` = ? AND `deleted` = 0 AND `type` IN ($placeholders)",
                    'ii' . str_repeat('s', count($types)),
                    array_merge([$fieldId, $areaId], $types)
                );
                if ($this->db->num_rows < 1) $fieldId = 0;
            }
            elseif ($type !== 'profile') {
                $fieldId = 0;
            }
            $data[$key] = $fieldId;
        }
        if (isset($data['details']) && is_array($data['details'])) {
            // Profile areas (type 'ugc'): only ids of existing profile areas
            if (array_key_exists('profile_area_ids', $data['details'])) {
                $ids = $this->system->sanitizeIds((array)$data['details']['profile_area_ids'], true);
                if (!empty($ids) && $type === 'ugc') {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $this->db->query(
                        "SELECT `area_id` FROM `area` WHERE `type` = 'profile' AND `deleted` = 0 AND `area_id` IN ($placeholders)",
                        str_repeat('i', count($ids)),
                        $ids
                    );
                    $ids = array_map('intval', $this->db->fetchColumn());
                }
                elseif ($type !== 'ugc') {
                    $ids = [];
                }
                $data['details']['profile_area_ids'] = array_values($ids);
            }

            // Second factor (type 'profile'): only known values, otherwise removed
            if (array_key_exists('second_factor', $data['details'])) {
                $policy = (string)$data['details']['second_factor'];
                if ($type !== 'profile' || !in_array($policy, UserSecondFactor::POLICIES, true) || $policy === 'none') {
                    unset($data['details']['second_factor']);
                }
            }

            // Slug prefix (see class comment): record areas only. If the
            // key is missing, the prefix is cleared (the form does not send empty inputs
            // either) - this is checked too
            if (!self::isContentType($type)) {
                unset($data['details']['slug_prefix']);
            }
            else {
                $instanceId = (int)($data['instance_id'] ?? $previousData['instance_id'] ?? 0);
                $clean = [];
                $errors = $this->slugPrefixErrors($data['details']['slug_prefix'] ?? [], $instanceId, $areaId, $clean);
                if (!empty($errors)) {
                    $label = $this->schema['details.slug_prefix']['label'];
                    foreach ($errors as $languageId => $message) {
                        $this->cms->error($message, 'details.slug_prefix.' . $languageId, $this->cms->textOrSnippet($label));
                    }
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                if (empty($clean)) unset($data['details']['slug_prefix']);
                else $data['details']['slug_prefix'] = $clean;
            }
        }
    }

    /**
     * Normalize and check the slug prefix of an area (class comment
     * "Slug prefix").
     *
     * Input per language: spaces and slashes at the edges are removed ("farben/" ->
     * "farben"), a slash in the middle is an error (no path specifications), the rest
     * is written like a slug (Cms::textToUri(): lowercase, special characters to '-').
     * A single value instead of an array applies to the main language; languages that
     * the instance does not maintain are removed.
     *
     * @param mixed $raw         details.slug_prefix from the form / the file
     * @param int   $instanceId  instance of the area
     * @param int   $areaId      the area (0 = new)
     * @param array $clean       result: language_id => prefix (only non-empty ones)
     * @return array  language_id => Fehlermeldung; leer = in Ordnung
     */
    public function slugPrefixErrors(mixed $raw, int $instanceId, int $areaId, array &$clean = []): array
    {
        $cache = $this->instanceCache();
        $languageIds = $cache->languageIds($instanceId);
        $primaryId = $cache->primaryLanguageId($instanceId);
        if (!is_array($raw)) $raw = (trim((string)$raw) === '') ? [] : [$primaryId => (string)$raw];

        // 1. Form
        // --------------------
        $clean = [];
        $errors = [];
        foreach ($raw as $languageId => $prefix) {
            $languageId = (int)$languageId;
            if (!in_array($languageId, $languageIds, true) || is_array($prefix)) continue;
            $prefix = trim(trim((string)$prefix), '/');
            if ($prefix === '') continue;
            if (str_contains($prefix, '/')) {
                $errors[$languageId] = $this->cms->snippet('area.error_slug_prefix_path');
                continue;
            }
            $normalized = $this->cms->textToUri($prefix, 64);
            if ($normalized === '' || str_contains($normalized, '/')) {
                $errors[$languageId] = $this->cms->snippet('error_invalid_format');
                continue;
            }
            $clean[$languageId] = $normalized;
        }
        if (!empty($errors)) return $errors;

        // 2. Konflikte je Sprache
        // --------------------
        // Reported at the input field of the language the prefix comes from (an
        // empty language takes over that of the main language)
        $resolved = InstanceCache::resolveSlugPrefixes($clean, $languageIds, $primaryId);
        $previous = ($areaId > 0) ? $cache->slugPrefixes($areaId) : [];
        $scope = $cache->uniqueScope($instanceId) ?: [$instanceId];
        $others = array_diff_key($cache->slugPrefixAreas($scope), [$areaId => true]);
        $lower = fn(string $text): string => mb_strtolower($text, 'UTF-8');
        foreach ($languageIds as $languageId) {
            $errorKey = isset($clean[$languageId]) ? $languageId : $primaryId;
            if (isset($errors[$errorKey])) continue;
            $new = (string)($resolved[$languageId] ?? '');
            $old = (string)($previous[$languageId] ?? '');
            if ($new !== '') {
                // same prefix in another area
                foreach (array_keys($others) as $otherId) {
                    if ($lower($cache->slugPrefix($otherId, $languageId)) !== $lower($new)) continue;
                    $errors[$errorKey] = strtr($this->cms->snippet('area.error_slug_prefix_used'), [
                        '{prefix}' => htmlspecialchars($new . '/'),
                        '{area}'   => htmlspecialchars($this->areaTitle($otherId)),
                    ]);
                    continue 2;
                }
                // records without prefix whose path begins with it
                if ($lower($new) !== $lower($old)) {
                    $hit = $this->slugPathsStartingWith([$new], $languageId, $scope, $areaId);
                    if ($hit !== null) {
                        $errors[$errorKey] = strtr($this->cms->snippet('area.error_slug_prefix_records'), [
                            '{prefix}' => htmlspecialchars($new . '/'),
                            '{record}' => (int)$hit['record_id'],
                            '{path}'   => htmlspecialchars((string)$hit['data']),
                            '{area}'   => htmlspecialchars($this->areaTitle((int)$hit['area_id'])),
                        ]);
                    }
                }
            }
            elseif ($old !== '' && $areaId > 0) {
                // Prefix is dropped: the own records then stand without it
                $hit = $this->slugPathCollision($areaId, $languageId, $scope);
                if ($hit !== null) {
                    $errors[$errorKey] = strtr($this->cms->snippet('area.error_slug_prefix_remove'), [
                        '{record}' => (int)$hit['record_id'],
                        '{path}'   => htmlspecialchars((string)$hit['data']),
                        '{other}'  => (int)$hit['other_id'],
                        '{area}'   => htmlspecialchars($this->areaTitle((int)$hit['area_id'])),
                    ]);
                    continue;
                }
                $otherPrefixes = [];
                foreach (array_keys($others) as $otherId) {
                    $prefix = $cache->slugPrefix($otherId, $languageId);
                    if ($prefix !== '') $otherPrefixes[$otherId] = $prefix;
                }
                $hit = empty($otherPrefixes) ? null : $this->slugPathsStartingWith(array_values($otherPrefixes), $languageId, [$instanceId], 0, $areaId);
                if ($hit !== null) {
                    $first = $lower(explode('/', (string)$hit['data'], 2)[0]);
                    $otherId = (int)array_key_first(array_filter($otherPrefixes, fn($p) => $lower($p) === $first));
                    $errors[$errorKey] = strtr($this->cms->snippet('area.error_slug_prefix_remove_reserved'), [
                        '{record}' => (int)$hit['record_id'],
                        '{path}'   => htmlspecialchars((string)$hit['data']),
                        '{prefix}' => htmlspecialchars($first . '/'),
                        '{area}'   => htmlspecialchars($this->areaTitle($otherId)),
                    ]);
                }
            }
        }
        return $errors;
    }

    /**
     * A live record whose slug (language $languageId) is one of the prefixes
     * or begins with "{prefix}/" - either only in areas WITHOUT a prefix in
     * this language (all except $excludeAreaId) or only in the area $onlyAreaId.
     *
     * @return array|null  ['record_id', 'area_id', 'data'] or null
     */
    private function slugPathsStartingWith(array $prefixes, int $languageId, array $instanceIds, int $excludeAreaId, int $onlyAreaId = 0): ?array
    {
        $vf = $this->cms->resolveVirtualField('slug');
        $suffix = ($vf !== null) ? ($this->cms->dataSuffix[$vf['type']] ?? '') : '';
        if ($suffix === '' || empty($prefixes)) return null;
        $conditions = [];
        $types = 'ii';
        $values = [(int)$vf['fieldname_id'], $languageId];
        foreach ($prefixes as $prefix) {
            $conditions[] = "`d`.`data` = ? OR `d`.`data` LIKE ?";
            $types .= 'ss';
            $values[] = $prefix;
            $values[] = addcslashes($prefix, '\\%_') . '/%';
        }
        $sql = "SELECT `d`.`record_id`, `r`.`area_id`, `d`.`data` FROM `data_{$suffix}` `d`"
             . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id` AND `r`.`tmp` = 0 AND `r`.`deleted` = 0"
             . " WHERE `d`.`fieldname_id` = ? AND `d`.`language_id` = ? AND (" . implode(' OR ', $conditions) . ")"
             . " AND `r`.`instance_id` IN (" . implode(',', array_map('intval', $instanceIds)) . ")";
        if ($onlyAreaId > 0) {
            $sql .= " AND `r`.`area_id` = ?";
            $types .= 'i';
            $values[] = $onlyAreaId;
        }
        else {
            $sql .= " AND `r`.`area_id` != ?";
            $types .= 'i';
            $values[] = $excludeAreaId;
        }
        $this->db->pushResult();
        $this->db->query($sql . " LIMIT 200", $types, $values);
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        foreach ($rows as $row) {
            if ($onlyAreaId < 1 && $this->instanceCache()->slugPrefix((int)$row['area_id'], $languageId) !== '') continue;
            return $row;
        }
        return null;
    }

    /**
     * A record of the area whose slug (language $languageId) also
     * belongs to a record of an area without a prefix - the path would be without the prefix
     * of the area twice.
     *
     * @return array|null  ['record_id', 'data', 'other_id', 'area_id' (of the other)]
     */
    private function slugPathCollision(int $areaId, int $languageId, array $instanceIds): ?array
    {
        $vf = $this->cms->resolveVirtualField('slug');
        $suffix = ($vf !== null) ? ($this->cms->dataSuffix[$vf['type']] ?? '') : '';
        if ($suffix === '') return null;
        $sql = "SELECT `a`.`record_id`, `a`.`data`, `b`.`record_id` AS `other_id`, `rb`.`area_id` FROM `data_{$suffix}` `a`"
             . " INNER JOIN `record` `ra` ON `ra`.`record_id` = `a`.`record_id` AND `ra`.`tmp` = 0 AND `ra`.`deleted` = 0 AND `ra`.`area_id` = ?"
             . " INNER JOIN `data_{$suffix}` `b` ON `b`.`fieldname_id` = `a`.`fieldname_id` AND `b`.`language_id` = `a`.`language_id`"
             . " AND `b`.`data` = `a`.`data` AND `b`.`record_id` != `a`.`record_id`"
             . " INNER JOIN `record` `rb` ON `rb`.`record_id` = `b`.`record_id` AND `rb`.`tmp` = 0 AND `rb`.`deleted` = 0"
             . " AND `rb`.`area_id` != ? AND `rb`.`instance_id` IN (" . implode(',', array_map('intval', $instanceIds)) . ")"
             . " WHERE `a`.`fieldname_id` = ? AND `a`.`language_id` = ? AND `a`.`data` != ''";
        $this->db->pushResult();
        $this->db->query($sql . " LIMIT 200", 'iiii', [$areaId, $areaId, (int)$vf['fieldname_id'], $languageId]);
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        foreach ($rows as $row) {
            if ($this->instanceCache()->slugPrefix((int)$row['area_id'], $languageId) !== '') continue;
            return $row;
        }
        return null;
    }

    /**
     * Label of an area for messages: "Label (name)".
     */
    private function areaTitle(int $areaId): string
    {
        $this->db->pushResult();
        $this->db->query("SELECT `label`, `name` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
        $row = $this->db->fetch();
        $this->db->popResult();
        if (!$row) return (string)$areaId;
        return $this->cms->_strip_tags($this->cms->textOrSnippet((string)$row['label'])) . ' (' . $row['name'] . ')';
    }

    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $areaId = (int)$data['area_id'];

        // instance_id
        // --------------------
        // The records of the area keep a copy of the assignment (`record` has
        // NO foreign key to `area`, so the area cannot manage them for them
        // - the foreign key to `instance` is attached to the copy).
        // If the area changes instance, they must move along; otherwise they would point
        // to the old website and disappear with it.
        // The same applies to the templates of a template container: they too keep
        // a copy, so that Cms::select() can filter via the area without a JOIN
        // (see Cms::$sqlAccess).
        $instanceId = (int)($data['instance_id'] ?? 0);
        $previousInstanceId = (int)($previousData['instance_id'] ?? -1);
        if ($instanceId !== $previousInstanceId) {
            $this->db->query(
                "UPDATE `record` SET `instance_id` = ? WHERE `area_id` = ?",
                'ii',
                [$instanceId, $areaId]
            );
            $this->db->query(
                "UPDATE `template` SET `instance_id` = ? WHERE `area_id` = ?",
                'ii',
                [$instanceId, $areaId]
            );
            // The media files are stored under media/{instance_id}/{field_id} and
            // must move along (Camindo\Core\MediaAccess). Cms remembers the
            // instance per field for the request - the assignment is now outdated.
            if ($previousInstanceId > 0 && !empty($previousData)) {
                $this->cms->clearFieldInstanceCache();
                $this->container->get(MediaAccess::class)->moveAreaMedia($areaId, $previousInstanceId, $instanceId);
            }
        }

        // default_field_id
        // --------------------
        // If default_field_id was changed - then also update table `field`
        $defaultFieldId = (int)$data['default_field_id'];
        if ($defaultFieldId !== (int)$previousData['default_field_id']) {
            // -> no other field of the area may remain the default field
            $this->db->query("UPDATE `field` SET `default_field`=0 WHERE `area_id` = ?", 'i', [$areaId]);
            // -> mark the field and validate area_id in the process
            $this->db->query("UPDATE `field` SET `default_field`=1 WHERE `field_id` = ? AND `area_id` = ?", 'ii', [$defaultFieldId, $areaId]);
            if ($this->db->affected_rows < 1) {
                // Field could not be updated
                $label = $this->schema['default_field_id']['label'] ?? 'default_field_id';
                $this->createFieldError('error_field_not_found', 'default_field_id', $label);
                throw new \Exception($this->cms->errorMsgLast());
            }
        }

        // Template-Zuweisungen (selectable, url-override)
        // --------------------
        // Replace only the lists passed: load() does not return them, so a
        // load()+save() (API area.update, import ...) would otherwise leave only the
        // default template. The form always sends both (AreaBackend).
        $selectableTemplates = array_key_exists('selectable_templates', $data)
            ? (array)$data['selectable_templates']
            : $this->templateIds($areaId, 'selectable');
        $overrideTemplates = array_key_exists('override_templates', $data)
            ? (array)$data['override_templates']
            : $this->templateIds($areaId, 'override');

        $this->db->query("DELETE FROM `rel_area_template` WHERE `area_id`= ? ", 'i', [$areaId]);

        // The default template is always also counted among the selectable templates
        // (simplifies the access check in class Page)
        // (sanitizeIds removes 0 or duplicates again)
        $selectableTemplates[] = (int)($data['default_template_id'] ?? $previousData['default_template_id'] ?? 0);
        $selectableTemplates = $this->system->sanitizeIds($selectableTemplates, true);

        $overrideTemplates = $this->system->sanitizeIds($overrideTemplates, true);

        // Template ids from both arrays
        $mergedIds = array_unique(array_merge($selectableTemplates, $overrideTemplates));

        if (count($mergedIds) > 0) {
            $sqlValues = [];
            $placeholders = [];
            
            foreach ($mergedIds as $templateId) {
                $selectable = in_array($templateId, $selectableTemplates, true) ? 1 : 0;
                $override = in_array($templateId, $overrideTemplates, true) ? 1 : 0;
                array_push($sqlValues, $areaId, $templateId, $selectable, $override);
                $placeholders[] = '(?, ?, ?, ?)';
            }
            
            $sqlCols = implode(', ', $placeholders);
            $sqlTypes = str_repeat('iiii', count($mergedIds));            
            $sql = "INSERT INTO `rel_area_template` (`area_id`, `template_id`, `selectable`, `override`) VALUES $sqlCols";
            $this->db->query($sql, $sqlTypes, $sqlValues);
        }

        // Trigger templates (`rel_area_trigger`) - only if passed; `position`
        // is the order of input, hookOnSave() has cleaned up the ids
        // --------------------
        if (array_key_exists('trigger_template_ids', $data)) {
            $this->db->query("DELETE FROM `rel_area_trigger` WHERE `area_id` = ?", 'i', [$areaId]);
            $ids = (array)$data['trigger_template_ids'];
            if (!empty($ids)) {
                $values = [];
                foreach (array_values($ids) as $position => $templateId) {
                    array_push($values, $areaId, (int)$templateId, $position + 1);
                }
                $placeholders = implode(', ', array_fill(0, count($ids), '(?, ?, ?)'));
                $this->db->query("INSERT INTO `rel_area_trigger` (`area_id`, `template_id`, `position`) VALUES $placeholders",
                    str_repeat('iii', count($ids)), $values);
            }
        }

        // Area rights of a NEW area (see class comment)
        // --------------------
        $type = (string)($data['type'] ?? $previousData['type'] ?? '');
        if (empty($previousData) && $type !== 'folder') {
            $groupIds = array_key_exists('access_group_ids', $data) ? (array)$data['access_group_ids'] : null;
            $this->grantGroups($areaId, $instanceId, $groupIds);
        }

        // Instanz-Dateicache (Bereichsnamen)
        // --------------------
        // The translation area_id => name in var/cache/instance-{id}.php and
        // instances.php must be updated too (see class comment "Name") - on a new
        // area, changed name, instance change or move in the tree. The
        // rebuild costs a few queries and areas change rarely, so
        // without distinguishing cases. Still runs in the transaction, so the queries see
        // the state just written.
        $this->instanceCache()->rebuild();

        // Source directory of the templates (var/templates/{instance_id}/{name},
        // see TemplateFiles): create on a new template area, on a changed
        // name or instance change rename or move it
        // --------------------
        if ($type === 'template') {
            $name = (string)($data['name'] ?? $previousData['name'] ?? '');
            $oldName = (string)($previousData['name'] ?? '');
            if ($oldName === '') {
                $this->templateFiles()->createAreaDir($instanceId, $name);
            }
            elseif ($oldName !== $name || $previousInstanceId !== $instanceId) {
                $this->templateFiles()->moveAreaDir($previousInstanceId, $oldName, $instanceId, $name);
            }
        }
    }

    /**
     * Gespeicherte Template-Zuweisungen eines Bereichs (`rel_area_template`).
     *
     * @param string $column  'selectable' | 'override'
     * @return int[]
     */
    public function templateIds(int $areaId, string $column): array
    {
        if (!in_array($column, ['selectable', 'override'], true) || $areaId < 1) return [];
        $this->db->pushResult();
        $this->db->query("SELECT `template_id` FROM `rel_area_template` WHERE `area_id` = ? AND `$column` = 1", 'i', [$areaId]);
        $ids = array_map('intval', $this->db->fetchAll()); // flache Liste
        $this->db->popResult();
        return $ids;
    }

    /**
     * before soft deletion...
     *
     * Marks all records of the area with the same timestamp as the area
     * itself. Only this way hookOnRestore() knows later which records belong to exactly
     * this deletion and may be restored. Afterwards the deletion policy of the area schedules
     * its blocks for the trigger 'area'.
     */
    protected function hookOnSoftDelete(array $data): void
    {
        $areaId = (int)$data['area_id'];
        $record = $this->recordDataset();

        // getMarkTimestamp(): operation timestamp, which the area record itself also
        // receives as changed_at -> records get exactly the same value
        // (needed for restoration)
        $record->setMarkTimestamp($this->getMarkTimestamp());

        // Each record individually - so Record::hookOnSoftDelete() runs per record
        // and moves its media files to the trash.
        // Order: leaves first (see cascadeIds()).
        $recordIds = $record->cascadeIds('area_id', $areaId, 'delete');
        foreach ($recordIds as $recordId) {
            if (!$record->softDelete($recordId)) {
                // The error message is already in Cms::errors -> discard the transaction
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }
        // Input fields: those of the area and the relation fields of other areas that
        // point to it (otherwise they would remain visible and lead nowhere) - with the
        // same timestamp, each through Field::hookOnSoftDelete() (counter fields,
        // caches). Their data_* rows stay for the restore.
        $field = $this->field;
        $field->setMarkTimestamp($this->getMarkTimestamp());
        $fieldIds = array_unique(array_merge(
            $field->cascadeIds('area_id', $areaId, 'delete'),
            $field->cascadeIds('rel_area_id', $areaId, 'delete')
        ));
        foreach ($fieldIds as $fieldId) {
            if (!$field->softDelete($fieldId)) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }

        // Deletion policy of the area, trigger "area was deleted" (module Privacy) -
        // runs with the scheduler task; a restoration discards the jobs
        if ($recordIds) {
            $this->container->get(\Camindo\Core\Modules\Privacy\PrivacyJobs::class)->scheduleArea($areaId, $recordIds);
        }

        // Template area: source directory to the trash (var/templates/
        // .trash/{area_id}) - otherwise TemplateFiles::sync() would create a new area for the orphaned
        // directory. hookOnRestore() fetches it back from there.
        if ((string)($data['type'] ?? '') === 'template') {
            $this->templateFiles()->trashAreaDir($areaId, (int)$data['instance_id'], (string)$data['name']);
        }
    }

    /**
     * before the final deletion...
     *
     * The records of the area must go first, before the area row disappears:
     * `record` has NO foreign key to `area`, otherwise they would remain with an
     * area_id that no longer exists - along with their media files.
     * Each record individually, so that Record::hookOnDelete() runs per record.
     *
     * Input fields (`field`) and their contents (`data_*`), by contrast, are cleared by the database
     * itself: fk_field_area and the data_* foreign keys are set to
     * ON DELETE CASCADE.
     */
    protected function hookOnDelete(array $data): void
    {
        $areaId = (int)$data['area_id'];
        $record = $this->recordDataset();

        // Permanently delete the records of the area
        // leaves first (see cascadeIds()), so that no record is removed via
        // ON DELETE CASCADE through record.parent_id, bypassing its hook.
        foreach ($record->cascadeIds('area_id', $areaId, 'purge') as $recordId) {
            if (!$record->finalDelete($recordId)) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }

        // Template area: the templates disappear with the area row
        // (fk_template_area_id ON DELETE CASCADE) - without Template::hookOnDelete().
        // Therefore their files are removed here: compiled PHP (var/php/{area_id}) and
        // source directory (var/templates/{instance_id}/{name} or its
        // Papierkorb-Kopie).
        if ((string)($data['type'] ?? '') === 'template') {
            $phpDir = $this->config->get('paths.var_dir') . '/php/' . $areaId;
            foreach ((array)glob($phpDir . '/*.php') as $file) {
                if (is_file($file)) unlink($file);
            }
            if (is_dir($phpDir)) @rmdir($phpDir);
            $this->templateFiles()->removeAreaDir((int)$data['instance_id'], (string)$data['name'], $areaId);
        }

        // Permanently delete the input fields of the area
        // (bidirectional counter-fields are deleted automatically via FK)
        // -> erledigt fk_field_area ON DELETE CASCADE
    }

    /**
     * after the final deletion...
     *
     * The input fields of the area disappear only WITH the area row
     * (fk_field_area ON DELETE CASCADE) - so after hookOnDelete(). A cleanup
     * of the field names there would come too early: cleanupFieldname() would still find the fields and
     * would leave their names in place. That is why it is done only here.
     */
    protected function hookOnDeleted(array $data): void
    {
        // Delete fieldname ids that are no longer needed
        $this->field->cleanupFieldname();

        // Remove the area from the instance file cache (see class comment "Name")
        $this->instanceCache()->rebuild();
    }

    /**
     * before restoring
     * Also restore records of the area marked as "deleted" -
     * but only those with exactly the deletion time of the area.
     * (Runs within the transaction - so it becomes visible in the frontend only once
     * the area itself is visible again)
     * getRestoreTimestamp(): timestamp of the deletion that is reverted
     *     -> only records with exactly this timestamp
     * getMarkTimestamp(): new, uniform change time
     *     for all restored records
     */
    protected function hookOnRestore(array $data): void
    {
        $areaId = (int)$data['area_id'];
        $record = $this->recordDataset();
        $record->setMarkTimestamp($this->getMarkTimestamp());

        // Only records with exactly the changed_at of the deletion to be reverted
        // - records from earlier deletions remain marked.
        // Each one individually, so that Record::hookOnRestore() fetches its media files
        // back from the trash. Order: parents first.
        $ids = $record->cascadeIds('area_id', $areaId, 'restore', $this->getRestoreTimestamp());
        foreach ($ids as $recordId) {
            if (!$record->restoreOne($recordId)) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }

        // Input fields of the same deletion (see hookOnSoftDelete()) - fields that
        // were deleted on their own before stay in the trash
        $field = $this->field;
        $field->setMarkTimestamp($this->getMarkTimestamp());
        $field->setRestoreTimestamp($this->getRestoreTimestamp());
        $fieldIds = array_unique(array_merge(
            $field->cascadeIds('area_id', $areaId, 'restore', $this->getRestoreTimestamp()),
            $field->cascadeIds('rel_area_id', $areaId, 'restore', $this->getRestoreTimestamp())
        ));
        foreach ($fieldIds as $fieldId) {
            if (!$field->restoreOne($fieldId)) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }

        // Template area: fetch the source directory back from the trash
        // (hookOnSoftDelete() had moved it there) - not rewritten from the database
        // again, see TemplateFiles
        if ((string)($data['type'] ?? '') === 'template') {
            $this->templateFiles()->restoreAreaDir($areaId, (int)$data['instance_id'], (string)$data['name']);
        }
    }

    // ====================
    // Area rights (rel_group_area) - see class comment

    /**
     * Rechte aller Gruppen an einem Bereich: group_id => Flags (Zeile = Leserecht).
     *
     * @return array  group_id => ['group_write', 'group_delete', 'other_write',
     *                'other_delete', 'set_owner'] (int 0|1)
     */
    public function accessRows(int $areaId): array
    {
        $rows = [];
        if ($areaId < 1) return $rows;
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `rel_group_area` WHERE `area_id` = ?", 'i', [$areaId]);
        while ($row = $this->db->fetch()) {
            $rows[(int)$row['group_id']] = [
                'group_write'  => (int)!empty($row['group_write']),
                'group_delete' => (int)!empty($row['group_delete']),
                'other_write'  => (int)!empty($row['other_write']),
                'other_delete' => (int)!empty($row['other_delete']),
                'set_owner'    => (int)!empty($row['set_owner']),
            ];
        }
        $this->db->popResult();
        return $rows;
    }

    /**
     * Groups that may come into question for an area: all except the superuser
     * group, to which the instance of the area is assigned (tab "Access",
     * Anlegen eines Bereichs).
     *
     * @return int[]  group_ids
     */
    public function accessCandidates(int $instanceId): array
    {
        $ids = [];
        foreach ($this->instanceCache()->groupIds() as $groupId) {
            if ($groupId === InstanceCache::SUPERUSER_GROUP_ID) continue;
            $group = $this->instanceCache()->group($groupId);
            if (in_array($instanceId, array_map('intval', (array)($group['instance_ids'] ?? [])), true)) $ids[] = $groupId;
        }
        return $ids;
    }

    /**
     * Rechte aller Gruppen an einem Bereich schreiben (AreaBackend::infoAccess()).
     *
     * @param int   $areaId
     * @param array $rows  group_id => ['read' => 1, 'group_write' => 1, ...]; without
     *                     'read' the row is dropped. Only groups from
     *                     accessCandidates(); template areas keep only group_write.
     * @return bool  false: Meldung in Cms::errors
     */
    public function saveAccess(int $areaId, array $rows): bool
    {
        $info = $this->areaInfo($areaId);
        if ($info === null || $info['type'] === 'folder') {
            $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            return false;
        }
        $candidates = $this->accessCandidates($info['instance_id']);
        $isTemplate = ($info['type'] === 'template');

        return (bool)$this->transactional(function () use ($areaId, $rows, $candidates, $isTemplate) {
            $this->db->query("DELETE FROM `rel_group_area` WHERE `area_id` = ?", 'i', [$areaId]);
            foreach ($rows as $groupId => $flags) {
                $groupId = (int)$groupId;
                $flags = (array)$flags;
                if (!in_array($groupId, $candidates, true) || empty($flags['read'])) continue;
                $row = [
                    'group_write'  => (int)!empty($flags['group_write']),
                    'group_delete' => $isTemplate ? 0 : (int)!empty($flags['group_delete']),
                    'other_write'  => $isTemplate ? 0 : (int)!empty($flags['other_write']),
                    'other_delete' => $isTemplate ? 0 : (int)!empty($flags['other_delete']),
                    'set_owner'    => $isTemplate ? 0 : (int)!empty($flags['set_owner']),
                ];
                $this->db->query(
                    "INSERT INTO `rel_group_area` (`group_id`, `area_id`, `group_write`, `group_delete`, `other_write`, `other_delete`, `set_owner`, `sig`)"
                    . " VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    'iiiiiiis',
                    array_merge([$groupId, $areaId], array_values($row), [$this->integrity()->areaRightSignature($groupId, $areaId, $row)])
                );
            }
            // The rights are stored in the group files - Access reads them per request
            $this->instanceCache()->rebuild();
            return true;
        }, false);
    }

    /**
     * Release a new area for groups: full rights except set_owner.
     *
     * @param int[]|null $groupIds  selected groups; null = all groups with the instance
     */
    private function grantGroups(int $areaId, int $instanceId, ?array $groupIds): void
    {
        $candidates = $this->accessCandidates($instanceId);
        if ($groupIds !== null) {
            $candidates = array_values(array_intersect($candidates, array_map('intval', $groupIds)));
        }
        foreach ($candidates as $groupId) {
            // Column defaults: all flags except set_owner (signature likewise)
            $this->db->query(
                "INSERT IGNORE INTO `rel_group_area` (`group_id`, `area_id`, `set_owner`, `sig`) VALUES (?, ?, 0, ?)",
                'iis', [$groupId, $areaId, $this->integrity()->areaRightSignature($groupId, $areaId, ['set_owner' => 0])]
            );
        }
    }

    /**
     * Transfer the rights of one area to another (copy of an area).
     * The cache is refreshed by the caller or by saving the target.
     */
    public function copyAccess(int $sourceId, int $targetId): void
    {
        if ($sourceId < 1 || $targetId < 1) return;
        $this->db->query("DELETE FROM `rel_group_area` WHERE `area_id` = ?", 'i', [$targetId]);
        // Row by row instead of INSERT ... SELECT: each copy needs its own
        // signature (Integrity), and only checked source rows are taken over.
        $this->db->query("SELECT * FROM `rel_group_area` WHERE `area_id` = ?", 'i', [$sourceId]);
        $rows = $this->db->fetchAll();
        foreach ($rows as $row) {
            if (!$this->integrity()->verifyRight('rel_group_area', $row)) continue;
            $groupId = (int)$row['group_id'];
            $this->db->query(
                "INSERT INTO `rel_group_area` (`group_id`, `area_id`, `group_write`, `group_delete`, `other_write`, `other_delete`, `set_owner`, `sig`)"
                . " VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                'iiiiiiis',
                [
                    $groupId, $targetId,
                    (int)$row['group_write'], (int)$row['group_delete'], (int)$row['other_write'], (int)$row['other_delete'], (int)$row['set_owner'],
                    $this->integrity()->areaRightSignature($groupId, $targetId, $row),
                ]
            );
        }
        $this->instanceCache()->rebuild();
    }

    /**
     * Determine the number of input fields of an area
     * @param int $areaId  : id of the area
     */
    public function fieldsNumber(int $areaId): int {
        $sql = "SELECT COUNT(*) FROM `field` WHERE `area_id`= ? AND `deleted` = 0";
        $this->db->query($sql, 'i', [$areaId]);
        return (int)$this->db->fetchNum()[0];
    }

    /**
     * Load areas and folders that contain at least one area in their tree structure
     * (between lft and rgt), i.e. not just empty folders.
     *
     * With $instanceId only the areas of this instance - the tree is shown in the backend per
     * tenant (hookOnSave() only allows an area under a folder
     * of the same instance, so a subtree is uniform). The nested-set
     * values remain table-wide; within an instance the lft order is correct.
     *
     * With $areaIds only these areas (the readable ones of the user group,
     * Access::areaFilter()) - a folder then appears only if it indirectly contains one
     * of them; null = no restriction, [] = nothing.
     *
     * @param array      $areaTypes
     * @param int        $instanceId  0 = all instances
     * @param bool       $hideDependent
     * @param int[]|null $areaIds
     */
    public function loadAreas(array $areaTypes, int $instanceId = 0, bool $hideDependent = false, ?array $areaIds = null): void
    {    
        $types = "'" . implode("','", $areaTypes) . "'";
        $instanceA = $instanceId > 0 ? " AND a.`instance_id` = " . $instanceId : '';
        $instanceB = $instanceId > 0 ? " AND b.`instance_id` = " . $instanceId : '';
        // Dependent areas (`dependent` = 1, target areas of 'link' fields) on
        // on request leave out - including folders that contain only such areas
        $dependentA = $hideDependent ? " AND a.`dependent` = 0" : '';
        $dependentB = $hideDependent ? " AND b.`dependent` = 0" : '';
        // Readable areas of the user group (ids from the cache, no external input)
        $idsA = $idsB = '';
        if ($areaIds !== null) {
            $list = implode(',', array_map('intval', $areaIds)) ?: '0';
            $idsA = " AND a.`area_id` IN ($list)";
            $idsB = " AND b.`area_id` IN ($list)";
        }
        $sql = "SELECT `area_id`, `instance_id`, `type`, `icon`, `label`, `depth`, `lft`, `rgt`"
            ." FROM `area` a"
            ." WHERE ("
            ."    (a.`type` IN ($types)" . $dependentA . $idsA . ")"
            ."    OR (a.`type` = 'folder' AND EXISTS ("
            ."        SELECT 1 FROM `area` b"
            ."        WHERE b.`type` IN ($types)" . $dependentB . $idsB
            ."          AND b.`lft` > a.`lft` AND b.`rgt` < a.`rgt` AND b.`deleted` = 0" . $instanceB
            ."    ))"
            ." )"
            ." AND a.`deleted` = 0" . $instanceA
            ." ORDER BY `lft` ASC";
        $this->db->query($sql);
    }

    /**
     * Provide the Record instance (factory not needed here)
     *
     * Deliberately NOT injected via the constructor: dataset subclasses inherit the
     * constructor of the base class and declare none of their own. An additional
     * dependency would mean repeating the complete signature of Dataset::__construct() in
     * the subclasses. Instead, access via the container fetches the instance only
     * when it is needed - the same shared instance as everywhere else.
     */
    private function recordDataset() {
        return $GLOBALS['app']->get(Record::class);
    }

    /**
     * Source files of the templates (see TemplateFiles).
     */
    private function templateFiles(): TemplateFiles
    {
        return $this->container->get(TemplateFiles::class);
    }

    /**
     * File cache of the instances (see Camindo\Core\InstanceCache).
     */
    private function instanceCache(): InstanceCache
    {
        return $this->container->get(InstanceCache::class);
    }

    private function integrity(): Integrity
    {
        return $this->container->get(Integrity::class);
    }
}
