<?php
/**
 * Abläufe rund um einen einzelnen Datensatz, die mehrere Oberflächen brauchen:
 * die Eingabemaske (RecordBackendEdit), das Verschieben (RecordBackendMove), die
 * Stapelverarbeitung (RecordBatch), der Import und die API (RecordApi).
 *
 * Record (Dataset + Traits) kennt Speichern, Arbeitskopien und Sperren als einzelne
 * Werkzeuge. Welche davon in welcher Reihenfolge gehören - Rechte vor dem Check-out,
 * Sperre prüfen, eingebettete Inhalte mit auschecken, Check-in samt dieser Inhalte in
 * EINER Transaktion, große Löschvorgänge in den Hintergrund -, stand bisher in den
 * Backend-Klassen, verwoben mit Request und HTML. Hier steht es einmal.
 *
 * Stil wie Record: Rückgabe null bzw. false bei Fehlern, die Meldungen in
 * Cms::errors. Kein Request, keine Ausgabe - die Aufrufer entscheiden, was sie
 * anzeigen (Maske, Bericht der Stapelverarbeitung, Fehlerliste der API).
 *
 * AKTIONEN UND RECHTE (loadForAction())
 * --------------------
 *     read    Leserecht auf den Bereich
 *     edit    Schreibrecht (nach der Gruppe des Owners)
 *     delete  Löschrecht (nach der Gruppe des Owners)
 *     copy    Anlegen im Bereich - und alle Verknüpfungsfelder mit lesbaren
 *             Zielbereichen (die Kopie nähme sonst Verknüpfungen mit, die die
 *             Gruppe nicht sieht)
 *     move    Schreiben + Löschen im Herkunftsbereich, Verknüpfungsfelder wie bei
 *             copy; den Zielbereich prüft MoveTrait::movePrepare()
 *
 * DIE EIGENE OFFENE MASKE (ownOpenCopy())
 * --------------------
 * Eine Arbeitskopie des Session-Admins sperrt ihn selbst nicht (getLock()) - aber
 * checkOut() verwirft sie. Ändert derselbe Admin einen Datensatz auf einem zweiten
 * Weg (Stapelverarbeitung, API, ein LLM in seiner Session), während er ihn in der
 * Maske offen hat, verlöre die Maske ihre Arbeitskopie, und sein Speichern
 * scheiterte. loadForChange() bricht deshalb ab und sagt, dass der Datensatz noch
 * geöffnet ist. Die Maske selbst prüft das nicht: dort ersetzt ein zweites Öffnen
 * bewusst das erste.
 *
 * @see Camindo\Core\Modules\Record\Record                das Dataset
 * @see Camindo\Core\TemporaryTrait                       checkOut / checkIn / getLock
 * @see Camindo\Core\LinkTrait                            eingebettete Inhalte
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\FulltextState;
use Camindo\Core\History;
use Camindo\Core\InstanceCache;
use Camindo\Core\MassDeletion;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Privacy\PrivacyJobs;

class RecordService
{
    public const ACTIONS = ['read', 'edit', 'delete', 'copy', 'move'];

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private Session $session,
        private System $system,
        private Backend $backend,
        private InstanceCache $instanceCache,
        private MassDeletion $massDeletion,
        private History $history,
        private Record $record,
        private PrivacyJobs $privacyJobs,
        private FulltextState $fulltextState,
    )
    {
    }

    // ====================
    // Laden und prüfen

    /**
     * Produktiven Datensatz für eine Aktion laden und die Rechte der Gruppe prüfen
     * (siehe Klassenkommentar).
     *
     * @param int    $id      record_id
     * @param string $action  read | edit | delete | copy | move
     * @return array|null     Datensatz (Record::load()), null bei Fehlern (Cms::errors)
     */
    public function loadForAction(int $id, string $action): ?array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("invalid action \"{$action}\"");
        }
        if (!$data = $this->record->load($id)) return null;
        if (!empty($data['tmp']) || !empty($data['deleted'])) {
            $this->cms->error($this->cms->snippet('record.error_record_not_productive'));
            return null;
        }
        $areaId = (int)$data['area_id'];
        if (!$this->backend->areaData($areaId)) {
            $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            return null;
        }

        $access = $this->backend->access();
        $ownerId = isset($data['owner_id']) ? (int)$data['owner_id'] : null;
        $allowed = match ($action) {
            'read'   => $access->areaRead($areaId),
            // Ändern: auch der aktuelle Status muss für die Gruppe setzbar sein
            'edit'   => $access->recordWrite($areaId, $ownerId, (int)($data['status_id'] ?? 0)),
            'delete' => $access->areaDelete($areaId, $ownerId),
            'copy'   => $access->areaCreate($areaId),
            'move'   => $access->areaWrite($areaId, $ownerId) && $access->areaDelete($areaId, $ownerId),
        };
        if (!$allowed) {
            // Schreibrecht ja, aber Status nicht setzbar: den Grund nennen
            $statusDenied = $action === 'edit' && $access->areaWrite($areaId, $ownerId);
            $this->cms->error($this->cms->snippet($statusDenied ? 'error_status_denied' : 'error_access_denied'));
            return null;
        }
        if (in_array($action, ['copy', 'move'], true) && !$this->record->relationTargetsReadable($areaId)) {
            $this->cms->error($this->cms->snippet($action === 'move' ? 'record.error_move_relation_access' : 'error_access_denied'));
            return null;
        }
        return $data;
    }

    /**
     * Wie loadForAction(), dazu alles, was einer Änderung ohne Eingabemaske im Weg
     * steht: der Datensatz gehört zum erwarteten Bereich, kein anderer Admin hat
     * ihn in Arbeit (außer mit $unlock), und der Session-Admin hat ihn nicht selbst
     * in einer Maske offen (siehe Klassenkommentar).
     *
     * @param int    $id              record_id
     * @param string $action          edit | delete | move
     * @param int    $expectedAreaId  > 0: der Datensatz muss in diesem Bereich stehen
     * @param bool   $unlock          fremde Bearbeitungssperre übergehen
     * @return array|null             Datensatz, null bei Fehlern (Cms::errors)
     */
    public function loadForChange(int $id, string $action, int $expectedAreaId = 0, bool $unlock = false): ?array
    {
        if (!$data = $this->loadForAction($id, $action)) return null;
        if ($expectedAreaId > 0 && (int)$data['area_id'] !== $expectedAreaId) {
            $this->cms->error($this->cms->snippet('error_record_not_found'));
            return null;
        }
        if (!$unlock && ($lock = $this->lockInfo($data))) {
            $this->cms->error($this->lockMessage($lock));
            return null;
        }
        if ($open = $this->ownOpenCopy($id)) {
            $this->cms->error($this->openCopyMessage($open));
            return null;
        }
        return $data;
    }

    /**
     * Bearbeitungssperre eines ANDEREN Admins (TemporaryTrait::getLock()).
     *
     * @param array $data  produktiver Datensatz
     * @return array|null  ['admin_id' => int, 'changed_at' => UTC] oder null
     */
    public function lockInfo(array $data): ?array
    {
        $lock = $this->record->getLock($data);
        if (!$lock) return null;
        return ['admin_id' => (int)$lock[0], 'changed_at' => (string)$lock[1]];
    }

    /**
     * Meldung zu einer Sperre als Klartext ("Gesperrt: wird seit … von … bearbeitet.")
     *
     * @param array $lock  lockInfo()
     */
    public function lockMessage(array $lock): string
    {
        return strtr($this->cms->snippet('record.error_record_locked_since'), [
            '{admin}' => $this->cms->_strip_tags($this->backend->teaser('admin', (int)$lock['admin_id'], linked: false, icon: false)),
            '{date}'  => $this->cms->_formatDate($this->cms->utcToUserTZ(substr((string)$lock['changed_at'], 0, 19))),
        ]);
    }

    /**
     * Eine Arbeitskopie des Session-Admins zu diesem Datensatz, die noch nicht
     * veraltet ist (dieselbe Frist wie getLock(): admin.edit_lock_sec) - also
     * eine Eingabemaske, die er vermutlich noch offen hat.
     *
     * @param int $recordId  produktiver Datensatz
     * @return array|null    ['tmp_id' => int, 'changed_at' => UTC] oder null
     */
    public function ownOpenCopy(int $recordId): ?array
    {
        $adminId = (int)$this->session->get('admin_id');
        if ($adminId < 1 || $recordId < 1) return null;
        $outdatedAt = gmdate('Y-m-d H:i:s', time() - (int)$this->config->get('admin.edit_lock_sec'));
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `record_id`, `changed_at` FROM `record`"
                . " WHERE `tmp` = 1 AND `deleted` = 0 AND `origin_id` = ? AND `changed_by` = ? AND `changed_at` >= ?"
                . " ORDER BY `changed_at` DESC LIMIT 1",
                'iis', [$recordId, $adminId, $outdatedAt]
            );
            $row = $this->db->num_rows > 0 ? $this->db->fetchNum() : null;
        }
        finally {
            $this->db->popResult();
        }
        return $row ? ['tmp_id' => (int)$row[0], 'changed_at' => (string)$row[1]] : null;
    }

    /**
     * Meldung zu ownOpenCopy() als Klartext.
     */
    public function openCopyMessage(array $open): string
    {
        return strtr($this->cms->snippet('record.error_record_open'), [
            '{date}' => $this->cms->_formatDate($this->cms->utcToUserTZ(substr((string)$open['changed_at'], 0, 19))),
        ]);
    }

    /**
     * Mit wie vielen anderen produktiven, nicht gelöschten Datensätzen ist ein
     * Datensatz verknüpft - über irgendein Verweisfeld (relation=any + dest_id).
     * Beidseitige Relationen stehen in `data_rel` in beiden Richtungen und zählen
     * damit mit, auch über Gegenfelder, die in der Maske ausgeblendet sind (etwa
     * die Artikel einer Rubrik). Gebraucht von der Löschbestätigung
     * (RecordBackendEdit::_editDeleteForm()).
     *
     * @param int $recordId  produktiver Datensatz
     * @return int           Anzahl verknüpfter Datensätze
     */
    public function linkedRecordCount(int $recordId): int
    {
        if ($recordId < 1) return 0;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT COUNT(DISTINCT `r`.`src_id`) FROM `data_rel` `r`"
                . " JOIN `record` `s` ON `s`.`record_id` = `r`.`src_id` AND `s`.`tmp` = 0 AND `s`.`deleted` = 0"
                . " WHERE `r`.`dest_id` = ? AND `r`.`src_id` <> ?",
                'ii', [$recordId, $recordId]
            );
            return (int)$this->db->fetchNum()[0];
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Volltext ganzer Bereiche neu aufbauen - alle Volltextfelder und das
     * Volltext-Standardfeld, für jeden produktiven Datensatz (auch die im
     * Papierkorb: sie sollen nach dem Wiederherstellen gefunden werden). Schreibt
     * nur `data_ftx`: keine Arbeitskopie, keine Sperre, kein Audit-Eintrag (der
     * Volltext ist aus den Quellfeldern abgeleitet). Aufgerufen von der
     * Zeitplaner-Aufgabe `fulltext` (SchedulerRunner::runFulltext()) und vom
     * CLI-Befehl `fulltext` (Button "Jetzt aktualisieren").
     *
     * Je Bereich unter der Sperre von FulltextState: baut ein anderer Prozess ihn
     * gerade neu auf, wird er übersprungen ('skipped'). Nach einem vollständigen Lauf
     * fällt die Markierung "veraltet" (FulltextState::clear()).
     *
     * @param int[]         $areaIds   Bereiche; leer = alle Bereiche außer Ordnern
     * @param int           $deadline  Unix-Zeit, ab der abgebrochen wird (0 = ohne Grenze)
     * @param callable|null $progress  fn(int $done, int $total) - Fortschritt je Datensatz
     * @return array        ['areas' => int, 'records' => int, 'complete' => bool, 'skipped' => int[]]
     */
    public function rebuildFulltext(array $areaIds, int $deadline = 0, ?callable $progress = null): array
    {
        $areaIds = $this->system->sanitizeIds($areaIds, true);
        if (empty($areaIds)) {
            $this->db->query("SELECT `area_id` FROM `area` WHERE `deleted` = 0 AND `type` IN ('record', 'profile', 'ugc') ORDER BY `lft`");
            $areaIds = array_map('intval', $this->db->fetchAll());
        }
        $state = $this->fulltextState;
        $result = ['areas' => 0, 'records' => 0, 'complete' => true, 'skipped' => []];
        // Gesamtzahl für den Fortschritt
        $total = 0;
        if ($progress !== null) {
            $this->db->query("SELECT COUNT(*) FROM `record` WHERE `tmp` = 0 AND `area_id` IN (" . implode(',', array_map('intval', $areaIds) ?: [0]) . ")");
            $total = (int)($this->db->fetchNum()[0] ?? 0);
        }
        foreach ($areaIds as $areaId) {
            if (!$state->lock($areaId)) {
                $result['skipped'][] = $areaId;
                continue;
            }
            try {
                $this->db->query("SELECT UTC_TIMESTAMP()");
                $startedAt = (string)$this->db->fetchNum()[0];
                $this->db->query("SELECT `field_id` FROM `field` WHERE `area_id` = ? AND `type` = 'fulltext'", 'i', [$areaId]);
                $fieldIds = array_map('intval', $this->db->fetchAll());
                $this->db->query("SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 0 ORDER BY `record_id`", 'i', [$areaId]);
                $recordIds = array_map('intval', $this->db->fetchAll());
                foreach ($recordIds as $recordId) {
                    if ($deadline > 0 && time() >= $deadline) {
                        $result['complete'] = false;
                        return $result;
                    }
                    foreach ($fieldIds as $fieldId) {
                        $this->record->refreshFulltext($fieldId, $recordId);
                    }
                    $this->record->refreshStandardFulltext($areaId, $recordId);
                    $result['records']++;
                    if ($result['records'] % 50 === 0) {
                        $state->touch($areaId);
                        if ($progress !== null) $progress($result['records'], $total);
                    }
                }
                $state->clear($areaId, $startedAt);
                $result['areas']++;
            }
            finally {
                $state->unlock($areaId);
            }
        }
        if ($progress !== null) $progress($result['records'], $total);
        return $result;
    }

    // ====================
    // Arbeitskopie

    /**
     * Arbeitskopie anlegen: checkOut(), der Session zuordnen (Abmelden gibt sie
     * frei, Record::cancelSession()) und - mit $dependents - die fest verknüpften
     * Inhalte eingebetteter 'link'-Felder mit auschecken. Scheitert das (fremde
     * Sperre an einem Inhalt), wird die Arbeitskopie gleich wieder verworfen.
     *
     * Beim Kopieren ($copy) bekommt $data['previous_id'] die Id des Vorbilds: die
     * Kopie soll unmittelbar hinter ihm stehen, beim selben Vaterdatensatz
     * (TreeManagerTrait::calculatePosition()).
     *
     * Die Sperre prüft der Aufrufer vorher (lockInfo() bzw. loadForChange()).
     *
     * @param array &$data       produktiver Datensatz (loadForAction()); zeigt danach
     *                           auf die Arbeitskopie (record_id, tmp, origin_id)
     * @param bool  $copy        Kopie statt Bearbeitung
     * @param bool  $dependents  eingebettete Inhalte mit auschecken (Eingabemaske)
     * @return int|null          Id der Arbeitskopie, null bei Fehlern (Cms::errors)
     */
    public function checkOut(array &$data, bool $copy = false, bool $dependents = true): ?int
    {
        $sourceId = (int)$data['record_id'];
        $areaId = (int)$data['area_id'];
        if (!$tmpId = $this->record->checkOut($data, $copy)) {
            if (!$this->cms->hasErrors) $this->cms->error('RecordService::checkOut(): check-out failed.');
            return null;
        }
        $this->session->registerWorkingCopy($tmpId);
        if ($copy) $data['previous_id'] = $sourceId;

        if ($dependents) {
            $schemaVirtual = $this->record->virtualSchema($areaId, addMetaFields: false);
            if (!$this->record->checkOutDependents($tmpId, $copy, $schemaVirtual)) {
                $this->record->cancel($tmpId);
                $data = $this->record->load($sourceId) ?? $data;
                return null;
            }
        }
        return $tmpId;
    }

    /**
     * Die Felder, die das Speichern einer Arbeitskopie schreibt: alle Felder des
     * Bereichs, area_id, und owner_id nur mit dem Zusatzrecht (areaSetOwner) und
     * wenn einer angegeben ist.
     *
     * @param int   $areaId
     * @param array $data  zu speichernde Werte
     * @return string[]
     */
    public function saveFields(int $areaId, array $data): array
    {
        $fields = array_keys($this->record->virtualSchema($areaId, addMetaFields: false));
        $fields[] = 'area_id';
        if ($this->backend->access()->areaSetOwner($areaId) && !empty($data['owner_id'])) {
            $fields[] = 'owner_id';
        }
        return $fields;
    }

    /**
     * Arbeitskopie einchecken: in EINER Transaktion zuerst die fest verknüpften
     * Inhalte (LinkTrait::checkInDependents(): auch Kopien und Verwaiste), dann der
     * Datensatz selbst (Record::save() - Validierung, checkIn, Dateien, Baum).
     * Scheitert eines, kommt alles zurück; die Arbeitskopien bleiben stehen.
     * Danach die Liste "Zuletzt geändert" (History).
     *
     * Die eingebetteten Inhalte müssen vorher zwischengespeichert sein
     * (RecordBackendEdit::_embedSave()).
     *
     * @param int      $tmpId      Arbeitskopie des Session-Admins
     * @param string[] $fields     zu schreibende Felder (saveFields())
     * @param array    $data       Werte im Eingabeformat der Maske, record_id = $tmpId
     * @param string[] $stored     Felder, deren Werte im Datenbankformat vorliegen
     *                             (Record::importSave() - API, Kopie ohne Maske)
     * @param string[] $prehashed  Passwort-Felder, deren Wert der Hash ist
     * @return int|null            record_id des produktiven Datensatzes, null bei Fehlern
     */
    public function checkIn(int $tmpId, array $fields, array $data, array $stored = [], array $prehashed = []): ?int
    {
        // Gehört die Kopie nicht (mehr) dem Session-Admin - jemand hat die Sperre
        // aufgehoben -, meldet save() das mit dem passenden Text
        $prodData = $this->record->getProdData($tmpId);
        $originId = (int)($prodData[0] ?? 0);
        $areaId = (int)($prodData[1] ?? $data['area_id'] ?? 0);
        $schemaVirtual = $this->record->virtualSchema($areaId, addMetaFields: false);
        $hasLinks = !empty($this->record->linkFields($schemaVirtual));
        $data['record_id'] = $tmpId;

        $newId = null;
        $this->db->beginTransaction();
        try {
            if ($hasLinks && !$this->record->checkInDependents($tmpId, $originId)) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $newId = ($stored || $prehashed)
                ? $this->record->importSave($fields, $data, $stored, $prehashed)
                : $this->record->save($fields, $data);
            if (!$newId) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $this->db->commit();
        }
        catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            $this->db->rollback();
            return null;
        }
        $this->history->touch('record', (int)$newId);
        return (int)$newId;
    }

    // ====================
    // Ändern ohne Maske

    /**
     * Einen Datensatz in EINEM Schritt ändern: laden und prüfen (loadForChange()),
     * auschecken, Verknüpfungen und Dateien an die Arbeitskopie, speichern
     * (Record::importSave(): Validierung nach den Felddefinitionen, checkIn, Cache,
     * Volltext, Baumpflege - alles wie in der Eingabemaske). Scheitert die Prüfung
     * oder ist es eine Simulation, wird die Arbeitskopie verworfen - die
     * Fehlermeldungen bleiben dabei erhalten.
     *
     * Eingebettete Inhalte ('link') werden nicht mit ausgecheckt: sie werden hier
     * nicht bearbeitet, ihre Verknüpfungen gehen mit dem Datensatz hin und zurück.
     *
     * @param int      $id         produktiver Datensatz
     * @param string[] $fields     Felder aus $data (Schema-Namen des Bereichs); dazu
     *                             'owner_id' (nur mit dem Recht "Owner anpassen") und
     *                             'position' (Wert: $data['previous_id'])
     * @param array    $data       Werte wie in der Maske (data[...]), mehrsprachig je
     *                             language_id
     * @param array    $relations  Verknüpfungsfeld => [dest_id, ...] (ersetzt die
     *                             bisherigen) oder ['set' | 'add' | 'remove' |
     *                             'order' => [dest_id, ...]] - siehe applyRelations()
     * @param array    $files      Upload-Feld => [langKey ('' oder language_id) =>
     *                             ['importfile' => Pfad unter paths.tmp_dir, 'filename' => Name]
     *                             | ['serverfile' => Pfad in paths.upload_dir]
     *                             | null (Datei entfernen)]
     * @param array    $options    area_id (erwarteter Bereich), unlock, simulate,
     *                             stored (Felder im Datenbankformat), prehashed
     *                             (Passwort-Felder, deren Wert der Hash ist),
     *                             history (false: nicht in "Zuletzt geändert" -
     *                             Stapelverarbeitung)
     * @return int|null            record_id, null bei Fehlern (Cms::errors)
     */
    public function updateOne(int $id, array $fields, array $data, array $relations = [], array $files = [], array $options = []): ?int
    {
        $simulate = !empty($options['simulate']);
        $record = $this->loadForChange($id, 'edit', (int)($options['area_id'] ?? 0), !empty($options['unlock']));
        if (!$record) return null;
        $areaId = (int)$record['area_id'];
        $fields = $this->changeFields($areaId, $fields, $relations, $files);
        if ($fields === null) return null;

        if (!$tmpId = $this->checkOut($record, false, dependents: false)) return null;

        $saveData = ['record_id' => $tmpId, 'area_id' => $areaId];
        $ok = $this->applyChanges($tmpId, $areaId, $fields, $data, $relations, $files, $saveData);
        $fields[] = 'area_id';

        if ($ok) {
            $ok = (bool)$this->record->importSave(
                $fields, $saveData, (array)($options['stored'] ?? []), (array)($options['prehashed'] ?? []),
                stayTemporary: $simulate
            );
        }
        if ($simulate || !$ok) {
            // Simulation: kein checkIn - Fehler: Arbeitskopie nicht stehen lassen
            $this->cancelKeepErrors($tmpId);
            return $ok ? $id : null;
        }
        if (($options['history'] ?? true) !== false) $this->history->touch('record', $id);
        return $id;
    }

    /**
     * Einen Datensatz in EINEM Schritt anlegen - wie updateOne(), nur mit einer
     * neuen Arbeitskopie (tmpInit(), prüft das Recht zum Anlegen). Nicht in
     * abhängigen Bereichen (area.dependent): deren Datensätze entstehen nur über ein
     * Feld vom Typ 'link'.
     *
     * @param int   $areaId   Bereich
     * @param array $options  simulate, stored, prehashed (siehe updateOne())
     * @return int|null       record_id des neuen Datensatzes (Simulation: 0)
     */
    public function createOne(int $areaId, array $fields, array $data, array $relations = [], array $files = [], array $options = []): ?int
    {
        $areaData = $this->backend->areaData($areaId);
        if (!$areaData) {
            $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            return null;
        }
        if ((int)($areaData['dependent'] ?? 0) === 1) {
            $this->cms->error($this->cms->snippet('error_access_denied'));
            return null;
        }
        $fields = $this->changeFields($areaId, $fields, $relations, $files, allowEmpty: true);
        if ($fields === null) return null;
        if (!$tmpData = $this->record->tmpInit(['area_id' => $areaId])) {
            if (!$this->cms->hasErrors) $this->cms->error('RecordService::createOne(): creation of working copy failed.');
            return null;
        }
        $tmpId = (int)$tmpData['record_id'];
        $this->session->registerWorkingCopy($tmpId);

        $saveData = ['record_id' => $tmpId, 'area_id' => $areaId];
        $ok = $this->applyChanges($tmpId, $areaId, $fields, $data, $relations, $files, $saveData);
        $fields[] = 'area_id';
        $simulate = !empty($options['simulate']);
        $newId = null;
        if ($ok) {
            $newId = $this->record->importSave(
                $fields, $saveData, (array)($options['stored'] ?? []), (array)($options['prehashed'] ?? []),
                stayTemporary: $simulate
            );
        }
        if ($simulate || !$newId) {
            $this->cancelKeepErrors($tmpId);
            return $newId ? 0 : null;
        }
        $this->history->touch('record', (int)$newId);
        return (int)$newId;
    }

    /**
     * Einen Datensatz in EINEM Schritt kopieren und dabei ändern - wie "Kopieren" in
     * der Maske: die Kopie samt Dateien und fest verknüpften Inhalten (als Kopien)
     * steht unmittelbar hinter dem Vorbild; Kinder im Baum werden nicht mitkopiert.
     * Geändert wird, was in $fields steht; alles andere übernimmt die Kopie.
     *
     * @param int   $id       Vorbild (produktiv)
     * @param array $options  simulate, stored, prehashed (siehe updateOne())
     * @return int|null       record_id der Kopie (Simulation: 0)
     */
    public function copyOne(int $id, array $fields, array $data, array $relations = [], array $files = [], array $options = []): ?int
    {
        if (!$record = $this->loadForAction($id, 'copy')) return null;
        $areaId = (int)$record['area_id'];
        $fields = $this->changeFields($areaId, $fields, $relations, $files, allowEmpty: true);
        if ($fields === null) return null;
        if (!$tmpId = $this->checkOut($record, true, dependents: true)) return null;

        $saveData = ['record_id' => $tmpId, 'area_id' => $areaId];
        $ok = $this->applyChanges($tmpId, $areaId, $fields, $data, $relations, $files, $saveData);
        $fields[] = 'area_id';
        // hinter dem Vorbild einordnen (checkOut() hat previous_id gesetzt)
        $schemaVirtual = $this->record->virtualSchema($areaId, addMetaFields: false);
        if (isset($schemaVirtual['position']) && !in_array('position', $fields, true)) {
            $fields[] = 'position';
            $saveData['previous_id'] = $id;
        }
        if (!$ok || !empty($options['simulate'])) {
            if ($ok) {
                $ok = (bool)$this->record->importSave($fields, $saveData, (array)($options['stored'] ?? []),
                    (array)($options['prehashed'] ?? []), stayTemporary: true);
            }
            $this->cancelKeepErrors($tmpId);
            return $ok ? 0 : null;
        }
        $newId = $this->checkIn($tmpId, $fields, $saveData, (array)($options['stored'] ?? []), (array)($options['prehashed'] ?? []));
        if (!$newId) $this->cancelKeepErrors($tmpId);
        return $newId;
    }

    /**
     * Die Felder einer Änderung prüfen: Felder des Bereichs mit Eingabefeld (keine
     * Volltext- und Thumbnail-Felder, keine eingebetteten Inhalte), dazu 'owner_id'
     * mit dem Recht "Owner anpassen".
     *
     * @return string[]|null  Felder (aus $fields, $relations und $files); null bei
     *                        Fehlern (Cms::errors) bzw. ohne Felder (außer $allowEmpty)
     */
    private function changeFields(int $areaId, array $fields, array $relations, array $files, bool $allowEmpty = false): ?array
    {
        $schemaVirtual = $this->record->virtualSchema($areaId, addMetaFields: false);
        $fields = array_values(array_unique(array_merge(
            array_filter($fields, 'is_string'), array_keys($relations), array_keys($files)
        )));
        $ok = true;
        foreach ($fields as $name) {
            if ($name === 'owner_id' && $this->backend->access()->areaSetOwner($areaId)) continue;
            $colDef = $schemaVirtual[$name] ?? null;
            $type = (string)($colDef['type'] ?? '');
            if ($colDef === null
                || $type === 'fulltext'
                || (int)($colDef['parent_field_id'] ?? 0) > 0
                || in_array($type, $this->cms->dependentTypes, true)
                || !$this->record->relationTargetReadable($colDef)
            ) {
                $this->cms->error(strtr($this->cms->snippet('error_field_unknown_type'), ['{name}' => $name]), $name);
                $ok = false;
            }
        }
        if (!$ok) return null;
        if (empty($fields) && !$allowEmpty) {
            $this->cms->error($this->cms->snippet('record.error_batch_no_fields'));
            return null;
        }
        return $fields;
    }

    /**
     * Die Änderungen an eine Arbeitskopie bringen und $saveData füllen:
     * Verknüpfungen (applyRelations()), Dateien (Löschmarkierung bzw. Quelle für
     * secureUploads()), die übrigen Werte aus $data; 'position' nimmt
     * $data['previous_id'].
     *
     * @return bool  false bei Fehlern (Cms::errors)
     */
    private function applyChanges(int $tmpId, int $areaId, array $fields, array $data, array $relations, array $files, array &$saveData): bool
    {
        $schemaVirtual = $this->record->virtualSchema($areaId, addMetaFields: false);
        $ok = true;
        foreach ($fields as $name) {
            $colDef = $schemaVirtual[$name] ?? [];
            $type = (string)($colDef['type'] ?? '');
            if (array_key_exists($name, $relations)) {
                if (!in_array($type, $this->cms->relationTypes, true)) {
                    $this->cms->error($this->cms->snippet('error_param_invalid'), $name);
                    $ok = false;
                    continue;
                }
                $destIds = $this->applyRelations($tmpId, $colDef, $relations[$name]);
                if ($destIds === null) {
                    $ok = false;
                    continue;
                }
                // save() prüft die Anzahl, checkIn() überträgt sie
                $saveData[$name] = $destIds;
                continue;
            }
            if (array_key_exists($name, $files)) {
                if ($type !== 'file') {
                    $this->cms->error($this->cms->snippet('error_param_invalid'), $name);
                    $ok = false;
                    continue;
                }
                $isMultilingual = $this->cms->isMultilingualField($colDef);
                $value = [];
                foreach ((array)$files[$name] as $langKey => $fileData) {
                    $languageId = ((string)$langKey === '') ? null : (int)$langKey;
                    if ($fileData === null) {
                        if (!$this->record->markFileDeleted($tmpId, (int)$colDef['field_id'], $languageId)) $ok = false;
                        continue;
                    }
                    if ($isMultilingual && $languageId !== null) $value[$languageId] = $fileData;
                    else $value = $fileData;
                }
                if (!empty($value)) $saveData[$name] = $value;
                continue;
            }
            if ($name === 'position') {
                $saveData['previous_id'] = (int)($data['previous_id'] ?? 0);
                continue;
            }
            $saveData[$name] = $data[$name] ?? '';
        }
        return $ok;
    }

    /**
     * Verknüpfungen eines Feldes an einer Arbeitskopie ändern.
     *
     *     [id, id, …]            wie 'set'
     *     ['set' => [...]]       ersetzt alle vorhandenen
     *     ['add' => [...]]       hängt an (vorhandene bleiben)
     *     ['remove' => [...]]    entfernt diese
     *     ['order' => [...]]     Reihenfolge (nur Felder mit manueller Sortierung);
     *                            nicht genannte folgen dahinter
     *
     * 'add', 'remove' und 'order' lassen sich kombinieren (in dieser Reihenfolge).
     *
     * @return int[]|null  die Verknüpfungen danach in ihrer Reihenfolge; null bei
     *                     Fehlern (Cms::errors)
     */
    public function applyRelations(int $tmpId, array $colDef, array $ops): ?array
    {
        if (array_is_list($ops)) $ops = ['set' => $ops];
        $ids = fn(string $key) => array_values(array_filter(array_map('intval', (array)($ops[$key] ?? [])), fn($id) => $id > 0));
        try {
            if (array_key_exists('set', $ops)) {
                $this->record->createRelations($colDef, $tmpId, $ids('set'), replace: true, checkTmp: false);
            }
            if ($ids('add')) {
                $this->record->createRelations($colDef, $tmpId, $ids('add'), replace: false, checkTmp: false);
            }
            foreach ($ids('remove') as $destId) {
                $this->record->removeRelations($colDef, $tmpId, $destId);
            }
        } catch (\Exception $e) {
            if (!str_starts_with($e->getMessage(), '#')) $this->cms->error($e->getMessage(), (string)$colDef['name']);
            return null;
        }
        $current = $this->relationIds($tmpId, (int)$colDef['field_id']);
        if ($order = $ids('order')) {
            $order = array_values(array_intersect($order, $current));
            $current = array_values(array_merge($order, array_diff($current, $order)));
            $this->record->linkReorder($tmpId, (int)$colDef['field_id'], $current);
        }
        return $current;
    }

    /** dest_ids eines Feldes einer Arbeitskopie in ihrer Reihenfolge */
    private function relationIds(int $srcId, int $fieldId): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `dest_id` FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? ORDER BY `position`, `dest_id`",
                'ii', [$fieldId, $srcId]);
            return array_map('intval', $this->db->fetchColumn());
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Arbeitskopie verwerfen, ohne die bis dahin gesammelten Fehlermeldungen zu
     * verlieren (cancel() setzt selbst Abfragen und ggf. Meldungen ab).
     */
    public function cancelKeepErrors(int $tmpId): void
    {
        $errors = $this->cms->errors;
        $this->record->cancel($tmpId);
        $this->cms->errorsReset();
        foreach ($errors as $key => $error) {
            $this->cms->error((string)$error['text'], is_int($key) ? '' : (string)$key, (string)($error['label'] ?? ''));
        }
    }

    // ====================
    // Löschen

    /**
     * Datensatz löschen - nach der Lösch-Policy seines Bereichs (Modul Privacy,
     * PrivacyJobs::delete(), Auslöser 'record'). Was "löschen" heißt, sagt die
     * Policy: in den Papierkorb, Felder überschreiben, endgültig löschen … - auch für
     * die mitgelöschten Kinder ($withChilds) und fest verknüpften Inhalte.
     *
     * Rechte und Sperre prüft der Aufrufer (loadForAction() / loadForChange()).
     *
     * Wird die Arbeitskopie des Session-Admins übergeben (Löschdialog der Maske),
     * ist das Original gemeint; die Arbeitskopie wird vorher verworfen - sie stünde
     * sonst bis zum Leeren des Papierkorbs bzw. käme beim Überschreiben der Felder
     * in die Quere.
     *
     * @param int  $id          produktiver Datensatz oder dessen Arbeitskopie
     * @param bool $withChilds  Nachkommen mit löschen (eigene Policy-Aufträge; in den
     *                          Papierkorb mit der Aktion soft_delete des Datensatzes),
     *                          sonst rücken sie eine Ebene hoch
     * @return string|null      'deleted' | 'background' | 'changed' | 'scheduled',
     *                          null bei Fehlern (Cms::errors)
     */
    public function delete(int $id, bool $withChilds = false): ?string
    {
        $prodData = $this->record->getProdData($id);
        $tmpId = ($prodData !== null && $prodData[0] > 0) ? $id : 0;
        $prodId = $tmpId > 0 ? (int)$prodData[0] : $id;
        if ($tmpId > 0) $this->cancelKeepErrors($tmpId);
        return $this->privacyJobs->delete($prodId, 'record', fn(int $recordId) => $this->softDelete($recordId, $withChilds), $withChilds);
    }

    /**
     * In den Papierkorb (Soft-Delete, Record::delete()) - die Aktion soft_delete
     * einer Lösch-Policy, ausgelöst im Backend. Ein großer Teilbaum ($withChilds, ab
     * admin.mass_delete_threshold) geht in den Hintergrund (MassDeletion); scheitert
     * dessen Start, wird im Request gelöscht.
     *
     * @return string|null  'deleted' | 'background', null bei Fehlern (Cms::errors)
     */
    private function softDelete(int $prodId, bool $withChilds): ?string
    {
        // Gezählt und im Hintergrund gestartet wird über die produktive Id - die
        // Arbeitskopie hat lft = rgt = 0, der Teilbaum hängt am Original
        $result = null;
        $count = $this->massDeletion->affectedCount('record', $prodId, $withChilds, 'delete');
        if ($this->massDeletion->needsBackground($count)) {
            $error = '';
            if ($this->massDeletion->start('record', $prodId, 'delete', $withChilds, $error)) {
                $result = 'background';
            }
            else {
                // Start fehlgeschlagen -> im Request weitermachen statt gar nicht zu löschen
                $this->system->log('massdelete-errors', "record {$prodId}: {$error}");
            }
        }
        if ($result === null && $this->record->delete($prodId, $withChilds)) $result = 'deleted';
        return $result;
    }

    // ====================
    // Export und Import

    /**
     * Welche Formate darf die Gruppe exportieren bzw. importieren? XML (Sicherung,
     * Strukturübertragung, auch global) nur der Superuser, CSV und DOCX über die
     * Zusatzrechte export_csv/import_csv bzw. export_docx/import_docx.
     *
     * @param string $direction  'export' | 'import'
     * @return string[]  Teilmenge von RecordExport::TYPES in deren Reihenfolge
     */
    public function transferTypes(string $direction): array
    {
        $access = $this->backend->access();
        $types = [];
        if ($access->isSuperuser()) $types[] = 'xml';
        if ($access->moduleRight('Record', $direction . '_csv')) $types[] = 'csv';
        if ($access->moduleRight('Record', $direction . '_docx')) $types[] = 'docx';
        return $types;
    }

    // ====================
    // Suche

    /**
     * Suchfeld '_url' auflösen (Suchmaske, RecordBackendList, API): eingefügt werden
     * darf eine record_id ("4902"), ein Slug mit oder ohne Slug-Präfix ("gt",
     * "modelle/gt") oder eine URL ("http://automobile.localhost/de_DE/modelle/gt").
     *
     *     Ziffern                    -> record_id
     *     URL                        -> Pfad; die Domain grenzt auf ihre Instanz ein
     *     Sprachsegment am Anfang    -> ignoriert (nur mit weiterem Pfad dahinter)
     *     ",template" am Ende        -> ignoriert
     *     erster Pfadteil = Präfix   -> slug = Rest, area_id = die Bereiche mit diesem
     *                                   Präfix (in irgendeiner Sprache, InstanceCache)
     *     sonst                      -> slug = ganzer Pfad
     *
     * Der Slug wird exakt verglichen ('==').
     *
     * @return array  Filterwerte: record_id | slug [, area_id (int[]), instance_id]
     *                - [] bei leerer Eingabe
     */
    public function urlFilter(string $input): array
    {
        $input = trim($input);
        if ($input === '') return [];
        if (ctype_digit($input)) return ['record_id' => $input];

        $instanceIds = [];
        $path = $input;
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $input)) {
            $url = parse_url($input) ?: [];
            $path = (string)($url['path'] ?? '');
            $instanceId = $this->instanceCache->matchDomain(strtolower((string)($url['host'] ?? '')));
            if ($instanceId > 0) $instanceIds = [$instanceId];
        }
        $path = (string)preg_replace('/[?#].*$/s', '', $path);
        $segments = array_values(array_filter(explode('/', rawurldecode($path)), fn($p) => trim($p) !== ''));
        if ($segments && strtolower($segments[0]) === 'index.php') array_shift($segments);

        // Sprachsegment (Kürzel einer Sprache der Instanz bzw. aller Instanzen)
        if (count($segments) > 1) {
            $languages = $this->instanceCache->languagesOf($instanceIds
                ?: array_map('intval', (array)($this->instanceCache->all()['instance_ids'] ?? [])));
            if ($this->instanceCache->findByCode($languages, $segments[0]) !== null) array_shift($segments);
        }
        // Template-Angabe "slug,template"
        if ($segments) {
            $last = array_pop($segments);
            $segments[] = explode(',', $last, 2)[0];
        }
        $path = trim(implode('/', $segments), '/');
        if ($path === '') return ['record_id' => '0'];
        if (ctype_digit($path)) return ['record_id' => $path];

        $filter = [];
        if (count($segments) > 1) {
            $first = mb_strtolower($segments[0], 'UTF-8');
            $areaIds = [];
            foreach ($this->instanceCache->slugPrefixAreas($instanceIds) as $areaId => $prefixes) {
                foreach ($prefixes as $prefix) {
                    if (mb_strtolower((string)$prefix, 'UTF-8') === $first) {
                        $areaIds[] = (int)$areaId;
                        break;
                    }
                }
            }
            if ($areaIds) {
                $path = implode('/', array_slice($segments, 1));
                $filter['area_id'] = $areaIds;
            }
        }
        $filter['slug'] = '==' . $path;
        if ($instanceIds) $filter['instance_id'] = (string)$instanceIds[0];
        return $filter;
    }
}
