<?php
/**
 * Trash: find and restore deleted records and areas.
 * Instance name in the code: $trash.
 *
 * The module has no table of its own. "In the trash" is everything with `deleted` = 1;
 * who deleted it and when is stored in `changed_by` / `changed_at` - Dataset::delete()
 * gives all records of ONE deletion operation exactly the same timestamp (see
 * Dataset::newTimestamp()), and Dataset::restore() later recognizes by exactly this what
 * belongs together.
 *
 * WHAT APPEARS IN THE LIST: ONLY THE ROOT OF AN OPERATION
 * --------------------
 * Records
 *     `deleted` = 1, no working copy, the area is NOT deleted and the
 *     parent record (tree) is not either. Children that were deleted with their branch
 *     therefore do not appear individually - they come back with the branch when it is restored
 *     (Record::restore($id, withChilds: true) takes exactly the children with the same
 *     deletion time). A child that was already deleted individually BEFORE only reappears
 *     when its parent is restored - otherwise it would stand under a deleted
 *     parent after restoring.
 *     Likewise, permanently linked content (field type 'link') of a deleted
 *     record is missing - LinkTrait::cascadeDependents() brings it back with it.
 *     Without 'all', only what the administrator deleted themselves (`changed_by`), and only from
 *     areas they may read (Access::areaRead()).
 * Areas (superusers only)
 *     `deleted` = 1 and the parent area not deleted. When restored, an area takes its
 *     subareas and all records with the same deletion time along (Area::hookOnRestore()).
 *     Records of a deleted area therefore never appear individually.
 * Input fields (superusers only - the module Area is theirs)
 *     `deleted` = 1, the area not deleted, and not deleted along with something else
 *     of the same operation: thumbnail fields of a deleted upload field, relation
 *     fields of a deleted target area. Of a relation and its counter field (deleted
 *     together, see Field::hookOnSoftDelete()) only the one with the smaller id appears.
 *
 * Most recent deletion first. Permanently deleted after admin.trash_retention_days
 * (scheduler task trash, ToolDatabase::purgeTrash()) - expiresAt() gives the day.
 *
 * RESTORING
 * --------------------
 * restore() first checks whether the entry would actually be in the list for this administrator
 * (restorable()) - the id comes from the request. Then the same path as
 * for deleting runs: Record::restore() or Area::restore() with children, large operations
 * from admin.mass_delete_threshold as a background process (MassDeletion). The
 * group permissions are checked by Dataset::restoreOneCore() per record (delete permission).
 *
 * CONFLICTS ON RESTORING
 * --------------------
 * The uniqueness check of saving only sees live records - a value of a record in
 * the trash (slug, unique fields such as a sign-in name) can therefore be taken by
 * another record in the meantime. Before anything is restored, restore()
 * checks all records of the operation (Record::restoreConflicts()):
 *     a single record   'edit' - the caller opens its input mask instead
 *                       (RecordService::checkOut(fromTrash: true)); it shows the
 *                       messages like a failed save, saving restores the record
 *                       (Record::saveAndRestore()), cancelling leaves it in the trash
 *     several records   'conflicts' - a branch or an area: nothing is restored until
 *                       the conflicts are removed; the list is in $conflicts
 *
 * @see Camindo\Core\Modules\Trash\TrashBackend  list, widget
 * @see Camindo\Core\Dataset                     delete() / restore()
 * @see Camindo\Core\Modules\Area\Area           hookOnSoftDelete() / hookOnRestore()
 * @see Camindo\Core\MassDeletion                background process
 */
namespace Camindo\Core\Modules\Trash;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\MassDeletion;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Area\Field;
use Camindo\Core\Modules\Record\Record;

class Trash {

    /** At most this many entries per list */
    public const MAX_ENTRIES = 200;

    // Conflicts of the last restore() ('edit' / 'conflicts'), Record::restoreConflicts()
    public array $conflicts = [];

