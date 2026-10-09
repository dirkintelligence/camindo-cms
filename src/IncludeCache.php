<?php
/**
 * Ausgabe-Cache für CMS:INCLUDE - (CMS:INCLUDE teaser caching=5m cacheparams=$params).
 *
 * Abgelegt wird die fertige Ausgabe eines eingebundenen Templates, nicht der
 * kompilierte Code. Wer das Template mit denselben Angaben innerhalb der Frist wieder
 * einbindet, bekommt die Datei, ohne dass das Template läuft.
 *
 * SCHLÜSSEL UND ABLAGE
 * --------------------
 *     var/cache/include/{area_id}/{name}/{hash}.html
 *
 * {area_id}/{name} ist die kompilierte Datei des Templates (Cms::templateFile()), der
 * Hash (sha256) bildet sich aus Instanz, Inhaltssprache und dem Text aus
 * 'cacheparams' (paramString()). Instanz und Sprache stecken also schon im
 * Schlüssel; alles andere, wovon die Ausgabe abhängt (Datensatz, Seite der
 * Pagination, angemeldeter Kunde …), gehört in 'cacheparams'.
 *
 * Die erste Zeile der Datei ist die Ablaufzeit (Unix-Zeit, 0 = unbegrenzt) - für
 * cleanup(), das den Inhalt sonst nicht kennt. Beim Lesen gilt eine Datei nur,
 * solange
 *   - ihre Ablaufzeit nicht erreicht ist,
 *   - sie jünger ist als die im Aufruf angegebene Frist (eine verkürzte Frist greift
 *     sofort) und
 *   - die kompilierte Template-Datei nicht jünger ist als sie (ein gespeichertes
 *     Template macht seinen Cache ungültig; 'cache templates' damit alle).
 *
 * Die Nonce der Seite (CMS:NONCE, CMS:FED_SCRIPT) steht nicht in der Datei, sondern
 * ein Platzhalter, der beim Ausliefern die Nonce des laufenden Requests bekommt -
 * sonst hielte die Script-Policy eine gecachte Script-Zeile an.
 *
 * GLEICHZEITIGE AUFRUFE
 * --------------------
 * Fehlt die Datei oder ist sie abgelaufen, legt der Prozess die Sperrdatei
 * {hash}.html.lock exklusiv an (fopen 'x', O_EXCL) und rendert. Andere Prozesse mit
 * demselben Schlüssel warten, bis die Sperrdatei verschwindet (höchstens
 * admin.include_cache_lock_wait Sekunden), und lesen danach die frische Datei. Nach
 * Ablauf der Wartezeit rendern sie selbst, schreiben aber nichts. Die Cache-Datei
 * entsteht unter einem temporären Namen und wird per rename() eingesetzt - ein Leser
 * sieht nie eine halbe Datei.
 *
 * Bewusst kein flock(): Es sperrt nicht auf jedem Dateisystem prozessübergreifend
 * (NFS, die Freigaben von Docker Desktop); das exklusive Anlegen schon. Endet der
 * rendernde Prozess vorzeitig (exit im Template, Fatal Error), löscht eine
 * Shutdown-Funktion seine Sperren. Bleibt trotzdem eine liegen (Prozess abgeschossen),
 * gilt sie nach staleAfter() Sekunden als verwaist und wird übernommen.
 *
 * AUFRÄUMEN
 * --------------------
 * cleanup() - Aufgabentyp cachecleanup im Modul Scheduler: abgelaufene Dateien,
 * Dateien zu gelöschten oder geänderten Templates, liegen gebliebene temporäre Dateien
 * und Sperrdateien, leere Verzeichnisse. clear() leert alles (camindo.php cache
 * include).
 *
 * @see Camindo\Core\Cms::cmsInclude()                          Anbindung
 * @see Camindo\Core\Modules\Scheduler\SchedulerRunner         Aufgabentyp cachecleanup
 * @see Camindo\Core\Cli\CacheCommand                          cache include
 */
namespace Camindo\Core;

class IncludeCache {

    /** Unterverzeichnis von var/cache */
    public const DIR = 'include';

    /** Platzhalter für die Nonce der Seite im abgelegten Text */
    private const NONCE_PLACEHOLDER = "\x01c9-nonce\x01";

    /** Abstand der Versuche beim Warten auf eine Sperre (Mikrosekunden) */
    private const LOCK_POLL_US = 50000;

    /** Alter, ab dem cleanup() temporäre und Sperrdateien entfernt (Sekunden) */
    private const STALE_SECONDS = 3600;

