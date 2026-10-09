<?php
/**
 * Zugriffsschutz des Medienverzeichnisses über .htaccess-Dateien.
 *
 * Die Ablage ist {paths.media_dir}/{instance_id}/{field_id}/{datei} (siehe
 * Cms::mediaFolder()). Zwei Ebenen von .htaccess-Dateien regeln, wer eine Datei
 * direkt über den Webserver abrufen darf:
 *
 *     media/{instance_id}/.htaccess              DOMAIN-SPERRE
 *         Der Webserver liefert die Dateien der Instanz nur aus, wenn der Request
 *         über eine Domain der Instanz selbst oder einer Instanz kommt, die sie
 *         lesen darf (`rel_instance_instance`: dort steht die Instanz als
 *         readable_id - ihre Inhalte und damit ihre Bilder erscheinen auf den
 *         Seiten dieser "lesenden" Instanzen). Alle anderen Hosts bekommen 403.
 *         Geschrieben werden diese Dateien für ALLE Instanzen, sobald sich im Modul
 *         Instance etwas ändert (rebuildAll()) - eine Änderung an den lesbaren
 *         Instanzen von A betrifft die Sperre von B. Dieselbe Datei trägt die
 *         RewriteRule für sprechende Dateinamen ({record},{name}.{ext} ->
 *         {record}.{ext}, siehe Cms::mediaUriName()).
 *
 *     media/{instance_id}/{field_id}/.htaccess   FELD-SPERRE ('user_auth')
 *         Ein Upload- oder Thumbnail-Feld mit details.user_auth = 1 sperrt jeden
 *         direkten Zugriff. Seine Dateien liefert ausschließlich public/file.php
 *         (Camindo\Core\MediaDelivery) aus - nach Prüfung der Frontend-Session.
 *         Geschrieben bzw. entfernt von Field::hookOnSaved() (protectField()).
 *
 * Das Backend verlinkt nie direkt ins Medienverzeichnis, sondern immer auf
 * backend/file.php (RecordBackendServerFile::filePreview()). Das Script entscheidet
 * anhand von hostAllowed(), ob es per Location auf die Datei umleiten darf oder sie
 * selbst lesen muss (Backend-Domain ist nicht die der Instanz, Feld ist gesperrt).
 *
 * DOMAINS ALS REGULÄRE AUSDRÜCKE
 * --------------------
 * `rel_instance_domain`.`regex` = 1 kennzeichnet ein PCRE-Muster (siehe
 * InstanceCache::resolveDomain()). Apache versteht dieselben Muster in RewriteCond,
 * deshalb landen sie unverändert in der .htaccess; wörtliche Domains werden
 * maskiert. Die Standard-Instance bekommt immer ".*" dazu - sie fängt jeden
 * unbekannten Host auf, ihre Dateien sind damit von überall abrufbar. Für die
 * PHP-Seite (hostAllowed()) gilt dieselbe Liste.
 *
 * Alle Angaben kommen aus dem Instanz-Dateicache (InstanceCache), nicht aus der
 * Datenbank - die Hooks des Moduls Instance schreiben den Cache vorher neu.
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

    /** Name der Sperrdateien */
    private const HTACCESS = '.htaccess';

    /**
     * AKTIVER INHALT: Dateitypen, die ein Browser beim direkten Aufruf als Dokument
     * bzw. Skript ausführt - aus /media im Ursprung der Website wäre das
     * gespeichertes XSS. Uploads aus dem Frontend lehnt VirtualFieldsTrait dafür ab
     * (setUntrustedUploads()); was im Backend hochgeladen wurde, liefert der
     * Webserver mit `Content-Security-Policy: sandbox` und als Download aus
     * (writeInstanceHtaccess(), MediaDelivery::sendFile()).
     * developer_docs/16-sicherheit.md §9.3
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
     * Aktiver Inhalt (siehe ACTIVE_CONTENT_EXTENSIONS)? Extension ODER Mime-Typ.
     */
    public static function isActiveContent(string $mimeType, string $extension): bool
    {
        return in_array(strtolower(trim($mimeType)), self::ACTIVE_CONTENT_MIMES, true)
            || in_array(strtolower(ltrim(trim($extension), '.')), self::ACTIVE_CONTENT_EXTENSIONS, true);
    }

    // ====================
    // Domain-Sperre je Instanz
    // ====================

    /**
     * Hosts, über die die Dateien einer Instanz abrufbar sind - als PCRE-Fragmente
     * ohne Anker und Delimiter, wörtliche Domains bereits maskiert.
     *
     * Enthalten sind die Domains der Instanz selbst und die aller Instanzen, die
     * sie lesen dürfen (siehe Klassenkommentar). Reihenfolge: eigene Instanz zuerst.
     *
     * @param int $instanceId
     * @return string[]  leer = kein Host darf zugreifen
     */
    public function allowedHostPatterns(int $instanceId): array
    {
        $patterns = [];
        foreach ($this->readingInstanceIds($instanceId) as $id) {
            // Die Standard-Instance fängt jeden Host auf, der nirgends eingetragen
            // ist (InstanceCache::resolveDomain()) - ihre Dateien müssen von
            // überall abrufbar sein, sonst zeigte genau dieser Auftritt keine Bilder.
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
     * Darf dieser Host die Dateien der Instanz direkt abrufen?
     *
     * Dieselbe Prüfung, die die .htaccess der Instanz im Webserver vornimmt - für
     * backend/file.php, das daran entscheidet, ob es umleiten darf.
     *
     * @param int    $instanceId
     * @param string $host  Hostname, mit oder ohne Port
     */
    public function hostAllowed(int $instanceId, string $host): bool
    {
        $host = mb_strtolower(trim($host));
        if (($colon = strpos($host, ':')) !== false) $host = substr($host, 0, $colon);
        if ($host === '') return false;
        foreach ($this->allowedHostPatterns($instanceId) as $pattern) {
            // Ein ungültiges Muster aus dem Backend zählt nicht als Treffer
            if (@preg_match('~^(?:' . str_replace('~', '\\~', $pattern) . ')$~ui', $host) === 1) return true;
        }
        return false;
    }

    /**
     * .htaccess-Dateien ALLER Instanz-Verzeichnisse neu schreiben.
     *
     * Aufzurufen nach jeder Änderung im Modul Instance (Domains, lesbare Instanzen,
     * gelöschte Instanz). Verzeichnisse, die es im Medienverzeichnis gibt, deren
     * Instanz aber nicht (mehr) existiert, bekommen eine Vollsperre - sie sollen
     * nicht plötzlich ohne Regel dastehen.
     */
    public function rebuildAll(): void
    {
        $instanceIds = array_map('intval', (array)($this->instanceCache->all()['instance_ids'] ?? []));
        foreach ($instanceIds as $instanceId) {
            $this->writeInstanceHtaccess($instanceId);
        }

        // Verwaiste Instanz-Verzeichnisse sperren
        $mediaDir = $this->mediaDir();
        foreach ((array)glob($mediaDir . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            if (!ctype_digit($name)) continue;
            if (in_array((int)$name, $instanceIds, true)) continue;
            $this->writeFile($dir, $this->denyAllContent('Instanz ' . $name . ' existiert nicht (mehr)'));
        }
    }

    /**
     * .htaccess eines Instanz-Verzeichnisses schreiben (Verzeichnis wird angelegt).
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
            // Keine Domain bekannt -> niemand darf direkt zugreifen
            $lines[] = 'RewriteRule .* - [F,L]';
        }
        else {
            // Port darf hinter dem Host stehen (camindo.local:8080). Das Muster ist
            // in Anführungszeichen, damit auch ein Leerzeichen in einem regulären
            // Ausdruck die Zeile nicht zerlegt.
            $alternation = implode('|', $patterns);
            $lines[] = 'RewriteCond %{HTTP_HOST} "!^(?:' . $alternation . ')(?::[0-9]+)?$" [NC]';
            $lines[] = 'RewriteRule .* - [F,L]';
        }
        // Sprechende Dateinamen: {field}/{record}[_{lang}],{name}.{ext} - der Teil
        // ",{name}" wird ungeprüft verworfen (siehe Cms::mediaUriName()). Die Regel
        // steht hier und nicht in public/.htaccess, weil ein RewriteEngine On in
        // diesem Verzeichnis die Regeln des Elternverzeichnisses nicht erbt.
        $lines[] = '# Sprechende Dateinamen: {feld}/{datensatz},{name}.{endung} -> {feld}/{datensatz}.{endung}';
        $lines[] = 'RewriteRule ^([0-9]+/[0-9]+(?:_[0-9]+)?),[^/]*\\.([^./]+)$ $1.$2 [L]';
        $lines[] = '</IfModule>';
        // Kein MIME-Sniffing; aktiver Inhalt (SVG, HTML, XML, JS) läuft beim
        // direkten Aufruf ohne Skripte in einem eigenen Ursprung und kommt als
        // Download - als <img> eingebunden bleibt ein SVG sichtbar (Klassenkonstante
        // ACTIVE_CONTENT_EXTENSIONS)
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
     * Direkten Zugriff auf das Verzeichnis eines Feldes sperren bzw. freigeben.
     *
     * Gesperrt heißt: Dateien dieses Feldes kommen nur noch über public/file.php
     * (Frontend, mit Session-Prüfung) bzw. backend/file.php. Ohne Sperre gilt allein
     * die Domain-Sperre der Instanz.
     *
     * @param int  $fieldId
     * @param bool $protected  true = sperren, false = Sperre entfernen
     * @param int  $instanceId Instanz des Feldes, wenn bekannt
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
     * Ist das Verzeichnis eines Feldes gesperrt (.htaccess vorhanden)?
     * Maßgeblich für das Verhalten ist details.user_auth des Feldes - das hier ist
     * nur die Frage an das Dateisystem, z. B. für Reparaturwerkzeuge.
     */
    public function isFieldProtected(int $fieldId, int $instanceId = 0): bool
    {
        $dir = $this->mediaDir() . '/' . $this->cms->mediaFolder($fieldId, $instanceId);
        return is_file($dir . '/' . self::HTACCESS);
    }

    /**
     * Feld-Sperren aller Upload-/Thumbnail-Felder mit dem Bestand in `field`
     * abgleichen (Werkzeug: Tools -> Entwickler).
     *
     * @return int  Anzahl der geschriebenen Sperren
     */
    public function rebuildFieldProtection(): int
    {
        $count = 0;
        $this->db->pushResult();
        $this->db->query(
            "SELECT `f`.`field_id`, `f`.`details`, `a`.`instance_id` FROM `field` `f`"
            . " INNER JOIN `area` `a` ON `a`.`area_id` = `f`.`area_id`"
            . " WHERE `f`.`type` = 'file'"
        );
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        foreach ($rows as $row) {
            $details = json_decode((string)($row['details'] ?? ''), true) ?: [];
            $protected = !empty($details['user_auth']);
            $this->protectField((int)$row['field_id'], $protected, (int)$row['instance_id']);
            if ($protected) $count++;
        }
        return $count;
    }

    // ====================
    // Instanzwechsel eines Bereichs
    // ====================

    /**
     * Die Feld-Verzeichnisse eines Bereichs von einem Instanz-Verzeichnis in ein
     * anderes verschieben - nach einem Instanzwechsel des Bereichs
     * (Area::hookOnSaved()). Feld-Sperren wandern als Teil des Verzeichnisses mit.
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
                // Rest eines abgebrochenen Vorgangs: Dateien einzeln hinüberziehen
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
        // Das neue Instanz-Verzeichnis braucht seine Domain-Sperre
        $this->writeInstanceHtaccess($newInstanceId);
    }

    // ====================
    // Hilfsfunktionen
    // ====================

    /**
     * Die Instanz selbst plus alle Instanzen, die sie lesen dürfen (readable_id =
     * $instanceId in `rel_instance_instance`) - aus dem Dateicache.
     *
     * @return int[]  eigene Instanz zuerst
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
     * .htaccess in ein Verzeichnis schreiben - erst temporär, dann per rename():
     * der Webserver sieht nie eine halb geschriebene Datei.
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
