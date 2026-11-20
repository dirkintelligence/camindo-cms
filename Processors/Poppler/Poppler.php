<?php
/**
 * Processor Poppler: an image of one PDF page with pdftoppm (Poppler).
 *
 * WHY NOT IMAGEMAGICK
 * --------------------
 * ImageMagick reads PDF through Ghostscript, a full PostScript interpreter whose
 * sandbox (-dSAFER) has been bypassed repeatedly. pdftoppm only renders PDF, it runs
 * neither PostScript nor JavaScript - much less to attack (developer docs, chapter 14
 * (Security: web)).
 *
 * WHAT THE PROCESSOR DOES
 * --------------------
 *     pdfinfo -f N -l N -box FILE                  page count, size and rotation of page N
 *     pdftoppm -f N -l N -singlefile -cropbox -scale-to S -png FILE OUT-PREFIX
 *
 * - Only files that begin with "%PDF-" are passed on; anything else is 'unsupported'.
 * - Fixed arguments, no expert mode: there is nothing an administrator could add.
 * - Exactly one page, at most MAX_SIDE pixels per side, time limit TIMEOUT_CONVERT.
 *   Memory cannot be limited from PHP; whoever operates the server can enter a wrapper
 *   script under paths.pdftoppm (manual "Operation").
 * - Target formats: PNG, JPEG, TIFF. WebP & co. come from a further thumbnail field
 *   (GD or ImageMagick) with this thumbnail as its source.
 *
 * pdfinfo (same directory as pdftoppm) is optional: without it, the page is fitted into
 * the target size by its longer side only, and a page number beyond the end fails.
 */
namespace Camindo\Core\Processors\Poppler;

use Camindo\Core\MediaProcessor;

class Poppler extends MediaProcessor {

    protected const TIMEOUT_CONVERT = 60;
    protected const TIMEOUT_INFO    = 15;
    protected const TIMEOUT_VERSION = 10;

    /** Largest side of the result in pixels */
    public const MAX_SIDE = 10000;

    /** Target extension -> [output option of pdftoppm, extension pdftoppm appends, MIME type] */
    protected const FORMATS = [
        'png'  => ['-png',  'png', 'image/png'],
        'jpg'  => ['-jpeg', 'jpg', 'image/jpeg'],
        'jpeg' => ['-jpeg', 'jpg', 'image/jpeg'],
        'tif'  => ['-tiff', 'tif', 'image/tiff'],
        'tiff' => ['-tiff', 'tif', 'image/tiff'],
    ];

    // Backend
    // ====================

    protected function hookDefinition(): void
    {
        $this->defaults['media_types_incl'] = 'application/pdf';

        $this->schema = [
            'details.page' => [
                'type'  => 'integer',
                'label' => '#proc.poppler.field_page',
                'tooltip' => '#proc.poppler.tooltip_field_page',
                'default' => '1',
                'min' => 1,
            ],
            'details.width' => [
                'type'  => 'integer',
                'label' => '#proc.poppler.field_width',
                'default' => '300',
                'min' => 0,
                'max' => self::MAX_SIDE,
                'suffix' => 'px'
            ],
            'details.height' => [
                'type'  => 'integer',
                'label' => '#proc.poppler.field_height',
                'tooltip' => '#proc.poppler.tooltip_field_height',
                'default' => '',
                'min' => 0,
                'max' => self::MAX_SIDE,
                'suffix' => 'px'
            ],
            'details.box' => [
                'type'  => 'chars',
                'label' => '#proc.poppler.field_box',
                'tooltip' => '#proc.poppler.tooltip_field_box',
                'display_type' => 'select',
                'options' => [
                    'crop'  => '#proc.poppler.option_box_crop',
                    'media' => '#proc.poppler.option_box_media',
                ],
                'default' => 'crop',
                'max' => 10
            ],
            'details.annotations' => [
                'type'  => 'bit',
                'label' => '#proc.poppler.field_annotations',
                'tooltip' => '#proc.poppler.tooltip_field_annotations',
                'display_type' => 'switch',
                'default' => 1
            ],
            'details.quality' => [
                'type'  => 'integer',
                'label' => '#proc.poppler.field_quality',
                'tooltip' => '#proc.poppler.tooltip_field_quality',
                'default' => '85',
                'suffix' => '%',
                'min' => 1,
                'max' => 100
            ],
        ];
    }

