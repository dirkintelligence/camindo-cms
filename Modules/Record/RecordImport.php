<?php
/**
 * Import of records and structures - the counterpart to RecordExport: XML
 * (backup format), CSV, DOCX (translation).
 *
 * This is the WORK; the masks are RecordBackendImport (record_import.php),
 * reachable via the menu on the right in the record list (one area) and on the
 * start page of the module (global). In the request or as a background process
 * (Core/cron/record_import.php) EXACTLY THE SAME code runs: run().
 *
 * THREE STEPS
 * --------------------
 *     prepare()   bring the import file (upload or from var/upload) to var/tmp/import-{token}/,
 *                 unpack a zip file there, search for the candidates (xml, csv,
 *                 docx) - several: the editor chooses
 *     analyze*()  look at the chosen file: XML header (origin, version, areas,
 *                 languages), CSV columns with a suggestion of the target fields, DOCX tables
 *     run()       import, log
 *
 * THE JOB
 * --------------------
 *     type        'xml' | 'csv' | 'docx'
 *     area_id     area from whose list the import was called (0 = global)
 *     dir         name of the import directory in var/tmp (import-{token})
 *     file        path of the import file relative to the import directory
 *     mode        mode of the records (MODES; docx: always update)
 *     skip        skip this many records (csv: lines) at the beginning
 *     limit       import at most this many records (0 = all)
 *     force       ignore edit locks of other admins
 *     parent_id   parent record of new records (area with tree; 0 = root) -
 *                 applies if the file names none (see INSERTION POSITION)
 *     previous_id record AFTER which the new ones are inserted (0 = at the
 *                 beginning); if the key is missing: after the last existing one
 *     source_area_id  xml (one area): area_id of the file whose records are imported into
 *                 area_id (0 = all)
 *     csv         csv: ['separator', 'enclosure', 'eol', 'stored' => bool]
 *     mapping     csv: column index => target key (see csvTargets())
 *     docx        docx: ['punct' => bool, 'space' => bool, 'rtl' => 'unicode'|'html'|'none']
 *     global      xml (global): true
 *     folder_id   xml (global): target level - folder under which new areas arise
 *     instance_id xml (global): instance of new areas (0 = from the file resp. target level)
 *     areas       xml (global): area_id of the file => [
 *                     'name'      => deviating area name (mask),
 *                     'structure' => mode (GLOBAL_MODES), 'structure_by' => 'name'|'area_id',
 *                     'records'   => mode, 'records_by' => 'slug'|'record_id',
 *                     'templates' => mode, 'templates_by' => 'name'|'template_id' ]
 *     converters  xml (global): converter_id of the file => decision of the mask:
 *                 'new' (create), 'ignore' or the converter_id of an existing
 *                 converter; if the key is missing: automatic (see CONVERTERS)
 *     same_db     (set by run()) file comes from this database: created_by/owner_id
 *                 is restored, otherwise assigned to the session admin; changed_by
 *                 is always the session admin
 *     token       background: token of the bgproc entry (start())
 *     admin_id    background: triggering admin (recipient of the message)
 *
 * MODES
 * --------------------
 *     insert             always a new record; if the file carries a record_id and it is
 *                        free (or occupied by a working copy / a deleted
 *                        record - which is removed), the record gets this id
 *     update_or_insert   exists: write only the fields of the file, all others
 *                        remain; otherwise like insert
 *     update             like update_or_insert, but skip those that do not exist
 *     replace_or_insert  exists: all fields - what the file does not name is
 *                        emptied (links removed, files deleted); otherwise insert
 *     replace            like replace_or_insert, but skip those that do not exist
 *     ignore             (global only) do not import
 *
 * TWO PASSES (XML)
 * --------------------
 * The ids of the file are not the ids of the target database. Therefore the
 * import collects mapping tables (id of the file => actual id) for areas, fields,
 * templates and records ($this->map) and works in stages:
 *
 *     1. structure   areas (parents before children, new ones under the target level), the
 *                    converters of the thumbnail fields (CONVERTERS), then the fields
 *                    (simple types, thumbnails, relations with their counter field,
 *                    pointers, fulltext), finally per area default/sort field,
 *                    default template and template assignments
 *     2. templates   per template area
 *     3. records     first pass: create resp. check out the working copy, check fields,
 *                    files and passwords and check in - the record_id
 *                    is thereby fixed.
 *                    Second pass, record by record: check out again,
 *                    set links, parent record, template and owner with the
 *                    assigned ids, check in. One after the other and not all at once:
 *                    a working copy also copies the incoming links of its
 *                    original back and forth - if all records were checked out at the same time,
 *                    the check-in of a target record would discard the just-written links of its
 *                    sources.
 *
 * PER RECORD THE SAME WAY AS BY HAND
 * --------------------
 * tmpInit() (new, possibly with a forced id) resp. load() -> lock -> checkOut(), then
 * Record::importSave(): input check according to the field definitions of the target area,
 * checkIn, cache, fulltext, thumbnails - everything as in the input mask. Values from
 * the XML are in database format (storedFormatFields), passwords as hash
 * (prehashedFields), encrypted contents are passed decrypted and re-encrypted by
 * save(). Files come from the import directory
 * ($data[field]['importfile']). Areas, fields and templates go through
 * Area::save(), Field::save(), Template::save() - with all hooks (counter fields,
 * fieldname, rel_field_area, template compilation).
 *
 * INSERTION POSITION
 * --------------------
 * In areas with a tree (input field `parent_id`) or manual sorting (input field
 * `position`) new records land in the order of the file after the
 * record previous_id of the job - once chosen, afterwards each after the most recently
 * inserted one (insertAfter). Without specification after the last existing one: without
 * previous_id createInTree() would place every new record at the BEGINNING of its
 * level, the file would arrive backwards. If the file names a parent record
 * (XML, CSV column parent_id), the record is attached there in the second pass -
 * at the end of the siblings there, likewise in file order.
 * The export sorts by `lft` resp. `position` (RecordExport::exportSort()),
 * so the order survives the way through the file.
 *
 * EMBEDDED CONTENTS (fields of type link; CSV and DOCX)
 * --------------------
 * The target records of a link field belong to the record (LinkTrait) and are
 * imported with it as a unit - as in the input mask: in the first pass
 * they are checked out at the working copy of the record resp. newly created
 * (_importLinks()), their values checked and written into the working state, and
 * LinkTrait::checkInDependents() checks them in within the same transaction as the
 * record (the check-in deletes orphans). If one content fails, the
 * record fails. The second pass then leaves out the link fields.
 *
 *     csv   columns "{link}.record_id", "{link}.area_id", "{link}.{field}[.{code}]"
 *           (csvTargets(); RecordExport writes them) - one value per content, separated
 *           by " | " (_csvLinks()). The list is binding: existing
 *           contents are matched and changed via their id resp. position, new ones
 *           created, ones no longer named removed, the order adopted.
 *           Thumbnails are created anew. Without columns for the link field it remains as
 *           it is - with replace its contents are removed.
 *     docx  lines "field={link}.{field} | record_id={id}": only the named
 *           contents are translated (partial).
 *
 * The XML imports further link link fields in the second pass via the
 * assigned ids (the contents are there as separate records in the file).
 *
 * CONVERTERS (thumbnail fields)
 * --------------------
 * A thumbnail field refers via `rel_field_converter` (sorted) to converters -
 * in the file per field <converter_ids>, older files carry them in
 * details.converter_ids. The ids of the file mean nothing in a foreign database,
 * therefore the export carries the
 * converters of the thumbnail fields along (<converters>, per converter the hash of its
 * settings - Converter::settingsHash()). The import assigns each converter of the
 * file (converterPlan()):
 *
 *     from the same database    the converter_id, if it still exists
 *     otherwise                 an existing converter with the same hash
 *     no match                  the converter is created anew - if its processor
 *                               is installed; otherwise it is ignored
 *
 * The global mask shows the result and lets the editor decide per converter
 * (job 'converters'): create new, choose an existing converter or ignore.
 * _importConverters() executes this before the fields and fills
 * $this->map['converter']; _saveField() inserts the assigned ids. If no converter
 * remains for a thumbnail field, the field is skipped (a thumbnail
 * without converter does not exist - Field::hookOnSave()). Files without <converters>
 * (older exports): from the same database the ids apply, otherwise every
 * converter drops away.
 *
 * CUSTOM SNIPPETS AND SLUG PREFIX (XML)
 * --------------------
 * Labels of areas and fields often refer to the snippet set of their
 * instance (#custom.{instance_id}.{key}, module Snippets). The export carries the texts
 * of these snippets along (<snippets>); the import adds missing keys to the set of the
 * TARGET instance (Snippets::merge() - existing ones remain) and rewrites the references
 * to its prefix (_mapSnippetRefs(), _importSnippets()). Likewise the snippets that
 * imported templates address (custom.{key}, _queueTemplateSnippets()). From the same
 * database the set of the source instance serves as replacement if the file (older
 * exports) carries no <snippets>.
 * The slug prefix of an area (details.slug_prefix, per language_id) is converted via the
 * language codes of the header to the languages of the target instance; if it is not
 * permitted there (Area::slugPrefixErrors(), e.g. already assigned), it is dropped
 * with a note in the log.
 *
 * ANALYSIS (analyze())
 * --------------------
 * Before the import the mask shows what the job would do - without writing
 * anything: per area of the file whether it is created or changed, which fields
 * are added, changed or removed, how many templates and records would be
 * added, changed, replaced or skipped ("extended": every record
 * individually). The input check of the field values only runs during import - whether a
 * record actually passes is not known beforehand.
 *
 * @see Camindo\Core\Modules\Record\RecordBackendImport  the masks
 * @see Camindo\Core\Modules\Record\RecordExport         the counterpart
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Backend;
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Area\Field;
use Camindo\Core\Modules\Converter\Converter;
use Camindo\Core\Modules\Messaging\Message;
use Camindo\Core\Modules\Snippets\Snippets;
use Camindo\Core\Modules\Template\Template;

class RecordImport {

    public const TYPES = ['xml', 'csv', 'docx'];
    public const MODES = ['insert', 'update_or_insert', 'update', 'replace_or_insert', 'replace'];
    /** modes of the global mask (per area): plus 'ignore' */
    public const GLOBAL_MODES = ['insert', 'update_or_insert', 'update', 'replace_or_insert', 'replace', 'ignore'];

    /** type identifier of the background process (table `bgproc`) */
    public const BGPROC_TYPE = 'import';

    /** prefix of the import directories in var/tmp */
    public const DIR_PREFIX = 'import-';

    /** meta columns of `record` that an import file may set */
    public const META_FIELDS = ['status_id', 'online_at', 'offline_at', 'template_id', 'parent_id', 'needs_review', 'user_id'];

    /**
     * Origin columns: admin ids that are only taken over from the file for NEW records
     * (_restoreCreated()) - from the same database (XML) resp.
     * from CSV as the specified admin id; unknown ids remain with the session admin
     */
    public const ORIGIN_FIELDS = ['created_by', 'owner_id'];

    /** area types with records */
    public const CONTENT_TYPES = ['record', 'profile', 'ugc'];

    /** languages with ideographic punctuation (default of the DOCX options) */
    private const IDEOGRAPHIC_LANGUAGES = ['zh', 'ja'];

    /** columns of `field` that the structure import takes over (without keys) */
    private const FIELD_COLUMNS = ['type', 'name', 'label', 'display_type', 'note', 'tooltip', 'height', 'min', 'min_op', 'max', 'max_op', 'pattern', 'prefix', 'suffix', 'position', 'custom_order', 'flags', 'default', 'visibility_input', 'visibility_search', 'visibility_list', 'default_field', 'meta', 'multilingual', 'details'];

    /** target schema of the running import per area */
    private array $schemaMemo = [];

    /** mapping of id of the file => actual id: 'area', 'field', 'template', 'record', 'converter' (0: ignored) */
    private array $map = ['area' => [], 'field' => [], 'template' => [], 'record' => [], 'converter' => []];

    /** most recently created area per parent (order of new areas) */
    private array $lastSibling = [];
    /** insertion position of new records: area_id => parent_id => record_id of the predecessor (INSERTION POSITION) */
    private array $insertAfter = [];

    /** templates of the file: template_id => ['name', 'area_id' (file)] - assignment by name if they are not imported */
    private array $fileTemplates = [];

    /** written templates: template_id => data of the file (for the id replacements in the code) */
    private array $savedTemplates = [];

    /**
     * Trigger templates of the areas: area_id => [template_id (file), ...] in their
     * order - set only
     * after the import (_applyTriggerTemplates()): the records of the file already carry
     * the computed values, and the code of the templates is executable only after
     * _rewriteTemplateCodes() (CMS:REPLACE markers)
     */
    private array $pendingTriggers = [];

    /** areas of the file: area_id => ['name', 'type'] (assignment of the origin areas) */
    private array $fileAreas = [];

    /** file comes from this database (job 'same_db', for the converter assignment in the fields) */
    private bool $sameDb = false;

    /** custom snippets of the file (<snippets>): instance_id of the file => [key => [lang => text]] */
    private array $fileSnippets = [];

    /** languages of the file: language_id of the file => code (header, for details.slug_prefix) */
    private array $fileLanguages = [];

    /** custom snippets to be taken over: target instance => [key => [lang => text]] (_mapSnippetRefs()) */
    private array $pendingSnippets = [];

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Session $session,
        private System $system,
        private InstanceCache $instanceCache,
        private Record $record,
        private Backend $backend,
        private BackgroundProcess $bgproc,
        private Message $message,
        private Area $area,
        private Field $field,
        private Template $template,
        private Converter $converter,
        private Snippets $snippets,
        private \Camindo\Core\AuditLog $auditLog,
        private RecordService $service,
    )
    {
    }

    // ====================
    // Modes

    /** Does the mode create records that do not exist? */
    public static function modeCreates(string $mode): bool
    {
        return in_array($mode, ['insert', 'update_or_insert', 'replace_or_insert'], true);
    }

    /** Does the mode empty what the file does not name? */
    public static function modeReplaces(string $mode): bool
    {
        return str_starts_with($mode, 'replace');
    }

    // ====================
    // Threshold

    public function needsBackground(int $count): bool
    {
        $threshold = $this->backgroundThreshold();
        return $threshold > 0 && $count >= $threshold;
    }

    public function backgroundThreshold(): int
    {
        return (int)($this->config->get('admin.import_background_threshold') ?? 200);
    }

    // ====================
    // Step 1a: provide the file

    /**
     * Bring the import file to var/tmp/import-{token}/ and search for the candidates.
     *
     * @param string $source        path of the file (upload tmpfile or file in var/upload)
     * @param string $originalName  original file name (extension!)
     * @param bool   $isUpload      true: $source is a PHP upload (move_uploaded_file)
     * @return array  ['dir' => 'import-…', 'files' => ['export.xml', …]] or ['error' => …]
     */
    public function prepare(string $source, string $originalName, bool $isUpload): array
    {
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['zip', 'xml', 'csv', 'docx'], true)) {
            return ['error' => $this->cms->snippet('record.error_import_file_type')];
        }
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $dirName = self::DIR_PREFIX . $this->crypt->randomKey(12);
        if (!$this->system->createDir($tmpDir, $dirName)) {
            return ['error' => 'var/tmp not writable'];
        }
        $dir = "$tmpDir/$dirName";
        $target = $dir . '/' . ($extension === 'zip' ? 'import.zip' : 'import.' . $extension);
        $ok = $isUpload ? @move_uploaded_file($source, $target) : @copy($source, $target);
        if (!$ok) {
            $this->removeDir($dirName);
            return ['error' => $this->cms->snippet('error_file_move_uploaded')];
        }
        if ($extension === 'zip') {
            $error = $this->_extractZip($target, $dir);
            @unlink($target);
            if ($error !== '') {
                $this->removeDir($dirName);
                return ['error' => $error];
            }
        }
        $files = $this->candidateFiles($dir);
        if (empty($files)) {
            $this->removeDir($dirName);
            return ['error' => $this->cms->snippet('record.error_import_no_file')];
        }
        return ['dir' => $dirName, 'files' => $files];
    }

    /**
     * Extract the zip file - entries with path traversal (zip slip) are rejected.
     * @return string  '' or error message
     */
    private function _extractZip(string $zipPath, string $dir): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return $this->cms->snippet('record.error_import_zip');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', $name);
            if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('!(^|/)\.\.(/|$)!', $normalized)) {
                $zip->close();
                return $this->cms->snippet('record.error_import_zip');
            }
        }
        $ok = $zip->extractTo($dir);
        $zip->close();
        return $ok ? '' : $this->cms->snippet('record.error_import_zip');
    }

    /**
     * Absolute path of an import directory, null for an invalid name or
     * missing directory.
     */
    public function importDir(string $dirName): ?string
    {
        if (!preg_match('/^' . preg_quote(self::DIR_PREFIX, '/') . '[A-Za-z0-9_\-]+$/', $dirName)) return null;
        $dir = (string)$this->config->get('paths.tmp_dir') . '/' . $dirName;
        return is_dir($dir) ? $dir : null;
    }

    /**
     * Remove the import directory including its contents.
     */
    public function removeDir(string $dirName): void
    {
        $dir = $this->importDir($dirName);
        if ($dir === null) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir((string)$item->getPathname()) : @unlink((string)$item->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Import files (xml, csv, docx) in the directory, relative paths; the
     * media directory of an export is left out.
     *
     * @return string[]
     */
    public function candidateFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) continue;
            $relative = substr((string)$item->getPathname(), strlen($dir) + 1);
            if (str_starts_with($relative, 'media/') || str_contains($relative, '/media/')) continue;
            if (str_starts_with(basename($relative), '.')) continue;
            if ($this->typeOf($relative) === '') continue;
            $files[] = $relative;
        }
        sort($files);
        return $files;
    }

    /**
     * Importer type of a file by its extension ('' = not an import file).
     */
    public function typeOf(string $file): string
    {
        $extension = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        return in_array($extension, self::TYPES, true) ? $extension : '';
    }

    /**
     * Path of an import file within the directory (checked against path traversal).
     */
    public function filePath(string $dirName, string $file): ?string
    {
        $dir = $this->importDir($dirName);
        if ($dir === null) return null;
        $real = realpath($dir . '/' . $file);
        if ($real === false || !is_file($real) || !str_starts_with($real, realpath($dir) . DIRECTORY_SEPARATOR)) return null;
        return $real;
    }

    /**
     * Importable files in var/upload (selection instead of upload), relative paths.
     *
     * @return string[]
     */
    public function uploadDirFiles(): array
    {
        $dir = (string)$this->config->get('paths.upload_dir');
        if (!is_dir($dir)) return [];
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) continue;
            $extension = strtolower((string)$item->getExtension());
            if (!in_array($extension, ['zip', 'xml', 'csv', 'docx'], true)) continue;
            $files[] = substr((string)$item->getPathname(), strlen($dir) + 1);
        }
        sort($files);
        return $files;
    }

    // ====================
    // Step 1b: analysis

    /**
     * XML: read the header, count areas, records and templates.
     *
     * @return array [
     *     'ok'        => bool      file is a camindo export file
     *     'errors'    => string[]  hard errors (no import possible)
     *     'warnings'  => string[]  version / database / format
     *     'header'    => array     database, cms_version, admin, exported_at, area, languages (id => code), structure, content
     *     'count'     => int       records
     *     'templates' => int       templates
     *     'areas'     => array     areas WITH records: area_id (origin) => ['name', 'label', 'count', 'target_area_id']
     *     'structure' => array     all areas of the file (format 2, tree order): area_id => [
     *                              'name', 'label', 'type', 'parent_id', 'fields', 'records', 'templates',
     *                              'target_by_name', 'target_by_id' (0 = not present)]
     * ]
     */
    public function analyzeXml(string $path, int $areaId): array
    {
        $result = ['ok' => false, 'errors' => [], 'warnings' => [], 'header' => [], 'count' => 0, 'templates' => 0, 'areas' => [], 'structure' => [], 'converters' => []];
        $reader = new \XMLReader();
        if (!@$reader->open($path)) {
            $result['errors'][] = $this->cms->snippet('record.error_import_not_camindo');
            return $result;
        }
        $header = null;
        $areas = [];
        $structure = [];
        $templates = [];
        $converters = [];
        $count = 0;
        $rootOk = false;
        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) continue;
                if ($reader->depth === 0) {
                    if ($reader->name !== 'camindo-export') break;
                    $rootOk = true;
                    $format = (int)$reader->getAttribute('format');
                    if ($format !== RecordExport::XML_FORMAT) {
                        $result['warnings'][] = str_replace('{format}', (string)$format, $this->cms->snippet('record.warning_import_format'));
                    }
                    continue;
                }
                if ($reader->depth === 1 && $reader->name === 'header') {
                    $header = $this->_xmlHeader($this->_readerNode($reader));
                    $reader->next();
                    continue;
                }
                if ($reader->depth === 2 && $reader->name === 'area') {
                    $node = $this->_readerNode($reader);
                    $fileAreaId = (int)$node['area_id'];
                    $structure[$fileAreaId] = [
                        'name' => (string)$node['name'],
                        'label' => trim((string)$node->label),
                        'type' => (string)$node['type'],
                        'parent_id' => (int)$node['parent_id'],
                        'fields' => count($node->fields->field ?? []),
                        'records' => 0,
                        'templates' => 0,
                    ];
                    $reader->next();
                    continue;
                }
                if ($reader->depth === 2 && $reader->name === 'record') {
                    $count++;
                    $recordAreaId = (int)$reader->getAttribute('area_id');
                    $areas[$recordAreaId] = ($areas[$recordAreaId] ?? 0) + 1;
                    $reader->next();
                    continue;
                }
                if ($reader->depth === 2 && $reader->name === 'template') {
                    $templateAreaId = (int)$reader->getAttribute('area_id');
                    $templates[$templateAreaId] = ($templates[$templateAreaId] ?? 0) + 1;
                    $reader->next();
                    continue;
                }
                if ($reader->depth === 2 && $reader->name === 'converter') {
                    $converter = $this->_xmlConverter($this->_readerNode($reader));
                    $converters[(int)$converter['converter_id']] = $converter;
                    $reader->next();
                    continue;
                }
            }
        }
        catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
        }
        $reader->close();

        if (!$rootOk || $header === null) {
            $result['errors'][] = $this->cms->snippet('record.error_import_not_camindo');
            return $result;
        }
        // format 1: the one area is only in the header
        if (empty($structure) && (int)($header['area']['area_id'] ?? 0) > 0) {
            $structure[(int)$header['area']['area_id']] = [
                'name' => (string)$header['area']['name'], 'label' => (string)$header['area']['label'],
                'type' => (string)($header['area']['type'] ?: 'record'), 'parent_id' => 0, 'fields' => 0, 'records' => 0, 'templates' => 0,
            ];
        }
        $result['header'] = $header;
        $result['count'] = $count;
        $result['templates'] = array_sum($templates);
        if ($count < 1 && empty($structure)) $result['errors'][] = $this->cms->snippet('record.error_import_empty');

        // origin
        $ownDb = (string)($this->config->get('db.name') ?? '');
        if ($header['database'] !== '' && $ownDb !== '' && $header['database'] !== $ownDb) {
            $result['warnings'][] = str_replace('{database}', $header['database'], $this->cms->snippet('record.warning_import_database'));
        }
        $ownVersion = defined('CAMINDO_VERSION') ? CAMINDO_VERSION : '';
        if ($header['cms_version'] !== '' && $ownVersion !== '' && version_compare($this->_versionNumber($header['cms_version']), $this->_versionNumber($ownVersion), '<')) {
            $result['warnings'][] = str_replace('{version}', $header['cms_version'], $this->cms->snippet('record.warning_import_version'));
        }
        if (empty($header['languages'])) {
            $result['warnings'][] = $this->cms->snippet('record.warning_import_no_languages');
        }

        // structure: per area of the file the matches in the target database
        foreach ($structure as $fileAreaId => &$info) {
            $info['records'] = (int)($areas[$fileAreaId] ?? 0);
            $info['templates'] = (int)($templates[$fileAreaId] ?? 0);
            $info['target_by_id'] = $this->_resolveArea($fileAreaId, '', (string)$info['type']);
            $info['target_by_name'] = $this->_resolveArea(0, (string)$info['name'], (string)$info['type']);
        }
        unset($info);
        $result['structure'] = $structure;

        // converters of the thumbnail fields: matches in the target database (CONVERTERS)
        $sameDb = $header['database'] !== '' && $header['database'] === $ownDb;
        $result['converters'] = $this->converterMatches($converters, $sameDb);

        // areas with records: origin -> target (same id, otherwise same name)
        foreach ($areas as $originAreaId => $areaCount) {
            $info = $structure[$originAreaId] ?? ['name' => '', 'label' => ''];
            $target = $this->_resolveArea($originAreaId, (string)($info['name'] ?? ''));
            $result['areas'][$originAreaId] = [
                'name' => (string)($info['name'] ?? ''),
                'label' => (string)($info['label'] ?? ''),
                'count' => $areaCount,
                'target_area_id' => $target,
            ];
        }
        $result['ok'] = empty($result['errors']);
        return $result;
    }

    private function _xmlHeader(\SimpleXMLElement $node): array
    {
        $languages = [];
        $primary = 0;
        $languageInstances = [];
        foreach ($node->languages->language ?? [] as $language) {
            $languageId = (int)$language['language_id'];
            $languages[$languageId] = (string)$language['code'];
            if ((string)$language['primary'] === '1') {
                $primary = $languageId;
                if (isset($language['instance_id'])) $languageInstances[(int)$language['instance_id']] = $languageId;
            }
        }
        $fields = [];
        foreach ($node->fields->field ?? [] as $field) {
            $fields[(string)$field['name']] = ['type' => (string)$field['type'], 'multilingual' => (int)$field['multilingual']];
        }
        return [
            'database' => trim((string)($node->database ?? '')),
            'cms_version' => trim((string)($node->{'cms-version'} ?? '')),
            'admin' => trim((string)($node->admin ?? '')),
            'exported_at' => trim((string)($node->{'exported-at'} ?? '')),
            'structure' => (int)($node->structure ?? 0),
            'content' => (int)($node->content ?? 1),
            'area' => [
                'area_id' => (int)($node->area['area_id'] ?? 0),
                'instance_id' => (int)($node->area['instance_id'] ?? 0),
                'name' => (string)($node->area['name'] ?? ''),
                'type' => (string)($node->area['type'] ?? ''),
                'label' => trim((string)($node->area ?? '')),
            ],
            'languages' => $languages,
            'primary_language_id' => $primary,
            // primary language per instance of the file (format 2)
            'primary_by_instance' => $languageInstances,
            'fields' => $fields,
            'files' => (int)($node->files ?? 0),
        ];
    }

    /**
     * The current element of the XMLReader as SimpleXMLElement (expand() returns
     * a node without a document - only after being adopted into a DOMDocument
     * can it be converted to SimpleXML).
     */
    private function _readerNode(\XMLReader $reader): \SimpleXMLElement
    {
        $doc = new \DOMDocument();
        $node = $doc->importNode($reader->expand(), true);
        $doc->appendChild($node);
        $element = simplexml_import_dom($node);
        if ($element === null) throw new \RuntimeException('xml node not readable');
        return $element;
    }

    private function _versionNumber(string $version): string
    {
        return preg_match('/\d+(\.\d+)*/', $version, $m) ? $m[0] : '0';
    }

    /**
     * Target area for an origin area: same id (not deleted), otherwise
     * same name, otherwise 0. $type restricts to an area type ('' = the
     * content areas).
     */
    private function _resolveArea(int $originAreaId, string $name, string $type = ''): int
    {
        $types = $type !== '' ? [$type] : self::CONTENT_TYPES;
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $this->db->pushResult();
        $target = 0;
        if ($originAreaId > 0) {
            $this->db->query("SELECT `area_id` FROM `area` WHERE `area_id` = ? AND `deleted` = 0 AND `type` IN ($placeholders)", 'i' . str_repeat('s', count($types)), array_merge([$originAreaId], $types));
            $target = (int)($this->db->fetchNum()[0] ?? 0);
        }
        if ($target < 1 && $name !== '') {
            $this->db->query("SELECT `area_id` FROM `area` WHERE `name` = ? AND `deleted` = 0 AND `type` IN ($placeholders) ORDER BY `area_id` ASC LIMIT 1", 's' . str_repeat('s', count($types)), array_merge([$name], $types));
            $target = (int)($this->db->fetchNum()[0] ?? 0);
        }
        $this->db->popResult();
        return $target;
    }

    /**
     * CSV: columns of the first line, line count, suggestion for the assignment.
     *
     * @param array $settings  separator, enclosure, eol
     * @return array [
     *     'columns'  => string[]   values of the first line
     *     'rows'     => int        lines in total (incl. header line)
     *     'header'   => bool       first line contains known field names
     *     'suggest'  => array      column index => target key ('' = ignore)
     *     'stored'   => bool       column names look as after the CSV export (database format)
     *     'example'  => string[]   second line (example values for the assignment)
     *     'errors'   => string[]
     * ]
     */
    public function analyzeCsv(string $path, int $areaId, array $settings): array
    {
        $result = ['columns' => [], 'rows' => 0, 'header' => false, 'suggest' => [], 'stored' => false, 'example' => [], 'errors' => []];
        $rows = $this->_csvRows($path, $settings);
        $result['rows'] = count($rows);
        if (empty($rows)) {
            $result['errors'][] = $this->cms->snippet('record.error_import_empty');
            return $result;
        }
        $columns = $rows[0];
        $result['columns'] = $columns;
        $result['example'] = $rows[1] ?? [];
        $targets = $this->csvTargets($areaId);
        $known = 0;
        $columnNames = array_map(fn($column) => trim((string)$column), $columns);
        foreach ($columns as $index => $column) {
            $key = $this->_csvSuggest(trim((string)$column), $targets, $areaId, $columnNames);
            $result['suggest'][$index] = $key;
            if ($key !== '') $known++;
        }
        $result['header'] = $known > 0 && $known >= (int)ceil(count($columns) / 2);
        $result['stored'] = $result['header'];
        if (!$result['header']) $result['suggest'] = array_fill_keys(array_keys($columns), '');
        return $result;
    }

    /**
     * Target key for a column name (format of the CSV export):
     * "{name}", "{name}.{code}", "{name}.record_id"; subfields of file fields
     * and thumbnails are ignored.
     *
     * Link field: "{name}.record_id" takes precedence; if this column is missing in the
     * file, the column "{name}" also counts as a list of ids.
     *
     * @param string[] $columnNames  all column names of the file
     */
    private function _csvSuggest(string $column, array $targets, int $areaId, array $columnNames = []): string
    {
        if ($column === '') return '';
        if (isset($targets[$column])) return $column;
        if (isset($targets["$column.record_id"]) && !in_array("$column.record_id", $columnNames, true)) {
            $type = (string)($this->_schemaOf($areaId)[$column]['type'] ?? '');
            if (in_array($type, $this->cms->relationTypes, true) && !in_array($type, $this->cms->dependentTypes, true)) {
                return "$column.record_id";
            }
        }
        // "{name}.{code}" with language code, "{name}.record_id" resp. "{name}.{sub}.{code}"
        $parts = explode('.', $column);
        while (count($parts) > 1) {
            array_pop($parts);
            $candidate = implode('.', $parts);
            if (isset($targets[$candidate])) {
                // does the language suffix fit? (target key "{candidate}.{code}")
                $code = substr($column, strlen($candidate) + 1);
                if (isset($targets["$candidate.$code"])) return "$candidate.$code";
                // file subfields (image.width) / target fields of a link: not importable
                $schema = $this->_schemaOf($areaId);
                $type = (string)($schema[$parts[0]]['type'] ?? '');
                if ($type === 'file' || in_array($type, $this->cms->relationTypes, true)) return '';
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Target keys of the CSV import: key => label.
     *
     *     {name}            field (multilingual: primary language), meta fields, record_id
     *     {name}.{code}     multilingual field in this language
     *     {name}.record_id  link: ids of the target records (separated by " | ")
     *     {name}            file field: path of the file in the zip file (media/…)
     *     {link}.area_id, {link}.{field}[.{code}]
     *                       field of type link: area and fields of the embedded
     *                       contents (EMBEDDED CONTENTS)
     *
     * Thumbnails and fulltext are not included.
     */
    public function csvTargets(int $areaId): array
    {
        $targets = [];
        $areaData = $this->_areaOf($areaId);
        $instanceId = (int)($areaData['instance_id'] ?? 0);
        $languages = $this->instanceCache->languages($instanceId);
        $primaryId = $this->instanceCache->primaryLanguageId($instanceId);
        $targets['record_id'] = 'record_id';
        foreach ($this->_schemaOf($areaId) as $name => $colDef) {
            $type = (string)($colDef['type'] ?? '');
            if ($type === 'fulltext') continue;
            if ($type === 'file' && !empty($colDef['parent_field_id'])) continue;
            $label = $this->cms->textOrSnippet((string)($colDef['label'] ?? $name));
            if (in_array($type, $this->cms->dependentTypes, true)) {
                // embedded contents (EMBEDDED CONTENTS): ids, area and the
                // fields of the target areas - without thumbnails (they arise anew)
                $targets["$name.record_id"] = "$label.record_id ($name)";
                $targets["$name.area_id"] = "$label.area_id ($name)";
                foreach ($this->record->linkTargetFields($colDef) as $sub => $targetDef) {
                    if ((string)($targetDef['type'] ?? '') === 'file' && !empty($targetDef['parent_field_id'])) continue;
                    $subLabel = $label . '.' . $this->cms->textOrSnippet((string)($targetDef['label'] ?? $sub));
                    if ($this->cms->isMultilingualField($targetDef) && count($languages) > 1) {
                        $targets["$name.$sub"] = $subLabel . " ($name.$sub, " . (string)($languages[$primaryId]['code'] ?? $primaryId) . ')';
                        foreach ($languages as $languageId => $language) {
                            $code = (string)($language['code'] ?? $languageId);
                            $targets["$name.$sub.$code"] = $subLabel . " ($name.$sub, $code)";
                        }
                        continue;
                    }
                    $targets["$name.$sub"] = "$subLabel ($name.$sub)";
                }
                continue;
            }
            if (in_array($type, $this->cms->relationTypes, true)) {
                $targets["$name.record_id"] = "$label.record_id ($name)";
                continue;
            }
            if ($this->cms->isMultilingualField($colDef) && count($languages) > 1) {
                $targets[$name] = $label . ' (' . $name . ', ' . (string)($languages[$primaryId]['code'] ?? $primaryId) . ')';
                foreach ($languages as $languageId => $language) {
                    $code = (string)($language['code'] ?? $languageId);
                    $targets["$name.$code"] = $label . " ($name, $code)";
                }
                continue;
            }
            $targets[$name] = "$label ($name)";
        }
        foreach (array_merge(self::META_FIELDS, self::ORIGIN_FIELDS) as $name) {
            if (isset($targets[$name])) continue;
            if ($name === 'parent_id' && !isset($this->_schemaOf($areaId)['parent_id'])) continue;
            $targets[$name] = $this->cms->textOrSnippet((string)($this->cms->schema[$name]['label'] ?? $name)) . " ($name)";
        }
        return $targets;
    }

    /**
     * DOCX: read tables, count records, check the target language.
     *
     * @return array ['ok', 'errors', 'records' => int, 'language_id' => int,
     *                'source_language_id' => int, 'fields' => string[]]
     */
    public function analyzeDocx(string $path, int $areaId): array
    {
        $result = ['ok' => false, 'errors' => [], 'warnings' => [], 'records' => 0, 'language_id' => 0, 'source_language_id' => 0, 'fields' => []];
        $records = $this->_docxRecords($path, $error);
        if ($records === null) {
            $result['errors'][] = $error !== '' ? $error : $this->cms->snippet('record.error_import_not_docx');
            return $result;
        }
        if (empty($records)) {
            $result['errors'][] = $this->cms->snippet('record.error_import_not_docx');
            return $result;
        }
        $result['records'] = count($records);
        $languageIds = [];
        $fields = [];
        foreach ($records as $entry) {
            $languageIds[(int)$entry['language_id']] = true;
            $result['source_language_id'] = (int)$entry['source_language_id'];
            foreach (array_keys($entry['fields']) as $name) $fields[$name] = true;
            foreach ((array)($entry['links'] ?? []) as $linkName => $dests) {
                foreach ($dests as $subs) {
                    foreach (array_keys($subs) as $sub) $fields["$linkName.$sub"] = true;
                }
            }
        }
        $result['fields'] = array_keys($fields);
        if (count($languageIds) !== 1) {
            $result['errors'][] = $this->cms->snippet('record.error_import_docx_languages');
            return $result;
        }
        $languageId = (int)array_key_first($languageIds);
        $result['language_id'] = $languageId;
        $instanceId = (int)($this->_areaOf($areaId)['instance_id'] ?? 0);
        if (!isset($this->instanceCache->languages($instanceId)[$languageId])) {
            $result['errors'][] = str_replace('{language_id}', (string)$languageId, $this->cms->snippet('record.error_import_docx_language'));
            return $result;
        }
        $schema = $this->_schemaOf($areaId);
        foreach ($result['fields'] as $name) {
            $colDef = $schema[$name] ?? null;
            if (str_contains($name, '.')) {
                // field of an embedded content: {link}.{field}
                [$linkName, $sub] = explode('.', $name, 2);
                $linkDef = $schema[$linkName] ?? null;
                $colDef = ($linkDef !== null && in_array((string)($linkDef['type'] ?? ''), $this->cms->dependentTypes, true))
                    ? ($this->record->linkTargetFields($linkDef, multilingualOnly: true)[$sub] ?? null) : null;
            }
            if ($colDef === null || !$this->cms->isMultilingualField($colDef)) {
                $result['warnings'][] = str_replace('{field}', $name, $this->cms->snippet('record.warning_import_docx_field'));
            }
        }
        $result['ok'] = true;
        return $result;
    }

    /**
     * Is the target language one with ideographic punctuation (default of the options)?
     */
    public function isIdeographicLanguage(int $languageId, int $instanceId): bool
    {
        $code = strtolower((string)($this->instanceCache->languages($instanceId)[$languageId]['code'] ?? ''));
        foreach (self::IDEOGRAPHIC_LANGUAGES as $prefix) {
            if (str_starts_with($code, $prefix)) return true;
        }
        return false;
    }

    // ====================
    // Step 2: execute

    /**
     * Execute the job - foreground as well as background.
     *
     * @param array         $job       see class comment
     * @param callable|null $progress  fn(int $done, int $total)
     * @return array [
     *     'total' => int, 'done' => int, 'failed' => int, 'skipped' => int,
     *     'inserted' => int, 'updated' => int, 'replaced' => int,
     *     'items' => [['kind' => area|field|template|record, 'id' => int, 'label' => string,
     *                  'status' => string, 'messages' => string[]], …]
     *     'error' => string  (only if nothing ran at all)
     * ]
     */
    public function run(array $job, ?callable $progress = null): array
    {
        // audit log: mark the changes of this run as 'import' (applies to the rest
        // of the process - a run is always the last thing a request does)
        $this->auditLog->setContext('import');
        $result = ['total' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0, 'inserted' => 0, 'updated' => 0, 'replaced' => 0, 'items' => []];
        $type = (string)($job['type'] ?? '');
        $path = $this->filePath((string)($job['dir'] ?? ''), (string)($job['file'] ?? ''));
        if (!in_array($type, self::TYPES, true) || $path === null) {
            $result['error'] = $this->cms->snippet('record.error_import_no_file');
            return $result;
        }
        $mode = (string)($job['mode'] ?? 'insert');
        if (!in_array($mode, self::MODES, true)) $mode = 'insert';
        if ($type === 'docx') $mode = 'update';
        $job['mode'] = $mode;
        $job['dir_path'] = dirname($path);
        $this->map = ['area' => [], 'field' => [], 'template' => [], 'record' => [], 'converter' => []];
        $this->lastSibling = [];
        $this->insertAfter = [];
        $this->fileTemplates = [];
        $this->savedTemplates = [];
        $this->pendingTriggers = [];
        $this->fileAreas = [];
        $this->schemaMemo = [];
        $job['same_db'] = false;
        if ($type === 'xml') {
            $job['same_db'] = $this->isSameDatabase($path);
        }
        $this->sameDb = !empty($job['same_db']);

        // structure and templates (XML only)
        // --------------------
        if ($type === 'xml') {
            $job['structure_result'] = $this->_importStructure($path, $job, $result);
            if ($progress !== null) $progress(0, max(1, (int)($job['count'] ?? 0)));
        }

        // records
        // --------------------
        $skip = max(0, (int)($job['skip'] ?? 0));
        $limit = max(0, (int)($job['limit'] ?? 0));
        $entries = match ($type) {
            'xml'  => $this->_xmlEntries($path, $job),
            'csv'  => $this->_csvEntries($path, $job),
            'docx' => $this->_docxEntries($path, $job),
        };
        $total = max(0, (int)($job['count'] ?? 0));
        $prepared = [];
        $index = 0;
        $imported = 0;
        foreach ($entries as $entry) {
            $index++;
            if ($index <= $skip) {
                $result['skipped']++;
                continue;
            }
            if ($limit > 0 && $imported >= $limit) break;
            $imported++;
            $this->cms->errorsReset();
            $item = ['kind' => 'record', 'id' => (int)($entry['id'] ?? 0), 'label' => (string)($entry['label'] ?? ''), 'status' => 'failed', 'messages' => []];
            try {
                if (!empty($entry['error'])) {
                    throw new \RuntimeException((string)$entry['error']);
                }
                $item = $this->_importPrepare($entry, $job, $item);
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $item['status'] = 'failed';
            }
            if ($item['status'] === 'failed') {
                $item['messages'] = array_merge($item['messages'], $this->_errorTexts());
                $this->_addItem($result, $item);
            }
            elseif (!empty($item['prepared'])) {
                $prepared[] = $item;
            }
            else {
                $this->_addItem($result, $item);
            }
            if ($progress !== null) $progress($index, max($total, $index));
        }

        // second pass: links and references with the assigned ids
        // --------------------
        foreach ($prepared as $item) {
            $this->cms->errorsReset();
            try {
                $item = $this->_importFinish($item, $job);
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $item['status'] = 'failed';
            }
            if ($item['status'] === 'failed') $item['messages'] = array_merge($item['messages'], $this->_errorTexts());
            $this->_addItem($result, $item);
        }

        // expert function: rewrite the ids in the template code to the assigned ones -
        // only now are the record ids also fixed
        // --------------------
        if ($type === 'xml' && !empty($this->savedTemplates)) {
            $this->_rewriteTemplateCodes($result);
        }
        // trigger templates only now (see $pendingTriggers)
        // --------------------
        $this->_applyTriggerTemplates($result);
        $this->cms->errorsReset();
        if (empty($result['items'])) {
            $result['error'] = $this->cms->snippet('record.error_import_empty');
        }
        return $result;
    }

    /**
     * Analysis of a job without writing (see class comment). $job as in
     * run(). Structure and templates are assigned as in _importStructure(),
     * the records as in _importPrepare() - only the decision, not the act.
     *
     * @param bool $extended  list every record individually ('items')
     * @return array [
     *     'areas' => [key (global: area_id of the file, otherwise area_id) => [
     *         'label', 'name', 'type', 'target_id' (0 = will be created),
     *         'action'    => inserted | updated | replaced | ignore | skipped (Struktur),
     *         'fields'    => ['added' => [Namen], 'updated' => [], 'removed' => [], 'failed' => []],
     *         'templates' => ['inserted' => n, 'updated' => n, 'replaced' => n, 'skipped' => n, 'failed' => n],
     *         'records'   => dito,
     *         'items'     => [['id' => file id, 'record_id' => target id, 'label', 'status', 'messages' => []], …] ]],
     *     'skipped' => n (option "skip"), 'total' => n (checked records),
     *     'error' => string (only if the file is not readable)
     * ]
     */
    public function analyze(array $job, bool $extended = false): array
    {
        $analysis = ['areas' => [], 'skipped' => 0, 'total' => 0];
        $type = (string)($job['type'] ?? '');
        $path = $this->filePath((string)($job['dir'] ?? ''), (string)($job['file'] ?? ''));
        if (!in_array($type, self::TYPES, true) || $path === null) {
            $analysis['error'] = $this->cms->snippet('record.error_import_no_file');
            return $analysis;
        }
        $mode = (string)($job['mode'] ?? 'insert');
        if (!in_array($mode, self::MODES, true)) $mode = 'insert';
        if ($type === 'docx') $mode = 'update';
        $job['mode'] = $mode;
        $job['dir_path'] = dirname($path);
        $this->map = ['area' => [], 'field' => [], 'template' => [], 'record' => [], 'converter' => []];
        $this->fileTemplates = [];
        $this->savedTemplates = [];
        $this->pendingTriggers = [];
        $this->fileAreas = [];
        $this->schemaMemo = [];
        $job['same_db'] = $type === 'xml' && $this->isSameDatabase($path);
        $this->sameDb = !empty($job['same_db']);
        $isGlobal = !empty($job['global']) && $type === 'xml';
        $counts = fn() => ['inserted' => 0, 'updated' => 0, 'replaced' => 0, 'skipped' => 0, 'failed' => 0];
        $areaEntry = fn(string $label, string $name, string $type, int $targetId, string $action) => [
            'label' => $label, 'name' => $name, 'type' => $type, 'target_id' => $targetId, 'action' => $action,
            'fields' => ['added' => [], 'updated' => [], 'removed' => [], 'failed' => [], 'skipped' => []],
            'templates' => $counts(), 'records' => $counts(), 'items' => [],
        ];
        $analysis['converters'] = []; // converter_id of the file => plan (CONVERTERS), only the ones used
        $newAreas = []; // file area_id => mode of the records: area arises only at import

        // structure and templates (global)
        // --------------------
        if ($isGlobal) {
            [$areas, $templates, $converters] = $this->_xmlStructure($path);
            $settings = (array)($job['areas'] ?? []);
            $writtenAreas = []; // file area_id of the areas whose fields would be written
            foreach ($templates as $template) {
                $this->fileTemplates[(int)$template['template_id']] = ['name' => (string)$template['name'], 'area_id' => (int)$template['area_id']];
            }
            foreach ($areas as $fileAreaId => &$area) {
                $override = trim((string)($settings[$fileAreaId]['name'] ?? ''));
                if ($override !== '' && preg_match('/^[0-9a-z_\-]+$/', $override)) $area['name'] = $override;
                $this->fileAreas[$fileAreaId] = ['name' => (string)$area['name'], 'type' => (string)$area['type'], 'default_field' => $this->_fileFieldName($area, (int)($area['default_field_id'] ?? 0))];
            }
            unset($area);
            // converters: plan for all converters of the file, assignment as at import
            // (nothing is created here - 'create' counts as existing)
            $plan = $this->converterPlan($converters, $job);
            foreach ($plan as $fileConverterId => $converter) {
                $this->map['converter'][$fileConverterId] = $converter['action'] === 'link' ? (int)$converter['converter_id'] : ($converter['action'] === 'create' ? -1 : 0);
            }
            foreach ($areas as $fileAreaId => $area) {
                $areaSettings = (array)($settings[$fileAreaId] ?? []);
                $structureMode = (string)($areaSettings['structure'] ?? 'ignore');
                $by = (string)($areaSettings['structure_by'] ?? 'name');
                if (!in_array($structureMode, self::GLOBAL_MODES, true)) $structureMode = 'ignore';
                $areaType = (string)$area['type'];
                $entry = $areaEntry($this->cms->_strip_tags($this->cms->textOrSnippet((string)$area['label'])), (string)$area['name'], $areaType, 0, 'ignore');
                if ($structureMode === 'ignore') {
                    $target = $this->_resolveArea(0, (string)$area['name'], $areaType);
                    if ($target < 1 && !empty($job['same_db'])) $target = $this->_resolveArea($fileAreaId, '', $areaType);
                    if ($target > 0) $this->map['area'][$fileAreaId] = $target;
                    $entry['target_id'] = $target;
                }
                else {
                    $existingId = ($by === 'area_id' && !empty($job['same_db']))
                        ? $this->_resolveArea($fileAreaId, '', $areaType)
                        : $this->_resolveArea(0, (string)$area['name'], $areaType);
                    $update = $existingId > 0 && $structureMode !== 'insert';
                    if (!$update && !self::modeCreates($structureMode)) {
                        $entry['action'] = 'skipped';
                    }
                    else {
                        $entry['action'] = $update ? (self::modeReplaces($structureMode) ? 'replaced' : 'updated') : 'inserted';
                        if ($update) {
                            $this->map['area'][$fileAreaId] = $existingId;
                            $entry['target_id'] = $existingId;
                        }
                        else {
                            $newAreas[$fileAreaId] = true;
                        }
                        // fields: by name against the target area
                        if (in_array($areaType, self::CONTENT_TYPES, true)) {
                            $writtenAreas[] = $fileAreaId;
                            $existingFields = [];
                            if ($update) {
                                $this->db->pushResult();
                                $this->db->query("SELECT `name`, `type` FROM `field` WHERE `area_id` = ? AND `deleted` = 0", 'i', [$existingId]);
                                foreach ($this->db->fetchAll() as $row) $existingFields[(string)$row['name']] = (string)$row['type'];
                                $this->db->popResult();
                            }
                            $fileNames = [];
                            foreach ((array)$area['fields'] as $field) {
                                $name = (string)$field['name'];
                                $fileNames[] = $name;
                                // thumbnail without converter: skipped (the plan
                                // is below - $this->map['converter'] is already filled)
                                if ($this->_isThumbnail($field) && empty($this->_mapConverterIds($field, $this->sameDb))) {
                                    $entry['fields']['skipped'][] = $name;
                                    continue;
                                }
                                if (!isset($existingFields[$name])) $entry['fields']['added'][] = $name;
                                elseif ($existingFields[$name] !== (string)$field['type']) $entry['fields']['failed'][] = $name;
                                else $entry['fields']['updated'][] = $name;
                            }
                            if (self::modeReplaces($structureMode)) {
                                $entry['fields']['removed'] = array_values(array_diff(array_keys($existingFields), $fileNames));
                            }
                        }
                    }
                }
                $analysis['areas'][$fileAreaId] = $entry;
            }
            $analysis['converters'] = array_intersect_key($plan, $this->_usedConverterIds($areas, $writtenAreas));
            foreach ($templates as $template) {
                $fileAreaId = (int)$template['area_id'];
                if (!isset($analysis['areas'][$fileAreaId])) continue;
                $areaSettings = (array)($settings[$fileAreaId] ?? []);
                $templateMode = (string)($areaSettings['templates'] ?? 'ignore');
                if ($templateMode === 'ignore' || !in_array($templateMode, self::GLOBAL_MODES, true)) continue;
                $by = (string)($areaSettings['templates_by'] ?? 'name');
                $areaId = (int)($this->map['area'][$fileAreaId] ?? 0);
                $status = 'failed';
                if ($areaId > 0 || isset($newAreas[$fileAreaId])) {
                    $existingId = $areaId > 0 ? $this->_resolveTemplate($template, $areaId, $by) : 0;
                    $update = $existingId > 0 && $templateMode !== 'insert';
                    if (!$update && !self::modeCreates($templateMode)) $status = 'skipped';
                    else $status = $update ? (self::modeReplaces($templateMode) ? 'replaced' : 'updated') : 'inserted';
                }
                $analysis['areas'][$fileAreaId]['templates'][$status]++;
            }
        }
        else {
            $areaId = (int)($job['area_id'] ?? 0);
            $areaRow = $this->_areaOf($areaId);
            if (!$areaRow) {
                $analysis['error'] = $this->cms->snippet('area.error_area_not_found');
                return $analysis;
            }
            $analysis['areas'][$areaId] = $areaEntry($this->_areaLabel($areaId), (string)$areaRow['name'], (string)$areaRow['type'], $areaId, 'ignore');
        }

        // records
        // --------------------
        $skip = max(0, (int)($job['skip'] ?? 0));
        $limit = max(0, (int)($job['limit'] ?? 0));
        $entries = match ($type) {
            'xml'  => $this->_xmlEntries($path, $job),
            'csv'  => $this->_csvEntries($path, $job),
            'docx' => $this->_docxEntries($path, $job),
        };
        $force = !empty($job['force']);
        $index = 0;
        $checked = 0;
        foreach ($entries as $entry) {
            $index++;
            if ($index <= $skip) {
                $analysis['skipped']++;
                continue;
            }
            if ($limit > 0 && $checked >= $limit) break;
            $checked++;
            $key = $isGlobal ? (int)$entry['area_id'] : (int)$job['area_id'];
            if (!isset($analysis['areas'][$key])) continue;
            $entryMode = (string)($entry['mode'] ?? $mode);
            if (!in_array($entryMode, self::MODES, true)) $entryMode = $mode;
            $item = ['id' => (int)($entry['id'] ?? 0), 'record_id' => 0, 'label' => (string)($entry['label'] ?? ''), 'status' => 'failed', 'messages' => []];
            if (!empty($entry['error'])) {
                if ($isGlobal && isset($newAreas[$key])) {
                    // the area only arises at import: there is nothing yet
                    $item['status'] = self::modeCreates($entryMode) ? 'inserted' : 'skipped';
                    if ($item['status'] === 'skipped') $item['messages'][] = $this->cms->snippet('record.note_import_not_found');
                }
                else {
                    $item['messages'][] = (string)$entry['error'];
                }
            }
            else {
                $areaId = (int)$entry['data']['area_id'];
                $lookup = $this->_lookupExisting($entry, $job, $entryMode, $areaId, $this->_languagesOf($areaId)['primary_id']);
                if ($lookup['update']) {
                    $item['record_id'] = $lookup['record_id'];
                    $item['status'] = self::modeReplaces($entryMode) ? 'replaced' : 'updated';
                    if (!$force && ($prodData = $this->record->load($lookup['record_id'])) && ($lock = $this->service->lockInfo($prodData))) {
                        $item['status'] = 'failed';
                        $item['messages'][] = $this->service->lockMessage($lock);
                    }
                }
                elseif (self::modeCreates($entryMode)) {
                    $item['status'] = 'inserted';
                    if ($lookup['force_id'] > 0 && $lookup['productive']) {
                        $item['messages'][] = str_replace('{id}', (string)$lookup['force_id'], $this->cms->snippet('record.note_import_id_taken'));
                    }
                }
                else {
                    $item['status'] = 'skipped';
                    $item['messages'][] = $this->cms->snippet('record.note_import_not_found');
                }
            }
            $analysis['areas'][$key]['records'][$item['status']]++;
            if ($extended) $analysis['areas'][$key]['items'][] = $item;
        }
        $analysis['total'] = $checked;
        $this->schemaMemo = [];
        return $analysis;
    }

    /**
     * Name of a field of the file (area from _xmlStructure()) for its field_id.
     */
    private function _fileFieldName(array $area, int $fileFieldId): string
    {
        if ($fileFieldId < 1) return '';
        return (string)($area['fields'][$fileFieldId]['name'] ?? '');
    }

    /**
     * Does the XML file come from this database (header)?
     */
    public function isSameDatabase(string $path): bool
    {
        $reader = new \XMLReader();
        if (!@$reader->open($path)) return false;
        $same = false;
        while (@$reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) continue;
            if ($reader->depth === 1 && $reader->name === 'header') {
                $header = $this->_xmlHeader($this->_readerNode($reader));
                $ownDb = (string)($this->config->get('db.name') ?? '');
                $same = $header['database'] !== '' && $header['database'] === $ownDb;
                break;
            }
            if ($reader->depth === 1) break;
        }
        $reader->close();
        return $same;
    }

    /**
     * Take the result of an entry into the counters and the list.
     */
    private function _addItem(array &$result, array $item): void
    {
        unset($item['tmp_id'], $item['prepared']);
        $status = (string)$item['status'];
        if ($status === 'failed') $result['failed']++;
        elseif ($status === 'skipped') $result['skipped']++;
        else {
            $result['done']++;
            if (isset($result[$status])) $result[$status]++;
        }
        if ($status !== 'skipped' || ($item['kind'] ?? '') === 'record') $result['total']++;
        $result['items'][] = $item;
    }

    /**
     * The messages from Cms::errors as plain-text lines ("Label: Text")
     * @return string[]
     */
    private function _errorTexts(): array
    {
        $texts = [];
        foreach ($this->cms->errors as $key => $error) {
            $text = $this->cms->_strip_tags((string)($error['text'] ?? ''));
            if (!is_int($key) && (string)($error['label'] ?? '') !== '') {
                $text = $this->cms->_strip_tags($this->cms->textOrSnippet((string)$error['label'])) . $this->cms->snippet('colon') . $text;
            }
            $texts[] = $text;
        }
        if (empty($texts)) $texts[] = $this->cms->snippet('record.error_batch_unknown');
        return array_values(array_unique($texts));
    }

    // ====================
    // Import one record (two passes)

    /**
     * First pass: create resp. check out the record, check field values, files,
     * passwords and the simple meta columns and check in. Links and references to other records
     * (parent_id, template_id, user_id) follow in the second pass (_importFinish()),
     * once all ids are fixed. $entry['data'] is the form prepared by the importer:
     *
     *     values    field => value (multilingual: language_id => value, target languages)
     *     stored    fields in database format
     *     prehashed password fields with hash
     *     relations field => [dest_id, …] (ids of the file - assigned in the second pass)
     *     files     field => [langKey ('' or language_id) => ['importfile' => path, 'filename' => name] | null]
     *               (null = file named, but not contained -> error)
     *     meta      meta column => value (database format; template_id, parent_id, user_id
     *               are ids of the file)
     *     area_id   target area
     *
     * The entry carries its mode ($entry['mode'], otherwise $job['mode']) and
     * its update criterion ($entry['by']: 'record_id' | 'slug').
     *
     * @return array  $item with 'status', 'id' and - if a second pass is needed -
     *                'prepared' (for _importFinish())
     */
    /**
     * Existing record for an entry (by criterion $entry['by']). Ids of a
     * foreign database are random: there only the slug counts, and new records
     * get new ids (force_id 0).
     *
     * @return array ['record_id' => found resp. file id, 'productive' => bool,
     *                'update' => bool (exists, in the target area, mode writes),
     *                'force_id' => file id that a new record should get (0 = none)]
     */
    private function _lookupExisting(array $entry, array $job, string $mode, int $areaId, int $primaryLanguageId): array
    {
        $fileId = (int)($entry['id'] ?? 0);
        $data = (array)($entry['data'] ?? []);
        $by = (string)($entry['by'] ?? 'record_id');
        $foreign = empty($job['same_db']) && (string)($job['type'] ?? '') === 'xml' && $by !== 'record_id';
        $recordId = $foreign ? 0 : $fileId;
        if ($by === 'slug') {
            $bySlug = $this->_recordBySlug($areaId, (array)($data['values'] ?? []), $primaryLanguageId);
            if ($bySlug > 0) $recordId = $bySlug;
        }
        $existing = null;
        if ($recordId > 0) {
            $this->db->pushResult();
            $this->db->query("SELECT `record_id`, `area_id`, `tmp`, `deleted` FROM `record` WHERE `record_id` = ?", 'i', [$recordId]);
            $existing = $this->db->fetch();
            $this->db->popResult();
        }
        $isProductive = (bool)($existing && empty($existing['tmp']) && empty($existing['deleted']));
        return [
            'record_id' => $recordId,
            'productive' => $isProductive,
            'update' => $isProductive && $mode !== 'insert' && (int)$existing['area_id'] === $areaId,
            'force_id' => $foreign ? 0 : $fileId,
        ];
    }

    private function _importPrepare(array $entry, array $job, array $item): array
    {
        $mode = (string)($entry['mode'] ?? $job['mode']);
        if (!in_array($mode, self::MODES, true)) $mode = (string)$job['mode'];
        $force = !empty($job['force']);
        $data = (array)$entry['data'];
        $areaId = (int)($data['area_id'] ?? 0);
        $areaData = $this->_areaOf($areaId);
        if (!$areaData) {
            $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            return $item;
        }
        $schema = $this->_schemaOf($areaId);
        $langCtx = $this->_languagesOf($areaId);
        $fileId = (int)($entry['id'] ?? 0);

        // existing record? (by criterion)
        // --------------------
        $lookup = $this->_lookupExisting($entry, $job, $mode, $areaId, $langCtx['primary_id']);
        $recordId = $lookup['record_id'];
        $isProductive = $lookup['productive'];
        $updateExisting = $lookup['update'];
        $fileIdForce = $lookup['force_id'];
        if (!$updateExisting && !self::modeCreates($mode)) {
            // update / replace without an existing record: skip
            $item['status'] = 'skipped';
            $item['messages'][] = $this->cms->snippet('record.note_import_not_found');
            return $item;
        }

        $tmpId = 0;
        $prodData = null;
        if ($updateExisting) {
            // working copy of the original
            if (!$prodData = $this->record->load($recordId)) return $item;
            if (!$force && ($lock = $this->service->lockInfo($prodData))) {
                $this->cms->error($this->service->lockMessage($lock));
                return $item;
            }
            if (!$tmpId = $this->record->checkOut($prodData)) return $item;
        }
        else {
            // new - with the id of the file, if it is free or can be made free
            $forceId = 0;
            if ($fileIdForce > 0) {
                if ($isProductive && $recordId === $fileId) {
                    $item['messages'][] = str_replace('{id}', (string)$fileId, $this->cms->snippet('record.note_import_id_taken'));
                }
                else {
                    $state = $this->record->freeRecordId($fileId);
                    if ($state === 'free') {
                        $forceId = $fileId;
                    }
                    else {
                        $item['messages'][] = str_replace('{id}', (string)$fileId, $this->cms->snippet('record.note_import_id_taken'));
                    }
                }
            }
            if (!$tmpData = $this->record->tmpInit(['area_id' => $areaId], $forceId)) return $item;
            $tmpId = (int)$tmpData['record_id'];
        }

        try {
            $saveData = ['record_id' => $tmpId, 'area_id' => $areaId];
            $fields = ['area_id'];
            $stored = (array)($data['stored'] ?? []);
            $prehashed = (array)($data['prehashed'] ?? []);
            $languageIds = $langCtx['language_ids'];
            $replace = self::modeReplaces($mode);

            // field values and files
            // --------------------
            $this->_fieldValues($schema, $data, $languageIds, $replace, $updateExisting, $tmpId, $saveData, $fields);

            // embedded contents (fields of type link, only CSV/DOCX - EMBEDDED
            // CONTENTS): check out resp. create, values into their working state; the
            // check-in follows with the record
            // --------------------
            $withLinks = array_key_exists('links', $data) && !empty($this->record->linkFields($schema));
            if ($withLinks) {
                $this->_importLinks($tmpId, $schema, (array)$data['links'], $replace, $updateExisting, $job, $item);
            }

            // simple meta columns (references to other records in the second pass)
            // --------------------
            $meta = (array)($data['meta'] ?? []);
            $laterMeta = [];
            foreach ($meta as $name => $value) {
                if (!in_array($name, self::META_FIELDS, true)) continue;
                if (in_array($name, ['parent_id', 'template_id', 'user_id'], true)) {
                    if ((int)$value > 0) $laterMeta[$name] = (int)$value;
                    continue;
                }
                $saveData[$name] = $value;
                $fields[] = $name;
                if (!in_array($name, $stored, true)) $stored[] = $name;
            }

            // insertion position of a new record (INSERTION POSITION): parent of the
            // job, after the predecessor - if the file names a parent, the second
            // pass attaches it
            $placementParent = null;
            if (!$updateExisting) {
                $placement = $this->_insertPlacement($areaId, $schema, $job);
                if ($placement !== null) {
                    $placementParent = $placement['parent_id'];
                    $saveData['previous_id'] = $placement['previous_id'];
                    if (isset($schema['parent_id'])) {
                        $saveData['parent_id'] = $placementParent;
                        $fields[] = 'parent_id';
                        if (!in_array('parent_id', $stored, true)) $stored[] = 'parent_id';
                    }
                }
            }

            // check and check in
            // --------------------
            // link fields are not checked here (not_empty / min): the
            // links come in the second pass, which saves again with a check
            $fields = array_values(array_unique($fields));
            $hasRelationFields = false;
            foreach ($schema as $colDef) {
                if (in_array((string)($colDef['type'] ?? ''), $this->cms->relationTypes, true)) { $hasRelationFields = true; break; }
            }
            // with embedded contents: their check-in and that of the record in
            // one transaction - if one fails, everything stays as it was
            $this->db->beginTransaction();
            try {
                if ($withLinks && !$this->record->checkInDependents($tmpId, $updateExisting ? $recordId : 0)) {
                    throw new \RuntimeException('#dependents failed');
                }
                $newId = $this->record->importSave($fields, $saveData, $stored, $prehashed, deferRelations: $hasRelationFields);
                if (!$newId) {
                    throw new \RuntimeException('#save failed');
                }
                $this->db->commit();
            }
            catch (\Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
            $item['id'] = $updateExisting ? $recordId : (int)$newId;
            $item['status'] = $updateExisting ? ($replace ? 'replaced' : 'updated') : 'inserted';
            if ($placementParent !== null) $this->insertAfter[$areaId][$placementParent] = (int)$newId;
            if (!$updateExisting) {
                // restore origin: created_at from the file; created_by and
                // owner_id only from the same database resp. from CSV (there the
                // admin id is an explicit specification) - save() otherwise sets them to
                // now / session admin
                $restoreAdmins = !empty($job['same_db']) || (string)($job['type'] ?? '') === 'csv';
                $this->_restoreCreated('record', 'record_id', (int)$newId, $meta, $restoreAdmins);
            }
            // mapping of id of the file => actual id (for links)
            if ($fileId > 0) $this->map['record'][$fileId] = (int)$item['id'];
            // second pass needed? link fields in the area (there also the
            // deferred check) or references
            $relations = (array)($data['relations'] ?? []);
            if ($hasRelationFields || !empty($laterMeta)) {
                $item['prepared'] = [
                    'replace' => $replace,
                    'area_id' => $areaId,
                    'links_handled' => $withLinks,
                    'relations' => $relations,
                    'meta' => $laterMeta,
                    'stored' => $stored,
                ];
            }
            return $item;
        }
        catch (\Throwable $e) {
            $message = $e->getMessage();
            if (!str_starts_with($message, '#')) $this->cms->error($message);
            $this->_cancelKeepErrors($tmpId);
            $item['status'] = 'failed';
            return $item;
        }
    }

    /**
     * Take the field values and files of an entry into $saveData/$fields (first
     * pass) - for the record as well as for its embedded contents. Without
     * links, fulltext and meta columns. Multilingual fields name all
     * languages of the instance (with replace, what is missing is emptied: fallback);
     * files not named are deleted with replace of an existing record.
     *
     * @param array $data  'values' and 'files' (see _importPrepare())
     */
    private function _fieldValues(array $schema, array $data, array $languageIds, bool $replace, bool $updateExisting, int $tmpId, array &$saveData, array &$fields): void
    {
        $values = (array)($data['values'] ?? []);
        foreach ($schema as $name => $colDef) {
            $type = (string)($colDef['type'] ?? '');
            if ($type === 'fulltext' || $type === 'file' || in_array($type, $this->cms->relationTypes, true)) continue;
            if (!empty($colDef['meta'])) continue; // meta columns in the second pass
            $given = array_key_exists($name, $values);
            if (!$given && !$replace) continue;
            $value = $given ? $values[$name] : ($type === 'json' ? [] : '');
            if ($this->cms->isMultilingualField($colDef) && count($languageIds) > 0) {
                // name all languages of the instance: what is missing is emptied (fallback)
                $givenLangs = is_array($value) ? $value : [$languageIds[0] => $value];
                $value = [];
                foreach ($languageIds as $languageId) {
                    if (array_key_exists($languageId, $givenLangs)) $value[$languageId] = $givenLangs[$languageId];
                    elseif ($replace) $value[$languageId] = '';
                }
                if (empty($value)) continue;
            }
            $saveData[$name] = $value;
            $fields[] = $name;
        }

        $files = (array)($data['files'] ?? []);
        $tmpCache = null;
        foreach ($schema as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') !== 'file' || !empty($colDef['parent_field_id'])) continue;
            $fieldId = (int)$colDef['field_id'];
            $isMultilingual = $this->cms->isMultilingualField($colDef);
            if (array_key_exists($name, $files)) {
                $value = [];
                foreach ((array)$files[$name] as $langKey => $fileData) {
                    if ($fileData === null) {
                        $this->cms->error($this->cms->snippet('record.error_import_file_missing'), $name, (string)($colDef['label'] ?? $name));
                        throw new \RuntimeException('#file missing');
                    }
                    if ($isMultilingual) {
                        $languageId = ($langKey === '') ? $languageIds[0] : (int)$langKey;
                        if (!in_array($languageId, $languageIds, true)) continue;
                        $value[$languageId] = $fileData;
                    }
                    else {
                        $value = $fileData;
                        break;
                    }
                }
                if (!empty($value)) {
                    $saveData[$name] = $value;
                    $fields[] = $name;
                }
            }
            elseif ($replace && $updateExisting) {
                // not named: delete existing files
                $tmpCache ??= $this->record->loadTmpData($tmpId);
                $entryCache = (array)($tmpCache[$name] ?? []);
                foreach ($this->record->fileEntries($entryCache) as $langKey => $fileData) {
                    if ((int)($fileData['size'] ?? 0) < 1 || !empty($fileData['deleted'])) continue;
                    $this->record->markFileDeleted($tmpId, $fieldId, $langKey === '' ? null : (int)$langKey);
                }
            }
        }
    }

    /**
     * Embedded contents of the link fields of an entry (EMBEDDED CONTENTS) at the
     * working copy $tmpId of the record: check out existing contents (the
     * edge then points to their working copy), create new ones (embedInsert()), write their
     * checked values into the working state (stayTemporary), detach those no longer
     * named and set the order of the file. Everything is checked in
     * with the record (LinkTrait::checkInDependents() in _importPrepare()) -
     * the check-in deletes orphaned contents.
     *
     * Assignment of an entry to an existing content: via its id (CSV column
     * {link}.record_id, DOCX), otherwise via the position. 'partial' (DOCX): change only the
     * named contents - create, remove or re-sort nothing.
     * Without specifications for a link field: with replace all contents are removed.
     *
     * @param array $links  link field => ['entries' => [...], 'partial' => bool]
     * @throws \RuntimeException  on errors (messages in Cms::errors)
     */
    private function _importLinks(int $tmpId, array $schema, array $links, bool $replace, bool $updateExisting, array $job, array &$item): void
    {
        $force = !empty($job['force']);
        foreach ($this->record->linkFields($schema) as $name => $colDef) {
            $fieldId = (int)$colDef['field_id'];
            $link = $links[$name] ?? null;
            if ($link === null && !($replace && $updateExisting)) continue;
            $entries = array_values((array)($link['entries'] ?? []));
            $partial = !empty($link['partial']);
            $linkLabel = $this->cms->textOrSnippet((string)($colDef['label'] ?? $name));

            // existing contents (productive, in their order)
            $current = [];
            foreach ($this->record->linkDestIds($tmpId, $fieldId) as $destId) {
                $this->db->pushResult();
                $this->db->query("SELECT 1 FROM `record` WHERE `record_id` = ? AND `tmp` = 0 AND `deleted` = 0", 'i', [$destId]);
                $productive = $this->db->num_rows > 0;
                $this->db->popResult();
                if ($productive) $current[] = $destId;
            }
            $targetAreaIds = $this->record->linkTargetAreaIds($colDef);
            $final = [];
            $used = [];
            foreach ($entries as $index => $entry) {
                $entryId = $entry['id'] ?? null;
                $destId = 0;
                if ($entryId !== null) {
                    if (in_array((int)$entryId, $current, true) && !isset($used[(int)$entryId])) $destId = (int)$entryId;
                }
                elseif (!$partial && isset($current[$index]) && !isset($used[$current[$index]])) {
                    $destId = $current[$index];
                }
                if ($partial && $destId < 1) {
                    $item['messages'][] = str_replace(['{field}', '{id}'], [$linkLabel, (string)(int)$entryId], $this->cms->snippet('record.note_import_embedded_missing'));
                    continue;
                }
                if ($destId > 0) {
                    // existing content: check out, edge to the working copy
                    $used[$destId] = true;
                    if (!$destData = $this->record->load($destId)) throw new \RuntimeException('#load embedded');
                    if (!$force && ($lock = $this->service->lockInfo($destData))) {
                        $this->cms->error($linkLabel . ' #' . ($index + 1) . $this->cms->snippet('colon') . $this->service->lockMessage($lock));
                        throw new \RuntimeException('#embedded locked');
                    }
                    if (!$destTmpId = $this->record->checkOut($destData)) throw new \RuntimeException('#checkout embedded');
                    $this->record->linkRepoint($fieldId, $tmpId, $destId, $destTmpId);
                    $destAreaId = (int)$destData['area_id'];
                    $isNew = false;
                }
                else {
                    // new content: working copy without original in the target area
                    $destAreaId = (int)($entry['area_id'] ?? 0);
                    if (!in_array($destAreaId, $targetAreaIds, true)) $destAreaId = (int)($targetAreaIds[0] ?? 0);
                    if (!$destTmpId = $this->record->embedInsert($colDef, $tmpId, $destAreaId, 0, false)) throw new \RuntimeException('#embed insert');
                    $isNew = true;
                }
                // values checked into the working state (as the embedded mask)
                $destSchema = $this->_schemaOf($destAreaId);
                $saveData = ['record_id' => $destTmpId, 'area_id' => $destAreaId];
                $fields = ['area_id'];
                $this->_fieldValues($destSchema, $entry, $this->_languagesOf($destAreaId)['language_ids'], $replace && !$partial, !$isNew, $destTmpId, $saveData, $fields);
                $errorsBefore = array_keys($this->cms->errors);
                $saved = $this->record->importSave(array_values(array_unique($fields)), $saveData, (array)($entry['stored'] ?? []), (array)($entry['prehashed'] ?? []), stayTemporary: true);
                if (!$saved) {
                    // mark field errors of the content: "{link} #n › {field}"
                    foreach (array_keys($this->cms->errors) as $key) {
                        if (is_int($key) || in_array($key, $errorsBefore, true)) continue;
                        $error = $this->cms->errors[$key];
                        $error['label'] = $linkLabel . ' #' . ($index + 1) . ' › ' . $this->cms->textOrSnippet((string)($error['label'] ?? $key));
                        unset($this->cms->errors[$key]);
                        $this->cms->errors["$name.$index.$key"] = $error;
                    }
                    throw new \RuntimeException('#embedded save failed');
                }
                $final[] = $destTmpId;
            }
            if ($partial) continue;
            // detach contents no longer named - the check-in deletes them as orphans
            foreach ($current as $destId) {
                if (!isset($used[$destId])) $this->record->removeRelations($colDef, $tmpId, $destId);
            }
            $this->record->linkReorder($tmpId, $fieldId, $final);
        }
    }

    /**
     * Second pass: check out the (now productive) record again,
     * set links and references with the assigned ids, check in.
     */
    private function _importFinish(array $item, array $job): array
    {
        $prepared = (array)($item['prepared'] ?? []);
        $recordId = (int)($item['id'] ?? 0);
        if ($recordId < 1 || empty($prepared)) return $item;
        $areaId = (int)$prepared['area_id'];
        $schema = $this->_schemaOf($areaId);
        $replace = !empty($prepared['replace']);

        if (!$prodData = $this->record->load($recordId)) {
            $item['status'] = 'failed';
            return $item;
        }
        // the lock is our own (first pass) - check out
        if (!$tmpId = $this->record->checkOut($prodData)) {
            $item['status'] = 'failed';
            return $item;
        }
        try {
            // links
            // --------------------
            $relations = (array)$prepared['relations'];
            foreach ($schema as $name => $colDef) {
                if (!in_array((string)($colDef['type'] ?? ''), $this->cms->relationTypes, true)) continue;
                // link fields were handled by the first pass (CSV/DOCX)
                if (!empty($prepared['links_handled']) && in_array((string)$colDef['type'], $this->cms->dependentTypes, true)) continue;
                if (array_key_exists($name, $relations)) {
                    $ids = [];
                    foreach ($this->system->sanitizeIds((array)$relations[$name], true) as $destId) {
                        $ids[] = $this->_mapId('record', (int)$destId);
                    }
                    $ids = array_values(array_unique(array_filter($ids)));
                    if (empty($ids)) $this->record->removeRelations($colDef, $tmpId);
                    else $this->record->createRelations($colDef, $tmpId, $ids, replace: true, checkTmp: false);
                }
                elseif ($replace) {
                    $this->record->removeRelations($colDef, $tmpId);
                }
            }

            // references to other records / templates
            // --------------------
            $saveData = ['record_id' => $tmpId, 'area_id' => $areaId];
            $fields = ['area_id'];
            $stored = (array)$prepared['stored'];
            $movedTo = null;
            foreach ((array)$prepared['meta'] as $name => $value) {
                if ($name === 'parent_id') {
                    if (!isset($schema['parent_id'])) continue;
                    $value = $this->_mapId('record', (int)$value);
                    if ($value > 0 && !$this->_recordInArea($value, $areaId)) {
                        $item['messages'][] = str_replace('{id}', (string)$value, $this->cms->snippet('record.note_import_parent_missing'));
                        continue;
                    }
                    if ($value === (int)($prodData['parent_id'] ?? 0)) continue;
                    // under the parent of the file: at the end of its siblings, in
                    // file order (INSERTION POSITION)
                    $movedTo = $value;
                    $saveData['previous_id'] = $this->_insertPrevious($areaId, $value, $job);
                }
                elseif ($name === 'template_id') {
                    $value = $this->_mapId('template', (int)$value);
                    if (!$this->_templateExists($value)) continue;
                }
                elseif ($name === 'user_id') {
                    $value = $this->_mapId('record', (int)$value);
                    if ($value < 1) continue;
                }
                $saveData[$name] = $value;
                $fields[] = $name;
                if (!in_array($name, $stored, true)) $stored[] = $name;
            }

            // check-in (the field values come from the working state of the working copy)
            // --------------------
            if (!$this->record->importSave(array_values(array_unique($fields)), $saveData, $stored, [])) {
                throw new \RuntimeException('#save failed');
            }
            if ($movedTo !== null) $this->insertAfter[$areaId][$movedTo] = $recordId;
            return $item;
        }
        catch (\Throwable $e) {
            $message = $e->getMessage();
            if (!str_starts_with($message, '#')) $this->cms->error($message);
            $this->_cancelKeepErrors($tmpId);
            $item['status'] = 'failed';
            $item['messages'][] = $this->cms->snippet('record.note_import_relations_failed');
            return $item;
        }
    }

    /**
     * Insertion position of a new record (INSERTION POSITION): parent from the
     * job (only areas with a tree) and the predecessor on this level.
     *
     * @return array|null  ['parent_id' => int, 'previous_id' => int]; null if the
     *                     area has neither a tree nor manual sorting
     */
    private function _insertPlacement(int $areaId, array $schema, array $job): ?array
    {
        $tree = isset($schema['parent_id']);
        if (!$tree && !isset($schema['position'])) return null;
        $parentId = $tree ? (int)($job['parent_id'] ?? 0) : 0;
        if ($parentId > 0 && !$this->_recordInArea($parentId, $areaId)) $parentId = 0;
        return ['parent_id' => $parentId, 'previous_id' => $this->_insertPrevious($areaId, $parentId, $job)];
    }

    /**
     * Predecessor of the next new record under a parent: the one most recently
     * inserted there, before that the previous_id of the job (on the level of the
     * job parent), otherwise the last existing record of the level.
     */
    private function _insertPrevious(int $areaId, int $parentId, array $job): int
    {
        if (!isset($this->insertAfter[$areaId][$parentId])) {
            if ($parentId === (int)($job['parent_id'] ?? 0) && array_key_exists('previous_id', $job)) {
                $previousId = (int)$job['previous_id'];
                if ($previousId > 0 && !$this->_recordInArea($previousId, $areaId)) $previousId = 0;
            }
            else {
                $this->db->pushResult();
                $this->db->query(
                    "SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `parent_id` <=> ? AND `tmp` = 0 AND `deleted` = 0"
                    . " ORDER BY `position` DESC, `record_id` DESC LIMIT 1",
                    'ii', [$areaId, $parentId > 0 ? $parentId : null]
                );
                $previousId = (int)($this->db->fetchNum()[0] ?? 0);
                $this->db->popResult();
            }
            $this->insertAfter[$areaId][$parentId] = $previousId;
        }
        return (int)$this->insertAfter[$areaId][$parentId];
    }

    /**
     * Set created_at / created_by / owner_id (ORIGIN_FIELDS) of a newly created
     * record or template from the file. The admin ids only with
     * $restoreAdmins (file from this database resp. CSV) and only if the admin
     * exists - otherwise the session admin remains; changed_by always remains it.
     * Templates have no owner_id (only columns that $table knows).
     */
    private function _restoreCreated(string $table, string $idField, int $id, array $source, bool $restoreAdmins): void
    {
        if ($id < 1) return;
        $set = [];
        $types = '';
        $values = [];
        $createdAt = trim((string)($source['created_at'] ?? ''));
        if ($createdAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $createdAt) && !str_starts_with($createdAt, '0000') && !str_starts_with($createdAt, '1000')) {
            $set[] = '`created_at` = ?';
            $types .= 's';
            $values[] = $createdAt;
        }
        if ($restoreAdmins) {
            foreach (self::ORIGIN_FIELDS as $name) {
                if ($name === 'owner_id' && $table !== 'record') continue;
                $adminId = (int)($source[$name] ?? 0);
                if ($adminId < 1) continue;
                $this->db->pushResult();
                $this->db->query("SELECT 1 FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
                $exists = $this->db->num_rows > 0;
                $this->db->popResult();
                if (!$exists) continue;
                $set[] = "`{$name}` = ?";
                $types .= 'i';
                $values[] = $adminId;
            }
        }
        if (empty($set)) return;
        $types .= 'i';
        $values[] = $id;
        $this->db->pushResult();
        $this->db->query("UPDATE `$table` SET " . implode(', ', $set) . " WHERE `$idField` = ?", $types, $values);
        $this->db->popResult();
    }

    /**
     * Find a record in the area via the field `slug` (update criterion 'slug'):
     * the value of the primary language in data_chr. 0 = no field `slug` or no match.
     */
    private function _recordBySlug(int $areaId, array $values, int $primaryId): int
    {
        $schema = $this->_schemaOf($areaId);
        $colDef = $schema['slug'] ?? null;
        if ($colDef === null || (string)($colDef['type'] ?? '') !== 'chars') return 0;
        $value = $values['slug'] ?? '';
        if (is_array($value)) $value = (string)($value[$primaryId] ?? reset($value) ?: '');
        $value = trim((string)$value);
        if ($value === '') return 0;
        $this->db->pushResult();
        $this->db->query(
            "SELECT `d`.`record_id` FROM `data_chr` `d` INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
            . " WHERE `d`.`field_id` = ? AND `d`.`data` = ? AND `r`.`area_id` = ? AND `r`.`tmp` = 0 AND `r`.`deleted` = 0 ORDER BY `d`.`language_id` = ? DESC LIMIT 1",
            'isii', [(int)$colDef['field_id'], $value, $areaId, $primaryId]
        );
        $id = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        return $id;
    }

    /**
     * Assigned id: from the mapping table, otherwise unchanged (a record,
     * area or template that was not in the file counts as existing).
     */
    private function _mapId(string $kind, int $id): int
    {
        if (isset($this->map[$kind][$id])) return (int)$this->map[$kind][$id];
        // templates that were not imported: via name and assigned area
        if ($kind === 'template' && isset($this->fileTemplates[$id])) {
            $fileAreaId = (int)$this->fileTemplates[$id]['area_id'];
            $areaId = (int)($this->map['area'][$fileAreaId] ?? $fileAreaId);
            $found = $this->_resolveTemplate(['name' => $this->fileTemplates[$id]['name'], 'template_id' => $id], $areaId, 'name');
            if ($found > 0) {
                $this->map['template'][$id] = $found;
                return $found;
            }
        }
        return $id;
    }

    /**
     * Discard the working copy, keep the collected messages (cancel() does not clear
     * Cms::errors, but an error there would otherwise be lost in the log).
     */
    private function _cancelKeepErrors(int $tmpId): void
    {
        if ($tmpId < 1) return;
        $errors = $this->cms->errors;
        try {
            $this->record->cancel($tmpId);
        }
        catch (\Throwable $e) {
        }
        $this->cms->errorsReset();
        foreach ($errors as $key => $error) {
            $this->cms->error((string)$error['text'], is_int($key) ? '' : (string)$key, (string)($error['label'] ?? ''));
        }
    }

    private function _recordInArea(int $recordId, int $areaId, bool $orTmp = false): bool
    {
        $this->db->pushResult();
        // $orTmp: in the second pass a new parent record is still a
        // working copy under its final id
        $tmpCondition = $orTmp ? "AND `deleted` = 0" : "AND `tmp` = 0 AND `deleted` = 0";
        $this->db->query("SELECT 1 FROM `record` WHERE `record_id` = ? AND `area_id` = ? $tmpCondition", 'ii', [$recordId, $areaId]);
        $found = $this->db->num_rows > 0;
        $this->db->popResult();
        return $found;
    }

    // ====================
    // Blueprints (Core/Modules/Area/blueprints/{type}/*.xml)

    /**
     * Read the blueprint of an area: an XML file in export format with exactly one
     * <area> (settings and fields, no records). AreaBackend uses it to pre-fill
     * the input mask of a new area (create()) and creates the fields after
     * saving (importBlueprint()).
     *
     * @return array|null  area as from _xmlStructure() (with 'fields'), null if
     *                     the file is not readable or contains no area
     */
    public function readBlueprint(string $path): ?array
    {
        if (!is_file($path)) return null;
        [$areas] = $this->_xmlStructure($path);
        if (empty($areas)) return null;
        return reset($areas);
    }

    /**
     * Create the fields of the blueprint in an (just created) area and set the
     * field references of the area (default_field_id, sort_field_id,
     * login_name_field_id, login_password_field_id). Relations and pointers to
     * other areas of the blueprint cannot be resolved - only references to the
     * area itself. Errors end up in Cms::errors.
     *
     * @return int  number of fields created, -1 if the blueprint is not readable
     */
    public function importBlueprint(string $path, int $areaId): int
    {
        if (!is_file($path) || $areaId < 1) return -1;
        [$areas, , $converters, $this->fileSnippets, $this->fileLanguages] = $this->_xmlStructure($path);
        $this->pendingSnippets = [];
        if (empty($areas)) return -1;
        $area = reset($areas);
        $fileAreaId = (int)$area['area_id'];
        $this->map = ['area' => [$fileAreaId => $areaId], 'field' => [], 'template' => [], 'record' => [], 'converter' => []];
        $this->fileTemplates = [];
        $this->savedTemplates = [];
        $this->pendingTriggers = [];
        $this->fileAreas = [$fileAreaId => ['name' => (string)$area['name'], 'type' => (string)$area['type']]];
        $this->schemaMemo = [];
        // resolve snippet references (#module.key) of the fields in the language of the admin -
        // except custom snippets whose texts the file brings along (copy of an
        // area, areaBlueprintFile()): _saveField() takes those over as a reference
        foreach ($area['fields'] as &$field) {
            foreach (['label', 'note', 'tooltip'] as $col) {
                if (!isset($field[$col]) || !str_starts_with((string)$field[$col], '#')) continue;
                if (preg_match(Snippets::REFERENCE_PATTERN, (string)$field[$col], $m) && isset($this->fileSnippets[(int)$m[1]][$m[2]])) continue;
                $field[$col] = $this->cms->textOrSnippet((string)$field[$col]);
            }
        }
        unset($field);
        $result = ['total' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0, 'inserted' => 0, 'updated' => 0, 'replaced' => 0, 'items' => []];
        // converters of the thumbnail fields: blueprints are foreign - matches by hash,
        // otherwise create, skip without processor (CONVERTERS)
        $this->sameDb = false;
        $used = $this->_usedConverterIds([$fileAreaId => $area], [$fileAreaId]);
        $this->_importConverters(array_intersect_key($this->converterPlan($converters, []), $used), $result);
        $count = $this->_importFields([$fileAreaId => $area], [$fileAreaId => 'insert'], $result);
        $this->_importSnippets($result);
        $messages = [];
        foreach ($result['items'] as $item) {
            if ($item['status'] !== 'failed') continue;
            $messages[] = htmlspecialchars((string)$item['label']) . $this->cms->snippet('colon') . htmlspecialchars(implode(' ', (array)$item['messages']));
        }
        $this->cms->errorsReset();
        try {
            $this->_saveAreaReferences($area, $areaId);
            $triggerResult = ['items' => []];
            $this->_applyTriggerTemplates($triggerResult);
            foreach ($triggerResult['items'] as $item) $messages = array_merge($messages, (array)$item['messages']);
        }
        catch (\Throwable $e) {
            $message = $e->getMessage();
            if (!str_starts_with($message, '#')) $this->cms->error($message);
            $messages = array_merge($messages, $this->_errorTexts());
        }
        $this->schemaMemo = [];
        $this->cms->clearFieldCaches();
        $this->cms->errorsReset();
        foreach ($messages as $message) $this->cms->error($message);
        return $count;
    }

    // ====================
    // Structure and templates (XML, global)

    /**
     * Import the areas, fields and templates of the file (see class comment,
     * "two passes"). Without a global job only the assignments of the
     * areas of the file to existing areas are filled (for the links).
     *
     * @return array  ['areas' => n, 'converters' => n, 'fields' => n, 'templates' => n]
     */
    private function _importStructure(string $path, array $job, array &$result): array
    {
        $summary = ['areas' => 0, 'converters' => 0, 'fields' => 0, 'templates' => 0];
        [$areas, $templates, $converters, $this->fileSnippets, $this->fileLanguages] = $this->_xmlStructure($path);
        $this->pendingSnippets = [];
        $isGlobal = !empty($job['global']);
        $settings = $isGlobal ? (array)($job['areas'] ?? []) : [];
        $folderId = (int)($job['folder_id'] ?? 0);
        $instanceId = (int)($job['instance_id'] ?? 0);
        // target level and instance match (the mask only offers the folders of the
        // chosen instance) - a folder of another instance does not count
        if ($instanceId > 0 && $folderId > 0 && (int)($this->_areaOf($folderId)['instance_id'] ?? 0) !== $instanceId) {
            $folderId = 0;
        }
        foreach ($templates as $template) {
            $this->fileTemplates[(int)$template['template_id']] = ['name' => (string)$template['name'], 'area_id' => (int)$template['area_id']];
        }
        foreach ($areas as $fileAreaId => $area) {
            $this->fileAreas[$fileAreaId] = ['name' => (string)$area['name'], 'type' => (string)$area['type'], 'default_field' => $this->_fileFieldName($area, (int)($area['default_field_id'] ?? 0))];
        }
        // deviating area names from the mask
        foreach ($areas as $fileAreaId => &$area) {
            $override = trim((string)($settings[$fileAreaId]['name'] ?? ''));
            if ($override !== '' && preg_match('/^[0-9a-z_\-]+$/', $override)) $area['name'] = $override;
        }
        unset($area);

        // 1. areas (parents before children)
        // --------------------
        $imported = []; // file area_id => mode with which the area was written
        foreach ($areas as $fileAreaId => $area) {
            $areaSettings = (array)($settings[$fileAreaId] ?? []);
            $mode = $isGlobal ? (string)($areaSettings['structure'] ?? 'ignore') : 'ignore';
            $by = (string)($areaSettings['structure_by'] ?? 'name');
            if (!in_array($mode, self::GLOBAL_MODES, true)) $mode = 'ignore';

            // existing area by criterion (the id only from the same database)
            $existingId = ($by === 'area_id' && !empty($job['same_db']))
                ? $this->_resolveArea($fileAreaId, '', (string)$area['type'])
                : $this->_resolveArea(0, (string)$area['name'], (string)$area['type']);
            if ($mode === 'ignore') {
                // do not touch - only assign, so that records and templates
                // can go into it (same name, otherwise - from the same database - same id)
                $target = $this->_resolveArea(0, (string)$area['name'], (string)$area['type']);
                if ($target < 1 && !empty($job['same_db'])) $target = $this->_resolveArea($fileAreaId, '', (string)$area['type']);
                if ($target > 0) $this->map['area'][$fileAreaId] = $target;
                continue;
            }
            $item = ['kind' => 'area', 'id' => $fileAreaId, 'label' => (string)$area['label'] . ' (' . $area['name'] . ')', 'status' => 'failed', 'messages' => []];
            $this->cms->errorsReset();
            $update = $existingId > 0 && $mode !== 'insert';
            if (!$update && !self::modeCreates($mode)) {
                $item['status'] = 'skipped';
                $item['messages'][] = $this->cms->snippet('record.note_import_not_found');
                $this->_addItem($result, $item);
                continue;
            }
            try {
                // insert always creates anew - even if there is an area of the same name
                $areaId = $this->_saveArea($area, $update ? $existingId : 0, $mode, $folderId, $instanceId, $item['messages']);
                $this->map['area'][$fileAreaId] = $areaId;
                $imported[$fileAreaId] = $mode;
                $item['id'] = $areaId;
                $item['status'] = $update ? (self::modeReplaces($mode) ? 'replaced' : 'updated') : 'inserted';
                $summary['areas']++;
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $item['messages'] = array_merge($item['messages'], $this->_errorTexts());
            }
            $this->_addItem($result, $item);
        }

        // 2. converters of the thumbnail fields, then the fields of the written
        //    content areas
        // --------------------
        if (!empty($imported)) {
            $used = $this->_usedConverterIds($areas, array_keys($imported));
            $plan = array_intersect_key($this->converterPlan($converters, $job), $used);
            $summary['converters'] = $this->_importConverters($plan, $result);
            $summary['fields'] = $this->_importFields($areas, $imported, $result);
        }
        // custom snippets of the labels into the sets of the target instances
        $this->_importSnippets($result);

        // 3. templates
        // --------------------
        $this->savedTemplates = []; // template_id (new) => data of the file (code replacements at the end)
        foreach ($templates as $template) {
            $fileAreaId = (int)$template['area_id'];
            $areaSettings = (array)($settings[$fileAreaId] ?? []);
            $mode = $isGlobal ? (string)($areaSettings['templates'] ?? 'ignore') : 'ignore';
            if ($mode === 'ignore' || !in_array($mode, self::GLOBAL_MODES, true)) continue;
            $by = (string)($areaSettings['templates_by'] ?? 'name');
            $item = ['kind' => 'template', 'id' => (int)$template['template_id'], 'label' => (string)$template['label'] . ' (' . $template['name'] . ')', 'status' => 'failed', 'messages' => []];
            $this->cms->errorsReset();
            $areaId = (int)($this->map['area'][$fileAreaId] ?? 0);
            if ($areaId < 1) {
                $item['messages'][] = $this->cms->snippet('area.error_area_not_found');
                $this->_addItem($result, $item);
                continue;
            }
            $existingId = $this->_resolveTemplate($template, $areaId, $by);
            $update = $existingId > 0 && $mode !== 'insert';
            if (!$update && !self::modeCreates($mode)) {
                $item['status'] = 'skipped';
                $item['messages'][] = $this->cms->snippet('record.note_import_not_found');
                $this->_addItem($result, $item);
                continue;
            }
            try {
                $templateId = $this->_saveTemplate($template, $areaId, $existingId);
                $this->map['template'][(int)$template['template_id']] = $templateId;
                $this->_queueTemplateSnippets((string)$template['code'], (int)($areas[$fileAreaId]['instance_id'] ?? 0), (int)($this->_areaOf($areaId)['instance_id'] ?? 0));
                if (!$update) $this->_restoreCreated('template', 'template_id', $templateId, $template, !empty($job['same_db']));
                $this->savedTemplates[$templateId] = $template;
                $item['id'] = $templateId;
                $item['status'] = $update ? (self::modeReplaces($mode) ? 'replaced' : 'updated') : 'inserted';
                $summary['templates']++;
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $item['messages'] = array_merge($item['messages'], $this->_errorTexts());
            }
            $this->_addItem($result, $item);
        }
        // custom snippets that the templates address (short form custom.{key})
        $this->_importSnippets($result);

        // 4. areas, second pass: default/sort field, templates
        // --------------------
        foreach ($imported as $fileAreaId => $mode) {
            $this->cms->errorsReset();
            try {
                $this->_saveAreaReferences($areas[$fileAreaId], (int)$this->map['area'][$fileAreaId]);
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $this->_addItem($result, ['kind' => 'area', 'id' => (int)$this->map['area'][$fileAreaId], 'label' => (string)$areas[$fileAreaId]['label'], 'status' => 'failed', 'messages' => $this->_errorTexts()]);
            }
        }
        $this->schemaMemo = [];
        $this->cms->clearFieldCaches();
        return $summary;
    }

    /**
     * Read the <areas>, <templates>, <converters> and <snippets> of the file, plus
     * the languages of the header.
     *
     * @return array  [areas (area_id => data, tree order), templates (list),
     *                 converters (converter_id of the file => data),
     *                 snippets (instance_id => [key => [lang => text]]),
     *                 languages (language_id of the file => code)]
     */
    private function _xmlStructure(string $path): array
    {
        $areas = [];
        $templates = [];
        $converters = [];
        $snippets = [];
        $languages = [];
        $reader = new \XMLReader();
        if (!@$reader->open($path)) return [$areas, $templates, $converters, $snippets, $languages];
        while (@$reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) continue;
            // the records are not needed here: skip the whole block
            if ($reader->depth === 1 && $reader->name === 'records') {
                $reader->next();
                continue;
            }
            if ($reader->depth === 1 && $reader->name === 'header') {
                $languages = (array)($this->_xmlHeader($this->_readerNode($reader))['languages'] ?? []);
                $reader->next();
                continue;
            }
            if ($reader->depth !== 2) continue;
            if ($reader->name === 'area') {
                $node = $this->_readerNode($reader);
                $area = [];
                foreach ($node->attributes() as $key => $value) $area[(string)$key] = (string)$value;
                $area['label'] = trim((string)$node->label);
                $area['note'] = trim((string)$node->note);
                $area['details'] = json_decode((string)$node->details, true) ?: [];
                $area['access'] = [];
                foreach ($node->access as $access) {
                    $area['access'][] = ['template_id' => (int)$access['template_id'], 'selectable' => (int)$access['selectable'], 'override' => (int)$access['override']];
                }
                // trigger templates in their order (<triggers><trigger template_id/>…);
                // without the block the state of the target area remains
                if (isset($node->triggers)) {
                    $area['trigger_template_ids'] = [];
                    foreach ($node->triggers->trigger ?? [] as $trigger) $area['trigger_template_ids'][] = (int)$trigger['template_id'];
                }
                $area['fields'] = [];
                foreach ($node->fields->field ?? [] as $fieldNode) {
                    $field = [];
                    foreach ($fieldNode->attributes() as $key => $value) $field[(string)$key] = (string)$value;
                    foreach (['label', 'note', 'tooltip', 'pattern', 'default', 'details'] as $col) {
                        if (isset($fieldNode->{$col})) $field[$col] = (string)$fieldNode->{$col};
                    }
                    $field['rel_area_ids'] = [];
                    foreach ($fieldNode->rel_area_ids->area_id ?? [] as $relAreaId) $field['rel_area_ids'][] = (int)$relAreaId;
                    $field['source_fields'] = [];
                    // via a link field: attribute rel_field_id (key as Field::sourceKey())
                    foreach ($fieldNode->source_fields->field_id ?? [] as $sourceNode) {
                        $field['source_fields'][] = Field::sourceKey((int)$sourceNode, (int)($sourceNode['rel_field_id'] ?? 0));
                    }
                    // converters of a thumbnail field in their order; older
                    // files (up to 2026-09-15) carry them in details.converter_ids
                    $field['converter_ids'] = [];
                    if (isset($fieldNode->converter_ids)) {
                        foreach ($fieldNode->converter_ids->converter_id ?? [] as $converterId) $field['converter_ids'][] = (int)$converterId;
                    }
                    else {
                        $details = json_decode((string)($field['details'] ?? '{}'), true) ?: [];
                        $field['converter_ids'] = array_map('intval', (array)($details['converter_ids'] ?? []));
                    }
                    $area['fields'][(int)$field['field_id']] = $field;
                }
                $areas[(int)$area['area_id']] = $area;
                $reader->next();
                continue;
            }
            if ($reader->name === 'template') {
                $node = $this->_readerNode($reader);
                $template = [];
                foreach ($node->attributes() as $key => $value) $template[(string)$key] = (string)$value;
                $template['label'] = trim((string)$node->label);
                $template['code'] = (string)$node->code;
                $templates[] = $template;
                $reader->next();
                continue;
            }
            if ($reader->name === 'converter') {
                $converter = $this->_xmlConverter($this->_readerNode($reader));
                $converters[(int)$converter['converter_id']] = $converter;
                $reader->next();
                continue;
            }
            if ($reader->name === 'snippet') {
                $node = $this->_readerNode($reader);
                $instanceId = (int)$node['instance_id'];
                $key = (string)$node['key'];
                if ($instanceId > 0 && $this->snippets->validKey($key)) {
                    foreach ($node->text as $text) {
                        $lang = (string)$text['lang'];
                        if ($lang !== '' && (string)$text !== '') $snippets[$instanceId][$key][$lang] = (string)$text;
                    }
                }
                $reader->next();
                continue;
            }
        }
        $reader->close();
        return [$areas, $templates, $converters, $snippets, $languages];
    }

    /**
     * A <converter> of the file (RecordExport::_xmlConverters()).
     *
     * @return array  converter_id, hash, processor, type, extension, label,
     *                media_types_incl, media_types_excl, details (Array)
     */
    private function _xmlConverter(\SimpleXMLElement $node): array
    {
        $converter = [];
        foreach ($node->attributes() as $key => $value) $converter[(string)$key] = (string)$value;
        $converter['converter_id'] = (int)($converter['converter_id'] ?? 0);
        $converter['hash'] = (string)($converter['hash'] ?? '');
        $converter['processor'] = $this->converter->cleanProcessorName((string)($converter['processor'] ?? ''));
        $converter['extension'] = (string)($converter['extension'] ?? '');
        $converter['label'] = trim((string)$node->label);
        $converter['media_types_incl'] = (string)$node->media_types_incl;
        $converter['media_types_excl'] = (string)$node->media_types_excl;
        $converter['details'] = json_decode((string)$node->details, true) ?: [];
        // The hash is calculated from the settings of the file, not taken from the
        // attribute: a manually edited file (blueprint, template set) stays
        // consistent this way - the attribute serves readability
        $converter['hash'] = Converter::settingsHash($converter);
        return $converter;
    }

    // ====================
    // Converters of the thumbnail fields (CONVERTERS)

    /**
     * Matches of the converters of the file in the target database - for mask and plan.
     *
     * @param array $converters  converter_id of the file => data (_xmlConverter())
     * @param bool  $sameDb      file comes from this database: the id counts
     * @return array  converter_id of the file => data + 'installed' (processor),
     *                'match' ('id' | 'hash' | ''), 'match_id' (converter_id, 0)
     */
    public function converterMatches(array $converters, bool $sameDb): array
    {
        $result = [];
        foreach ($converters as $fileId => $converter) {
            $fileId = (int)$fileId;
            $converter['installed'] = $this->converter->isProcessorInstalled((string)$converter['processor']);
            $converter['match'] = '';
            $converter['match_id'] = 0;
            if ($sameDb && $fileId > 0 && $this->_converterExists($fileId)) {
                $converter['match'] = 'id';
                $converter['match_id'] = $fileId;
            }
            else {
                $found = $this->converter->findByHash((string)$converter['hash']);
                if ($found > 0) {
                    $converter['match'] = 'hash';
                    $converter['match_id'] = $found;
                }
            }
            $result[$fileId] = $converter;
        }
        return $result;
    }

    /**
     * What happens to each converter of the file: the decision of the mask
     * (job 'converters'), otherwise automatically - link matches, otherwise create
     * if the processor is installed, otherwise ignore.
     *
     * @return array  converter_id of the file => data (converterMatches()) +
     *                'action' ('link' | 'create' | 'ignore'), 'converter_id' (link)
     */
    public function converterPlan(array $converters, array $job): array
    {
        $plan = [];
        $choices = (array)($job['converters'] ?? []);
        foreach ($this->converterMatches($converters, !empty($job['same_db'])) as $fileId => $converter) {
            $choice = $choices[$fileId] ?? 'auto';
            $converter['action'] = 'ignore';
            $converter['converter_id'] = 0;
            if (is_numeric($choice) && (int)$choice > 0 && $this->_converterExists((int)$choice)) {
                $converter['action'] = 'link';
                $converter['converter_id'] = (int)$choice;
            }
            elseif ($choice === 'new') {
                if ($converter['installed']) $converter['action'] = 'create';
            }
            elseif ($choice !== 'ignore') {
                // automatic
                if ((int)$converter['match_id'] > 0) {
                    $converter['action'] = 'link';
                    $converter['converter_id'] = (int)$converter['match_id'];
                }
                elseif ($converter['installed']) $converter['action'] = 'create';
            }
            $plan[$fileId] = $converter;
        }
        return $plan;
    }

    /**
     * Assign resp. create the converters of the file (before the fields) and
     * fill $this->map['converter'] - 0 for ignored ones. Created converters
     * go through Converter::save() (processor hooks, check of the settings).
     *
     * @param array $plan  converterPlan(), if applicable limited to the converters used
     * @return int  number of created converters
     */
    private function _importConverters(array $plan, array &$result): int
    {
        $count = 0;
        foreach ($plan as $fileId => $converter) {
            $fileId = (int)$fileId;
            $label = (string)$converter['label'] . ' (' . (string)$converter['processor'] . ')';
            if ($converter['action'] === 'link') {
                $this->map['converter'][$fileId] = (int)$converter['converter_id'];
                continue;
            }
            if ($converter['action'] !== 'create') {
                $this->map['converter'][$fileId] = 0;
                $this->_addItem($result, ['kind' => 'converter', 'id' => $fileId, 'label' => $label, 'status' => 'skipped', 'messages' => [
                    str_replace('{processor}', (string)$converter['processor'], $this->cms->snippet($converter['installed'] ? 'record.note_import_converter_ignored' : 'record.note_import_converter_not_installed')),
                ]]);
                continue;
            }
            $item = ['kind' => 'converter', 'id' => $fileId, 'label' => $label, 'status' => 'failed', 'messages' => []];
            $this->cms->errorsReset();
            try {
                $data = $this->converter->init(['processor' => (string)$converter['processor']]);
                if (!is_array($data)) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
                foreach (['label', 'extension', 'media_types_incl', 'media_types_excl'] as $col) {
                    $data[$col] = (string)($converter[$col] ?? '');
                }
                $data['details'] = array_replace((array)($data['details'] ?? []), (array)($converter['details'] ?? []));
                $data['converter_id'] = 0;
                $converterId = (int)$this->converter->save([], $data);
                if ($converterId < 1) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
                $this->map['converter'][$fileId] = $converterId;
                $item['id'] = $converterId;
                $item['status'] = 'inserted';
                $count++;
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $item['messages'] = array_merge($item['messages'], $this->_errorTexts());
                $this->map['converter'][$fileId] = 0;
            }
            $this->_addItem($result, $item);
        }
        return $count;
    }

    /**
     * Converters that the thumbnail fields of these areas of the file refer to.
     *
     * @param array $areas        areas of the file (_xmlStructure())
     * @param array $fileAreaIds  area_ids of the file whose fields are written
     * @return array  converter_ids of the file (keys)
     */
    private function _usedConverterIds(array $areas, array $fileAreaIds): array
    {
        $used = [];
        foreach ($fileAreaIds as $fileAreaId) {
            foreach ((array)($areas[$fileAreaId]['fields'] ?? []) as $field) {
                if (!$this->_isThumbnail($field)) continue;
                foreach ((array)($field['converter_ids'] ?? []) as $id) {
                    if ((int)$id > 0) $used[(int)$id] = true;
                }
            }
        }
        return $used;
    }

    /**
     * Map the converter_ids of a thumbnail field of the file (in their order) to the
     * target database: via $this->map['converter']; converters not carried in the
     * file (older exports) apply only from the same database and only
     * if they exist.
     *
     * In the analysis, -1 stands in the assignment for a converter still to be created
     * (analyze()) - it counts as existing, the ids are not written there.
     *
     * @return array  converter_ids, empty if none remains
     */
    private function _mapConverterIds(array $field, bool $sameDb): array
    {
        $ids = [];
        foreach ((array)($field['converter_ids'] ?? []) as $id) {
            $id = (int)$id;
            if ($id < 1) continue;
            if (isset($this->map['converter'][$id])) $mapped = (int)$this->map['converter'][$id];
            else $mapped = ($sameDb && $this->_converterExists($id)) ? $id : 0;
            if ($mapped !== 0) $ids[$mapped] = $mapped;
        }
        return array_values($ids);
    }

    private function _converterExists(int $converterId): bool
    {
        if ($converterId < 1) return false;
        $this->db->pushResult();
        $this->db->query("SELECT `converter_id` FROM `converter` WHERE `converter_id` = ?", 'i', [$converterId]);
        $found = (int)($this->db->fetchNum()[0] ?? 0) > 0;
        $this->db->popResult();
        return $found;
    }

    /**
     * Create resp. write an area (first pass: without field and
     * template references). New areas arise under the assigned parent of the
     * file, otherwise under the target level; the instance comes from the parent.
     *
     * @return int  area_id
     */
    private function _saveArea(array $area, int $existingId, string $mode, int $folderId, int $chosenInstanceId = 0, array &$messages = []): int
    {
        $fileParentId = (int)($area['parent_id'] ?? 0);
        $details = (array)($area['details'] ?? []);
        // field and area references only in the second pass (the login fields of
        // older files still stand in details)
        unset($details['login_name_field_id'], $details['login_password_field_id']);
        $details['profile_area_ids'] = array_values(array_filter(array_map(
            fn($id) => $this->_mapId('area', (int)$id), (array)($details['profile_area_ids'] ?? [])
        )));
        $data = [
            'type' => (string)$area['type'],
            'name' => (string)$area['name'],
            'label' => (string)$area['label'],
            'icon' => (string)($area['icon'] ?? ''),
            'note' => (string)($area['note'] ?? ''),
            'sort_order' => (string)($area['sort_order'] ?? 'DESC') ?: 'DESC',
            'dependent' => (int)!empty($area['dependent']),
            'details' => $details,
        ];
        if ($existingId > 0) {
            $previous = $this->area->load($existingId);
            if (!$previous) throw new \RuntimeException('#' . $this->cms->snippet('area.error_area_not_found'));
            if ((string)$previous['type'] !== $data['type']) {
                $this->cms->error(str_replace('{type}', (string)$previous['type'], $this->cms->snippet('record.error_import_area_type')));
                throw new \RuntimeException('#type');
            }
            $save = $previous;
            if (!self::modeReplaces($mode)) {
                // update: only label, icon, note, sorting, details
                unset($data['type'], $data['name']);
            }
            foreach ($data as $key => $value) $save[$key] = $value;
            $save['area_id'] = $existingId;
            $this->_mapAreaTexts($save, (int)$previous['instance_id'], $existingId, $messages);
            // file without slug prefix (also older exports): the existing one remains
            if (!array_key_exists('slug_prefix', (array)$save['details']) && !empty($previous['details']['slug_prefix'])) {
                $save['details']['slug_prefix'] = $previous['details']['slug_prefix'];
            }
            // deletion policy: from the same database that of the file, otherwise that of the
            // target area remains (policy ids are assigned per database)
            if ($this->sameDb && (int)($area['privacy_policy_id'] ?? 0) > 0) {
                $save['privacy_policy_id'] = (int)$area['privacy_policy_id'];
            }
            // template assignments remain (Area::hookOnSaved() replaces only those passed),
            // until _saveAreaReferences() sets them
            // keep the place in the tree: without previous_id the area would move to the beginning
            $save['previous_id'] = $this->_areaPreviousId($previous);
            if (!$this->area->save([], $save)) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
            return $existingId;
        }
        // new: under the assigned parent of the file, otherwise the target level. Instance:
        // from the imported parent; otherwise the chosen one, otherwise that of the
        // target level, otherwise the one from the file (if it exists), otherwise the first
        $mappedParentId = $fileParentId > 0 ? (int)($this->map['area'][$fileParentId] ?? 0) : 0;
        $parentId = $mappedParentId > 0 ? $mappedParentId : $folderId;
        $instanceId = 0;
        if ($mappedParentId > 0) {
            $instanceId = (int)($this->_areaOf($mappedParentId)['instance_id'] ?? 0);
        }
        if ($instanceId < 1 && $chosenInstanceId > 0 && $this->instanceCache->instance($chosenInstanceId) !== null) {
            $instanceId = $chosenInstanceId;
        }
        if ($instanceId < 1 && $folderId > 0) {
            $instanceId = (int)($this->_areaOf($folderId)['instance_id'] ?? 0);
        }
        if ($instanceId < 1) $instanceId = (int)($area['instance_id'] ?? 0);
        if ($instanceId < 1 || $this->instanceCache->instance($instanceId) === null) {
            $instanceId = (int)array_key_first($this->instanceCache->all()) ?: 1;
        }
        if ($parentId > 0 && !$this->_areaOf($parentId)) {
            throw new \RuntimeException('#' . $this->cms->snippet('area.error_area_not_found'));
        }
        $data['area_id'] = 0;
        $data['instance_id'] = $instanceId;
        $data['parent_id'] = $parentId > 0 ? $parentId : 0;
        $data['default_field_id'] = 0;
        $data['sort_field_id'] = 0;
        $data['default_template_id'] = 0;
        $data['list_template_id'] = 0;
        $data['overview_template_id'] = 0;
        // deletion policy: from the same database that of the file; from a foreign one the
        // default policy of the area type (0 - Area::hookOnSave() sets it, likewise
        // for an id that does not exist here or not for the instance)
        $data['privacy_policy_id'] = $this->sameDb ? (int)($area['privacy_policy_id'] ?? 0) : 0;
        // keep the order of the file: after the most recently created sibling,
        // otherwise after the last existing one (without previous_id every new
        // area would move to the beginning of its level)
        if (!isset($this->lastSibling[$parentId])) {
            $this->db->pushResult();
            $this->db->query(
                "SELECT `area_id` FROM `area` WHERE `parent_id` <=> ? AND `deleted` = 0 ORDER BY `position` DESC LIMIT 1",
                'i', [$parentId > 0 ? $parentId : null]
            );
            $this->lastSibling[$parentId] = (int)($this->db->fetchNum()[0] ?? 0);
            $this->db->popResult();
        }
        $data['previous_id'] = (int)$this->lastSibling[$parentId];
        $this->_mapAreaTexts($data, $instanceId, 0, $messages);
        $areaId = $this->area->save([], $data);
        if (!$areaId) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
        $this->lastSibling[$parentId] = (int)$areaId;
        return (int)$areaId;
    }

    /**
     * Prepare the texts and slug prefix of an area for the target instance (see
     * class comment "Custom snippets and slug prefix"): rewrite snippet references in
     * label and note, map details.slug_prefix to the languages of the
     * target instance and check it - an impermissible prefix is dropped with a note.
     *
     * @param array $data        area data for Area::save() (label, note, details)
     * @param int   $instanceId  target instance
     * @param int   $areaId      existing area (0 = new)
     * @param array $messages    notes for the log
     */
    private function _mapAreaTexts(array &$data, int $instanceId, int $areaId, array &$messages): void
    {
        foreach (['label', 'note'] as $col) {
            if (isset($data[$col])) $data[$col] = $this->_mapSnippetRefs((string)$data[$col], $instanceId);
        }
        $details = (array)($data['details'] ?? []);
        if (!array_key_exists('slug_prefix', $details)) return;
        $raw = is_array($details['slug_prefix']) ? $details['slug_prefix'] : [];
        $mapped = [];
        foreach ($raw as $fileLanguageId => $prefix) {
            $languageId = (int)$fileLanguageId;
            if (!$this->sameDb) {
                $code = (string)($this->fileLanguages[$languageId] ?? '');
                $target = ($code !== '') ? $this->instanceCache->languageByCode($instanceId, $code) : null;
                if ($target === null) continue;
                $languageId = (int)$target['language_id'];
            }
            $mapped[$languageId] ??= $prefix;
        }
        $errors = $this->area->slugPrefixErrors($mapped, $instanceId, $areaId);
        if (!empty($errors)) {
            $messages[] = $this->cms->snippet('record.note_import_slug_prefix_dropped') . ' ' . $this->cms->_strip_tags(implode(' ', array_unique($errors)));
            $mapped = [];
        }
        $data['details']['slug_prefix'] = $mapped;
    }

    /**
     * Rewrite references to custom snippets (#custom.{instance_id}.{key}) to the set of the
     * target instance and mark the snippets for taking over
     * ($pendingSnippets, written by _importSnippets()). Texts from the file; from the
     * same database alternatively from the set of the source instance; a snippet that
     * exists nowhere remains as a reference.
     */
    private function _mapSnippetRefs(string $text, int $targetInstanceId): string
    {
        if ($targetInstanceId < 1 || !str_contains($text, '#custom.')) return $text;
        return preg_replace_callback(Snippets::REFERENCE_PATTERN, function (array $m) use ($targetInstanceId): string {
            $sourceId = (int)$m[1];
            $key = $m[2];
            $texts = $this->fileSnippets[$sourceId][$key] ?? null;
            if ($texts === null && $this->sameDb) {
                $this->fileSnippets[$sourceId] ??= $this->snippets->load($sourceId);
                $texts = $this->fileSnippets[$sourceId][$key] ?? null;
            }
            if ($texts === null) return $m[0];
            $this->pendingSnippets[$targetInstanceId][$key] ??= (array)$texts;
            return '#' . $this->snippets->prefix($targetInstanceId) . '.' . $key;
        }, $text) ?? $text;
    }

    /**
     * Mark custom snippets that an imported template addresses
     * (Snippets::templateRefs()) - texts from the file, from the same installation
     * if need be from the set. The short form custom.{key} applies to the instance of the page,
     * the source text therefore remains unchanged.
     */
    private function _queueTemplateSnippets(string $code, int $sourceInstanceId, int $targetInstanceId): void
    {
        if ($sourceInstanceId < 1 || $targetInstanceId < 1) return;
        $set = $this->fileSnippets[$sourceInstanceId] ?? [];
        if (!$set && $this->sameDb) $set = $this->fileSnippets[$sourceInstanceId] = $this->snippets->load($sourceInstanceId);
        foreach (Snippets::templateRefs($code, $set, $sourceInstanceId) as $key) {
            $this->pendingSnippets[$targetInstanceId][$key] ??= (array)$set[$key];
        }
    }

    /**
     * Write the marked custom snippets into the sets of the target instances
     * (Snippets::merge(): only missing ones) - one log entry per set.
     */
    private function _importSnippets(array &$result): void
    {
        foreach ($this->pendingSnippets as $instanceId => $snippets) {
            $prefix = $this->snippets->prefix((int)$instanceId);
            $existing = $this->snippets->load((int)$instanceId);
            $this->cms->errorsReset();
            $item = ['kind' => 'snippet', 'id' => 0, 'label' => $prefix, 'status' => 'failed', 'messages' => []];
            $added = $this->snippets->merge((int)$instanceId, $snippets);
            if ($added === null) {
                $item['messages'] = $this->_errorTexts();
                $this->_addItem($result, $item);
                continue;
            }
            // existing keys with other text: the set keeps its own
            $kept = [];
            foreach ($snippets as $key => $texts) {
                if (!isset($existing[$key])) continue;
                foreach ((array)$texts as $lang => $text) {
                    if ((string)($existing[$key][$lang] ?? '') !== '' && (string)$existing[$key][$lang] !== (string)$text) {
                        $kept[] = $key;
                        break;
                    }
                }
            }
            if (empty($added) && empty($kept)) continue;
            $item['status'] = empty($added) ? 'skipped' : 'inserted';
            if (!empty($added)) $item['messages'][] = str_replace('{keys}', implode(', ', $added), $this->cms->snippet('record.note_import_snippets_added'));
            if (!empty($kept)) $item['messages'][] = str_replace('{keys}', implode(', ', $kept), $this->cms->snippet('record.note_import_snippets_kept'));
            $this->_addItem($result, $item);
        }
        $this->pendingSnippets = [];
    }

    /**
     * Second pass of an area: default/sort field, default template,
     * template assignments, login fields - all ids that are only fixed now.
     */
    private function _saveAreaReferences(array $area, int $areaId): void
    {
        $save = $this->area->load($areaId);
        if (!$save) return;
        $save['area_id'] = $areaId;
        $save['default_field_id'] = (int)($area['default_field_id'] ?? 0) > 0 ? $this->_mapId('field', (int)$area['default_field_id']) : 0;
        $save['sort_field_id'] = (int)($area['sort_field_id'] ?? 0) > 0 ? $this->_mapId('field', (int)$area['sort_field_id']) : 0;
        $save['default_template_id'] = (int)($area['default_template_id'] ?? 0) > 0 ? $this->_mapId('template', (int)$area['default_template_id']) : 0;
        // only fields / templates that really exist
        if ($save['default_field_id'] > 0 && !$this->_fieldInArea($save['default_field_id'], $areaId)) $save['default_field_id'] = 0;
        if ($save['sort_field_id'] > 0 && !$this->_fieldInArea($save['sort_field_id'], $areaId)) $save['sort_field_id'] = 0;
        if ($save['default_template_id'] > 0 && !$this->_templateExists($save['default_template_id'])) $save['default_template_id'] = 0;
        // list and trigger template (in the file since 2026-09-23; older files
        // do not name them - then the state of the target area remains)
        if (array_key_exists('list_template_id', $area)) {
            $listId = (int)$area['list_template_id'] > 0 ? $this->_mapId('template', (int)$area['list_template_id']) : 0;
            $save['list_template_id'] = ($listId > 0 && $this->_templateExists($listId)) ? $listId : 0;
        }
        if (array_key_exists('trigger_template_ids', $area)) {
            $this->pendingTriggers[$areaId] = array_map('intval', (array)$area['trigger_template_ids']);
        }
        // overview template of the prefix address (in the file since 2026-09-27)
        if (array_key_exists('overview_template_id', $area)) {
            $overviewId = (int)$area['overview_template_id'] > 0 ? $this->_mapId('template', (int)$area['overview_template_id']) : 0;
            $save['overview_template_id'] = ($overviewId > 0 && $this->_templateExists($overviewId)) ? $overviewId : 0;
        }
        $selectable = [];
        $override = [];
        foreach ((array)($area['access'] ?? []) as $access) {
            $templateId = $this->_mapId('template', (int)$access['template_id']);
            if (!$this->_templateExists($templateId)) continue;
            if (!empty($access['selectable'])) $selectable[] = $templateId;
            if (!empty($access['override'])) $override[] = $templateId;
        }
        $save['selectable_templates'] = $selectable;
        $save['override_templates'] = $override;
        // login fields (columns since 2026-09-14; older files carry them in details)
        $fileDetails = (array)($area['details'] ?? []);
        foreach (['login_name_field_id', 'login_password_field_id'] as $key) {
            $fileFieldId = (int)($area[$key] ?? $fileDetails[$key] ?? 0);
            $mapped = $fileFieldId > 0 ? $this->_mapId('field', $fileFieldId) : 0;
            $save[$key] = ($mapped > 0 && $this->_fieldInArea($mapped, $areaId)) ? $mapped : 0;
        }
        $details = is_array($save['details'] ?? null) ? $save['details'] : (json_decode((string)($save['details'] ?? ''), true) ?: []);
        unset($details['login_name_field_id'], $details['login_password_field_id']);
        $save['details'] = $details;
        $save['previous_id'] = $this->_areaPreviousId($save);
        if (!$this->area->save([], $save)) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
    }

    /**
     * Set the trigger templates of the imported areas ($pendingTriggers) - after
     * the records and the resolution of the code markers.
     */
    private function _applyTriggerTemplates(array &$result): void
    {
        foreach ($this->pendingTriggers as $areaId => $fileTemplateIds) {
            $this->cms->errorsReset();
            $templateIds = [];
            foreach ($fileTemplateIds as $fileTemplateId) {
                $templateId = $fileTemplateId > 0 ? $this->_mapId('template', $fileTemplateId) : 0;
                if ($templateId > 0 && $this->_templateExists($templateId)) $templateIds[] = $templateId;
            }
            $save = $this->area->load((int)$areaId);
            if (!$save || array_map('intval', (array)($save['trigger_template_ids'] ?? [])) === $templateIds) continue;
            $save['area_id'] = (int)$areaId;
            $save['trigger_template_ids'] = $templateIds;
            $save['previous_id'] = $this->_areaPreviousId($save);
            if (!$this->area->save([], $save)) {
                $this->_addItem($result, ['kind' => 'area', 'id' => (int)$areaId, 'label' => (string)($save['label'] ?? ''), 'status' => 'failed', 'messages' => $this->_errorTexts()]);
            }
        }
        $this->pendingTriggers = [];
    }

    /**
     * Predecessor of an area in the tree (previous_id), so that an update does not
     * move it (TreeManagerTrait::updateInTree() compares against it).
     */
    private function _areaPreviousId(array $areaRow): int
    {
        $position = (int)($areaRow['position'] ?? 0);
        if ($position < 1) return 0;
        return $this->area->getPreviousId((int)($areaRow['parent_id'] ?? 0), $position);
    }

    private function _fieldInArea(int $fieldId, int $areaId): bool
    {
        $this->db->pushResult();
        $this->db->query("SELECT 1 FROM `field` WHERE `field_id` = ? AND `area_id` = ? AND `deleted` = 0", 'ii', [$fieldId, $areaId]);
        $found = $this->db->num_rows > 0;
        $this->db->popResult();
        return $found;
    }

    private function _templateExists(int $templateId): bool
    {
        if ($templateId < 1) return false;
        $this->db->pushResult();
        $this->db->query("SELECT 1 FROM `template` WHERE `template_id` = ?", 'i', [$templateId]);
        $found = $this->db->num_rows > 0;
        $this->db->popResult();
        return $found;
    }

    /**
     * Import the fields of the written areas, in dependency order:
     * simple types, thumbnails (after their upload field), relations (one field per
     * pair - Field::hookOnSaved() creates the counter field, which then receives the
     * data of the counter field of the file), pointers (target areas), fulltext (source fields).
     * With replace, fields of the area that the file does not name disappear.
     *
     * @param array $areas     areas of the file (_xmlStructure())
     * @param array $imported  file area_id => mode of the written areas
     * @return int  number of fields written
     */
    private function _importFields(array $areas, array $imported, array &$result): int
    {
        $count = 0;
        // all fields of the file (also of the areas not written - counter fields)
        $allFields = [];
        foreach ($areas as $fileAreaId => $area) {
            foreach ((array)$area['fields'] as $fileFieldId => $field) {
                $field['file_area_id'] = $fileAreaId;
                $allFields[(int)$fileFieldId] = $field;
            }
        }
        $rank = function (array $field): int {
            $type = (string)($field['type'] ?? '');
            if ($type === 'fulltext') return 6;
            if (in_array($type, $this->cms->multiAreaTypes, true)) return 5;
            // relation1 before relation: the counter field that Field::hookOnSaved() creates
            // is always of type 'relation' - the "1" side must therefore be saved itself
            if ($type === 'relation1') return 3;
            if ($type === 'relation') return 4;
            if ($type === 'file' && (int)($field['parent_field_id'] ?? 0) > 0) return 2;
            return 1;
        };
        $queue = [];
        foreach ($imported as $fileAreaId => $mode) {
            foreach ((array)$areas[$fileAreaId]['fields'] as $fileFieldId => $field) {
                $queue[] = (int)$fileFieldId;
            }
        }
        usort($queue, fn($a, $b) => [$rank($allFields[$a]), (int)($allFields[$a]['position'] ?? 0), $a] <=> [$rank($allFields[$b]), (int)($allFields[$b]['position'] ?? 0), $b]);

        $handled = [];
        foreach ($queue as $fileFieldId) {
            if (isset($handled[$fileFieldId])) continue;
            $field = $allFields[$fileFieldId];
            $fileAreaId = (int)$field['file_area_id'];
            $mode = (string)$imported[$fileAreaId];
            $areaId = (int)$this->map['area'][$fileAreaId];
            $item = ['kind' => 'field', 'id' => $fileFieldId, 'label' => (string)($field['label'] ?? '') . ' (' . (string)$field['name'] . ', ' . $this->_areaLabel($areaId) . ')', 'status' => 'failed', 'messages' => []];
            // thumbnail without assignable converter: skip (CONVERTERS)
            if ($this->_isThumbnail($field) && empty($this->_mapConverterIds($field, $this->sameDb))) {
                $handled[$fileFieldId] = true;
                $item['status'] = 'skipped';
                $item['messages'][] = $this->cms->snippet('record.note_import_thumbnail_no_converter');
                $this->_addItem($result, $item);
                continue;
            }
            // fulltext default field: reserved, the core writes it without an input field
            // (files from the time when it still was one)
            if ((string)$field['name'] === Field::FULLTEXT) {
                $handled[$fileFieldId] = true;
                $item['status'] = 'skipped';
                $this->_addItem($result, $item);
                continue;
            }
            $this->cms->errorsReset();
            try {
                $existing = $this->_fieldByName($areaId, (string)$field['name']);
                $fieldId = $this->_saveField($field, $areaId, $existing, $mode, $allFields, $handled);
                $handled[$fileFieldId] = true;
                $this->map['field'][$fileFieldId] = $fieldId;
                $item['id'] = $fieldId;
                $item['status'] = $existing ? (self::modeReplaces($mode) ? 'replaced' : 'updated') : 'inserted';
                $count++;
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $item['messages'] = array_merge($item['messages'], $this->_errorTexts());
                $handled[$fileFieldId] = true;
            }
            $this->_addItem($result, $item);
        }

        // replace: remove fields that the file does not name
        foreach ($imported as $fileAreaId => $mode) {
            if (!self::modeReplaces($mode)) continue;
            $areaId = (int)$this->map['area'][$fileAreaId];
            $names = array_map(fn($f) => (string)$f['name'], (array)$areas[$fileAreaId]['fields']);
            $this->db->pushResult();
            $this->db->query("SELECT `field_id`, `name`, `label` FROM `field` WHERE `area_id` = ? AND `deleted` = 0", 'i', [$areaId]);
            $rows = $this->db->fetchAll();
            $this->db->popResult();
            foreach ($rows as $row) {
                if (in_array((string)$row['name'], $names, true)) continue;
                if (in_array((int)$row['field_id'], $this->map['field'], true)) continue;
                $this->cms->errorsReset();
                $ok = $this->field->delete((int)$row['field_id']);
                $this->_addItem($result, ['kind' => 'field', 'id' => (int)$row['field_id'], 'label' => (string)$row['label'] . ' (' . $row['name'] . ', ' . $this->_areaLabel($areaId) . ')', 'status' => $ok ? 'deleted' : 'failed', 'messages' => $ok ? [] : $this->_errorTexts()]);
            }
        }
        return $count;
    }

    private function _fieldByName(int $areaId, string $name): ?array
    {
        $this->db->pushResult();
        $this->db->query("SELECT `field_id` FROM `field` WHERE `area_id` = ? AND `name` = ? AND `deleted` = 0", 'is', [$areaId, $name]);
        $fieldId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        return $fieldId > 0 ? $this->field->load($fieldId) : null;
    }

    /**
     * Create resp. write a field. For relations the counter field of the file is
     * assigned to the counter field created by Field::hookOnSaved() (resp. the existing one)
     * and updated with its data.
     *
     * @return int  field_id
     */
    private function _saveField(array $field, int $areaId, ?array $existing, string $mode, array $allFields, array &$handled): int
    {
        $type = (string)$field['type'];
        $data = [];
        foreach (self::FIELD_COLUMNS as $col) {
            if (!array_key_exists($col, $field)) continue;
            $data[$col] = $field[$col];
        }
        $data['flags'] = json_decode((string)($field['flags'] ?? '[]'), true) ?: [];
        $data['details'] = json_decode((string)($field['details'] ?? '{}'), true) ?: [];
        if (isset($data['details']['profile_area_ids'])) {
            $data['details']['profile_area_ids'] = array_values(array_filter(array_map(fn($id) => $this->_mapId('area', (int)$id), (array)$data['details']['profile_area_ids'])));
        }
        if ($this->_isThumbnail($field)) {
            // order of the file remains (`rel_field_converter`.position)
            $data['converter_ids'] = $this->_mapConverterIds($field, $this->sameDb);
            unset($data['details']['converter_ids']);
        }
        $data['height'] = (int)($field['height'] ?? 0);
        foreach (['custom_order', 'visibility_input', 'visibility_search', 'visibility_list', 'default_field', 'meta', 'multilingual'] as $bit) {
            $data[$bit] = (int)($field[$bit] ?? 0);
        }
        if ($type === 'file' && (int)($field['parent_field_id'] ?? 0) > 0) {
            $data['parent_field_id'] = $this->_mapId('field', (int)$field['parent_field_id']);
        }
        if (str_starts_with($type, 'relation')) {
            $data['rel_area_id'] = $this->_mapId('area', (int)($field['rel_area_id'] ?? 0));
        }
        if (in_array($type, $this->cms->multiAreaTypes, true)) {
            $data['rel_area_ids'] = array_values(array_unique(array_filter(array_map(fn($id) => $this->_mapId('area', (int)$id), (array)($field['rel_area_ids'] ?? [])))));
        }
        if ($type === 'fulltext') {
            $data['source_fields'] = [];
            foreach ((array)($field['source_fields'] ?? []) as $key) {
                [$textFieldId, $relFieldId] = Field::parseSourceKey($key);
                $textFieldId = $this->_mapId('field', $textFieldId);
                $relFieldId = $relFieldId > 0 ? $this->_mapId('field', $relFieldId) : 0;
                if ($textFieldId > 0 && ($relFieldId > 0 || Field::parseSourceKey($key)[1] === 0)) {
                    $data['source_fields'][] = Field::sourceKey($textFieldId, $relFieldId);
                }
            }
            $data['source_fields'] = array_values(array_unique($data['source_fields']));
        }
        $data['area_id'] = $areaId;
        $targetInstanceId = $this->instanceCache->areaInstanceId($areaId);
        foreach (['label', 'note', 'tooltip'] as $col) {
            if (isset($data[$col])) $data[$col] = $this->_mapSnippetRefs((string)$data[$col], $targetInstanceId);
        }

        if ($existing) {
            if ((string)$existing['type'] !== $type) {
                $this->cms->error(str_replace('{type}', (string)$existing['type'], $this->cms->snippet('record.error_import_field_type')));
                throw new \RuntimeException('#type');
            }
            $save = $existing;
            unset($data['type'], $data['multilingual'], $data['meta']);
            foreach ($data as $key => $value) $save[$key] = $value;
            $save['field_id'] = (int)$existing['field_id'];
            $fieldId = (int)$existing['field_id'];
            if (!$this->field->save([], $save)) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
        }
        else {
            $data['field_id'] = 0;
            $fieldId = (int)$this->field->save([], $data);
            if ($fieldId < 1) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
        }

        // update the counter field of a relation
        if (str_starts_with($type, 'relation')) {
            $counterId = (int)($this->_fieldRow($fieldId)['rel_field_id'] ?? 0);
            $fileCounterId = (int)($field['rel_field_id'] ?? 0);
            if ($counterId > 0 && $fileCounterId > 0 && isset($allFields[$fileCounterId])) {
                $this->map['field'][$fileCounterId] = $counterId;
                $handled[$fileCounterId] = true;
                $counter = $allFields[$fileCounterId];
                $counterRow = $this->field->load($counterId);
                if ($counterRow) {
                    foreach (['name', 'label', 'display_type', 'note', 'tooltip', 'min', 'min_op', 'max', 'max_op', 'position', 'custom_order', 'visibility_input', 'visibility_search', 'visibility_list'] as $col) {
                        if (array_key_exists($col, $counter)) $counterRow[$col] = $counter[$col];
                    }
                    $counterInstanceId = $this->instanceCache->areaInstanceId((int)$counterRow['area_id']);
                    foreach (['label', 'note', 'tooltip'] as $col) {
                        if (isset($counterRow[$col])) $counterRow[$col] = $this->_mapSnippetRefs((string)$counterRow[$col], $counterInstanceId);
                    }
                    $counterRow['flags'] = json_decode((string)($counter['flags'] ?? '[]'), true) ?: [];
                    $counterRow['field_id'] = $counterId;
                    if (!$this->field->save([], $counterRow)) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
                }
            }
        }
        return $fieldId;
    }

    /** Is the field of the file a thumbnail (upload field with display_type 'thumbnail')? */
    private function _isThumbnail(array $field): bool
    {
        return (string)($field['type'] ?? '') === 'file' && (string)($field['display_type'] ?? '') === 'thumbnail';
    }

    private function _fieldRow(int $fieldId): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `field` WHERE `field_id` = ?", 'i', [$fieldId]);
        $row = $this->db->fetch() ?: [];
        $this->db->popResult();
        return $row;
    }

    /**
     * Resolve the markers in the code of the written templates (export option
     * "portable", RecordExport::_markTemplateReferences()) and save the templates again
     * (compilation) if something has changed. Runs after the
     * records - only then are all ids fixed. Without markers nothing happens.
     */
    private function _rewriteTemplateCodes(array &$result): void
    {
        foreach ($this->savedTemplates as $templateId => $template) {
            $code = (string)$template['code'];
            if (!str_contains($code, 'CMS:REPLACE')) continue;
            $unresolved = 0;
            $areaId = (int)($this->map['area'][(int)$template['area_id']] ?? 0);
            $rewritten = $this->_resolveTemplateMarkers($code, $unresolved, $this->instanceCache->areaInstanceId($areaId));
            $this->cms->errorsReset();
            try {
                $template['code'] = $rewritten;
                $this->_saveTemplate($template, (int)($this->map['area'][(int)$template['area_id']] ?? 0), $templateId);
                $messages = [$this->cms->snippet('record.note_import_code_rewritten')];
                if ($unresolved > 0) $messages[] = str_replace('{count}', (string)$unresolved, $this->cms->snippet('record.note_import_markers_unresolved'));
                $this->_addItem($result, ['kind' => 'template', 'id' => $templateId, 'label' => (string)$template['label'] . ' (' . $template['name'] . ')', 'status' => 'updated', 'messages' => $messages]);
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $this->_addItem($result, ['kind' => 'template', 'id' => $templateId, 'label' => (string)$template['label'], 'status' => 'failed', 'messages' => $this->_errorTexts()]);
            }
        }
    }

    /**
     * Replace <!-- CMS:REPLACE typ=wert [slug="…"] --> with the actual value:
     *
     *     template_id / area_id / record_id   assigned id ($this->map, otherwise the same)
     *     template_name / area_name           name of the assigned template/area
     *                                         (areas may have been renamed during the import)
     *     record_id with slug                 imported along: the assigned id, otherwise
     *                                         the record with this URL path
     *                                         ([slug prefix/]slug) in the instance of the
     *                                         template ($templateInstanceId)
     *
     * If a target is neither assigned nor present, the value of the file remains
     * and $unresolved is incremented (log).
     */
    private function _resolveTemplateMarkers(string $code, int &$unresolved, int $templateInstanceId = 0): string
    {
        return preg_replace_callback(
            '/<!--\s*CMS:REPLACE\s+(template_id|template_name|area_id|area_name|record_id)=("[^"]*"|[^\s]+)(?:\s+slug=("[^"]*"|[^\s]+))?\s*-->/',
            function ($m) use (&$unresolved, $templateInstanceId) {
                $kind = $m[1];
                $value = trim($m[2], '"');
                $slug = trim($m[3] ?? '', '"');
                switch ($kind) {
                    case 'template_id':
                        $id = $this->_mapId('template', (int)$value);
                        if (!$this->_templateExists($id)) $unresolved++;
                        return (string)$id;
                    case 'area_id':
                        $id = $this->_mapId('area', (int)$value);
                        if (!$this->_areaOf($id)) $unresolved++;
                        return (string)$id;
                    case 'record_id':
                        // imported along: the assigned id; otherwise first via the
                        // path (foreign database - the id of the file means nothing there)
                        if (isset($this->map['record'][(int)$value])) return (string)$this->map['record'][(int)$value];
                        if ($slug !== '') {
                            $bySlug = $this->_recordIdBySlug($slug, $templateInstanceId);
                            if ($bySlug > 0) return (string)$bySlug;
                        }
                        $id = $this->_mapId('record', (int)$value);
                        if (!$this->_recordExists($id)) $unresolved++;
                        return (string)$id;
                    case 'template_name':
                        // "area.template": assign the area name separately (may have been renamed)
                        $areaPrefix = '';
                        $templateName = $value;
                        if (str_contains($value, '.')) {
                            [$areaPrefix, $templateName] = explode('.', $value, 2);
                            foreach ($this->fileAreas as $fileAreaId => $area) {
                                if ((string)$area['name'] !== $areaPrefix) continue;
                                $mapped = (int)($this->map['area'][$fileAreaId] ?? 0);
                                if ($mapped > 0) $areaPrefix = (string)($this->_areaOf($mapped)['name'] ?? $areaPrefix);
                                break;
                            }
                            $areaPrefix .= '.';
                        }
                        $fileId = array_search($templateName, array_map(fn($t) => $t['name'], $this->fileTemplates), true);
                        if ($fileId !== false) {
                            $id = $this->_mapId('template', (int)$fileId);
                            $name = $this->_templateName($id);
                            if ($name !== '') return $areaPrefix . $name;
                        }
                        if (!$this->_templateNameExists($templateName)) $unresolved++;
                        return $areaPrefix . $templateName;
                    case 'area_name':
                        foreach ($this->fileAreas as $fileAreaId => $area) {
                            if ((string)$area['name'] !== $value) continue;
                            $mapped = (int)($this->map['area'][$fileAreaId] ?? 0);
                            if ($mapped > 0) return (string)($this->_areaOf($mapped)['name'] ?? $value);
                        }
                        if ($this->_resolveArea(0, $value, '') < 1 && $this->_resolveArea(0, $value, 'template') < 1 && $this->_resolveArea(0, $value, 'folder') < 1) $unresolved++;
                        return $value;
                }
                return $value;
            },
            $code
        ) ?? $code;
    }

    /**
     * Find a record via its URL path [slug prefix/]slug - like a call of the
     * page (Cms::recordIdsBySlugPath()) in the instance of the template and its
     * linked instances, per instance in the primary language (the export
     * writes the path of the primary language). 0 = no or no unique match.
     */
    private function _recordIdBySlug(string $path, int $instanceId): int
    {
        $scope = $instanceId > 0 ? $this->instanceCache->uniqueScope($instanceId) : [];
        $languageMap = [];
        foreach ($scope ?: (array)($this->instanceCache->all()['instance_ids'] ?? []) as $id) {
            $languageMap[(int)$id] = $this->instanceCache->primaryLanguageId((int)$id);
        }
        $ids = $this->cms->recordIdsBySlugPath($path, '', $languageMap, array_keys($languageMap));
        return count($ids) === 1 ? (int)$ids[0] : 0;
    }

    private function _recordExists(int $recordId): bool
    {
        if ($recordId < 1) return false;
        $this->db->pushResult();
        $this->db->query("SELECT 1 FROM `record` WHERE `record_id` = ? AND `deleted` = 0", 'i', [$recordId]);
        $found = $this->db->num_rows > 0;
        $this->db->popResult();
        return $found;
    }

    private function _templateName(int $templateId): string
    {
        $this->db->pushResult();
        $this->db->query("SELECT `name` FROM `template` WHERE `template_id` = ?", 'i', [$templateId]);
        $name = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->popResult();
        return $name;
    }

    private function _templateNameExists(string $name): bool
    {
        $this->db->pushResult();
        $this->db->query("SELECT 1 FROM `template` WHERE `name` = ? LIMIT 1", 's', [$name]);
        $found = $this->db->num_rows > 0;
        $this->db->popResult();
        return $found;
    }

    /**
     * Existing template by criterion: name in the target area or template_id.
     */
    private function _resolveTemplate(array $template, int $areaId, string $by): int
    {
        $this->db->pushResult();
        if ($by === 'template_id') {
            $this->db->query("SELECT `template_id` FROM `template` WHERE `template_id` = ?", 'i', [(int)$template['template_id']]);
        }
        else {
            $this->db->query("SELECT `template_id` FROM `template` WHERE `area_id` = ? AND `name` = ?", 'is', [$areaId, (string)$template['name']]);
        }
        $id = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        return $id;
    }

    /**
     * Create resp. write a template - Template::save() compiles the code.
     *
     * @return int  template_id
     */
    private function _saveTemplate(array $template, int $areaId, int $existingId): int
    {
        $data = [
            'area_id' => $areaId,
            'name' => (string)$template['name'],
            'label' => (string)$template['label'],
            'code' => (string)$template['code'],
            'type' => (string)($template['type'] ?? ''),
            'standalone' => (int)($template['standalone'] ?? 0),
        ];
        if ($existingId > 0) {
            $save = $this->template->load($existingId) ?: [];
            foreach ($data as $key => $value) $save[$key] = $value;
            $save['template_id'] = $existingId;
            if (!$this->template->save([], $save)) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
            return $existingId;
        }
        $data['template_id'] = 0;
        $id = (int)$this->template->save([], $data);
        if ($id < 1) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
        return $id;
    }

    // ====================
    // XML lesen

    /**
     * Records of the XML file as a generator. Target area, mode and
     * update criterion per entry: globally from $job['areas'] (areas of the file are
     * assigned via $this->map['area'] - _importStructure()); within an area only the
     * records of the chosen source area of the file (source_area_id), the target is
     * the calling area, mode $job['mode'].
     */
    private function _xmlEntries(string $path, array $job): \Generator
    {
        $reader = new \XMLReader();
        if (!@$reader->open($path)) return;
        $header = null;
        $isGlobal = !empty($job['global']);
        $currentAreaId = (int)($job['area_id'] ?? 0);
        $sourceAreaId = (int)($job['source_area_id'] ?? 0);
        while (@$reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) continue;
            if ($reader->depth === 1 && $reader->name === 'header') {
                $header = $this->_xmlHeader($this->_readerNode($reader));
                $reader->next();
                continue;
            }
            if ($reader->depth === 2 && $reader->name === 'record') {
                $node = $this->_readerNode($reader);
                $originAreaId = (int)$node['area_id'];
                $entry = ['id' => (int)$node['record_id'], 'area_id' => $originAreaId, 'label' => '', 'data' => []];
                if ($isGlobal) {
                    $settings = (array)($job['areas'][$originAreaId] ?? []);
                    $mode = (string)($settings['records'] ?? 'ignore');
                    if ($mode === 'ignore' || !in_array($mode, self::MODES, true)) {
                        $reader->next();
                        continue;
                    }
                    $entry['mode'] = $mode;
                    $entry['by'] = (string)($settings['records_by'] ?? 'slug');
                    $targetAreaId = (int)($this->map['area'][$originAreaId] ?? 0);
                }
                else {
                    // only the chosen source area (without specification: all) - here
                    if ($sourceAreaId > 0 && $originAreaId !== $sourceAreaId) {
                        $reader->next();
                        continue;
                    }
                    $targetAreaId = $currentAreaId;
                }
                if ($targetAreaId < 1) {
                    $entry['error'] = $this->cms->snippet('area.error_area_not_found');
                    // label from the default field of the file (analysis: the area only arises then)
                    $defaultField = (string)($this->fileAreas[$originAreaId]['default_field'] ?? '');
                    foreach ($defaultField !== '' ? $node->field : [] as $field) {
                        if ((string)$field['name'] !== $defaultField) continue;
                        $entry['label'] = trim((string)($field->value[0] ?? ''));
                        break;
                    }
                }
                else {
                    $entry['data'] = $this->_xmlRecordData($node, $header ?? [], $targetAreaId, $job);
                    $entry['label'] = $this->_labelOf($targetAreaId, $entry['data']['values']);
                }
                yield $entry;
                $reader->next();
            }
        }
        $reader->close();
    }

    /**
     * Bring a <record> element into the import form (see _importOne()): fields
     * by name onto the target schema, languages by code onto the instance.
     */
    private function _xmlRecordData(\SimpleXMLElement $node, array $header, int $areaId, array $job): array
    {
        $schema = $this->_schemaOf($areaId);
        $langCtx = $this->_languagesOf($areaId);
        $languageIds = $langCtx['language_ids'];
        $primaryId = $langCtx['primary_id'];
        // language of the file -> language of the instance (via the code)
        $xmlLanguages = (array)($header['languages'] ?? []);
        $xmlPrimary = (int)($header['primary_language_id'] ?? 0);
        // format 2: the primary language of the instance the record comes from
        $fileInstanceId = (int)($node['instance_id'] ?? 0);
        if ($fileInstanceId > 0 && isset($header['primary_by_instance'][$fileInstanceId])) {
            $xmlPrimary = (int)$header['primary_by_instance'][$fileInstanceId];
        }
        $languageMap = [];
        foreach ($xmlLanguages as $xmlId => $code) {
            $target = $this->instanceCache->languageByCode((int)$langCtx['instance_id'], (string)$code);
            if ($target !== null) $languageMap[(int)$xmlId] = (int)$target['language_id'];
        }
        $data = ['area_id' => $areaId, 'values' => [], 'stored' => [], 'prehashed' => [], 'relations' => [], 'files' => [], 'meta' => []];

        // meta columns from the attributes (created_at and ORIGIN_FIELDS only for
        // _restoreCreated())
        foreach (array_merge(self::META_FIELDS, ['created_at'], self::ORIGIN_FIELDS) as $name) {
            if (!isset($node[$name])) continue;
            $data['meta'][$name] = (string)$node[$name];
        }

        foreach ($node->field as $field) {
            $name = (string)$field['name'];
            $type = (string)$field['type'];
            $colDef = $schema[$name] ?? null;
            if ($colDef === null) continue;
            $targetType = (string)($colDef['type'] ?? '');
            if ($type !== $targetType && !($this->_sameRelationKind($type, $targetType))) continue;

            if (in_array($targetType, $this->cms->relationTypes, true)) {
                $ids = [];
                foreach ($field->relation as $relation) $ids[] = (int)$relation['dest_id'];
                $data['relations'][$name] = $ids;
                continue;
            }
            if ($targetType === 'file') {
                $files = [];
                $anyPath = false;
                foreach ($field->file as $file) {
                    $path = (string)$file['path'];
                    $fileLang = (string)$file['file_language_id'];
                    $rowLang = (int)$file['language_id'];
                    if ((string)$file['extension'] === '') continue;
                    // only own files (fallback rows point to the same file)
                    if ($fileLang !== '' && (int)$fileLang !== $rowLang) continue;
                    $langKey = '';
                    if ($this->cms->isMultilingualField($colDef)) {
                        $targetLang = $languageMap[$rowLang] ?? ($rowLang === $xmlPrimary ? $primaryId : null);
                        if ($targetLang === null) continue;
                        $langKey = (string)$targetLang;
                    }
                    if ($path === '') continue; // export without files: change nothing
                    $anyPath = true;
                    $absolute = $this->_importFilePath($job, $path);
                    $files[$langKey] = $absolute === null ? null : ['importfile' => $absolute, 'filename' => (string)$file['filename']];
                    if (!$this->cms->isMultilingualField($colDef)) break;
                }
                if ($anyPath) $data['files'][$name] = $files;
                continue;
            }

            // scalar values per language
            $values = [];
            $fallback = [];
            foreach ($field->value as $value) {
                $xmlLang = isset($value['language_id']) ? (int)$value['language_id'] : $xmlPrimary;
                $text = (string)$value;
                if ((string)$value['encoding'] === 'base64') $text = base64_decode($text, true) ?: '';
                if ($targetType === 'encrypted') $text = $this->crypt->decrypt($text, '', true);
                $values[$xmlLang] = $text;
                if ((string)$value['empty'] === '1') $fallback[$xmlLang] = true;
            }
            if (empty($values)) continue;
            $primaryValue = $values[$xmlPrimary] ?? reset($values);
            if ($this->cms->isMultilingualField($colDef)) {
                $value = [];
                foreach ($languageIds as $languageId) {
                    $xmlLang = array_search($languageId, $languageMap, true);
                    if ($languageId === $primaryId) {
                        $value[$languageId] = ($xmlLang !== false && isset($values[$xmlLang])) ? $values[$xmlLang] : $primaryValue;
                    }
                    else {
                        // fallback of the file = no own translation -> empty
                        $value[$languageId] = ($xmlLang !== false && isset($values[$xmlLang]) && empty($fallback[$xmlLang])) ? $values[$xmlLang] : '';
                    }
                }
            }
            else {
                $value = $primaryValue;
            }
            if ($targetType === 'json') {
                $value = is_array($value) ? array_map(fn($v) => $this->_jsonValue($v), $value) : $this->_jsonValue($value);
            }
            $data['values'][$name] = $value;
            if ($targetType === 'password') {
                $data['prehashed'][] = $name;
            }
            elseif ($targetType !== 'encrypted') {
                $data['stored'][] = $name;
            }
        }
        return $data;
    }

    private function _sameRelationKind(string $a, string $b): bool
    {
        return in_array($a, $this->cms->relationTypes, true) && in_array($b, $this->cms->relationTypes, true)
            && (str_starts_with($a, 'relation') === str_starts_with($b, 'relation'));
    }

    private function _jsonValue(mixed $value): mixed
    {
        if (is_array($value)) return $value;
        $value = (string)$value;
        if ($value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [$value];
    }

    /**
     * Absolute path of a file from the import file (media/…), null if it is missing.
     */
    private function _importFilePath(array $job, string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || preg_match('!(^|/)\.\.(/|$)!', $relative)) return null;
        $base = (string)($job['dir_path'] ?? '');
        $candidates = [$base . '/' . $relative, dirname($base . '/' . (string)$job['file']) . '/' . $relative];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) return realpath($candidate) ?: null;
        }
        return null;
    }

    // ====================
    // CSV lesen

    /**
     * Lines of a CSV file (own parser: enclosure characters may contain
     * line breaks and doubled enclosure characters, the
     * line break can be freely chosen). A UTF-8 BOM is skipped.
     *
     * @return array  lines as arrays of cells
     */
    private function _csvRows(string $path, array $settings): array
    {
        $content = (string)file_get_contents($path);
        if (str_starts_with($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
        $separator = $this->csvChar((string)($settings['separator'] ?? ';'), ';');
        $enclosure = $this->csvChar((string)($settings['enclosure'] ?? '"'), '"');
        $eol = $this->csvEol((string)($settings['eol'] ?? '\r\n'));
        $eolFirst = $eol[0];
        $eolLen = strlen($eol);

        $rows = [];
        $row = [];
        $cell = '';
        $inQuotes = false;
        $length = strlen($content);
        $i = 0;
        while ($i < $length) {
            $char = $content[$i];
            if ($inQuotes) {
                if ($char === $enclosure) {
                    if ($i + 1 < $length && $content[$i + 1] === $enclosure) {
                        $cell .= $enclosure;
                        $i += 2;
                        continue;
                    }
                    $inQuotes = false;
                    $i++;
                    continue;
                }
                $cell .= $char;
                $i++;
                continue;
            }
            if ($char === $enclosure && $cell === '') {
                $inQuotes = true;
                $i++;
                continue;
            }
            if ($char === $separator) {
                $row[] = $cell;
                $cell = '';
                $i++;
                continue;
            }
            if ($char === $eolFirst && substr($content, $i, $eolLen) === $eol) {
                $row[] = $cell;
                $rows[] = $row;
                $row = [];
                $cell = '';
                $i += $eolLen;
                continue;
            }
            // foreign line break (\n instead of \r\n): also take it as line end
            if (($char === "\n" || $char === "\r") && $eol !== $char) {
                if ($char === "\r" && $i + 1 < $length && $content[$i + 1] === "\n") $i++;
                $row[] = $cell;
                $rows[] = $row;
                $row = [];
                $cell = '';
                $i++;
                continue;
            }
            $cell .= $char;
            $i++;
        }
        if ($cell !== '' || !empty($row)) {
            $row[] = $cell;
            $rows[] = $row;
        }
        // skip empty lines (only one empty cell)
        return array_values(array_filter($rows, fn($r) => count($r) > 1 || trim((string)($r[0] ?? '')) !== ''));
    }

    public function csvChar(string $input, string $default): string
    {
        $input = strtr($input, ['\t' => "\t", '\\t' => "\t"]);
        if ($input === '') return $default;
        return mb_substr($input, 0, 1);
    }

    public function csvEol(string $input): string
    {
        $eol = strtr($input, ['\r' => "\r", '\n' => "\n", '\\r' => "\r", '\\n' => "\n"]);
        return trim($eol, ' ') === '' ? "\r\n" : $eol;
    }

    /**
     * Lines of the CSV file as a generator (the assignment 'mapping' turns them into
     * field values; skip passes over the header line).
     */
    private function _csvEntries(string $path, array $job): \Generator
    {
        $settings = (array)($job['csv'] ?? []);
        $mapping = (array)($job['mapping'] ?? []);
        $areaId = (int)($job['area_id'] ?? 0);
        $stored = !empty($settings['stored']);
        foreach ($this->_csvRows($path, $settings) as $row) {
            $data = $this->_csvRecordData($row, $mapping, $areaId, $stored, $job);
            $error = '';
            foreach ($data['links'] as $link) {
                if ((string)($link['error'] ?? '') !== '') { $error = (string)$link['error']; break; }
            }
            yield [
                'id' => (int)($data['meta']['record_id'] ?? 0),
                'area_id' => $areaId,
                'label' => $this->_labelOf($areaId, $data['values']),
                'data' => $data,
            ] + ($error !== '' ? ['error' => $error] : []);
        }
    }

    /**
     * Bring a CSV line into the import form (target keys see csvTargets()).
     */
    private function _csvRecordData(array $row, array $mapping, int $areaId, bool $stored, array $job): array
    {
        $schema = $this->_schemaOf($areaId);
        $langCtx = $this->_languagesOf($areaId);
        $primaryId = $langCtx['primary_id'];
        $data = ['area_id' => $areaId, 'values' => [], 'stored' => [], 'prehashed' => [], 'relations' => [], 'files' => [], 'meta' => [], 'links' => []];
        $linkCells = [];
        foreach ($mapping as $index => $key) {
            $key = (string)$key;
            if ($key === '' || !array_key_exists($index, $row)) continue;
            $value = (string)$row[$index];
            // column of a link field: "{link}.{…}" - collect first, the contents
            // arise when all columns have been read (_csvLinks())
            $dot = strpos($key, '.');
            if ($dot !== false && isset($schema[substr($key, 0, $dot)])
                && in_array((string)($schema[substr($key, 0, $dot)]['type'] ?? ''), $this->cms->dependentTypes, true)
            ) {
                $linkCells[substr($key, 0, $dot)][substr($key, $dot + 1)] = $value;
                continue;
            }
            if ($key === 'record_id' || in_array($key, self::META_FIELDS, true) || in_array($key, self::ORIGIN_FIELDS, true)) {
                if (isset($schema[$key]) && empty($schema[$key]['meta'])) {
                    // input field of the same name (not a meta field) - below as value
                }
                else {
                    $data['meta'][$key] = trim($value);
                    continue;
                }
            }
            $name = $key;
            $languageId = null;
            if (!isset($schema[$name])) {
                $dot = strrpos($key, '.');
                if ($dot === false) continue;
                $name = substr($key, 0, $dot);
                $suffix = substr($key, $dot + 1);
                if (!isset($schema[$name])) continue;
                if ($suffix === 'record_id') {
                    $ids = array_filter(array_map('trim', explode('|', $value)), fn($v) => $v !== '');
                    $data['relations'][$name] = array_map('intval', $ids);
                    continue;
                }
                $language = $this->instanceCache->languageByCode((int)$langCtx['instance_id'], $suffix);
                if ($language === null) continue;
                $languageId = (int)$language['language_id'];
            }
            $colDef = $schema[$name];
            $type = (string)($colDef['type'] ?? '');
            if ($type === 'fulltext') continue;
            if (in_array($type, $this->cms->relationTypes, true)) {
                $ids = array_filter(array_map('trim', explode('|', $value)), fn($v) => $v !== '');
                $data['relations'][$name] = array_map('intval', $ids);
                continue;
            }
            if ($type === 'file') {
                if (!empty($colDef['parent_field_id'])) continue;
                $value = trim($value);
                $langKey = ($languageId === null) ? '' : (string)$languageId;
                if ($value === '') {
                    // empty cell: with replace the file is removed (not named)
                    continue;
                }
                $absolute = $this->_importFilePath($job, $value);
                $filename = preg_replace('/^\d+_\d+_\d+-/', '', basename(str_replace('\\', '/', $value))) ?? basename($value);
                $data['files'][$name][$langKey] = $absolute === null ? null : ['importfile' => $absolute, 'filename' => $filename];
                continue;
            }
            if ($type === 'json') $value = $this->_jsonValue($value);
            if ($this->cms->isMultilingualField($colDef)) {
                $languageId ??= $primaryId;
                if (!isset($data['values'][$name]) || !is_array($data['values'][$name])) $data['values'][$name] = [];
                $data['values'][$name][$languageId] = $value;
            }
            else {
                $data['values'][$name] = $value;
            }
            if ($type === 'password') {
                if ((string)$value !== '' && !in_array($name, $data['prehashed'], true)) $data['prehashed'][] = $name;
            }
            elseif ($stored && $type !== 'encrypted' && !in_array($name, $data['stored'], true)) {
                $data['stored'][] = $name;
            }
        }
        // file columns per language: the CSV export writes the resolved path into every language
        // column - for a language without its own file that of the primary language.
        // Creating the same file again for the language would turn the fallback
        // into an own file; therefore: same path as the primary language = removed
        foreach ($data['files'] as $name => $langFiles) {
            $primaryKey = (string)$primaryId;
            $primaryFile = (string)($langFiles[$primaryKey]['importfile'] ?? '');
            if ($primaryFile === '') continue;
            foreach ($langFiles as $langKey => $fileData) {
                if ((string)$langKey === $primaryKey) continue;
                if ((string)($fileData['importfile'] ?? '') === $primaryFile) unset($data['files'][$name][$langKey]);
            }
        }
        // embedded contents
        foreach ($linkCells as $name => $cells) {
            $data['links'][$name] = $this->_csvLinks($schema[$name], $cells, $langCtx, $stored, $job);
        }
        // empty password cells: do not touch the field
        foreach ($data['prehashed'] as $name) {
            if ((string)($data['values'][$name] ?? '') === '') unset($data['values'][$name]);
        }
        foreach ($schema as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') === 'password' && isset($data['values'][$name]) && (string)$data['values'][$name] === '') {
                unset($data['values'][$name]);
            }
        }
        return $data;
    }

    /**
     * The columns of a link field of a CSV line -> embedded contents
     * (EMBEDDED CONTENTS). One value per content, separated by " | "; the number
     * of contents is set by the record_id column (otherwise the column with the most
     * values). With exactly one content no separation takes place - a text may then
     * contain " | ". If the number of a column does not match, the result carries
     * 'error' (the record is not imported).
     *
     * @param array $cells  sub-key (record_id, area_id, {field}, {field}.{code}) => cell
     * @return array  ['entries' => [['id' => int|null, 'area_id', 'values', 'files',
     *                'stored', 'prehashed'], …], 'error' => string]
     */
    private function _csvLinks(array $colDef, array $cells, array $langCtx, bool $stored, array $job): array
    {
        $result = ['entries' => [], 'error' => ''];
        $label = $this->cms->textOrSnippet((string)($colDef['label'] ?? $colDef['name'] ?? ''));
        $targetFields = $this->record->linkTargetFields($colDef);
        $split = fn(string $value) => $value === '' ? [] : explode(' | ', $value);
        $hasIds = array_key_exists('record_id', $cells);
        if ($hasIds) {
            $ids = array_values(array_filter(array_map('trim', explode('|', (string)$cells['record_id'])), fn($v) => $v !== ''));
            $count = count($ids);
        }
        else {
            $count = 0;
            foreach ($cells as $cell) $count = max($count, count($split((string)$cell)));
        }
        // columns -> [field, language] and values per content
        $columns = [];
        foreach ($cells as $sub => $cell) {
            $sub = (string)$sub;
            if ($sub === 'record_id') continue;
            $field = $sub;
            $languageId = null;
            if ($sub !== 'area_id' && !isset($targetFields[$sub])) {
                $dot = strrpos($sub, '.');
                if ($dot === false) continue;
                $field = substr($sub, 0, $dot);
                $language = $this->instanceCache->languageByCode((int)$langCtx['instance_id'], substr($sub, $dot + 1));
                if (!isset($targetFields[$field]) || $language === null) continue;
                $languageId = (int)$language['language_id'];
            }
            $parts = $count <= 1 ? ($cell === '' ? [] : [(string)$cell]) : $split((string)$cell);
            if (empty($parts)) $parts = array_fill(0, $count, '');
            if (count($parts) !== $count) {
                $result['error'] = str_replace(['{field}', '{count}'], ["$label.$sub", $count], $this->cms->snippet('record.error_import_embedded_count'));
                return $result;
            }
            $columns[] = ['field' => $field, 'language_id' => $languageId, 'parts' => $parts];
        }
        for ($i = 0; $i < $count; $i++) {
            $entry = [
                'id' => $hasIds ? (int)$ids[$i] : null,
                'area_id' => 0,
                'values' => [], 'files' => [], 'stored' => [], 'prehashed' => [],
            ];
            foreach ($columns as $column) {
                $field = $column['field'];
                $value = (string)$column['parts'][$i];
                if ($field === 'area_id') {
                    $entry['area_id'] = (int)trim($value);
                    continue;
                }
                $targetDef = $targetFields[$field];
                $type = (string)($targetDef['type'] ?? '');
                $languageId = $column['language_id'];
                if ($type === 'file') {
                    if (!empty($targetDef['parent_field_id'])) continue; // Thumbnail
                    $value = trim($value);
                    if ($value === '') continue;
                    $langKey = ($languageId === null || !$this->cms->isMultilingualField($targetDef)) ? '' : (string)$languageId;
                    $absolute = $this->_importFilePath($job, $value);
                    $filename = preg_replace('/^\d+_\d+_\d+-/', '', basename(str_replace('\\', '/', $value))) ?? basename($value);
                    $entry['files'][$field][$langKey] = $absolute === null ? null : ['importfile' => $absolute, 'filename' => $filename];
                    continue;
                }
                if ($type === 'json') $value = $this->_jsonValue($value);
                if ($this->cms->isMultilingualField($targetDef)) {
                    $entry['values'][$field] = (array)($entry['values'][$field] ?? []);
                    $entry['values'][$field][$languageId ?? (int)$langCtx['primary_id']] = $value;
                }
                else {
                    $entry['values'][$field] = $value;
                }
                if ($type === 'password') {
                    if ((string)$value === '') unset($entry['values'][$field]);
                    elseif (!in_array($field, $entry['prehashed'], true)) $entry['prehashed'][] = $field;
                }
                elseif ($stored && $type !== 'encrypted' && !in_array($field, $entry['stored'], true)) {
                    $entry['stored'][] = $field;
                }
            }
            // file per language with the path of the primary language: fallback, no own file
            foreach ($entry['files'] as $field => $langFiles) {
                $primaryFile = (string)($langFiles[(string)$langCtx['primary_id']]['importfile'] ?? '');
                if ($primaryFile === '') continue;
                foreach ($langFiles as $langKey => $fileData) {
                    if ((string)$langKey !== (string)$langCtx['primary_id'] && (string)($fileData['importfile'] ?? '') === $primaryFile) {
                        unset($entry['files'][$field][$langKey]);
                    }
                }
            }
            $result['entries'][] = $entry;
        }
        return $result;
    }

    // ====================
    // DOCX lesen

    /**
     * Tables of the Word document (RecordExport::_exportDocx()) -> records.
     *
     * @return array|null  [['record_id', 'language_id', 'source_language_id', 'fields' => [name => text],
     *                     'links' => [link => [record_id => [feld => text]]]], …]
     */
    private function _docxRecords(string $path, ?string &$error = null): ?array
    {
        $error = '';
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) return null;
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) return null;
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml)) return null;
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $records = [];
        foreach ($dom->getElementsByTagNameNS($ns, 'tbl') as $table) {
            $rows = [];
            foreach ($table->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE || $child->localName !== 'tr') continue;
                $cell = null;
                foreach ($child->childNodes as $tc) {
                    if ($tc->nodeType === XML_ELEMENT_NODE && $tc->localName === 'tc') { $cell = $tc; break; }
                }
                if ($cell === null) continue;
                $paragraphs = [];
                foreach ($cell->childNodes as $p) {
                    if ($p->nodeType !== XML_ELEMENT_NODE || $p->localName !== 'p') continue;
                    $paragraphs[] = $this->_docxParagraphText($p);
                }
                $rows[] = implode("\n", $paragraphs);
            }
            if (empty($rows) || !preg_match('/record_id=(\d+)/', $rows[0], $m)) continue;
            $record = ['record_id' => (int)$m[1], 'language_id' => 0, 'source_language_id' => 0, 'fields' => [], 'links' => []];
            if (preg_match('/(?<![a-z_])language_id=(\d+)/', $rows[0], $m)) $record['language_id'] = (int)$m[1];
            if (preg_match('/source_language_id=(\d+)/', $rows[0], $m)) $record['source_language_id'] = (int)$m[1];
            for ($i = 1; $i + 1 < count($rows); $i += 2) {
                if (!preg_match('/^field=([a-z0-9_\-]+)(?:\.([a-z0-9_\-]+))?/', $rows[$i], $m)) { $i--; continue; }
                if (!empty($m[2])) {
                    // field of an embedded content: "field={link}.{field} | record_id={id}"
                    $destId = preg_match('/record_id=(\d+)/', $rows[$i], $mm) ? (int)$mm[1] : 0;
                    $record['links'][$m[1]][$destId][$m[2]] = $rows[$i + 1];
                    continue;
                }
                $record['fields'][$m[1]] = $rows[$i + 1];
            }
            $records[] = $record;
        }
        return $records;
    }

    private function _docxParagraphText(\DOMNode $paragraph): string
    {
        $text = '';
        $walker = function (\DOMNode $node) use (&$walker, &$text) {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                switch ($child->localName) {
                    case 't': $text .= $child->textContent; break;
                    case 'tab': $text .= "\t"; break;
                    case 'br': case 'cr': $text .= "\n"; break;
                    case 'delText': break;
                    default: $walker($child);
                }
            }
        };
        $walker($paragraph);
        return $text;
    }

    /**
     * Records of the DOCX file as a generator: update in the target language.
     */
    private function _docxEntries(string $path, array $job): \Generator
    {
        $areaId = (int)($job['area_id'] ?? 0);
        $schema = $this->_schemaOf($areaId);
        $options = (array)($job['docx'] ?? []);
        $records = $this->_docxRecords($path) ?? [];
        foreach ($records as $record) {
            $languageId = (int)$record['language_id'];
            $data = ['area_id' => $areaId, 'values' => [], 'stored' => [], 'prehashed' => [], 'relations' => [], 'files' => [], 'meta' => [], 'links' => []];
            foreach ($record['fields'] as $name => $text) {
                $colDef = $schema[$name] ?? null;
                if ($colDef === null || !$this->cms->isMultilingualField($colDef)) continue;
                $data['values'][$name] = [$languageId => $this->docxText($text, $options)];
            }
            // embedded contents: translate only the named ones (partial)
            foreach ((array)($record['links'] ?? []) as $linkName => $dests) {
                $colDef = $schema[$linkName] ?? null;
                if ($colDef === null || !in_array((string)($colDef['type'] ?? ''), $this->cms->dependentTypes, true)) continue;
                $targetAreaIds = $this->record->linkTargetAreaIds($colDef);
                $entries = [];
                foreach ($dests as $destId => $subs) {
                    $entry = ['id' => (int)$destId, 'area_id' => 0, 'values' => [], 'files' => [], 'stored' => [], 'prehashed' => []];
                    // the fields of the content's own area decide (several target areas
                    // may carry a field of the same name, monolingual in one of them)
                    $destAreaId = $this->_recordAreaId((int)$destId);
                    $destSchema = in_array($destAreaId, $targetAreaIds, true) ? $this->_schemaOf($destAreaId) : [];
                    foreach ($subs as $sub => $text) {
                        $targetDef = $destSchema[$sub] ?? null;
                        if ($targetDef === null || !$this->cms->isMultilingualField($targetDef)) continue;
                        $entry['values'][$sub] = [$languageId => $this->docxText($text, $options)];
                    }
                    if (!empty($entry['values'])) $entries[] = $entry;
                }
                $data['links'][$linkName] = ['entries' => $entries, 'partial' => true];
            }
            yield [
                'id' => (int)$record['record_id'],
                'area_id' => $areaId,
                'label' => '',
                'data' => $data,
            ];
        }
    }

    /**
     * Prepare the translated text according to the DOCX options:
     *   punct  convert punctuation next to CJK characters into ideographic punctuation
     *   space  convert spaces between CJK characters into the ideographic space
     *   rtl    mark sections in right-to-left script: 'unicode'
     *          (RLI … PDI), 'html' (<span dir="rtl">), 'none'
     */
    public function docxText(string $text, array $options): string
    {
        $cjk = '\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}';
        if (!empty($options['punct'])) {
            $map = [',' => '，', '.' => '。', '!' => '！', '?' => '？', ':' => '：', ';' => '；', '(' => '（', ')' => '）'];
            $text = preg_replace_callback(
                "/(?<=[{$cjk}])[,.!?:;()]|[,.!?:;()](?=[{$cjk}])/u",
                fn($m) => $map[$m[0]] ?? $m[0],
                $text
            ) ?? $text;
        }
        if (!empty($options['space'])) {
            $text = preg_replace("/(?<=[{$cjk}]) +(?=[{$cjk}])/u", "\u{3000}", $text) ?? $text;
        }
        $rtl = (string)($options['rtl'] ?? 'none');
        if ($rtl === 'unicode' || $rtl === 'html') {
            $pattern = '/[\p{Arabic}\p{Hebrew}\p{Syriac}\p{Thaana}](?:[\p{Arabic}\p{Hebrew}\p{Syriac}\p{Thaana}\p{N}\p{P}\p{Zs}]*[\p{Arabic}\p{Hebrew}\p{Syriac}\p{Thaana}])?/u';
            if (preg_match_all($pattern, $text, $matches) > 0) {
                // a single section that makes up the whole text needs no marker
                if (!(count($matches[0]) === 1 && trim($text) === trim($matches[0][0]))) {
                    $text = preg_replace_callback($pattern, fn($m) => $rtl === 'html'
                        ? '<span dir="rtl">' . $m[0] . '</span>'
                        : "\u{2067}" . $m[0] . "\u{2069}", $text) ?? $text;
                }
            }
        }
        return $text;
    }

    // ====================
    // Hintergrund

    public function start(array $job, ?string &$error = null): ?array
    {
        $error = '';
        $phpExec = (string)$this->config->get('paths.php');
        if ($phpExec === '' || !is_executable($phpExec)) {
            $error = "Error: 'php' is not executable (paths.php)";
            $this->system->log('import-errors', $error);
            return null;
        }
        $token = $this->crypt->randomKey(16);
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $jobFile = $tmpDir . '/import-job-' . $token . '.json';
        $job['token'] = $token;
        $job['admin_id'] = (int)$this->session->get('admin_id');
        if (file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            $error = "Error: could not write job file {$jobFile}";
            $this->system->log('import-errors', $error);
            return null;
        }
        $command = [
            $phpExec,
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'record_import.php',
            '--job=' . $jobFile,
            '--token=' . $token,
        ];
        $result = $this->bgproc->start($command, self::BGPROC_TYPE, $token, null, null, $error);
        if ($result === null) {
            @unlink($jobFile);
            $this->system->log('import-errors', "start failed: {$error}");
        }
        return $result;
    }

    public function loadJob(string $jobFile): ?array
    {
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $real = realpath($jobFile);
        if ($real === false || !str_starts_with($real, realpath($tmpDir) . DIRECTORY_SEPARATOR)) return null;
        $job = json_decode((string)file_get_contents($real), true);
        @unlink($real);
        return is_array($job) ? $job : null;
    }

    /**
     * Result as an internal message to the triggering admin: summary as
     * text, the log (HTML with colors) in the HTML part.
     *
     * @return string  Betreff
     */
    public function report(array $job, array $result): string
    {
        $adminId = (int)($job['admin_id'] ?? 0);
        $subject = str_replace(
            ['{type}', '{area}'],
            [strtoupper((string)($job['type'] ?? '')), $this->_areaLabel((int)($job['area_id'] ?? 0))],
            $this->cms->snippet('record.import_report_subject')
        );
        if ($adminId < 1) return $subject;
        // text: only the summary - the log is in the HTML part
        $body = $this->summaryText($job, $result);
        if (!empty($result['error'])) $body .= "\n" . (string)$result['error'];
        $this->message->sendSystem($adminId, $subject, $body, $this->protocolHtml($job, $result));
        return $subject;
    }

    /**
     * "Import {mode}: n added, n changed, n replaced, n failed, n skipped"
     */
    public function summaryText(array $job, array $result): string
    {
        $text = $this->cms->snippet('record.import_summary', ['mode' => !empty($job['global']) ? 'global' : (string)($job['mode'] ?? '')])
            . $this->cms->snippet('colon')
            . str_replace(
                ['{inserted}', '{updated}', '{replaced}', '{failed}', '{skipped}'],
                [(int)($result['inserted'] ?? 0), (int)($result['updated'] ?? 0), (int)($result['replaced'] ?? 0), (int)($result['failed'] ?? 0), (int)($result['skipped'] ?? 0)],
                $this->cms->snippet('record.import_summary_counts')
            );
        return $text;
    }

    /**
     * Log as an HTML table with colors (Bootstrap classes) - for the
     * result page and the HTML part of the message.
     */
    public function protocolHtml(array $job, array $result): string
    {
        $classes = ['inserted' => 'success', 'updated' => 'info', 'replaced' => 'warning', 'deleted' => 'warning', 'failed' => 'danger', 'skipped' => 'secondary'];
        $rows = '';
        $tplRow = $this->backend->template('record.import_protocol_row');
        foreach ($result['items'] ?? [] as $item) {
            $status = (string)$item['status'];
            $kind = (string)($item['kind'] ?? 'record');
            $id = (int)($item['id'] ?? 0);
            $teaser = '';
            if ($id > 0 && $status !== 'failed' && $status !== 'skipped' && $status !== 'deleted') {
                $teaser = match ($kind) {
                    'record' => $this->backend->teaser('record', $id, linked: true, icon: false),
                    'area' => $this->backend->teaser('area', $id, linked: true, icon: false),
                    'template' => $this->backend->teaser('template', $id, linked: true, icon: false),
                    'converter' => $this->backend->teaser('converter', $id, linked: true, icon: false),
                    default => '',
                };
            }
            if ($teaser === '') $teaser = htmlspecialchars(trim(($id > 0 ? "[{$id}] " : '') . (string)($item['label'] ?? '')));
            $rows .= strtr($tplRow, [
                '{class}' => $classes[$status] ?? 'secondary',
                '{kind}' => htmlspecialchars($this->cms->snippet('record.import_kind_' . $kind)),
                '{record}' => $teaser,
                '{status}' => htmlspecialchars($this->cms->snippet('record.import_status_' . $status)),
                '{messages}' => htmlspecialchars(implode(' | ', (array)($item['messages'] ?? []))),
            ]) . "\n";
        }
        return $this->backend->template('record.import_protocol', [
            '{class}' => ($result['failed'] ?? 0) > 0 ? 'warning' : 'success',
            '{summary}' => htmlspecialchars($this->summaryText($job, $result) . (!empty($result['error']) ? ' ' . $result['error'] : '')),
            '{rows}' => $rows,
        ]);
    }

    // ====================
    // Hilfsfunktionen

    private function _schemaOf(int $areaId): array
    {
        if ($areaId < 1) return [];
        return $this->schemaMemo[$areaId] ??= $this->record->virtualSchema($areaId, false);
    }

    /**
     * Area of a record (0 if unknown).
     */
    private function _recordAreaId(int $recordId): int
    {
        $this->db->pushResult();
        $this->db->query("SELECT `area_id` FROM `record` WHERE `record_id` = ?", 'i', [$recordId]);
        $row = $this->db->fetch();
        $this->db->popResult();
        return (int)($row['area_id'] ?? 0);
    }

    private function _areaOf(int $areaId): ?array
    {
        static $memo = [];
        if (array_key_exists($areaId, $memo)) return $memo[$areaId];
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `area` WHERE `area_id` = ? AND `deleted` = 0", 'i', [$areaId]);
        $row = $this->db->fetch();
        $this->db->popResult();
        return $memo[$areaId] = $row;
    }

    private function _areaLabel(int $areaId): string
    {
        return $this->cms->_strip_tags($this->cms->textOrSnippet((string)($this->_areaOf($areaId)['label'] ?? '')));
    }

    /**
     * Languages of the instance of an area: ['instance_id', 'language_ids', 'primary_id']
     */
    private function _languagesOf(int $areaId): array
    {
        $instanceId = (int)($this->_areaOf($areaId)['instance_id'] ?? 0);
        $languageIds = $this->instanceCache->languageIds($instanceId);
        $primaryId = $this->instanceCache->primaryLanguageId($instanceId);
        if (empty($languageIds)) $languageIds = [$primaryId];
        return ['instance_id' => $instanceId, 'language_ids' => $languageIds, 'primary_id' => $primaryId];
    }

    /**
     * Label for the log: the default field of the area from the values.
     */
    private function _labelOf(int $areaId, array $values): string
    {
        $defaultField = $this->backend->getDefaultField($areaId);
        $name = (string)($defaultField['name'] ?? '');
        if ($name === '' || !isset($values[$name])) return '';
        $value = $values[$name];
        if (is_array($value)) $value = (string)(reset($value) ?: '');
        return $this->cms->_strlimit($this->cms->_strip_tags((string)$value), 60);
    }
}
