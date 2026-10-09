<?php
/**
 * Read, split and classify the log files under var/log - without UI.
 * Instance name in the code: $logfiles.
 *
 * NO DATASET, NO DATABASE
 * --------------------
 * The storage is the files that System::log() and PHP's own error log
 * write: var/log/{Y-m-d}_{name}.log, one file per day and purpose. Nothing is
 * written here - the module only reads.
 *
 * A LINE IS NOT ALWAYS AN ENTRY
 * --------------------
 * Three line formats occur in the existing logs:
 *
 *     [14:22:07] text                              System::log()
 *     [22-Sep-2026 00:18:23 Europe/Berlin] text    PHP error log (Config)
 *     2026-09-21 17:45:47 text                     upgrade log
 *
 * Everything that does not begin with one of these timestamps belongs to the entry before -
 * typically the stack trace of an exception. entries() therefore appends such lines
 * to the current entry instead of showing them individually; otherwise a
 * fatal error falls apart into twenty meaningless lines.
 *
 * CATEGORY AND URGENCY
 * --------------------
 * classify() assigns each entry to a category (CATEGORIES) - from the
 * file name and, where that is not enough, from the text. The order in CATEGORIES
 * is the ranking: what is at the top, the widget shows first. It follows what
 * an operator wants to know first - blocked logins, undelivered
 * emails, crashed requests, database errors - and not frequency.
 *
 * LARGE FILES
 * --------------------
 * An error log that has run for a long time can become very large. Therefore only the END
 * of a file (MAX_BYTES) is ever read - the newest is at the back - and the first,
 * possibly cut-off line is dropped. truncated() says whether that has happened.
 *
 * THE FILE NAMES COME FROM THE DIRECTORY, NOT FROM THE REQUEST
 * --------------------
 * files() returns the existing files; everything that comes from outside (name, date)
 * is checked against this list before it yields a path. A path is assembled
 * only here, from checked components.
 *
 * @see Camindo\Core\System::log()                            writes the files
 * @see Camindo\Core\Modules\Logfiles\LogfilesBackend         UI and widget
 */
namespace Camindo\Core\Modules\Logfiles;

use Camindo\Core\Config;

class Logfiles {

    /** At most this end of each file is read (see class comment) */
    public const MAX_BYTES = 2097152;   // 2 MB

    /** Upper limit of entries per call of entries() */
    public const MAX_ENTRIES = 500;

    /** Entries per page of the search (LogfilesBackend::list(), paginated) */
    public const PAGE_SIZE = 100;

    /** Period of the widget in days */
    public const SUMMARY_DAYS = 7;

    /**
     * Categories in their ranking order (see class comment), each with the level for
     * the color display.
     */
    public const CATEGORIES = [
        'login'    => 'danger',    // blocked logins (LoginGuard)
        'security' => 'danger',    // tampered signatures etc. (Integrity)
        'mail'     => 'warning',   // unsent emails (Email)
        'php'      => 'danger',    // Fatal Error, Uncaught, Parse Error
        'database' => 'danger',    // SQL errors
        'job'      => 'warning',   // background processes: *-errors
        'warning'  => 'warning',   // PHP warnings and notices
        'info'     => 'secondary', // everything else (scheduler, upgrade, trash, sql …)
    ];

    /** Log files whose entries are always 'job' */
    private const JOB_LOGS = [
        'batch-errors', 'cron-errors', 'export-errors', 'file-errors', 'import-errors',
        'massdelete-errors', 'thumbnails-errors', 'widget-errors', 'template-sync',
    ];

    /** The last read() cut off at the beginning */
    private bool $truncated = false;

    public function __construct(
        private Config $config,
    )
    {}

    // ==== Files ====

