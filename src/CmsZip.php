<?php
/**
 * CMS:ZIP - mehrere Dateien als ZIP-Archiv herunterladen (etwa Pressebilder aus einem
 * Leuchtkasten, alle Bilder einer Meldung).
 *
 * Selten gebraucht, deshalb ein nachgeladener Dienst von Page ($lazyServices 'zip'):
 * Klasse und ZipArchive werden nur angefasst, wenn ein Template CMS:ZIP enthält.
 *
 * ABLAUF
 * --------------------
 *  1. Datensätze wählen - wie CMS:SELECT (filter, relation, src_id, sort) über
 *     Cms::select() mit Cms::publishedSql(): nur veröffentlichte Datensätze der
 *     Instanzen des Auftritts. Oder 'ids' (kommaseparierte record_ids, dieselbe
 *     Prüfung) oder source=basket (Warenkorb der Session, 'category').
 *  2. Je Datensatz die erste Datei aus 'field' (Liste), die der Besucher abrufen
 *     darf - dieselben Prüfungen wie public/file.php (MediaDelivery::resolveFile():
 *     Instanz, produktiv, Feld gehört zum Bereich, user_auth + Profil-Bereich). So
 *     bekommt die Presse die Druckversion, alle anderen die Web-Version. Was nicht
 *     erlaubt ist, wird übergangen.
 *  3. Grenzen: höchstens user.zip.max_files Dateien und user.zip.max_mb MB (die
 *     Parameter maxfiles/maxsize dürfen nur kleiner sein), Drosselung je Herkunft
 *     (user.throttle.zip_ip) - nur beim tatsächlichen Erzeugen, nicht beim Treffer
 *     im Cache.
 *  4. Archiv unter var/tmp/zip/{hash}.zip - der Hash aus Instanz, Sprache, Dateien
 *     (Feld, Datensatz, Version) und Einstellungen; dieselbe Anfrage trifft den
 *     Cache. Bilder und Archive werden gespeichert, nicht noch einmal komprimiert.
 *     Ältere Archive als user.zip.retention_hours werden beim Erzeugen entfernt.
 *  5. Ausliefern (Content-Disposition: attachment) und Seite beenden.
 *
 * Ohne Datei oder bei einer Grenze bleibt die Seite stehen: Meldung in Cms::$errors
 * (CMS:MESSAGE), $set['zip_error'] = empty | limit | throttled.
 *
 * @see Camindo\Core\MediaDelivery   Zugriffsprüfung je Datei
 * @see Camindo\Core\Page            $lazyServices
 */
namespace Camindo\Core;

