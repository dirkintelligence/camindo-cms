<?php
/**
 * Versionsbedingungen nach Composer-Art, ohne composer/semver.
 *
 *     ^9.1        >= 9.1.0  < 10.0.0     (^0.3 -> >= 0.3.0 < 0.4.0)
 *     ~9.1        >= 9.1.0  < 10.0.0     ~9.1.2 -> >= 9.1.2 < 9.2.0
 *     9.1.*       >= 9.1.0  < 9.2.0      9.* -> >= 9.0.0 < 10.0.0
 *     >=9.1 <10   beide müssen gelten (AND: Leerzeichen oder Komma)
 *     ^8.2 || ^9.0   eine muss gelten (OR)
 *     *           alles
 *     9.1.0       genau diese Version (=, != ebenfalls)
 *
 * Verglichen wird mit version_compare(); Vorabversionen (9.1.0-alpha) gelten als
 * kleiner als die Freigabe, so wie dort. Eine unverständliche Bedingung ist
 * unerfüllt - lieber eine Komponente zu viel als "fehlerhaft" melden als eine
 * Bedingung stillschweigend ignorieren.
 *
 * Für settings.php 'requires' (core, components), siehe
 * docs/developers/14-installation-und-update.md, 5.3.
 */
namespace Camindo\Core\Install;

class VersionConstraint {

    /**
     * Erfüllt $version die Bedingung $constraint?
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        $version = self::normalize($version);
        if ($version === null) return false;

        foreach (preg_split('/\s*\|\|\s*/', trim($constraint)) ?: [] as $alternative) {
            $parts = preg_split('/\s*,\s*|\s+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($parts === []) return false;
            $ok = true;
            foreach ($parts as $part) {
                if (!self::single($version, $part)) { $ok = false; break; }
            }
            if ($ok) return true;
        }
        return false;
    }

    /**
     * Eine einzelne Bedingung ohne || und ,.
     */
    private static function single(string $version, string $part): bool
    {
        if ($part === '*') return true;

        // Operator
        if (preg_match('/^(>=|<=|!=|>|<|=)\s*(.+)$/', $part, $m)) {
            $bound = self::normalize($m[2]);
            if ($bound === null) return false;
            return match ($m[1]) {
                '>=' => version_compare($version, $bound, '>='),
                '<=' => version_compare($version, $bound, '<='),
                '>'  => version_compare($version, $bound, '>'),
                '<'  => version_compare($version, $bound, '<'),
                '!=' => version_compare($version, $bound, '!='),
                default => version_compare($version, $bound, '=='),
            };
        }

        // Caret: ^x.y.z
        if (preg_match('/^\^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $part, $m)) {
            [$x, $y, $z] = [(int)$m[1], (int)($m[2] ?? 0), (int)($m[3] ?? 0)];
            $low = "$x.$y.$z";
            $high = $x > 0 ? ($x + 1) . '.0.0' : ($y > 0 || !isset($m[3]) ? "0." . ($y + 1) . ".0" : "0.0." . ($z + 1));
            return version_compare($version, $low, '>=') && version_compare($version, $high, '<');
        }

        // Tilde: ~x.y[.z]
        if (preg_match('/^~v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $part, $m)) {
            $x = (int)$m[1];
            if (isset($m[3])) {
                $y = (int)$m[2];
                $low = "$x.$y." . (int)$m[3];
                $high = "$x." . ($y + 1) . '.0';
            }
            elseif (isset($m[2])) {
                $low = "$x." . (int)$m[2] . '.0';
                $high = ($x + 1) . '.0.0';
            }
            else {
                $low = "$x.0.0";
                $high = ($x + 1) . '.0.0';
            }
            return version_compare($version, $low, '>=') && version_compare($version, $high, '<');
        }

        // Wildcard: x.* / x.y.*
        if (preg_match('/^v?(\d+)(?:\.(\d+))?\.\*$/', $part, $m)) {
            $x = (int)$m[1];
            if (isset($m[2])) {
                $y = (int)$m[2];
                $low = "$x.$y.0";
                $high = "$x." . ($y + 1) . '.0';
            }
            else {
                $low = "$x.0.0";
                $high = ($x + 1) . '.0.0';
            }
            return version_compare($version, $low, '>=') && version_compare($version, $high, '<');
        }

        // Genau
        $bound = self::normalize($part);
        return $bound !== null && version_compare($version, $bound, '==');
    }

    /**
     * 'v9.1' -> '9.1.0', '9.1.0-beta2' bleibt; null, wenn keine Version.
     */
    public static function normalize(string $version): ?string
    {
        if (!preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(-[0-9A-Za-z.]+)?$/', trim($version), $m)) return null;
        return (int)$m[1] . '.' . (int)($m[2] ?? 0) . '.' . (int)($m[3] ?? 0) . ($m[4] ?? '');
    }
}
