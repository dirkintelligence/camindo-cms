<?php
/**
 * class BackgroundProcess, Instanzname $bgproc
 *
 * Manages long-running commands that run in the background, detached from the web request.
 * The actual start and process monitoring are in Execute; this class holds
 * only the state in the table `bgproc` (see bgproc.sql).
 *
 * Flow:
 *  1. start() creates a token (unless passed as a parameter), starts the command
 *     and writes PID + token to the database.
 *  2. The background process reports its progress back via the token
 *     (setProgressByToken()); it needs no session for this.
 *  3. list() reconciles the stored state with the actual state on every call.
 *
 * Security: abort() and remove() only terminate or delete PIDs that are also listed in
 * `bgproc`. Any PID of the system cannot be shot down this way.
 */
namespace Camindo\Core;

class BackgroundProcess {

    /**
     * Values of the column `status`, identical to the STATE_* constants of Execute
     */
    public const STATUS_FINISHED = 0;
    public const STATUS_RUNNING  = 1;
    public const STATUS_TIMEOUT  = 2;
    public const STATUS_FAILED   = 3;

    /**
     * Process types whose entries are still needed after they end: the trigger
     * (TriggerMedia::finalize()) reads the result and removes the entry
     * itself. All other finished entries are cleaned up by the list in the backend
     * (purgeFinishedOfAdmin()) - a finished process is no longer of interest there.
     */
    public const TRIGGER_TYPES = ['ffmpeg', 'magick'];

    /** File resource for method lock(), null = no lock held */
    private mixed $triggerLock = null;

    /** The browser has already been asked for monitoring in this request */
    private bool $watchScriptSent = false;

    public function __construct(
        private Database $db,
        private Config $config,
        private Crypt $crypt,
        private Session $session,
        private Execute $execute,
        private SecurityHeaders $securityHeaders,
    )
    {
    }

    /**
     * Load all background processes from the database incl. status and progress.
     * Determine the actual status for every process not yet finished.
     * If finished, also update it as "finished" in the database.
     * @param  int   $adminId list processes of this admin, 0 = all admins
     * @return array rows from `bgproc`, newest first; empty array if none exist
     */
    public function list(int $adminId = 0): array
    {
        $this->refresh($adminId);

        if (!empty($adminId)) {
            $this->db->query("SELECT * FROM `bgproc` WHERE `admin_id` = ? ORDER BY `created_at` DESC", 'i', [$adminId]);
        } else {
            $this->db->query("SELECT * FROM `bgproc` ORDER BY `created_at` DESC");
        }

        return $this->db->fetchAll();
    }

    /**
     * Start the command in the background and register the process.
     *
     * @param array       $command    command including all arguments
     * @param string      $type       free type identifier, e.g. 'import' or 'thumbnails'
     * @param string|null $stdoutFile log file for the output, null = discard
     * @param string|null $stderrFile log file for the error output, null = discard
     * @param string|null $error      reason of a failure
     * @return array|null             ['pid' => int, 'token' => string, 'id' => int] or null
     */
    public function start(array $command, string $type, ?string $token = null, ?string $stdoutFile = null, ?string $stderrFile = null, ?string &$error = null): ?array
    {
        $error = '';
        if ($token === null) $token = $this->crypt->randomKey(16);

        $pid = $this->execute->executeBackground($command, $stdoutFile, $stderrFile, $error);

        if ($pid <= 0) {
            return null;
        }

        if (!$this->create($pid, $type, $token)) {
            // Database entry failed: otherwise the process would be unsupervised
            $this->execute->stopProcess($pid, 2);
            $error = 'process could not be registered';
            return null;
        }

        return [
            'id'    => (int)$this->db->insert_id,
            'pid'   => $pid,
            'token' => $token,
        ];
    }

    /**
     * Enter a new process in the database
     * @param  int         $pid   PID of the running process, must be > 0
     * @param  string      $type  free type identifier
     * @param  string|null $token given token, null = create a new token
     * @return bool        true if the record was created
     */
    public function create(int $pid, string $type, ?string $token = null): bool
    {
        if ($pid <= 0) return false;

        $sessionAdminId = (int)$this->session->get('admin_id');
        $token ??= $this->crypt->randomKey(16);

        $this->db->query(
            "INSERT INTO `bgproc` (`token`, `type`, `admin_id`, `pid`, `status`, `progress`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            'ssiii',
            [$token, $type, $sessionAdminId, $pid, $this::STATUS_RUNNING],
            true
        );
        if ($this->db->affected_rows !== 1) return false;

        // Backend request: ask the browser to monitor (cApp.polling
        // only queries the state of the processes while one is running). The script goes
        // back with the response of this request; once per request is enough.
        if (!$this->watchScriptSent && defined('CAMINDO_BACKEND') && PHP_SAPI !== 'cli') {
            $this->watchScriptSent = true;
            echo '<script' . $this->securityHeaders->nonceAttr() . ">if (window.cApp && cApp.polling) cApp.polling.watchBgproc();</script>\n";
        }
        return true;
    }

