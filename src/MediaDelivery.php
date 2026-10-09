<?php
/**
 * Delivery of media files in the frontend via Core/public_master/file.php.
 *
 * Normally CMS:MEDIA links directly into the media directory, and the web server
 * delivers the file. Two cases need a script instead:
 *
 *     user_auth   The field is marked in the backend as "only for logged-in users"
 *                 (field.details.user_auth). Its directory is locked via .htaccess
 *                 (Camindo\Core\MediaAccess); here the frontend session is
 *                 checked and the file is read.
 *     download    CMS:MEDIA download="name" - the file should be offered for download
 *                 under its own name (Content-Disposition).
 *                 Only possible via a script, the web server does not know the requested name.
 *
 * CALL
 * --------------------
 *     /file.php?file={field_id}/{record_id}[/{language_id}][&download={name}]
 *
 * As with backend/file.php, the parameter contains only ids - extension, MIME type and
 * storage location come from `data_fil` or the content cache; changing directory
 * via the parameter is impossible. {language_id} names the language for multilingual
 * file fields; otherwise the main language of the record's instance applies.
 *
 * CHECKS, IN THIS ORDER
 * --------------------
 *  1. Instance from the domain (as Page::resolveInstance()) - the record must
 *     belong to it or to one of its readable instances
 *  2. Record live (tmp = 0, deleted = 0) - the same visibility as
 *     Cms::select() in the frontend; the template checks status and online period
 *  3. Field of type file, belongs to the area of the record
 *  4. for user_auth: frontend session logged in, and the profile area of the user
 *     is listed in field.details.profile_area_ids
 * Every failure ends with 404 - including missing access, so that the response
 * does not reveal whether the file exists.
 *
 * @see Camindo\Core\Cms::cmsMedia()          creates the links to file.php
 * @see Camindo\Core\MediaAccess              the .htaccess locks
 * @see Camindo\Core\UserSession              frontend session
 * @see Camindo\Core\Modules\Record\RecordBackendServerFile  the counterpart in the backend
 */
namespace Camindo\Core;

use Camindo\Core\Modules\Instance\Instance;

