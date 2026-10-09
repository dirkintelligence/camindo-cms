<?php
/**
 * Tool "Normalize tree" (Tools -> Data).
 *
 * Repairs the tree of ONE content area (`record`, see TreeManagerTrait) if
 * `position` and/or `lft`/`rgt` have got out of order - e.g. duplicate
 * `position` values within a parent, or a damaged nested-set range
 * (`lft`/`rgt` with gaps, overlaps or outside 1..2n).
 *
 * Both repairing steps already exist in the trait and are only chained here -
 * first reassign `position` per parent without gaps (order is preserved),
 * then rebuild `lft`/`rgt`/`depth`/`parent_ids` completely from `parent_id` +
 * `position`:
 *
 *     Record::normalizePositions()   see TreeManagerTrait
 *     Record::externalRebuildTree()  see TreeManagerTrait
 *
 * diagnose() is read-only and serves for display before and after the run.
 *
 *     backend/index_data.php             -> ToolsBackend::dataTools()      the form
 *     backend/tool_tree_normalize.php    -> ToolsBackend::treeNormalize()  check / run
 *
 * @see Camindo\Core\TreeManagerTrait
 * @see Camindo\Core\Modules\Tools\ToolsBackend
 */
namespace Camindo\Core\Modules\Tools;

use Camindo\Core\Cms;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\Modules\Record\Record;

class ToolTree {

    public function __construct(
        private Database $db,
        private Cms $cms,
        private Record $record,
        private InstanceCache $instanceCache,
    )
    {}

    /**
 *     Content areas with a tree (input field `parent_id`, Record::treeAreaIds()):
 *     area_id => label (label, with multiple tenants the name of the
 *     instance), in tree order of the areas themselves.
     */
    public function areaOptions(): array
    {
        $areaIds = $this->record->treeAreaIds();
        if (empty($areaIds)) return [];

        $placeholders = implode(',', array_fill(0, count($areaIds), '?'));
        $this->db->pushResult();
        $this->db->query(
            "SELECT `area_id`, `label`, `name`, `instance_id` FROM `area`"
            . " WHERE `area_id` IN ($placeholders) AND `deleted` = 0 ORDER BY `lft` ASC",
            str_repeat('i', count($areaIds)), $areaIds
        );
        $rows = $this->db->fetchAll();
        $this->db->popResult();

        $multi = $this->instanceCache->hasMultipleInstances();
        $instances = $multi ? $this->instanceCache->instanceLabels() : [];
        $options = [];
        foreach ($rows as $row) {
            $label = $this->cms->_strip_tags($this->cms->textOrSnippet((string)$row['label'])) . ' (' . $row['name'] . ')';
            if ($multi) {
                $label = $this->cms->_strip_tags($this->cms->textOrSnippet((string)($instances[(int)$row['instance_id']] ?? ''))) . ' / ' . $label;
            }
            $options[(int)$row['area_id']] = $label;
        }
        return $options;
    }

