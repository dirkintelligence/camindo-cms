<?php
/**
 * Werkzeug "Baum normalisieren" (Tools -> Daten).
 *
 * Repariert den Baum EINES Inhaltsbereichs (`record`, siehe TreeManagerTrait), wenn
 * `position` und/oder `lft`/`rgt` durcheinandergeraten sind - z. B. doppelt vergebene
 * `position`-Werte innerhalb eines Parents oder ein beschädigter Nested-Set-Bereich
 * (`lft`/`rgt` mit Lücken, Überschneidungen oder außerhalb 1..2n).
 *
 * Beide reparierenden Schritte existieren bereits im Trait und werden hier nur
 * verkettet - erst `position` je Parent lückenlos neu vergeben (Reihenfolge bleibt
 * erhalten), dann `lft`/`rgt`/`depth`/`parent_ids` komplett aus `parent_id` +
 * `position` neu aufbauen:
 *
 *     Record::normalizePositions()   siehe TreeManagerTrait
 *     Record::externalRebuildTree()  siehe TreeManagerTrait
 *
 * diagnose() ist rein lesend und dient der Anzeige vor/nach dem Lauf.
 *
 *     backend/index_data.php             -> ToolsBackend::dataTools()      das Formular
 *     backend/tool_tree_normalize.php    -> ToolsBackend::treeNormalize()  prüfen / ausführen
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
     * Inhaltsbereiche mit Baum (Eingabefeld `parent_id`, Record::treeAreaIds()):
     * area_id => Beschriftung (Label, bei mehreren Mandanten mit dem Namen der
     * Instanz), in Baumreihenfolge der Bereiche selbst.
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
     * Baum eines Bereichs auf Unstimmigkeiten prüfen - rein lesend.
     * Als deleted markierte Datensätze zählen mit (sie belegen weiterhin
     * position/lft/rgt, siehe Klassenkommentar TreeManagerTrait); Arbeitskopien
     * (tmp=1) nie.
     *
     * @return array{
     *     total: int,
     *     duplicate_positions: int,   Anzahl (parent_id, position)-Gruppen mit >1 Datensatz
     *     unclean_parents: int,       Anzahl Parents, deren position-Folge nicht exakt 1..n ist
     *     invalid_bounds: int,        Datensätze mit lft<1 oder rgt<=lft
     *     duplicate_lft: int,         Anzahl lft-Werte, die mehrfach vergeben sind
     *     orphan_parents: int,        parent_id verweist auf keinen Datensatz desselben Bereichs
     *     range_ok: bool,             MIN(lft)==1 und MAX(rgt)==2*total (bei total>0)
     *     clean: bool                 alle Prüfungen unauffällig
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
            // doppelt vergebene position je Parent
            $this->db->query(
                "SELECT COUNT(*) FROM ("
                . "  SELECT `parent_id`, `position` FROM `record` WHERE `area_id` = ? AND `tmp` = 0"
                . "  GROUP BY `parent_id`, `position` HAVING COUNT(*) > 1"
                . ") `x`",
                'i', [$areaId]
            );
            $result['duplicate_positions'] = (int)($this->db->fetchNum()[0] ?? 0);

            // Parents, deren position-Folge nicht lückenlos 1..n ist (fängt auch
            // Lücken ein, die keine Duplikate sind)
            $this->db->query(
                "SELECT COUNT(DISTINCT `parent_id`) FROM ("
                . "  SELECT `parent_id`, `position`,"
                . "         ROW_NUMBER() OVER (PARTITION BY `parent_id` ORDER BY `position` ASC, `record_id` ASC) AS `rn`"
                . "  FROM `record` WHERE `area_id` = ? AND `tmp` = 0"
                . ") `t` WHERE `position` != `rn`",
                'i', [$areaId]
            );
            $result['unclean_parents'] = (int)($this->db->fetchNum()[0] ?? 0);

            // lft/rgt: ungültige Grenzen
            $this->db->query(
                "SELECT COUNT(*) FROM `record` WHERE `area_id` = ? AND `tmp` = 0"
                . " AND (`lft` < 1 OR `rgt` <= `lft`)",
                'i', [$areaId]
            );
            $result['invalid_bounds'] = (int)($this->db->fetchNum()[0] ?? 0);

            // lft/rgt: doppelt vergebene lft-Werte
            $this->db->query(
                "SELECT COUNT(*) FROM ("
                . "  SELECT `lft` FROM `record` WHERE `area_id` = ? AND `tmp` = 0"
                . "  GROUP BY `lft` HAVING COUNT(*) > 1"
                . ") `x`",
                'i', [$areaId]
            );
            $result['duplicate_lft'] = (int)($this->db->fetchNum()[0] ?? 0);

            // parent_id verweist auf keinen (noch vorhandenen) Datensatz desselben Bereichs
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

            // Gesamtbereich: lft beginnt bei 1, rgt endet bei 2*total (lückenloses Nested Set)
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
     * Baum eines Bereichs reparieren: position je Parent lückenlos neu vergeben
     * (bisherige Reihenfolge bleibt erhalten), danach lft/rgt/depth/parent_ids
     * komplett aus parent_id + position neu aufbauen.
     * Ein `orphan_parents`-Befund aus diagnose() wird dabei mit-repariert:
     * rebuildTree() behandelt eine bereichsfremde/fehlende parent_id als Wurzel.
     *
     * @return array{before: array, after: array}  diagnose() vor und nach dem Lauf
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
