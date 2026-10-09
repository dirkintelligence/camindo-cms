<?php
 /**
 * Trait für Dataset Kind-Klassen
 * Verarbeitet Daten aus virtuellen Tabellenfeldern in den data-Tabellen
 *
 * Konzept "Einzelschritt-Updates" (Dataset::save() mit $stayTemporary = true):
 * 
 * Konzept "Dateien sofort sichern, Thumbnails später":
 * Eine Datei (Upload aus $_FILES oder ausgewählte Server-Datei aus $data) wird
 * SOFORT auf Typ geprüft und - wenn zulässig - physisch unter der Id der
 * Arbeitskopie abgelegt und in deren Arbeitsstand eingetragen (secureUploads()).
 * Damit übersteht sie eine anschließend scheiternde Eingabeprüfung und erscheint
 * in der erneut angezeigten Eingabemaske.
 * Die Thumbnail-Erzeugung ist davon entkoppelt: der Datei-Eintrag wird mit
 * 'thumbnails_pending' = true markiert und erst beim FINALEN Speichern über
 * writePendingThumbnails() (aus saveVirtualFields()) abgearbeitet - also einmal
 * pro Datensatz statt bei jedem Zwischenschritt.
 *
 * Felder:
 *      'tmp'             : Zustand 0 = produktiv, 1 = temporär
 *      'origin_id'       : `{tableName}_id` des produktiven Originals, 0 bei neuem Datensatz
 *      'changed_at'      : Zeitpunkt der letzten Änderung
 *      'changed_by'      : Admin-Id der letzten Änderung
 *      'deleted'         : 1 = wenn produktiv: Datensatz wurde gelöscht (Papierkorb)
 *                              wenn temporär: Arbeitskopie kann nicht mehr produktiv gehen (Edit-Lock geklöscht)
 * 
 * Tabelle `record` zusätzlich - der ARBEITSSTAND der Arbeitskopie:
 *      Er liegt nicht in einer eigenen Spalte, sondern wie der produktive Stand je
 *      Sprache in `record_cache` - unter der Id der Arbeitskopie, im selben Format
 *      (Cms::createRecordCache(): feldname => [typ, wert, Fallback-Kennzeichen]).
 *      Cms::fetch() liest eine Arbeitskopie damit genauso wie einen produktiven
 *      Datensatz. Er dient als Zwischenspeicher für Einzelschritt-Updates
 *      (Dataset::save() mit $stayTemporary = true - geschrieben werden nur die
 *      Sprachzeilen, die sich ändern) und als Datenbasis der Eingabemaske /
 *      Datei-Previews; beim checkOut wird er aus dem `record_cache` des produktiven
 *      Originals übernommen (VirtualFieldsTrait::_loadTmpData() / _saveTmpData()),
 *      beim checkIn fällt er mit der Arbeitskopie weg (FK-Cascade).
 *      Verschlüsselte Inhalte stehen darin als Geheimtext, Passwörter gar nicht.
 *                          Datei-Einträge tragen Flags:
 *                          'temporary' = true: neue Datei der Arbeitskopie (physisch
 *                              unter deren Id abgelegt; der `data_fil`-Eintrag entsteht
 *                              erst beim checkIn, siehe promoteTmpFiles())
 *                          'deleted' = true: Datei wurde per x-Button entfernt
 *                          Einträge OHNE Flags beschreiben die unveränderte Datei
 *                          des produktiven Originals (origin_id)
 */
namespace Camindo\Core;

trait TemporaryTrait
{
    /**
     * CREATE
     * Temporären Datensatz erstellen
     * (Um temporäre Relations hinzufügen zu können)
     */
    public function tmpInit(array $defaults = [], int $forceId = 0): ?array
    {
        $idField = $this->tableName . '_id';

        // area_id - auch im temporären Datensatz speichern, kann gut für lookup-Feld gebraucht werden
        $areaId = $this->hasAreaId ? (int)$defaults['area_id'] : null;

        // Rechte der Benutzergruppe: Anlegen im Bereich
        if ($areaId !== null && !$this->accessCheck('create', $areaId)) return null;

        // $forceId: die Arbeitskopie bekommt diese Id und geht später unter ihr
        // produktiv - der Import legt so Datensätze mit der Id aus der Importdatei an
        // (RecordImport); die Id muss frei sein (Record::freeRecordId())
        if (!$tmpId = $this->insertTmpRecord(0, $areaId, 0, $forceId)) {
            return null;
        }
        $data = $this->init($defaults);
        $data[$idField] = $tmpId;
        $data['tmp'] = 1;
        return $data;
    }

