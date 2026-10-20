<?php
/**
 * Common base of the backend classes of the module Record.
 *
 * Record is the largest module in the codebase; its user interface would not be
 * readable in one class. It is therefore split into nine classes, all of which inherit from here:
 *
 *     RecordBackendFolderList    tree of the content areas - the entry point
 *     RecordBackendList          record list of an area
 *     RecordBackendInfo          detail view
 *     RecordBackendMove          move to another area
 *     RecordBackendEdit          input mask, save, delete
 *     RecordBackendSearch        search mask and search execution
 *     RecordBackendUpload        file upload (Ajax)
 *     RecordBackendServerFile    file selection from the server directory
 *     RecordBackendBatch         batch processing
 *     RecordBackendExport        export (XML, CSV, DOCX)
 *     RecordBackendImport        import (XML, CSV, DOCX)
 *
 * The class is abstract: it has no entry point of its own, only
 * shared state and helper methods. Above all the constructor - otherwise each of the
 * subclasses would need the same ten-part dependency list.
 *
 * THE PAGE PARAMETERS ARE MORE INVOLVED HERE THAN ELSEWHERE
 * --------------------
 * _resolveParams() is the most important part of this class. A call in the module Record
 * can refer to very different things:
 *
 *     exactly one area          area_id set
 *     all areas                 nothing set (global search)
 *     a selection of areas      filter[area_id][] set
 *     the target areas of a     src_field set - then the areas come from the field
 *     link field                definition, not from the request
 *
 * The method reduces all of this to one common form and fills $areaId, $areaIds,
 * $areaFilter, $areaData and $params. Each subclass calls it first.
 *
 * LOOKUP OPERATION
 * --------------------
 * As in the module Template, the list serves a double purpose: as a normal result list and as
 * a selection window for a link field in another form.
 *
 *     src_field   field_id of the link field (here a number, unlike
 *                 TemplateBackend, where it is a field name)
 *     src_id      record_id of the record to be linked; 0 = call
 *                 from a search mask
 *
 * From src_field follows which records come into question at all: a 'relation'
 * field has exactly one target area, a 'pointer' field several.
 *
 * A special case is the meta field `parent_id` as lookup field: what is chosen is not a
 * linked record but the PARENT record. The target area is then the own
 * area, and nothing is written when clicked - the selection travels as
 * data[parent_id] with the form. _resolveParams() notes this in $srcIsParent.
 *
 * LIST COLUMNS AND LIST TEMPLATE
 * --------------------
 * Which fields of an area appear in the record list is determined by field.visibility_list
 * (RecordPresenter::listFields()); how a value looks there, RecordPresenter::
 * listValue() - status as a symbol, template_id/parent_id/position as plain text, everything
 * else via Backend::backendValue(). Besides RecordBackendList (and the
 * API, RecordApi::search()) this is also used by detailedText(),
 * which shows the same fields one below the other - in the detailed lookup preview
 * (RecordBackendEdit) and in the list of the last changed records
 * (RecordBackendFolderList).
 *
 * In addition, a frontend template can be chosen per area (`area`.`list_template_id`),
 * which RecordPresenter::listTemplateHtml() runs for each record (Page::renderRecord()) - as
 * the last column of the list or as the conclusion of the lookup preview.
 *
 * THE SELECTION LISTS ARE LIMITED
 * --------------------
 * _parentOptions() and _previousOptions() build selection lists over the records
 * of an area - there can be very many. Both therefore fetch at most
 * admin.list_max_datasets entries (default 200) and, if there are more, append a
 * disabled "…" entry instead of silently truncating the list.
 *
 * @see Camindo\Core\Modules\Record\Record  Dataset/model
 * @see Camindo\Core\Backend::areaData()     supplies the details on default and sort field
 * @see developer docs, chapter 05 (Backend development)
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\InstanceCache;
use Camindo\Core\Config;
use Camindo\Core\Backend;
use Camindo\Core\Crypt;
use Camindo\Core\Session;
use Camindo\Core\Database;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\MassDeletion;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Record\Record;

abstract class RecordBackend {
    protected int $id = 0;

    // Exactly ONE area - set if the call refers unambiguously to one.
    protected int $areaId = 0;

    protected int $srcId = 0;       // lookup call
    protected int $srcField = 0;    // lookup call
    protected int $straight = 0;    // lookup call

    // Lookup call from a FOREIGN form: src_field is then not a field_id,
    // but the name of a column that takes a record_id - e.g.
    // `instance`.`record_id` (the start page of a website). There is no
    // field definition from which target areas could be derived; which records
    // come into question is narrowed by the caller via the normal filters
    // (InstanceBackend: filter[instance_id]).
    protected string $srcFieldName = '';

    // Lookup call for the meta field parent_id: the parent record is chosen,
    // not a link. The target area is then the own area, and nothing is
    // written when clicked (see RecordBackendEdit).
    protected bool $srcIsParent = false;

    // target areas of a 'pointer' field (several possible)
    protected array $areaIds = [];  // relAreaIds of a pointer field

    // area restriction of the search; with a pointer call the intersection
    // of the search input and the target areas of the field.
    protected array $areaFilter = [];

    // The parameters that must be appended to every onward link so that
    // the next layer knows the context (area, lookup call).
    protected string $params = '';

    protected ?array $data = [];
    protected ?array $areaData = [];
    protected string $function = '';

    // if it is about ONE area WITH an input field parent_id, e.g. record list / info
    protected bool $hasParentId = false;

    public function __construct(
        protected Config $config,
        protected Backend $backend,
        protected Crypt $crypt,
        protected Session $session,
        protected Database $db,
        protected System $system,
        protected Cms $cms,
        protected MassDeletion $massDeletion,
        protected Area $area,
        protected Record $record,
        protected InstanceCache $instanceCache,
        protected RecordService $service,
        protected RecordPresenter $presenter,
    )
    {}


    /**
     * Create the headline - depending on $this->function.
     *
     * In the detail view and when deleting, the record itself appears above it. A record
     * does not have a "title" by default: which field applies is set by
     * the area with default_field_id. Therefore first fetch the default field and
     * then insert its content; if none is set, the record_id remains.
     *
     * The content comes from an input field and is escaped.
     *
     * @param bool $displayIcon  true: prepend status icon
     */
    protected function headline(bool $displayIcon = false) {
        $icon = '';
        if (in_array($this->function, ['delete', 'info'])) {
            // Default-Feld gesondert behandeln
            $defaultField = $this->backend->getDefaultField($this->areaId);
            $defaultFieldName = $defaultField['name'];
            $label = htmlspecialchars($this->data[$defaultFieldName] ?? $this->data['record_id']);
            if ($displayIcon) {
                $statusId = (int)($this->data['status_id'] ?? 0);
                $icon = $this->backend->teaser('status', $statusId, icon:true, label:false) . ' ';
            }
        }
        else {
            $label = $this->cms->snippet('record.headline_record_info');
        }
        return $this->backend->template('headline1', [
            '{text}' => $icon . $label,
        ]);
    }

    // ====================
    // Additional rights of the module (install/settings.php 'access', developer docs, chapter 11 (Permissions))

    /**
     * Export/import formats the group may use (RecordService::transferTypes()).
     *
     * @param string $direction  'export' | 'import'
     * @return string[]  subset of RecordExport::TYPES in their order
     */
    protected function allowedTypes(string $direction): array
    {
        return $this->service->transferTypes($direction);
    }

    /**
     * Media import into the area of this page (RecordBackendMediaImport)? Additional right
     * import_media, right to create, an upload field (without thumbnails) - and not a
     * dependent area (area.dependent: its records are only created via a field of type link).
     */
    protected function mediaImportAllowed(): bool
    {
        if ($this->areaId < 1 || (int)($this->areaData['dependent'] ?? 0) === 1) return false;
        $access = $this->backend->access();
        if (!$access->moduleRight('Record', 'import_media') || !$access->areaCreate($this->areaId)) return false;
        foreach ($this->record->virtualSchema($this->areaId, false) as $colDef) {
            if ((string)($colDef['type'] ?? '') === 'file' && empty($colDef['parent_field_id'])) return true;
        }
        return false;
    }

    /**
     * May the group use batch processing (additional right batch)?
     */
    protected function batchAllowed(): bool
    {
        return $this->backend->access()->moduleRight('Record', 'batch');
    }

    /**
     * Output error messages from Cms::errors as a red box.
     */
    protected function errors(): void
    {
        echo $this->backend->template('alert_danger',[
            '{text}' => $this->cms->errorMsg()
        ]);
    }

    /**
     * Read the page parameters and reduce them to a common form.
     *
     * Called first by each subclass and fills $id, $areaId,
     * $areaIds, $areaFilter, $srcField, $srcId, $areaData and $params.
     *
     * In order:
     *
     *  1. Id and area from the request. The area_id may come both directly and as
     *     data[area_id] - the input form sends it along in the data block.
     *     If a record id is given, the area of the record applies.
     *  2. With a lookup call (src_field) the FIELD DEFINITION wins over the
     *     request: a 'relation' field has exactly one target area, a 'pointer' field
     *     several. The editor should not be able to select anything from a link field
     *     that the field cannot accept at all.
     *  3. Load area data - via Backend::areaData(), not via Area::load():
     *     the method additionally supplies name and type of the default field as well as the
     *     sort field, which are needed further below for the selection lists.
     *  4. Without a unique area a substitute label is set ("All
     *     content areas" or "{n} areas").
     *  5. Assemble $params - see the property comment.
     *
     * With a pointer field WITH search restriction the intersection is formed: both
     * apply, the target areas of the field and the selection of the editor.
     *
     * If something fails, $this->function is set to 'errors' - the calling
     * method must check this.
     */
    protected function _resolveParams(): void
    {
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->areaId = (int)($_REQUEST['data']['area_id'] ?? $_REQUEST['area_id'] ?? 0);

        // lookup calls:
        // src_field is either a field_id (link field of an area) or
        // a column name from a foreign form - see $srcFieldName.
        $srcFieldRaw = trim((string)($_REQUEST['src_field'] ?? ''));
        if ($srcFieldRaw !== '' && !ctype_digit($srcFieldRaw)) {
            $this->srcFieldName = preg_replace('/[^a-z0-9_]/', '', strtolower($srcFieldRaw));
            $this->srcField = 0;
        }
        else {
            $this->srcField = (int)$srcFieldRaw;
            $this->srcFieldName = '';
        }
        $this->srcId    = (int)($_REQUEST['src_id'] ?? 0);

        // indicator whether the "Create" function was called directly from a lookup
        $this->straight = (int)($_REQUEST['straight'] ?? 0);

        // $filter[area_ids][]
        $this->areaFilter = $this->system->sanitizeIds(
            (array)($_REQUEST['filter']['area_id'] ?? [])
        );
        $_REQUEST['filter']['area_id'] = $this->areaFilter;

        // relation / pointer field given -> determine target area(s)
        // --------------------
        $this->areaIds = [];
        $this->srcIsParent = false;
        if (!empty($this->srcField)) {
            $srcFieldData = $this->record->fieldData($this->srcField);
            $type = $srcFieldData['type'];
            if (($srcFieldData['name'] ?? '') === 'parent_id' && $type === 'id') {
                // selection of the parent record: the candidates are in the same
                // area as the record being edited
                $this->srcIsParent = true;
                $this->areaId = (int)$srcFieldData['area_id'];
            }
            elseif (str_starts_with($type, 'relation')) {
                // one target area
                $this->areaId = (int)$srcFieldData['rel_area_id'];
            }
            elseif (in_array($type, $this->cms->multiAreaTypes, true)) {
                // target areas (pointer, link) - deleted ones (trash) or missing ones are skipped
                $relAreaIds = $this->record->getRelAreaIds($this->srcField);
                $this->areaIds = array_values(array_filter($relAreaIds,
                    fn($areaId) => $this->backend->areaData((int)$areaId) !== null));
                if (!empty($relAreaIds) && empty($this->areaIds)) {
                    // otherwise the lookup would list all areas
                    $this->cms->error($this->cms->snippet('area.error_rel_area_not_found'));
                    $this->function = 'errors';
                    return;
                }
                // exactly one?
                if (count($this->areaIds) === 1) $this->areaId = (int)$this->areaIds[0];
            }
            else {
                $this->cms->error('list() Exception: src_field is not of type relation.');
                $this->function = 'errors';
                return;
            }
        }

        // record given: its area applies, not the request parameter area_id -
        // references to the detail view (Backend::teaser(), "Used by" …) often know
        // only the id; without area data the language tabs, for example, would be missing
        if ($this->id > 0) {
            $this->db->pushResult();
            $this->db->query("SELECT `area_id` FROM `record` WHERE `record_id` = ?", 'i', [$this->id]);
            $recordAreaId = (int)($this->db->fetchNum()[0] ?? 0);
            $this->db->popResult();
            if ($recordAreaId > 0) $this->areaId = $recordAreaId;
        }

        // determine area label
        // --------------------
        $this->areaData = [];
        if ($this->areaId > 0) {
            // one area -> load data
            // Backend::areaData() supplies additional info on the default field and sort field
            if (!$this->areaData = $this->backend->areaData($this->areaId)) {
                // deleted (trash) or missing - e.g. the target area of a relation field
                $this->cms->error($this->cms->snippet(!empty($this->srcField)
                    ? 'area.error_rel_area_not_found' : 'area.error_area_not_found'));
                $this->function = 'errors';
                return;
            }
        }
        else {
            if (!empty($this->areaIds)) {
                // several target areas from a pointer field
                // -> form the intersection with areaFilter
                if (empty($this->areaFilter)) {
                    // (no areaFilter given -> only areaIds)
                    $this->areaFilter = $this->areaIds;
                }
                else {
                    // intersection
                    $this->areaFilter = array_intersect($this->areaFilter, $this->areaIds);
                }
            }
            if (empty($this->areaFilter)) {
                // "all areas"
                $this->areaData = ['label' => '#area.all_content_areas'];
            }
            else {
                // "{count} areas"
                $this->areaData = [
                    'label' => $this->cms->snippet('area.x_areas', ['count' => count($this->areaFilter) ])
                ];
            }
        }

        // page parameters to pass on
        // --------------------
        if ($this->areaId > 0) {
            $this->params = 'area_id=' . $this->areaId;
        }
        if ($this->hasLookup()) {
            $this->params .= '&src_field=' . ($this->srcFieldName !== '' ? $this->srcFieldName : $this->srcField);
            $this->params .= '&src_id=' . $this->srcId;
        }
    }

    /**
     * Ajax: update the preview of a FOREIGN record lookup
     * (backend/ajax_ext_lookup.php).
     *
     * Counterpart to TemplateBackend::lookupPreview(), only for records: called
     * by cApp.ui.slookupUpdate() after a checkmark was set or removed in the record list.
     * Nothing is written - the response contains the hidden field that
     * goes with the calling form.
     *
     * Request: src_field (column name, required), ids (comma list), max, function,
     *          prefix ('filter' for the search mask, otherwise 'data')
     */
    public function extLookupPreview(): void
    {
        $srcField = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_REQUEST['src_field'] ?? '')));
        $max      = (int)($_REQUEST['max'] ?? 0);
        if ($srcField === '') {
            $this->cms->error('extLookupPreview() Exception: parameter src_field is empty.');
            $this->errors();
            return;
        }
        $idString = preg_replace('/[^0-9,]/', '', (string)($_REQUEST['ids'] ?? ''));
        $ids = explode(',', $idString);

        // prefix=filter: search field of the search mask (filter[...]), otherwise form field
        $prefix = ((string)($_REQUEST['prefix'] ?? '') === 'filter') ? 'filter' : 'data';
        echo $this->extLookupPreviewCreate($srcField, $ids, $prefix, $max);

        if ((string)($_REQUEST['function'] ?? '') === 'link') {
            $this->backend->script("cApp.ui.lookupFocus('$srcField', true);");
        }
    }

    /**
     * Lookup of profile records (website users) from a foreign form: the
     * replacements for the template edit_form_lookup_foreign_table. Searched in the
     * record list with src_field = $srcField and a filter on the profile areas;
     * the list passes src_field on as a column name (also through its search mask),
     * "Apply" writes the preview block back via ajax_ext_lookup.php.
     * Used by the search mask (meta field user_id) and by the module Privacy
     * (PrivacyBackend::profilePickerHtml()).
     *
     * @param string $srcField  column name in the calling form ([a-z0-9_])
     * @param string $prefix    'data' (input form) or 'filter' (search mask)
     * @param int[]  $ids       selected profiles
     * @param int    $max       1 = single selection, 0 = any number
     * @param int[]  $areaIds   profile areas in which is searched
     * @return array            placeholder => value ({src_id}, {src_field},
     *                          {search_url}, {update_url}, {data_ids}, {max}, {preview})
     */
    public function profileLookupReplace(string $srcField, string $prefix, array $ids, int $max, array $areaIds): array
    {
        $srcField = preg_replace('/[^a-z0-9_]/', '', strtolower($srcField));
        $prefix = $prefix === 'filter' ? 'filter' : 'data';
        $ids = $this->system->sanitizeIds($ids, true);
        $areaParams = '';
        foreach ($areaIds ?: [0] as $areaId) {
            $areaParams .= '&filter[area_id][]=' . (int)$areaId;
        }
        return [
            '{src_id}' => '0',
            '{src_field}' => $srcField,
            '{search_url}' => 'Record/record_list.php?src_field=' . $srcField . $areaParams,
            '{update_url}' => 'Record/ajax_ext_lookup.php?src_field=' . $srcField . '&prefix=' . $prefix,
            '{data_ids}' => implode(',', $ids),
            '{max}' => $max > 0 ? (string)$max : '',
            '{preview}' => $this->extLookupPreviewCreate($srcField, $ids, $prefix, $max),
        ];
    }

    /** Profile areas the group may read (excluding deleted ones), in tree order */
    public function readableProfileAreaIds(): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `area_id` FROM `area` WHERE `type` = 'profile' AND `deleted` = 0"
                . $this->backend->areaAccessSql() . " ORDER BY `lft`");
            // one column: fetchColumn() returns the values as a list
            return array_map('intval', $this->db->fetchColumn());
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Render the preview block of a foreign record lookup.
     *
     * Common core of extLookupPreview() and the calling form. For each
     * selected record a row is created with label, remove link and
     * a hidden field that carries the id - the preview block IS the
     * input field.
     *
     * It is labeled with the default field of the area in which the record lies
     * (Backend::getDefaultField()); without content the id remains as a last resort.
     *
     * @param string $srcField  name of the column in the calling form (e.g. 'record_id')
     * @param array  $ids       selected record_ids
     * @param string $prefix    name of the form field ('data'/'filter')
     * @param int    $max       1 = single selection (no [] in the field name)
     */
    public function extLookupPreviewCreate(string $srcField, array $ids, string $prefix, int $max): string
    {
        if ($srcField === '') return '';
        $ids = $this->system->sanitizeIds($ids, true);
        if (empty($ids)) return '';
        if ($max === 1) $ids = [reset($ids)];

        $this->db->pushResult();
        $this->cms->select([
            'filter' => ['record_id' => $ids],
            'limit'  => count($ids)
        ]);
        $records = [];
        while ($row = $this->cms->fetch()) {
            $records[(int)$row['record_id']] = $row;
        }
        $this->db->popResult();
        if (empty($records)) return '';

        // keep the order of the passed ids - the editor selected them in this order
        $rank = array_flip($ids);
        uksort($records, fn($a, $b) => ((int)($rank[$a] ?? 0)) <=> ((int)($rank[$b] ?? 0)));

        // field name in the form: as an array for multiple selection
        $varName = "{$prefix}[{$srcField}]" . ($max === 1 ? '' : '[]');

        $rows = [];
        foreach ($records as $recordId => $data) {
            $this->db->pushResult();
            $defaultField = $this->backend->getDefaultField((int)($data['area_id'] ?? 0));
            $label = $this->backend->backendValue(
                $data[$defaultField['name']] ?? '',
                ['type' => $defaultField['type'] ?? 'text']
            );
            $this->db->popResult();

            $rows[] = $this->backend->template('record.slookup_table_row', [
                '{data_id}'    => $recordId,
                '{text}'       => $label,
                '{var_name}'   => $varName,
                '{unlink_url}' => "cApp.ui.slookupUnlink($recordId);"
            ]);
        }

        return $this->backend->template('record.lookup_preview_container', [
            '{html}' => $this->backend->template('record.slookup_table', [
                '{table_rows}' => implode("\n", $rows)
            ])
        ]);
    }

    /**
     * Is the call a lookup - regardless of whether via field_id or via a column name?
     */
    protected function hasLookup(): bool
    {
        return ($this->srcField > 0 || $this->srcFieldName !== '');
    }

    /**
     * src_field as a JavaScript argument for the cApp.ui.*lookup* calls.
     * A field_id passes through as a number, a column name must be in quotes.
     */
    protected function srcFieldJs(): string
    {
        return ($this->srcFieldName !== '')
            ? "'" . $this->srcFieldName . "'"
            : (string)$this->srcField;
    }


    /**
     * Selection list of the statuses (id => label).
     *
     * Used in the input mask AND the search mask. Database::fetchPairs() returns the
     * two-column query directly as a key-value array, in which array_map() still resolves
     * #-snippets.
     *
     * @param bool $settableOnly  only the statuses the group may set
     *                            (Access::statusWrite()) - input mask and
     *                            batch processing; the search offers all
     */
    protected function _statusOptions(bool $settableOnly = false): array
    {
        $this->db->query("SELECT `status_id`,`label` FROM `status` ORDER BY `position` ASC");
        $options = array_map(
            fn(string $value) => $this->cms->textOrSnippet($value),
            $this->db->fetchPairs()
        );
        if (!$settableOnly) return $options;
        $access = $this->backend->access();
        return array_filter($options, fn($statusId) => $access->statusWrite((int)$statusId), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Selection list of the templates that may be used in the area.
     *
     * Not all templates, only those entered in `rel_area_template` as 'selectable'
     * (maintained via TemplateBackend::infoAccess()). The
     * default template of the area is always included - Area::hookOnSaved() always
     * sets selectable for it.
     *
     * The first option is empty and means "use the default of the area".
     * A template without label is listed with its file name.
     */
    protected function _templateOptions(): array
    {
        // first option: "default"
        $options = ['' => $this->cms->snippet('record.default_template_label')];
        $sql = "SELECT `template`.`template_id`, COALESCE(NULLIF(`template`.`label`, ''), `template`.`name`) AS `title`"
        ." FROM `rel_area_template` `r`"
        ." INNER JOIN `template` ON `r`.`template_id` = `template`.`template_id`"
        ." WHERE `r`.`area_id` = ? AND `r`.`selectable` = 1"
        ." ORDER BY `title` ASC";
        $this->db->query($sql, 'i', [$this->areaId]);
        $assigendOptions = $this->db->fetchPairs();
        // do not list the default template again
        $defaultTemplateId = (int)($this->areaData['default_template_id'] ?? 0);
        unset($assigendOptions[$defaultTemplateId]);
        return $options + $assigendOptions;
    }

    /**
     * Selection list "parent record".
     *
     * All records of the area except the own one. If the area has a tree
     * (input field `parent_id`, Record::isTreeArea()), the list is in tree order
     * (`lft`) and each title is indented by `depth` - with
     * non-breaking spaces and the branch character, as in the record list
     * (RecordBackendList::list(), table_column_tree_x): ordinary spaces
     * would collapse in an <option>.
     *
     * '!!' before the record_id is the filter syntax for "not equal" (see
     * developer docs, chapter 04 (Select and filter)). origin_id is excluded here, if
     * present - when editing one works on a working copy, but what is meant is
     * the original.
     *
     * Otherwise sorted by the sort field of the area, displaying the content of
     * its default field - both are already in $areaData thanks to Backend::areaData().
     *
     * With more than admin.list_max_datasets hits (default 200) the list
     * is truncated and receives a disabled "…" entry appended: a
     * selection list with thousands of entries would be unusable anyway. That is why
     * limit+1 is queried - only this way can it be recognized whether there are more.
     */
    protected function _parentOptions(): array
    {
        // only available if areaId is defined
        if (empty($this->areaId)) return [];

        $limit = $this->config->get('admin.list_max_datasets') ?? 200;

        // $this->areaData was loaded with Backend::areaData() and therefore
        // already contains the name and type of the default field, as well as the name of the sort field
        $defaultFieldName = (string)$this->areaData['default_field_name'];
        $defaultFieldType = (string)$this->areaData['default_field_type'];
        $sortFieldName = (string)$this->areaData['sort_field_name'];
        $sortOrder = $this->areaData['sort_order'] ?? 'DESC';
        // tree: in tree order, indented (see method comment)
        $isTree = $this->record->isTreeArea($this->areaId);
        if ($isTree) {
            $sortFieldName = 'lft';
            $sortOrder = 'ASC';
        }
        $this->cms->select([
            'filter' => [
                'area_id' => $this->areaId,
                'record_id' => '!!' . ($this->data['origin_id'] ?: $this->id)
            ],
            'sort' => $sortFieldName,
            'order' => $sortOrder,
            'limit' => $limit + 1,
        ]);
        $numRows = $this->db->num_rows;

        $options = ['' => '---'];
        while ($data = $this->cms->fetch()) {
            $label = $this->backend->backendValue(
                $data[$defaultFieldName] ?? '',
                ['type' => $defaultFieldType]
            );
            $depth = $isTree ? (int)($data['depth'] ?? 0) : 0;
            $indent = $depth > 0 ? str_repeat('&nbsp;&nbsp;&nbsp;', $depth) . '└ ' : '';
            $options[$data['record_id']] = $indent . $label;
        }
        if ($numRows > $limit) {
            $options['disabled'] = $this->cms->snippet('ellipsis_spoken');
        }
        return $options;
    }

    /**
     * Selection list "preceding record" - the input aid for `position`.
     *
     * The editor chooses the predecessor; the position is stored from it
     * (TreeManagerTrait::calculatePosition()). The first entry means
     * "to the start".
     *
     * Only the SIBLINGS under $parentId are listed - i.e. the direct children,
     * not the whole branch: 'depth' => 1 is the modifier for the
     * parent_id filter (developer docs, chapter 04 (Select and filter)). $parentId = 0 means
     * the root level of the area; the filter value for this is 'null', which
     * Cms::buildParentCondition() reads as "parent_id IS NULL".
     *
     * Sorted by `position ASC` and not by the sort field of the area:
     * the list shows the order in which the record is inserted - any
     * other sorting would be pointless here.
     *
     * Otherwise as _parentOptions(): own record excluded ('!!'),
     * label from the default field of the area, limited to
     * admin.list_max_datasets with a "…" entry.
     *
     * @param int $parentId  parent record or 0 (root level)
     */
    protected function _previousOptions(int $parentId = 0): array
    {
        // only available if areaId is defined
        if (empty($this->areaId)) return [];

        $limit = $this->config->get('admin.list_max_datasets') ?? 200;

        // $this->areaData was loaded with Backend::areaData() and therefore
        // already contains the name and type of the default field
        $defaultFieldName = (string)$this->areaData['default_field_name'];
        $defaultFieldType = (string)$this->areaData['default_field_type'];
        $filter = [
            'area_id' => $this->areaId,
            // when editing, $this->id is the working copy - what is meant is the original
            'record_id' => '!!' . (($this->data['origin_id'] ?? 0) ?: $this->id),
            // 'null' = root level (parent_id IS NULL); an empty string is not suitable
            // for this, Cms::normalizeValues() discards it
            'parent_id' => $parentId > 0 ? $parentId : 'null',
            // only direct children, not the whole branch
            'depth' => 1
        ];
        $this->cms->select([
            'filter' => $filter,
            'sort' => 'position',
            'order' => 'ASC',
            'limit' => $limit + 1
        ]);
        $numRows = $this->db->num_rows;

        $options = ['' => $this->cms->snippet('position_at_start')];
        while ($data = $this->cms->fetch()) {
            $label = $this->backend->backendValue(
                $data[$defaultFieldName] ?? '',
                ['type' => $defaultFieldType]
            );
            $options[$data['record_id']] = $label;
        }
        if ($numRows > $limit) {
            $options['disabled'] = $this->cms->snippet('ellipsis_spoken');
        }
        return $options;
    }

    /**
     * Statuses as entries for a selection menu, optionally with symbol.
     *
     * Unlike _statusOptions(), this method returns finished HTML (symbol in the
     * status color plus label) - needed for the quick filter menu in the
     * column header of the list.
     *
     * pushResult()/popResult() bracket the query: the method is called in the middle of a
     * running result loop and would otherwise overwrite its result.
     */
    protected function _statusDropdownItems(bool $icon = false): array {
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `status` ORDER BY `position` ASC");
        $items = [];
        while ($status = $this->db->fetch()) {
            // status icon + label
            if ($icon) {
                $iconHtml = $this->backend->template('status.icon_status', [
                    '{icon}' => $status['icon'],
                    '{color_class}' => $status['color_class']
                ])
                . ' ';
            }
            else {
                $iconHtml = '';
            }
            $label = $iconHtml . $this->cms->textOrSnippet($status['label']);
            $items[$status['status_id']] = $label;
        }
        $this->db->popResult();
        return $items;
    }

    /**
     * Detailed display of a record - in the lookup preview
     * (details.lookup_detailed, RecordBackendEdit) and in the list of the last
     * changed records (RecordBackendFolderList): the list fields of its area one below the other (RecordPresenter::listFields() /
     * listValue(), the same as in the record list, shortened), the default field
     * in bold; then the output of the list template of the area, if one is
     * selected (listTemplateHtml()). From the second entry on with spacing
     * (record.lookup_table_row_div).
     *
     * With pointer fields and in the list of the last changed, each
     * record may come from a different area - the schema is therefore fetched
     * once per area and kept for the call.
     *
     * The caller brackets with pushResult()/popResult() (see listValue()).
     *
     * @param array $data  record (Record::fetch())
     */
    protected function detailedText(array $data): string
    {
        static $schemas = [];
        $areaId = (int)($data['area_id'] ?? 0);
        if (!isset($schemas[$areaId])) {
            $schemas[$areaId] = $this->record->virtualSchema($areaId, false);
        }
        $schemaVirtual = &$schemas[$areaId];

        $items = [];
        foreach ($this->presenter->listFields($schemaVirtual) as $name) {
            $value = $this->presenter->listValue($name, $data, $schemaVirtual);
            if ((int)($schemaVirtual[$name]['default_field'] ?? 0) === 1) {
                $value = '<strong>' . $value . '</strong>';
            }
            $items[] = $value;
        }
        $templateHtml = $this->presenter->listTemplateHtml($areaId, $data);
        if ($templateHtml !== '') $items[] = $templateHtml;

        $html = '';
        foreach ($items as $i => $item) {
            $html .= ($i === 0)
                ? '<div>' . $item . '</div>'
                : $this->backend->template('record.lookup_table_row_div', ['{text}' => $item]);
        }
        return $html;
    }

    /**
     * Does the area show multilingual fields on tabs? (area.details.tabs)
     */
    protected function _languageTabs(): bool
    {
        $details = $this->areaData['details'] ?? [];
        if (is_string($details)) $details = json_decode($details, true) ?: [];
        return (int)($details['tabs'] ?? 0) === 1;
    }

    /**
     * Languages of the instance to which the area belongs (primary language first).
     *
     * @return array  language_id => language row (InstanceCache::languages())
     */
    protected function _recordLanguages(): array
    {
        $instanceId = (int)($this->areaData['instance_id'] ?? 0);
        if ($instanceId < 1 && $this->areaId > 0) {
            $areaData = $this->backend->areaData($this->areaId) ?? [];
            $instanceId = (int)($areaData['instance_id'] ?? 0);
        }
        return $this->instanceCache->languages($instanceId);
    }
}