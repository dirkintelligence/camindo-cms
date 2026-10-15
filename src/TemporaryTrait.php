<?php
 /**
 * Trait for Dataset subclasses
 * Processes data from virtual table fields in the data tables
 *
 * Concept "single-step updates" (Dataset::save() with $stayTemporary = true):
 *
 * Concept "secure files immediately, thumbnails later":
 * A file (upload from $_FILES or a selected server file from $data) is checked for
 * type IMMEDIATELY and - if allowed - physically stored under the id of the
 * working copy and entered in its working state (secureUploads()).
 * This way it survives an input check that subsequently fails and appears
 * in the redisplayed input mask.
 * Thumbnail generation is decoupled from this: the file entry is marked with
 * 'thumbnails_pending' = true and only processed during the FINAL save via
 * writePendingThumbnails() (from saveVirtualFields()) - thus once
 * per record instead of at every intermediate step.
 *
 * Fields:
 *      'tmp'             : state 0 = live, 1 = temporary
 *      'origin_id'       : `{tableName}_id` of the live original, 0 for a new record
 *      'changed_at'      : time of the last change
 *      'changed_by'      : admin id of the last change
 *      'deleted'         : 1 = if live: record was deleted (trash)
 *                              if temporary: working copy can no longer go live (edit lock deleted)
 *
 * Table `record` additionally - the WORKING STATE of the working copy:
 *      It does not live in its own column, but like the live state per
 *      language in `record_cache` - under the id of the working copy, in the same format
 *      (Cms::createRecordCache(): field name => [type, value, fallback flag]).
 *      Cms::fetch() reads a working copy in this way just like a live
 *      record. It serves as a buffer for single-step updates
 *      (Dataset::save() with $stayTemporary = true - only the language
 *      rows that change are written) and as the data basis of the input mask /
 *      file previews; at checkOut it is taken from the `record_cache` of the live
 *      original (VirtualFieldsTrait::_loadTmpData() / _saveTmpData()),
 *      at checkIn it disappears along with the working copy (FK cascade).
 *      Encrypted contents are stored there as ciphertext, passwords not at all.
 *                          File entries carry flags:
 *                          'temporary' = true: new file of the working copy (physically
 *                              stored under its id; the `data_fil` entry is only created
 *                              at checkIn, see promoteTmpFiles())
 *                          'deleted' = true: file was removed via the x button
 *                          Entries WITHOUT flags describe the unchanged file
 *                          of the live original (origin_id)
 */
namespace Camindo\Core;

trait TemporaryTrait
{
    /**
     * CREATE
     * Create a temporary record
     * (in order to be able to add temporary relations)
     */
    public function tmpInit(array $defaults = [], int $forceId = 0): ?array
    {
        $idField = $this->tableName . '_id';

        // area_id - also store it in the temporary record, it can be useful for lookup fields
        $areaId = $this->hasAreaId ? (int)$defaults['area_id'] : null;

        // Rights of the user group: create in the area
        if ($areaId !== null && !$this->accessCheck('create', $areaId)) return null;

        // $forceId: the working copy gets this id and later goes live under it - the import
        // thus creates records with the id from the import file (RecordImport); the id must
        // be free (Record::freeRecordId())
        if (!$tmpId = $this->insertTmpRecord(0, $areaId, 0, $forceId)) {
            return null;
        }
        $data = $this->init($defaults);
        $data[$idField] = $tmpId;
        $data['tmp'] = 1;
        return $data;
    }

    /**
     * Check whether a temporary working copy exists for a live record
     *
     * @param array &$data  meta field contents
     * @return array        If an edit lock exists: [changed_by, changed_at], otherwise []
     */
    public function getLock(array &$data): array
    {
        if (!empty($data['tmp'])) {
            throw new \Exception('isLocked() Exception: dataset is not productive.');
        }
        $tableName = $this->tableName;
        $idField = $tableName . '_id';
        $id = (int)$data[$idField];
        
        // Change date and admin are stored in the temporary copy
        $sql = "SELECT `changed_by`,`changed_at` FROM `$tableName`"
            ." WHERE `tmp`=1 AND `deleted`=0 AND `origin_id` = ? ORDER BY `changed_at` DESC";
        $this->db->query($sql, 'i', [$id]);
        if ($this->db->num_rows < 1) {
            // Keine Kopie gefunden
            return [];
        }
        [$changedBy, $changedAt] = $this->db->fetchNum();

        // Check last change (changed_at is UTC time, datetime(3)) - a copy
        // that has been untouched for longer than admin.edit_lock_sec no longer locks
        $lockSec = (int)$this->config->get('admin.edit_lock_sec');
        $outdatedAt = gmdate('Y-m-d H:i:s', time() - $lockSec);
        if ($changedAt < $outdatedAt) {
            // Last change to the copy lies too far back
            // -> even if by another admin: it can be deleted
            return [];
        }

        // Check who created the temporary copy
        $sessionAdminId = (int)$this->session->get('admin_id');
        if ($changedBy === $sessionAdminId) {
            // The copy comes from the session admin himself
            // -> ignore the lock, assume the admin knows what he is doing
            return [];
        }

        // Respect the edit lock
        return [$changedBy, $changedAt];
    }

