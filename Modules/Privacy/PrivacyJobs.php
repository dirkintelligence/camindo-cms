<?php
/**
 * Execute deletion policies (module Privacy). Instance name in the code: $privacyJobs.
 *
 * When a record of a content area is deleted, nobody deletes it directly any more:
 * delete() creates a job in `privacy_job` for each matching block of the policy of the
 * area (DeletePolicy) - due immediately or after the set period - and
 * runs the immediately due ones straight away. The rest is handled by runDue() - from the
 * scheduler task "apply deletion policies" (type privacy, SchedulerRunner).
 *
 * TRIGGERS (DeletePolicy::SOURCES)
 * --------------------
 *     record   delete() - every way: RecordService::delete() (mask, batch, API),
 *              CMS:USER_DELETE (RecordUser::cmsDelete())
 *     profile  a profile was deleted (trigger record or with its area):
 *              always all records with record.user_id = profile (triggerLinked())
 *     area     an area was deleted (scheduleArea(), from
 *              Area::hookOnSoftDelete()) - its records already lie in the trash
 *
 * SUB-RECORDS
 * --------------------
 * Children in the tree deleted together with the main record ($withChilds) and firmly
 * linked contents (link, recursively) get the jobs of the policy of THEIR area - without
 * soft_delete and purge (DeletePolicy::REMOVE_ACTIONS): the main record handles the trash and
 * final deletion for all (subtree, cascadeDependents()).
 * Its jobs carry 'children' in `fields` for this.
 *
 * For profile records the known devices and sessions of the user are deleted
 * immediately - independent of the policy.
 *
 * JOBS
 * --------------------
 * Each job carries the fields of its block with it: a later change to the
 * policy only affects future deletions. At the same point in time the actions
 * run in the order of DeletePolicy::ACTIONS (database before audit log). If
 * a job fails (for example because someone is just editing the record), it stays open and
 * is repeated on the next run - at most MAX_ATTEMPTS times, after that it is
 * recorded with an error in the log 'privacy'. If a record is
 * restored from the trash, its open jobs expire (cancel()).
 *
 * Database actions run in the policy mode of the datasets (Dataset::asPolicy()):
 * no mandatory fields, no group rights, in the audit log as 'privacy' without the
 * previous values. If audit_set_fields has already overwritten the audit log of a record,
 * AuditLog::write() writes every later entry (for example 'purge' with
 * the last state) in the same way (auditOverride()).
 *
 * @see Camindo\Core\Modules\Privacy\DeletePolicy
 * @see Camindo\Core\Modules\Privacy\PrivacyBackend::hintHtml()  note in the delete dialog
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

    /** Attempts per job before it is recorded as failed */
    public const MAX_ATTEMPTS = 20;

    /** Jobs per run of runDue() */
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
    // Triggering

    /**
     * Delete a record according to the policy of its area - together with its
     * sub-records (see class comment). If it is a profile, afterwards
     * all records of the user are always deleted with the trigger 'profile'.
     *
     * @param int           $recordId    live record
     * @param string        $source      record | profile
     * @param callable|null $softDelete  function(int $recordId): ?string - how the
     *                                   immediate action soft_delete is executed
     *                                   (RecordService: subtree, background);
     *                                   null = Record::delete()
     * @param bool          $withChilds  delete children in the tree too, otherwise they move
     *                                   up one level
     * @return string|null  'deleted' (in the trash resp. finally deleted),
     *                      'background' (trash via background process),
     *                      'changed' (remains, adjusted), 'scheduled' (only later
     *                      actions), null on error (message in Cms::errors)
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
        // Profiles: devices and sessions always removed immediately
        $profileIds = [];
        foreach ($ids as $id) {
            $member = $id === $recordId ? $row : $this->recordRow($id);
            if ($member && (string)$member['area_type'] === 'profile') $profileIds[] = $id;
        }
        foreach ($profileIds as $id) $this->removeLogins($id);

        if (!$policy) {
            // Area without policy (created before the migration): to the trash as before
            $this->system->log('privacy', "record $recordId: area {$row['area_id']} has no deletion policy - moved to trash");
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

        // Records of the deleted profiles - before the own actions, while the
        // link still exists
        foreach ($profileIds as $id) $this->triggerLinked($id);

        // Run immediately due jobs right away
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
     * An area was deleted (into the trash, Area::hookOnSoftDelete()): for
     * its records deleted in the process, schedule the blocks of the area policy with the
     * trigger 'area'. They are executed by the next run of the
     * scheduler task - also the immediately due ones; the area is still deleted
     * (transaction). If the area is restored, the jobs expire
     * (Record::hookOnRestore()).
     *
     * A profile area: devices and sessions of the profiles are removed at once, and the
     * records of the users are deleted immediately according to their
     * policies, as with any deleted profile (trigger 'profile') - restoring the
     * area does not undo this, they may then lie in the trash.
     *
     * @param int[] $recordIds  the records deleted with the area, leaves first
     * @return int  jobs created
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
     * When finally deleting a record that never had policy jobs
     * (e.g. put into the trash before the policies were introduced), at least
     * schedule the actions of the area policy for the audit log - as with the trigger
     * 'area', period counted from the deletion (record.changed_at of the trash). Executed by runDue(), so
     * after the entry 'purge'. Runs in the transaction of the deletion
     * (Record::hookOnDelete()) - a rollback takes the jobs with it.
     *
     * @return int  jobs created
     */
    public function scheduleWithoutPolicy(int $recordId): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `area_id`, `user_id`, `deleted`, `changed_at` FROM `record` WHERE `record_id` = ? AND `tmp` = 0", 'i', [$recordId]);
            $row = $this->db->fetch();
            if (!$row || (int)$row['deleted'] !== 1) return 0;
            // If a policy already ran (also completed), it governs the audit log
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
     * Create jobs for blocks, due from $start + the period of the block. $children:
     * soft_delete and purge include the children in the tree (fields.children).
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
     * Discard open jobs of a record - it was restored from the trash
     * (Record::hookOnRestore()).
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
     * Remove jobs of a record after its audit log was deleted by hand
     * (`camindo.php audit delete`): completed ones always, open ones only if the
     * record no longer exists - otherwise the policy could no longer finish deleting it.
     * Without completed jobs a later final deletion schedules the audit actions
     * anew (scheduleWithoutPolicy()) - they then also clear the entry 'purge'.
     *
     * @return array  deleted, kept (open jobs that remain)
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

    /** Open jobs of a record */
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

    /** Next open job of a record (point in time UTC) or '' */
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
    // Execute

    /**
     * Execute due jobs of all records (at most RUN_LIMIT) - from the
     * scheduler task of type privacy, which calls as often until none are due any more.
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
        // Audit log: origin 'privacy'
        $context = $this->auditLog->context();
        $this->auditLog->setContext('privacy');
        try {
            foreach ($jobs as $job) {
                // An earlier job of this run may have completed it (cancel())
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
     * Execute a job and record the result.
     *
     * @return string|null  deleted | background | changed | done, null on error
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
     * Overwrite the fields of the record - via a working copy like the frontend
     * (RecordUser), in policy mode. If the record is already finally deleted or
     * in the trash, there is nothing more to do.
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
            // Multilingual fields get the value in all languages of the record
            $languageIds = $this->record->languageIdsOf($tmpId);
            $written = [];
            foreach ($fields as $name => $value) {
                if (!isset($schema[$name]) || in_array($name, ['record_id', 'area_id', 'instance_id', 'tmp', 'deleted', 'origin_id'], true)) continue;
                $colDef = $schema[$name];
                $type = (string)($colDef['type'] ?? 'text');
                $multilingual = $this->cms->isMultilingualField($colDef);
                $value = AuditLog::renderValue((string)$value, $vars);
                if ($type === 'file') {
                    // Remove the file (including derived thumbnails) - an upload field
                    // cannot accept another value; a thumbnail field alone cannot
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

    /** To the trash - finally deleted after admin.trash_retention_days */
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
     * Finally delete, including the audit log - with the children in the tree ($children, otherwise
     * they move up) and the firmly linked contents, including their audit log.
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
     * Apply the trigger template of the area to the record: onPrivacyAction($action,
     * &$data, $context) with the loaded record - multilingual fields as
     * language_id => value, file fields with their metadata. What the template changes
     * in $data (except file fields and identifiers) is saved via a working copy in
     * policy mode; linked records, files etc. it handles
     * itself ($this->record, $this->db …). An exception in the template makes
     * the job fail (new attempt at the next run). If the record is in the
     * trash, the template runs with $context['deleted'] = true, and then
     * nothing is saved.
     */
    private function recordTrigger(int $recordId, string $action, array $job): string
    {
        $row = $this->recordRow($recordId);
        if ($row === null) return 'done';
        $areaId = (int)$row['area_id'];
        $this->requireTrigger($areaId);
        $schema = $this->record->virtualSchema($areaId);
        $context = $this->triggerContext($job, 'record') + ['deleted' => (int)$row['deleted'] === 1];

        // In the trash: the template runs (clean up linked data …), nothing is saved
        // on the record itself - it cannot be checked out
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
                // Multilingual fields in all languages (load() returns only one)
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
     * Apply the trigger template of the area to the audit log of the record:
     * onPrivacyAction($action, &$entry, $context) per entry (AuditLog::transformObject()).
     * Also for an already deleted record - the area is stated in the job.
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

    /** Without a trigger template with onPrivacyAction() the action cannot run */
    private function requireTrigger(int $areaId): void
    {
        if (!$this->triggers->has($this->record, $areaId, 'onPrivacyAction')) {
            throw new \RuntimeException("area $areaId: no trigger template with onPrivacyAction()");
        }
    }

    /** Third argument of onPrivacyAction() */
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
     * Delete the records of the user (record.user_id = profile) according to the policies of their
     * areas with the trigger "profile" - always when a profile is deleted.
     * Whoever has no block for "profile" there remains.
     */
    private function triggerLinked(int $profileId): void
    {
        foreach ($this->linkedIds($profileId) as $id) {
            $this->cms->errorsReset();
            if ($this->delete($id, 'profile') === null && $this->cms->hasErrors) {
                // no action for "profile" is not an error of the profile
                $this->system->log('privacy', "record $id (linked to profile $profileId): " . $this->cms->errorMsg(' | '));
            }
        }
        $this->cms->errorsReset();
    }

    // ====================
    // Audit-Log

    /**
     * Already executed overwrite of the audit log of a record - for
     * AuditLog::write(): [fields, vars] of the last completed job
     * audit_set_fields, otherwise null.
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
    // Description (note in the delete dialog)

    /**
     * What happens with the database when a record is deleted - for the
     * note in the delete dialog (PrivacyBackend::hintHtml()).
     *
     * @return array  type (area type), policy (label), pending (point in time of the
     *                next open job or ''), plan (see plan()), linked
     *                (for profiles, list per area: count, area, plan - null =
     *                remain), linked_count (all records of the user)
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
     * Like describe(), but for an area instead of a record (batch processing):
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
     * The database actions of blocks, ordered by point in time.
     *
     * @return array  now: trash | purge | change | keep, now_fields (list of
     *                label/value/kind for "change"), later: list of delay, unit,
     *                items (list of kind = purge | trash | field, label, value, kind)
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
        // To the trash: finally deleted after the period of the trash
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

    /** A field of the description: label, value, kind of display */
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

    /** record row with the type of the area, null = does not exist (any more) */
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

    /** Policy of an area as a row (blocks decoded), null = none */
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

    /** Blocks of a policy for a trigger */
    private function blocksFor(array $policy, string $source): array
    {
        return array_values(array_filter((array)$policy['blocks'], fn($b) => in_array($source, (array)$b['sources'], true)));
    }

    /** Live records of a user (record.user_id) */
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

    /** Devices and sessions of a profile */
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

    /** Due, open jobs of records in execution order */
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

    /** ORDER BY expression: actions in the order of DeletePolicy::ACTIONS */
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
     * Sub-records that are deleted with a record: the children in the tree
     * ($withChilds), plus - recursively, also from the children - the firmly linked
     * contents (link). Without the record itself.
     *
     * @param array $row             recordRow()
     * @param bool  $includeDeleted  also records in the trash (final deletion)
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
