<?php
/**
 * Second factor via authenticator app (TOTP, RFC 6238 - the calculation is in
 * Camindo\Core\Totp) including recovery codes. Developer docs, chapter 13 (Security: authentication).
 *
 * WHAT IS STORED IN THE DATABASE
 * --------------------
 *     admin.second_factor     'totp' = active, '' = no own factor (email according to
 *                             admin.2_factor). Part of the row signature (Integrity):
 *                             if reset to '' by an UPDATE, the row is invalid.
 *     admin.totp_secret       Crypt::encrypt() binary, additional data 'totp|{admin_id}' -
 *                             copied into another admin row, it no longer decrypts.
 *     admin.totp_step         last accepted time step (replay protection): a
 *                             code only applies to later steps, even if it would still
 *                             lie within the ±1 window.
 *     admin.totp_enabled_at   seit wann (Anzeige)
 *     admin_recovery_code     one row per code with Crypt::hash() (HMAC with
 *                             .camindo.key) over 'recovery|{admin_id}|{code}'.
 *                             Once used, the row is deleted.
 *
 * SETUP WITHOUT AN INTERIM STATE IN THE DATABASE
 * --------------------
 * newSetup() generates a secret and returns it encrypted as a "setup token",
 * which travels along in the form (additional data bound to the admin_id, valid for SETUP_SECONDS).
 * Only enable() - after a valid code from the app - writes it into the
 * row. An aborted setup therefore leaves nothing behind.
 *
 * Whoever switches TOTP on or off or uses up a recovery code is recorded in the
 * audit log, and the admin receives a message (LoginNotifierInterface, events
 * totp_enabled, totp_disabled, recovery_code_used) - even if a superuser did the
 * reset.
 *
 * @see Camindo\Core\Totp
 * @see Camindo\Core\Modules\Admin\SecondFactorInterface
 * @see Camindo\Core\Modules\Admin\AdminBackend::totp()  Interface
 */
namespace Camindo\Core\Modules\Admin;

use Camindo\Core\AuditLog;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\Integrity;
use Camindo\Core\LoginDevices;
use Camindo\Core\Totp;

class TotpFactor implements SecondFactorInterface {

    /** Values of security.admin_2fa */
    public const MODES = ['email', 'totp', 'totp_required'];

    /** Anzahl Wiederherstellungscodes je Satz */
    public const RECOVERY_COUNT = 10;

    /** Validity of a setup token (QR code until confirmation) in seconds */
    public const SETUP_SECONDS = 900;