    /**
     * Prüfe, ob Temporäre Arbeitskopie für einen produktiven Datensatz besteht
     * 
     * @param array &$data  Metafeld-Inhalte
     * @return array        Wenn Editlock besteht: [changed_by, changed_at], ansonsten []
     */
    public function getLock(array &$data): array
    {
        if (!empty($data['tmp'])) {
            throw new \Exception('isLocked() Exception: dataset is not productive.');
        }
        $tableName = $this->tableName;
        $idField = $tableName . '_id';
        $id = (int)$data[$idField];
        
        // Änderungsdatum und -Admin stecken in der temporären Kopie
        $sql = "SELECT `changed_by`,`changed_at` FROM `$tableName`"
            ." WHERE `tmp`=1 AND `deleted`=0 AND `origin_id` = ? ORDER BY `changed_at` DESC";
        $this->db->query($sql, 'i', [$id]);
        if ($this->db->num_rows < 1) {
            // Keine Kopie gefunden
            return [];
        }
        [$changedBy, $changedAt] = $this->db->fetchNum();

        // Prüfe letzte Änderung (changed_at ist UTC-Zeit, datetime(3)) - eine Kopie,
        // die länger als admin.edit_lock_sec unberührt ist, sperrt nicht mehr
        $lockSec = (int)$this->config->get('admin.edit_lock_sec');
        $outdatedAt = gmdate('Y-m-d H:i:s', time() - $lockSec);
        if ($changedAt < $outdatedAt) {
            // Letzte Änderung an der Kopie liegt zuweit zurück
            // -> auch wenn von anderem Admin: sie kann gelöscht werden
            return [];
        }

        // Prüfe wer die temporäre Kopie erstellt hat
        $sessionAdminId = (int)$this->session->get('admin_id');
        if ($changedBy === $sessionAdminId) {
            // Die Kopie stammt vom Session-Admin selbst
            // -> ignoriere Lock, gehe davon aus, dass Admin weiß, was er tut
            return [];
        }

        // Edit-Lock berücksichtigen
        return [$changedBy, $changedAt];
    }

    /**
     * CHECKOUT
     * Erzeugt eine temporäre Arbeitskopie eines produktiven Datensatzes
     * Voraussetzungen:
     * - Datensatz ist produktiv (tmp=0, deleted=0)
     * - Datensatz wurde mit load() geladen
     * 
     * Edit-Lock sollte vorher geprüft werden, siehe
     * - Dataset::getLock()
     * 
     * Urpsprungsdatensatz erhält:
     * - `locked` = 1
     * 
     * Arbeitskopie erhält:
     * - `tmp` = 1
     * - `origin_id`  = Id des Ursprungsdatensatzes
     * - `changed_by` = sessionAdminId
     * - `changed_at` = UTC_TIMESTAMP()
     * - `record_cache`-Zeilen (nur Tabelle `record`): der produktive Stand aller
     *                  Sprachen als Arbeitsstand - Zwischenspeicher für
     *                  Einzelschritt-Updates und Datenbasis der Eingabemaske
     * 
     * @param array &$data  aus vorangehendem Dataset::load()
     * @param bool $copy    true: Es wird eine Kopie mit origin_id = 0 erstellt
     *                        Ursprungsdatensatz wird nicht gelockt
     * @return int|null     `{tableName}_id` der Arbeitskopie, oder null bei Fehler
     */
    public function checkOut(array &$data, $copy = false): ?int
    {
        $tableName = $this->tableName;
        $idField   = $tableName . '_id';
        $sessionAdminId   = $this->adminIdForDb();
        $id = (int)$data[$idField];

        if ($id < 1) {
            $this->cms->error('makeTemporary() - Error: invalid origin id.');
            return null;
        }

        if ((int)$data['tmp'] !== 0 || (int)$data['deleted'] > 0) {
            $this->cms->error('makeTemporary() - Error: dataset is not productive.');
            return null;
        }

        // Rechte der Benutzergruppe: Ändern des Originals bzw. Anlegen der Kopie
        if (!$this->accessCheck($copy ? 'create' : 'write', (int)($data['area_id'] ?? 0), isset($data['owner_id']) ? (int)$data['owner_id'] : null)) {
            return null;
        }

        // Transaktion (Database unterstützt Verschachtelung)
        try {
            $this->db->beginTransaction();
            if (!$copy) {
                // Bestehende Arbeitskopien zum Original als ungültig markieren
                // Deren physische Dateien werden entfernt (anhand der 'temporary'-
                // Einträge in ihrem Arbeitsstand; Arbeitskopien besitzen keine
                // `data_fil`-Einträge mehr)
                $oldTmpIds = [];
                if ($tableName == 'record' && method_exists($this, 'deleteRecordFiles')) {
                    $sql = "SELECT `record_id` FROM `record` WHERE `tmp` = 1 AND `deleted` = 0 AND `origin_id` = ?";
                    $this->db->query($sql, 'i', [$id]);
                    $oldTmpIds = array_map('intval', $this->db->fetchColumn());
                    foreach ($oldTmpIds as $oldTmpId) {
                        $this->deleteRecordFiles($oldTmpId);
                    }
                }
                $sql = "UPDATE `$tableName` SET `deleted` = 1, `changed_by` = ?, `changed_at` = UTC_TIMESTAMP() WHERE `tmp` = 1 AND `deleted` = 0 AND `origin_id` = ?";
                $this->db->query($sql, 'ii', [$sessionAdminId, $id]);
                // Verknüpfungen der ungültigen Kopien entfernen. Die Zeilen bleiben
                // (save() erkennt daran den verlorenen Edit-Lock), ihre Kanten nicht:
                // _copyRelations() hat auch EINGEHENDE Kanten auf die Kopie gelegt -
                // beim eingebetteten Inhalt (link) z. B. Original-Quelle -> Kopie. Blieben
                // sie stehen, käme die tote Kopie beim nächsten Check-out der Quelle
                // wieder in deren Maske, und Speichern scheiterte an ihr.
                if ($oldTmpIds) {
                    $in = implode(',', $oldTmpIds);
                    $this->db->query("DELETE FROM `data_rel` WHERE `src_id` IN ($in) OR `dest_id` IN ($in)");
                    $this->db->query("DELETE FROM `data_rel_checkout` WHERE `tmp_id` IN ($in)");
                }
            }

            // area_id - auch im temporären Datensatz speichern, kann gut für lookup-Feld gebraucht werden
            $areaId = $this->hasAreaId ? (int)$data['area_id'] : null;

            $originId = $copy ? 0 : $id;
            // $id ist die Quelle auch beim Kopieren ($copy) - dort ist originId 0,
            // die Datumswerte sollen aber trotzdem vom Vorbild kommen.
            if (!$tmpId = $this->insertTmpRecord($originId, $areaId, $id)) {
                // Kopie nicht erzeugt
                $errorMsg = $this->cms->snippet('error_create_temporary');
                $this->cms->error($errorMsg);
                throw new \Exception('#' . $errorMsg);
            }
            // Arbeitsstand: der produktive Stand aller Sprachen aus `record_cache`
            // des Originals (fehlende Sprachzeilen werden dabei nachgebaut) in die
            // `record_cache`-Zeilen der Arbeitskopie - Zwischenspeicher für
            // Einzelschritt-Updates ($stayTemporary), Datenbasis der Eingabemaske
            if ($tableName === 'record' && method_exists($this, '_tmpDataFromRecordCache')) {
                $schemaVirtual = $this->virtualSchema((int)$areaId, false);
                $langCtx = $this->languageContext((int)$areaId);
                $this->_saveTmpData($tmpId, $this->_tmpDataFromRecordCache($id, $schemaVirtual, $langCtx));
            }

            // data aktualisieren
            $data[$idField] = $tmpId;
            $data['tmp'] = 1;
            $data['origin_id'] = $originId;

            // Relations kopieren
            // --------------------
            if ($tableName === 'record') {
                $this->_copyRelations($id, $tmpId, $copy);
                // Stand beim Check-out merken - der Check-in überträgt nur, was sich an
                // der Arbeitskopie seither geändert hat (_copyRelationsBack())
                if (!$copy) {
                    $this->db->query(
                        "INSERT INTO `data_rel_checkout` (`tmp_id`, `field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`)"
                        . " SELECT ?, `field_id`, `fieldname_id`, `src_id`, `dest_id`, `position` FROM `data_rel`"
                        . " WHERE `src_id` = ? OR `dest_id` = ?",
                        'iii', [$tmpId, $tmpId, $tmpId]
                    );
                }
                // Eine KOPIE gehört keinem Quelldatensatz: eingehende 'link'-Verknüpfungen
                // (fest verknüpfte Inhalte, je Zieldatensatz höchstens ein
                // Quelldatensatz) bleiben beim Vorbild (LinkTrait)
                if ($copy && method_exists($this, 'removeIncomingLinks')) {
                    $this->removeIncomingLinks($tmpId);
                }
            }

            // Dateien kopieren, wenn Kopie erstellt wird
            // --------------------
            if ($tableName === 'record' && $copy === true) {
                $this->_copyFiles($id, $tmpId);
            }

            $this->db->commit();
            return $tmpId;

        } catch (\Exception $e) {
            // Fehlermeldungen, die noch nicht in Cms::errors gesammelt wurden, hinzufügen
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            // Alles rückgängig machen
            $this->db->rollback();
            return null;
        }
    }

