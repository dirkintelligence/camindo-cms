<?php
/**
 * Backend UI for the editorial states (table `status`).
 *
 * Entry points (scripts in backend/):
 *
 *     status_list.php  -> list()   status list
 *     status_info.php  -> info()   detail view
 *     status_edit.php  -> edit()   create / copy / change / delete
 *
 * Structure as in LanguageBackend: short list without pagination and search, sorting via
 * a predecessor select field, when deleting the choice of a replacement status.
 *
 * THE FIRST TWO STATUSES ARE PROTECTED
 * --------------------
 * status_id 0 ("enabled") and 1 ("disabled") belong to the delivered state and are
 * presupposed in many places. edit() therefore aborts right at the start with
 * exit if an id <= 1 is to be copied or deleted - a hard barrier,
 * before any output. For them, only the assignment of the
 * user groups (group_ids) can be edited - and only by a superuser.
 *
 * USER GROUPS (flat workflow)
 * --------------------
 * The field group_ids (multiple selection) defines which groups may set the status -
 * the other view of the field "Status" in the form of a group. It
 * appears only for superusers (whoever grants permissions must be allowed to see everything, see
 * GroupBackend); otherwise Status::hookOnSave() rejects the specification.
 *
 * REPLACEMENT STATUS WHEN DELETING
 * --------------------
 * Records must not point to a vanished status_id. The delete form
 * lets a replacement be chosen, _editDeleteConfirm() passes it on via
 * Status::setReplacementId(), Status::hookOnDelete() switches the records.
 * `replacement_id` is not a database field - the field definition for it is borrowed from
 * `status_id` and only relabeled.
 *
 * @see Camindo\Core\Modules\Status\Status              Dataset/Model
 * @see Camindo\Core\Modules\Language\LanguageBackend   identically structured sister class
 */
namespace Camindo\Core\Modules\Status;

use Camindo\Core\Backend;
use Camindo\Core\InstanceCache;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\Modules\Status\Status;

class StatusBackend {
    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';
    /** new status (status_id 0 is at the same time the basic status "enabled") */
    protected bool $isNew = false;

    public function __construct(
        private Backend $backend,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Status $status
    )
    {}

    /**
     * Status list (backend/status_list.php).
     *
     * Without pagination and search - the list naturally stays short. Sorted by
     * `position`, this is the order in the select lists of the records.
     */
    public function list()
    {
        // Page elements
        // --------------------

        // Breadcrumb navigation
        $this->backend->setNaviLabel('#status.module_label');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#status.headline_status_list');
        
        // Buttons
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Status/status_edit.php?function=create');");
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        // Status list
        // --------------------
        $f = $this->cms->select([
            'request' => 'all',
            'limit' => -1,
            'filtertext' => 'true'
        ], $this->status);

        echo $this->backend->paginationTopShort();

        if ($this->db->num_rows > 0) {

            // Table-Head
            $headColumns = $this->backend->template('status.table_head_columns');

            // Table-Rows
            $tplTableRow = $this->backend->template('status.table_row');
            $tableRows = '';

            // Fetch results (Dataset::fetch: with post-processing)
            while ($data = $this->status->fetch()) {
                // status Icon + Label
                $iconHtml = $this->backend->template('status.icon_status', [
                    '{icon}' => $data['icon'],
                    '{color_class}' => $data['color_class']
                ]);
                $labelText = $this->cms->textOrSnippet($data['label']);
                $labelHtml = $iconHtml . ' ' . $labelText; 
                
                // Icon+Label | Id
                $tableRows .= strtr($tplTableRow, [
                    '{name}' => $labelText,
                    '{data_label}' => $labelHtml,
                    '{data_id}' => $data['status_id']
                ]) ."\n";

            }

            // Render table
            echo $this->backend->template('status.table_with_head', [
                '{head_columns}' => $headColumns,
                '{table_rows}' => $tableRows
            ]);

            // Context menu
            echo $this->backend->template('status.context_menu');
        }

        // Container Footer
        echo $this->backend->template('container_footer');

    }

