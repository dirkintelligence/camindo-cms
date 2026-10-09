<?php
/**
 * Was eine Migration oder ein Komponenten-Installer zum Arbeiten bekommt.
 *
 * Eine PHP-Migration (Core/install/migrations/{version}.php bzw.
 * {Komponente}/install/migrations/{version}.php) gibt eine Closure zurück, die
 * genau dieses Objekt erhält:
 *
 *     return static function (InstallContext $ctx): void {
 *         if (!$ctx->columnExists('record', 'owner_id')) {
 *             $ctx->db->query("ALTER TABLE `record` ADD `owner_id` bigint UNSIGNED NULL");
 *         }
 *         $ctx->log('owner_id added');
 *     };
 *
 * Die Helfer tableExists()/columnExists()/indexExists() sind das, was jedes der
 * früheren Core/cron/migrate_*.php selbst nachgebaut hatten. log() schreibt über den
 * Logger des Aufrufers - auf der CLI nach STDOUT, im Backend ins Aside.
 *
 * Keine Backend-Klassen (Backend, Session) - Migrationen laufen auf der
 * Kommandozeile. Siehe docs/developers/14-installation-und-update.md, 3.2.
 */
namespace Camindo\Core\Install;

use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\System;
use Closure;

class InstallContext {

    /**
     * @param string  $component  Komponentenname, '' für den Core
     * @param string  $path       Verzeichnis der Komponente bzw. des Cores
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

    // SHOW-Anweisungen lassen sich nicht mit Platzhaltern vorbereiten - die Namen
    // werden deshalb geprüft (ident()) und direkt eingesetzt.

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
     * SQL-Datei Anweisung für Anweisung ausführen (SqlFile).
     *
     * @return int  Zahl der Anweisungen
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
