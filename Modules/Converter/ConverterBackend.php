<?php
/**
 * Backend UI for the media converters (table `converter`).
 *
 * Entry points (scripts in backend/):
 *
 *     converter_list.php     -> list()             converter list
 *     converter_info.php     -> info()             detail view
 *     converter_create.php   -> chooseProcessor()  step 1: choose processor
 *     converter_edit.php     -> edit()             step 2 and everything else
 *     converter_search.php   -> search()           search mask and search execution
 *
 * CREATING IN TWO STEPS
 * --------------------
 * As with the input fields: first the processor, then the form. The processor
 * determines which settings exist at all and cannot be changed afterwards, so
 * the choice is made in a separate step (chooseProcessor()),
 * grouped by media kind.
 *
 * THE PROCESSOR PROVIDES ITS PART OF THE FORM ITSELF
 * --------------------
 * This class only renders the shared fields (label, MIME filter, extension).
 * All processor-specific parts come from two files in the processor directory, which are
 * included in the middle of the method:
 *
 *     {processor}/backend/form.php   input fields  (in _editForm())
 *     {processor}/backend/info.php   detail display (in info())
 *
 * The include runs in the scope of the method, so the files access directly
 * $this->backend, $this->converter and $this->data. A new processor therefore needs
 * no change to this class - but it also gets full access to it.
 *
 * @see Camindo\Core\Modules\Converter\Converter  Dataset/model, extends its schema
 *                                                   with the fields of the processor
 * @see Camindo\Core\MediaProcessor                 base class of the processors
 * @see Camindo\Core\Modules\Area\FieldBackend     selects converters for thumbnail fields
 */
namespace Camindo\Core\Modules\Converter;

use Camindo\Core\Backend;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\Modules\Area\Field;
use Camindo\Core\Modules\Converter\Converter;

class ConverterBackend {
    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';
    protected string $processorName = '';

    public function __construct(
        private Backend $backend,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Converter $converter,
        private Field $field
    )
    {
    }

    /**
     * Converter list (backend/converter_list.php).
     *
     * Default sort 'processor ASC, label ASC' from Converter - so the converters
     * of the same processor are kept together.
     */
    public function list()
    {
        $id = (int)($_REQUEST['id'] ?? 0);

        // Page elements
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#converter.module_label');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#converter.headline_converter_list');
        
        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Converter/converter_create.php');");
        $this->backend->buttonAdd('button_search', "cApp.layers.add('Converter/converter_search.php?' + cApp.layers.queryString());");
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        // List
        // --------------------
        $f = $this->cms->select([
            'request' => 'all',
            'limit' => $this->cms->list_rows,
            'count' => 'true',
            'filtertext' => 'true',
        ], $this->converter);

        // Search terms or changed sorting as readable text
        echo $this->backend->filterText($f);

        if ($this->db->num_rows > 0) {

            // Pagination top
            echo $this->backend->paginationTop('converter.listed_records');

            $labels = [
                'label' => $this->cms->snippet('converter.field_label'),
                'processor' => $this->cms->snippet('converter.field_processor'),
                'type' => $this->cms->snippet('converter.field_type')
            ];

            // Prepare Table-Head
            $schema = $this->converter->getSchema();
            $thLabel = $this->backend->tableHeadColumn($schema['label'], $f, 'label');
            $thProcessor = $this->backend->tableHeadColumn($schema['processor'], $f, 'processor');
            $thType = $this->backend->tableHeadColumn($schema['type'], $f, 'type');
            
            // Render table head
            $headColumns = $this->backend->template('converter.converter_table_head_columns',[
                '{label_label}' => $thLabel,
                '{label_processor}' => $thProcessor,
                '{label_type}' => $thType,
            ]);

            // Collect table rows
            $tplTableRow = $this->backend->template('converter.converter_table_row');
            $tableRows = '';
            
            // Get results (Dataset::fetch: with post-processing)
            while ($data = $this->converter->fetch()) {

                // Label | Processor | Type
                $tableRow = strtr($tplTableRow, [
                    '{data_label}' => $this->cms->textOrSnippet($data['label']),
                    '{data_processor}' => $data['processor'],
                    '{data_type}' => $data['type'] ? $this->cms->snippet('converter.processor_type_' . $data['type']) : 'other',
                    '{data_id}' => $data['converter_id'],
                ]) ."\n";

                $tableRows .= $tableRow;
            }

            // Render table
            echo $this->backend->template('converter.converter_table_with_head', [
                '{head_columns}' => $headColumns,
                '{table_rows}' => $tableRows
            ]);

            // Context menu
            echo $this->backend->template('converter.converter_context_menu');

            // Pagination bottom
            echo $this->backend->paginationBottom();
        }

        // Container Footer
        echo $this->backend->template('container_footer');

    }

