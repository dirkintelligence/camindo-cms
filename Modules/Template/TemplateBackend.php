<?php
/**
 * Backend interface for the frontend templates (table `template`).
 *
 * Entry points (scripts in backend/):
 *
 *     index.php                 -> folderList()       tree of the template areas
 *     template_list.php         -> list()             templates of an area
 *     template_info.php         -> info()             view the source code (tab 1)
 *     template_access.php       -> infoAccess()       assignments (tab 2)
 *     template_edit.php         -> edit()             create / change / delete
 *     template_move.php         -> move()             move to another area
 *     template_sync.php         -> TemplateFiles::sync()  refresh button of the lists
 *     template_search.php       -> search()           search mask and search execution
 *     template_global_search.php-> searchCrossArea()  intermediate layer of the global search
 *     ajax_lookup_preview.php   -> lookupPreview()    update lookup field
 *     ajax_slookup_preview.php  -> sLookupPreview()   likewise in the search mask
 *
 * TWO LEVELS: AREAS, THEN TEMPLATES
 * --------------------
 * Templates lie in content areas of type 'template'. The entry point is therefore
 * not the template list, but the area tree (folderList()); a click on it
 * opens the template list as a further layer on top. "New" in the tree accordingly
 * creates no record, but opens the form of the module Area.
 *
 * The session (expanded_folder_ids) remembers which folders are expanded - the
 * tree therefore looks after reloading just as it was left. Collapsed
 * branches are skipped via the nested set: from the collapsed folder on, everything
 * with lft < its rgt counts as a child (see $skipUntilRgt in printTree()).
 *
 * If tenants are set up, the tree gets a main folder per instance as in the module Record
 * (expanded_instance_ids) - see RecordBackendFolderList, whose
 * folderList() is rebuilt here verbatim; both modules share the
 * session columns, a tenant opened here is also open there.
 *
 * THE EDITOR DOES NOT COLOR ITSELF
 * --------------------
 * info() shows not `code`, but `styled_code` - that is the already colored HTML source
 * including line numbers, created by the parser when SAVING. Likewise
 * the error messages come from `error_text` and not from a new parser run.
 * In the form, the source code is edited in a plain textarea instead - it
 * comes from the file var/templates/{instance_id}/{bereich}/{name}.cms.php
 * (Template::hookOnLoaded()) and is written back there on saving; the
 * file leads, the column `code` serves only for searching (see TemplateFiles).
 *
 * ASSIGNMENTS (tab "Access")
 * --------------------
 * infoAccess() maintains in which content areas the template may be used -
 * per area three check boxes (default / selectable / override) plus the standalone flag.
 * The method is a small state machine of its own:
 *
 *     ''        load the state from the database
 *     update    take over the form state and add an area row
 *     save      write via Template::saveAccess()
 * All three end in 'form', which renders the table.
 *
 * LOOKUP MODE
 * --------------------
 * list() serves a double purpose: as a normal hit list and - if src_field is in the request -
 * as the selection window of a lookup field in another form (e.g.
 * "Default template" in the area form). Then each row gets a check box which
 * sets the link in the calling form via JavaScript. If exactly one not yet
 * linked hit remains, it is taken over directly and the layer closes
 * itself - this saves the additional click on an unambiguous search.
 *
 *     src_field   name of the lookup field in the calling form
 *     checked     already linked ids
 *
 * @see Camindo\Core\Modules\Template\Template            dataset/model, compiled when saving
 * @see Camindo\Core\Modules\Template\TemplateParserTrait  parser and coloring
 * @see Camindo\Core\Modules\Area\AreaBackend              uses the lookup methods here
 */
namespace Camindo\Core\Modules\Template;

use Camindo\Core\Backend;
use Camindo\Core\System;
use Camindo\Core\Session;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\History;
use Camindo\Core\InstanceCache;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Template\Template;

class TemplateBackend {
    // Refresh button of the lists: first take over the source files in var/templates into the
    // database (backend/template_sync.php -> TemplateFiles::sync()),
    // then reload the view
    // POST, because sync() writes; the CSRF token comes from
    // cApp.system.csrfHeaders() (developer docs, chapter 14 (Security: web))
    /**
     * Split the parser messages (`error_text`, separated by <br>) into errors and notes
     * ("Line n: Note: …", TemplateParserTrait::inlineHandlerWarnings()).
     *
     * @return array{0:string[],1:string[]}
     */
    private function _splitParserMessages(string $errorText): array
    {
        $messages = array_values(array_filter(array_map('trim', explode('<br>', $errorText))));
        $notes = array_values(array_filter($messages, fn($m) => str_contains($m, ': Note: ')));
        return [array_values(array_diff($messages, $notes)), $notes];
    }

    /** Maximum number of templates in the widget */
    private const WIDGET_MAX = 20;
    // Comparison operators of the command picker for parameters with 'operator'
    // (TemplateParserTrait::readOperator())
    private const COMMAND_OPERATORS = ['=', '==', '!=', '!!', '<', '>', '<=', '>=', '<<', '>>'];

    private const REFRESH_SCRIPT = "cApp.system.addSpinner();"
        . "$.ajax({type:'POST',url:cConfig.backend_path + 'Modules/Template/template_sync.php',"
        . "data:new FormData(),contentType:false,processData:false,"
        . "headers:cApp.system.csrfHeaders()})"
        . ".always(function(){cApp.layers.refresh();});";

    protected string $iconTemplate = '';

    // Can be a number OR a name: info() allows the call via the template name,
    // so that a reference from another template can jump there directly.
    protected string|int $id = 0;
    protected int $areaId = 0;
    protected string $srcField = '';  // lookup call
    protected array $checked = [];    // lookup call
    protected array $areaFilter = [];
    protected ?array $data = [];
    protected ?array $areaData = [];
    protected string $function = '';
    protected string $params = '';

    public function __construct(
        private Backend $backend,
        private System $system,
        private Session $session,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Area $area,
        private Template $template,
        private InstanceCache $instanceCache,
    )
    {
        $this->iconTemplate = $this->backend->template('template.icon_template');
    }

    /**
     * Widget for the start page (settings.php 'widget'): parser messages of the
     * templates (`template`.`error_text`) - important when templates are edited in the file system
     * (var/templates) and nobody sees the editor. Errors and
     * notes (script without CMS:NONCE, inline handler) per template, only areas
     * that the group may read; always with the note on CMS:NONCE.
     */
    public function widget(): string
    {
        $html = $this->backend->template('template.widget_nonce_note');
        $access = $this->backend->access();
        $this->db->query(
            "SELECT `t`.`template_id`, `t`.`area_id`, `t`.`name`, `t`.`error_text`, `a`.`name` AS `area_name`"
            . " FROM `template` `t` INNER JOIN `area` `a` ON `a`.`area_id` = `t`.`area_id` AND `a`.`deleted` = 0"
            . " WHERE `t`.`error_text` <> '' ORDER BY `a`.`name`, `t`.`name`"
        );
        $items = [];
        $more = 0;
        foreach ($this->db->fetchAll() as $row) {
            if (!$access->templateRead((int)$row['area_id'])) continue;
            if (count($items) >= self::WIDGET_MAX) {
                $more++;
                continue;
            }
            [$errors, $notes] = $this->_splitParserMessages((string)$row['error_text']);
            $messages = array_merge($errors, $notes);
            $items[] = $this->backend->template('template.widget_template_button', [
                '{id}' => (string)(int)$row['template_id'],
                '{area_id}' => (string)(int)$row['area_id'],
                '{label}' => htmlspecialchars($row['area_name'] . '.' . $row['name']),
                '{title}' => htmlspecialchars($messages[0] ?? ''),
                '{badge_class}' => $errors ? 'bg-danger' : 'bg-warning text-dark',
                '{number}' => (string)count($messages),
            ]);
        }
        if (!$items) {
            return $html . $this->backend->template('alert_success', ['{text}' => '{#template.widget_no_messages}']);
        }
        $list = '<li>' . implode("</li>\n<li>", $items) . '</li>';
        if ($more > 0) {
            $list .= $this->backend->template('template.widget_more', [
                '{text}' => $this->backend->escape(str_replace('{count}', (string)$more, $this->cms->snippet('template.widget_more'))),
            ]);
        }
        return $html . $this->backend->template('ul_unstyled', ['{items}' => $list]);
    }

