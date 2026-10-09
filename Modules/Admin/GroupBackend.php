<?php
/**
 * Backend-Oberfläche für die Benutzergruppen (Tabelle `group`) - Reiter "Gruppen"
 * im Modul Admin.
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     group_list.php    -> list()    Gruppenliste (Reiter neben der Admin-Liste)
 *     group_info.php    -> info()    Detailansicht mit allen Rechten
 *     group_edit.php    -> edit()    anlegen / kopieren / ändern / löschen
 *     group_access.php  -> access()  Reiter "Bereichsrechte" (alle Bereiche der Gruppe)
 *
 * NUR FÜR SUPERUSER
 * --------------------
 * Wer Rechte vergibt, muss alles sehen dürfen: die drei Seiten sind für andere
 * Gruppen gesperrt (Backend::isSuperuser(), Meldung admin.error_groups_denied).
 * Die Superuser-Gruppe selbst (1) hat eine Info-Seite, aber weder Formular noch
 * Löschen - Group::hookOnSave()/hookOnDelete() weisen das auch serverseitig ab.
 *
 * DAS FORMULAR
 * --------------------
 *     label         Bezeichnung (Snippet-Verweis erlaubt: "#group_label_editor")
 *     interfaces    je Oberfläche eine Checkbox: Backend, Kommandozeile, PHP-API
 *                   (Access::INTERFACES) - über welche sich Admins der Gruppe
 *                   anmelden dürfen
 *     modules       je gelistetem Modul eine Checkbox, darunter eingerückt die
 *                   Zusatzrechte aus dessen settings.php (Group::availableModules());
 *                   Javascript koppelt die Zusatzrechte an die Modul-Checkbox
 *                   (Vorlagen group_module_row / group_module_right)
 *     instance_ids  je Instanz eine Checkbox - eine neu zugewiesene Instanz gibt
 *                   alle ihre Bereiche und Sprachen frei (Group::hookOnSaved())
 *     languages     je Sprache zwei Checkboxes "lesen" / "schreiben" (plus Hiddenfeld 0,
 *                   damit auch abgewählte Sprachen ankommen); "schreiben" setzt
 *                   "lesen" mit (cApp.ui.groupLanguageRight()), ein Klick auf den
 *                   Spaltenkopf hakt die ganze Spalte an bzw. ab
 *     statuses      je Status eine Checkbox: darf ihn setzen (lesen dürfen alle)
 *
 * Die Bereichsrechte (rel_group_area) haben einen eigenen Reiter "Bereichsrechte"
 * neben "Info" (access(): je Instanz eine Tabelle, Bearbeiten im selben Reiter) -
 * alle Bereiche, eine Gruppe. Der Reiter "Zugriff" eines Bereichs (Modul Area) ist
 * die andere Sicht: alle Gruppen, ein Bereich.
 *
 * @see Camindo\Core\Modules\Admin\Group
 * @see Camindo\Core\Modules\Admin\AdminBackend   Muster der Seiten
 * @see Camindo\Core\Backend
 */
namespace Camindo\Core\Modules\Admin;

use Camindo\Core\Access;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;

class GroupBackend {

    protected string $iconGroup = '';
    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';

    public function __construct(
        private Backend $backend,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private InstanceCache $instanceCache,
        private Group $group
    )
    {
        $this->iconGroup = $this->backend->template('admin.icon_group');
    }

    /**
     * Reiterleiste des Moduls Admin: Administratoren | Gruppen. Den Reiter
     * "Gruppen" sieht nur ein Superuser (auch AdminBackend::list() ruft das).
     *
     * @param string $active  'admins' | 'groups'
     */
    public function moduleTabs(string $active): string
    {
        if (!$this->backend->isSuperuser()) return '';
        $this->backend->tabBarReset();
        $this->backend->tabAdd('#admin.headline_admin_list', "cApp.layers.replace('Admin/admin_list.php')", $active === 'admins');
        $this->backend->tabAdd('#admin.headline_group_list', "cApp.layers.replace('Admin/group_list.php')", $active === 'groups');
        return $this->backend->tabBar();
    }

    /**
     * Zugriff nur für Superuser - sonst Meldung und false.
     */
    private function _allowed(): bool
    {
        if ($this->backend->isSuperuser()) return true;
        echo $this->backend->template('alert_danger', [
            '{text}' => $this->cms->snippet('admin.error_groups_denied')
        ]);
        return false;
    }

