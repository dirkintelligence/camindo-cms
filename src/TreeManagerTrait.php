<?php
/**
 * Erweiterung für Kindklassen von Dataset
 * Behandlung von Nested Sets mit Adjacency Lists
 * 
 * Felder:
 *      'parent_id' : Id des Parent-Datensatzes
 *      'position'  : Position innerhalb direktem Parent
 *                    Hinweis: es wird 'previous_id' verwarbeitet (vorangehender Datensatz)
 *                    Das ist aber kein echtes Datenbankeld - sondern daraus errechnet sich 'position'
 *      'lft' und
 *      'rgt'       : Grenzen im Nested Set
 *      'depth'     : Hierarchietiefe, 0-basiert (Wurzelknoten haben depth = 0)
 *
 * EIN BAUM JE INHALTSBEREICH
 * --------------------
 * Hat die Tabelle eine `area_id` (also: `record`), dann ist der Baum NICHT
 * tabellenweit, sondern es gibt einen eigenen, in sich geschlossenen Baum je
 * Inhaltsbereich:
 *
 *      - `lft`, `rgt` beginnen in JEDEM Bereich wieder bei 1
 *      - `position` zählt je Bereich und Parent
 *      - jede Abfrage über `lft`/`rgt` MUSS deshalb `area_id` mitfiltern
 *
 * Ob ein Bereich überhaupt einen Baum führt, entscheidet der Redakteur: nur wenn er
 * im Bereich ein Eingabefeld mit dem Namen `parent_id` anlegt (Metafeld), ist der
 * Baum dort aktiv - isTreeArea(). In allen anderen Bereichen bleiben
 *
 *      parent_id  = NULL
 *      lft, rgt   = 0
 *      depth      = 0
 *      parent_ids = []
 *
 * und es findet KEINE Baumpflege statt. Das ist der Normalfall und der Grund für
 * die Unterscheidung: die meisten Bereiche sind flach, enthalten aber sehr viele
 * Datensätze - ein tabellenweiter rebuildTree() bei jedem Speichern wäre dort ein
 * Update je Datensatz der ganzen Tabelle, ohne jeden Nutzen.
 *
 * Tabellen ohne `area_id` (`area` selbst) haben genau einen Baum über die ganze
 * Tabelle; isTreeArea() liefert dort immer true und die area-Filter entfallen.
 *
 * Soft-Delete:
 *      Als deleted markierte Einträge verbleiben vorerst im Baum.
 *      Sie laufen bei Änderungen mit (lft, rgt, position, depth),
 *      bis sie endgültig gelöscht werden.
 *      In den Select-Listen der Eingabemasken (parent_id, previous_id)
 *      stehen sie jedoch nicht zur Auswahl, daher überspringen
 *      getPreviousId() und validateNodeExists() deleted-Datensätze.
 *      Soft-Delete ohne Kinder (withChilds == false): Kinddatensätze werden
 *      vorher dem Parent zugewiesen (liftChildren()), analog zum echten Löschen.
 *      Alle in einem Vorgang markierten Datensätze erhalten exakt denselben
 *      changed_at (Dataset::$markTimestamp); restore() stellt nur Datensätze
 *      desselben Löschvorgangs wieder her (Dataset::$restoreTimestamp).
 *
 * Keine Massen-Operationen mehr:
 *      Früher räumten deleteWithChilds() und setDeletedWithChilds() einen ganzen
 *      Teilbaum mit EINEM SQL-Statement über lft/rgt ab - dabei liefen keine Hooks.
 *      Beide sind entfallen. Der Trait liefert jetzt nur noch die Ids des Teilbaums
 *      (subtreeIds()); Dataset schickt sie einzeln durch softDeleteOne(),
 *      restoreOneCore() bzw. finalDeleteOne(). Der Baum wird dabei einmal am Ende
 *      des Vorgangs neu berechnet (rebuildTreeNow()), nicht je Datensatz.
 */

namespace Camindo\Core;
trait TreeManagerTrait
{
    /**
     * Merker je Inhaltsbereich: führt er einen Baum? (isTreeArea())
     * Dataset-Kindklassen sind Shared Services - der Cache lebt daher so lange wie
     * der Request. Wird ein `parent_id`-Feld angelegt oder entfernt, muss er
     * verworfen werden: clearTreeAreaCache().
     */
    private array $treeAreaCache = [];

