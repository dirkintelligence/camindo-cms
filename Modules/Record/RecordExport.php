<?php
/**
 * Export of records of a content area: XML (backup), CSV, DOCX (translation).
 *
 * This is the WORK; the mask for it is RecordBackendExport (record_export.php),
 * reachable via the menu on the right of the record list. The export runs in the request.
 *
 * THE JOB
 * --------------------
 *     type        'xml' | 'csv' | 'docx'
 *     area_id     area of the records
 *     ids         record_ids (productive), determined by RecordBatch::collectIds()
 *     files       xml: export upload files (bool)
 *     portable    xml (global): mark templates for import into a foreign database -
 *                 references to templates, areas and records in the parameters of the CMS
 *                 commands become <!-- CMS:REPLACE ... --> (TemplateParserTrait::mapReferences(), types from commands.php);
 *                 RecordImport resolves them with the actual ids/names
 *     csv         csv: ['header' => bool, 'bom' => bool, 'separator' => ';',
 *                 'enclosure' => '"', 'eol' => "\r\n"]
 *     languages   csv: language_ids of the instance that are exported
 *     fields      csv: keys of the checked columns (see csvFields()),
 *                 docx: names of the checked multilingual fields
 *     from / to   docx: source and target language (language_id)
 *     token       background: token of the bgproc entry (start())
 *     admin_id    background: triggering admin (recipient of the message)
 *
 * BACKGROUND
 * --------------------
 * From admin.export_background_threshold records (default 1000, 0 = off) the export
 * always runs as a background process (Core/cron/record_export.php - EXACTLY
 * the same code as in the request), below that at the editor's request. The
 * download link then comes as an internal message (report()).
 *
 * RETENTION PERIOD
 * --------------------
 * A zip file is deleted immediately on download (RecordBackendExport::download())
 * and otherwise removed by cleanup() after admin.export_retention_hours (default 24) -
 * by the scheduler task "Delete temporary files" (type tmpcleanup, default hourly).
 *
 * STORAGE
 * --------------------
 * Each export creates var/export/{timestamp}/, containing the export file (export.xml,
 * export.csv, export.docx) and - for files - media/{record_id}_{field_id}_{language_id}-{filename}
 * (deliberately a different order of the ids than in public/media: the file should be
 * assignable to the record without lookup). At the end the directory is packed into
 * var/export/{timestamp}_{area}_{type}.zip and deleted; the zip file is delivered by
 * RecordBackendExport::download() (record_download.php).
 *
 * THREE FORMATS
 * --------------------
 *     xml   backup format, fixed: all fields of all languages as they are in the
 *           data_* tables - encrypted contents as ciphertext (base64),
 *           passwords as hash, the fallback flag `empty` per language.
 *           Thumbnails and full text are not exported (on re-import they are
 *           recreated from the upload files or source fields). The header names
 *           database, CMS version, admin, time, area, languages and the
 *           field definitions. Along with the structure the converters of the
 *           thumbnail fields travel (<converters>, see _xmlConverters()): their
 *           converter_id only applies in the same database, so each carries a
 *           hash of its settings (Converter::settingsHash()), through which the
 *           import in a foreign database finds the matching converter or
 *           creates it anew.
 *     csv   one row per record, values from the content cache of the chosen
 *           languages (resolved, i.e. with fallback from the primary language). Multilingual
 *           fields get one column per language if there is more than one language
 *           ("{name}.{code}"). Encrypted contents in plain text, passwords
 *           as hash, date/time values as stored (UTC). Multi-value cells
 *           (links) separated by " | ".
 *     docx  for translation: one table per record, gray rows carry the metadata for the
 *           re-import (record_id, language_id, field), the white rows the content of the
 *           source language to be overwritten. All records in export.docx or (split) one file
 *           "{record_id}-{default field}.docx" per record - e.g. to distribute them to several
 *           translators; the re-import takes each file on its own.
 *
 * EMBEDDED CONTENTS (fields of type link)
 * --------------------
 * The target records of a link field belong to the record (LinkTrait) - in CSV
 * and DOCX they appear like fields of the record itself:
 *
 *     csv   "{link}.record_id", "{link}.area_id" and EVERY field of the target areas
 *           (linkTargetFields(): also uploads and thumbnails - as the path of the
 *           exported file; multilingual ones with one column per language). Per
 *           embedded content one value, in the order of the field separated by " | ".
 *           RecordImport reads the columns back (except thumbnails).
 *     docx  the multilingual fields of the target areas ("{link}.{field}" in
 *           docxFields()); per content and field one meta row
 *           "field={link}.{field} | record_id={id}" below the fields of the record.
 *
 * @see Camindo\Core\Modules\Record\RecordBackendExport  the masks
 * @see Camindo\Core\Modules\Record\RecordBatch          scope (filterParams, collectIds)
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\BackgroundProcess;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Converter\Converter;
use Camindo\Core\Modules\Messaging\Message;
use Camindo\Core\Modules\Snippets\Snippets;
use Camindo\Core\Modules\Template\Template;

class RecordExport {

    public const TYPES = ['xml', 'csv', 'docx'];

    /** Type identifier of the background process (table `bgproc`) */
    public const BGPROC_TYPE = 'export';

    /** Format identifier in the XML root element - increase when the structure changes
     *  (1: one area, records only; 2: several areas, structure, templates;
     *  since 2026-09-15 also the optional block <converters> - files without it
     *  remain readable, hence no new identifier) */
    public const XML_FORMAT = 2;

    /** Columns of `field` that are exported as child elements (long texts) */
    private const FIELD_TEXT_COLUMNS = ['label', 'note', 'tooltip', 'pattern', 'default', 'details'];

    /** Columns of `field` that are not exported (keys or derived) */
    private const FIELD_SKIP_COLUMNS = ['field_id', 'area_id', 'rel_field_id', 'rel_area_id', 'parent_field_id'];

    /** Columns from `data_fil` that can be selected individually in the CSV */
    public const FILE_COLUMNS = ['file_language_id', 'extension', 'mime_type', 'filename', 'size', 'width', 'height', 'token', 'version', 'data'];

    /**
     * Meta fields that are not offered in the CSV: internal flags, the
     * derived tree columns and the position - the order is contained in the
     * sorting of the file (exportSort()), the import inserts in this order.
     */
    private const META_HIDDEN = ['tmp', 'deleted', 'instance_id', 'parent_ids', 'position', 'lft', 'rgt'];

    /** Separator for multi-value CSV cells (links) */
    private const MULTI_SEPARATOR = ' | ';

    /** Content caches of foreign records (link targets): "{id}.{lang}" => cache */
    private array $cacheMemo = [];

    /** Areas (Backend::areaData format without backend): area_id => row */
    private array $areaMemo = [];

    /** Schemas of foreign areas: area_id => virtual schema */
    private array $schemaMemo = [];

    /** exported files: source path => target name (each file only once) */
    private array $copiedFiles = [];

    /** exported records of the running export */
    private int $exportedRecords = 0;

    /** converter_ids of the exported thumbnail fields (_xmlArea() collects, _xmlConverters() writes) */
    private array $converterIds = [];

    /** Custom snippets of the labels: instance_id => [key => true] (_xmlArea() collects, _xmlSnippets() writes) */
    private array $snippetRefs = [];

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Session $session,
        private System $system,
        private InstanceCache $instanceCache,
        private Record $record,
        private BackgroundProcess $bgproc,
        private Message $message,
        private Template $template,
        private Snippets $snippets,
        private \Camindo\Core\Access $access,
    )
    {
    }

    // ====================
    // Auftrag

    /**
     * Assemble and check the job of an export - for the mask
     * (RecordBackendExport) and the API (record.export). Only readable languages; for
     * DOCX both languages must be readable. The caller checks the rights to the format
     * (RecordService::transferTypes()).
     *
     * @param string $type     xml | csv | docx
     * @param int    $areaId   area
     * @param int[]  $ids      record_ids (productive), in export order
     * @param array  $options  xml: files (bool); csv: header, bom (bool), separator,
     *                         enclosure, eol (text, '\r\n' …), languages (language_ids),
     *                         fields (field keys from csvFields()); docx: from, to
     *                         (language_id), fields (docxFields()), split (bool:
     *                         one record per file)
     * @return array|null      job for run()/start(), null on errors (Cms::errors)
     */
    public function job(string $type, int $areaId, array $ids, array $options = []): ?array
    {
        if (!in_array($type, self::TYPES, true)) {
            $this->cms->error($this->cms->snippet('error_param_invalid'), 'type');
            return null;
        }
        $job = ['type' => $type, 'area_id' => $areaId, 'ids' => array_values($this->system->sanitizeIds($ids, true))];
        if ($type === 'xml') {
            $job['files'] = !empty($options['files']);
        }
        elseif ($type === 'csv') {
            $job['csv'] = [
                'header' => !empty($options['header']),
                'bom' => !empty($options['bom']),
                'separator' => (string)($options['separator'] ?? ';'),
                'enclosure' => (string)($options['enclosure'] ?? '"'),
                'eol' => (string)($options['eol'] ?? '\r\n'),
            ];
            // (only readable languages - a manipulated form does not count)
            $areaData = $this->_areaOf($areaId) ?? [];
            $readable = $this->record->readableLanguages($this->instanceCache->languages((int)($areaData['instance_id'] ?? 0)));
            $job['languages'] = array_values(array_filter(
                $this->system->sanitizeIds((array)($options['languages'] ?? [])),
                fn($id) => isset($readable[$id])
            ));
            $job['fields'] = array_values(array_filter(array_map('strval', (array)($options['fields'] ?? []))));
        }
        else {
            $job['from'] = (int)($options['from'] ?? 0);
            $job['to'] = (int)($options['to'] ?? 0);
            // one record per file instead of all records in one
            $job['split'] = !empty($options['split']);
            if (!$this->access->languageRead($job['from']) || !$this->access->languageRead($job['to'])) {
                $this->cms->error($this->cms->snippet('error_access_denied'));
                return null;
            }
            $job['fields'] = array_values(array_filter(array_map('strval', (array)($options['fields'] ?? []))));
        }
        if (empty($job['ids'])) {
            $this->cms->error($this->cms->snippet('record.error_export_no_records'));
            return null;
        }
        return $job;
    }

    // ====================
    // Schwellwerte

    /**
     * Should the export go to the background? From admin.export_background_threshold
     * records on (0 = offloading off).
     */
    public function needsBackground(int $count): bool
    {
        $threshold = $this->backgroundThreshold();
        if ($threshold < 1) return false;
        return $count >= $threshold;
    }

    public function backgroundThreshold(): int
    {
        return (int)($this->config->get('admin.export_background_threshold') ?? 1000);
    }

    /**
     * Retention period of an export file in hours (admin.export_retention_hours).
     */
    public function retentionHours(): int
    {
        return max(1, (int)($this->config->get('admin.export_retention_hours') ?? 24));
    }

    /**
     * Point in time (UTC, ISO) until which a file created now remains available.
     */
    public function validUntil(int $time = 0): string
    {
        if ($time < 1) $time = time();
        return gmdate('Y-m-d H:i:s', $time + $this->retentionHours() * 3600);
    }

    // ====================
    // Field lists for the masks

    /**
     * Fields of an area for the CSV export: the input fields in their
     * order, then the remaining meta fields (without META_HIDDEN - even if
     * `position` is defined as an input field of the area).
     *
     * Per entry: name, label, type, field_id, meta, checked (default) and - for
     * link and file fields - 'sub': the expandable subfields
     * (key => [label, checked]). The key of a subfield is "{name}.{sub}";
     * for links 'record_id' is the default, followed by the virtual fields
     * of the target area (without link and file fields), for file fields the
     * columns from `data_fil`. For file fields the main key "{name}" means
     * "export file" - preselected only for upload fields, not for thumbnails.
     *
     * @return array  name => entry
     */
    public function csvFields(int $areaId): array
    {
        $schemaVirtual = $this->record->virtualSchema($areaId, false);
        $fields = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if (in_array($name, self::META_HIDDEN, true)) continue;
            $type = (string)($colDef['type'] ?? 'text');
            $entry = [
                'name' => $name,
                'label' => (string)($colDef['label'] ?? $name),
                'type' => $type,
                'field_id' => (int)($colDef['field_id'] ?? 0),
                'meta' => !empty($colDef['meta']),
                'multilingual' => $this->cms->isMultilingualField($colDef),
                'checked' => true,
                'sub' => [],
            ];
            if (in_array($type, $this->cms->dependentTypes, true)) {
                // field of type link: the embedded contents belong to the record -
                // all fields of the target areas, also uploads and thumbnails
                // (EMBEDDED CONTENTS)
                $targetAreaIds = $this->record->linkTargetAreaIds($colDef);
                $entry['sub']['record_id'] = ['label' => 'record_id', 'checked' => true];
                $entry['sub']['area_id'] = ['label' => 'area_id', 'checked' => count($targetAreaIds) > 1];
                foreach ($this->record->linkTargetFields($colDef) as $targetName => $targetDef) {
                    $isFile = (string)($targetDef['type'] ?? '') === 'file';
                    $entry['sub'][$targetName] = [
                        'label' => (string)($targetDef['label'] ?? $targetName),
                        'type' => (string)($targetDef['type'] ?? ''),
                        'checked' => false,
                        'multilingual' => $this->cms->isMultilingualField($targetDef),
                        'file' => $isFile,
                    ];
                }
                $entry['checked'] = false;
                $entry['relation'] = true;
                $entry['link'] = true;
            }
            elseif (in_array($type, $this->cms->relationTypes, true)) {
                $entry['sub']['record_id'] = ['label' => 'record_id', 'checked' => true];
                foreach ($this->_relationTargetFields($colDef) as $targetName => $targetDef) {
                    $entry['sub'][$targetName] = [
                        'label' => (string)($targetDef['label'] ?? $targetName),
                        'checked' => false,
                        'multilingual' => $this->cms->isMultilingualField($targetDef),
                    ];
                }
                // the main key of a link is not a value of its own
                $entry['checked'] = false;
                $entry['relation'] = true;
            }
            elseif ($type === 'file') {
                $entry['checked'] = empty($colDef['parent_field_id']);
                $entry['file'] = true;
                foreach (self::FILE_COLUMNS as $col) {
                    $entry['sub'][$col] = ['label' => $col, 'checked' => false];
                }
            }
            $fields[$name] = $entry;
        }
        foreach ($this->cms->schema as $name => $colDef) {
            if (isset($fields[$name]) || in_array($name, self::META_HIDDEN, true)) continue;
            $fields[$name] = [
                'name' => $name,
                'label' => (string)($colDef['label'] ?? $name),
                'type' => (string)($colDef['type'] ?? 'text'),
                'field_id' => 0,
                'meta' => true,
                'multilingual' => false,
                'checked' => in_array($name, ['record_id', 'area_id'], true),
                'sub' => [],
            ];
        }
        return $fields;
    }

    /**
     * Sorting of the records in the export file: areas with a tree in the
     * order of the tree structure (`lft`), areas with manual sorting by
     * `position`, otherwise by record_id. The import inserts in the order of the file
     * (RecordImport, INSERTION POSITION) - so the order survives the way
     * through the file.
     *
     * @return array  [sort, order] for Cms::select() / RecordBatch::collectIds()
     */
    public function exportSort(int $areaId): array
    {
        $schemaVirtual = $this->record->virtualSchema($areaId, false);
        if (isset($schemaVirtual['parent_id'])) return ['lft', 'ASC'];
        if (isset($schemaVirtual['position'])) return ['position', 'ASC'];
        return ['record_id', 'ASC'];
    }

    /**
     * Multilingual fields of an area for the DOCX export (without file fields) -
     * plus the multilingual fields of the embedded contents (fields of type link)
     * as "{link}.{field}"; their colDef carries 'link' (name of the link field), 'sub'
     * (field name in the target area) and the composed label.
     *
     * @return array  name => colDef
     */
    public function docxFields(int $areaId): array
    {
        $fields = [];
        $links = [];
        foreach ($this->record->virtualSchema($areaId, false) as $name => $colDef) {
            if (in_array((string)($colDef['type'] ?? ''), $this->cms->dependentTypes, true)) {
                $links[$name] = $colDef;
                continue;
            }
            if ((string)($colDef['type'] ?? '') === 'file') continue;
            if (!$this->cms->isMultilingualField($colDef)) continue;
            $fields[$name] = $colDef;
        }
        foreach ($links as $name => $colDef) {
            foreach ($this->record->linkTargetFields($colDef, multilingualOnly: true) as $sub => $targetDef) {
                if ((string)($targetDef['type'] ?? '') === 'file') continue;
                $targetDef['link'] = $name;
                $targetDef['sub'] = $sub;
                $targetDef['label'] = $this->cms->textOrSnippet((string)($colDef['label'] ?? $name))
                    . ' › ' . $this->cms->textOrSnippet((string)($targetDef['label'] ?? $sub));
                $fields["$name.$sub"] = $targetDef;
            }
        }
        return $fields;
    }

    /**
     * The embedded contents of a record in a link field, in their
     * order - only productive ones (while a content is checked out, the
     * record also holds an edge to its working copy).
     *
     * @return array[]  `record` rows
     */
    private function _linkDestRows(int $recordId, int $fieldId): array
    {
        $rows = [];
        foreach ($this->_relationRows($recordId, $fieldId) as $rel) {
            $destRow = $this->_recordRow((int)$rel['dest_id']);
            if ($destRow) $rows[] = $destRow;
        }
        return $rows;
    }

    /**
     * Virtual fields of the target areas of a link field - for a pointer field
     * the union across all target areas (by name). Without link and file fields:
     * no links of links.
     */
    private function _relationTargetFields(array $colDef): array
    {
        $type = (string)($colDef['type'] ?? '');
        $areaIds = str_starts_with($type, 'relation')
            ? [(int)($colDef['rel_area_id'] ?? 0)]
            : $this->record->getRelAreaIds((int)$colDef['field_id']);
        $fields = [];
        foreach ($this->system->sanitizeIds($areaIds, true) as $areaId) {
            foreach ($this->_schemaOf((int)$areaId) as $name => $targetDef) {
                $targetType = (string)($targetDef['type'] ?? '');
                if ($targetType === 'file' || in_array($targetType, $this->cms->relationTypes, true)) continue;
                $fields[$name] ??= $targetDef;
            }
        }
        return $fields;
    }

    // ====================
    // Run

    /**
     * Run the export: create the directory, write the file(s), pack, clean up.
     *
     * @return array  ['zip' => file name in var/export, 'records' => n, 'files' => n,
     *                 'size' => bytes of the zip file] or ['error' => message]
     */
    public function run(array $job): array
    {
        $type = (string)($job['type'] ?? '');
        $areaId = (int)($job['area_id'] ?? 0);
        $areaIds = $this->system->sanitizeIds((array)($job['area_ids'] ?? []), true);
        $isGlobal = !empty($areaIds);
        $ids = $this->system->sanitizeIds((array)($job['ids'] ?? []), true);
        if (!in_array($type, self::TYPES, true) || ($areaId < 1 && !$isGlobal) || ($isGlobal && $type !== 'xml')) {
            return ['error' => 'invalid job'];
        }
        $areaData = $isGlobal ? ['name' => 'global'] : $this->_areaOf($areaId);
        if (!$areaData) return ['error' => $this->cms->snippet('area.error_area_not_found')];

        $varDir = (string)$this->config->get('paths.var_dir');
        $stamp = gmdate('Y-m-d_His');
        $dirName = $stamp;
        $n = 1;
        while (is_dir("$varDir/export/$dirName")) {
            $dirName = $stamp . '_' . (++$n);
        }
        if (!$this->system->createDir($varDir, "export/$dirName")) {
            return ['error' => 'var/export not writable'];
        }
        $dir = "$varDir/export/$dirName";
        $this->copiedFiles = [];

        $job['ids'] = $ids;
        $job['area'] = $areaData;
        $job['area_ids'] = $areaIds;
        $job['dir'] = $dir;
        $job['stamp'] = $stamp;
        $this->exportedRecords = 0;

        try {
            match ($type) {
                'xml'  => $this->_exportXml($job),
                'csv'  => $this->_exportCsv($job),
                'docx' => $this->_exportDocx($job),
            };
            $areaName = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($areaData['name'] ?? 'area')));
            $zipName = $dirName . '_' . ($areaName !== '' ? $areaName : 'area') . '_' . $type . '.zip';
            $this->_zipDir($dir, "$varDir/export/$zipName");
        }
        catch (\Throwable $e) {
            $this->_removeDir($dir);
            return ['error' => $e->getMessage()];
        }
        $this->_removeDir($dir);

        return [
            'zip' => $zipName,
            'records' => $this->exportedRecords,
            'files' => count($this->copiedFiles),
            'size' => (int)filesize("$varDir/export/$zipName"),
        ];
    }

    /**
     * Zip files in var/export, newest first.
     *
     * @return array  file name => ['size' => bytes, 'time' => Unix time]
     */
    public function zipFiles(): array
    {
        $dir = (string)$this->config->get('paths.var_dir') . '/export';
        $files = [];
        foreach (glob("$dir/*.zip") ?: [] as $path) {
            $files[basename($path)] = ['size' => (int)filesize($path), 'time' => (int)filemtime($path)];
        }
        uasort($files, fn($a, $b) => $b['time'] <=> $a['time']);
        return $files;
    }

    // ====================
    // XML

    /**
     * Backup format (XML_FORMAT 2). Structure:
     *
     *     <camindo-export format="2">
     *       <header> database, cms-version, admin, exported-at, structure, content,
     *                instances, languages, count, files </header>
     *       <areas>                                        (structure)
     *         <area area_id parent_id instance_id type name icon position sort_order
     *               default_field_id sort_field_id default_template_id
     *               login_name_field_id login_password_field_id
     *               privacy_policy_id (deletion policy; counts only in the same database)>
     *           <label/> <note/> <details/>
     *           <access template_id selectable override/>   (rel_area_template)
     *           <triggers> <trigger template_id/> </triggers> (rel_area_trigger, in Reihenfolge)
     *           <fields> <field field_id name type ...Spalten...> <label/> <default/>
     *                    <details/> <rel_area_ids><area_id/></rel_area_ids>
     *                    <source_fields><field_id/></source_fields>
     *                    <converter_ids><converter_id/></converter_ids> </field> </fields>
     *         </area>
     *       </areas>
     *       <converters>                                   (structure, Thumbnail-Felder)
     *         <converter converter_id hash processor type extension>
     *           <label/> <media_types_incl/> <media_types_excl/> <details/>
     *         </converter>
     *       </converters>
     *       <snippets>                                     (structure, custom snippets of the
     *         <snippet instance_id key>                     Beschriftungen #custom.{id}.{key})
     *           <text lang=".."/>
     *         </snippet>
     *       </snippets>
     *       <records>                                      (content, Inhaltsbereiche)
     *         <record record_id=".." area_id=".." ...meta...>
     *           <field name=".." type=".." field_id="..">
     *             <value language_id=".." [empty="1"] [encoding="base64"]>..</value>   (Skalare)
     *             <file language_id=".." file_language_id=".." extension=".." ... [path="media/.."]><data>{json}</data></file>
     *             <relation dest_id=".." position=".."/>
     *           </field>
     *         </record>
     *       </records>
     *       <templates>                                    (content, Template-Bereiche)
     *         <template template_id area_id name type standalone> <label/> <code/> </template>
     *       </templates>
     *     </camindo-export>
     *
     * The export from a record list (area_id + ids) writes its area with fields
     * and the chosen records; the global export (area_ids) the chosen areas of all
     * types - structure and/or contents (all productive records or templates).
     *
     * Scalar values are stored per language as they lie in the data_* table;
     * `empty="1"` marks the fallback from the primary language. Encrypted
     * contents remain ciphertext (base64, encoding="base64"), passwords are the
     * hash. Thumbnail and full-text fields are missing from the records (see
     * class comment) - they are included in the structure.
     */
    private function _exportXml(array $job): void
    {
        $withFiles = !empty($job['files']);
        $isGlobal = !empty($job['area_ids']);
        $withStructure = $isGlobal ? !empty($job['structure']) : true;
        $withContent = $isGlobal ? !empty($job['content']) : true;
        $areaIds = $isGlobal ? $job['area_ids'] : [(int)$job['area_id']];

        // areas in tree order (parents before children - the import creates them this way)
        $areas = [];
        $placeholders = implode(',', array_fill(0, count($areaIds), '?'));
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `area` WHERE `area_id` IN ($placeholders) AND `deleted` = 0 ORDER BY `lft` ASC", str_repeat('i', count($areaIds)), $areaIds);
        foreach ($this->db->fetchAll() as $row) $areas[(int)$row['area_id']] = $row;
        $this->db->popResult();
        $instanceIds = array_values(array_unique(array_map(fn($a) => (int)$a['instance_id'], $areas)));

        $xml = new \XMLWriter();
        $xml->openUri($job['dir'] . '/export.xml');
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('camindo-export');
        $xml->writeAttribute('format', (string)self::XML_FORMAT);

        // header
        // --------------------
        $xml->startElement('header');
        $xml->writeElement('database', (string)($this->config->get('db.name') ?? ''));
        $xml->writeElement('cms-version', defined('CAMINDO_VERSION') ? CAMINDO_VERSION : '');
        $xml->startElement('admin');
        $xml->writeAttribute('admin_id', (string)$this->_adminId());
        $xml->text($this->_adminName());
        $xml->endElement();
        $xml->writeElement('exported-at', gmdate('c'));
        $xml->writeElement('structure', $withStructure ? '1' : '0');
        $xml->writeElement('content', $withContent ? '1' : '0');
        $xml->startElement('instances');
        foreach ($instanceIds as $instanceId) {
            $instance = $this->instanceCache->instance($instanceId) ?? [];
            $xml->startElement('instance');
            $xml->writeAttribute('instance_id', (string)$instanceId);
            $xml->writeAttribute('primary_language_id', (string)$this->instanceCache->primaryLanguageId($instanceId));
            $xml->text($this->cms->textOrSnippet((string)($instance['label'] ?? '')));
            $xml->endElement();
        }
        $xml->endElement();
        $xml->startElement('languages');
        $written = [];
        foreach ($instanceIds as $instanceId) {
            $primaryId = $this->instanceCache->primaryLanguageId($instanceId);
            foreach ($this->instanceCache->languages($instanceId) as $languageId => $language) {
                $xml->startElement('language');
                $xml->writeAttribute('language_id', (string)$languageId);
                $xml->writeAttribute('code', (string)($language['code'] ?? ''));
                $xml->writeAttribute('instance_id', (string)$instanceId);
                if ((int)$languageId === $primaryId) $xml->writeAttribute('primary', '1');
                $xml->text((string)($language['label'] ?? ''));
                $xml->endElement();
                $written[(int)$languageId] = true;
            }
        }
        $xml->endElement();
        // count in advance (for the analysis at import)
        $recordCount = 0;
        $recordsByArea = [];
        if ($withContent) {
            foreach ($areas as $areaRow) {
                if (!in_array((string)$areaRow['type'], ['record', 'profile', 'ugc'], true)) continue;
                $recordsByArea[(int)$areaRow['area_id']] = $isGlobal
                    ? $this->_productiveIds((int)$areaRow['area_id'])
                    : $job['ids'];
                $recordCount += count($recordsByArea[(int)$areaRow['area_id']]);
            }
        }
        $xml->writeElement('count', (string)$recordCount);
        $xml->writeElement('files', $withFiles ? '1' : '0');
        $xml->endElement(); // header

        // areas (structure)
        // --------------------
        $this->converterIds = [];
        $this->snippetRefs = [];
        $xml->startElement('areas');
        foreach ($areas as $areaRow) {
            $this->_xmlArea($xml, $areaRow, $withStructure);
        }
        $xml->endElement();

        // converters of the thumbnail fields
        // --------------------
        $this->_xmlConverters($xml);

        // custom snippets of the labels and of the exported templates
        // --------------------
        if ($withContent) $this->_collectTemplateSnippetRefs($areas);
        $this->_xmlSnippets($xml);

        // records
        // --------------------
        $xml->startElement('records');
        foreach ($recordsByArea as $areaId => $ids) {
            $schemaVirtual = $this->_schemaOf((int)$areaId);
            foreach ($ids as $recordId) {
                $row = $this->_recordRow((int)$recordId);
                if (!$row || (int)$row['area_id'] !== (int)$areaId) continue;
                $this->_xmlRecord($xml, $row, $schemaVirtual, $withFiles, $job['dir']);
                $this->exportedRecords++;
                $xml->flush();
            }
        }
        $xml->endElement();

        // templates
        // --------------------
        $xml->startElement('templates');
        if ($withContent) {
            foreach ($areas as $areaRow) {
                if ((string)$areaRow['type'] !== 'template') continue;
                $this->db->pushResult();
                $this->db->query("SELECT * FROM `template` WHERE `area_id` = ? ORDER BY `name` ASC", 'i', [(int)$areaRow['area_id']]);
                $templates = $this->db->fetchAll();
                $this->db->popResult();
                foreach ($templates as $template) {
                    $xml->startElement('template');
                    foreach (['template_id', 'area_id', 'name', 'type', 'standalone', 'created_by', 'created_at', 'changed_at'] as $attr) {
                        $xml->writeAttribute($attr, (string)($template[$attr] ?? ''));
                    }
                    $xml->writeElement('label', $this->_xmlText((string)$template['label']));
                    $code = (string)$template['code'];
                    if (!empty($job['portable'])) $code = $this->_markTemplateReferences($code);
                    $xml->writeElement('code', $this->_xmlText($code));
                    $xml->endElement();
                }
                $xml->flush();
            }
        }
        $xml->endElement();

        $xml->endElement(); // camindo-export
        $xml->endDocument();
        $xml->flush();
    }

    /**
     * Mark references in the template code (option "portable"):
     *
     *     <!-- CMS:REPLACE template_id=22 -->      <!-- CMS:REPLACE template_name=header -->
     *     <!-- CMS:REPLACE area_id=50 -->          <!-- CMS:REPLACE area_name=artikel -->
     *     <!-- CMS:REPLACE record_id=1758 slug="impressum" -->   (slug, if the record has one -
     *                                                            as URL path with slug prefix, "seiten/impressum")
     *
     * The marker replaces the value in place; which parameters carry references
     * is stated by commands.php ('ref'). A template marked this way is only marked in the
     * export file - the import puts the resolved values back in.
     */
    private function _markTemplateReferences(string $code): string
    {
        return $this->template->mapReferences($code, function (string $ref, string $value): ?string {
            $value = trim($value);
            if ($value === '') return null;
            $isNumeric = ctype_digit($value);
            if ($ref === 'record') {
                if (!$isNumeric) return null;
                $slug = $this->_recordPath((int)$value);
                return '<!-- CMS:REPLACE record_id=' . (int)$value . ($slug !== '' ? ' slug="' . $slug . '"' : '') . ' -->';
            }
            if ($ref === 'template' || $ref === 'area') {
                if (preg_match('/[^a-z0-9_\-.]/i', $value)) return null; // not a simple name
                return '<!-- CMS:REPLACE ' . $ref . '_' . ($isNumeric ? 'id' : 'name') . '=' . $value . ' -->';
            }
            return null;
        });
    }

    /**
     * URL path of a record in the primary language: slug prefix of the area +
     * field `slug` ("seiten/impressum", Cms::recordUrl()) - '' without field or value.
     * The import resolves it like a call of the page (Cms::recordIdsBySlugPath()).
     */
    private function _recordPath(int $recordId): string
    {
        $row = $this->_recordRow($recordId);
        if (!$row) return '';
        $schema = $this->_schemaOf((int)$row['area_id']);
        if (!isset($schema['slug']) || (string)($schema['slug']['type'] ?? '') !== 'chars') return '';
        $primaryId = $this->instanceCache->primaryLanguageId((int)$row['instance_id']);
        $cache = $this->_cacheOf($recordId, $primaryId, $row);
        $slug = trim((string)($cache['slug'][1] ?? ''));
        if (!preg_match('/^[a-z0-9_\-.\/]+$/i', $slug)) return '';
        $prefix = $this->instanceCache->slugPrefix((int)$row['area_id'], $primaryId);
        return ($prefix !== '') ? $prefix . '/' . $slug : $slug;
    }

    /**
     * Ids of all productive records of an area (global export).
     */
    private function _productiveIds(int $areaId): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 0 AND `deleted` = 0 ORDER BY `lft` ASC, `position` ASC, `record_id` ASC", 'i', [$areaId]);
        $ids = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
        return $ids;
    }

    /**
     * Write an area as a blueprint file: the same format as the files in
     * Core/Modules/Area/blueprints/ (one <area> with settings, assignments and
     * fields, no records) - for duplicating an area
     * (AreaBackend::_duplicateSave() -> RecordImport::importBlueprint()).
     *
     * @return string  path of the file in var/tmp (the caller deletes it), '' if
     *                 the area does not exist
     */
    public function areaBlueprintFile(int $areaId): string
    {
        $areaRow = $this->_areaOf($areaId);
        if ($areaRow === null) return '';
        $path = (string)$this->config->get('paths.tmp_dir') . '/blueprint-' . $areaId . '-' . $this->crypt->randomKey(8) . '.xml';
        $xml = new \XMLWriter();
        if (!$xml->openUri($path)) return '';
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('camindo-export');
        $xml->writeAttribute('format', (string)self::XML_FORMAT);
        $this->converterIds = [];
        $this->snippetRefs = [];
        $xml->startElement('areas');
        $this->_xmlArea($xml, $areaRow, true);
        $xml->endElement();
        $this->_xmlConverters($xml);
        $this->_xmlSnippets($xml);
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
        return $path;
    }

    /**
     * An area with template assignments and - for content areas with
     * structure - its fields.
     */
    private function _xmlArea(\XMLWriter $xml, array $areaRow, bool $withFields): void
    {
        $areaId = (int)$areaRow['area_id'];
        $xml->startElement('area');
        foreach (['area_id', 'parent_id', 'instance_id', 'type', 'name', 'icon', 'position', 'sort_order', 'dependent', 'default_field_id', 'sort_field_id', 'default_template_id', 'list_template_id', 'overview_template_id', 'login_name_field_id', 'login_password_field_id', 'privacy_policy_id'] as $attr) {
            $value = $areaRow[$attr] ?? null;
            if ($value === null || $value === '') continue;
            $xml->writeAttribute($attr, (string)$value);
        }
        $xml->writeElement('label', $this->_xmlText((string)$areaRow['label']));
        if ((string)$areaRow['note'] !== '') $xml->writeElement('note', $this->_xmlText((string)$areaRow['note']));
        $this->_collectSnippetRefs((string)$areaRow['label'] . ' ' . (string)$areaRow['note']);
        $details = (string)($areaRow['details'] ?? '');
        if ($details !== '' && $details !== '{}' && $details !== '[]') $xml->writeElement('details', $details);
        $this->db->pushResult();
        $this->db->query("SELECT `template_id`, `selectable`, `override` FROM `rel_area_template` WHERE `area_id` = ?", 'i', [$areaId]);
        foreach ($this->db->fetchAll() as $access) {
            $xml->startElement('access');
            $xml->writeAttribute('template_id', (string)$access['template_id']);
            $xml->writeAttribute('selectable', (string)(int)$access['selectable']);
            $xml->writeAttribute('override', (string)(int)$access['override']);
            $xml->endElement();
        }
        // trigger templates in their order - the block is always there, even
        // empty: the import thereby sets "none" (without the block the target state would remain)
        $this->db->query("SELECT `template_id` FROM `rel_area_trigger` WHERE `area_id` = ? ORDER BY `position`, `template_id`", 'i', [$areaId]);
        $xml->startElement('triggers');
        foreach ($this->db->fetchAll() as $templateId) {
            $xml->startElement('trigger');
            $xml->writeAttribute('template_id', (string)(int)$templateId);
            $xml->endElement();
        }
        $xml->endElement();
        $this->db->popResult();

        if ($withFields && in_array((string)$areaRow['type'], ['record', 'profile', 'ugc'], true)) {
            $xml->startElement('fields');
            $this->db->pushResult();
            $this->db->query("SELECT * FROM `field` WHERE `area_id` = ? AND `deleted` = 0 ORDER BY `position` ASC", 'i', [$areaId]);
            $fields = $this->db->fetchAll();
            $this->db->popResult();
            foreach ($fields as $field) {
                $xml->startElement('field');
                $xml->writeAttribute('field_id', (string)$field['field_id']);
                foreach (['rel_field_id', 'rel_area_id', 'parent_field_id'] as $attr) {
                    if ((int)($field[$attr] ?? 0) > 0) $xml->writeAttribute($attr, (string)$field[$attr]);
                }
                foreach ($field as $col => $value) {
                    if (in_array($col, self::FIELD_SKIP_COLUMNS, true) || in_array($col, self::FIELD_TEXT_COLUMNS, true)) continue;
                    if ($value === null) continue;
                    $xml->writeAttribute($col, (string)$value);
                }
                foreach (self::FIELD_TEXT_COLUMNS as $col) {
                    $value = (string)($field[$col] ?? '');
                    if ($value === '' || $value === '{}' || $value === '[]') continue;
                    $xml->writeElement($col, $this->_xmlText($value));
                    if (in_array($col, ['label', 'note', 'tooltip'], true)) $this->_collectSnippetRefs($value);
                }
                $fieldId = (int)$field['field_id'];
                $type = (string)$field['type'];
                if ($type === 'file' && (string)$field['display_type'] === 'thumbnail') {
                    // Konverter in ihrer Reihenfolge (`rel_field_converter`)
                    $xml->startElement('converter_ids');
                    foreach ($this->record->getConverterIds($fieldId) as $converterId) {
                        $xml->writeElement('converter_id', (string)$converterId);
                        $this->converterIds[(int)$converterId] = true;
                    }
                    $xml->endElement();
                }
                if (in_array($type, $this->cms->multiAreaTypes, true)) {
                    $xml->startElement('rel_area_ids');
                    foreach ($this->record->getRelAreaIds($fieldId) as $relAreaId) {
                        $xml->writeElement('area_id', (string)$relAreaId);
                    }
                    $xml->endElement();
                }
                if ($type === 'fulltext') {
                    $this->db->pushResult();
                    $this->db->query("SELECT `text_field_id`, `rel_field_id` FROM `rel_fulltext_text` WHERE `fulltext_field_id` = ?", 'i', [$fieldId]);
                    $sources = $this->db->fetchAll();
                    $this->db->popResult();
                    $xml->startElement('source_fields');
                    foreach ($sources as $source) {
                        $xml->startElement('field_id');
                        if ((int)($source['rel_field_id'] ?? 0) > 0) $xml->writeAttribute('rel_field_id', (string)$source['rel_field_id']);
                        $xml->text((string)$source['text_field_id']);
                        $xml->endElement();
                    }
                    $xml->endElement();
                }
                $xml->endElement(); // field
            }
            $xml->endElement(); // fields
        }
        $xml->endElement(); // area
    }

    /**
     * Note references to custom snippets (#custom.{instance_id}.{key}, Snippets::
     * REFERENCE_PATTERN) in a label.
     */
    private function _collectSnippetRefs(string $text): void
    {
        if (!str_contains($text, '#custom.')) return;
        if (!preg_match_all(Snippets::REFERENCE_PATTERN, $text, $matches, PREG_SET_ORDER)) return;
        foreach ($matches as $m) $this->snippetRefs[(int)$m[1]][$m[2]] = true;
    }

    /**
     * Custom snippets that the templates of the exported template areas address
     * (Snippets::templateRefs(): custom.{key} and composed keys) - otherwise after the
     * import in another instance only the keys would stand on the page.
     */
    private function _collectTemplateSnippetRefs(array $areas): void
    {
        foreach ($areas as $areaRow) {
            if ((string)$areaRow['type'] !== 'template') continue;
            $instanceId = (int)($areaRow['instance_id'] ?? 0);
            if ($instanceId < 1) continue;
            $set = $this->snippets->load($instanceId);
            if (!$set) continue;
            $this->db->pushResult();
            $this->db->query("SELECT `code` FROM `template` WHERE `area_id` = ?", 'i', [(int)$areaRow['area_id']]);
            $codes = $this->db->fetchAll();
            $this->db->popResult();
            foreach ($codes as $code) {
                foreach (Snippets::templateRefs((string)$code, $set, $instanceId) as $key) {
                    $this->snippetRefs[$instanceId][$key] = true;
                }
            }
        }
    }

    /**
     * The custom snippets that the labels of the exported areas and
     * fields refer to (<snippets>, see _exportXml()): per snippet the texts of all
     * backend languages from the set of the instance (Snippets::load()). The import creates
     * missing keys in the set of the target instance and rewrites the references to them
     * (RecordImport::_mapSnippetRefs()). Snippets that no longer exist in the set
     * are missing - the reference then remains as it is. Nothing to write: an
     * empty element.
     */
    private function _xmlSnippets(\XMLWriter $xml): void
    {
        $xml->startElement('snippets');
        ksort($this->snippetRefs);
        foreach ($this->snippetRefs as $instanceId => $keys) {
            $set = $this->snippets->load((int)$instanceId);
            foreach (array_keys($keys) as $key) {
                if (!isset($set[$key])) continue;
                $xml->startElement('snippet');
                $xml->writeAttribute('instance_id', (string)$instanceId);
                $xml->writeAttribute('key', (string)$key);
                foreach ($set[$key] as $lang => $text) {
                    if ((string)$text === '') continue;
                    $xml->startElement('text');
                    $xml->writeAttribute('lang', (string)$lang);
                    $xml->text((string)$text);
                    $xml->endElement();
                }
                $xml->endElement();
            }
        }
        $xml->endElement();
    }

    /**
     * The converters that the exported thumbnail fields refer to
     * (<converters>, see _exportXml()). Nothing to write: an empty element.
     *
     * Each converter carries, next to its converter_id, the hash of its settings
     * (Converter::settingsHash()) - the key for the import into a foreign
     * database in which the id means nothing. The label and the settings
     * are included in full so that the import can create the converter anew.
     */
    private function _xmlConverters(\XMLWriter $xml): void
    {
        $xml->startElement('converters');
        $ids = array_keys($this->converterIds);
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $this->db->pushResult();
            $this->db->query("SELECT * FROM `converter` WHERE `converter_id` IN ($placeholders) ORDER BY `converter_id` ASC", str_repeat('i', count($ids)), $ids);
            $rows = $this->db->fetchAll();
            $this->db->popResult();
            foreach ($rows as $row) {
                $xml->startElement('converter');
                $xml->writeAttribute('converter_id', (string)$row['converter_id']);
                $xml->writeAttribute('hash', Converter::settingsHash($row));
                $xml->writeAttribute('processor', (string)$row['processor']);
                $xml->writeAttribute('type', (string)$row['type']);
                $xml->writeAttribute('extension', (string)$row['extension']);
                $xml->writeElement('label', $this->_xmlText((string)$row['label']));
                foreach (['media_types_incl', 'media_types_excl', 'details'] as $col) {
                    $value = (string)($row[$col] ?? '');
                    if ($value === '' || $value === '{}' || $value === '[]') continue;
                    $xml->writeElement($col, $this->_xmlText($value));
                }
                $xml->endElement();
            }
        }
        $xml->endElement();
        $this->converterIds = [];
    }

    /**
     * A record with all fields (see _exportXml()).
     */
    private function _xmlRecord(\XMLWriter $xml, array $row, array $schemaVirtual, bool $withFiles, string $dir): void
    {
        $recordId = (int)$row['record_id'];
        $xml->startElement('record');
        foreach ($this->_metaColumns($row) as $col => $value) {
            if ($value === null) continue;
            $xml->writeAttribute($col, (string)$value);
        }
        foreach ($schemaVirtual as $name => $colDef) {
            if ($this->_xmlSkipField($colDef)) continue;
            $type = (string)$colDef['type'];
            $fieldId = (int)$colDef['field_id'];
            if (!empty($colDef['meta'])) {
                // input field on a column of `record`: the value is already
                // in the attribute
                continue;
            }
            $xml->startElement('field');
            $xml->writeAttribute('name', (string)$name);
            $xml->writeAttribute('type', $type);
            $xml->writeAttribute('field_id', (string)$fieldId);

            if (in_array($type, $this->cms->relationTypes, true)) {
                foreach ($this->_relationRows($recordId, $fieldId) as $rel) {
                    $xml->startElement('relation');
                    $xml->writeAttribute('dest_id', (string)$rel['dest_id']);
                    $xml->writeAttribute('position', (string)$rel['position']);
                    $xml->endElement();
                }
            }
            elseif ($type === 'file') {
                foreach ($this->_fileRows($recordId, $fieldId) as $fileRow) {
                    $xml->startElement('file');
                    $xml->writeAttribute('language_id', (string)$fileRow['language_id']);
                    foreach (self::FILE_COLUMNS as $col) {
                        if ($col === 'data') continue;
                        $value = $fileRow[$col];
                        if ($value === null) continue;
                        $xml->writeAttribute($col, (string)$value);
                    }
                    if ($withFiles && (string)$fileRow['extension'] !== '') {
                        $path = $this->_copyFile($row, $fieldId, $fileRow, $dir);
                        if ($path !== '') $xml->writeAttribute('path', $path);
                    }
                    $data = (string)($fileRow['data'] ?? '');
                    if ($data !== '' && $data !== '{}') $xml->writeElement('data', $data);
                    $xml->endElement();
                }
            }
            else {
                foreach ($this->_dataRows($recordId, $fieldId, $type) as $dataRow) {
                    $xml->startElement('value');
                    if ($dataRow['language_id'] !== null) {
                        $xml->writeAttribute('language_id', (string)$dataRow['language_id']);
                    }
                    if (!empty($dataRow['empty'])) $xml->writeAttribute('empty', '1');
                    $value = (string)($dataRow['data'] ?? '');
                    if ($type === 'encrypted') {
                        $xml->writeAttribute('encoding', 'base64');
                        $value = base64_encode($value);
                    }
                    $xml->text($this->_xmlText($value));
                    $xml->endElement();
                }
            }
            $xml->endElement(); // field
        }
        $xml->endElement(); // record
    }

    /**
     * Fields that do not belong in the backup: thumbnails (child of an upload field)
     * and full text - both are recreated on re-import.
     */
    private function _xmlSkipField(array $colDef): bool
    {
        if ((string)($colDef['type'] ?? '') === 'fulltext') return true;
        if ((string)($colDef['type'] ?? '') === 'file' && !empty($colDef['parent_field_id'])) return true;
        return false;
    }

    // ====================
    // CSV

    /**
     * One row per record. The columns result from the checked
     * keys (csvFields()) and the chosen languages: a multilingual field
     * gets one column per language if there is more than one language ("{key}.{code}"),
     * otherwise one column. On request the file begins with the UTF-8 BOM (Excel). Values come from the content cache of the respective language
     * (resolved); meta fields from the `record` row.
     */
    private function _exportCsv(array $job): void
    {
        $areaId = (int)$job['area_id'];
        $instanceId = (int)($job['area']['instance_id'] ?? 0);
        $settings = (array)($job['csv'] ?? []);
        $separator = $this->csvChar((string)($settings['separator'] ?? ';'), ';');
        $enclosure = $this->csvChar((string)($settings['enclosure'] ?? '"'), '"');
        $eol = $this->csvEol((string)($settings['eol'] ?? '\r\n'));
        $withHeader = !empty($settings['header']);
        $withBom = !empty($settings['bom']);

        $languageIds = $this->system->sanitizeIds((array)($job['languages'] ?? []));
        $instanceLanguages = $this->instanceCache->languages($instanceId);
        $languageIds = array_values(array_intersect(array_map('intval', array_keys($instanceLanguages)), $languageIds));
        if (empty($languageIds)) $languageIds = [$this->instanceCache->primaryLanguageId($instanceId)];
        $multiLanguage = count($languageIds) > 1;
        // column suffix per language: the code (language.code)
        $codes = [];
        foreach ($languageIds as $languageId) {
            $codes[$languageId] = (string)($instanceLanguages[$languageId]['code'] ?? $languageId);
        }

        $fields = $this->csvFields($areaId);
        $selected = array_map('strval', (array)($job['fields'] ?? []));

        // determine columns: [key, name, sub, multilingual, language_id|null]
        $columns = [];
        foreach ($fields as $name => $entry) {
            $keys = [];
            if (in_array($name, $selected, true)) $keys[''] = $entry['multilingual'];
            foreach ($entry['sub'] as $sub => $subEntry) {
                if (in_array("$name.$sub", $selected, true)) {
                    // subfields of a file follow the language of the file field,
                    // target fields of a link their own multilinguality
                    $keys[$sub] = !empty($entry['file']) ? $entry['multilingual'] : !empty($subEntry['multilingual']);
                }
            }
            foreach ($keys as $sub => $multilingual) {
                $key = $sub === '' ? $name : "$name.$sub";
                if ($multilingual && $multiLanguage) {
                    foreach ($languageIds as $languageId) {
                        $columns[] = ['key' => $key . '.' . $codes[$languageId], 'name' => $name, 'sub' => $sub, 'language_id' => $languageId];
                    }
                }
                else {
                    $columns[] = ['key' => $key, 'name' => $name, 'sub' => $sub, 'language_id' => $languageIds[0]];
                }
            }
        }
        if (empty($columns)) {
            throw new \RuntimeException($this->cms->snippet('record.error_export_no_fields'));
        }
        $fileExport = [];
        foreach ($fields as $name => $entry) {
            if (!empty($entry['file']) && in_array($name, $selected, true)) $fileExport[$name] = true;
        }

        $fh = fopen($job['dir'] . '/export.csv', 'w');
        if ($fh === false) throw new \RuntimeException('export.csv not writable');
        if ($withBom) fwrite($fh, "\xEF\xBB\xBF");
        if ($withHeader) {
            fwrite($fh, $this->_csvLine(array_column($columns, 'key'), $separator, $enclosure, $eol));
        }
        foreach ($job['ids'] as $recordId) {
            $row = $this->_recordRow((int)$recordId);
            if (!$row) continue;
            $caches = [];
            foreach ($languageIds as $languageId) {
                $caches[$languageId] = $this->_cacheOf((int)$recordId, $languageId, $row);
            }
            $values = [];
            foreach ($columns as $column) {
                $values[] = $this->_csvValue($row, $fields[$column['name']], $column['sub'], $column['language_id'], $caches[$column['language_id']], $fileExport, $job['dir']);
            }
            fwrite($fh, $this->_csvLine($values, $separator, $enclosure, $eol));
            $this->exportedRecords++;
        }
        fclose($fh);
    }

    /**
     * A cell value of the CSV.
     *
     * @param array  $row        `record` row
     * @param array  $entry      field entry from csvFields()
     * @param string $sub        subfield ('' = the field itself)
     * @param int    $languageId language of the column
     * @param array  $cache      content cache of the record in this language
     * @param array  $fileExport file fields whose file is exported
     */
    private function _csvValue(array $row, array $entry, string $sub, int $languageId, array $cache, array $fileExport, string $dir): string
    {
        $name = (string)$entry['name'];
        $type = (string)$entry['type'];

        // embedded contents (link): one value per content, separated by " | " -
        // id, area, field value or path of the exported file
        if (!empty($entry['link'])) {
            $values = [];
            foreach ($this->_linkDestRows((int)$row['record_id'], (int)$entry['field_id']) as $destRow) {
                $destId = (int)$destRow['record_id'];
                if ($sub === 'record_id' || $sub === '') { $values[] = (string)$destId; continue; }
                if ($sub === 'area_id') { $values[] = (string)$destRow['area_id']; continue; }
                $destDef = $this->_schemaOf((int)$destRow['area_id'])[$sub] ?? null;
                if ($destDef === null) { $values[] = ''; continue; }
                if ((string)($destDef['type'] ?? '') === 'file') {
                    $fileRow = $this->_fileRow($destId, (int)$destDef['field_id'], $languageId);
                    $values[] = ($fileRow && (string)$fileRow['extension'] !== '')
                        ? $this->_copyFile($destRow, (int)$destDef['field_id'], $fileRow, $dir) : '';
                    continue;
                }
                $destCache = $this->_cacheOf($destId, $languageId, $destRow);
                $values[] = $this->_scalarValue($sub, $destCache, $destRow, $destId, $languageId);
            }
            return implode(self::MULTI_SEPARATOR, $values);
        }

        // links: ids or field of the target records
        if (!empty($entry['relation'])) {
            $rels = $this->_relationRows((int)$row['record_id'], (int)$entry['field_id']);
            if ($sub === 'record_id' || $sub === '') {
                return implode(self::MULTI_SEPARATOR, array_column($rels, 'dest_id'));
            }
            $values = [];
            foreach ($rels as $rel) {
                $destId = (int)$rel['dest_id'];
                $destRow = $this->_recordRow($destId);
                if (!$destRow) continue;
                $destCache = $this->_cacheOf($destId, $languageId, $destRow);
                $values[] = $this->_scalarValue($sub, $destCache, $destRow, $destId, $languageId);
            }
            return implode(self::MULTI_SEPARATOR, $values);
        }

        // file fields: path of the exported file or column from data_fil
        if (!empty($entry['file'])) {
            $fileRow = $this->_fileRow((int)$row['record_id'], (int)$entry['field_id'], $languageId);
            if (!$fileRow || (string)$fileRow['extension'] === '') return '';
            if ($sub === '') {
                return isset($fileExport[$name]) ? $this->_copyFile($row, (int)$entry['field_id'], $fileRow, $dir) : '';
            }
            return (string)($fileRow[$sub] ?? '');
        }

        // meta field without input field
        if (!empty($entry['meta']) && (int)$entry['field_id'] < 1) {
            return $this->_metaValue($row, $name);
        }
        return $this->_scalarValue($name, $cache, $row, (int)$row['record_id'], $languageId);
    }

    /**
     * Scalar field value from the content cache (plain text, password as hash); fields
     * on a column of `record` from the row.
     */
    private function _scalarValue(string $name, array $cache, array $row, int $recordId, int $languageId): string
    {
        if (!isset($cache[$name]) && array_key_exists($name, $row)) {
            return $this->_metaValue($row, $name);
        }
        $entry = (array)($cache[$name] ?? []);
        $type = (string)($entry[0] ?? 'text');
        $value = $entry[1] ?? '';
        if ($type === 'file') return (string)($value['filename'] ?? '');
        if ($type === 'password') {
            return $this->_passwordHash($recordId, $name, $languageId);
        }
        if ($type === 'encrypted') {
            return $this->crypt->decrypt((string)$value);
        }
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE);
        return (string)$value;
    }

    /**
     * Value of a column of `record` as text.
     */
    private function _metaValue(array $row, string $name): string
    {
        $value = $row[$name] ?? null;
        if ($value === null) return '';
        return (string)$value;
    }

    /**
     * Password hash from data_pwd - it is never in the content cache.
     */
    private function _passwordHash(int $recordId, string $name, int $languageId): string
    {
        $fieldId = 0;
        // field_id via the area of the record
        $row = $this->_recordRow($recordId);
        foreach ($this->_schemaOf((int)($row['area_id'] ?? 0)) as $fieldName => $colDef) {
            if ($fieldName === $name) $fieldId = (int)$colDef['field_id'];
        }
        if ($fieldId < 1) return '';
        $this->db->pushResult();
        $this->db->query("SELECT `data` FROM `data_pwd` WHERE `record_id` = ? AND `field_id` = ? AND `language_id` = ?", 'iii', [$recordId, $fieldId, $languageId]);
        $hash = (string)($this->db->fetchNum()[0] ?? '');
        if ($hash === '') {
            $this->db->query("SELECT `data` FROM `data_pwd` WHERE `record_id` = ? AND `field_id` = ? LIMIT 1", 'ii', [$recordId, $fieldId]);
            $hash = (string)($this->db->fetchNum()[0] ?? '');
        }
        $this->db->popResult();
        return $hash;
    }

    /**
     * A CSV line: enclosed is whatever contains a separator, enclosure character or
     * line break; the enclosure character is doubled.
     */
    private function _csvLine(array $values, string $separator, string $enclosure, string $eol): string
    {
        $cells = [];
        foreach ($values as $value) {
            $value = (string)$value;
            if ($value !== '' && ($enclosure !== '' && (strpbrk($value, $separator . $enclosure . "\r\n") !== false))) {
                $value = $enclosure . str_replace($enclosure, $enclosure . $enclosure, $value) . $enclosure;
            }
            $cells[] = $value;
        }
        return implode($separator, $cells) . $eol;
    }

    /**
     * Reduce the input "separator"/"enclosure character" to one character:
     * "\t" is the tab, empty = default.
     */
    public function csvChar(string $input, string $default): string
    {
        $input = strtr($input, ['\t' => "\t", '\\t' => "\t"]);
        if ($input === '') return $default;
        return mb_substr($input, 0, 1);
    }

    /**
     * Input "line break": \r\n, \n, \r as escape sequences.
     */
    public function csvEol(string $input): string
    {
        $eol = strtr($input, ['\r' => "\r", '\n' => "\n", '\\r' => "\r", '\\n' => "\n"]);
        return trim($eol, " ") === '' ? "\r\n" : $eol;
    }

    // ====================
    // DOCX

    /**
     * Word document for translation: one table per record. Gray
     * shaded rows carry the metadata ("record_id=.. | language_id=.."
     * or "field=.."), white rows the content of the source language - one paragraph per line of the
     * content. The note texts are always English (the document goes to
     * translators, not to the editor).
     *
     * The file is a minimal OOXML package ([Content_Types].xml, _rels/.rels,
     * word/document.xml) - without a library; Word and LibreOffice open it.
     */
    private function _exportDocx(array $job): void
    {
        $areaId = (int)$job['area_id'];
        $instanceId = (int)($job['area']['instance_id'] ?? 0);
        $languages = $this->instanceCache->languages($instanceId);
        $from = (int)($job['from'] ?? 0);
        $to = (int)($job['to'] ?? 0);
        if (!isset($languages[$from]) || !isset($languages[$to]) || $from === $to) {
            throw new \RuntimeException($this->cms->snippet('record.error_export_languages'));
        }
        $fields = $this->docxFields($areaId);
        $selected = array_map('strval', (array)($job['fields'] ?? []));
        $fields = array_intersect_key($fields, array_flip($selected));
        if (empty($fields)) {
            throw new \RuntimeException($this->cms->snippet('record.error_export_no_fields'));
        }

        $fromLabel = $this->_languageLabel($languages[$from]);
        $toLabel = $this->_languageLabel($languages[$to]);
        $areaLabel = $this->cms->textOrSnippet((string)($job['area']['label'] ?? ''));

        $head = $this->_docxParagraph(str_replace(
            ['{area}', '{from}', '{to}'],
            [$areaLabel, $fromLabel, $toLabel],
            $this->cms->snippet('record.docx_title', [], Cms::FALLBACK_LANG)
        ), bold: true, size: 28);
        $head .= $this->_docxParagraph($this->cms->snippet('record.docx_note', [], Cms::FALLBACK_LANG), size: 18, color: '666666');
        $head .= $this->_docxParagraph('');
        $title = "camindo export: $areaLabel ($fromLabel -> $toLabel)";
        $split = !empty($job['split']);
        // Dateiname je Datensatz: Default-Feld des Bereichs (Quellsprache)
        $defaultName = '';
        foreach ($this->_schemaOf($areaId) as $name => $colDef) {
            if ((int)($colDef['field_id'] ?? 0) > 0 && (int)$colDef['field_id'] === (int)($job['area']['default_field_id'] ?? 0)) $defaultName = (string)$name;
        }

        $body = $head;
        foreach ($job['ids'] as $recordId) {
            $row = $this->_recordRow((int)$recordId);
            if (!$row) continue;
            $sourceCache = $this->_cacheOf((int)$recordId, $from, $row);
            $targetCache = $this->_cacheOf((int)$recordId, $to, $row);
            $this->exportedRecords++;
            $rows = '';
            $meta = "record_id={$recordId} | language_id={$to} | source_language_id={$from} | " . $toLabel;
            $rows .= $this->_docxRow([$meta], shaded: true, bold: true);
            $linkSubs = [];
            foreach ($fields as $name => $colDef) {
                if (isset($colDef['link'])) {
                    $linkSubs[(string)$colDef['link']][(string)$colDef['sub']] = $colDef;
                    continue;
                }
                $label = $this->cms->textOrSnippet((string)($colDef['label'] ?? $name));
                $fieldMeta = "field={$name} | " . $label;
                $targetEntry = (array)($targetCache[$name] ?? []);
                if (isset($targetEntry[1]) && empty($targetEntry[2]) && (string)$targetEntry[1] !== '') {
                    $fieldMeta .= ' | ' . $this->cms->snippet('record.docx_translated', [], Cms::FALLBACK_LANG);
                }
                $rows .= $this->_docxRow([$fieldMeta], shaded: true);
                $value = $this->_scalarValue($name, $sourceCache, $row, (int)$recordId, $from);
                $rows .= $this->_docxRow(preg_split('/\r\n|\r|\n/', $value) ?: [''], shaded: false);
            }
            // embedded contents (link): per content its fields, the meta row names
            // it ("field={link}.{field} | record_id={id}") - the import writes it
            // together with the record (RecordImport, EMBEDDED CONTENTS)
            $schema = $this->_schemaOf($areaId);
            foreach ($linkSubs as $linkName => $subs) {
                $number = 0;
                foreach ($this->_linkDestRows((int)$recordId, (int)($schema[$linkName]['field_id'] ?? 0)) as $destRow) {
                    $number++;
                    $destId = (int)$destRow['record_id'];
                    $destSchema = $this->_schemaOf((int)$destRow['area_id']);
                    $destSource = $this->_cacheOf($destId, $from, $destRow);
                    $destTarget = $this->_cacheOf($destId, $to, $destRow);
                    foreach ($subs as $sub => $colDef) {
                        if (!isset($destSchema[$sub]) || !$this->cms->isMultilingualField($destSchema[$sub])) continue;
                        $fieldMeta = "field={$linkName}.{$sub} | record_id={$destId} | " . $colDef['label'] . ' #' . $number;
                        $targetEntry = (array)($destTarget[$sub] ?? []);
                        if (isset($targetEntry[1]) && empty($targetEntry[2]) && (string)$targetEntry[1] !== '') {
                            $fieldMeta .= ' | ' . $this->cms->snippet('record.docx_translated', [], Cms::FALLBACK_LANG);
                        }
                        $rows .= $this->_docxRow([$fieldMeta], shaded: true);
                        $value = $this->_scalarValue($sub, $destSource, $destRow, $destId, $from);
                        $rows .= $this->_docxRow(preg_split('/\r\n|\r|\n/', $value) ?: [''], shaded: false);
                    }
                }
            }
            $table = $this->_docxTable($rows) . $this->_docxParagraph('');
            if (!$split) {
                $body .= $table;
                continue;
            }
            $label = $defaultName !== '' ? $this->_scalarValue($defaultName, $sourceCache, $row, (int)$recordId, $from) : '';
            $this->_docxWrite($job['dir'] . '/' . $this->_docxFileName((int)$recordId, $label), $head . $table, $title);
        }
        if (!$split) $this->_docxWrite($job['dir'] . '/export.docx', $body, $title);
    }

    /**
     * File name of a record in the export "one record per file":
     * "{record_id}-{label}.docx", the label shortened to letters, digits
     * and hyphens (at most 40 characters).
     */
    private function _docxFileName(int $recordId, string $label): string
    {
        $label = strtolower(trim((string)preg_replace('/[^\p{L}\p{N}]+/u', '-', $this->cms->_strip_tags($label)), '-'));
        $label = trim(mb_substr($label, 0, 40), '-');
        return $recordId . ($label !== '' ? '-' . $label : '') . '.docx';
    }

    /**
     * Write a minimal OOXML package (see _exportDocx()).
     */
    private function _docxWrite(string $path, string $body, string $title): void
    {
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
            . '</w:body></w:document>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>';
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $this->_xmlEscape($title) . '</dc:title>'
            . '<dc:creator>' . $this->_xmlEscape($this->_adminName()) . '</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException(basename($path) . ' not writable');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('docProps/core.xml', $core);
        $zip->addFromString('word/document.xml', $document);
        $zip->close();
    }

    private function _languageLabel(array $language): string
    {
        return (string)($language['label'] ?? '') . ' (' . (string)($language['code'] ?? '') . ')';
    }

    private function _docxParagraph(string $text, bool $bold = false, int $size = 0, string $color = ''): string
    {
        $rPr = '';
        if ($bold) $rPr .= '<w:b/>';
        if ($color !== '') $rPr .= '<w:color w:val="' . $color . '"/>';
        if ($size > 0) $rPr .= '<w:sz w:val="' . $size . '"/>';
        $run = $text === '' ? '' : '<w:r>' . ($rPr !== '' ? "<w:rPr>$rPr</w:rPr>" : '')
            . '<w:t xml:space="preserve">' . $this->_xmlEscape($this->_xmlText($text)) . '</w:t></w:r>';
        return '<w:p>' . $run . '</w:p>';
    }

    /**
     * Table row with one cell; one paragraph per entry in $lines.
     */
    private function _docxRow(array $lines, bool $shaded, bool $bold = false): string
    {
        $tcPr = '<w:tcPr><w:tcW w:w="9638" w:type="dxa"/>'
            . ($shaded ? '<w:shd w:val="clear" w:color="auto" w:fill="D9D9D9"/>' : '')
            . '</w:tcPr>';
        $paragraphs = '';
        foreach ($lines as $line) {
            $paragraphs .= $this->_docxParagraph((string)$line, bold: $bold, size: $shaded ? 16 : 0, color: $shaded ? '404040' : '');
        }
        if ($paragraphs === '') $paragraphs = '<w:p/>';
        return '<w:tr><w:tc>' . $tcPr . $paragraphs . '</w:tc></w:tr>';
    }

    private function _docxTable(string $rows): string
    {
        $borders = '';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $side) {
            $borders .= '<w:' . $side . ' w:val="single" w:sz="4" w:space="0" w:color="999999"/>';
        }
        return '<w:tbl><w:tblPr><w:tblW w:w="9638" w:type="dxa"/><w:tblBorders>' . $borders . '</w:tblBorders>'
            . '<w:tblCellMar><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="9638"/></w:tblGrid>' . $rows . '</w:tbl>';
    }

    private function _xmlEscape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Remove characters that are not allowed in XML 1.0 (control characters except
     * tab, LF, CR) - they would come from faulty inputs and make the file
     * unreadable.
     */
    private function _xmlText(string $text): string
    {
        return preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? $text;
    }

    // ====================
    // Datenzugriff

    /**
     * `record` row of a productive, not deleted record (memoized).
     */
    private function _recordRow(int $recordId): ?array
    {
        static $rows = [];
        if (array_key_exists($recordId, $rows)) return $rows[$recordId];
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `record` WHERE `record_id` = ? AND `tmp` = 0 AND `deleted` = 0", 'i', [$recordId]);
        $row = $this->db->fetch();
        $this->db->popResult();
        // only memoize the rows of the running export - foreign records
        // (link targets) can be many
        if (count($rows) > 5000) $rows = [];
        $rows[$recordId] = $row;
        return $row;
    }

    /**
     * The meta columns of a record for the XML attribute set: everything except the
     * derived tree columns and the working-copy flags.
     */
    private function _metaColumns(array $row): array
    {
        $skip = ['tmp', 'origin_id', 'locked', 'deleted', 'lft', 'rgt', 'depth', 'parent_ids', 'cache'];
        return array_diff_key($row, array_flip($skip));
    }

    /**
     * Content cache of a record in one language; if the row is missing, the
     * cache is rebuilt. If the instance of the record does not maintain the language
     * (link target in another instance), its primary language applies.
     */
    private function _cacheOf(int $recordId, int $languageId, array $row): array
    {
        $instanceId = (int)($row['instance_id'] ?? 0);
        if (!in_array($languageId, $this->instanceCache->languageIds($instanceId), true)) {
            $languageId = $this->instanceCache->primaryLanguageId($instanceId);
        }
        $key = "$recordId.$languageId";
        if (isset($this->cacheMemo[$key])) return $this->cacheMemo[$key];
        $cache = $this->cms->loadRecordCache($recordId, $languageId);
        if ($cache === null) {
            $cache = $this->cms->createRecordCache($recordId, (int)$row['area_id'], $instanceId, $languageId);
        }
        if (count($this->cacheMemo) > 2000) $this->cacheMemo = [];
        $this->cacheMemo[$key] = $cache;
        return $cache;
    }

    /**
     * Rows of a data_* table for a field: language_id, data, empty.
     */
    private function _dataRows(int $recordId, int $fieldId, string $type): array
    {
        $suffix = $this->cms->dataSuffix[$type] ?? '';
        if ($suffix === '' || $suffix === '_none_' || $suffix === 'ids') return [];
        $table = 'data_' . $suffix;
        $emptyCol = $this->cms->hasEmptyFlag($type) ? ', `empty`' : '';
        $this->db->pushResult();
        $this->db->query("SELECT `language_id`, `data`{$emptyCol} FROM `{$table}` WHERE `record_id` = ? AND `field_id` = ? ORDER BY `language_id` ASC", 'ii', [$recordId, $fieldId]);
        $rows = [];
        while ($row = $this->db->fetch()) {
            $rows[] = [
                'language_id' => $row['language_id'] ?? null,
                'data' => $row['data'],
                'empty' => (int)($row['empty'] ?? 0),
            ];
        }
        $this->db->popResult();
        return $rows;
    }

    /**
     * Links of a field: dest_id, position (in order).
     */
    private function _relationRows(int $recordId, int $fieldId): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT `dest_id`, `position` FROM `data_rel` WHERE `src_id` = ? AND `field_id` = ? ORDER BY `position` ASC, `dest_id` ASC", 'ii', [$recordId, $fieldId]);
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        return $rows;
    }

    /**
     * data_fil rows of a file field (all languages).
     */
    private function _fileRows(int $recordId, int $fieldId): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `data_fil` WHERE `record_id` = ? AND `field_id` = ? ORDER BY `language_id` ASC", 'ii', [$recordId, $fieldId]);
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        return $rows;
    }

    /**
     * data_fil row of a language; without its own row the first one (single-language
     * fields carry the same metadata in all languages).
     */
    private function _fileRow(int $recordId, int $fieldId, int $languageId): ?array
    {
        $rows = $this->_fileRows($recordId, $fieldId);
        foreach ($rows as $row) {
            if ((int)$row['language_id'] === $languageId) return $row;
        }
        return $rows[0] ?? null;
    }

    /**
     * Copy a media file into the export directory:
     * media/{record_id}_{field_id}_{language_id}-{filename}. Each physical file
     * only once (fallback rows of other languages point to the same file).
     *
     * @return string  relative path in the zip file; '' if the file is missing
     */
    private function _copyFile(array $row, int $fieldId, array $fileRow, string $dir): string
    {
        $recordId = (int)$row['record_id'];
        $fileLanguageId = $this->cms->fileLanguageId($fileRow);
        $extension = (string)$fileRow['extension'];
        $source = (string)$this->config->get('paths.media_dir') . '/'
            . $this->cms->mediaPath($fieldId, $recordId, $fileLanguageId, $extension, (int)($row['instance_id'] ?? 0));
        if (isset($this->copiedFiles[$source])) return $this->copiedFiles[$source];
        if (!is_file($source)) return '';

        $filename = basename(str_replace('\\', '/', (string)($fileRow['filename'] ?? '')));
        $filename = preg_replace('/[\x00-\x1f\/:*?"<>|]/', '_', $filename) ?? '';
        if ($filename === '') $filename = $recordId . '.' . $extension;
        $target = 'media/' . $recordId . '_' . $fieldId . '_' . (int)$fileLanguageId . '-' . $filename;
        if (!is_dir("$dir/media") && !$this->system->createDir($dir, 'media')) return '';
        if (!copy($source, "$dir/$target")) return '';
        $this->copiedFiles[$source] = $target;
        return $target;
    }

    private function _areaOf(int $areaId): ?array
    {
        if (array_key_exists($areaId, $this->areaMemo)) return $this->areaMemo[$areaId];
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `area` WHERE `area_id` = ? AND `deleted` = 0", 'i', [$areaId]);
        $row = $this->db->fetch();
        $this->db->popResult();
        return $this->areaMemo[$areaId] = $row;
    }

    private function _schemaOf(int $areaId): array
    {
        if ($areaId < 1) return [];
        return $this->schemaMemo[$areaId] ??= $this->record->virtualSchema($areaId, false);
    }

    private function _adminId(): int
    {
        return (int)($this->session->get('admin_id') ?? 0);
    }

    private function _adminName(): string
    {
        $details = $this->session->get('details');
        if (is_array($details) && !empty($details['name'])) return (string)$details['name'];
        $adminId = $this->_adminId();
        if ($adminId < 1) return '';
        $this->db->pushResult();
        $this->db->query("SELECT `name` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
        $name = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->popResult();
        return $name;
    }

    // ====================
    // Hintergrund

    /**
     * Start the job as a background process: JSON file to var/tmp, then
     * Core/cron/record_export.php (like RecordBatch::start()).
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
            $this->system->log('export-errors', $error);
            return null;
        }

        $token = $this->crypt->randomKey(16);
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $jobFile = $tmpDir . '/export-' . $token . '.json';
        $job['token'] = $token;
        $job['admin_id'] = $this->_adminId();
        if (file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            $error = "Error: could not write job file {$jobFile}";
            $this->system->log('export-errors', $error);
            return null;
        }

        $command = [
            $phpExec,
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'record_export.php',
            '--job=' . $jobFile,
            '--token=' . $token,
        ];
        $result = $this->bgproc->start($command, self::BGPROC_TYPE, $token, null, null, $error);
        if ($result === null) {
            @unlink($jobFile);
            $this->system->log('export-errors', "start failed: {$error}");
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

    /**
     * Result of the background process as an internal message to the triggering
     * admin: summary, download link (HTML part) and how long it is valid.
     * On an error the message.
     *
     * @return string  subject of the message
     */
    public function report(array $job, array $result): string
    {
        $adminId = (int)($job['admin_id'] ?? 0);
        $areaLabel = !empty($job['area_ids'])
            ? $this->cms->snippet('record.label_export_global')
            : $this->cms->_strip_tags($this->cms->textOrSnippet((string)($this->_areaOf((int)($job['area_id'] ?? 0))['label'] ?? '')));
        $type = strtoupper((string)($job['type'] ?? ''));
        $subject = str_replace(['{area}', '{type}'], [$areaLabel, $type], $this->cms->snippet('record.export_report_subject'));
        if ($adminId < 1) return $subject;

        if (!empty($result['error'])) {
            $body = $this->cms->snippet('record.error_export_failed') . ' ' . (string)$result['error'];
            $this->message->sendSystem($adminId, $subject, $body);
            return $subject;
        }
        $summary = $this->summaryText($result);
        $validity = $this->validityText((string)$result['zip']);
        $url = $this->downloadUrl((string)$result['zip']);
        $body = $summary . "\n" . (string)$result['zip'] . ' (' . $this->system->convertSize((float)$result['size']) . ")\n"
            . $validity . "\n" . $url;
        $html = '<p><a href="' . htmlspecialchars($url) . '" target="_blank"><span class="icon" aria-hidden="true">download</span> '
            . htmlspecialchars($this->cms->snippet('record.button_export_download')) . '</a></p>';
        $this->message->sendSystem($adminId, $subject, $body, $html);
        return $subject;
    }

    /**
     * "Export finished: n records, m files."
     */
    public function summaryText(array $result): string
    {
        return str_replace(
            ['{records}', '{files}'],
            [
                $this->cms->snippet('record.x_records', ['count' => (int)($result['records'] ?? 0)]),
                $this->cms->snippet('record.export_x_files', ['count' => (int)($result['files'] ?? 0)]),
            ],
            $this->cms->snippet('record.export_summary')
        );
    }

    /**
     * "The link is valid until {date} (n hours) - the file is deleted after the download."
     */
    public function validityText(string $zip): string
    {
        $path = (string)$this->config->get('paths.var_dir') . '/export/' . basename($zip);
        $time = is_file($path) ? (int)filemtime($path) : time();
        $until = $this->cms->_formatDate($this->cms->utcToUserTZ($this->validUntil($time)));
        return str_replace(
            ['{until}', '{hours}'],
            [$until, (string)$this->retentionHours()],
            $this->cms->snippet('record.note_export_validity')
        );
    }

    public function downloadUrl(string $zip): string
    {
        return (string)$this->config->get('paths.cms') . '/backendModules/Record/record_download.php?file=' . rawurlencode(basename($zip));
    }

    // ====================
    // Clean up

    /**
     * Delete export files older than admin.export_retention_hours -
     * zip files and left-over directories (aborted exports).
     * Called by the scheduler task tmpcleanup (SchedulerRunner).
     *
     * @return int  number of removed entries
     */
    public function cleanup(): int
    {
        $dir = (string)$this->config->get('paths.var_dir') . '/export';
        if (!is_dir($dir)) return 0;
        $limit = time() - $this->retentionHours() * 3600;
        $removed = 0;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = "$dir/$entry";
            if ((int)filemtime($path) > $limit) continue;
            if (is_dir($path)) {
                $this->_removeDir($path);
                $removed++;
            }
            elseif (str_ends_with($entry, '.zip') && @unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }

    // ====================
    // Pack and clean up

    private function _zipDir(string $dir, string $zipPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('zip not writable: ' . basename($zipPath));
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = substr((string)$item->getPathname(), strlen($dir) + 1);
            if ($item->isDir()) $zip->addEmptyDir($relative);
            else $zip->addFile((string)$item->getPathname(), $relative);
        }
        $zip->close();
    }

    private function _removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir((string)$item->getPathname()) : unlink((string)$item->getPathname());
        }
        rmdir($dir);
    }
}
