<?php
/**
 * Input mask for records (extension for RecordBackend).
 *
 * Entry points (scripts in backend/):
 *
 *     record_edit.php           -> edit()           create / copy / change / delete
 *     ajax_cancel.php           -> cancel()         cancel editing
 *     ajax_lookup_preview.php   -> lookupPreview()  edit links
 *     ajax_embed.php            -> embedAction()    embedded contents (field type 'link')
 *     record_previous.php       -> updatePreviousOptions()  reload the predecessor list
 *     ajax_parent_lookup.php    -> parentLookupPreview()    choose the parent record
 *
 * The most elaborate mask in the backend, for three reasons: the fields are not fixed,
 * work is never done on the original, and links and files are already written
 * DURING editing, not only when saving.
 *
 * WORK IS ALWAYS DONE ON A WORKING COPY
 * --------------------
 * This is the key to understanding this class. When the mask is opened, Record::checkOut()
 * creates a copy of the record with tmp=1 and origin_id; from then on $this->id refers
 * to THIS copy, not the original. Only saving (checkIn within Record::save()) transfers
 * and removes it; "Cancel" (cancel()) discards it.
 *
 * This solves several problems at once:
 *   - A link or an upload needs a record_id in order to be saved. When creating a new
 *     record there is none yet - that is why the working copy already arises when the
 *     empty form is opened (_editCreate() -> tmpInit()) and not only when saving.
 *   - A failed input check throws nothing away: files and links are already
 *     attached to the working copy.
 *   - The frontend never sees a half-finished state.
 *
 * EDIT LOCK
 * --------------------
 * The working copy is at the same time the lock: if _editLoad() finds one, someone
 * else is working on the record at the moment. A note with name and time is then shown,
 * along with a link that bypasses the lock with unlock=1.
 *
 * CREATING IN TWO STEPS
 * --------------------
 * Without an area, function=create first shows the area selection (_editCreateForm());
 * only with an area do the working copy and the actual mask arise. The area finally
 * determines which fields exist at all.
 *
 * VALUES ARE CONVERTED FOR DISPLAY
 * --------------------
 * _editFormatLoadedValues() converts database into input formats: date and time
 * into the local format, floating-point numbers with the local decimal separator, amounts from
 * cents into euros. Links are turned into id arrays so that a multi-select field
 * can handle them. The way back is taken by Dataset when saving.
 *
 * THE PARENT RECORD AND THE POSITION BELONG TOGETHER
 * --------------------
 * As in the module Area (see AreaBackend), `position` is not entered as a number,
 * but via the PRECEDING record. The field is still called `position` in the setup -
 * for the editor this is logical, without a parent record the position is simply the sorting -,
 * but the form contains `previous_id` (_editPreviousParams()); TreeManagerTrait
 * calculates the number from it when saving.
 *
 * Both are linked: only the siblings under the currently selected parent record are ever listed.
 * If the editor changes it, the list is obsolete and is fetched anew via Ajax
 * (updatePreviousOptions()) - regardless of whether the parent record is chosen via a
 * selection field or via a lookup field.
 *
 * MULTILINGUAL FIELDS
 * --------------------
 * If the instance of the area maintains more than one language (InstanceCache), each
 * field with `multilingual` = 1 gets an input field per language: data[field][{language_id}],
 * label with the language code as addition (Backend::formField(), langId).
 * The area selects the display (area.details.tabs):
 *
 *     tabs = 0   all languages one below the other on one page
 *     tabs = 1   one tab per language plus "All". On a language tab the
 *                mask shows only this language; single-language fields appear on every tab.
 *                A tab switch saves the mask in between (function=tab ->
 *                Record::save() with stayTemporary, see _editTab()) and reloads it with
 *                the new tab - the other languages remain in the working state of the
 *                working copy (Dataset::save() merges them per language).
 *
 * The values of the languages come from the working state of the working copy (there the
 * raw inputs of all languages are stored, empty = not translated), after a request from
 * this (_editLanguageValues()).
 *
 * TWO WAYS TO SET LINKS
 * --------------------
 *     display_type != 'lookup'   selection list with all records of the target areas
 *                                (_relatedDatasetOptions()) - only sensible with manageable
 *                                volumes, therefore limited to
 *                                admin.list_max_datasets
 *     display_type == 'lookup'   search window; the link is written IMMEDIATELY
 *                                (lookupPreview() -> Record::createRelations()), not
 *                                only when the form is saved
 *
 * FIRMLY LINKED CONTENTS (field type 'link', Camindo\Core\LinkTrait)
 * --------------------
 * The target records belong to the record. As 'lookup' as above; as 'embed' their
 * input masks stand in this mask (_embedBlock(), backend-embedded.js,
 * ajax_embed.php -> embedAction()). The contents are checked out together with the record
 * (_editLoad() -> checkOutDependents()), cached (_embedSave()) and
 * checked in (_editSave() -> checkInDependents()); cancelling discards them along
 * (Record::cancel()).
 *
 * @see Camindo\Core\TemporaryTrait                      checkOut / checkIn / cancel
 * @see Camindo\Core\Modules\Record\RecordBackend        shared base
 * @see Camindo\Core\Modules\Record\RecordBackendUpload  files in this mask
 * @see Camindo\Core\Modules\Record\RecordBackendInfo    the detail view for it
 * @see developer docs, chapter 03 (Dataset API)
 */
namespace Camindo\Core\Modules\Record;

class RecordBackendEdit extends RecordBackend {

    // inputs of the embedded contents (fields of type 'link', display_type
    // 'embed'): $_REQUEST['embed'][{record_id}][field] - see _embedCell()
    protected array $embedData = [];

    /** Message "locked by another user" (_editLoad()) */
    private string $lockAlert = '';

    /**
     * Create / copy / change / delete (backend/record_edit.php).
     *
     * State machine via $_REQUEST['function'] as in the other modules
     * (developer docs, chapter 05 (Backend development)) - with two special features:
     *
     *  - 'create' has two forms: without an area the area selection, with
     *    an area the actual form (see class comment).
     *  - Before the form branch, for 'errors', _editRestoreSecuredFiles() also runs:
     *    uploads that have already been secured must return to the mask that is
     *    shown again, because file fields are not in the POST.
     *
     * The field list for saving comes from the schema of the area - a fixed
     * list as in the other modules would not work here, the fields are user-defined.
     *
     * CHECK-OUT ONLY VIA POST (developer docs, chapter 14 (Security: web))
     * --------------------
     * 'edit', 'copy' and 'create' with an area create a working copy and set the
     * edit lock - a side effect. Via GET, edit() therefore only answers with
     * a placeholder (_editOpenStub()): if the layer was opened from the interface
     * (cApp.layers.add()/replace(), data-intent), the JavaScript fetches the mask
     * immediately via POST - with CSRF token. If the layer comes from the hash
     * (bookmark, F5, back button, a link from outside, frontend editing), the
     * placeholder shows a "Edit" button: only the click checks out.
     */
    public function edit(): void
    {
        echo $this->backend->template('container_header');

        // page parameters
        $this->function = (string)($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array)($_REQUEST['data'] ?? []);
        $this->embedData = (array)($_REQUEST['embed'] ?? []);
        
        $this->_resolveParams();
        
        $this->data['record_id'] = $this->id;

        // Bread crum navi
        $this->backend->setNaviLabel('#button_' . $this->function);

        // check-out via GET: only the placeholder (see above)
        $checksOut = in_array($this->function, ['edit', 'copy'], true)
            || ($this->function === 'create' && !empty($this->areaId));
        if ($checksOut && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST' && PHP_SAPI !== 'cli') {
            $this->_editOpenStub();
            echo $this->backend->template('container_footer');
            return;
        }

        // load
        if (in_array($this->function, ['edit', 'copy', 'delete'], true)) {
            $this->_editLoad();
        }

        // H1
        echo $this->headline();

        // locked by another user (_editLoad())
        if ($this->function === 'locked') {
            echo $this->lockAlert;
        }

        if (!empty($this->areaId)) {
            $schemaVirtual = $this->record->virtualSchema($this->areaId, addMetaFields:false);
        }

        if (in_array($this->function, ['edit', 'copy'], true)) {
            // convert date fields and floating-point numbers to the local format
            // provide relations as an array if the input field matches this format
            $this->_editFormatLoadedValues($schemaVirtual);
        }

        // Create
        if ($this->function === 'create' && !empty($this->areaId)) {
            $this->_editCreate();
        }

        // add cleared fields to the request (see _editNormalizeSubmitted())
        if (in_array($this->function, ['save', 'tab'], true)) {
            $this->_editNormalizeSubmitted($schemaVirtual);
        }

        if ($this->function === 'save') {
            // owner only with the additional right (lookup field of the mask, _editForm())
            $this->_editSave($this->service->saveFields($this->areaId, $this->data));
        }

        // tab switch (multilingual input with tabs): save in between
        if ($this->function === 'tab') {
            $this->_editTab($schemaVirtual);
        }

        // Delete confirm
        if ($this->function === 'delete_confirm') {
            $this->_editDeleteConfirm();
        }

        // Errors
        if (in_array($this->function, ['errors', 'skip'], true)) {
            $this->errors();
        }

        if ($this->function === 'create' && empty($this->areaId)) {
            // step1: choose area
            $this->_editCreateForm();
        }
        elseif (in_array($this->function, ['create', 'edit', 'copy', 'errors', 'tab'], true)) {
            if (in_array($this->function, ['errors', 'tab'], true)) {
                // feed files already secured by _secureUploads() back into the mask
                // that is shown again (file fields are not in the POST)
                $this->_editRestoreSecuredFiles($schemaVirtual);
            }

            // step2: edit record
            $this->_editForm($schemaVirtual);
        }

        // Delete form
        if ($this->function == 'delete') {
            $this->_editDeleteForm();
        }

        echo $this->backend->template('container_footer');
    }


