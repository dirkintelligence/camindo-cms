<?php
/**
 * Zweiter Faktor für Frontend-User (Profile) - developer_docs/20-frontend-schutz.md B,
 * Befehle CMS:USER_LOGIN codetemplate=…, CMS:USER_LOGIN_CODE, CMS:USER_2FA
 * (RecordUser).
 *
 * WANN EIN ZWEITER FAKTOR NÖTIG IST
 * --------------------
 * Je Profil-Bereich, area.details.second_factor (policy()):
 *
 *     none        (Vorgabe) nie - auch ein früher eingerichteter Faktor ruht
 *     optional    nur wenn der User selbst einen eingerichtet hat (USER_2FA)
 *     new_device  wie optional; ohne eigenen Faktor ein Code, wenn sich das Profil
 *                 von einem unbekannten Gerät anmeldet (LoginDevices::isKnown() -
 *                 auch das erste Gerät gilt als unbekannt). Das bekannte Gerät ist
 *                 hier der erste Faktor neben dem Passwort
 *     required    jede Anmeldung: TOTP, wenn eingerichtet, sonst der Code
 *
 * Zwei Faktoren:
 *     totp   Authenticator-App (RFC 6238, Camindo\Core\Totp) mit
 *            Wiederherstellungscodes. Einrichten: der User tippt den angezeigten
 *            Schlüssel in die App ab, scannt den QR-Code (im Template mit
 *            qrcode-generator, wie im Backend) oder öffnet auf dem Telefon den
 *            otpauth://-Link
 *     email  sechs Ziffern je Anmeldung; das codetemplate verschickt sie mit
 *            CMS:EMAIL - der Befehl selbst verschickt nichts
 *
 * WAS IN DER DATENBANK STEHT
 * --------------------
 *     user_second_factor   je Profil höchstens eine Zeile: method 'totp' | 'email'
 *                          (selbst gewählt), totp_secret (Crypt::encrypt() binär,
 *                          Zusatzdaten 'utotp|{record_id}'), totp_step (Replay-
 *                          Sperre), enabled_at
 *     user_recovery_code   Crypt::hash('urecovery|{record_id}|{Code}'), einmal
 *                          einlösbar (DELETE)
 *
 * Bewusst nicht in data_* und record_cache: das Secret stünde sonst in Arbeitskopien,
 * Exporten und dem Volltext. Beide Tabellen hängen per Fremdschlüssel am Profil
 * (ON DELETE CASCADE). KeyRotation verschlüsselt totp_secret mit um.
 *
 * Der Anmeldecode selbst steht nur als Hash in der wartenden Session
 * (user_session.details.mfa_pending, RecordUser::startSecondFactor()).
 *
 * @see Camindo\Core\Modules\Record\RecordUser
 * @see Camindo\Core\Modules\Admin\TotpFactor   dasselbe für Admins
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\AuditLog;
use Camindo\Core\Config;
use Camindo\Core\ContainerInterface;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\LoginDevices;
use Camindo\Core\System;
use Camindo\Core\Totp;

class UserSecondFactor
{
    /** Werte von area.details.second_factor */
    public const POLICIES = ['none', 'optional', 'new_device', 'required'];

    public const RECOVERY_COUNT = 10;

    /** Gültigkeit eines Setup-Tokens in Sekunden */
    public const SETUP_SECONDS = 900;

    /** Stellen des Anmeldecodes */
    public const CODE_DIGITS = 6;

    /** Zeichen der Wiederherstellungscodes: ohne 0/O, 1/I/L (wie beim Admin) */
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private Config $config,
        private Database $db,
        private Crypt $crypt,
        private AuditLog $auditLog,
        private LoginDevices $loginDevices,
        private System $system,
        private ContainerInterface $container,
    ) {}

    // ====================
    // Wann, womit

    /** area.details.second_factor, unbekannt = 'none' */
    public function policy(array $area): string
    {
        $details = is_array($area['details'] ?? null) ? $area['details'] : (json_decode((string)($area['details'] ?? ''), true) ?: []);
        $policy = (string)($details['second_factor'] ?? 'none');
        return in_array($policy, self::POLICIES, true) ? $policy : 'none';
    }

    /**
     * Welcher zweite Faktor gilt für diese Anmeldung?
     *
     * @return string  '' = keiner, 'totp' oder 'email' (Code)
     */
    public function needed(array $profile, array $area): string
    {
        $policy = $this->policy($area);
        if ($policy === 'none') return '';
        $recordId = (int)($profile['record_id'] ?? 0);
        $method = $this->method($recordId);
        if ($method !== '') return $method;
        if ($policy === 'required') return 'email';
        if ($policy === 'new_device' && !$this->loginDevices->isKnown('user', $recordId)) return 'email';
        return '';
    }

    /** Selbst eingerichteter Faktor: '' | 'totp' | 'email' */
    public function method(int $recordId): string
    {
        $row = $this->row($recordId);
        if ($row === null) return '';
        if ($row['method'] === 'totp' && (string)$row['totp_secret'] === '') return '';
        return (string)$row['method'];
    }

    /**
     * @return array  method ('' | 'totp' | 'email'), enabled_at (UTC), recovery_left
     */
    public function state(int $recordId): array
    {
        $method = $this->method($recordId);
        $row = $method !== '' ? $this->row($recordId) : null;
        return [
            'method' => $method,
            'enabled_at' => (string)($row['enabled_at'] ?? ''),
            'recovery_left' => $method === 'totp' ? $this->recoveryCount($recordId) : 0,
        ];
    }

    // ====================
    // Anmeldecode

    /**
     * Neuer Code.
     *
     * @return array{0:string,1:string}  [Code, Hash für die Session]
     */
    public function newCode(): array
    {
        $code = str_pad((string)random_int(0, 10 ** self::CODE_DIGITS - 1), self::CODE_DIGITS, '0', STR_PAD_LEFT);
        return [$code, $this->codeHash($code)];
    }

    public function codeMatches(string $hash, string $input): bool
    {
        $input = preg_replace('/\s+/', '', $input) ?? '';
        if (!preg_match('/^\d{' . self::CODE_DIGITS . '}$/', $input) || $hash === '') return false;
        return hash_equals($hash, $this->codeHash($input));
    }


    private function codeHash(string $code): string
    {
        return $this->crypt->hash('ulogincode|' . $code);
    }

    // ====================
    // TOTP

    /**
     * TOTP-Code prüfen und den Zeitschritt verbrauchen (UPDATE … totp_step < ? ist
     * die Sperre gegen denselben Code zweimal, auch gleichzeitig).
     */
    public function verifyTotp(int $recordId, string $code): bool
    {
        $row = $this->row($recordId);
        if ($row === null || $row['method'] !== 'totp' || (string)$row['totp_secret'] === '') return false;
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . Totp::DIGITS . '}$/', $code)) return false;
        $secret = $this->crypt->decrypt((string)$row['totp_secret'], $this->aad($recordId), true);
        if ($secret === '') return false;
        $step = Totp::verify($secret, $code, (int)$row['totp_step']);
        if ($step === null) return false;
        $this->db->pushResult();
        try {
            $this->db->query(
                "UPDATE `user_second_factor` SET `totp_step` = ? WHERE `record_id` = ? AND `totp_step` < ?",
                'iii', [$step, $recordId, $step]
            );
            return $this->db->affected_rows === 1;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * TOTP-Code oder Wiederherstellungscode.
     *
     * @return string  '' = falsch, 'totp' oder 'recovery'
     */
    public function verifyTotpOrRecovery(array $profile, string $code): string
    {
        $recordId = (int)$profile['record_id'];
        if ($this->verifyTotp($recordId, $code)) return 'totp';
        if ($this->useRecoveryCode($recordId, $code)) {
            $left = $this->recoveryCount($recordId);
            $this->audit($profile, 'recovery_codes', (string)($left + 1), (string)$left);
            $this->notify('recovery_code_used', $profile, ['remaining' => $left]);
            return 'recovery';
        }
        return '';
    }

    /**
     * Neues Secret zum Einrichten (nichts gespeichert - es reist verschlüsselt im
     * Setup-Token mit, bis enableTotp() mit einem gültigen Code kommt).
     *
     * @param string $account  Name des Kontos in der App (Anmeldename)
     * @param string $issuer   Anbieter in der App (Domain der Website)
     * @return array           token, secret (Base32 in Vierergruppen, zum Abtippen),
     *                         uri (otpauth://… - auf dem Telefon öffnet der Link die App)
     */
    public function newSetup(array $profile, string $account, string $issuer): array
    {
        $secret = Totp::generateSecret();
        $recordId = (int)$profile['record_id'];
        $token = $this->crypt->encrypt(time() . '|' . Totp::base32Encode($secret), 'utotp-setup|' . $recordId);
        return $this->setupData($secret, $token, $account, $issuer);
    }

    /** Anzeige zu einem vorhandenen Setup-Token (nach falschem Code), null = abgelaufen */
    public function setupFromToken(array $profile, string $token, string $account, string $issuer): ?array
    {
        $secret = $this->secretFromToken((int)$profile['record_id'], $token);
        return $secret === '' ? null : $this->setupData($secret, $token, $account, $issuer);
    }

    /**
     * TOTP einschalten.
     *
     * @return array|null  Wiederherstellungscodes (nur jetzt im Klartext), null =
     *                     Token abgelaufen oder Code falsch
     */
    public function enableTotp(array $profile, string $token, string $code): ?array
    {
        $recordId = (int)$profile['record_id'];
        $secret = $this->secretFromToken($recordId, $token);
        if ($secret === '') return null;
        $step = Totp::verify($secret, preg_replace('/\s+/', '', $code) ?? '');
        if ($step === null) return null;
        $before = $this->method($recordId);
        $this->db->beginTransaction();
        try {
            $this->db->query(
                "REPLACE INTO `user_second_factor` (`record_id`, `method`, `totp_secret`, `totp_step`, `enabled_at`)"
                . " VALUES (?, 'totp', ?, ?, UTC_TIMESTAMP())",
                'isi', [$recordId, $this->crypt->encrypt($secret, $this->aad($recordId), true), $step]
            );
            $codes = $this->writeRecoveryCodes($recordId);
            $this->audit($profile, 'second_factor', $before, 'totp');
            $this->db->commit();
        }
        catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->notify('totp_enabled', $profile);
        return $codes;
    }

    /** Code (E-Mail/SMS) als eigenen Faktor wählen - ersetzt TOTP */
    public function enableCode(array $profile): void
    {
        $recordId = (int)$profile['record_id'];
        $before = $this->method($recordId);
        $this->db->beginTransaction();
        try {
            $this->db->query(
                "REPLACE INTO `user_second_factor` (`record_id`, `method`, `totp_secret`, `totp_step`, `enabled_at`)"
                . " VALUES (?, 'email', '', 0, UTC_TIMESTAMP())",
                'i', [$recordId]
            );
            $this->db->query("DELETE FROM `user_recovery_code` WHERE `record_id` = ?", 'i', [$recordId]);
            $this->audit($profile, 'second_factor', $before, 'email');
            $this->db->commit();
        }
        catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->notify($before === 'totp' ? 'totp_disabled' : 'code_enabled', $profile);
    }

    /**
     * Eigenen Faktor entfernen - durch den User oder als Zurücksetzen im Backend
     * ($reset: dann steht der Admin als Handelnder im Audit-Log).
     */
    public function disable(array $profile, bool $reset = false): void
    {
        $recordId = (int)$profile['record_id'];
        $before = $this->method($recordId);
        if ($before === '') return;
        $this->db->beginTransaction();
        try {
            $this->db->query("DELETE FROM `user_second_factor` WHERE `record_id` = ?", 'i', [$recordId]);
            $this->db->query("DELETE FROM `user_recovery_code` WHERE `record_id` = ?", 'i', [$recordId]);
            $this->audit($profile, 'second_factor', $before, $reset ? 'reset' : '');
            $this->db->commit();
        }
        catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->notify($before === 'totp' ? 'totp_disabled' : 'code_disabled', $profile, ['reset' => $reset]);
    }

    // ====================
    // Wiederherstellungscodes

    /** @return string[]  neuer Satz im Klartext */
    public function regenerateRecoveryCodes(array $profile): array
    {
        $recordId = (int)$profile['record_id'];
        $before = $this->recoveryCount($recordId);
        $this->db->beginTransaction();
        try {
            $codes = $this->writeRecoveryCodes($recordId);
            $this->audit($profile, 'recovery_codes', (string)$before, (string)count($codes));
            $this->db->commit();
        }
        catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        return $codes;
    }

    public function recoveryCount(int $recordId): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT COUNT(*) FROM `user_recovery_code` WHERE `record_id` = ?", 'i', [$recordId]);
            return (int)($this->db->fetchNum()[0] ?? 0);
        }
        finally {
            $this->db->popResult();
        }
    }

    private function useRecoveryCode(int $recordId, string $code): bool
    {
        $normalized = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');
        if (!preg_match('/^[' . self::RECOVERY_ALPHABET . ']{10}$/', $normalized)) return false;
        // HMAC unter allen Schlüsseln (Codes aus der Zeit vor einer Schlüsselrotation)
        $hashes = $this->crypt->hashAll('urecovery|' . $recordId . '|' . $normalized, true);
        $this->db->pushResult();
        try {
            $this->db->query(
                "DELETE FROM `user_recovery_code` WHERE `record_id` = ? AND `code_hash` IN ("
                . implode(',', array_fill(0, count($hashes), '?')) . ") LIMIT 1",
                'i' . str_repeat('s', count($hashes)), array_merge([$recordId], $hashes)
            );
            return $this->db->affected_rows === 1;
        }
        finally {
            $this->db->popResult();
        }
    }

    /** @return string[] */
    private function writeRecoveryCodes(int $recordId): array
    {
        $this->db->query("DELETE FROM `user_recovery_code` WHERE `record_id` = ?", 'i', [$recordId]);
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
                "INSERT INTO `user_recovery_code` (`record_id`, `code_hash`, `created_at`) VALUES (?, ?, UTC_TIMESTAMP())",
                'is', [$recordId, $this->crypt->hash('urecovery|' . $recordId . '|' . $raw, true)]
            );
        }
        return array_values($codes);
    }

    // ====================
    // Intern

    private function row(int $recordId): ?array
    {
        if ($recordId < 1) return null;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `method`, `totp_secret`, `totp_step`, `enabled_at` FROM `user_second_factor` WHERE `record_id` = ?",
                'i', [$recordId]
            );
            return $this->db->num_rows > 0 ? $this->db->fetch() : null;
        }
        finally {
            $this->db->popResult();
        }
    }

    private function aad(int $recordId): string
    {
        return 'utotp|' . $recordId;
    }

    private function secretFromToken(int $recordId, string $token): string
    {
        if ($recordId < 1 || $token === '') return '';
        $plain = $this->crypt->decrypt($token, 'utotp-setup|' . $recordId);
        if (!preg_match('/^(\d+)\|([A-Z2-7]+)$/', $plain, $m)) return '';
        if ((int)$m[1] + self::SETUP_SECONDS < time()) return '';
        return Totp::base32Decode($m[2]);
    }

    private function setupData(string $secret, string $token, string $account, string $issuer): array
    {
        $issuer = $issuer !== '' ? $issuer : (trim((string)$this->config->get('security.totp_issuer')) ?: 'camindo');
        return [
            'token'  => $token,
            'secret' => trim(chunk_split(Totp::base32Encode($secret), 4, ' ')),
            'uri'    => Totp::uri($secret, $issuer, $account !== '' ? $account : $issuer),
        ];
    }

    /**
     * Audit 'update' am Profil - nur Kennungen und Anzahlen, nie Secret oder Code.
     */
    private function audit(array $profile, string $key, string $before, string $after): void
    {
        $this->auditLog->write('update', 'record', (int)$profile['record_id'], '#' . (int)$profile['record_id'], [[
            'k' => $key,
            'l' => '#user.audit_' . $key,
            't' => 'chars',
            'g' => '',
            'b' => $before,
            'a' => $after,
        ]], (int)($profile['area_id'] ?? 0) ?: null, (int)($profile['instance_id'] ?? 0) ?: null);
    }

    /**
     * Ereignis an den Notifier (security.login_notifier); die Vorgabe tut für
     * Frontend-User nichts - das Erfolgstemplate sieht $set['second_factor_changed'].
     */
    private function notify(string $event, array $profile, array $context = []): void
    {
        $this->loginDevices->notify($event, 'user', $profile, $context + [
            'label' => LoginDevices::label((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'time'  => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
