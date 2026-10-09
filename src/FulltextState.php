<?php
/**
 * Status of the full text per area: "stale" marker and lock on the rebuild.
 *
 * STALE INSTEAD OF REBUILDING AT ONCE
 * --------------------
 * Structural changes after which the full text of an area no longer matches the
 * source fields (sources of a full-text field changed, new full-text field,
 * source field deleted or converted, other folding rules of a language ...)
 * do not rebuild it at once for all records - that would tie up the request and,
 * for large areas, the operation. They set `area`.`fulltext_stale_at`
 * (markStale()). The detail view and the field list of the area then show the
 * note with "Update now" (background process, CLI command `fulltext`);
 * otherwise the scheduler task `fulltext` takes care of it.
 *
 * Exception: the conversion to 'encrypted' rebuilds at once (Field::convert()) -
 * otherwise the plain text would sit in the search index until then.
 *
 * The marker holds the EARLIEST point in time. RecordService::rebuildFulltext()
 * only clears it after a complete run if it is not younger than that run's
 * start (clear()) - a change during the run therefore stays.
 *
 * LOCK
 * --------------------
 * At most one rebuild runs per area: var/tmp/fulltext-{area_id}.lock, created
 * exclusively (fopen 'x' - flock() does not lock across processes on the bind mount
 * of Docker Desktop). The run refreshes the file regularly (touch()); if it stays
 * untouched for LOCK_STALE seconds, the process counts as aborted and the lock
 * is taken over.
 *
 * @see Camindo\Core\Modules\Record\RecordService::rebuildFulltext()
 * @see Camindo\Core\Cli\FulltextCommand
 */
namespace Camindo\Core;

class FulltextState {

    /** Seconds without touch() after which a lock counts as orphaned */
    public const LOCK_STALE = 900;

    /** Locks held by this process: area_id => path */
    private array $locks = [];

    public function __construct(
        private Database $db,
        private Config $config,
    ) {}

    // ====================
    // Marker

    /**
     * Mark the full text of the areas as stale (record areas only; an
     * existing marker keeps its point in time).
     *
     * @param int[] $areaIds
     */
    public function markStale(array $areaIds): void
    {
        $areaIds = array_values(array_unique(array_filter(array_map('intval', $areaIds))));
        if (empty($areaIds)) return;
        $this->db->pushResult();
        try {
            $this->db->query(
                "UPDATE `area` SET `fulltext_stale_at` = UTC_TIMESTAMP()"
                . " WHERE `fulltext_stale_at` IS NULL AND `type` IN ('record', 'profile', 'ugc')"
                . " AND `area_id` IN (" . implode(',', $areaIds) . ")"
            );
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Mark all record areas of an instance as stale */
    public function markStaleInstance(int $instanceId): void
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `area_id` FROM `area` WHERE `instance_id` = ?", 'i', [$instanceId]);
            $areaIds = array_map('intval', $this->db->fetchAll());
        }
        finally {
            $this->db->popResult();
        }
        $this->markStale($areaIds);
    }

    /**
     * Areas whose full text may have a field as source: its own area
     * (standard full text), the areas of the full-text fields it feeds into (also
     * via a link field), and the areas with a link field pointing to its area
     * (their standard full text takes in the text fields of the target areas).
     *
     * Call before the field is deleted - afterwards `field` and
     * `rel_fulltext_text` are missing.
     *
     * @return int[]
     */
    public function areasUsingField(int $fieldId): array
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `area_id` FROM `field` WHERE `field_id` = ?"
                . " UNION SELECT `f`.`area_id` FROM `rel_fulltext_text` `r`"
                . " INNER JOIN `field` `f` ON `f`.`field_id` = `r`.`fulltext_field_id` WHERE `r`.`text_field_id` = ?"
                . " UNION SELECT `l`.`area_id` FROM `field` `t`"
                . " INNER JOIN `rel_field_area` `ra` ON `ra`.`area_id` = `t`.`area_id`"
                . " INNER JOIN `field` `l` ON `l`.`field_id` = `ra`.`field_id` AND `l`.`type` = 'link'"
                . " WHERE `t`.`field_id` = ?",
                'iii', [$fieldId, $fieldId, $fieldId]
            );
            return array_map('intval', $this->db->fetchAll());
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Since when (UTC, 'Y-m-d H:i:s') the full text is stale - null: current */
    public function staleSince(int $areaId): ?string
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `fulltext_stale_at` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
            $value = $this->db->fetchNum()[0] ?? null;
            return $value === null ? null : (string)$value;
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Stale areas (for `fulltext --stale`) */
    public function staleAreaIds(): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `area_id` FROM `area` WHERE `fulltext_stale_at` IS NOT NULL AND `deleted` = 0 ORDER BY `lft`");
            return array_map('intval', $this->db->fetchAll());
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Delete the marker after a complete rebuild - only if it is not
     * younger than the start of that rebuild.
     *
     * @param string $startedAt  start of the run (UTC, 'Y-m-d H:i:s')
     */
    public function clear(int $areaId, string $startedAt): void
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                "UPDATE `area` SET `fulltext_stale_at` = NULL WHERE `area_id` = ? AND `fulltext_stale_at` <= ?",
                'is', [$areaId, $startedAt]
            );
        }
        finally {
            $this->db->popResult();
        }
    }

    // ====================
    // Lock

    private function lockFile(int $areaId): string
    {
        return $this->config->get('paths.tmp_dir') . '/fulltext-' . $areaId . '.lock';
    }

    /**
     * Take the lock for rebuilding an area.
     *
     * @return bool  false: another process is currently rebuilding the area
     */
    public function lock(int $areaId): bool
    {
        if (isset($this->locks[$areaId])) return true;
        $file = $this->lockFile($areaId);
        for ($try = 0; $try < 2; $try++) {
            $fh = @fopen($file, 'x');
            if ($fh !== false) {
                fwrite($fh, (string)getmypid());
                fclose($fh);
                $this->locks[$areaId] = $file;
                return true;
            }
            if ($this->lockInfo($areaId) !== null) return false;
            // orphaned: take over (rename, so that only ONE process gets it)
            $stale = $file . '.' . getmypid() . '.stale';
            if (@rename($file, $stale)) @unlink($stale);
        }
        return false;
    }

    /** Refresh a running lock (during a long run) */
    public function touch(int $areaId): void
    {
        if (isset($this->locks[$areaId])) @touch($this->locks[$areaId]);
    }

    public function unlock(int $areaId): void
    {
        if (!isset($this->locks[$areaId])) return;
        @unlink($this->locks[$areaId]);
        unset($this->locks[$areaId]);
    }

    /**
     * Is a rebuild of the area running at the moment?
     *
     * @return array|null  ['touched' => Unix time of the last refresh] or null
     */
    public function lockInfo(int $areaId): ?array
    {
        $file = $this->lockFile($areaId);
        clearstatcache(true, $file);
        $mtime = @filemtime($file);
        if ($mtime === false || time() - $mtime > self::LOCK_STALE) return null;
        return ['touched' => $mtime];
    }
}
