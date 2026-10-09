<?php
/**
 * What a migration or a component installer gets to work with.
 *
 * A PHP migration (Core/install/migrations/{version}.php or
 * {component}/install/migrations/{version}.php) returns a closure that
 * receives exactly this object:
 *
 *     return static function (InstallContext $ctx): void {
 *         if (!$ctx->columnExists('record', 'owner_id')) {
 *             $ctx->db->query("ALTER TABLE `record` ADD `owner_id` bigint UNSIGNED NULL");
 *         }
 *         $ctx->log('owner_id added');
 *     };
 *
 * The helpers tableExists()/columnExists()/indexExists() are what each of the
 * earlier Core/cron/migrate_*.php had rebuilt themselves. log() writes via the
 * logger of the caller - on the CLI to STDOUT, in the backend to the aside.
 *
 * No backend classes (Backend, Session) - migrations run on the
 * command line. See developer docs, chapter 09 (Installation and update).
 */
namespace Camindo\Core\Install;

use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\System;
use Closure;

class InstallContext {

    /**
     * @param string  $component  component name, '' for the core
     * @param string  $path       directory of the component or core
     * @param Closure $logger     function(string $line): void
     */
    public function __construct(
        public readonly Database $db,
        public readonly Config $config,
        public readonly System $system,
        public readonly string $component,
        public readonly string $path,
        private readonly Closure $logger,
    )
    {
    }

    public function log(string $line): void
    {
        ($this->logger)($line);
    }

    public function isCore(): bool
    {
        return $this->component === '';
    }

    // ==== Schema-Helfer ====

    // SHOW statements cannot be prepared with placeholders - the names
    // are therefore checked (ident()) and inserted directly.

    public function tableExists(string $table): bool
    {
        $this->db->query("SHOW TABLES LIKE '" . $this->ident($table) . "'");
        return $this->db->num_rows > 0;
    }

    public function columnExists(string $table, string $column): bool
    {
        if (!$this->tableExists($table)) return false;
        $this->db->query("SHOW COLUMNS FROM `" . $this->ident($table) . "` LIKE '" . $this->ident($column) . "'");
        return $this->db->num_rows > 0;
    }

    public function indexExists(string $table, string $index): bool
    {
        if (!$this->tableExists($table)) return false;
        $this->db->query("SHOW INDEX FROM `" . $this->ident($table) . "` WHERE Key_name = '" . $this->ident($index) . "'");
        return $this->db->num_rows > 0;
    }

    /**
     * Execute SQL file statement by statement (SqlFile).
     *
     * @return int  number of statements
     */
    public function runSqlFile(string $file): int
    {
        return (new SqlFile($this->db))->run($file, fn(string $line) => $this->log($line));
    }

    private function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("invalid identifier '$name'");
        }
        return $name;
    }
}
