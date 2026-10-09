<?php
/**
 * Backend-Oberfläche für die Medienkonverter (Tabelle `converter`).
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     converter_list.php     -> list()             Konverterliste
 *     converter_info.php     -> info()             Detailansicht
 *     converter_create.php   -> chooseProcessor()  Schritt 1: Prozessor wählen
 *     converter_edit.php     -> edit()             Schritt 2 und alles weitere
 *     converter_search.php   -> search()           Suchmaske und Suchausführung
 *
 * ANLEGEN IN ZWEI SCHRITTEN
 * --------------------
 * Wie bei den Eingabefeldern: erst der Prozessor, dann das Formular. Der Prozessor
 * bestimmt, welche Einstellungen es überhaupt gibt, und lässt sich nachträglich nicht
 * wechseln - deshalb steht die Auswahl in einem eigenen Schritt (chooseProcessor()),
 * gruppiert nach Medienart.
 *
 * DER PROZESSOR LIEFERT SEINEN TEIL DES FORMULARS SELBST
 * --------------------
 * Diese Klasse rendert nur die gemeinsamen Felder (label, Mime-Filter, extension).
 * Alles Prozessorspezifische kommt aus zwei Dateien im Prozessor-Verzeichnis, die
 * mitten in der Methode eingebunden werden:
 *
 *     {Prozessor}/backend/form.php   Eingabefelder   (in _editForm())
 *     {Prozessor}/backend/info.php   Detailanzeige   (in info())
 *
 * Der include läuft im Gültigkeitsbereich der Methode, die Dateien greifen also direkt
 * auf $this->backend, $this->converter und $this->data zu. Ein neuer Prozessor braucht
 * damit keine Änderung an dieser Klasse - aber auch: er bekommt vollen Zugriff auf sie.
 *
 * @see Camindo\Core\Modules\Converter\Converter  Dataset/Model, erweitert sein Schema
 *                                                   um die Felder des Prozessors
 * @see Camindo\Core\MediaProcessor                 Basisklasse der Prozessoren
 * @see Camindo\Core\Modules\Area\FieldBackend     wählt Konverter für Thumbnail-Felder
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
     * Konverterliste (backend/converter_list.php).
     *
     * Standardsortierung 'processor ASC, label ASC' aus Converter - die Konverter
     * desselben Prozessors stehen dadurch beieinander.
     */
    public function list()
    {
        $id = (int)($_REQUEST['id'] ?? 0);

        // Seitenelemente
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

        // Liste
        // --------------------
        $f = $this->cms->select([
            'request' => 'all',
            'limit' => $this->cms->list_rows,
            'count' => 'true',
            'filtertext' => 'true',
        ], $this->converter);

        // Suchbegriffe oder geänderte Sortierung als lesbarer Text
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
            
            // Hole Ergebnisse (Dataset::fetch: mit Datennachbeareitung)
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
     * Die Mime-Filter werden zeilenweise eingegeben und hier als Liste dargestellt;
     * htmlspecialchars() läuft VOR dem Zerlegen, weil der fertige HTML-Block danach mit
     * dem Flag 'html' übergeben wird und infoAdd() ihn dann nicht mehr maskiert.
     *
     * Am Ende bindet die Methode {Prozessor}/backend/info.php ein - der Prozessor
     * stellt seine eigenen Einstellungen selbst dar. Der include läuft im
     * Gültigkeitsbereich dieser Methode, die Datei arbeitet also mit $data,
     * $this->backend und $this->converter weiter.
     */
    public function info()
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $tab = (string)($_REQUEST['tab'] ?? '');

        // Seitenelemente
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

        // Tabs: Einstellungen | Verwendet von (n) - die Thumbnail-Felder, die den
        // Konverter nutzen (`rel_field_converter`)
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
        // "Änderungen" (Audit-Log, Backend::auditTabAdd())
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
     * Suchmaske.
     *
     * Nur die gemeinsamen Felder - nach prozessorspezifischen Einstellungen lässt sich
     * nicht suchen, sie liegen in der JSON-Spalte `details`.
     */
    public function searchForm():void
    {
        // Seiten-Parameter
        $filter = (array) ($_REQUEST['filter'] ?? []);

        echo '<form action="Converter/converter_search.php" method="post">';

        // Bread crum navi
        $this->backend->setNaviLabel('#button_search');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#converter.headline_search');

        // Suchmaske des Converters
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
     * function=delete: Löschbestätigung.
     *
     * Verwenden Thumbnail-Felder den Konverter (`rel_field_converter`), steht das als
     * Warnung dabei: die Zuordnungen fallen per Fremdschlüssel weg, ein Feld kann
     * danach ohne Konverter dastehen und erzeugt dann keine Thumbnails mehr.
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
     * Suche ausführen.
     *
     * Wie in den anderen Modulen: keine eigene Trefferliste, sondern Suchlayer
     * schließen und den Listenlayer mit den Parametern in der URL neu laden.
     * json_encode maskiert alles, was das Javascript-Literal beenden könnte;
     * substr(..., 1) entfernt nur das öffnende Anführungszeichen, das schließende
     * beendet unten den String.
     */
    public function search():void
    {
        // Parameter aus Post
        $queryString = http_build_query($_POST);

        // Sicher für Javascript verpacken
        $safeParams = json_encode($queryString, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $safeUri = '"Converter/index.php?' . substr($safeParams, 1);

        // Referer-Layer mit diesen Parametern neu laden
        $this->backend->script("
            cApp.layers.close();
            cApp.layers.replace({$safeUri});
        ");
    }

    /**
     * Erstellen / kopieren / ändern / löschen (backend/converter_edit.php).
     *
     * Zustandsautomat über $_REQUEST['function'] wie in den anderen Modulen
     * (docs/developers/03-backend-entwicklung.md).
     *
     * Der Prozessorname wird gleich zu Anfang aus data[processor] gezogen und
     * gesäubert: aus ihm werden ein Verzeichnispfad (für den include der
     * Prozessor-Formulardatei) und ein Klassenname. Beim Bearbeiten überschreibt
     * _editLoad() den Wert mit dem gespeicherten - aus dem Request kommt er nur beim
     * Anlegen, direkt aus chooseProcessor().
     */
    public function edit(): void
    {
        // Seiten-Parameter
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
     * Schritt 1 beim Anlegen: Prozessor wählen (backend/converter_create.php).
     *
     * Zeigt die installierten Prozessoren nach Medienart gruppiert. Je Art gibt es im
     * Formular-Template einen Platzhalter {type_image_links}, {type_audio_links}, … der
     * hier mit den Links gefüllt wird; eine Art ohne installierten Prozessor bekommt
     * statt der Liste einen Leer-Block.
     *
     * Jeder Link ersetzt den Layer durch converter_edit.php mit function=create und dem
     * gewählten Prozessor. Die Liste stammt aus var/config/components/processors.php,
     * nicht aus der Datenbank (siehe Converter).
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
    // edit() Hilfsfunktionen

    /**
     * function=create: Formular mit den Defaults vorbelegen.
     *
     * Converter::init() verlangt den Prozessor als Vorgabe - erst mit ihm ist das
     * Schema vollständig und kann Defaults für seine Felder liefern.
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
     * Der gespeicherte Prozessorname gewinnt über den aus dem Request - der Prozessor
     * eines bestehenden Konverters lässt sich nicht wechseln.
     */
    private function _editLoad(): void
    {
        if ($this->data = $this->converter->load($this->id)) {
            $this->processorName = $this->converter->cleanProcessorName($this->data['processor']);
        }
        else {
            // Angeforderter Datensatz nicht gefunden
            $this->function = 'skip';
        }

        // Wenn Daten kopiert werden sollen, dann jetzt Id zurücksetzen
        if ($this->function == 'copy') {
            $this->id = 0;
        }
    }

    /**
     * Überschrift des Formulars. Beim Löschen die Bezeichnung des Konverters, sonst
     * die allgemeine Überschrift.
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
     * $fields leer heißt: alle Schema-Felder, die in $data stehen - und dazu gehören
     * nach dem Laden des Prozessors auch dessen eigene Felder, die in der JSON-Spalte
     * `details` landen. Eine feste Feldliste ginge hier also gar nicht.
     */
    private function _editSave(): void
    {
        $fields = [];
        if ($this->converter->save($fields, $this->data)) {
            // Balloon Message
            $msg = $this->cms->snippet('converter.message_converter_saved');
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
     */
    private function _editDeleteConfirm(): void
    {
        if ($this->converter->delete($this->id)) {
            // Balloon Message
            $msg = $this->cms->snippet('converter.message_converter_deleted');
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
     * Zweigeteilt: erst die gemeinsamen Felder aus dem Converter-Schema, dann per
     * include die Eingabefelder des Prozessors aus dessen backend/form.php. Die
     * eingebundene Datei läuft im Gültigkeitsbereich dieser Methode und ruft dort
     * dieselben $this->backend->formField()-Aufrufe auf; ihre Werte landen über das
     * erweiterte Schema in der JSON-Spalte `details`.
     *
     * Der Prozessor selbst steht nicht zur Auswahl, sondern nur als Text und
     * Hidden-Feld im Formular - er wurde in chooseProcessor() festgelegt.
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

        // {processor_note}: Platz für einen Hinweis des Prozessors unter den
        // Medientypen - z. B. Formate, die nur über einen externen Delegate laufen
        // (Magick: SVG, PDF). Hat ihn die form.php des Prozessors nicht belegt,
        // fällt er ersatzlos weg.
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
     * Infoseite, Tab "Verwendet von": je Thumbnail-Feld ein Teaser (verlinkt auf die
     * Feld-Info) mit dem Bereich dahinter. Ohne Verwendung ein Hinweis.
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
     * Infoseite, Tab "Einstellungen"
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
     * Infoseite, Tab "Used by..."
     */
    private function _infoUsage(array $data): void
    {
        // Liste alle Thumbnail-Felder, die den Converter nutzen

    }
}