    /** Mindestalter einer verwaisten Sperre (Sekunden) - siehe staleAfter() */
    private const STALE_LOCK_SECONDS = 120;

    /**
     * Laufende Aufzeichnungen (verschachtelte Includes): je Eintrag Zieldatei,
     * Sperre, Ablaufzeit und Pufferebene
     */
    private array $stack = [];

    /** Sperrdateien dieses Prozesses (Pfad => true) - releaseAll() beim Beenden */
    private array $locks = [];
    private bool $shutdownRegistered = false;

    public function __construct(
        private Config $config,
        private System $system,
    )
    {}

    /** var/cache/include */
    public function dir(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/') . '/cache/' . self::DIR;
    }

    /** Cache eingeschaltet? (admin.include_cache) */
    public function enabled(): bool
    {
        return (bool)($this->config->get('admin.include_cache') ?? true);
    }

    /**
     * Wert von 'caching' in Sekunden.
     *
     *     300, "300s"  Sekunden      "5m"  Minuten      "1h"  Stunden      "1d"  Tage
     *     "infinite"   unbegrenzt (0)
     *     "", "0"      kein Cache (null) - etwa um den Cache per Variable abzuschalten
     *
     * @throws \InvalidArgumentException  unbekannte Schreibweise
     */
    public static function ttl(mixed $value): ?int
    {
        if (!is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException('caching must be a number of seconds, 5m, 1h, 1d or infinite');
        }
        $text = strtolower(trim((string)$value));
        if ($text === 'infinite') return 0;
        if (!preg_match('/^(\d+)\s*([smhd]?)$/', $text, $m)) {
            if ($text === '') return null;
            throw new \InvalidArgumentException("invalid caching value \"$text\" (seconds, 5m, 1h, 1d or infinite)");
        }
        $seconds = (int)$m[1] * match ($m[2]) {
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            default => 1,
        };
        return $seconds > 0 ? $seconds : null;
    }

    /**
     * 'cacheparams' als Text: null, bool, int, float und string wie (string),
     * Arrays als JSON (Schlüssel und Reihenfolge zählen mit).
     *
     * @throws \InvalidArgumentException  Objekt ohne __toString(), Ressource
     */
    public static function paramString(mixed $params): string
    {
        if ($params === null || is_scalar($params)) return (string)$params;
        if ($params instanceof \Stringable) return (string)$params;
        if (is_array($params)) {
            $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            if ($json !== false) return $json;
            return serialize($params);
        }
        throw new \InvalidArgumentException('cacheparams must be null, bool, int, string or array');
    }

    /**
     * Hash des Schlüssels - siehe Klassenkommentar.
     */
    public static function key(int $instanceId, int $languageId, string $params): string
    {
        return hash('sha256', $instanceId . "\n" . $languageId . "\n" . $params);
    }

    /**
     * Gültigen Inhalt holen - oder die Aufzeichnung beginnen.
     *
     * Bei einem Treffer kommt der Text (mit der Nonce $nonce) zurück; der Aufrufer gibt
     * ihn aus und bindet das Template nicht ein. Sonst kommt null: dann läuft ab hier
     * ein Ausgabepuffer, der Aufrufer bindet das Template ein und ruft danach finish().
     *
     * @param string $templateFile  "{area_id}/{name}" (Cms::templateFile())
     * @param string $source        absoluter Pfad der kompilierten Datei
     * @param int    $ttl           Frist in Sekunden, 0 = unbegrenzt
     * @param string $nonce         Nonce der laufenden Seite
     */
    public function start(string $templateFile, string $key, int $ttl, string $source, string $nonce): ?string
    {
        $relative = $this->relativePath($templateFile, $key);
        $path = $this->dir() . '/' . $relative;
        $sourceTime = (int)@filemtime($source);

        $content = $this->read($path, $ttl, $sourceTime);
        if ($content !== null) return $this->withNonce($content, $nonce);

        // Derselbe Schlüssel wird in diesem Prozess schon aufgezeichnet (das Template
        // bindet sich über Umwege selbst ein): ohne Cache rendern, sonst wartete der
        // Prozess auf die eigene Sperre
        $lock = false;
        if (!in_array($path, array_column($this->stack, 'path'), true)
            && $this->system->createDir(dirname($this->dir()), self::DIR . '/' . dirname($relative))) {
            $lock = $this->lock($path . '.lock');
            // Ein anderer Prozess hat die Datei geschrieben, während wir warteten
            if ($lock && ($content = $this->read($path, $ttl, $sourceTime)) !== null) {
                $this->unlock($path . '.lock');
                return $this->withNonce($content, $nonce);
            }
        }

        $this->stack[] = [
            'path'    => $path,
            'lock'    => $lock,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'level'   => ob_get_level(),
        ];
        ob_start();
        return null;
    }

