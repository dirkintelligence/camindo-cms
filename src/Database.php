<?php
/**
 * Datenbank-Abstraktion über mysqli. Instanzname im Code: $db.
 *
 * Alles, was das CMS an SQL absetzt, läuft hier durch. Die Klasse macht drei Dinge, die
 * mysqli allein nicht mitbringt: Prepared Statements mit einem einzigen Aufruf,
 * einen Stapel für verschachtelte Ergebnisse und verschachtelbare Transaktionen.
 *
 * PREPARED STATEMENTS
 * --------------------
 *     $db->query("SELECT * FROM `admin` WHERE `admin_id` = ?", 'i', [$id]);
 *     $data = $db->fetch();
 *
 * Werte gehören IMMER in $values, nie in den SQL-String. Bezeichner (Tabellen- und
 * Spaltennamen) lassen sich nicht binden - die werden von den Aufrufern vorher mit
 * preg_replace('/[^a-z0-9_]/', ...) bereinigt.
 *
 * Bind-Typen wie bei mysqli: 'i' int, 'd' double, 's' string, 'b' blob.
 * Dataset::getBindType() leitet sie aus dem Feldtyp des Schemas ab.
 *
 * DAS "AKTUELLE ERGEBNIS"
 * --------------------
 * query() merkt sich sein Ergebnis intern. Die fetch*-Methoden arbeiten ohne Parameter
 * darauf weiter; ebenso werden $num_rows, $affected_rows und $insert_id nach jedem
 * query() neu gesetzt. Das hält den Aufrufercode kurz, heißt aber auch: die nächste
 * Abfrage überschreibt den Zustand.
 *
 * ERGEBNIS-STAPEL (pushResult / popResult)
 * --------------------
 * Genau deshalb gibt es den Stapel. Wer eine Unterabfrage startet, während er noch über
 * ein Ergebnis iteriert, klammert sie ein:
 *
 *     $db->pushResult();          // laufendes Ergebnis + Zähler sichern
 *     $cms->select([...]);        // Unterabfrage
 *     while ($row = $cms->fetch()) { ... }
 *     $db->popResult();           // vorherigen Zustand wiederherstellen
 *
 * Der Template-Compiler setzt das bei CMS:SELECT automatisch (php/push_result.php und
 * php/pop_result.php); Dataset::load(), save() und transactional() tun es ebenfalls.
 * MAX_STACK_DEPTH bricht bei 50 Ebenen ab - das ist eher eine Endlosschleifen-Bremse
 * als eine echte Grenze.
 *
 * TRANSAKTIONEN
 * --------------------
 * beginTransaction() / commit() / rollback() zählen mit ($transactionDepth). An MySQL
 * geht nur die äußerste Ebene, verschachtelte Aufrufe erhöhen bzw. senken den Zähler.
 * Ruft eine innere Ebene rollback(), wird die Transaktion als $rollbackOnly markiert:
 * das äußere commit() rollt dann trotzdem zurück und wirft - eine teilweise
 * geschriebene Transaktion kann so nicht unbemerkt committet werden.
 *
 * Transaktionen ausschließlich über diese Klasse steuern, nie direkt über mysqli,
 * sonst stimmt der Zähler nicht mehr. Dataset::transactional() kapselt das Übliche.
 *
 * FEHLERVERHALTEN
 * --------------------
 * mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT) ist gesetzt: SQL-Fehler
 * werfen eine mysqli_sql_exception, es gibt keine stillen false-Rückgaben. Innerhalb
 * von Dataset fängt transactional() das ab und rollt zurück.
 *
 * DEBUGGING
 * --------------------
 * system.debugsql = true in .camindo.php protokolliert jede Query samt Werten nach
 * var/log/ (System::log('sql', ...)).
 * Setzt system.development_version = true voraus.
 */
namespace Camindo\Core;
use \mysqli;

class Database {
    /** Reißleine gegen Endlosschleifen bei push/popResult - keine fachliche Grenze */
    const MAX_STACK_DEPTH = 50;

