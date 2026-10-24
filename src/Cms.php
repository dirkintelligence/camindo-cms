<?php
/**
 * Core class of the CMS. Instance name in the code: $cms.
 *
 * At around 3,400 lines the largest class of the project. It combines four tasks that
 * have grown together historically:
 *
 *   1. TYPE SYSTEM      Which field types exist, in which data_* table they live
 *                       and how they are to be handled ($dataSuffix, $textTypes,
 *                       $numericTypes, $dateTypes, $arrayTypes, $relationTypes).
 *                       Also the fixed schema of the table `record` ($schema).
 *   2. QUERY            select() / filter() - the central read access including the
 *                       construction of the JOINs on the data_* tables. The same code serves
 *                       backend lists, backend search and the template command CMS:SELECT.
 *   3. OUTPUT           fetch() prepares a result row; the _* methods
 *                       format individual values (date, number, text).
 *   4. TEMPLATE COMMANDS The cms* methods at the end of the class are the runtime behind
 *                       the (CMS:...) commands of the frontend templates.
 *
 * In addition there are the multilingual text building blocks (snippet()). Since C9 the
 * page lists have been moved out to Camindo\Core\Pagination and are loaded there.
 *
 * VIRTUAL FIELDS - THE CORE IDEA
 * --------------------
 * A record (`record`) holds only metadata. The actual contents lie
 * separated by type in data_txt, data_int, data_fil, data_rel, ... - one table per field type.
 * Which field belongs where is stored in `field` (per content area) and in `fieldname`
 * (system-wide, name -> id + type).
 *
 * select() reassembles a row from these at runtime via JOIN. For the
 * caller it feels like an ordinary table:
 *
 *     $cms->select(['filter' => ['titel' => 'Hen*'], 'limit' => 20]);
 *     while ($row = $cms->fetch()) { echo $row['titel']; }
 *
 * The price for this is the JOIN construction in filter() and the register*Join() methods -
 * the most elaborate part of the class.
 *
 * META FIELDS VS. VIRTUAL FIELDS
 * --------------------
 *     meta field       fixed in $schema, real column in `record`
 *                      (record_id, area_id, status_id, online_at, lft/rgt, ...)
 *     virtual field    created by the editor, content in a data_* table
 *
 * All entries in $schema are marked with 'meta' => 1 in the constructor; filter()
 * uses this to tell the two worlds apart.
 *
 * In addition there is the pseudo field 'area' (tables with `area_id`, i.e. record and
 * template): the NAME of a content area instead of its id, for templates -
 * filter[area]=article resp. sort=area. select() translates it into `area_id` via the instance
 * file cache (see translateAreaFilter(), areaMap()); a number counts
 * as area_id in this case. The database does not see any of this.
 *
 * IMPORTANT: the data_* tables must be filled for EVERY field of EVERY record,
 * otherwise the select query does not work across fields. A virtual field
 * without a row in its data_* table does not exist for the filter - and that also
 * applies to negation, because filter() evaluates the condition INSIDE the EXISTS resp. the JOIN.
 * Example data_fil (buildFileExists()):
 *
 *     EXISTS (SELECT 1 FROM `data_fil`
 *             WHERE `record_id` = `record`.`record_id` AND `fieldname_id` = ?
 *               AND <condition>)
 *
 * If the row is missing, this yields false for every condition, also for "!= x". An empty
 * entry is therefore not ballast but a prerequisite: only it makes the record
 * findable - a missing file is found as `field.size = 0`, an empty text field
 * as `field = ''`. Whoever creates entries (promoteTmpFiles(), TriggerMedia, imports)
 * therefore creates them even if no content exists yet.
 *
 * TWO KINDS OF TABLES
 * --------------------
 * select() works without $tableObj on `record` - only there are there virtual fields.
 * If a dataset subclass is passed (Admin, Area, Template, ...), its schema applies,
 * and only real columns are filtered.
 *
 * THE "CURRENT RESULT"
 * --------------------
 * select() returns no data, only meta information (sorting used,
 * readable filter text). The rows are then fetched with fetch() from the database result.
 * Whoever starts a subquery in the meantime must bracket it with Database::pushResult() /
 * popResult().
 *
 * ERROR HANDLING
 * --------------------
 * The class hardly throws. Errors are collected with error() and are then available in
 * $errors / $hasErrors; errorMsg() composes a message from them and
 * getErrorFields() names the affected fields so that the backend can highlight them.
 * Callers must therefore actively check - a select() with an unknown filter field
 * silently returns a result and only records a message.
 *
 * LANGUAGE AND TIME ZONE
 * --------------------
 * setLang() sets the snippet language; snippet() loads the cache files
 * var/cache/lang/{lang}/{prefix}.php on the first access to a snippet of the prefix
 * (frontend file, on failure {prefix}.backend.php - see loadSnippets()) and
 * finally falls back to the key itself; the language fallback to 'en_US' is frozen
 * when the cache is built (ToolSnippets). Which languages exist is stated by
 * backendLanguages() (Core/lang/*.ini and var/translation/translated). The constructor
 * sets 'en_US' and the time zone from system.timezone; afterwards the context takes over:
 *
 *     Backend    Session::load()/create() call applyAdminSettings() with the
 *                personal settings of the admin (`admin`.`settings`:
 *                backend_code, locale, timezone - AdminBackend::settings(),
 *                login form)
 *     Frontend   setLanguage() sets the snippet language, time zone and locale of the
 *                content language (`language`.`backend_code`, `language`.`timezone`,
 *                `language`.`code`) - messages and date formats of the CMS commands
 *                thus come in the language of the page
 *
 * The LOCALE (ICU, e.g. de_AT) is separate from the backend language: it determines
 * date, time and number formats (_formatDate(), _formatFloat(), the input
 * recognition in Dataset) via \Locale::getDefault(). Config sets system.locale,
 * setUserLocale() the one of the admin (applyAdminSettings()) resp. in the frontend the one of the
 * content language (setLanguage()); $userLocale holds the value
 * for the backend (cConfig.locale).
 *
 * To be distinguished from this is the CONTENT LANGUAGE (table `language`): the language in
 * which the records are delivered. Every record keeps its field contents per
 * language of its instance - in the data_* tables (one row per language, with the
 * fallback value of the main language where no translation exists) and in `record_cache`
 * (the JSON image per language). select() appends the language condition to every JOIN on these
 * tables, fetch() reads the cache of the language:
 *
 *     Frontend   Page::resolveRequest() calls setLanguage() with the language from the URL resp.
 *                Accept-Language; for readable foreign instances without this language
 *                their main language applies (languageMap)
 *     Backend    applyAdminSettings() fills $languageMap with the preferred
 *                language version of the admin (per instance that has it); otherwise
 *                the main language of the instance of each record applies (languageExpr()
 *                builds a CASE expression from it via `instance_id`)
 *
 * The user time zone (setUserTZ()) applies to PHP and to the database connection and
 * overrides the server time zone set in Config. Date/time values are stored in UTC
 * and converted back for display via utcToUserTZ().
 *
 * FRONTEND EDITING
 * --------------------
 * CMS:FED / CMS:FED_CODE output something only if the cookie 'fed' (FED_COOKIE) is 1
 * or 2 - set via the personal settings of the admin
 * (AdminBackend::settings()) resp. at login, see fedActive().
 *
 * @see Camindo\Core\Dataset          write access, validation, hooks
 * @see Camindo\Core\VirtualFieldsTrait  writing of the virtual fields
 * @see Camindo\Core\Backend          backend UI
 * @see Camindo\Core\Pagination       page lists (CMS:JUMP_*)
 * @see developer docs, chapter 04 (Select and filter)   filter syntax and structure of the query
 */
namespace Camindo\Core;

use Camindo\Core\Dataset;

class Cms {

    // Date limits
    // --------------------
    // The datetime columns are NOT NULL - "no date" and "no end" therefore each need
    // a stand-in. Both are at the same time the limits within which
    // a date is permitted at all; Dataset::validateFieldValue() rejects everything
    // below them when saving.
    //
    //     DATE_MIN   no date set                 (UTC, exact)
    //     DATE_MAX   no end / unlimited          (schema default of offline_at)
    public const DATE_MIN = '1000-01-01 00:00:00';
    public const DATE_MAX = '9000-01-01 00:00:00';

    // Display window (_formatDate)
    // --------------------
    // When reading, utcToUserTZ() converts into the time zone of the user - a
    // stored DATE_MIN comes back as '1000-01-01 00:53:28' (Berlin local time
    // before 1893), in other zones with up to 14 hours offset in both
    // directions. The display therefore needs a day of leeway, otherwise
    // the placeholder would appear as a real date. For SAVING this does not apply: there
    // the value is already in UTC and DATE_MIN applies exactly.
    private const DATE_MIN_DISPLAY = '1000-01-02 00:00:00'; // DATE_MIN + 1 Tag
    private const DATE_MAX_DISPLAY = '8999-12-31 00:00:00'; // DATE_MAX - 1 Tag

    public string $lang;                // currently set language
    public array  $errors = [];         // collection of error messages
    public bool   $hasErrors = false;   // true: an error occurred
    public array  $modules = [];        // active modules      - var/config/components/modules.php (ComponentManager)
    public array  $processors = [];     // active processors   - var/config/components/processors.php
    public array  $extensions = [];     // active extensions   - var/config/components/extensions.php
    private array $snippetCache = [];   // snippets: [lang][prefix][key] => text|Closure, see loadSnippets()
    private array $snippetLoaded = [];  // [lang][prefix] => true: cache files included, see loadSnippets()
    private ?bool $backendSnippets = null; // also load {prefix}.backend.php? see withBackendSnippets()
    private array $fieldnameCache = []; // mapping name - type
    private array $recordRefCache = [];  // (CMS:...) id=... -> record, see _record()
    private array $slugCache = [];       // "{record_id}:{language_id}" -> slug, see languageSlug()
    private array $virtualSchemaCache = [];
    private ?array $deletedFieldIds = null; // fieldname_id => field_ids in the trash, see deletedFieldIds()
    private ?array $backendLanguages = null; // see backendLanguages()

    // SELECT and FILTER
    public array $dataSuffix = [
        'bit'        => 'bit',
        'chars'      => 'chr',
        'currency'   => 'cur',
        'datetime'   => 'dat',
        'decimal'    => 'dec',
        'encrypted'  => 'enc',
        'file'       => 'fil',
        'float'      => 'flt',
        'fulltext'   => 'ftx',
        'id'         => 'rid',
        'ids'        => 'ids',
        'integer'    => 'int',
        'json'       => 'jsn',
        'password'   => 'pwd',
        'pointer'    => 'rel',
        'pointer1'   => 'rel',
        'link'       => 'rel',
        'relation'   => 'rel',
        'relation1'  => 'rel',
        'text'       => 'txt',
        'time'       => 'tim',
        'timestampl' => 'tsl',
        // 'reltable': the content does NOT lie in its own column, but in a
        // separate relation table (e.g. `rel_instance_language`). The field stands only
        // in the schema, so that Dataset::save() can apply its input check to it;
        // it is written and read by hookOnSaved()/hookOnLoaded() of the
        // respective dataset subclass.
        // Handling: everywhere like 'json' (array type, Backend::formField, backendValue),
        // ONLY Cms::select() skips the type - there is no column to search
        // and no data_* table. Hence the placeholder suffix '_none_'.
        // Examples: Field 'rel_area_ids', Instance 'additional_languages',
        //           Instance 'readable_instances'
        'reltable'   => '_none_',
        // 'dft' is a special case and has no fieldname_id
        // copy of the first 32 characters of the default field
        // can be searched with Cms:select()
    ];
    public array $types; // all keys from dataSuffix
    // Field types that have no own table column and therefore cannot be filtered
    // nor sorted (see select() / filter()).
    public array $noColumnTypes = ['reltable'];
    // Field types that are never filtered nor sorted: a prefix search or the
    // order of the rows would reveal the stored password hash character by character
    public array $unsearchableTypes = ['password'];

    // string values
    public array $textTypes =     ['text', 'chars', 'encrypted', 'password'];
    // numeric values
    public array $numericTypes =  ['integer', 'currency', 'float', 'decimal', 'bit', 'id'];
    // date values
    public array $dateTypes =    ['timestampl', 'datetime'];
    // array values (also the n:1 relations: their value is a list of ids with
    // at most one entry, not_empty/min/max count entries)
    public array $arrayTypes =    ['file', 'json', 'relation', 'pointer', 'relation1', 'pointer1', 'link', 'reltable'];
    // relations
    public array $relationTypes = ['relation', 'pointer', 'relation1', 'pointer1', 'link'];
    public array $singleRelationTypes = ['relation1', 'pointer1'];
    // References with MULTIPLE target areas (`rel_field_area` instead of `field.rel_area_id`):
    // pointer/pointer1 and 'link' - the firmly linked contents (1:n, directed,
    // at most one source record per target record; target areas with
    // `area.dependent` = 1, see LinkTrait)
    public array $multiAreaTypes = ['pointer', 'pointer1', 'link'];
    // Field types whose target records DEPEND on the source record (deleting, copying,
    // moving act on them, see LinkTrait) - so far only 'link'
    public array $dependentTypes = ['link'];
    // Field types that can be maintained multilingually (`field`.`multilingual`):
    // all whose data_* table has a column `language_id` - except 'file'
    // (files and thumbnails follow in a separate step) and 'fulltext'
    // (generated per language from the source fields, not entered).
    // Not included: relation/pointer (data_rel), id (data_rid), password (data_pwd).
    public array $multilingualTypes = [
        'bit', 'chars', 'currency', 'datetime', 'decimal', 'encrypted',
        'float', 'integer', 'json', 'text', 'time', 'timestampl'
    ];
    // Types whose data_* table carries the column `empty`: 1 = the row of this
    // language is a fallback from the main language (multilingual field without its own
    // input). These are the multilingual-capable types; file fields keep
    // the same in `file_language_id`, all other types are never a fallback.
    // Written by VirtualFieldsTrait::saveVirtualFields() and
    // LanguageData::addLanguage(), read by createRecordCache() and the
    // filter value "(empty)" in select() (see emptyCondition()).
    public const EMPTY_FILTER = '(empty)';
    public function hasEmptyFlag(string $type): bool
    {
        return in_array($type, $this->multilingualTypes, true);
    }

    // Columns of `data_fil` that move into the content cache of a file field
    // ($fileSortCols plus the language of the physical file, see mediaFileName())
    public array $fileCacheCols = ['filename', 'mime_type', 'extension', 'size', 'width', 'height', 'file_language_id', 'version'];
    
    // allowed function calls (date-time functions)
    public array $funcWhitelist = [
        'NOW()', // synonym for CURRENT_TIMESTAMP()
        'CURRENT_TIMESTAMP()',
        'CURDATE()',
        'CURTIME()',
        'UTC_TIMESTAMP()',
        'UTC_DATE()',
        'UTC_TIME()',
    ];
    public string $serverTZ = '';
    public string $userTZ = '';
    // ICU locale of the formats (see class comment "Language and time zone")
    public string $userLocale = '';
    // Cookie of the frontend editing: 0/missing = off, 1 = until browser end resp. logout,
    // 2 = permanent (see fedActive(), AdminBackend::settings())
    public const FED_COOKIE = 'fed';
    // CMS:FED_SCRIPT outputs the script block only once per request (cmsFedScript())
    private bool $fedScriptOut = false;
    // Backend language in which every snippet must exist (fallback when building
    // the cache, see ToolSnippets)
    public const FALLBACK_LANG = 'en_US';
    // Format of the backend languages = file names of the language files (lang/{code}.ini):
    // language and region like the ICU locale, e.g. de_DE, pt_BR
    public const LANG_PATTERN = '/^[a-z]{2,3}_[A-Z]{2}$/';
    // prefix (= name of the cache file) of the snippets without component: Core/lang, Core/backend/lang
    public const CORE_PREFIX = 'core';
    // Backend languages with writing direction from right to left (<html dir="rtl">,
    // see isRtl()). Languages without a translation are listed here too - if someone
    // creates lang/{code}.ini, the direction is right immediately.
    public const RTL_LANGUAGES = [
        'ar_SA',    // Arabic
        'fa_IR',    // Persian
        'ur_PK',    // Urdu
        'he_IL',    // Hebrew
        'ps_AF',    // Pashto
        'ckb_IQ',   // Central Kurdish (Sorani)
        'ug_CN',    // Uyghur
        'syr_SY',   // Syriac
        'nqo_GN',   // N'Ko
    ];

    // ACCESS RESTRICTION TO INSTANCES
    // --------------------
    // An SQL expression that select() appends as an additional AND condition to every query on
    // `area`, `record` and `template`. Empty = no restriction.
    //
    //     ''                          everything visible (default)
    //     '`instance_id` = 5'         only this website
    //     '`instance_id` IN (5,7)'    this website and those it may read
    //
    // The expression is set from outside:
    //     Frontend  Page::resolveInstance() - from the called domain and the
    //               `readable_instances` of the instance
    //     Backend   at login (Session::applyAccess()), if the admin's group is not
    //               superuser: its assigned instances
    // Both via setInstanceAccess(), so that the expression is built from numbers and
    // not assembled by hand.
    //
    // IMPORTANT: the expression goes LITERALLY into the query (no bind parameters) and is
    // deliberately NOT table-qualified - `area`, `record` and `template` have the
    // column, the data_* tables added by select() do not, so the name stays
    // unambiguous. Queries that run directly via Database::query() must append it
    // themselves.
    public string $sqlAccess = '';
    // the same ids as a list (empty = no restriction), see accessInstanceIds()
    private array $accessInstanceIds = [];
    // tables for which $sqlAccess applies - all three have an `instance_id`
    public const ACCESS_TABLES = ['area', 'record', 'template'];
    // area level (backend, Session::applyAccess()): "`area_id` IN (...)" of the
    // readable areas of the user group, for `record` and `template`; '' = no
    // restriction. Like $sqlAccess literal and not table-qualified.
    public string $sqlAreaAccess = '';
    public const AREA_ACCESS_TABLES = ['record', 'template'];

    // CONTENT LANGUAGE
    // --------------------
    // See class comment "Language and time zone". Set via setLanguage().
    //
    //     $languageId    requested language (frontend); null = no language context
    //                    (backend: null, the language per instance is in $languageMap)
    //     $languageCode  its code, for CMS:URL
    //     $languageMap   instance_id => language_id: which language is read
    //                    per instance. In the frontend the requested language for the
    //                    own instance and the readable instances that have it,
    //                    otherwise their main language. In the backend the preferred
    //                    language version of the admin (applyAdminSettings()). Empty = filled
    //                    as needed from the instance cache with the main languages of all
    //                    instances.
    //     $instance      details of the running instance (frontend, from InstanceCache)
    //     $languages     languages of the site: own instance + readable instances,
    //                    language_id => row (code, label, endonym); main language
    //                    of the own instance first. Basis of CMS:URL and
    //                    CMS:SELECT_LANGVERSION.
    public ?int   $languageId = null;
    public string $languageCode = '';
    public array  $languageMap = [];
    public array  $instance = [];
    public array  $languages = [];

    // select()
    public int $list_rows;
    // Collation of the text comparisons of the running filter() (textCollation(); '' = that
    // of the columns)
    private string $filterCollation = '';
    // Languages in which the running filter() searches (searchLanguageIds(); empty = no
    // content, full text unfolded)
    private array $filterLanguageIds = [];
    private array $selectParams = ['filter','filterop','filtermode','skip','limit','sort','order'];
    private bool $hasVirtualFields;
    public $fileSortCols = ['filename', 'mime_type', 'extension', 'size', 'width', 'height'];

    // see template parser
    public bool $returnAfterSuccess = false;
    public string $templatePath = '';
    public int $includeDepth = 0;
    // output cache of CMS:INCLUDE off (preview - Page::resolve())
    public bool $includeCacheOff = false;
    // per running CMS:INCLUDE: error counter at the start of a recording, otherwise null
    private array $includeCacheStack = [];
    // number of calls to error() - whether errors occurred during a recording
    private int $errorCount = 0;
    public int $row_no = 0;
    // row counter and template variable $cms of the enclosing result loop,
    // set aside by php/push_result.php (see pushRow())
    private array $rowStack = [];
    // resolved template references per request: "[area.]name" => "{area_id}/{name}"
    private array $templateFiles = [];

    // SCHEMA of the table record
    public array $schema = [
        'record_id' => [
            'type'  => 'integer',
            'label' => '#record.field_record_id',
            'tooltip' => '#area.tooltip_field_name',
            'flags' => ['not_empty'],
        ],
        'area_id' => [
            'type'  => 'integer', // no foreign key
            'label' => '#record.field_area_id',
            'min'   => 1
        ],
        // Website to which the record belongs. Not an input field: the value comes from
        // the content area (`area`.`instance_id`) and is fetched from there by
        // Record::hookOnSave() on every save. The copy in `record` carries the
        // foreign key to `instance` - `record` has none to `area`, so the area
        // could not maintain the assignment for its records.
        'instance_id' => [
            'type'  => 'integer', // FK to `instance`, NOT NULL -> not 'id'
            'label' => '#record.field_instance_id',
            'min'   => 0
        ],
        'online_at' => [
            'type'  => 'timestampl',
            'label' => '#record.field_online_at',
            'default' => 'now'
        ],
        'offline_at' => [
            'type'  => 'timestampl',
            'label' => '#record.field_offline_at',
            'default' => self::DATE_MAX
        ],
        'status_id' => [
            'type'  => 'integer',
            'label' => '#record.field_status_id',
            'display_type' => 'select',
            'min' => 0
        ],
        'parent_id' => [
            'type'  => 'id',
            'label' => '#record.field_parent_id',
            'display_type' => 'lookup',
        ],
        'parent_ids' => [
            'type'  => 'json',
            'label' => '#record.field_parent_ids',
        ],
        'position' => [
            'type'  => 'integer',
            'label' => '#record.field_position',
            'display_type' => 'lookup'
        ],
        'depth' => [
            'type'  => 'integer',
            'label' => '#record.field_depth',
            'min' => 0,
            'max' => 255
        ],
        'lft' => [
            'type'  => 'integer',
            'label' => '#record.field_lft',
            'min' => 0
        ],
        'rgt' => [
            'type'  => 'integer',
            'label' => '#record.field_rgt',
            'min' => 0
        ],
        'template_id' => [
            'type'  => 'id',
            'display_type' => 'select',
            'label' => '#record.field_template_id',
            'table' => 'template'
        ],
        'created_by' => [
            'type'  => 'id',
            'label' => '#record.field_created_by',
            'table' => 'admin'
        ],
        'changed_by' => [
            'type'  => 'id',
            'label' => '#record.field_changed_by',
            'table' => 'admin'
        ],
        // Owner in the backend: the admin to whom the record "belongs" - its group
        // decides whether another admin may write/delete (rights "own group" /
        // "foreign group", see Camindo\Core\Access). When creating, the session
        // admin (Dataset::save()); in the frontend the 'owner' of the CMS command;
        // changeable only with the additional right "adjust owner". FK to `admin`
        // (ON DELETE SET NULL), hence type 'id'.
        'owner_id' => [
            'type'  => 'id',
            'label' => '#record.field_owner_id',
            'display_type' => 'lookup',
            'table' => 'admin',
            'max'   => 1
        ],
        'created_at' => [
            'type'  => 'timestampl',
            'label' => '#record.field_created_at',
            'default' => 'now'
        ],
        'changed_at' => [
            'type'  => 'timestampl',
            'label' => '#record.field_changed_at',
            'default' => 'now'
        ],
        // Owner in the frontend: the profile record (area type 'profile') of the
        // user who created the record via CMS:USER_INPUT. Not an
        // input field - RecordUser sets it from the frontend session; the
        // ownership check of CMS:USER_INPUT / USER_DELETE depends on it with 'id'.
        // FK to `record` (ON DELETE SET NULL), hence type 'id' (0 -> NULL).
        'user_id' => [
            'type'  => 'id',
            'label' => '#record.field_user_id',
            'table' => 'record'
        ],
        // special field for new user-generated content
        // (CMS:USER_INPUT resp. CMS:USER_REGISTER set it to 1, see RecordUser)
        'needs_review' => [
            'type'  => 'bit',
            'label' => '#record.field_needs_review',
        ],
        'tmp' => [
            'type'  => 'bit',
        ],
        'deleted' => [
            'type'  => 'bit',
        ]
        // separately handled fields:
        // --------------------
        // (must be skipped in Cms->fetch(), otherwise they would be treated as
        //  virtual fields)
        // 'cache'     content cache joined by select() from `record_cache`
        // 'origin_id'
    ];


    public function __construct(
        private Config $config,
        private Database $db,
        private Crypt $crypt,
        private InstanceCache $instanceCache,
        private HtmlSanitizer $htmlSanitizer,
        private SecurityHeaders $securityHeaders,
        private IncludeCache $includeCache,
    )
    {

        // Defaults until the session (backend) resp. page (frontend) sets something else -
        // see class comment "Language and time zone"
        $this->setLang(self::FALLBACK_LANG);
        $this->types = array_keys($this->dataSuffix);
        $this->serverTZ = (string)$this->config->get('system.timezone');
        $this->setUserTZ($this->serverTZ);
        $this->userLocale = (string)$this->config->get('system.locale');

        // Schema - mark fields as meta fields
        foreach ($this->schema as &$val) {
            $val['meta'] = 1;
        }
        unset($val);

        // Active components - runtime lists of the ComponentManager. Each file
        // contains assignments $this->{modules|processors|extensions}['Name'] = [...];
        // a component missing there does not exist at runtime (inactive,
        // update pending, not installed - developer docs, chapter 09 (Installation and update))
        $collDir = $this->config->get('paths.var_dir') . '/config/components';
        foreach (['modules', 'processors', 'extensions'] as $kind) {
            if (is_file("$collDir/$kind.php")) include "$collDir/$kind.php";
        }

        $this->list_rows = $this->config->get('admin.skiplist_default') ?? 20;

        $this->templatePath = $this->config->get('paths.var_dir') . '/php';
    }

    /**
     * Registers an error message that occurs within a CMS command
     *
     * @param string $msg    message
     * @param string $name   column name the error message refers to, e.g. 'title'
     * @param string $label  column label the error message refers to, e.g. 'Title'
     */
    public function error(string $msg, string $name = '', string $label = ''): void
    {
        if ($name == '') {
            $this->errors[] = ['text' => $msg];
        }
        else {
            if ($label == '') $label = $name;
            $this->errors[$name] = [
                'label' => $label,
                'text' => $msg
            ];
        }
        $this->hasErrors = true;
        $this->errorCount++;
    }

    /**
     * Resets the error messages (new collection begins)
     */
    public function errorsReset(): void
    {
        $this->errors = [];
        $this->hasErrors = false;
    }

    /**
     * Builds a message from the registered errors
     *
     * @param string $separator  separator
     */
    public function errorMsg(string $separator = '<br>'): string
    {
        $arr = array();
        foreach ($this->errors as $key => $val) {
            if (is_int($key)) {
                $arr[] = $val['text'];
            }
            else {
                $arr[] = $val['label'] . $this->snippet('colon') . $val['text'];
            }
        }
        return implode($separator, $arr);
    }

    /**
     * Returns the last error message as text
     */
    public function errorMsgLast(): string
    {
        $key = array_key_last($this->errors);
        if ($key === null) return '';

        $val = $this->errors[$key];
        if (is_int($key)) {
            return $val['text'];
        }
        // else
        return $val['label'] . $this->snippet('colon') . $val['text'];
    }

    /**
     * Returns the names of the fields affected by errors
     */
    public function getErrorFields(): array
    {
        return array_filter(
            array_keys($this->errors), 
            fn($key) => !is_int($key)
        );
    }

    /**
     * Switches to the given language. Nothing is loaded - snippet() fetches the
     * cache files of the language when needed (loadSnippets()).
     * @param string $lang  language code
     */
    public function setLang($lang): void
    {
        $this->lang = $lang;
        $cacheDir = $this->config->get('paths.var_dir') . '/cache/lang/' . $this->lang;
        if (!is_dir($cacheDir)) {
            echo 'BACKEND ERROR: Snippet cache ' . htmlspecialchars($cacheDir) . ' not found!';
        }
    }