    /**
     * Aufzeichnung beenden: Ausgabe zurückgeben und - bei $store und gehaltener
     * Sperre - ablegen.
     *
     * @param bool   $store  false: nicht ablegen (Fehlermeldungen beim Rendern)
     * @param string $nonce  Nonce der laufenden Seite - im abgelegten Text Platzhalter
     */
    public function finish(bool $store, string $nonce): string
    {
        $entry = array_pop($this->stack);
        if ($entry === null) return '';

        // Puffer, die das Template offen gelassen hat, gehören zur Ausgabe
        $output = '';
        while (ob_get_level() > $entry['level']) {
            $output = (string)ob_get_clean() . $output;
        }

        if ($entry['lock']) {
            if ($store) {
                $content = $nonce !== '' ? str_replace($nonce, self::NONCE_PLACEHOLDER, $output) : $output;
                $this->write($entry['path'], $entry['expires'], $content);
            }
            $this->unlock($entry['path'] . '.lock');
        }
        return $output;
    }

    /**
     * Abgelaufene und verwaiste Dateien löschen - siehe Klassenkommentar.
     *
     * @return array  ['files' => gelöschte Cache-Dateien, 'bytes' => deren Größe,
     *                 'kept' => verbliebene Cache-Dateien, 'failed' => Dateinamen]
     */
    public function cleanup(): array
    {
        $result = ['files' => 0, 'bytes' => 0, 'kept' => 0, 'failed' => []];
        $dir = $this->dir();
        if (!is_dir($dir)) return $result;

        $now = time();
        $templatePath = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/php';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isDir()) {
                @rmdir($path);   // nur leere Verzeichnisse
                continue;
            }
            $name = $entry->getFilename();
            $mtime = (int)$entry->getMTime();

            if (!str_ends_with($name, '.html')) {
                // Sperrdatei oder temporäre Datei eines abgebrochenen Prozesses
                if ($now - $mtime >= self::STALE_SECONDS) @unlink($path);
                continue;
            }

