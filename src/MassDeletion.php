<?php
/**
 * Decides whether a mass deletion runs in the web request or in the background.
 *
 * Since the switch to single-record processing, Dataset::delete() sends every affected
 * record individually through softDelete() - that is maintainable and lets the hooks run,
 * but per record it takes noticeably longer than a mass UPDATE. With an
 * area holding many thousands of records the request would hang.
 *
 * This class is the one place where the limit is drawn:
 *
 *     $count = $massDeletion->affectedCount('area', $areaId, $withChilds, 'delete');
 *     if ($massDeletion->needsBackground($count)) {
 *         $massDeletion->start('area', $areaId, 'delete', $withChilds);   // asynchron
 *     } else {
 *         $area->delete($areaId, $withChilds);                            // sofort
 *     }
 *
 * IMPORTANT: the background run executes EXACTLY THE SAME code - Core/cron/mass_delete.php
 * calls the same Dataset method. The background process only moves the work, it is
 * not a second deletion path.
 *
 * The threshold is set in admin.mass_delete_threshold (default 200).
 * 0 switches the offloading off - then everything runs in the request.
 *
 * Progress and cancellation run via the existing background process display in the
 * backend (table `bgproc`, cApp.polling).
 *
 * @see Camindo\Core\Dataset            delete() / restore() / purge()
 * @see Camindo\Core\BackgroundProcess  state management of the processes
 */
namespace Camindo\Core;

class MassDeletion {

    /**
     * Tables for which a mass deletion can be offloaded,
     * and the column through which their dependent records hang.
     *
     * 'area'   -> records hang on the area via record.area_id
     * 'record' -> child records hang on the parent via the nested set
     */
    private const TABLES = ['area', 'record'];

    public function __construct(
        private Config $config,
        private Database $db,
        private BackgroundProcess $bgproc,
        private Crypt $crypt,
        private Session $session,
        private System $system,
    )
    {
    }

    /**
     * How many records does the operation cover?
     *
     * Only an order of magnitude for the threshold decision, not an exact preview:
     * the dependent records are counted, not the one clicked.
     *
     * @param string $table       'area' or 'record'
     * @param int    $id          id of the clicked record
     * @param bool   $withChilds  include child records? (only for 'record')
     * @param string $mode        'delete' | 'restore' | 'purge'
     * @return int
     */
    public function affectedCount(string $table, int $id, bool $withChilds, string $mode = 'delete'): int
    {
        if (!in_array($table, self::TABLES, true) || $id < 1) return 0;

        // For 'delete' count only unmarked ones, for 'restore' only marked ones
        $deletedCondition = match ($mode) {
            'delete'  => ' AND `deleted` = 0',
            'restore' => ' AND `deleted` = 1',
            default   => '',
        };

        $this->db->pushResult();
        if ($table === 'area') {
            // All records of the area
            $sql = "SELECT COUNT(*) FROM `record` WHERE `area_id` = ? AND `tmp` = 0" . $deletedCondition;
            $this->db->query($sql, 'i', [$id]);
        }
        elseif (!$withChilds) {
            // Only the record itself - never a case for the background
            $this->db->popResult();
            return 1;
        }
        else {
            // Subtree via lft/rgt. The bounds apply per content area - there lft
            // starts again at 1 each time (see TreeManagerTrait), so `area_id`
            // must be filtered as well. Records from areas WITHOUT a tree
            // have lft = rgt = 0 and thus no subtree: without the check the
            // condition would match every other tree-less record.
            $this->db->query(
                "SELECT `lft`, `rgt`, `area_id` FROM `record` WHERE `record_id` = ?",
                'i', [$id]
            );
            $row = $this->db->fetch();
            $lft = (int)($row['lft'] ?? 0);
            $rgt = (int)($row['rgt'] ?? 0);
            if ($lft < 1 || $rgt <= $lft) {
                // No subtree - only the record itself
                $this->db->popResult();
                return 1;
            }
            $sql = "SELECT COUNT(*) FROM `record`"
                 . " WHERE `area_id` = ? AND `lft` >= ? AND `rgt` <= ?"
                 . " AND `tmp` = 0" . $deletedCondition;
            $this->db->query($sql, 'iii', [(int)$row['area_id'], $lft, $rgt]);
        }
        $count = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();

        return $count;
    }

    /**
     * Should this operation go to the background?
     *
     * @param int $count  result of affectedCount()
     */
    public function needsBackground(int $count): bool
    {
        $threshold = (int)($this->config->get('admin.mass_delete_threshold') ?? 200);
        if ($threshold < 1) return false;   // offloading switched off
        return $count > $threshold;
    }

    /**
     * Start the operation as a background process.
     *
     * The process calls the same Dataset method the web request would use -
     * see Core/cron/mass_delete.php.
     *
     * @param string $table       'area' or 'record'
     * @param int    $id          id of the clicked record
     * @param string $mode        'delete' | 'restore' | 'purge'
     * @param bool   $withChilds  include child records?
     * @param string $error       reason of a failure (by reference)
     * @return array|null         ['pid' => int, 'token' => string, 'id' => int] or null
     */
    public function start(string $table, int $id, string $mode, bool $withChilds, ?string &$error = null): ?array
    {
        $error = '';
        if (!in_array($table, self::TABLES, true) || $id < 1) {
            $error = 'invalid target';
            return null;
        }
        if (!in_array($mode, ['delete', 'restore', 'purge'], true)) {
            $error = 'invalid mode';
            return null;
        }

        $phpExec = (string)$this->config->get('paths.php');
        if ($phpExec === '' || !is_executable($phpExec)) {
            $error = "Error: 'php' is not executable (paths.php)";
            $this->system->log('massdelete-errors', $error);
            return null;
        }

        // Create the token beforehand so it can be passed to the command as an argument -
        // the background process reports its completion through it.
        // (Same approach as in FFmpegVideo::convert().)
        $token = $this->crypt->randomKey(16);

        $command = [
            $phpExec,
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'mass_delete.php',
            '--table=' . $table,
            '--id=' . $id,
            '--mode=' . $mode,
            '--withchilds=' . ($withChilds ? '1' : '0'),
            // The background process has no session; the id of the triggering
            // administrator is therefore passed as an argument - it ends up in
            // changed_by of the marked records.
            '--admin=' . (int)$this->session->get('admin_id'),
            '--token=' . $token,
        ];

        $result = $this->bgproc->start($command, 'massdelete', $token, null, null, $error);
        if ($result === null) {
            $this->system->log('massdelete-errors', "start failed: {$error}");
        }
        return $result;
    }

}
