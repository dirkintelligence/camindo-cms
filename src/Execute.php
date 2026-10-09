<?php
/**
 * class Execute
 * 
 * Stellt eine robuste und sichere Methode zur Ausführung von CLI-Kommandos zur Verfügung.
 * Dieser Trait ist besonders für die Interaktion mit externen Tools (wie ImageMagick) 
 * optimiert und löst typische Probleme, die bei der Nutzung von `proc_open()` auftreten können.
 * 
 * Hauptmerkmale:
 * - Sicherheit: Kommandos werden als Array übergeben, wodurch Shell-Injection vermieden wird.
 * - Deadlock-Prävention: STDOUT und STDERR werden in temporäre Dateien umgeleitet. Das verhindert
 *   ein Blockieren des Prozesses (Deadlocks), wenn die Ausgabepuffer des Betriebssystems voll sind, 
 *   was auf Windows-Systemen bei fehlendem `stream_select()` oft passiert.
 * - Timeout-Management: Überwacht die Ausführungszeit aktiv. Bei Überschreitung wird der Prozess 
 *   zunächst regulär beendet (SIGTERM) und nach einer Nachfrist hart abgebrochen (SIGKILL).
 * - Speicherschutz: Die maximale Größe der eingelesenen Ausgaben (STDOUT/STDERR) ist limitiert,
 *   um Memory Exhaustion durch extrem lange Ausgaben (z. B. durch `-verbose`) zu verhindern.
 * - Encoding-Korrektur: Stellt sicher, dass die Fehlerausgabe im Backend immer als UTF-8 vorliegt
 *   (besonders relevant für Windows-Kommandozeilen).
 * - Hintergrundprozesse: executeBackground() startet ein Kommando abgekoppelt vom PHP-Prozess
 *   und liefert dessen PID zurück, processState() und terminateProcess() überwachen und beenden es.
 * - Token-Abgleich: Weil das Betriebssystem PIDs wiederverwendet (auf Shared Hosting oft schon
 *   nach Minuten), kann jedem Hintergrundprozess ein Token mitgegeben werden. Es steht in der
 *   Kommandozeile des Prozesses und wird bei der Überwachung wieder daraus gelesen. Passt es
 *   nicht, gehört die PID inzwischen einem fremden Prozess und gilt als beendet.
 */
namespace Camindo\Core;

class Execute {

    /**
     * Rückgabewerte von execute(), die kein Exit-Code eines Prozesses sind
     * (public, weil BackgroundProcess sie auswertet)
     */
    public const EXEC_FAILED  = -1; // Prozess konnte nicht gestartet werden
    public const EXEC_TIMEOUT = -2; // Zeitüberschreitung, Prozess wurde abgebrochen

    /**
     * Zustände eines Prozesses, wie sie processState() / processStates() liefern.
     * Die Werte 0/1/2 sind absichtlich identisch mit der Spalte `status` in `bgproc`.
     */
    public const STATE_UNKNOWN  = -1; // nicht ermittelbar (weder ps/tasklist noch posix_kill verfügbar)
    public const STATE_FINISHED = 0;  // Prozess existiert nicht mehr
    public const STATE_RUNNING  = 1;  // Prozess läuft
    public const STATE_ZOMBIE   = 2;  // beendet, aber vom Elternprozess noch nicht eingesammelt

    /**
     * Obergrenze für die eingelesenen Ausgaben.
     * "-verbose" oder eine defekte Quelldatei können sonst Megabytes erzeugen,
     * die über 'error_details' bis in die Datenbank wandern.
     */
    private const MAX_STDOUT = 262144; // 256 KiB
    private const MAX_STDERR = 65536;  //  64 KiB

    public function __construct(
        private Config $config
    )
    {
    }

