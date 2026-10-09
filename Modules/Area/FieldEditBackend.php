<?php
namespace Camindo\Core\Modules\Area;

class FieldEditBackend extends FieldBackend {

     /**
     * Erstellen / Kopieren / Ändern / Löschen
     * Nach Schritt 1 (Create) ist dies Schritt 2 
     * (backend/field_edit.php).
     *
     * Zustandsautomat über $_REQUEST['function'], wie in den anderen Modulen
     * (docs/developers/03-backend-entwicklung.md): create, edit, copy, save, errors, skip,
     * delete, delete_confirm.
     *
     * Zwei Besonderheiten vorweg, noch vor dem eigentlichen Ablauf:
     *
     *  - `name` und `type` kommen aus der URL (der Typ aus der Auswahl in create())
     *    und werden hier hart auf [a-z0-9_-] gefiltert, bevor irgendetwas damit
     *    geschieht - sie steuern die Wahl der Formularvorlage.
     *  - Heißt das Feld wie eine feste Spalte aus Cms::$schema, ist es ein Meta-Feld;
     *    dann gewinnt der im Schema hinterlegte Typ über den aus dem Request.
     */
    public function edit(): void
    {
        // Seiten-Parameter
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->areaId = (int)($_REQUEST['area_id'] ?? 0);
        $this->function = (string)($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array)($_REQUEST['data'] ?? []);
        $this->data['field_id'] = $this->id;

        // name
        $name = preg_replace('![^a-z0-9_-]+!', '', (string) ($this->data['name'] ?? ''));
        $this->data['name'] = $name;

        // type
        $type = preg_replace('![^a-z0-9_-]+!', '', (string) ($this->data['type'] ?? ''));
        // Spezialfall: Metafeld, Typ ist festgelegt im Schema
        if (isset($this->cms->schema[$name])) {
            $type = $this->cms->schema[$name]['type'];
        }
        $this->data['type'] = $type;

        $this->fieldSchema = $this->field->getSchema(); // Schema `field`
        $this->recordSchema = $this->cms->schema;       // Schema `record` (Meta-Felder)

        // Bread crum navi
        $this->backend->setNaviLabel('#button_' . $this->function);

        // Container header
        echo $this->backend->template('container_header');

        // Create
        if ($this->function == 'create') {
            $this->_editCreate();
        }

        // Load
        if (in_array($this->function, ['edit', 'copy', 'delete', 'delete_confirm'], true)) {
            $this->_editLoad();
        }

        // Zugehörigen Bereich laden
        // (gibt Bereichsname aus)
        if ($this->function !== 'skip') {
            echo $this->_loadArea();
        }

        // H1
        $this->_headline();

        // Save
        if ($this->function == 'save') {
            $this->_editSave();
        }

        // Zielbereiche mit Verknüpfungen abgewählt: Rückfrage
        if ($this->function == 'confirm_links') {
            $this->_editConfirmLinksForm();
        }

        // Delete confirm
        if ($this->function == 'delete_confirm') {
            $this->_editDeleteConfirm();
        }

        // Errors
        if (in_array($this->function, ['errors', 'skip'], true)) {
            $this->_editErrors();
        }

        // Edit form ('back': aus der Rückfrage zurück, mit den eingegebenen Daten)
        if (in_array($this->function, ['create', 'edit', 'copy', 'errors', 'back'], true)) {
            $this->_editForm();
        }

        // Delete form
        if ($this->function == 'delete') {
            $this->_editDeleteForm();
        }

        echo $this->backend->template('container_footer');
    }


    /**
     * function=create: Formular vorbelegen.
     *
     * Bei einem Meta-Feld wird die Schema-Definition aus Cms::$schema als Vorbelegung
     * übernommen (Typ, Beschriftung, Darstellung, Flags) - der Redakteur soll die
     * feste Spalte nicht von Grund auf neu beschreiben müssen.
     *
     * area_id muss gesetzt sein, bevor init() läuft: Field::hookOnCreate() berechnet
     * daraus die nächste freie `position`.
     */
    private function _editCreate() {
        // Metafeld: Werte, die im Schema definert sind, als Standardwerte einsetzen
        $name = $this->data['name'] ?? '';
        if (isset($this->cms->schema[$name])) {
            $this->data = $this->cms->schema[$this->data['name']];
            $this->data['name'] = $name;
        }
        // area_id wird für Berechnung von position benötigt
        $this->data['area_id'] = $this->areaId;
        $this->data = $this->field->init($this->data);
        // Zusammenfassung der Sichtbarkeitsfelder in einem
        $this->data['visibility'] = $this->_visibilitySerialize($this->data);
        $this->id   = 0;
    }