    /**
     * Include the cache files of a prefix - see SnippetCache for the structure.
     *
     * Always var/cache/lang/{lang}/{prefix}.php (texts from lang/, backend and frontend),
     * outside the frontend additionally {prefix}.backend.php (texts from backend/lang/).
     * A frontend request needs no backend texts: everything output in the frontend
     * (also error messages from Dataset/RecordUser) is in lang/.
     * A missing file counts as loaded (empty).
     *
     * @param string $lang    language code
     * @param string $prefix  prefix, e.g. 'record', 'proc.ffmpegvideo' or CORE_PREFIX
     */
    private function loadSnippets(string $lang, string $prefix): void
    {
        $this->snippetLoaded[$lang][$prefix] = true;
        $base = $this->config->get('paths.var_dir') . '/cache/lang/' . $lang . '/' . $prefix;
        $files = [$base . '.php'];
        if ($this->withBackendSnippets()) $files[] = $base . '.backend.php';
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            $snippets = (include $file);
            if (!is_array($snippets)) continue;
            $this->snippetCache[$lang][$prefix] = ($this->snippetCache[$lang][$prefix] ?? []) + $snippets;
        }
    }

    /**
     * Are the backend texts needed? In the backend (CAMINDO_BACKEND, also the
     * preview) and on the command line (cron, background processes of the backend) -
     * only a frontend request gets by without them.
     */
    private function withBackendSnippets(): bool
    {
        return $this->backendSnippets ??= (defined('CAMINDO_BACKEND') && CAMINDO_BACKEND === true)
            || PHP_SAPI === 'cli';
    }

    /**
     * Backend languages: the codes for which snippets exist (Core/lang/{code}.ini and
     * the translation packages var/translation/translated/{code}….ini, SnippetCache).
     * Basis of the selection lists in Language::backend_code and in the personal
     * settings. The list is written once by SnippetCache::build() to
     * var/cache/lang/languages.php; only if the file is missing (cache not yet
     * built) is the directory searched. Cached per request.
     *
     * @return string[]  e.g. ['de_DE', 'en_US'] - FALLBACK_LANG is always included
     */
    public function backendLanguages(): array
    {
        if ($this->backendLanguages !== null) return $this->backendLanguages;
        $file = $this->config->get('paths.var_dir') . '/cache/lang/' . SnippetCache::LANGUAGES_FILE;
        $langs = is_file($file) ? (include $file) : null;
        if (!is_array($langs) || !$langs) {
            $langs = self::scanLanguages($this->config->get('paths.core_dir') . '/lang', SnippetCache::translatedDir($this->config));
        }
        return $this->backendLanguages = $langs;
    }

    /**
     * Language codes of a lang directory (file names {code}.ini in the format
     * LANG_PATTERN), optionally also those of a directory with
     * translation packages ({code}.ini, {code}_….ini - SnippetCache::translatedLanguage()),
     * sorted, FALLBACK_LANG always included. Also for SnippetCache, which is meant to
     * get by without Cms.
     *
     * @return string[]
     */
    public static function scanLanguages(string $dir, ?string $translatedDir = null): array
    {
        $codes = [];
        foreach ((array)glob($dir . '/*.ini') as $file) {
            $code = basename($file, '.ini');
            if (preg_match(self::LANG_PATTERN, $code)) $codes[$code] = true;
        }
        if ($translatedDir !== null) {
            foreach ((array)glob($translatedDir . '/*.ini') as $file) {
                $code = SnippetCache::translatedLanguage($file);
                if ($code !== null) $codes[$code] = true;
            }
        }
        $codes[self::FALLBACK_LANG] = true;
        $codes = array_keys($codes);
        sort($codes);
        return $codes;
    }

    /**
     * Derive the backend language from a code - falling back to FALLBACK_LANG.
     *
     * Accepts backend codes ("de_DE"), locale specifications ("de_AT", "fr-FR") and the
     * earlier two-letter codes ("de" in `language`.`backend_code` and
     * `admin`.`settings`): without an exact match the language part counts - the
     * first backend language with this language part applies ("de_AT" -> "de_DE").
     * If there are no snippets for it, FALLBACK_LANG applies.
     *
     * @param string $code  backend code or locale
     */
    public function resolveBackendLang(string $code): string
    {
        return self::matchLanguage($code, $this->backendLanguages()) ?? self::FALLBACK_LANG;
    }

    /**
     * Map a code or locale to one of the available languages (see
     * resolveBackendLang()): exact, otherwise via the language part; null without a match.
     *
     * @param string   $code       e.g. "de_DE", "de-at", "de"
     * @param string[] $available  codes in the format LANG_PATTERN, sorted
     */
    public static function matchLanguage(string $code, array $available): ?string
    {
        $parts = preg_split('/[-_]/', trim($code));
        $base = strtolower($parts[0] ?? '');
        if ($base === '') return null;
        $region = strtoupper((string)end($parts));
        if (count($parts) > 1 && in_array($base . '_' . $region, $available, true)) return $base . '_' . $region;
        foreach ($available as $lang) {
            if (str_starts_with($lang, $base . '_')) return $lang;
        }
        return null;
    }

    /**
     * Is the value a valid time zone (PHP identifier such as "Europe/Berlin")?
     */
    public function isValidTimezone(string $timezone): bool
    {
        return $timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Set the user time zone - for PHP and the database connection.
     *
     * Source per context:
     *     Backend    personal setting of the admin (applyAdminSettings())
     *     Frontend   time zone of the content language of the request (setLanguage())
     *     otherwise  system.timezone from the configuration (constructor)
     *
     * An empty or unknown value is skipped - the previous one remains.
     *
     * @param string $timezone  PHP identifier, e.g. 'Europe/Berlin'
     * @return bool             true: taken over (or already set)
     */
    public function setUserTZ(string $timezone): bool
    {
        $timezone = trim($timezone);
        if (!$this->isValidTimezone($timezone)) return false;
        if ($timezone === $this->userTZ) return true;
        $this->userTZ = $timezone;
        date_default_timezone_set($timezone);
        // The identifier is checked against listIdentifiers() and may go literally into the query
        $this->db->query("SET time_zone = '{$timezone}'");
        return true;
    }

    /**
     * Is the value an ICU locale with region (e.g. "de_AT", "zh_Hans_CN")? Only
     * such ones are available for selection - the region alone determines the date format.
     */
    public function isValidLocale(string $locale): bool
    {
        return $locale !== '' && in_array($locale, self::localeIdentifiers(), true);
    }

    /**
     * ICU locales with region that this PHP installation knows - basis of the
     * selection lists (Backend::localeOptions()) and of the check of stored values.
     *
     * @return string[]
     */
    public static function localeIdentifiers(): array
    {
        static $list = null;
        if ($list !== null) return $list;
        $list = [];
        if (class_exists(\ResourceBundle::class)) {
            foreach ((array)\ResourceBundle::getLocales('') as $locale) {
                if (\Locale::getRegion($locale) !== '') $list[] = $locale;
            }
        }
        return $list;
    }

    /**
     * Set the locale of the formats (\Locale::getDefault() for IntlDateFormatter and
     * NumberFormatter). An empty or unknown value is skipped - system.locale
     * from the configuration (Config) remains.
     *
     * @param string $locale  ICU locale, e.g. 'de_AT'
     * @return bool           true: taken over (or already set)
     */
    public function setUserLocale(string $locale): bool
    {
        $locale = trim($locale);
        if (!$this->isValidLocale($locale)) return false;
        if ($locale === $this->userLocale) return true;
        $this->userLocale = $locale;
        \Locale::setDefault($locale);
        setlocale(LC_TIME, $locale . '.utf8');
        return true;
    }

    /**
     * Take over the personal settings of an admin (`admin`.`settings`):
     * backend language, locale and time zone. Called by Session as soon as the
     * session of an admin is loaded or created - so everything applies for the
     * whole request.
     *
     * @param array $settings  ['backend_code' => 'de', 'locale' => 'de_AT',
     *                          'timezone' => 'Europe/Berlin', ...]
     */
    public function applyAdminSettings(array $settings): void
    {
        $code = (string)($settings['backend_code'] ?? '');
        if ($code !== '') $this->setLang($this->resolveBackendLang($code));
        $this->setUserLocale((string)($settings['locale'] ?? ''));
        $this->setUserTZ((string)($settings['timezone'] ?? ''));

        // Preferred language version: content language of the backend. As in the frontend
        // (Page::resolveLanguage()) the preferred language per instance, if the
        // instance has it, otherwise its main language - from then on select() and
        // fetch() deliver lists, search and detail view in this language. $languageId
        // stays null: the backend has no language "of the call" (CMS:URL & Co.).
        $preferred = (string)($settings['language_id'] ?? '');
        if ($preferred !== '' && ctype_digit($preferred)) {
            $this->languageMap = [];
            foreach ((array)($this->instanceCache->all()['instance_ids'] ?? []) as $instanceId) {
                $ids = $this->instanceCache->languageIds((int)$instanceId);
                $this->languageMap[(int)$instanceId] = in_array((int)$preferred, $ids, true)
                    ? (int)$preferred
                    : (int)($ids[0] ?? $this->instanceCache->primaryLanguageId((int)$instanceId));
            }
        }
    }

    /**
     * Restrict the content language of the backend to the readable languages of the user group
     * (Session::applyAccess(), developer docs, chapter 11 (Permissions)): if an
     * instance does not have the preferred language readable, the first readable
     * language in the order of the instance applies there; if none is readable, the
     * previous one remains (the lists then show the main language - the contents of this
     * instance cannot be edited by the group anyway).
     *
     * @param int[] $readableLanguageIds  Access::readableLanguageIds()
     */
    public function restrictLanguageMap(array $readableLanguageIds): void
    {
        foreach ((array)($this->instanceCache->all()['instance_ids'] ?? []) as $instanceId) {
            $instanceId = (int)$instanceId;
            $ids = $this->instanceCache->languageIds($instanceId);
            $current = (int)($this->languageMap[$instanceId] ?? ($ids[0] ?? $this->instanceCache->primaryLanguageId($instanceId)));
            if (in_array($current, $readableLanguageIds, true)) {
                $this->languageMap[$instanceId] = $current;
                continue;
            }
            foreach ($ids as $languageId) {
                if (in_array($languageId, $readableLanguageIds, true)) {
                    $current = $languageId;
                    break;
                }
            }
            $this->languageMap[$instanceId] = $current;
        }
    }

    /**
     * Frontend editing switched on? Decided by the cookie FED_COOKIE:
     *     1  on until the browser is closed resp. until logout
     *     2  on beyond that as well
     * Anything else means off. The cookie only enables the output of the links -
     * the backend itself still requires login.
     */
    public function fedActive(): bool
    {
        // signed value (FrontendHandover::fedCookieValue()) - a manually set
        // "fed=1" shows nothing, otherwise cFED() would reveal the backend domain
        return FrontendHandover::fedModeOf($this->crypt, (string)($_COOKIE[self::FED_COOKIE] ?? '')) > 0;
    }

    /**
     * Directory of an active component (from the runtime list), null if it
     * does not exist at runtime.
     *
     * @param string $kind  'modules', 'processors', 'extensions'
     */
    public function componentPath(string $kind, string $name): ?string
    {
        $path = $this->{$kind}[$name]['path'] ?? null;
        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Returns the currently set language
     * @return string   language code
     */
    public function getLang(): string
    {
        // Never returns an empty string - see __construct()
        return $this->lang;
    }

    /**
     * Writing direction of a language from right to left? The language part counts
     * (RTL_LANGUAGES): "ar_EG" or "ar-EG" applies like "ar_SA".
     *
     * @param string $lang  backend code or locale, empty = current language (getLang())
     */
    public function isRtl(string $lang = ''): bool
    {
        if ($lang === '') $lang = $this->lang;
        $base = strtolower((string)preg_split('/[-_]/', trim($lang))[0]);
        if ($base === '') return false;
        foreach (self::RTL_LANGUAGES as $code) {
            if (str_starts_with($code, $base . '_')) return true;
        }
        return false;
    }

    /**
     * Value for the dir attribute: 'rtl' or 'ltr' (see isRtl())
     */
    public function textDirection(string $lang = ''): string
    {
        return $this->isRtl($lang) ? 'rtl' : 'ltr';
    }

    /**
     * Returns a translation (language snippet)
     *
     * @param string $index  index in the format {module-name.}snippet-index
     * @param array $params  parameters for the ICU message format
     * @param string $lang   language code, e.g. 'en_US'
     * @return string
     */
    public function snippet(string $index, array $params = [], string $lang = ''): string
    {
        if ($index === '') return '';
        if ($lang === '') $lang = $this->lang;

        // Prefix = everything before the last dot (keys contain no dots),
        // without a dot: core snippet
        $pos = strrpos($index, '.');
        if ($pos === false) {
            $prefix = self::CORE_PREFIX;
            $key = $index;
        }
        else {
            $prefix = substr($index, 0, $pos);
            $key = substr($index, $pos + 1);
            // Short form custom.{key}: snippet set of the current instance (module
            // Snippets, prefix custom.{instance_id}) - in the frontend after
            // setLanguage(); without instance context it falls back to $index
            if ($prefix === SnippetCache::CUSTOM_PREFIX && !empty($this->instance['instance_id'])) {
                $prefix .= '.' . (int)$this->instance['instance_id'];
            }
        }

        // Include the cache files of the prefix on first access
        if (!isset($this->snippetLoaded[$lang][$prefix])) {
            $this->loadSnippets($lang, $prefix);
        }
        $text = $this->snippetCache[$lang][$prefix][$key] ?? '';

        // Fallback $index
        if ($text === '') return $index;

        // ICU Message Format
        if ($text instanceof \Closure) {
            $text = $text($params);
        }
        return $text;
    }

    /**
     * Call the API from a template, trigger template or custom tool
     * (Camindo\Core\Api\Api::call()).
     *
     * If an API session is already running (scheduler, CLI, PHP API), it applies. Otherwise the
     * call works within the loaded backend session (ApiSession::attach(), channel
     * 'tool') - e.g. a trigger template that runs when saving in the backend: it
     * acts as the admin who saves. Without an admin session (frontend) the
     * API answers with not_logged_in.
     *
     * @param string $method  e.g. 'record.search'
     * @param array  $params  parameters (snake_case)
     * @return array          ['ok' => bool, 'data' => …] resp. ['ok' => false, 'error' => …, 'message' => …]
     */
    public function api(string $method, array $params = []): array
    {
        $app = $GLOBALS['app'];
        $context = $app->get(\Camindo\Core\Api\ApiContext::class);
        $apiSession = $app->get(\Camindo\Core\Api\ApiSession::class);
        $attached = false;
        if (!$context->active() && $context->interface() === 'backend') {
            try {
                $apiSession->attach('tool');
                $attached = true;
            }
            catch (\Camindo\Core\Api\ApiException) {
                // Api::call() reports not_logged_in
            }
        }
        try {
            return $app->get(\Camindo\Core\Api\Api::class)->call($method, $params);
        }
        finally {
            if ($attached) $apiSession->logout();
        }
    }

    /**
     * Convert label, starting with '#' into snippet text
     * @param string $text  text, or '#' + snippet id
     */
    public function textOrSnippet(string $text): string
    {
        if (empty($text)) return '';
        if (substr($text,0,1) == '#') {
            return $this->snippet(substr($text,1));
        }
        else return $text;
    }

    /**
     * Removes the given parameters from the URL and prepares it for new parameters.
     *
     * Parameter format: 'formvars' also matches 'formvars[myfield]'
     *
     * @param string|array $params  parameters to remove
     * @param string       $uri     URL (default: current REQUEST_URI)
     * @return string               cleaned URL, ends with '?' or '&'
     */
    public function sys_reduce_uri(string|array $params = [], string $uri = ''): string
    {
        if (!is_array($params)) {
            $params = [$params];
        }

        if (empty($uri)) {
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
        }

        if (defined('CAMINDO_BACKEND') && CAMINDO_BACKEND === true) {
            // in the backend additionally remove the parameter cache
            $params[] = 'cache';
            // and remove the path to modules - see JavaScript cLoadContent()
            $prefix = $this->config->get('paths.cms') . '/Modules';
            if (str_starts_with($uri, $prefix)) {
                $uri = substr($uri, strlen($prefix));
            }
        }

        /* remove whole arrays $a[...] */
        foreach ($params as $param) {
            $param = preg_quote($param, '!');
            $uri = preg_replace(
                "!([?&])&*{$param}(\[[^\]]*\])*=[^?&=]*!",
                '$1',
                $uri
            );
        }

        // Clean up repeated ?, & and combinations, and remove trailing separators
        $uri = preg_replace(['!&+!', '!&?\\?+!', '!\\?&!', '![?&]+$!'], ['&', '?', '?', ''], $uri);

        // Prepare the URL for appending new parameters
        return str_contains($uri, '?') ? $uri . '&' : $uri . '?';
    }

    /**
     * Converts the first letter of $string to uppercase
     * @param string $string
     */
    public function mb_ucfirst(string $string): string {
        $firstChar = mb_substr($string, 0, 1, 'utf-8');
        $rest = mb_substr($string, 1, null, 'utf-8');
        return mb_strtoupper($firstChar, 'utf-8') . $rest;
    }

    /**
     * Prepares text for the full-text search - when saving (data_ftx) and for the
     * search terms (buildFulltextCondition()).
     *
     * Hyphen and underscore separate words; when saving, the run-together variant additionally
     * goes into the index ("sofa-weiss" -> "sofa weiss
     * sofaweiss"). So "sofaweiss", "sofa-weiss" (word sequence) and "weiss"
     * find the same text, also "EMail" finds a stored "E-Mail". Search terms
     * ($forSearch) are not doubled - there buildFulltextCondition() turns
     * several words into a word sequence.
     *
     * With $languageId the text is folded according to the rules of the language
     * (Collation::fold(): in German "Müller" -> "mueller", neutral "muller") -
     * `data_ftx` and the search terms on it; text and search term need the same
     * language.
     *
     * @param string   $text        the text to convert
     * @param bool     $forSearch   true: search term (without the run-together variant)
     * @param int|null $languageId  language by whose rules it is folded; null = do not fold
     */
    public function indexText(string $text, bool $forSearch = false, ?int $languageId = null): string {
        // HTML tags (rich-text fields) are not words: replace with spaces,
        // so that "Wort</p><p>Wort" remains two words
        $text = preg_replace('/<[^>]*>/', ' ', $text) ?? $text;

        // decode HTML entities correctly (IMPORTANT: use | instead of &)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // join soft hyphens (&shy;, decoded \u{00AD}) - they only stand
        // for hyphenation within the word
        $text = str_replace("\u{00AD}", '', $text);

        // words joined by hyphen or underscore: both separated AND run together
        // (hyphen, typographic hyphen U+2010/U+2011, underscore; not the dot -
        // "foto.jpg" should not yield "fotojpg")
        if (!$forSearch) {
            $text = preg_replace_callback(
                '/[\p{L}\p{N}]+(?:[-_\x{2010}\x{2011}]+[\p{L}\p{N}]+)+/u',
                fn(array $m) => $m[0] . ' ' . preg_replace('/[-_\x{2010}\x{2011}]+/u', '', $m[0]),
                $text
            ) ?? $text;
        }

        // Replace everything except letters, digits, and the characters @$[]{} (coding) with ONE
        // space (\p{L} = letters, \p{N} = digits). The + merges
        // several characters - also the separators of the separated variant above.
        $text = preg_replace('/[^\p{L}\p{N}@$\[\]\{\}]+/u', ' ', $text) ?? $text;
        $text = trim($text);

        if ($languageId !== null) $text = Collation::fold($text, $this->instanceCache->languageCode($languageId));
        return $text;
    }

    /**
     * Normalize the search value of an encrypted field (data_enc.hash) - when
     * saving (VirtualFieldsTrait::saveVirtualFields()) and for the search term
     * (buildEncryptedCondition(), RecordUser): indexText() as search term (without
     * language), lowercase and without spaces. Punctuation and whitespace
     * therefore do not count: "Anna.Muster@Example.com" finds "anna.muster@example.com",
     * "+49 171 123-456" finds "49171123456" (but not "0049171123456" - the
     * digits themselves stay as they are).
     *
     * If nothing remains (only punctuation), the result is '' - like an empty value.
     */
    public function encryptedSearchText(string $value): string
    {
        return str_replace(' ', '', mb_strtolower($this->indexText($value, true), 'UTF-8'));
    }

    /**
     * HMAC of the normalized value (encryptedSearchText()) for data_enc.hash - binary,
     * 32 bytes; '' for an empty result.
     */
    public function encryptedHash(string $value): string
    {
        return $this->crypt->hash($this->encryptedSearchText($value), true);
    }

    /**
     * Convert any text into a slug (URL path part).
     *
     * The slash deliberately stays: a slug may be multi-level
     * ("products/hiking-boots"), the rewrite rule passes the whole path on as
     * `request`. Umlauts and other letters are also kept -
     * \w also covers them under the u modifier.
     *
     * Everything else becomes a hyphen; double hyphens, those directly
     * at the slash and at the start/end are removed again.
     *
     *     "Über uns / Team & Co."   ->  "über-uns/team-co"
     *
     * @param string $text   source text, usually the content of the default field
     * @param int    $limit  maximum length; 0 = default from system.link_length
     * @return string        slug in lowercase
     * @see Camindo\Core\Modules\Record\Record::hookOnSave()  einziger Aufrufer
     */
    public function textToUri(string $text, int $limit = 0): string
    {
        if ($limit < 1) $limit = (int)$this->config->get('system.link_length');
        if ($limit < 1) $limit = 120;

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Remove tags, line breaks become separators
        $text = $this->_nl2br($this->_strip_tags($text), '-');
        // Everything except word characters and slash to '-' (\w also covers digits and _)
        $text = preg_replace('![^\w/]!u', '-', $text);
        // Remove hyphens at the slash
        $text = preg_replace('!-?/-?!', '/', $text);
        // Merge repeated hyphens resp. slashes - an empty path segment
        // ("a//b") would be a different URL than "a/b"
        $text = preg_replace('!--+!', '-', $text);
        $text = preg_replace('!//+!', '/', $text);
        // Truncate
        if (mb_strlen($text, 'UTF-8') > $limit) $text = mb_substr($text, 0, $limit, 'UTF-8');
        // Remove separators at the start and end (truncating can create new ones)
        $text = trim($text, '-/');

        return mb_strtolower($text, 'UTF-8');
    }

    // ====================

    /**
     * Set access to instances - counterpart to $sqlAccess.
     *
     * Builds the SQL expression from a list of ids, so that it does not have to be
     * assembled by hand (and no foreign input gets into it):
     *
     *     []        -> ''                       no restriction
     *     [5]       -> '`instance_id` = 5'
     *     [5,7]     -> '`instance_id` IN (5,7)'
     *
     * @param array $instanceIds  instance_ids that may be accessed.
     *                            An empty array lifts the restriction.
     */
    public function setInstanceAccess(array $instanceIds): void
    {
        $ids = [];
        foreach ($instanceIds as $id) {
            $id = (int)$id;
            if ($id < 1) continue;
            if (in_array($id, $ids, true)) continue;
            $ids[] = $id;
        }
        $this->accessInstanceIds = $ids;
        if (empty($ids)) {
            $this->sqlAccess = '';
        }
        elseif (count($ids) === 1) {
            $this->sqlAccess = '`instance_id` = ' . $ids[0];
        }
        else {
            $this->sqlAccess = '`instance_id` IN (' . implode(',', $ids) . ')';
        }
    }

    /**
     * Access to NO instance - for a user group without an assigned instance
     * (Session::applyAccess()). setInstanceAccess([]) would mean "no restriction",
     * hence a separate expression that no row satisfies; areaMap() is empty.
     */
    public function setInstanceAccessNone(): void
    {
        $this->accessInstanceIds = [0];
        $this->sqlAccess = '`instance_id` = 0';
    }

    /**
     * Set access to areas - counterpart to $sqlAreaAccess (backend: the
     * readable areas of the user group, Session::applyAccess()).
     *
     *     null  -> ''                        no restriction
     *     []    -> '`area_id` = 0'           no area
     *     [5,7] -> '`area_id` IN (5,7)'
     *
     * @param int[]|null $areaIds
     */
    public function setAreaAccess(?array $areaIds): void
    {
        if ($areaIds === null) {
            $this->sqlAreaAccess = '';
            return;
        }
        $ids = [];
        foreach ($areaIds as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
        }
        $this->sqlAreaAccess = empty($ids) ? '`area_id` = 0' : '`area_id` IN (' . implode(',', $ids) . ')';
    }

    /**
     * Instances this request may access (setInstanceAccess()) -
     * its own first. Empty = no restriction (backend), [0] = no instance
     * (setInstanceAccessNone()).
     *
     * @return int[]
     */
    public function accessInstanceIds(): array
    {
        return $this->accessInstanceIds;
    }

    /**
     * May this request see contents of the given instance?
     * Without restriction (backend) always yes.
     */
    public function instanceAllowed(int $instanceId): bool
    {
        return empty($this->accessInstanceIds) || in_array($instanceId, $this->accessInstanceIds, true);
    }

    /**
     * PUBLISHED - the condition under which the frontend shows a record.
     *
     * A record is published if it is released (`status_id` = 0), its publication date
     * has been reached (`online_at` <= now) and its end has not yet been reached
     * (`offline_at` > now; DATE_MAX = no end). Compared in UTC -
     * that is how the columns are stored, and UTC_TIMESTAMP() is independent of the
     * time zone of the connection (setUserTZ()).
     *
     * Three parameters relax the condition - the names are those of CMS:SELECT:
     *
     *     future    'true'/true  ->  scheduled records as well (no online_at condition)
     *     archive   'true'/true  ->  expired records as well (no offline_at condition)
     *     state     (int) > 0    ->  instead of released: records with exactly this status
     *               'any'        ->  every status
     *
     * Used by cmsSelect() (every query of a template) and by Page (the record called via
     * the URL, there with archive: an expired record remains reachable
     * under its address, it only no longer appears in any list). The backend calls
     * select() directly and sees everything.
     *
     * The expression goes literally into the query (no bind parameter) - it contains
     * only column names, functions and an integer.
     *
     * @param array  $p      parameters (future, archive, state) - other keys
     *                       are ignored, so the whole parameter array
     *                       of a command can be passed
     * @param string $table  table alias with which the columns are qualified
     *                       (select() joins `record` a second time for sorting,
     *                       unqualified the names would be ambiguous)
     * @return string        SQL condition without leading AND; '' if all three
     *                       parts are switched off (future, archive, state=any)
     */
    public function publishedSql(array $p = [], string $table = 'record'): string
    {
        $parts = [];
        if (!$this->_bool($p['future'] ?? false)) {
            $parts[] = "`$table`.`online_at` <= UTC_TIMESTAMP()";
        }
        if (!$this->_bool($p['archive'] ?? false)) {
            $parts[] = "`$table`.`offline_at` > UTC_TIMESTAMP()";
        }
        $state = $p['state'] ?? '';
        if (!is_scalar($state) || strtolower(trim((string)$state)) !== 'any') {
            // '' and everything <= 0 means: released (status 0)
            $parts[] = "`$table`.`status_id` = " . max(0, (int)$state);
        }
        return implode(' AND ', $parts);
    }

    /**
     * Set the content language of the running request (frontend).
     *
     * @param int    $languageId    requested language
     * @param string $languageCode  its code (for CMS:URL)
     * @param array  $languageMap   instance_id => language_id for all readable
     *                              instances; empty = only the own instance
     * @param array  $instance      details of the running instance (InstanceCache)
     * @param array  $languages     languages of the site (InstanceCache::languagesOf());
     *                              empty = those of the instance
     */
    public function setLanguage(int $languageId, string $languageCode, array $languageMap = [], array $instance = [], array $languages = []): void
    {
        $this->languageId   = $languageId;
        $this->languageCode = $languageCode;
        $this->languageMap  = [];
        foreach ($languageMap as $instanceId => $langId) {
            $this->languageMap[(int)$instanceId] = (int)$langId;
        }
        $this->instance  = $instance;
        $this->languages = $languages ?: (array)($instance['languages'] ?? []);

        // Time zone and snippet language of the content language (`language`.`timezone`,
        // `language`.`backend_code`, both in the instance cache). Without a time zone on the
        // language system.timezone applies; without a backend code the fallback FALLBACK_LANG applies.
        $language = (array)($this->languages[$languageId] ?? []);
        $timezone = (string)($language['timezone'] ?? '');
        $this->setUserTZ($timezone !== '' ? $timezone : $this->serverTZ);
        $this->setLang($this->resolveBackendLang((string)($language['backend_code'] ?? '')));
        // Date and time formats (CMS:DATE, CMS:DATETIME …) in the content language: the
        // language code, otherwise the backend code. It is enough that ICU knows the language -
        // ICU lists e.g. zh_Hans_CN, but formats just as well with zh_CN; a code like en_GB as en.
        // If ICU knows neither, system.locale remains.
        foreach ([$languageCode, (string)($language['backend_code'] ?? '')] as $locale) {
            if ($this->isKnownLanguage($locale)) {
                $this->userLocale = $locale;
                \Locale::setDefault($locale);
                setlocale(LC_TIME, $locale . '.utf8');
                break;
            }
        }
    }

    /** Does ICU know the language of the code (de_AT -> de, zh_CN -> zh)? */
    private function isKnownLanguage(string $locale): bool
    {
        static $languages = null;
        $language = strtolower((string)\Locale::getPrimaryLanguage(trim($locale)));
        if ($language === '' || !preg_match('/^[a-z]{2,3}$/', $language)) return false;
        if ($languages === null) {
            $languages = [];
            foreach (self::localeIdentifiers() as $known) $languages[(string)\Locale::getPrimaryLanguage($known)] = true;
        }
        return isset($languages[$language]);
    }

    /**
     * Language in which a record is read.
     *
     * With language context (frontend) the language from $languageMap for the instance of the
     * record, otherwise the requested language. Without language context (backend) the
     * main language of the instance of the record.
     *
     * @param int $instanceId  `record`.`instance_id`
     * @return int             language_id
     */
    public function recordLanguageId(int $instanceId): int
    {
        if (isset($this->languageMap[$instanceId])) return $this->languageMap[$instanceId];
        if ($this->languageId !== null) return $this->languageId;
        return $this->instanceCache->primaryLanguageId($instanceId);
    }

    /**
     * SQL expression for the `language_id` with which the data_* tables and
     * `record_cache` of a record are joined.
     *
     * Yields a number if the same language applies to all instances, otherwise a
     * CASE expression over the `instance_id` of the record:
     *
     *     3
     *     CASE `record`.`instance_id` WHEN 1 THEN 3 WHEN 23 THEN 0 ELSE 3 END
     *
     * The expression goes literally into the query (only numbers, no bind parameters) and
     * can thus also be used in ON conditions and EXISTS subqueries.
     *
     * @param string $recordAlias  alias of the `record` table whose instance_id counts
     */
    public function languageExpr(string $recordAlias = 'record'): string
    {
        // Language context without assignment per instance: the requested language everywhere
        if ($this->languageId !== null && empty($this->languageMap)) {
            return (string)$this->languageId;
        }
        $map = $this->languageMap;
        if (empty($map)) {
            // No language context: main language of each instance
            $map = $this->instanceCache->primaryLanguages();
        }
        $default = $this->languageId ?? (int)(reset($map) ?: 0);

        $distinct = array_unique(array_values($map));
        if (count($distinct) <= 1 && (empty($distinct) || (int)reset($distinct) === $default)) {
            return (string)$default;
        }
        $sql = "CASE `{$recordAlias}`.`instance_id`";
        foreach ($map as $instanceId => $languageId) {
            $sql .= ' WHEN ' . (int)$instanceId . ' THEN ' . (int)$languageId;
        }
        return $sql . ' ELSE ' . $default . ' END';
    }

    /**
     * Collation for text comparisons and sorting of a query on `record`: that of the
     * language being searched (Camindo\Core\Collation, in German e.g.
     * ä = ae and ß = ss).
     *
     * With language context (frontend) this is the requested language. Without (backend)
     * each instance has its own language ($languageMap); then the instances
     * of the areas from filter[area_id] count, without area filter all. If they lead to
     * different collations, the collation of the columns remains - a COLLATE applies to
     * the whole query.
     *
     * @param int[] $languageIds  searchLanguageIds()
     * @return string            collation; '' = that of the text columns, no COLLATE needed
     */
    private function textCollation(array $languageIds): string
    {
        $collations = [];
        foreach ($languageIds as $languageId) $collations[$this->instanceCache->collation($languageId)] = true;
        return count($collations) === 1 ? (string)array_key_first($collations) : '';
    }

    /**
     * Languages in which a query on `record` searches: with language context
     * (frontend) the requested one, otherwise (backend) the language of each instance
     * ($languageMap) - of the instances of the areas from filter[area_id], without
     * area filter all.
     *
     * @param array $p  parameters of select() (evaluated: filter[area_id])
     * @return int[]    language_ids, without duplicates
     */
    private function searchLanguageIds(array $p): array
    {
        if ($this->languageId !== null) return [$this->languageId];

        $instanceIds = [];
        foreach ($this->normalizeValues($p['filter']['area_id'] ?? []) as $value) {
            $value = ltrim($value, '=');
            if (ctype_digit($value)) $instanceIds[] = $this->instanceCache->areaInstanceId((int)$value);
        }
        $instanceIds = array_filter(array_unique($instanceIds));
        if (!$instanceIds) {
            $instanceIds = array_keys($this->languageMap ?: $this->instanceCache->primaryLanguages());
        }
        $languageIds = [];
        foreach ($instanceIds as $instanceId) $languageIds[$this->recordLanguageId((int)$instanceId)] = true;
        return array_keys($languageIds);
    }

    /** Column expression with the collation of the running filter() */
    private function collate(string $colExpr): string
    {
        return $this->filterCollation === '' ? $colExpr : "$colExpr COLLATE {$this->filterCollation}";
    }

    /**
     * Is a field maintained multilingually?
     *
     * `field`.`multilingual` = 1 and a type that can do it: the types from
     * $multilingualTypes as well as 'file' (upload fields and their thumbnails - thumbnails
     * inherit the flag from the upload field, see Field::hookOnSave()).
     *
     * @param array $colDef  field definition (virtualSchema())
     */
    public function isMultilingualField(array $colDef): bool
    {
        if (empty($colDef['multilingual'])) return false;
        $type = (string)($colDef['type'] ?? '');
        return $type === 'file' || in_array($type, $this->multilingualTypes, true);
    }

    /**
     * File name of a media file without extension, as it lies under media/{field_id}/.
     *
     *     {record_id}                  single-language file field
     *     {record_id}_{language_id}    multilingual file field: the file of THIS language
     *
     * `data_fil`.`file_language_id` says per row which file is meant: NULL for
     * single-language fields, otherwise the language of the physical file - for a
     * language without its own file that of the main language (fallback).
     *
     * @param int      $recordId        record id (resp. id of the working copy)
     * @param int|null $fileLanguageId  null = single-language
     */
    public function mediaBaseName(int $recordId, ?int $fileLanguageId): string
    {
        return $fileLanguageId === null ? (string)$recordId : $recordId . '_' . $fileLanguageId;
    }

    /**
     * File name of a media file with extension, see mediaBaseName().
     */
    public function mediaFileName(int $recordId, ?int $fileLanguageId, string $extension): string
    {
        return $this->mediaBaseName($recordId, $fileLanguageId) . '.' . $extension;
    }

    // MEDIA STORAGE
    // --------------------
    // The files of an upload/thumbnail field lie under
    //
    //     {paths.media_dir}/{instance_id}/{field_id}/{file name}
    //
    // The instance_id is that of the content area to which the field belongs
    // (`area`.`instance_id`) - the same one that every record of the area carries in
    // `record`.`instance_id`. The instance directory carries an .htaccess that
    // restricts access to the domains of the instance and its reading instances
    // (Camindo\Core\MediaAccess); a field with 'user_auth' gets a second .htaccess
    // below it that blocks every direct access - such files are delivered exclusively
    // by public/file.php.
    //
    // Whoever assembles a path ALWAYS goes via mediaFolder() / mediaPath() -
    // only this way does the storage move along at one point when the instance of the
    // area changes (Area::hookOnSaved()).

    /** instance_id per field_id, read once per request (see fieldInstanceId()) */
    private array $fieldInstanceCache = [];

    /**
     * Instance to which an input field belongs (`area`.`instance_id` of its area).
     *
     * @param int $fieldId
     * @return int  instance_id; 0 if the field does not exist
     */
    public function fieldInstanceId(int $fieldId): int
    {
        if ($fieldId < 1) return 0;
        if (isset($this->fieldInstanceCache[$fieldId])) return $this->fieldInstanceCache[$fieldId];
        $this->db->pushResult();
        $this->db->query(
            "SELECT `a`.`instance_id` FROM `field` `f`"
            . " INNER JOIN `area` `a` ON `a`.`area_id` = `f`.`area_id`"
            . " WHERE `f`.`field_id` = ?",
            'i',
            [$fieldId]
        );
        $instanceId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        $this->fieldInstanceCache[$fieldId] = $instanceId;
        return $instanceId;
    }

    /**
     * Forget the mapping field_id -> instance_id - after an instance change
     * of an area in the same request (Area::hookOnSaved()).
     */
    public function clearFieldInstanceCache(): void
    {
        $this->fieldInstanceCache = [];
    }

    /** details.user_auth je field_id, einmal je Request gelesen */
    private array $fieldUserAuthCache = [];

    /**
     * Is a file field marked as "only for logged-in users"
     * (field.details.user_auth)? Then CMS:MEDIA links to file.php instead of into the
     * media directory, and MediaAccess keeps the field directory locked.
     */
    public function isUserAuthField(int $fieldId): bool
    {
        if ($fieldId < 1) return false;
        if (isset($this->fieldUserAuthCache[$fieldId])) return $this->fieldUserAuthCache[$fieldId];
        $this->db->pushResult();
        $this->db->query("SELECT `details` FROM `field` WHERE `field_id` = ?", 'i', [$fieldId]);
        $details = json_decode((string)($this->db->fetchNum()[0] ?? ''), true) ?: [];
        $this->db->popResult();
        return $this->fieldUserAuthCache[$fieldId] = !empty($details['user_auth']);
    }

    /**
     * Directory of a field from paths.media_dir resp. paths.media, without slashes
     * at the ends: "{instance_id}/{field_id}".
     *
     * @param int $fieldId
     * @param int $instanceId  instance, if already known (saves the query);
     *                         0 = determine from the area of the field
     */
    public function mediaFolder(int $fieldId, int $instanceId = 0): string
    {
        if ($instanceId < 1) $instanceId = $this->fieldInstanceId($fieldId);
        return $instanceId . '/' . $fieldId;
    }

    /**
     * Path of a media file from paths.media_dir resp. paths.media:
     * "{instance_id}/{field_id}/{record_id}[_{language_id}].{ext}" (see
     * mediaFileName()).
     *
     * @param int      $fieldId
     * @param int      $recordId        record id (resp. id of the working copy)
     * @param int|null $fileLanguageId  null = single-language
     * @param string   $extension
     * @param int      $instanceId      instance, if already known
     */
    public function mediaPath(int $fieldId, int $recordId, ?int $fileLanguageId, string $extension, int $instanceId = 0): string
    {
        return $this->mediaFolder($fieldId, $instanceId) . '/' . $this->mediaFileName($recordId, $fileLanguageId, $extension);
    }

    /**
     * Media path with a meaningful name for the URL: the original file name
     * (without extension, as slug) is placed with a comma before the extension -
     *
     *     1/12/1977.jpg  +  "Strand Nord.jpg"   ->  1/12/1977,strand-nord.jpg
     *
     * The RewriteRule "CAMINDO MEDIA" in public/.htaccess removes ",{name}" again,
     * without checking it; the file itself is still called 1977.jpg. Without a name
     * the path stays unchanged.
     *
     * @param string $mediaPath  path from mediaPath()
     * @param string $filename   original file name (data_fil.filename)
     * @param string $extension  extension of the file
     */
    public function mediaUriName(string $mediaPath, string $filename, string $extension): string
    {
        $name = trim($filename);
        if ($extension !== '' && strtolower(substr($name, -strlen($extension) - 1)) === '.' . strtolower($extension)) {
            $name = substr($name, 0, -strlen($extension) - 1);
        }
        $name = str_replace('/', '-', $this->textToUri($name, 100));
        if ($name === '') return $mediaPath;
        $dot = strrpos($mediaPath, '.');
        if ($dot === false) return $mediaPath;
        return substr($mediaPath, 0, $dot) . ',' . $name . substr($mediaPath, $dot);
    }

    /**
     * Read file_language_id from a file entry (cache / working state / data_fil row).
     * If the key is missing or empty, it is a single-language file.
     */
    public function fileLanguageId(array $fileData): ?int
    {
        $value = $fileData['file_language_id'] ?? null;
        return ($value === null || $value === '') ? null : (int)$value;
    }

    /**
     * SELECT
     * Fetches records according to the filter parameters, incl. hit count
     * and sorting.
     *
     * Note on subqueries:
     *      if a subquery is started with select() - while results
     *      are being processed, the previous results can be saved with Database::pushResult()
     *      and restored with Database::popResult().
     *
     * @param array|null $param        array with filter and sort criteria
     *                                   'filter','filterop','filtermode','skip','limit','sort','order'
     *                                   'sql','src_id','dest_id','relation','nextof'
     *                                   (relation: field name or 'any')
     * @param ?Dataset $tableObj       dataset subclass whose table is to be searched.
     *                                   If not given, 'record' is searched.
     * @param array &$completedSchema  schema of the area enriched with virtual fields
     *                                   (backend only, so that labels can be inserted into the filter text)
     * @return array                   return array with default sorting, sorting used,
     *                                   filter specifications and sorting in readable form
     */
    public function select(array $param = [], ?Dataset $tableObj = null, array &$completedSchema = []): array
    {
        $param = $param ?? [];
        if (isset($tableObj)) {
            // take the schema from the passed dataset class
            $schema           = $tableObj->getSchema();
            $tableName        = $tableObj->getTableName();
            $defaultSortOrder = $tableObj->getDefaultSortOrder();
            // foreign tables have no virtual fields
            $this->hasVirtualFields = false;
        }
        else {
            $this->hasVirtualFields = true;
            // default: schema of table 'record'
            $schema           = $completedSchema ?: $this->schema;
            $tableName        = 'record';
            $defaultSortOrder = 'changed_at DESC';
            // Backend: parameter completedSchema contains virtual fields + meta fields
        }
        $useVirtual = $this->hasVirtualFields;

        // $_REQUEST: only the keys named in 'request' (see requestParams()),
        // 'sql', 'src_id', 'relation' ... cannot be added via request.
        // Values from $param override the request values.
        $p = array_replace_recursive(
            $this->requestParams((string)($param['request'] ?? ''), $param, $useVirtual),
            $param
        );
        // Type safeguard against manipulated request parameters.
        // filter/filterop/filtermode must be arrays (e.g. ?filter=xyz would
        // otherwise later lead to foreach/string offset errors), skip/limit
        // scalar, sort/order scalar or array. Faulty types are
        // discarded and registered.
        foreach (['filter', 'filterop', 'filtermode'] as $k) {
            if (isset($p[$k]) && !is_array($p[$k])) {
                $this->error($this->snippet('error_param_invalid'), $k);
                unset($p[$k]);
            }
        }
        foreach (['skip', 'limit'] as $k) {
            if (isset($p[$k]) && !is_scalar($p[$k])) {
                $this->error($this->snippet('error_param_invalid'), $k);
                unset($p[$k]);
            }
        }
        foreach (['sort', 'order'] as $k) {
            if (isset($p[$k]) && !is_scalar($p[$k]) && !is_array($p[$k])) {
                $this->error($this->snippet('error_param_invalid'), $k);
                unset($p[$k]);
            }
        }
        // '_default' field specification for tables other than 'record': translate into field name
        if ($tableName != 'record' && !empty($p['filter']['_default'])) {
            $defaultFilter = $p['filter']['_default'];
            if ($defaultFilter != '*') {
                $defaultFieldName = $tableObj->getDefaultFieldName();
                $p['filter'][$defaultFieldName] = $defaultFilter;
            }
        }

        // Pseudo field 'area': area name -> area_id (tables with `area_id`,
        // not `area` itself - there 'area' would simply be an unknown column)
        $hasAreaColumn = isset($schema['area_id']) && $tableName !== 'area';
        if ($hasAreaColumn && isset($p['filter']['area'])) {
            $this->translateAreaFilter($p);
        }

        // Only generate the filter text if explicitly requested
        $wantFilterText = ((string)($param['filtertext'] ?? '') === 'true');  // default: 'false'

        $hasDebug  = (
               (string)($param['debug'] ?? '') === 'true'
            || (bool)($param['debug'] ?? false) === true
        );   // default: 'false'

        // 'filter', 'filterop', 'filtermode', 'src_id', 'dest_id', 'relation', 'sql'
        // --------------------
        // filter() now additionally returns the shared JOIN context.
        $errorsBefore = $this->errors;
        $f       = $this->filter($p, $tableObj, $wantFilterText, $completedSchema);
        $joinCtx = $f['joinCtx'];
        // debug: show the filter errors of this call (unknown fields, invalid values …),
        // otherwise a dropped filter is only noticeable by its absence in the SQL
        if ($hasDebug) {
            foreach ($this->errors as $key => $val) {
                if (isset($errorsBefore[$key]) && $errorsBefore[$key] === $val) continue;
                echo htmlspecialchars((is_int($key) ? '' : $val['label'] . $this->snippet('colon')) . $val['text']) . "<br>";
            }
        }

        // 'skip', 'limit'
        // --------------------
        $skip  = abs((int)($p['skip']  ?? 0));
        $limit = (int)($p['limit'] ?? 0); // no abs() - anything < 1 means "unlimited"
        // skip without limit is ignored (an OFFSET without LIMIT would be a syntax error)
        if ($limit < 1) $skip = 0;

        $where = $f['where'];

        // Table-qualified (JOIN-safe)
        if (isset($schema['tmp']))     $where .= " AND `$tableName`.`tmp`=0";
        if (isset($schema['deleted'])) $where .= " AND `$tableName`.`deleted`=0";

        // Access restriction to instances (see $sqlAccess)
        // Applies to EVERY query on the three content tables - also to those of the
        // backend. Whoever has no access to a website finds its contents
        // nowhere, without the individual query having to do anything for it.
        if ($this->sqlAccess !== '' && in_array($tableName, self::ACCESS_TABLES, true)) {
            $where .= " AND (" . $this->sqlAccess . ")";
        }
        // Access restriction to areas (see $sqlAreaAccess) - backend: the
        // readable areas of the user group. Table-qualified: `record` is
        // joined with data_* tables, which likewise carry no area_id, but
        // `template` with `area`.
        if ($this->sqlAreaAccess !== '' && in_array($tableName, self::AREA_ACCESS_TABLES, true)) {
            $where .= " AND (`$tableName`." . $this->sqlAreaAccess . ")";
        }

        $hasCount  = ((string)($param['count']  ?? '') === 'true');   // default: 'false'
        $hasResult = ((string)($param['result'] ?? '') !== 'false');  // default: 'true'

        $this->db->num_rows_unlimited = 0;
        $this->db->num_rows = 0;
        // Page size for CMS:JUMP_LIST/PREV/NEXT (pagination; 0 = no pagination bar) -
        // travels with push/popResult, so it belongs to exactly this result
        $this->db->num_rows_limit = max(0, $limit);

        if ($hasCount) {
            // Perform the count
            // Note: the COUNT query contains NO ORDER BY -> only filter JOINs needed.
            // DISTINCT PK only if JOINs could produce multiple rows.
            $fj  = $this->emitJoins($joinCtx, ['filter']);
            $cnt = $joinCtx['distinct']
                 ? "COUNT(DISTINCT `$tableName`.`{$tableName}_id`)"
                 : "COUNT(*)";
            $sql = "SELECT $cnt FROM `$tableName`{$fj['sql']} WHERE 1 $where";
            if ($hasDebug) {
                echo $sql . "<br>";
                var_dump(array_merge($fj['values'], $f['values']));
            }
            // JOIN parameters first (they stand before WHERE in the SQL), then WHERE parameters
            $this->db->query(
                $sql,
                $fj['types'] . $f['types'],
                array_merge($fj['values'], $f['values'])
            );
            $this->db->num_rows_unlimited = $this->db->fetchNum()[0];
        }

        if (!$hasResult) {
            // return no result rows - num_rows otherwise still comes from the
            // COUNT query (one row) and a following resulttemplate /
            // CMS:RESULT_START would take that for a hit
            $this->db->num_rows = 0;
            return [
                'filter_text'  => '',
                'sort_text'    => '',
                'default_sort' => '',
                'used_sort'    => '',
                'sort'         => '',
                'order'        => ''
            ];
        }

        // 'sort', 'order'
        // --------------------
        $sort  = $p['sort']  ?? '';
        $order = $p['order'] ?? '';

        $orderClauses = [];
        $usedClauses  = [];
        $returnSort   = [];
        $returnOrder  = [];
        $counter      = 0;

        // 'nextof' (see filter()): the neighbors only make sense in the order of the
        // reference list - sort by data_rel.position ('_custom') in the requested
        // direction; any other sort specification is discarded.
        // filter() has already reported the validity of src_id/relation; here
        // only intervene with a valid reference list, otherwise '_custom' would
        // give a second error for the same cause.
        if ((int)($p['nextof'] ?? 0) > 0 && $useVirtual
            && (int)($p['src_id'] ?? 0) > 0 && trim((string)($p['relation'] ?? '')) !== '') {
            $relField = $this->resolveVirtualField(trim((string)$p['relation']));
            if ($relField !== null && in_array($relField['type'], $this->relationTypes, true)) {
                $order = $this->nextSortDirection($p);
                $sort  = '_custom';
            }
        }

        // If no sort is given, use the default sort
        if (empty($sort)) $sort = $defaultSortOrder;

        $sortArray  = !is_array($sort)  ? explode(',', (string)$sort)  : $sort;
        $orderArray = !is_array($order) ? explode(',', (string)$order) : $order;

        $orderTextParts = [];

        foreach ($sortArray as $index => $sortItem) {
            // Ignore nested request arrays (e.g. sort[0][]=x) -
            // a (string) cast on arrays would have produced "Array" as column name.
            if (!is_scalar($sortItem)) {
                $this->error($this->snippet('error_param_invalid'), 'sort');
                continue;
            }
            $sortItem = trim((string) $sortItem);
            if ($sortItem === '') {
                continue;
            }
            $sortItem = preg_replace('/\s+/', ' ', $sortItem);
            $parts    = explode(' ', $sortItem);

            $field     = $parts[0];
            $direction = 'ASC';
            // note whether the direction was given explicitly
            // (relation sort without subfield otherwise uses default DESC)
            $hasExplicitDir = false;
            if (count($parts) > 1) {
                $direction = strtoupper(trim($parts[1]));
                $hasExplicitDir = true;
            } elseif (isset($orderArray[$index]) && is_scalar($orderArray[$index])
                && trim((string) $orderArray[$index]) !== '') {
                $direction = strtoupper(trim((string) $orderArray[$index]));
                $hasExplicitDir = true;
            }
            $direction  = ($direction === 'DESC') ? 'DESC' : 'ASC';

            $fieldClean = preg_replace('/[^a-zA-Z0-9_.-]/', '', $field);
            if (empty($fieldClean)) {
                continue;
            }

            // '_custom' sort by data_rel.position
            if ($fieldClean === '_custom') {
                $srcId   = (int)($p['src_id'] ?? 0);
                $relName = trim((string)($p['relation'] ?? ''));

                if ($srcId > 0 && $relName !== '' && $useVirtual) {
                    $relField = $this->resolveVirtualField($relName);
                    
                    if ($relField !== null && in_array($relField['type'], $this->relationTypes, true)) {
                        $cacheKey = 'rel_sort_custom';
                        
                        if (isset($joinCtx['map'][$cacheKey])) {
                            $alias = $joinCtx['map'][$cacheKey];
                        } else {
                            $alias = '_rel_sort';
                            // Register a LEFT JOIN explicitly for the sort phase
                            // (deliberately NOT an INNER JOIN: the result must not be reduced
                            // to linked records if the src_id/relation filter is not set)
                            $joinCtx['joins'][$alias] = [
                                'sql'    => "LEFT JOIN `data_rel` AS `$alias`"
                                          . " ON `$alias`.`dest_id` = `$tableName`.`{$tableName}_id`"
                                          . " AND `$alias`.`src_id` = ?"
                                          . " AND `$alias`.`fieldname_id` = ?"
                                          . $this->liveFieldSql($alias, (int)$relField['fieldname_id']),
                                'types'  => 'ii',
                                'values' => [$srcId, $relField['fieldname_id']],
                                'phase'  => 'sort',
                            ];
                            $joinCtx['map'][$cacheKey] = $alias;
                        }
                        
                        $sortExpr = "`$alias`.`position`";
                    } else {
                        // Relation unknown -> ignore sort
                        $this->error($this->snippet('error_sort_field_invalid'), $relName !== '' ? $relName : $fieldClean);
                        continue;
                    }
                } else {
                    // Required parameters missing -> ignore sort
                    $this->error($this->snippet('error_sort_field_invalid'), $fieldClean);
                    continue;
                }
            } elseif ($fieldClean === 'area' && $hasAreaColumn) {
                // Pseudo field 'area': sort by the NAME of the area, without
                // a JOIN on `area` - the ids come sorted by name from the
                // instance file cache, FIELD() forms the ranking from them. Ids that
                // are not listed there yield 0 and come first with ASC.
                $areaIds = $this->areaIdsByName();
                $sortExpr = empty($areaIds)
                    ? "`$tableName`.`area_id`"
                    : "FIELD(`$tableName`.`area_id`, " . implode(',', $areaIds) . ")";
            } elseif ($fieldClean === 'instance' && !$useVirtual && isset($schema['instance_id'])) {
                // Pseudo field 'instance' (only foreign tables with instance_id: area,
                // template - in `record` a virtual field could be called that): in the
                // order of the instances in the backend (`instance`.`position`,
                // default instance last) - from the file cache, as for 'area'.
                // This way the area list groups its rows by client
                // (AreaBackend::list()).
                $instanceIds = array_keys($this->instanceCache->instanceLabels());
                $sortExpr = empty($instanceIds)
                    ? "`$tableName`.`instance_id`"
                    : "FIELD(`$tableName`.`instance_id`, " . implode(',', $instanceIds) . ")";
            } elseif ($fieldClean === '_default') {
                if ($useVirtual) {
                // Special handling for '_default' (direct JOIN on data_dft),
                // sorted by the language rules like the filter
                $sortExpr = $this->collate($this->registerDefaultJoin($tableName, $joinCtx, 'sort'));
                } else {
                    // Foreign table - translate '_default' into the configured default field
                    // (analogous to the filter translation above).
                    $fieldClean = $tableObj->getDefaultFieldName();
                    if (!isset($schema[$fieldClean])) {
                        $this->error($this->snippet('error_sort_field_unknown'), $fieldClean);
                        continue;
                    }
                    $sortExpr = "`$tableName`.`$fieldClean`";
                }
            } else {
                if (!$useVirtual) {
                    // Foreign tables - only allow columns from the schema.
                    if (!isset($schema[$fieldClean])) {
                        $this->error($this->snippet('error_sort_field_unknown'), $fieldClean);
                        continue;
                    }
                    // 'reltable' is in the schema but has no column
                    // (content lies in its own relation table)
                    if (in_array($schema[$fieldClean]['type'] ?? '', $this->noColumnTypes, true)
                        || in_array($schema[$fieldClean]['type'] ?? '', $this->unsearchableTypes, true)) {
                        $this->error($this->snippet('error_sort_field_invalid'), $fieldClean);
                        continue;
                    }
                    $sortExpr = "`$tableName`.`$fieldClean`";
            } else {
                // Default: column/meta field name with table qualifier (JOIN-safe)
                $sortExpr = "`$tableName`.`$fieldClean`";
                    // Virtual field -> JOIN resp. subquery (shared context)
                $isMeta = isset($this->schema[$fieldClean]);
                    if (!$isMeta) {
                        // Allow dot notation (mainfield.subfield, max. ONE level)
                        $mainF = $fieldClean;
                        $subF  = '';
                        if (($dotPos = strpos($fieldClean, '.')) !== false) {
                            $mainF = substr($fieldClean, 0, $dotPos);
                            $subF  = substr($fieldClean, $dotPos + 1);
                            // more than one level (relation.relation.field) not allowed
                            if ($subF === '' || strpos($subF, '.') !== false) {
                                $this->error($this->snippet('error_sort_field_invalid'), $fieldClean);
                        continue;
                    }
                        }
                        $vf = $this->resolveVirtualField($mainF);
                    if ($vf === null) {
                            // unknown sort field -> ignore (no hard abort),
                            $this->error($this->snippet('error_sort_field_unknown'), $mainF);
                        continue;
                    }
                        if (in_array($vf['type'], $this->relationTypes, true)) {
                            // Sort by fields of the LINKED record
                            // (meta or virtual scalar field). Without a subfield it
                            // sorts by its meta field 'changed_at' - default
                            // direction then DESC, unless given explicitly.
                            // Only n:1 types ('relation1'/'pointer1') are sortable,
                            // since the LEFT JOIN would produce multiple rows for n:m.
                            // The type check replaces the earlier database query
                            // on `field`.`max` (type comes from resolveVirtualField()).
                            if (!in_array($vf['type'], $this->singleRelationTypes, true)) {
                                // n:m -> ignore sort
                                $this->error($this->snippet('error_sort_field_invalid'), $fieldClean);
                                continue;
                            }
                            // Resolve the default subfield BEFORE the JOIN, so that
                            // used_sort/sort ACTUALLY reflect the sort used
                            // ("myrelation" -> "myrelation.changed_at DESC").
                            if ($subF === '') {
                                $subF = 'changed_at';
                                if (!$hasExplicitDir) $direction = 'DESC';
                            }
                            $sortExpr = $this->registerRelationJoin(
                                $tableName, $vf['fieldname_id'], $joinCtx, $subF, $fieldClean, 'sort'
                            );
                            if ($sortExpr === null) continue; // Fehler bereits registriert
                            // Normalize field names to the resolved form
                            // (flows into used_sort, sort[] and the round trip back)
                            $fieldClean = $mainF . '.' . $subF;
                        }
                        elseif ($vf['type'] === 'file') {
                            // Sort by file metadata (data_fil).
                            // Without a subfield it sorts by 'filename'.
                            // Resolve the default subfield before the JOIN (used_sort)
                            if ($subF === '') $subF = 'filename';
                            $sortExpr = $this->registerFileSortJoin(
                                $tableName, $vf['fieldname_id'], $joinCtx, $subF, $fieldClean
                            );
                            if ($sortExpr === null) continue; // Fehler bereits registriert
                            $fieldClean = $mainF . '.' . $subF;
                        }
                        elseif ($subF !== '') {
                            // Subfield notation only makes sense for relation/file
                            $this->error($this->snippet('error_sort_field_invalid'), $fieldClean);
                            continue;
                        }
                        elseif (in_array($vf['type'], $this->unsearchableTypes, true)) {
                            $this->error($this->snippet('error_sort_field_invalid'), $fieldClean);
                            continue;
                        }
                        else {
                            // Scalar field - incl. json (sort by raw string)
                            // and fulltext. Reuse of an already filter-built JOIN
                            // if any, otherwise a new JOIN with phase 'sort'
                            // (LEFT JOIN, see registerScalarJoin).
                    $sortExpr = $this->registerScalarJoin($tableName, $vf, $joinCtx, $fieldClean, 'sort');
                    // Text fields by the language rules (collation from filter())
                    if ($vf['type'] === 'text' || $vf['type'] === 'chars') $sortExpr = $this->collate($sortExpr);
                }
            }
                }
            }
            $orderClauses[] = "$sortExpr $direction";
            $usedClauses[]  = "$fieldClean $direction";
            $returnSort[$counter]  = $fieldClean;
            $returnOrder[$counter] = $direction;
            // Sort text (labels) only when needed - otherwise unnecessary
            // snippet accesses in the frontend (record.field_*, record.file_*)
            if (!$wantFilterText) {
                $counter++;
                continue;
            }
            if (($lblDot = strpos($fieldClean, '.')) !== false) {
                $lblMain = substr($fieldClean, 0, $lblDot);
                $lblSub  = substr($fieldClean, $lblDot + 1);
                
                // Determine the label of the main field ("My file")
                $mainLabel = $this->textOrSnippet($schema[$lblMain]['label'] ?? $lblMain);
                
                // Query the type of the main field from the cache to translate the subfield correctly
                $vf = $useVirtual ? $this->resolveVirtualField($lblMain) : null;
                
                if ($vf !== null && $vf['type'] === 'file') {
                    // File field: get the subfield label from record.file_<subfield> (e.g. "record.file_width" -> "Width")
                    $subLabel = $this->snippet("record.file_{$lblSub}");
                } elseif (isset($this->schema[$lblSub]['label'])) {
                    // Relation field with target meta field (e.g. category.changed_at)
                    $subLabel = $this->textOrSnippet($this->schema[$lblSub]['label']);
                } else {
                    // Fallback for other/unknown subfields
                    $subLabel = $this->textOrSnippet($lblSub);
                }
                
                $lblText = $mainLabel . '.' . $subLabel;
            } else {
                // (Pseudo field 'area' carries the label of area_id, 'instance' that of instance_id)
                $lblKey  = ($fieldClean === 'area' && $hasAreaColumn) ? 'area_id' : $fieldClean;
                if ($fieldClean === 'instance' && !$useVirtual && isset($schema['instance_id'])) $lblKey = 'instance_id';
                $lblText = $this->textOrSnippet($schema[$lblKey]['label'] ?? $fieldClean);
            }
            $orderTextParts[] = $lblText
                . ' ' . $this->snippet('label_sort_' . strtolower($direction));

            $counter++;
        }

        $sortText    = '';
        $orderClause = implode(', ', $orderClauses);
        $usedClause  = implode(', ', $usedClauses);

        if (!empty($orderClauses)) {
            $where .= " ORDER BY " . $orderClause;
            // Sort text likewise only when needed
            if ($wantFilterText) {
                $sortText = implode(', ', $orderTextParts);
            }
        }

        if ($limit > 0) $where .= " LIMIT {$limit}";
        if ($skip  > 0) $where .= " OFFSET {$skip}";

        // The actual select; JOINs (filter + sort) come before WHERE.
        $aj   = $this->emitJoins($joinCtx, ['filter', 'sort']);
        $dist = $joinCtx['distinct'] ? 'DISTINCT ' : '';
        $cols = "`$tableName`.*";
        $cacheJoin = '';
        if ($tableName === 'record') {
            // Include the content cache of the language as column `cache` - fetch() reads
            // the virtual fields from it (see class comment, content language).
            // LEFT JOIN: if the row is missing, fetch() rebuilds it.
            $cols .= ", `_rc`.`cache` AS `cache`";
            $cacheJoin = " LEFT JOIN `record_cache` AS `_rc`"
                       . " ON `_rc`.`record_id` = `record`.`record_id`"
                       . " AND `_rc`.`language_id` = " . $this->languageExpr('record');
        }
        $sql  = "SELECT {$dist}{$cols}"
              . " FROM `$tableName`{$aj['sql']}{$cacheJoin}"
              . " WHERE 1 $where";
        if ($hasDebug) {
            echo $sql . "<br>";
            var_dump(array_merge($aj['values'], $f['values']));
        }
        // JOIN parameters first, then WHERE parameters
        $this->db->query(
            $sql,
            $aj['types'] . $f['types'],
            array_merge($aj['values'], $f['values'])
        );

        return [
            'filter_text'  => $f['text'] ?? '',
            'sort_text'    => $sortText,
            'default_sort' => $defaultSortOrder,
            'used_sort'    => $usedClause,
            'sort'         => $returnSort,
            'order'        => $returnOrder
        ];
    }

    /**
     * Content areas this request may see: area_id => name.
     *
     * From the instance file cache (InstanceCache::areasOf()) - the own instance and
     * its readable instances (setInstanceAccess()), without restriction (backend) the
     * areas of all instances. Within this access scope a name
     * is unique (flag 'unique' on `area`.`name`, InstanceCache::uniqueScope()); only
     * without restriction can the same name occur in several instances.
     *
     * @return array  area_id => name, instances in access order, within them
     *                tree order
     */
    public function areaMap(): array
    {
        return $this->instanceCache->areasOf($this->accessInstanceIds);
    }

    /**
     * Ids of the content areas for a name (see areaMap()).
     *
     * @param string $name  area name; a number counts as area_id
     * @return int[]        empty if no area is called that
     */
    public function areaIds(string $name): array
    {
        $name = trim($name);
        if ($name === '') return [];
        if (ctype_digit($name)) return [(int)$name];
        // Names are lowercase (flag 'name'), the column compares without
        // regard to case - the cache should behave the same
        return array_keys($this->areaMap(), mb_strtolower($name), true);
    }

    /**
     * Find templates for a reference "[areaname.]templatename".
     *
     * Template names are unique only within their area (flag
     * 'unique_in_area'). Without an area part, therefore, all templates of this
     * name come back that the request may see ($sqlAccess) - the caller
     * decides what to do with several hits (templateFile(): exception;
     * TemplateBackend::info(): prefer the area of the referencing template).
     *
     * The caller's database result is preserved (pushResult) - CMS:SELECT
     * queries the resulttemplate after the hits are already in the result.
     *
     * @param string $ref  "[area.]name"; each part is cleaned to [a-z0-9_]
     * @return array       rows template_id, area_id, name, standalone, type, instance_id
     *                     (in tree order of the areas); empty = nothing found
     */
    public function findTemplates(string $ref): array
    {
        [$areaName, $name] = $this->splitTemplateRef($ref);
        if ($name === '') return [];

        $sql = "SELECT `t`.`template_id`, `t`.`area_id`, `t`.`name`, `t`.`standalone`, `t`.`type`, `t`.`instance_id`"
            . " FROM `template` `t`"
            . " INNER JOIN `area` `a` ON `a`.`area_id` = `t`.`area_id` AND `a`.`deleted` = 0"
            . " WHERE `t`.`name` = ?";
        $types = 's';
        $values = [$name];
        if ($areaName !== '') {
            $sql .= " AND `a`.`name` = ?";
            $types .= 's';
            $values[] = $areaName;
        }
        if ($this->sqlAccess !== '') {
            $sql .= " AND (`t`." . $this->sqlAccess . ")";
        }
        $sql .= " ORDER BY `a`.`lft` ASC";

        $this->db->pushResult();
        $this->db->query($sql, $types, $values);
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        return $rows;
    }

    /**
     * Path of the compiled file for a template reference, relative to
     * $templatePath and without ".php": "{area_id}/{name}" (Template::fileName()).
     *
     * The database resolution runs only once per reference per request.
     * A name that occurs in several areas without an area specification is an
     * error of the template - processing aborts instead of randomly
     * picking one of them.
     *
     * @param string $ref  "[area.]name"; '' yields '' (no template)
     * @return string
     * @throws \RuntimeException  template not found or not unique
     */
    public function templateFile(string $ref): string
    {
        [$areaName, $name] = $this->splitTemplateRef($ref);
        if ($name === '') return '';
        $key = ($areaName !== '') ? $areaName . '.' . $name : $name;
        if (isset($this->templateFiles[$key])) return $this->templateFiles[$key];

        $rows = $this->findTemplates($key);
        if (count($rows) > 1) {
            throw new \RuntimeException("Error: template name \"$key\" is ambiguous.");
        }
        if (count($rows) < 1) {
            throw new \RuntimeException("Error: template \"$key\" not found.");
        }
        $file = (int)$rows[0]['area_id'] . '/' . $rows[0]['name'];
        $this->templateFiles[$key] = $file;
        return $file;
    }

    /**
     * Split a template reference into area and template names, both cleaned to
     * [a-z0-9_] (like _name()).
     *
     * @return array  [areaname, templatename] - area name '' if not given
     */
    private function splitTemplateRef(string $ref): array
    {
        $parts = explode('.', trim($ref), 2);
        $clean = fn(string $s): string => preg_replace('/[^a-z0-9_]/i', '', $s);
        if (count($parts) === 2) {
            return [mb_strtolower($clean($parts[0])), mb_strtolower($clean($parts[1]))];
        }
        return ['', mb_strtolower($clean($parts[0]))];
    }

    /**
     * Ids of the visible content areas, sorted by name - for ORDER BY FIELD()
     * in select() (sort=area).
     *
     * @return int[]
     */
    private function areaIdsByName(): array
    {
        $map = $this->areaMap();
        asort($map, SORT_STRING);
        return array_keys($map);
    }

    /**
     * Parameters of select() taken from $_REQUEST ('request' parameter).
     *
     *     all                   filter, filterop, filtermode, skip, limit, sort, order
     *     filter,sort,skip      whole parameters (comma list)
     *     filter[title]         only this key (likewise filterop[title], filtermode[title])
     *     filter_op, filter_mode  spelling of CMS:SELECT, the URL keys stay filterop/filtermode
     *
     * The request may only NARROW what the caller fixes in $param:
     *   - skip/limit/sort/order set by the caller replace the request value completely
     *   - for a field the caller filters on, the request brings neither values nor
     *     filterop/filtermode - otherwise filter[area_id]=5 could be widened by a
     *     second value (OR) or inverted by filterop[area_id]=!=. The main field
     *     counts ('author.name' belongs to 'author'), 'area' and 'area_id' are the same.
     *   - password fields are never searchable (see $unsearchableTypes), encrypted
     *     ones only by exact value (buildEncryptedCondition())
     *   - limit only as positive number (no "unlimited" from the URL)
     *
     * @param string $requestKeys  value of the 'request' parameter
     * @param array  $param        parameters of the caller
     * @param bool   $useVirtual   table 'record' (virtual fields)
     * @return array               request values, unchecked types (see select())
     */
    private function requestParams(string $requestKeys, array $param, bool $useVirtual): array
    {
        $requestKeys = trim($requestKeys);
        if ($requestKeys === '' || $requestKeys === 'false') return [];

        // [mainKey => true (whole) | [subKey => true, ...]]
        $allowed = [];
        $keys = ($requestKeys === 'all') ? $this->selectParams : explode(',', $requestKeys);
        foreach ($keys as $key) {
            if (!preg_match('/^([a-z_]+)(?:\[([a-z0-9_.]+)\])?$/', trim($key), $m)) continue;
            // spelling of the template command (filter_op) = that of the URL (filterop)
            $mainKey = str_replace('_', '', $m[1]);
            $subKey  = $m[2] ?? '';
            if (!in_array($mainKey, $this->selectParams, true)) continue;
            if ($subKey === '') {
                $allowed[$mainKey] = true;
            }
            elseif (($allowed[$mainKey] ?? null) !== true) {
                $allowed[$mainKey][$subKey] = true;
            }
        }

        // main fields the caller filters on
        $mainOf = function (string $key) use ($useVirtual): string {
            if ($useVirtual && ($dot = strpos($key, '.')) !== false) $key = substr($key, 0, $dot);
            return ($key === 'area') ? 'area_id' : $key;
        };
        $fixed = [];
        foreach ((array)($param['filter'] ?? []) as $key => $v) $fixed[$mainOf((string)$key)] = true;

        $p = [];
        foreach ($allowed as $mainKey => $subKeys) {
            if (!isset($_REQUEST[$mainKey])) continue;
            $value = $_REQUEST[$mainKey];

            if (!in_array($mainKey, ['filter', 'filterop', 'filtermode'], true)) {
                // skip, limit, sort, order: the caller's value wins completely
                if (isset($param[$mainKey]) || $subKeys !== true) continue;
                if ($mainKey === 'limit' && (!is_scalar($value) || (int)$value < 1)) continue;
                $p[$mainKey] = $value;
                continue;
            }
            // filter/filterop/filtermode: per field; a non-array goes to select()'s check
            if (!is_array($value)) {
                if ($subKeys === true) $p[$mainKey] = $value;
                continue;
            }
            foreach ($value as $key => $v) {
                $key = (string)$key;
                if ($subKeys !== true && !isset($subKeys[$key])) continue;
                if (isset($fixed[$mainOf($key)])) continue;
                $p[$mainKey][$key] = $v;
            }
        }
        return $p;
    }

    /**
     * Translate pseudo filter field 'area' into 'area_id' (select()).
     *
     * filter[area] takes area names and/or ids, comma-separated or as array,
     * with the operators =, ==, ! resp. != and !! (inline expressions "a OR b" and
     * comparison operators do not exist for names). filterop[area] and
     * filtermode[area] move along. The translated values are appended to any
     * existing filter[area_id], after which everything follows the normal path
     * of a meta field (buildMetaGroup()).
     *
     *     filter[area]=artikel            ->  filter[area_id]=50
     *     filter[area]=artikel,topic      ->  filter[area_id]=50,56    (OR)
     *     filter[area]=!artikel           ->  filter[area_id]=!50
     *     filter[area]=57                 ->  filter[area_id]=57       (Zahl = Id)
     *
     * An unknown name is registered as an error; searched positively it yields
     * NOTHING (area_id 0 does not exist) instead of everything; negated, it restricts nothing.
     * If a name occurs in several instances without instance restriction, all
     * its ids count; purely negated values are then combined with AND, otherwise
     * "!a OR !b" would always be true.
     *
     * @param array &$p  parameters of select(), 'filter' is an array
     */
    private function translateAreaFilter(array &$p): void
    {
        $raw = $p['filter']['area'];
        unset($p['filter']['area']);

        $optList = $this->parseOptList((array)($p['filterop'] ?? []), 'area');
        if (isset($p['filterop']['area'])) unset($p['filterop']['area']);
        if (isset($p['filtermode']['area'])) {
            $p['filtermode']['area_id'] ??= $p['filtermode']['area'];
            unset($p['filtermode']['area']);
        }

        $label = $this->textOrSnippet($this->schema['area_id']['label'] ?? 'area');
        static $knownOps = ['=', '==', '!', '!=', '!!'];

        $values      = [];
        $allNegative = true;
        foreach ($this->normalizeValues($raw) as $idx => $val) {
            if (preg_match('/\s+(?:OR|AND)\s+/i', $val)) {
                $this->error($this->snippet('error_filter_value_invalid'), 'area', $label);
                continue;
            }
            if (isset($optList[$idx]) && $optList[$idx] !== '') {
                if (in_array($optList[$idx], $knownOps, true)) {
                    $val = $optList[$idx] . $val;
                } else {
                    $this->error($this->snippet('error_filter_op_invalid'), 'area', $label);
                }
            }
            [$op, $name] = $this->extractOperator($val);
            if (!in_array($op, ['=', '==', '!', '!!'], true)) {
                $this->error($this->snippet('error_filter_op_invalid'), 'area', $label);
                continue;
            }
            $negative = ($op === '!' || $op === '!!');
            $ids = $this->areaIds($name);
            if (empty($ids)) {
                $this->error($this->snippet('error_filter_value_invalid'), 'area', $label);
                if ($negative) continue;
                $ids = [0];
            }
            $prefix = ($op === '=') ? '' : $op;
            foreach ($ids as $id) $values[] = $prefix . $id;
            if (!$negative) $allNegative = false;
        }
        if (empty($values)) return;

        $existing = isset($p['filter']['area_id']) ? $this->normalizeValues($p['filter']['area_id']) : [];
        if (empty($existing) && $allNegative && count($values) > 1 && !isset($p['filtermode']['area_id'])) {
            $p['filtermode']['area_id'] = 'AND';
        }
        $p['filter']['area_id'] = array_merge($existing, $values);
    }

    /**
     * FILTER
     * Prepares the WHERE statement and collects in the JOIN context the
     * JOINs of the virtual scalar fields. Called exclusively by
     * select().
     *
     * @param array|null $param   filter/sort criteria
     *                            Evaluated keys: filter, filterop, filtermode,
     *                            src_id, relation, nextof, sql (string or list),
     *                            sort/order (only for the direction of nextof)
     * @param ?Dataset $tableObj  dataset subclass
     * @param bool $wantFilterText true: generate readable filter text
     * @return array [
     *      'where'   whole where clause, exclusively preceded by the expected "WHERE 1"
     *      'types'   mysqli stmt bind types (WHERE PARAMETERS ONLY)
     *      'values'  mysqli stmt values     (WHERE PARAMETERS ONLY)
     *      'text'    filter criteria in readable form (empty if !$wantFilterText)
     *      'joinCtx' shared JOIN context (see newJoinCtx())
     *      ]
     */
    protected function filter(?array $param = null, ?Dataset $tableObj = null, bool $wantFilterText = true, &$completedSchema = []): array
    {
        $param   = $param ?? [];
        $req     = $param['filter']     ?? [];
        $optReq  = $param['filterop']   ?? [];   // SQL comparison operators per field (comma-separated, index-synchronous)
        $modeReq = $param['filtermode'] ?? [];   // combination of same-named field values: 'AND'/'OR' (default: 'OR')
        // Defense-in-depth - select() already checks the types, but
        // filter() should also not iterate over strings on direct use.
        if (!is_array($req))     $req     = [];
        if (!is_array($optReq))  $optReq  = [];
        if (!is_array($modeReq)) $modeReq = [];
        if (isset($tableObj)) {
            $schema    = $tableObj->getSchema();
            $tableName = $tableObj->getTableName();
        } else {
            $schema    = empty($completedSchema) ? $this->schema : $completedSchema;
            $tableName = 'record';
        }
        $useVirtual = $this->hasVirtualFields;
        // Text comparisons and full text by the language rules - only for content (`record`)
        $this->filterLanguageIds = $useVirtual ? $this->searchLanguageIds($param) : [];
        $this->filterCollation = $this->textCollation($this->filterLanguageIds);

        // Shared JOIN context (used by filter AND sorting)
        $joinCtx = $this->newJoinCtx();

        $returnEmpty = [
            'where'   => '',
            'types'   => '',
            'values'  => [],
            'text'    => '',
            'joinCtx' => $joinCtx,
        ];

        $whereParts      = [];
        $bindTypes       = '';
        $values          = [];
        $filterTextParts = [];

        // Preload text snippets
        $strAnd = $this->snippet('filter_and');
        $strOr  = $this->snippet('filter_or');
        $strNot = $this->snippet('filter_not');

        $hasParentId = isset($schema['parent_id']);
        // Trees are bound to areas (see TreeManagerTrait): lft/rgt start in
        // every content area again at 1, so a subtree filter must
        // filter the area along as well.
        $hasAreaId   = isset($schema['area_id']) && $tableName !== 'area';

        // Read the relative depth limit for parent_id filters in advance.
        // < 0 (or not set) → all descendants; 0 → no children; 1 → direct children; n → up to depth n
        // avoid the (int) cast on arrays (depth[]=1)
        $depthLimit = (isset($req['depth']) && is_scalar($req['depth'])) ? (int)$req['depth'] : -1;
        // depth=0 means: no children wanted → force an empty result
        if ($depthLimit === 0 && isset($req['parent_id'])) {
            return [
                'where'   => 'AND 1=0',
                'types'   => '',
                'values'  => [],
                'text'    => '',
                'joinCtx' => $joinCtx,
            ];
        }

        // depth without parent_id has nothing to refer to - it is not the
        // absolute tree level (depth column) but a modifier of parent_id
        if ($hasParentId && isset($req['depth']) && $this->normalizeValues($req['depth'])
            && !$this->normalizeValues($req['parent_id'] ?? [])) {
            $this->error($this->snippet('error_filter_depth_without_parent'), 'depth');
        }

        // Group fields by MAIN field (see original).
        $groups = [];
        foreach ($req as $fieldKey => $rawValue) {
            $fieldKey = (string)$fieldKey;
            // depth is not a standalone filter field but a modifier for parent_id
            if ($fieldKey === 'depth') continue;

            // relation_any: some reference field points to one of the records
            // (like relation=any + dest_id, but as a filter value, also several ids)
            if ($fieldKey === 'relation_any' && $useVirtual && !isset($schema['relation_any'])) {
                $ids = array_values(array_filter(array_map('intval', $this->normalizeValues($rawValue)), fn($id) => $id > 0));
                if (!$ids) {
                    $this->error($this->snippet('error_filter_value_invalid'), $fieldKey);
                    $whereParts[] = '0 = 1';
                    continue;
                }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $whereParts[] = "EXISTS (SELECT 1 FROM `data_rel` `_rany`"
                              . " WHERE `_rany`.`src_id` = `$tableName`.`{$tableName}_id`"
                              . " AND `_rany`.`dest_id` IN ($placeholders)"
                              . $this->liveFieldSql('_rany', null) . ")";
                $bindTypes .= str_repeat('i', count($ids));
                array_push($values, ...$ids);
                $filterTextParts[] = $this->snippet('filter_refers_to') . ' #' . implode(', #', $ids);
                continue;
            }

            // nested set with adjacency list
            if ($fieldKey === 'parent_id' && $hasParentId) {
                $normalizedValues = $this->normalizeValues($rawValue);
                $parentCondition  = $this->buildParentCondition($tableName, $normalizedValues, $depthLimit, $hasAreaId);
                if ($parentCondition !== null) {
                    $whereParts[]      = $parentCondition['sql'];
                    $bindTypes        .= $parentCondition['types'];
                    array_push($values, ...$parentCondition['values']);
                    $filterTextParts[] = $parentCondition['text'];
                }
                continue; // skip normal field processing
            }

            // Split into main field + subfield (max. one level) - ONLY for virtual fields.
            if ($useVirtual && ($dotPos = strpos($fieldKey, '.')) !== false) {
                $main = substr($fieldKey, 0, $dotPos);
                $sub  = substr($fieldKey, $dotPos + 1);
            } else {
                $main = $fieldKey;
                $sub  = '';
            }

            $groups[$main][] = [
                'key' => $fieldKey,
                'sub' => $sub,
                'raw' => $rawValue,
            ];
        }

        // ----------------------------------------------------------------
        // Process groups
        // ----------------------------------------------------------------
        foreach ($groups as $main => $entries) {
            $main = (string)$main;
            // 'reltable': field without its own column - the content lies in its own
            // relation table, which select() does not know. Not searchable.
            if (in_array($schema[$main]['type'] ?? '', $this->noColumnTypes, true)) {
                $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$main]), $main);
                continue;
            }
            // Meta field (in the schema, without subfield)
            $isMeta = (bool)($schema[$main]['meta'] ?? false);
            if ($isMeta && count($entries) === 1 && $entries[0]['sub'] === '') {
                $res = $this->buildMetaGroup(
                    $tableName, $main, $entries[0]['raw'], $schema[$main],
                    $modeReq, $optReq, $strAnd, $strOr, $strNot
                );
                if ($res !== null) {
                    $whereParts[]      = $res[0];
                    $bindTypes        .= $res[1];
                    array_push($values, ...$res[2]);
                    $filterTextParts[] = $res[3];
                }
                continue;
            }

            // Table != 'record'? no virtual fields
            if (!$useVirtual) {
                // register unknown filter field for foreign tables
                $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$main]), $main);
                continue;
            }

            // _default
            if ($main === '_default') {
                $fieldLabel = $this->snippet('record.field_default');
                
                // filtermode: per MAIN field (default OR)
                $mode = $this->resolveFilterMode($modeReq, $main);
                // A scalar field must NOT have a subfield.
                if (count($entries) !== 1 || $entries[0]['sub'] !== '') {
                    $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$entries[0]['key']]), $entries[0]['key']);
                    continue;
                }
                // Fetch the JOIN via the default method; the filter phase now creates
                // an INNER JOIN. Note whether the JOIN was newly created, to
                // remove it again when the condition group is empty (otherwise
                // the INNER JOIN would restrict the result without a condition).
                $wasKnown = isset($joinCtx['map']['dft_join']);
                $colExpr = $this->registerDefaultJoin($tableName, $joinCtx, 'filter');
                $nv      = $this->normalizeValues($entries[0]['raw']);
                $optList = $this->parseOptList($optReq, $entries[0]['key']);

                // Build group as type 'text'
                $group = $this->buildScalarGroup(
                    $colExpr, $nv, $optList, $mode, 'text', $fieldLabel, $strAnd, $strOr, $strNot
                );
                if ($group[0] === '') {
                    if (!$wasKnown) {
                        unset($joinCtx['joins'][$joinCtx['map']['dft_join']], $joinCtx['map']['dft_join']);
                    }
                    $res = null;
            } else {
                    $res = $group;
                }
            } else {
                // Resolve regular virtual field
                $field = $this->resolveVirtualField($main);
                if ($field === null) {
                    $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$main]), $main);
                    continue;
                }

                // Determine label for the virtual field from the schema (fallback: field name)
                $fieldLabel = $main;
                if (!empty($schema[$main]['label'])) {
                    $fieldLabel = $this->textOrSnippet($schema[$main]['label']);
                }

                $type = $field['type'];
                $fid  = $field['fieldname_id'];

                // filtermode: per MAIN field (default OR)
                // central evaluation incl. error registration
                $mode = $this->resolveFilterMode($modeReq, $main);
                if (in_array($type, $this->relationTypes, true)) {
                    if (in_array($type, $this->singleRelationTypes, true)) {
                        // n:1 ('relation1'/'pointer1') -> JOIN instead of EXISTS.
                        // data_rel yields at most ONE row for these types
                        // per (src_id, fieldname_id) -> no multiple rows, no
                        // DISTINCT, semantically equivalent but easier to optimize
                        // (no correlated subselect per candidate row).
                        $res = $this->buildRelationJoinGroup(
                            $tableName, $fieldLabel, $fid, $entries, $mode, $optReq,
                            $joinCtx, $strAnd, $strOr, $strNot
                        );
                    } else {
                        // pointer / relation (n:m) -> EXISTS
                        // (a JOIN would produce multiple rows here)
                        $res = $this->buildRelationExists(
                            $tableName, $fieldLabel, $fid, $entries, $mode, $optReq, $strAnd, $strOr, $strNot
                        );
                    }
                }
                elseif ($type === 'file') {
                    // file -> EXISTS
                    $res = $this->buildFileExists(
                        $tableName, $fieldLabel, $fid, $entries, $mode, $optReq, $strAnd, $strOr, $strNot
                    );
                }
                else {
                    // Scalar (text/chars/integer/.../date/time/json/fulltext)
                    // A scalar field must NOT have a subfield.
                    if (count($entries) !== 1 || $entries[0]['sub'] !== '') {
                        $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$entries[0]['key']]), $entries[0]['key']);
                        continue;
                    }
                    // Points 1+2+3: JOIN instead of EXISTS.
                    // Note whether the JOIN was newly created, to remove it again
                    // on an empty condition group (e.g. only invalid values).
                    $cacheKey = 'vf:' . $field['fieldname_id'];
                    $wasKnown = isset($joinCtx['map'][$cacheKey]);
                    $colExpr = $this->registerScalarJoin($tableName, $field, $joinCtx, $main, 'filter');
                    $nv      = $this->normalizeValues($entries[0]['raw']);
                    $optList = $this->parseOptList($optReq, $entries[0]['key']);

                    $group = $this->buildScalarGroup(
                        $colExpr, $nv, $optList, $mode, $type, $fieldLabel, $strAnd, $strOr, $strNot
                    );
                    if ($group[0] === '') {
                        if (!$wasKnown) {
                            unset($joinCtx['joins'][$joinCtx['map'][$cacheKey]], $joinCtx['map'][$cacheKey]);
                }
                        $res = null;
                    } else {
                        $res = $group;
            }
                }
            }
            if ($res !== null && $res[0] !== '') {
                $whereParts[]      = $res[0];
                $bindTypes        .= $res[1];
                array_push($values, ...$res[2]);
                $filterTextParts[] = $res[3];
            }
        }

        // ----------------------------------------------------------------
        // src_id + relation
        // Records that are the TARGET (dest_id) of a relation of the given
        // src_id record via the given relation field.
        // (relation/pointer treated alike). Pure existence check -> EXISTS.
        //
        // dest_id + relation (reverse direction)
        // Records that point via the relation field TO dest_id - "who
        // refers to this record?". Together with src_id both apply.
        //
        // relation: one field name, several comma-separated (any of them) or
        // 'any' - any reference field (every row in data_rel, also 'link' fields).
        // With dest_id e.g. all references to a record.
        //
        // nextof (only together with src_id + exactly one field)
        // Neighbors of the record nextof in the same reference list: the entries
        // AFTER it (data_rel.position greater) for ascending, the ones BEFORE it
        // for descending direction - the direction comes from sort/order of the
        // call (nextSortDirection()). select() enforces the sort
        // '_custom' in this direction; with limit=1 the result is exactly the
        // next resp. previous record. If nextof itself is not in the list,
        // the subquery yields NULL and the comparison finds nothing.
        // ----------------------------------------------------------------
        $srcId   = isset($param['src_id']) ? (int)$param['src_id'] : 0;
        $destId  = isset($param['dest_id']) && is_scalar($param['dest_id']) ? (int)$param['dest_id'] : 0;
        $relName = trim((string)($param['relation'] ?? ''));
        $nextOf  = (int)($param['nextof'] ?? 0);
        $relNames = array_values(array_filter(array_map('trim', explode(',', $relName)), 'strlen'));
        $isAny   = in_array('any', array_map('strtolower', $relNames), true);
        if ($relName !== '' && $srcId < 1 && $destId < 1) {
            // Link without source resp. target (src_id/dest_id empty/0, e.g. from
            // a missing GET parameter): no hits - do not omit the filter
            // and thus return ALL records
            $whereParts[] = '0 = 1';
        }
        elseif ($relName !== '' && $useVirtual) {
            $fieldnameIds = [];
            $unknown = '';
            if (!$isAny) {
                foreach ($relNames as $name) {
                    $relField = $this->resolveVirtualField($name);
                    if ($relField === null || !in_array($relField['type'], $this->relationTypes, true)) {
                        $unknown = $name;
                        break;
                    }
                    $fieldnameIds[] = (int)$relField['fieldname_id'];
                }
            }
            if ($unknown !== '' || (!$isAny && !$fieldnameIds)) {
                $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$unknown !== '' ? $unknown : $relName]), $unknown !== '' ? $unknown : $relName);
                // unknown field: find nothing instead of omitting the filter
                $whereParts[] = '0 = 1';
            }
            else {
                $fieldnameIds = array_values(array_unique($fieldnameIds));
                $fieldCond = $isAny ? '' : (count($fieldnameIds) === 1
                    ? " AND `_src`.`fieldname_id` = ?"
                    : " AND `_src`.`fieldname_id` IN (" . implode(',', array_fill(0, count($fieldnameIds), '?')) . ")");
                // links of fields in the trash do not count
                if ($isAny) {
                    $fieldCond .= $this->liveFieldSql('_src', null);
                }
                else {
                    foreach ($fieldnameIds as $fieldnameId) $fieldCond .= $this->liveFieldSql('_src', $fieldnameId);
                }
                if ($srcId > 0) {
                    $nextCond = '';
                    if ($nextOf > 0 && count($fieldnameIds) === 1) {
                        $cmp = ($this->nextSortDirection($param) === 'DESC') ? '<' : '>';
                        $nextCond = " AND `_src`.`position` $cmp (SELECT `_nx`.`position` FROM `data_rel` `_nx`"
                                  . " WHERE `_nx`.`src_id` = ? AND `_nx`.`fieldname_id` = ?"
                                  . " AND `_nx`.`dest_id` = ? LIMIT 1)";
                    }
                    elseif ($nextOf > 0) {
                        $this->error($this->snippet('error_param_invalid'), 'nextof');
                    }
                    $whereParts[] = "EXISTS (SELECT 1 FROM `data_rel` `_src`"
                                  . " WHERE `_src`.`dest_id` = `$tableName`.`{$tableName}_id`"
                                  . " AND `_src`.`src_id` = ?" . $fieldCond . $nextCond . ")";
                    $bindTypes   .= 'i' . str_repeat('i', count($fieldnameIds));
                    $values[]     = $srcId;
                    array_push($values, ...$fieldnameIds);
                    if ($nextCond !== '') {
                        $bindTypes .= 'iii';
                        $values[]   = $srcId;
                        $values[]   = $fieldnameIds[0];
                        $values[]   = $nextOf;
                    }
                    if ($wantFilterText) {
                        $filterTextParts[] = $relName . ' ← ' . $srcId
                            . ($nextCond !== '' ? ' (nextof ' . $nextOf . ')' : '');
                    }
                }
                if ($destId > 0) {
                    $whereParts[] = "EXISTS (SELECT 1 FROM `data_rel` `_src`"
                                  . " WHERE `_src`.`src_id` = `$tableName`.`{$tableName}_id`"
                                  . " AND `_src`.`dest_id` = ?" . $fieldCond . ")";
                    $bindTypes   .= 'i' . str_repeat('i', count($fieldnameIds));
                    $values[]     = $destId;
                    array_push($values, ...$fieldnameIds);
                    if ($wantFilterText) {
                        $filterTextParts[] = trim(($isAny ? '' : $relName) . ' '
                            . $this->snippet('filter_refers_to') . ' #' . $destId);
                    }
                }
            }
        }
        elseif ($nextOf > 0) {
            // nextof without reference list is not defined
            $this->error($this->snippet('error_param_invalid'), 'nextof');
        }

        // hard-coded in the sql parameter (must be literal, no bindings).
        // A string or a list of conditions - cmsSelect() appends its
        // publication condition to the sql of the template this way. Each condition is
        // bracketed (an OR within it therefore stays an OR) and a leading "AND"
        // is tolerated.
        foreach ((array)($param['sql'] ?? []) as $sqlPart) {
            $sqlPart = trim((string)$sqlPart);
            $sqlPart = preg_replace('/^AND\s+/i', '', $sqlPart);
            if ($sqlPart === '') continue;
            $whereParts[] = '(' . $sqlPart . ')';
        }

        if (empty($whereParts)) return $returnEmpty;

        return [
            'where'   => 'AND ' . implode(' AND ', $whereParts),
            'types'   => $bindTypes,
            'values'  => $values,
            'text'    => $wantFilterText ? implode(" $strAnd ", $filterTextParts) : '',
            'joinCtx' => $joinCtx,
        ];
    }

    /**
     * Direction in which 'nextof' traverses the reference list (see filter()):
     * the direction of the first sort field - "field DESC" in the value of sort or
     * the first entry of order. Without specification ASC (the next record).
     *
     * @return string  'ASC' | 'DESC'
     */
    private function nextSortDirection(array $p): string
    {
        $sort  = $p['sort']  ?? '';
        $order = $p['order'] ?? '';
        $sort  = is_array($sort)  ? (string)(reset($sort)  ?: '') : (string)$sort;
        $order = is_array($order) ? (string)(reset($order) ?: '') : (string)$order;
        // only the first sort field counts
        $sort  = trim((string)strtok($sort, ','));
        $order = trim((string)strtok($order, ','));
        $parts = preg_split('/\s+/', $sort);
        $direction = (count($parts) > 1) ? $parts[1] : $order;
        return (strtoupper(trim($direction)) === 'DESC') ? 'DESC' : 'ASC';
    }

    /**
     * Creates an empty JOIN context, shared by filter() and the sorting
     * in select().
     *
     * 'joins'    : alias => ['sql','types','values','phase']  (phase: 'filter'|'sort')
     * 'map'      : reuse   cacheKey => alias
     * 'seq'      : running alias counter
     * 'distinct' : true as soon as a JOIN can produce multiple rows
     *              (in this version relations/files remain EXISTS,
     *               therefore usually false; the flag is for future extension
     *               and present)
     */
    private function newJoinCtx(): array
    {
        return [
            'joins'    => [],
            'map'      => [],
            'seq'      => 0,
            'distinct' => false,
        ];
    }

    /**
     * Returns the qualified column expression of a virtual field.
     *
     * NO COALESCE: data_* tables are guaranteed complete on write.
     * Should a value be missing (contrary to the guarantee), NULL yields no hit.
     *
     * @param string $alias  JOIN alias (begins with "_")
     * @param string $type   long field type
     * @param string $col    column in the data_* table (default 'data')
     */
    private function virtualColumnExpr(string $alias, string $type, string $col = 'data'): string
    {
        // Encrypted fields: not the ciphertext, but the HMAC next to it.
        // encrypt() draws a new IV per call - `data` is therefore useless for any
        // comparison or sorting, whereas `hash` is
        // deterministic. See buildEncryptedCondition().
        if ($type === 'encrypted' && $col === 'data') $col = 'hash';

        return "`$alias`.`$col`";
    }

    /**
     * Registers (or finds) a JOIN on the data_* table of a
     * virtual scalar field. Alias begins with "_".
     * Used by filter AND sorting -> reuse via 'map'.
     *
     * Assumption: per (record_id, fieldname_id) there is at most ONE data_* row
     * (scalar field) -> the JOIN produces no multiple rows, no DISTINCT needed.
     *
     * JOIN type by phase:
     *   'filter' -> INNER JOIN. All generated WHERE conditions on `alias`.`data`
     *               are NULL-rejecting (also != and NOT LIKE), i.e. LEFT and INNER
     *               yield identical results. INNER makes the intent explicit
     *               and gives the optimizer full freedom in the join order
     *               (e.g. access via idx_filter(fieldname_id, data) as driver).
     *   'sort'   -> LEFT JOIN. Records WITHOUT a field value must not drop out
     *               of the result when merely sorting.
     *
     * @param string $tableName  base table (e.g. 'record')
     * @param array  $vf         resolveVirtualField()-Ergebnis (fieldname_id, type)
     * @param array  &$ctx       JOIN context
     * @param string $fieldName  field name (only for possible debugging/extension)
     * @param string $phase      'filter' | 'sort'
     * @return string            column expression, e.g. `_vf_0`.`data`
     */
    private function registerScalarJoin(
        string $tableName,
        array  $vf,
        array  &$ctx,
        string $fieldName,
        string $phase = 'filter'
    ): string {
        $cacheKey = 'vf:' . $vf['fieldname_id'];
        if (isset($ctx['map'][$cacheKey])) {
            $alias = $ctx['map'][$cacheKey];
        } else {
            $dataTable = $this->virtualDataTable($vf['type']);
            $alias     = '_vf_' . $ctx['seq']++;
            // INNER in the filter phase, LEFT in the sort phase
            $joinType  = ($phase === 'filter') ? 'INNER JOIN' : 'LEFT JOIN';
            $ctx['joins'][$alias] = [
                'sql'    => "$joinType `$dataTable` AS `$alias`"
                          . " ON `$alias`.`record_id` = `$tableName`.`{$tableName}_id`"
                          . " AND `$alias`.`fieldname_id` = ?"
                          . $this->liveFieldSql($alias, (int)$vf['fieldname_id'])
                          . " AND `$alias`.`language_id` = " . $this->languageExpr($tableName),
                'types'  => 'i',
                'values' => [$vf['fieldname_id']],
                'phase'  => $phase,
            ];
            $ctx['map'][$cacheKey] = $alias;
        }
        return $this->virtualColumnExpr($alias, $vf['type']);
    }

    /**
     * Registers (or finds) a JOIN on the table data_dft
     * for the _default field. Alias begins with "_".
     * Used by filter AND sorting -> reuse via 'map'.
     *
     * Filter phase -> INNER JOIN (data_dft guarantees one row
     * per record; the filter condition is also NULL-rejecting).
     * Sort phase -> LEFT JOIN (defensive, in case a dft row is missing).
     *
     * @param string $tableName  base table (e.g. 'record')
     * @param array  &$ctx       JOIN-Kontext
     * @param string $phase      'filter' | 'sort'
     * @return string            column expression, e.g. `_dft_0`.`data`
     */
    private function registerDefaultJoin(
        string $tableName,
        array  &$ctx,
        string $phase = 'filter'
    ): string {
        $cacheKey = 'dft_join';
        if (isset($ctx['map'][$cacheKey])) {
            $alias = $ctx['map'][$cacheKey];
        } else {
            $alias    = '_dft_' . $ctx['seq']++;
            $joinType = ($phase === 'filter') ? 'INNER JOIN' : 'LEFT JOIN';
            $ctx['joins'][$alias] = [
                // NO fieldname_id needed in the ON condition
                'sql'    => "$joinType `data_dft` AS `$alias`"
                          . " ON `$alias`.`record_id` = `$tableName`.`{$tableName}_id`"
                          . " AND `$alias`.`language_id` = " . $this->languageExpr($tableName),
                'types'  => '',
                'values' => [],
                'phase'  => $phase,
            ];
            $ctx['map'][$cacheKey] = $alias;
        }
        return $this->virtualColumnExpr($alias, 'text');
    }

    /**
     * Central evaluation of the filtermode parameter for a main field.
     * Invalid values (neither AND nor OR) are registered as errors
     * (snippet 'error_filter_mode_invalid') and set to the default 'OR'.
     */
    private function resolveFilterMode(array $modeReq, string $key): string
    {
        $raw = $modeReq[$key] ?? '';
        // catch nested arrays (filtermode[title][]=..)
        if (!is_scalar($raw)) {
            $this->error($this->snippet('error_filter_mode_invalid'), $key);
            return 'OR';
        }
        $mode = strtoupper(trim((string)$raw));
        if ($mode === '') return 'OR';
        if (!in_array($mode, ['AND', 'OR'], true)) {
            $this->error($this->snippet('error_filter_mode_invalid'), $key);
            return 'OR';
        }
        return $mode;
    }

    /**
     * Emits JOIN SQL + bind parameters for the desired phases.
     * COUNT uses only ['filter'], the main SELECT ['filter','sort'].
     * Order stays insertion order -> filter joins first (bind-safe).
     *
     * @return array{sql:string,types:string,values:array}
     */
    private function emitJoins(array $ctx, array $phases): array
    {
        $sql    = '';
        $types  = '';
        $values = [];
        foreach ($ctx['joins'] as $j) {
            if (!in_array($j['phase'], $phases, true)) continue;
            $sql   .= ' ' . $j['sql'];
            $types .= $j['types'];
            array_push($values, ...$j['values']);
        }
        return ['sql' => $sql, 'types' => $types, 'values' => $values];
    }
    
    /**
     * Builds the WHERE sub-condition for a META FIELD.
     * Thin wrapper: determines column expression + mode and delegates to
     * buildScalarGroup() (same logic as for virtual fields).
     *
     * @return array{0:string,1:string,2:array,3:string}|null  [sql, types, values, text] or null
     */
    private function buildMetaGroup(
        string $tableName,
        string $fieldKey,
        mixed  $rawValue,
        array  $fieldDef,
        array  $modeReq,
        array  $optReq,
        string $strAnd,
        string $strOr,
        string $strNot
    ): ?array {
        $type = $fieldDef['type'] ?? 'text';
        $nv   = $this->normalizeValues($rawValue);

        // filtermode: combination of several values of the same field (default: 'OR')
        // central evaluation incl. error registration
        $mode = $this->resolveFilterMode($modeReq, $fieldKey);
        // filterop: operators per value as comma-separated list (index-synchronous)
        $optList = $this->parseOptList($optReq, $fieldKey);

        // Determine label
        $fieldLabel = empty($fieldDef['label']) ? $fieldKey : $this->textOrSnippet($fieldDef['label']);

        // Meta field -> table-qualified column expression (JOIN-safe)
        $colExpr = "`$tableName`.`$fieldKey`";

        $group = $this->buildScalarGroup(
            $colExpr, $nv, $optList, $mode, $type, $fieldLabel, $strAnd, $strOr, $strNot
        );
        return ($group[0] === '') ? null : $group;
    }

    /**
     * Builds the linked value group of a scalar field on a FINISHED
     * column expression $colExpr. Multiple values are combined according to $mode (AND/OR).
     * Supports inline expressions "A OR B" per value –
     * EXCEPTION: == and !! enforce exact search, no inline AND/OR.
     *
     * $colExpr is already qualified:
     *   Metafeld   ->  `record`.`title`
     *   virtuell   ->  `_vf_0`.`data`
     *   Subquery   ->  `data` / `size` / `rec`.`changed_at`
     *
     * @return array{0:string,1:string,2:array,3:string}  [sqlGruppe, bindTypes, values, textGruppe]
     */
    private function buildScalarGroup(
        string $colExpr,
        array  $normalizedValues,
        array  $optList,
        string $mode,
        string $type,
        string $fieldLabel,
        string $strAnd,
        string $strOr,
        string $strNot
    ): array {
        // Password hashes: no condition, but also no hit - an ignored filter
        // would otherwise find ALL records
        if (in_array($type, $this->unsearchableTypes, true)) {
            $this->error(strtr($this->snippet('error_field_not_searchable'), ['{name}' => $fieldLabel]), $fieldLabel);
            return ['0 = 1', '', [], ''];
        }
        $conds = [];
        $types = '';
        $vals  = [];
        $texts = [];
        static $knownOps = ['=', '==', '!', '!=', '!!', '>', '>=', '<', '<=', '>>', '<<'];
        foreach ($normalizedValues as $valIdx => $val) {
            // prefix operator from filterop
            if (isset($optList[$valIdx]) && $optList[$valIdx] !== '') {
                if (in_array($optList[$valIdx], $knownOps, true)) {
                    $val = $optList[$valIdx] . $val;
                    } else {
                        $this->error($this->snippet('error_filter_op_invalid'), $fieldLabel);
                }
            }
            // == and !! enforce exact search: no inline AND/OR expressions
            $isExact = in_array(substr($val, 0, 2), ['==', '!!'], true);

            // Inline expression "v1 OR v2 AND v3"
            if (!$isExact && preg_match('/\s+(?:OR|AND)\s+/i', $val)) {
                $expr = $this->buildScalarExpression(
                    $colExpr, $val, $type, $fieldLabel, $strAnd, $strOr, $strNot
                );
                if ($expr[0] === '') continue;
                $conds[] = $expr[0];
                $types  .= $expr[1];
                array_push($vals, ...$expr[2]);
                $texts[] = $expr[3];
                continue;
            }

            [$op, $value] = $this->extractOperator($val);
            $opForText = match ($op) {
                '!', '!!' => $strNot,
                '=='      => '=',
                default   => $op,
            };

            $res = $this->buildScalarCondition($colExpr, $value, $op, $type, $fieldLabel);
            if ($res[0] === '') {
                $this->error($this->snippet('error_filter_value_invalid'), $fieldLabel);
                continue;
            }
            $conds[] = $res[0];
            $types  .= $res[1];
            array_push($vals, ...$res[2]);
            $texts[] = trim("$fieldLabel $opForText $value");
        }

        if (empty($conds)) return ['', '', [], ''];

        $glue     = ($mode === 'AND') ? ' AND ' : ' OR ';
        $textGlue = ($mode === 'AND') ? " $strAnd " : " $strOr ";

        $sql  = count($conds) === 1 ? $conds[0] : '(' . implode($glue, $conds) . ')';
        $text = count($texts) === 1 ? $texts[0] : '(' . implode($textGlue, $texts) . ')';

        return [$sql, $types, $vals, $text];
    }

    /**
     * INLINE EXPRESSION ("A OR B AND C") for a column expression $colExpr.
     *
     * @return array{0:string,1:string,2:array,3:string}
     */
    private function buildScalarExpression(
        string $colExpr,
        string $expression,
        string $type,
        string $fieldLabel,
        string $strAnd,
        string $strOr,
        string $strNot
    ): array {
        $tokens    = preg_split('/\s+(OR|AND)\s+/i', $expression, -1, PREG_SPLIT_DELIM_CAPTURE);
        $parts     = [];
        $bindTypes = '';
        $values    = [];
        $textParts = [];
        $glue      = '';
        $textGlue  = '';

        $i = 0;
        while ($i < count($tokens)) {
            $token = trim($tokens[$i]);
            if (strtoupper($token) === 'OR' || strtoupper($token) === 'AND') {
                $glue     = strtoupper($token);
                $textGlue = ($glue === 'OR') ? $strOr : $strAnd;
                $i++;
                continue;
            }

            [$op, $value] = $this->extractOperator($token);
            $opForText = match ($op) {
                '!', '!!' => $strNot,
                '=='      => '=',
                default   => $op,
            };

            $res = $this->buildScalarCondition($colExpr, $value, $op, $type, $fieldLabel);
            if ($res[0] !== '') {
                if (!empty($parts)) {
                    $parts[]     = $glue;
                    $textParts[] = $textGlue;
                }
                $parts[]     = $res[0];
                $textParts[] = trim("$fieldLabel $opForText $value");
                $bindTypes  .= $res[1];
                array_push($values, ...$res[2]);
            } else {
                $this->error($this->snippet('error_filter_value_invalid'), $fieldLabel);
            }
            $i++;
        }

        if (empty($parts)) return ['', '', [], ''];

        return [
            '(' . implode(' ', $parts) . ')',
            $bindTypes,
            $values,
            '(' . implode(' ', $textParts) . ')',
        ];
    }

    /**
     * Builds the SQL condition for ONE value on a FINISHED column expression.
     * Central dispatcher by field type (used by the meta AND the virtual path).
     *
     * @return array{0:string,1:string,2:array}  [sql, bindTypes, values]
     */
    private function buildScalarCondition(string $colExpr, string $value, string $op, string $type, string $fieldLabel = ''): array
    {
        // "(empty)" - not a value of its own: empty OR fallback from the main language
        // (column `empty` = 1). This lets the backend find records not yet translated
        // into this language. Allowed with '=', '==' and negated.
        if (strcasecmp($value, self::EMPTY_FILTER) === 0) {
            if (!in_array($op, ['=', '==', '!', '!!'], true)) return ['', '', []];
            $sql = $this->emptyCondition($colExpr, $type);
            return [in_array($op, ['!', '!!'], true) ? "NOT $sql" : $sql, '', []];
        }
        if ($type === 'fulltext') {
            return $this->buildFulltextCondition($colExpr, $value);
        }
        if ($type === 'json') {
            return $this->buildJsonCondition($colExpr, $value, $op);
        }
        if (in_array($type, $this->dateTypes, true)) {
            // padded to 19 characters
            return $this->buildDateConditionUnified($colExpr, $value, $op, $type);
        }
        if ($type === 'time') {
            // padded to 8 characters
            return $this->buildTimeConditionUnified($colExpr, $value, $op);
        }
        if (in_array($type, $this->numericTypes, true)) {
            return $this->buildNumericCondition($colExpr, $value, $op, $type);
        }
        if ($type === 'encrypted') {
            return $this->buildEncryptedCondition($colExpr, $value, $op, $fieldLabel);
        }
        // Text fields (data_txt, data_chr, data_dft) by the language rules
        if ($type === 'text' || $type === 'chars') $colExpr = $this->collate($colExpr);
        return $this->buildTextCondition($colExpr, $value, $op);
    }

    /**
     * Condition for an encrypted field (data_enc).
     *
     * The HMAC in `hash` is compared, not the ciphertext in `data`: encrypt()
     * draws a new IV per call, so the same plaintext never yields the same
     * ciphertext. We normalize and hash the search value here exactly as
     * VirtualFieldsTrait::saveVirtualFields() handled it when saving
     * (encryptedHash(): case, whitespace and punctuation do not count).
     *
     * Therefore ONLY equality of the normalized value is possible. Greater/less and wildcard (*)
     * make no sense on a hash. The method reports both itself and returns
     * then a condition that finds nothing (1=0) - had it, like the other
     * build*Condition() methods, simply yielded nothing, the condition would silently drop out and
     * the query would return ALL records. For an encrypted field that is
     * the most dangerous conceivable outcome.
     *
     * (An empty search value never gets this far - normalizeValues() discards empty
     * filter values for all types beforehand.)
     *
     * @param string $fieldLabel  for the error message
     * @return array              [sql, bindTypes, values]
     */
    private function buildEncryptedCondition(string $col, string $value, string $op, string $fieldLabel = ''): array
    {
        $noMatch = ['1=0', '', []];

        if (str_contains($value, '*')) {
            // wildcard on a hash: there are no partial matches
            $this->error($this->snippet('error_filter_value_invalid'), $fieldLabel);
            return $noMatch;
        }

        if (!in_array($op, ['=', '==', '!', '!!', '!='], true)) {
            // greater/less: meaningless on a hash
            $this->error($this->snippet('error_filter_op_invalid'), $fieldLabel);
            return $noMatch;
        }

        // normalized as on save (encryptedHash()); if nothing remains
        // (only punctuation), there is nothing to find either
        $needle = $this->encryptedHash($value); // binary, 32 Bytes
        if ($needle === '') return $noMatch;

        return in_array($op, ['=', '=='], true)
            ? ["$col = ?",  's', [$needle]]
            : ["$col != ?", 's', [$needle]];
    }

    /**
     * Condition for the filter value "(empty)" resp. "!(empty)" on a
     * RELATION/POINTER field: no reference resp. at least one reference to a
     * LIVE record. Edges to working copies (check-out also creates the
     * incoming ones) and to deleted targets (kept for restore())
     * do not count - as with output via relation=.
     *
     * @return array{0:string,1:string,2:array,3:string}
     */
    private function relationEmptyPredicate(string $tableName, string $fieldLabel, int $fid, bool $negate, string $strNot): array
    {
        $exists = "EXISTS (SELECT 1 FROM `data_rel` `_re`"
                . " INNER JOIN `record` `_rd` ON `_rd`.`record_id` = `_re`.`dest_id` AND `_rd`.`tmp` = 0 AND `_rd`.`deleted` = 0"
                . " WHERE `_re`.`fieldname_id` = ? AND `_re`.`src_id` = `$tableName`.`{$tableName}_id`"
                . $this->liveFieldSql('_re', $fid) . ")";
        return [
            $negate ? $exists : "NOT $exists",
            'i',
            [$fid],
            $fieldLabel . ' ' . ($negate ? $strNot : '=') . ' ' . self::EMPTY_FILTER,
        ];
    }

    /**
     * EXISTS condition for a RELATION/POINTER field (data_rel).
     *
     * Direct target IDs (sub = '' | 'record_id'):
     *   OR  -> ONE EXISTS with  r.dest_id IN (...)
     *   AND -> one own EXISTS per target ID (... = id)  (linking with SEVERAL targets)
     *   negation (!id) -> NOT EXISTS (... = id)
     *
     * Subfield:
     *   - META FIELD of the target record (e.g. category.changed_at) -> EXISTS with JOIN on `record`
     *   - virtual scalar field (e.g. category.title)                 -> nested EXISTS on data_*
     *   relation/pointer as subfield -> error_relation_relation
     *   file as subfield             -> error_field_unknown_type
     *
     * filtermode (AND/OR) combines the predicates; AND allows DIFFERENT
     * target records per sub-condition (set reduction without DISTINCT, since EXISTS).
     *
     * @return array{0:string,1:string,2:array,3:string}|null
     */
    private function buildRelationExists(
        string $tableName,
        string $fieldLabel,
        int    $fid,
        array  $entries,
        string $mode,
        array  $optReq,
        string $strAnd,
        string $strOr,
        string $strNot
    ): ?array {
        $predicates = [];
        $relWhere   = "r.`fieldname_id` = ? AND r.`src_id` = `$tableName`.`{$tableName}_id`"
                    . $this->liveFieldSql('r', $fid);

        foreach ($entries as $e) {
            $sub = $e['sub'];
            $nv  = $this->normalizeValues($e['raw']);

            // target IDs directly (main resp. main.record_id)
            if ($sub === '' || $sub === 'record_id') {
                $pos = [];
                $neg = [];
                foreach ($nv as $v) {
                    [$op, $val] = $this->extractOperator($v);
                    if (strcasecmp($val, self::EMPTY_FILTER) === 0) {
                        $predicates[] = $this->relationEmptyPredicate($tableName, $fieldLabel, $fid, $op === '!' || $op === '!!', $strNot);
                        continue;
                    }
                    if (!is_numeric($val)) {
                        $this->error($this->snippet('error_filter_value_invalid'), $e['key']);
                        continue;
                    }
                    if (in_array($op, ['!', '!!'], true)) $neg[] = (int)$val;
                    else                                  $pos[] = (int)$val;
                }
                if ($pos) {
                    if ($mode === 'AND') {
                        // every target record must be linked separately
                        foreach ($pos as $pid) {
                            $predicates[] = [
                                "EXISTS (SELECT 1 FROM `data_rel` r WHERE $relWhere AND r.`dest_id` = ?)",
                                'ii',
                                [$fid, $pid],
                                $fieldLabel . ' = ' . $pid,
                            ];
                        }
                    } else {
                        // OR -> one EXISTS with IN
                        $ph = implode(',', array_fill(0, count($pos), '?'));
                        $predicates[] = [
                            "EXISTS (SELECT 1 FROM `data_rel` r WHERE $relWhere AND r.`dest_id` IN ($ph))",
                            'i' . str_repeat('i', count($pos)),
                            array_merge([$fid], $pos),
                            $fieldLabel . ' = ' . implode(', ', $pos),
                        ];
                    }
                }
                foreach ($neg as $nid) {
                    $predicates[] = [
                        "NOT EXISTS (SELECT 1 FROM `data_rel` r WHERE $relWhere AND r.`dest_id` = ?)",
                        'ii',
                        [$fid, $nid],
                        $fieldLabel . ' ' . $strNot . ' ' . $nid,
                    ];
                }
                continue;
            }

            $subLabel = $fieldLabel . '.' . $sub;
            $optList  = $this->parseOptList($optReq, $e['key']);

            // Subfield = META FIELD of the target record
            if (isset($this->schema[$sub])) {
                $metaType = $this->schema[$sub]['type'] ?? 'text';
                // column of the target record, qualified via alias `rec` in the inner EXISTS
                $colExpr  = "`rec`.`$sub`";
                $group = $this->buildScalarGroup(
                    $colExpr, $nv, $optList, $mode, $metaType, $subLabel, $strAnd, $strOr, $strNot
                );
                if ($group[0] === '') continue;
                $predicates[] = [
                    "EXISTS (SELECT 1 FROM `data_rel` r"
                        . " JOIN `record` `rec` ON `rec`.`record_id` = r.`dest_id`"
                        . " WHERE $relWhere AND " . $group[0] . ")",
                    'i' . $group[1],
                    array_merge([$fid], $group[2]),
                    $group[3],
                ];
                continue;
            }

            // Subfield = virtual field of the target record
            $subField = $this->resolveVirtualField($sub);
            if ($subField === null) {
                $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$e['key']]), $e['key']);
                continue;
            }
            if (in_array($subField['type'], $this->relationTypes, true)) {
                // Relation.Relation not allowed
                $this->error($this->snippet('error_relation_relation'), $e['key']);
                continue;
            }
            if ($subField['type'] === 'file') {
                // nested too deeply -> not supported
                $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$e['key']]), $e['key']);
                continue;
            }

            // scalar subfield -> nested EXISTS on data_* of the target record
            $dataTable = $this->virtualDataTable($subField['type']);
            $colExpr   = "`data`"; // in the inner data_* EXISTS
            $group = $this->buildScalarGroup(
                $colExpr, $nv, $optList, $mode, $subField['type'], $subLabel, $strAnd, $strOr, $strNot
            );
            if ($group[0] === '') continue;
            $predicates[] = [
                "EXISTS (SELECT 1 FROM `data_rel` r WHERE $relWhere"
                    . " AND EXISTS (SELECT 1 FROM `$dataTable`"
                        . " WHERE `record_id` = r.`dest_id`"
                        . " AND `fieldname_id` = ?"
                        . $this->liveFieldSql('', (int)$subField['fieldname_id'])
                        . " AND " . $group[0] . "))",
                'ii' . $group[1],
                array_merge([$fid, $subField['fieldname_id']], $group[2]),
                $group[3],
            ];
        }

        if (empty($predicates)) return null;

        $glue     = ($mode === 'AND') ? ' AND ' : ' OR ';
        $textGlue = ($mode === 'AND') ? " $strAnd " : " $strOr ";

        $sqlParts = [];
        $types    = '';
        $vals     = [];
        $texts    = [];
        foreach ($predicates as $pr) {
            $sqlParts[] = $pr[0];
            $types     .= $pr[1];
            array_push($vals, ...$pr[2]);
            $texts[]    = $pr[3];
        }
        $sql  = count($sqlParts) === 1 ? $sqlParts[0] : '(' . implode($glue, $sqlParts) . ')';
        $text = count($texts) === 1    ? $texts[0]    : '(' . implode($textGlue, $texts) . ')';

        return [$sql, $types, $vals, $text];
    }

    /**
     * Convert numbers with unit in a search value of file size to bytes:
     * k/K = 1024, M = 1024², G = 1024³, an appended B is allowed and upper/
     * lower case is irrelevant - ">1M AND <2.5M" becomes ">1048576 AND <2621440".
     * The decimal separator is the point (the comma separates search values).
     */
    public function expandSizeUnits(string $value): string
    {
        return (string)preg_replace_callback(
            '/(?<![\w.])(\d+(?:\.\d+)?)\s*([kmg])b?(?!\w)/i',
            function (array $m): string {
                $factor = match (strtolower($m[2])) { 'k' => 1024, 'm' => 1024 ** 2, default => 1024 ** 3 };
                return (string)(int)round((float)$m[1] * $factor);
            },
            $value
        );
    }

    /**
     * EXISTS condition for a FILE field (data_fil).
     *
     * Subfields of the same main field are combined according to $mode and
     * refer to THE SAME file row.
     *
     * Allowed subfields (column/type):
     *   size, width, height  (integer)
     *   filename             (chars)
     *   ocr_text             (text)
     * Bare file field without subfield -> match on `filename`.
     *
     * @return array{0:string,1:string,2:array,3:string}|null
     */
    private function buildFileExists(
        string $tableName,
        string $fieldLabel,
        int    $fid,
        array  $entries,
        string $mode,
        array  $optReq,
        string $strAnd,
        string $strOr,
        string $strNot
    ): ?array {
        // Whitelist: subfield -> [column, scalar type]
        $fileCols = [
            'size'     => ['col' => 'size',     'kind' => 'integer'],
            'width'    => ['col' => 'width',    'kind' => 'integer'],
            'height'   => ['col' => 'height',   'kind' => 'integer'],
            'filename' => ['col' => 'filename', 'kind' => 'chars'],
            'mime_type' => ['col' => 'mime_type', 'kind' => 'chars'],
            'extension' => ['col' => 'extension', 'kind' => 'chars'],
            'ocr_text' => ['col' => 'ocr_text', 'kind' => 'text'],
        ];

        $inner = [];
        $types = '';
        $vals  = [];
        $texts = [];
        // Conditions outside the EXISTS ("(empty)", one binding $fid each)
        $outer      = [];
        $outerTexts = [];

        foreach ($entries as $e) {
            // Bare file field -> filename
            $sub = $e['sub'] !== '' ? $e['sub'] : 'filename';
            if (!isset($fileCols[$sub])) {
                $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$e['key']]), $e['key']);
                continue;
            }
            $colInfo = $fileCols[$sub];
            $nv      = $this->normalizeValues($e['raw']);
            $optList = $this->parseOptList($optReq, $e['key']);

            // "(empty)" - no file: without a file there is no row in data_fil at all,
            // so an EXISTS on "filename = ''" would never find anything. Instead, outside
            // the EXISTS: NOT EXISTS of a row with a file name (negated: EXISTS).
            // Applies to filename resp. the bare field; numeric subfields such as width
            // have no "empty".
            foreach ($nv as $valIdx => $val) {
                $opt = (string)($optList[$valIdx] ?? '');
                [$op, $value] = $this->extractOperator($opt . $val);
                if (strcasecmp($value, self::EMPTY_FILTER) !== 0) continue;
                unset($nv[$valIdx], $optList[$valIdx]);
                if ($sub !== 'filename' || !in_array($op, ['=', '==', '!', '!!'], true)) {
                    $this->error($this->snippet('error_filter_value_invalid'), $e['key']);
                    continue;
                }
                $negate  = in_array($op, ['!', '!!'], true);
                $outer[] = ($negate ? '' : 'NOT ') . "EXISTS (SELECT 1 FROM `data_fil`"
                    . " WHERE `record_id` = `$tableName`.`{$tableName}_id`"
                    . " AND `fieldname_id` = ?"
                    . $this->liveFieldSql('', $fid)
                    . " AND `language_id` = " . $this->languageExpr($tableName)
                    . " AND `filename` != '')";
                $outerTexts[] = trim("$fieldLabel.$sub " . ($negate ? $strNot : '=') . ' ' . self::EMPTY_FILTER);
            }
            if (empty($nv)) continue;
            $nv      = array_values($nv);
            $optList = array_values($optList);
            // file size with multiplier: 500k, 1.5M, 2G (base 1024)
            if ($sub === 'size') $nv = array_map([$this, 'expandSizeUnits'], $nv);
            // extension without leading dot (".pdf" like "pdf")
            if ($sub === 'extension') $nv = array_map(fn($v) => (string)preg_replace('/(^|[=!<>\s])\.(?=\w)/', '$1', (string)$v), $nv);

            // column of the data_fil row (unqualified in the inner EXISTS)
            $colExpr = "`" . $colInfo['col'] . "`";
            $group = $this->buildScalarGroup(
                $colExpr, $nv, $optList, $mode, $colInfo['kind'],
                $fieldLabel . '.' . $sub, $strAnd, $strOr, $strNot
            );
            if ($group[0] === '') continue;

            $inner[] = $group[0];
            $types  .= $group[1];
            array_push($vals, ...$group[2]);
            $texts[] = $group[3];
        }

        if (empty($inner) && empty($outer)) return null;

        // Different subfields of the same file (bild.width, bild.height …) apply
        // together - like different fields of the search form (AND); filtermode acts
        // only on the values of ONE subfield (buildScalarGroup())
        $glue     = ' AND ';
        $textGlue = " $strAnd ";

        $parts     = [];
        $partTypes = '';
        $partVals  = [];
        if (!empty($inner)) {
            $innerSql = count($inner) === 1 ? $inner[0] : '(' . implode($glue, $inner) . ')';
            $parts[]  = "EXISTS (SELECT 1 FROM `data_fil`"
                      . " WHERE `record_id` = `$tableName`.`{$tableName}_id`"
                      . " AND `fieldname_id` = ?"
                      . $this->liveFieldSql('', $fid)
                      . " AND `language_id` = " . $this->languageExpr($tableName)
                      . " AND $innerSql)";
            $partTypes = 'i' . $types;
            $partVals  = array_merge([$fid], $vals);
        }
        foreach ($outer as $o) {
            $parts[]    = $o;
            $partTypes .= 'i';
            $partVals[] = $fid;
        }
        $texts = array_merge($texts, $outerTexts);

        return [
            count($parts) === 1 ? $parts[0] : '(' . implode($glue, $parts) . ')',
            $partTypes,
            $partVals,
            count($texts) === 1 ? $texts[0] : '(' . implode($textGlue, $texts) . ')',
        ];
    }

    /**
     * TEXTFELD
     * == and !! enforce exact search; no LIKE wildcard.
     * $col may already carry the language collation (collate()).
     */
    private function buildTextCondition(string $col, string $value, string $op): array
    {
        if ($op === '==') return ["$col = ?", 's', [$value]];
        if ($op === '!!') return ["$col != ?", 's', [$value]];

        if (str_contains($value, '*')) {
            [$sql, $types, $values] = $this->buildWildcardCondition($col, $value);
            return ($op === '!')
                ? ["NOT $sql", $types, $values]
                : [$sql, $types, $values];
        }
        if ($op === '!') return ["$col != ?", 's', [$value]];
        return ["$col $op ?", 's', [$value]];
    }

    /**
     * Wildcard search (Hen*, *mann, *berg*) by the collation rules.
     *
     * LIKE compares character by character and therefore does not find `weiß` with
     * 'weiss%', in German (ä = ae) also not `Müller` with 'Mue%'. The search is
     * therefore carried out with all spelling variants that the collation equates
     * (Collation::likeVariants()), combined with OR. LOCATE() would not help: MySQL
     * only compares sections of equal byte length there, `é` and `e` never.
     *
     * @param string $col    column expression, possibly with COLLATE
     * @param string $value  search value with at least one *
     * @return array         [sql (bracketed), bindTypes, values]
     */
    private function buildWildcardCondition(string $col, string $value): array
    {
        $values = array_map(
            fn(string $variant) => $this->toLikePattern($variant),
            Collation::likeVariants($value, $this->filterCollation)
        );
        $sql = '(' . implode(' OR ', array_fill(0, count($values), "$col LIKE ?")) . ')';
        return [$sql, str_repeat('s', count($values)), $values];
    }

    /**
     * NUMERIC FIELD
     */
    private function buildNumericCondition(string $col, string $value, string $op, string $type): array
    {
        $numVal   = is_numeric($value) ? $value + 0 : (int)$value;
        // Catch non-finite floating-point values (e.g. '1e400' from
        // $_REQUEST yields INF). mysqli/MySQL do not accept INF/NAN as
        // DOUBLE -> would trigger an SQL error. Error registration
        // happens centrally in buildScalarGroup()/buildScalarExpression().
        if (is_float($numVal) && !is_finite($numVal)) {
            return ['', '', []];
        }
        $bindType = ($type === 'float' || is_float($numVal)) ? 'd' : 'i';
        $op = match($op) {
            '=='     => '=',
            '!', '!!'=> '!=',
            default  => $op,
        };
        return ["$col $op ?", $bindType, [$numVal]];
    }

    /**
     * DATE FIELD (uniform for meta AND virtual fields).
     *
     * select()/filter() process only ISO format (zero-padded):
     * YYYY | YYYY-MM | YYYY-MM-DD | YYYY-MM-DD HH:[MM[:SS]]  ('T' allowed as separator)
     *
     * For field type 'timestampl' the value is converted from the PHP time zone to UTC.
     * Invalid/non-ISO input is ignored (empty condition).
     *
     * @return array{0:string,1:string,2:array}  [sql, bindTypes, values] or ['', '', []]
     */
    private function buildDateConditionUnified(string $col, string $value, string $op, string $type): array
    {
        $value = trim($value);

        // Relative specifications: now / today in the time zone of the call (user resp. language,
        // like typed data), utc_now / utc_today in UTC - each with offset (+3d, -2h,
        // +30m, -10s). "today" without hour/minute/second offset is a whole
        // day (like "2026-09-27"), "now" one second. UTC values are not converted
        // again below.
        $isUtc = false;
        if (preg_match('/^(utc_now|utc_today|now|today)(?:([+\-]\d+)([smhd]))?$/i', $value, $rel)) {
            $base = strtolower($rel[1]);
            $isUtc = str_starts_with($base, 'utc_');
            $isDay = str_ends_with($base, 'today');
            $dateTime = new \DateTime($isDay ? 'today' : 'now', $isUtc ? new \DateTimeZone('UTC') : null);
            $unit = strtolower($rel[3] ?? '');
            if ($unit !== '') {
                $dateTime->modify($rel[2] . ' ' . ['s' => 'seconds', 'm' => 'minutes', 'h' => 'hours', 'd' => 'days'][$unit]);
            }
            $value = $dateTime->format(($isDay && in_array($unit, ['', 'd'], true)) ? 'Y-m-d' : 'Y-m-d H:i:s');
        }

        // check ISO partial specification (zero-padded)
        $pattern = '/^(\d{4})(?:-(\d{2})(?:-(\d{2})(?:[ T](\d{2})(?::(\d{2})(?::(\d{2}))?)?)?)?)?$/';
        if (!preg_match($pattern, $value, $m)) {
            return ['', '', []];
        }

        $hasMonth = isset($m[2]) && $m[2] !== '';
        $hasDay   = isset($m[3]) && $m[3] !== '';
        $hasHour  = isset($m[4]) && $m[4] !== '';
        $hasMin   = isset($m[5]) && $m[5] !== '';
        $hasSec   = isset($m[6]) && $m[6] !== '';

        $year  = (int)$m[1];
        $month = $hasMonth ? (int)$m[2] : 1;
        $day   = $hasDay   ? (int)$m[3] : 1;
        $hour  = $hasHour  ? (int)$m[4] : 0;
        $min   = $hasMin   ? (int)$m[5] : 0;
        $sec   = $hasSec   ? (int)$m[6] : 0;

        // check validity (otherwise invalid input -> ignore)
        if (!checkdate($month, $day, $year)) return ['', '', []];
        if ($year < 1000 || $year > 9999)    return ['', '', []];
        if ($hour > 23 || $min > 59 || $sec > 59) return ['', '', []];

        // period start
        $lower = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec);

        // period end depends on the finest component given
        $month_end = $hasMonth ? $month : 12;
        if ($hasDay) {
            $day_end = $day;
        } else {
            // determine the last day of the month
            $dateString = sprintf('%04d-%02d-01', $year, $month_end);
            $date       = new \DateTimeImmutable($dateString);
            $day_end    = (int)$date->format('t');
        }
        $hour_end = $hasHour ? $hour : 23;
        $min_end  = $hasMin  ? $min : 59;
        $sec_end  = $hasSec  ? $sec : 59;

        $upper = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month_end, $day_end, $hour_end, $min_end, $sec_end);

        if ($type == 'timestampl' && !$isUtc) {
            // type 'timestampl': manual conversion to UTC (utc_now/utc_today already are)
            $pp = 'CONVERT_TZ(?, @@session.time_zone, \'UTC\')';
        } else {
            $pp = '?';
        }

        // operator -> SQL (value ranges, optimal index use)
        switch ($op) {
            case '=':   return ["($col >= $pp AND $col <= $pp)", 'ss', [$lower, $upper]];
            case '!':   return ["NOT ($col >= $pp AND $col <= $pp)", 'ss', [$lower, $upper]];
            case '>':   return ["$col > $pp",  's', [$upper]];
            case '>=':  return ["$col >= $pp", 's', [$lower]];
            case '<':   return ["$col < $pp",  's', [$lower]];
            case '<=':  return ["$col <= $pp", 's', [$upper]];
            case '==':  return ["$col = $pp",  's', [$lower]];
            case '!!':  return ["$col != $pp", 's', [$lower]];
            default:    return ['', '', []];
        }
    }

    /**
     * TIME FIELD (type 'time', data_tim).
     *
     * ISO format, e.g. "19:00". Padding via string mask.
     * Time zones: NO conversion.
     *
     * @return array{0:string,1:string,2:array}  [sql, 's', [string]] or ['', '', []]
     */
    private function buildTimeConditionUnified(string $col, string $value, string $op): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['', '', []];
        }

        $parts      = explode(':', $value);
        $hasMinutes = isset($parts[1]) && $parts[1] !== '';
        $hasSeconds = isset($parts[2]) && $parts[2] !== '';

        $hours   = abs((int)($parts[0] ?? 0));
        $minutes = abs((int)($parts[1] ?? 0));
        $seconds = abs((int)($parts[2] ?? 0));

        if ($hours > 23 || $minutes > 59 || $seconds > 59) {
            return ['', '', []];
        }

        // period start
        $lower = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);

        // period end
        $minutesEnd = $hasMinutes ? $minutes : 59;
        $secondsEnd = $hasSeconds ? $seconds : 59;
        $upper      = sprintf('%02d:%02d:%02d', $hours, $minutesEnd, $secondsEnd);

        // operator -> SQL (value ranges, optimal index use)
        switch ($op) {
            case '=':
                if ($hasSeconds) return ["$col = ?", 's', [$lower]];
                else             return ["($col >= ? AND $col <= ?)", 'ss', [$lower, $upper]];
            case '!':
                if ($hasSeconds) return ["$col != ?", 's', [$lower]];
                else             return ["NOT ($col >= ? AND $col <= ?)", 'ss', [$lower, $upper]];
            case '>':   return ["$col > ?",  's', [$upper]];
            case '>=':  return ["$col >= ?", 's', [$lower]];
            case '<':   return ["$col < ?",  's', [$lower]];
            case '<=':  return ["$col <= ?", 's', [$upper]];
            case '==':  return ["$col = ?",  's', [$lower]];
            case '!!':  return ["$col != ?", 's', [$lower]];
            default:    return ['', '', []];
        }
    }

    /**
     * FULLTEXT (MATCH ... AGAINST ... IN BOOLEAN MODE).
     * $col must be a real (indexed) column - for virtual
     * fulltext fields a FULLTEXT index on data_ftx.data is required.
     *
     * The search term is folded like the index by the rules of the search language
     * (indexText(), Collation::fold()). If a query searches in several languages
     * (backend, instances of different languages) and this yields different
     * search terms, each one is searched (OR).
     *
     * @return array{0:string,1:string,2:array} [sql, bindTypes, values]
     */
    private function buildFulltextCondition(string $col, string $value): array
    {
        $searches = [];
        foreach ($this->filterLanguageIds ?: [null] as $languageId) {
            $search = $this->fulltextSearchString($value, $languageId);
            if ($search !== '') $searches[$search] = true;
        }
        $searches = array_keys($searches);
        if (!$searches) {
            // After cleaning no word remains (only tags, punctuation): find nothing
            // - an omitted condition would have returned ALL records
            return ['1=0', '', []];
        }
        $sql = implode(' OR ', array_fill(0, count($searches), "MATCH($col) AGAINST(? IN BOOLEAN MODE)"));
        return [count($searches) > 1 ? "($sql)" : $sql, str_repeat('s', count($searches)), $searches];
    }

    /**
     * Search term for MATCH ... AGAINST in boolean mode, folded for a language.
     *
     * @param int|null $languageId  null = do not fold (tables other than `record`)
     * @return string               '' = no word left
     */
    private function fulltextSearchString(string $value, ?int $languageId): string
    {
        $processedTokens = [];

        foreach (explode(' ', $value) as $token) {
            $token = trim($token);
            if ($token === '') continue;

            // Leading MySQL operators (+, -, <, >, ~) and trailing wildcard (*)
            if (preg_match('/^([+\-~<>]*)(.*?)(\*?)$/u', $token, $matches)) {
                // Allow only ONE leading boolean operator.
                // Combinations such as '+-word' cause a syntax error in MySQL
                // in the boolean parser.
                $prefix   = $matches[1] === '' ? '' : $matches[1][0];
                $coreWord = $matches[2];
                $suffix   = $matches[3];

                if ($coreWord !== '') {
                    $cleanedWord = $this->indexText($coreWord, true, $languageId);
                    // '@' is a distance operator in boolean mode
                    // ("phrase" @n) and, standing alone, leads to a MySQL
                    // syntax error -> remove it from the search word.
                    $cleanedWord = str_replace('@', '', $cleanedWord);
                    $cleanedWord = trim($cleanedWord);
                    if ($cleanedWord === '') continue;
                    if (!str_contains($cleanedWord, ' ')) {
                        $processedTokens[] = $prefix . $cleanedWord . $suffix;
                    }
                    elseif ($suffix === '') {
                        // "sofa-weiss" -> word sequence "sofa weiss" (as indexText() stores it)
                        $processedTokens[] = $prefix . '"' . $cleanedWord . '"';
                    }
                    else {
                        // with wildcard no phrase possible: each word with the
                        // operator, the wildcard on the last one
                        $words = explode(' ', $cleanedWord);
                        $last = array_pop($words);
                        foreach ($words as $word) $processedTokens[] = $prefix . $word;
                        $processedTokens[] = $prefix . $last . $suffix;
                    }
                }
            }
        }
        return implode(' ', $processedTokens);
    }

    /**
     * JSON-FELD (Typ 'json').
     *
     * Flat JSON array, search via JSON_CONTAINS; * is NOT a wildcard.
     *   =  / ==  ->     JSON_CONTAINS(col, JSON_QUOTE(?))
     *   !  / !!  -> NOT JSON_CONTAINS(col, JSON_QUOTE(?))
     *
     * @return array{0:string,1:string,2:array}  [sql, 's', [value]]
     */
    private function buildJsonCondition(string $col, string $value, string $op): array
    {
        if (in_array($op, ['!', '!!'], true)) {
            return ["NOT JSON_CONTAINS($col, JSON_QUOTE(?))", 's', [$value]];
        }
        return ["JSON_CONTAINS($col, JSON_QUOTE(?))", 's', [$value]];
    }

    /**
     * Normalizes the raw value (string or array) to a flat string array.
     * OR/AND expressions in the string are passed on as a whole value.
     */
    private function normalizeValues(mixed $rawValue): array
    {
        $items  = is_array($rawValue) ? $rawValue : [$rawValue];
        $result = [];
        foreach ($items as $item) {
            // Accept only scalar values. Nested request
            // arrays (filter[title][0][]=x) would have produced via the (string) cast the
            // literal value "Array".
            if (!is_scalar($item)) continue;
            $item = (string)$item;
            $item = preg_replace('/\s+/', ' ', $item);
            $item = trim($item);
            if ($item === '') continue;

            // OR/AND -> do not split on comma, the entire logic is in the expression
            if (preg_match('/\s+(?:OR|AND)\s+/i', $item)) {
                $result[] = $item;
                continue;
            }

            $parts = array_map('trim', explode(',', $item));
            foreach ($parts as $part) {
                if ($part !== '') {
                    $result[] = $part;
                }
            }
        }
        return $result;
    }

    
    /**
     * Parse the filterop list for a field key
     * (comma-separated operator list, index-synchronous with the values).
     */
    private function parseOptList(array $optReq, string $key): array
    {
        if (!isset($optReq[$key])) return [];
        $raw = $optReq[$key];
        // filterop can also arrive per request as an array (filterop[preis][]=..)
        // -> take the elements as scalars instead of a (string) cast on the array
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $r) {
                if (is_scalar($r)) $out[] = trim((string)$r);
            }
            return $out;
        }
        if ((string)$raw === '') return [];
        return array_map('trim', explode(',', (string)$raw));
    }

    /**
     * Condition for the filter value "(empty)": the value of the language is empty or a
     * fallback from the main language (`empty` = 1, see hasEmptyFlag()).
     *
     * "Empty" per type: text/encrypted/JSON an empty string, date the lower bound
     * DATE_MIN (no date set), time NULL resp. '' - numbers have no empty value,
     * there only the fallback flag counts. For columns without `empty`
     * (meta fields, data_dft, data_fil) only the empty comparison remains.
     *
     * @param string $colExpr  column expression, e.g. `_vf_0`.`data` or `data`
     * @param string $type     field type
     */
    private function emptyCondition(string $colExpr, string $type): string
    {
        $parts = [];
        if (in_array($type, $this->dateTypes, true)) {
            $parts[] = "$colExpr <= '" . self::DATE_MIN . "'";
        }
        elseif ($type === 'time') {
            $parts[] = "($colExpr IS NULL OR $colExpr = '')";
        }
        elseif (!in_array($type, $this->numericTypes, true)) {
            // text, chars, encrypted (hash of '' is ''), json
            $parts[] = "$colExpr = ''";
        }
        if ($this->hasEmptyFlag($type) && ($emptyExpr = $this->emptyFlagExpr($colExpr)) !== null) {
            $parts[] = "$emptyExpr = 1";
        }
        if (empty($parts)) return '1=0';
        return count($parts) === 1 ? $parts[0] : '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * Column expression of the `empty` column for a data_* column expression - null,
     * if the column does not belong to a data_* table with `empty`.
     *
     *     `_vf_0`.`data`   -> `_vf_0`.`empty`     (scalar join, registerScalarJoin())
     *     `_rsd_3`.`data`  -> `_rsd_3`.`empty`    (field of the relation target)
     *     `data`           -> `empty`             (inner EXISTS on data_*)
     *     `record`.`title`, `_dft_1`.`data` ...   -> null
     */
    private function emptyFlagExpr(string $colExpr): ?string
    {
        if ($colExpr === '`data`') return '`empty`';
        if (preg_match('/^`(_vf_\d+|_rsd_\d+)`\.`[a-z_]+`$/', $colExpr, $m)) {
            return '`' . $m[1] . '`.`empty`';
        }
        return null;
    }

    /**
     * OPERATOR-PRAEFIX extrahieren.
     *
     * A value beginning with an HTML tag (`<section>`, `</p>`, `<!--`) is
     * not a "less than" comparison - otherwise the search for code in templates with
     * `<section>` would find everything alphabetically before "section>". It counts as a value;
     * contained instead of equal: `*<section>*`.
     */
    private function extractOperator(string $value): array
    {
        if (preg_match('~^</?[a-z!][^<>]*>~i', $value)) {
            return ['=', $value];
        }
        $twoChar    = substr($value, 0, 2);
        $oneChar    = substr($value, 0, 1);
        $twoCharOps = ['==', '>=', '<=', '>>', '<<', '!!', '!='];
        $oneCharOps = ['=', '>', '<', '!'];

        if (in_array($twoChar, $twoCharOps, true)) {
            $op = match($twoChar) {
                '>>'    => '>',
                '<<'    => '<',
                '!='    => '!',
                default => $twoChar,
            };
            return [$op, substr($value, 2)];
        }
        if (in_array($oneChar, $oneCharOps, true)) {
            return [$oneChar, substr($value, 1)];
        }
        return ['=', $value];
    }

    /**
     * Converts a search string with * into a LIKE pattern.
     *   * -> %      . -> \.      mask % and _ in the original
     */
    private function toLikePattern(string $value): string
    {
        $value = str_replace(['%', '_'], ['\%', '\_'], $value);
        $value = str_replace('.', '\.', $value);
        $value = str_replace('*', '%', $value);
        return $value;
    }

    /**
     * Returns the associated data_* table for a field type.
     */
    private function virtualDataTable(string $type): string
    {
        return 'data_' . ($this->dataSuffix[$type] ?? 'txt');
    }

    /**
     * Resolves a virtual field name via the table `fieldname`.
     * Result is cached.
     *
     * @return array{fieldname_id:int,type:string}|null  null = field not found
     */
    public function resolveVirtualField(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') return null;

        if (isset($this->fieldnameCache[$name])) {
            return $this->fieldnameCache[$name] ?: null;
        }

        // save the running result (subquery safety)
        $this->db->pushResult();
        $this->db->query(
            "SELECT `fieldname_id`, `type` FROM `fieldname` WHERE `name` = ? LIMIT 1",
            's',
            [$name]
        );
        $row = $this->db->fetch();
        $this->db->popResult();

        if (!$row) {
            $this->fieldnameCache[$name] = false;
            return null;
        }
        $resolved = [
            'fieldname_id' => (int)$row['fieldname_id'],
            'type'         => (string)$row['type']
        ];
        $this->fieldnameCache[$name] = $resolved;
        return $resolved;
    }

    /**
     * Forget the mapping field name -> type and the simplified area schemas -
     * after a type change of a field in the same request (Field::convert()).
     */
    public function clearFieldCaches(): void
    {
        $this->fieldnameCache = [];
        $this->virtualSchemaCache = [];
        $this->deletedFieldIds = null;
    }

    /**
     * Ids of the soft-deleted input fields (`field`.`deleted` = 1, trash),
     * grouped by fieldname_id - from the file cache (InstanceCache::deletedFieldIds()),
     * no query. Normally the trash holds no field and the result is empty.
     *
     * The data_* rows of such a field stay until the final deletion (restore),
     * but must neither be found nor sort: they share the fieldname_id with the
     * live fields of the same name in other areas.
     *
     * @return array<int, int[]>  fieldname_id => field_ids
     */
    public function deletedFieldIds(): array
    {
        return $this->deletedFieldIds ??= $this->instanceCache->deletedFieldIds();
    }

    /**
     * SQL condition that hides the data_* rows of soft-deleted fields, for a data
     * alias with a fixed fieldname_id - '' if no field of this name is in the
     * trash (the normal case: the query stays unchanged).
     *
     * @param string   $alias         alias of the data_* table ('' = unqualified)
     * @param int|null $fieldnameId   fieldname_id of the condition; null = all fields
     *                                (relation=any)
     * @return string                 ' AND `alias`.`field_id` NOT IN (…)' or ''
     */
    private function liveFieldSql(string $alias, ?int $fieldnameId): string
    {
        $map = $this->deletedFieldIds();
        if (!$map) return '';
        $ids = ($fieldnameId === null) ? array_merge(...array_values($map)) : ($map[$fieldnameId] ?? []);
        if (!$ids) return '';
        $col = ($alias === '' ? '' : "`$alias`.") . '`field_id`';
        return " AND $col NOT IN (" . implode(',', array_map('intval', $ids)) . ")";
    }

    /**
     * Nested-set filter for parent_id.
     *
     * Builds a WHERE condition that finds all descendants of the given parent nodes.
     * The depth limit is relative to the respective parent node.
     *
     * $depthLimit:  < 0 → all descendants; 1 → only direct children; n → up to relative depth n
     *
     * AREA BOUNDARY
     * Each content area keeps its own tree, lft/rgt each start there
     * again at 1 (see TreeManagerTrait). The area of the parent node is
     * therefore filtered along - without it, records of foreign areas would fall into the same
     * numeric range. A record from an area WITHOUT a tree has
     * lft = rgt = 0 and therefore no descendants.
     *
     * ROOT LEVEL
     * `parent_id` = NULL means the descendants of the (imagined) root, i.e. all
     * records; `depth` is 0-based, the root nodes themselves have depth = 0.
     * Records from areas without a tree likewise have depth = 0 and thus count
     * as root nodes - `parent_id=&depth=1` there returns everything, as expected.
     *
     * @param bool $hasAreaId  table has an `area_id` (i.e. `record`)
     * @return array{sql:string, types:string, values:array, text:string}|null
     */
    private function buildParentCondition(
        string $tableName,
        array  $normalizedValues,
        int    $depthLimit,
        bool   $hasAreaId = false
    ): ?array {
        // collect IDs; empty string / 'null' → NULL root entry
        $ids     = [];
        $hasNull = false;
        foreach ($normalizedValues as $val) {
            $val = trim((string)$val);
            if ($val === '' || strtolower($val) === 'null') {
                $hasNull = true;
            } elseif (is_numeric($val) && (int)$val > 0) {
                $ids[] = (int)$val;
            } else {
                $this->error($this->snippet('error_filter_value_invalid'), 'parent_id');
            }
        }
        if (empty($ids) && !$hasNull) return null;

        $tbl       = "`$tableName`";
        $fieldName = $tableName . '_id';
        $parts     = [];
        $bindTypes = '';
        $values    = [];

        // children of nodes with known ID (sub-queries on PRIMARY KEY)
        foreach ($ids as $id) {
            $part       = "(`lft` > (SELECT `lft` FROM $tbl WHERE `$fieldName` = ?)"
                        . " AND `rgt` < (SELECT `rgt` FROM $tbl WHERE `$fieldName` = ?)";
            $bindTypes .= 'ii';
            $values[]   = $id;
            $values[]   = $id;
            if ($depthLimit > 0) {
                $part      .= " AND `depth` <= (SELECT `depth` FROM $tbl WHERE `$fieldName` = ?) + $depthLimit";
                $bindTypes .= 'i';
                $values[]   = $id;
            }
            if ($hasAreaId) {
                // area boundary: lft/rgt count per content area
                $part      .= " AND `area_id` = (SELECT `area_id` FROM $tbl WHERE `$fieldName` = ?)";
                $bindTypes .= 'i';
                $values[]   = $id;
            }
            $parts[] = $part . ')';
        }

        // children of the root (parent_id IS NULL) -> filtering via depth (0-based:
        // root nodes have depth = 0, 'only direct children' is therefore depth <= 0)
        if ($hasNull) {
            if ($depthLimit > 0) {
                $parts[] = '`depth` <= ' . ($depthLimit - 1);
            } else {
                $parts[] = '`depth` >= 0';   // all descendants of the root = all records
            }
        }

        if (empty($parts)) return null;

        $sql = count($parts) === 1
            ? $parts[0]
            : '(' . implode(' OR ', $parts) . ')';

        // readable text for the filter display
        $idList    = implode(', ', $ids);
        $nullText  = $hasNull ? ($ids ? ', NULL' : 'NULL') : '';
        $depthText = $depthLimit > 0
            ? ' (' . $this->snippet('filter_depth_limit') . ' ' . $depthLimit . ')'
            : '';
        $text = $this->snippet('filter_parent_id') . ' = ' . $idList . $nullText . $depthText;

        return [
            'sql'    => $sql,
            'types'  => $bindTypes,
            'values' => $values,
            'text'   => $text,
        ];
    }

    /**
     * JOIN condition for an n:1 RELATION/POINTER field
     * (type 'relation1'/'pointer1', checked in advance by filter()).
     *
     * For these types, replaces the correlated EXISTS from buildRelationExists():
     * data_rel yields at most ONE row per (src_id, fieldname_id) ->
     * a LEFT JOIN produces no multiple rows and is semantically
     * equivalent, but gives the optimizer full freedom (index access
     * via data_rel instead of a correlated subquery per row).
     *
     * Equivalences (relating to the one data_rel row):
     *   EXISTS(dest_id = X)      ->  `rel`.`dest_id` = X        (NULL -> no hit)
     *   NOT EXISTS(dest_id = X)  ->  (`rel`.`dest_id` IS NULL OR `rel`.`dest_id` != X)
     *   EXISTS(... subfield ...) ->  condition on the joined target column
     *                                (NULL-rejecting -> like EXISTS)
     *
     * LEFT (not INNER) JOIN: needed for the NOT-EXISTS semantics of the
     * negation (records WITHOUT a link satisfy '!id'). Positive
     * conditions reject NULL -> the optimizer then converts the LEFT JOIN
     * itself into an INNER JOIN.
     * A possibly registered but unused JOIN (e.g. all
     * values invalid) is harmless: a LEFT JOIN with at most one row
     * restricts nothing and produces no duplicates.
     * CAUTION: same existing-data guarantee as for sorting - if
     * data_rel, contrary to the n:1 guarantee, contains several rows per src_id, duplicates arise
     * (see registerRelationJoin()).
     *
     * fieldname_id is NOT bound in the WHERE parameters, but
     * sits in the JOIN (joinCtx) - bind order: JOINs before WHERE.
     *
     * @return array{0:string,1:string,2:array,3:string}|null
     */
    private function buildRelationJoinGroup(
        string $tableName,
        string $fieldLabel,
        int    $fid,
        array  $entries,
        string $mode,
        array  $optReq,
        array  &$joinCtx,
        string $strAnd,
        string $strOr,
        string $strNot
    ): ?array {
        $predicates = [];
        foreach ($entries as $e) {
            $sub = $e['sub'];
            $nv  = $this->normalizeValues($e['raw']);
            // target IDs directly (main resp. main.record_id)
            if ($sub === '' || $sub === 'record_id') {
                $pos = [];
                $neg = [];
                foreach ($nv as $v) {
                    [$op, $val] = $this->extractOperator($v);
                    if (strcasecmp($val, self::EMPTY_FILTER) === 0) {
                        $predicates[] = $this->relationEmptyPredicate($tableName, $fieldLabel, $fid, $op === '!' || $op === '!!', $strNot);
                        continue;
                    }
                    if (!is_numeric($val)) {
                        $this->error($this->snippet('error_filter_value_invalid'), $e['key']);
                        continue;
                    }
                    if (in_array($op, ['!', '!!'], true)) $neg[] = (int)$val;
                    else                                  $pos[] = (int)$val;
                }
                // register the JOIN only when target IDs need it - an
                // unused JOIN is harmless only as long as data_rel keeps the n:1 guarantee
                if ($pos || $neg) {
                    $relAlias = $this->registerRelationBaseJoin($tableName, $fid, $joinCtx, 'filter');
                    $destCol  = "`$relAlias`.`dest_id`";
                }
                if ($pos) {
                    if ($mode === 'AND') {
                        // as in the EXISTS path: one own condition per target ID
                        // (with n:1 and several IDs necessarily empty -
                        // identical to the previous EXISTS variant)
                        foreach ($pos as $pid) {
                            $predicates[] = [
                                "$destCol = ?",
                                'i',
                                [$pid],
                                $fieldLabel . ' = ' . $pid,
                            ];
                        }
                    } else {
                        // OR -> IN list on the joined row
                        $ph = implode(',', array_fill(0, count($pos), '?'));
                        $predicates[] = [
                            "$destCol IN ($ph)",
                            str_repeat('i', count($pos)),
                            $pos,
                            $fieldLabel . ' = ' . implode(', ', $pos),
                        ];
                    }
                }
                foreach ($neg as $nid) {
                    // NOT-EXISTS semantics: also hit records WITHOUT a link
                    $predicates[] = [
                        "($destCol IS NULL OR $destCol != ?)",
                        'i',
                        [$nid],
                        $fieldLabel . ' ' . $strNot . ' ' . $nid,
                    ];
                }
                continue;
            }
            $subLabel = $fieldLabel . '.' . $sub;
            $optList  = $this->parseOptList($optReq, $e['key']);
            // Register JOIN chain (data_rel + record resp. data_*).
            // Validates the subfield (meta field / virtual scalar field,
            // relation.relation / file forbidden) and registers errors itself.
            $colExpr = $this->registerRelationJoin(
                $tableName, $fid, $joinCtx, $sub, $e['key'], 'filter'
            );
            if ($colExpr === null) continue;
            // Determine type of the subfield for building the condition
            // (resolveVirtualField is cached -> no second DB access)
            // '_default' is neither meta nor fieldname field -> type 'text'
            if ($sub === '_default') {
                $subType = 'text';
            } elseif (isset($this->schema[$sub])) {
                $subType = $this->schema[$sub]['type'] ?? 'text';
            } else {
                $subType = $this->resolveVirtualField($sub)['type'];
            }
            $group = $this->buildScalarGroup(
                $colExpr, $nv, $optList, $mode, $subType, $subLabel, $strAnd, $strOr, $strNot
            );
            if ($group[0] === '') continue;
            $predicates[] = $group;
        }
        if (empty($predicates)) return null;
        $glue     = ($mode === 'AND') ? ' AND ' : ' OR ';
        $textGlue = ($mode === 'AND') ? " $strAnd " : " $strOr ";
        $sqlParts = [];
        $types    = '';
        $vals     = [];
        $texts    = [];
        foreach ($predicates as $pr) {
            $sqlParts[] = $pr[0];
            $types     .= $pr[1];
            array_push($vals, ...$pr[2]);
            $texts[]    = $pr[3];
        }
        $sql  = count($sqlParts) === 1 ? $sqlParts[0] : '(' . implode($glue, $sqlParts) . ')';
        $text = count($texts) === 1    ? $texts[0]    : '(' . implode($textGlue, $texts) . ')';
        return [$sql, $types, $vals, $text];
    }

    /**
     * Registers (or finds) the base LEFT JOIN on data_rel for an
     * n:1 relation field. Common basis for the filtering
     * (buildRelationJoinGroup()) and the JOIN chain in registerRelationJoin().
     * Reuse via 'map' (key 'relj:<fieldname_id>') - filter and
     * sorting share the same alias. Since filter() runs before the
     * sort processing, a shared JOIN then carries
     * phase 'filter' and also appears in the COUNT query (harmless:
     * a LEFT JOIN with at most one row does not change the count).
     */
    private function registerRelationBaseJoin(
        string $tableName,
        int    $relFid,
        array  &$ctx,
        string $phase
    ): string {
        $baseKey = 'relj:' . $relFid;
        if (isset($ctx['map'][$baseKey])) {
            return $ctx['map'][$baseKey];
        }
        $relAlias = '_rs_' . $ctx['seq']++;
        $ctx['joins'][$relAlias] = [
            'sql'    => "LEFT JOIN `data_rel` AS `$relAlias`"
                      . " ON `$relAlias`.`src_id` = `$tableName`.`{$tableName}_id`"
                      . " AND `$relAlias`.`fieldname_id` = ?"
                      . $this->liveFieldSql($relAlias, $relFid)
                      // omit edges to working copies: check-out also creates the
                      // incoming edges to the copy - the field would then have two
                      // rows, the JOIN would yield duplicates
                      . " AND NOT EXISTS (SELECT 1 FROM `record` `{$relAlias}_t`"
                      . " WHERE `{$relAlias}_t`.`record_id` = `$relAlias`.`dest_id` AND `{$relAlias}_t`.`tmp` = 1)",
            'types'  => 'i',
            'values' => [$relFid],
            'phase'  => $phase,
        ];
        $ctx['map'][$baseKey] = $relAlias;
        return $relAlias;
    }

    /**
     * Registers the LEFT JOIN chain for an n:1 RELATION/POINTER
     * field (type 'relation1'/'pointer1', checked in advance by the caller via
     * $this->singleRelationTypes!). Used by the
     * SORTING (phase 'sort') and also by the FILTERING
     * (phase 'filter', see buildRelationJoinGroup()).
     * Both share the same JOIN chain via 'map', i.e.
     * filter[myrelation.title] and sort=myrelation.title create only
     * ONE data_rel access.
     *
     *   sort=myrelation           -> meta field 'changed_at' of the target (default)
     *   sort=myrelation.title     -> virtual scalar field of the target
     *   sort=myrelation.status_id -> meta field of the target
     *   relation.relation(.x)     -> not allowed (error_relation_relation)
     *   relation.file             -> not allowed
     *
     * JOIN chain (phase 'sort' -> only in the main SELECT, not in the COUNT query;
     * phase 'filter' -> also in the COUNT query):
     *   LEFT JOIN data_rel _rs_n   (src_id = record_id AND fieldname_id = ?)
     *   LEFT JOIN record   _rsr_n  for meta field:      record_id = _rs_n.dest_id
     *   LEFT JOIN data_*   _rsd_n  for virtual field:   record_id = _rs_n.dest_id
     *                                                   AND fieldname_id = ?
     *
     * LEFT (not INNER): records without a link must not drop out
     * of the result when merely sorting (NULL sorts to the end/start);
     * when filtering, LEFT is needed for the NOT-EXISTS semantics of negation.
     * Positive filter conditions are NULL-rejecting -> the optimizer
     * then converts the LEFT JOIN itself into an INNER JOIN.
     * For types 'relation1'/'pointer1' data_rel yields at most ONE
     * row per src_id -> no row inflation, no DISTINCT needed.
     * CAUTION: If the existing data, contrary to the type guarantee, contains several rows
     * per src_id (e.g. after a subsequent type change from 'relation' to
     * 'relation1' without data cleanup), duplicates arise in the result.
     * Reuse of the aliases via 'map': multiple sorting such as
     * "myrelation.title, myrelation.status_id" uses the same data_rel JOIN.
     *
     * @param string $tableName  base table (e.g. 'record')
     * @param int    $relFid     fieldname_id of the relation field
     * @param array  &$ctx       JOIN context
     * @param string $sub        subfield ('' = default 'changed_at')
     * @param string $fullName   complete field/sort key (for error messages)
     * @param string $phase      'filter' | 'sort'
     * @return ?string           column expression or null (error registered)
     */
    private function registerRelationJoin(
        string $tableName,
        int    $relFid,
        array  &$ctx,
        string $sub,
        string $fullName,
        string $phase = 'sort'
    ): ?string {
        // Error snippets per phase, so that the messages match the previous
        // paths (filter: error_field_unknown_type, sorting:
        // error_sort_field_unknown / error_sort_field_invalid).
        $errUnknown = ($phase === 'filter') ? 'error_field_unknown_type' : 'error_sort_field_unknown';
        $errInvalid = ($phase === 'filter') ? 'error_field_unknown_type' : 'error_sort_field_invalid';
        // Without subfield: meta field 'changed_at' of the linked record
        if ($sub === '') $sub = 'changed_at';
        // '_default' of the TARGET record (data_dft, without fieldname_id).
        // Not a meta field and not resolvable via `fieldname` -> own branch
        // BEFORE the subfield validation (analogous to registerDefaultJoin(), but
        // joined on dest_id of the relation).
        if ($sub === '_default') {
            // base JOIN on data_rel (only once per relation field)
            $relAlias = $this->registerRelationBaseJoin($tableName, $relFid, $ctx, $phase);
            $dftKey = 'relj:' . $relFid . ':dft';
            if (isset($ctx['map'][$dftKey])) {
                $dftAlias = $ctx['map'][$dftKey];
            } else {
                $dftAlias = '_rsdft_' . $ctx['seq']++;
                // language of the TARGET record: include its record row for this
                $recAlias = $this->registerRelationRecordJoin($relFid, $relAlias, $ctx, $phase);
                $ctx['joins'][$dftAlias] = [
                    // NO fieldname_id needed in the ON condition
                    'sql'    => "LEFT JOIN `data_dft` AS `$dftAlias`"
                              . " ON `$dftAlias`.`record_id` = `$relAlias`.`dest_id`"
                              . " AND `$dftAlias`.`language_id` = " . $this->languageExpr($recAlias),
                    'types'  => '',
                    'values' => [],
                    'phase'  => $phase,
                ];
                $ctx['map'][$dftKey] = $dftAlias;
            }
            return "`$dftAlias`.`data`";
        }
        // validate the subfield BEFORE the JOIN registration, so that on errors
        // no unused data_rel JOIN remains in the context.
        $isMetaSub = isset($this->schema[$sub]);
        $subField  = null;
        if (!$isMetaSub) {
            $subField = $this->resolveVirtualField($sub);
            if ($subField === null) {
                // register unknown subfield
                $this->error($this->snippet($errUnknown), $fullName);
                return null;
            }
            if (in_array($subField['type'], $this->relationTypes, true)) {
                // Relation.Relation not allowed (analogous to filter)
                $this->error($this->snippet('error_relation_relation'), $fullName);
                return null;
            }
            if ($subField['type'] === 'file') {
                // nested too deeply -> not supported
                $this->error($this->snippet($errInvalid), $fullName);
                return null;
            }
            // password hashes are not sortable (the filter yields no hit
            // via buildScalarGroup())
            if ($phase === 'sort' && in_array($subField['type'], $this->unsearchableTypes, true)) {
                $this->error($this->snippet($errInvalid), $fullName);
                return null;
            }
        }
        // base JOIN on data_rel (only once per relation field)
        $relAlias = $this->registerRelationBaseJoin($tableName, $relFid, $ctx, $phase);
        // Subfield = META FIELD of the target record
        if ($isMetaSub) {
            $recAlias = $this->registerRelationRecordJoin($relFid, $relAlias, $ctx, $phase);
            return "`$recAlias`.`$sub`";
        }
        // Subfield = virtual scalar field of the target record
        // The language condition follows the instance of the TARGET record -
        // for this, its record row is also joined (PK access, cheap).
        $recAlias  = $this->registerRelationRecordJoin($relFid, $relAlias, $ctx, $phase);
        $dataTable = $this->virtualDataTable($subField['type']);
        $subFid    = (int)$subField['fieldname_id'];
        $subKey    = 'relj:' . $relFid . ':vf:' . $subFid;
        if (isset($ctx['map'][$subKey])) {
            $dAlias = $ctx['map'][$subKey];
        } else {
            $dAlias = '_rsd_' . $ctx['seq']++;
            $ctx['joins'][$dAlias] = [
                'sql'    => "LEFT JOIN `$dataTable` AS `$dAlias`"
                          . " ON `$dAlias`.`record_id` = `$relAlias`.`dest_id`"
                          . " AND `$dAlias`.`fieldname_id` = ?"
                          . $this->liveFieldSql($dAlias, $subFid)
                          . " AND `$dAlias`.`language_id` = " . $this->languageExpr($recAlias),
                'types'  => 'i',
                'values' => [$subFid],
                'phase'  => $phase,
            ];
            $ctx['map'][$subKey] = $dAlias;
        }
        return "`$dAlias`.`data`";
    }

    /**
     * Registers (or finds) the LEFT JOIN on the `record` row of the
     * LINKED record of an n:1 relation field. Needed for its
     * meta fields and for the language condition of its data_* rows
     * (languageExpr() follows `instance_id`).
     *
     * @param int    $relFid    fieldname_id of the relation field
     * @param string $relAlias  alias of the data_rel JOIN (registerRelationBaseJoin())
     * @param array  &$ctx      JOIN-Kontext
     * @param string $phase     'filter' | 'sort'
     * @return string           alias of the record row
     */
    private function registerRelationRecordJoin(int $relFid, string $relAlias, array &$ctx, string $phase): string
    {
        $recKey = 'relj:' . $relFid . ':rec';
        if (isset($ctx['map'][$recKey])) {
            return $ctx['map'][$recKey];
        }
        $recAlias = '_rsr_' . $ctx['seq']++;
        $ctx['joins'][$recAlias] = [
            'sql'    => "LEFT JOIN `record` AS `$recAlias`"
                      . " ON `$recAlias`.`record_id` = `$relAlias`.`dest_id`",
            'types'  => '',
            'values' => [],
            'phase'  => $phase,
        ];
        $ctx['map'][$recKey] = $recAlias;
        return $recAlias;
    }
    
    /**
     * Registers (or finds) a LEFT JOIN on data_fil for the
     * sorting by file metadata.
     *
     *   sort=myfile        -> `filename` (Default)
     *   sort=myfile.size   -> `size`   (analog width/height/filename)
     *
     * data_fil has at most ONE row per (record_id, fieldname_id)
     * (PRIMARY KEY) -> the LEFT JOIN produces no multiple rows, no
     * DISTINCT needed. LEFT (not INNER): records without a file must not
     * drop out of the result when merely sorting.
     * Multiple sorting over the same field (e.g. myfile.width, myfile.height)
     * uses the same alias (reuse via 'map').
     *
     * @param string $tableName  base table (e.g. 'record')
     * @param int    $fid        fieldname_id of the file field
     * @param array  &$ctx       JOIN context
     * @param string $sub        subfield ('' = default 'filename')
     * @param string $fullName   complete sort key (for error messages)
     * @return ?string           column expression or null (error registered)
     */
    private function registerFileSortJoin(
        string $tableName,
        int    $fid,
        array  &$ctx,
        string $sub,
        string $fullName
    ): ?string {
        // Whitelist of sortable data_fil columns.
        if ($sub === '') $sub = 'filename';   // default sort column
        if (!in_array($sub, $this->fileSortCols, true)) {
            // register non-sortable file subfield (e.g. ocr_text)
            $this->error($this->snippet('error_sort_field_invalid'), $fullName);
            return null;
        }
        $cacheKey = 'fil:' . $fid;
        if (isset($ctx['map'][$cacheKey])) {
            $alias = $ctx['map'][$cacheKey];
        } else {
            $alias = '_fil_' . $ctx['seq']++;
            $ctx['joins'][$alias] = [
                'sql'    => "LEFT JOIN `data_fil` AS `$alias`"
                          . " ON `$alias`.`record_id` = `$tableName`.`{$tableName}_id`"
                          . " AND `$alias`.`fieldname_id` = ?"
                          . $this->liveFieldSql($alias, $fid)
                          . " AND `$alias`.`language_id` = " . $this->languageExpr($tableName),
                'types'  => 'i',
                'values' => [$fid],
                'phase'  => 'sort',
            ];
            $ctx['map'][$cacheKey] = $alias;
        }
        return "`$alias`.`$sub`";
    }

    // ====================

    /**
     * Provide the simplified schema of an area in $this->virtualSchemaCache($areaId)
     * 
     * @param int $areaId  area_id
     */
    protected function loadSimpleSchema(int $areaId): void
    {
        if (empty($this->virtualSchemaCache[$areaId])) {
            $this->db->pushResult();
            $this->db->query("SELECT `field_id`, `name`, `type`, `multilingual` FROM `field` WHERE `area_id`=? AND `deleted`=0", 'i', [$areaId]);
            $this->virtualSchemaCache[$areaId] = $this->db->fetchAll();
            $this->db->popResult();
        }
    }

    /**
     * Renew the content cache of a record - for ALL languages of its instance.
     *
     * From the data_* rows a JSON image of the virtual fields is created per language
     * (without relations) and stored in `record_cache`:
     *
     *     {"feldname": ["typ", wert], "text": ["text", "English text", 1], ...}
     *
     * The third element 1 marks, for a MULTILINGUAL field, that the value was not
     * entered in this language, but is the fallback from the main language.
     * TemporaryTrait::checkOut() turns it back into an empty input. The
     * origin is in column `empty` of the data_* row (hasEmptyFlag()), which
     * saveVirtualFields() sets when writing - the rebuild is thus as exact
     * as regular saving; file fields carry the same in file_language_id.
     *
     * Single-language fields have the same value in all languages (without marker).
     * Encrypted contents are base64-encoded in the cache - the ciphertext is
     * binary and could not be stored as JSON.
     *
     * @param int  $id          record id
     * @param int  $areaId      id of the associated content area
     * @param int  $instanceId  instance of the record (0 = look up)
     * @param ?int $languageId  language whose cache is returned
     *                          (null = language per recordLanguageId())
     * @return array            contents of the virtual fields of this language
     */
    public function createRecordCache(int $id, int $areaId, int $instanceId = 0, ?int $languageId = null): array
    {
        if ($id < 1 || $areaId < 1) {
            echo "createRecordCache() internal error : id or areaId is empty";
            return [];
        }
        $this->db->pushResult();

        if ($instanceId < 1) {
            $this->db->query("SELECT `instance_id` FROM `record` WHERE `record_id` = ?", 'i', [$id]);
            $instanceId = (int)($this->db->fetchNum()[0] ?? 0);
        }
        $languageIds = $this->instanceCache->languageIds($instanceId);
        if (empty($languageIds)) $languageIds = [$this->instanceCache->primaryLanguageId($instanceId)];
        $primaryId = $languageIds[0];
        if ($languageId === null) $languageId = $this->recordLanguageId($instanceId);

        // provide the simplified schema of the virtual fields
        $this->loadSimpleSchema($areaId);
        $schemaVirtual = &$this->virtualSchemaCache[$areaId];

        $caches = array_fill_keys($languageIds, []);
        foreach ($schemaVirtual as $field) {
            $fieldId = (int)$field['field_id'];
            $name = (string)$field['name'];
            $type = (string)$field['type'];
            $multilingual = !empty($field['multilingual']);
            if (in_array($type, $this->relationTypes, true)) continue;
            $dataTable = 'data_' . $this->dataSuffix[$type];

            // fetch rows of all languages: language_id => value
            $rows = [];
            if ($type === 'file') {
                $colExpr = '`language_id`, `' . implode('`,`', $this->fileCacheCols) . '`';
                $this->db->query("SELECT $colExpr FROM `{$dataTable}` WHERE `record_id` = ? AND `field_id` = ?", 'ii', [$id, $fieldId]);
                while ($row = $this->db->fetch()) {
                    $lang = (int)$row['language_id'];
                    unset($row['language_id']);
                    $row['file_language_id'] = $this->fileLanguageId($row);
                    $rows[$lang] = $row;
                }
                // File fields: the row of each language is already complete (with
                // fallback); a fallback is recognizable by the file belonging to
                // another language
                foreach ($languageIds as $lang) {
                    $row = $rows[$lang] ?? ($rows[$primaryId] ?? []);
                    $fileLang = $this->fileLanguageId($row);
                    $caches[$lang][$name] = ($fileLang !== null && $fileLang !== $lang)
                        ? [$type, $row, 1]
                        : [$type, $row];
                }
                continue;
            }
            elseif ($type === 'password') {
                // Password hashes do not belong in the content cache: the cache is
                // delivered to templates and backend lists. The comparison at
                // login reads `data_pwd` directly (RecordUser::passwordOk()).
                foreach ($languageIds as $lang) {
                    $caches[$lang][$name] = [$type, ''];
                }
                continue;
            }
            else {
                // `empty` says whether the row is a fallback from the main language
                $hasEmpty = $this->hasEmptyFlag($type);
                $emptyCol = $hasEmpty ? ', `empty`' : '';
                $fallbacks = [];
                $this->db->query("SELECT `language_id`, `data`{$emptyCol} FROM `{$dataTable}` WHERE `record_id` = ? AND `field_id` = ?", 'ii', [$id, $fieldId]);
                while ($row = $this->db->fetch()) {
                    $value = $row['data'];
                    if ($type === 'encrypted') $value = base64_encode((string)$value);
                    $rows[(int)$row['language_id']] = $value;
                    if ($hasEmpty && !empty($row['empty'])) $fallbacks[(int)$row['language_id']] = true;
                }
            }
            $primaryValue = $rows[$primaryId] ?? ($rows ? reset($rows) : ($type === 'file' ? [] : ''));

            foreach ($languageIds as $lang) {
                if ($multilingual && $lang !== $primaryId) {
                    $value = $rows[$lang] ?? $primaryValue;
                    if (!isset($rows[$lang]) || !empty($fallbacks[$lang])) {
                        // fallback from the main language - mark it
                        $caches[$lang][$name] = [$type, $primaryValue, 1];
                        continue;
                    }
                    $caches[$lang][$name] = [$type, $value];
                    continue;
                }
                $caches[$lang][$name] = [$type, $rows[$lang] ?? $primaryValue];
            }
        }

        // store data serialized in `record_cache` - one row per language
        $this->db->query("DELETE FROM `record_cache` WHERE `record_id` = ?", 'i', [$id]);
        foreach ($caches as $lang => $cache) {
            $jsonData = json_encode($cache, JSON_UNESCAPED_UNICODE);
            $this->db->query(
                "INSERT INTO `record_cache` (`record_id`, `language_id`, `cache`) VALUES (?, ?, ?)",
                'iis', [$id, (int)$lang, (string)$jsonData]
            );
        }
        $this->db->popResult();
        // return the non-serialized array of the requested language
        return $caches[$languageId] ?? ($caches[$primaryId] ?? []);
    }

    /**
     * Is the value of a file entry indexed by languages
     * (language_id => metadata, multilingual file field in the working state) or are they
     * the metadata themselves (extension, size, ... - single-language)?
     */
    public function isLanguageKeyedFiles(array $value): bool
    {
        if (empty($value)) return false;
        foreach ($value as $key => $item) {
            if (!is_int($key) && !ctype_digit((string)$key)) return false;
            if (!is_array($item)) return false;
        }
        return true;
    }

    /**
     * Read the content cache of a record in ONE language from `record_cache`.
     *
     * @param int $id          record id
     * @param int $languageId  language
     * @return array|null      decoded cache or null if no row exists
     */
    public function loadRecordCache(int $id, int $languageId): ?array
    {
        $this->db->pushResult();
        $this->db->query(
            "SELECT `cache` FROM `record_cache` WHERE `record_id` = ? AND `language_id` = ?",
            'ii', [$id, $languageId]
        );
        $json = ($this->db->num_rows > 0) ? (string)($this->db->fetchNum()[0] ?? '') : '';
        $this->db->popResult();
        if ($json === '') return null;
        $cache = json_decode($json, true);
        return is_array($cache) ? $cache : null;
    }

    /**
     * Set aside the state of the running result loop - counterpart to
     * Database::pushResult() for what the loop holds outside the database.
     *
     * The result loop (php/loop_start.php) counts with the shared counter
     * $row_no and stores the current row in the template variable $cms. A
     * CMS:SELECT (or CMS:SELECT_LANGVERSION) in the loop body, whose resulttemplate
     * again contains a loop, resets both - without a backup the
     * outer loop would then skip rows or run endlessly, and CMS:TEXT & co. would read the
     * last row of the inner query instead of the outer record. Therefore
     * php/push_result.php calls pushRow(), php/pop_result.php calls popRow().
     *
     * @param mixed $cms  template variable $cms; null where there is none
     *                    (trigger templates, see TriggerTemplates)
     */
    public function pushRow(mixed $cms): void
    {
        $this->rowStack[] = [$this->row_no, $cms];
    }

    /**
     * Retrieve the state saved with pushRow(): restores $row_no and
     * returns the saved value of $cms (php/pop_result.php assigns it).
     *
     * @return mixed  saved $cms; null without a matching pushRow()
     */
    public function popRow(): mixed
    {
        if (!$this->rowStack) return null;
        [$this->row_no, $cms] = array_pop($this->rowStack);
        return $cms;
    }

    /**
     * Load row from database result and prepare values
     *
     * For `record`, the virtual fields come from the content cache of the language
     * (column `cache`, joined by select() from `record_cache`). If the column is missing -
     * direct queries "SELECT * FROM record" - or the row, the cache is reloaded from
     * `record_cache` resp. rebuilt. Working copies (tmp = 1) keep
     * their working state in the same `record_cache` rows (under their own id,
     * see TemporaryTrait) and are read the same way - only rebuilding does not happen there.
     *
     * @param string $tableName
     * @param array &$schema
     * @return ?array     Daten
     */
    public function fetch(string $tableName = 'record', array &$schema = []): ?array
    {
        if (empty($schema)) $schema = &$this->schema;
        $data = $this->db->fetch();
        if ($data === null) return null;

        // contents of virtual fields
        // The query of CMS:SELECT_LANGVERSION returns rows from `language` instead of
        // `record`, but is read by the same result loop (php/loop_start.php).
        // Without record_id there are no virtual fields - and nothing to reload.
        if ($tableName == 'record' && array_key_exists('record_id', $data)) {
            $id = (int)($data['record_id'] ?? 0);
            $areaId = (int)($data['area_id'] ?? 0);
            $cache = null;
            if (!empty($data['cache'])) {
                // load from the joined cache
                $cache = json_decode((string)$data['cache'], true);
                if (!is_array($cache)) $cache = null;
            }
            elseif (!array_key_exists('cache', $data)) {
                // direct query without JOIN -> reload the row of the language
                $languageId = $this->recordLanguageId((int)($data['instance_id'] ?? 0));
                $cache = $this->loadRecordCache($id, $languageId);
            }
            if ($cache === null) {
                // cache empty -> create anew. Not for a working copy (tmp = 1):
                // its working state IS the cache, it has no data_* rows - a
                // new working copy (tmpInit) simply has no content yet
                $cache = empty($data['tmp'])
                    ? $this->createRecordCache($id, $areaId, (int)($data['instance_id'] ?? 0))
                    : [];
            }
            $data = $data + $cache;
        }

        // Post-processing of the data
        foreach ($data as $name => $value) {
            // Fields not listed in Cms::schema must be skipped.
            // Otherwise they are treated as virtual fields and lose their data.
            // (Because the content of virtual fields in the cache is in array format.)
            if ($name == 'cache') continue;
            if ($name == 'origin_id') continue;
            
            $type = $schema[$name]['type'] ?? null;
            $fromCache = false;
            if (empty($type)) {
                // Content from virtual field, format: [type, data] resp. [type, data, 1].
                // Columns that are neither in the schema nor come from the cache
                // (rows of other origin, e.g. CMS:SELECT_LANGVERSION) remain
                // unchanged.
                if (!is_array($value)) {
                    if ($tableName !== 'template') $data[$name] = $this->_sanitizeValue($value);
                    continue;
                }
                $type = $value[0] ?? 'text';
                $data[$name] = $value[1] ?? '';
                $fromCache = true;
            }
            if ($type === 'integer') {
                // convert NULL to 0, e.g. parent_id
                $data[$name] = (int)($data[$name] ?? 0);
            }
            elseif ($type === 'timestampl') {
                // date is in UTC time -> convert it to the PHP time zone
                // (NULL stays NULL, e.g. `field`.`changed_at` of a field never saved)
                if ($data[$name] !== null) $data[$name] = $this->utcToUserTZ($data[$name]);
            }
            elseif ($type === 'json') {
                $data[$name] = json_decode((string)$data[$name], true) ?? [];
            }
            elseif ($type === 'encrypted') {
                // base64-encoded in the cache (see createRecordCache()), binary in the
                // columns of `record` itself
                $data[$name] = $this->crypt->decrypt((string)$data[$name], '', !$fromCache);
            }

            // Read filter (chapter 14 Security: web): no active content from the database,
            // even if it got in past the application. Not for templates
            // (script is content there) and password hashes.
            if ($tableName !== 'template' && $type !== 'password') {
                $data[$name] = $this->_sanitizeValue($data[$name]);
            }
        }

        return $data;
    }

    /**
     * _sanitize_html() on a value of any depth (HtmlSanitizer::cleanValue()).
     */
    public function _sanitizeValue(mixed $value): mixed
    {
        return $this->htmlSanitizer->cleanValue($value);
    }

    /**
     * Convert date from UTC time to PHP time zone
     */
    public function utcToUserTZ(string $dateString): string {
        $date = new \DateTime($dateString, new \DateTimeZone('UTC'));
        $date->setTimezone(new \DateTimeZone($this->userTZ));
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Converts a date + time from the database (ISO format) into a readable format (short form)
     * according to the user's locale.
     * If the time is 00:00:00, omit the time
     * If the seconds are 00, omit the seconds
     * No time zone conversion
     */
    public function _formatDate(string $isoDate, bool $displayTimezone = false): string {
        if (empty(trim($isoDate))) return '';
        // Placeholder (no date / no end) is not output as a date
        if ($isoDate < self::DATE_MIN_DISPLAY || $isoDate >= self::DATE_MAX_DISPLAY) return '';
        try {
            // DateTimeImmutable is best practice to avoid unwanted state changes
            $date = new \DateTimeImmutable($isoDate);
        } catch (\Exception $e) {
            return $isoDate; // Fallback: return unchanged for a faulty ISO string
        }

        $timeString = $date->format('H:i:s');
        $hasNoTime = $timeString === '00:00:00';
        $hasNoSeconds = $date->format('s') === '00';

        $dateType = \IntlDateFormatter::MEDIUM;
        
        if ($hasNoTime) {
            $timeType = \IntlDateFormatter::NONE;
        } elseif ($hasNoSeconds) {
            // SHORT omits the seconds automatically for most locales
            $timeType = \IntlDateFormatter::SHORT;
        } else {
            // MEDIUM includes the seconds
            $timeType = \IntlDateFormatter::MEDIUM;
        }

        // fall back to system default if not otherwise set in the class
        $locale = \Locale::getDefault(); 
        
        // take the time zone from the date to prevent conversions
        $timezone = $date->getTimezone();

        if ($formatter = $this->_intlFormatter($dateType, $timeType, $timezone, $locale)) {
            $formatted = $formatter->format($date);
            if ($formatted !== false) {
                return $formatted . ($displayTimezone ? ' ' . $timezone->getName() : '');
            }
        }

        // Manual fallback if the Intl class is missing or fails
        $fallbackFormat = 'd.m.Y';
        if (!$hasNoTime) {
            $fallbackFormat .= $hasNoSeconds ? ' H:i' : ' H:i:s';
        }
        
        return $date->format($fallbackFormat) . ($displayTimezone ? ' ' . $timezone->getName() : '');
    }

    /**
     * Converts a time from the database (ISO format) into a readable format (short form)
     * according to the user's locale.
     * If the seconds are 00, omit the seconds
     * No time zone conversion
     */
    public function _formatTime(string $isoTime, bool $displayTimezone = false): string {
        if (empty(trim($isoTime))) return '';

        try {
            $time = new \DateTimeImmutable($isoTime);
        } catch (\Exception $e) {
            return $isoTime; // Fallback
        }

        $hasNoSeconds = $time->format('s') === '00';
        
        $dateType = \IntlDateFormatter::NONE;
        $timeType = $hasNoSeconds ? \IntlDateFormatter::SHORT : \IntlDateFormatter::MEDIUM;

        $locale = \Locale::getDefault();
        $timezone = $time->getTimezone();

        if ($formatter = $this->_intlFormatter($dateType, $timeType, $timezone, $locale)) {
            $formatted = $formatter->format($time);
            if ($formatted !== false) {
                return $formatted . ($displayTimezone ? ' ' . $timezone->getName() : '');
            }
        }

        // Manual fallback
        $fallbackFormat = $hasNoSeconds ? 'H:i' : 'H:i:s';
        return $time->format($fallbackFormat) . ($displayTimezone ? ' ' . $timezone->getName() : '');
    }

    /**
     * Build IntlDateFormatter with a PROLEPTIC Gregorian calendar.
     *
     * By default ICU calculates before 15.10.1582 in the Julian calendar - from
     * '1000-01-02' the display would show 28.12.999. For stored ISO data this is
     * wrong: they are stored in the database and throughout the CMS proleptically
     * Gregorian, i.e. by the same rules as today's data. setGregorianChange()
     * moves the change point arbitrarily far into the past and thereby switches
     * the Julian calculation off. For data from 1582 onward nothing changes.
     *
     * @param int           $dateType  IntlDateFormatter constant
     * @param int           $timeType  ditto
     * @param \DateTimeZone $timezone  time zone of the value to be formatted
     * @param string        $locale    default: locale of the user
     * @return ?\IntlDateFormatter     null if the intl extension is missing
     */
    private function _intlFormatter(
        int $dateType,
        int $timeType,
        \DateTimeZone $timezone,
        string $locale = ''
    ): ?\IntlDateFormatter {
        if (!class_exists(\IntlDateFormatter::class)) return null;
        if ($locale === '') $locale = \Locale::getDefault();

        $formatter = new \IntlDateFormatter($locale, $dateType, $timeType, $timezone);

        if (class_exists(\IntlGregorianCalendar::class)) {
            $calendar = new \IntlGregorianCalendar($timezone, $locale);
            // arbitrarily early = never changed = Gregorian throughout
            $calendar->setGregorianChange(-PHP_FLOAT_MAX);
            $formatter->setCalendar($calendar);
        }

        return $formatter;
    }

    /**
     * Converts line breaks
     *
     * @param string $text  text
     * @param string $br      conversion into: "<br>\n"
     * @return string
     */
    public function _nl2br(string $text, string $br = "<br>\n"): string
    {
        # ascii(13) = 015 = \r
        # ascii(10) = 012 = \n
        return (preg_replace("/(\r\n|\r|\n)/", $br, $text));
    }

    public function _strlimit(string $text, int|string $limit): string
    {
        $isSoft = false;
        $cleanLimit = $limit;
        if (is_string($limit) && str_ends_with(strtolower($limit), 's')) {
            $isSoft = true;
            $cleanLimit = (int) substr($limit, 0, -1);
        } else {
            $cleanLimit = (int) $cleanLimit;
        }
        if (mb_strlen($text) <= $cleanLimit) {
            return $text;
        }
        $ellipsis = $this->snippet('ellipsis');
        $truncated = mb_substr($text, 0, $cleanLimit);
        if ($isSoft) {
            if (preg_match('/\s\S*$/u', $truncated, $matches, PREG_OFFSET_CAPTURE)) {
                $lastSpaceOffset = $matches[0][1];
                $truncated = mb_substr($truncated, 0, $lastSpaceOffset);
            }
        }
        return $truncated . $ellipsis;
    }

    /**
     * Removes active content from HTML, keeps formatting - see
     * HtmlSanitizer::clean() (script, iframe, object, embed, base, meta …,
     * event handlers, srcdoc, javascript:/vbscript:/data: URLs, whitelist
     * system.html_allowed_tags).
     *
     * When WRITING: Dataset::validateValue() for every text value (exception: module
     * Template, see Dataset::$sanitizeHtml), Snippets::save().
     * When READING: Cms::fetch() for every row (except `template`) - against content that
     * got into the database past the application (chapter 14 Security: web).
     *
     * @param string $text  text with HTML
     */
    public function _sanitize_html(string $text): string
    {
        return $this->htmlSanitizer->clean($text);
    }

    /**
     * Replaces HTML tags with spaces, no trim
     *
     * @param string $text  text
     */
    public function _strip_tags(string $text): string
    {
        if ($text === '') return '';
        $cleaned = preg_replace('/<[^>]+>/', ' ', $text);
        $cleaned = preg_replace('/ +/', ' ', $cleaned);
        return $cleaned;
    }

    /**
     * Formats a floating-point value into the user format
     *
     * @param float $floatValue    input value
     * @param int $fractionDigits  number of decimal places
     */
    public function _formatFloat($floatValue, $fractionDigits = 0): string
    {
        $fmt = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::DECIMAL);
        if ($fractionDigits > 0) {
            $fmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $fractionDigits);
            $fmt->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $fractionDigits);
        }
        return $fmt->format($floatValue);
    }

    /**
     * Decode all &...; character references
     */
    public function _decodeSpecialchars(string $input): string {
        $decoded = html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $decoded;
    }


    // ====================
    // CMS-Commands
    //
    // Runtime of the (CMS:...) commands from the frontend templates. They are called
    // exclusively from the compiled template code, always with exactly one
    // parameter: the array $_a with the values given in the command.
    //
    // THREE CONVENTIONS APPLY TO ALL
    // --------------------
    //   1. The source is in $GLOBALS['cms'] - the record that Page::resolveRequest()
    //      loaded, or the row the running result loop is currently delivering.
    //   2. Output is via echo, unless the parameter 'set' is set; then
    //      the result goes into $GLOBALS['set'][...] and the page stays silent.
    //      _out() does both.
    //   3. ifempty / instead / prefix / suffix act the same in almost all output
    //      commands - _decorate() takes care of this:
    //          empty          -> 'ifempty' replaces everything else
    //          not empty      -> 'instead' replaces the value, then prefix + value + suffix
    //      'replace' (replace partial texts, _replace()) is supported by CMS:TEXT, CMS:DATE,
    //      CMS:DATETIME, CMS:TIME and CMS:NUMBER - for the last four on the
    //      fully formatted value, before _decorate().
    //
    // Commands with a php_suffix 'include'/'include_and_stop' instead return
    // a TEMPLATE NAME (string); the generated code then includes it.
    // @see Camindo\Core\Modules\Template\commands.php


    // CMS:CONTINUE
    // After a command with its own success template (CMS:USER_LOGIN & co.) the
    // snippet include_and_stop ends the page. This flag prevents that exactly once -
    // the snippet resets it itself.
    public function cmsContinue($p): void
    {
        $this->returnAfterSuccess = true;
    }

    // CMS:DATE - date part only
    public function cmsDate($p): void
    {
        $this->_out($this->_decorate($this->_replace($this->_dateValue($p, 'date'), $p), $p), $p);
    }

    // CMS:DATETIME - date and time
    public function cmsDatetime($p): void
    {
        $this->_out($this->_decorate($this->_replace($this->_dateValue($p, 'datetime'), $p), $p), $p);
    }

    // CMS:TIME - time only
    public function cmsTime($p): void
    {
        $this->_out($this->_decorate($this->_replace($this->_dateValue($p, 'time'), $p), $p), $p);
    }

    // CMS:FED - edit button into the backend
    //
    // Only with frontend editing switched on (fedActive(): cookie 'fed' = 1 or 2,
    // set via the personal settings of the admin). Without 'id' the
    // running record is meant, otherwise the rule of _record() applies (id or slug).
    //
    // NOT A LINK, BUT A JAVASCRIPT CALL
    // --------------------
    // Output is <a href="#" data-c9-fed="{id}" class="c9-fed"> (with info=true
    // additionally data-c9-fed-view="info"). The click is caught by CMS:FED_SCRIPT
    // (cmsFedScript()) and calls cFED() - it opens the backend in a new
    // tab and drives the input mask as a layer. No onclick attribute: this
    // was blocked by a script policy of the frontend (chapter 14 Security: web).
    //
    // Why no href: the target address (#Record/record_edit.php?id=…) was thereby in
    // every delivered page. The backend was thereby bound to this hash routing
    // - a deep link from the frontend that cannot be changed without
    // breaking foreign templates. Now the target is in exactly ONE place
    // (cmsFedScript()), and opening the mask can be reworked without a
    // template noticing anything (chapter 14 Security: web).
    //
    // Without CMS:FED_SCRIPT on the page nobody catches the click - it stays
    // ineffective. Anyway, only the logged-in admin sees the button.
    public function cmsFed($p): void
    {
        if (!$this->fedActive()) return;
        $data = $this->_record($p);
        $id = (int)($data['record_id'] ?? 0);
        if ($id < 1) return;

        $label = (string)($p['label'] ?? '');
        if ($label === '') $label = '<span style="color:#f00">[&raquo;]</span>';

        // 'info': detail view instead of input mask - second argument of cFED()
        $view = $this->_bool($p['info'] ?? false) ? ' data-c9-fed-view="info"' : '';
        $html = '<a href="#" data-c9-fed="' . $id . '"' . $view . ' class="c9-fed">'
              . $label . '</a>';
        $this->_out((string)($p['prefix'] ?? '') . $html . (string)($p['suffix'] ?? ''), $p);
    }

    // CMS:FED_SCRIPT - the function cFED() for the buttons from CMS:FED
    //
    // Belongs once per page in the template, usually in the footer include before
    // </body>; the order relative to the CMS:FED buttons does not matter, called
    // only on click. Like CMS:FED, it outputs only with frontend editing switched on
    // and at most once per request, even if the
    // command appears several times in the template (across several CMS:INCLUDE).
    //
    // cFED(id[, 'info']) opens {paths.backend}/index.php in a new tab and
    // passes the backend page along as hash. If the admin is not logged in there, the
    // backend places the login dialog over the layer. The timestamp in the query string
    // prevents a copy from the browser cache. Without a fixed backend domain the
    // backend lies under paths.backend of the same domain, otherwise under
    // admin.backend_domain (BackendDomain::url()).
    public function cmsFedScript($p): void
    {
        if (!$this->fedActive()) return;
        if ($this->fedScriptOut) return;
        $this->fedScriptOut = true;

        // With a fixed backend domain absolute (BackendDomain), otherwise relative as before
        $base = json_encode((new BackendDomain($this->config))->url('/index.php?'), JSON_UNESCAPED_SLASHES);
        $html = '<script nonce="' . $this->cspNonce() . '">' . "\n"
              . 'window.cFED = window.cFED || function (id, view) {' . "\n"
              . '    var script = (view === "info") ? "Record/record_info.php" : "Record/record_edit.php";' . "\n"
              . '    window.open(' . $base . ' + Date.now() + "#" + script' . "\n"
              . '        + "?id=" + encodeURIComponent(id), "_blank");' . "\n"
              . '};' . "\n"
              . 'document.addEventListener("click", function (ev) {' . "\n"
              . '    var a = ev.target.closest ? ev.target.closest("[data-c9-fed]") : null;' . "\n"
              . '    if (!a) return;' . "\n"
              . '    ev.preventDefault();' . "\n"
              . '    window.cFED(a.getAttribute("data-c9-fed"), a.getAttribute("data-c9-fed-view") || "");' . "\n"
              . '});' . "\n"
              . '</script>' . "\n";
        $this->_out($html, $p);
    }

    // ====================
    // Script policy of the frontend (chapter 14 Security: web, SecurityHeaders)
    // ====================

    /**
     * Nonce of the running page - for CMS:NONCE and core output with script
     * (CMS:FED_SCRIPT).
     */
    public function cspNonce(): string
    {
        return $this->securityHeaders->nonce();
    }

    // CMS:NONCE - the nonce of the page for each script in the template:
    // <script(CMS:NONCE)>. format=attribute (default) outputs ' nonce="…"',
    // format=value only the value (own policy via CMS:HEADER, echo in PHP).
    public function cmsNonce($p): void
    {
        $nonce = $this->cspNonce();
        $html = strtolower((string)($p['format'] ?? '')) === 'value' ? $nonce : ' nonce="' . $nonce . '"';
        $this->_out($html, $p);
    }

    // CMS:CSP - change the script policy of this page as long as nothing
    // has been output yet: script="https://js.example.com …" (further sources),
    // form="https://pay.example.com" (form targets, enables form-action),
    // mode=off|report|enforce. Several calls collect sources; the policy is
    // sent anew each time.
    public function cmsCsp($p): void
    {
        if (headers_sent()) {
            $this->error('CMS:CSP: headers already sent.');
            return;
        }
        $mode = strtolower(trim((string)($p['mode'] ?? '')));
        $script = SecurityHeaders::cleanSources((string)($p['script'] ?? ''));
        $form = SecurityHeaders::cleanSources((string)($p['form'] ?? ''));
        if ($mode !== '' && !in_array($mode, SecurityHeaders::FRONTEND_MODES, true)) {
            $this->error('CMS:CSP: unknown mode "' . $mode . '".');
            $mode = '';
        }
        $this->securityHeaders->changeFrontendCsp($mode, $script, $form);
    }

    // CMS:FED_CODE - HTML only with frontend editing switched on, otherwise 'instead'
    public function cmsFedCode($p): void
    {
        $html = $this->fedActive() ? (string)($p['code'] ?? '') : (string)($p['instead'] ?? '');
        $this->_out($html, $p);
    }

    // CMS:HEADER
    public function cmsHeader($p): void
    {
        $text = (string)($p['text'] ?? '');
        if ($text === '') return;
        if (headers_sent()) {
            $this->error('CMS:HEADER: headers already sent.');
            return;
        }
        // "Name:" without value removes the header - e.g. a preset from
        // SecurityHeaders (X-Frame-Options, Content-Security-Policy …)
        if (preg_match('/^([A-Za-z0-9-]+):\s*$/', $text, $m)) {
            header_remove($m[1]);
            return;
        }
        // 'overwrite' - false appends another header of the same type
        header($text, $this->_bool($p['overwrite'] ?? true));
    }

    // CMS:INCLUDE - returns template file ("{area_id}/{name}", see templateFile())
    // The snippet php/include_cached.php includes the returned template and
    // ruft danach includeEnd().
    //
    // 'caching' / 'cacheparams' - output cache (IncludeCache): on a hit
    // cmsInclude() itself outputs the stored output and returns '' (nothing
    // to include); otherwise IncludeCache records the output of the template, and
    // includeEnd() stores it - only if no command reported an error in the process.
    // Without cache, preview, frontend editing (buttons only for the admin) and
    // admin.include_cache = false stay in effect. An invalid value reports an error, the
    // template then runs uncached.
    public function cmsInclude($p): string
    {
        if ($this->includeDepth > (int)$this->config->get('admin.max_include_depth')) {
            $this->_cmsError('CMS:INCLUDE error: maximum inclusion depth exceeded.');
        }
        $file = $this->templateFile((string)($p['template'] ?? ''));

        $recording = null;
        if ($file !== '' && isset($p['caching']) && !$this->includeCacheOff
            && $this->includeCache->enabled() && !$this->fedActive()) {
            try {
                $ttl = IncludeCache::ttl($p['caching']);
                if ($ttl !== null) {
                    $key = IncludeCache::key(
                        (int)($this->instance['instance_id'] ?? 0),
                        (int)$this->languageId,
                        IncludeCache::paramString($p['cacheparams'] ?? null)
                    );
                    $source = $this->templatePath . "/$file.php";
                    $cached = $this->includeCache->start($file, $key, $ttl, $source, $this->cspNonce());
                    if ($cached !== null) {
                        echo $cached;
                        $file = '';
                    }
                    else {
                        $recording = $this->errorCount;
                    }
                }
            }
            catch (\InvalidArgumentException $e) {
                $this->error('CMS:INCLUDE: ' . $e->getMessage());
            }
        }
        $this->includeCacheStack[] = $recording;
        return $file;
    }

    /**
     * End of a CMS:INCLUDE (php/include_cached.php): finish a running recording
     * and pass on the output.
     */
    public function includeEnd(): void
    {
        $errorCount = array_pop($this->includeCacheStack);
        if ($errorCount === null) return;
        echo $this->includeCache->finish($this->errorCount === $errorCount, $this->cspNonce());
    }

    // CMS:MEDIA
    //
    // Source is an upload field: the record holds the row from data_fil for this
    // (filename, mime_type, extension, size, width, height). The file itself lies
    // under {paths.media}/{instance_id}/{field_id}/{record_id}.{extension}
    // (mediaPath()).
    //
    // Without 'subfield' an IMG tag is created, with 'subfield' the respective single value.
    //
    // 'auth' / 'download' / user_auth: instead of pointing directly into the media directory, the
    // link points to /file.php (Camindo\Core\MediaDelivery) if
    //   - the field is marked in the backend as "only for logged-in users"
    //     (field.details.user_auth - its directory is locked via .htaccess),
    //   - auth=true is given (forces file.php, e.g. for previews), or
    //   - download="name" is set (Content-Disposition with a desired name).
    // For multilingual fields the link carries the language of the file.
    public function cmsMedia($p): void
    {
        $file = $this->_source($p);
        if (!is_array($file)) $file = [];

        // Field id for the path - it is not in the record cache, but
        // in the field definition of the content area. A file array passed via 'useval'
        // may bring both itself.
        // Record: with 'id' the one given there, otherwise the running one (see _record()).
        $record   = $this->_record($p);
        $recordId = (int)($file['record_id'] ?? $record['record_id'] ?? 0);
        $areaId   = (int)($file['area_id']   ?? $record['area_id']   ?? 0);
        $fieldId  = (int)($file['field_id']  ?? 0);
        $fieldName = (string)($p['field'] ?? '');
        if ($fieldId < 1 && $fieldName !== '' && $areaId > 0) {
            $this->loadSimpleSchema($areaId);
            foreach ($this->virtualSchemaCache[$areaId] as $f) {
                if ($f['name'] === $fieldName) {
                    $fieldId = (int)$f['field_id'];
                    break;
                }
            }
        }

        // Two paths to the file:
        //   path  server-relative from DOCUMENT_ROOT, for href/src. The original
        //         file name is separated by a comma in the call -
        //         /media/{instance}/{field}/{record}[_{lang}],{name}.{ext} -
        //         so that browsers and search engines see a meaningful name. The
        //         RewriteRule "CAMINDO MEDIA" in public/.htaccess drops the part
        //         ",{name}" without checking it (see backend/html/htaccess.html).
        //         When delivered via file.php (auth, download, user_auth) this is omitted -
        //         there the name is in the Content-Disposition header.
        //   file  absolute path in the file system, for file operations in PHP.
        $extension = (string)($file['extension'] ?? '');
        $path = '';
        $filePath = '';
        if ($fieldId > 0 && $recordId > 0 && $extension !== '') {
            $fileLanguageId = $this->fileLanguageId($file);
            $instanceId = (int)($file['instance_id'] ?? $record['instance_id'] ?? 0);
            // For multilingual file fields the file of the language (resp. the
            // main language as fallback) - see mediaPath()
            $mediaPath = $this->mediaPath($fieldId, $recordId, $fileLanguageId, $extension, $instanceId);
            $filePath = $this->config->get('paths.media_dir') . '/' . $mediaPath;

            $download = trim((string)($p['download'] ?? ''));
            $viaScript = $download !== ''
                || $this->_bool($p['auth'] ?? false)
                || $this->isUserAuthField($fieldId);
            if ($viaScript) {
                // Delivery via file.php (see comment above)
                $path = "/file.php?file={$fieldId}/{$recordId}"
                     . ($fileLanguageId === null ? '' : '/' . $fileLanguageId)
                     . '&v=' . (int)($file['version'] ?? 0);
                if ($download !== '') {
                    $path .= '&download=' . rawurlencode(preg_replace('~[\\\\/:*?"<>|]~', '', $download));
                }
            }
            else {
                $path = $this->config->get('paths.media') . '/'
                      . $this->mediaUriName($mediaPath, (string)($file['filename'] ?? ''), $extension);
                // version number against browser caching (as in the backend)
                $version = (int)($file['version'] ?? 0);
                if ($version > 0) $path .= '?v=' . $version;
            }
        }

        // 'subfield' - single value instead of IMG tag
        $subfield = (string)($p['subfield'] ?? '');
        if ($subfield !== '') {
            $value = match ($subfield) {
                'path', 'url' => $path,   // 'url': spelling from C9 pre-releases
                'file'        => $filePath,
                default       => (string)($file[$subfield] ?? ''),
            };
            // 'autoformat' - make the file size readable (default: on)
            if ($subfield === 'size' && $this->_bool($p['autoformat'] ?? true)) {
                $value = $this->_formatBytes((int)$value);
            }
            $this->_out($this->_decorate($value, $p, true), $p);
            return;
        }

        // IMG-Tag
        $html = '';
        if ($path !== '') {
            $attr = [
                'src' => $path,
                'alt' => (string)($file['filename'] ?? ''),
            ];
            // 'width' / 'height' - without specification the dimensions from data_fil
            $width  = (string)($p['width']  ?? ($file['width']  ?? ''));
            $height = (string)($p['height'] ?? ($file['height'] ?? ''));
            if ($width  !== '' && $width  !== '0') $attr['width']  = $width;
            if ($height !== '' && $height !== '0') $attr['height'] = $height;
            // 'attr' - additional attributes, override the above
            foreach ((array)($p['attr'] ?? []) as $name => $value) {
                $attr[preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$name)] = (string)$value;
            }

            $html = '<img';
            foreach ($attr as $name => $value) {
                $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
            }
            $html .= '>';
        }

        $this->_out($this->_decorate($html, $p, true), $p);
    }

    // CMS:MESSAGE
    // Outputs the messages that the commands of this page stored with error() -
    // typically the field errors of a CMS:USER_INPUT form.
    public function cmsMessage($p): void
    {
        $value = (string)($p['useval'] ?? '');
        if ($value === '' && $this->hasErrors) {
            $value = $this->errorMsg();
        }
        $this->_out($this->_decorate($value, $p), $p);
    }

    // Area of the running overview page (prefix address without record,
    // Page::resolveRequest()) - 0 = none
    public int $overviewAreaId = 0;

    /** Area by name or id (in the instances of this site); 0 = unknown. */
    private function areaIdByRef(string $ref): int
    {
        $instanceIds = array_map('intval', $this->accessInstanceIds ?: (array)($this->instanceCache->all()['instance_ids'] ?? []));
        foreach ($this->instanceCache->areasOfType([], $instanceIds) as $areaId => $info) {
            if ((string)$areaId === $ref || $info['name'] === $ref) return (int)$areaId;
        }
        return 0;
    }

    // Stack of set names for commands with 'buffer_set' (commands.php) - one stack,
    // because included templates overwrite $_a and commands with set may be nested
    // at all
    private array $bufferSetStack = [];

    // Totals of CMS:SELECT hits=false up to select_count (see selectCountEnd())
    private array $selectCountStack = [];

    /**
     * Start of a command with 'buffer_set' (CMS:SELECT, CMS:INCLUDE …): with set=…
     * the output is intercepted until setBufferEnd(). If the page ends earlier (exit after
     * a success template, exception), PHP outputs the open buffer at the end completely
     * normally - error messages are not lost.
     */
    public function setBufferStart(string $name): void
    {
        $this->bufferSetStack[] = $name;
        if ($name !== '') ob_start();
    }

    /** End of a command with 'buffer_set': intercepted output to $set[name]. */
    public function setBufferEnd(): void
    {
        $name = (string)array_pop($this->bufferSetStack);
        if ($name !== '') $GLOBALS['set'][$name] = (string)ob_get_clean();
    }

    // CMS:SNIPPET
    //
    // Snippet in the language of the call - same resolution as snippet(): with
    // prefix (custom.key = snippet set of the instance), without prefix a core snippet.
    // param[name]=value fills ICU parameters and replaces {name} in simple texts
    // (snippet() itself only interpolates ICU messages). If the snippet is missing,
    // snippet() returns the key - it stays visible unless 'ifempty' is given.
    public function cmsSnippet(array $p): void
    {
        $key = trim((string)($p['key'] ?? ''));
        $params = array_map('strval', array_filter((array)($p['param'] ?? []), 'is_scalar'));
        $text = $key === '' ? '' : $this->snippet($key, $params);
        if ($text === $key && isset($p['ifempty'])) $text = '';
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }
        if ($this->_bool($p['specialchars'] ?? false)) {
            $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
        $this->_out($this->_decorate($text, $p), $p);
    }

    // CMS:NUMBER
    public function cmsNumber($p): void
    {
        $raw = $this->_source($p);
        $isEmpty = (trim((string)$raw) === '');

        // 'instead' applies before the calculation: it replaces the source, not the result
        if (!$isEmpty && isset($p['instead']) && (string)$p['instead'] !== '') {
            $raw = $p['instead'];
        }

        $number = (float)str_replace(',', '.', (string)$raw);
        // 'mul' / 'add'
        $number = $number * (float)($p['mul'] ?? 1) + (float)($p['add'] ?? 0);

        // 'sizefactor' - readable file size (1000 or 1024)
        $sizefactor = (int)($p['sizefactor'] ?? 0);
        if ($sizefactor > 0) {
            $value = $this->_formatBytes($number, $sizefactor);
        }
        else {
            // 'format' - sprintf pattern. It alone determines the output; 'decimal',
            // 'thousands_separator', 'decimals' and 'nullcent' are then ignored.
            $format = (string)($p['format'] ?? '');
            if ($format !== '') {
                $value = sprintf($format, $number);
            }
            else {
                // Deliberately WITHOUT locale formatting: the command specifies the separators
                // itself ('decimal' default '.', 'thousands_separator' default
                // none). A locale would override them and, when splitting
                // below, be misread as decimal point.
                // 'decimals' - fixed number of decimal places (rounded); without specification as many
                // as the number has
                $digits = (isset($p['decimals']) && (string)$p['decimals'] !== '')
                    ? max(0, min(10, (int)$p['decimals']))
                    : $this->_fractionDigits($number);
                $value = number_format($number, $digits, '.', '');

                $decimal = (string)($p['decimal'] ?? '.');
                $thousands = (string)($p['thousands_separator'] ?? '');
                [$intPart, $fracPart] = array_pad(explode('.', $value, 2), 2, null);
                if ($thousands !== '') {
                    // every three digits from the right, not counting the sign
                    $intPart = preg_replace('/\B(?=(\d{3})+(?!\d))/', $thousands, $intPart);
                }
                // 'nullcent' - replacement for a missing decimal value
                if ($fracPart === null && isset($p['nullcent']) && (string)$p['nullcent'] !== '') {
                    $fracPart = (string)$p['nullcent'];
                }
                $value = ($fracPart === null) ? $intPart : $intPart . $decimal . $fracPart;
            }
        }

        // 'replace' acts on the fully formatted value (e.g. ".00?")
        $value = $this->_replace((string)$value, $p);

        // 'ifempty' also applies here to the value 0 - checked on the numeric value, not on the
        // formatted text ("0.00" with format/decimals is also 0) - 'instead'
        // has already been consumed above
        unset($p['instead']);
        $this->_out($this->_decorate((float)$number == 0.0 ? '' : $value, $p), $p);
    }

    // CMS:RAW
    // Without any processing - the content must be trustworthy (HTML fields).
    // Only the output parameters (ifempty, instead, prefix, suffix, set) apply as everywhere.
    public function cmsRaw($p): void
    {
        $this->_out($this->_decorate((string)$this->_source($p), $p), $p);
    }

    // CMS:RELATION
    //
    // Outputs the default field (data_dft) of all records to which the given
    // reference field of the current record points - in the order set in the backend
    // (data_rel.position). With 'id' the references of another
    // record (record_id or slug, rule as in the URL - see _record(),
    // including publication). Targets only published as with CMS:SELECT
    // (publishedSql(), relaxable with future/archive/state) - also in the preview.
    public function cmsRelation($p): void
    {
        $values = [];
        // without 'id' the running record, otherwise id/slug (noted for the request)
        $srcId = (int)($this->_record($p)['record_id'] ?? 0);
        $field = (string)($p['field'] ?? '');
        $relField = ($field !== '') ? $this->resolveVirtualField($field) : null;

        if ($relField === null || !in_array($relField['type'], $this->relationTypes, true)) {
            if ($field !== '') $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$field]), $field);
        }
        elseif ($srcId > 0) {
            // (without source - no running record, unknown reference - the
            //  output simply remains empty)

            $limit = (int)($p['limit'] ?? 1000);
            if ($limit < 1) $limit = 1000;
            $published = $this->publishedSql($p, 't');

            // The command regularly stands INSIDE a result loop -
            // the running result must therefore be set aside.
            // data_dft has one row per language - that of the content language of the
            // target record (languageExpr(), as in select()).
            $this->db->pushResult();
            $sql = "SELECT `d`.`data` FROM `data_rel` `r`"
                 . " INNER JOIN `record` `t` ON `t`.`record_id` = `r`.`dest_id`"
                 . " AND `t`.`tmp`=0 AND `t`.`deleted`=0"
                 . ($published !== '' ? " AND " . $published : '')
                 . " LEFT JOIN `data_dft` `d` ON `d`.`record_id` = `r`.`dest_id`"
                 . " AND `d`.`language_id` = " . $this->languageExpr('t')
                 . " WHERE `r`.`src_id` = ? AND `r`.`fieldname_id` = ?"
                 . $this->liveFieldSql('r', (int)$relField['fieldname_id'])
                 . " ORDER BY `r`.`position` ASC LIMIT " . $limit;
            $this->db->query($sql, 'ii', [$srcId, $relField['fieldname_id']]);
            foreach ($this->db->fetchColumn() as $value) {
                $value = (string)$value;
                if ($value !== '') $values[] = $value;
            }
            $this->db->popResult();
        }

        // 'separator'
        $result = implode((string)($p['separator'] ?? '|'), $values);
        $this->_out($this->_decorate($result, $p), $p);
    }

    // CMS:RESULT_START / CMS:RESULT_END
    // 'no_action' => true: the compiler generates no call at all for these two.
    // Their effect lies entirely in php/loop_start.php and php/loop_end.php.
    // The methods remain only as a starting point, in case the brackets later
    // ever need runtime work.
    public function cmsResultStart($p): void
    {
    }

    public function cmsResultEnd($p): void
    {
    }

    // CMS:SAVEDATA
    //
    // Stores a complete record under $GLOBALS[{phpvar}], so that the surrounding
    // PHP can work with it. Without 'id' this is simply the current record.
    // Returns the variable name: the php_suffix savedata_local binds it as a
    // local variable as well - the template runs inside a closure, a plain
    // $GLOBALS entry would not be visible there as ${phpvar}.
    public function cmsSaveData($p): string
    {
        $name = $this->_name((string)($p['phpvar'] ?? ''));
        if ($name === '') {
            $this->error('CMS:SAVEDATA: parameter "phpvar" required.');
            return '';
        }

        // 'id' - record_id or slug; without specification the running record (_record())
        $GLOBALS[$name] = $this->_record($p);
        return $name;
    }

    /**
     * 'request' of CMS:SELECT - stricter than select() for the backend lists:
     * no 'all', and filter / filter_op / filter_mode only with a field name
     * (filter[title]); a whole 'filter' would let visitors search every field of the
     * found records, also those the page does not show. skip, limit, sort, order
     * as a whole. Comma list or array (request[]=sort request[]=filter[title]);
     * anything else is registered as error and ignored.
     *
     * @param mixed $request  value of the template parameter
     * @return string         comma list for select()
     */
    private function templateRequestKeys(mixed $request): string
    {
        $items = [];
        foreach ((array)$request as $part) {
            if (!is_scalar($part)) continue;
            array_push($items, ...explode(',', (string)$part));
        }
        $keys = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') continue;
            $ok = preg_match('/^([a-z_]+)(?:\[([a-z0-9_.]+)\])?$/', $item, $m) === 1;
            if ($ok) {
                $main = str_replace('_', '', $m[1]);
                $sub  = $m[2] ?? '';
                $ok = in_array($main, ['filter', 'filterop', 'filtermode'], true)
                    ? $sub !== ''
                    : (in_array($main, ['skip', 'limit', 'sort', 'order'], true) && $sub === '');
            }
            if (!$ok) {
                $this->error($this->snippet('error_param_invalid'), 'request: ' . $item);
                continue;
            }
            $keys[] = $item;
        }
        return implode(',', $keys);
    }

    // CMS:SELECT - returns template file (see templateFile())
    //
    // A template sees only PUBLISHED records (publishedSql()): re-
    // leased, publication date reached, end not reached. The parameters
    // future, archive and state relax this per query - also in the preview of the
    // backend (Page in backend mode) this remains so; there only the record
    // called via the URL may itself be unpublished.
    public function cmsSelect($p): string
    {
        if ($this->includeDepth > (int)$this->config->get('admin.max_include_depth')) {
            $this->_cmsError('CMS:SELECT error: maximum inclusion depth exceeded.');
        }
        if (!$this->_oneOf(['filter','relation'],$p)) {
            $this->_cmsError('CMS:SELECT error: parameter "filter" or "relation" required.');
        }

        // Bring the parameter names of the command to those of select(): the template
        // writes filter_op / filter_mode / hits (names from commands.php), select()
        // knows filterop / filtermode / result. What select() does not know (lang),
        // runs along without effect.
        foreach (['filter_op' => 'filterop', 'filter_mode' => 'filtermode', 'hits' => 'result'] as $from => $to) {
            if (isset($p[$from]) && !isset($p[$to])) $p[$to] = $p[$from];
        }

        // 'request' of a template: only named keys, see templateRequestKeys()
        if (isset($p['request'])) $p['request'] = $this->templateRequestKeys($p['request']);

        // 'relation' without 'src_id': the references of the running record
        // (default per commands.php). Without a source the relation filter in
        // select() would silently drop out and the query would find ALL records.
        // With dest_id the reverse direction is meant (who refers to dest_id?) - then
        // do not insert a source.
        if (trim((string)($p['relation'] ?? '')) !== '' && (int)($p['src_id'] ?? 0) < 1
            && (int)($p['dest_id'] ?? 0) < 1) {
            $current = $GLOBALS['cms'] ?? null;
            $p['src_id'] = is_array($current) ? (int)($current['record_id'] ?? 0) : 0;
            // No running record (trigger template, scheduler ...): no
            // hits instead of all records
            if ($p['src_id'] < 1) $p['sql'] = array_merge((array)($p['sql'] ?? []), ['0 = 1']);
        }

        // Publication condition - after any existing 'sql' of the
        // template (select() accepts a list of conditions)
        $published = $this->publishedSql($p);
        if ($published !== '') {
            $p['sql'] = array_merge((array)($p['sql'] ?? []), [$published]);
        }
        // hits=false: only count - without count=true there would be nothing to count
        $countOnly = ((string)($p['result'] ?? '') === 'false');
        if ($countOnly) $p['count'] = 'true';

        $this->select($p);

        // Remember the total for select_count.php (null = take nothing over): without
        // hit rows, hits=false includes no resulttemplate, and popResult()
        // then restores the numbers of the outer result - the count
        // would otherwise be visible nowhere. A stack, because a noresulttemplate itself
        // may again contain CMS:SELECT.
        $this->selectCountStack[] = $countOnly ? (int)$this->db->num_rows_unlimited : null;
        if ($countOnly && $this->db->num_rows_unlimited > 0) {
            // counted and found: no output, also no noresult
            return '';
        }

        if ($this->db->num_rows > 0) {
            // 'resulttemplate'
            return $this->templateFile((string)($p['resulttemplate'] ?? ''));
        }
        if (!empty($p['noresult'])) {
            // 'noresult'
            echo (string)$p['noresult'];
            return '';
        }
        // 'noresulttemplate'
        return $this->templateFile((string)($p['noresulttemplate'] ?? ''));
    }

    // Counterpart to cmsSelect() (PHP snippet select_count after pop_result): with
    // hits=false take the total of the count into the calling template. It
    // replaces there num_rows_unlimited of the surrounding result - num_rows and the
    // running result loop remain untouched.
    public function selectCountEnd(): void
    {
        $count = array_pop($this->selectCountStack);
        if ($count !== null) $this->db->num_rows_unlimited = $count;
    }

    // CMS:SELECT_LANGVERSION - returns template file (see templateFile())
    //
    // Returns the languages of the running site (own instance + readable instances,
    // Cms::$languages) as a result set, so that a template can build a
    // language switcher from it - main language first, then the others in the
    // order set in the backend. Structure like CMS:SELECT: the result remains in the
    // database result and is traversed in the 'resulttemplate' with CMS:RESULT_START
    // The rows are not records - fetch() recognizes this by the
    // missing record_id and skips the virtual fields.
    public function cmsSelectLanguages(array $p): string
    {
        // The languages come from the file cache (Page -> setLanguage()), not from
        // the database; Database::setArrayResult() provides them to the result loop
        // like a query result. Each row additionally carries 'current'
        // (1 = language of the running call).
        $rows = [];
        foreach ($this->languages as $languageId => $language) {
            $rows[] = [
                'language_id' => (int)$languageId,
                'code'        => (string)($language['code'] ?? ''),
                'label'       => (string)($language['label'] ?? ''),
                'endonym'     => (string)($language['endonym'] ?? ''),
                'current'     => ((int)$languageId === (int)$this->languageId) ? 1 : 0,
            ];
        }
        if (empty($rows)) {
            // Without language context (call outside the frontend): all languages
            $this->db->query("SELECT *, 0 AS `current` FROM `language` ORDER BY `position` ASC");
        }
        else {
            $this->db->setArrayResult($rows);
        }
        if ($this->db->num_rows < 1) return '';
        return $this->templateFile((string)($p['resulttemplate'] ?? ''));
    }

    // CMS:TEXT
    //
    // The workhorse command: fetch source, rewrite, format, output.
    // The order of steps is deliberately fixed and is shown below in the code.
    public function cmsText(array $p): void
    {
        $value = (string)$this->_source($p);

        // 'if' / 'else' - value table. Two notations:
        //     if[old]="new"       one entry per comparison value
        //     if="old?new"        short form with question mark as separator
        if (isset($p['if'])) {
            $matched = false;
            foreach ($this->_pairs($p['if']) as $search => $replace) {
                if ($value === (string)$search) {
                    $value = (string)$replace;
                    $matched = true;
                    break;
                }
            }
            // 'else' - none of the IF queries matched
            if (!$matched && isset($p['else'])) {
                $value = (string)$p['else'];
            }
        }

        // 'replace' - replace partial texts, same two notations as 'if'
        $value = $this->_replace($value, $p);

        // 'trim' / 'striptags' / 'limit'
        if ($this->_bool($p['trim'] ?? false))      $value = trim($value);
        if ($this->_bool($p['striptags'] ?? false)) $value = trim($this->_strip_tags($value));
        if (isset($p['limit']) && (string)$p['limit'] !== '') {
            $value = $this->_strlimit($value, $p['limit']);
        }

        // 'markdown' - the text is Markdown (extensions Markdown, MarkdownVisual) and
        // is turned into HTML (Camindo\Core\Markdown, HTML in the text masked). The other
        // conversions (entities, line breaks, linking …) are omitted.
        if ($this->_bool($p['markdown'] ?? false)) {
            $this->_out($this->_decorate((new Markdown())->toHtml($value), $p), $p);
            return;
        }

        // 'urlencode' - the text becomes part of a URL. Everything else (HTML conversion,
        // linking) would be pointless there and would destroy the encoding again.
        if ($this->_bool($p['urlencode'] ?? false)) {
            $this->_out($this->_decorate(rawurlencode($value), $p), $p);
            return;
        }

        // 'entitydecode' (default on) - &...; back to real characters
        if ($this->_bool($p['entitydecode'] ?? true)) {
            $value = $this->_decodeSpecialchars($value);
        }
        // 'specialchars' - and back again, this time fully masked
        if ($this->_bool($p['specialchars'] ?? false)) {
            $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
        // 'ampconvert' (default on) - mask single &, leave existing entities alone
        elseif ($this->_bool($p['ampconvert'] ?? true)) {
            $value = preg_replace('/&(?![a-zA-Z][a-zA-Z0-9]{0,30};|#[0-9]{1,7};|#x[0-9a-fA-F]{1,6};)/', '&amp;', $value);
        }

        // 'urlconvert' / 'emailconvert' - make addresses clickable
        $target = (string)($p['target'] ?? '_self');
        if ($this->_bool($p['urlconvert'] ?? false)) {
            $value = $this->_linkUrls($value, $target, (int)($p['urllimit'] ?? 80));
        }
        if (!empty($p['emailconvert']) && (string)$p['emailconvert'] !== 'false') {
            $value = $this->_linkEmails($value, $p);
        }

        // 'lbrconvert' (default on) - line breaks into <br>
        if ($this->_bool($p['lbrconvert'] ?? true)) {
            $value = $this->_nl2br($value);
        }

        // 'iso' / 'charset' - re-encode output
        $charset = $this->_bool($p['iso'] ?? false) ? 'ISO-8859-1' : (string)($p['charset'] ?? '');
        if ($charset !== '' && strtolower($charset) !== 'utf-8') {
            $value = mb_convert_encoding($value, $charset, 'UTF-8');
        }

        $this->_out($this->_decorate($value, $p), $p);
    }

    // CMS:URL
    //
    // Creates the frontend URL of a record in the format that Page::resolveRequest()
    // takes apart again:  [/{lang}]/{slug|id}[,{template}] - see recordUrl().
    //
    // 'lang' points to another language version (language switcher); without 'lang'
    // the language of the running request is kept.
    // 'id' takes - as with all commands - a record_id OR a slug, see
    // _record().
    //
    // Without 'id' in a result loop of CMS:SELECT_LANGVERSION: the rows
    // there are languages, not records - meant is the outer record that
    // php/push_result.php saved before the loop (language switcher).
    public function cmsUrl(array $p): void
    {
        $data = $this->_record($p);
        if ($this->_ref($p) === '' && !array_key_exists('record_id', $data)
            && array_key_exists('endonym', $data) && $this->rowStack) {
            $data = (array)(end($this->rowStack)[1] ?? []);
        }

        // 'lang' - language version the link points to (code from `language`)
        $lang = (string)($p['lang'] ?? '');
        // 'template' - second URL part, separated by comma; optionally with
        // area part "area.name" (see templateFile())
        $template = $this->_templateRef((string)($p['template'] ?? ''));

        // 'absolute' / 'protocol' / 'host' - full URL instead of server-relative path
        $host = (string)($p['host'] ?? '');
        $absolute = $this->_bool($p['absolute'] ?? false) || $host !== '';
        $protocol = (string)($p['protocol'] ?? '');

        // 'area' - overview of an area (prefix address, area.overview_template_id);
        // without 'id' and record on an overview page: the same overview (e.g.
        // in the language switcher)
        $areaRef = trim((string)($p['area'] ?? ''));
        $areaId = $areaRef !== '' ? $this->areaIdByRef($areaRef)
            : (($this->_ref($p) === '' && $template === '' && empty($data['record_id'])) ? $this->overviewAreaId : 0);
        if ($areaId > 0) {
            $this->_out($this->areaUrl($areaId, $lang, $absolute, $host, $protocol), $p);
            return;
        }

        $this->_out($this->recordUrl($data, $lang, $template, $absolute, $host, $protocol), $p);
    }

    /**
     * URL of a record - the one notation that CMS:URL creates and that
     * Page::resolveRequest() enforces with canonical = 1.
     *
     *     /{lang}/{slug|id}[,{template}]     instance with several languages
     *     /{slug|id}[,{template}]            instance with one language
     *
     * The language segment is the code from `language`; it is ALWAYS in the URL
     * as soon as the site (own instance + readable instances, Cms::$languages) has more
     * than one language - this way the language version is kept across all links of a page.
     * Without 'lang' it is the language of the running request; a
     * 'lang' that neither the instance nor its readable instances have is
     * ignored. Everything from the file cache, no database query.
     *
     * If the record has a slug, it wins - otherwise two URLs would point to the same
     * page and the canonical one would be the less frequently linked. Record::hookOnSave() fills
     * the field on save from the default field of the area; if it stays empty, the
     * record_id is in the URL.
     *
     * If 'lang' points to a different language than the one $data was read in, and
     * the slug field of the area is multilingual, the slug of the target language applies
     * (languageSlug()) - Page::resolveRequest() looks up the slug in the language of the URL.
     * If none is found, the record_id is in the URL (with canonical = 1 Page redirects
     * from there to the slug).
     *
     * If the area has a slug prefix (InstanceCache::slugPrefix()), it stands in
     * the same language before the slug: /{lang}/{prefix}/{slug}. The record_id gets
     * none - a pure string of digits is always the id (recordIdsBySlugPath()).
     *
     * @param array  $data      record contents (record_id, slug)
     * @param string $lang      language code or '' (running language)
     * @param string $template  template name or ''
     * @param bool   $absolute  true: with protocol and host
     * @param string $host      host for the absolute URL ('' = main domain of the
     *                          instance, otherwise the called host)
     * @param string $protocol  'http' | 'https' | '' (instance resp. request)
     * @return string
     */
    public function recordUrl(array $data, string $lang = '', string $template = '', bool $absolute = false, string $host = '', string $protocol = ''): string
    {
        $slug = trim((string)($data['slug'] ?? ''));
        $id   = (int)($data['record_id'] ?? 0);

        $targetId = null;   // language_id aus 'lang', null = laufende Sprache
        $path = $this->languagePath($lang, $targetId);

        // Slug of the target language - only with record id and a 'lang' that differs from the
        // language in which $data was read
        if ($id > 0 && $targetId !== null) {
            $instanceId = (int)($data['instance_id'] ?? ($this->instance['instance_id'] ?? 0));
            $slug = $this->languageSlug($data, $instanceId, $targetId) ?? $slug;
        }
        // slug prefix of the area in the same language
        if ($slug !== '') $slug = $this->slugWithPrefix($slug, $data, $targetId);

        $path .= ($slug !== '') ? $slug : $id;
        if ($template !== '') $path .= ',' . $template;

        return $absolute ? $this->absoluteUrl($path, $host, $protocol) : $path;
    }

    /**
     * URL of the overview of an area: the address that consists only of its slug prefix
     * - /{lang}/{prefix} (area.overview_template_id, Page::resolveRequest()).
     * Prefix in the target language ('lang') resp. the running one. Without prefix '' - the
     * area has no own address.
     *
     * @param int    $areaId
     * @param string $lang      language code or '' (running language)
     * @param bool   $absolute  true: with protocol and host (as recordUrl())
     */
    public function areaUrl(int $areaId, string $lang = '', bool $absolute = false, string $host = '', string $protocol = ''): string
    {
        if ($areaId < 1) return '';
        $targetId = null;
        $path = $this->languagePath($lang, $targetId);
        $languageId = $targetId ?? $this->recordLanguageId($this->instanceCache->areaInstanceId($areaId));
        $prefix = $this->instanceCache->slugPrefix($areaId, $languageId);
        if ($prefix === '') return '';
        $path .= $prefix;
        return $absolute ? $this->absoluteUrl($path, $host, $protocol) : $path;
    }

    /**
     * Path of the start page: '/{code}/' with more than one language in the site
     * (language from 'lang' resp. the running one), otherwise '/' (Page::resolveRequest()
     * enforces it with canonical = 1).
     *
     * @param string $lang  language code or '' (running language)
     */
    public function startPath(string $lang = ''): string
    {
        $targetId = null;
        return $this->languagePath($lang, $targetId);
    }

    /**
     * Area whose overview the address $path means: its slug prefix in the
     * running language is exactly $path, and it has an overview template. Only
     * areas of the instances of this site. 0 = none.
     */
    public function overviewAreaIdByPath(string $path): int
    {
        $path = mb_strtolower(trim($path, '/'), 'UTF-8');
        if ($path === '' || str_contains($path, '/')) return 0;
        $instanceIds = $this->accessInstanceIds ?: (array)($this->instanceCache->all()['instance_ids'] ?? []);
        foreach ($this->instanceCache->slugPrefixAreas(array_map('intval', $instanceIds)) as $areaId => $prefixes) {
            if (empty($this->instanceCache->areaInfo($areaId)['overview_template_id'])) continue;
            $languageId = $this->recordLanguageId($this->instanceCache->areaInstanceId($areaId));
            if (mb_strtolower($this->instanceCache->slugPrefix($areaId, $languageId), 'UTF-8') === $path) return (int)$areaId;
        }
        return 0;
    }

    /**
     * Language segment of a URL: '/{code}/' with more than one language in the site
     * (own instance + readable instances, see $languages), otherwise '/'. A 'lang'
     * that none of them has is ignored. $targetId: language_id from 'lang',
     * null = running language.
     */
    private function languagePath(string $lang, ?int &$targetId): string
    {
        $path = '/';
        $targetId = null;
        $languages = $this->languages;
        if (count($languages) > 1) {
            $code = '';
            $lang = preg_replace('/[^a-zA-Z0-9\-_]/', '', $lang);
            if ($lang !== '') {
                $language = $this->instanceCache->findByCode($languages, $lang);
                if ($language !== null) {
                    $code = (string)$language['code'];
                    $targetId = (int)$language['language_id'];
                }
            }
            if ($code === '') $code = $this->languageCode;
            if ($code === '') {
                // request language unknown (no frontend context) -> main language
                $first = reset($languages);
                $code = (string)($first['code'] ?? '');
            }
            if ($code !== '') $path .= $code . '/';
        }
        return $path;
    }

    /** Make a server-relative path into an absolute URL (protocol, host of the instance). */
    private function absoluteUrl(string $path, string $host = '', string $protocol = ''): string
    {
        if ($protocol === '') {
            $protocol = (string)($this->instance['protocol'] ?? '');
        }
        if ($protocol === '') {
            $protocol = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        }
        if ($host === '') $host = (string)($this->instance['primary_domain'] ?? '');
        if ($host === '') $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        return $protocol . '://' . $host . $path;
    }

    /**
     * Slug of a record in another language version (CMS:URL with 'lang').
     *
     * The `data_*` row that Page::resolveRequest() hits when calling the target URL
     * via the slug filter is read: the language that the request reads in the target language
     * for the instance of the record (the target language if the instance has it,
     * otherwise its main language - like Page::resolveLanguage()). The row is
     * fallback-filled: without a translation it holds the main language's slug.
     * One query via the primary key per record and language, noted for the request;
     * the area's schema (multilingual?) comes from loadSimpleSchema().
     *
     * @param array $data        record contents (record_id, area_id, slug)
     * @param int   $instanceId  instance of the record
     * @param int   $targetId    language_id of the target language
     * @return string|null       slug of the target language, '' = none (use record_id),
     *                           null = the same as in $data (same language resp. field
     *                           not multilingual)
     */
    private function languageSlug(array $data, int $instanceId, int $targetId): ?string
    {
        $id = (int)($data['record_id'] ?? 0);

        // language that a request reads in the target language for this instance
        $ids = $instanceId > 0 ? $this->instanceCache->languageIds($instanceId) : [];
        if (!empty($ids) && !in_array($targetId, $ids, true)) $targetId = (int)$ids[0];
        if ($targetId === $this->recordLanguageId($instanceId)) return null;

        $vf = $this->resolveVirtualField('slug');
        if ($vf === null) return null;

        // No or a single-language slug field in the area: nothing to look up,
        // the rows of all languages carry the same value
        $areaId = (int)($data['area_id'] ?? 0);
        if ($areaId > 0) {
            $this->loadSimpleSchema($areaId);
            $field = null;
            foreach ($this->virtualSchemaCache[$areaId] as $f) {
                if ((string)$f['name'] === 'slug') { $field = $f; break; }
            }
            if ($field === null || !$this->isMultilingualField($field)) return null;
        }

        $key = $id . ':' . $targetId;
        if (!array_key_exists($key, $this->slugCache)) {
            $this->db->pushResult();
            $this->db->query(
                'SELECT `data` FROM `' . $this->virtualDataTable($vf['type']) . '`'
                . ' WHERE `language_id` = ? AND `record_id` = ? AND `fieldname_id` = ?',
                'iii', [$targetId, $id, (int)$vf['fieldname_id']]
            );
            $this->slugCache[$key] = ($this->db->num_rows > 0)
                ? trim((string)($this->db->fetchNum()[0] ?? ''))
                : '';
            $this->db->popResult();
        }
        return $this->slugCache[$key];
    }

    /**
     * Slug with the slug prefix of its area: "farben/blau" (URL path without
     * language segment). Without prefix or without area_id in $data the slug remains as it is.
     *
     * Language of the prefix: $targetId (CMS:URL with 'lang'; a language that the
     * instance of the area does not have reads its main language), otherwise the one in which
     * the request reads the instance of the area (recordLanguageId()) - i.e. the same
     * language as the slug.
     *
     * @param string   $slug      slug of the record ('' = none)
     * @param array    $data      record contents (area_id)
     * @param int|null $targetId  language_id of the target language, null = running language
     */
    public function slugWithPrefix(string $slug, array $data, ?int $targetId = null): string
    {
        $areaId = (int)($data['area_id'] ?? 0);
        if ($slug === '' || $areaId < 1) return $slug;
        $instanceId = $this->instanceCache->areaInstanceId($areaId);
        $languageId = $targetId ?? $this->recordLanguageId($instanceId);
        $prefix = $this->instanceCache->slugPrefix($areaId, $languageId);
        return ($prefix !== '') ? $prefix . '/' . $slug : $slug;
    }

    /**
     * URL path (without language segment) -> record_ids: the resolution that Page and all
     * commands with 'id' share.
     *
     *     farben/blau          Is there an area with the slug prefix "farben"?
     *                          Yes: in it the record with the slug "blau".
     *                          No: the record with the slug "farben/blau" - only in
     *                          areas WITHOUT prefix (otherwise the same record would have
     *                          two URLs).
     *
     * Searched in the instances of the request (accessInstanceIds(); no
     * restriction = all) per instance in the language that the request reads there
     * (recordLanguageId()) - prefix and slug in the same language. $languageMap
     * specifies it explicitly (import: main language of the target instance).
     *
     * A path is unique as long as Record and Area could run their checks
     * (Record::slugPathOk(), Area::validateSlugPrefix()); more than one id is an
     * inconsistency of the database - the caller reports "Ambiguous path".
     *
     * @param string $path         URL path without language segment and template
     * @param string $published    additional condition on `record` (publishedSql())
     * @param array  $languageMap  instance_id => language_id (default instead of request)
     * @param int[]  $instanceIds  instances instead of accessInstanceIds()
     * @return int[]  0, 1 or (ambiguous) 2 record_ids
     */
    public function recordIdsBySlugPath(string $path, string $published = '', array $languageMap = [], array $instanceIds = []): array
    {
        $path = trim($path);
        // a pure string of digits is the record_id, never a slug (Record::hookOnSave())
        if ($path === '' || preg_match('/^\d+$/', $path)) return [];
        $vf = $this->resolveVirtualField('slug');
        if ($vf === null) return [];

        if (empty($instanceIds)) $instanceIds = $this->accessInstanceIds;
        if (empty($instanceIds)) $instanceIds = (array)($this->instanceCache->all()['instance_ids'] ?? []);
        $instanceIds = array_values(array_filter(array_map('intval', $instanceIds)));
        if (empty($instanceIds)) return [];

        // Sprache je Instanz
        $languages = [];
        foreach ($instanceIds as $instanceId) {
            $languages[$instanceId] = (int)($languageMap[$instanceId] ?? $this->recordLanguageId($instanceId));
        }

        // areas with prefix in the language of their instance (lowercase - the
        // column `data` compares without regard to case)
        $prefixed = [];
        foreach ($this->instanceCache->slugPrefixAreas($instanceIds) as $areaId => $prefixes) {
            $instanceId = $this->instanceCache->areaInstanceId($areaId);
            $prefix = $this->instanceCache->slugPrefix($areaId, $languages[$instanceId] ?? 0);
            if ($prefix !== '') $prefixed[$areaId] = mb_strtolower($prefix, 'UTF-8');
        }

        $slash = strpos($path, '/');
        if ($slash !== false && !empty($prefixed)) {
            $areaIds = array_keys($prefixed, mb_strtolower(substr($path, 0, $slash), 'UTF-8'), true);
            if (!empty($areaIds)) {
                $rest = trim(substr($path, $slash + 1));
                if ($rest === '') return [];
                return $this->slugRecordIds($vf, $rest, $languages, $areaIds, [], $published);
            }
        }
        return $this->slugRecordIds($vf, $path, $languages, [], array_keys($prefixed), $published);
    }

    /**
     * Query for recordIdsBySlugPath(): live records with this slug.
     *
     * @param array $vf          slug field (resolveVirtualField())
     * @param array $languages   instance_id => language_id (also the instances)
     * @param int[] $inAreas     only these areas (empty = all)
     * @param int[] $notInAreas  not these areas
     * @return int[]  at most 2 record_ids
     */
    private function slugRecordIds(array $vf, string $slug, array $languages, array $inAreas, array $notInAreas, string $published): array
    {
        // language per instance: literal (only numbers). The IN up front keeps the index
        // (language_id, fieldname_id, data) usable, the CASE maps precisely.
        $languageIds = array_values(array_unique($languages));
        if (count($languageIds) === 1) {
            $languageSql = '`d`.`language_id` = ' . (int)$languageIds[0];
        }
        else {
            $cases = '';
            foreach ($languages as $instanceId => $languageId) {
                $cases .= ' WHEN ' . (int)$instanceId . ' THEN ' . (int)$languageId;
            }
            $languageSql = '`d`.`language_id` IN (' . implode(',', array_map('intval', $languageIds)) . ')'
                . ' AND `d`.`language_id` = CASE `record`.`instance_id`' . $cases . ' END';
        }
        $sql = 'SELECT DISTINCT `record`.`record_id` FROM `' . $this->virtualDataTable($vf['type']) . '` `d`'
             . ' INNER JOIN `record` ON `record`.`record_id` = `d`.`record_id` AND `record`.`tmp` = 0 AND `record`.`deleted` = 0'
             . ' WHERE ' . $languageSql . ' AND `d`.`fieldname_id` = ? AND `d`.`data` = ?'
             . $this->liveFieldSql('d', (int)$vf['fieldname_id'])
             . ' AND `record`.`instance_id` IN (' . implode(',', array_map('intval', array_keys($languages))) . ')';
        if (!empty($inAreas)) {
            $sql .= ' AND `record`.`area_id` IN (' . implode(',', array_map('intval', $inAreas)) . ')';
        }
        if (!empty($notInAreas)) {
            $sql .= ' AND `record`.`area_id` NOT IN (' . implode(',', array_map('intval', $notInAreas)) . ')';
        }
        if ($published !== '') $sql .= ' AND ' . $published;
        $sql .= ' LIMIT 2';

        $this->db->pushResult();
        $this->db->query($sql, 'is', [(int)$vf['fieldname_id'], $slug]);
        $ids = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
        return $ids;
    }

    // CMS:XML
    // Must be the first thing in the template - no character may be output before it.
    public function cmsXml($p): void
    {
        $version  = (string)($p['version']  ?? '1.0');
        $encoding = (string)($p['encoding'] ?? 'utf-8');
        echo '<?xml version="' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8')
           . '" encoding="' . htmlspecialchars($encoding, ENT_QUOTES, 'UTF-8') . '"?>';
    }


    // Helper functions for the CMS commands
    // ====================


    /**
     * Determine the source of an output command: 'id', 'field' or 'useval'.
     *
     * 'id' loads another record and reads 'field' from it (default: 'text').
     * Without 'id' the value comes from the current record $GLOBALS['cms'].
     *
     * The return value is not necessarily a string - file and JSON fields return arrays
     * (see cmsMedia()).
     *
     * @param array $p  command parameters
     * @return mixed    raw value, '' if nothing to fetch
     */
    private function _source(array $p): mixed
    {
        $field = (string)($p['field'] ?? '');

        // 'id' points to another record. Without 'field' the field
        // 'text' is meant - so it was in C8, and only CMS:TEXT allows 'id' alone
        // (for all other commands 'oneof' requires 'field' or 'useval' anyway).
        if ($this->_ref($p) !== '' && $field === '') $field = 'text';

        if ($field !== '') {
            return $this->_record($p)[$field] ?? '';
        }

        // 'useval' - value stands literally in the template
        return $p['useval'] ?? '';
    }

    /**
     * Read the value of the parameter 'id' as reference.
     *
     * @return string  '' if no reference is given
     */
    private function _ref(array $p): string
    {
        return is_scalar($p['id'] ?? null) ? trim((string)$p['id']) : '';
    }

    /**
     * Determine the record a command refers to.
     *
     * Without 'id' this is the running record - the one that Page::resolveRequest()
     * loaded, resp. the row at which the result loop currently stands.
     *
     * With 'id' the specified record is loaded, namely by the same rule
     * by which Page::resolveRequest() also resolves a URL:
     *
     *     pure string of digits -> record_id, directly via SQL
     *     everything else       -> URL path [prefix/]slug, recordIdsBySlugPath()
     *                             (ambiguous: treated as not found)
     *
     * This makes 'id' in the template the same as what stands in the URL - the successor
     * of the C8 'idname'. A slug is never a pure string of digits, so the distinction is
     * therefore unambiguous (see Record::hookOnSave()).
     *
     * Publication also checks 'id' like the URL in the frontend: released and
     * publication date reached, expired (offline_at) remain readable
     * (publishedSql(['archive' => true])). This also applies in the preview of the
     * backend - as with CMS:SELECT only the record called via the URL may there
     * itself be unpublished (it comes as the running record).
     *
     * Loaded records are noted for the request: the same reference in a
     * result loop costs only one query.
     *
     * @param array $p  command parameters
     * @return array    record contents, empty if the reference does not exist
     */
    private function _record(array $p): array
    {
        $ref = $this->_ref($p);
        $current = $GLOBALS['cms'] ?? [];

        // No reference, or one to the running record -> it is already there
        if ($ref === '') return $current;
        if ($ref === (string)($current['record_id'] ?? '')) return $current;
        if ($ref === $this->slugWithPrefix(trim((string)($current['slug'] ?? '')), $current)) return $current;

        if (array_key_exists($ref, $this->recordRefCache)) {
            return $this->recordRefCache[$ref];
        }

        $data = [];
        $published = $this->publishedSql(['archive' => true]);
        // The command may stand within a result loop - the running
        // result must therefore be set aside.
        $this->db->pushResult();
        // record_id: via the id neither virtual fields nor filter logic are needed;
        // otherwise URL path [prefix/]slug - the same resolution as when calling the page
        $ids = preg_match('/^\d+$/', $ref) ? [(int)$ref] : $this->recordIdsBySlugPath($ref, $published);
        if (count($ids) === 1) {
            $this->db->query(
                "SELECT * FROM `record` WHERE `record_id` = ? AND `tmp`=0 AND `deleted`=0 AND {$published}",
                'i',
                [(int)$ids[0]]
            );
            if ($this->db->num_rows > 0) $data = $this->fetch() ?? [];
        }
        $this->db->popResult();

        $this->recordRefCache[$ref] = $data;
        return $data;
    }

    /**
     * Apply ifempty / instead / prefix / suffix - see conventions above.
     *
     * @param string $value        prepared value
     * @param array  $p            command parameters
     * @param bool   $zeroIsEmpty  true: "0" also counts as empty (CMS:NUMBER, CMS:MEDIA)
     */
    private function _decorate(string $value, array $p, bool $zeroIsEmpty = false): string
    {
        $isEmpty = ($value === '') || ($zeroIsEmpty && $value === '0');

        if ($isEmpty) {
            // 'ifempty' replaces the whole output, prefix/suffix are dropped
            return (string)($p['ifempty'] ?? '');
        }
        // 'instead' - replace the value, formatting was nevertheless necessary (empty/not empty)
        if (isset($p['instead']) && (string)$p['instead'] !== '') {
            $value = (string)$p['instead'];
        }
        return (string)($p['prefix'] ?? '') . $value . (string)($p['suffix'] ?? '');
    }

    /**
     * Output result - or redirect to $GLOBALS['set'] with 'set'.
     */
    private function _out(string $html, array $p): void
    {
        if (!empty($p['set'])) $GLOBALS['set'][$p['set']] = $html;
        else echo $html;
    }

    /**
     * Bring parameters that describe pairs ('if', 'replace') into one form.
     *
     *     if[alt]="neu"     ->  ['alt' => 'neu']
     *     if="alt?neu"      ->  ['alt' => 'neu']
     *     if[]="a?b" …      ->  ['a' => 'b', …]
     *
     * @return array  Suchwert => Ersatzwert
     */
    private function _pairs(mixed $param): array
    {
        $pairs = [];
        foreach ((array)$param as $key => $value) {
            // PHP turns a digit key (if[1]="x") into an int key -
            // it is then not to be told apart from the list form (if[]="1?x", if="1?x").
            // Rule: int key + question mark in the value = short form
            // "search?replace", otherwise the key itself is the search value. Only
            // if[1]="Text mit ?" remains ambiguous - for that use if[]="1?Text mit ?".
            if (is_int($key) && str_contains((string)$value, '?')) {
                $parts = explode('?', (string)$value, 2);
                $pairs[$parts[0]] = $parts[1];
            }
            else {
                $pairs[$key] = $value;
            }
        }
        return $pairs;
    }

    /**
     * Apply parameter 'replace' - replace partial texts in the value.
     *
     *     replace="alt?neu"          short form, question mark as separator
     *     replace[alt]="neu"         one entry per search text
     *     replace[]="a?b" …          mehrere Kurzformen
     *
     * With CMS:TEXT it acts on the source, with CMS:DATE/DATETIME/TIME and CMS:NUMBER
     * on the already formatted value (e.g. replace=".?," or replace=".00?").
     *
     * @param string $value  value in which to replace
     * @param array  $p      Befehlsparameter
     */
    private function _replace(string $value, array $p): string
    {
        if (!isset($p['replace'])) return $value;
        foreach ($this->_pairs($p['replace']) as $search => $replace) {
            if ((string)$search !== '') {
                $value = str_replace((string)$search, (string)$replace, $value);
            }
        }
        return $value;
    }

    /**
     * Read template parameter as a truth value.
     * Values always arrive from the template as strings ("true"/"false").
     */
    private function _bool(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        return filter_var((string)$value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Shared runtime of CMS:DATE, CMS:DATETIME and CMS:TIME.
     *
     * The three commands differ only in which part of the value they
     * show; source, formatting and time zone display are identical.
     *
     *     'format'    ICU pattern (dd.MM.y HH:mm). Without specification
     *                 _formatDate() resp. _formatTime() decide based on the user's locale.
     *     'timezone'  true: append the name of the time zone
     *     'autotime'  only CMS:DATETIME and only together with 'format': if the value
     *                 had no time set (00:00:00), it is not shown either.
     *                 Without 'format' _formatDate() omits it anyway.
     *
     * @param array  $p     Befehlsparameter
     * @param string $mode  'date' | 'datetime' | 'time'
     */
    private function _dateValue(array $p, string $mode): string
    {
        $value = trim((string)$this->_source($p));
        if ($value === '') return '';
        // Placeholder (no date / no end, e.g. an unlimited offline_at)
        // always count as empty - with and without 'format' ('ifempty' applies)
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)
            && ($value < self::DATE_MIN_DISPLAY || $value >= self::DATE_MAX_DISPLAY)) return '';

        // 'timezone' - see _formatDate() / _formatTime()
        $displayTimezone = $this->_bool($p['timezone'] ?? false);

        // Was a time set in the raw value at all?
        $hasTime = (strpos($value, ' ') !== false) && (substr($value, -8) !== '00:00:00');

        // CMS:DATE: truncate the time - _formatDate() omits 00:00 by itself
        if ($mode === 'date' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m)) {
            $value = $m[1] . ' 00:00:00';
            $hasTime = false;
        }

        $format = (string)($p['format'] ?? '');
        if ($format !== '') {
            $value = $this->_formatPattern($value, $format, $displayTimezone);
            // 'autotime'
            if ($mode !== 'time' && !$hasTime && $this->_bool($p['autotime'] ?? false)) {
                $value = trim(preg_replace('/\s*\d{1,2}:\d{2}(:\d{2})?\s*/u', ' ', $value));
            }
            return $value;
        }

        return ($mode === 'time')
            ? $this->_formatTime($value, $displayTimezone)
            : $this->_formatDate($value, $displayTimezone);
    }

    /**
     * Format date by ICU pattern (dd.MM.y HH:mm).
     * Without the intl extension the raw value remains.
     *
     * @param bool $displayTimezone  append the name of the time zone (as _formatDate())
     */
    private function _formatPattern(string $isoDate, string $pattern, bool $displayTimezone = false): string
    {
        try {
            $date = new \DateTimeImmutable($isoDate);
        } catch (\Exception $e) {
            return $isoDate;
        }
        $timezone = $date->getTimezone();
        $formatter = $this->_intlFormatter(
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            $timezone
        );
        if ($formatter === null) return $isoDate;
        $formatter->setPattern($pattern);
        $formatted = $formatter->format($date);
        if ($formatted === false) return $isoDate;
        return $formatted . ($displayTimezone ? ' ' . $timezone->getName() : '');
    }

    /**
     * Determine the decimal places of a number, capped at 6 -
     * so that CMS:NUMBER without 'format' neither rounds nor appends zeros.
     */
    private function _fractionDigits(float $number): int
    {
        $text = rtrim(rtrim(sprintf('%.6F', $number), '0'), '.');
        $dot = strpos($text, '.');
        return ($dot === false) ? 0 : strlen($text) - $dot - 1;
    }

    /**
     * Make bytes readable: 15728640 -> "15 MB"
     *
     * @param float $bytes
     * @param int   $factor  1000 (SI) or 1024 (binary)
     */
    private function _formatBytes(float $bytes, int $factor = 1024): string
    {
        if ($factor < 2) $factor = 1024;
        $units = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $step = 0;
        while ($bytes >= $factor && $step < count($units) - 1) {
            $bytes /= $factor;
            $step++;
        }
        // without locale - the point also stands this way in the command description ("15.2 GB")
        return number_format($bytes, $step > 0 ? 1 : 0, '.', '') . ' ' . $units[$step];
    }

    /**
     * Link http(s) and www addresses in the text.
     *
     * @param string $text
     * @param string $target  link target
     * @param int    $limit   visible length of the address (0 = not shortened)
     */
    private function _linkUrls(string $text, string $target, int $limit): string
    {
        $pattern = '~(?<![\w/@."\'])((?:https?://|www\.)[^\s<>"\']+[^\s<>"\'.,;:!?)])~i';
        return preg_replace_callback($pattern, function ($m) use ($target, $limit) {
            $url = $m[1];
            $href = (stripos($url, 'http') === 0) ? $url : 'http://' . $url;
            $label = ($limit > 0) ? $this->_strlimit($url, $limit) : $url;
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
                 . ' target="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '">'
                 . $label . '</a>';
        }, $text) ?? $text;
    }

    /**
     * Link e-mail addresses in the text.
     *
     * 'emailconvert' = 'encrypt' assembles the link via JavaScript; in the
     * page source no complete address remains then - enough against
     * simple address harvesters, no protection against targeted extraction. The script
     * carries the nonce of the page (cspNonce()), so it also runs under CSP enforce.
     *
     * @param string $text
     * @param array  $p  command parameters ('emailconvert', 'emailtext', 'emaillimit')
     */
    private function _linkEmails(string $text, array $p): string
    {
        $mode  = (string)$p['emailconvert'];
        $limit = (int)($p['emaillimit'] ?? 0);
        $label = (string)($p['emailtext'] ?? '');

        $pattern = '~(?<![\w.@])([\w.+-]+)@([\w-]+(?:\.[\w-]+)+)~';
        $nonce = ($mode === 'encrypt') ? $this->cspNonce() : '';
        return preg_replace_callback($pattern, function ($m) use ($mode, $limit, $label, $nonce) {
            $address = $m[0];
            $visible = $label !== '' ? $label : ($limit > 0 ? $this->_strlimit($address, $limit) : $address);

            if ($mode === 'encrypt') {
                // pass the address in parts to document.write - each part as
                // JS string with < > & ' " as \u00.. (no </script> in the text), the @
                // also separated in the visible text
                $js = static fn(string $v): string => (string)json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS
                    | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $code = "document.write('<a href=\"mai'+'lto:'+" . $js($m[1]) . "+'@'+" . $js($m[2])
                      . "+'\">'+" . implode("+'@'+", array_map($js, explode('@', $visible))) . "+'</a>');";
                return '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '">' . $code . '</script>';
            }
            return '<a href="mailto:' . htmlspecialchars($address, ENT_QUOTES, 'UTF-8') . '">'
                 . $visible . '</a>';
        }, $text) ?? $text;
    }

    /**
     * Is at least one of the keys in $p present?
     *
     * @param array $a  sought keys
     * @param array $p  command parameters
     */
    private function _oneOf($a, $p): bool
    {
        return !empty(array_intersect($a, array_keys($p)));
    }

    // Reduce to a name
    private function _name(string $s): string
    {
        return preg_replace('/[^a-z0-9_]/i', '', $s);
    }

    // Reduce to a template reference "[area.]name"
    private function _templateRef(string $s): string
    {
        [$areaName, $name] = $this->splitTemplateRef($s);
        if ($name === '') return '';
        return ($areaName !== '') ? $areaName . '.' . $name : $name;
    }

    // output error message
    private function _cmsError($t): void
    {
        die($t);
    }
}