    /**
     * Hilfsfunktion: temporären Datensatz anlegen
     * (gemeinsamer INSERT von tmpInit() und checkOut())
     *
     * @param int      $originId  Id des produktiven Originals, 0 bei neuem Datensatz / Kopie
     * @param int|null $areaId    area_id oder null, wenn Tabelle keine area_id hat
     * @param int      $sourceId  Vorbild für die Datumsspalten (checkOut: das Original
     *                            bzw. die Kopiervorlage), 0 bei Neuanlage
     * @param int      $forceId   Id, die die Arbeitskopie bekommen soll (0 = auto_increment)
     * @return int                Id der Arbeitskopie (0 bei Fehler)
     */
    private function insertTmpRecord(int $originId, ?int $areaId, int $sourceId = 0, int $forceId = 0): int
    {
        $tableName = $this->tableName;
        $idField   = $tableName . '_id';
        $sessionAdminId = $this->adminIdForDb();

        $sql = "INSERT INTO `$tableName` SET `tmp`=1, `origin_id`=?, `changed_at`=UTC_TIMESTAMP(), `changed_by`=?";
        $sqlTypes = 'ii';
        $sqlValues = [$originId, $sessionAdminId];
        if ($forceId > 0) {
            $sql .= ", `$idField` = ?";
            $sqlTypes .= 'i';
            $sqlValues[] = $forceId;
        }

        if ($areaId !== null) {
            $sql .= ", `area_id` = ?";
            $sqlTypes .= 'i';
            $sqlValues[] = $areaId;
        }
        if ($tableName == 'record') {
            // instance_id - schon in der Arbeitskopie
            // --------------------
            // Die Spalte trägt einen Fremdschlüssel auf `instance` und hat keinen
            // brauchbaren Default: was das INSERT nicht setzt, füllt MySQL mit 0 -
            // eine Instanz mit der Id 0 gibt es aber nicht, das INSERT liefe in den
            // Fremdschlüssel. Maßgeblich ist die Instanz des Inhaltsbereichs; beim
            // Speichern holt Record::hookOnSave() sie ohnehin von dort (Dataset::areaInstanceId()).
            $sql .= ", `instance_id` = ?";
            $sqlTypes .= 'i';
            $sqlValues[] = $this->areaInstanceId((int)$areaId);
        }
        $this->db->query($sql, $sqlTypes, $sqlValues);
        $tmpId = $forceId > 0 ? $forceId : (int)$this->db->insert_id;
        if ($tmpId < 1) return 0;

        // Datumsspalten aus dem Ursprungsdatensatz uebernehmen
        // --------------------
        // Die datetime-Spalten sind NOT NULL und haben keinen DB-Default. Was das
        // INSERT oben nicht setzt, fuellt MySQL deshalb mit dem Null-Datum
        // 0000-00-00 00:00:00 (der sql_mode dieser Installation laesst das zu) - und
        // beim Check-in wanderte dieser Wert in den produktiven Datensatz. Genau so
        // sind die Null-Daten im Bestand entstanden.
        //
        // Kopiert wird per SQL aus der Quellzeile, nicht ueber $data: dort stehen
        // timestampl-Werte bereits in der Zeitzone des Users (Cms::utcToUserTZ()),
        // beim Zurueckschreiben waeren sie um den Zonenversatz verschoben.
        //
        // changed_at bleibt aussen vor - es steht oben schon auf UTC_TIMESTAMP().
        $dateCols = [];
        foreach ($this->schema as $colName => $colDef) {
            if ($colName === 'changed_at') continue;
            if (!in_array($colDef['type'] ?? '', $this->cms->dateTypes, true)) continue;
            $dateCols[] = $colName;
        }

        if (!empty($dateCols)) {
            $set = [];
            if ($sourceId > 0) {
                foreach ($dateCols as $colName) {
                    $set[] = "`t`.`$colName` = `s`.`$colName`";
                }
                $sql = "UPDATE `$tableName` `t` INNER JOIN `$tableName` `s`"
                     . " ON `s`.`{$idField}` = ?"
                     . " SET " . implode(', ', $set)
                     . " WHERE `t`.`{$idField}` = ?";
                $this->db->query($sql, 'ii', [$sourceId, $tmpId]);
            }
            else {
                // Neuanlage (tmpInit): es gibt kein Vorbild. Die Untergrenze steht
                // fuer "kein Datum gesetzt"; die eigentlichen Vorgaben aus dem Schema
                // ('now' fuer online_at) setzt init() in $data und save() schreibt sie.
                foreach ($dateCols as $colName) {
                    $set[] = "`$colName` = '" . self::DATE_MIN . "'";
                }
                $sql = "UPDATE `$tableName` SET " . implode(', ', $set)
                     . " WHERE `{$idField}` = ?";
                $this->db->query($sql, 'i', [$tmpId]);
            }
        }

        return $tmpId;
    }

