<?php
/**
 * Move a record to another content area (extension for
 * RecordBackend).
 *
 * Entry point (script in backend/):
 *
 *     record_move.php  -> move()
 *
 * Reachable via the "Move" button of the detail view (RecordBackendInfo) -
 * not from a lookup window (src_id > 0): there it is about
 * linking, not about the record itself.
 *
 * TWO STEPS, ONE SCRIPT
 * --------------------
 *     without target_area_id   Step 1: choose the target area. The selection submits
 *                              the form right away (record.record_select_area_id).
 *     with target_area_id      Step 2: compare fields side by side - on the left the
 *                              input fields of the source area, on the right for each field a
 *                              selection of the matching target fields (MoveTrait::
 *                              moveTargets()), "Delete" first; the target field of the
 *                              same name is preselected.
 *     function=move            Move in two steps (MoveTrait):
 *                              movePrepare() creates the working copy in the target area -
 *                              if that already fails (mapping, lock, children),
 *                              the field mapping with the errors appears again.
 *                              moveSave() checks it in; afterwards the detail view
 *                              of the record is shown, which now belongs to the target area.
 *
 * IF THE CHECK IN THE TARGET AREA FAILS
 * --------------------
 * The target area may have fields that the source area does not know - a required
 * field without a mapping, a link to another area, stricter
 * limits. Instead of the field mapping, the INPUT MASK OF THE TARGET AREA
 * then appears with the working copy and the errors at its fields
 * (RecordBackendEdit::_editWorkingCopyForm()): the editor completes what is missing.
 * Saving is the check-in (record_edit.php, function=save - the copy carries the
 * area_id of the target area), cancelling discards the copy, and the record remains
 * unchanged in the previous area. Until then it is locked as with any edit.
 *
 * The errors of the mapping carry the name of the source field (field mapping),
 * those of the check the name of the TARGET FIELD, for multilingual fields "name.{language_id}" - just as
 * the input mask shows them at its fields.
 *
 * @see Camindo\Core\MoveTrait                            the actual work
 * @see Camindo\Core\Modules\Record\RecordBackendInfo     the button for it
 * @see Camindo\Core\Modules\Record\RecordBackendEdit     the input mask of the target area
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Modules\Area\Area;

class RecordBackendMove extends RecordBackendEdit {

    /**
     * Move mask (backend/record_move.php).
     *
     * Request: id, area_id, target_area_id (also as data[target_area_id] from the
     *          area selection), mapping[source_field] = target_field, function ('move')
     */
    public function move(): void
    {
        echo $this->backend->template('container_header');

        $this->function = 'info';
        $this->_resolveParams();
        if ($this->function !== 'info') {
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }

        // Bread crum navi
        $this->backend->setNaviLabel('#button_move');

        // Load the record - productive, not deleted - and the rights of the
        // user group: write + delete in the source area, and all
        // link fields must have readable target areas (developer docs, chapter 11 (Permissions));
        // RecordService::loadForAction(); the target area is checked by MoveTrait::movePrepare())
        if (!$this->data = $this->service->loadForAction($this->id, 'move')) {
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->areaId = (int)$this->data['area_id'];
        $this->areaData = $this->backend->areaData($this->areaId);
        $this->params = 'area_id=' . $this->areaId;

        // Area label, H1, record
        // --------------------
        echo $this->backend->template('record.prefix_content_area', [
            '{text}' => str_replace('{area_label}', $this->cms->textOrSnippet((string)$this->areaData['label']), $this->cms->snippet('area.headline_area_label'))
        ]);
        echo $this->backend->template('headline1', [
            '{text}' => '{#record.headline_record_move}'
        ]);
        echo $this->backend->template('p', [
            '{text}' => $this->backend->teaser('record', $this->id)
        ]);

        // Parameters of the steps
        // --------------------
        $function = (string)($_REQUEST['function'] ?? '');
        $targetAreaId = (int)($_REQUEST['data']['target_area_id'] ?? $_REQUEST['target_area_id'] ?? 0);
        $mapping = (array)($_REQUEST['mapping'] ?? []);

        // Move
        // --------------------
        if ($function === 'move' && $targetAreaId > 0) {
            $tmpId = $this->record->movePrepare($this->id, $targetAreaId, $mapping);
            if ($tmpId && $this->record->moveSave($tmpId)) {
                $this->backend->balloon('success', $this->cms->snippet('record.message_record_moved'));
                $this->backend->script(
                    "cApp.layers.invalidate();"
                    ."cApp.layers.replace('Record/record_info.php?area_id={$targetAreaId}&id={$this->id}');"
                );
                echo $this->backend->template('container_footer');
                return;
            }
            if ($tmpId) {
                // Check in the target area failed: the working copy is in the
                // target area, its input mask shows the errors (class comment)
                echo $this->backend->template('alert_warning', [
                    '{text}' => strtr($this->cms->snippet('record.warning_move_edit'), [
                        '{area}' => htmlspecialchars($this->cms->textOrSnippet((string)($this->backend->areaData($targetAreaId)['label'] ?? $targetAreaId))),
                    ]),
                ]);
                $this->errors();
                if ($this->_editWorkingCopyForm($tmpId)) {
                    echo $this->backend->template('container_footer');
                    return;
                }
                // Copy not accessible (should never happen): discard, back to the selection
                $this->record->cancel($tmpId);
                $this->errors();
            }
            else {
                // Mapping, lock, children: field mapping with the errors again
                $this->errors();
            }
        }

        // Step 2: compare fields side by side
        // --------------------
        $targetData = ($targetAreaId > 0 && $targetAreaId !== $this->areaId)
            ? $this->backend->areaData($targetAreaId)
            : null;
        if ($targetData && in_array((string)$targetData['type'], Area::CONTENT_TYPES, true)) {
            $this->_moveMappingForm($targetAreaId, $targetData, $mapping);
        }
        else {
            // Step 1: choose the target area
            $this->_moveAreaForm();
        }

        echo $this->backend->template('container_footer');
    }

    /**
     * Step 1: choose the target area.
     *
     * All record areas (Area::CONTENT_TYPES) except the own one, indented
     * by `depth`, in tree order. Areas of another instance get its
     * name as an addition - changing the instance is possible, but has consequences
     * (see MoveTrait). The selection submits the form right away.
     */
    private function _moveAreaForm(): void
    {
        echo '<form action="Record/record_move.php" method="post">';
        $this->backend->formInit('record.record_move_area_form');

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
        $this->backend->formField($this->record, [
            'name' => 'target_area_id',
            'value' => 0,
            'id' => $this->id,
            'colDef' => [
                'type' => 'integer',
                'display_type' => 'select',
                'label' => '#record.headline_move_target'
            ],
            'options' => $areaOptions,
            'template' => 'record.record_select_area_id',
            'selectPlease' => true
        ]);

        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('area_id', $this->areaId);
        echo $this->backend->form();
        echo '</form>';
    }

    /**
     * Step 2: map the fields of the source area to the fields of the target area.
     *
     * One row per source field: on the left label, name, type (and whether multilingual),
     * on the right the selection - "Delete" first, then the matching target fields
     * (MoveTrait::moveTargets()). The target field of the same name is preselected; after a
     * failed attempt, the editor's selection ($mapping).
     *
     * Errors: under the source field those of the mapping, under the selected target field
     * those of the input check in the target area (multilingual per language) - both appear
     * in the row of the source field.
     *
     * @param int   $targetAreaId
     * @param array $targetData    Backend::areaData() of the target area
     * @param array $mapping       selection from the request (empty on the first call)
     */
    private function _moveMappingForm(int $targetAreaId, array $targetData, array $mapping): void
    {
        $schemaSource = $this->record->virtualSchema($this->areaId, false);
        $schemaTarget = $this->record->virtualSchema($targetAreaId, false);
        $sourceFields = $this->record->moveFields($schemaSource);
        $targetFields = $this->record->moveFields($schemaTarget);
        $errorFields  = $this->cms->getErrorFields();
        $fromRequest  = !empty($mapping);
        $defaults     = $this->record->moveDefaultMapping($sourceFields, $targetFields);

        $tplRow = $this->backend->template('record.record_move_row');
        $tableRows = '';
        foreach ($sourceFields as $name => $colDef) {
            $type = (string)($colDef['type'] ?? '');
            $targets = $this->record->moveTargets($colDef, $targetFields);

            // preselection
            if ($fromRequest) {
                $selected = (string)($mapping[$name] ?? '');
            }
            else {
                $selected = $defaults[$name] ?? '';
            }

            // options: Delete, then the target fields
            $options = '<option value="">' . htmlspecialchars($this->cms->snippet('record.option_move_delete')) . "</option>\n";
            foreach ($targets as $targetName => $targetDef) {
                $optionLabel = $this->cms->textOrSnippet((string)($targetDef['label'] ?? $targetName))
                    . ' (' . $targetName . ')';
                $isSelected = ($selected === (string)$targetName) ? ' selected' : '';
                $options .= '<option value="' . htmlspecialchars((string)$targetName) . '"' . $isSelected . '>'
                    . htmlspecialchars($optionLabel) . "</option>\n";
            }

            // errors of this row: mapping (source field) and check (target field, per language)
            $errorTexts = [];
            foreach ($errorFields as $errorKey) {
                $errorKey = (string)$errorKey;
                if ($errorKey === $name
                    || ($selected !== '' && ($errorKey === $selected || str_starts_with($errorKey, $selected . '.')))
                ) {
                    $errorTexts[] = (string)($this->cms->errors[$errorKey]['text'] ?? '');
                }
            }
            $errorHtml = '';
            foreach (array_unique($errorTexts) as $text) {
                $errorHtml .= $this->backend->template('form_error', ['{id}' => 'c9-error-' . bin2hex(random_bytes(4)), '{text}' => $text]);
            }

            $multilingual = $this->cms->isMultilingualField($colDef)
                ? ' &middot; ' . $this->cms->snippet('record.label_move_multilingual')
                : '';
            $tableRows .= strtr($tplRow, [
                '{label}' => htmlspecialchars($this->cms->textOrSnippet((string)($colDef['label'] ?? $name))),
                '{name}' => htmlspecialchars((string)$name),
                '{type}' => htmlspecialchars($type),
                '{multilingual}' => $multilingual,
                '{options}' => $options,
                '{class}' => ($errorHtml !== '') ? 'c9-invalid' : '',
                '{error}' => $errorHtml,
            ]) . "\n";
        }

        // notice on instance change
        $instanceWarning = '';
        if ((int)($targetData['instance_id'] ?? 0) !== (int)($this->areaData['instance_id'] ?? 0)) {
            $instanceWarning = $this->backend->template('alert_warning', [
                '{text}' => '{#record.warning_move_instance}'
            ]);
        }

        echo '<form action="Record/record_move.php" method="post">';
        $this->backend->formInit('record.record_move_form', [
            '{instance_warning}' => $instanceWarning,
            '{source_area}' => $this->backend->teaser('area', $this->areaId, false),
            '{target_area}' => $this->backend->teaser('area', $targetAreaId, false),
            '{table_rows}' => $tableRows,
            '{params}' => $this->params,
            '{id}' => $this->id,
        ]);
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('target_area_id', $targetAreaId);
        $this->backend->formHidden('function', 'move');
        echo $this->backend->form('record.form_move_cancel', [
            '{params}' => $this->params,
            '{id}' => $this->id,
        ]);
        echo '</form>';
    }
}
