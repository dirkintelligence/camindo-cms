<?php
/**
 * Datenbank-Tabellen-Abstraktion
 */
namespace Camindo\Core;

use Camindo\Core\MediaProcessor;
use Camindo\Core\ProcessorFactory;
use Camindo\Core\LazyServicesTrait;
use Camindo\Core\InstanceCache;
use Camindo\Core\PasswordPolicy;

abstract class Dataset {

    // Nachladbare Dienste: $lazyServices in der Kindklasse füllen, Zugriff über $this->{name}
    // --------------------
    use LazyServicesTrait;

    // per Factory geladene Klassen
    // --------------------
    protected ?MediaProcessor $processor = null;

    // Tabellen-Eigenschaften, werden in hookDefinition() gesetzt
    // --------------------
    protected string $moduleName = '';           // wird von hookDefinition gesetzt
    protected string $tableName = '';            // ebenfalls
    protected string $defaultSortOrder = '';     // ebenfalls
    protected string $defaultFieldName = 'name'; // ebenfalls
    protected array  $schema = [];               // ebenfalls
    /**
     * Script-Injektionen aus Texteingaben löschen (Cms::_sanitize_html() in
     * validateValue(): <script>, Event-Handler, javascript:-URLs). HTML-Formatierung
     * bleibt erlaubt. Nur das Modul Template schaltet das ab - dort ist Script Inhalt.
     * Gilt für alle Speicherwege, also auch für Frontend-Eingaben (RecordUser).
     */
    protected bool $sanitizeHtml = true;
    /* e.g.
    $this->schema = [
        'record_id' => [
            'type'  => 'integer',
            'display_type' => 'text',
            'label' => '#area.field_record_id',
        ], ...
    */

    // Schema-Flags: werden nach hookDefinition() einmalig gesetzt und gecacht.
    // --------------------
    protected bool $hasParentId = false; // parent_id + position + rgt + lft + depth
    protected bool $hasDeleted = false;  // temporär löschbar
    protected bool $hasTmp = false;      // tmp (+ deleted + changed_by + changed_at)
    protected bool $hasAreaId = false;   // area_id vorhanden - aber nicht tabelle area selbst!
    protected bool $hasChangedAt = false; // changed_at vorhanden (Schema-Feld oder hasTmp-DB-Feld)
    protected bool $hasChangedBy = false; // changed_by vorhanden (Schema-Feld oder hasTmp-DB-Feld)
    protected string $dateExpression = '/(now|today)(?:([+\-][0-9]+)([smhd]))?/ui';

    // Felder, deren Werte in save() bereits im DATENBANKFORMAT vorliegen (Cent bei
    // currency, UTC-ISO bei timestampl, Dezimalpunkt bei float): validateValue()
    // lässt bei ihnen die Umwandlung aus dem Eingabeformat aus und prüft nur die
    // Regeln (not_empty, min/max, pattern, unique). Gesetzt wird die Liste vom
    // Aufrufer für die Dauer EINES save()-Aufrufs - siehe MoveTrait::moveToArea(),
    // das die Inhalte eines Datensatzes unverändert in die Felder eines anderen
    // Bereichs übernimmt und dort gegen dessen Felddefinitionen prüfen lässt.
    protected array $storedFormatFields = [];
    // Passwort-Felder, deren Wert in $data bereits der Hash ist (aus dem Arbeitsstand
    // der Arbeitskopie, siehe save()) - saveVirtualFields() hasht sie nicht erneut
    protected array $prehashedFields = [];
    // true: Anzahl-Prüfung (min/max, not_empty) der Verknüpfungsfelder auslassen -
    // der Import setzt die Verknüpfungen erst im zweiten Durchlauf, wenn die Ids der
    // Zieldatensätze feststehen, und speichert dann erneut MIT Prüfung
    // (Record::importSave()). Gilt für EINEN save()-Aufruf.
    protected bool $deferRelationChecks = false;
    // true, solange save() mit $stayTemporary läuft (Einzelschritt: Reiterwechsel,
    // Simulation) - für Hooks, die nur beim endgültigen Speichern etwas tun dürfen
    // (Record::hookOnSave(): Bereichswechsel räumt die alten Felder ab)
    protected bool $savingTemporary = false;
    // Arbeitskopie, an der während save() die Verknüpfungen des Datensatzes hängen -
    // bis zum checkIn (danach 0). Für die Trigger-Templates: $cms['record_id'] in
    // onSave / onSaved beim Zwischenspeichern (TriggerTemplates). Verschachtelte
    // save()-Aufrufe (Trigger, eingebettete Inhalte) stellen den Wert wieder her.
    protected int $savingWorkingCopyId = 0;
    // true während einer Aktion einer Lösch-Policy (asPolicy(), Modul Privacy): Werte
    // werden überschrieben, nicht eingegeben - Pflicht, Muster, Grenzen und die
    // Gruppenrechte gelten nicht, nur die Umwandlung in den Feldtyp und 'unique'. Im
    // Audit-Log steht die Änderung als 'privacy' OHNE die bisherigen Werte.
    protected bool $policyMode = false;

    // Datums-Untergrenze (UTC) - eine Quelle für alle: Cms::DATE_MIN.
    // --------------------
    // Doppelte Rolle:
    //   1. gespeicherter Wert für "kein Datum gesetzt" - die datetime-Spalten sind
    //      NOT NULL, es braucht also einen Vertreter dafür
    //   2. harte Untergrenze jedes Datumsfeldes. Ein kleinerer Wert wird beim
    //      Speichern ABGEWIESEN, nicht stillschweigend angehoben: ein Datum, das
    //      sich beim Speichern von selbst verändert, ist für den Redakteur nicht
    //      nachvollziehbar.
    // Cms::_formatDate() gibt diesen Wert als Leerstring aus - gespeichert und
    // angezeigt passen also zusammen.
    protected const DATE_MIN = Cms::DATE_MIN;

    // Vorgangs-Zeitstempel des laufenden Soft-Delete/Restore (UTC, 'Y-m-d H:i:s.v'):
    // Alle in EINEM Vorgang markierten Datensätze erhalten exakt diesen changed_at.
    // Wird in delete() bzw. restore() gesetzt, Zugriff für Hooks: getMarkTimestamp()
    protected string $markTimestamp = '';
    // Nur beim Restore: changed_at des Löschvorgangs, der rückgängig gemacht wird.
    // Es werden nur Datensätze mit exakt diesem Zeitstempel wiederhergestellt
    // (keine Datensätze aus früheren Löschvorgängen). Zugriff: getRestoreTimestamp()
    protected string $restoreTimestamp = '';

    // Audit-Log (Camindo\Core\AuditLog)
    // --------------------
    // false: Änderungen dieser Tabelle nicht protokollieren (z. B. private Nachrichten)
    protected bool $audit = true;
    // Spalten, die nicht ins Protokoll gehören - zusätzlich zu AUDIT_IGNORE
    protected array $auditIgnore = [];
    // Nie protokolliert: Änderungsstempel, abgeleitete Baumwerte, Volltext, Zustand
    private const AUDIT_IGNORE = ['changed_at', 'changed_by', 'ft', 'sig', 'lft', 'rgt', 'depth',
        'parent_ids', 'tmp', 'origin_id', 'locked', 'deleted'];

    // HOOKs zur Erweiterung der Funktionalitäten je Tabelle
    // --------------------
    // Leere Standard-Implementierung - bei Bedarf in Kindklasse überschreiben.
    // Bei der Programmierung von Hooks beachten:
    //   Im Fehlerfall: Exception werfen
    //   (Auch dann wenn verwendete Funktionen load(), save(), delete() null zurück geben!)
    abstract protected function hookDefinition(): void;
    protected function hookOnCreate(array &$data): void {}
    protected function hookOnLoaded(array &$data): void {}
    protected function hookOnSave(array &$affectedFields, array &$data, array &$previousData): void {}
    protected function hookOnSaved(array &$affectedFields, array &$data, array &$previousData): void {}
    protected function hookOnSoftDelete(array $data): void {}
    protected function hookOnDelete(array $data): void {}
    protected function hookOnDeleted(array $data): void {}
    protected function hookOnRestore(array $data): void {}

    public function __construct(
        protected Config $config,
        protected Database $db,
        protected Crypt $crypt,
        protected Cms $cms,
        protected System $system,
        protected Session $session,
        protected ProcessorFactory $factory,
        protected ContainerInterface $container
    ) {

        // HOOK Table definition
        // --------------------
        $this->hookDefinition();

        // Schema - Felder als Metafelder markieren
        foreach ($this->schema as &$val) {
            $val['meta'] = 1;
        }
        unset($val);

        // Schema-Flags einmalig aus dem Schema ableiten
        // --------------------
        if ($this->hasParentId = isset($this->schema['parent_id'])) {
            if (
                !isset($this->schema['parent_ids'])
                || !isset($this->schema['lft'])
                || !isset($this->schema['rgt'])
                || !isset($this->schema['position'])
                || !isset($this->schema['depth'])
            ) {
                throw new \Exception('Schema error in table "' . $this->tableName . '" : field "parent_id" requires the fields "parent_ids", "lft", "rgt", "position", "depth".');
            }
        }
        $this->hasDeleted = isset($this->schema['deleted']);
        /*
        $this->hasTmp
            wird in hookDefinition() gesetzt.
            Bei hasTmp-Tabellen sind `tmp` und `deleted` zwingend Schema-Felder;
            zusätzlich werden die Datenbankfelder "origin_id",
            "changed_by", "changed_at" vorausgesetzt.
            Es gilt daher immer: hasTmp => hasDeleted.
            Tabellen nur mit hasDeleted (ohne hasTmp) sind ebenfalls möglich.
        */
        if ($this->hasTmp && (!isset($this->schema['tmp']) || !$this->hasDeleted)) {
            throw new \Exception('Schema error in table "' . $this->tableName . '": hasTmp requires the schema fields "tmp" and "deleted".');
        }
        $this->hasAreaId = isset($this->schema['area_id']) && $this->tableName != 'area';
        // changed_at/changed_by: entweder reguläres Schema-Feld
        // oder bei hasTmp vorausgesetztes Datenbankfeld (siehe Kommentar oben)
        $this->hasChangedAt = $this->hasTmp || isset($this->schema['changed_at']);
        $this->hasChangedBy = $this->hasTmp || isset($this->schema['changed_by']);
    }

    /**
     * CREATE - Standardwerte für neuen Datensatz holen
     *
     * @param array $defaults  Überschreiben der 'default'-Werte aus dem Tabellen-Schema (hookDefinition)
     *                         Werte für Subfelder eines json-Feldes sind als Sub-Array anzugeben,
     *                         z. B. $defaults['details']['width'] = ...
     * @return array|null      Vollständiges Array mit Standardwerten (Schlüssel für jedes Feld)
     */
    public function init(array $defaults = []): ?array
    {
        $data = [];
        $tableName = $this->tableName;
        if ($tableName == 'record' && method_exists($this, 'virtualSchema')) {
            $areaId = (int)($defaults['area_id'] ?? 0);
            $schema = $this->virtualSchema($areaId, true); // metafelder + virtuelle Felder
        }
        else {
            $schema = $this->schema; // metafelder
        }
        foreach ($schema as $name => $colDef) {
            $type = $colDef['type'] ?? 'text';
            if (in_array($type, $this->cms->arrayTypes, true)) {
                $default = [];
                if (!empty($colDef['default'])) {
                    // Jede Zeile im Default-Wert stellt eine wählbare Option dar
                    // Bei Relation: Id, ansonsten Wert
                    $list = preg_split('/(\r\n|\r|\n)/', $colDef['default'] ?? '');
                    foreach ($list as $item) {
                        if (in_array($type, $this->cms->dateTypes, true)) {
                            // Datumsfunktion?
                            if (preg_match($this->dateExpression, $item, $matches)) {
                                $item = $this->parseDateExpression($matches);
                            }
                            // else: ISO-Format bleibt
                            // alternativ: $this->cms->_formatDate() verwenden
                        }
                        $default[$item] = $item;
                    }
                }
            }
            else {
                $default = $this->scalarDefault($colDef);
                if (in_array($type, $this->cms->dateTypes, true)) {
                    // Datumsfunktion?
                    if (preg_match($this->dateExpression, $default, $matches)) {
                        $default = $this->parseDateExpression($matches);
                    }
                    // else: ISO-Format bleibt
                    // alternativ: $this->cms->_formatDate() verwenden
                }
            }
            if (str_contains($name, '.')) {
                // Subfeld (z. B. 'details.width')
                [$parentField, $subKey] = explode('.', $name, 2);
                $data[$parentField][$subKey] = $default;
            }
            else {
                // Hauptfeld (z. B. 'width')
                $data[$name] = $default;
            }
        }
        $data = array_replace_recursive($data, $defaults);
        // HOOK onCreate
        // --------------------
        // Fehler abfangen
        try {
            $this->hookOnCreate($data);
            return $data;
        } catch (\Exception $e) {
            $this->cms->error($e->getMessage());
            return null;
        }
    }

    /**
     * Vorgabe eines Feldes mit einfachem Typ. Bei einem Auswahlfeld (select, radio …)
     * sind die Zeilen der Vorgabe die Optionen, nicht der Wert - vorgegeben ist dann
     * wie in der Maske bei select die erste Option (die der Browser vorwählt), bei
     * den übrigen keine. Gilt für init() und die nicht übermittelten Felder in save()
     * (Anlegen und Verschieben ohne Maske).
     */
    protected function scalarDefault(array $colDef): string
    {
        $default = (string)($colDef['default'] ?? '');
        $displayType = (string)($colDef['display_type'] ?? '');
        if (preg_match('/\R/', $default) && in_array($displayType, ['select', 'radio', 'checkbox', 'multiselect', 'sortable'], true)) {
            return $displayType === 'select' ? trim((string)preg_split('/\R/', $default)[0]) : '';
        }
        return $default;
    }

    /** check() läuft: save() bricht nach der Prüfung ab */
    private bool $checkOnly = false;

    /**
     * Prüfen, ohne zu schreiben - dieselbe Prüfung wie save() (hookOnSave(),
     * Validierung, Rechte), danach Rollback statt INSERT/UPDATE; hookOnSaved() läuft
     * nicht, es entstehen also auch keine Cache-Dateien. Für Probeläufe der API
     * (simulate). Nicht für Tabellen mit Arbeitskopien (record: RecordService).
     *
     * @param array $fields  wie save()
     * @param array $data    wie save() (wird nicht verändert)
     * @return bool          false: Meldungen in Cms::$errors
     */
    public function check(array $fields, array $data): bool
    {
        if ($this->hasTmp) throw new \LogicException('Dataset::check() does not support working copies');
        $this->checkOnly = true;
        try {
            return $this->save($fields, $data) !== null;
        }
        finally {
            $this->checkOnly = false;
        }
    }

