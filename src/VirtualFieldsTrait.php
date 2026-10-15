<?php
/**
 * Trait for Dataset subclasses
 * Processes data from virtual table fields in the data tables
 *
 * Concept "single-step updates" (Dataset::save() with $stayTemporary = true):
 * The temporary working copy collects intermediate states in its working state
 * (see saveTemporaryStep()) - for ALL languages: multilingual fields are stored
 * there as an array language_id => input, empty means "not translated".
 * File uploads are then only stored physically under the id of the working copy
 * and marked in the working state with 'temporary' = true.
 * `data_fil` is NOT written for the working copy.
 * Relations continue to be bound immediately to the working copy (`data_rel`).
 * Only on the final save ($stayTemporary = false) are the intermediate states
 * merged with the current input and placed into the meta/data fields of the
 * live record - one data_* row per language of the instance, with the
 * fallback to the primary language where a translation is missing (saveVirtualFields());
 * files move over during checkIn via promoteTmpFiles(), which creates the
 * `data_fil` entries from the file metadata of the working state.
 *
 * The live record carries NO working state. Its content cache lies per language
 * in `record_cache` (_writeRecordCache()), with a marker for the fallback values;
 * a new working copy takes its working state from it (_tmpDataFromRecordCache()).
 *
 * Concept "secure files immediately, thumbnails later":
 * A file (upload from $_FILES or a selected server file from $data) is checked
 * for type IMMEDIATELY and - if permitted - stored physically under the id of the
 * working copy and entered in its working state (secureUploads()).
 * This way it survives a subsequently failing input check and appears in the
 * re-displayed input form.
 * Thumbnail generation is decoupled from this: the file entry is marked with
 * 'thumbnails_pending' = true and only processed on the FINAL save via
 * writePendingThumbnails() (from saveVirtualFields()) - so once per
 * record instead of at every intermediate step.
 */
namespace Camindo\Core;
use Camindo\Core\DirectoryHelpers;
use Camindo\Core\InstanceCache;
use Camindo\Core\MediaAccess;

trait VirtualFieldsTrait {

    // Field types whose content can be included in full-text fields
    protected array $fulltextSourceTypes = ['text', 'chars', 'json', 'file'];   // file: the file name
    protected array $processedParentFieldIds = [];
    protected array $processedUploadFields = [];
    protected array $recordCache = [];
    // Upload fields [name, language_id] whose thumbnails are only generated AFTER the
    // files have been moved to the live id (see saveVirtualFields() /
    // writeDeferredThumbnails())
    protected array $deferredThumbnails = [];
    // Ids of the changed text fields (sources for the full-text index):
    // collected in saveVirtualFields() and processed in refreshAffectedFulltext()
    // (AFTER checkIn(), since the copied-back relations are needed)
    protected array $affectedTextFieldIds = [];
    protected array $mediaTypeGroups = [
        'image' => ['image/*'],
        'pdf'   => ['application/pdf', '.pdf'],
        'audio' => ['audio/*'],
        'video' => ['video/*'],
        'zip'   => [
            'application/zip', 'application/x-zip-compressed', 'application/gzip',
            'application/x-tar', 'application/x-bzip2', 'application/x-7z-compressed',
            'application/x-rar-compressed', 'application/vnd.rar'
        ],
        'text'  => [
            'text/plain', 'text/rtf', 'application/rtf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
            '.txt', '.rtf', '.doc', '.docx', '.odt'
        ],
        'excel' => [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.spreadsheet', 'text/csv',
            '.xls', '.xlsx', '.ods', '.csv'
        ],
        'ppt'   => [
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.presentation',
            '.ppt', '.pptx', '.odp'
        ],
    ];

    // Exceptions from a group: if a file only matches via the group, these
    // patterns do not count. SVG is an image but can contain JavaScript and
    // runs when called directly from /media in the origin of the website (stored
    // XSS) - whoever wants SVG must name it explicitly in media_types_incl
    // (developer docs, chapter 14 (Security: web): security: web)
    protected array $mediaTypeGroupsExcl = [
        'image' => ['image/svg+xml', '.svg', '.svgz'],
    ];

