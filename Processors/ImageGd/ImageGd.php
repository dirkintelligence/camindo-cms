<?php
namespace Camindo\Core\Processors\ImageGd;

use Camindo\Core\MediaProcessor;

class ImageGd extends MediaProcessor {

    // Backend
    // ====================

    /**
     * Table structure for the converter
     * An input field can be created from it with $this->backend->formField()
     * 
     * type:
     *     integer -> BIGINT
     *     chars -> VARCHAR(255)
     *     bit -> BIT(1)
     * display_type:
     *     text:   einfaches Eingabefeld
     *     select: selection, options are best generated dynamically
     *     label:  mehrsprachiges Snippet #proc.{processorname}.{snippetname} 
     *     prefix,
     *     suffix: appears before / after the input field
     *     pattern: JavaScript pattern for input validation
     *     min, max: minimum / maximum value, for text the number of characters
     */
    protected function hookDefinition(): void
    {
        // Default values for input fields of the converter
        $this->defaults['media_types_incl'] = implode("\n", [
            "image/jpeg",
            "image/png",
            "image/gif",
            "image/webp",
            "image/bmp"
        ]);

        // Additional input fields for the converter
        $this->schema = [
            'details.width' => [
                'type'  => 'integer',
                'label' => '#proc.imagegd.field_width',
                'default' => '300',
                'min' => 0,
                'suffix' => 'px'
            ],
            'details.height' => [
                'type'  => 'integer',
                'label' => '#proc.imagegd.field_height',
                'default' => '',
                'min' => 0,
                'suffix' => 'px'
            ],
            'details.crop' => [
                'type'  => 'integer',
                'label' => '#proc.imagegd.field_crop',
                'tooltip' => '#proc.imagegd.tooltip_field_crop',
                'display_type' => 'switch',
                'default' => 0
            ],
            // Size: condition, unit, maximum pixels - as with Magick
            // (there the flags > < % @ of the geometry), here in targetGeometry()
            'details.resize_when' => [
                'type'  => 'chars',
                'label' => '#proc.imagegd.field_resize_when',
                'tooltip' => '#proc.imagegd.tooltip_field_resize_when',
                'display_type' => 'select',
                'options' => [
                    'always'  => '#proc.imagegd.option_resize_always',
                    'shrink'  => '#proc.imagegd.option_resize_shrink',
                    'enlarge' => '#proc.imagegd.option_resize_enlarge',
                ],
                'default' => 'always',
                'max' => 10
            ],
            'details.unit' => [
                'type'  => 'chars',
                'label' => '#proc.imagegd.field_unit',
                'tooltip' => '#proc.imagegd.tooltip_field_unit',
                'display_type' => 'select',
                'options' => [
                    'px'      => '#proc.imagegd.option_unit_px',
                    'percent' => '#proc.imagegd.option_unit_percent',
                ],
                'default' => 'px',
                'max' => 10
            ],
            'details.max_pixels' => [
                'type'  => 'integer',
                'label' => '#proc.imagegd.field_max_pixels',
                'tooltip' => '#proc.imagegd.tooltip_field_max_pixels',
                'note' => '#proc.imagegd.note_max_pixels',
                'min' => 0
            ],
            'details.quality' => [
                'type'  => 'integer',
                'label' => '#proc.imagegd.field_quality',
                'default' => '80',
                'suffix' => '%',
                'note' => '#proc.imagegd.note_quality',
                'tooltip' => '#proc.imagegd.tooltip_field_quality',
                'min' => 0,
            ],
            'details.indexed_colors' => [
                'type'  => 'integer',
                'label' => '#proc.imagegd.field_indexed_colors',
                'default' => 'TrueColor',
                'note' => '#proc.imagegd.note_indexed_colors',
                'tooltip' => '#proc.imagegd.tooltip_field_indexed_colors',
                'min' => 0,
                'max' => 256
            ],
            'details.alpha' => [
                'type'  => 'bit',
                'label' => '#proc.imagegd.field_alpha',
                'tooltip' => '#proc.imagegd.tooltip_field_alpha',
                'display_type' => 'switch',
            ],
            'details.bgcolor' => [
                'type'  => 'chars',
                'label' => '#proc.imagegd.field_bgcolor',
                'note' => '#proc.imagegd.note_field_bgcolor',
                'tooltip' => '#proc.imagegd.tooltip_field_bgcolor',
                'default' => '#ffffff',
                'pattern' => '#?([0-9a-fA-F]{3}|[0-9a-zA-Z]{6})',
                'max' => 7
            ],
        ];
    }