    /**
     * Tree of the template areas (backend/index.php) - the entry point into the module.
     *
     * Shows folders and areas of type 'template' as a flat table with
     * pixel indentation by `depth`; the order is given by the nested set (lft).
     *
     * EXPAND AND COLLAPSE
     * The state is in the session (expanded_folder_ids, as JSON). A click calls
     * the same method with folder_id and toggle: the id is first removed from
     * the list in any case and added back when opening - this keeps it unique
     * without having to check beforehand whether it is already in there.
     *
     * Collapsed branches are not excluded from the query, but skipped
     * while iterating: $skipUntilRgt remembers the right limit of the
     * collapsed folder, and everything with a smaller lft lies in its subtree.
     * A single query is therefore enough for the whole tree.
     *
     * "New" does not create a template here, but an area - the button opens the
     * form of the module Area with the type preset.
     *
     * Request: folder_id, toggle (0|1) - both optional; for tenant main folders
     *          instance_id instead of folder_id (see class comment)
     */
    public function folderList()
    {
        // Get the ids of the open folders from the session
        $expandedFolderIds = $this->toggleExpanded('expanded_folder_ids', 'folder_id');
        // likewise for the tenant main folders
        $expandedInstanceIds = $this->toggleExpanded('expanded_instance_ids', 'instance_id');

        // Seitenelemente
        // --------------------

        // Breadcrumb navigation
        $this->backend->setNaviLabel('#template.module_label');
        
        // Container header
        echo $this->backend->template('container_header');
        echo $this->backend->template('row_start');
        echo $this->backend->template('col_start_50');

        // Linker Seitenbereich
        // --------------------

        // H1
        echo $this->backend->headline('#template.module_label');

        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Area/area_edit.php?function=create&data[type]=template&data[icon]=extension');");
        $this->backend->buttonAdd('button_search', "cApp.layers.add('Template/template_global_search.php');cApp.layers.add('Template/template_search.php?' + cApp.layers.queryString());");
        // Refresh: first reconcile var/templates (TemplateFiles::sync()), then reload
        $this->backend->buttonAdd('button_refresh', self::REFRESH_SCRIPT);
        echo $this->backend->buttonBar();

        // Folders and Areas
        // --------------------
        // Only the instances and readable template areas of the user group (null = all)
        $access = $this->backend->access();
        $instanceFilter = $access->instanceFilter();
        $areaFilter = $access->areaFilter(['template']);
        if ($this->instanceCache->hasMultipleInstances()) {
            // Tenants as main folders, below each the instance's tree
            foreach ($this->instanceCache->instanceLabels() as $instanceId => $label) {
                if ($instanceFilter !== null && !in_array((int)$instanceId, $instanceFilter, true)) continue;
                $open = in_array($instanceId, $expandedInstanceIds, true);
                echo $this->backend->template($open ? 'template.row_instance_open' : 'template.row_instance', [
                    '{instance_id}' => $instanceId,
                    '{label}' => $this->cms->textOrSnippet($label),
                    '{left}' => 20
                ]);
                if ($open) {
                    $this->area->loadAreas(['template'], $instanceId, areaIds:$areaFilter);
                    $this->printTree($expandedFolderIds, 1);
                }
            }
        }
        else {
            $this->area->loadAreas(areaTypes:['template'], areaIds:$areaFilter);
            $this->printTree($expandedFolderIds, 0);
        }

        echo $this->backend->template('col_end');
        echo $this->backend->template('col_start_50');

        // Rechter Seitenbereich
        // --------------------

        $this->_lastChangedTemplates();

        echo $this->backend->template('col_end');
        echo $this->backend->template('row_end');

        // Container Footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Note expanding resp. collapsing (request $requestKey + toggle) in the session and
     * return the list of open ids (as RecordBackendFolderList does).
     *
     * @param string $sessionKey  expanded_folder_ids | expanded_instance_ids (JSON)
     * @param string $requestKey  folder_id | instance_id
     * @return int[]
     */
    private function toggleExpanded(string $sessionKey, string $requestKey): array
    {
        $ids = array_map('intval', (array)json_decode((string)$this->session->get($sessionKey)));

        if (isset($_REQUEST[$requestKey])) {
            $id = (int)($_REQUEST[$requestKey] ?? 0);
            $toggle = (bool)(int)($_REQUEST['toggle'] ?? 0); // 1: open, 0: close
            // always remove first, append again when opening - this keeps
            // the id unique without having to check beforehand
            $ids = array_values(array_diff($ids, [$id]));
            if ($toggle) $ids[] = $id;
            $this->session->set($sessionKey, json_encode($ids, JSON_UNESCAPED_UNICODE) ?? '');
        }
        return $ids;
    }

    /**
     * Output the loaded result of Area::loadAreas() as tree rows.
     *
     * @param int[] $expandedFolderIds  open folders
     * @param int   $depthOffset        indentation: 1 under a tenant main folder
     */
    private function printTree(array $expandedFolderIds, int $depthOffset): void
    {
        $skipUntilRgt = null; // stores the right limit of the currently collapsed folder

        for ($i=0; $i < $this->db->num_rows; $i++) {
            $data = $this->db->fetch();
            
            // Check whether we are in the branch of a collapsed folder
            if ($skipUntilRgt !== null) {
                if ($data['lft'] < $skipUntilRgt) {
                    // Element is a child/descendant of the collapsed folder -> skip
                    continue;
                } else {
                    // The branch of the collapsed folder has been left
                    $skipUntilRgt = null;
                }
            }

            $label = $this->cms->textOrSnippet($data['label']);
            if ($data['type'] == 'folder') {
                $icon = 'folder';
                $folderId = (int)($data['area_id']);
                if (in_array($folderId, $expandedFolderIds, true)) {
                    // open folder
                    $tpl = 'template.row_folder_open';
                }
                else {
                    // collapsed folder
                    $tpl = 'template.row_folder';
                    // set the rgt value of the folder to skip all following children
                    $skipUntilRgt = $data['rgt'];
                }
            }
            else {
                $icon = $data['icon'];
                $tpl = 'template.row_area';
            }
            echo $this->backend->template($tpl, [
                '{area_id}' => $data['area_id'],
                '{icon}' => $icon,
                '{label}' => $label,
                '{left}' => ((int)$data['depth'] + $depthOffset) * 40 + 20
            ]);
        }
    }

    /**
     * Template list (backend/template_list.php).
     *
     * Two modes of operation (see class comment):
     *   - normal: list of the templates of an area
     *   - lookup: with src_field in the request it becomes a selection window for a
     *     lookup field in another form; each row then gets a
     *     check box whose onclick sets the link there.
     *
     * The area filter goes as a finished SQL fragment ('sql') into select() and not
     * as a normal filter - it should not appear in the filter text above the list, which
     * the editor reads as their search input. The area_id is cast to int
     * beforehand.
     *
     * Request: area_id | filter | src_field (at least one - otherwise the layer
     *          closes, instead of listing all templates), checked
     */
    public function list()
    {
        // If no filter, no area_id and no lookup field is given,
        // close the layer - instead of listing ALL records
        if (empty($_REQUEST['area_id']) && empty($_REQUEST['filter']) && empty($_REQUEST['src_field'])) {
            $this->backend->script('cApp.layers.close();');
            return;
        }

        // Container header
        echo $this->backend->template('container_header');

        $this->_resolveParams();

        // Rights of the user group: read right on the template area (developer docs, chapter 11 (Permissions))
        $access = $this->backend->access();
        if ($this->areaId > 0 && !$access->templateRead($this->areaId)) {
            echo $access->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $canWrite = ($this->areaId < 1) || $access->templateWrite($this->areaId);

        // Breadcrumb navigation
        if (!empty($this->areaId)) {
            $naviLabel = $this->cms->_strip_tags($this->cms->textOrSnippet($this->areaData['label']));
        }
        else {
            $naviLabel = '#record.headline_global_results';
        }
        $this->backend->setNaviLabel($naviLabel);
        
        // H1
        echo $this->backend->headline('#template.headline_template_list');
        
        // Buttons
        $this->backend->buttonBarReset();
        if (!empty($this->srcField)) {
            if ($canWrite) $this->backend->buttonAdd('button_create', "cApp.layers.add('Template/template_edit.php?{$this->params}&function=create&{$this->params}');");
            $this->backend->buttonAdd('button_search', "cApp.layers.add('Template/template_search.php?' + cApp.layers.queryString());");
            $this->backend->buttonAdd('button_refresh', self::REFRESH_SCRIPT);
            $this->backend->buttonAdd('record.button_apply', "cApp.layers.close();cApp.ui.lookupFocus('{$this->srcField}');");
        }
        else {
            if ($canWrite) $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Template/template_edit.php?function=create&{$this->params}');");
            $this->backend->buttonAdd('button_search', "cApp.layers.add('Template/template_search.php?' + cApp.layers.queryString());");
            $this->backend->buttonAdd('button_refresh', self::REFRESH_SCRIPT);
        }
        if (!empty($this->areaId) && $this->backend->access()->module('Area')) {
            // Settings of the area (only with access to the module Area)
            $this->backend->buttonAdd('button_settings', "cApp.layers.replace('Area/area_info.php?id={$this->areaId}&back=true');", alignRight:true);
        }
        echo $this->backend->buttonBar();

        // area filter (not in the filter text)
        // --------------------
        $sql = '';
        if (!empty($this->areaId)) {
            $sql = "`template`.`area_id` = {$this->areaId}";
        }

        // Liste
        // --------------------
        $f = $this->cms->select([
            'request' => 'all',
            'limit' => $this->cms->list_rows,
            'count' => 'true',
            'filtertext' => 'true',
            'sql' => $sql,
        ], $this->template);

        // Search terms or changed sorting as readable text
        echo $this->backend->filterText($f);

        if ($this->db->num_rows < 1) {
            // No results, no table
            echo $this->backend->template('container_footer');
            return;
        }

        // Pagination top
        echo $this->backend->paginationTop('template.listed_records');

        // Prepare Table-Head
        $schema = $this->template->getSchema();
        $thName = $this->backend->tableHeadColumn($schema['name'], $f, 'name');
        $thLabel = $this->backend->tableHeadColumn($schema['label'], $f, 'label');
        $thChangedAt = $this->backend->tableHeadColumn($schema['changed_at'], $f, 'changed_at');

        // Create Head columns
        if (!empty($this->srcField)) {
            $checkboxHeadColumn = $this->backend->template('template.template_table_checkbox_head_column');
        }
        else {
            $checkboxHeadColumn = '';
        }

        // Render table head
        $headColumns = $this->backend->template('template.template_table_head_columns', [
            '{checkbox_column}' => $checkboxHeadColumn,
            '{label_name}' => $thName,
            '{label_label}' => $thLabel,
            '{label_changed_at}' => $thChangedAt,
        ]);

        // Collect table rows
        $tplTableRow = $this->backend->template('template.template_table_row');
        $tableRows = '';
        
        // Fetch results (Dataset::fetch: with post-processing)
        $isLinked = false;
        while ($row = $this->template->fetch()) {
            // Neue Zeile
            $destId = (int)$row['template_id'];

            // If lookup call, then an additional first column
            if (!empty($this->srcField)) {

                $isLinked = in_array($destId, $this->checked, false);
                $clickHandler = "cApp.ui.slookupUpdate('{$this->srcField}',\$(this).prop('checked')?'link':'unlink',$destId);";

                $formCheck = $this->backend->template('list_checkbox', [
                    '{onclick}' => $clickHandler,
                    '{checked}' => $isLinked ? ' checked' : ''
                ]);

                // Checkbox in Spalte packen
                $checkboxColumn = $this->backend->template('table_column_noctx_lg', [
                    '{html}' => $formCheck
                ]);
            }
            else {
                $checkboxColumn = '';
            }

            // Row (context menu empty without write right on the area of the row)
            $rowWritable = $access->templateWrite((int)$row['area_id']);
            $tableRow = strtr($tplTableRow, [
                '{data_id}' => $row['template_id'],
                '{ctx_hide}' => $rowWritable ? '' : ' data-ctx-hide="edit,copy,delete"',
                '{checkbox_column}' => $checkboxColumn,
                '{data_name}' => $row['name'],
                '{data_label}' => $row['label'],
                '{data_changed_at}' => htmlspecialchars($this->cms->_formatDate($this->cms->utcToUserTZ((string)$row['changed_at']))),
            ]) ."\n";

            $tableRows .= $tableRow;
        }

        // Render table
        echo $this->backend->template('template.template_table_with_head', [
            '{head_columns}' => $headColumns,
            '{table_rows}' => $tableRows,
            '{params}' => $this->params ? "&{$this->params}" : ''
        ]);

        // Context menu - edit/copy/delete only with write right on the
        // area of the row (data-ctx-hide, backend-ctx-menu.js)
        echo $this->backend->template('template.template_context_menu');

        // Pagination bottom
        echo $this->backend->paginationBottom();

        // link immediately
        // --------------------
        // List opened from a lookup:
        // If exactly one record was found that is not yet linked,
        // then link it directly and close the layer

        if (!empty($this->srcField)
            && $this->db->num_rows_unlimited === 1
            && !$isLinked
        ) {
            $this->backend->script("
                cApp.ui.slookupUpdate('{$this->srcField}','link',$destId);
            ");
            // Container Footer
            echo $this->backend->template('container_footer');
            return;
        }

        // Container Footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Read in and prepare the recurring page parameters.
     *
     * Called right at the start by list(), info(), edit() and search() and fills
     * $id, $areaId, $srcField, $checked, $areaFilter, $areaData and $params.
     *
     * Two things are noteworthy here:
     *
     *  - $id is filtered only to [a-z0-9_.], not cast to int: info() also allows
     *    the reference "[area.]name" instead of the id.
     *  - $params is the collection of parameters that must be appended to every onward link
     *    (area and lookup context), so that the next
     *    layer knows where it comes from.
     *
     * If the area cannot be loaded, $this->function is set to 'errors' -
     * the caller must check this (see info()).
     */
    private function _resolveParams(): void
    {
        // Seitenparameter
        // --------------------
        // Id or reference "[area.]name" (see info())
        $this->id = preg_replace('/[^a-z0-9_.]+/i', '', (string)($_REQUEST['id'] ?? ''));
        $this->areaId = (int)($_REQUEST['data']['area_id'] ?? $_REQUEST['area_id'] ?? 0);

        // lookup calls:
        // Appears in JavaScript calls (lookupFocus/slookupUpdate) - field names only
        $this->srcField = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_REQUEST['src_field'] ?? '')));
        $this->checked = $this->system->sanitizeIds(
            explode(',', (string)($_REQUEST['checked'] ?? ''))
        );

        // $filter[area_ids][]
        $this->areaFilter = $this->system->sanitizeIds(
            (array)($_REQUEST['filter']['area_id'] ?? [])
        );
        $_REQUEST['filter']['area_id'] = $this->areaFilter;


        // Bereichslabel ermitteln
        // --------------------
        $this->areaData = [];
        if ($this->areaId > 0) {
            // One area -> load data
            if (!$this->areaData = $this->area->load($this->areaId)) {
                // (Fehlermeldungen in $this->cms->errors)
                $this->function = 'errors';
                return;
            }
        }
        else {
            $this->areaData = [
                'label' => $this->cms->snippet('template.headline_all_areas')
            ];
        }

        // Weiterzureichende Seitenparameter
        // --------------------
        if ($this->areaId > 0) {
            $this->params = 'area_id=' . $this->areaId;
        }
        if (!empty($this->srcField)) {
            $this->params .= '&src_field=' . $this->srcField;
            $this->params .= '&checked=' . implode(',', (array)($this->checked ?? []));
        }
    }

    /**
     * Render Info
     */
    /**
     * Quelltext eines Templates ansehen (backend/template_info.php, Reiter "Inhalt").
     *
     * Callable via the id OR a reference "[area.]name" (Cms::findTemplates())
     * - is_numeric() decides. A bare name that exists in several areas,
     * is searched first in the area of the referencing template (area_id from the link
     * in the styled_code, see TemplateParserTrait::styleCommandInner()); if it stays
     * ambiguous, there is an error message. If nothing is found under the name,
     * an error is not simply shown, but the query whether the template
     * should now be created: the normal case is a reference from another
     * template to a not yet existing one. kind=list (link from a parameter
     * marked 'list' in commands.php, e.g. resulttemplate) is passed on, so that
     * _editCreate() prefills the code with the result loop.
     *
     * `styled_code` is shown, not `code` - the coloring was created during
     * saving (see class comment). Before it stand the saved
     * parser messages from `error_text`.
     *
     * Preparing the styled_code is pure display: tabs to four
     * spaces, every second space to a non-breaking one (so that HTML does not
     * collapse the indentation), line breaks to <br>. The line numbers
     * next to it are a simple list - they match because the parser
     * keeps the line count.
     *
     * "Preview" only appears for standalone templates: only those can be called without
     * Datensatz aufrufen (/0,{name}).
     */
    public function info()
    {
        echo $this->backend->template('container_header');

        // Prefill and check afterwards: _resolveParams() sets 'errors' for an area that cannot be
        // loaded - the comparison below catches exactly that.
        $this->function = 'info';
        $this->_resolveParams();
        if ($this->function !== 'info') {
            $this->_editErrors();
            echo $this->backend->template('container_footer');
            return;
        }

        // Seitenelemente
        // --------------------

        // Breadcrumb navigation
        $this->backend->setNaviLabel('#button_info');

        // Container header
        echo $this->backend->template('container_header');

        // Load data
        if (!is_numeric($this->id)) {
            // Referenz "[bereich.]name" -> template_id
            $templates = $this->cms->findTemplates((string)$this->id);
            if (count($templates) > 1 && $this->areaId > 0) {
                // Prefer the area of the referencing template
                $inContext = array_values(array_filter(
                    $templates, fn($row) => (int)$row['area_id'] === $this->areaId
                ));
                if (count($inContext) === 1) $templates = $inContext;
            }
            if (count($templates) > 1) {
                echo $this->backend->template('alert_danger', [
                    '{text}' => str_replace('{name}', htmlspecialchars((string)$this->id), $this->cms->snippet('template.error_name_ambiguous'))
                ]);
                echo $this->backend->template('container_footer');
                return;
            }
            if (count($templates) < 1) {
                // Template not found.
                // -> query: should it be created now?
                $refParts = explode('.', (string)$this->id);
                $name = end($refParts);
                echo $this->backend->template('template.message_with_create_link', [
                    '{area_id}' => $this->areaId,
                    '{name}' => $name,
                    '{kind}' => ($_REQUEST['kind'] ?? '') === 'list' ? 'list' : ''
                ]);
                echo $this->backend->template('container_footer');
                return;
            }
            $this->id = (int)$templates[0]['template_id'];
        }
        if (!$this->data = $this->template->load((int)$this->id)) {
            $this->_editErrors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->id = (int)$this->data['template_id'];
        $this->areaId = (int)$this->data['area_id'];

        // Rights of the user group: read right on the template area
        $access = $this->backend->access();
        if (!$access->templateRead($this->areaId)) {
            echo $access->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $canWrite = $access->templateWrite($this->areaId);

        // H1
        echo $this->backend->headline("{$this->iconTemplate} {$this->data['name']}");
        
        // Tabs ("Changes" = audit log, Backend::auditTabAdd())
        $auditActive = empty($this->srcField) && $this->backend->auditTabActive();
        if (empty($this->srcField)) {
            $this->backend->tabBarReset();
            $this->backend->tabAdd('#template.headline_content', $auditActive ? "cApp.layers.replace('Template/template_info.php?id={$this->id}')" : '', !$auditActive);
            $this->backend->tabAdd('#template.headline_access', "cApp.layers.replace('Template/template_access.php?id={$this->id}')");
            $this->backend->auditTabAdd("Template/template_info.php?id={$this->id}");
            echo $this->backend->tabBar();
        }

        // buttons (change only with write right)
        $this->backend->buttonBarReset();
        if (empty($this->srcField)) {
            if ($canWrite) {
                $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Template/template_edit.php?id={$this->id}&{$this->params}');");
                $this->backend->buttonAdd('button_copy', "cApp.layers.replace('Template/template_edit.php?id={$this->id}&function=copy&{$this->params}');");
                $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Template/template_edit.php?id={$this->id}&function=delete&{$this->params}');");
                $this->backend->buttonAdd('button_move', "cApp.layers.replace('Template/template_move.php?id={$this->id}&{$this->params}');");
            }
            if ((int)$this->data['standalone'] > 0 && (string)($this->data['type'] ?? '') !== 'scheduler') {
                $this->backend->buttonAddPreview("0,{$this->data['name']}", (int)($this->data['instance_id'] ?? 0));
            }
        }
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        echo $this->backend->buttonBar();

        if ($auditActive) {
            echo $this->backend->auditHistory('template', (int)$this->id);
            echo $this->backend->template('container_footer');
            return;
        }

        // data fields
        // --------------------
        $areaLabel = $this->backend->teaser('area', $this->areaId);
        $this->backend->infoAdd($this->template, 'label', $this->data['label'], ['primary']);
        $this->backend->infoAdd($this->template, 'name', $this->data['name']);
        $this->backend->infoAdd($this->template, 'area_id', $areaLabel, ['html']);
        $this->backend->infoAdd($this->template, 'template_id', $this->data['template_id']);
        $this->backend->infoAdd($this->template, 'instance_id',
            $this->backend->teaser('instance', (int)$this->data['instance_id'])
            .' &middot; instance_id : ' . $this->data['instance_id'],
            ['html']
        );
        echo $this->backend->infoTable();

        // code
        // --------------------
        echo $this->backend->template('spacer');
        echo $this->backend->template('headline3', [
            '{text}' => '{#template.headline_code}'
        ]);

        // parsing errors
        // --------------------
        // errors red, notes (script policy, "Note:") yellow; escaped - the
        // Meldungen zitieren Quelltext (<script …>)
        if (!empty($this->data['error_text'])) {
            [$errors, $notes] = $this->_splitParserMessages((string)$this->data['error_text']);
            if ($errors) echo $this->backend->template('alert_danger', ['{text}' => implode('<br>', array_map('htmlspecialchars', $errors))]);
            if ($notes) echo $this->backend->template('alert_warning', ['{text}' => implode('<br>', array_map('htmlspecialchars', $notes))]);
        }

        // styled_code
        // --------------------
        $styledCode = $this->data['styled_code'] ?? '';
        // Multiple Spaces
        $styledCode = str_replace("\t",'    ', $styledCode);
        $styledCode = str_replace("  ","\u{00A0} ", $styledCode);
        // Linebreak
        $styledCode = $this->cms->_nl2br($styledCode);
        // Template links: handler with the nonce of this page (TemplateParserTrait::styleCommandInner()).
        // The raw <a … "> can only come from the parser, the template code itself is escaped.
        $styledCode = preg_replace_callback(
            '~<a href="#" data-c9-template-link="([0-9a-zA-Z_.]+),(\d+)(,list)?">~',
            fn($m) => '<a href="#"' . $this->backend->on('js', "cApp.layers.replace('Template/template_info.php?id="
                . $m[1] . '&area_id=' . (int)$m[2] . (!empty($m[3]) ? '&kind=list' : '') . "')") . '>',
            $styledCode
        );

        $lineCount = count(preg_split('/\R/', $styledCode));
        $lineNumbers = implode("<br>\n", range(1, $lineCount));

        echo $this->backend->template('template.template_code', [
            '{line_numbers}' => $lineNumbers,
            '{code}' => $styledCode
        ]);

        echo $this->backend->template('container_footer');
    }

    /**
     * Zuweisungen pflegen (backend/template_access.php, Reiter "Zugriff").
     *
     * Defines in which content areas the template may be used. Per
     * area one row with three check boxes:
     *
     *     default      default template of the area (at most one per area;
     *                  landet in area.default_template_id)
     *     selectable   selectable in the record
     *     override     per URL erzwingbar (/{id},{template})
     *
     * Plus the field `standalone`: the template may be called without a record.
     *
     * Small state machine via function (see class comment): '' loads the
     * saved state, 'update' takes over the form state and adds a row.
     * 'save' writes. Adding runs without its own button - the
     * selection list sends the form via onchange with function=update.
     *
     * Each row has a hidden field [nop]: a row in which ALL
     * check boxes are unticked would otherwise not be sent at all and would vanish on saving
     * instead of being kept as "nothing allowed".
     *
     * Both are written together in Template::saveAccess().
     */
    public function infoAccess()
    {
        echo $this->backend->template('container_header');

        $this->function = (string)($_REQUEST['function'] ?? '');
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->data = (array)($_REQUEST['data'] ?? []);

        // Seitenelemente
        // --------------------

        // Breadcrumb navigation
        $this->backend->setNaviLabel('#button_info');

        // Container header
        echo $this->backend->template('container_header');

        // Load data
        if (!$templateData = $this->template->load($this->id)) {
            // Template not found.
            echo $this->backend->template('alert_danger', [
                '{text}' => '{#template.error_template_not_found}'
            ]);
            echo $this->backend->template('container_footer');
            return;
        }

        $this->areaId = (int)$templateData['area_id'];

        // Rights of the user group: read the area; changing the assignments
        // only with write right (display stays read-only)
        $access = $this->backend->access();
        if (!$access->templateRead($this->areaId) || ($this->function !== '' && !$access->templateWrite($this->areaId))) {
            echo $access->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        if ($this->function === '') {
            $this->data['standalone'] = (int)$templateData['standalone'];
            $this->data['type'] = (string)$templateData['type'];
        }

        // H1
        echo $this->backend->headline("{$this->iconTemplate} {$templateData['name']}");
        
        // Tabs
        if (empty($this->srcField)) {
            $this->backend->tabBarReset();
            $this->backend->tabAdd('#template.headline_content', "cApp.layers.replace('Template/template_info.php?id={$this->id}')");
            $this->backend->tabAdd('#template.headline_access', '', true);
            $this->backend->auditTabAdd("Template/template_info.php?id={$this->id}");
            echo $this->backend->tabBar();
        }

        // Zugriff
        // --------------------
        echo $this->backend->template('spacer');
        echo $this->backend->template('headline3', [
            '{text}' => '{#template.headline_access}'
        ]);

        echo '<form action="Template/template_access.php" method="post">';

        $this->backend->formInit('template.access_form');

        // standalone
        // --------------------
        $this->backend->formField($this->template, [
            'name' => 'standalone',
            'value' => $this->data['standalone'],
            'id' => $this->id
        ]);

        // Usage: time-controlled (scheduler) - then never callable via URL
        // --------------------
        $this->backend->formField($this->template, [
            'name' => 'type',
            'value' => (string)($this->data['type'] ?? ''),
            'id' => $this->id,
            'options' => [
                '' => $this->cms->snippet('template.option_type_page'),
                'scheduler' => $this->cms->snippet('template.option_type_scheduler'),
            ],
        ]);

        // Template assignment to content areas
        // --------------------
        if ($this->function === '') {
            // Load the current state of the assignments
            $rows = $this->loadAreaAssignment($this->id);
    
            // Zuweisungen in $data umwandeln
            $this->data['access'] = [];
            foreach ($rows as $row) {
                $rowAreaId = (int)($row['area_id']);
                $isDefault = ((int)$row['template_id'] === (int)$row['default_template_id']);
                $this->data['access'][$rowAreaId] = [
                    'default' => (int)$isDefault,
                    'selectable' => (int)$row['selectable'],
                    'override' => (int)$row['override']
                ];
            }
            $this->function = 'form';
        }
        elseif ($this->function === 'update') {
            // Receive the assignments from the form
            $this->data['access'] = (array)($this->data['access'] ?? []);
            // Add row
            $addAreaId = (int)($this->data['add_area_id'] ?? 0);
            if (!empty($addAreaId)) {
                $this->data['access'][$addAreaId] = [
                    'default' => 0,
                    'selectable' => 0,
                    'override' => 0
                ];
            }
            $this->function = 'form';
        }
        elseif ($this->function === 'save') {
            // Zuweisungen schreiben
            if (!$this->_saveAccess()) {
                $this->_editErrors();
            };
            echo $this->backend->template('alert_success', [
                '{text}' => '{#template.message_data_saved}'
            ]);
            $this->function = 'form';
            $this->backend->balloon('success', $this->cms->snippet('template.message_data_saved'));

            // Umleiten auf Reiter "Content"
            $this->backend->script("cApp.layers.replace('Template/template_info.php?id={$this->id}');");
        }


        // Empty if body - without effect. All three branches above end in 'form',
        // so the table is built anyway in every case. Can be removed.
        if ($this->function === 'form') {}
        // Build the table with check boxes - without assignments (typical for standalone and
        // time-controlled templates) the form sends no data[access]
        $this->data['access'] = (array)($this->data['access'] ?? []);
        $tableRows = [];
        if (count($this->data['access']) > 0) {
            foreach($this->data['access'] as $rowAreaId => $rowData) {
                $defaultCheckbox = $this->_accessCheckbox($rowAreaId, 'default', (bool)($rowData['default'] ?? 0));
                $electableCheckbox = $this->_accessCheckbox($rowAreaId, 'selectable', (bool)($rowData['selectable'] ?? 0));
                $overrideCheckbox = $this->_accessCheckbox($rowAreaId, 'override', (bool)($rowData['override'] ?? 0));
                $areaLabel = $this->backend->teaser('area', $rowAreaId, linked:true);
                $tableRows[] = $this->backend->template('template.access_table_row', [
                    '{content_area}' => $areaLabel,
                    '{default}' => $defaultCheckbox,
                    '{selectable}' => $electableCheckbox,
                    '{override}' => $overrideCheckbox,
                ]);

                // Hidden field, ensures that area_id stays registered, even with missing check boxes
                $this->backend->formHidden("data[access][{$rowAreaId}][nop]", 1);
            }
            $table = $this->backend->template('template.access_table', [
                '{table_rows}' => implode("\n", $tableRows)
            ]);
        }
        else {
            $table = $this->backend->template('template.no_access_yet');
        }

        $this->backend->formReplace('{access_table}', $table);

        // Auswahl-Liste
        // --------------------
        $colDef = [
            'type' => 'id',
            'display_type' => 'select',
            'label' => '#template.button_add_area'
        ];
        $areaOptions = [];
        // Exclude the ids of the content areas already in use
        $excludedAreaIds = implode(',', $this->system->sanitizeIds(
            array_keys($this->data['access']), true
        ));
        if (!empty($excludedAreaIds)) $areaFilter = " AND `area_id` NOT IN ($excludedAreaIds)";
        else $areaFilter = '';
        // content areas only the readable ones of the group; folders (not selectable) remain
        $accessSql = $this->backend->areaAccessSql();
        if ($accessSql !== '') $areaFilter .= " AND (`type` = 'folder' OR (1=1" . $accessSql . "))";
        $this->db->query("SELECT `area_id`,`depth`,`label`,`type` FROM `area`"
            ." WHERE `type` != 'template' AND `deleted`=0" . $areaFilter
            ." ORDER BY `lft` ASC");
        while ($areaData = $this->db->fetch()) {
            $fullPath = str_repeat('&nbsp; ', (int)$areaData['depth'] * 2) . $this->cms->textOrSnippet($areaData['label']);
            if ($areaData['type'] === 'folder') {
                $relAreaId = 'disabled_' . $areaData['area_id'];
            }
            else {
                $relAreaId = (string)$areaData['area_id'];
            }
            $areaOptions[$relAreaId] = $fullPath;
        }
        $this->backend->formField($this->template, [
            'name' => 'add_area_id',
            'value' => $this->data['add_area_id'],
            'colDef' => $colDef,
            'options' => $areaOptions,
            'id' => $this->id,
            'attr' => ['onchange' => "if(this.value!=''){\$('.c9-layer:last input[name=\'function\']').val('update');\$('.c9-layer:last form').submit();}"],
            'selectPlease' => true
        ]);

        // hidden fields
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'save');

        // Formular rendern
        echo $this->backend->form('template.form_save_cancel', [
            '{template_id}' => $this->id
        ]);

        echo '</form>';
    }

    /**
     * Render a check box cell of the assignment table.
     *
     * The field name carries area and column in its name
     * (data[access][{area_id}][{column}]) - so the whole table comes back as
     * a nested array and can be passed unchanged to
     * Template::saveAccess() weitergeben.
     */
    private function _accessCheckbox(int $rowAreaId, string $column, bool $isChecked): string
    {
        return $this->backend->template('edit_form_checkbox', [
            '{name}' => "data[access][{$rowAreaId}][{$column}]",
            '{value}' => 1,
            '{checked}' => $isChecked ? 'checked' : '',
            '{id}' => ''
        ]);
    }

    /**
     * Helper function: update changed area-template assignments
     * as well as the standalone flag
     */
    private function _saveAccess(): bool
    {
        return $this->template->saveAccess((int)$this->id, $this->data['access'] ?? [], $this->data['standalone'] ?? 0, (string)($this->data['type'] ?? ''));
    }

    /**
     * Move a template to another template area (backend/template_move.php).
     *
     * Reachable via the button "Move" of the detail view - as with a
     * record (RecordBackendMove), only without field mapping: a template has no
     * area-dependent fields. One step: choose the target area and submit;
     * function=move moves via Template::moveToArea(). If that fails (e.g. because the name in the targetlbereich
     * is already taken - unique_in_area), the mask appears again with the errors.
     *
     * References of other templates via the bare name remain valid as long as the
     * name afterwards does not occur in several areas; otherwise they must also name the
     * area ("area.name", see Cms::templateFile()).
     *
     * Request: id, target_area_id (also as data[target_area_id]), function ('move')
     */
    public function move(): void
    {
        echo $this->backend->template('container_header');

        $this->function = 'info';
        $this->_resolveParams();
        if ($this->function !== 'info') {
            $this->_editErrors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->backend->setNaviLabel('#button_move');

        if (!$this->data = $this->template->load((int)$this->id)) {
            $this->_editErrors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->id = (int)$this->data['template_id'];
        $this->areaId = (int)$this->data['area_id'];
        $this->params = 'area_id=' . $this->areaId;

        // Rights of the user group: write right in the source area (target: Template::moveToArea())
        if (!$this->backend->access()->templateWrite($this->areaId)) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        echo $this->backend->template('headline1', [
            '{text}' => '{#template.headline_template_move}'
        ]);
        echo $this->backend->template('p', [
            '{text}' => $this->backend->teaser('template', $this->id)
        ]);

        $function = (string)($_REQUEST['function'] ?? '');
        $targetAreaId = (int)($_REQUEST['data']['target_area_id'] ?? $_REQUEST['target_area_id'] ?? 0);

        if ($function === 'move' && $targetAreaId > 0) {
            if ($this->template->moveToArea($this->id, $targetAreaId)) {
                $this->backend->balloon('success', $this->cms->snippet('template.message_template_moved'));
                $this->backend->script(
                    "cApp.layers.invalidate();"
                    ."cApp.layers.replace('Template/template_info.php?area_id={$targetAreaId}&id={$this->id}');"
                );
                echo $this->backend->template('container_footer');
                return;
            }
            $this->_editErrors();
        }

        // Choose the target area: all template areas except its own
        $areaOptions = $this->_areaOptions();
        unset($areaOptions[(string)$this->areaId]);

        echo '<form action="Template/template_move.php" method="post">';
        $this->backend->formInit('template.template_move_form');
        $this->backend->formField($this->template, [
            'name' => 'target_area_id',
            'value' => $targetAreaId,
            'id' => $this->id,
            'colDef' => [
                'type' => 'integer',
                'display_type' => 'select',
                'label' => '#template.headline_move_target'
            ],
            'options' => $areaOptions,
            'selectPlease' => true
        ]);
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'move');
        echo $this->backend->form('template.form_move_cancel', [
            '{id}' => $this->id,
            '{params}' => $this->params
        ]);
        echo '</form>';

        echo $this->backend->template('container_footer');
    }

    /**
     * Create / copy / change / delete (backend/template_edit.php).
     *
     * State machine via $_REQUEST['function'] as in the other modules
     * (developer docs, chapter 05 (Backend development)). Unlike there, the default value is set directly in the
     * access ('edit'), and the page parameters come from
     * _resolveParams() - among others the lookup context, which must be kept across the form.
     */
    public function edit(): void
    {
        // Seiten-Parameter
        $this->_resolveParams();
        $this->function = (string)($_REQUEST['function'] ?? 'edit');

        $this->data = (array) ($_REQUEST['data'] ?? []);

        // Breadcrumb navigation
        $this->backend->setNaviLabel('#button_' . $this->function);

        // Container header
        echo $this->backend->template('container_header');

        // Create
        if ($this->function == 'create') {
            $this->_editCreate();
        }

        // Load
        if (in_array($this->function, ['edit', 'copy', 'delete'], true)) {
            $this->_editLoad();
        }

        // Rights of the user group: write right on the template area (when
        // saving, the area of the form counts; Dataset::save() checks again)
        $accessAreaId = (int)($this->data['area_id'] ?? $this->areaId);
        if ($this->function !== 'skip' && $accessAreaId > 0 && !$this->backend->access()->templateWrite($accessAreaId)) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        // H1
        echo $this->_editHeadline();

        // Save
        if ($this->function == 'save') {
            $this->_editSave();
        }

        // Delete confirm
        if ($this->function == 'delete_confirm') {
            $this->_editDeleteConfirm();
        }

        // Errors
        if (in_array($this->function, ['errors','skip'], true)) {
            $this->_editErrors();
        }

        // Edit form
        if (in_array($this->function, ['create', 'edit', 'copy', 'errors'], true)) {
            $this->_editForm();
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
     * function=create: empty form with the schema defaults.
     *
     * $this->id may exceptionally be a NAME here: if the call comes from the
     * query "Template does not exist - create it now?" (see info()), the
     * searched name stands there. It is taken over as the prefill, after which the id is 0.
     */
    private function _editCreate(): void
    {
        $this->data = $this->template->init();
        // Specify the name in the URL?
        // see html snippet "template.message_with_create_link"
        $this->data['name'] = $this->id;
        $this->id = 0;
        // List template (kind=list, see info()): prefill the result loop
        if (($_REQUEST['kind'] ?? '') === 'list') {
            $this->data['code'] = "(CMS:RESULT_START)\n\n(CMS:RESULT_END)\n";
        }
    }

    /**
     * function=edit|copy|delete: Template laden.
     *
     * When copying only the id is discarded; the name initially stays the same and
     * runs into the unique_in_area check when saving - the editor must therefore
     * change it deliberately.
     */
    private function _editLoad(): void
    {
        $this->data = $this->template->load($this->id);
        if (!$this->data) {
            // Requested record not found
            $this->function = 'skip';
        }
        else {
            $this->areaId = (int)$this->data['area_id'];
        }
        // If data is to be copied, reset the id now
        if ($this->function == 'copy') {
            $this->id = 0;
        }
    }

    /**
     * Heading of the form. When deleting the template name, otherwise the general
     * heading.
     *
     * (The only one of the edit() helper methods that is public - deviating from the convention,
     * as in AdminBackend.)
     */
    private function _editHeadline() {
        if ($this->function == 'delete') {
            $label = $this->data['name'] ?? '';
        }
        else {
            $label = $this->cms->snippet('template.headline_template_info');
        }
        return $this->backend->template('headline1', [
            '{text}' => $this->iconTemplate . ' ' . $label,
        ]);
    }

    /**
     * function=save: speichern.
     *
     * Only what is in the list is written. `styled_code` and `error_text`
     * are included, although they have no input field - Template::hookOnSave()
     * fills them from the source code, and without an entry in the list the result would
     * not get into the table. The source code itself is written by Template::hookOnSaved()
     * into the file var/templates/... - it is overwritten, even if it was
     * changed by hand in the meantime (the form showed the state at the time of
     * opening).
     */
    private function _editSave(): void
    {
        // Check data and save
        $fields= [
            'area_id',
            'name',
            'label',
            'code',
            'styled_code',
            'error_text',
        ];
        $this->data['template_id'] = $this->id;
        if ($this->template->save($fields, $this->data)) {
            $this->id = (int)$this->data['template_id'];
            // List "Last changed" (folderList())
            $GLOBALS['app']->get(History::class)->touch('template', $this->id);
            // Balloon Message
            $msg = $this->cms->snippet('template.message_template_saved');
            $this->backend->balloon('success', $msg);
            if (!empty($this->srcField)) {
                // Referrer is a link list
                $this->backend->script("
                    cApp.layers.close();
                    cApp.ui.slookupUpdate('{$this->srcField}','link',$this->id);
                ");
            }
            else {
                // Update the referer (list) and redirect to the info page
                $this->backend->script("
                    cApp.layers.invalidate();
                    cApp.layers.replace('Template/template_info.php?id={$this->id}');
                ");
            }
        }
        else {
            $this->function = 'errors';
        }
    }

    /**
     * function=delete_confirm: delete.
     *
     * The compiled file var/php/{area_id}/{name}.php is removed
     * Template::hookOnDelete().
     */
    private function _editDeleteConfirm(): void
    {
        if ($this->template->delete($this->id)) {
            // Balloon Message
            $msg = $this->cms->snippet('template.message_template_deleted');
            $this->backend->balloon('success', $msg);
            
            // Close window
            $this->backend->script("
                cApp.layers.invalidate();
                cApp.layers.close();
            ");
        }
        else {
            $this->function = 'errors';
        }
    }

    /**
     * Selection list of all CMS commands (core and modules, alphabetical) for the
     * input mask. Choosing one opens command() as a new layer; the cursor position
     * in the code field is remembered by cApp.templateCommands (public/template.js).
     */
    private function _commandPicker(): string
    {
        $options = [];
        foreach (array_keys($this->template->commandDefinitions()) as $name) {
            $options[$name] = 'CMS:' . strtoupper($name);
        }
        return $this->backend->template('template.command_picker', [
            '{options}' => $this->backend->options($options),
        ]);
    }

    /**
     * Layer of the command picker: all parameters of one command (?cmd=select).
     *
     * One row per parameter: name (tooltip: description from commands.php),
     * one input per sub-key level ('subparam'), the comparison operator (a
     * selection only for parameters with 'operator', otherwise a fixed '='), the value.
     * Parameters with sub-keys may appear several times (filter[a]=… filter[b]=…) -
     * "+" below them adds another row. "Insert" assembles the command in the
     * browser and inserts it at the cursor position of the code field
     * (cApp.templateCommands.insert()); nothing is sent to the server.
     */
    public function command(): void
    {
        $name = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string)($_REQUEST['cmd'] ?? '')));
        $commands = $this->template->commandDefinitions();
        $upper = 'CMS:' . strtoupper($name);

        $this->backend->setNaviLabel($upper);
        echo $this->backend->template('container_header');

        if (!isset($commands[$name])) {
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->backend->escape(str_replace('{name}', $upper, $this->cms->snippet('template.command_unknown')))
            ]);
            echo $this->backend->template('container_footer');
            return;
        }
        $def = $commands[$name];

        echo $this->backend->headline("{$this->iconTemplate} " . $upper);

        // Texts from commands.php: '#prefix.key' = snippet, otherwise literal - escaped on output
        $text = fn($value) => $this->template->commandText($value);

        $rows = '';
        $n = 0;
        foreach ((array)($def['params'] ?? []) as $param => $pdef) {
            $n++;
            $uid = 'c9-cmd-' . $n;
            $depth = max(0, (int)($pdef['subparam'] ?? 0));

            // Tooltip (HTML): description and default
            $tooltip = [];
            if (($description = $text($pdef['description'] ?? '')) !== '') {
                $tooltip[] = htmlspecialchars($description);
            }
            if (($default = $text($pdef['default'] ?? '')) !== '') {
                $tooltip[] = htmlspecialchars(str_replace('{default}', $default, $this->cms->snippet('template.command_default')));
            }

            $subs = '';
            for ($i = 1; $i <= $depth; $i++) {
                $subs .= $this->backend->template('template.command_param_sub', [
                    '{param}' => $this->backend->escape($param),
                    '{level}' => $i,
                ]);
            }

            if (!empty($pdef['operator'])) {
                $operator = $this->backend->template('template.command_param_operator', [
                    '{options}' => $this->backend->options(array_combine(self::COMMAND_OPERATORS, self::COMMAND_OPERATORS), '='),
                ]);
            }
            else {
                $operator = $this->backend->template('template.command_param_equals');
            }

            $rows .= $this->backend->template('template.command_param_row', [
                '{id}' => $uid,
                '{param}' => $this->backend->escape($param),
                '{mandatory}' => !empty($pdef['mandatory']) ? $this->backend->template('mandatory_field_indicator') : '',
                '{tooltip}' => $tooltip ? $this->backend->template('template.command_param_tooltip', [
                    // escaped once more: the attribute value is read as HTML (data-bs-html)
                    '{tooltip}' => $this->backend->escape(implode('<br>', $tooltip)),
                ]) : '',
                '{subs}' => $subs,
                '{operator}' => $operator,
                '{placeholder}' => $this->backend->escape($text($pdef['value'] ?? '')),
                '{add}' => $depth > 0 ? $this->backend->template('template.command_param_add', [
                    '{param}' => $this->backend->escape($param),
                ]) : '',
            ]);
        }
        if ($rows === '') {
            $rows = $this->backend->template('template.command_no_params');
        }

        echo $this->backend->template('template.command_form', [
            '{name}' => $this->backend->escape($name),
            '{note}' => $this->backend->escape($text($def['note'] ?? '')),
            '{rows}' => $rows,
        ]);

        echo $this->backend->template('container_footer');
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
     * Four fields: area (selection among the template areas), name, label
     * and the source code as a growing textarea - the source code comes from the
     * file in var/templates (Template::hookOnLoaded()), not from the column `code`.
     * It is compiled when saving in Template::hookOnSave() - nothing happens here
     * dergleichen.
     *
     * src_field and checked travel along as hidden fields, so that a template created from a
     * lookup field can then be linked there afterwards.
     *
     * (The call to _initTextareaGrow() appears twice here - once is enough.)
     */
    private function _editForm(): void
    {
        if ($this->function == 'save') $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Template/template_edit.php" method="post"' . $changed . '>';

        $this->backend->formInit('template.template_edit_form');

        // label
        $this->backend->formField($this->template, [
            'name' => 'label',
            'value' => $this->data['label'] ?? '',
            'id' => $this->id,
            'primary' => true
        ]);

        // name
        $this->backend->formField($this->template, [
            'name' => 'name',
            'value' => $this->data['name'] ?? '',
            'id' => $this->id
        ]);

        // area_id
        $areaOptions = $this->_areaOptions();
        $this->backend->formField($this->template, [
            'name' => 'area_id',
            'value' => $this->areaId,
            'display_type' => 'select',
            'options' => $areaOptions,
            'id' => $this->id
        ]);

        $this->backend->formField($this->template, [
            'name' => 'code',
            'value' => $this->data['code'] ?? '',
            'id' => $this->id,
            'template' => 'template.edit_form_code_growing',
            'fullWidth' => true,
            // right-aligned on the height of the label: command picker (command())
            'labelPrefix' => $this->_commandPicker()
        ]);
        
        // JavaScript statement for growing textarea, after Ajax load
        $this->backend->script("cApp.ui._initTextareaGrow(\$('.c9-layer:last'));");

        // hidden fields
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $this->id);

        // form called via lookup field?
        $this->backend->formHidden('src_field', $this->srcField);
        $this->backend->formHidden('checked', implode(',',(array)($this->checked ?? [])));

        // Formular rendern
        echo $this->backend->form('form_save_cancel_note');

        // Textarea initialisieren
        $this->backend->script("cApp.ui._initTextareaGrow(\$('.c9-layer:last'));");
        
        echo '</form>';
    }

    /**
     * Really delete? Form
     */
    private function _editDeleteForm(): void
    {
        echo '<form action="Template/template_edit.php" method="post">';

        $this->backend->formInit('template.template_delete_form');

        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');
        // form called via lookup field?
        $this->backend->formHidden('src_field', $this->srcField);
        $this->backend->formHidden('checked', implode(',',(array)($this->checked ?? [])));

        // Print form
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }


    // ====================
    // Suchfunktion


    /**
     * Search results
     */
    public function search():void
    {
        $this->_resolveParams();
        $function = (string)($_POST['function'] ?? '');

        if ($function === 'search') {
            $this->_search();
        }

        if ($function === '') {
            $this->_searchForm();
        }
    }

    /**
     * function=search: run the search.
     *
     * As in the other modules: no own hit list, but close the search layer
     * and reload the list layer with the search parameters in the URL.
     * json_encode escapes everything that could terminate the JavaScript literal;
     * substr(..., 1) only removes the opening quotation mark, the closing one
     * ends the string below.
     */
    private function _search(): void
    {
        // Parameters from POST
        $queryString = http_build_query($_POST);

        // Wrap safely for JavaScript
        $safeParams = json_encode($queryString, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $safeUri = '"Template/template_list.php?' . substr($safeParams, 1);

        // Reload the referer layer with these parameters
        $this->backend->script("
            cApp.layers.close();
            cApp.layers.replace({$safeUri});
        ");
    }

    /**
     * Empty intermediate layer for the cross-area search.
     *
     * The layers stack up: area tree, with the hit list above it. A search across
     * all areas, however, has no area from which it would be opened - without
     * this placeholder the hit list would lie directly on the tree and would take it along
     * when closing. The layer therefore contains nothing except a
     * close button.
     */
    public function searchCrossArea():void
    {
        $this->backend->setNaviLabel('#record.headline_global_results');

        echo $this->backend->template('container_header');
        echo $this->backend->template('button_close', [
            '{onclick}' => 'cApp.layers.close();'
        ]);
        echo $this->backend->template('container_footer');
    }

    /**
     * Search form
     */
    private function _searchForm():void
    {
        // Seiten-Parameter
        $filter = (array) ($_REQUEST['filter'] ?? []);

        echo '<form action="Template/template_search.php" method="post">';

        // Breadcrumb navigation
        $this->backend->setNaviLabel('#button_search');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#template.headline_search');

        $this->backend->formInit('template.template_search_form');


        // label, name
        $this->backend->searchField($this->template, ['name' => 'label', 'value' => $filter['label'] ?? '']);
        $this->backend->searchField($this->template, ['name' => 'name', 'value' => $filter['name'] ?? '']);

        // area_id
        if (empty($this->areaId)) {

            $areaOptions = $this->_areaOptions();
            $areaColDef = [
                'type' => 'json', // Mehrfachauswahl fester Werte erlauben
                'display_type' => 'multiselect',
                'label' => '#template.template_area'
            ];

            $this->backend->searchField($this->template, [
                'name' => 'area_id',
                'value' => $this->areaFilter,
                'colDef' => $areaColDef,
                'options' => $areaOptions
            ]);
        }
        else {
            echo $this->backend->template('edit_form_static', [
                '{label}' => '{#template.template_area}',
                '{html}' => $this->cms->textOrSnippet($this->areaData['label']),
            ]);
        }

        // weitere Felder
        $this->backend->searchField($this->template, ['name' => 'template_id', 'value' => $filter['template_id'] ?? '']);
        $this->backend->searchField($this->template, ['name' => 'code', 'value' => $filter['code'] ?? '']);

        // Sort, Order
        $sortOptions = [            
            'name' => $this->cms->snippet('template.field_name'),
            'label' => $this->cms->snippet('template.field_label'),
            'created_at' => $this->cms->snippet('template.field_created_at'),
            'changed_at' => $this->cms->snippet('template.field_changed_at'),
            'standalone' => $this->cms->snippet('template.field_standalone'),
            'area_id' => $this->cms->snippet('area_id'),
            'template_id' => 'template_id'
        ];
        $this->backend->searchFieldSortOrder($sortOptions);

        // Hidden fields
        $this->backend->formHidden('function', 'search');
        $this->backend->formHidden('area_id', $this->areaId);
        // form called via lookup field?
        $this->backend->formHidden('src_field', $this->srcField);
        $this->backend->formHidden('checked', implode(',',(array)($this->checked ?? [])));

        // Print form
        echo $this->backend->form('form_search_cancel', removePlaceholders:true);

        // Container footer
        echo $this->backend->template('container_footer');

        echo '</form>';
    }

    /**
     * Collect selectable template areas for the option list
     */
    /**
     * selection list of the template areas.
     *
     * Only areas of type 'template' - no templates can be placed in other ones.
     * The indentation comes from `depth`, the order from `lft`; unlike in
     * FieldBackend, folders are not included here at all.
     */
    private function _areaOptions(): array
    {
        $this->db->query("SELECT `area_id`, `depth`, `label` FROM `area`"
            ." WHERE `type` = 'template' AND `deleted`=0" . $this->backend->areaAccessSql(writable:true)
            ." ORDER BY `lft` ASC");
        $areaOptions = [];
        while ($areaData = $this->db->fetch()) {
            $fullPath = str_repeat('&nbsp; ', (int)$areaData['depth'] * 2) . $this->cms->textOrSnippet($areaData['label']);
            $areaId = (string)$areaData['area_id'];
            $areaOptions[$areaId] = $fullPath;
        }
        return $areaOptions;
    }

    // ====================
    // LookupField with template contents

    /**
     * Ajax: update the preview of a lookup field in the INPUT FORM.
     *
     * Called by cApp.ui.lookupRUpdate() after the editor has set or removed a check mark in the
     * selection list. Responds with the finished
     * preview block, which the JavaScript places in the position of the old one.
     *
     * The only difference to sLookupPreview() is the form prefix: here 'data'
     * (input form), there 'filter' (search mask). The names of the
     * erzeugten versteckten Felder.
     *
     * Request: src_field (required), ids (comma list), max, function, ordered (up/down buttons)
     */
    public function lookupPreview(): void
    {
        // parameters passed through from cApp.ui.slookupUpdate()
        $function = (string)($_REQUEST['function'] ?? '');
        $srcField = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_REQUEST['src_field'] ?? '')));
        $max = (int)($_REQUEST['max'] ?? 0);
        if (empty($srcField)) {
            $this->cms->error('lookupPreview() Exception: parameter src_field is empty.');
            $this->_editErrors();
            return;
        }
        $idString = preg_replace('/[^0-9,]/', '', (string)($_REQUEST['ids'] ?? ''));
        $ids = explode(',', $idString);
        $ordered = !empty($_REQUEST['ordered']);
        echo $this->lookupPreviewCreate($srcField, $ids, 'data', $max, $ordered);
        if ($function == 'link') {
            $this->backend->script(
                "cApp.ui.lookupFocus('$srcField', true);"
            );
        }
    }

    /**
     * Ajax: update the preview of a lookup field in the SEARCH MASK.
     *
     * Like lookupPreview(), only with the form prefix 'filter' - the selection here is
     * a search criterion and not a link to be saved. Accordingly there is
     * also no resetting of the input focus.
     *
     * Request: src_field (Pflicht), ids (Kommaliste), max
     */
    public function sLookupPreview(): void
    {
        $srcField = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_REQUEST['src_field'] ?? '')));
        $max = (int)($_REQUEST['max'] ?? 0);
        if (empty($srcField)) {
            $this->cms->error('sLookupPreview() Exception: parameter src_field is empty.');
            $this->_editErrors();
            return;
        }
        $idString = preg_replace('/[^0-9,]/', '', (string)($_REQUEST['ids'] ?? ''));
        $ids = explode(',', $idString);
        echo $this->lookupPreviewCreate($srcField, $ids, 'filter', $max);
    }

