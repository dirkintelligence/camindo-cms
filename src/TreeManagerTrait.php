<?php
/**
 * Extension for child classes of Dataset
 * Handling of nested sets with adjacency lists
 *
 * Fields:
 *      'parent_id' : id of the parent record
 *      'position'  : position within the direct parent
 *                    Note: 'previous_id' is processed (preceding record)
 *                    This is not a real database column - 'position' is derived from it
 *      'lft' and
 *      'rgt'       : boundaries in the nested set
 *      'depth'     : depth of the hierarchy, 0-based (root nodes have depth = 0)
 *
 * ONE TREE PER CONTENT AREA
 * --------------------
 * If the table has an `area_id` (i.e. `record`), the tree is NOT table-wide,
 * but there is a separate, self-contained tree per content area:
 *
 *      - `lft`, `rgt` start again at 1 in EVERY area
 *      - `position` counts per area and parent
 *      - every query via `lft`/`rgt` MUST therefore filter on `area_id` as well
 *
 * Whether an area has a tree at all is decided by the editor: only if they create an
 * input field named `parent_id` in the area (meta field) is the tree
 * active there - isTreeArea(). In all other areas
 *
 *      parent_id  = NULL
 *      lft, rgt   = 0
 *      depth      = 0
 *      parent_ids = []
 *
 * and NO tree maintenance takes place. This is the normal case and the reason for
 * the distinction: most areas are flat, but contain very many
 * records - a table-wide rebuildTree() on every save would mean an
 * update per record of the whole table there, without any benefit.
 *
 * Tables without `area_id` (`area` itself) have exactly one tree over the whole
 * table; isTreeArea() always returns true there and the area filters are dropped.
 *
 * Soft delete:
 *      Entries marked as deleted initially remain in the tree.
 *      They take part in changes (lft, rgt, position, depth)
 *      until they are finally deleted.
 *      However, they are not selectable in the select lists of the input masks
 *      (parent_id, previous_id), therefore getPreviousId() and validateNodeExists()
 *      skip deleted records.
 *      Soft delete without children (withChilds == false): child records are
 *      first assigned to the parent (liftChildren()), analogous to real deletion.
 *      All records marked in one operation receive exactly the same
 *      changed_at (Dataset::$markTimestamp); restore() only restores records of
 *      the same deletion operation (Dataset::$restoreTimestamp).
 *
 * No more mass operations:
 *      Previously deleteWithChilds() and setDeletedWithChilds() removed a whole
 *      subtree with ONE SQL statement via lft/rgt - no hooks ran in the process.
 *      Both have been removed. The trait now only supplies the ids of the subtree
 *      (subtreeIds()); Dataset sends them through softDeleteOne(),
 *      restoreOneCore() or finalDeleteOne() one at a time. The tree is recalculated
 *      once at the end of the operation (rebuildTreeNow()), not per record.
 */

namespace Camindo\Core;
trait TreeManagerTrait
{
    /**
     * Flag per content area: does it have a tree? (isTreeArea())
     * Dataset subclasses are shared services - the cache therefore lives as long as
     * the request. If a `parent_id` field is created or removed, it must be
     * discarded: clearTreeAreaCache().
     */
    private array $treeAreaCache = [];

    /**
     * Does this content area have a tree?
     *
     * Decisive is whether an input field `parent_id` is defined in the area -
     * the same check the backend uses to decide whether it displays the list as a tree
     * (isset($schemaVirtual['parent_id'])). Here only the table `field` is
     * queried, so that the answer is also available in the delete paths without a
     * loaded schema.
     *
     * Tables without `area_id` (`area`) have exactly one tree -> always true.
     *
     * @param int $areaId  id of the content area
     */
    public function isTreeArea(int $areaId): bool
    {
        if (!$this->hasParentId) return false;
        if (!$this->hasAreaId)   return true;   // table without areas: one tree
        if ($areaId < 1)         return false;

        if (isset($this->treeAreaCache[$areaId])) {
            return $this->treeAreaCache[$areaId];
        }
        $this->db->pushResult();
        $this->db->query(
            "SELECT 1 FROM `field` WHERE `area_id` = ? AND `name` = 'parent_id' LIMIT 1",
            'i', [$areaId], true
        );
        $isTree = ($this->db->num_rows > 0);
        $this->db->popResult();

        return $this->treeAreaCache[$areaId] = $isTree;
    }

    /**
     * Ids of all content areas that have a tree.
     * For repair/migration tools that recalculate all trees.
     *
     * @return int[]  for tables without `area_id`: empty array (one tree, no areas)
     */
    public function treeAreaIds(): array
    {
        if (!$this->hasParentId || !$this->hasAreaId) return [];

        $this->db->pushResult();
        $this->db->query("SELECT DISTINCT `area_id` FROM `field` WHERE `name` = 'parent_id'");
        $ids = $this->db->fetchAll(); // flaches Array
        $this->db->popResult();

        return array_map('intval', $ids);
    }

    /**
     * Discard the flag from isTreeArea().
     * Necessary as soon as a `parent_id` field has been created or removed
     * (Field::hookOnSaved() / Field::hookOnDeleted()).
     */
    public function clearTreeAreaCache(): void
    {
        $this->treeAreaCache = [];
    }

    /**
     * Helper method: SQL filter for records that belong to the tree.
     * Temporary working copies (tmp=1) never belong to the tree.
     * Records marked as deleted remain in the tree (lft, rgt, position)
     * and are therefore by default NOT filtered out.
     *
     * @param bool $excludeDeleted  true: additionally require `deleted`=0
     *                              (for input masks / select lists)
     * @return string               ' AND ...' to append to a WHERE clause
     */
    private function treeFilter(bool $excludeDeleted = false): string
    {
        $sql = '';
        if ($this->hasTmp) $sql .= " AND `tmp`=0";
        if ($excludeDeleted && $this->hasDeleted) $sql .= " AND `deleted`=0";
        return $sql;
    }

