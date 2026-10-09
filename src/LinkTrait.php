<?php
/**
 * Trait für die Tabelle `record`: fest verknüpfte Inhalte - Felder vom Typ 'link'.
 *
 * Ein 'link'-Feld ist ein gerichteter Verweis wie 'pointer' (Kante in `data_rel`,
 * Zielbereiche in `rel_field_area`), aber mit Besitz: die Zieldatensätze GEHÖREN dem
 * Quelldatensatz. Zwei Regeln folgen daraus:
 *
 *   1. Je Zieldatensatz höchstens ein Quelldatensatz (linkOwner(); geprüft in
 *      VirtualFieldsTrait::createRelations()).
 *   2. Der Zieldatensatz teilt das Schicksal seines Quelldatensatzes: Löschen,
 *      Wiederherstellen und endgültiges Löschen (Record-Hooks -> cascade*()),
 *      Kopieren (die Inhalte werden mitkopiert) und Verschieben (die Inhalte
 *      nicht abgebildeter link-Felder fallen weg).
 *
 * Die Zielbereiche sind abhängige Bereiche (`area.dependent` = 1): sie erscheinen
 * nicht in der Bereichsliste des Moduls Record, ihre Datensätze werden über den
 * Quelldatensatz bearbeitet.
 *
 * ZWEI DARSTELLUNGEN IN DER EINGABEMASKE (field.display_type)
 * --------------------
 *     lookup   das Suchfenster der übrigen Verknüpfungsfelder. Die Zieldatensätze
 *              bleiben während der Bearbeitung produktiv, die Verknüpfungen hängen
 *              an der Arbeitskopie des Quelldatensatzes.
 *     embed    die Eingabemasken der Zieldatensätze stehen in der Maske des
 *              Quelldatensatzes. Dafür werden die Zieldatensätze beim Öffnen der Maske
 *              MIT ausgecheckt (checkOutDependents()): Uploads und Lookup-Felder in
 *              den eingebetteten Masken schreiben sofort an eine record_id und
 *              brauchen dafür eine Arbeitskopie des Session-Admins - und ein
 *              Abbrechen muss auch die eingebetteten Eingaben verwerfen können
 *              (cancelDependents()). Die Kanten der Arbeitskopie zeigen dann auf die
 *              Arbeitskopien der Zieldatensätze; ein neu eingefügter Zieldatensatz
 *              ist eine Arbeitskopie ohne Original (tmpInit()).
 *
 * DER CHECK-IN (checkInDependents())
 * --------------------
 * Läuft VOR Dataset::save() des Quelldatensatzes, in derselben Transaktion
 * (RecordBackendEdit::_editSave(), MoveTrait::moveSave()). Je Kante der Arbeitskopie:
 *
 *     Ziel ist eine Arbeitskopie des Admins   -> Check-in (Record::save()), rekursiv
 *                                               für dessen eigene link-Felder; die
 *                                               Kante wandert dabei auf das Original
 *                                               (TemporaryTrait::_copyRelations())
 *     Ziel gehört einem anderen produktiven   -> der Quelldatensatz ist eine Kopie
 *     Datensatz (nicht dem eigenen Original)     (TemporaryTrait::checkOut() mit $copy):
 *                                               das Ziel wird kopiert, die Kante zeigt
 *                                               auf die Kopie
 *     Ziel ist produktiv und frei             -> bleibt (Lookup: aus dem Suchfenster
 *                                               verknüpft bzw. neu angelegt)
 *
 * Danach die Verwaisten: Inhalte, die das Original bisher hielt und die an keiner
 * Kante der Arbeitskopie mehr hängen (Lookup "Verknüpfung aufheben", eingebettet
 * "Löschen", Verschieben ohne Abbildung), werden gelöscht - soft, wie jeder
 * Datensatz im Backend. Bis zum Check-in ist alles umkehrbar: die Arbeitskopie
 * verwerfen (cancel) lässt das Original samt Inhalten, wie es war.
 *
 * IMPORT (CSV, DOCX)
 * --------------------
 * RecordImport::_importLinks() arbeitet wie die eingebettete Maske: Inhalte an der
 * Arbeitskopie auschecken (linkRepoint()) bzw. anlegen (embedInsert()), Werte mit
 * stayTemporary prüfen, Reihenfolge setzen (linkReorder()), dann
 * checkInDependents() mit dem Quelldatensatz in einer Transaktion.
 *
 * NICHT ABGEDECKT
 * --------------------
 * Stapelverarbeitung, XML-Import und CMS:USER_INPUT speichern ohne
 * checkInDependents(): sie kopieren die Kanten der Arbeitskopie unverändert
 * zurück - Inhalte werden dort weder kopiert noch verwaist gelöscht.
 *
 * @see Camindo\Core\Modules\Record\RecordBackendEdit  Eingabemaske (Lookup, eingebettet)
 * @see Camindo\Core\Modules\Area\Field                 Felddefinition 'link'
 * @see Camindo\Core\TemporaryTrait                     checkOut / checkIn / cancel
 */