    /**
     * Detail view of a status (backend/status_info.php).
     *
     * The headline is built by _editHeadline(); so that its case distinction applies,
     * $this->function is set to 'info' beforehand.
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

        // Status data
        // ---------------  
        // Load Status Data
        if (!$data = $this->status->load($id)) {
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
        if ($id > 1 || $this->backend->isSuperuser()) {
            $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Status/status_edit.php?id={$id}');");
        }
        if ($id > 1) {
            $this->backend->buttonAdd('button_copy', "cApp.layers.replace('Status/status_edit.php?id={$id}&function=copy');");
            $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Status/status_edit.php?id={$id}&function=delete');");
        }
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        echo $this->backend->buttonBar();

        // Tabs: Info | Changes (audit log, Backend::infoAuditTabs())
        if ($this->backend->infoAuditTabs("Status/status_info.php?id={$id}", 'status', $id)) return;

        // Data
        // ---------------  
        $this->backend->infoAdd($this->status, 'label', $data['label'], ['primary']);
        $this->backend->infoAdd($this->status, 'icon', $data['icon']);
        $this->backend->infoAdd($this->status, 'color_class', $data['color_class']);
        // Groups that may set the status (superuser always)
        $groups = $this->_groupOptions();
        $items = [$this->cms->textOrSnippet((string)$this->_superuserLabel())];
        foreach ((array)$data['group_ids'] as $groupId) {
            if (isset($groups[$groupId])) $items[] = htmlspecialchars($groups[$groupId]);
        }
        $this->backend->infoStatic($this->cms->snippet('status.field_group_ids'), implode('<br>', $items), ['html']);
        echo $this->backend->infoTable();

        echo $this->backend->template('container_footer');
    }

    /**
     * Create / copy / change / delete (backend/status_edit.php).
     *
     * State machine via $_REQUEST['function'] as in the other modules
     * (developer docs, chapter 05 (Backend development)).
     *
     * First, the protection of the two fixed statuses: everything except 'create' aborts
     * with exit immediately for an id <= 1 - before anything is output.
     */
    public function edit(): void
    {
        // Page parameters
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = (string)($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array)($_REQUEST['data'] ?? []);
        $this->data['status_id'] = $this->id;
        // New: create, or the hidden field 'new' of the form - the id 0 alone
        // does not distinguish between a new status and the basic status "enabled"
        $this->isNew = ($this->function === 'create') || !empty($_REQUEST['new']);

        // status "enabled", "disabled": only the group assignment (superuser)
        if (!$this->isNew && $this->id <= 1
            && !(in_array($this->function, ['edit', 'save', 'errors'], true) && $this->backend->isSuperuser())) exit;

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
        $this->data = $this->status->init();
        $this->id = 0;
    }

    private function _editLoad(): void
    {
        $this->data = $this->status->load($this->id);
        if (!$this->data) {
            // Requested record not found
            $this->function = 'skip';
        }
        // If data is to be copied, reset the id now
        if ($this->function == 'copy') {
            $this->id = 0;
            $this->isNew = true;
            $this->data['position']++;
        }
    }