    /**
     * Gruppenliste (backend/group_list.php): Bezeichnung, Anzahl Admins, Instanzen.
     */
    public function list(): void
    {
        $this->backend->setNaviLabel('#admin.headline_group_list');
        echo $this->backend->template('container_header');
        if (!$this->_allowed()) {
            echo $this->backend->template('container_footer');
            return;
        }

        echo $this->backend->headline('#admin.headline_group_list');
        echo $this->moduleTabs('groups');

        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Admin/group_edit.php?function=create');");
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        $f = $this->cms->select([
            'request' => 'all',
            'limit' => $this->cms->list_rows,
            'count' => 'true',
        ], $this->group);

        if ($this->db->num_rows > 0) {
            echo $this->backend->paginationTop('admin.listed_groups');

            $schema = $this->group->getSchema();
            $headColumns = $this->backend->template('admin.group_table_head_columns', [
                '{label_label}' => $this->backend->tableHeadColumn($schema['label'], $f, 'label'),
                '{label_admins}' => $this->cms->snippet('admin.table_col_admins'),
                '{label_instances}' => $this->cms->snippet('admin.field_group_instance_ids'),
            ]);

            $instanceLabels = $this->instanceCache->instanceLabels();
            $tplRow = $this->backend->template('admin.group_table_row');
            $tableRows = '';
            // fetch() läuft nicht durch hookOnLoaded() - die Instanzen je Gruppe
            // kommen aus den Gruppendateien des Caches
            while ($data = $this->group->fetch()) {
                $groupId = (int)$data['group_id'];
                $isSuperuser = ($groupId === InstanceCache::SUPERUSER_GROUP_ID);
                $instances = [];
                if ($isSuperuser) {
                    $instances[] = $this->cms->snippet('admin.label_all_instances');
                }
                else {
                    foreach (array_map('intval', (array)($this->instanceCache->group($groupId)['instance_ids'] ?? [])) as $instanceId) {
                        if (isset($instanceLabels[$instanceId])) {
                            $instances[] = $this->cms->textOrSnippet($instanceLabels[$instanceId]);
                        }
                    }
                }
                $tableRows .= strtr($tplRow, [
                    '{data_id}' => $groupId,
                    '{class}' => $isSuperuser ? ' c9-grey-30' : '',
                    '{data_label}' => $this->cms->textOrSnippet((string)$data['label']),
                    '{data_admins}' => (string)$this->group->adminCount($groupId),
                    '{data_instances}' => $instances ? implode(', ', $instances) : $this->backend->emptyPlaceholder,
                ]) . "\n";
            }

            echo $this->backend->template('admin.group_table_with_head', [
                '{head_columns}' => $headColumns,
                '{table_rows}' => $tableRows
            ]);
            echo $this->backend->template('admin.group_context_menu');
            echo $this->backend->paginationBottom();
        }

        echo $this->backend->template('container_footer');
    }

    /**
     * Detailansicht (backend/group_info.php): Bezeichnung, Admins, Module mit
     * Zusatzrechten, Instanzen, Sprachen und - je Instanz - die Bereichsrechte.
     */
    public function info(): void
    {
        $id = (int)($_REQUEST['id'] ?? 0);

        $this->backend->setNaviLabel('#button_info');
        echo $this->backend->template('container_header');
        if (!$this->_allowed()) {
            echo $this->backend->template('container_footer');
            return;
        }

        if (!$data = $this->group->load($id)) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
            echo $this->backend->template('container_footer');
            return;
        }
        $isSuperuser = ($id === InstanceCache::SUPERUSER_GROUP_ID);
        $label = $this->cms->textOrSnippet((string)$data['label']);

        echo $this->backend->headline("{$this->iconGroup} {$label}");
        $auditActive = $this->backend->auditTabActive();
        echo $this->_infoTabs($id, $auditActive ? 'audit' : 'info', $isSuperuser);

        $this->backend->buttonBarReset();
        if (!$isSuperuser) {
            $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Admin/group_edit.php?id={$id}');");
            $this->backend->buttonAdd('button_copy', "cApp.layers.replace('Admin/group_edit.php?id={$id}&function=copy');");
            $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Admin/group_edit.php?id={$id}&function=delete');");
        }
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        echo $this->backend->buttonBar();