    /**
     * Aborts a running process and removes its record.
     *
     * Only PIDs that are listed in `bgproc` are touched.
     *
     * @param  string $token    token of the process to abort
     * @return bool             true if the record was removed; false if the PID is unknown
     */
    public function abort(string $token): bool
    {
        $row = $this->getByToken($token);
        if ($row === null) return false;
        $pid = (int)$row['pid'];

        if ((int)$row['status'] !== self::STATUS_FINISHED) {
            // First SIGTERM so the process can clean up, then SIGKILL
            $this->execute->stopProcess($pid, 3, (string)$row['token']);
        }

        return $this->deleteById((int)$row['id']);
    }

    /**
     * Remove a regularly finished process from the database
     * @param  string $token    token of the process to remove
     * @return bool             true if the record was removed; false if the PID is unknown or the process is still running
     */
    public function remove(string $token): bool
    {
        $row = $this->getByToken($token);
        if ($row === null) return false;
        $pid = (int)$row['pid'];

        // If the process unexpectedly is still running, the entry stays.
        // abort() is responsible for ending it.
        if ($this->execute->processState($pid, (string)$row['token']) === Execute::STATE_RUNNING) {
            return false;
        }
        
        return $this->deleteById((int)$row['id']);
    }

    /**
     * Update process progress
     *
     * @param  string      $token       token from the start argument
     * @param  int         $progress    progress in percent, 0-100
     * @param  string|null $message     null: no change, the JSON string
     * @return bool                     true if a record was updated
     */
    public function setProgressByToken(string $token, int $progress, ?string $message = null): bool
    {
        if ($token === '') return false;
        // progress: min 0, max 100
        $progress = max(0, min(100, $progress));

        $sqlCols = "`progress` = ?, `updated_at` = UTC_TIMESTAMP()";
        $sqlTypes = 'i';
        $sqlValues = [$progress];
        
        // message
        if ($message !== null) {
            $sqlCols .= ', `message` = ?';
            $sqlTypes .= 's';
            $sqlValues[] = $message;
        }
        $sql = "UPDATE `bgproc` SET $sqlCols WHERE `token` = ?";
        $sqlTypes .= 's';
        $sqlValues[] = $token;
        $this->db->query($sql, $sqlTypes, $sqlValues, true);

        return $this->db->affected_rows > 0;
    }

    /**
     * Update process status
     *
     * @param  string $token    token from the start argument
     * @param  int $status      status 0: finished, 1: running
     * @return bool             true if a record was updated
     */
    public function setStatusByToken(string $token, int $status): bool
    {
        if ($token === '') return false;
        // With status 0 ("completed") set the progress to 100%
        $sqlProgress = '';
        if ($status === 0) {
            $sqlProgress = '`progress` = 100, `finished_at` = UTC_TIMESTAMP(), ';
        }
        $sql = "UPDATE `bgproc` SET $sqlProgress `status` = ?, `updated_at` = UTC_TIMESTAMP() WHERE `token` = ?";
        $this->db->query($sql, 'is', [$status, $token], true);
        return $this->db->affected_rows > 0;
    }

    /**
     * Update process message
     *
     * @param  string $token    token from the start argument
     * @param  string $message  JSON string
     * @return bool             true if a record was updated
     */
    public function setMessageByToken(string $token, string $message): bool
    {
        if ($token === '') return false;
        $sql = "UPDATE `bgproc` SET `message` = ?, `updated_at` = UTC_TIMESTAMP() WHERE `token` = ?";
        $this->db->query($sql, 'ss', [$message, $token], true);
        return $this->db->affected_rows > 0;
    }


   /**
     * Fetches a single record by PID.
     *
     * @param  int        $pid PID of the process
     * @return array|null      record from `bgproc` or null if the PID is unknown
     */
    public function get(int $pid): ?array
    {
        if ($pid <= 0) return null;

        $this->db->query("SELECT * FROM `bgproc` WHERE `pid` = ? ORDER BY `id` DESC LIMIT 1", 'i', [$pid], true);
        $row = $this->db->fetch();

        return $row ?: null;
    }

    /**
     * Fetches a single record by token.
     *
     * @param  string     $token token of the process
     * @return array|null        record from `bgproc` or null if the token is unknown
     */
    public function getByToken(string $token): ?array
    {
        if ($token === '') return null;

        $this->db->query("SELECT * FROM `bgproc` WHERE `token` = ? LIMIT 1", 's', [$token], true);
        $row = $this->db->fetch();

        return $row ?: null;
    }

    /**
     * Cleans up finished entries whose last change is older.
     *
     * Running processes and processes marked as zombies are always kept.
     * Useful via cron job or when opening the overview, so the table does not grow without limit.
     *
     * @param  int $hours minimum age in hours, at least 1
     * @return int        number of deleted entries
     */
    public function purgeFinished(int $hours = 24): int
    {
        $this->db->query(
            "DELETE FROM `bgproc` WHERE `status` = 0 AND `updated_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)",
            'i',
            [max(1, $hours)],
            true
        );

        return $this->db->affected_rows;
    }