    public function __construct(
        private Backend $backend,
        private Cms $cms,
        private Config $config,
        private Database $db,
        private Session $session,
        private System $system,
        private MassDeletion $massDeletion,
        private Record $record,
        private Area $area,
        private Field $field,
    )
    {}

    // ====================
    // Lists

    /**
     * Deleted records (only roots of the deletion operations, see class comment).
     *
     * @param bool $all  true: all administrators (superusers only, otherwise ignored)
     * @param int  $limit
     * @return array  rows: record_id, area_id, instance_id, owner_id, changed_by,
     *                changed_at (UTC), children (children deleted along)
     */
    public function records(bool $all = false, int $limit = self::MAX_ENTRIES): array
    {
        [$where, $types, $values] = $this->recordConditions($all);
        $sql = "SELECT `r`.`record_id`, `r`.`area_id`, `r`.`instance_id`, `r`.`owner_id`,"
             . " `r`.`changed_by`, `r`.`changed_at`,"
             // Children deleted along: subtree (lft/rgt per area) with the same timestamp
             . " (SELECT COUNT(*) FROM `record` `c` WHERE `r`.`lft` > 0"
             . "     AND `c`.`area_id` = `r`.`area_id` AND `c`.`lft` > `r`.`lft` AND `c`.`rgt` < `r`.`rgt`"
             . "     AND `c`.`tmp` = 0 AND `c`.`deleted` = 1 AND `c`.`changed_at` = `r`.`changed_at`) AS `children`"
             . " FROM `record` `r`"
             . " JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id` AND `a`.`deleted` = 0"
             . " LEFT JOIN `record` `p` ON `p`.`record_id` = `r`.`parent_id`"
             . " WHERE " . $where
             . " ORDER BY `r`.`changed_at` DESC, `r`.`record_id` DESC"
             . " LIMIT " . max(1, $limit);
        $this->db->pushResult();
        $this->db->query($sql, $types, $values);
        $rows = [];
        while ($row = $this->db->fetch()) $rows[] = $row;
        $this->db->popResult();
        return $rows;
    }

    /**
     * Deleted areas (only roots) - only for superusers, otherwise empty.
     *
     * @return array  rows: area_id, instance_id, type, name, label, icon, changed_by,
     *                changed_at (UTC), subareas, records (deleted along)
     */
    public function areas(int $limit = self::MAX_ENTRIES): array
    {
        if (!$this->backend->isSuperuser()) return [];
        $sql = "SELECT `a`.`area_id`, `a`.`instance_id`, `a`.`type`, `a`.`name`, `a`.`label`, `a`.`icon`,"
             . " `a`.`changed_by`, `a`.`changed_at`,"
             . " (SELECT COUNT(*) FROM `area` `s` WHERE `a`.`lft` > 0 AND `s`.`lft` > `a`.`lft` AND `s`.`rgt` < `a`.`rgt`"
             . "     AND `s`.`deleted` = 1 AND `s`.`changed_at` = `a`.`changed_at`) AS `subareas`,"
             . " (SELECT COUNT(*) FROM `record` `r` JOIN `area` `s` ON `s`.`area_id` = `r`.`area_id`"
             . "     WHERE (`s`.`area_id` = `a`.`area_id` OR (`a`.`lft` > 0 AND `s`.`lft` > `a`.`lft` AND `s`.`rgt` < `a`.`rgt`))"
             . "     AND `s`.`deleted` = 1 AND `s`.`changed_at` = `a`.`changed_at`"
             . "     AND `r`.`tmp` = 0 AND `r`.`deleted` = 1 AND `r`.`changed_at` = `a`.`changed_at`) AS `records`"
             . " FROM `area` `a`"
             . " LEFT JOIN `area` `p` ON `p`.`area_id` = `a`.`parent_id`"
             . " WHERE `a`.`deleted` = 1 AND (`p`.`area_id` IS NULL OR `p`.`deleted` = 0)"
             . " ORDER BY `a`.`changed_at` DESC, `a`.`area_id` DESC"
             . " LIMIT " . max(1, $limit);
        $this->db->pushResult();
        $this->db->query($sql);
        $rows = [];
        while ($row = $this->db->fetch()) $rows[] = $row;
        $this->db->popResult();
        return $rows;
    }