    /**
     * Helper method: build the WHERE condition for the content area.
     *
     * Every tree lives within ONE area (see class comment). Without this
     * filter, sibling operations on the root level
     * (`parent_id` IS NULL) would affect all areas - there the
     * area is the only distinguishing feature.
     *
     * @param int    $areaId      id of the content area (0: no filter)
     * @param string &$bindTypes  bind types (are added to)
     * @param array  &$values     bind values (are added to)
     * @return string             '' or ' AND `area_id` = ?'
     */
    private function areaCondition(int $areaId, string &$bindTypes, array &$values): string
    {
        if (!$this->hasAreaId || $areaId < 1) return '';
        $bindTypes .= 'i';
        $values[]   = $areaId;
        return " AND `area_id` = ?";
    }

    /**
     * Helper method: build the WHERE condition for the parent.
     * parent_id = 0 stands for the root level (`parent_id` IS NULL).
     * The area filter always runs along (see areaCondition()).
     * Bind types and values are added as needed.
     *
     * @param int    $parentId    id of the parent record or 0
     * @param int    $areaId      id of the content area (0: no filter)
     * @param string &$bindTypes  bind types (are added to)
     * @param array  &$values     bind values (are added to)
     * @return string             SQL condition
     */
    private function parentCondition(int $parentId, int $areaId, string &$bindTypes, array &$values): string
    {
        if ($parentId === 0) {
            $sql = "`parent_id` IS NULL";
        }
        else {
            $bindTypes .= 'i';
            $values[]   = $parentId;
            $sql = "`parent_id` = ?";
        }
        return $sql . $this->areaCondition($areaId, $bindTypes, $values);
    }

    /**
     * Look up the area id of a record.
     * For the few places that only have an id at hand.
     *
     * @param int $id  `{tableName}_id`
     * @return int     0 if the table does not know areas or the record is missing
     */
    private function areaIdOf(int $id): int
    {
        if (!$this->hasAreaId || $id < 1) return 0;
        $this->db->pushResult();
        $this->db->query(
            "SELECT `area_id` FROM `{$this->tableName}` WHERE `{$this->tableName}_id` = ?",
            'i', [$id], true
        );
        $areaId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        return $areaId;
    }

    /**
     * Read access: loads a branch including all children.
     * If 0 is passed, the entire tree of the area is loaded.
     *
     * @param int $rootId    id of the root record, or 0 for the entire tree
     * @param int $levels    depth relative to the root node:
     *                       -1: unlimited (default)
     *                        0: only the root node itself, no children
     *                        1: root + direct children
     *                        n: root + n levels of children
     * @param bool $includeDeleted  true:  also return records marked as deleted (default,
     *                                     they remain in the tree; the field `deleted` is in the result)
     *                              false: only non-deleted records
     * @param int $areaId    content area; with $rootId > 0 it is taken
     *                       from the root node. Without $rootId mandatory for tables with `area_id`,
     *                       otherwise the trees of all areas would come back mixed.
     */
    public function loadTree(int $rootId = 0, int $levels = -1, bool $includeDeleted = true, int $areaId = 0): array
    {
        $conditions = [];
        $bindTypes  = '';
        $values     = [];
        $rootDepth  = 0;
        if ($rootId > 0) {
            // Load the root node to know lft/rgt/depth/area_id for the area query
            $sql = "SELECT `lft`, `rgt`, `depth`"
                 . ($this->hasAreaId ? ", `area_id`" : "")
                 . " FROM `{$this->tableName}` WHERE `{$this->tableName}_id` = ?";
            
            $sql .= $this->treeFilter(!$includeDeleted);
            
            $this->db->query($sql, 'i', [$rootId], true);
            $root = $this->db->fetch();
            $this->db->free();
            if (!$root) {
                return [];
            }
            if ((int)$root['lft'] < 1) {
                // Not a valid nested-set range (area without a tree) -> only the node
                return $this->loadOne($rootId);
            }
            $areaId    = (int)($root['area_id'] ?? $areaId);
            $rootDepth = (int)$root['depth'];
            // Subtree: all nodes in the lft/rgt range of the root node
            $conditions[] = '`lft` BETWEEN ? AND ?';
            $bindTypes   .= 'ii';
            $values[]     = (int)$root['lft'];
            $values[]     = (int)$root['rgt'];
        }
        else {
            // Without a root node only real tree nodes (lft = 0 means: no tree)
            $conditions[] = '`lft` > 0';
        }
        // One tree per area - without this filter foreign areas would come along
        if ($this->hasAreaId && $areaId > 0) {
            $conditions[] = '`area_id` = ?';
            $bindTypes   .= 'i';
            $values[]     = $areaId;
        }
        // tmp copies never, deleted only filtered out on request
        if ($this->hasTmp) {
            $conditions[] = '`tmp`=0';
        }
        if (!$includeDeleted && $this->hasDeleted) {
            $conditions[] = '`deleted`=0';
        }
        if ($levels >= 0) {
            // Depth limit: absolute depth value = root depth + desired levels
            $conditions[] = '`depth` <= ?';
            $bindTypes   .= 'i';
            $values[]     = $rootDepth + $levels;
        }
        // Assemble the WHERE clause
        $where = !empty($conditions)
            ? 'WHERE ' . implode(' AND ', $conditions)
            : '';
        $sql = "SELECT * FROM `{$this->tableName}` {$where} ORDER BY `lft` ASC";
        $this->db->query($sql, $bindTypes, $values, true);
        $data = $this->db->fetchAll();
        $this->db->free();
        return $data;
    }

    /**
     * Helper method for loadTree(): single record as a one-element list.
     */
    private function loadOne(int $id): array
    {
        $this->db->query(
            "SELECT * FROM `{$this->tableName}` WHERE `{$this->tableName}_id` = ?",
            'i', [$id], true
        );
        $row = $this->db->fetch();
        $this->db->free();
        return $row ? [$row] : [];
    }

