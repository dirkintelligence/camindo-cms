<?php
/**
 * Lösch-Policies ausführen (Modul Privacy). Instanzname im Code: $privacyJobs.
 *
 * Wird ein Datensatz eines Inhaltsbereichs gelöscht, löscht ihn niemand mehr direkt:
 * delete() legt für jeden passenden Block der Policy des Bereichs (DeletePolicy) einen
 * Auftrag in `privacy_job` an - fällig sofort oder nach der eingestellten Frist - und
 * führt die sofort fälligen gleich aus. Die übrigen erledigt runDue() - aus der
 * Zeitplaner-Aufgabe "Lösch-Policies anwenden" (Typ privacy, SchedulerRunner).
 *
 * AUSLÖSER (DeletePolicy::SOURCES)
 * --------------------
 *     record   delete() - jeder Weg: RecordService::delete() (Maske, Stapel, API),
 *              CMS:USER_DELETE (RecordUser::cmsDelete())
 *     profile  ein Profil wurde gelöscht (Auslöser record oder mit seinem Bereich):
 *              immer alle Datensätze mit record.user_id = Profil (triggerLinked())
 *     area     ein Bereich wurde gelöscht (scheduleArea(), aus
 *              Area::hookOnSoftDelete()) - seine Datensätze liegen schon im Papierkorb
 *
 * UNTERDATENSÄTZE
 * --------------------
 * Mit dem Hauptdatensatz gelöschte Kinder im Baum ($withChilds) und fest verknüpfte
 * Inhalte (link, rekursiv) bekommen die Aufträge der Policy IHRES Bereichs - ohne
 * soft_delete und purge (DeletePolicy::REMOVE_ACTIONS): Papierkorb und endgültiges
 * Löschen erledigt der Hauptdatensatz für alle (Teilbaum, cascadeDependents()).
 * Dessen Aufträge tragen dafür 'children' in `fields`.
 *
 * Bei Profil-Datensätzen werden - unabhängig von Policy - sofort die bekannten Geräte
 * und Sitzungen des Users gelöscht.
 *
 * AUFTRÄGE
 * --------------------
 * Jeder Auftrag trägt die Felder seines Blocks bei sich: eine spätere Änderung der
 * Policy wirkt nur auf künftige Löschungen. Bei gleichem Zeitpunkt laufen die Aktionen
 * in der Reihenfolge von DeletePolicy::ACTIONS (Datenbank vor Audit-Log). Scheitert
 * ein Auftrag (etwa weil jemand den Datensatz gerade bearbeitet), bleibt er offen und
 * wird beim nächsten Lauf wiederholt - höchstens MAX_ATTEMPTS mal, danach steht er
 * mit Fehler im Log 'privacy'. Wird ein Datensatz aus dem Papierkorb
 * wiederhergestellt, verfallen seine offenen Aufträge (cancel()).
 *
 * Datenbank-Aktionen laufen im Policy-Modus der Datasets (Dataset::asPolicy()):
 * keine Pflichtfelder, keine Gruppenrechte, im Audit-Log als 'privacy' ohne die
 * bisherigen Werte. Hat audit_set_fields das Audit-Log eines Datensatzes schon
 * überschrieben, schreibt AuditLog::write() jeden späteren Eintrag (etwa 'purge' mit
 * dem letzten Stand) gleich so (auditOverride()).
 *
 * @see Camindo\Core\Modules\Privacy\DeletePolicy
 * @see Camindo\Core\Modules\Privacy\PrivacyBackend::hintHtml()  Hinweis im Löschdialog
 */
namespace Camindo\Core\Modules\Privacy;

use Camindo\Core\AuditLog;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\TriggerTemplates;
use Camindo\Core\Modules\Record\Record;

class PrivacyJobs {

    /** Versuche je Auftrag, bevor er als gescheitert abgelegt wird */
    public const MAX_ATTEMPTS = 20;

