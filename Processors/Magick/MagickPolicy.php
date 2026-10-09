<?php
/**
 * Own ImageMagick policy of the processor Magick (developer docs, chapter 14 (Security: web)).
 *
 * camindo CMS generates the file var/config/magick/policy.xml from the configuration
 * and sets MAGICK_CONFIGURE_PATH to it before each ImageMagick call (apply()). The
 * child processes inherit the variable, including background processes, and PHP resets it at the end
 * of the request. Root rights or access to /etc are not needed, this
 * also works on shared hosting (tested with 6.9.11-60, the version at Strato).
 *
 * TIGHTEN, NEVER LOOSEN
 * --------------------
 * ImageMagick reads its own policy AND the system's; a prohibition wins,
 * regardless of the file it is in. The camindo policy therefore cannot release anything that
 * the provider has blocked (typically: PDF in the Debian policy) - it only adds
 * additional blocks and limits on top. Therefore it is always active and not
 * abschaltbar.
 *
 * INHALT
 * --------------------
 *   Limits       memory, disk cache, runtime, width/height, area, single images
 *                (decompression bombs, animated GIFs, multi-page files)
 *   Coder        blocked: script and pseudo formats (MSL, MVG, TEXT, LABEL, URL,
 *                FILE, EPHEMERAL, INLINE …) and the PostScript family (PS, EPS, XPS …)
 *   Delegates    all blocked - ImageMagick calls no foreign programs (gs,
 *                curl, libreoffice, inkscape, html2ps …). WebP, HEIC, AVIF, JPEG 2000
 *                are built-in libraries, not delegates, and continue to run.
 *   @-paths      blocked (file lists such as "@/etc/passwd")
 *
 * security.magick_allow schaltet zwei Formate frei:
 *   'svg'  SVG via the internal renderer (coder msvg, no inkscape/rsvg). It
 *          draws internally with MVG, therefore MVG is released as well; embedded
 *          references such as text:, msl: or local files remain blocked, external
 *          libxml does not load XML entities.
 *   'pdf'  PDF, via the delegate Ghostscript (gs). Ghostscript has repeatedly been
 *          an entry point (-dSAFER bypasses) - only enable if needed.
 */
namespace Camindo\Core\Processors\Magick;

use Camindo\Core\Config;
use Camindo\Core\System;

class MagickPolicy
{
    /** Formats that security.magick_allow can release */
    public const ALLOWABLE = ['svg', 'pdf'];

    /** MIME type per releasable format (info page, comparison with the media types) */
    public const FORMAT_MIME = ['svg' => 'image/svg+xml', 'pdf' => 'application/pdf'];

    /** Coder per releasable format - blocked as long as the format is not allowed */
    public const FORMAT_CODERS = [
        'svg' => ['SVG', 'SVGZ', 'MSVG', 'MVG'],
        'pdf' => ['PDF', 'PDFA'],
    ];

    /** Delegates per releasable format (all others remain blocked) */
    private const FORMAT_DELEGATES = ['pdf' => ['gs']];

    /** Immer gesperrte Coder */
    private const BLOCKED_CODERS = [
        // Script and pseudo formats (ImageTragick, file access, SSRF)
        'MSL', 'TEXT', 'LABEL', 'CAPTION', 'PANGO', 'URL', 'HTTP', 'HTTPS', 'FTP', 'FILE',
        'EPHEMERAL', 'INLINE', 'SHOW', 'WIN', 'X', 'PLASMA',
        // PostScript-Familie (Ghostscript)
        'PS', 'PS2', 'PS3', 'EPS', 'EPI', 'EPSF', 'EPSI', 'EPT', 'EPDF', 'AI', 'XPS', 'PCL',
        'POCKETMOD',
    ];

    /**
     * Upper limits. The command line (-limit, Magick::limitArguments()) can
     * lower, but not exceed - not even with own parameters in
     * override them in expert mode of a converter.
     */
    private const RESOURCES = [
        'memory'      => '256MiB',
        'map'         => '512MiB',
        'disk'        => '1GiB',
        'time'        => '120',     // seconds, = Magick::TIMEOUT_CONVERT
        'width'       => '16KP',
        'height'      => '16KP',
        'area'        => '128MP',
        'list-length' => '128',     // single images (frames, pages)
    ];

    /** Path below paths.var_dir */
    private const RELATIVE_DIR = 'config/magick';

    private ?bool $applied = null;

    public function __construct(
        private Config $config,
        private System $system,
    )
    {}

    /**
     * Released formats from security.magick_allow (only known, small).
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
     * Releasable format to which a coder belongs ('svg', 'pdf'), otherwise ''.
     * Coders that belong to neither and are never blocked need no release.
     */
    public static function formatOfCoder(string $coder): string
    {
        foreach (self::FORMAT_CODERS as $format => $coders) {
            if (in_array(strtoupper($coder), $coders, true)) return $format;
        }
        return '';
    }

    /** Directory of the generated policy.xml (value for MAGICK_CONFIGURE_PATH) */
    public function dir(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/\\') . '/' . self::RELATIVE_DIR;
    }

    /** Content of the policy.xml for the current configuration */
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
        // Order: first block everything, then release individual delegates
        $lines[] = '  <policy domain="delegate" rights="none" pattern="*"/>';
        foreach ($delegates as $delegate) {
            $lines[] = '  <policy domain="delegate" rights="execute" pattern="' . $delegate . '"/>';
        }
        $lines[] = '</policymap>';
        return implode("\n", $lines) . "\n";
    }

    /**
     * Write policy.xml (only if the content has changed) and
     * Set MAGICK_CONFIGURE_PATH. Once per request.
     *
     * @return bool  false: the file could not be written - then ImageMagick must not
     *               run (Magick::convert() aborts instead of working without a policy)
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
     * Does a MIME type fall under the media types of a converter (patterns like
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