namespace Camindo\Core;

trait LinkTrait
{
    /**
     * Die link-Felder eines Bereichs.
     *
     * @param array $schemaVirtual  virtualSchema($areaId, false)
     * @return array  name => Felddefinition
     */
    public function linkFields(array $schemaVirtual): array
    {
        return array_filter(
            $schemaVirtual,
            fn(array $colDef) => in_array((string)($colDef['type'] ?? ''), $this->cms->dependentTypes, true)
        );
    }

    /**
     * link-Felder, deren Zielbereich die Benutzergruppe lesen darf - nur diese
     * werden mit aus- und eingecheckt; die übrigen behalten ihre Kanten auf die
     * produktiven Inhalte (Dataset::relationTargetReadable()).
     */
    public function accessibleLinkFields(array $schemaVirtual): array
    {
        return array_filter($this->linkFields($schemaVirtual), fn(array $colDef) => $this->relationTargetReadable($colDef));
    }

    /**
     * Zielbereiche eines link-Feldes (rel_field_area).
     *
     * @return int[]
     */
    public function linkTargetAreaIds(array $colDef): array
    {
        $fieldId = (int)($colDef['field_id'] ?? 0);
        if ($fieldId < 1) return [];
        $this->db->pushResult();
        $ids = array_values(array_filter(array_map('intval', $this->getRelAreaIds($fieldId)), fn($id) => $id > 0));
        $this->db->popResult();
        sort($ids);
        return $ids;
    }

    /**
     * Felder der Zielbereiche eines link-Feldes, wie sie Export und Import als
     * Felder des Quelldatensatzes führen ("{link}.{feld}", RecordExport,
     * EINGEBETTETE INHALTE) - bei mehreren Zielbereichen die Vereinigung nach Name.
     * Alle virtuellen Felder außer Verknüpfungen, Volltext und den Baum-/
     * Sortierfeldern; Datei-Felder samt Thumbnails.
     *
     * @return array  name => Felddefinition (die des ersten Zielbereichs mit dem Namen)
     */
    public function linkTargetFields(array $colDef): array
    {
        $fields = [];
        foreach ($this->linkTargetAreaIds($colDef) as $areaId) {
            foreach ($this->virtualSchema($areaId, false) as $name => $targetDef) {
                $targetType = (string)($targetDef['type'] ?? '');
                if ($targetType === 'fulltext' || in_array($targetType, $this->cms->relationTypes, true)) continue;
                if (in_array($name, ['parent_id', 'position'], true)) continue;
                $fields[$name] ??= $targetDef;
            }
        }
        return $fields;
    }

    /**
     * Eine Kante eines link-Feldes auf ein anderes Ziel umhängen (Import: der
     * Inhalt wurde ausgecheckt, die Kante der Arbeitskopie des Quelldatensatzes
     * zeigt danach auf seine Arbeitskopie - wie bei checkOutDependents()).
     */
    public function linkRepoint(int $fieldId, int $srcId, int $oldDestId, int $newDestId): void
    {
        $this->_repointLink($fieldId, $srcId, $oldDestId, $newDestId);
    }

    /**
     * Reihenfolge der Inhalte eines link-Feldes setzen.
     *
     * @param int[] $ids  dest_ids in der gewünschten Reihenfolge
     */
    public function linkReorder(int $srcId, int $fieldId, array $ids): void
    {
        $this->_writeLinkPositions($srcId, $fieldId, $ids);
    }