    /**
     * Collect tree elements for creating a new record.
     * Sets position and prepares placeholders for lft/rgt/depth.
     * rebuildTree() is called after the INSERT in Dataset::save().
     *
     * Only called for areas with a tree (Dataset::save() checks isTreeArea()).
     *
     * @param array $data    data ready for saving (is extended)
     * @param int   $areaId  content area of the record
     */
    public function createInTree(array &$data, int $areaId = 0): void
    {
        $parentId   = (int)($data['parent_id']   ?? 0);
        $previousId = (int)($data['previous_id'] ?? 0);
        // Validation of the given records (must lie in the same area)
        $this->validateNodeExists($parentId,   'parent_id',   $areaId);
        $this->validateNodeExists($previousId, 'previous_id', $areaId);
        // Positions of the target level must be 1..n, otherwise
        // calculatePosition() would continue with duplicate assignments (normalizeLevel())
        $this->normalizeLevel($parentId, $areaId);
        // derive position from previous_id and shift siblings backwards
        $data['position'] = $this->calculatePosition($parentId, $previousId, $areaId);
        $this->shiftPositionsDown($parentId, $data['position'], 0, $areaId);
        // Placeholders – rebuildTree() sets the real values after the INSERT
        $data['lft']  = 0;
        $data['rgt']  = 0;
        $data['depth'] = 0;
    }

