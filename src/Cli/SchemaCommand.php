<?php
/**
 * schema export - schema.sql, seed.sql und dev.sql aus der Entwicklungsdatenbank
 *
 * Nur mit system.development_version. Schreibt nach Core/install/database/:
 *
 *     schema.sql   nur Struktur (alle Tabellen), normalisiert: ohne AUTO_INCREMENT-
 *                  Zähler und DEFINER, damit der Git-Diff einer Migration lesbar bleibt
 *     seed.sql     Stammdaten einer leeren Installation: status, language (en, de),
 *                  group 1 (Superuser), component (Core-Zeilen), fieldname `fulltext`,
 *                  die Standard-Policies des Moduls Privacy
 *     dev.sql      alle Daten der Entwicklungsdatenbank außer Sessions/Tokens (enthält
 *                  die Stammdaten aus seed.sql; install --fixtures lädt nur dev.sql) -
 *                  für `install --fixtures` und den Docker-Erststart
 *
 * Regel: Jede Core-Migration wird von einem `schema export` im selben Commit
 * begleitet (14 §2.3).
 */
namespace Camindo\Core\Cli;

use Camindo\Core\Backup;
use Camindo\Core\Config;

class SchemaCommand extends Command {

    // Tabellen und Bedingungen der Stammdaten
    private const SEED = [
        'status'    => null,
        // die mitgelieferten Sprachen (je eine Datei in Core/lang und Core/backend/lang)
        'language'  => "code IN ('ar_SA', 'bn_BD', 'zh_CN', 'de_DE', 'en_US', 'es_ES', 'fr_FR', 'hi_IN', 'ja_JP', 'ko_KR', 'pt_BR', 'ur_PK')",
        'group'     => "group_id = 1",
        'component' => "core = 1",
        // reservierter Name des Volltext-Standardfelds (ohne `field`-Zeile)
        'fieldname' => "name = 'fulltext'",
        // Standard-Policies (DeletePolicy::STANDARD) - jeder Bereich braucht eine
        'privacy_policy' => "standard <> ''",
    ];

    // Nicht in dev.sql: Laufzeitdaten; `system` schreibt der Installer selbst
    private const DEV_EXCLUDE = ['admin_session', 'admin_login_attempt', 'user_session', 'user_login_attempt', 'user_token', 'bgproc', 'system', 'history', 'audit_log'];

    public function run(array $args, array $options): int
    {
        if (($args[0] ?? '') !== 'export') {
            return $this->usage('usage: schema export');
        }
        $config = $this->get(Config::class);
        if (empty($config->get('system.development_version'))) {
            return $this->usage('schema export runs only with system.development_version');
        }
        $backup = $this->get(Backup::class);
        $dir = (string)$config->get('paths.core_dir') . '/install/database';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            $this->err("cannot create $dir");
            return Cli::ERROR;
        }
        $common = ['--skip-comments', '--skip-dump-date', '--default-character-set=utf8mb4'];
        // Daten: eine INSERT-Zeile je Datensatz (lesbarer Diff), Binärwerte als 0x…,
        // ohne LOCK TABLES / DISABLE KEYS (der Installer führt Anweisung für Anweisung aus)
        $data = ['--no-create-info', '--skip-triggers', '--complete-insert', '--skip-extended-insert',
                 '--hex-blob', '--skip-add-locks', '--skip-disable-keys'];
        $tmp = $dir . '/.export.tmp';
        $dbName = (string)$config->get('db.name');

        // Tabellen installierter Zusatzkomponenten (component.tables) gehören nicht
        // zum Core - weder in schema.sql noch in dev.sql
        $db = $this->get(\Camindo\Core\Database::class);
        $db->query("SELECT `name`, `tables` FROM `component` WHERE `core` = 0 AND `tables` IS NOT NULL");
        $componentTables = [];
        foreach ($db->fetchAll() as $row) {
            foreach ((array)json_decode((string)$row['tables'], true) as $t) {
                if (preg_match('/^[A-Za-z0-9_]+$/', (string)$t)) $componentTables[] = "--ignore-table=$dbName.$t";
            }
        }
        if ($componentTables) $this->out('ignoring component tables: ' . implode(', ', array_map(fn($a) => substr($a, strrpos($a, '.') + 1), $componentTables)));

        // schema.sql
        $backup->mysqldump(array_merge($common, ['--no-data', '--skip-triggers'], $componentTables), $tmp);
        $sql = (string)file_get_contents($tmp);
        $sql = preg_replace('/ AUTO_INCREMENT=\d+/', '', $sql);
        $sql = preg_replace('/\/\*!50013 DEFINER=[^*]*\*\/\s*/', '', $sql);
        $sql = preg_replace('/ DEFINER=`[^`]*`@`[^`]*`/', '', $sql);
        $this->write($dir . '/schema.sql', "-- camindo " . CAMINDO_VERSION . " - Struktur. Generiert von `camindo.php schema export`.\n\n" . $sql);

        // seed.sql
        // Rahmen wie mysqldump: NO_AUTO_VALUE_ON_ZERO, damit status_id 0
        // nicht zum nächsten AUTO_INCREMENT-Wert werden
        $seed = "-- camindo " . CAMINDO_VERSION . " - Stammdaten einer leeren Installation. Generiert von `camindo.php schema export`.\n\n"
            . "/*!40101 SET NAMES utf8mb4 */;\n"
            . "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n"
            . "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n";
        foreach (self::SEED as $table => $where) {
            $args = array_merge($common, $data);
            if ($where !== null) $args[] = '--where=' . $where;
            $args[] = $table;
            $backup->mysqldump($args, $tmp);
            $seed .= "\n-- $table\n" . $this->inserts((string)file_get_contents($tmp));
        }
        $seed .= "\n/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n";
        $this->write($dir . '/seed.sql', $seed);

        // dev.sql - vollständiger Datenbestand (inkl. Stammdaten); Zusatzkomponenten
        // nur als Verzeichnis-Eintrag? Nein: ihre Zeilen und Tabellen bleiben draußen,
        // `component install` legt sie auf dem frischen System regulär an
        $args = array_merge($common, $data, $componentTables);
        foreach (array_merge(self::DEV_EXCLUDE, ['component']) as $table) $args[] = "--ignore-table=$dbName.$table";
        $backup->mysqldump($args, $tmp);
        $dev = (string)file_get_contents($tmp);
        $backup->mysqldump(array_merge($common, $data, ['--where=core = 1', 'component']), $tmp);
        $dev .= "\n-- component (Core)\n" . $this->inserts((string)file_get_contents($tmp));
        $this->write($dir . '/dev.sql', "-- camindo " . CAMINDO_VERSION . " - Entwicklungsdaten (install --fixtures). Generiert von `camindo.php schema export`.\n\n"
            . $dev);
        @unlink($tmp);

        foreach (['schema.sql', 'seed.sql', 'dev.sql'] as $f) {
            $this->out(sprintf('%-11s %8d bytes', $f, filesize("$dir/$f")));
        }
        return Cli::OK;
    }

    /**
     * Nur die INSERT-Anweisungen eines Dumps (ohne SET-/LOCK-Rahmen).
     */
    private function inserts(string $dump): string
    {
        $lines = [];
        foreach (explode("\n", $dump) as $line) {
            if (str_starts_with($line, 'INSERT INTO')) $lines[] = $line;
        }
        return $lines ? implode("\n", $lines) . "\n" : "-- (keine Zeilen)\n";
    }

    private function write(string $file, string $content): void
    {
        if (@file_put_contents($file, $content, LOCK_EX) === false) {
            throw new \RuntimeException("cannot write $file");
        }
    }
}
