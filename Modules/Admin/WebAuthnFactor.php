<?php
/**
 * Second factor via passkey or security key (WebAuthn) -
 * developer docs, chapter 13 (Security: authentication).
 *
 * WHEN IT APPLIES (applies(), Admin::secondFactor() asks it first)
 * --------------------
 *     admin.second_factor = 'webauthn'   the admin has only passkeys (no TOTP). The
 *                                        identifier is part of the row signature - whoever
 *                                        deletes the passkey rows does not thereby downgrade the account
 *                                        to email or to nothing at all:
 *                                        the recovery codes remain.
 *     Passkeys present and WebAuthn    also alongside TOTP (second_factor = 'totp') -
 *     available                          then the form takes passkey, app code
 *                                        and recovery code
 *
 * ANMELDUNG
 * --------------------
 * start() does nothing. The challenge is an HMAC over the uid of the running
 * login (challengeFor()) - the uid is random and good for exactly one attempt
 * (Admin::_claimUid()), so the challenge is too; no column is needed for it.
 * loginOptions() returns the options for navigator.credentials.get(); the
 * login form sends the response as JSON in the field 'passkey', Admin::loginByCode()
 * passes it with the uid to verify(). A code instead of JSON goes to TotpFactor::verify()
 * (app code, if TOTP is active; recovery code always).
 *
 * EINRICHTEN
 * --------------------
 * registrationStart() after the previous password (AdminBackend): challenge and
 * label travel encrypted as a token in the form (bound to the admin_id,
 * valid for SETUP_SECONDS) - nothing is stored until registrationFinish(). The first
 * passkey sets second_factor = 'webauthn' (except with TOTP) and creates
 * recovery codes if there are none yet.
 *
 * @see Camindo\Core\WebAuthn        Verification
 * @see Camindo\Core\Modules\Admin\Passkeys
 */
namespace Camindo\Core\Modules\Admin;

use Camindo\Core\AuditLog;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\Integrity;
use Camindo\Core\LoginDevices;
use Camindo\Core\System;
use Camindo\Core\WebAuthn;

class WebAuthnFactor implements SecondFactorInterface {

    /** Validity of a setup token (password until gesture on the device) in seconds */
    public const SETUP_SECONDS = 600;

    /** Maximum length of the response from the browser (JSON) */
    private const MAX_RESPONSE_BYTES = 16384;

    public function __construct(
        private Database $db,
        private Crypt $crypt,
        private Integrity $integrity,
        private AuditLog $auditLog,
        private LoginDevices $loginDevices,
        private System $system,
        private WebAuthn $webAuthn,
        private Passkeys $passkeys,
        private TotpFactor $totpFactor,
    )
    {
    }

    public function name(): string
    {
        return 'webauthn';
    }

    /** WebAuthn usable (fixed backend domain, not switched off)? */
    public function available(): bool
    {
        return $this->webAuthn->available();
    }

    /** Does the admin have usable passkeys? */
    public function hasPasskeys(int $adminId): bool
    {
        return $this->available() && $this->passkeys->count($adminId) > 0;
    }

    /** Does this factor apply to the admin? (see class comment) */
    public function applies(array $admin): bool
    {
        $adminId = (int)($admin['admin_id'] ?? 0);
        if ($adminId < 1) return false;
        $row = $this->integrity->verifyAdmin($adminId);
        if ($row !== null && ($row['second_factor'] ?? '') === 'webauthn') return true;
        return $this->hasPasskeys($adminId);
    }

    // ====================
    // Anmeldung (SecondFactorInterface)

    /** Nothing to do - the challenge depends on the uid */
    public function start(array $admin): bool
    {
        return true;
    }

    /**
     * Options for navigator.credentials.get() - null if WebAuthn is not available
     * or the admin (still) has no passkeys (then only code entry).
     *
     * @param array $admin  with admin_id and uid
     */
    public function loginOptions(array $admin): ?array
    {
        $uid = (string)($admin['uid'] ?? '');
        if ($uid === '' || !$this->available()) return null;
        $allow = [];
        foreach ($this->passkeys->forAdmin((int)$admin['admin_id']) as $passkey) {
            $allow[] = ['id' => $passkey['credential_id'], 'transports' => $passkey['transports']];
        }
        return $allow ? $this->webAuthn->requestOptions($this->challengeFor($uid), $allow) : null;
    }

    /** Does the admin have TOTP as a fallback code? (hint text in the form) */
    public function totpActive(int $adminId): bool
    {
        return $this->totpFactor->isActive($adminId);
    }

    /**
     * Passkey response (JSON) or code (app, recovery).
     *
     * @param array $admin  Admin::load() with 'uid' of the running login
     */
    public function verify(array $admin, string $code): bool
    {
        $code = trim($code);
        if (str_starts_with($code, '{')) {
            return $this->verifyAssertion($admin, $code);
        }
        return $code !== '' && $this->totpFactor->verify($admin, $code);
    }

