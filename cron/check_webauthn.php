<?php
/**
 * check_webauthn.php - Selbsttest für Camindo\Core\WebAuthn (developer_docs/22-webauthn.md)
 *
 *     php Core/cron/check_webauthn.php
 *
 * Ein Software-Authenticator (OpenSSL/libsodium) registriert je einen Schlüssel ES256,
 * RS256 und EdDSA und meldet sich an; dazu die Fehlerfälle: falscher Origin, falsche
 * Challenge, falsche RP ID, Zähler nicht gestiegen, Signatur manipuliert, fehlendes
 * UP-Flag, falscher Typ, kaputtes CBOR. Ohne Datenbank; RP ID fest cms.example.com.
 * Exit-Code 1 bei Abweichungen.
 */
use Camindo\Core\{WebAuthn, Config, BackendDomain};

// Nur auf der Kommandozeile erreichbar, nicht per Webserver
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Bootstrap laden
require_once dirname(__DIR__, 2) . '/load_camindo.php';

$c = ['admin' => ['backend_domain' => 'cms.example.com', 'cookie_secure' => true]];
$cfg = new Config($c);
$wa = new WebAuthn($cfg, new BackendDomain($cfg));

// CBOR-Encoder und authenticatorData für den Software-Authenticator
function cb($v): string { // CBOR-Encoder für den Test
    $head = function (int $major, int $n): string {
        if ($n < 24) return chr(($major << 5) | $n);
        if ($n < 256) return chr(($major << 5) | 24) . chr($n);
        if ($n < 65536) return chr(($major << 5) | 25) . pack('n', $n);
        return chr(($major << 5) | 26) . pack('N', $n);
    };
    if (is_int($v)) return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
    if (is_array($v) && array_key_exists('__bytes', $v)) return $head(2, strlen($v['__bytes'])) . $v['__bytes'];
    if (is_string($v)) return $head(3, strlen($v)) . $v;
    if (is_array($v)) { $s = $head(5, count($v)); foreach ($v as $k => $x) $s .= cb($k) . cb($x); return $s; }
    throw new Exception('type');
}
function B($s) { return ['__bytes' => $s]; }
function authData(string $rpId, int $flags, int $count, ?string $credId = null, ?array $cose = null): string {
    $d = hash('sha256', $rpId, true) . chr($flags) . pack('N', $count);
    if ($credId !== null) $d .= str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId . cb($cose);
    return $d;
}
function clientData(string $type, string $challenge, string $origin): string {
    return json_encode(['type' => $type, 'challenge' => WebAuthn::b64uEncode($challenge), 'origin' => $origin, 'crossOrigin' => false]);
}
$origin = 'https://cms.example.com';
$keys = [];
// ES256
$k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]); $d = openssl_pkey_get_details($k);
$keys['ES256'] = [WebAuthn::ALG_ES256, [1 => 2, 3 => -7, -1 => 1, -2 => B($d['ec']['x']), -3 => B($d['ec']['y'])],
    fn($m) => (openssl_sign($m, $s, $k, OPENSSL_ALGO_SHA256) ? $s : '')];
// RS256
$r = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]); $rd = openssl_pkey_get_details($r);
$keys['RS256'] = [WebAuthn::ALG_RS256, [1 => 3, 3 => -257, -1 => B($rd['rsa']['n']), -2 => B($rd['rsa']['e'])],
    fn($m) => (openssl_sign($m, $s, $r, OPENSSL_ALGO_SHA256) ? $s : '')];
// EdDSA
$kp = sodium_crypto_sign_keypair();
$keys['EdDSA'] = [WebAuthn::ALG_EDDSA, [1 => 1, 3 => -8, -1 => 6, -2 => B(sodium_crypto_sign_publickey($kp))],
    fn($m) => sodium_crypto_sign_detached($m, sodium_crypto_sign_secretkey($kp))];

