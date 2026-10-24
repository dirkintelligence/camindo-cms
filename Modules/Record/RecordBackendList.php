<?php
/**
 * Record list of a content area (extension for RecordBackend).
 *
 * Entry point: backend/record_list.php -> list()
 *
 * The most elaborate list in the backend, because unlike all others it has no
 * fixed columns: which fields appear is decided by the editor per area
 * via the flag visibility_list of the field definition.
 *
 * THE SCHEMA IS BUILT AT RUNTIME
 * --------------------
 * Record::virtualSchema($areaId) returns the field definitions of the area as a schema -
 * meta fields and user-defined ones together. From this come column headers,
 * sortability and the preparation of the values (RecordPresenter::listFields() /
 * listValue()). Without a unique area (global search) this does not exist; then
 * a single column with the default field remains. A list template of the area
 * (`area`.`list_template_id`) adds a last column without a heading.
 *
 * THREE OPERATING MODES
 * --------------------
 *     one area          columns by visibility_list, tree display if the area
 *                       has a parent_id field
 *     several areas     global search - only the default field column
 *     lookup            src_field set: additional checkbox column with which
 *                       the link can be set in the calling form
 *
 * SORTING
 * --------------------
 * Without a specification in the request, the default of the area applies (area.sort_field_id /
 * sort_order), otherwise changed_at DESC.
 *
 * @see Camindo\Core\Modules\Record\RecordBackend        shared base, _resolveParams()
 * @see Camindo\Core\Record::virtualSchema()               schema of an area
 * @see Camindo\Core\Backend::backendValue()               prepare values for display
 * @see developer docs, chapter 04 (Select and filter)
 */
namespace Camindo\Core\Modules\Record;

class RecordBackendList extends RecordBackend {

