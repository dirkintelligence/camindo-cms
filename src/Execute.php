<?php
/**
 * class Execute
 * 
 * Provides a robust and safe method for running CLI commands.
 * This trait is optimized for interaction with external tools (such as ImageMagick)
 * and solves typical problems that can occur when using `proc_open()`.
 *
 * Main features:
 * - Safety: commands are passed as an array, which avoids shell injection.
 * - Deadlock prevention: STDOUT and STDERR are redirected to temporary files. This prevents
 *   the process from blocking (deadlocks) when the operating system's output buffers are full,
 *   which often happens on Windows systems without `stream_select()`.
 * - Timeout management: actively monitors the execution time. When it is exceeded, the process
 *   is first terminated normally (SIGTERM) and, after a grace period, aborted hard (SIGKILL).
 * - Memory protection: the maximum size of the read output (STDOUT/STDERR) is limited,
 *   to prevent memory exhaustion from extremely long output (e.g. from `-verbose`).
 * - Encoding correction: ensures that the error output in the backend is always UTF-8
 *   (especially relevant for Windows command lines).
 * - Background processes: executeBackground() starts a command detached from the PHP process
 *   and returns its PID; processState() and terminateProcess() monitor and end it.
 * - Token matching: because the operating system reuses PIDs (on shared hosting often
 *   within minutes), each background process can be given a token. It appears in the
 *   command line of the process and is read back from it during monitoring. If it does
 *   not match, the PID now belongs to a foreign process and counts as ended.
 */
namespace Camindo\Core;

class Execute {

    /**
     * Return values of execute() that are not an exit code of a process
     * (public, because BackgroundProcess evaluates them)
     */
    public const EXEC_FAILED  = -1; // process could not be started
    public const EXEC_TIMEOUT = -2; // timeout, process was aborted

    /**
     * States of a process, as returned by processState() / processStates().
     * The values 0/1/2 are deliberately identical to the column `status` in `bgproc`.
     */
    public const STATE_UNKNOWN  = -1; // cannot be determined (neither ps/tasklist nor posix_kill available)
    public const STATE_FINISHED = 0;  // process no longer exists
    public const STATE_RUNNING  = 1;  // process is running
    public const STATE_ZOMBIE   = 2;  // ended, but not yet collected by the parent process

    /**
     * Upper limit for the read output.
     * "-verbose" or a defective source file could otherwise produce megabytes
     * that travel via 'error_details' into the database.
     */
    private const MAX_STDOUT = 262144; // 256 KiB
    private const MAX_STDERR = 65536;  //  64 KiB

    public function __construct(
        private Config $config
    )
    {
    }