    // Extensions that are NEVER stored for security reasons
    // (executable code in the media directory), regardless of media_types_incl
    protected array $forbiddenExtensions = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar',
        'cgi', 'pl', 'asp', 'aspx', 'jsp', 'sh', 'htaccess'
    ];

    // Uploads come from website visitors (CMS:USER_INPUT / USER_REGISTER, see
    // setUntrustedUploads()): active content that the browser executes as a document or
    // script (MediaAccess::isActiveContent() - extension OR detected
    // MIME type) is then rejected regardless of the field settings. In the
    // backend, the fields decide.
    protected bool $untrustedUploads = false;

    // Fallback MIME type -> extension (if the original file name contains no extension)
    protected array $mimeToExtension = [
        'image/jpeg' => 'jpg',  'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/bmp' => 'bmp', 'application/pdf' => 'pdf',
        'audio/mpeg' => 'mp3',  'video/mp4' => 'mp4', 'application/zip' => 'zip',
        'text/plain' => 'txt',
    ];

    // Consistency check MIME type <-> extension for the most important image types:
    // if the MIME type is recognized server-side as one of these types, the
    // file extension MUST match (prevents e.g. JPEG content with a .zip extension).
    // Other MIME types: no consistency check.
    //
    // The list covers all formats that a media processor opens - an uploaded
    // file must never be stored under an extension that misrepresents its content
    // (developer docs, chapter 14 (Security: web): security: web, ImageMagick section).
    protected array $mimeExtensionMap = [
        'image/jpeg'                => ['jpg', 'jpeg', 'jpe'],
        'image/png'                 => ['png'],
        'image/gif'                 => ['gif'],
        'image/webp'                => ['webp'],
        'image/bmp'                 => ['bmp', 'dib'],
        'image/x-ms-bmp'            => ['bmp', 'dib'],
        'image/tiff'                => ['tif', 'tiff'],
        'image/svg+xml'             => ['svg'],
        'application/pdf'           => ['pdf'],
        'image/vnd.adobe.photoshop' => ['psd'],
        'application/x-photoshop'   => ['psd'],
        'image/heic'                => ['heic', 'heif'],
        'image/heif'                => ['heic', 'heif'],
        'image/avif'                => ['avif'],
        'image/x-icon'              => ['ico'],
        'image/vnd.microsoft.icon'  => ['ico'],
    ];

    /**
     * Return the extended schema with virtual fields
     * Note: does not load the 'rel_area_ids' from table `rel_field_area`, for that use: getRelAreaIds()
     *
     * @param bool $addMetaFields  true: add all meta fields, false: only meta fields on which the input field is based
     * @param int $areaId          id of the content area
     * @return array               schema
     */
    public function virtualSchema(int $areaId, $addMetaFields = true): array
    {
        if ($areaId < 1) {
            throw new \Exception('Error: virtualSchema(): called with areaId < 1.');
        }
        // load virtual fields, sorted in the correct order
        $schema = [];
        $this->db->pushResult();
        $sql = "SELECT * FROM `field` WHERE `area_id`=? ORDER BY `position` ASC";
        $this->db->query($sql, 'i', [$areaId]);
        if ($this->db->num_rows > 0) {
            while ($row = $this->db->fetch()) {
                // label, note, details: read filter as in Cms::fetch()
                // (developer docs, chapter 14 (Security: web): security: web)
                $row = $this->cms->_sanitizeValue($row);
                $name = (string)$row['name'];
                // provide flags as an array
                if (empty($row['flags'])) $row['flags'] = [];
                else $row['flags'] = json_decode((string)$row['flags'], false) ?? [];
                // set meta = 1 marker
                if (isset($this->cms->schema[$name])) $row['meta'] = 1;
                $schema[$name] = $row;
            }
        }
        $this->db->popResult();

        if ($addMetaFields) {
            // append meta fields that are not already included
            $schema = $schema + $this->cms->schema;
        }
        return $schema;
    }

    /**
     * Save content into virtual fields (final save)
     *
     * The intermediate states of the working copy (single-step updates, see
     * saveTemporaryStep()) have already been merged in Dataset::save() with the
     * data currently submitted ($data / $affectedFields) and are placed here into the
     * data tables of the live record.
     * New file uploads from this request lie physically under the id of the
     * working copy and are marked with 'temporary' in its working state; the move
     * to the live id, including creating the `data_fil` entries, takes place when
     * going live in TemporaryTrait::checkIn() -> promoteTmpFiles().
     *
     * LANGUAGES
     * --------------------
     * Each field produces ONE row per language of the instance in its data_* table
     * ($langCtx, primary language first):
     *
     *     single-language field  the same value in all languages
     *     multilingual field     the value of the language; if it is empty (not translated),
     *                            the value of the primary language (fallback)
     *
     * The same applies to `data_dft`. In this way Cms::select() filters and sorts
     * each language with a single JOIN condition. The content cache per language
     * (`record_cache`) is ultimately built from the working state $this->recordCache
     * (working-state format, see _recordCacheFromTmpData()).
     *
     * Notes for the caller (Dataset::save()):
     * - call BEFORE checkIn() (promoteTmpFiles() reads the working state of the
     *   working copy - it must still exist for this)
     * - all values in $data are already in database format
     *   (validateFieldValue(): timestampl in UTC, currency in cents, json as an array)
     * - the full-text refresh is done separately via refreshAffectedFulltext()
     *   AFTER checkIn() (requires the copied-back relations)
     *
     * @param array &$affectedFields  fields whose content changes (incl. intermediate states)
     * @param array &$data            contents to write (incl. intermediate states)
     * @param array &$previousData    on UPDATE: contents of the live record beforehand
     * @param array &$schemaVirtual   schema with virtual fields
     * @param int   $tmpId            id of the temporary working copy (0 = none)
     * @param array $langCtx          languages of the instance (Dataset::languageContext())
     */
    protected function saveVirtualFields(array &$affectedFields, array &$data, array &$previousData, array &$schemaVirtual, int $tmpId = 0, array $langCtx = []): void
    {
        $recordId = (int)$data['record_id'];
        if (empty($recordId)) {
            throw new \Exception('Error: saveVirtualFields() called with $recordId = 0.');
        }
        $languageIds = (array)($langCtx['language_ids'] ?? []);
        if (empty($languageIds)) {
            $langCtx = $this->languageContext((int)($data['area_id'] ?? $previousData['area_id'] ?? 0));
            $languageIds = (array)($langCtx['language_ids'] ?? [0]);
        }
        $primaryId = (int)($langCtx['primary_id'] ?? $languageIds[0]);

        // Id under which new files are physically stored FIRST:
        // the temporary working copy (if passed), otherwise the record itself.
        // (For new records the working copy goes live under its own id,
        //  then $tmpId === $recordId and no move takes place)
        $storageId = ($tmpId > 0) ? $tmpId : $recordId;
        // Working state (working-state format: raw input, multilingual as
        // language_id => value): for a working copy its intermediate state (contains
        // single-step updates incl. file info and delete markers), otherwise
        // the previous live state from `record_cache`
        if ($tmpId > 0) {
            $this->recordCache = $this->_loadTmpData($tmpId);
        }
        else {
            $this->recordCache = $this->_tmpDataFromRecordCache($recordId, $schemaVirtual, $langCtx);
        }
        // Encrypted content: the ciphertext of the data_enc row (base64) per language
        // goes into the cache unchanged (instead of a second encryption)
        $encryptedCache = [];

        // Collect the ids of the affected fields for the full-text index
        // (processed only in refreshAffectedFulltext(), see method comment)
        $this->affectedTextFieldIds = [];
        // default field: values per language for data_dft
        $defaultValues = null;

        // collection of already processed parent fields
        $this->processedParentFieldIds = [];

        // save contents
        // --------------------
        foreach ($affectedFields as $name) {
            if (isset($this->cms->schema[$name])) {
                // meta field or virtual field based on a meta field
                continue;
            }

            $colDef = $schemaVirtual[$name] ?? [];
            if (empty($colDef)) continue;

            // field_id
            $fieldId = (int)$colDef['field_id'];
            if (empty($fieldId)) continue;

            $type = (string)($colDef['type'] ?? 'text');
            $displayType = $colDef['display_type'];
            $label = $colDef['label'] ?? $name;
            $isMultilingual = !empty($colDef['multilingual'])
                && in_array($type, $this->cms->multilingualTypes, true);
            // full text is generated afterwards
            if ($type == 'fulltext') continue;

            if (in_array($type, $this->fulltextSourceTypes, true)) {
                // indexable text field
                $this->affectedTextFieldIds[] = (int)$fieldId;
            }

            $dataTable = $this->cms->dataSuffix[$type] ?? 'txt';
            $fieldNameId = $this->cms->resolveVirtualField($name)['fieldname_id'];
            $valueRef = &$data[$name];

            if (in_array($type, $this->cms->relationTypes, true)) {
                // links
                // were moved forward in Dataset::save() so that min / max can be checked
                // (they hang on the working copy, checkIn copies them back)
                continue;
            }
            if ($type === 'file') {
                if ($displayType === 'upload') {
                    // upload
                    // --------------------
                    // (processing outsourced, so that it can also be used for
                    //  single-step saves;
                    //  writes ONLY the physical file + working state with the 'temporary' and
                    //  'thumbnails_pending' flags; the `data_fil` entry is only created
                    //  at checkIn, the thumbnails further below)
                    // $data may contain a selected server file;
                    // multilingual: one upload field per language
                    foreach ($this->_uploadLanguages($colDef, $languageIds) as $lang) {
                        $this->_saveUploadField($storageId, $name, $colDef, $schemaVirtual, $data, $lang);
                    }
                }
                // display_type 'thumbnail' is filled exclusively automatically
                // via writeThumbnails(), never directly from $data
                continue;
            }

            // Other types
            // --------------------
            // determine values per language: raw input (working-state format) and the
            // value that is written into the data_* row (with fallback)
            $rawValues = [];   // language_id => input ('' = not translated)
            $rowValues = [];   // language_id => value of the row
            if ($isMultilingual) {
                $input = is_array($valueRef) ? $valueRef : [$primaryId => $valueRef];
                $primaryValue = $input[$primaryId] ?? ($type === 'json' ? [] : '');
                foreach ($languageIds as $lang) {
                    $raw = $input[$lang] ?? ($type === 'json' ? [] : '');
                    $rawValues[$lang] = $raw;
                    $rowValues[$lang] = $this->_isEmptyInput($raw) ? $primaryValue : $raw;
                }
            }
            else {
                foreach ($languageIds as $lang) {
                    $rawValues[$lang] = $valueRef;
                    $rowValues[$lang] = $valueRef;
                }
            }

            // Password left empty: keep the previous one (Dataset::validateValue()
            // allows this on a change) - the data_pwd rows remain.
            if (($type === 'password' || $displayType === 'password') && $this->_isEmptyInput($valueRef)) {
                $this->recordCache[$name] = [$type, ''];
                continue;
            }

            // delete existing values (all languages)
            $sql = "DELETE FROM `data_{$dataTable}` WHERE `record_id` = ? AND `field_id` = ?";
            $this->db->query($sql, 'ii', [$recordId, $fieldId]);

            $isDateType = in_array($type, $this->cms->dateTypes, true);
            $hasEmptyFlag = $this->cms->hasEmptyFlag($type);
            foreach ($languageIds as $lang) {
                $value = $rowValues[$lang];
                // build query
                $sqlExpressions = ['`language_id` = ?', '`record_id` = ?', '`field_id` = ?', '`fieldname_id` = ?'];
                $sqlTypes = 'iiii';
                $sqlValues = [$lang, $recordId, $fieldId, $fieldNameId];
                // `empty` = 1: the row carries the fallback from the primary language, because
                // nothing was entered in this language (Cms::hasEmptyFlag())
                if ($hasEmptyFlag) {
                    $isFallback = $isMultilingual && $lang !== $primaryId && $this->_isEmptyInput($rawValues[$lang]);
                    $sqlExpressions[] = '`empty` = ?';
                    $sqlTypes .= 'i';
                    $sqlValues[] = $isFallback ? 1 : 0;
                }
                if ($type === 'encrypted') {
                    // Encrypted text + hash
                    // --------------------
                    // encrypt(text, salt, binary) - hash(normalized text, binary), NO salt;
                    // the hash is the search value: lowercase, without whitespace and punctuation
                    // (Cms::encryptedHash(), the same normalization when searching)
                    $encVal = $this->crypt->encrypt((string)($value ?? ''), '', true); // binary
                    $hashVal = $this->cms->encryptedHash((string)($value ?? '')); // binary, 32 Bytes
                    $sqlExpressions[] = '`data` = ?';
                    $sqlExpressions[] = '`hash` = ?';
                    $sqlTypes .= 'ss';
                    $sqlValues[] = $encVal;
                    $sqlValues[] = $hashVal;
                    // cache: base64, otherwise not JSON-capable (see Cms::createRecordCache())
                    $encryptedCache[$lang][$name] = [$type, base64_encode($encVal)];
                    if ($isMultilingual && $lang !== $primaryId && $this->_isEmptyInput($rawValues[$lang])) {
                        $encryptedCache[$lang][$name][] = 1;
                    }
                }
                else {
                    if ($type === 'json') {
                        // JSON-encode (arrays are present as such)
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        if ($value == '""') $value = '';
                    }
                    elseif ($displayType === 'password' && !in_array($name, $this->prehashedFields, true)) {
                        // hash the password - 64 characters with an embedded salt, matching
                        // the column `data_pwd`.`data` varchar(64); the comparison at
                        // login is done by RecordUser::passwordOk(). A value from the
                        // working state ($prehashedFields) is already the hash.
                        $value = $this->crypt->passwordHash((string)$value);
                    }
                    if ($isDateType
                        && str_ends_with((string)$value, '()')
                        && in_array(strtoupper((string)$value), $this->cms->funcWhitelist, true)
                    ) {
                        // permitted SQL date function from the whitelist (e.g. UTC_TIMESTAMP())
                        $sqlExpressions[] = "`data` = $value";
                    }
                    elseif ($isDateType && preg_match($this->dateExpression, (string)$value, $matches)) {
                        // CMS date function (e.g. "(now)", "(today)+3d")
                        $sqlExpressions[] = "`data` = '" . $this->parseDateExpression($matches) ."'";
                    }
                    else {
                        // transfer values separately from the statement
                        // (timestampl is already in UTC - validateFieldValue())
                        $sqlExpressions[] = '`data` = ?';
                        $sqlTypes .= $this->getBindType($type);
                        $sqlValues[] = $value;
                    }
                }
                // build query
                $sql = "INSERT INTO `data_{$dataTable}` SET " . implode(', ', $sqlExpressions);
                $this->db->query($sql, $sqlTypes, $sqlValues);
            }

            // default field? -> also write the text content per language into table data_dft
            // (no encrypted data, no password, no array)
            if ((int)$colDef['default_field'] === 1
                && !in_array($type, ['password', 'encrypted', 'json'], true)
                && $displayType !== 'password'
            ) {
                $defaultValues = [];
                foreach ($languageIds as $lang) {
                    $defaultValues[$lang] = mb_substr((string)$rowValues[$lang], 0, 255);
                }
            }

            // update the working state (raw inputs; passwords never - neither
            // plain text nor hash belong in the cache, see _writeRecordCache())
            if ($type === 'password' || $displayType === 'password') {
                $this->recordCache[$name] = [$type, ''];
            }
            else {
                $this->recordCache[$name] = [$type, $isMultilingual ? $rawValues : $valueRef];
            }
        }

        // Generate deferred thumbnails now (final save)
        // --------------------
        // Uploads were already stored physically under $storageId when saved
        // (secureUploads() / single steps) and marked in the working state with
        // 'thumbnails_pending'. Generation takes place once here -
        // BEFORE checkIn()/promoteTmpFiles(), so that the resulting thumbnail
        // metadata moves along and receives `data_fil` entries.
        // $persist: for a working copy the updated working state (dimensions
        // of the source file, new thumbnail entries) must be written back, because
        // promoteTmpFiles() reads THAT working state - even if the copy goes live under its
        // own id.
        //
        // EXCEPTION - existing record ($tmpId != $recordId): the files still lie
        // under the id of the working copy and are renamed to the live id at checkIn.
        // A background process (ffmpeg) started here
        // would open its source under the tmp id - often only after it has already been
        // renamed ("no such file") - and place its target under the tmp id, where
        // no trigger finds it anymore. Therefore the thumbnails are only generated there
        // after checkIn (writeDeferredThumbnails(), from Dataset::save()), with
        // the final paths. For new records (tmp id = record id) there is
        // no move.
        $this->deferredThumbnails = [];
        if ($tmpId > 0 && $tmpId !== $recordId) {
            foreach ($this->recordCache as $cacheName => $entry) {
                if (($entry[0] ?? '') !== 'file') continue;
                foreach ($this->fileEntries($entry) as $lang => $fileData) {
                    if (empty($fileData['thumbnails_pending'])) continue;
                    $languageId = ($lang === '') ? null : (int)$lang;
                    $this->deferredThumbnails[] = [(string)$cacheName, $languageId];
                    unset($fileData['thumbnails_pending']);
                    $this->setFileEntry($this->recordCache, (string)$cacheName, $languageId, $fileData);
                }
            }
        }
        else {
            $this->writePendingThumbnails($storageId, $schemaVirtual, ($tmpId > 0));
        }
                        
        // data_dft
        // --------------------
        // Is there a default field? Was it written? Otherwise enter the id.
        // An encrypted default field (or password, array) does not count - its
        // plain text does not belong in data_dft, there the id is then stored as well.
        $flagDefaultAvailable = false;
        foreach ($schemaVirtual as $colDef) {
            if ((int)$colDef['default_field'] === 1
                && !in_array((string)$colDef['type'], ['password', 'encrypted', 'json'], true)
                && ($colDef['display_type'] ?? '') !== 'password'
            ) {
                $flagDefaultAvailable = true;
            }
        }
        if ($defaultValues !== null || !$flagDefaultAvailable) {
            $this->db->query("DELETE FROM `data_dft` WHERE `record_id` = ?", 'i', [$recordId]);
            foreach ($languageIds as $lang) {
                $defaultData = $defaultValues[$lang] ?? "ID " . $recordId;
                $sql = "INSERT INTO `data_dft` SET `language_id` = ?, `record_id` = ?, `data` = ?";
                $this->db->query($sql, 'iis', [$lang, $recordId, $defaultData]);
            }
        }
        // clean up file delete markers ('deleted' flag from the input form, x button)
        // for the live cache: the entry is emptied -
        // the actual deletion of the live file takes place at checkIn
        // in promoteTmpFiles(), based on the (unchanged) working state of the working copy
        // ('temporary' flags deliberately remain here: promoteTmpFiles()
        //  still needs them in case the working copy goes live under its
        //  own id, and removes them afterwards itself)
        foreach ($this->recordCache as $cacheName => $entry) {
            if (($entry[0] ?? '') !== 'file') continue;
            foreach ($this->fileEntries($entry) as $langKey => $fileData) {
                if (empty($fileData['deleted'])) continue;
                if ($langKey === '') {
                    $this->recordCache[$cacheName] = ['file', [
                        'extension' => '', 'mime_type' => '', 'width' => 0,
                        'height' => 0, 'size' => 0, 'filename' => '', 'file_language_id' => null
                    ]];
                }
                else {
                    // multilingual: the language no longer has its own file -> entry
                    // removed, the fallback applies (_writeRecordCache())
                    $this->setFileEntry($this->recordCache, (string)$cacheName, (int)$langKey, null);
                }
            }
        }
        // write the content cache per language (`record_cache`)
        $this->_writeRecordCache($recordId, $this->recordCache, $schemaVirtual, $langCtx, $encryptedCache);
        // Notes:
        // - The move of the files to the live id and the creation of the
        //   `data_fil` entries (from the file metadata of the working state) takes place when
        //   the working copy goes live in
        //   TemporaryTrait::checkIn() -> promoteTmpFiles()
        // - The full-text content is refreshed separately via
        //   refreshAffectedFulltext() and must be called AFTER checkIn()
        //   (see Dataset::save()), since it needs the copied-back relations of
        //   the working copy
    }

    /**
     * Languages for which an upload field accepts files.
     *
     * @return array  [null] for single-language fields, otherwise the language_ids
     */
    protected function _uploadLanguages(array $colDef, array $languageIds): array
    {
        if (!$this->cms->isMultilingualField($colDef) || empty($languageIds)) return [null];
        return array_map('intval', $languageIds);
    }

    /**
     * Is an input empty ("not translated")?
     * Scalars: empty string; json: array without non-empty entries.
     */
    protected function _isEmptyInput(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $v) {
                if (!is_string($v) || trim($v) !== '') return false;
            }
            return true;
        }
        return trim((string)$value) === '';
    }

    /**
     * Single-step save (Dataset::save() with $stayTemporary = true):
     * The record stays temporary; the contents (already checked and converted)
     * are only cached in the working-state field of the working copy -
     * for multilingual fields per language (language_id => value, empty = not
     * translated; Dataset::save() has previously added the languages not submitted
     * from the existing intermediate state).
     *
     * Exceptions:
     * - file uploads are immediately stored physically under the id of the working copy
     *   and marked in the working state with 'temporary' = true (a file cannot
     *   be "cached"; `data_fil` is only written at checkIn)
     * - relations were already bound to the working copy in Dataset::save() (validateFieldValue ->
     *   createRelations)
     * - passwords are not cached (no plain text, no hash in the
     *   cache) - they can only be written in the final save;
     *   encrypted fields lie in the cache of the working copy as ciphertext
     *   (_writeRecordCache())
     *
     * Only the language rows that change are written: on a tab
     * switch the language of the tab - but as soon as a single-language field or the
     * primary language is affected, all (fallbacks of the other languages).
     *
     * On the final save, Dataset::save() merges the intermediate states with the
     * current inputs and writes them live.
     *
     * @param array &$affectedFields  fields whose contents change
     * @param array &$data            contents to write (already converted)
     * @param array &$schemaVirtual   schema with virtual fields
     * @param int $tmpId              id of the temporary working copy
     * @param array $tmpCache         previous intermediate state of the working copy
     * @param array $langCtx          languages of the instance (Dataset::languageContext())
     */
    protected function saveTemporaryStep(array &$affectedFields, array &$data, array &$schemaVirtual, int $tmpId, array $tmpCache, array $langCtx = []): void
    {
        if ($tmpId < 1) {
            throw new \Exception('Error: saveTemporaryStep() called with $tmpId < 1.');
        }
        // previous intermediate state as the basis
        $this->recordCache = $tmpCache;
        // collection of already processed parent fields (for thumbnails)
        $this->processedParentFieldIds = [];
        // language rows that must be rewritten (null = all)
        $primaryId = (int)($langCtx['primary_id'] ?? 0);
        $changedLanguages = [];
        $allLanguages = false;
        foreach ($affectedFields as $name) {
            // Only virtual fields are cached
            // (incl. input fields based on meta fields - their values
            //  are only written to the columns on the final save)
            $colDef = $schemaVirtual[$name] ?? [];
            if (empty($colDef)) continue;
            $type = (string)($colDef['type'] ?? 'text');
            $displayType = (string)($colDef['display_type'] ?? '');
            // full text is only generated on the final save
            if ($type === 'fulltext') continue;
            // relations are already attached to the working copy (data_rel)
            if (in_array($type, $this->cms->relationTypes, true)) continue;
            // do not cache passwords (see method comment)
            if ($type === 'password' || $displayType === 'password') continue;
            if ($type === 'file') {
                $allLanguages = true;
                if ($displayType === 'upload') {
                    // bind the upload to the working copy immediately
                    // (physically + working state with the 'temporary' and 'thumbnails_pending' flags;
                    //  thumbnails are only generated on the final save;
                    //  any 'deleted' flag that may be set is overwritten in the process)
                    foreach ($this->_uploadLanguages($colDef, (array)($langCtx['language_ids'] ?? [])) as $lang) {
                        $this->_saveUploadField($tmpId, $name, $colDef, $schemaVirtual, $data, $lang);
                    }
                }
                // display_type 'thumbnail' is filled exclusively automatically
                // via writeThumbnails()
                continue;
            }
            // take over the value (already converted, cf. Dataset::validateFieldValue())
            // into the intermediate state
            $value = $data[$name] ?? '';
            $previous = $this->recordCache[$name][1] ?? null;
            $this->recordCache[$name] = [$type, $value];
            // which language rows change as a result?
            $isMultilingual = $this->cms->isMultilingualField($colDef) && is_array($value);
            if (!$isMultilingual) {
                $allLanguages = true;
                continue;
            }
            foreach ($value as $lang => $langValue) {
                if (is_array($previous) && array_key_exists($lang, $previous) && $previous[$lang] === $langValue) continue;
                if ((int)$lang === $primaryId) $allLanguages = true;   // fallbacks of the others
                $changedLanguages[] = (int)$lang;
            }
        }
        // write the intermediate state into the `record_cache` rows of the working copy
        $this->_saveTmpData($tmpId, $this->recordCache, $allLanguages ? null : array_unique($changedLanguages));
    }

     /**
     * Processes the file input of a single upload field.
     *
     * Source of the file (in this order):
     * 1. Real upload in $_FILES['data'][$name]  -> takes PRECEDENCE
     * 2. Selected file lying on the server: $data[$name]['serverfile']
     *    (relative to paths.upload_dir)
     * 3. File of an import: $data[$name]['importfile'] (absolute path, must lie in
     *    paths.tmp_dir - RecordImport unpacks the import file there), plus
     *    optionally $data[$name]['filename'] as the original file name
     * If both are present at the same time, 'serverfile' is discarded.
     * The entry is read from the passed $data (not from $_REQUEST),
     * so that the method works independently of the origin of the data
     * (input form, importer, CLI, API, ...).
     *
     * Procedure: check MIME type / extension (an invalid file is discarded immediately),
     * store the file physically under $storageId (id of the working copy) and
     * update $this->recordCache (entry with 'temporary' = true).
     *
     * NEW: thumbnails are NO LONGER generated here. Instead, the cache entry receives
     * 'thumbnails_pending' = true; generation only takes place on the
     * final save via writePendingThumbnails() (see saveVirtualFields()).
     * Therefore 'width', 'height' and 'data' initially remain empty/0 here -
     * these details are only supplied by the processor during thumbnail generation, if at all.
     *
     * NO `data_fil` entry is written: it is only created when going live in
     * promoteTmpFiles(), from the cache metadata.
     *
     * @param int $storageId         id under which the file is stored physically
     * @param string $name           field name
     * @param array $colDef          field definition from the virtual schema
     * @param array &$schemaVirtual  schema with virtual fields
     * @param array $data            contents to save (from Dataset::save());
     *                               unchecked, therefore defensive access
     * @param int|null $languageId   multilingual field: language of the file
     *                               (form field data[name][{language_id}]); null for
     *                               single-language fields
     * @return bool                  true: file processed,
     *                               false: no (new) file specified
     */
    protected function _saveUploadField(int $storageId, string $name, array $colDef, array &$schemaVirtual, array $data = [], ?int $languageId = null): bool
    {
        $fieldId = (int)$colDef['field_id'];
        if (empty($fieldId) || $storageId < 1) return false;
        // ensure that each upload field is processed only once per request
        $processedKey = $name . ($languageId === null ? '' : '.' . $languageId);
        if (isset($this->processedUploadFields[$processedKey])) return false;
        $label = $colDef['label'] ?? $name;
        if ($languageId !== null) $label = $this->cms->textOrSnippet($label) . ' | ' . $languageId;

        // determine the source
        // --------------------
        // The upload file takes precedence: if a $_FILES entry is present (even erroneous,
        // but not UPLOAD_ERR_NO_FILE), a selected server file is discarded.
        // Multilingual: $_FILES['data'][...][$name][$languageId]
        $filesInfo = [];
        foreach (['error', 'size', 'tmp_name', 'name', 'type'] as $key) {
            $item = $_FILES['data'][$key][$name] ?? null;
            if ($languageId !== null) $item = is_array($item) ? ($item[$languageId] ?? null) : null;
            $filesInfo[$key] = is_array($item) ? null : $item;
        }
        $fileError  = (int)($filesInfo['error'] ?? UPLOAD_ERR_NO_FILE);
        $hasUpload  = ($fileError !== UPLOAD_ERR_NO_FILE);
        // server file from the passed contents
        // ($data[$name] is still unchecked and may have any type)
        $fieldValue = $data[$name] ?? null;
        if ($languageId !== null) $fieldValue = is_array($fieldValue) ? ($fieldValue[$languageId] ?? null) : null;
        $serverFile = is_array($fieldValue) ? trim((string)($fieldValue['serverfile'] ?? '')) : '';
        $importFile = is_array($fieldValue) ? trim((string)($fieldValue['importfile'] ?? '')) : '';

        $isUploaded = false;
        $deleteServerFile = '';
        if ($hasUpload) {
            // ---- 1. File from file upload (precedence, $serverFile is discarded) ----
            // transfer error (e.g. upload_max_filesize exceeded)? Then error
            if ($fileError !== UPLOAD_ERR_OK) {
                $this->createFieldError('error_file_upload', $name, $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            // uploaded file empty? Then error
            if (empty($filesInfo['size'])) {
                $this->createFieldError('error_file_empty', $name, $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $tmpFile = (string)($filesInfo['tmp_name'] ?? '');
            // upload file already processed or no longer present? Then skip
            // (e.g. when saved again in the same request after a failed attempt;
            //  the server file also remains discarded in that case - the upload had precedence)
            if ($tmpFile === '' || !is_uploaded_file($tmpFile)) return false;
            // original file name, sanitized against injections
            // (remove path parts and control characters, limit length)
            $fileOriginName = basename(str_replace('\\', '/', (string)($filesInfo['name'] ?? '')));
            $isUploaded = true;
        }
        elseif ($serverFile !== '') {
            // ---- 2. File in the upload directory (server file) ----
            // normalize the path. (Throws an exception if invalid.)
            $relativePath = DirectoryHelpers::checkPath($serverFile);
            $tmpFile = $this->config->get('paths.upload_dir') . '/' . $relativePath;
            if (!is_file($tmpFile)) {
                // selected file does not exist (any longer)
                $this->createFieldError('error_file_move_uploaded', $name, $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $fileOriginName = basename(str_replace('\\', '/', $tmpFile));
            // switch "delete after successful takeover": deletion only happens once
            // the record is saved (promoteTmpFiles())
            if (!empty($fieldValue['serverfile_delete'])) $deleteServerFile = $relativePath;
        }
        elseif ($importFile !== '') {
            // ---- 3. File from an unpacked import (RecordImport) ----
            // only within paths.tmp_dir - any other path would be access
            // to arbitrary files on the server
            $tmpDir = realpath((string)$this->config->get('paths.tmp_dir'));
            $real = realpath($importFile);
            if ($tmpDir === false || $real === false || !is_file($real)
                || !str_starts_with($real, $tmpDir . DIRECTORY_SEPARATOR)
            ) {
                $this->createFieldError('error_file_move_uploaded', $name, $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $tmpFile = $real;
            $fileOriginName = basename(str_replace('\\', '/', trim((string)($fieldValue['filename'] ?? ''))));
            if ($fileOriginName === '') $fileOriginName = basename($real);
        }
        else {
            // no (new) file specified? Then skip
            // (required field is checked during the final save)
            return false;
        }

        // mark the field as processed (see above)
        $this->processedUploadFields[$processedKey] = true;
        // prepare the file name for storage
        $fileOriginName = DirectoryHelpers::checkPath($fileOriginName);
        // determine the MIME type server-side
        // (the client value in $_FILES['data']['type'] is not trustworthy)
        $fileMime = '';
        if ($finfo = finfo_open(FILEINFO_MIME_TYPE)) {
            $fileMime = (string)(finfo_file($finfo, $tmpFile) ?: '');
        }
        if ($fileMime === '') {
            $fileMime = strtolower((string)($filesInfo['type'] ?? ''));
        }
        // the file extension must be converted to lowercase
        // (additionally sanitize; fallback via MIME type if no extension is present)
        $fileExtension = strtolower((string)pathinfo($fileOriginName, PATHINFO_EXTENSION));
        $fileExtension = (string)preg_replace('/[^a-z0-9]/', '', $fileExtension);
        if ($fileExtension === '') {
            $fileExtension = $this->mimeToExtension[$fileMime] ?? 'bin';
        }
        // always reject executable / dangerous extensions
        if (in_array($fileExtension, $this->forbiddenExtensions, true)) {
            $this->createFieldError('error_file_denied', $name, $label);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        // frontend: no active content (HTML, SVG, XML, JavaScript)
        if ($this->untrustedUploads && MediaAccess::isActiveContent($fileMime, $fileExtension)) {
            $this->createFieldError('error_file_denied', $name, $label);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        // check MIME type / extension consistency for the most important image types
        // (see $mimeExtensionMap): content recognized server-side as e.g. image/jpeg
        // must not be stored with a foreign extension (.zip, .png, ...).
        // Otherwise the file would lie in the media directory under a misleading name.
        // (own message with the recognized type - "not allowed" would otherwise mislead,
        //  e.g. for a PNG stored as .webp)
        if (isset($this->mimeExtensionMap[$fileMime])
            && !in_array($fileExtension, $this->mimeExtensionMap[$fileMime], true)
        ) {
            $this->createFieldError('error_file_mismatch', $name, $label, "{$fileMime}, .{$fileExtension}");
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        $allowed = $this->fileTypeAllowed($colDef, $fileMime, $fileExtension);
        // if no match, then error message (invalid file is discarded immediately)
        if (!$allowed) {
            $this->createFieldError('error_file_denied', $name, $label);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        // storage {instance_id}/{field_id} (Cms::mediaFolder())
        $fileFolder = $this->cms->mediaFolder($fieldId);
        // the file is first stored under the id of the working copy
        // (move to the live id only at checkIn, see promoteTmpFiles());
        // for multilingual fields with the language in the name (Cms::mediaBaseName())
        $fileBaseName = $this->cms->mediaBaseName($storageId, $languageId);
        // target file:
        $filePath = "{$fileFolder}/{$fileBaseName}.{$fileExtension}";
        // move the tmp file and set permissions
        $mediaDir = $this->config->get('paths.media_dir');

        if (!$fileSize = $this->_moveUploadedFile($tmpFile, $mediaDir, $filePath, $isUploaded)) {
            $this->createFieldError('error_file_move_uploaded', $name, $label);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // delete an existing old file with a different extension
        // (when replacing several times within the working copy the
        //  extension may change; the previous extension is in the 'temporary'
        //  cache entry of the working copy. Entries WITHOUT the 'temporary' flag
        //  describe the file of the live original - this is only
        //  replaced at checkIn in promoteTmpFiles())
        $previousEntry = $this->fileEntry((array)($this->recordCache[$name] ?? []), $languageId);
        if (!empty($previousEntry['temporary'])) {
            $previousExtension = (string)($previousEntry['extension'] ?? '');
            if ($previousExtension !== '' && $previousExtension !== $fileExtension) {
                @unlink("{$mediaDir}/{$fileFolder}/{$fileBaseName}.{$previousExtension}");
            }
        }
        // file metadata (on error the existing entry and existing file remain)
        $fileData = [
            'width' => 0,   // filled in later during thumbnail generation, if applicable
            'height' => 0,  // " "
            'data' => '{}', // " "
            'size' => $fileSize,
            'mime_type' => $fileMime,
            'extension' => $fileExtension,
            'filename'  => $fileOriginName,  // original file name, sanitized against injections
            'field_id'  => $fieldId,         // for the file path / `data_fil` entry at checkIn
            'file_language_id' => $languageId, // language of the file (null: single-language)
            'temporary' => true,
            // thumbnails are only generated on the final save
            // (writePendingThumbnails(), called from saveVirtualFields())
            'thumbnails_pending' => true
        ];
        // delete the server file after saving (path relative to paths.upload_dir)
        if ($deleteServerFile !== '') $fileData['serverfile_delete'] = $deleteServerFile;
        // set the file entry (also overwrites any 'deleted' flag
        // of a file previously removed via the x button)
        // NO `data_fil` entry for the working copy: it is only created
        // when going live in promoteTmpFiles() from these file metadata of the working state
        $this->setFileEntry($this->recordCache, $name, $languageId, $fileData);
        return true;
    }

    /**
     * Generates the thumbnails deferred when the uploads were saved.
     *
     * Called on the FINAL save (saveVirtualFields()) - so once per
     * record, not at every intermediate step. All file entries of the cache
     * that were marked by _saveUploadField() with
     * 'thumbnails_pending' = true are processed (including those from earlier
     * single steps).
     *
     * Works on $this->recordCache:
     * - generates the thumbnails recursively via writeThumbnails() (their entries
     *   also end up in the recordCache, with the 'temporary' flag)
     * - adds the info about the SOURCE file supplied by the processor
     *   ('width', 'height', 'data')
     * - removes the 'thumbnails_pending' flag
     *
     * Must run BEFORE checkIn()/promoteTmpFiles(), so that the thumbnail metadata
     * moves along and receives `data_fil` entries.
     *
     * @param int $storageId         id under which the files lie physically
     *                               (working copy or live record)
     * @param array &$schemaVirtual  schema with virtual fields
     * @param bool $persist          true: write the updated cache back to the record
     *                               $storageId. Necessary if the
     *                               files hang on a working copy with a live
     *                               original: promoteTmpFiles() reads the
     *                               cache OF THE WORKING COPY.
     * @return bool                  true, if thumbnails were processed
     */
    protected function writePendingThumbnails(int $storageId, array &$schemaVirtual, bool $persist = true): bool
    {
        if ($storageId < 1) return false;
        $mediaDir = $this->config->get('paths.media_dir');
        // collect entries first: writeThumbnails() changes $this->recordCache
        // (one entry per language for multilingual file fields)
        $pending = [];
        foreach ($this->recordCache as $cacheName => $entry) {
            if (($entry[0] ?? '') !== 'file') continue;
            foreach ($this->fileEntries($entry) as $lang => $fileData) {
                if (empty($fileData['thumbnails_pending'])) continue;
                $pending[] = [(string)$cacheName, ($lang === '') ? null : (int)$lang];
            }
        }
        if (empty($pending)) return false;

        $processed = false;
        foreach ($pending as [$cacheName, $languageId]) {
            $fileData = $this->fileEntry((array)($this->recordCache[$cacheName] ?? []), $languageId);
            // remove the flag in any case (no repetition within the same operation)
            unset($fileData['thumbnails_pending']);
            $this->setFileEntry($this->recordCache, $cacheName, $languageId, $fileData);

            $colDef = $schemaVirtual[$cacheName] ?? [];
            if (empty($colDef)) continue;
            $fieldId   = (int)($fileData['field_id'] ?? ($colDef['field_id'] ?? 0));
            $extension = (string)($fileData['extension'] ?? '');
            if ($fieldId < 1 || $extension === '') continue;
            // source file (stored under the storage id)
            $filePath = $this->cms->mediaPath($fieldId, $storageId, $languageId, $extension);
            if (!is_file($mediaDir . '/' . $filePath)) continue; // file no longer present

            // generate thumbnails recursively, starting from this upload field
            $info = $this->writeThumbnails($storageId, $fieldId, $filePath, $fileData, $schemaVirtual, false, $languageId);
            $processed = true;
            if (!empty($info)) {
                // thumbnail generation had access to the tools and may return
                // values for the keys 'width', 'height', 'data'.
                // `data` (later in `data_fil`) is a JSON field - do not store an empty string!
                if ((string)($info['data'] ?? '') === '') $info['data'] = '{}';
                $fileData = $this->fileEntry((array)($this->recordCache[$cacheName] ?? []), $languageId);
                $fileData['width']  = (int)$info['width'];
                $fileData['height'] = (int)$info['height'];
                $fileData['data']   = (string)$info['data'];
                $this->setFileEntry($this->recordCache, $cacheName, $languageId, $fileData);
            }
        }

        if ($persist) {
            // update the working state of the working copy ('temporary' and 'deleted' flags
            // are kept, promoteTmpFiles() still needs them)
            $this->_saveTmpData($storageId, $this->recordCache);
        }
        return $processed;
    }

    /**
     * Generate deferred thumbnails of an EXISTING record - after the
     * checkIn, when the source files lie under the live id (see
     * saveVirtualFields()). Called from Dataset::save().
     *
     * Like promoteBackgroundThumbnails() for the trigger: there is no working copy
     * any more, the `data_fil` rows of the thumbnails are written here directly.
     * Image thumbnails are created immediately, videos in the background as configured -
     * with source and target under the live id. The source file receives the dimensions
     * determined by the converter (width, height, data) afterwards.
     *
     * @param int   $recordId       live record
     * @param array $schemaVirtual  schema of the area
     */
    public function writeDeferredThumbnails(int $recordId, array &$schemaVirtual): void
    {
        $pending = $this->deferredThumbnails;
        $this->deferredThumbnails = [];
        if ($recordId < 1 || empty($pending)) return;
        $mediaDir = $this->config->get('paths.media_dir');
        $written = false;

        foreach ($pending as [$name, $languageId]) {
            $colDef = $schemaVirtual[$name] ?? [];
            $fieldId = (int)($colDef['field_id'] ?? 0);
            if ($fieldId < 1) continue;
            $fn = $this->cms->resolveVirtualField($name);
            $fieldNameId = (int)($fn['fieldname_id'] ?? 0);

            // source file: its `data_fil` row was just written by promoteTmpFiles()
            $this->db->pushResult();
            $this->db->query(
                'SELECT * FROM `data_fil` WHERE `record_id` = ? AND `field_id` = ? AND `file_language_id` <=> ? LIMIT 1',
                'iii', [$recordId, $fieldId, $languageId]
            );
            $parentData = $this->db->fetch() ?? [];
            $this->db->popResult();
            $extension = (string)($parentData['extension'] ?? '');
            if ($extension === '') continue;
            $parentPath = $this->cms->mediaPath($fieldId, $recordId, $languageId, $extension);
            if (!is_file($mediaDir . '/' . $parentPath)) continue;

            // generate thumbnails (writeThumbnails() works on $this->recordCache)
            $this->clearRecordCache();
            $info = $this->writeThumbnails($recordId, $fieldId, $parentPath, $parentData, $schemaVirtual, false, $languageId);

            foreach ($this->recordCache as $thumbName => $entry) {
                if (!is_array($entry) || ($entry[0] ?? '') !== 'file') continue;
                foreach ($this->fileEntries($entry) as $langKey => $fileData) {
                    $lang = ($langKey === '') ? null : (int)$langKey;
                    $thumbFieldId = (int)($fileData['field_id'] ?? 0);
                    $thumbNameId  = (int)($fileData['fieldname_id'] ?? 0);
                    if ($thumbFieldId < 1 || $thumbNameId < 1) continue;
                    // do not leave an old thumbnail file with a different extension behind
                    $previous = $this->_getStoredExtension($recordId, $thumbNameId, $lang);
                    if ($previous !== '' && $previous !== (string)($fileData['extension'] ?? '')) {
                        @unlink("{$mediaDir}/" . $this->cms->mediaPath($thumbFieldId, $recordId, $lang, $previous));
                    }
                    $this->_writeFileRow($recordId, $thumbNameId, $thumbFieldId, $fileData, $lang);
                    if ($lang !== null) $this->_syncFileRows($recordId, $thumbNameId, $thumbFieldId);
                    $written = true;
                }
            }

            // add the dimensions of the source file (determined by the converter)
            if (!empty($info) && $fieldNameId > 0) {
                $data = (string)($info['data'] ?? '');
                if ($data === '') $data = '{}';
                $this->db->query(
                    'UPDATE `data_fil` SET `width` = ?, `height` = ?, `data` = ?'
                    . ' WHERE `record_id` = ? AND `fieldname_id` = ? AND `file_language_id` <=> ?',
                    'iisiii', [(int)$info['width'], (int)$info['height'], $data, $recordId, $fieldNameId, $languageId]
                );
                $written = true;
            }
        }

        if ($written) {
            // discard the content cache: Cms::fetch() rebuilds it on the next access
            // from the data_* rows (including the thumbnails)
            $this->db->query("DELETE FROM `record_cache` WHERE `record_id` = ?", 'i', [$recordId]);
        }
    }

    /**
     * Regenerate all thumbnails of a live record - for the tool
     * "regenerate thumbnails" (Tools -> Data, ToolThumbnails), e.g. after
     * changed converter settings or a new thumbnail field.
     *
     * The source is every file of the record in a field on which a thumbnail field
     * depends (parent_field_id) and which is not itself a thumbnail - the cascade
     * below it is handled recursively by writeThumbnails(). One pass per language
     * version of the file (file_language_id). The generation itself is the same as after
     * saving (writeDeferredThumbnails()): images immediately, videos in the
     * background as configured; the record_cache is discarded.
     *
     * @param int   $recordId       live record
     * @param array $schemaVirtual  schema of the area
     * @return int  number of source files for which thumbnails were generated
     */
    public function regenerateThumbnails(int $recordId, array &$schemaVirtual): int
    {
        if ($recordId < 1) return 0;
        // source fields: file fields to which a thumbnail field points
        $parentIds = [];
        foreach ($schemaVirtual as $colDef) {
            if ((string)($colDef['type'] ?? '') !== 'file') continue;
            if ((string)($colDef['display_type'] ?? '') !== 'thumbnail') continue;
            $parentIds[(int)($colDef['parent_field_id'] ?? 0)] = true;
        }
        $sources = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') !== 'file') continue;
            if ((string)($colDef['display_type'] ?? '') === 'thumbnail') continue;
            if (isset($parentIds[(int)($colDef['field_id'] ?? 0)])) $sources[(string)$name] = (int)$colDef['field_id'];
        }
        if (empty($sources)) return 0;

        // files of the record in these fields (one per language version)
        $this->deferredThumbnails = [];
        $this->db->pushResult();
        foreach ($sources as $name => $fieldId) {
            $this->db->query(
                "SELECT DISTINCT `file_language_id` FROM `data_fil` WHERE `record_id` = ? AND `field_id` = ? AND `extension` != ''",
                'ii', [$recordId, $fieldId]
            );
            foreach ($this->db->fetchAll() as $languageId) {
                $this->deferredThumbnails[] = [$name, ($languageId === null) ? null : (int)$languageId];
            }
        }
        $this->db->popResult();
        $count = count($this->deferredThumbnails);
        if ($count > 0) $this->writeDeferredThumbnails($recordId, $schemaVirtual);
        return $count;
    }

    /**
     * Refresh the full text of a saved record.
     *
     * 1. The full-text fields of ITS area, into which one of the changed fields
     *    goes into (collected in saveVirtualFields()), and those with sources via a
     *    link or relation field (the edges may have changed), plus the
     *    standard full text. If a target record of a relation changes, the
     *    full text learns nothing of this (deliberately: a keyword may hang on thousands
     *    of records) - the scheduler task fulltext catches up on this.
     * 2. If it is the content of a link field (LinkTrait), also the full text of its
     *    owner: its full-text fields with sources via this link field and
     *    its standard full text.
     *
     * Must be called AFTER checkIn(), since the relations of the working copy
     * are only copied back to the live record there.
     *
     * @param int $recordId  id of the live record
     */
    public function refreshAffectedFulltext(int $recordId): void
    {
        $affectedTextFieldIds = array_map('intval', $this->affectedTextFieldIds);
        // reset the collection (single processing per save operation)
        $this->affectedTextFieldIds = [];
        $this->db->query("SELECT `area_id` FROM `record` WHERE `record_id` = ?", 'i', [$recordId]);
        $areaId = (int)($this->db->fetchNum()[0] ?? 0);
        if ($areaId < 1) return;

        // 1. own area
        $in = $affectedTextFieldIds ? implode(',', $affectedTextFieldIds) : '0';
        $this->db->query(
            "SELECT DISTINCT `r`.`fulltext_field_id` FROM `rel_fulltext_text` `r`"
            . " INNER JOIN `field` `f` ON `f`.`field_id` = `r`.`fulltext_field_id`"
            . " WHERE `f`.`area_id` = ? AND (`r`.`rel_field_id` IS NOT NULL OR `r`.`text_field_id` IN ({$in}))",
            'i', [$areaId]
        );
        foreach (array_map('intval', $this->db->fetchAll()) as $fulltextFieldId) {
            $this->refreshFulltext($fulltextFieldId, $recordId);
        }
        $this->refreshStandardFulltext($areaId, $recordId);

        // 2. owner via a link field
        $this->db->query(
            "SELECT `d`.`src_id`, `d`.`field_id`, `o`.`area_id` FROM `data_rel` `d`"
            . " INNER JOIN `field` `l` ON `l`.`field_id` = `d`.`field_id` AND `l`.`type` = 'link'"
            . " INNER JOIN `record` `o` ON `o`.`record_id` = `d`.`src_id` AND `o`.`tmp` = 0"
            . " WHERE `d`.`dest_id` = ?",
            'i', [$recordId]
        );
        foreach ($this->db->fetchAll() as $owner) {
            $this->db->query(
                "SELECT DISTINCT `fulltext_field_id` FROM `rel_fulltext_text` WHERE `rel_field_id` = ?",
                'i', [(int)$owner['field_id']]
            );
            foreach (array_map('intval', $this->db->fetchAll()) as $fulltextFieldId) {
                $this->refreshFulltext($fulltextFieldId, (int)$owner['src_id']);
            }
            $this->refreshStandardFulltext((int)$owner['area_id'], (int)$owner['src_id']);
        }
    }

    // Standard full-text field `fulltext`
    // ====================
    // Every record area (Area::CONTENT_TYPES) has it, without a row in `field`: the
    // name is reserved (Field::FULLTEXT) and fixed in `fieldname` (type
    // 'fulltext'), the rows in `data_ftx` have field_id NULL. Sources are always
    // all fields of the area of type text, chars, json and file (file name, without
    // thumbnails) - standardFulltextSources(). select() filters by the field name
    // and does not need the field definition; the search masks offer it automatically.

    /** Name of the standard full-text field (reserved) */
    public const STANDARD_FULLTEXT = 'fulltext';

    /**
     * fieldname_id of the standard field - creates the entry in `fieldname` if it
     * is missing (Field::cleanupFieldname() leaves it in place).
     */
    public function standardFulltextFieldnameId(): int
    {
        $resolved = $this->cms->resolveVirtualField(self::STANDARD_FULLTEXT);
        if ($resolved !== null) return (int)$resolved['fieldname_id'];
        $this->db->pushResult();
        try {
            $this->db->query("INSERT IGNORE INTO `fieldname` (`name`, `type`) VALUES (?, 'fulltext')", 's', [self::STANDARD_FULLTEXT]);
        }
        finally {
            $this->db->popResult();
        }
        $this->cms->clearFieldCaches();
        return (int)($this->cms->resolveVirtualField(self::STANDARD_FULLTEXT)['fieldname_id'] ?? 0);
    }

    /**
     * Source fields of the standard field: text, chars, JSON and upload fields of the
     * area (no thumbnails) and, via its link fields, those of the target areas
     * (firmly linked content, one level) - format like `rel_fulltext_text`.
     */
    public function standardFulltextSources(int $areaId): array
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `field_id` AS `text_field_id`, 0 AS `rel_field_id` FROM `field`"
                . " WHERE `area_id` = ? AND `type` IN ('text', 'chars', 'json', 'file')"
                . " AND NOT (`type` = 'file' AND `display_type` = 'thumbnail')"
                . " ORDER BY `position`, `field_id`",
                'i', [$areaId]
            );
            $rows = [];
            while ($row = $this->db->fetch()) $rows[] = $row;
            $this->db->query(
                "SELECT `t`.`field_id` AS `text_field_id`, `l`.`field_id` AS `rel_field_id` FROM `field` `l`"
                . " INNER JOIN `rel_field_area` `ra` ON `ra`.`field_id` = `l`.`field_id`"
                . " INNER JOIN `field` `t` ON `t`.`area_id` = `ra`.`area_id`"
                . " WHERE `l`.`area_id` = ? AND `l`.`type` = 'link'"
                . " AND `t`.`type` IN ('text', 'chars', 'json', 'file')"
                . " AND NOT (`t`.`type` = 'file' AND `t`.`display_type` = 'thumbnail')"
                . " ORDER BY `l`.`position`, `t`.`position`, `t`.`field_id`",
                'i', [$areaId]
            );
            while ($row = $this->db->fetch()) $rows[] = $row;
            return $rows;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Rebuild the standard full text of a record or of all records of an area -
     * after saving, after changed fields of the area, via batch processing.
     */
    public function refreshStandardFulltext(int $areaId, ?int $recordId = null): void
    {
        $sources = $this->standardFulltextSources($areaId);
        $fieldNameId = $this->standardFulltextFieldnameId();
        if ($fieldNameId < 1) return;
        $recordIds = [$recordId];
        if ($recordId === null) {
            $this->db->query("SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 0", 'i', [$areaId]);
            $recordIds = array_map('intval', $this->db->fetchAll());
        }
        foreach ($recordIds as $id) {
            if (count($recordIds) > 1) set_time_limit(300);
            if (empty($sources)) {
                $this->db->query("DELETE FROM `data_ftx` WHERE `record_id` = ? AND `fieldname_id` = ?", 'ii', [$id, $fieldNameId]);
                continue;
            }
            $this->_refreshFulltext(null, (int)$id, $sources, $fieldNameId);
        }
    }

    /**
     * Transfers the files of a temporary working copy to the live record and
     * thereby creates the `data_fil` entries from the metadata
     * of the cache field of the working copy.
     *
     * Called from TemporaryTrait::checkIn() when the working copy
     * goes live (analogous to copying back the relations). Also when going live
     * under its own id (new record / copy: $tmpId === $recordId) - then the
     * physical move is omitted.
     *
     * Processing per file entry in the cache of the working copy:
     * 1. Flag 'deleted' (x button in the input form): a file possibly attached to the live
     *    record and its `data_fil` entry are deleted
     * 2. Flag 'temporary' (new / replaced file of the working copy): the physical
     *    file is renamed to the live id (an old live file
     *    and its entry are replaced; the old extension may differ)
     *    and the `data_fil` entry is created from the cache metadata.
     *    ALL 'temporary' entries are captured - including those from earlier
     *    single steps (multi-step updating before the record finally
     *    goes live).
     * 3. Entries without flags describe the unchanged file of the live
     *    original - nothing to do
     *
     * Finally, the 'temporary' flags are removed from the cache of the live
     * record (the files are now live).
     *
     * Preparation for multi-step saving: if further field types are to be moved out of the cache
     * in future, this can be added centrally here.
     *
     * Notes:
     * - Must take place within checkIn() BEFORE the working copy is deleted
     *   (it reads the cache of that copy)
     * - File operations are not transactional: if the save operation fails afterwards
     *   (rollback), the live files have already been replaced
     *
     * @param int $tmpId     id of the temporary working copy
     * @param int $recordId  id of the live record (may be $tmpId)
     */
    protected function promoteTmpFiles(int $tmpId, int $recordId): void
    {
        if ($tmpId < 1 || $recordId < 1) return;
        $mediaDir = $this->config->get('paths.media_dir');
        $this->db->pushResult();
        try {
            $tmpCache = $this->_loadTmpData($tmpId);
            // multilingual file fields whose `data_fil` rows are reconciled per language
            // at the end (fallback rows): fieldname_id => field_id
            $syncFields = [];
            foreach ($tmpCache as $name => $entry) {
                if (($entry[0] ?? '') !== 'file') continue;
                if (!$fn = $this->cms->resolveVirtualField((string)$name)) continue;
                $fieldNameId = (int)$fn['fieldname_id'];
                $entries = $this->fileEntries($entry);
                $isMultilingual = !isset($entries['']);

                foreach ($entries as $langKey => $fileData) {
                    $languageId = ($langKey === '') ? null : (int)$langKey;
                    $fieldId = (int)($fileData['field_id'] ?? 0);
                    if ($isMultilingual && $fieldId > 0) $syncFields[$fieldNameId] = $fieldId;

                    // 1. delete marker ('deleted' flag from the input form, x button)
                    // --------------------
                    if (!empty($fileData['deleted'])) {
                        // new copy / new record: no live stock present
                        if ($tmpId === $recordId) continue;
                        // remove the live file of THIS language together with its row(s)
                        // (for multilingual fields only the language's own file;
                        //  fallback rows of other languages are cleaned up by _syncFileRows())
                        $sql = 'SELECT DISTINCT `field_id`, `extension` FROM `data_fil`'
                            . ' WHERE `record_id` = ? AND `fieldname_id` = ? AND `file_language_id` <=> ?';
                        $this->db->query($sql, 'iii', [$recordId, $fieldNameId, $languageId]);
                        if ($this->db->num_rows < 1) continue;
                        [$prodFieldId, $prodExtension] = $this->db->fetchNum();
                        if ((string)$prodExtension !== '') {
                            @unlink("{$mediaDir}/" . $this->cms->mediaPath((int)$prodFieldId, $recordId, $languageId, (string)$prodExtension));
                        }
                        $sql = 'DELETE FROM `data_fil` WHERE `record_id` = ? AND `fieldname_id` = ? AND `file_language_id` <=> ?';
                        $this->db->query($sql, 'iii', [$recordId, $fieldNameId, $languageId]);
                        continue;
                    }

                    // 2. move only 'temporary' files
                    // --------------------
                    // entries without a flag describe the unchanged file of the
                    // live original
                    if (empty($fileData['temporary'])) continue;
                    if ($fieldId < 1) continue; // cannot be assigned without field_id
                    $fileFolder = $this->cms->mediaFolder($fieldId);
                    $extension  = (string)($fileData['extension'] ?? '');

                    if ($tmpId !== $recordId) {
                        // replace the existing old live file
                        // (its extension may differ from the new one)
                        $prodExtension = $this->_getStoredExtension($recordId, $fieldNameId, $languageId);
                        if ($prodExtension !== '') {
                            @unlink("{$mediaDir}/{$fileFolder}/" . $this->cms->mediaFileName($recordId, $languageId, $prodExtension));
                        }
                        // rename the physical file to the live id
                        // (empty extension -> no file)
                        if ($extension !== '') {
                            $tmpPath  = "{$mediaDir}/{$fileFolder}/" . $this->cms->mediaFileName($tmpId, $languageId, $extension);
                            $prodPath = "{$mediaDir}/{$fileFolder}/" . $this->cms->mediaFileName($recordId, $languageId, $extension);
                            if (is_file($tmpPath) && !@rename($tmpPath, $prodPath)) {
                                throw new \Exception("Error: promoteTmpFiles(): could not rename \"{$tmpPath}\".");
                            }
                        }
                    }

                    // create the `data_fil` entry from the file metadata of the working state
                    $this->_writeFileRow($recordId, $fieldNameId, $fieldId, $fileData, $languageId);

                    // delete the adopted server file (switch in the input form) -
                    // only after the commit, otherwise a rollback would lose it
                    $serverFile = (string)($fileData['serverfile_delete'] ?? '');
                    if ($serverFile !== '') {
                        $this->db->afterCommit(fn() => $this->_deleteServerFile($serverFile));
                    }
                }
            }

            // multilingual file fields: bring up to date the fallback rows of the languages
            // without their own file
            foreach ($syncFields as $fieldNameId => $fieldId) {
                $this->_syncFileRows($recordId, $fieldNameId, $fieldId);
            }

            // remove the 'temporary' flags from the content cache of the live record
            // (the files are now live; saveVirtualFields() previously took over the cache
            //  including the flags from the working copy) and update the
            // `version` of the rows (cache breaker of the references, see
            // Cms::cmsMedia() / Backend::backendValue())
            $versions = [];
            $this->db->query(
                "SELECT `language_id`, `field_id`, `version` FROM `data_fil` WHERE `record_id` = ?",
                'i', [$recordId]
            );
            foreach ($this->db->fetchAll() as $row) {
                $versions[(int)$row['language_id']][(int)$row['field_id']] = (int)$row['version'];
            }
            foreach ($this->_loadRecordCacheRows($recordId) as $lang => $prodCache) {
                $changed = false;
                foreach ($prodCache as $cacheName => $entry) {
                    if (($entry[0] ?? '') !== 'file') continue;
                    if (isset($entry[1]['temporary'])) {
                        unset($prodCache[$cacheName][1]['temporary']);
                        $changed = true;
                    }
                    $entryFieldId = (int)($entry[1]['field_id'] ?? 0);
                    $version = $versions[(int)$lang][$entryFieldId] ?? null;
                    if ($version !== null && (int)($entry[1]['version'] ?? -1) !== $version) {
                        $prodCache[$cacheName][1]['version'] = $version;
                        $changed = true;
                    }
                    // safety net: deferred thumbnails were processed in
                    // writePendingThumbnails(), the flag must not
                    // reach the live cache
                    if (isset($entry[1]['thumbnails_pending'])) {
                        unset($prodCache[$cacheName][1]['thumbnails_pending']);
                        $changed = true;
                    }
                    // the server file is done (see above)
                    if (isset($entry[1]['serverfile_delete'])) {
                        unset($prodCache[$cacheName][1]['serverfile_delete']);
                        $changed = true;
                    }
                }
                if ($changed) {
                    $jsonData = (string)json_encode($prodCache, JSON_UNESCAPED_UNICODE);
                    $this->db->query(
                        "UPDATE `record_cache` SET `cache` = ? WHERE `record_id` = ? AND `language_id` = ?",
                        'sii', [$jsonData, $recordId, (int)$lang]
                    );
                }
            }

        } finally {
            $this->db->popResult();
        }
    }

    /**
     * Reconcile the `data_fil` rows of a MULTILINGUAL file field per language.
     *
     * Own rows (file_language_id = language_id) remain. Every language of the
     * instance without its own file receives the row of the primary language with its
     * file_language_id (fallback) - if the primary language has no file either, an empty
     * row. Outdated fallback rows are replaced in the process.
     *
     * To be called after own files have been written or deleted
     * (promoteTmpFiles(), promoteBackgroundThumbnails()).
     *
     * @param int $recordId
     * @param int $fieldNameId
     * @param int $fieldId
     */
    protected function _syncFileRows(int $recordId, int $fieldNameId, int $fieldId): void
    {
        $languageIds = $this->recordLanguageIds($recordId);
        $primaryId   = $languageIds[0];

        // read own rows
        $own = [];
        $this->db->query(
            'SELECT * FROM `data_fil` WHERE `record_id` = ? AND `fieldname_id` = ?'
            . ' AND `file_language_id` IS NOT NULL AND `file_language_id` = `language_id`',
            'ii', [$recordId, $fieldNameId]
        );
        while ($row = $this->db->fetch()) {
            $own[(int)$row['language_id']] = $row;
        }

        // remove all other rows (fallbacks, remainders) ...
        $this->db->query(
            'DELETE FROM `data_fil` WHERE `record_id` = ? AND `fieldname_id` = ?'
            . ' AND NOT (`file_language_id` IS NOT NULL AND `file_language_id` = `language_id`)',
            'ii', [$recordId, $fieldNameId]
        );
        // ... and rewrite per language without its own file
        foreach ($languageIds as $lang) {
            if (isset($own[$lang])) continue;
            $source = $own[$primaryId] ?? null;
            $fileData = $source ? $source + ['file_language_id' => $primaryId]
                : ['extension' => '', 'mime_type' => '', 'filename' => '', 'size' => 0,
                   'width' => 0, 'height' => 0, 'token' => '', 'data' => '{}',
                   'file_language_id' => $lang];
            $this->_writeFileRow($recordId, $fieldNameId, $fieldId, $fileData, $lang, (int)$fileData['file_language_id']);
        }
    }

    /**
     * Delete a file adopted into an upload field from paths.upload_dir
     * (switch "delete after successful takeover", see _saveUploadField()).
     * Only within the upload directory; if the file is already missing, do nothing.
     *
     * @param string $relativePath  Pfad relativ zu paths.upload_dir
     */
    private function _deleteServerFile(string $relativePath): void
    {
        $uploadDir = realpath((string)$this->config->get('paths.upload_dir'));
        $file = realpath((string)$this->config->get('paths.upload_dir') . '/' . $relativePath);
        if ($uploadDir === false || $file === false || !is_file($file)
            || !str_starts_with($file, $uploadDir . DIRECTORY_SEPARATOR)
        ) return;
        @unlink($file);
    }

    /**
     * Write a `data_fil` row from the file metadata of the working state of a file entry.
     *
     * The only place in the project where `data_fil` rows are created - used by
     * promoteTmpFiles() (working copy goes live), _syncFileRows() (fallback rows)
     * and promoteBackgroundThumbnails() (a background process has delivered a source
     * file, the thumbnails are now created on it).
     *
     * `version` counts up on every write. Two purposes:
     *   1. anti-caching when delivering the file (?v=...)
     *   2. unique name in the trash, see trashFileName()
     * Only the respective current version is kept - the counter describes the
     * state of the file, not a history.
     *
     * An entry WITHOUT a file (empty extension, size 0) is deliberately written as well:
     * only it makes the record findable for the filters of Cms::select(),
     * see the class comment of Cms.
     *
     * LANGUAGES
     *     single-language field ($languageId null): the same row for EVERY language of the
     *         instance, file_language_id NULL - this way the language condition of the
     *         filters also applies to file fields
     *     multilingual field: ONE row for $languageId; file_language_id is the
     *         language of the physical file ($fileLanguageId, otherwise $languageId)
     *
     * @param int      $recordId        id of the live record
     * @param int      $fieldNameId     fieldname_id (together with language_id, record_id is the PK)
     * @param int      $fieldId         field_id of the field (determines the media directory)
     * @param array    $fileData        metadata: extension, mime_type, data, filename,
     *                                  size, width, height, token
     * @param int|null $languageId      language of the row (null: all languages, single-language)
     * @param int|null $fileLanguageId  language of the file (null: = $languageId)
     */
    private function _writeFileRow(int $recordId, int $fieldNameId, int $fieldId, array $fileData, ?int $languageId = null, ?int $fileLanguageId = null): void
    {
        // because `data` is a JSON field - do not store an empty string!
        $jsonData = (string)($fileData['data'] ?? '');
        if ($jsonData === '') $jsonData = '{}';

        $sql = 'INSERT INTO `data_fil`'
            . ' (`language_id`, `file_language_id`, `record_id`, `fieldname_id`, `field_id`, `extension`, `mime_type`,'
            . ' `data`, `filename`, `size`, `width`, `height`, `token`, `version`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
            . ' ON DUPLICATE KEY UPDATE'
            // PK: language_id, record_id, fieldname_id
            // field_id cannot change
            // VALUES(col) instead of the row alias (VALUES … AS new): MariaDB knows only this form
            . ' `file_language_id` = VALUES(`file_language_id`),'
            . ' `extension` = VALUES(`extension`),'
            . ' `mime_type` = VALUES(`mime_type`),'
            . ' `data` = VALUES(`data`),'
            . ' `filename` = VALUES(`filename`),'
            . ' `size` = VALUES(`size`),'
            . ' `width` = VALUES(`width`),'
            . ' `height` = VALUES(`height`),'
            . ' `token` = VALUES(`token`),'
            . ' `version` = `data_fil`.`version` + 1';

        $rows = ($languageId === null)
            ? array_map(fn($lang) => [$lang, null], $this->recordLanguageIds($recordId))
            : [[$languageId, $fileLanguageId ?? $languageId]];
        foreach ($rows as [$lang, $fileLang]) {
            $this->db->query($sql, 'iiiiissssiiis', [
                $lang,
                $fileLang,
                $recordId,
                $fieldNameId,
                $fieldId,
                (string)($fileData['extension'] ?? ''),
                (string)($fileData['mime_type'] ?? ''),
                $jsonData,
                (string)($fileData['filename'] ?? ''),
                (int)($fileData['size'] ?? 0),
                (int)($fileData['width'] ?? 0),
                (int)($fileData['height'] ?? 0),
                (string)($fileData['token'] ?? '')
            ]);
        }
    }

    /**
     * Generate thumbnails that build on a file completed in the BACKGROUND,
     * and create their `data_fil` entries.
     *
     * Counterpart to writePendingThumbnails() for the background path, called by
     * TriggerMedia as soon as a process has finished. The difference from the save operation:
     * here there is no working copy and no subsequent checkIn() that would transfer the
     * cache metadata into `data_fil` - this has to be done here directly.
     *
     * writeThumbnails() runs with forceBgproc: true, so the files it creates are
     * themselves generated in the background. Their rows therefore receive size/width/height 0
     * and the token of their process; TriggerMedia supplies the final values
     * once THAT process has finished as well. This is how a multi-stage
     * thumbnail chain advances cron run by cron run.
     *
     * @param int    $recordId        id of the live record
     * @param int    $parentFieldId   field_id of the completed file
     * @param string $parentPath      path to it from paths.media_dir
     * @param array  $parentData      its metadata (mime_type, extension, filename, ...)
     * @param array &$schemaVirtual   schema of the area
     * @return array                  names of the fields for which a row was written
     */
    public function promoteBackgroundThumbnails(
        int $recordId,
        int $parentFieldId,
        string $parentPath,
        array $parentData,
        array &$schemaVirtual
    ): array
    {
        if ($recordId < 1 || $parentFieldId < 1) return [];

        // writeThumbnails() writes exclusively to $this->recordCache and records in
        // $this->processedParentFieldIds which fields were already processed. Both
        // must be fresh for each record - the trigger works through several processes in
        // one run, otherwise the second record would be skipped.
        $this->clearRecordCache();

        // language of the source file (multilingual field) - the thumbnails belong to
        // the same language; fallback rows of the other languages are brought along by
        // _syncFileRows()
        $languageId = $this->cms->fileLanguageId($parentData);

        $this->writeThumbnails(
            $recordId, $parentFieldId, $parentPath, $parentData, $schemaVirtual, forceBgproc: true, languageId: $languageId
        );

        $written = [];
        foreach ($this->recordCache as $name => $entry) {
            if (!is_array($entry) || ($entry[0] ?? '') !== 'file') continue;
            foreach ($this->fileEntries($entry) as $langKey => $fileData) {
                $lang = ($langKey === '') ? null : (int)$langKey;
                $fieldId     = (int)($fileData['field_id'] ?? 0);
                // writeThumbnails() enters the fieldname_id in the entry
                $fieldNameId = (int)($fileData['fieldname_id'] ?? 0);
                // without both ids the entry cannot be assigned (PK: language_id + record_id + fieldname_id)
                if ($fieldId < 1 || $fieldNameId < 1) continue;

                $this->_writeFileRow($recordId, $fieldNameId, $fieldId, $fileData, $lang);
                if ($lang !== null) $this->_syncFileRows($recordId, $fieldNameId, $fieldId);
                $written[] = (string)$name;
            }
        }

        if ($written !== []) {
            // discard the content cache: Cms::fetch() rebuilds it on the next access
            // from the data_* rows
            $this->db->query("DELETE FROM `record_cache` WHERE `record_id` = ?", 'i', [$recordId]);
        }

        return $written;
    }

    /**
     * Deletes the physical files of a record, e.g.
     * - of a temporary working copy when cancelling (TemporaryTrait::cancel())
     *   or when invalidating old working copies (TemporaryTrait::checkOut())
     * - of a live record on permanent deletion (hookOnDelete)
     * Live records: based on the `data_fil` entries (these are removed along with
     * the deletion of the record row via FK cascade).
     * Working copies: based on the 'temporary' entries in their cache
     * (working copies have no `data_fil` entries; entries without the
     *  'temporary' flag describe files of the live original and
     *  are deliberately NOT touched).
     *
     * @param int $recordId  id of the record (temporary or live)
     */
    public function deleteRecordFiles(int $recordId): void
    {
        if ($recordId < 1) return;
        $mediaDir = $this->config->get('paths.media_dir');
        $this->db->pushResult();
        foreach ($this->recordFileRows($recordId) as $row) {
            @unlink("{$mediaDir}/" . $this->cms->mediaPath((int)$row['field_id'], $recordId, $row['file_language_id'], $row['extension']));
        }
        $this->db->popResult();

        // 'temporary' files of a working copy based on its working state
        $cache = $this->_loadTmpData($recordId);
        foreach ($cache as $entry) {
            if (($entry[0] ?? '') !== 'file') continue;
            foreach ($this->fileEntries($entry) as $langKey => $fileData) {
                if (empty($fileData['temporary'])) continue;
                $extension = (string)($fileData['extension'] ?? '');
                $fieldId = (int)($fileData['field_id'] ?? 0);
                if ($extension === '' || $fieldId < 1) continue;
                $lang = ($langKey === '') ? null : (int)$langKey;
                @unlink("{$mediaDir}/" . $this->cms->mediaPath($fieldId, $recordId, $lang, $extension));
            }
        }
    }

    // ====================
    // Media files on delete / restore
    //
    // A record passes through three states, and its files move along with it:
    //
    //     live         {media_dir}/{instanceId}/{fieldId}/{recordId}.{ext}
    //        |  softDelete()  -> trashRecordFiles()
    //        v
    //     trash        {var_dir}/deleted/{fieldId}_{recordId}_{version}.{ext}
    //        |  restoreOne()  -> untrashRecordFiles()   back up again
    //        |  finalDelete() -> purgeRecordFiles()     gone for good
    //
    // The `data_fil` rows remain untouched on soft delete - only through them can it
    // later be found which file belongs to which field and which
    // extension and version it has. Only on permanent deletion do they disappear
    // along with the record row via FK cascade.
    //
    // Why the version is in the file name: `data_fil`.`version` counts up on every upload
    // (see promoteTmpFiles()). The name in the trash thus stays unique even when
    // the same record receives a new file after a restore and is deleted again.
    // ====================

    /**
     * File name of a file in the trash directory.
     *
     * @param int    $fieldId    `data_fil`.`field_id`
     * @param int    $recordId   `data_fil`.`record_id`
     * @param int    $version    `data_fil`.`version`
     * @param string $extension  without leading dot
     * @return string            file name without directory
     */
    protected function trashFileName(int $fieldId, int $recordId, int $version, string $extension, ?int $fileLanguageId = null): string
    {
        return $fieldId . '_' . $this->cms->mediaBaseName($recordId, $fileLanguageId) . '_' . $version . '.' . $extension;
    }

    /**
     * Get the file metadata of a record from `data_fil`.
     *
     * Common basis of the three file operations below. Entries without an extension
     * (thumbnail field without an effective converter) are skipped - they have
     * no physical file.
     *
     * @param int $recordId
     * @return array  rows with field_id, extension, version
     */
    protected function recordFileRows(int $recordId): array
    {
        if ($recordId < 1) return [];
        $this->db->pushResult();
        // only the rows of the physical files: single-language fields carry the same
        // row per language (file_language_id NULL -> DISTINCT), multilingual ones
        // one file per language (file_language_id = language_id); fallback
        // rows of other languages point to one of these files and do not count.
        $sql = 'SELECT DISTINCT `field_id`, `extension`, `version`, `file_language_id` FROM `data_fil`'
             . ' WHERE `record_id` = ? AND (`file_language_id` IS NULL OR `file_language_id` = `language_id`)';
        $this->db->query($sql, 'i', [$recordId]);
        $rows = $this->db->fetchAll();
        $this->db->popResult();

        $result = [];
        foreach ($rows as $row) {
            if ((string)$row['extension'] === '') continue;
            $result[] = [
                'field_id'         => (int)$row['field_id'],
                'extension'        => (string)$row['extension'],
                'version'          => (int)$row['version'],
                'file_language_id' => $this->cms->fileLanguageId($row),
            ];
        }
        return $result;
    }

    /**
     * SOFT DELETE: move the media files of a record into the trash.
     *
     * Called from Record::hookOnSoftDelete(). If a file is already missing (e.g. because
     * an earlier operation was aborted), it is silently skipped -
     * deleting the record should not fail because of it.
     *
     * Working copies remain untouched: their files are intermediate states without a
     * `data_fil` entry and are only removed on permanent deletion.
     *
     * @param int $recordId  id of the live record
     */
    public function trashRecordFiles(int $recordId): void
    {
        $rows = $this->recordFileRows($recordId);
        if (empty($rows)) return;

        $varDir     = $this->config->get('paths.var_dir');
        $deletedDir = $varDir .'/deleted';
        $mediaDir   = $this->config->get('paths.media_dir');

        // ensure the target directory exists (permissions from permissions.dir)
        if (!is_dir($deletedDir) && !$this->system->createDir(dirname($deletedDir), basename($deletedDir))) {
            throw new \Exception("Error: trashRecordFiles(): could not create \"{$deletedDir}\".");
        }

        foreach ($rows as $row) {
            $source = "{$mediaDir}/" . $this->cms->mediaPath((int)$row['field_id'], $recordId, $row['file_language_id'], $row['extension']);
            if (!is_file($source)) continue;
            $target = $deletedDir . '/' . $this->trashFileName(
                $row['field_id'], $recordId, $row['version'], $row['extension'], $row['file_language_id']
            );
            // A file with the same name can only be a remainder of an aborted
            // operation - the current state takes precedence.
            if (is_file($target)) @unlink($target);
            @rename($source, $target);
        }
    }

    /**
     * RESTORE: fetch the media files of a record back from the trash.
     *
     * Counterpart to trashRecordFiles(), called from Record::hookOnRestore().
     * The target directory {media_dir}/{instanceId}/{fieldId} is recreated if
     * necessary - it may have been cleared in the meantime.
     *
     * @param int $recordId  id of the live record
     */
    public function untrashRecordFiles(int $recordId): void
    {
        $rows = $this->recordFileRows($recordId);
        if (empty($rows)) return;

        $varDir     = $this->config->get('paths.var_dir');
        $deletedDir = $varDir .'/deleted';
        $mediaDir   = $this->config->get('paths.media_dir');

        foreach ($rows as $row) {
            $source = $deletedDir . '/' . $this->trashFileName(
                $row['field_id'], $recordId, $row['version'], $row['extension'], $row['file_language_id']
            );
            if (!is_file($source)) continue;
            $folder = $this->cms->mediaFolder((int)$row['field_id']);
            if (!$this->system->createDir($mediaDir, $folder)) continue;
            $target = "{$mediaDir}/{$folder}/" . $this->cms->mediaFileName($recordId, $row['file_language_id'], $row['extension']);
            if (is_file($target)) @unlink($target);
            @rename($source, $target);
        }
    }

    /**
     * PERMANENT DELETION: remove all media files of a record.
     *
     * Called from Record::hookOnDelete(), i.e. before the record row (and with it
     * the `data_fil` rows via FK cascade) disappears - afterwards extension and
     * version could no longer be determined.
     *
     * Cleanup takes place in BOTH locations: in the trash (the normal case, the record was
     * after all soft-deleted) and in the media directory (in case the move during the soft delete
     * failed or a caller deletes permanently directly).
     *
     * @param int $recordId  id of the live record
     */
    public function purgeRecordFiles(int $recordId): void
    {
        $rows       = $this->recordFileRows($recordId);

        $varDir     = $this->config->get('paths.var_dir');
        $deletedDir = $varDir .'/deleted';
        $mediaDir   = $this->config->get('paths.media_dir');

        foreach ($rows as $row) {
            @unlink($deletedDir . '/' . $this->trashFileName(
                $row['field_id'], $recordId, $row['version'], $row['extension'], $row['file_language_id']
            ));
            @unlink("{$mediaDir}/" . $this->cms->mediaPath((int)$row['field_id'], $recordId, $row['file_language_id'], $row['extension']));
        }

        // also clean up the files of working copies still present
        // (they lie under the tmp id and have no `data_fil` entries)
        $this->db->pushResult();
        $this->db->query("SELECT `record_id` FROM `record` WHERE `tmp` = 1 AND `origin_id` = ?", 'i', [$recordId]);
        $tmpIds = $this->db->fetchAll();
        $this->db->popResult();
        foreach ($tmpIds as $tmpId) {
            $this->deleteRecordFiles((int)$tmpId);
        }
    }

    // ====================
    // File entries in working-state format
    //
    //     single-language  ['file', {extension, mime_type, size, ..., file_language_id: null}]
    //     multilingual     ['file', {language_id: {...}, language_id: {...}}]
    //                      - only the languages with THEIR OWN file; a language without
    //                      an entry uses the file of the primary language (fallback), which
    //                      is stored in the cache and in `data_fil` (file_language_id)
    //
    // The physical file is called media/{field_id}/{record_id}[_{language_id}].{ext}
    // (Cms::mediaFileName()). Thumbnails of a multilingual upload field are
    // themselves multilingual (Field::hookOnSave()) and are generated per language from
    // their file.
    // ====================

    /**
     * File entries of a file entry as a list per language.
     *
     * @param array $entry  ['file', ...]
     * @return array        file_language_id|null => metadata
     *                      (the null key appears as '' in the array)
     */
    public function fileEntries(array $entry): array
    {
        $value = (array)($entry[1] ?? []);
        if ($this->cms->isLanguageKeyedFiles($value)) {
            $result = [];
            foreach ($value as $lang => $fileData) $result[(int)$lang] = (array)$fileData;
            return $result;
        }
        return ['' => $value];
    }

    /**
     * File entry of ONE language (null = single-language).
     */
    public function fileEntry(array $entry, ?int $languageId): array
    {
        $entries = $this->fileEntries($entry);
        if ($languageId === null) {
            // single-language - if a language list is nevertheless present, the primary language
            return (array)($entries[''] ?? (reset($entries) ?: []));
        }
        return (array)($entries[$languageId] ?? []);
    }

    /**
     * Set the file entry of a language (null = single-language), or remove it with null.
     *
     * @param array      &$tmpData    working-state format (field name => [type, value])
     * @param string     $name        field name
     * @param int|null   $languageId  language of the file, null for single-language fields
     * @param array|null $fileData    metadata; null removes the entry of the language
     */
    public function setFileEntry(array &$tmpData, string $name, ?int $languageId, ?array $fileData): void
    {
        if ($languageId === null) {
            $tmpData[$name] = ['file', $fileData ?? []];
            return;
        }
        $value = (array)($tmpData[$name][1] ?? []);
        if (!$this->cms->isLanguageKeyedFiles($value)) $value = [];
        if ($fileData === null) unset($value[$languageId]);
        else $value[$languageId] = $fileData + ['file_language_id' => $languageId];
        $tmpData[$name] = ['file', $value];
    }

    /**
     * Stored contents of an encrypted field per language (decrypted).
     *
     * Encrypted contents are not in the working state of the working copy (data_enc
     * principle, see Crypt) - on the final save, Dataset::save() fetches them
     * from the live record via this method if the request does not supply them (for all
     * languages). Fallback rows (`empty` = 1, content from the primary language)
     * come back as '', so that saveVirtualFields() sets the fallback again.
     *
     * @param int   $recordId  live record
     * @param int   $fieldId   field of type 'encrypted'
     * @param array $langCtx   languageContext() of the area
     * @return array           language_id => plain text; empty if nothing is stored
     */
    public function encryptedValues(int $recordId, int $fieldId, array $langCtx): array
    {
        if ($recordId < 1 || $fieldId < 1) return [];
        $primaryId = (int)($langCtx['primary_id'] ?? 0);
        $this->db->pushResult();
        $this->db->query(
            "SELECT `language_id`, `data`, `empty` FROM `data_enc` WHERE `record_id` = ? AND `field_id` = ?",
            'ii', [$recordId, $fieldId]
        );
        $values = [];
        foreach ($this->db->fetchAll() as $row) {
            $languageId = (int)$row['language_id'];
            $isFallback = !empty($row['empty']) && $languageId !== $primaryId;
            $values[$languageId] = $isFallback ? '' : $this->crypt->decrypt((string)$row['data'], '', true);
        }
        $this->db->popResult();
        return $values;
    }

    /**
     * Load the working state (intermediate state) of a working copy.
     *
     * The intermediate state lies - like the live state - per language in
     * `record_cache`, under the id of the working copy (see class comment of
     * TemporaryTrait). Here it comes into working-state format: field name => [type, value];
     * for multilingual fields the value is an array language_id => input
     * (empty = not translated), for file fields the metadata including the flags
     * 'temporary' / 'deleted' / 'thumbnails_pending', encrypted contents
     * decrypted. Missing language rows are NOT rebuilt - a
     * working copy has no data_* rows (new record: empty state).
     *
     * @param int $recordId  id of the working copy
     * @return array         working state, possibly []
     */
    protected function _loadTmpData(int $recordId): array
    {
        [$schemaVirtual, $langCtx] = $this->_tmpContext($recordId);
        if (empty($langCtx)) return [];
        return $this->_tmpDataFromRecordCache($recordId, $schemaVirtual, $langCtx, rebuild: false);
    }

    /**
     * Write the working state of a working copy - into its `record_cache` rows,
     * derived per language as for the live state (_writeRecordCache()).
     *
     * @param int        $recordId     id of the working copy
     * @param array      $tmpData      working state (format see _loadTmpData())
     * @param int[]|null $languageIds  only renew these language rows (null = all);
     *                                 see saveTemporaryStep()
     * @param bool       $touch        refresh the edit lock (changed_at/by)
     */
    protected function _saveTmpData(int $recordId, array $tmpData, ?array $languageIds = null, bool $touch = false): void
    {
        [$schemaVirtual, $langCtx] = $this->_tmpContext($recordId);
        if (empty($langCtx)) return;
        $this->_writeRecordCache($recordId, $tmpData, $schemaVirtual, $langCtx, [], $languageIds, keepPasswordHash: true);
        if ($touch) {
            $this->db->query(
                "UPDATE `record` SET `changed_at` = UTC_TIMESTAMP(), `changed_by` = ? WHERE `record_id` = ?",
                'ii', [$this->adminIdForDb(), $recordId]
            );
        }
    }

    /**
     * Carry over the working state of all working copies of an area after a field conversion
     * (Field::convert()): the field's entry receives the new type,
     * $convert recalculates the values (plain text in, database format out;
     * _writeRecordCache() encrypts if necessary). Empty inputs remain empty.
     *
     * To be called after `field` carries the type and multilingualism of the target: which
     * form the value has (array per language or single), is read by
     * _tmpDataFromRecordCache() from the schema; in between only reshaping takes place -
     * multilingual -> single-language keeps the primary language.
     *
     * @param int      $areaId
     * @param string   $name     field name
     * @param string   $toType   new type
     * @param callable $convert  fn(string $value): int|float|string
     * @return int               number of adjusted working copies
     */
    public function retypeWorkingCopies(int $areaId, string $name, string $toType, callable $convert): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 1", 'i', [$areaId]);
            $tmpIds = array_map('intval', $this->db->fetchAll());
            $count = 0;
            foreach ($tmpIds as $tmpId) {
                [$schemaVirtual, $langCtx] = $this->_tmpContext($tmpId);
                if (empty($langCtx)) continue;
                $tmpData = $this->_tmpDataFromRecordCache($tmpId, $schemaVirtual, $langCtx, rebuild: false);
                if (!isset($tmpData[$name])) continue;
                $primaryId = (int)($langCtx['primary_id'] ?? 0);
                $one = fn($value) => $this->_isEmptyInput($value) ? '' : $convert((string)$value);
                $raw = $tmpData[$name][1] ?? '';
                $multilingual = !empty($schemaVirtual[$name]['multilingual'])
                    && in_array($toType, $this->cms->multilingualTypes, true);
                if (is_array($raw)) {
                    $value = $multilingual ? array_map($one, $raw) : $one($raw[$primaryId] ?? '');
                }
                else {
                    $value = $multilingual ? [$primaryId => $one($raw)] : $one($raw);
                }
                $tmpData[$name] = [$toType, $value];
                $this->_saveTmpData($tmpId, $tmpData);
                $count++;
            }
            return $count;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Carry over the working state of the working copies after switching a file field between
     * single- and multilingual (Field::migrateFileLanguage()) - directly in the
     * `record_cache` rows, independent of the schema:
     *
     *     -> multilingual   the entry becomes the file of the primary language; the other
     *                       languages receive it as fallback; a newly uploaded
     *                       file ('temporary') is then called {tmpId}_{primary language}
     *     -> single-language  the entry of the primary language applies to all languages (without
     *                       file_language_id); new files of other languages are
     *                       deleted, that of the primary language is then called {tmpId}
     *
     * @param int  $fieldId
     * @param bool $toMultilingual
     */
    public function migrateWorkingCopyFiles(int $fieldId, bool $toMultilingual): void
    {
        $mediaDir = $this->config->get('paths.media_dir');
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `name`, `area_id` FROM `field` WHERE `field_id` = ?", 'i', [$fieldId]);
            $field = $this->db->fetch();
            if (!$field) return;
            $name = (string)$field['name'];
            $this->db->query("SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 1", 'i', [(int)$field['area_id']]);
            foreach (array_map('intval', $this->db->fetchAll()) as $tmpId) {
                $rows = $this->_loadRecordCacheRows($tmpId);
                if (empty($rows)) continue;
                $languageIds = $this->recordLanguageIds($tmpId);
                $primaryId = $languageIds[0];
                $primaryEntry = $rows[$primaryId][$name] ?? null;
                if (!is_array($primaryEntry) || ($primaryEntry[0] ?? '') !== 'file') continue;
                $fileData = (array)($primaryEntry[1] ?? []);
                $extension = (string)($fileData['extension'] ?? '');
                $path = fn(?int $lang, string $ext) => "{$mediaDir}/" . $this->cms->mediaPath($fieldId, $tmpId, $lang, $ext);

                if ($toMultilingual) {
                    if (!empty($fileData['temporary']) && $extension !== '' && is_file($path(null, $extension))) {
                        @rename($path(null, $extension), $path($primaryId, $extension));
                    }
                    $fileData['file_language_id'] = $primaryId;
                    foreach ($rows as $lang => $cache) {
                        $rows[$lang][$name] = ($lang === $primaryId) ? ['file', $fileData] : ['file', $fileData, 1];
                    }
                }
                else {
                    // new files of other languages are dropped
                    foreach ($rows as $lang => $cache) {
                        $entry = (array)($cache[$name][1] ?? []);
                        $ext = (string)($entry['extension'] ?? '');
                        if ($lang !== $primaryId && !empty($entry['temporary']) && $ext !== ''
                            && $this->cms->fileLanguageId($entry) === $lang) {
                            @unlink($path($lang, $ext));
                        }
                    }
                    if (!empty($fileData['temporary']) && $extension !== '' && is_file($path($primaryId, $extension))) {
                        @rename($path($primaryId, $extension), $path(null, $extension));
                    }
                    $fileData['file_language_id'] = null;
                    foreach ($rows as $lang => $cache) {
                        $rows[$lang][$name] = ['file', $fileData];
                    }
                }
                foreach ($rows as $lang => $cache) {
                    $this->db->query(
                        "REPLACE INTO `record_cache` (`record_id`, `language_id`, `cache`) VALUES (?, ?, ?)",
                        'iis', [$tmpId, (int)$lang, (string)json_encode($cache, JSON_UNESCAPED_UNICODE)]
                    );
                }
            }
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Schema and languages of a working copy (from its own `record` row:
     * area_id - already the target area when moved).
     *
     * @return array  [schemaVirtual, langCtx]; [[], []] if the record does not exist
     */
    private function _tmpContext(int $recordId): array
    {
        $this->db->pushResult();
        $this->db->query('SELECT `area_id` FROM `record` WHERE `record_id` = ?', 'i', [$recordId]);
        $areaId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        if ($areaId < 1) return [[], []];
        return [$this->virtualSchema($areaId, false), $this->languageContext($areaId)];
    }

    /**
     * Languages of the instance of a record (primary language first).
     *
     * @param int $recordId
     * @return int[]  language_ids; at least one entry
     */
    protected function recordLanguageIds(int $recordId): array
    {
        $this->db->pushResult();
        $this->db->query('SELECT `instance_id` FROM `record` WHERE `record_id` = ?', 'i', [$recordId]);
        $instanceId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        $instanceCache = $this->container->get(InstanceCache::class);
        $ids = $instanceCache->languageIds($instanceId);
        return $ids ?: [$instanceCache->primaryLanguageId($instanceId)];
    }

    /**
     * Bring the live state of a record from `record_cache` into working-state format -
     * the basis of every working copy (TemporaryTrait::checkOut()).
     *
     * The cache carries the values per language WITH fallback; the third element 1 on
     * an entry says that the value comes from the primary language. From this the
     * input is recovered here: for multilingual fields an array language_id =>
     * value, fallbacks as empty input. json values are decoded, encrypted
     * contents decrypted (base64-encoded ciphertext in the cache); passwords
     * are not in the cache (empty).
     *
     * If a language row is missing, the cache is rebuilt from the
     * data_* rows via Cms::createRecordCache() - only for live records ($rebuild); the
     * working state of a working copy (_loadTmpData()) has no other source.
     *
     * @param int   $recordId       record (live or working copy)
     * @param array $schemaVirtual  schema of the area (multilingual per field)
     * @param array $langCtx        languages of the instance (Dataset::languageContext())
     * @param bool  $rebuild        rebuild missing language rows from data_*
     * @return array                working-state format
     */
    protected function _tmpDataFromRecordCache(int $recordId, array $schemaVirtual, array $langCtx, bool $rebuild = true): array
    {
        $languageIds = (array)($langCtx['language_ids'] ?? []);
        if (empty($languageIds)) return [];
        $primaryId = (int)($langCtx['primary_id'] ?? $languageIds[0]);

        // load all language rows - rebuild the missing ones
        $rows = $this->_loadRecordCacheRows($recordId);
        foreach ($languageIds as $lang) {
            if (isset($rows[$lang]) || !$rebuild) continue;
            $this->db->pushResult();
            $this->db->query('SELECT `area_id`, `instance_id` FROM `record` WHERE `record_id` = ?', 'i', [$recordId]);
            $row = $this->db->fetch() ?? [];
            $this->db->popResult();
            if (empty($row)) return [];
            $this->cms->createRecordCache($recordId, (int)$row['area_id'], (int)$row['instance_id'], (int)$lang);
            $rows = $this->_loadRecordCacheRows($recordId);
            break;
        }

        $primaryCache = $rows[$primaryId] ?? (reset($rows) ?: []);
        $tmpData = [];
        foreach ($primaryCache as $name => $entry) {
            $type = (string)($entry[0] ?? 'text');
            $isMultilingual = !empty($schemaVirtual[$name]['multilingual'])
                && in_array($type, $this->cms->multilingualTypes, true);
            if ($type === 'encrypted') {
                // ciphertext (base64) -> plain text; fallbacks as empty input
                $decrypt = fn($e) => $this->crypt->decrypt((string)($e[1] ?? ''), '', false);
                if ($isMultilingual) {
                    $values = [];
                    foreach ($languageIds as $lang) {
                        $langEntry = $rows[$lang][$name] ?? null;
                        $values[$lang] = ($langEntry === null || !empty($langEntry[2])) ? '' : $decrypt($langEntry);
                    }
                    $tmpData[$name] = [$type, $values];
                }
                else {
                    $tmpData[$name] = [$type, $decrypt($entry)];
                }
                continue;
            }
            if ($type === 'file' && $this->cms->isMultilingualField((array)($schemaVirtual[$name] ?? []))) {
                // multilingual file field: only the languages with THEIR OWN file
                // (fallback entries point to the file of another language)
                $files = [];
                foreach ($languageIds as $lang) {
                    $langEntry = $rows[$lang][$name] ?? null;
                    if ($langEntry === null || !empty($langEntry[2])) continue;
                    $fileData = (array)($langEntry[1] ?? []);
                    if ($this->cms->fileLanguageId($fileData) !== (int)$lang) continue;
                    $files[(int)$lang] = $fileData;
                }
                $tmpData[$name] = [$type, $files];
                continue;
            }
            if ($isMultilingual) {
                $values = [];
                foreach ($languageIds as $lang) {
                    $langEntry = $rows[$lang][$name] ?? null;
                    if ($langEntry === null || !empty($langEntry[2])) {
                        // missing or fallback -> empty input
                        $values[$lang] = ($type === 'json') ? [] : '';
                        continue;
                    }
                    $values[$lang] = ($type === 'json')
                        ? (json_decode((string)($langEntry[1] ?? ''), true) ?: [])
                        : ($langEntry[1] ?? '');
                }
                $tmpData[$name] = [$type, $values];
                continue;
            }
            $value = $entry[1] ?? '';
            if ($type === 'json') $value = json_decode((string)$value, true) ?: [];
            $tmpData[$name] = [$type, $value];
        }
        return $tmpData;
    }

    /**
     * Load all `record_cache` rows of a record.
     *
     * @return array  language_id => decoded cache
     */
    protected function _loadRecordCacheRows(int $recordId): array
    {
        $rows = [];
        $this->db->pushResult();
        $this->db->query('SELECT `language_id`, `cache` FROM `record_cache` WHERE `record_id` = ?', 'i', [$recordId]);
        while ($row = $this->db->fetch()) {
            $rows[(int)$row['language_id']] = json_decode((string)$row['cache'], true) ?: [];
        }
        $this->db->popResult();
        return $rows;
    }

    /**
     * Derive the content cache per language from the working state (working-state format) and
     * write it to `record_cache` - the counterpart to _tmpDataFromRecordCache().
     *
     *     single-language [type, value] in every language
     *     multilingual    [type, value] with the value of the language; if the input is empty,
     *                     [type, value of the primary language, 1]
     *     json            as JSON string (this is how Cms::fetch() reads it)
     *     file            unchanged in every language
     *
     * @param int   $recordId
     * @param array $tmpData         working state
     * @param array $schemaVirtual   schema of the area (multilingual per field)
     * @param array $langCtx         languages of the instance
     * @param array $extraEntries    language_id => [name => entry] - finished entries
     *                               that overlay the derived ones (encrypted fields
     *                               on the final save: ciphertext of the data_enc row)
     * @param int[]|null $onlyLanguages  only replace these language rows (null = all)
     * @param bool $keepPasswordHash  working copy: keep the password hash of the working state
     *                               (securePasswords()); never for the live record
     */
    protected function _writeRecordCache(int $recordId, array $tmpData, array $schemaVirtual, array $langCtx, array $extraEntries = [], ?array $onlyLanguages = null, bool $keepPasswordHash = false): void
    {
        $languageIds = (array)($langCtx['language_ids'] ?? []);
        if (empty($languageIds)) $languageIds = $this->recordLanguageIds($recordId);
        $primaryId = (int)($langCtx['primary_id'] ?? $languageIds[0]);
        $writeIds = ($onlyLanguages === null)
            ? $languageIds
            : array_values(array_intersect(array_map('intval', $languageIds), array_map('intval', $onlyLanguages)));

        if ($onlyLanguages === null) {
            $this->db->query("DELETE FROM `record_cache` WHERE `record_id` = ?", 'i', [$recordId]);
        }
        foreach ($writeIds as $lang) {
            $cache = [];
            foreach ($tmpData as $name => $entry) {
                $type = (string)($entry[0] ?? 'text');
                $raw  = $entry[1] ?? '';
                if (in_array($type, $this->cms->relationTypes, true)) continue;
                if ($type === 'file') {
                    if (is_array($raw) && $this->cms->isLanguageKeyedFiles($raw)) {
                        // multilingual: the language's own file, otherwise that of the
                        // primary language (fallback, marker 1), otherwise empty
                        if (isset($raw[$lang])) {
                            $cache[$name] = [$type, $raw[$lang]];
                        }
                        elseif (isset($raw[$primaryId])) {
                            $cache[$name] = [$type, $raw[$primaryId], 1];
                        }
                        else {
                            $cache[$name] = [$type, ['extension' => '', 'mime_type' => '', 'filename' => '',
                                'size' => 0, 'width' => 0, 'height' => 0, 'file_language_id' => (int)$lang]];
                        }
                        continue;
                    }
                    $cache[$name] = [$type, $raw];
                    continue;
                }
                // Multilingual: the value is an array language_id => input. For json
                // the value itself is also an array - there the indexing decides.
                $isMultilingual = !empty($schemaVirtual[$name]['multilingual'])
                    && in_array($type, $this->cms->multilingualTypes, true)
                    && is_array($raw)
                    && ($type !== 'json' || $this->_isLanguageKeyedArray($raw, $languageIds));
                $fallback = false;
                if ($isMultilingual) {
                    $value = $raw[$lang] ?? ($type === 'json' ? [] : '');
                    if ($lang !== $primaryId && $this->_isEmptyInput($value)) {
                        $value = $raw[$primaryId] ?? ($type === 'json' ? [] : '');
                        $fallback = true;
                    }
                }
                else {
                    $value = $raw;
                }
                if ($type === 'json') {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    if ($value == '""') $value = '';
                }
                elseif ($type === 'encrypted') {
                    // plain text of the working state -> ciphertext, base64 (JSON-capable)
                    $value = base64_encode($this->crypt->encrypt((string)$value, '', true));
                }
                elseif ($type === 'password' || ($schemaVirtual[$name]['display_type'] ?? '') === 'password') {
                    // passwords do not belong in the live cache (see
                    // Cms::createRecordCache()); in the working state of a working copy
                    // the hash may remain - it is the same value as in data_pwd
                    if (!$keepPasswordHash) $value = '';
                }
                $cache[$name] = $fallback ? [$type, $value, 1] : [$type, $value];
            }
            foreach ((array)($extraEntries[$lang] ?? []) as $name => $entry) {
                $cache[$name] = $entry;
            }
            $jsonData = (string)json_encode($cache, JSON_UNESCAPED_UNICODE);
            $this->db->query(
                "REPLACE INTO `record_cache` (`record_id`, `language_id`, `cache`) VALUES (?, ?, ?)",
                'iis', [$recordId, (int)$lang, $jsonData]
            );
        }
    }

    /**
     * Is an array indexed by languages (language_id => value)?
     */
    private function _isLanguageKeyedArray(array $values, array $languageIds): bool
    {
        if (empty($values)) return false;
        foreach (array_keys($values) as $key) {
            if (!is_numeric($key) || !in_array((int)$key, array_map('intval', $languageIds), true)) return false;
        }
        return true;
    }

    /**
     * Helper: determine the previously stored extension of a `data_fil` entry
     * (the primary key is language_id + record_id + fieldname_id; the row of the
     * file with file_language_id is searched for - NULL for single-language fields)
     *
     * @param int      $recordId        record_id of the entry (live record;
     *                                  working copies have no `data_fil` entries,
     *                                  their extension is in the working state)
     * @param int      $fieldNameId     fieldname_id of the entry
     * @param int|null $fileLanguageId  language of the file (null: single-language)
     * @return string                   extension, or '' if there is no entry / no file
     */
    protected function _getStoredExtension(int $recordId, int $fieldNameId, ?int $fileLanguageId = null): string
    {
        $this->db->pushResult();
        $sql = 'SELECT `extension` FROM `data_fil` WHERE `record_id` = ? AND `fieldname_id` = ?'
             . ' AND `file_language_id` <=> ?';
        $this->db->query($sql, 'iii', [$recordId, $fieldNameId, $fileLanguageId]);
        $extension = ($this->db->num_rows > 0) ? (string)$this->db->fetchNum()[0] : '';
        $this->db->popResult();
        return $extension;
    }

    /**
     * Recursively processes thumbnails for a file field.
     * Applied to the temporary working copy of a record, but via
     * promoteBackgroundThumbnails() also to a live record after a background process has finished.
     *
     * @param int $storageId         id under which the files are stored physically
     *                               (during the save operation: id of the temporary working copy,
     *                               cf. _saveUploadField() / promoteTmpFiles())
     * @param int $parentFieldId     id of the source field (upload/thumbnail)
     * @param string $parentPath     relative path to the source file incl. extension, from paths.media_dir
     * @param array $parentData      data of the source file
     * @param array &$schemaVirtual  reference to the virtual schema
     * @param bool $forceBgproc      true: forces the thumbnail to be generated as a background process
     *                               (forced if the parent thumbnail was already generated in the background)
     * @param int|null $languageId   language of the source file for multilingual fields:
     *                               the thumbnails are created for the same language
     *                               (file name with language, file entry per language)
     * @return ?array                possibly an array with info about the source file ('width', 'height', 'data')
     */
    public function writeThumbnails(int $storageId, int $parentFieldId, string $parentPath, array $parentData, array &$schemaVirtual, bool $forceBgproc = false, ?int $languageId = null): ?array
    {
        $parentMime = (string)($parentData['mime_type'] ?? '');
        $parentExtension = (string)($parentData['extension'] ?? '');

        // info about the source file that a processor may supply (return value)
        $srcInfo = null;

        foreach ($schemaVirtual as $name => $colDef) {
            // search for all thumbnail fields that are based on $parentFieldId
            $type = (string)($colDef['type'] ?? '');
            $displayType = (string)($colDef['display_type'] ?? '');
            $fieldId = (int)($colDef['field_id'] ?? 0);
            // prevent endless recursion through mutual assignment via parent_field_id:
            // each thumbnail field is processed only once per save operation (and language)
            $processedKey = $fieldId . ':' . ($languageId ?? '');
            if ($type !== 'file'
                || $displayType !== 'thumbnail'
                || (int)($colDef['parent_field_id'] ?? 0) !== $parentFieldId
                || in_array($processedKey, $this->processedParentFieldIds, true)
            ) {
                continue;
            }
            $this->processedParentFieldIds[] = $processedKey;

            $label = $colDef['label'] ?? $name;

            $fileData = [
                'width'     => 0,
                'height'    => 0,
                'mime_type' => '',
                'size'      => 0,
                'filename'  => '',
                'extension' => ''
            ];
            $filePath = '';

            // Process the media converters assigned to the thumbnail field
            // (`rel_field_converter`, in the order stored there):
            // take the first one that matches the media type AND whose processor can
            // handle the type (MediaProcessor::supports(); if this only turns out in
            // convert(), the processor reports 'unsupported'). Example: GD without
            // WebP support, followed by ImageMagick - ImageMagick takes the WebP.
            // If NO converter can process the file, that is an error (below).
            $converterIds = $this->getConverterIds($fieldId);
            $converterFound = false;      // a converter has taken over the file
            $unsupportedErrors = [];      // snippet ids of the skipped converters (for the message)
            foreach ($converterIds as $converterId) {
                // load the converter's data
                $converter = $this->loadConverter($converterId);
                // MIME types or extensions that the converter should / should not apply to
                // e.g. image/p-jpeg, or audio/* or .pdf (dot before extension)
                $mediaTypeIncl = !empty($converter['media_types_incl'])
                    ? explode("\n", (string)$converter['media_types_incl']) : [];
                $mediaTypeExcl = !empty($converter['media_types_excl'])
                    ? explode("\n", (string)$converter['media_types_excl']) : [];

                // if no match, check the next converter...
                // (empty incl list = converter accepts everything, excl list takes precedence)
                $matched = empty($mediaTypeIncl)
                    || $this->_matchMediaType($parentMime, $parentExtension, $mediaTypeIncl);
                if ($matched && !empty($mediaTypeExcl)
                    && $this->_matchMediaType($parentMime, $parentExtension, $mediaTypeExcl)
                ) {
                    $matched = false;
                }
                if (!$matched) { continue; }

                // on a match:
                $fileFolder = $this->cms->mediaFolder($fieldId);
                // the thumbnail is likewise stored under the storage id (working copy),
                // for multilingual fields with the language of the source file in the name
                $fileBaseName = $this->cms->mediaBaseName($storageId, $languageId);

                // file extension of the generated file:
                // if no extension is explicitly given, then that of the source file
                $parentExtension = pathinfo($parentPath, PATHINFO_EXTENSION);
                $fileExtension = $converter['extension'] ?: $parentExtension;
                // load the processor class
                $this->processor = $this->factory->create($converter['processor']);
                // processor does not support the source or target format -> next converter
                if (!$this->processor->supports($parentMime, $parentExtension, $fileExtension)) {
                    $unsupportedErrors[] = 'proc.' . strtolower($converter['processor']) . '.error_file_not_supported';
                    continue;
                }
                // target file
                $filePath = "{$fileFolder}/{$fileBaseName}.{$fileExtension}";
                // if the directory does not exist yet, create it now
                $this->system->createDir($this->config->get('paths.media_dir'), $fileFolder);
                // convert the file
                $converterDetails = json_decode((string)($converter['details'] ?? ''), true) ?? [];
                $converterDetails['extension'] = $fileExtension;
                $fileData = $this->processor->convert(
                    $parentPath, $parentData, $filePath, $converterDetails, $forceBgproc
                );
                // only found out while reading that the format does not work -> next converter
                if (!empty($fileData['unsupported'])) {
                    $unsupportedErrors[] = (string)($fileData['errors'] ?? 'error_file_conversion');
                    $filePath = '';
                    continue;
                }
                $converterFound = true;
                /**
                 * On success the processor returns the following data:
                 *     'width',
                 *     'height',
                 *     'mime_type',
                 *     'size',
                 *     'filename',
                 *     'extension',
                 *     'data',          (finished JSON string)
                 * optionally additionally info about the SOURCE file:
                 *     'source_width',
                 *     'source_height',
                 *     'source_data'    (finished JSON string)
                 * Background process started?
                 *     'token'          token of the background process that is currently creating the file
                 * in case of error:
                 * all values empty/0 and:
                 *     'errors'         snippet ID
                 *     'errors_defails' "additional error message, e.g. from the CLI"
                 */
                if ((int)$fileData['size'] === 0) {
                    if (!empty($fileData['token'])) {
                        // background process running
                        // allow saving, even if size and dimensions are 0.
                    }
                    else {
                        // file empty AND no background process created -> error
                        if (empty($fileData['errors'])) $fileData['errors'] = 'error_file_conversion';
                        $this->createFieldError($fileData['errors'], $name, $label);
                        if (!empty($fileData['error_details'])) {
                            $this->cms->error($fileData['error_details'], $name, $label);
                        }
                        throw new \Exception('#' . $this->cms->errorMsgLast());
                    }
                }
                // only one file can be created per thumbnail field
                break;
            }

            // converter assigned, but none can process this file type
            // (media type matches nowhere or all processors have rejected it) -> error.
            // Without an assigned converter the field simply remains empty.
            if (!$converterFound && !empty($converterIds)) {
                $fileData = [
                    'width' => 0, 'height' => 0, 'mime_type' => '',
                    'size' => 0, 'filename' => '', 'extension' => ''
                ];
                $this->createFieldError('error_file_no_converter', $name, $label, $parentMime ?: $parentExtension);
                // reasons of the skipped converters (without duplicates) as an addition
                foreach (array_unique($unsupportedErrors) as $snippetId) {
                    $this->cms->error($this->cms->snippet($snippetId), $name, $label);
                }
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }

            // take over info about the source file
            // (from the first converter that supplies it; order: width, height, data)
            if ($srcInfo === null
                && (!empty($fileData['source_width'])
                    || !empty($fileData['source_height'])
                    || !empty($fileData['source_data']))
            ) {
                $srcInfo = [
                    'width'  => (int)($fileData['source_width'] ?? 0),
                    'height' => (int)($fileData['source_height'] ?? 0),
                    'data'   => (string)($fileData['source_data'] ?? '')
                ];
            }
            // delete an existing old thumbnail file with a different extension
            // (depending on the converter that applies, the target extension may change;
            //  the previous extension is in the 'temporary' cache entry of the
            //  working copy. Entries without the flag describe the live file
            //  and are only replaced at checkIn in promoteTmpFiles())
            $previousEntry = $this->fileEntry((array)($this->recordCache[$name] ?? []), $languageId);
            if (!empty($previousEntry['temporary'])) {
                $previousExtension = (string)($previousEntry['extension'] ?? '');
                if ($previousExtension !== '' && $previousExtension !== (string)$fileData['extension']) {
                    $mediaDir = $this->config->get('paths.media_dir');
                    @unlink("{$mediaDir}/" . $this->cms->mediaPath($fieldId, $storageId, $languageId, $previousExtension));
                }
            }
            // take the values into the cache, even if no converter matched
            // ('temporary': the `data_fil` entry is only created at checkIn
            //  in promoteTmpFiles() from this metadata;
            //  empty extension = thumbnail field without an effective converter, no file)
            unset($fileData['errors'], $fileData['source_width'], $fileData['source_height'], $fileData['source_data']);

            $fileData['field_id']  = $fieldId;
            // also write fieldname_id: promoteTmpFiles() resolves it itself via the
            // cache key, but promoteBackgroundThumbnails() creates its
            // `data_fil` entries directly from this metadata
            $fileData['fieldname_id'] = (int)($this->cms->resolveVirtualField($name)['fieldname_id'] ?? 0);
            $fileData['data'] = (string)($fileData['data'] ?? '{}');
            $fileData['file_language_id'] = $languageId;
            $fileData['temporary'] = true;
            $this->setFileEntry($this->recordCache, $name, $languageId, $fileData);

            // recursion: generate thumbnails that are based on this thumbnail
            // With a background process: nested thumbnails are only recorded once
            // the background process has finished - see promoteBackgroundThumbnails()
            if ((int)$fileData['size'] > 0 && $filePath !== '' && empty($fileData['token'])) {
                $this->writeThumbnails($storageId, $fieldId, $filePath, $fileData, $schemaVirtual, false, $languageId);
                // (return value no longer considered, because info on nested thumbnails is known immediately)
            }
        }
        // return value
        return $srcInfo;
    }

    /**
     * Does an upload field allow files of this type? Media type groups
     * (details.media_types, without the exceptions of the group), media_types_incl,
     * media_types_excl - like the input check in _saveUploadField(). Extensions that
     * are never stored (forbiddenExtensions()) are checked by the caller.
     *
     * @param array  $colDef     field definition (virtual schema)
     * @param string $fileMime   recognized MIME type
     * @param string $fileExtension  extension, lowercase, without dot
     */
    public function fileTypeAllowed(array $colDef, string $fileMime, string $fileExtension): bool
    {
        $fieldDetails = json_decode((string)($colDef['details'] ?? ''), true) ?? [];
        // check the MIME types or file extensions of the uploaded file
        // 1. media type groups (see $mediaTypeGroups), several possible
        //    (without the exceptions of the group, see $mediaTypeGroupsExcl)
        $mediaTypeGroup = (array)($fieldDetails['media_types'] ?? []);
        $hasPatterns = false;
        $allowed = false;
        foreach ($mediaTypeGroup as $group) {
            $groupPatterns = $this->mediaTypeGroups[(string)$group] ?? [];
            if (empty($groupPatterns)) continue;
            $hasPatterns = true;
            if ($this->_matchMediaType($fileMime, $fileExtension, $groupPatterns)
                && !$this->_matchMediaType($fileMime, $fileExtension, $this->mediaTypeGroupsExcl[(string)$group] ?? [])
            ) {
                $allowed = true;
            }
        }
        // 2. explicitly specified MIME types or extensions that may match
        // e.g. image/p-jpeg, or audio/* or .pdf (dot before extension)
        // (case-insensitive)
        $mediaTypeIncl = !empty($fieldDetails['media_types_incl'])
            ? array_filter(array_map('trim', explode("\n", (string)$fieldDetails['media_types_incl']))) : [];
        if (!empty($mediaTypeIncl)) {
            $hasPatterns = true;
            if ($this->_matchMediaType($fileMime, $fileExtension, $mediaTypeIncl)) $allowed = true;
        }
        // neither groups nor incl list specified -> all types allowed (except the excl list)
        if (!$hasPatterns) $allowed = true;
        // 3. explicitly specified MIME types or extensions that must NOT match
        $mediaTypeExcl = !empty($fieldDetails['media_types_excl'])
            ? explode("\n", (string)$fieldDetails['media_types_excl']) : [];
        if ($allowed && !empty($mediaTypeExcl)
            && $this->_matchMediaType($fileMime, $fileExtension, $mediaTypeExcl)
        ) {
            $allowed = false;
        }
        return $allowed;
    }

    /**
     * Extensions that are never stored for security reasons.
     * @return string[]
     */
    public function forbiddenExtensions(): array
    {
        return $this->forbiddenExtensions;
    }

    /**
     * Does the extension not match the recognized content ($mimeExtensionMap)? Such a
     * file is rejected by _saveUploadField() with error_file_mismatch.
     */
    public function mimeExtensionMismatch(string $fileMime, string $fileExtension): bool
    {
        return isset($this->mimeExtensionMap[$fileMime])
            && !in_array(strtolower($fileExtension), $this->mimeExtensionMap[$fileMime], true);
    }

    /**
     * Checks the MIME type / extension of a file against a list of patterns
     * Patterns: exact MIME type (image/jpeg), wildcard MIME (image/*), extension with dot (.pdf)
     * Case is not distinguished
     *
     * @param string $mime       MIME type of the file
     * @param string $extension  extension of the file (without dot)
     * @param array $patterns    list of patterns
     * @return bool              true on a match
     */
    protected function _matchMediaType(string $mime, string $extension, array $patterns): bool
    {
        $mime = strtolower(trim($mime));
        $extension = strtolower(ltrim(trim($extension), '.'));
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim((string)$pattern));
            if ($pattern === '') continue;
            if ($pattern[0] === '.') {
                // extension comparison
                if (ltrim($pattern, '.') === $extension) return true;
            }
            elseif (str_ends_with($pattern, '/*')) {
                // wildcard MIME, e.g. image/*
                if (str_starts_with($mime, substr($pattern, 0, -1))) return true;
            }
            elseif ($pattern === $mime) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check whether a link exists (for lookup field handling)
     * @param int $srcFieldId  id of the relation field
     * @param int $srcId       id of the source record
     * @param int $destId      id of the target record
     */
    public function checkRelation(int $srcFieldId, int $srcId, int $destId): bool
    {
        if ($srcFieldId < 1) return false;
        if ($srcId  < 1) return false;
        if ($destId  < 1) return false;
        $this->db->pushResult();
        $sql = "SELECT 1 FROM `data_rel` WHERE `field_id` = ? AND `src_id`= ? AND `dest_id` = ?";
        $this->db->query($sql, 'iii', [$srcFieldId, $srcId, $destId]);
        $linked = ($this->db->num_rows > 0);
        $this->db->popResult();
        return $linked;
    }

    /**
     * Establish a link between source and target records
     * Depending on the field type: unidirectional or bidirectional
     * * format of the ids for the target records:
     * 1. single id
     * 2. comma-separated ids
     * 3. array with ids
     *
     * AT MOST ONE TARGET (relation1 / pointer1, max = 1)
     * --------------------
     * The rule is enforced HERE - all paths (lookup, select fields in save(),
     * batch processing, import, CMS:USER_INPUT) run through this method:
     *   - own side: more than one dest_id is a field error; an existing
     *     link is also replaced with $replace = false (there is no "add to")
     *   - opposite side: if the counter field of a 'relation' field allows only one target,
     *     the target record loses its previous link there at CHECK-IN
     *     (TemporaryTrait::_enforceSingleRelations()) - "an article has exactly one
     *     category: whoever assigns it to a new one takes it out of the old one". Not already
     *     here: the link hangs on the working copy and must be discardable with it
     *     (cancel), without foreign records losing anything.
     *     Cms::select() joins …1 fields as n:1 without DISTINCT and relies
     *     on exactly one row per src_id.
     *
     * @param array  $colDef   source field properties
     * @param int    $srcId    id of the source record
     * @param array  $destIds  ids of the target records
     * @param bool   $replace  true: delete existing links in this field
     * @param bool   $checkTmp true: check whether the record srcId is temporary and belongs to the session admin
     */
    public function createRelations(array &$colDef, int $srcId, int|array|string $destIds, bool $replace = true, bool $checkTmp = true): void
    {
        $this->db->pushResult();
        if (empty($colDef)) throw new \Exception('empty colDef');
        if ($srcId < 1) throw new \Exception('invalid srcId');

        $name = $colDef['name'];
        $label = $colDef['label'] ?? $name;
        $fieldId = $colDef['field_id'] ?? 0;
        if (empty($fieldId)) throw new \Exception('empty fieldId');
        $type = $colDef['type'] ?? '';
        if (!in_array($type, $this->cms->relationTypes, true)) {
            throw new \Exception('not a relation field');
        }

        // dest_ids
        if (is_string($destIds)) {
            $destIds = explode(',', $destIds);
        } elseif (is_int($destIds)) {
            $destIds = [$destIds];
        }

        // keep only real ids and remove duplicates directly
        $destIds = array_unique(array_filter($destIds, fn($value) => (int)$value > 0 && is_numeric($value)));
        $destCount = count($destIds);
        if ($destCount === 0 && !$replace) {
            // nothing to add
            return;
        }

        // safety limit: at most 5000 records at once
        if ($destCount > 5000) {
            $this->createFieldError('record.error_too_many_relations', $name, $label);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // at most one target (see method comment): several ids are an error,
        // an existing link is always replaced
        $isSingle = in_array($type, $this->cms->singleRelationTypes, true) || (int)($colDef['max'] ?? 0) === 1;
        if ($isSingle) {
            if ($destCount > 1) {
                $this->createFieldError('record.error_relation_single', $name, $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $replace = true;
        }

        if ($checkTmp) {
            // check whether the record is temporary and belongs to the session admin

            if (!$prodData = $this->getProdData($srcId)) {
                throw new \Exception('not temporary or access denied');
            }
            $areaId = $prodData[1];

            // compare the area_id of the field with the area id of the source record
            if ((int)$colDef['area_id'] !== $areaId) {
                throw new \Exception('wrong area_id');
            }
        }

        // fieldname_id
        if ($fn = $this->cms->resolveVirtualField($name)) {
            $fieldNameId = $fn['fieldname_id'];
        }
        else {
            throw new \Exception('fieldname not resolved');
        }
        
        if (str_starts_with($type, 'relation')) {
            // -> rel_field_id + rel_area_id
            $destFieldId = (int)$colDef['rel_field_id'];
            $destAreaId = (int)$colDef['rel_area_id'];

            // the fieldname_id of the counter field is also needed
            $sql = 'SELECT `fn`.`fieldname_id` FROM `field`'
                .' LEFT JOIN `fieldname` `fn` ON `field`.`name` = `fn`.`name`'
                .' WHERE `field`.`field_id`=?';
            $this->db->query($sql, 'i', [$destFieldId]);
            if ($this->db->num_rows < 1) {
                // counter field not found
                $this->createFieldError('record.error_dest_relationfield', $name, $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $destFieldnameId = $this->db->fetchNum()[0];
        }
        else {
            // pointer, pointer1
            // -> rel_area_ids
            $destAreaIds = $this->getRelAreaIds($fieldId);
        }

        $this->db->beginTransaction();

        try {
            if ($replace) {
                // The new links replace all existing links
                // between the source record and the target area
                $sql = "DELETE FROM `data_rel` WHERE `field_id`=? AND `src_id`=?";
                $this->db->query($sql, 'ii', [$fieldId, $srcId]);
                if (str_starts_with($type, 'relation')) {
                    // observe bidirectionality
                    $sql = "DELETE FROM `data_rel` WHERE `field_id`=? AND `dest_id`=?";
                    $this->db->query($sql, 'ii', [$destFieldId, $srcId]);
                }
                // set the position value to 0
                $position = 0;
                $destPosition = 0;

            } else {
                // The new links are added to the existing ones
                // placed at the end in sequence (position value max + 1)
                // Links that already exist are ignored by the unique key
                $sql = "SELECT COALESCE(MAX(`position`) + 1, 0) FROM `data_rel` WHERE `field_id`=? AND `src_id`=?";
                $this->db->query($sql, 'ii', [$fieldId, $srcId], true);
                $position = $this->db->fetchNum()[0];
                if (str_starts_with($type, 'relation')) {
                    $sql = "SELECT COALESCE(MAX(`position`) + 1, 0) FROM `data_rel` WHERE `field_id`=? AND `dest_id`=?";
                    $this->db->query($sql, 'ii', [$destFieldId, $srcId], true);
                    $destPosition = $this->db->fetchNum()[0];
                }
            }

            if ($destCount === 0) {
                // $replace was true, but there is nothing to add
                $this->db->commit();
                return;
            }

            // determine the area_id of the records to be linked, in bulk
            $placeholders = implode(',', array_fill(0, $destCount, '?'));
            $types = str_repeat('i', $destCount);
            $sql = "SELECT `record_id`, `area_id` FROM `record` WHERE `record_id` IN ($placeholders)";
            $this->db->query($sql, $types, $destIds);
            
            $areaMap = [];
            foreach ($this->db->fetchAll() as $row) {
                $areaMap[(int)$row['record_id']] = (int)$row['area_id'];
            }

            // statement template for the insert
            $sqlInsert = "INSERT INTO `data_rel`"
                ." (`field_id`, `fieldname_id`, `src_id`, `dest_id`, `position`) "
                ." VALUES (?, ?, ?, ?, ?)";

            // process the ids
            foreach ($destIds as $destId) {
                $destId = (int)$destId;

                if (!isset($areaMap[$destId])) {
                    // record to be linked not found
                    $this->createFieldError('record.error_dest_record_not_found', $name, $label, $destId);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                $areaId = $areaMap[$destId];

                if (str_starts_with($type, 'relation')) {
                    // relation: target record in the target area?
                    if ($areaId != $destAreaId) {
                        $this->createFieldError('record.error_dest_record_area', $name, $label, $destId);
                        throw new \Exception('#' . $this->cms->errorMsgLast());
                    }
                }
                else {
                    // pointer, pointer1, link
                    // target record in one of the target areas?
                    if (!in_array($areaId, $destAreaIds, false)) {
                        // (slightly different error message than for type 'relation')
                        $this->createFieldError('record.error_dest_record_areas', $name, $label, $destId);
                        throw new \Exception('#' . $this->cms->errorMsgLast());
                    }
                    // link: the target record belongs to at most ONE source record -
                    // if it already belongs to another one (not to the original of the working copy
                    // $srcId), it cannot be linked (LinkTrait::linkOwner())
                    if (in_array($type, $this->cms->dependentTypes, true) && method_exists($this, 'linkOwner')) {
                        $owner = $this->linkOwner($destId, $srcId);
                        if ($owner !== null) {
                            $this->createFieldError('record.error_link_owned', $name, $label, $destId);
                            throw new \Exception('#' . $this->cms->errorMsgLast());
                        }
                    }
                }

                // catch duplicate keys for existing entries
                // (saves a query)
                try {
                    $this->db->query($sqlInsert, 'iiiii', [$fieldId, $fieldNameId, $srcId, $destId, $position], true);
                    $position++;

                    // observe bidirectionality
                    if (str_starts_with($type, 'relation')) {
                        $this->db->query($sqlInsert, 'iiiii', [$destFieldId, (int)$destFieldnameId, $destId, $srcId, $destPosition], true);
                        $destPosition++;
                    }
                } catch (\mysqli_sql_exception $e) {
                    // check for the ANSI SQL standard for integrity constraint violation
                    if ($e->getSqlState() === '23000') {
                        continue;
                    }
                    throw $e;
                }
            }

            $this->db->commit();

        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;

        } finally {
            $this->db->popResult();
        }

    }

    /**
     * Removes link(s) in the given relation field of a temporary record
     *
     * @param array &$colDef     data of the relation field
     * @param int $srcId        id of the source record
     * @param int|null $destId  id of the target record (if not specified: all links)
     * @return bool             true on success, false on error (no messages)
     */
    public function removeRelations(array &$colDef, int $srcId, ?int $destId = null): bool
    {
        if (empty($colDef)) throw new \Exception('empty colDef');
        $name = $colDef['name'];
        $label = $colDef['label'] ?? $name;
        $fieldId = (int)($colDef['field_id'] ?? 0);
        if (empty($fieldId)) throw new \Exception('empty fieldId');
        $type = $colDef['type'] ?? '';
        if (!in_array($type, $this->cms->relationTypes, true)) {
            throw new \Exception('not a relation field');
        }

        if ($srcId  < 1) throw new \Exception('invalid srcId');
        if ($destId !== null && $destId  < 1) throw new \Exception('invalid destId');

        if (!$prodData = $this->getProdData($srcId)) {
            throw new \Exception('not temporary or access denied');
        }
        $areaId = $prodData[1];

        // compare the area_id of the field with the area id of the source record
        if ((int)$colDef['area_id'] !== $areaId) {
            throw new \Exception('wrong area_id');
        }

        $sql = "DELETE FROM `data_rel` WHERE `field_id` = ? AND `src_id`= ?";
        $sqlParams = 'ii';
        $sqlValues = [$fieldId, $srcId];
        if ($destId !== null) {
            $sql .= " AND `dest_id` = ?";
            $sqlParams .= 'i';
            $sqlValues[] = $destId;
        }
        $this->db->query($sql, $sqlParams, $sqlValues);

        // observe bidirectionality
        if (str_starts_with($type, 'relation')) {
            $relFieldId = (int)($colDef['rel_field_id'] ?? 0);
            $sql = "DELETE FROM `data_rel` WHERE `field_id` = ? AND `dest_id`= ?";
            $sqlParams = 'ii';
            $sqlValues = [$relFieldId, $srcId];
            if ($destId !== null) {
                $sql .= " AND `src_id` = ?";
                $sqlParams .= 'i';
                $sqlValues[] = $destId;
            }
            $this->db->query($sql, $sqlParams, $sqlValues);
        }

        return true;
    }

    /**
     * Sorting of records within a relation:
     * swap the position of the records with ids $destId1 and $destId2
     * The source record must be temporary and belong to the session admin
     *
     * @param array &$colDef  relation field
     * @param int $srcId      id of the source record
     * @param int $destId1    id of target record 1
     * @param int $destId2    id of target record 2
     */
    public function swapRelationPosition(array &$colDef, int $srcId, int $destId1, int $destId2): void
    {
        // colDef
        if (empty($colDef)) throw new \Exception('empty colDef');
        $fieldId = (int)($colDef['field_id'] ?? 0);
        if (empty($fieldId)) throw new \Exception('empty fieldId');
        $type = $colDef['type'] ?? '';
        if (!in_array($type, $this->cms->relationTypes, true)) {
            throw new \Exception('not a relation field');
        }

        if ($srcId  < 1) throw new \Exception('invalid srcId');
        if ($destId1  < 1) throw new \Exception('invalid destId1');
        if ($destId2  < 1) throw new \Exception('invalid destId2');

        if (!$prodData = $this->getProdData($srcId)) {
            throw new \Exception('not temporary or access denied');
        }
        $areaId = $prodData[1];

        // compare the area_id of the field with the area id of the source record
        if ((int)$colDef['area_id'] !== $areaId) {
            throw new \Exception('wrong area_id');
        }

        try {
            $this->db->beginTransaction();

            // validate the target records by checking the existing link,
            // determine its position
            $sql = "SELECT `position` FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ? FOR UPDATE";
            $this->db->query($sql, 'iii', [$fieldId, $srcId, $destId1], true);
            if ($this->db->num_rows < 1) {
                throw new \Exception('records are not linked');
            }
            $position1 = (int)$this->db->fetchNum()[0];

            // statement 2: $sql stays the same
            $this->db->query($sql, 'iii', [$fieldId, $srcId, $destId2], true);
            if ($this->db->num_rows < 1) {
                throw new \Exception('records are not linked');
            }
            $position2 = (int)$this->db->fetchNum()[0];

            // positions can be swapped crosswise within the transaction
            $sql = "UPDATE `data_rel` SET `position` = ? WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?";
            $this->db->query($sql, 'iiii', [$position2, $fieldId, $srcId, $destId1], true);

            // statement 2: $sql stays the same
            $this->db->query($sql, 'iiii', [$position1, $fieldId, $srcId, $destId2], true);

            $this->db->commit();
            
        } catch (\Exception $e) {
            $this->db->rollback();
            $this->cms->error($e->getMessage());
        }
    }

    /**
     * Refresh the content of full-text fields
     *
     * If no record id is given, the refresh of the full-text field content affects
     * all records of its area (when the choice of source fields changes).
     *
     * @param int $fulltextFieldId  id of the full-text field whose contents are to be refreshed
     * @param ?int $recordId        id of a record whose contents were changed (optional).
     */
    public function refreshFulltext(int $fulltextFieldId, ?int $recordId = null): void
    {
        // ids of the source fields (and possibly relation fields via which text field and full text are connected)
        $this->db->query("SELECT `rel_field_id`, `text_field_id` FROM `rel_fulltext_text` WHERE `fulltext_field_id`=?", 'i', [$fulltextFieldId]);
        $sources = $this->db->fetchAll();
        if (empty($sources)) {
            // no source fields
            if (!empty($recordId)) {
                // single record is saved: it may already have contained no content before
                return;
            }
            // all records: this case occurs when the last source field is removed from the full text
            if (!$fieldData = $this->fieldData($fulltextFieldId)) {
                return;
            }
            $sql = "UPDATE `data_ftx` SET `data` = '' WHERE `field_id` = ?";
            $this->db->query($sql, 'i', [$fulltextFieldId]);
            return;
        }

        if (!empty($recordId)) $this->_refreshFulltext($fulltextFieldId, $recordId, $sources);
        else {
            // regenerate the full text for each record of the content area
            if (!$fieldData = $this->fieldData($fulltextFieldId)) {
                return;
            }
            $areaId = (int)$fieldData['area_id'];
            $sql = "SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 0";
            $this->db->query($sql, 'i', [$areaId]);
            // fetch the ids in advance: _refreshFulltext() issues its own queries
            // (single-column fetchAll() returns the values directly).
            foreach ($this->db->fetchAll() as $recordId) {
                set_time_limit(300);
                $this->_refreshFulltext($fulltextFieldId, (int)$recordId, $sources);
            }
        }
    }

    /**
     * Helper function for refreshFulltext()
     *
     * The full text is built PER LANGUAGE of the instance of the record: from the
     * data_* rows of the source fields in this language (which carry the fallback of the
     * primary language for untranslated multilingual fields). This way the full-text
     * search finds in each language what is also displayed there - folded according to the
     * rules of that language (Cms::indexText(), Collation::fold()).
     */
    private function _refreshFulltext(?int $fulltextFieldId, int $recordId, array $sources, int $fieldNameId = 0): void
    {
        // $fulltextFieldId null: standard full-text field (without `field` row, $fieldNameId)
        if ($fulltextFieldId !== null && empty($fulltextFieldId)) return;
        if (empty($recordId)) return;
        if (empty($sources)) return;

        // determine the type of each source field once
        $sourceTables = [];
        foreach ($sources as $source) {
            $textFieldId = (int)$source['text_field_id'];
            $sql = "SELECT `type` FROM `field` WHERE `field_id` = ?";
            $this->db->query($sql, 'i', [$textFieldId]);
            if ($this->db->num_rows < 1) continue;
            $type = $this->db->fetchNum()[0];
            $sourceTables[] = [
                'text_field_id' => $textFieldId,
                'rel_field_id'  => (int)($source['rel_field_id'] ?? 0),
                'table'         => 'data_' . $this->cms->dataSuffix[$type],
                // upload field: its contribution is the original file name
                'column'        => $type === 'file' ? 'filename' : 'data',
            ];
        }
        if ($fulltextFieldId !== null && ($fieldData = $this->fieldData($fulltextFieldId))) {
            $fieldNameId = (int)($this->cms->resolveVirtualField((string)$fieldData['name'])['fieldname_id'] ?? 0);
        }
        if ($fieldNameId < 1) return;

        // collect and write the full text per language
        $sql = "DELETE FROM `data_ftx` WHERE `record_id` = ? AND `fieldname_id` = ?";
        $this->db->query($sql, 'ii', [$recordId, $fieldNameId]);

        foreach ($this->recordLanguageIds($recordId) as $languageId) {
            $ft = [];
            foreach ($sourceTables as $source) {
                $textFieldId = $source['text_field_id'];
                $tableName   = $source['table'];
                $column      = $source['column'];
                if (empty($source['rel_field_id'])) {
                    // case 1: no relation field specified
                    // full-text field and text field lie together in one content area
                    $sql = "SELECT `$column` FROM `$tableName`"
                         . " WHERE `record_id` = ? AND `field_id` = ? AND `language_id` = ?";
                    $this->db->query($sql, 'iii', [$recordId, $textFieldId, $languageId]);
                    if ($this->db->num_rows > 0) {
                        $ft[] = $this->cms->indexText((string)$this->db->fetchNum()[0], false, $languageId);
                    }
                }
                else {
                    // case 2: relation field specified
                    // text field lies in another area. The same language applies there;
                    // if the instance of the target does not have it, the contribution stays
                    // empty.
                    $relFieldId = $source['rel_field_id'];
                    // search for linked content from the target area (via the relation field)
                    $sql = "SELECT `t`.`$column` FROM `data_rel` `r`"
                        ." LEFT JOIN `$tableName` `t` ON `r`.`dest_id` = `t`.`record_id`"
                        ." AND `t`.`field_id` = ? AND `t`.`language_id` = ?"
                        ." WHERE `r`.`src_id` = ? AND `r`.`field_id` = ?";
                    $this->db->query($sql, 'iiii', [$textFieldId, $languageId, $recordId, $relFieldId]);
                    for ($i = 0; $i < $this->db->num_rows; $i++) {
                        $ft[] = $this->cms->indexText((string)($this->db->fetchNum()[0] ?? ''), false, $languageId);
                    }
                }
            }
            $sql = "INSERT INTO `data_ftx` SET `language_id` = ?, `data` = ?, `record_id` = ?, `field_id` = ?, `fieldname_id` = ?";
            $this->db->query($sql, 'isiii', [
                $languageId,
                implode(' ', $ft),
                $recordId,
                $fulltextFieldId,
                $fieldNameId
            ]);
        }
    }

    /**
     * Mark uploads as untrusted (frontend): files with active content
     * (MediaAccess::isActiveContent()) are then rejected regardless of the
     * field settings. RecordUser switches this on for the duration of a save and off again afterwards.
     */
    public function setUntrustedUploads(bool $untrusted): void
    {
        $this->untrustedUploads = $untrusted;
    }

    /**
     * Helper function: determine the data of a field
     */
    /**
     * Removes an uploaded file from a temporary working copy
     * (x button next to the preview link in the input form, parameter 'removefiles'
     * of CMS:USER_INPUT).
     * - deletes the file and the `data_fil` entry of the working copy
     *   (including all recursively dependent thumbnail fields)
     * - sets the flag 'deleted' in the working state of the working copy:
     *   at checkIn promoteTmpFiles() then also deletes the file that may be attached to the live
     *   record
     *
     * @param int $tmpId            id of the temporary working copy
     * @param int $fieldId          id of the upload field
     * @param int|null $languageId  language of the file for multilingual upload fields
     * @return bool                 true on success
     */
    public function markFileDeleted(int $tmpId, int $fieldId, ?int $languageId = null): bool
    {
        if (!$prodData = $this->getProdData($tmpId)) {
            $this->cms->error('_markFileDeleted() Exception: Dataset is not temporary.');
            return false;
        }
        $areaId = $prodData[1];
        // field must exist, be an upload field AND belong to the area of the working copy
        // (otherwise a foreign file could be deleted via field_id)
        if (!$fieldData = $this->fieldData($fieldId)) {
            $this->cms->error('_markFileDeleted() Exception: Field not found.');
            return false;
        }
        if ((string)($fieldData['type'] ?? '') !== 'file'
            || (string)($fieldData['display_type'] ?? '') !== 'upload'
        ) {
            $this->cms->error('_markFileDeleted() Exception: Wrong field type.');
            return false;
        }
        if ((int)$fieldData['area_id'] !== $areaId) {
            $this->cms->error('_markFileDeleted() Exception: Wrong area_id.');
            return false;
        }
        $fieldName = $fieldData['name'];
        $schemaVirtual = $this->virtualSchema($areaId, false);

        $this->db->pushResult();
        try {
            $this->db->beginTransaction();
            // load the working state (row lock against parallel changes)
            $this->db->query('SELECT `record_id` FROM `record` WHERE `record_id` = ? FOR UPDATE', 'i', [$tmpId]);
            if ($this->db->num_rows < 1) {
                throw new \Exception('removeUpload() Exception: working copy not found.');
            }
            $cache = $this->_loadTmpData($tmpId);
            // multilingual field without a language given, or single-language with one: the
            // language depends on the field
            if (!$this->cms->isMultilingualField($fieldData)) $languageId = null;
            elseif ($languageId === null) $languageId = $this->languageIdsOf($tmpId)[0] ?? 0;

            // clear the source field + recursively dependent thumbnails.
            // $handled protects against endless recursion through mutual
            // parent_field_id assignment (each field is processed only once).
            $current = $this->fileEntry((array)($cache[$fieldName] ?? []), $languageId);
            if (empty($current['extension'])) {
                // no file stored
                $this->db->commit();
                return true; // finally is called anyway
            }
            $handled = [];
            $this->_removeFileEntries($tmpId, $cache, $fieldName, $schemaVirtual, $handled, $languageId);

            // write the updated working state, refresh the edit lock
            $this->_saveTmpData($tmpId, $cache, null, touch: true);
            $this->db->commit();

        } catch (\Exception $e) {
            $this->db->rollback();
            $this->cms->error($e->getMessage());
            return false;
        } finally {
            $this->db->popResult();
        }
        return true;
    }


    /**
     * Helper for markFileDeleted(), recursive call
     *
     * @param array &$handled  field names already processed (endless-loop protection)
     */
    private function _removeFileEntries(int $tmpId, array &$cache, string $fieldName, array $schemaVirtual, array &$handled, ?int $languageId = null): void
    {
        // prevent endless recursion through mutual parent_field_id assignment
        if (in_array($fieldName, $handled, true)) return;
        $handled[] = $fieldName;

        $fieldData = $schemaVirtual[$fieldName] ?? null;
        if (empty($fieldData)) return;
        $fieldId = (int)$fieldData['field_id'];

        // entry has the format [$type, $fileData] - for multilingual
        // fields per language (VirtualFieldsTrait::fileEntry())
        $fileData = $this->fileEntry((array)($cache[$fieldName] ?? []), $languageId);
        $extension = (string)($fileData['extension'] ?? '');

        $mediaDir = $this->config->get('paths.media_dir');
        // storage scheme: {field_id}/{record_id}[_{language_id}].{extension}
        if ($extension !== '' && !empty($fileData['temporary'])
            && file_exists($tmpFile = "$mediaDir/" . $this->cms->mediaPath($fieldId, $tmpId, $languageId, $extension))
        ) {
            if (!@unlink($tmpFile)) {
                $this->system->log('file-errors', "$tmpFile : deletion failed.");
            }
        }

        // remove the `data_fil` entry of the working copy, otherwise
        // promoteTmpFiles() would rewrite it to the live record at checkIn
        // (entry without associated file -> broken preview link)
        if ($fn = $this->cms->resolveVirtualField($fieldName)) {
            $sql = 'DELETE FROM `data_fil` WHERE `record_id` = ? AND `fieldname_id` = ?';
            $this->db->query($sql, 'ii', [$tmpId, (int)$fn['fieldname_id']]);
        }

        // set the delete marker in the working state - keep the format [$type, $fileData]:
        // promoteTmpFiles() checks $entry[0] === 'file' and only then deletes the
        // file that may be attached to the live record (of this language)
        $this->setFileEntry($cache, $fieldName, $languageId, [
            'extension' => '', 'mime_type' => '', 'width' => 0,
            'height' => 0, 'size' => 0, 'filename' => '',
            'file_language_id' => $languageId,
            'deleted' => true
        ]);

        // also delete dependent thumbnails
        // (thumbnail fields have type 'file' and display_type 'thumbnail')
        foreach ($schemaVirtual as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') !== 'file') continue;
            if ((string)($colDef['display_type'] ?? '') !== 'thumbnail') continue;
            if ((int)($colDef['parent_field_id'] ?? 0) === $fieldId) {
                $this->_removeFileEntries($tmpId, $cache, $name, $schemaVirtual, $handled, $languageId);
            }
        }
    }

    public function fieldData(int $fieldId): ?array
    {
        $this->db->query("SELECT * FROM `field` WHERE `field_id`=?", 'i', [$fieldId]);
        if ($this->db->num_rows < 1) return null;
        return $this->db->fetch();
    }

    /**
     * Helper function: determine the 'rel_area_ids' of a pointer field
     */
    public function getRelAreaIds(int $fieldId): array
    {
        $sql = 'SELECT `area_id` FROM `rel_field_area` WHERE `field_id`=?';
        $this->db->query($sql, 'i', [$fieldId]);
        return $this->db->fetchAll(); // flat array, possibly []
    }

    /**
     * Helper function: 'converter_ids' of a thumbnail field in their order
     * (`rel_field_converter`, position) - thumbnail generation takes the first
     * matching one. Field::getConverterIds() returns the same.
     */
    public function getConverterIds(int $fieldId): array
    {
        $this->db->pushResult();
        $sql = 'SELECT `converter_id` FROM `rel_field_converter` WHERE `field_id`=? ORDER BY `position` ASC, `converter_id` ASC';
        $this->db->query($sql, 'i', [$fieldId]);
        $ids = array_map('intval', $this->db->fetchAll()); // flat array, possibly []
        $this->db->popResult();
        return $ids;
    }

     /**
     * Move a target record within a relation up or down
     * The record must be temporary (check-out)
     *
     * @param int $srcField     relation field id (relation/pointer)
     * @param int $srcId        record from which the links take place
     * @param int $destId       linked target record to be moved
     * @param string $function  'up' / 'down'
     * @return bool             true on success
     */
    /*
    public function moveRelationDestId(int $srcField, int $srcId, int $destId, string $function): bool 
    {
        if ($srcField < 1) return false;
        if ($srcId < 1) return false;
        if ($destId < 1) return false;
        
        // srcId
        if (!$prodData = $this->getProdData($srcId)) {
            // record is not temporary or does not belong to the session admin
            return false;
        }
        $areaId = $prodData[1];

        // srcField
        if (!$fieldData = $this->fieldData($srcField)) {
            // not found
            return false;
        }

        // compare the area_id of the field with the area id of the source record
        if ((int)$fieldData['area_id'] !== $areaId) {
            // not found
            return false;
        }

        if (!in_array($fieldData['type'], $this->cms->relationTypes, true)) {
            // not a relation field
            return false;
        }

        // destId
        // determine the current position of the record
        $sql = "SELECT `position` FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?";
        $this->db->query($sql, 'iii', [$srcField, $srcId, $destId]);
        if ($this->db->num_rows < 1) {
            // initial link not found
            return false;
        }
        $currentPos = $this->db->fetchNum()[0];

        // find the direct swap partner (next smaller or next larger position)
        // (consider only live records)
        if ($function === 'up') {
            $sign = '<';
            $order = 'DESC';
        }
        else {
            $sign = '>';
            $order = 'ASC';
        }
        $sql = "SELECT `d`.`dest_id`, `d`.`position` FROM `data_rel` `d`"
            ." LEFT JOIN `record` `r` ON `d`.`dest_id` = `r`.`record_id`"
            ." WHERE `d`.`field_id` = ? AND `d`.`src_id` = ? AND `d`.`position` $sign ?"
            ." AND `r`.`tmp`=0 AND `r`.`deleted`=0"
            ." ORDER BY `d`.`position` $order LIMIT 1";
        $this->db->query($sql, 'iii', [$srcField, $srcId, (int)$currentPos]);
        if ($this->db->num_rows < 1) {
            // swap partner not found
            return false;
        }
        [$swapDestId, $swapPos] = $this->db->fetchNum();

        // swap the positions crosswise within a transaction
        try {
            $this->db->beginTransaction();

            // set the current entry to the position of the swap partner
            $sql = "UPDATE `data_rel` SET `position` = ? WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?";
            $this->db->query($sql, 'iiii', [(int)$swapPos, $srcField, $srcId, $destId]);

            // set the swap partner to the old position of the current entry
            $sql = "UPDATE `data_rel` SET `position` = ? WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?";
            $this->db->query($sql, 'iiii', [(int)$currentPos, $srcField, $srcId, (int)$swapDestId]);

            $this->db->commit();
            
        } catch (\Exception $e) {
            $this->db->rollback();
            return false;
        }

        return true;
    }
    */

    /**
     * Moves an uploaded temporary file to the target directory.
     *
     * @param string $tmpFile        path to the temporary uploaded file (e.g. $_FILES['file']['tmp_name'])
     * @param string $baseDir        base directory
     * @param string $relativePath   relative path to the base directory incl. file name
     * @param bool   $isUploaded     true: file comes via file upload, false: file lies in the FTP upload directory
     * @return int                   file size in bytes, or 0 bytes in case of error (also for a 0-byte file)
     */
    protected function _moveUploadedFile(string $tmpFile, string $baseDir, string $relativePath, bool $isUploaded = true): ?int
    {
        // check whether the temporary file exists at all
        if ($isUploaded) {
            if (!is_uploaded_file($tmpFile)) {
                return null;
            }
        } else {
            if (!file_exists($tmpFile)) {
                return null;
            }
        }

        // clean up and normalize the paths
        $baseDir = rtrim($baseDir, '/\\');
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        // separate the relative directory path from the actual file
        $relativeDir = dirname($relativePath);

        // ensure that the directory structure exists
        if ($relativeDir !== '.' && $relativeDir !== '') {
            if (!$this->system->createDir($baseDir, $relativeDir)) {
                return null;
            }
        }
        $fullPath = $baseDir . '/' . $relativePath;

        // move the file from the temp folder to the target
        if ($isUploaded) {
            // from file upload
            if (!move_uploaded_file($tmpFile, $fullPath)) {
                return null;
            }
        }
        else {
            // from the upload directory
            if (!copy($tmpFile, $fullPath)) {
                return null;
            }
        }

        // determine the permissions
        $filePermissions = $this->config->get('permissions.file') ?? 0664;

        // set the file permissions explicitly, to bypass umask restrictions.
        chmod($fullPath, $filePermissions);

        $fileSize = @filesize($fullPath);
        return (int)$fileSize;
    }

    /**
     * Secures file inputs BEFORE the input check of the main save operation.
     * Every accepted file (upload from $_FILES or selected server file from
     * $data) is immediately checked for type and bound to the working copy
     * (physically under its id + cache entry with the 'temporary' flag) and committed in its
     * OWN transaction.
     * This way already accepted files survive a subsequent check that fails on other
     * fields (e.g. an empty required title) - the file does not have to be
     * uploaded again and appears in the re-displayed input form.
     *
     * Thumbnails are NOT generated here: the cache entries are marked with
     * 'thumbnails_pending' and only processed on the final save via
     * writePendingThumbnails().
     *
     * Double processing in the final saveVirtualFields() is excluded:
     * _saveUploadField() aborts there via $this->processedUploadFields
     * (additionally for uploads via is_uploaded_file() == false, since the file has
     * already been moved here with move_uploaded_file()).
     *
     * @param int   $tmpId           id of the temporary working copy
     * @param array &$schemaVirtual  schema with virtual fields
     * @param array $data            contents to save from Dataset::save()
     *                               (may contain $data[$name]['serverfile'])
     * @return bool                  true: all (possibly no) files ok
     *                               false: a file was rejected (MIME/size/
     *                                      forbidden extension) - message in Cms::errors
     */
    protected function secureUploads(int $tmpId, array &$schemaVirtual, array $data = []): bool
    {
        if ($tmpId < 1) return true;
        // start afresh for each save operation: the list only prevents double
        // processing WITHIN one save() (here and once more in
        // saveVirtualFields()). Without the restart, for the second record of a
        // run (import, batch processing) every file would remain whose field the first
        // record has already processed.
        $this->processedUploadFields = [];
        $this->db->pushResult();
        try {
            $this->db->beginTransaction();
            // working state of the working copy as the basis (updated by _saveUploadField)
            $this->recordCache = $this->_loadTmpData($tmpId);
            $languageIds = $this->recordLanguageIds($tmpId);
            $processed = false;
            foreach ($schemaVirtual as $name => $colDef) {
                if ((string)($colDef['type'] ?? '') !== 'file') continue;
                if ((string)($colDef['display_type'] ?? '') !== 'upload') continue;
                // binds the file to $tmpId (physically + working state with the 'temporary' and
                // 'thumbnails_pending' flags; no `data_fil` entry, see
                // promoteTmpFiles()), throws on rejection;
                // returns false if no (new) file is specified
                foreach ($this->_uploadLanguages($colDef, $languageIds) as $lang) {
                    if ($this->_saveUploadField($tmpId, $name, $colDef, $schemaVirtual, $data, $lang)) {
                        $processed = true;
                    }
                }
            }
            if ($processed) {
                // write the adjusted working state back, refresh the edit lock
                $this->_saveTmpData($tmpId, $this->recordCache, null, touch: true);
            }
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            $msg = $e->getMessage();
            if (!str_starts_with($msg, '#')) $this->cms->error($msg);
            return false;
        } finally {
            $this->db->popResult();
        }
    }

    /**
     * Secure password inputs in the working copy BEFORE the input check -
     * the counterpart to secureUploads() for password fields.
     *
     * A password field is never pre-filled in the form: if the check fails on
     * another field or the editor switches tabs, the input would be lost. It therefore
     * moves here - hashed, as it will later be stored in `data_pwd` -
     * into the working state of the working copy (own transaction, persists
     * even if the check fails). Dataset::save() takes the hash
     * as input if the field remains empty next time; the form then shows
     * the note "Password is set" instead of a required field.
     *
     * The raw input in $data is still checked (minimum length etc.).
     *
     * @param int   $tmpId          working copy
     * @param array $schemaVirtual  schema of the area
     * @param array $data           inputs (raw input of the password fields)
     */
    protected function securePasswords(int $tmpId, array $schemaVirtual, array $data): void
    {
        if ($tmpId < 1) return;
        $hashes = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if (($colDef['type'] ?? '') !== 'password' && ($colDef['display_type'] ?? '') !== 'password') continue;
            $value = $data[$name] ?? '';
            if (is_array($value) || trim((string)$value) === '') continue;
            // a value from an export file is already the hash (Record::importSave())
            $hashes[$name] = in_array($name, $this->prehashedFields, true)
                ? (string)$value
                : $this->crypt->passwordHash((string)$value);
        }
        if (empty($hashes)) return;

        $this->db->pushResult();
        try {
            $this->db->beginTransaction();
            $cache = $this->_loadTmpData($tmpId);
            foreach ($hashes as $name => $hash) {
                $cache[$name] = ['password', $hash];
            }
            $this->_saveTmpData($tmpId, $cache, null, touch: true);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
        } finally {
            $this->db->popResult();
        }
    }

    /**
     * Mandatory check of an upload field (flag 'not_empty' or min >= 1).
     *
     * The counting in Dataset::validateValue() does not apply to files: the value of an upload
     * field is not in $data but - after secureUploads() - in the
     * working state of the working copy; when saving without a working copy in
     * `data_fil` or in the request. A file counts as present if
     *     1. the working state has a non-deleted entry with a file extension, or
     *     2. the request carries a file (upload or 'serverfile'), or
     *     3. `data_fil` of the live record knows a file.
     * 2. and 3. only without a working copy - with a working copy secureUploads() has
     * already taken the request into the working state.
     *
     * For multilingual upload fields only the primary language of the instance counts:
     * a further language without its own file gets the fallback (just as the
     * input form there shows no asterisk). Thumbnail fields are
     * filled automatically and skipped.
     *
     * @param string $name      field name
     * @param array  $colDef    field definition from virtualSchema()
     * @param array  $data      contents to save (possibly $data[$name]['serverfile'])
     * @param array  $tmpCache  working state of the working copy ([] without a working copy)
     * @param array  $langCtx   languages of the instance (Dataset::languageContext())
     * @param int    $recordId  live record when saving WITHOUT a working copy,
     *                          otherwise 0
     * @return bool  true: ok; false: error (message in Cms::errors)
     * @see Camindo\Core\Dataset::save()
     */
    protected function validateFileRequired(string $name, array $colDef, array $data, array $tmpCache, array $langCtx, int $recordId = 0): bool
    {
        if ((string)($colDef['display_type'] ?? '') !== 'upload') return true;
        $flags = (array)($colDef['flags'] ?? []);
        if (!in_array('not_empty', $flags, true) && (int)($colDef['min'] ?? 0) < 1) return true;

        $isMultilingual = $this->cms->isMultilingualField($colDef) && !empty($langCtx['language_ids']);
        $languageId = $isMultilingual ? (int)($langCtx['primary_id'] ?? 0) : null;

        // 1. working state of the working copy
        if (isset($tmpCache[$name])) {
            $entry = $this->fileEntry((array)$tmpCache[$name], $languageId);
            if (!empty($entry) && empty($entry['deleted']) && (string)($entry['extension'] ?? '') !== '') {
                return true;
            }
        }
        elseif (empty($tmpCache)) {
            // 2. without a working copy: file in the request?
            $fileError = $_FILES['data']['error'][$name] ?? null;
            if ($languageId !== null) $fileError = is_array($fileError) ? ($fileError[$languageId] ?? null) : null;
            if ((int)($fileError ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) return true;
            $fieldValue = $data[$name] ?? null;
            if ($languageId !== null) $fieldValue = is_array($fieldValue) ? ($fieldValue[$languageId] ?? null) : null;
            if (is_array($fieldValue) && trim((string)($fieldValue['serverfile'] ?? '')) !== '') return true;

            // 3. file of the live record
            if ($recordId > 0) {
                $sql = "SELECT 1 FROM `data_fil` WHERE `record_id` = ? AND `field_id` = ? AND `extension` != ''";
                $sqlTypes = 'ii';
                $sqlValues = [$recordId, (int)($colDef['field_id'] ?? 0)];
                if ($languageId !== null) {
                    $sql .= " AND `language_id` = ?";
                    $sqlTypes .= 'i';
                    $sqlValues[] = $languageId;
                }
                $this->db->pushResult();
                $this->db->query($sql . " LIMIT 1", $sqlTypes, $sqlValues);
                $found = ($this->db->num_rows > 0);
                $this->db->popResult();
                if ($found) return true;
            }
        }

        // error - for multilingual fields on the input field of the primary language
        $label = $this->cms->textOrSnippet((string)($colDef['label'] ?? $name));
        $errName = $name;
        if ($languageId !== null) {
            $label .= ' | ' . (string)($langCtx['codes'][$languageId] ?? $languageId);
            $errName .= '.' . $languageId;
        }
        $this->createFieldError('error_value_mandatory', $errName, $label);
        return false;
    }

    /**
     * Load media converter
     */
    private function loadConverter(int $converterId): ?array
    {
        if (empty($converterId)) return [];
        $this->db->query('SELECT * FROM `converter` WHERE `converter_id` = ?', 'i', [$converterId]);
        return $this->db->fetch();
    }

    // Public access to the working state
    // ====================

    /**
     * Reset the working state before a record is processed that does not come from a save operation
     * (promoteBackgroundThumbnails()). Both lists must be kept
     * per record: $processedParentFieldIds stores field_ids, not
     * record ids, and would otherwise regard all thumbnail fields of the second record
     * of a run as "already done".
     */
    public function clearRecordCache(): void
    {
        $this->recordCache = [];
        $this->processedParentFieldIds = [];
        $this->processedUploadFields = [];
    }

    /** Current working state, e.g. for checking after writeThumbnails() */
    public function getRecordCache(): array
    {
        return $this->recordCache ?? [];
    }
}
