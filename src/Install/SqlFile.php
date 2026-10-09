<?php
/**
 * Eine .sql-Datei Anweisung für Anweisung ausführen.
 *
 * Für schema.sql, seed.sql, dev.sql und die *.sql-Migrationen. Getrennt wird am `;`
 * außerhalb von Strings und Kommentaren - kein multi_query, damit ein Fehler die
 * Anweisung nennt, die ihn ausgelöst hat (Database wirft mysqli_sql_exception).
 *
 * Verstanden werden: 'string', "string", `bezeichner` (mit \-Escapes und
 * verdoppelten Anführungszeichen), -- Zeilenkommentare, # Zeilenkommentare,
 * Blockkommentare. mysqldump-Bedingungskommentare (/*!40101 … *\/) sind
 * Anweisungen und werden ausgeführt. Nicht unterstützt: DELIMITER (Stored
 * Procedures, Trigger) - dafür eine .php-Migration.
 *
 * Kennt der Server die Kollation utf8mb4_0900_ai_ci nicht (MariaDB), wird sie vor dem
 * Ausführen durch seine neutrale ersetzt (Collation::portable()).
 *
 * @see docs/developers/14-installation-und-update.md, 3.2
 */
namespace Camindo\Core\Install;

use Camindo\Core\Collation;
use Camindo\Core\Database;

class SqlFile {

    public function __construct(private Database $db)
    {
    }

    /**
     * Datei ausführen.
     *
     * @param string        $file    absoluter Pfad
     * @param callable|null $logger  function(string $line): void - je Anweisung ein Auszug
     * @return int                   Zahl der ausgeführten Anweisungen
     * @throws \RuntimeException     Datei fehlt; SQL-Fehler kommen als mysqli_sql_exception
     *                               mit der Anweisung im Text
     */
    public function run(string $file, ?callable $logger = null): int
    {
        if (!is_file($file)) throw new \RuntimeException("sql file missing: $file");
        $sql = (string)file_get_contents($file);
        if (str_contains($sql, 'utf8mb4_0900_ai_ci')) {
            $sql = Collation::portable($sql, Collation::available($this->db));
        }
        $count = 0;
        foreach ($this->split($sql) as $statement) {
            $count++;
            try {
                $this->db->query($statement);
            } catch (\mysqli_sql_exception $e) {
                throw new \mysqli_sql_exception(
                    $e->getMessage() . ' in ' . basename($file) . ', statement ' . $count . ': '
                    . $this->excerpt($statement),
                    (int)$e->getCode()
                );
            }
            if ($logger) $logger($this->excerpt($statement));
        }
        return $count;
    }

    /**
     * SQL-Text in Anweisungen zerlegen (ohne abschließendes `;`, ohne Leerzeilen).
     *
     * @return string[]
     */
    public function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            // Zeilenkommentar: "-- " oder "#"
            if (($c === '-' && $next === '-' && in_array($sql[$i + 2] ?? "\n", [" ", "\t", "\n", "\r"], true))
                || $c === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            // Blockkommentar - außer mysqldump-Bedingungskommentar /*! … */
            if ($c === '/' && $next === '*' && ($sql[$i + 2] ?? '') !== '!') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }
            // String / Bezeichner
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $current .= $c;
                $i++;
                while ($i < $len) {
                    $ch = $sql[$i];
                    $current .= $ch;
                    if ($ch === '\\' && $quote !== '`') {           // Escape
                        $current .= $sql[$i + 1] ?? '';
                        $i += 2;
                        continue;
                    }
                    if ($ch === $quote) {
                        if (($sql[$i + 1] ?? '') === $quote) {    // verdoppelt
                            $current .= $quote;
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }
            // Ende der Anweisung
            if ($c === ';') {
                $statement = trim($current);
                if ($statement !== '') $statements[] = $statement;
                $current = '';
                $i++;
                continue;
            }
            $current .= $c;
            $i++;
        }
        $statement = trim($current);
        if ($statement !== '') $statements[] = $statement;
        return $statements;
    }

    private function excerpt(string $statement): string
    {
        $one = preg_replace('/\s+/', ' ', $statement);
        return strlen($one) > 90 ? substr($one, 0, 87) . '...' : $one;
    }
}
