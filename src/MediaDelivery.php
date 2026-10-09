<?php
/**
 * Auslieferung von Mediendateien im Frontend über Core/public_master/file.php.
 *
 * Normalerweise verlinkt CMS:MEDIA direkt ins Medienverzeichnis, und der Webserver
 * liefert die Datei aus. Zwei Fälle brauchen stattdessen ein Script:
 *
 *     user_auth   Das Feld ist im Backend als "nur für angemeldete User" markiert
 *                 (field.details.user_auth). Sein Verzeichnis ist per .htaccess
 *                 gesperrt (Camindo\Core\MediaAccess); hier wird die Frontend-Session
 *                 geprüft und die Datei gelesen.
 *     download    CMS:MEDIA download="name" - die Datei soll unter einem eigenen
 *                 Namen zum Herunterladen angeboten werden (Content-Disposition).
 *                 Geht nur über ein Script, der Webserver kennt den Wunschnamen nicht.
 *
 * AUFRUF
 * --------------------
 *     /file.php?file={field_id}/{record_id}[/{language_id}][&download={name}]
 *
 * Wie bei backend/file.php stehen im Parameter nur Ids - Endung, Mime-Type und
 * Ablageort kommen aus `data_fil` bzw. dem Inhalts-Cache, ein Verzeichniswechsel
 * über den Parameter ist ausgeschlossen. {language_id} nennt bei mehrsprachigen
 * Datei-Feldern die Sprache; sonst gilt die Hauptsprache der Instanz des
 * Datensatzes.
 *
 * PRÜFUNGEN, IN DIESER REIHENFOLGE
 * --------------------
 *  1. Instanz aus der Domain (wie Page::resolveInstance()) - der Datensatz muss
 *     zu ihr oder einer ihrer lesbaren Instanzen gehören
 *  2. Datensatz produktiv (tmp = 0, deleted = 0) - dieselbe Sichtbarkeit wie
 *     Cms::select() im Frontend; Status und Online-Zeitraum prüft das Template
 *  3. Feld vom Typ file, gehört zum Bereich des Datensatzes
 *  4. bei user_auth: Frontend-Session angemeldet, und der Profil-Bereich des Users
 *     steht in field.details.profile_area_ids
 * Jeder Fehlschlag endet mit 404 - auch der fehlende Zugriff, damit sich aus der
 * Antwort nicht ablesen lässt, ob es die Datei gibt.
 *
 * @see Camindo\Core\Cms::cmsMedia()          erzeugt die Verweise auf file.php
 * @see Camindo\Core\MediaAccess              die .htaccess-Sperren
 * @see Camindo\Core\UserSession              Frontend-Session
 * @see Camindo\Core\Modules\Record\RecordBackendServerFile  das Gegenstück im Backend
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
     * Datei zum Request ausliefern (public/file.php).
     *
     * Request: file = "{field_id}/{record_id}[/{language_id}]"
     *          download = Dateiname ohne Endung (optional)
     *          cookiename = Name des Session-Cookies (optional, siehe CMS:USER_LOGIN)
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

        // 1. Instanz aus der Domain
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

        // 2.-4. Datensatz, Feld, user_auth - und die Datei
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

        // Ausliefern
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
     * Prüfungen 2.-4. (siehe Klassenkommentar) für eine Datei und ihr Ort - geteilt
     * von deliver() (public/file.php) und CMS:ZIP (CmsZip), damit beide dieselben
     * Regeln anwenden. Status und Online-Zeitraum prüft der Aufrufer (das Template
     * bzw. CmsZip über Cms::select()).
     *
     * @param int      $fieldId      Upload-/Thumbnail-Feld
     * @param int      $recordId
     * @param int|null $languageId   Sprache der Datei bei mehrsprachigen Feldern; null =
     *                               die Sprache, die der Request für die Instanz liest
     * @param int[]    $instanceIds  Instanzen, zu denen der Datensatz gehören darf
     * @param string   $cookieName   Session-Cookie (CMS:USER_LOGIN cookiename)
     * @return array|null  ['file' => absoluter Pfad, 'file_data' => Metadaten aus
     *                     data_fil/Cache, 'private' => bool (user_auth),
     *                     'field' => Feldname, 'record' => Datensatz-Zeile]; null = nicht
     *                     vorhanden oder kein Zugriff
     */
    public function resolveFile(int $fieldId, int $recordId, ?int $languageId, array $instanceIds, string $cookieName = ''): ?array
    {
        // 2. Datensatz
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

            // 3. Feld
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

        // Datei-Eintrag der Sprache
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
     * Datei mit passenden Headern ausgeben.
     *
     * Der Ausgabepuffer wird geleert - ein Leerzeichen aus einem Include würde die
     * Binärdaten beschädigen. Geschützte Dateien (user_auth) dürfen nicht in
     * gemeinsamen Caches landen (Cache-Control: private); öffentliche bekommen
     * einen ETag aus Größe und Änderungszeit, damit der Browser sie nicht jedes
     * Mal neu lädt.
     *
     * @param string $file      absoluter Pfad
     * @param string $mimeType  aus data_fil; leer = application/octet-stream
     * @param string $filename  Name für Content-Disposition
     * @param bool   $download  true: attachment, sonst inline
     * @param bool   $private   true: keine gemeinsamen Caches
     */
    public function sendFile(string $file, string $mimeType, string $filename, bool $download, bool $private): void
    {
        if (ob_get_level()) ob_end_clean();

        $size  = (int)filesize($file);
        $mtime = (int)filemtime($file);
        $etag  = '"' . md5($size . ':' . $mtime . ':' . $file) . '"';
        if ($mimeType === '') $mimeType = 'application/octet-stream';
        // Dateiname ohne Steuerzeichen und Anführungszeichen
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
        // Aktiver Inhalt (SVG, HTML ...): ohne Skripte, als Download (MediaAccess)
        $active = MediaAccess::isActiveContent($mimeType, (string)pathinfo($file, PATHINFO_EXTENSION));
        if ($active) header('Content-Security-Policy: sandbox');
        header('Content-Disposition: ' . ($download || $active ? 'attachment' : 'inline')
            . '; filename="' . str_replace(['"', '\\'], '_', $filename) . '"'
            . "; filename*=UTF-8''" . rawurlencode($filename));

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') return;
        readfile($file);
    }
}
