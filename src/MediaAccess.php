<?php
/**
 * Access protection of the media directory via .htaccess files.
 *
 * The storage is {paths.media_dir}/{instance_id}/{field_id}/{file} (see
 * Cms::mediaFolder()). Two levels of .htaccess files govern who may fetch a file
 * directly via the web server:
 *
 *     media/{instance_id}/.htaccess              DOMAIN LOCK
 *         The web server only serves the files of the instance if the request
 *         comes via a domain of the instance itself or of an instance that may
 *         read it (`rel_instance_instance`: there the instance appears as
 *         readable_id - its contents and thus its images appear on the
 *         pages of these "reading" instances). All other hosts get 403.
 *         These files are written for ALL instances as soon as something changes in the
 *         Instance module (rebuildAll()) - a change to the readable
 *         instances of A affects the lock of B. The same file carries the
 *         RewriteRule for speaking file names ({record},{name}.{ext} ->
 *         {record}.{ext}, see Cms::mediaUriName()).
 *
 *     media/{instance_id}/{field_id}/.htaccess   FIELD LOCK ('user_auth')
 *         An upload or thumbnail field with details.user_auth = 1 blocks every
 *         direct access. Its files are delivered exclusively by public/file.php
 *         (Camindo\Core\MediaDelivery) - after checking the frontend session.
 *         Written or removed by Field::hookOnSaved() (protectField()).
 *
 * The backend never links directly into the media directory, but always to
 * backend/file.php (RecordBackendServerFile::filePreview()). The script decides
 * using hostAllowed() whether it may redirect to the file via Location or must read it
 * itself (backend domain is not that of the instance, field is locked).
 *
 * DOMAINS AS REGULAR EXPRESSIONS
 * --------------------
 * `rel_instance_domain`.`regex` = 1 marks a PCRE pattern (see
 * InstanceCache::resolveDomain()). Apache understands the same patterns in RewriteCond,
 * so they go into the .htaccess unchanged; literal domains are
 * escaped. The default instance always gets ".*" added - it catches every
 * unknown host, so its files can be fetched from anywhere. For the
 * PHP side (hostAllowed()) the same list applies.
 *
 * All data comes from the instance file cache (InstanceCache), not from the
 * database - the hooks of the Instance module rewrite the cache beforehand.
 *
 * @see Camindo\Core\Cms::mediaFolder()                Ablageschema
 * @see Camindo\Core\Modules\Instance\Instance          ruft rebuildAll()
 * @see Camindo\Core\Modules\Area\Field                 ruft protectField()
 * @see Camindo\Core\Modules\Area\Area                  ruft moveAreaMedia()
 * @see Camindo\Core\MediaDelivery                      public/file.php
 * @see Camindo\Core\Modules\Record\RecordBackendServerFile  backend/file.php
 */
namespace Camindo\Core;

class MediaAccess {

    /** Name of the lock files */
    private const HTACCESS = '.htaccess';