    /**
     * Splits a freely entered parameter line into individual arguments.
     * Quotation marks (single and double) keep values with spaces together.
     *
     * Works purely on bytes and is therefore independent of operating system,
     * locale and character encoding (UTF-8 included).
     *
     * @return array list of individual arguments
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
                // Inside double quotation marks the backslash is an escape only before
                // " and \. Otherwise it stays, so that Windows paths
                // such as "C:\temp\bild.png" are not destroyed.
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
                $started = true; // also allows empty arguments ("")
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
     * Runs a command without a shell (proc_open with an argument array),
     * so special characters in file and parameter values are harmless.
     *
     * stdout and stderr go to temporary files instead of pipes. This
     * prevents deadlocks with large output and works without stream_select(),
     * which does not work on process pipes under Windows.
     *
     * @param array       $command Command including all arguments
     * @param string|null $stdout  output
     * @param string|null $stderr  error output; on failure also the reason from PHP
     * @param int         $timeout seconds until abort, 0 = no abort
     * @param array       $env     additional environment variables of the child process
     *                             (added to its own environment), e.g. CAMINDO_API_SESSION
     * @return int                 exit code, EXEC_FAILED or EXEC_TIMEOUT
     */
    public function execute(array $command, ?string &$stdout = null, ?string &$stderr = null, int $timeout = 0, array $env = []): int
    {
        $stdout = '';
        $stderr = '';

        $outFile = @tmpfile();
        $errFile = @tmpfile();
        // tmpfile() can fail, e.g. with a restrictive open_basedir or a
        // temp directory that is not writable
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

        // Keep the reason for a failure instead of swallowing it with @:
        // "file not found" and "no execute permission" are the
        // most common stumbling blocks during setup.
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

        fclose($pipes[0]); // close stdin -> child process gets EOF immediately

        if (!$useTempFiles) {
            // Fallback without timeout monitoring: the pipes must be read
            // while the process is running. In practice only relevant for the short
            // output of "identify".
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
                    // The exit code must be taken here; after the process has
                    // been collected, proc_close() no longer returns it
                    // reliably.
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $deadline) {
                    if (!$terminated) {
                        proc_terminate($process);          // POSIX: SIGTERM, Windows: hard
                        $terminated = true;
                        $deadline   = microtime(true) + 5; // grace period for cleanup
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

        // Trick PHP: move the pointer to the end so that the internally cached file size
        // is synchronized, then rewind() back to the start, which is mandatory.
        fseek($outFile, 0, SEEK_END);
        rewind($outFile);
        $stdout = (string) stream_get_contents($outFile, self::MAX_STDOUT);

        fseek($errFile, 0, SEEK_END);
        rewind($errFile);
        $stderr = (string) stream_get_contents($errFile, self::MAX_STDERR);

        fclose($outFile); // tmpfile() deletes itself when closed
        fclose($errFile);

        $stderr = $this->toUtf8($stderr);

        return $exitCode;
    }

    // ========== Background processes ==========

    /**
     * Starts a command as an independent background process and returns its PID.
     *
     * The process is detached from the PHP process: it keeps running when the script ends,
     * holds no handles of the web server open (stdin/stdout/stderr are redirected) and
     * leaves no zombie, because it is taken over by init/PID 1 after the intermediate shell
     * ends immediately.
     *
     * Deliberately NOT via proc_open() without a shell: the resource destructor of proc_open()
     * waits for the child at the end of the script, so the process would not be in the background at all.
     *
     * POSIX  : sh -c 'nohup COMMAND </dev/null >out 2>err & echo $!'
     *          nohup replaces itself via exec() with the target program, so the
     *          PID reported by $! is also the PID of the target program.
     * Windows: powershell Start-Process -PassThru, whose .Id is the PID.
     *          (no COM/WMI needed, PowerShell has been a fixed part of Windows since Windows 7)
     *
     * Note: a failure of the target program itself (e.g. "not found") is not detectable
     * here under POSIX, because the shell reports the PID immediately. That is what
     * $stderrFile is for.
     *
     * @param array       $command    command including all arguments
     * @param string|null $stdoutFile file for the output, null = discard
     * @param string|null $stderrFile file for the error output, null = discard
     * @param string|null $error      reason for a failure
     * @return int                    PID of the process or EXEC_FAILED
     */
    public function executeBackground(array $command, ?string $stdoutFile = null, ?string $stderrFile = null, ?string &$error = null): int
    {
        $error   = '';
        $command = array_values(array_map('strval', $command));

        if ($command === []) {
            $error = 'empty command';
            return self::EXEC_FAILED;
        }
        // Start-Process rejects redirecting both streams into the same file,
        // so for consistency this is forbidden on both platforms.
        if ($stdoutFile !== null && $stdoutFile === $stderrFile) {
            $error = 'stdout and stderr must not point to the same file';
            return self::EXEC_FAILED;
        }

        return $this->isWindows()
            ? $this->startWindows($command, $stdoutFile, $stderrFile, $error)
            : $this->startPosix($command, $stdoutFile, $stderrFile, $error);
    }

    /**
     * Determines the state of a single process.
     *
     * @param  int         $pid
     * @param  string|null $token token from the start, null = without matching
     * @return int         STATE_FINISHED, STATE_RUNNING, STATE_ZOMBIE or STATE_UNKNOWN
     */
    public function processState(int $pid, ?string $token = null): int
    {
        if ($pid <= 0) return self::STATE_FINISHED;

        $tokens = ($token !== null && $token !== '') ? [$pid => $token] : [];
        $states = $this->processStates([$pid], $tokens);

        return $states[$pid] ?? self::STATE_UNKNOWN;
    }

    /**
     * Determines the state of several processes with a single call of
     * ps or tasklist/PowerShell. Much more economical than processState() for overview lists.
     *
     * If a token is given for a PID, the command line of the process is additionally
     * read. If the token does not appear in it, the PID has meanwhile been reassigned and the
     * own process counts as ended (STATE_FINISHED). This is exactly the case on shared hosting with
     * high PID turnover, which would otherwise be misread as "still running".
     *
     * Without a token it remains a pure existence test; then "running" still only means
     * "there is a process with this PID".
     *
     * @param  array $pids   list of PIDs
     * @param  array $tokens PID => token; only the PIDs named here are matched
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

        // Normalize tokens: only PIDs from the list, no empty tokens
        $check = [];
        foreach ($tokens as $pid => $token) {
            $pid   = (int) $pid;
            $token = (string) $token;
            if ($pid > 0 && $token !== '') $check[$pid] = $token;
        }

        // Default assumption: not found = ended
        $states = array_fill_keys($pids, self::STATE_FINISHED);

        if ($this->isWindows()) {
            if ($check !== []) {
                // tasklist does not know the command line; WMI/CIM is needed for that.
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
                // PowerShell not available: better to continue without token matching
                // via tasklist than to give no statement at all.
            }

            // A single query of the entire process list is cheaper than a
            // tasklist call with /FI per PID. Windows does not know zombies.
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

        // Specify "-o field=" separately: BSD/macOS would interpret
        // everything after the first "=" as a column heading with "-o pid=,state=".
        $arguments = ['ps'];
        if ($check !== []) {
            // -ww lifts the line width limit. Without a tty ps would otherwise cut at 80 characters,
            // a token at the end would then be truncated.
            $arguments[] = '-ww';
        }
        array_push($arguments, '-o', 'pid=', '-o', 'state=');
        if ($check !== []) array_push($arguments, '-o', 'args=');
        array_push($arguments, '-p', implode(',', $pids));

        $code = $this->execute($arguments, $stdout, $stderr, 20);

        // Exit code 1 from ps merely means "none of the PIDs found".
        // Anything else (missing ps, timeout, unknown option in stripped-down
        // ps variants) must not pass as "all ended".
        if ($code !== 0 && $code !== 1) {
            return $this->processStatesPosixFallback($pids);
        }

        foreach (preg_split('/\r?\n/', $stdout) as $line) {
            if (!preg_match('/^\s*(\d+)\s+(\S+)\s*(.*)$/', $line, $match)) continue;
            $pid = (int) $match[1];
            if (!isset($states[$pid])) continue;

            if ($match[2][0] === 'Z') {
                // For a zombie ps only shows "<defunct>"; the token has naturally
                // disappeared there - hence no matching.
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
     * Ends a process.
     *
     * If a token is given, it is first checked whether the PID still belongs to the own
     * process at all. Without this check a foreign process could be shot down after a
     * PID reuse.
     *
     * @param int         $pid
     * @param bool        $force true = SIGKILL or taskkill /F, false = SIGTERM
     * @param string|null $token token from the start, null = without matching
     * @return bool
     */
    public function terminateProcess(int $pid, bool $force = false, ?string $token = null): bool
    {
        if ($pid <= 1) return false; // never touch PID 1 and invalid values

        if ($token !== null && $token !== '') {
            $state = $this->processState($pid, $token);
            // Only abort when foreign ownership is proven. STATE_UNKNOWN means
            // "cannot be determined" and must not prevent the termination.
            if ($state === self::STATE_FINISHED) return false;
        }

        if ($this->isWindows()) {
            $command = ['taskkill', '/PID', (string) $pid, '/T']; // /T = including child processes
            if ($force) $command[] = '/F';
            return $this->execute($command, $stdout, $stderr, 20) === 0;
        }

        if (function_exists('posix_kill')) {
            return posix_kill($pid, $force ? 9 : 15); // SIGKILL : SIGTERM
        }

        return $this->execute(['kill', $force ? '-9' : '-15', (string) $pid], $stdout, $stderr, 20) === 0;
    }

    /**
     * Ends a process as cleanly as possible: first SIGTERM so that the program
     * can clean up, SIGKILL after the grace period has elapsed.
     * Under Windows the first attempt is already hard, unless it is an
     * application with a window.
     *
     * @param int         $pid
     * @param int         $graceSeconds grace period in seconds
     * @param string|null $token        token from the start, null = without matching
     * @return bool true if the process is no longer running afterwards
     */
    public function stopProcess(int $pid, int $graceSeconds = 3, ?string $token = null): bool
    {
        if ($pid <= 1) return false;

        // If the PID no longer belongs to the own process at all, that process has ended
        // and there is nothing to shoot down.
        if ($token !== null && $token !== '' && $this->processState($pid, $token) === self::STATE_FINISHED) {
            return true;
        }

        $this->terminateProcess($pid, false, $token);

        // From here on check without token: ownership was clarified once above,
        // and every further query with a token costs a complete
        // PowerShell start under Windows.
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
     * Is PHP running under Windows?
     */
    public function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Checks whether the token appears in the command line.
     *
     * An empty command line counts as a match: under Windows WMI returns it empty
     * when rights are missing, under POSIX it can be empty for kernel threads.
     * "Not readable" must not become "process ended".
     */
    private function matchToken(string $commandLine, string $token): bool
    {
        if ($token === '') return true;

        $commandLine = trim($commandLine);
        if ($commandLine === '') return true;

        return str_contains($commandLine, $token);
    }

    /**
     * Reads the command lines of several processes under Windows via CIM/WMI.
     *
     * tasklist does not provide them, hence PowerShell. Get-CimInstance is the current
     * way, Get-WmiObject the fallback option for PowerShell 2.0 (Windows 7).
     * Deliberately not wmic: the tool has been removed from Windows 11 24H2 / Server 2025.
     *
     * The output comes without double quotation marks ([char]9 = tab),
     * so that nothing is reinterpreted when passed through PHP and PowerShell.
     *
     * @param  array      $pids
     * @return array|null PID => command line, null if not determinable
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

            if ($code === self::EXEC_FAILED) continue; // shell not present
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
     * Start variant for Linux, BSD and macOS.
     */
    private function startPosix(array $command, ?string $stdoutFile, ?string $stderrFile, ?string &$error): int
    {
        // If the program contains a path, it can be checked beforehand whether it can
        // be started at all. Without a path, the shell's PATH search takes over.
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
        // The intermediate shell ends immediately after the "echo", so the pipes
        // stay small and do not block.
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
     * Start variant for Windows via PowerShell.
     * Start-Process -PassThru returns the process object, whose id is output.
     */
    private function startWindows(array $command, ?string $stdoutFile, ?string $stderrFile, ?string &$error): int
    {
        $file      = array_shift($command);
        $arguments = implode(' ', array_map([$this, 'quoteWindowsArgument'], $command));

        // Use only single quotation marks, so that the
        // command line is reinterpreted neither by PHP nor by PowerShell.
        $script  = '$ErrorActionPreference = ' . $this->quotePowerShell('Stop') . '; ';
        $script .= '$p = Start-Process -FilePath ' . $this->quotePowerShell($file);
        if ($arguments !== '') {
            // Passed as a single string, so that PowerShell passes the
            // already correctly quoted arguments through unchanged.
            $script .= ' -ArgumentList ' . $this->quotePowerShell($arguments);
        }
        $script .= ' -WindowStyle Hidden -PassThru';
        if ($stdoutFile !== null) $script .= ' -RedirectStandardOutput ' . $this->quotePowerShell($stdoutFile);
        if ($stderrFile !== null) $script .= ' -RedirectStandardError '  . $this->quotePowerShell($stderrFile);
        $script .= '; $p.Id';

        // pwsh as fallback for installations without Windows PowerShell
        foreach (['powershell.exe', 'pwsh.exe'] as $shell) {
            $code = $this->execute(
                [$shell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script],
                $stdout,
                $stderr,
                30
            );

            if ($code === self::EXEC_FAILED) continue; // shell not present

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
     * Escapes a value as a PowerShell string in single quotation marks.
     * Nothing is interpreted there; only ' has to be doubled.
     */
    private function quotePowerShell(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Escapes an argument according to the rules of CommandLineToArgvW:
     * backslashes before a quotation mark are doubled, the
     * quotation mark itself is protected with a backslash.
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

        // Double backslashes at the end, otherwise they would
        // invalidate the closing quotation mark.
        return '"' . $escaped . str_repeat('\\', $backslashes * 2) . '"';
    }

    /**
     * Fallback when ps cannot be called (e.g. a minimal container).
     * posix_kill() with signal 0 merely establishes existence; a
     * zombie cannot be told apart from a running process that way.
     * A token match is not possible here either.
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
            // EPERM (1) = process exists, but belongs to another user
            $states[$pid] = (function_exists('posix_get_last_error') && posix_get_last_error() === 1)
                ? self::STATE_RUNNING
                : self::STATE_FINISHED;
        }

        return $states;
    }

    /**
     * The command-line tool writes in the system's encoding. Under Linux and macOS
     * that is practically always UTF-8, under Windows however the active code page.
     * So that the message stays readable in the backend, it is converted if necessary.
     */
    private function toUtf8(string $text): string
    {
        if ($text === '') return '';
        if (!function_exists('mb_check_encoding')) return $text;
        if (mb_check_encoding($text, 'UTF-8')) return $text;

        return (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
}