    /**
     * Output the record list (backend/record_list.php).
     *
     * Sequence:
     *
     *  1. Without any reference (no area, no filter, no lookup) the layer closes,
     *     instead of listing all records of the system. This happens when
     *     the filter of a global search was removed via the close button.
     *  2. Resolve the page parameters (RecordBackend::_resolveParams()).
     *  3. Get the schema of the area - the columns are derived from it.
     *  4. Area filter as a finished SQL fragment ('sql'), so that it does not appear
     *     in the filter text above the list that the editor reads as their
     *     search input. The ids have been cast to int beforehand or have
     *     passed through sanitizeIds().
     *  5. Set the sorting (see class comment).
     *  6. Header, then the table row by row.
     *
     * Some fields get their own display in the table instead of the general one from
     * backendValue(): status_id as a colored symbol (with a read-back hint),
     * template_id, parent_id and needs_review as plain text
     * (RecordPresenter::listValue()). The default field is highlighted and carries the
     * tree indentation for hierarchical areas. If the area has a
     * list template (`area`.`list_template_id`), its output per record follows as the last
     * column without a header (RecordPresenter::listTemplateHtml()).
     *
     * The status column additionally carries a quick filter menu in the header and is
     * therefore always placed first (array_unshift).
     *
     * pushResult()/popResult() bracket each loop iteration: teaser() and
     * backendValue() run queries themselves and would otherwise overwrite the
     * result of the list.
     *
     * Request: area_id | filter | src_field | dest_id (at least one), src_id,
     *          checked, sort, order, page, relation
     *
     * relation + dest_id: the records that point to dest_id via the link field (or with
     * relation=any via any of them) - "All references to this record" in the
     * meta field tab of the detail view.
     * relation + src_id (without src_field): the records to which the link field of
     * src_id points - "more" behind a truncated link field of the detail view.
     * select() does not take either from the request (request=all only applies to
     * filter, sort …), so _list() passes it on after checking. With src_field
     * src_id is the source of a lookup call, not that of the link.
     */
    public function list(): void
    {
        // If no filter and no area_id is given (filter was removed from the global search via the close button)
        // then close the layer - instead of listing ALL records
        if (empty($_REQUEST['area_id']) && empty($_REQUEST['filter']) && empty($_REQUEST['src_field'])
            && empty($_REQUEST['dest_id']) && empty($_REQUEST['relation'])) {
            $this->backend->script('cApp.layers.close();');
            return;
        }

        // Container header
        echo $this->backend->template('container_header');
        $this->function = 'list';

        // translate the search field '_url' (id, slug or URL) into filter values - before
        // _resolveParams(), which evaluates filter[area_id]
        $this->_applyUrlFilter();

        $this->_resolveParams();
        
        if ($this->function !== 'list') {
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }

        // read right of the user group on the area (developer docs, chapter 11 (Permissions))
        $access = $this->backend->access();
        if ($this->areaId > 0 && !$access->areaRead($this->areaId)) {
            echo $access->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        // Bread crum navi
        if (!empty($this->areaId)) {
            $naviLabel = $this->cms->_strip_tags($this->cms->textOrSnippet($this->areaData['label']));
        }
        else {
            $naviLabel = '#record.headline_global_results';
        }
        $this->backend->setNaviLabel($naviLabel);

        // H1
        echo $this->backend->headline('#record.headline_record_list');

        if (!empty($this->areaData['note'])) {
            echo $this->backend->template('module_main_note', [
                '{text}' => $this->cms->_nl2br($this->cms->textOrSnippet((string)$this->areaData['note'])) // note may be a snippet (#custom.…)
            ]);
        }

        // record list with return values
        [$destId, $count, $isLinked] = $this->_list();

        // link the listed record directly?
        $this->_linkImmediately((int)($destId ?? 0), (int)($count ?? 0), (bool)($isLinked ?? false));

        echo $this->backend->template('container_footer');
    }

    /**
     * Record list
     * Return value array:
     *  $destId   (int)  last listed record (record_id)
     *  $count    (int)  num_rows_unlimited of the list
     *  $isLinked (bool)
     */
    private function _list(): array
    {
        $isLinked = false;

        // ids passed directly from the search mask:
        $checkedIds = $this->system->sanitizeIds(
            explode(',', (string)($_REQUEST['checked'] ?? ''))
        );

        // buttons ("New" only if the group may create in the area; without a
        // fixed area the area list of the mask decides)
        $access = $this->backend->access();
        $canCreate = ($this->areaId < 1) || $access->areaCreate($this->areaId);
        $this->backend->buttonBarReset();
        if ($this->hasLookup()) {
            $srcFieldJs = $this->srcFieldJs();
            if ($canCreate) {
                $this->backend->buttonAdd('button_create', "cApp.layers.add('Record/record_edit.php?{$this->params}&function=create');");
            }
            $this->backend->buttonAdd('button_search', "cApp.layers.add('Record/record_search.php?' + cApp.layers.queryString());");
            $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
            $this->backend->buttonAdd('record.button_apply', "cApp.layers.close();cApp.ui.lookupFocus({$srcFieldJs},false,{$this->srcId});");
        }
        else {
            if ((int)$this->areaData['dependent'] !== 1 && $canCreate) {
                $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Record/record_edit.php?{$this->params}&function=create');");
            }
            $this->backend->buttonAdd('button_search', "cApp.layers.add('Record/record_search.php?' + cApp.layers.queryString());");
            $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");

            if (!empty($this->areaId)) {
                // preview of the overview of the area (overview template + slug prefix)
                $this->backend->buttonAddAreaPreview((int)$this->areaId);

                // menu on the right: export, import, media import, batch processing, settings of the
                // area - depending on the additional right or module access (Area). Export and
                // batch processing receive the page parameters of this list
                // (cApp.layers.queryString(): filter, sorting)
                $items = '';
                if (!empty($this->allowedTypes('export'))) $items .= $this->backend->template('record.record_list_more_item_export');
                if (!empty($this->allowedTypes('import'))) $items .= $this->backend->template('record.record_list_more_item_import');
                if ($this->mediaImportAllowed()) $items .= $this->backend->template('record.record_list_more_item_media_import');
                if ($this->batchAllowed()) $items .= $this->backend->template('record.record_list_more_item_batch');
                if ($this->backend->access()->module('Area')) {
                    if ($items !== '') $items .= $this->backend->template('record.record_list_more_item_divider');
                    $items .= $this->backend->template('record.record_list_more_item_settings');
                }
                if ($items !== '') {
                    $this->backend->buttonAdd('record.record_list_more_dropdown', '', '', alignRight:true);
                    $this->backend->buttonBarReplace('{items}', $items);
                    $this->backend->buttonBarReplace('{area_id}', (string)$this->areaId);
                }
            }
        }
        echo $this->backend->buttonBar();

        // schema
        if ($this->areaId > 0) {
            $schemaVirtual = $this->record->virtualSchema($this->areaId, false);
            // schema with virtual fields and meta fields,
            // correctly marked fields with "meta=1" that are based on a meta field
            $completedSchema = $this->record->virtualSchema($this->areaId, true);
        }
        else {
            // only fields that occur in all content areas (meta fields)
            $schemaVirtual = [];
            $completedSchema = $this->cms->schema;
        }
        $this->hasParentId = isset($schemaVirtual['parent_id']);

        // area filter (not in the filter text)
        // --------------------
        $sql = '';
        if (!empty($this->areaId)) {
            $sql = "`record`.`area_id` = {$this->areaId}";
        }
        elseif (!empty($this->areaIds)) {
            $ids =  implode(',', $this->areaIds);
            $sql = "`record`.`area_id` IN ({$ids})";
        }
        // without a fixed area (global search, references): only areas that the group
        // may read - a filter[area_id] may not change this either
        if (empty($this->areaId)) {
            $accessSql = $this->backend->areaAccessSql(false, '`record`.`area_id`');
            if ($accessSql !== '') {
                $accessSql = substr($accessSql, 5); // leading " AND "
                $sql = ($sql === '') ? $accessSql : $sql . ' AND ' . $accessSql;
            }
        }

        // references to a record (see list())
        $relationParams = $this->_relationParams();

        // selection of the parent record: the record itself and its own branch
        // are excluded - otherwise the tree would hang on itself.
        if ($this->srcIsParent) {
            $excludeSql = $this->_parentExcludeSql();
            if ($excludeSql !== '') {
                $sql = ($sql === '') ? $excludeSql : $sql . ' AND ' . $excludeSql;
            }
        }

        // sorting
        // --------------------
        if (empty($_REQUEST['sort'])) {
            // fallback
            $sort = 'changed_at';
            $order = 'DESC';
            // one area?
            if ($this->areaId > 0) {
                // determine the default sorting of the area - the direction applies also
                // without a sort field (then changed_at), otherwise filterText() would show the
                // sorting as changed and would not let it be removed
                if ($sortFieldId = (int)($this->areaData['sort_field_id'] ?? 0)) {
                    if ($fieldData = $this->record->fieldData($sortFieldId)) {
                        $sort = $fieldData['name'];
                    }
                }
                $order = strtoupper((string)($this->areaData['sort_order'] ?? '')) === 'ASC' ? 'ASC' : 'DESC';
            }
            $_REQUEST['sort'] = $sort;
            $_REQUEST['order'] = $order;
        }

        // tree area: sorting by `position` would mean sorting flat across all branches -
        // `position` counts per parent record and starts again at 1 in EVERY branch
        // (see TreeManagerTrait). The order of the tree is held
        // solely in `lft`; so lft is meant.
        // ASC is mandatory here, even if the area specifies DESC: the list indents
        // the rows by `depth` (tree display), which only works if each
        // parent record comes before its children.
        if ($_REQUEST['sort'] === 'position' && $this->areaId > 0 && $this->hasParentId) {
            $_REQUEST['sort'] = 'lft';
            $_REQUEST['order'] = 'ASC';
        }

        // records per page: that of the area (details.page_size, 0 = all),
        // otherwise admin.skiplist_default
        $pageSize = $this->_pageSize();

        // SELECT
        // --------------------
        $f = $this->cms->select([
            'request' => 'all',
            'limit' => $pageSize,
            'count' => 'true',
            'filtertext' => 'true',
            'sql' => $sql,
        ] + $relationParams, null, $completedSchema);

        // search terms or changed sorting as readable text
        echo $this->filterText($f);

        // pagination top
        echo $this->backend->paginationTop('', $pageSize);
        // total count right here - the rows below stack their own queries
        $count = (int)$this->db->num_rows_unlimited;

        if ($this->db->num_rows < 1) {
            // no results, no table
            echo $this->backend->template('container_footer');
            return [0, 0, false];
        }

        // create head columns
        if ($this->hasLookup()) {
            // if lookup call, then an additional first column
            $headColumns = $this->backend->template('record.lookup_table_head_column');
        }
        else {
            $headColumns = '';
        }

        // list template of the area: additional last column without a header
        // (see RecordPresenter::listTemplateHtml())
        $listTemplateId = (int)($this->areaData['list_template_id'] ?? 0);
        $hasListTemplate = ($this->areaId > 0 && $listTemplateId > 0);

        if (empty($this->areaId)) {
            // Mehrere Bereiche -> Default-Feld als Spalte
            $headColumns .= '<th scope="col">' . $this->cms->snippet('record.field_default') . "</th>\n";
        }
        else {
            // one column per visible field - link fields only if the
            // group may read at least one target area
            $visibleFields = array_values(array_filter(
                $this->presenter->listFields($schemaVirtual),
                fn($name) => $this->record->relationTargetReadable($schemaVirtual[$name])
            ));
            foreach ($visibleFields as $name) {
                $headColumn = $this->backend->tableHeadColumn($schemaVirtual[$name], $f, $name);
                if ($name == 'status_id') {
                    // status has dropdown
                    $statusItems = [];
                    $statusDropdownItems = $this->_statusDropdownItems(icon:true);
                    foreach ($statusDropdownItems as $statusValue => $statusLabel) {
                        $statusItems[] = $this->backend->template('record.record_table_dropdown_item', [
                            '{name}' => 'status_id',
                            '{value}' => $statusValue,
                            '{label}' => $statusLabel
                        ]);
                    }
                    $statusItems[] = $this->backend->template('record.dropdown_items_review');
                    $statusLabelDropdown = $this->backend->template('record.record_table_head_column_dropdown',[
                        '{dropdown_id}' => $this->crypt->randomKey(16),
                        '{column_label}' => $headColumn,
                        '{items}' => implode("\n", $statusItems)
                    ]);
                    $headColumns .= '<th scope="col" style="width:1px">' . $statusLabelDropdown . '</th>';
                }
                else {
                    $headColumns .= '<th scope="col">' . $headColumn . "</th>\n";
                }
            }
            if ($hasListTemplate) $headColumns .= "<td></td>\n"; // column without a header
        }

        // tree display only if the area has a hierarchy at all -
        // i.e. an input field for parent_id is defined. Otherwise the list is flat.
        $hasParentId = isset($schemaVirtual['parent_id']);

        // create table rows
        $tplTableRow = $this->backend->template('record.record_table_row');
        $tableRows = '';
        $destId = 0;
        for ($i = 0; $i < $this->db->num_rows; $i++) {
            $row = $this->cms->fetch();
            $this->db->pushResult();
            // new row
            $destId = (int)$row['record_id'];

            // if lookup call, then an additional first column
            if ($this->hasLookup()) {
                $srcFieldJs = $this->srcFieldJs();

                if ($this->srcIsParent && $this->srcId > 0) {
                    // 0. choose the parent record for the INPUT MASK: nothing is
                    // written, the choice moves into the form in the layer below.
                    // Which one is already chosen is in the page parameter
                    // 'checked' - the working copy itself does not know it.
                    // (Without src_id the call comes from the search mask; there
                    //  several parent records are allowed and case 3 applies.)
                    $isLinked = in_array($destId, $checkedIds, false);
                    $clickHandler = "cApp.ui.plookupUpdate($srcFieldJs,\$(this).prop('checked')?'link':'unlink',$destId);";
                }
                elseif ($this->srcId > 0) {
                    // 1. srcId given: the link with the src record should be possible to set
                    // check whether the link already exists
                    $isLinked = $this->record->checkRelation($this->srcField, $this->srcId, $destId);
                    $clickHandler = "cApp.ui.lookupRUpdate($srcFieldJs,\$(this).prop('checked')?'link':'unlink',$destId,0,false,{$this->srcId});";
                }
                else {
                    // 2. srcId not given: the record moves into the field of the
                    // calling form (search mask or a foreign form such as
                    // instance.record_id). Page parameter 'checked' is evaluated.
                    $isLinked = in_array($destId, $checkedIds, false);
                    $clickHandler = "cApp.ui.slookupUpdate($srcFieldJs,\$(this).prop('checked')?'link':'unlink',$destId);";
                };

                $formCheck = $this->backend->template('list_checkbox', [
                    '{onclick}' => $clickHandler,
                    '{checked}' => $isLinked ? ' checked' : ''
                ]);

                // wrap the checkbox in a column
                $tableCols = $this->backend->template('table_column_noctx_lg', [
                    '{html}' => $formCheck
                ]);
            }
            else {
                $tableCols = '';
            }
            $defaultField = $this->backend->getDefaultField($row['area_id']);
            if (empty($schemaVirtual)) {
                // several areas: a single column with the default field.
                // The default field is determined per ROW - in a result list spanning
                // areas it can be a different one in every row.

                $defaultFieldName = $defaultField['name'] ?? 'record_id';
                $value = $this->backend->backendValue($row[$defaultFieldName], $defaultField, id: $row['record_id']);
                $tableCols .= $this->backend->template('table_column_default', [
                    '{text}' => $value
                ]);
            }
            else {
                // add one column per visible field
                foreach ($visibleFields as $name) {
                    $defaultField = (int)($schemaVirtual[$name]['default_field'] ?? 0);
                    $value = $this->presenter->listValue($name, $row, $schemaVirtual);
                    if ($defaultField == 1) {
                        if ($hasParentId) {
                            // bold with tree structure
                            // depth is 0-based: root nodes are not indented
                            $depth = (int)($row['depth'] ?? 0);
                            if ($depth > 0) {
                                $tableCols .= $this->backend->template('table_column_tree_x', [
                                    '{text}' => $value,
                                    '{depth}' => $depth * 15
                                ]);
                            }
                            else {
                                $tableCols .= $this->backend->template('table_column_tree_0', [
                                    '{text}' => $value,
                                    '{depth}' => $depth * 15
                                ]);
                            }
                        }
                        else {
                            // bold only
                            $tableCols .= $this->backend->template('table_column_default', [
                                '{text}' => $value
                            ]);
                        }
                    }
                    else {
                        $tableCols .= '<td>' .$value . "</td>\n";
                    }
                }
                // list template of the area as last column
                if ($hasListTemplate) {
                    $tableCols .= '<td>' . $this->presenter->listTemplateHtml($this->areaId, $row) . "</td>\n";
                }
            }
            // context menu: hide entries without the right for this record
            // (owner group decides on edit/delete, copy = create)
            $rowAreaId = (int)$row['area_id'];
            $rowOwnerId = isset($row['owner_id']) ? (int)$row['owner_id'] : null;
            $hide = [];
            if (!$access->recordWrite($rowAreaId, $rowOwnerId, (int)($row['status_id'] ?? 0))) $hide[] = 'edit';
            if (!$access->areaCreate($rowAreaId)) $hide[] = 'copy';
            if (!$access->areaDelete($rowAreaId, $rowOwnerId)) $hide[] = 'delete';

            // add to the row collection
            $tableRows .= strtr($tplTableRow, [
                '{data_id}' => $row['record_id'],
                '{ctx_hide}' => $hide ? ' data-ctx-hide="' . implode(',', $hide) . '"' : '',
                '{columns}' => $tableCols
            ]);
            $this->db->popResult();
        }

        // Tabelle rendern
        echo $this->backend->template('record.record_table_with_head', [
            '{head_columns}' => $headColumns,
            '{table_rows}' => $tableRows,
            '{params}' => $this->params ? "&{$this->params}" : ''
        ]);


        // Context menu
        echo $this->backend->template('record.record_context_menu', [
            '{params}' => $this->params ? "&{$this->params}" : ''
        ]);

        // Pagination bottom
        echo $this->backend->paginationBottom($pageSize);

        // positional - list() splits it with [$destId, $count, $isLinked]
        return [$destId ?? null, $count, $isLinked];
    }


    /**
     * SQL condition that excludes the edited record and its branch.
     *
     * Only needed for the lookup call for `parent_id`: neither the record itself nor
     * one of its own descendants can be its own parent record - otherwise the tree would hang
     * on itself. The branch is delimited via the nested set (lft/rgt).
     *
     * src_id is the working copy; what is meant is the original, which getProdData()
     * returns - this also checks that the working copy belongs to the signed-in editor.
     * A newly created record has no original yet and no branch,
     * so there is nothing to exclude.
     *
     * @return string  '' or a WHERE sub-condition
     */
    private function _parentExcludeSql(): string
    {
        if ($this->srcId < 1) return '';
        if (!$prodData = $this->record->getProdData($this->srcId)) return '';
        $originId = (int)$prodData[0];
        if ($originId < 1) return '';

        $this->db->query("SELECT `lft`,`rgt`,`area_id` FROM `record` WHERE `record_id` = ?", 'i', [$originId]);
        if ($this->db->num_rows < 1) return '';
        [$lft, $rgt, $areaId] = $this->db->fetchNum();
        $lft = (int)$lft;
        $rgt = (int)$rgt;
        $areaId = (int)$areaId;

        // without valid nested-set boundaries (area without tree) there is no branch,
        // then only the record itself is to be excluded.
        if ($lft < 1 || $rgt <= $lft) {
            return "`record`.`record_id` != {$originId}";
        }
        // lft/rgt count per content area from 1 (see TreeManagerTrait) - without the
        // area filter the branch exclusion would also hit foreign areas.
        return "`record`.`record_id` != {$originId}"
            . " AND NOT (`record`.`area_id` = {$areaId}"
            . " AND `record`.`lft` > {$lft} AND `record`.`rgt` < {$rgt})";
    }

    /**
     * Resolve filter[_url] (RecordService::urlFilter()) and replace it by record_id or
     * slug. If a slug prefix limits to areas, in a list with a fixed area it
     * applies: if the area is not below it, there is no hit; in the global list
     * the intersection with an area selection.
     * The value remains as filter[_url] in the address (the search mask shows it
     * again), select() does not see it.
     */
    private function _applyUrlFilter(): void
    {
        $filter = $_REQUEST['filter'] ?? null;
        if (!is_array($filter) || !isset($filter['_url']) || !is_scalar($filter['_url'])) return;
        $url = $this->service->urlFilter((string)$filter['_url']);
        unset($filter['_url']);
        if (isset($url['area_id'])) {
            $areaId = (int)($_REQUEST['data']['area_id'] ?? $_REQUEST['area_id'] ?? 0);
            $selected = $this->system->sanitizeIds((array)($filter['area_id'] ?? []));
            if ($areaId > 0) {
                if (!in_array($areaId, $url['area_id'], true)) $url = ['record_id' => '0'];
                else unset($url['area_id']);
            }
            elseif ($selected) {
                $url['area_id'] = array_values(array_intersect($selected, $url['area_id'])) ?: [0];
            }
        }
        $_REQUEST['filter'] = array_replace($filter, $url);
    }

    /**
     * relation + dest_id or src_id from the request for select() (see list()):
     * ids as positive numbers, relation 'any' or field names (comma-separated).
     * src_id only without lookup call (src_field). relation without either: no
     * hits (select() finds nothing with relation but no source).
     *
     * @return array  ['relation' => …, 'dest_id' => …, 'src_id' => …] or []
     */
    private function _relationParams(): array
    {
        $relation = strtolower(trim((string)($_REQUEST['relation'] ?? '')));
        if ($relation === '') return [];
        if (!preg_match('/^[a-z0-9_]+(,[a-z0-9_]+)*$/', $relation)) return ['relation' => 'any'];
        $params = ['relation' => $relation];
        $destId = (int)($_REQUEST['dest_id'] ?? 0);
        if ($destId > 0) $params['dest_id'] = $destId;
        $srcId = (int)($_REQUEST['src_id'] ?? 0);
        if ($srcId > 0 && !$this->hasLookup()) $params['src_id'] = $srcId;
        return $params;
    }

    /**
     * Prepare results from Dataset::select
     * Including close button, analogous to Backend->filterText()
     * but with the default sorting of the area and
     * the exception that 'lft' is meant when 'position' is requested with an existing 'parent_id'
     *
     * @param array $f           return values of Dataset::select
     */
    public function filterText(array $f): string
    {
        $filterText = (string) $f['filter_text'] ?? '';
        // defaultSort comes from the area for Record
        $sortOrder = strtoupper((string)($this->areaData['sort_order'] ?? '')) === 'ASC' ? 'ASC' : 'DESC';
        if ($sortFieldName = $this->areaData['sort_field_name'] ?? '') {
            if ($sortFieldName === 'position' && $this->hasParentId) {
                // as above in list(): tree order is lft ASC
                $sortFieldName = 'lft';
                $sortOrder = 'ASC';
            }
        }
        else {
            $sortFieldName = 'changed_at';
        }
        $defaultSort = $sortFieldName . ' ' . $sortOrder;
        $usedSort = (string) $f['used_sort'] ?? '';
        $sortingChanged = ($defaultSort != $usedSort);

        $textParts = [];
        if (!empty($filterText) || $sortingChanged) {
            if (!empty($filterText)) {
                $prefixSearchFor = $this->backend->template('prefix_search_for');
                $textParts[] = $prefixSearchFor . ' ' . $filterText;
            }
            if ($sortingChanged) {
                $sortText = (string) $f['sort_text'] ?? '';
                $prefixSortBy = $this->backend->template('prefix_sort_by');
                $textParts[] = $prefixSortBy . ' ' . $sortText;
            }
        }
        if (!empty($textParts)) {
            return $this->backend->template('search_where_clause', [
                    '{text}' => implode('<br>', $textParts)
            ]);
        }
        else return '';
    }

    /**
     * Checks whether the (one listed) record should be linked directly
     * if
     *   - the list was opened by a lookup
     *   - exactly one record was found
     *   - it is not yet linked,
     * then link this one directly and close the layer.
     * Saves the extra click for an unambiguous search.
     * num_rows_unlimited is the total count WITHOUT pagination.
     */
    private function _linkImmediately(?int $destId, ?int $count, bool $isLinked): void
    {
        if ($this->hasLookup()
            && $count === 1
            && !$isLinked
        ) {
            $srcFieldJs = $this->srcFieldJs();
            if ($this->srcIsParent && $this->srcId > 0) {
                // parent record: close the window, take over, clear the lookup field
                $this->backend->script("
                    cApp.layers.close();
                    cApp.ui.plookupUpdate({$srcFieldJs},'link',$destId,true);
                    cApp.ui.lookupFocus({$srcFieldJs},true,{$this->srcId});
                ");
            }
            elseif (!empty($this->srcId)) {
                // link directly, close the window, clear the lookup field and focus it
                $preventClosing = 'true'; // prevents double closing with max 1 link
                $this->backend->script("
                    cApp.ui.lookupRUpdate({$srcFieldJs},'link',$destId,0,$preventClosing,{$this->srcId});
                    cApp.layers.close();
                    cApp.ui.lookupFocus({$srcFieldJs},true,{$this->srcId});
                ");
            }
            else {
                // search mask or foreign form
                $this->backend->script("
                    cApp.ui.slookupUpdate({$srcFieldJs},'link',$destId);
                ");
            }
        }
    }
}