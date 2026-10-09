<?php
/**
 * Trait for the table `record`: move a record to another content area.
 *
 * A record consists of the `record` row (meta fields) and the contents of its
 * user-defined fields in the data_* tables - and the fields belong to the
 * area. An area change is therefore not an UPDATE of area_id, but a
 * transfer: every field of the source area is mapped to a field of the target area
 * or is dropped.
 *
 * THE MAPPING
 * --------------------
 *     [source field name => target field name | '']       '' = discard content
 *
 * Only target fields that correspond to the source field are allowed (moveCompatible()):
 * same type, same multilingualism, for file fields both upload fields,
 * for links the same target area (relation) - for references (pointer) it is
 * checked during the move whether the referenced records lie in the target areas
 * of the target field. Without a mapping, meta fields (they are in `record` itself
 * and stay there), full-text fields (they are generated) and thumbnails (they are
 * recalculated from the upload field of the target area) are not carried over.
 *
 * THE WAY VIA THE WORKING COPY
 * --------------------
 * The move does not use direct UPDATEs, but Dataset::save(): thus the field definitions
 * of the target area apply (required fields, uniqueness, limits, target areas of
 * links, required uploads), and all consequences of a save run by themselves -
 * data_* rows per language, `data_dft`, `record_cache`, thumbnails, full text, tree
 * maintenance in both areas. The flow:
 *
 *   movePrepare() - one transaction:
 *   1. checkOut(): working copy with the live state (working state in
 *      `record_cache`, links)
 *   2. the working copy is rebuilt for the target area: area_id, the fields of the
 *      working state renamed according to the mapping, files of the upload fields copied into the directories
 *      of the target fields (marked as 'temporary' + 'thumbnails_pending'),
 *      data_rel rows re-attached to the target fields, password hashes in the working state
 *   moveSave():
 *   3. save() with area_id = target area and the mapped values from the
 *      working state. The values are in database format - $storedFormatFields
 *      makes Dataset skip the input conversion, but it checks all rules of the
 *      target area
 *   4. Record::hookOnSave() removes the data_* rows of the fields of the source area
 *      (removeAreaData()) before the new ones are written, hookOnSaved()
 *      then the media files (removeAreaFiles()) - both within the transaction
 *      of save(), and only on the final save (not with stayTemporary)
 *
 * After movePrepare() the copy is an ordinary working copy of the target area
 * with the original in the source area as edit lock: the input mask of the
 * target area can display it, tab switches cache it (stayTemporary),
 * cancel() discards it along with the copied files - the original stays unchanged until the
 * check-in. If the check in moveSave() fails, RecordBackendMove therefore
 * shows the input mask of the target area with the errors; saving it is the
 * check-in (Record::save() with area_id = target area), cancelling it leaves the
 * record in its previous area. moveToArea() (batch processing) discards the
 * copy itself on an error. File operations are not transactional: the
 * copied files are removed by cancel() via the 'temporary' entries.
 *
 * NOT MOVED
 * --------------------
 *   - records with children in the tree of the source area (the children would lose
 *     their parent)
 *   - records on which someone else is currently working (edit lock)
 *   - languages that the instance of the target area does not run: if the target area
 *     lies in another instance, its contents are lost (note in the mask)
 *
 * @see Camindo\Core\Modules\Record\RecordBackendMove  the mask for it
 * @see Camindo\Core\Dataset::$storedFormatFields
 * @see Camindo\Core\TemporaryTrait                     checkOut / checkIn
 */
namespace Camindo\Core;

use Camindo\Core\Modules\Area\Area;