    /**
     * Zieldatensätze eines link-Feldes in ihrer Reihenfolge (data_rel.position) -
     * auch Arbeitskopien, deshalb nicht über Cms::select().
     *
     * @return int[]  dest_ids
     */
    public function linkDestIds(int $srcId, int $fieldId): array
    {
        if ($srcId < 1 || $fieldId < 1) return [];
        $this->db->pushResult();
        $this->db->query(
            "SELECT `dest_id`, `position` FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ?"
            . " ORDER BY `position` ASC, `dest_id` ASC",
            'ii', [$fieldId, $srcId]
        );
        $ids = array_map(fn($row) => (int)$row['dest_id'], $this->db->fetchAll());
        $this->db->popResult();
        return $ids;
    }

    /**
     * Alle fest verknüpften Inhalte eines Datensatzes (über jedes seiner link-Felder).
     *
     * Solange ein Inhalt ausgecheckt ist, hält der produktive Quelldatensatz auch
     * eine Kante auf dessen Arbeitskopie (TemporaryTrait::_copyRelations() kopiert
     * die eingehenden Kanten mit) - $productiveOnly lässt Arbeitskopien weg.
     *
     * @return int[]  dest_ids
     */
    public function dependentIds(int $srcId, bool $productiveOnly = false): array
    {
        if ($srcId < 1) return [];
        $this->db->pushResult();
        $this->db->query(
            "SELECT `d`.`dest_id`, `d`.`position` FROM `data_rel` `d`"
            . " INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id`"
            . ($productiveOnly ? " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`dest_id` AND `r`.`tmp` = 0" : '')
            . " WHERE `d`.`src_id` = ? AND `f`.`type` IN (" . $this->_dependentTypeList() . ")"
            . " ORDER BY `d`.`field_id` ASC, `d`.`position` ASC",
            'i', [$srcId]
        );
        $ids = array_map(fn($row) => (int)$row['dest_id'], $this->db->fetchAll());
        $this->db->popResult();
        return array_values(array_unique($ids));
    }

    /**
     * Wem gehört ein Datensatz? Der PRODUKTIVE Quelldatensatz, der ihn über ein
     * link-Feld hält - außer $exceptId und dessen Original bzw. Arbeitskopien:
     * die Arbeitskopie eines Quelldatensatzes darf verknüpfen, was ihr Original
     * schon hält (createRelations() nach einem Reiterwechsel, Check-in).
     *
     * @param int $destId    Zieldatensatz
     * @param int $exceptId  Quelldatensatz (produktiv oder Arbeitskopie), der nicht zählt
     * @return int|null      record_id des Besitzers oder null (frei)
     */
    public function linkOwner(int $destId, int $exceptId = 0): ?int
    {
        if ($destId < 1) return null;
        $this->db->pushResult();
        // $exceptId auf sein Original auflösen
        $originId = 0;
        if ($exceptId > 0) {
            $this->db->query("SELECT `tmp`, `origin_id` FROM `record` WHERE `record_id` = ?", 'i', [$exceptId]);
            $row = $this->db->fetch();
            $originId = !empty($row['tmp']) ? (int)($row['origin_id'] ?? 0) : $exceptId;
        }
        $this->db->query(
            "SELECT `r`.`record_id` FROM `data_rel` `d`"
            . " INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id`"
            . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`src_id`"
            . " WHERE `d`.`dest_id` = ? AND `f`.`type` IN (" . $this->_dependentTypeList() . ")"
            . " AND `r`.`tmp` = 0 AND `r`.`record_id` != ? AND `r`.`record_id` != ?"
            . " LIMIT 1",
            'iii', [$destId, $exceptId, $originId]
        );
        $owner = ($this->db->num_rows > 0) ? (int)$this->db->fetchNum()[0] : null;
        $this->db->popResult();
        return $owner;
    }

    /**
     * Ist die Arbeitskopie $tmpId ein EINGEBETTETER Inhalt - hängt sie an einem
     * link-Feld einer anderen Arbeitskopie (checkOutDependents() / embedInsert())?
     * Dann heißen ihre Eingabefelder embed[{tmpId}][feld] statt data[feld]
     * (RecordBackendUpload::fileServerSelect()).
     */
    public function isEmbedded(int $tmpId): bool
    {
        if ($tmpId < 1) return false;
        $this->db->pushResult();
        $this->db->query(
            "SELECT 1 FROM `data_rel` `d`"
            . " INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id`"
            . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`src_id`"
            . " WHERE `d`.`dest_id` = ? AND `f`.`type` IN (" . $this->_dependentTypeList() . ")"
            . " AND `r`.`tmp` = 1 LIMIT 1",
            'i', [$tmpId]
        );
        $embedded = $this->db->num_rows > 0;
        $this->db->popResult();
        return $embedded;
    }