$ok = 0; $fail = 0;
$expect = function (string $label, callable $fn, bool $shouldPass) use (&$ok, &$fail) {
    try { $fn(); $passed = true; $msg = ''; } catch (Throwable $e) { $passed = false; $msg = $e->getMessage(); }
    $good = $passed === $shouldPass; $good ? $ok++ : $fail++;
    printf("%-4s %-52s %s\n", $good ? 'ok' : 'FAIL', $label, $msg);
};
foreach ($keys as $name => [$alg, $cose, $sign]) {
    $credId = random_bytes(32);
    $ch = WebAuthn::challenge();
    $att = cb(['fmt' => 'none', 'attStmt' => [], 'authData' => B(authData('cms.example.com', 0x41 | 0x04, 0, $credId, $cose))]);
    $reg = null;
    $expect("$name Registrierung", function () use (&$reg, $wa, $ch, $att, $origin) {
        $reg = $wa->verifyRegistration(clientData('webauthn.create', $ch, $origin), $att, $ch); }, true);
    if (!$reg) continue;
    if ($reg['credential_id'] !== $credId || $reg['alg'] !== $alg) { echo "FAIL data\n"; $fail++; }
    $expect("$name Registrierung falscher Origin", fn() => $wa->verifyRegistration(clientData('webauthn.create', $ch, 'https://evil.example'), $att, $ch), false);
    $expect("$name Registrierung falsche Challenge", fn() => $wa->verifyRegistration(clientData('webauthn.create', $ch, $origin), $att, WebAuthn::challenge()), false);
    $attBadRp = cb(['fmt' => 'none', 'attStmt' => [], 'authData' => B(authData('evil.example', 0x41, 0, $credId, $cose))]);
    $expect("$name Registrierung falsche RP ID", fn() => $wa->verifyRegistration(clientData('webauthn.create', $ch, $origin), $attBadRp, $ch), false);

    // Anmeldung
    $ch2 = WebAuthn::challenge();
    $ad = authData('cms.example.com', 0x05, 7);
    $cd = clientData('webauthn.get', $ch2, $origin);
    $sig = $sign($ad . hash('sha256', $cd, true));
    $expect("$name Anmeldung", function () use ($wa, $cd, $ad, $sig, $ch2, $reg) {
        if ($wa->verifyAssertion($cd, $ad, $sig, $ch2, $reg['public_key'], $reg['alg'], 3) !== 7) throw new Exception('count'); }, true);
    $expect("$name Anmeldung Zähler nicht gestiegen", fn() => $wa->verifyAssertion($cd, $ad, $sig, $ch2, $reg['public_key'], $reg['alg'], 7), false);
    $expect("$name Anmeldung Signatur manipuliert", fn() => $wa->verifyAssertion($cd, $ad, substr($sig, 0, -1) . chr(ord(substr($sig, -1)) ^ 1), $ch2, $reg['public_key'], $reg['alg'], 3), false);
    $expect("$name Anmeldung andere Challenge", fn() => $wa->verifyAssertion($cd, $ad, $sig, WebAuthn::challenge(), $reg['public_key'], $reg['alg'], 3), false);
    $adNoUp = authData('cms.example.com', 0x04, 8); $sig2 = $sign($adNoUp . hash('sha256', $cd, true));
    $expect("$name Anmeldung ohne UP-Flag", fn() => $wa->verifyAssertion($cd, $adNoUp, $sig2, $ch2, $reg['public_key'], $reg['alg'], 3), false);
    $cdCreate = clientData('webauthn.create', $ch2, $origin); $sig3 = $sign($ad . hash('sha256', $cdCreate, true));
    $expect("$name Anmeldung falscher Typ", fn() => $wa->verifyAssertion($cdCreate, $ad, $sig3, $ch2, $reg['public_key'], $reg['alg'], 3), false);
    $ad0 = authData('cms.example.com', 0x01, 0); $sig0 = $sign($ad0 . hash('sha256', $cd, true));
    $expect("$name Anmeldung Zähler 0/0 (Passkey ohne Zähler)", fn() => $wa->verifyAssertion($cd, $ad0, $sig0, $ch2, $reg['public_key'], $reg['alg'], 0), true);
}
$expect("CBOR Müll", fn() => WebAuthn::cborDecode("\xff\x00"), false);
$expect("CBOR abgeschnitten", fn() => WebAuthn::cborDecode("\x59\x01\x00ab"), false);
echo "\n$ok ok, $fail fehlgeschlagen\n";
exit($fail > 0 ? 1 : 0);
