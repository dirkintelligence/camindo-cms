<?php
/**
 * Maintenance of the language-dependent content rows when the languages of an instance
 * change.
 *
 * THE RULE ENFORCED HERE
 * --------------------
 * Every record carries, in the data_* tables, in `data_dft` and in `record_cache`,
 * EXACTLY ONE row per field for each language of its instance - for single-language fields
 * with the same value in all languages, for multilingual fields with the fallback value
 * of the primary language where no translation exists (see VirtualFieldsTrait::
 * saveVirtualFields()). Only this way can Cms::select() filter and sort a language with a
 * single JOIN condition (`language_id` = ?).
 *
 * When a record is saved, these rows are created automatically. But if the language
 * list of an instance changes, the rows of the existing data no longer match:
 *
 *     language added     -> addLanguage(): the rows of the primary language are
 *                           copied for the new language (fallback)
 *     language removed   -> removeLanguage(): its rows are deleted,
 *                           its own files likewise
 *     language deleted   -> deleteLanguage(): the same in all instances
 *
 * `record_cache` is not copied in these cases, but discarded for the affected
 * records (clearRecordCache()): the cache needs the marking of whether
 * a value is a fallback, and that is produced when rebuilding in
 * Cms::createRecordCache() by comparison with the primary language.
 *
 * The full text (`data_ftx`) is folded according to the rules of its language
 * (Collation::fold()). Copied rows therefore only match if both
 * languages have the same rules (sameRules()); otherwise the hooks mark it
 * as stale with markFulltextStale() - it is rebuilt via "Update now"
 * in the Area module or the scheduler (FulltextState).
 *
 * All methods run in the transaction of the caller (hooks of the Instance and
 * Language modules) - nothing is committed here.
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

    /** Do two languages fold the full text the same way (Collation::sameRules())? */
    public function sameRules(int $languageIdA, int $languageIdB): bool
    {
        return Collation::sameRules(
            $this->instanceCache->languageCode($languageIdA),
            $this->instanceCache->languageCode($languageIdB)
        );
    }

    /**
     * Mark the full text of all areas of an instance as stale (FulltextState) -
     * do not rebuild it at once, which would tie up the request for large instances.
     */
    public function markFulltextStale(int $instanceId): void
    {
        $this->container->get(FulltextState::class)->markStaleInstance($instanceId);
    }

    /**
     * The data_* tables with a column `language_id`, along with the columns that are
     * carried over when a language is copied (without `language_id`).
     *
     * @return array  table => [columns]
     */
    public function tables(): array
    {
        $tables = [];
        foreach ($this->cms->dataSuffix as $type => $suffix) {
            if (in_array($type, $this->cms->noColumnTypes, true)) continue;
            $table = 'data_' . $suffix;
            if (isset($tables[$table])) continue;
            $tables[$table] = match ($suffix) {
                // Edge table or display type without a table
                // (data_pwd and data_rid have also carried language_id since 2026-09-11)
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
     * New language of an instance: copy the rows of the primary language as fallback.
     *
     * @param int $instanceId
     * @param int $languageId        the language that was added
     * @param int $sourceLanguageId  the primary language whose values are taken over
     */
    public function addLanguage(int $instanceId, int $languageId, int $sourceLanguageId): void
    {
        if ($languageId === $sourceLanguageId) return;
        foreach ($this->tables() as $table => $cols) {
            $colList = '`' . implode('`, `', $cols) . '`';
            $selList = '`d`.`' . implode('`, `d`.`', $cols) . '`';
            // Tables with `empty`: for a MULTILINGUAL field the copy is a
            // fallback from the primary language (empty = 1); for single-language fields
            // simply the same value (empty = 0) - see Cms::hasEmptyFlag()
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
     * Does the data_* table have the column `empty` (Cms::hasEmptyFlag())?
     */
    private function tableHasEmpty(string $table): bool
    {
        foreach ($this->cms->dataSuffix as $type => $suffix) {
            if ('data_' . $suffix === $table) return $this->cms->hasEmptyFlag($type);
        }
        return false;
    }

    /**
     * Language removed from an instance: delete its contents.
     *
     *     files         own files of multilingual file fields in this language
     *                   are deleted (deleteFiles()), including those of deleted
     *                   records in the trash
     *     data_*        all rows of the language (texts, numbers, dates, file entries,
     *                   full text, data_dft) - links (`data_rel`) know no
     *                   language and remain
     *     record_cache  the rows of the language, also in the working state of working copies
     *     privacy_template  privacy templates of the instance in this language
     *
     * Fallback rows of other languages that point to a file of this language (it
     * was primary language before) are fixed by repairFileFallbacks() - to be called as soon as the
     * new language list is in `rel_instance_language`.
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
     * Language deleted system-wide: delete its contents in all instances.
     *
     * The language must not be the primary language anywhere (checked by Language::hookOnDelete()).
     * For each instance that carries it as an additional language, removeLanguage(); afterwards
     * remnants in records of other instances (e.g. after moving) are removed as well.
     * The rows in `rel_instance_language` and `rel_group_language` drop
     * with the language via foreign keys.
     *
     * @return int[]  instances that carried the language
     */
    public function deleteLanguage(int $languageId): array
    {
        $this->db->query("SELECT `instance_id` FROM `rel_instance_language` WHERE `language_id` = ?", 'i', [$languageId]);
        $instanceIds = array_map('intval', $this->db->fetchAll());
        foreach ($instanceIds as $instanceId) {
            $this->removeLanguage($instanceId, $languageId);
        }

        // Remnants outside these instances
        $this->deleteFiles($languageId, null);
        $tables = array_keys($this->tables());
        $tables[] = 'record_cache';
        $tables[] = 'privacy_template';
        foreach ($tables as $table) {
            $this->db->query("DELETE FROM `{$table}` WHERE `language_id` = ?", 'i', [$languageId]);
        }

        // Fallback rows that still point to files of the language
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
     * Delete the own files of a language (multilingual file fields) - in the
     * media directory and, for deleted records, in the trash var/deleted
     * (name see VirtualFieldsTrait::trashFileName()). Afterwards there is no
     * `data_fil` row left by which they could be found.
     *
     * @param int      $languageId
     * @param int|null $instanceId  only records of this instance (null: all)
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
     * Fix fallback rows of multilingual file fields that point to the file of a
     * language the instance no longer carries.
     *
     * As when saving (VirtualFieldsTrait::_syncFileRows()): every language without
     * its own file gets the row of the primary language - if that one has no
     * file of its own either, an empty row. Afterwards `record_cache` of the instance is discarded.
     *
     * @param int   $instanceId
     * @param int[] $excludeIds  languages that are just being dropped but may
     *                           still be in `rel_instance_language`
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
            // file of the primary language
            if ($languageId !== $primaryId) {
                $this->db->query(
                    "INSERT IGNORE INTO `data_fil` (`language_id`, `file_language_id`, {$cols})"
                    . " SELECT ?, `d`.`file_language_id`, {$dCols}" . $from . " AND `d`.`language_id` = ? AND `d`.`file_language_id` = `d`.`language_id`"
                    . " AND `d`.`extension` != ''",
                    'iii', [$languageId, $instanceId, $primaryId]
                );
            }
            // if the primary language has none either: empty
            $this->db->query(
                "INSERT IGNORE INTO `data_fil` (`language_id`, `file_language_id`, {$cols})"
                . " SELECT DISTINCT ?, ?, `d`.`record_id`, `d`.`fieldname_id`, `d`.`field_id`, '', '', '',"
                . " 0, 0, 0, '', 1, '{}'" . $from,
                'iii', [$languageId, $languageId, $instanceId]
            );
        }
        $this->clearRecordCache($instanceId);
    }

    /** Languages of an instance according to the database, primary language first */
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
     * Discard the `record_cache` of all records of an instance - Cms::fetch() rebuilds it
     * on the next access. Working copies remain: their `record_cache` is
     * the working state, not a cache.
     */
    public function clearRecordCache(int $instanceId): void
    {
        $sql = "DELETE `c` FROM `record_cache` `c`"
             . " INNER JOIN `record` `r` ON `r`.`record_id` = `c`.`record_id`"
             . " WHERE `r`.`instance_id` = ? AND `r`.`tmp` = 0";
        $this->db->query($sql, 'i', [$instanceId]);
    }
}