    /**
     * Zerlegt eine frei eingegebene Parameterzeile in einzelne Argumente.
     * Anführungszeichen (einfach und doppelt) halten Werte mit Leerzeichen zusammen.
     *
     * Arbeitet rein auf Bytes und ist damit unabhängig von Betriebssystem,
     * Locale und Zeichenkodierung (UTF-8 inklusive).
     *
     * @return array Liste einzelner Argumente
     */
    public function tokenizeParameters(string $parameters): array
    {
        $tokens  = [];
        $current = '';
        $quote   = '';
        $started = false;
        $length  = strlen($parameters);

        for ($i = 0; $i < $length; $i++) {
            $char = $parameters[$i];

            if ($quote !== '') {
                if ($char === $quote) {
                    $quote = '';
                    continue;
                }
                // Innerhalb doppelter Anführungszeichen ist der Backslash nur vor
                // " und \ ein Escape. Sonst bleibt er stehen, damit Windows-Pfade
                // wie "C:\temp\bild.png" nicht zerstört werden.
                if ($char === '\\' && $quote === '"' && $i + 1 < $length
                    && ($parameters[$i + 1] === '"' || $parameters[$i + 1] === '\\')) {
                    $i++;
                    $current .= $parameters[$i];
                    continue;
                }
                $current .= $char;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote   = $char;
                $started = true; // ermöglicht auch leere Argumente ("")
                continue;
            }

            if (ctype_space($char)) {
                if ($started) {
                    $tokens[] = $current;
                    $current  = '';
                    $started  = false;
                }
                continue;
            }

            $current .= $char;
            $started  = true;
        }

        if ($started) $tokens[] = $current;

        return $tokens;
    }

