<?php
/**
 * Output cache for CMS:INCLUDE - (CMS:INCLUDE teaser caching=5m cacheparams=$params).
 *
 * What is stored is the finished output of an included template, not the
 * compiled code. Whoever includes the template again with the same parameters within the
 * time limit gets the file without the template running.
 *
 * KEY AND STORAGE
 * --------------------
 *     var/cache/include/{area_id}/{name}/{hash}.html
 *
 * {area_id}/{name} is the compiled file of the template (Cms::templateFile()), the
 * hash (sha256) is formed from instance, content language and the text from
 * 'cacheparams' (paramString()). Instance and language are thus already part of the
 * key; everything else the output depends on (record, page of the
 * pagination, logged-in customer …) belongs in 'cacheparams'.
 *
 * The first line of the file is the expiry time (Unix time, 0 = unlimited) - for
 * cleanup(), which otherwise does not know the content. When reading, a file only applies
 * as long as
 *   - its expiry time has not been reached,
 *   - it is younger than the time limit given in the call (a shortened limit applies
 *     immediately) and
 *   - the compiled template file is not younger than it (a saved template invalidates
 *     its cache; 'cache templates' does so for all).
 *
 * The nonce of the page (CMS:NONCE, CMS:FED_SCRIPT) is not in the file; instead a
 * placeholder that receives the nonce of the running request on delivery -
 * otherwise the script policy would block a cached script line.
 *
 * CONCURRENT CALLS
 * --------------------
 * If the file is missing or expired, the process creates the lock file
 * {hash}.html.lock exclusively (fopen 'x', O_EXCL) and renders. Other processes with
 * the same key wait until the lock file disappears (at most
 * admin.include_cache_lock_wait seconds), and then read the fresh file. After the
 * waiting time has elapsed they render themselves, but write nothing. The cache file
 * is created under a temporary name and put in place via rename() - a reader
 * never sees a half-written file.
 *
 * Deliberately no flock(): it does not lock across processes on every file system
 * (NFS, the shares of Docker Desktop); exclusive creation does. If the rendering
 * process ends early (exit in the template, fatal error), a
 * shutdown function deletes its locks. If one is still left behind (process killed),
 * it counts as orphaned after staleAfter() seconds and is taken over.
 *
 * CLEANUP
 * --------------------
 * cleanup() - task type cachecleanup in the Scheduler module: expired files,
 * files for deleted or changed templates, left-over temporary files
 * and lock files, empty directories. clear() empties everything (camindo.php cache
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

    /** Placeholder for the nonce of the page in the stored text */
    private const NONCE_PLACEHOLDER = "\x01c9-nonce\x01";

    /** Interval between attempts when waiting for a lock (microseconds) */
    private const LOCK_POLL_US = 50000;

    /** Age from which cleanup() removes temporary and lock files (seconds) */
    private const STALE_SECONDS = 3600;

    /** Minimum age of an orphaned lock (seconds) - see staleAfter() */
    private const STALE_LOCK_SECONDS = 120;

    /**
     * Running recordings (nested includes): per entry target file,
     * lock, expiry time and output level
     */
    private array $stack = [];

    /** Lock files of this process (path => true) - releaseAll() on shutdown */
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

    /** Cache enabled? (admin.include_cache) */
    public function enabled(): bool
    {
        return (bool)($this->config->get('admin.include_cache') ?? true);
    }

    /**
     * Value of 'caching' in seconds.
     *
     *     300, "300s"  seconds       "5m"  minutes      "1h"  hours      "1d"  days
     *     "infinite"   unlimited (0)
     *     "", "0"      no cache (null) - e.g. to switch the cache off via a variable
     *
     * @throws \InvalidArgumentException  unknown notation
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
     * 'cacheparams' as text: null, bool, int, float and string as (string),
     * arrays as JSON (keys and order count).
     *
     * @throws \InvalidArgumentException  object without __toString(), resource
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
     * Hash of the key - see class comment.
     */
    public static function key(int $instanceId, int $languageId, string $params): string
    {
        return hash('sha256', $instanceId . "\n" . $languageId . "\n" . $params);
    }

    /**
     * Fetch valid content - or start the recording.
     *
     * On a hit the text comes back (with the nonce $nonce); the caller outputs
     * it and does not include the template. Otherwise null comes back: then from here on
     * an output buffer runs, the caller includes the template and calls finish() afterwards.
     *
     * @param string $templateFile  "{area_id}/{name}" (Cms::templateFile())
     * @param string $source        absolute path of the compiled file
     * @param int    $ttl           time limit in seconds, 0 = unlimited
     * @param string $nonce         nonce of the running page
     */
    public function start(string $templateFile, string $key, int $ttl, string $source, string $nonce): ?string
    {
        $relative = $this->relativePath($templateFile, $key);
        $path = $this->dir() . '/' . $relative;
        $sourceTime = (int)@filemtime($source);

        $content = $this->read($path, $ttl, $sourceTime);
        if ($content !== null) return $this->withNonce($content, $nonce);

        // The same key is already being recorded in this process (the template
        // includes itself indirectly): render without cache, otherwise the
        // process would wait for its own lock
        $lock = false;
        if (!in_array($path, array_column($this->stack, 'path'), true)
            && $this->system->createDir(dirname($this->dir()), self::DIR . '/' . dirname($relative))) {
            $lock = $this->lock($path . '.lock');
            // Another process wrote the file while we were waiting
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
     * End the recording: return the output and - if $store is set and the
     * lock is held - store it.
     *
     * @param bool   $store  false: do not store (error messages while rendering)
     * @param string $nonce  nonce of the running page - placeholder in the stored text
     */
    public function finish(bool $store, string $nonce): string
    {
        $entry = array_pop($this->stack);
        if ($entry === null) return '';

        // Buffers the template left open belong to the output
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
     * Delete expired and orphaned files - see class comment.
     *
     * @return array  ['files' => deleted cache files, 'bytes' => their size,
     *                 'kept' => remaining cache files, 'failed' => file names]
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
                @rmdir($path);   // empty directories only
                continue;
            }
            $name = $entry->getFilename();
            $mtime = (int)$entry->getMTime();

            if (!str_ends_with($name, '.html')) {
                // Lock file or temporary file of an aborted process
                if ($now - $mtime >= self::STALE_SECONDS) @unlink($path);
                continue;
            }

            // Template of the file: {area_id}/{name} from the path
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
     * Size of the cache (info page of the task, start page).
     *
     * @return array  ['files' => number of cache files, 'bytes' => size]
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
     * Empty the cache - completely or for one template.
     *
     * @param string $templateFile  "{area_id}/{name}", '' = everything
     * @return int  number of deleted cache files
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
                // Lock of a process that is currently rendering stays
            }
            elseif (@unlink($path) && str_ends_with($path, '.html')) {
                $count++;
            }
        }
        if ($templateFile !== '') {
            // empty directories of the template and its area
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
     * Content of a valid file (with placeholder), otherwise null.
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

    /** Expiry time from the first line; null = no cache file */
    private function expires(string $path): ?int
    {
        $fh = @fopen($path, 'r');
        if ($fh === false) return null;
        $line = (string)fgets($fh, 32);
        fclose($fh);
        $line = rtrim($line, "\n");
        return ctype_digit($line) ? (int)$line : null;
    }

    /** Replace the placeholder with the nonce of the request */
    private function withNonce(string $content, string $nonce): string
    {
        return str_replace(self::NONCE_PLACEHOLDER, $nonce, $content);
    }

    /**
     * Write the file: temporarily next to it, then rename() - readers see the old or
     * the new file, never a half-written one.
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
     * Acquire the lock: create the lock file exclusively - wait at most
     * admin.include_cache_lock_wait seconds while another process holds it.
     * An orphaned lock (older than staleAfter()) is taken over.
     *
     * @return bool  false: no lock (time expired, file cannot be created)
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
                // just released - or the directory is not writable
                if (!is_writable(dirname($lockFile))) return false;
            }
            elseif (time() - $mtime > $this->staleAfter()) {
                // orphaned: rename instead of delete - only one process succeeds at this
                $stale = $lockFile . '.' . getmypid() . '.stale';
                if (@rename($lockFile, $stale)) @unlink($stale);
                continue;
            }
            if (microtime(true) >= $deadline) return false;
            usleep(self::LOCK_POLL_US);
        }
    }

    /**
     * From this age a lock counts as orphaned (seconds): twice the waiting
     * time, at least STALE_LOCK_SECONDS - no template renders that long.
     */
    private function staleAfter(): int
    {
        $wait = max(0, (int)($this->config->get('admin.include_cache_lock_wait') ?? 30));
        return max(self::STALE_LOCK_SECONDS, 2 * $wait);
    }

    /** Release the lock */
    private function unlock(string $lockFile): void
    {
        @unlink($lockFile);
        unset($this->locks[$lockFile]);
    }

    /**
     * Release all locks of this process - shutdown function, in case the template
     * ends with exit or a fatal error before finish() ran.
     */
    private function releaseAll(): void
    {
        foreach (array_keys($this->locks) as $lockFile) {
            $this->unlock($lockFile);
        }
    }
}