    /**
     * CHECKOUT
     * Creates a temporary working copy of a live record
     * Prerequisites:
     * - record is live (tmp=0, deleted=0)
     * - record was loaded with load()
     *
     * The edit lock should be checked beforehand, see
     * - Dataset::getLock()
     *
     * The original record receives:
     * - `locked` = 1
     *
     * The working copy receives:
     * - `tmp` = 1
     * - `origin_id`  = id of the original record
     * - `changed_by` = sessionAdminId
     * - `changed_at` = UTC_TIMESTAMP()
     * - `record_cache` rows (table `record` only): the live state of all
     *                  languages as working state - buffer for
     *                  single-step updates and data basis of the input mask
     *
     * @param array &$data  from the preceding Dataset::load()
     * @param bool $copy    true: a copy with origin_id = 0 is created
     *                        the original record is not locked
     * @return int|null     `{tableName}_id` of the working copy, or null on error
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

        // Rights of the user group: changing the original resp. creating the copy
        if (!$this->accessCheck($copy ? 'create' : 'write', (int)($data['area_id'] ?? 0), isset($data['owner_id']) ? (int)$data['owner_id'] : null)) {
            return null;
        }

        // Transaction (Database supports nesting)
        try {
            $this->db->beginTransaction();
            if (!$copy) {
                // Mark existing working copies of the original as invalid
                // Their physical files are removed (based on the 'temporary'
                // entries in their working state; working copies no longer have
                // `data_fil` entries)
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
                // Remove the links of the invalid copies. The rows stay
                // (save() recognizes the lost edit lock from them), their edges do not:
                // _copyRelations() also placed INCOMING edges onto the copy -
                // for embedded content (link) e.g. original source -> copy. If they
                // remained, the dead copy would reappear in the mask of the source at its
                // next check-out, and saving would fail because of it.
                if ($oldTmpIds) {
                    $in = implode(',', $oldTmpIds);
                    $this->db->query("DELETE FROM `data_rel` WHERE `src_id` IN ($in) OR `dest_id` IN ($in)");
                    $this->db->query("DELETE FROM `data_rel_checkout` WHERE `tmp_id` IN ($in)");
                }
            }

            // area_id - also store it in the temporary record, it can be useful for lookup fields
            $areaId = $this->hasAreaId ? (int)$data['area_id'] : null;

            $originId = $copy ? 0 : $id;
            // $id is the source also when copying ($copy) - there originId is 0,
            // but the date values should nevertheless come from the template.
            if (!$tmpId = $this->insertTmpRecord($originId, $areaId, $id)) {
                // Copy not created
                $errorMsg = $this->cms->snippet('error_create_temporary');
                $this->cms->error($errorMsg);
                throw new \Exception('#' . $errorMsg);
            }
            // Working state: the live state of all languages from `record_cache`
            // of the original (missing language rows are rebuilt in the process) into the
            // `record_cache` rows of the working copy - buffer for
            // single-step updates ($stayTemporary), data basis of the input mask
            if ($tableName === 'record' && method_exists($this, '_tmpDataFromRecordCache')) {
                $schemaVirtual = $this->virtualSchema((int)$areaId, false);
                $langCtx = $this->languageContext((int)$areaId);
                $this->_saveTmpData($tmpId, $this->_tmpDataFromRecordCache($id, $schemaVirtual, $langCtx));
            }

            // data aktualisieren
            $data[$idField] = $tmpId;
            $data['tmp'] = 1;
            $data['origin_id'] = $originId;

            // Copy relations
            // --------------------
            if ($tableName === 'record') {
                $this->_copyRelations($id, $tmpId, $copy);
                // Note the state at check-out - the check-in only transfers what has
                // changed on the working copy since then (_copyRelationsBack())
                if (!$copy) {
                    $this->db->query(
                        "INSERT INTO `data_rel_checkout` (`tmp_id`, `field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`)"
                        . " SELECT ?, `field_id`, `fieldname_id`, `src_id`, `dest_id`, `position` FROM `data_rel`"
                        . " WHERE `src_id` = ? OR `dest_id` = ?",
                        'iii', [$tmpId, $tmpId, $tmpId]
                    );
                }
                // A COPY belongs to no source record: incoming 'link' links
                // (firmly linked contents, at most one source record per target record)
                // stay with the template (LinkTrait)
                if ($copy && method_exists($this, 'removeIncomingLinks')) {
                    $this->removeIncomingLinks($tmpId);
                }
            }

            // Copy files when a copy is created
            // --------------------
            if ($tableName === 'record' && $copy === true) {
                $this->_copyFiles($id, $tmpId);
            }

            $this->db->commit();
            return $tmpId;

        } catch (\Exception $e) {
            // Add error messages that have not yet been collected in Cms::errors
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            // Undo everything
            $this->db->rollback();
            return null;
        }
    }

    /**
     * Helper function: create a temporary record
     * (shared INSERT of tmpInit() and checkOut())
     *
     * @param int      $originId  id of the live original, 0 for a new record / copy
     * @param int|null $areaId    area_id or null if the table has no area_id
     * @param int      $sourceId  template for the date columns (checkOut: the original
     *                            resp. the copy template), 0 for new creation
     * @param int      $forceId   id the working copy should get (0 = auto_increment)
     * @return int                id of the working copy (0 on error)
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
            // instance_id - already in the working copy
            // --------------------
            // The column carries a foreign key to `instance` and has no
            // usable default: what the INSERT does not set, MySQL fills with 0 -
            // but there is no instance with id 0, the INSERT would fail on the
            // foreign key. What matters is the instance of the content area; when
            // saving, Record::hookOnSave() fetches it from there anyway (Dataset::areaInstanceId()).
            $sql .= ", `instance_id` = ?";
            $sqlTypes .= 'i';
            $sqlValues[] = $this->areaInstanceId((int)$areaId);
            // parent_ids is JSON NOT NULL without a default. MySQL fills a missing value with
            // JSON null, MariaDB with '' - which violates its implicit CHECK (json_valid()).
            $sql .= ", `parent_ids` = '[]'";
        }
        $this->db->query($sql, $sqlTypes, $sqlValues);
        $tmpId = $forceId > 0 ? $forceId : (int)$this->db->insert_id;
        if ($tmpId < 1) return 0;

        // Take over the date columns from the original record
        // --------------------
        // The datetime columns are NOT NULL and have no DB default. What the
        // INSERT above does not set, MySQL therefore fills with the null date
        // 0000-00-00 00:00:00 (the sql_mode of this installation allows that) - and
        // at check-in this value moved into the live record. That is exactly how
        // the null dates in the existing data came about.
        //
        // Copied via SQL from the source row, not via $data: there timestamp
        // values are already in the user's time zone (Cms::utcToUserTZ()),
        // when written back they would be shifted by the zone offset.
        //
        // changed_at is left out - it is already set to UTC_TIMESTAMP() above.
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
                // New creation (tmpInit): there is no template. The lower bound stands
                // for "no date set"; the actual defaults from the schema
                // ('now' for online_at) are set by init() in $data and save() writes them.
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
     * Makes a temporary working copy live.
     * Called by Dataset::save() when a temporary record is saved
     *
     * Moves files of the working copy to the live record and creates
     * the `data_fil` entries from the file metadata of the working state, or deletes
     * files removed via deletion marker (promoteTmpFiles)
     * Copies the relations of the working copy into the live record
     * Deletes the temporary record
     *
     * @param int $tmpId            id of the temporary state
     * @param int|null $prodId      id of the live state, if present
     * @param array $schemaVirtual  schema with input fields (relation fields needed)
     */
    protected function checkIn(int $tmpId, ?int $prodId, array $schemaVirtual): void
    {
        if (!$prodId) {
            // The temporary copy goes live under its own id (new record
            // or copy): enter the 'temporary' files from its working state into
            // `data_fil` now (promoteTmpFiles with tmpId as target id;
            // no physical move takes place). Its `record_cache`
            // rows saveVirtualFields() has already brought to the live state.
            if ($this->tableName == 'record' && method_exists($this, 'promoteTmpFiles')) {
                $this->promoteTmpFiles($tmpId, $tmpId);
            }
            // Links already hang on the final id - only enforce the rule
            // "at most one target" on the other side
            if ($this->tableName == 'record') {
                $this->_enforceSingleRelations($tmpId, $schemaVirtual);
            }
            return;
        }

        $tableName     = $this->tableName;
        $idField       = $tableName . '_id';

        if ($this->tableName == 'record') {
            // Assign the files of the working copy to the original now and create their
            // `data_fil` entries from the file metadata, or delete files
            // removed via deletion marker
            // (BEFORE deleting the copy: promoteTmpFiles() reads its working state)
            if (method_exists($this, 'promoteTmpFiles')) {
                $this->promoteTmpFiles($tmpId, $prodId);
            }
            // Assign the links of the temporary copy to the original now
            $this->_copyRelationsBack($tmpId, $prodId, $schemaVirtual);
        }

        // delete the temporary copy (also deletes its relations)
        $sql = "DELETE FROM `{$tableName}` WHERE `$idField` = ?";
        $this->db->query($sql, 'i', [$tmpId]);
    }