class MediaDelivery {

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private System $system,
        private InstanceCache $instanceCache,
        private UserSession $userSession,
    )
    {
    }

    /**
     * Deliver the file for the request (public/file.php).
     *
     * Request: file = "{field_id}/{record_id}[/{language_id}]"
     *          download = file name without extension (optional)
     *          cookiename = name of the session cookie (optional, see CMS:USER_LOGIN)
     */
    public function deliver(): void
    {
        $parts = explode('/', (string)($_REQUEST['file'] ?? ''), 3);
        $fieldId  = (int)($parts[0] ?? 0);
        $recordId = (int)($parts[1] ?? 0);
        $langPart = trim((string)($parts[2] ?? ''));
        if ($fieldId < 1 || $recordId < 1) {
            $this->system->error404();
            return;
        }

        // 1. Instance from the domain
        // --------------------
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (($colon = strpos($host, ':')) !== false) $host = substr($host, 0, $colon);
        $instanceId = $this->instanceCache->resolveDomain(mb_strtolower(trim($host)));
        if ($instanceId < 1) $instanceId = Instance::DEFAULT_ID;
        $instanceIds = [$instanceId];
        foreach ((array)($this->instanceCache->instance($instanceId)['readable_instances'] ?? []) as $readableId) {
            $readableId = (int)$readableId;
            if ($readableId > 0 && !in_array($readableId, $instanceIds, true)) $instanceIds[] = $readableId;
        }
        $this->cms->setInstanceAccess($instanceIds);

        // 2.-4. Record, field, user_auth - and the file
        // --------------------
        $cookieName = (string)($_REQUEST['cookiename'] ?? '');
        $languageId = ($langPart !== '' && ctype_digit($langPart)) ? (int)$langPart : null;
        $resolved = $this->resolveFile($fieldId, $recordId, $languageId, $instanceIds, $cookieName);
        if ($resolved === null) {
            $this->system->error404();
            return;
        }
        $file = $resolved['file'];
        $fileData = $resolved['file_data'];
        $extension = (string)$fileData['extension'];

        // Deliver
        // --------------------
        $download = trim((string)($_REQUEST['download'] ?? ''));
        $this->sendFile(
            $file,
            (string)($fileData['mime_type'] ?? ''),
            $download !== '' ? $download . '.' . $extension : (string)($fileData['filename'] ?? basename($file)),
            $download !== '',
            $resolved['private']
        );
    }

    /**
     * Checks 2.-4. (see class comment) for a file and its location - shared
     * by deliver() (public/file.php) and CMS:ZIP (CmsZip), so that both apply the same
     * rules. Status and online period are checked by the caller (the template
     * or CmsZip via Cms::select()).
     *
     * @param int      $fieldId      upload/thumbnail field
     * @param int      $recordId
     * @param int|null $languageId   language of the file for multilingual fields; null =
     *                               the language the request reads for the instance
     * @param int[]    $instanceIds  instances the record may belong to
     * @param string   $cookieName   session cookie (CMS:USER_LOGIN cookiename)
     * @return array|null  ['file' => absolute path, 'file_data' => metadata from
     *                     data_fil/cache, 'private' => bool (user_auth),
     *                     'field' => field name, 'record' => record row]; null = not
     *                     present or no access
     */
    public function resolveFile(int $fieldId, int $recordId, ?int $languageId, array $instanceIds, string $cookieName = ''): ?array
    {
        // 2. Record
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT * FROM `record` WHERE `record_id` = ? AND `tmp` = 0 AND `deleted` = 0",
                'i',
                [$recordId]
            );
            if ($this->db->num_rows < 1) return null;
            $record = $this->cms->fetch() ?? [];
            if (!in_array((int)($record['instance_id'] ?? 0), $instanceIds, true)) return null;
            $areaId = (int)($record['area_id'] ?? 0);

            // 3. Field
            $this->db->query(
                "SELECT `field_id`, `name`, `type`, `multilingual`, `details` FROM `field` WHERE `field_id` = ? AND `area_id` = ?",
                'ii',
                [$fieldId, $areaId]
            );
            $field = $this->db->fetch();
        }
        finally {
            $this->db->popResult();
        }
        if ($field === null || (string)$field['type'] !== 'file') return null;
        $details = json_decode((string)($field['details'] ?? ''), true) ?: [];

        // 4. user_auth
        $private = !empty($details['user_auth']);
        if ($private) {
            if (!$this->userSession->isLoggedIn() && !$this->userSession->load('', $cookieName)) return null;
            if (!$this->userSession->isLoggedIn()) return null;
            $allowedAreas = array_map('intval', (array)($details['profile_area_ids'] ?? []));
            if (!in_array($this->userSession->areaId(), $allowedAreas, true)) return null;
        }

        // File entry of the language
        $name = (string)$field['name'];
        $recordInstanceId = (int)($record['instance_id'] ?? 0);
        $languageId ??= $this->cms->recordLanguageId($recordInstanceId);
        $fileData = (array)($record[$name] ?? []);
        if (!empty($field['multilingual'])) {
            $cache = $this->cms->loadRecordCache($recordId, $languageId) ?? [];
            $fileData = (array)($cache[$name][1] ?? $fileData);
        }
        $extension = (string)($fileData['extension'] ?? '');
        if ($extension === '') return null;

        $file = $this->config->get('paths.media_dir') . '/' . $this->cms->mediaPath(
            $fieldId, $recordId, $this->cms->fileLanguageId($fileData), $extension, $recordInstanceId
        );
        if (!is_file($file)) return null;
        return ['file' => $file, 'file_data' => $fileData, 'private' => $private, 'field' => $name, 'record' => $record];
    }

    /**
     * Output the file with matching headers.
     *
     * The output buffer is cleared - a space from an include would corrupt the
     * binary data. Protected files (user_auth) must not end up in shared
     * caches (Cache-Control: private); public ones get
     * an ETag from size and modification time, so that the browser does not reload
     * them every time.
     *
     * @param string $file      absolute path
     * @param string $mimeType  from data_fil; empty = application/octet-stream
     * @param string $filename  name for Content-Disposition
     * @param bool   $download  true: attachment, otherwise inline
     * @param bool   $private   true: no shared caches
     */
    public function sendFile(string $file, string $mimeType, string $filename, bool $download, bool $private): void
    {
        if (ob_get_level()) ob_end_clean();

        $size  = (int)filesize($file);
        $mtime = (int)filemtime($file);
        $etag  = '"' . md5($size . ':' . $mtime . ':' . $file) . '"';
        if ($mimeType === '') $mimeType = 'application/octet-stream';
        // File name without control characters and quotes
        $filename = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', $filename) ?: 'file';

        if (!$private && (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            header('HTTP/1.1 304 Not Modified');
            header('ETag: ' . $etag);
            return;
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $size);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('ETag: ' . $etag);
        header('Cache-Control: ' . ($private ? 'private, no-store' : 'public, max-age=86400'));
        header('X-Content-Type-Options: nosniff');
        // Active content (SVG, HTML ...): without scripts, as a download (MediaAccess)
        $active = MediaAccess::isActiveContent($mimeType, (string)pathinfo($file, PATHINFO_EXTENSION));
        if ($active) header('Content-Security-Policy: sandbox');
        header('Content-Disposition: ' . ($download || $active ? 'attachment' : 'inline')
            . '; filename="' . str_replace(['"', '\\'], '_', $filename) . '"'
            . "; filename*=UTF-8''" . rawurlencode($filename));

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') return;
        readfile($file);
    }
}
