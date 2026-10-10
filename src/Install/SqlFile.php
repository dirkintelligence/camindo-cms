<?php
/**
 * Execute a .sql file statement by statement.
 *
 * For schema.sql, seed.sql, dev.sql and the *.sql migrations. Splitting happens at the `;`
 * outside strings and comments - no multi_query, so that an error names the
 * statement that caused it (Database throws mysqli_sql_exception).
 *
 * Understood are: 'string', "string", `identifier` (with \ escapes and
 * doubled quotes), -- line comments, # line comments,
 * block comments. mysqldump conditional comments (/*!40101 … *\/) are
 * statements and are executed. Not supported: DELIMITER (stored
 * procedures, triggers) - use a .php migration for that.
 *
 * If the server does not know the collation utf8mb4_0900_ai_ci (MariaDB), it is replaced before
 * execution by its neutral equivalent (Collation::portable()). MariaDB has no multi-valued
 * indexes (KEY … ((cast(… as char(32) array)))) - they are left out there
 * (withoutMultiValuedIndexes()); JSON_CONTAINS then works without the index.
 *
 * @see developer docs, chapter 09 (Installation and update)
 */
namespace Camindo\Core\Install;

use Camindo\Core\Collation;
use Camindo\Core\Database;

class SqlFile {

    public function __construct(private Database $db)
    {
    }

    /**
     * Execute the file.
     *
     * @param string        $file    absolute path
     * @param callable|null $logger  function(string $line): void - an excerpt per statement
     * @return int                   number of executed statements
     * @throws \RuntimeException     file missing; SQL errors come as mysqli_sql_exception
     *                               with the statement in the text
     */
    public function run(string $file, ?callable $logger = null): int
    {
        if (!is_file($file)) throw new \RuntimeException("sql file missing: $file");
        $sql = (string)file_get_contents($file);
        if (str_contains($sql, 'utf8mb4_0900_ai_ci')) {
            $sql = Collation::portable($sql, Collation::available($this->db));
        }
        if (stripos($sql, ' array)') !== false && $this->isMariaDb()) {
            $sql = self::withoutMultiValuedIndexes($sql);
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
     * Remove multi-valued index lines from CREATE TABLE statements (MySQL 8 only),
     * including a comma that would then precede the closing parenthesis.
     */
    public static function withoutMultiValuedIndexes(string $sql): string
    {
        $stripped = (string)preg_replace('/^[ \t]*(?:UNIQUE[ \t]+)?KEY[ \t]+`[^`]+`[ \t]*\(\(cast\(.*\barray\)\)\),?[ \t]*\r?\n/mi', '', $sql);
        if ($stripped === $sql) return $sql;
        $sql = $stripped;
        return (string)preg_replace('/,(\s*\r?\n\)[ \t]*ENGINE)/i', '$1', $sql);
    }

    private function isMariaDb(): bool
    {
        $this->db->pushResult();
        try {
            $this->db->query('SELECT VERSION()');
            return stripos((string)($this->db->fetchNum()[0] ?? ''), 'mariadb') !== false;
        } finally {
            $this->db->popResult();
        }
    }

    /**
     * Split SQL text into statements (without the closing `;`, without blank lines).
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

            // line comment: "-- " or "#"
            if (($c === '-' && $next === '-' && in_array($sql[$i + 2] ?? "\n", [" ", "\t", "\n", "\r"], true))
                || $c === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            // block comment - except mysqldump conditional comment /*! … */
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
            // end of statement
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