    /**
     * The input form.
     *
     * Runs over the schema of the area and renders each field with
     * visibility_input = 1 (_editFormFields()). Which input field it becomes
     * is stated by the field definition - Backend::formField() takes care of that.
     *
     * Only the choices that cannot be part of the field definition are obtained by hand:
     *
     *     relation / pointer  records of the target areas (_relatedDatasetOptions())
     *     template_id         the templates permitted in the area
     *     status_id           the statuses
     *     parent_id           the other records of the area
     *     position            likewise, if applicable limited to the siblings
     *
     * Two kinds of field then still need rework on the template:
     *
     *     lookup fields  get the URLs for searching, creating and updating
     *                    as well as the finished preview block of the existing
     *                    links
     *     file fields    get the preview link. It does not point into the
     *                    media directory, but to backend/file.php - only that
     *                    knows the storage and checks the permission
     *                    (RecordBackendServerFile::filePreview()). The appended
     *                    random value prevents the browser from showing an old version
     *                    from the cache.
     *
     * FIRMLY LINKED CONTENTS (fields of type 'link', Camindo\Core\LinkTrait)
     * --------------------
     *     display_type 'lookup'  like a pointer lookup: search window, link
     *                            immediately on the working copy
     *     display_type 'embed'   the input masks of the contents stand in this mask
     *                            (_embedBlock()): field label as subheading,
     *                            below it per content a cell with its mask. The
     *                            contents were checked out together with the check-out
     *                            (LinkTrait::checkOutDependents()), their input fields
     *                            are called embed[{record_id}][field]; inserting, moving
     *                            and deleting run via Ajax (embedAction()), saving
     *                            happens with the source record (_editSave()).
     *
     * data-changed="true" is set as soon as the form is not the first call -
     * the JavaScript then asks on closing, instead of discarding inputs.
     *
     * @param array $schemaVirtual  schema with virtual fields
     */
    private function _editForm(array $schemaVirtual): void
    {
        $requestFunction = (string)($_REQUEST['function'] ?? '');
        if ($requestFunction != '') $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Record/record_edit.php" method="post"' . $changed . '>';

        // languages of the instance of the area (see class comment)
        // --------------------
        $ctx = $this->_editLanguageContext();
        $languages = $ctx['languages'];
        $useTabs = $ctx['useTabs'];
        $tab = $ctx['tab'];
        if ($useTabs) {
            $this->backend->tabBarReset();
            foreach ($languages as $languageId => $language) {
                // tab per language
                $label = $language['code'] ?? '**';
                $link = "cApp.ui.recordTab(this, '{$languageId}');";
                $this->backend->tabAdd($label, $link, (string)$tab === (string)$languageId);
            }
            // tab "All"
            $labelAll = '#record.tab_all_languages';
            $linkAll = "cApp.ui.recordTab(this, 'all');";
            $this->backend->tabAdd($labelAll, $linkAll, $tab === 'all');
            
            echo $this->backend->tabBar();

            // show the currently selected language
            if (isset($languages[$tab])) {
                echo $this->backend->template('edit_form_block', [
                    '{label}' => '',
                    '{field}' => $this->backend->template('language.icon_labeled', [
                        '{label}' => $this->cms->textOrSnippet($languages[$tab]['label'])
                    ])
                ]);
            }
        }

        $this->backend->formInit('record.record_edit_form');

        // the input fields (see _editFormFields())
        $shown = $this->_editFormFields($schemaVirtual, $ctx);

        // owner (additional right "adjust owner"): as the last field a lookup on the
        // admin list - the value travels as hidden data[owner_id] in the preview block
        // (developer docs, chapter 11 (Permissions)); it is only written with the right (_editSave()
        // takes it into $fields, Record::hookOnSave() checks again)
        if ($this->backend->access()->areaSetOwner($this->areaId)) {
            $ownerId = (int)($this->data['owner_id'] ?? 0);
            if ($ownerId < 1) $ownerId = (int)$this->session->get('admin_id');
            $this->backend->formField($this->record, [
                'name' => 'owner_id',
                'colDef' => $this->cms->schema['owner_id'],
                'value' => $ownerId,
                'id' => $this->id,
                'append' => true,
                'template' => 'edit_form_lookup_foreign_table',
                'contextMenu' => '',
            ]);
            $this->backend->formReplace('{src_id}', (string)$this->id);
            $this->backend->formReplace('{src_field}', 'owner_id');
            $this->backend->formReplace('{search_url}', 'Admin/admin_list.php?src_field=owner_id');
            $this->backend->formReplace('{update_url}', 'Admin/ajax_lookup_preview.php?src_field=owner_id');
            $this->backend->formReplace('{data_ids}', $ownerId > 0 ? (string)$ownerId : '');
            $this->backend->formReplace('{max}', '1');
            $this->backend->formReplace(
                '{preview}',
                $GLOBALS['app']->get(\Camindo\Core\Modules\Admin\AdminBackend::class)->lookupPreviewCreate('owner_id', [$ownerId], 'data', 1)
            );
        }

        // hidden fields
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('data[area_id]', $this->areaId);
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('shown', implode(',', $shown));
        // tabs: the displayed one (tab) and the target of a tab switch
        // (tab_target, set by cApp.ui.recordTab() before submitting)
        if ($useTabs) {
            $this->backend->formHidden('tab', (string)$tab);
            $this->backend->formHidden('tab_target', '');
        }
        // form called via lookup field?
        $this->backend->formHidden('src_field', $this->srcField);
        $this->backend->formHidden('src_id', $this->srcId);
        $this->backend->formHidden('straight', $this->straight);

        // Formular rendern
        echo $this->backend->form('record.form_save_cancel', [
            '{id}' => $this->id
        ]);
        
        // JavaScript instruction for growing textarea, after Ajax load
        $this->backend->script("cApp.ui._initTextareaGrow(\$('.c9-layer:last'));");
        echo '</form>';
    }

    /**
     * Language context of the mask: languages of the instance of the area, tab mode and
     * active tab, plus the fields with errors in other languages - common
     * basis of _editForm() and the embedded masks (embedAction()).
     *
     * Errors from other tabs: Dataset::save() registers field errors of
     * multilingual fields under "name.{language_id}" (embedded
     * "embed.{record_id}.name.{language_id}"). If the error lies in a language that
     * the active tab does not show, the editor would never see it - and
     * could not switch the tab either because of the error. Therefore such
     * fields are additionally shown in their error language (errorLanguages:
     * error key without language => [language_id, ...], see _editFormFields()).
     *
     * @return array  languages, isMultilingual, languageIds, primaryId, useTabs, tab,
     *                errorLanguages
     */
    private function _editLanguageContext(): array
    {
        $allLanguages = $this->_recordLanguages();
        // The instance is multilingual - regardless of how many languages the
        // user group sees: tabs and fields exist only for readable languages,
        // input fields only for writable ones (otherwise grayed-out text, see
        // _editFormFields()); languages without right remain untouched when saving
        $isMultilingual = count($allLanguages) > 1;
        $languages = $this->record->readableLanguages($allLanguages);
        $languageIds = array_map('intval', array_keys($languages));
        $primaryId = $languageIds[0] ?? 0;
        $access = $this->backend->access();
        $writableIds = array_values(array_filter($languageIds, fn($id) => $access->languageWrite($id)));
        $useTabs = $isMultilingual && $this->_languageTabs();
        // active tab: language_id or 'all'
        $tab = $useTabs ? $this->_editCurrentTab($languageIds) : 'all';
        $errorLanguages = [];
        if ($useTabs && $tab !== 'all') {
            foreach ($this->cms->getErrorFields() as $errorKey) {
                if (!preg_match('/^(.+)\.(\d+)$/', (string)$errorKey, $m)) continue;
                if (!in_array((int)$m[2], $languageIds, true)) continue;
                $errorLanguages[$m[1]][] = (int)$m[2];
            }
        }
        return [
            'languages' => $languages,
            'isMultilingual' => $isMultilingual,
            'languageIds' => $languageIds,
            'primaryId' => $primaryId,
            'writableIds' => $writableIds,
            'useTabs' => $useTabs,
            'tab' => $tab,
            'errorLanguages' => $errorLanguages,
        ];
    }

    /**
     * Set the input fields of ONE record in the form - for the record of the mask
     * ($varName 'data') and for each embedded content of a 'link' field
     * ($varName 'embed[{record_id}]', _embedCell()). Works on
     * $this->id / $this->data / $this->areaId / $this->areaData - the embedded
     * mask swaps this context beforehand.
     *
     * When embedded, the fields `parent_id` and `position` are left out (the order
     * of the contents is determined by the link) as well as 'link' fields of the contents themselves
     * (no nesting in the mask - their contents are still taken along at check-in,
     * LinkTrait).
     *
     * @param array  $schemaVirtual  schema of the area
     * @param array  $ctx            _editLanguageContext()
     * @param string $varName        name of the request array ('data' | 'embed[{id}]')
     * @param string $errorPrefix    prefix of the error keys ('' | 'embed.{id}.')
     * @return string[]  rendered input fields: "name" or "name.{language_id}"
     *                   (hidden field 'shown', see _editNormalizeSubmitted())
     */
    private function _editFormFields(array $schemaVirtual, array $ctx, string $varName = 'data', string $errorPrefix = ''): array
    {
        $languages = $ctx['languages'];
        $isMultilingual = $ctx['isMultilingual'];
        $languageIds = $ctx['languageIds'];
        $primaryId = $ctx['primaryId'];
        $tab = $ctx['tab'];
        $errorLanguages = $ctx['errorLanguages'];
        $embedded = ($varName !== 'data');

        if ($isMultilingual) {
            // assemble values per language (working state + request)
            $this->_editLanguageValues($schemaVirtual, $languageIds);
        }

        // passwords the working copy already holds as a hash (Record::
        // securePasswords(): secured before a failed check or a tab
        // switch) - the field may then stay empty and also says so
        $keptPasswords = [];
        if ($this->id > 0) {
            foreach ($this->record->loadTmpData($this->id) as $name => $entry) {
                if (($entry[0] ?? '') === 'password' && (string)($entry[1] ?? '') !== '') $keptPasswords[$name] = true;
            }
        }

        // reference values for the pair parent_id / position (see class comment):
        // without an input field for parent_id there is no hierarchy to choose from -
        // the predecessor list then runs over the root level of the area.
        $parentId = isset($schemaVirtual['parent_id'])
            ? (int)($this->data['parent_id'] ?? 0)
            : 0;
        // is there a predecessor list at all that would have to be reloaded?
        $hasPreviousField = isset($schemaVirtual['position'])
            && (int)($schemaVirtual['position']['visibility_input'] ?? 0) === 1
            && ($schemaVirtual['position']['display_type'] ?? '') !== 'lookup';

        // which input fields the mask contains - "name" or "name.{language_id}".
        // Travels along as hidden field 'shown', so that the server recognizes a missing
        // field as CLEARED (see _editNormalizeSubmitted()).
        $shown = [];

        foreach ($schemaVirtual as $name => $colDef) {
            // only visible fields
            $visibility = (int)($colDef['visibility_input'] ?? 0);
            if ($visibility !== 1) continue;

            $type = $colDef['type'] ?? 'text';
            $displayType = $colDef['display_type'] ?? 'text';
            $fieldId = (int) $colDef['field_id'];
            $isLink = in_array($type, $this->cms->dependentTypes, true);

            if ($embedded && ($isLink || in_array($name, ['parent_id', 'position'], true))) continue;

            // link field without a readable target area: not in the mask, not
            // in 'shown' - the links stay as they are when saving
            if (!$this->record->relationTargetReadable($colDef)) continue;

            // firmly linked contents, embedded: own block instead of an input field
            if ($isLink && $displayType === 'embed') {
                $this->backend->formAppend(
                    '{' . $varName . '_' . $name . '}',
                    $this->_embedBlock($name, $colDef, $ctx)
                );
                continue;
            }

            $isSelectType = (
                in_array($displayType, $this->backend->optionDisplayTypes)
                && !in_array($type, $this->cms->relationTypes)
            );
            // relation / pointer
            // display_type != lookup -> build options from records in the target areas
            $options = null;
            if (in_array($type, $this->cms->relationTypes, true) && $displayType != 'lookup') {                
                if (str_starts_with($type, 'relation')) {
                    $relAreaIds = [(int)$colDef['rel_area_id']];
                }
                else {
                    // pointer, pointer1
                    $relAreaIds = $this->record->getRelAreaIds($fieldId);
                }
                if ($displayType === 'select') {
                    $options = $this->_relatedDatasetOptions($relAreaIds, addEmptyOption:true);
                }
                else {
                    // multiselect
                    $options = $this->_relatedDatasetOptions($relAreaIds);
                }
            }
            elseif ($isSelectType) {
                $values = preg_split('/(\r\n|\r|\n)/', (string)($colDef['default'] ?? ''));
                $options = array_combine($values, $values);
            }

            // template_id
            if ($name === 'template_id' && $displayType != 'lookup') {
                $options = $this->_templateOptions();
            }

            // status_id
            elseif ($name === 'status_id' && $displayType != 'lookup') {
                $options = $this->_statusOptions(settableOnly: true);
            }

            // parent_id
            elseif ($name === 'parent_id' && $displayType != 'lookup') {
                $options = $this->_parentOptions();
            }

            // position
            // the input is not the number but the PREDECESSOR record
            // (see _editPreviousParams()) - the options are therefore record_ids.
            elseif ($name == 'position' && $displayType != 'lookup') {
                $options = $this->_previousOptions($parentId);
            }

            if (isset($keptPasswords[$name])) {
                // secured password: no longer a required field, note below it
                $colDef['flags'] = array_values(array_diff((array)($colDef['flags'] ?? []), ['not_empty']));
                $colDef['note'] = '#record.note_password_kept';
            }
            $fieldParams = [
                'name' => $name,
                'colDef' => $colDef,
                'value' => $this->data[$name] ?? '',
                'id' => $this->id,
                'append' => true,
                'options' => $options,
                'varName' => $varName,
                'errorPrefix' => $errorPrefix,
                'placeholder' => '{' . $varName . '_' . $name . '}',
                'primary' => $fieldId > 0 && $fieldId === (int)($this->areaData['default_field_id'] ?? 0)
            ];

            // parent_id and position are linked: a change of the parent record
            // makes the predecessor list obsolete. Both branches below adjust the
            // form parameters accordingly.
            if ($name === 'parent_id') {
                $fieldParams = $this->_editParentParams($fieldParams, $displayType, $hasPreviousField);
            }
            elseif ($name === 'position' && $displayType != 'lookup') {
                $fieldParams = $this->_editPreviousParams($fieldParams, $parentId, $options);
            }

            // multilingual field: one input field per language - on a language tab
            // only that of the tab language. Each gets its own placeholder, so that
            // the fields stand directly below each other in language order
            // (append adds a missing placeholder at the end).
            if ($isMultilingual && $this->cms->isMultilingualField($colDef)) {
                $values = (array)($this->data[$name] ?? []);
                $formLanguages = ($tab === 'all') ? $languageIds : [(int)$tab];
                if (!empty($errorLanguages[$errorPrefix . $name])) {
                    // add languages with errors, in the order of the instance
                    $formLanguages = array_values(array_intersect(
                        $languageIds, array_unique(array_merge($formLanguages, $errorLanguages[$errorPrefix . $name]))
                    ));
                }
                foreach ($formLanguages as $languageId) {
                    if (!in_array($languageId, $ctx['writableIds'], true)) {
                        // read-only: saved value as grayed-out text, no input field
                        // (not in 'shown' - the value stays untouched when saving)
                        $this->backend->formAppend(
                            '{' . $varName . '_' . $name . '__' . $languageId . '}',
                            $this->_editReadOnlyField($colDef, $values[$languageId] ?? '', (string)($languages[$languageId]['code'] ?? $languageId))
                        );
                        continue;
                    }
                    $shown[] = $name . '.' . $languageId;
                    $langParams = $fieldParams;
                    $langParams['value'] = $values[$languageId] ?? '';
                    $langParams['langId'] = $languageId;
                    $langParams['langSuffix'] = (string)($languages[$languageId]['code'] ?? $languageId);
                    $langParams['placeholder'] = '{' . $varName . '_' . $name . '__' . $languageId . '}';
                    if ($languageId !== $primaryId) {
                        // the requirement applies only to the primary language: a further language
                        // may stay empty (fallback) - no asterisk, no required
                        $langParams['colDef']['flags'] = array_values(array_diff(
                            (array)($colDef['flags'] ?? []), ['not_empty']
                        ));
                    }
                    $this->backend->formField($this->record, $langParams);
                    if ($type === 'file') {
                        // upload field: preview of the file of THIS language
                        $this->_editFileReplacements($name, $fieldId, (array)($values[$languageId] ?? []), $languageId);
                    }
                }
                continue;
            }

            $shown[] = $name;
            $this->backend->formField($this->record, $fieldParams);

            if ($name === 'parent_id' && $displayType === 'lookup') {
                // lookup field for the parent record - table record -
                // subsequent replacements. Unlike a link field, NOTHING is written
                // immediately here: the preview contains a hidden field data[parent_id]
                // that is sent along with the form.
                $urlSuffix = "&src_id={$this->id}&src_field={$fieldId}";
                $this->backend->formReplace('{src_id}', $this->id);
                $this->backend->formReplace('{src_field}', $fieldId);
                $this->backend->formReplace('{search_url}', "Record/record_list.php?" . $urlSuffix);
                $this->backend->formReplace('{update_url}', "Record/ajax_parent_lookup.php?" . $urlSuffix);
                $this->backend->formReplace('{preview}', $this->_parentLookupPreviewCreate($parentId));
            }

            if (in_array($type, $this->cms->relationTypes, true)
                && $displayType === 'lookup'
            ) {
                // lookup field - table record - subsequent replacements
                $this->_editLookupReplacements($fieldId, (int)($colDef['max'] ?? 0));
            }

            if ($type === 'file') {
                // upload field - table record - subsequent replacements:
                $this->_editFileReplacements($name, $fieldId, (array)($this->data[$name] ?? []), null);
            }
        }

        return $shown;
    }

