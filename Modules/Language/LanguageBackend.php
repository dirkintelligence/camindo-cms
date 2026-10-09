<?php
/**
 * Backend-Oberfläche für die Frontend-Sprachen (Tabelle `language`).
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     language_list.php  -> list()   Sprachliste
 *     language_info.php  -> info()   Detailansicht
 *     language_edit.php  -> edit()   anlegen / kopieren / ändern / löschen
 *
 * Keine Suchmaske - die Liste ist kurz und wird ungeblättert ausgegeben (limit -1).
 *
 * BESONDERHEIT: LÖSCHEN
 * --------------------
 * Mit der Sprache verschwinden ihre Inhalte in allen Instanzen
 * (Language::hookOnDelete()). Das Löschformular nennt deshalb die Instanzen, die sie
 * führen; ist sie irgendwo Hauptsprache, bietet es das Löschen gar nicht erst an.
 *
 * @see Camindo\Core\Modules\Language\Language          Dataset/Model
 * @see Camindo\Core\Modules\Instance\InstanceBackend   fast wortgleiche Schwesterklasse
 * @see Camindo\Core\Page                                 wertet den Sprachcode in der URL aus
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
     * Sprachliste (backend/language_list.php).
     *
     * limit -1 heißt: keine Paginierung, alle Sprachen auf einer Seite. Sortiert nach
     * `position` - das ist die Reihenfolge, in der die Sprachen später im
     * Sprachumschalter erscheinen sollen.
     */
    public function list()
    {
        // Seitenelemente
        // --------------------

        // Bread crum navi
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

        // Language-Liste
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

            // Hole Ergebnisse (Dataset::fetch: mit Datennachbeareitung)
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
     * Detailansicht einer Sprache (backend/language_info.php).
     *
     * Die Überschrift baut _editHeadline() - dieselbe Methode wie im Formular; damit
     * die dortige Fallunterscheidung greift, wird $this->function vorher auf 'info'
     * gesetzt.
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

        // Language-Data
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

        // Reiter: Info | Änderungen (Audit-Log, Backend::infoAuditTabs())
        if ($this->backend->infoAuditTabs("Language/language_info.php?id={$id}", 'language', $id)) return;

        // Data
        // ---------------  
        $this->backend->infoAdd($this->language, 'label', $data['label'], ['primary']);
        $this->backend->infoAdd($this->language, 'code', $data['code']);
        $this->backend->infoAdd($this->language, 'endonym', $data['endonym']);
        $backendOptions = $this->backend->backendLanguageOptions();
        $this->backend->infoAdd($this->language, 'backend_code', $backendOptions[$this->cms->resolveBackendLang((string)$data['backend_code'])] ?? $data['backend_code']);
        // Ohne eigene Zeitzone gilt die aus der Konfiguration
        $timezone = (string)$data['timezone'];
        if ($timezone === '') {
            $timezone = $this->cms->serverTZ . ' ' . $this->cms->snippet('language.note_timezone_default');
        }
        $this->backend->infoAdd($this->language, 'timezone', $timezone);
        echo $this->backend->infoTable();

        echo $this->backend->template('container_footer');
    }

    /**
     * Erstellen / kopieren / ändern / löschen (backend/language_edit.php).
     *
     * Zustandsautomat über $_REQUEST['function'] wie in den anderen Modulen
     * (docs/developers/03-backend-entwicklung.md).
     */
    public function edit(): void
    {
        // Seiten-Parameter
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = (string)($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array)($_REQUEST['data'] ?? []);
        $this->data['language_id'] = $this->id;

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
        $this->data = $this->language->init();
        $this->id = 0;
    }

    /**
     * function=edit|copy|delete: Sprache laden.
     *
     * Beim Kopieren wird die Id verworfen und die Position um eins erhöht, damit die
     * Kopie hinter dem Original einsortiert wird.
     */
    private function _editLoad(): void
    {
        $this->data = $this->language->load($this->id);
        if (!$this->data) {
            // Angeforderter Datensatz nicht gefunden
            $this->function = 'skip';
        }
        // Wenn Daten kopiert werden sollen, dann jetzt Id zurücksetzen
        if ($this->function == 'copy') {
            $this->id = 0;
            $this->data['position']++;
        }
    }

    /**
     * Überschrift für Formular und Detailansicht.
     *
     * Nur beim Löschen und in der Detailansicht steht die Sprache selbst darüber -
     * dort ist sie die eigentliche Information.
     *
     * Die Platzhalter {icon} und {color_class} bleiben leer: die Spalten gibt es nicht
     * (siehe Klassenkommentar).
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
     * function=save: speichern. $fields leer = alle Schema-Felder aus $data.
     */
    private function _editSave(): void
    {
        // Prüfe Daten und speichere
        $fields= [];
        if ($this->language->save($fields, $this->data)) {
            // Balloon Message
            $msg = $this->cms->snippet('language.message_language_saved');
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
     * function=delete_confirm: löschen - die Inhalte der Sprache löscht
     * Language::hookOnDelete().
     */
    private function _editDeleteConfirm(): void
    {
        if ($this->language->delete($this->id)) {
            // Balloon Message
            $msg = $this->cms->snippet('language.message_language_deleted');
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

    private function _editErrors(): void
    {
        echo $this->backend->template('alert_danger',[
            '{text}' => $this->cms->errorMsg()
        ]);
    }

    /**
     * Das Eingabeformular.
     *
     * Drei einfache Felder und die Position. Bei der Position wählt der Redakteur den
     * VORGÄNGER; der Optionsschlüssel ist deshalb dessen position + 1, also die
     * Zielposition. Der Eintrag "an den Anfang" trägt den Schlüssel 5, weil die
     * Positionen 1 bis 4 den mitgelieferten Sprachen vorbehalten sind - aus demselben
     * Grund führt die Abfrage nur Sprachen ab language_id 4 auf.
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

        // backend_code: Backend-Sprache der Snippets (Cms::backendLanguages())
        // Vorbelegung bei einer neuen Sprache: aus dem Sprachteil des Codes
        // (de_AT -> de_DE), sofern es dafür Snippets gibt; ein früheres Kürzel
        // wie "de" erscheint als "de_DE"
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

        // timezone: leer = Vorgabe aus der Konfiguration (system.timezone)
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
     * function=delete: Löschbestätigung mit den Instanzen, die die Sprache führen.
     *
     * Ist sie Hauptsprache einer Instanz, gibt es keinen Löschknopf - erst dort eine
     * andere Hauptsprache wählen (Language::hookOnDelete() weist es ohnehin ab).
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
