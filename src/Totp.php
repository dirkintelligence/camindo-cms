<?php
/**
 * Zeitbasierte Einmal-Passwörter nach RFC 6238 (TOTP) - die Codes der
 * Authenticator-Apps (Google Authenticator, Aegis, 1Password, Microsoft …).
 *
 * Reine Rechnung ohne Zustand und ohne Bibliothek: HMAC-SHA1 über den Zeitschritt
 * (RFC 4226, "dynamic truncation"), 30 Sekunden je Schritt, 6 Stellen. Das sind die
 * Werte, die jede App ohne weitere Angaben annimmt - die otpauth://-URI nennt sie
 * trotzdem, damit nichts geraten wird.
 *
 * verify() lässt einen Schritt Abweichung in beide Richtungen zu (Uhren von Handy
 * und Server gehen selten auf die Sekunde gleich) und liefert den getroffenen
 * Schritt zurück. Den muss der Aufrufer sich merken und beim nächsten Mal nur
 * noch spätere Schritte annehmen - sonst ließe sich ein abgefangener Code innerhalb
 * seiner 90 Sekunden ein zweites Mal verwenden (Replay, siehe
 * Camindo\Core\Modules\Admin\TotpFactor).
 *
 * Das Secret sind 20 Zufallsbytes (160 Bit, die Länge des SHA1-Blocks laut RFC 4226
 * §4), für die App Base32-kodiert (RFC 4648, ohne '=').
 *
 * @see Camindo\Core\Modules\Admin\TotpFactor  Speicherung, Replay-Schutz, Wiederherstellungscodes
 */
namespace Camindo\Core;

class Totp {

    public const DIGITS = 6;
    public const PERIOD = 30;
    /** Erlaubte Abweichung in Zeitschritten (je Richtung) */
    public const WINDOW = 1;
    /** Länge des Secrets in Bytes */
    public const SECRET_BYTES = 20;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Neues Secret, roh (20 Bytes) */
    public static function generateSecret(): string
    {
        return random_bytes(self::SECRET_BYTES);
    }

    /** Zeitschritt zu einem Unix-Zeitstempel */
    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /**
     * Code zu einem Zeitschritt (RFC 4226 §5.3).
     *
     * @param string $secret  rohes Secret
     */
    public static function code(string $secret, int $step): string
    {
        // Zähler als 8 Byte big-endian
        $hmac = hash_hmac('sha1', pack('J', $step), $secret, true);
        // Dynamic truncation: die unteren 4 Bit des letzten Bytes wählen 4 Bytes aus
        $offset = ord($hmac[19]) & 0x0f;
        $value = ((ord($hmac[$offset]) & 0x7f) << 24)
            | (ord($hmac[$offset + 1]) << 16)
            | (ord($hmac[$offset + 2]) << 8)
            | ord($hmac[$offset + 3]);
        return str_pad((string)($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Code prüfen.
     *
     * Verglichen wird jeder Schritt im Fenster mit hash_equals(), auch nach einem
     * Treffer - die Laufzeit soll nicht verraten, welcher Schritt passte.
     *
     * @param string $secret     rohes Secret
     * @param string $code       Eingabe; Leerzeichen werden entfernt ("123 456")
     * @param int    $afterStep  nur Schritte GRÖSSER als dieser gelten (Replay-Schutz)
     * @return int|null          der getroffene Zeitschritt, null = kein gültiger Code
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
     * otpauth://-URI für den QR-Code (Key Uri Format, von allen gängigen Apps
     * verstanden).
     *
     * @param string $secret   rohes Secret
     * @param string $issuer   Anbieter, erscheint in der App über dem Konto
     * @param string $account  Kontoname (Anmeldename)
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

    /** Base32 nach RFC 4648, ohne Auffüllzeichen */
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
     * Base32 dekodieren (Groß-/Kleinschreibung, Leerzeichen und '=' egal).
     *
     * @return string  rohe Bytes; '' bei ungültigen Zeichen
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
