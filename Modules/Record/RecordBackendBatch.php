<?php
/**
 * Batch processing - the masks (extension for RecordBackend).
 *
 * Entry point (script in backend/):
 *
 *     record_batch.php  -> batch()
 *
 * Reachable via the menu (more_vert) on the right of the record list; the call
 * opens its own layer above the list (cApp.layers.add) and carries the
 * page parameters of the list (cApp.layers.queryString(): area_id, filter, sort,
 * ...). The work itself is done by RecordBatch - in the request or as
 * a background process.
 *
 * NAVIGATION: back to the list from the layer only works with cApp.layers.close()
 * (after running, followed by a refresh); within the steps the
 * layer is replaced with cApp.layers.replace().
 *
 * THREE STEPS, ONE SCRIPT (function)
 * --------------------
 *     ''        choose scope and function. Scope: the last search result (if
 *               the list was filtered, preselected) or all records of the
 *               area. Function: Edit, Move, Delete. (The full text is rebuilt
 *               by the scheduler task "Rebuild full text", see
 *               SchedulerRunner::runFulltext().)
 *     select    the mask for the function:
 *                 edit    the input mask of the area with a checkbox in front of each
 *                         field - only checked fields are changed (in ALL
 *                         records). Without tree fields and fields with the unique flag
 *                         (RecordBatch::batchFields()). Links with
 *                         search windows and upload fields depend on a TEMPLATE -
 *                         a working copy without original (Record::tmpInit()), which
 *                         carries the hidden field batch_tmp_id through the steps and
 *                         is served by the Ajax calls of the lookup and upload fields
 *                         as when creating a record (see
 *                         RecordBatch, THE TEMPLATE). Cancelling discards it
 *                         (record_cancel.php, closes the layer).
 *                 move    choose the target area, then the field mapping as in
 *                         moving a single record (RecordBackendMove)
 *                 delete  confirmation
 *               In addition the options simulation and background (from
 *               admin.mass_delete_threshold records fixed, see RecordBatch).
 *     run       execute. In the request: result with the error messages per record
 *               one below the other. In the background: start the process, close the layer -
 *               the result comes as an internal message (inbox).
 *
 * The filter parameters of the list travel as a query string (hidden field 'query') through
 * the steps; the ids are only determined at 'run' (RecordBatch::collectIds()).
 *
 * @see Camindo\Core\Modules\Record\RecordBatch        the work
 * @see Camindo\Core\Modules\Record\RecordBackendEdit  selection lists of the input mask
 * @see Camindo\Core\Modules\Record\RecordBackendMove  model for the field mapping
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

class RecordBackendBatch extends RecordBackendEdit {

    /** filter parameters of the list (RecordBatch::filterParams()) */
    private array $filterParams = [];
    /** the same as query string for the hidden fields */
    private string $query = '';
    /** query string of the list (hidden field, for a return to step 1) */
    private string $listQuery = '';
    /** 'filter' (last search result) | 'all' */
    private string $scope = 'all';
    private string $mode = '';
    private int $count = 0;

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
    )
    {
        parent::__construct($config, $backend, $crypt, $session, $db, $system, $cms, $massDeletion, $area, $record, $instanceCache, $service, $presenter);
    }

    /**
     * Batch processing (backend/record_batch.php).
     *
     * Request: area_id, filter/filterop/filtermode (from the list) or query (from
     *          step 2), list_query, function ('' | select | run), scope, mode,
     *          batch_fields[] (name or name:language_id), batch_tmp_id, data[...] (uploads in $_FILES),
     *          target_area_id, mapping[...], simulate, background
     */
    public function batch(): void
    {
        echo $this->backend->template('container_header');
        $this->function = 'list';
        $this->_resolveParams();
        $function = (string)($_REQUEST['function'] ?? '');

        if ($this->function !== 'list' || $this->areaId < 1) {
            if ($this->areaId < 1) $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }
        // additional right batch and read right on the area
        if (!$this->batchAllowed() || !$this->backend->access()->areaRead($this->areaId)) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        // filter parameters: on the first call from the list, afterwards from 'query'
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
        $hasFilter = $this->batch->hasFilter($this->filterParams);

        $this->scope = ($hasFilter && (string)($_REQUEST['scope'] ?? 'filter') === 'filter') ? 'filter' : 'all';
        $this->mode = (string)($_REQUEST['mode'] ?? '');
        if (!in_array($this->mode, RecordBatch::MODES, true)) $this->mode = '';
        $this->count = $this->batch->count($this->areaId, $this->scope === 'filter' ? $this->filterParams : []);

        // Bread crum navi, Bereich, H1
        $this->backend->setNaviLabel('#record.headline_batch');
        echo $this->backend->template('record.prefix_content_area', [
            '{text}' => str_replace('{area_label}', $this->cms->textOrSnippet((string)$this->areaData['label']), $this->cms->snippet('area.headline_area_label'))
        ]);
        echo $this->backend->headline('#record.headline_batch');

        // Step 3: execute
        if ($function === 'run' && $this->mode !== '') {
            $this->_batchRun();
            echo $this->backend->template('container_footer');
            return;
        }

        // Step 2: mask for the function
        if ($function === 'select' && $this->mode !== '') {
            match ($this->mode) {
                'edit'   => $this->_batchEditForm(),
                'move'   => $this->_batchMoveForm(),
                'delete' => $this->_batchDeleteForm(),
            };
            echo $this->backend->template('container_footer');
            return;
        }

        // Step 1: scope and function
        $this->_batchScopeForm($hasFilter);
        echo $this->backend->template('container_footer');
    }

    // ====================
    // Step 1

    /**
     * Choose scope (search result / all) and function.
     */
    private function _batchScopeForm(bool $hasFilter): void
    {
        if ($hasFilter) {
            $countFilter = $this->count;
            $countAll = $this->batch->count($this->areaId, []);
            $scopeHtml = $this->backend->template('record.batch_scope_choice', [
                '{count_filter}' => $this->cms->snippet('record.batch_scope_filter', ['count' => $countFilter]),
                '{count_all}' => $this->cms->snippet('record.batch_scope_all', ['count' => $countAll]),
            ]);
        }
        else {
            $scopeHtml = $this->backend->template('record.batch_scope_note', [
                '{count}' => $this->cms->snippet('record.batch_scope_all', ['count' => $this->count]),
            ]);
        }

        echo '<form action="Record/record_batch.php" method="post">';
        $this->backend->formInit('record.record_batch_scope_form', [
            '{scope}' => $scopeHtml,
        ]);
        $this->_batchHidden('select');
        echo $this->backend->form('record.form_batch_next_close');
        echo '</form>';
    }

    /**
     * The hidden fields that accompany each step.
     */
    private function _batchHidden(string $function): void
    {
        $this->backend->formHidden('function', $function);
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('query', $this->query);
        $this->backend->formHidden('list_query', $this->listQuery);
        if ($function !== 'select') {
            $this->backend->formHidden('scope', $this->scope);
            $this->backend->formHidden('mode', $this->mode);
        }
    }

    // ====================
    // Step 2

    /**
     * Options below each mask: simulation, background
     * (checkbox or - from the threshold on - a notice with a fixed hidden field).
     */
    private function _batchOptions(): string
    {
        if ($this->batch->needsBackground($this->count)) {
            $background = $this->backend->template('record.batch_option_background_fixed', [
                '{threshold}' => (int)($this->config->get('admin.mass_delete_threshold') ?? 200),
            ]);
        }
        else {
            $background = $this->backend->template('record.batch_option_background');
        }
        return $this->backend->template('record.batch_options', [
            '{count}' => $this->cms->snippet('record.batch_scope_all', ['count' => $this->count]),
            '{simulate}' => $this->backend->template('record.batch_option_simulate'),
            '{background}' => $background,
        ]);
    }

    /**
     * edit: the input mask of the area with a checkbox in front of each field.
     *
     * No field is required (no `required`) - what is not checked remains
     * untouched; on save only the checked fields are validated, according to the
     * field definitions of the area. Multilingual fields are shown with all languages
     * one below the other, each language with its own checkbox ("name:language_id") - only the
     * checked languages are written.
     *
     * Lookup and upload fields work as in RecordBackendEdit::_editForm() on
     * a working copy - here the template (class comment): $this->id points to
     * it, so that the Ajax URLs and preview blocks of the fields address it. An
     * existing template (batch_tmp_id, after an error in step 3) is
     * reused, otherwise a new one is created.
     */
    private function _batchEditForm(): void
    {
        $schemaVirtual = $this->record->virtualSchema($this->areaId, false);
        $fields = $this->batch->batchFields($schemaVirtual);
        $this->data = (array)($_REQUEST['data'] ?? []);
        $checked = array_map('strval', (array)($_REQUEST['batch_fields'] ?? []));

        // The template
        $templateId = (int)($_REQUEST['batch_tmp_id'] ?? 0);
        if (!$this->batch->templateValid($templateId, $this->areaId)) {
            $templateData = $this->record->tmpInit(['area_id' => $this->areaId]);
            $templateId = (int)($templateData['record_id'] ?? 0);
        }
        $this->id = $templateId;
        $templateData = ($templateId > 0) ? $this->record->loadTmpData($templateId) : [];

        $languages = $this->instanceCache->languages((int)($this->areaData['instance_id'] ?? 0));
        $languageIds = array_map('intval', array_keys($languages));
        $isMultilingual = count($languageIds) > 1;

        echo '<form action="Record/record_batch.php" method="post">';
        $this->backend->formInit('record.record_batch_edit_form', [
            '{count}' => $this->cms->snippet('record.batch_scope_all', ['count' => $this->count]),
        ]);

        foreach ($fields as $name => $colDef) {
            $type = (string)($colDef['type'] ?? 'text');
            $displayType = (string)($colDef['display_type'] ?? 'text');
            $fieldId = (int)$colDef['field_id'];
            $isLookup = in_array($type, $this->cms->relationTypes, true) && $displayType === 'lookup';

            // choices as in the input mask
            $options = null;
            if (in_array($type, $this->cms->relationTypes, true) && !$isLookup) {
                $relAreaIds = str_starts_with($type, 'relation')
                    ? [(int)$colDef['rel_area_id']]
                    : $this->record->getRelAreaIds($fieldId);
                $options = ($displayType === 'select')
                    ? $this->_relatedDatasetOptions($relAreaIds, addEmptyOption: true)
                    : $this->_relatedDatasetOptions($relAreaIds);
            }
            elseif ($name === 'template_id') $options = $this->_templateOptions();
            elseif ($name === 'status_id')   $options = $this->_statusOptions(settableOnly: true);

            // no required: only checked fields count
            $colDef['flags'] = array_values(array_diff((array)($colDef['flags'] ?? []), ['not_empty']));

            // checkbox: value "name", for multilingual fields per language "name:language_id"
            $checkbox = fn(?int $languageId) => $this->backend->template('record.batch_field_checkbox', [
                '{name}' => htmlspecialchars($name),
                '{value}' => htmlspecialchars($languageId === null ? $name : "{$name}:{$languageId}"),
                '{field_id}' => $fieldId,
                '{lang}' => $languageId ?? '',
                '{checked}' => in_array($languageId === null ? $name : "{$name}:{$languageId}", $checked, true) ? ' checked' : '',
            ]);
            $fieldParams = [
                'name' => $name,
                'colDef' => $colDef,
                'value' => $this->data[$name] ?? '',
                'id' => $templateId,
                'append' => true,
                'options' => $options,
                'labelPrefix' => $checkbox(null),
            ];

            if ($isMultilingual && $this->cms->isMultilingualField($colDef)) {
                $values = (array)($this->data[$name] ?? []);
                // upload field: per language, the template's own file
                $files = ($type === 'file' && isset($templateData[$name]))
                    ? $this->record->fileEntries((array)$templateData[$name]) : [];
                foreach ($languageIds as $languageId) {
                    $langParams = $fieldParams;
                    $langParams['value'] = $values[$languageId] ?? '';
                    $langParams['langId'] = $languageId;
                    $langParams['langSuffix'] = (string)($languages[$languageId]['code'] ?? $languageId);
                    $langParams['placeholder'] = '{data_' . $name . '__' . $languageId . '}';
                    $langParams['labelPrefix'] = $checkbox($languageId);
                    $this->backend->formField($this->record, $langParams);
                    if ($type === 'file') {
                        $this->_editFileReplacements($name, $fieldId, $this->_batchTemplateFile((array)($files[$languageId] ?? [])), $languageId);
                    }
                }
                continue;
            }
            $this->backend->formField($this->record, $fieldParams);

            if ($isLookup) {
                $this->_editLookupReplacements($fieldId, (int)($colDef['max'] ?? 0));
            }
            elseif ($type === 'file') {
                $fileData = isset($templateData[$name]) ? $this->record->fileEntry((array)$templateData[$name], null) : [];
                $this->_editFileReplacements($name, $fieldId, $this->_batchTemplateFile($fileData), null);
            }
        }

        $this->backend->formHidden('batch_tmp_id', $templateId);
        $this->_batchHidden('run');
        echo $this->backend->form('record.form_batch_run', [
            '{options}' => $this->_batchOptions(),
            '{cancel}' => "cApp.layers.replacePost('Record/record_cancel.php?id={$templateId}')",
            '{icon}' => 'save',
            '{label}' => '{#button_save}',
        ]);
        echo '</form>';
    }

    /**
     * File entry of the template for the preview: removed (x button) and empty
     * entries show nothing - as in RecordBackendEdit::_editRestoreSecuredFiles().
     */
    private function _batchTemplateFile(array $fileData): array
    {
        if (!empty($fileData['deleted']) || (int)($fileData['size'] ?? 0) < 1) return [];
        return $fileData;
    }

    /**
     * delete: confirmation.
     */
    private function _batchDeleteForm(): void
    {
        echo '<form action="Record/record_batch.php" method="post">';
        $this->backend->formInit('record.record_batch_delete_form', [
            '{text}' => $this->cms->snippet('record.alert_batch_delete', ['count' => $this->count]),
            // what the deletion policy of the area does with each record (module Privacy)
            '{policy}' => $GLOBALS['app']->get(\Camindo\Core\Modules\Privacy\PrivacyBackend::class)->areaHintHtml($this->areaId),
        ]);
        $this->_batchHidden('run');
        echo $this->backend->form('record.form_batch_run', [
            '{options}' => $this->_batchOptions(),
            '{cancel}' => 'cApp.layers.close()',
            '{icon}' => 'delete',
            '{label}' => '{#button_delete}',
        ]);
        echo '</form>';
    }

    /**
     * move: choose the target area (2a), then the field mapping (2b) - as in
     * RecordBackendMove, just without a record and with the options below.
     */
    private function _batchMoveForm(): void
    {
        $targetAreaId = (int)($_REQUEST['data']['target_area_id'] ?? $_REQUEST['target_area_id'] ?? 0);
        $mapping = (array)($_REQUEST['mapping'] ?? []);
        $targetData = ($targetAreaId > 0 && $targetAreaId !== $this->areaId)
            ? $this->backend->areaData($targetAreaId)
            : null;

        echo '<form action="Record/record_batch.php" method="post">';
        if ($targetData && in_array((string)$targetData['type'], Area::CONTENT_TYPES, true)) {
            // 2b: field mapping
            $schemaSource = $this->record->virtualSchema($this->areaId, false);
            $schemaTarget = $this->record->virtualSchema($targetAreaId, false);
            $sourceFields = $this->record->moveFields($schemaSource);
            $targetFields = $this->record->moveFields($schemaTarget);
            $fromRequest = !empty($mapping);

            $tplRow = $this->backend->template('record.record_move_row');
            $tableRows = '';
            foreach ($sourceFields as $name => $colDef) {
                $targets = $this->record->moveTargets($colDef, $targetFields);
                $selected = $fromRequest ? (string)($mapping[$name] ?? '') : (isset($targets[$name]) ? $name : '');
                $options = '<option value="">' . htmlspecialchars($this->cms->snippet('record.option_move_delete')) . "</option>\n";
                foreach ($targets as $targetName => $targetDef) {
                    $optionLabel = $this->cms->textOrSnippet((string)($targetDef['label'] ?? $targetName)) . ' (' . $targetName . ')';
                    $isSelected = ($selected === (string)$targetName) ? ' selected' : '';
                    $options .= '<option value="' . htmlspecialchars((string)$targetName) . '"' . $isSelected . '>'
                        . htmlspecialchars($optionLabel) . "</option>\n";
                }
                $multilingual = $this->cms->isMultilingualField($colDef)
                    ? ' &middot; ' . $this->cms->snippet('record.label_move_multilingual') : '';
                $tableRows .= strtr($tplRow, [
                    '{label}' => htmlspecialchars($this->cms->textOrSnippet((string)($colDef['label'] ?? $name))),
                    '{name}' => htmlspecialchars((string)$name),
                    '{type}' => htmlspecialchars((string)($colDef['type'] ?? '')),
                    '{multilingual}' => $multilingual,
                    '{options}' => $options,
                    '{class}' => '',
                    '{error}' => '',
                ]) . "\n";
            }
            $instanceWarning = '';
            if ((int)($targetData['instance_id'] ?? 0) !== (int)($this->areaData['instance_id'] ?? 0)) {
                $instanceWarning = $this->backend->template('alert_warning', ['{text}' => '{#record.warning_move_instance}']);
            }
            $this->backend->formInit('record.record_batch_move_form', [
                '{count}' => $this->cms->snippet('record.batch_scope_all', ['count' => $this->count]),
                '{instance_warning}' => $instanceWarning,
                '{source_area}' => $this->backend->teaser('area', $this->areaId, false),
                '{target_area}' => $this->backend->teaser('area', $targetAreaId, false),
                '{table_rows}' => $tableRows,
            ]);
            $this->backend->formHidden('target_area_id', $targetAreaId);
            $this->_batchHidden('run');
            echo $this->backend->form('record.form_batch_run', [
                '{options}' => $this->_batchOptions(),
                '{cancel}' => 'cApp.layers.close()',
                '{icon}' => 'drive_file_move',
                '{label}' => '{#button_move}',
            ]);
        }
        else {
            // 2a: choose the target area - the selection submits the form
            $this->backend->formInit('record.record_move_area_form');
            $this->backend->formField($this->record, [
                'name' => 'target_area_id',
                'value' => 0,
                'id' => 0,
                'colDef' => [
                    'type' => 'integer',
                    'display_type' => 'select',
                    'label' => '#record.headline_move_target'
                ],
                'options' => $this->_batchTargetAreas(),
                'template' => 'record.record_select_area_id',
                'selectPlease' => true
            ]);
            $this->_batchHidden('select');
            $this->backend->formHidden('scope', $this->scope);
            $this->backend->formHidden('mode', $this->mode);
            echo $this->backend->form('record.form_batch_cancel');
        }
        echo '</form>';
    }

    /**
     * Target areas: all record areas except the own one (see
     * RecordBackendMove::_moveAreaForm()).
     */
    private function _batchTargetAreas(): array
    {
        $sourceInstanceId = (int)($this->areaData['instance_id'] ?? 0);
        $placeholders = implode(',', array_fill(0, count(Area::CONTENT_TYPES), '?'));
        $this->db->query(
            "SELECT `area_id`, `instance_id`, `depth`, `label` FROM `area`"
            . " WHERE `type` IN ($placeholders) AND `deleted` = 0 AND `area_id` != ?"
            . $this->backend->areaAccessSql(writable:true)
            . " ORDER BY `lft` ASC",
            str_repeat('s', count(Area::CONTENT_TYPES)) . 'i',
            array_merge(Area::CONTENT_TYPES, [$this->areaId])
        );
        $areaOptions = [];
        while ($areaData = $this->db->fetch()) {
            $label = str_repeat('&nbsp; ', (int)$areaData['depth'] * 2)
                . $this->cms->textOrSnippet((string)$areaData['label']);
            $instanceId = (int)$areaData['instance_id'];
            if ($instanceId !== $sourceInstanceId) {
                $instanceLabel = (string)($this->instanceCache->instance($instanceId)['label'] ?? $instanceId);
                $label .= ' (' . $this->cms->textOrSnippet($instanceLabel) . ')';
            }
            $areaOptions[(string)$areaData['area_id']] = $label;
        }
        return $areaOptions;
    }

    // ====================
    // Step 3

    /**
     * Assemble the job and execute it or hand it to the background.
     */
    private function _batchRun(): void
    {
        $simulate = !empty($_REQUEST['simulate']);
        $background = !empty($_REQUEST['background']) || $this->batch->needsBackground($this->count);

        $job = [
            'mode'     => $this->mode,
            'area_id'  => $this->areaId,
            'simulate' => $simulate,
        ];
        if ($this->mode === 'edit') {
            $data = (array)($_REQUEST['data'] ?? []);
            $allowed = $this->batch->batchFields($this->record->virtualSchema($this->areaId, false));
            [$fields, $languages] = $this->batch->checkedFields((array)($_REQUEST['batch_fields'] ?? []), $allowed);
            // The template: bind the uploads and passwords of the request to it - BEFORE
            // any error message, so that they remain in the mask shown again
            $templateId = (int)($_REQUEST['batch_tmp_id'] ?? 0);
            if (!$this->batch->templateValid($templateId, $this->areaId)) {
                $this->cms->error($this->cms->snippet('record.error_batch_template'));
                unset($_REQUEST['batch_tmp_id']);
            }
            elseif (!$this->record->secureInputs($templateId, $data)) {
                // file rejected - the message is in Cms::errors
            }
            elseif (empty($fields)) {
                $this->cms->error($this->cms->snippet('record.error_batch_no_fields'));
            }
            if (!empty($this->cms->errors)) {
                $this->errors();
                $this->_batchEditForm();
                return;
            }
            $job['fields'] = $fields;
            try {
                $job += $this->batch->editJob($templateId, $fields, $data, $allowed, $languages);
            } catch (\Throwable $e) {
                $this->cms->error($e->getMessage());
                $this->errors();
                $this->_batchEditForm();
                return;
            }
            // The job is self-contained - the template has served its purpose
            $this->record->cancel($templateId);
            // The uploads of the request are part of the job as 'importfile'. A leftover
            // $_FILES entry would take precedence when saving each record
            // (_saveUploadField()) and would be skipped - already moved long ago
            $_FILES = [];
        }
        elseif ($this->mode === 'move') {
            $job['target_area_id'] = (int)($_REQUEST['target_area_id'] ?? 0);
            $job['mapping'] = (array)($_REQUEST['mapping'] ?? []);
            // check the mapping once in advance - otherwise the error would apply to every record
            $sourceFields = $this->record->moveFields($this->record->virtualSchema($this->areaId, false));
            $targetFields = $this->record->moveFields($this->record->virtualSchema($job['target_area_id'], false));
            if ($job['target_area_id'] < 1 || $this->record->moveMapping($sourceFields, $targetFields, $job['mapping']) === null) {
                if ($job['target_area_id'] < 1) $this->cms->error($this->cms->snippet('area.error_area_not_found'));
                $this->errors();
                $this->_batchMoveForm();
                return;
            }
        }

        $job['ids'] = $this->batch->collectIds($this->areaId, $this->scope === 'filter' ? $this->filterParams : []);

        // Background
        // --------------------
        if ($background) {
            $error = '';
            if ($this->batch->start($job, $error) === null) {
                $this->batch->cleanupJob($job);
                $this->cms->error($this->cms->snippet('record.error_batch_start') . ' ' . $error);
                $this->errors();
                echo $this->backend->template('record.form_batch_cancel');
                return;
            }
            $this->backend->balloon('success', $this->cms->snippet('record.message_batch_started'));
            $this->backend->script('cApp.layers.invalidate(); cApp.layers.close();');
            return;
        }

        // In the request
        // --------------------
        $result = $this->batch->run($job);
        // the list below has changed: reload when it is shown again
        // (close button, breadcrumb navigation, browser "Back")
        $this->backend->script('cApp.layers.invalidate();');
        $items = '';
        $tplRow = $this->backend->template('record.batch_result_row');
        foreach ($result['errors'] as $id => $entry) {
            $items .= strtr($tplRow, [
                '{teaser}' => $id > 0 ? ($this->backend->teaser('record', (int)$id) ?: "[{$id}]") : '-',
                '{messages}' => htmlspecialchars(implode(' | ', $entry['messages'])),
            ]) . "\n";
        }
        echo $this->backend->template('record.batch_result', [
            '{class}' => $result['failed'] > 0 ? 'warning' : 'success',
            '{summary}' => htmlspecialchars($this->batch->summaryText($job, $result)),
            '{items}' => $items,
        ]);
    }
}
