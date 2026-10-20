<?php
/**
 * Backend interface for the content areas (table `area`).
 *
 * Controller layer following the usual pattern (see developer docs, chapter 05 (Backend development)):
 * the three-line scripts in backend/ fetch this class from the container and call
 * exactly one of the public methods.
 *
 *     backend/area_list.php     -> list()      Baumliste aller Bereiche
 *     backend/area_info.php     -> info()      Detailansicht eines Bereichs
 *     backend/area_edit.php     -> edit()      create / copy / change / delete
 *     backend/area_search.php   -> search()    search mask and search execution
 *     backend/area_previous.php -> updatePreviousOptions()   Ajax-Teilantwort
 *     backend/area_parent.php   -> updateParentOptions()     Ajax-Teilantwort
 *
 * THE LIST IS A TREE
 * --------------------
 * `Area` sorts by default by `lft ASC` (nested set), so the rows already come
 * in display order. The indentation comes solely from `depth` * 20 pixels - there are
 * no nested <ul>, the table stays flat.
 *
 * TENANTS AS MAIN DIRECTORIES
 * --------------------
 * If tenants are set up (InstanceCache::hasMultipleInstances()), each one keeps
 * its own tree: the list then sorts by instance (pseudo sort field
 * 'instance' of Cms::select(), order of the backend) and puts a folder row with its label
 * before the first row of each instance (area_table_row_instance.html);
 * the areas are indented one level. If the editor sorts by a column,
 * the grouping is dropped. So that a subtree always belongs to ONE tenant,
 * Area::hookOnSave() only allows a folder of the same instance as parent; the
 * form accordingly shows only folders and siblings of the selected instance and
 * reloads both lists via Ajax when the instance changes.
 *
 * KOPIEREN (function=copy)
 * --------------------
 * A separate mask with label, name, icon, instance, parent folder, predecessor and
 * note (pre-filled from the original, the predecessor with the original itself - the copy
 * is thus placed directly after it); the copy takes all other settings from the
 * original - for record areas including input fields (with thumbnails, references,
 * full texts), default/sort/login field and template assignments. The route goes
 * via the export format: RecordExport::areaBlueprintFile() writes the original like
 * a blueprint, RecordImport::importBlueprint() creates the fields in the new area -
 * the same code as when creating from a blueprint (both in AreaService, which
 * the API also uses). Content (records, templates)
 * is not copied. (The earlier "copy" - the full form with the values
 * of the original, without fields - is superseded by this.)
 *
 * THE POSITION IS ENTERED VIA "previous_id"
 * --------------------
 * `previous_id` is NOT a database column. The form lets the editor select the
 * PREDECESSOR area; `Area` resp. TreeManagerTrait calculates the
 * `position` from it when saving. Conversely, _editForm() must determine the predecessor again when loading from the saved
 * `position` (SQL: largest position below its own,
 * same parent). If the editor changes the parent in the form, the
 * predecessor list is obsolete - then updatePreviousOptions() reloads it via Ajax.
 *
 * TEMPLATE-ZUWEISUNG
 * --------------------
 * Three fields in the form write to the same edge table `rel_area_template`:
 *
 *     default_template_id    column in `area`; template if the record selects none
 *     list_template_id       column in `area`; template for the backend display
 *                            (list column, detailed lookup preview)
 *     trigger_template_ids   table `rel_area_trigger`; event methods of the
 *                            records, several in execution order (lookup
 *                            with up/down buttons)
 *     overview_template_id   column in `area`; overview under the prefix address
 *     selectable_templates   rel_area_template.selectable=1; selectable in the record
 *     override_templates     rel_area_template.override=1;   per URL erzwingbar
 *
 * The edge table is not written here, but in Area::hookOnSaved() - the
 * two lookup fields travel as `data[selectable_templates]` / `data[override_templates]`
 * in the form; _editSave() sets them empty if nothing is selected (only
 * passed lists are replaced). They are read here via
 * selectableTemplateIds() / overrideTemplateIds().
 *
 * DELETION
 * --------------------
 * From the backend, deletion is always SOFT only (trash). Before the
 * confirmation, _editDeleteForm() totals what is attached - depending on the area type
 * child areas, records or templates - and formulates the warning from it.
 * Large areas go into a background process (MassDeletion).
 *
 * @see Camindo\Core\Modules\Area\Area          Dataset/model of the table `area`
 * @see Camindo\Core\Modules\Area\FieldBackend  backend of the input fields of an area
 * @see Camindo\Core\Backend                      form, table and layer helpers
 */
namespace Camindo\Core\Modules\Area;

use Camindo\Core\BackgroundProcess;
use Camindo\Core\Config;
use Camindo\Core\FulltextState;
use Camindo\Core\Backend;
use Camindo\Core\InstanceCache;
use Camindo\Core\Modules\Instance\Instance;
use Camindo\Core\Database;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\MassDeletion;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Area\Field;
use Camindo\Core\Modules\Record\UserSecondFactor;
use Camindo\Core\Modules\Template\TemplateBackend;

class AreaBackend {
    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';
    /** Blueprint of a new area (file name without .xml, see create()) */
    protected string $blueprint = '';

    public function __construct(
        private Backend $backend,
        private Database $db,
        private System $system,
        private Cms $cms,
        private Crypt $crypt,
        private MassDeletion $massDeletion,
        private Area $area,
        private Field $field,
        private TemplateBackend $templateBackend,
        private InstanceCache $instanceCache,
        private AreaService $service,
        private FulltextState $fulltextState,
        private BackgroundProcess $bgproc,
        private Config $config,
    )
    {}

    // ====================
    // Volltext veraltet (FulltextState)

    /**
     * Note above the detail view and the field list of an area: the full text
     * no longer matches the source fields after a structural change - with the
     * button "Update now" (fulltext()). If the rebuild is running right now, only
     * this note.
     *
     * @param string $return  'info' | 'fields' - where the button leads back to afterwards
     */
    public function fulltextNotice(int $areaId, string $return = 'info'): string
    {
        if ($this->fulltextState->lockInfo($areaId) !== null) {
            return $this->backend->template('area.fulltext_running');
        }
        $since = $this->fulltextState->staleSince($areaId);
        if ($since === null) return '';
        $text = str_replace('{date}', $this->cms->_formatDate($this->cms->utcToUserTZ($since)),
            $this->cms->snippet('area.alert_fulltext_stale'));
        return $this->backend->template('area.fulltext_notice', [
            '{text}' => htmlspecialchars($text),
            '{onclick}' => "cApp.layers.replacePost('Area/area_fulltext.php?id={$areaId}&return={$return}');",
        ]);
    }