        if ($auditActive) {
            echo $this->backend->auditHistory('group', $id);
            echo $this->backend->template('container_footer');
            return;
        }

        // Stammdaten
        // --------------------
        $this->backend->infoReset();
        $this->backend->infoStatic($this->cms->snippet('admin.field_group_label'), $label);
        $adminCount = $this->group->adminCount($id);
        $adminCountLabel = $this->cms->snippet('admin.listed_records',['count' => $adminCount]);
        if ($adminCount > 0) {
            $adminCountLabel = $this->backend->template('link_replace', [
                '{href}' => "Admin/admin_list.php?filter[group_id]={$id}&back=true",
                '{text}' => $adminCountLabel
            ]);
        }
        $this->backend->infoStatic($this->cms->snippet('admin.table_col_admins'), $adminCountLabel);
        if ($isSuperuser) {
            $this->backend->infoStatic($this->cms->snippet('admin.field_group_interfaces'), $this->cms->snippet('admin.label_superuser_all'), ['html']);
            $this->backend->infoStatic($this->cms->snippet('admin.field_group_modules'), $this->cms->snippet('admin.label_superuser_all'), ['html']);
            $this->backend->infoStatic($this->cms->snippet('admin.field_group_instance_ids'), $this->cms->snippet('admin.label_all_instances'));
            $this->backend->infoStatic($this->cms->snippet('admin.field_group_languages'), $this->cms->snippet('admin.label_superuser_all'), ['html']);
            $this->backend->infoStatic($this->cms->snippet('admin.field_group_statuses'), $this->cms->snippet('admin.label_superuser_all'), ['html']);
            echo $this->backend->infoTable();
            echo $this->backend->template('container_footer');
            return;
        }

        // Oberflächen
        $items = array_map(
            fn($interface) => $this->cms->snippet('admin.label_interface_' . $interface),
            Access::normalizeInterfaces(isset($data['details']['interfaces']) ? (array)$data['details']['interfaces'] : null)
        );
        $this->backend->infoStatic(
            $this->cms->snippet('admin.field_group_interfaces'),
            $items ? implode(', ', $items) : $this->backend->emptyPlaceholder
        );

        // Module
        $items = [];
        $granted = (array)($data['details']['modules'] ?? []);
        $modules = $this->group->availableModules();
        foreach ($this->group->availableModules() as $name => $module) {
            if (!array_key_exists($name, $granted)) continue;
            $text = $this->cms->textOrSnippet($module['label']);
            $rights = [];
            foreach ($module['access'] as $right => $snippet) {
                if (in_array($right, (array)$granted[$name], true)) $rights[] = $this->cms->snippet($snippet);
            }
            if ($rights) $text .= ' <span class="c9-grey-50">(' . implode(', ', $rights) . ')</span>';
            $items[] = $text;
        }
        $this->backend->infoStatic(
            $this->cms->snippet('admin.field_group_modules'),
            $items ? $this->backend->template('ul', ['{items}' => '<li>' . implode("</li>\n<li>", $items) . '</li>']) : $this->backend->emptyPlaceholder,
            ['html']
        );

        // Instanzen (in Backend-Reihenfolge)
        $instanceIds = array_values(array_intersect(
            array_keys($this->instanceCache->instanceLabels()),
            array_map('intval', (array)($data['instance_ids'] ?? []))
        ));
        $items = [];
        foreach ($instanceIds as $instanceId) {
            $teaser = $this->backend->teaser('instance', $instanceId);
            if ($teaser !== '') $items[] = $teaser;
        }
        $this->backend->infoStatic(
            $this->cms->snippet('admin.field_group_instance_ids'),
            $items ? implode('<br>', $items) : $this->backend->emptyPlaceholder,
            ['html']
        );

        // Sprachen
        $items = [];
        foreach ($this->_languages() as $languageId => $language) {
            $level = (int)($data['languages'][$languageId] ?? Group::LANGUAGE_NONE);
            if ($level < Group::LANGUAGE_READ) continue;
            // (kein teaser(): language_id 0 ist eine gültige Sprache, teaser() gibt für 0 nichts aus)
            $items[] = $this->cms->textOrSnippet((string)$language['label']) . ' (' . $language['code'] . ')'
                . ' <span class="c9-grey-50">' . $this->cms->snippet('admin.label_language_' . ($level >= Group::LANGUAGE_WRITE ? 'write' : 'read')) . '</span>';
        }
        $this->backend->infoStatic(
            $this->cms->snippet('admin.field_group_languages'),
            $items ? implode('<br>', $items) : $this->backend->emptyPlaceholder,
            ['html']
        );

