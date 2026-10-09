<?php
/**
 * Pflege der sprachabhängigen Inhaltszeilen, wenn sich die Sprachen einer Instanz
 * ändern.
 *
 * DIE REGEL, DIE HIER DURCHGESETZT WIRD
 * --------------------
 * Jeder Datensatz führt in den data_*-Tabellen, in `data_dft` und in `record_cache`
 * je Sprache seiner Instanz GENAU EINE Zeile pro Feld - bei einsprachigen Feldern mit
 * demselben Wert in allen Sprachen, bei mehrsprachigen Feldern mit dem Fallback-Wert
 * der Hauptsprache, wo keine Übersetzung vorliegt (siehe VirtualFieldsTrait::
 * saveVirtualFields()). Nur so kann Cms::select() eine Sprache mit einer einzigen
 * JOIN-Bedingung (`language_id` = ?) filtern und sortieren.
 *
 * Beim Speichern eines Datensatzes entstehen diese Zeilen von selbst. Ändert sich
 * aber die Sprachliste einer Instanz, stimmen die Zeilen des Bestands nicht mehr:
 *
 *     Sprache kommt hinzu     -> addLanguage(): die Zeilen der Hauptsprache werden
 *                                für die neue Sprache kopiert (Fallback)
 *     Sprache fällt weg       -> removeLanguage(): ihre Zeilen werden gelöscht,
 *                                eigene Dateien ebenso
 *     Sprache wird gelöscht   -> deleteLanguage(): dasselbe in allen Instanzen
 *
 * `record_cache` wird in diesen Fällen nicht kopiert, sondern für die betroffenen
 * Datensätze verworfen (clearRecordCache()): der Cache braucht die Kennzeichnung, ob
 * ein Wert ein Fallback ist, und die entsteht beim Neuaufbau in
 * Cms::createRecordCache() aus dem Vergleich mit der Hauptsprache.
 *
 * Der Volltext (`data_ftx`) ist nach den Regeln seiner Sprache gefaltet
 * (Collation::fold()). Kopierte Zeilen stimmen deshalb nur, wenn beide
 * Sprachen dieselben Regeln haben (sameRules()); sonst markieren die Hooks ihn mit
 * markFulltextStale() als veraltet - neu aufgebaut wird er über "Jetzt aktualisieren"
 * im Modul Area oder den Zeitplaner (FulltextState).
 *
 * Alle Methoden laufen in der Transaktion des Aufrufers (Hooks der Module Instance
 * und Language) - hier wird nichts committet.
 *
 * @see Camindo\Core\Modules\Instance\Instance::hookOnSaved()
 * @see Camindo\Core\Modules\Language\Language::hookOnDelete()
 */
namespace Camindo\Core;


class LanguageData {

    public function __construct(
        private Database $db,
        private Cms $cms,
        private Config $config,
        private InstanceCache $instanceCache,
        private ContainerInterface $container
    ) {}

    /** Falten zwei Sprachen den Volltext gleich (Collation::sameRules())? */
    public function sameRules(int $languageIdA, int $languageIdB): bool
    {
        return Collation::sameRules(
            $this->instanceCache->languageCode($languageIdA),
            $this->instanceCache->languageCode($languageIdB)
        );
    }

    /**
     * Volltext aller Bereiche einer Instanz als veraltet markieren (FulltextState) -
     * nicht sofort neu aufbauen, das hielte bei großen Instanzen den Request auf.
     */
    public function markFulltextStale(int $instanceId): void
    {
        $this->container->get(FulltextState::class)->markStaleInstance($instanceId);
    }

