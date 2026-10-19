<?php
/**
 * Batch processing of records: change one field in many records, move many
 * records to another area, delete them or rebuild their full text.
 *
 * This is the WORK; the mask for it is RecordBackendBatch (record_batch.php), the
 * background process Core/cron/record_batch.php. Both call run() - in the background
 * EXACTLY THE SAME CODE runs; the process merely moves the work out of the request
 * (as with MassDeletion).
 *
 * THE JOB
 * --------------------
 *     mode            'edit' | 'move' | 'delete'
 *     area_id         area of the records
 *     ids             record_ids (productive), determined by collectIds()
 *     simulate        true: check, change nothing (see below)
 *     fields          edit: names of the checked fields
 *     data            edit: raw input of the mask (data[...] as in a normal
 *                     save, multilingual per language) - only the checked fields;
 *                     passwords as hash (see prehashed); tree fields as
 *                     data['parent_id'] and data['previous_id'] (see TREE FIELDS)
 *     stored          edit: fields whose values in data are in database format
 *                     (API, ApiValues::input())
 *     prehashed       edit: password fields whose value in data is the hash
 *     relations       edit: lookup link field => [dest_id, ...] (replaces); via
 *                     the API every link field, also as set/add/remove/order
 *     files           edit: upload field => [langKey ('' or language_id) =>
 *                     ['importfile' => path, 'filename' => name] | null (= delete)]
 *     files_dir       edit: directory of the files under var/tmp (removed after the
 *                     run)
 *     target_area_id  move: target area
 *     mapping         move: source field => target field | '' (see MoveTrait)
 *
 * THE TEMPLATE (edit)
 * --------------------
 * Links via a search window (lookup) and files already need a record_id to hang on
 * while being filled in - just as when creating a record. The batch edit mask
 * therefore creates a working copy without original (Record::tmpInit(), the
 * TEMPLATE): lookups write their data_rel rows to it, uploads lie physically under its
 * id (Record::secureInputs()). When running, editJob() turns this into the job -
 * ids of the links, files as a copy under var/tmp ('importfile', as with the import), password
 * hashes - and the template is discarded. The job is thereby self-contained and also runs
 * in the background.
 *
 * For the background, start() writes the job as JSON to var/tmp and passes
 * the path to the process (loadJob()).
 *
 * PER RECORD THE SAME WAY AS BY HAND
 * --------------------
 *     edit    RecordService::updateOne(): load, rights, edit lock ->
 *             checkOut() -> links of the checked lookup fields to the
 *             working copy (createRelations()), deletion markers for files
 *             (markFileDeleted()) -> Record::importSave() with the checked fields
 *             (files as 'importfile', passwords as hash): validation according to the
 *             field definitions, checkIn, cache, full text, tree maintenance - all as in
 *             the input mask. If the check fails, the working copy is
 *             discarded (cancel()).
 *     move    rights, lock -> MoveTrait::moveToArea() - children, mapping,
 *             check in the target area
 *     delete  rights, lock -> Record::delete() (soft delete incl. hooks)
 *
 * TREE FIELDS (edit)
 * --------------------
 * parent_id and position move the records - one after the other, in their current
 * order (tree order lft, otherwise position; _treeOrder()):
 *
 *     parent_id only    each record is appended at the end of the children of the new
 *                       parent record (predecessor: lastChildId()) - so they stand
 *                       there last, in their previous order
 *     position          the first record goes behind the chosen predecessor
 *                       (data['previous_id'], 0 = at the start), each further one
 *                       behind the record moved before it. The level is the parent
 *                       record from data['parent_id'] - without parent_id among the
 *                       fields that of the predecessor (0: root level). The
 *                       predecessor itself stays where it is, if it is in the scope
 *
 * A new parent record that is one of the records itself or lies in its subtree is
 * refused per record (TreeManagerTrait::isInSubtree()).
 *
 * "Lock" here also means: the triggering admin still has the record open
 * in an input mask - the check-out would discard that working copy
 * (RecordService::loadForChange()).
 *
 * SIMULATION
 * --------------------
 * checkOut -> save WITH input check -> but no checkIn: Record::save() with
 * stayTemporary (the checked values only land in the working state of the working copy), then
 * cancel(). When moving, the same within moveToArea($simulate): the
 * prepared working copy is only saved with stayTemporary and afterwards discarded
 * together with its copied files.
 * Deleting only checks the edit lock. Nothing is changed in any case;
 * the error messages come back per record, just as in a real run.
 *
 * RESULT
 * --------------------
 * run() returns counters and, for each failed record, the messages (Cms::errors
 * is reset per record). In the foreground RecordBackendBatch shows them
 * one below the other; in the background report() writes them to a file
 * (var/log/{Y-m-d}_batch-{token}.log) and sends the triggering admin an internal
 * message (Message::sendSystem(): only in his inbox) with the content of the
 * file - the HTML part of the message links the affected records.
 *
 * THRESHOLD
 * --------------------
 * From admin.mass_delete_threshold records on (default 200, 0 = off) the
 * job always runs in the background; below that the editor decides (needsBackground()).
 *
 * @see Camindo\Core\Modules\Record\RecordBackendBatch  the masks
 * @see Camindo\Core\MassDeletion                       same pattern for deleting
 *                                                      entire areas
 * @see Core/cron/record_batch.php                      the background process
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Backend;
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Messaging\Message;

class RecordBatch {

    public const MODES = ['edit', 'move', 'delete'];

    /** Type identifier of the background process (table `bgproc`) */
    public const TYPE = 'batch';

    /** Tree fields - moved one after the other (see TREE FIELDS) */
    public const TREE_FIELDS = ['parent_id', 'position'];

    /** Tree fields of the running job (_treePlan()) and the last moved record */
    private ?array $tree = null;
    private int $treePrevious = 0;

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private Session $session,
        private System $system,
        private Crypt $crypt,
        private BackgroundProcess $bgproc,
        private Backend $backend,
        private Record $record,
        private Message $message,
        private \Camindo\Core\AuditLog $auditLog,
        private RecordService $service,
    )
    {
    }

    // ====================
    // Scope

    /**
     * Filter parameters from the page parameters of a record list
     * (cApp.layers.queryString()): only filter, filterop, filtermode - sorting
     * and page play no role for the scope. Empty filter values are dropped.
     *
     * @param array $parsed  request parameters ($_REQUEST or parse_str() of the query string)
     * @return array         ['filter' => [...], 'filterop' => [...], 'filtermode' => [...]]
     */
    public function filterParams(array $parsed): array
    {
        $params = [];
        foreach (['filter', 'filterop', 'filtermode'] as $key) {
            $values = (array)($parsed[$key] ?? []);
            // area_id comes as its own parameter, not as a filter
            unset($values['area_id']);
            $values = array_filter($values, function ($value) {
                if (is_array($value)) return !empty(array_filter($value, fn($v) => (string)$v !== ''));
                return (string)$value !== '';
            });
            if (!empty($values)) $params[$key] = $values;
        }
        return $params;
    }

    /**
     * Does the scope contain a search (filter)?
     */
    public function hasFilter(array $filterParams): bool
    {
        return !empty($filterParams['filter']);
    }

    /**
     * Number of productive records of the area, if applicable with filter.
     */
    public function count(int $areaId, array $filterParams = []): int
    {
        if ($areaId < 1) return 0;
        $this->db->pushResult();
        $this->cms->select($this->_selectParams($areaId, $filterParams) + [
            'count' => 'true',
            'result' => 'false',
        ]);
        $count = (int)$this->db->num_rows_unlimited;
        $this->db->popResult();
        return $count;
    }

    /**
     * Ids of the affected records (productive, not deleted), if applicable with filter.
     *
     * @return int[]
     */
    public function collectIds(int $areaId, array $filterParams = [], string $sort = 'record_id', string $order = 'ASC'): array
    {
        if ($areaId < 1) return [];
        $this->db->pushResult();
        $this->cms->select($this->_selectParams($areaId, $filterParams, $sort, $order));
        $ids = [];
        while ($row = $this->db->fetch()) {
            $ids[] = (int)$row['record_id'];
        }
        $this->db->popResult();
        return $ids;
    }

    /**
     * select() parameters for counting and collecting: area condition as SQL, plus the
     * filters of the list. No 'request' - there is none in the background.
     */
    private function _selectParams(int $areaId, array $filterParams, string $sort = 'record_id', string $order = 'ASC'): array
    {
        return [
            'filter'     => (array)($filterParams['filter'] ?? []),
            'filterop'   => (array)($filterParams['filterop'] ?? []),
            'filtermode' => (array)($filterParams['filtermode'] ?? []),
            'sql'        => "`record`.`area_id` = " . (int)$areaId,
            'limit'      => 0,
            'sort'       => $sort,
            'order'      => $order,
        ];
    }

    /**
     * Should the job go to the background? From admin.mass_delete_threshold records on
     * (0 = offloading off).
     */
    public function needsBackground(int $count): bool
    {
        $threshold = (int)($this->config->get('admin.mass_delete_threshold') ?? 200);
        if ($threshold < 1) return false;
        return $count >= $threshold;
    }

    /**
     * Fields of an area that can be changed in batch: all visible
     * input fields - including links with search windows and upload fields (via
     * the template, see class comment) - except:
     *
     *     unique / unique_in_area   the same value in many records would never be
     *                               unique (if the rule is violated only with one
     *                               record, it is not a batch)
     *     thumbnails                are calculated from the upload field
     *     full text                 is generated
     *     position as lookup        the predecessor is chosen from a list
     *     link                      firmly linked contents each belong to one
     *                               record - the same content in many would belong to
     *                               none (LinkTrait)
     *
     * @param array $schemaVirtual  virtualSchema($areaId, false)
     * @return array  name => Felddefinition, in Feldreihenfolge
     */
    public function batchFields(array $schemaVirtual): array
    {
        $fields = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if ((int)($colDef['visibility_input'] ?? 0) !== 1) continue;
            $type = (string)($colDef['type'] ?? 'text');
            $displayType = (string)($colDef['display_type'] ?? 'text');
            if ($type === 'fulltext') continue;
            if ($type === 'file' && $displayType !== 'upload') continue;
            if (in_array($type, $this->cms->dependentTypes, true)) continue;
            if ($name === 'position' && $displayType === 'lookup') continue;
            $flags = (array)($colDef['flags'] ?? []);
            if (in_array('unique', $flags, true) || in_array('unique_in_area', $flags, true)) continue;
            $fields[$name] = $colDef;
        }
        return $fields;
    }

    // ====================
    // Template (edit)

    /**
     * Is $tmpId a usable template: working copy of the session admin without
     * original, in the area $areaId?
     */
    public function templateValid(int $tmpId, int $areaId): bool
    {
        if ($tmpId < 1) return false;
        if (!$prodData = $this->record->getProdData($tmpId)) return false;
        return (int)$prodData[0] === 0 && (int)$prodData[1] === $areaId;
    }

    /**
     * The checked checkboxes of the mask: "name", or for multilingual fields per
     * language "name:language_id". Only fields from batchFields(); a multilingual
     * field without language specification applies to all languages.
     *
     * @param array $checked  batch_fields[] of the request
     * @param array $allowed  batchFields() of the area
     * @return array  [string[] $fields, array $languages (name => int[] language_ids,
     *                only for fields with language selection)]
     */
    public function checkedFields(array $checked, array $allowed): array
    {
        $fields = [];
        $languages = [];
        $allLanguages = [];
        foreach ($checked as $value) {
            [$name, $languageId] = array_pad(explode(':', (string)$value, 2), 2, null);
            if (!isset($allowed[$name])) continue;
            $fields[$name] = true;
            if ($languageId === null || !$this->cms->isMultilingualField($allowed[$name])) {
                $allLanguages[$name] = true;
                continue;
            }
            if ((int)$languageId > 0) $languages[$name][] = (int)$languageId;
        }
        return [array_keys($fields), array_diff_key($languages, $allLanguages)];
    }

    /**
     * Job parts for 'edit' from the template and the inputs of the mask
     * (class comment: THE TEMPLATE). The template is not needed afterwards
     * (the caller discards it with Record::cancel()).
     *
     * @param int      $templateId  the template (Record::secureInputs() has run)
     * @param string[] $fields      checked fields (already checked against batchFields())
     * @param array    $data        data[...] of the mask
     * @param array    $allowed     batchFields() of the area
     * @param array    $languages   multilingual fields with language selection: name =>
     *                              [language_id, ...] (checkedFields()); the other
     *                              languages remain untouched. Without entry: all
     * @return array   ['data' => [], 'prehashed' => [], 'relations' => [], 'files' => [], 'files_dir' => '']
     */
    public function editJob(int $templateId, array $fields, array $data, array $allowed, array $languages = []): array
    {
        $job = ['data' => [], 'prehashed' => [], 'relations' => [], 'files' => [], 'files_dir' => ''];
        $tmpData = $this->record->loadTmpData($templateId);
        $mediaDir = (string)$this->config->get('paths.media_dir');
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $filesDir = '';

        foreach ($fields as $name) {
            $colDef = $allowed[$name];
            $type = (string)($colDef['type'] ?? 'text');
            $displayType = (string)($colDef['display_type'] ?? 'text');
            $fieldId = (int)$colDef['field_id'];
            // languages that are written (multilingual fields)
            $languageIds = $this->cms->isMultilingualField($colDef)
                ? array_values(array_intersect($this->record->languageIdsOf($templateId), (array)($languages[$name] ?? $this->record->languageIdsOf($templateId))))
                : [];

            // lookup link: the data_rel rows of the template
            if (in_array($type, $this->cms->relationTypes, true) && $displayType === 'lookup') {
                $this->db->pushResult();
                $this->db->query(
                    "SELECT `dest_id` FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? ORDER BY `position`",
                    'ii', [$fieldId, $templateId]
                );
                $job['relations'][$name] = array_map('intval', $this->db->fetchAll());
                $this->db->popResult();
                continue;
            }

            // file: copy under var/tmp per language; without file (or removed) null
            if ($type === 'file') {
                $entries = isset($tmpData[$name]) ? $this->record->fileEntries((array)$tmpData[$name]) : [];
                if ($this->cms->isMultilingualField($colDef)) {
                    // one entry per checked language - without file null (delete)
                    $byLanguage = [];
                    foreach ($languageIds as $languageId) {
                        $byLanguage[$languageId] = (array)($entries[$languageId] ?? []);
                    }
                    $entries = $byLanguage;
                }
                else $entries = ['' => (array)($entries[''] ?? (reset($entries) ?: []))];
                $job['files'][$name] = [];
                foreach ($entries as $langKey => $fileData) {
                    $extension = (string)($fileData['extension'] ?? '');
                    if ($extension === '' || !empty($fileData['deleted']) || (int)($fileData['size'] ?? 0) < 1) {
                        $job['files'][$name][(string)$langKey] = null;
                        continue;
                    }
                    if ($filesDir === '') {
                        $filesDir = 'batch-files-' . $this->crypt->randomKey(16);
                        if (!$this->system->createDir($tmpDir, $filesDir)) {
                            throw new \RuntimeException("Error: could not create {$tmpDir}/{$filesDir}");
                        }
                        $job['files_dir'] = $tmpDir . '/' . $filesDir;
                    }
                    $languageId = ($langKey === '') ? null : (int)$langKey;
                    $source = $mediaDir . '/' . $this->cms->mediaPath($fieldId, $templateId, $languageId, $extension);
                    $target = $job['files_dir'] . '/' . $name . ($languageId === null ? '' : '.' . $languageId) . '.' . $extension;
                    if (!is_file($source) || !@copy($source, $target)) {
                        $this->system->log('file-errors', "$source > $target : copy failed.");
                        throw new \RuntimeException("Error: could not copy template file for \"{$name}\"");
                    }
                    $job['files'][$name][(string)$langKey] = [
                        'importfile' => $target,
                        'filename'   => (string)($fileData['filename'] ?? basename($target)),
                    ];
                }
                continue;
            }

            // tree fields: parent record resp. predecessor (TREE FIELDS)
            if ($name === 'parent_id') {
                $job['data']['parent_id'] = max(0, (int)($data['parent_id'] ?? 0));
                continue;
            }
            if ($name === 'position') {
                $job['data']['previous_id'] = max(0, (int)($data['previous_id'] ?? 0));
                continue;
            }

            // password: the hash from the template (securePasswords()) instead of the input
            if ($type === 'password' || $displayType === 'password') {
                $hash = (string)($tmpData[$name][1] ?? '');
                if ($hash !== '' && trim((string)($data[$name] ?? '')) !== '') {
                    $job['data'][$name] = $hash;
                    $job['prehashed'][] = $name;
                    continue;
                }
            }

            $value = $data[$name] ?? '';
            // multilingual field: only the checked languages. The mask does not send
            // empty inputs (serializeFiles()) - a checked language without value is
            // cleared; otherwise without a value per language it would remain unchanged
            if ($this->cms->isMultilingualField($colDef) && (is_array($value) || trim((string)$value) === '')) {
                $value = is_array($value) ? $value : [];
                $byLanguage = [];
                foreach ($languageIds as $languageId) {
                    $byLanguage[$languageId] = $value[$languageId] ?? '';
                }
                $value = $byLanguage;
            }
            $job['data'][$name] = $value;
        }
        return $job;
    }

    /**
     * Remove the files of an 'edit' job under var/tmp (after the run).
     */
    public function cleanupJob(array $job): void
    {
        $dir = (string)($job['files_dir'] ?? '');
        $tmpDir = realpath((string)$this->config->get('paths.tmp_dir'));
        $real = ($dir !== '') ? realpath($dir) : false;
        if ($real === false || $tmpDir === false || !str_starts_with($real, $tmpDir . DIRECTORY_SEPARATOR)) return;
        foreach (glob($real . '/*') ?: [] as $file) @unlink($file);
        @rmdir($real);
    }

    // ====================
    // Run

    /**
     * Run the job - foreground and background alike.
     *
     * @param array         $job       see class comment
     * @param callable|null $progress  fn(int $done, int $total) - progress message
     * @return array  [
     *     'total'    => int,
     *     'done'     => int,    successful (in simulation: without objection)
     *     'failed'   => int,
     *     'errors'   => [record_id => ['messages' => string[]], ...]
     * ]
     */
    public function run(array $job, ?callable $progress = null): array
    {
        // Audit log: mark the changes of this run as 'batch' - via the API
        // with its channel (llm-batch, scheduler-batch …); afterwards the
        // previous origin applies again
        $previous = $this->auditLog->context();
        $this->auditLog->setContext(in_array($previous, ['', 'batch'], true) ? 'batch' : $previous . '-batch');
        try {
            return $this->runJob($job, $progress);
        }
        finally {
            $this->auditLog->setContext($previous);
        }
    }

    /** The actual run (run()) */
    private function runJob(array $job, ?callable $progress): array
    {
        $mode = (string)($job['mode'] ?? '');
        $ids = $this->system->sanitizeIds((array)($job['ids'] ?? []), true);
        $result = ['total' => count($ids), 'done' => 0, 'failed' => 0, 'errors' => []];
        if (!in_array($mode, self::MODES, true)) {
            $result['failed'] = $result['total'];
            $result['errors'][0] = ['messages' => ["invalid mode \"{$mode}\""]];
            return $result;
        }

        // tree fields: in the current order, one after the other (TREE FIELDS)
        $this->tree = ($mode === 'edit') ? $this->_treePlan($job) : null;
        if ($this->tree !== null) {
            $ids = $this->_treeOrder($ids);
            $this->treePrevious = (int)($this->tree['previous_id'] ?? 0);
        }

        $step = 0;
        foreach ($ids as $id) {
            $this->cms->errorsReset();
            $ok = false;
            try {
                $ok = match ($mode) {
                    'edit'   => $this->_editOne($id, $job),
                    'move'   => $this->_moveOne($id, $job),
                    'delete' => $this->_deleteOne($id, $job),
                };
            } catch (\Throwable $e) {
                $this->cms->error($e->getMessage());
            }
            if ($ok) {
                $result['done']++;
            }
            else {
                $result['failed']++;
                $result['errors'][$id] = ['messages' => $this->_errorTexts()];
            }
            $step++;
            if ($progress !== null) $progress($step, $result['total']);
        }
        $this->cms->errorsReset();
        $this->tree = null;
        if ($mode === 'edit') $this->cleanupJob($job);
        return $result;
    }

    /**
     * The tree fields of an 'edit' job: target level and predecessor (TREE FIELDS).
     *
     * @return array|null  ['tree' => bool (area with tree), 'parent_id' => int,
     *                     'previous_id' => int|null (null: append at the end)];
     *                     null: no tree field checked
     */
    private function _treePlan(array $job): ?array
    {
        $fields = (array)($job['fields'] ?? []);
        $hasParent = in_array('parent_id', $fields, true);
        $hasPosition = in_array('position', $fields, true);
        if (!$hasParent && !$hasPosition) return null;
        $areaId = (int)($job['area_id'] ?? 0);
        $isTree = $this->record->isTreeArea($areaId);
        $previousId = $hasPosition ? max(0, (int)($job['data']['previous_id'] ?? 0)) : null;
        if (!$isTree) $parentId = 0;
        elseif ($hasParent) $parentId = max(0, (int)($job['data']['parent_id'] ?? 0));
        elseif ($previousId > 0) {
            // only position: the level of the predecessor
            $this->db->pushResult();
            $this->db->query("SELECT `parent_id` FROM `record` WHERE `record_id` = ? AND `area_id` = ?", 'ii', [$previousId, $areaId]);
            $parentId = (int)($this->db->fetchNum()[0] ?? 0);
            $this->db->popResult();
        }
        else $parentId = 0;
        return ['tree' => $isTree, 'parent_id' => $parentId, 'previous_id' => $previousId];
    }

    /**
     * Ids in their current order: tree order (lft), otherwise position.
     *
     * @param int[] $ids
     * @return int[]
     */
    private function _treeOrder(array $ids): array
    {
        if (count($ids) < 2) return $ids;
        $this->db->pushResult();
        $this->db->query("SELECT `record_id` FROM `record` WHERE `record_id` IN (" . implode(',', array_map('intval', $ids)) . ")"
            . " ORDER BY `lft` ASC, `position` ASC, `record_id` ASC");
        $ordered = array_map('intval', $this->db->fetchAll());
        $this->db->popResult();
        // ids no longer found (deleted in the meantime) at the end - they fail individually
        return array_values(array_unique(array_merge($ordered, $ids)));
    }

    /**
     * The messages from Cms::errors as plain text lines ("Label: text")
     * @return string[]
     */
    private function _errorTexts(): array
    {
        $texts = [];
        foreach ($this->cms->errors as $key => $error) {
            $text = $this->cms->_strip_tags((string)($error['text'] ?? ''));
            if (!is_int($key) && (string)($error['label'] ?? '') !== '') {
                $text = $this->cms->_strip_tags((string)$error['label']) . $this->cms->snippet('colon') . $text;
            }
            $texts[] = $text;
        }
        if (empty($texts)) $texts[] = $this->cms->snippet('record.error_batch_unknown');
        return array_values(array_unique($texts));
    }

    /**
     * edit: change the checked fields in ONE record - RecordService::
     * updateOne(): working copy, links of the lookup fields (replace the
     * previous ones), deletion markers and files of the template ('importfile', as with the
     * import), passwords as hash ($prehashed), then Record::importSave() with the
     * checked fields - all other values remain (Dataset::save()).
     * Simulation: stayTemporary (check, no checkIn), afterwards the
     * working copy is discarded - together with the files stored under its id.
     *
     * A record is locked if another admin is editing it or if the triggering
     * admin still has it open in a mask himself
     * (RecordService::loadForChange()).
     */
    private function _editOne(int $id, array $job): bool
    {
        $areaId = (int)($job['area_id'] ?? 0);
        $fields = array_values(array_filter((array)($job['fields'] ?? []), 'is_string'));

        // only permitted fields (the request could name others)
        $allowed = $this->batchFields($this->record->virtualSchema($areaId, false));
        $fields = array_values(array_intersect($fields, array_keys($allowed)));
        if (empty($fields)) {
            $this->cms->error($this->cms->snippet('record.error_batch_no_fields'));
            return false;
        }

        $data = [];
        $relations = [];
        $files = [];
        $moved = false;
        if ($this->tree !== null) {
            $fields = array_values(array_diff($fields, self::TREE_FIELDS));
            $previousId = $this->tree['previous_id'];
            // the chosen predecessor itself stays where it is
            if ($previousId === null || $id !== $previousId) {
                $parentId = (int)$this->tree['parent_id'];
                if ($this->tree['tree'] && $parentId > 0 && $this->record->isInSubtree($parentId, $id, $areaId)) {
                    $this->cms->error($this->cms->snippet('error_parent_in_subtree'), 'parent_id', $this->cms->textOrSnippet('#record.field_parent_id'));
                    return false;
                }
                $data['previous_id'] = ($previousId === null)
                    ? $this->record->lastChildId($parentId, $areaId, $id)
                    : $this->treePrevious;
                if ($this->tree['tree'] && isset($allowed['parent_id'])) {
                    $fields[] = 'parent_id';
                    $data['parent_id'] = $parentId;
                }
                if (isset($allowed['position'])) $fields[] = 'position';
                $moved = true;
            }
            // nothing else to change (the predecessor itself)
            if (empty($fields)) return true;
        }
        foreach ($fields as $name) {
            if (in_array($name, self::TREE_FIELDS, true)) continue;
            $colDef = $allowed[$name];
            $type = (string)($colDef['type'] ?? 'text');
            // links: lookup fields from the template (ids), via the API also
            // operations (set/add/remove/order, RecordService::applyRelations())
            if (in_array($type, $this->cms->relationTypes, true)
                && (($colDef['display_type'] ?? '') === 'lookup' || array_key_exists($name, (array)($job['relations'] ?? [])))) {
                $relations[$name] = (array)($job['relations'][$name] ?? []);
            }
            elseif ($type === 'file') {
                $files[$name] = (array)($job['files'][$name] ?? []);
            }
            else {
                $data[$name] = $job['data'][$name] ?? '';
            }
        }

        $dataFields = array_values(array_diff(array_keys($data), ['previous_id']));
        if ($moved && in_array('position', $fields, true)) $dataFields[] = 'position';
        $ok = (bool)$this->service->updateOne($id, $dataFields, $data, $relations, $files, [
            'area_id'   => $areaId,
            'simulate'  => !empty($job['simulate']),
            'prehashed' => (array)($job['prehashed'] ?? []),
            'stored'    => array_values(array_intersect((array)($job['stored'] ?? []), array_keys($data))),
            'history'   => false,
        ]);
        // the next record goes behind this one
        if ($ok && $moved) $this->treePrevious = $id;
        return $ok;
    }

    /**
     * move: move ONE record to the target area (MoveTrait::moveToArea()).
     */
    private function _moveOne(int $id, array $job): bool
    {
        $areaId = (int)($job['area_id'] ?? 0);
        $targetAreaId = (int)($job['target_area_id'] ?? 0);
        $mapping = (array)($job['mapping'] ?? []);
        $simulate = !empty($job['simulate']);
        if (!$this->service->loadForChange($id, 'move', $areaId)) return false;
        return $this->record->moveToArea($id, $targetAreaId, $mapping, $simulate);
    }

    /**
     * delete: delete ONE record - according to the deletion policy of its area
     * (RecordService::delete(), module Privacy). Simulation: only the checks.
     */
    private function _deleteOne(int $id, array $job): bool
    {
        $areaId = (int)($job['area_id'] ?? 0);
        if (!$this->service->loadForChange($id, 'delete', $areaId)) return false;
        if (!empty($job['simulate'])) return true;
        return $this->service->delete($id) !== null;
    }

    // ====================
    // Background

    /**
     * Start the job as a background process: JSON file to var/tmp, then
     * Core/cron/record_batch.php (see MassDeletion::start()).
     *
     * @param array       $job    see class comment
     * @param string|null $error  reason for a failure (by reference)
     * @return array|null         ['pid' => int, 'token' => string, 'id' => int] or null
     */
    public function start(array $job, ?string &$error = null): ?array
    {
        $error = '';
        $phpExec = (string)$this->config->get('paths.php');
        if ($phpExec === '' || !is_executable($phpExec)) {
            $error = "Error: 'php' is not executable (paths.php)";
            $this->system->log('batch-errors', $error);
            return null;
        }

        $token = $this->crypt->randomKey(16);
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $jobFile = $tmpDir . '/batch-' . $token . '.json';
        $job['token'] = $token;
        $job['admin_id'] = (int)$this->session->get('admin_id');
        if (file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            $error = "Error: could not write job file {$jobFile}";
            $this->system->log('batch-errors', $error);
            return null;
        }

        $command = [
            $phpExec,
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'record_batch.php',
            '--job=' . $jobFile,
            '--token=' . $token,
        ];
        $result = $this->bgproc->start($command, self::TYPE, $token, null, null, $error);
        if ($result === null) {
            @unlink($jobFile);
            $this->system->log('batch-errors', "start failed: {$error}");
        }
        return $result;
    }

    /**
     * Read (and remove) the job file of the background process.
     */
    public function loadJob(string $jobFile): ?array
    {
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $real = realpath($jobFile);
        if ($real === false || !str_starts_with($real, realpath($tmpDir) . DIRECTORY_SEPARATOR)) return null;
        $job = json_decode((string)file_get_contents($real), true);
        @unlink($real);
        return is_array($job) ? $job : null;
    }

    // ====================
    // Report

    /**
     * Summary as plain text lines: header, then one line per failed record with
     * its identifier and messages.
     *
     * @return string[]
     */
    public function resultLines(array $job, array $result): array
    {
        $lines = [$this->summaryText($job, $result)];
        foreach ($result['errors'] as $id => $entry) {
            $label = $id > 0
                ? $this->cms->_strip_tags($this->backend->teaser('record', (int)$id, linked: false, icon: false)) . " [{$id}]"
                : '-';
            $lines[] = $label . $this->cms->snippet('colon') . implode(' | ', $entry['messages']);
        }
        return $lines;
    }

    /**
     * The header line: "{function} – {area}: n of m ..." (simulation marked)
     */
    public function summaryText(array $job, array $result): string
    {
        $areaData = $this->backend->areaData((int)($job['area_id'] ?? 0)) ?? [];
        $areaLabel = $this->cms->_strip_tags($this->cms->textOrSnippet((string)($areaData['label'] ?? '')));
        $text = $this->cms->snippet('record.batch_summary', [
            'mode'   => (string)($job['mode'] ?? ''),
            'total'  => (int)$result['total'],
            'done'   => (int)$result['done'],
            'failed' => (int)$result['failed'],
        ]);
        if (!empty($job['simulate'])) $text = $this->cms->snippet('record.label_batch_simulation') . ' - ' . $text;
        return $areaLabel . $this->cms->snippet('colon') . $text;
    }

    /**
     * Record the result of the background process: file in var/log and internal
     * message to the triggering admin (sender is the admin himself - the process runs
     * under his identity, see Session::setCliAdmin()).
     *
     * @return string  path of the log file
     */
    public function report(array $job, array $result): string
    {
        $adminId = (int)($job['admin_id'] ?? 0);
        $token = preg_replace('/[^a-z0-9_-]/i', '', (string)($job['token'] ?? 'x'));
        $lines = $this->resultLines($job, $result);

        // Datei
        $relativePath = 'log/' . date('Y-m-d') . '_batch-' . $token . '.log';
        $varDir = (string)$this->config->get('paths.var_dir');
        $this->system->filePutContents($varDir, $relativePath, implode(PHP_EOL, $lines) . PHP_EOL);
        $logFile = $varDir . '/' . $relativePath;

        // message: plain text = file content, HTML part = linked records
        if ($adminId > 0) {
            $items = [];
            foreach ($result['errors'] as $id => $entry) {
                if ($id < 1) continue;
                $teaser = $this->backend->teaser('record', (int)$id, linked: true, icon: true);
                if ($teaser === '') continue;
                $items[] = '<li>' . $teaser . $this->cms->snippet('colon')
                    . htmlspecialchars(implode(' | ', $entry['messages'])) . '</li>';
            }
            $html = empty($items) ? '' : '<ul>' . implode("\n", $items) . '</ul>';
            $body = implode("\n", $lines) . "\n\n" . $this->cms->snippet('record.batch_report_file') . ' ' . $relativePath;
            $this->message->sendSystem(
                $adminId,
                $this->cms->snippet('record.batch_report_subject', ['mode' => (string)($job['mode'] ?? '')]),
                $body,
                $html
            );
        }
        return $logFile;
    }
}
