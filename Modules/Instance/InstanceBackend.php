<?php
/**
 * Backend UI for the instances - the individual websites (table `instance`).
 *
 * Entry points (scripts in backend/):
 *
 *     instance_list.php  -> list()   instance list
 *     instance_info.php  -> info()   detail view
 *     instance_edit.php  -> edit()   create / copy / change / delete
 *
 * THE DEFAULT INSTANCE
 * --------------------
 * Instance::DEFAULT_ID is the catch-all for everything that matches no configured domain.
 * It can be changed like any other - only two things are fixed:
 *
 *     the additional domains are always ".*" (instead of the list of lines there is a
 *     note, see _editForm())
 *     it is always at the end of the list and cannot be selected as predecessor of an
 *     other instance - after all, it catches what the others do not
 *     cover
 *
 * The backend offers no copying or deleting for it; Instance::hookOnDelete()
 * additionally rejects a deletion.
 *
 * DELETION
 * --------------------
 * `instance` has no `deleted` field - there is no trash, Dataset::delete()
 * deletes immediately and permanently. And with the instance its entire content goes:
 * content areas, their input fields, records and templates
 * (Instance::hookOnDelete()). _editDeleteForm() therefore shows the quantities beforehand.
 *
 * @see Camindo\Core\Modules\Instance\Instance           Dataset/Model
 * @see Camindo\Core\Modules\Area\AreaBackend            where the container is assigned
 * @see Camindo\Core\Modules\Language\LanguageBackend    the template of this class
 */
namespace Camindo\Core\Modules\Instance;

use Camindo\Core\Backend;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\SecurityHeaders;
use Camindo\Core\Modules\Instance\Instance;
use Camindo\Core\Modules\Record\RecordBackendEdit;
use Camindo\Core\Modules\Template\TemplateBackend;

class InstanceBackend {

    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';

    public function __construct(
        private Backend $backend,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Instance $instance,
        // Both only for the lookup fields of the start page: they render the
        // preview block of their own table (see _editFormStartPage()).
        private RecordBackendEdit $recordBackend,
        private TemplateBackend $templateBackend,
        private Config $config,
        private SecurityHeaders $securityHeaders,
    )
    {}

