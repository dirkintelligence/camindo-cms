<?php
/**
 * Database backup with mysqldump to var/dbbackup.
 *
 * Two callers, one command:
 *
 *     SchedulerRunner (task type dbbackup)     rotating: backup-1.sql is the newest,
 *                                              rotate() shifts older ones up by one
 *     camindo.php upgrade / component …        before each step that changes the schema:
 *                                              {reason}-{timestamp}.sql, prune() cleans up
 *
 * The password comes from paths.sqlpwd_file (--defaults-extra-file), never from the
 * command line. If db.pass comes from the environment (CAMINDO_DB_PASS, Config::fromEnv())
 * or the file is missing, mysqldump() writes its own option file (0600) for this one call
 * under paths.tmp_dir and deletes it afterwards. mysqldump runs via Execute with a timeout; the result is first
 * written under .tmp, checked (verify()) and then renamed, so that no half-written
 * or unusable dump is left behind as a backup.
 *
 * @see developer docs, chapter 09 (Installation and update)
 */
namespace Camindo\Core;

class Backup {

    /** Smallest usable backup in bytes (see verify()) */
    public const MIN_DUMP_BYTES = 10240;

    /** Last line of every complete dump (mysqldump and mariadb-dump) */
    public const DUMP_COMPLETED = '-- Dump completed';

    public function __construct(
        private Config $config,
        private System $system,
        private Execute $execute,
    )
    {
    }

    /**
     * Backup directory var/dbbackup, created if needed.
     */
    public function dir(): string
    {
        $varDir = rtrim((string)$this->config->get('paths.var_dir'), '/');
        $dir = $varDir . '/dbbackup';
        if (!is_dir($dir) && !$this->system->createDir($varDir, 'dbbackup')) {
            throw new \Exception('var/dbbackup not writable');
        }
        return $dir;
    }

    /**
     * Write the dump to var/dbbackup/{name}.sql.
     *
     * @param string $name     File name without extension, e.g. 'upgrade-9.0.0-9.1.0-20260917-140211'
     * @param int    $timeout  Seconds, 0 = no limit
     * @return string          absolute path of the file
     */
    public function dump(string $name, int $timeout = 600): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        if ($name === '' || $name[0] === '.') throw new \Exception('invalid backup name');