    /**
     * Führt dieser Inhaltsbereich einen Baum?
     *
     * Maßgeblich ist, ob im Bereich ein Eingabefeld `parent_id` definiert ist -
     * dieselbe Prüfung, mit der das Backend entscheidet, ob es die Liste als Baum
     * darstellt (isset($schemaVirtual['parent_id'])). Hier wird nur die Tabelle
     * `field` gefragt, damit die Antwort auch in den Lösch-Pfaden ohne geladenes
     * Schema zur Verfügung steht.
     *
     * Tabellen ohne `area_id` (`area`) führen genau einen Baum -> immer true.
     *
     * @param int $areaId  Id des Inhaltsbereichs
     */
    public function isTreeArea(int $areaId): bool
    {
        if (!$this->hasParentId) return false;
        if (!$this->hasAreaId)   return true;   // Tabelle ohne Bereiche: ein Baum
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
     * Ids aller Inhaltsbereiche, die einen Baum führen.
     * Für Reparatur-/Migrationswerkzeuge, die alle Bäume neu berechnen.
     *
     * @return int[]  bei Tabellen ohne `area_id`: leeres Array (ein Baum, keine Bereiche)
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
     * Merker aus isTreeArea() verwerfen.
     * Nötig, sobald ein `parent_id`-Feld angelegt oder entfernt wurde
     * (Field::hookOnSaved() / Field::hookOnDeleted()).
     */
    public function clearTreeAreaCache(): void
    {
        $this->treeAreaCache = [];
    }

    /**
     * Hilfsmethode: SQL-Filter für Datensätze, die zum Baum gehören.
     * Temporäre Arbeitskopien (tmp=1) gehören nie zum Baum.
     * Als deleted markierte Datensätze verbleiben im Baum (lft, rgt, position)
     * und werden daher standardmäßig NICHT herausgefiltert.
     *
     * @param bool $excludeDeleted  true: zusätzlich `deleted`=0 fordern
     *                              (für Eingabemasken / Select-Listen)
     * @return string               ' AND ...' zum Anhängen an eine WHERE-Klausel
     */
    private function treeFilter(bool $excludeDeleted = false): string
    {
        $sql = '';
        if ($this->hasTmp) $sql .= " AND `tmp`=0";
        if ($excludeDeleted && $this->hasDeleted) $sql .= " AND `deleted`=0";
        return $sql;
    }

    /**
     * Hilfsmethode: WHERE-Bedingung für den Inhaltsbereich aufbauen.
     *
     * Jeder Baum lebt innerhalb EINES Bereichs (siehe Klassenkommentar). Ohne diesen
     * Filter würden sich Geschwister-Operationen auf der Wurzelebene
     * (`parent_id` IS NULL) über alle Bereiche hinweg auswirken - dort ist der
     * Bereich das einzige Unterscheidungsmerkmal.
     *
     * @param int    $areaId      Id des Inhaltsbereichs (0: kein Filter)
     * @param string &$bindTypes  Bind-Typen (werden ergänzt)
     * @param array  &$values     Bind-Werte (werden ergänzt)
     * @return string             '' oder ' AND `area_id` = ?'
     */
    private function areaCondition(int $areaId, string &$bindTypes, array &$values): string
    {
        if (!$this->hasAreaId || $areaId < 1) return '';
        $bindTypes .= 'i';
        $values[]   = $areaId;
        return " AND `area_id` = ?";
    }

    /**
     * Hilfsmethode: WHERE-Bedingung für den Parent aufbauen.
     * parent_id = 0 steht für die Wurzelebene (`parent_id` IS NULL).
     * Der Bereichsfilter läuft immer mit (siehe areaCondition()).
     * Bind-Typen und -Werte werden bei Bedarf ergänzt.
     *
     * @param int    $parentId    Id des Parent-Datensatzes oder 0
     * @param int    $areaId      Id des Inhaltsbereichs (0: kein Filter)
     * @param string &$bindTypes  Bind-Typen (werden ergänzt)
     * @param array  &$values     Bind-Werte (werden ergänzt)
     * @return string             SQL-Bedingung
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
     * Bereichs-Id eines Datensatzes nachschlagen.
     * Für die wenigen Stellen, die nur eine Id in der Hand haben.
     *
     * @param int $id  `{tableName}_id`
     * @return int     0, wenn die Tabelle keine Bereiche kennt oder der Datensatz fehlt
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
     * Lesezugriff: Lädt einen Zweig inklusive aller Kinder.
     * Wird 0 übergeben, wird der gesamte Baum des Bereichs geladen.
     *
     * @param int $rootId    Id des Root-Datensatzes oder 0 für den gesamten Baum
     * @param int $levels    Tiefe relativ zum Root-Knoten:
     *                       -1: unbegrenzt (Standard)
     *                        0: nur der Root-Knoten selbst, keine Kinder
     *                        1: Root + direkte Kinder
     *                        n: Root + n Ebenen Kinder
     * @param bool $includeDeleted  true:  als deleted markierte Datensätze mitliefern (Standard,
     *                                     sie verbleiben im Baum; das Feld `deleted` steht im Ergebnis)
     *                              false: nur nicht-gelöschte Datensätze
     * @param int $areaId    Inhaltsbereich; bei $rootId > 0 wird er aus dem Root-Knoten
     *                       übernommen. Ohne $rootId bei Tabellen mit `area_id` zwingend,
     *                       sonst kämen die Bäume aller Bereiche gemischt zurück.
     */
    public function loadTree(int $rootId = 0, int $levels = -1, bool $includeDeleted = true, int $areaId = 0): array
    {
        $conditions = [];
        $bindTypes  = '';
        $values     = [];
        $rootDepth  = 0;
        if ($rootId > 0) {
            // Root-Knoten laden, um lft/rgt/depth/area_id für die Bereichsabfrage zu kennen
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
                // Kein gültiger Nested-Set-Bereich (Bereich ohne Baum) -> nur der Knoten
                return $this->loadOne($rootId);
            }
            $areaId    = (int)($root['area_id'] ?? $areaId);
            $rootDepth = (int)$root['depth'];
            // Teilbaum: alle Knoten im lft/rgt-Bereich des Root-Knotens
            $conditions[] = '`lft` BETWEEN ? AND ?';
            $bindTypes   .= 'ii';
            $values[]     = (int)$root['lft'];
            $values[]     = (int)$root['rgt'];
        }
        else {
            // Ohne Root-Knoten nur echte Baum-Knoten (lft = 0 heißt: kein Baum)
            $conditions[] = '`lft` > 0';
        }
        // Ein Baum je Bereich - ohne diesen Filter kämen fremde Bereiche mit
        if ($this->hasAreaId && $areaId > 0) {
            $conditions[] = '`area_id` = ?';
            $bindTypes   .= 'i';
            $values[]     = $areaId;
        }
        // tmp-Kopien nie, deleted nur auf Wunsch herausfiltern
        if ($this->hasTmp) {
            $conditions[] = '`tmp`=0';
        }
        if (!$includeDeleted && $this->hasDeleted) {
            $conditions[] = '`deleted`=0';
        }
        if ($levels >= 0) {
            // Tiefenbegrenzung: absoluter depth-Wert = root-Tiefe + gewünschte Ebenen
            $conditions[] = '`depth` <= ?';
            $bindTypes   .= 'i';
            $values[]     = $rootDepth + $levels;
        }
        // WHERE-Klausel zusammensetzen
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
     * Hilfsmethode für loadTree(): einzelnen Datensatz als einelementige Liste.
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
     * Tree-Elemente für das Erstellen eines neuen Datensatzes zusammentragen.
     * Setzt position und bereitet Platzhalter für lft/rgt/depth vor.
     * rebuildTree() wird nach dem INSERT in Dataset::save() aufgerufen.
     *
     * Wird nur für Bereiche mit Baum gerufen (Dataset::save() prüft isTreeArea()).
     *
     * @param array $data    Daten, die zum Speichern bereit sind (werden erweitert)
     * @param int   $areaId  Inhaltsbereich des Datensatzes
     */
    public function createInTree(array &$data, int $areaId = 0): void
    {
        $parentId   = (int)($data['parent_id']   ?? 0);
        $previousId = (int)($data['previous_id'] ?? 0);
        // Validierung der angegebenen Datensätze (müssen im selben Bereich liegen)
        $this->validateNodeExists($parentId,   'parent_id',   $areaId);
        $this->validateNodeExists($previousId, 'previous_id', $areaId);
        // Positionen der Zielebene müssen 1..n lauten, sonst rechnet
        // calculatePosition() mit Doppelbelegungen weiter (normalizeLevel())
        $this->normalizeLevel($parentId, $areaId);
        // position aus previous_id ableiten und Geschwister nach hinten schieben
        $data['position'] = $this->calculatePosition($parentId, $previousId, $areaId);
        $this->shiftPositionsDown($parentId, $data['position'], 0, $areaId);
        // Platzhalter – rebuildTree() setzt die echten Werte nach dem INSERT
        $data['lft']  = 0;
        $data['rgt']  = 0;
        $data['depth'] = 0;
    }

    /**
     * Tree-Elemente für das Aktualisieren eines Datensatzes zusammentragen.
     * Prüft intern, ob eine Neupositionierung überhaupt nötig ist –
     * previous_id (Vorangehender Datensatz): wird zwar in $data angegeben, aber nicht selbst
     * in der Datenbank gespeichert und fehlt daher in $previousData daher immer
     * und muss daher abgeleitet werden.
     * 
     * Schließt die Lücke am alten Platz, schafft Platz am neuen Platz
     * und schreibt parent_id + position direkt in die DB.
     * rebuildTree() wird nach dem UPDATE in Dataset::save() aufgerufen.
     *
     * @param array $data          Daten die zum Speichern bereit sind (werden erweitert)
     * @param array $previousData  Bisherige Daten des Datensatzes
     * @param int   $areaId        Inhaltsbereich des Datensatzes (Ziel-Bereich)
     * @return bool                true, wenn der Datensatz tatsächlich umgehängt oder
     *                             verschoben wurde - nur dann muss der Aufrufer den
     *                             Baum neu berechnen lassen
     */
    public function updateInTree(array &$data, array $previousData, int $areaId = 0): bool
    {
        $id         = (int)($data[$this->tableName . '_id'] ?? 0);
        $parentId   = (int)($data['parent_id']   ?? 0);
        $previousId = (int)($data['previous_id'] ?? 0);
        $oldParentId = (int)($previousData['parent_id'] ?? 0);
        $oldPosition = (int)($previousData['position']  ?? 0);
        $oldAreaId   = (int)($previousData['area_id']   ?? $areaId);
        // Bisherige Ebene auf 1..n bringen (Doppelbelegungen, Lücken, Position 0 aus
        // Altbeständen) - sonst stimmen weder der abgeleitete Vorgänger noch die
        // Verschiebungen. Die eigene Position hat sich dabei ggf. geändert.
        if ($this->normalizeLevel($oldParentId, $oldAreaId)) {
            $oldPosition = $this->positionOf($id, $oldPosition);
        }
        // previous_id (ID des vorangehenden Datensatzes) aus der DB ableiten,
        // da dieses Feld nicht persistiert wird und in $previousData fehlt.
        // WICHTIG: dieselbe Logik wie beim Befüllen der Eingabemaske verwenden
        // (getPreviousId überspringt als deleted markierte Datensätze),
        // damit der Vergleich mit der aus der Maske übermittelten previous_id
        // konsistent bleibt - auch wenn Datensätze zwischenzeitlich
        // deleted markiert und wiederhergestellt wurden.
        $oldPreviousId = 0;
        if ($oldPosition > 1) {
            $oldPreviousId = $this->getPreviousId($oldParentId, $oldPosition, $oldAreaId);
        }
        // Keine Neupositionierung nötig
        if (
            $parentId   === $oldParentId
            && $previousId === $oldPreviousId
            && $areaId     === $oldAreaId
        ) {
            // Aktuelle Position mitgeben: ohne Baum schreibt Dataset::save() die
            // Spalte aus $data (der geladene Wert kann durch normalizeLevel() veraltet sein)
            $data['position'] = $oldPosition;
            return false;
        }
        // Validierung der angegebenen Datensätze (müssen im selben Bereich liegen)
        $this->validateNodeExists($parentId,   'parent_id',   $areaId);
        $this->validateNodeExists($previousId, 'previous_id', $areaId);
        // Lücke am alten Platz schließen
        $this->shiftPositionsUp($oldParentId, $oldPosition, $id, $oldAreaId);
        // Neue Ebene ebenfalls auf 1..n bringen (die eigene Zeile zählt dort nicht
        // mit, sie steht noch auf der alten Ebene bzw. wird unten überschrieben)
        if ($parentId !== $oldParentId || $areaId !== $oldAreaId) {
            $this->normalizeLevel($parentId, $areaId, $id);
        }
        // Platz am neuen Platz schaffen
        $newPosition = $this->calculatePosition($parentId, $previousId, $areaId);
        $this->shiftPositionsDown($parentId, $newPosition, $id, $areaId);
        // parent_id und position direkt schreiben (vor rebuildTree)
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
        // lft, rgt, depth – rebuildTree() übernimmt das nach dem UPDATE in save()
        $data['position'] = $newPosition;
        return true;
    }

    /**
     * Ids eines Teilbaums in Bearbeitungsreihenfolge sammeln.
     *
     * Ersetzt die früheren Massen-Operationen deleteWithChilds() und
     * setDeletedWithChilds(), die den ganzen Teilbaum mit EINEM SQL-Statement über
     * lft/rgt abgeräumt haben - dabei liefen keine Hooks. Stattdessen liefert diese
     * Methode nur noch die Ids; Dataset schickt sie einzeln durch softDeleteOne(),
     * restoreOneCore() bzw. finalDeleteOne().
     *
     * REIHENFOLGE
     *     'delete', 'purge'  Blätter zuerst (rgt aufsteigend). Beim endgültigen
     *                        Löschen zwingend: der Fremdschlüssel parent_id löscht
     *                        mit ON DELETE CASCADE sonst ganze Teilbäume, ohne dass
     *                        deren hookOnDelete() je liefe.
     *     'restore'          Eltern zuerst (lft aufsteigend) - die Umkehrung.
     *
     * FILTER
     *     'delete'   nur noch nicht markierte Datensätze (`deleted` = 0): bereits
     *                markierte behalten den changed_at ihres eigenen Löschvorgangs
     *     'restore'  nur markierte Datensätze (`deleted` = 1), und nur die mit exakt
     *                dem changed_at des rückgängig zu machenden Vorgangs
     *                (Dataset::$restoreTimestamp) - Datensätze aus früheren
     *                Löschvorgängen bleiben markiert
     *     'purge'    alle Datensätze des Teilbaums
     *
     * Der lft/rgt-Bereich gilt je Inhaltsbereich, `area_id` läuft deshalb mit.
     * Temporäre Arbeitskopien sind nie Teil des Baums (treeFilter()) und werden je
     * Datensatz von den Einzel-Funktionen mitbehandelt.
     *
     * @param array  $data  Datensatz mit lft / rgt / area_id (aus loadProductive())
     * @param string $mode  'delete' | 'restore' | 'purge'
     * @return int[]        Ids in Bearbeitungsreihenfolge
     */
    public function subtreeIds(array $data, string $mode = 'delete'): array
    {
        $idField = $this->tableName . '_id';
        $lft = (int)($data['lft'] ?? 0);
        $rgt = (int)($data['rgt'] ?? 0);
        if ($lft < 1 || $rgt <= $lft) {
            // Kein gültiger Nested-Set-Bereich (Bereich ohne Baum, Blatt ohne
            // gültige Grenzen) -> nur der Datensatz selbst
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

        // Blätter zuerst bzw. Eltern zuerst
        $sql .= ($mode === 'restore') ? " ORDER BY `lft` ASC" : " ORDER BY `rgt` ASC";

        $this->db->pushResult();
        $this->db->query($sql, $bindTypes, $values);
        $ids = $this->db->fetchAll(); // flaches Array
        $this->db->popResult();

        return array_map('intval', $ids);
    }

    /**
     * Lücke schließen, die ein endgültig gelöschter Datensatz bei seinen Geschwistern
     * hinterlässt (Adjacency-List-Positionierung).
     *
     * Wird von Dataset::finalDeleteOne() je gelöschtem Datensatz gerufen - nur für
     * Datensätze aus Bereichen mit Baum. lft, rgt und depth bleiben dabei außen vor -
     * die richtet der Aufrufer mit EINEM rebuildTree()-Lauf am Ende des gesamten
     * Vorgangs.
     *
     * @param array $data  Datensatz mit parent_id / position / area_id
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
     * lft, rgt, depth und parent_ids sofort neu berechnen - INNERHALB der laufenden
     * Transaktion des Aufrufers.
     *
     * Dataset ruft das einmal am Ende von purge() bzw. finalDelete(), nachdem alle
     * Datensätze einzeln entfernt wurden. Der Umweg über eine eigene Methode existiert,
     * weil rebuildTree() private ist.
     *
     * Nicht zu verwechseln mit externalRebuildTree(): das öffnet eine EIGENE
     * Transaktion und ist für Aufrufer gedacht, die noch keine haben (Reparaturwerkzeuge).
     *
     * @param int $areaId  Inhaltsbereich, dessen Baum neu berechnet wird
     */
    public function rebuildTreeNow(int $areaId = 0): void
    {
        if (!$this->isTreeArea($areaId)) return;
        $this->rebuildTree($areaId);
    }

    /**
     * Löschen ohne Kinder (withChilds == false): direkte Kinddatensätze dem Parent des
     * betroffenen Datensatzes zuweisen, damit sie nicht verwaisen. Gilt für Soft-Delete
     * und endgültiges Löschen gleichermaßen; gerufen aus Dataset::collectIds().
     * Unterschied zum echten Löschen: der markierte Datensatz verbleibt als
     * (deleted) Blatt im Baum und behält seine Position; die Kinder werden
     * direkt HINTER ihm beim Parent einsortiert (keine Lücke zu schließen).
     * Als deleted markierte Kinder laufen dabei mit.
     * Anschließend wird der Baum des Bereichs neu berechnet (lft, rgt, depth, parent_ids).
     * Läuft innerhalb der Transaktion des Aufrufers (Dataset::delete() / purge()).
     *
     * @param array $data  Bisherige Inhalte des zu markierenden Datensatzes
     */
    public function liftChildren(array $data): void
    {
        $idField  = $this->tableName . '_id';
        $id       = (int)($data[$idField]    ?? 0);
        $parentId = (int)($data['parent_id'] ?? 0);
        $position = (int)($data['position']  ?? 0);
        $areaId   = (int)($data['area_id']   ?? 0);
        if ($id === 0) return;
        // Direkte Kinder ermitteln (inklusive deleted, ohne tmp-Kopien)
        $sql = "SELECT `{$idField}` FROM `{$this->tableName}` WHERE `parent_id` = ?";
        $sql .= $this->treeFilter();
        $sql .= " ORDER BY `position` ASC";
        $this->db->query($sql, 'i', [$id], true);
        $children = $this->db->fetchAll(); // flaches Array
        $this->db->free();
        $count = count($children);
        if ($count === 0) return;
        // Platz hinter dem markierten Datensatz schaffen:
        // nachfolgende Geschwister um die Anzahl der Kinder nach hinten schieben
        $bindTypes = 'i';
        $values    = [$count];
        $sql = "UPDATE `{$this->tableName}` SET `position` = `position` + ?"
             . " WHERE " . $this->parentCondition($parentId, $areaId, $bindTypes, $values)
             . " AND `position` > ?";
        $bindTypes .= 'i';
        $values[]   = $position;
        $sql .= $this->treeFilter();
        $this->db->query($sql, $bindTypes, $values);
        // Kinder umhängen: neuer Parent, Positionen direkt hinter dem Datensatz
        // (Reihenfolge der Kinder untereinander bleibt erhalten)
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
        // lft, rgt, depth, parent_ids neu berechnen (Kinder sind eine Ebene hochgerückt)
        // (Database erlaubt verschachtelte Transaktionen, siehe rebuildTree())
        $this->rebuildTree($areaId);
    }

    /**
     * Kinder eines Knotens kopieren und sie an einen neuen (leeren) Knoten anhängen.
     * Nach Duplizieren eines Datensatzes.
     * Als deleted markierte Kinder werden nicht mitkopiert.
     *
     * @param int $idFrom  Id des Quell-Datensatzes
     * @param int $idTo    Id des Ziel-Datensatzes
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
     * Führt eine rekursive Kopie eines Teilbaums durch.
     * Als deleted markierte Datensätze werden übersprungen.
     *
     * @param int $sourceParentId  Id des Quell-Parent-Datensatzes
     * @param int $targetParentId  Id des Ziel-Parent-Datensatzes
     * @param int $areaId          Inhaltsbereich des ZIEL-Knotens
     */
    private function copySubtree(int $sourceParentId, int $targetParentId, int $areaId): void
    {
        // Der Quell-Parent ist immer ein echter Knoten - mit 0 (Wurzelebene) wäre die
        // Auswahl nicht auf einen Bereich eingegrenzt.
        if ($sourceParentId < 1) return;
        $bindTypes = '';
        $values    = [];
        // Quelle: der Bereich ergibt sich aus dem Quell-Parent selbst
        $sql = "SELECT `{$this->tableName}_id` FROM `{$this->tableName}`"
             . " WHERE " . $this->parentCondition($sourceParentId, 0, $bindTypes, $values);
        $sql .= $this->treeFilter(true); // deleted nicht mitkopieren
        $sql .= " ORDER BY `position` ASC";
        
        $this->db->query($sql, $bindTypes, $values, true);
        $children = $this->db->fetchAll();
        $this->db->free();
        $idField       = $this->tableName . '_id';
        $previousNewId = 0; // erster Eintrag hat keinen Vorgänger
        foreach ($children as $childId) {
            $data = $this->load($childId);
            if (!$data) continue;
            $data[$idField]      = 0;               // wird neu vergeben
            $data['parent_id']   = $targetParentId; // neuer Parent
            $data['previous_id'] = $previousNewId;  // Vorgänger im Zielbaum
            $newChildId = $this->save([], $data);
            if (!$newChildId) continue;
            // Für den nächsten Durchlauf: dieser neue Datensatz ist der Vorgänger
            $previousNewId = $newChildId;
            // Rekursiv tiefer in den Baum gehen
            $this->copySubtree($childId, $newChildId, $areaId);
        }
    }

    /**
     * Prüft, ob ein Knoten existiert und wirft andernfalls eine Exception.
     * Als deleted markierte Knoten gelten als nicht wählbar
     * (sie stehen in den Select-Listen der Eingabemasken nicht zur Auswahl).
     * Mit $areaId wird zugleich geprüft, dass der Knoten im SELBEN Inhaltsbereich
     * liegt - ein Zweig darf nie über Bereichsgrenzen hinweg hängen.
     *
     * @param int    $nodeId  Id des zu prüfenden Knotens (0 wird übersprungen)
     * @param string $name    Feldname für die Fehlermeldung ('parent_id', 'previous_id')
     * @param int    $areaId  Inhaltsbereich, in dem der Knoten liegen muss (0: egal)
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
     * Positionen EINER Ebene (Geschwister unter $parentId) auf 1..n bringen, sofern
     * sie es nicht schon sind.
     *
     * Alle Positionsrechnungen (calculatePosition(), shiftPositions*(),
     * getPreviousId()) setzen eine lückenlose Zählung ab 1 voraus. Altbestände,
     * direkte Datenbank-Eingriffe oder Datensätze, die ohne `position`-Feld angelegt
     * wurden, tragen aber Doppelbelegungen, Lücken oder 0 - dort erzeugte jedes
     * Umsortieren neue Doppelbelegungen (z. B. 0,0,0,1). Die bisherige Reihenfolge
     * bleibt erhalten (Gleichstand: nach Id). Als deleted markierte Datensätze
     * zählen mit (sie belegen Positionen), Arbeitskopien nicht.
     *
     * @param int $parentId   Id des Parent-Datensatzes oder 0
     * @param int $areaId     Inhaltsbereich
     * @param int $excludeId  Datensatz, der nicht mitgezählt wird (0: keiner)
     * @return bool           true, wenn Positionen umgeschrieben wurden
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
     * Aktuelle `position` eines Datensatzes aus der Datenbank.
     *
     * @param int $id        `{tableName}_id`
     * @param int $fallback  Rückgabe, wenn der Datensatz fehlt
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
     * Hilfsmethode zur Ermittlung der numerischen Position anhand der previous_id.
     * Berücksichtigt parent_id und area_id, um Fehlzuordnungen zwischen Zweigen
     * und zwischen Bereichen zu verhindern.
     * Als deleted markierte Datensätze belegen weiterhin Positionen und
     * werden daher NICHT herausgefiltert.
     *
     * @param int $parentId    Id des Parent-Datensatzes oder 0
     * @param int $previousId  Id des vorangehenden Datensatzes oder 0
     * @param int $areaId      Inhaltsbereich
     */
    private function calculatePosition(int $parentId, int $previousId, int $areaId = 0): int
    {
        // Kein Vorgänger angegeben → erstes Element im Zweig
        if ($previousId === 0) return 1;
        // Position des Vorgängers laden – dabei prüfen ob er wirklich zum Parent gehört
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
        // Fallback: previous_id gehört nicht zum parent → ans Ende des Zweigs anhängen
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
     * Geschwister ab $fromPosition um +1 verschieben (Platz schaffen).
     * Als deleted markierte Datensätze laufen dabei mit.
     *
     * @param int $parentId      Id des Parent-Datensatzes oder 0
     * @param int $fromPosition  Zielposition (ab hier wird verschoben)
     * @param int $excludeId     Eigene Id (wird ausgeschlossen)
     * @param int $areaId        Inhaltsbereich - auf der Wurzelebene das einzige
     *                           Merkmal, das die Bereiche auseinanderhält
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
     * Geschwister nach $fromPosition um -1 verschieben (Lücke schließen).
     * Als deleted markierte Datensätze laufen dabei mit.
     *
     * @param int $parentId      Id des Parent-Datensatzes oder 0
     * @param int $fromPosition  Bisherige Position (ab hier+1 wird verschoben)
     * @param int $excludeId     Eigene Id (wird ausgeschlossen)
     * @param int $areaId        Inhaltsbereich (siehe shiftPositionsDown())
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
     * Baum EINES Inhaltsbereichs neu aufbauen (lft, rgt, depth, parent_ids).
     * Liest position + parent_id aus der DB und berechnet daraus
     * das komplette Nested Set in einem Durchlauf.
     * Als deleted markierte Datensätze verbleiben im Baum und
     * erhalten daher ebenfalls aktuelle lft/rgt/depth-Werte.
     *
     * `lft` beginnt in jedem Bereich wieder bei 1, `depth` bei 0.
     *
     * Datensätze, deren parent_id auf einen Knoten außerhalb der gelesenen Menge
     * zeigt (Datenfehler, bereichsübergreifende parent_id), werden als Wurzelknoten
     * behandelt - sie fielen sonst aus dem Baum und behielten alte lft/rgt-Werte.
     *
     * @param int $areaId  Inhaltsbereich; bei Tabellen ohne `area_id` ohne Bedeutung
     */
    private function rebuildTree(int $areaId = 0): void
    {
        if ($this->hasAreaId && $areaId < 1) return;

        // FOR UPDATE nur innerhalb einer laufenden Transaktion
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

        $sql = "SELECT `{$this->tableName}_id`, `parent_id` FROM `{$this->tableName}`"
             . $where
             . " ORDER BY `parent_id` ASC, `position` ASC, `{$this->tableName}_id` ASC" . $forUpdate;
        $this->db->query($sql, $bindTypes, $values);
        
        $rows = $this->db->fetchAll();
        $this->db->free();
        $idField = $this->tableName . '_id';
        $tree    = [];
        $known   = [];
        foreach ($rows as $row) {
            $known[(int)$row[$idField]] = true;
        }
        foreach ($rows as $row) {
            $pId = (int)($row['parent_id'] ?? 0);
            // Parent außerhalb der Menge (anderer Bereich, Datenfehler) -> Wurzel
            if ($pId > 0 && !isset($known[$pId])) $pId = 0;
            $tree[$pId][] = (int)$row[$idField];
        }
        $counter = 1;
        $updates = [];
        $traverse = function (int $nodeId, int $depth, array $parentIds) use (&$traverse, &$tree, &$counter, &$updates): void {
            $lft = $counter++;
            // Für die Kinder dieses Knotens: Aktuellen Pfad kopieren und eigene ID anhängen
            $nextParentIds = $parentIds;
            $nextParentIds[] = $nodeId;
            if (isset($tree[$nodeId])) {
                foreach ($tree[$nodeId] as $childId) {
                    $traverse($childId, $depth + 1, $nextParentIds);
                }
            }
            $updates[] = [
                'id'         => $nodeId,
                'lft'        => $lft,
                'rgt'        => $counter++,
                'depth'      => $depth,
                'parent_ids' => json_encode($parentIds) // Array als JSON speichern
            ];
        };
        if (isset($tree[0])) {
            foreach ($tree[0] as $rootId) {
                // Wurzelknoten: Tiefe 0, keine übergeordneten Elemente
                $traverse($rootId, 0, []);
            }
        }
        if (!empty($updates)) {
            // DB-Klasse erlaubt verschachtelte Transaktionen
            $this->db->beginTransaction();
            try {
                // Prepared Statement statt riesiger SQL-String
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
     * Öffentlicher Zugriff auf rebuildTree() für externe Aufrufe (z. B. Migrations-Skripte).
     * Öffnet eine eigene Transaktion.
     *
     * @param int $areaId  Inhaltsbereich; bei Tabellen ohne `area_id` ohne Bedeutung
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
     * Baumfelder eines Inhaltsbereichs zurücksetzen.
     *
     * Gerufen, wenn das Eingabefeld `parent_id` aus einem Bereich entfernt wird
     * (Field::hookOnDeleted()): der Bereich ist danach flach, also müssen auch seine
     * Datensätze flach sein - sonst blieben parent_id-Verweise und lft/rgt-Grenzen
     * stehen, die niemand mehr pflegt und über die weiterhin gefiltert würde.
     *
     * `position` bleibt erhalten, wird aber in der bisherigen ANZEIGEREIHENFOLGE des
     * Baums (lft) neu durchnummeriert: die Reihenfolge, die der Redakteur zuletzt
     * gesehen hat, überlebt damit den Umbau. Ein Bereich ohne `position`-Feld nutzt
     * die Werte schlicht nicht.
     *
     * @param int $areaId  Inhaltsbereich
     */
    public function resetTreeFields(int $areaId): void
    {
        if (!$this->hasParentId || !$this->hasAreaId || $areaId < 1) return;

        $tableName = $this->tableName;
        $idField   = $tableName . '_id';

        $tmpFilter = $this->hasTmp ? " AND `tmp` = 0" : '';

        $this->db->pushResult();
        // 1. position in der bisherigen Baum-Reihenfolge neu vergeben (1..n)
        $sql = "UPDATE `{$tableName}` AS `t`"
             . " JOIN ("
             . "   SELECT `{$idField}` AS `id`,"
             . "          ROW_NUMBER() OVER (ORDER BY `lft` ASC, `{$idField}` ASC) AS `pos`"
             . "   FROM `{$tableName}` WHERE `area_id` = ?" . $tmpFilter
             . " ) AS `n` ON `n`.`id` = `t`.`{$idField}`"
             . " SET `t`.`position` = `n`.`pos`";
        $this->db->query($sql, 'i', [$areaId]);

        // 2. Baumfelder neutralisieren
        $sql = "UPDATE `{$tableName}` SET `parent_id` = NULL, `lft` = 0, `rgt` = 0,"
             . " `depth` = 0, `parent_ids` = '[]' WHERE `area_id` = ?";
        $this->db->query($sql, 'i', [$areaId]);
        $this->db->popResult();

        $this->clearTreeAreaCache();
    }

    /**
     * Baum eines Inhaltsbereichs erstmalig aufbauen.
     *
     * Gegenstück zu resetTreeFields(): gerufen, wenn im Bereich ein Eingabefeld
     * `parent_id` angelegt wurde (Field::hookOnSaved()). Alle vorhandenen Datensätze
     * sind zunächst Wurzelknoten; ihre `position` wird in der bisherigen Sortierung
     * eindeutig gemacht (sonst wäre die Reihenfolge der Wurzeln zufällig), danach
     * werden lft/rgt/depth/parent_ids erstmalig berechnet.
     *
     * @param int $areaId  Inhaltsbereich
     */
    public function initTreeArea(int $areaId): void
    {
        if (!$this->hasParentId || !$this->hasAreaId || $areaId < 1) return;

        $this->clearTreeAreaCache();
        $this->normalizePositions($areaId);
        $this->rebuildTree($areaId);
    }

    /**
     * `position` eines Inhaltsbereichs lückenlos neu vergeben: je Elternknoten 1..n,
     * in der bisherigen Reihenfolge.
     *
     * Die Reihenfolge bleibt dabei erhalten - es verschwinden nur Lücken und
     * Doppelbelegungen. Gebraucht wird das, wenn ein Bereich neu einen Baum bekommt
     * (initTreeArea()) und beim einmaligen Umstellen von Altbeständen auf die
     * bereichsweise Zählung (Core/cron/rebuild_trees.php).
     *
     * @param int $areaId  Inhaltsbereich
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
     * Hilfsfunktion: den vorangehenden Datensatz bei gleichem Parent finden
     * Als deleted markierte Datensätze werden übersprungen:
     * sie verbleiben zwar im Baum (belegen Positionen), stehen aber in den
     * Select-Listen (previous_id) der Eingabemasken nicht zur Auswahl.
     * Dadurch wird previous_id auch dann korrekt ermittelt, wenn Datensätze
     * zwischenzeitlich deleted markiert und wiederhergestellt wurden.
     * 
     * @param int $parentId  Id des eigenen Parents oder 0 (parent_id IS NULL)
     * @param int $position  eigene Position
     * @param int $areaId    Inhaltsbereich - auf der Wurzelebene zwingend, sonst
     *                       käme der Vorgänger womöglich aus einem fremden Bereich
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
        $sql .= $this->treeFilter(true); // deleted überspringen
        $sql .= " ORDER BY `position` DESC LIMIT 1";
        $this->db->query($sql, $bindTypes, $values);
        if ($this->db->num_rows > 0) return (int) $this->db->fetchNum()[0];
        else return 0;
    }
    
    /**
     * Ermittelt die IDs aller übergeordneten Datensätze (Vorfahren) eines Knotens
     * in absteigender Reihenfolge (Root -> ... -> Großvater -> Vater),
     * um daraus z. B. eine Pfadangabe (Breadcrumb) zu bilden.
     * * Nutzt das Nested-Set (lft/rgt) für maximale Performance ohne Rekursion.
     * Der lft/rgt-Bereich gilt je Inhaltsbereich, `area_id` läuft deshalb mit.
     * Als deleted markierte Vorfahren verbleiben im Baum und damit im Pfad
     * (das Ergebnis wäre sonst lückenhaft).
     * Gibt null zurück, wenn kein übergeordneter Datensatz existiert.
     *
     * @param array $data  Felder {$tableName_}id, 'lft', 'rgt', 'area_id'
     * @return array|null  Array mit den IDs der Vorfahren oder null
     */
    public function getParentIds(array $data): ?array
    {
        $tableName = $this->tableName;
        $idField = $tableName . '_id';
        $lft = (int)($data['lft'] ?? 0);
        $rgt = (int)($data['rgt'] ?? 0);
        if ($lft < 1 || $rgt <= $lft) {
            return null; // kein Baum (flacher Bereich)
        }
        // Finde alle Vorfahren anhand des Nested Set Modells
        // lft kleiner als eigener lft UND rgt größer als eigener rgt
        // ORDER BY lft ASC sortiert von oben nach unten (Root zuerst, dann Parent)
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
            return null; // Kein Parent vorhanden (Root-Knoten)
        }
        return $ancestors;
    }
}
