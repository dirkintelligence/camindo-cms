<?php
/**
 * Media import: from each file of a zip file a new record is created - the file
 * in the chosen upload field, on request its name as text in a second field.
 *
 * Independent of RecordImport (XML/CSV/DOCX): no modes (always INSERT), no
 * column mapping, no background process. The mask is
 * RecordBackendMediaImport (record_media_import.php), reachable in the menu on the right of the
 * record list of an area, under "Import".
 *
 * PROCESS
 * --------------------
 *     prepare()  unpack the zip file (upload or from var/upload) into
 *                var/tmp/mediaimport-{token}/ - each file under a
 *                generated name (files/{n}.{ext}); its path in the zip file (converted
 *                to UTF-8, see FILE NAMES) is stored in the directory
 *                manifest.json. Entries starting with a dot are ignored
 *                (also in directory names: .git/…, __MACOSX/…), as are typical
 *                metadata files (Thumbs.db, desktop.ini, …); each file gets its
 *                MIME type (finfo, from the content) and, if applicable, the reason why it is
 *                not importable (DANGEROUS FILES)
 *     analyze()  the files of the directory grouped by MIME type
 *     run()      for each file of the chosen types: tmpInit() -> importSave() with the
 *                file as 'importfile' (as with the CSV import) - input check,
 *                thumbnails, check-in as in the input mask
 *
 * The directory never holds files under their original name: path jumps
 * (zip slip), names in foreign encodings and extensions such as .php play no role
 * on the server this way. The extension of the generated names is the (cleaned) one
 * of the original - the input check of the upload field sees the same extension as
 * with an upload.
 *
 * DANGEROUS FILES
 * --------------------
 * Not importable (grayed out) are: executable code (Record::forbiddenExtensions()
 * and executable programs/scripts, EXECUTABLE_EXTENSIONS), active content that a
 * browser runs as a document or script (MediaAccess::isActiveContent(): HTML,
 * SVG, XML, JavaScript - extension OR recognized MIME type), empty files and files
 * whose content does not match the extension (Record::mimeExtensionMismatch(), e.g. a
 * JPEG with the extension .png).
 *
 * FILE NAMES
 * --------------------
 * Zip files have no binding encoding for names: UTF-8 (flag in the
 * entry, macOS, 7-Zip) or the OEM code page of the system (Windows Explorer: CP437
 * or CP850); some tools write Windows-1252. filenameUtf8() checks the
 * raw bytes: valid UTF-8 remains (in NFC - macOS decomposes umlauts), otherwise the
 * conversion from CP850 or Windows-1252 wins, which yields more letters.
 * filenameText() turns this into the text of the name field: without directory and extension,
 * minus and underscore as spaces.
 *
 * @see Camindo\Core\Modules\Record\RecordBackendMediaImport  the mask
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\MediaAccess;
use Camindo\Core\System;

class RecordMediaImport {

    /** Prefix of the import directories in var/tmp */
    public const DIR_PREFIX = 'mediaimport-';

    /** Verzeichnisliste im Importverzeichnis */
    private const MANIFEST = 'manifest.json';

    /** Typical metadata files (Windows, macOS), lowercase - are ignored */
    public const META_FILES = ['thumbs.db', 'ehthumbs.db', 'ehthumbs_vista.db', 'desktop.ini', 'icon' . "\r", 'folder.jpg', 'albumartsmall.jpg'];

    /** Directories with metadata, lowercase - their contents are ignored */
    public const META_DIRS = ['__macosx', '$recycle.bin', 'system volume information'];

    /** Executable programs and scripts - not importable */
    public const EXECUTABLE_EXTENSIONS = [
        'exe', 'com', 'bat', 'cmd', 'msi', 'msp', 'scr', 'pif', 'cpl', 'dll', 'sys',
        'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'ps1', 'psm1', 'hta', 'lnk', 'reg',
        'jar', 'app', 'command', 'bash', 'zsh', 'csh', 'py', 'rb', 'apk', 'deb', 'rpm',
    ];

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private System $system,
        private InstanceCache $instanceCache,
        private Record $record,
        private Backend $backend,
        private \Camindo\Core\AuditLog $auditLog,
    )
    {
    }

    // ====================
    // Step 1: provide

    /**
     * Unpack the zip file into a new import directory (see class comment).
     *
     * @param string $source        path of the zip file (upload tmpfile or file in var/upload)
     * @param string $originalName  original file name (extension!)
     * @return array  ['dir' => 'mediaimport-…'] or ['error' => …]
     */
    public function prepare(string $source, string $originalName): array
    {
        if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
            return ['error' => $this->cms->snippet('record.error_media_import_zip_only')];
        }
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $dirName = self::DIR_PREFIX . $this->crypt->randomKey(12);
        if (!$this->system->createDir($tmpDir, $dirName . '/files')) {
            return ['error' => 'var/tmp not writable'];
        }
        $dir = "$tmpDir/$dirName";
        $zip = new \ZipArchive();
        if ($zip->open($source) !== true) {
            $this->removeDir($dirName);
            return ['error' => $this->cms->snippet('record.error_import_zip')];
        }
        // space for the unpacked contents? (figures from the zip file)
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) $total += (int)(($zip->statIndex($i) ?: [])['size'] ?? 0);
        $free = @disk_free_space($dir);
        if ($free !== false && $total > $free) {
            $zip->close();
            $this->removeDir($dirName);
            return ['error' => $this->cms->snippet('record.error_media_import_space')];
        }
        $files = [];
        $number = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i, \ZipArchive::FL_ENC_RAW);
            if ($stat === false) continue;
            $path = $this->filenameUtf8((string)$stat['name']);
            // path parts '.' and '..' are dropped (stored under a generated
            // name anyway - it is only about the display)
            $path = implode('/', array_filter(explode('/', str_replace('\\', '/', $path)), fn($part) => $part !== '' && $part !== '.' && $part !== '..'));
            if ($path === '' || str_ends_with((string)$stat['name'], '/')) continue; // Verzeichnis
            $entry = [
                'path' => $path,
                'size' => (int)$stat['size'],
                'mime' => '',
                'stored' => '',
                'reason' => '',
            ];
            $ignored = $this->_ignoredReason($path);
            if ($ignored !== '') {
                $entry['reason'] = $ignored;
                $entry['ignored'] = true;
                $files[] = $entry;
                continue;
            }
            // store under a generated name, extension of the original (cleaned)
            $extension = (string)preg_replace('/[^a-z0-9]/', '', strtolower((string)pathinfo($path, PATHINFO_EXTENSION)));
            $stored = 'files/' . (++$number) . ($extension !== '' ? '.' . $extension : '');
            $in = $zip->getStreamIndex($i);
            $out = $in ? @fopen("$dir/$stored", 'wb') : false;
            if (!$in || !$out) {
                if ($in) fclose($in);
                $entry['reason'] = 'unreadable';
                $files[] = $entry;
                continue;
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $entry['stored'] = $stored;
            $entry['size'] = (int)filesize("$dir/$stored");
            $entry['mime'] = $this->_mimeType("$dir/$stored");
            $entry['reason'] = $this->dangerReason($entry['mime'], $extension, $entry['size']);
            $files[] = $entry;
        }
        $zip->close();
        if (empty($files)) {
            $this->removeDir($dirName);
            return ['error' => $this->cms->snippet('record.error_media_import_empty')];
        }
        usort($files, fn($a, $b) => strnatcasecmp($a['path'], $b['path']));
        file_put_contents("$dir/" . self::MANIFEST, json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['dir' => $dirName];
    }

    /**
     * Is the entry ignored? Dot at the start of a path part, metadata files and
     * directories (META_FILES, META_DIRS), Office lock files (~$…).
     *
     * @return string  '' or reason ('hidden' | 'meta')
     */
    private function _ignoredReason(string $path): string
    {
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if (str_starts_with($part, '.')) return 'hidden';
        }
        foreach (array_slice($parts, 0, -1) as $part) {
            if (in_array(mb_strtolower($part), self::META_DIRS, true)) return 'meta';
        }
        $base = (string)end($parts);
        if (in_array(mb_strtolower($base), self::META_FILES, true) || str_starts_with($base, '~$')) return 'meta';
        return '';
    }

    private function _mimeType(string $file): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)(finfo_file($finfo, $file) ?: '') : '';
        return $mime !== '' ? strtolower($mime) : 'application/octet-stream';
    }

    /**
     * Reason why a file cannot be imported (see DANGEROUS FILES).
     *
     * @return string  '' (importable) | 'forbidden' | 'active' | 'mismatch' | 'empty'
     */
    public function dangerReason(string $mime, string $extension, int $size): string
    {
        $extension = strtolower($extension);
        if (in_array($extension, $this->record->forbiddenExtensions(), true)
            || in_array($extension, self::EXECUTABLE_EXTENSIONS, true)
            || in_array($mime, ['application/x-dosexec', 'application/x-executable', 'application/x-sharedlib', 'application/x-mach-binary', 'text/x-php', 'application/x-httpd-php', 'text/x-shellscript'], true)
        ) {
            return 'forbidden';
        }
        if (MediaAccess::isActiveContent($mime, $extension)) return 'active';
        if ($size < 1) return 'empty';
        if ($this->record->mimeExtensionMismatch($mime, $extension)) return 'mismatch';
        return '';
    }

    // ====================
    // File names

    /**
     * Raw bytes of a name from the zip file as UTF-8 (see FILE NAMES).
     */
    public function filenameUtf8(string $raw): string
    {
        if ($raw === '') return '';
        if (mb_check_encoding($raw, 'UTF-8')) {
            $utf8 = $raw;
        }
        else {
            $best = '';
            $bestScore = PHP_INT_MIN;
            foreach (['CP850', 'Windows-1252'] as $encoding) {
                $candidate = (string)@mb_convert_encoding($raw, 'UTF-8', $encoding);
                // count letters, digits and usual characters, deduct symbols (box drawing,
                // control characters, currency signs)
                $good = (int)preg_match_all('/[\p{L}\p{N} ._\-()\/]/u', $candidate);
                $bad = mb_strlen($candidate) - $good;
                // an uppercase letter in the middle of a word ("CafÚ" instead of "Café") speaks
                // against the encoding
                $bad += (int)preg_match_all('/\p{Ll}\p{Lu}/u', $candidate);
                $score = $good - 2 * $bad;
                if ($score > $bestScore) {
                    $best = $candidate;
                    $bestScore = $score;
                }
            }
            $utf8 = $best;
        }
        if (class_exists(\Normalizer::class)) {
            $utf8 = (string)(\Normalizer::normalize($utf8, \Normalizer::FORM_C) ?: $utf8);
        }
        // remove control characters
        return (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $utf8);
    }

    /**
     * Text for the name field: file name without directory and extension, minus and
     * underscore as spaces, whitespace collapsed.
     */
    public function filenameText(string $path): string
    {
        $base = basename(str_replace('\\', '/', $path));
        $dot = strrpos($base, '.');
        if ($dot !== false && $dot > 0) $base = substr($base, 0, $dot);
        $text = str_replace(['-', '_'], ' ', $base);
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    // ====================
    // Import directory

    /**
     * Absolute path of an import directory, null for an invalid name or a
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
     * The file list of the import directory (prepare()), null without directory.
     *
     * @return array|null  [['path', 'size', 'mime', 'stored', 'reason', 'ignored'?], …]
     */
    public function files(string $dirName): ?array
    {
        $dir = $this->importDir($dirName);
        if ($dir === null || !is_file("$dir/" . self::MANIFEST)) return null;
        $files = json_decode((string)file_get_contents("$dir/" . self::MANIFEST), true);
        return is_array($files) ? $files : null;
    }

    // ====================
    // Step 2: analyze

    /**
     * Files grouped by MIME type.
     *
     * @return array [
     *     'groups'  => [mime => ['count' => n, 'size' => Bytes, 'files' => [path, …]]]  importable
     *     'blocked' => [['path', 'mime', 'reason'], …]   not importable (grayed out)
     *     'ignored' => [['path', 'reason'], …]           dot files, metadata files
     * ]
     */
    public function analyze(array $files): array
    {
        $result = ['groups' => [], 'blocked' => [], 'ignored' => []];
        foreach ($files as $file) {
            if (!empty($file['ignored'])) {
                $result['ignored'][] = ['path' => (string)$file['path'], 'reason' => (string)$file['reason']];
                continue;
            }
            if ((string)$file['reason'] !== '') {
                $result['blocked'][] = ['path' => (string)$file['path'], 'mime' => (string)$file['mime'], 'reason' => (string)$file['reason']];
                continue;
            }
            $mime = (string)$file['mime'];
            $result['groups'][$mime] ??= ['count' => 0, 'size' => 0, 'files' => []];
            $result['groups'][$mime]['count']++;
            $result['groups'][$mime]['size'] += (int)$file['size'];
            $result['groups'][$mime]['files'][] = (string)$file['path'];
        }
        ksort($result['groups']);
        return $result;
    }

    /**
     * Upload fields of an area (without thumbnails): name => field.
     */
    public function fileFields(int $areaId): array
    {
        $fields = [];
        foreach ($this->record->virtualSchema($areaId, false) as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') === 'file' && empty($colDef['parent_field_id']) && empty($colDef['meta'])) {
                $fields[$name] = $colDef;
            }
        }
        return $fields;
    }

    /**
     * Text fields of an area (chars, text) for the file name: name => field.
     */
    public function textFields(int $areaId): array
    {
        $fields = [];
        foreach ($this->record->virtualSchema($areaId, false) as $name => $colDef) {
            if (in_array((string)($colDef['type'] ?? ''), ['chars', 'text'], true) && empty($colDef['meta'])) {
                $fields[$name] = $colDef;
            }
        }
        return $fields;
    }

    // ====================
    // Step 3: import

    /**
     * Run the import: one new record per file of the chosen types.
     *
     * @param array $job [
     *     'area_id'    => target area,
     *     'dir'        => import directory (mediaimport-…),
     *     'types'      => [MIME type, …],
     *     'file_field' => name of the upload field,
     *     'name_field' => name of the text field for the file name ('' = none) ]
     * @return array  as RecordImport::run(): 'total', 'done', 'failed', 'skipped',
     *                'inserted', 'items' => [['kind', 'id', 'label', 'status', 'messages'], …],
     *                'error' (only if nothing ran at all)
     */
    public function run(array $job): array
    {
        $this->auditLog->setContext('import');
        $result = ['total' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0, 'inserted' => 0, 'updated' => 0, 'replaced' => 0, 'items' => []];
        $areaId = (int)($job['area_id'] ?? 0);
        $dir = $this->importDir((string)($job['dir'] ?? ''));
        $files = $this->files((string)($job['dir'] ?? ''));
        $fileFields = $this->fileFields($areaId);
        $textFields = $this->textFields($areaId);
        $fileField = (string)($job['file_field'] ?? '');
        $nameField = (string)($job['name_field'] ?? '');
        if ($dir === null || $files === null) {
            $result['error'] = $this->cms->snippet('record.error_import_no_file');
            return $result;
        }
        if (!isset($fileFields[$fileField])) {
            $result['error'] = $this->cms->snippet('record.error_media_import_no_field');
            return $result;
        }
        if (!isset($textFields[$nameField])) $nameField = '';
        $types = array_map('strval', (array)($job['types'] ?? []));
        $this->db->pushResult();
        $this->db->query("SELECT `instance_id` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
        $instanceId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        $primaryId = $this->instanceCache->primaryLanguageId($instanceId);
        $schema = $this->record->virtualSchema($areaId, false);
        $previousId = $this->_lastRootRecord($areaId, $schema);

        @set_time_limit(0);
        foreach ($files as $file) {
            if (!empty($file['ignored']) || (string)$file['reason'] !== '' || !in_array((string)$file['mime'], $types, true)) continue;
            $this->cms->errorsReset();
            $path = (string)$file['path'];
            $text = $this->filenameText($path);
            $item = ['kind' => 'record', 'id' => 0, 'label' => $path, 'status' => 'failed', 'messages' => []];
            // not accepted by the upload field (media types of the field): skip
            $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
            if (!$this->record->fileTypeAllowed($fileFields[$fileField], (string)$file['mime'], $extension)) {
                $item['status'] = 'skipped';
                $item['messages'][] = $this->cms->snippet('record.note_media_import_not_accepted');
                $this->_addItem($result, $item);
                continue;
            }
            $tmpId = 0;
            try {
                if (!$tmpData = $this->record->tmpInit(['area_id' => $areaId])) throw new \RuntimeException('#tmpInit');
                $tmpId = (int)$tmpData['record_id'];
                $fileValue = ['importfile' => "$dir/" . $file['stored'], 'filename' => basename($path)];
                $saveData = [
                    'record_id' => $tmpId,
                    'area_id' => $areaId,
                    $fileField => $this->cms->isMultilingualField($fileFields[$fileField]) ? [$primaryId => $fileValue] : $fileValue,
                ];
                $fields = ['area_id', $fileField];
                $stored = [];
                if ($nameField !== '') {
                    $saveData[$nameField] = $this->cms->isMultilingualField($textFields[$nameField]) ? [$primaryId => $text] : $text;
                    $fields[] = $nameField;
                }
                // insertion position: tree or manual sorting - after the last
                // record of the top level, in the order of the zip file
                if ($previousId !== null) {
                    $saveData['previous_id'] = $previousId;
                    if (isset($schema['parent_id'])) {
                        $saveData['parent_id'] = 0;
                        $fields[] = 'parent_id';
                        $stored[] = 'parent_id';
                    }
                }
                $newId = $this->record->importSave($fields, $saveData, $stored);
                if (!$newId) throw new \RuntimeException('#save failed');
                $item['id'] = (int)$newId;
                $item['status'] = 'inserted';
                if ($previousId !== null) $previousId = (int)$newId;
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                if ($tmpId > 0) {
                    $errors = $this->cms->errors;
                    $this->record->cancel($tmpId);
                    $this->cms->errors = $errors;
                }
                $item['messages'] = $this->_errorTexts();
            }
            $this->_addItem($result, $item);
        }
        $this->cms->errorsReset();
        if (empty($result['items'])) {
            $result['error'] = $this->cms->snippet('record.error_media_import_nothing');
        }
        return $result;
    }

    /**
     * Last record of the top level - null if the area has neither a tree nor
     * manual sorting (then the position does not matter).
     */
    private function _lastRootRecord(int $areaId, array $schema): ?int
    {
        if (!isset($schema['parent_id']) && !isset($schema['position'])) return null;
        $this->db->pushResult();
        $this->db->query(
            "SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `parent_id` IS NULL AND `tmp` = 0 AND `deleted` = 0"
            . " ORDER BY `position` DESC, `record_id` DESC LIMIT 1",
            'i', [$areaId]
        );
        $previousId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        return $previousId;
    }

    private function _addItem(array &$result, array $item): void
    {
        $status = (string)$item['status'];
        if ($status === 'failed') $result['failed']++;
        elseif ($status === 'skipped') $result['skipped']++;
        else {
            $result['done']++;
            if (isset($result[$status])) $result[$status]++;
        }
        $result['total']++;
        $result['items'][] = $item;
    }

    /**
     * The messages from Cms::errors as plain text lines ("Label: text").
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

    /**
     * "n added, n failed, n skipped"
     */
    public function summaryText(array $result): string
    {
        return str_replace(
            ['{inserted}', '{failed}', '{skipped}'],
            [(int)($result['inserted'] ?? 0), (int)($result['failed'] ?? 0), (int)($result['skipped'] ?? 0)],
            $this->cms->snippet('record.media_import_summary')
        );
    }
}
