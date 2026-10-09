<?php
/**
 * schema export - schema.sql, seed.sql and dev.sql from the development database
 *
 * Only with system.development_version. Writes to Core/install/database/:
 *
 *     schema.sql   structure only (all tables), normalized: without AUTO_INCREMENT-
 *                  counters and DEFINER, so that the Git diff of a migration stays readable
 *     seed.sql     master data of an empty installation: status, language (en, de),
 *                  group 1 (superuser), component (core rows), fieldname `fulltext`,
 *                  the default policies of the module Privacy
 *     dev.sql      all data of the development database except sessions/tokens (contains
 *                  the master data from seed.sql; install --fixtures loads only dev.sql) -
 *                  for `install --fixtures` and the first Docker start
 *
 * Rule: every core migration is accompanied by a `schema export` in the same commit
 * (developer docs, chapter 09 (Installation and update)).
 */
namespace Camindo\Core\Cli;

use Camindo\Core\Backup;
use Camindo\Core\Config;

class SchemaCommand extends Command {

    // Tables and conditions of the master data
    private const SEED = [
        'status'    => null,
        // the bundled languages (one file each in Core/lang and Core/backend/lang)
        'language'  => "code IN ('ar_SA', 'bn_BD', 'zh_CN', 'de_DE', 'en_US', 'es_ES', 'fr_FR', 'hi_IN', 'ja_JP', 'ko_KR', 'pt_BR', 'ur_PK')",
        'group'     => "group_id = 1",
        'component' => "core = 1",
        // reserved name of the default full-text field (without `field` row)
        'fieldname' => "name = 'fulltext'",
        // default policies (DeletePolicy::STANDARD) - every area needs one
        'privacy_policy' => "standard <> ''",
    ];

    // Not in dev.sql: runtime data; `system` is written by the installer itself
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
        // Data: one INSERT row per record (readable diff), binary values as 0x…,
        // without LOCK TABLES / DISABLE KEYS (the installer executes statement by statement)
        $data = ['--no-create-info', '--skip-triggers', '--complete-insert', '--skip-extended-insert',
                 '--hex-blob', '--skip-add-locks', '--skip-disable-keys'];
        $tmp = $dir . '/.export.tmp';
        $dbName = (string)$config->get('db.name');

        // Tables of installed add-on components (component.tables) do not belong
        // to the core - neither in schema.sql nor in dev.sql
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
        // frame like mysqldump: NO_AUTO_VALUE_ON_ZERO, so that status_id 0
        // not to the next AUTO_INCREMENT value
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

        // dev.sql - complete data set (incl. master data); add-on components
        // only as a directory entry? No: their rows and tables stay out,
        // `component install` creates them regularly on the fresh system
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
     * Only the INSERT statements of a dump (without SET/LOCK frame).
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
