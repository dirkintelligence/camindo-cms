<?php
/**
 * Stand des Volltexts je Bereich: "veraltet"-Markierung und Sperre des Neuaufbaus.
 *
 * VERALTET STATT SOFORT NEU
 * --------------------
 * Strukturänderungen, nach denen der Volltext eines Bereichs nicht mehr zu den
 * Quellfeldern passt (Quellen eines Volltextfeldes geändert, neues Volltextfeld,
 * Quellfeld gelöscht oder umgewandelt, andere Faltungsregeln einer Sprache ...),
 * bauen ihn nicht sofort für alle Datensätze neu auf - das hielte den Request und
 * bei großen Bereichen den Betrieb auf. Sie setzen `area`.`fulltext_stale_at`
 * (markStale()). Die Detailansicht und die Feldliste des Bereichs zeigen dann den
 * Hinweis mit "Jetzt aktualisieren" (Hintergrundprozess, CLI-Befehl `fulltext`),
 * sonst erledigt es die Zeitplaner-Aufgabe `fulltext`.
 *
 * Ausnahme: die Umwandlung in 'encrypted' baut sofort neu auf (Field::convert()) -
 * sonst stünde der Klartext bis dahin im Suchindex.
 *
 * Die Markierung hält den FRÜHESTEN Zeitpunkt. RecordService::rebuildFulltext()
 * löscht sie nach einem vollständigen Lauf nur, wenn sie nicht jünger ist als dessen
 * Beginn (clear()) - eine Änderung während des Laufs bleibt also stehen.
 *
 * SPERRE
 * --------------------
 * Je Bereich läuft höchstens ein Neuaufbau: var/tmp/fulltext-{area_id}.lock, exklusiv
 * angelegt (fopen 'x' - flock() sperrt auf dem Bind-Mount von Docker Desktop nicht
 * prozessübergreifend). Der Lauf frischt die Datei regelmäßig auf (touch()); bleibt
 * sie LOCK_STALE Sekunden unberührt, gilt der Prozess als abgebrochen und die Sperre
 * wird übernommen.
 *
 * @see Camindo\Core\Modules\Record\RecordService::rebuildFulltext()
 * @see Camindo\Core\Cli\FulltextCommand
 */
namespace Camindo\Core;

class FulltextState {

    /** Sekunden ohne touch(), nach denen eine Sperre als verwaist gilt */
    public const LOCK_STALE = 900;

    /** Gehaltene Sperren dieses Prozesses: area_id => Pfad */
    private array $locks = [];

    public function __construct(
        private Database $db,
        private Config $config,
    ) {}

    // ====================
    // Markierung

    /**
     * Volltext der Bereiche als veraltet markieren (nur Datensatzbereiche; eine
     * bestehende Markierung behält ihren Zeitpunkt).
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

    /** Alle Datensatzbereiche einer Instanz als veraltet markieren */
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
     * Bereiche, deren Volltext ein Feld als Quelle haben kann: sein eigener Bereich
     * (Standard-Volltext), die Bereiche der Volltextfelder, in die es eingeht (auch
     * über ein link-Feld), und die Bereiche mit einem link-Feld auf seinen Bereich
     * (deren Standard-Volltext nimmt die Textfelder der Zielbereiche auf).
     *
     * Vor dem Löschen des Feldes aufzurufen - danach fehlen `field` und
     * `rel_fulltext_text`.
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

    /** Seit wann (UTC, 'Y-m-d H:i:s') der Volltext veraltet ist - null: aktuell */
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

    /** Veraltete Bereiche (für `fulltext --stale`) */
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
     * Markierung nach einem vollständigen Neuaufbau löschen - nur wenn sie nicht
     * jünger ist als dessen Beginn.
     *
     * @param string $startedAt  Beginn des Laufs (UTC, 'Y-m-d H:i:s')
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
    // Sperre

    private function lockFile(int $areaId): string
    {
        return $this->config->get('paths.tmp_dir') . '/fulltext-' . $areaId . '.lock';
    }

    /**
     * Sperre für den Neuaufbau eines Bereichs nehmen.
     *
     * @return bool  false: ein anderer Prozess baut den Bereich gerade neu auf
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
            // verwaist: übernehmen (umbenennen, damit nur EIN Prozess sie bekommt)
            $stale = $file . '.' . getmypid() . '.stale';
            if (@rename($file, $stale)) @unlink($stale);
        }
        return false;
    }

    /** Laufende Sperre auffrischen (während eines langen Laufs) */
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
     * Läuft gerade ein Neuaufbau des Bereichs?
     *
     * @return array|null  ['touched' => Unix-Zeit der letzten Auffrischung] oder null
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
