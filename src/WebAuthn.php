<?php
/**
 * WebAuthn (Passkeys, Sicherheitsschlüssel) - die Prüfung auf dem Server, ohne
 * Bibliothek. developer_docs/22-webauthn.md.
 *
 * Die Klasse kennt keine Datenbank: sie baut die Optionen für den Browser
 * (navigator.credentials.create()/get()) und prüft, was er zurückschickt. Wo die
 * Schlüssel liegen und wer sie benutzen darf, regelt der Aufrufer
 * (Modules\Admin\Passkeys, WebAuthnFactor).
 *
 * RELYING PARTY
 * --------------------
 * Ein Passkey gehört genau einer Domain (RP ID). Das ist die feste Backend-Domain
 * (admin.backend_domain, BackendDomain) ohne Port; ohne sie ist WebAuthn nicht
 * verfügbar (available()). Origin = Schema + Backend-Domain (+ Port), https - http
 * nur bei admin.cookie_secure = false (lokaler Test; Browser erlauben WebAuthn ohne
 * TLS nur unter localhost).
 *
 * WAS GEPRÜFT WIRD (W3C WebAuthn Level 2, §7.1 / §7.2)
 * --------------------
 *     clientDataJSON   type (webauthn.create | webauthn.get), challenge (wörtlich,
 *                      base64url), origin, crossOrigin nicht true
 *     authenticatorData  rpIdHash = SHA-256(RP ID), Flag UP (Benutzer anwesend);
 *                      UV (Benutzer verifiziert, PIN/Biometrie) wird gemeldet,
 *                      nicht verlangt - der Passkey ist hier der ZWEITE Faktor
 *     Registrierung    Flag AT, Credential-Id, öffentlicher Schlüssel (COSE)
 *     Anmeldung        Signatur über authenticatorData || SHA-256(clientDataJSON)
 *                      mit dem gespeicherten Schlüssel; Signaturzähler muss steigen,
 *                      wenn der Authenticator einen führt (sonst geklont)
 *
 * Attestation: angefordert wird 'none'. Liefert ein Authenticator trotzdem eine
 * (fmt packed, tpm, …), wird sie nicht ausgewertet - camindo verlässt sich nicht
 * auf die Herkunft des Geräts, nur auf den Besitz des Schlüssels.
 *
 * Algorithmen: ES256 (-7, P-256), EdDSA (-8, Ed25519, über libsodium), RS256 (-257).
 * Die Schlüssel werden als PEM (SubjectPublicKeyInfo) gespeichert.
 *
 * @see Camindo\Core\Modules\Admin\WebAuthnFactor
 * @see Camindo\Core\Modules\Admin\Passkeys
 */
namespace Camindo\Core;

class WebAuthn
{
    public const ALG_ES256 = -7;
    public const ALG_EDDSA = -8;
    public const ALG_RS256 = -257;

    /** Zeit für die Geste am Gerät, in Millisekunden (Browser-Vorgabe) */
    public const TIMEOUT_MS = 120000;

    /** Länge einer Challenge in Bytes */
    public const CHALLENGE_BYTES = 32;

    private const FLAG_UP = 0x01;
    private const FLAG_UV = 0x04;
    private const FLAG_AT = 0x40;

    public function __construct(
        private Config $config,
        private BackendDomain $backendDomain,
    ) {}

    // ====================
    // Relying Party

    /** WebAuthn nutzbar? Braucht die feste Backend-Domain; abschaltbar mit security.webauthn = false */
    public function available(): bool
    {
        return $this->config->get('security.webauthn') !== false && $this->rpId() !== '';
    }

    /** RP ID: Backend-Domain ohne Port, '' ohne feste Domain */
    public function rpId(): string
    {
        $domain = $this->backendDomain->configured();
        if ($domain === '' || str_starts_with($domain, '[')) return '';
        return (string)preg_replace('/:\d+$/', '', $domain);
    }