    /**
     * CHECKIN
     * Setzt eine temporäre Arbeitskopie produktiv.
     * Wird von Dataset::save() aufgerufen, wenn temporärer Datensatz gespeichert wird
     *
     * Zieht Dateien der Arbeitskopie auf den produktiven Datensatz um und erzeugt
     * dabei die `data_fil`-Einträge aus den Datei-Metadaten des Arbeitsstands bzw. löscht per
     * Löschmarkierung entfernte Dateien (promoteTmpFiles)
     * Kopiert die Relations der Arbeitskopie in den produktiven Datensatz
     * Löscht den temporären Datensatz
     *
     * @param int $tmpId            Id des temporären Zustands
     * @param int|null $prodId      Id des produktiven Zustands, falls vorhanden
     * @param array $schemaVirtual  Schema mit Eingabefeldern (relation-Felder benötigt)
     */
    protected function checkIn(int $tmpId, ?int $prodId, array $schemaVirtual): void
    {
        if (!$prodId) {
            // temporäre Kopie geht unter ihrer eigenen Id produktiv (neuer
            // Datensatz oder Kopie): 'temporary'-Dateien aus ihrem Arbeitsstand jetzt
            // in `data_fil` eintragen (promoteTmpFiles mit tmpId als Ziel-Id;
            // ein physischer Umzug findet dabei nicht statt). Ihre `record_cache`-
            // Zeilen hat saveVirtualFields() bereits zum produktiven Stand gemacht.
            if ($this->tableName == 'record' && method_exists($this, 'promoteTmpFiles')) {
                $this->promoteTmpFiles($tmpId, $tmpId);
            }
            // Verknüpfungen hängen schon an der endgültigen Id - nur die Regel
            // "höchstens ein Ziel" auf der Gegenseite durchsetzen
            if ($this->tableName == 'record') {
                $this->_enforceSingleRelations($tmpId, $schemaVirtual);
            }
            return;
        }

        $tableName     = $this->tableName;
        $idField       = $tableName . '_id';

        if ($this->tableName == 'record') {
            // Dateien der Arbeitskopie jetzt dem Original zuweisen und deren
            // `data_fil`-Einträge aus den Datei-Metadaten erzeugen bzw. per
            // Löschmarkierung entfernte Dateien löschen
            // (VOR dem Löschen der Kopie: promoteTmpFiles() liest deren Arbeitsstand)
            if (method_exists($this, 'promoteTmpFiles')) {
                $this->promoteTmpFiles($tmpId, $prodId);
            }
            // Verknüpfungen der temporären Kopie jetzt dem Orignal zuweisen
            $this->_copyRelationsBack($tmpId, $prodId, $schemaVirtual);
        }

        // temporäre Kopie löschen (löscht auch deren Relations)
        $sql = "DELETE FROM `{$tableName}` WHERE `$idField` = ?";
        $this->db->query($sql, 'i', [$tmpId]);
    }

