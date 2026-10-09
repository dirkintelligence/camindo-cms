<?php
namespace Camindo\Core\Processors\Magick;

use Camindo\Core\BackgroundProcess;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Execute;
use Camindo\Core\MediaProcessor;

class Magick extends MediaProcessor {

    public function __construct(
        Config $config,
        Cms $cms,
        Crypt $crypt,
        Execute $execute,
        BackgroundProcess $bgproc,
        /** Own policy.xml, active before each call (MagickPolicy, developer docs, chapter 14 (Security: web)) */
        protected MagickPolicy $policy,
    )
    {
        parent::__construct($config, $cms, $crypt, $execute, $bgproc);
    }

    protected const LIMIT_TIME       = 60;
    protected const LIMIT_MEMORY     = '256MiB';
    protected const LIMIT_MAP        = '512MiB';
    protected const LIMIT_DISK       = '1GiB';
    protected const TIMEOUT_CONVERT  = 120;
    protected const TIMEOUT_IDENTIFY = 30;
    protected const TIMEOUT_VERSION  = 10;

    /**
     * Oldest ImageMagick version that is considered harmless.
     * Below it, the converter info page marks the version as outdated
     * (see developer docs, chapter 14 (Security: web)).
     */
    public const MIN_VERSION = '7.1.1';

    /** Adjustment to the target format (details.crop) */
    public const FIT     = 0;   // fit entirely
    public const CROP    = 1;   // fill and crop (^ + -extent)
    public const FILL    = 2;   // fill, let overhang (^)
    public const DISTORT = 3;   // exactly to the format, ignore aspect ratio (!)

    /** Condition (details.resize_when) -> flags of the geometry */
    protected const RESIZE_FLAGS = ['always' => '', 'shrink' => '>', 'enlarge' => '<'];

    /** EXIF orientations for which -auto-orient rotates by 90° (width and height swap) */
    protected const ORIENTATION_ROTATED = ['LeftTop', 'RightTop', 'RightBottom', 'LeftBottom'];

    /** Type identifier in the table `bgproc`, which trigger_magick.php searches for */
    public const BGPROC_TYPE = 'magick';

    /** Prefix and extension of the temporary progress file */
    public const PROGRESS_PREFIX    = 'magick-';
    public const PROGRESS_EXTENSION = '.progress';