            // Template zur Datei: {area_id}/{name} aus dem Pfad
            $templateFile = substr(dirname($path), strlen($dir) + 1);
            $source = $templatePath . '/' . $templateFile . '.php';
            $expires = $this->expires($path);
            $sourceTime = is_file($source) ? (int)filemtime($source) : null;
            $stale = $sourceTime === null
                || $sourceTime > $mtime
                || $expires === null
                || ($expires > 0 && $expires <= $now);
            if (!$stale) {
                $result['kept']++;
                continue;
            }
            $size = (int)$entry->getSize();
            if (@unlink($path)) {
                $result['files']++;
                $result['bytes'] += $size;
            }
            else {
                $result['failed'][] = substr($path, strlen($dir) + 1);
            }
        }
        return $result;
    }

    /**
     * Umfang des Caches (Infoseite der Aufgabe, Startseite).
     *
     * @return array  ['files' => Anzahl Cache-Dateien, 'bytes' => Größe]
     */
    public function stats(): array
    {
        $stats = ['files' => 0, 'bytes' => 0];
        if (!is_dir($this->dir())) return $stats;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if (!str_ends_with($entry->getFilename(), '.html')) continue;
            $stats['files']++;
            $stats['bytes'] += (int)$entry->getSize();
        }
        return $stats;
    }

    /**
     * Cache leeren - ganz oder für ein Template.
     *
     * @param string $templateFile  "{area_id}/{name}", '' = alles
     * @return int  Anzahl gelöschter Cache-Dateien
     */
    public function clear(string $templateFile = ''): int
    {
        $dir = $this->dir();
        if ($templateFile !== '') {
            if (!preg_match('~^\d+/[a-z0-9_]+$~', $templateFile)) return 0;
            $dir .= '/' . $templateFile;
        }
        if (!is_dir($dir)) return 0;

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isDir()) {
                @rmdir($path);
            }
            elseif (str_ends_with($path, '.lock') && time() - (int)$entry->getMTime() < $this->staleAfter()) {
                // Sperre eines gerade rendernden Prozesses bleibt
            }
            elseif (@unlink($path) && str_ends_with($path, '.html')) {
                $count++;
            }
        }
        if ($templateFile !== '') {
            // leere Verzeichnisse des Templates und seines Bereichs
            @rmdir($dir);
            @rmdir(dirname($dir));
        }
        return $count;
    }

    // Intern
    // ====================

    /** "{area_id}/{name}/{hash}.html" */
    private function relativePath(string $templateFile, string $key): string
    {
        return $templateFile . '/' . $key . '.html';
    }

    /**
     * Inhalt einer gültigen Datei (mit Platzhalter), sonst null.
     */
    private function read(string $path, int $ttl, int $sourceTime): ?string
    {
        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        if ($mtime === false) return null;
        $now = time();
        if ($ttl > 0 && $mtime + $ttl <= $now) return null;
        if ($sourceTime > $mtime) return null;

        $data = @file_get_contents($path);
        if ($data === false) return null;
        $pos = strpos($data, "\n");
        if ($pos === false || !ctype_digit(substr($data, 0, $pos))) return null;
        $expires = (int)substr($data, 0, $pos);
        if ($expires > 0 && $expires <= $now) return null;
        return substr($data, $pos + 1);
    }

    /** Ablaufzeit aus der ersten Zeile; null = keine Cache-Datei */
    private function expires(string $path): ?int
    {
        $fh = @fopen($path, 'r');
        if ($fh === false) return null;
        $line = (string)fgets($fh, 32);
        fclose($fh);
        $line = rtrim($line, "\n");
        return ctype_digit($line) ? (int)$line : null;
    }

    /** Platzhalter durch die Nonce des Requests ersetzen */
    private function withNonce(string $content, string $nonce): string
    {
        return str_replace(self::NONCE_PLACEHOLDER, $nonce, $content);
    }

    /**
     * Datei schreiben: temporär daneben, dann rename() - Leser sehen die alte oder
     * die neue Datei, nie eine halbe.
     */
    private function write(string $path, int $expires, string $content): void
    {
        $dir = dirname($path);
        $tmp = basename($path) . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (!$this->system->filePutContents($dir, $tmp, $expires . "\n" . $content)) {
            @unlink("$dir/$tmp");
            return;
        }
        if (!@rename("$dir/$tmp", $path)) @unlink("$dir/$tmp");
    }

    /**
     * Sperre holen: Sperrdatei exklusiv anlegen - höchstens
     * admin.include_cache_lock_wait Sekunden warten, solange ein anderer Prozess sie
     * hält. Eine verwaiste Sperre (älter als staleAfter()) wird übernommen.
     *
     * @return bool  false: keine Sperre (Zeit abgelaufen, Datei nicht anlegbar)
     */
    private function lock(string $lockFile): bool
    {
        $wait = max(0, (int)($this->config->get('admin.include_cache_lock_wait') ?? 30));
        $deadline = microtime(true) + $wait;
        while (true) {
            $fh = @fopen($lockFile, 'x');
            if ($fh !== false) {
                fwrite($fh, getmypid() . ' ' . time());
                fclose($fh);
                @chmod($lockFile, $this->config->get('permissions.file') ?? 0664);
                $this->locks[$lockFile] = true;
                if (!$this->shutdownRegistered) {
                    $this->shutdownRegistered = true;
                    register_shutdown_function(fn() => $this->releaseAll());
                }
                return true;
            }
            clearstatcache(true, $lockFile);
            $mtime = @filemtime($lockFile);
            if ($mtime === false) {
                // eben freigegeben - oder das Verzeichnis ist nicht beschreibbar
                if (!is_writable(dirname($lockFile))) return false;
            }
            elseif (time() - $mtime > $this->staleAfter()) {
                // verwaist: umbenennen statt löschen - das gelingt nur einem
                $stale = $lockFile . '.' . getmypid() . '.stale';
                if (@rename($lockFile, $stale)) @unlink($stale);
                continue;
            }
            if (microtime(true) >= $deadline) return false;
            usleep(self::LOCK_POLL_US);
        }
    }

    /**
     * Ab diesem Alter gilt eine Sperre als verwaist (Sekunden): das Doppelte der
     * Wartezeit, mindestens STALE_LOCK_SECONDS - so lange rendert kein Template.
     */
    private function staleAfter(): int
    {
        $wait = max(0, (int)($this->config->get('admin.include_cache_lock_wait') ?? 30));
        return max(self::STALE_LOCK_SECONDS, 2 * $wait);
    }

    /** Sperre freigeben */
    private function unlock(string $lockFile): void
    {
        @unlink($lockFile);
        unset($this->locks[$lockFile]);
    }

    /**
     * Alle Sperren dieses Prozesses freigeben - Shutdown-Funktion, falls das Template
     * mit exit oder einem Fatal Error endet, bevor finish() lief.
     */
    private function releaseAll(): void
    {
        foreach (array_keys($this->locks) as $lockFile) {
            $this->unlock($lockFile);
        }
    }
}
