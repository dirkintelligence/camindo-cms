<?php
/**
 * Trait for the table `record`: permanently linked contents - fields of type 'link'.
 *
 * A 'link' field is a directed reference like 'pointer' (edge in `data_rel`,
 * target areas in `rel_field_area`), but with ownership: the target records BELONG to the
 * source record. Two rules follow from this:
 *
 *   1. At most one source record per target record (linkOwner(); checked in
 *      VirtualFieldsTrait::createRelations()).
 *   2. The target record shares the fate of its source record: deleting,
 *      restoring and permanently deleting (record hooks -> cascade*()),
 *      copying (the contents are copied along) and moving (the contents
 *      of link fields not mapped are dropped).
 *
 * The target areas are dependent areas (`area.dependent` = 1): they do not appear
 * in the area list of the Record module; their records are edited via the
 * source record.
 *
 * TWO REPRESENTATIONS IN THE INPUT MASK (field.display_type)
 * --------------------
 *     lookup   the search window of the other link fields. The target records
 *              remain live during editing, the links hang
 *              on the working copy of the source record.
 *     embed    the input masks of the target records sit inside the mask of the
 *              source record. For this, the target records are checked out WHEN THE MASK
 *              IS OPENED (checkOutDependents()): uploads and lookup fields in
 *              the embedded masks write immediately to a record_id and
 *              need a working copy of the session admin for this - and cancelling
 *              must also be able to discard the embedded input
 *              (cancelDependents()). The edges of the working copy then point to the
 *              working copies of the target records; a newly inserted target record
 *              is a working copy without original (tmpInit()).
 *
 * THE CHECK-IN (checkInDependents())
 * --------------------
 * Runs BEFORE Dataset::save() of the source record, in the same transaction
 * (RecordBackendEdit::_editSave(), MoveTrait::moveSave()). For each edge of the working copy:
 *
 *     target is a working copy of the admin   -> check-in (Record::save()), recursively
 *                                               for its own link fields; the
 *                                               edge moves onto the original
 *                                               (TemporaryTrait::_copyRelations())
 *     target belongs to another live           -> the source record is a copy
 *     record (not to the own original)            (TemporaryTrait::checkOut() with $copy):
 *                                               the target is copied, the edge points
 *                                               to the copy
 *     target is live and free                  -> stays (lookup: linked from the search
 *                                               window or newly created)
 *
 * Then the orphans: contents that the original held so far and that no longer hang
 * on any edge of the working copy (lookup "remove link", embedded
 * "Delete", moving without mapping), are deleted - soft, like every
 * record in the backend. Up to the check-in everything is reversible: discarding the working copy
 * (cancel) leaves the original including its contents as it was.
 *
 * IMPORT (CSV, DOCX)
 * --------------------
 * RecordImport::_importLinks() works like the embedded mask: check out contents at the
 * working copy (linkRepoint()) or create them (embedInsert()), check values with
 * stayTemporary, set the order (linkReorder()), then
 * checkInDependents() with the source record in one transaction.
 *
 * NOT COVERED
 * --------------------
 * Batch processing, XML import and CMS:USER_INPUT save without
 * checkInDependents(): they copy the edges of the working copy back unchanged -
 * contents are neither copied nor deleted as orphans there.
 *
 * @see Camindo\Core\Modules\Record\RecordBackendEdit  Eingabemaske (Lookup, eingebettet)
 * @see Camindo\Core\Modules\Area\Field                 Felddefinition 'link'
 * @see Camindo\Core\TemporaryTrait                     checkOut / checkIn / cancel
 */
namespace Camindo\Core;

trait LinkTrait
{
    /**
     * The link fields of an area.
     *
     * @param array $schemaVirtual  virtualSchema($areaId, false)
     * @return array  name => field definition
     */
    public function linkFields(array $schemaVirtual): array
    {
        return array_filter(
            $schemaVirtual,
            fn(array $colDef) => in_array((string)($colDef['type'] ?? ''), $this->cms->dependentTypes, true)
        );
    }

    /**
     * link fields whose target area the user group may read - only these
     * are checked out and in as well; the others keep their edges to the
     * live contents (Dataset::relationTargetReadable()).
     */
    public function accessibleLinkFields(array $schemaVirtual): array
    {
        return array_filter($this->linkFields($schemaVirtual), fn(array $colDef) => $this->relationTargetReadable($colDef));
    }