    /**
     * before creating converter (defaults)
     */
    public function hookOnCreate(array &$data): void
    {
    }

    /**
     * after loading converter
     */
    public function hookOnLoaded(array &$data): void
    {
        if (!empty($data['details']['bgcolor'])) $data['details']['bgcolor'] = '#' . $data['details']['bgcolor'];
    }

    /**
     * before saving converter (check data)
     */
    public function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        // Selection fields: only the values from the schema (also via the API)
        foreach (['resize_when', 'unit'] as $key) {
            if (!isset($data['details'][$key]) || $data['details'][$key] === '') continue;
            $allowed = array_map('strval', array_keys($this->schema['details.' . $key]['options']));
            if (!in_array((string)$data['details'][$key], $allowed, true)) {
                throw new \Exception(strtr($this->cms->snippet('proc.imagegd.error_option'), [
                    '{field}' => $this->cms->textOrSnippet($this->schema['details.' . $key]['label']),
                    '{values}' => implode(', ', $allowed),
                ]));
            }
        }

        // bgcolor, stored without '#'
        $data['details']['bgcolor'] = ltrim($data['details']['bgcolor'] ?? '', '#');
        $indexedColors = $data['details']['indexed_colors'] ?? '';
        if ($indexedColors === 'TrueColor') $data['details']['indexed_colors'] = '';
    }

    /**
     * after saving converter
     */
    public function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
    }

    /**
     * before deleting converter
     */
    public function hookOnDelete(array $data): void
    {
    }

    // Engine
    // ====================

    /**
     * Image formats that GD can read/write - depending on the GD build (imagetypes()).
     * Key: extension, value: [IMG_*-bit, MIME type]. WebP and AVIF are missing in
     * some builds entirely (then imagecreatefromwebp() & co. do not exist either).
     */
    protected const FORMATS = [
        'jpg'  => [IMG_JPG,  'image/jpeg'],
        'jpeg' => [IMG_JPG,  'image/jpeg'],
        'jpe'  => [IMG_JPG,  'image/jpeg'],
        'png'  => [IMG_PNG,  'image/png'],
        'gif'  => [IMG_GIF,  'image/gif'],
        'webp' => [IMG_WEBP, 'image/webp'],
        'bmp'  => [IMG_BMP,  'image/bmp'],
        'dib'  => [IMG_BMP,  'image/bmp'],
        'avif' => [IMG_AVIF, 'image/avif'],
    ];

    /**
     * Is the format included in this GD build?
     * @param string $extension  extension without dot (case does not matter)
     */
    protected function formatAvailable(string $extension): bool
    {
        $format = static::FORMATS[strtolower($extension)] ?? null;
        return $format !== null && (imagetypes() & $format[0]) !== 0;
    }

    /**
     * Source AND target format must belong to the formats of this GD build,
     * otherwise writeThumbnails() should take the next converter.
     * The source is checked via the MIME type (determined server-side, more reliable
     * than the extension), alternatively via the extension.
     */
    public function supports(string $mime, string $srcExtension, string $destExtension): bool
    {
        $srcExt = '';
        foreach (static::FORMATS as $extension => [, $formatMime]) {
            if ($formatMime === strtolower($mime)) { $srcExt = $extension; break; }
        }
        if ($srcExt === '') $srcExt = $srcExtension;
        return $this->formatAvailable($srcExt) && $this->formatAvailable($destExtension);
    }

    /**
     * Creates a file version based on the passed parameters
     *
     * @param string $srcPath   path to the source file, relative to media_dir
     * @param array  $srcData   data of the source file
     * @param string $destPath  path to the target file, relative to media_dir
     * @param array  $details   Konfigurations-Array
     * @return array            data of the target file, or ['size'=>0, 'errors'=>'...']
     */
    public function convert(string $srcPath, array $srcData, string $destPath, array $details, bool $forceBgproc): array
    {
        $mediaDir = $this->config->get('paths.media_dir');
        $srcFile = $mediaDir . DIRECTORY_SEPARATOR . ltrim($srcPath, '/\\');
        $destFile =  $mediaDir . DIRECTORY_SEPARATOR . ltrim($destPath, '/\\');
        // Target format: extension from the converter (writeThumbnails() puts it into
        // $details), alternatively that of the target file
        $ext = strtolower((string)($details['extension'] ?: pathinfo($destPath, PATHINFO_EXTENSION)));
        
        $fileData = [
            'width'     => 0,
            'height'    => 0,
            'mime_type' => '',
            'size'      => 0,
            'filename'  => '',
            'extension' => '',
        ];
        
        // Check whether the source file exists and is readable
        if (!file_exists($srcFile) || !is_readable($srcFile)) {
            return $fileData + ['errors' => 'error_file_not_found'];
        }

        // Target format must be writable by GD - otherwise try the next converter
        if (!$this->formatAvailable($ext)) {
            return $fileData + ['errors' => 'proc.imagegd.error_file_not_supported', 'unsupported' => true];
        }

        // Read image information
        // (not an image GD knows - e.g. PDF, SVG: try the next converter)
        $info = @getimagesize($srcFile);
        if ($info === false) {
            return $fileData + ['errors' => 'proc.imagegd.error_file_dimensions', 'unsupported' => true];
        }

        [$srcW, $srcH, $type] = $info;

        // Originalbild laden
        // (format not in this GD build? Then the imagecreatefrom*() function is missing
        //  too - check beforehand, otherwise a fatal error)
        $readers = [
            IMAGETYPE_JPEG => ['jpg',  'imagecreatefromjpeg'],
            IMAGETYPE_PNG  => ['png',  'imagecreatefrompng'],
            IMAGETYPE_GIF  => ['gif',  'imagecreatefromgif'],
            IMAGETYPE_WEBP => ['webp', 'imagecreatefromwebp'],
            IMAGETYPE_BMP  => ['bmp',  'imagecreatefrombmp'],
            IMAGETYPE_AVIF => ['avif', 'imagecreatefromavif'],
        ];
        [$srcFormat, $reader] = $readers[$type] ?? ['', ''];
        if ($srcFormat === '' || !$this->formatAvailable($srcFormat) || !function_exists($reader)) {
            return $fileData + ['errors' => 'proc.imagegd.error_file_not_supported', 'unsupported' => true];
        }
        $srcImg = @$reader($srcFile);
        if (!$srcImg) {
            return $fileData + ['errors' => 'proc.imagegd.error_file_not_supported'];
        }

        // Calculate target dimensions and source section
        [$srcX, $srcY, $copyW, $copyH, $dstW, $dstH] = $this->targetGeometry($details, $srcW, $srcH);

        // Create target canvas (TrueColor RGB)
        $dstImg = imagecreatetruecolor($dstW, $dstH);

        // Evaluate background color (bgcolor)
        $bgColorHex = isset($details['bgcolor']) ? trim($details['bgcolor']) : '';
        $bgColorHex = ltrim($bgColorHex, '#'); // Remove leading hash, if present

        // Check with a regular expression for 3 or 6 valid hex characters
        if (!preg_match('/^([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $bgColorHex)) {
            $bgColorHex = 'FFF'; // Fallback for invalid or empty input
        }

        // Convert hex value to RGB values (0-255)
        if (strlen($bgColorHex) === 3) {
            $r = hexdec(str_repeat($bgColorHex[0], 2));
            $g = hexdec(str_repeat($bgColorHex[1], 2));
            $b = hexdec(str_repeat($bgColorHex[2], 2));
        } else {
            $r = hexdec(substr($bgColorHex, 0, 2));
            $g = hexdec(substr($bgColorHex, 2, 2));
            $b = hexdec(substr($bgColorHex, 4, 2));
        }

        // Apply alpha channel (transparency) or background color
        $keepAlpha = !empty($details['alpha']);
        if ($keepAlpha) {
            // Transparenz erhalten
            imagealphablending($dstImg, false);
            imagesavealpha($dstImg, true);
            $transparent = imagecolorallocatealpha($dstImg, 255, 255, 255, 127);
            imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, $transparent);
        } else {
            // Fill the background with the calculated RGB color
            $bgAllocated = imagecolorallocate($dstImg, $r, $g, $b);
            imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, $bgAllocated);
        }

        // Recalculate and copy the image
        imagecopyresampled($dstImg, $srcImg, 0, 0, $srcX, $srcY, $dstW, $dstH, $copyW, $copyH);

        // Adjust color depth (if a value below 24 is requested)
        $bit = isset($details['indexed_colors']) ? (int)$details['indexed_colors'] : 24;

        if ($bit < 24) {
            $colors = 0;
            
            switch ($bit) {
                case 8: $colors = 256; break;
                case 4: $colors = 16;  break;
                case 1: $colors = 2;   break;
            }

            if ($colors > 0) {
                // Parameter 2 (true) aktiviert Dithering. 
                // This noticeably softens hard color transitions during the reduction.
                imagetruecolortopalette($dstImg, true, $colors);
            }
        }

        // Save target image
        // --------------------

        // Komprimierung
        $quality = isset($details['quality']) ? (int)$details['quality'] : 80;
        $success = false;
        
        // MimeType
        $mimeType = '';
        switch ($ext) {
            case 'jpeg':
            case 'jpg':
            case 'jpe':
                $success = imagejpeg($dstImg, $destFile, $quality);
                $mimeType = 'image/jpeg';
                break;
            case 'png':
                // Calculate PNG compression (0-9) honestly from quality (0-100)
                $pngCompression = (int)round(9 - ($quality * 9 / 100));
                $pngCompression = max(0, min(9, $pngCompression));
                $success = imagepng($dstImg, $destFile, $pngCompression);
                $mimeType = 'image/png';
                break;
            case 'webp':
                $success = imagewebp($dstImg, $destFile, $quality);
                $mimeType = 'image/webp';
                break;
            case 'gif':
                $success = imagegif($dstImg, $destFile);
                $mimeType = 'image/gif';
                break;
            case 'bmp':
            case 'dib':
                $success = imagebmp($dstImg, $destFile);
                $mimeType = 'image/bmp';
                break;
            case 'avif':
                $success = imageavif($dstImg, $destFile, $quality);
                $mimeType = 'image/avif';
                break;
            default:
                // (formatAvailable() above has already caught unknown extensions)
                return $fileData + ['errors' => 'proc.imagegd.error_file_not_supported', 'unsupported' => true];
        }
        
        // Check whether saving was successful
        if (!$success) {
            return $fileData + ['errors' => 'error_file_conversion'];
        }

        /**
         * The following return structure is the same for all media converters.
         */
        $fileData = [
            'width'     => $dstW,
            'height'    => $dstH,
            'mime_type' => $mimeType,
            'size'      => filesize($destFile),
            'filename'  => (string)($srcData['filename'] ?? ''),
            'extension' => $ext,
            // Additional info on the source file was determined:
            'source_width' => $srcW,
            'source_height' => $srcH,
            // no source_data
        ];
        return $fileData;
    }

    /**
     * Target size and source section from target width/height and the size options,
     * equivalent to the geometry flags of Magick::geometryArguments():
     *
     *     crop         0 fit, 1 fill and crop centered
     *     resize_when  shrink  only downscale (>), enlarge only upscale (<)
     *     unit         percent width/height as a percentage of the source (%) - fit only;
     *                          one value applies to both sides, two values per side
     *     max_pixels   maximum pixels (@), afterwards, with the same condition
     *
     * If the change is excluded by the condition, the image keeps its size when
     * fitting; when cropping, it is only cropped centered to the target format
     * as far as the image reaches (without a border in the background color).
     * Cropping needs both dimensions; if one is missing, fitting is used.
     *
     * @return int[]  [srcX, srcY, copyW, copyH, dstW, dstH]
     */
    protected function targetGeometry(array $details, int $srcW, int $srcH): array
    {
        $reqW = max(0, (int)($details['width'] ?? 0));
        $reqH = max(0, (int)($details['height'] ?? 0));
        $percent = ($details['unit'] ?? 'px') === 'percent';
        $when = (string)($details['resize_when'] ?? 'always');
        $isCrop = !empty($details['crop']) && $reqW > 0 && $reqH > 0 && !$percent;

        // Does a factor change the size in the allowed direction?
        $allowed = fn(float $scale): bool => match ($when) {
            'shrink'  => $scale < 1,
            'enlarge' => $scale > 1,
            default   => true,
        };

        // Without target dimensions: original size (only format, quality ... change)
        $srcX = $srcY = 0;
        $copyW = $dstW = $srcW;
        $copyH = $dstH = $srcH;

        if ($percent && ($reqW > 0 || $reqH > 0)) {
            // 50 (both sides) or 50 x 30 (per side)
            $scaleW = ($reqW ?: $reqH) / 100;
            $scaleH = ($reqH ?: $reqW) / 100;
            // as in ImageMagick: downscale if one side is larger, upscale if one side is smaller,
            // upscale if one side becomes larger
            $change = match ($when) {
                'shrink'  => $scaleW < 1 || $scaleH < 1,
                'enlarge' => $scaleW > 1 || $scaleH > 1,
                default   => true,
            };
            if ($change) {
                $dstW = (int)round($srcW * $scaleW);
                $dstH = (int)round($srcH * $scaleH);
            }
        }
        elseif ($isCrop) {
            // Fill and crop centered
            $scale = max($reqW / $srcW, $reqH / $srcH);
            if ($allowed($scale)) {
                $copyW = (int)round($reqW / $scale);
                $copyH = (int)round($reqH / $scale);
                $dstW = $reqW;
                $dstH = $reqH;
            }
            else {
                // no size change allowed: only crop, as far as the image reaches
                $copyW = $dstW = min($reqW, $srcW);
                $copyH = $dstH = min($reqH, $srcH);
            }
            $srcX = (int)round(($srcW - $copyW) / 2);
            $srcY = (int)round(($srcH - $copyH) / 2);
        }
        elseif ($reqW > 0 || $reqH > 0) {
            // Fit: the whole image fits into the box
            $scale = min($reqW > 0 ? $reqW / $srcW : INF, $reqH > 0 ? $reqH / $srcH : INF);
            if ($allowed($scale)) {
                $dstW = (int)round($srcW * $scale);
                $dstH = (int)round($srcH * $scale);
            }
        }

        // Maximum pixels: proportional to the area, with the same condition
        $maxPixels = (int)($details['max_pixels'] ?? 0);
        if ($maxPixels > 0 && $dstW > 0 && $dstH > 0) {
            $scale = sqrt($maxPixels / ($dstW * $dstH));
            if ($allowed($scale)) {
                $dstW = (int)floor($dstW * $scale);
                $dstH = (int)floor($dstH * $scale);
            }
        }

        return [$srcX, $srcY, $copyW, $copyH, max(1, $dstW), max(1, $dstH)];
    }

}
