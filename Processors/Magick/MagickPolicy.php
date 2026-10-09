<?php
/**
 * Eigene ImageMagick-Policy des Prozessors Magick (developer_docs/18-imagemagick.md, 2.2.2).
 *
 * camindo CMS erzeugt die Datei var/config/magick/policy.xml aus der Konfiguration
 * und setzt vor jedem ImageMagick-Aufruf MAGICK_CONFIGURE_PATH darauf (apply()). Die
 * Kindprozesse erben die Variable, auch Hintergrundprozesse, und PHP setzt sie am Ende
 * des Requests zurück. Root-Rechte oder Zugriff auf /etc sind nicht nötig, das
 * funktioniert auch auf Shared Hosting (getestet mit 6.9.11-60, dem Stand bei Strato).
 *
 * VERSCHÄRFEN, NIE LOCKERN
 * --------------------
 * ImageMagick liest seine eigene Policy UND die des Systems; ein Verbot gewinnt,
 * gleich in welcher Datei es steht. Die camindo-Policy kann also nichts freigeben, was
 * der Provider gesperrt hat (typisch: PDF in der Debian-Policy) - sie legt nur
 * zusätzliche Sperren und Grenzen darüber. Deshalb ist sie immer aktiv und nicht
 * abschaltbar.
 *
 * INHALT
 * --------------------
 *   Grenzen      Speicher, Plattencache, Laufzeit, Breite/Höhe, Fläche, Einzelbilder
 *                (Dekompressionsbomben, animierte GIFs, mehrseitige Dateien)
 *   Coder        gesperrt: Skript- und Pseudoformate (MSL, MVG, TEXT, LABEL, URL,
 *                FILE, EPHEMERAL, INLINE …) und die PostScript-Familie (PS, EPS, XPS …)
 *   Delegates    alle gesperrt - ImageMagick ruft keine fremden Programme auf (gs,
 *                curl, libreoffice, inkscape, html2ps …). WebP, HEIC, AVIF, JPEG 2000
 *                sind eingebaute Bibliotheken, keine Delegates, und laufen weiter.
 *   @-Pfade      gesperrt (Dateilisten wie "@/etc/passwd")
 *
 * security.magick_allow schaltet zwei Formate frei:
 *   'svg'  SVG über den internen Renderer (Coder msvg, kein inkscape/rsvg). Der
 *          zeichnet intern mit MVG, deshalb wird MVG mit freigegeben; eingebettete
 *          Verweise wie text:, msl: oder lokale Dateien bleiben gesperrt, externe
 *          XML-Entities lädt libxml nicht.
 *   'pdf'  PDF, dafür der Delegate Ghostscript (gs). Ghostscript war wiederholt
 *          Einfallstor (-dSAFER-Umgehungen) - nur einschalten, wenn gebraucht.
 */
namespace Camindo\Core\Processors\Magick;

use Camindo\Core\Config;
use Camindo\Core\System;

class MagickPolicy
{
    /** Formate, die security.magick_allow freischalten kann */
    public const ALLOWABLE = ['svg', 'pdf'];

    /** MIME-Typ je freischaltbarem Format (Infoseite, Abgleich mit den Medientypen) */
    public const FORMAT_MIME = ['svg' => 'image/svg+xml', 'pdf' => 'application/pdf'];

    /** Coder je freischaltbarem Format - gesperrt, solange das Format nicht erlaubt ist */
    public const FORMAT_CODERS = [
        'svg' => ['SVG', 'SVGZ', 'MSVG', 'MVG'],
        'pdf' => ['PDF', 'PDFA'],
    ];

    /** Delegates je freischaltbarem Format (alle übrigen bleiben gesperrt) */
    private const FORMAT_DELEGATES = ['pdf' => ['gs']];

    /** Immer gesperrte Coder */
    private const BLOCKED_CODERS = [
        // Skript- und Pseudoformate (ImageTragick, Dateizugriff, SSRF)
        'MSL', 'TEXT', 'LABEL', 'CAPTION', 'PANGO', 'URL', 'HTTP', 'HTTPS', 'FTP', 'FILE',
        'EPHEMERAL', 'INLINE', 'SHOW', 'WIN', 'X', 'PLASMA',
        // PostScript-Familie (Ghostscript)
        'PS', 'PS2', 'PS3', 'EPS', 'EPI', 'EPSF', 'EPSI', 'EPT', 'EPDF', 'AI', 'XPS', 'PCL',
        'POCKETMOD',
    ];

    /**
     * Obergrenzen. Die Kommandozeile (-limit, Magick::limitArguments()) kann sie
     * senken, aber nicht überschreiten - auch nicht mit eigenen Parametern im
     * Expertenmodus eines Konverters.
     */
    private const RESOURCES = [
        'memory'      => '256MiB',
        'map'         => '512MiB',
        'disk'        => '1GiB',
        'time'        => '120',     // Sekunden, = Magick::TIMEOUT_CONVERT
        'width'       => '16KP',
        'height'      => '16KP',
        'area'        => '128MP',
        'list-length' => '128',     // Einzelbilder (Frames, Seiten)
    ];

