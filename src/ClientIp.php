<?php
/**
 * Herkunft eines Requests (IP-Adresse des Besuchers) - für Drosselung
 * (RateLimiter), Brute-Force-Schutz (LoginGuard) und Anmeldegeräte.
 *
 * X-FORWARDED-FOR NUR VON BEKANNTEN PROXIES
 * --------------------
 * Der Header ist frei wählbar: wer ihn ungeprüft übernimmt, lässt jeden Angreifer
 * für jeden Versuch eine neue Herkunft erfinden. Er zählt deshalb nur, wenn
 * REMOTE_ADDR in security.trusted_proxies steht. Die Liste wird dann von rechts
 * gelesen - der letzte Eintrag stammt vom nächstgelegenen Proxy - und die erste
 * Adresse, die kein bekannter Proxy ist, ist der Besucher.
 *
 * Ohne Eintrag in trusted_proxies haben hinter einem Proxy alle Besucher dieselbe
 * Adresse - eine Sperre je IP träfe dann alle zugleich (SystemHealth weist darauf
 * hin, wenn X-Forwarded-For ankommt, trusted_proxies aber leer ist).
 *
 * @see Camindo\Core\RateLimiter
 */
namespace Camindo\Core;

class ClientIp
{
    private ?string $ip = null;

    public function __construct(private Config $config) {}

    /** IP-Adresse des Besuchers, '' auf der Kommandozeile ohne REMOTE_ADDR */
    public function ip(): string
    {
        if ($this->ip !== null) return $this->ip;
        $remote = $this->valid((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $proxies = array_values(array_filter(array_map('strval', (array)$this->config->get('security.trusted_proxies'))));
        if ($remote !== '' && $proxies && $this->matchesAny($remote, $proxies)) {
            $chain = array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                $candidate = $this->valid($chain[$i]);
                if ($candidate === '') break;
                if (!$this->matchesAny($candidate, $proxies)) return $this->ip = $candidate;
            }
        }
        return $this->ip = $remote;
    }

    /**
     * Schlüssel für Zähler je Herkunft: IPv4 wie sie ist, IPv6 auf das /64 gekürzt -
     * ein Anschluss bekommt meist ein ganzes /64 und hätte sonst beliebig viele
     * Herkünfte.
     */
    public function rateKey(): string
    {
        $ip = $this->ip();
        if (!str_contains($ip, ':')) return $ip;
        $packed = inet_pton($ip);
        if ($packed === false) return $ip;
        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** IP des Besuchers, gekürzt nach privacy.ip_v4_octets (anonymize()) */
    public function anonymized(): string
    {
        return self::anonymize($this->ip(), self::v4Octets($this->config));
    }

    /**
     * IP-Adresse kürzen (DSGVO): IPv4 verliert die letzten $v4Octets Oktette (1 oder
     * 2), IPv6 die letzten 80 Bit - es bleibt das /48 eines Anschlusses. IPv4 in
     * IPv6 (::ffff:a.b.c.d) wird wie IPv4 behandelt. Gekürzte Adressen bleiben
     * unverändert (idempotent); was keine IP ist, wird '' - etwa abgeschnittene
     * Reste -, damit nichts Ungekürztes stehen bleibt.
     */
    public static function anonymize(string $ip, int $v4Octets = 1): string
    {
        $ip = trim($ip);
        if ($ip === '') return '';
        $packed = @inet_pton($ip);
        if ($packed === false) return '';
        $v4Octets = $v4Octets >= 2 ? 2 : 1;
        if (strlen($packed) === 4) {
            return (string)inet_ntop(substr($packed, 0, 4 - $v4Octets) . str_repeat("\0", $v4Octets));
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return self::anonymize((string)inet_ntop(substr($packed, 12)), $v4Octets);
        }
        return (string)inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10));
    }

    /**
     * IP-Adresse zum Anzeigen (Datenauskunft, Audit-Log, Geräte): gekürzte Stellen
     * als "xxx" statt 0 (203.0.113.0 -> 203.0.113.xxx, 2001:db8:abcd:: ->
     * 2001:db8:abcd:xxxx:…) - sonst hielte man die Null für einen Teil der Adresse.
     * Gespeichert bleibt die gekürzte Adresse mit Nullen: nur so bleibt sie eine
     * gültige IP, die sich vergleichen und erneut kürzen lässt (anonymize() ist
     * idempotent, aus "xxx" würde '').
     */
    public static function display(string $ip, int $v4Octets = 1): string
    {
        $ip = trim($ip);
        if ($ip === '') return '';
        if (str_contains($ip, '.') && !str_contains($ip, ':')) {
            // gekürzt = endet auf 0-Oktette; markiert werden alle (höchstens zwei),
            // unabhängig von der heutigen Einstellung: Einträge aus der Zeit vor einer
            // Änderung von privacy.ip_v4_octets sind anders gekürzt (nach dem Senken
            // auf 1 tragen ältere noch zwei Nullen). Lieber eine echte 0 als xxx
            // zeigen als eine Null für einen Teil der Adresse halten lassen.
            if (self::anonymize($ip, 1) !== $ip) return $ip;   // ungekürzt
            $parts = explode('.', $ip);
            $count = self::anonymize($ip, 2) === $ip ? 2 : 1;
            for ($i = 4 - $count; $i < 4; $i++) $parts[$i] = 'xxx';
            return implode('.', $parts);
        }
        if (self::anonymize($ip, $v4Octets) !== $ip) return $ip;   // ungekürzt
        // IPv6: die ersten 48 Bit bleiben
        $packed = (string)@inet_pton($ip);
        if (strlen($packed) !== 16) return $ip;
        $groups = array_map(fn($g) => ltrim($g, '0') ?: '0', str_split(bin2hex(substr($packed, 0, 6)), 4));
        return implode(':', $groups) . str_repeat(':xxxx', 5);
    }

    /** privacy.ip_v4_octets, auf 1 oder 2 begrenzt */
    public static function v4Octets(Config $config): int
    {
        return (int)$config->get('privacy.ip_v4_octets') >= 2 ? 2 : 1;
    }

    /** Kommt X-Forwarded-For an, ohne dass der Absender als Proxy eingetragen ist? */
    public function untrustedForwarding(): bool
    {
        if (empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return false;
        $proxies = array_filter((array)$this->config->get('security.trusted_proxies'));
        return !$proxies || !$this->matchesAny((string)($_SERVER['REMOTE_ADDR'] ?? ''), $proxies);
    }

    /** Liegt $ip in einer der Angaben (Adresse oder CIDR)? */
    public static function inRange(string $ip, string $range): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) return false;
        [$net, $bits] = array_pad(explode('/', trim($range), 2), 2, null);
        $netBin = @inet_pton((string)$net);
        if ($netBin === false || strlen($netBin) !== strlen($ipBin)) return false;
        $bits = $bits === null ? strlen($ipBin) * 8 : max(0, min(strlen($ipBin) * 8, (int)$bits));
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
        $rest = $bits % 8;
        if ($rest === 0) return true;
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    private function matchesAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($ip, (string)$range)) return true;
        }
        return false;
    }

    private function valid(string $ip): string
    {
        $ip = trim($ip);
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }
}
