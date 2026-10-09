<?php
/**
 * File delivery and file browser of the backend.
 *
 * Entry points:
 *
 *     public/backend/file.php       -> filePreview()        deliver a file
 *     backend/server_file.php       -> serverFileSelect()   show the server directory
 *
 * WHY MEDIA FILES ARE NOT LINKED DIRECTLY
 * --------------------
 * A file can be in three different places, depending on where it is
 * in the editing process:
 *
 *     on the productive record            {media_dir}/{instance_id}/{field_id}/{record_id}.{ext}
 *     on the working copy (freshly uploaded, not yet saved)
 *                                         the same storage, but under the tmp id
 *     no longer at all (marked as deleted, entry only kept for the checkIn)
 *
 * A fixed link from the input mask could not represent this. filePreview() is
 * therefore the one place that decides which file is meant, and delivers it.
 * At the same time it replaces direct access to the media directory -
 * the backend NEVER links there directly (Backend::backendValue(),
 * RecordBackendEdit::_editFileReplacements()); delivery happens only after sign-in
 * and only for what Record::load() would show the user.
 *
 * REDIRECT OR READ
 * --------------------
 * The media directory is locked twice (Camindo\Core\MediaAccess): per
 * instance to its domains, per field with user_auth completely. The backend often runs
 * under a different domain than the instance of the record - a link into the
 * media directory would then end in a 403. filePreview() therefore only redirects
 * (header Location) if all four conditions are met:
 *
 *     - the file is attached to the productive record (not a working copy: its
 *       files are renamed at check-in, and their name should not remain in the
 *       browser cache)
 *     - the field is not locked with user_auth
 *     - the host of this request may access according to the domain lock of the
 *       instance (MediaAccess::hostAllowed())
 *     - it is a browser-compatible image (Backend::$webImageFormats): everything
 *       else should come as a download and not be rendered in the backend context
 *
 * Otherwise the script reads the file itself (readfile) - slower, but always
 * correct. The link carries `version` or a random value as cache breaker.
 *
 * DOES NOT INHERIT FROM RecordBackend
 * --------------------
 * Like RecordBackendFolderList, the class has its own, shorter constructor.
 * This fits particularly well here: filePreview() runs via its own entry script
 * (public/backend/file.php) and may output nothing except the file itself - the
 * entire page machinery of the base class would be in the way.
 *
 * @see Camindo\Core\Modules\Record\RecordBackendUpload  takes over the selected file
 * @see Camindo\Core\TemporaryTrait                       working copies
 * @see Camindo\Core\VirtualFieldsTrait                   storage scheme of the files
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Config;
use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\MediaAccess;
use Camindo\Core\MediaDelivery;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Record\Record;

class RecordBackendServerFile {

    /** requested file extensions of the file browser (request 'ext'), empty = all */
    private array $extensions = [];

    public function __construct(
        private Config $config,
        private Backend $backend,
        private Cms $cms,
        private Session $session,
        private System $system,
        private Record $record,
        private MediaAccess $mediaAccess,
        private MediaDelivery $mediaDelivery
    )
    {}

    /**
     * Deliver a media file (public/backend/file.php).
     *
     * The parameter file has the form "{field_id}/{record_id}" - no file names, no
     * path. Extension and MIME type come from the `data_fil` entry, the file itself
     * is assembled from them. A change of directory via the parameter is thus
     * ruled out.
     *
     * CHECKS, IN THIS ORDER
     *  1. signed in? otherwise abort
     *  2. fetch the record via Record::load() - this is at the same time the
     *     access check; whoever may not see the record gets 404
     *  3. the field must exist
     *  4. file marked as deleted -> 404 (the entry only remains for the
     *     checkIn, see RecordBackendUpload)
     *
     * WHERE THE FILE LIES
     * The decisive case: if a working copy is shown whose file is still attached
     * to the original (it was not touched in this edit), the search must use the
     * origin_id. The flag 'temporary' in the cache entry says
     * which of the two cases applies.
     *
     * DELIVERY
     * Either a redirect into the media directory (see class comment "Redirect
     * or read") or readfile(). When reading, the output buffer is cleared
     * beforehand - a single space from another file would corrupt the
     * binary data. Images in browser-compatible formats are sent as
     * 'inline', everything else as 'attachment' (download); a PDF or an
     * HTML file must not be rendered in the context of the backend.
     *
     * Delivery happens with MediaDelivery::sendFile() under the original
     * file name (with extension) and `Cache-Control: private, no-store` - so the browser
     * does not show a cached version. (Previously a random sequence hung
     * behind the extension; downloaded files then had no type anymore.)
     *
     * Request: file = "{field_id}/{record_id}[/{language_id}]" - the language for
     * multilingual file fields (otherwise the language of the record, see
     * Cms::recordLanguageId()). For a working copy, the entry of the language comes
     * from the working state (own file of the language, otherwise that of the primary language), otherwise from
     * `record_cache`; file_language_id says which file it is.
     */
    public function filePreview()
    {
        if (!$this->session->load()) {
            die('##accessdenied##');
        }
        $file    = (string)($_REQUEST['file'] ?? '');

        $parts = explode('/', $file, 3);
        $fieldId = (int)($parts[0] ?? 0);
        $recordId = (int)($parts[1] ?? 0);
        $langPart = trim((string)($parts[2] ?? ''));
        if (empty($fieldId) or empty($recordId)) {
            $this->system->error404();
            return;
        }
        
        // use record->load -> checks access rights
        if (!$data = $this->record->load($recordId)) {
            $this->system->error404();
            return;
        }
        $areaId = (int)$data['area_id'];
        // read right of the group on the area (developer docs, chapter 11 (Permissions))
        if (!$this->backend->access()->areaRead($areaId)) {
            $this->system->error404();
            return;
        }
        if (!$fieldData = $this->record->fieldData($fieldId)) {
            $this->system->error404();
            return;
        }
        $name = $fieldData['name'] ?? '';

        $tmp = (int)($data['tmp'] ?? 0);
        $originId = (int)($data['origin_id'] ?? 0);

        // Sprache des Eintrags
        $languageId = ($langPart !== '' && ctype_digit($langPart))
            ? (int)$langPart
            : $this->cms->recordLanguageId((int)($data['instance_id'] ?? 0));

        // determine where the file lies
        $fileData = (array)($data[$name] ?? []);
        if ($this->cms->isMultilingualField($fieldData)) {
            if ($tmp === 1) {
                $entry = (array)($this->record->loadTmpData($recordId)[$name] ?? []);
                $entries = $this->record->fileEntries($entry);
                $primaryId = $this->record->languageIdsOf($recordId)[0] ?? $languageId;
                $fileData = (array)($entries[$languageId] ?? $entries[$primaryId] ?? []);
            }
            else {
                $cache = $this->cms->loadRecordCache($recordId, $languageId) ?? [];
                $fileData = (array)($cache[$name][1] ?? $fileData);
            }
        }
        $fileLanguageId = $this->cms->fileLanguageId($fileData);
        $isTemporary = (bool)($fileData['temporary'] ?? false);
        $isDeleted = (bool)($fileData['deleted'] ?? false);
        if ($isDeleted) {
            // File was marked as removed,
            // the entry only remains for the checkIn process
            $this->system->error404();
            return;
        }
        if ($tmp === 1 && !$isTemporary) {
            // record is temporary - but the file is assigned to the productive record
            $storageId = $originId;
        }
        else {
            // The file is assigned to the given record itself.
            // (The given record can be temporary or productive.)
            $storageId = $recordId;
        }

        $instanceId = (int)($data['instance_id'] ?? 0);
        $relativePath = $this->cms->mediaPath($fieldId, $storageId, $fileLanguageId, (string)$fileData['extension'], $instanceId);
        $mediaDir = $this->config->get('paths.media_dir');
        $file = "$mediaDir/$relativePath";
        if (!file_exists($file)) {
            // file is not where it should be
            $this->system->error404();
            return;
        }

        // default MIME type, if none is stored in the DB
        $mimeType = $fileData['mime_type'] ?? 'application/octet-stream';

        // Redirect instead of reading? (see class comment)
        // --------------------
        $details = json_decode((string)($fieldData['details'] ?? ''), true) ?: [];
        $isProductiveFile = ($tmp === 0);
        if ($isProductiveFile
            && empty($details['user_auth'])
            && in_array($mimeType, $this->backend->webImageFormats, true)
            && !headers_sent()
            && $this->mediaAccess->hostAllowed($instanceId, (string)($_SERVER['HTTP_HOST'] ?? ''))
        ) {
            if (ob_get_level()) ob_end_clean();
            header('Location: ' . $this->config->get('paths.media') . '/' . $relativePath
                . '?v=' . (int)($fileData['version'] ?? 0), true, 302);
            return;
        }
        
        // Deliver as in the frontend (MediaDelivery::sendFile()): file name escaped
        // (filename*), active content such as SVG only as a download in the sandbox.
        // The original name keeps its extension - without it, macOS for example does not
        // recognize the file type; if it is missing from the stored name, the file's extension is added.
        $filename = (string)($fileData['filename'] ?? '') ?: 'file';
        $extension = strtolower((string)($fileData['extension'] ?? ''));
        if ($extension !== '' && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== $extension) {
            $filename .= '.' . $extension;
        }
        $this->mediaDelivery->sendFile($file, (string)$mimeType, $filename,
            download: !in_array($mimeType, $this->backend->webImageFormats, true), private: true);
    }

    /**
     * File browser for the upload directory (backend/server_file.php).
     *
     * Shows the contents of paths.upload_dir as a tree. Intended for files that have
     * landed on the server via FTP or from another process and should be taken over into an
     * upload field without uploading them again. A click
     * goes to RecordBackendUpload::fileServerSelect().
     *
     * Subdirectories are shown, but entering a subdirectory is
     * not yet implemented ($relativePath) - the tree is output completely anyway.
     *
     * The path of a file goes into the template escaped twice: json_encode for the
     * JavaScript, htmlspecialchars for the attribute in which the call stands.
     *
     * @param string $relativePath  relative path to paths.upload_dir (no effect yet)
     */
    public function serverFileSelect($relativePath = ''): void
    {
        $srcField = (int)($_REQUEST['src_field'] ?? 0);
        // working copy to which the upload field belongs (0: unknown) - distinguishes
        // identical fields of several embedded contents (cApp.ui.selectUploadFile())
        $srcId = (int)($_REQUEST['src_id'] ?? 0);
        // language of the upload field (multilingual), '' for single-language fields
        $lang = preg_replace('/[^0-9]/', '', (string)($_REQUEST['lang'] ?? ''));
        // ext: show only files with these extensions (comma list, e.g. for the import:
        // zip,xml,csv,docx), case-insensitive; empty = all
        $this->extensions = array_values(array_filter(array_map(
            fn($e) => strtolower(trim($e)),
            explode(',', (string)($_REQUEST['ext'] ?? ''))
        )));

        // Bread crum navi
        $this->backend->setNaviLabel('#record.server_files');

        echo $this->backend->template('container_header');

        // container header
        echo $this->backend->template('headline1', [
            '{text}' => '{#record.server_files}'
        ]);

        $uploadDir = $this->config->get('paths.upload_dir'); // (always ends without slash)
        $currentDir = $uploadDir . DIRECTORY_SEPARATOR . $relativePath;

        if (!empty($relativePath)) {
            echo "error: not supported yet.";
            $this->backend->template('container_footer');
            exit;
        }
        else {
            echo $this->backend->template('record.upload_tree_head', [
                '{upload_dir}' => $uploadDir
            ]);
        }
        $files = $this->buildDirectoryTree($currentDir);

        if (empty($files)) $this->backend->template('div-mt', [
            '{id}' => '',
            '{text}' => '{#record.message_folder_empty}'
        ]);
        $this->backend->buttonAdd('button_cancel_default', 'cApp.layers.close()');
        echo $this->backend->buttonBar();

        $items = [];
        foreach ($files as $file) {
            // wrap safely for javascript
            $pathFile = $file['path'] . DIRECTORY_SEPARATOR . $file['file'];
            $pathFileEncoded = htmlspecialchars(
                json_encode($pathFile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
            );
            // safe for display
            $fileNameEncoded = htmlspecialchars($file['file']);
            // template replacements
            $replacements = [
                '{file}' => $fileNameEncoded,
                '{path_file}' => $pathFileEncoded,
                '{indent}' => ($file['indent'] * 20),
                '{src_field}' => $srcField,
                '{src_id}' => $srcId,
                '{lang}' => $lang,
            ];
            if ($file['type'] == 'folder') {
                $items[] = $this->backend->template('record.upload_tree_folder', $replacements);
            }
            elseif ($file['type'] == 'file') {
                $items[] = $this->backend->template('record.upload_tree_file', $replacements);
            }
            // else: unknown type
        }
        // ---- end ----
        echo $this->backend->template('record.upload_tree', [
            '{list_items}' => implode("\n", $items)
        ]);

        echo $this->backend->template('container_footer');
    }

    /**
     * Scans a directory recursively and returns a flat list
     * in the correct tree order for display.
     * Excludes hidden files and folders (starting with a dot).
     *
     * Flat and not nested, because the output is a list with indentation:
     * the recursion attaches the children directly behind their folder and only
     * increases the indentation level. The sorting puts folders before files and compares
     * within the groups naturally ("bild10" after "bild9").
     *
     * Dot entries are dropped - .htaccess and the like do not belong in a
     * selection window.
     *
     * @param string $dir Current absolute target directory
     * @param string $currentRelativePath The relative path so far to the base directory
     * @param int $indent Current indentation level (used internally)
     * @return array
     */
    private function buildDirectoryTree(string $dir, string $currentRelativePath = '', int $indent = 0): array
    {
        $tree = [];

        // ensure that the directory exists and is readable
        if (!is_dir($dir) || !is_readable($dir)) {
            return $tree;
        }

        // clean up the path (no trailing slash at the end)
        $dir = rtrim($dir, '/\\');

        // read files and folders and filter all dotfiles (starting with '.')
        $items = array_filter(scandir($dir), function ($item) {
            return !str_starts_with($item, '.');
            // For PHP versions < 8.0 instead: return $item[0] !== '.';
        });

        // sorting: folders first, then files (each alphabetically)
        usort($items, function ($a, $b) use ($dir) {
            $pathA = $dir . '/' . $a;
            $pathB = $dir . '/' . $b;

            $isDirA = is_dir($pathA);
            $isDirB = is_dir($pathB);

            if ($isDirA !== $isDirB) {
                return $isDirA ? -1 : 1;
            }

            return strnatcasecmp($a, $b);
        });

        foreach ($items as $item) {
            $fullPath = $dir . '/' . $item;
            $isDir = is_dir($fullPath);

            // skip files outside the requested extensions
            if (!$isDir && !empty($this->extensions)
                && !in_array(strtolower((string)pathinfo($item, PATHINFO_EXTENSION)), $this->extensions, true)
            ) {
                continue;
            }

            // entry for the current element.
            // $currentRelativePath here is exactly the wanted "subdir/subsubdir"
            $entry = [
                'path'   => $currentRelativePath,
                'file'   => $item,
                'indent' => $indent,
                'type'   => $isDir ? 'folder' : 'file'
            ];
            if (!$isDir) {
                $tree[] = $entry;
                continue;
            }

            // folder: process its contents recursively
            // extend the relative path for the children of this folder
            $nextRelativePath = $currentRelativePath !== ''
                ? $currentRelativePath . '/' . $item
                : $item;
            $subItems = $this->buildDirectoryTree($fullPath, $nextRelativePath, $indent + 1);
            // With an extension filter, folders without a matching file are left out - an empty
            // folder above a file of the level below looked as if the
            // file lay inside it
            if (!empty($this->extensions) && !in_array('file', array_column($subItems, 'type'), true)) {
                continue;
            }
            $tree[] = $entry;
            $tree = array_merge($tree, $subItems);
        }

        return $tree;
    }
}