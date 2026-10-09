<?php
/**
 * WebAuthn (passkeys, security keys) - the check on the server, without a
 * library. Developer docs, chapter 13 (Security: authentication).
 *
 * The class knows no database: it builds the options for the browser
 * (navigator.credentials.create()/get()) and checks what the browser sends back. The
 * caller decides where the keys are stored and who may use them
 * (Modules\Admin\Passkeys, WebAuthnFactor).
 *
 * RELYING PARTY
 * --------------------
 * A passkey belongs to exactly one domain (RP ID). That is the fixed backend domain
 * (admin.backend_domain, BackendDomain) without a port; without it WebAuthn is not
 * available (available()). Origin = scheme + backend domain (+ port), https - http
 * only with admin.cookie_secure = false (local testing; browsers allow WebAuthn
 * without TLS only on localhost).
 *
 * WHAT IS CHECKED (W3C WebAuthn Level 2, §7.1 / §7.2)
 * --------------------
 *     clientDataJSON   type (webauthn.create | webauthn.get), challenge (verbatim,
 *                      base64url), origin, crossOrigin not true
 *     authenticatorData  rpIdHash = SHA-256(RP ID), flag UP (user present);
 *                      UV (user verified, PIN/biometrics) is reported,
 *                      not required - here the passkey is the SECOND factor
 *     Registration     flag AT, credential id, public key (COSE)
 *     Sign-in          signature over authenticatorData || SHA-256(clientDataJSON)
 *                      with the stored key; the signature counter must increase
 *                      if the authenticator keeps one (otherwise cloned)
 *
 * Attestation: 'none' is requested. If an authenticator still returns one
 * (fmt packed, tpm, …), it is not evaluated - camindo does not rely on where the
 * device comes from, only on possession of the key.
 *
 * Algorithms: ES256 (-7, P-256), EdDSA (-8, Ed25519, via libsodium), RS256 (-257).
 * The keys are stored as PEM (SubjectPublicKeyInfo).
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

    /** Time for the gesture on the device, in milliseconds (browser default) */
    public const TIMEOUT_MS = 120000;

    /** Length of a challenge in bytes */
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

    /** Is WebAuthn usable? Requires the fixed backend domain; can be switched off with security.webauthn = false */
    public function available(): bool
    {
        return $this->config->get('security.webauthn') !== false && $this->rpId() !== '';
    }

    /** RP ID: backend domain without port, '' without a fixed domain */
    public function rpId(): string
    {
        $domain = $this->backendDomain->configured();
        if ($domain === '' || str_starts_with($domain, '[')) return '';
        return (string)preg_replace('/:\d+$/', '', $domain);
    }

    /** Expected origin (scheme://host[:port]) */
    public function origin(): string
    {
        $domain = $this->backendDomain->configured();
        $scheme = $this->config->get('admin.cookie_secure') === false ? 'http' : 'https';
        return $scheme . '://' . $domain;
    }

    // ====================
    // Options for the browser (binary values base64url, the script converts them)

    /**
     * Options for navigator.credentials.create().
     *
     * @param string   $userHandle   stable, non-descriptive identifier of the account (bytes, ≤ 64)
     * @param string   $name         sign-in name (shown in the passkey dialog)
     * @param string   $displayName  display name
     * @param string   $challenge    CHALLENGE_BYTES raw bytes
     * @param string[] $excludeIds   credential ids (raw) that the account already has
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
                // Store the passkey on the device if possible - basis for a later
                // sign-in without a name
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
     * Options for navigator.credentials.get().
     *
     * @param string   $challenge  CHALLENGE_BYTES raw bytes
     * @param array    $allow      list of ['id' => raw, 'transports' => string[]]
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
    // Checking

    /**
     * Check the response of navigator.credentials.create().
     *
     * @param string $clientDataJSON     raw (not base64)
     * @param string $attestationObject  raw
     * @param string $challenge          the issued challenge, raw
     * @return array  credential_id (raw), public_key (PEM), alg, sign_count, aaguid (16 bytes),
     *                uv (bool), backup_eligible (bool)
     * @throws \RuntimeException  with a short English reason (for the log)
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
     * Check the response of navigator.credentials.get().
     *
     * @param string $publicKeyPem  stored key
     * @param int    $alg           stored algorithm
     * @param int    $storedCount   stored signature counter
     * @return int   new signature counter
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
        // Counter: 0 = the authenticator does not keep one (passkeys in iCloud/Google)
        $count = $auth['sign_count'];
        if (($count !== 0 || $storedCount !== 0) && $count <= $storedCount) {
            throw new \RuntimeException('signature counter did not increase (cloned authenticator?)');
        }
        return $count;
    }

    // ====================
    // Hilfen

    /** New challenge (raw bytes) */
    public static function challenge(): string
    {
        return random_bytes(self::CHALLENGE_BYTES);
    }

    public static function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** base64url (also with standard alphabet or padding) - '' on invalid input */
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
     * Split authenticatorData (§6.1): rpIdHash 32, flags 1, signCount 4, then
     * with AT: aaguid 16, length 2, credential id, COSE key.
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
     * COSE_Key (RFC 8152 §13) -> [PEM, alg]. Only the three offered algorithms.
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
        // Cross-check: OpenSSL must be able to read the key (Ed25519 is checked by libsodium)
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
        // ES256: WebAuthn already delivers the signature DER-encoded, as OpenSSL expects it
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

    // ---- CBOR (RFC 8949) - only what WebAuthn needs ----

    /**
     * Read a CBOR data item starting at $offset.
     *
     * Byte and text strings both become PHP strings, maps become arrays (keys
     * int or string). No indefinite lengths, no tags, depth at most 16.
     *
     * @return array{0:mixed,1:int}  [value, offset after it]
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
