<?php
/**
 * Backend-Oberfläche für die Backend-Benutzer (Tabelle `admin`).
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     admin_list.php    -> list()    Benutzerliste
 *     admin_info.php    -> info()    Detailansicht
 *     admin_edit.php    -> edit()    anlegen / kopieren / ändern / löschen
 *     admin_search.php  -> search()  Suchmaske und Suchausführung
 *     settings.php      -> settings() persönliche Einstellungen (eigene, oder als
 *                                    Superuser die eines anderen Admins)
 *     totp.php          -> totp()     Zwei-Faktor-Anmeldung: Authenticator-App
 *                                    einrichten/abschalten, Wiederherstellungscodes,
 *                                    E-Mail-Code als eigene Wahl; als Superuser für
 *                                    andere zurücksetzen
 *     password.php      -> password() eigenes Passwort ändern (nur mit dem bisherigen)
 *     devices.php       -> devices()  Anmeldegeräte und Sitzungen: eigene ansehen,
 *                                    einzeln entfernen, andere Sitzungen beenden; als
 *                                    Superuser alle Geräte eines anderen Admins entfernen
 *
 * Das Modul ist das schlichteste im Bestand und dient deshalb als Muster für den
 * Standardaufbau (docs/developers/03-backend-entwicklung.md): vier Seiten, ein Zustandsautomat in
 * edit(), Hilfsmethoden mit _edit-Präfix.
 *
 * VERSCHLÜSSELTE FELDER
 * --------------------
 * email, mobile und phone liegen als 'encrypted' in varbinary-Spalten, das Passwort als
 * nicht umkehrbarer Hash. Für die Oberfläche macht das keinen Unterschied - Dataset
 * ent- und verschlüsselt beim Laden und Speichern. Zu beachten ist nur die Suche:
 * verschlüsselte Felder lassen sich nur exakt vergleichen, nicht per LIKE. Für die
 * unscharfe Suche gibt es die Volltextspalte `ft`, die aus name, displayname,
 * primename und sortname gespeist wird.
 *
 * GRUPPEN
 * --------------------
 * Backend::getGroups() liefert die Auswahlliste (Gruppe 1 = Superuser zuerst). Die
 * Gruppen selbst - mit ihren Rechten auf Module, Instanzen, Bereiche und Sprachen -
 * verwaltet GroupBackend im Reiter "Gruppen" neben der Admin-Liste (nur Superuser).
 *
 * ZUSATZRECHTE (install/settings.php 'access', siehe 12-group-rights.md)
 * --------------------
 * Die Liste mit den Namen sieht jeder mit Modulzugriff. Darüber hinaus:
 *
 *     admin_read_group    Detailansicht (Kontaktdaten) der Admins der eigenen Gruppe
 *     admin_read_other    Detailansicht der Admins anderer Gruppen
 *     admin_create        Admins anlegen und kopieren - in der eigenen Gruppe, mit
 *                         Startpasswort
 *     admin_change        Daten der Admins der eigenen Gruppe ändern und sie sperren
 *                         (Status) - ohne Passwort und ohne Gruppe
 *
 * Die eigene Detailansicht ist immer lesbar. Das Passwort eines anderen Admins,
 * Löschen, Admins fremder Gruppen und die Einstellungen anderer Admins bleiben dem
 * Superuser vorbehalten (_canRead() / _canCreate() / _canChange()).
 *
 * PASSWÖRTER
 * --------------------
 * Ein Passwort, das ein anderer Admin gesetzt hat (Startpasswort beim Anlegen,
 * Zurücksetzen durch den Superuser), muss bei der ersten Anmeldung geändert werden
 * (admin.password_change, Admin::hookOnSaved()); ebenso nach
 * admin.password_retention_days. Das eigene Passwort ändert jeder Admin über
 * password.php - nur mit dem bisherigen.
 *
 * ALS LOOKUP-ZIEL
 * --------------------
 * Andere Formulare wählen einen Admin über ein Lookup-Feld (Vorlage
 * edit_form_lookup_foreign_table, z. B. der Empfänger einer internen Nachricht in
 * MessagingBackend). Dafür kennt list() den Parameter src_field: die Liste bekommt
 * eine Checkbox-Spalte, deren Häkchen cApp.ui.slookupUpdate() aufruft, und
 * ajax_lookup_preview.php (lookupPreview()) liefert den Vorschaublock mit dem
 * versteckten Feld, das die gewählte admin_id in das aufrufende Formular trägt -
 * genau wie TemplateBackend es für Instance::template_id macht.
 *
 * @see Camindo\Core\Modules\Admin\Admin  Dataset/Model, enthält auch die Anmeldung
 * @see Camindo\Core\Modules\Admin\GroupBackend  Benutzergruppen und ihre Rechte
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

// Der Klassenname ist hier kleingeschrieben, Datei und alle Verwendungsstellen
// schreiben ihn als `AdminBackend`. PHP vergleicht Klassennamen ohne Rücksicht auf
// Groß-/Kleinschreibung, der Autoloader findet die Datei über den Dateinamen - es
// funktioniert also, weicht aber von der Konvention ab (docs/developers/08-konventionen.md).
class AdminBackend {
    // Werden nirgends gelesen oder gefüllt; die Statusdarstellung kommt aus
    // Backend::teaser('status', …).
    protected array $statusIcon = [];
    protected array $statusLabel = [];

    // Einmal im Konstruktor gerendertes Icon, damit die HTML-Vorlage nicht in jeder
    // Zeile neu aus dem Cache geholt werden muss.
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
    // Zusatzrechte (siehe Klassenkommentar)

    /**
     * Darf die Detailansicht eines Admins der Gruppe $groupId gelesen werden?
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
     * Darf ein Admin angelegt bzw. kopiert werden (Zusatzrecht admin_create; die
     * Gruppe ist für Nicht-Superuser fest die eigene)?
     */
    private function _canCreate(): bool
    {
        $access = $this->backend->access();
        if ($access->isSuperuser()) return true;
        return $access->moduleRight('Admin', 'admin_create');
    }

    /**
     * Darf ein Admin der Gruppe $groupId geändert bzw. gesperrt werden
     * (Zusatzrecht admin_change, nur eigene Gruppe)?
     */
    private function _canChange(int $groupId): bool
    {
        $access = $this->backend->access();
        if ($access->isSuperuser()) return true;
        return $groupId === $access->groupId() && $access->moduleRight('Admin', 'admin_change');
    }

    /**
     * Gruppe neuer Admins: für Nicht-Superuser fest die eigene.
     */
    private function _ownGroupId(): int
    {
        return $this->backend->access()->groupId();
    }

    /**
     * Benutzerliste (backend/admin_list.php).
     *
     * 'request' => 'all' lässt Cms::select() Filter, Sortierung und Seitenzahl aus dem
     * Request übernehmen - damit wirken Suchmaske, Sortierlinks und Paginierung ohne
     * eigenes Zutun.
     *
     * Die Statusspalte trägt zusätzlich ein Dropdown im Spaltenkopf (Schnellfilter);
     * $thStatusId wird deshalb nicht direkt eingesetzt, sondern erst in die Vorlage
     * admin.admin_table_head_column_dropdown gewickelt.
     *
     * Mit src_field (Name des aufrufenden Lookup-Feldes) wird die Liste zur
     * Auswahlliste: erste Spalte Checkbox, Häkchen = cApp.ui.slookupUpdate(); bereits
     * gewählte Admins kommen als 'checked' (Kommaliste) mit. Ein einziger, noch nicht
     * gewählter Treffer wird sofort übernommen und der Layer geschlossen.
     *
     * Request: filter, sort, order, skip (über select 'request'), src_field, checked
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

        // Reiter: Administratoren | Gruppen (nur Superuser, nicht im Lookup)
        if ($srcField === '') {
            echo $this->groupBackend->moduleTabs('admins');
        }
        
        // Buttons (Anlegen nur mit dem Zusatzrecht admin_create)
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
        // Owner-Lookup der Datensatz-Maske (src_field owner_id): ein Nicht-Superuser
        // darf nur Admins seiner eigenen Gruppe als Owner wählen (12-group-rights.md)
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

        // Suchbegriffe oder geänderte Sortierung als lesbarer Text
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
            
            // Hole Ergebnisse (Dataset::fetch: mit Datennachbeareitung)
            $destId = 0;
            $isLinked = false;
            $groupLabels = $this->backend->getGroups();
            while ($data = $this->admin->fetch()) {
                $destId = (int)$data['admin_id'];

                // Lookup-Aufruf: Checkbox-Spalte. Die Auswahl wandert in das Feld des
                // aufrufenden Formulars (Layer darunter), geschrieben wird hier nichts.
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

            // Context menu (Bearbeiten mit admin_change, Kopieren mit admin_create,
            // Löschen nur Superuser - ein Menü für alle Zeilen, edit() prüft je
            // Datensatz noch einmal)
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

            // Lookup-Aufruf mit genau einem, noch nicht gewählten Treffer (z. B. nach
            // Eingabe eines Namens im Lookup-Feld): sofort übernehmen und schließen.
            // slookupUpdate() schließt bei einem Einzelfeld (max 1) selbst - ohne
            // preventClosing ginge sonst auch das Formular darunter zu
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
     * Aufgerufen von cApp.ui.slookupUpdate() / slookupUnlink(), nachdem in der
     * Auswahlliste ein Häkchen gesetzt oder entfernt wurde. Geschrieben wird nichts:
     * der Block enthält je gewähltem Admin ein verstecktes Feld data[{src_field}],
     * das erst mit dem aufrufenden Formular abgeht.
     *
     * Request: src_field (Pflicht), ids (Kommaliste), max, function, prefix
     *          ('filter' für die Suchmaske, sonst 'data')
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
        // prefix=filter: Suchfeld der Suchmaske (filter[...]), sonst Formularfeld
        $prefix = ((string)($_REQUEST['prefix'] ?? '') === 'filter') ? 'filter' : 'data';
        echo $this->lookupPreviewCreate($srcField, $ids, $prefix, $max);
        if ($function == 'link') {
            $this->backend->script("cApp.ui.lookupFocus('{$srcField}', true);");
        }
    }

    /**
     * Den Vorschaublock eines Admin-Lookup-Feldes rendern.
     *
     * Gemeinsamer Kern von lookupPreview() und dem erstmaligen Formularaufbau in
     * anderen Modulen (MessagingBackend::_editForm()). Je gewähltem Admin eine Zeile
     * mit Icon und Name, Entfernen-Button und dem versteckten Feld mit der Id - der
     * Block IST das Eingabefeld. Gelöschte und deaktivierte Admins bleiben wählbar
     * sichtbar, wenn sie schon gespeichert sind (load statt select), damit ein
     * bestehender Wert nicht stillschweigend verschwindet.
     *
     * @param string $srcField  Name des Lookup-Feldes (= Name des Formularfeldes)
     * @param array  $ids       gewählte admin_ids
     * @param string $prefix    Formularpräfix ('data' / 'filter')
     * @param int    $max       1: höchstens ein Admin
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
     * Das Passwort taucht nicht auf - Dataset::load() gibt Felder vom Typ 'password'
     * gar nicht erst zurück.
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

        // Reiter: Info | Einstellungen | … (nur für Superuser, siehe _infoTabs())
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
        // Gelöscht und noch nicht anonymisiert: "Anonymisieren" (Modul Privacy,
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
     * Reiter "Aktivitäten" der Detailansicht (backend/admin_audit.php): was der
     * Admin im Audit-Log hinterlassen hat - zuletzt bearbeitete Datensätze und alle
     * Einträge (AuditBackend::adminActivity()). Nur für Superuser, wie das Modul
     * Audit selbst.
     *
     * Request: id, skip (Blättern)
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
     * Reiterleiste der Detailansicht: Info | Einstellungen | Zwei-Faktor-Anmeldung |
     * Geräte | Aktivitäten | Änderungen. Die Reiter sieht nur ein Superuser - nur er
     * darf die Einstellungen eines anderen Admins vorgeben (settings()), dessen TOTP
     * zurücksetzen (totp()), seine Geräte entfernen (devices()) und das Audit-Log
     * einsehen: was der Admin getan hat (audit(), "Aktivitäten") und was an seinem
     * Konto geändert wurde (info() mit tab=audit, "Änderungen").
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
        // "Änderungen": Audit-Log DES Admin-Kontos (tab=audit auf admin_info.php) -
        // "Aktivitäten" oben ist, was der Admin selbst geändert hat
        $this->backend->auditTabAdd("Admin/admin_info.php?id={$id}");
        return $this->backend->tabBar();
    }

    /**
     * Persönliche Einstellungen (backend/settings.php).
     *
     * Ohne id (Zahnrad rechts oben) die eigenen; mit id die eines anderen Admins -
     * das darf nur ein Superuser, als Reiter in dessen Detailansicht. Gespeichert
     * wird in `admin`.`settings` (Admin::saveSettings()), Admin::hookOnSaved()
     * trägt die Werte in die laufenden Sessions nach.
     *
     * Was sich einstellen lässt:
     *     language_id    bevorzugte Sprachversion in der Eingabemaske
     *     backend_code   Backend-Sprache
     *     locale         Locale der Datums-, Zeit- und Zahlenformate
     *     timezone       eigene Zeitzone
     *     fed            Frontend-Editing (nur eigene Einstellungen: es ist ein Cookie
     *                    dieses Browsers)
     *     widgets        Widgets der Startseite als Liste aus Modul + Platz
     *                    (Camindo\Core\Widgets)
     *     reports        abonnierte Berichte (scheduler_ids, Aufgaben mit "wählbarer
     *                    Bericht" für die Gruppe des Admins) - nach jedem Lauf kommt
     *                    das Ergebnis als interne Nachricht (SchedulerRunner)
     *     message_digest Zusammenfassung der internen Nachrichten per E-Mail
     *                    (0 aus, 1 täglich - Messaging\MessageDigest)
     *
     * Neben '' (anzeigen), 'save' und 'errors' gibt es 'update': Hinzufügen und
     * Entfernen eines Widgets schicken das Formular an sich selbst zurück, ohne zu
     * speichern - die Liste wandert dabei als verstecktes Feld mit. Gespeichert wird
     * erst mit "Speichern", wie bei jedem anderen Feld auch.
     *
     * Beim Speichern der eigenen Einstellungen wird das Cookie 'fed' gesetzt bzw.
     * gelöscht, die Wahl von Sprache, Locale und Zeitzone im localStorage abgelegt (Vorbelegung
     * des Anmeldeformulars) und das Backend neu geladen - die statischen Teile der
     * Oberfläche (Kopfzeile, Menü) stehen sonst weiter in der alten Sprache.
     */
    public function settings(): void
    {
        $sessionAdminId = (int)$this->session->get('admin_id');
        $id = (int)($_REQUEST['id'] ?? 0);
        $isOwn = ($id < 1 || $id === $sessionAdminId);
        if ($isOwn) $id = $sessionAdminId;
        $function = (string)($_REQUEST['function'] ?? '');

        // Erst verarbeiten, dann ausgeben: das Cookie des Frontend-Editings muss vor
        // dem ersten Byte gesetzt werden (kein Output-Buffering)
        $denied = (!$isOwn && !$this->backend->isSuperuser());
        $data = $denied ? null : $this->admin->load($id);
        $settings = [];
        $saved = false;
        $fedChanged = false;
        if ($data) {
            // Aktuelle Werte: Vorgaben + gespeicherte Einstellungen + Eingaben des Requests
            $defaults = (array)($this->admin->init()['settings'] ?? []);
            $settings = array_replace($defaults, (array)($data['settings'] ?? []));
            if ($function === 'save' || $function === 'update') {
                $input = (array)($_REQUEST['data']['settings'] ?? []);
                // Frontend-Editing ist ein Cookie DIESES Browsers - für andere nicht einstellbar
                if (!$isOwn) unset($input['fed']);
                // Widget hinzufügen oder entfernen (siehe _settingsWidgets())
                $input[Widgets::SETTINGS_KEY] = $this->_settingsWidgetsFromRequest($input);
                // Berichte: nur die, die die Gruppe des Admins abonnieren kann - ohne
                // Häkchen kommt der Schlüssel gar nicht an, dann ist es keiner
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

        // Überschrift: eigene Einstellungen bzw. die des angezeigten Admins
        if ($isOwn) {
            echo $this->backend->headline('#admin.headline_settings');
        }
        else {
            echo $this->backend->headline("{$this->iconAdmin} {$data['name']}");
            echo $this->_infoTabs($id, 'settings');
            // Alle Nachrichten des Benutzers löschen (nur hier: Superuser, fremdes Konto)
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
     * Eigene Einstellungen gespeichert: localStorage und Neuladen (siehe settings();
     * das Cookie des Frontend-Editings ist dort bereits vor der Ausgabe gesetzt,
     * aber nur unter der Domain des Backends). Hat sich Frontend-Editing geändert,
     * läuft statt des Neuladens der Abgleich mit den Websites (cApp.ui.fedSync()),
     * der ebenfalls im Backend endet.
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
     * Websites für das Frontend-Editing: primary_domain je Instanz, die die eigene
     * Gruppe lesen darf - ohne die Domain, unter der das Backend gerade läuft (dort
     * setzt Backend::setFedCookie() das Cookie direkt).
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
     * Daten für cApp.ui.fedSync() als JavaScript-Literal - '' ohne Website.
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
     * Das Einstellungsformular.
     *
     * @param int   $id        admin_id
     * @param bool  $isOwn     eigene Einstellungen (mit Frontend-Editing)
     * @param array $settings  aktuelle Werte
     * @param bool  $changed   true: nach einem Fehler erneut angezeigt
     */
    private function _settingsForm(int $id, bool $isOwn, array $settings, bool $changed): void
    {
        echo '<form action="Admin/settings.php" method="post"' . ($changed ? ' data-changed="true"' : '') . '>';
        // Zwei-Faktor-Anmeldung: Stand und der Weg zur Einrichtung (totp())
        $this->backend->formInit('admin.admin_settings_form', [
            '{data_second_factor}' => $this->_settingsSecondFactor($id, $isOwn),
            '{data_security}' => $isOwn ? $this->_settingsSecurity() : '',
            '{data_fed_sites}' => $isOwn ? $this->_settingsFedSites((int)($settings['fed'] ?? 0)) : '',
            '{data_messages}' => $isOwn ? '' : $this->_settingsMessages($id),
        ]);

        // Bevorzugte Sprachversion: '' = Hauptsprache der Instanz; für die eigenen
        // Einstellungen nur die Sprachen, die die eigene Gruppe lesen darf (A8,
        // 12-group-rights.md) - ein Superuser sieht alle
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

        // Backend-Sprache (ein früheres Kürzel wie "de" als "de_DE" vorbelegen)
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

        // Frontend-Editing - nur für die eigenen Einstellungen
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

        // Widgets der Startseite
        $this->backend->formStatic($this->admin, 'settings.widgets', $this->_settingsWidgets($settings));

        // Berichte abonnieren (wählbare Berichte des Zeitplaners für die Gruppe)
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
     * Berichte, die Admins einer Gruppe abonnieren können
     * (Scheduler::reportsForGroup(): Aufgaben url/php mit "wählbarer Bericht").
     *
     * @return array  scheduler_id => Bezeichnung
     */
    private function _reportOptions(int $groupId): array
    {
        return $GLOBALS['app']->get(\Camindo\Core\Modules\Scheduler\Scheduler::class)->reportsForGroup($groupId);
    }

    /**
     * Frontend-Editing auf den Websites: das Cookie gilt je Domain, das Backend
     * setzt es beim Speichern nur unter seiner eigenen. Beim Speichern einer
     * geänderten Einstellung gleicht settings() alle Websites ab (cApp.ui.fedSync());
     * hier zusätzlich ein Knopf für den Abgleich (z. B. in einem anderen Browser oder
     * nach Ablauf des Cookies) und je Website (primary_domain, Instanz lesbar für die
     * eigene Gruppe) ein Knopf, der sie in einem neuen Tab öffnet und das Cookie
     * setzt (FrontendHandover::fedUrl(), 21-backend-domain.md §4). Ist
     * Frontend-Editing aus (gespeicherte Einstellung), nur der Abgleich - er löscht
     * die Cookies auf den Websites.
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
     * Block "Zwei-Faktor-Anmeldung" im Einstellungsformular: welcher Faktor gilt,
     * dazu ein Knopf zur Seite totp() - eigene Einstellungen im neuen Layer,
     * fremde (Superuser) als Reiter.
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
     * Zeile "Interne Nachrichten" in den Einstellungen eines anderen Benutzers
     * (Superuser): Zahl im Posteingang/Postausgang und der Knopf, alle zu löschen
     * (Message::deleteAllOf()) - etwa für einen gesperrten Mitarbeiter.
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
     * Block "Passwort und Geräte" in den eigenen Einstellungen: Knöpfe zu
     * password() und devices(), dazu der Stand des Passworts.
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
     * Stand des Passworts als Text: zuletzt geändert am …, ggf. "muss bei der
     * nächsten Anmeldung geändert werden" (Admin::passwordChangeReason()).
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
                // gespeichert wird nur der Tag (UTC) - ohne Umrechnung in die Zeitzone
                '{date}' => $this->cms->_formatDate(substr($changed, 0, 10) . ' 00:00:00') ?: substr($changed, 0, 10),
            ]);
        $reason = $this->admin->passwordChangeReason($adminId);
        if ($reason !== '') {
            $text .= ' – ' . $this->cms->snippet('admin.text_password_change_' . $reason);
        }
        return $text;
    }

    /**
     * Welcher zweite Faktor gilt für diesen Admin (Admin::secondFactor()) - als Text,
     * bei TOTP mit Datum und Zahl der übrigen Wiederherstellungscodes.
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
                // gespeichert in UTC (wie alle timestampl-Spalten), gezeigt in der Zeitzone des Admins
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
     * Der Block "Widgets" im Einstellungsformular.
     *
     * Oben die bereits eingehängten Widgets mit ihrem Platz und einem
     * Entfernen-Knopf, darunter die Auswahl "Widget" + "Position" und der Knopf
     * "Hinzufügen". Angeboten wird nur, was noch nicht drin ist und was dieser
     * Admin sehen darf - stellt ein Superuser die Einstellungen eines anderen ein,
     * ist das die Liste des Superusers; was der andere nicht darf, bleibt auf
     * dessen Startseite später einfach leer (Widgets::render()).
     *
     * Die Liste selbst reist als verstecktes Feld mit, damit Hinzufügen und
     * Entfernen ohne Zwischenspeichern auskommen. Hinzufügen und Entfernen setzen
     * 'function' auf 'update' und schicken das Formular an sich selbst - ein Knopf
     * überträgt seinen eigenen Wert nicht (FormData kennt keinen Submitter),
     * deshalb steht der zu entfernende Eintrag in einem Hiddenfeld.
     */
    private function _settingsWidgets(array $settings): string
    {
        $widgets = $this->widgets->normalize((array)($settings[Widgets::SETTINGS_KEY] ?? []), requireExists: false);
        $positions = $this->widgets->positionOptions();

        // Die Erklärung steht als Hinweis im Block, nicht als Tooltip am Label:
        // formStatic() rendert keinen Tooltip, und für einen Hover ist der Text zu lang
        $note = $this->backend->template('remark_block', [
            '{text}' => $this->cms->snippet('admin.note_widgets'),
        ]);

        // Eingehängte Widgets
        $rows = '';
        foreach ($widgets as $i => $item) {
            $rows .= $this->backend->template('admin.settings_widget_row', [
                '{i}' => (string)$i,
                '{label}' => htmlspecialchars($this->widgets->label($item['module'])),
                '{position}' => htmlspecialchars((string)($positions[$item['position']] ?? $item['position'])),
            ]);
            // Der Eintrag muss den Umweg über das Formular überstehen
            $this->backend->formHidden("data[settings][widgets][$i][module]", $item['module']);
            $this->backend->formHidden("data[settings][widgets][$i][position]", $item['position']);
        }
        $list = $note . ($rows === ''
            ? $this->backend->template('admin.settings_widgets_none')
            : $this->backend->template('admin.settings_widget_table', ['{table_rows}' => $rows]));

        // Auswahl "Widget": alles, was noch nicht eingehängt ist
        $used = array_column($widgets, 'module');
        $moduleOptions = $this->widgets->options($used);
        if (!$moduleOptions) return $list . $this->backend->template('admin.settings_widgets_all_used');

        return $list . $this->backend->template('admin.settings_widgets_add', [
            '{module_options}' => $this->_settingsWidgetOptions($moduleOptions),
            '{position_options}' => $this->_settingsWidgetOptions($positions),
        ]);
    }

    /**
     * Options einer Auswahlliste des Widget-Blocks. Werte sind Modulnamen bzw.
     * Plätze, maskiert wird trotzdem beides.
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
     * Die Widget-Liste aus dem Request, mit dem gewünschten Hinzufügen bzw.
     * Entfernen - siehe _settingsWidgets().
     *
     * Ein neues Widget kommt ans Ende seines Platzes, weil die Liste die
     * Reihenfolge auf der Startseite ist. Unsinnige Eingaben (Modul schon drin,
     * unbekannter Platz) fallen in Widgets::normalize() heraus.
     *
     * @param array $input  data[settings] aus dem Request
     * @return array        Liste aus ['module' => string, 'position' => int]
     */
    private function _settingsWidgetsFromRequest(array $input): array
    {
        $list = $this->widgets->normalize((array)($input[Widgets::SETTINGS_KEY] ?? []), requireExists: false);

        // Was zu tun ist, sagt das Hiddenfeld, das der jeweilige Knopf setzt - NICHT
        // der Inhalt der Auswahllisten: die stehen auch beim Speichern auf einem
        // Modul, und "Speichern" darf kein Widget hinzufügen.
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
                // Ans Ende des Platzes - die Liste ist die Reihenfolge auf der Startseite
                $list[] = ['module' => $module, 'position' => $position];
                $list = $this->widgets->normalize($list, requireExists: false);
            }
        }
        return $list;
    }

    // ====================
    // Eigenes Passwort, Geräte und Sitzungen

    /**
     * Eigenes Passwort ändern (backend/password.php): bisheriges, neues, Wiederholung.
     * Das neue muss der Richtlinie genügen und sich vom bisherigen unterscheiden
     * (Admin::changePassword()); danach enden die anderen Sitzungen des Kontos
     * (Admin::hookOnSaved()) und der Admin bekommt eine E-Mail.
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
        // Neues Passwort mit der Extension Password (Stärke, Richtlinie) aus dem Schema
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
     * Anmeldegeräte und Sitzungen (backend/devices.php), 16-sicherheit.md §11.
     *
     * Eigenes Konto (ohne id):
     *     ''            Liste der Geräte mit ihren laufenden Sitzungen
     *     remove        POST: ein Gerät entfernen - seine Sitzungen enden mit
     *                   (admin_session.device_id); ist es dieses Gerät, lädt die
     *                   Seite neu und fragt nach der Anmeldung
     *     end_others    POST: alle anderen Sitzungen beenden (auch die ohne Gerät)
     * Fremdes Konto (id, nur Superuser), Reiter der Detailansicht:
     *     ''                Liste
     *     remove_all_form   Rückfrage
     *     remove_all        POST: alle Geräte entfernen und alle Sitzungen beenden
     *
     * Ein entferntes Gerät gilt bei der nächsten Anmeldung als neu - mit Hinweis per
     * E-Mail. Einträge im Audit-Log unter dem Konto ('devices').
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
                    // Die eigene Sitzung hing an diesem Gerät und ist damit beendet -
                    // neu laden führt zur Anmeldung
                    $this->backend->script("window.location.reload();");
                    echo $this->backend->template('container_footer');
                    return;
                }
                $this->backend->balloon('success', $this->cms->snippet('admin.message_device_removed'));
            }
        }
        if ($isOwn && $function === 'end_others') {
            // Bearbeitungssperren der anderen Sessions freigeben - wie beim Abmelden
            // auf allen Geräten (StartBackend::_logout()), solange die Zuordnung
            // der Arbeitskopien zu den Sessions noch besteht
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
     * Tabelle der Geräte mit der Zahl ihrer laufenden Sitzungen; Sitzungen ohne
     * Gerät (angelegt vor der Geräteverwaltung oder per CLI) als eigene Zeile.
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

    /** Audit-Log: Zahl der Geräte vorher/nachher */
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
     * Zwei-Faktor-Anmeldung (backend/totp.php), 16-sicherheit.md §16.
     *
     * Eigenes Konto (ohne id) - Zustandsautomat über 'function':
     *     ''               Stand: aktiv seit, übrige Wiederherstellungscodes, Knöpfe
     *     setup            QR-Code + Secret + Code-Feld (Setup-Token als Hiddenfeld,
     *                      gespeichert wird noch nichts)
     *     enable           POST: erster Code aus der App -> TOTP an, Codes zeigen
     *     recovery_form /
     *     recovery         POST: neuer Satz Wiederherstellungscodes
     *     disable_form /
     *     disable          POST: TOTP abschalten (nicht, wenn es Pflicht ist)
     *     email_switch_form /
     *     email_switch     POST: von der App zum Code per E-Mail wechseln (Code aus
     *                      der App bestätigt; nicht, wenn TOTP Pflicht ist)
     *     email_on_form /
     *     email_on         POST: Code per E-Mail als eigene Wahl einschalten, wenn er
     *                      nicht ohnehin für alle gilt (admin.2_factor) - bestätigt
     *                      mit einem Code, der dabei per E-Mail kommt
     *     email_off_form /
     *     email_off        POST: die eigene Wahl wieder abschalten (ebenso bestätigt)
     * Die schreibenden Schritte verlangen einen gültigen Code aus der App (oder einen
     * Wiederherstellungscode); jeder falsche zählt im LoginGuard wie ein falsches
     * Passwort - eine gekaperte Sitzung soll den Faktor weder abschalten noch
     * durchprobieren können.
     *
     * Fremdes Konto (id, nur Superuser), Reiter der Detailansicht:
     *     ''          Stand
     *     reset_form  Rückfrage
     *     reset       POST: TOTP des Admins zurücksetzen - Audit-Log und E-Mail an ihn
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

        // Passkeys unabhängig von security.admin_2fa (abschaltbar mit security.webauthn)
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

        // Schreibende Schritte nur per POST (17-csrf.md §2.7); ebenso die Schritte,
        // die einen Code per E-Mail verschicken
        $writing = in_array($function, ['enable', 'recovery', 'disable', 'reset', 'email_switch',
            'email_on_form', 'email_on', 'email_off_form', 'email_off'], true);
        if ($writing && !$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $active = $this->totpFactor->isActive($id);

        if (!$isOwn) {
            // Zurücksetzen: App samt Passkeys (WebAuthnFactor::reset())
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
                // Pflicht (totp_required für Superuser): nicht abschaltbar
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
                // Pflicht (totp_required für Superuser): nicht abschaltbar
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
                // Von der App zurück zum Code per E-Mail - bestätigt mit einem Code aus
                // der App (oder Wiederherstellungscode), wie das Abschalten
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
                    // Der Code ist mit dem Versuch verbraucht - neuer Anlauf über den Knopf
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
     * Code-Prüfung mit dem LoginGuard des eigenen Namens: gesperrt -> gar nicht erst
     * prüfen; Fehlschlag -> Fehlversuch verbuchen; Erfolg -> Zähler leeren.
     *
     * @param callable $check  liefert bei Erfolg einen Wert, sonst null
     * @return mixed           Ergebnis von $check oder null (Fehler in Cms::errors)
     */
    private function _totpGuarded(array $data, callable $check, string $errorSnippet = 'admin.error_totp_code',
        string $labelSnippet = 'admin.label_totp_code'): mixed
    {
        // Schlüssel wie bei der Anmeldung: am bekannten Gerät ein eigener Zähler
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
     * Lässt sich der Code per E-Mail als eigene Wahl ein- ($on) bzw. ausschalten?
     * Nicht bei aktiver App (dafür email_switch) und nicht, wenn er ohnehin für
     * alle gilt (admin.2_factor).
     */
    private function _emailFactorSwitchable(int $id, bool $totpActive, bool $on): bool
    {
        if ($totpActive || $this->config->get('admin.2_factor')) return false;
        return $this->admin->emailFactorChosen($id) !== $on;
    }

    /**
     * Hat der Admin eine E-Mail-Adresse? Sonst Fehlermeldung (ohne Adresse käme
     * kein Code an - das Konto wäre gesperrt).
     */
    private function _emailAddressOk(array $data): bool
    {
        if (trim((string)($data['email'] ?? '')) !== '') return true;
        $this->cms->error($this->cms->snippet('admin.error_email_factor_no_address'));
        $this->_editErrors();
        return false;
    }

    /**
     * Bestätigungscode per E-Mail schicken (EmailFactor::start() schreibt ihn in
     * admin.code); das Zeitfenster admin.2_factor_expiration läuft ab uid_date.
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
     * Code aus der E-Mail prüfen - ein Versuch, danach ist er verbraucht.
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

    /** Gerät, IP und Zeit für eine Benachrichtigung (LoginNotifier) */
    private function _eventContext(): array
    {
        return $this->loginDevices->context();
    }

    /**
     * Stand der Zwei-Faktor-Anmeldung und die möglichen Schritte als Knöpfe.
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
                    // Rückweg zum Code per E-Mail; "Abschalten" nur, wenn danach nicht
                    // ohnehin der E-Mail-Code gilt (dann wäre es derselbe Knopf)
                    $this->backend->buttonAdd('button_secondary', "cApp.layers.replace('Admin/totp.php?function=email_switch_form')", $this->cms->snippet('admin.button_email_switch'));
                    if (!$emailForAll) {
                        $this->backend->buttonAdd('button_secondary', "cApp.layers.replace('Admin/totp.php?function=disable_form')", $this->cms->snippet('admin.button_totp_disable'));
                    }
                }
            }
        }
        // Code per E-Mail als eigene Wahl (nur, wenn er nicht ohnehin für alle gilt)
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
    // Passkeys (WebAuthnFactor, developer_docs/22-webauthn.md)

    /**
     * Abschnitt "Passkeys" unter dem zweiten Faktor: Liste, beim eigenen Konto mit
     * "Entfernen" und "Passkey hinzufügen". Ohne feste Backend-Domain nur ein
     * Hinweis (WebAuthn bindet jeden Schlüssel an eine Domain).
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
            // Neue Wiederherstellungscodes ohne App (die TOTP-Variante fragt den App-Code)
            if ($passkeys && !$this->totpFactor->isActive($id)) {
                $this->backend->buttonAdd('button_secondary', "cApp.layers.replace('Admin/totp.php?function=passkey_recovery_form')", $this->cms->snippet('admin.button_recovery_regenerate'));
            }
            echo $this->backend->buttonBar();
        }
    }

    /**
     * Schritte function=passkey_*. Nur das eigene Konto.
     *
     *     passkey_form         Bezeichnung + bisheriges Passwort
     *     passkey_options      (POST) Passwort prüfen -> Knopf, der den Passkey
     *                          auf dem Gerät anlegt (Token mit Challenge im Formular)
     *     passkey_add          (POST) Antwort prüfen und speichern
     *     passkey_remove_form  Rückfrage mit Passwort
     *     passkey_remove       (POST) entfernen
     *
     * @return bool  true: Ausgabe erledigt; false: weiter mit der Übersicht
     */
    private function _passkeyStep(array $data, string $function): bool
    {
        $id = (int)$data['admin_id'];
        if (!$this->webAuthnFactor->available()) return false;
        $label = trim(mb_substr((string)($_POST['label'] ?? ''), 0, 100));
        $passkeyId = (int)($_REQUEST['passkey_id'] ?? 0);

        switch ($function) {
            case 'passkey_form':
                // Vorbelegung: Browser / System wie in der Geräteliste ('?' = unbekannt)
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

    /** Rückfrage mit bisherigem Passwort (Entfernen, neue Wiederherstellungscodes) */
    private function _passkeyConfirm(string $textSnippet, string $function, int $passkeyId,
        string $style, string $buttonSnippet, array $vars = []): void
    {
        echo $this->backend->template('admin.passkey_confirm_form', [
            // einfache Platzhalter {label} ersetzt Cms::snippet() nicht (nur ICU)
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

    /** Passkey des eigenen Kontos, null = gibt es nicht */
    private function _ownPasskey(int $adminId, int $passkeyId): ?array
    {
        foreach ($this->passkeys->forAdmin($adminId) as $passkey) {
            if ($passkey['passkey_id'] === $passkeyId) return $passkey;
        }
        return null;
    }

    /**
     * QR-Code, Secret zum Abtippen und das Feld für den ersten Code.
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
     * Code abfragen vor 'disable' bzw. 'recovery'.
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
     * Neue Wiederherstellungscodes zeigen - nur dieses eine Mal im Klartext.
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
     * Erstellen / kopieren / ändern / löschen (backend/admin_edit.php).
     *
     * Zustandsautomat über $_REQUEST['function'] - siehe
     * docs/developers/03-backend-entwicklung.md für die Bedeutung der einzelnen Werte
     * (create, edit, copy, save, errors, skip, delete, delete_confirm).
     *
     * Der Ablauf ist die Reihenfolge der if-Blöcke: Daten beschaffen, Überschrift,
     * schreibende Aktion, dann das passende Formular. _editSave() setzt bei Fehlern
     * $this->function auf 'errors' und fällt damit in den Formular-Zweig - deshalb
     * kein Redirect nach dem Speichern.
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

        // Zusatzrechte: Löschen nur Superuser; Anlegen/Kopieren mit admin_create,
        // Ändern mit admin_change - beides nur in der eigenen Gruppe (Superuser
        // alles). Beim Speichern zählt die gespeicherte Gruppe des Datensatzes,
        // nicht die aus dem Formular.
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
     * Darf die laufende edit()-Funktion ausgeführt werden? (siehe edit())
     */
    private function _editAllowed(): bool
    {
        if ($this->backend->isSuperuser()) return true;
        if (in_array($this->function, ['delete', 'delete_confirm'], true)) return false;
        if ($this->id > 0) {
            // Ändern (edit, save, errors): Gruppe des gespeicherten Datensatzes
            $this->db->pushResult();
            $this->db->query("SELECT `group_id` FROM `admin` WHERE `admin_id` = ?", 'i', [$this->id]);
            $groupId = (int)($this->db->fetchNum()[0] ?? 0);
            $this->db->popResult();
            return $groupId > 0 && $this->_canChange($groupId);
        }
        // Anlegen (create, copy, save ohne Id): eigene Gruppe
        return $this->_canCreate();
    }

    /**
     * Darf das Formular ein Passwort setzen? Beim Anlegen immer (Startpasswort),
     * sonst nur ein Superuser - das Passwort eines anderen Admins zu ändern hieße,
     * sein Konto zu übernehmen. Das eigene Passwort ändert jeder über password.php.
     */
    private function _editPasswordAllowed(): bool
    {
        return $this->id < 1 || $this->backend->isSuperuser();
    }

    /**
     * function=create: leeres Formular mit den Schema-Defaults.
     *
     * init() ohne Argument - anders als in AreaBackend werden Vorgaben aus der URL
     * hier nicht übernommen.
     */
    private function _editCreate(): void
    {
        $this->data = $this->admin->init();
        $this->id = 0;
    }

    /**
     * function=edit|copy|delete: Benutzer laden.
     *
     * Beim Kopieren wird nur die Id verworfen, damit save() einen neuen Datensatz
     * anlegt. Der Name bleibt zunächst gleich und läuft beim Speichern in die
     * unique-Prüfung - der Redakteur muss ihn also bewusst ändern.
     */
    private function _editLoad(): void
    {
        $this->data = $this->admin->load($this->id);
        if (!$this->data) {
            // Angeforderter Datensatz nicht gefunden
            $this->function = 'skip';
        }
        // Wenn Daten kopiert werden sollen, dann jetzt Id zurücksetzen
        if ($this->function == 'copy') {
            $this->id = 0;
        }
    }

    /**
     * Überschrift des Formulars. Beim Löschen steht der Benutzername darüber, sonst
     * die allgemeine Überschrift.
     *
     * (Als einzige der _edit-Hilfsmethoden public - ohne erkennbaren Grund, aufgerufen
     * wird sie nur aus edit().)
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
     * Die Feldliste steht hier ausdrücklich - nur diese Spalten werden geschrieben.
     * Damit kann ein manipuliertes Formular keine anderen Spalten setzen (etwa uid
     * oder login_date aus der Zwei-Faktor-Anmeldung).
     *
     * 'ft' ist die Volltextspalte; sie steht in der Liste, damit Dataset sie beim
     * Speichern aus den Quellfeldern neu zusammensetzt.
     */
    private function _editSave(): void
    {
        // Nicht-Superuser: die Gruppe ist fest die eigene (Formularwert zählt nicht)
        if (!$this->backend->isSuperuser()) {
            $this->data['group_id'] = $this->_ownGroupId();
        }

        // Prüfe Daten und speichere
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
        
        // Passwort eines bestehenden Kontos: nur Superuser (_editPasswordAllowed())
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

            // Referer aktualisieren und Fenster schließen
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
     * Bearbeitet der Admin seinen eigenen Datensatz?
     */
    private function _isOwnAccount(): bool
    {
        return $this->id > 0 && $this->id === (int)$this->session->get('admin_id');
    }

    /**
     * Neues Passwort für ein bestehendes Konto: im Feld password_current muss das
     * Passwort des ANGEMELDETEN Benutzers stehen - beim eigenen Konto also das
     * bisherige, beim Konto eines anderen (nur Superuser) das eigene. Eine offen
     * stehende Sitzung soll weder das eigene noch ein fremdes Konto übernehmen
     * können (16-sicherheit.md §13). Fehlversuche zählen im LoginGuard wie eine
     * falsche Anmeldung - sonst ließe sich das Passwort über dieses Formular
     * durchprobieren. Ein Konto ohne Passwort (nur per CLI angelegt) braucht keins;
     * beim Anlegen ist keins nötig.
     */
    private function _editPasswordCurrentOk(): bool
    {
        if ($this->id < 1 || trim((string)($this->data['password'] ?? '')) === '') return true;
        return $this->_passwordCurrentOk((int)$this->session->get('admin_id'), (string)($this->data['password_current'] ?? ''),
            ownAccount: $this->_isOwnAccount());
    }

    /**
     * Passwort des Kontos $adminId prüfen - mit LoginGuard (siehe
     * _editPasswordCurrentOk()). Fehler am Feld password_current; $ownAccount =
     * false: es ging um das Konto eines anderen (Feld "Ihr Passwort").
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
     * function=delete_confirm: löschen.
     *
     * `admin` kennt Soft-Delete, delete() markiert also nur. Wichtig, denn
     * record.created_by und record.changed_by zeigen mit ON DELETE CASCADE auf
     * admin.admin_id - ein endgültiges Löschen würde alle Datensätze mitreißen, die
     * dieser Benutzer angelegt oder zuletzt geändert hat.
     */
    private function _editDeleteConfirm(): void
    {
        if ($this->admin->delete($this->id)) {
            // Balloon Message
            $msg = $this->cms->snippet('admin.message_admin_deleted');
            $this->backend->balloon('success', $msg);
            
            // Fenster schließen
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
     * Fehlermeldungen aus Cms::errors als roten Kasten ausgeben.
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
     * Alle Textfelder laufen über dieselbe Schleife; Formulartyp, Beschriftung und
     * Validierung kommen dabei aus dem Schema von Admin. Von Hand gebaut werden nur die
     * beiden Auswahlfelder, deren Optionen nicht im Schema stehen können:
     * group_id (aus Backend::getGroups()) und status_id (aus den Status-Datensätzen).
     *
     * data-changed="true" nach einem fehlgeschlagenen Speichern markiert das Formular
     * als verändert, damit das Javascript beim Schließen nachfragt.
     */
    private function _editForm(): void
    {
        if ($this->function == 'save') $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Admin/admin_edit.php" method="post"' . $changed . '>';

        $this->backend->formInit('admin.admin_edit_form');

        // Data Fields (Passwort nur, wenn es hier gesetzt werden darf)
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
            // Name änderbar, außer für User 'scheduler'
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

        // Bestehendes Konto: ein neues Passwort nur zusammen mit dem Passwort des
        // angemeldeten Benutzers - beim eigenen Konto dem bisherigen, sonst dem eigenen
        // (_editPasswordCurrentOk()). Eine gekaperte oder offen stehende Sitzung soll
        // kein Konto übernehmen können
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

        // group_id - nur ein Superuser darf sie wählen, sonst steht die eigene fest
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
     * Wirklich löschen? Formular
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
     * Ohne function die Maske, mit function=search die Ausführung.
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
     * function=search: Suche ausführen.
     *
     * Rendert keine Trefferliste. Stattdessen schließt sich der Suchlayer und der
     * darunterliegende Listenlayer wird mit den Suchparametern in der URL neu geladen;
     * list() wertet sie über Cms::select(['request' => 'all']) aus. Dadurch
     * funktionieren danach Sortierung und Paginierung wie sonst auch.
     */
    private function _search(): void
    {
        // Parameter aus Post
        $queryString = http_build_query($_POST);

        // Sicher für Javascript verpacken
        // json_encode maskiert < > ' " & als \uXXXX - der String darf danach in das
        // Javascript unten eingesetzt werden.
        $safeParams = json_encode($queryString, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        // json_encode liefert den String MIT Anführungszeichen. substr(..., 1) schneidet
        // das öffnende ab und ein eigenes wird davorgesetzt - das schließende aus
        // json_encode bleibt stehen und beendet unten das Javascript-Literal. Deshalb
        // steht {$safeUri} dort ohne eigene Anführungszeichen.
        $safeUri = '"Admin/index.php?' . substr($safeParams, 1);

        // Referer-Layer mit diesen Parametern neu laden
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
        // Achtung: der dritte Schlüssel muss 'status_id' heißen - so wie die Spalte im
        // Schema. 'status' gibt es dort nicht, eine Sortierung danach läuft ins Leere.
        $sortOptions = [
            'name' => $this->cms->snippet('admin.field_admin_name'),
            'primename' => $this->cms->snippet('admin.field_admin_primename'),
            'status_id' => $this->cms->snippet('admin.field_admin_status_id')
        ];
        $this->backend->searchFieldSortOrder($sortOptions);

        // function
        $this->backend->formHidden('function', 'search');
        // Lookup-Zusammenhang durchreichen (die Trefferliste bleibt Auswahlliste)
        $srcField = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_REQUEST['src_field'] ?? '')));
        if ($srcField !== '') $this->backend->formHidden('src_field', $srcField);

        // Print form
        echo $this->backend->form('form_search_cancel');

        // Container footer
        echo $this->backend->template('container_footer');

        echo '</form>';
    }
}