    /**
     * A field of a read-only language: label with language code and the
     * saved value as grayed-out text (class c9-grey-30) instead of an
     * input field (developer docs, chapter 11 (Permissions)).
     *
     * @param array  $colDef  Felddefinition
     * @param mixed  $value   value in display format (_editLanguageValues())
     * @param string $code    language code
     */
    private function _editReadOnlyField(array $colDef, mixed $value, string $code): string
    {
        $label = $this->cms->textOrSnippet((string)($colDef['label'] ?? ''))
            . $this->backend->template('label_lang_suffix', ['{lang}' => htmlspecialchars($code)]);
        if (is_array($value)) {
            // file / link: display form from backendValue()
            $html = $this->backend->backendValue($value, $colDef, 'short', $this->id);
        }
        else {
            $html = nl2br(htmlspecialchars((string)$value));
            // in the writing direction of its language (Backend::fieldDirection())
            $dir = $this->backend->fieldDirection($colDef, $code);
            if ($dir !== '' && trim($html) !== '') $html = '<span dir="' . $dir . '">' . $html . '</span>';
        }
        if (trim($html) === '') $html = $this->backend->emptyPlaceholder;
        return $this->backend->template('edit_form_static', [
            '{label}' => $label,
            '{html}' => '<span class="c9-grey-30">' . $html . '</span>',
        ]);
    }

    // ====================
    // Firmly linked contents, embedded (field type 'link', display_type 'embed')

    /**
     * Render the block of an embedded 'link' field: field label as a
     * subheading, below it at full width one cell per content with its
     * input mask (_embedCell()), plus the buttons for appending (one per
     * target area). The block carries the data that the JavaScript needs for inserting,
     * moving and deleting (backend-embedded.js -> embedAction()).
     *
     * @param string $name    field name
     * @param array  $colDef  field definition
     * @param array  $ctx     _editLanguageContext()
     */
    private function _embedBlock(string $name, array $colDef, array $ctx): string
    {
        $fieldId = (int)$colDef['field_id'];
        $areas = $this->_embedAreas($fieldId);

        $cells = [];
        foreach ($this->record->linkDestIds($this->id, $fieldId) as $destId) {
            $cells[] = $this->_embedCell($destId, $colDef, $ctx, $areas);
        }

        $label = $this->cms->textOrSnippet($colDef['label'] ?? $name);
        if (in_array('not_empty', (array)($colDef['flags'] ?? []), true)) {
            $label .= $this->backend->template('mandatory_field_indicator');
        }
        $tooltip = (string)($colDef['tooltip'] ?? '');
        $tooltipHtml = $tooltip !== ''
            ? $this->backend->template('record.embed_tooltip', [
                '{tooltip}' => $this->backend->_escapeParam($this->cms->textOrSnippet($tooltip))
            ])
            : '';

        // error on the field itself (e.g. not_empty: no content)
        $errorMsg = '';
        $blockClass = '';
        if (in_array($name, $this->cms->getErrorFields(), true)) {
            $blockClass = ' c9-invalid';
            $errorMsg = $this->backend->template('form_error', [
                '{text}' => $this->cms->errors[$name]['text'] ?? ''
            ]);
        }

        return $this->backend->template('record.embed_block', [
            '{label}' => $label,
            '{tooltip}' => $tooltipHtml,
            '{src_id}' => $this->id,
            '{src_field}' => $fieldId,
            '{tab}' => (string)$ctx['tab'],
            '{update_url}' => "Record/ajax_embed.php?src_id={$this->id}&src_field={$fieldId}",
            '{cells}' => implode("\n", $cells),
            '{add_buttons}' => $this->_embedAddButtons($areas),
            '{class}' => $blockClass,
            '{error}' => $errorMsg,
        ]);
    }

    /**
     * Target areas of a 'link' field: area_id => label (for the
     * insert entries of the cell menu and the append buttons).
     */
    private function _embedAreas(int $fieldId): array
    {
        $areas = [];
        foreach ($this->record->getRelAreaIds($fieldId) as $areaId) {
            if (!$areaData = $this->backend->areaData((int)$areaId)) continue;
            $areas[(int)$areaId] = $this->cms->textOrSnippet((string)$areaData['label']);
        }
        return $areas;
    }

    /**
     * Append buttons below the cells: with one target area one button, otherwise one
     * per target area with its label.
     */
    private function _embedAddButtons(array $areas): string
    {
        $buttons = [];
        foreach ($areas as $areaId => $label) {
            $buttons[] = $this->backend->template('record.embed_add_button', [
                '{area_id}' => $areaId,
                '{label}' => count($areas) > 1
                    ? strtr($this->cms->snippet('record.embed_add_area'), ['{area}' => $label])
                    : $this->cms->snippet('record.embed_add'),
            ]);
        }
        return implode("\n", $buttons);
    }

    /**
     * The cell of an embedded content: its input mask (field label + field
     * side by side, as in the main mask) with the cell menu (more_vert:
     * insert before / after, up / down, delete).
     *
     * The content is a working copy of the session admin (LinkTrait). Its values:
     *
     *   1. from the request (function save / tab / errors, embedAction()):
     *      embed[{record_id}][field] - raw input in display format
     *   2. otherwise from the working copy (Record::load(): working state via
     *      Cms::fetch()) in database format -> _editFormatLoadedValues(); a newly
     *      inserted content without working state gets the defaults of its area
     *      (Record::init())
     *
     * The field loop (_editFormFields()) works on $this->id / $this->data /
     * $this->areaId / $this->areaData / $this->function - the context is swapped out
     * for the duration of the cell. Field errors are stored under
     * "embed.{record_id}.field" (_embedSave()).
     *
     * @param int   $destId  working copy of the content
     * @param array $colDef  the 'link' field
     * @param array $ctx     _editLanguageContext()
     * @param array $areas   _embedAreas()
     */
    private function _embedCell(int $destId, array $colDef, array $ctx, array $areas): string
    {
        $keep = [$this->id, $this->data, $this->areaId, $this->areaData, $this->function];
        $this->backend->formPush();
        try {
            if (!$prodData = $this->record->getProdData($destId)) {
                return $this->backend->template('alert_danger', [
                    '{text}' => $this->cms->snippet('error_tmp_record_access_denied')
                ]);
            }
            $areaId = (int)$prodData[1];
            if (!$areaData = $this->backend->areaData($areaId)) {
                return $this->backend->template('alert_danger', [
                    '{text}' => $this->cms->snippet('area.error_area_not_found')
                ]);
            }
            $schemaVirtual = $this->record->virtualSchema($areaId, addMetaFields:false);
            $this->id = $destId;
            $this->areaId = $areaId;
            $this->areaData = $areaData;

            $fromRequest = isset($this->embedData[$destId]) && is_array($this->embedData[$destId]);
            if ($fromRequest) {
                $this->function = 'errors';
                $this->data = $this->embedData[$destId];
                $this->data['record_id'] = $destId;
                $this->data['area_id'] = $areaId;
                $this->_editRestoreSecuredFiles($schemaVirtual);
            }
            elseif (empty($this->record->loadTmpData($destId))) {
                // newly inserted, still without working state: defaults of the area
                $this->function = 'create';
                $this->data = (array)$this->record->init(['area_id' => $areaId]);
                $this->data['record_id'] = $destId;
            }
            else {
                $this->function = 'edit';
                $this->data = (array)$this->record->load($destId);
                $this->data['record_id'] = $destId;
                $this->_editFormatLoadedValues($schemaVirtual);
            }

            $varName = 'embed[' . $destId . ']';
            $shown = $this->_editFormFields($schemaVirtual, $ctx, $varName, 'embed.' . $destId . '.');
            $this->backend->formHidden('embed_shown[' . $destId . ']', implode(',', $shown));
            $fields = $this->backend->formPop();
            $this->backend->formPush();

            // cell menu
            $insertItems = '';
            foreach ($areas as $insertAreaId => $label) {
                $insertItems .= $this->backend->template('record.embed_menu_insert', [
                    '{area_id}' => $insertAreaId,
                    '{suffix}' => count($areas) > 1 ? ': ' . $label : '',
                ]);
            }
            $areaLabel = $this->cms->textOrSnippet((string)$areaData['label']);
            return $this->backend->template('record.embed_cell', [
                '{dest_id}' => $destId,
                '{area_id}' => $areaId,
                '{area_label}' => count($areas) > 1 ? $areaLabel : '',
                '{fields}' => $fields,
                '{menu}' => $this->backend->template('record.embed_menu', [
                    '{insert_items}' => $insertItems,
                ]),
            ]);
        } finally {
            $this->backend->formPop();
            [$this->id, $this->data, $this->areaId, $this->areaData, $this->function] = $keep;
        }
    }

