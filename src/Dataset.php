<?php
/**
 * Database table abstraction
 */
namespace Camindo\Core;

use Camindo\Core\MediaProcessor;
use Camindo\Core\ProcessorFactory;
use Camindo\Core\LazyServicesTrait;
use Camindo\Core\InstanceCache;
use Camindo\Core\PasswordPolicy;

abstract class Dataset {

    // Lazily loadable services: fill $lazyServices in the child class, access via $this->{name}
    // --------------------
    use LazyServicesTrait;

    // classes loaded via the factory
    // --------------------
    protected ?MediaProcessor $processor = null;

    // Table properties, set in hookDefinition()
    // --------------------
    protected string $moduleName = '';           // set by hookDefinition
    protected string $tableName = '';            // likewise
    protected string $defaultSortOrder = '';     // likewise
    protected string $defaultFieldName = 'name'; // likewise
    protected array  $schema = [];               // likewise
    /**
     * Delete script injections from text input (Cms::_sanitize_html() in
     * validateValue(): <script>, event handlers, javascript: URLs). HTML formatting
     * remains allowed. Only the Template module switches this off - there script is content.
     * Applies to all save paths, so also to frontend input (RecordUser).
     */
    protected bool $sanitizeHtml = true;
    /* e.g.
    $this->schema = [
        'record_id' => [
            'type'  => 'integer',
            'display_type' => 'text',
            'label' => '#area.field_record_id',
        ], ...
    */

    // Schema flags: set once after hookDefinition() and cached.
    // --------------------
    protected bool $hasParentId = false; // parent_id + position + rgt + lft + depth
    protected bool $hasDeleted = false;  // temporarily deletable
    protected bool $hasTmp = false;      // tmp (+ deleted + changed_by + changed_at)
    protected bool $hasAreaId = false;   // area_id present - but not the table area itself!
    protected bool $hasChangedAt = false; // changed_at present (schema field or hasTmp DB field)
    protected bool $hasChangedBy = false; // changed_by present (schema field or hasTmp DB field)
    protected string $dateExpression = '/(now|today)(?:([+\-][0-9]+)([smhd]))?/ui';

    // Fields whose values are already in DATABASE FORMAT in save() (cents for
    // currency, UTC ISO for timestampl, decimal point for float): validateValue()
    // skips the conversion from the input format for them and only checks the
    // rules (not_empty, min/max, pattern, unique). The list is set by the
    // caller for the duration of ONE save() call - see MoveTrait::moveToArea(),
    // which takes over the contents of a record unchanged into the fields of another
    // area and has them checked there against its field definitions.
    protected array $storedFormatFields = [];
    // Password fields whose value in $data is already the hash (from the working state
    // of the working copy, see save()) - saveVirtualFields() does not hash them again
    protected array $prehashedFields = [];
    // true: skip the count check (min/max, not_empty) of the link fields -
    // the import sets the links only in the second pass, when the ids of the
    // target records are fixed, and then saves again WITH checking
    // (Record::importSave()). Applies to ONE save() call.
    protected bool $deferRelationChecks = false;
    // true while save() runs with $stayTemporary (single step: tab switch,
    // simulation) - for hooks that may only do something on the final save
    // (Record::hookOnSave(): an area change removes the old fields)
    protected bool $savingTemporary = false;
    // Working copy to which the links of the record are attached during save() -
    // until the checkIn (0 afterwards). For the trigger templates: $cms['record_id'] in
    // onSave / onSaved when temporarily saving (TriggerTemplates). Nested
    // save() calls (triggers, embedded contents) restore the value.
    protected int $savingWorkingCopyId = 0;
    // true during an action of a deletion policy (asPolicy(), Privacy module): values
    // are overwritten, not entered - required, patterns, limits and the
    // group rights do not apply, only the conversion to the field type and 'unique'. In the
    // audit log the change is recorded as 'privacy' WITHOUT the previous values.
    protected bool $policyMode = false;

    // Date lower limit (UTC) - one source for all: Cms::DATE_MIN.
    // --------------------
    // Double role:
    //   1. stored value for "no date set" - the datetime columns are
    //      NOT NULL, so a stand-in is needed for it
    //   2. hard lower limit of every date field. A smaller value is REJECTED
    //      when saving, not silently raised: a date that changes
    //      by itself while saving is not comprehensible to the editor.
    // Cms::_formatDate() outputs this value as an empty string - stored and
    // displayed thus match.
    protected const DATE_MIN = Cms::DATE_MIN;

    // Operation timestamp of the running soft delete/restore (UTC, 'Y-m-d H:i:s.v'):
    // All records marked in ONE operation receive exactly this changed_at.
    // Set in delete() or restore(), access for hooks: getMarkTimestamp()
    protected string $markTimestamp = '';
    // Only for restore: changed_at of the deletion operation that is being undone.
    // Only records with exactly this timestamp are restored
    // (no records from earlier deletion operations). Access: getRestoreTimestamp()
    protected string $restoreTimestamp = '';

    // Audit log (Camindo\Core\AuditLog)
    // --------------------
    // false: do not log changes of this table (e.g. private messages)
    protected bool $audit = true;
    // Columns that do not belong in the log - in addition to AUDIT_IGNORE
    protected array $auditIgnore = [];
    // Never logged: change stamps, derived tree values, full text, state
    private const AUDIT_IGNORE = ['changed_at', 'changed_by', 'ft', 'sig', 'lft', 'rgt', 'depth',
        'parent_ids', 'tmp', 'origin_id', 'locked', 'deleted'];

    // HOOKs to extend the functionality per table
    // --------------------
    // Empty default implementation - override in the child class if needed.
    // When programming hooks, note:
    //   In case of error: throw an exception
    //   (Even when the functions used, load(), save(), delete(), return null!)
    abstract protected function hookDefinition(): void;
    protected function hookOnCreate(array &$data): void {}
    protected function hookOnLoaded(array &$data): void {}
    protected function hookOnSave(array &$affectedFields, array &$data, array &$previousData): void {}
    protected function hookOnSaved(array &$affectedFields, array &$data, array &$previousData): void {}
    protected function hookOnSoftDelete(array $data): void {}
    protected function hookOnDelete(array $data): void {}
    protected function hookOnDeleted(array $data): void {}
    protected function hookOnRestore(array $data): void {}

    public function __construct(
        protected Config $config,
        protected Database $db,
        protected Crypt $crypt,
        protected Cms $cms,
        protected System $system,
        protected Session $session,
        protected ProcessorFactory $factory,
        protected ContainerInterface $container
    ) {

        // HOOK table definition
        // --------------------
        $this->hookDefinition();

        // Schema - mark fields as meta fields
        foreach ($this->schema as &$val) {
            $val['meta'] = 1;
        }
        unset($val);

        // Derive the schema flags once from the schema
        // --------------------
        if ($this->hasParentId = isset($this->schema['parent_id'])) {
            if (
                !isset($this->schema['parent_ids'])
                || !isset($this->schema['lft'])
                || !isset($this->schema['rgt'])
                || !isset($this->schema['position'])
                || !isset($this->schema['depth'])
            ) {
                throw new \Exception('Schema error in table "' . $this->tableName . '" : field "parent_id" requires the fields "parent_ids", "lft", "rgt", "position", "depth".');
            }
        }
        $this->hasDeleted = isset($this->schema['deleted']);
        /*
        $this->hasTmp
            is set in hookDefinition().
            For hasTmp tables `tmp` and `deleted` are mandatory schema fields;
            in addition the database fields "origin_id",
            "changed_by", "changed_at" are required.
            Therefore always applies: hasTmp => hasDeleted.
            Tables with only hasDeleted (without hasTmp) are also possible.
        */
        if ($this->hasTmp && (!isset($this->schema['tmp']) || !$this->hasDeleted)) {
            throw new \Exception('Schema error in table "' . $this->tableName . '": hasTmp requires the schema fields "tmp" and "deleted".');
        }
        $this->hasAreaId = isset($this->schema['area_id']) && $this->tableName != 'area';
        // changed_at/changed_by: either a regular schema field
        // or a database field required with hasTmp (see comment above)
        $this->hasChangedAt = $this->hasTmp || isset($this->schema['changed_at']);
        $this->hasChangedBy = $this->hasTmp || isset($this->schema['changed_by']);
    }

    /**
     * CREATE - get default values for a new record
     *
     * @param array $defaults  overrides of the 'default' values from the table schema (hookDefinition)
     *                         values for sub-fields of a json field are given as a sub-array,
     *                         e.g. $defaults['details']['width'] = ...
     * @return array|null      complete array with default values (key for every field)
     */
    public function init(array $defaults = []): ?array
    {
        $data = [];
        $tableName = $this->tableName;
        if ($tableName == 'record' && method_exists($this, 'virtualSchema')) {
            $areaId = (int)($defaults['area_id'] ?? 0);
            $schema = $this->virtualSchema($areaId, true); // meta fields + virtual fields
        }
        else {
            $schema = $this->schema; // meta fields
        }
        foreach ($schema as $name => $colDef) {
            $type = $colDef['type'] ?? 'text';
            if (in_array($type, $this->cms->arrayTypes, true)) {
                $default = [];
                if (!empty($colDef['default'])) {
                    // Each line in the default value represents a selectable option
                    // For a relation: id, otherwise value
                    $list = preg_split('/(\r\n|\r|\n)/', $colDef['default'] ?? '');
                    foreach ($list as $item) {
                        if (in_array($type, $this->cms->dateTypes, true)) {
                            // Date function?
                            if (preg_match($this->dateExpression, $item, $matches)) {
                                $item = $this->parseDateExpression($matches);
                            }
                            // else: ISO format stays
                            // alternatively: use $this->cms->_formatDate()
                        }
                        $default[$item] = $item;
                    }
                }
            }
            else {
                $default = $this->scalarDefault($colDef);
                if (in_array($type, $this->cms->dateTypes, true)) {
                    // Date function?
                    if (preg_match($this->dateExpression, $default, $matches)) {
                        $default = $this->parseDateExpression($matches);
                    }
                    // else: ISO format stays
                    // alternatively: use $this->cms->_formatDate()
                }
            }
            if (str_contains($name, '.')) {
                // Sub-field (e.g. 'details.width')
                [$parentField, $subKey] = explode('.', $name, 2);
                $data[$parentField][$subKey] = $default;
            }
            else {
                // Main field (e.g. 'width')
                $data[$name] = $default;
            }
        }
        $data = array_replace_recursive($data, $defaults);
        // HOOK onCreate
        // --------------------
        // Catch errors
        try {
            $this->hookOnCreate($data);
            return $data;
        } catch (\Exception $e) {
            $this->cms->error($e->getMessage());
            return null;
        }
    }

    /**
     * Default of a field with a simple type. For a selection field (select, radio …)
     * the lines of the default are the options, not the value - the default is then
     * as in the mask for select the first option (which the browser preselects), for
     * the others none. Applies to init() and the fields in save() that were not submitted
     * (creating and moving without a mask).
     */
    protected function scalarDefault(array $colDef): string
    {
        $default = (string)($colDef['default'] ?? '');
        $displayType = (string)($colDef['display_type'] ?? '');
        if (preg_match('/\R/', $default) && in_array($displayType, ['select', 'radio', 'checkbox', 'multiselect', 'sortable'], true)) {
            return $displayType === 'select' ? trim((string)preg_split('/\R/', $default)[0]) : '';
        }
        return $default;
    }

    /** check() is running: save() aborts after the check */
    private bool $checkOnly = false;

    /**
     * Check without writing - the same check as save() (hookOnSave(),
     * validation, rights), then rollback instead of INSERT/UPDATE; hookOnSaved() does
     * not run, so no cache files are created either. For trial runs of the API
     * (simulate). Not for tables with working copies (record: RecordService).
     *
     * @param array $fields  as save()
     * @param array $data    as save() (is not changed)
     * @return bool          false: messages in Cms::$errors
     */
    public function check(array $fields, array $data): bool
    {
        if ($this->hasTmp) throw new \LogicException('Dataset::check() does not support working copies');
        $this->checkOnly = true;
        try {
            return $this->save($fields, $data) !== null;
        }
        finally {
            $this->checkOnly = false;
        }
    }