    private mysqli $mysqli;
    /** Wiederverwendete Statements, nur bei query(..., cache: true) befüllt */
    private array $stmtCache = [];
    /** Gesicherte Ergebniszustände, siehe pushResult() */
    private array $stack = [];
    /** Ergebnis der letzten query() - Grundlage aller fetch*-Aufrufe ohne Parameter */
    private ?\mysqli_result $currentResult = null;
    /**
     * Ergebnis aus einem PHP-Array statt aus der Datenbank, siehe setArrayResult().
     * Solange gesetzt, lesen fetch() / fetchNum() / fetchAll() / fetchColumn() daraus.
     */
    private ?array $arrayResult = null;
    private int $arrayPos = 0;
    private bool $debug = false;
    /** Verschachtelungstiefe der Transaktionen; nur 0 -> 1 startet wirklich eine */
    public int $transactionDepth = 0;
    /** true: eine innere Ebene hat rollback() gerufen, das äußere commit() muss scheitern */
    private bool $rollbackOnly = false;

    // Kennzahlen der letzten query(), von push/popResult mitgesichert
    public int $affected_rows = 0;
    public int $insert_id = 0;
    /** Zeilen im aktuellen Ergebnis (mit LIMIT) */
    public int $num_rows = 0;
    /** Gesamttreffer ohne LIMIT - nur gesetzt, wenn Cms::select() mit count=true lief */
    public int $num_rows_unlimited = 0;
    /** LIMIT der Abfrage (Seitengröße für CMS:JUMP_*) - nur von Cms::select() gesetzt, 0 = ohne */
    public int $num_rows_limit = 0;

