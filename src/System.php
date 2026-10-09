<?php
/**
 * System-level services: logging, creating files and directories, small helpers.
 * Instance name in the code: $system.
 *
 * Collection point for everything that has to do with the file system or the runtime
 * state and belongs to none of the domain classes. Its only dependency is the
 * Config - which makes it usable everywhere, even very early in the bootstrap.
 *
 * WRITE ACCESS ALWAYS WITH A BASE DIRECTORY
 * --------------------
 * createDir() and filePutContents() always take the target path in two parts:
 * a $baseDir, which must exist, and a $relativePath below it.
 *
 *     $system->filePutContents($varDir, 'log/2026-09-03_sql.log', $text, append: true);
 *
 * This is not a question of style but of protection: the relative part comes from
 * user input in some calls, and the separation makes it possible to normalize it and
 * to prevent escaping from the base directory. Both methods create missing
 * intermediate directories and set the permissions from permissions.dir / permissions.file.
 *
 * LOGS
 * --------------------
 * log($name, $text) writes to var/log/{Y-m-d}_{name}.log, one file per day and
 * purpose. In use:
 *   'sql'         with system.debugsql + system.development_version,
 *   'sql-errors'  SQL errors
 *   'cron-errors' errors
 *   'security' (too many failed sign-ins)
 * Separate from this is the PHP error log, which Config redirects in the constructor to
 * var/log/{Y-m-d}_php_errors.log.
 *
 * EXCEPTION HANDLER
 * --------------------
 * handleException() is meant as a global handler; its registration in the constructor
 * is currently commented out - so uncaught exceptions go to the standard PHP handling.
 * If the handler is enabled, it treats only database errors specially (log them, output
 * them in development mode, exit); everything else it rethrows unchanged.
 */
namespace Camindo\Core;

class System {

    public function __construct(
        private Config $config
    )
    {
        // Catch exceptions
        // Commented out: as long as the line is disabled, handleException()
        // is never called automatically (see class comment).
        // set_exception_handler([$this, 'handleException']);
    }
    
    /**
     * Catch exceptions that were not handled by a catch
     *
     * Database errors are logged and end the request - in development mode
     * (system.development_version) additionally with plain-text output.
     * All other exceptions are rethrown unchanged, so that they are not
     * swallowed silently.
     *
     * Currently not registered as a handler, see constructor.
     */
    public function handleException(\Throwable $e): void {

        if ($e instanceof \mysqli_sql_exception) {
            if ($this->config->get('system.development_version')) {
                // Debug output
                echo "Database exception: " . $e->getMessage();
            }
            // Log
            $this->log('sql-errors', $e->getMessage());
            exit;
        }

        // All other errors are rethrown unchanged!
        throw $e;
    }

    /**
     * Write to a log file, e.g. cms_errors
     * @param string $logName  log file name (extended by date and extension)
     * @param string $logText  content
     */
    public function log(string $logName, string $logText): void {
        // Build the path to the file
        $varDir = $this->config->get('paths.var_dir');
        $date = date('Y-m-d');
        $file = $date . '_' . $logName . '.log';
        $relativePath = 'log/' . $file;

        // Build the line
        $time = date('H:i:s');
        $logEntry = "[$time] " . $logText . PHP_EOL;

        // Write with flag FILE_APPEND
        $this->filePutContents($varDir, $relativePath, $logEntry, append: true);
    }

    /**
     * Create a directory (recursively) inside baseDir
     * and set permissions according to the configuration
     * @param string $baseDir       base directory (must exist)
     * @param string $relativePath  relative path to the base directory
     * @return bool                 true on success
     */
    
    public function createDir(string $baseDir, string $relativePath): bool
    {
        // Determine the desired permissions
        $permissions = $this->config->get('permissions.dir') ?? 0775;

        // Clean up and normalize paths
        $baseDir = rtrim($baseDir, '/\\');
        $relativePath = trim(
            str_replace('\\', '/', $relativePath),
            '/'
        );

        if ($relativePath === '') {
            return is_dir($baseDir);
        }

        $parts = explode('/', $relativePath);
        $currentPath = $baseDir;

        foreach ($parts as $part) {
            $currentPath .= '/' . $part;

            // Check whether the directory already exists at this level
            if (!is_dir($currentPath)) {
                
                // Create the directory.
                // The additional is_dir() check prevents errors caused by race conditions
                // (if another process creates the directory within the same millisecond fraction).
                if (!mkdir($currentPath) && !is_dir($currentPath)) {
                    return false; 
                }

                // Set permissions explicitly, independent of the server umask
                if (!chmod($currentPath, $permissions)) {
                    return false; 
                }
            }
        }

        return true;
    }