    /**
     * INSERT / UPDATE depending on $data["{$tableName}_id"]
     *
     * @param array $fields         names of the fields that are written
     *                              (empty array: all fields are meant)
     * @param array $data           contents
     * @return int|null             id of the created/changed record
     */
    public function save(array $fields, array &$data, bool $stayTemporary = false): ?int
    {
        $tableName = $this->tableName;
        $idField = $tableName . '_id';
        $schema = $this->schema;
        $sessionAdminId = (int)$this->session->get('admin_id');
        $adminIdForDb = $this->adminIdForDb();
        $id = (int)$data[$idField];
        $tmpId = 0;
        $originId = 0;
        $isTmp = false;
        
        // If a tmp field is defined in the table:
        // only a temporary record can be saved
        // checkout must always take place beforehand
        // Check whether the edit lock still exists and belongs to the session admin
        if ($this->hasTmp) {
            $sql = "SELECT `origin_id`,`tmp`,`deleted`,`changed_by` FROM `$tableName` WHERE `$idField` = ?";
            $this->db->query($sql, 'i', [$id]);
            if (empty($this->db->num_rows)) {
                $errorMsg = $this->cms->snippet('error_record_not_found');
                $this->cms->error($errorMsg);
                return null;
            }
            [$originId, $tmp, $deleted, $changedBy] = $this->db->fetchNum();
            $originId = (int)$originId;
            if (!empty($deleted)) {
                // Edit lock was deleted - changedBy is the admin who deleted it
                $sql = "SELECT `name` FROM `admin` WHERE `admin_id` = ?";
                $this->db->query($sql, 'i', [$changedBy]);
                if ($this->db->num_rows < 1) $adminName = '??';
                else $adminName = $this->db->fetchNum()[0];
                $errorMsg = strtr($this->cms->snippet('record.error_editlock_deleted'), [
                    '{admin_name}' => $adminName
                ]);
                // own admin: working copy usually discarded by logout (Record::cancelAll)
                if ((int)$changedBy === $sessionAdminId) {
                    $errorMsg .= ' ' . $this->cms->snippet('record.error_editlock_deleted_logout');
                }
                $this->cms->error($errorMsg);
                return null;
            }

            if ((int)$changedBy != $sessionAdminId) {
                // Access denied: a foreign working copy is to be written
                $errorMsg = $this->cms->snippet('error_tmp_record_access_denied');
                $this->cms->error($errorMsg);
                return null;
            }
            $isTmp = !empty($tmp);
            if ($isTmp) {
                // if an original exists for the record, use its id
                $tmpId = $id;
                if (!empty($originId)) {
                    $id = $originId;
                    $data[$idField] = $originId;
                }
            }
        }

        // Single-step saving requires a temporary working copy
        if ($stayTemporary && !$isTmp) {
            $this->cms->error('Dataset::save(): $stayTemporary requires a temporary working copy.');
            return null;
        }

        // Secure uploaded files BEFORE the input check
        if ($tableName === 'record' && $isTmp && $tmpId > 0
            && method_exists($this, 'secureUploads')
            && method_exists($this, 'getProdData')
        ) {
            if (!$prodData = $this->getProdData($tmpId)) {
                $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
                return null;
            }
            $uploadAreaId = (int)$prodData[1];
            if ($uploadAreaId > 0 && method_exists($this, 'virtualSchema')) {
                $uploadSchema = $this->virtualSchema($uploadAreaId, false);
                if (!$this->secureUploads($tmpId, $uploadSchema, $data)) {
                    // Upload was rejected - message is already in Cms::errors
                    return null;
                }
                // Entered passwords likewise: secure them as a hash in the working copy,
                // so that they survive a failing check and tab switches
                // (see securePasswords(); the raw input is checked below)
                if (method_exists($this, 'securePasswords')) {
                    $this->securePasswords($tmpId, $uploadSchema, $data);
                }
            }
        }

        $isNew = ($id < 1);
        // NEW IN THE TREE also applies to a working copy without an original (create, copy):
        // it has never stood in the tree - tmp rows are excluded there -, but already brings
        // an id, so the process looks like an UPDATE.
        // For parent_id / position / lft / rgt / depth the tree counts, not the id.
        $isNewInTree = $isNew || ($isTmp && $originId < 1);
        $this->savingTemporary = $stayTemporary;
        $previousWorkingCopyId = $this->savingWorkingCopyId;
        $this->savingWorkingCopyId = $isTmp ? (int)$tmpId : 0;
        $this->db->pushResult();
        // Transaktion starten
        try {
            $this->db->beginTransaction();
            // previousData
            // --------------------
            $previousData = [];
            if (!$isNew) {
                // SELECT FOR UPDATE
                if (!$previousData = $this->load($id, $idField, forUpdate:true)) {
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }
            // Audit log: state before the change (single steps of the working copy are not logged)
            $auditBefore = null;
            if (!$stayTemporary && $this->auditActive()) {
                $auditBefore = $isNewInTree ? [] : $this->auditSnapshot((int)$id);
            }

            // area_id
            // --------------------
            $areaId = 0;
            if ($this->hasAreaId) {
                // possibly not contained in data, because it is to be kept
                // then take it from the previous data
                if (!in_array('area_id', $fields, true) && !empty($fields)) {
                    $areaId = (int)($previousData['area_id'] ?? 0);
                }
                else {
                    // otherwise take it from $data
                    $areaId = (int)($data['area_id'] ?? 0);
                }
                if ($areaId < 1) {
                    $this->createFieldError('error_value_mandatory', 'area_id');
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                // Rights of the user group: creating in the area, or changing the
                // record (owner of the saved state)
                $accessAction = $isNewInTree ? 'create' : 'write';
                if (!$this->accessCheck($accessAction, $areaId, isset($previousData['owner_id']) ? (int)$previousData['owner_id'] : null)) {
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }

            // schema, allFields
            // --------------------
            if ($tableName == 'record' && method_exists($this, 'virtualSchema')) {
                // Load schema with virtual fields
                $schemaVirtual = $this->virtualSchema($areaId, false);
                $schemaComplete = $schemaVirtual + $schema; // (fields of the same name from $schemaVirtual preferred)
                // Names that may be given in fields:
                // all meta fields and virtual fields
                $allFields = array_keys($schemaComplete);
            }
            else {
                $schemaVirtual = [];
                $schemaComplete = $schema;
                // Names that may be given in fields:
                // all fields that contain no dot in the field name
                // (otherwise it is a sub-field whose data is stored in the main field)
                $allFields = array_keys(
                    array_filter($schema, fn($key) => !str_contains($key, '.'), ARRAY_FILTER_USE_KEY)
                );
            }

            // Tree active? Manual sorting active?
            // --------------------
            // Whether a record stands in a tree is not decided by the table
            // alone: for `record` only a content area that has an input field
            // `parent_id` created by the editor carries a tree. In all
            // other areas parent_id/lft/rgt/depth stay neutral and no tree
            // maintenance takes place at all - see TreeManagerTrait.
            // The same applies to `position`: without an input field nothing is sorted
            // and no siblings are shifted.
            if ($tableName === 'record') {
                $treeActive     = $this->hasParentId && isset($schemaVirtual['parent_id']);
                $positionActive = isset($schemaVirtual['position']);
            }
            else {
                $treeActive     = $this->hasParentId;
                $positionActive = isset($schema['position']);
            }

            // fields
            // --------------------
            $fields = array_unique($fields);
            foreach ($fields as $name) {
                if (!isset($schemaComplete[$name])) {
                    // Hard abort
                    throw new \Exception("Dataset::save(): field \"{$name}\" is not defined in schema of table \"{$tableName}\"");
                }
            }
            // Field names are now "safe".
            if (empty($fields)) $fields = $allFields;
            // Record the fields actually submitted in this request
            // (basis for the distinction "submitted" vs. "staged/from the working state")
            $submittedFields = $fields;

            // Position data that the request brings ITSELF.
            // Further below $data is supplemented from the working state of the working copy and from
            // $previousData - afterwards it would no longer be possible to distinguish whether
            // the editor chose a parent record or predecessor or whether
            // only the old value was carried over. Without this distinction an
            // area with an invisible parent_id/position field would pull its records to the
            // start of their siblings on every save.
            // `parent_id` counts only if the caller also names it in $fields:
            // whoever calls load() + save($fields, $data) (RecordUser, scripts) has
            // merely loaded it. Moreover, re-sorting below only takes place if previous_id
            // is submitted or the parent record actually changes.
            $parentIdSubmitted   = array_key_exists('parent_id',   $data) && in_array('parent_id', $fields, true);
            $previousIdSubmitted = array_key_exists('previous_id', $data);

            // Load the interim state (working state in `record_cache`) of the working copy:
            // - single-step saving: basis for saveTemporaryStep()
            // - final save: source of the staged fields (see below)
            // (already contains the file info just secured by secureUploads())
            $tmpCache = [];
            if ($tableName === 'record' && $isTmp && method_exists($this, '_loadTmpData')) {
                $tmpCache = $this->_loadTmpData($tmpId);
            }

            // Languages of the instance of the record (multilingual fields)
            // --------------------
            $langCtx = ($tableName === 'record') ? $this->languageContext($areaId) : [];

            // affectedFields / staged fields
            // --------------------
            // stayTemporary (single step): only the submitted fields.
            // final + working copy (record && isTmp): ALL fields. Due to the
            //   single-step interim saving, the final record can be composed of
            //   the current request AND the working state of the working copy and
            //   must be checked as a whole (e.g. never filled mandatory fields).
            // final WITHOUT working copy (other tables or record without tmp): only the
            //   submitted fields - there are no interim states here; fields that were
            //   not submitted remain unchanged in the database
            //   (e.g. login update: login_date is written, group_id stays).

            $stagedFields = [];   // fields taken over completely from the interim state
            $staged = [];         // field name => [language_id, ...]: partially (per language)
            $isFinalCheckIn = ($tableName === 'record' && $isTmp && !$stayTemporary);
            if (!$isFinalCheckIn) {
                $affectedFields = $submittedFields;
            }
            else {
                $affectedFields = $allFields;
            }
            if ($tableName === 'record' && $isTmp) {
                // Let interim states flow in: at checkOut the live state was copied into the
                // working state and advanced by single steps - it contains the complete field
                // state. Cached value fields that were NOT submitted again are taken over
                // from there on the final save (values already converted/checked) and
                // marked in $stagedFields. Relations (not cached, attached to the working copy) and
                // file fields (via the working state + promoteTmpFiles) stay out.
                //
                // Multilingual fields are merged PER LANGUAGE: the input
                // mask with tabs only sends the language of the tab, the other
                // languages come from the interim state and count as checked ($staged).
                // This also applies to the single step itself - otherwise a tab switch
                // would overwrite the other languages with empty values.
                foreach ($tmpCache as $cacheName => $entry) {
                    if (!isset($schemaComplete[$cacheName])) continue;
                    $cType = $schemaComplete[$cacheName]['type'] ?? 'text';
                    if (in_array($cType, $this->cms->relationTypes, true)) continue;
                    if ($cType === 'file') continue;
                    $isMulti = !empty($schemaComplete[$cacheName]['multilingual'])
                        && !empty($langCtx['language_ids'])
                        && is_array($entry[1] ?? null);
                    if (in_array($cacheName, $submittedFields, true)) {
                        // Request takes precedence - for multilingual fields per language
                        if ($isMulti) {
                            $submitted = is_array($data[$cacheName] ?? null) ? $data[$cacheName] : [];
                            $submittedLangs = array_map('intval', array_keys($submitted));
                            $data[$cacheName] = $submitted + (array)$entry[1];
                            $staged[$cacheName] = array_values(array_diff(
                                array_map('intval', array_keys((array)$entry[1])),
                                $submittedLangs
                            ));
                        }
                        continue;
                    }
                    if (!$isFinalCheckIn) continue; // single step: only submitted fields
                    $data[$cacheName] = $entry[1] ?? '';
                    $stagedFields[]   = $cacheName;
                }
            }
            // Passwords: left empty, but secured in the working copy
            // --------------------
            // The hash from the working state (securePasswords()) counts as input -
            // already hashed ($prehashedFields, saveVirtualFields() does not hash again)
            // and checked (staged). So a password does not have to be entered again after a failed
            // check or a tab switch, and a new record does not run into the mandatory check.
            if ($tableName === 'record' && $isTmp) {
                foreach ($schemaVirtual as $name => $colDef) {
                    if (($colDef['type'] ?? '') !== 'password' && ($colDef['display_type'] ?? '') !== 'password') continue;
                    if ((string)($data[$name] ?? '') !== '') continue;
                    $hash = (string)($tmpCache[$name][1] ?? '');
                    if ($hash === '') continue;
                    $data[$name] = $hash;
                    $this->prehashedFields[] = $name;
                    if (!in_array($name, $stagedFields, true)) $stagedFields[] = $name;
                }
            }

            if ($isFinalCheckIn) {
                // Meta fields that the request did not send
                // --------------------
                // Only the working state of the working copy is carried through the editing
                // process - and that contains ONLY the VIRTUAL
                // field contents (saveTemporaryStep(): fields from `record` itself
                // are skipped). The meta fields are therefore nowhere
                // cached: what the input mask does not display does not come back either.
                // Affected is every meta field that the area does not carry as an input
                // field or that is set to invisible -
                // status_id, template_id, online_at, offline_at, needs_review …
                // Without this block they would get the schema default below and
                // would thereby overwrite the live value at the check-in.
                //
                // Zwei Dinge passieren deshalb hier:
                //   1. the previous value moves into $data - otherwise the logic below
                //      would calculate with 0 or empty (e.g. updateInTree() via
                //      parent_id, which would hang the record at the root)
                //   2. the field drops out of $affectedFields: the column is not
                //      written and not checked again - exactly the semantics of the
                //      other tables ("not submitted remains unchanged")
                // Excluded from 2. are the tree fields: buildColumnExpressions()
                // decides about them via $treeActive (in areas without a
                // tree they are explicitly written neutrally).
                //
                // New records ($isNewInTree) are excluded: there is no predecessor
                // value, here the schema defaults belong.
                //
                // Also excluded: meta fields that the area carries as a VISIBLE
                // input field and that the caller names in $fields. If such a
                // field is missing from $data, the editor has emptied it - a switched-off
                // switch (bit) is not in the POST at all, an emptied text field is omitted by
                // serializeFiles(). The old value must not be carried over here,
                // otherwise e.g. needs_review could never be set to 0 via the mask.
                if (!$isNewInTree && !empty($previousData)) {
                    $treeColumns = ['parent_id', 'parent_ids', 'position', 'lft', 'rgt', 'depth'];
                    foreach ($schema as $name => $colDef) {
                        if (array_key_exists($name, $data)) continue;
                        if (!array_key_exists($name, $previousData)) continue;
                        if (in_array($name, $submittedFields, true)
                            && (int)($schemaComplete[$name]['visibility_input'] ?? 0) === 1
                            && !in_array($name, $treeColumns, true)) {
                            continue;
                        }
                        $data[$name] = $previousData[$name];
                        if (in_array($name, $treeColumns, true)) continue;
                        if (($pos = array_search($name, $affectedFields, true)) !== false) {
                            unset($affectedFields[$pos]);
                        }
                    }
                    $affectedFields = array_values($affectedFields);
                }

                // Default values for fields that are neither submitted nor staged
                // (empty mandatory fields thus correctly run into the not_empty check)
                foreach ($schemaComplete as $name => $colDef) {
                    if (in_array($name, $submittedFields, true)) continue;
                    if (in_array($name, $stagedFields, true)) continue;
                    if (array_key_exists($name, $data)) continue;
                    $type = $colDef['type'] ?? 'text';
                    $default = in_array($type, $this->cms->arrayTypes, true) ? ($colDef['default'] ?? '') : $this->scalarDefault($colDef);
                    if (in_array($type, $this->cms->dateTypes, true)
                        && preg_match($this->dateExpression, $default, $matches)) {
                        // convert CMS date function into a date
                        $default = $this->parseDateExpression($matches);
                        // SQL date function is inserted when writing
                    }
                    $data[$name] = $default;
                }
            }

            // HOOK onSave
            // --------------------
            // Rollback on error
            $this->hookOnSave($affectedFields, $data, $previousData);

            // Instance of the record - scope of the flag 'unique'
            // --------------------
            // For tables with `instance_id` (area, record, template) a
            // 'unique' value does not have to be unique table-wide, but within the
            // instance together with the instances interconnected with it
            // (InstanceCache::uniqueScope()). Only after hookOnSave(): Record and
            // Template fetch their instance_id there from the area.
            $instanceId = 0;
            if (isset($schema['instance_id'])) {
                $instanceId = (int)($data['instance_id'] ?? $previousData['instance_id'] ?? 0);
            }

            // Submitted, visible relation fields if these are submitted directly
            // (i.e. not of display type 'lookup')
            if ($isTmp && method_exists($this, 'createRelations')) {
                foreach ($submittedFields as $name) {
                    $colDef = $schemaComplete[$name];
                    $type = $colDef['type'] ?? 'text';
                    if (!in_array($type, $this->cms->relationTypes, true)) continue;
                    if (($colDef['display_type'] ?? '') === 'lookup') continue;
                    // target area unreadable for the group: links remain as they are
                    if (!$this->relationTargetReadable($colDef)) continue;
                    // 'link' (permanently linked contents): the links are already attached to
                    // the working copy (lookup or embedded masks, LinkTrait) -
                    // never an id list from the form
                    if (in_array($type, $this->cms->dependentTypes, true)) continue;
                    if ((int)($colDef['visibility_input'] ?? 0) !== 1) continue;
                    $this->createRelations($colDef, $tmpId, $data[$name] ?? [], replace:true, checkTmp:false);
                }
            }

            // Data processing and checking
            // --------------------
            $flagSuccess = true;
            foreach ($schemaComplete as $name => $colDef) {
                // Staged fields (taken over from the working state) are already checked and
                // converted - do not validate again (format would no longer match
                // the raw input, e.g. currency in cents, timestampl in UTC)
                if (in_array($name, $stagedFields, true)) continue;
                // created_at/created_by are not written on a change
                // (see below) - then do not check them either. Otherwise an old value
                // would block saving without being correctable via the mask:
                // the fields are not available for input there at all.
                // ($isNewInTree: a working copy without an original is also a new entry)
                if (!$isNewInTree && in_array($name, ['created_at', 'created_by'], true)) continue;
                if (!$this->validateFieldValue($name, $colDef, $data, $affectedFields, $isNew, $isTmp, $id, $tmpId, $areaId, $langCtx, $staged, $instanceId)) {
                    $flagSuccess = false;
                }
                // Upload field with mandatory (not_empty): a file must be present.
                // Only on the final save - an interim step (tab switch)
                // may remain without a file, as an empty mandatory text field would there too.
                if (($colDef['type'] ?? '') === 'file' && !$stayTemporary && !$this->policyMode
                    && in_array($name, $affectedFields, true)
                    && method_exists($this, 'validateFileRequired')
                    && !$this->validateFileRequired($name, $colDef, $data, $tmpCache, $langCtx, $isTmp ? 0 : $id)
                ) {
                    $flagSuccess = false;
                }
            }

            // Status of a record (flat workflow): if status_id stays
            // unchanged, nothing needs attention; if it is changed, the group must
            // be allowed to set the new status (Access::statusWrite()). Without a predecessor
            // the schema default counts.
            if ($flagSuccess && !$this->policyMode && $tableName === 'record' && isset($schemaComplete['status_id'])
                && in_array('status_id', $affectedFields, true) && array_key_exists('status_id', $data)) {
                $previousStatus = array_key_exists('status_id', $previousData)
                    ? (int)$previousData['status_id'] : (int)$this->scalarDefault($schemaComplete['status_id']);
                $access = $this->container->get(Access::class);
                if ((int)$data['status_id'] !== $previousStatus && !$access->statusWrite((int)$data['status_id'])) {
                    $this->createFieldError('error_status_denied', 'status_id', (string)($schemaComplete['status_id']['label'] ?? ''));
                    $flagSuccess = false;
                }
            }

            // Abort on errors
            if (!$flagSuccess) {
                // mind the finally
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }

            // Check only (check()): here everything is checked - write nothing, no
            // hooks after writing; changes from hookOnSave() are discarded with the
            // rollback
            if ($this->checkOnly) {
                $this->db->rollback();
                // (mind the finally)
                return max(0, (int)$id);
            }

            // Single-step saving ($stayTemporary === true)
            // --------------------
            // The working copy remains temporary (tmp = 1). The checked/converted
            // contents are only cached in the working state; uploads have already been
            // bound by secureUploads(), relations in validateFieldValue().
            if ($stayTemporary) {
                if ($tableName === 'record' && method_exists($this, 'saveTemporaryStep')) {
                    $this->saveTemporaryStep($affectedFields, $data, $schemaVirtual, $tmpId, $tmpCache, $langCtx);
                }
                // Refresh the edit lock of the working copy
                $sql = "UPDATE `{$tableName}` SET `changed_at` = UTC_TIMESTAMP(), `changed_by` = ?"
                    . " WHERE `{$idField}` = ?";
                $this->db->query($sql, 'ii', [$adminIdForDb, $tmpId]);
                // HOOK onSaved (before the commit)
                $this->hookOnSaved($affectedFields, $data, $previousData);
                $this->db->commit();
                // (mind the finally)
                return (int)$tmpId;
            }

            // (The content cache per language is created in saveVirtualFields() and lies
            //  in `record_cache` - for working copies it is at the same time their working state,
            //  see TemporaryTrait.)

            // position
            // --------------------
            // Was something really moved in the tree? Only then must it be
            // recalculated after the commit - a status change on a record leaves the
            // structure untouched and must not rewrite the whole area.
            $treeChanged = false;
            if ($treeActive || ($this->hasParentId && $positionActive)) {

                // parent_id, (previous_id -> position, lft, rgt)
                // --------------------
                // The order is calculated this way even WITHOUT a tree: the mask does not send
                // the number `position`, but the preceding record
                // (previous_id, see RecordBackendEdit::_editPreviousParams()). In
                // an area without a `parent_id` field all records lie on the
                // root level - the calculation is the same, only the nested set is omitted
                // ($treeChanged stays false, so nothing is recalculated).
                if ($isNewInTree) {
                    // New node: it needs a position in any case - without
                    // information it lands as the first record of its level. A copy
                    // brings its model as previous_id and thus stands directly
                    // after it (RecordBackendEdit::_editLoad()).
                    if (method_exists($this, 'createInTree')) $this->createInTree($data, $areaId);
                    $treeChanged = $treeActive;
                }
                elseif ($previousIdSubmitted
                    || ($parentIdSubmitted && (int)($data['parent_id'] ?? 0) !== (int)($previousData['parent_id'] ?? 0))) {
                    // Re-sort only if the request brings information about it:
                    // a predecessor or another parent record. An unchanged
                    // parent_id without previous_id leaves the record in its place
                    // (otherwise it would land as the first of its level).
                    if (method_exists($this, 'updateInTree')) {
                        $moved = $this->updateInTree($data, $previousData, $areaId);
                        $treeChanged = ($treeActive && $moved);
                    }
                }
            }
            elseif ($positionActive && in_array('position', $affectedFields, true)) {

                // position without tree fields
                // --------------------
                $position    = (int)$data['position'];
                $oldPosition = (int)($previousData['position'] ?? -1);
                if ($position !== $oldPosition || $isNew) {
                    // Make room: shift siblings from the target position backwards
                    $sql = "UPDATE `{$tableName}` SET `position` = `position` + 1"
                        . " WHERE `position` >= ? AND `{$tableName}_id` != ?";
                    $sqlTypes = 'ii';
                    $sqlValues = [$position, $id];
                    if ($this->hasAreaId) {
                        // position within area
                        $sql  .= " AND `area_id` = ?";
                        $sqlTypes .= 'i';
                        $sqlValues[] = $areaId;
                    }
                    $this->db->query($sql, $sqlTypes, $sqlValues);
                    // Note: MySQL locks the affected rows until the transaction is completed
                }
            }

            // Creation/change fields
            // --------------------
            // Creation date and admin always for a new entry - also for
            // a working copy without an original (create in the backend, CMS:USER_INPUT): it
            // already has an id but is new ($isNewInTree); without that
            // created_at would stay at the null date from insertTmpRecord()
            if ($isNewInTree) {
                if (isset($schema['created_at']) && !in_array('created_at', $affectedFields, true)) {
                    $affectedFields[] = 'created_at';
                }
                if (isset($schema['created_by']) && !in_array('created_by', $affectedFields, true)) {
                    $affectedFields[] = 'created_by';
                }
                // Owner (`record`.`owner_id`, see developer docs, chapter 11 (Permissions)): the
                // session admin, unless the caller passes one (frontend user:
                // RecordUser sets the 'owner' of the CMS command; import: the origin).
                // On a change the owner is only written if it is named in
                // $fields (additional right "adjust owner").
                if (isset($schema['owner_id'])) {
                    if (empty($data['owner_id'])) $data['owner_id'] = (int)($adminIdForDb ?? 0);
                    if (!in_array('owner_id', $affectedFields, true)) $affectedFields[] = 'owner_id';
                }
            }
            else {
                // on a change not taken into account
                $affectedFields = array_diff($affectedFields, ['created_at', 'created_by']);
            }
            // Always update change date and admin if present
            if (isset($schema['changed_at']) && !in_array('changed_at', $affectedFields, true)) {
                $affectedFields[] = 'changed_at';
            }
            if (isset($schema['changed_by']) && !in_array('changed_by', $affectedFields, true)) {
                $affectedFields[] = 'changed_by';
            }
            // Assemble the query
            // --------------------
            [$colExpressions, $bindTypes, $values] =
                $this->buildColumnExpressions($schemaComplete, $affectedFields, $data, $previousData, $isNew, $isNewInTree, $treeActive, $positionActive);
            // Execute the query
            // --------------------
            if ($isNew && !$isTmp) {
                // INSERT
                // --------------------
                $sql = "INSERT INTO `{$tableName}` SET " . implode(', ', $colExpressions);
                $result = $this->db->query($sql, $bindTypes, $values);
                if ($result === false) {
                    // Insert failed
                    $errorMsg = $this->cms->snippet('error_save_error');
                    $this->cms->error($errorMsg);
                    // discard the transaction
                    throw new \Exception('#' . $errorMsg);
                }
                $newId = $this->db->insert_id;
                $data[$idField] = (int)$newId;
                $data['new'] = 1;
                $id = $newId;
            } else {
                // UPDATE
                // --------------------
                if (!empty($colExpressions)) {
                    // Write meta fields
                    $sql = "UPDATE `{$tableName}`"
                        ." SET " . implode(', ', $colExpressions)
                        . " WHERE `{$idField}` = ?";
                    $bindTypes .= 'i';
                    $values[] = $id; // id for the WHERE condition
                    $result = $this->db->query($sql, $bindTypes, $values);
                    if ($result === false) {
                        // Update failed
                        $errorMsg = $this->cms->snippet('error_save_error');
                        $this->cms->error($errorMsg);
                        // discard the transaction
                        throw new \Exception('#' . $errorMsg);
                    }
                }
                $data['new'] = 0;
                // $id stays the same
            }

            // Write contents into virtual fields
            // (BEFORE checkIn: files are first stored under the id of the working copy -
            //  the working copy must still exist for this, otherwise the data_fil entries
            //  would be lost via FK cascade. All values in $data are at this point
            //  already in database format - validateFieldValue() has converted them,
            //  staged values already were.)
            if ($tableName === 'record' && method_exists($this, 'saveVirtualFields')) {
                $this->saveVirtualFields($affectedFields, $data, $previousData, $schemaVirtual, $tmpId, $langCtx);
                $this->prehashedFields = [];
            }

            // Copy the relations and delete the temp. copy
            // (checkIn() also moves the files of the working copy via promoteTmpFiles()
            //  to the live record)
            if ($isTmp && method_exists($this, 'checkIn')) {
                $this->checkIn($tmpId, $originId, $schemaVirtual);
            }
            // the links now hang on the original (see $savingWorkingCopyId)
            $this->savingWorkingCopyId = 0;

            // Thumbnails of an existing record: only now, with the files
            // under the live id (see VirtualFieldsTrait::saveVirtualFields())
            if ($tableName === 'record' && method_exists($this, 'writeDeferredThumbnails')) {
                $this->writeDeferredThumbnails((int)$id, $schemaVirtual);
            }

            // Refresh the full texts (AFTER checkIn: needs the relations copied back)
            if ($tableName === 'record' && method_exists($this, 'refreshAffectedFulltext')) {
                $this->refreshAffectedFulltext((int)$id);
            }

            // HOOK onSaved
            // --------------------
            // before the commit, in case something goes wrong
            $this->hookOnSaved($affectedFields, $data, $previousData);

            // Audit log: before/after in the same transaction
            if ($auditBefore !== null) {
                $this->auditWrite($isNewInTree ? 'create' : 'update', (int)$id, $auditBefore);
            }
            $this->db->commit();

            // Rebuild the nested set
            // --------------------
            // after the commit, and only for the tree of THIS content area -
            // trees of other areas are not affected by the change.
            if ($treeActive && $treeChanged && method_exists($this, 'externalRebuildTree')) {
                $this->externalRebuildTree($areaId);
            }
            // Area change: the tree of the source area loses a node
            // and must likewise be recalculated (externalRebuildTree() checks
            // itself whether the old area has a tree at all).
            if ($this->hasParentId && $this->hasAreaId && method_exists($this, 'externalRebuildTree')) {
                $oldAreaId = (int)($previousData['area_id'] ?? 0);
                if ($oldAreaId > 0 && $oldAreaId !== $areaId) {
                    $this->externalRebuildTree($oldAreaId);
                }
            }
            return (int)$id;
            // (mind the finally)

        } catch (\mysqli_sql_exception $e) {
            // This block is executed ONLY if it is a mysqli error
            // Refactoring: regular error handling instead of echo (debug output)
            // including rollback (was missing here until now)
            $this->cms->error('MySQL error: ' . $e->getMessage() . ' (Code ' . $e->getCode() . ')');
            $this->db->rollback();
            // (mind the finally)
            return null;

        } catch (\Exception $e) {
            // Add error messages that have not yet been collected in Cms::errors
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            // Undo everything
            $this->db->rollback();
            // (mind the finally)
            return null;
        } finally {
            $this->savingTemporary = false;
            $this->savingWorkingCopyId = $previousWorkingCopyId;
            $this->db->popResult();
        }
    }

    /**
     * Working copy to which the links of the record just saved are attached - only
     * during save() until the checkIn, otherwise 0 (see
     * $savingWorkingCopyId).
     */
    public function savingWorkingCopyId(): int
    {
        return $this->savingWorkingCopyId;
    }

    /**
     * LOAD
     *
     * @param string|int $colData  column value
     * @param string $fieldName    column name, default: {tableName}_id
     * @param bool $forUpdate      true: load with a row lock in the database
     * @return array|null          all contents except the password hash
     */
    public function load(string|int $colData, string $fieldName = '', $forUpdate = false): ?array
    {
        $fieldName = preg_replace('/[^a-z0-9_]/','', $fieldName);
        $tableName = $this->tableName;
        $schema = $this->schema;

        // WHERE column
        if ($fieldName === '') $fieldName = $tableName . '_id';

        // Determine the type of the column for transfer
        $type = $schema[$fieldName]['type'] ?? '';
        if (empty($type)) {
            // Hard abort
            throw new \Exception("Dataset::load(): Unkown column $fieldName");
        }

        // Save the previous database result
        $this->db->pushResult();
        try {
            // Create the select query
            // FOR UPDATE, only within a running transaction (see save(), delete())
            $bindType = $this->getBindType($type);

            $sql = "SELECT * FROM `{$tableName}` WHERE `{$fieldName}` = ?";
            $sql .= $forUpdate ? ' FOR UPDATE' : '';                        
            $this->db->query($sql, $bindType, [$colData]);

            if ($this->db->num_rows == 0) {
                // Record not found
                // Is a specific error snippet defined?
                $msgId = "{$this->moduleName}.error_{$tableName}_not_found";
                $errorMsg = $this->cms->snippet($msgId);
                if ($errorMsg == $msgId) {
                    // Otherwise use the general one
                    $errorMsg = $this->cms->snippet('error_record_not_found');
                }
                $this->cms->error($errorMsg);
                // discard the transaction
                throw new \Exception('#' . $errorMsg);
            }
            if ($this->tableName == 'record') {
                $data = $this->cms->fetch();
            }
            else {
                $data = $this->fetch();
            }

            // HOOK onLoaded
            // --------------------
            // Catch errors
            try {
                $this->hookOnLoaded($data);
                // (mind the finally of the outer try-catch block)
                return $data;
            } catch (\Exception $e) {
                $this->cms->error($e->getMessage());
                // (mind the finally of the outer try-catch block)
                return null;
            }

        } catch (\Exception $e) {
            // Add error messages that have not yet been collected in Cms::errors
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            // (mind the finally)
            return null;

        } finally {
            // restore the previous database result
            $this->db->popResult();
        }
    }

    // ====================
    // DELETE AND RESTORE
    //
    // Split into SINGLE and ORCHESTRATION functions.
    //
    // Single functions - each handles exactly ONE record and fires its hooks:
    //     softDelete($id)     set `deleted` = 1
    //     restoreOne($id)     set `deleted` = 0
    //     finalDelete($id)    remove the record row
    //
    // Orchestration functions - only collect the affected ids and pass them through
    // the matching single function in a loop:
    //     delete($id, $withChilds)     entry point for the backend    -> soft delete
    //     restore($id, $withChilds)    entry point for the backend    -> restore
    //     purge($id, $withChilds)      entry point for the system tool -> permanent deletion
    //
    // This split is intentional: there is NO way to delete a record
    // without its hooks running. Earlier mass UPDATEs/DELETEs via lft/rgt or
    // area_id have therefore been dropped. As soon as user-defined hooks per content area
    // are added, they take effect without further change also for mass deletions.
    //
    // DIVISION OF WORK
    //     The administrator ALWAYS deletes only soft (delete()).
    //     Permanent deletion is done exclusively by the system tool (purge()), and only
    //     records that have been marked for at least admin.trash_retention_days
    //     (scheduler task "Empty trash", type trash).
    //     Tables without a `deleted` field know no trash - there delete()
    //     deletes permanently at once.
    //
    // TRANSACTIONS
    //     The orchestration functions open ONE transaction for the entire
    //     operation: either all records are processed or none.
    //     The single functions open their own when they are called directly.
    // ====================

    /**
     * DELETE - entry point for the backend.
     *
     * Marks a record (and on request its subtree) as deleted by passing
     * each affected record individually through softDelete(). Tables without a
     * `deleted` field are instead deleted permanently (purge()).
     *
     * If the id belongs to a temporary working copy, the associated original article is meant!
     *
     * @param int  $id          `{tableName}_id`
     * @param bool $withChilds  true:  child records are marked as well; the tree remains
     *                                 unchanged (lft, rgt, position, depth), marked
     *                                 entries stay in it for the time being.
     *                          false: child records move up one level (default),
     *                                 analogous to permanent deletion - see liftChildren()
     * @return bool             true if successful
     */
    public function delete(int $id, bool $withChilds = false): bool
    {
        // Tables without a `deleted` field know no soft delete
        if (!$this->hasDeleted) {
            return $this->purge($id, $withChilds);
        }

        return (bool)$this->transactional(function () use ($id, $withChilds) {

            // SELECT FOR UPDATE, resolve temporary working copy to original
            $data = $this->loadProductive($id);

            // Operation timestamp: all records marked in this operation
            // receive exactly the same changed_at (basis for restore())
            $this->markTimestamp = $this->newTimestamp();

            $ids = $this->collectIds($data, $withChilds, 'delete');
            foreach ($ids as $currentId) {
                $this->softDeleteOne($currentId);
            }
            return true;
        }, false);
    }

    /**
     * RESTORE - entry point for the backend.
     *
     * Lifts the deletion marker by passing each affected record individually through
     * restoreOne(). The tree remained unchanged during soft delete, so a
     * rebuildTree() is not necessary.
     *
     * With $withChilds == true only the child elements of the subtree are
     * restored whose changed_at EXACTLY matches that of the root record
     * (= the same deletion operation). Records from earlier, separate deletion operations
     * remain marked. Tables without a changed_at field: no timestamp comparison
     * possible, all marked child elements are then restored.
     *
     * @param int  $id          `{tableName}_id`
     * @param bool $withChilds  true: also child elements of the same deletion operation (default)
     * @return bool             true if successful
     */
    public function restore(int $id, bool $withChilds = true): bool
    {
        if (!$this->hasDeleted) {
            // Table does not support soft delete
            $errorMsg = $this->cms->snippet('error_undelete_not_supported');
            $this->cms->error($errorMsg);
            return false;
        }

        return (bool)$this->transactional(function () use ($id, $withChilds) {
            // SELECT FOR UPDATE, resolve temporary working copy to original
            $data = $this->loadProductive($id);
            if (empty($data['deleted'])) {
                // Record is not marked as deleted at all -> nothing to do
                // (prevents accidental "co-restoring" of children via a
                // changed_at that is not a deletion time)
                return true;
            }

            // Determine the timestamp of the deletion operation:
            // raw from the DB, not from the fetch() processing of load(),
            // so that the comparison is exactly on the stored value - incl. microseconds
            $this->restoreTimestamp = $this->rawChangedAt((int)$data[$this->tableName . '_id']);

            // New timestamp, identical for all restored records
            $this->markTimestamp = $this->newTimestamp();

            $ids = $this->collectIds($data, $withChilds, 'restore');
            foreach ($ids as $currentId) {
                $this->restoreOne($currentId);
            }
            return true;
        }, false);
    }

    /**
     * PURGE - entry point for the system tool (permanent deletion).
     *
     * Removes a record (and on request its subtree) irrevocably by passing
     * each affected record individually through finalDelete(). Children are processed
     * BEFORE their parents, so that each record runs through its own hookOnDelete() -
     * the foreign key record.parent_id would otherwise delete whole subtrees via
     * ON DELETE CASCADE, bypassing the hooks.
     *
     * Callers: scheduler task trash (expired trash entries) and delete() for
     * tables without a `deleted` field.
     *
     * @param int  $id          `{tableName}_id`
     * @param bool $withChilds  true:  child records are deleted along (default)
     *                          false: child records move up one level
     * @return bool             true if successful
     */
    public function purge(int $id, bool $withChilds = true): bool
    {
        return (bool)$this->transactional(function () use ($id, $withChilds) {

            // SELECT FOR UPDATE, resolve temporary working copy to original
            $data = $this->loadProductive($id);

            $ids = $this->collectIds($data, $withChilds, 'purge');
            foreach ($ids as $currentId) {
                $this->finalDeleteOne($currentId);
            }

            // Set lft, rgt, depth once at the end - not per record
            // (rebuildTreeNow() checks itself whether the area has a tree)
            if ($this->hasParentId && method_exists($this, 'rebuildTreeNow')) {
                $this->rebuildTreeNow((int)($data['area_id'] ?? 0));
            }
            return true;
        }, false);
    }

    /**
     * SOFT DELETE of a SINGLE record.
     *
     * Sets `deleted` = 1, calls hookOnSoftDelete() and updates changed_by /
     * changed_at. hookOnDelete() is deliberately NOT called: linked data must
     * remain for a later restore().
     *
     * For media files: Record::hookOnSoftDelete() moves them to the trash
     * (var/deleted), the `data_fil` entries remain.
     *
     * The tree is not touched - marked records remain in it for the time being.
     *
     * @param int $id  `{tableName}_id` (working copy is resolved to the original)
     * @return bool    true, if successful
     */
    public function softDelete(int $id): bool
    {
        if (!$this->hasDeleted) {
            $errorMsg = $this->cms->snippet('error_undelete_not_supported');
            $this->cms->error($errorMsg);
            return false;
        }
        return (bool)$this->transactional(function () use ($id) {
            if ($this->markTimestamp === '') $this->markTimestamp = $this->newTimestamp();
            $this->softDeleteOne($id);
            return true;
        }, false);
    }

    /**
     * RESTORE of a SINGLE record.
     *
     * @param int $id  `{tableName}_id`
     * @return bool    true, if successful
     */
    public function restoreOne(int $id): bool
    {
        if (!$this->hasDeleted) {
            $errorMsg = $this->cms->snippet('error_undelete_not_supported');
            $this->cms->error($errorMsg);
            return false;
        }
        return (bool)$this->transactional(function () use ($id) {
            if ($this->markTimestamp === '') $this->markTimestamp = $this->newTimestamp();
            $this->restoreOneCore($id);
            return true;
        }, false);
    }

    /**
     * PERMANENT DELETION of a SINGLE record.
     *
     * Calls hookOnDelete() (there, among other things, the media files are removed) and then
     * deletes the record row together with its working copies. Child records are
     * NOT considered - purge() takes care of that, which processes them individually beforehand.
     *
     * The tree is not rebuilt; the caller takes care of that once at the end.
     *
     * @param int $id  `{tableName}_id` (working copy is resolved to the original)
     * @return bool    true, if successful
     */
    public function finalDelete(int $id): bool
    {
        return (bool)$this->transactional(function () use ($id) {
            $areaId = $this->finalDeleteOne($id);
            if ($this->hasParentId && method_exists($this, 'rebuildTreeNow')) {
                $this->rebuildTreeNow($areaId);
            }
            return true;
        }, false);
    }

    /**
     * UNDELETE
     * Backward-compatible alias for restore().
     */
    public function undelete(int $id, bool $withChilds = true): bool
    {
        return $this->restore($id, $withChilds);
    }

    // ====================
    // Internals of the deletion functions
    // (ALWAYS run within an already opened transaction)

    /**
     * Core of the soft delete for a record.
     *
     * @param int $id  `{tableName}_id`
     */
    protected function softDeleteOne(int $id): void
    {
        $data = $this->loadProductive($id);

        // Do not touch already marked records: they keep the changed_at
        // of their own, earlier deletion operation - otherwise a restore() of this
        // operation would wrongly restore them too.
        if (!empty($data['deleted'])) return;

        // Rights of the user group (rollback on denial)
        if (!$this->accessCheck('delete', (int)($data['area_id'] ?? 0), isset($data['owner_id']) ? (int)$data['owner_id'] : null)) {
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // HOOK onSoftDelete
        // --------------------
        // Rollback on errors
        $this->hookOnSoftDelete($data);

        $this->markDeleted($data, true);
        if ($this->auditActive()) $this->auditWrite('delete', (int)$data[$this->tableName . '_id']);
    }

    /**
     * Core of the restore for a record.
     *
     * @param int $id  `{tableName}_id`
     */
    protected function restoreOneCore(int $id): void
    {
        $data = $this->loadProductive($id);
        if (empty($data['deleted'])) return;

        // Rights of the user group: restoring = delete right
        if (!$this->accessCheck('delete', (int)($data['area_id'] ?? 0), isset($data['owner_id']) ? (int)$data['owner_id'] : null)) {
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // HOOK onRestore
        // --------------------
        // Rollback on errors
        $this->hookOnRestore($data);

        $this->markDeleted($data, false);
        if ($this->auditActive()) $this->auditWrite('restore', (int)$data[$this->tableName . '_id']);
    }

    /**
     * Core of the permanent deletion for a record.
     *
     * @param int $id  `{tableName}_id`
     * @return int     `area_id` of the deleted record (0 if the table
     *                 knows no areas) - the caller needs it to recalculate
     *                 the tree of exactly this area at the end
     */
    protected function finalDeleteOne(int $id): int
    {
        $tableName = $this->tableName;
        $data   = $this->loadProductive($id);
        $prodId = (int)$data[$tableName . '_id'];

        // Rights of the user group (rollback on denial)
        if (!$this->accessCheck('delete', (int)($data['area_id'] ?? 0), isset($data['owner_id']) ? (int)$data['owner_id'] : null)) {
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // Audit log: the last state, as long as it still exists
        $auditBefore = $this->auditActive() ? $this->auditSnapshot($prodId) : null;

        // HOOK onDelete
        // --------------------
        // Runs BEFORE the DELETE: afterwards dependent rows (e.g. `data_fil`)
        // are gone via FK cascade and the media files are no longer findable.
        // Rollback on errors
        $this->hookOnDelete($data);

        // Delete the live record
        $sql = "DELETE FROM `{$tableName}` WHERE `{$tableName}_id` = ?";
        $this->db->query($sql, 'i', [$prodId]);
        if ($this->db->affected_rows == 0) {
            // Deletion failed
            $errorMsg = $this->cms->snippet('error_deletion_failed');
            $this->cms->error($errorMsg);
            // discard the transaction
            throw new \Exception('#' . $errorMsg);
        }
        if ($this->hasTmp) {
            // Delete the temporary working copies of the record
            $sql = "DELETE FROM `{$tableName}` WHERE `tmp`=1 AND `origin_id` = ?";
            $this->db->query($sql, 'i', [$prodId]);
        }

        // Close the gap in the adjacency-list positioning
        // (only in areas with a tree - otherwise there is no sibling sequence
        //  to close, and the filter would apply across areas)
        if ($this->hasParentId && method_exists($this, 'closePositionGap')
            && $this->isTreeArea((int)($data['area_id'] ?? 0))) {
            $this->closePositionGap($data);
        }

        // HOOK onDeleted
        // --------------------
        // Runs AFTER the DELETE
        $this->hookOnDeleted($data);

        if ($auditBefore !== null) $this->auditWrite('purge', $prodId, $auditBefore, $data);

        return (int)($data['area_id'] ?? 0);
    }

    /**
     * Collect the ids that a deletion or restore operation comprises.
     *
     * Without a tree it is always exactly one id. With a tree $withChilds decides:
     *   true   the entire subtree
     *   false  only the record itself; its children are first moved up one level
     *          (liftChildren), so that they do not become orphans
     *
     * The ORDER is significant:
     *   'purge'    leaves first (rgt ascending) - each record shall run through
     *              its own hookOnDelete() before a parent could take it along via
     *              ON DELETE CASCADE
     *   'delete'   leaves first - same order, so that hooks run from bottom to
     *              top as with permanent deletion
     *   'restore'  parents first (lft ascending) - exact reversal
     *
     * @param array  $data        record (from loadProductive())
     * @param bool   $withChilds  include the subtree?
     * @param string $mode        'delete' | 'restore' | 'purge'
     * @return int[]              ids in processing order
     */
    protected function collectIds(array $data, bool $withChilds, string $mode): array
    {
        $id = (int)$data[$this->tableName . '_id'];

        // Without a tree (table without parent_id or area without a `parent_id` field)
        // there is no subtree and no children to move up.
        if (!$this->hasParentId || !method_exists($this, 'subtreeIds')
            || !$this->isTreeArea((int)($data['area_id'] ?? 0))) {
            return [$id];
        }

        if (!$withChilds) {
            // Move children up one level so that they do not become orphans
            if (method_exists($this, 'liftChildren')) {
                $this->liftChildren($data);
            }
            return [$id];
        }

        return $this->subtreeIds($data, $mode);
    }

    /**
     * Collect ids for a CASCADE via a filter field.
     *
     * Replaces the former mass function setDeletedBulk(): instead of wiping out all records of a
     * filter value with one UPDATE, this method only returns the ids -
     * the caller passes them one at a time through softDelete() / restoreOne() /
     * finalDelete(), so that the hooks run per record.
     *
     * Intended for hookOnSoftDelete() / hookOnRestore() / hookOnDelete() of OTHER
     * datasets, e.g. Area -> all records of its area_id.
     *
     * @param string $filterField     column name (must be defined in the schema)
     * @param int    $filterValue     filter value (e.g. area_id)
     * @param string $mode            'delete'  only not yet marked (`deleted` = 0)
     *                                'restore' only marked (`deleted` = 1), optionally
     *                                          restricted to $matchTimestamp
     *                                'purge'   all
     * @param string $matchTimestamp  only for 'restore': changed_at of the deletion
     *                                operation to be undone ('' = all marked)
     * @param int    $limit           > 0: return at most this many ids (batch
     *                                processing, e.g. in the system tool)
     * @return int[]
     */
    public function cascadeIds(string $filterField, int $filterValue, string $mode = 'delete', string $matchTimestamp = '', int $limit = 0): array
    {
        if (!isset($this->schema[$filterField])) {
            throw new \Exception("Dataset::cascadeIds(): field \"{$filterField}\" is not defined in schema of table \"{$this->tableName}\"");
        }
        $tableName = $this->tableName;
        $idField   = $tableName . '_id';
        $bindTypes = $this->getBindType($this->schema[$filterField]['type'] ?? 'id');
        $values    = [$filterValue];

        $sql = "SELECT `{$idField}` FROM `{$tableName}` WHERE `{$filterField}` = ?";

        // Never cascade working copies on their own - they hang on their original
        if ($this->hasTmp) $sql .= " AND `tmp` = 0";

        if ($this->hasDeleted) {
            if ($mode === 'delete') {
                $sql .= " AND `deleted` = 0";
            }
            elseif ($mode === 'restore') {
                $sql .= " AND `deleted` = 1";
                if ($matchTimestamp !== '' && $this->hasChangedAt) {
                    $sql .= " AND `changed_at` = ?";
                    $bindTypes .= 's';
                    $values[]   = $matchTimestamp;
                }
            }
        }

        // For trees: delete leaves first, restore parents first
        if ($this->hasParentId) {
            $sql .= ($mode === 'restore') ? " ORDER BY `lft` ASC" : " ORDER BY `rgt` ASC";
        }
        if ($limit > 0) $sql .= " LIMIT " . (int)$limit;

        $this->db->pushResult();
        $this->db->query($sql, $bindTypes, $values);
        $ids = $this->db->fetchAll(); // flat array
        $this->db->popResult();

        return array_map('intval', $ids);
    }

    /**
     * Set the operation timestamp from outside.
     *
     * Needed for cascades across table boundaries: every dataset is its own instance with
     * its own $markTimestamp. So that the records of an area get the same changed_at
     * as the area itself - and restore() later recognizes them as one operation -
     * the caller passes its timestamp through:
     *
     *     $record->setMarkTimestamp($this->getMarkTimestamp());
     *
     * @param string $timestamp  'Y-m-d H:i:s.v' in UTC, see newTimestamp()
     */
    public function setMarkTimestamp(string $timestamp): void
    {
        $this->markTimestamp = $timestamp;
    }

    /**
     * Set the comparison timestamp from outside (counterpart to setMarkTimestamp()).
     * Only for cascades when restoring.
     */
    public function setRestoreTimestamp(string $timestamp): void
    {
        $this->restoreTimestamp = $timestamp;
    }

    /**
     * Uniform operation timestamp in UTC, with milliseconds.
     * All records of a deletion or restore operation receive exactly
     * this value as changed_at - restore() recognizes from it what belongs together.
     */
    protected function newTimestamp(): string
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    /**
     * FETCH
     * Fetches the data of the next row as an associative array
     * and prepares the data according to the flags in the table schema
     * Table record: call extendedSchema first, then pass it as a parameter
     * 
     * @return ?array
     */
    public function fetch(): ?array
    {
        return $this->cms->fetch($this->tableName, $this->schema);
    }

    // wrapper for CMS::select()
    public function select(array $param = []): array
    {
        return $this->cms->select($param, $this);
    }

    // Save helper methods
    // ====================

    /**
     * Helper for save(): processing and checking of a single schema field.
     * Works directly on $data (reference).
     *
     * MULTILINGUAL FIELDS (`field`.`multilingual`, only table `record`)
     * --------------------
     * The value is present as an array language_id => input (this is how it comes from the
     * input mask: data[field][{language_id}], and this is how it is stored in the working state).
     * Each language is checked with the same rules - the primary language always, another
     * language only if something was entered there: empty means "not
     * translated" there, and then on writing the fallback from the primary language
     * applies (VirtualFieldsTrait::saveVirtualFields()). Errors are registered under
     * "{field}.{language_id}", the label gets the language code
     * as a suffix ("Title | de").
     *
     * $staged lists per field the languages whose values come from the interim state
     * (working state) and are already checked and converted - they are
     * not checked again (format would no longer match the raw input).
     *
     * @param array $langCtx     languages of the instance of the record, see
     *                           languageContext(); empty = single-language
     * @param array $staged      field name => [language_id, ...] of already checked languages
     * @param int   $instanceId  instance of the record (scope of 'unique'),
     *                           0 = table without instance_id
     * @return bool  true:  field ok or not affected
     *               false: error (message was entered in Cms::errors)
     */
    protected function validateFieldValue(
        string $name,
        array $colDef,
        array &$data,
        array $affectedFields,
        bool $isNew,
        bool $isTmp,
        int $id,
        int $tmpId,
        int $areaId,
        array $langCtx = [],
        array $staged = [],
        int $instanceId = 0
    ): bool {
        $type = $colDef['type'] ?? 'text';
        $empty = in_array($type, $this->cms->arrayTypes, true) ? [] : '';
        if ($this->policyMode) {
            // Deletion policy: no mandatory, no pattern, no limits (see $policyMode)
            unset($colDef['min'], $colDef['max'], $colDef['pattern']);
            $colDef['flags'] = array_values(array_intersect((array)($colDef['flags'] ?? []), ['trim', 'unique', 'unique_in_area']));
        }

        // Sub-field handling (e.g. 'details.width')
        // --------------------
        $isSubField = str_contains($name, '.');
        $parentField = null;
        $subKey = null;
        if ($isSubField) {
            [$parentField, $subKey] = explode('.', $name, 2);
            // validate a sub-field only if the parent field is written
            if (!in_array($parentField, $affectedFields, true)) return true;
        } else {
            // validate a field only if it is written
            if (!in_array($name, $affectedFields, true)) return true;
        }

        // Multilingual field: check per language
        // --------------------
        $isMultilingual = !$isSubField
            && !empty($colDef['multilingual'])
            && !empty($langCtx['language_ids'])
            && in_array($type, $this->cms->multilingualTypes, true);
        if ($isMultilingual) {
            $primaryId = (int)$langCtx['primary_id'];
            $values = $data[$name] ?? [];
            if (!is_array($values)) {
                // A single value (importer, CLI) applies to the primary language
                $values = ($values === null || $values === '') ? [] : [$primaryId => $values];
            }
            elseif (in_array($type, $this->cms->arrayTypes, true)
                && !empty($values) && !$this->isLanguageKeyed($values, $langCtx['language_ids'])
            ) {
                // json field: a simple array is the value of the primary language
                $values = [$primaryId => $values];
            }
            $stagedLangs = (array)($staged[$name] ?? []);
            $result = [];
            $ok = true;
            foreach ($langCtx['language_ids'] as $languageId) {
                $languageId = (int)$languageId;
                $value = $values[$languageId] ?? $empty;
                if (in_array($languageId, $stagedLangs, true)) {
                    // already checked and converted (interim state)
                    $result[$languageId] = $value;
                    continue;
                }
                $isEmpty = is_array($value)
                    ? empty(array_filter($value, fn($v) => !is_string($v) || trim($v) !== ''))
                    : trim((string)$value) === '';
                if ($languageId !== $primaryId && $isEmpty) {
                    // Not translated - fallback when writing
                    $result[$languageId] = $empty;
                    continue;
                }
                $suffix = ' | ' . (string)($langCtx['codes'][$languageId] ?? $languageId);
                if (!$this->validateValue($name, $colDef, $value, $isNew, $isTmp, $id, $tmpId, $areaId, false, $languageId, $suffix, $instanceId)) {
                    $ok = false;
                }
                $result[$languageId] = $value;
            }
            $data[$name] = $result;
            return $ok;
        }

        // Sub-field: get the value from the parent array (by reference)
        if ($isSubField) {
            // The parent field holds JSON data
            // so an array is expected
            $data[$parentField] = (array)($data[$parentField] ?? []);
            $data[$parentField][$subKey] = $data[$parentField][$subKey] ?? $empty;
        }
        else {
            $data[$name] = $data[$name] ?? $empty;
        }

        // Uniform reference for the subsequent validation logic
        if ($isSubField) {
            $valueRef = &$data[$parentField][$subKey];
        } else {
            $valueRef = &$data[$name];
        }

        return $this->validateValue($name, $colDef, $valueRef, $isNew, $isTmp, $id, $tmpId, $areaId, $isSubField, null, '', $instanceId);
    }

    /**
     * Is an array indexed by language (language_id => value)?
     * For json fields it distinguishes the language container from the value list itself.
     */
    private function isLanguageKeyed(array $values, array $languageIds): bool
    {
        foreach (array_keys($values) as $key) {
            if (!in_array((int)$key, $languageIds, true) || !is_numeric($key)) return false;
        }
        return true;
    }

    /**
     * Helper for validateFieldValue(): process and check ONE value.
     * Works directly on $valueRef (reference) - typing, post-processing,
     * flags, pattern, limits, uniqueness.
     *
     * Refactoring note: the unique check now runs per field independently of
     * whether another field has already failed (previously it was skipped for subsequent
     * fields after the first error; save() aborts anyway).
     *
     * @param ?int   $languageId   language of the value for multilingual fields (errors
     *                             are then registered under "{name}.{language_id}")
     * @param string $labelSuffix  addition to the label in error messages (" | de")
     * @param int    $instanceId   instance of the record - 'unique' applies within
     *                             its scope (InstanceCache::uniqueScope());
     *                             0 = table-wide
     * @return bool  true:  value ok
     *               false: error (message was entered in Cms::errors)
     */
    protected function validateValue(
        string $name,
        array $colDef,
        mixed &$valueRef,
        bool $isNew,
        bool $isTmp,
        int $id,
        int $tmpId,
        int $areaId,
        bool $isSubField = false,
        ?int $languageId = null,
        string $labelSuffix = '',
        int $instanceId = 0
    ): bool {
        $tableName = $this->tableName;
        $type = $colDef['type'] ?? 'text';
        $isDateType = in_array($type, $this->cms->dateTypes, true);
        $displayType = $colDef['display_type'] ?? 'text';
        $label = $colDef['label'] ?? $name;
        if ($labelSuffix !== '') $label = $this->cms->textOrSnippet($label) . $labelSuffix;
        $flags  = (array)($colDef['flags'] ?? []);
        // Key of the error message (highlighting in the input mask)
        $errName = ($languageId !== null) ? $name . '.' . $languageId : $name;
        // Is the value already in database format (see $storedFormatFields)?
        $isStored = in_array($name, $this->storedFormatFields, true);

        // Typing based on the table schema
        // --------------------
        if ($type === 'bit') {
            // 0 <- "false", "off", "no" -> false
            // 1 <- "true", "on", "yes"	 -> true
            $filteredValue = filter_var(trim($valueRef ?? ''), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($filteredValue === null) {
                $this->createFieldError('error_invalid_format', $errName, $label);
                return false;
            }
            // turn boolean into 0 or 1
            $valueRef = (int)$filteredValue;
        }
        elseif (in_array($type, $this->cms->arrayTypes, true)) {
            // relation, pointer, file, json, reltable
            $valueRef = (array)($valueRef ?? []);
            // Post processing per element
            // --------------------
            // Only for the types whose elements are text. 'file' carries per element an
            // array with file metadata, 'relation'/'pointer' only ids - there would be
            // nothing to trim. Nested values (json sub-fields) are left out;
            // they are checked via their own schema entry.
            if (in_array($type, ['json', 'reltable'], true)) {
                $stripTags = in_array('strip_tags', $flags, true);
                $doTrim    = in_array('trim', $flags, true);
                if ($stripTags || $doTrim || $this->sanitizeHtml) {
                    foreach ($valueRef as &$element) {
                        if (!is_string($element)) continue;
                        if ($stripTags) $element = $this->cms->_strip_tags($element);
                        elseif ($this->sanitizeHtml) $element = $this->cms->_sanitize_html($element);
                        if ($doTrim)    $element = trim($element);
                    }
                    unset($element);
                }
            }
        }
        else {
            // Wenn unerwartetes Array-Format -> Fehler
            if (is_array($valueRef ?? '')) {
                // error
                $this->createFieldError('error_unexpected_array_format', $errName, $label);
                return false;
            }
            $valueRef = (string)($valueRef ?? ''); // null for 'integer' -> ''
            // Post processing
            // --------------------
            // 'strip_tags'
            if (in_array('strip_tags', $flags, true)) {
                $valueRef = $this->cms->_strip_tags($valueRef);
            }
            // Script injection (see $sanitizeHtml) - not for passwords: the
            // raw input is hashed, a hash must not depend on a cleanup
            elseif ($this->sanitizeHtml && $type !== 'password') {
                $valueRef = $this->cms->_sanitize_html($valueRef);
            }
            // 'trim'
            if (in_array('trim', $flags, true)
                || in_array('name', $flags, true)
                || in_array($type, $this->cms->numericTypes, true)) {
                $valueRef = trim($valueRef);
            }
            // 'email'
            if ($displayType == 'email') {
                $valueRef = mb_strtolower(trim($valueRef));
            }
        }

        if ($type === 'id') {
            $valueRef = (int)($valueRef);
        }
        elseif ($type === 'integer') {
            $valueRef = trim($valueRef);
            if ($valueRef === '') $valueRef = '0'; // '' for integer => '0'
        }

        if (in_array($type, ['float', 'decimal', 'currency', 'integer'], true) && !$isStored) {
            // Number: convert into standard format
            $fmt = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::DECIMAL);
            $decimalSep = $fmt->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
            $groupingSep = $fmt->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
            $minusSign = $fmt->getSymbol(\NumberFormatter::MINUS_SIGN_SYMBOL);
            $formattedValue = $valueRef;
            // 1. remove the thousands separator ('' would lead to a parse error!)
            if ($groupingSep !== '') {
                $formattedValue = str_replace($groupingSep, '', $formattedValue);
            }
            // 2. replace the decimal separator with the SQL point
            $formattedValue = str_replace($decimalSep, '.', $formattedValue);
            // 3. the locale's minus sign (e.g. sv_SE: U+2212, possibly with direction marks)
            //    and the typographic minus are replaced by '-' - negative values remain
            //    and are only sorted out via min/max
            $formattedValue = str_replace([$minusSign, "\u{2212}"], '-', $formattedValue);
            // 4. convert digits of other scripts (ar_SA: ١٢٣, bn_BD: ১২৩ - this is how _formatFloat()
            //    displays the number) into 0-9, otherwise they would drop out in step 5
            $formattedValue = preg_replace_callback('/\p{Nd}/u',
                fn($digit) => (string)\IntlChar::charDigitValue($digit[0]), $formattedValue) ?? $formattedValue;
            // 5. remove remaining characters that are not digits, dot or minus
            //    (the format check below requires the minus before the number)
            $formattedValue = preg_replace('/[^0-9.\-]/', '', $formattedValue);
            $valueRef = trim($formattedValue); // string
        }

        if ($type === 'integer') {
            // After the conversion only digits may remain
            // (database format: the number itself, possibly with sign)
            if (!$isStored && !preg_match("/^-?[0-9]+\$/", $valueRef)) {
                $this->createFieldError('error_invalid_format', $errName, $label, $valueRef);
                return false;
            }
            
            $valueRef = (int)$valueRef;
        }
        elseif ($type === 'currency' && $isStored) {
            // Database format: already in cents
            $valueRef = (int)$valueRef;
        }
        elseif ($type === 'currency') {
            // Currency amount must be given with exactly 2 decimal places
            if ($valueRef != '' && !preg_match("/^-?[0-9]+\\.[0-9]{2}\$/", $valueRef)) {
                $this->createFieldError('error_currency_format', $errName, $label, $valueRef);
                return false;
            }
            else {
                // round instead of truncate: 19.99 * 100 gives 1998.99999... as a float
                $valueRef = (int)round((float)$valueRef * 100);
            }
        }
        elseif (in_array($type, ['float', 'decimal'], true) && !$isStored && $valueRef !== ''
            && !preg_match('/^-?([0-9]+(\.[0-9]*)?|\.[0-9]+)$/', $valueRef)
        ) {
            // e.g. "1.2.3" or "5-3"
            $this->createFieldError('error_invalid_format', $errName, $label, $valueRef);
            return false;
        }

        // Convert date and time into ISO format
        // Allowed:
        // - empty value
        // - date + time in user locale or ISO format
        // - CMS date-time function, e.g. "now" or "today+1d"
        // - SQL date-time function from the default value
        // (database format: already ISO or UTC - no conversion)
        if ($isDateType && $valueRef != '' && !$isStored) {
            if (str_ends_with($valueRef, '()') && in_array(strtoupper($valueRef), $this->cms->funcWhitelist, true)) {
                // SQL date-time function is converted when saving
            }
            elseif (preg_match($this->dateExpression, $valueRef, $matches)) {
                // Replace the entered CMS date-time function now
                $valueRef = $this->parseDateExpression($matches);
                if ($type === 'timestampl') {
                    // parseDateExpression() calculates in the user's time zone -
                    // stored in UTC (as with the date input below)
                    $date = new \DateTime($valueRef, new \DateTimeZone($this->cms->userTZ));
                    $date->setTimezone(new \DateTimeZone('UTC'));
                    $valueRef = $date->format('Y-m-d H:i:s');
                }
            }
            else {
                // Convert the entered date
                $iso = $this->dateInputToIso($valueRef);
                if ($iso !== null) {
                    $valueRef = $iso;
                    if ($type === 'timestampl') {
                        // convert date into UTC
                        $date = new \DateTime($valueRef, new \DateTimeZone($this->cms->userTZ));
                        $date->setTimezone(new \DateTimeZone('UTC'));
                        $valueRef = $date->format('Y-m-d H:i:s');
                    }
                } else {
                    // Error handling: invalid date
                    $this->createFieldError('record.error_date_format', $errName, $label);
                    return false;
                }
            }
        }
        
        // Convert time
        // Allowed
        // - empty value
        // - time in user locale or ISO format
        if ($type === 'time' && (string)$valueRef != '' && !$isStored) {
            $iso = $this->timeInputToIso((string)$valueRef);
            if ($iso !== null) {
                $valueRef = $iso;
            } else {
                // Error handling: invalid time
                $this->createFieldError('record.error_time_format', $errName, $label);
                return false;
            }
        }

        // 'not_empty' flag ("0" is accepted as well)
        // --------------------
        // For array types it is not the value that counts but the NUMBER of entries -
        // the flag is therefore evaluated further below as "at least one entry" (min = 1),
        // see $isArrayType there. For the fields created by the editor,
        // Field::hookOnSave() already does the same when saving the
        // field definition; for the schemas fixed in the code (e.g. Instance)
        // this path does not exist - hence the evaluation here.
        if (in_array('not_empty', $flags, true)
            && !in_array($type, $this->cms->arrayTypes, true)
        ) {
            if ($valueRef === '') {
                if ($displayType == 'password' && !$isNew) {
                    // Special case password: may stay empty on update = the previous one is kept
                } else {
                    $this->createFieldError('error_value_mandatory', $errName, $label);
                    // no further error message for this field
                    return false;
                }
            }
        }

        // The following checks work on a text value. For array types there is
        // none - there only count limits count (min/max, see below).
        $isArrayType = in_array($type, $this->cms->arrayTypes, true);

        // 'not_zero' flag: value must not be '0'
        // --------------------
        if (!$isArrayType && in_array('not_zero', $flags, true)) {
            if (preg_match('/^0+(\\.0*)?$/', $valueRef)) {
                $this->createFieldError('error_requirements', $errName, $label);
                return false;
            }
        }
        // pattern
        // --------------------
        $pattern = '';
        // name pattern
        if (!$isArrayType && in_array('name', $flags, true)) {
            // At least two characters
            if (mb_strlen($valueRef) < 2) {
                $this->createFieldError('error_name_length', $errName, $label);
                // no further error message for this field
                return false;
            }
            elseif (!preg_match('/^[a-z][a-z0-9_]+$/', $valueRef)) {
                $this->createFieldError('error_name_format', $errName, $label);
                // no further error message for this field
                return false;
            }
        }
        // e-mail pattern
        if ($displayType == 'email') {
            $pattern = '/^[^@]+@[^@]+\\.[a-z]+$/';
        }
        // pattern from field definition
        $colPattern = (string) ($colDef['pattern'] ?? '');
        if ($colPattern !== '') {
            $quotedPattern = str_replace('~', '\\~', $colPattern);
            $pattern = '~^(?:' . $quotedPattern . ')$~u';
        }
        // check value against pattern
        if (!$isArrayType && $pattern !== '' && $valueRef !== '' && !$this->policyMode && !preg_match($pattern, $valueRef)) {
            $this->createFieldError('error_invalid_format', $errName, $label);
            // no further error message for this field
            return false;
        }

        // Empty date/time input
        // --------------------
        if ($isDateType && $valueRef == '') {                    
            $default = $colDef['default'] ?? '' ?: self::DATE_MIN;
            if (str_starts_with($default, 'now') || str_starts_with($default, 'today')) {
                // do not re-insert a CMS date function that was deleted manually
                $default = self::DATE_MIN;
            }
            $valueRef = $default;
        }
        elseif ($type == 'time' && $valueRef == '') {
            $valueRef = '00:00';
        }

        // min / max
        // --------------------
        $min   = $colDef['min'] ?? '';
        $max   = $colDef['max'] ?? '';
        $minOp = $colDef['min_op'] ?? '>=';
        if ($isArrayType && in_array('not_empty', $flags, true)) {
            // For an array, a mandatory field means: at least one entry
            $min = max((int)$min, 1);
            $minOp = '>=';
        }
        if ($displayType === 'password' && !$isNew && $valueRef == '') {
            // Special case: password may stay empty when the record is changed
            $min = '';
        }

        // Password policy (security.password_policy, extension Password on the field;
        // developer docs, chapter 13 (Security: authentication) - only for an input, not for a hash from
        // the working state or export ($prehashedFields)
        if ($displayType === 'password' && is_string($valueRef) && $valueRef !== ''
            && !in_array($name, $this->prehashedFields, true)
        ) {
            $policy = $this->container->get(PasswordPolicy::class);
            $rules = $policy->rulesFor($colDef);
            $failed = $rules !== null ? $policy->failed($valueRef, $rules) : [];
            if ($failed) {
                $this->createFieldError('error_password_policy', $errName, $label, $policy->message($failed, $rules));
                return false;
            }
        }

        if ($isDateType) {
            if (str_ends_with((string)$valueRef, '()')
                && in_array(strtoupper((string)$valueRef), $this->cms->funcWhitelist, true)
            ) {
                // SQL date function (NOW(), UTC_TIMESTAMP(), ...): an expression, not a
                // value - the comparison would only find the function name here.
                $min = '';
                $max = '';
            }
            // Date fields always have a lower limit - see DATE_MIN.
            // A minimum stored on the field applies, provided it is NOT below it;
            // otherwise DATE_MIN applies. The check itself is done by the loop below,
            // so the message comes from the same source as any other minimum
            // ("The value must be at least {value}", with timestampl with the
            // addition "(UTC)").
            elseif ($this->dateCompare($min, self::DATE_MIN) < 0) {
                $min = self::DATE_MIN;
                $minOp = '>=';
            }
        }

        $testValue = [$min, $max];
        $testOp = [
            $minOp,
            $colDef['max_op'] ?? '<='
        ];
        $chosenNumber = 0;

        if (in_array($type, $this->cms->relationTypes, true)) {
            // Links only in the second pass (import): do not count now
            if ($this->deferRelationChecks) return true;
            // count relations
            $fieldId = (int)$colDef['field_id'];
            $srcId = $isTmp ? $tmpId : $id;
            $sql = "SELECT COUNT(*) FROM `data_rel` WHERE `field_id` = ? AND `src_id`= ?";
            $this->db->query($sql, 'ii', [$fieldId, $srcId]);
            $chosenNumber = (int)$this->db->fetchNum()[0];
        }

        foreach ($testValue as $key => $val) {
            if ($val === '') {
                // Minimum / maximum not specified
                continue;
            }
            if ($type === 'integer') {
                // integer value
                $result = ((int)$valueRef <=> (int)$val);
            }
            elseif ($type === 'float') {
                // floating-point value
                $result = ((float)$valueRef <=> (float)$val);
            }
            elseif ($type === 'decimal') {
                // decimal value
                $precision = 10;
                $result = bccomp($valueRef, $val, $precision);
            }
            elseif ($type === 'currency') {
                // 12.56 (the format in the min/max field must be the technical format with a decimal point)
                // - the value is already in cents, so the limit is converted as well
                $result = ((int)$valueRef <=> (int)round((float)$val * 100));
            }
            elseif (in_array($type, $this->cms->dateTypes, true)) {
                // compare dates (ISO format)
                // 'timestampl': the date was converted to UTC time -> min/max should be UTC as well
                // otherwise: PHP time zone / local time
                // compare entries of equal length
                $dateRef = mb_substr($valueRef, 0, mb_strlen($val));
                $result = ($dateRef <=> (string)$val);
            }
            elseif (in_array($type, $this->cms->relationTypes, true)) {
                // number of outgoing links
                $result = ((int)$chosenNumber <=> (int)$val);
            }
            elseif ($type === 'json' || $type === 'reltable') {
                // number of entries of an array
                // ('reltable' is handled like 'json' - the content is likewise an
                //  array, it only ends up in its own relation table)
                $arrayValue = (array) ($valueRef ?? []);
                $count = count($arrayValue);
                $result = ($count <=> (int)$val);
            }
            elseif (in_array($type, $this->cms->textTypes, true)) {
                // character length
                $stringValue = (string)($valueRef ?? '');
                $strlen = mb_strlen($stringValue);
                $result = ($strlen <=> (int)$val);
            }
            else {
                // unsupported type
                continue;
            }

            $op = $testOp[$key];
            if ($this->_hasCompareError($key, $op, $type, $errName, $label, $val, $result)) {
                // error message was entered in Cms::errors
                // no further checks for this field
                return false;
            }
        }

        // unique / unique_in_area
        // --------------------
        // Only meaningful for regular fields, not for sub-fields
        // 'reltable' is excluded: the field has no column that could be
        // compared (uniqueness belongs in the relation table there).
        if (!$isSubField && !in_array($type, $this->cms->noColumnTypes, true)) {
            if (in_array('unique', $flags, true)
                || in_array('unique_in_area', $flags, true)
            ) {
                $inArea = in_array('unique_in_area', $flags, true) && $this->hasAreaId;

                // Virtual field? Then the content is not a column in `record`,
                // but in the data_* table of the field type - a "WHERE `slug` = ?"
                // on `record` would fail there with "Unknown column".
                // Meta fields (Cms::$schema) are real columns and work as before.
                $isVirtual = ($tableName === 'record') && empty($colDef['meta']);

                if ($isVirtual) {
                    // Slug: what must be unique is the URL path [prefix/]slug, not the
                    // value alone - slug prefixes of the areas, Record::slugPathOk()
                    // (registers its error message itself)
                    if ($name === 'slug' && method_exists($this, 'slugPathOk')) {
                        return $this->slugPathOk((string)$valueRef, $colDef, $errName, (string)$label, $id, $areaId, $languageId, $instanceId);
                    }
                    if (!$this->uniqueVirtualOk($name, $colDef, $type, (string)$valueRef, $inArea, $id, $areaId, $languageId, $instanceId)) {
                        $this->createFieldError('error_unique_value', $errName, $label);
                        return false;
                    }
                    return true;
                }

                $sql = "SELECT 1 FROM `$tableName`"
                    ." WHERE `$name` = ? AND `{$tableName}_id` != ?";
                $sqlTypes = $this->getBindType($type) . 'i';
                $sqlValues = [$valueRef, $id];
                // 'unique_in_area' requires area_id in the schema, otherwise treat as 'unique'
                if ($inArea) {
                    $sql .= " AND `area_id` =?";
                    $sqlTypes .= 'i';
                    $sqlValues[] = $areaId;
                }
                else {
                    // 'unique' for tables with instance_id: within the instance and
                    // the instances interconnected with it, not table-wide
                    // (an area belongs to exactly one instance - unique_in_area is
                    // therefore narrower anyway)
                    $sql .= $this->uniqueInstanceCondition($instanceId);
                }
                // search only across live records
                // (also consider tables with only hasDeleted)
                if ($this->hasTmp)     $sql .= " AND `tmp` = 0";
                if ($this->hasDeleted) $sql .= " AND `deleted` = 0";
                $sql .= " LIMIT 1";
                $this->db->query($sql, $sqlTypes, $sqlValues);
                if ($this->db->num_rows > 0) {
                    $this->createFieldError('error_unique_value', $errName, $label);
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Check uniqueness of a VIRTUAL field (table `record`).
     *
     * The content lies in data_{suffix}, not as a column in `record`. The join on
     * `record` excludes working copies and deleted records - the record being
     * saved therefore already drops out as long as it is temporary;
     * $excludeId covers the case that it is already live.
     *
     * Scope:
     *     unique_in_area   per field_id - a field definition belongs to exactly one
     *                      area, the area_id comparison is nevertheless set,
     *                      so that the intention remains readable in the statement
     *     unique           per fieldname_id - the same field name across all areas
     *                      (`fieldname` maps name -> id system-wide)
     *
     * The column `data` is compared - for `encrypted` `hash` instead: encrypt()
     * draws a new IV per call, so the same plain text never yields the same
     * ciphertext. The HMAC next to it, however, is deterministic (see Crypt).
     *
     * NOT checked are relation and file fields:
     *   - relation/pointer lie as an edge list in data_rel (src_id/dest_id), there is no
     *     equality check on a `data` column
     *   - file has no meaningful equality at field level
     * For these types the flag counts as fulfilled.
     *
     * @param string $name       field name (only for the error message of the caller)
     * @param array  $colDef     field definition from virtualSchema() (needs field_id)
     * @param string $type       field type
     * @param string $value      value to check
     * @param bool   $inArea     true: check only within the area
     * @param int    $excludeId  record_id that does not count as a hit
     * @param int    $areaId     area of the record
     * @param ?int   $languageId language of the value for multilingual fields
     * @param int    $instanceId instance of the record: 'unique' applies within
     *                           its scope (InstanceCache::uniqueScope())
     * @return bool              true: value is unique (or not checkable)
     */
    protected function uniqueVirtualOk(
        string $name,
        array $colDef,
        string $type,
        string $value,
        bool $inArea,
        int $excludeId,
        int $areaId,
        ?int $languageId = null,
        int $instanceId = 0
    ): bool {
        if (in_array($type, $this->cms->relationTypes, true)) return true;
        if ($type === 'file') return true;

        $suffix = $this->cms->dataSuffix[$type] ?? '';
        $fieldId = (int)($colDef['field_id'] ?? 0);
        if ($suffix === '' || $fieldId < 1) return true;

        // Compare encrypted fields via the HMAC, not via the ciphertext
        // (same call as when writing in VirtualFieldsTrait::saveVirtualFields():
        // normalized, so "Anna@…" is therefore a duplicate of "anna@…")
        $column   = ($type === 'encrypted') ? 'hash' : 'data';
        $needle   = ($type === 'encrypted') ? $this->cms->encryptedHash($value) : $value;

        $sql = "SELECT 1 FROM `data_{$suffix}` `d`"
             . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
             . " AND `r`.`tmp` = 0 AND `r`.`deleted` = 0"
             . " WHERE `d`.`{$column}` = ? AND `d`.`record_id` != ?";
        $sqlTypes  = $this->getBindType($type) . 'i';
        $sqlValues = [$needle, $excludeId];

        // Multilingual field: uniqueness applies per language. The rows of the other
        // languages carry fallback values of the primary language and do not count there
        // as a duplicate. (Single-language fields have the same value in all languages -
        // a hit in any language is then a real duplicate.)
        if ($languageId !== null) {
            $sql .= " AND `d`.`language_id` = ?";
            $sqlTypes .= 'i';
            $sqlValues[] = $languageId;
        }

        if ($inArea) {
            $sql .= " AND `d`.`field_id` = ? AND `r`.`area_id` = ?";
            $sqlTypes .= 'ii';
            $sqlValues[] = $fieldId;
            $sqlValues[] = $areaId;
        }
        else {
            // across all areas: the fieldname_id that all same-named fields
            // share. It is not in `field` but in `fieldname` (name -> id).
            // Restricted to the instance of the record and the instances
            // interconnected with it - only there must the value (e.g. a
            // slug) be unique, see InstanceCache::uniqueScope().
            $resolved = $this->cms->resolveVirtualField($name);
            if ($resolved === null) return true;
            $sql .= " AND `d`.`fieldname_id` = ?";
            $sqlTypes .= 'i';
            $sqlValues[] = $resolved['fieldname_id'];
            $sql .= $this->uniqueInstanceCondition($instanceId, 'r');
        }
        $sql .= " LIMIT 1";

        $this->db->query($sql, $sqlTypes, $sqlValues);
        return ($this->db->num_rows < 1);
    }

    /**
     * SQL condition for the scope of a 'unique' value: the instance of the
     * record together with its readable and reading instances (InstanceCache::uniqueScope()).
     *
     * Empty for tables without instance_id or without a known instance - then
     * 'unique' applies table-wide as before. Only numbers, goes literally into the query.
     *
     * @param int    $instanceId  instance of the record, 0 = no restriction
     * @param string $alias       table alias of the column instance_id ('' = none)
     */
    protected function uniqueInstanceCondition(int $instanceId, string $alias = ''): string
    {
        if ($instanceId < 1) return '';
        $scope = $this->container->get(InstanceCache::class)->uniqueScope($instanceId);
        if (empty($scope)) return '';
        $column = ($alias !== '' ? "`{$alias}`." : '') . '`instance_id`';
        return " AND {$column} IN (" . implode(',', array_map('intval', $scope)) . ")";
    }

    /**
     * Helper for save(): assemble SET expressions, bind types and values for the
     * INSERT/UPDATE statement.
     *
     * Fields of type 'reltable' are skipped here - they have no column of their own
     * (see Cms::$dataSuffix). Their contents are written to the corresponding relation
     * table by hookOnSaved() of the Dataset subclass.
     *
     * @return array             [$colExpressions, $bindTypes, $values]
     */
    protected function buildColumnExpressions(
        array $schemaComplete,
        array $affectedFields,
        array &$data,
        array $previousData,
        bool $isNew,
        bool $isNewInTree = false,
        bool $treeActive = false,
        bool $positionActive = true
    ): array {
        $tableName = $this->tableName;
        $idField = $tableName . '_id';
        $sessionAdminId = $this->adminIdForDb();
        $colExpressions = [];
        $bindTypes = '';
        $values = [];

        foreach ($affectedFields as $name) {
            if ($name == $idField) continue;
            if ($name === 'created_at') {
                $colExpressions[] = "`created_at` = UTC_TIMESTAMP()";
                continue;
            }
            if ($name === 'created_by') {
                // Without a backend session, a supplied value applies (frontend user:
                // the owner from CMS:USER_INPUT / USER_REGISTER, see RecordUser)
                $createdBy = $sessionAdminId ?? ((int)($data['created_by'] ?? 0) ?: null);
                $colExpressions[] = "`created_by` = ?";
                $bindTypes .= 'i';
                $values[] = $createdBy;
                continue;
            }
            if ($name === 'changed_at') {
                $colExpressions[] = "`changed_at` = UTC_TIMESTAMP()";
                continue;
            }
            if ($name === 'changed_by') {
                $colExpressions[] = "`changed_by` = ?";
                $bindTypes .= 'i';
                $values[] = $sessionAdminId;
                continue;
            }
            if ($name === 'tmp') {
                continue; // handled further below
            }
            // Tree fields
            // What counts is $isNewInTree, not $isNew: a working copy without an original
            // that goes live is new in the tree, although the row already
            // exists and is therefore written via UPDATE. createInTree() has
            // set its tree fields only in $data - they must be included here.
            if ($this->hasParentId) {
                if (!$treeActive) {
                    // Area WITHOUT a tree: the tree fields stay neutral. They are
                    // explicitly written and not merely omitted - so a record that
                    // was moved here from a tree area is cleaned up at the same time.
                    if ($name === 'parent_id') {
                        $colExpressions[] = "`parent_id` = NULL";
                        continue;
                    }
                    if (in_array($name, ['lft', 'rgt', 'depth'], true)) {
                        $colExpressions[] = "`{$name}` = 0";
                        continue;
                    }
                    if ($name === 'parent_ids') {
                        $colExpressions[] = "`parent_ids` = '[]'";
                        continue;
                    }
                    if ($name === 'position' && !$positionActive) {
                        // No manual sorting: leave the value to the database
                        continue;
                    }
                }
                elseif ($isNewInTree) {
                    if ($name === 'parent_id' && (int)$data['parent_id'] === 0) {
                        // catch the special case NULL
                        $colExpressions[] = "`parent_id` = NULL";
                        continue;
                    }
                }
                else {
                    // UPDATE
                    if (in_array($name, ['parent_id', 'position', 'lft', 'rgt', 'depth'], true)) {
                        // These fields were already written via updateInTree()
                        continue;
                    }
                }
            }
            $colDef = $schemaComplete[$name] ?? [];
            $type = $colDef['type'] ?? 'text';
            // 'reltable': field without its own column. The content belongs in its own
            // relation table and is written by hookOnSaved() of the Dataset subclass -
            // nothing may go into the UPDATE/INSERT here, the column does not exist.
            // The field has already been checked in validateFieldValue().
            if (in_array($type, $this->cms->noColumnTypes, true)) continue;
            $isDateType = in_array($type, $this->cms->dateTypes, true);
            $displayType = $colDef['display_type'] ?? 'text';

            if (
                $tableName === 'record'
                && !key_exists($name, $this->schema)
            ) {
                // 1. Content for virtual field
                // --------------------
                // Written by saveVirtualFields() after the main query, once the
                // id is fixed even after INSERT - including JSON encoding and
                // password hash per language. Do not touch anything here: for multilingual
                // fields $data[$name] is an array language_id => value.
                continue;
            }

            $valueRef = &$data[$name];

            // JSON-encode
            if ($type === 'json') {
                $valueRef = json_encode($valueRef, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if ($valueRef == '""') $valueRef = '';
            }

            // hash
            if ($displayType == 'password') {
                if ($valueRef == '' && !$isNew) {
                    // Exception: when the password is changed, empty means keep the value
                    continue;
                }
                // hash the password (salt in the value, 64 characters - Crypt::passwordHash())
                $valueRef = $this->crypt->passwordHash((string) $valueRef);
            }

            {
                // 2. Meta field (data in table $tableName)
                // --------------------
                // encrypt
                if ($type === 'encrypted') {
                    $valueRef = $this->crypt->encrypt((string) $valueRef, '', true); // binary
                }
                // fulltext
                if ($type === 'fulltext') {
                    $sourceFields = (array)($colDef['source_fields'] ?? []);
                    $ft = [];
                    foreach ($sourceFields as $textField) {
                        // safety check:
                        if (($colDef['type'] ?? '') == 'encrypted') continue;
                        // parse the text and collect it
                        if (in_array($textField, $affectedFields, true)) {
                            // contained in $data
                            $ft[] = $this->cms->indexText((string)($data[$textField] ?? ''));
                        } else {
                            // not contained in data, but possibly in $previousData
                            $ft[] = $this->cms->indexText((string)($previousData[$textField] ?? ''));
                        }
                    }
                    $valueRef = implode("\n", $ft);
                }
                if ($type === 'id' && (int)$valueRef === 0) {
                    $colExpressions[] = "`$name` = NULL";
                }
                elseif ($isDateType && str_ends_with($valueRef, '()') && in_array(strtoupper($valueRef), $this->cms->funcWhitelist, true)) {
                    // SQL date function
                    $colExpressions[] = "`$name` = $valueRef";
                }
                else {
                    // transfer values separately from the statement
                    $colExpressions[] = "`$name` = ?";
                    $bindTypes .= $this->getBindType($type);
                    $values[] = $valueRef;
                }
            }
        }
        if ($this->hasTmp) {
            // the record always goes live when written
            $colExpressions[] = "`tmp` = 0";
        }
        return [$colExpressions, $bindTypes, $values];
    }

    /**
     * Languages of the instance to which a content area belongs - the context for
     * multilingual fields (table `record` only).
     *
     * @param int $areaId
     * @return array  [] for other tables, otherwise
     *                'instance_id'   instance of the area
     *                'language_ids'  languages of the instance, primary language first
     *                'primary_id'    primary language
     *                'codes'         language_id => code (for labels)
     */
    protected function languageContext(int $areaId): array
    {
        if ($this->tableName !== 'record' || $areaId < 1) return [];

        $instanceId = $this->areaInstanceId($areaId);

        $instanceCache = $this->container->get(InstanceCache::class);
        $languages = $instanceCache->languages($instanceId);
        $ids   = [];
        $codes = [];
        foreach ($languages as $languageId => $language) {
            $ids[] = (int)$languageId;
            $codes[(int)$languageId] = (string)($language['code'] ?? '');
        }
        if (empty($ids)) {
            // Instance without a (valid) primary language: it keeps its id
            $primaryId = $instanceCache->primaryLanguageId($instanceId);
            $ids = [$primaryId];
            $codes = [$primaryId => ''];
        }
        return [
            'instance_id'  => $instanceId,
            'language_ids' => $ids,
            'primary_id'   => $ids[0],
            'codes'        => $codes,
        ];
    }

    // Delete helper methods
    // =====================

    /**
     * Helper for delete()/restore(): load the record with a row lock and
     * resolve a temporary working copy to the live record if necessary.
     * Call only within a running transaction.
     *
     * @param int $id      `{tableName}_id` (the id of a working copy is also possible)
     * @return array       contents of the live record
     * @throws \Exception  if the record is not found
     */
    protected function loadProductive(int $id): array
    {
        $idField = $this->tableName . '_id';
        // SELECT FOR UPDATE
        if (!$data = $this->load($id, $idField, true)) {
            // record does not exist
            // discard the transaction
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // temporary working copy
        if ($this->hasTmp) {
            if ((int)$data['tmp'] === 1 && method_exists($this, 'getProdData')) {
                if (!$prodData = $this->getProdData($id)) {
                    $this->cms->error('error_record_not_found');
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                // continue from here with the live record
                $prodId = (int)$prodData[0];
                // SELECT FOR UPDATE
                if (!$data = $this->load($prodId, $idField, true)) {
                    // record does not exist
                    // discard the transaction
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }
        }
        return $data;
    }

    /**
     * Helper: set the deleted flag for EXACTLY ONE record.
     * Runs within the transaction of the caller (softDeleteOne() / restoreOneCore()).
     * Requires $this->hasDeleted (checked by the caller).
     * Uses $this->markTimestamp as changed_at (operation timestamp,
     * set in delete() or restore()).
     *
     * @param array $data        previous contents of the (live) record
     * @param bool  $deleted     true:  mark as deleted
     *                           false: restore
     */
    protected function markDeleted(array $data, bool $deleted): void
    {
        $tableName = $this->tableName;
        $idField   = $tableName . '_id';
        $id   = (int)$data[$idField];
        $flag = $deleted ? 1 : 0;
        $sessionAdminId = $this->adminIdForDb();
        // Safety net: the operation timestamp must be set
        if ($this->markTimestamp === '') {
            $this->markTimestamp = $this->newTimestamp();
        }

        // Exactly ONE record - subtrees and siblings are the concern of the
        // orchestration functions (see collectIds())
        $sql = "UPDATE `{$tableName}` SET `deleted` = ?";
        $sqlTypes = 'i';
        $values = [$flag];
        if ($this->hasChangedBy) {
            // changed_by shows who deleted or restored it
            // (see the edit-lock check in save())
            $sql .= ", `changed_by` = ?";
            $sqlTypes .= 'i';
            $values[] = $sessionAdminId;
        }
        if ($this->hasChangedAt) {
            // Uniform operation timestamp instead of UTC_TIMESTAMP(),
            // so that all records marked in one operation are dated exactly the same
            $sql .= ", `changed_at` = ?";
            $sqlTypes .= 's';
            $values[] = $this->markTimestamp;
        }
        $sql .= " WHERE `{$idField}` = ?";
        $sqlTypes .= 'i';
        $values[] = $id;
        $this->db->query($sql, $sqlTypes, $values);

        if ($this->hasTmp) {
            if ($deleted) {
                // Also mark the temporary working copies of the record as deleted:
                // save() recognizes the destroyed edit lock by this
                // (record.error_editlock_deleted)
                $sql = "UPDATE `{$tableName}`"
                     . " SET `deleted` = 1, `changed_by` = ?, `changed_at` = ?"
                     . " WHERE `tmp` = 1 AND `origin_id` = ?";
                $this->db->query($sql, 'isi', [$sessionAdminId, $this->markTimestamp, $id]);
                // Remove their links (as when invalidating in checkOut()):
                // check-out also placed the INCOMING edges on the copy -
                // if they remained, an n:1 field of the source would have two targets
                if ($tableName === 'record') {
                    $this->db->query(
                        "DELETE `r` FROM `data_rel` `r` INNER JOIN `record` `t`"
                        . " ON `t`.`record_id` IN (`r`.`src_id`, `r`.`dest_id`)"
                        . " WHERE `t`.`tmp` = 1 AND `t`.`origin_id` = ?",
                        'i', [$id]
                    );
                }
            } else {
                // Permanently remove working copies invalidated by the deletion
                // (edit lock was already destroyed, content is based on the pre-delete state)
                $sql = "DELETE FROM `{$tableName}`"
                     . " WHERE `tmp` = 1 AND `deleted` = 1 AND `origin_id` = ?";
                $this->db->query($sql, 'i', [$id]);
            }
        }
    }

    /**
     * Helper for restore(): read the changed_at of a record unchanged (raw) from the DB.
     * load()/fetch() may convert date values according to the schema - for the
     * exact timestamp comparison, however, the stored value is needed.
     * Tables without a changed_at field: empty string (no timestamp comparison possible).
     *
     * @param int $id   `{tableName}_id`
     * @return string   changed_at in DB format 'Y-m-d H:i:s.v' or ''
     */
    protected function rawChangedAt(int $id): string
    {
        if (!$this->hasChangedAt) return '';
        $tableName = $this->tableName;
        $sql = "SELECT `changed_at` FROM `{$tableName}` WHERE `{$tableName}_id` = ?";
        $this->db->query($sql, 'i', [$id]);
        if ($this->db->num_rows < 1) return '';
        return (string)$this->db->fetchNum()[0];
    }

    /*
     * Former mass function setDeletedBulk() removed.
     *
     * It marked all records of a filter value (e.g. an area_id) with ONE
     * UPDATE - no hooks ran in the process. Cascades from hookOnSoftDelete() /
     * hookOnRestore() of other datasets instead send the records individually
     * through softDeleteOne() or restoreOneCore(); an example is in
     * Area::hookOnSoftDelete().
     */

    /**
     * Timestamp access for hooks (hookOnSoftDelete / hookOnRestore):
     * getMarkTimestamp():    operation timestamp, set as changed_at
     * getRestoreTimestamp(): changed_at of the delete operation being reverted
     */
    public function getMarkTimestamp(): string
    {
        return $this->markTimestamp;
    }

    /**
     * Run $fn as an action of a deletion policy (see $policyMode) - module
     * Privacy, PrivacyJobs. Saving, deleting and permanent deletion within it
     * check neither required fields nor group rights.
     */
    public function asPolicy(callable $fn): mixed
    {
        $previous = $this->policyMode;
        $this->policyMode = true;
        try {
            return $fn();
        }
        finally {
            $this->policyMode = $previous;
        }
    }

    /**
     * Check the user group's rights on records and templates
     * (developer docs, chapter 11 (Permissions): permissions, Camindo\Core\Access).
     *
     * Applies only to the tables `record` and `template` and only with loaded
     * rights (backend session); in the frontend (RecordUser) and in background jobs
     * Access is not loaded and everything is allowed. The other Dataset subclasses
     * (Admin, Area, Field, ...) go through module access.
     *
     *     create   create in the area (owner = session admin)
     *     write    change - depending on the owner's group (group_/other_write)
     *     delete   delete, restore, permanent deletion
     *
     * Templates only know read/write: all three actions = templateWrite().
     * On denial the message is in Cms::errors; the caller aborts with
     * false/null (no die(), so that forms can show the message).
     *
     * @param string   $action   'create' | 'write' | 'delete'
     * @param int      $areaId
     * @param int|null $ownerId  owner_id of the record (write/delete)
     */
    protected function accessCheck(string $action, int $areaId, ?int $ownerId = null): bool
    {
        if ($this->tableName !== 'record' && $this->tableName !== 'template') return true;
        if ($this->policyMode) return true;
        $access = $this->container->get(Access::class);
        if (!$access->isLoaded() || $access->isSuperuser()) return true;
        if ($this->tableName === 'template') {
            $ok = $access->templateWrite($areaId);
        }
        else {
            $ok = match ($action) {
                'create' => $access->areaCreate($areaId),
                'delete' => $access->areaDelete($areaId, $ownerId),
                default  => $access->areaWrite($areaId, $ownerId),
            };
        }
        if (!$ok) $this->cms->error($this->cms->snippet('error_access_denied'));
        return $ok;
    }

    /** Result of relationTargetReadable() per field_id (request) */
    private array $relationTargetReadable = [];

    /**
     * May the user group read at least one target area of a link field
     * (relation, pointer, link - uni- as well as bidirectional)? Otherwise the
     * input form does not show the field, and saving, check-out/check-in leave its
     * links untouched (developer docs, chapter 11 (Permissions)). Always true for other field
     * types and without loaded rights.
     *
     * @param array $colDef  entry from virtualSchema() (field_id, type, rel_area_id)
     */
    public function relationTargetReadable(array $colDef): bool
    {
        $type = (string)($colDef['type'] ?? '');
        if (!in_array($type, $this->cms->relationTypes, true)) return true;
        $access = $this->container->get(Access::class);
        if (!$access->isLoaded() || $access->isSuperuser()) return true;
        $fieldId = (int)($colDef['field_id'] ?? 0);
        if ($fieldId > 0 && array_key_exists($fieldId, $this->relationTargetReadable)) {
            return $this->relationTargetReadable[$fieldId];
        }
        $areaIds = [];
        if (!empty($colDef['rel_area_id'])) $areaIds[] = (int)$colDef['rel_area_id'];
        if ($fieldId > 0 && method_exists($this, 'getRelAreaIds')) {
            $this->db->pushResult();
            $areaIds = array_merge($areaIds, array_map('intval', $this->getRelAreaIds($fieldId)));
            $this->db->popResult();
        }
        $readable = empty($areaIds);
        foreach ($areaIds as $areaId) {
            if ($access->areaRead($areaId)) { $readable = true; break; }
        }
        if ($fieldId > 0) $this->relationTargetReadable[$fieldId] = $readable;
        return $readable;
    }

    /**
     * name, type and instance_id of an area from the instance file cache
     * (InstanceCache::areaInfo()) - instead of "SELECT instance_id, type FROM area".
     *
     * The cache is rewritten in Area::hookOnSaved() within the same transaction,
     * so a newly created area is already in it. If it is missing anyway
     * (cache cleared by hand, external writer), the method queries the
     * table - the caller notices nothing of this.
     *
     * @return array|null  ['name', 'type', 'instance_id', 'trigger_template_ids'] or null (unknown)
     */
    protected function areaInfo(int $areaId): ?array
    {
        if ($areaId < 1) return null;
        $info = $this->container->get(InstanceCache::class)->areaInfo($areaId);
        if ($info !== null) return $info;
        $this->db->pushResult();
        $this->db->query("SELECT `name`, `type`, `instance_id` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
        $row = $this->db->fetch();
        $triggerIds = [];
        if ($row) {
            $this->db->query("SELECT `template_id` FROM `rel_area_trigger` WHERE `area_id` = ? ORDER BY `position`, `template_id`", 'i', [$areaId]);
            $triggerIds = array_map('intval', $this->db->fetchAll()); // flat list
        }
        $this->db->popResult();
        if (!$row) return null;
        return [
            'name' => (string)$row['name'],
            'type' => (string)$row['type'],
            'instance_id' => (int)$row['instance_id'],
            'trigger_template_ids' => $triggerIds,
        ];
    }

    /**
     * Instance of an area (see areaInfo()); 0 = unknown.
     */
    protected function areaInstanceId(int $areaId): int
    {
        return (int)($this->areaInfo($areaId)['instance_id'] ?? 0);
    }

    /**
     * Type of an area (see areaInfo()); '' = unknown.
     */
    protected function areaType(int $areaId): string
    {
        return (string)($this->areaInfo($areaId)['type'] ?? '');
    }

    /**
     * Admin id for the columns created_by / changed_by - or NULL.
     *
     * Without a backend session (frontend: CMS:USER_INPUT & Co. write records
     * for website visitors, see RecordUser) there is no administrator. The
     * columns carry a foreign key to `admin`, so a 0 would run into the
     * foreign key - hence NULL. mysqli transmits a null value as SQL NULL,
     * regardless of the bind type.
     *
     * @return int|null  admin_id of the session, null without a session
     */
    protected function adminIdForDb(): ?int
    {
        $adminId = (int)$this->session->get('admin_id');
        return $adminId > 0 ? $adminId : null;
    }

    public function getRestoreTimestamp(): string
    {
        return $this->restoreTimestamp;
    }

    // General helper methods
    // ======================

    /**
     * Helper: runs $task within a transaction.
     * Saves the previous database result (push/popResult),
     * commits on success, collects error messages in Cms::errors
     * and rolls back on exceptions.
     *
     * @param callable $task     task to run, its return value is passed through
     * @param mixed $onError     return value in case of error
     */
    protected function transactional(callable $task, mixed $onError = null): mixed
    {
        // save the previous database result
        $this->db->pushResult();
        // start the transaction
        try {
            $this->db->beginTransaction();
            $result = $task();
            $this->db->commit();
            // (mind finally)
            return $result;
        } catch (\Exception $e) {
            // add error messages that have not yet been collected in Cms::errors
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            // undo everything
            $this->db->rollback();
            // (mind finally)
            return $onError;
        } finally {
            // restore the previous database result
            $this->db->popResult();
        }
    }

    /**
     * Helper: evaluates the previous comparison result ($result) and creates an error message if necessary.
     * @return bool     true: error (occurred during the comparison)
     */
    private function _hasCompareError(int $key, string $op, string $type, string $name, string $label, mixed $val, int $result): bool
    {
        $error = false;
        $errorSnippetId = 'error' . ($key === 0 ? '_min' : '_max');
        switch ($op) {
            case '>=':
                // Error: the checked value is less than the minimum
                if ($result === -1) {
                    $error = true;
                    $errorSnippetId .= '_ge';
                }
                break;
            case '>':
                // Error: the checked value is less than or equal to the minimum
                if ($result <= 0) {
                    $error = true;
                    $errorSnippetId .= '_gt';
                }
                break;
            case '<=':
                // Error: the checked value is greater than the maximum
                if ($result === 1) {
                    $error = true;
                    $errorSnippetId .= '_le';
                }
                break;
            case '<':
                // Error: the checked value is greater than or equal to the maximum
                if ($result >= 0) {
                    $error = true;
                    $errorSnippetId .= '_lt';
                }
                break;
        }
        if (in_array($type, $this->cms->textTypes, true)) {
            $errorSnippetId .= '_length';
        }
        elseif (in_array($type, $this->cms->arrayTypes, true)) {
            $errorSnippetId .= '_number';
        }
        else {
            $errorSnippetId .= '_value';
        }
        if ($error) {
            if ($type == 'timestampl') {
                // note what is being compared
                $val = (string)$val . ' (UTC)';
            }
            $this->createFieldError($errorSnippetId, $name, $label, (string)$val);
        }
        return $error;
    }

    /**
     * Compare two dates in ISO format.
     *
     * The comparison only goes as far as the shorter entry reaches - a minimum may
     * be a partial entry ('2020' means "from 2020"). The same rule is applied to the
     * field value by the min/max loop in validateFieldValue().
     *
     * An empty entry counts as "no date" and therefore as smaller than any other;
     * this is how DATE_MIN replaces an unset field minimum.
     *
     * @return int  -1, 0 or 1, like the spaceship operator
     */
    protected function dateCompare(string $a, string $b): int
    {
        if ($a === '') return ($b === '') ? 0 : -1;
        if ($b === '') return 1;
        $len = min(mb_strlen($a), mb_strlen($b));
        return mb_substr($a, 0, $len) <=> mb_substr($b, 0, $len);
    }

    /**
     * Helper: create an error message with the field label.
     *
     * @param string $errorSnippetId  Id of errorSnippet
     * @param string $name         colum name
     * @param string $label           Label value from schema (e.g. '#area.field_...')
     * @param string $refValue        Optional reference value for the {value} placeholder
     */
    protected function createFieldError(string $errorSnippetId, string $name, string $label = '', string $refValue = '') {
        if (!empty($label)) $labelText = $this->cms->textOrSnippet($label);
        else $labelText = $name;
        $msg = strtr($this->cms->snippet($errorSnippetId), [
            '{value}' => htmlspecialchars($refValue)
        ]);
        $this->cms->error($msg, $name, $labelText);
    }

    /**
     * Helper: determines the mysqli bind type ('i', 'd', 's', 'b')
     * from the SQL type in the definition.
     *
     * @param string $type  For supported SQL-Fieldtype see Class Tables
     */
    public function getBindType(string $type): string
    {
        return match ($type) {
            'integer', 'bit', 'currency', 'id' => 'i',
            'float' => 'd',
            default => 's'
        };
    }

    /**
     * Creates a date in ISO format from a preceding preg_match() via $this->parseDateExpression
     * The input string (e.g. "now+2d" or "today-5h")
     *
     * @param array $matches
     * @return string the formatted date, or the original string if there is no match
     */
    protected function parseDateExpression(array $matches): string
    {
        // determine the base ('now' = current time, 'today' = today 00:00:00)
        $baseTime = strtolower($matches[1]);
        $dateTime = new \DateTime($baseTime);
        // check whether an offset exists (sign/number in index 2 and unit in index 3)
        if (isset($matches[2], $matches[3])) {
            $offsetValue = $matches[2];
            $unitShort = strtolower($matches[3]);
            $unitLong = match ($unitShort) {
                's' => 'seconds',
                'm' => 'minutes',
                'h' => 'hours',
                'd' => 'days',
                default => ''
            };
            // apply the offset (e.g. "+5 days", "-2 hours")
            if ($unitLong !== '') {
                $dateTime->modify($offsetValue . ' ' . $unitLong);
            }
        }
        return $dateTime->format('Y-m-d H:i:s');
    }

    /**
     * Replaces protected spaces in an ICU pattern with normal ones.
     *
     * Since ICU 72, CLDR patterns contain a narrow no-break space (U+202F)
     * before the AM/PM marker ("h:mm\u{202F}a" for en_US). Non-tolerant parsing
     * then requires exactly this character; with a normal space in the pattern,
     * ICU accepts both the typed space and U+202F/U+00A0.
     */
    private static function intlPatternNormalize(string $pattern): string
    {
        // nobody types direction marks (ar_SA: "d\u{200F}/M\u{200F}/y") - they are dropped,
        // as in intlInputNormalize()
        return str_replace(self::BIDI_MARKS, '', str_replace(["\u{202F}", "\u{00A0}"], ' ', $pattern));
    }

    /** Direction marks LRM, RLM, ALM - CLDR puts them into date patterns of Arabic locales */
    private const BIDI_MARKS = ["\u{200E}", "\u{200F}", "\u{061C}"];

    /**
     * Prepare the date or time input for recognition: digits of any script
     * (Arabic ٢٦, Bengali ২৬ …) become 0-9, direction marks are dropped. This way, with
     * ar_SA, the displayed form "٢٦ سبتمبر ٢٠٣٠ م" counts just like a typed
     * "26/09/2030" - parsing then uses Latin digits (intlLatnLocale()).
     */
    private static function intlInputNormalize(string $value): string
    {
        $value = str_replace(self::BIDI_MARKS, '', $value);
        return preg_replace_callback('/\p{Nd}/u', fn($digit) => (string)\IntlChar::charDigitValue($digit[0]), $value) ?? $value;
    }

    /** Locale with Latin digits for IntlDateFormatter, e.g. "ar_SA@numbers=latn" */
    private static function intlLatnLocale(string $locale): string
    {
        if (str_contains($locale, 'numbers=')) return preg_replace('/numbers=[A-Za-z]+/', 'numbers=latn', $locale) ?? $locale;
        return $locale . (str_contains($locale, '@') ? ';' : '@') . 'numbers=latn';
    }

    /**
     * IntlDateFormatter::parse(), which only returns a result if the input
     * was processed completely.
     *
     * parse() succeeds as soon as the pattern matches a prefix of the input;
     * a remainder such as a time or "extra" would go unnoticed. The position
     * filled by reference counts UTF-16 units (not bytes), so the comparison is made
     * against the UTF-16 length - otherwise inputs such as "24. März 2026" would fail.
     *
     * @return int|null  Unix timestamp, or null (no match or remainder not read)
     */
    private static function intlParseComplete(\IntlDateFormatter $fmt, string $value): ?int
    {
        $position = 0;
        $ts = $fmt->parse($value, $position);
        if ($ts === false) return null;
        $utf16Length = strlen(mb_convert_encoding($value, 'UTF-16BE', 'UTF-8')) / 2;
        return $position === $utf16Length ? (int)$ts : null;
    }

    /**
     * Converts a flexible date input into the ISO storage format.
     *
     * Order of recognition:
     *   1. ISO partial entry (YYYY[-MM[-DD[ HH[:MM[:SS[.mmm]]]]]], 'T' allowed)
     *   2. IntlDateFormatter (locale-aware, requires the intl extension)
     *   3. strtotime() as a last fallback
     *
     * Day, month and year must be recognized
     * Time is padded at the end with the mask 00:00:00
     *
     * No time zone conversion
     * Year range: 1000–3000 (paths 2+3 require 64-bit PHP)
     *
     * Return format:
     * -> 'Y-m-d H:i:s'
     *
     * Digits of any script are first converted to 0-9 (intlInputNormalize()).
     *
     * @param string $value  entered date value
     * @return string|null   ISO string, or null for invalid input
     */
    protected function dateInputToIso(string $value): ?string
    {
        $value = trim(self::intlInputNormalize($value));
        if ($value === '') return null;
        // 1. ISO partial entry (zero-padded)
        // --------------------
        $pattern = '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2})(?::(\d{1,2})(?::(\d{1,2}))?)?)?$/';
        if (preg_match($pattern, $value, $m)) {
            $year  = (int)$m[1];
            $month = (int)$m[2];  // not optional in the regex → always set
            $day   = (int)$m[3];  // not optional in the regex → always set
            $hour  = isset($m[4]) && $m[4] !== '' ? (int)$m[4] : 0;
            $min   = isset($m[5]) && $m[5] !== '' ? (int)$m[5] : 0;
            $sec   = isset($m[6]) && $m[6] !== '' ? (int)$m[6] : 0;
            // Range check (otherwise invalid input)
            // Nothing is rejected here for being too early: a well-formed date before
            // the year 1000 is not a format violation, it merely lies below the
            // lower limit. The message for it should name the limit ("at least
            // 1000-01-01 (UTC)") and not "invalid format" - it is therefore only
            // rejected by the min check in validateFieldValue().
            // Nonsense such as 0000-00-00 (month/day 0) still falls through below.
            if ($year > 9999)                          return null;
            if ($month < 1 || $month > 12)             return null;
            // check the day against the last day of the month
            $dateString = sprintf('%04d-%02d-01', $year, $month);
            $date = new \DateTimeImmutable($dateString);
            $lastDay = (int)$date->format('t');
            if ($day < 1 || $day > $lastDay)           return null;
            if ($hour > 23 || $min > 59 || $sec > 59)  return null;
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec);
        }
        // 2. IntlDateFormatter (locale-aware)
        // --------------------
        if (class_exists('\IntlDateFormatter')) {
            $locale = self::intlLatnLocale(\Locale::getDefault() ?: 'de_DE');
            $dateStyles = [
                \IntlDateFormatter::SHORT,
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::LONG,
            ];
            $timeStyles = [
                \IntlDateFormatter::MEDIUM,  // HH:mm:ss
                \IntlDateFormatter::SHORT,   // HH:mm
                \IntlDateFormatter::NONE,    // no time component
            ];
            foreach ($dateStyles as $dateStyle) {
                foreach ($timeStyles as $timeStyle) {
                    $fmt = new \IntlDateFormatter(
                        $locale,
                        $dateStyle,
                        $timeStyle,
                        'UTC',                          // fixed zone: no DST edge case
                        \IntlDateFormatter::GREGORIAN
                    );
                    $fmt->setLenient(false);
                    // Retrieve the standard pattern of the locale (e.g. "dd.MM.y, HH:mm:ss" for de_DE).
                    // Protected spaces (U+202F before "PM" since ICU 72, U+00A0) are replaced by
                    // normal ones - ICU then accepts both variants in the input.
                    $originalPattern = self::intlPatternNormalize($fmt->getPattern());
                    // Create a tolerant variant without a comma (e.g. "dd.MM.y HH:mm:ss")
                    // (also the Arabic comma: ar_SA "d/M/y G، h:mm a")
                    $flexiblePattern = str_replace([', ', '، '], ' ', $originalPattern);
                    // and without the era (ar_SA: "d/M/y G" - when typing, the "م" is left out)
                    $noEraPattern = trim(preg_replace(['/\bG+\b/', '/\s+/'], ['', ' '], $flexiblePattern) ?? $flexiblePattern);
                    // Test all patterns to be robust against missing punctuation.
                    // array_unique prevents unnecessary duplicate runs if nothing changes.
                    $patterns = array_unique([$originalPattern, $flexiblePattern, $noEraPattern]);
                    foreach ($patterns as $pattern) {
                        $fmt->setPattern($pattern);
                        // Only successful if the entire string was processed: parse() is satisfied with a
                        // matching prefix; with $timeStyle = NONE an existing time (that does not match the pattern)
                        // would otherwise be silently discarded.
                        $ts = self::intlParseComplete($fmt, $value);
                        if ($ts !== null) {
                            // the '@' prefix forces UTC interpretation of the timestamp
                            $date = new \DateTimeImmutable('@' . $ts);
                            return $date->format('Y-m-d H:i:s');
                        }
                    }
                }
            }
        }
        // 2b. Date and time separately: "9/26/2030 14:30" in a locale with a
        // 12-hour format (en_US, ar_SA …) matches no pattern of the locale
        // --------------------
        if (preg_match('/^(.*\S)[\s,،]+(\d{1,2}[:.]\d{1,2}(?:[:.]\d{1,2})?(?:\s*(?:am|pm))?)$/iu', $value, $m)
            && preg_match('/\d/', $m[1])
        ) {
            $date = $this->dateInputToIso($m[1]);
            $time = $date !== null && substr($date, 11) === '00:00:00' ? $this->timeInputToIso($m[2]) : null;
            if ($time !== null) return substr($date, 0, 10) . ' ' . $time;
        }
        // 3. strtotime() fallback
        // --------------------
        $ts = strtotime($value);
        if ($ts === false) return null;
        // new DateTime() uses the PHP default timezone (consistent with the strtotime interpretation)
        $date = new \DateTime();
        $date->setTimestamp($ts);
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Converts a flexible time input into the storage format 'H:i:s'.
     *
     * Order of recognition:
     * 1. own patterns (ISO, French/German 'h', AM/PM, bare hour)
     * 2. IntlDateFormatter (time only, locale-aware)
     * 3. strtotime() fallback
     *
     * No time zone conversion
     *
     * Digits of any script are first converted to 0-9 (intlInputNormalize()).
     *
     * @param string $value  entered time
     * @return string|null   'HH:MM:SS', or null for invalid input
     */
    protected function timeInputToIso(string $value): ?string
    {
        $value = trim(self::intlInputNormalize($value));
        if ($value === '') return null;
        $hour = null;
        $min  = 0;
        $sec  = 0;
        $norm = mb_strtolower($value);
        // 1a. AM/PM (12-hour format), e.g. "7 pm", "7:30 pm", "7:30:15 pm", "12am"
        if (preg_match('/^(\d{1,2})(?::(\d{1,2})(?::(\d{1,2}))?)?\s*(am|pm)$/', $norm, $m)) {
            $h = (int)$m[1];
            $min = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
            $sec = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 0;
            if ($h < 1 || $h > 12) return null;
            // 12am = 00:00, 12pm = 12:00
            if ($m[4] === 'am') $hour = ($h === 12) ? 0 : $h;
            else                $hour = ($h === 12) ? 12 : $h + 12;
        }
        // 1b. French/German "19h", "19h30", "19 h 30", "19 h 30 15"
        elseif (preg_match('/^(\d{1,2})\s*h\s*(\d{1,2})?(?:\s*(\d{1,2}))?$/', $norm, $m)) {
            $hour = (int)$m[1];
            $min  = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
            $sec  = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 0;
        }
        // 1c. ISO or "19:00", "19.00", "19:00:30" (seconds are taken along)
        elseif (preg_match('/^(\d{1,2})[:.](\d{1,2})(?:[:.](\d{1,2}))?$/', $norm, $m)) {
            $hour = (int)$m[1];
            $min  = (int)$m[2];
            $sec  = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 0;
        }
        // 1d. bare hour "19"
        elseif (preg_match('/^(\d{1,2})$/', $norm, $m)) {
            $hour = (int)$m[1];
            $min  = 0;
            $sec  = 0;
        }
        // 2. IntlDateFormatter (time only, locale-aware)
        // --------------------
        if ($hour === null && class_exists('\IntlDateFormatter')) {
            $locale = self::intlLatnLocale(\Locale::getDefault() ?: 'de_DE');
            foreach ([\IntlDateFormatter::SHORT, \IntlDateFormatter::MEDIUM] as $timeStyle) {
                $fmt = new \IntlDateFormatter(
                    $locale,
                    \IntlDateFormatter::NONE,
                    $timeStyle,
                    'UTC',                              // fixed zone: no DST edge case
                    \IntlDateFormatter::GREGORIAN
                );
                $fmt->setLenient(false);
                $fmt->setPattern(self::intlPatternNormalize($fmt->getPattern()));
                // As in dateInputToIso(): accept only if the input was read completely
                $ts = self::intlParseComplete($fmt, $value);
                if ($ts === null) continue;
                // the '@' prefix forces UTC interpretation of the timestamp
                $dt   = new \DateTimeImmutable('@' . $ts);
                $hour = (int)$dt->format('G');
                $min  = (int)$dt->format('i');
                $sec  = (int)$dt->format('s');
                break;
            }
        }
        // 3. strtotime() fallback (e.g. "7 pm" in environments without intl)
        // --------------------
        if ($hour === null) {
            $ts = strtotime($value);
            if ($ts !== false) {
                // date() uses the PHP default timezone (consistent with the strtotime interpretation)
                $hour = (int)date('G', $ts);
                $min  = (int)date('i', $ts);
                $sec  = (int)date('s', $ts);
            }
        }
        // range check
        // --------------------
        if ($hour === null)            return null;
        if ($hour < 0 || $hour > 23)   return null;
        if ($min  < 0 || $min  > 59)   return null;
        if ($sec  < 0 || $sec  > 59)   return null;
        return sprintf('%02d:%02d:%02d', $hour, $min, $sec);
    }

    /**
     * Helper functions for backend extensions
     * access to protected vars
     */
    public function getSchema(): array {
        return $this->schema;
    }
    
    public function setSchema(array $schema): void {
        $this->schema = $schema;
    }
    
    public function getModuleName(): string {
        return $this->moduleName;
    }
    
    public function getTableName(): string {
        return $this->tableName;
    }
    
    public function getDefaultSortOrder(): string {
        return $this->defaultSortOrder;
    }
    
    public function getDefaultFieldName(): string {
        return $this->defaultFieldName;
    }
    
    public function getSortOrder(): string {
        return $this->defaultSortOrder;
    }
    
    public function hasTree(): bool {
        return $this->hasParentId;
    }

    // ====================
    // Audit log (Camindo\Core\AuditLog)

    /**
     * Is this table currently being logged?
     */
    protected function auditActive(): bool
    {
        return $this->audit && $this->container->get(AuditLog::class)->enabled();
    }

    /**
     * Snapshot of a record for the audit log: the columns of the table in
     * database format, JSON columns expanded (details.url …), without AUDIT_IGNORE
     * and $auditIgnore. For the format see AuditLog (class comment).
     *
     * Record overrides this (all languages, virtual fields, links).
     *
     * @param int $id  `{tableName}_id`
     * @return array   key => ['l', 't', 'g', 'v', 'c'?]; [] if it does not exist
     */
    public function auditSnapshot(int $id): array
    {
        $idField = $this->tableName . '_id';
        $this->db->pushResult();
        try {
            $this->db->query("SELECT * FROM `{$this->tableName}` WHERE `{$idField}` = ?", 'i', [$id]);
            $row = $this->db->fetch();
        }
        finally {
            $this->db->popResult();
        }
        if (!$row) return [];
        return $this->auditColumns($row, $this->schema);
    }

    /**
     * Columns of a table row as a snapshot (see auditSnapshot()).
     *
     * @param array $row     row in database format
     * @param array $schema  schema with label and type per column
     */
    protected function auditColumns(array $row, array $schema): array
    {
        $auditLog = $this->container->get(AuditLog::class);
        $ignore = array_merge(self::AUDIT_IGNORE, $this->auditIgnore);
        $snapshot = [];
        foreach ($row as $name => $value) {
            $name = (string)$name;
            if (in_array($name, $ignore, true) || !isset($schema[$name])) continue;
            $colDef = $schema[$name];
            $type = (string)($colDef['type'] ?? 'text');
            $label = (string)($colDef['label'] ?? $name);
            if ($type === 'password') {
                $value = $this->auditSecret($name, (string)$value);
                $snapshot[$name] = ['l' => $label, 't' => 'password', 'g' => '', 'v' => '',
                    'c' => $value === '' ? '' : hash('sha256', $value)];
            }
            elseif ($type === 'encrypted') {
                $snapshot[$name] = $auditLog->encryptedEntry($label, (string)$value);
            }
            elseif ($type === 'json') {
                $decoded = json_decode((string)$value, true);
                if (!is_array($decoded)) {
                    $snapshot[$name] = ['l' => $label, 't' => 'json', 'g' => '', 'v' => (string)$value];
                    continue;
                }
                foreach ($this->auditFlatten($decoded, $name) as $key => $subValue) {
                    if (in_array($key, $ignore, true)) continue;
                    $subDef = $schema[$key] ?? [];
                    $subType = (string)($subDef['type'] ?? 'text');
                    if ($subType === 'password') {
                        $subValue = $this->auditSecret($key, $subValue);
                        $snapshot[$key] = ['l' => (string)($subDef['label'] ?? $key), 't' => 'password', 'g' => '', 'v' => '',
                            'c' => $subValue === '' ? '' : hash('sha256', $subValue)];
                        continue;
                    }
                    $snapshot[$key] = ['l' => (string)($subDef['label'] ?? $key), 't' => $subType, 'g' => '', 'v' => $subValue];
                }
            }
            else {
                $snapshot[$name] = ['l' => $label, 't' => $type, 'g' => '', 'v' => $value === null ? '' : (string)$value];
            }
        }
        return $snapshot;
    }

    /**
     * Comparison value of a password field for the audit log. Default: the stored
     * value (a hash). A subclass that stores a password reversibly encrypted
     * (new ciphertext on every save) returns the plain text here -
     * otherwise every save would count as a password change. It leaves the method only
     * as a hash.
     */
    protected function auditSecret(string $key, string $value): string
    {
        return $value;
    }

    /**
     * Expand a nested array into dot keys: ['details' => ['a' => 1]]
     * -> ['details.a' => '1']. Lists (0, 1, 2 …) remain one value (JSON).
     */
    protected function auditFlatten(array $values, string $prefix): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = $prefix . '.' . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $flat += $this->auditFlatten($value, $path);
            }
            elseif (is_array($value)) {
                $flat[$path] = $value === [] ? '' : (string)json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            elseif (is_bool($value)) {
                $flat[$path] = $value ? '1' : '0';
            }
            else {
                $flat[$path] = $value === null ? '' : (string)$value;
            }
        }
        return $flat;
    }

    /**
     * Label of an object for the log: the default field of the table (usually
     * 'name'), otherwise 'label'.
     *
     * @param array $snapshot  snapshot (after, or before when deleting)
     */
    protected function auditLabel(int $id, array $snapshot): string
    {
        foreach ([$this->defaultFieldName, 'label', 'name', 'displayname'] as $field) {
            $value = (string)($snapshot[$field]['v'] ?? '');
            if ($value !== '') return $this->cms->textOrSnippet($value);
        }
        return '#' . $id;
    }

    /**
     * Write an entry to the audit log (within the running transaction).
     *
     * @param string     $action  create|update|delete|restore|purge
     * @param int        $id      live id
     * @param array|null $before  snapshot before; null for delete/restore (no
     *                            field changes, only the event)
     * @param array      $data    on permanent deletion: the loaded row (the
     *                            database no longer knows it)
     */
    protected function auditWrite(string $action, int $id, ?array $before = null, array $data = []): void
    {
        $auditLog = $this->container->get(AuditLog::class);
        $after = ($action === 'purge') ? [] : $this->auditSnapshot($id);
        $changes = ($before === null) ? [] : $auditLog->diff($before, $after);
        if ($action === 'update' && !$changes) return;
        if ($this->policyMode && $action === 'update') {
            // deletion policy: the previous values are about to disappear - only the
            // new ones are recorded in the log
            $action = 'privacy';
            foreach ($changes as &$change) $change['b'] = '';
            unset($change);
        }

        $ref = $after ?: ($before ?? []);
        $areaId = isset($ref['area_id']['v']) ? (int)$ref['area_id']['v']
            : (isset($data['area_id']) ? (int)$data['area_id'] : null);
        if ($this->tableName === 'area') $areaId = $id;
        $instanceId = isset($ref['instance_id']['v']) ? (int)$ref['instance_id']['v']
            : (isset($data['instance_id']) ? (int)$data['instance_id'] : null);
        $auditLog->write($action, $this->tableName, $id, $this->auditLabel($id, $ref), $changes,
            $areaId ?: null, $instanceId ?: null);
    }
}