    /**
     * Target areas of a link field (rel_field_area).
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
     * Fields of the target areas of a link field, as export and import carry them
     * as fields of the source record ("{link}.{field}", RecordExport,
     * EMBEDDED CONTENTS) - for several target areas the union by name.
     * All virtual fields except links, full text and the tree/
     * sort fields; file fields including thumbnails.
     *
     * @return array  name => field definition (that of the first target area with this name)
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
     * Re-attach an edge of a link field to another target (import: the
     * content was checked out, the edge of the working copy of the source record
     * then points to its working copy - as with checkOutDependents()).
     */
    public function linkRepoint(int $fieldId, int $srcId, int $oldDestId, int $newDestId): void
    {
        $this->_repointLink($fieldId, $srcId, $oldDestId, $newDestId);
    }

    /**
     * Set the order of the contents of a link field.
     *
     * @param int[] $ids  dest_ids in the desired order
     */
    public function linkReorder(int $srcId, int $fieldId, array $ids): void
    {
        $this->_writeLinkPositions($srcId, $fieldId, $ids);
    }

    /**
     * Target records of a link field in their order (data_rel.position) -
     * including working copies, hence not via Cms::select().
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
     * All permanently linked contents of a record (via each of its link fields).
     *
     * As long as a content is checked out, the live source record also holds
     * an edge to its working copy (TemporaryTrait::_copyRelations() copies
     * the incoming edges along) - $productiveOnly leaves out working copies.
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
     * Who owns a record? The LIVE source record that holds it via a
     * link field - except $exceptId and its original or working copies:
     * the working copy of a source record may link what its original
     * already holds (createRelations() after a tab switch, check-in).
     *
     * @param int $destId    target record
     * @param int $exceptId  source record (live or working copy) that does not count
     * @return int|null      record_id of the owner or null (free)
     */
    public function linkOwner(int $destId, int $exceptId = 0): ?int
    {
        if ($destId < 1) return null;
        $this->db->pushResult();
        // resolve $exceptId to its original
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
     * Is the working copy $tmpId an EMBEDDED content - is it attached to a
     * link field of another working copy (checkOutDependents() / embedInsert())?
     * Then its input fields are called embed[{tmpId}][field] instead of data[field]
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
     * Remove the incoming link edges of a record (a copy belongs to
     * nobody - TemporaryTrait::checkOut() with $copy).
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
     * When the input mask is opened: check out the contents of the EMBEDDED link fields
     * (display_type 'embed') as well - see class comment.
     *
     * The edges of the working copy $tmpId (copied from the original by checkOut(), they
     * point to the live contents) are re-attached to the new working copies of the
     * contents. For a COPY of the source record ($copy) copies of the contents are created
     * (checkOut() with $copy: without original, with copied files) -
     * the check-in turns them into new records. A content locked by someone else
     * (edit lock of another admin) aborts.
     *
     * Recursive for embedded fields of the contents themselves (the mask does not show them
     * - their edges and working copies must still be correct so that the check-in
     * and cancelling find them).
     *
     * @param int   $tmpId          working copy of the source record
     * @param bool  $copy           true: the source record is copied
     * @param array $schemaVirtual  schema of the area (virtual fields)
     * @return bool                 false on errors (Cms::errors)
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
                if ($isTmp) continue;   // already checked out (mask opened again)

                if (!$destData = $this->load($destId)) return false;
                if (!empty($destData['deleted'])) {
                    // deleted content: drop the edge, the check-in no longer cleans it up
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
                // re-attach the edge to the working copy of the content
                $this->_repointLink($fieldId, $tmpId, $destId, $destTmpId);
                // recursive: embedded fields of the content
                $destSchema = $this->virtualSchema((int)$destData['area_id'], false);
                if (!$this->checkOutDependents($destTmpId, $copy, $destSchema)) return false;
            }
        }
        return true;
    }

    /**
     * Insert a new content into an embedded link field: working copy without
     * original in the target area (tmpInit()) and edge on the working copy of the
     * source record, placed before or after $refId (0 = at the end).
     *
     * @param array  $colDef   link field
     * @param int    $srcId    working copy of the source record (session admin)
     * @param int    $areaId   target area (one of the target areas of the field)
     * @param int    $refId    reference content or 0
     * @param bool   $before   true: before $refId, otherwise after it
     * @return int|null        record_id of the new working copy, null on errors
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
            // append at the end, then insert in order
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
     * Move a content of an embedded link field by one position.
     *
     * @param string $direction  'up' | 'down'
     * @return bool  false if there was nothing to move (start/end) or an error
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
     * Remove a content from an embedded link field: drop the edge; a
     * working copy (newly inserted or checked out) is discarded - its
     * live original remains until the check-in and is deleted there as
     * an orphan (checkInDependents()).
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
     * At the check-in of the source record - see class comment. Recursive.
     *
     * @param int $tmpId     working copy of the source record (session admin)
     * @param int $originId  live original (0 for a new record / copy)
     * @return bool          false on errors (Cms::errors); the caller rolls back
     */
    public function checkInDependents(int $tmpId, int $originId): bool
    {
        if (!$prodData = $this->getProdData($tmpId)) {
            $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
            return false;
        }
        $areaId = (int)$prodData[1];
        $schemaVirtual = $this->virtualSchema($areaId, false);
        // (Fields without target access were not checked out - their edges continue
        //  to point to the live contents and move along unchanged)
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
                    // working copy of a content (embedded or copy): its own
                    // contents first, then check-in - the edge moves onto the
                    // original (checkIn() -> _copyRelations()) or, for a
                    // working copy without an original, stays with its id
                    if ((int)$row['changed_by'] !== $sessionAdminId || !empty($row['deleted'])) {
                        $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
                        return false;
                    }
                    if (!$this->checkInDependents($destId, (int)$row['origin_id'])) return false;
                    if (!$this->_saveDependent($destId, (int)$row['area_id'])) return false;
                    continue;
                }

                // live content: if it belongs to another record, the
                // source record is a copy - the content is copied along
                if ($this->linkOwner($destId, $tmpId) !== null) {
                    if (!$copyId = $this->_copyDependent($destId)) return false;
                    $this->_repointLink($fieldId, $tmpId, $destId, $copyId);
                }
            }
        }

        // Delete orphaned contents of the original (the contents are now all
        // live: checked in, copied or unchanged)
        if ($originId > 0) {
            $after = $this->dependentIds($tmpId, productiveOnly: true);
            foreach (array_diff($before, $after) as $orphanId) {
                if ($this->linkOwner($orphanId, $tmpId) !== null) continue; // (cannot happen)
                if (!$this->delete($orphanId, false)) return false;
            }
        }
        return true;
    }

    /**
     * On cancelling (Record::cancel()): discard the working copies of the contents
     * as well - recursively, before the edges disappear with the working copy.
     *
     * @param int $tmpId  working copy of the source record (session admin)
     */
    public function cancelDependents(int $tmpId): void
    {
        foreach ($this->dependentIds($tmpId) as $destId) {
            if (!$this->getProdData($destId)) continue;   // live or foreign
            $this->cancelDependents($destId);
            $this->cancel($destId);
        }
    }

    /**
     * Extend soft delete / restore / permanent deletion of the source record to its
     * contents (record hooks). Runs in the transaction of the operation;
     * the contents go through the same hooks one by one - recursively via their own
     * link fields.
     *
     * @param int    $srcId  live record
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
                    // only contents of the same deletion operation (same timestamp)
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
     * Check-in of a working copy of a content: Record::save() with the staged
     * values from its working state (the input mask checked them with stayTemporary
     * and saved them temporarily; a copy carries the state of its original).
     * The states of an outer save() (MoveTrait, import) remain untouched.
     */
    private function _saveDependent(int $tmpId, int $areaId): bool
    {
        $keep = [$this->storedFormatFields, $this->prehashedFields, $this->deferRelationChecks, $this->savingTemporary, $this->areaMoveFiles];
        $this->storedFormatFields = [];
        $this->prehashedFields = [];
        $this->deferRelationChecks = false;
        // The uploads of the current request ($_FILES['data']) belong to the
        // source record - the mask has already saved those of the content
        // (RecordBackendEdit::_embedSave() with stayTemporary)
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
     * Re-attach an edge (src -> old) to another target (src -> new) without failing
     * on the primary key: TemporaryTrait::_copyRelations() may already have copied the edge
     * to the working copy when the target was checked out.
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
     * Copy a live content (for the copy of a source record):
     * working copy without original with copied files (checkOut() with $copy), then
     * check-in as a new record - including its own permanently linked contents.
     *
     * @return int|null  record_id of the copy
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
     * Renumber data_rel.position of a link field.
     *
     * @param int[] $ids  dest_ids in the desired order
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
     * SQL list of the dependent field types (Cms::$dependentTypes), e.g. "'link'".
     */
    private function _dependentTypeList(): string
    {
        return "'" . implode("','", array_map(
            fn($t) => preg_replace('/[^a-z0-9_]/', '', (string)$t),
            $this->cms->dependentTypes
        )) . "'";
    }
}
