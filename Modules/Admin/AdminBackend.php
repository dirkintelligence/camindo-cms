<?php
/**
 * Backend interface for the backend users (table `admin`).
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     admin_list.php    -> list()    Benutzerliste
 *     admin_info.php    -> info()    Detailansicht
 *     admin_edit.php    -> edit()    create / copy / change / delete
 *     admin_search.php  -> search()  search mask and search execution
 *     settings.php      -> settings() personal settings (own, or as
 *                                    superuser, those of another admin)
 *     totp.php          -> totp()     Zwei-Faktor-Anmeldung: Authenticator-App
 *                                    einrichten/abschalten, Wiederherstellungscodes,
 *                                    email code as own choice; as superuser, for
 *                                    reset for others
 *     password.php      -> password() change own password (only with the previous one)
 *     devices.php       -> devices()  login devices and sessions: view own,
 *                                    remove individually, end other sessions; as
 *                                    superuser, remove all devices of another admin
 *
 * This is the simplest module in the codebase and therefore serves as the model for the
 * standard structure (developer docs, chapter 05 (Backend development)): four pages, a state machine in
 * edit(), helper methods with the _edit prefix.
 *
 * ENCRYPTED FIELDS
 * --------------------
 * email, mobile and phone are stored as 'encrypted' in varbinary columns, the password as
 * a non-reversible hash. For the interface this makes no difference - Dataset
 * decrypts and encrypts on load and save. Only the search needs attention:
 * encrypted fields can only be compared exactly, not with LIKE. For the
 * fuzzy search there is the full-text column `ft`, fed from name, displayname,
 * primename and sortname.
 *
 * GRUPPEN
 * --------------------
 * Backend::getGroups() returns the selection list (group 1 = superuser first). The
 * groups themselves - with their rights to modules, instances, areas and languages -
 * are managed by GroupBackend in the "Groups" tab next to the admin list (superuser only).
 *
 * ADDITIONAL RIGHTS (install/settings.php 'access', see developer docs, chapter 11 (Permissions))
 * --------------------
 * Everyone with module access sees the list of names. In addition:
 *
 *     admin_read_group    detail view (contact data) of the admins of one's own group
 *     admin_read_other    detail view of the admins of other groups
 *     admin_create        create and copy admins - in one's own group, with
 *                         Startpasswort
 *     admin_change        change data of the admins of one's own group and block them
 *                         (status) - without password and without group
 *
 * One's own detail view is always readable. The password of another admin,
 * deleting, admins of foreign groups and the settings of other admins remain reserved for the
 * Superuser vorbehalten (_canRead() / _canCreate() / _canChange()).
 *
 * PASSWORDS
 * --------------------
 * A password that another admin has set (initial password on creation,
 * reset by the superuser) must be changed at the first login
 * (admin.password_change, Admin::hookOnSaved()); likewise after
 * admin.password_retention_days. Every admin changes their own password via
 * password.php - only with the previous one.
 *
 * AS LOOKUP TARGET
 * --------------------
 * Other forms select an admin via a lookup field (template
 * edit_form_lookup_foreign_table, e.g. the recipient of an internal message in
 * MessagingBackend). For this, list() knows the parameter src_field: the list gets
 * a checkbox column whose tick calls cApp.ui.slookupUpdate(), and
 * ajax_lookup_preview.php (lookupPreview()) returns the preview block with the
 * hidden field that carries the selected admin_id into the calling form -
 * just as TemplateBackend does for Instance::template_id.
 *
 * @see Camindo\Core\Modules\Admin\Admin  Dataset/model, also contains the login
 * @see Camindo\Core\Modules\Admin\GroupBackend  user groups and their rights
 * @see Camindo\Core\Backend
 */
namespace Camindo\Core\Modules\Admin;

use Camindo\Core\Backend;
use Camindo\Core\ClientIp;
use Camindo\Core\Modules\Privacy\AdminAnonymizer;
use Camindo\Core\Modules\Privacy\PrivacyBackend;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\Widgets;
use Camindo\Core\LoginGuard;
use Camindo\Core\LoginDevices;
use Camindo\Core\AuditLog;
use Camindo\Core\BackendDomain;
use Camindo\Core\FrontendHandover;
use Camindo\Core\InstanceCache;
use Camindo\Core\Modules\Audit\AuditBackend;
use Camindo\Core\Modules\Record\Record;
use Camindo\Core\Modules\Messaging\Message;

// The class name is written in lowercase here, while the file and all places of use
// write it as `AdminBackend`. PHP compares class names without regard to
// case, and the autoloader finds the file via the file name - so it
// works, but deviates from the convention (developer docs, chapter 15 (Conventions)).
class AdminBackend {
    // Are never read or filled; the status display comes from
    // Backend::teaser('status', …).
    protected array $statusIcon = [];
    protected array $statusLabel = [];

    // Icon rendered once in the constructor, so that the HTML template does not have to fetch it from the cache
    // again for each row.
    protected string $iconAdmin = '';
    protected string $iconSuperuser = '';

    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';

    public function __construct(
        private Backend $backend,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Session $session,
        private System $system,
        private Admin $admin,
        private GroupBackend $groupBackend,
        private Widgets $widgets,
        private LoginGuard $loginGuard,
        private TotpFactor $totpFactor,
        private EmailFactor $emailFactor,
        private WebAuthnFactor $webAuthnFactor,
        private Passkeys $passkeys,
        private FrontendHandover $frontendHandover,
        private BackendDomain $backendDomain,
        private InstanceCache $instanceCache,
        private LoginDevices $loginDevices,
        private AuditLog $auditLog,
        private Config $config,
        private AuditBackend $auditBackend,
        private Record $record,
    )
    {
        $this->iconAdmin = $this->backend->template('admin.icon_admin');
        $this->iconSuperuser = $this->backend->template('admin.icon_superuser');
    }

    // ====================
    // Additional rights (see class comment)

    /**
     * May the detail view of an admin of group $groupId be read?
     * (0 = eigener Datensatz)
     */
    private function _canRead(int $groupId, int $adminId = 0): bool
    {
        $access = $this->backend->access();
        if ($access->isSuperuser()) return true;
        if ($adminId > 0 && $adminId === (int)$this->session->get('admin_id')) return true;
        $own = ($groupId === $access->groupId());
        return $access->moduleRight('Admin', $own ? 'admin_read_group' : 'admin_read_other');
    }

    /**
     * May an admin be created or copied (additional right admin_create; the
     * group is fixed to one's own for non-superusers)?
     */
    private function _canCreate(): bool
    {
        $access = $this->backend->access();
        if ($access->isSuperuser()) return true;
        return $access->moduleRight('Admin', 'admin_create');
    }

    /**
     * May an admin of group $groupId be changed or blocked
     * (additional right admin_change, own group only)?
     */
    private function _canChange(int $groupId): bool
    {
        $access = $this->backend->access();
        if ($access->isSuperuser()) return true;
        return $groupId === $access->groupId() && $access->moduleRight('Admin', 'admin_change');
    }

    /**
     * Group of new admins: for non-superusers, fixed to one's own.
     */
    private function _ownGroupId(): int
    {
        return $this->backend->access()->groupId();
    }