    /**
     * Eingehende link-Verknüpfungen eines Datensatzes entfernen (eine Kopie gehört
     * niemandem - TemporaryTrait::checkOut() mit $copy).
     */
    public function removeIncomingLinks(int $recordId): void
    {
        if ($recordId < 1) return;
        $this->db->query(
            "DELETE `d` FROM `data_rel` `d`"
            . " INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id`"
            . " WHERE `d`.`dest_id` = ? AND `f`.`type` IN (" . $this->_dependentTypeList() . ")",
            'i', [$recordId]
        );
    }

    /**
     * Beim Öffnen der Eingabemaske: die Inhalte der EINGEBETTETEN link-Felder
     * (display_type 'embed') mit auschecken - siehe Klassenkommentar.
     *
     * Die Kanten der Arbeitskopie $tmpId (von checkOut() vom Vorbild kopiert, sie
     * zeigen auf die produktiven Inhalte) werden auf die neuen Arbeitskopien der
     * Inhalte umgehängt. Bei einer KOPIE des Quelldatensatzes ($copy) entstehen
     * Kopien der Inhalte (checkOut() mit $copy: ohne Original, mit kopierten
     * Dateien) - der Check-in macht daraus neue Datensätze. Ein fremd gesperrter
     * Inhalt (Bearbeitungssperre eines anderen Admins) bricht ab.
     *
     * Rekursiv für eingebettete Felder der Inhalte selbst (die Maske zeigt sie nicht
     * an - ihre Kanten und Arbeitskopien müssen trotzdem stimmen, damit der Check-in
     * und das Abbrechen sie finden).
     *
     * @param int   $tmpId          Arbeitskopie des Quelldatensatzes
     * @param bool  $copy           true: der Quelldatensatz wird kopiert
     * @param array $schemaVirtual  Schema des Bereichs (virtuelle Felder)
     * @return bool                 false bei Fehlern (Cms::errors)
     */
    public function checkOutDependents(int $tmpId, bool $copy, array $schemaVirtual): bool
    {
        foreach ($this->accessibleLinkFields($schemaVirtual) as $name => $colDef) {
            if ((string)($colDef['display_type'] ?? '') !== 'embed') continue;
            $fieldId = (int)$colDef['field_id'];
            foreach ($this->linkDestIds($tmpId, $fieldId) as $destId) {
                $this->db->pushResult();
                $this->db->query("SELECT `tmp` FROM `record` WHERE `record_id` = ?", 'i', [$destId]);
                $isTmp = !empty($this->db->fetch()['tmp']);
                $this->db->popResult();
                if ($isTmp) continue;   // schon ausgecheckt (Maske erneut geöffnet)

                if (!$destData = $this->load($destId)) return false;
                if (!empty($destData['deleted'])) {
                    // gelöschter Inhalt: Kante lösen, der Check-in räumt ihn nicht mehr ab
                    $this->db->query("DELETE FROM `data_rel` WHERE `src_id` = ? AND `dest_id` = ?", 'ii', [$tmpId, $destId]);
                    continue;
                }
                if (!$copy && ($lock = $this->getLock($destData))) {
                    [$changedBy] = $lock;
                    $this->db->pushResult();
                    $this->db->query("SELECT `name` FROM `admin` WHERE `admin_id` = ?", 'i', [(int)$changedBy]);
                    $adminName = (string)($this->db->fetchNum()[0] ?? '??');
                    $this->db->popResult();
                    $this->cms->error(strtr($this->cms->snippet('record.error_record_locked'), ['{admin_name}' => $adminName]));
                    return false;
                }
                if (!$destTmpId = $this->checkOut($destData, $copy)) return false;
                // Kante auf die Arbeitskopie des Inhalts umhängen
                $this->_repointLink($fieldId, $tmpId, $destId, $destTmpId);
                // rekursiv: eingebettete Felder des Inhalts
                $destSchema = $this->virtualSchema((int)$destData['area_id'], false);
                if (!$this->checkOutDependents($destTmpId, $copy, $destSchema)) return false;
            }
        }
        return true;
    }