    /**
     * "Update now" (backend/area_fulltext.php): start the rebuild of the full text
     * as a background process (CLI command `fulltext`) - unless it is already running
     * (lock of FulltextState). Afterwards back to the detail view or field list.
     */
    public function fulltext(): void
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $return = (string)($_REQUEST['return'] ?? '') === 'fields'
            ? "Area/field_list.php?area_id={$id}" : "Area/area_info.php?id={$id}";
        echo $this->backend->template('container_header');
        // Starts a background process - POST only
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        if (!$this->area->load($id)) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
            echo $this->backend->template('container_footer');
            return;
        }
        if ($this->fulltextState->lockInfo($id) !== null) {
            $this->backend->balloon('info', $this->cms->snippet('area.message_fulltext_running'));
        }
        else {
            $error = '';
            $command = [(string)$this->config->get('paths.php'), $this->config->get('paths.core_dir') . '/cron/camindo.php',
                'fulltext', '--area=' . $id];
            $token = bin2hex(random_bytes(8));
            $command[] = '--token=' . $token;
            if ($this->bgproc->start($command, 'fulltext', $token, null, null, $error)) {
                $this->backend->balloon('success', $this->cms->snippet('area.message_fulltext_started'));
            }
            else {
                $this->backend->balloon('danger', $this->cms->snippet('area.message_fulltext_start_failed') . ' ' . htmlspecialchars($error));
            }
        }
        $this->backend->script("cApp.layers.replace('{$return}');");
        echo $this->backend->template('container_footer');
    }

    /**
     * Baumliste aller Bereiche (backend/area_list.php).
     *
     * 'request' => 'all' lets Cms::select() take filter, sorting and page from the
     * request - therefore search mask and column sort links work
     * without any effort of their own. The tree display costs nothing here: Area sorts by
     * default by `lft ASC`, the indentation is pixel padding from `depth`.
     */
    public function list()
    {
        // If there are several instances, show this
        $this->db->query("SELECT `instance_id`,`label` FROM `instance` ORDER BY " . Instance::orderSql());
        $instances = $this->db->fetchPairs();
        // Tenants as main directories (see class comment) - only in the
        // tree sorting; a column sort from the request cancels them
        $grouped = $this->instanceCache->hasMultipleInstances() && empty($_REQUEST['sort']);

        // Seitenelemente
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#area.module_label');

        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#area.headline_area_list');
        
        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Area/area_create.php');");
        $this->backend->buttonAdd('button_search', "cApp.layers.add('Area/area_search.php?' + cApp.layers.queryString());");
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();
        
        // Area list
        // --------------------
        $param = [
            'request' => 'all',
            'limit' => -1,
            'count' => 'true',
            'filtertext' => 'true',
        ];
        if ($grouped) {
            $param['sort']  = ['instance', 'lft'];
            $param['order'] = ['ASC', 'ASC'];
        }
        $f = $this->cms->select($param, $this->area);

        // Search terms or changed sorting as readable text
        echo $this->backend->filterText($f);

        if ($this->db->num_rows > 0) {

            // Prepare Table-Head
            $schema = $this->area->getSchema();
            $thLabel = $this->backend->tableHeadColumn($schema['label'], $f, 'label');
            $thName = $this->backend->tableHeadColumn($schema['name'], $f, 'name');
            $thInstance = $this->backend->tableHeadColumn($schema['instance_id'], $f, 'instance_id');

            $headColumns = $this->backend->template(
                $instances ? 'area.area_table_head_columns_instances' : 'area.area_table_head_columns', [
                '{label_label}' => $thLabel,
                '{label_name}' => $thName,
                '{label_instance}' => $thInstance,
                '{label_area_id}' => 'area_id'
            ]);

            // Collect table rows
            $tplTableRow = $this->backend->template(
                $instances ? 'area.area_table_row_instances' : 'area.area_table_row'
            );
            $tableRows = '';
            $instanceLabels = $this->instanceCache->instanceLabels();
            $currentInstanceId = -1;

            // Fetch results (Dataset::fetch: with post-processing)
            while ($data = $this->area->fetch()) {

                // Tenant changed -> folder row with its label
                if ($grouped && (int)$data['instance_id'] !== $currentInstanceId) {
                    $currentInstanceId = (int)$data['instance_id'];
                    $tableRows .= $this->backend->template('area.area_table_row_instance', [
                        '{label}' => htmlspecialchars($this->cms->textOrSnippet((string)($instanceLabels[$currentInstanceId] ?? $instances[$currentInstanceId] ?? ''))),
                        '{instance_id}' => $currentInstanceId,
                    ]) . "\n";
                }

                $icon = $this->backend->template('icon_large', [
                    '{name}' => $data['icon'] ?: 'draft'
                ]);
                // depth is 0-based - the base indentation of the top level remains
                // preserved; under a tenant directory one level more
                $paddingLeft = ((int)$data['depth'] + ($grouped ? 1 : 0)) * 20 + 20;

                $label = $icon . ' ' . $this->cms->textOrSnippet($data['label'] ?? '');
                if ((int)$data['dependent'] === 1) {
                    $label = '<span class="c9-grey-30">' . $label . '</span>';
                }
                // Icon + Label | Name | area_id ( | Instanz)
                $tableRow = strtr($tplTableRow, [
                    '{padding_left}' => $paddingLeft,
                    '{data_label}' => $label,
                    '{data_name}' => $data['name'],
                    '{data_area_id}' => $data['area_id'],
                    '{data_instance}' => $instances[(int) $data['instance_id']] ?? '',
                    '{data_id}' => $data['area_id']

                ]) ."\n";

                $tableRows .= $tableRow;
            }

            // Render table
            echo $this->backend->template('area.area_table_with_head', [
                '{head_columns}' => $headColumns,
                '{table_rows}' => $tableRows
            ]);

            // Context menu
            echo $this->backend->template('area.area_context_menu');
        }

        // Container Footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Detailansicht eines Bereichs (backend/area_info.php).
     *
     * Which fields are shown depends on the type: a 'folder' has neither note nor
     * default field, a 'template' area gets the
     * Template-Tab.
     *
     * Below is the overview of the template assignments from `rel_area_template`
     * (default / selectable / override as tick columns).
     */
    public function info()
    {
        $id = (int)($_REQUEST['id'] ?? 0);

        // Seitenelemente
        // --------------------

        // Container header
        echo $this->backend->template('container_header');

        // Load data
        if (!$data = $this->area->load($id)) {
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->cms->errorMsg()
            ]);
            echo $this->backend->template('container_footer');
            return;
        }
        $type = $data['type'];

        // Bread crum navi
        $this->backend->setNaviLabel($data['label']); // also evaluates snippet

        // H1
        $icon = $this->backend->template('icon', [
            '{name}' => $data['icon'] ?: 'draft'
        ]);
        echo $this->backend->headline($icon . ' ' . $this->cms->textOrSnippet($data['label']));
        
        // Tabs ("Changes" = audit log of the area, Backend::auditTabAdd())
        $auditActive = $this->backend->auditTabActive();
        $this->backend->tabBarReset();
        $this->backend->tabAdd('#area.headline_settings', $auditActive ? "cApp.layers.replace('Area/area_info.php?id=$id')" : '', !$auditActive);
        if (in_array($data['type'], ['record','profile','ugc'], true)) {
            $this->backend->tabAdd('#area.headline_field_list', "cApp.layers.replace('Area/field_list.php?area_id=$id')");
        }
        if ($type !== 'folder') {
            $this->backend->tabAdd('#area.headline_access', "cApp.layers.replace('Area/area_access.php?id=$id')");
        }
        // (tabs into other modules only if the group has access there)
        if ($data['type'] == 'template' && $this->backend->access()->module('Template')) {
            $this->backend->tabAdd('#area.headline_templates', "cApp.layers.replace('Template/template_list.php?area_id=$id')");
        }
        elseif (Area::isContentType($type) && $this->backend->access()->module('Record')) {
            $this->backend->tabAdd('#area.headline_records', "cApp.layers.replace('Record/record_list.php?area_id=$id')");
        }
        $this->backend->auditTabAdd("Area/area_info.php?id=$id");
        echo $this->backend->tabBar();

        // Buttons
        $this->backend->buttonBarReset();
        // Edit/copy as a separate layer above the info page: Cancel and
        // Save (invalidate(null, 2) + close()) return to it
        $this->backend->buttonAdd('button_edit_primary', "cApp.layers.add('Area/area_edit.php?id={$id}')");
        $this->backend->buttonAdd('button_copy', "cApp.layers.add('Area/area_edit.php?id={$id}&function=copy')");
        $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Area/area_edit.php?id={$id}&function=delete')");
        // Preview of the overview (overview template + slug prefix)
        $this->backend->buttonAddAreaPreview($id);
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        echo $this->backend->buttonBar();

        if ($auditActive) {
            echo $this->backend->auditHistory('area', $id);
            echo $this->backend->template('container_footer');
            return;
        }

        echo $this->fulltextNotice($id);

        // label, name, area_id, instance_id
        // ---------------
        $this->backend->infoAdd($this->area, 'label', $data['label'], ['primary']);
        $this->backend->infoAdd($this->area, 'name', $data['name'], ['monospace']);
        $this->backend->infoAdd($this->area, 'icon', $data['icon']);
        $this->backend->infoAdd($this->area, 'area_id', $data['area_id']);
        $this->backend->infoAdd($this->area, 'instance_id',
            $this->backend->teaser('instance', (int)$data['instance_id'])
            .' &middot; instance_id : ' . $data['instance_id'],
            ['html']
        );

        // note
        // --------------------
        if ($type !== 'folder') {
            $this->backend->infoAdd($this->area, 'note', $this->cms->textOrSnippet((string)$data['note'])); // like label: also a snippet (#custom.…)
        }

        // sort_field_id
        // sort_order
        // --------------------
        if (Area::isContentType($type)) {
            $sortFieldId = (int)$data['sort_field_id'];
            if (!empty($sortFieldId)) {
                // is FK, always returns a result
                $this->db->query("SELECT `name` FROM `field` WHERE `field_id` = ?", 'i', [$sortFieldId]);
                $sortFieldName = $this->db->fetchNum()[0];
            }
            else {
                $sortFieldName = 'changed_at';            
            }
            $sortOrder = $data['sort_order'] ?: 'DESC';
            $this->backend->infoStatic($this->cms->snippet('area.default_sort'), $sortFieldName . ' ' . $sortOrder);
        }
        
        // details.slug_prefix (per language, resolved as in the URL)
        // --------------------
        if (Area::isContentType($type)) {
            $items = [];
            $languages = $this->instanceCache->languages((int)$data['instance_id']);
            foreach ($languages as $languageId => $language) {
                $prefix = $this->instanceCache->slugPrefix($id, (int)$languageId);
                if ($prefix === '') continue;
                $items[] = htmlspecialchars($prefix . '/') . (count($languages) > 1 ? $this->backend->template('area.slug_prefix_lang', ['{code}' => $this->backend->escape((string)$language['code'])]) : '');
            }
            $html = empty($items) ? '' : implode('<br>', $items);
            $this->backend->infoAdd($this->area, 'details.slug_prefix', $html, ['html', 'monospace']);
        }

        // dependent
        // --------------------
        if (Area::isContentType($type)) {
            $this->backend->infoAdd($this->area, 'dependent',
                !empty($data['dependent']) ? $this->cms->snippet('yes') : $this->cms->snippet('no'));
        }

        // Deletion policy (module Privacy)
        // --------------------
        if (Area::isContentType($type)) {
            $policyId = (int)($data['privacy_policy_id'] ?? 0);
            $policies = $GLOBALS['app']->get(\Camindo\Core\Modules\Privacy\DeletePolicy::class);
            $policyLabel = $policyId > 0 ? (string)($policies->optionsFor((int)$data['instance_id'])[$policyId] ?? '#' . $policyId) : '';
            $this->backend->infoAdd($this->area, 'privacy_policy_id', $policyLabel);
        }

        // default_field_id
        // --------------------
        if (Area::isContentType($type)) {
            $defaultFieldId = (int)$data['default_field_id'];
            if (!empty($defaultFieldId)) {
                $defaultFieldHtml = $this->backend->teaser('field', $defaultFieldId);
            }
            else {
                $defaultFieldHtml = 'record_id';
            }
            $this->backend->infoAdd($this->area, 'default_field_id', $defaultFieldHtml, ['html']);
        }

        // default_template_id
        // --------------------
        if (!empty($data['default_template_id'])) {
            $templateLabel = $this->backend->teaser('template', (int)$data['default_template_id']);
            $flags = ['html'];
        }
        else {
            $templateLabel = '';
            $flags = [];
        }
        $this->backend->infoAdd($this->area, 'default_template_id', $templateLabel, $flags);

        // list_template_id
        // --------------------
        if (Area::isContentType($type)) {
            $listTemplateId = (int)($data['list_template_id'] ?? 0);
            $html = $listTemplateId > 0 ? $this->backend->teaser('template', $listTemplateId) : '';
            $this->backend->infoAdd($this->area, 'list_template_id', $html, $html !== '' ? ['html'] : []);

            // Trigger templates in execution order, one per row
            $teasers = array_map(fn($id) => $this->backend->teaser('template', (int)$id), (array)($data['trigger_template_ids'] ?? []));
            $html = implode('<br>', $teasers);
            $this->backend->infoAdd($this->area, 'trigger_template_ids', $html, $html !== '' ? ['html'] : []);

            $overviewTemplateId = (int)($data['overview_template_id'] ?? 0);
            $html = $overviewTemplateId > 0 ? $this->backend->teaser('template', $overviewTemplateId) : '';
            $this->backend->infoAdd($this->area, 'overview_template_id', $html, $html !== '' ? ['html'] : []);
        }

        // Login to the website (type 'profile')
        // --------------------
        if ($type === 'profile') {
            foreach (['login_name_field_id', 'login_password_field_id'] as $key) {
                $fieldId = (int)($data[$key] ?? 0);
                $html = $fieldId > 0 ? $this->backend->teaser('field', $fieldId) : '';
                $this->backend->infoAdd($this->area, $key, $html, $html !== '' ? ['html'] : []);
            }
            $policy = (string)($data['details']['second_factor'] ?? 'none');
            $policy = in_array($policy, UserSecondFactor::POLICIES, true) ? $policy : 'none';
            $this->backend->infoAdd($this->area, 'details.second_factor', $this->cms->snippet('area.option_second_factor_' . $policy));
        }

        // Profil-Bereiche (type 'ugc')
        // --------------------
        if ($type === 'ugc') {
            $items = [];
            foreach ($this->system->sanitizeIds((array)($data['details']['profile_area_ids'] ?? []), true) as $profileAreaId) {
                $items[] = $this->backend->teaser('area', (int)$profileAreaId);
            }
            $html = empty($items) ? '' : $this->backend->template('ul', [
                '{items}' => '<li>' . implode("</li>\n<li>", $items) . '</li>'
            ]);
            $this->backend->infoAdd($this->area, 'details.profile_area_ids', $html, $html !== '' ? ['html'] : []);
        }

        echo $this->backend->infoTable();

        // Template-Zuweisung
        // --------------------
        $checked = $this->backend->template('icon_checked');
        $notChecked = $this->backend->emptyPlaceholder;

        $assignments = $this->templateBackend->loadTemplateAssignment($id);
        if (count($assignments) > 0) {
            echo $this->backend->template('headline4_mt', [
                '{text}' => '{#area.headline_templates}'
            ]);
            foreach($assignments as $assignment) {
                // default
                $isDefault = (int)$assignment['template_id'] === (int)$data['default_template_id'];
                // selectable
                $isSelectable = (int)$assignment['selectable'];
                // url-override
                $isOverride = (int)$assignment['override'];
                // linked template label
                $link = $this->backend->teaser('template', (int)$assignment['template_id']);
                // create row
                $rows[] = $this->backend->template('area.template_row', [
                    '{label}' => $link,
                    '{name}' => $assignment['name'],
                    '{default}' => $isDefault ? $checked : $notChecked,
                    '{selectable}' => $isSelectable ? $checked : $notChecked,
                    '{override}' => $isOverride ? $checked : $notChecked,
                ]);
            }
            echo $this->backend->template('area.template_table', [
                '{table_rows}' => implode("\n", $rows),
            ]);
        }

        echo $this->backend->template('container_footer');
    }

    /**
     * Ajax: return the options of the select field "previous_id" again.
     *
     * Called as soon as the editor changes the parent folder in the form -
     * the previous predecessor list then belongs to the wrong
     * siblings. The response deliberately consists only of <option> rows, so the
     * JavaScript replaces the content of the existing <select> with it.
     *
     * The LAST entry is always preselected, so the new area ends up at the
     * end of its new siblings - the same default as in Area::hookOnCreate().
     *
     * Request: id = own area (to be excluded from the list),
     *          data[parent_id] = newly selected folder
     */
    /**
     * Tab "Access" (backend/area_access.php): which user groups may read the area
     * and what they may do with its records (rel_group_area) -
     * all groups, one area. The other view (all areas, one group) has
     * the group itself (GroupBackend::access()).
     *
     *     ''        table with ticks, button "Edit" (superuser only)
     *     edit      checkboxes; "Add": dropdown of the groups without a row, whose
     *               instance list contains the area - the selection sends the
     *               form with function=update
     *     update    form with the new row (full rights except set_owner)
     *     save      Area::saveAccess(), then the table
     *
     * Edit and save only for superusers (granting rights = administration).
     */
    public function infoAccess(): void
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $function = (string)($_REQUEST['function'] ?? '');

        $this->backend->setNaviLabel('#area.headline_access');
        echo $this->backend->template('container_header');

        if (!$data = $this->area->load($id)) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
            echo $this->backend->template('container_footer');
            return;
        }
        $type = (string)$data['type'];
        $instanceId = (int)$data['instance_id'];
        $isTemplate = ($type === 'template');
        $canEdit = $this->backend->isSuperuser();
        if ($type === 'folder' || (!$canEdit && $function !== '')) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        // H1 and tabs as in info()
        $icon = $this->backend->template('icon', ['{name}' => $data['icon'] ?: 'draft']);
        echo $this->backend->headline($icon . ' ' . $data['label']);
        $this->backend->tabBarReset();
        $this->backend->tabAdd('#area.headline_settings', "cApp.layers.replace('Area/area_info.php?id=$id')");
        if (Area::isContentType($type)) {
            $this->backend->tabAdd('#area.headline_field_list', "cApp.layers.replace('Area/field_list.php?area_id=$id')");
        }
        $this->backend->tabAdd('#area.headline_access', '', true);
        if ($isTemplate && $this->backend->access()->module('Template')) {
            $this->backend->tabAdd('#area.headline_templates', "cApp.layers.replace('Template/template_list.php?area_id=$id')");
        }
        elseif (Area::isContentType($type) && $this->backend->access()->module('Record')) {
            $this->backend->tabAdd('#area.headline_records', "cApp.layers.replace('Record/record_list.php?area_id=$id')");
        }
        $this->backend->auditTabAdd("Area/area_info.php?id=$id");
        echo $this->backend->tabBar();

        // Speichern
        // --------------------
        if ($function === 'save') {
            if ($this->area->saveAccess($id, (array)($_REQUEST['data']['access'] ?? []))) {
                $this->backend->balloon('success', $this->cms->snippet('area.message_access_saved'));
                $function = '';
            }
            else {
                echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
                $function = 'update';
            }
        }

        // Rows: saved (display) or from the form (edit/update)
        // --------------------
        $candidates = $this->area->accessCandidates($instanceId);
        $groupLabels = $this->backend->getGroups();
        if ($function === '') {
            $rows = $this->area->accessRows($id);
        }
        else {
            $rows = [];
            if ($function === 'edit') {
                $rows = $this->area->accessRows($id);
            }
            else {
                foreach ((array)($_REQUEST['data']['access'] ?? []) as $groupId => $flags) {
                    $flags = (array)$flags;
                    if (empty($flags['read'])) continue;
                    $rows[(int)$groupId] = [
                        'group_write'  => (int)!empty($flags['group_write']),
                        'group_delete' => (int)!empty($flags['group_delete']),
                        'other_write'  => (int)!empty($flags['other_write']),
                        'other_delete' => (int)!empty($flags['other_delete']),
                        'set_owner'    => (int)!empty($flags['set_owner']),
                    ];
                }
                $addGroupId = (int)($_REQUEST['data']['add_group_id'] ?? 0);
                if ($addGroupId > 0 && in_array($addGroupId, $candidates, true) && !isset($rows[$addGroupId])) {
                    $rows[$addGroupId] = ['group_write' => 1, 'group_delete' => 1, 'other_write' => 1, 'other_delete' => 1, 'set_owner' => 0];
                }
            }
            // only groups that come into question for the area
            $rows = array_intersect_key($rows, array_flip($candidates));
        }
        // Order as getGroups() (label)
        $ordered = [];
        foreach ($groupLabels as $groupId => $label) {
            if (isset($rows[$groupId])) $ordered[$groupId] = $rows[$groupId];
        }
        $rows = $ordered;
        $edit = ($function !== '');

        // Tabelle
        // --------------------
        $checked = $this->backend->template('icon_checked');
        $empty = $this->backend->emptyPlaceholder;
        $tableRows = '';
        foreach ($rows as $groupId => $flags) {
            if ($edit) {
                $cell = fn(string $key) => $this->backend->template('admin.group_area_checkbox', [
                    '{name}' => "data[access][{$groupId}][{$key}]",
                    '{class}' => ' c9-access-dep',
                    '{checked}' => !empty($flags[$key]) ? ' checked' : '',
                    '{disabled}' => '',
                    '{onchange}' => '',
                ]);
                $read = $this->backend->template('admin.group_area_checkbox', [
                    '{name}' => "data[access][{$groupId}][read]",
                    '{class}' => '',
                    '{checked}' => ' checked',
                    '{disabled}' => '',
                    '{onchange}' => $this->backend->on('change', "$(this).closest('tr').find('.c9-access-dep').prop('disabled', !this.checked);"),
                ]);
            }
            else {
                $cell = fn(string $key) => !empty($flags[$key]) ? $checked : $empty;
                $read = $checked;
            }
            $tableRows .= $this->backend->template('admin.group_area_row', [
                '{class}' => '',
                '{area}' => $this->backend->teaser('group', (int)$groupId, linked: !$edit),
                '{read}' => $read,
                '{group_write}' => $cell('group_write'),
                '{group_delete}' => $isTemplate ? '' : $cell('group_delete'),
                '{other_write}' => $isTemplate ? '' : $cell('other_write'),
                '{other_delete}' => $isTemplate ? '' : $cell('other_delete'),
                '{set_owner}' => $isTemplate ? '' : $cell('set_owner'),
            ]);
        }
        $table = ($tableRows !== '')
            ? $this->backend->template('admin.group_area_table', [
                '{instance}' => '',
                '{first_column}' => $this->cms->snippet('area.access_group'),
                '{table_rows}' => $tableRows,
            ])
            : $this->backend->template('p', ['{text}' => $this->cms->snippet('area.note_no_access_yet')]);

        // Anzeige
        // --------------------
        if (!$edit) {
            $this->backend->buttonBarReset();
            if ($canEdit) {
                $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Area/area_access.php?id={$id}&function=edit');");
            }
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
            echo $this->backend->buttonBar();
            echo $table;
            echo $this->backend->template('p', ['{text}' => $this->cms->snippet('area.note_access')]);
            echo $this->backend->template('container_footer');
            return;
        }

        // Form: add dropdown, table, save/cancel
        // --------------------
        echo '<form action="Area/area_access.php" method="post">';
        $this->backend->formInit('area.access_form');
        $addOptions = [];
        foreach ($groupLabels as $groupId => $label) {
            if (isset($rows[$groupId]) || !in_array((int)$groupId, $candidates, true)) continue;
            $addOptions[(string)$groupId] = $label;
        }
        if (!empty($addOptions)) {
            $this->backend->formField($this->area, [
                'name' => 'add_group_id',
                'value' => '',
                'colDef' => ['type' => 'id', 'display_type' => 'select', 'label' => '#area.button_add_group', 'tooltip' => '#area.tooltip_add_group'],
                'options' => $addOptions,
                'id' => $id,
                'attr' => ['onchange' => "if(this.value!=''){\$('.c9-layer:last input[name=\'function\']').val('update');\$('.c9-layer:last form').submit();}"],
                'selectPlease' => true
            ]);
        }
        else {
            $this->backend->formReplace('{data_add_group_id}', '');
        }
        $this->backend->formReplace('{access_table}', $table);
        $this->backend->formHidden('id', $id);
        $this->backend->formHidden('function', 'save');
        echo $this->backend->form('area.access_form_buttons', ['{area_id}' => $id]);
        echo '</form>';
        echo $this->backend->template('container_footer');
    }

    public function updatePreviousOptions(): void
    {
        $areaId = (int)($_REQUEST['id'] ?? 0);
        $parentId = (int)($_REQUEST['data']['parent_id'] ?? 0);
        $instanceId = (int)($_REQUEST['data']['instance_id'] ?? 0);

        $previousOptions = $this->_getPreviousOptions($parentId, $areaId, $instanceId);
        $lastKey = array_key_last($previousOptions);

        foreach ($previousOptions as $key => $val) {
            $selected = ($key === $lastKey) ? ' selected' : '';            
            echo "<option value=\"{$key}\"{$selected}>" . $this->cms->_strip_tags($val) . "</option>\n";
        }
    }

    /**
     * Ajax: return the options of the select field "parent_id" again - when the editor
     * changes the instance in the form: only folders of the same
     * instance come into question as parent (Area::hookOnSave()). Preselected is "nothing" (root);
     * the predecessor list is fetched again by area_form_instance_select.html in the same step.
     *
     * Request: id = own area, data[instance_id] = newly selected instance
     */
    public function updateParentOptions(): void
    {
        $areaId = (int)($_REQUEST['id'] ?? 0);
        $instanceId = (int)($_REQUEST['data']['instance_id'] ?? 0);

        foreach ($this->_getParentOptions($areaId, $instanceId) as $key => $val) {
            $selected = ($key === 0) ? ' selected' : '';
            echo "<option value=\"{$key}\"{$selected}>" . $this->cms->_strip_tags($val) . "</option>\n";
        }
    }

    /**
     * Step 1 when creating: select the content type (backend/area_create.php).
     *
     * A separate step, because the type determines the entire form and cannot be changed later.
     * Each entry of the selection is a link that replaces the layer
     * with area_edit.php with function=create and the selected type.
     *
     * Blueprints: under each type there are additionally the files from
     * blueprints/{type}/*.xml and var/area_blueprints/*.xml (export format, one
     * area with settings and fields, no records; AreaService::
     * blueprints()) - one link per file (area_create_form_link.html)
     * with &blueprint={file name without .xml}. Label and note are <label> and
     * <note> of the area in the file (snippet references allowed). The form takes over
     * the settings of the file (_editCreate()); after saving,
     * RecordImport::importBlueprint() creates the fields (_editSave()).
     */
    public function create():void {
        // Bread crum navi
        $this->backend->setNaviLabel('#button_create');
        
        // Container header
        echo $this->backend->template('container_header');

        echo '<form action="Area/area_edit.php" method="post">';

        // Formular
        $linkPrefix = "cApp.layers.replace('Area/area_edit.php?function=create";
        $linkSuffix = "');";
        $replace = [
            '{link_prefix}' => $linkPrefix,
            '{link_suffix}' => $linkSuffix
        ];
        foreach (AreaService::BLUEPRINT_TYPES as $type) {
            $links = '';
            foreach ($this->_blueprints($type) as $name => $area) {
                $links .= $this->backend->template('area.area_create_form_link', [
                    '{link_prefix}' => $linkPrefix,
                    '{link_suffix}' => $linkSuffix,
                    '{type}' => $type,
                    '{blueprint}' => $name,
                    '{headline}' => htmlspecialchars($this->cms->textOrSnippet((string)$area['label'])),
                    '{label}' => htmlspecialchars($this->cms->textOrSnippet((string)$area['label'])),
                    '{note}' => htmlspecialchars($this->cms->textOrSnippet((string)$area['note'])),
                ]);
            }
            $replace['{blueprints_' . $type . '}'] = $links;
        }
        $this->backend->formInit('area.area_create_form', $replace);
        echo $this->backend->form();

        echo '</form>';

        echo $this->backend->template('container_footer');
    }

    /**
     * Create / copy / change / delete (backend/area_edit.php).
     *
     * State machine via $_REQUEST['function'] - the flow is the same in all modules
     * the same (developer docs, chapter 05 (Backend development)):
     *
     *     create          empty form with defaults from Area::init()
     *     edit            load and show the area (default if nothing is set)
     *     copy            load, show the copy mask - see class comment
     *     copy_save       create the copy (settings, fields, assignments); on
     *                     errors 'copy_errors': mask again with the input
     *     save            save; if that fails, it switches to 'errors'
     *     errors          show the form again, with input and error message
     *     skip            area cannot be loaded - only error message, no form
     *     delete          show deletion confirmation
     *     delete_confirm  perform deletion
     *
     * The order of the if blocks is the flow: first fetch data (create /
     * load), then heading, then the writing action, then the form. Because
     * _editSave() sets $this->function to 'errors' on errors, it falls
     * then into the form branch - a redirect is not needed.
     */
    public function edit(): void
    {
        // Seiten-Parameter
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = (string) ($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array) ($_REQUEST['data'] ?? []);
        $this->data['area_id'] = $this->id;
        // Blueprint (only when creating; kept via 'errors' as a hidden field)
        $this->blueprint = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_REQUEST['blueprint'] ?? ''));

        // Breadcrumb navigation (copy, copy_save, copy_errors: one entry)
        $naviFunction = str_starts_with($this->function, 'copy') ? 'copy' : $this->function;
        $this->backend->setNaviLabel('#area.menu_item_' . $naviFunction);

        // Container header
        echo $this->backend->template('container_header');

        // Create
        if ($this->function == 'create') {
            $this->_editCreate();
        }

        // Load
        if (in_array($this->function, ['edit', 'copy', 'delete', 'delete_confirm'], true)) {
            $this->_editLoad();
        }

        // H1
        echo $this->_editHeadline();

        // Save
        if ($this->function == 'save') {
            $this->_editSave();
        }

        // Copy
        if ($this->function == 'copy_save') {
            $this->_copySave();
        }

        // Delete confirm
        if ($this->function == 'delete_confirm') {
            $this->_editDeleteConfirm();
        }

        // Errors
        if (in_array($this->function, ['errors', 'skip'], true)) {
            $this->_editErrors();
        }

        // Edit form
        if (in_array($this->function, ['create', 'edit', 'update', 'errors'], true)) {
            $this->_editForm();
        }

        // Copy form (also after an error while copying)
        if (in_array($this->function, ['copy', 'copy_errors'], true)) {
            $this->_copyForm();
        }

        // Delete form
        if ($this->function == 'delete') {
            $this->_editDeleteForm();
        }

        echo $this->backend->template('container_footer');
    }

    // ====================
    // edit() Hilfsfunktionen

    /**
     * function=create: pre-fill the form with the schema defaults.
     */
    private function _editCreate(): void
    {
        // Page parameters may override default values
        // (init() already takes values set in $data along unchanged -
        // so e.g. a call with ?data[parent_id]=5 can preset the folder)
        // Blueprint: its settings as defaults - label and note stay empty,
        // the field references (default_field_id …) only arise with the fields
        $defaults = $this->service->blueprintDefaults($this->_blueprintArea((string)($this->data['type'] ?? '')));
        if ($defaults) $this->data = array_merge($defaults, $this->data);
        $this->data = $this->area->init($this->data);
        $this->id = 0;
    }

    /** Blueprints of a type (AreaService::blueprints()) */
    private function _blueprints(string $type): array
    {
        return $this->service->blueprints($type);
    }

    /**
     * The selected blueprint ($this->blueprint) for the type - null if none
     * is selected, the file is missing or its area has a different type.
     */
    private function _blueprintArea(string $type): ?array
    {
        return $this->blueprint === '' ? null : $this->service->blueprint($type, $this->blueprint);
    }

    /**
     * function=edit|copy|delete|delete_confirm: fetch the area from the database.
     */
    private function _editLoad(): void
    {
        $this->data = $this->area->load($this->id);
        if (!$this->data) {
            // Requested record not found
            $this->function = 'skip';
        }
    }

    /**
     * The original of a copy: when the mask is called, from id, afterwards from the
     * hidden field source_id. (The mask deliberately has NO field 'id': the Ajax
     * calls for the parent and predecessor list would exclude the area with this id -
     * but the original should remain selectable as predecessor.)
     */
    private function _copySource(): ?array
    {
        $sourceId = (int)($_REQUEST['source_id'] ?? 0) ?: $this->id;
        $source = ($sourceId > 0) ? $this->area->load($sourceId) : null;
        return $source ?: null;
    }

    /**
     * function=copy: the copy mask - label, name, icon, instance, parent folder,
     * predecessor and note; everything else the copy takes from the original (see
     * class comment). Pre-filled with the values of the original, the predecessor with
     * the original itself; after an error with the inputs of the request.
     */
    private function _copyForm(): void
    {
        $source = $this->_copySource();
        if (!$source) {
            $this->_editErrors();
            return;
        }
        $fromRequest = ($this->function === 'copy_errors');
        $input = $fromRequest ? (array)($_REQUEST['data'] ?? []) : [];
        $changed = $fromRequest ? ' data-changed="true"' : '';
        echo '<form action="Area/area_edit.php" method="post"' . $changed . '>';

        $type = (string)$source['type'];
        $this->backend->formInit('area.area_copy_form', [
            '{source_label}' => htmlspecialchars($this->cms->textOrSnippet((string)$source['label'])),
            '{source_name}' => htmlspecialchars((string)$source['name']),
        ]);
        $this->backend->formHidden('source_id', (int)$source['area_id']);
        $this->backend->formHidden('function', 'copy_save');
        $this->backend->formStatic($this->area, 'type', $type);

        // Pre-fill: "{label} (copy)" and "{name}_copy" (name: at most 32 characters)
        $copyLabel = (string)($input['label'] ?? '');
        if ($copyLabel === '') {
            $copyLabel = $this->cms->textOrSnippet((string)$source['label']) . ' ' . $this->cms->snippet('area.suffix_copy_label');
        }
        $copyName = (string)($input['name'] ?? '');
        if ($copyName === '') {
            $copyName = substr((string)$source['name'], 0, 32 - 5) . '_copy';
        }
        $this->backend->formField($this->area, ['name' => 'label', 'value' => $copyLabel, 'id' => 0, 'primary' => true]);
        $this->backend->formField($this->area, ['name' => 'name', 'value' => $copyName, 'id' => 0]);
        $this->backend->formField($this->area, ['name' => 'icon', 'value' => (string)($input['icon'] ?? $source['icon']), 'id' => 0]);

        // Instance, parent folder, predecessor - the same fields and Ajax paths as in the
        // edit form (_editForm()); parent and predecessor per instance
        $instanceId = (int)($input['instance_id'] ?? $source['instance_id']);
        $parentId = (int)($input['parent_id'] ?? $source['parent_id'] ?? 0);
        $previousId = array_key_exists('previous_id', $input) ? (int)$input['previous_id'] : (int)$source['area_id'];
        $this->backend->formField($this->area, [
            'id' => 0,
            'name' => 'instance_id',
            'value' => $instanceId,
            'options' => $this->_getInstanceOptions(),
            'selectPlease' => false,
            'template' => 'area.area_form_instance_select'
        ]);
        $this->backend->formField($this->area, [
            'id' => 0,
            'name' => 'parent_id',
            'value' => $parentId,
            'display_type' => 'select',
            'options' => $this->_getParentOptions(0, $instanceId),
            'selectPlease' => false,
            'template' => 'area.area_form_parent_select'
        ]);
        $this->backend->formField($this->area, [
            'id' => 0,
            'name' => 'previous_id',
            'value' => $previousId,
            'colDef' => ['type' => 'integer', 'label' => '#area.field_area_previous_id'],
            'display_type' => 'select',
            'options' => $this->_getPreviousOptions($parentId, 0, $instanceId),
        ]);
        $this->backend->formField($this->area, [
            'id' => 0,
            'name' => 'note',
            'value' => (string)($input['note'] ?? $source['note'] ?? '')
        ]);

        // The rest: is taken over and can be changed afterwards
        foreach (['default_field_id', 'sort_field_id', 'default_template_id'] as $key) {
            if ($key !== 'default_template_id' && !Area::isContentType($type)) continue;
            $this->backend->formStatic($this->area, $key, $this->cms->snippet('area.blueprint_note_later'));
        }

        // (placeholders of the fields that the type does not know disappear)
        echo $this->backend->form('area.form_copy_run', [], true);
        echo '</form>';
    }

    /**
     * function=copy_save: create the copy.
     *
     * 1. save the area: label, name, icon, instance, parent, predecessor and
     *    note from the mask, type, sort direction and details from the original
     * 2. record areas: create the fields of the original in the copy and
     *    set the field references (default/sort/login field) as well as default template and
     *    template assignments - via the blueprint import
     *    (RecordImport::importBlueprint(), Quelle: RecordExport::areaBlueprintFile()).
     *    references of the original to itself point in the copy to the copy,
     *    references to other areas remain (there a new per-relation
     *    counter-field is created, as when a reference field is created by hand).
     *    folders and template areas: only step 1 and the template assignments.
     *
     * Errors in step 1 (e.g. a name already taken) show the mask again; errors
     * in step 2 leave the area in place - the layer stays open with the message
     * open (as when creating from a blueprint).
     */
    private function _copySave(): void
    {
        $source = $this->_copySource();
        if (!$source) {
            $this->function = 'skip';
            return;
        }
        $input = array_intersect_key((array)($_REQUEST['data'] ?? []), array_flip(AreaService::COPY_FIELDS));
        $areaId = (int)$this->service->copy($source, $input);
        if ($areaId < 1) {
            $this->function = 'copy_errors';
            $this->_editErrors();
            return;
        }
        if (!empty($this->cms->errors)) {
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->cms->snippet('area.message_blueprint_errors') . '<br>' . $this->cms->errorMsg()
            ]);
            $this->cms->errorsReset();
            $this->backend->buttonBarReset();
            $this->backend->buttonAdd('button_close', "cApp.layers.invalidate(null, 2); cApp.layers.close();");
            echo $this->backend->buttonBar();
            return;
        }

        $this->backend->balloon('success', $this->cms->snippet('area.message_area_copied'));
        // Continue on the info page of the copy: if the mask came from the info page of the
        // original, the copy replaces it (otherwise "Back" would lead to the original);
        // from the context menu of the list, the info page lies directly above the list.
        // Reload the list below it (new entry)
        $this->backend->script("
            const \$referer = $('article .c9-layer').eq(-2);
            if (\$referer.length && (\$referer.attr('data-url') || '').startsWith('Area/area_info.php')) cApp.layers.removeReferer();
            cApp.layers.invalidate(null, 1);
            cApp.layers.replace('Area/area_info.php?id={$areaId}', true);
        ");
    }

    /**
     * Heading of the form.
     *
     * Only when deleting does the name of the affected area stand above it - there it is
     * the actual information. Otherwise the generic heading is sufficient.
     */
    private function _editHeadline() {
        if ($this->function == 'delete') {
            $label = $this->data['label'] ?? '';
        }
        elseif (str_starts_with($this->function, 'copy')) {
            $label = $this->cms->snippet('area.headline_area_copy');
        }
        else {
            $label = $this->cms->snippet('area.headline_area_info');
        }
        return $this->backend->template('headline1', [
            '{text}' => $label
        ]);
    }

    /**
     * function=save: speichern.
     *
     * $fields empty means: all schema fields that are in $data are
     * written. Validation, transaction and tree maintenance are in Dataset::save()
     * or in the hooks of Area - here only the feedback to the interface remains.
     *
     * Success closes the layer and reloads the one below it; failure
     * switches to 'errors', edit() then shows the form again with the inputs and
     * the error message from Cms::errors.
     */
    private function _editSave(): void
    {
        // Check data and save - new: with blueprint via AreaService
        $isNew = $this->id < 1;
        // Lookups without entries send nothing - "none left" must still
        // arrive (Area::hookOnSaved() only writes passed lists)
        if (Area::isContentType((string)($this->data['type'] ?? ''))) {
            $this->data['trigger_template_ids'] ??= [];
            $this->data['selectable_templates'] ??= [];
            $this->data['override_templates'] ??= [];
        }
        $areaId = $isNew
            ? (int)$this->service->create($this->data, $this->blueprint)
            : (int)$this->area->save([], $this->data);
        if ($areaId > 0) {
            // Balloon Message
            $msg = $this->cms->snippet('area.message_area_saved');
            $this->backend->balloon('success', $msg);

            // Blueprint: create and link fields. Errors here leave the
            // area in place - the layer then stays open with the message,
            // so that it does not get lost in a balloon
            if ($isNew && $this->service->blueprintFailed()) {
                echo $this->backend->template('alert_danger', [
                    '{text}' => $this->cms->snippet('area.message_blueprint_errors') . '<br>' . $this->cms->errorMsg()
                ]);
                $this->cms->errorsReset();
                $this->backend->buttonBarReset();
                $this->backend->buttonAdd('button_close', "cApp.layers.invalidate(null, 2); cApp.layers.close();");
                echo $this->backend->buttonBar();
                return;
            }

            // Refresh referer and close window - when editing via the
            // info page also the list below it (new label, position)
            $this->backend->script("
                cApp.layers.invalidate(null, 2);
                cApp.layers.close();
            ");
        }
        else {
            $this->function = 'errors';
        }
    }

    /**
     * Perform deletion - always SOFT only.
     *
     * Final deletion is performed exclusively by the system tool after the expiry of
     * admin.trash_retention_days (Zeitplaner-Aufgabe "Papierkorb leeren").
     *
     * The area takes its records along (Area::hookOnSoftDelete), each one
     * individually - therefore the process can take a long time for large areas. From
     * admin.mass_delete_threshold it is therefore moved into a background process;
     * the same call runs there, only outside the request.
     *
     * $withChilds controls what happens to the sub-areas:
     *     true   the whole subtree is marked along, the tree remains unchanged
     *     false  the children move up one level (Dataset::liftChildren())
     * The radio field for this is in area_delete_with_option_form.html and comes back as
     * $_REQUEST['deletechilds'].
     */
    private function _editDeleteConfirm(): void
    {
        $withChilds = (string)($_REQUEST['deletechilds'] ?? 'false') === 'true';

        // Move large areas to the background
        $count = $this->massDeletion->affectedCount('area', $this->id, $withChilds, 'delete');
        if ($this->massDeletion->needsBackground($count)) {
            $error = '';
            if ($this->massDeletion->start('area', $this->id, 'delete', $withChilds, $error)) {
                $this->backend->balloon('info', $this->cms->snippet('message_background_started'));
                $this->backend->script("
                    cApp.layers.invalidate();
                    cApp.layers.close();
                ");
                return;
            }
            // Start failed -> continue within the request instead of not deleting at all
            $this->system->log('massdelete-errors', "area {$this->id}: {$error}");
        }

        if ($this->area->delete($this->id, $withChilds)) {
            // Balloon Message
            $msg = $this->cms->snippet('area.message_area_deleted');
            $this->backend->balloon('success', $msg);
            
            // Close window
            $this->backend->script("
                cApp.layers.invalidate();
                cApp.layers.close();
            ");
        }
        else {
            $this->_editErrors();
            $this->function = 'delete';
        }
    }

    /**
     * Output error messages from Cms::errors as a red box.
     */
    private function _editErrors(): void
    {
        echo $this->backend->template('alert_danger',[
            '{text}' => $this->cms->errorMsg()
        ]);
    }

    /**
     * The input form.
     *
     * The structure follows the order in the form:
     *
     *  1. einfache Schema-Felder (type, name, icon, label)
     *  2. parent_id      - selection among the folders, with its own template that, on
     *                      change, triggers updatePreviousOptions() via Ajax
     *  3. previous_id    - not a database field, but an input aid for `position`
     *  4. note
     *  5. default_field_id / sort_field_id / sort_order - defaults for the records
     *     of the area; the selection lists contain only field types that qualify as title or
     *     as sort criterion
     *  6. default_template_id, selectable_templates, override_templates - drei
     *     Lookup-Felder auf `template`
     *
     * data-changed="true" on the <form> marks the form as "changed" after a failed
     * save - the JavaScript then asks when closing,
     * instead of silently discarding the input.
     */
    private function _editForm(): void
    {
        if ($this->function == 'save') $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Area/area_edit.php" method="post"' . $changed . '>';

        $type = $this->data['type'];
        $this->backend->formInit('area.area_edit_form_' . $type);

        // The input masks of the 3 types differ - therefore 'type' is determined in the preceding
        // step - see create()
        $this->backend->formStatic($this->area, 'type', $type);
        $this->backend->formHidden('data[type]', $type);
        if ($this->id < 1 && $this->blueprint !== '') $this->backend->formHidden('blueprint', $this->blueprint);

        // Label
        $this->backend->formField($this->area, [
            'name' => 'label',
            'value' => $this->data['label'] ?? '',
            'id' => $this->id,
            'primary' => true
        ]);

        // Data fields
        $dataFields = [
            'name',
            'icon',
        ];
        foreach ($dataFields as $key) {
            $this->backend->formField($this->area, [
                'name' => $key,
                'value' => $this->data[$key] ?? '',
                'id' => $this->id
            ]);
        }

        // instance_id
        // --------------------
        // To which website does the container belong? Applies to all three types.
        // The records or templates of the container inherit the assignment
        // (see Area::hookOnSaved()).
        // If the instance changes, the parent and predecessor lists are reloaded via Ajax
        // (area_form_instance_select.html) - the tree is per tenant.
        $instanceId = (int)($this->data['instance_id'] ?? 0);
        $this->backend->formField($this->area, [
            'id' => $this->id,
            'name' => 'instance_id',
            'value' => $instanceId,
            'options' => $this->_getInstanceOptions(),
            'selectPlease' => false,
            'template' => 'area.area_form_instance_select'
        ]);

        // access_group_ids - only when creating (A4, developer docs, chapter 11 (Permissions)): groups that
        // may read the area; preselected are those with the selected instance
        // --------------------
        if ($this->id < 1 && $type !== 'folder') {
            $selected = ($this->function === 'create')
                ? $this->area->accessCandidates($instanceId)
                : array_map('intval', (array)($this->data['access_group_ids'] ?? []));
            $options = $this->backend->getGroups();
            unset($options[InstanceCache::SUPERUSER_GROUP_ID]);
            $this->backend->formField($this->area, [
                'name' => 'access_group_ids',
                'value' => $selected,
                'id' => $this->id,
                'options' => $options,
            ]);
            // so that "no group" arrives as a selection (without a tick the field is missing from the POST)
            $this->backend->formHidden('data[access_group_ids][]', 0);
        }

        // parent_id
        // --------------------
        // List folders of the instance (except the record itself)
        $areaId = (int)($this->id ?? 0);
        $parentOptions = $this->_getParentOptions($areaId, $instanceId);
        // spezielles Template: update on change
        $this->backend->formField($this->area, [
            'id' => $this->id,
            'name' => 'parent_id',
            'value' => $this->data['parent_id'] ?? '',
            'display_type' => 'select',
            'options' => $parentOptions,
            'selectPlease' => false,
            'template' => 'area.area_form_parent_select'
        ]);

        // previous_id - vorangehender Datensatz
        // --------------------
        // Not a database field. The editor selects the predecessor, and what is saved is
        // the `position` derived from it. Here the way back: from the saved position
        // find the area that stands before it.
        $parentId = (int)($this->data['parent_id'] ?? 0);
        $positionOptions = $this->_getPreviousOptions($parentId, $areaId, $instanceId);
        $previousId = 0; // Default: at the start

        // Predecessor = largest position that is still smaller than one's own,
        // under the same parent. No match -> the area itself is at the start,
        // $previousId stays 0.
        // Root areas have parent_id IS NULL, not 0 - hence the special branch.
        // There the position is continuous across all instances table-wide: without
        // an instance filter the predecessor would often be an area of another instance,
        // which the selection (_getPreviousOptions()) does not contain - it would be displayed
        // then as "(at the start)".
        if ($areaId > 0 && isset($this->data['position'])) {
            $currentPos = (int)$this->data['position'];
            $sql = "SELECT `area_id` FROM `area` WHERE `area_id` != ? AND `deleted`=0";
            $bindTypes = 'i';
            $values = [$areaId];

            if ($parentId === 0) {
                $sql .= " AND `parent_id` IS NULL";
                if ($instanceId > 0) {
                    $sql .= " AND `instance_id` = ?";
                    $bindTypes .= 'i';
                    $values[] = $instanceId;
                }
            } else {
                $sql .= " AND `parent_id` = ?";
                $bindTypes .= 'i';
                $values[] = $parentId;
            }

            $sql .= " AND `position` < ? ORDER BY `position` DESC LIMIT 1";
            $bindTypes .= 'i';
            $values[] = $currentPos;

            $this->db->query($sql, $bindTypes, $values);
            if ($row = $this->db->fetch()) {
                $previousId = (int)$row['area_id'];
            }
        } elseif ($this->function === 'create') {
            // For a new record, select the end of the list by default
            $lastId = array_key_last($positionOptions);
            $previousId = $lastId !== null ? $lastId : 0;
        }

        $this->backend->formField($this->area, [
            'id' => $this->id,
            'name' => 'previous_id',
            'value' => $previousId,
            'colDef' => ['type' => 'integer', 'label' => '#area.field_area_previous_id'],
            'display_type' => 'select',
            'options' => $positionOptions,
        ]);

        $this->backend->formField($this->area, [
            'id' => $this->id,
            'name' => 'note',
            'value' => $this->data['note'] ?? ''
        ]);

        if (Area::isContentType($type)) {
            // default_field_id
            // --------------------
            // field from whose content the compact record list is read
            // (only certain types)
            if (!empty($this->blueprint)) {
                $this->backend->formStatic($this->area, 'default_field_id', $this->cms->snippet('area.blueprint_note_later'));
            }
            else {
                $defaultFieldTypes = array_merge(
                    $this->cms->textTypes,
                    $this->cms->numericTypes,
                    $this->cms->dateTypes,
                    ['encoded']
                );
                $this->cms->select([
                    'filter' => [
                        'area_id' => $this->id,
                        'type' => $defaultFieldTypes,
                    ],
                    // filtermode[type] = OR
                    'limit' => -1,
                    'sort' => 'position ASC'
                ], $this->field);

                $defaultFieldOptions = ['' => 'record_id'];
                while ($row = $this->db->fetch()) {
                    $label = strtr($this->cms->snippet('parenthetical_note'), [
                        '{text}' => $this->cms->textOrSnippet($row['label'] ?? '')
                    ]);
                    $defaultFieldOptions[(int)$row['field_id']] = $row['name'] . ' ' . $label;
                }

                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'default_field_id',
                    'value' => $this->data['default_field_id'] ?? '',
                    'display_type' => 'select',
                    'options' => $defaultFieldOptions,
                ]);
            }
            
            // sort_field_id
            // --------------------
            // Field for default sorting in the area (only certain types)
            // Fallback: changed_at
            if (!empty($this->blueprint)) {
                $this->backend->formStatic($this->area, 'sort_field_id', $this->cms->snippet('area.blueprint_note_later'));
            }
            else {
                $sortFieldTypes = array_merge(
                    $this->cms->textTypes,
                    $this->cms->numericTypes,
                    $this->cms->dateTypes
                );
                $this->cms->select([
                    'filter' => [
                        'area_id' => $this->id,
                        'type' => $sortFieldTypes,
                    ],
                    // filtermode[type] = OR
                    'limit' => -1,
                    'sort' => 'position ASC'
                ], $this->field);

                $sortFieldOptions = [];
                $containsChangedAt = false;
                while ($row = $this->db->fetch()) {
                    $label = strtr($this->cms->snippet('parenthetical_note'), [
                        '{text}' => $this->cms->textOrSnippet($row['label'] ?? '')
                    ]);
                    $name = $row['name'];
                    $sortFieldOptions[(int)$row['field_id']] = $name . ' ' . $label;
                    if ($name === 'changed_at') $containsChangedAt = true;
                }
                if (!$containsChangedAt) {
                    $sortFieldOptions = ['' => 'changed_at'] + $sortFieldOptions;
                }

                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'sort_field_id',
                    'value' => $this->data['sort_field_id'] ?? '',
                    'display_type' => 'select',
                    'options' => $sortFieldOptions,
                ]);

                // sort_order
                // --------------------
                // Direction for default sorting
                // Fallback: DESC
                $sortOrderOptions = [
                    'ASC' => $this->cms->snippet('label_sort_asc'),
                    'DESC' => $this->cms->snippet('label_sort_desc')
                ];
                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'sort_order',
                    'value' => $this->data['sort_order'] ?? 'DESC',
                    'display_type' => 'radio',
                    'options' => $sortOrderOptions
                ]);
            }

            // details.slug_prefix
            // --------------------
            // First path part of the URLs of the records (/farben/blau), per language of the
            // instance one input field - all one below the other, no tabs. Empty
            // languages take the prefix of the main language (Area::slugPrefixErrors()).
            // The languages are those of the instance when the mask is built; if the
            // instance changes, foreign languages are dropped when saving.
            $languages = $this->instanceCache->languages((int)($this->data['instance_id'] ?? 0));
            $prefixes = $this->data['details']['slug_prefix'] ?? [];
            if (!is_array($prefixes)) $prefixes = [(int)array_key_first($languages) => (string)$prefixes];
            $placeholders = '';
            foreach (array_keys($languages) as $languageId) {
                $placeholders .= '{data_details_slug_prefix_' . (int)$languageId . "}\n";
            }
            $this->backend->formReplace('{data_details_slug_prefix}', $placeholders);
            $colDef = [
                'type'    => 'chars',
                'label'   => $this->area->getSchema()['details.slug_prefix']['label'],
                'tooltip' => $this->area->getSchema()['details.slug_prefix']['tooltip'],
                'max'     => 64,
                'suffix'  => '/',
            ];
            foreach ($languages as $languageId => $language) {
                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'details.slug_prefix',
                    'value' => (string)($prefixes[$languageId] ?? ''),
                    'colDef' => $colDef,
                    'placeholder' => '{data_details_slug_prefix_' . (int)$languageId . '}',
                    'langId' => (int)$languageId,
                    'langSuffix' => count($languages) > 1 ? (string)($language['code'] ?? '') : '',
                    'textLang' => (string)($language['code'] ?? ''),
                ]);
            }

            // details.tabs
            // --------------------
            // Display of multilingual fields in the input mask: all languages
            // one below the other (0) or one tab per language (1). Only offered if
            // an instance has more than one language at all - otherwise the
            // choice would have no effect (RecordBackendEdit::_editForm()).
            $hasMultilingualInstance = false;
            foreach ((array)($this->instanceCache->all()['instance_ids'] ?? []) as $instanceId) {
                if (count($this->instanceCache->languageIds((int)$instanceId)) > 1) {
                    $hasMultilingualInstance = true;
                    break;
                }
            }
            if ($hasMultilingualInstance) {
                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'details.tabs',
                    'value' => (int)($this->data['details']['tabs'] ?? 0),
                    'options' => [
                        0 => $this->cms->snippet('area.option_tabs_0'),
                        1 => $this->cms->snippet('area.option_tabs_1'),
                    ],
                ]);
            }

            // dependent
            // --------------------
            // Dependent area: target area of 'link' fields (firmly linked
            // content, see Area::hookDefinition())
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'dependent',
                'value' => (int)($this->data['dependent'] ?? 0),
            ]);

            // privacy_policy_id
            // --------------------
            // Deletion policy (module Privacy): the one for all instances and the one of the instance
            // of the area; a new area gets the default policy of its type
            $policies = $GLOBALS['app']->get(\Camindo\Core\Modules\Privacy\DeletePolicy::class);
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'privacy_policy_id',
                'value' => (int)($this->data['privacy_policy_id'] ?? 0) ?: $policies->standardId($type),
                'options' => $policies->optionsFor((int)($this->data['instance_id'] ?? 0)),
            ]);


            // default_template_id
            // --------------------
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'default_template_id',
                'value' => $this->data['default_template_id'] ?? '',
                'display_type' => 'lookup',
                'template' => 'edit_form_lookup_foreign_table'
            ]);

            $urlSuffix = "src_field=default_template_id";
            $searchUrl = "Template/template_list.php?" . $urlSuffix;
            $updateUrl = "Template/ajax_lookup_preview.php?" . $urlSuffix;
            $ids = [(int)$this->data['default_template_id'] ?? 0];
            $idString = implode(',', $ids);

            $this->backend->formReplace('{src_id}', $this->id);
            $this->backend->formReplace('{src_field}', 'default_template_id');
            $this->backend->formReplace('{search_url}', $searchUrl);
            $this->backend->formReplace('{update_url}', $updateUrl);
            $this->backend->formReplace('{data_ids}', $idString);
            $this->backend->formReplace('{max}', '1');
            $this->backend->formReplace('{preview}', $this->templateBackend->lookupPreviewCreate('default_template_id', $ids, 'data', 1));

            // selectable templates
            // --------------------
            $colDef = [
                'type' => 'ids',
                'label' => '#area.field_area_selectable_templates',
                'tooltip' => '#template.tooltip_access_selectable',
                'display_type' => 'lookup',
                'template' => 'edit_form_lookup_foreign_table'
            ];
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'selectable_templates',
                'colDef' => $colDef,
                'template' => 'edit_form_lookup_foreign_table'
            ]);

            $urlSuffix = "src_field=selectable_templates";
            $searchUrl = "Template/template_list.php?" . $urlSuffix;
            $updateUrl = "Template/ajax_lookup_preview.php?" . $urlSuffix;
            $ids = $this->selectableTemplateIds($this->id);
            $idString = implode(',', $ids);

            $this->backend->formReplace('{src_id}', $this->id);
            $this->backend->formReplace('{src_field}', 'selectable_templates');
            $this->backend->formReplace('{search_url}', $searchUrl);
            $this->backend->formReplace('{update_url}', $updateUrl);
            $this->backend->formReplace('{data_ids}', $idString);
            $this->backend->formReplace('{max}', '');
            $this->backend->formReplace('{preview}', $this->templateBackend->lookupPreviewCreate('selectable_templates', $ids, 'data', 0));


            // url-override templates
            // --------------------
            $colDef = [
                'type' => 'ids',
                'label' => '#area.field_area_override_templates',
                'tooltip' => '#template.tooltip_access_override',
                'display_type' => 'lookup',
                'template' => 'edit_form_lookup_foreign_table'
            ];
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'override_templates',
                'colDef' => $colDef,
                'template' => 'edit_form_lookup_foreign_table'
            ]);

            $urlSuffix = "src_field=override_templates";
            $searchUrl = "Template/template_list.php?" . $urlSuffix;
            $updateUrl = "Template/ajax_lookup_preview.php?" . $urlSuffix;
            $ids = $this->overrideTemplateIds($this->id);
            $idString = implode(',', $ids);

            $this->backend->formReplace('{src_id}', $this->id);
            $this->backend->formReplace('{src_field}', 'override_templates');
            $this->backend->formReplace('{search_url}', $searchUrl);
            $this->backend->formReplace('{update_url}', $updateUrl);
            $this->backend->formReplace('{data_ids}', $idString);
            $this->backend->formReplace('{max}', '');
            $this->backend->formReplace('{preview}', $this->templateBackend->lookupPreviewCreate('override_templates', $ids, 'data', 0));

            // list_template_id
            // --------------------
            // Display in the backend: template for the last column of the
            // record list and the detailed lookup preview
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'list_template_id',
                'value' => $this->data['list_template_id'] ?? '',
                'display_type' => 'lookup',
                'template' => 'edit_form_lookup_foreign_table'
            ]);

            $urlSuffix = "src_field=list_template_id";
            $searchUrl = "Template/template_list.php?" . $urlSuffix;
            $updateUrl = "Template/ajax_lookup_preview.php?" . $urlSuffix;
            $ids = [(int)($this->data['list_template_id'] ?? 0)];
            $idString = implode(',', $ids);

            $this->backend->formReplace('{src_id}', $this->id);
            $this->backend->formReplace('{src_field}', 'list_template_id');
            $this->backend->formReplace('{search_url}', $searchUrl);
            $this->backend->formReplace('{update_url}', $updateUrl);
            $this->backend->formReplace('{data_ids}', $idString);
            $this->backend->formReplace('{max}', '1');
            $this->backend->formReplace('{preview}', $this->templateBackend->lookupPreviewCreate('list_template_id', $ids, 'data', 1));

            // trigger_template_ids
            // --------------------
            // Event methods (onCreate, onSave ...) for the records of the
            // area, see TriggerTemplates - several; the order of the
            // preview is the execution order (ordered=1: up/down)
            $colDef = [
                'type' => 'ids',
                'label' => '#area.field_area_trigger_template_ids',
                'tooltip' => '#area.tooltip_field_trigger_template_ids',
                'display_type' => 'lookup',
                'template' => 'edit_form_lookup_foreign_table'
            ];
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'trigger_template_ids',
                'colDef' => $colDef,
                'template' => 'edit_form_lookup_foreign_table'
            ]);

            $urlSuffix = "src_field=trigger_template_ids";
            $searchUrl = "Template/template_list.php?" . $urlSuffix;
            $updateUrl = "Template/ajax_lookup_preview.php?" . $urlSuffix . "&ordered=1";
            $ids = array_map('intval', (array)($this->data['trigger_template_ids'] ?? []));
            $idString = implode(',', $ids);

            $this->backend->formReplace('{src_id}', $this->id);
            $this->backend->formReplace('{src_field}', 'trigger_template_ids');
            $this->backend->formReplace('{search_url}', $searchUrl);
            $this->backend->formReplace('{update_url}', $updateUrl);
            $this->backend->formReplace('{data_ids}', $idString);
            $this->backend->formReplace('{max}', '');
            $this->backend->formReplace('{preview}', $this->templateBackend->lookupPreviewCreate('trigger_template_ids', $ids, 'data', 0, true));

            // overview_template_id
            // --------------------
            // Overview under the address that consists only of the slug prefix
            // (/de/modelle) - without record, see Area
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'overview_template_id',
                'value' => $this->data['overview_template_id'] ?? '',
                'display_type' => 'lookup',
                'template' => 'edit_form_lookup_foreign_table'
            ]);

            $urlSuffix = "src_field=overview_template_id";
            $searchUrl = "Template/template_list.php?" . $urlSuffix;
            $updateUrl = "Template/ajax_lookup_preview.php?" . $urlSuffix;
            $ids = [(int)($this->data['overview_template_id'] ?? 0)];
            $idString = implode(',', $ids);

            $this->backend->formReplace('{src_id}', $this->id);
            $this->backend->formReplace('{src_field}', 'overview_template_id');
            $this->backend->formReplace('{search_url}', $searchUrl);
            $this->backend->formReplace('{update_url}', $updateUrl);
            $this->backend->formReplace('{data_ids}', $idString);
            $this->backend->formReplace('{max}', '1');
            $this->backend->formReplace('{preview}', $this->templateBackend->lookupPreviewCreate('overview_template_id', $ids, 'data', 1));
        }

        // Login to the website (type 'profile')
        // --------------------
        // Which input fields of the area carry the login name and password?
        // name: 'chars' or 'encrypted' (email addresses are stored encrypted),
        // password: 'password'. A new area has no fields yet - the
        // lists then remain empty and are filled after the fields are created.
        if ($type === 'profile') {
            if (!empty($this->blueprint)) {
                $this->backend->formStatic($this->area, 'login_name_field_id', $this->cms->snippet('area.blueprint_note_later'));
                $this->backend->formStatic($this->area, 'login_password_field_id', $this->cms->snippet('area.blueprint_note_later'));
            }
            else {
                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'login_name_field_id',
                    'value' => (int)($this->data['login_name_field_id'] ?? 0),
                    'options' => $this->_getFieldOptions(['chars', 'encrypted']),
                    'selectPlease' => true,
                ]);
                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'login_password_field_id',
                    'value' => (int)($this->data['login_password_field_id'] ?? 0),
                    'options' => $this->_getFieldOptions(['password']),
                    'selectPlease' => true,
                ]);
            }
            // Second factor of the login (UserSecondFactor::POLICIES)
            $options = [];
            foreach (UserSecondFactor::POLICIES as $policy) {
                $options[$policy] = $this->cms->snippet('area.option_second_factor_' . $policy);
            }
            $this->backend->formField($this->area, [
                'id' => $this->id,
                'name' => 'details.second_factor',
                'value' => (string)($this->data['details']['second_factor'] ?? 'none'),
                'options' => $options,
            ]);
        }

        // Profil-Bereiche (type 'ugc')
        // --------------------
        // From which profile areas may the authors come? Available for selection are
        // the profile areas of the instance of the area and its readable instances -
        // only their records are visible to the frontend of this instance (Cms::$sqlAccess).
        if ($type === 'ugc') {
            if (!empty($this->blueprint)) {
                $this->backend->formStatic($this->area, 'profile_area_ids', $this->cms->snippet('area.blueprint_note_later'));
            }
            else {
                $instanceId = (int)($this->data['instance_id'] ?? 0);
                $this->backend->formField($this->area, [
                    'id' => $this->id,
                    'name' => 'details.profile_area_ids',
                    'value' => $this->system->sanitizeIds((array)($this->data['details']['profile_area_ids'] ?? []), true),
                    'options' => $this->backend->profileAreaOptions($instanceId),
                ]);
            }
        }

        // Hidden fields
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $this->id);

        // Print form
        echo $this->backend->form('form_save_cancel_note', removePlaceholders:true);

        // Textarea initialisieren
        $this->backend->script("cApp.ui._initTextareaGrow(\$('.c9-layer:last'));");

        echo '</form>';
    }


    /**
     * function=delete: deletion confirmation.
     *
     * The text of the warning depends on what disappears with the area - therefore
     * the count queries. Per area type:
     *
     *     folder    child areas in the nested-set subtree (lft BETWEEN lft AND rgt),
     *               live records and templates contained in it.
     *               Only if content is really attached does a form with the choice "with / without sub-areas" appear instead of the simple
     *               confirmation.
     *     template  number of templates in the area itself
     *     record    number of live records (tmp=0, deleted=0)
     *
     * `tmp = 0` excludes the working copies that arise during an edit
     * - they would otherwise distort the number.
     *
     * The counts run via the nested set and not via parent_id, because the
     * whole subtree is meant and not only the direct children.
     */
    private function _editDeleteForm(): void
    {
        echo '<form action="Area/area_edit.php" method="post">';

        $id = (int)$this->id;
        $areaType = $this->data['type'];

        if ($areaType == 'folder') {
            // Count of the child areas
            $sql = "SELECT COUNT(`_d2`.`area_id`)"
                ." FROM `area` AS `_d1`"
                ." JOIN `area` AS `_d2` ON `_d2`.`lft` BETWEEN `_d1`.`lft` AND `_d1`.`rgt`"
                ." WHERE `_d1`.`area_id` = ?"
                ." AND `_d2`.`area_id` != ?"
                ." AND `_d2`.`deleted` = 0";
            $this->db->query($sql, 'ii', [$id, $id]);
            $countChilds = $this->db->fetchNum()[0];

            if ($countChilds > 0) {
                // Count of all live records in the child areas
                $sql = "SELECT COUNT(`r`.`record_id`)"
                    ." FROM `area` AS `_d1`"
                    ." JOIN `area` AS `_d2` ON `_d2`.`lft` BETWEEN `_d1`.`lft` AND `_d1`.`rgt`"
                    ." JOIN `record` AS `r` ON `r`.`area_id` = `_d2`.`area_id`"
                    ." WHERE `_d1`.`area_id` = ?"
                    ." AND `_d2`.`area_id` != ?"
                    ." AND `_d2`.`deleted` = 0"
                    ." AND `r`.`tmp` = 0"
                    ." AND `r`.`deleted` = 0";
                $this->db->query($sql, 'ii', [$id, $id]);
                $countChildDatasets = $this->db->fetchNum()[0];

                // Count of all templates in the child areas
                $sql = "SELECT COUNT(`t`.`template_id`)"
                    ." FROM `area` AS `_d1`"
                    ." JOIN `area` AS `_d2` ON `_d2`.`lft` BETWEEN `_d1`.`lft` AND `_d1`.`rgt`"
                    ." JOIN `template` AS `t` ON `t`.`area_id` = `_d2`.`area_id`"
                    ." WHERE `_d1`.`area_id` = ?"
                    ." AND `_d2`.`area_id` != ?"
                    ." AND `_d2`.`deleted` = 0";
                $this->db->query($sql, 'ii', [$id, $id]);
                $countChildTemplates = $this->db->fetchNum()[0];

                if ($countChildDatasets + $countChildTemplates > 0) {
                    // Baue Meldung zusammen 
                    // contains ... areas
                    $childAreas = $this->cms->snippet('area.x_areas', ['count' => $countChilds]);
                    // ... records and ... templates (count in ICU message format)
                    $itemsArr = [];
                    if ($countChildDatasets > 0) {
                        $itemsArr[] = $this->cms->snippet('record.x_records', ['count' => $countChildDatasets]);
                    }
                    if ($countChildTemplates > 0) {
                        $itemsArr[] = $this->cms->snippet('template.x_templates', ['count' => $countChildTemplates]);
                    }
                    $items = implode($this->cms->snippet('area.elements_and'), $itemsArr);
                    $msg = strtr($this->cms->snippet('area.alert_folder_delete_with_contents'), [
                        '{child_areas}' => $childAreas,
                        '{items}' => $items,
                    ]);

                    // Erstelle Radio Select
                    $uid = $this->crypt->randomKey(16);
                    $this->backend->formInit('area.area_delete_with_option_form', [
                        '{text}' => $msg,
                        '{id}' => $uid
                    ]);
                }
                else {
                    $this->backend->formInit('area.area_delete_form', [
                        '{text}' => '{#area.alert_folder_delete}'
                    ]);
                }
            }
        }
        elseif ($areaType == 'template') {
            // Count of the templates in the area itself
            $sql = "SELECT COUNT(`template_id`) FROM `template` WHERE `area_id` = ?";
            $this->db->query($sql, 'i', [$id]);
            $countTemplates = $this->db->fetchNum()[0];
            if ($countTemplates > 0) {
                $items = $this->cms->snippet('template.x_templates', ['count' => $countTemplates]);
                $msg = str_replace('{items}', $items, $this->cms->snippet('area.alert_area_delete_with_templates'));
                $this->backend->formInit('area.area_delete_form', [
                    '{text}' => $msg
                ]);
            }
            else {
                $this->backend->formInit('area.area_delete_form', [
                    '{text}' => '{#area.alert_area_delete}'
                ]);
            }
        }
        elseif (Area::isContentType($areaType)) {
            // Datensatzbereich (record, profile, ugc)
            // Count of live records in the area itself
            $sql = "SELECT COUNT(`record_id`) FROM `record` WHERE `area_id` = ? AND `tmp`=0 AND `deleted`=0";
            $this->db->query($sql, 'i', [$id]);
            $countRecords = $this->db->fetchNum()[0];
            if ($countRecords > 0) {
                $items = $this->cms->snippet('record.x_records', ['count' => $countRecords]);
                $msg = str_replace('{items}', $items, $this->cms->snippet('area.alert_area_delete_with_records'));
                $this->backend->formInit('area.area_delete_form', [
                    '{text}' => $msg
                ]);
            }
            else {
                $this->backend->formInit('area.area_delete_form', [
                    '{text}' => '{#area.alert_area_delete}'
                ]);
            }
        }
        else {
            echo $this->backend->template('alert_danger', [
                '{text}' => 'Internal error: Area type not supported.'
            ]);
            return;
        }
        
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');

        // Print form
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }

    // ====================
    // Suchfunktion

    /**
     * Suche (backend/area_search.php).
     *
     * Two states: without function the mask, with function=search the execution.
     * Unlike list()/edit() there is no third branch here - _search() renders
     * no result list at all, but sends the search parameters back to the area list
     * (see there).
     */
    public function search(): void
    {
        // Seiten-Parameter
        $function = (string) ($_POST['function'] ?? '');

        if ($function == 'search') {
            $this->_search();
        }

        if ($function == '') {
            $this->_searchForm();
        }
    }

    /**
     * Suchmaske.
     *
     * searchField() instead of formField(): search fields are never required fields and write
     * to `filter[...]` instead of `data[...]`.
     */
    private function _searchForm():void
    {
        // Seiten-Parameter
        $filter = (array) ($_REQUEST['filter'] ?? []);

        echo '<form action="Area/area_search.php" method="post">';

        // Bread crum navi
        $this->backend->setNaviLabel('#button_search');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#area.headline_search');

        $this->backend->formInit('area.area_search_form');

        // label
        $this->backend->searchField($this->area, ['name' => 'label', 'value' => $filter['label'] ?? '']);

        // name
        $this->backend->searchField($this->area, ['name' => 'name', 'value' => $filter['name'] ?? '']);

        // parent_id - in the search mask no folder is excluded
        $parentOptions = $this->_getParentOptions(0);
        $this->backend->searchField($this->area, [
            'name' => 'parent_id',
            'value' => $filter['parent_id'] ?? '0',
            'display_type' => 'select',
            'options' => $parentOptions,
            'selectPlease' => true
        ]);

        // icon
        $this->backend->searchField($this->area, ['name' => 'icon', 'value' => $filter['icon'] ?? '']);

        // area_id
        $this->backend->searchField($this->area, ['name' => 'area_id', 'value' => $filter['area_id'] ?? '']);

        // instance_id
        $instanceOptions = $this->_instanceOptions();
        if (count($instanceOptions) > 0) {
            $this->backend->searchField($this->area, [
                'name' => 'instance_id',
                'value' => $filter['instance_id'] ?? '',
                'options' => $instanceOptions
            ]);
        }
        // default_template_id
        // --------------------
        $this->backend->searchField($this->area, ['name' => 'default_template_id', 'value' => $filter['default_template_id'] ?? '']);

        $urlSuffix = "src_field=default_template_id";
        $searchUrl = "Template/template_list.php?" . $urlSuffix;
        $updateUrl = "Template/ajax_slookup_preview.php?" . $urlSuffix;
        $filterIds = (array)($_REQUEST['filter']['default_template_id'] ?? []);
        $ids = $this->system->sanitizeIds($filterIds, true);
        $idString = implode(',', $ids);
        
        $this->backend->formReplace('{src_field}', 'default_template_id');
        $this->backend->formReplace('{search_url}', $searchUrl);
        $this->backend->formReplace('{update_url}', $updateUrl);
        $this->backend->formReplace('{data_ids}', $idString);
        $this->backend->formReplace('{max}', '1');
        $this->backend->formReplace('{preview}', $this->templateBackend->lookupPreviewCreate('default_template_id', $ids, 'filter', 0));

        // sort, order
        // --------------------
        $sortOptions = [
            'lft' => $this->cms->snippet('area.field_area_lft'), // nested set order
            'name' => $this->cms->snippet('area.field_area_name'),
            'icon' => $this->cms->snippet('area.field_area_icon'),
            'label' => $this->cms->snippet('area.field_area_label'),
            'area_id' => 'area_id'
        ];
        $this->backend->searchFieldSortOrder($sortOptions);

        // function
        $this->backend->formHidden('function', 'search');

        // Print form
        echo $this->backend->form('form_search_cancel');

        // Container footer
        echo $this->backend->template('container_footer');

        echo '</form>';
    }

    /**
     * function=search: run the search.
     *
     * The result list is not rendered here. Instead, the search layer closes and the
     * list layer below it is reloaded with the search parameters in
     * the URL - list() evaluates them via Cms::select(['request' => 'all']).
     * Advantage: sorting, pagination and filter text then work entirely
     * continues normally.
     */
    private function _search():void
    {
        // Parameters from POST
        $queryString = http_build_query($_POST);

        // Wrap safely for JavaScript
        // json_encode escapes < > ' " & as \uXXXX, so the string can safely
        // be inserted into the JavaScript below.
        $safeParams = json_encode($queryString, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        // The result is a JSON string WITH quotation marks ('"a=1&b=2"').
        // substr(..., 1) only cuts off the opening one - the closing one remains
        // and ends the JavaScript string literal below.
        $safeUri = 'Area/index.php?' . substr($safeParams, 1);

        // Reload the referer layer with these parameters
        $this->backend->script("
            cApp.layers.close();
            cApp.layers.replace('{$safeUri}');
        ");
    }


    // ====================
    // Sonstige Hilfsfunktionen

    /**
     * Instances (websites) as key-value pairs for the selection in the form, in the
     * order of the backend (Instance::orderSql(): the default instance last).
     */
    private function _getInstanceOptions(): array
    {
        $options = [];
        foreach ($this->_instanceOptions() as $instanceId => $label) {
            $options[$instanceId] = $this->cms->textOrSnippet((string)$label);
        }
        return $options;
    }

    /**
     * Selection list of the input fields of the area with certain types - for the
     * Anmeldefelder eines Profil-Bereichs (details.login_*_field_id).
     *
     * @param string[] $types  Feldtypen (`field`.`type`)
     * @return array  field_id => "name (Label)"
     */
    private function _getFieldOptions(array $types): array
    {
        $options = [];
        if ($this->id < 1 || empty($types)) return $options;
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $this->db->query(
            "SELECT `field_id`, `name`, `label` FROM `field`"
            . " WHERE `area_id` = ? AND `deleted` = 0 AND `type` IN ($placeholders) ORDER BY `position` ASC",
            'i' . str_repeat('s', count($types)),
            array_merge([$this->id], $types)
        );
        while ($row = $this->db->fetch()) {
            $label = strtr($this->cms->snippet('parenthetical_note'), [
                '{text}' => $this->cms->textOrSnippet($row['label'] ?? '')
            ]);
            $options[(int)$row['field_id']] = $row['name'] . ' ' . $label;
        }
        return $options;
    }

    /**
     * Selection list "parent area".
     *
     * Only areas of type 'folder' come into question - only folders may have children.
     * The indentation in the option text comes from `depth`, the order from
     * `lft` (nested set), so the list reads like the tree.
     *
     * @param int $areaId      id of an area to be excluded from the list
     *                         (one cannot select oneself as parent)
     * @param int $instanceId  only folders of this instance (0 = all) - an area may
     *                         only stand in the tree of its tenant (Area::hookOnSave())
     */
    private function _getParentOptions(int $areaId = 0, int $instanceId = 0): array
    {
        // Please select
        $options = [0 => $this->cms->snippet('nothing_selected')];

        // Ordner auflisten
        $sql = "SELECT `area_id`, `label`, `depth` FROM `area`"
            ." WHERE `type` = 'folder' AND `area_id` != ? AND `deleted`=0"
            . ($instanceId > 0 ? " AND `instance_id` = " . $instanceId : '')
            ." ORDER BY `lft` ASC";
        $this->db->query($sql, 'i', [$areaId]);
        while ($row = $this->db->fetch()) {
            $label = str_repeat(' ', $row['depth'] * 4) . $this->cms->textOrSnippet($row['label'] ?? '');
            $options[(int)$row['area_id']] = $label;
        }

        return $options;
    }

    /**
     * Selection list "preceding area" - the input aid for `position`.
     *
     * Contains all siblings under $parentId in their current order, before them
     * the entry 0 ("at the start").
     *
     * Root areas have `parent_id IS NULL` in the database, not 0 - therefore
     * $parentId === 0 gets its own WHERE branch. On the root level only
     * the areas of the same instance count as siblings (each tenant its own tree);
     * the `position` remains continuous table-wide across all roots.
     */
    private function _getPreviousOptions(int $parentId, int $areaId, int $instanceId = 0): array
    {
        // At the start
        $options = [0 => $this->cms->snippet('position_at_start')];

        // Restliche Optionen
        $sql = "SELECT `area_id`,`label` FROM `area` WHERE `area_id` != ? AND `deleted`=0";
        $bindTypes = 'i';
        $values = [$areaId];
        if ($parentId === 0) {
            $sql .= ' AND `parent_id` IS NULL';
            if ($instanceId > 0) $sql .= ' AND `instance_id` = ' . $instanceId;
        } else {
            $sql .= ' AND `parent_id`=?';
            $bindTypes .= 'i';
            $values[] = $parentId;
        }
        $sql .= ' ORDER BY `position` ASC';
        $this->db->query($sql, $bindTypes, $values);
        while ($row = $this->db->fetch()) {
            $options[(int)$row['area_id']] = $this->cms->textOrSnippet($row['label'] ?? '');
        }

        return $options;
    }

    /**
     * ids of the templates selectable in the area (rel_area_template.selectable = 1).
     *
     * Database::fetchAll() returns a flat list for a single-column query
     * of values (not an array of rows) - the result is therefore directly an id array.
     */
    private function selectableTemplateIds(int $areaId): array
    {
        $sql = "SELECT `template_id` FROM `rel_area_template` WHERE `area_id` = ? AND `selectable`=1";
        $this->db->query($sql, 'i', [$areaId]);
        return $this->db->fetchAll();
    }

    /**
     * ids of the templates that may be forced via URL
     * (rel_area_template.override = 1).
     *
     * This allows a call /{id},{template}, even if the record has a
     * another template. Return, as above, a flat id list.
     */
    private function overrideTemplateIds(int $areaId): array
    {
        $sql = "SELECT `template_id` FROM `rel_area_template` WHERE `area_id` = ? AND `override`=1";
        $this->db->query($sql, 'i', [$areaId]);
        return $this->db->fetchAll();
    }

    /**
     * List instances as options
     */
    private function _instanceOptions(): array
    {
        $this->db->query("SELECT `instance_id`, `label` FROM `instance` ORDER BY " . Instance::orderSql());
        return $this->db->fetchPairs() ?? [];
    }
}
