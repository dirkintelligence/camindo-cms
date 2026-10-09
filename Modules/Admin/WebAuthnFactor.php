<?php
/**
 * Zweiter Faktor per Passkey bzw. Sicherheitsschlüssel (WebAuthn) -
 * developer_docs/22-webauthn.md.
 *
 * WANN ER GILT (applies(), Admin::secondFactor() fragt ihn zuerst)
 * --------------------
 *     admin.second_factor = 'webauthn'   der Admin hat nur Passkeys (kein TOTP). Die
 *                                        Kennung steht in der Zeilensignatur - wer die
 *                                        Passkey-Zeilen löscht, stuft das Konto damit
 *                                        nicht auf E-Mail oder gar nichts herunter:
 *                                        es bleiben die Wiederherstellungscodes.
 *     Passkeys vorhanden und WebAuthn    auch neben TOTP (second_factor = 'totp') -
 *     verfügbar                          dann nimmt das Formular Passkey, App-Code
 *                                        und Wiederherstellungscode
 *
 * ANMELDUNG
 * --------------------
 * start() tut nichts. Die Challenge ist ein HMAC über die uid der laufenden
 * Anmeldung (challengeFor()) - die uid ist zufällig und genau einen Versuch wert
 * (Admin::_claimUid()), damit auch die Challenge; eine Spalte dafür braucht es
 * nicht. loginOptions() liefert die Optionen für navigator.credentials.get(), das
 * Anmeldeformular schickt die Antwort als JSON im Feld 'passkey', Admin::loginByCode()
 * reicht sie mit der uid an verify(). Ein Code statt JSON geht an TotpFactor::verify()
 * (App-Code, wenn TOTP aktiv ist; Wiederherstellungscode immer).
 *
 * EINRICHTEN
 * --------------------
 * registrationStart() nach dem bisherigen Passwort (AdminBackend): Challenge und
 * Bezeichnung reisen verschlüsselt als Token im Formular (an die admin_id gebunden,
 * SETUP_SECONDS gültig) - gespeichert ist bis registrationFinish() nichts. Der erste
 * Passkey setzt second_factor = 'webauthn' (außer bei TOTP) und erzeugt
 * Wiederherstellungscodes, wenn es noch keine gibt.
 *
 * @see Camindo\Core\WebAuthn        Prüfung
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

    /** Gültigkeit eines Einrichtungs-Tokens (Passwort bis Geste am Gerät) in Sekunden */
    public const SETUP_SECONDS = 600;

    /** Höchstlänge der Antwort aus dem Browser (JSON) */
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

    /** WebAuthn nutzbar (feste Backend-Domain, nicht abgeschaltet)? */
    public function available(): bool
    {
        return $this->webAuthn->available();
    }

    /** Hat der Admin nutzbare Passkeys? */
    public function hasPasskeys(int $adminId): bool
    {
        return $this->available() && $this->passkeys->count($adminId) > 0;
    }

    /** Gilt dieser Faktor für den Admin? (siehe Klassenkommentar) */
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

    /** Nichts zu tun - die Challenge hängt an der uid */
    public function start(array $admin): bool
    {
        return true;
    }

    /**
     * Optionen für navigator.credentials.get() - null, wenn WebAuthn nicht verfügbar
     * ist oder der Admin (noch) keine Passkeys hat (dann nur Code-Eingabe).
     *
     * @param array $admin  mit admin_id und uid
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

    /** Hat der Admin TOTP als Ausweich-Code? (Hinweistext im Formular) */
    public function totpActive(int $adminId): bool
    {
        return $this->totpFactor->isActive($adminId);
    }

    /**
     * Passkey-Antwort (JSON) oder Code (App, Wiederherstellung).
     *
     * @param array $admin  Admin::load() mit 'uid' der laufenden Anmeldung
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
     * Antwort von navigator.credentials.get() prüfen und den Signaturzähler
     * fortschreiben. Gründe einer Ablehnung stehen im Log 'security'.
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
     * Optionen für navigator.credentials.create() und das Token fürs Formular.
     * Aufrufer hat das bisherige Passwort geprüft.
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
     * Antwort von navigator.credentials.create() prüfen und speichern.
     *
     * @param string $clientData   base64url
     * @param string $attestation  base64url
     * @param string $transports   JSON-Liste aus dem Browser
     * @return array  passkey_id, codes (neue Wiederherstellungscodes oder []) - oder
     *                error: expired | unavailable | limit | failed (Grund im Log
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
     * Einen Passkey entfernen (der Admin selbst, nach dem Passwort). War es der
     * letzte und gilt kein TOTP, endet der eigene zweite Faktor: second_factor = '',
     * die Wiederherstellungscodes verfallen.
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
     * Zweiten Faktor zurücksetzen (Superuser): TOTP samt Passkeys über TotpFactor,
     * sonst alle Passkeys und die Wiederherstellungscodes.
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

    /** Keine Passkeys mehr und kein TOTP: eigener Faktor aus, Codes verfallen */
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

    /** Challenge der laufenden Anmeldung (roh, 32 Bytes) */
    public function challengeFor(string $uid): string
    {
        return $this->crypt->hash('webauthn-login|' . $uid, true);
    }

    /** Nicht sprechende, stabile Kennung des Kontos für den Authenticator */
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
