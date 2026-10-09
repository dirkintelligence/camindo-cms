<?php
/**
 * Passkeys of the admins (table admin_passkey) - developer docs, chapter 13 (Security: authentication).
 *
 * One key per row: credential id, public key (PEM), method,
 * signature counter, transports, label. Every row carries a signature
 * (Integrity::passkeySignature()); a row with a wrong signature does not count and
 * is logged in 'security'. Lookups use credential_hash (SHA-256 of the id,
 * unique) - the id itself is up to 1023 bytes long.
 *
 * Knows no login logic - that lives in WebAuthnFactor.
 *
 * @see Camindo\Core\Modules\Admin\WebAuthnFactor
 */
namespace Camindo\Core\Modules\Admin;

use Camindo\Core\Database;
use Camindo\Core\Integrity;
use Camindo\Core\System;

class Passkeys {

    /** Maximum number of passkeys per account */
    public const MAX_PER_ADMIN = 20;

    public function __construct(
        private Database $db,
        private Integrity $integrity,
        private System $system,
    )
    {
    }

    /**
     * Valid passkeys of an admin, most recently created first.
     *
     * @return array  list of passkey_id, credential_id, public_key, alg, sign_count,
     *                transports (string[]), label, created_at, last_used_at (UTC)
     */
    public function forAdmin(int $adminId): array
    {
        if ($adminId < 1) return [];
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT * FROM `admin_passkey` WHERE `admin_id` = ? ORDER BY `created_at` DESC, `passkey_id` DESC",
                'i', [$adminId]
            );
            $rows = $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
        $list = [];
        foreach ($rows as $row) {
            if (!$this->integrity->verifyPasskey($row)) {
                $this->system->log('security', "admin_passkey {$row['passkey_id']} of admin {$adminId}: signature mismatch - ignored");
                continue;
            }
            $list[] = $this->shape($row);
        }
        return $list;
    }

    /** Number of valid passkeys */
    public function count(int $adminId): int
    {
        return count($this->forAdmin($adminId));
    }

    /** Passkey of an admin for a credential id (raw), null = unknown/invalid */
    public function find(int $adminId, string $credentialId): ?array
    {
        if ($adminId < 1 || $credentialId === '') return null;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT * FROM `admin_passkey` WHERE `credential_hash` = ? AND `admin_id` = ?",
                'si', [hash('sha256', $credentialId, true), $adminId]
            );
            $row = $this->db->num_rows > 0 ? $this->db->fetch() : null;
        }
        finally {
            $this->db->popResult();
        }
        if ($row === null || !hash_equals((string)$row['credential_id'], $credentialId)) return null;
        if (!$this->integrity->verifyPasskey($row)) {
            $this->system->log('security', "admin_passkey {$row['passkey_id']} of admin {$adminId}: signature mismatch - ignored");
            return null;
        }
        return $this->shape($row);
    }

    /** Does the credential id already exist (for any account)? */
    public function exists(string $credentialId): bool
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT 1 FROM `admin_passkey` WHERE `credential_hash` = ?", 's', [hash('sha256', $credentialId, true)]);
            return $this->db->num_rows > 0;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Save and sign the passkey.
     *
     * @param array    $credential  WebAuthn::verifyRegistration()
     * @param string[] $transports
     * @return int     passkey_id
     */
    public function add(int $adminId, array $credential, string $label, array $transports): int
    {
        $row = [
            'admin_id' => $adminId,
            'credential_hash' => hash('sha256', (string)$credential['credential_id'], true),
            'public_key' => (string)$credential['public_key'],
            'alg' => (int)$credential['alg'],
        ];
        $this->db->query(
            "INSERT INTO `admin_passkey` (`admin_id`, `credential_id`, `credential_hash`, `public_key`, `alg`,"
            . " `sign_count`, `transports`, `aaguid`, `label`, `created_at`, `sig`)"
            . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)",
            'isssiissss', [
                $adminId, (string)$credential['credential_id'], $row['credential_hash'], $row['public_key'],
                $row['alg'], (int)$credential['sign_count'], self::transportsString($transports),
                str_pad(substr((string)($credential['aaguid'] ?? ''), 0, 16), 16, "\0"),
                mb_substr($label, 0, 100), $this->integrity->passkeySignature($row),
            ]
        );
        return (int)$this->db->insert_id;
    }

    /**
     * Advance the signature counter. Only if it still has the value that was read -
     * two simultaneous logins with the same response yield exactly one.
     */
    public function markUsed(int $passkeyId, int $oldCount, int $newCount): bool
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                "UPDATE `admin_passkey` SET `sign_count` = ?, `last_used_at` = UTC_TIMESTAMP()"
                . " WHERE `passkey_id` = ? AND `sign_count` = ?",
                'iii', [$newCount, $passkeyId, $oldCount]
            );
            return $this->db->affected_rows === 1;
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Delete one passkey of the admin */
    public function remove(int $adminId, int $passkeyId): bool
    {
        $this->db->pushResult();
        try {
            $this->db->query("DELETE FROM `admin_passkey` WHERE `passkey_id` = ? AND `admin_id` = ?", 'ii', [$passkeyId, $adminId]);
            return $this->db->affected_rows === 1;
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Delete all passkeys of an admin (reset by a superuser) */
    public function removeAll(int $adminId): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("DELETE FROM `admin_passkey` WHERE `admin_id` = ?", 'i', [$adminId]);
            return max(0, (int)$this->db->affected_rows);
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Transports from the browser (usb, nfc, ble, internal, hybrid, …) - known words only */
    public static function transportsString(array $transports): string
    {
        $clean = [];
        foreach ($transports as $transport) {
            if (is_string($transport) && preg_match('/^[a-z-]{2,20}$/', $transport)) $clean[$transport] = true;
        }
        return mb_substr(implode(',', array_keys($clean)), 0, 100);
    }

    private function shape(array $row): array
    {
        return [
            'passkey_id' => (int)$row['passkey_id'],
            'credential_id' => (string)$row['credential_id'],
            'public_key' => (string)$row['public_key'],
            'alg' => (int)$row['alg'],
            'sign_count' => (int)$row['sign_count'],
            'transports' => array_values(array_filter(explode(',', (string)$row['transports']))),
            'label' => (string)$row['label'],
            'created_at' => (string)$row['created_at'],
            'last_used_at' => (string)($row['last_used_at'] ?? ''),
        ];
    }
}
