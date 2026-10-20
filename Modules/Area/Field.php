<?php
/**
 * Dataset subclass for the table `field` - the input fields of a content area.
 *
 * A field is the definition, not the content: type, label, display type,
 * validation rules. The actual values are stored type-separated in the `data_*` tables
 * (EAV, see developer docs, chapter 02 (Data model)).
 *
 * THREE TABLES THAT ARE ALSO MAINTAINED
 * --------------------
 *     fieldname          global registration name -> type, unique across areas.
 *                        The primary key of the data_* tables is
 *                        (record_id, fieldname_id) - NOT field_id. This allows
 *                        Cms::select() to filter by a field name across area boundaries
 *                        without knowing the field_id per area. The price for this:
 *                        a name is bound system-wide to exactly one type.
 *                        -> createFieldname() / cleanupFieldname()
 *     rel_field_area     Ziel-Bereiche eines 'pointer'-Feldes (n Bereiche)
 *     rel_fulltext_text  Quell-Felder eines 'fulltext'-Feldes
 *     rel_field_converter  converter of a thumbnail field, with `position` - the
 *                        order in which thumbnail generation runs through them
 *                        (VirtualFieldsTrait: the first matching one wins)
 *
 * RESERVED NAME PREFIXES
 * --------------------
 * $fieldPrefixes is built from Cms::$dataSuffix (txt_, int_, fil_, …) plus four
 * special cases. A name must not begin with the prefix of a DIFFERENT type - `txt_x`
 * as an integer field is rejected. The point: the name alone should reveal the type,
 * without having to look in `fieldname`.
 *
 * FIELD TYPES WITH SPECIAL HANDLING
 * --------------------
 *     relation / relation1   link into another area. When creating, a counter-field is
 *                            created automatically in the TARGET AREA (name
 *                            `rel_{areaname}`), so that the link is visible from both sides.
 *                            rel_field_id always points to the other side.
 *     pointer / pointer1     reference to records from SEVERAL areas. The list
 *                            of target areas does not fit into one column and is therefore stored
 *                            in `rel_field_area`; the schema column
 *                            `rel_area_ids` stays empty.
 *     link                   firmly linked content (1:n): directed like 'pointer'
 *                            with several target areas (`rel_field_area`), but the
 *                            target records BELONG to the source record - per
 *                            target record at most one source record; deleting,
 *                            copying and moving the source record affect
 *                            them as well (Camindo\Core\LinkTrait). Target areas
 *                            must be dependent areas (`area.dependent` = 1).
 *                            display_type 'lookup' (search window as before) or
 *                            'embed' (the input masks of the target records appear in
 *                            the mask of the source record). Always hand-sorted
 *                            (custom_order = 1), without maximum; cannot be converted.
 *     fulltext               collective field that bundles the text of several other fields for the
 *                            full-text search. The source fields are stored in
 *                            `rel_fulltext_text`, in the form under the pseudo field
 *                            data['source_fields'].
 *     file + display_type    thumbnail, from an upload field (parent_field_id)
 *     'thumbnail'            erzeugt. Seine Konverter stehen sortiert in
 *                            `rel_field_converter`, in the schema as 'reltable' field
 *                            `converter_ids` (display_type 'sortable': the order
 *                            of the ticked options is the value). At least one
 *                            is required. Until 2026-09-15 the ids were stored unsorted in
 *                            details.converter_ids.
 *     relation/pointer +     details.lookup_detailed: 1 = the preview of the linked
 *     display_type 'lookup'  records shows all list fields of the
 *                            target area one below the other (default field in bold) and its
 *                            list template, instead of only the default field
 *                            (RecordBackendEdit::_lookupPreviewCreate()).
 *     file + display_type    'thumbnail' erzeugt abgeleitete Medienversionen, verlangt
 *                            so at least one converter and no input is allowed.
 *                            Upload and thumbnail fields know details.user_auth:
 *                            1 = files not directly retrievable via the media directory
 *                            retrievable (Camindo\Core\MediaAccess locks the field
 *                            directory via .htaccess), delivered only via
 *                            public/file.php to frontend users from the profile
 *                            Bereichen in details.profile_area_ids.
 *
 * The suffixes '1' (relation1, pointer1) mean "at most one target" - therefore set
 * the hooks set `max = 1` for them.
 *
 * THE STANDARD FULL-TEXT FIELD `fulltext`
 * --------------------
 * Every record area has a full text `fulltext` over all text, character,
 * JSON and upload fields - without a row in this table; it is written by
 * VirtualFieldsTrait::refreshStandardFulltext(). Here only: the name is reserved
 * (FULLTEXT), remains in `fieldname` (cleanupFieldname()). Via a link field
 * the text fields of the target areas also go in (VirtualFieldsTrait::
 * standardFulltextSources()). Sources of own full-text fields can additionally
 * be fields behind link and relation fields (fulltextSourceOptions()).
 *
 * If a source field is deleted or converted, the sources of a
 * full-text field or the target areas of a link field change; hookOnSaved(),
 * hookOnDeleted() or convert() mark the full text of the affected areas as outdated
 * (FulltextState) - it is rebuilt via "Update now" or the
 * scheduler. Only the conversion to 'encrypted' rebuilds immediately.
 *
 * TRASH
 * --------------------
 * Deleting a field (Dataset::delete()) only marks it: `deleted` = 1, changed_at =
 * time of the operation. Its `data_*` rows and relation table entries stay, but
 * every query on `field` reads only live fields (`deleted` = 0) - masks, lists,
 * virtualSchema(), record_cache (cleared for the area, rebuilt without the field),
 * and Cms::filter()/sort exclude its data_* rows (Cms::liveFieldSql()). After
 * admin.trash_retention_days the scheduler task trash deletes it finally
 * (ToolDatabase::purgeTrash() -> hookOnDelete()/hookOnDeleted() as before).
 * What goes along (same timestamp, back with restore()): the counter field of a
 * relation and the thumbnail fields of an upload field; with an area all its
 * fields and the relation fields of other areas pointing to it
 * (Area::hookOnSoftDelete()). The name stays registered in `fieldname` and taken
 * in the area (hookOnSave()) - a restore cannot meet a type or name conflict.
 * Exception to "only live fields": a `parent_id` field in the trash keeps the
 * tree of its area (TreeManagerTrait::isTreeArea()) - only the final deletion
 * flattens it.
 *
 * TYPE AND MULTILINGUALISM CHANGE ONLY VIA convert()
 * --------------------
 * The type is fixed since creation, and the flag `multilingual` can no longer be
 * switched via the input mask for an existing field
 * (hookOnSave() removes it from the fields to be written) - an accidental click
 * would otherwise discard the translations of all records. Both now change only via
 * the explicit conversion: convert() copies the stock into the data_* table
 * of the target type (conversionTargets() says which targets exist, conversionLossy(),
 * whether something can be lost in the process), adjusts the `empty` flags resp. the
 * file storage and adjusts the field definition.
 *
 * @see Camindo\Core\Modules\Area\Area          content area the field belongs to
 * @see Camindo\Core\Modules\Area\FieldBackend  Backend interface
 * @see Camindo\Core\Cms                          $types, $dataSuffix, $relationTypes …
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Area;

use Camindo\Core\Backend;
use Camindo\Core\Dataset;
use Camindo\Core\FulltextState;
use Camindo\Core\InstanceCache;
use Camindo\Core\MediaAccess;
use Camindo\Core\RecordCache;
use Camindo\Core\Modules\Record\Record;

class Field extends Dataset {

    // Meta fields that input fields may be based on
    // (columns fixed in the record schema - an input field can
    // label and display them without creating a data_* row for them)
    public array $metafields = [
        'online_at',
        'offline_at',
        'status_id',
        'template_id',
        'position',
        'parent_id'
    ];

    // Reserved name prefixes, filled in hookDefinition(): type => prefix.
    // A field name must not begin with the prefix of another type.
    private array $fieldPrefixes = [];

    /** Name of the standard full-text field (see class comment) - reserved */
    public const FULLTEXT = 'fulltext';

    /** Types whose fields can go into a full text - 'file': upload fields
     *  with their file name (no thumbnails, which have no own one) */
    public const FULLTEXT_SOURCE_TYPES = ['text', 'chars', 'json', 'file'];

    // Areas whose full text had a just-deleted field as source
    // (hookOnDelete() -> hookOnDeleted())
    private array $fulltextOfDeleted = [];

    // field_id => true while its soft deletion/restore cascades into the counter
    // field - which points back (hookOnSoftDelete(), hookOnRestore())
    private array $trashCascade = [];

    // InstanceCache::rebuild() is due after the next commit (queueCacheRebuild())
    private bool $cacheRebuildPending = false;

    // Types that can be converted into each other (convert()): within and
    // between the two groups. Including relation <-> relation1 and pointer <-> pointer1
    // as well as switching single/multilingual for all types capable of multilingualism.
    // Not included: id (foreign key to `record`), password (hash), json, date/
    // time, file, fulltext - for them there is no sensible target type.
    public array $convertTextTypes    = ['chars', 'text', 'encrypted'];
    public array $convertNumericTypes = ['integer', 'currency', 'float', 'decimal', 'bit'];

    // Conversions WITHOUT possible data loss (source type => target types); every other
    // combination gets the data loss note in the selection - e.g.
    // text -> chars (255 characters), text -> number (non-numeric content), float ->
    // integer (decimal places), relation -> relation1 (only one link remains).
    // integer -> float/decimal counts as lossless: the value ranges of the stock
    // practically never exceed the precision of double or decimal(20,10).
    private array $losslessConversions = [
        'chars'     => ['text', 'encrypted'],
        'text'      => ['encrypted'],
        'encrypted' => ['text'],
        'bit'       => ['integer', 'currency', 'float', 'decimal', 'chars', 'text', 'encrypted'],
        'integer'   => ['currency', 'float', 'decimal', 'chars', 'text', 'encrypted'],
        'currency'  => ['float', 'decimal', 'chars', 'text', 'encrypted'],
        'float'     => ['chars', 'text', 'encrypted'],
        'decimal'   => ['chars', 'text', 'encrypted'],
        'relation1' => ['relation'],
        'pointer1'  => ['pointer'],
    ];

    // Assignment of predefined MIME types to values in the field 'media_types'
    // The editor ticks groups in the form ('image', 'pdf', …); from this the
    // actual MIME type list for the upload filter is built. 'image/*' is a pattern and
    // is treated as a prefix when checking - without SVG (active content, see
    // VirtualFieldsTrait::$mediaTypeGroupsExcl); SVG only via media_types_incl.
    public array $mimeTypes = [
        'image' => [
            'mime' => ['image/*'],
            'label' => 'area.mime_type_image'
        ],
        'pdf' => [
            'mime' => ['application/pdf'],
            'label' => 'area.mime_type_pdf'
        ],
        'audio' => [
            'mime' => ['audio/*'],
            'label' => 'area.mime_type_audio'
        ],
        'video' => [
            'mime' => ['video/*'],
            'label' => 'area.mime_type_video'
        ],
        'zip' => [
            'mime' => [
                'application/zip',
                'application/x-zip-compressed',
                'application/x-compress',
                'application/x-compressed'
            ],
            'label' => 'area.mime_type_zip'
        ],
        'text' => [
            'mime' => [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/rtf',
                'text/plain',
            ],
            'label' => 'area.mime_type_text'
        ],
        'excel' => [
            'mime' => [
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/csv'
            ],
            'label' => 'area.mime_type_excel'
        ],
        'ppt' => [
            'mime' => [
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation'
            ],
            'label' => 'area.mime_type_powerpoint'
        ],
    ];

    /**
     * Tabellenstruktur `field`
     *
     * The entries with a dot in the key ('details.media_types',
     * …) are not own columns, but subfields of the JSON column `details`.
     * Dataset knows this dot notation and reads/writes it into the JSON.
     *
     * At the end the reserved name prefixes are assembled: the prefixes of the
     * data_* tables from Cms::$dataSuffix plus four types that have no own
     * data_* table and therefore do not appear there.
     */
    protected function hookDefinition(): void
    {
        $this->tableName = 'field';
        $this->moduleName = 'Area';
        $this->defaultSortOrder = 'position ASC';
        $this->schema = [
            'field_id' => [
                'type'  => 'integer',
                'label' => 'field_id',
            ],
            'area_id' => [
                'type'  => 'integer', // no foreign key
                'label' => 'area_id',
                'min'   => 1,
            ],
            'type' => [
                'type'  => 'chars',
                'label' => '#area.field_field_type',
                'max'   => 12,
                'flags' => ['not_empty'],
            ],
            'name' => [
                'type'  => 'chars',
                'label' => '#area.field_field_name',
                'tooltip' => '#area.tooltip_field_name',
                'max'   => 32,
                'flags' => ['not_empty', 'trim', 'name', 'unique_in_area'],
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#area.field_field_label',
                'tooltip' => '#area.tooltip_field_label',
                'max'   => 64,
                'extension' => 'Snippets',   // Core extension: select snippet (#prefix.key)
                'flags' => ['trim', 'not_empty'],
            ],
            'display_type' => [
                'type'   => 'chars',
                'label'  => '#area.field_field_display_type',
                'display_type' => 'select',
                'flags'  => ['not_empty'],
            ],
            'note' => [
                'type'  => 'chars',
                'label' => '#area.field_field_note',
                'tooltip' => '#area.tooltip_field_note',
                 'max'  => 64,
                 'extension' => 'Snippets',
            ],
            'tooltip' => [
                'type'  => 'chars',
                'label' => '#area.field_field_tooltip',
                'tooltip' => '#area.tooltip_field_tooltip',
                 'max'  => 255,
                 'extension' => 'Snippets',
            ],
            'height' => [
                'type'  => 'integer',
                'suffix' => '#unit_rows',
                'default' => '(auto)',
                'min' => 0,
                'max' => 100,
                'label' => '#area.field_field_height',
            ],
            'prefix' => [
                'type'  => 'chars',
                'label' => '#area.field_field_prefix',
                'tooltip' => '#area.tooltip_field_prefix',
                'max'   => 32,
            ],
            'suffix' => [
                'type'  => 'chars',
                'label' => '#area.field_field_suffix',
                'tooltip' => '#area.tooltip_field_suffix',
                'max'   => 32,
            ],
            'pattern' => [
                'type'  => 'chars',
                'label' => '#area.field_field_pattern',
                'tooltip'  => '#area.note_field_pattern',
                'prefix' => '^',
                'suffix' => '$',
                'max'   => 255,
            ],
            'min' => [
                'type'  => 'chars',
                'label' => '#area.field_field_min',
                'max'   => 255,
            ],
            'min_op' => [
                'type'  => 'chars',
                'label' => '#area.field_field_min_op',
            ],
            'max' => [
                'type'  => 'chars',
                'label' => '#area.field_field_max',
                'max'   => 255,
            ],
            'max_op' => [
                'type' => 'chars',
                'label' => '#area.field_field_max_op',
            ],
            'position' => [
                'type'  => 'integer',
                'display_type' => 'select',
                'label' => '#area.field_field_position',
            ],
            'rel_field_id' => [
                'type'  => 'id',
                'label' => '#area.field_field_rel_field_id'
            ],
            'rel_area_id' => [
                'type'  => 'id',
                'display_type' => 'select',
                'label' => '#area.field_field_rel_area_id'
            ],
            // table: `rel_field_area`
            'rel_area_ids' => [
                'type'  => 'reltable',
                'display_type' => 'multiselect',
                'label' => '#area.field_field_rel_area_ids',
                'height' => '(auto)'
            ],
            'custom_order' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_custom_order',
                'default' => 1
            ],
            'flags' => [
                'type'  => 'json',
                'display_type' => 'checkbox',
                'label' => '#area.field_field_flags',
                'max' => '255'
            ],
            'parent_field_id' => [
                'type'  => 'id',
                'display_type' => 'select',
                'label' => '#area.field_field_parent_field_id',
                'table' => 'field'
            ],
            'default' => [
                'type'   => 'text',
                'display_type' => 'textarea',
                'label'  => '#area.field_field_default',
                'height' => 0 // growing
            ],
            'visibility_input' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_visibility_input',
                'tooltip' => '#area.table_col_input_note',
                'default' => 1
            ],
            'visibility_search' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_visibility_search',
                'tooltip' => '#area.table_col_search_note',
                'default' => 1
            ],
            'visibility_list' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_visibility_list',
                'tooltip' => '#area.table_col_list_note',
                'default' => 1
            ],
            'default_field' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_default_field',
                'tooltip' => '#area.tooltip_field_default_field',
            ],
            'meta' => [
                'type' => 'bit',
            ],
            // Multilingual field: one own content per language of the instance, with the
            // main language as fallback. Only for the types in Cms::$multilingualTypes
            // and not for fields on meta columns (hookOnSave() otherwise resets it).
            'multilingual' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_multilingual',
                'tooltip' => '#area.tooltip_field_multilingual',
            ],
            'details' => [
                'type' => 'json',
                'display_type' => 'none',
                'label' => '#area.field_field_details',
            ],
            // Lookup fields (relation/pointer): detailed preview, see
            // Klassenkommentar
            'details.lookup_detailed' => [
                'type' => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_lookup_detailed',
                'tooltip' => '#area.tooltip_field_lookup_detailed',
            ],
            // Field extension (developer docs, chapter 08 (Components and extensions)): name of an active extension that matches the
            // Display-Typ passt; ihre Optionen liegen in details.extension_options
            'details.extension' => [
                'type' => 'chars',
                'display_type' => 'select',
                'label' => '#area.field_field_extension',
                'tooltip' => '#area.tooltip_field_extension',
                'max' => 32,
            ],
            // Tabelle `rel_field_converter`
            'converter_ids' => [
                'type' => 'reltable',
                'display_type' => 'sortable',
                'label' => '#area.field_field_converter_ids',
            ],
            'details.media_types' => [
                'type' => 'json',
                'display_type' => 'checkbox',
                'label' => '#area.field_field_media_types',
            ],
            'details.media_types_incl' => [
                'type' => 'text',
                'display_type' => 'textarea',
                'label' => '#area.field_field_media_types_incl',
                'note' => '#converter.note_field_media_types',
                'tooltip' => '#converter.tooltip_field_media_types',
            ],
            'details.media_types_excl' => [
                'type' => 'text',
                'display_type' => 'textarea',
                'label' => '#area.field_field_media_types_excl',
                'note' => '#converter.note_field_media_types',
                'tooltip' => '#converter.tooltip_field_media_types',
            ],
            // Upload/thumbnail: files only for logged-in frontend users
            // (see class comment, MediaAccess::protectField())
            'details.user_auth' => [
                'type' => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_user_auth',
                'tooltip' => '#area.tooltip_field_user_auth',
            ],
            // Profile areas whose logged-in users may see the files
            // (only with user_auth = 1; empty = nobody)
            'details.profile_area_ids' => [
                'type' => 'json',
                'display_type' => 'checkbox',
                'label' => '#area.field_field_profile_area_ids',
                'tooltip' => '#area.tooltip_field_profile_area_ids',
            ],
            // Trash: Dataset::delete() only marks the field (see class comment
            // "TRASH"); changed_at/changed_by name the deletion operation
            'changed_at' => [
                'type'  => 'timestampl',
                'label' => '#area.field_area_changed_at',
            ],
            'changed_by' => [
                'type'  => 'id',
                'label' => '#area.field_area_changed_by',
                'table' => 'admin'
            ],
            'deleted' => [
                'type' => 'bit'
            ],
        ];

        // Reserved prefix at the start of a field name, depending on the type
        // ('reltable' has no data_* table and is not a selectable field type -
        //  its placeholder suffix does not belong in the prefix list)
        $this->fieldPrefixes = array_diff_key(
            $this->cms->dataSuffix,
            array_flip($this->cms->noColumnTypes)
        );
        // Special cases:
        $this->fieldPrefixes['pointer'] = 'ptr';
        $this->fieldPrefixes['pointer1'] = 'pn1';
        $this->fieldPrefixes['link'] = 'lnk';
        $this->fieldPrefixes['relation'] = 'rel';
        $this->fieldPrefixes['relation1'] = 'rn1';
    }

    /**
     * Before creating: place the new field at the end of the field list of its area.
     *
     * Unlike Area::hookOnCreate() (COALESCE(..., -1) + 1, starts at 0), here
     * COALESCE(..., 0) + 1 is counted, so the first field gets position = 1. For sorting
     * this does not matter, but it is confusing when comparing the two classes.
     */
    protected function hookOnCreate(array &$data): void
    {
        // Set the position of the new field to "highest position in the area + 1"
        $area_id = (int)$data['area_id'];
        $sql = "SELECT COALESCE(MAX(`position`), 0) + 1 FROM `field` WHERE `area_id` =?";
        $this->db->query($sql, 'i', [$area_id]);
        $data['position'] = $this->db->fetchNum()[0] ?? 0;
        // Do not preselect visibility for certain fields
        $name = $data['name'] ?? '';
        $type = $data['type'] ?? '';
        $displayType = $data['display_type'] ?? '';
        if ($displayType == 'thumbnail') {
            // Vorschaubild
            $data['visibility_search'] = 0;
            $data['visibility_input'] = 0;
        }
        if ($displayType == 'textarea') {
            // Mehrzeiliges Eingabefeld
            $data['visibility_list'] = 0;
        }
        if ($displayType == 'embed') {
            // Eingebettete Inhalte
            $data['visibility_list'] = 0;
        }
        if (in_array($name,['position','parent_id','indent'], true)) {
            // Sorting, parent record, indentation
            // is visualized in the list anyway
            $data['visibility_list'] = 0;
        }
        // Date field: preselect the calendar (not for meta fields)
        if (!isset($this->cms->schema[$name])) {
            // init() pre-fills details.extension with '' - this is not a choice here
            if (is_array($data['details'] ?? null) && (string)($data['details']['extension'] ?? '') === '') {
                unset($data['details']['extension']);
            }
            $this->presetExtension($data);
        }
    }

    /**
     * Pre-fill the extension of a NEW field: a date field (Cms::$dateTypes) with
     * display type 'text' gets the extension Calendar ("by field type",
     * minute step 1) - provided it is active and no extension (also no
     * empty = deliberately "none") was passed. Called by hookOnCreate()
     * (input mask) and FieldApi::create() (AI assistant).
     */
    public function presetExtension(array &$data): void
    {
        $type = (string)($data['type'] ?? '');
        $displayType = (string)($data['display_type'] ?? 'text');
        if ($displayType === '') $displayType = 'text';
        if (!in_array($type, $this->cms->dateTypes, true) || $displayType !== 'text') return;
        $details = $data['details'] ?? [];
        if (!is_array($details)) {
            // passed as JSON text: do not touch
            if ((string)$details !== '') return;
            $details = [];
        }
        if (array_key_exists('extension', $details)) return;
        $backend = $this->container->get(Backend::class);
        if (!isset($backend->extensionsFor($type, $displayType)['Calendar'])) return;
        $details['extension'] = 'Calendar';
        $details['extension_options'] = ['mode' => 'auto', 'step' => '1'];
        $data['details'] = $details;
    }

    /**
     * A file field switches between single and multilingual: convert the stock.
     *
     * The physical files are named, depending on the mode, {record_id}.{ext} or
     * {record_id}_{language_id}.{ext} (Cms::mediaFileName()), and `data_fil` records in
     * file_language_id which file a row refers to. Both must match the flag
     * of the field, otherwise frontend and backend would no longer find the files.
     *
     *     -> multilingual  the file becomes the file of the main language of the instance of
     *                       the record; the rows of the other languages point to it
     *                       (fallback)
     *     -> single-language  the file of the main language stays (renamed), files of
     *                       other languages are deleted; all rows get the metadata of
     *                       the main language and file_language_id NULL
     *
     * Runs in the transaction of Dataset::save(); the file operations themselves are
     * not transactional.
     *
     * @param int  $fieldId
     * @param bool $toMultilingual
     */
    private function migrateFileLanguage(int $fieldId, bool $toMultilingual): void
    {
        $mediaDir = $this->config->get('paths.media_dir') . '/' . $this->cms->mediaFolder($fieldId);
        $instanceCache = $this->container->get(\Camindo\Core\InstanceCache::class);

        $this->db->query(
            "SELECT DISTINCT `d`.`record_id`, `r`.`instance_id` FROM `data_fil` `d`"
            . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
            . " WHERE `d`.`field_id` = ?",
            'i', [$fieldId]
        );
        $records = $this->db->fetchAll();
        foreach ($records as $rec) {
            $recordId  = (int)$rec['record_id'];
            $primaryId = $instanceCache->primaryLanguageId((int)$rec['instance_id']);

            $this->db->query("SELECT * FROM `data_fil` WHERE `record_id` = ? AND `field_id` = ?", 'ii', [$recordId, $fieldId]);
            $rows = [];
            while ($row = $this->db->fetch()) $rows[(int)$row['language_id']] = $row;
            if (empty($rows)) continue;
            $primaryRow = $rows[$primaryId] ?? reset($rows);
            $extension  = (string)$primaryRow['extension'];

            if ($toMultilingual) {
                // {id}.{ext} -> {id}_{primary}.{ext}
                if ($extension !== '' && is_file("$mediaDir/" . $this->cms->mediaFileName($recordId, null, $extension))) {
                    @rename(
                        "$mediaDir/" . $this->cms->mediaFileName($recordId, null, $extension),
                        "$mediaDir/" . $this->cms->mediaFileName($recordId, $primaryId, $extension)
                    );
                }
                $this->db->query(
                    "UPDATE `data_fil` SET `file_language_id` = ? WHERE `record_id` = ? AND `field_id` = ?",
                    'iii', [$primaryId, $recordId, $fieldId]
                );
            }
            else {
                // Remove files of other languages; rename the ones of the main language
                foreach ($rows as $lang => $row) {
                    $fileLang = $this->cms->fileLanguageId($row);
                    if ($fileLang === null || $fileLang !== (int)$lang) continue; // Fallback-Zeile
                    $ext = (string)$row['extension'];
                    if ($ext === '') continue;
                    $path = "$mediaDir/" . $this->cms->mediaFileName($recordId, $fileLang, $ext);
                    if ($fileLang === $primaryId) {
                        if (is_file($path)) @rename($path, "$mediaDir/" . $this->cms->mediaFileName($recordId, null, $ext));
                    }
                    else {
                        @unlink($path);
                    }
                }
                $this->db->query(
                    "UPDATE `data_fil` SET `file_language_id` = NULL, `extension` = ?, `mime_type` = ?, `filename` = ?,"
                    . " `size` = ?, `width` = ?, `height` = ?, `data` = ?, `token` = ''"
                    . " WHERE `record_id` = ? AND `field_id` = ?",
                    'sssiiisii', [
                        $extension, (string)$primaryRow['mime_type'], (string)$primaryRow['filename'],
                        (int)$primaryRow['size'], (int)$primaryRow['width'], (int)$primaryRow['height'],
                        (string)($primaryRow['data'] ?? '{}'), $recordId, $fieldId
                    ]
                );
            }
        }
    }

    /**
     * After loading: supply the data that is not in `field`.
     *
     * Two field types have more targets than fit into one column; their data is stored in
     * edge tables and are placed here as pseudo fields in $data, so that the
     * form can treat them like normal fields:
     *
     *     pointer   -> data['rel_area_ids']   from `rel_field_area`
     *     fulltext  -> data['source_fields']  from `rel_fulltext_text`
     *     thumbnail -> data['converter_ids']  from `rel_field_converter` (by position)
     *
     * Database::fetchAll() returns a flat list of values for single-column queries,
     * so the assignments directly yield an id array.
     */
    protected function hookOnLoaded(array &$data): void
    {
        $fieldId = (int) ($data['field_id'] ?? 0);
        $type = $data['type'] ?? '';

        // pointer, link
        // --------------------
        if (in_array($type, $this->cms->multiAreaTypes, true)) {
            // The ids of the target areas come from the table `rel_field_area`
            $sql = "SELECT `area_id` FROM `rel_field_area` WHERE `field_id` =?";
            $this->db->query($sql, 'i', [$fieldId]);
            $data['rel_area_ids'] = $this->db->fetchAll();
        }

        // fulltext Felder
        // --------------------
        if ($type == 'fulltext') {
            // If it is a full-text field:
            // then the source field ids are stored in table `rel_fulltext_text`
            // The ids are provided in the pseudo field data['source_fields']
            // (via a link field: key "{rel_field_id}:{text_field_id}", sourceKey())
            $this->db->query("SELECT `rel_field_id`, `text_field_id` FROM `rel_fulltext_text` WHERE `fulltext_field_id`=?", 'i', [$fieldId]);
            $data['source_fields'] = array_map(
                fn(array $row) => self::sourceKey((int)$row['text_field_id'], (int)($row['rel_field_id'] ?? 0)),
                $this->db->fetchAll()
            );
        }

        // thumbnail
        // --------------------
        if ($type === 'file' && (string)($data['display_type'] ?? '') === 'thumbnail') {
            $data['converter_ids'] = $this->getConverterIds($fieldId);
        }
    }

    /**
     * Konverter eines Thumbnail-Feldes in ihrer Reihenfolge (`rel_field_converter`).
     *
     * @return array  converter_ids, sorted by position
     */
    public function getConverterIds(int $fieldId): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT `converter_id` FROM `rel_field_converter` WHERE `field_id` = ? ORDER BY `position` ASC, `converter_id` ASC", 'i', [$fieldId]);
        $ids = array_map('intval', $this->db->fetchAll()); // flaches Array
        $this->db->popResult();
        return $ids;
    }

    /**
     * Thumbnail fields that use a converter (`rel_field_converter`) -
     * for the tab "Used by" of the converter info.
     *
     * @return array  field_ids, by area and position
     */
    public function getFieldIdsByConverter(int $converterId): array
    {
        $this->db->pushResult();
        $this->db->query(
            "SELECT f.`field_id` FROM `rel_field_converter` r JOIN `field` f ON f.`field_id` = r.`field_id` AND f.`deleted` = 0
             WHERE r.`converter_id` = ? ORDER BY f.`area_id` ASC, f.`position` ASC",
            'i', [$converterId]
        );
        $ids = array_map('intval', $this->db->fetchAll()); // flaches Array
        $this->db->popResult();
        return $ids;
    }

    /**
     * Before saving: check and straighten out dependencies.
     *
     * Runs within the transaction of Dataset::save(). Every error is
     * registered as a field error and then thrown as an exception - this rolls
     * the transaction back and the backend shows the form again.
     *
     * In order:
     *
     *  1. remove rel_field_id from $fields - hookOnSaved() enters the counter-field id
     *     itself via UPDATE. If it stayed in the list, the form (which does not know the
     *     column) would overwrite it with 0 when saving again.
     *  2. type must be in Cms::$types.
     *  3. On name change: conflict with the global registration `fieldname`
     *     (name already assigned to another type) and check reserved prefixes.
     *  4. display_type 'thumbnail' creates its content itself -> no input.
     *  5. default_field is a state of exactly one field per area. If it is
     *     set, all other fields of the area lose the flag.
     *     `area.default_field_id` itself is updated by hookOnSaved() - there the
     *     `field_id` is fixed for a new field (the reverse direction is noted in
     *     Area::hookOnSaved()).
     *  6. Type-dependent forced values: max = 1 for the '1' types, custom_order only
     *     sensible with display_type 'lookup' and an n:m relation, min/max as count
     *     for array types, converter required for thumbnails.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        $schema = $this->schema;
        $fieldId  = (int)($data['field_id'] ?? 0);
        $areaId   = (int)($data['area_id'] ?? 0);
        $name     = (string)($data['name'] ?? '');
        $type     = (string)($data['type'] ?? '');
        $displayType = (string)($data['display_type'] ?? 'text');
        $previousName = (string)($previousData['name'] ?? '');

        // dest_relation_field
        // --------------------
        // prevent content from being lost when saving again
        // (counter-field id is entered manually in hookOnSaved)
        $fields = array_diff($fields, ['rel_field_id']);

        // 'reltable' is not a selectable field type: it is reserved for the schemas
        // defined firmly in the code (content in its own relation table) and has
        // no data_* table in which a virtual field could end up.
        $selectableTypes = array_diff($this->cms->types, $this->cms->noColumnTypes);
        if (in_array('type', $fields, true) && !in_array($type, $selectableTypes, true)) {
            // Field 'type' is saved but not known
            $type = preg_replace('/[^a-z0-9_]/','', $type);
            $this->createFieldError('area.error_field_unknown_type', 'type', $schema['type']['label'] ?? '', $type);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // fulltext: only sources of the own area or via one of its link fields
        // (fulltextSourceOptions()) - a field of a foreign area without a path there
        // would write the full text to foreign records
        if ($type === 'fulltext' && array_key_exists('source_fields', $data)) {
            $allowed = $this->fulltextSourceOptions($areaId ?: (int)($previousData['area_id'] ?? 0));
            $keys = [];
            foreach ((array)$data['source_fields'] as $key) {
                [$textFieldId, $relFieldId] = self::parseSourceKey($key);
                $key = (string)self::sourceKey($textFieldId, $relFieldId);
                if (!isset($allowed[$key])) {
                    $this->createFieldError('area.error_fulltext_source', 'source_fields', $this->cms->snippet('area.field_field_source_fields'), $key);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                $keys[$key] = self::sourceKey($textFieldId, $relFieldId);
            }
            $data['source_fields'] = array_values($keys);
        }

        // name - change / new entry
        // --------------------
        $nameChanged = in_array('name', $fields, true) && $name !== $previousName;
        // Standard full-text field: name reserved (see class comment)
        if ($nameChanged && $name === self::FULLTEXT) {
            $this->createFieldError('area.error_field_name_reserved', 'name', $schema['name']['label'] ?? '', $name);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        if ($nameChanged) {
            // Check whether the name is already used for a field of another type
            $sql ="SELECT `type` FROM `fieldname` WHERE `name`=? AND `type` != ? ";
            $this->db->query($sql, 'ss', [$name, $type]);
            if ($this->db->num_rows > 0) {
                // Yes, name reserved for another field type -> error message
                $wrongType = $this->db->fetchNum()[0];
                $this->createFieldError('area.error_field_name_wrong_type', 'name', $schema['name']['label'] ?? '', $wrongType);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            // A field in the trash keeps its name in the area: its data_* rows
            // carry (record_id, fieldname_id) - a new field of the same name would
            // collide with them, and the restore would have two fields of one name
            $this->db->query(
                "SELECT 1 FROM `field` WHERE `area_id` = ? AND `name` = ? AND `deleted` = 1 AND `field_id` != ? LIMIT 1",
                'isi', [$areaId ?: (int)($previousData['area_id'] ?? 0), $name, $fieldId]
            );
            if ($this->db->num_rows > 0) {
                $this->createFieldError('area.error_field_name_in_trash', 'name', $schema['name']['label'] ?? '', $name);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            // Check reserved prefixes
            foreach ($this->fieldPrefixes as $long => $short) {
                $prefix = $short . '_';
                if (str_starts_with($name, $prefix) && $type != $long) {
                    // Prefix is reserved for another type
                    $this->createFieldError('error_field_reserved_prefix', 'name', $schema['name']['label'] ?? '', $prefix);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }
        }

        // visibility
        // --------------------
        // Thumbnail does not allow input
        if ($data['display_type'] == 'thumbnail') {
            $data['visibility_input'] = 0;
        }

        // multilingual
        // --------------------
        // Only for types with a language-dependent data_* table and never for fields on
        // meta columns of `record` - the columns themselves know no language.
        // For an EXISTING field the flag is no longer written here:
        // the change is only possible via convert() (see class comment); the mask
        // only displays it. Thumbnails are excluded - they inherit it below.
        if ($fieldId > 0) {
            $fields = array_values(array_diff($fields, ['multilingual']));
        }
        if (in_array('multilingual', $fields, true)) {
            $data['multilingual'] = (int)!empty($data['multilingual']);
            if (isset($this->cms->schema[$name])
                || !($type === 'file' || in_array($type, $this->cms->multilingualTypes, true))
            ) {
                $data['multilingual'] = 0;
            }
        }
        // Thumbnails inherit the flag from their upload field: they are created from
        // its file, one per language (see VirtualFieldsTrait::
        // writeThumbnails()). hookOnSaved() updates the children when the
        // upload field changes.
        if ($type === 'file' && $displayType === 'thumbnail') {
            $parentFieldId = (int)($data['parent_field_id'] ?? 0);
            $data['multilingual'] = 0;
            if ($parentFieldId > 0) {
                $this->db->query("SELECT `multilingual` FROM `field` WHERE `field_id` = ?", 'i', [$parentFieldId]);
                $data['multilingual'] = (int)($this->db->fetchNum()[0] ?? 0);
            }
            if (!in_array('multilingual', $fields, true)) $fields[] = 'multilingual';
        }

        // default_field - change / new entry
        // --------------------
        $isDefaultField = (int)($data['default_field'] ?? 0);
        $wasDefaultField = (int)($previousData['default_field'] ?? 0);
        if ($isDefaultField !== $wasDefaultField        // Default field status changes
            && in_array('default_field', $fields, true) // Field is written
        ) {
            if (!empty($isDefaultField)) {
                // neuer Status: true
                // The default field is always visible in the record list
                $data['visibility_list'] = 1;

                // No other field of the area may remain the default field
                $this->db->query("UPDATE `field` SET `default_field` = 0 WHERE `area_id` = ?", 'i', [$areaId]);
            }

            // `area.default_field_id` is only written in hookOnSaved():
            // For a new field the `field_id` is not yet fixed here (the
            // INSERT only follows after this hook), so the foreign key
            // area.default_field_id -> field.field_id would therefore fail.
            $data['__set_default_field'] = !empty($isDefaultField);
        }

        // height: (auto) becomes 0
        $data['height'] = (int)$data['height'];

        // relation
        // --------------------
        if (str_starts_with($type, 'relation')) {
            // Target area must be selected
            if (empty($data['rel_area_id'])) {
                $this->createFieldError('error_value_mandatory', 'rel_area_id', $schema['rel_area_id']['label']);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            // Type relation does not allow the own content area as target area
            if ((int)$data['rel_area_id'] === $areaId) {
                $this->createFieldError('area.error_field_rel_area_id', 'rel_area_id', $schema['rel_area_id']['label'] ?? '', $type);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            // Check whether the target area exists
            $sql = "SELECT 1 FROM `area` WHERE `area_id`=? AND `deleted`=0";
            $this->db->query($sql, 'i', [(int)$data['rel_area_id']]);
            if ($this->db->num_rows == 0) {
                $this->createFieldError('area.error_rel_area_not_found', 'rel_area_id', $schema['rel_area_id']['label'] ?? '');
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            if ($type == 'relation1') {
                $data['max'] = 1;
            }
            // custom_order | only for type 'lookup' and 1:n relation
            if ($displayType !== 'lookup' || str_ends_with($type, '1')) {
                $data['custom_order'] = 0;
            }
        }

        // pointer
        // --------------------
        if (str_starts_with($type, 'pointer')) {
            // In typify the selected ids of the target areas were already converted into a string,
            // (char-Feld laut Schema)
            // This string is processed in hookOnSaved
            $relAreaIds = $this->system->sanitizeIds((array)($data['rel_area_ids'] ?? []), removeZero:true);
            $data['__rel_area_ids'] = $relAreaIds;
            // nothing ends up in the char field itself
            $data['rel_area_ids'] = '';

            // Validation (only for type pointer)
            if (empty($relAreaIds)) {
                $label = $schema['rel_area_ids']['label'] ?? 'rel_area_ids';
                $this->createFieldError('error_value_mandatory', 'rel_area_ids', $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            if ($type == 'pointer1') {
                $data['max'] = 1;
            }

            // custom_order | only for type 'lookup' and 1:n relation
            if ($displayType !== 'lookup' || str_ends_with($type, '1')) {
                $data['custom_order'] = 0;
            }
        }

        // link - firmly linked content (1:n), see class comment
        // --------------------
        if ($type === 'link') {
            // target areas as with pointer (rel_field_area, hookOnSaved()) - but only
            // dependent record areas (area.dependent = 1)
            $relAreaIds = $this->system->sanitizeIds((array)($data['rel_area_ids'] ?? []), removeZero:true);
            $data['rel_area_ids'] = '';
            $label = $schema['rel_area_ids']['label'] ?? 'rel_area_ids';
            if (empty($relAreaIds)) {
                $this->createFieldError('error_value_mandatory', 'rel_area_ids', $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $placeholders = implode(',', array_fill(0, count($relAreaIds), '?'));
            $this->db->query(
                "SELECT `area_id` FROM `area` WHERE `deleted` = 0 AND `dependent` = 1"
                . " AND `type` IN ('record','profile','ugc') AND `area_id` IN ($placeholders)",
                str_repeat('i', count($relAreaIds)), $relAreaIds
            );
            $dependentIds = array_map('intval', $this->db->fetchColumn());
            if (count($dependentIds) !== count($relAreaIds)) {
                $this->createFieldError('area.error_field_rel_area_dependent', 'rel_area_ids', $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $data['__rel_area_ids'] = $relAreaIds;

            // Display: search window or embedded input masks
            if (!in_array($displayType, ['lookup', 'embed'], true)) {
                $data['display_type'] = $displayType = 'lookup';
            }
            // The order of the target records is always manual (data_rel.position),
            // there is no maximum - the field holds any number of contents
            $data['custom_order'] = 1;
            $data['max'] = '';
        }

        // Array types: min/max count elements, not characters
        // --------------------
        // Therefore min and the flag 'not_empty' belong together and are kept here in
        // sync in both directions: not_empty enforces min >= 1, and a
        // min > 0 conversely enforces the flag. Otherwise the validation could let through an
        // empty field that according to min needs at least one element.
        if (in_array($type, $this->cms->arrayTypes, true)) {
            // "Count" is handled in min / max
            // -> not_empty can be merged into min
            if ($data['min'] !== '') $data['min'] = (int)$data['min'];
            if ($data['max'] !== '') $data['max'] = (int)$data['max'];

            // min cannot remain 0 if flag 'not_empty' is set
            $flags = (array)($data['flags'] ?? []);
            if (in_array('not_empty', $flags)) {
               $data['min'] = max($data['min'], 1);
            }

            // conversely: if min > 0, 'not_empty' must be set
            if ($data['min'] > 0) {
                $data['flags'] = array_diff($flags, ['not_empty']);
                $data['flags'][] = 'not_empty';
            }
        }

        // Extension (developer docs, chapter 08 (Components and extensions)): must be active and match the field and display type;
        // without extension its options are dropped as well
        if (isset($data['details']) && is_array($data['details'])) {
            $extension = trim((string)($data['details']['extension'] ?? ''));
            if ($extension !== '') {
                $backend = $this->container->get(Backend::class);
                if (!isset($backend->extensionsFor($type, $displayType)[$extension])) {
                    $this->createFieldError('area.error_extension_invalid', 'details.extension',
                        $schema['details.extension']['label'] ?? '', $extension);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                $data['details']['extension'] = $extension;
                $options = (array)($data['details']['extension_options'] ?? []);
                $data['details']['extension_options'] = array_map(
                    fn($v) => is_array($v) ? array_map('strval', $v) : (string)$v, $options);
            }
            else {
                unset($data['details']['extension'], $data['details']['extension_options']);
            }
        }

        if (in_array($type, $this->cms->relationTypes, true)) {
            // Target areas only from the own instance and its readable instances
            // (targetInstanceIds()) - only NEWLY added targets are checked:
            // an existing target outside stays valid until it is deselected
            $newTargetIds = in_array($type, $this->cms->multiAreaTypes, true)
                ? (array)($data['__rel_area_ids'] ?? [])
                : [(int)$data['rel_area_id']];
            // (existing targets: hookOnLoaded() stores rel_area_ids from `rel_field_area`)
            $previousTargetIds = in_array($type, $this->cms->multiAreaTypes, true)
                ? array_map('intval', (array)($previousData['rel_area_ids'] ?? []))
                : [(int)($previousData['rel_area_id'] ?? 0)];
            $addedIds = array_values(array_diff(array_map('intval', $newTargetIds), $previousTargetIds));
            if (!empty($addedIds)) {
                $instanceIds = $this->targetInstanceIds($areaId);
                $placeholders = implode(',', array_fill(0, count($addedIds), '?'));
                $this->db->query(
                    "SELECT `area_id` FROM `area` WHERE `area_id` IN ($placeholders) AND `instance_id` IN ("
                    . implode(',', $instanceIds) . ")",
                    str_repeat('i', count($addedIds)), $addedIds
                );
                if (count($this->db->fetchColumn()) !== count($addedIds)) {
                    $name = in_array($type, $this->cms->multiAreaTypes, true) ? 'rel_area_ids' : 'rel_area_id';
                    $this->createFieldError('area.error_field_rel_area_instance', $name, $schema[$name]['label'] ?? $name);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }

            // If the chosen display type can only create one link,
            // set 'max' to 1 - this helps in TemporaryTrait::_copyRelationsBack()
            if (in_array($displayType, ['radio', 'select'])) {
                $data['max'] = '1';
            }
            // detailed lookup preview: 0/1 (a switched-off switch is not
            // sent in the POST), only relevant for display_type 'lookup'
            if (isset($data['details']) && is_array($data['details'])) {
                $data['details']['lookup_detailed'] = (!empty($data['details']['lookup_detailed']) && $displayType === 'lookup') ? 1 : 0;
            }

            // If min or max is 0, save '' = ignore the limit
            if (empty($data['min'])) $data['min'] = '';
            if (empty($data['max'])) $data['max'] = '';
        }

        // thumbnail: converter-selection
        if ($type === 'file') {
            if ($displayType === 'thumbnail') {
                // Input order (sortable) is kept, duplicates and foreign entries are dropped
                $data['converter_ids'] = $this->system->sanitizeIds((array)($data['converter_ids'] ?? []), true);
                if (empty($data['converter_ids'])) {
                    $label = $this->schema['converter_ids']['label'] ?? 'converter_ids';
                    $this->createFieldError('error_value_mandatory', 'converter_ids', $label);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                // (older data: the ids were in details - do not keep them there anymore)
                if (isset($data['details']) && is_array($data['details'])) unset($data['details']['converter_ids']);
            }
            // user_auth: profile areas only with the switch set, and only
            // existing areas of type 'profile'
            if (isset($data['details']) && is_array($data['details'])) {
                $data['details']['user_auth'] = empty($data['details']['user_auth']) ? 0 : 1;
                $ids = $this->system->sanitizeIds((array)($data['details']['profile_area_ids'] ?? []), true);
                if (!empty($ids) && $data['details']['user_auth'] === 1) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $this->db->query(
                        "SELECT `area_id` FROM `area` WHERE `type` = 'profile' AND `deleted` = 0 AND `area_id` IN ($placeholders)",
                        str_repeat('i', count($ids)),
                        $ids
                    );
                    $ids = array_map('intval', $this->db->fetchColumn());
                }
                elseif ($data['details']['user_auth'] !== 1) {
                    $ids = [];
                }
                $data['details']['profile_area_ids'] = array_values($ids);
            }
        }

        // name == slug
        if ($name === 'slug') {
            // The slug is a special field that is used as the URI of the records
            // Therefore 'unique' (across the content areas) is mandatory - for the slug
            // it means: unique URL path [slug prefix of the area/]slug, see
            // Record::slugPathOk()
            $flags = (array)($data['flags'] ?? []);
            $data['flags'] = array_diff($flags, ['unique', 'unique_in_area']);
            $data['flags'][] = 'unique';
        }
    }

    /**
     * After saving: update the side tables.
     *
     * Only here is the `field_id` fixed for a new field - everything that needs it
     * therefore belongs in this hook and not in hookOnSave(). Also within the
     * transaction.
     *
     *     area               set `default_field_id` to this field or clear it
     *     fieldname          create the name entry or clean up the old one
     *     field (counterpart) for 'relation', create the counter field in the target area
     *     rel_field_area     for 'pointer', rewrite the target areas
     *     rel_fulltext_text  for 'fulltext', rewrite the source fields
     *     rel_field_converter  for thumbnails, rewrite the converters (with position)
     *
     * The pattern "first DELETE, then INSERT the whole list" is intentional: it saves the
     * old/new comparison and is safe within the transaction.
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {   
        $fieldId = (int)($data['field_id'] ?? 0);
        $areaId  = (int)($data['area_id'] ?? $previousData['area_id'] ?? 0);
        if (empty($fieldId)) throw new \Exception('hookOnSaved(): field_id is empty');
        if (empty($areaId)) throw new \Exception('hookOnSaved(): area_id is empty');
        
        $isNew   = (bool)($data['new'] ?? false);
        $name = (string)($data['name'] ?? '');
        $previousName = $previousData['name'] ?? '';
        $type    = (string)($data['type'] ?? 'text');
        $previousType = $previousData['type'] ?? 'text';

        // default_field
        // --------------------
        // Addendum to hookOnSave(): only here is the `field_id` known
        if (array_key_exists('__set_default_field', $data)) {
            if ($data['__set_default_field']) {
                // Default-Feld in Inhaltsbereich eintragen
                $this->db->query("UPDATE `area` SET `default_field_id` = ? WHERE `area_id` = ?", 'ii', [$fieldId, $areaId]);
            }
            else {
                // Remove default field from the content area
                $this->db->query("UPDATE `area` SET `default_field_id` = NULL WHERE `area_id` = ?", 'i', [$areaId]);
            }
        }

        // multilingual
        // --------------------
        // (Switching an existing field between single and multilingual no longer
        //  runs via save(), but via convert() -> applyMultilingualChange();
        //  hookOnSave() takes the flag out of $fields.)

        // name
        // --------------------
        $nameChanged = !$isNew && in_array('name', $fields, true) && $name !== $previousName;
        if ($isNew || $nameChanged) {
            // Entry in fieldname (if not already present)
            // On wrong type: the return value is the type in use
            if ($wrongType = $this->createFieldname($name, $type)) {
                // Name-Typ-Konflikt
                $this->createFieldError('area.error_field_name_wrong_type', 'name', $this->schema['name']['label'] ?? '', (string)$wrongType);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            // Look up the id, not insert_id: if the name already existed (another
            // field of the same type carries it), createFieldname() inserted nothing -
            // insert_id would be 0 and the data would lose its name on renaming
            $this->db->query("SELECT `fieldname_id` FROM `fieldname` WHERE `name` = ?", 's', [$name]);
            $fieldnameId = (int)($this->db->fetchNum()[0] ?? 0);
            if ($fieldnameId < 1) {
                throw new \Exception("Internal error: fieldname '$name' not found.");
            }

            if ($nameChanged) {
                // Rows in the data table still store the old fieldname_id,
                // now adjust them
                $previousDataSuffix = $this->cms->dataSuffix[$previousData['type']] ?? null;
                if (!$previousDataSuffix) {
                    throw new \Exception('Internal error: previousData[type] is undefined.');
                }
                $sql = "UPDATE `data_{$previousDataSuffix}` SET `fieldname_id` = ? WHERE `field_id` = ?";
                $this->db->query($sql, 'ii', [$fieldnameId, $fieldId]);

                // Check whether the entry in `fieldname` can be deleted
                $this->cleanupFieldname($previousName);
            }
        }

        // type change
        // --------------------
        // Only via convert() - the input mask keeps the type fixed (formStatic)
        $typeChanged = !$isNew && in_array('type', $fields, true) && $type !== $previousType;
        if ($typeChanged) {
            throw new \Exception("Internal Error: type changed");
        }
        /*
            ENTWURF
            // Type change is only allowed to a limited extent in the input mask.
            // In this case it is ensured that data can be copied 1:1 from one data_ table to another.
            // Other type changes are made via special conversion tools. (PLANNED)
            if ($typeChanged) {
                // fieldname_id has already been adjusted in the old table
                $dataSuffix = $this->cms->dataSuffix[$data['type']] ?? null;
                $previousDataSuffix = $this->cms->dataSuffix[$previousData['type']] ?? null;
                if (!empty($dataSuffix) && !empty($previousDataSuffix)
                    && $dataSuffix != $previousDataSuffix
                ) {
                    $this->db->query("INSERT INTO `data_{$dataSuffix}` SELECT * FROM `data_{$previousDataSuffix}` WHERE `field_id` = ?", 'i', [$fieldId]);
                    $this->db->query("DELETE FROM `data_{$previousDataSuffix}` WHERE `field_id` = ?", 'i', [$fieldId]);
                }
            }
        */

        // type == relation
        // --------------------
        if (str_starts_with($type, 'relation')) {
            $relAreaId = (int)$data['rel_area_id'];
            if (empty($relAreaId)) {
                throw new \Exception("hookOnSave(): rel_area_id is empty!");
            }
            $flagCreateNewField = false;
            if (!$isNew) {
                $previousRelAreaId = (int)$previousData['rel_area_id'];

                // Was the target area changed?
                if ($relAreaId != $previousRelAreaId) {
                    // Delete old target field
                    // (also deletes all saved links in it).
                    // FIRST release its own reference to it: fk_field_rel_field
                    // is ON DELETE CASCADE - with rel_field_id pointing to the old
                    // counter field, its DELETE would take this field along
                    $relFieldId = (int)$previousData['rel_field_id'];
                    $this->db->query("UPDATE `field` SET `rel_field_id` = NULL WHERE `field_id` = ?", 'i', [$fieldId]);
                    if ($relFieldId > 0) {
                        $sql = "DELETE FROM `field` WHERE `field_id` =?";
                        $this->db->query($sql, 'i', [$relFieldId]);
                    }
                    
                    $flagCreateNewField = true;
                }
            }

            // Create new target relation field (counter field) in the target area
            // --------------------
            // The counter field makes the link visible from the other side. It is
            // deliberately created with a direct INSERT and not via save(): that
            // would recurse into this hook and create a counter field for the counter field.
            // It stays invisible (visibility_* stay 0, because they do not occur in the
            // INSERT at all and the column defaults apply).
            if ($isNew || $flagCreateNewField) {
                // Area name of the source field becomes the name of the target field
                // Area label of the source field becomes the label of the target field
                $sql = "SELECT `name`, `label` FROM `area` WHERE `area_id` =? AND `deleted`=0";
                $this->db->query($sql, 'i', [$areaId]);
                if ($this->db->num_rows < 1) {
                    throw new \Exception("hookOnSave(): area with area_id=$areaId not found!");
                }
                $areaData = $this->db->fetch();
                $areaLabel = $areaData['label'];
                $areaName = $areaData['name'];
                // Determine a free name with prefix "rel_{$areaName}" in the target area
                $relFieldName = $this->generateUniqueFieldName('rel_' . $areaName, $relAreaId);

                $sql = "SELECT COALESCE(MAX(`position`), 0) + 1 FROM `field` WHERE `area_id` =?";
                $this->db->query($sql, 'i', [$relAreaId]);
                $position = $this->db->fetchNum()[0];

                // Fieldname-Eintrag erstellen
                // (return value does not matter, because prefix 'rel_' is reserved for type 'relation')
                $this->createFieldname($name, 'relation');

                // Create data for the target field
                $relFieldData = [
                    'area_id' => $relAreaId,    // Zielbereich
                    'rel_area_id' => $areaId,   // Quellbereich
                    'rel_field_id' => $fieldId, // Quellfeld
                    'position' => $position,
                    'name' => $relFieldName,
                    'label' => $areaLabel,
                    'type' => 'relation',
                    'display_type' => 'lookup',
                    // visibility: 0,0,0
                ];

                // Build and send the query
                $columns = implode("`,`", array_keys($relFieldData));
                $placeholders = implode(',', array_fill(0, count($relFieldData), '?'));
                $types = 'iiiissss';
                $values = array_values($relFieldData);
                $sql = "INSERT INTO `field` (`$columns`) VALUES ($placeholders)";
                if (!$this->db->query($sql, $types, $values)) {
                    throw new \Exception("hookOnSave(): destination field could not be created!");
                }

                // Enter / update the id of the target field in the source field
                $relFieldId = (int)$this->db->insert_id;
                $sql = "UPDATE `field` SET `rel_field_id`=? WHERE `field_id`=? ";
                $this->db->query($sql, 'ii', [$relFieldId, $fieldId]
                );

                // Tabelle fieldname aktualisieren
                $this->createFieldname($relFieldName, 'relation');
            }
        }

        // pointer, link
        // --------------------
        if (in_array($type, $this->cms->multiAreaTypes, true)) {
            $data['rel_area_ids'] = (array)$data['__rel_area_ids']; // see hookOnSave
            // link: the text fields of the target areas flow into the standard full text
            if ($type === 'link' && !$isNew) {
                $old = array_map('intval', (array)($previousData['rel_area_ids'] ?? []));
                $new = array_map('intval', $data['rel_area_ids']);
                sort($old);
                sort($new);
                if ($old !== $new) $this->fulltextState()->markStale([$areaId]);
            }
            // Tabelle `rel_field_area` aktualisieren
            $sql = "DELETE FROM `rel_field_area` WHERE `field_id`=?";
            $this->db->query($sql, 'i', [$fieldId]);
            foreach ($data['rel_area_ids'] as $destAreaId) {
                // foreign key ensures that area_id exists in table area
                $sql = "INSERT INTO `rel_field_area` SET `field_id`=?, `area_id`=?";
                $this->db->query($sql, 'ii', [$fieldId, $destAreaId]);
            }
        }

        // 'Maximum' verringert
        // relation, pointer, file
        // --------------------
        $max = (int)($data['max'] ?? 0);
        $previousMax = (int)($previousData['max'] ?? 0);
        if (
            !$isNew && $max < $previousMax 
            && in_array($type, $this->cms->relationTypes, true)
        ) {
            // Maximum selection is reduced
            // -> check links via this field
            // remove links beyond the new maximum (sorted by the set order)
            // ...
        }

        // fulltext
        // --------------------
        if ($type == 'fulltext') {
            // For a full-text field the ids of the source fields are stored in table `rel_fulltext_text`
            // The ids were collected in the pseudo field data['source_fields']
            // key see sourceKey(): via a link field with `rel_field_id`
            $keys = array_map('strval', (array)($data['source_fields'] ?? []));
            $this->db->query("SELECT `rel_field_id`, `text_field_id` FROM `rel_fulltext_text` WHERE `fulltext_field_id`=?", 'i', [$fieldId]);
            $previousKeys = array_map(fn(array $row) => (string)self::sourceKey((int)$row['text_field_id'], (int)($row['rel_field_id'] ?? 0)), $this->db->fetchAll());
            $this->db->query("DELETE FROM `rel_fulltext_text` WHERE `fulltext_field_id`=?", 'i', [$fieldId]);
            foreach ($keys as $key) {
                [$textFieldId, $relFieldId] = self::parseSourceKey($key);
                $this->db->query(
                    "INSERT INTO `rel_fulltext_text` (`fulltext_field_id`, `rel_field_id`, `text_field_id`) VALUES (?, ?, ?)",
                    'iii', [$fieldId, $relFieldId > 0 ? $relFieldId : null, $textFieldId]
                );
            }
            // New full-text field or other sources: the stock no longer matches -
            // do not rebuild immediately, only mark it (FulltextState)
            sort($keys);
            sort($previousKeys);
            if ($isNew || $keys !== $previousKeys) {
                $this->fulltextState()->markStale([$areaId]);
            }
        }

        // thumbnail: Konverter
        // --------------------
        // Only if the field was passed - a save() with a field list without
        // 'converter_ids' leaves the assignment as it is. `position` is the order
        // of the input (display_type 'sortable'); hookOnSave() has cleaned up the ids.
        $displayType = (string)($data['display_type'] ?? $previousData['display_type'] ?? '');
        if ($type === 'file' && $displayType === 'thumbnail' && array_key_exists('converter_ids', $data)) {
            $ids = $this->system->sanitizeIds((array)$data['converter_ids'], true);
            $this->db->query("DELETE FROM `rel_field_converter` WHERE `field_id` = ?", 'i', [$fieldId]);
            if (!empty($ids)) {
                $placeholders = implode(', ', array_fill(0, count($ids), '(?, ?, ?)'));
                $sql = "INSERT INTO `rel_field_converter` (`field_id`, `converter_id`, `position`) VALUES $placeholders";
                $bindTypes = '';
                $values = [];
                foreach (array_values($ids) as $position => $converterId) {
                    $bindTypes .= 'iii';
                    $values[] = $fieldId;
                    $values[] = (int)$converterId;
                    $values[] = $position + 1;
                }
                $this->db->query($sql, $bindTypes, $values);
            }
        }

        // Field 'position'
        // --------------------
        // Automatically switch the default sorting of the area to 'position ASC'
        if ($name === 'position') {
            // Default-Feld in Inhaltsbereich eintragen
            $this->db->query("UPDATE `area` SET `sort_field_id` = ?, `sort_order` = 'ASC' WHERE `area_id` = ?", 'ii', [$fieldId, $areaId]);
            // Without a `position` field the area maintains no order - the
            // existing records then all carry the same value. When the field is created
            // they are therefore numbered once, otherwise the first displayed order
            // would be random.
            if ($isNew || $nameChanged) {
                $this->recordDataset()->normalizePositions($areaId);
            }
        }

        // Field 'parent_id' - the area gets (or loses) its tree
        // --------------------
        // An area has a tree exactly when it has an input field
        // `parent_id` (see TreeManagerTrait). When it is created, all
        // existing records are initially root nodes and get
        // lft/rgt/depth/parent_ids for the first time; when it is renamed away, the area is flat again
        // and the tree fields are reset (as when the field is deleted,
        // see hookOnDeleted()).
        $wasParentField = ($previousName === 'parent_id');
        $isParentField  = ($name === 'parent_id');
        if ($isParentField && ($isNew || $nameChanged)) {
            $this->recordDataset()->initTreeArea($areaId);
        }
        elseif ($wasParentField && $nameChanged) {
            $this->recordDataset()->resetTreeFields($areaId);
        }

        // File fields: field lock in the media directory (details.user_auth)
        // --------------------
        // The .htaccess in the directory media/{instance_id}/{field_id} is reconciled
        // with the switch on every save - also for a new field,
        // so that the directory including the lock already exists before the first upload.
        // ($data['details'] is already JSON-encoded here - Dataset::save()
        //  has converted the column for writing)
        if ($type === 'file' && in_array('details', $fields, true)) {
            $details = is_array($data['details'] ?? null)
                ? $data['details']
                : (json_decode((string)($data['details'] ?? ''), true) ?: []);
            $this->container->get(MediaAccess::class)->protectField(
                $fieldId,
                !empty($details['user_auth'])
            );
        }
    }

    /**
     * Before deleting.
     *
     * The database handles almost everything via foreign keys: the `data_*` rows hang
     * with ON DELETE CASCADE to `field`, likewise the counter field of a relation and the
     * entries in `rel_field_area` / `rel_fulltext_text` / `rel_field_converter`.
     * `area.default_field_id` is cleared via constraint.
     *
     * What remains is the entry in the global name registry `fieldname`.
     */
    protected function hookOnDelete(array $data): void
    {
        $fieldId = (int)($data['field_id'] ?? 0);
        if (empty($fieldId)) {
            // (This case never occurs, because Dataset::load() succeeded)
            throw new \Exception("hookOnDelete(): data[field_id] is empty!");
        }

        // Areas whose full text has the field as source - after deleting, mark them as
        // stale (the rows in `rel_fulltext_text` disappear via
        // foreign key, also those via a deleted link field)
        $type = (string)($data['type'] ?? '');
        $this->fulltextOfDeleted = [];
        if (in_array($type, self::FULLTEXT_SOURCE_TYPES, true) && (string)($data['display_type'] ?? '') !== 'thumbnail') {
            $this->fulltextOfDeleted = $this->fulltextState()->areasUsingField($fieldId);
        }
        elseif (in_array($type, ['link', 'relation', 'relation1'], true)) {
            $this->fulltextOfDeleted = [(int)($data['area_id'] ?? 0)];
        }

        // (`default_field_id` in table `area` is removed via constraint)
        // (the entry in `fieldname` is checked in hookOnDeleted() - as long as the
        //  `field` row still exists, it would hold on to its own name)
    }

    /**
     * After deleting.
     *
     * Only here is the `field` row really gone - both points below need this:
     *
     *     fieldname   the name entry may only be dropped if no field uses it anymore
     *                 used. As long as its own row still existed, the check would
     *                 always be negative (this was the TODO in hookOnDelete()).
     *     parent_id   if it was THE tree field of the area, the area no longer has a tree from now on:
     *                 all its records are flattened
     *                 (parent_id = NULL, lft/rgt/depth = 0, parent_ids = []). Otherwise
     *                 references and nested-set boundaries would remain that nobody
     *                 maintains anymore, but which would still be filtered by.
     *                 The display order of the tree is kept as `position`.
     */
    protected function hookOnDeleted(array $data): void
    {
        $name   = (string)($data['name']    ?? '');
        $areaId = (int)   ($data['area_id'] ?? 0);

        // Check whether the old name in `fieldname` can be removed
        $this->cleanupFieldname($name);

        // tree field removed -> area is flat again
        if ($name === 'parent_id' && $areaId > 0) {
            $this->recordDataset()->resetTreeFields($areaId);
        }

        // the full text still contains the content of the deleted field: mark as stale
        // markieren statt sofort neu aufzubauen (FulltextState)
        $this->fulltextState()->markStale($this->fulltextOfDeleted);
        $this->fulltextOfDeleted = [];

        // a field from the trash: the file cache lists it (InstanceCache::deletedFieldIds())
        if (!empty($data['deleted'])) $this->queueCacheRebuild();
    }

    // Trash (soft delete) - see class comment "TRASH"
    // ====================

    /**
     * load() for the backend masks: a field in the trash counts as not found
     * (message area.error_field_in_trash) - it is only restored via the trash.
     */
    public function loadLive(int $fieldId): ?array
    {
        $data = $this->load($fieldId);
        if ($data && !empty($data['deleted'])) {
            $this->cms->error($this->cms->snippet('area.error_field_in_trash'));
            return null;
        }
        return $data;
    }

    /**
     * Before the soft deletion.
     *
     * The `data_*` rows and the entries in the relation tables stay - only the
     * definition disappears from all lists, masks and queries (`deleted` = 1).
     * Done here:
     *
     *     cascade      counter field of a relation (rel_field_id) and thumbnail
     *                  fields (parent_field_id) with the same timestamp - they would
     *                  disappear with the field on the final deletion anyway (FK)
     *     area refs    default/sort/login field of the area are cleared and noted
     *                  in details.trash_area_refs - hookOnRestore() sets them back
     *     media        the media directory is locked (MediaAccess), the files stay
     *     caches       record_cache of the area (rebuilt on the next access),
     *                  full text of the areas using the field marked as stale
     */
    protected function hookOnSoftDelete(array $data): void
    {
        $fieldId = (int)$data['field_id'];
        $areaId  = (int)$data['area_id'];

        // Counter field and thumbnail fields: same operation (timestamp).
        // The counter field points back - $trashCascade stops the recursion.
        $this->trashCascade[$fieldId] = true;
        try {
            foreach ($this->trashDependentIds($fieldId, (int)($data['rel_field_id'] ?? 0), 'delete') as $id) {
                if (!isset($this->trashCascade[$id])) $this->softDeleteOne($id);
            }
        }
        finally {
            unset($this->trashCascade[$fieldId]);
        }

        // Area references (fk_area_* would only clear them on the final deletion)
        $refs = [];
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `default_field_id`, `sort_field_id`, `login_name_field_id`, `login_password_field_id`"
                . " FROM `area` WHERE `area_id` = ?", 'i', [$areaId]
            );
            foreach ((array)$this->db->fetch() as $column => $value) {
                if ((int)$value === $fieldId) $refs[] = $column;
            }
            foreach ($refs as $column) {
                $this->db->query("UPDATE `area` SET `$column` = NULL WHERE `area_id` = ?", 'i', [$areaId]);
            }
            $this->writeTrashDetails($fieldId, $refs);
        }
        finally {
            $this->db->popResult();
        }

        $this->trashSideEffects($data);
    }

    /**
     * Before restoring.
     *
     * Counterpart to hookOnSoftDelete(): counter and thumbnail fields of the same
     * operation, area references (only where still empty), media lock according to
     * details.user_auth, caches. The name must still be free in the area - creating
     * a field with the name of a field in the trash is refused (hookOnSave()), a
     * restore checks it once more.
     */
    protected function hookOnRestore(array $data): void
    {
        $fieldId = (int)$data['field_id'];
        $areaId  = (int)$data['area_id'];

        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT 1 FROM `field` WHERE `area_id` = ? AND `name` = ? AND `deleted` = 0 AND `field_id` != ? LIMIT 1",
                'isi', [$areaId, (string)$data['name'], $fieldId]
            );
            $taken = $this->db->num_rows > 0;
            $this->db->query("SELECT `details` FROM `field` WHERE `field_id` = ?", 'i', [$fieldId]);
            $details = json_decode((string)($this->db->fetchNum()[0] ?? ''), true);
        }
        finally {
            $this->db->popResult();
        }
        if ($taken) {
            $this->cms->error($this->cms->snippet('area.error_field_restore_name_taken', ['name' => (string)$data['name']]));
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // Counter field and thumbnail fields of the same deletion
        $this->trashCascade[$fieldId] = true;
        try {
            foreach ($this->trashDependentIds($fieldId, (int)($data['rel_field_id'] ?? 0), 'restore') as $id) {
                if (!isset($this->trashCascade[$id])) $this->restoreOneCore($id);
            }
        }
        finally {
            unset($this->trashCascade[$fieldId]);
        }

        // Area references back - only where nobody has set another field since
        $details = is_array($details) ? $details : [];
        $refs = array_intersect(
            (array)($details['trash_area_refs'] ?? []),
            ['default_field_id', 'sort_field_id', 'login_name_field_id', 'login_password_field_id']
        );
        $this->db->pushResult();
        try {
            foreach ($refs as $column) {
                $this->db->query("UPDATE `area` SET `$column` = ? WHERE `area_id` = ? AND `$column` IS NULL", 'ii', [$fieldId, $areaId]);
            }
            $this->writeTrashDetails($fieldId, null);
        }
        finally {
            $this->db->popResult();
        }

        $this->trashSideEffects($data, $details);
    }

    /**
     * Fields that go into the trash resp. come back with a field: its counter
     * field and its thumbnail fields - only those of the same operation on restore
     * (timestamp, as Area::hookOnRestore() does with the records).
     *
     * @param string $mode  'delete' | 'restore'
     * @return int[]
     */
    private function trashDependentIds(int $fieldId, int $relFieldId, string $mode): array
    {
        $deleted = ($mode === 'delete') ? 0 : 1;
        $sql = "SELECT `field_id` FROM `field` WHERE `deleted` = ? AND (`parent_field_id` = ?"
             . ($relFieldId > 0 ? " OR `field_id` = ?" : '') . ")";
        $types = 'ii' . ($relFieldId > 0 ? 'i' : '');
        $values = [$deleted, $fieldId];
        if ($relFieldId > 0) $values[] = $relFieldId;
        if ($mode === 'restore') {
            $sql .= " AND `changed_at` = ?";
            $types .= 's';
            $values[] = $this->getRestoreTimestamp();
        }
        $this->db->pushResult();
        $this->db->query($sql, $types, $values);
        $ids = array_map('intval', $this->db->fetchAll());
        $this->db->popResult();
        return $ids;
    }

    /**
     * Note the cleared area references in details.trash_area_refs (null: remove).
     * Directly in the column - the row is not saved via save() in the trash.
     */
    private function writeTrashDetails(int $fieldId, ?array $refs): void
    {
        $this->db->query("SELECT `details` FROM `field` WHERE `field_id` = ?", 'i', [$fieldId]);
        $details = json_decode((string)($this->db->fetchNum()[0] ?? ''), true);
        $details = is_array($details) ? $details : [];
        if ($refs) {
            $details['trash_area_refs'] = array_values($refs);
        }
        elseif (array_key_exists('trash_area_refs', $details)) {
            unset($details['trash_area_refs']);
        }
        else {
            return;
        }
        $this->db->query(
            "UPDATE `field` SET `details` = ? WHERE `field_id` = ?",
            'si', [json_encode($details ?: new \stdClass(), JSON_UNESCAPED_UNICODE), $fieldId]
        );
    }

    /**
     * What soft deletion and restore have in common: media lock, content cache of
     * the area, full text, field caches of the request.
     *
     * @param ?array $details  on restore: details of the field (user_auth decides
     *                         about the media lock); null = soft deletion (lock)
     */
    private function trashSideEffects(array $data, ?array $details = null): void
    {
        $fieldId = (int)$data['field_id'];
        $areaId  = (int)$data['area_id'];
        $type    = (string)($data['type'] ?? '');

        // Media files stay in place, but are no longer directly retrievable
        // (public/file.php refuses fields in the trash as well)
        // (after the commit: a rollback must not leave the directory locked)
        if ($type === 'file') {
            $protect = $details === null || !empty($details['user_auth']);
            $mediaAccess = $this->container->get(MediaAccess::class);
            $this->db->afterCommit(fn() => $mediaAccess->protectField($fieldId, $protect));
        }

        // record_cache: rebuilt without (resp. with) the field on the next access;
        // working copies keep their working state (RecordCache::clear())
        $this->db->pushResult();
        try {
            $this->container->get(RecordCache::class)->clear(0, [$areaId]);
        }
        finally {
            $this->db->popResult();
        }

        // Full text: sources of other full-text fields, link/relation targets of the
        // standard full text
        $areas = [];
        if (in_array($type, self::FULLTEXT_SOURCE_TYPES, true) && (string)($data['display_type'] ?? '') !== 'thumbnail') {
            $areas = $this->fulltextState()->areasUsingField($fieldId);
        }
        elseif (in_array($type, ['link', 'relation', 'relation1', 'fulltext'], true)) {
            $areas = [$areaId];
        }
        $this->fulltextState()->markStale($areas);

        $this->cms->clearFieldCaches();
        $this->queueCacheRebuild();
    }

    /**
     * Rewrite the file cache after the commit: it lists the fields in the trash
     * (InstanceCache::deletedFieldIds(), read by Cms::select()). Once per
     * transaction, however many fields an operation takes along.
     */
    private function queueCacheRebuild(): void
    {
        // Each call queues, the first callback after the commit rebuilds - a rollback
        // discards the callbacks, but leaves the flag: the next commit rebuilds then
        $this->cacheRebuildPending = true;
        $this->db->afterCommit(function () {
            if (!$this->cacheRebuildPending) return;
            $this->cacheRebuildPending = false;
            $this->container->get(InstanceCache::class)->rebuild();
            $this->cms->clearFieldCaches();
        });
    }

    // Target areas of the reference fields
    // ====================

    /**
     * Instances from which a reference field (relation, pointer, link) of an area
     * may choose its target areas: the instance of the area and its readable
     * instances (`readable_instances`, see InstanceCache) - only their records
     * are visible to the frontend of the instance.
     *
     * @param int $areaId  source area (the area of the field)
     * @return int[]       instance_ids, own one first; [0] for unknown area
     */
    public function targetInstanceIds(int $areaId): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT `instance_id` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
        $instanceId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        if ($instanceId < 1) return [0];
        $ids = [$instanceId];
        $instanceCache = $this->container->get(InstanceCache::class);
        foreach ((array)($instanceCache->instance($instanceId)['readable_instances'] ?? []) as $readableId) {
            $readableId = (int)$readableId;
            if ($readableId > 0 && !in_array($readableId, $ids, true)) $ids[] = $readableId;
        }
        return $ids;
    }

    /**
     * Links (`data_rel`) of a pointer/link field whose target record lies in
     * none of the areas $areaIds - counted per target area before the
     * target areas of the field are changed (FieldBackend::_editSave()).
     *
     * Working copies count too: the edges of a checked-out source record
     * disappear during cleanup likewise (removeRelationsOutside()).
     *
     * @param int   $fieldId
     * @param int[] $areaIds  the future target areas
     * @return array          area_id => number of edges; empty if nothing is affected
     */
    public function countRelationsOutside(int $fieldId, array $areaIds): array
    {
        if ($fieldId < 1) return [];
        $areaIds = $this->system->sanitizeIds($areaIds, true);
        $notIn = empty($areaIds) ? '' : " AND `r`.`area_id` NOT IN (" . implode(',', $areaIds) . ")";
        $this->db->pushResult();
        $this->db->query(
            "SELECT `r`.`area_id`, COUNT(*) AS `cnt` FROM `data_rel` `d`"
            . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`dest_id`"
            . " WHERE `d`.`field_id` = ?" . $notIn
            . " GROUP BY `r`.`area_id`",
            'i', [$fieldId]
        );
        $counts = [];
        while ($row = $this->db->fetch()) $counts[(int)$row['area_id']] = (int)$row['cnt'];
        $this->db->popResult();
        return $counts;
    }

    /**
     * Remove links (`data_rel`) of a pointer/link field whose
     * target record no longer lies in any target area of the field (`rel_field_area`) -
     * after saving changed target areas, if the admin has confirmed it.
     *
     * With 'link' the target records belong to the source record (LinkTrait): without
     * an edge they would be orphaned and unreachable in their dependent area - they
     * are therefore soft-deleted as at check-in (live records, not already
     * deleted records; working copies are left to their process).
     *
     * @return int  Anzahl entfernter Kanten
     */
    public function removeRelationsOutside(int $fieldId): int
    {
        if ($fieldId < 1) return 0;
        $this->db->pushResult();
        $this->db->query("SELECT `type` FROM `field` WHERE `field_id` = ?", 'i', [$fieldId]);
        $type = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->query(
            "SELECT `d`.`src_id`, `d`.`dest_id`, `r`.`tmp`, `r`.`deleted` FROM `data_rel` `d`"
            . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`dest_id`"
            . " WHERE `d`.`field_id` = ? AND NOT EXISTS ("
            . "   SELECT 1 FROM `rel_field_area` `a` WHERE `a`.`field_id` = `d`.`field_id` AND `a`.`area_id` = `r`.`area_id`"
            . " )",
            'i', [$fieldId]
        );
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        if (empty($rows)) return 0;

        $orphanIds = [];
        foreach ($rows as $row) {
            $this->db->query(
                "DELETE FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?",
                'iii', [$fieldId, (int)$row['src_id'], (int)$row['dest_id']]
            );
            if (in_array($type, $this->cms->dependentTypes, true) && empty($row['tmp']) && empty($row['deleted'])) {
                $orphanIds[(int)$row['dest_id']] = true;
            }
        }
        // link: soft-delete orphaned contents (Record::delete() also takes their own
        // firmly linked contents along, LinkTrait::cascadeDependents())
        $record = $this->recordDataset();
        foreach (array_keys($orphanIds) as $orphanId) {
            if ($record->linkOwner($orphanId) !== null) continue; // (still belongs to someone)
            $record->delete($orphanId, false);
        }
        return count($rows);
    }

    /**
     * Record-Instanz bereitstellen.
     *
     * As in Area::recordDataset(): deliberately not injected via the constructor -
     * Dataset child classes inherit its signature and do not declare their own.
     * The container supplies the same shared instance as everywhere else.
     */
    private function recordDataset(): Record
    {
        return $this->container->get(Record::class);
    }

    private function fulltextState(): FulltextState
    {
        return $this->container->get(FulltextState::class);
    }

    // Quellfelder eines Volltextfeldes
    // ====================

    /**
     * Key of a full-text source (pseudo field source_fields): the field_id of a
     * field of the own area, or via a link field "{rel_field_id}:{text_field_id}".
     */
    public static function sourceKey(int $textFieldId, int $relFieldId = 0): int|string
    {
        return $relFieldId > 0 ? $relFieldId . ':' . $textFieldId : $textFieldId;
    }

    /** @return int[]  [text_field_id, rel_field_id (0 = eigener Bereich)] */
    public static function parseSourceKey(mixed $key): array
    {
        $parts = explode(':', (string)$key, 2);
        return count($parts) === 2 ? [(int)$parts[1], (int)$parts[0]] : [(int)$parts[0], 0];
    }

    /**
     * Selectable sources of a full-text field: text, character, JSON and upload fields
     * (no thumbnails) of the area and - via its fields of type link, relation
     * and relation1 - of the target areas. Fields behind a pointer do not: a
     * reference is a loose reference to foreign contents (often several areas), whose
     * text does not belong to the record.
     *
     * Changes to a target record reach the full text only with link (the
     * content belongs to the record, VirtualFieldsTrait::refreshAffectedFulltext());
     * with relation only at the next save of the record itself or via
     * Neuaufbau (Zeitplaner-Aufgabe fulltext).
     *
     * @return array  sourceKey() => ['label' => HTML, 'text_field_id', 'rel_field_id',
     *                'group' => heading HTML ('' = own area)]
     */
    public function fulltextSourceOptions(int $areaId): array
    {
        $options = [];
        $sourceSql = "`t`.`type` IN ('text', 'chars', 'json', 'file') AND NOT (`t`.`type` = 'file' AND `t`.`display_type` = 'thumbnail')"
                   . " AND `t`.`deleted` = 0";
        $label = fn(array $row) => '<strong>' . htmlspecialchars((string)$row['name']) . '</strong> '
            . htmlspecialchars($this->cms->textOrSnippet((string)$row['label']));
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `t`.`field_id`, `t`.`name`, `t`.`label` FROM `field` `t` WHERE `t`.`area_id` = ? AND {$sourceSql}"
                . " ORDER BY `t`.`position`",
                'i', [$areaId]
            );
            foreach ($this->db->fetchAll() as $row) {
                $options[(string)$row['field_id']] = [
                    'label' => $label($row), 'group' => '',
                    'text_field_id' => (int)$row['field_id'], 'rel_field_id' => 0,
                ];
            }
            // Target areas: link via rel_field_area, relation/relation1 via rel_area_id
            $this->db->query(
                "SELECT `l`.`field_id` AS `rel_id`, `l`.`name` AS `rel_name`, `l`.`label` AS `rel_label`,"
                . " `a`.`label` AS `area_label`, `t`.`field_id`, `t`.`name`, `t`.`label`"
                . " FROM `field` `l`"
                . " LEFT JOIN `rel_field_area` `ra` ON `ra`.`field_id` = `l`.`field_id` AND `l`.`type` = 'link'"
                . " INNER JOIN `area` `a` ON `a`.`area_id` = IF(`l`.`type` = 'link', `ra`.`area_id`, `l`.`rel_area_id`) AND `a`.`deleted` = 0"
                . " INNER JOIN `field` `t` ON `t`.`area_id` = `a`.`area_id`"
                . " WHERE `l`.`area_id` = ? AND `l`.`deleted` = 0 AND `l`.`type` IN ('link', 'relation', 'relation1') AND {$sourceSql}"
                . " ORDER BY `l`.`position`, `l`.`field_id`, `a`.`area_id`, `t`.`position`",
                'i', [$areaId]
            );
            foreach ($this->db->fetchAll() as $row) {
                $group = htmlspecialchars($this->cms->textOrSnippet((string)$row['area_label']))
                    . ' <span class="c9-grey-50 fw-normal">– ' . htmlspecialchars($this->cms->textOrSnippet((string)$row['rel_label']))
                    . ' (' . htmlspecialchars((string)$row['rel_name']) . ')</span>';
                $options[(string)self::sourceKey((int)$row['field_id'], (int)$row['rel_id'])] = [
                    'label' => $label($row), 'group' => $group,
                    'text_field_id' => (int)$row['field_id'], 'rel_field_id' => (int)$row['rel_id'],
                ];
            }
        }
        finally {
            $this->db->popResult();
        }
        return $options;
    }

    // Typ-Konvertierung
    // ====================

    /**
     * Possible targets of a conversion for a field (selection in FieldBackend::convert()).
     *
     * Per entry: 'type', 'multilingual' (0|1) and 'lossy' (true = the conversion can
     * lose content, see conversionLossy()). A type change keeps the
     * multilingualism, a language change keeps the type - both at once do not exist,
     * so the selection stays manageable.
     *
     *     Text and number types      among each other (both groups)
     *     relation <-> relation1     pointer <-> pointer1
     *     single <-> multilingual    all types capable of multilingualism
     *                                (Cms::isMultilingualField()), Thumbnails erben
     *                                the flag from the upload field and are excluded
     *
     * Fields on meta columns of `record` have a fixed type and no language.
     *
     * @param array $data  field data from load()
     * @return array       [['type' => ..., 'multilingual' => ..., 'lossy' => ...], ...]
     */
    public function conversionTargets(array $data): array
    {
        $type = (string)($data['type'] ?? '');
        $name = (string)($data['name'] ?? '');
        $multilingual = (int)!empty($data['multilingual']);
        if ($type === '' || isset($this->cms->schema[$name])) return [];

        $targets = [];

        // Typwechsel
        $typeTargets = [];
        $valueTypes = array_merge($this->convertTextTypes, $this->convertNumericTypes);
        if (in_array($type, $valueTypes, true)) {
            $typeTargets = array_values(array_diff($valueTypes, [$type]));
        }
        elseif (in_array($type, $this->cms->relationTypes, true) && !in_array($type, $this->cms->dependentTypes, true)) {
            // (not 'link': there is no target type for it)
            $typeTargets = [str_ends_with($type, '1') ? substr($type, 0, -1) : $type . '1'];
        }
        foreach ($typeTargets as $toType) {
            $targets[] = [
                'type' => $toType,
                'multilingual' => $multilingual,
                'lossy' => $this->conversionLossy($type, $toType, $multilingual, $multilingual),
            ];
        }

        // single <-> multilingual
        if ($this->cms->isMultilingualField(['type' => $type, 'multilingual' => 1])
            && !($type === 'file' && ($data['display_type'] ?? '') === 'thumbnail')
        ) {
            $targets[] = [
                'type' => $type,
                'multilingual' => $multilingual ? 0 : 1,
                'lossy' => $this->conversionLossy($type, $type, $multilingual, $multilingual ? 0 : 1),
            ];
        }
        return $targets;
    }

    /**
     * The preconditions of convert() - without changing anything (also for dry runs):
     *
     *  1. the target is listed under conversionTargets()
     *  2. on a type change: the name is bound system-wide to one type - no
     *     other field may carry it - and it must not have the prefix of another type
     *     ($fieldPrefixes)
     *
     * @param array $data  field data from load()
     * @return bool        false: Meldung in Cms::errors
     */
    public function conversionAllowed(array $data, string $toType, int $toMultilingual): bool
    {
        $toMultilingual = (int)(bool)$toMultilingual;
        $allowed = false;
        foreach ($this->conversionTargets($data) as $target) {
            if ($target['type'] === $toType && (int)$target['multilingual'] === $toMultilingual) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            $this->cms->error($this->cms->snippet('area.error_field_convert_not_allowed'));
            return false;
        }
        if ($toType === (string)$data['type']) return true;
        $name = (string)$data['name'];
        $this->db->pushResult();
        try {
            $this->db->query("SELECT COUNT(*) FROM `field` WHERE `name` = ? AND `field_id` != ?", 'si', [$name, (int)$data['field_id']]);
            $inUse = (int)($this->db->fetchNum()[0] ?? 0) > 0;
        }
        finally {
            $this->db->popResult();
        }
        if ($inUse) {
            $this->cms->error($this->cms->snippet('area.error_field_convert_name_in_use'));
            return false;
        }
        foreach ($this->fieldPrefixes as $long => $short) {
            $prefix = $short . '_';
            if (str_starts_with($name, $prefix) && $toType !== $long) {
                $this->createFieldError('area.error_field_reserved_prefix', 'name', $this->schema['name']['label'] ?? '', $prefix);
                return false;
            }
        }
        return true;
    }

    /**
     * Open working copies of an area (note before convert(): the conversion
     * does not respect the edit lock).
     *
     * @return array  rows with record_id and displayname of the editor
     */
    public function workingCopies(int $areaId): array
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `r`.`record_id`, `a`.`displayname` FROM `record` `r`"
                . " LEFT JOIN `admin` `a` ON `a`.`admin_id` = `r`.`changed_by`"
                . " WHERE `r`.`area_id` = ? AND `r`.`tmp` = 1",
                'i', [$areaId]
            );
            return $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Can a conversion lose contents?
     *
     * From multilingual to single-language always (only the main language remains), on a
     * type change everything not listed in $losslessConversions.
     */
    public function conversionLossy(string $fromType, string $toType, int $fromMultilingual, int $toMultilingual): bool
    {
        if ($fromMultilingual && !$toMultilingual) return true;
        if ($fromType === $toType) return false;
        return !in_array($toType, $this->losslessConversions[$fromType] ?? [], true);
    }

    /**
     * Convert a field into another type and/or from single to multilingual (and
     * vice versa) - the only way to do so, see class comment.
     *
     * In order, within one transaction:
     *
     *  1. check the target against conversionTargets().
     *  2. On type change: the name is bound system-wide to one type
     *     (`fieldname`). If another field uses the same name, it is not possible - first
     *     rename it. Reserved prefixes apply as when creating.
     *  3. Copy the stock: row by row from the old into the new data_* table
     *     (convertRows(), values via convertValue()), for relation -> relation1 and
     *     pointer -> pointer1 instead, per record remove all links except the
     *     first. The type of the name in `fieldname` is updated; a
     *     field that is no longer a text field drops out of the full-text sources.
     *  4. On language change: applyMultilingualChange() - `empty` flags resp.
     *     Dateiablage umstellen.
     *  5. Felddefinition anpassen (conversionUpdate()): display_type, min/max,
     *     flags, default - whatever does not fit the new type is reset.
     *  6. The content cache of the area's records is discarded, Cms::fetch()
     *     rebuilds it on the next access. The working state of open working copies
     *     remains and is converted (Record::retypeWorkingCopies(), for file fields
     *     Record::migrateWorkingCopyFiles()); if the field is
     *     the default field, `data_dft` is updated as well.
     *
     * Fehler stehen in Cms::errors.
     *
     * @param int    $fieldId
     * @param string $toType
     * @param int    $toMultilingual  0|1
     * @return bool  true if converted
     */
    public function convert(int $fieldId, string $toType, int $toMultilingual): bool
    {
        if (!$data = $this->load($fieldId)) return false;
        $fromType = (string)$data['type'];
        $fromMultilingual = (int)!empty($data['multilingual']);
        $toMultilingual = (int)(bool)$toMultilingual;
        $name = (string)$data['name'];
        $areaId = (int)$data['area_id'];

        // 1. and 2.: target allowed, name fits the target type (conversionAllowed())
        if (!$this->conversionAllowed($data, $toType, $toMultilingual)) return false;
        $typeChanged = ($toType !== $fromType);
        $multilingualChanged = ($toMultilingual !== $fromMultilingual);

        // Full text affected? Determine the areas now - convert() removes the field
        // if necessary from `rel_fulltext_text`
        $isSource = fn(string $t) => in_array($t, self::FULLTEXT_SOURCE_TYPES, true)
            && (string)($data['display_type'] ?? '') !== 'thumbnail';
        $fulltextAffected = ($typeChanged && ($isSource($fromType) || $isSource($toType)))
            || ($multilingualChanged && $isSource($fromType));
        $fulltextAreas = $fulltextAffected ? $this->fulltextState()->areasUsingField($fieldId) : [];
        $fulltextIds = [];

        $this->db->pushResult();
        try {
            $this->db->beginTransaction();

            if ($typeChanged) {
                // 3. Bestand
                $fromSuffix = (string)($this->cms->dataSuffix[$fromType] ?? '');
                $toSuffix   = (string)($this->cms->dataSuffix[$toType] ?? '');
                if ($fromSuffix === '' || $toSuffix === '') {
                    throw new \Exception("convert(): unknown data table for $fromType / $toType");
                }
                if ($fromSuffix !== $toSuffix) {
                    $this->convertRows($fieldId, $fromType, $toType);
                }
                elseif (in_array($toType, $this->cms->singleRelationTypes, true)) {
                    $this->reduceRelations($fieldId, (int)($data['rel_field_id'] ?? 0), str_starts_with($toType, 'relation'));
                }
                $this->db->query("UPDATE `fieldname` SET `type` = ? WHERE `name` = ?", 'ss', [$toType, $name]);
                $this->cms->clearFieldCaches();

                // Full-text sources: text fields only
                if (!in_array($toType, self::FULLTEXT_SOURCE_TYPES, true)) {
                    $this->db->query("SELECT `fulltext_field_id` FROM `rel_fulltext_text` WHERE `text_field_id` = ?", 'i', [$fieldId]);
                    $fulltextIds = array_map('intval', $this->db->fetchAll());
                    $this->db->query("DELETE FROM `rel_fulltext_text` WHERE `text_field_id` = ?", 'i', [$fieldId]);
                }
            }

            // 4. Sprachwechsel
            if ($multilingualChanged) {
                $this->applyMultilingualChange($fieldId, $toType, (bool)$toMultilingual);
            }

            // 5. Felddefinition
            $update = $this->conversionUpdate($data, $toType);
            $update['type'] = $toType;
            $update['multilingual'] = $toMultilingual;
            $set = [];
            $bindTypes = '';
            $values = [];
            foreach ($update as $column => $value) {
                $set[] = "`$column` = ?";
                $bindTypes .= is_int($value) ? 'i' : 's';
                $values[] = $value;
            }
            $bindTypes .= 'i';
            $values[] = $fieldId;
            $this->db->query("UPDATE `field` SET " . implode(', ', $set) . " WHERE `field_id` = ?", $bindTypes, $values);

            // Full text: the field is added as a source, drops out or yields in
            // the other languages different values (language change)
            if ($fulltextAffected) {
                if ($toType === 'encrypted') {
                    // Rebuild immediately - otherwise the plain text would stand in the
                    // search index until then (only here: standardFulltextSources() reads the type
                    // from `field`)
                    foreach ($fulltextIds as $fulltextId) {
                        $this->recordDataset()->refreshFulltext($fulltextId);
                    }
                    foreach ($fulltextAreas as $fulltextAreaId) {
                        $this->recordDataset()->refreshStandardFulltext($fulltextAreaId);
                    }
                }
                else {
                    // otherwise only mark as stale (FulltextState)
                    $this->fulltextState()->markStale($fulltextAreas);
                }
            }

            // Copy of the default field (data_dft): plain text of an encrypted
            // field does not belong in it (then as when saving "ID {record_id}"),
            // otherwise the converted value per language
            if (!empty($data['default_field']) && $toType !== 'file') {
                if (in_array($toType, ['password', 'encrypted', 'json'], true)) {
                    $this->db->query(
                        "UPDATE `data_dft` `d` INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
                        . " SET `d`.`data` = CONCAT('ID ', `r`.`record_id`) WHERE `r`.`area_id` = ?",
                        'i', [$areaId]
                    );
                }
                elseif ($typeChanged || $multilingualChanged) {
                    $toTable = 'data_' . $this->cms->dataSuffix[$toType];
                    $this->db->query(
                        "UPDATE `data_dft` `d` INNER JOIN `{$toTable}` `x`"
                        . " ON `x`.`record_id` = `d`.`record_id` AND `x`.`language_id` = `d`.`language_id` AND `x`.`field_id` = ?"
                        . " SET `d`.`data` = LEFT(CAST(`x`.`data` AS CHAR), 255)",
                        'i', [$fieldId]
                    );
                }
            }

            // 6. Discard the content cache of the area - working copies excluded: their
            // `record_cache` is the working state; it receives the new type
            // (retypeWorkingCopies()), so that nothing is lost when saving
            $this->db->query(
                "DELETE `c` FROM `record_cache` `c`"
                . " INNER JOIN `record` `r` ON `r`.`record_id` = `c`.`record_id`"
                . " WHERE `r`.`area_id` = ? AND `r`.`tmp` = 0",
                'i', [$areaId]
            );
            if ($toType !== 'file' && ($typeChanged || $multilingualChanged)) {
                $this->cms->clearFieldCaches();
                $this->recordDataset()->retypeWorkingCopies(
                    $areaId, $name, $toType,
                    fn(string $value) => $typeChanged ? $this->convertValue($fromType, $toType, $value) : $value
                );
            }

            $this->db->commit();
            return true;

        } catch (\mysqli_sql_exception $e) {
            $this->cms->error('MySQL error: ' . $e->getMessage() . ' (Code ' . $e->getCode() . ')');
            $this->db->rollback();
            return false;
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            $this->db->rollback();
            return false;
        } finally {
            $this->db->popResult();
        }
    }

    /**
     * Changes to the field definition that a type change entails
     * (column => value for the UPDATE in convert()).
     *
     *     display_type   must match the new type (Backend::$displayTypes), otherwise
     *                    the first allowed display
     *     flags          only what the new type knows (Backend::processingFlags() and
     *                    $validationFlags)
     *     min/max        mean something different per group (characters vs. value) and
     *                    are cleared on group change, likewise pattern and default;
     *                    for relation1/pointer1 max = 1 applies, the reverse drops it
     *     custom_order   only for n:m links
     *
     * @param array  $data    field data from load()
     * @param string $toType
     */
    private function conversionUpdate(array $data, string $toType): array
    {
        $fromType = (string)$data['type'];
        $update = [];
        if ($toType === $fromType) return $update;

        $backend = $this->container->get(Backend::class);

        // display_type
        $displayTypes = (array)($backend->displayTypes[$toType] ?? []);
        $displayType = (string)($data['display_type'] ?? '');
        if (!in_array($displayType, $displayTypes, true)) {
            $update['display_type'] = (string)($displayTypes[0] ?? 'text');
        }

        // flags
        $allowedFlags = array_merge(
            $backend->processingFlags($toType),
            (array)($backend->validationFlags[$toType] ?? [])
        );
        $flags = array_values(array_intersect((array)($data['flags'] ?? []), $allowedFlags));
        $update['flags'] = json_encode($flags, JSON_UNESCAPED_UNICODE);

        // Group change text <-> number: limits, pattern and default no longer fit
        $fromText = in_array($fromType, $this->convertTextTypes, true);
        $toText   = in_array($toType, $this->convertTextTypes, true);
        if ($fromText !== $toText || $toType === 'bit' || $fromType === 'bit') {
            $update['min'] = '';
            $update['max'] = '';
            $update['pattern'] = '';
            $update['default'] = '';
        }

        // relation / pointer
        if (in_array($toType, $this->cms->singleRelationTypes, true)) {
            $update['max'] = '1';
            $update['custom_order'] = 0;
        }
        elseif (in_array($fromType, $this->cms->singleRelationTypes, true)) {
            $update['max'] = '';
        }
        return $update;
    }

    /**
     * Copy the stock of a field into the data_* table of the new type.
     *
     * Row by row in PHP instead of INSERT ... SELECT: the conversion of the values
     * (convertValue()) needs decryption, cent arithmetic and a number parser, and
     * MySQL would abort an unsuitable CAST in strict mode. Reading is done in
     * blocks; language_id, record_id, field_id, fieldname_id and `empty` are carried
     * over unchanged, encrypted targets get ciphertext and hash.
     *
     * @param int    $fieldId
     * @param string $fromType
     * @param string $toType
     */
    private function convertRows(int $fieldId, string $fromType, string $toType): void
    {
        $fromTable = 'data_' . $this->cms->dataSuffix[$fromType];
        $toTable   = 'data_' . $this->cms->dataSuffix[$toType];
        $fromEmpty = $this->cms->hasEmptyFlag($fromType);
        $toEmpty   = $this->cms->hasEmptyFlag($toType);
        $bindType  = $this->getBindType($toType);

        $emptyCol = $fromEmpty ? ', `empty`' : '';
        $sqlSelect = "SELECT `language_id`, `record_id`, `field_id`, `fieldname_id`, `data`{$emptyCol}"
            . " FROM `{$fromTable}` WHERE `field_id` = ? ORDER BY `record_id`, `language_id` LIMIT 500 OFFSET ?";

        $sqlInsert = "INSERT INTO `{$toTable}` SET `language_id` = ?, `record_id` = ?, `field_id` = ?, `fieldname_id` = ?, `data` = ?";
        $insertTypes = 'iiii' . $bindType;
        if ($toType === 'encrypted') {
            $sqlInsert .= ", `hash` = ?";
            $insertTypes .= 's';
        }
        if ($toEmpty) {
            $sqlInsert .= ", `empty` = ?";
            $insertTypes .= 'i';
        }

        $offset = 0;
        while (true) {
            $this->db->query($sqlSelect, 'ii', [$fieldId, $offset]);
            $rows = $this->db->fetchAll();
            if (empty($rows)) break;
            foreach ($rows as $row) {
                $value = (string)($row['data'] ?? '');
                if ($fromType === 'encrypted') {
                    $value = $this->crypt->decrypt($value, '', true);
                }
                $newValue = $this->convertValue($fromType, $toType, $value);

                $values = [(int)$row['language_id'], (int)$row['record_id'], (int)$row['field_id'], (int)$row['fieldname_id']];
                if ($toType === 'encrypted') {
                    $values[] = $this->crypt->encrypt((string)$newValue, '', true);
                    $values[] = $this->cms->encryptedHash((string)$newValue);
                }
                else {
                    $values[] = $newValue;
                }
                if ($toEmpty) {
                    $values[] = ($fromEmpty && !empty($row['empty'])) ? 1 : 0;
                }
                $this->db->query($sqlInsert, $insertTypes, $values, true);
            }
            $offset += count($rows);
            if (count($rows) < 500) break;
        }
        $this->db->query("DELETE FROM `{$fromTable}` WHERE `field_id` = ?", 'i', [$fieldId]);
    }

    /**
     * Convert a value from the source into the target type (database format on both sides).
     *
     *     -> Text        numbers as decimal with a point (cents -> amount with two
     *                    decimal places, decimal without trailing zeros), chars to 255 characters
     *     Text -> Zahl   parseNumber(): first number in the text, comma as decimal separator
     *                    allowed, otherwise 0
     *     -> integer     decimal places truncated, cents -> whole amounts
     *     -> currency    Betrag mal 100, gerundet
     *     -> decimal     10 decimal places (bcmath, without loss of precision)
     *     -> bit         1 for "true/yes/on/ja" and any number not equal to 0
     *
     * @param string $fromType
     * @param string $toType
     * @param string $value   value of the source (decrypted)
     * @return int|float|string  value for the column `data` of the target table
     */
    private function convertValue(string $fromType, string $toType, string $value): int|float|string
    {
        // Text-Ziel
        if (in_array($toType, $this->convertTextTypes, true)) {
            $text = match ($fromType) {
                'currency' => $this->centsToText((int)$value),
                'float'    => $this->trimDecimal(sprintf('%.10F', (float)$value)),
                'decimal'  => $this->trimDecimal($value),
                default    => $value,
            };
            return ($toType === 'chars') ? mb_substr($text, 0, 255) : $text;
        }

        // Number target: source as decimal (string with a point)
        if (in_array($fromType, $this->convertTextTypes, true)) {
            $number = $this->parseNumber($value);
            if ($toType === 'bit' && $number === null) {
                $flag = filter_var(trim($value), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                return ($flag === true || in_array(mb_strtolower(trim($value)), ['ja', 'x'], true)) ? 1 : 0;
            }
            $number ??= '0';
        }
        else {
            $number = match ($fromType) {
                'currency' => $this->centsToText((int)$value),
                'float'    => sprintf('%.10F', (float)$value),
                default    => (string)$value,   // integer, bit, decimal
            };
        }

        return match ($toType) {
            'integer'  => preg_match('/^-?\d+$/', $number) ? (int)$number : (int)bcadd($number, '0', 0),
            'currency' => (int)round((float)bcmul($number, '100', 4)),
            'float'    => (float)$number,
            'decimal'  => bcadd($number, '0', 10),
            'bit'      => (bccomp($number, '0', 10) !== 0) ? 1 : 0,
            default    => $number,
        };
    }

    /**
     * Cent amount (currency) as decimal number with two decimal places, e.g. -1234 -> "-12.34".
     */
    private function centsToText(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . sprintf('%02d', $cents % 100);
    }

    /**
     * Nachnullen einer Dezimalzahl entfernen: "12.5000000000" -> "12.5", "3.0" -> "3".
     */
    private function trimDecimal(string $number): string
    {
        if (!str_contains($number, '.')) return $number;
        $number = rtrim(rtrim($number, '0'), '.');
        return ($number === '' || $number === '-') ? '0' : $number;
    }

    /**
     * Find the first number in a text - decimal separator point or comma, thousands-
     * separators are removed ("1.234,56" -> "1234.56", "1,234.56" -> "1234.56",
     * "12,5" -> "12.5"). null if no number is contained.
     */
    private function parseNumber(string $text): ?string
    {
        $text = str_replace(["\u{a0}", ' '], '', trim($text));
        if (!preg_match('/-?\d[\d.,]*/', $text, $m)) return null;
        $number = $m[0];
        $lastComma = strrpos($number, ',');
        $lastDot   = strrpos($number, '.');
        if ($lastComma !== false && $lastDot !== false) {
            // both present: the last one is the decimal separator
            $decimalSep = ($lastComma > $lastDot) ? ',' : '.';
            $number = str_replace($decimalSep === ',' ? '.' : ',', '', $number);
            $number = str_replace(',', '.', $number);
        }
        elseif ($lastComma !== false) {
            $number = str_replace(',', '.', $number);
        }
        // several points: only the last one is the decimal separator
        if (substr_count($number, '.') > 1) {
            $pos = strrpos($number, '.');
            $number = str_replace('.', '', substr($number, 0, $pos)) . substr($number, $pos);
        }
        $number = rtrim($number, '.');
        return preg_match('/^-?\d+(\.\d+)?$/', $number) ? $number : null;
    }

    /**
     * relation -> relation1 resp. pointer -> pointer1: per source record only the
     * first link remains (smallest position, on a tie the smallest dest_id);
     * for links the counter row of the counter field is dropped as well.
     *
     * @param int  $fieldId
     * @param int  $relFieldId     counter field (only relation)
     * @param bool $bidirectional  true for relation
     */
    private function reduceRelations(int $fieldId, int $relFieldId, bool $bidirectional): void
    {
        $this->db->query(
            "SELECT `src_id`, `dest_id` FROM `data_rel` WHERE `field_id` = ? ORDER BY `src_id`, `position`, `dest_id`",
            'i', [$fieldId]
        );
        $rows = $this->db->fetchAll();
        $seen = [];
        foreach ($rows as $row) {
            $srcId = (int)$row['src_id'];
            $destId = (int)$row['dest_id'];
            if (!isset($seen[$srcId])) {
                $seen[$srcId] = true;
                continue;
            }
            $this->db->query(
                "DELETE FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?",
                'iii', [$fieldId, $srcId, $destId]
            );
            if ($bidirectional && $relFieldId > 0) {
                $this->db->query(
                    "DELETE FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?",
                    'iii', [$relFieldId, $destId, $srcId]
                );
            }
        }
    }

    /**
     * Switch the stock of a field from single to multilingual or vice versa.
     *
     * Each record keeps one row per language of its instance (see
     * developer docs, chapter 02 (Data model); only their meaning is changed:
     *
     *     -> mehrsprachig   the row of the main language carries the content, all others
     *                       become fallback (`empty` = 1) - nothing is translated yet
     *     -> einsprachig    all rows get the content of the main language
     *                       (`empty` = 0); the translations are lost
     *
     * The main language is that of the instance of the record - one UPDATE per instance.
     * File fields instead switch files and `data_fil` rows
     * (migrateFileLanguage()) and update their thumbnail fields as well.
     *
     * @param int    $fieldId
     * @param string $type            type (after the conversion)
     * @param bool   $toMultilingual
     */
    private function applyMultilingualChange(int $fieldId, string $type, bool $toMultilingual): void
    {
        if ($type === 'file') {
            // Thumbnail fields (also nested ones) inherit the flag, files
            // along with `data_fil` rows move to the new storage scheme
            $parentIds = [$fieldId];
            $seen = [];
            while (!empty($parentIds)) {
                $parentId = array_shift($parentIds);
                if (isset($seen[$parentId])) continue;
                $seen[$parentId] = true;
                $this->migrateFileLanguage($parentId, $toMultilingual);
                $this->recordDataset()->migrateWorkingCopyFiles($parentId, $toMultilingual);
                $this->db->query("SELECT `field_id` FROM `field` WHERE `parent_field_id` = ?", 'i', [$parentId]);
                foreach ($this->db->fetchAll() as $childId) {
                    $this->db->query("UPDATE `field` SET `multilingual` = ? WHERE `field_id` = ?", 'ii', [(int)$toMultilingual, (int)$childId]);
                    $parentIds[] = (int)$childId;
                }
            }
            return;
        }
        if (!$this->cms->hasEmptyFlag($type)) return;

        $table = 'data_' . $this->cms->dataSuffix[$type];
        $instanceCache = $this->container->get(InstanceCache::class);
        $this->db->query(
            "SELECT DISTINCT `r`.`instance_id` FROM `{$table}` `d`"
            . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id` WHERE `d`.`field_id` = ?",
            'i', [$fieldId]
        );
        foreach (array_map('intval', $this->db->fetchAll()) as $instanceId) {
            $primaryId = $instanceCache->primaryLanguageId($instanceId);
            if ($toMultilingual) {
                $this->db->query(
                    "UPDATE `{$table}` `d` INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
                    . " SET `d`.`empty` = IF(`d`.`language_id` = ?, 0, 1)"
                    . " WHERE `d`.`field_id` = ? AND `r`.`instance_id` = ?",
                    'iii', [$primaryId, $fieldId, $instanceId]
                );
            }
            else {
                $hashCol = ($type === 'encrypted') ? ", `d`.`hash` = `p`.`hash`" : '';
                $this->db->query(
                    "UPDATE `{$table}` `d`"
                    . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
                    . " INNER JOIN `{$table}` `p` ON `p`.`record_id` = `d`.`record_id`"
                    . "     AND `p`.`field_id` = `d`.`field_id` AND `p`.`language_id` = ?"
                    . " SET `d`.`data` = `p`.`data`{$hashCol}, `d`.`empty` = 0"
                    . " WHERE `d`.`field_id` = ? AND `r`.`instance_id` = ?",
                    'iii', [$primaryId, $fieldId, $instanceId]
                );
            }
        }
    }

    // Hilfsfunktionen
    // ====================

    /**
     * Delete the entry in `fieldname` if no field uses it anymore.
     *
     * Public, because Area::hookOnDelete() calls the method from outside when finally deleting
     * an area (there all fields of the area drop via
     * fk_field_area, otherwise their names would remain in `fieldname`).
     *
     * @param string $name  check a specific field name,
     *                      default '': check all field names. Used e.g. when deleting an area.
     */
    public function cleanupFieldname(string $name = ''): void
    {
        // The standard full-text field has no `field` row - its name remains
        if (!empty($name)) {
            $sql = "DELETE FROM `fieldname` WHERE `name` = ? AND `name` != ?"
            ." AND NOT EXISTS (SELECT 1 FROM `field` WHERE `field`.`name` = `fieldname`.`name`)";
            $this->db->query($sql, 'ss', [$name, self::FULLTEXT]);
        }
        else {
            $sql = "DELETE FROM `fieldname` WHERE `name` != ?"
            ." AND NOT EXISTS (SELECT 1 FROM `field` WHERE `field`.`name` = `fieldname`.`name`)";
            $this->db->query($sql, 's', [self::FULLTEXT]);
        }
    }

    /**
     * Creates an entry in fieldname with the given name and type (if not already present)
     * If already present but with another type, the type in use is returned
     *
     * FOR UPDATE locks the row until the end of the transaction. Without this, two
     * parallel save operations create the same name for two different types -
     * `fieldname` has UNIQUE(name), one of the two would run into a duplicate error
     * instead of the understandable message error_field_name_wrong_type.
     *
     * @param string $name  Eingabefeld-Name
     * @param string $type  Eingabefeld-Typ
     * @return string       on type conflict: type in use, otherwise ''
     */
    private function createFieldname(string $name, string $type): string
    {
        $sql = "SELECT `type` FROM `fieldname` WHERE `name` = ? FOR UPDATE";
        $this->db->query($sql, 's', [$name]);

        if ($this->db->num_rows > 0) {
            $usedType = (string) $this->db->fetchNum()[0];

            // Return wrong type
            if ($type !== $usedType) return $usedType;

            // correct type - already present -> no action needed
            return '';

        } else {
            // not yet present -> create it now
            $sql = "INSERT INTO `fieldname` (`name`, `type`) VALUES (?, ?)";
            $this->db->query($sql, 'ss', [$name, $type]);            
            return '';
        }
    }

    /**
     * Generates a unique field name in table `field` with at most 32 characters.
     * Checks whether the name already exists and shortens it if necessary.
     * (because of FOR UPDATE only usable within a transaction)
     *
     * Needed for the automatically created counter fields of relations: their name
     * `rel_{areaname}` may already be taken in the target area. Then is appended
     * _1, _2, … - and because `field`.`name` holds only 32 characters, the prefix is shortened by the
     * length of the suffix instead of cutting the result off at the end.
     *
     * @param string $prefix  Names-Prefix.
     * @param int $areaId     Bereichs-ID
     * @return string         Garantiert eindeutiger Name (max 32 Zeichen).
     */
    public function generateUniqueFieldName(string $prefix, int $areaId): string 
    {
        $suffixNumber = 0;
        $isUnique = false;
        $finalName = '';

        $sql = "SELECT 1 FROM `field` WHERE `area_id`=? AND `name` = ? LIMIT 1 FOR UPDATE";

        while (!$isUnique) {
            // Build counter string
            $suffixStr = ($suffixNumber === 0) ? '' : '_' . $suffixNumber;
            
            // Calculate the available space and cut the prefix accordingly
            $allowedPrefixLength = 32 - strlen($suffixStr);
            $currentPrefix = mb_substr($prefix, 0, $allowedPrefixLength);
            
            $finalName = $currentPrefix . $suffixStr;

            // Query with prepared statement
            $this->db->query($sql, 'is', [$areaId, $finalName]);
            
            if ($this->db->num_rows === 0) {
                $isUnique = true;
            } else {
                $suffixNumber++;
            }
        }

        return $finalName;
    }
}