    /**
     * Deleted input fields (only roots, see class comment) - only for superusers,
     * otherwise empty.
     *
     * @return array  rows: field_id, area_id, name, label, type, changed_by,
     *                changed_at (UTC), counter_field (name of the counter field
     *                deleted along, '' = none), thumbnails (deleted along)
     */
    public function fields(int $limit = self::MAX_ENTRIES): array
    {
        if (!$this->backend->isSuperuser()) return [];
        $sql = "SELECT `f`.`field_id`, `f`.`area_id`, `f`.`name`, `f`.`label`, `f`.`type`,"
             . " `f`.`changed_by`, `f`.`changed_at`,"
             . " COALESCE((SELECT `g`.`name` FROM `field` `g` WHERE `g`.`field_id` = `f`.`rel_field_id`"
             . "     AND `g`.`deleted` = 1 AND `g`.`changed_at` = `f`.`changed_at`), '') AS `counter_field`,"
             . " (SELECT COUNT(*) FROM `field` `t` WHERE `t`.`parent_field_id` = `f`.`field_id`"
             . "     AND `t`.`deleted` = 1 AND `t`.`changed_at` = `f`.`changed_at`) AS `thumbnails`"
             . " FROM `field` `f`"
             . " WHERE " . $this->fieldConditions()
             . " ORDER BY `f`.`changed_at` DESC, `f`.`field_id` DESC"
             . " LIMIT " . max(1, $limit);
        $this->db->pushResult();
        $this->db->query($sql);
        $rows = [];
        while ($row = $this->db->fetch()) $rows[] = $row;
        $this->db->popResult();
        return $rows;
    }

    /**
     * Deleted records whose default field (`data_dft`) matches a LIKE pattern in the
     * preferred language version of the administrator (Cms::languageExpr(), as in the
     * record lists) - for the quick search (StartQuickSearch). Same roots as records():
     * superusers see the records of all administrators, everyone else only their own.
     *
     * @param string $like        LIKE pattern, already escaped (e.g. 'abc%' or '%abc%')
     * @param int    $limit
     * @param int[]  $excludeIds  record_ids that are already in the result
     * @return array  rows: record_id, area_id, instance_id, changed_by, title
     */
    public function searchRecords(string $like, int $limit, array $excludeIds = []): array
    {
        [$where, $types, $values] = $this->recordConditions(true);
        $excludeIds = array_values(array_filter(array_map('intval', $excludeIds), fn(int $id): bool => $id > 0));
        if ($excludeIds) $where .= " AND `r`.`record_id` NOT IN (" . implode(',', $excludeIds) . ")";
        $sql = "SELECT `r`.`record_id`, `r`.`area_id`, `r`.`instance_id`, `r`.`changed_by`, `d`.`data` AS `title`"
             . " FROM `record` `r`"
             . " JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id` AND `a`.`deleted` = 0"
             . " JOIN `data_dft` `d` ON `d`.`record_id` = `r`.`record_id`"
             . "     AND `d`.`language_id` = " . $this->cms->languageExpr('r') . " AND `d`.`data` LIKE ?"
             . " LEFT JOIN `record` `p` ON `p`.`record_id` = `r`.`parent_id`"
             . " WHERE " . $where
             . " ORDER BY `title`, `r`.`record_id`"
             . " LIMIT " . max(1, $limit);
        $this->db->pushResult();
        $this->db->query($sql, 's' . $types, array_merge([$like], $values));
        $rows = [];
        while ($row = $this->db->fetch()) $rows[] = $row;
        $this->db->popResult();
        return $rows;
    }