    /** Pfad unterhalb von paths.var_dir */
    private const RELATIVE_DIR = 'config/magick';

    private ?bool $applied = null;

    public function __construct(
        private Config $config,
        private System $system,
    )
    {}

    /**
     * Freigeschaltete Formate aus security.magick_allow (nur bekannte, klein).
     * @return string[]
     */
    public function allowed(): array
    {
        $values = array_map(fn($v) => strtolower(trim((string)$v)), (array)($this->config->get('security.magick_allow') ?? []));
        return array_values(array_intersect(self::ALLOWABLE, $values));
    }

    public function isAllowed(string $format): bool
    {
        return in_array($format, $this->allowed(), true);
    }

    /**
     * Freischaltbares Format, zu dem ein Coder gehört ('svg', 'pdf'), sonst ''.
     * Coder, die weder dazu gehören noch immer gesperrt sind, brauchen keine Freigabe.
     */
    public static function formatOfCoder(string $coder): string
    {
        foreach (self::FORMAT_CODERS as $format => $coders) {
            if (in_array(strtoupper($coder), $coders, true)) return $format;
        }
        return '';
    }

    /** Verzeichnis der erzeugten policy.xml (Wert für MAGICK_CONFIGURE_PATH) */
    public function dir(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/\\') . '/' . self::RELATIVE_DIR;
    }

    /** Inhalt der policy.xml für die aktuelle Konfiguration */
    public function xml(): string
    {
        $allowed = $this->allowed();

        $coders = self::BLOCKED_CODERS;
        foreach (self::FORMAT_CODERS as $format => $list) {
            if (!in_array($format, $allowed, true)) $coders = array_merge($coders, $list);
        }
        $delegates = [];
        foreach (self::FORMAT_DELEGATES as $format => $list) {
            if (in_array($format, $allowed, true)) $delegates = array_merge($delegates, $list);
        }

        $lines = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<!-- camindo CMS: erzeugt von ' . self::class . ' - nicht von Hand ändern.';
        $lines[] = '     security.magick_allow: ' . ($allowed ? implode(', ', $allowed) : '-') . ' -->';
        $lines[] = '<policymap>';
        foreach (self::RESOURCES as $name => $value) {
            $lines[] = '  <policy domain="resource" name="' . $name . '" value="' . $value . '"/>';
        }
        $lines[] = '  <policy domain="path" rights="none" pattern="@*"/>';
        $lines[] = '  <policy domain="coder" rights="none" pattern="{' . implode(',', $coders) . '}"/>';
        // Reihenfolge: erst alles sperren, dann einzelne Delegates freigeben
        $lines[] = '  <policy domain="delegate" rights="none" pattern="*"/>';
        foreach ($delegates as $delegate) {
            $lines[] = '  <policy domain="delegate" rights="execute" pattern="' . $delegate . '"/>';
        }
        $lines[] = '</policymap>';
        return implode("\n", $lines) . "\n";
    }

    /**
     * policy.xml schreiben (nur wenn sich der Inhalt geändert hat) und
     * MAGICK_CONFIGURE_PATH setzen. Einmal je Request.
     *
     * @return bool  false: die Datei ließ sich nicht schreiben - dann darf ImageMagick
     *               nicht laufen (Magick::convert() bricht ab, statt ohne Policy zu arbeiten)
     */
    public function apply(): bool
    {
        if ($this->applied !== null) return $this->applied;

        $file = $this->dir() . '/policy.xml';
        $xml = $this->xml();
        if (!is_file($file) || (string)@file_get_contents($file) !== $xml) {
            $varDir = (string)$this->config->get('paths.var_dir');
            if (!$this->system->filePutContents($varDir, self::RELATIVE_DIR . '/policy.xml', $xml, lock: true)) {
                return $this->applied = false;
            }
        }
        putenv('MAGICK_CONFIGURE_PATH=' . $this->dir());
        return $this->applied = true;
    }

    /**
     * Fällt ein MIME-Typ unter die Medientypen eines Konverters (Muster wie
     * VirtualFieldsTrait::_matchMediaType(): 'image/svg+xml', 'image/*', '.svg')?
     *
     * @param string $mime        z. B. 'application/pdf'
     * @param string $extension   z. B. 'pdf'
     * @param string $incl        media_types_incl, zeilenweise
     * @param string $excl        media_types_excl, zeilenweise
     */
    public static function mediaTypesCover(string $mime, string $extension, string $incl, string $excl): bool
    {
        $match = function (string $patterns) use ($mime, $extension): bool {
            foreach (preg_split('/[\r\n]+/', strtolower($patterns)) as $pattern) {
                $pattern = trim($pattern);
                if ($pattern === '') continue;
                if ($pattern[0] === '.') { if (ltrim($pattern, '.') === $extension) return true; }
                elseif (str_ends_with($pattern, '/*')) { if (str_starts_with($mime, substr($pattern, 0, -1))) return true; }
                elseif ($pattern === $mime) return true;
            }
            return false;
        };
        return $match($incl) && !$match($excl);
    }
}
