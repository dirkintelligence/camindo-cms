<?php
/**
 * Database maintenance: empty the trash, discard working copies.
 *
 * purgeTrash() permanently removes records that have been marked as deleted for at least a number of days
 * (default admin.trash_retention_days, default 7). This is the
 * ONLY place in the system that irrevocably deletes content - the
 * administrator always deletes only softly.
 *
 * purgeTmp() removes working copies (record.tmp = 1) that have not been touched for a number of hours
 * - left-over edits (browser closed, session expired).
 * With them the edit lock of the original is released (getLock()
 * derives it from the working copy).
 *
 * TWO ENTRY POINTS, ONE CODE
 * --------------------
 *     SchedulerRunner, type trash              scheduler task (the normal case)
 *     Modules/Tools/backend/tool_delete.php   tab Database: "older than N days"
 *     Modules/Tools/backend/tool_tmp_delete.php  tab Database: "older than N hours"
 * All call the same methods; the backend tools specify the period
 * (0 = everything), the cron job takes the setting.
 *
 * ADMINS REMAIN
 * --------------------
 * Only `record` and `area` are processed. Administrators marked as deleted
 * (admin.deleted = 1) are NEVER finally removed - neither here nor in the cron job:
 * their ID stands in created_by/changed_by/owner_id of the content and thus remains
 * resolvable.
 *
 * HOW IT WORKS
 * --------------------
 * Processing happens in batches (CHUNK_SIZE records per round), so that neither memory
 * nor run time run out of control. Each record runs INDIVIDUALLY through
 * Record::finalDelete() resp. Area::finalDelete() - only this way do the hooks run that
 * remove the media files from var/deleted.
 *
 * ORDER
 * --------------------
 * First the records (`record`), then the content areas (`area`). An area that
 * is due for deletion removes its remaining records in its own
 * hookOnDelete() anyway; the separate round before it keeps the batches small and
 * also catches records whose area still exists.
 *
 * The nested-set tree is recalculated once per finalDelete() call. That is the
 * price of the individual processing and the reason why the tool runs at night.
 */
namespace Camindo\Core\Modules\Tools;

use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\Dataset;
use Camindo\Core\Modules\Record\Record;
use Camindo\Core\Modules\Area\Area;

class ToolDatabase {

    /** Records per round - small enough for time limit and memory */
    private const CHUNK_SIZE = 60;

    /** Seconds a round may take at most (file deletions can take a while) */
    private const CHUNK_TIME_LIMIT = 180;

    public function __construct(
        private Config $config,
        private Database $db,
        protected System $system,
        protected Cms $cms,
        protected Record $record,
        protected Area $area
    )
    {
    }

    /**
     * Empty the trash.
     *
     * @param int|null $days  minimum age of the marking in days; null: setting
     *                        admin.trash_retention_days; 0: everything that
     *                        is marked as deleted
     * @return array          ['record' => int, 'area' => int, 'errors' => string[]]
     *                        number of permanently deleted records per table
     */
    public function purgeTrash(?int $days = null): array
    {
        if ($days === null) $days = $this->trashRetentionDays();
        if ($days < 0) $days = 0;

        // Cut-off date in UTC - changed_at is also stored in UTC
        $expirationDate = gmdate('Y-m-d H:i:s', time() - 86400 * $days);

        $result = ['record' => 0, 'area' => 0, 'errors' => []];

        // First the content, then its areas
        $result['record'] = $this->purgeTable($this->record, $expirationDate, $result['errors']);
        $result['area']   = $this->purgeTable($this->area,   $expirationDate, $result['errors']);

        return $result;
    }