    public function hookOnCreate(array &$data): void
    {
        if (($data['extension'] ?? '') === '') $data['extension'] = 'png';
    }

    public function hookOnLoaded(array &$data): void
    {
    }

    public function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        $details = (array)($data['details'] ?? []);

        // target format: only what pdftoppm writes
        $extension = strtolower(ltrim((string)($data['extension'] ?? $previousData['extension'] ?? ''), '.'));
        if (!isset(self::FORMATS[$extension])) {
            throw new \Exception($this->cms->snippet('proc.poppler.error_extension'));
        }
        if (!in_array((string)($details['box'] ?? 'crop'), ['crop', 'media'], true)) {
            throw new \Exception(strtr($this->cms->snippet('proc.poppler.error_option'), [
                '{field}' => $this->cms->textOrSnippet($this->schema['details.box']['label']),
                '{values}' => 'crop, media',
            ]));
        }
        if ((int)($details['width'] ?? 0) < 1 && (int)($details['height'] ?? 0) < 1) {
            throw new \Exception($this->cms->snippet('proc.poppler.error_size'));
        }
        // without the program the converter would be ineffective from the start
        if ($this->getVersion() === null) {
            throw new \Exception($this->cms->snippet('proc.poppler.error_binary_missing'));
        }
    }

    public function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
    }

    public function hookOnDelete(array $data): void
    {
    }

    // Engine
    // ====================

    /** Only PDF, and only into the formats pdftoppm writes */
    public function supports(string $mime, string $srcExtension, string $destExtension): bool
    {
        $isPdf = strtolower($mime) === 'application/pdf' || strtolower($srcExtension) === 'pdf';
        return $isPdf && isset(self::FORMATS[strtolower($destExtension)]);
    }

    /**
     * Image of one page of a PDF (see class comment). Always in the foreground.
     *
     * @see MediaProcessor::convert()
     */
    public function convert(string $srcPath, array $srcData, string $destPath, array $details, bool $forceBgproc): array
    {
        $mediaDir = (string)$this->config->get('paths.media_dir');
        $srcFile = $mediaDir . DIRECTORY_SEPARATOR . ltrim($srcPath, '/\\');
        $destFile = $mediaDir . DIRECTORY_SEPARATOR . ltrim($destPath, '/\\');
        $ext = strtolower((string)(($details['extension'] ?? '') ?: pathinfo($destPath, PATHINFO_EXTENSION)));
        $fileData = ['width' => 0, 'height' => 0, 'mime_type' => '', 'size' => 0, 'filename' => '', 'extension' => ''];

        if (!isset(self::FORMATS[$ext])) {
            return $fileData + ['errors' => 'proc.poppler.error_extension', 'unsupported' => true];
        }
        $pdftoppm = $this->binary();
        if ($pdftoppm === null) {
            return $fileData + ['errors' => 'proc.poppler.error_binary_missing'];
        }
        if (!is_file($srcFile) || !is_readable($srcFile)) {
            return $fileData + ['errors' => 'error_file_not_found'];
        }
        // only real PDF files: the signature must stand in the first kilobyte
        if (!self::isPdf($srcFile)) {
            return $fileData + ['errors' => 'proc.poppler.error_not_pdf', 'unsupported' => true];
        }

        // page and target size
        $page = max(1, (int)($details['page'] ?? 1));
        $cropBox = ($details['box'] ?? 'crop') !== 'media';
        $info = $this->pageInfo($srcFile, $page, $cropBox);
        if ($info !== null) $page = min($page, $info['pages']);
        $scaling = self::targetSize(
            (int)($details['width'] ?? 0), (int)($details['height'] ?? 0),
            $info === null ? 0.0 : $info['width'], $info === null ? 0.0 : $info['height']
        );
        if ($scaling === null) {
            return $fileData + ['errors' => 'proc.poppler.error_size'];
        }

        // pdftoppm appends the extension to the prefix itself
        [$formatOption, $writtenExt, $mime] = self::FORMATS[$ext];
        $prefix = dirname($destFile) . DIRECTORY_SEPARATOR . '.poppler-' . $this->crypt->randomKey(8);
        $written = $prefix . '.' . $writtenExt;

        $command = [$pdftoppm, '-q', '-f', (string)$page, '-l', (string)$page, '-singlefile', ...$scaling];
        if ($cropBox) $command[] = '-cropbox';
        if (empty($details['annotations']) && array_key_exists('annotations', $details)) $command[] = '-hide-annotations';
        $command[] = $formatOption;
        if ($formatOption === '-jpeg') {
            $quality = max(1, min(100, (int)($details['quality'] ?? 85) ?: 85));
            array_push($command, '-jpegopt', 'quality=' . $quality);
        }
        if ($formatOption === '-tiff') array_push($command, '-tiffcompression', 'lzw');
        array_push($command, $srcFile, $prefix);

        $exitCode = $this->execute->execute($command, $stdout, $stderr, self::TIMEOUT_CONVERT);
        clearstatcache(true, $written);
        if ($exitCode !== 0 || !is_file($written) || filesize($written) === 0) {
            if (is_file($written)) @unlink($written);
            return $fileData + [
                'errors' => $exitCode === $this->execute::EXEC_TIMEOUT ? 'proc.poppler.error_timeout' : 'error_file_conversion',
                'error_details' => trim((string)$stderr),
            ];
        }
        if (!@rename($written, $destFile)) {
            @unlink($written);
            return $fileData + ['errors' => 'error_file_conversion', 'error_details' => 'rename'];
        }
        clearstatcache(true, $destFile);
        $dimensions = @getimagesize($destFile) ?: [0, 0];

        return [
            'width'     => (int)$dimensions[0],
            'height'    => (int)$dimensions[1],
            'mime_type' => $mime,
            'size'      => (int)filesize($destFile),
            'filename'  => $srcData['filename'] ?? 'file',
            'extension' => $ext,
            // the page in points (1/72 inch), as far as pdfinfo knows it
            'source_width'  => $info === null ? 0 : (int)round($info['width']),
            'source_height' => $info === null ? 0 : (int)round($info['height']),
        ];
    }

    /**
     * Scaling option of pdftoppm: fitted into width × height (one of them may be 0), with
     * the aspect ratio of the page, never larger than requested; at most MAX_SIDE per side.
     *
     * Always -scale-to (longer side of the page): it respects the page rotation.
     * -scale-to-x / -scale-to-y do not in every Poppler version - 22.12 applies them
     * before the rotation, a turned page comes out swapped.
     *
     * Without page size (no pdfinfo) the longer side gets the smallest given value: the
     * result fits, but may stay smaller than possible.
     *
     * @return array|null arguments ['-scale-to', 'N']; null without any size
     */
    public static function targetSize(int $reqW, int $reqH, float $pageW, float $pageH): ?array
    {
        $reqW = max(0, min(self::MAX_SIDE, $reqW));
        $reqH = max(0, min(self::MAX_SIDE, $reqH));
        if ($reqW < 1 && $reqH < 1) return null;
        if ($pageW <= 0 || $pageH <= 0) {
            return ['-scale-to', (string)min(array_filter([$reqW, $reqH]))];
        }
        $scales = [];
        if ($reqW > 0) $scales[] = $reqW / $pageW;
        if ($reqH > 0) $scales[] = $reqH / $pageH;
        $scale = min($scales);
        $long = max($pageW, $pageH);
        $short = min($pageW, $pageH);
        $side = max(1, min(self::MAX_SIDE, (int)round($long * $scale)));
        // pdftoppm rounds the shorter side up - one pixel less on the longer side if it
        // would otherwise exceed the requested size
        $capW = $reqW > 0 ? $reqW : PHP_INT_MAX;
        $capH = $reqH > 0 ? $reqH : PHP_INT_MAX;
        while ($side > 1) {
            $shortPx = (int)ceil($short * $side / $long - 1e-9);
            [$w, $h] = $pageW >= $pageH ? [$side, $shortPx] : [$shortPx, $side];
            if ($w <= $capW && $h <= $capH) break;
            $side--;
        }
        return ['-scale-to', (string)$side];
    }

    /** Does the file begin (within the first kilobyte, as readers allow) with "%PDF-"? */
    public static function isPdf(string $file): bool
    {
        $head = (string)@file_get_contents($file, false, null, 0, 1024);
        return str_contains($head, '%PDF-');
    }

    /**
     * Page count and size of one page (points, after its rotation) with pdfinfo. A page
     * beyond the end counts as the last one.
     *
     * @param bool $cropBox  size of the crop box (else media box)
     * @return array|null    ['pages' => int, 'width' => float, 'height' => float], null
     *                       without pdfinfo or if it cannot read the file
     */
    public function pageInfo(string $file, int $page = 1, bool $cropBox = true): ?array
    {
        $pdfinfo = $this->infoBinary();
        if ($pdfinfo === null) return null;
        // page count first: a page beyond the end makes pdfinfo fail - then the last one
        if ($this->execute->execute([$pdfinfo, $file], $stdout, $stderr, self::TIMEOUT_INFO) !== 0) return null;
        if (!preg_match('/^Pages:\s+(\d+)/m', (string)$stdout, $pages) || (int)$pages[1] < 1) return null;
        $page = min(max(1, $page), (int)$pages[1]);
        $exitCode = $this->execute->execute([$pdfinfo, '-f', (string)$page, '-l', (string)$page, '-box', $file],
            $stdout, $stderr, self::TIMEOUT_INFO);
        if ($exitCode !== 0) return null;
        $stdout = (string)$stdout;
        $box = $cropBox ? 'CropBox' : 'MediaBox';
        if (!preg_match('/^Page\s+\d+\s+' . $box . ':\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)/m', $stdout, $m)) {
            return null;
        }
        $width = abs((float)$m[3] - (float)$m[1]);
        $height = abs((float)$m[4] - (float)$m[2]);
        if (preg_match('/^Page\s+\d+\s+rot:\s+(-?\d+)/m', $stdout, $rot) && in_array(((int)$rot[1] % 360 + 360) % 360, [90, 270], true)) {
            [$width, $height] = [$height, $width];
        }
        return ['pages' => (int)$pages[1], 'width' => $width, 'height' => $height];
    }

    /** pdftoppm under paths.pdftoppm, if executable */
    public function binary(): ?string
    {
        $path = (string)($this->config->get('paths.pdftoppm') ?? '');
        return $path !== '' && is_executable($path) ? $path : null;
    }

    /** pdfinfo next to pdftoppm (both belong to poppler-utils), if executable */
    public function infoBinary(): ?string
    {
        $pdftoppm = $this->binary();
        if ($pdftoppm === null) return null;
        $path = dirname($pdftoppm) . DIRECTORY_SEPARATOR . 'pdfinfo';
        return is_executable($path) ? $path : null;
    }

    /**
     * Version of pdftoppm, e.g. "22.12.0"; null if it does not run.
     * (pdftoppm -v writes to stderr.)
     */
    public function getVersion(): ?string
    {
        $pdftoppm = $this->binary();
        if ($pdftoppm === null) return null;
        $this->execute->execute([$pdftoppm, '-v'], $stdout, $stderr, self::TIMEOUT_VERSION);
        if (!preg_match('/pdftoppm version\s+([0-9][0-9.]*)/i', $stdout . "\n" . $stderr, $match)) return null;
        return $match[1];
    }
}