    /**
     * Existing log files, newest first.
     *
     * @return array  ['2026-09-22_php_errors.log' => ['name' => 'php_errors',
     *                'date' => '2026-09-22', 'size' => int], …]
     */
    public function files(): array
    {
        $list = [];
        foreach ((array)glob($this->dir() . '/*.log') as $path) {
            $file = basename((string)$path);
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})_(.+)\.log$/', $file, $m)) continue;
            $list[$file] = [
                'name' => $m[2],
                'date' => $m[1],
                'size' => (int)filesize((string)$path),
            ];
        }
        // Newest first; for the same day by name
        uasort($list, fn(array $a, array $b): int => [$b['date'], $a['name']] <=> [$a['date'], $b['name']]);
        return $list;
    }

    /**
     * Occurring log names with the number of their files.
     *
     * @return array  'php_errors' => 5, …  (alphabetisch)
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->files() as $file) {
            $names[$file['name']] = ($names[$file['name']] ?? 0) + 1;
        }
        ksort($names);
        return $names;
    }

    /**
     * Occurring days, newest first.
     *
     * @return array  ['2026-09-22', …]
     */
    public function dates(): array
    {
        $dates = [];
        foreach ($this->files() as $file) $dates[$file['date']] = true;
        $dates = array_keys($dates);
        rsort($dates);
        return $dates;
    }

    // ==== Reading ====

    /**
     * Read and filter entries, the most recent first.
     *
     * Reading goes file by file in the order of files() (day descending)
     * and stops as soon as $limit is reached - with several logs of one
     * day, the order within the day is thus that of the file names, not
     * that of the times. Anyone who needs a timeline restricts it to one log.
     *
     * @param string $name    log name, '' = all
     * @param string $date    day (Y-m-d), '' = all
     * @param string $search  search text (substring, case-insensitive)
     * @param string $category category from CATEGORIES, '' = all
     * @param int    $limit   maximum number of entries
     * @param int    $skip    skip this many matching entries (paging)
     * @return array  ['file', 'name', 'date', 'time', 'text', 'category'] per entry
     */
    public function entries(string $name = '', string $date = '', string $search = '', string $category = '', int $limit = self::MAX_ENTRIES, int $skip = 0): array
    {
        $this->truncated = false;
        $limit = max(1, min($limit, self::MAX_ENTRIES));
        $search = trim($search);

        $entries = [];
        // files() is already sorted by date descending: read the newest files
        // first and stop as soon as there are enough
        foreach ($this->files() as $file => $info) {
            if ($name !== '' && $info['name'] !== $name) continue;
            if ($date !== '' && $info['date'] !== $date) continue;

            $fileEntries = $this->parse($file, $info);
            // Within a file, the newest is at the bottom
            $fileEntries = array_reverse($fileEntries);
            foreach ($fileEntries as $entry) {
                if ($category !== '' && $entry['category'] !== $category) continue;
                if ($search !== '' && stripos($entry['text'], $search) === false) continue;
                if ($skip > 0) {
                    $skip--;
                    continue;
                }
                $entries[] = $entry;
                if (count($entries) >= $limit) return $entries;
            }
        }
        return $entries;
    }

    /**
     * Summary for the widget: per category the number of entries of the
     * last $days days and the newest entry as an example.
     *
     * The ranking is that of CATEGORIES, not frequency (class comment).
     * 'info' stays out: these are the lines that occur anyway in normal operation
     * (every successful cron run), and a few hundred of them would
     * cover the three messages that matter. They are still there in the search.
     *
     * @return array  category => ['count' => int, 'level' => string,
     *                'latest' => ?array (entry)]
     */
    public function summary(int $days = self::SUMMARY_DAYS): array
    {
        $from = date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days'));

        $counts = [];
        foreach ($this->files() as $file => $info) {
            if ($info['date'] < $from) continue;   // files() is sorted descending
            foreach ($this->parse($file, $info) as $entry) {
                $category = $entry['category'];
                if ($category === 'info') continue;
                if (!isset($counts[$category])) {
                    $counts[$category] = ['count' => 0, 'level' => self::CATEGORIES[$category], 'latest' => null];
                }
                $counts[$category]['count']++;
                $latest = $counts[$category]['latest'];
                if ($latest === null || [$entry['date'], $entry['time']] >= [$latest['date'], $latest['time']]) {
                    $counts[$category]['latest'] = $entry;
                }
            }
        }

        // Put into the ranking of CATEGORIES
        $summary = [];
        foreach (self::CATEGORIES as $category => $level) {
            if (isset($counts[$category])) $summary[$category] = $counts[$category];
        }
        return $summary;
    }

    /**
     * Was the start of a file cut off during the last read (MAX_BYTES)?
     */
    public function truncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Category of an entry - see CATEGORIES.
     *
     * @param string $name  log name (file name without date)
     * @param string $text  text of the entry
     */
    public function classify(string $name, string $text): string
    {
        if ($name === 'security') {
            return str_contains($text, 'Login blocked') ? 'login' : 'security';
        }
        if ($name === 'mail') return 'mail';
        // Violations of the script policy of the backend (StartCspReport)
        if ($name === 'csp') return 'warning';
        if ($name === 'sql-errors') return 'database';
        if (in_array($name, self::JOB_LOGS, true)) return 'job';

        if ($name === 'php_errors') {
            // Database errors are still database errors when they end up as an
            // unhandled exception in the PHP log (System::handleException()
            // is not registered)
            if (stripos($text, 'mysqli') !== false || stripos($text, 'SQLSTATE') !== false) return 'database';
            if (preg_match('/\b(Fatal error|Parse error|Uncaught)\b/i', $text)) return 'php';
            if (preg_match('/\b(Warning|Notice|Deprecated)\b/i', $text)) return 'warning';
            return 'php';
        }
        return 'info';
    }

    // ====================

    /** Directory of the log files */
    private function dir(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/\\') . '/log';
    }

    /**
     * Split a file into entries (see class comment).
     *
     * @param string $file  file name from files() - already checked
     * @return array        entries in file order (oldest first)
     */
    private function parse(string $file, array $info): array
    {
        $content = $this->read($file, $info['size']);
        if ($content === '') return [];

        $entries = [];
        $current = null;
        foreach (explode("\n", $content) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '' && $current === null) continue;

            $time = $this->lineTime($line, $rest);
            if ($time !== null) {
                if ($current !== null) $entries[] = $current;
                $current = [
                    'file' => $file,
                    'name' => $info['name'],
                    'date' => $info['date'],
                    'time' => $time,
                    'text' => $rest,
                    'category' => '',
                ];
                continue;
            }
            // Continuation (stack trace) - append to the current entry
            if ($current !== null) $current['text'] .= "\n" . $line;
        }
        if ($current !== null) $entries[] = $current;

        foreach ($entries as $i => $entry) {
            $entries[$i]['category'] = $this->classify($entry['name'], $entry['text']);
        }
        return $entries;
    }

    /**
     * Does the line begin with one of the three timestamps (class comment)?
     *
     * @param ?string $rest  rest of the line without timestamp
     * @return ?string       time H:i:s, null if it is not the start of a line
     */
    private function lineTime(string $line, ?string &$rest): ?string
    {
        // [14:22:07] text
        if (preg_match('/^\[(\d{2}:\d{2}:\d{2})\]\s?(.*)$/s', $line, $m)) {
            $rest = $m[2];
            return $m[1];
        }
        // [22-Sep-2026 00:18:23 Europe/Berlin] text
        if (preg_match('/^\[\d{2}-[A-Za-z]{3}-\d{4} (\d{2}:\d{2}:\d{2})[^\]]*\]\s?(.*)$/s', $line, $m)) {
            $rest = $m[2];
            return $m[1];
        }
        // 2026-09-21 17:45:47 text
        if (preg_match('/^\d{4}-\d{2}-\d{2} (\d{2}:\d{2}:\d{2})\s?(.*)$/s', $line, $m)) {
            $rest = $m[2];
            return $m[1];
        }
        $rest = null;
        return null;
    }

    /**
     * Read the end of a file - at most MAX_BYTES (see class comment).
     *
     * @param string $file  file name from files()
     */
    private function read(string $file, int $size): string
    {
        $path = $this->dir() . '/' . $file;
        if (!is_file($path)) return '';

        if ($size <= self::MAX_BYTES) {
            return (string)file_get_contents($path);
        }

        $this->truncated = true;
        $handle = fopen($path, 'rb');
        if ($handle === false) return '';
        fseek($handle, -self::MAX_BYTES, SEEK_END);
        $content = (string)stream_get_contents($handle);
        fclose($handle);

        // The first line is cut off
        $pos = strpos($content, "\n");
        return $pos === false ? '' : substr($content, $pos + 1);
    }
}