        // Status (setzbar)
        $settable = array_map('intval', (array)($data['statuses'] ?? []));
        $items = [];
        foreach ($this->_statuses() as $statusId => $statusHtml) {
            if (in_array($statusId, $settable, true)) $items[] = $statusHtml;
        }
        $this->backend->infoStatic(
            $this->cms->snippet('admin.field_group_statuses'),
            $items ? implode('<br>', $items) : $this->backend->emptyPlaceholder,
            ['html']
        );
        echo $this->backend->infoTable();

        echo $this->backend->template('container_footer');
    }

    /**
     * Reiterleiste der Detailansicht: Info | Bereichsrechte (letztere nicht für
     * die Superuser-Gruppe - sie hat keine Zeilen).
     *
     * @param string $active  'info' | 'areas'
     */
    private function _infoTabs(int $id, string $active, bool $isSuperuser): string
    {
        $this->backend->tabBarReset();
        $this->backend->tabAdd('#button_info', "cApp.layers.replace('Admin/group_info.php?id={$id}')", $active === 'info');
        if (!$isSuperuser) {
            $this->backend->tabAdd('#admin.headline_group_areas', "cApp.layers.replace('Admin/group_access.php?id={$id}')", $active === 'areas');
        }
        // "Änderungen" (Audit-Log der Gruppe) - aktiv nur mit tab=audit auf group_info.php
        $this->backend->auditTabAdd("Admin/group_info.php?id={$id}");
        return $this->backend->tabBar();
    }

    /**
     * Reiter "Bereichsrechte" (backend/group_access.php): je zugewiesener Instanz
     * eine Tabelle der Bereiche mit den Rechten der Gruppe - alle Bereiche, eine
     * Gruppe. Das Gegenstück je Bereich (alle Gruppen, ein Bereich) ist der Reiter
     * "Zugriff" im Modul Area.
     *
     *     ''      Anzeige mit Häkchen, Buttons "Bearbeiten" und "Schließen"
     *     edit    Checkboxen, darunter "Speichern"/"Abbrechen"
     *     save    Group::saveAreaRights(), danach die Anzeige
     */
    public function access(): void
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $function = (string)($_REQUEST['function'] ?? '');

        $this->backend->setNaviLabel('#admin.headline_group_areas');
        echo $this->backend->template('container_header');
        if (!$this->_allowed()) {
            echo $this->backend->template('container_footer');
            return;
        }
        if ($id === InstanceCache::SUPERUSER_GROUP_ID || !$data = $this->group->load($id)) {
            echo $this->backend->template('alert_danger', [
                '{text}' => $id === InstanceCache::SUPERUSER_GROUP_ID ? $this->cms->snippet('admin.error_group_superuser') : $this->cms->errorMsg()
            ]);
            echo $this->backend->template('container_footer');
            return;
        }
        $label = $this->cms->textOrSnippet((string)$data['label']);
        echo $this->backend->headline("{$this->iconGroup} {$label}");
        echo $this->_infoTabs($id, 'areas', false);

        if ($function === 'save') {
            if ($this->group->saveAreaRights($id, (array)($_REQUEST['data']['areas'] ?? []))) {
                $this->backend->balloon('success', $this->cms->snippet('admin.message_group_saved'));
                $function = '';
            }
            else {
                echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
                $function = 'edit';
            }
        }
        $instanceIds = array_values(array_intersect(
            array_keys($this->instanceCache->instanceLabels()),
            array_map('intval', (array)($data['instance_ids'] ?? []))
        ));

        if ($function !== 'edit') {
            $this->backend->buttonBarReset();
            $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Admin/group_access.php?id={$id}&function=edit');");
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
            echo $this->backend->buttonBar();
            $this->_areaTables($id, $instanceIds, false);
            echo $this->backend->template('p', ['{text}' => $this->cms->snippet('admin.note_group_areas')]);
            echo $this->backend->template('container_footer');
            return;
        }

        echo '<form action="Admin/group_access.php" method="post">';
        $this->_areaTables($id, $instanceIds, true);
        $this->backend->formInit('admin.group_access_form');
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $id);
        echo $this->backend->form('admin.group_access_buttons', ['{group_id}' => $id]);
        echo '</form>';
        echo $this->backend->template('container_footer');
    }

    /**
     * Je Instanz eine Tabelle der Bereiche (ohne Ordner) mit den Rechten der Gruppe -
     * als Häkchen (Info) oder als Checkboxen data[areas][{area_id}][{recht}] (access()).
     * Template-Bereiche kennen nur Lesen und Schreiben.
     */
    private function _areaTables(int $groupId, array $instanceIds, bool $edit): void
    {
        $areaRights = $this->group->areaRights($groupId);
        $checked = $this->backend->template('icon_checked');
        $empty = $this->backend->emptyPlaceholder;
        $anyArea = false;
        foreach ($instanceIds as $instanceId) {
            $rows = '';
            foreach ($this->instanceCache->areasOfType([], [$instanceId]) as $areaId => $info) {
                if ($info['type'] === 'folder') continue;
                $flags = $areaRights[$areaId] ?? null;
                $teaser = $this->backend->teaser('area', $areaId, linked: !$edit);
                if ($teaser === '') continue;
                $isTemplate = ($info['type'] === 'template');
                if ($edit) {
                    $cell = fn(string $key) => $this->backend->template('admin.group_area_checkbox', [
                        '{name}' => "data[areas][{$areaId}][{$key}]",
                        '{class}' => ' c9-access-dep',
                        '{checked}' => ($flags !== null && !empty($flags[$key])) ? ' checked' : '',
                        '{disabled}' => ($flags === null) ? ' disabled' : '',
                        '{onchange}' => '',
                    ]);
                    $read = $this->backend->template('admin.group_area_checkbox', [
                        '{name}' => "data[areas][{$areaId}][read]",
                        '{class}' => '',
                        '{checked}' => ($flags !== null) ? ' checked' : '',
                        '{disabled}' => '',
                        '{onchange}' => $this->backend->on('change', "$(this).closest('tr').find('.c9-access-dep').prop('disabled', !this.checked);"),
                    ]);
                }
                else {
                    $cell = fn(string $key) => ($flags === null) ? $empty : (!empty($flags[$key]) ? $checked : $empty);
                    $read = ($flags === null) ? $empty : $checked;
                }
                $rows .= $this->backend->template('admin.group_area_row', [
                    '{class}' => ($flags === null && !$edit) ? ' c9-grey-30' : '',
                    '{area}' => $teaser,
                    '{read}' => $read,
                    '{group_write}' => $cell('group_write'),
                    '{group_delete}' => $isTemplate ? '' : $cell('group_delete'),
                    '{other_write}' => $isTemplate ? '' : $cell('other_write'),
                    '{other_delete}' => $isTemplate ? '' : $cell('other_delete'),
                    '{set_owner}' => $isTemplate ? '' : $cell('set_owner'),
                ]);
            }
            if ($rows === '') continue;
            $anyArea = true;
            echo $this->backend->template('admin.group_area_table', [
                '{instance}' => $this->backend->teaser('instance', $instanceId, linked: !$edit),
                '{first_column}' => $this->cms->snippet('admin.access_area'),
                '{table_rows}' => $rows,
            ]);
        }
        if (!$anyArea) {
            echo $this->backend->template('p', ['{text}' => $empty]);
        }
    }

    /**
     * Erstellen / kopieren / ändern / löschen (backend/group_edit.php) -
     * Zustandsautomat wie AdminBackend::edit().
     */
    public function edit(): void
    {
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = (string)($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array)($_REQUEST['data'] ?? []);
        $this->data['group_id'] = $this->id;

        $this->backend->setNaviLabel('#button_' . $this->function);
        echo $this->backend->template('container_header');
        if (!$this->_allowed()) {
            echo $this->backend->template('container_footer');
            return;
        }

        // Die Superuser-Gruppe hat kein Formular
        if ($this->id === InstanceCache::SUPERUSER_GROUP_ID && $this->function !== 'copy') {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->snippet('admin.error_group_superuser')]);
            echo $this->backend->template('container_footer');
            return;
        }

        if ($this->function == 'create') {
            $this->data = $this->group->init();
            $this->id = 0;
        }
        if (in_array($this->function, ['edit', 'copy', 'delete'], true)) {
            $this->data = $this->group->load($this->id);
            if (!$this->data) {
                $this->function = 'skip';
            }
            elseif ($this->function == 'copy') {
                $this->id = 0;
            }
        }

        echo $this->_editHeadline();

        if ($this->function == 'save') $this->_editSave();
        if ($this->function == 'delete_confirm') $this->_editDeleteConfirm();
        if (in_array($this->function, ['errors', 'skip'], true)) {
            echo $this->backend->template('alert_danger', ['{text}' => $this->cms->errorMsg()]);
        }
        if (in_array($this->function, ['create', 'edit', 'copy', 'errors'], true)) $this->_editForm();
        if ($this->function == 'delete') $this->_editDeleteForm();

        echo $this->backend->template('container_footer');
    }

    private function _editHeadline(): string
    {
        if ($this->function == 'delete') {
            $label = $this->cms->textOrSnippet((string)($this->data['label'] ?? ''));
        }
        else {
            $label = $this->cms->snippet('admin.headline_group_info');
        }
        return $this->backend->template('headline1', ['{text}' => $this->iconGroup . ' ' . $label]);
    }

    /**
     * function=save: die Feldliste steht fest - nur diese Felder werden geschrieben.
     * data[modules] (Formular) wird von Group::hookOnSave() nach details.modules
     * übernommen.
     */
    private function _editSave(): void
    {
        $fields = ['label', 'details', 'instance_ids', 'languages', 'statuses'];
        $this->data['modules'] = (array)($this->data['modules'] ?? []);
        // keine Checkbox angehakt = keine Oberfläche (nicht: die bisherigen behalten)
        $this->data['interfaces'] = (array)($this->data['interfaces'] ?? []);
        $this->data['instance_ids'] = (array)($this->data['instance_ids'] ?? []);
        $this->data['languages'] = (array)($this->data['languages'] ?? []);
        // keine Checkbox angehakt = kein Status setzbar
        $this->data['statuses'] = (array)($this->data['statuses'] ?? []);
        if (!isset($this->data['details'])) $this->data['details'] = [];

        if ($this->group->save($fields, $this->data)) {
            $this->backend->balloon('success', $this->cms->snippet('admin.message_group_saved'));
            $this->backend->script("
                cApp.layers.invalidate();
                cApp.layers.close();
            ");
        }
        else {
            $this->function = 'errors';
        }
    }

    private function _editDeleteConfirm(): void
    {
        if (!$this->backend->requirePost()) {
            $this->function = 'skip';
            return;
        }
        if ($this->group->deleteReplacing($this->id, (int)($_POST['replacement_id'] ?? 0))) {
            $this->backend->balloon('success', $this->cms->snippet('admin.message_group_deleted'));
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
     * Das Formular - siehe Klassenkommentar. Nach einem Fehler ('errors') stehen
     * die Eingaben noch als data[modules] usw. im Request und werden so wieder
     * angezeigt; sonst kommen die Werte aus dem geladenen Datensatz.
     */
    private function _editForm(): void
    {
        $changed = ($this->function == 'errors') ? ' data-changed="true"' : '';
        echo '<form action="Admin/group_edit.php" method="post"' . $changed . '>';
        $this->backend->formInit('admin.group_edit_form');

        // label
        $this->backend->formField($this->group, [
            'name' => 'label',
            'value' => $this->data['label'] ?? '',
            'id' => $this->id
        ]);

        // Oberflächen
        // --------------------
        // aus dem Formular (data[interfaces]) oder aus details.interfaces
        if ($this->function === 'errors') {
            $interfaces = array_map('strval', (array)($this->data['interfaces'] ?? []));
        }
        else {
            $details = is_string($this->data['details'] ?? null) ? json_decode($this->data['details'], true) : ($this->data['details'] ?? []);
            $interfaces = Access::normalizeInterfaces(isset($details['interfaces']) ? (array)$details['interfaces'] : null);
        }
        $rows = '';
        foreach (Access::INTERFACES as $interface) {
            $rows .= $this->backend->template('edit_form_checkbox_with_label', [
                '{name}' => 'data[interfaces][]',
                '{value}' => $interface,
                '{id}' => 'if_' . $this->crypt->randomKey(8),
                '{label}' => $this->cms->snippet('admin.label_interface_' . $interface),
                '{checked}' => in_array($interface, $interfaces, true) ? ' checked' : '',
            ]);
        }
        $this->backend->formReplace('{data_interfaces}', $this->_formGroup('admin.field_group_interfaces', $rows, 'admin.tooltip_group_interfaces'));

        // Module mit Zusatzrechten
        // --------------------
        // Gewährte Rechte: aus dem Formular (data[modules]) oder aus details.modules
        if (isset($this->data['modules'])) {
            $granted = [];
            foreach ((array)$this->data['modules'] as $name => $entry) {
                if (!empty($entry['access'])) $granted[$name] = array_map('strval', (array)($entry['rights'] ?? []));
            }
        }
        else {
            $details = is_string($this->data['details'] ?? null) ? json_decode($this->data['details'], true) : ($this->data['details'] ?? []);
            $granted = (array)($details['modules'] ?? []);
        }
        $iconTemplate = $this->backend->template('icon_large');
        $rows = '';
        foreach ($this->group->availableModules() as $name => $module) {
            $moduleId = 'mod_' . $this->crypt->randomKey(8);
            $hasAccess = array_key_exists($name, $granted);
            $rights = '';
            foreach ($module['access'] as $right => $snippet) {
                $rights .= $this->backend->template('admin.group_module_right', [
                    '{module}' => $name,
                    '{module_id}' => $moduleId,
                    '{right}' => $right,
                    '{id}' => 'right_' . $this->crypt->randomKey(8),
                    '{label}' => $this->cms->snippet($snippet),
                    '{checked}' => ($hasAccess && in_array($right, (array)$granted[$name], true)) ? ' checked' : '',
                    '{disabled}' => $hasAccess ? '' : ' disabled',
                ]);
            }
            $icon = str_replace('{name}', $module['icon'], $iconTemplate);
            $rows .= $this->backend->template('admin.group_module_row', [
                '{module}' => $name,
                '{id}' => $moduleId,
                '{label}' => $icon . ' <strong class="c9-grey-50">' . $this->cms->textOrSnippet($module['label']) . '</strong>',
                '{checked}' => $hasAccess ? ' checked' : '',
                '{rights}' => $rights,
            ]);
        }
        $this->backend->formReplace('{data_modules}', $this->_formGroup('admin.field_group_modules', $rows, 'admin.tooltip_group_modules'));

        // Instanzen
        // --------------------
        $selected = array_map('intval', (array)($this->data['instance_ids'] ?? []));
        $rows = '';
        foreach ($this->instanceCache->instanceLabels() as $instanceId => $label) {
            $rows .= $this->backend->template('edit_form_checkbox_with_label', [
                '{name}' => 'data[instance_ids][]',
                '{value}' => (string)$instanceId,
                '{id}' => 'inst_' . $this->crypt->randomKey(8),
                '{label}' => $this->cms->textOrSnippet($label),
                '{checked}' => in_array((int)$instanceId, $selected, true) ? ' checked' : '',
            ]);
        }
        $this->backend->formReplace('{data_instance_ids}', $this->_formGroup('admin.field_group_instance_ids', $rows, 'admin.tooltip_group_instance_ids'));

        // Sprachen
        // --------------------
        $levels = (array)($this->data['languages'] ?? []);
        $rows = '';
        foreach ($this->_languages() as $languageId => $language) {
            $level = (int)($levels[$languageId] ?? Group::LANGUAGE_NONE);
            $rows .= $this->backend->template('admin.group_language_row', [
                '{name}' => "data[languages][{$languageId}][]",
                '{label}' => $this->cms->textOrSnippet((string)$language['label']) . ' (' . $language['code'] . ')',
                '{id_read}' => 'lang_' . $this->crypt->randomKey(8),
                '{id_write}' => 'lang_' . $this->crypt->randomKey(8),
                '{checked_read}' => ($level >= Group::LANGUAGE_READ) ? ' checked' : '',
                '{checked_write}' => ($level >= Group::LANGUAGE_WRITE) ? ' checked' : '',
            ]);
        }
        $table = $this->backend->template('admin.group_language_table', [
            '{table_rows}' => $rows
        ]);
        $this->backend->formReplace('{data_languages}', $this->_formGroup('admin.field_group_languages', $table, 'admin.tooltip_group_languages'));

        // Status (setzbar)
        // --------------------
        $settable = array_map('intval', (array)($this->data['statuses'] ?? []));
        $rows = '';
        foreach ($this->_statuses() as $statusId => $statusHtml) {
            $rows .= $this->backend->template('edit_form_checkbox_with_label', [
                '{name}' => 'data[statuses][]',
                '{value}' => (string)$statusId,
                '{id}' => 'status_' . $this->crypt->randomKey(8),
                '{label}' => $this->backend->protect($statusHtml),
                '{checked}' => in_array($statusId, $settable, true) ? ' checked' : '',
            ]);
        }
        $this->backend->formReplace('{data_statuses}', $this->_formGroup('admin.field_group_statuses', $rows, 'admin.tooltip_group_statuses'));

        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $this->id);
        echo $this->backend->form('form_save_cancel_note');
        echo '</form>';
    }

    /**
     * Beschrifteter Block im Formular (Vorlagen group_label_tt + edit_form_block).
     */
    private function _formGroup(string $labelSnippet, string $content, string $tooltipSnippet = ''): string
    {
        $uid = $this->crypt->randomKey(16);
        $label = $this->backend->template($tooltipSnippet !== '' ? 'group_label_tt' : 'group_label', [
            '{id}' => $uid,
            '{label}' => $this->cms->snippet($labelSnippet),
            '{tooltip}' => $tooltipSnippet !== '' ? $this->cms->snippet($tooltipSnippet) : '',
            '{class}' => '',
        ]);
        return $this->backend->template('edit_form_block', [
            '{label}' => $label,
            '{field}' => $this->backend->template('group_container', ['{id}' => $uid, '{content}' => $content]),
            '{class}' => '',
        ]);
    }

    /**
     * Löschen bestätigen. Hat die Gruppe noch (aktive) Benutzer, wählt man eine
     * Ersatzgruppe, der sie zugewiesen werden (Group::deleteReplacing()); die
     * Superuser-Gruppe steht nicht zur Wahl. Gelöschte Benutzer verlieren die
     * Zuordnung ohne Rückfrage.
     */
    private function _editDeleteForm(): void
    {
        $replacement = '';
        // Nur mit aktiven Benutzern - gelöschte verlieren die Zuordnung (Group::hookOnDelete())
        if ($this->group->adminCount($this->id) > 0) {
            $options = ['' => $this->cms->snippet('admin.option_group_replacement')];
            foreach ($this->backend->getGroups() as $groupId => $label) {
                if ($groupId === $this->id || $groupId === InstanceCache::SUPERUSER_GROUP_ID) continue;
                $options[(string)$groupId] = $label;
            }
            $replacement = $this->backend->template('admin.group_delete_replacement', [
                '{text}' => $this->backend->escape(strtr($this->cms->snippet('admin.alert_group_delete_replacement'), ['{count}' => (string)$this->group->adminCount($this->id)])),
                '{id}' => 'repl_' . $this->crypt->randomKey(8),
                '{options}' => $this->backend->options($options, ''),
            ]);
        }
        echo '<form action="Admin/group_edit.php" method="post">';
        $this->backend->formInit('admin.group_delete_form', [
            '{replacement}' => $replacement,
        ]);
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');
        echo $this->backend->form('form_delete_cancel');
        echo '</form>';
    }

    /**
     * Alle Status in ihrer Reihenfolge: status_id => Icon und Beschriftung (HTML).
     * (kein teaser(): status_id 0 ist ein gültiger Status)
     */
    private function _statuses(): array
    {
        $statuses = [];
        $this->db->pushResult();
        $this->db->query("SELECT `status_id`, `icon`, `color_class`, `label` FROM `status` ORDER BY `position` ASC");
        while ($row = $this->db->fetch()) {
            $statuses[(int)$row['status_id']] = $this->backend->template('status.icon_status', [
                '{icon}' => htmlspecialchars((string)$row['icon']),
                '{color_class}' => htmlspecialchars((string)$row['color_class']),
            ]) . ' ' . htmlspecialchars($this->cms->textOrSnippet((string)$row['label']));
        }
        $this->db->popResult();
        return $statuses;
    }

    /**
     * Alle Sprachen in Backend-Reihenfolge: language_id => ['code', 'label'].
     */
    private function _languages(): array
    {
        $languages = [];
        $this->db->pushResult();
        $this->db->query("SELECT `language_id`, `code`, `label` FROM `language` ORDER BY `position` ASC");
        while ($row = $this->db->fetch()) {
            $languages[(int)$row['language_id']] = ['code' => (string)$row['code'], 'label' => (string)$row['label']];
        }
        $this->db->popResult();
        return $languages;
    }
}
