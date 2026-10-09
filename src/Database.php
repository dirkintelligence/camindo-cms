<?php
/**
 * Database abstraction over mysqli. Instance name in the code: $db.
 *
 * Everything the CMS sends as SQL runs through here. The class does three things that
 * mysqli alone does not provide: prepared statements with a single call,
 * a stack for nested results and nestable transactions.
 *
 * PREPARED STATEMENTS
 * --------------------
 *     $db->query("SELECT * FROM `admin` WHERE `admin_id` = ?", 'i', [$id]);
 *     $data = $db->fetch();
 *
 * Values ALWAYS belong in $values, never in the SQL string. Identifiers (table and
 * column names) cannot be bound - the callers clean them beforehand with
 * preg_replace('/[^a-z0-9_]/', ...).
 *
 * Bind types as in mysqli: 'i' int, 'd' double, 's' string, 'b' blob.
 * Dataset::getBindType() derives them from the field type of the schema.
 *
 * THE "CURRENT RESULT"
 * --------------------
 * query() remembers its result internally. The fetch* methods continue on it without
 * parameters; likewise $num_rows, $affected_rows and $insert_id are set anew after each
 * query(). This keeps the caller's code short, but also means: the next
 * query overwrites the state.
 *
 * RESULT STACK (pushResult / popResult)
 * --------------------
 * That is exactly why the stack exists. Whoever starts a subquery while still iterating
 * over a result brackets it:
 *
 *     $db->pushResult();          // save the running result + counters
 *     $cms->select([...]);        // subquery
 *     while ($row = $cms->fetch()) { ... }
 *     $db->popResult();           // restore the previous state
 *
 * The template compiler does this automatically for CMS:SELECT (php/push_result.php and
 * php/pop_result.php); Dataset::load(), save() and transactional() do so as well.
 * MAX_STACK_DEPTH aborts at 50 levels - more a brake on endless loops
 * than a real limit.
 *
 * TRANSACTIONS
 * --------------------
 * beginTransaction() / commit() / rollback() count with ($transactionDepth). MySQL
 * only knows the outermost level; nested calls raise or lower the counter.
 * If an inner level calls rollback(), the transaction is marked as $rollbackOnly:
 * the outer commit() then still rolls back and throws - a partially
 * written transaction can thus not be committed unnoticed.
 *
 * Control transactions exclusively through this class, never directly through mysqli,
 * otherwise the counter goes wrong. Dataset::transactional() encapsulates the usual.
 *
 * ERROR BEHAVIOR
 * --------------------
 * mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT) is set: SQL errors
 * throw a mysqli_sql_exception, there are no silent false returns. Within
 * Dataset, transactional() catches this and rolls back.
 *
 * DEBUGGING
 * --------------------
 * system.debugsql = true in .camindo.php logs every query with its values to
 * var/log/ (System::log('sql', ...)).
 * Requires system.development_version = true.
 */
namespace Camindo\Core;
use \mysqli;

class Database {
    /** Emergency brake against endless loops in push/popResult - not a functional limit */
    const MAX_STACK_DEPTH = 50;

    private mysqli $mysqli;
    /** Reused statements, only filled when query(..., cache: true) */
    private array $stmtCache = [];
    /** Saved result states, see pushResult() */
    private array $stack = [];
    /** Result of the last query() - basis of all fetch* calls without parameters */
    private ?\mysqli_result $currentResult = null;
    /**
     * Result from a PHP array instead of the database, see setArrayResult().
     * While set, fetch() / fetchNum() / fetchAll() / fetchColumn() read from it.
     */
    private ?array $arrayResult = null;
    private int $arrayPos = 0;
    private bool $debug = false;
    /** Nesting depth of the transactions; only 0 -> 1 really starts one */
    public int $transactionDepth = 0;
    /** true: an inner level called rollback(), the outer commit() must fail */
    private bool $rollbackOnly = false;

    // Key figures of the last query(), saved along by push/popResult
    public int $affected_rows = 0;
    public int $insert_id = 0;
    /** Rows in the current result (with LIMIT) */
    public int $num_rows = 0;
    /** Total hits without LIMIT - only set if Cms::select() ran with count=true */
    public int $num_rows_unlimited = 0;
    /** LIMIT of the query (page size for CMS:JUMP_*) - only set by Cms::select(), 0 = none */
    public int $num_rows_limit = 0;

