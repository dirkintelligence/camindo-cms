<?php
/**
 * Gemeinsame Basis der Backend-Klassen des Moduls Record.
 *
 * Record ist das größte Modul im Bestand, seine Oberfläche wäre in einer Klasse nicht
 * mehr lesbar. Sie ist deshalb auf neun Klassen aufgeteilt, die alle hier erben:
 *
 *     RecordBackendFolderList    Baum der Inhaltsbereiche - der Einstieg
 *     RecordBackendList          Datensatzliste eines Bereichs
 *     RecordBackendInfo          Detailansicht
 *     RecordBackendMove          Verschieben in einen anderen Bereich
 *     RecordBackendEdit          Eingabemaske, Speichern, Löschen
 *     RecordBackendSearch        Suchmaske und Suchausführung
 *     RecordBackendUpload        Datei-Upload (Ajax)
 *     RecordBackendServerFile    Dateiauswahl aus dem Serververzeichnis
 *     RecordBackendBatch         Stapelverarbeitung
 *     RecordBackendExport        Export (XML, CSV, DOCX)
 *     RecordBackendImport        Import (XML, CSV, DOCX)
 *
 * Die Klasse ist abstract: sie hat keinen eigenen Einstiegspunkt, sondern nur
 * gemeinsamen Zustand und Hilfsmethoden. Vor allem den Konstruktor - jede der
 * Unterklassen bräuchte sonst dieselbe zehnstellige Abhängigkeitsliste.
 *
 * DIE SEITENPARAMETER SIND HIER AUFWENDIGER ALS ANDERSWO
 * --------------------
 * _resolveParams() ist der wichtigste Teil dieser Klasse. Ein Aufruf im Modul Record
 * kann sich auf sehr Verschiedenes beziehen:
 *
 *     genau einen Bereich       area_id gesetzt
 *     alle Bereiche             nichts gesetzt (globale Suche)
 *     eine Auswahl an Bereichen filter[area_id][] gesetzt
 *     die Zielbereiche eines    src_field gesetzt - dann kommen die Bereiche aus der
 *     Verknüpfungsfeldes        Felddefinition, nicht aus dem Request
 *
 * Die Methode bringt all das auf einen Nenner und füllt $areaId, $areaIds,
 * $areaFilter, $areaData und $params. Jede Unterklasse ruft sie als Erstes auf.
 *
 * LOOKUP-BETRIEB
 * --------------------
 * Wie im Modul Template dient die Liste doppelt: als normale Trefferliste und als
 * Auswahlfenster für ein Verknüpfungsfeld in einem anderen Formular.
 *
 *     src_field   field_id des Verknüpfungsfeldes (hier eine Zahl, anders als in
 *                 TemplateBackend, wo es ein Feldname ist)
 *     src_id      record_id des Datensatzes, der verknüpft werden soll; 0 = Aufruf
 *                 aus einer Suchmaske
 *
 * Aus src_field ergibt sich, welche Datensätze überhaupt in Frage kommen: ein
 * 'relation'-Feld hat genau einen Zielbereich, ein 'pointer'-Feld mehrere.
 *
 * Ein Sonderfall ist das Meta-Feld `parent_id` als Lookup-Feld: gewählt wird kein
 * verknüpfter, sondern der ÜBERGEORDNETE Datensatz. Zielbereich ist dann der eigene
 * Bereich, und angeklickt wird nichts geschrieben - die Auswahl reist als
 * data[parent_id] mit dem Formular. _resolveParams() merkt sich das in $srcIsParent.
 *
 * LISTENSPALTEN UND LISTEN-TEMPLATE
 * --------------------
 * Welche Felder eines Bereichs in der Datensatzliste stehen, sagt field.visibility_list
 * (RecordPresenter::listFields()); wie ein Wert dort aussieht, RecordPresenter::
 * listValue() - Status als Symbol, template_id/parent_id/position als Klartext, alles
 * andere über Backend::backendValue(). Beides nutzt neben RecordBackendList (und der
 * API, RecordApi::search()) auch detailedText(),
 * das dieselben Felder untereinander zeigt - in der ausführlichen Lookup-Vorschau
 * (RecordBackendEdit) und in der Liste der zuletzt geänderten Datensätze
 * (RecordBackendFolderList).
 *
 * Dazu kann je Bereich ein Frontend-Template gewählt sein (`area`.`list_template_id`),
 * das RecordPresenter::listTemplateHtml() für jeden Datensatz ausführt (Page::renderRecord()) - als
 * letzte Spalte der Liste bzw. als Abschluss der Lookup-Vorschau.
 *
 * DIE AUSWAHLLISTEN SIND BEGRENZT
 * --------------------
 * _parentOptions() und _previousOptions() bauen Auswahllisten über die Datensätze
 * eines Bereichs - das können sehr viele sein. Beide holen deshalb höchstens
 * admin.list_max_datasets Einträge (Standard 200) und hängen bei mehr einen
 * deaktivierten "…"-Eintrag an, statt die Liste stillschweigend abzuschneiden.
 *
 * @see Camindo\Core\Modules\Record\Record  Dataset/Model
 * @see Camindo\Core\Backend::areaData()     liefert die Angaben zu Default- und Sortierfeld
 * @see docs/developers/03-backend-entwicklung.md
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\InstanceCache;
use Camindo\Core\Config;
use Camindo\Core\Backend;
use Camindo\Core\Crypt;
use Camindo\Core\Session;
use Camindo\Core\Database;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\MassDeletion;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Record\Record;

abstract class RecordBackend {
    protected int $id = 0;

    // Genau EIN Bereich - gesetzt, wenn der Aufruf sich eindeutig auf einen bezieht.
    protected int $areaId = 0;

    protected int $srcId = 0;       // lookup call
    protected int $srcField = 0;    // lookup call
    protected int $straight = 0;    // lookup call

    // Lookup-Aufruf aus einem FREMDEN Formular: src_field ist dann kein field_id,
    // sondern der Name einer Spalte, die eine record_id aufnimmt - z. B.
    // `instance`.`record_id` (die Startseite einer Website). Es gibt dazu keine
    // Felddefinition, aus der sich Zielbereiche ableiten ließen; welche Datensätze
    // in Frage kommen, grenzt der Aufrufer über die normalen Filter ein
    // (InstanceBackend: filter[instance_id]).
    protected string $srcFieldName = '';

    // Lookup-Aufruf für das Meta-Feld parent_id: gewählt wird der Vaterdatensatz,
    // nicht eine Verknüpfung. Zielbereich ist dann der eigene Bereich, und es wird
    // beim Anklicken nichts geschrieben (siehe RecordBackendEdit).
    protected bool $srcIsParent = false;

    // Zielbereiche eines 'pointer'-Feldes (mehrere möglich)
    protected array $areaIds = [];  // relAreaIds of a pointer field

    // Bereichs-Einschränkung der Suche; bei einem Pointer-Aufruf die Schnittmenge
    // aus Sucheingabe und den Zielbereichen des Feldes.
    protected array $areaFilter = [];

    // Die Parameter, die an jeden weiterführenden Link angehängt werden müssen, damit
    // der nächste Layer den Zusammenhang kennt (Bereich, Lookup-Aufruf).
    protected string $params = '';

    protected ?array $data = [];
    protected ?array $areaData = [];
    protected string $function = '';

    // wenn es um EINEN Bereich MIT parent_id-Eingabefeld geht, z.B. Datensatz-Liste / -Info
    protected bool $hasParentId = false;

    public function __construct(
        protected Config $config,
        protected Backend $backend,
        protected Crypt $crypt,
        protected Session $session,
        protected Database $db,
        protected System $system,
        protected Cms $cms,
        protected MassDeletion $massDeletion,
        protected Area $area,
        protected Record $record,
        protected InstanceCache $instanceCache,
        protected RecordService $service,
        protected RecordPresenter $presenter,
    )
    {}


    /**
     * Überschrift erzeugen - abhängig von $this->function.
     *
     * In der Detailansicht und beim Löschen steht der Datensatz selbst darüber. Einen
     * "Titel" hat ein Datensatz aber nicht von Haus aus: welches Feld dafür gilt, legt
     * der Bereich mit default_field_id fest. Deshalb erst das Default-Feld holen und
     * dann dessen Inhalt einsetzen; ist keins bestimmt, bleibt die record_id.
     *
     * Der Inhalt kommt aus einem Eingabefeld und wird maskiert.
     *
     * @param bool $displayIcon  true: Status-Icon voranstellen
     */
    protected function headline(bool $displayIcon = false) {
        $icon = '';
        if (in_array($this->function, ['delete', 'info'])) {
            // Default-Feld gesondert behandeln
            $defaultField = $this->backend->getDefaultField($this->areaId);
            $defaultFieldName = $defaultField['name'];
            $label = htmlspecialchars($this->data[$defaultFieldName] ?? $this->data['record_id']);
            if ($displayIcon) {
                $statusId = (int)($this->data['status_id'] ?? 0);
                $icon = $this->backend->teaser('status', $statusId, icon:true, label:false) . ' ';
            }
        }
        else {
            $label = $this->cms->snippet('record.headline_record_info');
        }
        return $this->backend->template('headline1', [
            '{text}' => $icon . $label,
        ]);
    }

    // ====================
    // Zusatzrechte des Moduls (install/settings.php 'access', 12-group-rights.md)

    /**
     * Export-/Importformate, die die Gruppe nutzen darf (RecordService::transferTypes()).
     *
     * @param string $direction  'export' | 'import'
     * @return string[]  Teilmenge von RecordExport::TYPES in deren Reihenfolge
     */
    protected function allowedTypes(string $direction): array
    {
        return $this->service->transferTypes($direction);
    }

    /**
     * Medien-Import in den Bereich dieser Seite (RecordBackendMediaImport)? Zusatzrecht
     * import_media, Recht zum Anlegen, ein Upload-Feld (ohne Thumbnails) - und kein
     * abhängiger Bereich (area.dependent: dessen Datensätze entstehen nur über ein
     * Feld vom Typ link).
     */
    protected function mediaImportAllowed(): bool
    {
        if ($this->areaId < 1 || (int)($this->areaData['dependent'] ?? 0) === 1) return false;
        $access = $this->backend->access();
        if (!$access->moduleRight('Record', 'import_media') || !$access->areaCreate($this->areaId)) return false;
        foreach ($this->record->virtualSchema($this->areaId, false) as $colDef) {
            if ((string)($colDef['type'] ?? '') === 'file' && empty($colDef['parent_field_id'])) return true;
        }
        return false;
    }

    /**
     * Darf die Gruppe die Stapelverarbeitung nutzen (Zusatzrecht batch)?
     */
    protected function batchAllowed(): bool
    {
        return $this->backend->access()->moduleRight('Record', 'batch');
    }

    /**
     * Fehlermeldungen aus Cms::errors als roten Kasten ausgeben.
     */
    protected function errors(): void
    {
        echo $this->backend->template('alert_danger',[
            '{text}' => $this->cms->errorMsg()
        ]);
    }

    /**
     * Die Seitenparameter einlesen und auf einen Nenner bringen.
     *
     * Wird von jeder Unterklasse als Erstes aufgerufen und füllt $id, $areaId,
     * $areaIds, $areaFilter, $srcField, $srcId, $areaData und $params.
     *
     * Der Reihe nach:
     *
     *  1. Id und Bereich aus dem Request. Die area_id darf sowohl direkt als auch als
     *     data[area_id] kommen - das Eingabeformular schickt sie im data-Block mit.
     *     Ist eine Datensatz-Id angegeben, gilt der Bereich des Datensatzes.
     *  2. Bei einem Lookup-Aufruf (src_field) gewinnt die FELDDEFINITION über den
     *     Request: ein 'relation'-Feld hat genau einen Zielbereich, ein 'pointer'-Feld
     *     mehrere. Der Redakteur soll aus einem Verknüpfungsfeld heraus nichts
     *     auswählen können, was das Feld gar nicht aufnehmen kann.
     *  3. Bereichsangaben laden - über Backend::areaData(), nicht über Area::load():
     *     die Methode liefert zusätzlich Name und Typ des Default-Feldes sowie das
     *     Sortierfeld, die weiter unten für die Auswahllisten gebraucht werden.
     *  4. Ohne eindeutigen Bereich wird eine Ersatz-Beschriftung gesetzt ("Alle
     *     Inhaltsbereiche" bzw. "{n} Bereiche").
     *  5. $params zusammenstellen - siehe Property-Kommentar.
     *
     * Bei einem Pointer-Feld MIT Sucheinschränkung wird die Schnittmenge gebildet: es
     * gilt beides, die Zielbereiche des Feldes und die Auswahl des Redakteurs.
     *
     * Schlägt etwas fehl, wird $this->function auf 'errors' gesetzt - die aufrufende
     * Methode muss das prüfen.
     */
    protected function _resolveParams(): void
    {
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->areaId = (int)($_REQUEST['data']['area_id'] ?? $_REQUEST['area_id'] ?? 0);

        // lookup calls:
        // src_field ist entweder eine field_id (Verknüpfungsfeld eines Bereichs) oder
        // ein Spaltenname aus einem fremden Formular - siehe $srcFieldName.
        $srcFieldRaw = trim((string)($_REQUEST['src_field'] ?? ''));
        if ($srcFieldRaw !== '' && !ctype_digit($srcFieldRaw)) {
            $this->srcFieldName = preg_replace('/[^a-z0-9_]/', '', strtolower($srcFieldRaw));
            $this->srcField = 0;
        }
        else {
            $this->srcField = (int)$srcFieldRaw;
            $this->srcFieldName = '';
        }
        $this->srcId    = (int)($_REQUEST['src_id'] ?? 0);

        // Indikator ob "Create"-Funktion direkt aus Lookup heraus aufgerufen wurde
        $this->straight = (int)($_REQUEST['straight'] ?? 0);

        // $filter[area_ids][]
        $this->areaFilter = $this->system->sanitizeIds(
            (array)($_REQUEST['filter']['area_id'] ?? [])
        );
        $_REQUEST['filter']['area_id'] = $this->areaFilter;

        // Relation- / Pointer-feld angegeben -> Zielbereich(e) ermitteln
        // --------------------
        $this->areaIds = [];
        $this->srcIsParent = false;
        if (!empty($this->srcField)) {
            $srcFieldData = $this->record->fieldData($this->srcField);
            $type = $srcFieldData['type'];
            if (($srcFieldData['name'] ?? '') === 'parent_id' && $type === 'id') {
                // Auswahl des Vaterdatensatzes: die Kandidaten stehen im selben
                // Bereich wie der Datensatz, der bearbeitet wird
                $this->srcIsParent = true;
                $this->areaId = (int)$srcFieldData['area_id'];
            }
            elseif (str_starts_with($type, 'relation')) {
                // Ein Zielbereich
                $this->areaId = (int)$srcFieldData['rel_area_id'];
            }
            elseif (in_array($type, $this->cms->multiAreaTypes, true)) {
                // Zielbereiche (pointer, link)
                $this->areaIds = $this->record->getRelAreaIds($this->srcField);
                // Genau einer?
                if (count($this->areaIds) === 1) $this->areaId = (int)$this->areaIds[0];
            }
            else {
                $this->cms->error('list() Exception: src_field is not of type relation.');
                $this->function = 'errors';
                return;
            }
        }

        // Datensatz angegeben: sein Bereich gilt, nicht der Request-Parameter area_id -
        // Verweise auf die Detailansicht (Backend::teaser(), "Verwendet von" …) kennen
        // oft nur die Id; ohne Bereichsdaten fehlten z. B. die Sprach-Reiter
        if ($this->id > 0) {
            $this->db->pushResult();
            $this->db->query("SELECT `area_id` FROM `record` WHERE `record_id` = ?", 'i', [$this->id]);
            $recordAreaId = (int)($this->db->fetchNum()[0] ?? 0);
            $this->db->popResult();
            if ($recordAreaId > 0) $this->areaId = $recordAreaId;
        }

        // Bereichslabel ermitteln
        // --------------------
        $this->areaData = [];
        if ($this->areaId > 0) {
            // Ein Bereich -> Daten laden
            // Backend::areaData() liefert zusätzliche Infos zum Default-Feld und Sortierfeld
            if (!$this->areaData = $this->backend->areaData($this->areaId)) {
                // (Fehlermeldungen in $this->cms->errors)
                $this->function = 'errors';
                return;
            }
        }
        else {
            if (!empty($this->areaIds)) {
                // Mehrere Ziel-Bereiche aus Pointer-Feld
                // -> Schnittmenge mit areaFilter bilden
                if (empty($this->areaFilter)) {
                    // (kein areaFilter angegeben -> nur areaIds)
                    $this->areaFilter = $this->areaIds;
                }
                else {
                    // Schnittmenge
                    $this->areaFilter = array_intersect($this->areaFilter, $this->areaIds);
                }
            }
            if (empty($this->areaFilter)) {
                // "alle Bereiche"
                $this->areaData = ['label' => '#area.all_content_areas'];
            }
            else {
                // "{count} Bereiche"
                $this->areaData = [
                    'label' => $this->cms->snippet('area.x_areas', ['count' => count($this->areaFilter) ])
                ];
            }
        }

        // Weiterzureichende Seitenparameter
        // --------------------
        if ($this->areaId > 0) {
            $this->params = 'area_id=' . $this->areaId;
        }
        if ($this->hasLookup()) {
            $this->params .= '&src_field=' . ($this->srcFieldName !== '' ? $this->srcFieldName : $this->srcField);
            $this->params .= '&src_id=' . $this->srcId;
        }
    }

    /**
     * Ajax: Vorschau eines FREMDEN Datensatz-Lookups aktualisieren
     * (backend/ajax_ext_lookup.php).
     *
     * Gegenstück zu TemplateBackend::lookupPreview(), nur für Datensätze: aufgerufen
     * von cApp.ui.slookupUpdate(), nachdem in der Datensatzliste ein Häkchen gesetzt
     * oder entfernt wurde. Geschrieben wird nichts - die Antwort enthält das
     * versteckte Feld, das mit dem aufrufenden Formular abgeht.
     *
     * Request: src_field (Spaltenname, Pflicht), ids (Kommaliste), max, function,
     *          prefix ('filter' für die Suchmaske, sonst 'data')
     */
    public function extLookupPreview(): void
    {
        $srcField = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_REQUEST['src_field'] ?? '')));
        $max      = (int)($_REQUEST['max'] ?? 0);
        if ($srcField === '') {
            $this->cms->error('extLookupPreview() Exception: parameter src_field is empty.');
            $this->errors();
            return;
        }
        $idString = preg_replace('/[^0-9,]/', '', (string)($_REQUEST['ids'] ?? ''));
        $ids = explode(',', $idString);

        // prefix=filter: Suchfeld der Suchmaske (filter[...]), sonst Formularfeld
        $prefix = ((string)($_REQUEST['prefix'] ?? '') === 'filter') ? 'filter' : 'data';
        echo $this->extLookupPreviewCreate($srcField, $ids, $prefix, $max);

        if ((string)($_REQUEST['function'] ?? '') === 'link') {
            $this->backend->script("cApp.ui.lookupFocus('$srcField', true);");
        }
    }

    /**
     * Lookup auf Profil-Datensätze (Website-User) aus einem fremden Formular: die
     * Ersetzungen für die Vorlage edit_form_lookup_foreign_table. Gesucht wird in der
     * Datensatzliste mit src_field = $srcField und einem Filter auf die Profil-Bereiche;
     * die Liste reicht src_field als Spaltennamen weiter (auch durch ihre Suchmaske),
     * "Übernehmen" schreibt über ajax_ext_lookup.php den Vorschaublock zurück.
     * Genutzt von der Suchmaske (Metafeld user_id) und vom Modul Datenschutz
     * (PrivacyBackend::profilePickerHtml()).
     *
     * @param string $srcField  Spaltenname im aufrufenden Formular ([a-z0-9_])
     * @param string $prefix    'data' (Eingabeformular) oder 'filter' (Suchmaske)
     * @param int[]  $ids       gewählte Profile
     * @param int    $max       1 = Einfachauswahl, 0 = beliebig viele
     * @param int[]  $areaIds   Profil-Bereiche, in denen gesucht wird
     * @return array            Platzhalter => Wert ({src_id}, {src_field},
     *                          {search_url}, {update_url}, {data_ids}, {max}, {preview})
     */
    public function profileLookupReplace(string $srcField, string $prefix, array $ids, int $max, array $areaIds): array
    {
        $srcField = preg_replace('/[^a-z0-9_]/', '', strtolower($srcField));
        $prefix = $prefix === 'filter' ? 'filter' : 'data';
        $ids = $this->system->sanitizeIds($ids, true);
        $areaParams = '';
        foreach ($areaIds ?: [0] as $areaId) {
            $areaParams .= '&filter[area_id][]=' . (int)$areaId;
        }
        return [
            '{src_id}' => '0',
            '{src_field}' => $srcField,
            '{search_url}' => 'Record/record_list.php?src_field=' . $srcField . $areaParams,
            '{update_url}' => 'Record/ajax_ext_lookup.php?src_field=' . $srcField . '&prefix=' . $prefix,
            '{data_ids}' => implode(',', $ids),
            '{max}' => $max > 0 ? (string)$max : '',
            '{preview}' => $this->extLookupPreviewCreate($srcField, $ids, $prefix, $max),
        ];
    }

    /** Profil-Bereiche, die die Gruppe lesen darf (ohne gelöschte), in Baumreihenfolge */
    public function readableProfileAreaIds(): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `area_id` FROM `area` WHERE `type` = 'profile' AND `deleted` = 0"
                . $this->backend->areaAccessSql() . " ORDER BY `lft`");
            // eine Spalte: fetchColumn() liefert die Werte als Liste
            return array_map('intval', $this->db->fetchColumn());
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Den Vorschaublock eines fremden Datensatz-Lookups rendern.
     *
     * Gemeinsamer Kern von extLookupPreview() und dem aufrufenden Formular. Je
     * gewähltem Datensatz entsteht eine Zeile mit Beschriftung, Entfernen-Verweis und
     * einem versteckten Feld, das die Id trägt - der Vorschaublock IST das
     * Eingabefeld.
     *
     * Beschriftet wird mit dem Default-Feld des Bereichs, in dem der Datensatz liegt
     * (Backend::getDefaultField()); ohne Inhalt bleibt die Id als Notnagel.
     *
     * @param string $srcField  Name der Spalte im aufrufenden Formular (z. B. 'record_id')
     * @param array  $ids       gewählte record_ids
     * @param string $prefix    Name des Formularfeldes ('data'/'filter')
     * @param int    $max       1 = Einfachauswahl (kein [] im Feldnamen)
     */
    public function extLookupPreviewCreate(string $srcField, array $ids, string $prefix, int $max): string
    {
        if ($srcField === '') return '';
        $ids = $this->system->sanitizeIds($ids, true);
        if (empty($ids)) return '';
        if ($max === 1) $ids = [reset($ids)];

        $this->db->pushResult();
        $this->cms->select([
            'filter' => ['record_id' => $ids],
            'limit'  => count($ids)
        ]);
        $records = [];
        while ($row = $this->cms->fetch()) {
            $records[(int)$row['record_id']] = $row;
        }
        $this->db->popResult();
        if (empty($records)) return '';

        // Reihenfolge der übergebenen Ids erhalten - der Redakteur hat sie so gewählt
        $rank = array_flip($ids);
        uksort($records, fn($a, $b) => ((int)($rank[$a] ?? 0)) <=> ((int)($rank[$b] ?? 0)));

        // Feldname im Formular: bei Mehrfachauswahl als Array
        $varName = "{$prefix}[{$srcField}]" . ($max === 1 ? '' : '[]');

        $rows = [];
        foreach ($records as $recordId => $data) {
            $this->db->pushResult();
            $defaultField = $this->backend->getDefaultField((int)($data['area_id'] ?? 0));
            $label = $this->backend->backendValue(
                $data[$defaultField['name']] ?? '',
                ['type' => $defaultField['type'] ?? 'text']
            );
            $this->db->popResult();

            $rows[] = $this->backend->template('record.slookup_table_row', [
                '{data_id}'    => $recordId,
                '{text}'       => $label,
                '{var_name}'   => $varName,
                '{unlink_url}' => "cApp.ui.slookupUnlink($recordId);"
            ]);
        }

        return $this->backend->template('record.lookup_preview_container', [
            '{html}' => $this->backend->template('record.slookup_table', [
                '{table_rows}' => implode("\n", $rows)
            ])
        ]);
    }

    /**
     * Ist der Aufruf ein Lookup - egal ob über field_id oder über einen Spaltennamen?
     */
    protected function hasLookup(): bool
    {
        return ($this->srcField > 0 || $this->srcFieldName !== '');
    }

    /**
     * src_field als JavaScript-Argument für die cApp.ui.*lookup*-Aufrufe.
     * Eine field_id geht als Zahl durch, ein Spaltenname muss in Anführungszeichen.
     */
    protected function srcFieldJs(): string
    {
        return ($this->srcFieldName !== '')
            ? "'" . $this->srcFieldName . "'"
            : (string)$this->srcField;
    }


    /**
     * Auswahlliste der Status (id => Beschriftung).
     *
     * Verwendet in Eingabemaske UND Suchmaske. Database::fetchPairs() liefert die
     * zweispaltige Abfrage direkt als Schlüssel-Wert-Array, das array_map() löst darin
     * noch #-Snippets auf.
     *
     * @param bool $settableOnly  nur die Status, die die Gruppe setzen darf
     *                            (Access::statusWrite()) - Eingabemaske und
     *                            Stapelverarbeitung; die Suche bietet alle
     */
    protected function _statusOptions(bool $settableOnly = false): array
    {
        $this->db->query("SELECT `status_id`,`label` FROM `status` ORDER BY `position` ASC");
        $options = array_map(
            fn(string $value) => $this->cms->textOrSnippet($value),
            $this->db->fetchPairs()
        );
        if (!$settableOnly) return $options;
        $access = $this->backend->access();
        return array_filter($options, fn($statusId) => $access->statusWrite((int)$statusId), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Auswahlliste der Templates, die im Bereich benutzt werden dürfen.
     *
     * Nicht alle Templates, sondern nur die in `rel_area_template` als 'selectable'
     * eingetragenen (gepflegt über TemplateBackend::infoAccess()). Das
     * Default-Template des Bereichs ist immer dabei - Area::hookOnSaved() setzt bei ihm
     * selectable stets mit.
     *
     * Die erste Option ist leer und bedeutet "Vorgabe des Bereichs benutzen".
     */
    protected function _templateOptions(): array
    {
        // erste Option: "default"
        $options = ['' => $this->cms->snippet('record.default_template_label')];
        $sql = "SELECT `template`.`template_id`, `template`.`label` FROM `rel_area_template` `r`"
        ." INNER JOIN `template` ON `r`.`template_id` = `template`.`template_id`"
        ." WHERE `r`.`area_id` = ? AND `r`.`selectable` = 1"
        ." ORDER BY `template`.`label` ASC";
        $this->db->query($sql, 'i', [$this->areaId]);
        $assigendOptions = $this->db->fetchPairs();
        // Das Default-Template nicht nochtmal listen
        $defaultTemplateId = (int)($this->areaData['default_template_id'] ?? 0);
        unset($assigendOptions[$defaultTemplateId]);
        return $options + $assigendOptions;
    }

    /**
     * Auswahlliste "übergeordneter Datensatz".
     *
     * Alle Datensätze des Bereichs außer dem eigenen. Führt der Bereich einen Baum
     * (Eingabefeld `parent_id`, Record::isTreeArea()), steht die Liste in
     * Baumreihenfolge (`lft`) und jeder Titel ist nach `depth` eingerückt - mit
     * geschützten Leerzeichen und dem Ast-Zeichen, wie die Datensatzliste
     * (RecordBackendList::list(), table_column_tree_x): gewöhnliche Leerzeichen
     * fielen in einer <option> zusammen.
     *
     * '!!' vor der record_id ist die Filter-Syntax für "ungleich" (siehe
     * docs/developers/05-select-und-filter.md). Ausgeschlossen wird dabei origin_id, falls
     * vorhanden - beim Bearbeiten arbeitet man auf einer Arbeitskopie, gemeint ist aber
     * das Original.
     *
     * Sortiert wird sonst nach dem Sortierfeld des Bereichs, angezeigt der Inhalt
     * seines Default-Feldes - beides steht dank Backend::areaData() bereits in
     * $areaData.
     *
     * Bei mehr als admin.list_max_datasets Treffern (Standard 200) wird die Liste
     * abgeschnitten und bekommt einen deaktivierten "…"-Eintrag angehängt: eine
     * Auswahlliste mit tausenden Einträgen wäre ohnehin unbenutzbar. Deshalb wird
     * limit+1 abgefragt - nur so lässt sich erkennen, ob es mehr gibt.
     */
    protected function _parentOptions(): array
    {
        // gibts nur wenn areaId defniert ist
        if (empty($this->areaId)) return [];

        $limit = $this->config->get('admin.list_max_datasets') ?? 200;

        // $this->areaData wurde mit Backend::areaData() geladen und enthält
        // daher schon name und typ des Default-Feldes, sowie den Namen des Sortierfeldes
        $defaultFieldName = (string)$this->areaData['default_field_name'];
        $defaultFieldType = (string)$this->areaData['default_field_type'];
        $sortFieldName = (string)$this->areaData['sort_field_name'];
        $sortOrder = $this->areaData['sort_order'] ?? 'DESC';
        // Baum: in Baumreihenfolge, eingerückt (siehe Methodenkommentar)
        $isTree = $this->record->isTreeArea($this->areaId);
        if ($isTree) {
            $sortFieldName = 'lft';
            $sortOrder = 'ASC';
        }
        $this->cms->select([
            'filter' => [
                'area_id' => $this->areaId,
                'record_id' => '!!' . ($this->data['origin_id'] ?: $this->id)
            ],
            'sort' => $sortFieldName,
            'order' => $sortOrder,
            'limit' => $limit + 1,
        ]);
        $numRows = $this->db->num_rows;

        $options = ['' => '---'];
        while ($data = $this->cms->fetch()) {
            $label = $this->backend->backendValue(
                $data[$defaultFieldName] ?? '',
                ['type' => $defaultFieldType]
            );
            $depth = $isTree ? (int)($data['depth'] ?? 0) : 0;
            $indent = $depth > 0 ? str_repeat('&nbsp;&nbsp;&nbsp;', $depth) . '└ ' : '';
            $options[$data['record_id']] = $indent . $label;
        }
        if ($numRows > $limit) {
            $options['disabled'] = $this->cms->snippet('ellipsis_spoken');
        }
        return $options;
    }

    /**
     * Auswahlliste "vorangehender Datensatz" - die Eingabehilfe für `position`.
     *
     * Der Redakteur wählt den Vorgänger, gespeichert wird daraus die Position
     * (TreeManagerTrait::calculatePosition()). Der erste Eintrag bedeutet
     * "an den Anfang".
     *
     * Gelistet werden ausschließlich die GESCHWISTER unter $parentId - also die
     * direkten Kinder, nicht der ganze Zweig: 'depth' => 1 ist der Modifikator zum
     * parent_id-Filter (docs/developers/05-select-und-filter.md). $parentId = 0 meint
     * die Wurzelebene des Bereichs; der Filterwert dafür ist 'null', das
     * Cms::buildParentCondition() als "parent_id IS NULL" liest.
     *
     * Sortiert wird nach `position ASC` und nicht nach dem Sortierfeld des Bereichs:
     * die Liste bildet die Reihenfolge ab, in die sich der Datensatz einreiht - jede
     * andere Sortierung wäre hier sinnlos.
     *
     * Ansonsten wie _parentOptions(): eigener Datensatz ausgeschlossen ('!!'),
     * Beschriftung aus dem Default-Feld des Bereichs, Begrenzung auf
     * admin.list_max_datasets mit "…"-Eintrag.
     *
     * @param int $parentId  Übergeordneter Datensatz oder 0 (Wurzelebene)
     */
    protected function _previousOptions(int $parentId = 0): array
    {
        // gibts nur wenn areaId defniert ist
        if (empty($this->areaId)) return [];

        $limit = $this->config->get('admin.list_max_datasets') ?? 200;

        // $this->areaData wurde mit Backend::areaData() geladen und enthält
        // daher schon name und typ des Default-Feldes
        $defaultFieldName = (string)$this->areaData['default_field_name'];
        $defaultFieldType = (string)$this->areaData['default_field_type'];
        $filter = [
            'area_id' => $this->areaId,
            // Beim Bearbeiten ist $this->id die Arbeitskopie - gemeint ist das Original
            'record_id' => '!!' . (($this->data['origin_id'] ?? 0) ?: $this->id),
            // 'null' = Wurzelebene (parent_id IS NULL); ein leerer String taugt
            // dafür nicht, den wirft Cms::normalizeValues() weg
            'parent_id' => $parentId > 0 ? $parentId : 'null',
            // nur direkte Kinder, nicht der ganze Zweig
            'depth' => 1
        ];
        $this->cms->select([
            'filter' => $filter,
            'sort' => 'position',
            'order' => 'ASC',
            'limit' => $limit + 1
        ]);
        $numRows = $this->db->num_rows;

        $options = ['' => $this->cms->snippet('position_at_start')];
        while ($data = $this->cms->fetch()) {
            $label = $this->backend->backendValue(
                $data[$defaultFieldName] ?? '',
                ['type' => $defaultFieldType]
            );
            $options[$data['record_id']] = $label;
        }
        if ($numRows > $limit) {
            $options['disabled'] = $this->cms->snippet('ellipsis_spoken');
        }
        return $options;
    }

    /**
     * Status als Einträge für ein Auswahlmenü, wahlweise mit Symbol.
     *
     * Anders als _statusOptions() liefert diese Methode fertiges HTML (Symbol in der
     * Statusfarbe plus Beschriftung) - gebraucht für das Schnellfilter-Menü im
     * Spaltenkopf der Liste.
     *
     * pushResult()/popResult() klammern die Abfrage: die Methode wird mitten in einer
     * laufenden Ergebnisschleife aufgerufen und würde deren Ergebnis sonst
     * überschreiben.
     */
    protected function _statusDropdownItems(bool $icon = false): array {
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `status` ORDER BY `position` ASC");
        $items = [];
        while ($status = $this->db->fetch()) {
            // status Icon + Label
            if ($icon) {
                $iconHtml = $this->backend->template('status.icon_status', [
                    '{icon}' => $status['icon'],
                    '{color_class}' => $status['color_class']
                ])
                . ' ';
            }
            else {
                $iconHtml = '';
            }
            $label = $iconHtml . $this->cms->textOrSnippet($status['label']);
            $items[$status['status_id']] = $label;
        }
        $this->db->popResult();
        return $items;
    }

    /**
     * Ausführliche Darstellung eines Datensatzes - in der Lookup-Vorschau
     * (details.lookup_detailed, RecordBackendEdit) und in der Liste der zuletzt
     * geänderten Datensätze (RecordBackendFolderList): die Listenfelder seines Bereichs untereinander (RecordPresenter::listFields() /
     * listValue(), dieselben wie in der Datensatzliste, gekürzt), das Default-Feld
     * fett; danach die Ausgabe des Listen-Templates des Bereichs, falls eines
     * gewählt ist (listTemplateHtml()). Ab dem zweiten Eintrag mit Abstand
     * (record.lookup_table_row_div).
     *
     * Bei Pointer-Feldern und in der Liste der zuletzt geänderten kann jeder
     * Datensatz aus einem anderen Bereich stammen - das Schema wird deshalb je
     * Bereich einmal geholt und für den Aufruf gemerkt.
     *
     * Der Aufrufer klammert mit pushResult()/popResult() (siehe listValue()).
     *
     * @param array $data  Datensatz (Record::fetch())
     */
    protected function detailedText(array $data): string
    {
        static $schemas = [];
        $areaId = (int)($data['area_id'] ?? 0);
        if (!isset($schemas[$areaId])) {
            $schemas[$areaId] = $this->record->virtualSchema($areaId, false);
        }
        $schemaVirtual = &$schemas[$areaId];

        $items = [];
        foreach ($this->presenter->listFields($schemaVirtual) as $name) {
            $value = $this->presenter->listValue($name, $data, $schemaVirtual);
            if ((int)($schemaVirtual[$name]['default_field'] ?? 0) === 1) {
                $value = '<strong>' . $value . '</strong>';
            }
            $items[] = $value;
        }
        $templateHtml = $this->presenter->listTemplateHtml($areaId, $data);
        if ($templateHtml !== '') $items[] = $templateHtml;

        $html = '';
        foreach ($items as $i => $item) {
            $html .= ($i === 0)
                ? '<div>' . $item . '</div>'
                : $this->backend->template('record.lookup_table_row_div', ['{text}' => $item]);
        }
        return $html;
    }

    /**
     * Zeigt der Bereich mehrsprachige Felder auf Reitern? (area.details.tabs)
     */
    protected function _languageTabs(): bool
    {
        $details = $this->areaData['details'] ?? [];
        if (is_string($details)) $details = json_decode($details, true) ?: [];
        return (int)($details['tabs'] ?? 0) === 1;
    }

    /**
     * Sprachen der Instanz, zu der der Bereich gehört (Hauptsprache zuerst).
     *
     * @return array  language_id => Sprachzeile (InstanceCache::languages())
     */
    protected function _recordLanguages(): array
    {
        $instanceId = (int)($this->areaData['instance_id'] ?? 0);
        if ($instanceId < 1 && $this->areaId > 0) {
            $areaData = $this->backend->areaData($this->areaId) ?? [];
            $instanceId = (int)($areaData['instance_id'] ?? 0);
        }
        return $this->instanceCache->languages($instanceId);
    }
}