    /**
     * Bearbeitenprozess abbrechen - Löscht tempräre Arbeitskopie
     * und entfernt Edit-Lock
     * 
     * @param int $tmpId    Id eines temporären Datensatzes
     */
    public function cancel(int $tmpId): void
    {
        if ($tmpId < 1) return;

        if (!$prodData = $this->getProdData($tmpId)) {
            return;
        }

        // Arbeitskopie ist temporär und gehört dem Session-Admin
        $tableName     = $this->tableName;
        $idField       = $tableName . '_id';
        // Physische Dateien der Arbeitskopie entfernen
        // (anhand der 'temporary'-Einträge in ihrem Arbeitsstand; Arbeitskopien
        //  besitzen keine `data_fil`-Einträge mehr)
        if ($tableName == 'record' && method_exists($this, 'deleteRecordFiles')) {
            $this->deleteRecordFiles($tmpId);
        }
        // Arbeitskopie löschen
        $this->db->query("DELETE FROM `$tableName` WHERE `$idField` = ?", 'i', [$tmpId]);
    }

    /**
     * Hilfsfunktion: Verknüpfungen aus 'relation' und 'pointer'-Feldern von Datensatz $id1 nach $id2 kopieren
     *
     * Check-out ($asCopy = false): alle Kanten 1:1, auch die eingehenden
     * (andere Datensätze -> $id1) mit ihrer Position - die Arbeitskopie steht für
     * das Original. (Der Check-in kopiert nicht zurück, sondern gleicht ab:
     * _copyRelationsBack().)
     *
     * Kopie eines Datensatzes ($asCopy = true): die Kopie ist ein NEUER Datensatz.
     *   - Eingehende Kanten (X -> $id1 im Feld von X) hängen hinten an X' Liste
     *     (MAX(position) + 1) - sonst teilten sich Vorbild und Kopie eine Position,
     *     die Reihenfolge wäre zufällig.
     *   - Kanten, deren andere Seite nur EIN Ziel erlaubt (Feld von X bzw. Gegenfeld
     *     vom Typ relation1/pointer1 oder max = 1), werden nicht übernommen: X hat
     *     sein eines Ziel schon (das Vorbild). Sonst setzte der Check-in der Kopie
     *     die Regel "höchstens ein Ziel" durch (_enforceSingleRelations()) und nähme
     *     sie dem Vorbild weg.
     */
    protected function _copyRelations(int $id1, int $id2, bool $asCopy = false): void
    {
        // Verknüpfungen des Ziel-Datensatzes löschen
        $sql = "DELETE FROM `data_rel` WHERE `src_id` = ? OR `dest_id` = ?";
        $this->db->query($sql, 'ii', [$id2, $id2]);

        if (!$asCopy) {
            // Relations kopieren, bei denen $id1 die Quelle (src_id) ist
            // Kopiert (src_id = $id1, dest_id = X) -> zu (src_id = $id2, dest_id = X)
            $sql = "INSERT INTO `data_rel` (`field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`)"
                ." SELECT `field_id`, `fieldname_id`, ?, `dest_id`, `position`"
                ." FROM `data_rel`"
                ." WHERE `src_id` = ?";
            $this->db->query($sql, 'ii', [$id2, $id1]);

            // Relations kopieren, bei denen $id1 das Ziel (dest_id) ist
            // Kopiert (src_id = X, dest_id = $id1) -> zu (src_id = X, dest_id = $id2)
            $sql = "INSERT INTO `data_rel` (`field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`)"
                ." SELECT `field_id`, `fieldname_id`, `src_id`, ?, `position`"
                ." FROM `data_rel`"
                ." WHERE `dest_id` = ?";
            $this->db->query($sql, 'ii', [$id2, $id1]);
            return;
        }

        // Feld nimmt nur ein Ziel auf (Alias als Platzhalter)
        $single = "(%s.`type` IN ('" . implode("','", $this->cms->singleRelationTypes) . "') OR %s.`max` = '1')";

        // Eigene Kanten der Kopie ($id2 -> X), Position wie beim Vorbild - außer
        // das Gegenfeld von X erlaubt nur ein Ziel
        $sql = "INSERT INTO `data_rel` (`field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`)"
            ." SELECT `r`.`field_id`, `r`.`fieldname_id`, ?, `r`.`dest_id`, `r`.`position`"
            ." FROM `data_rel` `r`"
            ." INNER JOIN `field` `f` ON `f`.`field_id` = `r`.`field_id`"
            ." LEFT JOIN `field` `g` ON `g`.`field_id` = `f`.`rel_field_id`"
            ." WHERE `r`.`src_id` = ?"
            ." AND NOT (`g`.`field_id` IS NOT NULL AND " . sprintf($single, '`g`', '`g`') . ")";
        $this->db->query($sql, 'ii', [$id2, $id1]);

        // Eingehende Kanten (X -> $id2) hinten an die Liste von X - außer das Feld
        // von X erlaubt nur ein Ziel. Je (X, Feld) gibt es höchstens eine solche
        // Kante (dest_id ist Teil des Primärschlüssels), MAX + 1 bleibt eindeutig.
        $sql = "INSERT INTO `data_rel` (`field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`)"
            ." SELECT `r`.`field_id`, `r`.`fieldname_id`, `r`.`src_id`, ?,"
            ."   (SELECT COALESCE(MAX(`p`.`position`), -1) + 1 FROM `data_rel` `p`"
            ."    WHERE `p`.`src_id` = `r`.`src_id` AND `p`.`fieldname_id` = `r`.`fieldname_id`)"
            ." FROM `data_rel` `r`"
            ." INNER JOIN `field` `f` ON `f`.`field_id` = `r`.`field_id`"
            ." WHERE `r`.`dest_id` = ?"
            ." AND NOT " . sprintf($single, '`f`', '`f`');
        $this->db->query($sql, 'ii', [$id2, $id1]);
    }