    /**
     * Headline for form and detail view. When deleting and in the
     * detail view, the status itself is shown above it - with its icon in its color.
     */
    private function _editHeadline() {
        if (in_array($this->function, ['delete', 'info'])) {
            $labelText = $this->cms->textOrSnippet($this->data['label']);
            $iconHtml = $this->backend->template('status.icon_status', [
                '{icon}' => $this->data['icon'],
                '{color_class}' => $this->data['color_class']
            ]);
            $labelHtml = $iconHtml . ' ' . $labelText;
        }
        else {
            $labelHtml = $this->cms->snippet('status.headline_status_info');
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
        // Check data and save - the multiple selection sends nothing without a selection
        // (= no group); for the two basic statuses only the groups
        if ($this->backend->isSuperuser()) $this->data['group_ids'] = (array)($this->data['group_ids'] ?? []);
        $saved = (!$this->isNew && $this->id <= 1)
            ? $this->status->saveGroups($this->id, (array)($this->data['group_ids'] ?? []))
            : $this->status->save([], $this->data);
        if ($saved) {
            // Balloon Message
            $msg = $this->cms->snippet('status.message_status_saved');
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
     * function=delete_confirm: delete.
     *
     * The replacement status must be set BEFORE delete() - Status::hookOnDelete() runs
     * inside delete() and fetches the value there.
     */
    private function _editDeleteConfirm(): void
    {
        // Set replacement status for table record
        $replacementId = (int)($_REQUEST['data']['replacement_id'] ?? 1);
        $this->status->setReplacementId($replacementId);

        // Delete status, the replacement in `record` is handled by a hook
        if ($this->status->delete($this->id)) {
            // Balloon Message
            $msg = $this->cms->snippet('status.message_status_deleted');
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
     * Das Eingabeformular.
     *
     * Only `label` and `icon` can be edited. `color_class` is in the schema, but
     * has no input field here - so a newly created status gets no color.
     *
     * For the position, the editor chooses the PREDECESSOR; the option key is
     * its position + 1, i.e. the target position. The entry "at the beginning" has the
     * key 5, because positions 1 to 4 are reserved for the bundled statuses - for the same
     * reason the query only lists entries from status_id 4 on.
     */
    private function _editForm(): void
    {
        if ($this->function == 'save') $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Status/status_edit.php" method="post"' . $changed . '>';

        // Load Form
        $this->backend->formInit('status.edit_form');

        // Basic statuses (0, 1): only the groups
        $basic = (!$this->isNew && $this->id <= 1);
        if ($basic) {
            $this->backend->formReplace('{data_label}', '');
            $this->backend->formReplace('{data_icon}', '');
            $this->backend->formReplace('{data_position}', '');
        }
        else {
            // Data fields
            $this->backend->formField($this->status, ['name' => 'label', 'value' => $this->data['label'] ?? '', 'id' => $this->id, 'primary' => true]);
            $this->backend->formField($this->status, ['name' => 'icon', 'value' => $this->data['icon'] ?? '', 'id' => $this->id]);
        }

        // User groups that may set the status (superuser only)
        if ($this->backend->isSuperuser()) {
            $this->backend->formField($this->status, [
                'id' => $this->id,
                'name' => 'group_ids',
                'value' => array_map('strval', (array)($this->data['group_ids'] ?? [])),
                'options' => $this->_groupOptions(),
            ]);
        }
        else {
            $this->backend->formReplace('{data_group_ids}', '');
        }
        if ($basic) {
            $this->backend->formHidden('function', 'save');
            $this->backend->formHidden('id', $this->id);
            echo $this->backend->form('form_save_cancel_note');
            echo '</form>';
            return;
        }

        // position
        // --------------------
        // Options
        $options = [5 => $this->cms->snippet('position_at_start')];
        $this->db->query(
            "SELECT * FROM `status` WHERE `status_id` > 3 AND `status_id` !=? ORDER BY `position` ASC",
            'i',
            [$this->id]
        );
        while ($option = $this->db->fetch()) {
            $position = (int)$option['position'] + 1;
            $options[$position] = $this->cms->textOrSnippet($option['label']);
        }
        $this->backend->formField($this->status, [
            'id' => $this->id,
            'name' => 'position',
            'value' => $this->data['position'],
            'options' => $options,
        ]);

        // function
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $this->id);
        if ($this->isNew) $this->backend->formHidden('new', 1);

        // Print form
        echo $this->backend->form('form_save_cancel_note');

        echo '</form>';
    }

    /**
     * User groups for the assignment: group_id => label (without superuser).
     */
    private function _groupOptions(): array
    {
        $ids = $this->status->groupIds();
        if (empty($ids)) return [];
        $options = [];
        $this->db->pushResult();
        $this->db->query("SELECT `group_id`, `label` FROM `group` WHERE `group_id` IN (" . implode(',', $ids) . ") ORDER BY `label`");
        while ($row = $this->db->fetch()) {
            $options[(int)$row['group_id']] = $this->cms->textOrSnippet((string)$row['label']);
        }
        $this->db->popResult();
        return $options;
    }

    /** Label of the superuser group (it may set every status) */
    private function _superuserLabel(): string
    {
        $this->db->pushResult();
        $this->db->query("SELECT `label` FROM `group` WHERE `group_id` = ?", 'i', [InstanceCache::SUPERUSER_GROUP_ID]);
        $label = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->popResult();
        return $this->cms->textOrSnippet($label);
    }

    /**
     * function=delete: deletion confirmation with selection of the replacement status.
     *
     * `replacement_id` is not a field of the table. The field definition is borrowed from
     * `status_id` (same type, same checks) and only relabeled.
     *
     * Database::fetchPairs() returns the two-column query directly as an
     * id => label array, in which array_map() still resolves #-snippets.
     */
    private function _editDeleteForm(): void
    {
        echo '<form action="Status/status_edit.php" method="post">';

        $this->backend->formInit('status.delete_form');

        $this->db->query("SELECT `status_id`,`label` FROM `status` WHERE `status_id` != ?", 'i', [$this->id]);
        $replacementOptions = array_map(
            fn(string $value) => $this->cms->textOrSnippet($value),
            $this->db->fetchPairs()
        );
        $colDef = $this->status->getSchema()['status_id'];
        $colDef['label'] = '#status.label_replacement_status';
        $this->backend->formField($this->status, [
            'id' => $this->id,
            'name' => 'replacement_id',
            'colDef' => $colDef,
            'value' => 1,
            'display_type' => 'select',
            'options' => $replacementOptions,
        ]);
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');

        // Print form
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }
}