    /**
     * Write a file
     *
     * @param string $baseDir       base directory
     * @param string $relativePath  relative path to the base directory, incl. file name
     * @param string $content       content
     * @param bool $append          true: content is appended, false: overwrites an existing file
     * @return bool                 true on success
     */
    public function filePutContents(string $baseDir, string $relativePath, string $content, bool $append = false, bool $lock = false): bool
    {
        // Clean up and normalize paths
        $baseDir = rtrim($baseDir, '/\\');
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        // Separate the relative directory path from the actual file
        $relativeDir = dirname($relativePath);

        // dirname() returns '.' if the path contains no slash (file lies directly in baseDir)
        if ($relativeDir !== '.' && $relativeDir !== '') {
            // Make sure the directory structure exists
            if (!$this->createDir($baseDir, $relativeDir)) {
                return false;
            }
        }

        $fullPath = $baseDir . '/' . $relativePath;

        // Flags
        $flags = 0;
        if ($append) {
            $flags |= FILE_APPEND;
        }
        if ($lock) {
            $flags |= LOCK_EX;
        }

        // file_put_contents() returns the number of bytes written on success, false on error.
        $writeResult = file_put_contents($fullPath, $content, $flags);

        if ($writeResult === false) {
            return false;
        }

        // Determine permissions
        $filePermissions = $this->config->get('permissions.file') ?? 0664;

        // Set file permissions explicitly to get around umask restrictions.
        chmod($fullPath, $filePermissions);

        return true;
    }

    /**
     * Sanitizes several integers, e.g. for an IN() statement in the database;
     * returns real int values as an array
     *
     * @param array|string|int $ids   integers
     * @param bool $removeZero        true: remove the 0 value
     * @return array                  [] is also possible
     *
     * Needed where lists of ids must go into an IN (...) - placeholders cannot be bound
     * there. intval() + array_filter() ensure that only numbers get into the SQL
     * string. Negative values always drop out, the 0 only with
     * $removeZero (in some places it is a valid value, e.g. status_id).
     * Duplicate entries are removed, the keys are renumbered.
     */
    public function sanitizeIds(array|string|int $ids, bool $removeZero = false): array {
        if (!is_array($ids)) {
            $ids = is_int($ids) ? [$ids] : explode(',', $ids);
        }
        $ids = array_unique(
            array_filter(
                array_map('intval', $ids),
                fn($id) => $removeZero ? $id > 0 : $id >= 0
            )
        );
        return array_values($ids);
    }


    /**
     * Converts byte values into a readable format (e.g. "1.5 MB" or "1.5 MiB").
     *
     * @param float|int $bytes  the number of bytes to convert
     * @param int       $factor 1000 for decimal prefixes (SI), 1024 for binary prefixes (IEC)
     * @param int       $decimals number of decimal places (default: 1)
     * @return string
     */
    public function convertSize(float $bytes, int $factor = 1000, int $decimals = 1): string 
    {
        $nbsp = "\u{00A0}"; // &nbsp;
        if ($bytes <= 0) {
            return "0{$nbsp}Bytes";
        }

        $factor = ($factor === 1024) ? 1024 : 1000;
        
        $units = ($factor === 1000) 
            ? ['Bytes', 'kB', 'MB', 'GB', 'TB', 'PB'] 
            : ['Bytes', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];

        // Calculate the matching prefix index from the logarithm
        $i = (int) floor(log($bytes, $factor));
        $i = min($i, count($units) - 1); // Do not exceed the largest available unit

        if ($i === 0) {
            return (int) $bytes . "{$nbsp}Bytes";
        }

        $value = $bytes / pow($factor, $i);
        $decimalSep = localeconv()['decimal_point'] ?? '.';
        $formatted  = number_format($value, $decimals, $decimalSep, '');

        // Strip superfluous zeros after the decimal point (e.g. 1.0 -> 1)
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted . $nbsp . $units[$i];
    }

    /**
     * Send a 404 status.
     *
     * Only sets the header, outputs no content and does not end the request -
     * the caller has to take care of that (cf. RecordBackendServerFile::filePreview(),
     * where every call is followed by a return).
     *
     * Not used in the frontend yet: Page::resolveRequest() ends with
     * die() on all errors and then returns status 200.
     */
    public function error404() {
        header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
    }
}