    /**
     * Save the embedded contents of all 'link' fields of the mask - before the
     * source record (_editSave(), _editTab()).
     *
     * Record::save() per content with the inputs from embed[{record_id}] - the
     * field list is its visible input fields (as in _editTab()), cleared
     * fields added from embed_shown (_editNormalizeSubmitted()). Uploads
     * lie under $_FILES['embed'][...][{record_id}] and are placed into $_FILES['data']
     * for the duration of the call, where Dataset::save() expects them.
     *
     * Always with stayTemporary: the checked values end up in the working state of the
     * working copy of the content, from where the check-in takes them
     * (LinkTrait::checkInDependents() -> Record::save() with staged fields).
     * This way the check is complete before a file changes location.
     *
     * Dataset::save() registers field errors under the field name; they are re-keyed to
     * "embed.{record_id}.field" so that the mask shows them at the correct
     * cell (Backend::formField() with errorPrefix). Without inputs for a
     * content (cell not in the request) its working state remains as it is.
     *
     * @param array $schemaVirtual  schema of the area of the mask
     * @return bool  false if a content could not be saved
     */
    private function _embedSave(array $schemaVirtual): bool
    {
        $ok = true;
        foreach ($this->record->linkFields($schemaVirtual) as $colDef) {
            if ((string)($colDef['display_type'] ?? '') !== 'embed') continue;
            foreach ($this->record->linkDestIds($this->id, (int)$colDef['field_id']) as $destId) {
                if (!isset($this->embedData[$destId]) || !is_array($this->embedData[$destId])) continue;
                if (!$prodData = $this->record->getProdData($destId)) {
                    $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
                    $ok = false;
                    continue;
                }
                $areaId = (int)$prodData[1];
                $destSchema = $this->record->virtualSchema($areaId, addMetaFields:false);

                $data = $this->embedData[$destId];
                $data['record_id'] = $destId;
                $data['area_id'] = $areaId;
                $this->_editNormalizeSubmitted($destSchema, $data, (string)($_REQUEST['embed_shown'][$destId] ?? ''));

                $fields = [];
                foreach ($destSchema as $name => $destDef) {
                    if ((int)($destDef['visibility_input'] ?? 0) !== 1) continue;
                    $type = (string)($destDef['type'] ?? '');
                    if ($type === 'fulltext' || in_array($type, $this->cms->dependentTypes, true)) continue;
                    if (in_array($name, ['parent_id', 'position'], true)) continue;
                    $fields[] = $name;
                }
                $fields[] = 'area_id';

                // place the uploads of this content where save() expects them
                $filesKeep = $_FILES['data'] ?? null;
                $_FILES['data'] = [];
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                    if (isset($_FILES['embed'][$key][$destId])) {
                        $_FILES['data'][$key] = $_FILES['embed'][$key][$destId];
                    }
                }
                $errorsBefore = array_keys($this->cms->errors);
                try {
                    $saved = $this->record->save($fields, $data, stayTemporary: true);
                } finally {
                    if ($filesKeep === null) unset($_FILES['data']);
                    else $_FILES['data'] = $filesKeep;
                }
                if (!$saved) {
                    $ok = false;
                    // re-key field errors to the cell
                    foreach (array_keys($this->cms->errors) as $key) {
                        if (is_int($key) || in_array($key, $errorsBefore, true)) continue;
                        $this->cms->errors['embed.' . $destId . '.' . $key] = $this->cms->errors[$key];
                        unset($this->cms->errors[$key]);
                    }
                }
            }
        }
        return $ok;
    }

    /**
     * Ajax: edit embedded contents of a 'link' field
     * (backend/ajax_embed.php, called by backend-embedded.js).
     *
     *     insert   insert new content before/after dest_id (dest_id 0: at the end) -
     *              the response is the finished cell (_embedCell()), the JavaScript places
     *              it at its position
     *     up/down  move the content one position - the JavaScript swaps the cells
     *     delete   remove the content (LinkTrait::embedRemove(): detach the link,
     *              discard the working copy; a productive original is deleted as
     *              orphaned at check-in)
     *
     * For up/down/delete the response stays empty; an error comes as a message. The
     * working copy of the source record (src_id) must belong to the session admin -
     * the LinkTrait methods check that.
     *
     * Request: src_id, src_field, function, dest_id, area_id (insert), before (insert),
     *          tab (active language tab of the mask)
     */
    public function embedAction(): void
    {
        // creates, reorders, detaches - POST only (developer docs, chapter 14 (Security: web))
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }
        $srcId    = (int)($_REQUEST['src_id'] ?? 0);
        $srcField = (int)($_REQUEST['src_field'] ?? 0);
        $destId   = (int)($_REQUEST['dest_id'] ?? 0);
        $areaId   = (int)($_REQUEST['area_id'] ?? 0);
        $before   = (int)($_REQUEST['before'] ?? 0) === 1;
        $function = (string)($_REQUEST['function'] ?? '');

        if (!$colDef = $this->record->fieldData($srcField)) {
            $this->cms->error('embedAction() Exception: field not found.');
            $this->errors();
            return;
        }
        if (!in_array((string)($colDef['type'] ?? ''), $this->cms->dependentTypes, true)) {
            $this->cms->error('embedAction() Exception: not a link field.');
            $this->errors();
            return;
        }
        if (!$prodData = $this->record->getProdData($srcId)) {
            $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
            $this->errors();
            return;
        }
        // context of the mask (languages, tabs)
        $this->id = $srcId;
        $this->areaId = (int)$prodData[1];
        $this->areaData = $this->backend->areaData($this->areaId) ?? [];
        $this->embedData = [];

        try {
            switch ($function) {
                case 'insert':
                    $newId = $this->record->embedInsert($colDef, $srcId, $areaId, $destId, $before);
                    if (!$newId) {
                        $this->errors();
                        return;
                    }
                    $ctx = $this->_editLanguageContext();
                    echo $this->_embedCell($newId, $colDef, $ctx, $this->_embedAreas($srcField));
                    return;
                case 'up':
                case 'down':
                    $this->record->embedMove($colDef, $srcId, $destId, $function);
                    break;
                case 'delete':
                    $this->record->embedRemove($colDef, $srcId, $destId);
                    break;
                default:
                    $this->cms->error('embedAction() Exception: unknown function.');
            }
        } catch (\Exception $e) {
            $this->cms->error($e->getMessage());
        }
        if ($this->cms->hasErrors) $this->errors();
    }

    /**
     * Lookup field (link with search window): replace the placeholders of the most recently appended
     * input field - the URLs for searching, creating and updating
     * (src_id is the working copy $this->id to which the links are attached) and
     * the preview block of the existing links (_lookupPreviewCreate()).
     *
     * Must run directly after the formField() call of the field (see
     * _editFileReplacements()). Also for the template of batch editing
     * (RecordBackendBatch).
     *
     * @param int $fieldId  field_id of the link field
     * @param int $max      at most this many links (0 = any)
     */
    protected function _editLookupReplacements(int $fieldId, int $max): void
    {
        $urlSuffix = "&src_id={$this->id}&src_field={$fieldId}";
        $this->backend->formReplace('{src_id}', $this->id);
        $this->backend->formReplace('{src_field}', $fieldId);
        $this->backend->formReplace('{search_url}', "Record/record_list.php?" . $urlSuffix);
        $this->backend->formReplace('{create_url}', "Record/record_edit.php?function=create&straight=1" . $urlSuffix);
        $this->backend->formReplace('{update_url}', "Record/ajax_lookup_preview.php?" . $urlSuffix);
        $this->backend->formReplace('{max}', $max);
        $this->backend->formReplace('{preview}', $this->_lookupPreviewCreate($fieldId, $this->id) ?? '');
    }

    /**
     * Upload field: replace the preview block and placeholders of the most recently appended
     * input field ({src_id}, {src_field}, {lang}, {preview}).
     *
     * The preview link does not point into the media directory, but to
     * backend/file.php - only that knows the storage and checks the permission
     * (RecordBackendServerFile::filePreview()); for multilingual fields with the
     * language as third path part. The appended random value prevents the
     * browser from showing an old version from the cache.
     *
     * Must run directly after the formField() call of the field: formReplace()
     * replaces all still open placeholders in the form, the next upload field
     * brings its own.
     *
     * @param string   $name        field name
     * @param int      $fieldId     field_id (media directory)
     * @param array    $fileData    metadata of the file (empty = no file)
     * @param int|null $languageId  language for multilingual fields
     */
    protected function _editFileReplacements(string $name, int $fieldId, array $fileData, ?int $languageId): void
    {
        $previewContainer = '';
        if (!empty($fileData['size']) && empty($fileData['deleted'])) {
            $unlinkUrl = "cApp.ui.fileUnlink();";
            $fileAttribs = $this->system->convertSize($fileData['size']);
            $linkText = $fileData['filename'] . ' ' . str_replace('{text}', $fileAttribs, $this->cms->snippet('parenthetical_note'));
            $previewUrl = $this->config->get('paths.backend') . "/file.php?file=$fieldId/{$this->id}"
                . ($languageId === null ? '' : '/' . $languageId)
                . "&v=" . $this->crypt->randomKey(16);
            $previewContainer = $this->backend->template('record.file_preview_container', [
                '{preview_url}' => $previewUrl,
                '{link_text}' => $linkText,
                '{unlink_url}' => $unlinkUrl
            ]);
        }
        $this->backend->formReplace('{src_id}', $this->id);
        $this->backend->formReplace('{src_field}', $fieldId);
        $this->backend->formReplace('{lang}', $languageId === null ? '' : (string)$languageId);
        $this->backend->formReplace('{preview}', $previewContainer);
    }

    /**
     * Active tab of the input mask: language_id or 'all'.
     *
     * On a tab switch (function=tab) the target from tab_target - unless the
     * intermediate save failed, in which case the previous tab stays so that the
     * error can be corrected there. Otherwise the submitted tab
     * (tab); on the first call the admin's preferred language version
     * (personal setting settings.language_id), provided the instance maintains it,
     * otherwise the primary language. When CREATING (function=create) always the
     * primary language: a new record first needs its mandatory values there, the
     * other languages are only translations of them.
     *
     * @param int[] $languageIds  languages of the instance
     * @return int|string
     */
    private function _editCurrentTab(array $languageIds): int|string
    {
        $requested = (string)($_REQUEST['tab'] ?? '');
        $target    = (string)($_REQUEST['tab_target'] ?? '');
        $useTarget = ((string)($_REQUEST['function'] ?? '') === 'tab' && $this->function === 'tab');
        $tab = $useTarget && $target !== '' ? $target : $requested;
        if ($tab === 'all') return 'all';
        if ($tab !== '' && in_array((int)$tab, $languageIds, true)) return (int)$tab;
        // create: primary language
        if ((string)($_REQUEST['function'] ?? '') === 'create') return $languageIds[0] ?? 'all';
        // first call: the admin's preferred language version
        $preferred = (string)($this->backend->adminSettings()['language_id'] ?? '');
        if ($preferred !== '' && in_array((int)$preferred, $languageIds, true)) return (int)$preferred;
        return $languageIds[0] ?? 'all';
    }

    /**
     * Assemble the values of the multilingual fields for the mask:
     * $this->data[field] = [language_id => value].
     *
     * Sources, per language, in this order:
     *   1. the request (function=errors / tab): raw input in display format
     *   2. the working state of the working copy: database format -> display format
     *      (_editFormatValue()); empty = not translated
     *   3. a single value from load()/init() (cache of the primary language resp.
     *      default) for the primary language
     *
     * @param array $schemaVirtual  schema of the area
     * @param int[] $languageIds    languages of the instance, primary language first
     */
    private function _editLanguageValues(array $schemaVirtual, array $languageIds): void
    {
        $primaryId = $languageIds[0] ?? 0;
        $tmpData = ($this->id > 0) ? $this->record->loadTmpData($this->id) : [];
        $fromRequest = in_array((string)($_REQUEST['function'] ?? ''), ['save', 'tab'], true);

        foreach ($schemaVirtual as $name => $colDef) {
            if (!$this->cms->isMultilingualField($colDef)) continue;
            $type = (string)($colDef['type'] ?? 'text');
            if ((int)($colDef['visibility_input'] ?? 0) !== 1) continue;
            $displayType = (string)($colDef['display_type'] ?? 'text');

            if ($type === 'file') {
                // upload field: per language the own file from the working state of the
                // working copy (no input in the request - file fields are not
                // in the POST, see _editRestoreSecuredFiles())
                $files = [];
                $entry = (array)($tmpData[$name] ?? []);
                $entries = !empty($entry) ? $this->record->fileEntries($entry) : [];
                foreach ($languageIds as $languageId) {
                    $files[$languageId] = (array)($entries[$languageId] ?? []);
                }
                $this->data[$name] = $files;
                continue;
            }

            $current = $this->data[$name] ?? null;
            $tmpEntry = $tmpData[$name][1] ?? null;
            $values = [];
            foreach ($languageIds as $languageId) {
                if ($fromRequest && is_array($current) && array_key_exists($languageId, $current)) {
                    // 1. from the request (already display format)
                    $values[$languageId] = $current[$languageId];
                }
                elseif (is_array($tmpEntry) && array_key_exists($languageId, $tmpEntry)) {
                    // 2. from the intermediate state of the working copy
                    $value = $tmpEntry[$languageId];
                    $isEmpty = is_array($value) ? empty($value) : ((string)$value === '');
                    $values[$languageId] = $isEmpty ? $value : $this->_editFormatValue($type, $displayType, $value);
                }
                elseif (!is_array($current) && $languageId === $primaryId) {
                    // 3. single value (cache of the primary language / default)
                    $values[$languageId] = ($this->function === 'create')
                        ? $current
                        : $this->_editFormatValue($type, $displayType, $current);
                }
                else {
                    $values[$languageId] = ($type === 'json') ? [] : '';
                }
            }
            $this->data[$name] = $values;
        }
    }

    /**
     * Add cleared input fields to the request (function=save / tab).
     *
     * A browser does not send a switched-off switch at all, and
     * serializeFiles() leaves out empty text fields (short URLs). For the server a
     * cleared field would then look exactly like a field that was not in the mask at all -
     * and Dataset::save() would then take the intermediate state from the working state resp. the
     * old value. With multilingual fields with tabs exactly that happened: a
     * deleted text was back after the tab switch.
     *
     * Therefore the mask carries the hidden field 'shown': the list of all
     * rendered input fields, "name" or "name.{language_id}" (_editForm()).
     * Everything that is listed there but missing in data[] is added here as empty
     * ('' or [] for array types). Excluded:
     *
     *     file                    no upload means "keep the file"
     *     relation/pointer lookup write immediately via Ajax, never in the POST
     *
     * `parent_id` and `position` are added not as EMPTY but as 0:
     * their first selection entry carries the empty value ("no parent record" resp.
     * "to the beginning", see RecordBackend::_parentOptions()/_previousOptions()) -
     * exactly that is discarded by serializeFiles(). Without the addition
     * Dataset::save() would see them as NOT SUBMITTED ($parentIdSubmitted / $previousIdSubmitted) and
     * would not move the record at all: "to the beginning" and "no parent record"
     * would remain without effect. What is entered here is the predecessor, not the number -
     * therefore `position` in 'shown' becomes data[previous_id]
     * (_editPreviousParams()).
     *
     * No 'shown' in the request (e.g. call from outside): nothing is changed - a
     * mask that does not show the fields at all leaves the record where it is.
     *
     * @param array $schemaVirtual  schema of the area
     */
    private function _editNormalizeSubmitted(array $schemaVirtual, ?array &$data = null, ?string $shownRaw = null): void
    {
        // without input: the mask itself (data[] / shown); embedded contents
        // pass their inputs and their embed_shown[{record_id}] (_embedSave())
        if ($data === null) $data = &$this->data;
        $shownRaw = trim($shownRaw ?? (string)($_REQUEST['shown'] ?? ''));
        if ($shownRaw === '') return;

        foreach (explode(',', $shownRaw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            [$name, $languageId] = array_pad(explode('.', $entry, 2), 2, null);
            if (!isset($schemaVirtual[$name])) continue;
            $colDef = $schemaVirtual[$name];
            $type = (string)($colDef['type'] ?? 'text');
            $displayType = (string)($colDef['display_type'] ?? 'text');

            if ($type === 'file' || $type === 'fulltext') continue;
            if (in_array($type, $this->cms->relationTypes, true) && $displayType === 'lookup') continue;
            // 'link': links are attached to the working copy, never in the POST
            if (in_array($type, $this->cms->dependentTypes, true)) continue;
            // tree fields: 0 instead of empty (see method comment)
            if ($name === 'parent_id') {
                if (!array_key_exists('parent_id', $data)) $data['parent_id'] = 0;
                continue;
            }
            if ($name === 'position') {
                if ($displayType !== 'lookup' && !array_key_exists('previous_id', $data)) {
                    $data['previous_id'] = 0;
                }
                continue;
            }
            if ($name === 'previous_id') continue;

            $empty = in_array($type, $this->cms->arrayTypes, true) ? [] : '';

            if ($languageId !== null) {
                // multilingual: data[name][language_id]
                if (!ctype_digit($languageId)) continue;
                $languageId = (int)$languageId;
                if (!is_array($data[$name] ?? null)) $data[$name] = [];
                if (!array_key_exists($languageId, $data[$name])) {
                    $data[$name][$languageId] = $empty;
                }
            }
            elseif (!array_key_exists($name, $data)) {
                $data[$name] = $empty;
            }
        }
    }

    /**
     * function=tab: tab switch - save the mask in between.
     *
     * Record::save() with stayTemporary writes the checked inputs into the
     * working state of the working copy; the languages that were not on the tab
     * remain there (Dataset::save()). Saved are the fields that the mask
     * shows - all visible input fields; of those multilingual ones only with
     * the tab language. If the check fails, the previous tab remains
     * (function 'errors'), otherwise edit() shows the mask with the target tab.
     *
     * @param array $schemaVirtual  schema of the area
     */
    private function _editTab(array $schemaVirtual): void
    {
        $fields = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if ((int)($colDef['visibility_input'] ?? 0) !== 1) continue;
            if (($colDef['type'] ?? '') === 'fulltext') continue;
            $fields[] = $name;
        }
        $fields[] = 'area_id';

        // save embedded contents (link/embed) in between as well
        $embedOk = $this->_embedSave($schemaVirtual);

        // pass a copy: save() converts $data by reference into the storage format
        // (currency in cents, date ISO ...) - the mask afterwards still shows the input
        $data = $this->data;
        if ($this->record->save($fields, $data, stayTemporary: true) && $embedOk) {
            // show the mask with the target tab (see _editCurrentTab())
            return;
        }
        $this->function = 'errors';
    }

    /**
     * Prepare a database value for the input mask (see
     * _editFormatLoadedValues()).
     */
    private function _editFormatValue(string $type, string $displayType, mixed $value): mixed
    {
        if ($type === 'currency') {
            return $this->cms->_formatFloat((float)($value ?? 0) / 100, 2);
        }
        if (in_array($type, ['float', 'decimal'], true)) {
            return $this->cms->_formatFloat((float)($value ?? 0));
        }
        if (in_array($type, $this->cms->dateTypes, true)) {
            return $this->cms->_formatDate((string)($value ?? ''));
        }
        if ($type === 'time') {
            return $this->cms->_formatTime((string)($value ?? ''));
        }
        return $value;
    }

    /**
     * Adjust the form parameters of the field `parent_id`.
     *
     * Both presentations need their own template, because the change of the parent
     * record makes the predecessor list obsolete:
     *
     *     select   template with onchange -> updatePreviousOptions() via Ajax
     *     lookup   own lookup field. Unlike a link field, it writes
     *              nothing immediately into the database, but places the
     *              selection as hidden data[parent_id] into the preview.
     *              The context menu of the link fields does not fit with it.
     *
     * @param array  $fieldParams       parameters for Backend::formField()
     * @param string $displayType       display_type of the field
     * @param bool   $hasPreviousField  is there a predecessor list to reload?
     */
    private function _editParentParams(array $fieldParams, string $displayType, bool $hasPreviousField): array
    {
        if ($displayType === 'lookup') {
            $fieldParams['template'] = 'record.parent_lookup_field';
            $fieldParams['contextMenu'] = '';
            return $fieldParams;
        }
        if ($displayType === 'select' && $hasPreviousField) {
            $fieldParams['template'] = 'record.record_form_parent_select';
        }
        return $fieldParams;
    }

    /**
     * Adjust the form parameters of the field `position` - previous_id is entered.
     *
     * `position` is indeed the field name that the editor assigns in field_create.php
     * (there it is logical: without a parent record the position is simply the
     * sorting), but the form contains the PRECEDING record - just as in the
     * module Area. The number itself is calculated by TreeManagerTrait when saving:
     * Dataset::save() -> createInTree() / updateInTree() -> calculatePosition().
     * That is why the input field is called previous_id and not position.
     *
     * The label is replaced only if the editor has not set it himself -
     * otherwise "Position" would stand above a list of records.
     *
     * @param array $fieldParams  parameters for Backend::formField()
     * @param int   $parentId     parent record to which the list refers
     * @param array $options      selection list from _previousOptions()
     */
    private function _editPreviousParams(array $fieldParams, int $parentId, array $options): array
    {
        $fieldParams['name'] = 'previous_id';
        $fieldParams['value'] = $this->_previousValue($parentId, $options);

        $defaultLabel = (string)($this->cms->schema['position']['label'] ?? '');
        if ((string)($fieldParams['colDef']['label'] ?? '') === $defaultLabel) {
            $fieldParams['colDef']['label'] = '#record.field_previous_id';
        }
        return $fieldParams;
    }

    /**
     * Preselection of the predecessor list.
     *
     * Three cases, in the order of checking:
     *
     *  1. The form is shown again after a failed save - then the editor's input
     *     is in the request and stays.
     *  2. New record - at the end of the siblings, the same default as in
     *     updatePreviousOptions(). A COPY does not arrive here: _editLoad() puts
     *     its model as previous_id into $this->data, so it falls under case 1
     *     and stands directly after the model.
     *  3. Existing record - the way back from the saved `position`:
     *     getPreviousId() looks for the largest position below its own under the same
     *     parent. Position 1 means "at the beginning", so no predecessor.
     *
     * @param int   $parentId  parent record to which the list refers
     * @param array $options   selection list from _previousOptions()
     */
    private function _previousValue(int $parentId, array $options): int
    {
        if (array_key_exists('previous_id', $this->data)) {
            return (int)$this->data['previous_id'];
        }
        if (in_array($this->function, ['create', 'copy'], true)) {
            // 'disabled' is the "…" entry of a truncated list, not a value
            unset($options['disabled']);
            return (int)array_key_last($options);
        }
        $position = (int)($this->data['position'] ?? 0);
        if ($position < 2) return 0;
        // areaId: at the root level (parent_id IS NULL) only the content
        // area distinguishes the siblings from each other
        return $this->record->getPreviousId($parentId, $position, (int)$this->areaId);
    }

    /**
     * Ajax: deliver the options of the select field "previous_id" anew
     * (backend/record_previous.php).
     *
     * Called as soon as the editor changes the parent record in the form -
     * the previous predecessor list then belongs to the wrong siblings. The
     * response deliberately consists only of <option> rows; the JavaScript thereby replaces
     * the content of the existing <select> (cApp.forms.updateElement()).
     *
     * Area and original id do NOT come from the request, but via
     * Record::getProdData() from the working copy - this also checks that it belongs to the
     * logged-in editor. Without a working copy (id = 0: the insertion position
     * of the import mask, RecordBackendImport) area_id from the request applies.
     *
     * The LAST entry is always preselected: the record ends up at the end
     * of its new siblings, the same default as when creating.
     *
     * Request: id = working copy (0 = none, then area_id), data[parent_id] = newly
     *          selected parent record
     */
    public function updatePreviousOptions(): void
    {
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->data = (array)($_REQUEST['data'] ?? []);

        if ($this->id > 0) {
            if (!$prodData = $this->record->getProdData($this->id)) {
                $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
                $this->errors();
                return;
            }
            [$originId, $areaId] = $prodData;
            $this->data['origin_id'] = $originId;
            $this->areaId = $areaId;
        }
        else {
            $this->areaId = (int)($_REQUEST['area_id'] ?? 0);
            $this->data['origin_id'] = 0;
        }

        if (!$this->areaData = $this->backend->areaData($this->areaId)) {
            $this->errors();
            return;
        }

        $options = $this->_previousOptions((int)($this->data['parent_id'] ?? 0));

        $selectable = $options;
        unset($selectable['disabled']);
        $lastKey = array_key_last($selectable);

        foreach ($options as $key => $val) {
            if ((string)$key === 'disabled') {
                $attr = ' disabled';
                $key = '';
            }
            else {
                $attr = ((string)$key === (string)$lastKey) ? ' selected' : '';
            }
            echo '<option value="' . htmlspecialchars((string)$key) . '"' . $attr . '>'
                . $this->cms->_strip_tags($val) . "</option>\n";
        }
    }

    /**
     * Ajax: update the preview of the parent-record lookup
     * (backend/ajax_parent_lookup.php).
     *
     * Called by cApp.ui.plookupUpdate() after a check mark was set or removed in the
     * selection list. Nothing is written here: the response contains the hidden field
     * data[parent_id], which is only sent along with the form.
     *
     * Request: src_id = working copy, parent_id = selected parent record (0 = none)
     */
    public function parentLookupPreview(): void
    {
        $this->id = (int)($_REQUEST['src_id'] ?? 0);
        $parentId = (int)($_REQUEST['parent_id'] ?? 0);

        if (!$prodData = $this->record->getProdData($this->id)) {
            $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
            $this->errors();
            return;
        }
        // a record cannot be its own parent record
        if ($parentId > 0 && $parentId === (int)$prodData[0]) $parentId = 0;

        echo $this->_parentLookupPreviewCreate($parentId);
    }

    /**
     * Render the preview block of the parent-record lookup.
     *
     * Common core of _editForm() and parentLookupPreview(). Unlike with a link field
     * it is always at most ONE row - and this row IS the input field: it carries the
     * hidden data[parent_id]. Without a parent record the block stays empty, then the
     * field is missing in the request and `parent_id` falls back to the root level.
     *
     * The label is the content of the default field of the area in which the
     * selected record lies.
     *
     * @param int $parentId  parent record or 0
     */
    private function _parentLookupPreviewCreate(int $parentId): string
    {
        if ($parentId < 1) return '';

        $this->cms->select([
            'filter' => ['record_id' => $parentId],
            'limit' => 1
        ]);
        if ($this->db->num_rows < 1) return '';
        if (!$data = $this->cms->fetch()) return '';

        $defaultField = $this->backend->getDefaultField((int)$data['area_id']);
        $label = $this->backend->backendValue(
            $data[$defaultField['name']] ?? '',
            ['type' => $defaultField['type'] ?? 'text']
        );

        $table = $this->backend->template('record.slookup_table', [
            '{table_rows}' => $this->backend->template('record.plookup_table_row', [
                '{text}' => $label,
                '{data_id}' => $parentId,
            ])
        ]);

        return $this->backend->template('record.lookup_preview_container', [
            '{html}' => $table
        ]);
    }

    /**
     * Convert loaded values into the input format.
     *
     * Values come from the database in their storage format; the form should show
     * what the editor would have entered:
     *
     *     currency        stored in cents -> divided by 100, two decimal places
     *     float, decimal  local decimal separator
     *     date, time      ISO format -> local format
     *     relation,       existing links as an id array (or a single id for
     *     pointer         a selection field) - only for direct selection; lookup fields
     *                     fetch their display themselves
     *
     * Dataset takes the way back when saving.
     *
     * @param array &$schemaVirtual  reference to the table schema, virtual fields only
     */
    private function _editFormatLoadedValues(array &$schemaVirtual):void
    {
        // $virtalSchema contains all input fields
        foreach ($schemaVirtual as $name => $colDef) {
            // only consider visible input fields
            $visibilityInput = (int)($colDef['visibility_input'] ?? 0);
            if ($visibilityInput == 0) continue;

            // convert values to the input format depending on type
            $type = (string)($colDef['type'] ?? 'text');
            $displayType = $colDef['display_type'] ?? 'text';
            $options = [];
            // multilingual fields are formatted per language by _editLanguageValues()
            // (if the instance maintains several languages - otherwise like single-language)
            if (!empty($colDef['multilingual'])
                && in_array($type, $this->cms->multilingualTypes, true)
                && count($this->_recordLanguages()) > 1
            ) {
                continue;
            }
            if (in_array($type, $this->cms->relationTypes, true)) {
                // relation / pointer direct selection ('link' has none: lookup or
                // embedded masks, which fetch their display themselves)
                if ($displayType !== 'lookup' && !in_array($type, $this->cms->dependentTypes, true)) {
                    // load existing links into an array:
                    $this->cms->select([
                        'src_id' => $this->id,
                        'relation' => $name
                    ]);
                    $destIds = [];
                    while ($row = $this->db->fetch()) {
                        $destIds[] = (int)$row['record_id'];
                    }

                    if ($displayType === 'select') {
                        $this->data[$name] = $destIds[0] ?? 0;
                    }
                    else {
                        // multiselect
                        $this->data[$name] = $destIds;
                    }

                }
            }
            else {
                $this->data[$name] = $this->_editFormatValue($type, $displayType, $this->data[$name] ?? null);
            }
        }    
    }

    /**
     * function=create (with area): create a working copy for a new record.
     *
     * tmpInit() creates it immediately in the database - unlike init() in the other
     * modules, which only returns an array with defaults. The reason: links and
     * uploads need a record_id to which they can attach, already
     * while the form is being filled in.
     *
     * $this->id then holds the id of the working copy, not 0.
     */
    private function _editCreate(): void
    {
        // INIT
        // --------------------
        // (rights: creating in the area is checked by tmpInit(), message in Cms::errors)
        if (!$this->data = $this->record->tmpInit(['area_id' => $this->areaId])) {
            if (!$this->cms->hasErrors) $this->cms->error('_editCreate(): creation of temporary dataset failed.');
            $this->function = 'skip';
            return;
        }
        $this->id = $this->data['record_id'];
        $this->session->registerWorkingCopy((int)$this->id);
    }

    /**
     * function=create (without area): step 1 - choose the content area.
     *
     * Only the area says which fields a record has; without it no
     * mask can be built. The selection submits the same script again with area_id set.
     *
     * If the call comes from a pointer / link field, only its target areas are
     * selectable ($areaIds from _resolveParams()); otherwise the dependent areas
     * are missing (`area.dependent`, see LinkTrait).
     */
    private function _editCreateForm(): void
    {
        echo '<form action="Record/record_edit.php" method="post">';
        $this->backend->formInit('record.record_create_form');

       // area_id
        $sqlTypes = '';
        $sqlValues = [];
        // (only areas in which the group may create)
        $sql = "SELECT `area_id`, `depth`, `label` FROM `area` WHERE `type` IN ('record','profile','ugc') AND `deleted`=0"
             . $this->backend->areaAccessSql(writable:true);
        
        if (!empty($this->areaIds)) {
            $placeholders = implode(',', array_fill(0, count($this->areaIds), '?'));
            $sql .= " AND `area_id` IN ($placeholders)";                
            $sqlTypes .= str_repeat('i', count($this->areaIds));
            $sqlValues = $this->areaIds;
        }
        else {
            // dependent areas (target areas of 'link' fields) only from such a
            // field - their records belong to a source record
            $sql .= " AND `dependent` = 0";
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
            'type' => 'integer',
            'display_type' => 'select',
            'label' => '#area.content_area'
        ];
        $this->backend->formField($this->record, [
            'name' => 'area_id',
            'value' => $this->areaFilter,
            'colDef' => $areaColDef,
            'options' => $areaOptions,
            'template' => 'record.record_select_area_id',
            'selectPlease' => true
        ]);

        // hidden fields
        // --------------------
        $this->backend->formHidden('function', 'create');
        // call by lookup field
        $this->backend->formHidden('src_field', $this->srcField);
        $this->backend->formHidden('src_id', $this->srcId);

        // render the form
        echo $this->backend->form('form_save_cancel');
        echo '</form>';
    }

    /**
     * function=edit|copy|delete: load the record and check it out for editing.
     *
     * Three steps:
     *
     *  1. Load. A working copy or a deleted record must not arrive here - always the
     *     productive state is edited.
     *  2. Check the edit lock (only for 'edit'): if Record::getLock() finds a
     *     foreign working copy, someone else is working on it at the moment. A note
     *     with name and time is then shown, plus a link with unlock=1 that
     *     bypasses the lock - a hard lock would be unusable, because an
     *     aborted session would otherwise leave it in place permanently.
     *  3. Check-out: Record::checkOut() creates the working copy, from here on
     *     $this->id points to it. When copying, a copy without
     *     origin_id arises - it becomes a new record when saved.
     */
    /**
     * Placeholder instead of the input mask for a GET (see edit(), "check-out only via
     * POST"). cApp.layers.openPost() reloads the layer via POST - immediately if it
     * was opened from the interface, otherwise with the button.
     */
    private function _editOpenStub(): void
    {
        $elementId = 'c9-open-' . bin2hex(random_bytes(6));
        $label = match ($this->function) {
            'copy' => $this->cms->snippet('button_copy'),
            'create' => $this->cms->snippet('button_create'),
            default => $this->cms->snippet('button_edit'),
        };
        echo $this->backend->template('record.edit_open_stub', [
            '{element_id}' => $elementId,
            '{button}' => htmlspecialchars($label),
            '{onclick}' => "cApp.layers.openPost('{$elementId}', true)",
        ]);
        $this->backend->script("cApp.layers.openPost('{$elementId}');");
    }

    private function _editLoad(): void
    {
        // load, productive?, rights of the user group (developer docs, chapter 11 (Permissions)):
        // change/delete according to the group of the owner, copy = create in the area -
        // and only if all link fields have readable target areas
        // (RecordService::loadForAction())
        $action = match ($this->function) {
            'delete' => 'delete',
            'copy'   => 'copy',
            default  => 'edit',
        };
        if (!$this->data = $this->service->loadForAction($this->id, $action)) {
            // Fehlermeldung steckt in Cms::errors
            $this->function = 'skip';
            return;
        }

        // When resolving the page parameters the area was not yet fixed - the
        // call names only the record id. Only now can the details on the
        // default and sort field be loaded; without them the labels in
        // _parentOptions() and _previousOptions() remain empty.
        $this->areaId = (int)$this->data['area_id'];
        $this->areaData = $this->backend->areaData($this->areaId);

        // check the edit lock (unless parameter unlock=1 is set)
        // --------------------
        if ($this->function == 'edit' && !(bool)($_REQUEST['unlock'] ?? 0)) {
            if ($lock = $this->service->lockInfo($this->data)) {
                // message with buttons to lift the lock / cancel - output in edit()
                // below the heading and the default field of the record
                $defaultName = $this->backend->getDefaultField($this->areaId)['name'] ?? '';
                $label = $this->data[$defaultName] ?? '';
                if (!is_scalar($label) || (string)$label === '') $label = $this->id;
                // (template() resolves {#…} only after the placeholders - the text with
                // its own placeholders is therefore assembled here)
                $text = strtr($this->cms->snippet('record.alert_record_locked'), [
                    '{admin_name}' => $this->backend->teaser('admin', $lock['admin_id']),
                    '{changed_at}' => $this->cms->_formatDate($this->cms->utcToUserTZ(substr($lock['changed_at'], 0, 19)))
                ]);
                $this->lockAlert = $this->backend->template('record.alert_record_locked', [
                    '{id}' => $this->id,
                    '{label}' => htmlspecialchars((string)$label),
                    '{text}' => $text,
                ]);
                $this->function = 'locked';
                return;
            }
        }

        // CHECK-OUT
        // --------------------
        // copy: RecordService::checkOut() passes the model along as `previous_id` -
        // it then stands directly after it, under the same parent record.
        // Firmly linked contents of embedded 'link' fields are checked out
        // along (not when deleting); if that fails, the working copy is
        // discarded again and $this->id still points to the original.
        $tmpId = $this->service->checkOut($this->data, $this->function === 'copy', dependents: $this->function !== 'delete');
        if (!$tmpId) {
            $this->function = 'skip';
            return;
        }
        $this->id = $tmpId;
    }


    /**
     * The input mask for an EXISTING working copy of the session admin - without
     * a new check-out. Needed by moving (RecordBackendMove): if the check in the
     * target area fails there, the working copy reshaped by MoveTrait::movePrepare()
     * remains, and the editor adds the missing inputs in the mask of the
     * target area. From here on it is an ordinary edit: saving
     * (function=save) checks the copy in, cancelling (record_cancel.php) discards
     * it - the record then stays where it was.
     *
     * Values come from the working copy itself: Record::load() reads it like a
     * productive record (Cms::fetch() via `record_cache`, links via
     * data_rel of the copy), languages are added by _editLanguageValues() from the
     * working state. Field errors in Cms::errors (names of the target fields, multilingual
     * "name.{language_id}") appear at their fields.
     *
     * @param int $tmpId  working copy (tmp = 1, changed_by = session admin)
     * @return bool       false if the copy does not (or no longer) belong to the admin
     */
    protected function _editWorkingCopyForm(int $tmpId): bool
    {
        if (!$prodData = $this->record->getProdData($tmpId)) {
            $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
            return false;
        }
        $this->id = $tmpId;
        $this->areaId = (int)$prodData[1];
        if (!$this->areaData = $this->backend->areaData($this->areaId)) {
            $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            return false;
        }
        $this->params = 'area_id=' . $this->areaId;
        if (!$this->data = $this->record->load($tmpId)) return false;
        $this->data['record_id'] = $tmpId;

        $schemaVirtual = $this->record->virtualSchema($this->areaId, addMetaFields: false);
        // check out embedded contents (link/embed) of the copy, unless already
        // done - when moving, their edges still point to the productive
        // contents (LinkTrait::checkOutDependents() skips working copies)
        if (!$this->record->checkOutDependents($tmpId, false, $schemaVirtual)) return false;
        $this->_editFormatLoadedValues($schemaVirtual);
        $this->_editRestoreSecuredFiles($schemaVirtual);
        $this->_editForm($schemaVirtual);
        return true;
    }

    /**
     * function=save: check the inputs and save them.
     *
     * Record::save() does everything else: validation, checkIn of the working copy,
     * taking over the files (promoteTmpFiles()) and the links, recalculation
     * of the tree - all in one transaction. The check of the edit lock
     * is also located there.
     *
     * Where it goes afterwards depends on where the call came from: from a
     * lookup field the new record is linked there right away, otherwise
     * the record list is updated.
     *
     * @param array $fields  fields to be written - the schema fields of the area
     */
    private function _editSave(array $fields): void
    {
        // EDIT LOCK - check access
        // is performed in Record::save()

        // Firmly linked contents (link, LinkTrait)
        // --------------------
        // 1. check the embedded contents with their inputs and save them in between
        //    (stayTemporary) - before anything else, so that no check-in begins
        //    while a cell has errors
        // 2. in one transaction: check-in of the contents (checkInDependents(): also
        //    copies and orphans), then the source record. If one fails,
        //    everything is rolled back; the working copies remain
        $schemaVirtual = $this->record->virtualSchema($this->areaId, addMetaFields:false);
        if (!$this->_embedSave($schemaVirtual)) {
            $this->function = 'errors';
            return;
        }
        // check-in including the contents in one transaction (RecordService::checkIn()).
        // copy: on errors the mask shows the input, not the storage format
        $newId = $this->service->checkIn($this->id, $fields, $this->data);
        if ($newId) {
            // balloon message
            $msg = $this->cms->snippet('message_record_saved');
            $this->backend->balloon('success', $msg);

            // debug function (suspend the redirect to see direct debug output)
            if (
                $this->data['title'] == 'camindodebugmedia'
                && !empty($this->config->get('system.development_version'))
            ) {
                echo $msg . '<br><br>' . $this->backend->template('button_close', [
                    '{onclick}' => 'cApp.layers.close()'
                ]);
                return;
            }

            if ($this->srcField > 0 && $this->srcIsParent && $this->srcId > 0) {
                // referrer is the lookup field for the parent record in an
                // input mask -> enter the new record there (do not link it).
                // Close first: this form HAS ITSELF a lookup field with the
                // same src-field, which would otherwise be found as the target
                $this->backend->script("
                    cApp.layers.close();
                    cApp.ui.plookupUpdate({$this->srcField},'link',$newId,true);
                ");
            }
            elseif ($this->srcField > 0 && $this->srcIsParent) {
                // the same field in the SEARCH MASK (no src_id): there several
                // parent records may be chosen, the usual search lookup applies
                $this->backend->script("
                    cApp.layers.close();
                    cApp.ui.slookupUpdate({$this->srcField},'link',$newId,true);
                ");
            }
            elseif ($this->srcField > 0) {
                if (!empty($this->straight)) {
                    // referrer is lookup field -> link the new record
                    $this->backend->script("
                        cApp.ui.lookupRUpdate({$this->srcField},'link',$newId,0,true,{$this->srcId});
                        cApp.layers.close();
                    ");
                }
                else {
                    // referrer is link list -> update the list
                    // and then link the record (closes the list if applicable)
                    $this->backend->script("
                        cApp.layers.invalidate(function(){cApp.ui.lookupRUpdate({$this->srcField},'link',$newId,0,false,{$this->srcId});});
                        cApp.layers.close();
                    ");
                }
            }
            else {
                // referrer is record list -> display it updated
                $this->backend->script("
                    cApp.layers.invalidate();
                    cApp.layers.close();
                ");
            }
            return;
        }
        $this->function = 'errors';
    }


    /**
     * After a failed input check (function 'errors'):
     * feed the file fields from the intermediate state (cache) of the working copy back into $this->data,
     * so that the uploads already secured by Dataset::save() -> _secureUploads()
     * appear in the input mask shown again (preview link + x button in _editForm()).
     *
     * Background: $this->data is based on $_REQUEST['data'] (POST) - file fields
     * are never in there; $_FILES is consumed after move_uploaded_file().
     * The file itself is already correctly attached to the working copy
     * (physically + `data_fil` + cache) and goes productive at the next successful
     * save via promoteTmpFiles() - only the display is missing.
     *
     * @param array $schemaVirtual  schema with virtual fields
     */
    private function _editRestoreSecuredFiles(array $schemaVirtual): void
    {
        if ($this->id < 1) return;

        // only for temporary working copies of the session admin
        // (same access pattern as removeUpload())
        if (!$this->record->getProdData($this->id)) return;

        // load the working state of the working copy
        $cache = $this->record->loadTmpData($this->id);
        if (empty($cache)) return;

        foreach ($schemaVirtual as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') !== 'file') continue;
            // entry has the format [$type, $fileData], multilingual per language
            $entry = $cache[$name] ?? null;
            if (($entry[0] ?? '') !== 'file') continue;
            if ($this->cms->isMultilingualField($colDef)) {
                $files = [];
                foreach ($this->record->fileEntries((array)$entry) as $langKey => $fileData) {
                    if ($langKey === '') continue;
                    if (!empty($fileData['deleted']) || (int)($fileData['size'] ?? 0) < 1) continue;
                    $files[(int)$langKey] = $fileData;
                }
                $this->data[$name] = $files;
                continue;
            }
            $fileData = (array)($entry[1] ?? []);
            // do not show delete markers (x button) and empty entries
            if (!empty($fileData['deleted']) || (int)($fileData['size'] ?? 0) < 1) continue;
            $this->data[$name] = $fileData;
        }
    }

    /**
     * function=delete: deletion confirmation.
     *
     * If the area has a tree (input field `parent_id`, Record::isTreeArea())
     * and the record has descendants, the user is asked, as with folders, what
     * should happen to them (cf. AreaBackend::_editDeleteForm()): move up one level
     * or delete along. The radio field is in
     * record_delete_with_option_form.html and comes back as $_REQUEST['deletechilds'].
     * Without a tree resp. without descendants the simple confirmation remains.
     *
     * The whole subtree is counted via lft/rgt (not only the direct children),
     * per area - there lft starts again at 1 each time (TreeManagerTrait). The
     * boundaries come from $this->data: that is the productive state that
     * _editLoad() loaded - the check-out only changes record_id/tmp/
     * origin_id there, the working copy itself has lft = rgt = 0.
     */
    private function _editDeleteForm(): void
    {
        echo '<form action="Record/record_edit.php" method="post">';

        $countChilds = 0;
        if ($this->record->isTreeArea($this->areaId)) {
            $lft = (int)($this->data['lft'] ?? 0);
            $rgt = (int)($this->data['rgt'] ?? 0);
            if ($lft > 0 && $rgt > $lft + 1) {
                $sql = "SELECT COUNT(`record_id`) FROM `record`"
                    ." WHERE `area_id` = ? AND `lft` > ? AND `rgt` < ?"
                    ." AND `tmp` = 0 AND `deleted` = 0";
                $this->db->query($sql, 'iii', [$this->areaId, $lft, $rgt]);
                $countChilds = (int)$this->db->fetchNum()[0];
            }
        }

        // linked records (any reference field, also two-sided relations
        // via hidden counter fields): note with button to the cross-area
        // hit list relation=any + dest_id - the same set as the count
        $references = '';
        $originId = (int)($this->data['origin_id'] ?? 0) ?: $this->id;
        $linked = $this->service->linkedRecordCount($originId);
        if ($linked > 0) {
            $references = $this->backend->template('record.record_delete_references', [
                '{text}' => htmlspecialchars($this->cms->snippet('record.alert_record_delete_referenced', ['count' => $linked])),
                '{button}' => $this->backend->template('button_secondary', [
                    '{onclick}' => "cApp.layers.add('Record/record_list.php?relation=any&dest_id={$originId}')",
                    '{label}' => htmlspecialchars($this->cms->snippet('record.button_referenced_details')),
                ]),
            ]);
        }

        // what the deletion policy of the area does with the database (module Privacy)
        $policy = $GLOBALS['app']->get(\Camindo\Core\Modules\Privacy\PrivacyBackend::class)->hintHtml($originId);

        if ($countChilds > 0) {
            // record has descendants -> confirmation with a choice
            $items = $this->cms->snippet('record.x_records', ['count' => $countChilds]);
            $msg = str_replace('{items}', $items, $this->cms->snippet('record.alert_record_delete_with_childs'));
            $uid = $this->crypt->randomKey(16);
            $this->backend->formInit('record.record_delete_with_option_form', [
                '{text}' => $msg,
                '{id}' => $uid,
                '{references}' => $references,
                '{policy}' => $policy,
            ]);
        }
        else {
            $this->backend->formInit('record.record_delete_form', ['{references}' => $references, '{policy}' => $policy]);
        }

        // Hiddenfelder
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('function', 'delete_confirm');
        // call by lookup field?
        $this->backend->formHidden('src_field', $this->srcField);
        $this->backend->formHidden('src_id', $this->srcId);

        // render the form
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }

    /**
     * Carry out the deletion - according to the deletion policy of the area (module Privacy,
     * RecordService::delete()). Usually this means: into the trash; it is then
     * permanently deleted by the system tool after admin.trash_retention_days
     * (scheduler task "Empty trash"). Instead, the policy can overwrite fields or
     * act only later ('changed' / 'scheduled').
     *
     * $withChilds controls what happens to the child records (radio field from
     * _editDeleteForm(), only for areas with a tree and existing descendants):
     *     true   the whole subtree is marked along, the tree remains unchanged
     *     false  the children move up one level (Dataset::liftChildren()), default
     * Large subtrees move from admin.mass_delete_threshold into a
     * background process, so that deleting does not fail on the web timeout.
     */
    private function _editDeleteConfirm(): void
    {
        $withChilds = ((string)($_REQUEST['deletechilds'] ?? 'false') === 'true');

        // large subtrees go to the background (RecordService::delete());
        // $this->id is the working copy, the original is meant
        $result = $this->service->delete($this->id, $withChilds);
        if ($result === 'background') {
            $this->backend->balloon('info', $this->cms->snippet('message_background_started'));
            $this->backend->script("
                cApp.layers.invalidate();
                cApp.layers.close();
            ");
            return;
        }

        // DELETE
        // --------------------
        if (in_array($result, ['deleted', 'changed', 'scheduled'], true)) {
            // balloon message
            $msg = $this->cms->snippet(match ($result) {
                'changed'   => 'privacy.message_record_changed',
                'scheduled' => 'privacy.message_record_scheduled',
                default     => 'record.message_record_deleted',
            });
            $this->backend->balloon('success', $msg);
            
            if ($this->srcField > 0) {
                // referrer is lookup field -> update the preview container
                $this->backend->script("
                    cApp.ui.lookupRUpdate({$this->srcField},'',0,0,false,{$this->srcId});
                    cApp.layers.close();
                ");
            }
            else {
                // referrer is record list -> update it
                $this->backend->script("
                    cApp.layers.invalidate();
                    cApp.layers.close();
                ");
            }
        }
        else {
            $this->function = 'errors';
        }
    }


    /**
     * Cancel editing (Ajax).
     *
     * Discards the working copy together with the files attached to it and thereby
     * also lifts the edit lock. The productive record remains as it was - which is why
     * "Cancel" really has no consequences here, although writing already took place
     * in the meantime.
     */
    public function cancel(): void {
        // discards the working copy together with uploaded files - POST only
        // (developer docs, chapter 14 (Security: web)). The layer closes in any case: a layer
        // left standing would be worse for the user than a remaining working copy, which the
        // trash (module Tools) clears away anyway.
        $id = (int)($_REQUEST['id'] ?? 0);
        if ($this->backend->requirePost()) $this->record->cancel($id);
        $this->backend->script('cApp.layers.close();');
    }


    /**
     * Selection list of the linkable records.
     *
     * For relation and pointer fields with direct selection (display_type select,
     * multiselect, radio, checkbox) - not for lookup fields, which have their own
     * search window.
     *
     * With several target areas (pointer) they are processed one after the other;
     * within an area its default sort order applies, and the content of its default
     * field is shown. Both can differ per area, which is why the
     * loop is used instead of a single query.
     *
     * The total number is limited to admin.list_max_datasets (default 200) - the
     * counter runs across all areas, and the limit of the next query is reduced
     * by what has already been loaded. If it is exceeded, processing stops and a
     * disabled "…" entry is appended, instead of silently truncating the list.
     * The lookup field is intended for larger volumes.
     *
     * @param array $destAreas      content area ids
     * @param bool $addEmptyOption  true (default): "please select" option at the start of the list
     * @return array                array with id => defaultField elements
     */
    protected function _relatedDatasetOptions(array $destAreas, bool $addEmptyOption = false): array {

        $areaIds = $this->system->sanitizeIds($destAreas, removeZero:true);
        if (empty($areaIds)) return [];

        $multipleAreas = (count($areaIds) > 1);

        // maximum records in direct selection
        $limit = $this->config->get('admin.list_max_datasets') ?? 200;
        // count across several areas
        $count = 0;

        $options = $addEmptyOption ? ['' => $this->cms->snippet('please_select')] : [];
        
        // records of several areas: grouped by area,
        // within an area the area's default sort order is used
        foreach ($areaIds as $areaId) {
            $areaData = $this->backend->areaData((int)$areaId);
            $defaultFieldName = (string)$areaData['default_field_name'];
            $defaultFieldType = (string)$areaData['default_field_type'] ?? 'text';
            $param = [];
            $param['filter']['area_id'] = $areaId;
            // default sort order of the area
            $param['sort'] = (string)$areaData['sort_field_name'];
            $param['order'] = $areaData['sort_order'];
            // load +1 to check whether more exist, minus the number from previous rounds
            $param['limit'] = $limit + 1 - $count;
            $this->cms->select($param);
            $numRows = $this->db->num_rows;
            // number of records displayed
            $displayNumber = min($numRows, $limit - $count);
            $colDef = ['type' => $defaultFieldType];
            for ($i = 0; $i < $displayNumber; $i++) {
                $data = $this->record->fetch();
                $value = $this->backend->backendValue($data[$defaultFieldName] ?? '', $colDef);
                $options[(string)$data['record_id']] = $value;
            }
            // add newly loaded records to the total counter
            $count += $numRows;
            // limit exceeded?
            if ($count > $limit) {
                $options['disabled'] = $this->cms->snippet('ellipsis_spoken');
                break; // do not process any further areas
            }
        }
        
        return $options;
    }

    /**
     * Ajax: edit the links of a lookup field and deliver the preview anew.
     *
     * The link is written IMMEDIATELY, not only when the form is saved - possible
     * because it is attached to the working copy and would be discarded with it
     * (see class comment).
     *
     *     link     link; with max = 1 the existing link is replaced
     *              instead of creating a second one
     *     unlink   remove a link
     *     empty    remove all links of the field
     *     swap     swap two links in order (only for fields
     *              with custom_order)
     *
     * In any case, the updated preview follows afterwards. Errors are caught and
     * output as a message - the checks (maximum number, permitted target area)
     * are located in Record::createRelations().
     *
     * The commented-out 'up'/'down' block is the predecessor of 'swap'.
     *
     * Request: src_field, src_id, function, dest_id, swap_id
     */
    public function lookupPreview() {
        $srcField  = (int)($_REQUEST['src_field'] ?? 0);
        $srcId     = (int)($_REQUEST['src_id'] ?? 0);
        $destId    = (int)($_REQUEST['dest_id'] ?? 0);
        $swapId    = (int)($_REQUEST['swap_id'] ?? 0);
        $function  = (string)($_REQUEST['function'] ?? '');

        // link/unlink/empty write data_rel of the working copy - POST only
        // (developer docs, chapter 14 (Security: web)). Without 'function' the call is a pure
        // redisplay of the preview block and may remain GET.
        if (in_array($function, ['link', 'unlink', 'empty'], true) && !$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }

        // load data of the given relation field
        if (!$fieldData = $this->record->fieldData($srcField)) {
            $this->cms->error('lookupPreview() Exception: field not found.');
            $this->errors();
            return;
        }

        if ($function == 'link') {
            try {
                $max = (int)$fieldData['max'];
                if ($max === 1) $replace = true;
                else $replace = false;
                $this->record->createRelations($fieldData, $srcId, $destId, $replace);
            } catch (\Exception $e) {
                // (field errors from createRelations() are already in Cms::errors - '#')
                $errorMsg = $e->getMessage();
                if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
                $this->errors();
                return;
            }
            $msg = $this->cms->snippet('record.message_relation_updated');
            $this->backend->balloon('success', $msg);
        }

        if ($function === 'unlink') {
            try {
                $this->record->removeRelations($fieldData, $srcId, $destId);
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                $this->cms->error($errorMsg);
                $this->errors();
                return;
            }
            $msg = $this->cms->snippet('record.message_relation_updated');
            $this->backend->balloon('success', $msg);
        }

        if ($function === 'empty') {
            try {
                $this->record->removeRelations($fieldData, $srcId);
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                $this->cms->error($errorMsg);
                $this->errors();
                return;
            }
            $msg = $this->cms->snippet('record.message_lookup_emptied');
            $this->backend->balloon('success', $msg);
        }

        /*
        if ($function === 'up' || $function === 'down') {
            if ($this->record->moveRelationDestId($srcField, $srcId, $destId, $function)) {
                $msg = $this->cms->snippet('record.message_relation_updated');
                $this->backend->balloon('success', $msg);
            }
        }
        */

        if ($function == 'swap') {
            if ($this->record->swapRelationPosition($fieldData, $srcId, $destId, $swapId)) {
                $msg = $this->cms->snippet('record.message_relation_updated');
                $this->backend->balloon('success', $msg);
            }
        }

        echo $this->_lookupPreviewCreate($srcField, $srcId) ?? '';
    }


    /**
     * Render the preview block of a lookup field.
     *
     * Common core of lookupPreview() and _editForm() - the form builds it
     * on the first build, afterwards the JavaScript replaces it after every change.
     *
     * The block contains one row per link with the title of the target record
     * and a remove reference, plus hidden fields with the ids - so it IS
     * the input field.
     *
     * Which records come into question results from the field type: 'relation' has
     * exactly one target area, 'pointer' several.
     *
     * Each row shows the default field of the target record - or, if the field
     * carries details.lookup_detailed, the detailed version
     * (RecordBackend::detailedText()): all list fields of the target area one below the other,
     * the default field in bold, plus the output of the list template of the area.
     */
    protected function _lookupPreviewCreate(int $srcField, int $srcId): ?string {
        if ($srcField < 1 || $srcId < 1) {
            $this->cms->error('_lookupPreviewCreate() Exception: wrong parameters.');
            $this->errors();
            return null;
        }
        // load data of the given relation field
        if (!$fieldData = $this->record->fieldData($srcField)) {
            $this->cms->error('_lookupPreviewCreate() Exception: field not found.');
            $this->errors();
            return null;
        }
        $type = $fieldData['type'];
        if (str_starts_with($type, 'relation')) {
            // one target area
            $relAreaId = (int)$fieldData['rel_area_id'];
            if (empty($relAreaId)) return null;
            $relAreaIds = [(int)$relAreaId];
        }
        elseif (in_array($type, $this->cms->multiAreaTypes, true)) {
            // target areas (pointer, link)
            $relAreaIds = $this->record->getRelAreaIds($srcField);
            if (empty($relAreaIds)) return null;
        }
        else {
            $this->cms->error('list() Exception: src_field is not of type relation.');
            return null;
        }
        $fieldName = $fieldData['name'];

        // detailed preview? (field.details.lookup_detailed)
        $details = json_decode((string)($fieldData['details'] ?? ''), true) ?: [];
        $detailed = !empty($details['lookup_detailed']);

        // custom_order
        $customOrder = (int)$fieldData['custom_order'];
        if ($customOrder) $sortOrder = '_custom ASC';
        else $sortOrder = 'created_by DESC';

        $this->cms->select([
            'filter' => [
                'area_id' => $relAreaIds,
            ],
            'src_id' => $srcId,
            'relation' => $fieldName,
            'sort' => $sortOrder
        ]);
        
        if ($this->db->num_rows < 1) return '';

        
        $rowTpl = $customOrder ? 'record.lookup_table_ordered_row' : 'record.lookup_table_row';

        // for each row
        // --------------------
        $rows = [];
        $i = 0;
        $count = $this->db->num_rows;
        $data = [];
        $nextData = [];
        for ($i = 0; $i <= $this->db->num_rows; $i++) {
            $previousData = $data;
            $data = $nextData;
            if ($i < $this->db->num_rows) {
                $nextData = $this->record->fetch();
            }
            else {
                $nextData = [];
            }
            if ($i === 0) continue;

            $this->db->pushResult();

            // default field text / detailed preview
            // --------------------
            $id = (int)$data['record_id'];
            $areaId = (int)$data['area_id'];
            if ($detailed) {
                $label = $this->detailedText($data);
            }
            else {
                $defaultField = $this->backend->getDefaultField($areaId);
                $fieldName = $defaultField['name'];
                $fieldType =  $defaultField['type'] ?? 'text';
                $label = $this->backend->backendValue(
                    $data[$fieldName] ?? '',
                    ['type' => $fieldType]
                );
            }
            // (the detailed version without text-truncate - it has several lines)
            $columns = $this->backend->template($detailed ? 'record.lookup_table_column_detailed' : 'record.lookup_table_column', [
                '{text}' => $label
            ]);

            // up and down buttons
            // --------------------
            $buttons = [];
            if ($customOrder > 0) {          
                // "down" button
                $nextId = (int)$nextData['record_id'];
                $replace = [
                    '{down_url}' => "cApp.ui.lookupUpdate('swap', $id, $nextId);",
                    '{class}' => ''
                ];
                if (empty($nextData)) {
                    // hide
                    $replace['{class}'] = ' invisible';
                }
                $buttons[] = $this->backend->template('record.lookup_button_down', $replace);

                // "up" button
                $previousId = (int)$previousData['record_id'];
                $replace = [
                    '{up_url}' => "cApp.ui.lookupUpdate('swap', $id, $previousId);",
                    '{class}' => ''
                ];
                if (empty($previousData)) {
                    // hide
                    $replace['{class}'] = ' invisible';
                }
                $buttons[] = $this->backend->template('record.lookup_button_up', $replace);
            }
            $trClass = '';
            $rows[] = $this->backend->template($rowTpl, [
                '{data_id}' => $id,
                '{columns}' => $columns,
                '{buttons}' => implode('', $buttons),
                '{unlink_url}' => "cApp.ui.lookupUpdate('unlink', $id);",
                '{tr_class}' => $trClass
            ]);
            $this->db->popResult();
        }

        // render table
        // --------------------
        $params = "&src_field={$srcField}&src_id={$srcId}";
        $table = $this->backend->template('record.lookup_table', [
            '{table_rows}' => implode("\n", $rows),
            '{params}' => $params
        ]);

        // render preview container
        $previewContainer = $this->backend->template('record.lookup_preview_container', [
            '{html}' => $table
        ]);
        
        return $previewContainer;
    }

}