    /**
     * Check the response from navigator.credentials.get() and advance the signature counter.
     * Reasons for a rejection are logged in 'security'.
     *
     * @param string $json  {id, clientDataJSON, authenticatorData, signature} base64url
     */
    public function verifyAssertion(array $admin, string $json): bool
    {
        $adminId = (int)($admin['admin_id'] ?? 0);
        $uid = (string)($admin['uid'] ?? '');
        if ($adminId < 1 || $uid === '' || strlen($json) > self::MAX_RESPONSE_BYTES || !$this->available()) return false;
        $data = json_decode($json, true);
        if (!is_array($data)) return false;
        $credentialId = WebAuthn::b64uDecode((string)($data['id'] ?? ''));
        $passkey = $this->passkeys->find($adminId, $credentialId);
        if ($passkey === null) {
            $this->system->log('security', "passkey login admin {$adminId}: unknown credential");
            return false;
        }
        try {
            $count = $this->webAuthn->verifyAssertion(
                WebAuthn::b64uDecode((string)($data['clientDataJSON'] ?? '')),
                WebAuthn::b64uDecode((string)($data['authenticatorData'] ?? '')),
                WebAuthn::b64uDecode((string)($data['signature'] ?? '')),
                $this->challengeFor($uid),
                $passkey['public_key'], $passkey['alg'], $passkey['sign_count']
            );
        }
        catch (\RuntimeException $e) {
            $this->system->log('security', "passkey login admin {$adminId}, passkey {$passkey['passkey_id']}: " . $e->getMessage());
            return false;
        }
        return $this->passkeys->markUsed($passkey['passkey_id'], $passkey['sign_count'], $count);
    }

    // ====================
    // Einrichten, entfernen

    /**
     * Options for navigator.credentials.create() and the token for the form.
     * The caller has checked the previous password.
     *
     * @param array $admin  Admin::load()
     * @return array        options, token
     */
    public function registrationStart(array $admin, string $label): array
    {
        $adminId = (int)$admin['admin_id'];
        $challenge = WebAuthn::challenge();
        $exclude = array_map(fn(array $p) => $p['credential_id'], $this->passkeys->forAdmin($adminId));
        $options = $this->webAuthn->creationOptions(
            $this->userHandle($adminId), (string)$admin['name'], (string)($admin['displayname'] ?? ''),
            $challenge, $exclude
        );
        $token = $this->crypt->encrypt(
            time() . '|' . WebAuthn::b64uEncode($challenge) . '|' . WebAuthn::b64uEncode(mb_substr($label, 0, 100)),
            'webauthn-reg|' . $adminId
        );
        return ['options' => $options, 'token' => $token];
    }

    /**
     * Check the response from navigator.credentials.create() and save it.
     *
     * @param string $clientData   base64url
     * @param string $attestation  base64url
     * @param string $transports   JSON list from the browser
     * @return array  passkey_id, codes (new recovery codes or []) - or
     *                error: expired | unavailable | limit | failed (reason in the log
     *                'security') | exists
     */
    public function registrationFinish(array $admin, string $token, string $clientData, string $attestation, string $transports): array
    {
        $adminId = (int)$admin['admin_id'];
        $parsed = $this->parseToken($adminId, $token);
        if ($parsed === null) return ['error' => 'expired'];
        if (!$this->available()) return ['error' => 'unavailable'];
        if ($this->passkeys->count($adminId) >= Passkeys::MAX_PER_ADMIN) return ['error' => 'limit'];
        try {
            $credential = $this->webAuthn->verifyRegistration(
                WebAuthn::b64uDecode($clientData), WebAuthn::b64uDecode($attestation), $parsed['challenge']
            );
        }
        catch (\RuntimeException $e) {
            $this->system->log('security', "passkey registration admin {$adminId}: " . $e->getMessage());
            return ['error' => 'failed'];
        }
        if ($this->passkeys->exists($credential['credential_id'])) return ['error' => 'exists'];
        $transportList = json_decode($transports, true);

        $before = $this->passkeys->count($adminId);
        $codes = [];
        $this->db->beginTransaction();
        try {
            $passkeyId = $this->passkeys->add($adminId, $credential, $parsed['label'], is_array($transportList) ? $transportList : []);
            $row = $this->integrity->verifyAdmin($adminId);
            $factor = (string)($row['second_factor'] ?? '');
            if ($factor !== 'totp' && $factor !== 'webauthn') {
                $this->db->query("UPDATE `admin` SET `second_factor` = 'webauthn' WHERE `admin_id` = ?", 'i', [$adminId]);
                $this->integrity->signAdmin($adminId);
                $this->audit($admin, 'second_factor', $factor === '' ? 'email' : $factor, 'webauthn');
            }
            if ($this->totpFactor->recoveryCount($adminId) === 0) {
                $codes = $this->totpFactor->regenerateRecoveryCodes($admin);
            }
            $this->audit($admin, 'passkeys', (string)$before, (string)($before + 1));
            $this->db->commit();
        }
        catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->notify('passkey_added', $admin, ['passkey' => $parsed['label']]);
        return ['passkey_id' => $passkeyId, 'codes' => $codes];
    }