class CmsZip
{
    // Endungen, die schon komprimiert sind - nur gespeichert (schneller, nicht größer)
    private const STORED = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'zip', 'gz', 'mp4', 'mov', 'mp3', 'pdf', 'docx', 'xlsx', 'pptx'];

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private System $system,
        private MediaDelivery $mediaDelivery,
        private UserSession $userSession,
        private RateLimiter $rateLimiter,
        private ClientIp $clientIp,
    ) {}

    public function cmsZip(array $p): void
    {
        // Das Frontend puffert nicht: nach der ersten Ausgabe landete das Archiv im HTML
        if (headers_sent()) {
            $this->cms->error('CMS:ZIP: headers already sent.');
            return;
        }
        $maxFiles = max(1, (int)($this->config->get('user.zip.max_files') ?: 100));
        if ((int)($p['maxfiles'] ?? 0) > 0) $maxFiles = min($maxFiles, (int)$p['maxfiles']);
        $maxBytes = max(1, (int)($this->config->get('user.zip.max_mb') ?: 500)) * 1048576;
        if ((int)($p['maxsize'] ?? 0) > 0) $maxBytes = min($maxBytes, (int)$p['maxsize'] * 1048576);

        // 1. Datensätze
        $rows = $this->selectRecords($p, $maxFiles);

        // 2. Dateien
        $fields = array_values(array_filter(array_map('trim', explode(',', (string)($p['field'] ?? '')))));
        $cookieName = (string)($p['cookiename'] ?? '');
        $instanceIds = $this->instanceIds();
        $pattern = trim((string)($p['filenames'] ?? '')) ?: '{slug}.{extension}';
        $creditFields = array_values(array_filter(array_map('trim', explode(',', (string)($p['credits'] ?? '')))));

        $files = [];      // Name im Archiv => ['file', 'version', ...]
        $credits = [];
        $bytes = 0;
        foreach ($rows as $row) {
            foreach ($fields as $name) {
                $fieldId = $this->fileFieldId((int)$row['area_id'], $name);
                if ($fieldId < 1) continue;
                $resolved = $this->mediaDelivery->resolveFile($fieldId, (int)$row['record_id'], null, $instanceIds, $cookieName);
                if ($resolved === null) continue;
                $fileData = $resolved['file_data'];
                $size = (int)(@filesize($resolved['file']) ?: 0);
                if ($bytes + $size > $maxBytes) { $this->fail('limit', $p); return; }
                $bytes += $size;
                $entry = $this->uniqueName($this->entryName($pattern, $row, $fileData), $files);
                $files[$entry] = ['file' => $resolved['file'], 'key' => $fieldId . '/' . $row['record_id'] . '/' . (int)($fileData['version'] ?? 0) . '/' . $this->cms->fileLanguageId($fileData)];
                if ($creditFields) $credits[] = $this->creditLines($entry, $row, $creditFields);
                break;
            }
            if (count($files) >= $maxFiles) break;
        }
        if (!$files) { $this->fail('empty', $p); return; }

        // 4. Archiv (Cache oder neu)
        $name = $this->safeName(trim((string)($p['name'] ?? '')) ?: 'download');
        $hash = hash('sha256', implode('|', [
            (int)($this->cms->instance['instance_id'] ?? 0), $this->cms->languageId, $pattern, implode(',', $creditFields),
            implode(';', array_map(fn($entry, $f) => $entry . '=' . $f['key'], array_keys($files), $files)),
        ]));
        $dir = $this->config->get('paths.tmp_dir') . '/zip';
        $zipFile = $dir . '/' . $hash . '.zip';
        if (!is_file($zipFile)) {
            // 3. Drosselung - nur, wenn wirklich ein Archiv entsteht
            if (!$this->rateLimiter->hit('zip_ip', $this->clientIp->rateKey())) { $this->fail('throttled', $p); return; }
            if (!$this->build($dir, $zipFile, $files, $credits)) { $this->fail('empty', $p); return; }
        }

        // 5. Ausliefern und Seite beenden
        $this->mediaDelivery->sendFile($zipFile, 'application/zip', $name . '.zip', true, true);
        exit;
    }

    /**
     * Datensätze wie CMS:SELECT - nur veröffentlichte, nur Instanzen des Auftritts.
     *
     * @return array[]  Zeilen mit virtuellen Feldern (Cms::fetch()), höchstens $limit
     */
    private function selectRecords(array $p, int $limit): array
    {
        $param = ['limit' => $limit];
        foreach (['filter', 'filter_op', 'filter_mode', 'relation', 'src_id', 'sort', 'order'] as $key) {
            if (isset($p[$key])) $param[$key] = $p[$key];
        }
        foreach (['filter_op' => 'filterop', 'filter_mode' => 'filtermode'] as $from => $to) {
            if (isset($param[$from])) $param[$to] = $param[$from];
        }
        $source = strtolower(trim((string)($p['source'] ?? '')));
        $ids = [];
        if ($source === 'basket') {
            $ids = $this->basketIds((int)($p['category'] ?? 0), (string)($p['cookiename'] ?? ''));
            if (!$ids) return [];
        }
        elseif (trim((string)($p['ids'] ?? '')) !== '') {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string)$p['ids']))));
            if (!$ids) return [];
        }
        if ($ids) {
            $param['filter']['record_id'] = implode(',', $ids);
        }
        elseif (empty($param['filter']) && trim((string)($param['relation'] ?? '')) === '') {
            // ohne Auswahl keine Dateien - nie "alle Datensätze"
            return [];
        }
        if (trim((string)($param['relation'] ?? '')) !== '') {
            // ohne src_id der aktuelle Datensatz; eine leere src_id (etwa aus einem
            // fehlenden GET-Parameter) wählt nichts - Cms::select() ließe sonst die
            // Verknüpfung weg und nähme alle Datensätze
            if (!array_key_exists('src_id', $param)) $param['src_id'] = (int)($GLOBALS['cms']['record_id'] ?? 0);
            $param['src_id'] = (int)$param['src_id'];
            if ($param['src_id'] < 1) return [];
        }
        $published = $this->cms->publishedSql($p);
        if ($published !== '') $param['sql'] = [$published];

        $rows = [];
        $this->db->pushResult();
        try {
            $this->cms->select($param);
            while ($row = $this->cms->fetch()) $rows[] = $row;
        }
        finally {
            $this->db->popResult();
        }
        // Reihenfolge des Warenkorbs bzw. aus 'ids' behalten, wenn nicht sortiert wird
        if ($ids && empty($p['sort'])) {
            $order = array_flip($ids);
            usort($rows, fn($a, $b) => ($order[(int)$a['record_id']] ?? 0) <=> ($order[(int)$b['record_id']] ?? 0));
        }
        return $rows;
    }

    /** record_ids im Warenkorb der Session (ohne eine Session anzulegen). */
    private function basketIds(int $category, string $cookieName): array
    {
        if (!$this->userSession->isActive() && !$this->userSession->load('', $cookieName)) return [];
        $this->db->pushResult();
        $this->db->query(
            "SELECT `record_id` FROM `user_basket` WHERE `session_id` = ? AND `category` = ? AND `quantity` > 0 ORDER BY `basket_id` ASC",
            'si',
            [$this->userSession->id(), $category]
        );
        $ids = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
        return $ids;
    }

    /** Instanzen des Auftritts (Cms::setInstanceAccess() aus Page). */
    private function instanceIds(): array
    {
        $ids = $this->cms->accessInstanceIds();
        if (!$ids) $ids = [(int)($this->cms->instance['instance_id'] ?? 0)];
        return array_values(array_filter(array_map('intval', $ids)));
    }

    /** field_id eines Datei-Feldes im Bereich; 0 = gibt es dort nicht. */
    private function fileFieldId(int $areaId, string $name): int
    {
        static $memo = [];
        $key = $areaId . ':' . $name;
        if (!array_key_exists($key, $memo)) {
            $this->db->pushResult();
            $this->db->query("SELECT `field_id` FROM `field` WHERE `area_id` = ? AND `name` = ? AND `type` = 'file'", 'is', [$areaId, $name]);
            $memo[$key] = (int)($this->db->fetchNum()[0] ?? 0);
            $this->db->popResult();
        }
        return $memo[$key];
    }

    /**
     * Name im Archiv aus dem Muster: {slug}, {id}, {filename} (ursprünglicher Name
     * ohne Endung), {extension}, {feld} (Inhalt eines Feldes des Datensatzes).
     */
    private function entryName(string $pattern, array $row, array $fileData): string
    {
        $extension = strtolower((string)($fileData['extension'] ?? ''));
        $original = pathinfo((string)($fileData['filename'] ?? ''), PATHINFO_FILENAME);
        $name = preg_replace_callback('/\{([a-z0-9_]+)\}/', function ($m) use ($row, $extension, $original) {
            return match ($m[1]) {
                'id'        => (string)$row['record_id'],
                'extension' => $extension,
                'filename'  => $original,
                'slug'      => (string)($row['slug'] ?? '') ?: (string)$row['record_id'],
                default     => is_scalar($row[$m[1]] ?? null) ? (string)$row[$m[1]] : '',
            };
        }, $pattern);
        $name = $this->safeName($name);
        if ($extension !== '' && !str_ends_with(strtolower($name), '.' . $extension)) $name .= '.' . $extension;
        return $name;
    }

    /** Doppelte Namen: -2, -3 … vor der Endung. */
    private function uniqueName(string $name, array $files): string
    {
        if (!isset($files[$name])) return $name;
        $info = pathinfo($name);
        $base = ($info['dirname'] ?? '.') !== '.' ? $info['dirname'] . '/' . $info['filename'] : $info['filename'];
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        for ($i = 2; isset($files[$base . '-' . $i . $ext]); $i++);
        return $base . '-' . $i . $ext;
    }

    /** Dateiname ohne Pfad, Steuerzeichen und Zeichen, die Dateisysteme ablehnen. */
    private function safeName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/', '-', strip_tags($name));
        $name = trim(preg_replace('/\s+/', ' ', $name), " .-");
        return mb_substr($name, 0, 120) ?: 'file';
    }

    /** Zeilen für CREDITS.txt: Dateiname, dann die Werte der Felder. */
    private function creditLines(string $entry, array $row, array $fields): string
    {
        $lines = [$entry];
        foreach ($fields as $field) {
            $value = $row[$field] ?? '';
            if (!is_scalar($value)) continue;
            $value = trim(html_entity_decode(strip_tags((string)$value), ENT_QUOTES, 'UTF-8'));
            if ($value !== '') $lines[] = '  ' . preg_replace('/\s*\R\s*/', ' ', $value);
        }
        return implode("\n", $lines);
    }

    /** Archiv schreiben (erst unter temporärem Namen, dann umbenennen). */
    private function build(string $dir, string $zipFile, array $files, array $credits): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return false;
        $this->cleanup($dir);
        $tmp = $zipFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) return false;
        foreach ($files as $entry => $f) {
            $zip->addFile($f['file'], $entry);
            if (in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), self::STORED, true)) {
                $zip->setCompressionName($entry, \ZipArchive::CM_STORE);
            }
        }
        if ($credits) $zip->addFromString('CREDITS.txt', implode("\n\n", $credits) . "\n");
        if (!$zip->close()) {
            @unlink($tmp);
            return false;
        }
        return @rename($tmp, $zipFile);
    }

    /** Archive, die älter als user.zip.retention_hours sind, entfernen. */
    private function cleanup(string $dir): void
    {
        $hours = (int)($this->config->get('user.zip.retention_hours') ?: 24);
        $limit = time() - $hours * 3600;
        foreach ((array)glob($dir . '/*.zip*') as $file) {
            if (is_file($file) && filemtime($file) < $limit) @unlink($file);
        }
    }

    /** Kein Archiv: Meldung (message[…] oder Snippet), $set['zip_error']. */
    private function fail(string $reason, array $p): void
    {
        $GLOBALS['set']['zip_error'] = $reason;
        $message = (string)($p['message'][$reason] ?? '') ?: $this->cms->snippet('zip_error_' . $reason);
        $this->cms->error($message);
    }
}
