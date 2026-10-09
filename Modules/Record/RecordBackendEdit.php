<?php
/**
 * Eingabemaske für Datensätze (Erweiterung für RecordBackend).
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     record_edit.php           -> edit()           anlegen / kopieren / ändern / löschen
 *     ajax_cancel.php           -> cancel()         Bearbeitung abbrechen
 *     ajax_lookup_preview.php   -> lookupPreview()  Verknüpfungen bearbeiten
 *     ajax_embed.php            -> embedAction()    eingebettete Inhalte (Feldtyp 'link')
 *     record_previous.php       -> updatePreviousOptions()  Vorgängerliste neu laden
 *     ajax_parent_lookup.php    -> parentLookupPreview()    Vaterdatensatz wählen
 *
 * Die aufwendigste Maske im Backend, aus drei Gründen: die Felder stehen nicht fest,
 * es wird nie am Original gearbeitet, und Verknüpfungen und Dateien werden schon
 * WÄHREND der Bearbeitung geschrieben, nicht erst beim Speichern.
 *
 * ES WIRD IMMER AN EINER ARBEITSKOPIE GEARBEITET
 * --------------------
 * Das ist der Schlüssel zum Verständnis dieser Klasse. Beim Öffnen der Maske legt
 * Record::checkOut() eine Kopie des Datensatzes mit tmp=1 und origin_id an; ab da
 * bezieht sich $this->id auf DIESE Kopie, nicht auf das Original. Erst das Speichern
 * (checkIn innerhalb von Record::save()) überträgt sie und räumt sie ab, "Abbrechen"
 * (cancel()) verwirft sie.
 *
 * Das löst mehrere Probleme auf einmal:
 *   - Eine Verknüpfung oder ein Upload braucht eine record_id, um gespeichert zu
 *     werden. Beim Anlegen eines neuen Datensatzes gibt es die noch nicht - deshalb
 *     entsteht die Arbeitskopie schon beim Öffnen des leeren Formulars
 *     (_editCreate() -> tmpInit()) und nicht erst beim Speichern.
 *   - Eine fehlgeschlagene Eingabeprüfung wirft nichts weg: Dateien und
 *     Verknüpfungen hängen bereits an der Arbeitskopie.
 *   - Das Frontend sieht nie einen halbfertigen Stand.
 *
 * BEARBEITUNGSSPERRE
 * --------------------
 * Die Arbeitskopie ist zugleich die Sperre: findet _editLoad() eine, arbeitet gerade
 * jemand anders an dem Datensatz. Angezeigt wird dann ein Hinweis mit Name und
 * Zeitpunkt sowie einem Link, der die Sperre mit unlock=1 übergeht.
 *
 * ANLEGEN IN ZWEI SCHRITTEN
 * --------------------
 * Ohne Bereich zeigt function=create erst die Bereichsauswahl (_editCreateForm());
 * erst mit Bereich entsteht die Arbeitskopie und die eigentliche Maske. Der Bereich
 * bestimmt schließlich, welche Felder es überhaupt gibt.
 *
 * WERTE WERDEN FÜR DIE ANZEIGE UMGEFORMT
 * --------------------
 * _editFormatLoadedValues() wandelt Datenbank- in Eingabeformate: Datum und Uhrzeit
 * ins lokale Format, Fließkommazahlen mit lokalem Dezimaltrennzeichen, Beträge von
 * Cent in Euro. Verknüpfungen werden zu Id-Arrays, damit ein Mehrfach-Auswahlfeld
 * damit umgehen kann. Den Rückweg geht Dataset beim Speichern.
 *
 * DER VATERDATENSATZ UND DIE POSITION GEHÖREN ZUSAMMEN
 * --------------------
 * Wie im Modul Area (siehe AreaBackend) wird die `position` nicht als Zahl eingegeben,
 * sondern über den VORANGEHENDEN Datensatz. Das Feld heißt beim Einrichten trotzdem
 * `position` - für den Redakteur ist das schlüssig, ohne Vaterdatensatz ist die
 * Position schlicht die Sortierung -, im Formular steht aber `previous_id`
 * (_editPreviousParams()); die Zahl rechnet TreeManagerTrait beim Speichern daraus aus.
 *
 * Beides hängt zusammen: gelistet werden immer nur die Geschwister unter dem aktuell
 * gewählten Vaterdatensatz. Wechselt der Redakteur ihn, ist die Liste hinfällig und
 * wird per Ajax neu geholt (updatePreviousOptions()) - egal ob der Vaterdatensatz über
 * ein Auswahlfeld oder über ein Lookup-Feld gewählt wird.
 *
 * MEHRSPRACHIGE FELDER
 * --------------------
 * Führt die Instanz des Bereichs mehr als eine Sprache (InstanceCache), bekommt jedes
 * Feld mit `multilingual` = 1 je Sprache ein Eingabefeld: data[feld][{language_id}],
 * Beschriftung mit dem Sprachkürzel als Zusatz (Backend::formField(), langId).
 * Die Darstellung wählt der Bereich (area.details.tabs):
 *
 *     tabs = 0   alle Sprachen untereinander auf einer Seite
 *     tabs = 1   ein Reiter je Sprache plus "Alle". Auf einem Sprach-Reiter zeigt die
 *                Maske nur diese Sprache; einsprachige Felder stehen in jedem Reiter.
 *                Ein Reiterwechsel speichert die Maske zwischen (function=tab ->
 *                Record::save() mit stayTemporary, siehe _editTab()) und lädt sie mit
 *                dem neuen Reiter - die anderen Sprachen bleiben im Arbeitsstand der
 *                Arbeitskopie erhalten (Dataset::save() führt sie je Sprache zusammen).
 *
 * Die Werte der Sprachen kommen aus dem Arbeitsstand der Arbeitskopie (dort stehen die
 * rohen Eingaben aller Sprachen, leer = nicht übersetzt), nach einem Request aus
 * diesem (_editLanguageValues()).
 *
 * ZWEI ARTEN, VERKNÜPFUNGEN ZU SETZEN
 * --------------------
 *     display_type != 'lookup'   Auswahlliste mit allen Datensätzen der Zielbereiche
 *                                (_relatedDatasetOptions()) - nur bei überschaubaren
 *                                Mengen sinnvoll, deshalb auf
 *                                admin.list_max_datasets begrenzt
 *     display_type == 'lookup'   Suchfenster; die Verknüpfung wird SOFORT geschrieben
 *                                (lookupPreview() -> Record::createRelations()), nicht
 *                                erst beim Speichern des Formulars
 *
 * FEST VERKNÜPFTE INHALTE (Feldtyp 'link', Camindo\Core\LinkTrait)
 * --------------------
 * Die Zieldatensätze gehören dem Datensatz. Als 'lookup' wie oben; als 'embed' stehen
 * ihre Eingabemasken in dieser Maske (_embedBlock(), backend-embedded.js,
 * ajax_embed.php -> embedAction()). Die Inhalte werden mit dem Datensatz ausgecheckt
 * (_editLoad() -> checkOutDependents()), zwischengespeichert (_embedSave()) und
 * eingecheckt (_editSave() -> checkInDependents()); Abbrechen verwirft sie mit
 * (Record::cancel()).
 *
 * @see Camindo\Core\TemporaryTrait                      checkOut / checkIn / cancel
 * @see Camindo\Core\Modules\Record\RecordBackend        gemeinsame Basis
 * @see Camindo\Core\Modules\Record\RecordBackendUpload  Dateien in dieser Maske
 * @see Camindo\Core\Modules\Record\RecordBackendInfo    die Detailansicht dazu
 * @see docs/developers/04-dataset-api.md
 */
namespace Camindo\Core\Modules\Record;

class RecordBackendEdit extends RecordBackend {

    // Eingaben der eingebetteten Inhalte (Felder vom Typ 'link', display_type
    // 'embed'): $_REQUEST['embed'][{record_id}][feld] - siehe _embedCell()
    protected array $embedData = [];

    /** Meldung „von einem anderen Benutzer gesperrt" (_editLoad()) */
    private string $lockAlert = '';

    /**
     * Erstellen / kopieren / ändern / löschen (backend/record_edit.php).
     *
     * Zustandsautomat über $_REQUEST['function'] wie in den anderen Modulen
     * (docs/developers/03-backend-entwicklung.md) - mit zwei Besonderheiten:
     *
     *  - 'create' hat zwei Ausprägungen: ohne Bereich die Bereichsauswahl, mit
     *    Bereich das eigentliche Formular (siehe Klassenkommentar).
     *  - Vor dem Formular-Zweig steht bei 'errors' zusätzlich
     *    _editRestoreSecuredFiles(): bereits gesicherte Uploads müssen zurück in die
     *    erneut angezeigte Maske, weil Datei-Felder nicht im POST stehen.
     *
     * Die Feldliste für das Speichern kommt aus dem Schema des Bereichs - eine feste
     * Liste wie in den anderen Modulen ginge hier nicht, die Felder sind ja
     * benutzerdefiniert.
     *
     * CHECK-OUT NUR PER POST (17-csrf.md §2.8)
     * --------------------
     * 'edit', 'copy' und 'create' mit Bereich legen eine Arbeitskopie an und setzen die
     * Bearbeitungssperre - ein Seiteneffekt. Per GET antwortet edit() deshalb nur mit
     * einem Platzhalter (_editOpenStub()): wurde der Layer aus der Oberfläche heraus
     * geöffnet (cApp.layers.add()/replace(), data-intent), holt das Javascript die
     * Maske sofort per POST nach - mit CSRF-Token. Stammt der Layer aus dem Hash
     * (Lesezeichen, F5, Zurück-Taste, ein Link von außen, Frontend-Editing), zeigt
     * der Platzhalter einen Knopf "Bearbeiten": erst der Klick checkt aus.
     */
    public function edit(): void
    {
        echo $this->backend->template('container_header');

        // Seiten-Parameter
        $this->function = (string)($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array)($_REQUEST['data'] ?? []);
        $this->embedData = (array)($_REQUEST['embed'] ?? []);
        
        $this->_resolveParams();
        
        $this->data['record_id'] = $this->id;

        // Bread crum navi
        $this->backend->setNaviLabel('#button_' . $this->function);

        // Check-out per GET: nur der Platzhalter (siehe oben)
        $checksOut = in_array($this->function, ['edit', 'copy'], true)
            || ($this->function === 'create' && !empty($this->areaId));
        if ($checksOut && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST' && PHP_SAPI !== 'cli') {
            $this->_editOpenStub();
            echo $this->backend->template('container_footer');
            return;
        }

        // Load
        if (in_array($this->function, ['edit', 'copy', 'delete'], true)) {
            $this->_editLoad();
        }

        // H1
        echo $this->headline();

        // Von einem anderen Benutzer gesperrt (_editLoad())
        if ($this->function === 'locked') {
            echo $this->lockAlert;
        }

        if (!empty($this->areaId)) {
            $schemaVirtual = $this->record->virtualSchema($this->areaId, addMetaFields:false);
        }

        if (in_array($this->function, ['edit', 'copy'], true)) {
            // Datumsfelder und Fließkommazahlen in lokales Format umwandeln
            // Relations als Array zur Verfügung stellen, wenn Eingabefeld diesem Format entspricht
            $this->_editFormatLoadedValues($schemaVirtual);
        }

        // Create
        if ($this->function === 'create' && !empty($this->areaId)) {
            $this->_editCreate();
        }

        // Geleerte Felder im Request nachtragen (siehe _editNormalizeSubmitted())
        if (in_array($this->function, ['save', 'tab'], true)) {
            $this->_editNormalizeSubmitted($schemaVirtual);
        }

        if ($this->function === 'save') {
            // Owner nur mit dem Zusatzrecht (Lookup-Feld der Maske, _editForm())
            $this->_editSave($this->service->saveFields($this->areaId, $this->data));
        }

        // Reiterwechsel (mehrsprachige Eingabe mit Reitern): zwischenspeichern
        if ($this->function === 'tab') {
            $this->_editTab($schemaVirtual);
        }

        // Delete confirm
        if ($this->function === 'delete_confirm') {
            $this->_editDeleteConfirm();
        }

        // Errors
        if (in_array($this->function, ['errors', 'skip'], true)) {
            $this->errors();
        }

        if ($this->function === 'create' && empty($this->areaId)) {
            // step1: choose area
            $this->_editCreateForm();
        }
        elseif (in_array($this->function, ['create', 'edit', 'copy', 'errors', 'tab'], true)) {
            if (in_array($this->function, ['errors', 'tab'], true)) {
                // Von _secureUploads() bereits gesicherte Dateien in die erneut
                // angezeigte Maske zurückspielen (Datei-Felder stehen nicht im POST)
                $this->_editRestoreSecuredFiles($schemaVirtual);
            }

            // step2: edit record
            $this->_editForm($schemaVirtual);
        }

        // Delete form
        if ($this->function == 'delete') {
            $this->_editDeleteForm();
        }

        echo $this->backend->template('container_footer');
    }