    /**
     * function=edit|copy|delete|delete_confirm: Feld laden und für das Formular
     * aufbereiten.
     *
     * Zwei Umformungen für die Anzeige:
     *   - die drei visibility-Spalten werden zum Pseudo-Feld 'visibility' gebündelt
     *   - height 0 bedeutet "automatisch" und wird als '(auto)' angezeigt; beim
     *     Speichern macht Field::hookOnSave() daraus per (int)-Cast wieder 0
     *
     * Beim Kopieren wird die Id verworfen und die Position um eins erhöht, damit die
     * Kopie hinter dem Original einsortiert wird.
     */
    private function _editLoad(): void
    {
        if ($this->data = $this->field->load($this->id)) {
            $this->areaId = (int)$this->data['area_id'];

            // alle 3 visibility-Optionen in ein Pseudo-Feld schreiben
            if ($this->data['type'] != 'fulltext') {
                $this->data['visibility'] = $this->_visibilitySerialize($this->data);
            }

            // height
            $this->data['height'] = (int)($this->data['height'] ?: 0);
            if ($this->data['height'] == 0) $this->data['height'] = '(auto)';

        } else {
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->cms->errorMsg()
            ]);
            $this->function = 'skip';
        }

        // Wenn Daten kopiert werden sollen, dann jetzt Id zurücksetzen
        if ($this->function == 'copy') {
            $this->id = 0;
            $this->data['position']++;
        }
    }

    /**
     * function=save: speichern.
     *
     * Vorher zwei Rückumwandlungen, die das Formular nötig macht:
     *   - 'visibility' (eine Checkbox-Gruppe) zurück in drei Spalten
     *   - beim `pattern` werden ^ und $ abgeschnitten. Im Formular stehen sie als
     *     feste Prefix-/Suffix-Zierde neben dem Eingabefeld (siehe Schema), gespeichert
     *     wird der Ausdruck ohne Anker.
     *
     * save([], ...) mit leerer Feldliste heißt: alle Schema-Felder schreiben, die in
     * $data vorkommen.
     */
    private function _editSave(): void
    {
        if ($this->data['type'] != 'fulltext') {
            // Visibility kommt aus Pseuo-Form - zurück in 3 Felder
            list (
                $this->data['visibility_input'],
                $this->data['visibility_search'],
                $this->data['visibility_list']
            ) = $this->_visibilityUnserialize($this->data);
        }

        // pattern
        // remove ^.. and ..$
        $pattern = (string) ($this->data['pattern'] ?? '');
        $pattern = ltrim($pattern, '^');
        $pattern = rtrim($pattern, '$');
        $this->data['pattern'] = $pattern;

        // pointer, link: Zielbereiche abgewählt, an denen noch Verknüpfungen hängen?
        // -> erst nachfragen (_editConfirmLinksForm()); mit delete_links=1 kommt die
        // Bestätigung, die Kanten fallen dann nach dem Speichern
        $deleteLinks = !empty($_REQUEST['delete_links']);
        $isMultiArea = $this->id > 0 && in_array($this->data['type'], $this->cms->multiAreaTypes, true);
        if ($isMultiArea && !$deleteLinks) {
            $this->linkCounts = $this->field->countRelationsOutside(
                $this->id,
                $this->system->sanitizeIds((array)($this->data['rel_area_ids'] ?? []), true)
            );
            if (!empty($this->linkCounts)) {
                $this->function = 'confirm_links';
                return;
            }
        }

        if ($this->field->save([], $this->data)) {
            if ($isMultiArea && $deleteLinks) {
                $this->field->removeRelationsOutside($this->id);
            }
            $this->backend->balloon('success', $this->cms->snippet('area.message_field_saved'));
            $this->backend->script("
                cApp.layers.invalidate();
                cApp.layers.close();
            ");
        } else {
            $this->function = 'errors';
        }
    }

    /**
     * Das Eingabeformular - der eigentliche Umfang dieser Klasse.
     *
     * Reihenfolge:
     *
     *  1. Vorabprüfung für Thumbnails: ohne ein Upload-Feld im selben Bereich gibt es
     *     nichts, wovon ein Thumbnail abgeleitet werden könnte -> Abbruch mit Hinweis.
     *  2. HTML-Vorlage nach Typ wählen (area.field_form_*). Sie bestimmt Anordnung und
     *     welche Platzhalter es überhaupt gibt.
     *  3. Immer vorhanden: type (fest), name, label, note, tooltip, position,
     *     display_type, Sichtbarkeit, default_field, min/max.
     *  4. Typabhängig: prefix/suffix/pattern (Text, Zahl, Datum), height (Text),
     *     custom_order und details.lookup_detailed (Relationen), Zielbereich(e)
 *     (relation/pointer), Mime-Typen
     *     (file+upload), Quellfeld und Konverter (file+thumbnail), Quellfelder
     *     (fulltext).
     *  5. Flags in zwei Gruppen: Verarbeitung (trim, lowercase, …) und Validierung
     *     (not_empty, unique, …). Welche zulässig sind, sagt Backend::processingFlags()
     *     bzw. Backend::$validationFlags je Typ.
     *
     * $targetId ist ein Zufallsschlüssel, mit dem sich die vom display_type abhängigen
     * Blöcke (Optionshinweis, custom_order) im DOM adressieren lassen; das Javascript
     * blendet sie beim Wechsel der Auswahl ein und aus. Deshalb wird die
     * display_type-Auswahl am Ende einmal per trigger('change') angestoßen - damit der
     * Anfangszustand stimmt, ohne ihn hier serverseitig nachzubauen.
     */
    private function _editForm(): void
    {
        if ($this->function == 'save') $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Area/field_edit.php" method="post"' . $changed . '>';

        $name = $this->data['name'] ?? '';
        if (isset($this->cms->schema[$name])) {
            $isMeta = true;
        }
        else {
            $isMeta = false;
        }
        $type = $this->data['type'];
        $displayType = $this->data['display_type'];
                
        // Thubnail: Zuerst prüfen, ob Upload-Feld im Bereich verfügbar ist,
        // ansonsten mit Fehlermeldung frühzeitig beenden
        if ($displayType == 'thumbnail') {
            $sourceOptions = [];
            $sql = "SELECT `field_id`,`label` FROM `field`"
                ." WHERE `type`='file' AND `area_id`=? AND `field_id` != ?"
                ." ORDER BY `position` ASC";
            $this->db->query($sql, 'ii', [$this->areaId, $this->id]);
            if ($this->db->num_rows > 0) {
                // Upload-Felder sammeln
                while ($row = $this->db->fetch()) {
                    $sourceOptions[(int)$row['field_id']] = $this->cms->textOrSnippet($row['label']);
                }
            }
            else {
                echo $this->backend->template('alert_danger', [
                    '{text}' => '{#area.error_no_uploadfield_available}'
                    .'<br><br>'
                    .$this->backend->template('button_back', [
                        '{onclick}' => "cApp.layers.replace('Area/field_create.php?area_id={$this->areaId}')"
                    ])
                ]);

                // Formular in diesem Fall nicht anzeigen
                echo $this->backend->template('container_footer');
                exit;
            }
        }

        // Formular template laden
        // --------------------
        if ($name == 'template_id') {
            $templateId = 'area.field_form_' . $name;
        }
        elseif (in_array($type, ['chars', 'json', 'time', 'encrypted'], true)) {
            $templateId = 'area.field_form_text';
        }
        elseif (in_array($type, $this->cms->numericTypes, true)) {
            $templateId = 'area.field_form_number';
        }
        elseif (in_array($type, $this->cms->dateTypes, true)) {
            $templateId = 'area.field_form_datetime';
        }
        elseif ($type == 'file') {
            if ($displayType == 'upload') $templateId = 'area.field_form_upload';
            else $templateId = 'area.field_form_thumbnail';
        }
        else {
            // Alle anderen Typen
            $templateId = 'area.field_form_' . $type;
        }
        $this->backend->formInit($templateId);

        // type
        // --------------------
        $typeHtml = $type . ' (' . $this->cms->snippet('area.label_field_type_' . $type) . ')';
        $this->backend->formStatic($this->field, 'type', $typeHtml);
        $this->backend->formHidden('data[type]', $type);

        // label
        // --------------------
        $this->backend->formField($this->field, [
            'name' => 'label',
            'value' => $this->data['label'] ?? '',
            'id' => $this->id,
            'primary' => true
        ]);

        // name
        // --------------------
        if ($isMeta) {
            $this->backend->formStatic($this->field, 'name', $name);
            $this->backend->formHidden('data[name]', $name);
        }
        else {
            $this->backend->formField($this->field, [
                'name' => 'name',
                'value' => $name,
                'id' => $this->id
            ]);
        }

        // note, tooltip, prefix, suffix, default, pattern, height
        // --------------------
        $inputFields = ['note', 'tooltip'];
        if (
            in_array($type, $this->cms->textTypes, true)
            || in_array($type, $this->cms->numericTypes, true)
            || in_array($type, $this->cms->dateTypes, true)
        ) {
            $inputFields = array_merge($inputFields,
                ['prefix', 'suffix', 'pattern']
            );
        }
        if (in_array($type, ['text', 'chars', 'encrypted'], true)) {
            $inputFields = array_merge($inputFields,
                ['height']
            );
        }
        foreach ($inputFields as $fieldName) {
            $this->backend->formField($this->field, [
                'name' => $fieldName,
                'value' => $this->data[$fieldName] ?? '',
                'id' => $this->id
            ]);
        }

        // default
        $defaultColDef = $this->fieldSchema['default'];
        if (in_array($type, $this->cms->dateTypes, true)) {
            // Date: add special note and tooltip
            $defaultColDef['note'] = '#area.note_format_date_or_function';
            $defaultColDef['tooltip'] = '#area.tooltip_format_date_or_function';
        }
        $this->backend->formField($this->field, [
            'colDef' => $defaultColDef,
            'name' => 'default',
            'value' => $this->data['default'] ?? '',
            'id' => $this->id,
        ]);

        // position
        // --------------------
        // Der Redakteur wählt den VORGÄNGER, gespeichert wird die Zielposition:
        // deshalb position + 1 als Optionsschlüssel. Der Eintrag 0 heißt "an den
        // Anfang". Das eigene Feld ist ausgeschlossen - es kann nicht sein eigener
        // Vorgänger sein.
        // Die Positionen müssen nicht lückenlos sein (z. B. 10, 14): vorausgewählt
        // wird deshalb der tatsächliche Vorgänger (letztes Feld mit kleinerer
        // Position), nicht der Schlüssel eigene Position.
        $options = [0 => $this->cms->snippet('position_at_start')];
        $ownPosition = (int)($this->data['position'] ?? 0);
        $selected = 0;
        $sql = "SELECT * FROM `field` WHERE `area_id` =? AND `field_id` !=? ORDER BY `position` ASC";
        $this->db->query($sql, 'ii', [$this->areaId, $this->id]);
        
        while ($row = $this->db->fetch()) {
            $position = (int)$row['position'] + 1;
            $options[$position] = $this->cms->textOrSnippet($row['label']);
            if ($this->id > 0 && (int)$row['position'] < $ownPosition) {
                $selected = $position;
            }
        }
        if ($this->id <= 0) {
            // neues/kopiertes Feld: position ist bereits die Zielposition
            $selected = $this->data['position'];
        }
        $this->backend->formField($this->field, [
            'id' => $this->id,
            'name' => 'position',
            'value' => $selected,
            'options' => $options,
        ]);

        // display_type
        // --------------------
        // Id für abhängige Elemente (onchange)
        $targetId = $this->crypt->randomKey(16);

        if ($type === 'file') {
            // keine Display Type auswahl
            $this->backend->formHidden('data[display_type]', $displayType);
        }
        else {
            if (($isMeta && $type === 'integer') // z. B. status_id
                || $name == 'template_id'
            ) {
                
                $displayTypes = $this->backend->displayTypes['meta'] ?? [];
            }
            else {
                // Display type anhand von Zuordnungstabelle
                $displayTypes = $this->backend->displayTypes[$type ?? ''] ?? [];
            }
            $options = [];
            foreach ($displayTypes as $key) {
                $options[$key] = $this->cms->snippet('area.label_display_type_' . $key);
            }
            $this->backend->formField($this->field, [
                'name' => 'display_type',
                'value' => $displayType ?? '',
                'id' => $this->id,
                'options' => $options,
                'template' => 'area.edit_form_display_type' // spezielles Template
            ]);
            
            // Platzhalter im speziellen Template ersetzen
            // cApp.forms.updateElement({element}, {action})
            $this->backend->formReplace('{element}', $targetId);
            if (in_array($type, $this->cms->relationTypes, true)) {
                $this->backend->formReplace('{elopt}', "lookup");
            }
            elseif ($type !== 'file' && !$isMeta) {
                $this->backend->formReplace('{elopt}', "select,multiselect,checkbox,radio");
            }
        }

        // Extension (13 §3.3)
        // --------------------
        // Eigener Abschnitt "Erweiterung" am Ende der Maske (area.field_extension_block):
        // Auswahl der aktiven Extensions, die zum Feldtyp passen, darunter ihre
        // Einstellungen im Fieldset (area.field_extension_options); das JavaScript
        // (cApp.extensions.fieldForm) blendet je Display-Typ die passenden ein und
        // lädt die Optionen der gewählten Extension nach (field_extension_options.php).
        // Auch für Meta-Felder wie online_at - sie haben eine Zeile in `field`, und
        // das virtuelle Schema liefert deren details an Backend::formField().
        // Gibt es für den Feldtyp keine Extension, entfällt der Abschnitt ganz.
        $extensions = $this->backend->extensionsFor($type);
        $extensionOptionsId = $this->crypt->randomKey(16);
        if ($extensions) {
            $this->backend->formReplace('{extension_block}', $this->backend->template('area.field_extension_block'));
            $details = is_array($this->data['details'] ?? null) ? $this->data['details'] : [];
            $extension = (string)($details['extension'] ?? '');
            $options = ['' => $this->cms->snippet('area.label_extension_none')];
            $types = [];
            foreach ($extensions as $extName => $ext) {
                $options[$extName] = $ext['label'];
                $types[$extName] = $ext['display_types'];
            }
            $this->backend->formField($this->field, [
                'name' => 'details.extension',
                'value' => $extension,
                'id' => $this->id,
                'options' => $options,
                'template' => 'area.edit_form_extension',
                'attr' => [
                    'data-ext-types'   => htmlspecialchars(json_encode($types), ENT_QUOTES),
                    'data-ext-target'  => $extensionOptionsId,
                    'data-ext-url'     => "Area/field_extension_options.php?area_id={$this->areaId}&id={$this->id}&type=" . rawurlencode($type),
                ],
            ]);
            $this->backend->formReplace('{extension_options}',
                $this->_extensionOptionsHtml($extension, (array)($details['extension_options'] ?? [])));
        }
        else {
            $this->backend->formReplace('{extension_block}', '');
        }
        $this->backend->formReplace('{extension_options_id}', $extensionOptionsId);

        // note_default Hinweis zur Konfiguration wählebarer Optionen
        // --------------------
        // Erklärt, wie man im Feld `default` die auswählbaren Optionen hinterlegt.
        $noteHtml = '';
        if(!in_array($type, $this->cms->relationTypes, true)
            && $type !== 'file'
            && !$isMeta
         ) {
            $noteHtmlInner = $this->backend->template('area.field_default_options_note');
            $noteHtml = $this->backend->template('edit_form_block', [
                '{label}' => '',
                '{class}' => '',
                '{field}' => $noteHtmlInner,
            ]);
        }
        $this->backend->formReplace('{note_default}', $noteHtml);
        $this->backend->formReplace('{note_default_id}', $targetId);

        // custom_order, details.lookup_detailed
        // --------------------
        // Beide im Block {custom_order_id}, den das Javascript nur bei
        // display_type 'lookup' einblendet
        if (in_array($type, $this->cms->relationTypes, true)) {
            // ('link' ist immer handsortiert, Field::hookOnSave() - kein Schalter)
            if (!in_array($type, $this->cms->dependentTypes, true)) {
                $this->backend->formField($this->field, [
                    'name' => 'custom_order',
                    'value' => $this->data['custom_order'],
                    'id' => $this->id
                ]);
            }
            $this->backend->formField($this->field, [
                'name' => 'details.lookup_detailed',
                'value' => (int)($this->data['details']['lookup_detailed'] ?? 0),
                'id' => $this->id
            ]);
            $this->backend->formReplace('{custom_order_id}', $targetId);
        }

        // visibility (combine all 3 options in one pseudo-field)
        // --------------------
        if ($type !== 'fulltext') {
            $colDef = $this->_visibilityColDef(); // (pseudo field 'visibility')
            if ($displayType == 'thumbnail') {
                // Thumbnail-Feld keine Sichtbarkeit in der Eingabemaske 
            }
            $this->backend->formField($this->field, [
                'colDef' => $colDef,
                'name' => 'visibility',
                'value' => $this->data['visibility'],
                'id' => $this->id,
                'options' => $this->_visibilityOptions($displayType)
            ]);
        }

        // default field
        // --------------------
        $this->backend->formField($this->field, [
            'name' => 'default_field',
            'value' => $this->data['default_field'],
            'id' => $this->id,
        ]);

        // multilingual
        // --------------------
        // Nur bei Typen, deren Inhalte je Sprache geführt werden können, und nicht
        // bei Feldern auf Metaspalten (Field::hookOnSave() setzt es dort zurück).
        // Ohne das Feld bleibt der Platzhalter {data_multilingual} leer.
        // Upload-Felder ja, Thumbnails nein - die erben es vom Upload-Feld.
        // Umschalten lässt sich das Kennzeichen nur beim Anlegen (auch Kopie): bei
        // einem bestehenden Feld ginge es an den Bestand (Übersetzungen) - das
        // macht nur die ausdrückliche Umwandlung (convert()), hier steht es fest.
        if (!$isMeta
            && (in_array($type, $this->cms->multilingualTypes, true)
                || ($type === 'file' && $displayType === 'upload'))
        ) {
            if ($this->id > 0) {
                $multilingualHtml = !empty($this->data['multilingual'])
                    ? $this->backend->template('icon_checked') . ' ' . $this->cms->snippet('yes')
                    : $this->cms->snippet('no');
                $multilingualHtml .= ' <small class="c9-grey-50">{#area.note_field_convert_multilingual_fixed}</small>';
                $this->backend->formStatic($this->field, 'multilingual', $multilingualHtml);
            }
            else {
                $this->backend->formField($this->field, [
                    'name' => 'multilingual',
                    'value' => $this->data['multilingual'] ?? 0,
                    'id' => $this->id,
                ]);
            }
        }

        // min + operator
        // --------------------
        $this->_editFormMinMaxField('min');

        // max + operator
        // --------------------

        if (str_ends_with($type, '1')) {
            $this->backend->formStatic($this->field, 'max', 1);
        }
        else {
            $this->_editFormMinMaxField('max');
        }

        // processing flags
        // --------------------
        $processingOptions = [];
        $processingKeys = array_flip($this->backend->processingFlags($type));
        foreach ($processingKeys as $key => $val) {
            $processingOptions[$key] = htmlspecialchars($this->cms->snippet('area.flag_' . $key));
        }
        if (!empty($processingKeys)) {
            $colDef = $this->fieldSchema['flags'];
            $colDef['label'] = '#area.headline_processing_flags';
            $this->backend->formField($this->field, [
                'colDef' => $colDef,
                'name' => 'flags',
                'value' => $this->data['flags'] ?? '',
                'id' => $this->id,
                'options' => $processingOptions,
                'placeholder' => '{data_processing_flags}'
            ]);
        }

        // validation flags
        // --------------------
        $validationOptions = array_flip($this->backend->validationFlags[$type] ?? []);
        if (!empty($validationOptions)) {
            foreach ($validationOptions as $key => &$val) {
                $val = htmlspecialchars($this->cms->snippet('area.flag_' . $key)); 
            }
            unset($val);
            $colDef = $this->fieldSchema['flags'];
            $colDef['label'] = '#area.headline_validation_flags';

            // wenn global... in Flags, dann Hinweis einblenden
            // (mittels speziellem inputField-Template)
            if (
                isset($validationOptions['unique'])
                || isset($validationOptions['unique_in_area'])
            ) {
                $inputFieldTemplate = 'area.group_container_flags';
            }

            $this->backend->formField($this->field, [
                'colDef' => $colDef,
                'name' => 'flags',
                'value' => $this->data['flags'] ?? '',
                'id' => $this->id,
                'options' => $validationOptions,
                'template' => $inputFieldTemplate ?? '',
                'placeholder' => '{data_validation_flags}'
            ]);
        }

        // Zielbereiche sammeln (sowohl für rel_area_id als auch rel_area_ids) -
        // siehe _relAreaOptions()
        // --------------------
        $relAreaOptions = [];
        if (in_array($type, $this->cms->relationTypes, true)) {
            $relAreaOptions = $this->_relAreaOptions($type);
        }

        // rel_area_id (select)
        // --------------------
        if (str_starts_with($type, 'relation')) {
            $this->backend->formField($this->field, [
                'name' => 'rel_area_id',
                'value' => (int)($this->data['rel_area_id'] ?? 0),
                'id' => $this->id,
                'options' => $relAreaOptions,
                'selectPlease' => true
            ]);
        }
        
        // rel_area_ids (multiselect) - pointer, link
        // --------------------
        if (in_array($type, $this->cms->multiAreaTypes, true)) {
            // Typ 'reltable' (Inhalt liegt in `rel_field_area`) - formField() behandelt
            // ihn wie 'json', die Umbiegung des Typs ist deshalb nicht mehr nötig.
            $colDef = $this->field->getSchema()['rel_area_ids'];
            $this->backend->formField($this->field, [
                'name' => 'rel_area_ids',
                'value' => (array)($this->data['rel_area_ids'] ?? []),
                'id' => $this->id,
                'options' => $relAreaOptions,
                'colDef' => $colDef
            ]);
        }

        // file types
        // --------------------
        if ($type === 'file') {
            
            // upload
            if ($displayType === 'upload') {
                    
                // media_types
                // --------------------
                $mimeOptions = array_flip(array_keys($this->field->mimeTypes));
                foreach ($mimeOptions as $key => &$val) {
                    $val = $this->cms->snippet('area.mime_type_' . $key);
                }
                unset($val);
                $this->backend->formField($this->field, [
                    'id' => $this->id,
                    'name' => 'details.media_types',
                    'value' => $this->data['details']['media_types'] ?? [],
                    'options' => $mimeOptions,
                ]);

                // media_types_incl
                // --------------------
                $this->backend->formField($this->field, [
                    'id' => $this->id,
                    'name' => 'details.media_types_incl',
                    'value' => $this->data['details']['media_types_incl'] ?? '',
                ]);

                // media_types_excl
                // --------------------
                $this->backend->formField($this->field, [
                    'id' => $this->id,
                    'name' => 'details.media_types_excl',
                    'value' => $this->data['details']['media_types_excl'] ?? '',
                ]);
            }

            // thumbnail
            elseif ($displayType === 'thumbnail') {

                // parent_field_id
                // --------------------
                $this->backend->formField($this->field, [
                    'id' => $this->id,
                    'name' => 'parent_field_id',
                    'value' => $this->data['parent_field_id'],
                    'options' => $sourceOptions,
                ]);

                // converter_ids
                // --------------------
                $converterOptions = $this->_converterOptions();
                $this->backend->formField($this->field, [
                    'id' => $this->id,
                    'name' => 'converter_ids',
                    'value' => $this->data['converter_ids'] ?? [],
                    'options' => $converterOptions
                ]);
            }

            // Authentifizierung (Upload und Thumbnail)
            // --------------------
            // user_auth: Dateien nur über file.php an angemeldete Frontend-User
            // aus den angehakten Profil-Bereichen (Instanz des Bereichs und ihre
            // lesbaren Instanzen). Die Checkbox-Liste ist nur mit gesetztem
            // Schalter sichtbar (Script unten).
            if (in_array($displayType, ['upload', 'thumbnail'], true)) {
                $this->backend->formField($this->field, [
                    'id' => $this->id,
                    'name' => 'details.user_auth',
                    'value' => (int)($this->data['details']['user_auth'] ?? 0),
                ]);
                $instanceId = (int)($this->backend->areaData($this->areaId)['instance_id'] ?? 0);
                $profileOptions = $this->backend->profileAreaOptions($instanceId);
                if (empty($profileOptions)) {
                    $this->backend->formReplace('{data_details_profile_area_ids}', $this->backend->template('alert_warning', [
                        '{text}' => '{#area.no_profile_areas_found}'
                    ]));
                }
                else {
                    $this->backend->formField($this->field, [
                        'id' => $this->id,
                        'name' => 'details.profile_area_ids',
                        'value' => $this->system->sanitizeIds((array)($this->data['details']['profile_area_ids'] ?? []), true),
                        'options' => $profileOptions,
                    ]);
                }
            }
        }

        // fulltext
        if ($type === 'fulltext') {
            // source_fields
            // --------------------
            // Textfelder und Upload-Felder (Dateiname), keine Thumbnails - des Bereichs
            // und über seine link-/relation-Felder der Zielbereiche
            // (Field::fulltextSourceOptions()); je Zielbereich eine Zwischenüberschrift
            $options = [];
            $group = '';
            foreach ($this->field->fulltextSourceOptions($this->areaId) as $key => $option) {
                if ($option['group'] !== $group) {
                    $group = $option['group'];
                    $options['group:' . count($options)] = ['group' => $group];
                }
                $options[$key] = $option['label'];
            }
            if (empty($options)) {
                // Hinweis: Es sind keine Textfelder im Bereich definiert
                $nothingFoundBlock = $this->backend->template('alert_warning', [
                    '{text}' => '{#area.no_text_fields_found}'
                ]);
                $this->backend->formReplace('{data_source_fields}', $nothingFoundBlock);
            }
            else {
                // Create pseudo field
                // Content will be written by Field->hookOnSaved into external table
                $colDef = [
                    'name' => 'source_fields',
                    'label' => '#area.field_field_source_fields',
                    'type' => 'json',
                    'display_type' => 'checkbox'
                ];
                $this->backend->formField($this->field, [
                    'id' => $this->id,
                    'colDef' => $colDef,
                    'name' => 'source_fields',
                    'value' => $this->data['source_fields'],
                    'options' => $options,
                ]);
            }

            // visibility_search (speziell für 'fulltext')
            // --------------------
            $this->backend->formField($this->field, [
                'id' => $this->id,
                'name' => 'visibility_search',
                'value' => $this->data['visibility_search']
            ]);

            $this->backend->formHidden('data[display_type]', 'text');
        }
        
        // Hidden-Felder
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('data[area_id]', $this->areaId);
        $this->backend->formHidden('function', 'save');

        // Print form
        echo $this->backend->form('form_save_cancel_note', removePlaceholders:true);

        // JavaScripte
        // - Anweisung für growing textarea, nach Ajax-Load
        $script = "cApp.ui._initTextareaGrow(\$('.c9-layer:last'));";

        // - display_type-Liste triggern
        $script .= "\$('.c9-layer:last select[name=\"data[display_type]\"]').trigger('change');";
        // - Profil-Bereiche nur bei gesetztem user_auth zeigen
        if ($type === 'file' && in_array($displayType, ['upload', 'thumbnail'], true)) {
            $script .= "(function(){"
                . "var \$l=\$('.c9-layer:last'),\$s=\$l.find('input[name=\"data[details][user_auth]\"]'),"
                . "\$p=\$l.find('.c9-user-auth-areas');"
                . "function t(){\$p.toggle(\$s.filter(':checked').length>0);}"
                . "\$s.on('change',t);t();"
                . "})();";
        }
        $this->backend->script($script);

        echo '</form>';
    }

    /**
     * Auswahlliste der Zielbereiche für relation (select, rel_area_id) und
     * pointer/link (multiselect, rel_area_ids).
     *
     * Was erscheint:
     *
     *     Instanz        die Instanz des Quellbereichs und deren lesbare Instanzen
     *                    (Field::targetInstanceIds()) - Bereiche fremder Instanzen mit
     *                    Zusatz "(Instanz)"
     *     Gruppenrechte  nur Bereiche mit Leserecht der Benutzergruppe (Access::areaRead())
     *     relation       der eigene Bereich bleibt sichtbar, aber gesperrt
     *     link           nur ABHÄNGIGE Bereiche (area.dependent = 1), nicht der eigene;
     *                    alles andere fehlt (Field::hookOnSave() prüft beim Speichern)
     *     Ordner         gesperrt (keine Datensätze), und nur die, unter denen ein
     *                    wählbarer Bereich liegt - die Einrückung entsteht aus `depth`,
     *                    die Reihenfolge aus `lft`; Template-Bereiche fehlen
     *     bereits gewählte Ziele erscheinen immer, auch außerhalb von Instanz und
     *     Rechten - sonst würden sie beim Speichern stillschweigend abgewählt
     *
     * Gesperrte Einträge tragen den Präfix 'disabled_' im Schlüssel und sind damit
     * keine gültige Id mehr (Backend::formField() rendert sie als disabled).
     *
     * @return array  area_id|'disabled_'.area_id => Label
     */
    private function _relAreaOptions(string $type): array
    {
        $access = $this->backend->access();
        $instanceIds = $this->field->targetInstanceIds($this->areaId);
        $ownInstanceId = (int)($this->areaData['instance_id'] ?? 0);
        $isLink = in_array($type, $this->cms->dependentTypes, true);
        $isRelation = str_starts_with($type, 'relation');

        // bereits gewählte Ziele (aus dem Formular bzw. der Felddefinition)
        $currentIds = $isRelation
            ? $this->system->sanitizeIds([(int)($this->data['rel_area_id'] ?? 0)], true)
            : $this->system->sanitizeIds((array)($this->data['rel_area_ids'] ?? []), true);
        $currentSql = empty($currentIds) ? '' : " OR `area_id` IN (" . implode(',', $currentIds) . ")";

        $this->db->query("SELECT `area_id`,`instance_id`,`depth`,`lft`,`rgt`,`label`,`type`,`dependent` FROM `area`"
            ." WHERE `type` != 'template' AND `deleted`=0"
            ." AND (`instance_id` IN (" . implode(',', $instanceIds) . ")" . $currentSql . ")"
            ." ORDER BY `lft` ASC");
        $rows = $this->db->fetchAll();

        // 1. Durchgang: wählbar / gesperrt / weg
        $entries = [];      // [area_id, key, label, lft, rgt, isFolder]
        $selectable = [];   // [lft, rgt] der wählbaren Bereiche (für die Ordner)
        foreach ($rows as $areaData) {
            $areaId = (int)$areaData['area_id'];
            $isCurrent = in_array($areaId, $currentIds, true);
            $label = str_repeat('&nbsp; ', (int)$areaData['depth'] * 2) . $this->cms->textOrSnippet($areaData['label']);
            if ((int)$areaData['instance_id'] !== $ownInstanceId) {
                $instanceLabel = (string)($this->instanceCache->instance((int)$areaData['instance_id'])['label'] ?? '');
                $label .= strtr($this->cms->snippet('parenthetical_note'), [
                    '{text}' => $this->cms->textOrSnippet($instanceLabel)
                ]);
            }
            if ($areaData['type'] === 'folder') {
                $entries[] = [$areaId, 'disabled_' . $areaId, $label, (int)$areaData['lft'], (int)$areaData['rgt'], true];
                continue;
            }
            if ($isCurrent) {
                $key = (string)$areaId;
            }
            elseif (!$access->areaRead($areaId)) {
                continue;
            }
            elseif ($isRelation && $areaId === $this->areaId) {
                // relation - eigener Content-Bereich kann nicht Zielbereich werden
                $key = 'disabled_' . $areaId;
            }
            elseif ($isLink && (empty($areaData['dependent']) || $areaId === $this->areaId)) {
                // link - nur abhängige Bereiche, nicht der eigene
                continue;
            }
            else {
                $key = (string)$areaId;
            }
            if ($key === (string)$areaId) $selectable[] = [(int)$areaData['lft'], (int)$areaData['rgt']];
            $entries[] = [$areaId, $key, $label, (int)$areaData['lft'], (int)$areaData['rgt'], false];
        }

        // 2. Durchgang: Ordner nur, wenn darunter etwas Wählbares liegt
        $options = [];
        foreach ($entries as [$areaId, $key, $label, $lft, $rgt, $isFolder]) {
            if ($isFolder) {
                $hasSelectable = false;
                foreach ($selectable as [$sLft, $sRgt]) {
                    if ($sLft > $lft && $sRgt < $rgt) { $hasSelectable = true; break; }
                }
                if (!$hasSelectable) continue;
            }
            $options[$key] = $label;
        }
        return $options;
    }

    /**
     * Alle Konverter als Optionen des sortierbaren Feldes converter_ids
     * (converter_id => Bezeichnung mit Prozessor). Nicht nur die gewählten: das
     * Formular muss weitere anbieten. Die gespeicherte Reihenfolge stellt
     * Backend::formField() (display_type 'sortable') selbst her - die gewählten
     * oben in der Reihenfolge von $value, der Rest darunter.
     */
    private function _converterOptions(): array
    {
        $options = [];
        $this->db->pushResult();
        $this->db->query("SELECT `converter_id`, `processor`, `label` FROM `converter` ORDER BY `type` ASC, `processor` ASC, `label` ASC");
        foreach ($this->db->fetchAll() as $row) {
            $options[(int)$row['converter_id']] = $this->cms->textOrSnippet((string)$row['label']) . ' (' . $row['processor'] . ')';
        }
        $this->db->popResult();
        return $options;
    }

    private function _editFormMinMaxField($name): void
    {
        $type = $this->data['type'] ?? '';
        $colDef = $this->fieldSchema[$name];
        if (in_array($type, $this->cms->dateTypes, true)) {
            // date: add special note and tooltip
            $colDef['note'] = '#area.note_format_date_or_function';
            $colDef['tooltip'] = '#area.tooltip_format_date_or_function';
        }
        elseif (in_array($type, $this->cms->textTypes, true)) {
            // text: add special note
            $colDef['note'] = '#area.note_format_text_length';
        }
        // assign special template
        $this->backend->formField($this->field, [
            'colDef' => $colDef,
            'name' => $name,
            'value' => $this->data[$name] ?? '',
            'id' => $this->id,
            'template' => 'area.edit_form_input_' . $name,
        ]);
        if (
            $name == 'min' && $this->data['min_op'] == '>'
            || $name == 'max' && $this->data['max_op'] == '<'
        ) {
            // '>'/'<'
            $selected_1 = '';
            $selected_2 = 'selected';
        }
        else {
            // '>='/'<='
            $selected_1 = 'selected';
            $selected_2 = '';
        }
        $this->backend->formReplace('{selected_1}', $selected_1);
        $this->backend->formReplace('{selected_2}', $selected_2);
    }

    /**
     * Rückfrage beim Speichern eines pointer-/link-Feldes: in abgewählten
     * Zielbereichen hängen noch Verknüpfungen (Field::countRelationsOutside()).
     *
     * Zeigt je Bereich die Anzahl und lässt wählen: "Verknüpfungen löschen" schickt
     * das Formular mit delete_links=1 erneut ab (_editSave() speichert und räumt mit
     * Field::removeRelationsOutside() auf), "Abbrechen" führt mit den eingegebenen
     * Daten zurück ins Formular (function 'back'). Die Formulardaten reisen dafür
     * als Hidden-Felder mit.
     */
    private function _editConfirmLinksForm(): void
    {
        $items = '';
        $total = 0;
        foreach ($this->linkCounts as $areaId => $count) {
            $total += $count;
            $items .= '<li>' . $this->backend->teaser('area', (int)$areaId)
                . ': ' . strtr($this->cms->snippet('area.note_field_links_count'), ['{count}' => $count]) . '</li>';
        }

        echo '<form action="Area/field_edit.php" method="post">';
        $this->backend->formInit('area.field_links_confirm_form', [
            '{text}' => strtr($this->cms->snippet('area.alert_field_links_outside'), ['{count}' => $total]),
            '{list}' => $this->backend->template('ul', ['{items}' => $items]),
        ]);
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('delete_links', 1);
        $this->_formHiddenArray('data', $this->data);
        echo $this->backend->form();
        echo '</form>';
    }

    /**
     * Ein (verschachteltes) Array als Hidden-Felder name[key][sub]=… ins Formular.
     */
    private function _formHiddenArray(string $name, array $data): void
    {
        foreach ($data as $key => $value) {
            $fullName = $name . '[' . $key . ']';
            if (is_array($value)) $this->_formHiddenArray($fullName, $value);
            elseif ($value !== null) $this->backend->formHidden($fullName, (string)$value);
        }
    }



    /**
     * Wirklich löschen? Formular
     */
    private function _editDeleteForm(): void
    {
        echo '<form action="Area/field_edit.php" method="post">';

        $this->backend->formInit('area.field_delete_form');

        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');

        // Print form
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }

    /**
     * Löschung durchführen
     */
    private function _editDeleteConfirm(): void
    {
        if ($this->field->delete($this->id)) {
            // Balloon Message
            $msg = $this->cms->snippet('area.message_field_deleted');
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
     * Fehlermeldungen
     */
    private function _editErrors(): void
    {
        echo $this->backend->template('alert_danger',[
            '{text}' => $this->cms->errorMsg()
        ]);
    }

    // ====================
    // Hilfsfunktionen, um die Visibility Optionen als gemeinsames Pseudofeld zu behandeln
    // In der Tabelle drei Bit-Spalten, im Formular eine Checkbox-Gruppe. Die
    // Felddefinition dafür steht in keinem Schema - sie wird hier von Hand gebaut und
    // per 'colDef' an formField() übergeben.

    private function _visibilityColDef() {
        return [
            'name' => 'visibility',
            'type' => 'json',
            'label' => '#area.headline_field_visibility',
            'display_type' => 'checkbox'
        ];
    }

    /**
     * Welche der drei Sichtbarkeiten überhaupt zur Wahl stehen.
     *
     * Ein Thumbnail entsteht aus einem anderen Feld und wird nie eingegeben; es bleibt
     * nur die Sichtbarkeit in der Datensatzliste.
     *
     * @param string $displayType  display_type, weil manche Felder keine Sichtbarkeit erlauben
     */
    private function _visibilityOptions(string $displayType) {
        if ($displayType == 'thumbnail') {
            $options = [
                'list' => $this->cms->snippet('area.field_field_visibility_list')
            ];
        }
        else {
            $options = [
                'input' => $this->cms->snippet('area.field_field_visibility_input'),
                'search' => $this->cms->snippet('area.field_field_visibility_search'),
                'list' => $this->cms->snippet('area.field_field_visibility_list')
            ];
        }
        return $options;
    }

    private function _visibilitySerialize(array $data) {
        $dataVisibility = [];
        if (!empty($data['visibility_input']))  $dataVisibility[] = 'input';
        if (!empty($data['visibility_search'])) $dataVisibility[] = 'search';
        if (!empty($data['visibility_list']))   $dataVisibility[] = 'list';
        return $dataVisibility;
    }

    private function _visibilityUnserialize(array $data) {
        $dataVisibility = (array) ($data['visibility'] ?? []);
        return [
            (int)in_array('input', $dataVisibility, true),
            (int)in_array('search', $dataVisibility, true),
            (int)in_array('list', $dataVisibility, true)
        ];
    }

    // ====================
    // Hilfsfunktionen für Extensions

    /**
     * Optionen einer Extension nachladen (backend/field_extension_options.php) -
     * beim Wechsel der Extension-Auswahl in der Feld-Eingabemaske.
     *
     * Request: extension, type (Feldtyp), data[details][extension_options] (bisherige Werte)
     */
    public function extensionOptions(): void
    {
        $extension = preg_replace('/[^A-Za-z0-9]/', '', (string)($_REQUEST['extension'] ?? ''));
        $type = preg_replace('/[^a-z0-9_]/', '', (string)($_REQUEST['type'] ?? ''));
        if ($extension === '' || !isset($this->backend->extensionsFor($type)[$extension])) {
            return;
        }
        $options = (array)($_REQUEST['data']['details']['extension_options'] ?? []);
        echo $this->_extensionOptionsHtml($extension, $options);
    }

    /**
     * Optionsblock einer Extension: {Extension}/backend/form.php rendert mit
     * $backend/$cms Eingaben unter dem Namen $name[…] (13 §3.3); ohne form.php gibt
     * es keine Optionen.
     */
    private function _extensionOptionsHtml(string $extension, array $options): string
    {
        $file = $this->_extensionFile($extension, 'form.php');
        if ($file === null) return '';
        $html = (static function (string $file, \Camindo\Core\Backend $backend, \Camindo\Core\Cms $cms, array $options, string $name): string {
            ob_start();
            include $file;
            return (string)ob_get_clean();
        })($file, $this->backend, $this->cms, $options, 'data[details][extension_options]');
        if (trim($html) === '') return '';
        return $this->backend->template('area.field_extension_options', ['{options}' => $html]);
    }

}