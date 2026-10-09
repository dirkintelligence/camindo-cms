<?php
/**
 * Tree of the content areas - the entry point into the module Record.
 *
 * Entry point: backend/index.php -> folderList()
 *
 * Records always live in a content area. The entry point is therefore not the
 * record list, but the area tree; a click on it puts the list as
 * a further layer on top (RecordBackendList).
 *
 * INSTANCES AS MAIN FOLDERS
 * --------------------
 * If instances are set up (more than the default instance,
 * InstanceCache::hasMultipleInstances()), the tree gets one level more: for each
 * instance a main folder with its label, below it its own area tree
 * (Area::loadAreas() with instance filter). The main folders look like folder
 * areas and also behave like them - expandable and collapsible; the state lies in
 * the admin session (expanded_instance_ids, separate from expanded_folder_ids, because
 * instance and area ids overlap). With only one instance the
 * tree remains as before.
 *
 * LAST CHANGED (right column)
 * --------------------
 * Next to the tree are the ten records that the logged-in admin last saved in the
 * input mask (Camindo\Core\History, table `history`), most recent
 * first - in the detailed display of the lookup preview
 * (RecordBackend::detailedText()). For this the class inherits from RecordBackend; the tree
 * itself is not affected by this, it knows neither records nor lookup calls.
 *
 * ALMOST IDENTICAL TO TemplateBackend::folderList()
 * --------------------
 * Both show the same tree, only with a different area type ('record' instead of 'template')
 * and different HTML templates. They even share the SESSION KEY
 * expanded_folder_ids - a folder expanded here is also found expanded
 * in the module Template. With separate area trees this hardly matters, but a
 * separate key per module would be cleaner.
 *
 * @see Camindo\Core\Modules\Record\RecordBackendList     the list behind it
 * @see Camindo\Core\Modules\Template\TemplateBackend     the same display for templates
 * @see Camindo\Core\Modules\Area\Area::loadAreas()       supplies the rows
 * @see Camindo\Core\History                             last changed
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\History;
use Camindo\Core\Modules\Area\Area;

class RecordBackendFolderList extends RecordBackend {

    /**
     * Output the area tree (backend/index.php).
     *
     * Shows folders and areas of type 'record' as a flat table with
     * pixel indentation by `depth`; the order comes from the nested set (lft).
     * Area::loadAreas() omits folders that contain no area at all -
     * empty branches therefore do not appear.
     *
     * EXPAND AND COLLAPSE
     * The state is kept in the session (expanded_folder_ids, as JSON). A click calls
     * the same method with folder_id and toggle: the id is always first removed from
     * the list and added again when opening - this keeps it unique,
     * without having to check beforehand whether it is already included.
     *
     * Collapsed branches are not excluded from the query, but skipped
     * while iterating: $skipUntilRgt remembers the right boundary of the
     * collapsed folder; everything with a smaller lft lies in its subtree. A single
     * query is thus enough for the whole tree.
     *
     * Unlike in the module Template, there is no "New" button here - areas are
     * created in the module Area.
     *
     * Request: folder_id, toggle (0|1) - both optional; for instance main folders
     *          instance_id instead of folder_id (see class comment)
     */
    public function folderList()
    {
        // Get the ids of the open folders from the session
        $expandedFolderIds = $this->toggleExpanded('expanded_folder_ids', 'folder_id');
        // ditto for the instance main folders
        $expandedInstanceIds = $this->toggleExpanded('expanded_instance_ids', 'instance_id');

        // Page elements
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#record.module_label');

        // Container header
        echo $this->backend->template('container_header');

        // Left page area
        // --------------------
        echo $this->backend->template('row_start');
        echo $this->backend->template('col_start_50');

        // H1
        echo $this->backend->headline('#record.headline_data');

        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_search', "cApp.layers.add('Record/record_global_search.php');cApp.layers.add('Record/record_search.php?' + cApp.layers.queryString());");
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        // Menu on the right: global export / import (all areas, structure, templates) -
        // XML backups are reserved for the superuser
        if ($this->backend->isSuperuser()) {
            $this->backend->buttonAdd('record.record_folder_more_dropdown', '', '', alignRight:true);
        }
        echo $this->backend->buttonBar();

        // Folders and Areas
        // --------------------
        // Only the instances and readable areas of the user group (null = all)
        $access = $this->backend->access();
        $instanceFilter = $access->instanceFilter();
        $areaFilter = $access->areaFilter(Area::CONTENT_TYPES);
        if ($this->instanceCache->hasMultipleInstances()) {
            // instances as main folders, each with its own tree below
            foreach ($this->instanceCache->instanceLabels() as $instanceId => $label) {
                if ($instanceFilter !== null && !in_array((int)$instanceId, $instanceFilter, true)) continue;
                $open = in_array($instanceId, $expandedInstanceIds, true);
                echo $this->backend->template($open ? 'record.row_instance_open' : 'record.row_instance', [
                    '{instance_id}' => $instanceId,
                    '{label}' => $this->cms->textOrSnippet($label),
                    '{left}' => 20
                ]);
                if ($open) {
                    $this->area->loadAreas(Area::CONTENT_TYPES, $instanceId, hideDependent:true, areaIds:$areaFilter);
                    $this->printTree($expandedFolderIds, 1);
                }
            }
        }
        else {
            $this->area->loadAreas(areaTypes:Area::CONTENT_TYPES, hideDependent:true, areaIds:$areaFilter);
            $this->printTree($expandedFolderIds, 0);
        }

        echo $this->backend->template('col_end');
        echo $this->backend->template('col_start_50');

        // Right page area
        // --------------------

        $this->_lastChangedRecords();

        echo $this->backend->template('col_end');
        echo $this->backend->template('row_end');

        // Container Footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Record expanding or collapsing (request $requestKey + toggle) in the session and
     * return the list of open ids.
     *
     * The id is always first removed from the list and added again when opening - this
     * keeps it unique, without having to check beforehand whether it is
     * already included.
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
            $ids = array_values(array_diff($ids, [$id]));
            if ($toggle) $ids[] = $id;
            $this->session->set($sessionKey, json_encode($ids, JSON_UNESCAPED_UNICODE) ?? '');
        }
        return $ids;
    }

    /**
     * Output the result of Area::loadAreas() loaded as tree rows.
     *
     * @param int[] $expandedFolderIds  open folders
     * @param int   $depthOffset        indentation: 1 below an instance main folder
     */
    private function printTree(array $expandedFolderIds, int $depthOffset): void
    {
        $skipUntilRgt = null; // Stores the right boundary of the currently collapsed folder

        for ($i=0; $i < $this->db->num_rows; $i++) {
            $data = $this->db->fetch();
            
            // Check whether we are inside the branch of a collapsed folder
            if ($skipUntilRgt !== null) {
                if ($data['lft'] < $skipUntilRgt) {
                    // element is a child/descendant of the collapsed folder -> skip
                    continue;
                } else {
                    // the branch of the collapsed folder has been left
                    $skipUntilRgt = null;
                }
            }

            $label = $this->cms->textOrSnippet($data['label']);
            if ($data['type'] == 'folder') {
                $icon = 'folder';
                $folderId = (int)($data['area_id']);
                if (in_array($folderId, $expandedFolderIds, true)) {
                    // open folder
                    $tpl = 'record.row_folder_open';
                }
                else {
                    // closed folder
                    $tpl = 'record.row_folder';
                    // set the rgt value of the folder to skip all following children
                    $skipUntilRgt = $data['rgt'];
                }
            }
            else {
                $icon = $data['icon'];
                $tpl = 'record.row_area';
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
     * List the records last changed by the session admin (see class comment).
     *
     * The ids come from `history`, the records via Cms::select() - so records that
     * were deleted (trash) and those the user group may no longer read are
     * left out automatically. A click opens the detail view.
     */
    private function _lastChangedRecords(): void
    {
        $entries = $GLOBALS['app']->get(History::class)->entries('record');
        if (empty($entries)) return;

        $this->db->pushResult();
        $this->cms->select([
            'filter' => ['record_id' => array_keys($entries)],
            'limit'  => count($entries)
        ]);
        $records = [];
        while ($row = $this->record->fetch()) {
            $records[(int)$row['record_id']] = $row;
        }
        $this->db->popResult();
        if (empty($records)) return;

        $rows = '';
        foreach ($entries as $recordId => $changedAt) {
            if (!isset($records[$recordId])) continue;
            $this->db->pushResult();
            $text = $this->detailedText($records[$recordId]);
            $this->db->popResult();
            $rows .= $this->backend->template('record.last_changed_row', [
                '{data_id}'    => $recordId,
                '{text}'       => $text,
                '{changed_at}' => $this->cms->_formatDate($this->cms->utcToUserTZ($changedAt)),
            ]) . "\n";
        }

        echo $this->backend->template('record.last_changed_table', [
            '{table_rows}' => $rows
        ]);
    }
}