    /**
     * Das Eingabeformular.
     *
     * Läuft über das Schema des Bereichs und rendert jedes Feld mit
     * visibility_input = 1 (_editFormFields()). Was für ein Eingabefeld daraus wird,
     * sagt die Felddefinition - Backend::formField() erledigt das.
     *
     * Von Hand beschafft werden nur die Auswahlmöglichkeiten, die nicht in der
     * Felddefinition stehen können:
     *
     *     relation / pointer  Datensätze der Zielbereiche (_relatedDatasetOptions())
     *     template_id         die im Bereich zugelassenen Templates
     *     status_id           die Status
     *     parent_id           die übrigen Datensätze des Bereichs
     *     position            desgleichen, ggf. auf die Geschwister begrenzt
     *
     * Zwei Feldarten brauchen danach noch Nacharbeit an der Vorlage:
     *
     *     Lookup-Felder  bekommen die URLs für Suchen, Anlegen und Aktualisieren
     *                    sowie den fertigen Vorschaublock der bestehenden
     *                    Verknüpfungen
     *     Datei-Felder   bekommen den Vorschau-Link. Der zeigt nicht ins
     *                    Medienverzeichnis, sondern auf backend/file.php - nur das
     *                    kennt die Ablage und prüft die Berechtigung
     *                    (RecordBackendServerFile::filePreview()). Der angehängte
     *                    Zufallswert verhindert, dass der Browser eine alte Fassung
     *                    aus dem Zwischenspeicher zeigt.
     *
     * FEST VERKNÜPFTE INHALTE (Felder vom Typ 'link', Camindo\Core\LinkTrait)
     * --------------------
     *     display_type 'lookup'  wie ein Pointer-Lookup: Suchfenster, Verknüpfung
     *                            sofort an der Arbeitskopie
     *     display_type 'embed'   die Eingabemasken der Inhalte stehen in dieser Maske
     *                            (_embedBlock()): Feldlabel als Zwischenüberschrift,
     *                            darunter je Inhalt eine Zelle mit dessen Maske. Die
     *                            Inhalte sind beim Check-out mit ausgecheckt worden
     *                            (LinkTrait::checkOutDependents()), ihre Eingabefelder
     *                            heißen embed[{record_id}][feld]; Einfügen, Verschieben
     *                            und Löschen laufen per Ajax (embedAction()), gespeichert
     *                            wird mit dem Quelldatensatz (_editSave()).
     *
     * data-changed="true" wird gesetzt, sobald das Formular nicht der erste Aufruf ist -
     * das Javascript fragt dann beim Schließen nach, statt Eingaben zu verwerfen.
     *
     * @param array $schemaVirtual  Schema mit virtuellen Felder
     */
    private function _editForm(array $schemaVirtual): void
    {
        $requestFunction = (string)($_REQUEST['function'] ?? '');
        if ($requestFunction != '') $changed = ' data-changed="true"';
        else $changed = '';
        echo '<form action="Record/record_edit.php" method="post"' . $changed . '>';

        // Sprachen der Instanz des Bereichs (siehe Klassenkommentar)
        // --------------------
        $ctx = $this->_editLanguageContext();
        $languages = $ctx['languages'];
        $useTabs = $ctx['useTabs'];
        $tab = $ctx['tab'];
        if ($useTabs) {
            $this->backend->tabBarReset();
            foreach ($languages as $languageId => $language) {
                // Reiter je Sprache
                $label = $language['code'] ?? '**';
                $link = "cApp.ui.recordTab(this, '{$languageId}');";
                $this->backend->tabAdd($label, $link, (string)$tab === (string)$languageId);
            }
            // Reiter "Alle"
            $labelAll = '#record.tab_all_languages';
            $linkAll = "cApp.ui.recordTab(this, 'all');";
            $this->backend->tabAdd($labelAll, $linkAll, $tab === 'all');
            
            echo $this->backend->tabBar();

            // Aktuell ausgewählte Sprache anzeigen
            if (isset($languages[$tab])) {
                echo $this->backend->template('edit_form_block', [
                    '{label}' => '',
                    '{field}' => $this->backend->template('language.icon_labeled', [
                        '{label}' => $this->cms->textOrSnippet($languages[$tab]['label'])
                    ])
                ]);
            }
        }

        $this->backend->formInit('record.record_edit_form');

        // Die Eingabefelder (siehe _editFormFields())
        $shown = $this->_editFormFields($schemaVirtual, $ctx);

        // Owner (Zusatzrecht "Owner anpassen"): als letztes Feld ein Lookup auf die
        // Admin-Liste - der Wert reist als verstecktes data[owner_id] im Vorschaublock
        // (12-group-rights.md); geschrieben wird er nur mit dem Recht (_editSave()
        // nimmt ihn in $fields, Record::hookOnSave() prüft noch einmal)
        if ($this->backend->access()->areaSetOwner($this->areaId)) {
            $ownerId = (int)($this->data['owner_id'] ?? 0);
            if ($ownerId < 1) $ownerId = (int)$this->session->get('admin_id');
            $this->backend->formField($this->record, [
                'name' => 'owner_id',
                'colDef' => $this->cms->schema['owner_id'],
                'value' => $ownerId,
                'id' => $this->id,
                'append' => true,
                'template' => 'edit_form_lookup_foreign_table',
                'contextMenu' => '',
            ]);
            $this->backend->formReplace('{src_id}', (string)$this->id);
            $this->backend->formReplace('{src_field}', 'owner_id');
            $this->backend->formReplace('{search_url}', 'Admin/admin_list.php?src_field=owner_id');
            $this->backend->formReplace('{update_url}', 'Admin/ajax_lookup_preview.php?src_field=owner_id');
            $this->backend->formReplace('{data_ids}', $ownerId > 0 ? (string)$ownerId : '');
            $this->backend->formReplace('{max}', '1');
            $this->backend->formReplace(
                '{preview}',
                $GLOBALS['app']->get(\Camindo\Core\Modules\Admin\AdminBackend::class)->lookupPreviewCreate('owner_id', [$ownerId], 'data', 1)
            );
        }

        // hidden fields
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('data[area_id]', $this->areaId);
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('shown', implode(',', $shown));
        // Reiter: der angezeigte (tab) und das Ziel eines Reiterwechsels
        // (tab_target, setzt cApp.ui.recordTab() vor dem Absenden)
        if ($useTabs) {
            $this->backend->formHidden('tab', (string)$tab);
            $this->backend->formHidden('tab_target', '');
        }
        // form called via lookup field?
        $this->backend->formHidden('src_field', $this->srcField);
        $this->backend->formHidden('src_id', $this->srcId);
        $this->backend->formHidden('straight', $this->straight);

        // Formular rendern
        echo $this->backend->form('record.form_save_cancel', [
            '{id}' => $this->id
        ]);
        
        // JavaScript Anweisung für growing textarea, nach Ajax-Load
        $this->backend->script("cApp.ui._initTextareaGrow(\$('.c9-layer:last'));");
        echo '</form>';
    }

    /**
     * Sprachkontext der Maske: Sprachen der Instanz des Bereichs, Reiter-Modus und
     * aktiver Reiter, dazu die Felder mit Fehlern in anderen Sprachen - gemeinsame
     * Grundlage von _editForm() und den eingebetteten Masken (embedAction()).
     *
     * Fehler aus anderen Reitern: Dataset::save() registriert Feldfehler
     * mehrsprachiger Felder unter "name.{language_id}" (eingebettet
     * "embed.{record_id}.name.{language_id}"). Liegt der Fehler in einer Sprache, die
     * der aktive Reiter nicht zeigt, bekäme der Redakteur ihn nie zu sehen - und
     * könnte den Reiter wegen des Fehlers auch nicht wechseln. Deshalb werden solche
     * Felder zusätzlich in ihrer Fehlersprache angezeigt (errorLanguages:
     * Fehlerschlüssel ohne Sprache => [language_id, ...], siehe _editFormFields()).
     *
     * @return array  languages, isMultilingual, languageIds, primaryId, useTabs, tab,
     *                errorLanguages
     */
    private function _editLanguageContext(): array
    {
        $allLanguages = $this->_recordLanguages();
        // Mehrsprachig ist die Instanz - unabhängig davon, wie viele Sprachen die
        // Benutzergruppe sieht: Reiter und Felder gibt es nur für lesbare Sprachen,
        // Eingabefelder nur für schreibbare (sonst grauer Text, siehe
        // _editFormFields()); Sprachen ohne Recht bleiben beim Speichern unangetastet
        $isMultilingual = count($allLanguages) > 1;
        $languages = $this->record->readableLanguages($allLanguages);
        $languageIds = array_map('intval', array_keys($languages));
        $primaryId = $languageIds[0] ?? 0;
        $access = $this->backend->access();
        $writableIds = array_values(array_filter($languageIds, fn($id) => $access->languageWrite($id)));
        $useTabs = $isMultilingual && $this->_languageTabs();
        // Aktiver Reiter: language_id oder 'all'
        $tab = $useTabs ? $this->_editCurrentTab($languageIds) : 'all';
        $errorLanguages = [];
        if ($useTabs && $tab !== 'all') {
            foreach ($this->cms->getErrorFields() as $errorKey) {
                if (!preg_match('/^(.+)\.(\d+)$/', (string)$errorKey, $m)) continue;
                if (!in_array((int)$m[2], $languageIds, true)) continue;
                $errorLanguages[$m[1]][] = (int)$m[2];
            }
        }
        return [
            'languages' => $languages,
            'isMultilingual' => $isMultilingual,
            'languageIds' => $languageIds,
            'primaryId' => $primaryId,
            'writableIds' => $writableIds,
            'useTabs' => $useTabs,
            'tab' => $tab,
            'errorLanguages' => $errorLanguages,
        ];
    }

    /**
     * Die Eingabefelder EINES Datensatzes in das Formular setzen - für den
     * Datensatz der Maske ($varName 'data') und für jeden eingebetteten Inhalt eines
     * 'link'-Feldes ($varName 'embed[{record_id}]', _embedCell()). Arbeitet auf
     * $this->id / $this->data / $this->areaId / $this->areaData - die eingebettete
     * Maske tauscht diesen Zusammenhang vorher aus.
     *
     * Eingebettet entfallen die Felder `parent_id` und `position` (die Reihenfolge
     * der Inhalte bestimmt die Verknüpfung) sowie 'link'-Felder der Inhalte selbst
     * (keine Verschachtelung in der Maske - ihre Inhalte werden beim Check-in
     * trotzdem mitgenommen, LinkTrait).
     *
     * @param array  $schemaVirtual  Schema des Bereichs
     * @param array  $ctx            _editLanguageContext()
     * @param string $varName        Name des Request-Arrays ('data' | 'embed[{id}]')
     * @param string $errorPrefix    Präfix der Fehlerschlüssel ('' | 'embed.{id}.')
     * @return string[]  gerenderte Eingabefelder: "name" bzw. "name.{language_id}"
     *                   (verstecktes Feld 'shown', siehe _editNormalizeSubmitted())
     */
    private function _editFormFields(array $schemaVirtual, array $ctx, string $varName = 'data', string $errorPrefix = ''): array
    {
        $languages = $ctx['languages'];
        $isMultilingual = $ctx['isMultilingual'];
        $languageIds = $ctx['languageIds'];
        $primaryId = $ctx['primaryId'];
        $tab = $ctx['tab'];
        $errorLanguages = $ctx['errorLanguages'];
        $embedded = ($varName !== 'data');

        if ($isMultilingual) {
            // Werte je Sprache zusammenstellen (Arbeitsstand + Request)
            $this->_editLanguageValues($schemaVirtual, $languageIds);
        }

        // Passwörter, die die Arbeitskopie bereits als Hash trägt (Record::
        // securePasswords(): gesichert vor einer gescheiterten Prüfung oder einem
        // Reiterwechsel) - das Feld darf dann leer bleiben und sagt das auch
        $keptPasswords = [];
        if ($this->id > 0) {
            foreach ($this->record->loadTmpData($this->id) as $name => $entry) {
                if (($entry[0] ?? '') === 'password' && (string)($entry[1] ?? '') !== '') $keptPasswords[$name] = true;
            }
        }

        // Bezugsgrößen für das Paar parent_id / position (siehe Klassenkommentar):
        // Ohne Eingabefeld für parent_id gibt es keine Hierarchie zum Auswählen -
        // die Vorgängerliste läuft dann über die Wurzelebene des Bereichs.
        $parentId = isset($schemaVirtual['parent_id'])
            ? (int)($this->data['parent_id'] ?? 0)
            : 0;
        // Gibt es überhaupt eine Vorgängerliste, die neu geladen werden müsste?
        $hasPreviousField = isset($schemaVirtual['position'])
            && (int)($schemaVirtual['position']['visibility_input'] ?? 0) === 1
            && ($schemaVirtual['position']['display_type'] ?? '') !== 'lookup';

        // Welche Eingabefelder die Maske enthält - "name" bzw. "name.{language_id}".
        // Geht als verstecktes Feld 'shown' mit, damit der Server ein fehlendes
        // Feld als GELEERT erkennt (siehe _editNormalizeSubmitted()).
        $shown = [];

        foreach ($schemaVirtual as $name => $colDef) {
            // Nur sichtbare Felder
            $visibility = (int)($colDef['visibility_input'] ?? 0);
            if ($visibility !== 1) continue;

            $type = $colDef['type'] ?? 'text';
            $displayType = $colDef['display_type'] ?? 'text';
            $fieldId = (int) $colDef['field_id'];
            $isLink = in_array($type, $this->cms->dependentTypes, true);

            if ($embedded && ($isLink || in_array($name, ['parent_id', 'position'], true))) continue;

            // Verknüpfungsfeld ohne lesbaren Zielbereich: nicht in der Maske, nicht
            // in 'shown' - die Verknüpfungen bleiben beim Speichern, wie sie sind
            if (!$this->record->relationTargetReadable($colDef)) continue;

            // Fest verknüpfte Inhalte, eingebettet: eigener Block statt Eingabefeld
            if ($isLink && $displayType === 'embed') {
                $this->backend->formAppend(
                    '{' . $varName . '_' . $name . '}',
                    $this->_embedBlock($name, $colDef, $ctx)
                );
                continue;
            }

            $isSelectType = (
                in_array($displayType, $this->backend->optionDisplayTypes)
                && !in_array($type, $this->cms->relationTypes)
            );
            // relation / pointer
            // display_type != lookup -> Options aus Datensätzen in den Zielbereichen zusammenstellen
            $options = null;
            if (in_array($type, $this->cms->relationTypes, true) && $displayType != 'lookup') {                
                if (str_starts_with($type, 'relation')) {
                    $relAreaIds = [(int)$colDef['rel_area_id']];
                }
                else {
                    // pointer, pointer1
                    $relAreaIds = $this->record->getRelAreaIds($fieldId);
                }
                if ($displayType === 'select') {
                    $options = $this->_relatedDatasetOptions($relAreaIds, addEmptyOption:true);
                }
                else {
                    // multiselect
                    $options = $this->_relatedDatasetOptions($relAreaIds);
                }
            }
            elseif ($isSelectType) {
                $values = preg_split('/(\r\n|\r|\n)/', (string)($colDef['default'] ?? ''));
                $options = array_combine($values, $values);
            }

            // template_id
            if ($name === 'template_id' && $displayType != 'lookup') {
                $options = $this->_templateOptions();
            }

            // status_id
            elseif ($name === 'status_id' && $displayType != 'lookup') {
                $options = $this->_statusOptions(settableOnly: true);
            }

            // parent_id
            elseif ($name === 'parent_id' && $displayType != 'lookup') {
                $options = $this->_parentOptions();
            }

            // position
            // Eingegeben wird nicht die Zahl, sondern der VORGÄNGER-Datensatz
            // (siehe _editPreviousParams()) - die Optionen sind daher record_ids.
            elseif ($name == 'position' && $displayType != 'lookup') {
                $options = $this->_previousOptions($parentId);
            }

            if (isset($keptPasswords[$name])) {
                // gesichertes Passwort: kein Pflichtfeld mehr, Hinweis darunter
                $colDef['flags'] = array_values(array_diff((array)($colDef['flags'] ?? []), ['not_empty']));
                $colDef['note'] = '#record.note_password_kept';
            }
            $fieldParams = [
                'name' => $name,
                'colDef' => $colDef,
                'value' => $this->data[$name] ?? '',
                'id' => $this->id,
                'append' => true,
                'options' => $options,
                'varName' => $varName,
                'errorPrefix' => $errorPrefix,
                'placeholder' => '{' . $varName . '_' . $name . '}',
                'primary' => $fieldId > 0 && $fieldId === (int)($this->areaData['default_field_id'] ?? 0)
            ];

            // parent_id und position hängen zusammen: ein Wechsel des Vaterdatensatzes
            // macht die Vorgängerliste hinfällig. Beide Zweige unten passen die
            // Formularparameter dafür an.
            if ($name === 'parent_id') {
                $fieldParams = $this->_editParentParams($fieldParams, $displayType, $hasPreviousField);
            }
            elseif ($name === 'position' && $displayType != 'lookup') {
                $fieldParams = $this->_editPreviousParams($fieldParams, $parentId, $options);
            }

            // Mehrsprachiges Feld: ein Eingabefeld je Sprache - auf einem Sprach-Reiter
            // nur das der Reitersprache. Jedes bekommt seinen eigenen Platzhalter,
            // damit die Felder in der Reihenfolge der Sprachen direkt untereinander
            // stehen (append hängt einen fehlenden Platzhalter ans Ende an).
            if ($isMultilingual && $this->cms->isMultilingualField($colDef)) {
                $values = (array)($this->data[$name] ?? []);
                $formLanguages = ($tab === 'all') ? $languageIds : [(int)$tab];
                if (!empty($errorLanguages[$errorPrefix . $name])) {
                    // Sprachen mit Fehlern dazu, in der Reihenfolge der Instanz
                    $formLanguages = array_values(array_intersect(
                        $languageIds, array_unique(array_merge($formLanguages, $errorLanguages[$errorPrefix . $name]))
                    ));
                }
                foreach ($formLanguages as $languageId) {
                    if (!in_array($languageId, $ctx['writableIds'], true)) {
                        // nur lesbar: gespeicherter Wert als grauer Text, kein Eingabefeld
                        // (nicht in 'shown' - der Wert bleibt beim Speichern unangetastet)
                        $this->backend->formAppend(
                            '{' . $varName . '_' . $name . '__' . $languageId . '}',
                            $this->_editReadOnlyField($colDef, $values[$languageId] ?? '', (string)($languages[$languageId]['code'] ?? $languageId))
                        );
                        continue;
                    }
                    $shown[] = $name . '.' . $languageId;
                    $langParams = $fieldParams;
                    $langParams['value'] = $values[$languageId] ?? '';
                    $langParams['langId'] = $languageId;
                    $langParams['langSuffix'] = (string)($languages[$languageId]['code'] ?? $languageId);
                    $langParams['placeholder'] = '{' . $varName . '_' . $name . '__' . $languageId . '}';
                    if ($languageId !== $primaryId) {
                        // Pflicht gilt nur für die Hauptsprache: eine weitere Sprache
                        // darf leer bleiben (Fallback) - kein Stern, kein required
                        $langParams['colDef']['flags'] = array_values(array_diff(
                            (array)($colDef['flags'] ?? []), ['not_empty']
                        ));
                    }
                    $this->backend->formField($this->record, $langParams);
                    if ($type === 'file') {
                        // Upload-Feld: Vorschau der Datei DIESER Sprache
                        $this->_editFileReplacements($name, $fieldId, (array)($values[$languageId] ?? []), $languageId);
                    }
                }
                continue;
            }

            $shown[] = $name;
            $this->backend->formField($this->record, $fieldParams);

            if ($name === 'parent_id' && $displayType === 'lookup') {
                // Lookup-Feld für den Vaterdatensatz - Tabelle record -
                // nachträgliche Ersetzungen. Anders als bei einem Verknüpfungsfeld
                // wird hier NICHTS sofort geschrieben: die Vorschau enthält ein
                // verstecktes Feld data[parent_id], das mit dem Formular abgeht.
                $urlSuffix = "&src_id={$this->id}&src_field={$fieldId}";
                $this->backend->formReplace('{src_id}', $this->id);
                $this->backend->formReplace('{src_field}', $fieldId);
                $this->backend->formReplace('{search_url}', "Record/record_list.php?" . $urlSuffix);
                $this->backend->formReplace('{update_url}', "Record/ajax_parent_lookup.php?" . $urlSuffix);
                $this->backend->formReplace('{preview}', $this->_parentLookupPreviewCreate($parentId));
            }

            if (in_array($type, $this->cms->relationTypes, true)
                && $displayType === 'lookup'
            ) {
                // Lookup-Feld - Tabelle record - nachträgliche Ersetzungen
                $this->_editLookupReplacements($fieldId, (int)($colDef['max'] ?? 0));
            }

            if ($type === 'file') {
                // Upload-Feld - Tabelle record - nachträgliche Ersetzungen:
                $this->_editFileReplacements($name, $fieldId, (array)($this->data[$name] ?? []), null);
            }
        }

        return $shown;
    }