    /**
     * Führt ein Kommando ohne Shell aus (proc_open mit Argument-Array),
     * dadurch sind Sonderzeichen in Datei- und Parameterwerten unkritisch.
     *
     * stdout und stderr laufen in temporäre Dateien statt in Pipes. Das
     * verhindert Deadlocks bei großen Ausgaben und kommt ohne stream_select()
     * aus, das unter Windows auf Prozess-Pipes nicht arbeitet.
     *
     * @param array       $command Kommando inklusive aller Argumente
     * @param string|null $stdout  Ausgabe
     * @param string|null $stderr  Fehlerausgabe, im Fehlerfall auch der Grund von PHP
     * @param int         $timeout Sekunden bis zum Abbruch, 0 = kein Abbruch
     * @param array       $env     zusätzliche Umgebungsvariablen des Kindprozesses
     *                             (zur eigenen Umgebung dazu), z. B. CAMINDO_API_SESSION
     * @return int                 Exit-Code, EXEC_FAILED oder EXEC_TIMEOUT
     */
    public function execute(array $command, ?string &$stdout = null, ?string &$stderr = null, int $timeout = 0, array $env = []): int
    {
        $stdout = '';
        $stderr = '';

        $outFile = @tmpfile();
        $errFile = @tmpfile();
        // tmpfile() kann z. B. bei restriktivem open_basedir oder nicht
        // beschreibbarem Temp-Verzeichnis fehlschlagen
        $useTempFiles = ($outFile !== false && $errFile !== false);

        if ($useTempFiles) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => $outFile,
                2 => $errFile,
            ];
        } else {
            if ($outFile !== false) { fclose($outFile); $outFile = false; }
            if ($errFile !== false) { fclose($errFile); $errFile = false; }
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
        }

        // Grund eines Fehlschlags festhalten, statt ihn mit @ zu verschlucken:
        // "Datei nicht gefunden" und "keine Ausführungsrechte" sind die
        // häufigsten Stolpersteine bei der Einrichtung.
        $lastError = null;
        set_error_handler(function (int $errno, string $message) use (&$lastError): bool {
            $lastError = $message;
            return true;
        });
        $process = proc_open($command, $descriptors, $pipes, null, $env ? array_merge(getenv(), $env) : null);
        restore_error_handler();

        if (!is_resource($process)) {
            $stderr = $lastError ?? 'proc_open() failed';
            if ($outFile !== false) fclose($outFile);
            if ($errFile !== false) fclose($errFile);
            return self::EXEC_FAILED;
        }

        fclose($pipes[0]); // stdin schließen -> Kindprozess bekommt sofort EOF

        if (!$useTempFiles) {
            // Notlösung ohne Timeout-Überwachung: die Pipes müssen gelesen
            // werden, solange der Prozess läuft. Praktisch nur für die kurzen
            // Ausgaben von "identify" relevant.
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $stderr = $this->toUtf8($stderr);
            return proc_close($process);
        }

        $exitCode = null;

        if ($timeout > 0) {
            $deadline   = microtime(true) + $timeout;
            $terminated = false;

            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    // Der Exit-Code muss hier abgegriffen werden, nach dem
                    // Einsammeln des Prozesses liefert proc_close() ihn nicht
                    // mehr zuverlässig.
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $deadline) {
                    if (!$terminated) {
                        proc_terminate($process);          // POSIX: SIGTERM, Windows: hart
                        $terminated = true;
                        $deadline   = microtime(true) + 5; // Nachfrist zum Aufräumen
                        continue;
                    }
                    proc_terminate($process, 9);           // SIGKILL
                    $exitCode = self::EXEC_TIMEOUT;
                    break;
                }
                usleep(50000); // 50 ms
            }
        }

        $closeCode = proc_close($process);
        if ($exitCode === null) $exitCode = $closeCode;

        // PHP austricksen: Zeiger ans Ende setzen, damit die intern gecachte Dateigröße
        // synchronisiert wird, danach mit rewind() zwingend wieder an den Anfang.
        fseek($outFile, 0, SEEK_END);
        rewind($outFile);
        $stdout = (string) stream_get_contents($outFile, self::MAX_STDOUT);

        fseek($errFile, 0, SEEK_END);
        rewind($errFile);
        $stderr = (string) stream_get_contents($errFile, self::MAX_STDERR);

        fclose($outFile); // tmpfile() löscht sich beim Schließen selbst
        fclose($errFile);

        $stderr = $this->toUtf8($stderr);

        return $exitCode;
    }

    // ========== Hintergrundprozesse ==========

    /**
     * Startet ein Kommando als eigenständigen Hintergrundprozess und liefert dessen PID.
     *
     * Der Prozess ist vom PHP-Prozess abgekoppelt: er läuft weiter, wenn das Skript endet,
     * hält keine Handles des Webservers offen (stdin/stdout/stderr werden umgeleitet) und
     * hinterlässt keinen Zombie, weil er nach dem sofortigen Ende der Zwischenschale von
     * init/PID 1 übernommen wird.
     *
     * Bewusst NICHT über proc_open() ohne Shell: der Ressourcen-Destruktor von proc_open()
     * wartet am Skriptende auf das Kind, der Prozess wäre also gar nicht im Hintergrund.
     *
     * POSIX  : sh -c 'nohup KOMMANDO </dev/null >out 2>err & echo $!'
     *          nohup ersetzt sich per exec() selbst durch das Zielprogramm, deshalb ist
     *          die von $! gemeldete PID auch die PID des Zielprogramms.
     * Windows: powershell Start-Process -PassThru, dessen .Id die PID ist.
     *          (kein COM/WMI nötig, PowerShell ist ab Windows 7 fester Bestandteil)
     *
     * Achtung: Ein Fehlschlag des Zielprogramms selbst (z. B. "not found") ist an dieser
     * Stelle unter POSIX nicht erkennbar, da die Shell die PID sofort meldet. Dafür ist
     * $stderrFile gedacht.
     *
     * @param array       $command    Kommando inklusive aller Argumente
     * @param string|null $stdoutFile Datei für die Ausgabe, null = verwerfen
     * @param string|null $stderrFile Datei für die Fehlerausgabe, null = verwerfen
     * @param string|null $error      Grund eines Fehlschlags
     * @return int                    PID des Prozesses oder EXEC_FAILED
     */
    public function executeBackground(array $command, ?string $stdoutFile = null, ?string $stderrFile = null, ?string &$error = null): int
    {
        $error   = '';
        $command = array_values(array_map('strval', $command));

        if ($command === []) {
            $error = 'empty command';
            return self::EXEC_FAILED;
        }
        // Beide Ströme in dieselbe Datei umzuleiten lehnt Start-Process ab,
        // deshalb der Einheitlichkeit halber auf beiden Plattformen verboten.
        if ($stdoutFile !== null && $stdoutFile === $stderrFile) {
            $error = 'stdout and stderr must not point to the same file';
            return self::EXEC_FAILED;
        }

        return $this->isWindows()
            ? $this->startWindows($command, $stdoutFile, $stderrFile, $error)
            : $this->startPosix($command, $stdoutFile, $stderrFile, $error);
    }

    /**
     * Ermittelt den Zustand eines einzelnen Prozesses.
     *
     * @param  int         $pid
     * @param  string|null $token Token aus dem Start, null = ohne Abgleich
     * @return int         STATE_FINISHED, STATE_RUNNING, STATE_ZOMBIE oder STATE_UNKNOWN
     */
    public function processState(int $pid, ?string $token = null): int
    {
        if ($pid <= 0) return self::STATE_FINISHED;

        $tokens = ($token !== null && $token !== '') ? [$pid => $token] : [];
        $states = $this->processStates([$pid], $tokens);

        return $states[$pid] ?? self::STATE_UNKNOWN;
    }

    /**
     * Ermittelt den Zustand mehrerer Prozesse mit einem einzigen Aufruf von
     * ps bzw. tasklist/PowerShell. Für Übersichtslisten deutlich sparsamer als processState().
     *
     * Ist zu einer PID ein Token angegeben, wird zusätzlich die Kommandozeile des Prozesses
     * gelesen. Kommt das Token darin nicht vor, wurde die PID inzwischen neu vergeben und der
     * eigene Prozess gilt als beendet (STATE_FINISHED). Genau das ist auf Shared Hosting mit
     * hoher PID-Fluktuation der Fall, den man sonst als "läuft noch" fehlinterpretiert.
     *
     * Ohne Token bleibt es beim reinen Existenztest, dann gilt weiterhin: "läuft" heißt nur
     * "es gibt einen Prozess mit dieser PID".
     *
     * @param  array $pids   Liste von PIDs
     * @param  array $tokens PID => Token, nur für die hier genannten PIDs wird abgeglichen
     * @return array         PID => STATE_*
     */
    public function processStates(array $pids, array $tokens = []): array
    {
        $pids = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $pids), fn(int $pid): bool => $pid > 0
                )
            )
        );
        if ($pids === []) return [];

        // Tokens normalisieren: nur PIDs aus der Liste, keine leeren Tokens
        $check = [];
        foreach ($tokens as $pid => $token) {
            $pid   = (int) $pid;
            $token = (string) $token;
            if ($pid > 0 && $token !== '') $check[$pid] = $token;
        }

        // Grundannahme: nicht gefunden = beendet
        $states = array_fill_keys($pids, self::STATE_FINISHED);

        if ($this->isWindows()) {
            if ($check !== []) {
                // tasklist kennt die Kommandozeile nicht, dafür ist WMI/CIM nötig.
                $commands = $this->commandLinesWindows($pids);
                if ($commands !== null) {
                    foreach ($commands as $pid => $commandLine) {
                        if (!isset($states[$pid])) continue;
                        $states[$pid] = $this->matchToken($commandLine, $check[$pid] ?? '')
                            ? self::STATE_RUNNING
                            : self::STATE_FINISHED;
                    }
                    return $states;
                }
                // PowerShell nicht verfügbar: lieber ohne Token-Abgleich weiter über
                // tasklist als gar keine Aussage.
            }

            // Eine einzige Abfrage der gesamten Prozessliste ist billiger als ein
            // tasklist-Aufruf mit /FI je PID. Zombies kennt Windows nicht.
            $code = $this->execute(['tasklist', '/NH', '/FO', 'CSV'], $stdout, $stderr, 20);
            if ($code !== 0) return array_fill_keys($pids, self::STATE_UNKNOWN);

            foreach (preg_split('/\r?\n/', $stdout) as $line) {
                // Format: "programm.exe","1234","Services","0","1.234 K"
                if (!preg_match('/^"[^"]*","(\d+)"/', $line, $match)) continue;
                $pid = (int) $match[1];
                if (isset($states[$pid])) $states[$pid] = self::STATE_RUNNING;
            }

            return $states;
        }

        // "-o feld=" getrennt angeben: BSD/macOS würde bei "-o pid=,state="
        // alles nach dem ersten "=" als Spaltenüberschrift interpretieren.
        $arguments = ['ps'];
        if ($check !== []) {
            // -ww hebt die Zeilenbreite auf. Ohne tty kürzt ps sonst auf 80 Zeichen,
            // ein am Ende stehendes Token wäre dann abgeschnitten.
            $arguments[] = '-ww';
        }
        array_push($arguments, '-o', 'pid=', '-o', 'state=');
        if ($check !== []) array_push($arguments, '-o', 'args=');
        array_push($arguments, '-p', implode(',', $pids));

        $code = $this->execute($arguments, $stdout, $stderr, 20);

        // Exit-Code 1 heißt bei ps lediglich "keine der PIDs gefunden".
        // Alles andere (fehlendes ps, Timeout, unbekannte Option bei abgespeckten
        // ps-Varianten) darf nicht als "alle beendet" durchgehen.
        if ($code !== 0 && $code !== 1) {
            return $this->processStatesPosixFallback($pids);
        }

        foreach (preg_split('/\r?\n/', $stdout) as $line) {
            if (!preg_match('/^\s*(\d+)\s+(\S+)\s*(.*)$/', $line, $match)) continue;
            $pid = (int) $match[1];
            if (!isset($states[$pid])) continue;

            if ($match[2][0] === 'Z') {
                // Bei einem Zombie zeigt ps nur noch "<defunct>", das Token ist
                // dort naturgemäß verschwunden - deshalb kein Abgleich.
                $states[$pid] = self::STATE_ZOMBIE;
                continue;
            }

            $states[$pid] = $this->matchToken($match[3], $check[$pid] ?? '')
                ? self::STATE_RUNNING
                : self::STATE_FINISHED;
        }

        return $states;
    }

    /**
     * Beendet einen Prozess.
     *
     * Ist ein Token angegeben, wird vorher geprüft, ob die PID überhaupt noch zum eigenen
     * Prozess gehört. Ohne diese Prüfung könnte nach einer PID-Wiederverwendung ein
     * fremder Prozess abgeschossen werden.
     *
     * @param int         $pid
     * @param bool        $force true = SIGKILL bzw. taskkill /F, false = SIGTERM
     * @param string|null $token Token aus dem Start, null = ohne Abgleich
     * @return bool
     */
    public function terminateProcess(int $pid, bool $force = false, ?string $token = null): bool
    {
        if ($pid <= 1) return false; // PID 1 und ungültige Werte niemals anfassen

        if ($token !== null && $token !== '') {
            $state = $this->processState($pid, $token);
            // Nur bei erwiesenem Fremdbesitz abbrechen. STATE_UNKNOWN bedeutet
            // "nicht ermittelbar" und darf das Beenden nicht verhindern.
            if ($state === self::STATE_FINISHED) return false;
        }

        if ($this->isWindows()) {
            $command = ['taskkill', '/PID', (string) $pid, '/T']; // /T = inklusive Kindprozesse
            if ($force) $command[] = '/F';
            return $this->execute($command, $stdout, $stderr, 20) === 0;
        }

        if (function_exists('posix_kill')) {
            return posix_kill($pid, $force ? 9 : 15); // SIGKILL : SIGTERM
        }

        return $this->execute(['kill', $force ? '-9' : '-15', (string) $pid], $stdout, $stderr, 20) === 0;
    }

    /**
     * Beendet einen Prozess möglichst sauber: erst SIGTERM, damit das Programm
     * aufräumen kann, nach Ablauf der Nachfrist SIGKILL.
     * Unter Windows ist bereits der erste Versuch hart, sobald es sich nicht um
     * eine Anwendung mit Fenster handelt.
     *
     * @param int         $pid
     * @param int         $graceSeconds Nachfrist in Sekunden
     * @param string|null $token        Token aus dem Start, null = ohne Abgleich
     * @return bool true, wenn der Prozess anschließend nicht mehr läuft
     */
    public function stopProcess(int $pid, int $graceSeconds = 3, ?string $token = null): bool
    {
        if ($pid <= 1) return false;

        // Gehört die PID gar nicht mehr zum eigenen Prozess, ist dieser beendet
        // und es gibt nichts abzuschießen.
        if ($token !== null && $token !== '' && $this->processState($pid, $token) === self::STATE_FINISHED) {
            return true;
        }

        $this->terminateProcess($pid, false, $token);

        // Ab hier ohne Token weiterprüfen: die Zugehörigkeit ist oben einmal
        // geklärt, und jede weitere Abfrage mit Token kostet unter Windows
        // einen kompletten PowerShell-Start.
        $deadline = microtime(true) + max(0, $graceSeconds);
        while (microtime(true) < $deadline) {
            usleep(100000); // 100 ms
            $state = $this->processState($pid);
            if ($state === self::STATE_FINISHED || $state === self::STATE_ZOMBIE) return true;
        }

        $this->terminateProcess($pid, true);
        usleep(200000);

        return $this->processState($pid) !== self::STATE_RUNNING;
    }

    /**
     * Läuft PHP unter Windows?
     */
    public function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Prüft, ob das Token in der Kommandozeile steht.
     *
     * Eine leere Kommandozeile gilt als Treffer: unter Windows liefert WMI sie bei
     * fehlenden Rechten leer, unter POSIX kann sie bei Kernel-Threads leer sein.
     * "Nicht lesbar" darf nicht zu "Prozess beendet" werden.
     */
    private function matchToken(string $commandLine, string $token): bool
    {
        if ($token === '') return true;

        $commandLine = trim($commandLine);
        if ($commandLine === '') return true;

        return str_contains($commandLine, $token);
    }

    /**
     * Liest die Kommandozeilen mehrerer Prozesse unter Windows über CIM/WMI aus.
     *
     * tasklist gibt sie nicht her, deshalb PowerShell. Get-CimInstance ist der aktuelle
     * Weg, Get-WmiObject die Rückfalloption für PowerShell 2.0 (Windows 7).
     * Bewusst kein wmic: das Werkzeug ist ab Windows 11 24H2 / Server 2025 entfernt.
     *
     * Die Ausgabe kommt ohne doppelte Anführungszeichen aus ([char]9 = Tabulator),
     * damit beim Durchreichen durch PHP und PowerShell nichts umgedeutet wird.
     *
     * @param  array      $pids
     * @return array|null PID => Kommandozeile, null wenn nicht ermittelbar
     */
    private function commandLinesWindows(array $pids): ?array
    {
        $filter = implode(' or ', array_map(static fn(int $pid): string => 'ProcessId=' . $pid, $pids));

        $script  = '$f = ' . $this->quotePowerShell($filter) . '; ';
        $script .= '$p = if (Get-Command Get-CimInstance -ErrorAction SilentlyContinue) '
                 . '{ Get-CimInstance Win32_Process -Filter $f } else { Get-WmiObject Win32_Process -Filter $f }; ';
        $script .= 'foreach ($i in $p) { [Console]::Out.WriteLine(([string]$i.ProcessId) + [char]9 + ([string]$i.CommandLine)) }';

        foreach (['powershell.exe', 'pwsh.exe'] as $shell) {
            $code = $this->execute(
                [$shell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script],
                $stdout,
                $stderr,
                30
            );

            if ($code === self::EXEC_FAILED) continue; // Shell nicht vorhanden
            if ($code !== 0) return null;

            $lines = [];
            foreach (preg_split('/\r?\n/', $stdout) as $line) {
                $parts = explode("\t", $line, 2);
                $pid   = (int) trim($parts[0]);
                if ($pid <= 0) continue;
                $lines[$pid] = $parts[1] ?? '';
            }

            return $lines;
        }

        return null;
    }

    /**
     * Startvariante für Linux, BSD und macOS.
     */
    private function startPosix(array $command, ?string $stdoutFile, ?string $stderrFile, ?string &$error): int
    {
        // Enthält das Programm einen Pfad, lässt sich vorab prüfen, ob es überhaupt
        // startbar ist. Ohne Pfad übernimmt das die PATH-Suche der Shell.
        if (str_contains($command[0], '/') && !is_executable($command[0])) {
            $error = 'not executable: ' . $command[0];
            return self::EXEC_FAILED;
        }

        $line = implode(' ', array_map('escapeshellarg', $command));

        $shellCommand = 'nohup ' . $line
            . ' < /dev/null'
            . ' > '  . escapeshellarg($stdoutFile ?? '/dev/null')
            . ' 2> ' . escapeshellarg($stderrFile ?? '/dev/null')
            . ' & echo $!';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $lastError = null;
        set_error_handler(function (int $errno, string $message) use (&$lastError): bool {
            $lastError = $message;
            return true;
        });
        $process = proc_open(['/bin/sh', '-c', $shellCommand], $descriptors, $pipes);
        restore_error_handler();

        if (!is_resource($process)) {
            $error = $lastError ?? 'proc_open() failed';
            return self::EXEC_FAILED;
        }

        fclose($pipes[0]);
        // Die Zwischenschale beendet sich sofort nach dem "echo", die Pipes
        // bleiben also klein und blockieren nicht.
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $pid = (int) trim($stdout);
        if ($pid <= 0) {
            $error = $this->toUtf8(trim($stderr));
            if ($error === '') $error = 'no pid returned by shell';
            return self::EXEC_FAILED;
        }

        return $pid;
    }

    /**
     * Startvariante für Windows über PowerShell.
     * Start-Process -PassThru liefert das Prozessobjekt, dessen Id ausgegeben wird.
     */
    private function startWindows(array $command, ?string $stdoutFile, ?string $stderrFile, ?string &$error): int
    {
        $file      = array_shift($command);
        $arguments = implode(' ', array_map([$this, 'quoteWindowsArgument'], $command));

        // Ausschließlich einfache Anführungszeichen verwenden, damit die
        // Kommandozeile weder von PHP noch von PowerShell umgedeutet wird.
        $script  = '$ErrorActionPreference = ' . $this->quotePowerShell('Stop') . '; ';
        $script .= '$p = Start-Process -FilePath ' . $this->quotePowerShell($file);
        if ($arguments !== '') {
            // Als einzelne Zeichenkette übergeben, damit PowerShell die bereits
            // korrekt gequoteten Argumente unverändert durchreicht.
            $script .= ' -ArgumentList ' . $this->quotePowerShell($arguments);
        }
        $script .= ' -WindowStyle Hidden -PassThru';
        if ($stdoutFile !== null) $script .= ' -RedirectStandardOutput ' . $this->quotePowerShell($stdoutFile);
        if ($stderrFile !== null) $script .= ' -RedirectStandardError '  . $this->quotePowerShell($stderrFile);
        $script .= '; $p.Id';

        // pwsh als Rückfalloption für Installationen ohne Windows PowerShell
        foreach (['powershell.exe', 'pwsh.exe'] as $shell) {
            $code = $this->execute(
                [$shell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script],
                $stdout,
                $stderr,
                30
            );

            if ($code === self::EXEC_FAILED) continue; // Shell nicht vorhanden

            $pid = (int) trim($stdout);
            if ($code === 0 && $pid > 0) return $pid;

            $error = trim($stderr);
            if ($error === '') $error = 'powershell exit code ' . $code;
            return self::EXEC_FAILED;
        }

        $error = 'neither powershell.exe nor pwsh.exe found';
        return self::EXEC_FAILED;
    }

    /**
     * Maskiert einen Wert als PowerShell-Zeichenkette in einfachen Anführungszeichen.
     * Dort wird nichts interpretiert, lediglich ' muss verdoppelt werden.
     */
    private function quotePowerShell(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Maskiert ein Argument nach den Regeln von CommandLineToArgvW:
     * Backslashes vor einem Anführungszeichen werden verdoppelt, das
     * Anführungszeichen selbst wird mit einem Backslash geschützt.
     */
    private function quoteWindowsArgument(string $argument): string
    {
        if ($argument !== '' && !preg_match('/[\s"]/', $argument)) {
            return $argument;
        }

        $escaped      = '';
        $backslashes  = 0;
        $length       = strlen($argument);

        for ($i = 0; $i < $length; $i++) {
            $char = $argument[$i];

            if ($char === '\\') {
                $backslashes++;
                continue;
            }
            if ($char === '"') {
                $escaped .= str_repeat('\\', $backslashes * 2 + 1) . '"';
                $backslashes = 0;
                continue;
            }
            $escaped    .= str_repeat('\\', $backslashes) . $char;
            $backslashes = 0;
        }

        // Am Ende stehende Backslashes verdoppeln, sonst würden sie das
        // abschließende Anführungszeichen entwerten.
        return '"' . $escaped . str_repeat('\\', $backslashes * 2) . '"';
    }

    /**
     * Notlösung, wenn ps nicht aufrufbar ist (z. B. minimaler Container).
     * posix_kill() mit Signal 0 stellt lediglich die Existenz fest, ein
     * Zombie ist damit nicht von einem laufenden Prozess zu unterscheiden.
     * Ein Token-Abgleich ist hier ebenfalls nicht möglich.
     */
    private function processStatesPosixFallback(array $pids): array
    {
        if (!function_exists('posix_kill')) {
            return array_fill_keys($pids, self::STATE_UNKNOWN);
        }

        $states = [];
        foreach ($pids as $pid) {
            if (posix_kill($pid, 0)) {
                $states[$pid] = self::STATE_RUNNING;
                continue;
            }
            // EPERM (1) = Prozess existiert, gehört aber einem anderen Benutzer
            $states[$pid] = (function_exists('posix_get_last_error') && posix_get_last_error() === 1)
                ? self::STATE_RUNNING
                : self::STATE_FINISHED;
        }

        return $states;
    }

    /**
     * Das Commandline-Tool schreibt in der Kodierung des Systems. Unter Linux und macOS
     * ist das praktisch immer UTF-8, unter Windows dagegen die aktive Codepage.
     * Damit die Meldung im Backend lesbar bleibt, wird notfalls konvertiert.
     */
    private function toUtf8(string $text): string
    {
        if ($text === '') return '';
        if (!function_exists('mb_check_encoding')) return $text;
        if (mb_check_encoding($text, 'UTF-8')) return $text;

        return (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
}