trait MoveTrait
{
    /**
     * Fields of an area that can be mapped when moving.
     *
     * Without meta fields (columns of `record`), full-text fields and thumbnails - see
     * class comment. Applies to the source AND the target area.
     *
     * @param array $schemaVirtual  virtualSchema($areaId, false)
     * @return array  name => field definition, in field order
     */
    public function moveFields(array $schemaVirtual): array
    {
        $fields = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if (isset($this->cms->schema[$name])) continue;
            $type = (string)($colDef['type'] ?? '');
            if ($type === 'fulltext') continue;
            if ($type === 'file' && ($colDef['display_type'] ?? '') === 'thumbnail') continue;
            $fields[$name] = $colDef;
        }
        return $fields;
    }

    /**
     * Does a target field match a source field?
     *
     *     type                same (relation and relation1 are different types)
     *     multilingual        same (Cms::isMultilingualField())
     *     file                upload fields only
     *     relation/relation1  same target area - the counter field is there
     *     pointer/pointer1    no pre-check; the referenced records are
     *                         checked against the target areas during the move
     *     link                the target field allows all target areas of the source field.
     *                         Without a mapping the firmly linked contents are deleted
     *                         at check-in (LinkTrait::checkInDependents())
     *
     * @param array $source  field definition in the source area
     * @param array $target  field definition in the target area
     */
    public function moveCompatible(array $source, array $target): bool
    {
        $type = (string)($source['type'] ?? '');
        if ($type === '' || $type !== (string)($target['type'] ?? '')) return false;
        if ($this->cms->isMultilingualField($source) !== $this->cms->isMultilingualField($target)) return false;
        if ($type === 'file') {
            return ($source['display_type'] ?? '') === 'upload' && ($target['display_type'] ?? '') === 'upload';
        }
        if (str_starts_with($type, 'relation')) {
            return (int)($source['rel_area_id'] ?? 0) === (int)($target['rel_area_id'] ?? 0);
        }
        if (in_array($type, $this->cms->dependentTypes, true)) {
            // link: the target field must allow all target areas of the source field -
            // otherwise the firmly linked contents would lose their source record
            $sourceAreas = array_map('intval', $this->getRelAreaIds((int)($source['field_id'] ?? 0)));
            $targetAreas = array_map('intval', $this->getRelAreaIds((int)($target['field_id'] ?? 0)));
            return empty(array_diff($sourceAreas, $targetAreas));
        }
        return true;
    }

    /**
     * Target fields that come into question for a source field.
     *
     * @param array $source        field definition in the source area
     * @param array $targetFields  moveFields() of the target area
     * @return array  name => field definition
     */
    public function moveTargets(array $source, array $targetFields): array
    {
        return array_filter($targetFields, fn(array $target) => $this->moveCompatible($source, $target));
    }

    /**
     * Default of the mapping: each source field to the target field of the same name, if it
     * fits (moveCompatible()), otherwise '' (discard) - the preselection of the mask
     * (RecordBackendMove) and of the API (record.move with auto_map).
     *
     * @param array $sourceFields  moveFields() of the source area
     * @param array $targetFields  moveFields() of the target area
     * @return array               source field => target field | ''
     */
    public function moveDefaultMapping(array $sourceFields, array $targetFields): array
    {
        $mapping = [];
        foreach ($sourceFields as $name => $colDef) {
            $mapping[$name] = isset($targetFields[$name]) && $this->moveCompatible($colDef, $targetFields[$name]) ? (string)$name : '';
        }
        return $mapping;
    }

    /**
     * Check and complete the mapping.
     *
     * Every source field gets an entry ('' = discard), unknown source fields
     * are dropped, non-matching target fields are an error, and no target field may
     * be assigned twice. Errors end up in Cms::errors (key = source field).
     *
     * @param array $sourceFields  moveFields() of the source area
     * @param array $targetFields  moveFields() of the target area
     * @param array $mapping       source field => target field | ''
     * @return array|null          cleaned mapping, null on errors
     */
    public function moveMapping(array $sourceFields, array $targetFields, array $mapping): ?array
    {
        $result = [];
        $used = [];
        $ok = true;
        foreach ($sourceFields as $name => $colDef) {
            $target = preg_replace('/[^a-z0-9_\-]/', '', (string)($mapping[$name] ?? ''));
            $result[$name] = '';
            if ($target === '') continue;
            $label = (string)($colDef['label'] ?? $name);
            if (!isset($targetFields[$target]) || !$this->moveCompatible($colDef, $targetFields[$target])) {
                $this->createFieldError('record.error_move_target_field', $name, $label, $target);
                $ok = false;
                continue;
            }
            if (isset($used[$target])) {
                $this->createFieldError('record.error_move_target_twice', $name, $label, $target);
                $ok = false;
                continue;
            }
            $used[$target] = true;
            $result[$name] = $target;
        }
        return $ok ? $result : null;
    }

    /**
     * Move a record to another content area - see class comment.
     *
     * movePrepare() + moveSave() in one go: if the check in the target area fails,
     * the working copy is discarded (cancel()) and the record stays unchanged
     * in the source area; the error messages are in Cms::errors (field errors
     * under the name of the TARGET FIELD, mapping errors under the source field).
     * Whoever wants to keep the working copy in case of error (input mask of the target area
     * with the errors, RecordBackendMove) calls the two steps itself.
     *
     * @param int   $recordId      live record
     * @param int   $targetAreaId  target area
     * @param array $mapping       source field => target field | '' (see moveMapping())
     * @param bool  $simulate      true: check only (batch processing), see moveSave()
     * @return bool                true if moved (simulation: without objection)
     */
    public function moveToArea(int $recordId, int $targetAreaId, array $mapping, bool $simulate = false): bool
    {
        if (!$tmpId = $this->movePrepare($recordId, $targetAreaId, $mapping)) return false;
        $ok = $this->moveSave($tmpId, $simulate);
        if ($simulate || !$ok) {
            // Keep the error messages across cancel()
            $errors = $this->cms->errors;
            $this->cancel($tmpId);
            $this->cms->errorsReset();
            foreach ($errors as $key => $error) {
                $this->cms->error((string)$error['text'], is_int($key) ? '' : (string)$key, (string)($error['label'] ?? ''));
            }
        }
        return $ok;
    }

    /**
     * Step 1: create the working copy for the target area (class comment,
     * steps 1 and 2).
     *
     * Checks record, target area, edit lock, children and mapping, checks out
     * the record and rebuilds the working copy for the target area: area_id, the
     * fields of the working state renamed according to the mapping, files of the upload fields
     * copied into the directories of the target fields, links re-attached,
     * password hashes in the working state. Everything in one transaction - afterwards the
     * copy is like an ordinary working copy in the target area: Cms::fetch() reads it,
     * the input mask of the target area can display it, cancel() discards it
     * (along with the copied files), and Record::save() with area_id = target area checks
     * it in - exactly what moveSave() does.
     *
     * @param int   $recordId      live record
     * @param int   $targetAreaId  target area
     * @param array $mapping       source field => target field | '' (see moveMapping())
     * @return int|null            id of the working copy, null on errors (Cms::errors)
     */
    public function movePrepare(int $recordId, int $targetAreaId, array $mapping): ?int
    {
        // Record and areas
        // --------------------
        if (!$data = $this->load($recordId)) return null;
        if (!empty($data['tmp']) || !empty($data['deleted'])) {
            $this->cms->error($this->cms->snippet('record.error_move_not_productive'));
            return null;
        }
        $sourceAreaId = (int)$data['area_id'];
        if ($targetAreaId < 1 || $targetAreaId === $sourceAreaId) {
            $this->cms->error($this->cms->snippet('record.error_move_same_area'));
            return null;
        }
        // Rights of the user group: source write + delete, target create
        $ownerId = isset($data['owner_id']) ? (int)$data['owner_id'] : null;
        if (!$this->accessCheck('write', $sourceAreaId, $ownerId)
            || !$this->accessCheck('delete', $sourceAreaId, $ownerId)
            || !$this->accessCheck('create', $targetAreaId)
        ) {
            return null;
        }
        // Type and instance from the cache; whether the area is marked as deleted
        // is known only to the table
        $targetArea = $this->areaInfo($targetAreaId);
        if ($targetArea !== null) {
            $this->db->pushResult();
            $this->db->query("SELECT 1 FROM `area` WHERE `area_id` = ? AND `deleted` = 0", 'i', [$targetAreaId]);
            if ($this->db->num_rows < 1) $targetArea = null;
            $this->db->popResult();
        }
        if ($targetArea === null || !in_array((string)$targetArea['type'], Area::CONTENT_TYPES, true)) {
            $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            return null;
        }
        $targetInstanceId = (int)$targetArea['instance_id'];

        // Edit lock
        if ($lock = $this->getLock($data)) {
            $this->cms->error($this->cms->snippet('record.error_move_locked'));
            return null;
        }

        // Children in the tree of the source area
        if ($this->isTreeArea($sourceAreaId)) {
            $this->db->pushResult();
            $this->db->query(
                "SELECT COUNT(*) FROM `record` WHERE `parent_id` = ? AND `tmp` = 0",
                'i', [$recordId]
            );
            $children = (int)($this->db->fetchNum()[0] ?? 0);
            $this->db->popResult();
            if ($children > 0) {
                $this->cms->error($this->cms->snippet('record.error_move_has_children'));
                return null;
            }
        }

        // Mapping
        // --------------------
        $schemaSource = $this->virtualSchema($sourceAreaId, false);
        $schemaTarget = $this->virtualSchema($targetAreaId, false);
        $sourceFields = $this->moveFields($schemaSource);
        $targetFields = $this->moveFields($schemaTarget);
        if (($mapping = $this->moveMapping($sourceFields, $targetFields, $mapping)) === null) {
            return null;
        }

        $mediaDir = $this->config->get('paths.media_dir');
        $sourceLanguageIds = array_map('intval', (array)($this->languageContext($sourceAreaId)['language_ids'] ?? []));
        $langCtx = $this->languageContext($targetAreaId);
        $targetLanguageIds = array_map('intval', (array)($langCtx['language_ids'] ?? []));
        $targetPrimaryId = (int)($langCtx['primary_id'] ?? ($targetLanguageIds[0] ?? 0));

        // Passwords: the hashes cannot be passed as input (they would be hashed
        // again). They move as a hash into the working state of the copy - like
        // a password entered in the input mask (securePasswords()); Dataset::
        // save() takes the hash from there if the field stays empty ($prehashedFields).
        // A password field is single-language: the hash of the main language counts
        $passwords = [];
        foreach ($mapping as $srcName => $dstName) {
            if ($dstName === '' || ($sourceFields[$srcName]['type'] ?? '') !== 'password') continue;
            $this->db->pushResult();
            $this->db->query(
                "SELECT `language_id`, `data` FROM `data_pwd` WHERE `record_id` = ? AND `field_id` = ?",
                'ii', [$recordId, (int)$sourceFields[$srcName]['field_id']]
            );
            $hashes = array_map('strval', $this->db->fetchPairs());
            $this->db->popResult();
            $hash = (string)($hashes[$targetPrimaryId] ?? (reset($hashes) ?: ''));
            if ($hash !== '') $passwords[$dstName] = $hash;
        }

        $copied = [];   // copied files (to clean up on error)
        $tmpId = 0;
        $this->db->beginTransaction();
        try {
            // 1. Working copy
            // --------------------
            if (!$tmpId = $this->checkOut($data)) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            // Read the working state of the copy WHILE it still belongs to the source area
            // (_loadTmpData() uses its schema - multilingual fields)
            $tmpData = $this->_loadTmpData($tmpId);
            // From now on the working copy belongs to the target area: Dataset::save(),
            // getProdData() and _saveTmpData() read the area from it
            $this->db->query(
                "UPDATE `record` SET `area_id` = ?, `instance_id` = ? WHERE `record_id` = ?",
                'iii', [$targetAreaId, $targetInstanceId, $tmpId]
            );

            // 2. Rebuild the working state and files according to the mapping
            // --------------------
            $newTmpData = [];
            foreach ($mapping as $srcName => $dstName) {
                if ($dstName === '') continue;
                $srcDef = $sourceFields[$srcName];
                $dstDef = $targetFields[$dstName];
                $type = (string)$srcDef['type'];
                $srcFieldId = (int)$srcDef['field_id'];
                $dstFieldId = (int)$dstDef['field_id'];

                if (in_array($type, $this->cms->relationTypes, true)) {
                    continue;   // data_rel, see below
                }
                if ($type === 'password') {
                    if (isset($passwords[$dstName])) $newTmpData[$dstName] = ['password', $passwords[$dstName]];
                    continue;
                }
                if ($type === 'file') {
                    // Copy live files into the directory of the target field,
                    // under the id of the working copy; promoteTmpFiles() moves them to the
                    // record at check-in and writes `data_fil`,
                    // writePendingThumbnails() recalculates the thumbnails of the target field
                    // (source and target field are both single- or both multilingual:
                    //  single-language one entry without language, multilingual one entry per language
                    //  with its own file - see _tmpDataFromRecordCache())
                    $entry = $tmpData[$srcName] ?? null;
                    if ($entry === null) continue;
                    foreach ($this->fileEntries($entry) as $langKey => $fileData) {
                        $languageId = ($langKey === '') ? null : (int)$langKey;
                        if ($languageId !== null && !in_array($languageId, $targetLanguageIds, true)) continue;
                        $extension = (string)($fileData['extension'] ?? '');
                        unset($fileData['deleted'], $fileData['temporary'], $fileData['thumbnails_pending']);
                        $fileData['field_id'] = $dstFieldId;
                        $fileData['file_language_id'] = $languageId;
                        if ($extension !== '') {
                            $srcPath = $this->cms->mediaPath($srcFieldId, $recordId, $languageId, $extension);
                            $dstPath = $this->cms->mediaPath($dstFieldId, $tmpId, $languageId, $extension, $targetInstanceId);
                            if (!is_file("$mediaDir/$srcPath")) {
                                throw new \Exception("Error: movePrepare(): file \"$srcPath\" not found.");
                            }
                            if (!$this->system->createDir($mediaDir, dirname($dstPath))
                                || !@copy("$mediaDir/$srcPath", "$mediaDir/$dstPath")
                            ) {
                                $this->system->log('file-errors', "$srcPath > $dstPath : copy failed.");
                                throw new \Exception("Error: movePrepare(): could not copy \"$srcPath\".");
                            }
                            $copied[] = "$mediaDir/$dstPath";
                            $fileData['temporary'] = true;
                            $fileData['thumbnails_pending'] = true;
                        }
                        $this->setFileEntry($newTmpData, $dstName, $languageId, $fileData);
                    }
                    continue;
                }
                if ($type === 'encrypted') {
                    // Encrypted contents: read from `data_enc` of the original; in the
                    // working state they are decrypted (_saveTmpData() encrypts
                    // them again), save() encrypts them for data_enc at check-in
                    $this->db->pushResult();
                    $this->db->query(
                        "SELECT `language_id`, `data`, `empty` FROM `data_enc` WHERE `record_id` = ? AND `field_id` = ?",
                        'ii', [$recordId, $srcFieldId]
                    );
                    $rows = $this->db->fetchAll();
                    $this->db->popResult();
                    $texts = [];
                    foreach ($rows as $row) {
                        $texts[(int)$row['language_id']] = [
                            $this->crypt->decrypt((string)$row['data'], '', true),
                            !empty($row['empty'])
                        ];
                    }
                    if ($this->cms->isMultilingualField($dstDef)) {
                        $value = [];
                        foreach ($targetLanguageIds as $lang) {
                            [$text, $isFallback] = $texts[$lang] ?? ['', true];
                            $value[$lang] = ($isFallback && $lang !== $targetPrimaryId) ? '' : $text;
                        }
                        if ($value[$targetPrimaryId] === '') {
                            $value[$targetPrimaryId] = (string)($texts[$targetPrimaryId][0] ?? (reset($texts)[0] ?? ''));
                        }
                    }
                    else {
                        $value = (string)($texts[$targetPrimaryId][0] ?? (reset($texts)[0] ?? ''));
                    }
                    $newTmpData[$dstName] = [$type, $value];
                    continue;
                }
                // All other types: the entry of the working copy, only renamed.
                // Multilingual: only the languages of the target area
                $entry = $tmpData[$srcName] ?? null;
                if ($entry === null) continue;
                $value = $entry[1] ?? '';
                if ($this->cms->isMultilingualField($dstDef) && is_array($value)
                    && ($type !== 'json' || $this->_isLanguageKeyedArray($value, $sourceLanguageIds))
                ) {
                    $value = array_intersect_key($value, array_flip($targetLanguageIds));
                }
                $newTmpData[$dstName] = [$type, $value];
            }
            $this->_saveTmpData($tmpId, $newTmpData);

            // 3. Re-attach links to the target fields
            // --------------------
            $this->_moveRelations($tmpId, $sourceFields, $targetFields, $mapping, $targetAreaId);

            $this->db->commit();
            return $tmpId;

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            // Remove the copied files of the working copy, then roll back (the copy
            // thereby disappears along with its working state and links)
            foreach ($copied as $path) @unlink($path);
            $this->db->rollback();
            return null;
        }
    }

    /**
     * Step 2: save the prepared working copy in the target area
     * (class comment, steps 3 and 4).
     *
     * The mapped values come from the working state of the copy and are passed to
     * Dataset::save() explicitly, so that they are checked against the field definitions
     * of the target area (staged fields are not checked again by save());
     * they are already in database format ($storedFormatFields). Unmapped
     * target fields get their defaults. parent_id / previous_id: in the target area at
     * the start of the root level - a parent record from the source area would be
     * invalid there.
     *
     * If the check fails, the working copy stays (errors in Cms::errors
     * under the names of the target fields, multilingual "name.{language_id}"); the
     * caller shows the input mask of the target area for it or discards the
     * copy with cancel() - the record is unchanged until then.
     *
     * @param int  $tmpId     working copy from movePrepare()
     * @param bool $simulate  true: check only (batch processing): Record::save() with
     *                        stayTemporary - the checked values only end up in the
     *                        working state of the copy, the record stays as it is.
     *                        The copy also stays here (cancel() by the caller)
     * @return bool           true if checked in (simulation: without objection)
     */
    public function moveSave(int $tmpId, bool $simulate = false): bool
    {
        if (!$prodData = $this->getProdData($tmpId)) {
            $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
            return false;
        }
        [$recordId, $targetAreaId] = $prodData;
        $schemaTarget = $this->virtualSchema($targetAreaId, false);

        $fields = ['area_id'];
        $saveData = [
            'record_id'   => $tmpId,
            'area_id'     => $targetAreaId,
            'parent_id'   => 0,
            'previous_id' => 0,
        ];
        $stored = [];
        foreach ($this->_loadTmpData($tmpId) as $name => $entry) {
            $type = (string)($entry[0] ?? '');
            if (!isset($schemaTarget[$name])) continue;
            // Files (working state + promoteTmpFiles), links (data_rel of the
            // copy) and passwords (hash in the working state) are taken over by save() itself
            if ($type === 'file' || $type === 'password' || in_array($type, $this->cms->relationTypes, true)) continue;
            $fields[] = $name;
            $stored[] = $name;
            $saveData[$name] = $entry[1] ?? '';
        }

        if ($simulate) {
            // A single step only checks the submitted fields - the link fields of the
            // target area (not_empty, min/max count the data_rel rows of the
            // copy) are therefore added with their current state; at check-in
            // save() checks all fields anyway
            foreach ($schemaTarget as $name => $colDef) {
                if (!in_array((string)($colDef['type'] ?? ''), $this->cms->relationTypes, true)) continue;
                $this->db->pushResult();
                $this->db->query(
                    "SELECT `dest_id` FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? ORDER BY `position`",
                    'ii', [(int)$colDef['field_id'], $tmpId]
                );
                $saveData[$name] = array_map('intval', $this->db->fetchAll());
                $this->db->popResult();
                $fields[] = $name;
            }
        }

        $this->storedFormatFields = $stored;
        try {
            if ($simulate) {
                return (bool)$this->save($fields, $saveData, stayTemporary: true);
            }
            // Check-in: firmly linked contents (link) first - the contents of unmapped
            // link fields are deleted in the process (LinkTrait)
            $this->db->beginTransaction();
            try {
                if (!$this->checkInDependents($tmpId, $recordId)) {
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                if (!$this->save($fields, $saveData)) {
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                $this->db->commit();
                return true;
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
                $this->db->rollback();
                return false;
            }
        } finally {
            $this->storedFormatFields = [];
        }
    }

    /**
     * Re-attach the data_rel rows of the working copy according to the mapping.
     *
     * Links are stored in both directions in `data_rel` (VirtualFieldsTrait::
     * createRelations()): for a relation field F the row (F, src, dest) and for
     * its counter field F' the row (F', dest, src). Both move along - to the
     * target field G and its counter field G' (moveCompatible() requires the same
     * target area, so G' lies in the same area as F'). References (pointer) have
     * only the one row; their targets must lie in the target areas of G.
     *
     * Unmapped fields lose their links. Incoming references from other records
     * to this one remain only if their pointer field allows the
     * target area; incoming links (relation) to the source area are invalid after the change.
     *
     * Runs BEFORE save(): validateValue() counts the rows per target field (min/max),
     * checkIn() copies them back to the live record.
     *
     * @param int   $tmpId         working copy
     * @param array $sourceFields  moveFields() of the source area
     * @param array $targetFields  moveFields() of the target area
     * @param array $mapping       cleaned mapping (moveMapping())
     * @param int   $targetAreaId  target area
     */
    private function _moveRelations(int $tmpId, array $sourceFields, array $targetFields, array $mapping, int $targetAreaId): void
    {
        foreach ($sourceFields as $srcName => $srcDef) {
            $type = (string)($srcDef['type'] ?? '');
            if (!in_array($type, $this->cms->relationTypes, true)) continue;
            $srcFieldId = (int)$srcDef['field_id'];
            $dstName = $mapping[$srcName] ?? '';

            if ($dstName === '') {
                // discard - both directions
                $this->db->query("DELETE FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ?", 'ii', [$srcFieldId, $tmpId]);
                if (str_starts_with($type, 'relation')) {
                    $this->db->query(
                        "DELETE FROM `data_rel` WHERE `field_id` = ? AND `dest_id` = ?",
                        'ii', [(int)$srcDef['rel_field_id'], $tmpId]
                    );
                }
                continue;
            }

            $dstDef = $targetFields[$dstName];
            $dstFieldId = (int)$dstDef['field_id'];
            $dstFieldnameId = (int)($this->cms->resolveVirtualField($dstName)['fieldname_id'] ?? 0);
            if ($dstFieldnameId < 1) {
                throw new \Exception("Error: _moveRelations(): fieldname of \"$dstName\" not found.");
            }

            if (in_array($type, $this->cms->multiAreaTypes, true)) {
                // pointer, link: referenced records must lie in the target areas
                // of the target field
                $allowed = array_map('intval', $this->getRelAreaIds($dstFieldId));
                $this->db->pushResult();
                $this->db->query(
                    "SELECT DISTINCT `r`.`area_id` FROM `data_rel` `d`"
                    . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`dest_id`"
                    . " WHERE `d`.`field_id` = ? AND `d`.`src_id` = ?",
                    'ii', [$srcFieldId, $tmpId]
                );
                $destAreaIds = array_map('intval', $this->db->fetchAll());
                $this->db->popResult();
                if (!empty(array_diff($destAreaIds, $allowed))) {
                    $this->createFieldError('record.error_move_pointer_area', $dstName, (string)($dstDef['label'] ?? $dstName));
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }

            // own rows: (F, tmp, dest) -> (G, tmp, dest)
            $this->db->query(
                "UPDATE `data_rel` SET `field_id` = ?, `fieldname_id` = ? WHERE `field_id` = ? AND `src_id` = ?",
                'iiii', [$dstFieldId, $dstFieldnameId, $srcFieldId, $tmpId]
            );

            if (str_starts_with($type, 'relation')) {
                // rows of the counter field: (F', dest, tmp) -> (G', dest, tmp)
                $srcRelFieldId = (int)$srcDef['rel_field_id'];
                $dstRelFieldId = (int)$dstDef['rel_field_id'];
                $this->db->pushResult();
                $this->db->query("SELECT `name` FROM `field` WHERE `field_id` = ?", 'i', [$dstRelFieldId]);
                $dstRelName = (string)($this->db->fetchNum()[0] ?? '');
                $this->db->popResult();
                $dstRelFieldnameId = (int)($this->cms->resolveVirtualField($dstRelName)['fieldname_id'] ?? 0);
                if ($dstRelFieldnameId < 1) {
                    $this->createFieldError('record.error_dest_relationfield', $dstName, (string)($dstDef['label'] ?? $dstName));
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                $this->db->query(
                    "UPDATE `data_rel` SET `field_id` = ?, `fieldname_id` = ? WHERE `field_id` = ? AND `dest_id` = ?",
                    'iiii', [$dstRelFieldId, $dstRelFieldnameId, $srcRelFieldId, $tmpId]
                );
            }
        }

        // Incoming references of other records: only if the pointer field allows
        // the target area
        $this->db->query(
            "DELETE `d` FROM `data_rel` `d`"
            . " INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id`"
            . " WHERE `d`.`dest_id` = ? AND (`f`.`type` LIKE 'pointer%' OR `f`.`type` = 'link')"
            . " AND NOT EXISTS (SELECT 1 FROM `rel_field_area` `a` WHERE `a`.`field_id` = `f`.`field_id` AND `a`.`area_id` = ?)",
            'ii', [$tmpId, $targetAreaId]
        );
        // Incoming links whose field does not point to the target area
        // (safety net - the counter fields in the source area were handled above)
        $this->db->query(
            "DELETE `d` FROM `data_rel` `d`"
            . " INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id`"
            . " WHERE `d`.`dest_id` = ? AND `f`.`type` LIKE 'relation%' AND `f`.`rel_area_id` != ?",
            'ii', [$tmpId, $targetAreaId]
        );
    }

    // Media files of the fields of the vacated area (paths from paths.media_dir),
    // collected by removeAreaData() in hookOnSave(), deleted by
    // removeAreaFiles() in hookOnSaved() - only once the save is complete
    protected array $areaMoveFiles = [];

    /**
     * Remove the data_* rows of a record in the fields of an area it no longer
     * belongs to.
     *
     * Called from Record::hookOnSave() when the area_id changes - BEFORE writing
     * the new fields: the primary key of the data_* tables is
     * (language_id, record_id, fieldname_id), and a same-named field in the
     * target area carries the same fieldname_id as the old one. The rows of the old
     * field would stand in the way of the INSERT of saveVirtualFields(). Runs in the
     * transaction of save() - if the check in the target area fails, everything
     * comes back.
     *
     * The media files of the old upload and thumbnail fields are only collected
     * here ($areaMoveFiles) and deleted in removeAreaFiles(): files
     * cannot be rolled back.
     *
     * `data_dft` and `record_cache` are rewritten by saveVirtualFields() anyway,
     * links (data_rel) are replaced by checkIn() - here it concerns the rows with
     * field_id in the old area, which Cms::select() would otherwise still find
     * via their fieldname_id.
     *
     * @param int $recordId
     * @param int $areaId    the previous area
     */
    public function removeAreaData(int $recordId, int $areaId): void
    {
        $this->areaMoveFiles = [];
        if ($recordId < 1 || $areaId < 1) return;
        $this->db->pushResult();

        // Note the files of the upload and thumbnail fields of the old area
        $this->db->query(
            "SELECT DISTINCT `d`.`field_id`, `d`.`extension`, `d`.`file_language_id` FROM `data_fil` `d`"
            . " INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id`"
            . " WHERE `d`.`record_id` = ? AND `f`.`area_id` = ?"
            . " AND (`d`.`file_language_id` IS NULL OR `d`.`file_language_id` = `d`.`language_id`)",
            'ii', [$recordId, $areaId]
        );
        foreach ($this->db->fetchAll() as $row) {
            if ((string)$row['extension'] === '') continue;
            $this->areaMoveFiles[] = $this->cms->mediaPath(
                (int)$row['field_id'], $recordId, $this->cms->fileLanguageId($row), (string)$row['extension']
            );
        }

        // data_* rows of the old fields
        $tables = [];
        foreach ($this->cms->dataSuffix as $type => $suffix) {
            if (in_array($type, $this->cms->noColumnTypes, true) || $suffix === 'ids') continue;
            $tables['data_' . $suffix] = true;
        }
        foreach (array_keys($tables) as $table) {
            $column = ($table === 'data_rel') ? 'src_id' : 'record_id';
            $this->db->query(
                "DELETE `d` FROM `{$table}` `d`"
                . " INNER JOIN `field` `f` ON `f`.`field_id` = `d`.`field_id`"
                . " WHERE `d`.`{$column}` = ? AND `f`.`area_id` = ?",
                'ii', [$recordId, $areaId]
            );
        }
        $this->db->popResult();
    }

    /**
     * Delete the media files noted in removeAreaData().
     *
     * Called from Record::hookOnSaved() - after the check and writing, but still
     * before the commit; as with promoteTmpFiles() this is the price of
     * file operations not being transactional.
     */
    public function removeAreaFiles(): void
    {
        $mediaDir = $this->config->get('paths.media_dir');
        foreach ($this->areaMoveFiles as $path) {
            @unlink("$mediaDir/$path");
        }
        $this->areaMoveFiles = [];
    }
}