    /**
     * Ein Feld einer nur lesbaren Sprache: Beschriftung mit Sprachkürzel und der
     * gespeicherte Wert als grauer Text (class c9-grey-30) statt eines
     * Eingabefeldes (12-group-rights.md).
     *
     * @param array  $colDef  Felddefinition
     * @param mixed  $value   Wert im Anzeigeformat (_editLanguageValues())
     * @param string $code    Sprachkürzel
     */
    private function _editReadOnlyField(array $colDef, mixed $value, string $code): string
    {
        $label = $this->cms->textOrSnippet((string)($colDef['label'] ?? ''))
            . $this->backend->template('label_lang_suffix', ['{lang}' => htmlspecialchars($code)]);
        if (is_array($value)) {
            // Datei / Verknüpfung: Anzeigeform aus backendValue()
            $html = $this->backend->backendValue($value, $colDef, 'short', $this->id);
        }
        else {
            $html = nl2br(htmlspecialchars((string)$value));
            // in der Schreibrichtung seiner Sprache (Backend::fieldDirection())
            $dir = $this->backend->fieldDirection($colDef, $code);
            if ($dir !== '' && trim($html) !== '') $html = '<span dir="' . $dir . '">' . $html . '</span>';
        }
        if (trim($html) === '') $html = $this->backend->emptyPlaceholder;
        return $this->backend->template('edit_form_static', [
            '{label}' => $label,
            '{html}' => '<span class="c9-grey-30">' . $html . '</span>',
        ]);
    }

    // ====================
    // Fest verknüpfte Inhalte, eingebettet (Feldtyp 'link', display_type 'embed')

    /**
     * Den Block eines eingebetteten 'link'-Feldes rendern: Feldlabel als
     * Zwischenüberschrift, darunter auf voller Breite je Inhalt eine Zelle mit
     * dessen Eingabemaske (_embedCell()), dazu die Schaltflächen zum Anfügen (eine je
     * Zielbereich). Der Block trägt die Angaben, die das Javascript für Einfügen,
     * Verschieben und Löschen braucht (backend-embedded.js -> embedAction()).
     *
     * @param string $name    Feldname
     * @param array  $colDef  Felddefinition
     * @param array  $ctx     _editLanguageContext()
     */
    private function _embedBlock(string $name, array $colDef, array $ctx): string
    {
        $fieldId = (int)$colDef['field_id'];
        $areas = $this->_embedAreas($fieldId);

        $cells = [];
        foreach ($this->record->linkDestIds($this->id, $fieldId) as $destId) {
            $cells[] = $this->_embedCell($destId, $colDef, $ctx, $areas);
        }

        $label = $this->cms->textOrSnippet($colDef['label'] ?? $name);
        if (in_array('not_empty', (array)($colDef['flags'] ?? []), true)) {
            $label .= $this->backend->template('mandatory_field_indicator');
        }
        $tooltip = (string)($colDef['tooltip'] ?? '');
        $tooltipHtml = $tooltip !== ''
            ? $this->backend->template('record.embed_tooltip', [
                '{tooltip}' => $this->backend->_escapeParam($this->cms->textOrSnippet($tooltip))
            ])
            : '';

        // Fehler am Feld selbst (z. B. not_empty: kein Inhalt)
        $errorMsg = '';
        $blockClass = '';
        if (in_array($name, $this->cms->getErrorFields(), true)) {
            $blockClass = ' c9-invalid';
            $errorMsg = $this->backend->template('form_error', [
                '{text}' => $this->cms->errors[$name]['text'] ?? ''
            ]);
        }

        return $this->backend->template('record.embed_block', [
            '{label}' => $label,
            '{tooltip}' => $tooltipHtml,
            '{src_id}' => $this->id,
            '{src_field}' => $fieldId,
            '{tab}' => (string)$ctx['tab'],
            '{update_url}' => "Record/ajax_embed.php?src_id={$this->id}&src_field={$fieldId}",
            '{cells}' => implode("\n", $cells),
            '{add_buttons}' => $this->_embedAddButtons($areas),
            '{class}' => $blockClass,
            '{error}' => $errorMsg,
        ]);
    }

    /**
     * Zielbereiche eines 'link'-Feldes: area_id => Beschriftung (für die
     * Einfügen-Einträge des Zellenmenüs und die Anfügen-Schaltflächen).
     */
    private function _embedAreas(int $fieldId): array
    {
        $areas = [];
        foreach ($this->record->getRelAreaIds($fieldId) as $areaId) {
            if (!$areaData = $this->backend->areaData((int)$areaId)) continue;
            $areas[(int)$areaId] = $this->cms->textOrSnippet((string)$areaData['label']);
        }
        return $areas;
    }

    /**
     * Anfügen-Schaltflächen unter den Zellen: bei einem Zielbereich eine, sonst eine
     * je Zielbereich mit dessen Beschriftung.
     */
    private function _embedAddButtons(array $areas): string
    {
        $buttons = [];
        foreach ($areas as $areaId => $label) {
            $buttons[] = $this->backend->template('record.embed_add_button', [
                '{area_id}' => $areaId,
                '{label}' => count($areas) > 1
                    ? strtr($this->cms->snippet('record.embed_add_area'), ['{area}' => $label])
                    : $this->cms->snippet('record.embed_add'),
            ]);
        }
        return implode("\n", $buttons);
    }

    /**
     * Die Zelle eines eingebetteten Inhalts: seine Eingabemaske (Feldlabel + Feld
     * nebeneinander, wie in der Hauptmaske) mit dem Zellenmenü (more_vert:
     * Einfügen vor / nach, nach oben / unten, Löschen).
     *
     * Der Inhalt ist eine Arbeitskopie des Session-Admins (LinkTrait). Seine Werte:
     *
     *   1. aus dem Request (function save / tab / errors, embedAction()):
     *      embed[{record_id}][feld] - rohe Eingabe im Anzeigeformat
     *   2. sonst aus der Arbeitskopie (Record::load(): Arbeitsstand über
     *      Cms::fetch()) im Datenbankformat -> _editFormatLoadedValues(); ein neu
     *      eingefügter Inhalt ohne Arbeitsstand bekommt die Vorgaben seines Bereichs
     *      (Record::init())
     *
     * Die Feldschleife (_editFormFields()) arbeitet auf $this->id / $this->data /
     * $this->areaId / $this->areaData / $this->function - der Zusammenhang wird für
     * die Dauer der Zelle ausgetauscht. Feldfehler stehen unter
     * "embed.{record_id}.feld" (_embedSave()).
     *
     * @param int   $destId  Arbeitskopie des Inhalts
     * @param array $colDef  das 'link'-Feld
     * @param array $ctx     _editLanguageContext()
     * @param array $areas   _embedAreas()
     */
    private function _embedCell(int $destId, array $colDef, array $ctx, array $areas): string
    {
        $keep = [$this->id, $this->data, $this->areaId, $this->areaData, $this->function];
        $this->backend->formPush();
        try {
            if (!$prodData = $this->record->getProdData($destId)) {
                return $this->backend->template('alert_danger', [
                    '{text}' => $this->cms->snippet('error_tmp_record_access_denied')
                ]);
            }
            $areaId = (int)$prodData[1];
            if (!$areaData = $this->backend->areaData($areaId)) {
                return $this->backend->template('alert_danger', [
                    '{text}' => $this->cms->snippet('area.error_area_not_found')
                ]);
            }
            $schemaVirtual = $this->record->virtualSchema($areaId, addMetaFields:false);
            $this->id = $destId;
            $this->areaId = $areaId;
            $this->areaData = $areaData;

            $fromRequest = isset($this->embedData[$destId]) && is_array($this->embedData[$destId]);
            if ($fromRequest) {
                $this->function = 'errors';
                $this->data = $this->embedData[$destId];
                $this->data['record_id'] = $destId;
                $this->data['area_id'] = $areaId;
                $this->_editRestoreSecuredFiles($schemaVirtual);
            }
            elseif (empty($this->record->loadTmpData($destId))) {
                // neu eingefügt, noch ohne Arbeitsstand: Vorgaben des Bereichs
                $this->function = 'create';
                $this->data = (array)$this->record->init(['area_id' => $areaId]);
                $this->data['record_id'] = $destId;
            }
            else {
                $this->function = 'edit';
                $this->data = (array)$this->record->load($destId);
                $this->data['record_id'] = $destId;
                $this->_editFormatLoadedValues($schemaVirtual);
            }

            $varName = 'embed[' . $destId . ']';
            $shown = $this->_editFormFields($schemaVirtual, $ctx, $varName, 'embed.' . $destId . '.');
            $this->backend->formHidden('embed_shown[' . $destId . ']', implode(',', $shown));
            $fields = $this->backend->formPop();
            $this->backend->formPush();

            // Zellenmenü
            $insertItems = '';
            foreach ($areas as $insertAreaId => $label) {
                $insertItems .= $this->backend->template('record.embed_menu_insert', [
                    '{area_id}' => $insertAreaId,
                    '{suffix}' => count($areas) > 1 ? ': ' . $label : '',
                ]);
            }
            $areaLabel = $this->cms->textOrSnippet((string)$areaData['label']);
            return $this->backend->template('record.embed_cell', [
                '{dest_id}' => $destId,
                '{area_id}' => $areaId,
                '{area_label}' => count($areas) > 1 ? $areaLabel : '',
                '{fields}' => $fields,
                '{menu}' => $this->backend->template('record.embed_menu', [
                    '{insert_items}' => $insertItems,
                ]),
            ]);
        } finally {
            $this->backend->formPop();
            [$this->id, $this->data, $this->areaId, $this->areaData, $this->function] = $keep;
        }
    }