    /** Characters of the recovery codes: without 0/O, 1/I/L */
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private Config $config,
        private Database $db,
        private Crypt $crypt,
        private Integrity $integrity,
        private AuditLog $auditLog,
        private LoginDevices $loginDevices,
        private Passkeys $passkeys,
    )
    {
    }

    public function name(): string
    {
        return 'totp';
    }

    // ====================
    // Konfiguration

    /** security.admin_2fa, unbekannte Werte = 'totp' */
    public function mode(): string
    {
        $mode = (string)$this->config->get('security.admin_2fa');
        return in_array($mode, self::MODES, true) ? $mode : 'totp';
    }

    /** May admins use TOTP (security.admin_2fa not 'email')? */
    public function available(): bool
    {
        return $this->mode() !== 'email';
    }

    /** Must an admin of this group have TOTP (totp_required applies to superusers)? */
    public function requiredFor(int $groupId): bool
    {
        return $this->mode() === 'totp_required' && $groupId === InstanceCache::SUPERUSER_GROUP_ID;
    }

    // ====================
    // Anmeldung (SecondFactorInterface)

    /** Nothing to do - the code is generated in the app */
    public function start(array $admin): bool
    {
        return true;
    }

    /**
     * TOTP code (6 digits) or recovery code.
     */
    public function verify(array $admin, string $code): bool
    {
        $adminId = (int)($admin['admin_id'] ?? 0);
        $compact = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{' . Totp::DIGITS . '}$/', $compact)) {
            return $this->verifyTotp($adminId, $compact);
        }
        if ($this->useRecoveryCode($adminId, $code)) {
            $left = $this->recoveryCount($adminId);
            $this->audit($admin, 'recovery_codes', (string)($left + 1), (string)$left, (string)($admin['name'] ?? ''));
            $this->notify('recovery_code_used', $admin, ['remaining' => $left]);
            return true;
        }
        return false;
    }

    /**
     * Check the TOTP code and consume the time step.
     *
     * The UPDATE with "totp_step < ?" is the actual lock: two simultaneous
     * requests with the same code both pass Totp::verify(), but only one
     * changes the row.
     */
    public function verifyTotp(int $adminId, string $code): bool
    {
        $row = $this->row($adminId);
        if ($row === null || $row['second_factor'] !== 'totp') return false;
        $secret = $this->crypt->decrypt((string)$row['totp_secret'], $this->aad($adminId), true);
        $step = Totp::verify($secret, $code, (int)$row['totp_step']);
        if ($step === null) return false;
        $this->db->pushResult();
        try {
            $this->db->query(
                "UPDATE `admin` SET `totp_step` = ? WHERE `admin_id` = ? AND `totp_step` < ?",
                'iii', [$step, $adminId, $step]
            );
            return $this->db->affected_rows === 1;
        }
        finally {
            $this->db->popResult();
        }
    }

    // ====================
    // Zustand

    /** Has the admin set up TOTP? */
    public function isActive(int $adminId): bool
    {
        $row = $this->row($adminId);
        return $row !== null && $row['second_factor'] === 'totp' && (string)$row['totp_secret'] !== '';
    }

    /**
     * @return array  active (bool), enabled_at (UTC 'Y-m-d H:i:s' or ''),
     *                recovery_left (int)
     */
    public function state(int $adminId): array
    {
        $row = $this->row($adminId);
        $active = $row !== null && $row['second_factor'] === 'totp' && (string)$row['totp_secret'] !== '';
        return [
            'active' => $active,
            'enabled_at' => $active ? (string)($row['totp_enabled_at'] ?? '') : '',
            'recovery_left' => $active ? $this->recoveryCount($adminId) : 0,
        ];
    }

    // ====================
    // Einrichten, abschalten

    /**
     * New secret for the setup (see class comment).
     *
     * @param array $admin  admin_id, name
     * @return array        token (for the form), uri (otpauth:// for the QR code),
     *                      secret (Base32, to type in)
     */
    public function newSetup(array $admin): array
    {
        $secret = Totp::generateSecret();
        return $this->setupData($admin, $secret, $this->setupToken((int)$admin['admin_id'], $secret));
    }

    /**
     * Display for an existing setup token (showing again after a
     * wrong code). null = token invalid or expired.
     */
    public function setupFromToken(array $admin, string $token): ?array
    {
        $secret = $this->secretFromToken((int)$admin['admin_id'], $token);
        return $secret === '' ? null : $this->setupData($admin, $secret, $token);
    }

    /**
     * Enable TOTP: check the code from the app against the secret in the setup token,
     * then save the secret, re-sign the row and create a set
     * Wiederherstellungscodes erzeugen.
     *
     * @param array $admin  Admin::load()
     * @return array|null   the recovery codes in plain text (only now to
     *                      be seen), null = token invalid or code wrong
     */
    public function enable(array $admin, string $token, string $code): ?array
    {
        $adminId = (int)$admin['admin_id'];
        $secret = $this->secretFromToken($adminId, $token);
        if ($secret === '') return null;
        $step = Totp::verify($secret, $code);
        if ($step === null) return null;

        $this->db->beginTransaction();
        try {
            $this->db->query(
                "UPDATE `admin` SET `second_factor` = 'totp', `totp_secret` = ?, `totp_step` = ?,"
                . " `totp_enabled_at` = UTC_TIMESTAMP() WHERE `admin_id` = ?",
                'sii', [$this->crypt->encrypt($secret, $this->aad($adminId), true), $step, $adminId]
            );
            $this->integrity->signAdmin($adminId);
            $codes = $this->writeRecoveryCodes($adminId);
            $this->audit($admin, 'second_factor', 'email', 'totp');
            $this->db->commit();
        }
        catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->notify('totp_enabled', $admin);
        return $codes;
    }

    /**
     * Switch off TOTP - by the admin themselves or as a reset by a
     * superuser (then the superuser is recorded as the actor in the audit log).
     *
     * If the admin has passkeys, when switching off their own, they remain the second factor
     * (second_factor = 'webauthn') along with the recovery codes. The reset
     * by a superuser removes them as well.
     *
     * @param array $admin   Admin::load() of the affected admin
     * @param bool  $reset   true: reset by a superuser
     */
    public function disable(array $admin, bool $reset = false): void
    {
        $adminId = (int)$admin['admin_id'];
        $keepPasskeys = !$reset && $this->passkeys->count($adminId) > 0;
        $this->db->beginTransaction();
        try {
            $this->db->query(
                "UPDATE `admin` SET `second_factor` = ?, `totp_secret` = '', `totp_step` = 0,"
                . " `totp_enabled_at` = NULL WHERE `admin_id` = ?",
                'si', [$keepPasskeys ? 'webauthn' : '', $adminId]
            );
            if (!$keepPasskeys) {
                $this->db->query("DELETE FROM `admin_recovery_code` WHERE `admin_id` = ?", 'i', [$adminId]);
            }
            if ($reset) $this->passkeys->removeAll($adminId);
            $this->integrity->signAdmin($adminId);
            $this->audit($admin, 'second_factor', 'totp', $reset ? 'reset' : ($keepPasskeys ? 'webauthn' : 'email'));
            $this->db->commit();
        }
        catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->notify('totp_disabled', $admin, ['reset' => $reset]);
    }

    // ====================
    // Wiederherstellungscodes

    /**
     * Create a new set; the old one expires.
     *
     * @return string[]  codes in plain text (form XXXXX-XXXXX)
     */
    public function regenerateRecoveryCodes(array $admin): array
    {
        $adminId = (int)$admin['admin_id'];
        $before = $this->recoveryCount($adminId);
        $this->db->beginTransaction();
        try {
            $codes = $this->writeRecoveryCodes($adminId);
            $this->audit($admin, 'recovery_codes', (string)$before, (string)count($codes));
            $this->db->commit();
        }
        catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        return $codes;
    }

    /** Recovery codes not yet used */
    public function recoveryCount(int $adminId): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT COUNT(*) FROM `admin_recovery_code` WHERE `admin_id` = ?", 'i', [$adminId]);
            return (int)($this->db->fetchNum()[0] ?? 0);
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Redeem a recovery code: the row is deleted; a second time
     * it does not apply.
     */
    private function useRecoveryCode(int $adminId, string $code): bool
    {
        $normalized = $this->normalizeRecoveryCode($code);
        if ($normalized === '') return false;
        $this->db->pushResult();
        try {
            // HMAC under all keys: codes from the time before a
            // key rotation remain valid as long as the old key is in the
            // key ring (Crypt::hashAll(), KeyRotation)
            $hashes = $this->crypt->hashAll('recovery|' . $adminId . '|' . $normalized, true);
            $this->db->query(
                "DELETE FROM `admin_recovery_code` WHERE `admin_id` = ? AND `code_hash` IN ("
                . implode(',', array_fill(0, count($hashes), '?')) . ") LIMIT 1",
                'i' . str_repeat('s', count($hashes)), array_merge([$adminId], $hashes)
            );
            return $this->db->affected_rows === 1;
        }
        finally {
            $this->db->popResult();
        }
    }

    /** @return string[] */
    private function writeRecoveryCodes(int $adminId): array
    {
        $this->db->query("DELETE FROM `admin_recovery_code` WHERE `admin_id` = ?", 'i', [$adminId]);
        $codes = [];
        while (count($codes) < self::RECOVERY_COUNT) {
            $raw = '';
            for ($i = 0; $i < 10; $i++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $codes[$raw] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        foreach (array_keys($codes) as $raw) {
            $this->db->query(
                "INSERT INTO `admin_recovery_code` (`admin_id`, `code_hash`, `created_at`) VALUES (?, ?, UTC_TIMESTAMP())",
                'is', [$adminId, $this->recoveryHash($adminId, (string)$raw)]
            );
        }
        return array_values($codes);
    }

    /** Uppercase, without hyphen and spaces; '' = cannot be a code */
    private function normalizeRecoveryCode(string $code): string
    {
        $code = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');
        return preg_match('/^[' . self::RECOVERY_ALPHABET . ']{10}$/', $code) ? $code : '';
    }

    private function recoveryHash(int $adminId, string $normalized): string
    {
        return $this->crypt->hash('recovery|' . $adminId . '|' . $normalized, true);
    }

    // ====================
    // Intern

    private function row(int $adminId): ?array
    {
        if ($adminId < 1) return null;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `second_factor`, `totp_secret`, `totp_step`, `totp_enabled_at` FROM `admin`"
                . " WHERE `admin_id` = ? AND `deleted` = 0",
                'i', [$adminId]
            );
            return $this->db->num_rows > 0 ? $this->db->fetch() : null;
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Encryption additional data: binds the secret to the row */
    private function aad(int $adminId): string
    {
        return 'totp|' . $adminId;
    }

    private function setupToken(int $adminId, string $secret): string
    {
        return $this->crypt->encrypt(time() . '|' . Totp::base32Encode($secret), 'totp-setup|' . $adminId);
    }

    /** Secret from the setup token, '' = invalid, foreign or expired */
    private function secretFromToken(int $adminId, string $token): string
    {
        if ($adminId < 1 || $token === '') return '';
        $plain = $this->crypt->decrypt($token, 'totp-setup|' . $adminId);
        if (!preg_match('/^(\d+)\|([A-Z2-7]+)$/', $plain, $m)) return '';
        if ((int)$m[1] + self::SETUP_SECONDS < time()) return '';
        return Totp::base32Decode($m[2]);
    }

    private function setupData(array $admin, string $secret, string $token): array
    {
        $issuer = trim((string)$this->config->get('security.totp_issuer')) ?: 'camindo';
        $host = preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '';
        $account = (string)($admin['name'] ?? '') . ($host !== '' ? '@' . $host : '');
        return [
            'token'  => $token,
            'uri'    => Totp::uri($secret, $issuer, $account),
            'secret' => trim(chunk_split(Totp::base32Encode($secret), 4, ' ')),
        ];
    }

    /**
     * audit entry 'update' on the admin. Values are only identifiers (email/totp/reset)
     * or counts - never secret or code.
     *
     * @param string $actorName  during login (no session yet): the admin themselves
     */
    private function audit(array $admin, string $key, string $before, string $after, string $actorName = ''): void
    {
        $this->auditLog->write('update', 'admin', (int)$admin['admin_id'], (string)($admin['name'] ?? ''), [[
            'k' => $key,
            'l' => '#admin.field_admin_' . $key,
            't' => 'chars',
            'g' => '',
            'b' => $before,
            'a' => $after,
        ]], null, null, $actorName);
    }

    private function notify(string $event, array $admin, array $context = []): void
    {
        $this->loginDevices->notify($event, 'admin', $admin, $context + $this->loginDevices->context());
    }
}
