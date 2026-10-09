<?php
/**
 * Papierkorb: gelöschte Datensätze und Bereiche finden und wiederherstellen.
 * Instanzname im Code: $trash.
 *
 * Das Modul hat keine eigene Tabelle. "Im Papierkorb" ist alles mit `deleted` = 1;
 * wer gelöscht hat und wann, steht in `changed_by` / `changed_at` - Dataset::delete()
 * gibt allen Datensätzen EINES Löschvorgangs exakt denselben Zeitstempel (siehe
 * Dataset::newTimestamp()), und genau daran erkennt Dataset::restore() später, was
 * zusammengehört.
 *
 * WAS IN DER LISTE STEHT: NUR DIE WURZEL EINES VORGANGS
 * --------------------
 * Datensätze
 *     `deleted` = 1, keine Arbeitskopie, der Bereich ist NICHT gelöscht und der
 *     Vaterdatensatz (Baum) auch nicht. Kinder, die mit ihrem Zweig gelöscht wurden,
 *     stehen damit nicht einzeln da - sie kommen beim Wiederherstellen des Zweigs mit
 *     (Record::restore($id, withChilds: true) nimmt genau die Kinder mit demselben
 *     Löschzeitpunkt). Ein Kind, das schon VORHER einzeln gelöscht wurde, taucht
 *     erst wieder auf, wenn sein Vater wiederhergestellt ist - sonst stünde es nach
 *     dem Wiederherstellen unter einem gelöschten Vater.
 *     Ebenso fehlen fest verbundene Inhalte (Feldtyp 'link') eines gelöschten
 *     Datensatzes - LinkTrait::cascadeDependents() holt sie mit ihm zurück.
 *     Ohne 'alle' nur, was der Admin selbst gelöscht hat (`changed_by`), und nur aus
 *     Bereichen, die er lesen darf (Access::areaRead()).
 * Bereiche (nur Superuser)
 *     `deleted` = 1 und der übergeordnete Bereich nicht gelöscht. Ein Bereich nimmt
 *     beim Wiederherstellen seine Unterbereiche und alle Datensätze mit demselben
 *     Löschzeitpunkt mit (Area::hookOnRestore()). Datensätze eines gelöschten
 *     Bereichs erscheinen deshalb nie einzeln.
 *
 * Neueste Löschung zuerst. Endgültig gelöscht wird nach admin.trash_retention_days
 * (Zeitplaner-Aufgabe trash, ToolDatabase::purgeTrash()) - expiresAt() nennt den Tag.
 *
 * WIEDERHERSTELLEN
 * --------------------
 * restore() prüft zuerst, ob der Eintrag für diesen Admin tatsächlich in der Liste
 * stünde (restorable()) - die Id kommt aus dem Request. Danach läuft derselbe Weg wie
 * beim Löschen: Record::restore() bzw. Area::restore() mit Kindern, große Vorgänge
 * ab admin.mass_delete_threshold als Hintergrundprozess (MassDeletion). Die
 * Gruppenrechte prüft Dataset::restoreOneCore() je Datensatz (Löschrecht).
 *
 * @see Camindo\Core\Modules\Trash\TrashBackend  Liste, Widget
 * @see Camindo\Core\Dataset                     delete() / restore()
 * @see Camindo\Core\Modules\Area\Area           hookOnSoftDelete() / hookOnRestore()
 * @see Camindo\Core\MassDeletion                Hintergrundprozess
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
use Camindo\Core\Modules\Record\Record;

class Trash {

    /** Höchstens so viele Einträge je Liste */
    public const MAX_ENTRIES = 200;

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
    )
    {}

    // ====================
    // Listen

    /**
     * Gelöschte Datensätze (nur Wurzeln der Löschvorgänge, siehe Klassenkommentar).
     *
     * @param bool $all  true: alle Admins (nur Superuser, sonst ignoriert)
     * @param int  $limit
     * @return array  Zeilen: record_id, area_id, instance_id, owner_id, changed_by,
     *                changed_at (UTC), children (mitgelöschte Kinder)
     */
    public function records(bool $all = false, int $limit = self::MAX_ENTRIES): array
    {
        [$where, $types, $values] = $this->recordConditions($all);
        $sql = "SELECT `r`.`record_id`, `r`.`area_id`, `r`.`instance_id`, `r`.`owner_id`,"
             . " `r`.`changed_by`, `r`.`changed_at`,"
             // Mitgelöschte Kinder: Teilbaum (lft/rgt je Bereich) mit demselben Zeitstempel
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
     * Gelöschte Bereiche (nur Wurzeln) - nur für Superuser, sonst leer.
     *
     * @return array  Zeilen: area_id, instance_id, type, name, label, icon, changed_by,
     *                changed_at (UTC), subareas, records (mitgelöscht)
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
     * Anzahl der Einträge für das Widget: eigene Datensätze plus - beim Superuser -
     * gelöschte Bereiche. Gezählt werden Wurzeln, also das, was die Liste zeigt.
     *
     * @return array  ['records' => int, 'areas' => int]
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
        if ($this->backend->isSuperuser()) {
            $this->db->query(
                "SELECT COUNT(*) FROM `area` `a` LEFT JOIN `area` `p` ON `p`.`area_id` = `a`.`parent_id`"
                . " WHERE `a`.`deleted` = 1 AND (`p`.`area_id` IS NULL OR `p`.`deleted` = 0)"
            );
            $areas = (int)($this->db->fetchNum()[0] ?? 0);
        }
        $this->db->popResult();
        return ['records' => $records, 'areas' => $areas];
    }

    /**
     * Tag, an dem der Papierkorb-Cronjob einen Eintrag endgültig löscht - in der
     * Zeitzone des Admins, 'Y-m-d H:i:s'. Der Cronjob läuft nachts; gemeint ist
     * "ab diesem Zeitpunkt beim nächsten Lauf".
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
     * `changed_at` (UTC) in der Zeitzone des Admins, 'Y-m-d H:i:s'.
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
    // Wiederherstellen

    /**
     * Steht der Eintrag für den angemeldeten Admin in der Liste? Nur dann darf er
     * über das Modul wiederhergestellt werden.
     *
     * @param string $table  'record' | 'area'
     */
    public function restorable(string $table, int $id): bool
    {
        if ($id < 1) return false;
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

        // Superuser dürfen auch Einträge anderer Admins (Liste "alle")
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
     * Einen Eintrag samt seinem Zweig bzw. seinen Datensätzen wiederherstellen.
     *
     * @param string $table  'record' | 'area'
     * @return string        'restored' | 'background' | 'failed' (Meldung in Cms::errors)
     *                       | 'denied' (nicht in der Liste des Admins)
     */
    public function restore(string $table, int $id): string
    {
        if (!$this->restorable($table, $id)) return 'denied';

        // Große Vorgänge in den Hintergrund - derselbe Weg wie beim Löschen
        $count = $this->massDeletion->affectedCount($table, $id, true, 'restore');
        if ($this->massDeletion->needsBackground($count)) {
            $error = '';
            if ($this->massDeletion->start($table, $id, 'restore', true, $error)) {
                return 'background';
            }
            // Start fehlgeschlagen -> im Request weitermachen
            $this->system->log('massdelete-errors', "restore {$table} {$id}: {$error}");
        }

        $dataset = ($table === 'area') ? $this->area : $this->record;
        return $dataset->restore($id, true) ? 'restored' : 'failed';
    }

    // ====================

    /**
     * Feldtypen fest verbundener Inhalte (Cms::$dependentTypes) als SQL-Liste.
     */
    private function dependentTypeList(): string
    {
        $types = array_map(fn($t) => preg_replace('/[^a-z0-9_]/', '', (string)$t), $this->cms->dependentTypes);
        return $types ? "'" . implode("','", $types) . "'" : "''";
    }

    /**
     * WHERE-Bedingung der Datensatzliste (Aliase r = record, a = area, p = Vater).
     *
     * @param bool $all  true: alle Admins - nur für Superuser wirksam
     * @return array     [sql, bindTypes, values]
     */
    private function recordConditions(bool $all): array
    {
        $where = "`r`.`tmp` = 0 AND `r`.`deleted` = 1"
               . " AND (`p`.`record_id` IS NULL OR `p`.`deleted` = 0)"
               // Fest verbundener Inhalt (link-Feld) eines gelöschten Besitzers: kommt
               // mit diesem zurück (LinkTrait::cascadeDependents()), steht nicht allein da
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

        // Nur lesbare Bereiche (null = keine Einschränkung: Superuser, ungeladen).
        // Ohne einen einzigen lesbaren Bereich bleibt die Liste leer.
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
