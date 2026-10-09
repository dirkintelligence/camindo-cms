<?php
/**
 * Time-based one-time passwords per RFC 6238 (TOTP) - the codes of the
 * authenticator apps (Google Authenticator, Aegis, 1Password, Microsoft …).
 *
 * Pure computation without state and without a library: HMAC-SHA1 over the time step
 * (RFC 4226, "dynamic truncation"), 30 seconds per step, 6 digits. These are the
 * values every app accepts without further details - the otpauth:// URI names them
 * anyway, so that nothing is guessed.
 *
 * verify() allows one step of deviation in either direction (clocks of phone
 * and server rarely match to the second) and returns the matched
 * step. The caller must remember it and next time accept only later
 * steps - otherwise an intercepted code could be used a second time within
 * its 90 seconds (replay, see
 * Camindo\Core\Modules\Admin\TotpFactor).
 *
 * The secret is 20 random bytes (160 bits, the length of the SHA1 block per RFC 4226
 * §4), Base32-encoded for the app (RFC 4648, without '=').
 *
 * @see Camindo\Core\Modules\Admin\TotpFactor  Speicherung, Replay-Schutz, Wiederherstellungscodes
 */
namespace Camindo\Core;

class Totp {

    public const DIGITS = 6;
    public const PERIOD = 30;
    /** Allowed deviation in time steps (per direction) */
    public const WINDOW = 1;
    /** Length of the secret in bytes */
    public const SECRET_BYTES = 20;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** New secret, raw (20 bytes) */
    public static function generateSecret(): string
    {
        return random_bytes(self::SECRET_BYTES);
    }

    /** Time step for a Unix timestamp */
    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /**
     * Code for a time step (RFC 4226 §5.3).
     *
     * @param string $secret  raw secret
     */
    public static function code(string $secret, int $step): string
    {
        // Counter as 8 bytes big-endian
        $hmac = hash_hmac('sha1', pack('J', $step), $secret, true);
        // Dynamic truncation: the lower 4 bits of the last byte select 4 bytes
        $offset = ord($hmac[19]) & 0x0f;
        $value = ((ord($hmac[$offset]) & 0x7f) << 24)
            | (ord($hmac[$offset + 1]) << 16)
            | (ord($hmac[$offset + 2]) << 8)
            | ord($hmac[$offset + 3]);
        return str_pad((string)($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Check a code.
     *
     * Every step in the window is compared with hash_equals(), also after a
     * match - the runtime should not reveal which step matched.
     *
     * @param string $secret     raw secret
     * @param string $code       input; spaces are removed ("123 456")
     * @param int    $afterStep  only steps GREATER than this count (replay protection)
     * @return int|null          the matched time step, null = no valid code
     */
    public static function verify(string $secret, string $code, int $afterStep = 0, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if ($secret === '' || !preg_match('/^\d{' . self::DIGITS . '}$/', $code)) return null;
        $current = self::step($time);
        $matched = null;
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if (hash_equals(self::code($secret, $step), $code) && $step > $afterStep && $matched === null) {
                $matched = $step;
            }
        }
        return $matched;
    }

    /**
     * otpauth:// URI for the QR code (key URI format, understood by all common
     * apps).
     *
     * @param string $secret   raw secret
     * @param string $issuer   provider, shown in the app above the account
     * @param string $account  account name (login name)
     */
    public static function uri(string $secret, string $issuer, string $account): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret'    => self::base32Encode($secret),
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Base32 per RFC 4648, without padding characters */
    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    /**
     * Decode Base32 (case, spaces and '=' do not matter).
     *
     * @return string  raw bytes; '' on invalid characters
     */
    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=]+/', '', $text) ?? '');
        $bits = '';
        foreach (str_split($text) as $char) {
            $pos = strpos(self::BASE32, $char);
            if ($pos === false) return '';
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr(bindec($byte));
        }
        return $out;
    }
}
