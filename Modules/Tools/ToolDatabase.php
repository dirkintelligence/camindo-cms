<?php
/**
 * Datenbankpflege: Papierkorb leeren, Arbeitskopien verwerfen.
 *
 * purgeTrash() entfernt Datensätze endgültig, die seit mindestens einer Anzahl Tagen
 * (Vorgabe admin.trash_retention_days, Standard 7) als gelöscht markiert sind. Das
 * ist die EINZIGE Stelle im System, die Inhalte unwiderruflich löscht - der
 * Administrator löscht immer nur soft.
 *
 * purgeTmp() entfernt Arbeitskopien (record.tmp = 1), die seit einer Anzahl Stunden
 * nicht mehr angefasst wurden - liegen gebliebene Bearbeitungen (Browser zu,
 * Sitzung abgelaufen). Mit ihnen fällt der Edit-Lock des Originals (getLock()
 * leitet ihn aus der Arbeitskopie ab).
 *
 * ZWEI EINSTIEGE, EIN CODE
 * --------------------
 *     SchedulerRunner, Typ trash              Zeitplaner-Aufgabe (der Normalfall)
 *     Modules/Tools/backend/tool_delete.php   Reiter Datenbank: "älter als N Tage"
 *     Modules/Tools/backend/tool_tmp_delete.php  Reiter Datenbank: "älter als N Std."
 * Alle rufen dieselben Methoden; die Backend-Werkzeuge geben die Frist vor
 * (0 = alles), der Cronjob nimmt die Einstellung.
 *
 * ADMINS BLEIBEN
 * --------------------
 * Verarbeitet werden nur `record` und `area`. Als gelöscht markierte Administratoren
 * (admin.deleted = 1) werden NIE endgültig entfernt - weder hier noch im Cronjob:
 * ihre Id steht in created_by/changed_by/owner_id der Inhalte und bleibt so
 * auflösbar.
 *
 * ARBEITSWEISE
 * --------------------
 * Verarbeitet wird paketweise (CHUNK_SIZE Datensätze je Runde), damit weder Speicher
 * noch Laufzeit aus dem Ruder laufen. Jeder Datensatz läuft EINZELN durch
 * Record::finalDelete() bzw. Area::finalDelete() - nur so laufen die Hooks, die die
 * Mediendateien aus var/deleted entfernen.
 *
 * REIHENFOLGE
 * --------------------
 * Erst die Datensätze (`record`), dann die Inhaltsbereiche (`area`). Ein Bereich, der
 * zur Löschung ansteht, räumt seine restlichen Datensätze in seinem eigenen
 * hookOnDelete() ohnehin mit ab; die getrennte Runde davor hält die Pakete klein und
 * erwischt auch Datensätze, deren Bereich noch existiert.
 *
 * Der Nested-Set-Baum wird je finalDelete()-Aufruf einmal neu berechnet. Das ist der
 * Preis für die Einzelverarbeitung und der Grund, warum das Werkzeug nachts läuft.
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

    /** Datensätze je Runde - klein genug für Zeitlimit und Speicher */
    private const CHUNK_SIZE = 60;

    /** Sekunden, die eine Runde höchstens brauchen darf (Dateilöschungen können dauern) */
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
     * Papierkorb leeren.
     *
     * @param int|null $days  Mindestalter der Markierung in Tagen; null: Einstellung
     *                        admin.trash_retention_days; 0: alles, was als gelöscht
     *                        markiert ist
     * @return array          ['record' => int, 'area' => int, 'errors' => string[]]
     *                        Anzahl endgültig gelöschter Datensätze je Tabelle
     */
    public function purgeTrash(?int $days = null): array
    {
        if ($days === null) $days = $this->trashRetentionDays();
        if ($days < 0) $days = 0;

        // Stichtag in UTC - changed_at wird ebenfalls in UTC gespeichert
        $expirationDate = gmdate('Y-m-d H:i:s', time() - 86400 * $days);

        $result = ['record' => 0, 'area' => 0, 'errors' => []];

        // Erst die Inhalte, dann ihre Bereiche
        $result['record'] = $this->purgeTable($this->record, $expirationDate, $result['errors']);
        $result['area']   = $this->purgeTable($this->area,   $expirationDate, $result['errors']);

        return $result;
    }

    /**
     * Papierkorb EINES Frontend-Users sofort leeren (Reiter "Papierkorb eines Users",
     * Zusatzrecht trash_user): sein Profil und alle Datensätze mit record.user_id =
     * Profil, soweit sie im Papierkorb liegen - endgültig, samt Dateien. Offene
     * Aufträge der Lösch-Policies (Audit-Log nach Frist) bleiben bestehen.
     *
     * @param int $profileId  record_id des Profils (Bereich vom Typ 'profile')
     * @return array|null     ['record' => int, 'errors' => string[]], null = kein Profil
     */
    public function purgeUserTrash(int $profileId): ?array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT 1 FROM `record` r JOIN `area` a ON a.`area_id` = r.`area_id`"
                . " WHERE r.`record_id` = ? AND r.`tmp` = 0 AND a.`type` = 'profile'", 'i', [$profileId]);
            if (!$this->db->fetchNum()) return null;
            // Blätter zuerst (rgt aufsteigend), wie purgeTable()
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
     * Aufbewahrungsfrist des Papierkorbs (admin.trash_retention_days, Standard 7) -
     * Vorgabe des Cronjobs und des Formulars im Reiter Datenbank.
     */
    public function trashRetentionDays(): int
    {
        return max(0, (int)($this->config->get('admin.trash_retention_days') ?? 7));
    }

    /**
     * Arbeitskopien verwerfen, die seit mindestens $hours Stunden nicht mehr
     * angefasst wurden (record.tmp = 1, changed_at älter als der Stichtag).
     *
     * Wie TemporaryTrait::cancel(), nur ohne Bindung an den Session-Admin: die
     * hochgeladenen Dateien der Arbeitskopie (deleteRecordFiles()) und die Zeile
     * selbst; record_cache und data_rel fallen per FK-Cascade. Arbeitskopien fest
     * verknüpfter Inhalte (link) sind selbst tmp-Zeilen und kommen mit demselben
     * Stichtag an die Reihe.
     *
     * @param int $hours  Mindestalter in Stunden; 0: alle Arbeitskopien
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
     * Eine Tabelle abarbeiten.
     *
     * Holt paketweise die Ids abgelaufener Papierkorb-Einträge und schickt jede einzeln
     * durch finalDelete(). Scheitert ein Datensatz, wird das protokolliert und mit dem
     * nächsten weitergemacht - ein defekter Datensatz soll den nächtlichen Lauf nicht
     * anhalten. Genau deshalb bekommt jeder Datensatz seine eigene Transaktion
     * (finalDelete() öffnet sie selbst).
     *
     * @param Dataset $dataset         Record oder Area
     * @param string  $expirationDate  Stichtag 'Y-m-d H:i:s' in UTC
     * @param array   $errors          Sammelstelle für Fehlermeldungen (per Referenz)
     * @return int                     Anzahl endgültig gelöschter Datensätze
     */
    private function purgeTable(Dataset $dataset, string $expirationDate, array &$errors): int
    {
        $tableName = $dataset->getTableName();
        $idField   = $tableName . '_id';
        $hasTree   = $dataset->hasTree();
        $hasTmp    = isset($dataset->getSchema()['tmp']);
        $deleted   = 0;

        // Ids, die sich nicht löschen ließen: sie stünden sonst in jeder Runde wieder
        // im Paket und würden eine Endlosschleife erzeugen.
        $failedIds = [];

        do {
            set_time_limit(self::CHUNK_TIME_LIMIT);

            // Abgelaufene Papierkorb-Einträge holen.
            // Bei Bäumen Blätter zuerst (rgt aufsteigend): finalDelete() eines Elternteils
            // würde seine Kinder sonst per ON DELETE CASCADE an deren Hooks vorbei
            // mitnehmen. Arbeitskopien stehen nie eigenständig an - sie hängen an ihrem
            // Original und werden von finalDelete() mitgelöscht.
            $sql = "SELECT `{$idField}` FROM `{$tableName}`"
                 . " WHERE `deleted` = 1 AND `changed_at` <= ?";
            if ($hasTmp) $sql .= " AND `tmp` = 0";
            if ($failedIds) {
                // Ids sind hier immer Integer aus der Datenbank - keine Bindung nötig
                $sql .= " AND `{$idField}` NOT IN (" . implode(',', $failedIds) . ")";
            }
            if ($hasTree) $sql .= " ORDER BY `rgt` ASC";
            $sql .= " LIMIT " . self::CHUNK_SIZE;

            $this->db->pushResult();
            $this->db->query($sql, 's', [$expirationDate]);
            $ids = $this->db->fetchAll(); // flaches Array
            $this->db->popResult();

            $found = count($ids);
            if ($found === 0) break;

            foreach ($ids as $id) {
                $id = (int)$id;
                // Jeder Datensatz einzeln - so laufen seine Hooks
                // (Record::hookOnDelete räumt die Mediendateien ab)
                $this->cms->errorsReset();
                if ($dataset->finalDelete($id)) {
                    $deleted++;
                    continue;
                }
                // Gescheiterten Datensatz protokollieren und überspringen - ein
                // defekter Eintrag soll den nächtlichen Lauf nicht anhalten
                $message = "{$tableName} {$id}: " . $this->cms->errorMsg(' | ');
                $errors[]    = $message;
                $failedIds[] = $id;
                $this->system->log('trash', $message);
            }

        } while ($found === self::CHUNK_SIZE);

        return $deleted;
    }
}