    /**
     * Benutzerliste (backend/admin_list.php).
     *
     * 'request' => 'all' lets Cms::select() take filter, sort order and page number from the
     * request - so search mask, sort links and pagination work without
     * eigenes Zutun.
     *
     * The status column additionally has a dropdown in the column header (quick filter);
     * $thStatusId is therefore not used directly, but only inserted into the template
     * admin.admin_table_head_column_dropdown gewickelt.
     *
     * With src_field (name of the calling lookup field) the list becomes a
     * selection list: first column checkbox, tick = cApp.ui.slookupUpdate(); already
     * selected admins come along as 'checked' (comma list). A single, not yet
     * selected hit is applied immediately and the layer is closed.
     *
     * Request: filter, sort, order, skip (via select 'request'), src_field, checked
     */
    public function list()
    {
        // Lookup-Aufruf?
        $srcField = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_REQUEST['src_field'] ?? '')));
        $checkedIds = $this->system->sanitizeIds(
            explode(',', (string)($_REQUEST['checked'] ?? ''))
        );

        // Seitenelemente
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#admin.module_label');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#admin.headline_admin_list');

        // Tabs: Admins | Groups (superuser only, not in lookup)
        if ($srcField === '') {
            echo $this->groupBackend->moduleTabs('admins');
        }
        
        // Buttons (create only with the additional right admin_create)
        $canCreate = $this->_canCreate();
        $canChange = $this->_canChange($this->_ownGroupId());
        $this->backend->buttonBarReset();
        if ($srcField !== '') {
            if ($canCreate) $this->backend->buttonAdd('button_create', "cApp.layers.add('Admin/admin_edit.php?function=create');");
        }
        elseif ($canCreate) {
            $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Admin/admin_edit.php?function=create');");
        }
        $this->backend->buttonAdd('button_search', "cApp.layers.add('Admin/admin_search.php?' + cApp.layers.queryString());");
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        if ($srcField !== '') {
            $this->backend->buttonAdd('record.button_apply', "cApp.layers.close();cApp.ui.lookupFocus('{$srcField}');");
        }
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        echo $this->backend->buttonBar();

        // Liste
        // --------------------
        // Owner lookup of the record mask (src_field owner_id): a non-superuser
        // may only select admins of his own group as owner (developer docs, chapter 11 (Permissions))
        $sql = '';
        if ($srcField === 'owner_id' && !$this->backend->isSuperuser()) {
            $sql = '`admin`.`group_id` = ' . $this->_ownGroupId();
        }
        $f = $this->cms->select([
            'request' => 'all',
            'limit' => $this->cms->list_rows,
            'count' => 'true',
            'filtertext' => 'true',
            'sql' => $sql,
        ], $this->admin);

        // Search terms or changed sort order as readable text
        echo $this->backend->filterText($f);

        if ($this->db->num_rows > 0) {

            // Pagination top
            echo $this->backend->paginationTop('admin.listed_records');

            // Prepare Table-Head
            $schema = $this->admin->getSchema();
            $thName = $this->backend->tableHeadColumn($schema['name'], $f, 'name');
            $thDisplayname = $this->backend->tableHeadColumn($schema['displayname'], $f, 'displayname');
            $thGroupId = $this->backend->tableHeadColumn($schema['group_id'], $f, 'group_id');
            $thStatusId = $this->backend->tableHeadColumn($schema['status_id'], $f, '#admin.field_admin_status_id');
            
            // Status has dropdown
            $statusLabelDropdown = $this->backend->template('admin.admin_table_head_column_dropdown',[
                '{dropdown_id}' => $this->crypt->randomKey(16),
                '{column_label}' => $thStatusId
            ]);

            // Render table head
            $checkboxHeadColumn = ($srcField !== '')
                ? $this->backend->template('admin.admin_table_checkbox_head_column') : '';
            $headColumns = $this->backend->template('admin.admin_table_head_columns',[
                '{checkbox_column}' => $checkboxHeadColumn,
                '{label_name}' => $thName,
                '{label_displayname}' => $thDisplayname,
                '{label_group_id}' => $thGroupId,
                '{label_status_id}' => $statusLabelDropdown
            ]);

            // Collect table rows
            $tplTableRow = $this->backend->template('admin.admin_table_row');
            $tableRows = '';
            
            // Fetch results (Dataset::fetch: with post-processing of the data)
            $destId = 0;
            $isLinked = false;
            $groupLabels = $this->backend->getGroups();
            while ($data = $this->admin->fetch()) {
                $destId = (int)$data['admin_id'];

                // Lookup call: checkbox column. The selection goes into the field of the
                // calling form (layer below); nothing is written here.
                $checkboxColumn = '';
                if ($srcField !== '') {
                    $isLinked = in_array($destId, $checkedIds, true);
                    $checkboxColumn = $this->backend->template('table_column_noctx_lg', [
                        '{html}' => $this->backend->template('list_checkbox', [
                            '{onclick}' => "cApp.ui.slookupUpdate('{$srcField}',\$(this).prop('checked')?'link':'unlink',{$destId});",
                            '{checked}' => $isLinked ? ' checked' : ''
                        ])
                    ]);
                }

                // Group
                $dataGroup = $groupLabels[(int)$data['group_id']];

                // Show only Icon
                $dataStatus = $this->backend->teaser('status', (int)$data['status_id'], linked:false, icon:true, label:false);

                // Username | Displayed Name | Group | Status
                $tableRow = strtr($tplTableRow, [
                    '{data_id}' => $data['admin_id'],
                    '{icon}' => ($data['group_id'] == 1) ? 'identity_platform' : 'person',
                    '{checkbox_column}' => $checkboxColumn,
                    '{data_name}' => $data['name'],
                    '{data_displayname}' => $data['displayname'] ?: $this->backend->emptyPlaceholder,
                    '{data_group}' => $dataGroup ?: '??',
                    '{data_status}' => $dataStatus ?: '??'
                ]) ."\n";

                $tableRows .= $tableRow;
            }

            // Render table
            echo $this->backend->template('admin.admin_table_with_head', [
                '{head_columns}' => $headColumns,
                '{table_rows}' => $tableRows
            ]);

            // Context menu (edit with admin_change, copy with admin_create,
            // delete only superuser - one menu for all rows, edit() checks each
            // record again)
            $menu = match (true) {
                $this->backend->isSuperuser() => 'admin.admin_context_menu',
                $canCreate && $canChange => 'admin.admin_context_menu_write',
                $canChange => 'admin.admin_context_menu_change',
                $canCreate => 'admin.admin_context_menu_create',
                default => '',
            };
            if ($menu !== '') echo $this->backend->template($menu);

            // Pagination bottom
            echo $this->backend->paginationBottom();

            // Lookup call with exactly one hit not yet selected (e.g. after
            // entering a name in the lookup field): apply and close immediately.
            // slookupUpdate() closes itself for a single field (max 1) - without
            // preventClosing, the form below would otherwise close as well
            if ($srcField !== '' && $this->db->num_rows_unlimited === 1 && !$isLinked) {
                $this->backend->script("
                    cApp.ui.slookupUpdate('{$srcField}','link',{$destId},true);
                    cApp.layers.close();
                ");
            }
        }

        // Container Footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Ajax: Vorschaublock eines Admin-Lookup-Feldes (backend/ajax_lookup_preview.php).
     *
     * Called by cApp.ui.slookupUpdate() / slookupUnlink() after a tick was set or removed in the
     * selection list. Nothing is written:
     * the block contains per selected admin a hidden field data[{src_field}],
     * which only goes out with the calling form.
     *
     * Request: src_field (Pflicht), ids (Kommaliste), max, function, prefix
     *          ('filter' for the search mask, otherwise 'data')
     */
    public function lookupPreview(): void
    {
        $function = (string)($_REQUEST['function'] ?? '');
        $srcField = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_REQUEST['src_field'] ?? '')));
        $max = (int)($_REQUEST['max'] ?? 0);
        if ($srcField === '') {
            $this->cms->error('lookupPreview() Exception: parameter src_field is empty.');
            $this->_editErrors();
            return;
        }
        $ids = explode(',', preg_replace('/[^0-9,]/', '', (string)($_REQUEST['ids'] ?? '')));
        // prefix=filter: search field of the search mask (filter[...]), otherwise form field
        $prefix = ((string)($_REQUEST['prefix'] ?? '') === 'filter') ? 'filter' : 'data';
        echo $this->lookupPreviewCreate($srcField, $ids, $prefix, $max);
        if ($function == 'link') {
            $this->backend->script("cApp.ui.lookupFocus('{$srcField}', true);");
        }
    }

    /**
     * Render the preview block of an admin lookup field.
     *
     * Common core of lookupPreview() and the initial form setup in
     * other modules (MessagingBackend::_editForm()). One row per selected admin
     * with icon and name, remove button and the hidden field with the id - the
     * block IS the input field. Deleted and deactivated admins remain selectable
     * visible if they are already saved (load instead of select), so that an
     * existing value does not silently disappear.
     *
     * @param string $srcField  Name of the lookup field (= name of the form field)
     * @param array  $ids       selected admin_ids
     * @param string $prefix    form prefix ('data' / 'filter')
     * @param int    $max       1: at most one admin
     */
    public function lookupPreviewCreate(string $srcField, array $ids, string $prefix, int $max): string
    {
        if ($srcField === '') return '';
        $ids = $this->system->sanitizeIds($ids, true);
        if (empty($ids)) return '';
        if ($max == 1) $ids = [reset($ids)];

        $varName = "{$prefix}[{$srcField}]";
        if ($max != 1) $varName .= '[]';

        $rows = [];
        foreach ($ids as $id) {
            $label = $this->backend->teaser('admin', (int)$id, linked: false, icon: true, label: true);
            if ($label === '') continue;
            $rows[] = $this->backend->template('admin.lookup_table_row', [
                '{data_id}' => $id,
                '{text}' => $label,
                '{unlink_url}' => "cApp.ui.slookupUnlink({$id});",
                '{var_name}' => $varName
            ]);
        }
        if (empty($rows)) return '';

        return $this->backend->template('record.lookup_preview_container', [
            '{html}' => $this->backend->template('record.slookup_table', [
                '{table_rows}' => implode("\n", $rows)
            ])
        ]);
    }

    /**
     * Detailansicht eines Benutzers (backend/admin_info.php).
     *
     * The password does not appear - Dataset::load() does not return fields of type 'password'
     * at all.
     */
    public function info()
    {
        $id = (int)($_REQUEST['id'] ?? 0);

        // Seitenelemente
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#button_info');

        // Container header
        echo $this->backend->template('container_header');

        // Load admin data
        if (!$data = $this->admin->load($id)) {
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->cms->errorMsg()
            ]);
            echo $this->backend->template('container_footer');
            return;
        }
        // Zusatzrecht: Details lesen (eigene / andere Gruppe)
        if (!$this->_canRead((int)$data['group_id'], $id)) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        // H1
        $icon = ($data['group_id'] == 1) ? $this->iconSuperuser : $this->iconAdmin;
        echo $this->backend->headline("{$icon} {$data['name']}");

        // Tabs: Info | Settings | … (superuser only, see _infoTabs())
        $auditActive = $this->backend->isSuperuser() && $this->backend->auditTabActive();
        echo $this->_infoTabs($id, $auditActive ? 'changes' : 'info');
        
        // Buttons
        $this->backend->buttonBarReset();
        if (empty($data['deleted'])) {
            if ($this->_canChange((int)$data['group_id'])) {
                $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Admin/admin_edit.php?id={$id}');");
            }
            if ($this->_canCreate()) {
                $this->backend->buttonAdd('button_copy', "cApp.layers.replace('Admin/admin_edit.php?id={$id}&function=copy');");
            }
            if ($this->backend->isSuperuser()) {
                $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Admin/admin_edit.php?id={$id}&function=delete');");
            }
        }
        // Deleted and not yet anonymized: "Anonymize" (module Privacy,
        // Zusatzrecht admin_anonymize - AdminAnonymizer)
        elseif (!AdminAnonymizer::isAnonymized($data) && $this->backend->access()->module('Privacy')
            && $GLOBALS['app']->get(PrivacyBackend::class)->canAnonymizeAdmins()) {
            $this->backend->buttonAdd('privacy.button_admin_anonymize_bar', "cApp.layers.replace('Privacy/admin_anonymize.php?id={$id}');");
        }
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        echo $this->backend->buttonBar();

        if ($auditActive) {
            echo $this->backend->auditHistory('admin', $id);
            echo $this->backend->template('container_footer');
            return;
        }

        // Data fields
        $this->backend->infoAdd($this->admin, 'name', $data['name']);
        $groupLabel = $this->backend->teaser('group', (int)$data['group_id']);
        $this->backend->infoAdd($this->admin, 'group_id', $groupLabel,['html']);
        $this->backend->infoAdd($this->admin, 'primename', $data['primename']);
        $this->backend->infoAdd($this->admin, 'displayname', $data['displayname']);
        $this->backend->infoAdd($this->admin, 'sortname', $data['sortname']);
        $this->backend->infoAdd($this->admin, 'email', $data['email']);
        $this->backend->infoAdd($this->admin, 'mobile', $data['mobile']);
        $this->backend->infoAdd($this->admin, 'phone', $data['phone']);
        if (!empty($data['deleted'])) {
            $statusLabel = $this->backend->teaser('status', -1 , linked:false, icon:true, label:true);
        }
        else {
            $statusLabel = $this->backend->teaser('status', (int)$data['status_id'], linked:false, icon:true, label:true);
        }
        $this->backend->infoAdd($this->admin, 'status_id', $statusLabel, ['html']);
        $this->backend->infoStatic('#admin.field_admin_second_factor', htmlspecialchars($this->_secondFactorLabel($data)));
        $this->backend->infoStatic('#admin.field_admin_password_state', htmlspecialchars($this->_passwordStateLabel($id)));
        echo $this->backend->infoTable();

        echo $this->backend->template('container_footer');
    }
    
    /**
     * "Activities" tab of the detail view (backend/admin_audit.php): what the
     * admin left in the audit log - recently edited records and all
     * entries (AuditBackend::adminActivity()). Superuser only, as the module
     * Audit selbst.
     *
     * Request: id, skip (paging)
     */
    public function audit(): void
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $this->backend->setNaviLabel('#admin.headline_audit');
        echo $this->backend->template('container_header');

        if (!$this->backend->isSuperuser()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        if (!$data = $this->admin->load($id)) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
            echo $this->backend->template('container_footer');
            return;
        }

        echo $this->backend->headline("{$this->iconAdmin} {$data['name']}");
        echo $this->_infoTabs($id, 'audit');

        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        $this->backend->buttonAdd('admin.button_audit_module', "cApp.layers.add('Audit/index.php?admin_id={$id}');");
        $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        echo $this->backend->buttonBar();

        echo $this->backend->template('spacer');
        echo $this->auditBackend->adminActivity($id);
        echo $this->backend->template('container_footer');
    }

    /**
     * Tab bar of the detail view: Info | Settings | Two-factor login |
     * Devices | Activities | Changes. Only a superuser sees the tabs - only he
     * may preset the settings of another admin (settings()), reset their TOTP
     * (totp()), remove their devices (devices()) and view the audit log
     * : what the admin did (audit(), "Activities") and what was changed on their
     * account (info() with tab=audit, "Changes").
     *
     * @param int    $id      admin_id
     * @param string $active  'info' | 'settings' | 'totp' | 'devices' | 'audit' | 'changes'
     */
    private function _infoTabs(int $id, string $active): string
    {
        if (!$this->backend->isSuperuser()) return '';
        $this->backend->tabBarReset();
        $this->backend->tabAdd('#button_info', "cApp.layers.replace('Admin/admin_info.php?id={$id}')", $active === 'info');
        $this->backend->tabAdd('#admin.headline_settings', "cApp.layers.replace('Admin/settings.php?id={$id}')", $active === 'settings');
        $this->backend->tabAdd('#admin.headline_totp', "cApp.layers.replace('Admin/totp.php?id={$id}')", $active === 'totp');
        $this->backend->tabAdd('#admin.headline_devices', "cApp.layers.replace('Admin/devices.php?id={$id}')", $active === 'devices');
        $this->backend->tabAdd('#admin.headline_audit', "cApp.layers.replace('Admin/admin_audit.php?id={$id}')", $active === 'audit');
        // "Changes": audit log OF the admin account (tab=audit on admin_info.php) -
        // "Activities" above is what the admin changed themselves
        $this->backend->auditTabAdd("Admin/admin_info.php?id={$id}");
        return $this->backend->tabBar();
    }

    /**
     * Personal settings (backend/settings.php).
     *
     * Without id (gear icon top right) the own ones; with id those of another admin -
     * only a superuser may do that, as a tab in their detail view. Saved
     * in `admin`.`settings` (Admin::saveSettings()), Admin::hookOnSaved()
     * carries the values into the running sessions.
     *
     * What can be set:
     *     language_id    preferred language version in the input mask
     *     backend_code   Backend-Sprache
     *     locale         locale of the date, time and number formats
     *     timezone       eigene Zeitzone
     *     fed            frontend editing (own settings only: it is a cookie
     *                    dieses Browsers)
     *     widgets        widgets of the start page as a list of module + slot
     *                    (Camindo\Core\Widgets)
     *     reports        subscribed reports (scheduler_ids, tasks with "selectable
     *                    report" for the admin's group) - after each run the result comes as
     *                    an internal message (SchedulerRunner)
     *     message_digest summary of the internal messages by email
     *                    (0 off, 1 daily - Messaging\MessageDigest)
     *     animation      slide animation of the record list of a lookup field
     *                    (1 on, 0 off - cConfig.animation)
     *
     * Besides '' (display), 'save' and 'errors' there is 'update': adding and
     * removing a widget send the form back to itself without
     * saving - the list travels along as a hidden field. Saving only happens
     * with "Save", as with every other field.
     *
     * When saving one's own settings, the cookie 'fed' is set or
     * deleted, the choice of language, locale and time zone is stored in localStorage (pre-fill
     * of the login form) and the backend is reloaded - the static parts of the
     * interface (header, menu) would otherwise stay in the old language.
     */
    public function settings(): void
    {
        $sessionAdminId = (int)$this->session->get('admin_id');
        $id = (int)($_REQUEST['id'] ?? 0);
        $isOwn = ($id < 1 || $id === $sessionAdminId);
        if ($isOwn) $id = $sessionAdminId;
        $function = (string)($_REQUEST['function'] ?? '');

        // Process first, then output: the frontend editing cookie must be set before
        // the first byte is output (no output buffering)
        $denied = (!$isOwn && !$this->backend->isSuperuser());
        $data = $denied ? null : $this->admin->load($id);
        $settings = [];
        $saved = false;
        $fedChanged = false;
        if ($data) {
            // Current values: defaults + saved settings + input from the request
            $defaults = (array)($this->admin->init()['settings'] ?? []);
            $settings = array_replace($defaults, (array)($data['settings'] ?? []));
            if ($function === 'save' || $function === 'update') {
                $input = (array)($_REQUEST['data']['settings'] ?? []);
                // Frontend editing is a cookie of THIS browser - not settable for others
                if (!$isOwn) unset($input['fed']);
                // Add or remove a widget (see _settingsWidgets())
                $input[Widgets::SETTINGS_KEY] = $this->_settingsWidgetsFromRequest($input);
                // Reports: only those the admin's group may subscribe to - without
                // a tick the key does not arrive at all, so then there is none
                $input['reports'] = array_values(array_intersect(
                    $this->system->sanitizeIds((array)($input['reports'] ?? []), true),
                    array_keys($this->_reportOptions((int)$data['group_id']))
                ));
                $settings = array_replace($settings, $input);
            }
            if ($function === 'save') {
                $saved = $this->admin->saveSettings($id, $input);
                if ($saved && $isOwn) {
                    $this->backend->setFedCookie((int)($settings['fed'] ?? 0));
                    $fedChanged = (int)($data['settings']['fed'] ?? 0) !== (int)($settings['fed'] ?? 0);
                }
                if (!$saved) $function = 'errors';
            }
        }

        $this->backend->setNaviLabel('#admin.headline_settings');
        echo $this->backend->template('container_header');

        if ($denied) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->snippet('admin.error_settings_denied')]);
            echo $this->backend->template('container_footer');
            return;
        }
        if (!$data) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
            echo $this->backend->template('container_footer');
            return;
        }

        // Heading: own settings or those of the admin shown
        if ($isOwn) {
            echo $this->backend->headline('#admin.headline_settings');
        }
        else {
            echo $this->backend->headline("{$this->iconAdmin} {$data['name']}");
            echo $this->_infoTabs($id, 'settings');
            // Delete all messages of the user (only here: superuser, foreign account)
            if ($function === 'messages_delete_form') {
                echo $this->backend->template('admin.messages_delete_form', ['{id}' => (string)$id]);
                echo $this->backend->template('container_footer');
                return;
            }
            if ($function === 'messages_delete' && $this->backend->requirePost()) {
                $count = $GLOBALS['app']->get(Message::class)->deleteAllOf($id);
                $this->system->log('security', sprintf('messages of admin %d (%s) deleted by admin %d: %d received, %d sent',
                    $id, (string)$data['name'], $sessionAdminId, $count['inbox'], $count['outbox']));
                $this->backend->balloon('success', strtr($this->cms->snippet('admin.message_messages_deleted'),
                    ['{inbox}' => (string)$count['inbox'], '{outbox}' => (string)$count['outbox']]));
            }
        }

        if ($saved) {
            $this->backend->balloon('success', $this->cms->snippet('admin.message_settings_saved'));
            if ($isOwn) {
                $this->_settingsApplyOwn($settings, $fedChanged);
            }
            else {
                $this->backend->script("cApp.layers.invalidate(); cApp.layers.close();");
            }
            echo $this->backend->template('container_footer');
            return;
        }
        if ($function === 'errors') {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
        }

        $this->_settingsForm($id, $isOwn, $settings, $function === 'errors');
        echo $this->backend->template('container_footer');
    }

    /**
     * Own settings saved: localStorage and reload (see settings();
     * the frontend editing cookie is already set there before output,
     * but only under the domain of the backend). If frontend editing has changed,
     * the sync with the websites (cApp.ui.fedSync()) runs instead of the reload,
     * which likewise ends in the backend.
     */
    private function _settingsApplyOwn(array $settings, bool $fedChanged): void
    {
        $code = json_encode((string)($settings['backend_code'] ?? ''));
        $timezone = json_encode((string)($settings['timezone'] ?? ''));
        $locale = json_encode((string)($settings['locale'] ?? ''));
        $sync = $fedChanged ? $this->_fedSyncJson((int)($settings['fed'] ?? 0)) : '';
        $reload = $sync !== ''
            ? "cApp.ui.fedSync({$sync}, () => window.location.reload());"
            : 'window.location.reload();';
        $this->backend->script("
            cApp.ui.loginPrefsStore({$code}, {$timezone}, {$locale});
            cApp.layers.close();
            {$reload}
        ");
    }

    /**
     * Websites for frontend editing: primary_domain per instance, which the own
     * group may read - without the domain under which the backend is currently running (there
     * Backend::setFedCookie() sets the cookie directly).
     *
     * @return array<string,int>  Domain => instance_id
     */
    private function _fedSites(): array
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $host = (string)preg_replace('/:\d+$/', '', $host);
        $access = $this->backend->access();
        $sites = [];
        foreach ((array)($this->instanceCache->all()['primary_domains'] ?? []) as $domain => $instanceId) {
            if (!$access->instance((int)$instanceId)) continue;
            if (preg_replace('/:\d+$/', '', (string)$domain) === $host) continue;
            $sites[(string)$domain] = (int)$instanceId;
        }
        return $sites;
    }

    /**
     * Data for cApp.ui.fedSync() as a JavaScript literal - '' without website.
     */
    private function _fedSyncJson(int $mode): string
    {
        $sites = $this->_fedSites();
        $returnUrl = $this->frontendHandover->backendReturnUrl();
        if (!$sites || $returnUrl === '') return '';
        $data = $this->frontendHandover->fedSyncData($sites, $mode, $returnUrl);
        if ($data === null) return '';
        return (string)json_encode($data,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The settings form.
     *
     * @param int   $id        admin_id
     * @param bool  $isOwn     own settings (with frontend editing)
     * @param array $settings  aktuelle Werte
     * @param bool  $changed   true: shown again after an error
     */
    private function _settingsForm(int $id, bool $isOwn, array $settings, bool $changed): void
    {
        echo '<form action="Admin/settings.php" method="post"' . ($changed ? ' data-changed="true"' : '') . '>';
        // Two-factor login: status and the way to set it up (totp())
        $this->backend->formInit('admin.admin_settings_form', [
            '{data_second_factor}' => $this->_settingsSecondFactor($id, $isOwn),
            '{data_security}' => $isOwn ? $this->_settingsSecurity() : '',
            '{data_fed_sites}' => $isOwn ? $this->_settingsFedSites((int)($settings['fed'] ?? 0)) : '',
            '{data_messages}' => $isOwn ? '' : $this->_settingsMessages($id),
        ]);

        // Preferred language version: '' = main language of the instance; for own
        // settings only the languages the own group may read (A8,
        // developer docs, chapter 11 (Permissions) - a superuser sees all
        $access = $this->backend->access();
        $languageOptions = ['' => $this->cms->snippet('admin.option_language_primary')];
        $this->db->query("SELECT `language_id`, `code`, `label` FROM `language` ORDER BY `position` ASC");
        while ($row = $this->db->fetch()) {
            if ($isOwn && !$access->languageRead((int)$row['language_id'])) continue;
            $languageOptions[(string)$row['language_id']] = $this->cms->textOrSnippet((string)$row['label']) . ' (' . $row['code'] . ')';
        }
        $this->backend->formField($this->admin, [
            'name' => 'settings.language_id',
            'value' => (string)($settings['language_id'] ?? ''),
            'id' => $id,
            'options' => $languageOptions,
        ]);

        // Backend language (pre-fill an earlier code such as "de" as "de_DE")
        $backendCode = (string)($settings['backend_code'] ?? '');
        $this->backend->formField($this->admin, [
            'name' => 'settings.backend_code',
            'value' => $backendCode === '' ? '' : $this->cms->resolveBackendLang($backendCode),
            'id' => $id,
            'options' => $this->backend->backendLanguageOptions(),
        ]);

        // Locale
        $this->backend->formField($this->admin, [
            'name' => 'settings.locale',
            'value' => (string)($settings['locale'] ?? ''),
            'id' => $id,
            'options' => $this->backend->localeOptions(),
        ]);

        // Zeitzone
        $this->backend->formField($this->admin, [
            'name' => 'settings.timezone',
            'value' => (string)($settings['timezone'] ?? ''),
            'id' => $id,
            'options' => $this->backend->timezoneOptions(),
        ]);

        // Frontend editing - only for own settings
        if ($isOwn) {
            $this->backend->formField($this->admin, [
                'name' => 'settings.fed',
                'value' => (int)($settings['fed'] ?? 0),
                'id' => $id,
                'options' => [
                    0 => $this->cms->snippet('admin.option_fed_0'),
                    1 => $this->cms->snippet('admin.option_fed_1'),
                    2 => $this->cms->snippet('admin.option_fed_2'),
                ],
            ]);
        }

        // Animation when a lookup field opens its record list
        $this->backend->formField($this->admin, [
            'name' => 'settings.animation',
            'value' => (int)($settings['animation'] ?? 1),
            'id' => $id,
            'options' => [
                1 => $this->cms->snippet('admin.option_animation_1'),
                0 => $this->cms->snippet('admin.option_animation_0'),
            ],
        ]);

        // Start page widgets
        $this->backend->formStatic($this->admin, 'settings.widgets', $this->_settingsWidgets($settings));

        // Subscribe to reports (selectable scheduler reports for the group)
        $reports = $this->_reportOptions((int)($this->admin->load($id)['group_id'] ?? 0));
        if ($reports) {
            $this->backend->formField($this->admin, [
                'name' => 'settings.reports',
                'value' => array_map('intval', (array)($settings['reports'] ?? [])),
                'id' => $id,
                'options' => $reports,
            ]);
        }

        // Interne Nachrichten per E-Mail zusammenfassen
        $this->backend->formField($this->admin, [
            'name' => 'settings.message_digest',
            'value' => (int)($settings['message_digest'] ?? 0),
            'id' => $id,
            'options' => [
                0 => $this->cms->snippet('admin.option_message_digest_0'),
                1 => $this->cms->snippet('admin.option_message_digest_1'),
            ],
        ]);

        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $id);
        echo $this->backend->form('form_save_cancel_note', [], removePlaceholders: true);
        echo '</form>';
    }

    /**
     * Reports that admins of a group can subscribe to
     * (Scheduler::reportsForGroup(): tasks url/php with "selectable report").
     *
     * @return array  scheduler_id => Bezeichnung
     */
    private function _reportOptions(int $groupId): array
    {
        return $GLOBALS['app']->get(\Camindo\Core\Modules\Scheduler\Scheduler::class)->reportsForGroup($groupId);
    }

    /**
     * Frontend editing on the websites: the cookie applies per domain, the backend
     * only sets it under its own when saving. When saving a
     * changed setting, settings() syncs all websites (cApp.ui.fedSync());
     * here additionally a button for the sync (e.g. in another browser or
     * after the cookie has expired) and per website (primary_domain, instance readable for the
     * own group) a button that opens it in a new tab and sets the cookie
     * (FrontendHandover::fedUrl(), developer docs, chapter 13 (Security: authentication)). If
     * frontend editing is off (saved setting), only the sync - it deletes
     * the cookies on the websites.
     */
    private function _settingsFedSites(int $mode): string
    {
        $sites = $this->_fedSites();
        if (!$sites) {
            return $this->backend->template('edit_form_static', [
                '{label}' => $this->cms->snippet('admin.label_fed_sites'),
                '{html}' => htmlspecialchars($this->cms->snippet('admin.note_fed_sites_none')),
            ]);
        }
        $buttons = [];
        $sync = $this->_fedSyncJson($mode);
        if ($sync !== '') {
            $buttons[] = $this->backend->template('button_secondary', [
                '{onclick}' => "cApp.ui.fedSync({$sync}, () => cApp.system.balloon('danger', "
                    . json_encode($this->cms->snippet('admin.error_fed_sync_unreachable'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
                    . "));",
                '{label}' => $this->cms->snippet('admin.button_fed_sync'),
            ]);
        }
        if ($mode > 0) {
            foreach ($sites as $domain => $instanceId) {
                $url = $this->frontendHandover->fedUrl($instanceId, $mode);
                if ($url === '') continue;
                $buttons[] = $this->backend->template('admin.fed_site_button', [
                    '{url}' => htmlspecialchars($url, ENT_QUOTES),
                    '{domain}' => htmlspecialchars($domain),
                ]);
            }
        }
        $html = $this->backend->template('admin.fed_sites', [
            '{buttons}' => implode('', $buttons),
            '{note}' => $this->backend->escape($this->cms->snippet($mode > 0 ? 'admin.note_fed_sites' : 'admin.note_fed_sites_off')),
        ]);
        return $this->backend->template('edit_form_static', [
            '{label}' => $this->cms->snippet('admin.label_fed_sites'),
            '{html}' => $html,
        ]);
    }

    /**
     * Block "Two-factor login" in the settings form: which factor applies,
     * plus a button to the totp() page - own settings in the new layer,
     * foreign ones (superuser) as a tab.
     */
    private function _settingsSecondFactor(int $id, bool $isOwn): string
    {
        $data = $this->admin->load($id) ?? ['admin_id' => $id];
        $html = htmlspecialchars($this->_secondFactorLabel($data));
        if ($this->totpFactor->available()) {
            $onclick = $isOwn ? "cApp.layers.add('Admin/totp.php')" : "cApp.layers.replace('Admin/totp.php?id={$id}')";
            $html .= ' &nbsp; ' . $this->backend->template('button_secondary', [
                '{onclick}' => $onclick,
                '{label}' => $this->cms->snippet('admin.button_totp_manage'),
            ]);
        }
        return $this->backend->template('edit_form_static', [
            '{label}' => $this->cms->snippet('admin.field_admin_second_factor'),
            '{html}' => $html,
        ]);
    }

    /**
     * Row "Internal messages" in the settings of another user
     * (superuser): count in inbox/outbox and the button to delete all of them
     * (Message::deleteAllOf()) - e.g. for a blocked employee.
     */
    private function _settingsMessages(int $id): string
    {
        $count = $GLOBALS['app']->get(Message::class)->countOf($id);
        $html = $this->backend->escape(strtr($this->cms->snippet('admin.text_messages_count'),
            ['{inbox}' => (string)$count['inbox'], '{outbox}' => (string)$count['outbox']]));
        if ($count['inbox'] + $count['outbox'] > 0) {
            $html .= '<div class="mt-2">' . $this->backend->template('button_danger', [
                '{onclick}' => "cApp.layers.replace('Admin/settings.php?id={$id}&function=messages_delete_form')",
                '{label}' => $this->cms->snippet('admin.button_messages_delete'),
            ]) . '</div>';
        }
        return $this->backend->template('edit_form_static', [
            '{label}' => $this->cms->snippet('admin.field_messages'),
            '{html}' => $html,
        ]);
    }

    /**
     * Block "Password and devices" in the own settings: buttons for
     * password() and devices(), plus the status of the password.
     */
    private function _settingsSecurity(): string
    {
        $html = $this->backend->template('admin.settings_security', [
            '{state}' => $this->backend->escape($this->_passwordStateLabel((int)$this->session->get('admin_id'))),
            '{buttons}' => $this->backend->template('button_secondary', [
                '{onclick}' => "cApp.layers.add('Admin/password.php')",
                '{label}' => $this->cms->snippet('admin.button_password_change'),
            ])
            . $this->backend->template('admin.button_devices', [
                '{onclick}' => "cApp.layers.add('Admin/devices.php')",
                '{label}' => $this->cms->snippet('admin.button_devices'),
            ]),
        ]);
        return $this->backend->template('edit_form_static', [
            '{label}' => $this->cms->snippet('admin.field_admin_password_state'),
            '{html}' => $html,
        ]);
    }

    /**
     * Status of the password as text: last changed on …, if applicable "must be changed at the
     * next login" (Admin::passwordChangeReason()).
     */
    private function _passwordStateLabel(int $adminId): string
    {
        $this->db->pushResult();
        $this->db->query("SELECT `password_changed_at` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
        $changed = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->popResult();
        $text = $changed === ''
            ? $this->cms->snippet('admin.text_password_changed_unknown')
            : strtr($this->cms->snippet('admin.text_password_changed_at'), [
                // only the day (UTC) is stored - without conversion to the time zone
                '{date}' => $this->cms->_formatDate(substr($changed, 0, 10) . ' 00:00:00') ?: substr($changed, 0, 10),
            ]);
        $reason = $this->admin->passwordChangeReason($adminId);
        if ($reason !== '') {
            $text .= ' – ' . $this->cms->snippet('admin.text_password_change_' . $reason);
        }
        return $text;
    }

    /**
     * Which second factor applies to this admin (Admin::secondFactor()) - as text,
     * for TOTP with date and number of the remaining recovery codes.
     */
    private function _secondFactorLabel(array $data): string
    {
        $adminId = (int)($data['admin_id'] ?? 0);
        $factor = $this->admin->secondFactor($data);
        if ($factor === null) {
            $text = $this->cms->snippet('admin.option_second_factor_none');
        }
        elseif ($factor->name() === 'webauthn') {
            $count = $this->passkeys->count($adminId);
            $text = $this->cms->snippet('admin.option_second_factor_webauthn', ['count' => $count]);
            if ($this->totpFactor->isActive($adminId)) {
                $text .= ' + ' . strtr($this->cms->snippet('admin.option_second_factor_totp'), ['{date}' => '']);
                $text = str_replace(' ()', '', $text);
            }
            $text .= ' – ' . $this->cms->snippet('admin.text_recovery_left', ['count' => $this->totpFactor->recoveryCount($adminId)]);
        }
        elseif ($factor->name() === 'totp') {
            $state = $this->totpFactor->state($adminId);
            $text = strtr($this->cms->snippet('admin.option_second_factor_totp'), [
                // stored in UTC (like all timestamp columns), shown in the admin's time zone
                '{date}' => $state['enabled_at'] === '' ? ''
                    : $this->backend->backendValue($this->cms->utcToUserTZ($state['enabled_at']), ['type' => 'timestampl'], 'medium'),
            ]) . ' – ' . $this->cms->snippet('admin.text_recovery_left', ['count' => $state['recovery_left']]);
        }
        else {
            $text = $this->cms->snippet('admin.option_second_factor_email');
        }
        if ($this->admin->totpSetupRequired($data)) {
            $text .= ' – ' . $this->cms->snippet('admin.text_totp_required');
        }
        return $text;
    }

    /**
     * The "Widgets" block in the settings form.
     *
     * At the top the widgets already attached, with their slot and a
     * remove button, below that the selection "Widget" + "Position" and the button
     * "Add". Only what is not yet included and what this
     * admin may see is offered - if a superuser sets the settings of another admin,
     * it is the superuser's list; what the other may not use remains on
     * the other's start page later simply empty (Widgets::render()).
     *
     * The list itself travels as a hidden field, so that adding and
     * removing get by without intermediate saving. Adding and removing set
     * 'function' to 'update' and send the form to itself - a button
     * does not transmit its own value (FormData knows no submitter),
     * so the entry to be removed is in a hidden field.
     */
    private function _settingsWidgets(array $settings): string
    {
        $widgets = $this->widgets->normalize((array)($settings[Widgets::SETTINGS_KEY] ?? []), requireExists: false);
        $positions = $this->widgets->positionOptions();

        // The explanation is a note in the block, not a tooltip on the label:
        // formStatic() renders no tooltip, and for a hover the text is too long
        $note = $this->backend->template('remark_block', [
            '{text}' => $this->cms->snippet('admin.note_widgets'),
        ]);

        // Attached widgets
        $rows = '';
        foreach ($widgets as $i => $item) {
            $rows .= $this->backend->template('admin.settings_widget_row', [
                '{i}' => (string)$i,
                '{label}' => htmlspecialchars($this->widgets->label($item['module'])),
                '{position}' => htmlspecialchars((string)($positions[$item['position']] ?? $item['position'])),
            ]);
            // The entry must survive the detour via the form
            $this->backend->formHidden("data[settings][widgets][$i][module]", $item['module']);
            $this->backend->formHidden("data[settings][widgets][$i][position]", $item['position']);
        }
        $list = $note . ($rows === ''
            ? $this->backend->template('admin.settings_widgets_none')
            : $this->backend->template('admin.settings_widget_table', ['{table_rows}' => $rows]));

        // Selection "Widget": everything not yet attached
        $used = array_column($widgets, 'module');
        $moduleOptions = $this->widgets->options($used);
        if (!$moduleOptions) return $list . $this->backend->template('admin.settings_widgets_all_used');

        return $list . $this->backend->template('admin.settings_widgets_add', [
            '{module_options}' => $this->_settingsWidgetOptions($moduleOptions),
            '{position_options}' => $this->_settingsWidgetOptions($positions),
        ]);
    }

    /**
     * Options of a selection list of the widget block. Values are module names or
     * slots; both are escaped anyway.
     */
    private function _settingsWidgetOptions(array $options): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . htmlspecialchars((string)$value) . '">'
                . htmlspecialchars((string)$label) . "</option>\n";
        }
        return $html;
    }

    /**
     * The widget list from the request, with the desired addition or
     * removal - see _settingsWidgets().
     *
     * A new widget goes to the end of its slot, because the list is the
     * order on the start page. Nonsensical input (module already in it,
     * unbekannter Platz) fallen in Widgets::normalize() heraus.
     *
     * @param array $input  data[settings] from the request
     * @return array        list of ['module' => string, 'position' => int]
     */
    private function _settingsWidgetsFromRequest(array $input): array
    {
        $list = $this->widgets->normalize((array)($input[Widgets::SETTINGS_KEY] ?? []), requireExists: false);

        // What to do is said by the hidden field that the respective button sets - NOT
        // the content of the selection lists: those also stand on a
        // module when saving, and "Save" must not add a widget.
        $action = (string)($_REQUEST['widget_action'] ?? '');

        if ($action === 'remove') {
            $remove = (string)($_REQUEST['widget_remove'] ?? '');
            if ($remove !== '' && isset($list[(int)$remove])) {
                unset($list[(int)$remove]);
                $list = array_values($list);
            }
        }
        elseif ($action === 'add') {
            $module = trim((string)($_REQUEST['widget_add_module'] ?? ''));
            $position = (int)($_REQUEST['widget_add_position'] ?? 0);
            if ($module !== '' && $position > 0) {
                // To the end of the slot - the list is the order on the start page
                $list[] = ['module' => $module, 'position' => $position];
                $list = $this->widgets->normalize($list, requireExists: false);
            }
        }
        return $list;
    }

    // ====================
    // Own password, devices and sessions

    /**
     * Change own password (backend/password.php): previous, new, repeat.
     * The new one must meet the policy and differ from the previous one
     * (Admin::changePassword()); afterwards the other sessions of the account end
     * (Admin::hookOnSaved()) and the admin receives an email.
     */
    public function password(): void
    {
        $id = (int)$this->session->get('admin_id');
        $function = (string)($_POST['function'] ?? '');

        $this->backend->setNaviLabel('#admin.headline_password');
        echo $this->backend->template('container_header');
        echo $this->backend->headline('#admin.headline_password');

        $data = $id > 0 ? $this->admin->load($id) : null;
        if (!$data) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        $changed = false;
        if ($function === 'save' && $this->backend->requirePost()) {
            $input = (array)($_POST['data'] ?? []);
            if ($this->_passwordCurrentOk($id, (string)($input['password_current'] ?? ''))
                && $this->admin->changePassword($id, (string)($input['password'] ?? ''), (string)($input['password_repeat'] ?? ''))
            ) {
                $this->loginDevices->notify('password_changed', 'admin', $data, $this->_eventContext());
                $this->backend->balloon('success', $this->cms->snippet('admin.message_password_saved'));
                $this->backend->script("cApp.layers.invalidate(); cApp.layers.close();");
                echo $this->backend->template('container_footer');
                return;
            }
            $changed = true;
            $this->_editErrors();
        }

        echo '<form action="Admin/password.php" method="post"' . ($changed ? ' data-changed="true"' : '') . '>';
        $this->backend->formInit('admin.password_form');
        $this->backend->formField($this->admin, [
            'name' => 'password_current',
            'value' => '',
            'id' => $id,
            'colDef' => [
                'type' => 'password',
                'label' => '#admin.field_admin_password_current',
                'display_type' => 'password',
            ],
            'attr' => ['autocomplete' => 'current-password'],
        ]);
        // New password with the Password extension (strength, policy) from the schema
        $this->backend->formField($this->admin, [
            'name' => 'password',
            'value' => '',
            'id' => $id,
            'colDef' => array_replace($this->admin->getSchema()['password'], ['label' => '#admin.field_admin_password_new']),
            'attr' => ['autocomplete' => 'new-password'],
        ]);
        $this->backend->formField($this->admin, [
            'name' => 'password_repeat',
            'value' => '',
            'id' => $id,
            'colDef' => [
                'type' => 'password',
                'label' => '#admin.field_admin_password_repeat',
                'display_type' => 'password',
            ],
            'attr' => ['autocomplete' => 'new-password'],
        ]);
        $this->backend->formHidden('function', 'save');
        echo $this->backend->form('form_save_cancel');
        echo '</form>';
        echo $this->backend->template('container_footer');
    }

    /**
     * Login devices and sessions (backend/devices.php), developer docs, chapter 13 (Security: authentication).
     *
     * Own account (without id):
     *     ''            list of devices with their running sessions
     *     remove        POST: remove a device - its sessions end with
     *                   (admin_session.device_id); if it is this device, the
     *                   page reloads and asks for login
     *     end_others    POST: end all other sessions (including those without a device)
     * Foreign account (id, superuser only), tab of the detail view:
     *     ''                Liste
     *     remove_all_form   confirmation prompt
     *     remove_all        POST: remove all devices and end all sessions
     *
     * A removed device counts as new at the next login - with a notice by
     * email. Entries in the audit log under the account ('devices').
     */
    public function devices(): void
    {
        $sessionAdminId = (int)$this->session->get('admin_id');
        $id = (int)($_REQUEST['id'] ?? 0);
        $isOwn = ($id < 1 || $id === $sessionAdminId);
        if ($isOwn) $id = $sessionAdminId;
        $function = (string)($_REQUEST['function'] ?? '');

        $this->backend->setNaviLabel('#admin.headline_devices');
        echo $this->backend->template('container_header');

        $data = (!$isOwn && !$this->backend->isSuperuser()) ? null : $this->admin->load($id);
        if (!$data) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        if ($isOwn) {
            echo $this->backend->headline('#admin.headline_devices');
        }
        else {
            echo $this->backend->headline("{$this->iconAdmin} " . htmlspecialchars((string)$data['name']));
            echo $this->_infoTabs($id, 'devices');
        }

        $writing = in_array($function, ['remove', 'end_others', 'remove_all'], true);
        if ($writing && !$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $currentDevice = (int)$this->session->get('device_id');
        $before = count($this->loginDevices->listFor('admin', $id));

        if ($isOwn && $function === 'remove') {
            $deviceId = (int)($_POST['device_id'] ?? 0);
            if ($this->loginDevices->remove('admin', $id, $deviceId)) {
                $this->_devicesAudit($data, $before);
                if ($deviceId === $currentDevice) {
                    // The own session was attached to this device and is thereby ended -
                    // reloading leads to the login
                    $this->backend->script("window.location.reload();");
                    echo $this->backend->template('container_footer');
                    return;
                }
                $this->backend->balloon('success', $this->cms->snippet('admin.message_device_removed'));
            }
        }
        if ($isOwn && $function === 'end_others') {
            // Release the edit locks of the other sessions - as on logout
            // on all devices (StartBackend::_logout()), as long as the assignment
            // of the working copies to the sessions still exists
            $this->record->cancelOtherSessions((string)$this->session->get('session_id'));
            $count = $this->session->closeAllOf($id, keepCurrent: true);
            $this->backend->balloon('success', $this->cms->snippet('admin.message_sessions_ended', ['count' => $count]));
        }
        if (!$isOwn && $function === 'remove_all') {
            $this->loginDevices->removeAll('admin', $id);
            $this->session->closeAllOf($id);
            $this->_devicesAudit($data, $before);
            $this->backend->balloon('success', $this->cms->snippet('admin.message_devices_removed'));
        }
        if (!$isOwn && $function === 'remove_all_form') {
            echo $this->backend->template('admin.devices_remove_all_form', ['{id}' => (string)$id]);
            echo $this->backend->template('container_footer');
            return;
        }

        echo $this->backend->template('remark_block', ['{text}' => $this->cms->snippet($isOwn ? 'admin.note_devices' : 'admin.note_devices_other')]);
        echo $this->_devicesTable($id, $isOwn, $currentDevice);

        $this->backend->buttonBarReset();
        if ($isOwn) {
            $this->backend->buttonAdd('button_secondary', "cApp.layers.replacePost('Admin/devices.php?function=end_others')", $this->cms->snippet('admin.button_sessions_end_others'));
        }
        elseif ($this->loginDevices->listFor('admin', $id) || $this->session->activeSessionsOf($id)) {
            $this->backend->buttonAdd('button_secondary', "cApp.layers.replace('Admin/devices.php?id={$id}&function=remove_all_form')", $this->cms->snippet('admin.button_devices_remove_all'));
        }
        $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        echo $this->backend->buttonBar();
        echo $this->backend->template('container_footer');
    }

    /**
     * Table of devices with the number of their running sessions; sessions without a
     * device (created before device management or via CLI) as their own row.
     */
    private function _devicesTable(int $id, bool $isOwn, int $currentDevice): string
    {
        $devices = $this->loginDevices->listFor('admin', $id);
        $sessionsPerDevice = [];
        foreach ($this->session->activeSessionsOf($id) as $session) {
            $sessionsPerDevice[$session['device_id']] = ($sessionsPerDevice[$session['device_id']] ?? 0) + 1;
        }
        if (!$devices && empty($sessionsPerDevice[0])) {
            return $this->backend->template('admin.devices_none');
        }
        $date = fn(string $utc) => $utc === '' ? '' : $this->backend->backendValue($this->cms->utcToUserTZ($utc), ['type' => 'timestampl'], 'medium');
        $rows = '';
        foreach ($devices as $device) {
            $deviceId = (int)$device['device_id'];
            $isCurrent = $isOwn && $deviceId === $currentDevice;
            $button = '';
            if ($isOwn) {
                $button = $this->backend->template('admin.devices_remove_form', [
                    '{function}' => 'remove',
                    '{id}' => '',
                    '{device_id}' => (string)$deviceId,
                    '{style}' => $isCurrent ? 'secondary' : 'secondary',
                    '{button}' => $this->cms->snippet($isCurrent ? 'admin.button_device_remove_current' : 'admin.button_device_remove'),
                ]);
            }
            $rows .= $this->backend->template('admin.devices_row', [
                '{label}' => htmlspecialchars((string)$device['label']),
                '{current}' => $isCurrent ? $this->backend->template('admin.badge_device_current') : '',
                '{ip}' => htmlspecialchars(ClientIp::display((string)$device['ip'], ClientIp::v4Octets($this->config))),
                '{created_at}' => $date((string)$device['created_at']),
                '{last_seen_at}' => $date((string)$device['last_seen_at']),
                '{sessions}' => (string)($sessionsPerDevice[$deviceId] ?? 0),
                '{button}' => $button,
            ]);
        }
        if (!empty($sessionsPerDevice[0])) {
            $rows .= $this->backend->template('admin.devices_row', [
                '{label}' => $this->cms->snippet('admin.label_device_unknown'),
                '{current}' => ($isOwn && $currentDevice === 0) ? $this->backend->template('admin.badge_device_current') : '',
                '{ip}' => '', '{created_at}' => '', '{last_seen_at}' => '',
                '{sessions}' => (string)$sessionsPerDevice[0],
                '{button}' => '',
            ]);
        }
        return $this->backend->template('admin.devices_table', ['{table_rows}' => $rows]);
    }

    /** Audit log: number of devices before/after */
    private function _devicesAudit(array $data, int $before): void
    {
        $after = count($this->loginDevices->listFor('admin', (int)$data['admin_id']));
        $this->auditLog->write('update', 'admin', (int)$data['admin_id'], (string)$data['name'], [[
            'k' => 'devices', 'l' => '#admin.field_admin_devices', 't' => 'chars', 'g' => '',
            'b' => (string)$before, 'a' => (string)$after,
        ]]);
    }

    // ====================
    // Zwei-Faktor-Anmeldung per Authenticator-App (TotpFactor)

    /**
     * Two-factor login (backend/totp.php), developer docs, chapter 13 (Security: authentication).
     *
     * Own account (without id) - state machine via 'function':
     *     ''               status: active since, remaining recovery codes, buttons
     *     setup            QR code + secret + code field (setup token as hidden field,
     *                      nothing is saved yet)
     *     enable           POST: first code from the app -> TOTP on, show codes
     *     recovery_form /
     *     recovery         POST: neuer Satz Wiederherstellungscodes
     *     disable_form /
     *     disable          POST: switch off TOTP (not if it is mandatory)
     *     email_switch_form /
     *     email_switch     POST: switch from the app to the code by email (code from
     *                      the app confirms; not if TOTP is mandatory)
     *     email_on_form /
     *     email_on         POST: enable the code by email as own choice, if it
     *                      does not apply to everyone anyway (admin.2_factor) - confirmed
     *                      with a code that comes by email in the process
     *     email_off_form /
     *     email_off        POST: switch off the own choice again (likewise confirmed)
     * The writing steps require a valid code from the app (or a
     * recovery code); every wrong one counts in the LoginGuard like a wrong
     * password - a hijacked session must be able neither to switch off the factor nor to
     * guess it.
     *
     * Foreign account (id, superuser only), tab of the detail view:
     *     ''          Stand
     *     reset_form  confirmation prompt
     *     reset       POST: reset the admin's TOTP - audit log and email to them
     */
    public function totp(): void
    {
        $sessionAdminId = (int)$this->session->get('admin_id');
        $id = (int)($_REQUEST['id'] ?? 0);
        $isOwn = ($id < 1 || $id === $sessionAdminId);
        if ($isOwn) $id = $sessionAdminId;
        $function = (string)($_REQUEST['function'] ?? '');

        $this->backend->setNaviLabel('#admin.headline_totp');
        echo $this->backend->template('container_header');

        $data = (!$isOwn && !$this->backend->isSuperuser()) ? null : $this->admin->load($id);
        if (!$data) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        if ($isOwn) {
            echo $this->backend->headline('#admin.headline_totp');
        }
        else {
            echo $this->backend->headline("{$this->iconAdmin} " . htmlspecialchars((string)$data['name']));
            echo $this->_infoTabs($id, 'totp');
        }

        // Passkeys independent of security.admin_2fa (can be switched off with security.webauthn)
        if ($isOwn && str_starts_with($function, 'passkey_')) {
            $writing = in_array($function, ['passkey_options', 'passkey_add', 'passkey_remove', 'passkey_recovery'], true);
            if ($writing && !$this->backend->requirePost()) {
                echo $this->backend->access()->deniedHtml();
                echo $this->backend->template('container_footer');
                return;
            }
            if ($this->_passkeyStep($data, $function)) {
                echo $this->backend->template('container_footer');
                return;
            }
            $function = '';
        }

        if (!$this->totpFactor->available()) {
            echo $this->backend->template('alert_warning', ['{text}' => $this->cms->snippet('admin.error_totp_unavailable')]);
            $this->_totpStatus($data, $isOwn);
            echo $this->backend->template('container_footer');
            return;
        }

        // Writing steps only via POST (developer docs, chapter 14 (Security: web)); likewise the steps
        // that send a code by email
        $writing = in_array($function, ['enable', 'recovery', 'disable', 'reset', 'email_switch',
            'email_on_form', 'email_on', 'email_off_form', 'email_off'], true);
        if ($writing && !$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $active = $this->totpFactor->isActive($id);

        if (!$isOwn) {
            // Reset: app including passkeys (WebAuthnFactor::reset())
            $resettable = $active || $this->passkeys->count($id) > 0;
            if ($function === 'reset' && $resettable) {
                $this->webAuthnFactor->reset($data);
                $this->backend->balloon('success', $this->cms->snippet('admin.message_totp_reset_done'));
                $resettable = false;
                $function = '';
            }
            if ($function === 'reset_form' && $resettable) {
                echo $this->backend->template('admin.totp_reset_form', ['{id}' => (string)$id]);
            }
            else {
                $this->_totpStatus($data, false);
            }
            echo $this->backend->template('container_footer');
            return;
        }

        $code = (string)($_POST['code'] ?? '');
        switch ($function) {
            case 'setup':
                if ($active) break;
                $this->_totpSetupForm($this->totpFactor->newSetup($data));
                echo $this->backend->template('container_footer');
                return;

            case 'enable':
                if ($active) break;
                $token = (string)($_POST['token'] ?? '');
                $setup = $this->totpFactor->setupFromToken($data, $token);
                if ($setup === null) {
                    // Token abgelaufen (TotpFactor::SETUP_SECONDS) - neuer QR-Code
                    echo $this->backend->template('alert_danger', ['{text}' => $this->cms->snippet('admin.error_totp_setup_expired')]);
                    $this->_totpSetupForm($this->totpFactor->newSetup($data));
                    echo $this->backend->template('container_footer');
                    return;
                }
                $codes = $this->_totpGuarded($data, fn() => $this->totpFactor->enable($data, $token, $code));
                if ($codes === null) {
                    $this->_editErrors();
                    $this->_totpSetupForm($setup);
                    echo $this->backend->template('container_footer');
                    return;
                }
                $this->_totpCodesDone('admin.message_totp_setup_done', $codes);
                echo $this->backend->template('container_footer');
                return;

            case 'recovery_form':
            case 'disable_form':
                $disable = $function === 'disable_form';
                // Mandatory (totp_required for superusers): cannot be switched off
                if (!$active || ($disable && $this->totpFactor->requiredFor((int)$data['group_id']))) break;
                $this->_totpCodeForm($disable ? 'disable' : 'recovery');
                echo $this->backend->template('container_footer');
                return;

            case 'recovery':
                if (!$active) break;
                $ok = $this->_totpGuarded($data, fn() => $this->totpFactor->verify($data, $code) ?: null);
                if ($ok === null) {
                    $this->_editErrors();
                    $this->_totpCodeForm('recovery');
                    echo $this->backend->template('container_footer');
                    return;
                }
                $this->_totpCodesDone('admin.message_recovery_regenerated', $this->totpFactor->regenerateRecoveryCodes($data));
                echo $this->backend->template('container_footer');
                return;

            case 'disable':
                // Mandatory (totp_required for superusers): cannot be switched off
                if (!$active || $this->totpFactor->requiredFor((int)$data['group_id'])) break;
                $ok = $this->_totpGuarded($data, fn() => $this->totpFactor->verify($data, $code) ?: null);
                if ($ok === null) {
                    $this->_editErrors();
                    $this->_totpCodeForm('disable');
                    echo $this->backend->template('container_footer');
                    return;
                }
                $this->totpFactor->disable($data);
                $this->backend->balloon('success', $this->cms->snippet('admin.message_totp_switched_off'));
                break;

            case 'email_switch_form':
                if (!$active || $this->totpFactor->requiredFor((int)$data['group_id'])) break;
                if (!$this->_emailAddressOk($data)) break;
                $this->_totpCodeForm('email_switch');
                echo $this->backend->template('container_footer');
                return;

            case 'email_switch':
                // From the app back to the code by email - confirmed with a code from
                // the app (or recovery code), as switching off
                if (!$active || $this->totpFactor->requiredFor((int)$data['group_id'])) break;
                if (!$this->_emailAddressOk($data)) break;
                $ok = $this->_totpGuarded($data, fn() => $this->totpFactor->verify($data, $code) ?: null);
                if ($ok === null) {
                    $this->_editErrors();
                    $this->_totpCodeForm('email_switch');
                    echo $this->backend->template('container_footer');
                    return;
                }
                $this->totpFactor->disable($data);
                $this->admin->setEmailFactor($id, true);
                $this->backend->balloon('success', $this->cms->snippet('admin.message_email_factor_enabled'));
                break;

            case 'email_on_form':
            case 'email_off_form':
                $on = $function === 'email_on_form';
                if (!$this->_emailFactorSwitchable($id, $active, $on)) break;
                if (!$this->_emailAddressOk($data) || !$this->_emailCodeSend($data)) {
                    $this->_editErrors();
                    break;
                }
                echo $this->backend->template('admin.email_code_form', [
                    '{text}' => $this->cms->snippet($on ? 'admin.text_email_factor_on' : 'admin.text_email_factor_off'),
                    '{function}' => $on ? 'email_on' : 'email_off',
                    '{style}' => $on ? 'primary' : 'warning',
                    '{button}' => $this->cms->snippet($on ? 'admin.button_email_factor_on' : 'admin.button_email_factor_off'),
                ]);
                $this->backend->script("document.getElementById('totp-code')?.focus();");
                echo $this->backend->template('container_footer');
                return;

            case 'email_on':
            case 'email_off':
                $on = $function === 'email_on';
                if (!$this->_emailFactorSwitchable($id, $active, $on)) break;
                $ok = $this->_totpGuarded($data, fn() => $this->_emailCodeOk($id, $code) ?: null,
                    'admin.error_email_code', 'admin.label_email_code');
                if ($ok === null) {
                    // The code is used up with the attempt - new try via the button
                    $this->_editErrors();
                    break;
                }
                $this->admin->setEmailFactor($id, $on);
                $this->auditLog->write('update', 'admin', $id, (string)$data['name'], [[
                    'k' => 'second_factor', 'l' => '#admin.field_admin_second_factor', 't' => 'chars', 'g' => '',
                    'b' => $on ? '' : 'email', 'a' => $on ? 'email' : '',
                ]]);
                $this->loginDevices->notify($on ? 'email_factor_on' : 'email_factor_off', 'admin', $data, $this->_eventContext());
                $this->backend->balloon('success', $this->cms->snippet($on ? 'admin.message_email_factor_enabled' : 'admin.message_email_factor_disabled'));
                break;
        }

        $this->_totpStatus($data, true);
        echo $this->backend->template('container_footer');
    }

    /**
     * Code check with the LoginGuard of the own name: blocked -> do not even
     * check; failure -> record a failed attempt; success -> clear the counter.
     *
     * @param callable $check  returns a value on success, otherwise null
     * @return mixed           result of $check or null (errors in Cms::errors)
     */
    private function _totpGuarded(array $data, callable $check, string $errorSnippet = 'admin.error_totp_code',
        string $labelSnippet = 'admin.label_totp_code'): mixed
    {
        // Key as at login: on a known device a separate counter
        $name = $this->admin->guardKey((string)$data['name'], (int)$data['admin_id']);
        $label = $this->cms->snippet($labelSnippet);
        if ($blocked = $this->loginGuard->blockedFor($name)) {
            $this->cms->error($this->cms->snippet('admin.error_login_blocked', ['minutes' => (int)ceil($blocked / 60)]), 'code', $label);
            return null;
        }
        $result = $check();
        if ($result === null) {
            $this->loginGuard->recordFailure($name);
            $this->cms->error($this->cms->snippet($errorSnippet), 'code', $label);
            return null;
        }
        $this->loginGuard->clear($name);
        return $result;
    }

    /**
     * Can the code by email be switched on ($on) or off as an own choice?
     * Not while the app is active (use email_switch for that) and not if it already applies
     * to everyone (admin.2_factor).
     */
    private function _emailFactorSwitchable(int $id, bool $totpActive, bool $on): bool
    {
        if ($totpActive || $this->config->get('admin.2_factor')) return false;
        return $this->admin->emailFactorChosen($id) !== $on;
    }

    /**
     * Does the admin have an email address? Otherwise an error message (without an address
     * no code would arrive - the account would be locked).
     */
    private function _emailAddressOk(array $data): bool
    {
        if (trim((string)($data['email'] ?? '')) !== '') return true;
        $this->cms->error($this->cms->snippet('admin.error_email_factor_no_address'));
        $this->_editErrors();
        return false;
    }

    /**
     * Send confirmation code by email (EmailFactor::start() writes it into
     * admin.code); the time window admin.2_factor_expiration runs from uid_date.
     */
    private function _emailCodeSend(array $data): bool
    {
        try {
            if (!$this->emailFactor->start($data)) return false;
        }
        catch (\Throwable $e) {
            $this->cms->error($this->cms->snippet('start.error_login_mail_failed'));
            return false;
        }
        $this->db->query("UPDATE `admin` SET `uid_date` = UTC_TIMESTAMP() WHERE `admin_id` = ?", 'i', [(int)$data['admin_id']]);
        return true;
    }

    /**
     * Check the code from the email - one attempt, after which it is used up.
     */
    private function _emailCodeOk(int $adminId, string $code): bool
    {
        $seconds = (int)$this->config->get('admin.2_factor_expiration');
        $this->db->query(
            "SELECT `code` FROM `admin` WHERE `admin_id` = ? AND `uid_date` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)",
            'ii', [$adminId, $seconds]
        );
        $stored = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->query("UPDATE `admin` SET `code` = '' WHERE `admin_id` = ?", 'i', [$adminId]);
        $code = trim($code);
        return $stored !== '' && $code !== '' && hash_equals($stored, $code);
    }

    /** Device, IP and time for a notification (LoginNotifier) */
    private function _eventContext(): array
    {
        return $this->loginDevices->context();
    }

    /**
     * Status of the two-factor login and the possible steps as buttons.
     */
    private function _totpStatus(array $data, bool $isOwn): void
    {
        $id = (int)$data['admin_id'];
        $state = $this->totpFactor->state($id);
        $required = $this->totpFactor->requiredFor((int)$data['group_id']);

        echo $this->backend->template('remark_block', ['{text}' => $this->cms->snippet('admin.note_totp')]);

        $this->backend->infoStatic('#admin.field_admin_second_factor', htmlspecialchars($this->_secondFactorLabel($data)));
        if ($state['active']) {
            $this->backend->infoStatic('#admin.field_admin_recovery_codes',
                htmlspecialchars($this->cms->snippet('admin.text_recovery_left', ['count' => $state['recovery_left']])));
        }
        echo $this->backend->infoTable();

        $available = $this->totpFactor->available();
        $emailForAll = (bool)$this->config->get('admin.2_factor');
        $this->backend->buttonBarReset();
        if ($isOwn && $available) {
            if (!$state['active']) {
                $this->backend->buttonAdd('button_secondary', "cApp.layers.replace('Admin/totp.php?function=setup')", $this->cms->snippet('admin.button_totp_setup'));
            }
            else {
                $this->backend->buttonAdd('button_secondary', "cApp.layers.replace('Admin/totp.php?function=recovery_form')", $this->cms->snippet('admin.button_recovery_regenerate'));
                if (!$required) {
                    // Way back to the code by email; "Switch off" only if afterwards not
                    // the email code applies anyway (then it would be the same button)
                    $this->backend->buttonAdd('button_secondary', "cApp.layers.replace('Admin/totp.php?function=email_switch_form')", $this->cms->snippet('admin.button_email_switch'));
                    if (!$emailForAll) {
                        $this->backend->buttonAdd('button_secondary', "cApp.layers.replace('Admin/totp.php?function=disable_form')", $this->cms->snippet('admin.button_totp_disable'));
                    }
                }
            }
        }
        // Code by email as own choice (only if it does not apply to everyone anyway)
        if ($isOwn && !$state['active'] && !$emailForAll) {
            $chosen = $this->admin->emailFactorChosen($id);
            $this->backend->buttonAdd($chosen ? 'button_danger' : 'button_secondary',
                "cApp.layers.replacePost('Admin/totp.php?function=" . ($chosen ? 'email_off_form' : 'email_on_form') . "')",
                $this->cms->snippet($chosen ? 'admin.button_email_factor_off' : 'admin.button_email_factor_on'));
        }
        $passkeys = $this->passkeys->forAdmin($id);
        if (!$isOwn && ($state['active'] || $passkeys)) {
            $this->backend->buttonAdd('button_danger', "cApp.layers.replace('Admin/totp.php?id={$id}&function=reset_form')", $this->cms->snippet('admin.button_totp_reset'));
        }
        $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        echo $this->backend->buttonBar();

        $this->_passkeyList($id, $passkeys, $isOwn);
    }

    // ====================
    // Passkeys (WebAuthnFactor, developer docs, chapter 13 (Security: authentication))

    /**
     * Section "Passkeys" under the second factor: list, for the own account with
     * "Remove" and "Add passkey". Without a fixed backend domain only a
     * note (WebAuthn binds every key to a domain).
     */
    private function _passkeyList(int $id, array $passkeys, bool $isOwn): void
    {
        echo $this->backend->template('headline2_mt', ['{text}' => $this->cms->snippet('admin.headline_passkeys')]);
        if (!$this->webAuthnFactor->available()) {
            echo $this->backend->template('remark_block', ['{text}' => $this->cms->snippet('admin.note_passkeys_unavailable')]);
            return;
        }
        echo $this->backend->template('remark_block', ['{text}' => $this->cms->snippet('admin.note_passkeys')]);
        $date = fn(string $utc) => $utc === '' ? '–'
            : htmlspecialchars($this->backend->backendValue($this->cms->utcToUserTZ($utc), ['type' => 'timestampl'], 'medium'));
        if (!$passkeys) {
            echo $this->backend->template('admin.passkeys_none');
        }
        else {
            $rows = '';
            foreach ($passkeys as $passkey) {
                $rows .= $this->backend->template('admin.passkeys_row', [
                    '{label}' => htmlspecialchars($passkey['label'] !== '' ? $passkey['label'] : $this->cms->snippet('admin.label_passkey_unnamed')),
                    '{created_at}' => $date($passkey['created_at']),
                    '{last_used_at}' => $date($passkey['last_used_at']),
                    '{button}' => $isOwn ? $this->backend->template('admin.passkeys_remove_button', [
                        '{passkey_id}' => (string)$passkey['passkey_id'],
                    ]) : '',
                ]);
            }
            echo $this->backend->template('admin.passkeys_table', ['{table_rows}' => $rows]);
        }
        if ($isOwn) {
            $this->backend->buttonBarReset();
            if (count($passkeys) < Passkeys::MAX_PER_ADMIN) {
                $this->backend->buttonAdd('button_primary', "cApp.layers.replace('Admin/totp.php?function=passkey_form')", $this->cms->snippet('admin.button_passkey_add'));
            }
            // New recovery codes without the app (the TOTP variant asks for the app code)
            if ($passkeys && !$this->totpFactor->isActive($id)) {
                $this->backend->buttonAdd('button_secondary', "cApp.layers.replace('Admin/totp.php?function=passkey_recovery_form')", $this->cms->snippet('admin.button_recovery_regenerate'));
            }
            echo $this->backend->buttonBar();
        }
    }

    /**
     * Steps function=passkey_*. Only the own account.
     *
     *     passkey_form         Bezeichnung + bisheriges Passwort
     *     passkey_options      (POST) check password -> button that creates the passkey
     *                          on the device (token with challenge in the form)
     *     passkey_add          (POST) check the response and save
     *     passkey_remove_form  confirmation prompt with password
     *     passkey_remove       (POST) entfernen
     *
     * @return bool  true: output done; false: continue with the overview
     */
    private function _passkeyStep(array $data, string $function): bool
    {
        $id = (int)$data['admin_id'];
        if (!$this->webAuthnFactor->available()) return false;
        $label = trim(mb_substr((string)($_POST['label'] ?? ''), 0, 100));
        $passkeyId = (int)($_REQUEST['passkey_id'] ?? 0);

        switch ($function) {
            case 'passkey_form':
                // Pre-fill: browser / system as in the device list ('?' = unknown)
                $device = LoginDevices::label((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
                $this->_passkeyForm($label !== '' ? $label : ($device === '?' ? '' : $device));
                return true;

            case 'passkey_options':
                if (!$this->_passwordCurrentOk($id, (string)($_POST['password_current'] ?? ''))) {
                    $this->_editErrors();
                    $this->_passkeyForm($label);
                    return true;
                }
                $start = $this->webAuthnFactor->registrationStart($data, $label);
                echo $this->backend->template('admin.passkey_create_form', [
                    '{label}' => htmlspecialchars($label),
                    '{token}' => htmlspecialchars($start['token'], ENT_QUOTES),
                    '{options}' => htmlspecialchars(json_encode($start['options'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES),
                ]);
                $this->backend->script("document.getElementById('passkey-create')?.focus();");
                return true;

            case 'passkey_add':
                $result = $this->webAuthnFactor->registrationFinish($data, (string)($_POST['token'] ?? ''),
                    (string)($_POST['client_data'] ?? ''), (string)($_POST['attestation'] ?? ''), (string)($_POST['transports'] ?? ''));
                if (isset($result['error'])) {
                    echo $this->backend->template('alert_danger', ['{text}' => $this->cms->snippet('admin.error_passkey_' . $result['error'])]);
                    return false;
                }
                if ($result['codes']) {
                    $this->_totpCodesDone('admin.message_passkey_saved_codes', $result['codes']);
                    return true;
                }
                $this->backend->balloon('success', $this->cms->snippet('admin.message_passkey_saved'));
                return false;

            case 'passkey_recovery_form':
            case 'passkey_recovery':
                if (!$this->passkeys->count($id) || $this->totpFactor->isActive($id)) return false;
                if ($function === 'passkey_recovery') {
                    if ($this->_passwordCurrentOk($id, (string)($_POST['password_current'] ?? ''))) {
                        $this->_totpCodesDone('admin.message_recovery_regenerated', $this->totpFactor->regenerateRecoveryCodes($data));
                        return true;
                    }
                    $this->_editErrors();
                }
                $this->_passkeyConfirm('admin.text_recovery_regenerate_password', 'passkey_recovery', 0,
                    'primary', 'admin.button_recovery_regenerate');
                return true;

            case 'passkey_remove_form':
                $passkey = $this->_ownPasskey($id, $passkeyId);
                if ($passkey === null) return false;
                $this->_passkeyConfirm('admin.text_passkey_remove', 'passkey_remove', $passkeyId,
                    'warning', 'admin.button_passkey_remove', ['label' => $passkey['label']]);
                return true;

            case 'passkey_remove':
                $passkey = $this->_ownPasskey($id, $passkeyId);
                if ($passkey === null) return false;
                if (!$this->_passwordCurrentOk($id, (string)($_POST['password_current'] ?? ''))) {
                    $this->_editErrors();
                    $this->_passkeyConfirm('admin.text_passkey_remove', 'passkey_remove', $passkeyId,
                        'warning', 'admin.button_passkey_remove', ['label' => $passkey['label']]);
                    return true;
                }
                $this->webAuthnFactor->remove($data, $passkeyId);
                $this->backend->balloon('success', $this->cms->snippet('admin.message_passkey_deleted'));
                return false;
        }
        return false;
    }

    /** Confirmation prompt with the previous password (remove, new recovery codes) */
    private function _passkeyConfirm(string $textSnippet, string $function, int $passkeyId,
        string $style, string $buttonSnippet, array $vars = []): void
    {
        echo $this->backend->template('admin.passkey_confirm_form', [
            // simple placeholders {label} are not replaced by Cms::snippet() (ICU only)
            '{text}' => htmlspecialchars(strtr($this->cms->snippet($textSnippet, $vars),
                array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', $vars)) ?: [])),
            '{function}' => $function,
            '{passkey_id}' => (string)$passkeyId,
            '{style}' => $style,
            '{button}' => $this->cms->snippet($buttonSnippet),
        ]);
        $this->backend->script("document.getElementById('passkey-password')?.focus();");
    }

    private function _passkeyForm(string $label): void
    {
        echo $this->backend->template('admin.passkey_form', ['{label}' => htmlspecialchars($label, ENT_QUOTES)]);
        $this->backend->script("document.getElementById('passkey-password')?.focus();");
    }

    /** Passkey of the own account, null = does not exist */
    private function _ownPasskey(int $adminId, int $passkeyId): ?array
    {
        foreach ($this->passkeys->forAdmin($adminId) as $passkey) {
            if ($passkey['passkey_id'] === $passkeyId) return $passkey;
        }
        return null;
    }

    /**
     * QR code, secret to type in, and the field for the first code.
     *
     * @param array $setup  TotpFactor::newSetup() / setupFromToken()
     */
    private function _totpSetupForm(array $setup): void
    {
        echo $this->backend->template('admin.totp_setup_form', [
            '{uri}' => htmlspecialchars((string)$setup['uri'], ENT_QUOTES),
            '{secret}' => htmlspecialchars((string)$setup['secret'], ENT_QUOTES),
            '{token}' => htmlspecialchars((string)$setup['token'], ENT_QUOTES),
        ]);
        $this->backend->script("cApp.ui.qrCodes(); document.getElementById('totp-code')?.focus();");
    }

    /**
     * Ask for code before 'disable' or 'recovery'.
     */
    private function _totpCodeForm(string $function): void
    {
        $disable = $function === 'disable';
        echo $this->backend->template('admin.totp_code_form', [
            '{text}' => $this->cms->snippet($disable ? 'admin.text_totp_disable' : 'admin.text_recovery_regenerate'),
            '{function}' => $function,
            '{style}' => $disable ? 'warning' : 'primary',
            '{button}' => $this->cms->snippet($disable ? 'admin.button_totp_disable' : 'admin.button_recovery_regenerate'),
        ]);
        $this->backend->script("document.getElementById('totp-code')?.focus();");
    }

    /**
     * Show new recovery codes - only this one time, in plain text.
     */
    private function _totpCodesDone(string $messageSnippet, array $codes): void
    {
        $items = '';
        foreach ($codes as $code) {
            $items .= '<li>' . htmlspecialchars((string)$code) . "</li>\n";
        }
        echo $this->backend->template('admin.totp_codes_done', [
            '{message}' => $this->cms->snippet($messageSnippet),
            '{codes}' => $this->backend->template('admin.totp_recovery_codes', [
                '{items}' => $items,
                '{copy}' => htmlspecialchars(implode("\n", $codes), ENT_QUOTES),
            ]),
        ]);
    }

    /**
     * Create / copy / change / delete (backend/admin_edit.php).
     *
     * State machine via $_REQUEST['function'] - see
     * developer docs, chapter 05 (Backend development), for the meaning of the individual values
     * (create, edit, copy, save, errors, skip, delete, delete_confirm).
     *
     * The sequence is the order of the if blocks: fetch data, heading,
     * writing action, then the matching form. _editSave() sets on errors
     * $this->function to 'errors' and thereby falls into the form branch - therefore
     * no redirect after saving.
     */
    public function edit(): void
    {
        // Seiten-Parameter
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = (string) ($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array) ($_REQUEST['data'] ?? []);
        $this->data['admin_id'] = $this->id;

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

        // Additional rights: delete only superuser; create/copy with admin_create,
        // change with admin_change - both only in the own group (superuser
        // everything). When saving, the stored group of the record counts,
        // not the one from the form.
        if ($this->function !== 'skip' && !$this->_editAllowed()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
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
        if (in_array($this->function, ['errors','skip'], true)) {
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
    // edit() Hilfsfunktionen

    /**
     * May the running edit() function be executed? (see edit())
     */
    private function _editAllowed(): bool
    {
        if ($this->backend->isSuperuser()) return true;
        if (in_array($this->function, ['delete', 'delete_confirm'], true)) return false;
        if ($this->id > 0) {
            // Change (edit, save, errors): group of the stored record
            $this->db->pushResult();
            $this->db->query("SELECT `group_id` FROM `admin` WHERE `admin_id` = ?", 'i', [$this->id]);
            $groupId = (int)($this->db->fetchNum()[0] ?? 0);
            $this->db->popResult();
            return $groupId > 0 && $this->_canChange($groupId);
        }
        // Create (create, copy, save without id): own group
        return $this->_canCreate();
    }

    /**
     * May the form set a password? On creation always (initial password),
     * otherwise only a superuser - changing another admin's password would mean
     * taking over their account. Everyone changes their own password via password.php.
     */
    private function _editPasswordAllowed(): bool
    {
        return $this->id < 1 || $this->backend->isSuperuser();
    }

    /**
     * function=create: empty form with the schema defaults.
     *
     * init() without argument - unlike in AreaBackend, defaults from the URL
     * are not taken over here.
     */
    private function _editCreate(): void
    {
        $this->data = $this->admin->init();
        $this->id = 0;
    }

    /**
     * function=edit|copy|delete: Benutzer laden.
     *
     * When copying, only the id is discarded, so that save() creates a new record.
     * The name initially stays the same and runs into the
     * unique check when saving - so the editor must deliberately change it.
     */
    private function _editLoad(): void
    {
        $this->data = $this->admin->load($this->id);
        if (!$this->data) {
            // Requested record not found
            $this->function = 'skip';
        }
        // If data is to be copied, reset the id now
        if ($this->function == 'copy') {
            $this->id = 0;
        }
    }

    /**
     * Heading of the form. When deleting, the user name stands above it, otherwise
     * the general heading.
     *
     * (The only _edit helper method that is public - for no apparent reason; it is called
     * only from edit().)
     */
    private function _editHeadline() {
        if ($this->function == 'delete') {
            $label = $this->data['name'] ?? '';
        }
        else {
            $label = $this->cms->snippet('admin.headline_admin_info');
        }
        return $this->backend->template('headline1', [
            '{text}' => $this->iconAdmin . ' ' . $label,
        ]);
    }

    /**
     * function=save: speichern.
     *
     * The field list is explicit here - only these columns are written.
     * This way a manipulated form cannot set other columns (such as uid
     * or login_date from the two-factor login).
     *
     * 'ft' is the full-text column; it is in the list so that Dataset rebuilds it when
     * saving from the source fields.
     */
    private function _editSave(): void
    {
        // Non-superuser: the group is fixed to the own one (form value does not count)
        if (!$this->backend->isSuperuser()) {
            $this->data['group_id'] = $this->_ownGroupId();
        }

        // Check data and save
        $fields= [
            'name',
            'password',
            'group_id',
            'primename',
            'displayname',
            'sortname',
            'email',
            'phone',
            'mobile',
            'status_id',
            'ft'
        ];
        
        // Password of an existing account: superuser only (_editPasswordAllowed())
        if (!$this->_editPasswordAllowed()) {
            $fields = array_values(array_diff($fields, ['password']));
            unset($this->data['password']);
        }

        if (!$this->_editPasswordCurrentOk()) {
            $this->function = 'errors';
            return;
        }

        if ($this->admin->save($fields, $this->data)) {
            // Balloon Message
            $msg = $this->cms->snippet('admin.message_admin_saved');
            $this->backend->balloon('success', $msg);

            // Refresh referer and close the window
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
     * Is the admin editing their own record?
     */
    private function _isOwnAccount(): bool
    {
        return $this->id > 0 && $this->id === (int)$this->session->get('admin_id');
    }

    /**
     * New password for an existing account: the field password_current must contain the
     * password of the LOGGED-IN user - for one's own account, therefore the
     * previous one, for another's account (superuser only) one's own. An open
     * session left open must be able to take over neither one's own nor a foreign account
     * (developer docs, chapter 13 (Security: authentication)). Failed attempts count in the LoginGuard like a
     * wrong login - otherwise the password could be guessed via this form.
     * An account without a password (created only via CLI) does not need one;
     * none is needed when creating.
     */
    private function _editPasswordCurrentOk(): bool
    {
        if ($this->id < 1 || trim((string)($this->data['password'] ?? '')) === '') return true;
        return $this->_passwordCurrentOk((int)$this->session->get('admin_id'), (string)($this->data['password_current'] ?? ''),
            ownAccount: $this->_isOwnAccount());
    }

    /**
     * Check the password of account $adminId - with LoginGuard (see
     * _editPasswordCurrentOk()). Errors on field password_current; $ownAccount =
     * false: it concerned another's account (field "Your password").
     */
    private function _passwordCurrentOk(int $adminId, string $current, bool $ownAccount = true): bool
    {
        $this->db->pushResult();
        $this->db->query("SELECT `name`, `password` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
        $row = $this->db->fetch() ?: [];
        $this->db->popResult();
        $stored = (string)($row['password'] ?? '');
        if ($stored === '') return true;

        $guard = $this->loginGuard;
        $name = $this->admin->guardKey((string)($row['name'] ?? ''), $adminId);
        $label = $this->cms->snippet($ownAccount ? 'admin.field_admin_password_current' : 'admin.field_admin_password_own');
        if ($blocked = $guard->blockedFor($name)) {
            $this->cms->error($this->cms->snippet('admin.error_login_blocked', ['minutes' => (int)ceil($blocked / 60)]), 'password_current', $label);
            return false;
        }
        if (!$this->crypt->passwordVerify($current, $stored)) {
            $guard->recordFailure($name);
            $this->cms->error($this->cms->snippet($ownAccount ? 'admin.error_password_current' : 'admin.error_password_own'), 'password_current', $label);
            return false;
        }
        $guard->clear($name);
        return true;
    }

    /**
     * function=delete_confirm: delete.
     *
     * `admin` knows soft delete, so delete() only marks. Important, because
     * record.created_by and record.changed_by point with ON DELETE CASCADE to
     * admin.admin_id - a final delete would take along all records that
     * this user created or last changed.
     */
    private function _editDeleteConfirm(): void
    {
        if ($this->admin->delete($this->id)) {
            // Balloon Message
            $msg = $this->cms->snippet('admin.message_admin_deleted');
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
     * All text fields run through the same loop; form type, label and
     * validation come from the schema of Admin. Only the
     * two selection fields built by hand, whose options cannot be in the schema:
     * group_id (from Backend::getGroups()) and status_id (from the status records).
     *
     * data-changed="true" after a failed save marks the form
     * as changed, so that the JavaScript asks when closing.
     */
    private function _editForm(): void
    {
        if ($this->function == 'save') $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Admin/admin_edit.php" method="post"' . $changed . '>';

        $this->backend->formInit('admin.admin_edit_form');

        // Data fields (password only if it may be set here)
        $dataFields = [
            'password',
            'primename',
            'displayname',
            'sortname',
            'email',
            'phone',
            'mobile'
        ];
        if ($this->data['name'] !== 'scheduler') {
            // Name changeable, except for user 'scheduler'
            array_unshift($dataFields, 'name');
        }
        else {
            $this->backend->formStatic($this->admin, 'name', 'scheduler');
        }

        if (!$this->_editPasswordAllowed()) {
            $dataFields = array_values(array_diff($dataFields, ['password']));
        }
        foreach ($dataFields as $key) {
            $this->backend->formField($this->admin, [
                'name' => $key,
                'value' => $this->data[$key] ?? '',
                'id' => $this->id
            ]);
        }

        // Existing account: a new password only together with the password of the
        // logged-in user - for one's own account the previous one, otherwise one's own
        // (_editPasswordCurrentOk()). A hijacked or left-open session must not be able to
        // take over any account
        if ($this->id > 0 && $this->_editPasswordAllowed()) {
            $own = $this->_isOwnAccount();
            $this->backend->formField($this->admin, [
                'name' => 'password_current',
                'value' => '',
                'id' => $this->id,
                'colDef' => [
                    'type' => 'password',
                    'label' => $own ? '#admin.field_admin_password_current' : '#admin.field_admin_password_own',
                    'display_type' => 'password',
                    'tooltip' => $own ? '#admin.tooltip_password_current' : '#admin.tooltip_password_own',
                ],
                'attr' => ['autocomplete' => 'current-password'],
            ]);
        }
       
        if (!$this->_editPasswordAllowed()) $this->backend->formReplace('{data_password}', '');

        // group_id - only a superuser may select it, otherwise the own one is fixed
        // --------------------
        if ($this->data['name'] === 'scheduler') {
            $groupOptions = [InstanceCache::SUPERUSER_GROUP_ID => $this->cms->snippet('group_label_superuser')];
        }
        else {
            $groupOptions = $this->backend->getGroups();
        }
        if ($this->backend->isSuperuser()) {
            $this->backend->formField($this->admin, [
                'name' => 'group_id',
                'value' => $this->data['group_id'] ?? '',
                'id' => $this->id,
                'options' => $groupOptions
            ]);
        }
        else {
            $ownGroupId = $this->_ownGroupId();
            $this->backend->formStatic($this->admin, 'group_id', htmlspecialchars((string)($groupOptions[$ownGroupId] ?? $ownGroupId)));
        }
        // status_id
        // --------------------
        $statusOptions = [
            0 => $statusText = $this->backend->teaser('status', 0, false, false),
            1 => $statusText = $this->backend->teaser('status', 1, false, false)
        ];
        $this->backend->formField($this->admin, [
            'name' => 'status_id',
            'value' => $this->data['status_id'] ?? '',
            'id' => $this->id,
            'options' => $statusOptions,
            'selectPlease' => false
        ]);

        // function
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $this->id);

        // Print form
        echo $this->backend->form('form_save_cancel_note', removePlaceholders:true);

        echo '</form>';
    }

    /**
     * Really delete? Form
     */
    private function _editDeleteForm(): void
    {
        echo '<form action="Admin/admin_edit.php" method="post">';

        $this->backend->formInit('admin.admin_delete_form');

        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');

        // Print form
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }


    // ====================
    // Suchfunktion


    /**
     * Suche (backend/admin_search.php).
     *
     * Without function the mask, with function=search the execution.
     */
    public function search():void
    {
        $function = (string) ($_POST['function'] ?? '');

        if ($function == 'search') {
            $this->_search();
        }

        if ($function == '') {
            $this->_searchForm();
        }
    }

    /**
     * function=search: run the search.
     *
     * Renders no result list. Instead, the search layer closes and the list layer
     * below is reloaded with the search parameters in the URL;
     * list() evaluates them via Cms::select(['request' => 'all']). As a result,
     * sorting and pagination then work as usual.
     */
    private function _search(): void
    {
        // Parameters from POST
        $queryString = http_build_query($_POST);

        // Wrap safely for JavaScript
        // json_encode escapes < > ' " & as \uXXXX - the string may then be inserted into the
        // JavaScript below.
        $safeParams = json_encode($queryString, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        // json_encode returns the string WITH quotation marks. substr(..., 1) cuts off
        // the opening one and a custom one is prepended - the closing one from
        // json_encode remains and ends the JavaScript literal below. Therefore
        // {$safeUri} stands there without its own quotation marks.
        $safeUri = '"Admin/index.php?' . substr($safeParams, 1);

        // Reload the referer layer with these parameters
        $this->backend->script("
            cApp.layers.close();
            cApp.layers.replace({$safeUri});
        ");
    }

    /**
     * Search form
     */
    private function _searchForm():void
    {
        // Seiten-Parameter
        $filter = (array) ($_REQUEST['filter'] ?? []);

        echo '<form action="Admin/admin_search.php" method="post">';

        // Bread crum navi
        $this->backend->setNaviLabel('#button_search');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#admin.headline_search');

        $this->backend->formInit('admin.admin_search_form');

        $this->backend->searchField($this->admin, ['name' => 'name', 'value' => $filter['name'] ?? '']);
        $this->backend->searchField($this->admin, ['name' => 'primename', 'value' => $filter['primename'] ?? '']);
        $this->backend->searchField($this->admin, ['name' => 'displayname', 'value' => $filter['displayname'] ?? '']);
        $this->backend->searchField($this->admin, ['name' => 'email', 'value' => $filter['email'] ?? '']);
        $this->backend->searchField($this->admin, ['name' => 'phone', 'value' => $filter['phone'] ?? '']);
        $this->backend->searchField($this->admin, ['name' => 'mobile', 'value' => $filter['mobile'] ?? '']);
        $this->backend->searchField($this->admin, ['name' => 'ft', 'value' => $filter['ft'] ?? '']);

        // Groups
        $groupOptions = $this->backend->getGroups();
        $this->backend->searchField($this->admin, [
            'name' => 'group_id', 
            'value' => $filter['group_id'] ?? '',
            'options' => $groupOptions
        ]);

        // status_id
        $statusOptions = [
            0 => $statusText = $this->backend->teaser('status', 0, false, false),
            1 => $statusText = $this->backend->teaser('status', 1, false, false)
        ];
        $this->backend->searchField($this->admin, [
            'name' => 'status_id', 
            'value' => $filter['status_id'],
            'options' => $statusOptions
        ]);

        // Sort, Order
        // Caution: the third key must be called 'status_id' - like the column in the
        // schema. 'status' does not exist there; sorting by it would run into nothing.
        $sortOptions = [
            'name' => $this->cms->snippet('admin.field_admin_name'),
            'primename' => $this->cms->snippet('admin.field_admin_primename'),
            'status_id' => $this->cms->snippet('admin.field_admin_status_id')
        ];
        $this->backend->searchFieldSortOrder($sortOptions);

        // function
        $this->backend->formHidden('function', 'search');
        // Pass on the lookup context (the result list remains a selection list)
        $srcField = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_REQUEST['src_field'] ?? '')));
        if ($srcField !== '') $this->backend->formHidden('src_field', $srcField);

        // Print form
        echo $this->backend->form('form_search_cancel');

        // Container footer
        echo $this->backend->template('container_footer');

        echo '</form>';
    }
}