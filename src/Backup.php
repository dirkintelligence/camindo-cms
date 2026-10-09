<?php
/**
 * Datenbank-Sicherung mit mysqldump nach var/dbbackup.
 *
 * Zwei Aufrufer, ein Kommando:
 *
 *     SchedulerRunner (Aufgabentyp dbbackup)   rotierend: backup-1.sql ist das jüngste,
 *                                              rotate() rückt ältere um eins weiter
 *     camindo.php upgrade / component …        vor jedem Schritt, der das Schema ändert:
 *                                              {grund}-{zeitstempel}.sql, prune() räumt auf
 *
 * Das Passwort kommt aus paths.sqlpwd_file (--defaults-extra-file), nie auf die
 * Kommandozeile. Kommt db.pass aus der Umgebung (CAMINDO_DB_PASS, Config::fromEnv())
 * oder fehlt die Datei, schreibt mysqldump() für den einen Aufruf eine eigene
 * Optionsdatei (0600) unter paths.tmp_dir und löscht sie danach. mysqldump läuft über Execute mit Timeout; das Ergebnis wird erst
 * unter .tmp geschrieben, geprüft (verify()) und dann umbenannt, damit kein halber
 * oder unbrauchbarer Dump als Sicherung liegen bleibt.
 *
 * @see docs/developers/14-installation-und-update.md, 6.6
 */
namespace Camindo\Core;

class Backup {

    /** Kleinste brauchbare Sicherung in Bytes (siehe verify()) */
    public const MIN_DUMP_BYTES = 10240;

    /** Letzte Zeile jedes vollständigen Dumps (mysqldump und mariadb-dump) */
    public const DUMP_COMPLETED = '-- Dump completed';

    public function __construct(
        private Config $config,
        private System $system,
        private Execute $execute,
    )
    {
    }

    /**
     * Sicherungsverzeichnis var/dbbackup, wird bei Bedarf angelegt.
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
     * Dump nach var/dbbackup/{name}.sql schreiben.
     *
     * @param string $name     Dateiname ohne Endung, z. B. 'upgrade-9.0.0-9.1.0-20260917-140211'
     * @param int    $timeout  Sekunden, 0 = ohne Limit
     * @return string          absoluter Pfad der Datei
     */
    public function dump(string $name, int $timeout = 600): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        if ($name === '' || $name[0] === '.') throw new \Exception('invalid backup name');

        $file = $this->dir() . '/' . $name . '.sql';
        $target = $file . '.tmp';
        $this->mysqldump(['--single-transaction', '--routines', '--triggers', '--events'], $target, $timeout);
        // Ein Dump mit Exit-Code 0 kann trotzdem unbrauchbar sein (etwa nur eine
        // Fehlermeldung enthalten) - dann gilt er als fehlgeschlagen
        if (($error = $this->verify($target)) !== null) {
            @unlink($target);
            throw new \Exception('invalid dump: ' . $error);
        }
        if (!@rename($target, $file)) throw new \Exception('could not rename dump');
        @chmod($file, (int)($this->config->get('permissions.file') ?? 0664));
        return $file;
    }

    /**
     * Ist eine Sicherungsdatei brauchbar? Zwei Prüfungen:
     *
     *     Größe   unter MIN_DUMP_BYTES ist sie immer fehlgeschlagen - schon das
     *             leere Schema von camindo ist ein Vielfaches davon. So landet eine
     *             Datei, in der nur eine Fehlermeldung steht, nie als Sicherung.
     *     Ende    mysqldump und mariadb-dump schreiben als letzte Zeile
     *             "-- Dump completed on …". Fehlt sie, brach der Dump ab.
     *
     * @return string|null  null = in Ordnung, sonst der Grund (mit dem Anfang der
     *                      Datei, falls sie klein ist - dort steht meist der Fehler)
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
     * Sicherung vor einer Änderung: {reason}-{Ymd-His}.sql
     *
     * @return string  absoluter Pfad
     */
    public function beforeChange(string $reason, int $timeout = 600): string
    {
        return $this->dump($reason . '-' . date('Ymd-His'), $timeout);
    }

    /**
     * mysqldump mit beliebigen Zusatzargumenten in eine Datei schreiben - auch für
     * SchemaCommand (schema export). Host, Port, User, Passwortdatei und Datenbank
     * kommen aus der Config; Optionen aus $args stehen vor dem Datenbanknamen,
     * Tabellennamen (alles ohne --) dahinter.
     *
     * @param string[] $args    z. B. ['--no-data'] oder ['--no-create-info', 'status']
     * @param string   $target  Zieldatei (wird überschrieben)
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
     * Optionsdatei [client] mit dem Passwort, nur für den Eigentümer lesbar.
     * Anführungszeichen, die im Passwort nicht vorkommen; \ wird verdoppelt.
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
     * Rotierende Sicherung backup-1.sql … backup-{keep}.sql (Scheduler):
     * vorhandene rücken um eins, überzählige fallen weg, dann wird backup-1 geschrieben.
     *
     * @return string  absoluter Pfad von backup-1.sql
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
     * Änderungs-Sicherungen (alles außer backup-N.sql) auf die jüngsten $keep kürzen.
     *
     * @return string[]  gelöschte Dateinamen
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
     * Vorhandene Sicherungen: [name => ['file', 'size', 'mtime']], jüngste zuerst.
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