    /**
     * Render the preview block of a lookup field.
     *
     * Common core of lookupPreview() and sLookupPreview(); additionally called directly
     * when a form is built for the first time (e.g. from
     * AreaBackend::_editForm()).
     *
     * Each selected template produces a row with its name, a remove link and
     * a hidden field that carries the id - the preview block IS therefore the
     * input field; there is no visible text field.
     *
     * The order of the rows follows the passed id list, not the
     * sorting of the database: the editor set it up that way. For this the
     * original order is turned into a rank with array_flip() and then sorted with uksort().
     * With max == 1 this is skipped - there is only one row there.
     *
     * $ordered: the order has a meaning (trigger templates of an area
     * run one after another) - each row gets up/down buttons that swap two
     * ids in the list (cApp.ui.slookupSwap()).
     *
     * @param string $srcField  name of the lookup field
     * @param array $ids        ids stored in the field
     * @param string $prefix    name of the form field ('data'/'filter')
     * @param int $max          parameter $max passed through from JavaScript (not binding!)
     * @param bool $ordered     up/down buttons
     */
    public function lookupPreviewCreate(string $srcField, array $ids, string $prefix, int $max, bool $ordered = false): string
    {
        if (empty($srcField)) return '';
        $ids = $this->system->sanitizeIds($ids, true);
        if (empty($ids)) return '';

        // Load records - all selected: what is missing here is missing after the
        // saving in the assignment as well (the preview carries the hidden fields)
        $this->template->select([
            'filter' => [
                'template_id' => $ids
            ],
            'limit' => count($ids)
        ]);
        if ($this->db->num_rows < 1) return '';
        
        $records = [];
        while ($row = $this->template->fetch()) {
            // Id in the key
            $records[(int)$row['template_id']] = $row;

            if ($max == 1) break;
        }

        if ($max != 1) {
            // Sort results by original id order
            $flippedOrder = array_flip($ids); 
            uksort($records, function($a, $b) use ($flippedOrder) {
                return (int)$flippedOrder[$a] <=> (int)$flippedOrder[$b];
            });
        }
        
        // Hidden field with the stored id
        $varName = "{$prefix}[$srcField]";
        // Mehrfachauswahl
        if ($max != 1) $varName .= '[]';

        $rows = [];
        $orderedIds = array_keys($records);
        foreach ($orderedIds as $index => $id) {
            $data = $records[$id];
            $buttons = '';
            if ($ordered) {
                // swap partners below / above; invisible at the edge (columns stay aligned)
                $nextId = (int)($orderedIds[$index + 1] ?? 0);
                $previousId = (int)($orderedIds[$index - 1] ?? 0);
                $buttons = $this->backend->template('record.lookup_button_down', [
                    '{down_url}' => "cApp.ui.slookupSwap($id, $nextId);",
                    '{class}' => $nextId > 0 ? '' : ' invisible',
                ]) . $this->backend->template('record.lookup_button_up', [
                    '{up_url}' => "cApp.ui.slookupSwap($id, $previousId);",
                    '{class}' => $previousId > 0 ? '' : ' invisible',
                ]);
            }
            $rows[] = $this->backend->template($ordered ? 'template.lookup_table_ordered_row' : 'template.lookup_table_row', [
                '{data_id}' => $id,
                '{text}' => $data['name'],
                '{name}' => $srcField,
                '{buttons}' => $buttons,
                '{unlink_url}' => "cApp.ui.slookupUnlink($id);",
                '{var_name}' => $varName
            ]);
            // (There stood a popResult() without a matching pushResult(): it fetched the
            //  result state of the CALLER from the stack and made it disappear there.
            //  The loop runs over the already collected $records array and
            //  itself issues no query - there is nothing to restore here.)
        }

        // render table
        $table = $this->backend->template('template.lookup_table', [
            '{table_rows}' => implode("\n", $rows)
        ]);

        // render preview container
        $previewContainer = $this->backend->template('record.lookup_preview_container', [
            '{html}' => $table
        ]);

        return $previewContainer;
    }

