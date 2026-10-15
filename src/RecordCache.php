<?php
/**
 * Discard the content cache `record_cache` - all of it, of one instance or of single areas.
 *
 * The rows are a derived image of the data_* tables (developer docs, chapter 02
 * (Data model)); Cms::fetch() rebuilds a missing row on the next access. Clearing
 * therefore loses nothing - it only costs the rebuild. Working copies (`record.tmp` = 1)
 * stay untouched: their `record_cache` is the working state, not a cache.
 *
 * Callers: camindo.php cache records (CacheCommand) and the Server tab of the Tools
 * module (ToolServer).
 */
namespace Camindo\Core;

class RecordCache {

    public function __construct(
        private Database $db,
    )
    {
    }

    /**
     * Delete the cache rows of live records.
     *
     * @param int   $instanceId  0: all instances
     * @param int[] $areaIds     empty: all areas (of the instance)
     * @return int  number of deleted rows (one per record and language)
     * @throws \InvalidArgumentException  unknown instance, unknown area or area of another instance
     */
    public function clear(int $instanceId = 0, array $areaIds = []): int
    {
        $areaIds = array_values(array_unique(array_filter(array_map('intval', $areaIds))));

        if ($instanceId > 0) {
            $this->db->query("SELECT COUNT(*) FROM `instance` WHERE `instance_id` = ?", 'i', [$instanceId]);
            if ((int)($this->db->fetchNum()[0] ?? 0) === 0) {
                throw new \InvalidArgumentException("unknown instance $instanceId");
            }
        }
        if ($areaIds) {
            $marks = implode(',', array_fill(0, count($areaIds), '?'));
            $this->db->query(
                "SELECT `area_id`, `instance_id` FROM `area` WHERE `area_id` IN ($marks)",
                str_repeat('i', count($areaIds)), $areaIds
            );
            $found = [];
            while ($row = $this->db->fetch()) $found[(int)$row['area_id']] = (int)$row['instance_id'];
            foreach ($areaIds as $areaId) {
                if (!isset($found[$areaId])) {
                    throw new \InvalidArgumentException("unknown area $areaId");
                }
                if ($instanceId > 0 && $found[$areaId] !== $instanceId) {
                    throw new \InvalidArgumentException("area $areaId does not belong to instance $instanceId");
                }
            }
        }

        $where = ["`r`.`tmp` = 0"];
        $types = '';
        $params = [];
        if ($instanceId > 0) {
            $where[] = "`r`.`instance_id` = ?";
            $types .= 'i';
            $params[] = $instanceId;
        }
        if ($areaIds) {
            $where[] = "`r`.`area_id` IN (" . implode(',', array_fill(0, count($areaIds), '?')) . ")";
            $types .= str_repeat('i', count($areaIds));
            array_push($params, ...$areaIds);
        }
        $this->db->query(
            "DELETE `c` FROM `record_cache` `c`"
            . " INNER JOIN `record` `r` ON `r`.`record_id` = `c`.`record_id`"
            . " WHERE " . implode(' AND ', $where),
            $types, $params
        );
        return $this->db->affected_rows;
    }

    /**
     * Areas that hold records, for a selection: instance_id => [area_id => label (name)],
     * instances and areas in tree order.
     *
     * @return array<int, array<int, string>>
     */
    public function areaOptions(Cms $cms): array
    {
        $this->db->query(
            "SELECT `area_id`, `instance_id`, `label`, `name` FROM `area`"
            . " WHERE `type` IN ('record','profile','ugc') AND `deleted` = 0"
            . " ORDER BY `instance_id` ASC, `lft` ASC"
        );
        $options = [];
        while ($row = $this->db->fetch()) {
            $options[(int)$row['instance_id']][(int)$row['area_id']] =
                $cms->_strip_tags($cms->textOrSnippet((string)$row['label'])) . ' (' . $row['name'] . ')';
        }
        return $options;
    }
}
