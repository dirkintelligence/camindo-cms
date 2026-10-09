<?php
/**
 * Export of records - the masks (extension for RecordBackend).
 *
 * Entry points (scripts in backend/):
 *
 *     record_export.php    -> export()    mask, run, result
 *     record_download.php  -> download()  deliver the zip file from var/export
 *
 * Reachable via the menu (more_vert) on the right of the record list (global: in the
 * folder list); the call opens its own layer above the list
 * (cApp.layers.add) and carries the page parameters of the list
 * (cApp.layers.queryString(): area_id, filter, sort, ...). The work is done by
 * RecordExport.
 *
 * NAVIGATION: back to the list from the layer only works with cApp.layers.close();
 * switching tabs replaces the layer with cApp.layers.replace().
 *
 * THREE TABS, ONE SCRIPT (type)
 * --------------------
 *     xml    backup format, only setting: export upload files as well
 *     csv    CSV settings (header row, separator, enclosure character, line break),
 *            languages of the instance, fields with expandable subfields
 *            (RecordExport::csvFields())
 *     docx   translation: source and target language, all records in one file
 *            or one record per file, multilingual fields
 *
 * Above all tabs the scope: the last search result (if the list was filtered,
 * preselected) or all records of the area - as with
 * batch processing, the filter parameters travel along as a query string (hidden field
 * 'query'); the ids are determined by RecordBatch::collectIds() only when running.
 *
 * Below each mask the option background process (from
 * admin.export_background_threshold records fixed): the download link then comes
 * as an internal message. Otherwise function = 'run' executes in the request and shows the
 * result with the download link (opens record_download.php in a new
 * window - a zip file cannot be loaded into the layer) and the note
 * how long it is valid. The file is deleted on download, otherwise after the
 * retention period has expired (RecordExport::cleanup()).
 *
 * @see Camindo\Core\Modules\Record\RecordExport        the work
 * @see Camindo\Core\Modules\Record\RecordBackendBatch  same pattern for the scope
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\MassDeletion;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Area\Area;

class RecordBackendExport extends RecordBackend {

    /** filter parameters of the list (RecordBatch::filterParams()) */
    private array $filterParams = [];
    /** the same as query string for the hidden fields */
    private string $query = '';
    /** query string of the list (hidden field, for switching tabs) */
    private string $listQuery = '';
    /** 'filter' (last search result) | 'all' */
    private string $scope = 'all';
    private string $type = 'xml';
    /** formats the group may export (allowedTypes()) */
    private array $allowedTypes = [];
    private int $count = 0;
    private bool $hasFilter = false;

    public function __construct(
        Config $config,
        Backend $backend,
        Crypt $crypt,
        Session $session,
        Database $db,
        System $system,
        Cms $cms,
        MassDeletion $massDeletion,
        Area $area,
        Record $record,
        InstanceCache $instanceCache,
        RecordService $service,
        RecordPresenter $presenter,
        private RecordBatch $batch,
        private RecordExport $export,
    )
    {
        parent::__construct($config, $backend, $crypt, $session, $db, $system, $cms, $massDeletion, $area, $record, $instanceCache, $service, $presenter);
    }

    /**
     * Export (backend/record_export.php).
     *
     * Request: area_id, filter/filterop/filtermode (from the list) or query (when
     *          running), list_query, type (xml|csv|docx), function ('' | run),
     *          scope, files, data[csv][...], languages[], fields[], data[docx][from|to|split]
     */
    public function export(): void
    {
        echo $this->backend->template('container_header');
        $this->function = 'list';
        $this->_resolveParams();
        $function = (string)($_REQUEST['function'] ?? '');

        // additional rights: allowed formats (XML superuser only)
        $allowedTypes = $this->allowedTypes('export');
        if (empty($allowedTypes)) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        // Global (start page of the module): XML only, selection of areas
        if (!empty($_REQUEST['global']) && $this->areaId < 1 && $this->function === 'list') {
            if (!in_array('xml', $allowedTypes, true)) {
                echo $this->backend->access()->deniedHtml();
                echo $this->backend->template('container_footer');
                return;
            }
            $this->_exportGlobal($function);
            echo $this->backend->template('container_footer');
            return;
        }

        if ($this->function !== 'list' || $this->areaId < 1) {
            if ($this->areaId < 1) $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }
        // read right of the user group on the area
        if (!$this->backend->access()->areaRead($this->areaId)) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        // filter parameters: on the call from the list or tab switch from the
        // request, when running from 'query'
        if ($function === '') {
            $this->filterParams = $this->batch->filterParams($_REQUEST);
            $listKeys = array_intersect_key($_REQUEST, array_flip(['area_id', 'filter', 'filterop', 'filtermode', 'sort', 'order', 'skip']));
            $this->listQuery = http_build_query($listKeys);
        }
        else {
            parse_str((string)($_REQUEST['query'] ?? ''), $parsed);
            $this->filterParams = $this->batch->filterParams($parsed);
            $this->listQuery = (string)($_REQUEST['list_query'] ?? ('area_id=' . $this->areaId));
        }
        $this->query = http_build_query($this->filterParams);
        $this->hasFilter = $this->batch->hasFilter($this->filterParams);

        $this->type = (string)($_REQUEST['type'] ?? 'xml');
        if (!in_array($this->type, $allowedTypes, true)) $this->type = $allowedTypes[0];
        $this->allowedTypes = $allowedTypes;
        $this->scope = ($this->hasFilter && (string)($_REQUEST['scope'] ?? 'filter') === 'filter') ? 'filter' : 'all';
        $this->count = $this->batch->count($this->areaId, $this->scope === 'filter' ? $this->filterParams : []);

        // Bread crum navi, area, H1
        $this->backend->setNaviLabel('#record.headline_export');
        echo $this->backend->template('record.prefix_content_area', [
            '{text}' => str_replace('{area_label}', $this->cms->textOrSnippet((string)$this->areaData['label']), $this->cms->snippet('area.headline_area_label'))
        ]);
        echo $this->backend->headline('#record.headline_export');

        if ($function === 'run') {
            $this->_exportRun();
            echo $this->backend->template('container_footer');
            return;
        }

        // tabs (only the allowed formats)
        $this->backend->tabBarReset();
        foreach ($this->allowedTypes as $type) {
            $link = "cApp.layers.replace('Record/record_export.php?{$this->listQuery}&type={$type}');";
            $this->backend->tabAdd('#record.tab_export_' . $type, $type === $this->type ? '' : $link, $type === $this->type);
        }
        echo $this->backend->tabBar();

        match ($this->type) {
            'xml'  => $this->_exportFormXml(),
            'csv'  => $this->_exportFormCsv(),
            'docx' => $this->_exportFormDocx(),
        };
        echo $this->backend->template('container_footer');
    }

    // ====================
    // Masken

    /**
     * Scope: selection (if the list was filtered) or a note.
     */
    private function _scopeHtml(): string
    {
        if ($this->hasFilter) {
            $countFilter = $this->batch->count($this->areaId, $this->filterParams);
            $countAll = $this->batch->count($this->areaId, []);
            return $this->backend->template('record.batch_scope_choice', [
                '{count_filter}' => $this->cms->snippet('record.batch_scope_filter', ['count' => $countFilter]),
                '{count_all}' => $this->cms->snippet('record.batch_scope_all', ['count' => $countAll]),
            ]);
        }
        return $this->backend->template('record.batch_scope_note', [
            '{count}' => $this->cms->snippet('record.batch_scope_all', ['count' => $this->count]),
        ]);
    }

    /**
     * The hidden fields that accompany each form.
     */
    private function _exportHidden(): void
    {
        $this->backend->formHidden('function', 'run');
        $this->backend->formHidden('type', $this->type);
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('query', $this->query);
        $this->backend->formHidden('list_query', $this->listQuery);
        if (!$this->hasFilter) $this->backend->formHidden('scope', 'all');
    }

    /**
     * Options below each mask: background (checkbox or - from the threshold on -
     * a notice with a fixed hidden field), plus the retention period of the file.
     */
    private function _exportOptions(): string
    {
        if ($this->export->needsBackground($this->count)) {
            $background = $this->backend->template('record.batch_option_background_fixed', [
                '{threshold}' => $this->export->backgroundThreshold(),
            ]);
        }
        else {
            $background = $this->backend->template('record.export_option_background', [
                '{checked}' => !empty($_REQUEST['background']) ? ' checked' : '',
            ]);
        }
        return $this->backend->template('record.export_options', [
            '{count}' => $this->cms->snippet('record.batch_scope_all', ['count' => $this->count]),
            '{background}' => $background,
            '{hours}' => $this->export->retentionHours(),
        ]);
    }

    /**
     * xml: scope and the option "export upload files as well".
     */
    private function _exportFormXml(): void
    {
        echo '<form action="Record/record_export.php" method="post">';
        $this->backend->formInit('record.record_export_form_xml', [
            '{scope}' => $this->_scopeHtml(),
            '{files_checked}' => (isset($_REQUEST['files']) || (string)($_REQUEST['function'] ?? '') === '') ? ' checked' : '',
        ]);
        $this->_exportHidden();
        echo $this->backend->form('record.form_export_run', ['{options}' => $this->_exportOptions()]);
        echo '</form>';
    }

    /**
     * csv: CSV settings, languages, fields.
     */
    private function _exportFormCsv(): void
    {
        $csv = (array)($_REQUEST['data']['csv'] ?? []);
        $fromRequest = !empty($csv);
        $csv += ['header' => 1, 'bom' => 1, 'separator' => ';', 'enclosure' => '"', 'eol' => '\r\n'];

        echo '<form action="Record/record_export.php" method="post">';
        $this->backend->formInit('record.record_export_form_csv', [
            '{scope}' => $this->_scopeHtml(),
            '{languages}' => $this->_languageCheckboxes(),
            '{fields}' => $this->_csvFieldList(),
        ]);
        foreach (['header', 'bom'] as $key) {
            $this->backend->formField($this->record, [
                'name' => 'csv.' . $key,
                'value' => $fromRequest ? (int)!empty($csv[$key]) : 1,
                'id' => 0,
                'colDef' => ['type' => 'bit', 'display_type' => 'switch', 'label' => '#record.label_csv_' . $key],
            ]);
        }
        foreach (['separator' => ', ; \t', 'enclosure' => '" \'', 'eol' => '\r\n \n'] as $key => $suffix) {
            $this->backend->formField($this->record, [
                'name' => 'csv.' . $key,
                'value' => (string)$csv[$key],
                'id' => 0,
                'colDef' => ['type' => 'chars', 'display_type' => 'text', 'label' => '#record.label_csv_' . $key, 'suffix' => $suffix],
            ]);
        }
        $this->_exportHidden();
        echo $this->backend->form('record.form_export_run', ['{options}' => $this->_exportOptions()]);
        echo '</form>';
    }

    /**
     * Languages of the instance as checkboxes; the primary language or the
     * selection of the request is preselected.
     */
    private function _languageCheckboxes(): string
    {
        $instanceId = (int)($this->areaData['instance_id'] ?? 0);
        // only readable languages of the user group
        $languages = $this->record->readableLanguages($this->instanceCache->languages($instanceId));
        $primaryId = $this->instanceCache->primaryLanguageId($instanceId);
        if (!isset($languages[$primaryId])) $primaryId = (int)(array_key_first($languages) ?? 0);
        $requested = isset($_REQUEST['languages']) ? $this->system->sanitizeIds((array)$_REQUEST['languages']) : null;
        $html = '';
        foreach ($languages as $languageId => $language) {
            $checked = ($requested === null) ? ((int)$languageId === $primaryId) : in_array((int)$languageId, $requested, true);
            $html .= $this->backend->template('record.export_checkbox', [
                '{name}' => 'languages[]',
                '{value}' => (string)$languageId,
                '{id}' => 'c9-export-lang-' . (int)$languageId,
                '{label}' => htmlspecialchars((string)($language['label'] ?? '') . ' (' . (string)($language['code'] ?? '') . ')'),
                '{checked}' => $checked ? ' checked' : '',
                '{class}' => '',
            ]);
        }
        return $html;
    }

    /**
     * Fields for the CSV export as a table: checkbox | name | label | type.
     * Link and file fields have a more link below them, which expands the
     * subfields (Bootstrap collapse on a separate tbody).
     */
    private function _csvFieldList(): string
    {
        $fields = $this->export->csvFields($this->areaId);
        $requested = isset($_REQUEST['fields']) ? array_map('strval', (array)$_REQUEST['fields']) : null;
        $isChecked = fn(string $key, bool $default) => ($requested === null) ? $default : in_array($key, $requested, true);

        $rows = '';
        foreach ($fields as $name => $entry) {
            $label = $this->cms->textOrSnippet((string)$entry['label']);
            $type = (string)$entry['type'];
            if (!empty($entry['meta'])) $type .= ' &middot; ' . $this->cms->snippet('record.label_export_meta');
            if (!empty($entry['multilingual'])) $type .= ' &middot; ' . $this->cms->snippet('record.label_move_multilingual');

            if (!empty($entry['relation'])) {
                // main row: the record_id of the target records, below it their fields
                $rows .= $this->_fieldRow("$name.record_id", $isChecked("$name.record_id", true), $name . '.record_id', $label, $type, $name);
                $rows .= $this->_subRows($name, $entry, $isChecked, ['record_id'], $label . '.');
            }
            elseif (!empty($entry['file'])) {
                $rows .= $this->_fieldRow($name, $isChecked($name, !empty($entry['checked'])), $name, $label . ' &middot; ' . $this->cms->snippet('record.label_export_file'), $type, $name);
                $rows .= $this->_subRows($name, $entry, $isChecked, [], 'data_fil.');
            }
            else {
                $rows .= $this->_fieldRow($name, $isChecked($name, !empty($entry['checked'])), $name, $label, $type, '');
            }
        }
        return $this->_fieldTable($rows);
    }

    /**
     * The field table with the "all" checkbox above it (cApp.ui.checkAll()) - set
     * if all main rows are selected (the collapsed subfields do not count).
     */
    private function _fieldTable(string $rows): string
    {
        $main = (string)preg_replace('~<tbody class="collapse".*?</tbody>~s', '', $rows);
        $boxes = preg_match_all('~<input[^>]*name="fields\[\]"[^>]*>~', $main, $matches);
        $checked = count(array_filter($matches[0], fn($input) => str_contains($input, ' checked')));
        return $this->backend->template('record.export_field_table', [
            '{table_rows}'  => $rows,
            '{all_checked}' => $boxes > 0 && $checked === $boxes ? ' checked' : '',
        ]);
    }

    /**
     * One row of the field table; with $moreName it gets the more link to the
     * associated tbody of the subfields. $sub = row of a subfield.
     */
    private function _fieldRow(string $key, bool $checked, string $name, string $label, string $type, string $moreName, bool $sub = false): string
    {
        $id = 'c9-export-field-' . preg_replace('/[^a-z0-9_]/i', '-', $key);
        $more = '';
        if ($moreName !== '') {
            $more = $this->backend->template('record.export_field_more', [
                '{more_id}' => 'c9-export-more-' . preg_replace('/[^a-z0-9_]/i', '-', $moreName),
            ]);
        }
        $row = $this->backend->template('record.export_field_row', [
            '{value}' => htmlspecialchars($key),
            '{id}' => $id,
            '{checked}' => $checked ? ' checked' : '',
            '{name}' => htmlspecialchars($name),
            '{label}' => $label,
            '{type}' => $type,
            '{more}' => $more,
            '{class}' => $sub ? 'c9-export-sub' : '',
        ]);
        // main rows in their own tbody - the subfields follow as a
        // collapsed tbody (export_field_subrows); rows directly in <table>
        // would not be valid HTML
        return $sub ? $row : "<tbody>\n" . $row . "</tbody>\n";
    }

    /**
     * The subfields of a field as a collapsed tbody (without those in $skip).
     */
    private function _subRows(string $name, array $entry, callable $isChecked, array $skip, string $labelPrefix): string
    {
        $rows = '';
        foreach ($entry['sub'] as $sub => $subEntry) {
            if (in_array($sub, $skip, true)) continue;
            $type = !empty($entry['file']) ? 'data_fil' : (string)($subEntry['type'] ?? '');
            if (!empty($subEntry['multilingual'])) $type .= ' &middot; ' . $this->cms->snippet('record.label_move_multilingual');
            $rows .= $this->_fieldRow(
                "$name.$sub",
                $isChecked("$name.$sub", !empty($subEntry['checked'])),
                "$name.$sub",
                $labelPrefix . htmlspecialchars($this->cms->textOrSnippet((string)$subEntry['label'])),
                $type,
                '',
                sub: true
            );
        }
        if ($rows === '') return '';
        return $this->backend->template('record.export_field_subrows', [
            '{more_id}' => 'c9-export-more-' . preg_replace('/[^a-z0-9_]/i', '-', $name),
            '{table_rows}' => $rows,
        ]);
    }

    /**
     * docx: source and target language, multilingual fields.
     */
    private function _exportFormDocx(): void
    {
        $instanceId = (int)($this->areaData['instance_id'] ?? 0);
        $languages = $this->record->readableLanguages($this->instanceCache->languages($instanceId));
        $languageIds = array_map('intval', array_keys($languages));
        $options = [];
        foreach ($languages as $languageId => $language) {
            $options[(string)$languageId] = (string)($language['label'] ?? '') . ' (' . (string)($language['code'] ?? '') . ')';
        }
        $docx = (array)($_REQUEST['data']['docx'] ?? []);
        $from = isset($docx['from']) ? (int)$docx['from'] : ($languageIds[0] ?? 0);
        $to = isset($docx['to']) ? (int)$docx['to'] : ($languageIds[1] ?? $languageIds[0] ?? 0);
        $split = !empty($docx['split']);

        $fields = $this->export->docxFields($this->areaId);
        $requested = isset($_REQUEST['fields']) ? array_map('strval', (array)$_REQUEST['fields']) : null;
        $rows = '';
        foreach ($fields as $name => $colDef) {
            $rows .= $this->_fieldRow(
                (string)$name,
                $requested === null || in_array((string)$name, $requested, true),
                (string)$name,
                htmlspecialchars($this->cms->textOrSnippet((string)($colDef['label'] ?? $name))),
                (string)($colDef['type'] ?? ''),
                ''
            );
        }
        $fieldHtml = $rows === '' ? '' : $this->_fieldTable($rows);
        if ($fieldHtml === '') {
            $fieldHtml = $this->backend->template('alert_warning', ['{text}' => '{#record.note_export_no_multilingual}']);
        }
        if (count($languageIds) < 2) {
            echo $this->backend->template('alert_warning', ['{text}' => '{#record.note_export_one_language}']);
        }

        echo '<form action="Record/record_export.php" method="post">';
        $this->backend->formInit('record.record_export_form_docx', [
            '{scope}' => $this->_scopeHtml(),
            '{fields}' => $fieldHtml,
            '{single_checked}' => $split ? '' : ' checked',
            '{split_checked}' => $split ? ' checked' : '',
        ]);
        foreach (['from' => $from, 'to' => $to] as $key => $value) {
            $this->backend->formField($this->record, [
                'name' => 'docx.' . $key,
                'value' => $value,
                'id' => 0,
                'colDef' => ['type' => 'integer', 'display_type' => 'select', 'label' => '#record.label_docx_' . $key],
                'options' => $options,
            ]);
        }
        $this->_exportHidden();
        echo $this->backend->form('record.form_export_run', ['{options}' => $this->_exportOptions()]);
        echo '</form>';
    }

    // ====================
    // Global (start page)

    /**
     * Global export: only the XML tab. Selection of the areas of all types as a
     * tree with checkboxes (a folder selects its elements too, see
     * export_area_tree.html), options structure / content / files.
     *
     * Request: global=1, function ('' | run), areas[], structure, content, files, background
     */
    private function _exportGlobal(string $function): void
    {
        $this->type = 'xml';
        $this->listQuery = 'global=1';
        $this->backend->setNaviLabel('#record.headline_export_global');
        echo $this->backend->headline('#record.headline_export_global');

        if ($function === 'run') {
            $areaIds = $this->system->sanitizeIds((array)($_REQUEST['areas'] ?? []), true);
            if (empty($areaIds)) {
                $this->cms->error($this->cms->snippet('record.error_export_no_areas'));
                $this->errors();
                $this->_exportGlobalForm();
                return;
            }
            $job = [
                'type' => 'xml',
                'area_ids' => $areaIds,
                'structure' => !empty($_REQUEST['structure']),
                'content' => !empty($_REQUEST['content']),
                'files' => !empty($_REQUEST['files']),
                'portable' => !empty($_REQUEST['portable']),
            ];
            // scope for the threshold: records of the selected content areas
            $count = 0;
            if ($job['content']) {
                $placeholders = implode(',', array_fill(0, count($areaIds), '?'));
                $this->db->query("SELECT COUNT(*) FROM `record` WHERE `area_id` IN ($placeholders) AND `tmp` = 0 AND `deleted` = 0", str_repeat('i', count($areaIds)), $areaIds);
                $count = (int)($this->db->fetchNum()[0] ?? 0);
            }
            if (!empty($_REQUEST['background']) || $this->export->needsBackground($count)) {
                $error = '';
                if ($this->export->start($job, $error) === null) {
                    $this->cms->error($this->cms->snippet('record.error_batch_start') . ' ' . $error);
                    $this->errors();
                    $this->_exportGlobalForm();
                    return;
                }
                $this->backend->balloon('success', $this->cms->snippet('record.message_export_started'));
                $this->backend->script('cApp.layers.close();');
                return;
            }
            $result = $this->export->run($job);
            if (!empty($result['error'])) {
                $this->cms->error((string)$result['error']);
                $this->errors();
                $this->_exportGlobalForm();
                return;
            }
            echo $this->backend->template('record.export_result_global', [
                '{summary}' => htmlspecialchars($this->export->summaryText($result)),
                '{download_url}' => htmlspecialchars($this->export->downloadUrl((string)$result['zip'])),
                '{file}' => htmlspecialchars((string)$result['zip']),
                '{size}' => htmlspecialchars($this->system->convertSize((float)$result['size'])),
                '{validity}' => htmlspecialchars($this->export->validityText((string)$result['zip'])),
            ]);
            return;
        }

        $this->backend->tabBarReset();
        $this->backend->tabAdd('#record.tab_export_xml', '', true);
        echo $this->backend->tabBar();
        $this->_exportGlobalForm();
    }

    /**
     * The mask of the global export: area tree, options.
     *
     * The tree is shown per instance under its own row with the label of the
     * instance - like a folder: one click selects all areas of the instance. The row
     * is not an area (checkbox without name, data-parent of the root elements
     * "i{instance_id}"). At root level the areas of all instances share a
     * common order (lft) - within each instance it is kept.
     */
    private function _exportGlobalForm(): void
    {
        $checked = isset($_REQUEST['areas']) ? $this->system->sanitizeIds((array)$_REQUEST['areas'], true) : [];
        $fromRequest = (string)($_REQUEST['function'] ?? '') !== '';
        $this->db->query("SELECT `area_id`, `parent_id`, `depth`, `type`, `name`, `label`, `icon`, `instance_id` FROM `area` WHERE `deleted` = 0 ORDER BY `lft` ASC");
        $byInstance = [];
        foreach ($this->db->fetchAll() as $areaRow) {
            $byInstance[(int)$areaRow['instance_id']][] = $areaRow;
        }
        $rows = '';
        // order of the instances as everywhere in the backend (default instance last)
        foreach (array_keys($this->instanceCache->instanceLabels()) as $instanceId) {
            $areaRows = $byInstance[$instanceId] ?? [];
            if (!$areaRows) continue;
            $instanceLabel = htmlspecialchars($this->cms->textOrSnippet((string)($this->instanceCache->instance($instanceId)['label'] ?? '')));
            $rows .= $this->backend->template('record.export_instance_row', [
                '{instance_id}' => $instanceId,
                '{label}' => $instanceLabel !== '' ? $instanceLabel : (string)$instanceId,
                '{instance}' => $instanceLabel,
            ]);
            foreach ($areaRows as $areaRow) {
                $areaId = (int)$areaRow['area_id'];
                $parentId = (int)$areaRow['parent_id'];
                $rows .= $this->backend->template('record.export_area_row', [
                    '{area_id}' => $areaId,
                    '{parent_id}' => $parentId > 0 ? (string)$parentId : 'i' . $instanceId,
                    '{depth}' => ((int)$areaRow['depth'] + 1) * 24,
                    '{icon}' => htmlspecialchars((string)$areaRow['icon']),
                    '{label}' => htmlspecialchars($this->cms->textOrSnippet((string)$areaRow['label'])),
                    '{name}' => htmlspecialchars((string)$areaRow['name']),
                    '{type}' => htmlspecialchars((string)$areaRow['type']),
                    '{instance}' => $instanceLabel,
                    '{checked}' => in_array($areaId, $checked, true) ? ' checked' : '',
                    '{folder}' => (string)$areaRow['type'] === 'folder' ? ' c9-export-folder' : '',
                ]);
            }
        }
        echo '<form action="Record/record_export.php" method="post">';
        $this->backend->formInit('record.record_export_form_global', [
            '{rows}' => $rows,
            '{checked_structure}' => (!$fromRequest || !empty($_REQUEST['structure'])) ? ' checked' : '',
            '{checked_content}' => (!$fromRequest || !empty($_REQUEST['content'])) ? ' checked' : '',
            '{checked_files}' => (!$fromRequest || !empty($_REQUEST['files'])) ? ' checked' : '',
            '{checked_portable}' => !empty($_REQUEST['portable']) ? ' checked' : '',
            '{checked_background}' => !empty($_REQUEST['background']) ? ' checked' : '',
            '{hours}' => $this->export->retentionHours(),
        ]);
        $this->backend->formHidden('function', 'run');
        $this->backend->formHidden('global', 1);
        $this->backend->formHidden('type', 'xml');
        echo $this->backend->form('record.form_export_run_global');
        echo '</form>';
    }

    // ====================
    // Run

    /**
     * Assemble the job, run it, show the result with the download link. On
     * an error the mask appears again with the message.
     */
    private function _exportRun(): void
    {
        // in the order of the tree structure or the manual sorting (exportSort())
        [$sort, $order] = $this->export->exportSort($this->areaId);
        $ids = $this->batch->collectIds($this->areaId, $this->scope === 'filter' ? $this->filterParams : [], $sort, $order);
        $csv = (array)($_REQUEST['data']['csv'] ?? []);
        $docx = (array)($_REQUEST['data']['docx'] ?? []);
        $job = $this->export->job($this->type, $this->areaId, $ids, [
            'files'     => !empty($_REQUEST['files']),
            'header'    => !empty($csv['header']),
            'bom'       => !empty($csv['bom']),
            'separator' => (string)($csv['separator'] ?? ';'),
            'enclosure' => (string)($csv['enclosure'] ?? '"'),
            'eol'       => (string)($csv['eol'] ?? '\r\n'),
            'languages' => (array)($_REQUEST['languages'] ?? []),
            'fields'    => (array)($_REQUEST['fields'] ?? []),
            'from'      => (int)($docx['from'] ?? 0),
            'to'        => (int)($docx['to'] ?? 0),
            'split'     => !empty($docx['split']),
        ]);
        if ($job === null) {
            $this->_exportRunError($this->cms->errorMsgLast());
            $this->cms->errorsReset();
            return;
        }

        // Background
        // --------------------
        $background = !empty($_REQUEST['background']) || $this->export->needsBackground(count($job['ids']));
        if ($background) {
            $error = '';
            if ($this->export->start($job, $error) === null) {
                $this->_exportRunError($this->cms->snippet('record.error_batch_start') . ' ' . $error);
                return;
            }
            $this->backend->balloon('success', $this->cms->snippet('record.message_export_started'));
            $this->backend->script('cApp.layers.close();');
            return;
        }

        // In the request
        // --------------------
        $result = $this->export->run($job);
        if (!empty($result['error'])) {
            $this->_exportRunError((string)$result['error']);
            return;
        }
        echo $this->backend->template('record.export_result', [
            '{summary}' => htmlspecialchars($this->export->summaryText($result)),
            '{download_url}' => htmlspecialchars($this->export->downloadUrl((string)$result['zip'])),
            '{file}' => htmlspecialchars((string)$result['zip']),
            '{size}' => htmlspecialchars($this->system->convertSize((float)$result['size'])),
            '{validity}' => htmlspecialchars($this->export->validityText((string)$result['zip'])),
        ]);
    }

    /**
     * Error when running: message, then the mask again.
     */
    private function _exportRunError(string $error): void
    {
        $this->cms->error($error);
        $this->errors();
        $this->backend->tabBarReset();
        foreach ($this->allowedTypes as $type) {
            $link = "cApp.layers.replace('Record/record_export.php?{$this->listQuery}&type={$type}');";
            $this->backend->tabAdd('#record.tab_export_' . $type, $type === $this->type ? '' : $link, $type === $this->type);
        }
        echo $this->backend->tabBar();
        match ($this->type) {
            'xml'  => $this->_exportFormXml(),
            'csv'  => $this->_exportFormCsv(),
            'docx' => $this->_exportFormDocx(),
        };
    }

    // ====================
    // Download

    /**
     * Deliver the zip file from var/export (backend/record_download.php).
     *
     * The session has already checked Backend::backendPath(). Only a file name
     * (no path) with the extension .zip that lies in var/export is allowed. After
     * sending, the file is deleted - the link is valid exactly once.
     *
     * Request: file
     */
    public function download(): void
    {
        $file = basename((string)($_REQUEST['file'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_\-]+\.zip$/', $file)) {
            $this->system->error404();
            return;
        }
        $path = (string)$this->config->get('paths.var_dir') . '/export/' . $file;
        if (!is_file($path)) {
            $this->system->error404();
            return;
        }
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Length: ' . (int)filesize($path));
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Cache-Control: private, no-store');
        readfile($path);
        @unlink($path);
        exit;
    }
}
