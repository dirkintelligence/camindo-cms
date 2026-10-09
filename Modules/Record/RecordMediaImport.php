<?php
/**
 * Medien-Import: aus jeder Datei einer Zip-Datei wird ein neuer Datensatz - die Datei
 * im gewählten Upload-Feld, auf Wunsch ihr Name als Text in einem zweiten Feld.
 *
 * Unabhängig von RecordImport (XML/CSV/DOCX): keine Modi (immer INSERT), keine
 * Spaltenzuordnung, kein Hintergrundprozess. Die Maske ist
 * RecordBackendMediaImport (record_media_import.php), erreichbar im Menü rechts der
 * Datensatzliste eines Bereichs, unter "Import".
 *
 * ABLAUF
 * --------------------
 *     prepare()  Zip-Datei (Upload oder aus var/upload) nach
 *                var/tmp/mediaimport-{token}/ entpacken - jede Datei unter einem
 *                erzeugten Namen (files/{n}.{ext}), ihr Pfad in der Zip-Datei (nach
 *                UTF-8 umgewandelt, siehe DATEINAMEN) steht im Verzeichnis
 *                manifest.json. Ignoriert werden Einträge, die mit einem Punkt
 *                beginnen (auch in Verzeichnisnamen: .git/…, __MACOSX/…) und typische
 *                Metadateien (Thumbs.db, desktop.ini, …); jede Datei bekommt ihren
 *                Mime-Typ (finfo, aus dem Inhalt) und ggf. den Grund, aus dem sie
 *                nicht importierbar ist (GEFÄHRLICHE DATEIEN)
 *     analyze()  die Dateien des Verzeichnisses nach Mime-Typ gruppiert
 *     run()      je Datei der gewählten Typen: tmpInit() -> importSave() mit der
 *                Datei als 'importfile' (wie der CSV-Import) - Eingabeprüfung,
 *                Thumbnails, Check-in wie in der Eingabemaske
 *
 * Das Verzeichnis hält nie Dateien unter ihrem Originalnamen: Pfadsprünge
 * (Zip-Slip), Namen in fremder Kodierung und Endungen wie .php spielen damit auf
 * dem Server keine Rolle. Die Endung der erzeugten Namen ist die (gesäuberte) des
 * Originals - die Eingabeprüfung des Upload-Feldes sieht dieselbe Endung wie bei
 * einem Upload.
 *
 * GEFÄHRLICHE DATEIEN
 * --------------------
 * Nicht importierbar (ausgegraut) sind: ausführbarer Code (Record::forbiddenExtensions()
 * und ausführbare Programme/Skripte, EXECUTABLE_EXTENSIONS), aktiver Inhalt, den ein
 * Browser als Dokument bzw. Skript ausführt (MediaAccess::isActiveContent(): HTML,
 * SVG, XML, JavaScript - Endung ODER erkannter Mime-Typ), leere Dateien und Dateien,
 * deren Inhalt nicht zur Endung passt (Record::mimeExtensionMismatch(), z. B. ein
 * JPEG mit Endung .png).
 *
 * DATEINAMEN
 * --------------------
 * Zip-Dateien kennen keine verbindliche Kodierung der Namen: UTF-8 (Kennzeichen im
 * Eintrag, macOS, 7-Zip) oder die OEM-Codepage des Systems (Windows-Explorer: CP437
 * bzw. CP850), manche Werkzeuge schreiben Windows-1252. filenameUtf8() prüft die
 * rohen Bytes: gültiges UTF-8 bleibt (in NFC - macOS zerlegt Umlaute), sonst gewinnt
 * die Umwandlung aus CP850 oder Windows-1252, die mehr Buchstaben ergibt.
 * filenameText() macht daraus den Text des Namensfeldes: ohne Verzeichnis und Endung,
 * Minus und Unterstrich als Leerzeichen.
 *
 * @see Camindo\Core\Modules\Record\RecordBackendMediaImport  die Maske
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

    /** Präfix der Importverzeichnisse in var/tmp */
    public const DIR_PREFIX = 'mediaimport-';

    /** Verzeichnisliste im Importverzeichnis */
    private const MANIFEST = 'manifest.json';

    /** Typische Metadateien (Windows, macOS), kleingeschrieben - werden ignoriert */
    public const META_FILES = ['thumbs.db', 'ehthumbs.db', 'ehthumbs_vista.db', 'desktop.ini', 'icon' . "\r", 'folder.jpg', 'albumartsmall.jpg'];

    /** Verzeichnisse mit Metadaten, kleingeschrieben - ihr Inhalt wird ignoriert */
    public const META_DIRS = ['__macosx', '$recycle.bin', 'system volume information'];

    /** Ausführbare Programme und Skripte - nicht importierbar */
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
    // Schritt 1: bereitstellen

    /**
     * Zip-Datei in ein neues Importverzeichnis entpacken (siehe Klassenkommentar).
     *
     * @param string $source        Pfad der Zip-Datei (Upload-Tmpfile oder Datei in var/upload)
     * @param string $originalName  ursprünglicher Dateiname (Endung!)
     * @return array  ['dir' => 'mediaimport-…'] oder ['error' => …]
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
        // Platz für den entpackten Inhalt? (Angaben der Zip-Datei)
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
            // Pfadteile '.' und '..' fallen weg (abgelegt wird ohnehin unter
            // erzeugtem Namen - es geht nur um die Anzeige)
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
            // unter erzeugtem Namen ablegen, Endung des Originals (gesäubert)
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
     * Wird der Eintrag ignoriert? Punkt am Anfang eines Pfadteils, Metadateien und
     * -verzeichnisse (META_FILES, META_DIRS), Office-Sperrdateien (~$…).
     *
     * @return string  '' oder Grund ('hidden' | 'meta')
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
     * Grund, aus dem eine Datei nicht importierbar ist (siehe GEFÄHRLICHE DATEIEN).
     *
     * @return string  '' (importierbar) | 'forbidden' | 'active' | 'mismatch' | 'empty'
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
    // Dateinamen

    /**
     * Rohe Bytes eines Namens aus der Zip-Datei als UTF-8 (siehe DATEINAMEN).
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
                // Buchstaben, Ziffern und übliche Zeichen zählen, Symbole (Rahmen,
                // Steuerzeichen, Währungen) abziehen
                $good = (int)preg_match_all('/[\p{L}\p{N} ._\-()\/]/u', $candidate);
                $bad = mb_strlen($candidate) - $good;
                // Großbuchstabe mitten im Wort ("CafÚ" statt "Café") spricht gegen
                // die Kodierung
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
        // Steuerzeichen entfernen
        return (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $utf8);
    }

    /**
     * Text für das Namensfeld: Dateiname ohne Verzeichnis und Endung, Minus und
     * Unterstrich als Leerzeichen, Leerraum zusammengefasst.
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
    // Importverzeichnis

    /**
     * Absoluter Pfad eines Importverzeichnisses, null bei ungültigem Namen oder
     * fehlendem Verzeichnis.
     */
    public function importDir(string $dirName): ?string
    {
        if (!preg_match('/^' . preg_quote(self::DIR_PREFIX, '/') . '[A-Za-z0-9_\-]+$/', $dirName)) return null;
        $dir = (string)$this->config->get('paths.tmp_dir') . '/' . $dirName;
        return is_dir($dir) ? $dir : null;
    }

    /**
     * Importverzeichnis samt Inhalt entfernen.
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
     * Die Dateiliste des Importverzeichnisses (prepare()), null ohne Verzeichnis.
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
    // Schritt 2: analysieren

    /**
     * Dateien nach Mime-Typ gruppiert.
     *
     * @return array [
     *     'groups'  => [mime => ['count' => n, 'size' => Bytes, 'files' => [Pfad, …]]]  importierbar
     *     'blocked' => [['path', 'mime', 'reason'], …]   nicht importierbar (ausgegraut)
     *     'ignored' => [['path', 'reason'], …]           Punkt-Dateien, Metadateien
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
     * Upload-Felder eines Bereichs (ohne Thumbnails): Name => Feld.
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
     * Textfelder eines Bereichs (chars, text) für den Dateinamen: Name => Feld.
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
    // Schritt 3: importieren

    /**
     * Import ausführen: je Datei der gewählten Typen ein neuer Datensatz.
     *
     * @param array $job [
     *     'area_id'    => Zielbereich,
     *     'dir'        => Importverzeichnis (mediaimport-…),
     *     'types'      => [Mime-Typ, …],
     *     'file_field' => Name des Upload-Feldes,
     *     'name_field' => Name des Textfeldes für den Dateinamen ('' = keins) ]
     * @return array  wie RecordImport::run(): 'total', 'done', 'failed', 'skipped',
     *                'inserted', 'items' => [['kind', 'id', 'label', 'status', 'messages'], …],
     *                'error' (nur wenn gar nichts lief)
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
            // vom Upload-Feld nicht angenommen (Medien-Typen des Feldes): übergehen
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
                // Einfügeposition: Baum bzw. Handsortierung - hinter dem letzten
                // Datensatz der obersten Ebene, in der Reihenfolge der Zip-Datei
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
     * Letzter Datensatz der obersten Ebene - null, wenn der Bereich weder Baum noch
     * Handsortierung führt (dann ist die Position egal).
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
     * Die Meldungen aus Cms::errors als Klartext-Zeilen ("Label: Text").
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
     * "n hinzugefügt, n fehlgeschlagen, n übergangen"
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
