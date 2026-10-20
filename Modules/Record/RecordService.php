<?php
/**
 * Operations around a single record that several interfaces need:
 * the input mask (RecordBackendEdit), moving (RecordBackendMove), the
 * batch processing (RecordBatch), the import and the API (RecordApi).
 *
 * Record (Dataset + traits) knows saving, working copies and locks as individual
 * tools. Which of them belong in which order - rights before the check-out,
 * check the lock, check out embedded contents along, check-in together with these
 * contents in ONE transaction, large deletions into the background - used to be in the
 * backend classes, intertwined with request and HTML. It is set down here once.
 *
 * Style as in Record: return null or false on errors, the messages in
 * Cms::errors. No request, no output - the callers decide what to show
 * (mask, batch processing report, error list of the API).
 *
 * ACTIONS AND RIGHTS (loadForAction())
 * --------------------
 *     read    read right on the area
 *     edit    write right (according to the group of the owner)
 *     delete  delete right (according to the group of the owner)
 *     copy    create in the area - and all link fields with readable
 *             target areas (the copy would otherwise take along links the
 *             group does not see)
 *     move    write + delete in the source area, link fields as with
 *             copy; the target area is checked by MoveTrait::movePrepare()
 *
 * THE OWN OPEN MASK (ownOpenCopy())
 * --------------------
 * A working copy of the session admin does not lock him out himself (getLock()) - but
 * checkOut() discards it. If the same admin changes a record by a second
 * route (batch processing, API, an LLM in his session) while he has it open in the
 * mask, the mask would lose its working copy and his save would
 * fail. loadForChange() therefore aborts and says that the record is still
 * open. The mask itself does not check this: there, opening a second time deliberately
 * replaces the first.
 *
 * @see Camindo\Core\Modules\Record\Record                the dataset
 * @see Camindo\Core\TemporaryTrait                       checkOut / checkIn / getLock
 * @see Camindo\Core\LinkTrait                            eingebettete Inhalte
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\FulltextState;
use Camindo\Core\History;
use Camindo\Core\InstanceCache;
use Camindo\Core\MassDeletion;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Privacy\PrivacyJobs;

class RecordService
{
    public const ACTIONS = ['read', 'edit', 'delete', 'copy', 'move'];

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private Session $session,
        private System $system,
        private Backend $backend,
        private InstanceCache $instanceCache,
        private MassDeletion $massDeletion,
        private History $history,
        private Record $record,
        private PrivacyJobs $privacyJobs,
        private FulltextState $fulltextState,
    )
    {
    }

    // ====================
    // Load and check

    /**
     * Load the productive record for an action and check the rights of the group
     * (see class comment).
     *
     * @param int    $id      record_id
     * @param string $action  read | edit | delete | copy | move
     * @return array|null     record (Record::load()), null on errors (Cms::errors)
     */
    public function loadForAction(int $id, string $action): ?array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("invalid action \"{$action}\"");
        }
        if (!$data = $this->record->load($id)) return null;
        if (!empty($data['tmp']) || !empty($data['deleted'])) {
            $this->cms->error($this->cms->snippet('record.error_record_not_productive'));
            return null;
        }
        $areaId = (int)$data['area_id'];
        if (!$this->backend->areaData($areaId)) {
            $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            return null;
        }

        $access = $this->backend->access();
        $ownerId = isset($data['owner_id']) ? (int)$data['owner_id'] : null;
        $allowed = match ($action) {
            'read'   => $access->areaRead($areaId),
            // change: the current status must also be settable for the group
            'edit'   => $access->recordWrite($areaId, $ownerId, (int)($data['status_id'] ?? 0)),
            'delete' => $access->areaDelete($areaId, $ownerId),
            'copy'   => $access->areaCreate($areaId),
            'move'   => $access->areaWrite($areaId, $ownerId) && $access->areaDelete($areaId, $ownerId),
        };
        if (!$allowed) {
            // write right yes, but status not settable: name the reason
            $statusDenied = $action === 'edit' && $access->areaWrite($areaId, $ownerId);
            $this->cms->error($this->cms->snippet($statusDenied ? 'error_status_denied' : 'error_access_denied'));
            return null;
        }
        if (in_array($action, ['copy', 'move'], true) && !$this->record->relationTargetsReadable($areaId)) {
            $this->cms->error($this->cms->snippet($action === 'move' ? 'record.error_move_relation_access' : 'error_access_denied'));
            return null;
        }
        return $data;
    }

    /**
     * As loadForAction(), plus everything that stands in the way of a change without an
     * input mask: the record belongs to the expected area, no other admin has
     * it in work (except with $unlock), and the session admin does not have it
     * open in a mask himself (see class comment).
     *
     * @param int    $id              record_id
     * @param string $action          edit | delete | move
     * @param int    $expectedAreaId  > 0: the record must be in this area
     * @param bool   $unlock          bypass a foreign edit lock
     * @return array|null             record, null on errors (Cms::errors)
     */
    public function loadForChange(int $id, string $action, int $expectedAreaId = 0, bool $unlock = false): ?array
    {
        if (!$data = $this->loadForAction($id, $action)) return null;
        if ($expectedAreaId > 0 && (int)$data['area_id'] !== $expectedAreaId) {
            $this->cms->error($this->cms->snippet('error_record_not_found'));
            return null;
        }
        if (!$unlock && ($lock = $this->lockInfo($data))) {
            $this->cms->error($this->lockMessage($lock));
            return null;
        }
        if ($open = $this->ownOpenCopy($id)) {
            $this->cms->error($this->openCopyMessage($open));
            return null;
        }
        return $data;
    }

    /**
     * Edit lock of ANOTHER admin (TemporaryTrait::getLock()).
     *
     * @param array $data  productive record
     * @return array|null  ['admin_id' => int, 'changed_at' => UTC] or null
     */
    public function lockInfo(array $data): ?array
    {
        $lock = $this->record->getLock($data);
        if (!$lock) return null;
        return ['admin_id' => (int)$lock[0], 'changed_at' => (string)$lock[1]];
    }

    /**
     * Message about a lock as plain text ("Locked: being edited since … by ….")
     *
     * @param array $lock  lockInfo()
     */
    public function lockMessage(array $lock): string
    {
        return strtr($this->cms->snippet('record.error_record_locked_since'), [
            '{admin}' => $this->cms->_strip_tags($this->backend->teaser('admin', (int)$lock['admin_id'], linked: false, icon: false)),
            '{date}'  => $this->cms->_formatDate($this->cms->utcToUserTZ(substr((string)$lock['changed_at'], 0, 19))),
        ]);
    }

    /**
     * A working copy of the session admin for this record that is not yet
     * outdated (same period as getLock(): admin.edit_lock_sec) - i.e.
     * an input mask that he probably still has open.
     *
     * @param int $recordId  productive record
     * @return array|null    ['tmp_id' => int, 'changed_at' => UTC] or null
     */
    public function ownOpenCopy(int $recordId): ?array
    {
        $adminId = (int)$this->session->get('admin_id');
        if ($adminId < 1 || $recordId < 1) return null;
        $outdatedAt = gmdate('Y-m-d H:i:s', time() - (int)$this->config->get('admin.edit_lock_sec'));
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `record_id`, `changed_at` FROM `record`"
                . " WHERE `tmp` = 1 AND `deleted` = 0 AND `origin_id` = ? AND `changed_by` = ? AND `changed_at` >= ?"
                . " ORDER BY `changed_at` DESC LIMIT 1",
                'iis', [$recordId, $adminId, $outdatedAt]
            );
            $row = $this->db->num_rows > 0 ? $this->db->fetchNum() : null;
        }
        finally {
            $this->db->popResult();
        }
        return $row ? ['tmp_id' => (int)$row[0], 'changed_at' => (string)$row[1]] : null;
    }

    /**
     * Message on ownOpenCopy() as plain text.
     */
    public function openCopyMessage(array $open): string
    {
        return strtr($this->cms->snippet('record.error_record_open'), [
            '{date}' => $this->cms->_formatDate($this->cms->utcToUserTZ(substr((string)$open['changed_at'], 0, 19))),
        ]);
    }

    /**
     * How many other productive, not deleted records a record is linked with - via any
     * link field (relation=any + dest_id). Two-way relations are stored in `data_rel`
     * in both directions and are therefore counted, also via opposite fields that are hidden in the
     * mask (e.g. the articles of a category). Needed by the delete confirmation
     * (RecordBackendEdit::_editDeleteForm()).
     *
     * @param int $recordId  productive record
     * @return int           number of linked records
     */
    public function linkedRecordCount(int $recordId): int
    {
        if ($recordId < 1) return 0;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT COUNT(DISTINCT `r`.`src_id`) FROM `data_rel` `r`"
                . " JOIN `record` `s` ON `s`.`record_id` = `r`.`src_id` AND `s`.`tmp` = 0 AND `s`.`deleted` = 0"
                . " WHERE `r`.`dest_id` = ? AND `r`.`src_id` <> ?",
                'ii', [$recordId, $recordId]
            );
            return (int)$this->db->fetchNum()[0];
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Rebuild the full text of whole areas - all full-text fields and the
     * standard full-text field, for every productive record (also those in the
     * trash: they should be found after restoring). Writes only
     * `data_ftx`: no working copy, no lock, no audit entry (the full text
     * is derived from the source fields). Called by the scheduler task
     * `fulltext` (SchedulerRunner::runFulltext()) and by the CLI command
     * `fulltext` (button "Update now").
     *
     * Per area under the lock of FulltextState: if another process is currently rebuilding
     * it, it is skipped ('skipped'). After a complete run the mark "outdated"
     * is cleared (FulltextState::clear()).
     *
     * @param int[]         $areaIds   areas; empty = all areas except folders
     * @param int           $deadline  Unix time from which to abort (0 = no limit)
     * @param callable|null $progress  fn(int $done, int $total) - progress per record
     * @return array        ['areas' => int, 'records' => int, 'complete' => bool, 'skipped' => int[]]
     */
    public function rebuildFulltext(array $areaIds, int $deadline = 0, ?callable $progress = null): array
    {
        $areaIds = $this->system->sanitizeIds($areaIds, true);
        if (empty($areaIds)) {
            $this->db->query("SELECT `area_id` FROM `area` WHERE `deleted` = 0 AND `type` IN ('record', 'profile', 'ugc') ORDER BY `lft`");
            $areaIds = array_map('intval', $this->db->fetchAll());
        }
        $state = $this->fulltextState;
        $result = ['areas' => 0, 'records' => 0, 'complete' => true, 'skipped' => []];
        // total count for the progress
        $total = 0;
        if ($progress !== null) {
            $this->db->query("SELECT COUNT(*) FROM `record` WHERE `tmp` = 0 AND `area_id` IN (" . implode(',', array_map('intval', $areaIds) ?: [0]) . ")");
            $total = (int)($this->db->fetchNum()[0] ?? 0);
        }
        foreach ($areaIds as $areaId) {
            if (!$state->lock($areaId)) {
                $result['skipped'][] = $areaId;
                continue;
            }
            try {
                $this->db->query("SELECT UTC_TIMESTAMP()");
                $startedAt = (string)$this->db->fetchNum()[0];
                $this->db->query("SELECT `field_id` FROM `field` WHERE `area_id` = ? AND `type` = 'fulltext' AND `deleted` = 0", 'i', [$areaId]);
                $fieldIds = array_map('intval', $this->db->fetchAll());
                $this->db->query("SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 0 ORDER BY `record_id`", 'i', [$areaId]);
                $recordIds = array_map('intval', $this->db->fetchAll());
                foreach ($recordIds as $recordId) {
                    if ($deadline > 0 && time() >= $deadline) {
                        $result['complete'] = false;
                        return $result;
                    }
                    foreach ($fieldIds as $fieldId) {
                        $this->record->refreshFulltext($fieldId, $recordId);
                    }
                    $this->record->refreshStandardFulltext($areaId, $recordId);
                    $result['records']++;
                    if ($result['records'] % 50 === 0) {
                        $state->touch($areaId);
                        if ($progress !== null) $progress($result['records'], $total);
                    }
                }
                $state->clear($areaId, $startedAt);
                $result['areas']++;
            }
            finally {
                $state->unlock($areaId);
            }
        }
        if ($progress !== null) $progress($result['records'], $total);
        return $result;
    }

    // ====================
    // Working copy

    /**
     * Create a working copy: checkOut(), assign it to the session (logging out releases it,
     * Record::cancelSession()) and - with $dependents - also check out the firmly linked
     * contents of embedded 'link' fields. If this fails (a foreign lock on
     * a content), the working copy is discarded straight away again.
     *
     * When copying ($copy), $data['previous_id'] receives the id of the template: the
     * copy should stand directly behind it, at the same parent record
     * (TreeManagerTrait::calculatePosition()).
     *
     * The caller checks the lock beforehand (lockInfo() or loadForChange()).
     *
     * @param array &$data       productive record (loadForAction()); afterwards points
     *                           to the working copy (record_id, tmp, origin_id)
     * @param bool  $copy        copy instead of edit
     * @param bool  $dependents  also check out embedded contents (input mask)
     * @return int|null          id of the working copy, null on errors (Cms::errors)
     */
    public function checkOut(array &$data, bool $copy = false, bool $dependents = true): ?int
    {
        $sourceId = (int)$data['record_id'];
        $areaId = (int)$data['area_id'];
        if (!$tmpId = $this->record->checkOut($data, $copy)) {
            if (!$this->cms->hasErrors) $this->cms->error('RecordService::checkOut(): check-out failed.');
            return null;
        }
        $this->session->registerWorkingCopy($tmpId);
        if ($copy) $data['previous_id'] = $sourceId;

        if ($dependents) {
            $schemaVirtual = $this->record->virtualSchema($areaId, addMetaFields: false);
            if (!$this->record->checkOutDependents($tmpId, $copy, $schemaVirtual)) {
                $this->record->cancel($tmpId);
                $data = $this->record->load($sourceId) ?? $data;
                return null;
            }
        }
        return $tmpId;
    }

    /**
     * The fields that saving a working copy writes: all fields of the
     * area, area_id, and owner_id only with the additional right (areaSetOwner) and
     * if one is given.
     *
     * @param int   $areaId
     * @param array $data  values to save
     * @return string[]
     */
    public function saveFields(int $areaId, array $data): array
    {
        $fields = array_keys($this->record->virtualSchema($areaId, addMetaFields: false));
        $fields[] = 'area_id';
        if ($this->backend->access()->areaSetOwner($areaId) && !empty($data['owner_id'])) {
            $fields[] = 'owner_id';
        }
        return $fields;
    }

    /**
     * Check in a working copy: in ONE transaction first the firmly linked
     * contents (LinkTrait::checkInDependents(): also copies and orphans), then the
     * record itself (Record::save() - validation, checkIn, files, tree).
     * If one fails, everything is rolled back; the working copies remain.
     * Afterwards the list "Last changed" (History).
     *
     * The embedded contents must have been cached beforehand
     * (RecordBackendEdit::_embedSave()).
     *
     * @param int      $tmpId      working copy of the session admin
     * @param string[] $fields     fields to write (saveFields())
     * @param array    $data       values in the input format of the mask, record_id = $tmpId
     * @param string[] $stored     fields whose values are in database format
     *                             (Record::importSave() - API, copy without mask)
     * @param string[] $prehashed  password fields whose value is the hash
     * @return int|null            record_id of the productive record, null on errors
     */
    public function checkIn(int $tmpId, array $fields, array $data, array $stored = [], array $prehashed = []): ?int
    {
        // If the copy no longer belongs to the session admin - someone has lifted the lock -
        // save() reports this with the matching text
        $prodData = $this->record->getProdData($tmpId);
        $originId = (int)($prodData[0] ?? 0);
        $areaId = (int)($prodData[1] ?? $data['area_id'] ?? 0);
        $schemaVirtual = $this->record->virtualSchema($areaId, addMetaFields: false);
        $hasLinks = !empty($this->record->linkFields($schemaVirtual));
        $data['record_id'] = $tmpId;

        $newId = null;
        $this->db->beginTransaction();
        try {
            if ($hasLinks && !$this->record->checkInDependents($tmpId, $originId)) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $newId = ($stored || $prehashed)
                ? $this->record->importSave($fields, $data, $stored, $prehashed)
                : $this->record->save($fields, $data);
            if (!$newId) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $this->db->commit();
        }
        catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            $this->db->rollback();
            return null;
        }
        $this->history->touch('record', (int)$newId);
        return (int)$newId;
    }

    // ====================
    // Change without a mask

    /**
     * Change a record in ONE step: load and check (loadForChange()),
     * check out, links and files to the working copy, save
     * (Record::importSave(): validation according to the field definitions, checkIn, cache,
     * full text, tree maintenance - all as in the input mask). If the check fails
     * or it is a simulation, the working copy is discarded - the
     * error messages are kept in the process.
     *
     * Embedded contents ('link') are not checked out: they are not edited here,
     * their links travel with the record there and back.
     *
     * @param int      $id         productive record
     * @param string[] $fields     fields from $data (schema names of the area); plus
     *                             'owner_id' (only with the right "adjust owner") and
     *                             'position' (value: $data['previous_id'])
     * @param array    $data       values as in the mask (data[...]), multilingual per
     *                             language_id
     * @param array    $relations  link field => [dest_id, ...] (replaces the
     *                             previous ones) or ['set' | 'add' | 'remove' |
     *                             'order' => [dest_id, ...]] - see applyRelations()
     * @param array    $files      upload field => [langKey ('' or language_id) =>
     *                             ['importfile' => path under paths.tmp_dir, 'filename' => name]
     *                             | ['serverfile' => path in paths.upload_dir]
     *                             | null (remove file)]
     * @param array    $options    area_id (expected area), unlock, simulate,
     *                             stored (fields in database format), prehashed
     *                             (password fields whose value is the hash),
     *                             history (false: not in "Last changed" -
     *                             batch processing)
     * @return int|null            record_id, null on errors (Cms::errors)
     */
    public function updateOne(int $id, array $fields, array $data, array $relations = [], array $files = [], array $options = []): ?int
    {
        $simulate = !empty($options['simulate']);
        $record = $this->loadForChange($id, 'edit', (int)($options['area_id'] ?? 0), !empty($options['unlock']));
        if (!$record) return null;
        $areaId = (int)$record['area_id'];
        $fields = $this->changeFields($areaId, $fields, $relations, $files);
        if ($fields === null) return null;

        if (!$tmpId = $this->checkOut($record, false, dependents: false)) return null;

        $saveData = ['record_id' => $tmpId, 'area_id' => $areaId];
        $ok = $this->applyChanges($tmpId, $areaId, $fields, $data, $relations, $files, $saveData);
        $fields[] = 'area_id';

        if ($ok) {
            $ok = (bool)$this->record->importSave(
                $fields, $saveData, (array)($options['stored'] ?? []), (array)($options['prehashed'] ?? []),
                stayTemporary: $simulate
            );
        }
        if ($simulate || !$ok) {
            // simulation: no checkIn - on error: do not leave the working copy behind
            $this->cancelKeepErrors($tmpId);
            return $ok ? $id : null;
        }
        if (($options['history'] ?? true) !== false) $this->history->touch('record', $id);
        return $id;
    }

    /**
     * Create a record in ONE step - like updateOne(), only with a
     * new working copy (tmpInit(), checks the right to create). Not in
     * dependent areas (area.dependent): their records are only created via a
     * field of type 'link'.
     *
     * @param int   $areaId   area
     * @param array $options  simulate, stored, prehashed (see updateOne())
     * @return int|null       record_id of the new record (simulation: 0)
     */
    public function createOne(int $areaId, array $fields, array $data, array $relations = [], array $files = [], array $options = []): ?int
    {
        $areaData = $this->backend->areaData($areaId);
        if (!$areaData) {
            $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            return null;
        }
        if ((int)($areaData['dependent'] ?? 0) === 1) {
            $this->cms->error($this->cms->snippet('error_access_denied'));
            return null;
        }
        $fields = $this->changeFields($areaId, $fields, $relations, $files, allowEmpty: true);
        if ($fields === null) return null;
        if (!$tmpData = $this->record->tmpInit(['area_id' => $areaId])) {
            if (!$this->cms->hasErrors) $this->cms->error('RecordService::createOne(): creation of working copy failed.');
            return null;
        }
        $tmpId = (int)$tmpData['record_id'];
        $this->session->registerWorkingCopy($tmpId);

        $saveData = ['record_id' => $tmpId, 'area_id' => $areaId];
        $ok = $this->applyChanges($tmpId, $areaId, $fields, $data, $relations, $files, $saveData);
        $fields[] = 'area_id';
        $simulate = !empty($options['simulate']);
        $newId = null;
        if ($ok) {
            $newId = $this->record->importSave(
                $fields, $saveData, (array)($options['stored'] ?? []), (array)($options['prehashed'] ?? []),
                stayTemporary: $simulate
            );
        }
        if ($simulate || !$newId) {
            $this->cancelKeepErrors($tmpId);
            return $newId ? 0 : null;
        }
        $this->history->touch('record', (int)$newId);
        return (int)$newId;
    }

    /**
     * Copy a record in ONE step and change it in the process - like "Copy" in
     * the mask: the copy including files and firmly linked contents (as copies)
     * stands directly behind the template; children in the tree are not copied along.
     * What is in $fields is changed; the copy takes over everything else.
     *
     * @param int   $id       template (productive)
     * @param array $options  simulate, stored, prehashed (see updateOne())
     * @return int|null       record_id of the copy (simulation: 0)
     */
    public function copyOne(int $id, array $fields, array $data, array $relations = [], array $files = [], array $options = []): ?int
    {
        if (!$record = $this->loadForAction($id, 'copy')) return null;
        $areaId = (int)$record['area_id'];
        $fields = $this->changeFields($areaId, $fields, $relations, $files, allowEmpty: true);
        if ($fields === null) return null;
        if (!$tmpId = $this->checkOut($record, true, dependents: true)) return null;

        $saveData = ['record_id' => $tmpId, 'area_id' => $areaId];
        $ok = $this->applyChanges($tmpId, $areaId, $fields, $data, $relations, $files, $saveData);
        $fields[] = 'area_id';
        // place it behind the template (checkOut() has set previous_id)
        $schemaVirtual = $this->record->virtualSchema($areaId, addMetaFields: false);
        if (isset($schemaVirtual['position']) && !in_array('position', $fields, true)) {
            $fields[] = 'position';
            $saveData['previous_id'] = $id;
        }
        if (!$ok || !empty($options['simulate'])) {
            if ($ok) {
                $ok = (bool)$this->record->importSave($fields, $saveData, (array)($options['stored'] ?? []),
                    (array)($options['prehashed'] ?? []), stayTemporary: true);
            }
            $this->cancelKeepErrors($tmpId);
            return $ok ? 0 : null;
        }
        $newId = $this->checkIn($tmpId, $fields, $saveData, (array)($options['stored'] ?? []), (array)($options['prehashed'] ?? []));
        if (!$newId) $this->cancelKeepErrors($tmpId);
        return $newId;
    }

    /**
     * Check the fields of a change: fields of the area with an input field (no
     * full-text and thumbnail fields, no embedded contents), plus 'owner_id'
     * with the right "adjust owner".
     *
     * @return string[]|null  fields (from $fields, $relations and $files); null on
     *                        errors (Cms::errors) or without fields (except $allowEmpty)
     */
    private function changeFields(int $areaId, array $fields, array $relations, array $files, bool $allowEmpty = false): ?array
    {
        $schemaVirtual = $this->record->virtualSchema($areaId, addMetaFields: false);
        $fields = array_values(array_unique(array_merge(
            array_filter($fields, 'is_string'), array_keys($relations), array_keys($files)
        )));
        $ok = true;
        foreach ($fields as $name) {
            if ($name === 'owner_id' && $this->backend->access()->areaSetOwner($areaId)) continue;
            $colDef = $schemaVirtual[$name] ?? null;
            $type = (string)($colDef['type'] ?? '');
            if ($colDef === null
                || $type === 'fulltext'
                || (int)($colDef['parent_field_id'] ?? 0) > 0
                || in_array($type, $this->cms->dependentTypes, true)
                || !$this->record->relationTargetReadable($colDef)
            ) {
                $this->cms->error(strtr($this->cms->snippet('error_field_unknown_type'), ['{name}' => $name]), $name);
                $ok = false;
            }
        }
        if (!$ok) return null;
        if (empty($fields) && !$allowEmpty) {
            $this->cms->error($this->cms->snippet('record.error_batch_no_fields'));
            return null;
        }
        return $fields;
    }

    /**
     * Apply the changes to a working copy and fill $saveData:
     * links (applyRelations()), files (deletion marker or source for
     * secureUploads()), the other values from $data; 'position' takes
     * $data['previous_id'] (so does 'parent_id' without 'position').
     *
     * @return bool  false on errors (Cms::errors)
     */
    private function applyChanges(int $tmpId, int $areaId, array $fields, array $data, array $relations, array $files, array &$saveData): bool
    {
        $schemaVirtual = $this->record->virtualSchema($areaId, addMetaFields: false);
        $ok = true;
        foreach ($fields as $name) {
            $colDef = $schemaVirtual[$name] ?? [];
            $type = (string)($colDef['type'] ?? '');
            if (array_key_exists($name, $relations)) {
                if (!in_array($type, $this->cms->relationTypes, true)) {
                    $this->cms->error($this->cms->snippet('error_param_invalid'), $name);
                    $ok = false;
                    continue;
                }
                $destIds = $this->applyRelations($tmpId, $colDef, $relations[$name]);
                if ($destIds === null) {
                    $ok = false;
                    continue;
                }
                // save() checks the count, checkIn() transfers it
                $saveData[$name] = $destIds;
                continue;
            }
            if (array_key_exists($name, $files)) {
                if ($type !== 'file') {
                    $this->cms->error($this->cms->snippet('error_param_invalid'), $name);
                    $ok = false;
                    continue;
                }
                $isMultilingual = $this->cms->isMultilingualField($colDef);
                $value = [];
                foreach ((array)$files[$name] as $langKey => $fileData) {
                    $languageId = ((string)$langKey === '') ? null : (int)$langKey;
                    if ($fileData === null) {
                        if (!$this->record->markFileDeleted($tmpId, (int)$colDef['field_id'], $languageId)) $ok = false;
                        continue;
                    }
                    if ($isMultilingual && $languageId !== null) $value[$languageId] = $fileData;
                    else $value = $fileData;
                }
                if (!empty($value)) $saveData[$name] = $value;
                continue;
            }
            if ($name === 'position') {
                $saveData['previous_id'] = (int)($data['previous_id'] ?? 0);
                continue;
            }
            // parent record with a predecessor, but without the field `position` (batch
            // processing: append at the end of the new siblings)
            if ($name === 'parent_id' && array_key_exists('previous_id', $data) && !in_array('position', $fields, true)) {
                $saveData['previous_id'] = (int)$data['previous_id'];
            }
            $saveData[$name] = $data[$name] ?? '';
        }
        return $ok;
    }

    /**
     * Change the links of a field on a working copy.
     *
     *     [id, id, …]            like 'set'
     *     ['set' => [...]]       replaces all existing ones
     *     ['add' => [...]]       appends (existing ones remain)
     *     ['remove' => [...]]    removes these
     *     ['order' => [...]]     order (only fields with manual sorting);
     *                            ids not named follow after them
     *
     * 'add', 'remove' and 'order' can be combined (in this order).
     *
     * @return int[]|null  the links afterwards in their order; null on
     *                     errors (Cms::errors)
     */
    public function applyRelations(int $tmpId, array $colDef, array $ops): ?array
    {
        if (array_is_list($ops)) $ops = ['set' => $ops];
        $ids = fn(string $key) => array_values(array_filter(array_map('intval', (array)($ops[$key] ?? [])), fn($id) => $id > 0));
        try {
            if (array_key_exists('set', $ops)) {
                $this->record->createRelations($colDef, $tmpId, $ids('set'), replace: true, checkTmp: false);
            }
            if ($ids('add')) {
                $this->record->createRelations($colDef, $tmpId, $ids('add'), replace: false, checkTmp: false);
            }
            foreach ($ids('remove') as $destId) {
                $this->record->removeRelations($colDef, $tmpId, $destId);
            }
        } catch (\Exception $e) {
            if (!str_starts_with($e->getMessage(), '#')) $this->cms->error($e->getMessage(), (string)$colDef['name']);
            return null;
        }
        $current = $this->relationIds($tmpId, (int)$colDef['field_id']);
        if ($order = $ids('order')) {
            $order = array_values(array_intersect($order, $current));
            $current = array_values(array_merge($order, array_diff($current, $order)));
            $this->record->linkReorder($tmpId, (int)$colDef['field_id'], $current);
        }
        return $current;
    }

    /** dest_ids of a field of a working copy in their order */
    private function relationIds(int $srcId, int $fieldId): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `dest_id` FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? ORDER BY `position`, `dest_id`",
                'ii', [$fieldId, $srcId]);
            return array_map('intval', $this->db->fetchColumn());
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Discard a working copy without losing the error messages collected so far
     * (cancel() itself runs queries and possibly emits messages).
     */
    public function cancelKeepErrors(int $tmpId): void
    {
        $errors = $this->cms->errors;
        $this->record->cancel($tmpId);
        $this->cms->errorsReset();
        foreach ($errors as $key => $error) {
            $this->cms->error((string)$error['text'], is_int($key) ? '' : (string)$key, (string)($error['label'] ?? ''));
        }
    }

    // ====================
    // Delete

    /**
     * Delete a record - according to the deletion policy of its area (module Privacy,
     * PrivacyJobs::delete(), trigger 'record'). What "delete" means is determined by the
     * policy: to the trash, overwrite fields, delete permanently … - also for
     * the children deleted along ($withChilds) and firmly linked contents.
     *
     * The caller checks rights and lock (loadForAction() / loadForChange()).
     *
     * If the working copy of the session admin is passed (delete dialog of the mask),
     * the original is meant; the working copy is discarded beforehand - otherwise it would
     * remain until the trash is emptied, or get in the way when the fields are overwritten.
     *
     * @param int  $id          productive record or its working copy
     * @param bool $withChilds  also delete descendants (own policy jobs; to the
     *                          trash with the action soft_delete of the record),
     *                          otherwise they move up one level
     * @return string|null      'deleted' | 'background' | 'changed' | 'scheduled',
     *                          null on errors (Cms::errors)
     */
    public function delete(int $id, bool $withChilds = false): ?string
    {
        $prodData = $this->record->getProdData($id);
        $tmpId = ($prodData !== null && $prodData[0] > 0) ? $id : 0;
        $prodId = $tmpId > 0 ? (int)$prodData[0] : $id;
        if ($tmpId > 0) $this->cancelKeepErrors($tmpId);
        return $this->privacyJobs->delete($prodId, 'record', fn(int $recordId) => $this->softDelete($recordId, $withChilds), $withChilds);
    }

    /**
     * To the trash (soft delete, Record::delete()) - the action soft_delete
     * of a deletion policy, triggered in the backend. A large subtree ($withChilds, from
     * admin.mass_delete_threshold) goes to the background (MassDeletion); if
     * its start fails, the deletion is done in the request.
     *
     * @return string|null  'deleted' | 'background', null on errors (Cms::errors)
     */
    private function softDelete(int $prodId, bool $withChilds): ?string
    {
        // counted and started in the background via the productive id - the
        // working copy has lft = rgt = 0, the subtree hangs on the original
        $result = null;
        $count = $this->massDeletion->affectedCount('record', $prodId, $withChilds, 'delete');
        if ($this->massDeletion->needsBackground($count)) {
            $error = '';
            if ($this->massDeletion->start('record', $prodId, 'delete', $withChilds, $error)) {
                $result = 'background';
            }
            else {
                // start failed -> continue in the request instead of not deleting at all
                $this->system->log('massdelete-errors', "record {$prodId}: {$error}");
            }
        }
        if ($result === null && $this->record->delete($prodId, $withChilds)) $result = 'deleted';
        return $result;
    }

    // ====================
    // Export and import

    /**
     * Which formats may the group export or import? XML (backup, structure transfer,
     * also global) only the superuser, CSV and DOCX via the additional rights
     * export_csv/import_csv or export_docx/import_docx.
     *
     * @param string $direction  'export' | 'import'
     * @return string[]  subset of RecordExport::TYPES in their order
     */
    public function transferTypes(string $direction): array
    {
        $access = $this->backend->access();
        $types = [];
        if ($access->isSuperuser()) $types[] = 'xml';
        if ($access->moduleRight('Record', $direction . '_csv')) $types[] = 'csv';
        if ($access->moduleRight('Record', $direction . '_docx')) $types[] = 'docx';
        return $types;
    }

    // ====================
    // Search

    /**
     * Resolve the search field '_url' (search mask, RecordBackendList, API): what may be
     * entered is a record_id ("4902"), a slug with or without slug prefix ("gt",
     * "modelle/gt") or a URL ("http://automobile.localhost/de_DE/modelle/gt").
     *
     *     digits                     -> record_id
     *     URL                        -> path; the domain narrows to its instance
     *     language segment at start  -> ignored (only with a further path behind it)
     *     ",template" at the end     -> ignored
     *     first path part = prefix   -> slug = rest, area_id = the areas with this
     *                                   prefix (in any language, InstanceCache)
     *     otherwise                  -> slug = whole path
     *
     * The slug is compared exactly ('==').
     *
     * @return array  filter values: record_id | slug [, area_id (int[]), instance_id]
     *                - [] for empty input
     */
    public function urlFilter(string $input): array
    {
        $input = trim($input);
        if ($input === '') return [];
        if (ctype_digit($input)) return ['record_id' => $input];

        $instanceIds = [];
        $path = $input;
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $input)) {
            $url = parse_url($input) ?: [];
            $path = (string)($url['path'] ?? '');
            $instanceId = $this->instanceCache->matchDomain(strtolower((string)($url['host'] ?? '')));
            if ($instanceId > 0) $instanceIds = [$instanceId];
        }
        $path = (string)preg_replace('/[?#].*$/s', '', $path);
        $segments = array_values(array_filter(explode('/', rawurldecode($path)), fn($p) => trim($p) !== ''));
        if ($segments && strtolower($segments[0]) === 'index.php') array_shift($segments);

        // language segment (code of a language of the instance or of all instances)
        if (count($segments) > 1) {
            $languages = $this->instanceCache->languagesOf($instanceIds
                ?: array_map('intval', (array)($this->instanceCache->all()['instance_ids'] ?? [])));
            if ($this->instanceCache->findByCode($languages, $segments[0]) !== null) array_shift($segments);
        }
        // Template-Angabe "slug,template"
        if ($segments) {
            $last = array_pop($segments);
            $segments[] = explode(',', $last, 2)[0];
        }
        $path = trim(implode('/', $segments), '/');
        if ($path === '') return ['record_id' => '0'];
        if (ctype_digit($path)) return ['record_id' => $path];

        $filter = [];
        if (count($segments) > 1) {
            $first = mb_strtolower($segments[0], 'UTF-8');
            $areaIds = [];
            foreach ($this->instanceCache->slugPrefixAreas($instanceIds) as $areaId => $prefixes) {
                foreach ($prefixes as $prefix) {
                    if (mb_strtolower((string)$prefix, 'UTF-8') === $first) {
                        $areaIds[] = (int)$areaId;
                        break;
                    }
                }
            }
            if ($areaIds) {
                $path = implode('/', array_slice($segments, 1));
                $filter['area_id'] = $areaIds;
            }
        }
        $filter['slug'] = '==' . $path;
        if ($instanceIds) $filter['instance_id'] = (string)$instanceIds[0];
        return $filter;
    }
}