    /**
     * Remove a passkey (the admin themselves, after the password). If it was the
     * last one and no TOTP applies, the own second factor ends: second_factor = '',
     * the recovery codes expire.
     */
    public function remove(array $admin, int $passkeyId): bool
    {
        $adminId = (int)$admin['admin_id'];
        $before = $this->passkeys->count($adminId);
        $label = '';
        foreach ($this->passkeys->forAdmin($adminId) as $passkey) {
            if ($passkey['passkey_id'] === $passkeyId) $label = $passkey['label'];
        }
        $this->db->beginTransaction();
        try {
            if (!$this->passkeys->remove($adminId, $passkeyId)) {
                $this->db->rollback();
                return false;
            }
            $this->afterLastRemoved($admin);
            $this->audit($admin, 'passkeys', (string)$before, (string)max(0, $before - 1));
            $this->db->commit();
        }
        catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->notify('passkey_removed', $admin, ['passkey' => $label]);
        return true;
    }

    /**
     * Reset the second factor (superuser): TOTP including passkeys via TotpFactor,
     * otherwise all passkeys and the recovery codes.
     */
    public function reset(array $admin): void
    {
        $adminId = (int)$admin['admin_id'];
        if ($this->totpFactor->isActive($adminId)) {
            $this->totpFactor->disable($admin, reset: true);
            return;
        }
        $before = $this->passkeys->count($adminId);
        $this->db->beginTransaction();
        try {
            $this->passkeys->removeAll($adminId);
            $this->afterLastRemoved($admin, 'reset');
            $this->audit($admin, 'passkeys', (string)$before, '0');
            $this->db->commit();
        }
        catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->notify('passkey_removed', $admin, ['reset' => true]);
    }

    /** No passkeys left and no TOTP: own factor off, codes expire */
    private function afterLastRemoved(array $admin, string $after = 'email'): void
    {
        $adminId = (int)$admin['admin_id'];
        if ($this->passkeys->count($adminId) > 0) return;
        $row = $this->integrity->verifyAdmin($adminId);
        if (($row['second_factor'] ?? '') !== 'webauthn') return;
        $this->db->query("UPDATE `admin` SET `second_factor` = '' WHERE `admin_id` = ?", 'i', [$adminId]);
        $this->db->query("DELETE FROM `admin_recovery_code` WHERE `admin_id` = ?", 'i', [$adminId]);
        $this->integrity->signAdmin($adminId);
        $this->audit($admin, 'second_factor', 'webauthn', $after);
    }

    // ====================
    // Intern

    /** Challenge of the running login (raw, 32 bytes) */
    public function challengeFor(string $uid): string
    {
        return $this->crypt->hash('webauthn-login|' . $uid, true);
    }

    /** Non-descriptive, stable identifier of the account for the authenticator */
    private function userHandle(int $adminId): string
    {
        return $this->crypt->hash('webauthn-user|' . $adminId, true);
    }

    /** @return array{challenge:string,label:string}|null */
    private function parseToken(int $adminId, string $token): ?array
    {
        if ($adminId < 1 || $token === '') return null;
        $plain = $this->crypt->decrypt($token, 'webauthn-reg|' . $adminId);
        if (!preg_match('/^(\d+)\|([A-Za-z0-9_-]+)\|([A-Za-z0-9_-]*)$/', $plain, $m)) return null;
        if ((int)$m[1] + self::SETUP_SECONDS < time()) return null;
        return ['challenge' => WebAuthn::b64uDecode($m[2]), 'label' => $m[3] === '' ? '' : WebAuthn::b64uDecode($m[3])];
    }

    private function audit(array $admin, string $key, string $before, string $after): void
    {
        $this->auditLog->write('update', 'admin', (int)$admin['admin_id'], (string)($admin['name'] ?? ''), [[
            'k' => $key,
            'l' => '#admin.field_admin_' . $key,
            't' => 'chars',
            'g' => '',
            'b' => $before,
            'a' => $after,
        ]]);
    }

    private function notify(string $event, array $admin, array $context = []): void
    {
        $this->loginDevices->notify($event, 'admin', $admin, $context + $this->loginDevices->context());
    }
}