        $file = $this->dir() . '/' . $name . '.sql';
        $target = $file . '.tmp';
        $this->mysqldump(['--single-transaction', '--routines', '--triggers', '--events'], $target, $timeout);
        // A dump with exit code 0 can still be unusable (e.g. containing only an
        // error message) - then it counts as failed
        if (($error = $this->verify($target)) !== null) {
            @unlink($target);
            throw new \Exception('invalid dump: ' . $error);
        }
        if (!@rename($target, $file)) throw new \Exception('could not rename dump');
        @chmod($file, (int)($this->config->get('permissions.file') ?? 0664));
        return $file;
    }

    /**
     * Is a backup file usable? Two checks:
     *
     *     Size    below MIN_DUMP_BYTES it always counts as failed - even the
     *             empty schema of camindo is many times that. So a file that contains
     *             only an error message never ends up as a backup.
     *     End     mysqldump and mariadb-dump write "-- Dump completed on …" as the
     *             last line. If it is missing, the dump was aborted.
     *
     * @return string|null  null = OK, otherwise the reason (with the start of the
     *                      file if it is small - the error is usually there)
     */
    public function verify(string $file): ?string
    {
        if (!is_file($file)) return 'file missing';
        $size = (int)filesize($file);
        if ($size < self::MIN_DUMP_BYTES) {
            $head = trim(preg_replace('/\s+/', ' ', (string)file_get_contents($file, false, null, 0, 300)) ?? '');
            return "only $size bytes" . ($head !== '' ? ': ' . $head : '');
        }
        $tail = (string)file_get_contents($file, false, null, max(0, $size - 1024));
        if (!str_contains($tail, self::DUMP_COMPLETED)) {
            return 'incomplete, "' . self::DUMP_COMPLETED . '" missing at the end';
        }
        return null;
    }

    /**
     * Backup before a change: {reason}-{Ymd-His}.sql
     *
     * @return string  absolute path
     */
    public function beforeChange(string $reason, int $timeout = 600): string
    {
        return $this->dump($reason . '-' . date('Ymd-His'), $timeout);
    }

    /**
     * Write mysqldump with arbitrary extra arguments to a file - also for
     * SchemaCommand (schema export). Host, port, user, password file and database
     * come from the config; options from $args come before the database name,
     * table names (everything without --) after it.
     *
     * @param string[] $args    e.g. ['--no-data'] or ['--no-create-info', 'status']
     * @param string   $target  target file (will be overwritten)
     */
    public function mysqldump(array $args, string $target, int $timeout = 600): void
    {
        $sqlpwd = (string)$this->config->get('paths.sqlpwd_file');
        $tempFile = null;
        if (in_array('db.pass', $this->config->fromEnv(), true) || !is_file($sqlpwd)) {
            $sqlpwd = $tempFile = $this->writeOptionFile((string)$this->config->get('db.pass'));
        }
        try {
            $this->runMysqldump($sqlpwd, $args, $target, $timeout);
        }
        finally {
            if ($tempFile !== null) @unlink($tempFile);
        }
    }

    /**
     * Option file [client] with the password, readable by the owner only.
     * Quotes that do not occur in the password; \ is doubled.
     */
    private function writeOptionFile(string $password): string
    {
        $dir = rtrim((string)$this->config->get('paths.tmp_dir'), '/');
        $old = umask(0077);
        try {
            $file = tempnam($dir, 'mysqldump-');
            if ($file === false) throw new \Exception('cannot create option file in ' . $dir);
            $quote = !str_contains($password, '"') ? '"' : (!str_contains($password, "'") ? "'" : '');
            $value = $quote . str_replace('\\', '\\\\', $password) . $quote;
            if (file_put_contents($file, "[client]\npassword=" . $value . "\n") === false) {
                @unlink($file);
                throw new \Exception('cannot write option file ' . $file);
            }
            @chmod($file, 0600);
            return $file;
        }
        finally {
            umask($old);
        }
    }

    private function runMysqldump(string $sqlpwd, array $args, string $target, int $timeout): void
    {
        $options = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
        $tables  = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
        $command = array_merge([
            (string)($this->config->get('paths.mysqldump') ?: 'mysqldump'),
            '--defaults-extra-file=' . $sqlpwd,
            '--host=' . (string)$this->config->get('db.host'),
            '--port=' . (int)($this->config->get('db.port') ?: 3306),
            '--user=' . (string)$this->config->get('db.user'),
            '--result-file=' . $target,
        ], $options, [(string)$this->config->get('db.name')], $tables);
        $stdout = '';
        $stderr = '';
        $exitCode = $this->execute->execute($command, $stdout, $stderr, $timeout);
        if ($exitCode !== 0 || !is_file($target)) {
            @unlink($target);
            if ($exitCode === Execute::EXEC_TIMEOUT) throw new \Exception("mysqldump timeout after $timeout s");
            throw new \Exception('mysqldump failed (' . $exitCode . '): ' . trim($stderr));
        }
    }

    /**
     * Rotating backup backup-1.sql … backup-{keep}.sql (scheduler):
     * existing ones move up by one, surplus ones are dropped, then backup-1 is written.
     *
     * @return string  absolute path of backup-1.sql
     */
    public function rotate(int $keep, int $timeout = 600): string
    {
        $dir = $this->dir();
        $file = fn(int $n) => "$dir/backup-$n.sql";
        for ($n = 200; $n >= 1; $n--) {
            if (!is_file($file($n))) continue;
            if ($n + 1 > $keep) @unlink($file($n));
            else @rename($file($n), $file($n + 1));
        }
        return $this->dump('backup-1', $timeout);
    }

    /**
     * Trim the change backups (everything except backup-N.sql) to the newest $keep.
     *
     * @return string[]  deleted file names
     */
    public function prune(int $keep): array
    {
        $files = [];
        foreach (glob($this->dir() . '/*.sql') ?: [] as $f) {
            if (preg_match('/^backup-\d+\.sql$/', basename($f))) continue;
            $files[(int)filemtime($f) . '-' . basename($f)] = $f;
        }
        krsort($files, SORT_STRING);
        $deleted = [];
        foreach (array_slice($files, max(0, $keep)) as $f) {
            if (@unlink($f)) $deleted[] = basename($f);
        }
        return $deleted;
    }

    /**
     * Existing backups: [name => ['file', 'size', 'mtime']], newest first.
     */
    public function list(): array
    {
        $list = [];
        foreach (glob($this->dir() . '/*.sql') ?: [] as $f) {
            $list[basename($f)] = ['file' => $f, 'size' => (int)filesize($f), 'mtime' => (int)filemtime($f)];
        }
        uasort($list, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $list;
    }
}