    /**
     * Load the assignments from the view of ONE AREA - which templates belong to it.
     *
     * For the overview table in AreaBackend::info(). LEFT JOIN, so that an
     * orphaned row in rel_area_template does not fall through (there
     * name and label would then stay empty).
     */
    public function loadTemplateAssignment(int $areaId):array
    {
        $sql = "SELECT `template`.`name`, `template`.`label`, `r`.* FROM `rel_area_template` `r`"
        ." LEFT JOIN `template` ON `r`.`template_id` = `template`.`template_id`"
        ." WHERE `r`.`area_id` = ?"
        ." ORDER BY `template`.`name` ASC";
        $this->db->query($sql, 'i', [$areaId]);
        return $this->db->fetchAll();
    }

    /**
     * Load the assignments from the view of ONE TEMPLATE - in which areas it is
     * benutzbar.
     *
     * The reverse direction of loadTemplateAssignment(), needed by infoAccess().
     * `default_template_id` of the area comes along, because "is default template"
     * can only be read from it - rel_area_template has no column for it.
     */
    public function loadAreaAssignment(int $templateId):array
    {
        $sql = "SELECT `area`.`label`, `area`.`default_template_id`, `r`.* FROM `rel_area_template` `r`"
        ." LEFT JOIN `area` ON `r`.`area_id` = `area`.`area_id`"
        ." WHERE `r`.`template_id` = ?"
        ." ORDER BY `area`.`lft` ASC";
        $this->db->query($sql, 'i', [$templateId]);
        return $this->db->fetchAll();
    }