    /**
     * Detailansicht eines Konverters (backend/converter_info.php).
     *
     * The MIME filters are entered line by line and shown here as a list;
     * htmlspecialchars() runs BEFORE splitting, because the finished HTML block is then passed
     * with the flag 'html' and infoAdd() no longer escapes it.
     *
     * At the end the method includes {processor}/backend/info.php - the processor
     * presents its own settings itself. The include runs in the
     * scope of this method, so the file continues to work with $data,
     * $this->backend and $this->converter.
     */
    public function info()
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $tab = (string)($_REQUEST['tab'] ?? '');

        // Page elements
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#button_info');

        // Container header
        echo $this->backend->template('container_header');

        // Load data
        if (!$data = $this->converter->load($id)) {
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->cms->errorMsg()
            ]);
            echo $this->backend->template('container_footer');
            return;
        }

        // H1
        echo $this->backend->headline($data['label']);
        
        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Converter/converter_edit.php?id={$id}');");
        $this->backend->buttonAdd('button_copy', "cApp.layers.replace('Converter/converter_edit.php?id={$id}&function=copy');");
        $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Converter/converter_edit.php?id={$id}&function=delete');");
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        echo $this->backend->buttonBar();

        // Tabs: Settings | Used by (n) - the thumbnail fields that use the
        // converter (`rel_field_converter`)
        $usedBy = $this->field->getFieldIdsByConverter($id);
        $back = !empty($_REQUEST['back']) ? '&back=true' : '';
        $this->backend->tabBarReset();
        $settingsActive = !in_array($tab, ['usage', 'audit'], true);
        $this->backend->tabAdd('#converter.tab_settings', $settingsActive ? '' : "cApp.layers.replace('Converter/converter_info.php?id={$id}{$back}');", $settingsActive);
        $this->backend->tabAdd(
            $this->cms->snippet('converter.tab_used_by') . ' (' . count($usedBy) . ')',
            $tab !== 'usage' ? "cApp.layers.replace('Converter/converter_info.php?id={$id}&tab=usage{$back}');" : '',
            $tab === 'usage'
        );
        // "Changes" (audit log, Backend::auditTabAdd())
        $this->backend->auditTabAdd("Converter/converter_info.php?id={$id}{$back}");
        echo $this->backend->tabBar();

        if ($this->backend->auditTabActive()) {
            echo $this->backend->auditHistory('converter', $id);
        }
        elseif ($tab === 'usage') {
            $this->_infoUsedBy($usedBy);
        }
        else {
            $this->_infoSettings($data);
        }
        echo $this->backend->template('container_footer');
    }

    /**
     * Search mask.
     *
     * Only the shared fields - processor-specific settings cannot be searched,
     * they are in the JSON column `details`.
     */
    public function searchForm():void
    {
        // Page parameters
        $filter = (array) ($_REQUEST['filter'] ?? []);

        echo '<form action="Converter/converter_search.php" method="post">';

        // Bread crum navi
        $this->backend->setNaviLabel('#button_search');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#converter.headline_search');

        // Search mask of the converter
        $this->backend->formInit('converter.converter_search_form');
        $this->backend->searchField($this->converter, ['name' => 'label', 'value' => $filter['label'] ?? '']);
        $this->backend->searchField($this->converter, ['name' => 'type', 'value' => $filter['type'] ?? '']);
        $this->backend->searchField($this->converter, ['name' => 'processor', 'value' => $filter['processor'] ?? '']);
        $this->backend->searchField($this->converter, ['name' => 'media_types_incl', 'value' => $filter['media_types_incl'] ?? '']);
        $this->backend->searchField($this->converter, ['name' => 'media_types_excl', 'value' => $filter['media_types_excl'] ?? '']);
        $this->backend->searchField($this->converter, ['name' => 'extension', 'value' => $filter['extension'] ?? '']);

        $sortOptions = [
            'processor' => $this->cms->snippet('converter.field_processor'),
            'label' => $this->cms->snippet('converter.field_label'),
            'extension' => $this->cms->snippet('converter.field_extension')
        ];
        $this->backend->searchFieldSortOrder($sortOptions);

        // function
        $this->backend->formHidden('function', 'search');

        // Print form
        echo $this->backend->form('form_search_cancel');

        // Container footer
        echo $this->backend->template('container_footer');

        echo '</form>';
    }

    /**
     * function=delete: deletion confirmation.
     *
     * If thumbnail fields use the converter (`rel_field_converter`), this is shown as a
     * warning: the assignments are removed via foreign key, a field can then
     * be left without a converter and then no longer generates thumbnails.
     */
    private function _editDeleteForm(): void
    {
        echo '<form action="Converter/converter_edit.php" method="post">';

        $usedBy = $this->field->getFieldIdsByConverter($this->id);
        $this->backend->formInit('converter.converter_delete_form', [
            '{used_by}' => empty($usedBy) ? '' : $this->backend->template('alert_warning', [
                '{text}' => str_replace('{count}', (string)count($usedBy), $this->cms->snippet('converter.alert_converter_used_by')),
            ]),
        ]);

        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');

        // Print form
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }

    /**
     * Run search.
     *
     * As in the other modules: no own result list, but close the search layer
     * and reload the list layer with the parameters in the URL.
     * json_encode escapes everything that could end the JavaScript literal;
     * substr(..., 1) only removes the opening quotation mark, the closing one
     * ends the string below.
     */
    public function search():void
    {
        // Parameters from POST
        $queryString = http_build_query($_POST);

        // Wrap safely for JavaScript
        $safeParams = json_encode($queryString, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $safeUri = '"Converter/index.php?' . substr($safeParams, 1);

        // Reload referer layer with these parameters
        $this->backend->script("
            cApp.layers.close();
            cApp.layers.replace({$safeUri});
        ");
    }

    /**
     * Create / copy / change / delete (backend/converter_edit.php).
     *
     * State machine via $_REQUEST['function'] as in the other modules
     * (developer docs, chapter 05 (Backend development)).
     *
     * The processor name is taken from data[processor] right at the start and
     * cleaned: a directory path (for the include of the
     * processor form file) and a class name are built from it. When editing,
     * _editLoad() overwrites the value with the stored one - the request only supplies it when
     * creating, directly from chooseProcessor().
     */
    public function edit(): void
    {
        // Page parameters
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = (string)($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array)($_REQUEST['data'] ?? []);
        $this->data['converter_id'] = $this->id;
        $this->processorName = $this->converter->cleanProcessorName($this->data['processor']);

        // Bread crum navi
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

        // Save
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

    /**
     * Step 1 when creating: choose processor (backend/converter_create.php).
     *
     * Shows the installed processors grouped by media kind. For each kind the
     * form template has a placeholder {type_image_links}, {type_audio_links}, … which
     * is filled here with the links; a kind without an installed processor gets
     * an empty block instead of the list.
     *
     * Each link replaces the layer with converter_edit.php using function=create and the
     * chosen processor. The list comes from var/config/components/processors.php,
     * not from the database (see Converter).
     */
    public function chooseProcessor():void {

        echo '<form action="Converter/converter_edit.php" method="post">';

        // Bread crum navi
        $this->backend->setNaviLabel('#converter.button_create');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#converter.headline_converter_create');

        // form
        $this->backend->formInit('converter.converter_create_form');

        $tplLink = $this->backend->template('converter.converter_create_link');
        foreach ($this->converter->processorTypes as $type) {
            $processorsByType = $this->converter->getProcessorsByType($type);
            $links = [];
            foreach ($processorsByType as $processorName => $settings) {
                $links[] = strtr($tplLink, [
                    '{processor}' => $processorName,
                    '{label}' => $this->cms->textOrSnippet($settings['label'] ?? $processorName),
                    '{description}' => $this->cms->textOrSnippet($settings['description'] ?? ''),
                ]);
            }
            $linksHtml = implode('', $links) ?: $this->backend->template('converter.processor_empty_block');
            $this->backend->formReplace('{type_' . $type . '_links}', $linksHtml);
        }

        $this->backend->formReplace(
            '{link_prefix}',
            "cApp.layers.replace('Converter/converter_edit.php?&function=create"
        );
        $this->backend->formReplace(
            '{link_suffix}',
            "');"
        );
        
        echo $this->backend->form();

        echo $this->backend->template('container_footer');

        echo '</form>';

    }

    // ====================
    // edit() helper functions

    /**
     * function=create: prefill the form with the defaults.
     *
     * Converter::init() requires the processor as a default - only with it is the
     * schema complete and can it supply defaults for its fields.
     */
    private function _editCreate(): void
    {
        if (!$this->data = $this->converter->init(['processor' => $this->processorName])) {
            $this->function = 'skip';
        };
        $this->id = 0;
    }

    /**
     * function=edit|copy|delete: Konverter laden.
     *
     * The stored processor name takes precedence over the one from the request - the processor
     * of an existing converter cannot be changed.
     */
    private function _editLoad(): void
    {
        if ($this->data = $this->converter->load($this->id)) {
            $this->processorName = $this->converter->cleanProcessorName($this->data['processor']);
        }
        else {
            // Requested record not found
            $this->function = 'skip';
        }

        // If data is to be copied, reset the ID now
        if ($this->function == 'copy') {
            $this->id = 0;
        }
    }

    /**
     * Heading of the form. When deleting, the label of the converter, otherwise
     * the general heading.
     */
    private function _editHeadline() {
        if ($this->function == 'delete') {
            $label = $this->data['label'] ?? '';
        }
        else {
            $label = $this->cms->snippet('converter.headline_converter_info');
        }
        $icon = $this->backend->template('converter.icon_converter');
        return $this->backend->template('headline1', [
            '{text}' => $icon . ' ' . $label
        ]);
    }

    /**
     * function=save: speichern.
     *
     * $fields empty means: all schema fields that are in $data - and after loading
     * the processor, this includes its own fields, which end up in the JSON column
     * `details`. A fixed field list would therefore not work here.
     */
    private function _editSave(): void
    {
        $fields = [];
        if ($this->converter->save($fields, $this->data)) {
            // Balloon Message
            $msg = $this->cms->snippet('converter.message_converter_saved');
            $this->backend->balloon('success', $msg);

            // Update referer and close window
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
     * function=delete_confirm: delete.
     */
    private function _editDeleteConfirm(): void
    {
        if ($this->converter->delete($this->id)) {
            // Balloon Message
            $msg = $this->cms->snippet('converter.message_converter_deleted');
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
     * Output error messages from Cms::errors as a red box.
     */
    private function _editErrors(): void
    {
        echo $this->backend->template('alert_danger',[
            '{text}' => $this->cms->errorMsg()
        ]);
    }

    /**
     * The input form.
     *
     * Split in two: first the shared fields from the converter schema, then via
     * include the input fields of the processor from its backend/form.php. The
     * included file runs in the scope of this method and makes the same
     * $this->backend->formField() calls there; its values end up via the
     * extended schema in the JSON column `details`.
     *
     * The processor itself is not selectable, only shown as text and a
     * hidden field in the form - it was set in chooseProcessor().
     */
    private function _editForm(): void
    {
        if ($this->function == 'save') $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Converter/converter_edit.php" method="post"' . $changed . '>';

        $this->backend->formInit('converter.converter_edit_form');

        // processor name
        $this->backend->formReplace('{data_processor}', $this->processorName);
        $this->backend->formHidden('data[processor]', $this->processorName);

        // converter data fields
        $this->backend->formField($this->converter, ['name' => 'label', 'value' => $this->data['label'] ?? '', 'id' => $this->id, 'primary' => true]);
        $this->backend->formField($this->converter, ['name' => 'media_types_incl', 'value' => $this->data['media_types_incl'] ?? '', 'id' => $this->id]);
        $this->backend->formField($this->converter, ['name' => 'media_types_excl', 'value' => $this->data['media_types_excl'] ?? '', 'id' => $this->id]);
        $this->backend->formField($this->converter, ['name' => 'extension', 'value' => $this->data['extension'] ?? '', 'id' => $this->id]);

        // processor data fields
        $processorDir = $this->converter->getProcessorDir();
        include $processorDir . '/backend/form.php';

        // {processor_note}: placeholder for a note of the processor below the
        // media types - e.g. formats that only run via an external delegate
        // (Magick: SVG, PDF). If the processor's form.php does not fill it,
        // it is simply dropped.
        $this->backend->formReplace('{processor_note}', '');

        // function, id
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $this->id);

        // Print form
        echo $this->backend->form('form_save_cancel_note');

        $this->backend->script("cApp.ui._initTextareaGrow(\$('.c9-layer:last'));");

        echo '</form>';
    }

    /**
     * Info page, tab "Used by": for each thumbnail field a teaser (linked to the
     * field info) with the area behind it. Without use, a note.
     *
     * @param array $fieldIds  Field::getFieldIdsByConverter()
     */
    private function _infoUsedBy(array $fieldIds): void
    {
        if (empty($fieldIds)) {
            echo $this->backend->template('converter.note_not_used');
            return;
        }
        $items = '';
        foreach ($fieldIds as $fieldId) {
            $areaId = (int)($this->field->load($fieldId)['area_id'] ?? 0);
            $items .= '<li>' . $this->backend->teaser('field', $fieldId, linked: true)
                . ($areaId > 0 ? ' ' . $this->backend->template('text-muted', ['{text}' => '(' . $this->backend->protect($this->backend->teaser('area', $areaId, linked: false)) . ')']) : '')
                . "</li>\n";
        }
        echo $this->backend->template('ul', ['{items}' => $items]);
    }

    /**
     * Info page, tab "Settings"
     */
    private function _infoSettings(array $data): void
    {
        // Converter data
        $this->backend->infoAdd($this->converter, 'label', $data['label'], ['primary']);
        $this->backend->infoAdd($this->converter, 'processor', $data['processor']);
        $this->backend->infoAdd($this->converter, 'type', $this->cms->snippet('converter.processor_type_' . $data['type']));
        
        // media_types_incl
        if (!empty($data['media_types_incl'])) {
            $mimeTypesIncl = explode("\n", htmlspecialchars($data['media_types_incl']));
            $mimeTypesInclHtml = $this->backend->template('ul', [
                '{items}' => '<li>' . implode ("</li>\n<li>", $mimeTypesIncl) . "</li>\n"
            ]);
            $flags = ['html'];
        }
        else {
            $mimeTypesInclHtml = '';
            $flags = [];
        }
        $this->backend->infoAdd($this->converter, 'media_types_incl', $mimeTypesInclHtml, $flags);
        
        // media_types_excl
        if (!empty($data['media_types_excl'])) {
            $mimeTypesExcl = explode("\n", htmlspecialchars($data['media_types_excl']));
            $mimeTypesExclHtml = $this->backend->template('ul', [
                '{items}' => '<li>' . implode ("</li>\n<li>", $mimeTypesExcl) . "</li>\n"
            ]);
            $flags = ['html'];
        }
        else {
            $mimeTypesExclHtml = '';
            $flags = [];
        }
        $this->backend->infoAdd($this->converter, 'media_types_excl', $mimeTypesExclHtml, $flags);

        // extension
        $this->backend->infoAdd($this->converter, 'extension', $data['extension']);

        // Processor data
        include $this->converter->getProcessorDir() . '/backend/info.php';

        echo $this->backend->infoTable();
    }

    /**
     * Info page, tab "Used by..."
     */
    private function _infoUsage(array $data): void
    {
        // List all thumbnail fields that use the converter

    }
}