    /**
     * Verknüpfungen der Arbeitskopie beim Check-in auf das Original übertragen.
     *
     * Übertragen wird nur, was sich AN DER ARBEITSKOPIE seit dem Check-out geändert
     * hat - verglichen mit dem Stand, den checkOut() in `data_rel_checkout` abgelegt
     * hat. Was andere Datensätze inzwischen am Original geändert haben, bleibt:
     * etwa ein Verweis B -> A, der gesetzt wurde, während A ausgecheckt war, oder
     * die Spiegelkante A -> B eines bidirektionalen Feldes, das B bearbeitet hat.
     * (Früher ersetzte der Check-in alle Kanten des Originals durch die der
     * Arbeitskopie - solche Änderungen gingen verloren.)
     *
     * Drei Mengenoperationen, die Arbeitskopie jeweils auf das Original abgebildet:
     *
     *   1. entfernt: Kanten des Check-out-Stands, die der Arbeitskopie fehlen
     *      -> am Original löschen
     *   2. neu oder geändert (Position, Feld): Kanten der Arbeitskopie, die im
     *      Check-out-Stand fehlen oder anders aussehen -> am Original anlegen bzw.
     *      aktualisieren. Dazu IMMER die Kanten zu anderen Arbeitskopien: über sie
     *      wandern fest verknüpfte Inhalte zwischen den Arbeitskopien
     *      (LinkTrait::_repointLink(), checkInDependents())
     *   3. die Reihenfolge der eigenen Verknüpfungen des Originals lückenlos neu
     *      zählen - Kanten von der Arbeitskopie und von anderen können sich eine
     *      Position teilen
     *
     * Danach die Regel "höchstens ein Ziel" auf der Gegenseite - jetzt für das
     * Original, dessen Kanten feststehen.
     */
    protected function _copyRelationsBack(int $tmpId, int $prodId, array $schemaVirtual): void
    {
        // Kante der Arbeitskopie -> Kante des Originals (nur Ganzzahlen, literal)
        $map = fn(string $column) => "CASE {$column} WHEN {$tmpId} THEN {$prodId} ELSE {$column} END";

        // 1. entfernt
        $this->db->query(
            "DELETE `d` FROM `data_rel_checkout` `b`"
            . " INNER JOIN `data_rel` `d` ON `d`.`src_id` = " . $map('`b`.`src_id`')
            . "   AND `d`.`fieldname_id` = `b`.`fieldname_id` AND `d`.`dest_id` = " . $map('`b`.`dest_id`')
            . " LEFT JOIN `data_rel` `r` ON `r`.`src_id` = `b`.`src_id`"
            . "   AND `r`.`fieldname_id` = `b`.`fieldname_id` AND `r`.`dest_id` = `b`.`dest_id`"
            . " WHERE `b`.`tmp_id` = ? AND `r`.`src_id` IS NULL",
            'i', [$tmpId]
        );

        // 2. neu oder geändert, dazu die Kanten zu anderen Arbeitskopien
        $this->db->query(
            "INSERT INTO `data_rel` (`field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`)"
            . " SELECT `r`.`field_id`, `r`.`fieldname_id`, " . $map('`r`.`src_id`') . ", " . $map('`r`.`dest_id`') . ", `r`.`position`"
            . " FROM `data_rel` `r`"
            . " LEFT JOIN `data_rel_checkout` `b` ON `b`.`tmp_id` = ? AND `b`.`src_id` = `r`.`src_id`"
            . "   AND `b`.`fieldname_id` = `r`.`fieldname_id` AND `b`.`dest_id` = `r`.`dest_id`"
            . " LEFT JOIN `record` `o` ON `o`.`record_id` = IF(`r`.`src_id` = ?, `r`.`dest_id`, `r`.`src_id`)"
            . "   AND `o`.`record_id` <> ? AND `o`.`tmp` = 1"
            . " WHERE (`r`.`src_id` = ? OR `r`.`dest_id` = ?)"
            . "   AND (`b`.`tmp_id` IS NULL OR `b`.`position` <> `r`.`position` OR `b`.`field_id` <> `r`.`field_id`"
            . "        OR `o`.`record_id` IS NOT NULL)"
            . " ON DUPLICATE KEY UPDATE `field_id` = VALUES(`field_id`), `position` = VALUES(`position`)",
            'iiiii', [$tmpId, $tmpId, $tmpId, $tmpId, $tmpId]
        );

        // 3. Reihenfolge der eigenen Verknüpfungen je Feld
        $this->db->query(
            "UPDATE `data_rel` `d` INNER JOIN ("
            . "   SELECT `src_id`, `fieldname_id`, `dest_id`,"
            . "          ROW_NUMBER() OVER (PARTITION BY `fieldname_id` ORDER BY `position` ASC, `dest_id` ASC) - 1 AS `pos`"
            . "   FROM `data_rel` WHERE `src_id` = ?"
            . " ) `x` ON `x`.`src_id` = `d`.`src_id` AND `x`.`fieldname_id` = `d`.`fieldname_id` AND `x`.`dest_id` = `d`.`dest_id`"
            . " SET `d`.`position` = `x`.`pos` WHERE `d`.`position` <> `x`.`pos`",
            'i', [$prodId]
        );

        $this->_enforceSingleRelations($prodId, $schemaVirtual);
    }

