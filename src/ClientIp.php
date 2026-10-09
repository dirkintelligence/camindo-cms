<?php
/**
 * Origin of a request (IP address of the visitor) - for throttling
 * (RateLimiter), brute-force protection (LoginGuard) and login devices.
 *
 * X-FORWARDED-FOR ONLY FROM KNOWN PROXIES
 * --------------------
 * The header can be set freely: whoever accepts it unchecked lets every attacker
 * invent a new origin for every attempt. It therefore only counts if
 * REMOTE_ADDR is listed in security.trusted_proxies. The list is then read from the
 * right - the last entry comes from the nearest proxy - and the first
 * address that is not a known proxy is the visitor.
 *
 * Without an entry in trusted_proxies, all visitors behind a proxy share the same
 * address - a block per IP would then hit everyone at once (SystemHealth warns
 * when X-Forwarded-For arrives but trusted_proxies is empty).
 *
 * @see Camindo\Core\RateLimiter
 */
namespace Camindo\Core;

class ClientIp
{
    private ?string $ip = null;

    public function __construct(private Config $config) {}

    /** IP address of the visitor, '' on the command line without REMOTE_ADDR */
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
     * Key for counters per origin: IPv4 as it is, IPv6 truncated to the /64 -
     * a connection usually gets a whole /64 and would otherwise have an arbitrary
     * number of origins.
     */
    public function rateKey(): string
    {
        $ip = $this->ip();
        if (!str_contains($ip, ':')) return $ip;
        $packed = inet_pton($ip);
        if ($packed === false) return $ip;
        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** IP of the visitor, truncated according to privacy.ip_v4_octets (anonymize()) */
    public function anonymized(): string
    {
        return self::anonymize($this->ip(), self::v4Octets($this->config));
    }

    /**
     * Truncate an IP address (GDPR): IPv4 loses the last $v4Octets octets (1 or
     * 2), IPv6 the last 80 bits - the /48 of a connection remains. IPv4 in
     * IPv6 (::ffff:a.b.c.d) is treated like IPv4. Truncated addresses stay
     * unchanged (idempotent); anything that is not an IP becomes '' - such as cut-off
     * remnants - so that nothing untruncated remains.
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
     * IP address for display (data access report, audit log, devices): truncated
     * digits shown as "xxx" instead of 0 (203.0.113.0 -> 203.0.113.xxx, 2001:db8:abcd:: ->
     * 2001:db8:abcd:xxxx:…) - otherwise the zero would be taken as part of the address.
     * The stored value keeps the truncated address with zeros: only this way it stays a
     * valid IP that can be compared and truncated again (anonymize() is
     * idempotent, "xxx" would become '').
     */
    public static function display(string $ip, int $v4Octets = 1): string
    {
        $ip = trim($ip);
        if ($ip === '') return '';
        if (str_contains($ip, '.') && !str_contains($ip, ':')) {
            // truncated = ends in 0-octets; all of them (at most two) are marked,
            // regardless of the current setting: entries from the time before a change
            // of privacy.ip_v4_octets are truncated differently (after lowering it
            // to 1, older ones still carry two zeros). Better to show a real 0 than
            // let xxx make it look like a zero is part of the address.
            if (self::anonymize($ip, 1) !== $ip) return $ip;   // not truncated
            $parts = explode('.', $ip);
            $count = self::anonymize($ip, 2) === $ip ? 2 : 1;
            for ($i = 4 - $count; $i < 4; $i++) $parts[$i] = 'xxx';
            return implode('.', $parts);
        }
        if (self::anonymize($ip, $v4Octets) !== $ip) return $ip;   // not truncated
        // IPv6: the first 48 bits remain
        $packed = (string)@inet_pton($ip);
        if (strlen($packed) !== 16) return $ip;
        $groups = array_map(fn($g) => ltrim($g, '0') ?: '0', str_split(bin2hex(substr($packed, 0, 6)), 4));
        return implode(':', $groups) . str_repeat(':xxxx', 5);
    }

    /** privacy.ip_v4_octets, limited to 1 or 2 */
    public static function v4Octets(Config $config): int
    {
        return (int)$config->get('privacy.ip_v4_octets') >= 2 ? 2 : 1;
    }

    /** Does X-Forwarded-For arrive without the sender being listed as a proxy? */
    public function untrustedForwarding(): bool
    {
        if (empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return false;
        $proxies = array_filter((array)$this->config->get('security.trusted_proxies'));
        return !$proxies || !$this->matchesAny((string)($_SERVER['REMOTE_ADDR'] ?? ''), $proxies);
    }

    /** Is $ip within one of the entries (address or CIDR)? */
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