    /**
     * Number of entries for the widget: own records plus - for superusers -
     * deleted areas and input fields. Roots are counted, i.e. what the list shows.
     *
     * @return array  ['records' => int, 'areas' => int, 'fields' => int]
     */
    public function counts(): array
    {
        [$where, $types, $values] = $this->recordConditions(false);
        $this->db->pushResult();
        $this->db->query(
            "SELECT COUNT(*) FROM `record` `r`"
            . " JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id` AND `a`.`deleted` = 0"
            . " LEFT JOIN `record` `p` ON `p`.`record_id` = `r`.`parent_id`"
            . " WHERE " . $where,
            $types, $values
        );
        $records = (int)($this->db->fetchNum()[0] ?? 0);
        $areas = 0;
        $fields = 0;
        if ($this->backend->isSuperuser()) {
            $this->db->query(
                "SELECT COUNT(*) FROM `area` `a` LEFT JOIN `area` `p` ON `p`.`area_id` = `a`.`parent_id`"
                . " WHERE `a`.`deleted` = 1 AND (`p`.`area_id` IS NULL OR `p`.`deleted` = 0)"
            );
            $areas = (int)($this->db->fetchNum()[0] ?? 0);
            $this->db->query("SELECT COUNT(*) FROM `field` `f` WHERE " . $this->fieldConditions());
            $fields = (int)($this->db->fetchNum()[0] ?? 0);
        }
        $this->db->popResult();
        return ['records' => $records, 'areas' => $areas, 'fields' => $fields];
    }

    /**
     * Day on which the trash cron job permanently deletes an entry - in the
     * time zone of the administrator, 'Y-m-d H:i:s'. The cron job runs at night; meant is
     * "from this point on at the next run".
     *
     * @param string $changedAt  `changed_at` (UTC)
     */
    public function expiresAt(string $changedAt): string
    {
        $days = max(0, (int)($this->config->get('admin.trash_retention_days') ?? 7));
        try {
            $date = new \DateTimeImmutable($changedAt, new \DateTimeZone('UTC'));
        }
        catch (\Exception $e) {
            return '';
        }
        return $date->modify("+{$days} days")
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
    }