    /**
     * List the templates last changed by the session admin (right column of
     * folderList()).
     *
     * The ids come from `history` (Camindo\Core\History), newest first; per
     * template name, label and area one below the other - the columns of the
     * template list plus the area, because the entries come from different areas.
     * Templates whose area the user group may no longer read
     * are dropped. A click opens the detail view.
     */
    private function _lastChangedTemplates(): void
    {
        $entries = $GLOBALS['app']->get(History::class)->entries('template');
        if (empty($entries)) return;

        $this->db->pushResult();
        $this->cms->select([
            'filter' => ['template_id' => array_keys($entries)],
            'limit'  => count($entries)
        ], $this->template);
        $templates = [];
        while ($row = $this->template->fetch()) {
            $templates[(int)$row['template_id']] = $row;
        }
        $this->db->popResult();

        $access = $this->backend->access();
        $rows = '';
        foreach ($entries as $templateId => $changedAt) {
            $row = $templates[$templateId] ?? null;
            if ($row === null || !$access->templateRead((int)$row['area_id'])) continue;

            $items = [$this->iconTemplate . ' <strong>' . $row['name'] . '</strong>'];
            $label = $this->cms->textOrSnippet((string)$row['label']);
            if ($label !== '') $items[] = $label;
            $areaLabel = $this->cms->textOrSnippet((string)($this->backend->areaData((int)$row['area_id'])['label'] ?? ''));
            if ($areaLabel !== '') $items[] = $areaLabel;

            $text = '';
            foreach ($items as $i => $item) {
                $text .= ($i === 0)
                    ? '<div>' . $item . '</div>'
                    : $this->backend->template('record.lookup_table_row_div', ['{text}' => $item]);
            }
            $rows .= $this->backend->template('template.last_changed_row', [
                '{data_id}'    => $templateId,
                '{text}'       => $text,
                '{changed_at}' => $this->cms->_formatDate($this->cms->utcToUserTZ($changedAt)),
            ]) . "\n";
        }
        if ($rows === '') return;

        echo $this->backend->template('template.last_changed_table', [
            '{table_rows}' => $rows
        ]);
    }
}