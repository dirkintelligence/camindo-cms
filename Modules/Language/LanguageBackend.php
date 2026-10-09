<?php
/**
 * Backend UI for the frontend languages (table `language`).
 *
 * Entry points (scripts in backend/):
 *
 *     language_list.php  -> list()   language list
 *     language_info.php  -> info()   detail view
 *     language_edit.php  -> edit()   create / copy / change / delete
 *
 * No search mask - the list is short and is output without paging (limit -1).
 *
 * SPECIAL CASE: DELETING
 * --------------------
 * Deleting the language removes its content from all instances
 * (Language::hookOnDelete()). The delete form therefore names the instances that use it;
 * if it is the main language somewhere, the form does not offer deletion at all.
 *
 * @see Camindo\Core\Modules\Language\Language          Dataset/Model
 * @see Camindo\Core\Modules\Instance\InstanceBackend   almost identical sister class
 * @see Camindo\Core\Page                                 evaluates the language code in the URL
 */
namespace Camindo\Core\Modules\Language;

use Camindo\Core\Backend;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\Modules\Language\Language;

class LanguageBackend {
    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';

    public function __construct(
        private Backend $backend,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Language $language
    )
    {}

    /**
     * Language list (backend/language_list.php).
     *
     * limit -1 means: no pagination, all languages on one page. Sorted by
     * `position` - this is the order in which the languages should later appear in the
     * language switcher.
     */
    public function list()
    {
        // Page elements
        // --------------------

        // Breadcrumb navigation
        $this->backend->setNaviLabel('#language.module_label');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#language.headline_language_list');
        
        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Language/language_edit.php?function=create');");
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        // Language list
        // --------------------
        $f = $this->cms->select([
            'request' => 'all',
            'limit' => -1,
            'filtertext' => 'true'
        ], $this->language);

        echo $this->backend->paginationTopShort();

        if ($this->db->num_rows > 0) {

            // Table-Head
            $headColumns = $this->backend->template('language.table_head_columns');

            $tableRows = '';

            // Fetch results (Dataset::fetch: with post-processing)
            while ($data = $this->language->fetch()) {
                // language Icon + Label
                $iconHtml = $this->backend->template('language.icon_language', [
                    '{icon}' => $data['icon'],
                    '{color_class}' => $data['color_class']
                ]);
                $labelText = $this->cms->textOrSnippet($data['label']);
                $labelHtml = $iconHtml . ' ' . $labelText; 
                
                // Table-Rows
                $tableRows .= $this->backend->template('language.table_row', [
                    '{name}' => $labelText,
                    '{data_label}' => $labelHtml,
                    '{data_code}' => htmlspecialchars((string)$data['code']),
                    '{data_backend_code}' => htmlspecialchars((string)$data['backend_code']),
                    '{data_timezone}' => htmlspecialchars((string)$data['timezone']) ?: $this->backend->emptyPlaceholder,
                    '{data_id}' => $data['language_id']
                ]) ."\n";
            }

            // Render table
            echo $this->backend->template('language.table_with_head', [
                '{head_columns}' => $headColumns,
                '{table_rows}' => $tableRows
            ]);

            // Context menu
            echo $this->backend->template('language.context_menu');
        }

        // Container Footer
        echo $this->backend->template('container_footer');

    }

