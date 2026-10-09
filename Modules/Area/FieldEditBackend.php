<?php
namespace Camindo\Core\Modules\Area;

class FieldEditBackend extends FieldBackend {

     /**
     * Create / Copy / Change / Delete
     * After step 1 (create) this is step 2 
     * (backend/field_edit.php).
     *
     * State machine via $_REQUEST['function'], as in the other modules
     * (developer docs, chapter 05 (Backend development)): create, edit, copy, save, errors, skip,
     * delete, delete_confirm.
     *
     * Two special cases up front, before the actual flow:
     *
     *  - `name` and `type` come from the URL (the type from the selection in create())
     *    and are strictly filtered here to [a-z0-9_-] before anything is done with them
     *    - they control the choice of the form template.
     *  - If the field is named like a fixed column from Cms::$schema, it is a meta field;
     *    then the type stored in the schema takes precedence over the one from the request.
     */
    public function edit(): void
    {
        // Seiten-Parameter
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->areaId = (int)($_REQUEST['area_id'] ?? 0);
        $this->function = (string)($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array)($_REQUEST['data'] ?? []);
        $this->data['field_id'] = $this->id;

        // name
        $name = preg_replace('![^a-z0-9_-]+!', '', (string) ($this->data['name'] ?? ''));
        $this->data['name'] = $name;

        // type
        $type = preg_replace('![^a-z0-9_-]+!', '', (string) ($this->data['type'] ?? ''));
        // Special case: meta field, type is fixed in the schema
        if (isset($this->cms->schema[$name])) {
            $type = $this->cms->schema[$name]['type'];
        }
        $this->data['type'] = $type;

        $this->fieldSchema = $this->field->getSchema(); // Schema `field`
        $this->recordSchema = $this->cms->schema;       // Schema `record` (Meta-Felder)

        // Bread crum navi
        $this->backend->setNaviLabel('#button_' . $this->function);

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

        // Load the associated area
        // (outputs the area name)
        if ($this->function !== 'skip') {
            echo $this->_loadArea();
        }

        // H1
        $this->_headline();

        // Save
        if ($this->function == 'save') {
            $this->_editSave();
        }

        // Target areas with links deselected: confirmation prompt
        if ($this->function == 'confirm_links') {
            $this->_editConfirmLinksForm();
        }

        // Delete confirm
        if ($this->function == 'delete_confirm') {
            $this->_editDeleteConfirm();
        }

        // Errors
        if (in_array($this->function, ['errors', 'skip'], true)) {
            $this->_editErrors();
        }

        // Edit form ('back': back from the prompt, with the entered data)
        if (in_array($this->function, ['create', 'edit', 'copy', 'errors', 'back'], true)) {
            $this->_editForm();
        }

        // Delete form
        if ($this->function == 'delete') {
            $this->_editDeleteForm();
        }

        echo $this->backend->template('container_footer');
    }


    /**
     * function=create: Formular vorbelegen.
     *
     * For a meta field, the schema definition from Cms::$schema is taken over as the default
     * (type, label, display, flags) - the editor should not have to
     * describe the fixed column from scratch.
     *
     * area_id must be set before init() runs: Field::hookOnCreate() calculates
     * the next free `position` from it.
     */
    private function _editCreate() {
        // Meta field: insert values defined in the schema as default values
        $name = $this->data['name'] ?? '';
        if (isset($this->cms->schema[$name])) {
            $this->data = $this->cms->schema[$this->data['name']];
            $this->data['name'] = $name;
        }
        // area_id is needed to calculate position
        $this->data['area_id'] = $this->areaId;
        $this->data = $this->field->init($this->data);
        // Combine the visibility fields into one
        $this->data['visibility'] = $this->_visibilitySerialize($this->data);
        $this->id   = 0;
    }