    /**
     * Regel "höchstens ein Ziel" (relation1 / pointer1 bzw. max = 1) für die
     * Verknüpfungen des Datensatzes $srcId durchsetzen - beim Check-in, wenn die
     * Verknüpfungen der Arbeitskopie endgültig werden (Arbeitskopie mit Original
     * oder neuer Datensatz unter seiner eigenen Id).
     *
     * Die eigene Seite hält createRelations() sauber (nie mehr als eine Zeile je
     * …1-Feld). Hier geht es um die GEGENSEITE eines 'relation'-Feldes: erlaubt das
     * Gegenfeld nur ein Ziel, darf jeder Zieldatensatz nur von diesem einen
     * Datensatz verknüpft sein - seine bisherigen Verknüpfungen in diesem Feldpaar
     * (von anderen Quelldatensätzen) werden gelöst, beide Richtungen.
     *
     * Beispiel: Artikel.topic (relation1) <-> Rubrik.rel_artikel (relation). Wird
     * Artikel A an Rubrik 3 gehängt, verliert er Rubrik 1. Erst hier und nicht in
     * createRelations(): solange die Verknüpfung an der Arbeitskopie hängt, muss sie
     * sich verwerfen lassen (cancel), ohne dass Artikel A etwas verliert.
     *
     * Unabhängig von visibility_input - die Regel gilt auch für Verknüpfungen, die
     * nicht über die Maske, sondern per Import oder Stapelverarbeitung entstehen.
     *
     * @param int   $srcId          Datensatz, dessen Verknüpfungen gelten sollen
     * @param array $schemaVirtual  Schema mit Eingabefeldern
     */
    protected function _enforceSingleRelations(int $srcId, array $schemaVirtual): void
    {
        foreach ($schemaVirtual as $name => $colDef) {
            $type = $colDef['type'] ?? 'text';
            if (!str_starts_with($type, 'relation')) continue;
            // Hole Infos zum Gegenfeld
            $relFieldId = (int)$colDef['rel_field_id'];
            if (!$relField = $this->fieldData($relFieldId)) {
                // Fall sollte nie eintreten
                $this->createFieldError('record.error_dest_relationfield', $name, $colDef['label'] ?? $name);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $relIsSingle = in_array((string)($relField['type'] ?? ''), $this->cms->singleRelationTypes, true)
                || (int)($relField['max'] ?? 0) === 1;
            if (!$relIsSingle) continue;
            $fieldId = (int)$colDef['field_id'];

            // Kein anderer Datensatz darf (im eigenen Feld) auf einen der Zieldatensätze verweisen
            // außer der Quelldatensatz
            $sql = "DELETE `d2` FROM `data_rel` `d2`"
                   ." INNER JOIN `data_rel` `d1`"
                   ."     ON `d1`.`field_id` = ?"
                   ."     AND `d1`.`src_id` = ?"
                   ."     AND `d1`.`dest_id` = `d2`.`dest_id`"
                   ." WHERE `d2`.`field_id` = ?"
                   ." AND `d2`.`src_id` != ?";
            $this->db->query($sql, 'iiii', [$fieldId, $srcId, $fieldId, $srcId]);

            // Bidirektionalität beachten
            // Die Zieldatensätze dürfen (im Gegenfeld) auf keinen anderen Datensatz verweisen
            // außer auf den Quelldatensatz
            $sql = "DELETE `d2` FROM `data_rel` `d2`"
                    ." INNER JOIN `data_rel` `d1`"
                    ."     ON `d1`.`field_id` = ?"
                    ."     AND `d1`.`src_id` = ?"
                    ."     AND `d1`.`dest_id` = `d2`.`src_id`"
                    ." WHERE `d2`.`field_id` = ?"
                    ." AND `d2`.`dest_id` != ?";
            $this->db->query($sql, 'iiii', [$fieldId, $srcId, $relFieldId, $srcId]);
        }
    }

    /**
     * Dateien des produktiven Datensatzes $id1 physisch für die Kopie $id2
     * duplizieren und im Arbeitsstand der Kopie mit 'temporary' = true markieren.
     * Es werden KEINE `data_fil`-Einträge für die Kopie erzeugt: diese entstehen
     * erst beim Produktivgehen in promoteTmpFiles() aus den Datei-Metadaten
     * (die Kopie geht mit origin_id = 0 unter ihrer eigenen Id produktiv,
     *  siehe checkIn()).
     */
    private function _copyFiles(int $id1, int $id2): void
    {
        $mediaDir = $this->config->get('paths.media_dir');

        // Metadaten der produktiven Dateien lesen (inkl. Feldname für den
        // Feldnamen) - nur die Zeilen der physischen Dateien: einsprachige
        // Felder führen je Sprache dieselbe Zeile (file_language_id NULL), bei
        // mehrsprachigen zählt je Sprache die eigene Datei (file_language_id =
        // language_id); Fallback-Zeilen zeigen auf eine davon.
        $sql = "SELECT DISTINCT `df`.`field_id`, `df`.`file_language_id`, `df`.`extension`, `df`.`mime_type`,"
            ." `df`.`filename`, `df`.`size`, `df`.`width`, `df`.`height`, `df`.`data`, `fn`.`name`"
            ." FROM `data_fil` `df`"
            ." LEFT JOIN `fieldname` `fn` ON `df`.`fieldname_id` = `fn`.`fieldname_id`"
            ." WHERE `df`.`record_id` = ?"
            ." AND (`df`.`file_language_id` IS NULL OR `df`.`file_language_id` = `df`.`language_id`)";
        $this->db->query($sql, 'i', [$id1]);
        if ($this->db->num_rows < 1) return;
        $rows = [];
        while ($f = $this->db->fetch()) {
            $rows[] = $f;
        }

        // Arbeitsstand der Kopie fortschreiben
        // (wurde beim checkOut bereits vom Original übernommen)
        $cache = $this->_loadTmpData($id2);
        foreach ($rows as $f) {
            $name = (string)($f['name'] ?? '');
            if ($name === '') continue;
            $fieldId = (int)$f['field_id'];
            $extension = (string)$f['extension'];
            $fileLang = $this->cms->fileLanguageId($f);

            // 1. Datei kopieren
            // (leere Extension = Thumbnail-Feld ohne greifenden Converter, keine Datei)
            if ($extension !== '') {
                $src  = "$mediaDir/" . $this->cms->mediaPath($fieldId, $id1, $fileLang, $extension);
                $dest = "$mediaDir/" . $this->cms->mediaPath($fieldId, $id2, $fileLang, $extension);
                if (!@copy($src, $dest)) {
                    // Abbruch statt stillem Weiterlaufen: sonst entstünde beim
                    // Produktivgehen ein `data_fil`-Eintrag ohne physische Datei
                    $this->system->log('file-errors', "$src > $dest : copy failed.");
                    throw new \Exception("Error: _copyFiles(): could not copy \"$src\".");
                }
            }

            // 2. Datei-Eintrag mit 'temporary' markieren
            // (promoteTmpFiles() erzeugt daraus beim Produktivgehen den
            //  `data_fil`-Eintrag; deleteRecordFiles() findet darüber die
            //  physische Datei, falls die Kopie abgebrochen wird)
            $this->setFileEntry($cache, $name, $fileLang, [
                'extension' => $extension,
                'mime_type' => (string)$f['mime_type'],
                'width'     => (int)$f['width'],
                'height'    => (int)$f['height'],
                'size'      => (int)$f['size'],
                'filename'  => (string)$f['filename'],
                'field_id'  => $fieldId,
                'data'      => (string)($f['data'] ?? '{}'),
                'file_language_id' => $fileLang,
                'temporary' => true,
            ]);
        }
        $this->_saveTmpData($id2, $cache);
    }

    /**
     * Prüft
     * - ist Datensatz temporär
     * - gehört temporärer Datensatz dem Session-Admin
     * wenn nicht -> null
     * ansonsten  -> id und area_id des produktiven Datensatzes
     * 
     * @param int $tmpId    Id eines temporären Datensatzes
     * @return array|null   [Id, AreaId] des produktiven Datensatzes oder null
     */
    public function getProdData(int $tmpId): ?array
    {
        $tableName     = $this->tableName;
        $idField       = $tableName . '_id';

        $cols = ['`tmp`','`origin_id`','`changed_by`'];
        if ($this->hasAreaId) $cols[] = '`area_id`';
        else $cols[] = '0';
        $sqlCols = implode(',', $cols);
        $sql = "SELECT $sqlCols FROM `$tableName` WHERE `$idField` = ?";
        $this->db->query($sql, 'i', [$tmpId]);
        if ($this->db->num_rows < 1) {
            // nicht gefunden
            return null; 
        }

        [$tmp, $prodId, $changedBy, $areaId] = $this->db->fetchNum();
        if (empty($tmp)) {
            // nicht temporär
            return null;
        }

        $sessionAdminId = (int)$this->session->get('admin_id');
        if ((int)$changedBy !== $sessionAdminId) {
            // gehört nicht dem Session-Admin
            return null;
        }

        return [(int)$prodId, (int)$areaId];
    }
}