    /**
     * Check a tree of an area for inconsistencies - read-only.
     * Records marked as deleted count too (they still occupy
     * position/lft/rgt, see class comment TreeManagerTrait); working copies
     * (tmp=1) never.
     *
     * @return array{
     *     total: int,
     *     duplicate_positions: int,   number of (parent_id, position) groups with >1 record
     *     unclean_parents: int,       number of parents whose position sequence is not exactly 1..n
     *     invalid_bounds: int,        records with lft<1 or rgt<=lft
     *     duplicate_lft: int,         number of lft values assigned more than once
     *     orphan_parents: int,        parent_id points to no record of the same area
     *     range_ok: bool,             MIN(lft)==1 and MAX(rgt)==2*total (when total>0)
     *     clean: bool                 all checks inconspicuous
     * }
     */
    public function diagnose(int $areaId): array
    {
        $result = [
            'total' => 0,
            'duplicate_positions' => 0,
            'unclean_parents' => 0,
            'invalid_bounds' => 0,
            'duplicate_lft' => 0,
            'orphan_parents' => 0,
            'range_ok' => true,
            'clean' => true,
        ];
        if ($areaId < 1) return $result;

        $this->db->pushResult();

        $this->db->query("SELECT COUNT(*) FROM `record` WHERE `area_id` = ? AND `tmp` = 0", 'i', [$areaId]);
        $result['total'] = (int)($this->db->fetchNum()[0] ?? 0);

        if ($result['total'] > 0) {
            // duplicate position per parent
            $this->db->query(
                "SELECT COUNT(*) FROM ("
                . "  SELECT `parent_id`, `position` FROM `record` WHERE `area_id` = ? AND `tmp` = 0"
                . "  GROUP BY `parent_id`, `position` HAVING COUNT(*) > 1"
                . ") `x`",
                'i', [$areaId]
            );
            $result['duplicate_positions'] = (int)($this->db->fetchNum()[0] ?? 0);

            // Parents whose position sequence is not gapless 1..n (also catches
            // gaps that are not duplicates)
            $this->db->query(
                "SELECT COUNT(DISTINCT `parent_id`) FROM ("
                . "  SELECT `parent_id`, `position`,"
                . "         ROW_NUMBER() OVER (PARTITION BY `parent_id` ORDER BY `position` ASC, `record_id` ASC) AS `rn`"
                . "  FROM `record` WHERE `area_id` = ? AND `tmp` = 0"
                . ") `t` WHERE `position` != `rn`",
                'i', [$areaId]
            );
            $result['unclean_parents'] = (int)($this->db->fetchNum()[0] ?? 0);

            // lft/rgt: invalid bounds
            $this->db->query(
                "SELECT COUNT(*) FROM `record` WHERE `area_id` = ? AND `tmp` = 0"
                . " AND (`lft` < 1 OR `rgt` <= `lft`)",
                'i', [$areaId]
            );
            $result['invalid_bounds'] = (int)($this->db->fetchNum()[0] ?? 0);

            // lft/rgt: duplicate lft values
            $this->db->query(
                "SELECT COUNT(*) FROM ("
                . "  SELECT `lft` FROM `record` WHERE `area_id` = ? AND `tmp` = 0"
                . "  GROUP BY `lft` HAVING COUNT(*) > 1"
                . ") `x`",
                'i', [$areaId]
            );
            $result['duplicate_lft'] = (int)($this->db->fetchNum()[0] ?? 0);

            // parent_id points to no (still existing) record of the same area
            $this->db->query(
                "SELECT COUNT(*) FROM `record` `r` WHERE `r`.`area_id` = ? AND `r`.`tmp` = 0"
                . " AND `r`.`parent_id` IS NOT NULL"
                . " AND NOT EXISTS ("
                . "   SELECT 1 FROM `record` `p` WHERE `p`.`record_id` = `r`.`parent_id`"
                . "     AND `p`.`area_id` = `r`.`area_id` AND `p`.`tmp` = 0"
                . " )",
                'i', [$areaId]
            );
            $result['orphan_parents'] = (int)($this->db->fetchNum()[0] ?? 0);

            // Overall range: lft starts at 1, rgt ends at 2*total (gapless nested set)
            $this->db->query(
                "SELECT MIN(`lft`), MAX(`rgt`) FROM `record` WHERE `area_id` = ? AND `tmp` = 0",
                'i', [$areaId]
            );
            $bounds = $this->db->fetchNum();
            $minLft = (int)($bounds[0] ?? 0);
            $maxRgt = (int)($bounds[1] ?? 0);
            $result['range_ok'] = ($minLft === 1 && $maxRgt === 2 * $result['total']);
        }

        $this->db->popResult();

        $result['clean'] =
            $result['duplicate_positions'] === 0
            && $result['unclean_parents'] === 0
            && $result['invalid_bounds'] === 0
            && $result['duplicate_lft'] === 0
            && $result['orphan_parents'] === 0
            && $result['range_ok'];

        return $result;
    }

    /**
     * Repair the tree of an area: reassign position per parent without gaps
     * (previous order is preserved), then rebuild lft/rgt/depth/parent_ids
     * completely from parent_id + position.
     * An `orphan_parents` finding from diagnose() is repaired along with it:
     * rebuildTree() treats a parent_id that is foreign to the area or missing as root.
     *
     * @return array{before: array, after: array}  diagnose() before and after the run
     */
    public function normalize(int $areaId): array
    {
        $before = $this->diagnose($areaId);
        if ($areaId > 0 && $before['total'] > 0) {
            $this->record->normalizePositions($areaId);
            $this->record->externalRebuildTree($areaId);
        }
        $after = $this->diagnose($areaId);
        return ['before' => $before, 'after' => $after];
    }
}