    /**
     * Detail view of a language (backend/language_info.php).
     *
     * The headline is built by _editHeadline() - the same method as in the form; so that
     * its case distinction applies there, $this->function is set to 'info' beforehand.
     */
    public function info()
    {
        $id = (int)($_REQUEST['id'] ?? 0);

        // Page elements
        // --------------------

        // Breadcrumb navigation
        $this->backend->setNaviLabel('#button_info');

        // Container header
        echo $this->backend->template('container_header');

        // Language data
        // ---------------  
        // Load Language Data
        if (!$data = $this->language->load($id)) {
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->cms->errorMsg()
            ]);
            echo $this->backend->template('container_footer');
            return;
        }

        // H1
        echo $this->_editHeadline();
        
        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Language/language_edit.php?id={$id}');");
        $this->backend->buttonAdd('button_copy', "cApp.layers.replace('Language/language_edit.php?id={$id}&function=copy');");
        $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Language/language_edit.php?id={$id}&function=delete');");
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        echo $this->backend->buttonBar();

        // Tabs: Info | Changes (audit log, Backend::infoAuditTabs())
        if ($this->backend->infoAuditTabs("Language/language_info.php?id={$id}", 'language', $id)) return;

        // Data
        // ---------------  
        $this->backend->infoAdd($this->language, 'label', $data['label'], ['primary']);
        $this->backend->infoAdd($this->language, 'code', $data['code']);
        $this->backend->infoAdd($this->language, 'endonym', $data['endonym']);
        $backendOptions = $this->backend->backendLanguageOptions();
        $this->backend->infoAdd($this->language, 'backend_code', $backendOptions[$this->cms->resolveBackendLang((string)$data['backend_code'])] ?? $data['backend_code']);
        // Without its own time zone, the one from the configuration applies
        $timezone = (string)$data['timezone'];
        if ($timezone === '') {
            $timezone = $this->cms->serverTZ . ' ' . $this->cms->snippet('language.note_timezone_default');
        }
        $this->backend->infoAdd($this->language, 'timezone', $timezone);
        echo $this->backend->infoTable();

        echo $this->backend->template('container_footer');
    }

    /**
     * Create / copy / change / delete (backend/language_edit.php).
     *
     * State machine via $_REQUEST['function'] as in the other modules
     * (developer docs, chapter 05 (Backend development)).
     */
    public function edit(): void
    {
        // Page parameters
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = (string)($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array)($_REQUEST['data'] ?? []);
        $this->data['language_id'] = $this->id;

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

        // H1
        echo $this->_editHeadline();

        if ($this->function == 'save') {
            $this->_editSave();
        }

        // Delete confirm
        if ($this->function == 'delete_confirm') {
            $this->_editDeleteConfirm();
        }

        // Errors
        if (in_array($this->function, ['errors', 'skip'], true)) {
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
    // edit() helper functions

    private function _editCreate(): void
    {
        $this->data = $this->language->init();
        $this->id = 0;
    }

    /**
     * function=edit|copy|delete: load the language.
     *
     * When copying, the id is discarded and the position is increased by one, so that the
     * copy is sorted after the original.
     */
    private function _editLoad(): void
    {
        $this->data = $this->language->load($this->id);
        if (!$this->data) {
            // Requested record not found
            $this->function = 'skip';
        }
        // If data is to be copied, reset the id now
        if ($this->function == 'copy') {
            $this->id = 0;
            $this->data['position']++;
        }
    }

    /**
     * Headline for form and detail view.
     *
     * Only when deleting and in the detail view is the language itself shown above it -
     * there it is the actual information.
     *
     * The placeholders {icon} and {color_class} stay empty: the columns do not exist
     * (see class comment).
     */
    private function _editHeadline() {
        if (in_array($this->function, ['delete', 'info'])) {
            $labelText = $this->cms->textOrSnippet($this->data['label']);
            $iconHtml = $this->backend->template('language.icon_language');
            $labelHtml = $iconHtml . ' ' . $labelText;
        }
        else {
            $labelHtml = $this->cms->snippet('language.headline_language_info');
        }
        return $this->backend->template('headline1', [
            '{text}' => $labelHtml
        ]);
    }

    /**
     * function=save: save. $fields empty = all schema fields from $data.
     */
    private function _editSave(): void
    {
        // Check data and save
        $fields= [];
        if ($this->language->save($fields, $this->data)) {
            // Balloon Message
            $msg = $this->cms->snippet('language.message_language_saved');
            $this->backend->balloon('success', $msg);

            // Refresh referer and close window
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
     * function=delete_confirm: delete - the contents of the language are deleted by
     * Language::hookOnDelete().
     */
    private function _editDeleteConfirm(): void
    {
        if ($this->language->delete($this->id)) {
            // Balloon Message
            $msg = $this->cms->snippet('language.message_language_deleted');
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

    private function _editErrors(): void
    {
        echo $this->backend->template('alert_danger',[
            '{text}' => $this->cms->errorMsg()
        ]);
    }

    /**
     * The input form.
     *
     * Three simple fields and the position. For the position, the editor chooses the
     * PREDECESSOR; the option key is therefore its position + 1, i.e. the
     * target position. The entry "at the beginning" has the key 5, because
     * positions 1 to 4 are reserved for the bundled languages - for the same
     * reason the query only lists languages from language_id 4 on.
     */
    private function _editForm(): void
    {
        if ($this->function == 'save') $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Language/language_edit.php" method="post"' . $changed . '>';

        // Load Form
        $this->backend->formInit('language.edit_form');

        // Data fields
        $this->backend->formField($this->language, ['name' => 'label', 'value' => $this->data['label'] ?? '', 'id' => $this->id, 'primary' => true]);
        $this->backend->formField($this->language, ['name' => 'code', 'value' => $this->data['code'] ?? '', 'id' => $this->id]);
        $this->backend->formField($this->language, ['name' => 'endonym', 'value' => $this->data['endonym'] ?? '', 'id' => $this->id]);

        // backend_code: backend language of the snippets (Cms::backendLanguages())
        // Default for a new language: from the language part of the code
        // (de_AT -> de_DE), if there are snippets for it; an earlier code
        // like "de" appears as "de_DE"
        $backendCode = (string)($this->data['backend_code'] ?? '');
        if ($this->id < 1 && $backendCode === '' && (string)($this->data['code'] ?? '') !== '') {
            $backendCode = $this->cms->resolveBackendLang((string)$this->data['code']);
        }
        elseif ($backendCode !== '') {
            $backendCode = $this->cms->resolveBackendLang($backendCode);
        }
        $this->backend->formField($this->language, [
            'name' => 'backend_code',
            'value' => $backendCode,
            'id' => $this->id,
            'options' => $this->backend->backendLanguageOptions(),
            'selectPlease' => true,
        ]);

        // timezone: empty = default from the configuration (system.timezone)
        $defaultLabel = str_replace('{timezone}', $this->cms->serverTZ, $this->cms->snippet('language.option_timezone_default'));
        $timezoneOptions = ['' => $defaultLabel] + $this->backend->timezoneOptions();
        $this->backend->formField($this->language, [
            'name' => 'timezone',
            'value' => $this->data['timezone'] ?? '',
            'id' => $this->id,
            'options' => $timezoneOptions,
        ]);
        
        // position
        // --------------------
        // Options
        $options = [5 => $this->cms->snippet('position_at_start')];
        $this->db->query(
            "SELECT * FROM `language` WHERE `language_id` !=? ORDER BY `position` ASC",
            'i',
            [$this->id]
        );
        while ($option = $this->db->fetch()) {
            $position = (int)$option['position'] + 1;
            $options[$position] = $this->cms->textOrSnippet($option['label']);
        }
        $this->backend->formField($this->language, [
            'id' => $this->id,
            'name' => 'position',
            'value' => $this->data['position'],
            'options' => $options,
        ]);

        // function
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $this->id);

        // Print form
        echo $this->backend->form('form_save_cancel_note');

        echo '</form>';
    }

    /**
     * function=delete: deletion confirmation with the instances that use the language.
     *
     * If it is the main language of an instance, there is no delete button - first choose
     * another main language there (Language::hookOnDelete() rejects it anyway).
     */
    private function _editDeleteForm(): void
    {
        $usage = $this->language->usage($this->id);
        $list = fn(array $instances) => '<ul class="mt-1">' . implode('', array_map(
            fn(int $id, string $label) => '<li>' . htmlspecialchars($label) . ' <span class="c9-grey-50">(#' . $id . ')</span></li>',
            array_keys($instances), $instances
        )) . '</ul>';

        if (!empty($usage['primary'])) {
            echo $this->backend->template('alert_danger', [
                '{text}' => '{#language.alert_language_primary}' . $list($usage['primary'])
            ]);
            echo $this->backend->template('button_close', ['{onclick}' => 'cApp.layers.close()']);
            return;
        }

        echo '<form action="Language/language_edit.php" method="post">';

        $instancesHtml = empty($usage['additional'])
            ? '<div class="mt-3">{#language.note_language_unused}</div>'
            : '<div class="mt-3">{#language.headline_delete_instances}</div>' . $list($usage['additional']);
        $this->backend->formInit('language.delete_form', [
            '{instances}' => $instancesHtml,
        ]);
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');

        // Print form
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }
}