    /**
     * Empty the trash of ONE frontend user immediately (tab "Trash of a user",
     * additional right trash_user): their profile and all records with record.user_id =
     * profile, as far as they lie in the trash - permanently, including files. Open
     * orders of the deletion policies (audit log after the period) remain in place.
     *
     * @param int $profileId  record_id of the profile (area of type 'profile')
     * @return array|null     ['record' => int, 'errors' => string[]], null = no profile
     */
    public function purgeUserTrash(int $profileId): ?array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT 1 FROM `record` r JOIN `area` a ON a.`area_id` = r.`area_id`"
                . " WHERE r.`record_id` = ? AND r.`tmp` = 0 AND a.`type` = 'profile'", 'i', [$profileId]);
            if (!$this->db->fetchNum()) return null;
            // Leaves first (rgt ascending), as in purgeTable()
            $this->db->query("SELECT `record_id` FROM `record` WHERE `deleted` = 1 AND `tmp` = 0"
                . " AND (`record_id` = ? OR `user_id` = ?) ORDER BY `record_id` = ?, `rgt` ASC", 'iii', [$profileId, $profileId, $profileId]);
            $ids = array_map('intval', $this->db->fetchColumn());
        }
        finally {
            $this->db->popResult();
        }
        $result = ['record' => 0, 'errors' => []];
        foreach ($ids as $id) {
            $this->cms->errorsReset();
            if ($this->record->finalDelete($id)) {
                $result['record']++;
                continue;
            }
            $message = "record {$id}: " . $this->cms->errorMsg(' | ');
            $result['errors'][] = $message;
            $this->system->log('trash', $message);
        }
        return $result;
    }

    /**
     * Retention period of the trash (admin.trash_retention_days, default 7) -
     * default of the cron job and of the form in the Database tab.
     */
    public function trashRetentionDays(): int
    {
        return max(0, (int)($this->config->get('admin.trash_retention_days') ?? 7));
    }

    /**
     * Discard working copies that have not been touched for at least $hours hours
     * (record.tmp = 1, changed_at older than the cut-off date).
     *
     * Like TemporaryTrait::cancel(), only without binding to the session admin: the
     * uploaded files of the working copy (deleteRecordFiles()) and the row
     * itself; record_cache and data_rel fall away via FK cascade. Working copies of
     * firmly linked contents (link) are themselves tmp rows and come up with the same
     * cut-off date.
     *
     * @param int $hours  minimum age in hours; 0: all working copies
     * @return array      ['record' => int, 'errors' => string[]]
     */
    public function purgeTmp(int $hours): array
    {
        if ($hours < 0) $hours = 0;
        $expirationDate = gmdate('Y-m-d H:i:s', time() - 3600 * $hours);
        $result = ['record' => 0, 'errors' => []];
        $failedIds = [];

        do {
            set_time_limit(self::CHUNK_TIME_LIMIT);

            $sql = "SELECT `record_id` FROM `record` WHERE `tmp` = 1 AND `changed_at` <= ?";
            if ($failedIds) {
                $sql .= " AND `record_id` NOT IN (" . implode(',', $failedIds) . ")";
            }
            $sql .= " ORDER BY `record_id` ASC LIMIT " . self::CHUNK_SIZE;

            $this->db->pushResult();
            $this->db->query($sql, 's', [$expirationDate]);
            $ids = $this->db->fetchAll();
            $this->db->popResult();

            $found = count($ids);
            if ($found === 0) break;

            foreach ($ids as $id) {
                $id = (int)$id;
                try {
                    $this->record->deleteRecordFiles($id);
                    $this->db->pushResult();
                    $this->db->query("DELETE FROM `record` WHERE `record_id` = ? AND `tmp` = 1", 'i', [$id]);
                    $affected = (int)$this->db->affected_rows;
                    $this->db->popResult();
                    if ($affected > 0) {
                        $result['record']++;
                        continue;
                    }
                    $message = "record {$id}: not deleted";
                }
                catch (\Throwable $e) {
                    $message = "record {$id}: " . $e->getMessage();
                }
                $result['errors'][] = $message;
                $failedIds[] = $id;
                $this->system->log('trash', $message);
            }
        } while ($found === self::CHUNK_SIZE);

        return $result;
    }

    /**
     * Process one table.
     *
     * Fetches the IDs of expired trash entries in batches and sends each one individually
     * through finalDelete(). If a record fails, this is logged and processing continues with the
     * next one - a defective record should not stop the nightly run. That is exactly why each
     * record gets its own transaction (finalDelete() opens it itself).
     *
     *
     * @param Dataset $dataset         Record or Area
     * @param string  $expirationDate  cut-off date 'Y-m-d H:i:s' in UTC
     * @param array   $errors          collection point for error messages (by reference)
     * @return int                     number of permanently deleted records
     */
    private function purgeTable(Dataset $dataset, string $expirationDate, array &$errors): int
    {
        $tableName = $dataset->getTableName();
        $idField   = $tableName . '_id';
        $hasTree   = $dataset->hasTree();
        $hasTmp    = isset($dataset->getSchema()['tmp']);
        $deleted   = 0;

        // IDs that could not be deleted: otherwise they would be in the batch again in every round
        // and create an endless loop.
        $failedIds = [];

        do {
            set_time_limit(self::CHUNK_TIME_LIMIT);

            // Fetch expired trash entries.
            // For trees, leaves first (rgt ascending): finalDelete() of a parent would
            // otherwise take its children along via ON DELETE CASCADE past their hooks.
            // Working copies never come up on their own - they hang on their original and
            // are deleted with it by finalDelete().
            $sql = "SELECT `{$idField}` FROM `{$tableName}`"
                 . " WHERE `deleted` = 1 AND `changed_at` <= ?";
            if ($hasTmp) $sql .= " AND `tmp` = 0";
            if ($failedIds) {
                // IDs here are always integers from the database - no binding needed
                $sql .= " AND `{$idField}` NOT IN (" . implode(',', $failedIds) . ")";
            }
            if ($hasTree) $sql .= " ORDER BY `rgt` ASC";
            $sql .= " LIMIT " . self::CHUNK_SIZE;

            $this->db->pushResult();
            $this->db->query($sql, 's', [$expirationDate]);
            $ids = $this->db->fetchAll(); // flat array
            $this->db->popResult();

            $found = count($ids);
            if ($found === 0) break;

            foreach ($ids as $id) {
                $id = (int)$id;
                // Each record individually - so that its hooks run
                // (Record::hookOnDelete() removes the media files)
                $this->cms->errorsReset();
                if ($dataset->finalDelete($id)) {
                    $deleted++;
                    continue;
                }
                // Log the failed record and skip it - a defective entry
                // should not stop the nightly run
                $message = "{$tableName} {$id}: " . $this->cms->errorMsg(' | ');
                $errors[]    = $message;
                $failedIds[] = $id;
                $this->system->log('trash', $message);
            }

        } while ($found === self::CHUNK_SIZE);

        return $deleted;
    }
}
