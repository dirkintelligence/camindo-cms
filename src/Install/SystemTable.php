<?php
/**
 * Tabelle `system` - Schlüssel/Wert-Speicher des Cores.
 *
 *     core_version   Stand, auf den die Datenbank migriert ist (Zeiger, 14 §3.3)
 *     installed_at   Installer
 *     upgraded_at    letztes camindo.php upgrade
 *
 * Teil von schema.sql seit dem ersten Release (9.0.0). Ohne diese Tabelle gab es keinen Versionsstand in der
 * Datenbank - exists() sagt, ob die Tabelle da ist; UpgradeCommand nimmt dann
 * 9.0.0 als Ausgangsstand an.
 *
 * @see docs/developers/14-installation-und-update.md, 2.1
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
     * Alle Einträge: key => value
     */
    public function all(): array
    {
        if (!$this->exists()) return [];
        $this->db->query("SELECT `key`, `value` FROM `system` ORDER BY `key`");
        return $this->db->fetchPairs();
    }
}