    /**
     * Cancel the editing process - deletes the temporary working copy
     * and removes the edit lock
     *
     * @param int $tmpId    id of a temporary record
     */
    public function cancel(int $tmpId): void
    {
        if ($tmpId < 1) return;

        if (!$prodData = $this->getProdData($tmpId)) {
            return;
        }

        // Working copy is temporary and belongs to the session admin
        $tableName     = $this->tableName;
        $idField       = $tableName . '_id';
        // Remove the physical files of the working copy
        // (based on the 'temporary' entries in its working state; working copies
        //  no longer have `data_fil` entries)
        if ($tableName == 'record' && method_exists($this, 'deleteRecordFiles')) {
            $this->deleteRecordFiles($tmpId);
        }
        // Delete the working copy
        $this->db->query("DELETE FROM `$tableName` WHERE `$idField` = ?", 'i', [$tmpId]);
    }

    /**
     * Helper function: copy the links of the 'relation' and 'pointer' fields from record $id1 to $id2
     *
     * Check-out ($asCopy = false): all edges 1:1, also the incoming ones
     * (other records -> $id1) with their position - the working copy stands for
     * the original. (The check-in does not copy back, but reconciles:
     * _copyRelationsBack().)
     *
     * Copy of a record ($asCopy = true): the copy is a NEW record.
     *   - Incoming edges (X -> $id1 in the field of X) are appended to the end of X's list
     *     (MAX(position) + 1) - otherwise template and copy would share a position,
     *     the order would be random.
     *   - Edges whose other side allows only ONE target (field of X resp. counter field
     *     of type relation1/pointer1 or max = 1) are not taken over: X already has
     *     its one target (the template). Otherwise the check-in of the copy would
     *     enforce the rule "at most one target" (_enforceSingleRelations()) and take
     *     it away from the template.
     */
    protected function _copyRelations(int $id1, int $id2, bool $asCopy = false): void
    {
        // Delete the links of the target record
        $sql = "DELETE FROM `data_rel` WHERE `src_id` = ? OR `dest_id` = ?";
        $this->db->query($sql, 'ii', [$id2, $id2]);

        if (!$asCopy) {
            // Copy relations where $id1 is the source (src_id)
            // Copies (src_id = $id1, dest_id = X) -> to (src_id = $id2, dest_id = X)
            $sql = "INSERT INTO `data_rel` (`field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`)"
                ." SELECT `field_id`, `fieldname_id`, ?, `dest_id`, `position`"
                ." FROM `data_rel`"
                ." WHERE `src_id` = ?";
            $this->db->query($sql, 'ii', [$id2, $id1]);

            // Copy relations where $id1 is the target (dest_id)
            // Copies (src_id = X, dest_id = $id1) -> to (src_id = X, dest_id = $id2)
            $sql = "INSERT INTO `data_rel` (`field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`)"
                ." SELECT `field_id`, `fieldname_id`, `src_id`, ?, `position`"
                ." FROM `data_rel`"
                ." WHERE `dest_id` = ?";
            $this->db->query($sql, 'ii', [$id2, $id1]);
            return;
        }

        // Field accepts only one target (alias as placeholder)
        $single = "(%s.`type` IN ('" . implode("','", $this->cms->singleRelationTypes) . "') OR %s.`max` = '1')";

        // Own edges of the copy ($id2 -> X), position as in the template - except
        // when the counter field of X allows only one target
        $sql = "INSERT INTO `data_rel` (`field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`)"
            ." SELECT `r`.`field_id`, `r`.`fieldname_id`, ?, `r`.`dest_id`, `r`.`position`"
            ." FROM `data_rel` `r`"
            ." INNER JOIN `field` `f` ON `f`.`field_id` = `r`.`field_id`"
            ." LEFT JOIN `field` `g` ON `g`.`field_id` = `f`.`rel_field_id`"
            ." WHERE `r`.`src_id` = ?"
            ." AND NOT (`g`.`field_id` IS NOT NULL AND " . sprintf($single, '`g`', '`g`') . ")";
        $this->db->query($sql, 'ii', [$id2, $id1]);

        // Incoming edges (X -> $id2) appended to the list of X - except when the field
        // of X allows only one target. For each (X, field) there is at most one such
        // edge (dest_id is part of the primary key), MAX + 1 stays unique.
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
     * Transfer the links of the working copy to the original at check-in.
     *
     * Only what has changed ON THE WORKING COPY since the check-out is transferred -
     * compared with the state that checkOut() stored in `data_rel_checkout`.
     * What other records have meanwhile changed on the original remains:
     * for example a reference B -> A that was set while A was checked out, or
     * the mirror edge A -> B of a bidirectional field that B has edited.
     * (Previously the check-in replaced all edges of the original with those of the
     * working copy - such changes were lost.)
     *
     * Three set operations, each mapping the working copy onto the original:
     *
     *   1. removed: edges of the check-out state that the working copy lacks
     *      -> delete on the original
     *   2. new or changed (position, field): edges of the working copy that are missing
     *      in the check-out state or look different -> create resp.
     *      update on the original. For this ALWAYS the edges to other working copies:
     *      firmly linked contents move between the working copies via them
     *      (LinkTrait::_repointLink(), checkInDependents())
     *   3. renumber the order of the original's own links without gaps -
     *      edges from the working copy and from others can share a
     *      position
     *
     * Afterwards the rule "at most one target" on the other side - now for the
     * original, whose edges are settled.
     */
    protected function _copyRelationsBack(int $tmpId, int $prodId, array $schemaVirtual): void
    {
        // Edge of the working copy -> edge of the original (integers only, literal)
        $map = fn(string $column) => "CASE {$column} WHEN {$tmpId} THEN {$prodId} ELSE {$column} END";

        // 1. removed
        $this->db->query(
            "DELETE `d` FROM `data_rel_checkout` `b`"
            . " INNER JOIN `data_rel` `d` ON `d`.`src_id` = " . $map('`b`.`src_id`')
            . "   AND `d`.`fieldname_id` = `b`.`fieldname_id` AND `d`.`dest_id` = " . $map('`b`.`dest_id`')
            . " LEFT JOIN `data_rel` `r` ON `r`.`src_id` = `b`.`src_id`"
            . "   AND `r`.`fieldname_id` = `b`.`fieldname_id` AND `r`.`dest_id` = `b`.`dest_id`"
            . " WHERE `b`.`tmp_id` = ? AND `r`.`src_id` IS NULL",
            'i', [$tmpId]
        );

        // 2. new or changed, plus the edges to other working copies
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

        // 3. Order of the own links per field
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
     * Enforce the rule "at most one target" (relation1 / pointer1 resp. max = 1) for the
     * links of record $srcId - at check-in, when the links of the working copy become final
     * (working copy with original or new record under its own id).
     *
     * The own side is kept clean by createRelations() (never more than one row per
     * …1 field). Here it concerns the COUNTER SIDE of a 'relation' field: if the
     * counter field allows only one target, each target record may be linked only by this one
     * source record - its previous links in this field pair
     * (from other source records) are dissolved, in both directions.
     *
     * Example: Article.topic (relation1) <-> Section.rel_article (relation). If
     * article A is attached to section 3, it loses section 1. Only here and not in
     * createRelations(): as long as the link hangs on the working copy, it must
     * be discardable (cancel) without article A losing anything.
     *
     * Independent of visibility_input - the rule also applies to links that
     * arise not via the mask but via import or batch processing.
     *
     * @param int   $srcId          record whose links should apply
     * @param array $schemaVirtual  schema with input fields
     */
    protected function _enforceSingleRelations(int $srcId, array $schemaVirtual): void
    {
        foreach ($schemaVirtual as $name => $colDef) {
            $type = $colDef['type'] ?? 'text';
            if (!str_starts_with($type, 'relation')) continue;
            // Fetch info on the counter field
            $relFieldId = (int)$colDef['rel_field_id'];
            if (!$relField = $this->fieldData($relFieldId)) {
                // this case should never occur
                $this->createFieldError('record.error_dest_relationfield', $name, $colDef['label'] ?? $name);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $relIsSingle = in_array((string)($relField['type'] ?? ''), $this->cms->singleRelationTypes, true)
                || (int)($relField['max'] ?? 0) === 1;
            if (!$relIsSingle) continue;
            $fieldId = (int)$colDef['field_id'];

            // No other record may point (in its own field) to one of the target records
            // except the source record
            $sql = "DELETE `d2` FROM `data_rel` `d2`"
                   ." INNER JOIN `data_rel` `d1`"
                   ."     ON `d1`.`field_id` = ?"
                   ."     AND `d1`.`src_id` = ?"
                   ."     AND `d1`.`dest_id` = `d2`.`dest_id`"
                   ." WHERE `d2`.`field_id` = ?"
                   ." AND `d2`.`src_id` != ?";
            $this->db->query($sql, 'iiii', [$fieldId, $srcId, $fieldId, $srcId]);

            // Respect bidirectionality
            // The target records may (in the counter field) not point to any other record
            // except the source record
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
     * Physically duplicate the files of the live record $id1 for the copy $id2
     * and mark them with 'temporary' = true in the working state of the copy.
     * NO `data_fil` entries are created for the copy: these arise only when going live
     * in promoteTmpFiles() from the file metadata
     * (the copy goes live with origin_id = 0 under its own id,
     *  see checkIn()).
     */
    private function _copyFiles(int $id1, int $id2): void
    {
        $mediaDir = $this->config->get('paths.media_dir');

        // Read the metadata of the live files (incl. field name for the
        // field name) - only the rows of the physical files: single-language
        // fields carry the same row per language (file_language_id NULL), for
        // multilingual ones each language counts its own file (file_language_id =
        // language_id); fallback rows point to one of them.
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

        // Update the working state of the copy
        // (already taken over from the original at checkOut)
        $cache = $this->_loadTmpData($id2);
        foreach ($rows as $f) {
            $name = (string)($f['name'] ?? '');
            if ($name === '') continue;
            $fieldId = (int)$f['field_id'];
            $extension = (string)$f['extension'];
            $fileLang = $this->cms->fileLanguageId($f);

            // 1. Copy the file
            // (empty extension = thumbnail field without an applicable converter, no file)
            if ($extension !== '') {
                $src  = "$mediaDir/" . $this->cms->mediaPath($fieldId, $id1, $fileLang, $extension);
                $dest = "$mediaDir/" . $this->cms->mediaPath($fieldId, $id2, $fileLang, $extension);
                if (!@copy($src, $dest)) {
                    // Abort instead of silently continuing: otherwise a `data_fil`
                    // entry without a physical file would arise when going live
                    $this->system->log('file-errors', "$src > $dest : copy failed.");
                    throw new \Exception("Error: _copyFiles(): could not copy \"$src\".");
                }
            }

            // 2. Mark the file entry with 'temporary'
            // (promoteTmpFiles() creates the `data_fil` entry from it when going live;
            //  deleteRecordFiles() finds the physical file via it if the copy is cancelled)
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
     * Checks
     * - is the record temporary
     * - does the temporary record belong to the session admin
     * if not -> null
     * otherwise -> id and area_id of the live record
     *
     * @param int $tmpId    id of a temporary record
     * @return array|null   [id, area_id] of the live record or null
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
            // not found
            return null; 
        }

        [$tmp, $prodId, $changedBy, $areaId] = $this->db->fetchNum();
        if (empty($tmp)) {
            // not temporary
            return null;
        }

        $sessionAdminId = (int)$this->session->get('admin_id');
        if ((int)$changedBy !== $sessionAdminId) {
            // does not belong to the session admin
            return null;
        }

        return [(int)$prodId, (int)$areaId];
    }
}