    /**
     * Mapping file extension -> MIME type of the target format.
     * Also used to decide whether an explicit format prefix
     * (e.g. "webp:/path/file.webp") is put in front of the target path.
     */
    protected const MIME_TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
        'tif'  => 'image/tiff',
        'tiff' => 'image/tiff',
        'avif' => 'image/avif',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'jp2'  => 'image/jp2',
        'ico'  => 'image/x-icon',
        'tga'  => 'image/x-tga',
        'psd'  => 'image/vnd.adobe.photoshop',
        'ppm'  => 'image/x-portable-pixmap',
        'pgm'  => 'image/x-portable-graymap',
        'pbm'  => 'image/x-portable-bitmap',
        'xpm'  => 'image/x-xpixmap',
        'pdf'  => 'application/pdf',
        'svg'  => 'image/svg+xml',
    ];

    /**
     * Allowed SOURCE FORMATS: server-side detected MIME type -> ImageMagick coder.
     *
     * Otherwise ImageMagick determines the format by the CONTENT of the file, not by the extension:
     * a "bild.jpg" that is in truth MVG or MSL text is executed as a script
     * executed as a script (ImageTragick, CVE-2016-3714 ff.). With a coder prefixed
     * ("jpeg:/path/bild.jpg") ImageMagick reads the file only with this one
     * coder; if the content does not match, it aborts instead of running a script or
     * Pseudo-Coder (MVG, MSL, TEXT, URL, FILE, EPHEMERAL ...) zu fallen.
     *
     * A MIME type that is missing here is not processed: convert() reports
     * 'unsupported', writeThumbnails() takes the next converter.
     *
     * SVG and PDF are deliberately NOT in the defaults of media_types_incl and
     * only run if security.magick_allow releases them (MagickPolicy). SVG goes
     * via the internal renderer 'msvg' - the coder 'svg' would first call inkscape or
     * rsvg-convert as a delegate, and the policy blocks delegates.
     *
     * @see developer_docs/18-imagemagick.md
     */
    protected const CODER_BY_MIME = [
        'image/jpeg'                => 'jpeg',
        'image/pjpeg'               => 'jpeg',
        'image/png'                 => 'png',
        'image/gif'                 => 'gif',
        'image/webp'                => 'webp',
        'image/bmp'                 => 'bmp',
        'image/x-bmp'               => 'bmp',
        'image/x-ms-bmp'            => 'bmp',
        'image/tiff'                => 'tiff',
        'image/x-tiff'              => 'tiff',
        'image/avif'                => 'avif',
        'image/heic'                => 'heic',
        'image/heif'                => 'heic',
        'image/jp2'                 => 'jp2',
        'image/jpx'                 => 'jp2',
        'image/jpeg2000'            => 'jp2',
        'image/x-icon'              => 'ico',
        'image/vnd.microsoft.icon'  => 'ico',
        'image/x-tga'               => 'tga',
        'image/x-targa'             => 'tga',
        'image/vnd.adobe.photoshop' => 'psd',
        'image/x-photoshop'         => 'psd',
        'application/x-photoshop'   => 'psd',
        'image/x-portable-pixmap'   => 'ppm',
        'image/x-portable-graymap'  => 'pgm',
        'image/x-portable-bitmap'   => 'pbm',
        'image/x-portable-anymap'   => 'pnm',
        'image/x-xpixmap'           => 'xpm',
        'image/svg+xml'             => 'msvg',
        'application/pdf'           => 'pdf',
    ];

    /**
     * Dateiendung -> ImageMagick-Coder.
     *
     * Two tasks: replacement for CODER_BY_MIME if a source file has no
     * (usable) MIME type - the coder is still enforced, so sniffing stays off;
     * and format prefix of the TARGET file, so that the output does not end up
     * in a foreign coder based on the extension.
     */
    protected const CODER_BY_EXTENSION = [
        'jpg'  => 'jpeg', 'jpeg' => 'jpeg', 'jpe' => 'jpeg',
        'png'  => 'png',
        'gif'  => 'gif',
        'webp' => 'webp',
        'bmp'  => 'bmp',  'dib'  => 'bmp',
        'tif'  => 'tiff', 'tiff' => 'tiff',
        'avif' => 'avif',
        'heic' => 'heic', 'heif' => 'heic',
        'jp2'  => 'jp2',
        'ico'  => 'ico',
        'tga'  => 'tga',
        'psd'  => 'psd',
        'ppm'  => 'ppm',  'pgm'  => 'pgm', 'pbm' => 'pbm', 'pnm' => 'pnm',
        'xpm'  => 'xpm',
        'svg'  => 'msvg',
        'pdf'  => 'pdf',
    ];

    /**
     * Option groups for the "intelligent merge" (expert mode).
     *
     * If the administrator sets an option from a group in parameters1 / parameters2, the converter
     * no longer generates the corresponding own parameters. The hand-written parameter therefore
     * always wins, but only for "its" area. All other form values remain effective.
     */
    protected const OPTION_GROUPS = [
        // Target dimensions / cropping -> replaces width, height, crop
        'geometry'   => ['resize', 'thumbnail', 'sample', 'scale', 'adaptive-resize',
                         'crop', 'extent', 'geometry', 'distort', 'liquid-rescale',
                         'trim', 'shave'],
        // Crop alignment              -> replaces only "-gravity center"
        'gravity'    => ['gravity'],
        // Background color             -> replaces bgcolor
        'background' => ['background'],
        // Transparenz                  -> ersetzt alpha
        'alpha'      => ['alpha', 'flatten', 'matte', 'transparent', 'opaque'],
        // Farbreduktion                -> ersetzt indexed_colors
        'colors'     => ['colors', 'remap', 'map', 'monochrome', 'type', 'dither', 'posterize'],
        // Quality                      -> replaces quality
        'quality'    => ['quality'],
        // Metadaten                    -> ersetzt "-strip"
        'strip'      => ['strip', 'profile', 'thumbnail'],
        // Drehung                      -> ersetzt "-auto-orient"
        'orient'     => ['auto-orient', 'orient', 'rotate', 'flip', 'flop',
                         'transpose', 'transverse'],
        // Single images / animation    -> suppresses the frame selection "[0]" at the source
        'frames'     => ['coalesce', 'layers', 'delete', 'scene', 'adjoin',
                         'deconstruct', 'flatten', 'append', 'duplicate'],
    ];

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
        // ImageMagick processes significantly more formats than GD.
        // Which of them are actually available depends on the
        // installed delegates ("magick -list format").
        //
        // NOT preset are "image/svg+xml" and "application/pdf": both are
        // not read by ImageMagick itself, but passed on to librsvg or Ghostscript -
        // the most dangerous delegations (XXE, -dSAFER bypasses).
        // Whoever needs them adds them deliberately; the note on this is below the
        // media types in the converter form (#proc.magick.note_media_types).
        // Siehe developer_docs/18-imagemagick.md
        $this->defaults['media_types_incl'] = implode("\n", [
            "image/jpeg",
            "image/png",
            "image/gif",
            "image/webp",
            "image/bmp",
            "image/tiff",
            "image/avif",
            "image/heic",
            "image/heif",
            "image/jp2",
            "image/x-icon",
            "image/x-tga",
            "image/x-portable-pixmap",
            "image/vnd.adobe.photoshop"
        ]);

        // Additional input fields for the converter
        $this->schema = [
            'details.width' => [
                'type'  => 'integer',
                'label' => '#proc.magick.field_width',
                'default' => '300',
                'min' => 0,
                'suffix' => 'px'
            ],
            'details.height' => [
                'type'  => 'integer',
                'label' => '#proc.magick.field_height',
                'default' => '',
                'min' => 0,
                'suffix' => 'px'
            ],
            // Size: adjustment to the target format, condition, unit, maximum pixels -
            // the geometry flags (^ ! > < % @) that depend on the target size
            // and therefore cannot be supplemented via parameters1/2 (buildArguments())
            'details.crop' => [
                'type'  => 'integer',
                'label' => '#proc.magick.field_crop',
                'tooltip' => '#proc.magick.tooltip_field_crop',
                'display_type' => 'radio',
                'options' => [
                    self::FIT     => '#proc.magick.label_crop_fit',
                    self::CROP    => '#proc.magick.label_crop_crop',
                    self::FILL    => '#proc.magick.label_crop_fill',
                    self::DISTORT => '#proc.magick.label_crop_distort',
                ],
                'default' => self::FIT
            ],
            'details.resize_when' => [
                'type'  => 'chars',
                'label' => '#proc.magick.field_resize_when',
                'tooltip' => '#proc.magick.tooltip_field_resize_when',
                'display_type' => 'select',
                'options' => [
                    'always'  => '#proc.magick.option_resize_always',
                    'shrink'  => '#proc.magick.option_resize_shrink',
                    'enlarge' => '#proc.magick.option_resize_enlarge',
                ],
                'default' => 'always',
                'max' => 10
            ],
            'details.unit' => [
                'type'  => 'chars',
                'label' => '#proc.magick.field_unit',
                'tooltip' => '#proc.magick.tooltip_field_unit',
                'display_type' => 'select',
                'options' => [
                    'px'      => '#proc.magick.option_unit_px',
                    'percent' => '#proc.magick.option_unit_percent',
                ],
                'default' => 'px',
                'max' => 10
            ],
            'details.max_pixels' => [
                'type'  => 'integer',
                'label' => '#proc.magick.field_max_pixels',
                'tooltip' => '#proc.magick.tooltip_field_max_pixels',
                'note' => '#proc.magick.note_max_pixels',
                'min' => 0
            ],
            'details.quality' => [
                'type'  => 'integer',
                'label' => '#proc.magick.field_quality',
                'default' => '80',
                'suffix' => '%',
                'note' => '#proc.magick.note_quality',
                'tooltip' => '#proc.magick.tooltip_field_quality',
                'min' => 0,
                'max' => 100
            ],
            'details.indexed_colors' => [
                'type'  => 'integer',
                'label' => '#proc.magick.field_indexed_colors',
                'default' => 'TrueColor',
                'note' => '#proc.magick.note_indexed_colors',
                'tooltip' => '#proc.magick.tooltip_field_indexed_colors',
                'min' => 0,
                'max' => 256
            ],
            'details.alpha' => [
                'type'  => 'bit',
                'label' => '#proc.magick.field_alpha',
                'tooltip' => '#proc.magick.tooltip_field_alpha',
                'display_type' => 'switch',
            ],
            'details.bgcolor' => [
                'type'  => 'chars',
                'label' => '#proc.magick.field_bgcolor',
                'note' => '#proc.magick.note_field_bgcolor',
                'tooltip' => '#proc.magick.tooltip_field_bgcolor',
                'default' => '#ffffff',
                // Nur echte Hexadezimalzeichen zulassen
                'pattern' => '#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})',
                'max' => 7
            ],

            // ---- Expertenmodus ----
            // magick [parameters1] {Quelldatei} [parameters2] {Zieldatei}
            'details.parameters1' => [
                'type'  => 'chars',
                'label' => '#proc.magick.field_parameters1',
                'note' => '#proc.magick.note_parameters1',
                'tooltip' => '#proc.magick.tooltip_field_parameters1',
                'default' => '-strip -colorspace sRGB',
                'max' => 255
            ],
            'details.parameters2' => [
                'type'  => 'chars',
                'label' => '#proc.magick.field_parameters2',
                'note' => '#proc.magick.note_parameters2',
                'tooltip' => '#proc.magick.tooltip_field_parameters2',
                'default' => '',
                'max' => 255
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
        foreach (['crop', 'resize_when', 'unit'] as $key) {
            if (!isset($data['details'][$key]) || $data['details'][$key] === '') continue;
            $allowed = array_map('strval', array_keys($this->schema['details.' . $key]['options']));
            if (!in_array((string)$data['details'][$key], $allowed, true)) {
                throw new \Exception(strtr($this->cms->snippet('proc.magick.error_option'), [
                    '{field}' => $this->cms->textOrSnippet($this->schema['details.' . $key]['label']),
                    '{values}' => implode(', ', $allowed),
                ]));
            }
        }

        // bgcolor, stored without '#'
        $data['details']['bgcolor'] = ltrim($data['details']['bgcolor'] ?? '', '#');
        $indexedColors = $data['details']['indexed_colors'] ?? '';
        if ($indexedColors === 'TrueColor') $data['details']['indexed_colors'] = '';

        // Expert mode: remove line breaks and multiple spaces,
        // so that the stored command line always stays on one line.
        foreach (['parameters1', 'parameters2'] as $key) {
            $value = trim((string) ($data['details'][$key] ?? ''));
            $data['details'][$key] = (string) preg_replace('/\s+/', ' ', $value);
        }

        // Is there an ImageMagick running under paths.magick at all? Otherwise the converter
        // would be ineffective from the start - as with the FFmpeg processors, saving
        // therefore aborts. A version that is too OLD, by contrast, does not block: it
        // works, it is just unpatched. The info page of the
        // Konverters hin (backend/info.php, versionState()).
        if ($this->getVersion() === null) {
            throw new \Exception($this->cms->snippet('proc.magick.error_binary_missing'));
        }
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
     * Creates a file version based on the passed parameters
     *
     * @param string $srcPath   path to the source file, relative to media_dir
     * @param array  $srcData   data of the source file
     *      'extension' => $fileExtension,
     *      'mime_type' => $fileMime,
     *      'size'      => $fileSize,        // in bytes
     *      'filename'  => $fileOriginName,  // original file name, sanitized against injections
     *      'field_id'  => $fieldId,         // for file path / `data_fil` entry at check-in
     * @param string $destPath  path to the target file, relative to media_dir
     * @param array  $details   Konfigurations-Array
     * @param bool $forceBgproc true: forces execution as a background process
     * @return array            data of the target file, or ['size'=>0, 'errors'=>'...']
     */
    public function convert(string $srcPath, array $srcData, string $destPath, array $details, bool $forceBgproc): array
    {
        $mediaDir = $this->config->get('paths.media_dir');
        $srcFile = $mediaDir . DIRECTORY_SEPARATOR . ltrim($srcPath, '/\\');
        $destFile =  $mediaDir . DIRECTORY_SEPARATOR . ltrim($destPath, '/\\');
        // Extension of the TARGET file: the converter specifies it, otherwise it inherits the
        // source file (writeThumbnails() adds $details['extension'] to it).
        $srcExt = strtolower((string)pathinfo($srcPath, PATHINFO_EXTENSION));
        $ext = strtolower((string)($details['extension'] ?: pathinfo($destPath, PATHINFO_EXTENSION)));

        $fileData = [
            'width'     => 0,
            'height'    => 0,
            'mime_type' => '',
            'size'      => 0,
            'filename'  => '',
            'extension' => '',
        ];

        // ImageMagick (version 7, command "magick") must be available.
        // If the configured value contains no path separator, it is treated as a
        // command in PATH and not checked any further.
        $magick = $this->config->get('paths.magick') ?? '';
        if ($magick === '') {
            return $fileData + ['errors' => 'proc.magick.error_binary_missing'];
        }
        if (!is_executable($magick)) {
            return $fileData + ['errors' => 'proc.magick.error_binary_missing'];
        }

        // Check whether the source file exists and is readable
        if (!file_exists($srcFile) || !is_readable($srcFile)) {
            return $fileData + ['errors' => 'error_file_not_found'];
        }

        // Activate our own policy.xml. If it cannot be written, ImageMagick
        // does not run - without the policy the blocks and limits are missing.
        if (!$this->policy->apply()) {
            return $fileData + ['errors' => 'proc.magick.error_policy_file'];
        }

        // Set the coder of the source file (see CODER_BY_MIME). Without a matching coder
        // the file is not opened at all - writeThumbnails() then tries the
        // next converter of the thumbnail field.
        $srcCoder = $this->coderFor((string)($srcData['mime_type'] ?? ''), $srcExt);
        if ($srcCoder === '') {
            return $fileData + [
                'errors' => 'proc.magick.error_file_not_supported',
                'unsupported' => true,
            ];
        }
        // SVG/PDF only with security.magick_allow - as source as well as target. Blocked
        // counts as "not supported": writeThumbnails() takes the next converter.
        foreach ([$srcCoder, self::CODER_BY_EXTENSION[$ext] ?? ''] as $coder) {
            $format = MagickPolicy::formatOfCoder($coder);
            if ($format !== '' && !$this->policy->isAllowed($format)) {
                return $fileData + [
                    'errors' => 'proc.magick.error_format_blocked',
                    'unsupported' => true,
                ];
            }
        }

        // Expert mode: split and evaluate free parameters.
        // The limits deliberately stand at the very front; a specification of the
        // administrator comes afterwards and wins.
        $params1 = array_merge(
            $this->limitArguments(),
            $this->execute->tokenizeParameters((string) ($details['parameters1'] ?? ''))
        );
        $params2 = $this->execute->tokenizeParameters((string) ($details['parameters2'] ?? ''));
        $claimed = $this->claimedGroups(array_merge($params1, $params2));

        // Source: by default only process the first single image
        // (multi-page PDFs, animated GIFs, ICO with several sizes ...).
        // If the administrator deliberately works with frames (-coalesce, -layers ...),
        // the selection is not set.
        $srcArg = $this->fileArgument($srcCoder, $srcFile, !$claimed['frames']);

        // Determine dimensions of the source file
        $srcInfo = $this->identify($magick, $srcFile, $srcCoder);
        if ($srcInfo === null) {
            // (no readable image format: writeThumbnails() tries the next converter)
            return $fileData + ['errors' => 'proc.magick.error_file_dimensions', 'unsupported' => true];
        }
        $srcW = $srcInfo['width'];
        $srcH = $srcInfo['height'];

        // Parameters generated from the form values - with the dimensions as
        // -auto-orient aligns them (EXIF rotation by 90°: width and height swap)
        $rotated = !$claimed['orient'] && in_array($srcInfo['orientation'], self::ORIENTATION_ROTATED, true);
        $args = $this->buildArguments($details, $ext, $claimed, $rotated ? $srcH : $srcW, $rotated ? $srcW : $srcH);

        // Target file: specify the format explicitly so that the extension is not
        // misinterpreted (e.g. ".jpg" with PNG content).
        $destCoder = self::CODER_BY_EXTENSION[$ext] ?? '';
        $destArg = $this->fileArgument($destCoder, $destFile, false);

        $magickExec = basename($magick);
        $magickPath = dirname($magick);
        $magickVersion = $magickExec == 'magick' ? 7 : 6;

        // magick [parameters1] {source file} [generated parameters] [parameters2] {target file}
        if ($magickVersion >= 7) $call = [$magick, 'convert'];
        else $call = [$magick];

        // Progress file for the background process.
        $progressArgs = [];
        if ($forceBgproc) {
            // Token is passed on into the background process!
            $token = $this->crypt->randomKey(16);
            $tmpDir = (string)($this->config->get('paths.tmp_dir') ?? '');
            if ($tmpDir === '' || !is_dir($tmpDir) || !is_writable($tmpDir)) {
                return $fileData + ['errors' => 'proc.magick.error_tmp_dir'];
            }
            $progressArgs = [
                '-monitor'
            ];
            $progressFile = $tmpDir . DIRECTORY_SEPARATOR . self::PROGRESS_PREFIX . $token . self::PROGRESS_EXTENSION;
        }

        $command = array_merge(
            $call,
            $params1,
            [$srcArg],
            $args,
            $params2,
            $progressArgs,
            [$destArg]
        );

        if ($forceBgproc) {
            // Token is passed on into the background process!
            // magick writes its progress (-monitor) to stderr.
            // 'timeout' : time limit in seconds, after which TriggerMagick intervenes
            //             (magick has no converter setting for this)
            // 'dest'    : path to the file from the media directory
            // ('proc' is added by startBackgroundProcess() itself)
            $message = [
                'timeout' => (string)self::TIMEOUT_CONVERT,
                'dest'    => $destPath,
            ];
            $error   = '';
            $started = $this->startBackgroundProcess(
                $command, $token, $message, stderrFile:$progressFile, error:$error
            );
            if ($started === null) {
                return $fileData + [
                    'errors' => 'proc.magick.error_background_start',
                    'error_details' => $error,
                ];
            }
            return array_merge($fileData, [
                'width' => 0,
                'height' => 0,
                'size' => 0,
                // set mime_type neutrally: the thumbnail should in the backend for now
                // only shown with a placeholder symbol
                'mime_type'  => 'image/unknown',
                'filename'   => $srcData['filename'] ?? 'file',
                'extension'  => $ext,
                'data' => '{}',
                // Info on the source file
                'source_width'  => $srcW,
                'source_height' => $srcH,
                // 'data' => '{}' could be a JSON string with further info (flat associative array)
                // Info on the background process
                'token'      => (string)$started['token'],
                'bgproc_id'  => (int)$started['id'],
                'pid'        => (int)$started['pid']
            ]);
        }
        else {
            $exitCode = $this->execute->execute($command, $stdout, $stderr, self::TIMEOUT_CONVERT);
        }

        // Check whether saving was successful
        if ($exitCode !== 0 || !is_file($destFile) || filesize($destFile) === 0) {
            // Do not leave a started or empty target file behind
            if (is_file($destFile)) @unlink($destFile);

            return $fileData + [
                'errors' => $exitCode === $this->execute::EXEC_TIMEOUT
                    ? 'proc.magick.error_timeout'
                    : 'error_file_conversion',
                // Plain-text message from ImageMagick, helpful for troubleshooting
                'error_details' => trim($stderr),
            ];
        }

        // Read the actual dimensions of the target file.
        // They are not calculated, because the free parameters can change
        // the result arbitrarily.
        $destInfo = $this->identify($magick, $destFile, $destCoder);

        /**
         * The following return structure is the same for all media converters.
         */
        $fileData = [
            'width'     => $destInfo['width'] ?? 0,
            'height'    => $destInfo['height'] ?? 0,
            'mime_type' => self::MIME_TYPES[$ext] ?? ($destInfo['mime_type'] ?? ''),
            'size'      => filesize($destFile),
            'filename'  => $srcData['filename'] ?? 'file',
            'extension' => $ext,
            // Additional info on the source file was determined:
            'source_width' => $srcW,
            'source_height' => $srcH,
            // 'data' => '{}' could be a JSON string with further info (flat associative array)
        ];
        return $fileData;
    }

    /**
     * Creates the parameters that result from the form fields.
     * Option groups already set by the administrator are skipped.
     *
     * @param array  $details  Konfigurations-Array
     * @param string $ext      extension / format of the target file
     * @param array  $claimed  result of claimedGroups()
     * @param int    $srcW     width of the source (aligned; 0 = unknown)
     * @param int    $srcH     height of the source (aligned; 0 = unknown)
     * @return array           argument list for the command line
     */
    protected function buildArguments(array $details, string $ext, array $claimed, int $srcW = 0, int $srcH = 0): array
    {
        $args = [];

        // Apply EXIF orientation (GD does not know this, but the result
        // corresponds to what the user sees in the image)
        if (!$claimed['orient']) {
            $args[] = '-auto-orient';
        }

        // Apply alpha channel (transparency) or background color
        $keepAlpha = !empty($details['alpha']);
        if ($keepAlpha) {
            // Transparenz erhalten
            if (!$claimed['background']) {
                $args[] = '-background';
                $args[] = 'none';
            }
            if (!$claimed['alpha']) {
                $args[] = '-alpha';
                $args[] = 'on';
            }
        } else {
            // Fill the background with the configured color.
            // "-alpha remove" before scaling avoids color fringes at
            // transparent edges.
            if (!$claimed['background']) {
                $args[] = '-background';
                $args[] = $this->evaluateBgColor($details);
            }
            if (!$claimed['alpha']) {
                $args[] = '-alpha';
                $args[] = 'remove';
                $args[] = '-alpha';
                $args[] = 'off';
            }
        }

        // Ziel-Dimensionen
        if (!$claimed['geometry']) {
            array_push($args, ...$this->geometryArguments($details, $claimed, $srcW, $srcH));
        }

        // Adjust color depth (only if a number of colors is specified)
        if (!$claimed['colors']) {
            $colors = (int) ($details['indexed_colors'] ?? 0);
            if ($colors > 0) {
                $args[] = '-colors';
                $args[] = (string) min(256, $colors);
            }
        }

        // Metadaten entfernen (GD verwirft sie ohnehin)
        if (!$claimed['strip']) {
            $args[] = '-strip';
        }

        // Quality
        if (!$claimed['quality']) {
            $quality = isset($details['quality']) ? (int) $details['quality'] : 80;
            $quality = max(0, min(100, $quality));

            if ($ext === 'png') {
                // For PNG, -quality means something different than for JPEG:
                // Zehnerstelle = zlib-Kompression (0-9), Einerstelle = Filtertyp.
                // we convert the percentage as in the GD converter,
                // Filtertyp 5 = adaptiv.
                $pngCompression = (int) round(9 - ($quality * 9 / 100));
                $pngCompression = max(0, min(9, $pngCompression));
                $args[] = '-quality';
                $args[] = (string) ($pngCompression * 10 + 5);
            } elseif (in_array($ext, ['jpg', 'jpeg', 'webp', 'avif', 'heic', 'heif', 'jp2', 'tif', 'tiff'], true)) {
                $args[] = '-quality';
                $args[] = (string) $quality;
            }
            // GIF, BMP and the like know no lossy quality
        }

        return $args;
    }

    /**
     * The size change from target width/height and the geometry flags:
     *
     *     crop         FIT      einpassen                 -resize 440x300
     *                  CROP     fill, crop                -resize 440x300^ -extent 440x300
     *                  FILL     fill, overhang            -resize 440x300^
     *                  DISTORT  ignore aspect ratio      -resize 440x300!
     *     resize_when  shrink   only downscale (>), enlarge only upscale (<)
     *     unit         percent  width/height in percent (%) - fit/distort only
     *     max_pixels   maximum pixels (@), afterwards, with the same condition
     *
     * ">" and "<" are only suitable for fitting and distorting: when filling, the factor
     * enlarges as soon as one side is too short. There the factor from the source
     * dimensions decides: if no change is allowed, CROP only crops centered to the
     * target format, as far as the image reaches (without a border in the background
     * color), FILL leaves the image as it is. Cropping and filling both need
     * both dimensions; if one is missing, fitting is used (the result is the same).
     *
     * @param int $srcW  width of the source (aligned; 0 = unknown)
     * @param int $srcH  height of the source (aligned; 0 = unknown)
     */
    protected function geometryArguments(array $details, array $claimed, int $srcW, int $srcH): array
    {
        $args = [];
        $reqW = max(0, (int)($details['width'] ?? 0));
        $reqH = max(0, (int)($details['height'] ?? 0));
        $percent = ($details['unit'] ?? 'px') === 'percent';
        $flag = self::RESIZE_FLAGS[(string)($details['resize_when'] ?? 'always')] ?? '';
        $mode = (int)($details['crop'] ?? self::FIT);
        if (!in_array($mode, [self::FIT, self::CROP, self::FILL, self::DISTORT], true)) $mode = self::FIT;
        // Filling, cropping, distorting need both dimensions; percent only fitting
        // or distorting
        if ($reqW < 1 || $reqH < 1 || ($percent && $mode !== self::DISTORT)) $mode = self::FIT;

        if ($reqW > 0 || $reqH > 0) {
            if ($percent) {
                // 50% (both sides), 50x30% (per side)
                $geometry = $reqW > 0 && $reqH > 0 ? $reqW . 'x' . $reqH . '%' : max($reqW, $reqH) . '%';
            }
            else {
                $geometry = ($reqW > 0 ? (string)$reqW : '') . 'x' . ($reqH > 0 ? (string)$reqH : '');
            }

            if ($mode === self::CROP || $mode === self::FILL) {
                // Factor for filling - without source dimensions the condition does not apply
                $scale = $srcW > 0 && $srcH > 0 ? max($reqW / $srcW, $reqH / $srcH) : 0.0;
                $blocked = $scale > 0 && (($flag === '>' && $scale >= 1) || ($flag === '<' && $scale <= 1));
                if ($blocked) {
                    if ($mode === self::CROP) {
                        if (!$claimed['gravity']) array_push($args, '-gravity', 'center');
                        array_push($args, '-crop', min($reqW, $srcW) . 'x' . min($reqH, $srcH) . '+0+0', '+repage');
                    }
                }
                else {
                    array_push($args, '-resize', $geometry . '^');
                    if ($mode === self::CROP) {
                        if (!$claimed['gravity']) array_push($args, '-gravity', 'center');
                        array_push($args, '-extent', $geometry);
                    }
                }
            }
            else {
                array_push($args, '-resize', $geometry . ($mode === self::DISTORT ? '!' : '') . $flag);
            }
        }

        $maxPixels = (int)($details['max_pixels'] ?? 0);
        if ($maxPixels > 0) array_push($args, '-resize', $maxPixels . '@' . $flag);
        return $args;
    }

    /**
     * Evaluate the background color and return it as '#rrggbb'
     */
    protected function evaluateBgColor(array $details): string
    {
        $bgColorHex = isset($details['bgcolor']) ? trim((string) $details['bgcolor']) : '';
        $bgColorHex = ltrim($bgColorHex, '#'); // Remove leading hash, if present

        // Check with a regular expression for 3 or 6 valid hex characters
        if (!preg_match('/^([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $bgColorHex)) {
            $bgColorHex = 'FFF'; // Fallback for invalid or empty input
        }

        // expand the short notation to 6 characters
        if (strlen($bgColorHex) === 3) {
            $bgColorHex = str_repeat($bgColorHex[0], 2)
                        . str_repeat($bgColorHex[1], 2)
                        . str_repeat($bgColorHex[2], 2);
        }

        return '#' . strtolower($bgColorHex);
    }

    /**
     * Determines which option groups the administrator has set themselves.
     *
     * @param array $tokens arguments from parameters1 and parameters2
     * @return array        ['geometry' => bool, 'quality' => bool, ...]
     */
    protected function claimedGroups(array $tokens): array
    {
        $claimed = array_fill_keys(array_keys(self::OPTION_GROUPS), false);

        foreach ($tokens as $token) {
            // Only consider options, not values
            if ($token === '' || ($token[0] !== '-' && $token[0] !== '+')) continue;

            $option = strtolower(ltrim($token, '-+'));
            foreach (self::OPTION_GROUPS as $group => $options) {
                if (in_array($option, $options, true)) $claimed[$group] = true;
            }
        }

        return $claimed;
    }

    /**
     * Determine the coder for a source file: first via the server-side detected
     * MIME type, alternatively via the file extension.
     *
     * The extension is the weaker, but still safe, path: it only determines
     * WHICH coder is enforced - if the content does not match, ImageMagick
     * fails instead of guessing the format from the content. It is needed for
     * files without a reliable MIME specification, such as a thumbnail created in the
     * background that is itself the source of a thumbnail.
     *
     * @param string $mime       MIME type of the source file (may be empty)
     * @param string $extension  extension of the source file, without dot
     * @return string            coder, or '' if the format is not allowed
     */
    protected function coderFor(string $mime, string $extension): string
    {
        $mime = strtolower(trim($mime));
        if (isset(self::CODER_BY_MIME[$mime])) return self::CODER_BY_MIME[$mime];

        return self::CODER_BY_EXTENSION[strtolower(ltrim(trim($extension), '.'))] ?? '';
    }

    /**
     * File argument for the command line: coder prefix, path and frame selection.
     *
     * @param string $coder       coder, e.g. 'jpeg'; empty = no prefix
     * @param string $file        absolute path
     * @param bool   $firstFrame  true: only the first single image ("[0]")
     */
    protected function fileArgument(string $coder, string $file, bool $firstFrame = true): string
    {
        return ($coder !== '' ? $coder . ':' : '') . $file . ($firstFrame ? '[0]' : '');
    }

    /**
     * Resource limits that apply to EVERY call - also for identify().
     *
     * A decompression bomb (tiny PNG, gigapixels in memory) or a
     * crafted image with an endless loop would otherwise tie up the whole server
     * already when reading the dimensions. The values stand at the front of the command line,
     * own specifications in expert mode come afterwards and win.
     */
    protected function limitArguments(): array
    {
        return [
            '-limit', 'time',   (string) self::LIMIT_TIME,
            '-limit', 'memory', self::LIMIT_MEMORY,
            '-limit', 'map',    self::LIMIT_MAP,
            '-limit', 'disk',   self::LIMIT_DISK,
        ];
    }

    /**
     * Reads width, height and format of a file with "magick identify"
     *
     * @param string $magick  path to the program
     * @param string $file    absolute path to the file, WITHOUT coder prefix and "[0]"
     * @param string $coder   enforced coder (see coderFor()); empty = no prefix
     * @return array|null ['width'=>int, 'height'=>int, 'orientation'=>string, 'mime_type'=>string] or null on error
     */
    protected function identify(string $magick, string $file, string $coder = ''): ?array
    {
        if (!$this->policy->apply()) return null;

        $magickExec = basename($magick);
        $magickPath = dirname($magick);
        $magickVersion = str_starts_with($magickExec, 'magick') ? 7 : 6;

        if ($magickVersion >= 7) $call = [$magick, 'identify'];
        else $call = [$magickPath . DIRECTORY_SEPARATOR . 'identify'];
        
        $exitCode = $this->execute->execute(
            [...$call, ...$this->limitArguments(), '-format', '%w %h %[orientation] %[mime]',
             $this->fileArgument($coder, $file)],
            $stdout,
            $stderr,
            self::TIMEOUT_IDENTIFY
        );
        if ($exitCode !== 0) return null;

        // With several single images, one line is returned per image
        $line  = strtok(trim($stdout), "\n");
        $parts = preg_split('/\s+/', trim((string) $line));
        if (!is_array($parts) || count($parts) < 2) return null;

        return [
            'width'       => (int) $parts[0],
            'height'      => (int) $parts[1],
            // EXIF orientation (TopLeft, RightTop ...; without specification: Undefined)
            'orientation' => (string)($parts[2] ?? ''),
            // %[mime] is empty for some formats, then the fourth part is missing
            'mime_type'   => (string)($parts[3] ?? '')
        ];
    }

    /**
     * Public method to add the parameters of a thumbnail created by a background process
     * to table `data_fil`
     * 
     * @param string $srcPath   relative path to the file from the media directory
     * @return array            data determined via identify + file size
     *                          width, height, mime_type, extension, size
     */
    public function rectifyParams(string $srcPath): array
    {
        // Absolute path to the file
        $srcFile = $this->config->get('paths.media_dir') . '/' . $srcPath;

        // Check whether the source file exists and is readable
        if (!file_exists($srcFile) || !is_readable($srcFile)) {
            return [
                'error' => '#error_file_not_found'
            ];
        }

        // magick/convert must be available
        $magick = $this->config->get('paths.magick') ?? '';
        if ($magick === '') {
            return ['error' => 'proc.magick.error_binary_missing'];
        }
        if (!is_executable($magick)) {
            return ['error' => 'proc.magick.error_binary_missing'];
        }

        // Determine properties of the file.
        // The converter created the file itself, so its extension specifies the
        // format - the coder is enforced anyway (no sniffing).
        $extension = strtolower((string)pathinfo($srcFile, PATHINFO_EXTENSION));
        $srcInfo = $this->identify($magick, $srcFile, self::CODER_BY_EXTENSION[$extension] ?? '');
        if ($srcInfo === null || $srcInfo['width'] <= 0 || $srcInfo['height'] <= 0) {
            return [
                'error' => 'proc.magick.error_file_dimensions'
            ];
        }

        // identify returns: width, height, mime_type
        $srcInfo['size'] = filesize($srcFile);
        $srcInfo['extension'] = $extension;
        return $srcInfo;
    }

    /**
     * Does a releasable format (MagickPolicy::ALLOWABLE) actually run on this server?
     * For the info page of the converter: the camindo policy releases it, but the
     * system's policy can still block it (Debian blocks PDF), or Ghostscript is
     * missing. A small test run clarifies this instead of interpreting the policies:
     *   svg  write a mini SVG and read it with msvg:
     *   pdf  create a mini PDF (ImageMagick writes PDF itself) and read it with gs
     *
     * @param string $format  'svg' | 'pdf'
     * @return array ['ok' => bool, 'error' => string] - error: first line of ImageMagick
     */
    public function probeFormat(string $format): array
    {
        if (!$this->policy->isAllowed($format)) return ['ok' => false, 'error' => 'blocked'];
        $magick = (string)($this->config->get('paths.magick') ?? '');
        if ($magick === '' || !is_executable($magick)) return ['ok' => false, 'error' => 'no binary'];
        if (!$this->policy->apply()) return ['ok' => false, 'error' => 'policy file'];
        $tmpDir = (string)($this->config->get('paths.tmp_dir') ?? '');
        if ($tmpDir === '' || !is_dir($tmpDir) || !is_writable($tmpDir)) return ['ok' => false, 'error' => 'tmp dir'];

        $call = basename($magick) === 'magick' ? [$magick, 'convert'] : [$magick];
        $base = $tmpDir . DIRECTORY_SEPARATOR . 'magick-probe-' . $this->crypt->randomKey(8);
        $out = $base . '.png';
        $stderr = '';
        if ($format === 'svg') {
            file_put_contents($base . '.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8">'
                . '<rect width="8" height="8" fill="#000"/></svg>');
            $code = $this->execute->execute([...$call, ...$this->limitArguments(), 'msvg:' . $base . '.svg', 'png:' . $out],
                $stdout, $stderr, self::TIMEOUT_IDENTIFY);
        }
        else {
            $code = $this->execute->execute([...$call, '-size', '8x8', 'xc:white', 'pdf:' . $base . '.pdf'],
                $stdout, $stderr, self::TIMEOUT_IDENTIFY);
            if ($code === 0) {
                $code = $this->execute->execute([...$call, ...$this->limitArguments(), 'pdf:' . $base . '.pdf[0]', 'png:' . $out],
                    $stdout, $stderr, self::TIMEOUT_IDENTIFY);
            }
        }
        $ok = $code === 0 && is_file($out) && filesize($out) > 0;
        foreach (['.svg', '.pdf', '.png'] as $suffix) {
            if (is_file($base . $suffix)) @unlink($base . $suffix);
        }
        return ['ok' => $ok, 'error' => $ok ? '' : (string)strtok(trim((string)$stderr), "\n")];
    }

    /** The policy of the processor (info page) */
    public function policy(): MagickPolicy
    {
        return $this->policy;
    }

    /**
     * Read the version of the configured ImageMagick.
     *
     * @return string|null e.g. "7.1.1-39", null if no
     *                     working ImageMagick is installed under paths.magick
     */
    public function getVersion(): ?string
    {
        $magick = (string)($this->config->get('paths.magick') ?? '');
        if ($magick === '' || !is_executable($magick)) return null;

        $exitCode = $this->execute->execute([$magick, '-version'], $stdout, $stderr, self::TIMEOUT_VERSION);
        if ($exitCode !== 0) return null;

        if (!preg_match('/ImageMagick\s+([0-9]+\.[0-9]+\.[0-9\-]+)/i', (string)$stdout, $match)) return null;

        return $match[1];
    }

    /**
     * State of the installed ImageMagick version for display in the backend.
     *
     * ImageMagick before MIN_VERSION is not fundamentally unusable, but unpatched:
     * the -dSAFER bypasses in Ghostscript and the fuzzing findings in the binary formats
     * were only closed in the newer releases (developer docs, chapter 14 (Security: web)).
     *
     * @return array ['version' => ?string, 'outdated' => bool, 'snippet' => string]
     *               'snippet' is the snippet id of the warning, '' if everything is in order
     */
    public function versionState(): array
    {
        $version = $this->getVersion();
        if ($version === null) {
            return ['version' => null, 'outdated' => true, 'snippet' => 'proc.magick.error_binary_missing'];
        }
        if (version_compare($version, self::MIN_VERSION, '<')) {
            return ['version' => $version, 'outdated' => true, 'snippet' => 'proc.magick.warning_version_old'];
        }
        return ['version' => $version, 'outdated' => false, 'snippet' => ''];
    }
}
