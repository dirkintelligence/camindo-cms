<?php
/**
 * Stapelverarbeitung von Datensätzen: ein Feld in vielen Datensätzen ändern, viele
 * Datensätze in einen anderen Bereich verschieben, löschen oder ihren Volltext neu
 * aufbauen.
 *
 * Das ist die ARBEIT; die Maske dazu ist RecordBackendBatch (record_batch.php), der
 * Hintergrundprozess Core/cron/record_batch.php. Beide rufen run() - im Hintergrund
 * läuft EXAKT DERSELBE Code, der Prozess verlagert die Arbeit nur aus dem Request
 * heraus (wie bei MassDeletion).
 *
 * DER AUFTRAG (JOB)
 * --------------------
 *     mode            'edit' | 'move' | 'delete'
 *     area_id         Bereich der Datensätze
 *     ids             record_ids (produktiv), von collectIds() ermittelt
 *     simulate        true: prüfen, nichts ändern (siehe unten)
 *     fields          edit: Namen der angehakten Felder
 *     data            edit: rohe Eingaben der Maske (data[...] wie beim gewöhnlichen
 *                     Speichern, mehrsprachig je Sprache) - nur die angehakten Felder;
 *                     Passwörter als Hash (siehe prehashed)
 *     stored          edit: Felder, deren Werte in data im Datenbankformat vorliegen
 *                     (API, ApiValues::input())
 *     prehashed       edit: Passwort-Felder, deren Wert in data der Hash ist
 *     relations       edit: lookup-Verknüpfungsfeld => [dest_id, ...] (ersetzt); über
 *                     die API jedes Verknüpfungsfeld, auch als set/add/remove/order
 *     files           edit: Upload-Feld => [langKey ('' oder language_id) =>
 *                     ['importfile' => Pfad, 'filename' => Name] | null (= löschen)]
 *     files_dir       edit: Verzeichnis der Dateien unter var/tmp (wird nach dem
 *                     Lauf entfernt)
 *     target_area_id  move: Zielbereich
 *     mapping         move: quellfeld => zielfeld | '' (siehe MoveTrait)
 *
 * DIE VORLAGE (edit)
 * --------------------
 * Verknüpfungen über ein Suchfenster (lookup) und Dateien brauchen schon beim
 * Ausfüllen eine record_id, an der sie hängen - genau wie beim Anlegen eines
 * Datensatzes. Die Maske der Stapelbearbeitung legt deshalb eine Arbeitskopie ohne
 * Original an (Record::tmpInit(), die VORLAGE): Lookups schreiben ihre data_rel-Zeilen
 * an sie, Uploads liegen physisch unter ihrer Id (Record::secureInputs()). Beim
 * Ausführen macht editJob() daraus den Auftrag - Ids der Verknüpfungen, Dateien als
 * Kopie unter var/tmp ('importfile', wie beim Import), Passwort-Hashes - und die
 * Vorlage wird verworfen. Der Auftrag ist damit in sich geschlossen und läuft auch
 * im Hintergrund.
 *
 * Für den Hintergrund schreibt start() den Auftrag als JSON nach var/tmp und gibt
 * dem Prozess den Pfad mit (loadJob()).
 *
 * JE DATENSATZ DERSELBE WEG WIE VON HAND
 * --------------------
 *     edit    RecordService::updateOne(): laden, Rechte, Bearbeitungssperre ->
 *             checkOut() -> Verknüpfungen der angehakten Lookup-Felder an die
 *             Arbeitskopie (createRelations()), Löschmarkierungen für Dateien
 *             (markFileDeleted()) -> Record::importSave() mit den angehakten Feldern
 *             (Dateien als 'importfile', Passwörter als Hash): Validierung nach den
 *             Felddefinitionen, checkIn, Cache, Volltext, Baumpflege - alles wie in
 *             der Eingabemaske. Scheitert die Prüfung, wird die Arbeitskopie
 *             verworfen (cancel()).
 *     move    Rechte, Sperre -> MoveTrait::moveToArea() - Kinder, Abbildung,
 *             Prüfung im Zielbereich
 *     delete  Rechte, Sperre -> Record::delete() (Soft-Delete samt Hooks)
 *
 * "Sperre" heißt dabei auch: der auslösende Admin hat den Datensatz selbst noch in
 * einer Eingabemaske offen - der Check-out verwürfe deren Arbeitskopie
 * (RecordService::loadForChange()).
 *
 * SIMULATION
 * --------------------
 * checkOut -> Speichern MIT Eingabeprüfung -> aber kein checkIn: Record::save() mit
 * stayTemporary (die geprüften Werte landen nur im Arbeitsstand der Arbeitskopie), danach
 * cancel(). Beim Verschieben dasselbe innerhalb von moveToArea($simulate): die
 * vorbereitete Arbeitskopie wird nur mit stayTemporary gespeichert und danach samt
 * kopierter Dateien verworfen.
 * Löschen prüft nur die Bearbeitungssperre. Geändert wird in keinem Fall etwas;
 * die Fehlermeldungen kommen wie beim Ernstfall je Datensatz zurück.
 *
 * ERGEBNIS
 * --------------------
 * run() liefert Zähler und je gescheitertem Datensatz die Meldungen (Cms::errors
 * wird je Datensatz zurückgesetzt). Im Vordergrund zeigt RecordBackendBatch sie
 * untereinander an; im Hintergrund schreibt report() sie in eine Datei
 * (var/log/{Y-m-d}_batch-{token}.log) und schickt dem auslösenden Admin eine interne
 * Nachricht (Message::sendSystem(): nur in seinem Posteingang) mit dem Inhalt der
 * Datei - der HTML-Teil der Nachricht verlinkt die betroffenen Datensätze.
 *
 * SCHWELLWERT
 * --------------------
 * Ab admin.mass_delete_threshold Datensätzen (Standard 200, 0 = aus) läuft der
 * Auftrag immer im Hintergrund; darunter entscheidet der Redakteur (needsBackground()).
 *
 * @see Camindo\Core\Modules\Record\RecordBackendBatch  die Masken
 * @see Camindo\Core\MassDeletion                       gleiches Muster für das Löschen
 *                                                      ganzer Bereiche
 * @see Core/cron/record_batch.php                      der Hintergrundprozess
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Backend;
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Messaging\Message;

class RecordBatch {

    public const MODES = ['edit', 'move', 'delete'];

    /** Typkennung des Hintergrundprozesses (Tabelle `bgproc`) */
    public const TYPE = 'batch';

    /** Felder, die sich nicht im Stapel ändern lassen (siehe batchFields()) */
    private const NOT_EDITABLE = ['parent_id', 'position', 'previous_id'];

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private Session $session,
        private System $system,
        private Crypt $crypt,
        private BackgroundProcess $bgproc,
        private Backend $backend,
        private Record $record,
        private Message $message,
        private \Camindo\Core\AuditLog $auditLog,
        private RecordService $service,
    )
    {
    }

    // ====================
    // Umfang

    /**
     * Filterparameter aus den Seitenparametern einer Datensatzliste
     * (cApp.layers.queryString()): nur filter, filterop, filtermode - Sortierung
     * und Seite spielen für den Umfang keine Rolle. Leere Filterwerte fallen weg.
     *
     * @param array $parsed  Request-Parameter ($_REQUEST bzw. parse_str() des Query-Strings)
     * @return array         ['filter' => [...], 'filterop' => [...], 'filtermode' => [...]]
     */
    public function filterParams(array $parsed): array
    {
        $params = [];
        foreach (['filter', 'filterop', 'filtermode'] as $key) {
            $values = (array)($parsed[$key] ?? []);
            // area_id kommt als eigener Parameter, nicht als Filter
            unset($values['area_id']);
            $values = array_filter($values, function ($value) {
                if (is_array($value)) return !empty(array_filter($value, fn($v) => (string)$v !== ''));
                return (string)$value !== '';
            });
            if (!empty($values)) $params[$key] = $values;
        }
        return $params;
    }

    /**
     * Enthält der Umfang eine Suche (Filter)?
     */
    public function hasFilter(array $filterParams): bool
    {
        return !empty($filterParams['filter']);
    }

    /**
     * Anzahl der produktiven Datensätze des Bereichs, ggf. mit Filter.
     */
    public function count(int $areaId, array $filterParams = []): int
    {
        if ($areaId < 1) return 0;
        $this->db->pushResult();
        $this->cms->select($this->_selectParams($areaId, $filterParams) + [
            'count' => 'true',
            'result' => 'false',
        ]);
        $count = (int)$this->db->num_rows_unlimited;
        $this->db->popResult();
        return $count;
    }

    /**
     * Ids der betroffenen Datensätze (produktiv, nicht gelöscht), ggf. mit Filter.
     *
     * @return int[]
     */
    public function collectIds(int $areaId, array $filterParams = [], string $sort = 'record_id', string $order = 'ASC'): array
    {
        if ($areaId < 1) return [];
        $this->db->pushResult();
        $this->cms->select($this->_selectParams($areaId, $filterParams, $sort, $order));
        $ids = [];
        while ($row = $this->db->fetch()) {
            $ids[] = (int)$row['record_id'];
        }
        $this->db->popResult();
        return $ids;
    }

    /**
     * select()-Parameter für Zählen und Sammeln: Bereichsbedingung als SQL, dazu die
     * Filter der Liste. Kein 'request' - im Hintergrund gibt es keinen.
     */
    private function _selectParams(int $areaId, array $filterParams, string $sort = 'record_id', string $order = 'ASC'): array
    {
        return [
            'filter'     => (array)($filterParams['filter'] ?? []),
            'filterop'   => (array)($filterParams['filterop'] ?? []),
            'filtermode' => (array)($filterParams['filtermode'] ?? []),
            'sql'        => "`record`.`area_id` = " . (int)$areaId,
            'limit'      => 0,
            'sort'       => $sort,
            'order'      => $order,
        ];
    }

    /**
     * Soll der Auftrag in den Hintergrund? Ab admin.mass_delete_threshold Datensätzen
     * (0 = Auslagerung aus).
     */
    public function needsBackground(int $count): bool
    {
        $threshold = (int)($this->config->get('admin.mass_delete_threshold') ?? 200);
        if ($threshold < 1) return false;
        return $count >= $threshold;
    }

    /**
     * Felder eines Bereichs, die sich im Stapel ändern lassen: alle sichtbaren
     * Eingabefelder - auch Verknüpfungen mit Suchfenster und Upload-Felder (über die
     * Vorlage, siehe Klassenkommentar) - außer:
     *
     *     unique / unique_in_area   derselbe Wert in vielen Datensätzen wäre nie
     *                               eindeutig (wird die Regel nur bei einem
     *                               Datensatz nicht verletzt, ist es kein Stapel)
     *     Thumbnails                werden aus dem Upload-Feld gerechnet
     *     Volltext                  wird erzeugt
     *     parent_id / position      Baumfelder (NOT_EDITABLE)
     *     link                      fest verknüpfte Inhalte gehören je einem
     *                               Datensatz - derselbe Inhalt in vielen wäre
     *                               keiner (LinkTrait)
     *
     * @param array $schemaVirtual  virtualSchema($areaId, false)
     * @return array  name => Felddefinition, in Feldreihenfolge
     */
    public function batchFields(array $schemaVirtual): array
    {
        $fields = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if ((int)($colDef['visibility_input'] ?? 0) !== 1) continue;
            $type = (string)($colDef['type'] ?? 'text');
            $displayType = (string)($colDef['display_type'] ?? 'text');
            if ($type === 'fulltext') continue;
            if ($type === 'file' && $displayType !== 'upload') continue;
            if (in_array($type, $this->cms->dependentTypes, true)) continue;
            if (in_array($name, self::NOT_EDITABLE, true)) continue;
            $flags = (array)($colDef['flags'] ?? []);
            if (in_array('unique', $flags, true) || in_array('unique_in_area', $flags, true)) continue;
            $fields[$name] = $colDef;
        }
        return $fields;
    }

    // ====================
    // Vorlage (edit)

    /**
     * Ist $tmpId eine brauchbare Vorlage: Arbeitskopie des Session-Admins ohne
     * Original, im Bereich $areaId?
     */
    public function templateValid(int $tmpId, int $areaId): bool
    {
        if ($tmpId < 1) return false;
        if (!$prodData = $this->record->getProdData($tmpId)) return false;
        return (int)$prodData[0] === 0 && (int)$prodData[1] === $areaId;
    }

    /**
     * Die angehakten Checkboxen der Maske: "name" bzw. bei mehrsprachigen Feldern je
     * Sprache "name:language_id". Nur Felder aus batchFields(); ein mehrsprachiges
     * Feld ohne Sprachangabe gilt für alle Sprachen.
     *
     * @param array $checked  batch_fields[] des Requests
     * @param array $allowed  batchFields() des Bereichs
     * @return array  [string[] $fields, array $languages (name => int[] language_ids,
     *                nur für Felder mit Sprachauswahl)]
     */
    public function checkedFields(array $checked, array $allowed): array
    {
        $fields = [];
        $languages = [];
        $allLanguages = [];
        foreach ($checked as $value) {
            [$name, $languageId] = array_pad(explode(':', (string)$value, 2), 2, null);
            if (!isset($allowed[$name])) continue;
            $fields[$name] = true;
            if ($languageId === null || !$this->cms->isMultilingualField($allowed[$name])) {
                $allLanguages[$name] = true;
                continue;
            }
            if ((int)$languageId > 0) $languages[$name][] = (int)$languageId;
        }
        return [array_keys($fields), array_diff_key($languages, $allLanguages)];
    }

    /**
     * Auftragsteile für 'edit' aus der Vorlage und den Eingaben der Maske
     * (Klassenkommentar: DIE VORLAGE). Die Vorlage wird danach nicht mehr gebraucht
     * (der Aufrufer verwirft sie mit Record::cancel()).
     *
     * @param int      $templateId  die Vorlage (Record::secureInputs() ist gelaufen)
     * @param string[] $fields      angehakte Felder (bereits gegen batchFields() geprüft)
     * @param array    $data        data[...] der Maske
     * @param array    $allowed     batchFields() des Bereichs
     * @param array    $languages   mehrsprachige Felder mit Sprachauswahl: name =>
     *                              [language_id, ...] (checkedFields()); die übrigen
     *                              Sprachen bleiben unberührt. Ohne Eintrag: alle
     * @return array   ['data' => [], 'prehashed' => [], 'relations' => [], 'files' => [], 'files_dir' => '']
     */
    public function editJob(int $templateId, array $fields, array $data, array $allowed, array $languages = []): array
    {
        $job = ['data' => [], 'prehashed' => [], 'relations' => [], 'files' => [], 'files_dir' => ''];
        $tmpData = $this->record->loadTmpData($templateId);
        $mediaDir = (string)$this->config->get('paths.media_dir');
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $filesDir = '';

        foreach ($fields as $name) {
            $colDef = $allowed[$name];
            $type = (string)($colDef['type'] ?? 'text');
            $displayType = (string)($colDef['display_type'] ?? 'text');
            $fieldId = (int)$colDef['field_id'];
            // Sprachen, die geschrieben werden (mehrsprachige Felder)
            $languageIds = $this->cms->isMultilingualField($colDef)
                ? array_values(array_intersect($this->record->languageIdsOf($templateId), (array)($languages[$name] ?? $this->record->languageIdsOf($templateId))))
                : [];

            // Lookup-Verknüpfung: die data_rel-Zeilen der Vorlage
            if (in_array($type, $this->cms->relationTypes, true) && $displayType === 'lookup') {
                $this->db->pushResult();
                $this->db->query(
                    "SELECT `dest_id` FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? ORDER BY `position`",
                    'ii', [$fieldId, $templateId]
                );
                $job['relations'][$name] = array_map('intval', $this->db->fetchAll());
                $this->db->popResult();
                continue;
            }

            // Datei: Kopie unter var/tmp je Sprache; ohne Datei (bzw. entfernt) null
            if ($type === 'file') {
                $entries = isset($tmpData[$name]) ? $this->record->fileEntries((array)$tmpData[$name]) : [];
                if ($this->cms->isMultilingualField($colDef)) {
                    // je angehakter Sprache ein Eintrag - ohne Datei null (löschen)
                    $byLanguage = [];
                    foreach ($languageIds as $languageId) {
                        $byLanguage[$languageId] = (array)($entries[$languageId] ?? []);
                    }
                    $entries = $byLanguage;
                }
                else $entries = ['' => (array)($entries[''] ?? (reset($entries) ?: []))];
                $job['files'][$name] = [];
                foreach ($entries as $langKey => $fileData) {
                    $extension = (string)($fileData['extension'] ?? '');
                    if ($extension === '' || !empty($fileData['deleted']) || (int)($fileData['size'] ?? 0) < 1) {
                        $job['files'][$name][(string)$langKey] = null;
                        continue;
                    }
                    if ($filesDir === '') {
                        $filesDir = 'batch-files-' . $this->crypt->randomKey(16);
                        if (!$this->system->createDir($tmpDir, $filesDir)) {
                            throw new \RuntimeException("Error: could not create {$tmpDir}/{$filesDir}");
                        }
                        $job['files_dir'] = $tmpDir . '/' . $filesDir;
                    }
                    $languageId = ($langKey === '') ? null : (int)$langKey;
                    $source = $mediaDir . '/' . $this->cms->mediaPath($fieldId, $templateId, $languageId, $extension);
                    $target = $job['files_dir'] . '/' . $name . ($languageId === null ? '' : '.' . $languageId) . '.' . $extension;
                    if (!is_file($source) || !@copy($source, $target)) {
                        $this->system->log('file-errors', "$source > $target : copy failed.");
                        throw new \RuntimeException("Error: could not copy template file for \"{$name}\"");
                    }
                    $job['files'][$name][(string)$langKey] = [
                        'importfile' => $target,
                        'filename'   => (string)($fileData['filename'] ?? basename($target)),
                    ];
                }
                continue;
            }

            // Passwort: der Hash aus der Vorlage (securePasswords()) statt der Eingabe
            if ($type === 'password' || $displayType === 'password') {
                $hash = (string)($tmpData[$name][1] ?? '');
                if ($hash !== '' && trim((string)($data[$name] ?? '')) !== '') {
                    $job['data'][$name] = $hash;
                    $job['prehashed'][] = $name;
                    continue;
                }
            }

            $value = $data[$name] ?? '';
            // Mehrsprachiges Feld: nur die angehakten Sprachen. Die Maske schickt leere
            // Eingaben nicht (serializeFiles()) - eine angehakte Sprache ohne Wert wird
            // geleert; ohne Wert je Sprache bliebe sie sonst unverändert
            if ($this->cms->isMultilingualField($colDef) && (is_array($value) || trim((string)$value) === '')) {
                $value = is_array($value) ? $value : [];
                $byLanguage = [];
                foreach ($languageIds as $languageId) {
                    $byLanguage[$languageId] = $value[$languageId] ?? '';
                }
                $value = $byLanguage;
            }
            $job['data'][$name] = $value;
        }
        return $job;
    }

    /**
     * Die Dateien eines 'edit'-Auftrags unter var/tmp entfernen (nach dem Lauf).
     */
    public function cleanupJob(array $job): void
    {
        $dir = (string)($job['files_dir'] ?? '');
        $tmpDir = realpath((string)$this->config->get('paths.tmp_dir'));
        $real = ($dir !== '') ? realpath($dir) : false;
        if ($real === false || $tmpDir === false || !str_starts_with($real, $tmpDir . DIRECTORY_SEPARATOR)) return;
        foreach (glob($real . '/*') ?: [] as $file) @unlink($file);
        @rmdir($real);
    }

    // ====================
    // Ausführen

    /**
     * Auftrag ausführen - Vordergrund wie Hintergrund.
     *
     * @param array         $job       siehe Klassenkommentar
     * @param callable|null $progress  fn(int $done, int $total) - Fortschrittsmeldung
     * @return array  [
     *     'total'    => int,
     *     'done'     => int,    erfolgreich (bei Simulation: ohne Beanstandung)
     *     'failed'   => int,
     *     'errors'   => [record_id => ['messages' => string[]], ...]
     * ]
     */
    public function run(array $job, ?callable $progress = null): array
    {
        // Audit-Log: Änderungen dieses Laufs als 'batch' kennzeichnen - über die API
        // mit ihrem Kanal (llm-batch, scheduler-batch …); danach gilt wieder die
        // bisherige Herkunft
        $previous = $this->auditLog->context();
        $this->auditLog->setContext(in_array($previous, ['', 'batch'], true) ? 'batch' : $previous . '-batch');
        try {
            return $this->runJob($job, $progress);
        }
        finally {
            $this->auditLog->setContext($previous);
        }
    }

    /** Der eigentliche Lauf (run()) */
    private function runJob(array $job, ?callable $progress): array
    {
        $mode = (string)($job['mode'] ?? '');
        $ids = $this->system->sanitizeIds((array)($job['ids'] ?? []), true);
        $result = ['total' => count($ids), 'done' => 0, 'failed' => 0, 'errors' => []];
        if (!in_array($mode, self::MODES, true)) {
            $result['failed'] = $result['total'];
            $result['errors'][0] = ['messages' => ["invalid mode \"{$mode}\""]];
            return $result;
        }

        $step = 0;
        foreach ($ids as $id) {
            $this->cms->errorsReset();
            $ok = false;
            try {
                $ok = match ($mode) {
                    'edit'   => $this->_editOne($id, $job),
                    'move'   => $this->_moveOne($id, $job),
                    'delete' => $this->_deleteOne($id, $job),
                };
            } catch (\Throwable $e) {
                $this->cms->error($e->getMessage());
            }
            if ($ok) {
                $result['done']++;
            }
            else {
                $result['failed']++;
                $result['errors'][$id] = ['messages' => $this->_errorTexts()];
            }
            $step++;
            if ($progress !== null) $progress($step, $result['total']);
        }
        $this->cms->errorsReset();
        if ($mode === 'edit') $this->cleanupJob($job);
        return $result;
    }

    /**
     * Die Meldungen aus Cms::errors als Klartext-Zeilen ("Label: Text")
     * @return string[]
     */
    private function _errorTexts(): array
    {
        $texts = [];
        foreach ($this->cms->errors as $key => $error) {
            $text = $this->cms->_strip_tags((string)($error['text'] ?? ''));
            if (!is_int($key) && (string)($error['label'] ?? '') !== '') {
                $text = $this->cms->_strip_tags((string)$error['label']) . $this->cms->snippet('colon') . $text;
            }
            $texts[] = $text;
        }
        if (empty($texts)) $texts[] = $this->cms->snippet('record.error_batch_unknown');
        return array_values(array_unique($texts));
    }

    /**
     * edit: die angehakten Felder in EINEM Datensatz ändern - RecordService::
     * updateOne(): Arbeitskopie, Verknüpfungen der Lookup-Felder (ersetzen die
     * bisherigen), Löschmarkierungen und Dateien der Vorlage ('importfile', wie beim
     * Import), Passwörter als Hash ($prehashed), dann Record::importSave() mit den
     * angehakten Feldern - alle übrigen Werte bleiben stehen (Dataset::save()).
     * Simulation: stayTemporary (Prüfung, kein checkIn), danach wird die
     * Arbeitskopie verworfen - samt der unter ihrer Id abgelegten Dateien.
     *
     * Gesperrt ist ein Datensatz, den ein anderer Admin bearbeitet oder den der
     * auslösende Admin selbst noch in einer Maske offen hat
     * (RecordService::loadForChange()).
     */
    private function _editOne(int $id, array $job): bool
    {
        $areaId = (int)($job['area_id'] ?? 0);
        $fields = array_values(array_filter((array)($job['fields'] ?? []), 'is_string'));

        // Nur zulässige Felder (der Request könnte anderes nennen)
        $allowed = $this->batchFields($this->record->virtualSchema($areaId, false));
        $fields = array_values(array_intersect($fields, array_keys($allowed)));
        if (empty($fields)) {
            $this->cms->error($this->cms->snippet('record.error_batch_no_fields'));
            return false;
        }

        $data = [];
        $relations = [];
        $files = [];
        foreach ($fields as $name) {
            $colDef = $allowed[$name];
            $type = (string)($colDef['type'] ?? 'text');
            // Verknüpfungen: Lookup-Felder aus der Vorlage (Ids), über die API auch
            // Operationen (set/add/remove/order, RecordService::applyRelations())
            if (in_array($type, $this->cms->relationTypes, true)
                && (($colDef['display_type'] ?? '') === 'lookup' || array_key_exists($name, (array)($job['relations'] ?? [])))) {
                $relations[$name] = (array)($job['relations'][$name] ?? []);
            }
            elseif ($type === 'file') {
                $files[$name] = (array)($job['files'][$name] ?? []);
            }
            else {
                $data[$name] = $job['data'][$name] ?? '';
            }
        }

        return (bool)$this->service->updateOne($id, array_keys($data), $data, $relations, $files, [
            'area_id'   => $areaId,
            'simulate'  => !empty($job['simulate']),
            'prehashed' => (array)($job['prehashed'] ?? []),
            'stored'    => array_values(array_intersect((array)($job['stored'] ?? []), array_keys($data))),
            'history'   => false,
        ]);
    }

    /**
     * move: EINEN Datensatz in den Zielbereich verschieben (MoveTrait::moveToArea()).
     */
    private function _moveOne(int $id, array $job): bool
    {
        $areaId = (int)($job['area_id'] ?? 0);
        $targetAreaId = (int)($job['target_area_id'] ?? 0);
        $mapping = (array)($job['mapping'] ?? []);
        $simulate = !empty($job['simulate']);
        if (!$this->service->loadForChange($id, 'move', $areaId)) return false;
        return $this->record->moveToArea($id, $targetAreaId, $mapping, $simulate);
    }

    /**
     * delete: EINEN Datensatz löschen - nach der Lösch-Policy seines Bereichs
     * (RecordService::delete(), Modul Privacy). Simulation: nur die Prüfungen.
     */
    private function _deleteOne(int $id, array $job): bool
    {
        $areaId = (int)($job['area_id'] ?? 0);
        if (!$this->service->loadForChange($id, 'delete', $areaId)) return false;
        if (!empty($job['simulate'])) return true;
        return $this->service->delete($id) !== null;
    }

    // ====================
    // Hintergrund

    /**
     * Auftrag als Hintergrundprozess starten: JSON-Datei nach var/tmp, dann
     * Core/cron/record_batch.php (siehe MassDeletion::start()).
     *
     * @param array       $job    siehe Klassenkommentar
     * @param string|null $error  Grund eines Fehlschlags (per Referenz)
     * @return array|null         ['pid' => int, 'token' => string, 'id' => int] oder null
     */
    public function start(array $job, ?string &$error = null): ?array
    {
        $error = '';
        $phpExec = (string)$this->config->get('paths.php');
        if ($phpExec === '' || !is_executable($phpExec)) {
            $error = "Error: 'php' is not executable (paths.php)";
            $this->system->log('batch-errors', $error);
            return null;
        }

        $token = $this->crypt->randomKey(16);
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $jobFile = $tmpDir . '/batch-' . $token . '.json';
        $job['token'] = $token;
        $job['admin_id'] = (int)$this->session->get('admin_id');
        if (file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            $error = "Error: could not write job file {$jobFile}";
            $this->system->log('batch-errors', $error);
            return null;
        }

        $command = [
            $phpExec,
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'record_batch.php',
            '--job=' . $jobFile,
            '--token=' . $token,
        ];
        $result = $this->bgproc->start($command, self::TYPE, $token, null, null, $error);
        if ($result === null) {
            @unlink($jobFile);
            $this->system->log('batch-errors', "start failed: {$error}");
        }
        return $result;
    }

    /**
     * Auftragsdatei des Hintergrundprozesses lesen (und entfernen).
     */
    public function loadJob(string $jobFile): ?array
    {
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $real = realpath($jobFile);
        if ($real === false || !str_starts_with($real, realpath($tmpDir) . DIRECTORY_SEPARATOR)) return null;
        $job = json_decode((string)file_get_contents($real), true);
        @unlink($real);
        return is_array($job) ? $job : null;
    }

    // ====================
    // Bericht

    /**
     * Zusammenfassung als Klartext-Zeilen: Kopf, dann je gescheitertem Datensatz eine
     * Zeile mit Kennung und Meldungen.
     *
     * @return string[]
     */
    public function resultLines(array $job, array $result): array
    {
        $lines = [$this->summaryText($job, $result)];
        foreach ($result['errors'] as $id => $entry) {
            $label = $id > 0
                ? $this->cms->_strip_tags($this->backend->teaser('record', (int)$id, linked: false, icon: false)) . " [{$id}]"
                : '-';
            $lines[] = $label . $this->cms->snippet('colon') . implode(' | ', $entry['messages']);
        }
        return $lines;
    }

    /**
     * Die Kopfzeile: "{Funktion} – {Bereich}: n von m ..." (Simulation gekennzeichnet)
     */
    public function summaryText(array $job, array $result): string
    {
        $areaData = $this->backend->areaData((int)($job['area_id'] ?? 0)) ?? [];
        $areaLabel = $this->cms->_strip_tags($this->cms->textOrSnippet((string)($areaData['label'] ?? '')));
        $text = $this->cms->snippet('record.batch_summary', [
            'mode'   => (string)($job['mode'] ?? ''),
            'total'  => (int)$result['total'],
            'done'   => (int)$result['done'],
            'failed' => (int)$result['failed'],
        ]);
        if (!empty($job['simulate'])) $text = $this->cms->snippet('record.label_batch_simulation') . ' - ' . $text;
        return $areaLabel . $this->cms->snippet('colon') . $text;
    }

    /**
     * Ergebnis des Hintergrundprozesses festhalten: Datei in var/log und interne
     * Nachricht an den auslösenden Admin (Absender ist er selbst - der Prozess läuft
     * unter seiner Kennung, siehe Session::setCliAdmin()).
     *
     * @return string  Pfad der Log-Datei
     */
    public function report(array $job, array $result): string
    {
        $adminId = (int)($job['admin_id'] ?? 0);
        $token = preg_replace('/[^a-z0-9_-]/i', '', (string)($job['token'] ?? 'x'));
        $lines = $this->resultLines($job, $result);

        // Datei
        $relativePath = 'log/' . date('Y-m-d') . '_batch-' . $token . '.log';
        $varDir = (string)$this->config->get('paths.var_dir');
        $this->system->filePutContents($varDir, $relativePath, implode(PHP_EOL, $lines) . PHP_EOL);
        $logFile = $varDir . '/' . $relativePath;

        // Nachricht: Klartext = Datei-Inhalt, HTML-Teil = verlinkte Datensätze
        if ($adminId > 0) {
            $items = [];
            foreach ($result['errors'] as $id => $entry) {
                if ($id < 1) continue;
                $teaser = $this->backend->teaser('record', (int)$id, linked: true, icon: true);
                if ($teaser === '') continue;
                $items[] = '<li>' . $teaser . $this->cms->snippet('colon')
                    . htmlspecialchars(implode(' | ', $entry['messages'])) . '</li>';
            }
            $html = empty($items) ? '' : '<ul>' . implode("\n", $items) . '</ul>';
            $body = implode("\n", $lines) . "\n\n" . $this->cms->snippet('record.batch_report_file') . ' ' . $relativePath;
            $this->message->sendSystem(
                $adminId,
                $this->cms->snippet('record.batch_report_subject', ['mode' => (string)($job['mode'] ?? '')]),
                $body,
                $html
            );
        }
        return $logFile;
    }
}