    /**
     * Collect tree elements for updating a record.
     * Internally checks whether a repositioning is needed at all –
     * previous_id (preceding record): it is specified in $data, but is not itself
     * stored in the database and therefore is always missing from $previousData
     * and must be derived.
     *
     * Closes the gap at the old place, makes room at the new place
     * and writes parent_id + position directly into the DB.
     * rebuildTree() is called after the UPDATE in Dataset::save().
     *
     * @param array $data          data ready for saving (is extended)
     * @param array $previousData  previous data of the record
     * @param int   $areaId        content area of the record (target area)
     * @return bool                true if the record was actually re-attached or
     *                             moved - only then must the caller have the
     *                             tree recalculated
     */
    public function updateInTree(array &$data, array $previousData, int $areaId = 0): bool
    {
        $id         = (int)($data[$this->tableName . '_id'] ?? 0);
        $parentId   = (int)($data['parent_id']   ?? 0);
        $previousId = (int)($data['previous_id'] ?? 0);
        $oldParentId = (int)($previousData['parent_id'] ?? 0);
        $oldPosition = (int)($previousData['position']  ?? 0);
        $oldAreaId   = (int)($previousData['area_id']   ?? $areaId);
        // Bring the previous level to 1..n (duplicate assignments, gaps, position 0 from
        // legacy data) – otherwise neither the derived predecessor nor the
        // shifts are correct. Its own position may have changed in the process.
        if ($this->normalizeLevel($oldParentId, $oldAreaId)) {
            $oldPosition = $this->positionOf($id, $oldPosition);
        }
        // Derive previous_id (ID of the preceding record) from the DB,
        // since this field is not persisted and is missing from $previousData.
        // IMPORTANT: use the same logic as when filling the input mask
        // (getPreviousId skips records marked as deleted),
        // so that the comparison with the previous_id submitted from the mask
        // stays consistent - even if records have meanwhile been
        // marked as deleted and restored.
        $oldPreviousId = 0;
        if ($oldPosition > 1) {
            $oldPreviousId = $this->getPreviousId($oldParentId, $oldPosition, $oldAreaId);
        }
        // No repositioning needed
        if (
            $parentId   === $oldParentId
            && $previousId === $oldPreviousId
            && $areaId     === $oldAreaId
        ) {
            // Pass the current position: without a tree Dataset::save() writes the
            // column from $data (the loaded value may be outdated due to normalizeLevel())
            $data['position'] = $oldPosition;
            return false;
        }
        // Validation of the given records (must lie in the same area)
        $this->validateNodeExists($parentId,   'parent_id',   $areaId);
        $this->validateNodeExists($previousId, 'previous_id', $areaId);
        // The new parent must not be the record itself or lie in its subtree -
        // the branch would detach from the tree (cycle)
        if ($parentId > 0 && $this->isInSubtree($parentId, $id, $areaId)) {
            $this->createFieldError('error_parent_in_subtree', 'parent_id', '#record.field_parent_id');
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        // Close the gap at the old place
        $this->shiftPositionsUp($oldParentId, $oldPosition, $id, $oldAreaId);
        // Also bring the new level to 1..n (its own row does not count there,
        // it still stands on the old level or is overwritten below)
        if ($parentId !== $oldParentId || $areaId !== $oldAreaId) {
            $this->normalizeLevel($parentId, $areaId, $id);
        }
        // Make room at the new place
        $newPosition = $this->calculatePosition($parentId, $previousId, $areaId);
        $this->shiftPositionsDown($parentId, $newPosition, $id, $areaId);
        // Write parent_id and position directly (before rebuildTree)
        $newParentSql = $parentId === 0 ? '`parent_id` = NULL' : '`parent_id` = ?';
        $bindTypes    = $parentId === 0 ? 'ii'                 : 'iii';
        $values       = $parentId === 0
            ? [$newPosition,            $id]
            : [$parentId, $newPosition, $id];
        $this->db->query(
            "UPDATE `{$this->tableName}` SET {$newParentSql}, `position` = ?"
            . " WHERE `{$this->tableName}_id` = ?",
            $bindTypes, $values
        );
        // lft, rgt, depth – rebuildTree() takes care of these after the UPDATE in save()
        $data['position'] = $newPosition;
        return true;
    }

    /**
     * Is $nodeId the record $rootId itself or one of its descendants? Via the
     * nested set (lft/rgt of the area) - basis of the cycle check in
     * updateInTree() and of the batch processing (RecordBatch).
     *
     * @param int $nodeId  record to check (e.g. the new parent record)
     * @param int $rootId  root of the subtree
     * @param int $areaId  content area
     */
    public function isInSubtree(int $nodeId, int $rootId, int $areaId = 0): bool
    {
        if ($nodeId < 1 || $rootId < 1) return false;
        if ($nodeId === $rootId) return true;
        $bindTypes = 'ii';
        $values    = [$rootId, $nodeId];
        $sql = "SELECT 1 FROM `{$this->tableName}` `r` JOIN `{$this->tableName}` `n`"
             . " ON `n`.`lft` > `r`.`lft` AND `n`.`lft` < `r`.`rgt`"
             . ($this->hasAreaId ? " AND `n`.`area_id` = `r`.`area_id`" : '')
             . " WHERE `r`.`{$this->tableName}_id` = ? AND `n`.`{$this->tableName}_id` = ?";
        if ($this->hasAreaId && $areaId > 0) {
            $sql .= " AND `r`.`area_id` = ?";
            $bindTypes .= 'i';
            $values[]   = $areaId;
        }
        $this->db->pushResult();
        $this->db->query($sql, $bindTypes, $values);
        $found = $this->db->num_rows > 0;
        $this->db->popResult();
        return $found;
    }

    /**
     * The last child (highest position) under $parentId - the predecessor for
     * "append at the end". Records marked as deleted are skipped as in
     * getPreviousId(), working copies do not count.
     *
     * @param int $parentId   id of the parent record or 0 (root level)
     * @param int $areaId     content area
     * @param int $excludeId  record that is not counted (the one being moved)
     * @return int            record id or 0 (no children)
     */
    public function lastChildId(int $parentId, int $areaId = 0, int $excludeId = 0): int
    {
        $bindTypes = '';
        $values    = [];
        $sql = "SELECT `{$this->tableName}_id` FROM `{$this->tableName}`"
             . " WHERE " . $this->parentCondition($parentId, $areaId, $bindTypes, $values)
             . " AND `{$this->tableName}_id` != ?";
        $bindTypes .= 'i';
        $values[]   = $excludeId;
        $sql .= $this->treeFilter(true);
        $sql .= " ORDER BY `position` DESC LIMIT 1";
        $this->db->pushResult();
        $this->db->query($sql, $bindTypes, $values);
        $id = $this->db->num_rows > 0 ? (int)$this->db->fetchNum()[0] : 0;
        $this->db->popResult();
        return $id;
    }

    /**
     * Collect the ids of a subtree in processing order.
     *
     * Replaces the former mass operations deleteWithChilds() and
     * setDeletedWithChilds(), which cleared the whole subtree with ONE SQL statement via
     * lft/rgt - no hooks ran in the process. Instead this method only returns the
     * ids now; Dataset sends them one at a time through softDeleteOne(),
     * restoreOneCore() or finalDeleteOne().
     *
     * ORDER
     *     'delete', 'purge'  leaves first (rgt ascending). Mandatory for permanent
     *                        deletion: otherwise the foreign key parent_id would delete
     *                        whole subtrees with ON DELETE CASCADE, without their
     *                        hookOnDelete() ever running.
     *     'restore'          parents first (lft ascending) - the reverse.
     *
     * FILTER
     *     'delete'   only not yet marked records (`deleted` = 0): already
     *                marked ones keep the changed_at of their own deletion operation
     *     'restore'  only marked records (`deleted` = 1), and only those with exactly
     *                the changed_at of the operation to be undone
     *                (Dataset::$restoreTimestamp) - records from earlier
     *                deletion operations remain marked
     *     'purge'    all records of the subtree
     *
     * The lft/rgt range applies per content area, hence `area_id` runs along.
     * Temporary working copies are never part of the tree (treeFilter()) and are
     * handled by the individual functions per record.
     *
     * @param array  $data  record with lft / rgt / area_id (from loadProductive())
     * @param string $mode  'delete' | 'restore' | 'purge'
     * @return int[]        ids in processing order
     */
    public function subtreeIds(array $data, string $mode = 'delete'): array
    {
        $idField = $this->tableName . '_id';
        $lft = (int)($data['lft'] ?? 0);
        $rgt = (int)($data['rgt'] ?? 0);
        if ($lft < 1 || $rgt <= $lft) {
            // Not a valid nested-set range (area without a tree, leaf without
            // valid boundaries) -> only the record itself
            $id = (int)($data[$idField] ?? 0);
            return $id > 0 ? [$id] : [];
        }

        $bindTypes = 'ii';
        $values    = [$lft, $rgt];

        $sql = "SELECT `{$idField}` FROM `{$this->tableName}`"
             . " WHERE `lft` >= ? AND `rgt` <= ?"
             . $this->areaCondition((int)($data['area_id'] ?? 0), $bindTypes, $values)
             . $this->treeFilter();

        if ($mode === 'delete' && $this->hasDeleted) {
            $sql .= " AND `deleted` = 0";
        }
        elseif ($mode === 'restore' && $this->hasDeleted) {
            $sql .= " AND `deleted` = 1";
            if ($this->restoreTimestamp !== '' && $this->hasChangedAt) {
                $sql .= " AND `changed_at` = ?";
                $bindTypes .= 's';
                $values[]   = $this->restoreTimestamp;
            }
        }

        // Leaves first, or parents first
        $sql .= ($mode === 'restore') ? " ORDER BY `lft` ASC" : " ORDER BY `rgt` ASC";

        $this->db->pushResult();
        $this->db->query($sql, $bindTypes, $values);
        $ids = $this->db->fetchAll(); // flaches Array
        $this->db->popResult();

        return array_map('intval', $ids);
    }

    /**
     * Close the gap that a permanently deleted record leaves among its siblings
     * (adjacency-list positioning).
     *
     * Called by Dataset::finalDeleteOne() for each deleted record - only for
     * records from areas with a tree. lft, rgt and depth are left out here -
     * the caller sorts them out with ONE rebuildTree() run at the end of the entire
     * operation.
     *
     * @param array $data  record with parent_id / position / area_id
     */
    public function closePositionGap(array $data): void
    {
        $parentId = (int)($data['parent_id'] ?? 0);
        $position = (int)($data['position']  ?? 0);
        $areaId   = (int)($data['area_id']   ?? 0);
        if ($position < 1) return;
        $this->shiftPositionsUp($parentId, $position, 0, $areaId);
    }

    /**
     * Recalculate lft, rgt, depth and parent_ids immediately - WITHIN the running
     * transaction of the caller.
     *
     * Dataset calls this once at the end of purge() or finalDelete(), after all
     * records have been removed individually. The detour via its own method exists
     * because rebuildTree() is private.
     *
     * Not to be confused with externalRebuildTree(): that opens its OWN
     * transaction and is meant for callers that do not have one yet (repair tools).
     *
     * @param int $areaId  content area whose tree is recalculated
     */
    public function rebuildTreeNow(int $areaId = 0): void
    {
        if (!$this->isTreeArea($areaId)) return;
        $this->rebuildTree($areaId);
    }

    /**
     * Delete without children (withChilds == false): assign direct child records to the parent of the
     * affected record, so that they do not become orphans. Applies to soft delete
     * and permanent deletion alike; called from Dataset::collectIds().
     * Difference to real deletion: the marked record remains as a
     * (deleted) leaf in the tree and keeps its position; the children are
     * sorted in directly AFTER it under the parent (no gap to close).
     * Children marked as deleted take part as well.
     * Afterwards the tree of the area is recalculated (lft, rgt, depth, parent_ids).
     * Runs within the transaction of the caller (Dataset::delete() / purge()).
     *
     * @param array $data  previous contents of the record to be marked
     */
    public function liftChildren(array $data): void
    {
        $idField  = $this->tableName . '_id';
        $id       = (int)($data[$idField]    ?? 0);
        $parentId = (int)($data['parent_id'] ?? 0);
        $position = (int)($data['position']  ?? 0);
        $areaId   = (int)($data['area_id']   ?? 0);
        if ($id === 0) return;
        // Determine direct children (including deleted, without tmp copies)
        $sql = "SELECT `{$idField}` FROM `{$this->tableName}` WHERE `parent_id` = ?";
        $sql .= $this->treeFilter();
        $sql .= " ORDER BY `position` ASC";
        $this->db->query($sql, 'i', [$id], true);
        $children = $this->db->fetchAll(); // flaches Array
        $this->db->free();
        $count = count($children);
        if ($count === 0) return;
        // Make room after the marked record:
        // shift the following siblings backwards by the number of children
        $bindTypes = 'i';
        $values    = [$count];
        $sql = "UPDATE `{$this->tableName}` SET `position` = `position` + ?"
             . " WHERE " . $this->parentCondition($parentId, $areaId, $bindTypes, $values)
             . " AND `position` > ?";
        $bindTypes .= 'i';
        $values[]   = $position;
        $sql .= $this->treeFilter();
        $this->db->query($sql, $bindTypes, $values);
        // Re-attach the children: new parent, positions directly after the record
        // (order of the children among themselves is kept)
        $newParentSql = $parentId === 0 ? '`parent_id` = NULL' : '`parent_id` = ?';
        $pos = $position;
        foreach ($children as $childId) {
            $pos++;
            $bindTypes = $parentId === 0 ? 'ii' : 'iii';
            $values    = $parentId === 0
                ? [$pos,            (int)$childId]
                : [$parentId, $pos, (int)$childId];
            $this->db->query(
                "UPDATE `{$this->tableName}` SET {$newParentSql}, `position` = ?"
                . " WHERE `{$idField}` = ?",
                $bindTypes, $values
            );
        }
        // Recalculate lft, rgt, depth, parent_ids (children have moved up one level)
        // (Database allows nested transactions, see rebuildTree())
        $this->rebuildTree($areaId);
    }

    /**
     * Copy the children of a node and attach them to a new (empty) node.
     * After duplicating a record.
     * Children marked as deleted are not copied along.
     *
     * @param int $idFrom  id of the source record
     * @param int $idTo    id of the target record
     */
    public function copyChilds(int $idFrom, int $idTo): void
    {
        if ($idFrom === $idTo) return;
        $areaId = $this->areaIdOf($idTo);
        if (!$this->isTreeArea($areaId)) return;
        $this->validateNodeExists($idFrom, 'parent_id', 0);
        $this->validateNodeExists($idTo,   'parent_id', $areaId);
        $this->copySubtree($idFrom, $idTo, $areaId);
        $this->rebuildTree($areaId);
    }

    /**
     * Perform a recursive copy of a subtree.
     * Records marked as deleted are skipped.
     *
     * @param int $sourceParentId  id of the source parent record
     * @param int $targetParentId  id of the target parent record
     * @param int $areaId          content area of the TARGET node
     */
    private function copySubtree(int $sourceParentId, int $targetParentId, int $areaId): void
    {
        // The source parent is always a real node - with 0 (root level) the
        // selection would not be restricted to an area.
        if ($sourceParentId < 1) return;
        $bindTypes = '';
        $values    = [];
        // Source: the area follows from the source parent itself
        $sql = "SELECT `{$this->tableName}_id` FROM `{$this->tableName}`"
             . " WHERE " . $this->parentCondition($sourceParentId, 0, $bindTypes, $values);
        $sql .= $this->treeFilter(true); // do not copy deleted ones
        $sql .= " ORDER BY `position` ASC";
        
        $this->db->query($sql, $bindTypes, $values, true);
        $children = $this->db->fetchAll();
        $this->db->free();
        $idField       = $this->tableName . '_id';
        $previousNewId = 0; // first entry has no predecessor
        foreach ($children as $childId) {
            $data = $this->load($childId);
            if (!$data) continue;
            $data[$idField]      = 0;               // is assigned anew
            $data['parent_id']   = $targetParentId; // new parent
            $data['previous_id'] = $previousNewId;  // predecessor in the target tree
            $newChildId = $this->save([], $data);
            if (!$newChildId) continue;
            // For the next pass: this new record is the predecessor
            $previousNewId = $newChildId;
            // Recurse deeper into the tree
            $this->copySubtree($childId, $newChildId, $areaId);
        }
    }

    /**
     * Checks whether a node exists and otherwise throws an exception.
     * Nodes marked as deleted count as not selectable
     * (they are not available for selection in the select lists of the input masks).
     * With $areaId it is also checked that the node lies in the SAME content area -
     * a branch must never hang across area boundaries.
     *
     * @param int    $nodeId  id of the node to check (0 is skipped)
     * @param string $name    field name for the error message ('parent_id', 'previous_id')
     * @param int    $areaId  content area in which the node must lie (0: any)
     */
    private function validateNodeExists(int $nodeId, string $name, int $areaId = 0): void
    {
        if ($nodeId === 0) return;
        $bindTypes = 'i';
        $values    = [$nodeId];
        $sql = "SELECT 1 FROM `{$this->tableName}` WHERE `{$this->tableName}_id` = ?";
        $sql .= $this->areaCondition($areaId, $bindTypes, $values);
        $sql .= $this->treeFilter(true);
        $this->db->query($sql, $bindTypes, $values, true);
        $exists = $this->db->fetch();
        $this->db->free();
        if (!$exists) {
            $label = '#record.field_' . $name;
            $this->createFieldError('error_record_not_found', $name, $label);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
    }

    /**
     * Bring the positions of ONE level (siblings under $parentId) to 1..n, if
     * they are not already.
     *
     * All position calculations (calculatePosition(), shiftPositions*(),
     * getPreviousId()) require a gapless count starting at 1. Legacy data,
     * direct database intervention or records created without a `position`
     * field, however, carry duplicate assignments, gaps or 0 - there every
     * re-sorting produced new duplicate assignments (e.g. 0,0,0,1). The previous
     * order is kept (tie: by id). Records marked as deleted
     * count (they occupy positions), working copies do not.
     *
     * @param int $parentId   id of the parent record or 0
     * @param int $areaId     content area
     * @param int $excludeId  record that is not counted (0: none)
     * @return bool           true if positions were rewritten
     */
    private function normalizeLevel(int $parentId, int $areaId = 0, int $excludeId = 0): bool
    {
        $idField   = $this->tableName . '_id';
        $bindTypes = '';
        $values    = [];
        $where = $this->parentCondition($parentId, $areaId, $bindTypes, $values);
        if ($excludeId > 0) {
            $where     .= " AND `{$idField}` != ?";
            $bindTypes .= 'i';
            $values[]   = $excludeId;
        }
        $where .= $this->treeFilter();

        $this->db->pushResult();
        $this->db->query(
            "SELECT COUNT(*) AS `n`, COUNT(DISTINCT `position`) AS `d`,"
            . " MIN(`position`) AS `mi`, MAX(`position`) AS `ma`"
            . " FROM `{$this->tableName}` WHERE " . $where,
            $bindTypes, $values
        );
        $stat = $this->db->fetch();
        $n = (int)($stat['n'] ?? 0);
        $dirty = $n > 0 && ((int)$stat['d'] !== $n || (int)$stat['mi'] !== 1 || (int)$stat['ma'] !== $n);
        if ($dirty) {
            $this->db->query(
                "UPDATE `{$this->tableName}` AS `t`"
                . " JOIN ("
                . "   SELECT `{$idField}` AS `id`,"
                . "          ROW_NUMBER() OVER (ORDER BY `position` ASC, `{$idField}` ASC) AS `pos`"
                . "   FROM `{$this->tableName}` WHERE " . $where
                . " ) AS `n` ON `n`.`id` = `t`.`{$idField}`"
                . " SET `t`.`position` = `n`.`pos`",
                $bindTypes, $values
            );
        }
        $this->db->popResult();
        return $dirty;
    }

    /**
     * Current `position` of a record from the database.
     *
     * @param int $id        `{tableName}_id`
     * @param int $fallback  return value if the record is missing
     */
    private function positionOf(int $id, int $fallback = 0): int
    {
        if ($id < 1) return $fallback;
        $this->db->pushResult();
        $this->db->query(
            "SELECT `position` FROM `{$this->tableName}` WHERE `{$this->tableName}_id` = ?",
            'i', [$id]
        );
        $row = $this->db->fetch();
        $this->db->popResult();
        return $row ? (int)$row['position'] : $fallback;
    }

    /**
     * Helper method to determine the numeric position from the previous_id.
     * Takes parent_id and area_id into account, to prevent misassignments between branches
     * and between areas.
     * Records marked as deleted still occupy positions and
     * are therefore NOT filtered out.
     *
     * @param int $parentId    id of the parent record or 0
     * @param int $previousId  id of the preceding record or 0
     * @param int $areaId      content area
     */
    private function calculatePosition(int $parentId, int $previousId, int $areaId = 0): int
    {
        // No predecessor given → first element in the branch
        if ($previousId === 0) return 1;
        // Load the predecessor's position – checking whether it really belongs to the parent
        $bindTypes = 'i';
        $values    = [$previousId];
        $sql = "SELECT `position` FROM `{$this->tableName}`"
             . " WHERE `{$this->tableName}_id` = ? AND " . $this->parentCondition($parentId, $areaId, $bindTypes, $values);
        $sql .= $this->treeFilter();
        
        $this->db->query($sql, $bindTypes, $values, true);
        $prevPos = $this->db->fetch();
        $this->db->free();
        if ($prevPos) {
            return (int)$prevPos['position'] + 1;
        }
        // Fallback: previous_id does not belong to the parent → append at the end of the branch
        $bindTypes = '';
        $values    = [];
        $sql = "SELECT MAX(`position`) AS `max_pos` FROM `{$this->tableName}`"
             . " WHERE " . $this->parentCondition($parentId, $areaId, $bindTypes, $values);
        $sql .= $this->treeFilter();
        
        $this->db->query($sql, $bindTypes, $values, true);
        $maxPos = $this->db->fetch();
        $this->db->free();
        return $maxPos && $maxPos['max_pos'] !== null ? (int)$maxPos['max_pos'] + 1 : 1;
    }

    /**
     * Shift siblings from $fromPosition by +1 (make room).
     * Records marked as deleted take part as well.
     *
     * @param int $parentId      id of the parent record or 0
     * @param int $fromPosition  target position (shifting starts here)
     * @param int $excludeId     own id (is excluded)
     * @param int $areaId        content area - on the root level the only
     *                           feature that keeps the areas apart
     */
    private function shiftPositionsDown(int $parentId, int $fromPosition, int $excludeId, int $areaId = 0): void
    {
        $bindTypes = '';
        $values    = [];
        $sql = "UPDATE `{$this->tableName}` SET `position` = `position` + 1"
             . " WHERE " . $this->parentCondition($parentId, $areaId, $bindTypes, $values)
             . " AND `position` >= ? AND `{$this->tableName}_id` != ?";
        $bindTypes .= 'ii';
        $values[]   = $fromPosition;
        $values[]   = $excludeId;
        $sql .= $this->treeFilter();
        
        $this->db->query($sql, $bindTypes, $values);
    }

    /**
     * Shift siblings after $fromPosition by -1 (close the gap).
     * Records marked as deleted take part as well.
     *
     * @param int $parentId      id of the parent record or 0
     * @param int $fromPosition  previous position (shifting starts at here+1)
     * @param int $excludeId     own id (is excluded)
     * @param int $areaId        content area (see shiftPositionsDown())
     */
    private function shiftPositionsUp(int $parentId, int $fromPosition, int $excludeId, int $areaId = 0): void
    {
        $bindTypes = '';
        $values    = [];
        $sql = "UPDATE `{$this->tableName}` SET `position` = `position` - 1"
             . " WHERE " . $this->parentCondition($parentId, $areaId, $bindTypes, $values)
             . " AND `position` > ? AND `{$this->tableName}_id` != ?";
        $bindTypes .= 'ii';
        $values[]   = $fromPosition;
        $values[]   = $excludeId;
        $sql .= $this->treeFilter();
        
        $this->db->query($sql, $bindTypes, $values);
    }

    /**
     * Rebuild the tree of ONE content area (lft, rgt, depth, parent_ids).
     * Reads position + parent_id from the DB and calculates from them
     * the complete nested set in one pass. Only rows whose values differ
     * from the stored ones are written.
     * Records marked as deleted remain in the tree and
     * therefore also receive current lft/rgt/depth values.
     *
     * `lft` starts again at 1 in each area, `depth` at 0.
     *
     * Records whose parent_id points to a node outside the read set
     * (data error, cross-area parent_id) are treated as root nodes -
     * otherwise they would fall out of the tree and keep old lft/rgt values.
     *
     * @param int $areaId  content area; irrelevant for tables without `area_id`
     */
    private function rebuildTree(int $areaId = 0): void
    {
        if ($this->hasAreaId && $areaId < 1) return;

        // FOR UPDATE only within a running transaction
        $forUpdate = $this->db->inTransaction() ? ' FOR UPDATE' : '';

        $bindTypes  = '';
        $values     = [];
        $conditions = [];
        if ($this->hasTmp)   $conditions[] = '`tmp`=0';
        if ($this->hasAreaId) {
            $conditions[] = '`area_id` = ?';
            $bindTypes   .= 'i';
            $values[]     = $areaId;
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

        // The previous values come along, so that only changed rows are written below
        $sql = "SELECT `{$this->tableName}_id`, `parent_id`, `lft`, `rgt`, `depth`, `parent_ids`"
             . " FROM `{$this->tableName}`"
             . $where
             . " ORDER BY `parent_id` ASC, `position` ASC, `{$this->tableName}_id` ASC" . $forUpdate;
        $this->db->query($sql, $bindTypes, $values);

        $rows = $this->db->fetchAll();
        $this->db->free();
        $idField = $this->tableName . '_id';
        $tree    = [];
        $known   = [];
        foreach ($rows as $row) {
            $known[(int)$row[$idField]] = $row;
        }
        foreach ($rows as $row) {
            $pId = (int)($row['parent_id'] ?? 0);
            // Parent outside the set (other area, data error) -> root
            if ($pId > 0 && !isset($known[$pId])) $pId = 0;
            $tree[$pId][] = (int)$row[$idField];
        }
        $counter = 1;
        $updates = [];
        $traverse = function (int $nodeId, int $depth, array $parentIds) use (&$traverse, &$tree, &$counter, &$updates, &$known): void {
            $lft = $counter++;
            // For the children of this node: copy the current path and append own id
            $nextParentIds = $parentIds;
            $nextParentIds[] = $nodeId;
            if (isset($tree[$nodeId])) {
                foreach ($tree[$nodeId] as $childId) {
                    $traverse($childId, $depth + 1, $nextParentIds);
                }
            }
            $rgt = $counter++;
            // Write only if something changed - a move only shifts the nodes
            // between the old and the new place (and the rgt of their ancestors).
            // parent_ids is compared decoded: MySQL returns the JSON column
            // normalized ("[1, 2]"), MariaDB as stored.
            $old = $known[$nodeId];
            $oldParentIds = json_decode((string)($old['parent_ids'] ?? ''), true);
            if (
                (int)$old['lft'] === $lft
                && (int)$old['rgt'] === $rgt
                && (int)$old['depth'] === $depth
                && is_array($oldParentIds)
                && array_map('intval', $oldParentIds) === $parentIds
            ) {
                return;
            }
            $updates[] = [
                'id'         => $nodeId,
                'lft'        => $lft,
                'rgt'        => $rgt,
                'depth'      => $depth,
                'parent_ids' => json_encode($parentIds) // Array als JSON speichern
            ];
        };
        if (isset($tree[0])) {
            foreach ($tree[0] as $rootId) {
                // Root node: depth 0, no parent elements
                $traverse($rootId, 0, []);
            }
        }
        if (!empty($updates)) {
            // DB class allows nested transactions
            $this->db->beginTransaction();
            try {
                // Prepared statement instead of a huge SQL string
                $sql = "UPDATE `{$this->tableName}`"
                    . " SET `lft` = ?, `rgt` = ?, `depth` = ?, `parent_ids` = ?"
                    . " WHERE `{$this->tableName}_id` = ?";
                foreach ($updates as $u) {
                    $this->db->query($sql, 'iiisi', [$u['lft'], $u['rgt'], $u['depth'], $u['parent_ids'], $u['id']], true);
                }
                $this->db->commit();
            } catch (\Exception $e) {
                $this->db->rollback();
                throw $e;
            }
        }
    }

    /**
     * Public access to rebuildTree() for external calls (e.g. migration scripts).
     * Opens its own transaction.
     *
     * @param int $areaId  content area; irrelevant for tables without `area_id`
     */
    public function externalRebuildTree(int $areaId = 0): void
    {
        if (!$this->isTreeArea($areaId)) return;
        $this->db->beginTransaction();
        try {
            $this->rebuildTree($areaId);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Reset the tree fields of a content area.
     *
     * Called when the input field `parent_id` is removed from an area
     * (Field::hookOnDeleted()): the area is flat afterwards, so its records must be flat
     * too - otherwise parent_id references and lft/rgt boundaries would remain,
     * which nobody maintains any more and by which filtering would continue.
     *
     * `position` is kept, but renumbered in the previous DISPLAY ORDER of the
     * tree (lft): the order the editor last saw survives the conversion this way. An area
     * without a `position` field simply does not use the values.
     *
     * @param int $areaId  content area
     */
    public function resetTreeFields(int $areaId): void
    {
        if (!$this->hasParentId || !$this->hasAreaId || $areaId < 1) return;

        $tableName = $this->tableName;
        $idField   = $tableName . '_id';

        $tmpFilter = $this->hasTmp ? " AND `tmp` = 0" : '';

        $this->db->pushResult();
        // 1. reassign position in the previous tree order (1..n)
        $sql = "UPDATE `{$tableName}` AS `t`"
             . " JOIN ("
             . "   SELECT `{$idField}` AS `id`,"
             . "          ROW_NUMBER() OVER (ORDER BY `lft` ASC, `{$idField}` ASC) AS `pos`"
             . "   FROM `{$tableName}` WHERE `area_id` = ?" . $tmpFilter
             . " ) AS `n` ON `n`.`id` = `t`.`{$idField}`"
             . " SET `t`.`position` = `n`.`pos`";
        $this->db->query($sql, 'i', [$areaId]);

        // 2. neutralize the tree fields
        $sql = "UPDATE `{$tableName}` SET `parent_id` = NULL, `lft` = 0, `rgt` = 0,"
             . " `depth` = 0, `parent_ids` = '[]' WHERE `area_id` = ?";
        $this->db->query($sql, 'i', [$areaId]);
        $this->db->popResult();

        $this->clearTreeAreaCache();
    }

    /**
     * Build the tree of a content area for the first time.
     *
     * Counterpart to resetTreeFields(): called when an input field `parent_id`
     * was created in the area (Field::hookOnSaved()). All existing records
     * are initially root nodes; their `position` is made unique in the previous sort order
     * (otherwise the order of the roots would be random), afterwards
     * lft/rgt/depth/parent_ids are calculated for the first time.
     *
     * @param int $areaId  content area
     */
    public function initTreeArea(int $areaId): void
    {
        if (!$this->hasParentId || !$this->hasAreaId || $areaId < 1) return;

        $this->clearTreeAreaCache();
        $this->normalizePositions($areaId);
        $this->rebuildTree($areaId);
    }

    /**
     * Reassign `position` of a content area without gaps: per parent node 1..n,
     * in the previous order.
     *
     * The order is kept in the process - only gaps and
     * duplicate assignments disappear. This is needed when an area newly gets a tree
     * (initTreeArea()) and when legacy data is converted once to the
     * per-area counting (Core/cron/rebuild_trees.php).
     *
     * @param int $areaId  content area
     */
    public function normalizePositions(int $areaId): void
    {
        if (!$this->hasAreaId || $areaId < 1) return;

        $tableName = $this->tableName;
        $idField   = $tableName . '_id';

        $this->db->pushResult();
        $sql = "UPDATE `{$tableName}` AS `t`"
             . " JOIN ("
             . "   SELECT `{$idField}` AS `id`,"
             . "          ROW_NUMBER() OVER (PARTITION BY `parent_id`"
             . "                             ORDER BY `position` ASC, `{$idField}` ASC) AS `pos`"
             . "   FROM `{$tableName}` WHERE `area_id` = ?" . ($this->hasTmp ? " AND `tmp` = 0" : '')
             . " ) AS `n` ON `n`.`id` = `t`.`{$idField}`"
             . " SET `t`.`position` = `n`.`pos`";
        $this->db->query($sql, 'i', [$areaId]);
        $this->db->popResult();
    }

    /**
     * Helper function: find the preceding record under the same parent
     * Records marked as deleted are skipped:
     * they remain in the tree (occupy positions), but are not selectable in the
     * select lists (previous_id) of the input masks.
     * This way previous_id is also determined correctly if records have meanwhile been
     * marked as deleted and restored.
     *
     * @param int $parentId  id of the own parent or 0 (parent_id IS NULL)
     * @param int $position  own position
     * @param int $areaId    content area - mandatory on the root level, otherwise
     *                       the predecessor could possibly come from a foreign area
     */
    public function getPreviousId(int $parentId, int $position, int $areaId = 0): int
    {
        $tableName = $this->tableName;
        $idField = $tableName . '_id';
        $bindTypes = '';
        $values    = [];
        $sql = "SELECT `$idField` FROM `$tableName`"
            ." WHERE " . $this->parentCondition($parentId, $areaId, $bindTypes, $values)
            ." AND `position` < ?";
        $bindTypes .= 'i';
        $values[]   = $position;
        $sql .= $this->treeFilter(true); // skip deleted
        $sql .= " ORDER BY `position` DESC LIMIT 1";
        $this->db->query($sql, $bindTypes, $values);
        if ($this->db->num_rows > 0) return (int) $this->db->fetchNum()[0];
        else return 0;
    }
    
    /**
     * Determine the IDs of all parent records (ancestors) of a node
     * in descending order (root -> ... -> grandfather -> father),
     * e.g. to form a path (breadcrumb) from them.
     * * Uses the nested set (lft/rgt) for maximum performance without recursion.
     * The lft/rgt range applies per content area, hence `area_id` runs along.
     * Ancestors marked as deleted remain in the tree and thus in the path
     * (otherwise the result would have gaps).
     * Returns null if no parent record exists.
     *
     * @param array $data  fields {$tableName_}id, 'lft', 'rgt', 'area_id'
     * @return array|null  array with the IDs of the ancestors or null
     */
    public function getParentIds(array $data): ?array
    {
        $tableName = $this->tableName;
        $idField = $tableName . '_id';
        $lft = (int)($data['lft'] ?? 0);
        $rgt = (int)($data['rgt'] ?? 0);
        if ($lft < 1 || $rgt <= $lft) {
            return null; // no tree (flat area)
        }
        // Find all ancestors using the nested set model
        // lft smaller than own lft AND rgt greater than own rgt
        // ORDER BY lft ASC sorts from top to bottom (root first, then parent)
        $bindTypes = 'ii';
        $values    = [$lft, $rgt];
        $sql = "SELECT `{$idField}` FROM `{$this->tableName}`"
            ." WHERE `lft` < ? AND `rgt` > ?";
        
        $sql .= $this->areaCondition((int)($data['area_id'] ?? 0), $bindTypes, $values);
        $sql .= $this->treeFilter();
        
        $sql .= " ORDER BY `lft` ASC";
        
        $this->db->query($sql, $bindTypes, $values, true);
        $ancestors = $this->db->fetchAll(); // flaches Array
        $this->db->free();
        if (empty($ancestors)) {
            return null; // no parent present (root node)
        }
        return $ancestors;
    }
}
