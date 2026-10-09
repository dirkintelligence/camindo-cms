<?php
/**
 * Backend-Oberfläche für die Redaktions-Zustände (Tabelle `status`).
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     status_list.php  -> list()   Statusliste
 *     status_info.php  -> info()   Detailansicht
 *     status_edit.php  -> edit()   anlegen / kopieren / ändern / löschen
 *
 * Aufbau wie LanguageBackend: kurze Liste ohne Paginierung und Suche, Sortierung über
 * ein Vorgänger-Auswahlfeld, beim Löschen die Wahl eines Ersatz-Status.
 *
 * DIE ERSTEN BEIDEN STATUS SIND GESCHÜTZT
 * --------------------
 * status_id 0 ("enabled") und 1 ("disabled") gehören zum Auslieferungsstand und werden
 * an vielen Stellen fest vorausgesetzt. edit() bricht deshalb gleich zu Anfang mit
 * exit ab, wenn eine Id <= 1 kopiert oder gelöscht werden soll - ein harter Riegel,
 * noch vor jeder Ausgabe. Bearbeiten lässt sich bei ihnen nur die Zuordnung der
 * Benutzergruppen (group_ids) - und die nur von einem Superuser.
 *
 * BENUTZERGRUPPEN (flacher Workflow)
 * --------------------
 * Das Feld group_ids (Mehrfachauswahl) legt fest, welche Gruppen den Status setzen
 * dürfen - die andere Sicht auf das Feld "Status" im Formular einer Gruppe. Es
 * erscheint nur für Superuser (wer Rechte vergibt, muss alles sehen dürfen, siehe
 * GroupBackend); Status::hookOnSave() weist die Angabe sonst ab.
 *
 * ERSATZ-STATUS BEIM LÖSCHEN
 * --------------------
 * Datensätze dürfen nicht auf eine verschwundene status_id zeigen. Das Löschformular
 * lässt einen Ersatz wählen, _editDeleteConfirm() gibt ihn über
 * Status::setReplacementId() weiter, Status::hookOnDelete() stellt die Datensätze um.
 * `replacement_id` ist kein Datenbankfeld - die Felddefinition dafür wird von
 * `status_id` geborgt und nur neu beschriftet.
 *
 * @see Camindo\Core\Modules\Status\Status              Dataset/Model
 * @see Camindo\Core\Modules\Language\LanguageBackend   gleich aufgebaute Schwesterklasse
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
    /** neuer Status (status_id 0 ist zugleich der Grundstatus "enabled") */
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
     * Statusliste (backend/status_list.php).
     *
     * Ohne Paginierung und Suche - die Liste bleibt naturgemäß kurz. Sortiert nach
     * `position`, das ist die Reihenfolge in den Auswahllisten der Datensätze.
     */
    public function list()
    {
        // Seitenelemente
        // --------------------

        // Bread crum navi
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

        // Status-Liste
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

            // Hole Ergebnisse (Dataset::fetch: mit Datennachbeareitung)
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
     * Detailansicht eines Status (backend/status_info.php).
     *
     * Die Überschrift baut _editHeadline(); damit deren Fallunterscheidung greift, wird
     * $this->function vorher auf 'info' gesetzt.
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

        // Status-Data
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

        // Reiter: Info | Änderungen (Audit-Log, Backend::infoAuditTabs())
        if ($this->backend->infoAuditTabs("Status/status_info.php?id={$id}", 'status', $id)) return;

        // Data
        // ---------------  
        $this->backend->infoAdd($this->status, 'label', $data['label'], ['primary']);
        $this->backend->infoAdd($this->status, 'icon', $data['icon']);
        $this->backend->infoAdd($this->status, 'color_class', $data['color_class']);
        // Gruppen, die den Status setzen dürfen (Superuser immer)
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
     * Erstellen / kopieren / ändern / löschen (backend/status_edit.php).
     *
     * Zustandsautomat über $_REQUEST['function'] wie in den anderen Modulen
     * (docs/developers/03-backend-entwicklung.md).
     *
     * Vorweg der Schutz der beiden festen Status: alles außer 'create' bricht bei einer
     * Id <= 1 sofort mit exit ab - noch bevor irgendetwas ausgegeben wird.
     */
    public function edit(): void
    {
        // Seiten-Parameter
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = (string)($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array)($_REQUEST['data'] ?? []);
        $this->data['status_id'] = $this->id;
        // Neu: Anlegen bzw. das Hiddenfeld 'new' des Formulars - die Id 0 allein
        // unterscheidet nicht zwischen neuem Status und Grundstatus "enabled"
        $this->isNew = ($this->function === 'create') || !empty($_REQUEST['new']);

        // status "enabled", "disabled": nur die Gruppenzuordnung (Superuser)
        if (!$this->isNew && $this->id <= 1
            && !(in_array($this->function, ['edit', 'save', 'errors'], true) && $this->backend->isSuperuser())) exit;

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
    // edit() Hilfsfunktionen

    private function _editCreate(): void
    {
        $this->data = $this->status->init();
        $this->id = 0;
    }

    private function _editLoad(): void
    {
        $this->data = $this->status->load($this->id);
        if (!$this->data) {
            // Angeforderter Datensatz nicht gefunden
            $this->function = 'skip';
        }
        // Wenn Daten kopiert werden sollen, dann jetzt Id zurücksetzen
        if ($this->function == 'copy') {
            $this->id = 0;
            $this->isNew = true;
            $this->data['position']++;
        }
    }

    /**
     * Überschrift für Formular und Detailansicht. Beim Löschen und in der
     * Detailansicht steht der Status selbst darüber - mit seinem Icon in seiner Farbe.
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
     * function=save: speichern. $fields leer = alle Schema-Felder aus $data.
     */
    private function _editSave(): void
    {
        // Prüfe Daten und speichere - die Mehrfachauswahl schickt ohne Auswahl nichts
        // (= keine Gruppe); bei den beiden Grundstatus nur die Gruppen
        if ($this->backend->isSuperuser()) $this->data['group_ids'] = (array)($this->data['group_ids'] ?? []);
        $saved = (!$this->isNew && $this->id <= 1)
            ? $this->status->saveGroups($this->id, (array)($this->data['group_ids'] ?? []))
            : $this->status->save([], $this->data);
        if ($saved) {
            // Balloon Message
            $msg = $this->cms->snippet('status.message_status_saved');
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
     * function=delete_confirm: löschen.
     *
     * Der Ersatz-Status muss VOR delete() gesetzt werden - Status::hookOnDelete() läuft
     * innerhalb von delete() und holt den Wert dort ab.
     */
    private function _editDeleteConfirm(): void
    {
        // Ersatzstatus für Tabelle record festlegen
        $replacementId = (int)($_REQUEST['data']['replacement_id'] ?? 1);
        $this->status->setReplacementId($replacementId);

        // Status löschen, die Ersetzung in `record` übernimmt ein Hook
        if ($this->status->delete($this->id)) {
            // Balloon Message
            $msg = $this->cms->snippet('status.message_status_deleted');
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
     * Bearbeitbar sind nur `label` und `icon`. `color_class` steht zwar im Schema, hat
     * hier aber kein Eingabefeld - ein neu angelegter Status bekommt also keine Farbe.
     *
     * Bei der Position wählt der Redakteur den VORGÄNGER; der Optionsschlüssel ist
     * dessen position + 1, also die Zielposition. Der Eintrag "an den Anfang" trägt den
     * Schlüssel 5, weil die Positionen 1 bis 4 den mitgelieferten Status vorbehalten
     * sind - aus demselben Grund führt die Abfrage nur Einträge ab status_id 4 auf.
     */
    private function _editForm(): void
    {
        if ($this->function == 'save') $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Status/status_edit.php" method="post"' . $changed . '>';

        // Load Form
        $this->backend->formInit('status.edit_form');

        // Grundstatus (0, 1): nur die Gruppen
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

        // Benutzergruppen, die den Status setzen dürfen (nur Superuser)
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
     * Benutzergruppen für die Zuordnung: group_id => Bezeichnung (ohne Superuser).
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

    /** Bezeichnung der Superuser-Gruppe (sie darf jeden Status setzen) */
    private function _superuserLabel(): string
    {
        $this->db->pushResult();
        $this->db->query("SELECT `label` FROM `group` WHERE `group_id` = ?", 'i', [InstanceCache::SUPERUSER_GROUP_ID]);
        $label = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->popResult();
        return $this->cms->textOrSnippet($label);
    }

    /**
     * function=delete: Löschbestätigung mit Auswahl des Ersatz-Status.
     *
     * `replacement_id` ist kein Feld der Tabelle. Die Felddefinition wird von
     * `status_id` geborgt (gleicher Typ, gleiche Prüfungen) und nur neu beschriftet.
     *
     * Database::fetchPairs() liefert die zweispaltige Abfrage direkt als
     * id => label-Array, das array_map() löst darin noch #-Snippets auf.
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
