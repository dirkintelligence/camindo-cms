<?php
/**
 * Table `system` - key/value store of the core.
 *
 *     core_version   state to which the database is migrated (pointer, developer docs, chapter 09 (Installation and update))
 *     installed_at   Installer
 *     upgraded_at    letztes camindo.php upgrade
 *
 * Part of schema.sql since the first release (9.0.0). Without this table there was no version state in the
 * database - exists() says whether the table is there; UpgradeCommand then takes
 * 9.0.0 as the starting state.
 *
 * @see developer docs, chapter 09 (Installation and update)
 */
namespace Camindo\Core\Install;

use Camindo\Core\Database;

class SystemTable {

    private ?bool $exists = null;

    public function __construct(private Database $db)
    {
    }

    public function exists(): bool
    {
        if ($this->exists === null) {
            $this->db->query("SHOW TABLES LIKE 'system'");
            $this->exists = $this->db->num_rows > 0;
        }
        return $this->exists;
    }

    public function get(string $key): ?string
    {
        if (!$this->exists()) return null;
        $this->db->query("SELECT `value` FROM `system` WHERE `key` = ?", 's', [$key]);
        $row = $this->db->fetchNum();
        return $row === null ? null : (string)$row[0];
    }

    public function set(string $key, string $value): void
    {
        $this->db->query(
            "INSERT INTO `system` (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            'ss', [$key, $value]
        );
    }

    /**
     * All entries: key => value
     */
    public function all(): array
    {
        if (!$this->exists()) return [];
        $this->db->query("SELECT `key`, `value` FROM `system` ORDER BY `key`");
        return $this->db->fetchPairs();
    }
}