    // Der Konstruktor erwartet ein Config-Objekt
    public function __construct(
        private Config $config,
        private System $system
    )
    {
        $dbConf = $config->get('db');
        $systemConf = $config->get('system');
        if (empty($dbConf['host']) || empty($dbConf['name'])) {
            // keine .camindo.php (vor dem Installer) oder db-Block unvollständig
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

            // SQL-Modus der Sitzung unabhängig vom Server-Standard (Config db.sql_mode)
            if (isset($dbConf['sql_mode'])) {
                $this->mysqli->query("SET SESSION sql_mode = '" . $this->mysqli->real_escape_string((string)$dbConf['sql_mode']) . "'");
            }

            // Initiale Queries ausführen aus der Config 
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
     * Führt eine Query aus. Nutzt Prepared Statements und cacht diese.
     * 
     * @param string $sql       Das SQL-Template
     * @param string $bindTypes Die Bind-Typen als String, z. B. 'is' für int + string
     * @param array $values     Die zugehörigen Werte als Array
     * @param bool $cache       true: speichere stmt-Template
     *                          Lohnt nur für Abfragen, die im selben Request oft mit
     *                          unterschiedlichen Werten laufen (Schleifen). Achtung:
     *                          Schlüssel des Caches ist der SQL-String - dynamisch
     *                          zusammengebaute Queries füllen ihn sinnlos auf.
     * @return \mysqli_result|bool Gibt das Result-Objekt oder true (bei Erfolg ohne Result) zurück.
     *                          Bei Fehlern kommt kein false zurück, sondern es wird
     *                          eine mysqli_sql_exception geworfen (siehe Klassenkommentar).
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

        // Ein Array-Ergebnis (setArrayResult) ist damit abgelöst
        $this->arrayResult = null;
        // Wandle false in null um, falls kein Ergebnis
        $this->currentResult = ($result !== false) ? $result : null;
        $this->num_rows = $this->currentResult ? $this->currentResult->num_rows : 0;

        return $result !== false ? $result : true;
    }

    /**
     * Ein PHP-Array als aktuelles Ergebnis einsetzen.
     *
     * Für Ergebnismengen, die nicht aus der Datenbank kommen - etwa die Sprachen der
     * Instanz aus dem Dateicache (Cms::cmsSelectLanguages()) - und trotzdem von der
     * Ergebnisschleife der Templates (num_rows + fetch()) gelesen werden sollen.
     * Verhält sich wie ein Query-Ergebnis: pushResult()/popResult() sichern es mit,
     * die nächste query() löst es ab.
     *
     * @param array $rows  Liste assoziativer Zeilen
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
     * Legt das aktuelle Resultat und die Eigenschaften auf den Stapel
     * und setzt die aktuelle Ebene zurück.
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
     * Holt das Resultat und die Eigenschaften vom Stapel zurück.
     * 
     * @return \mysqli_result|null Das wiederhergestellte Result-Objekt
     */
    public function popResult(): ?\mysqli_result
    {
        if (empty($this->stack)) {
            return null;
        }

        // Speicher vom aktuellen Result-Objekt freigeben
        if ($this->currentResult instanceof \mysqli_result) {
            $this->currentResult->close();
        }
        // Letztes Element vom Stapel holen
        $state = array_pop($this->stack);

        // Werte wiederherstellen
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
     * Holt die nächste Zeile als assoziatives Array.
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
     * Holt alle Zeilen als flaches Array (eine Spalte)
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
     * Holt alle Zeilen als verschachteltes Array
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
     * Holt genau zwei Spalten als flaches Key-Value-Array.
     * (Erste Spalte ist der Index, zweite Spalte ist der Wert)
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
     * Holt Zeilen bei Abfragen mit mehr als 2 Spalten und indiziert sie.
     * Wenn kein Index angegeben ist, wird die erste Spalte als Index genutzt.
     *
     * @param ?string $indexCol Optional: Spaltenname, der als Array-Key genutzt werden soll
     * @param ?\mysqli_result $dbResult
     * @return array
     */
    public function fetchAndIndex(?string $indexCol = null, ?\mysqli_result $dbResult = null) : array
    {
        $res = $dbResult ?: $this->currentResult;
        if (!$res || $res->field_count <= 2) {
            return [];
        }

        // Spalten-Metadaten holen, um Existenz VOR dem Daten-Fetch zu prüfen
        $fields = array_column($res->fetch_fields(), 'name');
        
        if ($indexCol === null) {
            $indexCol = $fields[0] ?? null;
        }
        
        // Wenn die gewünschte Spalte nicht existiert, direkt abbrechen
        if ($indexCol === null || !in_array($indexCol, $fields, true)) {
            return [];
        }
        
        // Erst jetzt, wo alles validiert ist, die Daten laden
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        if (empty($rows)) {
            return [];
        }
        
        return array_column($rows, null, $indexCol);
    }
    
    /**
     * Holt die nächste Zeile als numerisches Array.
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
     * Gibt den Speicher des Result-Objekts frei.
     * 
     * @param ?\mysqli_result $dbResult
     */
    public function free(?\mysqli_result $dbResult = null) {
        $res = $dbResult ?: $this->currentResult;
        if ($res instanceof \mysqli_result) {
            $res->close();
        }
        // Wenn das intern gemerkte Result geschlossen wird, Referenz löschen
        if ($res === $this->currentResult) {
            $this->currentResult = null;
        }
    }


    /**
     * Lösche die Templates der Prepared Statements
     * Schließe die MySQL-Verbindung
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


    // ========== Transaktionen ========== 
    // Verschachtelte Transaktionen warten mit commit() / rollback() auf Rückkehr zur äußersten Ebene.
    // Transaktionen nur über diese Abstraktionsklasse behanden, nicht direkt über mysqli!
    // commit innerhalb try-Block ausführen, rollback innerhalb catch-Block!


    /**
     * Starten einer neue Datenbank-Transaktion
     */
    /**
     * Code nach dem erfolgreichen Commit der äußersten Transaktion ausführen - für
     * Seiteneffekte außerhalb der Datenbank, die ein Rollback nicht zurücknehmen
     * könnte (Audit-Log im Dateisystem, AuditStore). Ohne laufende Transaktion sofort.
     * Ein Rollback verwirft die vorgemerkten Aufrufe.
     */
    public function afterCommit(callable $callback): void
    {
        if ($this->transactionDepth === 0) {
            $callback();
            return;
        }
        $this->afterCommit[] = $callback;
    }

    /** Vorgemerkt für afterCommit() */
    private array $afterCommit = [];

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            // Nur bei der ersten Ebene wirklich an MySQL senden
            $this->mysqli->begin_transaction();
        }
        $this->transactionDepth++;
    }


    /**
     * Transaktion rückabwickeln
     */
    public function rollback(): void
    {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth--;
            if ($this->transactionDepth === 0) {
                // Äußerste Ebene: Rollback ausführen
                $this->rollbackOnly = false;
                $this->afterCommit = [];
                $this->mysqli->rollback();
            } else {
                // Innere Ebene: Transaktion ungültig markieren
                $this->rollbackOnly = true;
            }
        }
    }


    /**
     * Transaktion bestätigen
     */
    public function commit(): void
    {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth--;
            if ($this->transactionDepth === 0) {
                if ($this->rollbackOnly) {
                    // Jemand hat vorher rollback() gerufen, nie committen
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
     * Prüft, ob aktuell eine Transaktion läuft.
     */
    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }
}