    /**
     * Einen neuen Inhalt in ein eingebettetes link-Feld einfügen: Arbeitskopie ohne
     * Original im Zielbereich (tmpInit()) und Kante an der Arbeitskopie des
     * Quelldatensatzes, eingereiht vor bzw. nach $refId (0 = ans Ende).
     *
     * @param array  $colDef   link-Feld
     * @param int    $srcId    Arbeitskopie des Quelldatensatzes (Session-Admin)
     * @param int    $areaId   Zielbereich (einer der Zielbereiche des Feldes)
     * @param int    $refId    Bezugs-Inhalt oder 0
     * @param bool   $before   true: vor $refId, sonst danach
     * @return int|null        record_id der neuen Arbeitskopie, null bei Fehlern
     */
    public function embedInsert(array $colDef, int $srcId, int $areaId, int $refId, bool $before): ?int
    {
        $fieldId = (int)($colDef['field_id'] ?? 0);
        if ($fieldId < 1 || $srcId < 1) return null;
        if (!$this->getProdData($srcId)) {
            $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
            return null;
        }
        if (!in_array($areaId, array_map('intval', $this->getRelAreaIds($fieldId)), true)) {
            $this->cms->error($this->cms->snippet('record.error_dest_record_areas'));
            return null;
        }
        $this->db->beginTransaction();
        try {
            if (!$data = $this->tmpInit(['area_id' => $areaId])) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $newId = (int)$data['record_id'];
            // ans Ende anhängen, dann einreihen
            $this->createRelations($colDef, $srcId, $newId, replace:false);
            $ids = $this->linkDestIds($srcId, $fieldId);
            $ids = array_values(array_diff($ids, [$newId]));
            $pos = array_search($refId, $ids, true);
            if ($refId > 0 && $pos !== false) {
                array_splice($ids, $before ? $pos : $pos + 1, 0, [$newId]);
            }
            else {
                $ids[] = $newId;
            }
            $this->_writeLinkPositions($srcId, $fieldId, $ids);
            $this->db->commit();
            return $newId;
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            $this->db->rollback();
            return null;
        }
    }