    /**
     * Die data_*-Tabellen mit einer Spalte `language_id` samt der Spalten, die beim
     * Kopieren einer Sprache mitgenommen werden (ohne `language_id`).
     *
     * @return array  tabelle => [spalten]
     */
    public function tables(): array
    {
        $tables = [];
        foreach ($this->cms->dataSuffix as $type => $suffix) {
            if (in_array($type, $this->cms->noColumnTypes, true)) continue;
            $table = 'data_' . $suffix;
            if (isset($tables[$table])) continue;
            $tables[$table] = match ($suffix) {
                // Kantentabelle bzw. Anzeige-Typ ohne Tabelle
                // (data_pwd und data_rid führen seit 2026-09-11 ebenfalls language_id)
                'rel', 'ids' => null,
                'enc' => ['record_id', 'field_id', 'fieldname_id', 'data', 'hash'],
                'fil' => ['file_language_id', 'record_id', 'fieldname_id', 'field_id', 'extension', 'mime_type',
                          'filename', 'size', 'width', 'height', 'token', 'version', 'data'],
                default => ['record_id', 'field_id', 'fieldname_id', 'data'],
            };
        }
        $tables = array_filter($tables);
        $tables['data_dft'] = ['record_id', 'data'];
        return $tables;
    }

    /**
     * Neue Sprache einer Instanz: Zeilen der Hauptsprache als Fallback kopieren.
     *
     * @param int $instanceId
     * @param int $languageId        die hinzugekommene Sprache
     * @param int $sourceLanguageId  die Hauptsprache, deren Werte übernommen werden
     */
    public function addLanguage(int $instanceId, int $languageId, int $sourceLanguageId): void
    {
        if ($languageId === $sourceLanguageId) return;
        foreach ($this->tables() as $table => $cols) {
            $colList = '`' . implode('`, `', $cols) . '`';
            $selList = '`d`.`' . implode('`, `d`.`', $cols) . '`';
            // Tabellen mit `empty`: die Kopie ist bei einem MEHRSPRACHIGEN Feld ein
            // Fallback aus der Hauptsprache (empty = 1), bei einsprachigen Feldern
            // schlicht derselbe Wert (empty = 0) - siehe Cms::hasEmptyFlag()
            $emptyCol = $emptySel = '';
            $join = '';
            if ($this->tableHasEmpty($table)) {
                $emptyCol = ', `empty`';
                $emptySel = ', `f`.`multilingual`';
                $join = " INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id`";
            }
            $sql = "INSERT IGNORE INTO `{$table}` (`language_id`, {$colList}{$emptyCol})"
                 . " SELECT ?, {$selList}{$emptySel} FROM `{$table}` `d`"
                 . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`" . $join
                 . " WHERE `r`.`instance_id` = ? AND `d`.`language_id` = ?";
            $this->db->query($sql, 'iii', [$languageId, $instanceId, $sourceLanguageId]);
        }
        $this->clearRecordCache($instanceId);
    }

    /**
     * Führt die data_*-Tabelle die Spalte `empty` (Cms::hasEmptyFlag())?
     */
    private function tableHasEmpty(string $table): bool
    {
        foreach ($this->cms->dataSuffix as $type => $suffix) {
            if ('data_' . $suffix === $table) return $this->cms->hasEmptyFlag($type);
        }
        return false;
    }

    /**
     * Sprache aus einer Instanz entfernt: ihre Inhalte löschen.
     *
     *     Dateien       eigene Dateien mehrsprachiger Datei-Felder in dieser Sprache
     *                   werden gelöscht (deleteFiles()), auch die gelöschter
     *                   Datensätze im Papierkorb
     *     data_*        alle Zeilen der Sprache (Texte, Zahlen, Daten, Dateieinträge,
     *                   Volltext, data_dft) - Verknüpfungen (`data_rel`) kennen keine
     *                   Sprache und bleiben
     *     record_cache  die Zeilen der Sprache, auch im Arbeitsstand von Arbeitskopien
     *     privacy_template  Datenschutz-Vorlagen der Instanz in dieser Sprache
     *
     * Fallback-Zeilen anderer Sprachen, die auf eine Datei dieser Sprache zeigen (sie
     * war früher Hauptsprache), richtet repairFileFallbacks() - aufzurufen, sobald die
     * neue Sprachliste in `rel_instance_language` steht.
     */
    public function removeLanguage(int $instanceId, int $languageId): void
    {
        $this->deleteFiles($languageId, $instanceId);

        foreach (array_keys($this->tables()) as $table) {
            $sql = "DELETE `d` FROM `{$table}` `d`"
                 . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
                 . " WHERE `r`.`instance_id` = ? AND `d`.`language_id` = ?";
            $this->db->query($sql, 'ii', [$instanceId, $languageId]);
        }
        $sql = "DELETE `c` FROM `record_cache` `c`"
             . " INNER JOIN `record` `r` ON `r`.`record_id` = `c`.`record_id`"
             . " WHERE `r`.`instance_id` = ? AND `c`.`language_id` = ?";
        $this->db->query($sql, 'ii', [$instanceId, $languageId]);
        $this->db->query(
            "DELETE FROM `privacy_template` WHERE `instance_id` = ? AND `language_id` = ?",
            'ii', [$instanceId, $languageId]
        );
    }

    /**
     * Sprache wird systemweit gelöscht: ihre Inhalte in allen Instanzen löschen.
     *
     * Die Sprache darf nirgends Hauptsprache sein (prüft Language::hookOnDelete()).
     * Je Instanz, die sie als weitere Sprache führt, removeLanguage(); danach werden
     * Reste in Datensätzen anderer Instanzen (etwa nach dem Verschieben) ebenso
     * entfernt. Die Zeilen in `rel_instance_language` und `rel_group_language` fallen
     * per Fremdschlüssel mit der Sprache.
     *
     * @return int[]  Instanzen, die die Sprache geführt haben
     */
    public function deleteLanguage(int $languageId): array
    {
        $this->db->query("SELECT `instance_id` FROM `rel_instance_language` WHERE `language_id` = ?", 'i', [$languageId]);
        $instanceIds = array_map('intval', $this->db->fetchAll());
        foreach ($instanceIds as $instanceId) {
            $this->removeLanguage($instanceId, $languageId);
        }

        // Reste ausserhalb dieser Instanzen
        $this->deleteFiles($languageId, null);
        $tables = array_keys($this->tables());
        $tables[] = 'record_cache';
        $tables[] = 'privacy_template';
        foreach ($tables as $table) {
            $this->db->query("DELETE FROM `{$table}` WHERE `language_id` = ?", 'i', [$languageId]);
        }

        // Fallback-Zeilen, die noch auf Dateien der Sprache zeigen
        $this->db->query(
            "SELECT DISTINCT `r`.`instance_id` FROM `data_fil` `d`"
            . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
            . " WHERE `d`.`file_language_id` = ?",
            'i', [$languageId]
        );
        $repair = array_map('intval', $this->db->fetchAll());
        foreach (array_unique(array_merge($instanceIds, $repair)) as $instanceId) {
            $this->repairFileFallbacks($instanceId, [$languageId]);
        }
        return $instanceIds;
    }

    /**
     * Eigene Dateien einer Sprache (mehrsprachige Datei-Felder) löschen - im
     * Medienverzeichnis und, bei gelöschten Datensätzen, im Papierkorb var/deleted
     * (Name siehe VirtualFieldsTrait::trashFileName()). Danach gibt es keine
     * `data_fil`-Zeile mehr, über die sie sich finden ließen.
     *
     * @param int      $languageId
     * @param int|null $instanceId  nur Datensätze dieser Instanz (null: alle)
     */
    private function deleteFiles(int $languageId, ?int $instanceId): void
    {
        $sql = "SELECT `d`.`field_id`, `d`.`record_id`, `d`.`extension`, `d`.`version` FROM `data_fil` `d`"
             . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
             . " WHERE `d`.`language_id` = ? AND `d`.`file_language_id` = ? AND `d`.`extension` != ''";
        $types = 'ii';
        $values = [$languageId, $languageId];
        if ($instanceId !== null) {
            $sql .= " AND `r`.`instance_id` = ?";
            $types .= 'i';
            $values[] = $instanceId;
        }
        $this->db->query($sql, $types, $values);
        $mediaDir   = $this->config->get('paths.media_dir');
        $deletedDir = $this->config->get('paths.var_dir') . '/deleted';
        foreach ($this->db->fetchAll() as $row) {
            $fieldId  = (int)$row['field_id'];
            $recordId = (int)$row['record_id'];
            @unlink("{$mediaDir}/" . $this->cms->mediaPath($fieldId, $recordId, $languageId, (string)$row['extension']));
            @unlink($deletedDir . '/' . $fieldId . '_' . $this->cms->mediaBaseName($recordId, $languageId)
                . '_' . (int)$row['version'] . '.' . $row['extension']);
        }
    }

    /**
     * Fallback-Zeilen mehrsprachiger Datei-Felder richten, die auf die Datei einer
     * Sprache zeigen, die die Instanz nicht mehr führt.
     *
     * Wie beim Speichern (VirtualFieldsTrait::_syncFileRows()): jede Sprache ohne
     * eigene Datei bekommt die Zeile der Hauptsprache - hat auch die keine eigene
     * Datei, eine leere Zeile. Danach wird `record_cache` der Instanz verworfen.
     *
     * @param int   $instanceId
     * @param int[] $excludeIds  Sprachen, die gerade wegfallen, aber noch in
     *                           `rel_instance_language` stehen können
     */
    public function repairFileFallbacks(int $instanceId, array $excludeIds = []): void
    {
        $languageIds = array_values(array_diff($this->instanceLanguageIds($instanceId), $excludeIds));
        if (empty($languageIds)) return;
        $primaryId = $languageIds[0];
        $in = implode(',', array_map('intval', $languageIds));
        $from = " FROM `data_fil` `d`"
              . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
              . " INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id`"
              . " WHERE `r`.`instance_id` = ? AND `f`.`multilingual` = 1";

        $this->db->query(
            "DELETE `d`" . $from . " AND `d`.`file_language_id` IS NOT NULL AND `d`.`file_language_id` NOT IN ({$in})",
            'i', [$instanceId]
        );
        if ($this->db->affected_rows < 1) return;

        $colNames = ['record_id', 'fieldname_id', 'field_id', 'extension', 'mime_type', 'filename',
                     'size', 'width', 'height', 'token', 'version', 'data'];
        $cols  = '`' . implode('`, `', $colNames) . '`';
        $dCols = '`d`.`' . implode('`, `d`.`', $colNames) . '`';
        foreach ($languageIds as $languageId) {
            // Datei der Hauptsprache
            if ($languageId !== $primaryId) {
                $this->db->query(
                    "INSERT IGNORE INTO `data_fil` (`language_id`, `file_language_id`, {$cols})"
                    . " SELECT ?, `d`.`file_language_id`, {$dCols}" . $from . " AND `d`.`language_id` = ? AND `d`.`file_language_id` = `d`.`language_id`"
                    . " AND `d`.`extension` != ''",
                    'iii', [$languageId, $instanceId, $primaryId]
                );
            }
            // hat auch die Hauptsprache keine: leer
            $this->db->query(
                "INSERT IGNORE INTO `data_fil` (`language_id`, `file_language_id`, {$cols})"
                . " SELECT DISTINCT ?, ?, `d`.`record_id`, `d`.`fieldname_id`, `d`.`field_id`, '', '', '',"
                . " 0, 0, 0, '', 1, '{}'" . $from,
                'iii', [$languageId, $languageId, $instanceId]
            );
        }
        $this->clearRecordCache($instanceId);
    }

    /** Sprachen einer Instanz laut Datenbank, Hauptsprache zuerst */
    private function instanceLanguageIds(int $instanceId): array
    {
        $this->db->query(
            "SELECT `primary_language_id` FROM `instance` WHERE `instance_id` = ?"
            . " UNION ALL (SELECT `language_id` FROM `rel_instance_language` WHERE `instance_id` = ?"
            . " ORDER BY `position`)",
            'ii', [$instanceId, $instanceId]
        );
        return array_values(array_unique(array_map('intval', $this->db->fetchAll())));
    }

    /**
     * `record_cache` aller Datensätze einer Instanz verwerfen - Cms::fetch() baut ihn
     * beim nächsten Zugriff neu auf. Arbeitskopien bleiben: ihr `record_cache` ist
     * der Arbeitsstand, kein Cache.
     */
    public function clearRecordCache(int $instanceId): void
    {
        $sql = "DELETE `c` FROM `record_cache` `c`"
             . " INNER JOIN `record` `r` ON `r`.`record_id` = `c`.`record_id`"
             . " WHERE `r`.`instance_id` = ? AND `r`.`tmp` = 0";
        $this->db->query($sql, 'i', [$instanceId]);
    }
}