    // The constructor expects a Config object
    public function __construct(
        private Config $config,
        private System $system
    )
    {
        $dbConf = $config->get('db');
        $systemConf = $config->get('system');
        if (empty($dbConf['host']) || empty($dbConf['name'])) {
            // no .camindo.php (before the installer) or incomplete db block
            throw new \Exception('Database not configured (.camindo.php missing or incomplete)');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        
        try {
            $this->mysqli = new mysqli(
                $dbConf['host'], 
                $dbConf['user'], 
                $dbConf['pass'], 
                $dbConf['name'],
                $dbConf['port']
            );
            $this->mysqli->set_charset('utf8mb4');

            // SQL mode of the session, independent of the server default (config db.sql_mode)
            if (isset($dbConf['sql_mode'])) {
                $this->mysqli->query("SET SESSION sql_mode = '" . $this->mysqli->real_escape_string((string)$dbConf['sql_mode']) . "'");
            }

            // Run initial queries from the config
            if (!empty($dbConf['init_querys'])) {
                foreach ((array)$dbConf['init_querys'] as $query) {
                    $this->mysqli->query($query);
                }
            }

            // Set timezone
            $timezone = $systemConf['timezone'] ?? 'Europe/Berlin';
            $query = "SET time_zone = '$timezone'";
            $this->mysqli->query($query);
            
        } catch (\mysqli_sql_exception $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }

        $this->debug = !empty($config->get('system.debugsql')) && !empty($config->get('system.development_version'));
    }


    /**
     * Runs a query. Uses prepared statements and caches them.
     *
     * @param string $sql       the SQL template
     * @param string $bindTypes the bind types as a string, e.g. 'is' for int + string
     * @param array $values     the corresponding values as an array
     * @param bool $cache       true: store the stmt template
     *                          Only worthwhile for queries that run often within the same request
     *                          with different values (loops). Note: the key of the cache
     *                          is the SQL string - dynamically built queries fill it uselessly.
     * @return \mysqli_result|bool Returns the result object or true (on success without result).
     *                          On errors no false is returned, instead a
     *                          mysqli_sql_exception is thrown (see class comment).
     */
    public function query(string $sql, string $bindTypes = '', array $values = [], bool $cache = false): \mysqli_result|bool
    {
        if ($this->debug) {
            $this->system->log('sql', $sql . "\n" . print_r($values, true));
        }

        if ($cache) {
            if (!isset($this->stmtCache[$sql])) {
                $stmt = $this->mysqli->prepare($sql);
                $this->stmtCache[$sql] = $stmt;
            } else {
                $stmt = $this->stmtCache[$sql];
            }
        }
        else $stmt = $this->mysqli->prepare($sql);

        if (!empty($bindTypes) && !empty($values)) {
            $stmt->bind_param($bindTypes, ...$values);
        }

        $stmt->execute();

        $this->affected_rows = $stmt->affected_rows;
        $this->insert_id = $stmt->insert_id;

        $result = $stmt->get_result();

        // An array result (setArrayResult) is replaced by this
        $this->arrayResult = null;
        // Convert false to null if there is no result
        $this->currentResult = ($result !== false) ? $result : null;
        $this->num_rows = $this->currentResult ? $this->currentResult->num_rows : 0;

        return $result !== false ? $result : true;
    }

    /**
     * Set a PHP array as the current result.
     *
     * For result sets that do not come from the database - e.g. the languages of the
     * instance from the file cache (Cms::cmsSelectLanguages()) - but should still be read
     * by the result loop of the templates (num_rows + fetch()).
     * Behaves like a query result: pushResult()/popResult() save it along,
     * the next query() replaces it.
     *
     * @param array $rows  list of associative rows
     */
    public function setArrayResult(array $rows): void
    {
        if ($this->currentResult instanceof \mysqli_result) {
            $this->currentResult->close();
        }
        $this->currentResult      = null;
        $this->arrayResult        = array_values($rows);
        $this->arrayPos           = 0;
        $this->affected_rows      = 0;
        $this->insert_id          = 0;
        $this->num_rows           = count($this->arrayResult);
        $this->num_rows_unlimited = $this->num_rows;
        $this->num_rows_limit     = 0;
    }

    /**
     * Puts the current result and the properties on the stack
     * and resets the current level.
     */
    public function pushResult()
    {
        if (count($this->stack) >= self::MAX_STACK_DEPTH) {
            throw new \Exception("Datenbank-Stack Fehler: Maximale Verschachtelungstiefe (" . self::MAX_STACK_DEPTH . ") überschritten. Möglicher Endlos-Loop!");
        }

        $this->stack[] = [
            'result'             => $this->currentResult,
            'array_result'       => $this->arrayResult,
            'array_pos'          => $this->arrayPos,
            'affected_rows'      => $this->affected_rows,
            'insert_id'          => $this->insert_id,
            'num_rows'           => $this->num_rows,
            'num_rows_unlimited' => $this->num_rows_unlimited,
            'num_rows_limit'     => $this->num_rows_limit
        ];

        // Neue Ebene freigeben
        $this->currentResult      = null;
        $this->arrayResult        = null;
        $this->arrayPos           = 0;
        $this->affected_rows      = 0;
        $this->insert_id          = 0;
        $this->num_rows           = 0;
        $this->num_rows_unlimited = 0;
        $this->num_rows_limit     = 0;
    }

    /**
     * Takes the result and the properties back from the stack.
     *
     * @return \mysqli_result|null the restored result object
     */
    public function popResult(): ?\mysqli_result
    {
        if (empty($this->stack)) {
            return null;
        }

        // Free the memory of the current result object
        if ($this->currentResult instanceof \mysqli_result) {
            $this->currentResult->close();
        }
        // Take the last element from the stack
        $state = array_pop($this->stack);

        // Restore values
        $this->currentResult      = $state['result'];
        $this->arrayResult        = $state['array_result'];
        $this->arrayPos           = $state['array_pos'];
        $this->affected_rows      = $state['affected_rows'];
        $this->insert_id          = $state['insert_id'];
        $this->num_rows           = $state['num_rows'];
        $this->num_rows_unlimited = $state['num_rows_unlimited'];
        $this->num_rows_limit     = $state['num_rows_limit'];

        return $this->currentResult;
    }


    /**
     * Fetches the next row as an associative array.
     *
     * @param ?\mysqli_result $dbResult
     * @return ?array
     */
    public function fetch(?\mysqli_result $dbResult = null) : ?array
    {
        if ($dbResult === null && $this->arrayResult !== null) {
            return $this->arrayResult[$this->arrayPos++] ?? null;
        }
        $res = $dbResult ?: $this->currentResult;
        if (!$res) return null;
        return $res->fetch_array(MYSQLI_ASSOC);
    }

    /**
     * Fetches all rows as a flat array (one column)
     */
    public function fetchColumn(?\mysqli_result $dbResult = null) : array
    {
        if ($dbResult === null && $this->arrayResult !== null) {
            return array_map(fn($row) => reset($row), array_slice($this->arrayResult, $this->arrayPos));
        }
        $res = $dbResult ?: $this->currentResult;
        if (!$res) return [];
        return array_column($res->fetch_all(MYSQLI_NUM), 0);
    }

    /**
     * Fetches all rows as a nested array
     * @param ?\mysqli_result $dbResult
     * @return array
     */
    public function fetchAll(?\mysqli_result $dbResult = null) : array
    {
        if ($dbResult === null && $this->arrayResult !== null) {
            $rows = array_slice($this->arrayResult, $this->arrayPos);
            $this->arrayPos = count($this->arrayResult);
            if (!empty($rows) && count(reset($rows)) === 1) {
                return array_map(fn($row) => reset($row), $rows);
            }
            return $rows;
        }
        $res = $dbResult ?: $this->currentResult;
        if (!$res) return [];
        
        // deprecated:
        if ($res->field_count === 1) {
            return array_column($res->fetch_all(MYSQLI_NUM), 0);
        }
        
        return $res->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Fetches exactly two columns as a flat key-value array.
     * (First column is the index, second column is the value)
     *
     * @param ?\mysqli_result $dbResult
     * @return array
     */
    public function fetchPairs(?\mysqli_result $dbResult = null) : array
    {
        $res = $dbResult ?: $this->currentResult;
        if (!$res || $res->field_count !== 2) {
            return [];
        }
        
        return array_column($res->fetch_all(MYSQLI_NUM), 1, 0);
    }

    /**
     * Fetches rows of queries with more than 2 columns and indexes them.
     * If no index is given, the first column is used as the index.
     *
     * @param ?string $indexCol Optional: column name to be used as the array key
     * @param ?\mysqli_result $dbResult
     * @return array
     */
    public function fetchAndIndex(?string $indexCol = null, ?\mysqli_result $dbResult = null) : array
    {
        $res = $dbResult ?: $this->currentResult;
        if (!$res || $res->field_count <= 2) {
            return [];
        }

        // Fetch column metadata to check existence BEFORE fetching the data
        $fields = array_column($res->fetch_fields(), 'name');
        
        if ($indexCol === null) {
            $indexCol = $fields[0] ?? null;
        }
        
        // If the requested column does not exist, abort right away
        if ($indexCol === null || !in_array($indexCol, $fields, true)) {
            return [];
        }
        
        // Only now, with everything validated, load the data
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        if (empty($rows)) {
            return [];
        }
        
        return array_column($rows, null, $indexCol);
    }
    
    /**
     * Fetches the next row as a numeric array.
     *
     * @param ?\mysqli_result $dbResult
     * @return ?array
     */
    public function fetchNum(?\mysqli_result $dbResult = null) : ?array
    {
        if ($dbResult === null && $this->arrayResult !== null) {
            $row = $this->arrayResult[$this->arrayPos++] ?? null;
            return $row === null ? null : array_values($row);
        }
        $res = $dbResult ?: $this->currentResult;
        if (!$res) return null;
        return $res->fetch_array(MYSQLI_NUM);
    }



    /**
     * Frees the memory of the result object.
     * 
     * @param ?\mysqli_result $dbResult
     */
    public function free(?\mysqli_result $dbResult = null) {
        $res = $dbResult ?: $this->currentResult;
        if ($res instanceof \mysqli_result) {
            $res->close();
        }
        // If the internally remembered result is closed, clear the reference
        if ($res === $this->currentResult) {
            $this->currentResult = null;
        }
    }


    /**
     * Delete the templates of the prepared statements
     * Close the MySQL connection
     */
    public function __destruct()
    {
        foreach ($this->stmtCache as $stmt) {
            $stmt->close();
        }
        if ($this->mysqli) {
            $this->mysqli->close();
        }
    }


    // ========== Transactions ==========
    // Nested transactions wait with commit() / rollback() for the return to the outermost level.
    // Handle transactions only through this abstraction class, not directly through mysqli!
    // Run commit inside a try block, rollback inside a catch block!


    /**
     * Start a new database transaction
     */
    /**
     * Run code after the successful commit of the outermost transaction - for
     * side effects outside the database that a rollback could not undo
     * (audit log in the file system, AuditStore). Immediately if no transaction is running.
     * A rollback discards the queued calls.
     */
    public function afterCommit(callable $callback): void
    {
        if ($this->transactionDepth === 0) {
            $callback();
            return;
        }
        $this->afterCommit[] = $callback;
    }

    /** Queued for afterCommit() */
    private array $afterCommit = [];

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            // Only send to MySQL at the first level
            $this->mysqli->begin_transaction();
        }
        $this->transactionDepth++;
    }


    /**
     * Roll back the transaction
     */
    public function rollback(): void
    {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth--;
            if ($this->transactionDepth === 0) {
                // Outermost level: run the rollback
                $this->rollbackOnly = false;
                $this->afterCommit = [];
                $this->mysqli->rollback();
            } else {
                // Inner level: mark the transaction as invalid
                $this->rollbackOnly = true;
            }
        }
    }


    /**
     * Confirm the transaction
     */
    public function commit(): void
    {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth--;
            if ($this->transactionDepth === 0) {
                if ($this->rollbackOnly) {
                    // Someone called rollback() before, never commit
                    $this->rollbackOnly = false;
                    $this->afterCommit = [];
                    $this->mysqli->rollback();
                    throw new \Exception('Transaction failed: Inner process failed and transaction marked as rollback-only.');
                }
                $this->mysqli->commit();
                $callbacks = $this->afterCommit;
                $this->afterCommit = [];
                foreach ($callbacks as $callback) $callback();
            }
        }
    }


    /**
     * Checks whether a transaction is currently running.
     */
    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }
}