    /**
     * Die eingebetteten Inhalte aller 'link'-Felder der Maske speichern - vor dem
     * Quelldatensatz (_editSave(), _editTab()).
     *
     * Je Inhalt Record::save() mit den Eingaben aus embed[{record_id}] - die
     * Feldliste sind seine sichtbaren Eingabefelder (wie _editTab()), geleerte
     * Felder aus embed_shown nachgetragen (_editNormalizeSubmitted()). Uploads
     * liegen unter $_FILES['embed'][...][{record_id}] und werden für die Dauer des
     * Aufrufs nach $_FILES['data'] gelegt, wo Dataset::save() sie erwartet.
     *
     * Immer mit stayTemporary: die geprüften Werte landen im Arbeitsstand der
     * Arbeitskopie des Inhalts, von wo der Check-in sie nimmt
     * (LinkTrait::checkInDependents() -> Record::save() mit gestagten Feldern).
     * So ist die Prüfung durch, bevor eine Datei den Ort wechselt.
     *
     * Feldfehler registriert Dataset::save() unter dem Feldnamen; sie werden auf
     * "embed.{record_id}.feld" umgeschlüsselt, damit die Maske sie an der richtigen
     * Zelle zeigt (Backend::formField() mit errorPrefix). Ohne Eingaben zu einem
     * Inhalt (Zelle nicht im Request) bleibt sein Arbeitsstand, wie er ist.
     *
     * @param array $schemaVirtual  Schema des Bereichs der Maske
     * @return bool  false, wenn ein Inhalt nicht gespeichert werden konnte
     */
    private function _embedSave(array $schemaVirtual): bool
    {
        $ok = true;
        foreach ($this->record->linkFields($schemaVirtual) as $colDef) {
            if ((string)($colDef['display_type'] ?? '') !== 'embed') continue;
            foreach ($this->record->linkDestIds($this->id, (int)$colDef['field_id']) as $destId) {
                if (!isset($this->embedData[$destId]) || !is_array($this->embedData[$destId])) continue;
                if (!$prodData = $this->record->getProdData($destId)) {
                    $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
                    $ok = false;
                    continue;
                }
                $areaId = (int)$prodData[1];
                $destSchema = $this->record->virtualSchema($areaId, addMetaFields:false);

                $data = $this->embedData[$destId];
                $data['record_id'] = $destId;
                $data['area_id'] = $areaId;
                $this->_editNormalizeSubmitted($destSchema, $data, (string)($_REQUEST['embed_shown'][$destId] ?? ''));

                $fields = [];
                foreach ($destSchema as $name => $destDef) {
                    if ((int)($destDef['visibility_input'] ?? 0) !== 1) continue;
                    $type = (string)($destDef['type'] ?? '');
                    if ($type === 'fulltext' || in_array($type, $this->cms->dependentTypes, true)) continue;
                    if (in_array($name, ['parent_id', 'position'], true)) continue;
                    $fields[] = $name;
                }
                $fields[] = 'area_id';

                // Uploads dieses Inhalts an die Stelle legen, wo save() sie erwartet
                $filesKeep = $_FILES['data'] ?? null;
                $_FILES['data'] = [];
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                    if (isset($_FILES['embed'][$key][$destId])) {
                        $_FILES['data'][$key] = $_FILES['embed'][$key][$destId];
                    }
                }
                $errorsBefore = array_keys($this->cms->errors);
                try {
                    $saved = $this->record->save($fields, $data, stayTemporary: true);
                } finally {
                    if ($filesKeep === null) unset($_FILES['data']);
                    else $_FILES['data'] = $filesKeep;
                }
                if (!$saved) {
                    $ok = false;
                    // Feldfehler auf die Zelle umschlüsseln
                    foreach (array_keys($this->cms->errors) as $key) {
                        if (is_int($key) || in_array($key, $errorsBefore, true)) continue;
                        $this->cms->errors['embed.' . $destId . '.' . $key] = $this->cms->errors[$key];
                        unset($this->cms->errors[$key]);
                    }
                }
            }
        }
        return $ok;
    }

    /**
     * Ajax: eingebettete Inhalte eines 'link'-Feldes bearbeiten
     * (backend/ajax_embed.php, aufgerufen von backend-embedded.js).
     *
     *     insert   neuen Inhalt vor/nach dest_id einfügen (dest_id 0: ans Ende) -
     *              Antwort ist die fertige Zelle (_embedCell()), das Javascript setzt
     *              sie an ihre Stelle
     *     up/down  Inhalt eine Stelle verschieben - das Javascript tauscht die Zellen
     *     delete   Inhalt entfernen (LinkTrait::embedRemove(): Kante lösen,
     *              Arbeitskopie verwerfen; ein produktives Original wird beim
     *              Check-in als verwaist gelöscht)
     *
     * Bei up/down/delete bleibt die Antwort leer; ein Fehler kommt als Meldung. Die
     * Arbeitskopie des Quelldatensatzes (src_id) muss dem Session-Admin gehören -
     * das prüfen die LinkTrait-Methoden.
     *
     * Request: src_id, src_field, function, dest_id, area_id (insert), before (insert),
     *          tab (aktiver Sprach-Reiter der Maske)
     */
    public function embedAction(): void
    {
        // Legt an, sortiert um, hängt aus - nur per POST (17-csrf.md §2.7)
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }
        $srcId    = (int)($_REQUEST['src_id'] ?? 0);
        $srcField = (int)($_REQUEST['src_field'] ?? 0);
        $destId   = (int)($_REQUEST['dest_id'] ?? 0);
        $areaId   = (int)($_REQUEST['area_id'] ?? 0);
        $before   = (int)($_REQUEST['before'] ?? 0) === 1;
        $function = (string)($_REQUEST['function'] ?? '');

        if (!$colDef = $this->record->fieldData($srcField)) {
            $this->cms->error('embedAction() Exception: field not found.');
            $this->errors();
            return;
        }
        if (!in_array((string)($colDef['type'] ?? ''), $this->cms->dependentTypes, true)) {
            $this->cms->error('embedAction() Exception: not a link field.');
            $this->errors();
            return;
        }
        if (!$prodData = $this->record->getProdData($srcId)) {
            $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
            $this->errors();
            return;
        }
        // Zusammenhang der Maske (Sprachen, Reiter)
        $this->id = $srcId;
        $this->areaId = (int)$prodData[1];
        $this->areaData = $this->backend->areaData($this->areaId) ?? [];
        $this->embedData = [];

        try {
            switch ($function) {
                case 'insert':
                    $newId = $this->record->embedInsert($colDef, $srcId, $areaId, $destId, $before);
                    if (!$newId) {
                        $this->errors();
                        return;
                    }
                    $ctx = $this->_editLanguageContext();
                    echo $this->_embedCell($newId, $colDef, $ctx, $this->_embedAreas($srcField));
                    return;
                case 'up':
                case 'down':
                    $this->record->embedMove($colDef, $srcId, $destId, $function);
                    break;
                case 'delete':
                    $this->record->embedRemove($colDef, $srcId, $destId);
                    break;
                default:
                    $this->cms->error('embedAction() Exception: unknown function.');
            }
        } catch (\Exception $e) {
            $this->cms->error($e->getMessage());
        }
        if ($this->cms->hasErrors) $this->errors();
    }

    /**
     * Lookup-Feld (Verknüpfung mit Suchfenster): Platzhalter des zuletzt angefügten
     * Eingabefeldes ersetzen - die URLs für Suchen, Anlegen und Aktualisieren
     * (src_id ist die Arbeitskopie $this->id, an der die Verknüpfungen hängen) und
     * der Vorschaublock der bestehenden Verknüpfungen (_lookupPreviewCreate()).
     *
     * Muss direkt nach dem formField()-Aufruf des Feldes laufen (siehe
     * _editFileReplacements()). Auch für die Vorlage der Stapelbearbeitung
     * (RecordBackendBatch).
     *
     * @param int $fieldId  field_id des Verknüpfungsfeldes
     * @param int $max      höchstens so viele Verknüpfungen (0 = beliebig)
     */
    protected function _editLookupReplacements(int $fieldId, int $max): void
    {
        $urlSuffix = "&src_id={$this->id}&src_field={$fieldId}";
        $this->backend->formReplace('{src_id}', $this->id);
        $this->backend->formReplace('{src_field}', $fieldId);
        $this->backend->formReplace('{search_url}', "Record/record_list.php?" . $urlSuffix);
        $this->backend->formReplace('{create_url}', "Record/record_edit.php?function=create&straight=1" . $urlSuffix);
        $this->backend->formReplace('{update_url}', "Record/ajax_lookup_preview.php?" . $urlSuffix);
        $this->backend->formReplace('{max}', $max);
        $this->backend->formReplace('{preview}', $this->_lookupPreviewCreate($fieldId, $this->id) ?? '');
    }

    /**
     * Upload-Feld: Vorschau-Block und Platzhalter des zuletzt angefügten
     * Eingabefeldes ersetzen ({src_id}, {src_field}, {lang}, {preview}).
     *
     * Der Vorschau-Link zeigt nicht ins Medienverzeichnis, sondern auf
     * backend/file.php - nur das kennt die Ablage und prüft die Berechtigung
     * (RecordBackendServerFile::filePreview()); bei mehrsprachigen Feldern mit der
     * Sprache als drittem Pfadteil. Der angehängte Zufallswert verhindert, dass der
     * Browser eine alte Fassung aus dem Zwischenspeicher zeigt.
     *
     * Muss direkt nach dem formField()-Aufruf des Feldes laufen: formReplace()
     * ersetzt alle noch offenen Platzhalter im Formular, das nächste Upload-Feld
     * bringt seine eigenen mit.
     *
     * @param string   $name        Feldname
     * @param int      $fieldId     field_id (Medienverzeichnis)
     * @param array    $fileData    Metadaten der Datei (leer = keine Datei)
     * @param int|null $languageId  Sprache bei mehrsprachigen Feldern
     */
    protected function _editFileReplacements(string $name, int $fieldId, array $fileData, ?int $languageId): void
    {
        $previewContainer = '';
        if (!empty($fileData['size']) && empty($fileData['deleted'])) {
            $unlinkUrl = "cApp.ui.fileUnlink();";
            $fileAttribs = $this->system->convertSize($fileData['size']);
            $linkText = $fileData['filename'] . ' ' . str_replace('{text}', $fileAttribs, $this->cms->snippet('parenthetical_note'));
            $previewUrl = $this->config->get('paths.backend') . "/file.php?file=$fieldId/{$this->id}"
                . ($languageId === null ? '' : '/' . $languageId)
                . "&v=" . $this->crypt->randomKey(16);
            $previewContainer = $this->backend->template('record.file_preview_container', [
                '{preview_url}' => $previewUrl,
                '{link_text}' => $linkText,
                '{unlink_url}' => $unlinkUrl
            ]);
        }
        $this->backend->formReplace('{src_id}', $this->id);
        $this->backend->formReplace('{src_field}', $fieldId);
        $this->backend->formReplace('{lang}', $languageId === null ? '' : (string)$languageId);
        $this->backend->formReplace('{preview}', $previewContainer);
    }

    /**
     * Aktiver Reiter der Eingabemaske: language_id oder 'all'.
     *
     * Beim Reiterwechsel (function=tab) das Ziel aus tab_target - außer die
     * Zwischenspeicherung ist gescheitert, dann bleibt der bisherige Reiter stehen,
     * damit der Fehler dort korrigiert werden kann. Sonst der übermittelte Reiter
     * (tab), beim ersten Aufruf die bevorzugte Sprachversion des Admins
     * (persönliche Einstellung settings.language_id), sofern die Instanz sie führt,
     * andernfalls die Hauptsprache. Beim ANLEGEN (function=create) immer die
     * Hauptsprache: ein neuer Datensatz braucht zuerst seine Pflichtwerte dort, die
     * anderen Sprachen sind nur Übersetzungen davon.
     *
     * @param int[] $languageIds  Sprachen der Instanz
     * @return int|string
     */
    private function _editCurrentTab(array $languageIds): int|string
    {
        $requested = (string)($_REQUEST['tab'] ?? '');
        $target    = (string)($_REQUEST['tab_target'] ?? '');
        $useTarget = ((string)($_REQUEST['function'] ?? '') === 'tab' && $this->function === 'tab');
        $tab = $useTarget && $target !== '' ? $target : $requested;
        if ($tab === 'all') return 'all';
        if ($tab !== '' && in_array((int)$tab, $languageIds, true)) return (int)$tab;
        // Anlegen: Hauptsprache
        if ((string)($_REQUEST['function'] ?? '') === 'create') return $languageIds[0] ?? 'all';
        // Erster Aufruf: bevorzugte Sprachversion des Admins
        $preferred = (string)($this->backend->adminSettings()['language_id'] ?? '');
        if ($preferred !== '' && in_array((int)$preferred, $languageIds, true)) return (int)$preferred;
        return $languageIds[0] ?? 'all';
    }

    /**
     * Werte der mehrsprachigen Felder für die Maske zusammenstellen:
     * $this->data[feld] = [language_id => Wert].
     *
     * Quellen, in dieser Reihenfolge je Sprache:
     *   1. der Request (function=errors / tab): rohe Eingabe im Anzeigeformat
     *   2. der Arbeitsstand der Arbeitskopie: Datenbankformat -> Anzeigeformat
     *      (_editFormatValue()); leer = nicht übersetzt
     *   3. ein einzelner Wert aus load()/init() (Cache der Hauptsprache bzw.
     *      Default) für die Hauptsprache
     *
     * @param array $schemaVirtual  Schema des Bereichs
     * @param int[] $languageIds    Sprachen der Instanz, Hauptsprache zuerst
     */
    private function _editLanguageValues(array $schemaVirtual, array $languageIds): void
    {
        $primaryId = $languageIds[0] ?? 0;
        $tmpData = ($this->id > 0) ? $this->record->loadTmpData($this->id) : [];
        $fromRequest = in_array((string)($_REQUEST['function'] ?? ''), ['save', 'tab'], true);

        foreach ($schemaVirtual as $name => $colDef) {
            if (!$this->cms->isMultilingualField($colDef)) continue;
            $type = (string)($colDef['type'] ?? 'text');
            if ((int)($colDef['visibility_input'] ?? 0) !== 1) continue;
            $displayType = (string)($colDef['display_type'] ?? 'text');

            if ($type === 'file') {
                // Upload-Feld: je Sprache die eigene Datei aus dem Arbeitsstand der
                // Arbeitskopie (keine Eingabe im Request - Datei-Felder stehen nicht
                // im POST, siehe _editRestoreSecuredFiles())
                $files = [];
                $entry = (array)($tmpData[$name] ?? []);
                $entries = !empty($entry) ? $this->record->fileEntries($entry) : [];
                foreach ($languageIds as $languageId) {
                    $files[$languageId] = (array)($entries[$languageId] ?? []);
                }
                $this->data[$name] = $files;
                continue;
            }

            $current = $this->data[$name] ?? null;
            $tmpEntry = $tmpData[$name][1] ?? null;
            $values = [];
            foreach ($languageIds as $languageId) {
                if ($fromRequest && is_array($current) && array_key_exists($languageId, $current)) {
                    // 1. aus dem Request (bereits Anzeigeformat)
                    $values[$languageId] = $current[$languageId];
                }
                elseif (is_array($tmpEntry) && array_key_exists($languageId, $tmpEntry)) {
                    // 2. aus dem Zwischenstand der Arbeitskopie
                    $value = $tmpEntry[$languageId];
                    $isEmpty = is_array($value) ? empty($value) : ((string)$value === '');
                    $values[$languageId] = $isEmpty ? $value : $this->_editFormatValue($type, $displayType, $value);
                }
                elseif (!is_array($current) && $languageId === $primaryId) {
                    // 3. einzelner Wert (Cache der Hauptsprache / Default)
                    $values[$languageId] = ($this->function === 'create')
                        ? $current
                        : $this->_editFormatValue($type, $displayType, $current);
                }
                else {
                    $values[$languageId] = ($type === 'json') ? [] : '';
                }
            }
            $this->data[$name] = $values;
        }
    }

    /**
     * Geleerte Eingabefelder im Request nachtragen (function=save / tab).
     *
     * Ein Browser schickt einen ausgeschalteten Switch gar nicht mit, und
     * serializeFiles() lässt leere Textfelder weg (kurze URLs). Für den Server sähe
     * ein geleertes Feld damit genauso aus wie ein Feld, das gar nicht in der Maske
     * stand - und Dataset::save() nähme dann den Zwischenstand aus dem Arbeitsstand bzw. den
     * Altwert. Bei mehrsprachigen Feldern mit Reitern geschah genau das: ein
     * herausgelöschter Text war nach dem Reiterwechsel wieder da.
     *
     * Deshalb führt die Maske das versteckte Feld 'shown' mit: die Liste aller
     * gerenderten Eingabefelder, "name" bzw. "name.{language_id}" (_editForm()).
     * Alles, was darin steht, aber in data[] fehlt, wird hier als leer nachgetragen
     * ('' bzw. [] bei Array-Typen). Ausgenommen:
     *
     *     file                    kein Upload heißt "Datei behalten"
     *     relation/pointer lookup schreiben sofort per Ajax, stehen nie im POST
     *
     * `parent_id` und `position` werden nicht als LEER, sondern als 0 nachgetragen:
     * ihr erster Auswahleintrag trägt den leeren Wert ("kein Vaterdatensatz" bzw.
     * "an den Anfang", siehe RecordBackend::_parentOptions()/_previousOptions()) -
     * genau den wirft serializeFiles() weg. Ohne das Nachtragen sähe Dataset::save()
     * sie als NICHT ÜBERMITTELT ($parentIdSubmitted / $previousIdSubmitted) und
     * bewegte den Datensatz gar nicht: "an den Anfang" und "kein Vaterdatensatz"
     * blieben wirkungslos. Eingegeben wird dabei der Vorgänger, nicht die Zahl -
     * aus `position` in 'shown' wird deshalb data[previous_id]
     * (_editPreviousParams()).
     *
     * Kein 'shown' im Request (z. B. Aufruf von außen): nichts wird verändert - eine
     * Maske, die die Felder gar nicht zeigt, lässt den Datensatz stehen, wo er ist.
     *
     * @param array $schemaVirtual  Schema des Bereichs
     */
    private function _editNormalizeSubmitted(array $schemaVirtual, ?array &$data = null, ?string $shownRaw = null): void
    {
        // Ohne Angaben: die Maske selbst (data[] / shown); eingebettete Inhalte
        // übergeben ihre Eingaben und ihr embed_shown[{record_id}] (_embedSave())
        if ($data === null) $data = &$this->data;
        $shownRaw = trim($shownRaw ?? (string)($_REQUEST['shown'] ?? ''));
        if ($shownRaw === '') return;

        foreach (explode(',', $shownRaw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            [$name, $languageId] = array_pad(explode('.', $entry, 2), 2, null);
            if (!isset($schemaVirtual[$name])) continue;
            $colDef = $schemaVirtual[$name];
            $type = (string)($colDef['type'] ?? 'text');
            $displayType = (string)($colDef['display_type'] ?? 'text');

            if ($type === 'file' || $type === 'fulltext') continue;
            if (in_array($type, $this->cms->relationTypes, true) && $displayType === 'lookup') continue;
            // 'link': Verknüpfungen hängen an der Arbeitskopie, nie im POST
            if (in_array($type, $this->cms->dependentTypes, true)) continue;
            // Baumfelder: 0 statt leer (siehe Methodenkommentar)
            if ($name === 'parent_id') {
                if (!array_key_exists('parent_id', $data)) $data['parent_id'] = 0;
                continue;
            }
            if ($name === 'position') {
                if ($displayType !== 'lookup' && !array_key_exists('previous_id', $data)) {
                    $data['previous_id'] = 0;
                }
                continue;
            }
            if ($name === 'previous_id') continue;

            $empty = in_array($type, $this->cms->arrayTypes, true) ? [] : '';

            if ($languageId !== null) {
                // mehrsprachig: data[name][language_id]
                if (!ctype_digit($languageId)) continue;
                $languageId = (int)$languageId;
                if (!is_array($data[$name] ?? null)) $data[$name] = [];
                if (!array_key_exists($languageId, $data[$name])) {
                    $data[$name][$languageId] = $empty;
                }
            }
            elseif (!array_key_exists($name, $data)) {
                $data[$name] = $empty;
            }
        }
    }

    /**
     * function=tab: Reiterwechsel - die Maske zwischenspeichern.
     *
     * Record::save() mit stayTemporary schreibt die geprüften Eingaben in das
     * Arbeitsstand der Arbeitskopie; die Sprachen, die nicht auf dem Reiter standen,
     * bleiben dort erhalten (Dataset::save()). Gespeichert werden die Felder, die
     * die Maske zeigt - alle sichtbaren Eingabefelder; mehrsprachige davon nur mit
     * der Reitersprache. Schlägt die Prüfung fehl, bleibt der bisherige Reiter
     * stehen (function 'errors'), sonst zeigt edit() die Maske mit dem Ziel-Reiter.
     *
     * @param array $schemaVirtual  Schema des Bereichs
     */
    private function _editTab(array $schemaVirtual): void
    {
        $fields = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if ((int)($colDef['visibility_input'] ?? 0) !== 1) continue;
            if (($colDef['type'] ?? '') === 'fulltext') continue;
            $fields[] = $name;
        }
        $fields[] = 'area_id';

        // Eingebettete Inhalte (link/embed) ebenso zwischenspeichern
        $embedOk = $this->_embedSave($schemaVirtual);

        // Kopie übergeben: save() wandelt $data per Referenz in die Speicherform um
        // (currency in Cent, Datum ISO ...) - die Maske zeigt danach aber die Eingabe
        $data = $this->data;
        if ($this->record->save($fields, $data, stayTemporary: true) && $embedOk) {
            // Maske mit dem Ziel-Reiter anzeigen (siehe _editCurrentTab())
            return;
        }
        $this->function = 'errors';
    }

    /**
     * Einen Datenbankwert für die Eingabemaske aufbereiten (siehe
     * _editFormatLoadedValues()).
     */
    private function _editFormatValue(string $type, string $displayType, mixed $value): mixed
    {
        if ($type === 'currency') {
            return $this->cms->_formatFloat((float)($value ?? 0) / 100, 2);
        }
        if (in_array($type, ['float', 'decimal'], true)) {
            return $this->cms->_formatFloat((float)($value ?? 0));
        }
        if (in_array($type, $this->cms->dateTypes, true)) {
            return $this->cms->_formatDate((string)($value ?? ''));
        }
        if ($type === 'time') {
            return $this->cms->_formatTime((string)($value ?? ''));
        }
        return $value;
    }

    /**
     * Formularparameter des Feldes `parent_id` anpassen.
     *
     * Beide Darstellungen brauchen eine eigene Vorlage, weil der Wechsel des
     * Vaterdatensatzes die Vorgängerliste hinfällig macht:
     *
     *     select   Vorlage mit onchange -> updatePreviousOptions() per Ajax
     *     lookup   eigenes Lookup-Feld. Es schreibt - anders als ein
     *              Verknüpfungsfeld - nichts sofort in die Datenbank, sondern legt
     *              die Auswahl als verstecktes data[parent_id] in die Vorschau.
     *              Das Kontextmenü der Verknüpfungsfelder passt dazu nicht.
     *
     * @param array  $fieldParams       Parameter für Backend::formField()
     * @param string $displayType       display_type des Feldes
     * @param bool   $hasPreviousField  Gibt es eine Vorgängerliste zum Nachladen?
     */
    private function _editParentParams(array $fieldParams, string $displayType, bool $hasPreviousField): array
    {
        if ($displayType === 'lookup') {
            $fieldParams['template'] = 'record.parent_lookup_field';
            $fieldParams['contextMenu'] = '';
            return $fieldParams;
        }
        if ($displayType === 'select' && $hasPreviousField) {
            $fieldParams['template'] = 'record.record_form_parent_select';
        }
        return $fieldParams;
    }

    /**
     * Formularparameter des Feldes `position` anpassen - eingegeben wird previous_id.
     *
     * `position` ist zwar der Feldname, den der Redakteur in field_create.php vergibt
     * (dort ist er schlüssig: ohne Vaterdatensatz ist die Position schlicht die
     * Sortierung), im Formular steht aber der VORANGEHENDE Datensatz - genauso wie im
     * Modul Area. Die Zahl selbst rechnet TreeManagerTrait beim Speichern aus:
     * Dataset::save() -> createInTree() / updateInTree() -> calculatePosition().
     * Deshalb heißt das Eingabefeld previous_id und nicht position.
     *
     * Die Beschriftung wird nur dann ersetzt, wenn der Redakteur sie nicht selbst
     * gesetzt hat - sonst stünde "Position" über einer Liste von Datensätzen.
     *
     * @param array $fieldParams  Parameter für Backend::formField()
     * @param int   $parentId     Vaterdatensatz, auf den sich die Liste bezieht
     * @param array $options      Auswahlliste aus _previousOptions()
     */
    private function _editPreviousParams(array $fieldParams, int $parentId, array $options): array
    {
        $fieldParams['name'] = 'previous_id';
        $fieldParams['value'] = $this->_previousValue($parentId, $options);

        $defaultLabel = (string)($this->cms->schema['position']['label'] ?? '');
        if ((string)($fieldParams['colDef']['label'] ?? '') === $defaultLabel) {
            $fieldParams['colDef']['label'] = '#record.field_previous_id';
        }
        return $fieldParams;
    }

    /**
     * Vorauswahl der Vorgängerliste.
     *
     * Drei Fälle, in der Reihenfolge der Prüfung:
     *
     *  1. Das Formular wird nach einem fehlgeschlagenen Speichern erneut gezeigt -
     *     dann steht die Eingabe des Redakteurs im Request und bleibt stehen.
     *  2. Neuer Datensatz - ans Ende der Geschwister, dieselbe Vorgabe wie in
     *     updatePreviousOptions(). Eine KOPIE kommt hier nicht an: _editLoad() legt
     *     ihr Vorbild als previous_id in $this->data, sie fällt damit unter Fall 1
     *     und steht direkt hinter dem Vorbild.
     *  3. Bestehender Datensatz - der Rückweg aus der gespeicherten `position`:
     *     getPreviousId() sucht die größte Position unterhalb der eigenen beim selben
     *     Parent. Position 1 heißt "steht am Anfang", also kein Vorgänger.
     *
     * @param int   $parentId  Vaterdatensatz, auf den sich die Liste bezieht
     * @param array $options   Auswahlliste aus _previousOptions()
     */
    private function _previousValue(int $parentId, array $options): int
    {
        if (array_key_exists('previous_id', $this->data)) {
            return (int)$this->data['previous_id'];
        }
        if (in_array($this->function, ['create', 'copy'], true)) {
            // 'disabled' ist der "…"-Eintrag einer abgeschnittenen Liste, kein Wert
            unset($options['disabled']);
            return (int)array_key_last($options);
        }
        $position = (int)($this->data['position'] ?? 0);
        if ($position < 2) return 0;
        // areaId: auf der Wurzelebene (parent_id IS NULL) unterscheidet nur der
        // Inhaltsbereich die Geschwister voneinander
        return $this->record->getPreviousId($parentId, $position, (int)$this->areaId);
    }

    /**
     * Ajax: Optionen des Select-Feldes "previous_id" neu liefern
     * (backend/record_previous.php).
     *
     * Wird aufgerufen, sobald der Redakteur im Formular den Vaterdatensatz wechselt -
     * die bisherige Vorgängerliste gehört dann zu den falschen Geschwistern. Die
     * Antwort besteht absichtlich nur aus <option>-Zeilen; das Javascript ersetzt
     * damit den Inhalt des vorhandenen <select> (cApp.forms.updateElement()).
     *
     * Bereich und Original-Id kommen NICHT aus dem Request, sondern über
     * Record::getProdData() aus der Arbeitskopie - das prüft zugleich, dass sie dem
     * angemeldeten Redakteur gehört. Ohne Arbeitskopie (id = 0: die Einfügeposition
     * der Import-Maske, RecordBackendImport) gilt area_id aus dem Request.
     *
     * Vorausgewählt wird immer der LETZTE Eintrag: der Datensatz landet ans Ende
     * seiner neuen Geschwister, dieselbe Vorgabe wie beim Anlegen.
     *
     * Request: id = Arbeitskopie (0 = keine, dann area_id), data[parent_id] = neu
     *          gewählter Vaterdatensatz
     */
    public function updatePreviousOptions(): void
    {
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->data = (array)($_REQUEST['data'] ?? []);

        if ($this->id > 0) {
            if (!$prodData = $this->record->getProdData($this->id)) {
                $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
                $this->errors();
                return;
            }
            [$originId, $areaId] = $prodData;
            $this->data['origin_id'] = $originId;
            $this->areaId = $areaId;
        }
        else {
            $this->areaId = (int)($_REQUEST['area_id'] ?? 0);
            $this->data['origin_id'] = 0;
        }

        if (!$this->areaData = $this->backend->areaData($this->areaId)) {
            $this->errors();
            return;
        }

        $options = $this->_previousOptions((int)($this->data['parent_id'] ?? 0));

        $selectable = $options;
        unset($selectable['disabled']);
        $lastKey = array_key_last($selectable);

        foreach ($options as $key => $val) {
            if ((string)$key === 'disabled') {
                $attr = ' disabled';
                $key = '';
            }
            else {
                $attr = ((string)$key === (string)$lastKey) ? ' selected' : '';
            }
            echo '<option value="' . htmlspecialchars((string)$key) . '"' . $attr . '>'
                . $this->cms->_strip_tags($val) . "</option>\n";
        }
    }

    /**
     * Ajax: Vorschau des Vaterdatensatz-Lookups aktualisieren
     * (backend/ajax_parent_lookup.php).
     *
     * Aufgerufen von cApp.ui.plookupUpdate(), nachdem in der Auswahlliste ein Häkchen
     * gesetzt oder entfernt wurde. Geschrieben wird dabei nichts: die Antwort enthält
     * das versteckte Feld data[parent_id], das erst mit dem Formular abgeht.
     *
     * Request: src_id = Arbeitskopie, parent_id = gewählter Vaterdatensatz (0 = keiner)
     */
    public function parentLookupPreview(): void
    {
        $this->id = (int)($_REQUEST['src_id'] ?? 0);
        $parentId = (int)($_REQUEST['parent_id'] ?? 0);

        if (!$prodData = $this->record->getProdData($this->id)) {
            $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
            $this->errors();
            return;
        }
        // Ein Datensatz kann nicht sein eigener Vaterdatensatz sein
        if ($parentId > 0 && $parentId === (int)$prodData[0]) $parentId = 0;

        echo $this->_parentLookupPreviewCreate($parentId);
    }

    /**
     * Den Vorschaublock des Vaterdatensatz-Lookups rendern.
     *
     * Gemeinsamer Kern von _editForm() und parentLookupPreview(). Anders als bei einem
     * Verknüpfungsfeld ist es immer höchstens EINE Zeile - und diese Zeile IST das
     * Eingabefeld: sie trägt das versteckte data[parent_id]. Ohne Vaterdatensatz
     * bleibt der Block leer, dann fehlt das Feld im Request und `parent_id` fällt auf
     * die Wurzelebene zurück.
     *
     * Als Beschriftung dient der Inhalt des Default-Feldes des Bereichs, in dem der
     * gewählte Datensatz liegt.
     *
     * @param int $parentId  Vaterdatensatz oder 0
     */
    private function _parentLookupPreviewCreate(int $parentId): string
    {
        if ($parentId < 1) return '';

        $this->cms->select([
            'filter' => ['record_id' => $parentId],
            'limit' => 1
        ]);
        if ($this->db->num_rows < 1) return '';
        if (!$data = $this->cms->fetch()) return '';

        $defaultField = $this->backend->getDefaultField((int)$data['area_id']);
        $label = $this->backend->backendValue(
            $data[$defaultField['name']] ?? '',
            ['type' => $defaultField['type'] ?? 'text']
        );

        $table = $this->backend->template('record.slookup_table', [
            '{table_rows}' => $this->backend->template('record.plookup_table_row', [
                '{text}' => $label,
                '{data_id}' => $parentId,
            ])
        ]);

        return $this->backend->template('record.lookup_preview_container', [
            '{html}' => $table
        ]);
    }

    /**
     * Geladene Werte ins Eingabeformat umwandeln.
     *
     * Aus der Datenbank kommen Werte in ihrer Speicherform; im Formular soll das
     * stehen, was der Redakteur eingegeben hätte:
     *
     *     currency        in Cent gespeichert -> geteilt durch 100, zwei Nachkommastellen
     *     float, decimal  lokales Dezimaltrennzeichen
     *     Datum, Zeit     ISO-Format -> lokales Format
     *     relation,       bestehende Verknüpfungen als Id-Array (bzw. einzelne Id bei
     *     pointer         einem Auswahlfeld) - nur bei Direktauswahl; Lookup-Felder
     *                     holen sich ihre Anzeige selbst
     *
     * Den Rückweg geht Dataset beim Speichern.
     *
     * @param array &$schemaVirtual  Zeiger auf das Tabellenschema, nur virtuelle Felder
     */
    private function _editFormatLoadedValues(array &$schemaVirtual):void
    {
        // $virtalSchema enthält alle Eingabefelder
        foreach ($schemaVirtual as $name => $colDef) {
            // Nur sichtbare Eingabefelder beachten
            $visibilityInput = (int)($colDef['visibility_input'] ?? 0);
            if ($visibilityInput == 0) continue;

            // Werte je nach Typ in Eingabeformat umwandeln
            $type = (string)($colDef['type'] ?? 'text');
            $displayType = $colDef['display_type'] ?? 'text';
            $options = [];
            // Mehrsprachige Felder formatiert _editLanguageValues() je Sprache
            // (sofern die Instanz mehrere Sprachen führt - sonst wie einsprachig)
            if (!empty($colDef['multilingual'])
                && in_array($type, $this->cms->multilingualTypes, true)
                && count($this->_recordLanguages()) > 1
            ) {
                continue;
            }
            if (in_array($type, $this->cms->relationTypes, true)) {
                // relation / pointer Direktauswahl ('link' hat keine: Lookup oder
                // eingebettete Masken, die ihre Anzeige selbst holen)
                if ($displayType !== 'lookup' && !in_array($type, $this->cms->dependentTypes, true)) {
                    // Bestehende Verknüpfungen in Array laden:
                    $this->cms->select([
                        'src_id' => $this->id,
                        'relation' => $name
                    ]);
                    $destIds = [];
                    while ($row = $this->db->fetch()) {
                        $destIds[] = (int)$row['record_id'];
                    }

                    if ($displayType === 'select') {
                        $this->data[$name] = $destIds[0] ?? 0;
                    }
                    else {
                        // multiselect
                        $this->data[$name] = $destIds;
                    }

                }
            }
            else {
                $this->data[$name] = $this->_editFormatValue($type, $displayType, $this->data[$name] ?? null);
            }
        }    
    }

    /**
     * function=create (mit Bereich): Arbeitskopie für einen neuen Datensatz anlegen.
     *
     * tmpInit() legt sie sofort in der Datenbank an - anders als init() in den übrigen
     * Modulen, das nur ein Array mit Defaults liefert. Der Grund: Verknüpfungen und
     * Uploads brauchen eine record_id, an die sie sich hängen können, und zwar schon
     * beim Ausfüllen des Formulars.
     *
     * $this->id trägt danach die Id der Arbeitskopie, nicht 0.
     */
    private function _editCreate(): void
    {
        // INIT
        // --------------------
        // (Rechte: Anlegen im Bereich prüft tmpInit(), Meldung in Cms::errors)
        if (!$this->data = $this->record->tmpInit(['area_id' => $this->areaId])) {
            if (!$this->cms->hasErrors) $this->cms->error('_editCreate(): creation of temporary dataset failed.');
            $this->function = 'skip';
            return;
        }
        $this->id = $this->data['record_id'];
        $this->session->registerWorkingCopy((int)$this->id);
    }

    /**
     * function=create (ohne Bereich): Schritt 1 - Inhaltsbereich wählen.
     *
     * Erst der Bereich sagt, welche Felder ein Datensatz hat; ohne ihn lässt sich
     * keine Maske bauen. Die Auswahl schickt dasselbe Script mit gesetzter area_id
     * erneut ab.
     *
     * Kommt der Aufruf aus einem Pointer-/Link-Feld, sind nur dessen Zielbereiche
     * wählbar ($areaIds aus _resolveParams()); sonst fehlen die abhängigen Bereiche
     * (`area.dependent`, siehe LinkTrait).
     */
    private function _editCreateForm(): void
    {
        echo '<form action="Record/record_edit.php" method="post">';
        $this->backend->formInit('record.record_create_form');

       // area_id
        $sqlTypes = '';
        $sqlValues = [];            
        // (nur Bereiche, in denen die Gruppe anlegen darf)
        $sql = "SELECT `area_id`, `depth`, `label` FROM `area` WHERE `type` IN ('record','profile','ugc') AND `deleted`=0"
             . $this->backend->areaAccessSql(writable:true);
        
        if (!empty($this->areaIds)) {
            $placeholders = implode(',', array_fill(0, count($this->areaIds), '?'));
            $sql .= " AND `area_id` IN ($placeholders)";                
            $sqlTypes .= str_repeat('i', count($this->areaIds));
            $sqlValues = $this->areaIds;
        }
        else {
            // Abhängige Bereiche (Zielbereiche von 'link'-Feldern) nur aus einem
            // solchen Feld heraus - ihre Datensätze gehören einem Quelldatensatz
            $sql .= " AND `dependent` = 0";
        }
        
        $sql .= " ORDER BY `lft` ASC";            
        $this->db->query($sql, $sqlTypes, $sqlValues);
        
        $areaOptions = [];
        while ($areaData = $this->db->fetch()) {
            $fullPath = str_repeat('&nbsp; ', (int)$areaData['depth'] * 2) . $this->cms->textOrSnippet($areaData['label']);
            $areaId = (string)$areaData['area_id'];
            $areaOptions[$areaId] = $fullPath;
        }
        $areaColDef = [
            'type' => 'integer',
            'display_type' => 'select',
            'label' => '#area.content_area'
        ];
        $this->backend->formField($this->record, [
            'name' => 'area_id',
            'value' => $this->areaFilter,
            'colDef' => $areaColDef,
            'options' => $areaOptions,
            'template' => 'record.record_select_area_id',
            'selectPlease' => true
        ]);

        // Hidden-Felder
        // --------------------
        $this->backend->formHidden('function', 'create');
        // call by lookup field
        $this->backend->formHidden('src_field', $this->srcField);
        $this->backend->formHidden('src_id', $this->srcId);

        // Formular rendern
        echo $this->backend->form('form_save_cancel');
        echo '</form>';
    }

    /**
     * function=edit|copy|delete: Datensatz laden und zur Bearbeitung auschecken.
     *
     * Drei Schritte:
     *
     *  1. Laden. Eine Arbeitskopie oder ein gelöschter Datensatz darf hier nicht
     *     ankommen - bearbeitet wird immer der produktive Stand.
     *  2. Bearbeitungssperre prüfen (nur bei 'edit'): findet Record::getLock() eine
     *     fremde Arbeitskopie, arbeitet gerade jemand anders daran. Angezeigt wird
     *     dann ein Hinweis mit Name und Zeitpunkt, dazu ein Link mit unlock=1, der
     *     die Sperre übergeht - eine harte Sperre wäre unbrauchbar, weil eine
     *     abgebrochene Sitzung sie sonst dauerhaft stehen ließe.
     *  3. Check-out: Record::checkOut() legt die Arbeitskopie an, ab hier zeigt
     *     $this->id auf sie. Beim Kopieren entsteht dabei eine Kopie ohne
     *     origin_id - sie wird beim Speichern zu einem neuen Datensatz.
     */
    /**
     * Platzhalter statt Eingabemaske bei einem GET (siehe edit(), "Check-out nur per
     * POST"). cApp.layers.openPost() lädt den Layer per POST neu - sofort, wenn er
     * aus der Oberfläche geöffnet wurde, sonst mit dem Knopf.
     */
    private function _editOpenStub(): void
    {
        $elementId = 'c9-open-' . bin2hex(random_bytes(6));
        $label = match ($this->function) {
            'copy' => $this->cms->snippet('button_copy'),
            'create' => $this->cms->snippet('button_create'),
            default => $this->cms->snippet('button_edit'),
        };
        echo $this->backend->template('record.edit_open_stub', [
            '{element_id}' => $elementId,
            '{button}' => htmlspecialchars($label),
            '{onclick}' => "cApp.layers.openPost('{$elementId}', true)",
        ]);
        $this->backend->script("cApp.layers.openPost('{$elementId}');");
    }

    private function _editLoad(): void
    {
        // Laden, produktiv?, Rechte der Benutzergruppe (12-group-rights.md):
        // Ändern/Löschen nach der Gruppe des Owners, Kopieren = Anlegen im Bereich -
        // und nur, wenn alle Verknüpfungsfelder lesbare Zielbereiche haben
        // (RecordService::loadForAction())
        $action = match ($this->function) {
            'delete' => 'delete',
            'copy'   => 'copy',
            default  => 'edit',
        };
        if (!$this->data = $this->service->loadForAction($this->id, $action)) {
            // Fehlermeldung steckt in Cms::errors
            $this->function = 'skip';
            return;
        }

        // Beim Auflösen der Seitenparameter stand der Bereich noch nicht fest - der
        // Aufruf nennt nur die Datensatz-Id. Erst jetzt lassen sich die Angaben zu
        // Default- und Sortierfeld nachladen; ohne sie bleiben die Beschriftungen in
        // _parentOptions() und _previousOptions() leer.
        $this->areaId = (int)$this->data['area_id'];
        $this->areaData = $this->backend->areaData($this->areaId);

        // Edit-Lock prüfen (sofern nicht Parameter unlock=1 gesetzt ist)
        // --------------------
        if ($this->function == 'edit' && !(bool)($_REQUEST['unlock'] ?? 0)) {
            if ($lock = $this->service->lockInfo($this->data)) {
                // Meldung mit Knöpfen Sperre aufheben/Abbrechen - ausgegeben in edit()
                // unter der Überschrift und dem Default-Feld des Datensatzes
                $defaultName = $this->backend->getDefaultField($this->areaId)['name'] ?? '';
                $label = $this->data[$defaultName] ?? '';
                if (!is_scalar($label) || (string)$label === '') $label = $this->id;
                // (template() löst {#…} erst nach den Platzhaltern auf - der Text mit
                // seinen eigenen Platzhaltern wird deshalb hier zusammengesetzt)
                $text = strtr($this->cms->snippet('record.alert_record_locked'), [
                    '{admin_name}' => $this->backend->teaser('admin', $lock['admin_id']),
                    '{changed_at}' => $this->cms->_formatDate($this->cms->utcToUserTZ(substr($lock['changed_at'], 0, 19)))
                ]);
                $this->lockAlert = $this->backend->template('record.alert_record_locked', [
                    '{id}' => $this->id,
                    '{label}' => htmlspecialchars((string)$label),
                    '{text}' => $text,
                ]);
                $this->function = 'locked';
                return;
            }
        }

        // CHECK-OUT
        // --------------------
        // Kopie: RecordService::checkOut() gibt ihr das Vorbild als `previous_id` mit -
        // sie steht dann unmittelbar hinter ihm, beim selben Vaterdatensatz.
        // Fest verknüpfte Inhalte eingebetteter 'link'-Felder werden mit
        // ausgecheckt (nicht beim Löschen); scheitert das, ist die Arbeitskopie
        // wieder verworfen und $this->id zeigt weiter auf das Original.
        $tmpId = $this->service->checkOut($this->data, $this->function === 'copy', dependents: $this->function !== 'delete');
        if (!$tmpId) {
            $this->function = 'skip';
            return;
        }
        $this->id = $tmpId;
    }


    /**
     * Die Eingabemaske für eine BESTEHENDE Arbeitskopie des Session-Admins - ohne
     * neuen Check-out. Gebraucht vom Verschieben (RecordBackendMove): scheitert dort
     * die Prüfung im Zielbereich, bleibt die von MoveTrait::movePrepare() umgebaute
     * Arbeitskopie stehen, und der Redakteur ergänzt die fehlenden Eingaben in der
     * Maske des Zielbereichs. Ab hier ist es eine gewöhnliche Bearbeitung: Speichern
     * (function=save) checkt die Kopie ein, Abbrechen (record_cancel.php) verwirft
     * sie - der Datensatz bleibt dann, wo er war.
     *
     * Werte kommen aus der Arbeitskopie selbst: Record::load() liest sie wie einen
     * produktiven Datensatz (Cms::fetch() über `record_cache`, Verknüpfungen über
     * data_rel der Kopie), Sprachen ergänzt _editLanguageValues() aus dem
     * Arbeitsstand. Feldfehler in Cms::errors (Namen der Zielfelder, mehrsprachig
     * "name.{language_id}") erscheinen an ihren Feldern.
     *
     * @param int $tmpId  Arbeitskopie (tmp = 1, changed_by = Session-Admin)
     * @return bool       false, wenn die Kopie nicht (mehr) dem Admin gehört
     */
    protected function _editWorkingCopyForm(int $tmpId): bool
    {
        if (!$prodData = $this->record->getProdData($tmpId)) {
            $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
            return false;
        }
        $this->id = $tmpId;
        $this->areaId = (int)$prodData[1];
        if (!$this->areaData = $this->backend->areaData($this->areaId)) {
            $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            return false;
        }
        $this->params = 'area_id=' . $this->areaId;
        if (!$this->data = $this->record->load($tmpId)) return false;
        $this->data['record_id'] = $tmpId;

        $schemaVirtual = $this->record->virtualSchema($this->areaId, addMetaFields: false);
        // Eingebettete Inhalte (link/embed) der Kopie auschecken, sofern noch nicht
        // geschehen - beim Verschieben zeigen ihre Kanten noch auf die produktiven
        // Inhalte (LinkTrait::checkOutDependents() übergeht Arbeitskopien)
        if (!$this->record->checkOutDependents($tmpId, false, $schemaVirtual)) return false;
        $this->_editFormatLoadedValues($schemaVirtual);
        $this->_editRestoreSecuredFiles($schemaVirtual);
        $this->_editForm($schemaVirtual);
        return true;
    }

    /**
     * function=save: Eingaben prüfen und speichern.
     *
     * Record::save() erledigt alles Weitere: Validierung, checkIn der Arbeitskopie,
     * Übernahme der Dateien (promoteTmpFiles()) und der Verknüpfungen, Neuberechnung
     * des Baumes - alles in einer Transaktion. Die Prüfung der Bearbeitungssperre
     * steckt ebenfalls dort.
     *
     * Wohin es danach geht, hängt davon ab, woher der Aufruf kam: aus einem
     * Lookup-Feld heraus wird der neue Datensatz dort gleich verknüpft, sonst wird
     * die Datensatzliste aktualisiert.
     *
     * @param array $fields  zu schreibende Felder - die Schema-Felder des Bereichs
     */
    private function _editSave(array $fields): void
    {
        // EDIT LOCK - Zugriff prüfen
        // wird in Record::save() durchgeführt

        // Fest verknüpfte Inhalte (link, LinkTrait)
        // --------------------
        // 1. eingebettete Inhalte mit ihren Eingaben prüfen und zwischenspeichern
        //    (stayTemporary) - vor allem anderen, damit kein Check-in beginnt,
        //    solange eine Zelle Fehler hat
        // 2. in einer Transaktion: Check-in der Inhalte (checkInDependents(): auch
        //    Kopien und Verwaiste), dann der Quelldatensatz. Scheitert eines,
        //    kommt alles zurück; die Arbeitskopien bleiben stehen
        $schemaVirtual = $this->record->virtualSchema($this->areaId, addMetaFields:false);
        if (!$this->_embedSave($schemaVirtual)) {
            $this->function = 'errors';
            return;
        }
        // Check-in samt der Inhalte in einer Transaktion (RecordService::checkIn()).
        // Kopie: bei Fehlern zeigt die Maske die Eingabe, nicht die Speicherform
        $newId = $this->service->checkIn($this->id, $fields, $this->data);
        if ($newId) {
            // Balloon Message
            $msg = $this->cms->snippet('message_record_saved');
            $this->backend->balloon('success', $msg);

            // Debug-Funktion (Weiterleitung aussetzen, um direkte Debug-Ausgaben zu sehen)
            if (
                $this->data['title'] == 'camindodebugmedia'
                && !empty($this->config->get('system.development_version'))
            ) {
                echo $msg . '<br><br>' . $this->backend->template('button_close', [
                    '{onclick}' => 'cApp.layers.close()'
                ]);
                return;
            }

            if ($this->srcField > 0 && $this->srcIsParent && $this->srcId > 0) {
                // Referer ist das Lookupfeld für den Vaterdatensatz in einer
                // Eingabemaske -> den neuen Datensatz dort eintragen (nicht verknüpfen).
                // Erst schließen: dieses Formular hat SELBST ein Lookup-Feld mit
                // derselben src-field, das sonst als Ziel gefunden würde
                $this->backend->script("
                    cApp.layers.close();
                    cApp.ui.plookupUpdate({$this->srcField},'link',$newId,true);
                ");
            }
            elseif ($this->srcField > 0 && $this->srcIsParent) {
                // Dasselbe Feld in der SUCHMASKE (kein src_id): dort dürfen mehrere
                // Vaterdatensätze gewählt werden, es gilt das gewöhnliche Such-Lookup
                $this->backend->script("
                    cApp.layers.close();
                    cApp.ui.slookupUpdate({$this->srcField},'link',$newId,true);
                ");
            }
            elseif ($this->srcField > 0) {
                if (!empty($this->straight)) {
                    // Referer ist Lookupfeld -> neuen Datensatz verknüpfen
                    $this->backend->script("
                        cApp.ui.lookupRUpdate({$this->srcField},'link',$newId,0,true,{$this->srcId});
                        cApp.layers.close();
                    ");
                }
                else {
                    // Referer ist Verknüpfungsliste -> Liste aktualisieren
                    // und im Anschluss Datensatz verknüpfen (schließt ggf die Liste)
                    $this->backend->script("
                        cApp.layers.invalidate(function(){cApp.ui.lookupRUpdate({$this->srcField},'link',$newId,0,false,{$this->srcId});});
                        cApp.layers.close();
                    ");
                }
            }
            else {
                // Referer ist Datensatzliste -> diese aktualisiert anzeigen
                $this->backend->script("
                    cApp.layers.invalidate();
                    cApp.layers.close();
                ");
            }
            return;
        }
        $this->function = 'errors';
    }


    /**
     * Nach fehlgeschlagener Eingabeprüfung (function 'errors'):
     * Datei-Felder aus dem Zwischenstand (cache) der Arbeitskopie in $this->data
     * zurückspielen, damit die von Dataset::save() -> _secureUploads() bereits
     * gesicherten Uploads in der erneut angezeigten Eingabemaske erscheinen
     * (Preview-Link + x-Button in _editForm()).
     *
     * Hintergrund: $this->data basiert auf $_REQUEST['data'] (POST) - Datei-Felder
     * stehen dort nie drin; $_FILES ist nach move_uploaded_file() verbraucht.
     * Die Datei selbst hängt bereits korrekt an der Arbeitskopie
     * (physisch + `data_fil` + cache) und geht beim nächsten erfolgreichen
     * Speichern via promoteTmpFiles() produktiv - es fehlt nur die Anzeige.
     *
     * @param array $schemaVirtual  Schema mit virtuellen Feldern
     */
    private function _editRestoreSecuredFiles(array $schemaVirtual): void
    {
        if ($this->id < 1) return;

        // Nur für temporäre Arbeitskopien des Session-Admins
        // (gleiches Zugriffsmuster wie removeUpload())
        if (!$this->record->getProdData($this->id)) return;

        // Arbeitsstand der Arbeitskopie laden
        $cache = $this->record->loadTmpData($this->id);
        if (empty($cache)) return;

        foreach ($schemaVirtual as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') !== 'file') continue;
            // Eintrag hat das Format [$type, $fileData], mehrsprachig je Sprache
            $entry = $cache[$name] ?? null;
            if (($entry[0] ?? '') !== 'file') continue;
            if ($this->cms->isMultilingualField($colDef)) {
                $files = [];
                foreach ($this->record->fileEntries((array)$entry) as $langKey => $fileData) {
                    if ($langKey === '') continue;
                    if (!empty($fileData['deleted']) || (int)($fileData['size'] ?? 0) < 1) continue;
                    $files[(int)$langKey] = $fileData;
                }
                $this->data[$name] = $files;
                continue;
            }
            $fileData = (array)($entry[1] ?? []);
            // Löschmarkierungen (x-Button) und leere Einträge nicht anzeigen
            if (!empty($fileData['deleted']) || (int)($fileData['size'] ?? 0) < 1) continue;
            $this->data[$name] = $fileData;
        }
    }

    /**
     * function=delete: Löschbestätigung.
     *
     * Führt der Bereich einen Baum (Eingabefeld `parent_id`, Record::isTreeArea())
     * und hat der Datensatz Nachkommen, wird wie bei den Ordnern gefragt, was mit
     * ihnen geschehen soll (vgl. AreaBackend::_editDeleteForm()): eine Ebene hoch
     * rücken oder mit löschen. Das Radio-Feld steht in
     * record_delete_with_option_form.html und kommt als $_REQUEST['deletechilds']
     * zurück. Ohne Baum bzw. ohne Nachkommen bleibt es bei der einfachen Rückfrage.
     *
     * Gezählt wird der ganze Teilbaum über lft/rgt (nicht nur die direkten Kinder),
     * je Bereich - dort beginnt lft jeweils wieder bei 1 (TreeManagerTrait). Die
     * Grenzen stammen aus $this->data: das ist der produktive Stand, den
     * _editLoad() geladen hat - der Check-out ändert daran nur record_id/tmp/
     * origin_id, die Arbeitskopie selbst hat lft = rgt = 0.
     */
    private function _editDeleteForm(): void
    {
        echo '<form action="Record/record_edit.php" method="post">';

        $countChilds = 0;
        if ($this->record->isTreeArea($this->areaId)) {
            $lft = (int)($this->data['lft'] ?? 0);
            $rgt = (int)($this->data['rgt'] ?? 0);
            if ($lft > 0 && $rgt > $lft + 1) {
                $sql = "SELECT COUNT(`record_id`) FROM `record`"
                    ." WHERE `area_id` = ? AND `lft` > ? AND `rgt` < ?"
                    ." AND `tmp` = 0 AND `deleted` = 0";
                $this->db->query($sql, 'iii', [$this->areaId, $lft, $rgt]);
                $countChilds = (int)$this->db->fetchNum()[0];
            }
        }

        // Verknüpfte Datensätze (irgendein Verweisfeld, auch beidseitige Relationen
        // über ausgeblendete Gegenfelder): Hinweis mit Knopf zur bereichsübergreifenden
        // Trefferliste relation=any + dest_id - dieselbe Menge wie die Zählung
        $references = '';
        $originId = (int)($this->data['origin_id'] ?? 0) ?: $this->id;
        $linked = $this->service->linkedRecordCount($originId);
        if ($linked > 0) {
            $references = $this->backend->template('record.record_delete_references', [
                '{text}' => htmlspecialchars($this->cms->snippet('record.alert_record_delete_referenced', ['count' => $linked])),
                '{button}' => $this->backend->template('button_secondary', [
                    '{onclick}' => "cApp.layers.add('Record/record_list.php?relation=any&dest_id={$originId}')",
                    '{label}' => htmlspecialchars($this->cms->snippet('record.button_referenced_details')),
                ]),
            ]);
        }

        // Was die Lösch-Policy des Bereichs mit der Datenbank tut (Modul Privacy)
        $policy = $GLOBALS['app']->get(\Camindo\Core\Modules\Privacy\PrivacyBackend::class)->hintHtml($originId);

        if ($countChilds > 0) {
            // Datensatz hat Nachkommen -> Rückfrage mit Wahlmöglichkeit
            $items = $this->cms->snippet('record.x_records', ['count' => $countChilds]);
            $msg = str_replace('{items}', $items, $this->cms->snippet('record.alert_record_delete_with_childs'));
            $uid = $this->crypt->randomKey(16);
            $this->backend->formInit('record.record_delete_with_option_form', [
                '{text}' => $msg,
                '{id}' => $uid,
                '{references}' => $references,
                '{policy}' => $policy,
            ]);
        }
        else {
            $this->backend->formInit('record.record_delete_form', ['{references}' => $references, '{policy}' => $policy]);
        }

        // Hiddenfelder
        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('function', 'delete_confirm');
        // call by lookup field?
        $this->backend->formHidden('src_field', $this->srcField);
        $this->backend->formHidden('src_id', $this->srcId);

        // Formular rendern
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }

    /**
     * Löschung durchführen - nach der Lösch-Policy des Bereichs (Modul Privacy,
     * RecordService::delete()). Meist heißt das: in den Papierkorb; endgültig
     * gelöscht wird dann vom Systemtool nach admin.trash_retention_days
     * (Zeitplaner-Aufgabe "Papierkorb leeren"). Die Policy kann stattdessen Felder überschreiben oder
     * erst später handeln ('changed' / 'scheduled').
     *
     * $withChilds steuert, was mit den Kind-Datensätzen passiert (Radio-Feld aus
     * _editDeleteForm(), nur bei Bereichen mit Baum und vorhandenen Nachkommen):
     *     true   der ganze Teilbaum wird mitmarkiert, der Baum bleibt unverändert
     *     false  die Kinder rücken eine Ebene hoch (Dataset::liftChildren()), Standard
     * Große Teilbäume wandern ab admin.mass_delete_threshold in einen
     * Hintergrundprozess, damit das Löschen nicht am Web-Timeout scheitert.
     */
    private function _editDeleteConfirm(): void
    {
        $withChilds = ((string)($_REQUEST['deletechilds'] ?? 'false') === 'true');

        // Große Teilbäume gehen in den Hintergrund (RecordService::delete());
        // $this->id ist die Arbeitskopie, gemeint ist das Original
        $result = $this->service->delete($this->id, $withChilds);
        if ($result === 'background') {
            $this->backend->balloon('info', $this->cms->snippet('message_background_started'));
            $this->backend->script("
                cApp.layers.invalidate();
                cApp.layers.close();
            ");
            return;
        }

        // DELETE
        // --------------------
        if (in_array($result, ['deleted', 'changed', 'scheduled'], true)) {
            // Balloon Message
            $msg = $this->cms->snippet(match ($result) {
                'changed'   => 'privacy.message_record_changed',
                'scheduled' => 'privacy.message_record_scheduled',
                default     => 'record.message_record_deleted',
            });
            $this->backend->balloon('success', $msg);
            
            if ($this->srcField > 0) {
                // Referer ist Lookupfeld -> preview Container aktualisieren
                $this->backend->script("
                    cApp.ui.lookupRUpdate({$this->srcField},'',0,0,false,{$this->srcId});
                    cApp.layers.close();
                ");
            }
            else {
                // Referer ist Datensatzliste -> diese aktualisieren
                $this->backend->script("
                    cApp.layers.invalidate();
                    cApp.layers.close();
                ");
            }
        }
        else {
            $this->function = 'errors';
        }
    }


    /**
     * Bearbeitung abbrechen (Ajax).
     *
     * Verwirft die Arbeitskopie samt der daran hängenden Dateien und hebt damit
     * zugleich die Bearbeitungssperre auf. Der produktive Datensatz bleibt, wie er
     * war - deshalb ist "Abbrechen" hier tatsächlich folgenlos, obwohl währenddessen
     * schon geschrieben wurde.
     */
    public function cancel(): void {
        // Verwirft die Arbeitskopie samt hochgeladener Dateien - nur per POST
        // (17-csrf.md §2.7). Der Layer schließt in jedem Fall: ein stehengebliebener
        // Layer wäre für den Anwender schlimmer als eine übrige Arbeitskopie, die der
        // Papierkorb (Modul Tools) ohnehin abräumt.
        $id = (int)($_REQUEST['id'] ?? 0);
        if ($this->backend->requirePost()) $this->record->cancel($id);
        $this->backend->script('cApp.layers.close();');
    }


    /**
     * Auswahlliste der verknüpfbaren Datensätze.
     *
     * Für Relations- und Pointer-Felder mit Direktauswahl (display_type select,
     * multiselect, radio, checkbox) - nicht für Lookup-Felder, die ein eigenes
     * Suchfenster haben.
     *
     * Bei mehreren Zielbereichen (Pointer) werden sie nacheinander abgearbeitet;
     * innerhalb eines Bereichs gilt dessen Standardsortierung, angezeigt wird der
     * Inhalt seines Default-Feldes. Beides kann je Bereich verschieden sein, deshalb
     * die Schleife statt einer einzigen Abfrage.
     *
     * Die Gesamtzahl ist auf admin.list_max_datasets begrenzt (Standard 200) - der
     * Zähler läuft über alle Bereiche hinweg, und das Limit der nächsten Abfrage wird
     * um das bereits Geladene verringert. Bei Überschreitung wird abgebrochen und ein
     * deaktivierter "…"-Eintrag angehängt, statt die Liste stillschweigend zu kürzen.
     * Für größere Mengen ist das Lookup-Feld gedacht.
     *
     * @param array $destAreas      Inhaltsbereichs-Ids
     * @param bool $addEmptyOption  true (default): Bitte wählen-Option am Anfang der Liste
     * @return array                Array mit id => defaultField Elementen
     */
    protected function _relatedDatasetOptions(array $destAreas, bool $addEmptyOption = false): array {

        $areaIds = $this->system->sanitizeIds($destAreas, removeZero:true);
        if (empty($areaIds)) return [];

        $multipleAreas = (count($areaIds) > 1);

        // maximale Datensätze in Direktauswahl
        $limit = $this->config->get('admin.list_max_datasets') ?? 200;
        // mitzählen über mehrere Bereiche
        $count = 0;

        $options = $addEmptyOption ? ['' => $this->cms->snippet('please_select')] : [];
        
        // Datensätze mehrerer Bereiche: gruppiert nach Bereich,
        // innerhalb Bereich wird Standard-Sotierung des Bereichs genommen
        foreach ($areaIds as $areaId) {
            $areaData = $this->backend->areaData((int)$areaId);
            $defaultFieldName = (string)$areaData['default_field_name'];
            $defaultFieldType = (string)$areaData['default_field_type'] ?? 'text';
            $param = [];
            $param['filter']['area_id'] = $areaId;
            // Standardsortierung des Bereichs
            $param['sort'] = (string)$areaData['sort_field_name'];
            $param['order'] = $areaData['sort_order'];
            // lade +1 um zu prüfen, ob mehr vorhanden sind, abzüglich Anzahl aus Vorrunden
            $param['limit'] = $limit + 1 - $count;
            $this->cms->select($param);
            $numRows = $this->db->num_rows;
            // Anzahl der angezeigten Datensätze
            $displayNumber = min($numRows, $limit - $count);
            $colDef = ['type' => $defaultFieldType];
            for ($i = 0; $i < $displayNumber; $i++) {
                $data = $this->record->fetch();
                $value = $this->backend->backendValue($data[$defaultFieldName] ?? '', $colDef);
                $options[(string)$data['record_id']] = $value;
            }
            // Neu geladene Datensätze zum Gesamtzähler hinzufügen
            $count += $numRows;
            // Limit überschritten?
            if ($count > $limit) {
                $options['disabled'] = $this->cms->snippet('ellipsis_spoken');
                break; // keine weiteren Areas mehr verarbeiten
            }
        }
        
        return $options;
    }

    /**
     * Ajax: Verknüpfungen eines Lookup-Feldes bearbeiten und die Vorschau neu liefern.
     *
     * Die Verknüpfung wird SOFORT geschrieben, nicht erst beim Speichern des
     * Formulars - möglich, weil sie an der Arbeitskopie hängt und mit ihr verworfen
     * würde (siehe Klassenkommentar).
     *
     *     link     verknüpfen; bei max = 1 wird die bestehende Verknüpfung ersetzt
     *              statt eine zweite anzulegen
     *     unlink   eine Verknüpfung lösen
     *     empty    alle Verknüpfungen des Feldes lösen
     *     swap     zwei Verknüpfungen in der Reihenfolge tauschen (nur bei Feldern
     *              mit custom_order)
     *
     * Danach in jedem Fall die aktualisierte Vorschau. Fehler werden abgefangen und
     * als Meldung ausgegeben - die Prüfungen (Höchstzahl, zulässiger Zielbereich)
     * stecken in Record::createRelations().
     *
     * Der auskommentierte 'up'/'down'-Block ist der Vorgänger von 'swap'.
     *
     * Request: src_field, src_id, function, dest_id, swap_id
     */
    public function lookupPreview() {
        $srcField  = (int)($_REQUEST['src_field'] ?? 0);
        $srcId     = (int)($_REQUEST['src_id'] ?? 0);
        $destId    = (int)($_REQUEST['dest_id'] ?? 0);
        $swapId    = (int)($_REQUEST['swap_id'] ?? 0);
        $function  = (string)($_REQUEST['function'] ?? '');

        // link/unlink/empty schreiben data_rel der Arbeitskopie - nur per POST
        // (17-csrf.md §2.7). Ohne 'function' ist der Aufruf eine reine Neuanzeige
        // des Vorschaublocks und darf GET bleiben.
        if (in_array($function, ['link', 'unlink', 'empty'], true) && !$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }

        // Lade Daten des angegebenen Relation-Feldes
        if (!$fieldData = $this->record->fieldData($srcField)) {
            $this->cms->error('lookupPreview() Exception: field not found.');
            $this->errors();
            return;
        }

        if ($function == 'link') {
            try {
                $max = (int)$fieldData['max'];
                if ($max === 1) $replace = true;
                else $replace = false;
                $this->record->createRelations($fieldData, $srcId, $destId, $replace);
            } catch (\Exception $e) {
                // (Feldfehler aus createRelations() stehen schon in Cms::errors - '#')
                $errorMsg = $e->getMessage();
                if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
                $this->errors();
                return;
            }
            $msg = $this->cms->snippet('record.message_relation_updated');
            $this->backend->balloon('success', $msg);
        }

        if ($function === 'unlink') {
            try {
                $this->record->removeRelations($fieldData, $srcId, $destId);
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                $this->cms->error($errorMsg);
                $this->errors();
                return;
            }
            $msg = $this->cms->snippet('record.message_relation_updated');
            $this->backend->balloon('success', $msg);
        }

        if ($function === 'empty') {
            try {
                $this->record->removeRelations($fieldData, $srcId);
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                $this->cms->error($errorMsg);
                $this->errors();
                return;
            }
            $msg = $this->cms->snippet('record.message_lookup_emptied');
            $this->backend->balloon('success', $msg);
        }

        /*
        if ($function === 'up' || $function === 'down') {
            if ($this->record->moveRelationDestId($srcField, $srcId, $destId, $function)) {
                $msg = $this->cms->snippet('record.message_relation_updated');
                $this->backend->balloon('success', $msg);
            }
        }
        */

        if ($function == 'swap') {
            if ($this->record->swapRelationPosition($fieldData, $srcId, $destId, $swapId)) {
                $msg = $this->cms->snippet('record.message_relation_updated');
                $this->backend->balloon('success', $msg);
            }
        }

        echo $this->_lookupPreviewCreate($srcField, $srcId) ?? '';
    }


    /**
     * Den Vorschaublock eines Lookup-Feldes rendern.
     *
     * Gemeinsamer Kern von lookupPreview() und _editForm() - das Formular baut ihn
     * beim ersten Aufbau, danach ersetzt ihn das Javascript nach jeder Änderung.
     *
     * Der Block enthält je Verknüpfung eine Zeile mit dem Titel des Zieldatensatzes
     * und einem Entfernen-Verweis, dazu versteckte Felder mit den Ids - er IST also
     * das Eingabefeld.
     *
     * Welche Datensätze in Frage kommen, ergibt sich aus dem Feldtyp: 'relation' hat
     * genau einen Zielbereich, 'pointer' mehrere.
     *
     * Je Zeile steht das Default-Feld des Zieldatensatzes - oder, wenn das Feld
     * details.lookup_detailed trägt, die ausführliche Fassung
     * (RecordBackend::detailedText()): alle Listenfelder des Zielbereichs untereinander,
     * das Default-Feld fett, dazu die Ausgabe des Listen-Templates des Bereichs.
     */
    protected function _lookupPreviewCreate(int $srcField, int $srcId): ?string {
        if ($srcField < 1 || $srcId < 1) {
            $this->cms->error('_lookupPreviewCreate() Exception: wrong parameters.');
            $this->errors();
            return null;
        }
        // Lade Daten des angegebenen Relation-Feldes
        if (!$fieldData = $this->record->fieldData($srcField)) {
            $this->cms->error('_lookupPreviewCreate() Exception: field not found.');
            $this->errors();
            return null;
        }
        $type = $fieldData['type'];
        if (str_starts_with($type, 'relation')) {
            // Ein Zielbereich
            $relAreaId = (int)$fieldData['rel_area_id'];
            if (empty($relAreaId)) return null;
            $relAreaIds = [(int)$relAreaId];
        }
        elseif (in_array($type, $this->cms->multiAreaTypes, true)) {
            // Zielbereiche (pointer, link)
            $relAreaIds = $this->record->getRelAreaIds($srcField);
            if (empty($relAreaIds)) return null;
        }
        else {
            $this->cms->error('list() Exception: src_field is not of type relation.');
            return null;
        }
        $fieldName = $fieldData['name'];

        // ausführliche Vorschau? (field.details.lookup_detailed)
        $details = json_decode((string)($fieldData['details'] ?? ''), true) ?: [];
        $detailed = !empty($details['lookup_detailed']);

        // custom_order
        $customOrder = (int)$fieldData['custom_order'];
        if ($customOrder) $sortOrder = '_custom ASC';
        else $sortOrder = 'created_by DESC';

        $this->cms->select([
            'filter' => [
                'area_id' => $relAreaIds,
            ],
            'src_id' => $srcId,
            'relation' => $fieldName,
            'sort' => $sortOrder
        ]);
        
        if ($this->db->num_rows < 1) return '';

        
        $rowTpl = $customOrder ? 'record.lookup_table_ordered_row' : 'record.lookup_table_row';

        // Für jede Zeile
        // --------------------
        $rows = [];
        $i = 0;
        $count = $this->db->num_rows;
        $data = [];
        $nextData = [];
        for ($i = 0; $i <= $this->db->num_rows; $i++) {
            $previousData = $data;
            $data = $nextData;
            if ($i < $this->db->num_rows) {
                $nextData = $this->record->fetch();
            }
            else {
                $nextData = [];
            }
            if ($i === 0) continue;

            $this->db->pushResult();

            // default field text / ausführliche Vorschau
            // --------------------
            $id = (int)$data['record_id'];
            $areaId = (int)$data['area_id'];
            if ($detailed) {
                $label = $this->detailedText($data);
            }
            else {
                $defaultField = $this->backend->getDefaultField($areaId);
                $fieldName = $defaultField['name'];
                $fieldType =  $defaultField['type'] ?? 'text';
                $label = $this->backend->backendValue(
                    $data[$fieldName] ?? '',
                    ['type' => $fieldType]
                );
            }
            // (die ausführliche Fassung ohne text-truncate - sie hat mehrere Zeilen)
            $columns = $this->backend->template($detailed ? 'record.lookup_table_column_detailed' : 'record.lookup_table_column', [
                '{text}' => $label
            ]);

            // up and down buttons
            // --------------------
            $buttons = [];
            if ($customOrder > 0) {          
                // "down"-Button
                $nextId = (int)$nextData['record_id'];
                $replace = [
                    '{down_url}' => "cApp.ui.lookupUpdate('swap', $id, $nextId);",
                    '{class}' => ''
                ];
                if (empty($nextData)) {
                    // ausblenden
                    $replace['{class}'] = ' invisible';
                }
                $buttons[] = $this->backend->template('record.lookup_button_down', $replace);

                // "up"-Button
                $previousId = (int)$previousData['record_id'];
                $replace = [
                    '{up_url}' => "cApp.ui.lookupUpdate('swap', $id, $previousId);",
                    '{class}' => ''
                ];
                if (empty($previousData)) {
                    // ausblenden
                    $replace['{class}'] = ' invisible';
                }
                $buttons[] = $this->backend->template('record.lookup_button_up', $replace);
            }
            $trClass = '';
            $rows[] = $this->backend->template($rowTpl, [
                '{data_id}' => $id,
                '{columns}' => $columns,
                '{buttons}' => implode('', $buttons),
                '{unlink_url}' => "cApp.ui.lookupUpdate('unlink', $id);",
                '{tr_class}' => $trClass
            ]);
            $this->db->popResult();
        }

        // render table
        // --------------------
        $params = "&src_field={$srcField}&src_id={$srcId}";
        $table = $this->backend->template('record.lookup_table', [
            '{table_rows}' => implode("\n", $rows),
            '{params}' => $params
        ]);

        // render preview container
        $previewContainer = $this->backend->template('record.lookup_preview_container', [
            '{html}' => $table
        ]);
        
        return $previewContainer;
    }

}