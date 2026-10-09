<?php
/**
 * Search mask and search execution for records (extension for RecordBackend).
 *
 * Entry points (scripts in backend/):
 *
 *     record_search.php         -> search()           mask or execution
 *     record_global_search.php  -> searchCrossArea()  empty intermediate layer
 *     ajax_slookup_preview.php  -> sLookupPreview()   update the lookup preview
 *
 * THE MASK IS NOT FIXED
 * --------------------
 * Like the list, the search mask has no fixed fields: one can search by
 * what the editor has marked per area with visibility_search=1. In addition,
 * the field _url (record_id, slug or URL) and four time fields (online_at, offline_at, created_at,
 * changed_at) are always included, appended at the end, and always the standard full-text field
 * (fulltext, without input field). The global search (without area) additionally offers the
 * default field (_default) - it is called the same as `fulltext` and is the same in every area.
 *
 * Without a unique area (global search) an additional multiple selection of
 * the areas appears; with a pointer call it is limited to the target areas of that call.
 *
 * THE SEARCH DOES NOT RENDER A RESULT LIST
 * --------------------
 * _searchAction() closes the search layer and reloads the list layer beneath it
 * with the search parameters in the URL - RecordBackendList evaluates them. As a result
 * sorting, pagination and filter text work afterwards as usual.
 *
 * LINKS AS A SEARCH CRITERION
 * --------------------
 * After a relation or pointer field one does not search with text, but by
 * selecting the intended records. For this there is the same lookup procedure as in the
 * input form, only with the form prefix 'filter' instead of 'data':
 *
 *     _sLookupInit()           builds the preview when the mask is first built
 *     sLookupPreview()         updates it via Ajax after a selection
 *     _sLookupPreviewCreate()  the common core
 *
 * The same procedure applies to the meta field `parent_id` when it is set up as a lookup.
 * Unlike in the input mask (RecordBackendEdit, where there is exactly ONE parent record
 * and its own field), no special handling is needed here: the filter `parent_id`
 * searches the descendants of the given records and accepts several values.
 *
 * SORT FIELDS
 * --------------------
 * The selection list "sort by" is built along the way. Not every field is suitable
 * for this: one cannot sort by 'relation'/'pointer' (n targets); for the
 * 1:1 variants sorting is done via the default field of the target (name._default), for
 * file fields via the file name (name.filename).
 *
 * @see Camindo\Core\Modules\Record\RecordBackend      shared base
 * @see Camindo\Core\Modules\Record\RecordBackendList  evaluates the search parameters
 * @see developer docs, chapter 04 (Select and filter)                      filter syntax
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Modules\Area\Field;

class RecordBackendSearch extends RecordBackend {

    /**
     * Search (backend/record_search.php).
     *
     * Without function the mask, with function=search the execution.
     */
    public function search(): void
    {
        $function = (string)($_REQUEST['function'] ?? '');
        $this->_resolveParams();
        // create a temporary record so that relations can be added via a lookup field

        if ($function == 'search') {
            $this->_searchAction();
        }
        else {
            $this->_searchForm();
        }
    }

    /**
     * Empty intermediate layer for the cross-area search.
     *
     * The layers stack: area tree, the result list above it. A search across
     * all areas, however, has no area from which it would be opened - without
     * this placeholder the result list would lie directly on the tree and would take it along
     * when closed. The layer therefore contains nothing except a
     * close button.
     */
    public function searchCrossArea(): void
    {
        $this->backend->setNaviLabel('#record.headline_global_results');

        echo $this->backend->template('container_header');
        echo $this->backend->template('button_close', [
            '{onclick}' => 'cApp.layers.close();'
        ]);
        echo $this->backend->template('container_footer');
    }

    /**
     * Meta fields that the global search (without area) temporarily offers via "more fields".
     * (Without an area there is no parent_id - and therefore also no
     * depth that refers only to parent_id.)
     */
    private const GLOBAL_EXTRA_FIELDS = ['status_id', 'user_id', 'owner_id', 'instance_id', 'needs_review', 'template_id'];

    /** Subfields of an upload field in the search mask (Cms::buildFileExists()) */
    private const FILE_SUBFIELDS = ['width', 'height', 'size', 'mime_type', 'extension'];

    /**
     * The search mask.
     *
     * Structure from top to bottom:
     *
     *  1. _url - always there: record_id, slug or URL (RecordService::urlFilter())
     *  2. area selection - only without a unique area; with a pointer call
     *     restricted to its target areas
     *  3. the fields with visibility_search=1, in schema order
     *  4. frontend users (user_id) in areas with frontend input (ugc with
     *     profile areas); directly after parent_id its depth (filter[depth],
     *     relative to the selected parent record as with CMS:SELECT)
     *  5. the four time fields
     *  6. temporarily added fields (class .warning, see below)
     *  7. sort by / direction, below it the selection "more fields"
     *
     * searchField() instead of formField(): search fields are never required fields and write
     * to filter[...] instead of data[...].
     *
     * MORE FIELDS
     * Below the submit button fields can be added that are otherwise not in the
     * mask: in the area the fields with visibility_search=0, in the global
     * search meta fields (GLOBAL_EXTRA_FIELDS). "Add" sends the mask with
     * function=add_field to itself; the list of added fields travels as
     * extra[] (also into the result list and from there back into the mask), the
     * entered values as filter[...].
     *
     * UPLOAD FIELDS
     * An upload field searches in the stored file name; below it, expandable: width,
     * height, file size (with k/M/G, Cms::expandSizeUnits()), MIME type and extension
     * (filter[field.width] …).
     *
     * Where the selection options of a field come from depends on the field -
     * status_id, template_id, parent_id and position get them from the
     * helper methods of the base class, all others from the rows of the `default`
     * field of the field definition (there, for selection fields, the possible values are
     * stored, one per row).
     */
    private function _searchForm(): void
    {
        echo $this->backend->template('container_header');

        // page parameters
        $filter = (array)($_REQUEST['filter'] ?? []);

        echo '<form action="Record/record_search.php" method="post" enctype="multipart/form-data">';

        // Bread crum navi
        $this->backend->setNaviLabel('#button_search');

        // H1
        echo $this->backend->headline('#record.headline_search');

        $schemaVirtual = [];
        $visibleFields = [];

        // meta fields that are always visible
        $visibleMetaFields = [
            'online_at', 'offline_at', 'created_at', 'changed_at'
        ];

        // virtual fields marked with visibility_search=1
        $configured = [];
        if ($this->areaId > 0) {
            $schemaVirtual = $this->record->virtualSchema($this->areaId, true);
            // the fields set up in the area (without the other meta columns)
            $configured = $this->record->virtualSchema($this->areaId, false);

            // search fields defined by the admin for the area
            $visibleFields = [];
            foreach($schemaVirtual as $name => $colDef) {
                if (in_array($name, $visibleMetaFields)) continue;

                $visibility = (int)($colDef['visibility_search'] ?? 0);
                if ($visibility === 1) {
                    $visibleFields[] = $name;
                    }
            }
        }

        // more fields: selectable and already added (extra[])
        $extraOptions = $this->_extraFieldOptions($configured, $visibleFields, $visibleMetaFields);
        $extraFields = array_values(array_intersect(
            array_unique(array_map('strval', (array)($_REQUEST['extra'] ?? []))),
            array_keys($extraOptions)
        ));
        $addField = (string)($_REQUEST['add_field'] ?? '');
        if ((string)($_REQUEST['function'] ?? '') === 'add_field' && isset($extraOptions[$addField])
            && !in_array($addField, $extraFields, true)) {
            $extraFields[] = $addField;
        }

        // create the form
        // --------------------
        $this->backend->formInit('record.record_search_form');

        // id, slug or URL (RecordService::urlFilter(), resolved in the list)
        $this->backend->searchField($this->record, [
            'name' => '_url',
            'value' => (string)($filter['_url'] ?? $filter['record_id'] ?? ''),
            'colDef' => ['name' => '_url', 'type' => 'chars', 'label' => '#record.field_url'],
            'note' => '#record.note_field_url',
        ]);

        // area_id
        if (empty($this->areaId)) {
            $sqlTypes = '';
            $sqlValues = [];            
            $sql = "SELECT `area_id`, `depth`, `label` FROM `area` WHERE `type` IN ('record','profile','ugc') AND `deleted`=0"
                 . $this->backend->areaAccessSql();
            
            if (!empty($this->areaIds)) {
                $placeholders = implode(',', array_fill(0, count($this->areaIds), '?'));
                $sql .= " AND `area_id` IN ($placeholders)";                
                $sqlTypes .= str_repeat('i', count($this->areaIds));
                $sqlValues = $this->areaIds;
            }
            
            $sql .= " ORDER BY `lft` ASC";            
            $this->db->query($sql, $sqlTypes, $sqlValues);
            
            $areaOptions = [];
            while ($areaData = $this->db->fetch()) {
                $fullPath = str_repeat('&nbsp; ', (int)$areaData['depth'] * 2) . $this->cms->textOrSnippet($areaData['label']);
                $areaId = (string)$areaData['area_id'];
                $areaOptions[$areaId] = $fullPath;
            }
            $areaColDef = [
                'type' => 'json', // Mehrfachauswahl fester Werte erlauben
                'display_type' => 'multiselect',
                'label' => '#area.content_area'
            ];
            $this->backend->searchField($this->record, [
                'name' => 'area_id',
                'value' => $this->areaFilter,
                'colDef' => $areaColDef,
                'options' => $areaOptions,
                'append' => true
            ]);

            // default field (data_dft)
            $this->backend->searchField($this->record, [
                'name' => '_default',
                'value' => (string)($filter['_default'] ?? ''),
                'colDef' => ['name' => '_default', 'type' => 'chars', 'label' => '#record.field_default'],
                'append' => true
            ]);
        }

        // standard full-text field: in every record area, without input field
        // (VirtualFieldsTrait::refreshStandardFulltext()); it has the same name everywhere, so the
        // filter also applies across areas
        $this->backend->searchField($this->record, [
            'name' => Field::FULLTEXT,
            'value' => (string)($filter[Field::FULLTEXT] ?? ''),
            'colDef' => ['name' => Field::FULLTEXT, 'type' => 'fulltext', 'display_type' => 'text', 'label' => '#area.label_field_fulltext'],
            'append' => true
        ]);
        
        // collect fields for the sort selection list
        $sortOptions = [];

        // input fields (virtual fields)
        // --------------------
        foreach ($visibleFields as $name) {
            $this->_searchVirtualField($name, $schemaVirtual[$name] ?? [], $filter, $sortOptions);
        }

        // frontend users - in areas with frontend input
        // --------------------
        if ($this->areaId > 0 && !empty($this->_profileAreaIds())) {
            $this->_searchMetaField('user_id', $filter);
        }

        // append meta fields at the end
        // --------------------
        foreach ($visibleMetaFields as $name) {
            $this->backend->searchField($this->record, [
                'name' => $name,
                'value' => $filter[$name] ?? '',
                'append' => true
            ]);
            $sortOptions[$name] = $this->cms->textOrSnippet($this->cms->schema[$name]['label'] ?? $name);
        }

        // temporarily added fields (more fields)
        // --------------------
        foreach ($extraFields as $name) {
            if ($this->areaId > 0) {
                $this->_searchVirtualField($name, $schemaVirtual[$name] ?? [], $filter, $sortOptions, 'warning');
            }
            else {
                $this->_searchMetaField($name, $filter, 'warning');
            }
            $this->backend->formHidden('extra[]', $name);
        }

        // Sort, Order
        $this->backend->searchFieldSortOrder($sortOptions, append:true);

        // Hidden fields
        // --------------------
        $this->backend->formHidden('function', 'search');
        $this->backend->formHidden('area_id', $this->areaId);
        // call by lookup field - field_id or (foreign form) column name; as a
        // number the name would be lost and the result list would no longer be a lookup
        // (without "Apply")
        $this->backend->formHidden('src_field', $this->srcFieldName !== '' ? $this->srcFieldName : $this->srcField);
        $this->backend->formHidden('src_id', $this->srcId);
        // ids already selected from a search-mask lookup (slookupSearch(): checked) -
        // the result list checks them again
        $checked = preg_replace('/[^0-9,]/', '', (string)($_REQUEST['checked'] ?? ''));
        if ($checked !== '' && $this->hasLookup()) $this->backend->formHidden('checked', $checked);
        // references from or to a record (RecordBackendList::list()) are kept
        // during the search (src_id is already above)
        $destId = (int)($_REQUEST['dest_id'] ?? 0);
        $relation = strtolower(trim((string)($_REQUEST['relation'] ?? '')));
        if (preg_match('/^[a-z0-9_]+(,[a-z0-9_]+)*$/', $relation) && ($destId > 0 || $this->srcId > 0)) {
            if ($destId > 0) $this->backend->formHidden('dest_id', $destId);
            $this->backend->formHidden('relation', $relation);
        }

        // Formular rendern
        echo $this->backend->form('form_search_cancel');

        // more fields: selection below the submit button
        $addOptions = array_diff_key($extraOptions, array_flip($extraFields));
        if (!empty($addOptions)) {
            $optionsHtml = '';
            foreach ($addOptions as $value => $label) {
                $optionsHtml .= '<option value="' . htmlspecialchars((string)$value) . '">'
                    . htmlspecialchars($this->cms->_strip_tags($label)) . "</option>\n";
            }
            echo $this->backend->template('record.search_add_field', ['{options}' => $optionsHtml]);
        }

        // container footer
        echo $this->backend->template('container_footer');

        echo '</form>';
    }

    /**
     * A virtual field as a search field (fields with visibility_search=1 and those added via
     * "more fields") - including the entry in the sort selection.
     *
     * @param array  $sortOptions  is supplemented
     * @param string $blockClass   'warning' for a temporarily added field
     */
    private function _searchVirtualField(string $name, array $colDef, array $filter, array &$sortOptions, string $blockClass = ''): void
    {
        $type = $colDef['type'] ?? 'text';
        $displayType = $colDef['display_type'] ?? 'text';
        // firmly linked contents: embedded ones have no search field for them
        // (as a lookup like every link field)
        if (in_array($type, $this->cms->dependentTypes, true) && $displayType !== 'lookup') return;

        // upload field: file name, subfields expandable
        if ($type === 'file') {
            $this->_searchFileField($name, $colDef, $filter, $blockClass);
            $sortOptions[$name . '.filename'] = $this->cms->textOrSnippet($colDef['label'] ?? $name);
            return;
        }

        // Optionen
        $options = [];
        if ($name == 'status_id') {
            $options = $this->_statusDropdownItems(icon:false);
        }
        else {
            // default value entries, if display_type 'json'
            $list = preg_split('/(\r\n|\r|\n)/', $colDef['default'] ?? '');
            foreach ($list as $item) {
                $options[$item] = $item;
            }
        }

        // template_id
        if ($name === 'template_id' && $displayType != 'lookup') {
            $options = $this->_templateOptions();
        }

        // status_id
        elseif ($name === 'status_id' && $displayType != 'lookup') {
            $options = $this->_statusOptions();
        }

        // parent_id
        elseif ($name === 'parent_id' && $displayType != 'lookup') {
            $options = $this->_parentOptions();
        }

        // position
        // _previousOptions() lists the siblings of ONE parent record. There is none
        // in the search mask, so it stays at the root level
        // (default value 0).
        elseif ($name == 'position' && $displayType != 'lookup') {
            $options = $this->_previousOptions();
        }

        $fieldParams = [
            'name' => $name,
            'value' => $filter[$name] ?? '',
            'colDef' => $colDef,
            'options' => $options,
            'append' => true,
            'blockClass' => $blockClass,
        ];
        if ($name === 'parent_id' && $displayType === 'lookup') {
            // not a link field - the context menu of the links
            // (remove link) would have nothing to do here
            $fieldParams['contextMenu'] = '';
        }
        $this->backend->searchField($this->record, $fieldParams);

        // lookup field - table record - subsequent replacements.
        //
        // The meta field parent_id is also searched this way. Unlike in the
        // input mask, no special handling is needed for it: the filter
        // parent_id searches the DESCENDANTS of the given records and accepts
        // several values (developer docs, chapter 04 (Select and filter)) - exactly what
        // the ordinary search lookup delivers. The filter name comes from the
        // field definition, so the hidden fields are called filter[parent_id][].
        if ($displayType === 'lookup'
            && (in_array($type, $this->cms->relationTypes, true)
                || ($name === 'parent_id' && $type === 'id'))
        ) {
            $srcField = $colDef['field_id'] ?? 0;
            $urlSuffix = "src_field={$srcField}";
            $searchUrl = "Record/record_list.php?" . $urlSuffix;
            $updateUrl = "Record/ajax_slookup_preview.php?" . $urlSuffix;
            $filterIds = (array)($_REQUEST['filter'][$name] ?? []);
            $ids = $this->system->sanitizeIds($filterIds, true);
            $idString = implode(',', $ids);
            
            $this->backend->formReplace('{src_field}', $srcField);
            $this->backend->formReplace('{search_url}', $searchUrl);
            $this->backend->formReplace('{update_url}', $updateUrl);
            $this->backend->formReplace('{max}', '');
            $this->backend->formReplace('{data_ids}', $idString);
            $this->backend->formReplace('{preview}', $this->_sLookupInit($srcField, $ids));
        }

        // depth directly after the parent record - it refers to it
        if ($name === 'parent_id') {
            $this->_searchMetaField('depth', $filter, $blockClass);
        }

        if (in_array($type, ['relation', 'pointer', 'link'], true)) {
            // not sortable
            return;
        }
        elseif (in_array($type, ['relation1', 'pointer1'], true)) {
            $sortKey = $name . '._default';
        }
        else {
            $sortKey = $name;
        }
        $sortOptions[$sortKey] = $this->cms->textOrSnippet($colDef['label'] ?? $name);
    }

    /**
     * Upload field as a search field: a text field for the stored file name
     * (filter[field]), below it expandable: width, height, file size, MIME type and
     * extension (filter[field.width] …, Cms::buildFileExists()). The section is open
     * as soon as one of the subfields has a value.
     */
    private function _searchFileField(string $name, array $colDef, array $filter, string $blockClass): void
    {
        $label = (string)($colDef['label'] ?? $name);
        $this->backend->searchField($this->record, [
            'name' => $name,
            'value' => (string)(is_scalar($filter[$name] ?? null) ? $filter[$name] : ''),
            'colDef' => ['name' => $name, 'type' => 'chars', 'label' => $label],
            'note' => '#record.note_search_file_filename',
            'append' => true,
            'blockClass' => $blockClass,
        ]);

        // build the subfields in their own buffer and insert them expandable
        $this->backend->formPush();
        $open = false;
        foreach (self::FILE_SUBFIELDS as $sub) {
            $key = $name . '.' . $sub;
            $value = is_scalar($filter[$key] ?? null) ? (string)$filter[$key] : '';
            if ($value !== '') $open = true;
            $this->backend->searchField($this->record, [
                'name' => $key,
                'value' => $value,
                'colDef' => ['name' => $key, 'type' => 'chars', 'label' => '#record.field_file_' . $sub],
                'note' => in_array($sub, ['width', 'height', 'size'], true) ? '#record.note_search_file_' . ($sub === 'size' ? 'size' : 'number') : '',
                'flatName' => true,
                'append' => true,
                'blockClass' => $blockClass,
            ]);
        }
        $subFields = $this->backend->formPop();
        $this->backend->formAppend('', $this->backend->template('record.search_file_details', [
            '{label}' => $this->cms->textOrSnippet($label),
            '{fields}' => $subFields,
            '{open}' => $open ? ' open' : '',
        ]));
    }

    /**
     * Meta field as a search field - the fixed additional fields (depth, frontend user) and
     * the "more fields" of the global search (GLOBAL_EXTRA_FIELDS).
     *
     * @param string $blockClass  'warning' for a temporarily added field
     */
    private function _searchMetaField(string $name, array $filter, string $blockClass = ''): void
    {
        $value = $filter[$name] ?? '';
        $base = ['name' => $name, 'value' => $value, 'append' => true, 'blockClass' => $blockClass];

        switch ($name) {
            case 'depth':
                // depth relative to the parent record (modifier of filter[parent_id],
                // Cms::filter()): empty = all descendants, 1 = direct children …
                $this->backend->searchField($this->record, array_replace($base, [
                    'value' => is_scalar($value) ? (string)$value : '',
                    'colDef' => ['name' => 'depth', 'type' => 'chars', 'label' => '#record.field_search_depth'],
                    'note' => '#record.note_search_depth',
                ]));
                return;

            case 'status_id':
                $this->backend->searchField($this->record, array_replace($base, [
                    'colDef' => ['type' => 'integer', 'display_type' => 'select', 'label' => $this->cms->schema['status_id']['label']],
                    'options' => $this->_statusOptions(),
                ]));
                return;

            case 'instance_id':
                $options = [];
                $access = $this->backend->access();
                foreach ($this->instanceCache->instanceLabels() as $instanceId => $label) {
                    if (!$access->instance((int)$instanceId)) continue;
                    $options[(int)$instanceId] = $this->cms->textOrSnippet($label);
                }
                $this->backend->searchField($this->record, array_replace($base, [
                    'colDef' => ['type' => 'integer', 'display_type' => 'select', 'label' => $this->cms->schema['instance_id']['label']],
                    'options' => $options,
                ]));
                return;

            case 'needs_review':
                $this->backend->searchField($this->record, array_replace($base, [
                    'colDef' => ['type' => 'integer', 'display_type' => 'select', 'label' => $this->cms->schema['needs_review']['label']],
                    'options' => [1 => $this->cms->snippet('yes'), 0 => $this->cms->snippet('no')],
                ]));
                return;

            case 'template_id':
                $this->backend->searchField($this->record, array_replace($base, [
                    'colDef' => ['type' => 'id', 'display_type' => 'select', 'label' => $this->cms->schema['template_id']['label']],
                    'options' => $this->_allTemplateOptions(),
                ]));
                return;

            case 'user_id':
                // lookup on the profile areas (record list with area filter)
                $ids = $this->system->sanitizeIds((array)$value, true);
                $this->backend->searchField($this->record, array_replace($base, [
                    'value' => $ids,
                    'colDef' => ['type' => 'id', 'display_type' => 'lookup', 'label' => $this->cms->schema['user_id']['label']],
                    'template' => 'edit_form_lookup_foreign_table',
                    'contextMenu' => '',
                ]));
                foreach ($this->profileLookupReplace('user_id', 'filter', $ids, 0, $this->_profileAreaIds()) as $from => $to) {
                    $this->backend->formReplace($from, $to);
                }
                return;

            case 'owner_id':
                // lookup on the user list (as in the input mask)
                $ids = $this->system->sanitizeIds((array)$value, true);
                $this->backend->searchField($this->record, array_replace($base, [
                    'value' => $ids,
                    'colDef' => $this->cms->schema['owner_id'],
                    'template' => 'edit_form_lookup_foreign_table',
                    'contextMenu' => '',
                ]));
                $this->backend->formReplace('{src_id}', '0');
                $this->backend->formReplace('{src_field}', 'owner_id');
                $this->backend->formReplace('{search_url}', 'Admin/admin_list.php?src_field=owner_id');
                $this->backend->formReplace('{update_url}', 'Admin/ajax_lookup_preview.php?src_field=owner_id&prefix=filter');
                $this->backend->formReplace('{data_ids}', implode(',', $ids));
                $this->backend->formReplace('{max}', '');
                $this->backend->formReplace('{preview}',
                    $GLOBALS['app']->get(\Camindo\Core\Modules\Admin\AdminBackend::class)->lookupPreviewCreate('owner_id', $ids, 'filter', 0));
                return;
        }
    }

    /**
     * Selection "more fields": in the area the set-up fields that are not in
     * the search mask (visibility_search=0) and can be searched; in the global
     * search the meta fields GLOBAL_EXTRA_FIELDS.
     *
     * @param array $configured  virtual fields of the area (virtualSchema(…, false))
     * @return array  field name => label
     */
    private function _extraFieldOptions(array $configured, array $visibleFields, array $visibleMetaFields): array
    {
        $options = [];
        if ($this->areaId < 1) {
            foreach (self::GLOBAL_EXTRA_FIELDS as $name) {
                // instance only if there are several
                if ($name === 'instance_id' && !$this->instanceCache->hasMultipleInstances()) continue;
                $label = $this->cms->schema[$name]['label'] ?? $name;
                $options[$name] = $this->cms->textOrSnippet($label);
            }
            return $options;
        }
        foreach ($configured as $name => $colDef) {
            $name = (string)$name;
            if (in_array($name, $visibleFields, true) || in_array($name, $visibleMetaFields, true)) continue;
            if ($name === Field::FULLTEXT) continue;
            $type = (string)($colDef['type'] ?? 'text');
            $displayType = (string)($colDef['display_type'] ?? 'text');
            // not searchable: passwords, fields without their own column, embedded contents
            if ($type === 'password' || $displayType === 'password') continue;
            if (in_array($type, $this->cms->noColumnTypes, true)) continue;
            if (in_array($type, $this->cms->dependentTypes, true) && $displayType !== 'lookup') continue;
            // do not offer fields without a label (technical meta columns)
            if (empty($colDef['field_id']) && empty($colDef['label'])) continue;
            $options[$name] = $this->cms->textOrSnippet((string)($colDef['label'] ?? $name));
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    /**
     * Profile areas whose users may create records in the area
     * (area type ugc, details.profile_area_ids) - in the global search all
     * readable profile areas (RecordBackend::readableProfileAreaIds()).
     *
     * @return int[]
     */
    private function _profileAreaIds(): array
    {
        if ($this->areaId > 0) {
            if ((string)($this->areaData['type'] ?? '') !== 'ugc') return [];
            $details = $this->areaData['details'] ?? [];
            if (is_string($details)) $details = json_decode($details, true) ?: [];
            return $this->system->sanitizeIds((array)($details['profile_area_ids'] ?? []), true);
        }
        return $this->readableProfileAreaIds();
    }

    /**
     * Templates for template_id in the global search: the selectable and the
     * default templates of the readable content areas.
     *
     * @return array  template_id => Beschriftung
     */
    private function _allTemplateOptions(): array
    {
        $access = $this->backend->areaAccessSql(false, '`a`.`area_id`');
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT DISTINCT `t`.`template_id`, `t`.`label` FROM `template` `t`"
                . " WHERE `t`.`template_id` IN ("
                . "   SELECT `r`.`template_id` FROM `rel_area_template` `r` INNER JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id`"
                . "   WHERE `a`.`deleted` = 0" . $access
                . "   UNION SELECT `a`.`default_template_id` FROM `area` `a` WHERE `a`.`deleted` = 0" . $access
                . " ) ORDER BY `t`.`label` ASC"
            );
            $rows = $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
        $options = [];
        foreach ($rows as $row) {
            $options[(int)$row['template_id']] = $this->cms->textOrSnippet((string)$row['label']);
        }
        return $options;
    }

    /**
     * function=search: execute the search.
     *
     * Renders no result list, but closes the search layer and reloads the
     * list layer beneath it with the search parameters in the URL (see
     * class comment).
     *
     * json_encode escapes everything that could end the JavaScript literal;
     * substr(..., 1) only removes the opening quotation mark, the closing one
     * remains and ends the string below - which is why {$safeUri} stands there without
     * its own quotation marks.
     */
    private function _searchAction():void
    {

        // parameters (POST)
        $queryString = http_build_query($_POST);

        // wrap safely for JavaScript
        $safeParams = json_encode($queryString, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $safeUri = '"Record/record_list.php?' . substr($safeParams, 1);

        // reload the referrer layer with these parameters
        $this->backend->script("
            cApp.layers.close();
            cApp.layers.replace({$safeUri});
        ");
    }

    /**
     * Create the preview of a lookup search field when the mask is built for the FIRST time.
     *
     * Called from _searchForm() when the mask is displayed again with an already
     * set filter - the selected records should then be there again.
     *
     * The passed $ids are discarded and instead fetched from
     * $_REQUEST['filter'][{fieldname}]: the caller only knows the field_id, but the
     * filter is stored under the field name.
     *
     * @param int $srcField  id of the relation field
     * @param array $ids     not used (see above)
     */
    public function _sLookupInit(int $srcField, array $ids): string
    {
        if (!$fieldData = $this->record->fieldData($srcField)) {
            $this->cms->error('_sLookupInit() Exception: field not found.');
            $this->errors();
            return '';
        }

        $relFieldName = $fieldData['name'];
        $filterIds = (array)($_REQUEST['filter'][$relFieldName] ?? []);
        $ids = $this->system->sanitizeIds($filterIds, true);
        return $this->_sLookupPreviewCreate($fieldData, $ids);
    }

    /**
     * Ajax: update the preview of a lookup search field.
     *
     * Called by cApp.ui.slookupUpdate() after a checkmark was set or removed in the
     * selection list. Responds with the finished preview block, which the
     * JavaScript puts in place of the old one.
     *
     * The id list is filtered to digits and commas before it is split.
     *
     * Request: src_field (field_id), ids (comma list)
     */
    public function sLookupPreview(): void
    {
        $srcField = (int)($_REQUEST['src_field'] ?? 0);
        if (!$fieldData = $this->record->fieldData($srcField)) {
            $this->cms->error('sLookupPreview() Exception: field not found.');
            $this->errors();
            return;
        }

        $idString = preg_replace('/[^0-9,]/', '', (string)($_REQUEST['ids'] ?? ''));
        $ids = explode(',', $idString);
        echo $this->_sLookupPreviewCreate($fieldData, $ids);
    }

    /**
     * Render the preview block of a lookup search field.
     *
     * Common core of _sLookupInit() and sLookupPreview(). For each selected
     * record a row with its title, a remove link and a hidden field that carries the id -
     * the preview block IS therefore the search field; there is no visible
     * input field.
     *
     * The title is the content of the default field. This is determined per ROW: the
     * selected records can come from different areas with a pointer field and
     * have different default fields there.
     *
     * The order follows the passed id list and not the sorting of the
     * database - the editor selected them that way. For this the
     * original order is turned into a rank with array_flip() and sorted afterwards with uksort().
     *
     * @param array $fieldData  relation field data
     * @param array $ids        ids stored in the field
     */
    private function _sLookupPreviewCreate(array $fieldData, array $ids): string
    {   
        $srcField = (int)($fieldData['field_id'] ?? 0);
        $relFieldName = $fieldData['name'];
        if (empty($srcField)) return '';
        if (empty($ids)) return '';

        // load records
        $this->cms->select([
            'filter' => [
                'record_id' => $ids
            ],
            'limit' => 10
        ]);
        if ($this->db->num_rows < 1) return '';
        
        $records = [];
        while ($row = $this->record->fetch()) {
            // record_id as key
            $records[(int)$row['record_id']] = $row;
        }

        // sort the results by the original id order
        $flippedOrder = array_flip($ids); 
        uksort($records, function($a, $b) use ($flippedOrder) {
            return (int)$flippedOrder[$a] <=> (int)$flippedOrder[$b];
        });

        $rows = [];
        foreach ($records as $id => $data) {
            // default field text
            $areaId = (int)$data['area_id'];
            $defaultField = $this->backend->getDefaultField($areaId);
            $fieldName = $defaultField['name'];
            $fieldType =  $defaultField['type'] ?? 'text';
            $label = $this->backend->backendValue(
                $data[$fieldName] ?? '',
                ['type' => $fieldType]
            );

            $rows[] = $this->backend->template('record.slookup_table_row', [
                '{text}' => $label,
                '{data_id}' => $id,
                // The field name of the template can now be freely chosen ({var_name}) -
                // here it is always a search criterion, i.e. the 'filter' block.
                '{var_name}' => "filter[{$relFieldName}][]",
                '{unlink_url}' => "cApp.ui.slookupUnlink($id);",
            ]);
            $this->db->popResult();
        }

        // render table
        $table = $this->backend->template('record.slookup_table', [
            '{table_rows}' => implode("\n", $rows)
        ]);

        // render preview container
        $previewContainer = $this->backend->template('record.lookup_preview_container', [
            '{html}' => $table
        ]);

        return $previewContainer;
    }
}