    /**
     * Remove regularly finished entries of an admin - except those a trigger
     * still has to finalize (TRIGGER_TYPES). Runs before every list output in the backend.
     *
     * @return int  number of deleted entries
     */
    public function purgeFinishedOfAdmin(int $adminId): int
    {
        if ($adminId < 1) return 0;
        $placeholders = implode(',', array_fill(0, count(self::TRIGGER_TYPES), '?'));
        $this->db->query(
            "DELETE FROM `bgproc` WHERE `status` = ? AND `admin_id` = ? AND `type` NOT IN ($placeholders)",
            'ii' . str_repeat('s', count(self::TRIGGER_TYPES)),
            array_merge([self::STATUS_FINISHED, $adminId], self::TRIGGER_TYPES),
            true
        );
        return $this->db->affected_rows;
    }

    /**
     * Reconciles the stored status with the actual state of the processes.
     *
     * All entries not yet marked as finished are checked with a single call of
     * ps or tasklist, so the cost is independent of the number of processes. Written only
     * if the status has actually changed.
     *
     * If Execute returns STATE_UNKNOWN, the state cannot be determined (neither ps/tasklist
     * nor posix_kill available). The entry then stays unchanged, otherwise a
     * running process would wrongly be shown as finished.
     *
     * Note: PIDs are reused. A process counts as running here as long as
     * there is any process with this PID.
     *
     * @param  int  $adminId check only processes of this admin, 0 = all admins
     * @return void
     */
    private function refresh(int $adminId = 0): void
    {
        
        // Only reconcile RUNNING entries. A process recorded as failed or timed out
        // (trigger, mass_delete, scheduler) stays so - its process is gone anyway, and
        // "gone" must not become "finished successfully" here. (STATUS_TIMEOUT and
        // Execute::STATE_ZOMBIE share the value 2 - so a zombie counts below as still
        // running until it has been collected.)
        if (!empty($adminId)) {
            $this->db->query("SELECT `id`, `pid`, `status`, `token` FROM `bgproc` WHERE `status` = ? AND `admin_id` = ?", 'ii', [self::STATUS_RUNNING, $adminId]);
        } else {
            $this->db->query("SELECT `id`, `pid`, `status`, `token` FROM `bgproc` WHERE `status` = ?", 'i', [self::STATUS_RUNNING]);
        }

        // Read everything completely first, then write: the following UPDATEs
        // overwrite the current result of the database class.
        $rows = $this->db->fetchAll();
        if (empty($rows)) return;

        $states = $this->execute->processStates(
            array_column($rows, 'pid'),
            array_column($rows, 'token', 'pid')
        );

        foreach ($rows as $row) {
            $pid   = (int)$row['pid'];
            $state = $states[$pid] ?? Execute::STATE_UNKNOWN;

            // State cannot be determined: leave the entry unchanged, otherwise
            // a running process would wrongly be shown as finished.
            if ($state === Execute::STATE_UNKNOWN) continue;
            if ($state === Execute::STATE_ZOMBIE) continue; // not yet collected
            if ($state === (int)$row['status']) continue;

            if ($state === Execute::STATE_FINISHED) {
                $this->db->query(
                    "UPDATE `bgproc` SET `status` = ?, `finished_at` = IFNULL(`finished_at`, UTC_TIMESTAMP()), `updated_at` = UTC_TIMESTAMP() WHERE `id` = ?",
                    'ii',
                    [self::STATUS_FINISHED, (int)$row['id']],
                    true
                );
                continue;
            }

            $this->db->query(
                "UPDATE `bgproc` SET `status` = ?, `updated_at` = UTC_TIMESTAMP() WHERE `id` = ?",
                'ii',
                [$state, (int)$row['id']],
                true
            );
        }
    }

    /**
     * Deletes a record by its primary key.
     *
     * Deliberately via `id` instead of `pid`: with reused PIDs the record of another
     * process would otherwise be deleted as well.
     *
     * @param  int  $id primary key from `bgproc`
     * @return bool     true if exactly one record was deleted
     */
    private function deleteById(int $id): bool
    {
        if ($id <= 0) return false;

        $this->db->query("DELETE FROM `bgproc` WHERE `id` = ?", 'i', [$id], true);

        return $this->db->affected_rows === 1;
    }


    /**
     * Creates a lock so that processes of the same type are not started in parallel.
     * Returns false if a process is already running, true if a new lock was created.
     * Garbage collection releases the lock, or also unlock()
     *
     * @param string $type  to distinguish processes running in parallel
     */
    public function lock(string $type): bool
    {
        $tmpDir = $this->config->get('paths.tmp_dir');
        $lockFile = $tmpDir . DIRECTORY_SEPARATOR . $type . '-trigger.lock';
        $f = @fopen($lockFile, 'c');
        if ($f === false) return false;

        // Try to get an exclusive, non-blocking lock
        if (!flock($f, LOCK_EX | LOCK_NB)) {
            fclose($f);
            return false;
        }
        $this->triggerLock = $f;
        return true;
    }

    /**
     * see lock()
     */
    public function unlock(): void
    {
        if (!is_resource($this->triggerLock)) return;

        flock($this->triggerLock, LOCK_UN);
        fclose($this->triggerLock);
        $this->triggerLock = null;
    }

}