    /**
     * Instance list (backend/instance_list.php).
     *
     * limit -1: no pagination, all instances on one page. Sorted by
     * `position`.
     *
     * The default instance is in the list too, but without context menu and without
     * a click on the detail view - there is nothing to do with it.
     */
    public function list(): void
    {
        // Page elements
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#instance.module_label');

        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#instance.headline_instance_list');

        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Instance/instance_edit.php?function=create');");
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        // Instance list
        // --------------------
        $this->cms->select([
            'request' => 'all',
            'limit' => -1,
            'filtertext' => 'true'
        ], $this->instance);

        echo $this->backend->paginationTopShort();

        if ($this->db->num_rows > 0) {

            // Get languages once - one query per row would be wasteful
            $languages = $this->_languageOptions();

            // Table-Head
            $headColumns = $this->backend->template('instance.table_head_columns');

            // Table-Rows
            $tableRows = '';

            // Get results (Dataset::fetch: with post-processing)
            $tableRowLast = ''; // Default instance
            while ($data = $this->instance->fetch()) {
                $instanceId = (int)$data['instance_id'];
                $labelText = $this->cms->textOrSnippet($data['label']);
                $iconHtml = $this->backend->template('instance.icon_instance');
                $languageId = (int)$data['primary_language_id'];

                $tableRow = $this->backend->template('instance.table_row', [
                    '{data_label}' => $iconHtml . ' ' . $labelText,
                    '{data_domain}' => htmlspecialchars((string)$data['primary_domain']),
                    '{data_language}' => htmlspecialchars($languages[$languageId] ?? ''),
                    '{data_id}' => $instanceId
                ]);
                // {name} is not in the template but in the language snippet
                // ('Instance "{name}"' as aria-label) - that is inserted by Backend::template()
                // only AFTER its own replacements.
                $tableRow = strtr($tableRow, ['{name}' => $labelText]) . "\n";
                if ($instanceId === Instance::DEFAULT_ID) {
                    $tableRowLast = $tableRow;
                }
                else {
                    $tableRows .= $tableRow;
                }
            }
            $tableRows .= $tableRowLast;

            // Render table
            echo $this->backend->template('instance.table_with_head', [
                '{head_columns}' => $headColumns,
                '{table_rows}' => $tableRows
            ]);

            // Context menu
            echo $this->backend->template('instance.context_menu');
        }

        // Container Footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Detail view of an instance (backend/instance_info.php).
     *
     * Edit, copy and delete do not appear for the default instance
     * (see class comment).
     */
    public function info(): void
    {
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = 'info';

        // Bread crum navi
        $this->backend->setNaviLabel('#button_info');

        // Container header
        echo $this->backend->template('container_header');

        // Load instance data
        // --------------------
        if (!$this->data = $this->instance->load($this->id)) {
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
        $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Instance/instance_edit.php?id={$this->id}');");
        if ($this->id !== Instance::DEFAULT_ID) {
            $this->backend->buttonAdd('button_copy', "cApp.layers.replace('Instance/instance_edit.php?id={$this->id}&function=copy');");
            $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Instance/instance_edit.php?id={$this->id}&function=delete');");
        }
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        echo $this->backend->buttonBar();

        // Tabs: Info | Changes (audit log, Backend::infoAuditTabs())
        if ($this->backend->infoAuditTabs("Instance/instance_info.php?id={$this->id}", 'instance', $this->id)) return;

        // Data - in the sections of the input mask (edit_form.html)
        // --------------------
        $languages = $this->_languageOptions();
        $instances = $this->_instanceOptions();
        $details = is_array($this->data['details'] ?? null) ? $this->data['details'] : (json_decode((string)($this->data['details'] ?? ''), true) ?: []);
        $yesNo = fn($value) => $this->cms->snippet(!empty($value) ? 'yes' : 'no');
        $section = function (string $headline) {
            echo $this->backend->infoTable();
            $this->backend->infoReset();
            echo $this->backend->template('headline4_mt', ['{text}' => $this->cms->snippet($headline)]);
        };

        $this->backend->infoReset();
        $this->backend->infoAdd($this->instance, 'label', $this->cms->textOrSnippet($this->data['label']), ['primary']);
        $this->backend->infoAdd($this->instance, 'position', $this->_positionLabel());

        // Languages
        $section('instance.headline_languages');
        $this->backend->infoAdd(
            $this->instance,
            'primary_language_id',
            $languages[(int)$this->data['primary_language_id']] ?? ''
        );
        // Translate the language IDs into readable names (backendValue outputs the list)
        $this->backend->infoAdd(
            $this->instance,
            'additional_languages',
            $this->_translateIds($this->data['additional_languages'], $languages)
        );

        // Domains
        $section('instance.headline_domains');
        $this->backend->infoAdd($this->instance, 'primary_domain', (string)($this->data['primary_domain'] ?? ''));
        $this->backend->infoAdd($this->instance, 'protocol', (string)($this->data['protocol'] ?? ''));
        $this->backend->infoAdd($this->instance, 'canonical', $yesNo($this->data['canonical'] ?? 0));
        if ($this->id === Instance::DEFAULT_ID) {
            // Default instance - always ".*" (as in the form)
            $this->backend->infoAdd($this->instance, 'additional_domains', $this->backend->template('instance.all_other_domains'), ['html']);
        }
        else {
            // one row per domain; regular expressions marked "RegEx"
            $regex = array_values((array)($this->data['regex'] ?? []));
            $domains = [];
            foreach (array_values((array)($this->data['domain'] ?? [])) as $i => $domain) {
                $domains[] = (string)$domain . (!empty($regex[$i]) ? ' (' . $this->cms->snippet('instance.field_regex') . ')' : '');
            }
            $this->backend->infoAdd($this->instance, 'additional_domains', $domains);
        }

        // Access
        $section('instance.headline_access');
        $this->backend->infoAdd(
            $this->instance,
            'readable_instances',
            $this->_translateIds($this->data['readable_instances'], $instances)
        );

        // Script policy of the frontend (developer docs, chapter 14 (Security: web)) - without an own value the default
        // of the configuration, as in the choice of the mask
        $section('instance.headline_script_policy');
        $mode = $this->cms->snippet('instance.option_csp_' . $this->securityHeaders->frontendCspMode(['details' => $details]));
        if ((string)($details['csp'] ?? '') === '') {
            $mode = str_replace('{mode}', $mode, $this->cms->snippet('instance.option_csp_default'));
        }
        $this->backend->infoAdd($this->instance, 'details.csp', $mode);
        $this->backend->infoAdd($this->instance, 'details.csp_script_sources', (string)($details['csp_script_sources'] ?? ''));
        $this->backend->infoAdd($this->instance, 'details.csp_strict_dynamic', $yesNo($details['csp_strict_dynamic'] ?? 0));
        $this->backend->infoAdd($this->instance, 'details.csp_form_action', $yesNo($details['csp_form_action'] ?? 0));
        if (!empty($details['csp_form_action'])) {
            $this->backend->infoAdd($this->instance, 'details.csp_form_sources', "'self' " . str_replace("\n", ' ', (string)($details['csp_form_sources'] ?? '')));
        }

        // Start page
        $section('instance.headline_start_page');
        $recordId = (int)($this->data['record_id'] ?? 0);
        $templateId = (int)($this->data['template_id'] ?? 0);
        $html = $recordId > 0 ? $this->backend->teaser('record', $recordId) : '';
        $this->backend->infoAdd($this->instance, 'record_id', $html, $html !== '' ? ['html'] : []);
        $html = $templateId > 0 ? $this->backend->teaser('template', $templateId) : '';
        $this->backend->infoAdd($this->instance, 'template_id', $html, $html !== '' ? ['html'] : []);

        echo $this->backend->infoTable();

        echo $this->backend->template('container_footer');
    }

    /**
     * Create / copy / change / delete (backend/instance_edit.php).
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
        $this->data['instance_id'] = $this->id;

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

        if ($this->function == 'save') {
            $this->_editSave();
        }

        // Delete confirmation
        if ($this->function == 'delete_confirm') {
            $this->_editDeleteConfirm();
        }

        // Errors
        if (in_array($this->function, ['errors', 'skip'], true)) {
            $this->_editErrors();
        }

        // Edit form
        if (in_array($this->function, ['create', 'edit', 'copy', 'errors', 'confirm'], true)) {
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
        $this->data = $this->instance->init();
        $this->id = 0;
        // primary language: en_US preselected - otherwise the browser takes the first
        // option of the list (sorted by position, e.g. ar_SA)
        $this->db->pushResult();
        $this->db->query("SELECT `language_id` FROM `language` WHERE `code` = 'en_US' LIMIT 1");
        $languageId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        if ($languageId > 0) $this->data['primary_language_id'] = $languageId;
    }

    /**
     * function=edit|copy|delete: load the instance.
     *
     * When copying, the ID is discarded (save() thereby creates a new record)
     * and the position is raised by one, so that the copy stands behind the original.
     * The start page is NOT copied along: record and template belong to the
     * contents of the template, not to the new website.
     */
    private function _editLoad(): void
    {
        $this->data = $this->instance->load($this->id);
        if (!$this->data) {
            // Requested record not found
            $this->function = 'skip';
            $this->data = [];
            return;
        }
        // If data is to be copied, reset the ID now
        if ($this->function == 'copy') {
            $this->id = 0;
            $this->data['instance_id'] = 0;
            $this->data['position']++;
            // Domains are unique per website - the copy starts without them
            $this->data['primary_domain'] = '';
            $this->data['domain'] = [];
            $this->data['regex'] = [];
            $this->data['additional_domains'] = [];
            // Start page belongs to the contents of the template
            $this->data['record_id'] = 0;
            $this->data['template_id'] = 0;
        }
    }

    /**
     * Heading for form and detail view. When deleting and in the
     * detail view the instance itself stands above it, otherwise the general
     * heading.
     */
    private function _editHeadline(): string
    {
        if (in_array($this->function, ['delete', 'info'], true)) {
            $labelText = $this->cms->textOrSnippet($this->data['label'] ?? '');
            $labelHtml = $this->backend->template('instance.icon_instance') . ' ' . $labelText;
        }
        else {
            // 'errors'/'skip' are follow-up states of 'save' resp. of a failed
            // loading - they carry no heading of their own
            $headlines = [
                'create' => 'instance.headline_instance_create',
                'copy'   => 'instance.headline_instance_copy',
                'edit'   => 'instance.headline_instance_edit',
            ];
            $labelHtml = $this->cms->snippet(
                $headlines[$this->function] ?? 'instance.headline_instance'
            );
        }
        return $this->backend->template('headline1', [
            '{text}' => $labelHtml
        ]);
    }

    /**
     * function=save: save. $fields empty = all schema fields from $data.
     *
     * If languages drop out, their contents disappear (LanguageData::removeLanguage()).
     * This happens only after an intermediate step: the form appears once more
     * with the warning (function=confirm) and carries the confirmed languages in the
     * hidden field `confirm_remove_languages`; only when exactly these languages drop out
     * is it saved.
     */
    private function _editSave(): void
    {
        // Check data and save
        $fields = [];
        $this->data['instance_id'] = $this->id;

        $removed = $this->_removedLanguages();
        if (!empty($removed)
            && (string)($_REQUEST['confirm_remove_languages'] ?? '') !== implode(',', array_keys($removed))
        ) {
            $this->db->query(
                "SELECT COUNT(*) FROM `record` WHERE `instance_id` = ? AND `tmp` = 0 AND `deleted` = 0",
                'i', [$this->id]
            );
            $count = (int)($this->db->fetchNum()[0] ?? 0);
            echo $this->backend->template('alert_warning', [
                '{text}' => '<strong>{#instance.alert_remove_languages}</strong>'
                    . '<ul class="mt-1 mb-1"><li>' . implode('</li><li>', array_map('htmlspecialchars', $removed)) . '</li></ul>'
                    . str_replace('{count}', (string)$count, $this->cms->snippet('instance.alert_remove_languages_note')),
            ]);
            $this->function = 'confirm';
            return;
        }

        if ($this->instance->save($fields, $this->data)) {
            // Balloon Message
            $msg = $this->cms->snippet('instance.message_instance_saved');
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
     *
     * Final and irrevocable, including all contents of the website - see
     * Instance::hookOnDelete().
     */
    private function _editDeleteConfirm(): void
    {
        if ($this->instance->delete($this->id)) {
            // Balloon Message
            $msg = $this->cms->snippet('instance.message_instance_deleted');
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
     * For the position, the editor chooses the PREDECESSOR; the option key is
     * its position + 1.
     * The "Default" instance is always at the end, since it catches all domains
     * that the others do not cover.
     *
     * The further domains are no regular form field: for each domain there is a
     * row with two inputs (data[domain][] and data[regex][]) in the form. They
     * are inserted here via the placeholder {domain_items}, which each row
     * re-appends after itself - so the order is kept. Moving, adding and removing
     * is handled by cApp.smartrows in the browser.
     */
    private function _editForm(): void
    {
        if (in_array($this->function, ['save', 'confirm'], true)) $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Instance/instance_edit.php" method="post"' . $changed . '>';

        // Load form
        $this->backend->formInit('instance.edit_form');

        // label
        // --------------------
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'label',
            'value' => $this->data['label'] ?? '',
            'primary' => true,
        ]);

        // position
        // --------------------
        // The editor chooses the predecessor; position + 1 of it is saved.
        // The default instance is always at the end regardless of its position (0),
        // so it cannot be chosen as predecessor
        if ($this->id === 1) {
            $options = [1 => $this->cms->snippet('position_at_end')];
        }
        else {
            $options = [1 => $this->cms->snippet('position_at_start')];
            $this->db->query(
                "SELECT `instance_id`, `label`, `position` FROM `instance`"
                . " WHERE `instance_id` != ? AND `instance_id` != ?"
                . " ORDER BY `position` ASC",
                'ii',
                [$this->id, Instance::DEFAULT_ID]
            );
            while ($option = $this->db->fetch()) {
                $position = (int)$option['position'] + 1;
                $options[$position] = $this->cms->textOrSnippet($option['label']);
            }
        }
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'position',
            'value' => $this->data['position'] ?? 0,
            'options' => $options,
        ]);

        // languages
        // --------------------
        $languageOptions = $this->_languageOptions();

        // primary_language_id
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'primary_language_id',
            'value' => $this->data['primary_language_id'] ?? 0,
            'options' => $languageOptions,
        ]);

        // additional_languages (display_type 'sortable')
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'additional_languages',
            'value' => (array)($this->data['additional_languages'] ?? []),
            'options' => $languageOptions,
        ]);

        // domains
        // --------------------

        // primary_domain
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'primary_domain',
            'value' => $this->data['primary_domain'] ?? '',
        ]);

        // protocol
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'protocol',
            'value' => $this->data['protocol'] ?? 'https',
            'options' => ['https' => 'https', 'http' => 'http'],
        ]);

        // canonical
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'canonical',
            'value' => $this->data['canonical'] ?? 1,
        ]);

        // additional domains
        if ($this->id === Instance::DEFAULT_ID) {
            // Default instance - always ".*"
            $snippetAllDomains = $this->backend->template('instance.all_other_domains');
            $this->backend->formStatic($this->instance, 'additional_domains', $snippetAllDomains);
        }
        else {
            // all other instances allow several values with domain and flag "regex"
            $this->backend->formReplace('{data_additional_domains}',
                $this->backend->template('instance.edit_form_domain_list')
            );
            $domains = (array)($this->data['domain'] ?? []);
            $regex   = (array)($this->data['regex'] ?? []);
            foreach (array_values($domains) as $i => $domain) {
                $tplDomainTableRow = $this->backend->template('instance.edit_form_domain_item', [
                    '{domain}' => htmlspecialchars((string)$domain),
                    '{regex_checked}' => !empty($regex[$i]) ? ' checked' : ''
                ]);
                $this->backend->formReplace('{domain_items}', $tplDomainTableRow . "\n" . '{domain_items}');
            }
            // Last row stays empty and can be filled right away.
            $tplDomainTableRow = $this->backend->template('instance.edit_form_domain_item', [
                '{domain}' => '',
                '{regex_checked}' => ''
            ]);
            $this->backend->formReplace('{domain_items}', $tplDomainTableRow);
        }

        // access
        // --------------------

        // readable_instances
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'readable_instances',
            'value' => (array)($this->data['readable_instances'] ?? []),
            'options' => $this->_instanceOptions(),
        ]);

        // Script policy of the frontend (developer docs, chapter 14 (Security: web))
        // --------------------
        $details = is_array($this->data['details'] ?? null) ? $this->data['details'] : (json_decode((string)($this->data['details'] ?? ''), true) ?: []);
        $default = (string)($this->config->get('security.frontend_csp') ?? 'off');
        $cspOptions = ['' => str_replace('{mode}', $this->cms->snippet('instance.option_csp_' . (in_array($default, SecurityHeaders::FRONTEND_MODES, true) ? $default : 'off')), $this->cms->snippet('instance.option_csp_default'))];
        foreach (SecurityHeaders::FRONTEND_MODES as $mode) {
            $cspOptions[$mode] = $this->cms->snippet('instance.option_csp_' . $mode);
        }
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'details.csp',
            'value' => (string)($details['csp'] ?? ''),
            'options' => $cspOptions,
        ]);
        foreach (['csp_script_sources', 'csp_strict_dynamic', 'csp_form_action', 'csp_form_sources'] as $key) {
            $this->backend->formField($this->instance, [
                'id' => $this->id,
                'name' => 'details.' . $key,
                'value' => $details[$key] ?? '',
            ]);
        }

        // start page
        // --------------------
        // Two lookup fields on FOREIGN tables (`record` and `template`) - same
        // procedure as with the default template of an area (AreaBackend). The
        // selection comes from the respective hit list in the next layer; the
        // preview block carries the hidden field that goes along with the form.
        //
        // Both stay limited to the contents of THIS website:
        //     records   via filter[instance_id]
        //     templates via filter[area_id][] - the template containers of the instance
        $this->_editFormStartPage();

        // function
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $this->id);
        if ($this->function === 'confirm') {
            $this->backend->formHidden('confirm_remove_languages', implode(',', array_keys($this->_removedLanguages())));
        }

        // Print form
        echo $this->backend->form('form_save_cancel_note');

        echo '</form>';
    }

    /**
     * function=delete: deletion confirmation.
     *
     * The contents of the website disappear with it. Therefore the form shows
     * an overview of what is lost.
     */
    private function _editDeleteForm(): void
    {
        $count = $this->instance->contentCount($this->id);

        echo '<form action="Instance/instance_edit.php" method="post">';

        $this->backend->formInit('instance.delete_form', [
            '{count_areas}' => (string)$count['areas'],
            '{count_fields}' => (string)$count['fields'],
            '{count_records}' => (string)$count['records'],
            '{count_templates}' => (string)$count['templates'],
        ]);

        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');

        // Print form
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }

    /**
     * Languages that drop out of the instance when saving: previous main and
     * further languages according to the database that no longer appear in the form.
     *
     * @return array  language_id => "code | label", sorted by language_id
     */
    private function _removedLanguages(): array
    {
        if ($this->id < 1) return [];
        $this->db->query(
            "SELECT `primary_language_id` FROM `instance` WHERE `instance_id` = ?"
            . " UNION SELECT `language_id` FROM `rel_instance_language` WHERE `instance_id` = ?",
            'ii', [$this->id, $this->id]
        );
        $old = array_map('intval', $this->db->fetchAll());
        $new = array_map('intval', array_merge(
            [$this->data['primary_language_id'] ?? 0],
            (array)($this->data['additional_languages'] ?? [])
        ));
        $removed = array_values(array_diff($old, $new));
        sort($removed);
        $options = $this->_languageOptions();
        $result = [];
        foreach ($removed as $languageId) {
            $result[$languageId] = $options[$languageId] ?? ('#' . $languageId);
        }
        return $result;
    }

    // ====================
    // Choice lists

    /**
     * Language versions as key-value pairs
     */
    private function _languageOptions(): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT `language_id`, `code`, `label` FROM `language` ORDER BY `position` ASC");
        $options = [];
        while ($option = $this->db->fetch()) {
            $options[(int)$option['language_id']] =
                $option['code'] . ' | ' . $this->cms->textOrSnippet($option['label']);
        }
        $this->db->popResult();
        return $options;
    }

    /**
     * Instances, for readable_instances
     * (readable other instances; the current one is therefore excluded)
     * default instance always at the end
     */
    private function _instanceOptions(): array
    {
        $sql = "SELECT `instance_id`, `label` FROM `instance`"
            . " WHERE `instance_id` != ? AND `instance_id` != ?"
            . " ORDER BY `position` ASC";
        $this->db->query($sql, 'ii', [$this->id, Instance::DEFAULT_ID]);
        $options = [];
        foreach ($this->db->fetchPairs() as $instanceId => $label) {
            $options[(int)$instanceId] = $this->cms->textOrSnippet($label);
        }
        if ($this->id > 1) $options[1] = 'Default';
        
        return $options;
    }

    /**
     * Set the two lookup fields of the start page in the form.
     *
     * Structure as with every lookup on a foreign table (see
     * AreaBackend::_editForm(), field default_template_id):
     *
     *     {search_url}   hit list from which one is chosen
     *     {update_url}   Ajax endpoint that renders the preview block again
     *     {data_ids}     currently chosen ID (cApp.ui keeps it in the data attribute)
     *     {preview}      the preview block including the hidden input field
     *
     * For an instance not yet saved ($id < 1) there are no contents
     * from which a start page could be chosen - the fields then appear as a
     * note instead of a choice.
     */
    private function _editFormStartPage(): void
    {
        if ($this->id < 1) {
            $note = $this->backend->template('instance.note_start_page_new');
            $this->backend->formStatic($this->instance, 'record_id', $note);
            $this->backend->formStatic($this->instance, 'template_id', $note);
            return;
        }

        // record_id
        // --------------------
        $recordId = (int)($this->data['record_id'] ?? 0);
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'record_id',
            'value' => $recordId,
            'template' => 'edit_form_lookup_foreign_table',
            // The context menu behind a lookup field works with the field_id of a
            // link field - there is none here (foreign table).
            'contextMenu' => '',
        ]);
        $urlSuffix = "src_field=record_id&filter[instance_id]={$this->id}";
        $this->backend->formReplace('{src_id}', (string)$this->id);
        $this->backend->formReplace('{src_field}', 'record_id');
        $this->backend->formReplace('{search_url}', "Record/record_list.php?" . $urlSuffix);
        $this->backend->formReplace('{update_url}', "Record/ajax_ext_lookup.php?" . $urlSuffix);
        $this->backend->formReplace('{data_ids}', $recordId > 0 ? (string)$recordId : '');
        $this->backend->formReplace('{max}', '1');
        $this->backend->formReplace(
            '{preview}',
            $this->recordBackend->extLookupPreviewCreate('record_id', [$recordId], 'data', 1)
        );

        // template_id
        // --------------------
        $templateId = (int)($this->data['template_id'] ?? 0);
        $this->backend->formField($this->instance, [
            'id' => $this->id,
            'name' => 'template_id',
            'value' => $templateId,
            'template' => 'edit_form_lookup_foreign_table',
            'contextMenu' => '',
        ]);
        // Only templates from the template containers of this website
        $areaFilter = '';
        foreach ($this->_templateAreaIds() as $areaId) {
            $areaFilter .= '&filter[area_id][]=' . $areaId;
        }
        if ($areaFilter === '') {
            // No template container - a search call without restriction would otherwise
            // show the templates of all websites. An unfulfillable area_id blocks the list.
            $areaFilter = '&filter[area_id][]=0';
        }
        $urlSuffix = "src_field=template_id" . $areaFilter;
        $this->backend->formReplace('{src_id}', (string)$this->id);
        $this->backend->formReplace('{src_field}', 'template_id');
        $this->backend->formReplace('{search_url}', "Template/template_list.php?" . $urlSuffix);
        $this->backend->formReplace('{update_url}', "Template/ajax_lookup_preview.php?src_field=template_id");
        $this->backend->formReplace('{data_ids}', $templateId > 0 ? (string)$templateId : '');
        $this->backend->formReplace('{max}', '1');
        $this->backend->formReplace(
            '{preview}',
            $this->templateBackend->lookupPreviewCreate('template_id', [$templateId], 'data', 1)
        );
    }

    /**
     * area_ids of the template containers of this website.
     */
    private function _templateAreaIds(): array
    {
        $sql = "SELECT `area_id` FROM `area`"
            . " WHERE `instance_id` = ? AND `type` = 'template' AND `deleted` = 0";
        $this->db->pushResult();
        $this->db->query($sql, 'i', [$this->id]);
        $ids = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
        return $ids;
    }

    /**
     * Position of an instance for the info page - like the choice "Position after..."
     * of the mask: label of the predecessor, "(at the beginning)" resp. for the
     * default instance "(at the end)".
     */
    private function _positionLabel(): string
    {
        if ($this->id === Instance::DEFAULT_ID) return $this->cms->snippet('position_at_end');
        $this->db->query(
            "SELECT `label` FROM `instance` WHERE `position` < ? AND `instance_id` != ? AND `instance_id` != ?"
            . " ORDER BY `position` DESC LIMIT 1",
            'iii',
            [(int)($this->data['position'] ?? 0), $this->id, Instance::DEFAULT_ID]
        );
        $label = $this->db->fetchNum()[0] ?? null;
        return $label === null ? $this->cms->snippet('position_at_start') : $this->cms->textOrSnippet((string)$label);
    }

    /**
     * Translate an ID list into the corresponding labels.
     * For the detail view: Backend::backendValue() outputs for 'reltable' the values
     * of the array - and without this step those would only be numbers.
     *
     * @param mixed $ids      ID list from the record
     * @param array $options  ID => label
     */
    private function _translateIds(mixed $ids, array $options): array
    {
        $labels = [];
        foreach ((array)$ids as $id) {
            $labels[] = $options[(int)$id] ?? ('#' . (int)$id);
        }
        return $labels;
    }
}