    /**
     * ACTIVE CONTENT: file types that a browser executes as a document or script
     * on direct access - from /media in the origin of the website this would be
     * stored XSS. Uploads from the frontend are rejected by VirtualFieldsTrait for that
     * (setUntrustedUploads()); what was uploaded in the backend is delivered by the
     * web server with `Content-Security-Policy: sandbox` and as a download
     * (writeInstanceHtaccess(), MediaDelivery::sendFile()).
     * Developer docs, chapter 14 (Security: web)
     */
    public const ACTIVE_CONTENT_EXTENSIONS = [
        'html', 'htm', 'xhtml', 'xht', 'shtml', 'svg', 'svgz',
        'xml', 'xsl', 'xslt', 'js', 'mjs',
    ];
    public const ACTIVE_CONTENT_MIMES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml',
        'application/xml', 'text/xsl', 'application/xslt+xml',
        'text/javascript', 'application/javascript', 'application/x-javascript',
    ];

    public function __construct(
        private Config $config,
        private Database $db,
        private System $system,
        private InstanceCache $instanceCache,
        private Cms $cms,
    )
    {
    }

    /**
     * Active content (see ACTIVE_CONTENT_EXTENSIONS)? Extension OR mime type.
     */
    public static function isActiveContent(string $mimeType, string $extension): bool
    {
        return in_array(strtolower(trim($mimeType)), self::ACTIVE_CONTENT_MIMES, true)
            || in_array(strtolower(ltrim(trim($extension), '.')), self::ACTIVE_CONTENT_EXTENSIONS, true);
    }

    // ====================
    // Domain lock per instance
    // ====================

    /**
     * Hosts from which the files of an instance can be fetched - as PCRE fragments
     * without anchor and delimiter, literal domains already escaped.
     *
     * Included are the domains of the instance itself and those of all instances that
     * may read it (see class comment). Order: own instance first.
     *
     * @param int $instanceId
     * @return string[]  empty = no host may access
     */
    public function allowedHostPatterns(int $instanceId): array
    {
        $patterns = [];
        foreach ($this->readingInstanceIds($instanceId) as $id) {
            // The default instance catches every host that is not registered anywhere
            // (InstanceCache::resolveDomain()) - its files must be fetchable from
            // everywhere, otherwise exactly this site would show no images.
            if ($id === (int)($this->instanceCache->all()['default_id'] ?? 0)) {
                $patterns[] = '.*';
            }
            $instance = $this->instanceCache->instance($id);
            if ($instance === null) continue;
            $primary = trim((string)($instance['primary_domain'] ?? ''));
            if ($primary !== '') $patterns[] = preg_quote($primary, '~');
            foreach ((array)($instance['additional_domains'] ?? []) as $row) {
                $domain = trim((string)($row['domain'] ?? ''));
                if ($domain === '') continue;
                $patterns[] = !empty($row['regex']) ? $domain : preg_quote($domain, '~');
            }
        }
        return array_values(array_unique($patterns));
    }

    /**
     * May this host fetch the files of the instance directly?
     *
     * The same check the instance's .htaccess performs in the web server - for
     * backend/file.php, which decides whether it may redirect.
     *
     * @param int    $instanceId
     * @param string $host  host name, with or without port
     */
    public function hostAllowed(int $instanceId, string $host): bool
    {
        $host = mb_strtolower(trim($host));
        if (($colon = strpos($host, ':')) !== false) $host = substr($host, 0, $colon);
        if ($host === '') return false;
        foreach ($this->allowedHostPatterns($instanceId) as $pattern) {
            // An invalid pattern from the backend does not count as a match
            if (@preg_match('~^(?:' . str_replace('~', '\\~', $pattern) . ')$~ui', $host) === 1) return true;
        }
        return false;
    }

    /**
     * Rewrite the .htaccess files of ALL instance directories.
     *
     * To be called after every change in the Instance module (domains, readable
     * instances, deleted instance). Directories that exist in the media directory whose
     * instance no longer exists (any more) get a full lock - they must
     * not suddenly stand there without a rule.
     */
    public function rebuildAll(): void
    {
        $instanceIds = array_map('intval', (array)($this->instanceCache->all()['instance_ids'] ?? []));
        foreach ($instanceIds as $instanceId) {
            $this->writeInstanceHtaccess($instanceId);
        }

        // Lock orphaned instance directories
        $mediaDir = $this->mediaDir();
        foreach ((array)glob($mediaDir . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            if (!ctype_digit($name)) continue;
            if (in_array((int)$name, $instanceIds, true)) continue;
            $this->writeFile($dir, $this->denyAllContent('Instanz ' . $name . ' existiert nicht (mehr)'));
        }
    }

    /**
     * Write the .htaccess of an instance directory (directory is created).
     *
     * @param int $instanceId
     */
    public function writeInstanceHtaccess(int $instanceId): void
    {
        if ($instanceId < 1) return;
        if (!$this->system->createDir($this->mediaDir(), (string)$instanceId)) {
            throw new \Exception("MediaAccess: could not create media directory for instance {$instanceId}.");
        }
        $dir = $this->mediaDir() . '/' . $instanceId;
        $patterns = $this->allowedHostPatterns($instanceId);

        $lines = [
            '# ' . $this->generatedNote(),
            '# Instanz ' . $instanceId . ': Zugriff nur über ihre Domains und die der Instanzen, die sie lesen.',
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
        ];
        if (empty($patterns)) {
            // No domain known -> nobody may access directly
            $lines[] = 'RewriteRule .* - [F,L]';
        }
        else {
            // A port may follow the host (camindo.local:8080). The pattern is
            // in quotation marks, so that even a space in a regular
            // expression does not split the line.
            $alternation = implode('|', $patterns);
            $lines[] = 'RewriteCond %{HTTP_HOST} "!^(?:' . $alternation . ')(?::[0-9]+)?$" [NC]';
            $lines[] = 'RewriteRule .* - [F,L]';
        }
        // Speaking file names: {field}/{record}[_{lang}],{name}.{ext} - the part
        // ",{name}" is discarded unchecked (see Cms::mediaUriName()). The rule
        // is here and not in public/.htaccess, because a RewriteEngine On in
        // this directory does not inherit the rules of the parent directory.
        $lines[] = '# Sprechende Dateinamen: {feld}/{datensatz},{name}.{endung} -> {feld}/{datensatz}.{endung}';
        $lines[] = 'RewriteRule ^([0-9]+/[0-9]+(?:_[0-9]+)?),[^/]*\\.([^./]+)$ $1.$2 [L]';
        $lines[] = '</IfModule>';
        // No MIME sniffing; active content (SVG, HTML, XML, JS) runs on a direct
        // call without scripts in its own origin and comes as a download - an SVG embedded
        // as <img> remains visible (class constant ACTIVE_CONTENT_EXTENSIONS)
        $lines[] = '<IfModule mod_headers.c>';
        $lines[] = 'Header set X-Content-Type-Options "nosniff"';
        $lines[] = '<FilesMatch "\\.(?i:' . implode('|', self::ACTIVE_CONTENT_EXTENSIONS) . ')$">';
        $lines[] = 'Header set Content-Security-Policy "sandbox"';
        $lines[] = 'Header set Content-Disposition "attachment"';
        $lines[] = '</FilesMatch>';
        $lines[] = '</IfModule>';

        $this->writeFile($dir, implode("\n", $lines) . "\n");
    }

    // ====================
    // Feld-Sperre ('user_auth')
    // ====================

    /**
     * Lock or release direct access to the directory of a field.
     *
     * Locked means: files of this field only come via public/file.php
     * (frontend, with session check) or backend/file.php. Without a lock only
     * the domain lock of the instance applies.
     *
     * @param int  $fieldId
     * @param bool $protected  true = lock, false = remove lock
     * @param int  $instanceId instance of the field, if known
     */
    public function protectField(int $fieldId, bool $protected, int $instanceId = 0): void
    {
        if ($fieldId < 1) return;
        $folder = $this->cms->mediaFolder($fieldId, $instanceId);
        $dir = $this->mediaDir() . '/' . $folder;
        if ($protected) {
            if (!$this->system->createDir($this->mediaDir(), $folder)) {
                throw new \Exception("MediaAccess: could not create media directory \"{$folder}\".");
            }
            $this->writeFile($dir, $this->denyAllContent(
                'Feld ' . $fieldId . ' (user_auth): Dateien nur über file.php'
            ));
        }
        elseif (is_file($dir . '/' . self::HTACCESS)) {
            @unlink($dir . '/' . self::HTACCESS);
        }
    }

    /**
     * Is the directory of a field locked (.htaccess present)?
     * What counts for the behavior is details.user_auth of the field - this is
     * only the question to the file system, e.g. for repair tools.
     */
    public function isFieldProtected(int $fieldId, int $instanceId = 0): bool
    {
        $dir = $this->mediaDir() . '/' . $this->cms->mediaFolder($fieldId, $instanceId);
        return is_file($dir . '/' . self::HTACCESS);
    }

    /**
     * Reconcile the field locks of all upload/thumbnail fields with the stock in `field`
     * (tool: Tools -> Developer).
     *
     * @return int  number of locks written
     */
    public function rebuildFieldProtection(): int
    {
        $count = 0;
        $this->db->pushResult();
        $this->db->query(
            "SELECT `f`.`field_id`, `f`.`details`, `f`.`deleted`, `a`.`instance_id` FROM `field` `f`"
            . " INNER JOIN `area` `a` ON `a`.`area_id` = `f`.`area_id`"
            . " WHERE `f`.`type` = 'file'"
        );
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        foreach ($rows as $row) {
            $details = json_decode((string)($row['details'] ?? ''), true) ?: [];
            // fields in the trash stay locked until restored (Field::hookOnSoftDelete())
            $protected = !empty($details['user_auth']) || !empty($row['deleted']);
            $this->protectField((int)$row['field_id'], $protected, (int)$row['instance_id']);
            if ($protected) $count++;
        }
        return $count;
    }

    // ====================
    // Instanzwechsel eines Bereichs
    // ====================

    /**
     * Move the field directories of an area from one instance directory to
     * another - after an instance change of the area
     * (Area::hookOnSaved()). Field locks move along as part of the directory.
     *
     * @param int $areaId
     * @param int $oldInstanceId
     * @param int $newInstanceId
     */
    public function moveAreaMedia(int $areaId, int $oldInstanceId, int $newInstanceId): void
    {
        if ($areaId < 1 || $oldInstanceId === $newInstanceId) return;
        $mediaDir = $this->mediaDir();

        $this->db->pushResult();
        $this->db->query("SELECT `field_id` FROM `field` WHERE `area_id` = ? AND `type` = 'file'", 'i', [$areaId]);
        $fieldIds = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();

        foreach ($fieldIds as $fieldId) {
            $source = "{$mediaDir}/{$oldInstanceId}/{$fieldId}";
            if (!is_dir($source)) continue;
            if (!$this->system->createDir($mediaDir, (string)$newInstanceId)) {
                throw new \Exception("MediaAccess: could not create media directory for instance {$newInstanceId}.");
            }
            $target = "{$mediaDir}/{$newInstanceId}/{$fieldId}";
            if (is_dir($target)) {
                // Remnant of an aborted operation: move the files over one by one
                foreach ((array)scandir($source) as $entry) {
                    if ($entry === '.' || $entry === '..') continue;
                    @rename("{$source}/{$entry}", "{$target}/{$entry}");
                }
                @rmdir($source);
                continue;
            }
            if (!@rename($source, $target)) {
                throw new \Exception("MediaAccess: could not move \"{$source}\" to \"{$target}\".");
            }
        }
        // The new instance directory needs its domain lock
        $this->writeInstanceHtaccess($newInstanceId);
    }

    // ====================
    // Hilfsfunktionen
    // ====================

    /**
     * The instance itself plus all instances that may read it (readable_id =
     * $instanceId in `rel_instance_instance`) - from the file cache.
     *
     * @return int[]  own instance first
     */
    private function readingInstanceIds(int $instanceId): array
    {
        $ids = [$instanceId];
        foreach ((array)($this->instanceCache->all()['instance_ids'] ?? []) as $otherId) {
            $otherId = (int)$otherId;
            if ($otherId === $instanceId) continue;
            $readable = array_map('intval', (array)($this->instanceCache->instance($otherId)['readable_instances'] ?? []));
            if (in_array($instanceId, $readable, true)) $ids[] = $otherId;
        }
        return $ids;
    }

    private function denyAllContent(string $reason): string
    {
        return '# ' . $this->generatedNote() . "\n"
             . '# ' . $reason . "\n"
             . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
             . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
    }

    private function generatedNote(): string
    {
        return 'Generiert von ' . self::class . ' am ' . gmdate('Y-m-d H:i:s') . ' UTC - nicht von Hand ändern.';
    }

    /**
     * Write .htaccess into a directory - first temporarily, then via rename():
     * the web server never sees a half-written file.
     */
    private function writeFile(string $dir, string $content): void
    {
        $file = $dir . '/' . self::HTACCESS;
        $tmp  = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \Exception("MediaAccess: could not write \"{$tmp}\".");
        }
        @chmod($tmp, $this->config->get('permissions.file') ?? 0664);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \Exception("MediaAccess: could not rename \"{$tmp}\" to \"{$file}\".");
        }
    }

    private function mediaDir(): string
    {
        return rtrim((string)$this->config->get('paths.media_dir'), '/\\');
    }
}