    /**
     * Einen Inhalt eines eingebetteten link-Feldes um eine Stelle verschieben.
     *
     * @param string $direction  'up' | 'down'
     * @return bool  false, wenn nichts zu verschieben war (Anfang/Ende) oder Fehler
     */
    public function embedMove(array $colDef, int $srcId, int $destId, string $direction): bool
    {
        $fieldId = (int)($colDef['field_id'] ?? 0);
        if ($fieldId < 1 || $srcId < 1 || $destId < 1) return false;
        if (!$this->getProdData($srcId)) return false;
        $ids = $this->linkDestIds($srcId, $fieldId);
        $pos = array_search($destId, $ids, true);
        if ($pos === false) return false;
        $swap = ($direction === 'up') ? $pos - 1 : $pos + 1;
        if ($swap < 0 || $swap >= count($ids)) return false;
        [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
        $this->_writeLinkPositions($srcId, $fieldId, $ids);
        return true;
    }

    /**
     * Einen Inhalt aus einem eingebetteten link-Feld entfernen: Kante lösen; eine
     * Arbeitskopie (neu eingefügt oder mit ausgecheckt) wird verworfen - ihr
     * produktives Original bleibt bis zum Check-in stehen und wird dort als
     * verwaist gelöscht (checkInDependents()).
     */
    public function embedRemove(array $colDef, int $srcId, int $destId): bool
    {
        if ($srcId < 1 || $destId < 1) return false;
        if (!$this->getProdData($srcId)) return false;
        $this->removeRelations($colDef, $srcId, $destId);
        if ($this->getProdData($destId)) {
            $this->cancel($destId);
        }
        return true;
    }

    /**
     * Beim Check-in des Quelldatensatzes - siehe Klassenkommentar. Rekursiv.
     *
     * @param int $tmpId     Arbeitskopie des Quelldatensatzes (Session-Admin)
     * @param int $originId  produktives Original (0 bei neuem Datensatz / Kopie)
     * @return bool          false bei Fehlern (Cms::errors); der Aufrufer rollt zurück
     */
    public function checkInDependents(int $tmpId, int $originId): bool
    {
        if (!$prodData = $this->getProdData($tmpId)) {
            $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
            return false;
        }
        $areaId = (int)$prodData[1];
        $schemaVirtual = $this->virtualSchema($areaId, false);
        // (Felder ohne Zielzugriff wurden nicht ausgecheckt - ihre Kanten zeigen
        //  weiter auf die produktiven Inhalte und wandern unverändert mit)
        $linkFields = $this->accessibleLinkFields($schemaVirtual);
        if (empty($linkFields)) return true;

        $before = $originId > 0 ? $this->dependentIds($originId, productiveOnly: true) : [];
        $sessionAdminId = (int)$this->session->get('admin_id');

        foreach ($linkFields as $name => $colDef) {
            $fieldId = (int)$colDef['field_id'];
            foreach ($this->linkDestIds($tmpId, $fieldId) as $destId) {
                $this->db->pushResult();
                $this->db->query(
                    "SELECT `tmp`, `origin_id`, `changed_by`, `deleted`, `area_id` FROM `record` WHERE `record_id` = ?",
                    'i', [$destId]
                );
                $row = $this->db->fetch();
                $this->db->popResult();
                if (!$row) continue;

                if (!empty($row['tmp'])) {
                    // Arbeitskopie eines Inhalts (eingebettet bzw. Kopie): eigene
                    // Inhalte zuerst, dann Check-in - die Kante wandert auf das
                    // Original (checkIn() -> _copyRelations()) bzw. bleibt bei einer
                    // Arbeitskopie ohne Original an deren Id
                    if ((int)$row['changed_by'] !== $sessionAdminId || !empty($row['deleted'])) {
                        $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
                        return false;
                    }
                    if (!$this->checkInDependents($destId, (int)$row['origin_id'])) return false;
                    if (!$this->_saveDependent($destId, (int)$row['area_id'])) return false;
                    continue;
                }

                // produktiver Inhalt: gehört er einem anderen Datensatz, ist der
                // Quelldatensatz eine Kopie - der Inhalt wird mitkopiert
                if ($this->linkOwner($destId, $tmpId) !== null) {
                    if (!$copyId = $this->_copyDependent($destId)) return false;
                    $this->_repointLink($fieldId, $tmpId, $destId, $copyId);
                }
            }
        }

        // Verwaiste Inhalte des Originals löschen (die Inhalte sind jetzt alle
        // produktiv: eingecheckt, kopiert oder unverändert)
        if ($originId > 0) {
            $after = $this->dependentIds($tmpId, productiveOnly: true);
            foreach (array_diff($before, $after) as $orphanId) {
                if ($this->linkOwner($orphanId, $tmpId) !== null) continue; // (kann nicht sein)
                if (!$this->delete($orphanId, false)) return false;
            }
        }
        return true;
    }

    /**
     * Beim Abbrechen (Record::cancel()): die Arbeitskopien der Inhalte mit
     * verwerfen - rekursiv, bevor die Kanten mit der Arbeitskopie verschwinden.
     *
     * @param int $tmpId  Arbeitskopie des Quelldatensatzes (Session-Admin)
     */
    public function cancelDependents(int $tmpId): void
    {
        foreach ($this->dependentIds($tmpId) as $destId) {
            if (!$this->getProdData($destId)) continue;   // produktiv oder fremd
            $this->cancelDependents($destId);
            $this->cancel($destId);
        }
    }

    /**
     * Soft-Delete / Wiederherstellen / endgültiges Löschen des Quelldatensatzes auf
     * seine Inhalte ausdehnen (Record-Hooks). Läuft in der Transaktion des Vorgangs;
     * die Inhalte laufen einzeln durch dieselben Hooks - rekursiv über ihre eigenen
     * link-Felder.
     *
     * @param int    $srcId  produktiver Datensatz
     * @param string $mode   'delete' | 'restore' | 'purge'
     */
    protected function cascadeDependents(int $srcId, string $mode): void
    {
        foreach ($this->dependentIds($srcId) as $destId) {
            $this->db->pushResult();
            $this->db->query("SELECT `tmp`, `deleted` FROM `record` WHERE `record_id` = ?", 'i', [$destId]);
            $row = $this->db->fetch();
            $this->db->popResult();
            if (!$row || !empty($row['tmp'])) continue;
            switch ($mode) {
                case 'delete':
                    if (!empty($row['deleted'])) continue 2;
                    $this->softDeleteOne($destId);
                    break;
                case 'restore':
                    if (empty($row['deleted'])) continue 2;
                    // nur Inhalte desselben Löschvorgangs (gleicher Zeitstempel)
                    $stamp = $this->getRestoreTimestamp();
                    if ($stamp !== '' && $this->rawChangedAt($destId) !== $stamp) continue 2;
                    $this->restoreOneCore($destId);
                    break;
                case 'purge':
                    $this->finalDeleteOne($destId);
                    break;
            }
        }
    }

    // ====================
    // Hilfsfunktionen

    /**
     * Check-in einer Arbeitskopie eines Inhalts: Record::save() mit den gestagten
     * Werten aus ihrem Arbeitsstand (die Eingabemaske hat sie mit stayTemporary
     * geprüft und zwischengespeichert, eine Kopie trägt den Stand ihres Vorbilds).
     * Die Zustände eines äußeren save() (MoveTrait, Import) bleiben unberührt.
     */
    private function _saveDependent(int $tmpId, int $areaId): bool
    {
        $keep = [$this->storedFormatFields, $this->prehashedFields, $this->deferRelationChecks, $this->savingTemporary, $this->areaMoveFiles];
        $this->storedFormatFields = [];
        $this->prehashedFields = [];
        $this->deferRelationChecks = false;
        // Die Uploads des laufenden Requests ($_FILES['data']) gehören dem
        // Quelldatensatz - die des Inhalts hat die Maske schon gesichert
        // (RecordBackendEdit::_embedSave() mit stayTemporary)
        $files = $_FILES['data'] ?? null;
        unset($_FILES['data']);
        try {
            $data = ['record_id' => $tmpId, 'area_id' => $areaId];
            return (bool)$this->save(['area_id'], $data);
        } finally {
            [$this->storedFormatFields, $this->prehashedFields, $this->deferRelationChecks, $this->savingTemporary, $this->areaMoveFiles] = $keep;
            if ($files !== null) $_FILES['data'] = $files;
        }
    }

    /**
     * Eine Kante (src -> old) auf ein anderes Ziel umhängen (src -> new), ohne am
     * Primärschlüssel zu scheitern: TemporaryTrait::_copyRelations() hat die Kante
     * beim Auschecken des Ziels unter Umständen schon auf die Arbeitskopie kopiert.
     */
    private function _repointLink(int $fieldId, int $srcId, int $oldDestId, int $newDestId): void
    {
        $this->db->pushResult();
        $this->db->query(
            "SELECT `fieldname_id`, `position` FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?",
            'iii', [$fieldId, $srcId, $oldDestId]
        );
        $row = $this->db->fetch();
        $this->db->popResult();
        if (!$row) return;
        $this->db->query(
            "DELETE FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?",
            'iii', [$fieldId, $srcId, $oldDestId]
        );
        $this->db->query(
            "INSERT IGNORE INTO `data_rel` (`field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`) VALUES (?, ?, ?, ?, ?)",
            'iiiii', [$fieldId, (int)$row['fieldname_id'], $srcId, $newDestId, (int)$row['position']]
        );
        $this->db->query(
            "UPDATE `data_rel` SET `position` = ? WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?",
            'iiii', [(int)$row['position'], $fieldId, $srcId, $newDestId]
        );
    }

    /**
     * Einen produktiven Inhalt kopieren (für die Kopie eines Quelldatensatzes):
     * Arbeitskopie ohne Original mit kopierten Dateien (checkOut() mit $copy), dann
     * Check-in als neuer Datensatz - samt seiner eigenen fest verknüpften Inhalte.
     *
     * @return int|null  record_id der Kopie
     */
    private function _copyDependent(int $recordId): ?int
    {
        if (!$data = $this->load($recordId)) return null;
        if (!empty($data['tmp']) || !empty($data['deleted'])) return null;
        if (!$copyId = $this->checkOut($data, true)) return null;
        if (!$this->checkInDependents($copyId, 0)) return null;
        if (!$this->_saveDependent($copyId, (int)$data['area_id'])) return null;
        return $copyId;
    }

    /**
     * data_rel.position eines link-Feldes neu durchnummerieren.
     *
     * @param int[] $ids  dest_ids in der gewünschten Reihenfolge
     */
    private function _writeLinkPositions(int $srcId, int $fieldId, array $ids): void
    {
        foreach (array_values($ids) as $position => $destId) {
            $this->db->query(
                "UPDATE `data_rel` SET `position` = ? WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?",
                'iiii', [$position, $fieldId, $srcId, (int)$destId]
            );
        }
    }

    /**
     * SQL-Liste der abhängigen Feldtypen (Cms::$dependentTypes), z. B. "'link'".
     */
    private function _dependentTypeList(): string
    {
        return "'" . implode("','", array_map(
            fn($t) => preg_replace('/[^a-z0-9_]/', '', (string)$t),
            $this->cms->dependentTypes
        )) . "'";
    }
}