    /** Erwarteter Origin (Schema://Host[:Port]) */
    public function origin(): string
    {
        $domain = $this->backendDomain->configured();
        $scheme = $this->config->get('admin.cookie_secure') === false ? 'http' : 'https';
        return $scheme . '://' . $domain;
    }

    // ====================
    // Optionen für den Browser (Binärwerte base64url, das Script wandelt sie)

    /**
     * Optionen für navigator.credentials.create().
     *
     * @param string   $userHandle   stabile, nicht sprechende Kennung des Kontos (Bytes, ≤ 64)
     * @param string   $name         Anmeldename (Anzeige im Passkey-Dialog)
     * @param string   $displayName  Anzeigename
     * @param string   $challenge    CHALLENGE_BYTES rohe Bytes
     * @param string[] $excludeIds   Credential-Ids (roh), die das Konto schon hat
     */
    public function creationOptions(string $userHandle, string $name, string $displayName,
        string $challenge, array $excludeIds = []): array
    {
        $rpName = trim((string)$this->config->get('security.totp_issuer')) ?: 'camindo CMS';
        return [
            'challenge' => self::b64uEncode($challenge),
            'rp' => ['id' => $this->rpId(), 'name' => $rpName],
            'user' => [
                'id' => self::b64uEncode($userHandle),
                'name' => $name,
                'displayName' => $displayName !== '' ? $displayName : $name,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => self::ALG_ES256],
                ['type' => 'public-key', 'alg' => self::ALG_EDDSA],
                ['type' => 'public-key', 'alg' => self::ALG_RS256],
            ],
            'timeout' => self::TIMEOUT_MS,
            'attestation' => 'none',
            'authenticatorSelection' => [
                // Passkey auf dem Gerät speichern, wenn es geht - Grundlage für eine
                // spätere Anmeldung ohne Namen
                'residentKey' => 'preferred',
                'userVerification' => 'preferred',
            ],
            'excludeCredentials' => array_map(
                fn(string $id) => ['type' => 'public-key', 'id' => self::b64uEncode($id)],
                array_values($excludeIds)
            ),
        ];
    }

    /**
     * Optionen für navigator.credentials.get().
     *
     * @param string   $challenge  CHALLENGE_BYTES rohe Bytes
     * @param array    $allow      Liste aus ['id' => roh, 'transports' => string[]]
     */
    public function requestOptions(string $challenge, array $allow): array
    {
        return [
            'challenge' => self::b64uEncode($challenge),
            'rpId' => $this->rpId(),
            'timeout' => self::TIMEOUT_MS,
            'userVerification' => 'preferred',
            'allowCredentials' => array_map(function (array $credential): array {
                $entry = ['type' => 'public-key', 'id' => self::b64uEncode((string)$credential['id'])];
                $transports = array_values(array_filter((array)($credential['transports'] ?? []), 'is_string'));
                if ($transports) $entry['transports'] = $transports;
                return $entry;
            }, array_values($allow)),
        ];
    }

    // ====================
    // Prüfen

    /**
     * Antwort von navigator.credentials.create() prüfen.
     *
     * @param string $clientDataJSON     roh (nicht base64)
     * @param string $attestationObject  roh
     * @param string $challenge          die ausgegebene Challenge, roh
     * @return array  credential_id (roh), public_key (PEM), alg, sign_count, aaguid (16 Bytes),
     *                uv (bool), backup_eligible (bool)
     * @throws \RuntimeException  mit kurzem englischem Grund (fürs Log)
     */
    public function verifyRegistration(string $clientDataJSON, string $attestationObject, string $challenge): array
    {
        $this->checkClientData($clientDataJSON, 'webauthn.create', $challenge);

        [$attestation] = self::cborDecode($attestationObject);
        if (!is_array($attestation) || !isset($attestation['authData']) || !is_string($attestation['authData'])) {
            throw new \RuntimeException('attestation object without authData');
        }
        $auth = $this->parseAuthData($attestation['authData']);
        if (!($auth['flags'] & self::FLAG_AT) || !isset($auth['credential_id'])) {
            throw new \RuntimeException('no attested credential data');
        }
        return [
            'credential_id' => $auth['credential_id'],
            'public_key' => $auth['public_key'],
            'alg' => $auth['alg'],
            'sign_count' => $auth['sign_count'],
            'aaguid' => $auth['aaguid'],
            'uv' => (bool)($auth['flags'] & self::FLAG_UV),
            'backup_eligible' => (bool)($auth['flags'] & 0x08),
        ];
    }

    /**
     * Antwort von navigator.credentials.get() prüfen.
     *
     * @param string $publicKeyPem  gespeicherter Schlüssel
     * @param int    $alg           gespeicherter Algorithmus
     * @param int    $storedCount   gespeicherter Signaturzähler
     * @return int   neuer Signaturzähler
     * @throws \RuntimeException
     */
    public function verifyAssertion(string $clientDataJSON, string $authenticatorData, string $signature,
        string $challenge, string $publicKeyPem, int $alg, int $storedCount): int
    {
        $this->checkClientData($clientDataJSON, 'webauthn.get', $challenge);
        $auth = $this->parseAuthData($authenticatorData);

        $signed = $authenticatorData . hash('sha256', $clientDataJSON, true);
        if (!self::verifySignature($signed, $signature, $publicKeyPem, $alg)) {
            throw new \RuntimeException('signature invalid');
        }
        // Zähler: 0 = der Authenticator führt keinen (Passkeys in iCloud/Google)
        $count = $auth['sign_count'];
        if (($count !== 0 || $storedCount !== 0) && $count <= $storedCount) {
            throw new \RuntimeException('signature counter did not increase (cloned authenticator?)');
        }
        return $count;
    }

    // ====================
    // Hilfen

    /** Neue Challenge (rohe Bytes) */
    public static function challenge(): string
    {
        return random_bytes(self::CHALLENGE_BYTES);
    }

    public static function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** base64url (auch mit Standard-Alphabet oder Padding) - '' bei Unsinn */
    public static function b64uDecode(string $text): string
    {
        $text = strtr(trim($text), '-_', '+/');
        if ($text === '' || !preg_match('#^[A-Za-z0-9+/]*=*$#', $text)) return '';
        $raw = base64_decode($text . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        return is_string($raw) ? $raw : '';
    }

    private function checkClientData(string $json, string $type, string $challenge): void
    {
        $data = json_decode($json, true);
        if (!is_array($data)) throw new \RuntimeException('clientDataJSON not JSON');
        if (($data['type'] ?? '') !== $type) throw new \RuntimeException('clientData type mismatch');
        if (!is_string($data['challenge'] ?? null)
            || !hash_equals(self::b64uEncode($challenge), rtrim(strtr($data['challenge'], '+/', '-_'), '='))) {
            throw new \RuntimeException('challenge mismatch');
        }
        if (($data['origin'] ?? '') !== $this->origin()) {
            throw new \RuntimeException('origin mismatch: ' . mb_substr((string)($data['origin'] ?? ''), 0, 100));
        }
        if (!empty($data['crossOrigin'])) throw new \RuntimeException('cross-origin request');
    }

    /**
     * authenticatorData zerlegen (§6.1): rpIdHash 32, flags 1, signCount 4, danach
     * bei AT: aaguid 16, Länge 2, Credential-Id, COSE-Schlüssel.
     */
    private function parseAuthData(string $data): array
    {
        if (strlen($data) < 37) throw new \RuntimeException('authenticatorData too short');
        $rpId = $this->rpId();
        if ($rpId === '' || !hash_equals(hash('sha256', $rpId, true), substr($data, 0, 32))) {
            throw new \RuntimeException('rpIdHash mismatch');
        }
        $flags = ord($data[32]);
        if (!($flags & self::FLAG_UP)) throw new \RuntimeException('user not present');
        $result = [
            'flags' => $flags,
            'sign_count' => unpack('N', substr($data, 33, 4))[1],
        ];
        if ($flags & self::FLAG_AT) {
            if (strlen($data) < 55) throw new \RuntimeException('attested credential data too short');
            $result['aaguid'] = substr($data, 37, 16);
            $length = unpack('n', substr($data, 53, 2))[1];
            if ($length < 16 || $length > 1023 || strlen($data) < 55 + $length) {
                throw new \RuntimeException('credential id length invalid');
            }
            $result['credential_id'] = substr($data, 55, $length);
            [$cose] = self::cborDecode($data, 55 + $length);
            [$result['public_key'], $result['alg']] = self::coseToPem($cose);
        }
        return $result;
    }

    /**
     * COSE_Key (RFC 8152 §13) -> [PEM, alg]. Nur die drei angebotenen Verfahren.
     */
    private static function coseToPem(mixed $cose): array
    {
        if (!is_array($cose)) throw new \RuntimeException('COSE key not a map');
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;
        if ($kty === 2 && $alg === self::ALG_ES256) {
            $x = $cose[-2] ?? '';
            $y = $cose[-3] ?? '';
            if (($cose[-1] ?? null) !== 1 || !is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new \RuntimeException('EC2 key invalid (P-256 expected)');
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        }
        elseif ($kty === 1 && $alg === self::ALG_EDDSA) {
            $x = $cose[-2] ?? '';
            if (($cose[-1] ?? null) !== 6 || !is_string($x) || strlen($x) !== 32) {
                throw new \RuntimeException('OKP key invalid (Ed25519 expected)');
            }
            $der = hex2bin('302a300506032b6570032100') . $x;
        }
        elseif ($kty === 3 && $alg === self::ALG_RS256) {
            $n = $cose[-1] ?? '';
            $e = $cose[-2] ?? '';
            if (!is_string($n) || !is_string($e) || strlen($n) < 256 || $e === '') {
                throw new \RuntimeException('RSA key invalid (2048 bit or more expected)');
            }
            $rsaKey = self::derSequence(self::derInteger($n) . self::derInteger($e));
            $algId = hex2bin('300d06092a864886f70d0101010500');
            $der = self::derSequence($algId . "\x03" . self::derLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey);
        }
        else {
            throw new \RuntimeException('unsupported key type/algorithm');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        // Gegenprobe: OpenSSL muss den Schlüssel lesen können (Ed25519 prüft libsodium)
        if ($alg !== self::ALG_EDDSA && openssl_pkey_get_public($pem) === false) {
            throw new \RuntimeException('public key not readable');
        }
        return [$pem, $alg];
    }

    private static function verifySignature(string $data, string $signature, string $pem, int $alg): bool
    {
        if ($signature === '') return false;
        if ($alg === self::ALG_EDDSA) {
            if (!function_exists('sodium_crypto_sign_verify_detached') || strlen($signature) !== 64) return false;
            $der = base64_decode((string)preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
            if (!is_string($der) || strlen($der) !== 44) return false;
            return sodium_crypto_sign_verify_detached($signature, $data, substr($der, 12));
        }
        if ($alg !== self::ALG_ES256 && $alg !== self::ALG_RS256) return false;
        // ES256: WebAuthn liefert die Signatur schon DER-kodiert, wie OpenSSL sie will
        return openssl_verify($data, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    // ---- DER ----

    private static function derLength(int $length): string
    {
        if ($length < 0x80) return chr($length);
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    private static function derInteger(string $unsigned): string
    {
        $unsigned = ltrim($unsigned, "\x00");
        if ($unsigned === '' || (ord($unsigned[0]) & 0x80)) $unsigned = "\x00" . $unsigned;
        return "\x02" . self::derLength(strlen($unsigned)) . $unsigned;
    }

    // ---- CBOR (RFC 8949) - nur, was WebAuthn braucht ----

    /**
     * Ein CBOR-Datenelement ab $offset lesen.
     *
     * Byte- und Textstrings werden beide zu PHP-Strings, Maps zu Arrays (Schlüssel
     * int oder string). Keine unbestimmten Längen, keine Tags, Tiefe höchstens 16.
     *
     * @return array{0:mixed,1:int}  [Wert, Offset danach]
     */
    public static function cborDecode(string $data, int $offset = 0, int $depth = 0): array
    {
        if ($depth > 16) throw new \RuntimeException('CBOR nested too deep');
        if ($offset >= strlen($data)) throw new \RuntimeException('CBOR truncated');
        $initial = ord($data[$offset++]);
        $major = $initial >> 5;
        $info = $initial & 0x1f;

        if ($major === 7) {
            return match ($info) {
                20 => [false, $offset],
                21 => [true, $offset],
                22, 23 => [null, $offset],
                25 => [self::cborHalf(self::cborBytes($data, $offset, 2)), $offset + 2],
                26 => [unpack('G', self::cborBytes($data, $offset, 4))[1], $offset + 4],
                27 => [unpack('E', self::cborBytes($data, $offset, 8))[1], $offset + 8],
                default => throw new \RuntimeException('CBOR simple value not supported'),
            };
        }

        if ($info < 24) {
            $value = $info;
        }
        elseif ($info === 24) {
            $value = ord(self::cborBytes($data, $offset, 1));
            $offset += 1;
        }
        elseif ($info === 25) {
            $value = unpack('n', self::cborBytes($data, $offset, 2))[1];
            $offset += 2;
        }
        elseif ($info === 26) {
            $value = unpack('N', self::cborBytes($data, $offset, 4))[1];
            $offset += 4;
        }
        elseif ($info === 27) {
            $value = unpack('J', self::cborBytes($data, $offset, 8))[1];
            if ($value < 0) throw new \RuntimeException('CBOR integer too large');
            $offset += 8;
        }
        else {
            throw new \RuntimeException('CBOR indefinite length not supported');
        }

        switch ($major) {
            case 0:
                return [$value, $offset];
            case 1:
                return [-1 - $value, $offset];
            case 2:
            case 3:
                return [self::cborBytes($data, $offset, $value), $offset + $value];
            case 4:
                if ($value > 1024) throw new \RuntimeException('CBOR array too long');
                $list = [];
                for ($i = 0; $i < $value; $i++) {
                    [$list[], $offset] = self::cborDecode($data, $offset, $depth + 1);
                }
                return [$list, $offset];
            case 5:
                if ($value > 1024) throw new \RuntimeException('CBOR map too long');
                $map = [];
                for ($i = 0; $i < $value; $i++) {
                    [$key, $offset] = self::cborDecode($data, $offset, $depth + 1);
                    if (!is_int($key) && !is_string($key)) throw new \RuntimeException('CBOR map key type');
                    [$map[$key], $offset] = self::cborDecode($data, $offset, $depth + 1);
                }
                return [$map, $offset];
            default:
                throw new \RuntimeException('CBOR tags not supported');
        }
    }

    private static function cborBytes(string $data, int $offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($data)) throw new \RuntimeException('CBOR truncated');
        return substr($data, $offset, $length);
    }

    private static function cborHalf(string $bytes): float
    {
        $half = unpack('n', $bytes)[1];
        $exp = ($half >> 10) & 0x1f;
        $mant = $half & 0x3ff;
        $value = $exp === 0 ? $mant * 2 ** -24 : ($exp !== 31 ? ($mant + 1024) * 2 ** ($exp - 25) : ($mant === 0 ? INF : NAN));
        return ($half & 0x8000) ? -$value : $value;
    }
}