    /**
     * INSERT / UPDATE abhängig von $data["{$tableName}_id"]
     *
     * @param array $fields         Namen der Felder, die geschrieben werden
     *                              (leeres Array: alle Felder sind gemeint)
     * @param array $data           Inhalte
     * @return int|null             Id des erstellten/geändeten Datensatzes
     */
    public function save(array $fields, array &$data, bool $stayTemporary = false): ?int
    {
        $tableName = $this->tableName;
        $idField = $tableName . '_id';
        $schema = $this->schema;
        $sessionAdminId = (int)$this->session->get('admin_id');
        $adminIdForDb = $this->adminIdForDb();
        $id = (int)$data[$idField];
        $tmpId = 0;
        $originId = 0;
        $isTmp = false;
        
        // Wenn tmp-Feld in der Tabelle definiert ist:
        // Nur temporärer Datensatz kann gespeichert werden
        // checkout muss vorher immer erfolgen
        // Prüfen ob Edit-Lock noch besteht und dem Session-Admin gehört
        if ($this->hasTmp) {
            $sql = "SELECT `origin_id`,`tmp`,`deleted`,`changed_by` FROM `$tableName` WHERE `$idField` = ?";
            $this->db->query($sql, 'i', [$id]);
            if (empty($this->db->num_rows)) {
                $errorMsg = $this->cms->snippet('error_record_not_found');
                $this->cms->error($errorMsg);
                return null;
            }
            [$originId, $tmp, $deleted, $changedBy] = $this->db->fetchNum();
            $originId = (int)$originId;
            if (!empty($deleted)) {
                // Edit-Lock wurde gelöscht - changedBy ist der Admin der gelöscht hat
                $sql = "SELECT `name` FROM `admin` WHERE `admin_id` = ?";
                $this->db->query($sql, 'i', [$changedBy]);
                if ($this->db->num_rows < 1) $adminName = '??';
                else $adminName = $this->db->fetchNum()[0];
                $errorMsg = strtr($this->cms->snippet('record.error_editlock_deleted'), [
                    '{admin_name}' => $adminName
                ]);
                // eigener Admin: Arbeitskopie meist durch Logout verworfen (Record::cancelAll)
                if ((int)$changedBy === $sessionAdminId) {
                    $errorMsg .= ' ' . $this->cms->snippet('record.error_editlock_deleted_logout');
                }
                $this->cms->error($errorMsg);
                return null;
            }

            if ((int)$changedBy != $sessionAdminId) {
                // Zugriff verweigert: fremde Arbeitskopie soll geschrieben werden
                $errorMsg = $this->cms->snippet('error_tmp_record_access_denied');
                $this->cms->error($errorMsg);
                return null;
            }
            $isTmp = !empty($tmp);
            if ($isTmp) {
                // wenn Original zum Datensatz besteht, dann diese Id verwenden
                $tmpId = $id;
                if (!empty($originId)) {
                    $id = $originId;
                    $data[$idField] = $originId;
                }
            }
        }

        // Einzelschritt-Speicherung setzt eine temporäre Arbeitskopie voraus
        if ($stayTemporary && !$isTmp) {
            $this->cms->error('Dataset::save(): $stayTemporary requires a temporary working copy.');
            return null;
        }

        // Hochgeladene Dateien VOR der Eingabeprüfung sichern
        if ($tableName === 'record' && $isTmp && $tmpId > 0
            && method_exists($this, 'secureUploads')
            && method_exists($this, 'getProdData')
        ) {
            if (!$prodData = $this->getProdData($tmpId)) {
                $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
                return null;
            }
            $uploadAreaId = (int)$prodData[1];
            if ($uploadAreaId > 0 && method_exists($this, 'virtualSchema')) {
                $uploadSchema = $this->virtualSchema($uploadAreaId, false);
                if (!$this->secureUploads($tmpId, $uploadSchema, $data)) {
                    // Upload wurde abgelehnt - Meldung steht bereits in Cms::errors
                    return null;
                }
                // Eingegebene Passwörter ebenso: als Hash in der Arbeitskopie sichern,
                // damit sie eine scheiternde Prüfung und Reiterwechsel überstehen
                // (siehe securePasswords(); geprüft wird unten die Roheingabe)
                if (method_exists($this, 'securePasswords')) {
                    $this->securePasswords($tmpId, $uploadSchema, $data);
                }
            }
        }

        $isNew = ($id < 1);
        // Neu IM BAUM ist auch eine Arbeitskopie ohne Original (Anlegen, Kopieren):
        // sie hat nie im Baum gestanden - tmp-Zeilen sind dort ausgeschlossen -, bringt
        // aber schon eine Id mit, der Vorgang sieht deshalb aus wie ein UPDATE.
        // Für parent_id / position / lft / rgt / depth zählt der Baum, nicht die Id.
        $isNewInTree = $isNew || ($isTmp && $originId < 1);
        $this->savingTemporary = $stayTemporary;
        $previousWorkingCopyId = $this->savingWorkingCopyId;
        $this->savingWorkingCopyId = $isTmp ? (int)$tmpId : 0;
        $this->db->pushResult();
        // Transaktion starten
        try {
            $this->db->beginTransaction();
            // previousData
            // --------------------
            $previousData = [];
            if (!$isNew) {
                // SELECT FOR UPDATE
                if (!$previousData = $this->load($id, $idField, forUpdate:true)) {
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }
            // Audit-Log: Stand vor der Änderung (Einzelschritte der Arbeitskopie nicht)
            $auditBefore = null;
            if (!$stayTemporary && $this->auditActive()) {
                $auditBefore = $isNewInTree ? [] : $this->auditSnapshot((int)$id);
            }

            // area_id
            // --------------------
            $areaId = 0;
            if ($this->hasAreaId) {
                // evtl. nicht in data enthalten, weil sie behalten werden soll
                // dann aus den bisherigen Daten holen
                if (!in_array('area_id', $fields, true) && !empty($fields)) {
                    $areaId = (int)($previousData['area_id'] ?? 0);
                }
                else {
                    // ansonsten aus $data nehmen
                    $areaId = (int)($data['area_id'] ?? 0);
                }
                if ($areaId < 1) {
                    $this->createFieldError('error_value_mandatory', 'area_id');
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                // Rechte der Benutzergruppe: Anlegen im Bereich bzw. Ändern des
                // Datensatzes (Owner des gespeicherten Standes)
                $accessAction = $isNewInTree ? 'create' : 'write';
                if (!$this->accessCheck($accessAction, $areaId, isset($previousData['owner_id']) ? (int)$previousData['owner_id'] : null)) {
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }

            // schema, allFields
            // --------------------
            if ($tableName == 'record' && method_exists($this, 'virtualSchema')) {
                // Schema mit virtuellen Feldern laden
                $schemaVirtual = $this->virtualSchema($areaId, false);
                $schemaComplete = $schemaVirtual + $schema; // (gleichnamige Felder aus $schemaVirtual bevorzugt)
                // Namen, die in fields angegeben sein dürfen:
                // Alle Metafelder und virtuellen Felder
                $allFields = array_keys($schemaComplete);
            }
            else {
                $schemaVirtual = [];
                $schemaComplete = $schema;
                // Namen, die in fields angegeben sein dürfen:
                // Alle Felder, die keinen Punkt im Feldname enthalten
                // (ansonsten ist es ein Subfeld, dessen Daten im Hauptfeld gespeichert werden)
                $allFields = array_keys(
                    array_filter($schema, fn($key) => !str_contains($key, '.'), ARRAY_FILTER_USE_KEY)
                );
            }

            // Baum aktiv? Handsortierung aktiv?
            // --------------------
            // Ob ein Datensatz in einem Baum steht, entscheidet nicht die Tabelle
            // allein: bei `record` fuehrt nur ein Inhaltsbereich einen Baum, in dem
            // der Redakteur ein Eingabefeld `parent_id` angelegt hat. In allen
            // anderen Bereichen bleiben parent_id/lft/rgt/depth neutral und es
            // findet keinerlei Baumpflege statt - siehe TreeManagerTrait.
            // Dasselbe gilt fuer `position`: ohne Eingabefeld wird nicht sortiert
            // und es werden keine Geschwister verschoben.
            if ($tableName === 'record') {
                $treeActive     = $this->hasParentId && isset($schemaVirtual['parent_id']);
                $positionActive = isset($schemaVirtual['position']);
            }
            else {
                $treeActive     = $this->hasParentId;
                $positionActive = isset($schema['position']);
            }

            // fields
            // --------------------
            $fields = array_unique($fields);
            foreach ($fields as $name) {
                if (!isset($schemaComplete[$name])) {
                    // Harter Abbruch
                    throw new \Exception("Dataset::save(): field \"{$name}\" is not defined in schema of table \"{$tableName}\"");
                }
            }
            // Feldnamen sind nun "sicher".
            if (empty($fields)) $fields = $allFields;
            // Tatsächlich in diesem Request übermittelte Felder festhalten
            // (Basis für die Unterscheidung "übermittelt" vs. "gestagt/aus dem Arbeitsstand")
            $submittedFields = $fields;

            // Positionsangaben, die der Request SELBST mitbringt.
            // Weiter unten wird $data aus dem Arbeitsstand der Arbeitskopie und aus
            // $previousData ergänzt - danach ließe sich nicht mehr unterscheiden, ob
            // der Redakteur einen Vaterdatensatz bzw. Vorgänger gewählt hat oder ob
            // nur der Altwert nachgetragen wurde. Ohne diese Unterscheidung würde ein
            // Bereich mit unsichtbarem parent_id-/position-Feld seine Datensätze bei
            // jedem Speichern an den Anfang seiner Geschwister ziehen.
            // `parent_id` zählt nur, wenn der Aufrufer es auch in $fields nennt:
            // wer load() + save($fields, $data) aufruft (RecordUser, Skripte), hat
            // es nur mitgeladen. Umsortiert wird unten zudem nur, wenn previous_id
            // übermittelt ist oder sich der Vaterdatensatz tatsächlich ändert.
            $parentIdSubmitted   = array_key_exists('parent_id',   $data) && in_array('parent_id', $fields, true);
            $previousIdSubmitted = array_key_exists('previous_id', $data);

            // Zwischenstand (Arbeitsstand in `record_cache`) der Arbeitskopie laden:
            // - Einzelschritt-Speicherung: Basis für saveTemporaryStep()
            // - finaler Speichervorgang: Quelle der gestagten Felder (s.u.)
            // (enthält bereits die soeben von secureUploads() gesicherten Datei-Infos)
            $tmpCache = [];
            if ($tableName === 'record' && $isTmp && method_exists($this, '_loadTmpData')) {
                $tmpCache = $this->_loadTmpData($tmpId);
            }

            // Sprachen der Instanz des Datensatzes (mehrsprachige Felder)
            // --------------------
            $langCtx = ($tableName === 'record') ? $this->languageContext($areaId) : [];

            // affectedFields / gestagte Felder
            // --------------------
            // stayTemporary (Einzelschritt): nur die übermittelten Felder.
            // final + Arbeitskopie (record && isTmp): ALLE Felder. Durch die
            //   Einzelschritt-Zwischenspeicherung kann sich der endgültige Datensatz aus
            //   dem aktuellen Request UND dem Arbeitsstand der Arbeitskopie zusammensetzen und
            //   muss als Ganzes geprüft werden (z. B. nie gefüllte Pflichtfelder).
            // final OHNE Arbeitskopie (andere Tabellen bzw. record ohne tmp): nur die
            //   übermittelten Felder - hier gibt es keine Zwischenstände; nicht
            //   übermittelte Felder bleiben in der Datenbank unverändert
            //   (z. B. Login-Update: login_date wird geschrieben, group_id bleibt).

            $stagedFields = [];   // vollständig aus dem Zwischenstand übernommene Felder
            $staged = [];         // feldname => [language_id, ...]: teilweise (je Sprache)
            $isFinalCheckIn = ($tableName === 'record' && $isTmp && !$stayTemporary);
            if (!$isFinalCheckIn) {
                $affectedFields = $submittedFields;
            }
            else {
                $affectedFields = $allFields;
            }
            if ($tableName === 'record' && $isTmp) {
                // Zwischenstände einfließen lassen: beim checkOut wurde der produktive
                // Stand in den Arbeitsstand kopiert und durch Einzelschritte fortgeschrieben -
                // er enthält den vollständigen Feldstand. Gecachte Werte-Felder, die
                // NICHT erneut übermittelt wurden, beim finalen Speichern von dort
                // übernehmen (Werte bereits umgewandelt/geprüft) und in $stagedFields
                // markieren. Relations (nicht gecacht, hängen an der Arbeitskopie) und
                // Datei-Felder (über Arbeitsstand + promoteTmpFiles) bleiben außen vor.
                //
                // Mehrsprachige Felder werden je SPRACHE zusammengeführt: die Eingabe-
                // maske mit Reitern schickt nur die Sprache des Reiters, die übrigen
                // Sprachen kommen aus dem Zwischenstand und gelten als geprüft ($staged).
                // Das gilt auch für den Einzelschritt selbst - sonst überschriebe der
                // Reiterwechsel die anderen Sprachen mit leeren Werten.
                foreach ($tmpCache as $cacheName => $entry) {
                    if (!isset($schemaComplete[$cacheName])) continue;
                    $cType = $schemaComplete[$cacheName]['type'] ?? 'text';
                    if (in_array($cType, $this->cms->relationTypes, true)) continue;
                    if ($cType === 'file') continue;
                    $isMulti = !empty($schemaComplete[$cacheName]['multilingual'])
                        && !empty($langCtx['language_ids'])
                        && is_array($entry[1] ?? null);
                    if (in_array($cacheName, $submittedFields, true)) {
                        // Request hat Vorrang - bei mehrsprachigen Feldern je Sprache
                        if ($isMulti) {
                            $submitted = is_array($data[$cacheName] ?? null) ? $data[$cacheName] : [];
                            $submittedLangs = array_map('intval', array_keys($submitted));
                            $data[$cacheName] = $submitted + (array)$entry[1];
                            $staged[$cacheName] = array_values(array_diff(
                                array_map('intval', array_keys((array)$entry[1])),
                                $submittedLangs
                            ));
                        }
                        continue;
                    }
                    if (!$isFinalCheckIn) continue; // Einzelschritt: nur übermittelte Felder
                    $data[$cacheName] = $entry[1] ?? '';
                    $stagedFields[]   = $cacheName;
                }
            }
            // Passwörter: leer gelassen, aber in der Arbeitskopie gesichert
            // --------------------
            // Der Hash aus dem Arbeitsstand (securePasswords()) gilt als Eingabe -
            // bereits gehasht ($prehashedFields, saveVirtualFields() hasht nicht erneut)
            // und geprüft (gestagt). So muss ein Passwort nach einer fehlgeschlagenen
            // Prüfung oder einem Reiterwechsel nicht erneut eingegeben werden, und ein
            // neuer Datensatz läuft nicht in die Pflichtprüfung.
            if ($tableName === 'record' && $isTmp) {
                foreach ($schemaVirtual as $name => $colDef) {
                    if (($colDef['type'] ?? '') !== 'password' && ($colDef['display_type'] ?? '') !== 'password') continue;
                    if ((string)($data[$name] ?? '') !== '') continue;
                    $hash = (string)($tmpCache[$name][1] ?? '');
                    if ($hash === '') continue;
                    $data[$name] = $hash;
                    $this->prehashedFields[] = $name;
                    if (!in_array($name, $stagedFields, true)) $stagedFields[] = $name;
                }
            }

            if ($isFinalCheckIn) {
                // Metafelder, die der Request nicht mitgeschickt hat
                // --------------------
                // Durch den Bearbeitungsvorgang getragen wird nur der Arbeitsstand der
                // Arbeitskopie - und der enthält ausschließlich die VIRTUELLEN
                // Feldinhalte (saveTemporaryStep(): Felder aus `record` selbst
                // werden übersprungen). Die Metafelder stehen also
                // nirgends zwischengespeichert: was die Eingabemaske nicht anzeigt,
                // kommt auch nicht zurück. Betroffen ist jedes Metafeld, das der
                // Bereich nicht als Eingabefeld führt oder das auf unsichtbar steht -
                // status_id, template_id, online_at, offline_at, needs_review …
                // Ohne diesen Block bekämen sie unten den Schema-Default und
                // überschrieben damit beim Check-in den produktiven Wert.
                //
                // Zwei Dinge passieren deshalb hier:
                //   1. der bisherige Wert wandert nach $data - die Logik weiter unten
                //      rechnet sonst mit 0 bzw. leer (z. B. updateInTree() über
                //      parent_id, das den Datensatz an die Wurzel hängen würde)
                //   2. das Feld fliegt aus $affectedFields: die Spalte wird nicht
                //      geschrieben und nicht erneut geprüft - genau die Semantik der
                //      übrigen Tabellen ("nicht übermittelt bleibt unverändert")
                // Ausgenommen von 2. sind die Baumfelder: über sie entscheidet
                // buildColumnExpressions() anhand von $treeActive (in Bereichen ohne
                // Baum werden sie ausdrücklich neutral geschrieben).
                //
                // Neue Datensätze ($isNewInTree) sind ausgenommen: dort gibt es keinen
                // Vorgängerwert, hier gehören die Schema-Defaults hin.
                //
                // Ebenfalls ausgenommen: Metafelder, die der Bereich als SICHTBARES
                // Eingabefeld führt und die der Aufrufer in $fields nennt. Fehlt so ein
                // Feld in $data, hat der Redakteur es geleert - ein ausgeschalteter
                // Switch (bit) steht gar nicht im POST, ein geleertes Textfeld lässt
                // serializeFiles() weg. Der Altwert darf hier nicht nachgetragen werden,
                // sonst ließe sich z. B. needs_review über die Maske nie auf 0 setzen.
                if (!$isNewInTree && !empty($previousData)) {
                    $treeColumns = ['parent_id', 'parent_ids', 'position', 'lft', 'rgt', 'depth'];
                    foreach ($schema as $name => $colDef) {
                        if (array_key_exists($name, $data)) continue;
                        if (!array_key_exists($name, $previousData)) continue;
                        if (in_array($name, $submittedFields, true)
                            && (int)($schemaComplete[$name]['visibility_input'] ?? 0) === 1
                            && !in_array($name, $treeColumns, true)) {
                            continue;
                        }
                        $data[$name] = $previousData[$name];
                        if (in_array($name, $treeColumns, true)) continue;
                        if (($pos = array_search($name, $affectedFields, true)) !== false) {
                            unset($affectedFields[$pos]);
                        }
                    }
                    $affectedFields = array_values($affectedFields);
                }

                // Default-Werte für Felder, die weder übermittelt noch gestagt sind
                // (leere Pflichtfelder laufen so korrekt in die not_empty-Prüfung)
                foreach ($schemaComplete as $name => $colDef) {
                    if (in_array($name, $submittedFields, true)) continue;
                    if (in_array($name, $stagedFields, true)) continue;
                    if (array_key_exists($name, $data)) continue;
                    $type = $colDef['type'] ?? 'text';
                    $default = in_array($type, $this->cms->arrayTypes, true) ? ($colDef['default'] ?? '') : $this->scalarDefault($colDef);
                    if (in_array($type, $this->cms->dateTypes, true)
                        && preg_match($this->dateExpression, $default, $matches)) {
                        // CMS-Datumsfunktion in Datum wandeln
                        $default = $this->parseDateExpression($matches);
                        // SQL-Datumsfunktion wird beim Schreiben eingesetzt
                    }
                    $data[$name] = $default;
                }
            }

            // HOOK onSave
            // --------------------
            // Rollback bei Fehler
            $this->hookOnSave($affectedFields, $data, $previousData);

            // Instanz des Datensatzes - Reichweite des Flags 'unique'
            // --------------------
            // Bei Tabellen mit `instance_id` (area, record, template) muss ein
            // 'unique'-Wert nicht tabellenweit einmalig sein, sondern innerhalb der
            // Instanz samt den mit ihr zusammengeschalteten Instanzen
            // (InstanceCache::uniqueScope()). Erst nach hookOnSave(): Record und
            // Template holen ihre instance_id dort aus dem Bereich.
            $instanceId = 0;
            if (isset($schema['instance_id'])) {
                $instanceId = (int)($data['instance_id'] ?? $previousData['instance_id'] ?? 0);
            }

            // Übermittelte, sichtbare Relation-Felder wenn diese direkt übermittelt werden 
            // (also nicht vom displayTyp 'lookup' sind)
            if ($isTmp && method_exists($this, 'createRelations')) {
                foreach ($submittedFields as $name) {
                    $colDef = $schemaComplete[$name];
                    $type = $colDef['type'] ?? 'text';
                    if (!in_array($type, $this->cms->relationTypes, true)) continue;
                    if (($colDef['display_type'] ?? '') === 'lookup') continue;
                    // Zielbereich für die Gruppe unlesbar: Verknüpfungen bleiben, wie sie sind
                    if (!$this->relationTargetReadable($colDef)) continue;
                    // 'link' (fest verknüpfte Inhalte): die Verknüpfungen hängen schon an
                    // der Arbeitskopie (Lookup bzw. eingebettete Masken, LinkTrait) -
                    // nie eine Id-Liste aus dem Formular
                    if (in_array($type, $this->cms->dependentTypes, true)) continue;
                    if ((int)($colDef['visibility_input'] ?? 0) !== 1) continue;
                    $this->createRelations($colDef, $tmpId, $data[$name] ?? [], replace:true, checkTmp:false);
                }
            }

            // Daten-Aufbereitung und -prüfung
            // --------------------
            $flagSuccess = true;
            foreach ($schemaComplete as $name => $colDef) {
                // Gestagte Felder (aus dem Arbeitsstand übernommen) sind bereits geprüft und
                // umgewandelt - nicht erneut validieren (Format entspräche nicht mehr
                // der Roh-Eingabe, z. B. currency in Cent, timestampl in UTC)
                if (in_array($name, $stagedFields, true)) continue;
                // created_at/created_by werden bei einer Änderung nicht geschrieben
                // (siehe weiter unten) - dann auch nicht prüfen. Sonst blockiert ein
                // Altwert das Speichern, ohne dass er sich über die Maske korrigieren
                // ließe: die Felder stehen dort gar nicht zur Eingabe.
                // ($isNewInTree: auch eine Arbeitskopie ohne Original ist ein Neueintrag)
                if (!$isNewInTree && in_array($name, ['created_at', 'created_by'], true)) continue;
                if (!$this->validateFieldValue($name, $colDef, $data, $affectedFields, $isNew, $isTmp, $id, $tmpId, $areaId, $langCtx, $staged, $instanceId)) {
                    $flagSuccess = false;
                }
                // Upload-Feld mit Pflicht (not_empty): eine Datei muss vorliegen.
                // Nur beim finalen Speichern - ein Zwischenschritt (Reiterwechsel)
                // darf ohne Datei bleiben, wie ein leeres Pflicht-Textfeld dort auch.
                if (($colDef['type'] ?? '') === 'file' && !$stayTemporary && !$this->policyMode
                    && in_array($name, $affectedFields, true)
                    && method_exists($this, 'validateFileRequired')
                    && !$this->validateFileRequired($name, $colDef, $data, $tmpCache, $langCtx, $isTmp ? 0 : $id)
                ) {
                    $flagSuccess = false;
                }
            }

            // Status eines Datensatzes (flacher Workflow): bleibt status_id
            // unverändert, ist nichts zu beachten; wird er geändert, muss die Gruppe
            // den neuen Status setzen dürfen (Access::statusWrite()). Ohne Vorgänger
            // zählt der Schema-Default.
            if ($flagSuccess && !$this->policyMode && $tableName === 'record' && isset($schemaComplete['status_id'])
                && in_array('status_id', $affectedFields, true) && array_key_exists('status_id', $data)) {
                $previousStatus = array_key_exists('status_id', $previousData)
                    ? (int)$previousData['status_id'] : (int)$this->scalarDefault($schemaComplete['status_id']);
                $access = $this->container->get(Access::class);
                if ((int)$data['status_id'] !== $previousStatus && !$access->statusWrite((int)$data['status_id'])) {
                    $this->createFieldError('error_status_denied', 'status_id', (string)($schemaComplete['status_id']['label'] ?? ''));
                    $flagSuccess = false;
                }
            }

            // Bei Fehlern abbrechen
            if (!$flagSuccess) {
                // finally beachten
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }

            // Nur prüfen (check()): hier ist alles geprüft - nichts schreiben, keine
            // Hooks nach dem Schreiben; Änderungen aus hookOnSave() fallen mit dem
            // Rollback weg
            if ($this->checkOnly) {
                $this->db->rollback();
                // (finally beachten)
                return max(0, (int)$id);
            }

            // Einzelschritt-Speicherung ($stayTemporary === true)
            // --------------------
            // Arbeitskopie bleibt temporär (tmp = 1). Die geprüften/umgewandelten
            // Inhalte werden nur im Arbeitsstand zwischengespeichert; Uploads wurden bereits
            // von secureUploads() gebunden, Relations in validateFieldValue().
            if ($stayTemporary) {
                if ($tableName === 'record' && method_exists($this, 'saveTemporaryStep')) {
                    $this->saveTemporaryStep($affectedFields, $data, $schemaVirtual, $tmpId, $tmpCache, $langCtx);
                }
                // Edit-Lock der Arbeitskopie auffrischen
                $sql = "UPDATE `{$tableName}` SET `changed_at` = UTC_TIMESTAMP(), `changed_by` = ?"
                    . " WHERE `{$idField}` = ?";
                $this->db->query($sql, 'ii', [$adminIdForDb, $tmpId]);
                // HOOK onSaved (vor dem Commit)
                $this->hookOnSaved($affectedFields, $data, $previousData);
                $this->db->commit();
                // (finally beachten)
                return (int)$tmpId;
            }

            // (Der Inhalts-Cache je Sprache entsteht in saveVirtualFields() und liegt
            //  in `record_cache` - bei Arbeitskopien ist er zugleich deren Arbeitsstand,
            //  siehe TemporaryTrait.)

            // position
            // --------------------
            // Wurde am Baum wirklich etwas bewegt? Nur dann muss er nach dem Commit
            // neu berechnet werden - eine Statusänderung an einem Datensatz laesst die
            // Struktur unberuehrt und darf nicht den ganzen Bereich umschreiben.
            $treeChanged = false;
            if ($treeActive || ($this->hasParentId && $positionActive)) {

                // parent_id, (previous_id -> position, lft, rgt)
                // --------------------
                // Auch OHNE Baum wird die Reihenfolge so gerechnet: die Maske schickt
                // nicht die Zahl `position`, sondern den vorangehenden Datensatz
                // (previous_id, siehe RecordBackendEdit::_editPreviousParams()). In
                // einem Bereich ohne `parent_id`-Feld liegen alle Datensätze auf der
                // Wurzelebene - die Rechnung ist dieselbe, nur das Nested Set entfällt
                // ($treeChanged bleibt false, es wird also nichts neu berechnet).
                if ($isNewInTree) {
                    // Neuer Knoten: er braucht in jedem Fall eine Position - ohne
                    // Angaben landet er als erster Datensatz seiner Ebene. Eine Kopie
                    // bringt ihr Vorbild als previous_id mit und steht damit direkt
                    // dahinter (RecordBackendEdit::_editLoad()).
                    if (method_exists($this, 'createInTree')) $this->createInTree($data, $areaId);
                    $treeChanged = $treeActive;
                }
                elseif ($previousIdSubmitted
                    || ($parentIdSubmitted && (int)($data['parent_id'] ?? 0) !== (int)($previousData['parent_id'] ?? 0))) {
                    // Umsortieren nur, wenn der Request eine Angabe dazu mitbringt:
                    // einen Vorgänger oder einen anderen Vaterdatensatz. Ein
                    // unveränderter parent_id ohne previous_id lässt den Datensatz
                    // an seinem Platz (sonst landete er als erster seiner Ebene).
                    if (method_exists($this, 'updateInTree')) {
                        $moved = $this->updateInTree($data, $previousData, $areaId);
                        $treeChanged = ($treeActive && $moved);
                    }
                }
            }
            elseif ($positionActive && in_array('position', $affectedFields, true)) {

                // position ohne Tree-Felder
                // --------------------
                $position    = (int)$data['position'];
                $oldPosition = (int)($previousData['position'] ?? -1);
                if ($position !== $oldPosition || $isNew) {
                    // Platz schaffen: Geschwister ab Zielposition nach hinten schieben
                    $sql = "UPDATE `{$tableName}` SET `position` = `position` + 1"
                        . " WHERE `position` >= ? AND `{$tableName}_id` != ?";
                    $sqlTypes = 'ii';
                    $sqlValues = [$position, $id];
                    if ($this->hasAreaId) {
                        // Position innerhalb Area
                        $sql  .= " AND `area_id` = ?";
                        $sqlTypes .= 'i';
                        $sqlValues[] = $areaId;
                    }
                    $this->db->query($sql, $sqlTypes, $sqlValues);
                    // Hinweis: MySQL sperrt die betroffenen Zeilen bis die Transaktion abgeschlossen ist
                }
            }

            // Erstellungs-/Änderungs-Felder
            // --------------------
            // Erstellungsdatum und -Admin immer bei Neueintrag - auch bei einer
            // Arbeitskopie ohne Original (Anlegen im Backend, CMS:USER_INPUT): sie
            // hat schon eine Id, ist aber neu ($isNewInTree); ohne das bliebe
            // created_at auf dem Null-Datum aus insertTmpRecord()
            if ($isNewInTree) {
                if (isset($schema['created_at']) && !in_array('created_at', $affectedFields, true)) {
                    $affectedFields[] = 'created_at';
                }
                if (isset($schema['created_by']) && !in_array('created_by', $affectedFields, true)) {
                    $affectedFields[] = 'created_by';
                }
                // Owner (`record`.`owner_id`, siehe 12-group-rights.md): der
                // Session-Admin, sofern der Aufrufer keinen mitgibt (Frontend-User:
                // RecordUser setzt den 'owner' des CMS-Befehls; Import: die Herkunft).
                // Bei einer Änderung wird der Owner nur geschrieben, wenn er in
                // $fields genannt ist (Zusatzrecht "Owner anpassen").
                if (isset($schema['owner_id'])) {
                    if (empty($data['owner_id'])) $data['owner_id'] = (int)($adminIdForDb ?? 0);
                    if (!in_array('owner_id', $affectedFields, true)) $affectedFields[] = 'owner_id';
                }
            }
            else {
                // bei Änderung nicht beachten
                $affectedFields = array_diff($affectedFields, ['created_at', 'created_by']);
            }
            // Änderungsdatum und -Admin immer aktualisieren, wenn vorhanden
            if (isset($schema['changed_at']) && !in_array('changed_at', $affectedFields, true)) {
                $affectedFields[] = 'changed_at';
            }
            if (isset($schema['changed_by']) && !in_array('changed_by', $affectedFields, true)) {
                $affectedFields[] = 'changed_by';
            }
            // Query zusammenbauen
            // --------------------
            [$colExpressions, $bindTypes, $values] =
                $this->buildColumnExpressions($schemaComplete, $affectedFields, $data, $previousData, $isNew, $isNewInTree, $treeActive, $positionActive);
            // Query ausführen
            // --------------------
            if ($isNew && !$isTmp) {
                // INSERT
                // --------------------
                $sql = "INSERT INTO `{$tableName}` SET " . implode(', ', $colExpressions);
                $result = $this->db->query($sql, $bindTypes, $values);
                if ($result === false) {
                    // Einfügen fehlgeschlagen
                    $errorMsg = $this->cms->snippet('error_save_error');
                    $this->cms->error($errorMsg);
                    // Transaktion verwerfen
                    throw new \Exception('#' . $errorMsg);
                }
                $newId = $this->db->insert_id;
                $data[$idField] = (int)$newId;
                $data['new'] = 1;
                $id = $newId;
            } else {
                // UPDATE
                // --------------------
                if (!empty($colExpressions)) {
                    // Metafelder schreiben
                    $sql = "UPDATE `{$tableName}`"
                        ." SET " . implode(', ', $colExpressions)
                        . " WHERE `{$idField}` = ?";
                    $bindTypes .= 'i';
                    $values[] = $id; // Id für die WHERE-Bedingung
                    $result = $this->db->query($sql, $bindTypes, $values);
                    if ($result === false) {
                        // Update fehlgeschlagen
                        $errorMsg = $this->cms->snippet('error_save_error');
                        $this->cms->error($errorMsg);
                        // Transaktion verwerfen
                        throw new \Exception('#' . $errorMsg);
                    }
                }
                $data['new'] = 0;
                // $id bleibt gleich
            }

            // Inhalte in virtuelle Felder schreiben
            // (VOR checkIn: Dateien werden zunächst unter der Id der Arbeitskopie abgelegt -
            //  die Arbeitskopie muss dazu noch existieren, sonst gehen die data_fil-Einträge
            //  per FK-Cascade verloren. Alle Werte in $data liegen an dieser Stelle
            //  bereits im Datenbankformat vor - validateFieldValue() hat sie umgewandelt,
            //  gestagte Werte waren es schon.)
            if ($tableName === 'record' && method_exists($this, 'saveVirtualFields')) {
                $this->saveVirtualFields($affectedFields, $data, $previousData, $schemaVirtual, $tmpId, $langCtx);
                $this->prehashedFields = [];
            }

            // Kopieren der Relations und temp. Kopie löschen
            // (checkIn() zieht via promoteTmpFiles() auch die Dateien der Arbeitskopie
            //  auf den produktiven Datensatz um)
            if ($isTmp && method_exists($this, 'checkIn')) {
                $this->checkIn($tmpId, $originId, $schemaVirtual);
            }
            // die Verknüpfungen hängen jetzt am Original (siehe $savingWorkingCopyId)
            $this->savingWorkingCopyId = 0;

            // Thumbnails eines vorhandenen Datensatzes: erst jetzt, mit den Dateien
            // unter der produktiven Id (siehe VirtualFieldsTrait::saveVirtualFields())
            if ($tableName === 'record' && method_exists($this, 'writeDeferredThumbnails')) {
                $this->writeDeferredThumbnails((int)$id, $schemaVirtual);
            }

            // Volltexte erneuern (NACH checkIn: benötigt die zurückkopierten Relations)
            if ($tableName === 'record' && method_exists($this, 'refreshAffectedFulltext')) {
                $this->refreshAffectedFulltext((int)$id);
            }

            // HOOK onSaved
            // --------------------
            // vor dem Commit, falls was schief läuft
            $this->hookOnSaved($affectedFields, $data, $previousData);

            // Audit-Log: Vorher/Nachher in derselben Transaktion
            if ($auditBefore !== null) {
                $this->auditWrite($isNewInTree ? 'create' : 'update', (int)$id, $auditBefore);
            }
            $this->db->commit();

            // Nested-Set neu aufbauen
            // --------------------
            // nach dem Commit, und nur fuer den Baum DIESES Inhaltsbereichs -
            // Baeume anderer Bereiche sind von der Aenderung nicht betroffen.
            if ($treeActive && $treeChanged && method_exists($this, 'externalRebuildTree')) {
                $this->externalRebuildTree($areaId);
            }
            // Bereichswechsel: der Baum des Herkunfts-Bereichs verliert einen Knoten
            // und muss ebenfalls neu berechnet werden (externalRebuildTree() prueft
            // selbst, ob der alte Bereich ueberhaupt einen Baum fuehrt).
            if ($this->hasParentId && $this->hasAreaId && method_exists($this, 'externalRebuildTree')) {
                $oldAreaId = (int)($previousData['area_id'] ?? 0);
                if ($oldAreaId > 0 && $oldAreaId !== $areaId) {
                    $this->externalRebuildTree($oldAreaId);
                }
            }
            return (int)$id;
            // (finally beachten)

        } catch (\mysqli_sql_exception $e) {
            // Dieser Block wird NUR ausgeführt, wenn es ein mysqli-Fehler ist
            // Refactoring: statt echo (Debug-Ausgabe) reguläre Fehlerbehandlung
            // inklusive Rollback (fehlte hier bisher)
            $this->cms->error('MySQL error: ' . $e->getMessage() . ' (Code ' . $e->getCode() . ')');
            $this->db->rollback();
            // (finally beachten)
            return null;

        } catch (\Exception $e) {
            // Fehlermeldungen, die noch nicht in Cms::errors gesammelt wurden, hinzufügen
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            // Alles Rückgängig machen
            $this->db->rollback();
            // (finally beachten)
            return null;
        } finally {
            $this->savingTemporary = false;
            $this->savingWorkingCopyId = $previousWorkingCopyId;
            $this->db->popResult();
        }
    }

    /**
     * Arbeitskopie, an der die Verknüpfungen des gerade gespeicherten Datensatzes
     * hängen - nur während save() bis zum checkIn, sonst 0 (siehe
     * $savingWorkingCopyId).
     */
    public function savingWorkingCopyId(): int
    {
        return $this->savingWorkingCopyId;
    }

    /**
     * LOAD
     *
     * @param string|int $colData  Spaltenwert
     * @param string $fieldName    Spaltenname, default: {tableName}_id
     * @param bool $forUpdate      true: Laden mit Zeilensperre in der Datenbank
     * @return array|null          Alle Inhalte außer Passwort-Hash
     */
    public function load(string|int $colData, string $fieldName = '', $forUpdate = false): ?array
    {
        $fieldName = preg_replace('/[^a-z0-9_]/','', $fieldName);
        $tableName = $this->tableName;
        $schema = $this->schema;

        // WHERE-Spalte
        if ($fieldName === '') $fieldName = $tableName . '_id';
        
        // Typ der Spalte für Übertragung festlegen
        $type = $schema[$fieldName]['type'] ?? '';
        if (empty($type)) {
            // Harter Abbruch
            throw new \Exception("Dataset::load(): Unkown column $fieldName");
        }

        // Bisheriges Datenbank-Ergebnis sichern
        $this->db->pushResult();
        try {
            // Select-Query erstellen
            // FOR UPDATE, nur innerhalb laufender Transaktion (siehe save(), delete())
            $bindType = $this->getBindType($type);

            $sql = "SELECT * FROM `{$tableName}` WHERE `{$fieldName}` = ?";
            $sql .= $forUpdate ? ' FOR UPDATE' : '';                        
            $this->db->query($sql, $bindType, [$colData]);

            if ($this->db->num_rows == 0) {
                // Datensatz nicht gefunden
                // Spezielles error-Snippet defiert?
                $msgId = "{$this->moduleName}.error_{$tableName}_not_found";
                $errorMsg = $this->cms->snippet($msgId);
                if ($errorMsg == $msgId) {
                    // Ansonsten verwende allgemeines
                    $errorMsg = $this->cms->snippet('error_record_not_found');
                }
                $this->cms->error($errorMsg);
                // Transaktion verwerfen
                throw new \Exception('#' . $errorMsg);
            }
            if ($this->tableName == 'record') {
                $data = $this->cms->fetch();
            }
            else {
                $data = $this->fetch();
            }

            // HOOK onLoaded
            // --------------------
            // Fehler abfangen
            try {
                $this->hookOnLoaded($data);
                // (finally des äußereren try-catch-Blocks beachten)
                return $data;
            } catch (\Exception $e) {
                $this->cms->error($e->getMessage());
                // (finally des äußereren try-catch-Blocks beachten)
                return null;
            }

        } catch (\Exception $e) {
            // Fehlermeldungen, die noch nicht in Cms::errors gesammelt wurden, hinzufügen
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            // (finally beachten)
            return null;

        } finally {
            // vorheriges Datenbankergebnis wiederherstellen
            $this->db->popResult();
        }
    }

    // ====================
    // LÖSCHEN UND WIEDERHERSTELLEN
    //
    // Aufgeteilt in EINZEL- und ORCHESTRIERUNGS-Funktionen.
    //
    // Einzel-Funktionen - jede behandelt genau EINEN Datensatz und feuert dessen Hooks:
    //     softDelete($id)     `deleted` = 1 setzen
    //     restoreOne($id)     `deleted` = 0 setzen
    //     finalDelete($id)    Datensatz-Zeile entfernen
    //
    // Orchestrierungs-Funktionen - sammeln nur die betroffenen Ids und schicken sie in
    // einer Schleife durch die passende Einzel-Funktion:
    //     delete($id, $withChilds)     Einstieg fürs Backend    -> Soft-Delete
    //     restore($id, $withChilds)    Einstieg fürs Backend    -> Wiederherstellen
    //     purge($id, $withChilds)      Einstieg fürs Systemtool -> endgültiges Löschen
    //
    // Diese Aufteilung ist Absicht: Es gibt KEINEN Weg, einen Datensatz zu löschen,
    // ohne dass seine Hooks laufen. Frühere Massen-UPDATEs/DELETEs über lft/rgt bzw.
    // area_id sind dadurch entfallen. Sobald benutzerdefinierte Hooks je Inhaltsbereich
    // dazukommen, greifen sie ohne weitere Änderung auch bei Massenlöschungen.
    //
    // ARBEITSTEILUNG
    //     Der Administrator löscht IMMER nur soft (delete()).
    //     Endgültig löscht ausschließlich das Systemtool (purge()), und zwar nur
    //     Datensätze, die seit mindestens admin.trash_retention_days markiert sind
    //     (Zeitplaner-Aufgabe "Papierkorb leeren", Typ trash).
    //     Tabellen ohne `deleted`-Feld kennen keinen Papierkorb - dort löscht delete()
    //     unmittelbar endgültig.
    //
    // TRANSAKTIONEN
    //     Die Orchestrierungs-Funktionen öffnen EINE Transaktion für den gesamten
    //     Vorgang: entweder werden alle Datensätze verarbeitet oder keiner.
    //     Die Einzel-Funktionen öffnen ihre eigene, wenn sie direkt aufgerufen werden.
    // ====================

    /**
     * DELETE - Einstieg für das Backend.
     *
     * Markiert einen Datensatz (und auf Wunsch seinen Teilbaum) als gelöscht, indem
     * jeder betroffene Datensatz einzeln durch softDelete() läuft. Tabellen ohne
     * `deleted`-Feld werden stattdessen endgültig gelöscht (purge()).
     *
     * Wenn Id zu temporärer Arbeitskopie gehört, dann ist zugehöriger Original-Artikel gemeint!
     *
     * @param int  $id          `{tableName}_id`
     * @param bool $withChilds  true:  Kinddatensätze werden mit markiert; der Baum bleibt
     *                                 unverändert (lft, rgt, position, depth), markierte
     *                                 Einträge verbleiben vorerst darin.
     *                          false: Kinddatensätze rutschen eine Ebene hoch (default),
     *                                 analog zum endgültigen Löschen - siehe liftChildren()
     * @return bool             true, wenn erfolgreich
     */
    public function delete(int $id, bool $withChilds = false): bool
    {
        // Tabellen ohne `deleted`-Feld kennen kein Soft-Delete
        if (!$this->hasDeleted) {
            return $this->purge($id, $withChilds);
        }

        return (bool)$this->transactional(function () use ($id, $withChilds) {

            // SELECT FOR UPDATE, temporäre Arbeitskopie auf Original auflösen
            $data = $this->loadProductive($id);

            // Vorgangs-Zeitstempel: alle in diesem Vorgang markierten Datensätze
            // erhalten exakt denselben changed_at (Basis für restore())
            $this->markTimestamp = $this->newTimestamp();

            $ids = $this->collectIds($data, $withChilds, 'delete');
            foreach ($ids as $currentId) {
                $this->softDeleteOne($currentId);
            }
            return true;
        }, false);
    }

    /**
     * RESTORE - Einstieg für das Backend.
     *
     * Hebt die Löschmarkierung auf, indem jeder betroffene Datensatz einzeln durch
     * restoreOne() läuft. Der Baum blieb beim Soft-Delete unverändert, ein
     * rebuildTree() ist daher nicht nötig.
     *
     * Bei $withChilds == true werden nur die Kind-Elemente des Teilbaums
     * wiederhergestellt, deren changed_at EXAKT dem des Wurzel-Datensatzes entspricht
     * (= derselbe Löschvorgang). Datensätze aus früheren, separaten Löschvorgängen
     * bleiben markiert. Tabellen ohne changed_at-Feld: kein Zeitstempel-Abgleich
     * möglich, es werden dann alle markierten Kind-Elemente wiederhergestellt.
     *
     * @param int  $id          `{tableName}_id`
     * @param bool $withChilds  true: auch Kind-Elemente desselben Löschvorgangs (default)
     * @return bool             true, wenn erfolgreich
     */
    public function restore(int $id, bool $withChilds = true): bool
    {
        if (!$this->hasDeleted) {
            // Tabelle unterstützt kein Soft-Delete
            $errorMsg = $this->cms->snippet('error_undelete_not_supported');
            $this->cms->error($errorMsg);
            return false;
        }

        return (bool)$this->transactional(function () use ($id, $withChilds) {
            // SELECT FOR UPDATE, temporäre Arbeitskopie auf Original auflösen
            $data = $this->loadProductive($id);
            if (empty($data['deleted'])) {
                // Datensatz ist gar nicht als deleted markiert -> nichts zu tun
                // (verhindert versehentliches "Mit-Restoren" von Kindern über
                // einen changed_at, der kein Löschzeitpunkt ist)
                return true;
            }

            // Zeitstempel des Löschvorgangs ermitteln:
            // roh aus der DB, nicht aus der fetch()-Aufbereitung von load(),
            // damit der Vergleich exakt auf dem gespeicherten Wert erfolgt - inkl. Mikrosekunden
            $this->restoreTimestamp = $this->rawChangedAt((int)$data[$this->tableName . '_id']);

            // Neuer, für alle wiederhergestellten Datensätze identischer Zeitstempel
            $this->markTimestamp = $this->newTimestamp();

            $ids = $this->collectIds($data, $withChilds, 'restore');
            foreach ($ids as $currentId) {
                $this->restoreOne($currentId);
            }
            return true;
        }, false);
    }

    /**
     * PURGE - Einstieg für das Systemtool (endgültiges Löschen).
     *
     * Entfernt einen Datensatz (und auf Wunsch seinen Teilbaum) unwiderruflich, indem
     * jeder betroffene Datensatz einzeln durch finalDelete() läuft. Kinder werden dabei
     * VOR ihren Eltern verarbeitet, damit jeder Datensatz seinen eigenen hookOnDelete()
     * durchläuft - der Fremdschlüssel record.parent_id löscht mit ON DELETE CASCADE
     * sonst ganze Teilbäume an den Hooks vorbei.
     *
     * Aufrufer: Zeitplaner-Aufgabe trash (abgelaufene Papierkorb-Einträge) und delete() bei
     * Tabellen ohne `deleted`-Feld.
     *
     * @param int  $id          `{tableName}_id`
     * @param bool $withChilds  true:  Kinddatensätze werden mit gelöscht (default)
     *                          false: Kinddatensätze rutschen eine Ebene hoch
     * @return bool             true, wenn erfolgreich
     */
    public function purge(int $id, bool $withChilds = true): bool
    {
        return (bool)$this->transactional(function () use ($id, $withChilds) {

            // SELECT FOR UPDATE, temporäre Arbeitskopie auf Original auflösen
            $data = $this->loadProductive($id);

            $ids = $this->collectIds($data, $withChilds, 'purge');
            foreach ($ids as $currentId) {
                $this->finalDeleteOne($currentId);
            }

            // lft, rgt, depth einmal am Ende richten - nicht je Datensatz
            // (rebuildTreeNow() prueft selbst, ob der Bereich einen Baum fuehrt)
            if ($this->hasParentId && method_exists($this, 'rebuildTreeNow')) {
                $this->rebuildTreeNow((int)($data['area_id'] ?? 0));
            }
            return true;
        }, false);
    }

    /**
     * SOFT-DELETE eines EINZELNEN Datensatzes.
     *
     * Setzt `deleted` = 1, ruft hookOnSoftDelete() und aktualisiert changed_by /
     * changed_at. hookOnDelete() wird bewusst NICHT gerufen: verknüpfte Daten müssen
     * für ein späteres restore() erhalten bleiben.
     *
     * Für Mediendateien gilt: Record::hookOnSoftDelete() schiebt sie in den Papierkorb
     * (var/deleted), die `data_fil`-Einträge bleiben stehen.
     *
     * Der Baum wird nicht angefasst - markierte Datensätze verbleiben vorerst darin.
     *
     * @param int $id  `{tableName}_id` (Arbeitskopie wird auf das Original aufgelöst)
     * @return bool    true, wenn erfolgreich
     */
    public function softDelete(int $id): bool
    {
        if (!$this->hasDeleted) {
            $errorMsg = $this->cms->snippet('error_undelete_not_supported');
            $this->cms->error($errorMsg);
            return false;
        }
        return (bool)$this->transactional(function () use ($id) {
            if ($this->markTimestamp === '') $this->markTimestamp = $this->newTimestamp();
            $this->softDeleteOne($id);
            return true;
        }, false);
    }

    /**
     * WIEDERHERSTELLEN eines EINZELNEN Datensatzes.
     *
     * @param int $id  `{tableName}_id`
     * @return bool    true, wenn erfolgreich
     */
    public function restoreOne(int $id): bool
    {
        if (!$this->hasDeleted) {
            $errorMsg = $this->cms->snippet('error_undelete_not_supported');
            $this->cms->error($errorMsg);
            return false;
        }
        return (bool)$this->transactional(function () use ($id) {
            if ($this->markTimestamp === '') $this->markTimestamp = $this->newTimestamp();
            $this->restoreOneCore($id);
            return true;
        }, false);
    }

    /**
     * ENDGÜLTIGES LÖSCHEN eines EINZELNEN Datensatzes.
     *
     * Ruft hookOnDelete() (dort werden u. a. die Mediendateien entfernt) und löscht
     * anschließend die Datensatz-Zeile samt ihrer Arbeitskopien. Kind-Datensätze werden
     * NICHT beachtet - darum kümmert sich purge(), das sie vorher einzeln abarbeitet.
     *
     * Der Baum wird nicht neu aufgebaut; das erledigt der Aufrufer einmal am Ende.
     *
     * @param int $id  `{tableName}_id` (Arbeitskopie wird auf das Original aufgelöst)
     * @return bool    true, wenn erfolgreich
     */
    public function finalDelete(int $id): bool
    {
        return (bool)$this->transactional(function () use ($id) {
            $areaId = $this->finalDeleteOne($id);
            if ($this->hasParentId && method_exists($this, 'rebuildTreeNow')) {
                $this->rebuildTreeNow($areaId);
            }
            return true;
        }, false);
    }

    /**
     * UNDELETE
     * Rückwärtskompatibler Alias für restore().
     */
    public function undelete(int $id, bool $withChilds = true): bool
    {
        return $this->restore($id, $withChilds);
    }

    // ====================
    // Innereien der Lösch-Funktionen
    // (laufen IMMER innerhalb einer bereits geöffneten Transaktion)

    /**
     * Kern des Soft-Delete für einen Datensatz.
     *
     * @param int $id  `{tableName}_id`
     */
    protected function softDeleteOne(int $id): void
    {
        $data = $this->loadProductive($id);

        // Bereits markierte Datensätze nicht anfassen: sie behalten den changed_at
        // ihres eigenen, früheren Löschvorgangs - sonst würde ein restore() dieses
        // Vorgangs sie fälschlich mit wiederherstellen.
        if (!empty($data['deleted'])) return;

        // Rechte der Benutzergruppe (Rollback bei Verweigerung)
        if (!$this->accessCheck('delete', (int)($data['area_id'] ?? 0), isset($data['owner_id']) ? (int)$data['owner_id'] : null)) {
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // HOOK onSoftDelete
        // --------------------
        // Rollback bei Fehlern
        $this->hookOnSoftDelete($data);

        $this->markDeleted($data, true);
        if ($this->auditActive()) $this->auditWrite('delete', (int)$data[$this->tableName . '_id']);
    }

    /**
     * Kern des Wiederherstellens für einen Datensatz.
     *
     * @param int $id  `{tableName}_id`
     */
    protected function restoreOneCore(int $id): void
    {
        $data = $this->loadProductive($id);
        if (empty($data['deleted'])) return;

        // Rechte der Benutzergruppe: Wiederherstellen = Löschrecht
        if (!$this->accessCheck('delete', (int)($data['area_id'] ?? 0), isset($data['owner_id']) ? (int)$data['owner_id'] : null)) {
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // HOOK onRestore
        // --------------------
        // Rollback bei Fehlern
        $this->hookOnRestore($data);

        $this->markDeleted($data, false);
        if ($this->auditActive()) $this->auditWrite('restore', (int)$data[$this->tableName . '_id']);
    }

    /**
     * Kern des endgültigen Löschens für einen Datensatz.
     *
     * @param int $id  `{tableName}_id`
     * @return int     `area_id` des gelöschten Datensatzes (0, wenn die Tabelle
     *                 keine Bereiche kennt) - der Aufrufer braucht sie, um am Ende
     *                 den Baum genau dieses Bereichs neu zu berechnen
     */
    protected function finalDeleteOne(int $id): int
    {
        $tableName = $this->tableName;
        $data   = $this->loadProductive($id);
        $prodId = (int)$data[$tableName . '_id'];

        // Rechte der Benutzergruppe (Rollback bei Verweigerung)
        if (!$this->accessCheck('delete', (int)($data['area_id'] ?? 0), isset($data['owner_id']) ? (int)$data['owner_id'] : null)) {
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // Audit-Log: der letzte Stand, solange es ihn noch gibt
        $auditBefore = $this->auditActive() ? $this->auditSnapshot($prodId) : null;

        // HOOK onDelete
        // --------------------
        // Läuft VOR dem DELETE: danach sind abhängige Zeilen (z. B. `data_fil`)
        // per FK-Cascade weg und die Mediendateien nicht mehr auffindbar.
        // Rollback bei Fehlern
        $this->hookOnDelete($data);

        // Produktiven Datensatz löschen
        $sql = "DELETE FROM `{$tableName}` WHERE `{$tableName}_id` = ?";
        $this->db->query($sql, 'i', [$prodId]);
        if ($this->db->affected_rows == 0) {
            // Löschung fehlgeschlagen
            $errorMsg = $this->cms->snippet('error_deletion_failed');
            $this->cms->error($errorMsg);
            // Transaktion verwerfen
            throw new \Exception('#' . $errorMsg);
        }
        if ($this->hasTmp) {
            // Temporäre Arbeitskopien zum Datensatz löschen
            $sql = "DELETE FROM `{$tableName}` WHERE `tmp`=1 AND `origin_id` = ?";
            $this->db->query($sql, 'i', [$prodId]);
        }

        // Lücke in der Adjacency-List-Positionierung schließen
        // (nur in Bereichen mit Baum - sonst gibt es keine Geschwisterfolge,
        //  die zu schließen wäre, und der Filter würde bereichsübergreifend greifen)
        if ($this->hasParentId && method_exists($this, 'closePositionGap')
            && $this->isTreeArea((int)($data['area_id'] ?? 0))) {
            $this->closePositionGap($data);
        }

        // HOOK onDeleted
        // --------------------
        // Läuft NACH dem DELETE
        $this->hookOnDeleted($data);

        if ($auditBefore !== null) $this->auditWrite('purge', $prodId, $auditBefore, $data);

        return (int)($data['area_id'] ?? 0);
    }

    /**
     * Ids sammeln, die ein Lösch- bzw. Wiederherstellungsvorgang umfasst.
     *
     * Ohne Baum ist es immer genau eine Id. Mit Baum entscheidet $withChilds:
     *   true   der gesamte Teilbaum
     *   false  nur der Datensatz selbst; seine Kinder werden vorher eine Ebene
     *          hochgezogen (liftChildren), damit sie nicht verwaisen
     *
     * Die REIHENFOLGE ist bedeutsam:
     *   'purge'    Blätter zuerst (rgt aufsteigend) - jeder Datensatz soll seinen
     *              eigenen hookOnDelete() durchlaufen, bevor ein Elternteil ihn per
     *              ON DELETE CASCADE mitreißen könnte
     *   'delete'   Blätter zuerst - gleiche Reihenfolge, damit Hooks von unten nach
     *              oben laufen wie beim endgültigen Löschen
     *   'restore'  Eltern zuerst (lft aufsteigend) - genaue Umkehrung
     *
     * @param array  $data        Datensatz (aus loadProductive())
     * @param bool   $withChilds  Teilbaum einbeziehen?
     * @param string $mode        'delete' | 'restore' | 'purge'
     * @return int[]              Ids in Bearbeitungsreihenfolge
     */
    protected function collectIds(array $data, bool $withChilds, string $mode): array
    {
        $id = (int)$data[$this->tableName . '_id'];

        // Ohne Baum (Tabelle ohne parent_id oder Bereich ohne `parent_id`-Feld)
        // gibt es keinen Teilbaum und keine Kinder, die hochzuziehen wären.
        if (!$this->hasParentId || !method_exists($this, 'subtreeIds')
            || !$this->isTreeArea((int)($data['area_id'] ?? 0))) {
            return [$id];
        }

        if (!$withChilds) {
            // Kinder eine Ebene hochziehen, damit sie nicht verwaisen
            if (method_exists($this, 'liftChildren')) {
                $this->liftChildren($data);
            }
            return [$id];
        }

        return $this->subtreeIds($data, $mode);
    }

    /**
     * Ids für eine KASKADE über ein Filterfeld sammeln.
     *
     * Ersetzt die frühere Massen-Funktion setDeletedBulk(): Statt alle Datensätze eines
     * Filterwerts mit einem UPDATE zu erschlagen, liefert diese Methode nur die Ids -
     * der Aufrufer schickt sie einzeln durch softDelete() / restoreOne() /
     * finalDelete(), sodass je Datensatz die Hooks laufen.
     *
     * Gedacht für hookOnSoftDelete() / hookOnRestore() / hookOnDelete() ANDERER
     * Datasets, z. B. Area -> alle records seiner area_id.
     *
     * @param string $filterField     Spaltenname (muss im Schema definiert sein)
     * @param int    $filterValue     Filterwert (z. B. area_id)
     * @param string $mode            'delete'  nur noch nicht markierte (`deleted` = 0)
     *                                'restore' nur markierte (`deleted` = 1), optional
     *                                          eingeschränkt auf $matchTimestamp
     *                                'purge'   alle
     * @param string $matchTimestamp  nur bei 'restore': changed_at des rückgängig zu
     *                                machenden Löschvorgangs ('' = alle markierten)
     * @param int    $limit           > 0: höchstens so viele Ids liefern (Paketweise
     *                                Abarbeitung, z. B. im Systemtool)
     * @return int[]
     */
    public function cascadeIds(string $filterField, int $filterValue, string $mode = 'delete', string $matchTimestamp = '', int $limit = 0): array
    {
        if (!isset($this->schema[$filterField])) {
            throw new \Exception("Dataset::cascadeIds(): field \"{$filterField}\" is not defined in schema of table \"{$this->tableName}\"");
        }
        $tableName = $this->tableName;
        $idField   = $tableName . '_id';
        $bindTypes = $this->getBindType($this->schema[$filterField]['type'] ?? 'id');
        $values    = [$filterValue];

        $sql = "SELECT `{$idField}` FROM `{$tableName}` WHERE `{$filterField}` = ?";

        // Arbeitskopien nie eigenständig kaskadieren - sie hängen an ihrem Original
        if ($this->hasTmp) $sql .= " AND `tmp` = 0";

        if ($this->hasDeleted) {
            if ($mode === 'delete') {
                $sql .= " AND `deleted` = 0";
            }
            elseif ($mode === 'restore') {
                $sql .= " AND `deleted` = 1";
                if ($matchTimestamp !== '' && $this->hasChangedAt) {
                    $sql .= " AND `changed_at` = ?";
                    $bindTypes .= 's';
                    $values[]   = $matchTimestamp;
                }
            }
        }

        // Bei Bäumen: Blätter zuerst löschen, Eltern zuerst wiederherstellen
        if ($this->hasParentId) {
            $sql .= ($mode === 'restore') ? " ORDER BY `lft` ASC" : " ORDER BY `rgt` ASC";
        }
        if ($limit > 0) $sql .= " LIMIT " . (int)$limit;

        $this->db->pushResult();
        $this->db->query($sql, $bindTypes, $values);
        $ids = $this->db->fetchAll(); // flaches Array
        $this->db->popResult();

        return array_map('intval', $ids);
    }

    /**
     * Vorgangs-Zeitstempel von außen setzen.
     *
     * Nötig für Kaskaden über Tabellengrenzen: Jedes Dataset ist eine eigene Instanz mit
     * eigenem $markTimestamp. Damit die Datensätze eines Bereichs denselben changed_at
     * bekommen wie der Bereich selbst - und restore() sie später als einen Vorgang
     * erkennt - reicht der Aufrufer seinen Zeitstempel durch:
     *
     *     $record->setMarkTimestamp($this->getMarkTimestamp());
     *
     * @param string $timestamp  'Y-m-d H:i:s.v' in UTC, siehe newTimestamp()
     */
    public function setMarkTimestamp(string $timestamp): void
    {
        $this->markTimestamp = $timestamp;
    }

    /**
     * Abgleich-Zeitstempel von außen setzen (Gegenstück zu setMarkTimestamp()).
     * Nur für Kaskaden beim Wiederherstellen.
     */
    public function setRestoreTimestamp(string $timestamp): void
    {
        $this->restoreTimestamp = $timestamp;
    }

    /**
     * Einheitlicher Vorgangs-Zeitstempel in UTC, mit Millisekunden.
     * Alle Datensätze eines Lösch- oder Wiederherstellungsvorgangs bekommen exakt
     * diesen Wert als changed_at - restore() erkennt daran, was zusammengehört.
     */
    protected function newTimestamp(): string
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    /**
     * FETCH
     * Holt die Daten der nächste Zeile als assoziatives Array
     * und bereitet die Daten entsprechend der flags im Table-Schema auf
     * Tabelle Record: erst extendedSchema aufrufen, dann als Parameter mitgeben
     * 
     * @return ?array
     */
    public function fetch(): ?array
    {
        return $this->cms->fetch($this->tableName, $this->schema);
    }

    // Wrapper für CMS::select()
    public function select(array $param = []): array
    {
        return $this->cms->select($param, $this);
    }

    // Save-Hilfsmethoden
    // ====================

    /**
     * Helper zu save(): Aufbereitung und Prüfung eines einzelnen Schema-Feldes.
     * Arbeitet direkt auf $data (Referenz).
     *
     * MEHRSPRACHIGE FELDER (`field`.`multilingual`, nur Tabelle `record`)
     * --------------------
     * Der Wert liegt als Array language_id => Eingabe vor (so kommt er aus der
     * Eingabemaske: data[feld][{language_id}], und so steht er im Arbeitsstand).
     * Geprüft wird je Sprache mit denselben Regeln - die Hauptsprache immer, eine
     * weitere Sprache nur, wenn dort etwas eingegeben wurde: leer heißt dort "nicht
     * übersetzt", und dann gilt beim Schreiben der Fallback aus der Hauptsprache
     * (VirtualFieldsTrait::saveVirtualFields()). Fehler werden unter
     * "{feld}.{language_id}" registriert, die Beschriftung bekommt das Sprachkürzel
     * als Zusatz ("Titel | de").
     *
     * $staged nennt je Feld die Sprachen, deren Werte aus dem Zwischenstand
     * (Arbeitsstand) stammen und bereits geprüft und umgewandelt sind - sie werden
     * nicht erneut geprüft (Format entspräche nicht mehr der Roh-Eingabe).
     *
     * @param array $langCtx     Sprachen der Instanz des Datensatzes, siehe
     *                           languageContext(); leer = einsprachig
     * @param array $staged      feldname => [language_id, ...] bereits geprüfter Sprachen
     * @param int   $instanceId  Instanz des Datensatzes (Reichweite von 'unique'),
     *                           0 = Tabelle ohne instance_id
     * @return bool  true:  Feld ok oder nicht betroffen
     *               false: Fehler (Meldung wurde in Cms::errors eingetragen)
     */
    protected function validateFieldValue(
        string $name,
        array $colDef,
        array &$data,
        array $affectedFields,
        bool $isNew,
        bool $isTmp,
        int $id,
        int $tmpId,
        int $areaId,
        array $langCtx = [],
        array $staged = [],
        int $instanceId = 0
    ): bool {
        $type = $colDef['type'] ?? 'text';
        $empty = in_array($type, $this->cms->arrayTypes, true) ? [] : '';
        if ($this->policyMode) {
            // Lösch-Policy: keine Pflicht, kein Muster, keine Grenzen (siehe $policyMode)
            unset($colDef['min'], $colDef['max'], $colDef['pattern']);
            $colDef['flags'] = array_values(array_intersect((array)($colDef['flags'] ?? []), ['trim', 'unique', 'unique_in_area']));
        }

        // Subfeld-Behandlung (z. B. 'details.width')
        // --------------------
        $isSubField = str_contains($name, '.');
        $parentField = null;
        $subKey = null;
        if ($isSubField) {
            [$parentField, $subKey] = explode('.', $name, 2);
            // Subfeld nur validieren wenn das Parent-Feld geschrieben wird
            if (!in_array($parentField, $affectedFields, true)) return true;
        } else {
            // Feld nur validieren, wenn es geschrieben wird
            if (!in_array($name, $affectedFields, true)) return true;
        }

        // Mehrsprachiges Feld: je Sprache prüfen
        // --------------------
        $isMultilingual = !$isSubField
            && !empty($colDef['multilingual'])
            && !empty($langCtx['language_ids'])
            && in_array($type, $this->cms->multilingualTypes, true);
        if ($isMultilingual) {
            $primaryId = (int)$langCtx['primary_id'];
            $values = $data[$name] ?? [];
            if (!is_array($values)) {
                // Ein einzelner Wert (Importer, CLI) gilt für die Hauptsprache
                $values = ($values === null || $values === '') ? [] : [$primaryId => $values];
            }
            elseif (in_array($type, $this->cms->arrayTypes, true)
                && !empty($values) && !$this->isLanguageKeyed($values, $langCtx['language_ids'])
            ) {
                // json-Feld: ein einfaches Array ist der Wert der Hauptsprache
                $values = [$primaryId => $values];
            }
            $stagedLangs = (array)($staged[$name] ?? []);
            $result = [];
            $ok = true;
            foreach ($langCtx['language_ids'] as $languageId) {
                $languageId = (int)$languageId;
                $value = $values[$languageId] ?? $empty;
                if (in_array($languageId, $stagedLangs, true)) {
                    // bereits geprüft und umgewandelt (Zwischenstand)
                    $result[$languageId] = $value;
                    continue;
                }
                $isEmpty = is_array($value)
                    ? empty(array_filter($value, fn($v) => !is_string($v) || trim($v) !== ''))
                    : trim((string)$value) === '';
                if ($languageId !== $primaryId && $isEmpty) {
                    // Nicht übersetzt - Fallback beim Schreiben
                    $result[$languageId] = $empty;
                    continue;
                }
                $suffix = ' | ' . (string)($langCtx['codes'][$languageId] ?? $languageId);
                if (!$this->validateValue($name, $colDef, $value, $isNew, $isTmp, $id, $tmpId, $areaId, false, $languageId, $suffix, $instanceId)) {
                    $ok = false;
                }
                $result[$languageId] = $value;
            }
            $data[$name] = $result;
            return $ok;
        }

        // Subfeld: Wert aus Parent-Array holen (per Referenz)
        if ($isSubField) {
            // Bei Parent Feld handelt es sich um Json-Daten
            // also wird Array erwartet
            $data[$parentField] = (array)($data[$parentField] ?? []);
            $data[$parentField][$subKey] = $data[$parentField][$subKey] ?? $empty;
        }
        else {
            $data[$name] = $data[$name] ?? $empty;
        }

        // Einheitliche Referenz für die nachfolgende Validierungslogik
        if ($isSubField) {
            $valueRef = &$data[$parentField][$subKey];
        } else {
            $valueRef = &$data[$name];
        }

        return $this->validateValue($name, $colDef, $valueRef, $isNew, $isTmp, $id, $tmpId, $areaId, $isSubField, null, '', $instanceId);
    }

    /**
     * Ist ein Array nach Sprachen indiziert (language_id => Wert)?
     * Unterscheidet bei json-Feldern den Sprach-Container von der Werteliste selbst.
     */
    private function isLanguageKeyed(array $values, array $languageIds): bool
    {
        foreach (array_keys($values) as $key) {
            if (!in_array((int)$key, $languageIds, true) || !is_numeric($key)) return false;
        }
        return true;
    }

    /**
     * Helper zu validateFieldValue(): EINEN Wert aufbereiten und prüfen.
     * Arbeitet direkt auf $valueRef (Referenz) - Typisierung, Post-Processing,
     * Flags, Muster, Grenzwerte, Eindeutigkeit.
     *
     * Refactoring-Hinweis: die unique-Prüfung erfolgt nun je Feld unabhängig davon,
     * ob ein anderes Feld bereits fehlgeschlagen ist (zuvor wurde sie nach dem
     * ersten Fehler für nachfolgende Felder übersprungen; save() bricht ohnehin ab).
     *
     * @param ?int   $languageId   Sprache des Wertes bei mehrsprachigen Feldern (Fehler
     *                             werden dann unter "{name}.{language_id}" registriert)
     * @param string $labelSuffix  Zusatz zur Beschriftung in Fehlermeldungen (" | de")
     * @param int    $instanceId   Instanz des Datensatzes - 'unique' gilt innerhalb
     *                             ihrer Reichweite (InstanceCache::uniqueScope());
     *                             0 = tabellenweit
     * @return bool  true:  Wert ok
     *               false: Fehler (Meldung wurde in Cms::errors eingetragen)
     */
    protected function validateValue(
        string $name,
        array $colDef,
        mixed &$valueRef,
        bool $isNew,
        bool $isTmp,
        int $id,
        int $tmpId,
        int $areaId,
        bool $isSubField = false,
        ?int $languageId = null,
        string $labelSuffix = '',
        int $instanceId = 0
    ): bool {
        $tableName = $this->tableName;
        $type = $colDef['type'] ?? 'text';
        $isDateType = in_array($type, $this->cms->dateTypes, true);
        $displayType = $colDef['display_type'] ?? 'text';
        $label = $colDef['label'] ?? $name;
        if ($labelSuffix !== '') $label = $this->cms->textOrSnippet($label) . $labelSuffix;
        $flags  = (array)($colDef['flags'] ?? []);
        // Schlüssel der Fehlermeldung (Hervorhebung in der Eingabemaske)
        $errName = ($languageId !== null) ? $name . '.' . $languageId : $name;
        // Wert liegt schon im Datenbankformat vor (siehe $storedFormatFields)?
        $isStored = in_array($name, $this->storedFormatFields, true);

        // Typisierung anhand des Tabellen-Schemas
        // --------------------
        if ($type === 'bit') {
            // 0 <- "false", "off", "no" -> false
            // 1 <- "true", "on", "yes"	 -> true
            $filteredValue = filter_var(trim($valueRef ?? ''), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($filteredValue === null) {
                $this->createFieldError('error_invalid_format', $errName, $label);
                return false;
            }
            // aus boolean 0 oder 1 machen
            $valueRef = (int)$filteredValue;
        }
        elseif (in_array($type, $this->cms->arrayTypes, true)) {
            // relation, pointer, file, json, reltable
            $valueRef = (array)($valueRef ?? []);
            // Post processing je Element
            // --------------------
            // Nur bei den Typen, deren Elemente Text sind. 'file' trägt je Element ein
            // Array mit Datei-Metadaten, 'relation'/'pointer' nur Ids - dort gäbe es
            // nichts zu trimmen. Verschachtelte Werte (json-Subfelder) bleiben außen
            // vor, sie werden über ihren eigenen Schema-Eintrag geprüft.
            if (in_array($type, ['json', 'reltable'], true)) {
                $stripTags = in_array('strip_tags', $flags, true);
                $doTrim    = in_array('trim', $flags, true);
                if ($stripTags || $doTrim || $this->sanitizeHtml) {
                    foreach ($valueRef as &$element) {
                        if (!is_string($element)) continue;
                        if ($stripTags) $element = $this->cms->_strip_tags($element);
                        elseif ($this->sanitizeHtml) $element = $this->cms->_sanitize_html($element);
                        if ($doTrim)    $element = trim($element);
                    }
                    unset($element);
                }
            }
        }
        else {
            // Wenn unerwartetes Array-Format -> Fehler
            if (is_array($valueRef ?? '')) {
                // Fehler
                $this->createFieldError('error_unexpected_array_format', $errName, $label);
                return false;
            }
            $valueRef = (string)($valueRef ?? ''); // null bei 'integer' -> ''
            // Post processing
            // --------------------
            // 'strip_tags'
            if (in_array('strip_tags', $flags, true)) {
                $valueRef = $this->cms->_strip_tags($valueRef);
            }
            // Script-Injektion (siehe $sanitizeHtml) - nicht bei Passwörtern: die
            // Roheingabe wird gehasht, ein Hash darf nicht von einer Bereinigung abhängen
            elseif ($this->sanitizeHtml && $type !== 'password') {
                $valueRef = $this->cms->_sanitize_html($valueRef);
            }
            // 'trim'
            if (in_array('trim', $flags, true)
                || in_array('name', $flags, true)
                || in_array($type, $this->cms->numericTypes, true)) {
                $valueRef = trim($valueRef);
            }
            // 'email'
            if ($displayType == 'email') {
                $valueRef = mb_strtolower(trim($valueRef));
            }
        }

        if ($type === 'id') {
            $valueRef = (int)($valueRef);
        }
        elseif ($type === 'integer') {
            $valueRef = trim($valueRef);
            if ($valueRef === '') $valueRef = '0'; // '' bei integer => '0'
        }

        if (in_array($type, ['float', 'decimal', 'currency', 'integer'], true) && !$isStored) {
            // Zahl: in Standardformat umwandeln
            $fmt = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::DECIMAL);
            $decimalSep = $fmt->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
            $groupingSep = $fmt->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
            $minusSign = $fmt->getSymbol(\NumberFormatter::MINUS_SIGN_SYMBOL);
            $formattedValue = $valueRef;
            // 1. Tausendertrenner entfernen ('' würde zu parse error führen!)
            if ($groupingSep !== '') {
                $formattedValue = str_replace($groupingSep, '', $formattedValue);
            }
            // 2. Dezimaltrenner durch den SQL-Punkt ersetzen
            $formattedValue = str_replace($decimalSep, '.', $formattedValue);
            // 3. Minuszeichen der Locale (z. B. sv_SE: U+2212, ggf. mit Richtungszeichen)
            //    und das typografische Minus durch '-' ersetzen - negative Werte bleiben
            //    erhalten und werden nur über min/max aussortiert
            $formattedValue = str_replace([$minusSign, "\u{2212}"], '-', $formattedValue);
            // 4. Ziffern anderer Schriften (ar_SA: ١٢٣, bn_BD: ১২৩ - so zeigt _formatFloat()
            //    die Zahl an) in 0-9 wandeln, sonst fielen sie in Schritt 5 weg
            $formattedValue = preg_replace_callback('/\p{Nd}/u',
                fn($digit) => (string)\IntlChar::charDigitValue($digit[0]), $formattedValue) ?? $formattedValue;
            // 5. Verbleibende Zeichen entfernen, die keine Ziffern, Punkt oder Minus sind
            //    (die Formatprüfung unten verlangt das Minus vor der Zahl)
            $formattedValue = preg_replace('/[^0-9.\-]/', '', $formattedValue);
            $valueRef = trim($formattedValue); // string
        }

        if ($type === 'integer') {
            // Nach der Umwandlung dürfen nur Ziffern übrig bleiben
            // (Datenbankformat: die Zahl selbst, ggf. mit Vorzeichen)
            if (!$isStored && !preg_match("/^-?[0-9]+\$/", $valueRef)) {
                $this->createFieldError('error_invalid_format', $errName, $label, $valueRef);
                return false;
            }
            
            $valueRef = (int)$valueRef;
        }
        elseif ($type === 'currency' && $isStored) {
            // Datenbankformat: bereits in Cent
            $valueRef = (int)$valueRef;
        }
        elseif ($type === 'currency') {
            // Währungsbetrag muss mit genau 2 Nachkommastellen angegeben werden
            if ($valueRef != '' && !preg_match("/^-?[0-9]+\\.[0-9]{2}\$/", $valueRef)) {
                $this->createFieldError('error_currency_format', $errName, $label, $valueRef);
                return false;
            }
            else {
                // runden statt abschneiden: 19.99 * 100 ergibt als float 1998.99999...
                $valueRef = (int)round((float)$valueRef * 100);
            }
        }
        elseif (in_array($type, ['float', 'decimal'], true) && !$isStored && $valueRef !== ''
            && !preg_match('/^-?([0-9]+(\.[0-9]*)?|\.[0-9]+)$/', $valueRef)
        ) {
            // z. B. "1.2.3" oder "5-3"
            $this->createFieldError('error_invalid_format', $errName, $label, $valueRef);
            return false;
        }

        // Datum und Zeit in ISO-Format umwandeln
        // Erlaubt:
        // - Leerwert
        // - Datum + Zeit in User-Locale oder ISO-Format
        // - CMS-Datum-Zeit-Funktion z. B. "now" oder "today+1d"
        // - SQL-Datum-Zeit-Funktion aus Default Wert
        // (Datenbankformat: bereits ISO bzw. UTC - keine Umwandlung)
        if ($isDateType && $valueRef != '' && !$isStored) {
            if (str_ends_with($valueRef, '()') && in_array(strtoupper($valueRef), $this->cms->funcWhitelist, true)) {
                // SQL-Datums-Zeit-Funktion wird beim Speichern umgewandelt
            }
            elseif (preg_match($this->dateExpression, $valueRef, $matches)) {
                // Eingegebene CMS-Datums-Zeit-Funktion jetzt ersetzen
                $valueRef = $this->parseDateExpression($matches);
                if ($type === 'timestampl') {
                    // parseDateExpression() rechnet in der Zeitzone des Users -
                    // gespeichert wird in UTC (wie bei der Datumseingabe unten)
                    $date = new \DateTime($valueRef, new \DateTimeZone($this->cms->userTZ));
                    $date->setTimezone(new \DateTimeZone('UTC'));
                    $valueRef = $date->format('Y-m-d H:i:s');
                }
            }
            else {
                // Eingegebenes Datum umwandeln
                $iso = $this->dateInputToIso($valueRef);
                if ($iso !== null) {
                    $valueRef = $iso;
                    if ($type === 'timestampl') {
                        // Datum in UTC umwandeln
                        $date = new \DateTime($valueRef, new \DateTimeZone($this->cms->userTZ));
                        $date->setTimezone(new \DateTimeZone('UTC'));
                        $valueRef = $date->format('Y-m-d H:i:s');
                    }
                } else {
                    // Fehlerbehandlung: Ungültiges Datum
                    $this->createFieldError('record.error_date_format', $errName, $label);
                    return false;
                }
            }
        }
        
        // Uhrzeut Umwandeln
        // Erlaubt
        // - Leerwert
        // - Uhrzeit im User-Locale oder ISO Format
        if ($type === 'time' && (string)$valueRef != '' && !$isStored) {
            $iso = $this->timeInputToIso((string)$valueRef);
            if ($iso !== null) {
                $valueRef = $iso;
            } else {
                // Fehlerbehandlung: Ungültige Zeit
                $this->createFieldError('record.error_time_format', $errName, $label);
                return false;
            }
        }

        // 'not_empty' Flag ("0" wird auch akzeptiert)
        // --------------------
        // Bei Array-Typen zählt nicht der Wert, sondern die ANZAHL der Einträge -
        // das Flag wird deshalb weiter unten als "mindestens ein Eintrag" (min = 1)
        // ausgewertet, siehe $isArrayType dort. Für die vom Redakteur angelegten
        // Felder erledigt Field::hookOnSave() dasselbe schon beim Speichern der
        // Felddefinition; für die fest im Code stehenden Schemata (z. B. Instance)
        // gibt es diesen Weg nicht - deshalb die Auswertung hier.
        if (in_array('not_empty', $flags, true)
            && !in_array($type, $this->cms->arrayTypes, true)
        ) {
            if ($valueRef === '') {
                if ($displayType == 'password' && !$isNew) {
                    // Sonderfall Passwort: darf bei Update leer bleiben = bisheriges wird behalten
                } else {
                    $this->createFieldError('error_value_mandatory', $errName, $label);
                    // keine weitere Fehlermeldung für dieses Feld
                    return false;
                }
            }
        }

        // Die folgenden Prüfungen arbeiten auf einem Textwert. Bei Array-Typen gibt es
        // den nicht - dort zählen ausschließlich Anzahl-Grenzen (min/max, s. u.).
        $isArrayType = in_array($type, $this->cms->arrayTypes, true);

        // 'not_zero' Flag Wert darf nicht '0' sein
        // --------------------
        if (!$isArrayType && in_array('not_zero', $flags, true)) {
            if (preg_match('/^0+(\\.0*)?$/', $valueRef)) {
                $this->createFieldError('error_requirements', $errName, $label);
                return false;
            }
        }
        // pattern
        // --------------------
        $pattern = '';
        // name pattern
        if (!$isArrayType && in_array('name', $flags, true)) {
            // Mindestens zwei Zeichen
            if (mb_strlen($valueRef) < 2) {
                $this->createFieldError('error_name_length', $errName, $label);
                // keine weitere Fehlermeldung für dieses Feld
                return false;
            }
            elseif (!preg_match('/^[a-z][a-z0-9_]+$/', $valueRef)) {
                $this->createFieldError('error_name_format', $errName, $label);
                // keine weitere Fehlermeldung für dieses Feld
                return false;
            }
        }
        // e-mail pattern
        if ($displayType == 'email') {
            $pattern = '/^[^@]+@[^@]+\\.[a-z]+$/';
        }
        // pattern from field definiton
        $colPattern = (string) ($colDef['pattern'] ?? '');
        if ($colPattern !== '') {
            $quotedPattern = str_replace('~', '\\~', $colPattern);
            $pattern = '~^(?:' . $quotedPattern . ')$~u';
        }
        // check value against pattern
        if (!$isArrayType && $pattern !== '' && $valueRef !== '' && !$this->policyMode && !preg_match($pattern, $valueRef)) {
            $this->createFieldError('error_invalid_format', $errName, $label);
            // keine weitere Fehlermeldung für dieses Feld
            return false;
        }

        // Leere Datum-/Uhrzeit-Eingabe
        // --------------------
        if ($isDateType && $valueRef == '') {                    
            $default = $colDef['default'] ?? '' ?: self::DATE_MIN;
            if (str_starts_with($default, 'now') || str_starts_with($default, 'today')) {
                // manuell rausgelöschte CMS-Datumsfunktion nicht erneut einsetzen
                $default = self::DATE_MIN;
            }
            $valueRef = $default;
        }
        elseif ($type == 'time' && $valueRef == '') {
            $valueRef = '00:00';
        }

        // min / max
        // --------------------
        $min   = $colDef['min'] ?? '';
        $max   = $colDef['max'] ?? '';
        $minOp = $colDef['min_op'] ?? '>=';
        if ($isArrayType && in_array('not_empty', $flags, true)) {
            // Pflichtfeld heißt bei einem Array: mindestens ein Eintrag
            $min = max((int)$min, 1);
            $minOp = '>=';
        }
        if ($displayType === 'password' && !$isNew && $valueRef == '') {
            // Sondefall: Passwort darf bei Datensatz-Änderung leer bleiben
            $min = '';
        }

        // Passwort-Richtlinie (security.password_policy, Extension Password am Feld;
        // 16-sicherheit.md §12) - nur für eine Eingabe, nicht für einen Hash aus
        // Arbeitsstand oder Export ($prehashedFields)
        if ($displayType === 'password' && is_string($valueRef) && $valueRef !== ''
            && !in_array($name, $this->prehashedFields, true)
        ) {
            $policy = $this->container->get(PasswordPolicy::class);
            $rules = $policy->rulesFor($colDef);
            $failed = $rules !== null ? $policy->failed($valueRef, $rules) : [];
            if ($failed) {
                $this->createFieldError('error_password_policy', $errName, $label, $policy->message($failed, $rules));
                return false;
            }
        }

        if ($isDateType) {
            if (str_ends_with((string)$valueRef, '()')
                && in_array(strtoupper((string)$valueRef), $this->cms->funcWhitelist, true)
            ) {
                // SQL-Datumsfunktion (NOW(), UTC_TIMESTAMP(), ...): ein Ausdruck, kein
                // Wert - der Vergleich fände hier nur den Funktionsnamen vor.
                $min = '';
                $max = '';
            }
            // Datumsfelder haben immer eine Untergrenze - siehe DATE_MIN.
            // Ein am Feld hinterlegtes Minimum gilt, sofern es NICHT darunter liegt;
            // andernfalls greift DATE_MIN. Die Prüfung selbst macht die Schleife unten,
            // die Meldung kommt damit aus derselben Quelle wie jedes andere Minimum
            // ("Der Wert muss mindestens {value} betragen", bei timestampl mit dem
            // Zusatz "(UTC)").
            elseif ($this->dateCompare($min, self::DATE_MIN) < 0) {
                $min = self::DATE_MIN;
                $minOp = '>=';
            }
        }

        $testValue = [$min, $max];
        $testOp = [
            $minOp,
            $colDef['max_op'] ?? '<='
        ];
        $chosenNumber = 0;

        if (in_array($type, $this->cms->relationTypes, true)) {
            // Verknüpfungen erst im zweiten Durchlauf (Import): jetzt nicht zählen
            if ($this->deferRelationChecks) return true;
            // Relations zählen
            $fieldId = (int)$colDef['field_id'];
            $srcId = $isTmp ? $tmpId : $id;
            $sql = "SELECT COUNT(*) FROM `data_rel` WHERE `field_id` = ? AND `src_id`= ?";
            $this->db->query($sql, 'ii', [$fieldId, $srcId]);
            $chosenNumber = (int)$this->db->fetchNum()[0];
        }

        foreach ($testValue as $key => $val) {
            if ($val === '') {
                // Minimum / Maximum nicht angegeben
                continue;
            }
            if ($type === 'integer') {
                // Ganzzahlenwert
                $result = ((int)$valueRef <=> (int)$val);
            }
            elseif ($type === 'float') {
                // Fließkommawert
                $result = ((float)$valueRef <=> (float)$val);
            }
            elseif ($type === 'decimal') {
                // Dezimalwert
                $precision = 10;
                $result = bccomp($valueRef, $val, $precision);
            }
            elseif ($type === 'currency') {
                // 12.56 (Format im min/max Feld muss technisches Format mit Dezimalpunkt haben)
                // - der Wert liegt bereits in Cent vor, die Grenze also ebenfalls umrechnen
                $result = ((int)$valueRef <=> (int)round((float)$val * 100));
            }
            elseif (in_array($type, $this->cms->dateTypes, true)) {
                // Datum vergleichen (ISO-Format)
                // 'timestampl': Datum wurde in UTC-Zeit umgerechnet -> min max sollte auch UTC sein
                // ansonsten: PHP-Zeitzone / Wandzeit
                // vergleiche gleich lange Angaben
                $dateRef = mb_substr($valueRef, 0, mb_strlen($val));
                $result = ($dateRef <=> (string)$val);
            }
            elseif (in_array($type, $this->cms->relationTypes, true)) {
                // Anzahl verknüpfungen ausgehend
                $result = ((int)$chosenNumber <=> (int)$val);
            }
            elseif ($type === 'json' || $type === 'reltable') {
                // Anzahl Einträge eines Arrays
                // ('reltable' wird wie 'json' behandelt - der Inhalt ist ebenfalls ein
                //  Array, es landet nur in einer eigenen Relation-Tabelle)
                $arrayValue = (array) ($valueRef ?? []);
                $count = count($arrayValue);
                $result = ($count <=> (int)$val);
            }
            elseif (in_array($type, $this->cms->textTypes, true)) {
                // Zeichenlänge
                $stringValue = (string)($valueRef ?? '');
                $strlen = mb_strlen($stringValue);
                $result = ($strlen <=> (int)$val);
            }
            else {
                // nicht unterstützter Typ
                continue;
            }

            $op = $testOp[$key];
            if ($this->_hasCompareError($key, $op, $type, $errName, $label, $val, $result)) {
                // Fehlermeldung wurde in Cms::errors eingetragen
                // keine weitere Prüfungen für dieses Feld
                return false;
            }
        }

        // unique / unique_in_area
        // --------------------
        // Nur für reguläre Felder sinnvoll, nicht für Subfelder
        // 'reltable' ist davon ausgenommen: das Feld hat keine Spalte, die sich
        // vergleichen ließe (Eindeutigkeit gehört dort in die Relation-Tabelle).
        if (!$isSubField && !in_array($type, $this->cms->noColumnTypes, true)) {
            if (in_array('unique', $flags, true)
                || in_array('unique_in_area', $flags, true)
            ) {
                $inArea = in_array('unique_in_area', $flags, true) && $this->hasAreaId;

                // Virtuelles Feld? Dann steht der Inhalt nicht als Spalte in `record`,
                // sondern in der data_*-Tabelle des Feldtyps - ein "WHERE `slug` = ?"
                // auf `record` läuft dort in "Unknown column".
                // Metafelder (Cms::$schema) sind echte Spalten und laufen wie bisher.
                $isVirtual = ($tableName === 'record') && empty($colDef['meta']);

                if ($isVirtual) {
                    // Slug: eindeutig sein muss der URL-Pfad [präfix/]slug, nicht der
                    // Wert allein - Slug-Präfixe der Bereiche, Record::slugPathOk()
                    // (registriert seine Fehlermeldung selbst)
                    if ($name === 'slug' && method_exists($this, 'slugPathOk')) {
                        return $this->slugPathOk((string)$valueRef, $colDef, $errName, (string)$label, $id, $areaId, $languageId, $instanceId);
                    }
                    if (!$this->uniqueVirtualOk($name, $colDef, $type, (string)$valueRef, $inArea, $id, $areaId, $languageId, $instanceId)) {
                        $this->createFieldError('error_unique_value', $errName, $label);
                        return false;
                    }
                    return true;
                }

                $sql = "SELECT 1 FROM `$tableName`"
                    ." WHERE `$name` = ? AND `{$tableName}_id` != ?";
                $sqlTypes = $this->getBindType($type) . 'i';
                $sqlValues = [$valueRef, $id];
                // 'unique_in_area' erfordert area_id im Schema, ansonsten als 'unique' behandeln
                if ($inArea) {
                    $sql .= " AND `area_id` =?";
                    $sqlTypes .= 'i';
                    $sqlValues[] = $areaId;
                }
                else {
                    // 'unique' bei Tabellen mit instance_id: innerhalb der Instanz und
                    // der mit ihr zusammengeschalteten Instanzen, nicht tabellenweit
                    // (ein Bereich gehört zu genau einer Instanz - unique_in_area ist
                    // damit ohnehin enger)
                    $sql .= $this->uniqueInstanceCondition($instanceId);
                }
                // nur über produktive Datensätze suchen
                // (auch Tabellen nur mit hasDeleted berücksichtigen)
                if ($this->hasTmp)     $sql .= " AND `tmp` = 0";
                if ($this->hasDeleted) $sql .= " AND `deleted` = 0";
                $sql .= " LIMIT 1";
                $this->db->query($sql, $sqlTypes, $sqlValues);
                if ($this->db->num_rows > 0) {
                    $this->createFieldError('error_unique_value', $errName, $label);
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Eindeutigkeit eines VIRTUELLEN Feldes prüfen (Tabelle `record`).
     *
     * Der Inhalt liegt in data_{suffix}, nicht als Spalte in `record`. Der Join auf
     * `record` schließt Arbeitskopien und Gelöschte aus - der Datensatz, der gerade
     * gespeichert wird, fällt dadurch schon heraus, solange er temporär ist;
     * $excludeId deckt den Fall ab, dass er bereits produktiv ist.
     *
     * Reichweite:
     *     unique_in_area   je field_id - eine Felddefinition gehört zu genau einem
     *                      Bereich, der area_id-Vergleich ist trotzdem gesetzt,
     *                      damit die Absicht im Statement lesbar bleibt
     *     unique           je fieldname_id - derselbe Feldname über alle Bereiche
     *                      hinweg (`fieldname` ordnet Name -> Id systemweit zu)
     *
     * Verglichen wird die Spalte `data` - bei `encrypted` stattdessen `hash`: encrypt()
     * zieht je Aufruf einen neuen IV, derselbe Klartext ergibt also nie denselben
     * Geheimtext. Der HMAC daneben ist dagegen deterministisch (siehe Crypt).
     *
     * NICHT geprüft werden Verweis- und Dateifelder:
     *   - relation/pointer liegen als Kantenliste in data_rel (src_id/dest_id), eine
     *     Gleichheitsprüfung auf einer `data`-Spalte gibt es dort nicht
     *   - file hat keine sinnvolle Gleichheit auf Feldebene
     * Für diese Typen gilt das Flag als erfüllt - siehe docs/developers/09-offene-punkte.md.
     *
     * @param string $name       Feldname (nur für die Fehlermeldung des Aufrufers)
     * @param array  $colDef     Felddefinition aus virtualSchema() (braucht field_id)
     * @param string $type       Feldtyp
     * @param string $value      zu prüfender Wert
     * @param bool   $inArea     true: nur innerhalb des Bereichs prüfen
     * @param int    $excludeId  record_id, die nicht als Treffer zählt
     * @param int    $areaId     Bereich des Datensatzes
     * @param ?int   $languageId Sprache des Wertes bei mehrsprachigen Feldern
     * @param int    $instanceId Instanz des Datensatzes: 'unique' gilt innerhalb
     *                           ihrer Reichweite (InstanceCache::uniqueScope())
     * @return bool              true: Wert ist eindeutig (oder nicht prüfbar)
     */
    protected function uniqueVirtualOk(
        string $name,
        array $colDef,
        string $type,
        string $value,
        bool $inArea,
        int $excludeId,
        int $areaId,
        ?int $languageId = null,
        int $instanceId = 0
    ): bool {
        if (in_array($type, $this->cms->relationTypes, true)) return true;
        if ($type === 'file') return true;

        $suffix = $this->cms->dataSuffix[$type] ?? '';
        $fieldId = (int)($colDef['field_id'] ?? 0);
        if ($suffix === '' || $fieldId < 1) return true;

        // Verschlüsselte Felder über den HMAC vergleichen, nicht über den Geheimtext
        // (gleicher Aufruf wie beim Schreiben in VirtualFieldsTrait::saveVirtualFields():
        // normalisiert, "Anna@…" ist also eine Dublette von "anna@…")
        $column   = ($type === 'encrypted') ? 'hash' : 'data';
        $needle   = ($type === 'encrypted') ? $this->cms->encryptedHash($value) : $value;

        $sql = "SELECT 1 FROM `data_{$suffix}` `d`"
             . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
             . " AND `r`.`tmp` = 0 AND `r`.`deleted` = 0"
             . " WHERE `d`.`{$column}` = ? AND `d`.`record_id` != ?";
        $sqlTypes  = $this->getBindType($type) . 'i';
        $sqlValues = [$needle, $excludeId];

        // Mehrsprachiges Feld: Eindeutigkeit gilt je Sprache. Die Zeilen der übrigen
        // Sprachen tragen Fallback-Werte der Hauptsprache und zählen dort nicht als
        // Dublette. (Einsprachige Felder haben in allen Sprachen denselben Wert -
        // ein Treffer in irgendeiner Sprache ist dann eine echte Dublette.)
        if ($languageId !== null) {
            $sql .= " AND `d`.`language_id` = ?";
            $sqlTypes .= 'i';
            $sqlValues[] = $languageId;
        }

        if ($inArea) {
            $sql .= " AND `d`.`field_id` = ? AND `r`.`area_id` = ?";
            $sqlTypes .= 'ii';
            $sqlValues[] = $fieldId;
            $sqlValues[] = $areaId;
        }
        else {
            // über alle Bereiche: die fieldname_id, die sich alle gleichnamigen Felder
            // teilen. Sie steht nicht in `field`, sondern in `fieldname` (Name -> Id).
            // Begrenzt auf die Instanz des Datensatzes und die mit ihr
            // zusammengeschalteten Instanzen - nur dort muss der Wert (z. B. ein
            // Slug) eindeutig sein, siehe InstanceCache::uniqueScope().
            $resolved = $this->cms->resolveVirtualField($name);
            if ($resolved === null) return true;
            $sql .= " AND `d`.`fieldname_id` = ?";
            $sqlTypes .= 'i';
            $sqlValues[] = $resolved['fieldname_id'];
            $sql .= $this->uniqueInstanceCondition($instanceId, 'r');
        }
        $sql .= " LIMIT 1";

        $this->db->query($sql, $sqlTypes, $sqlValues);
        return ($this->db->num_rows < 1);
    }

    /**
     * SQL-Bedingung für die Reichweite eines 'unique'-Wertes: die Instanz des
     * Datensatzes samt lesbaren und lesenden Instanzen (InstanceCache::uniqueScope()).
     *
     * Leer bei Tabellen ohne instance_id bzw. ohne bekannte Instanz - dann gilt
     * 'unique' wie bisher tabellenweit. Nur Zahlen, geht literal in den Query.
     *
     * @param int    $instanceId  Instanz des Datensatzes, 0 = keine Einschränkung
     * @param string $alias       Tabellen-Alias der Spalte instance_id ('' = ohne)
     */
    protected function uniqueInstanceCondition(int $instanceId, string $alias = ''): string
    {
        if ($instanceId < 1) return '';
        $scope = $this->container->get(InstanceCache::class)->uniqueScope($instanceId);
        if (empty($scope)) return '';
        $column = ($alias !== '' ? "`{$alias}`." : '') . '`instance_id`';
        return " AND {$column} IN (" . implode(',', array_map('intval', $scope)) . ")";
    }

    /**
     * Helper zu save(): SET-Ausdrücke, Bind-Typen und Werte für das
     * INSERT/UPDATE-Statement zusammenstellen.
     *
     * Felder vom Typ 'reltable' werden dabei übergangen - sie haben keine eigene
     * Spalte (siehe Cms::$dataSuffix). Ihre Inhalte schreibt hookOnSaved() der
     * Dataset-Kindklasse in die zugehörige Relation-Tabelle.
     *
     * @return array             [$colExpressions, $bindTypes, $values]
     */
    protected function buildColumnExpressions(
        array $schemaComplete,
        array $affectedFields,
        array &$data,
        array $previousData,
        bool $isNew,
        bool $isNewInTree = false,
        bool $treeActive = false,
        bool $positionActive = true
    ): array {
        $tableName = $this->tableName;
        $idField = $tableName . '_id';
        $sessionAdminId = $this->adminIdForDb();
        $colExpressions = [];
        $bindTypes = '';
        $values = [];

        foreach ($affectedFields as $name) {
            if ($name == $idField) continue;
            if ($name === 'created_at') {
                $colExpressions[] = "`created_at` = UTC_TIMESTAMP()";
                continue;
            }
            if ($name === 'created_by') {
                // Ohne Backend-Session gilt ein mitgegebener Wert (Frontend-User:
                // der Owner aus CMS:USER_INPUT / USER_REGISTER, siehe RecordUser)
                $createdBy = $sessionAdminId ?? ((int)($data['created_by'] ?? 0) ?: null);
                $colExpressions[] = "`created_by` = ?";
                $bindTypes .= 'i';
                $values[] = $createdBy;
                continue;
            }
            if ($name === 'changed_at') {
                $colExpressions[] = "`changed_at` = UTC_TIMESTAMP()";
                continue;
            }
            if ($name === 'changed_by') {
                $colExpressions[] = "`changed_by` = ?";
                $bindTypes .= 'i';
                $values[] = $sessionAdminId;
                continue;
            }
            if ($name === 'tmp') {
                continue; // wird weiter unten behandelt
            }
            // Tree-Felder
            // Maßgeblich ist $isNewInTree, nicht $isNew: eine produktiv gehende
            // Arbeitskopie ohne Original ist im Baum neu, obwohl die Zeile schon
            // existiert und deshalb per UPDATE geschrieben wird. createInTree() hat
            // ihre Baum-Felder nur in $data gesetzt - sie müssen hier mit.
            if ($this->hasParentId) {
                if (!$treeActive) {
                    // Bereich OHNE Baum: die Baumfelder bleiben neutral. Sie werden
                    // ausdrücklich geschrieben und nicht nur ausgelassen - so wird
                    // ein Datensatz, der aus einem Baum-Bereich hierher verschoben
                    // wurde, dabei gleich mit aufgeräumt.
                    if ($name === 'parent_id') {
                        $colExpressions[] = "`parent_id` = NULL";
                        continue;
                    }
                    if (in_array($name, ['lft', 'rgt', 'depth'], true)) {
                        $colExpressions[] = "`{$name}` = 0";
                        continue;
                    }
                    if ($name === 'parent_ids') {
                        $colExpressions[] = "`parent_ids` = '[]'";
                        continue;
                    }
                    if ($name === 'position' && !$positionActive) {
                        // Keine Handsortierung: Wert der Datenbank überlassen
                        continue;
                    }
                }
                elseif ($isNewInTree) {
                    if ($name === 'parent_id' && (int)$data['parent_id'] === 0) {
                        // Sonderfall NULL abfangen
                        $colExpressions[] = "`parent_id` = NULL";
                        continue;
                    }
                }
                else {
                    // UPDATE
                    if (in_array($name, ['parent_id', 'position', 'lft', 'rgt', 'depth'], true)) {
                        // Diese Felder wurden bereits mittels updateInTree() geschrieben
                        continue;
                    }
                }
            }
            $colDef = $schemaComplete[$name] ?? [];
            $type = $colDef['type'] ?? 'text';
            // 'reltable': Feld ohne eigene Spalte. Der Inhalt gehoert in eine eigene
            // Relation-Tabelle und wird von hookOnSaved() der Dataset-Kindklasse
            // geschrieben - hier darf nichts ins UPDATE/INSERT wandern, die Spalte
            // gibt es nicht. Geprueft wurde das Feld bereits in validateFieldValue().
            if (in_array($type, $this->cms->noColumnTypes, true)) continue;
            $isDateType = in_array($type, $this->cms->dateTypes, true);
            $displayType = $colDef['display_type'] ?? 'text';

            if (
                $tableName === 'record'
                && !key_exists($name, $this->schema)
            ) {
                // 1. Inhalt für virtuelles Feld
                // --------------------
                // Wird nach dem Hauptquery von saveVirtualFields() geschrieben, wenn
                // die Id auch nach INSERT fest steht - samt JSON-Kodierung und
                // Passwort-Hash je Sprache. Hier nichts anfassen: bei mehrsprachigen
                // Feldern ist $data[$name] ein Array language_id => Wert.
                continue;
            }

            $valueRef = &$data[$name];

            // JSON-enkodieren
            if ($type === 'json') {
                $valueRef = json_encode($valueRef, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if ($valueRef == '""') $valueRef = '';
            }

            // hashen
            if ($displayType == 'password') {
                if ($valueRef == '' && !$isNew) {
                    // Ausnahme: bei Passwortänderung bedeutet leer: Wert behalten
                    continue;
                }
                // Passwort hashen (Salt im Wert, 64 Zeichen - Crypt::passwordHash())
                $valueRef = $this->crypt->passwordHash((string) $valueRef);
            }

            {
                // 2. Meta Field (data in table $tableName)
                // --------------------
                // verschlüsseln
                if ($type === 'encrypted') {
                    $valueRef = $this->crypt->encrypt((string) $valueRef, '', true); // binary
                }
                // fulltext
                if ($type === 'fulltext') {
                    $sourceFields = (array)($colDef['source_fields'] ?? []);
                    $ft = [];
                    foreach ($sourceFields as $textField) {
                        // Sicherheitsprüfung:
                        if (($colDef['type'] ?? '') == 'encrypted') continue;
                        // Text parsen und sammeln
                        if (in_array($textField, $affectedFields, true)) {
                            // in $data enthalten
                            $ft[] = $this->cms->indexText((string)($data[$textField] ?? ''));
                        } else {
                            // nicht in data enthalten, ggf aber in $previousData
                            $ft[] = $this->cms->indexText((string)($previousData[$textField] ?? ''));
                        }
                    }
                    $valueRef = implode("\n", $ft);
                }
                if ($type === 'id' && (int)$valueRef === 0) {
                    $colExpressions[] = "`$name` = NULL";
                }
                elseif ($isDateType && str_ends_with($valueRef, '()') && in_array(strtoupper($valueRef), $this->cms->funcWhitelist, true)) {
                    // SQL-Datumsfunktion
                    $colExpressions[] = "`$name` = $valueRef";
                }
                else {
                    // Werte getrennt vom Statement übertragen
                    $colExpressions[] = "`$name` = ?";
                    $bindTypes .= $this->getBindType($type);
                    $values[] = $valueRef;
                }
            }
        }
        if ($this->hasTmp) {
            // Datensatz geht immer produktiv beim Schreiben
            $colExpressions[] = "`tmp` = 0";
        }
        return [$colExpressions, $bindTypes, $values];
    }

    /**
     * Sprachen der Instanz, zu der ein Inhaltsbereich gehört - der Kontext für
     * mehrsprachige Felder (nur Tabelle `record`).
     *
     * @param int $areaId
     * @return array  [] bei anderen Tabellen, sonst
     *                'instance_id'   Instanz des Bereichs
     *                'language_ids'  Sprachen der Instanz, Hauptsprache zuerst
     *                'primary_id'    Hauptsprache
     *                'codes'         language_id => Kürzel (für Beschriftungen)
     */
    protected function languageContext(int $areaId): array
    {
        if ($this->tableName !== 'record' || $areaId < 1) return [];

        $instanceId = $this->areaInstanceId($areaId);

        $instanceCache = $this->container->get(InstanceCache::class);
        $languages = $instanceCache->languages($instanceId);
        $ids   = [];
        $codes = [];
        foreach ($languages as $languageId => $language) {
            $ids[] = (int)$languageId;
            $codes[(int)$languageId] = (string)($language['code'] ?? '');
        }
        if (empty($ids)) {
            // Instanz ohne (gültige) Hauptsprache: es bleibt bei deren Id
            $primaryId = $instanceCache->primaryLanguageId($instanceId);
            $ids = [$primaryId];
            $codes = [$primaryId => ''];
        }
        return [
            'instance_id'  => $instanceId,
            'language_ids' => $ids,
            'primary_id'   => $ids[0],
            'codes'        => $codes,
        ];
    }

    // Delete-Hilfsmethoden
    // ====================

    /**
     * Helper zu delete()/restore(): Datensatz mit Zeilensperre laden und
     * eine temporäre Arbeitskopie ggf. auf den produktiven Datensatz auflösen.
     * Nur innerhalb einer laufenden Transaktion aufrufen.
     *
     * @param int $id      `{tableName}_id` (auch Id einer Arbeitskopie möglich)
     * @return array       Inhalte des produktiven Datensatzes
     * @throws \Exception  wenn Datensatz nicht gefunden
     */
    protected function loadProductive(int $id): array
    {
        $idField = $this->tableName . '_id';
        // SELECT FOR UPDATE
        if (!$data = $this->load($id, $idField, true)) {
            // Datensatz nicht vorhanden
            // Transaktion verwerfen
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // Temporäre Arbeitskopie
        if ($this->hasTmp) {
            if ((int)$data['tmp'] === 1 && method_exists($this, 'getProdData')) {
                if (!$prodData = $this->getProdData($id)) {
                    $this->cms->error('error_record_not_found');
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                // ab hier mit dem produktiven Datensatz weiterarbeiten
                $prodId = (int)$prodData[0];
                // SELECT FOR UPDATE
                if (!$data = $this->load($prodId, $idField, true)) {
                    // Datensatz nicht vorhanden
                    // Transaktion verwerfen
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }
        }
        return $data;
    }

    /**
     * Helper: deleted-Flag für GENAU EINEN Datensatz setzen.
     * Läuft innerhalb der Transaktion des Aufrufers (softDeleteOne() / restoreOneCore()).
     * Setzt $this->hasDeleted voraus (vom Aufrufer geprüft).
     * Verwendet $this->markTimestamp als changed_at (Vorgangs-Zeitstempel,
     * wird in delete() bzw. restore() gesetzt).
     *
     * @param array $data        Bisherige Inhalte des (produktiven) Datensatzes
     * @param bool  $deleted     true:  als gelöscht markieren
     *                           false: wiederherstellen
     */
    protected function markDeleted(array $data, bool $deleted): void
    {
        $tableName = $this->tableName;
        $idField   = $tableName . '_id';
        $id   = (int)$data[$idField];
        $flag = $deleted ? 1 : 0;
        $sessionAdminId = $this->adminIdForDb();
        // Sicherheitsnetz: Vorgangs-Zeitstempel muss gesetzt sein
        if ($this->markTimestamp === '') {
            $this->markTimestamp = $this->newTimestamp();
        }

        // Genau EIN Datensatz - Teilbäume und Geschwister sind Sache der
        // Orchestrierungs-Funktionen (siehe collectIds())
        $sql = "UPDATE `{$tableName}` SET `deleted` = ?";
        $sqlTypes = 'i';
        $values = [$flag];
        if ($this->hasChangedBy) {
            // changed_by zeigt an, wer gelöscht bzw. wiederhergestellt hat
            // (siehe Edit-Lock-Prüfung in save())
            $sql .= ", `changed_by` = ?";
            $sqlTypes .= 'i';
            $values[] = $sessionAdminId;
        }
        if ($this->hasChangedAt) {
            // Einheitlicher Vorgangs-Zeitstempel statt UTC_TIMESTAMP(),
            // damit alle in einem Vorgang markierten Datensätze exakt gleich datiert sind
            $sql .= ", `changed_at` = ?";
            $sqlTypes .= 's';
            $values[] = $this->markTimestamp;
        }
        $sql .= " WHERE `{$idField}` = ?";
        $sqlTypes .= 'i';
        $values[] = $id;
        $this->db->query($sql, $sqlTypes, $values);

        if ($this->hasTmp) {
            if ($deleted) {
                // Temporäre Arbeitskopien zum Datensatz ebenfalls als deleted markieren:
                // save() erkennt daran den zerstörten Edit-Lock
                // (record.error_editlock_deleted)
                $sql = "UPDATE `{$tableName}`"
                     . " SET `deleted` = 1, `changed_by` = ?, `changed_at` = ?"
                     . " WHERE `tmp` = 1 AND `origin_id` = ?";
                $this->db->query($sql, 'isi', [$sessionAdminId, $this->markTimestamp, $id]);
                // Ihre Verknüpfungen entfernen (wie beim Entwerten in checkOut()):
                // Check-out hat auch die EINGEHENDEN Kanten auf die Kopie gelegt -
                // blieben sie stehen, hätte ein n:1-Feld der Quelle zwei Ziele
                if ($tableName === 'record') {
                    $this->db->query(
                        "DELETE `r` FROM `data_rel` `r` INNER JOIN `record` `t`"
                        . " ON `t`.`record_id` IN (`r`.`src_id`, `r`.`dest_id`)"
                        . " WHERE `t`.`tmp` = 1 AND `t`.`origin_id` = ?",
                        'i', [$id]
                    );
                }
            } else {
                // Beim Löschen entwertete Arbeitskopien endgültig entfernen
                // (Edit-Lock war bereits zerstört, Inhalt basiert auf Vor-Lösch-Stand)
                $sql = "DELETE FROM `{$tableName}`"
                     . " WHERE `tmp` = 1 AND `deleted` = 1 AND `origin_id` = ?";
                $this->db->query($sql, 'i', [$id]);
            }
        }
    }

    /**
     * Helper zu restore(): changed_at eines Datensatzes unverändert (roh) aus der DB lesen.
     * load()/fetch() bereiten Datumswerte ggf. entsprechend Schema auf - für den
     * exakten Zeitstempel-Vergleich wird aber der gespeicherte Wert benötigt.
     * Tabellen ohne changed_at-Feld: leerer String (kein Zeitstempel-Vergleich möglich).
     *
     * @param int $id   `{tableName}_id`
     * @return string   changed_at im DB-Format 'Y-m-d H:i:s.v' oder ''
     */
    protected function rawChangedAt(int $id): string
    {
        if (!$this->hasChangedAt) return '';
        $tableName = $this->tableName;
        $sql = "SELECT `changed_at` FROM `{$tableName}` WHERE `{$tableName}_id` = ?";
        $this->db->query($sql, 'i', [$id]);
        if ($this->db->num_rows < 1) return '';
        return (string)$this->db->fetchNum()[0];
    }

    /*
     * Frühere Massen-Funktion setDeletedBulk() entfernt.
     *
     * Sie markierte alle Datensätze eines Filterwerts (z. B. einer area_id) mit EINEM
     * UPDATE - dabei liefen keine Hooks. Kaskaden aus hookOnSoftDelete() /
     * hookOnRestore() anderer Datasets schicken die Datensätze stattdessen einzeln
     * durch softDeleteOne() bzw. restoreOneCore(); ein Beispiel steht in
     * Area::hookOnSoftDelete().
     */

    /**
     * Zeitstempel-Zugriff für Hooks (hookOnSoftDelete / hookOnRestore):
     * getMarkTimestamp():    Vorgangs-Zeitstempel, der als changed_at gesetzt wird
     * getRestoreTimestamp(): changed_at des Löschvorgangs, der rückgängig gemacht wird
     */
    public function getMarkTimestamp(): string
    {
        return $this->markTimestamp;
    }

    /**
     * $fn als Aktion einer Lösch-Policy ausführen (siehe $policyMode) - Modul
     * Privacy, PrivacyJobs. Speichern, Löschen und endgültiges Löschen darin
     * prüfen weder Pflichtfelder noch Gruppenrechte.
     */
    public function asPolicy(callable $fn): mixed
    {
        $previous = $this->policyMode;
        $this->policyMode = true;
        try {
            return $fn();
        }
        finally {
            $this->policyMode = $previous;
        }
    }

    /**
     * Rechte der Benutzergruppe an Datensätzen und Templates prüfen
     * (docs/developers/12-group-rights.md, Camindo\Core\Access).
     *
     * Greift nur für die Tabellen `record` und `template` und nur mit geladenen
     * Rechten (Backend-Session); im Frontend (RecordUser) und in Hintergrundjobs
     * ist Access ungeladen und alles erlaubt. Die übrigen Dataset-Kindklassen
     * (Admin, Area, Field, ...) laufen über den Modulzugriff.
     *
     *     create   Anlegen im Bereich (Owner = Session-Admin)
     *     write    Ändern - je nach Gruppe des Owners (group_/other_write)
     *     delete   Löschen, Wiederherstellen, endgültiges Löschen
     *
     * Templates kennen nur Lesen/Schreiben: alle drei Aktionen = templateWrite().
     * Bei Verweigerung steht die Meldung in Cms::errors; der Aufrufer bricht mit
     * false/null ab (kein die(), damit Masken die Meldung zeigen).
     *
     * @param string   $action   'create' | 'write' | 'delete'
     * @param int      $areaId
     * @param int|null $ownerId  owner_id des Datensatzes (write/delete)
     */
    protected function accessCheck(string $action, int $areaId, ?int $ownerId = null): bool
    {
        if ($this->tableName !== 'record' && $this->tableName !== 'template') return true;
        if ($this->policyMode) return true;
        $access = $this->container->get(Access::class);
        if (!$access->isLoaded() || $access->isSuperuser()) return true;
        if ($this->tableName === 'template') {
            $ok = $access->templateWrite($areaId);
        }
        else {
            $ok = match ($action) {
                'create' => $access->areaCreate($areaId),
                'delete' => $access->areaDelete($areaId, $ownerId),
                default  => $access->areaWrite($areaId, $ownerId),
            };
        }
        if (!$ok) $this->cms->error($this->cms->snippet('error_access_denied'));
        return $ok;
    }

    /** Ergebnis von relationTargetReadable() je field_id (Request) */
    private array $relationTargetReadable = [];

    /**
     * Darf die Benutzergruppe mindestens einen Zielbereich eines Verknüpfungsfeldes
     * lesen (relation, pointer, link - uni- wie bidirektional)? Sonst zeigt die
     * Eingabemaske das Feld nicht, und Speichern, Check-out/-in lassen seine
     * Verknüpfungen unangetastet (12-group-rights.md). Für andere Feldtypen und
     * ohne geladene Rechte immer true.
     *
     * @param array $colDef  Eintrag aus virtualSchema() (field_id, type, rel_area_id)
     */
    public function relationTargetReadable(array $colDef): bool
    {
        $type = (string)($colDef['type'] ?? '');
        if (!in_array($type, $this->cms->relationTypes, true)) return true;
        $access = $this->container->get(Access::class);
        if (!$access->isLoaded() || $access->isSuperuser()) return true;
        $fieldId = (int)($colDef['field_id'] ?? 0);
        if ($fieldId > 0 && array_key_exists($fieldId, $this->relationTargetReadable)) {
            return $this->relationTargetReadable[$fieldId];
        }
        $areaIds = [];
        if (!empty($colDef['rel_area_id'])) $areaIds[] = (int)$colDef['rel_area_id'];
        if ($fieldId > 0 && method_exists($this, 'getRelAreaIds')) {
            $this->db->pushResult();
            $areaIds = array_merge($areaIds, array_map('intval', $this->getRelAreaIds($fieldId)));
            $this->db->popResult();
        }
        $readable = empty($areaIds);
        foreach ($areaIds as $areaId) {
            if ($access->areaRead($areaId)) { $readable = true; break; }
        }
        if ($fieldId > 0) $this->relationTargetReadable[$fieldId] = $readable;
        return $readable;
    }

    /**
     * name, type und instance_id eines Bereichs aus dem Instanz-Dateicache
     * (InstanceCache::areaInfo()) - statt "SELECT instance_id, type FROM area".
     *
     * Der Cache wird in Area::hookOnSaved() innerhalb derselben Transaktion neu
     * geschrieben, ein gerade angelegter Bereich steht also schon darin. Fehlt er
     * trotzdem (Cache von Hand geleert, Fremdschreiber), fragt die Methode die
     * Tabelle - der Aufrufer merkt davon nichts.
     *
     * @return array|null  ['name', 'type', 'instance_id', 'trigger_template_ids'] oder null (unbekannt)
     */
    protected function areaInfo(int $areaId): ?array
    {
        if ($areaId < 1) return null;
        $info = $this->container->get(InstanceCache::class)->areaInfo($areaId);
        if ($info !== null) return $info;
        $this->db->pushResult();
        $this->db->query("SELECT `name`, `type`, `instance_id` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
        $row = $this->db->fetch();
        $triggerIds = [];
        if ($row) {
            $this->db->query("SELECT `template_id` FROM `rel_area_trigger` WHERE `area_id` = ? ORDER BY `position`, `template_id`", 'i', [$areaId]);
            $triggerIds = array_map('intval', $this->db->fetchAll()); // flache Liste
        }
        $this->db->popResult();
        if (!$row) return null;
        return [
            'name' => (string)$row['name'],
            'type' => (string)$row['type'],
            'instance_id' => (int)$row['instance_id'],
            'trigger_template_ids' => $triggerIds,
        ];
    }

    /**
     * Instanz eines Bereichs (siehe areaInfo()); 0 = unbekannt.
     */
    protected function areaInstanceId(int $areaId): int
    {
        return (int)($this->areaInfo($areaId)['instance_id'] ?? 0);
    }

    /**
     * Typ eines Bereichs (siehe areaInfo()); '' = unbekannt.
     */
    protected function areaType(int $areaId): string
    {
        return (string)($this->areaInfo($areaId)['type'] ?? '');
    }

    /**
     * Administrator-Id für die Spalten created_by / changed_by - oder NULL.
     *
     * Ohne Backend-Session (Frontend: CMS:USER_INPUT & Co. schreiben Datensätze
     * für Website-Besucher, siehe RecordUser) gibt es keinen Administrator. Die
     * Spalten tragen einen Fremdschlüssel auf `admin`, eine 0 liefe also in den
     * Fremdschlüssel - deshalb NULL. mysqli überträgt einen null-Wert unabhängig
     * vom Bind-Typ als SQL-NULL.
     *
     * @return int|null  admin_id der Session, null ohne Session
     */
    protected function adminIdForDb(): ?int
    {
        $adminId = (int)$this->session->get('admin_id');
        return $adminId > 0 ? $adminId : null;
    }

    public function getRestoreTimestamp(): string
    {
        return $this->restoreTimestamp;
    }

    // Allgemeine Hilfsmethoden
    // ====================

    /**
     * Helper: führt $task innerhalb einer Transaktion aus.
     * Sichert das bisherige Datenbank-Ergebnis (push/popResult),
     * committet bei Erfolg, sammelt Fehlermeldungen in Cms::errors
     * und rollt bei Exceptions zurück.
     *
     * @param callable $task     auszuführende Aufgabe, Rückgabewert wird durchgereicht
     * @param mixed $onError     Rückgabewert im Fehlerfall
     */
    protected function transactional(callable $task, mixed $onError = null): mixed
    {
        // Bisheriges Datenbank-Ergebnis sichern
        $this->db->pushResult();
        // Transaktion starten
        try {
            $this->db->beginTransaction();
            $result = $task();
            $this->db->commit();
            // (finally beachten)
            return $result;
        } catch (\Exception $e) {
            // Fehlermeldungen, die noch nicht in Cms::errors gesammelt wurden, hinzufügen
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            // Alles Rückgängig machen
            $this->db->rollback();
            // (finally beachten)
            return $onError;
        } finally {
            // vorheriges Datenbankergebnis wiederherstellen
            $this->db->popResult();
        }
    }

    /**
     * Helper: Werte vorherigen Vergleich aus ($result) und erzeuge ggf Fehlermeldung.
     * @return bool     true: Fehler (sind beim Vegleichen aufgetreten)
     */
    private function _hasCompareError(int $key, string $op, string $type, string $name, string $label, mixed $val, int $result): bool
    {
        $error = false;
        $errorSnippetId = 'error' . ($key === 0 ? '_min' : '_max');
        switch ($op) {
            case '>=':
                // Fehler: Der geprüfte Wert ist kleiner als das Minimum
                if ($result === -1) {
                    $error = true;
                    $errorSnippetId .= '_ge';
                }
                break;
            case '>':
                // Fehler: Der geprüfte Wert ist kleiner oder gleich dem Minimum
                if ($result <= 0) {
                    $error = true;
                    $errorSnippetId .= '_gt';
                }
                break;
            case '<=':
                // Fehler: Der geprüfte Wert ist größer als das Maximum
                if ($result === 1) {
                    $error = true;
                    $errorSnippetId .= '_le';
                }
                break;
            case '<':
                // Fehler: Der geprüfte Wert ist größer oder gleich dem Maximum
                if ($result >= 0) {
                    $error = true;
                    $errorSnippetId .= '_lt';
                }
                break;
        }
        if (in_array($type, $this->cms->textTypes, true)) {
            $errorSnippetId .= '_length';
        }
        elseif (in_array($type, $this->cms->arrayTypes, true)) {
            $errorSnippetId .= '_number';
        }
        else {
            $errorSnippetId .= '_value';
        }
        if ($error) {
            if ($type == 'timestampl') {
                // Hinweis, was verglichen wird
                $val = (string)$val . ' (UTC)';
            }
            $this->createFieldError($errorSnippetId, $name, $label, (string)$val);
        }
        return $error;
    }

    /**
     * Zwei Datumsangaben im ISO-Format vergleichen.
     *
     * Verglichen wird nur so weit, wie die kuerzere Angabe reicht - ein Minimum darf
     * eine Teilangabe sein ('2020' meint "ab 2020"). Dieselbe Regel wendet die
     * min/max-Schleife in validateFieldValue() auf den Feldwert an.
     *
     * Eine leere Angabe gilt als "kein Datum" und damit als kleiner als jede andere;
     * so ersetzt DATE_MIN ein nicht gesetztes Feld-Minimum.
     *
     * @return int  -1, 0 oder 1 wie der Raumschiff-Operator
     */
    protected function dateCompare(string $a, string $b): int
    {
        if ($a === '') return ($b === '') ? 0 : -1;
        if ($b === '') return 1;
        $len = min(mb_strlen($a), mb_strlen($b));
        return mb_substr($a, 0, $len) <=> mb_substr($b, 0, $len);
    }

    /**
     * Helper: Fehlermeldung mit Feld-Label erzeugen.
     *
     * @param string $errorSnippetId  Id of errorSnippet
     * @param string $name         colum name
     * @param string $label           Label-Wert aus Schema (z. B. '#area.field_...')
     * @param string $refValue        Optionaler Referenzwert für {value}-Platzhalter
     */
    protected function createFieldError(string $errorSnippetId, string $name, string $label = '', string $refValue = '') {
        if (!empty($label)) $labelText = $this->cms->textOrSnippet($label);
        else $labelText = $name;
        $msg = strtr($this->cms->snippet($errorSnippetId), [
            '{value}' => htmlspecialchars($refValue)
        ]);
        $this->cms->error($msg, $name, $labelText);
    }

    /**
     * Hilfsfunktion: Ermittelt den mysqli Bind-Type ('i', 'd', 's', 'b')
     * anhand des SQL-Typs in der Definition.
     *
     * @param string $type  For supported SQL-Fieldtype see Class Tables
     */
    public function getBindType(string $type): string
    {
        return match ($type) {
            'integer', 'bit', 'currency', 'id' => 'i',
            'float' => 'd',
            default => 's'
        };
    }

    /**
     * Erzeugt Datum im ISO-Format aus vorherigem preg_match() mittels $this->parseDateExpression
     * Der Eingabestring (z. B. "now+2d" oder "today-5h")
     *
     * @param array $matches 
     * @return string Das formatierte Datum oder der Originalstring bei fehlendem Match
     */
    protected function parseDateExpression(array $matches): string
    {
        // Basis ermitteln ('now' = aktuelle Zeit, 'today' = heute 00:00:00 Uhr)
        $baseTime = strtolower($matches[1]);
        $dateTime = new \DateTime($baseTime);
        // Prüfen, ob ein Offset (Vorzeichen/Zahl in Index 2 und Einheit in Index 3) existiert
        if (isset($matches[2], $matches[3])) {
            $offsetValue = $matches[2];
            $unitShort = strtolower($matches[3]);
            $unitLong = match ($unitShort) {
                's' => 'seconds',
                'm' => 'minutes',
                'h' => 'hours',
                'd' => 'days',
                default => ''
            };
            // Offset anwenden (z. B. "+5 days", "-2 hours")
            if ($unitLong !== '') {
                $dateTime->modify($offsetValue . ' ' . $unitLong);
            }
        }
        return $dateTime->format('Y-m-d H:i:s');
    }

    /**
     * Ersetzt geschützte Leerzeichen in einem ICU-Muster durch normale.
     *
     * CLDR-Muster enthalten seit ICU 72 ein schmales geschütztes Leerzeichen (U+202F)
     * vor der AM/PM-Markierung ("h:mm\u{202F}a" bei en_US). Nicht-tolerantes Parsen
     * verlangt dann exakt dieses Zeichen; mit einem normalen Leerzeichen im Muster
     * akzeptiert ICU sowohl das getippte Leerzeichen als auch U+202F/U+00A0.
     */
    private static function intlPatternNormalize(string $pattern): string
    {
        // Richtungszeichen (ar_SA: "d\u{200F}/M\u{200F}/y") tippt niemand - sie fallen
        // weg, wie in intlInputNormalize()
        return str_replace(self::BIDI_MARKS, '', str_replace(["\u{202F}", "\u{00A0}"], ' ', $pattern));
    }

    /** Richtungszeichen LRM, RLM, ALM - CLDR setzt sie in Datumsmuster arabischer Locales */
    private const BIDI_MARKS = ["\u{200E}", "\u{200F}", "\u{061C}"];

    /**
     * Datums- bzw. Zeiteingabe für die Erkennung vorbereiten: Ziffern jeder Schrift
     * (arabisch ٢٦, bengalisch ২৬ …) werden zu 0-9, Richtungszeichen fallen weg. So gilt
     * bei ar_SA die angezeigte Form "٢٦ سبتمبر ٢٠٣٠ م" ebenso wie ein getipptes
     * "26/09/2030" - geparst wird dann mit lateinischen Ziffern (intlLatnLocale()).
     */
    private static function intlInputNormalize(string $value): string
    {
        $value = str_replace(self::BIDI_MARKS, '', $value);
        return preg_replace_callback('/\p{Nd}/u', fn($digit) => (string)\IntlChar::charDigitValue($digit[0]), $value) ?? $value;
    }

    /** Locale mit lateinischen Ziffern für IntlDateFormatter, z. B. "ar_SA@numbers=latn" */
    private static function intlLatnLocale(string $locale): string
    {
        if (str_contains($locale, 'numbers=')) return preg_replace('/numbers=[A-Za-z]+/', 'numbers=latn', $locale) ?? $locale;
        return $locale . (str_contains($locale, '@') ? ';' : '@') . 'numbers=latn';
    }

    /**
     * IntlDateFormatter::parse(), das nur ein Ergebnis liefert, wenn die Eingabe
     * vollständig verarbeitet wurde.
     *
     * parse() ist erfolgreich, sobald das Muster auf einen Präfix der Eingabe passt;
     * ein Rest wie eine Uhrzeit oder "extra" bliebe unbemerkt. Die per Referenz
     * gefüllte Position zählt UTF-16-Einheiten (nicht Bytes), deshalb wird gegen die
     * UTF-16-Länge verglichen - sonst scheitern Eingaben wie "24. März 2026".
     *
     * @return int|null  Unix-Timestamp oder null (kein Treffer bzw. Rest nicht gelesen)
     */
    private static function intlParseComplete(\IntlDateFormatter $fmt, string $value): ?int
    {
        $position = 0;
        $ts = $fmt->parse($value, $position);
        if ($ts === false) return null;
        $utf16Length = strlen(mb_convert_encoding($value, 'UTF-16BE', 'UTF-8')) / 2;
        return $position === $utf16Length ? (int)$ts : null;
    }

    /**
     * Wandelt eine flexible Datums-Eingabe in das ISO-Speicherformat um.
     *
     * Reihenfolge der Erkennung:
     *   1. ISO-Teilangabe (YYYY[-MM[-DD[ HH[:MM[:SS[.mmm]]]]]], 'T' erlaubt)
     *   2. IntlDateFormatter (locale-aware, benötigt intl-Extension)
     *   3. strtotime() als letzter Fallback
     *
     * Tag, Monat und Jahr müssen erkannt werden
     * Zeit wird nach hinten aufgefüllt mit Maske 00:00:00
     *
     * Keine Zeitzonen-Umwandlung
     * Jahresbereich: 1000–3000 (Pfade 2+3 benötigen 64-Bit-PHP)
     *
     * Rückgabeformat:
     * -> 'Y-m-d H:i:s'
     *
     * Ziffern jeder Schrift werden vorab zu 0-9 (intlInputNormalize()).
     *
     * @param string $value  Eingegebener Datumswert
     * @return string|null   ISO-String oder null bei ungültiger Eingabe
     */
    protected function dateInputToIso(string $value): ?string
    {
        $value = trim(self::intlInputNormalize($value));
        if ($value === '') return null;
        // 1. ISO-Teilangabe (zero-padded)
        // --------------------
        $pattern = '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2})(?::(\d{1,2})(?::(\d{1,2}))?)?)?$/';
        if (preg_match($pattern, $value, $m)) {
            $year  = (int)$m[1];
            $month = (int)$m[2];  // im Regex nicht optional → immer gesetzt
            $day   = (int)$m[3];  // im Regex nicht optional → immer gesetzt
            $hour  = isset($m[4]) && $m[4] !== '' ? (int)$m[4] : 0;
            $min   = isset($m[5]) && $m[5] !== '' ? (int)$m[5] : 0;
            $sec   = isset($m[6]) && $m[6] !== '' ? (int)$m[6] : 0;
            // Bereichsprüfung (sonst ungültige Eingabe)
            // Nach unten wird hier NICHT abgewiesen: ein wohlgeformtes Datum vor dem
            // Jahr 1000 ist keine Formatverletzung, sondern liegt nur unter der
            // Untergrenze. Die Meldung dazu soll die Grenze nennen ("mindestens
            // 1000-01-01 (UTC)") und nicht "ungültiges Format" - abgewiesen wird es
            // deshalb erst von der min-Prüfung in validateFieldValue().
            // Unsinn wie 0000-00-00 (Monat/Tag 0) fällt weiter unten trotzdem durch.
            if ($year > 9999)                          return null;
            if ($month < 1 || $month > 12)             return null;
            // Tag mit letztem des Monats abgleichen
            $dateString = sprintf('%04d-%02d-01', $year, $month);
            $date = new \DateTimeImmutable($dateString);
            $lastDay = (int)$date->format('t');
            if ($day < 1 || $day > $lastDay)           return null;
            if ($hour > 23 || $min > 59 || $sec > 59)  return null;
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec);
        }
        // 2. IntlDateFormatter (locale-aware)
        // --------------------
        if (class_exists('\IntlDateFormatter')) {
            $locale = self::intlLatnLocale(\Locale::getDefault() ?: 'de_DE');
            $dateStyles = [
                \IntlDateFormatter::SHORT,
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::LONG,
            ];
            $timeStyles = [
                \IntlDateFormatter::MEDIUM,  // HH:mm:ss
                \IntlDateFormatter::SHORT,   // HH:mm
                \IntlDateFormatter::NONE,    // kein Zeitanteil
            ];
            foreach ($dateStyles as $dateStyle) {
                foreach ($timeStyles as $timeStyle) {
                    $fmt = new \IntlDateFormatter(
                        $locale,
                        $dateStyle,
                        $timeStyle,
                        'UTC',                          // feste Zone: kein DST-Randfall
                        \IntlDateFormatter::GREGORIAN
                    );
                    $fmt->setLenient(false);
                    // Standardmuster der Locale abrufen (z. B. "dd.MM.y, HH:mm:ss" bei de_DE).
                    // Geschützte Leerzeichen (U+202F vor "PM" seit ICU 72, U+00A0) werden durch
                    // normale ersetzt - ICU akzeptiert dann beide Varianten in der Eingabe.
                    $originalPattern = self::intlPatternNormalize($fmt->getPattern());
                    // Tolerante Variante ohne Komma erzeugen (z. B. "dd.MM.y HH:mm:ss")
                    // (auch das arabische Komma: ar_SA "d/M/y G، h:mm a")
                    $flexiblePattern = str_replace([', ', '، '], ' ', $originalPattern);
                    // und ohne Ära (ar_SA: "d/M/y G" - das "م" lässt man beim Tippen weg)
                    $noEraPattern = trim(preg_replace(['/\bG+\b/', '/\s+/'], ['', ' '], $flexiblePattern) ?? $flexiblePattern);
                    // Alle Muster testen, um robust gegenüber fehlenden Satzzeichen zu sein.
                    // array_unique verhindert unnötige doppelte Durchläufe, falls sich nichts ändert.
                    $patterns = array_unique([$originalPattern, $flexiblePattern, $noEraPattern]);
                    foreach ($patterns as $pattern) {
                        $fmt->setPattern($pattern);
                        // Nur erfolgreich, wenn der gesamte String verarbeitet wurde: parse() ist mit
                        // einem passenden Präfix zufrieden, bei $timeStyle = NONE würde sonst eine
                        // vorhandene (aber nicht zum Muster passende) Uhrzeit stillschweigend verworfen.
                        $ts = self::intlParseComplete($fmt, $value);
                        if ($ts !== null) {
                            // '@'-Prefix erzwingt UTC-Interpretation des Timestamps
                            $date = new \DateTimeImmutable('@' . $ts);
                            return $date->format('Y-m-d H:i:s');
                        }
                    }
                }
            }
        }
        // 2b. Datum und Uhrzeit getrennt: "9/26/2030 14:30" in einer Locale mit
        // 12-Stunden-Format (en_US, ar_SA …) passt auf kein Muster der Locale
        // --------------------
        if (preg_match('/^(.*\S)[\s,،]+(\d{1,2}[:.]\d{1,2}(?:[:.]\d{1,2})?(?:\s*(?:am|pm))?)$/iu', $value, $m)
            && preg_match('/\d/', $m[1])
        ) {
            $date = $this->dateInputToIso($m[1]);
            $time = $date !== null && substr($date, 11) === '00:00:00' ? $this->timeInputToIso($m[2]) : null;
            if ($time !== null) return substr($date, 0, 10) . ' ' . $time;
        }
        // 3. strtotime()-Fallback
        // --------------------
        $ts = strtotime($value);
        if ($ts === false) return null;
        // new DateTime() nutzt PHP-Default-Timezone (konsistent mit strtotime-Interpretation)
        $date = new \DateTime();
        $date->setTimestamp($ts);
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Wandelt eine flexible Uhrzeit-Eingabe in das Speicherformat 'H:i:s'.
     *
     * Erkennungsreihenfolge:
     * 1. eigene Muster (ISO, French/German 'h', AM/PM, blanke Stunde)
     * 2. IntlDateFormatter (reine Zeit, locale-aware)
     * 3. strtotime()-Fallback
     *
     * Keine Zeitzonen-Umwandlung
     *
     * Ziffern jeder Schrift werden vorab zu 0-9 (intlInputNormalize()).
     *
     * @param string $value  Eingegebene Uhrzeit
     * @return string|null   'HH:MM:SS' oder null bei ungültiger Eingabe
     */
    protected function timeInputToIso(string $value): ?string
    {
        $value = trim(self::intlInputNormalize($value));
        if ($value === '') return null;
        $hour = null;
        $min  = 0;
        $sec  = 0;
        $norm = mb_strtolower($value);
        // 1a. AM/PM (12-Stunden-Format), z. B. "7 pm", "7:30 pm", "7:30:15 pm", "12am"
        if (preg_match('/^(\d{1,2})(?::(\d{1,2})(?::(\d{1,2}))?)?\s*(am|pm)$/', $norm, $m)) {
            $h = (int)$m[1];
            $min = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
            $sec = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 0;
            if ($h < 1 || $h > 12) return null;
            // 12am = 00 Uhr, 12pm = 12 Uhr
            if ($m[4] === 'am') $hour = ($h === 12) ? 0 : $h;
            else                $hour = ($h === 12) ? 12 : $h + 12;
        }
        // 1b. French/German "19h", "19h30", "19 h 30", "19 h 30 15"
        elseif (preg_match('/^(\d{1,2})\s*h\s*(\d{1,2})?(?:\s*(\d{1,2}))?$/', $norm, $m)) {
            $hour = (int)$m[1];
            $min  = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
            $sec  = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 0;
        }
        // 1c. ISO bzw. "19:00", "19.00", "19:00:30" (Sekunden werden mitgenommen)
        elseif (preg_match('/^(\d{1,2})[:.](\d{1,2})(?:[:.](\d{1,2}))?$/', $norm, $m)) {
            $hour = (int)$m[1];
            $min  = (int)$m[2];
            $sec  = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 0;
        }
        // 1d. blanke Stunde "19"
        elseif (preg_match('/^(\d{1,2})$/', $norm, $m)) {
            $hour = (int)$m[1];
            $min  = 0;
            $sec  = 0;
        }
        // 2. IntlDateFormatter (reine Zeit, locale-aware)
        // --------------------
        if ($hour === null && class_exists('\IntlDateFormatter')) {
            $locale = self::intlLatnLocale(\Locale::getDefault() ?: 'de_DE');
            foreach ([\IntlDateFormatter::SHORT, \IntlDateFormatter::MEDIUM] as $timeStyle) {
                $fmt = new \IntlDateFormatter(
                    $locale,
                    \IntlDateFormatter::NONE,
                    $timeStyle,
                    'UTC',                              // feste Zone: kein DST-Randfall
                    \IntlDateFormatter::GREGORIAN
                );
                $fmt->setLenient(false);
                $fmt->setPattern(self::intlPatternNormalize($fmt->getPattern()));
                // Wie in dateInputToIso(): nur akzeptieren, wenn die Eingabe vollständig gelesen wurde
                $ts = self::intlParseComplete($fmt, $value);
                if ($ts === null) continue;
                // '@'-Prefix erzwingt UTC-Interpretation des Timestamps
                $dt   = new \DateTimeImmutable('@' . $ts);
                $hour = (int)$dt->format('G');
                $min  = (int)$dt->format('i');
                $sec  = (int)$dt->format('s');
                break;
            }
        }
        // 3. strtotime()-Fallback (z. B. "7 pm" in Umgebungen ohne intl)
        // --------------------
        if ($hour === null) {
            $ts = strtotime($value);
            if ($ts !== false) {
                // date() nutzt PHP-Default-Timezone (konsistent mit strtotime-Interpretation)
                $hour = (int)date('G', $ts);
                $min  = (int)date('i', $ts);
                $sec  = (int)date('s', $ts);
            }
        }
        // Bereichsprüfung
        // --------------------
        if ($hour === null)            return null;
        if ($hour < 0 || $hour > 23)   return null;
        if ($min  < 0 || $min  > 59)   return null;
        if ($sec  < 0 || $sec  > 59)   return null;
        return sprintf('%02d:%02d:%02d', $hour, $min, $sec);
    }

    /**
     * Hilfsfunktionen für Backend-Erweiterungen
     * Zugriff auf protected Vars
     */
    public function getSchema(): array {
        return $this->schema;
    }
    
    public function setSchema(array $schema): void {
        $this->schema = $schema;
    }
    
    public function getModuleName(): string {
        return $this->moduleName;
    }
    
    public function getTableName(): string {
        return $this->tableName;
    }
    
    public function getDefaultSortOrder(): string {
        return $this->defaultSortOrder;
    }
    
    public function getDefaultFieldName(): string {
        return $this->defaultFieldName;
    }
    
    public function getSortOrder(): string {
        return $this->defaultSortOrder;
    }
    
    public function hasTree(): bool {
        return $this->hasParentId;
    }

    // ====================
    // Audit-Log (Camindo\Core\AuditLog)

    /**
     * Wird diese Tabelle gerade protokolliert?
     */
    protected function auditActive(): bool
    {
        return $this->audit && $this->container->get(AuditLog::class)->enabled();
    }

    /**
     * Schnappschuss eines Datensatzes für das Audit-Log: die Spalten der Tabelle im
     * Datenbankformat, JSON-Spalten aufgefächert (details.url …), ohne AUDIT_IGNORE
     * und $auditIgnore. Format siehe AuditLog (Klassenkommentar).
     *
     * Record überschreibt das (alle Sprachen, virtuelle Felder, Verknüpfungen).
     *
     * @param int $id  `{tableName}_id`
     * @return array   Schlüssel => ['l', 't', 'g', 'v', 'c'?]; [] wenn es ihn nicht gibt
     */
    public function auditSnapshot(int $id): array
    {
        $idField = $this->tableName . '_id';
        $this->db->pushResult();
        try {
            $this->db->query("SELECT * FROM `{$this->tableName}` WHERE `{$idField}` = ?", 'i', [$id]);
            $row = $this->db->fetch();
        }
        finally {
            $this->db->popResult();
        }
        if (!$row) return [];
        return $this->auditColumns($row, $this->schema);
    }

    /**
     * Spalten einer Tabellenzeile als Schnappschuss (siehe auditSnapshot()).
     *
     * @param array $row     Zeile im Datenbankformat
     * @param array $schema  Schema mit Beschriftung und Typ je Spalte
     */
    protected function auditColumns(array $row, array $schema): array
    {
        $auditLog = $this->container->get(AuditLog::class);
        $ignore = array_merge(self::AUDIT_IGNORE, $this->auditIgnore);
        $snapshot = [];
        foreach ($row as $name => $value) {
            $name = (string)$name;
            if (in_array($name, $ignore, true) || !isset($schema[$name])) continue;
            $colDef = $schema[$name];
            $type = (string)($colDef['type'] ?? 'text');
            $label = (string)($colDef['label'] ?? $name);
            if ($type === 'password') {
                $value = $this->auditSecret($name, (string)$value);
                $snapshot[$name] = ['l' => $label, 't' => 'password', 'g' => '', 'v' => '',
                    'c' => $value === '' ? '' : hash('sha256', $value)];
            }
            elseif ($type === 'encrypted') {
                $snapshot[$name] = $auditLog->encryptedEntry($label, (string)$value);
            }
            elseif ($type === 'json') {
                $decoded = json_decode((string)$value, true);
                if (!is_array($decoded)) {
                    $snapshot[$name] = ['l' => $label, 't' => 'json', 'g' => '', 'v' => (string)$value];
                    continue;
                }
                foreach ($this->auditFlatten($decoded, $name) as $key => $subValue) {
                    if (in_array($key, $ignore, true)) continue;
                    $subDef = $schema[$key] ?? [];
                    $subType = (string)($subDef['type'] ?? 'text');
                    if ($subType === 'password') {
                        $subValue = $this->auditSecret($key, $subValue);
                        $snapshot[$key] = ['l' => (string)($subDef['label'] ?? $key), 't' => 'password', 'g' => '', 'v' => '',
                            'c' => $subValue === '' ? '' : hash('sha256', $subValue)];
                        continue;
                    }
                    $snapshot[$key] = ['l' => (string)($subDef['label'] ?? $key), 't' => $subType, 'g' => '', 'v' => $subValue];
                }
            }
            else {
                $snapshot[$name] = ['l' => $label, 't' => $type, 'g' => '', 'v' => $value === null ? '' : (string)$value];
            }
        }
        return $snapshot;
    }

    /**
     * Vergleichswert eines Passwort-Felds fürs Audit-Log. Vorgabe: der gespeicherte
     * Wert (ein Hash). Eine Kindklasse, die ein Passwort umkehrbar verschlüsselt
     * ablegt (neuer Geheimtext bei jedem Speichern), liefert hier den Klartext -
     * sonst gälte jedes Speichern als Passwortänderung. Er verlässt die Methode nur
     * als Hash.
     */
    protected function auditSecret(string $key, string $value): string
    {
        return $value;
    }

    /**
     * Verschachteltes Array in Punktschlüssel auffächern: ['details' => ['a' => 1]]
     * -> ['details.a' => '1']. Listen (0, 1, 2 …) bleiben ein Wert (JSON).
     */
    protected function auditFlatten(array $values, string $prefix): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = $prefix . '.' . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $flat += $this->auditFlatten($value, $path);
            }
            elseif (is_array($value)) {
                $flat[$path] = $value === [] ? '' : (string)json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            elseif (is_bool($value)) {
                $flat[$path] = $value ? '1' : '0';
            }
            else {
                $flat[$path] = $value === null ? '' : (string)$value;
            }
        }
        return $flat;
    }

    /**
     * Bezeichnung eines Objekts fürs Protokoll: Default-Feld der Tabelle (meist
     * 'name'), sonst 'label'.
     *
     * @param array $snapshot  Schnappschuss (nachher, beim Löschen vorher)
     */
    protected function auditLabel(int $id, array $snapshot): string
    {
        foreach ([$this->defaultFieldName, 'label', 'name', 'displayname'] as $field) {
            $value = (string)($snapshot[$field]['v'] ?? '');
            if ($value !== '') return $this->cms->textOrSnippet($value);
        }
        return '#' . $id;
    }

    /**
     * Einen Eintrag ins Audit-Log schreiben (innerhalb der laufenden Transaktion).
     *
     * @param string     $action  create|update|delete|restore|purge
     * @param int        $id      produktive Id
     * @param array|null $before  Schnappschuss vorher; null bei delete/restore (keine
     *                            Feldänderungen, nur das Ereignis)
     * @param array      $data    beim endgültigen Löschen: die geladene Zeile (die
     *                            Datenbank kennt sie nicht mehr)
     */
    protected function auditWrite(string $action, int $id, ?array $before = null, array $data = []): void
    {
        $auditLog = $this->container->get(AuditLog::class);
        $after = ($action === 'purge') ? [] : $this->auditSnapshot($id);
        $changes = ($before === null) ? [] : $auditLog->diff($before, $after);
        if ($action === 'update' && !$changes) return;
        if ($this->policyMode && $action === 'update') {
            // Lösch-Policy: die bisherigen Werte sollen gerade verschwinden - nur das
            // Neue steht im Protokoll
            $action = 'privacy';
            foreach ($changes as &$change) $change['b'] = '';
            unset($change);
        }

        $ref = $after ?: ($before ?? []);
        $areaId = isset($ref['area_id']['v']) ? (int)$ref['area_id']['v']
            : (isset($data['area_id']) ? (int)$data['area_id'] : null);
        if ($this->tableName === 'area') $areaId = $id;
        $instanceId = isset($ref['instance_id']['v']) ? (int)$ref['instance_id']['v']
            : (isset($data['instance_id']) ? (int)$data['instance_id'] : null);
        $auditLog->write($action, $this->tableName, $id, $this->auditLabel($id, $ref), $changes,
            $areaId ?: null, $instanceId ?: null);
    }
}