    /** Aufträge je Lauf von runDue() */
    public const RUN_LIMIT = 500;

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private System $system,
        private Record $record,
        private DeletePolicy $policy,
        private AuditLog $auditLog,
        private Session $session,
        private TriggerTemplates $triggers,
    )
    {}

    // ====================
    // Auslösen

    /**
     * Einen Datensatz nach der Policy seines Bereichs löschen - mit seinen
     * Unterdatensätzen (siehe Klassenkommentar). Ist er ein Profil, werden danach
     * immer alle Datensätze des Users mit dem Auslöser 'profile' gelöscht.
     *
     * @param int           $recordId    produktiver Datensatz
     * @param string        $source      record | profile
     * @param callable|null $softDelete  function(int $recordId): ?string - wie die
     *                                   sofortige Aktion soft_delete ausgeführt wird
     *                                   (RecordService: Teilbaum, Hintergrund);
     *                                   null = Record::delete()
     * @param bool          $withChilds  Kinder im Baum mit löschen, sonst rücken sie
     *                                   eine Ebene hoch
     * @return string|null  'deleted' (im Papierkorb bzw. endgültig gelöscht),
     *                      'background' (Papierkorb per Hintergrundprozess),
     *                      'changed' (bleibt, angepasst), 'scheduled' (nur spätere
     *                      Aktionen), null bei Fehler (Meldung in Cms::errors)
     */
    public function delete(int $recordId, string $source = 'record', ?callable $softDelete = null, bool $withChilds = false): ?string
    {
        $row = $this->recordRow($recordId);
        if ($row === null || (int)$row['tmp'] === 1) {
            $this->cms->error($this->cms->snippet('error_record_not_found'));
            return null;
        }
        if ((int)$row['deleted'] === 1) return 'deleted';
        if ($this->pendingCount($recordId) > 0) {
            if ($source === 'profile') return 'scheduled';
            $this->cms->error($this->cms->snippet('privacy.error_already_pending'));
            return null;
        }
        $policy = $this->policyOfArea((int)$row['area_id']);
        $blocks = $policy ? $this->blocksFor($policy, $source) : [];
        if ($policy && !$blocks) {
            $this->cms->error(strtr($this->cms->snippet('privacy.error_no_action'), ['{policy}' => $this->cms->textOrSnippet((string)$policy['label'])]));
            return null;
        }

        $subIds = $this->subrecordIds($row, $withChilds, false);
        $ids = array_merge([$recordId], $subIds);
        // Profile: Geräte und Sitzungen immer sofort weg
        $profileIds = [];
        foreach ($ids as $id) {
            $member = $id === $recordId ? $row : $this->recordRow($id);
            if ($member && (string)$member['area_type'] === 'profile') $profileIds[] = $id;
        }
        foreach ($profileIds as $id) $this->removeLogins($id);

        if (!$policy) {
            // Bereich ohne Policy (vor der Migration angelegt): wie früher in den Papierkorb
            $this->system->log('privacy', "record $recordId: area {$row['area_id']} has no delete policy - moved to trash");
            $status = $this->softDelete($recordId, $softDelete, $withChilds) === 'background' ? 'background' : 'deleted';
            foreach ($profileIds as $id) $this->triggerLinked($id);
            return $status;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->insertJobs($recordId, (int)$row['area_id'], $row['user_id'] === null ? null : (int)$row['user_id'],
            $policy, $source, $blocks, $now, $withChilds);
        $count = count($blocks);
        foreach ($subIds as $id) {
            if ($this->pendingCount($id) > 0) continue;
            $sub = $this->recordRow($id);
            $subPolicy = $sub ? $this->policyOfArea((int)$sub['area_id']) : null;
            if (!$subPolicy) continue;
            $subBlocks = array_values(array_filter($this->blocksFor($subPolicy, $source),
                fn($b) => !in_array($b['action'], DeletePolicy::REMOVE_ACTIONS, true)));
            $this->insertJobs($id, (int)$sub['area_id'], $sub['user_id'] === null ? null : (int)$sub['user_id'],
                $subPolicy, $source, $subBlocks, $now, false);
            $count += count($subBlocks);
        }
        $this->system->log('privacy', sprintf('record %d (area %d): policy %d, source %s, %d subrecords, %d jobs',
            $recordId, (int)$row['area_id'], (int)$policy['privacy_policy_id'], $source, count($subIds), $count));

        // Datensätze der gelöschten Profile - vor den eigenen Aktionen, solange die
        // Verknüpfung noch steht
        foreach ($profileIds as $id) $this->triggerLinked($id);

        // Sofort Fälliges gleich ausführen
        $status = 'scheduled';
        foreach ($this->dueJobs($ids) as $job) {
            $result = $this->run($job, (int)$job['record_id'] === $recordId ? $softDelete : null);
            if ((int)$job['record_id'] !== $recordId) continue;
            if ($result === 'background') $status = 'background';
            elseif ($result === 'deleted' && $status !== 'background') $status = 'deleted';
            elseif ($result === 'changed' && $status === 'scheduled') $status = 'changed';
        }
        return $status;
    }

    /**
     * Ein Bereich wurde gelöscht (in den Papierkorb, Area::hookOnSoftDelete()): für
     * seine dabei gelöschten Datensätze die Blöcke der Bereichs-Policy mit dem
     * Auslöser 'area' planen. Ausgeführt werden sie vom nächsten Lauf der
     * Zeitplaner-Aufgabe - auch die sofort fälligen; der Bereich wird noch gelöscht
     * (Transaktion). Wird der Bereich wiederhergestellt, verfallen die Aufträge
     * (Record::hookOnRestore()).
     *
     * Ein Profil-Bereich: Geräte und Sitzungen der Profile gleich weg, und die
     * Datensätze der User werden wie bei jedem gelöschten Profil sofort nach ihren
     * Policies gelöscht (Auslöser 'profile') - das macht eine Wiederherstellung des
     * Bereichs nicht rückgängig, sie liegen dann ggf. im Papierkorb.
     *
     * @param int[] $recordIds  die mit dem Bereich gelöschten Datensätze, Blätter zuerst
     * @return int  angelegte Aufträge
     */
    public function scheduleArea(int $areaId, array $recordIds): int
    {
        if (!$recordIds) return 0;
        $policy = $this->policyOfArea($areaId);
        $blocks = $policy ? $this->blocksFor($policy, 'area') : [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $count = 0;
        $profileIds = [];
        foreach ($recordIds as $id) {
            $row = $this->recordRow((int)$id);
            if ($row === null) continue;
            if ((string)$row['area_type'] === 'profile') $profileIds[] = (int)$id;
            if (!$blocks || $this->pendingCount((int)$id) > 0) continue;
            $this->insertJobs((int)$id, $areaId, $row['user_id'] === null ? null : (int)$row['user_id'], $policy, 'area', $blocks, $now, false);
            $count += count($blocks);
        }
        if ($count > 0) {
            $this->system->log('privacy', sprintf('area %d deleted: policy %d, %d jobs', $areaId, (int)$policy['privacy_policy_id'], $count));
        }
        foreach ($profileIds as $id) {
            $this->removeLogins($id);
            $this->triggerLinked($id);
        }
        return $count;
    }

    /**
     * Beim endgültigen Löschen eines Datensatzes, für den es nie Policy-Aufträge
     * gab (etwa vor Einführung der Policies in den Papierkorb gelegt), wenigstens
     * die Aktionen der Bereichs-Policy für das Audit-Log planen - wie beim Auslöser
     * 'area', Frist ab dem Löschen (record.changed_at des Papierkorbs). Ausgeführt von runDue(), also
     * nach dem Eintrag 'purge'. Läuft in der Transaktion des Löschens
     * (Record::hookOnDelete()) - ein Rollback nimmt die Aufträge mit.
     *
     * @return int  angelegte Aufträge
     */
    public function scheduleWithoutPolicy(int $recordId): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `area_id`, `user_id`, `deleted`, `changed_at` FROM `record` WHERE `record_id` = ? AND `tmp` = 0", 'i', [$recordId]);
            $row = $this->db->fetch();
            if (!$row || (int)$row['deleted'] !== 1) return 0;
            // Lief schon eine Policy (auch erledigt), regelt sie das Audit-Log
            $this->db->query("SELECT COUNT(*) FROM `privacy_job` WHERE `record_id` = ?", 'i', [$recordId]);
            if ((int)($this->db->fetchNum()[0] ?? 0) > 0) return 0;
        }
        finally {
            $this->db->popResult();
        }
        $policy = $this->policyOfArea((int)$row['area_id']);
        if (!$policy) return 0;
        $blocks = array_values(array_filter($this->blocksFor($policy, 'area'),
            fn($b) => in_array($b['action'], DeletePolicy::AUDIT_ACTIONS, true)));
        if (!$blocks) return 0;
        $deletedAt = new \DateTimeImmutable(substr((string)$row['changed_at'], 0, 19), new \DateTimeZone('UTC'));
        $this->insertJobs($recordId, (int)$row['area_id'], $row['user_id'] === null ? null : (int)$row['user_id'],
            $policy, 'area', $blocks, $deletedAt, false);
        $this->system->log('privacy', sprintf('record %d (area %d) purged without policy: policy %d, %d audit jobs',
            $recordId, (int)$row['area_id'], (int)$policy['privacy_policy_id'], count($blocks)));
        return count($blocks);
    }

    /**
     * Aufträge für Blöcke anlegen, fällig ab $start + Frist des Blocks. $children:
     * soft_delete und purge erfassen die Kinder im Baum (fields.children).
     */
    private function insertJobs(int $recordId, int $areaId, ?int $userId, array $policy, string $source, array $blocks,
        \DateTimeImmutable $start, bool $children): void
    {
        $adminId = (int)$this->session->get('admin_id') ?: null;
        $this->db->pushResult();
        try {
            foreach ($blocks as $block) {
                $due = (int)$block['delay'] > 0 ? $start->modify('+' . (int)$block['delay'] . ' ' . $block['unit']) : $start;
                $fields = match (true) {
                    in_array($block['action'], DeletePolicy::TRIGGER_ACTIONS, true) => ['trigger' => (string)$block['trigger']],
                    in_array($block['action'], DeletePolicy::REMOVE_ACTIONS, true) => $children ? ['children' => true] : [],
                    default => $block['fields'],
                };
                $this->db->query(
                    "INSERT INTO `privacy_job` (`record_id`, `area_id`, `user_id`, `privacy_policy_id`, `source`, `action`, `fields`,"
                    . " `due_at`, `created_at`, `created_by`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)",
                    'iiiissssi',
                    [$recordId, $areaId, $userId, (int)$policy['privacy_policy_id'], $source, (string)$block['action'],
                        (string)json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $due->format('Y-m-d H:i:s'), $adminId]
                );
            }
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Offene Aufträge eines Datensatzes verwerfen - er wurde aus dem Papierkorb
     * wiederhergestellt (Record::hookOnRestore()).
     */
    public function cancel(int $recordId): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("UPDATE `privacy_job` SET `done_at` = UTC_TIMESTAMP(), `last_error` = 'cancelled: restored'"
                . " WHERE `record_id` = ? AND `done_at` IS NULL", 'i', [$recordId]);
            return max(0, (int)$this->db->affected_rows);
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Aufträge eines Datensatzes entfernen, nachdem sein Audit-Log von Hand gelöscht
     * wurde (`camindo.php audit delete`): erledigte immer, offene nur, wenn es den
     * Datensatz nicht mehr gibt - sonst könnte die Policy ihn nicht mehr zu Ende löschen.
     * Ohne erledigte Aufträge plant ein späteres endgültiges Löschen die Audit-Aktionen
     * neu (scheduleWithoutPolicy()) - sie räumen dann auch den Eintrag 'purge' ab.
     *
     * @return array  deleted, kept (offene Aufträge, die bleiben)
     */
    public function forget(int $recordId, bool $dryRun = false): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT COUNT(*) FROM `record` WHERE `record_id` = ?", 'i', [$recordId]);
            $exists = (int)($this->db->fetchNum()[0] ?? 0) > 0;
            $where = "`record_id` = ?" . ($exists ? " AND `done_at` IS NOT NULL" : '');
            $this->db->query("SELECT COUNT(*) FROM `privacy_job` WHERE $where", 'i', [$recordId]);
            $deleted = (int)($this->db->fetchNum()[0] ?? 0);
            $kept = $exists ? $this->pendingCount($recordId) : 0;
            if (!$dryRun && $deleted > 0) $this->db->query("DELETE FROM `privacy_job` WHERE $where", 'i', [$recordId]);
            return ['deleted' => $deleted, 'kept' => $kept];
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Offene Aufträge eines Datensatzes */
    public function pendingCount(int $recordId): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT COUNT(*) FROM `privacy_job` WHERE `record_id` = ? AND `done_at` IS NULL", 'i', [$recordId]);
            return (int)($this->db->fetchNum()[0] ?? 0);
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Nächster offener Auftrag eines Datensatzes (Zeitpunkt UTC) oder '' */
    public function nextDue(int $recordId): string
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT MIN(`due_at`) FROM `privacy_job` WHERE `record_id` = ? AND `done_at` IS NULL", 'i', [$recordId]);
            return (string)($this->db->fetchNum()[0] ?? '');
        }
        finally {
            $this->db->popResult();
        }
    }

    // ====================
    // Ausführen

    /**
     * Fällige Aufträge aller Datensätze ausführen (höchstens RUN_LIMIT) - aus der
     * Zeitplaner-Aufgabe vom Typ privacy, die so oft aufruft, bis keine mehr fällig sind.
     *
     * @return array  done, failed
     */
    public function runDue(): array
    {
        $result = ['done' => 0, 'failed' => 0];
        $this->db->pushResult();
        try {
            $this->db->query("SELECT * FROM `privacy_job` WHERE `done_at` IS NULL AND `due_at` <= UTC_TIMESTAMP()"
                . " ORDER BY `due_at`, " . $this->actionOrder() . ", `job_id` LIMIT " . self::RUN_LIMIT);
            $jobs = $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
        // Audit-Log: Herkunft 'privacy'
        $context = $this->auditLog->context();
        $this->auditLog->setContext('privacy');
        try {
            foreach ($jobs as $job) {
                // Ein früherer Auftrag dieses Laufs kann ihn erledigt haben (cancel())
                if ($this->isDone((int)$job['job_id'])) continue;
                $this->run($job) === null ? $result['failed']++ : $result['done']++;
            }
        }
        finally {
            $this->auditLog->setContext($context);
        }
        return $result;
    }

    /**
     * Einen Auftrag ausführen und das Ergebnis vermerken.
     *
     * @return string|null  deleted | background | changed | done, null bei Fehler
     */
    private function run(array $job, ?callable $softDelete = null): ?string
    {
        $jobId = (int)$job['job_id'];
        $recordId = (int)$job['record_id'];
        $fields = json_decode((string)$job['fields'], true) ?: [];
        $this->cms->errorsReset();
        try {
            $status = match ((string)$job['action']) {
                'set_fields'       => $this->setFields($recordId, $fields, $job),
                'soft_delete'      => $this->softDelete($recordId, $softDelete, !empty($fields['children'])),
                'purge'            => $this->purge($recordId, !empty($fields['children'])),
                'audit_set_fields' => $this->auditSetFields($recordId, $fields, $job),
                'audit_delete'     => $this->auditDelete($recordId),
                'record_trigger'   => $this->recordTrigger($recordId, (string)($fields['trigger'] ?? ''), $job),
                'audit_trigger'    => $this->auditTrigger($recordId, (string)($fields['trigger'] ?? ''), $job),
                default            => throw new \RuntimeException('unknown action ' . $job['action']),
            };
            $this->finish($jobId, '');
            return $status;
        }
        catch (\Throwable $e) {
            $message = mb_substr($e->getMessage() ?: $this->cms->errorMsg(' | '), 0, 255);
            $attempts = (int)$job['attempts'] + 1;
            $this->db->pushResult();
            try {
                $this->db->query("UPDATE `privacy_job` SET `attempts` = ?, `last_error` = ?"
                    . ($attempts >= self::MAX_ATTEMPTS ? ", `done_at` = UTC_TIMESTAMP()" : '') . " WHERE `job_id` = ?",
                    'isi', [$attempts, $message, $jobId]);
            }
            finally {
                $this->db->popResult();
            }
            $this->system->log('privacy', sprintf('job %d (record %d, %s) failed (attempt %d): %s',
                $jobId, $recordId, $job['action'], $attempts, $message));
            return null;
        }
    }

    /**
     * Felder des Datensatzes überschreiben - über eine Arbeitskopie wie das Frontend
     * (RecordUser), im Policy-Modus. Ist der Datensatz schon endgültig gelöscht oder
     * im Papierkorb, gibt es nichts mehr zu tun.
     */
    private function setFields(int $recordId, array $fields, array $job): string
    {
        $row = $this->recordRow($recordId);
        if ($row === null || (int)$row['deleted'] === 1 || !$fields) return 'done';
        $areaId = (int)$row['area_id'];
        $schema = $this->record->virtualSchema($areaId);
        $vars = $this->vars($job);

        return $this->record->asPolicy(function () use ($recordId, $areaId, $schema, $fields, $vars) {
            $data = $this->record->load($recordId);
            if ($data === null) throw new \RuntimeException('record not loadable');
            if (!$tmpId = $this->record->checkOut($data)) throw new \RuntimeException($this->cms->errorMsg(' | ') ?: 'check-out failed');
            // Mehrsprachige Felder bekommen den Wert in allen Sprachen des Datensatzes
            $languageIds = $this->record->languageIdsOf($tmpId);
            $written = [];
            foreach ($fields as $name => $value) {
                if (!isset($schema[$name]) || in_array($name, ['record_id', 'area_id', 'instance_id', 'tmp', 'deleted', 'origin_id'], true)) continue;
                $colDef = $schema[$name];
                $type = (string)($colDef['type'] ?? 'text');
                $multilingual = $this->cms->isMultilingualField($colDef);
                $value = AuditLog::renderValue((string)$value, $vars);
                if ($type === 'file') {
                    // Datei entfernen (samt abgeleiteter Thumbnails) - einen anderen Wert
                    // kann ein Upload-Feld nicht annehmen; ein Thumbnail-Feld allein nicht
                    if ($value !== '' || (string)($colDef['display_type'] ?? '') !== 'upload' || empty($colDef['field_id'])) continue;
                    foreach ($multilingual ? $languageIds : [null] as $languageId) {
                        if (!$this->record->markFileDeleted($tmpId, (int)$colDef['field_id'], $languageId)) {
                            $message = $this->cms->errorMsg(' | ');
                            $this->record->cancel($tmpId);
                            throw new \RuntimeException("$name: " . ($message ?: 'file could not be removed'));
                        }
                    }
                    $written[] = $name;
                    continue;
                }
                if (in_array($type, $this->cms->arrayTypes, true)) {
                    if ($value !== '') continue;
                    $data[$name] = [];
                }
                elseif ($multilingual) {
                    $data[$name] = array_fill_keys($languageIds, $value);
                }
                else {
                    $data[$name] = $value;
                }
                $written[] = $name;
            }
            if (!$written) {
                $this->record->cancel($tmpId);
                return 'done';
            }
            $data['record_id'] = $tmpId;
            $data['area_id'] = $areaId;
            $written[] = 'area_id';
            if (!$this->record->save(array_values(array_unique($written)), $data)) {
                $message = $this->cms->errorMsg(' | ');
                $this->record->cancel($tmpId);
                throw new \RuntimeException($message ?: 'save failed');
            }
            return 'changed';
        });
    }

    /** In den Papierkorb - endgültig gelöscht nach admin.trash_retention_days */
    private function softDelete(int $recordId, ?callable $softDelete, bool $children): string
    {
        $row = $this->recordRow($recordId);
        if ($row === null || (int)$row['deleted'] === 1) return 'done';
        if ($softDelete !== null) {
            $status = $this->record->asPolicy(fn() => $softDelete($recordId));
            if ($status === null) throw new \RuntimeException($this->cms->errorMsg(' | ') ?: 'delete failed');
            return $status === 'background' ? 'background' : 'deleted';
        }
        if (!$this->record->asPolicy(fn() => $this->record->delete($recordId, $children))) {
            throw new \RuntimeException($this->cms->errorMsg(' | ') ?: 'delete failed');
        }
        return 'deleted';
    }

    /**
     * Endgültig löschen, samt Audit-Log - mit den Kindern im Baum ($children, sonst
     * rücken sie hoch) und den fest verknüpften Inhalten, auch deren Audit-Log.
     */
    private function purge(int $recordId, bool $children): string
    {
        $row = $this->recordRow($recordId);
        $ids = [$recordId];
        if ($row !== null) {
            $ids = array_merge($ids, $this->subrecordIds($row, $children, true));
            if (!$this->record->asPolicy(fn() => $this->record->purge($recordId, $children))) {
                throw new \RuntimeException($this->cms->errorMsg(' | ') ?: 'purge failed');
            }
        }
        foreach ($ids as $id) $this->auditLog->deleteObject('record', $id);
        return 'deleted';
    }

    private function auditSetFields(int $recordId, array $fields, array $job): string
    {
        if (!$fields) return 'done';
        $vars = $this->vars($job);
        $this->auditLog->overwriteObject('record', $recordId, $fields, $vars);
        $this->auditLog->forgetPolicyOverride($recordId);
        return 'done';
    }

    private function auditDelete(int $recordId): string
    {
        $this->auditLog->deleteObject('record', $recordId);
        return 'done';
    }

    /**
     * Trigger-Template des Bereichs auf den Datensatz anwenden: onPrivacyAction($action,
     * &$data, $context) mit dem geladenen Datensatz - mehrsprachige Felder als
     * language_id => Wert, Dateifelder mit ihren Metadaten. Was das Template an $data
     * ändert (außer Dateifeldern und Kennungen), wird über eine Arbeitskopie im
     * Policy-Modus gespeichert; verknüpfte Datensätze, Dateien usw. bearbeitet es
     * selbst ($this->record, $this->db …). Eine Ausnahme im Template lässt den
     * Auftrag scheitern (neuer Versuch beim nächsten Lauf). Liegt der Datensatz im
     * Papierkorb, läuft das Template mit $context['deleted'] = true, gespeichert wird
     * dann nichts.
     */
    private function recordTrigger(int $recordId, string $action, array $job): string
    {
        $row = $this->recordRow($recordId);
        if ($row === null) return 'done';
        $areaId = (int)$row['area_id'];
        $this->requireTrigger($areaId);
        $schema = $this->record->virtualSchema($areaId);
        $context = $this->triggerContext($job, 'record') + ['deleted' => (int)$row['deleted'] === 1];

        // Im Papierkorb: das Template läuft (verknüpfte Daten aufräumen …), am
        // Datensatz selbst wird nichts gespeichert - auschecken lässt er sich nicht
        if ($context['deleted']) {
            return $this->record->asPolicy(function () use ($recordId, $areaId, $action, $context) {
                $data = $this->record->load($recordId) ?? ['record_id' => $recordId];
                $this->triggers->run($this->record, $areaId, 'onPrivacyAction', false, $action, $data, $context);
                return 'done';
            });
        }

        return $this->record->asPolicy(function () use ($recordId, $areaId, $schema, $action, $context) {
            $data = $this->record->load($recordId);
            if ($data === null) throw new \RuntimeException('record not loadable');
            if (!$tmpId = $this->record->checkOut($data)) throw new \RuntimeException($this->cms->errorMsg(' | ') ?: 'check-out failed');
            try {
                // Mehrsprachige Felder in allen Sprachen (load() liefert nur eine)
                $tmp = $this->record->loadTmpData($tmpId);
                foreach ($schema as $name => $colDef) {
                    if ((string)($colDef['type'] ?? '') === 'file' || !$this->cms->isMultilingualField($colDef)) continue;
                    $data[$name] = (array)($tmp[$name][1] ?? []);
                }
                $data['record_id'] = $recordId;
                $before = $data;
                $this->triggers->run($this->record, $areaId, 'onPrivacyAction', false, $action, $data, $context);
            }
            catch (\Throwable $e) {
                $this->record->cancel($tmpId);
                throw $e;
            }
            $locked = ['record_id', 'area_id', 'instance_id', 'tmp', 'deleted', 'origin_id', 'user_id'];
            $changed = [];
            foreach ($data as $name => $value) {
                if (!isset($schema[$name]) || in_array($name, $locked, true) || (string)($schema[$name]['type'] ?? '') === 'file') continue;
                if (!array_key_exists($name, $before) || $before[$name] !== $value) $changed[] = (string)$name;
            }
            if (!$changed) {
                $this->record->cancel($tmpId);
                return 'done';
            }
            $data['record_id'] = $tmpId;
            $data['area_id'] = $areaId;
            $changed[] = 'area_id';
            if (!$this->record->save($changed, $data)) {
                $message = $this->cms->errorMsg(' | ');
                $this->record->cancel($tmpId);
                throw new \RuntimeException($message ?: 'save failed');
            }
            return 'changed';
        });
    }

    /**
     * Trigger-Template des Bereichs auf das Audit-Log des Datensatzes anwenden:
     * onPrivacyAction($action, &$entry, $context) je Eintrag (AuditLog::transformObject()).
     * Auch für einen schon gelöschten Datensatz - der Bereich steht im Auftrag.
     */
    private function auditTrigger(int $recordId, string $action, array $job): string
    {
        $areaId = (int)($job['area_id'] ?? 0);
        $this->requireTrigger($areaId);
        $context = $this->triggerContext($job, 'audit');
        $this->auditLog->transformObject('record', $recordId, function (array $entry) use ($areaId, $action, $context): array {
            $this->triggers->run($this->record, $areaId, 'onPrivacyAction', false, $action, $entry, $context);
            return $entry;
        });
        return 'done';
    }

    /** Ohne Trigger-Template mit onPrivacyAction() kann die Aktion nicht laufen */
    private function requireTrigger(int $areaId): void
    {
        if (!$this->triggers->has($this->record, $areaId, 'onPrivacyAction')) {
            throw new \RuntimeException("area $areaId: no trigger template with onPrivacyAction()");
        }
    }

    /** Drittes Argument von onPrivacyAction() */
    private function triggerContext(array $job, string $target): array
    {
        return [
            'target'    => $target,
            'record_id' => (int)$job['record_id'],
            'user_id'   => (int)($job['user_id'] ?? 0),
            'area_id'   => (int)($job['area_id'] ?? 0),
            'source'    => (string)($job['source'] ?? ''),
            'policy_id' => (int)($job['privacy_policy_id'] ?? 0),
            'job_id'    => (int)($job['job_id'] ?? 0),
        ];
    }

    /**
     * Die Datensätze des Users (record.user_id = Profil) nach den Policies ihrer
     * Bereiche mit dem Auslöser "profile" löschen - immer, wenn ein Profil gelöscht
     * wird. Wer dort keinen Block für "profile" hat, bleibt stehen.
     */
    private function triggerLinked(int $profileId): void
    {
        foreach ($this->linkedIds($profileId) as $id) {
            $this->cms->errorsReset();
            if ($this->delete($id, 'profile') === null && $this->cms->hasErrors) {
                // keine Aktion für "profile" ist kein Fehler des Profils
                $this->system->log('privacy', "record $id (linked to profile $profileId): " . $this->cms->errorMsg(' | '));
            }
        }
        $this->cms->errorsReset();
    }

    // ====================
    // Audit-Log

    /**
     * Schon ausgeführte Überschreibung des Audit-Logs eines Datensatzes - für
     * AuditLog::write(): [fields, vars] des letzten erledigten Auftrags
     * audit_set_fields, sonst null.
     */
    public function auditOverride(int $recordId): ?array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT * FROM `privacy_job` WHERE `record_id` = ? AND `action` = 'audit_set_fields'"
                . " AND `done_at` IS NOT NULL AND `last_error` = '' ORDER BY `done_at` DESC LIMIT 1", 'i', [$recordId]);
            $job = $this->db->fetch();
        }
        finally {
            $this->db->popResult();
        }
        if (!$job) return null;
        return [json_decode((string)$job['fields'], true) ?: [], $this->vars($job)];
    }

    // ====================
    // Beschreibung (Hinweis im Löschdialog)

    /**
     * Was beim Löschen eines Datensatzes mit der Datenbank geschieht - für den
     * Hinweis im Löschdialog (PrivacyBackend::hintHtml()).
     *
     * @return array  type (Bereichstyp), policy (Bezeichnung), pending (Zeitpunkt des
     *                nächsten offenen Auftrags oder ''), plan (siehe plan()), linked
     *                (bei Profilen, Liste je Bereich: count, area, plan - null =
     *                bleiben), linked_count (alle Datensätze des Users)
     */
    public function describe(int $recordId, string $source = 'record'): array
    {
        $row = $this->recordRow($recordId);
        if ($row === null) return [];
        $policy = $this->policyOfArea((int)$row['area_id']);
        $blocks = $policy ? $this->blocksFor($policy, $source) : [];
        $result = [
            'type' => (string)$row['area_type'],
            'policy' => $policy ? $this->cms->textOrSnippet((string)$policy['label']) : '',
            'pending' => $this->pendingCount($recordId) > 0 ? $this->nextDue($recordId) : '',
            'plan' => $this->plan($blocks, (int)$row['area_id']),
            'linked' => [],
            'linked_count' => 0,
        ];
        if ((string)$row['area_type'] === 'profile') {
            $byArea = [];
            foreach ($this->linkedIds($recordId) as $id) {
                $linked = $this->recordRow($id);
                if ($linked) $byArea[(int)$linked['area_id']][] = $id;
            }
            $result['linked_count'] = array_sum(array_map('count', $byArea));
            foreach ($byArea as $areaId => $ids) {
                $areaPolicy = $this->policyOfArea($areaId);
                $result['linked'][] = [
                    'count' => count($ids),
                    'area' => $this->areaLabel($areaId),
                    'plan' => $areaPolicy && $this->blocksFor($areaPolicy, 'profile') ? $this->plan($this->blocksFor($areaPolicy, 'profile'), $areaId) : null,
                ];
            }
        }
        return $result;
    }

    /**
     * Wie describe(), nur für einen Bereich statt eines Datensatzes (Stapelverarbeitung):
     * type, policy, plan.
     */
    public function describeArea(int $areaId, string $source = 'record'): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `type` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
            $type = (string)($this->db->fetchNum()[0] ?? '');
        }
        finally {
            $this->db->popResult();
        }
        $policy = $this->policyOfArea($areaId);
        return [
            'type' => $type,
            'policy' => $policy ? $this->cms->textOrSnippet((string)$policy['label']) : '',
            'pending' => '',
            'plan' => $this->plan($policy ? $this->blocksFor($policy, $source) : [], $areaId),
            'linked' => [],
            'linked_count' => 0,
        ];
    }

    /**
     * Die Datenbank-Aktionen von Blöcken, nach Zeitpunkt geordnet.
     *
     * @return array  now: trash | purge | change | keep, now_fields (Liste aus
     *                label/value/kind für "change"), later: Liste aus delay, unit,
     *                items (Liste aus kind = purge | trash | field, label, value, kind)
     */
    private function plan(array $blocks, int $areaId): array
    {
        $schema = $areaId > 0 ? $this->record->virtualSchema($areaId) : [];
        $plan = ['now' => 'keep', 'now_fields' => [], 'later' => []];
        $later = [];
        foreach ($blocks as $block) {
            if (!in_array($block['action'], DeletePolicy::DB_ACTIONS, true)) continue;
            $items = [];
            if ($block['action'] === 'record_trigger') {
                $items[] = ['kind' => 'trigger', 'label' => '', 'value' => (string)$block['trigger']];
            }
            elseif ($block['action'] === 'set_fields') {
                foreach ((array)$block['fields'] as $name => $value) {
                    if (!isset($schema[$name])) continue;
                    $items[] = $this->fieldItem($schema[$name], (string)$name, (string)$value);
                }
            }
            else {
                $items[] = ['kind' => $block['action'] === 'purge' ? 'purge' : 'trash', 'label' => '', 'value' => ''];
            }
            if ((int)$block['delay'] === 0) {
                if ($block['action'] === 'purge') $plan['now'] = 'purge';
                elseif ($block['action'] === 'soft_delete' && $plan['now'] !== 'purge') $plan['now'] = 'trash';
                elseif ($block['action'] === 'set_fields' || $block['action'] === 'record_trigger') {
                    if ($plan['now'] === 'keep') $plan['now'] = 'change';
                    $plan['now_fields'] = array_merge($plan['now_fields'], $items);
                }
                continue;
            }
            $key = $block['unit'] . ':' . (int)$block['delay'];
            $later[$key] ??= ['delay' => (int)$block['delay'], 'unit' => $block['unit'], 'items' => []];
            $later[$key]['items'] = array_merge($later[$key]['items'], $items);
        }
        // In den Papierkorb: endgültig nach der Frist des Papierkorbs
        if ($plan['now'] === 'trash') {
            $days = max(0, (int)($this->config->get('admin.trash_retention_days') ?? 7));
            $key = 'days:' . $days;
            $later[$key] ??= ['delay' => $days, 'unit' => 'days', 'items' => []];
            array_unshift($later[$key]['items'], ['kind' => 'purge', 'label' => '', 'value' => '']);
        }
        uasort($later, fn($a, $b) => ($a['unit'] === 'years' ? $a['delay'] * 365 : $a['delay']) <=> ($b['unit'] === 'years' ? $b['delay'] * 365 : $b['delay']));
        $plan['later'] = array_values($later);
        return $plan;
    }

    /** Ein Feld der Beschreibung: Beschriftung, Wert, Art der Darstellung */
    private function fieldItem(array $colDef, string $name, string $value): array
    {
        $type = (string)($colDef['type'] ?? 'text');
        $label = $this->cms->textOrSnippet((string)($colDef['label'] ?? $name)) ?: $name;
        if ($type === 'file') return ['kind' => 'field', 'label' => $label, 'value' => $value === '' ? '---' : $value, 'format' => 'raw'];
        if ($name === 'status_id') {
            return ['kind' => 'field', 'label' => $label, 'value' => $this->statusLabel((int)$value), 'format' => 'quoted'];
        }
        $numeric = in_array($type, $this->cms->numericTypes, true);
        return ['kind' => 'field', 'label' => $label, 'value' => $value, 'format' => $numeric ? 'raw' : 'quoted'];
    }

    // ====================
    // Hilfen

    /** record-Zeile mit Typ des Bereichs, null = gibt es nicht (mehr) */
    private function recordRow(int $recordId): ?array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT r.`record_id`, r.`area_id`, r.`user_id`, r.`tmp`, r.`deleted`, r.`lft`, r.`rgt`, a.`type` AS `area_type`"
                . " FROM `record` r JOIN `area` a ON a.`area_id` = r.`area_id` WHERE r.`record_id` = ?", 'i', [$recordId]);
            return $this->db->fetch();
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Policy eines Bereichs als Zeile (blocks dekodiert), null = keine */
    public function policyOfArea(int $areaId): ?array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT p.* FROM `area` a JOIN `privacy_policy` p ON p.`privacy_policy_id` = a.`privacy_policy_id`"
                . " WHERE a.`area_id` = ?", 'i', [$areaId]);
            $row = $this->db->fetch();
        }
        finally {
            $this->db->popResult();
        }
        if (!$row) return null;
        $row['blocks'] = DeletePolicy::normalizeBlocks(json_decode((string)$row['blocks'], true) ?: []);
        return $row;
    }

    /** Blöcke einer Policy für einen Auslöser */
    private function blocksFor(array $policy, string $source): array
    {
        return array_values(array_filter((array)$policy['blocks'], fn($b) => in_array($source, (array)$b['sources'], true)));
    }

    /** Produktive Datensätze eines Users (record.user_id) */
    private function linkedIds(int $profileId): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `record_id` FROM `record` WHERE `user_id` = ? AND `record_id` <> ? AND `tmp` = 0 AND `deleted` = 0"
                . " ORDER BY `record_id`", 'ii', [$profileId, $profileId]);
            return array_map('intval', $this->db->fetchColumn());
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Geräte und Sitzungen eines Profils */
    private function removeLogins(int $profileId): void
    {
        $this->db->pushResult();
        try {
            $this->db->query("DELETE FROM `login_device` WHERE `record_id` = ?", 'i', [$profileId]);
            $this->db->query("DELETE FROM `user_session` WHERE `record_id` = ?", 'i', [$profileId]);
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Fällige, offene Aufträge von Datensätzen in Ausführungsreihenfolge */
    private function dueJobs(array $recordIds): array
    {
        $recordIds = array_map('intval', $recordIds);
        $this->db->pushResult();
        try {
            $this->db->query("SELECT * FROM `privacy_job` WHERE `record_id` IN (" . implode(',', array_fill(0, count($recordIds), '?'))
                . ") AND `done_at` IS NULL AND `due_at` <= UTC_TIMESTAMP()"
                . " ORDER BY " . $this->actionOrder() . ", `job_id`", str_repeat('i', count($recordIds)), $recordIds);
            return $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
    }

    /** ORDER BY-Ausdruck: Aktionen in der Reihenfolge von DeletePolicy::ACTIONS */
    private function actionOrder(): string
    {
        return "FIELD(`action`, '" . implode("', '", DeletePolicy::ACTIONS) . "')";
    }

    private function isDone(int $jobId): bool
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `done_at` IS NOT NULL FROM `privacy_job` WHERE `job_id` = ?", 'i', [$jobId]);
            return (int)($this->db->fetchNum()[0] ?? 1) === 1;
        }
        finally {
            $this->db->popResult();
        }
    }

    private function finish(int $jobId, string $error): void
    {
        $this->db->pushResult();
        try {
            $this->db->query("UPDATE `privacy_job` SET `done_at` = UTC_TIMESTAMP(), `last_error` = ? WHERE `job_id` = ?", 'si', [$error, $jobId]);
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Platzhalter eines Auftrags */
    private function vars(array $job): array
    {
        return ['record_id' => (int)$job['record_id'], 'user_id' => (int)($job['user_id'] ?? 0)];
    }

    /**
     * Unterdatensätze, die mit einem Datensatz gelöscht werden: die Kinder im Baum
     * ($withChilds), dazu - rekursiv, auch von den Kindern - die fest verknüpften
     * Inhalte (link). Ohne den Datensatz selbst.
     *
     * @param array $row             recordRow()
     * @param bool  $includeDeleted  auch Datensätze im Papierkorb (endgültiges Löschen)
     * @return int[]
     */
    private function subrecordIds(array $row, bool $withChilds, bool $includeDeleted): array
    {
        $recordId = (int)$row['record_id'];
        $ids = [];
        if ($withChilds && $this->record->isTreeArea((int)$row['area_id'])) {
            $ids = $this->record->subtreeIds($row, $includeDeleted ? 'purge' : 'delete');
        }
        $ids = array_values(array_diff(array_map('intval', $ids), [$recordId]));
        $seen = array_fill_keys(array_merge([$recordId], $ids), true);
        $queue = array_keys($seen);
        while ($queue) {
            foreach ($this->record->dependentIds((int)array_shift($queue), true) as $destId) {
                if (isset($seen[$destId])) continue;
                $seen[$destId] = true;
                $dest = $this->recordRow($destId);
                if ($dest === null || (!$includeDeleted && (int)$dest['deleted'] === 1)) continue;
                $ids[] = $destId;
                $queue[] = $destId;
            }
        }
        return $ids;
    }

    private function areaLabel(int $areaId): string
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `label` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
            return $this->cms->textOrSnippet((string)($this->db->fetchNum()[0] ?? ''));
        }
        finally {
            $this->db->popResult();
        }
    }

    private function statusLabel(int $statusId): string
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `label` FROM `status` WHERE `status_id` = ?", 'i', [$statusId]);
            $label = (string)($this->db->fetchNum()[0] ?? '');
        }
        finally {
            $this->db->popResult();
        }
        return $label === '' ? (string)$statusId : $this->cms->textOrSnippet($label);
    }
}
