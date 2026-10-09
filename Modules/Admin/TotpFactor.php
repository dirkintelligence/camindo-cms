<?php
/**
 * Zweiter Faktor per Authenticator-App (TOTP, RFC 6238 - die Rechnung steht in
 * Camindo\Core\Totp) samt Wiederherstellungscodes. 16-sicherheit.md §16.
 *
 * WAS IN DER DATENBANK STEHT
 * --------------------
 *     admin.second_factor     'totp' = aktiv, '' = kein eigener Faktor (E-Mail laut
 *                             admin.2_factor). Teil der Zeilensignatur (Integrity):
 *                             per UPDATE auf '' zurückgestellt, ist die Zeile ungültig.
 *     admin.totp_secret       Crypt::encrypt() binär, Zusatzdaten 'totp|{admin_id}' -
 *                             in eine andere Admin-Zeile kopiert, entschlüsselt es
 *                             nicht mehr.
 *     admin.totp_step         zuletzt angenommener Zeitschritt (Replay-Schutz): ein
 *                             Code gilt nur für Schritte danach, auch wenn er noch
 *                             im ±1-Fenster läge.
 *     admin.totp_enabled_at   seit wann (Anzeige)
 *     admin_recovery_code     je Code eine Zeile mit Crypt::hash() (HMAC mit
 *                             .camindo.key) über 'recovery|{admin_id}|{Code}'.
 *                             Einmal benutzt, wird die Zeile gelöscht.
 *
 * EINRICHTEN OHNE ZWISCHENSTAND IN DER DATENBANK
 * --------------------
 * newSetup() würfelt ein Secret und gibt es verschlüsselt als "Setup-Token" zurück,
 * das im Formular mitreist (Zusatzdaten an die admin_id gebunden, SETUP_SECONDS
 * gültig). Erst enable() - nach einem gültigen Code aus der App - schreibt es in die
 * Zeile. Ein abgebrochenes Einrichten hinterlässt damit nichts.
 *
 * Wer TOTP an- oder abschaltet oder einen Wiederherstellungscode verbraucht, steht im
 * Audit-Log, und der Admin bekommt eine Nachricht (LoginNotifierInterface, Ereignisse
 * totp_enabled, totp_disabled, recovery_code_used) - auch wenn ein Superuser das
 * Zurücksetzen war.
 *
 * @see Camindo\Core\Totp
 * @see Camindo\Core\Modules\Admin\SecondFactorInterface
 * @see Camindo\Core\Modules\Admin\AdminBackend::totp()  Oberfläche
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

    /** Werte von security.admin_2fa */
    public const MODES = ['email', 'totp', 'totp_required'];

    /** Anzahl Wiederherstellungscodes je Satz */
    public const RECOVERY_COUNT = 10;

    /** Gültigkeit eines Setup-Tokens (QR-Code bis Bestätigung) in Sekunden */
    public const SETUP_SECONDS = 900;

    /** Zeichen der Wiederherstellungscodes: ohne 0/O, 1/I/L */
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

    /** Dürfen Admins TOTP benutzen (security.admin_2fa nicht 'email')? */
    public function available(): bool
    {
        return $this->mode() !== 'email';
    }

    /** Muss ein Admin dieser Gruppe TOTP haben (totp_required gilt für Superuser)? */
    public function requiredFor(int $groupId): bool
    {
        return $this->mode() === 'totp_required' && $groupId === InstanceCache::SUPERUSER_GROUP_ID;
    }

    // ====================
    // Anmeldung (SecondFactorInterface)

    /** Nichts zu tun - der Code entsteht in der App */
    public function start(array $admin): bool
    {
        return true;
    }

    /**
     * TOTP-Code (6 Ziffern) oder Wiederherstellungscode.
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
     * TOTP-Code prüfen und den Zeitschritt verbrauchen.
     *
     * Das UPDATE mit "totp_step < ?" ist die eigentliche Sperre: zwei gleichzeitige
     * Anfragen mit demselben Code kommen beide durch Totp::verify(), aber nur eine
     * ändert die Zeile.
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

    /** Hat der Admin TOTP eingerichtet? */
    public function isActive(int $adminId): bool
    {
        $row = $this->row($adminId);
        return $row !== null && $row['second_factor'] === 'totp' && (string)$row['totp_secret'] !== '';
    }

    /**
     * @return array  active (bool), enabled_at (UTC 'Y-m-d H:i:s' oder ''),
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
     * Neues Secret für das Einrichten (siehe Klassenkommentar).
     *
     * @param array $admin  admin_id, name
     * @return array        token (fürs Formular), uri (otpauth:// für den QR-Code),
     *                      secret (Base32, zum Abtippen)
     */
    public function newSetup(array $admin): array
    {
        $secret = Totp::generateSecret();
        return $this->setupData($admin, $secret, $this->setupToken((int)$admin['admin_id'], $secret));
    }

    /**
     * Anzeige zu einem vorhandenen Setup-Token (erneutes Anzeigen nach einem
     * falschen Code). null = Token ungültig oder abgelaufen.
     */
    public function setupFromToken(array $admin, string $token): ?array
    {
        $secret = $this->secretFromToken((int)$admin['admin_id'], $token);
        return $secret === '' ? null : $this->setupData($admin, $secret, $token);
    }

    /**
     * TOTP einschalten: Code aus der App gegen das Secret im Setup-Token prüfen,
     * dann Secret speichern, Zeile neu signieren und einen Satz
     * Wiederherstellungscodes erzeugen.
     *
     * @param array $admin  Admin::load()
     * @return array|null   die Wiederherstellungscodes im Klartext (nur jetzt zu
     *                      sehen), null = Token ungültig oder Code falsch
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
     * TOTP abschalten - durch den Admin selbst oder als Zurücksetzen durch einen
     * Superuser (dann steht der Superuser als Handelnder im Audit-Log).
     *
     * Hat der Admin Passkeys, bleiben sie beim eigenen Abschalten der zweite Faktor
     * (second_factor = 'webauthn') samt Wiederherstellungscodes. Das Zurücksetzen
     * durch einen Superuser entfernt auch sie.
     *
     * @param array $admin   Admin::load() des betroffenen Admins
     * @param bool  $reset   true: Zurücksetzen durch einen Superuser
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
     * Neuen Satz erzeugen, der alte verfällt.
     *
     * @return string[]  Codes im Klartext (Form XXXXX-XXXXX)
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

    /** Noch unbenutzte Wiederherstellungscodes */
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
     * Wiederherstellungscode einlösen: die Zeile wird gelöscht, ein zweites Mal
     * greift er nicht.
     */
    private function useRecoveryCode(int $adminId, string $code): bool
    {
        $normalized = $this->normalizeRecoveryCode($code);
        if ($normalized === '') return false;
        $this->db->pushResult();
        try {
            // HMAC unter allen Schlüsseln: Codes aus der Zeit vor einer
            // Schlüsselrotation gelten weiter, solange der alte Schlüssel im
            // Schlüsselbund ist (Crypt::hashAll(), KeyRotation)
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

    /** Groß, ohne Bindestrich und Leerzeichen; '' = kann kein Code sein */
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

    /** Zusatzdaten der Verschlüsselung: bindet das Secret an die Zeile */
    private function aad(int $adminId): string
    {
        return 'totp|' . $adminId;
    }

    private function setupToken(int $adminId, string $secret): string
    {
        return $this->crypt->encrypt(time() . '|' . Totp::base32Encode($secret), 'totp-setup|' . $adminId);
    }

    /** Secret aus dem Setup-Token, '' = ungültig, fremd oder abgelaufen */
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
     * Audit-Eintrag 'update' am Admin. Wert sind nur Kennungen (email/totp/reset)
     * bzw. Anzahlen - nie Secret oder Code.
     *
     * @param string $actorName  bei der Anmeldung (noch keine Session): der Admin selbst
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