    /**
     * function=edit|copy|delete|delete_confirm: load the field and prepare it for the form
     * aufbereiten.
     *
     * Two transformations for the display:
     *   - the three visibility columns are combined into the pseudo field 'visibility'
     *   - height 0 means "automatic" and is shown as '(auto)'; when
     *     Saving turns it back into 0 via (int) cast in Field::hookOnSave()
     *
     * When copying, the id is discarded and the position is increased by one, so that the
     * copy is sorted after the original.
     */
    private function _editLoad(): void
    {
        if ($this->data = $this->field->load($this->id)) {
            $this->areaId = (int)$this->data['area_id'];

            // write all 3 visibility options into one pseudo field
            if ($this->data['type'] != 'fulltext') {
                $this->data['visibility'] = $this->_visibilitySerialize($this->data);
            }

            // height
            $this->data['height'] = (int)($this->data['height'] ?: 0);
            if ($this->data['height'] == 0) $this->data['height'] = '(auto)';

        } else {
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->cms->errorMsg()
            ]);
            $this->function = 'skip';
        }

        // If data is to be copied, reset the id now
        if ($this->function == 'copy') {
            $this->id = 0;
            $this->data['position']++;
        }
    }

    /**
     * function=save: speichern.
     *
     * Before that, two reverse conversions that the form requires:
     *   - 'visibility' (a checkbox group) back into three columns
     *   - for `pattern` the ^ and $ are cut off. In the form they stand as
     *     fixed prefix/suffix decoration next to the input field (see schema), stored
     *     is the expression without anchors.
     *
     * save([], ...) with an empty field list means: write all schema fields that are in
     * $data vorkommen.
     */
    private function _editSave(): void
    {
        if ($this->data['type'] != 'fulltext') {
            // Visibility comes from the pseudo form - back into 3 fields
            list (
                $this->data['visibility_input'],
                $this->data['visibility_search'],
                $this->data['visibility_list']
            ) = $this->_visibilityUnserialize($this->data);
        }

        // pattern
        // remove ^.. and ..$
        $pattern = (string) ($this->data['pattern'] ?? '');
        $pattern = ltrim($pattern, '^');
        $pattern = rtrim($pattern, '$');
        $this->data['pattern'] = $pattern;

        // pointer, link: target areas deselected that still have links attached?
        // -> ask first (_editConfirmLinksForm()); with delete_links=1 the
        // confirmation comes, the edges are then dropped after saving
        $deleteLinks = !empty($_REQUEST['delete_links']);
        $isMultiArea = $this->id > 0 && in_array($this->data['type'], $this->cms->multiAreaTypes, true);
        if ($isMultiArea && !$deleteLinks) {
            $this->linkCounts = $this->field->countRelationsOutside(
                $this->id,
                $this->system->sanitizeIds((array)($this->data['rel_area_ids'] ?? []), true)
            );
            if (!empty($this->linkCounts)) {
                $this->function = 'confirm_links';
                return;
            }
        }

        if ($this->field->save([], $this->data)) {
            if ($isMultiArea && $deleteLinks) {
                $this->field->removeRelationsOutside($this->id);
            }
            $this->backend->balloon('success', $this->cms->snippet('area.message_field_saved'));
            $this->backend->script("
                cApp.layers.invalidate();
                cApp.layers.close();
            ");
        } else {
            $this->function = 'errors';
        }
    }

    /**
     * The input form - the actual scope of this class.
     *
     * Reihenfolge:
     *
     *  1. Preliminary check for thumbnails: without an upload field in the same area there is
     *     nothing a thumbnail could be derived from -> abort with a note.
     *  2. Select HTML template by type (area.field_form_*). It determines the layout and
     *     which placeholders exist at all.
     *  3. Immer vorhanden: type (fest), name, label, note, tooltip, position,
     *     display_type, Sichtbarkeit, default_field, min/max.
     *  4. Type-dependent: prefix/suffix/pattern (text, number, date), height (text),
     *     custom_order and details.lookup_detailed (relations), target area(s)
 *     (relation/pointer), Mime-Typen
     *     (file+upload), source field and converter (file+thumbnail), source fields
     *     (fulltext).
     *  5. Flags in two groups: processing (trim, lowercase, …) and validation
     *     (not_empty, unique, …). Which are allowed is stated by Backend::processingFlags()
     *     or Backend::$validationFlags per type.
     *
     * $targetId is a random key with which the blocks that depend on display_type
     * (option note, custom_order) can be addressed in the DOM; the JavaScript
     * shows and hides them when the selection changes. Therefore the
     * display_type selection is triggered once at the end via trigger('change') - so that the
     * initial state is correct without rebuilding it here on the server.
     */
    private function _editForm(): void
    {
        if ($this->function == 'save') $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Area/field_edit.php" method="post"' . $changed . '>';

        $name = $this->data['name'] ?? '';
        if (isset($this->cms->schema[$name])) {
            $isMeta = true;
        }
        else {
            $isMeta = false;
        }
        $type = $this->data['type'];
        $displayType = $this->data['display_type'];
                
        // Thumbnail: first check whether an upload field is available in the area,
        // otherwise end early with an error message
        if ($displayType == 'thumbnail') {
            $sourceOptions = [];
            $sql = "SELECT `field_id`,`label` FROM `field`"
                ." WHERE `type`='file' AND `area_id`=? AND `field_id` != ?"
                ." ORDER BY `position` ASC";
            $this->db->query($sql, 'ii', [$this->areaId, $this->id]);
            if ($this->db->num_rows > 0) {
                // Upload-Felder sammeln
                while ($row = $this->db->fetch()) {
                    $sourceOptions[(int)$row['field_id']] = $this->cms->textOrSnippet($row['label']);
                }
            }
            else {
                echo $this->backend->template('alert_danger', [
                    '{text}' => '{#area.error_no_uploadfield_available}'
                    .'<br><br>'
                    .$this->backend->template('button_back', [
                        '{onclick}' => "cApp.layers.replace('Area/field_create.php?area_id={$this->areaId}')"
                    ])
                ]);

                // do not show the form in this case
                echo $this->backend->template('container_footer');
                exit;
            }
        }

        // Formular template laden
        // --------------------
        if ($name == 'template_id') {
            $templateId = 'area.field_form_' . $name;
        }
        elseif (in_array($type, ['chars', 'json', 'time', 'encrypted'], true)) {
            $templateId = 'area.field_form_text';
        }
        elseif (in_array($type, $this->cms->numericTypes, true)) {
            $templateId = 'area.field_form_number';
        }
        elseif (in_array($type, $this->cms->dateTypes, true)) {
            $templateId = 'area.field_form_datetime';
        }
        elseif ($type == 'file') {
            if ($displayType == 'upload') $templateId = 'area.field_form_upload';
            else $templateId = 'area.field_form_thumbnail';
        }
        else {
            // All other types
            $templateId = 'area.field_form_' . $type;
        }
        $this->backend->formInit($templateId);

        // type
        // --------------------
        $typeHtml = $type . ' (' . $this->cms->snippet('area.label_field_type_' . $type) . ')';
        $this->backend->formStatic($this->field, 'type', $typeHtml);
        $this->backend->formHidden('data[type]', $type);

        // label
        // --------------------
        $this->backend->formField($this->field, [
            'name' => 'label',
            'value' => $this->data['label'] ?? '',
            'id' => $this->id,
            'primary' => true
        ]);

        // name
        // --------------------
        if ($isMeta) {
            $this->backend->formStatic($this->field, 'name', $name);
            $this->backend->formHidden('data[name]', $name);
        }
        else {
            $this->backend->formField($this->field, [
                'name' => 'name',
                'value' => $name,
                'id' => $this->id
            ]);
        }

        // note, tooltip, prefix, suffix, default, pattern, height
        // --------------------
        $inputFields = ['note', 'tooltip'];
        if (
            in_array($type, $this->cms->textTypes, true)
            || in_array($type, $this->cms->numericTypes, true)
            || in_array($type, $this->cms->dateTypes, true)
        ) {
            $inputFields = array_merge($inputFields,
                ['prefix', 'suffix', 'pattern']
            );
        }
        if (in_array($type, ['text', 'chars', 'encrypted'], true)) {
            $inputFields = array_merge($inputFields,
                ['height']
            );
        }
        foreach ($inputFields as $fieldName) {
            $this->backend->formField($this->field, [
                'name' => $fieldName,
                'value' => $this->data[$fieldName] ?? '',
                'id' => $this->id
            ]);
        }

        // default
        $defaultColDef = $this->fieldSchema['default'];
        if (in_array($type, $this->cms->dateTypes, true)) {
            // Date: add special note and tooltip
            $defaultColDef['note'] = '#area.note_format_date_or_function';
            $defaultColDef['tooltip'] = '#area.tooltip_format_date_or_function';
        }
        $this->backend->formField($this->field, [
            'colDef' => $defaultColDef,
            'name' => 'default',
            'value' => $this->data['default'] ?? '',
            'id' => $this->id,
        ]);

        // position
        // --------------------
        // The editor selects the PREDECESSOR, the target position is saved:
        // therefore position + 1 as option key. The entry 0 is called "at the
        // start". The own field is excluded - it cannot be its own
        // predecessor.
        // The positions need not be gapless (e.g. 10, 14): preselected
        // is therefore the actual predecessor (last field with a smaller
        // position), not the key of its own position.
        $options = [0 => $this->cms->snippet('position_at_start')];
        $ownPosition = (int)($this->data['position'] ?? 0);
        $selected = 0;
        $sql = "SELECT * FROM `field` WHERE `area_id` =? AND `field_id` !=? ORDER BY `position` ASC";
        $this->db->query($sql, 'ii', [$this->areaId, $this->id]);
        
        while ($row = $this->db->fetch()) {
            $position = (int)$row['position'] + 1;
            $options[$position] = $this->cms->textOrSnippet($row['label']);
            if ($this->id > 0 && (int)$row['position'] < $ownPosition) {
                $selected = $position;
            }
        }
        if ($this->id <= 0) {
            // new/copied field: position is already the target position
            $selected = $this->data['position'];
        }
        $this->backend->formField($this->field, [
            'id' => $this->id,
            'name' => 'position',
            'value' => $selected,
            'options' => $options,
        ]);

        // display_type
        // --------------------
        // Id for dependent elements (onchange)
        $targetId = $this->crypt->randomKey(16);

        if ($type === 'file') {
            // no display type selection
            $this->backend->formHidden('data[display_type]', $displayType);
        }
        else {
            if (($isMeta && $type === 'integer') // z. B. status_id
                || $name == 'template_id'
            ) {
                
                $displayTypes = $this->backend->displayTypes['meta'] ?? [];
            }
            else {
                // Display type from the mapping table
                $displayTypes = $this->backend->displayTypes[$type ?? ''] ?? [];
            }
            $options = [];
            foreach ($displayTypes as $key) {
                $options[$key] = $this->cms->snippet('area.label_display_type_' . $key);
            }
            $this->backend->formField($this->field, [
                'name' => 'display_type',
                'value' => $displayType ?? '',
                'id' => $this->id,
                'options' => $options,
                'template' => 'area.edit_form_display_type' // spezielles Template
            ]);
            
            // Replace placeholders in the special template
            // cApp.forms.updateElement({element}, {action})
            $this->backend->formReplace('{element}', $targetId);
            if (in_array($type, $this->cms->relationTypes, true)) {
                $this->backend->formReplace('{elopt}', "lookup");
            }
            elseif ($type !== 'file' && !$isMeta) {
                $this->backend->formReplace('{elopt}', "select,multiselect,checkbox,radio");
            }
        }

        // Extension (developer docs, chapter 08 (Components and extensions))
        // --------------------
        // Own section "Extension" at the end of the mask (area.field_extension_block):
        // selection of the active extensions that match the field type, below them their
        // settings in the fieldset (area.field_extension_options); the JavaScript
        // (cApp.extensions.fieldForm) shows the matching ones per display type and
        // loads the options of the selected extension (field_extension_options.php).
        // Also for meta fields like online_at - they have a row in `field`, and
        // the virtual schema supplies their details to Backend::formField().
        // If there is no extension for the field type, the section is omitted entirely.
        $extensions = $this->backend->extensionsFor($type);
        $extensionOptionsId = $this->crypt->randomKey(16);
        if ($extensions) {
            $this->backend->formReplace('{extension_block}', $this->backend->template('area.field_extension_block'));
            $details = is_array($this->data['details'] ?? null) ? $this->data['details'] : [];
            $extension = (string)($details['extension'] ?? '');
            $options = ['' => $this->cms->snippet('area.label_extension_none')];
            $types = [];
            foreach ($extensions as $extName => $ext) {
                $options[$extName] = $ext['label'];
                $types[$extName] = $ext['display_types'];
            }
            $this->backend->formField($this->field, [
                'name' => 'details.extension',
                'value' => $extension,
                'id' => $this->id,
                'options' => $options,
                'template' => 'area.edit_form_extension',
                'attr' => [
                    'data-ext-types'   => htmlspecialchars(json_encode($types), ENT_QUOTES),
                    'data-ext-target'  => $extensionOptionsId,
                    'data-ext-url'     => "Area/field_extension_options.php?area_id={$this->areaId}&id={$this->id}&type=" . rawurlencode($type),
                ],
            ]);
            $this->backend->formReplace('{extension_options}',
                $this->_extensionOptionsHtml($extension, (array)($details['extension_options'] ?? [])));
        }
        else {
            $this->backend->formReplace('{extension_block}', '');
        }
        $this->backend->formReplace('{extension_options_id}', $extensionOptionsId);

        // note_default note on configuring selectable options
        // --------------------
        // Explains how to store the selectable options in the field `default`.
        $noteHtml = '';
        if(!in_array($type, $this->cms->relationTypes, true)
            && $type !== 'file'
            && !$isMeta
         ) {
            $noteHtmlInner = $this->backend->template('area.field_default_options_note');
            $noteHtml = $this->backend->template('edit_form_block', [
                '{label}' => '',
                '{class}' => '',
                '{field}' => $noteHtmlInner,
            ]);
        }
        $this->backend->formReplace('{note_default}', $noteHtml);
        $this->backend->formReplace('{note_default_id}', $targetId);

        // custom_order, details.lookup_detailed
        // --------------------
        // Both in the block {custom_order_id}, which the JavaScript only
        // display_type 'lookup' einblendet
        if (in_array($type, $this->cms->relationTypes, true)) {
            // ('link' is always hand-sorted, Field::hookOnSave() - no switch)
            if (!in_array($type, $this->cms->dependentTypes, true)) {
                $this->backend->formField($this->field, [
                    'name' => 'custom_order',
                    'value' => $this->data['custom_order'],
                    'id' => $this->id
                ]);
            }
            $this->backend->formField($this->field, [
                'name' => 'details.lookup_detailed',
                'value' => (int)($this->data['details']['lookup_detailed'] ?? 0),
                'id' => $this->id
            ]);
            $this->backend->formReplace('{custom_order_id}', $targetId);
        }

        // visibility (combine all 3 options in one pseudo-field)
        // --------------------
        if ($type !== 'fulltext') {
            $colDef = $this->_visibilityColDef(); // (pseudo field 'visibility')
            if ($displayType == 'thumbnail') {
                // Thumbnail field: no visibility in the input mask 
            }
            $this->backend->formField($this->field, [
                'colDef' => $colDef,
                'name' => 'visibility',
                'value' => $this->data['visibility'],
                'id' => $this->id,
                'options' => $this->_visibilityOptions($displayType)
            ]);
        }

        // default field
        // --------------------
        $this->backend->formField($this->field, [
            'name' => 'default_field',
            'value' => $this->data['default_field'],
            'id' => $this->id,
        ]);

        // multilingual
        // --------------------
        // Only for types whose contents can be kept per language, and not
        // for fields on meta columns (Field::hookOnSave() resets it there).
        // Without the field, the placeholder {data_multilingual} stays empty.
        // Upload fields yes, thumbnails no - they inherit it from the upload field.
        // The flag can only be switched when creating (also copies): for an
        // existing field it would affect the stock (translations) - this
        // is done only by the explicit conversion (convert()); here it is fixed.
        if (!$isMeta
            && (in_array($type, $this->cms->multilingualTypes, true)
                || ($type === 'file' && $displayType === 'upload'))
        ) {
            if ($this->id > 0) {
                $multilingualHtml = !empty($this->data['multilingual'])
                    ? $this->backend->template('icon_checked') . ' ' . $this->cms->snippet('yes')
                    : $this->cms->snippet('no');
                $multilingualHtml .= ' <small class="c9-grey-50">{#area.note_field_convert_multilingual_fixed}</small>';
                $this->backend->formStatic($this->field, 'multilingual', $multilingualHtml);
            }
            else {
                $this->backend->formField($this->field, [
                    'name' => 'multilingual',
                    'value' => $this->data['multilingual'] ?? 0,
                    'id' => $this->id,
                ]);
            }
        }

        // min + operator
        // --------------------
        $this->_editFormMinMaxField('min');

        // max + operator
        // --------------------

        if (str_ends_with($type, '1')) {
            $this->backend->formStatic($this->field, 'max', 1);
        }
        else {
            $this->_editFormMinMaxField('max');
        }

        // processing flags
        // --------------------
        $processingOptions = [];
        $processingKeys = array_flip($this->backend->processingFlags($type));
        foreach ($processingKeys as $key => $val) {
            $processingOptions[$key] = htmlspecialchars($this->cms->snippet('area.flag_' . $key));
        }
        if (!empty($processingKeys)) {
            $colDef = $this->fieldSchema['flags'];
            $colDef['label'] = '#area.headline_processing_flags';
            $this->backend->formField($this->field, [
                'colDef' => $colDef,
                'name' => 'flags',
                'value' => $this->data['flags'] ?? '',
                'id' => $this->id,
                'options' => $processingOptions,
                'placeholder' => '{data_processing_flags}'
            ]);
        }

        // validation flags
        // --------------------
        $validationOptions = array_flip($this->backend->validationFlags[$type] ?? []);
        if (!empty($validationOptions)) {
            foreach ($validationOptions as $key => &$val) {
                $val = htmlspecialchars($this->cms->snippet('area.flag_' . $key)); 
            }
            unset($val);
            $colDef = $this->fieldSchema['flags'];
            $colDef['label'] = '#area.headline_validation_flags';

            // if global... in flags, then show a note
            // (mittels speziellem inputField-Template)
            if (
                isset($validationOptions['unique'])
                || isset($validationOptions['unique_in_area'])
            ) {
                $inputFieldTemplate = 'area.group_container_flags';
            }

            $this->backend->formField($this->field, [
                'colDef' => $colDef,
                'name' => 'flags',
                'value' => $this->data['flags'] ?? '',
                'id' => $this->id,
                'options' => $validationOptions,
                'template' => $inputFieldTemplate ?? '',
                'placeholder' => '{data_validation_flags}'
            ]);
        }

        // Collect target areas (for both rel_area_id and rel_area_ids) -
        // see _relAreaOptions()
        // --------------------
        $relAreaOptions = [];
        if (in_array($type, $this->cms->relationTypes, true)) {
            $relAreaOptions = $this->_relAreaOptions($type);
        }

        // rel_area_id (select)
        // --------------------
        if (str_starts_with($type, 'relation')) {
            $this->backend->formField($this->field, [
                'name' => 'rel_area_id',
                'value' => (int)($this->data['rel_area_id'] ?? 0),
                'id' => $this->id,
                'options' => $relAreaOptions,
                'selectPlease' => true
            ]);
        }
        
        // rel_area_ids (multiselect) - pointer, link
        // --------------------
        if (in_array($type, $this->cms->multiAreaTypes, true)) {
            // Typ 'reltable' (Inhalt liegt in `rel_field_area`) - formField() behandelt
            // it like 'json'; the type conversion is therefore no longer necessary.
            $colDef = $this->field->getSchema()['rel_area_ids'];
            $this->backend->formField($this->field, [
                'name' => 'rel_area_ids',
                'value' => (array)($this->data['rel_area_ids'] ?? []),
                'id' => $this->id,
                'options' => $relAreaOptions,
                'colDef' => $colDef
            ]);
        }

        // file types
        // --------------------
        if ($type === 'file') {
            
            // upload
            if ($displayType === 'upload') {
                    
                // media_types
                // --------------------
                $mimeOptions = array_flip(array_keys($this->field->mimeTypes));
                foreach ($mimeOptions as $key => &$val) {
                    $val = $this->cms->snippet('area.mime_type_' . $key);
                }
                unset($val);
                $this->backend->formField($this->field, [
                    'id' => $this->id,
                    'name' => 'details.media_types',
                    'value' => $this->data['details']['media_types'] ?? [],
                    'options' => $mimeOptions,
                ]);

                // media_types_incl
                // --------------------
                $this->backend->formField($this->field, [
                    'id' => $this->id,
                    'name' => 'details.media_types_incl',
                    'value' => $this->data['details']['media_types_incl'] ?? '',
                ]);

                // media_types_excl
                // --------------------
                $this->backend->formField($this->field, [
                    'id' => $this->id,
                    'name' => 'details.media_types_excl',
                    'value' => $this->data['details']['media_types_excl'] ?? '',
                ]);
            }

            // thumbnail
            elseif ($displayType === 'thumbnail') {

                // parent_field_id
                // --------------------
                $this->backend->formField($this->field, [
                    'id' => $this->id,
                    'name' => 'parent_field_id',
                    'value' => $this->data['parent_field_id'],
                    'options' => $sourceOptions,
                ]);

                // converter_ids
                // --------------------
                $converterOptions = $this->_converterOptions();
                $this->backend->formField($this->field, [
                    'id' => $this->id,
                    'name' => 'converter_ids',
                    'value' => $this->data['converter_ids'] ?? [],
                    'options' => $converterOptions
                ]);
            }

            // Authentication (upload and thumbnail)
            // --------------------
            // user_auth: files only via file.php to logged-in frontend users
            // from the ticked profile areas (instance of the area and its
            // readable instances). The checkbox list is only with set
            // Schalter sichtbar (Script unten).
            if (in_array($displayType, ['upload', 'thumbnail'], true)) {
                $this->backend->formField($this->field, [
                    'id' => $this->id,
                    'name' => 'details.user_auth',
                    'value' => (int)($this->data['details']['user_auth'] ?? 0),
                ]);
                $instanceId = (int)($this->backend->areaData($this->areaId)['instance_id'] ?? 0);
                $profileOptions = $this->backend->profileAreaOptions($instanceId);
                if (empty($profileOptions)) {
                    $this->backend->formReplace('{data_details_profile_area_ids}', $this->backend->template('alert_warning', [
                        '{text}' => '{#area.no_profile_areas_found}'
                    ]));
                }
                else {
                    $this->backend->formField($this->field, [
                        'id' => $this->id,
                        'name' => 'details.profile_area_ids',
                        'value' => $this->system->sanitizeIds((array)($this->data['details']['profile_area_ids'] ?? []), true),
                        'options' => $profileOptions,
                    ]);
                }
            }
        }

        // fulltext
        if ($type === 'fulltext') {
            // source_fields
            // --------------------
            // Text fields and upload fields (file name), no thumbnails - of the area
            // and via its link/relation fields of the target areas
            // (Field::fulltextSourceOptions()); one subheading per target area
            $options = [];
            $group = '';
            foreach ($this->field->fulltextSourceOptions($this->areaId) as $key => $option) {
                if ($option['group'] !== $group) {
                    $group = $option['group'];
                    $options['group:' . count($options)] = ['group' => $group];
                }
                $options[$key] = $option['label'];
            }
            if (empty($options)) {
                // Note: no text fields are defined in the area
                $nothingFoundBlock = $this->backend->template('alert_warning', [
                    '{text}' => '{#area.no_text_fields_found}'
                ]);
                $this->backend->formReplace('{data_source_fields}', $nothingFoundBlock);
            }
            else {
                // Create pseudo field
                // Content will be written by Field->hookOnSaved into external table
                $colDef = [
                    'name' => 'source_fields',
                    'label' => '#area.field_field_source_fields',
                    'type' => 'json',
                    'display_type' => 'checkbox'
                ];
                $this->backend->formField($this->field, [
                    'id' => $this->id,
                    'colDef' => $colDef,
                    'name' => 'source_fields',
                    'value' => $this->data['source_fields'],
                    'options' => $options,
                ]);
            }

            // visibility_search (special for 'fulltext')
            // --------------------
            $this->backend->formField($this->field, [
                'id' => $this->id,
                'name' => 'visibility_search',
                'value' => $this->data['visibility_search']
            ]);

            $this->backend->formHidden('data[display_type]', 'text');
        }
        
        // Hidden-Felder
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('data[area_id]', $this->areaId);
        $this->backend->formHidden('function', 'save');

        // Print form
        echo $this->backend->form('form_save_cancel_note', removePlaceholders:true);

        // JavaScripte
        // - instruction for growing textarea, after Ajax load
        $script = "cApp.ui._initTextareaGrow(\$('.c9-layer:last'));";

        // - display_type-Liste triggern
        $script .= "\$('.c9-layer:last select[name=\"data[display_type]\"]').trigger('change');";
        // - show profile areas only if user_auth is set
        if ($type === 'file' && in_array($displayType, ['upload', 'thumbnail'], true)) {
            $script .= "(function(){"
                . "var \$l=\$('.c9-layer:last'),\$s=\$l.find('input[name=\"data[details][user_auth]\"]'),"
                . "\$p=\$l.find('.c9-user-auth-areas');"
                . "function t(){\$p.toggle(\$s.filter(':checked').length>0);}"
                . "\$s.on('change',t);t();"
                . "})();";
        }
        $this->backend->script($script);

        echo '</form>';
    }

    /**
     * Selection list of the target areas for relation (select, rel_area_id) and
     * pointer/link (multiselect, rel_area_ids).
     *
     * What appears:
     *
     *     instance        the instance of the source area and its readable instances
     *                    (Field::targetInstanceIds()) - areas of foreign instances with
     *                    Zusatz "(Instanz)"
     *     group rights   only areas with read right for the user group (Access::areaRead())
     *     relation       the own area stays visible, but locked
     *     link           only DEPENDENT areas (area.dependent = 1), not the own one;
     *                    everything else is missing (Field::hookOnSave() checks when saving)
     *     folder          locked (no records), and only those under which a
     *                    selectable area lies - the indentation comes from `depth`,
     *                    the order from `lft`; template areas are missing
     *     already selected targets always appear, even outside instance and
     *     rights - otherwise they would be silently deselected when saving
     *
     * Locked entries carry the prefix 'disabled_' in the key and are thereby
     * no longer a valid id (Backend::formField() renders them as disabled).
     *
     * @return array  area_id|'disabled_'.area_id => Label
     */
    private function _relAreaOptions(string $type): array
    {
        $access = $this->backend->access();
        $instanceIds = $this->field->targetInstanceIds($this->areaId);
        $ownInstanceId = (int)($this->areaData['instance_id'] ?? 0);
        $isLink = in_array($type, $this->cms->dependentTypes, true);
        $isRelation = str_starts_with($type, 'relation');

        // already selected targets (from the form or the field definition)
        $currentIds = $isRelation
            ? $this->system->sanitizeIds([(int)($this->data['rel_area_id'] ?? 0)], true)
            : $this->system->sanitizeIds((array)($this->data['rel_area_ids'] ?? []), true);
        $currentSql = empty($currentIds) ? '' : " OR `area_id` IN (" . implode(',', $currentIds) . ")";

        $this->db->query("SELECT `area_id`,`instance_id`,`depth`,`lft`,`rgt`,`label`,`type`,`dependent` FROM `area`"
            ." WHERE `type` != 'template' AND `deleted`=0"
            ." AND (`instance_id` IN (" . implode(',', $instanceIds) . ")" . $currentSql . ")"
            ." ORDER BY `lft` ASC");
        $rows = $this->db->fetchAll();

        // 1st pass: selectable / locked / removed
        $entries = [];      // [area_id, key, label, lft, rgt, isFolder]
        $selectable = [];   // [lft, rgt] of the selectable areas (for the folders)
        foreach ($rows as $areaData) {
            $areaId = (int)$areaData['area_id'];
            $isCurrent = in_array($areaId, $currentIds, true);
            $label = str_repeat('&nbsp; ', (int)$areaData['depth'] * 2) . $this->cms->textOrSnippet($areaData['label']);
            if ((int)$areaData['instance_id'] !== $ownInstanceId) {
                $instanceLabel = (string)($this->instanceCache->instance((int)$areaData['instance_id'])['label'] ?? '');
                $label .= strtr($this->cms->snippet('parenthetical_note'), [
                    '{text}' => $this->cms->textOrSnippet($instanceLabel)
                ]);
            }
            if ($areaData['type'] === 'folder') {
                $entries[] = [$areaId, 'disabled_' . $areaId, $label, (int)$areaData['lft'], (int)$areaData['rgt'], true];
                continue;
            }
            if ($isCurrent) {
                $key = (string)$areaId;
            }
            elseif (!$access->areaRead($areaId)) {
                continue;
            }
            elseif ($isRelation && $areaId === $this->areaId) {
                // relation - own content area cannot become a target area
                $key = 'disabled_' . $areaId;
            }
            elseif ($isLink && (empty($areaData['dependent']) || $areaId === $this->areaId)) {
                // link - only dependent areas, not the own one
                continue;
            }
            else {
                $key = (string)$areaId;
            }
            if ($key === (string)$areaId) $selectable[] = [(int)$areaData['lft'], (int)$areaData['rgt']];
            $entries[] = [$areaId, $key, $label, (int)$areaData['lft'], (int)$areaData['rgt'], false];
        }

        // 2nd pass: folders only if something selectable lies below them
        $options = [];
        foreach ($entries as [$areaId, $key, $label, $lft, $rgt, $isFolder]) {
            if ($isFolder) {
                $hasSelectable = false;
                foreach ($selectable as [$sLft, $sRgt]) {
                    if ($sLft > $lft && $sRgt < $rgt) { $hasSelectable = true; break; }
                }
                if (!$hasSelectable) continue;
            }
            $options[$key] = $label;
        }
        return $options;
    }

    /**
     * All converters as options of the sortable field converter_ids
     * (converter_id => label with processor). Not only the selected ones: the
     * form must offer more. The stored order is produced
     * by Backend::formField() (display_type 'sortable') itself - the selected ones
     * at the top in the order of $value, the rest below.
     */
    private function _converterOptions(): array
    {
        $options = [];
        $this->db->pushResult();
        $this->db->query("SELECT `converter_id`, `processor`, `label` FROM `converter` ORDER BY `type` ASC, `processor` ASC, `label` ASC");
        foreach ($this->db->fetchAll() as $row) {
            $options[(int)$row['converter_id']] = $this->cms->textOrSnippet((string)$row['label']) . ' (' . $row['processor'] . ')';
        }
        $this->db->popResult();
        return $options;
    }

    private function _editFormMinMaxField($name): void
    {
        $type = $this->data['type'] ?? '';
        $colDef = $this->fieldSchema[$name];
        if (in_array($type, $this->cms->dateTypes, true)) {
            // date: add special note and tooltip
            $colDef['note'] = '#area.note_format_date_or_function';
            $colDef['tooltip'] = '#area.tooltip_format_date_or_function';
        }
        elseif (in_array($type, $this->cms->textTypes, true)) {
            // text: add special note
            $colDef['note'] = '#area.note_format_text_length';
        }
        // assign special template
        $this->backend->formField($this->field, [
            'colDef' => $colDef,
            'name' => $name,
            'value' => $this->data[$name] ?? '',
            'id' => $this->id,
            'template' => 'area.edit_form_input_' . $name,
        ]);
        if (
            $name == 'min' && $this->data['min_op'] == '>'
            || $name == 'max' && $this->data['max_op'] == '<'
        ) {
            // '>'/'<'
            $selected_1 = '';
            $selected_2 = 'selected';
        }
        else {
            // '>='/'<='
            $selected_1 = 'selected';
            $selected_2 = '';
        }
        $this->backend->formReplace('{selected_1}', $selected_1);
        $this->backend->formReplace('{selected_2}', $selected_2);
    }

    /**
     * Confirmation prompt when saving a pointer/link field: in deselected
     * target areas links are still attached (Field::countRelationsOutside()).
     *
     * Shows the count per area and lets one choose: "Delete links" sends
     * the form again with delete_links=1 (_editSave() saves and clears with
     * Field::removeRelationsOutside()), "Cancel" leads back to the form with the entered
     * data (function 'back'). The form data travels along for this
     * as hidden fields.
     */
    private function _editConfirmLinksForm(): void
    {
        $items = '';
        $total = 0;
        foreach ($this->linkCounts as $areaId => $count) {
            $total += $count;
            $items .= '<li>' . $this->backend->teaser('area', (int)$areaId)
                . ': ' . strtr($this->cms->snippet('area.note_field_links_count'), ['{count}' => $count]) . '</li>';
        }

        echo '<form action="Area/field_edit.php" method="post">';
        $this->backend->formInit('area.field_links_confirm_form', [
            '{text}' => strtr($this->cms->snippet('area.alert_field_links_outside'), ['{count}' => $total]),
            '{list}' => $this->backend->template('ul', ['{items}' => $items]),
        ]);
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('delete_links', 1);
        $this->_formHiddenArray('data', $this->data);
        echo $this->backend->form();
        echo '</form>';
    }

    /**
     * A (nested) array as hidden fields name[key][sub]=… in the form.
     */
    private function _formHiddenArray(string $name, array $data): void
    {
        foreach ($data as $key => $value) {
            $fullName = $name . '[' . $key . ']';
            if (is_array($value)) $this->_formHiddenArray($fullName, $value);
            elseif ($value !== null) $this->backend->formHidden($fullName, (string)$value);
        }
    }



    /**
     * Really delete? Form
     */
    private function _editDeleteForm(): void
    {
        echo '<form action="Area/field_edit.php" method="post">';

        $this->backend->formInit('area.field_delete_form');

        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');

        // Print form
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }

    /**
     * Perform deletion
     */
    private function _editDeleteConfirm(): void
    {
        if ($this->field->delete($this->id)) {
            // Balloon Message
            $msg = $this->cms->snippet('area.message_field_deleted');
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
     * Fehlermeldungen
     */
    private function _editErrors(): void
    {
        echo $this->backend->template('alert_danger',[
            '{text}' => $this->cms->errorMsg()
        ]);
    }

    // ====================
    // Helper functions to treat the visibility options as a shared pseudo field
    // Three bit columns in the table, a checkbox group in the form. The
    // field definition for it is in no schema - it is built here by hand and
    // passed to formField() via 'colDef'.

    private function _visibilityColDef() {
        return [
            'name' => 'visibility',
            'type' => 'json',
            'label' => '#area.headline_field_visibility',
            'display_type' => 'checkbox'
        ];
    }

    /**
     * Which of the three visibilities are available for selection at all.
     *
     * A thumbnail is created from another field and is never entered; only
     * its visibility in the record list remains.
     *
     * @param string $displayType  display_type, because some fields do not allow visibilityen
     */
    private function _visibilityOptions(string $displayType) {
        if ($displayType == 'thumbnail') {
            $options = [
                'list' => $this->cms->snippet('area.field_field_visibility_list')
            ];
        }
        else {
            $options = [
                'input' => $this->cms->snippet('area.field_field_visibility_input'),
                'search' => $this->cms->snippet('area.field_field_visibility_search'),
                'list' => $this->cms->snippet('area.field_field_visibility_list')
            ];
        }
        return $options;
    }

    private function _visibilitySerialize(array $data) {
        $dataVisibility = [];
        if (!empty($data['visibility_input']))  $dataVisibility[] = 'input';
        if (!empty($data['visibility_search'])) $dataVisibility[] = 'search';
        if (!empty($data['visibility_list']))   $dataVisibility[] = 'list';
        return $dataVisibility;
    }

    private function _visibilityUnserialize(array $data) {
        $dataVisibility = (array) ($data['visibility'] ?? []);
        return [
            (int)in_array('input', $dataVisibility, true),
            (int)in_array('search', $dataVisibility, true),
            (int)in_array('list', $dataVisibility, true)
        ];
    }

    // ====================
    // Helper functions for extensions

    /**
     * Optionen einer Extension nachladen (backend/field_extension_options.php) -
     * when the extension selection changes in the field input mask.
     *
     * Request: extension, type (Feldtyp), data[details][extension_options] (bisherige Werte)
     */
    public function extensionOptions(): void
    {
        $extension = preg_replace('/[^A-Za-z0-9]/', '', (string)($_REQUEST['extension'] ?? ''));
        $type = preg_replace('/[^a-z0-9_]/', '', (string)($_REQUEST['type'] ?? ''));
        if ($extension === '' || !isset($this->backend->extensionsFor($type)[$extension])) {
            return;
        }
        $options = (array)($_REQUEST['data']['details']['extension_options'] ?? []);
        echo $this->_extensionOptionsHtml($extension, $options);
    }

    /**
     * Options block of an extension: {Extension}/backend/form.php renders with
     * $backend/$cms inputs under the name $name[…] (developer docs, chapter 08 (Components and extensions)); without form.php there
     * are no options.
     */
    private function _extensionOptionsHtml(string $extension, array $options): string
    {
        $file = $this->_extensionFile($extension, 'form.php');
        if ($file === null) return '';
        $html = (static function (string $file, \Camindo\Core\Backend $backend, \Camindo\Core\Cms $cms, array $options, string $name): string {
            ob_start();
            include $file;
            return (string)ob_get_clean();
        })($file, $this->backend, $this->cms, $options, 'data[details][extension_options]');
        if (trim($html) === '') return '';
        return $this->backend->template('area.field_extension_options', ['{options}' => $html]);
    }

}