    /**
     * `changed_at` (UTC) in the time zone of the administrator, 'Y-m-d H:i:s'.
     */
    public function localTime(string $changedAt): string
    {
        try {
            return (new \DateTimeImmutable($changedAt, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s');
        }
        catch (\Exception $e) {
            return $changedAt;
        }
    }

    // ====================
    // Restoring

    /**
     * Is the entry in the list for the logged-in administrator? Only then may it
     * be restored via the module.
     *
     * @param string $table  'record' | 'area' | 'field'
     */
    public function restorable(string $table, int $id): bool
    {
        if ($id < 1) return false;
        if ($table === 'field') {
            if (!$this->backend->isSuperuser()) return false;
            $this->db->pushResult();
            $this->db->query(
                "SELECT 1 FROM `field` `f` WHERE `f`.`field_id` = ? AND " . $this->fieldConditions(),
                'i', [$id]
            );
            $found = $this->db->num_rows > 0;
            $this->db->popResult();
            return $found;
        }
        if ($table === 'area') {
            if (!$this->backend->isSuperuser()) return false;
            $this->db->pushResult();
            $this->db->query(
                "SELECT 1 FROM `area` `a` LEFT JOIN `area` `p` ON `p`.`area_id` = `a`.`parent_id`"
                . " WHERE `a`.`area_id` = ? AND `a`.`deleted` = 1 AND (`p`.`area_id` IS NULL OR `p`.`deleted` = 0)",
                'i', [$id]
            );
            $found = $this->db->num_rows > 0;
            $this->db->popResult();
            return $found;
        }
        if ($table !== 'record') return false;

        // Superusers may also restore entries of other administrators (list "all")
        [$where, $types, $values] = $this->recordConditions($this->backend->isSuperuser());
        $this->db->pushResult();
        $this->db->query(
            "SELECT 1 FROM `record` `r`"
            . " JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id` AND `a`.`deleted` = 0"
            . " LEFT JOIN `record` `p` ON `p`.`record_id` = `r`.`parent_id`"
            . " WHERE " . $where . " AND `r`.`record_id` = ?",
            $types . 'i', array_merge($values, [$id])
        );
        $found = $this->db->num_rows > 0;
        $this->db->popResult();
        return $found;
    }

    /**
     * Restore an entry together with its branch or its records.
     *
     * @param string $table  'record' | 'area' | 'field'
     * @return string        'restored' | 'background' | 'failed' (message in Cms::errors)
     *                       | 'denied' (not in the list of the administrator)
     *                       | 'edit' (single record with conflicts: open its input mask)
     *                       | 'conflicts' (several records: refused, see $conflicts)
     *                       - for 'edit' and 'conflicts' the messages are also in Cms::errors
     */
    public function restore(string $table, int $id): string
    {
        $this->conflicts = [];
        if (!$this->restorable($table, $id)) return 'denied';

        // values taken in the meantime (see class comment)
        if ($table !== 'field') {
            $recordIds = ($table === 'record') ? $this->record->restoreIds($id) : $this->areaRecordIds($id);
            $this->conflicts = $this->record->restoreConflicts($recordIds);
            if (!empty($this->conflicts)) {
                foreach ($this->conflicts as $conflict) {
                    $this->cms->error($this->conflictText($conflict));
                }
                return ($table === 'record' && count($recordIds) === 1) ? 'edit' : 'conflicts';
            }
        }

        // Input field: with its counter and thumbnail fields - never many rows
        if ($table === 'field') {
            return $this->field->restore($id) ? 'restored' : 'failed';
        }

        // Large operations in the background - the same path as when deleting
        $count = $this->massDeletion->affectedCount($table, $id, true, 'restore');
        if ($this->massDeletion->needsBackground($count)) {
            $error = '';
            if ($this->massDeletion->start($table, $id, 'restore', true, $error)) {
                return 'background';
            }
            // Start failed -> continue in the request
            $this->system->log('massdelete-errors', "restore {$table} {$id}: {$error}");
        }

        $dataset = ($table === 'area') ? $this->area : $this->record;
        return $dataset->restore($id, true) ? 'restored' : 'failed';
    }

    /**
     * Records that restoring the area $areaId brings back: those of the areas of the
     * same deletion operation (the area and its subareas, same changed_at - see
     * Area::hookOnRestore()) with exactly this changed_at.
     *
     * @return int[]
     */
    private function areaRecordIds(int $areaId): array
    {
        $this->db->query("SELECT `changed_at` FROM `area` WHERE `area_id` = ? AND `deleted` = 1", 'i', [$areaId]);
        $timestamp = (string)($this->db->fetchNum()[0] ?? '');
        if ($timestamp === '') return [];
        $this->db->query(
            "SELECT `r`.`record_id` FROM `record` `r` JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id`"
            . " WHERE `a`.`deleted` = 1 AND `a`.`changed_at` = ? AND `r`.`deleted` = 1 AND `r`.`tmp` = 0"
            . " AND `r`.`changed_at` = ? ORDER BY `r`.`record_id`",
            'ss', [$timestamp, $timestamp]
        );
        return array_map('intval', $this->db->fetchAll());
    }

    /**
     * One conflict as text (HTML): "Title (id) - field [language]: "value" - message".
     */
    public function conflictText(array $conflict): string
    {
        $title = (string)($conflict['title'] ?? '');
        $record = ($title !== '' ? htmlspecialchars($title) . ' ' : '') . '(' . (int)$conflict['record_id'] . ')';
        $field = htmlspecialchars((string)$conflict['label']);
        $languageId = (int)($conflict['language_id'] ?? 0);
        if ($languageId > 0) {
            $this->db->query("SELECT `code` FROM `language` WHERE `language_id` = ?", 'i', [$languageId]);
            $code = (string)($this->db->fetchNum()[0] ?? '');
            if ($code !== '') $field .= ' [' . htmlspecialchars($code) . ']';
        }
        $value = (string)($conflict['value'] ?? '');
        return strtr($this->cms->snippet('trash.error_restore_conflict'), [
            '{record}'  => $record,
            '{field}'   => $field,
            '{value}'   => htmlspecialchars($value),
            '{message}' => (string)$conflict['message'],
        ]);
    }

    // ====================

    /**
     * Field types of permanently linked content (Cms::$dependentTypes) as an SQL list.
     */
    private function dependentTypeList(): string
    {
        $types = array_map(fn($t) => preg_replace('/[^a-z0-9_]/', '', (string)$t), $this->cms->dependentTypes);
        return $types ? "'" . implode("','", $types) . "'" : "''";
    }

    /**
     * WHERE condition of the field list (alias f = field): roots of the deletion
     * operations whose area is not deleted (see class comment).
     */
    private function fieldConditions(): string
    {
        return "`f`.`deleted` = 1"
            . " AND EXISTS (SELECT 1 FROM `area` `a` WHERE `a`.`area_id` = `f`.`area_id` AND `a`.`deleted` = 0)"
            // thumbnail field of an upload field deleted with it
            . " AND NOT EXISTS (SELECT 1 FROM `field` `pf` WHERE `pf`.`field_id` = `f`.`parent_field_id`"
            . "     AND `pf`.`deleted` = 1 AND `pf`.`changed_at` = `f`.`changed_at`)"
            // relation field of a target area deleted with it
            . " AND NOT EXISTS (SELECT 1 FROM `area` `ra` WHERE `ra`.`area_id` = `f`.`rel_area_id`"
            . "     AND `ra`.`deleted` = 1 AND `ra`.`changed_at` = `f`.`changed_at`)"
            // relation pair: the counter field with the smaller id stands for both
            . " AND NOT EXISTS (SELECT 1 FROM `field` `cf`"
            . "     JOIN `area` `ca` ON `ca`.`area_id` = `cf`.`area_id` AND `ca`.`deleted` = 0"
            . "     WHERE `cf`.`field_id` = `f`.`rel_field_id` AND `cf`.`field_id` < `f`.`field_id`"
            . "     AND `cf`.`deleted` = 1 AND `cf`.`changed_at` = `f`.`changed_at`)";
    }

    /**
     * WHERE condition of the record list (aliases r = record, a = area, p = parent).
     *
     * @param bool $all  true: all administrators - only effective for superusers
     * @return array     [sql, bindTypes, values]
     */
    private function recordConditions(bool $all): array
    {
        $where = "`r`.`tmp` = 0 AND `r`.`deleted` = 1"
               . " AND (`p`.`record_id` IS NULL OR `p`.`deleted` = 0)"
               // Permanently linked content (link field) of a deleted owner: comes
               // back with it (LinkTrait::cascadeDependents()), does not stand alone
               . " AND NOT EXISTS (SELECT 1 FROM `data_rel` `d`"
               . "     JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id` AND `f`.`type` IN (" . $this->dependentTypeList() . ")"
               . "     JOIN `record` `o` ON `o`.`record_id` = `d`.`src_id` AND `o`.`tmp` = 0 AND `o`.`deleted` = 1"
               . "     WHERE `d`.`dest_id` = `r`.`record_id`)";
        $types = '';
        $values = [];

        if (!$all || !$this->backend->isSuperuser()) {
            $where .= " AND `r`.`changed_by` = ?";
            $types .= 'i';
            $values[] = (int)$this->session->get('admin_id');
        }

        // Only readable areas (null = no restriction: superuser, not loaded).
        // Without a single readable area the list stays empty.
        $areaIds = $this->backend->access()->areaFilter();
        if ($areaIds !== null) {
            $areaIds = array_values(array_filter(array_map('intval', $areaIds), fn(int $id): bool => $id > 0));
            $where .= $areaIds
                ? " AND `r`.`area_id` IN (" . implode(',', $areaIds) . ")"
                : " AND 0";
        }
        return [$where, $types, $values];
    }
}
