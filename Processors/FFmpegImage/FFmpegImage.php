<?php
/**
 * Media processor FFmpegImage
 *
 * Creates a still image (thumbnail) from a video with the command-line tool
 * ffmpeg. The source is therefore a video, the result is an image.
 *
 * In contrast to FFmpegVideo, only ONE single image is decoded here, which
 * takes only seconds even for long films.
 *
 * Nevertheless there is a BACKGROUND PROCESS here too (see field
 * details.background_process): if the source itself comes from a
 * background process, trigger_ff.php starts this converter as soon as the
 * rendered video is available. The preview image follows the same path, so that the
 * cron job can handle both steps in the same way.
 *
 * The process registers with the same type identifier as FFmpegVideo in the
 * table `bgproc` and writes its progress via "-progress" into a
 * temporary file under paths.tmp_dir. For a still image, `bgproc`.`message`
 * simply holds a single image to be calculated.
 *
 * The source file must exist when called, in the foreground as well as in the
 * background: the target dimensions are calculated from its size.
 *
 * Command line (expert mode):
 *     ffmpeg [generated input options] [parameter1] -i SOURCE
 *            [generated output options] [parameter2] TARGET
 * parameter1 are therefore input options, parameter2 output options.
 *
 * Requirements:
 *   - configuration paths.ffmpeg  (path to the ffmpeg binary)
 *   - configuration paths.ffprobe (optional, otherwise it is searched for next to ffmpeg)
 *   - configuration paths.tmp_dir (writable directory for the progress files)
 *   - MediaProcessor provides $this->bgproc (Camindo\Core\BackgroundProcess).
 */
namespace Camindo\Core\Processors\FFmpegImage;

use Camindo\Core\MediaProcessor;
use Camindo\Core\BackgroundProcess;

class FFmpegImage extends MediaProcessor {

    /** Time limits in seconds */
    protected const TIMEOUT_PROBE   = 30;
    protected const TIMEOUT_VERSION = 10;

    /** Default for details.max_time_out if the field is empty */
    protected const DEFAULT_TIMEOUT = 120;

    /**
     * Type identifier in the table `bgproc`, which trigger_ff.php searches for.
     * Deliberately the same as in FFmpegVideo: it is the same cron job that reads the
     * progress file and updates the record.
     */
    public const BGPROC_TYPE = 'ffmpeg';

    /** Prefix and extension of the temporary progress file */
    public const PROGRESS_PREFIX    = 'ffmpeg-';
    public const PROGRESS_EXTENSION = '.progress';

    /**
     * Target format: option value => [encoder, muxer for "-f", MIME type, color model]
     *
     * The muxer is set explicitly via "-f" so that the file extension is not
     * misinterpreted. The color model belongs to the respective encoder:
     * mjpeg expects full range (yuvj420p), png works with
     * real RGB values, libwebp with yuv420p.
     */
    public const FORMATS = [
        'jpg'  => ['mjpeg',   'image2', 'image/jpeg', 'yuvj420p'],
        'png'  => ['png',     'image2', 'image/png',  'rgb24'],
        'webp' => ['libwebp', 'webp',   'image/webp', 'yuv420p'],
    ];

    /**
     * Method for selecting the image:
     *   'fixed'   fixed position in the film (details.frame_time)
     *   'percent' share of the runtime (details.frame_percent)
     *   'smart'   ffmpeg picks the most meaningful one from several images
     *             (filter "thumbnail", see details.scan_frames)
     */
    public const FRAME_MODES = ['fixed', 'percent', 'smart'];

    /** Deinterlacing: option value => filter, '' = do not deinterlace */
    public const DEINTERLACE = [
        'no'     => '',
        'auto'   => 'yadif=deint=interlaced',
        'always' => 'yadif',
    ];

    /**
     * Color models with halved color channels. For them the edge lengths must
     * be even, otherwise the encoders reject the image. For rgb24 (png)
     * the requested size may, however, be kept exactly.
     */
    protected const SUBSAMPLED = ['yuvj420p', 'yuv420p', 'yuvj422p', 'yuv422p', 'nv12'];

    /**
     * Sharpening after downscaling. When a video frame is strongly scaled down,
     * edges are lost; the values correspond to moderate sharpening
     * that is not visibly overdone.
     */
    protected const SHARPEN_FILTER = 'unsharp=5:5:0.8:3:3:0.4';

    /** Limits for details.scan_frames in mode 'smart' */
    protected const SCAN_MIN =   2;
    protected const SCAN_MAX = 500;

    /**
     * Safety margin to the end of the file in seconds.
     * A "-ss" after the last image produces empty output.
     */
    protected const SEEK_MARGIN = 0.2;

    /**
     * Option groups for the "intelligent merge" (expert mode).
     *
     * If the administrator sets an option from a group in parameter1 / parameter2, the converter
     * no longer generates the corresponding own parameters. The hand-written parameter therefore
     * always wins, but only for "its" area. All other form values remain effective.
     *
     * An appended stream index is removed beforehand ("-c:v:0" -> "c:v"),
     * the entries are exact matches afterwards. "-c" (applies to all streams) is
     * deliberately listed for both video_codec and audio.
     */
    protected const OPTION_GROUPS = [
        // Target format                -> replaces "-f"
        'format'        => ['f'],
        // Encoder                      -> replaces format (encoder part)
        'video_codec'   => ['c', 'c:v', 'codec', 'codec:v', 'vcodec'],
        // Video filter chain           -> replaces width, height, crop,
        //                                 prevent_upscaling, deinterlace,
        //                                 sharpen and the selection in mode 'smart'
        'video_filter'  => ['vf', 'filter', 'filter:v', 'filter_complex', 'lavfi'],
        // Image quality                -> replaces quality
        'video_quality' => ['q:v', 'qscale:v', 'qscale', 'q', 'crf', 'cq',
                            'quality', 'compression_level', 'lossless', 'b:v'],
        // Color model                  -> replaces the color model of the target format
        'pix_fmt'       => ['pix_fmt'],
        // Position in the film         -> replaces frame_mode, frame_time, frame_percent
        'seek'          => ['ss', 'sseof', 't', 'to', 'itsoffset'],
        // Number of images             -> replaces "-frames:v 1"
        'frames'        => ['frames:v', 'vframes', 'frames', 'update',
                            'r', 'fps_mode', 'vsync'],
        // Drehung                      -> ersetzt auto_rotation
        'orient'        => ['autorotate', 'noautorotate', 'display_rotation'],
        // Metadaten                    -> ersetzt strip_meta
        'metadata'      => ['map_metadata', 'map_chapters', 'metadata'],
        // Tonspur                      -> ersetzt "-an"
        'audio'         => ['c', 'c:a', 'codec', 'codec:a', 'acodec', 'an'],
        // Threads                      -> ersetzt threads
        'threads'       => ['threads', 'filter_threads', 'filter_complex_threads'],
        // Overwrite                    -> replaces "-y"
        'overwrite'     => ['y', 'n'],
    ];

    // Backend
    // ====================

    /**
     * Table structure for the converter.
     * An input field can be created from it with $this->backend->formField()
     *
     * type:
     *     integer -> BIGINT
     *     chars -> VARCHAR(255)
     *     bit -> BIT(1)
     * display_type:
     *     text:   simple input field
     *     select: selection, options are best generated dynamically
     *     label:  multilingual snippet #proc.{processorname}.{snippetname}
     *     prefix,
     *     suffix: appears before / after the input field
     *     pattern: JavaScript pattern for input validation
     *     min, max: minimum / maximum value, for text the number of characters
     */
    protected function hookDefinition(): void
    {
        // Default values for input fields of the converter.
        // The source is a video, even if the result is an image.
        // Which formats are actually available depends on which
        // libraries ffmpeg was built with ("ffmpeg -formats").
        $this->defaults['media_types_incl'] = implode("\n", [
            "video/mp4",
            "video/quicktime",
            "video/x-matroska",
            "video/webm",
            "video/x-msvideo",
            "video/x-ms-wmv",
            "video/mpeg",
            "video/3gpp",
            "video/x-flv",
            "video/ogg",
            "video/mp2t",
        ]);

        $this->defaults['extension'] = 'jpg';

        // Additional input fields for the converter
        $this->schema = [
            // Output format
            // --------------------
            'details.format' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegimage.field_format',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_format',
                'display_type' => 'select',
                'default' => 'jpg',
                'max' => 10
            ],
            'details.quality' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegimage.field_quality',
                'note' => '#proc.ffmpegimage.note_quality',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_quality',
                'default' => '80',
                'min' => 0,
                'max' => 100,
                'suffix' => '%'
            ],
            // Image selection
            // --------------------
            'details.frame_mode' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegimage.field_frame_mode',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_frame_mode',
                'display_type' => 'select',
                'default' => 'percent',
                'max' => 10
            ],
            'details.frame_time' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegimage.field_frame_time',
                'note' => '#proc.ffmpegimage.note_frame_time',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_frame_time',
                'default' => '',
                // seconds (12.5) or [HH:]MM:SS[.mmm]
                'pattern' => '(\d+(\.\d+)?|(\d+:)?[0-5]?\d:[0-5]?\d(\.\d+)?)',
                'max' => 20
            ],
            'details.frame_percent' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegimage.field_frame_percent',
                'note' => '#proc.ffmpegimage.note_frame_percent',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_frame_percent',
                'default' => '10',
                'min' => 0,
                'max' => 100,
                'suffix' => '%'
            ],
            'details.scan_frames' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegimage.field_scan_frames',
                'note' => '#proc.ffmpegimage.note_scan_frames',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_scan_frames',
                'default' => '100',
                'min' => self::SCAN_MIN,
                'max' => self::SCAN_MAX
            ],
            // Dimensions
            // --------------------
            'details.width' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegimage.field_width',
                'default' => '480',
                'min' => 0,
                'suffix' => 'px'
            ],
            'details.height' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegimage.field_height',
                'default' => '',
                'min' => 0,
                'suffix' => 'px'
            ],
            'details.crop' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegimage.field_crop',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_crop',
                'display_type' => 'switch',
                'default' => 0
            ],
            'details.prevent_upscaling' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegimage.field_prevent_upscaling',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_prevent_upscaling',
                'display_type' => 'switch',
                'default' => 1
            ],
            // Image editing
            // --------------------
            'details.deinterlace' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegimage.field_deinterlace',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_deinterlace',
                'display_type' => 'select',
                'default' => 'auto',
                'max' => 10
            ],
            'details.auto_rotation' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegimage.field_auto_rotation',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_auto_rotation',
                'display_type' => 'switch',
                'default' => 1
            ],
            'details.sharpen' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegimage.field_sharpen',
                'note' => '#proc.ffmpegimage.note_sharpen',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_sharpen',
                'display_type' => 'switch',
                'default' => 0
            ],
            'details.strip_meta' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegimage.field_strip_meta',
                'note' => '#proc.ffmpegimage.note_strip_meta',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_strip_meta',
                'display_type' => 'switch',
                'default' => 1
            ],
            // Execution
            // --------------------
            'details.threads' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegimage.field_threads',
                'note' => '#proc.ffmpegimage.note_threads',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_threads',
                'default' => '',
                'min' => 0,
                'max' => 64
            ],
            'details.background_process' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegimage.field_background_process',
                'note' => '#proc.ffmpegimage.note_background_process',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_background_process',
                'display_type' => 'switch',
                'default' => 1
            ],
            'details.max_time_out' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegimage.field_max_time_out',
                'note' => '#proc.ffmpegimage.note_max_time_out',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_max_time_out',
                'default' => (string)self::DEFAULT_TIMEOUT,
                'min' => 0,
                'suffix' => 's'
            ],
            // Expert mode
            // --------------------
            // ffmpeg [parameter1] -i {source file} [parameter2] {target file}
            'details.parameter1' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegimage.field_parameter1',
                'note' => '#proc.ffmpegimage.note_parameter1',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_parameter1',
                'default' => '',
                'max' => 255
            ],
            'details.parameter2' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegimage.field_parameter2',
                'note' => '#proc.ffmpegimage.note_parameter2',
                'tooltip' => '#proc.ffmpegimage.tooltip_field_parameter2',
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
    }

    /**
     * before saving converter (check data)
     */
    public function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        $details = (array)($data['details'] ?? []);

        // Adjust selection fields to valid values, so that later in
        // convert() no unknown keys arrive.
        $details['format']      = $this->pick($details['format']      ?? '', array_keys(self::FORMATS),      'jpg');
        $details['frame_mode']  = $this->pick($details['frame_mode']  ?? '', self::FRAME_MODES,              'percent');
        $details['deinterlace'] = $this->pick($details['deinterlace'] ?? '', array_keys(self::DEINTERLACE),  'auto');

        // Normalize time values, invalid input is discarded
        $details['frame_time'] = $this->normalizeTime((string)($details['frame_time'] ?? ''));

        // Limit value ranges
        $details['quality']       = max(0, min(100, (int)($details['quality'] ?? 80)));
        $details['frame_percent'] = max(0, min(100, (int)($details['frame_percent'] ?? 10)));

        $scan = (int)($details['scan_frames'] ?? 0);
        $details['scan_frames'] = $scan > 0
            ? max(self::SCAN_MIN, min(self::SCAN_MAX, $scan))
            : 100;

        // Without a time limit, a hanging ffmpeg could use computing time indefinitely
        $timeout = (int)($details['max_time_out'] ?? 0);
        $details['max_time_out'] = $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;

        // Expert mode: remove line breaks and multiple spaces,
        // so that the stored command line always stays on one line.
        foreach (['parameter1', 'parameter2'] as $key) {
            $value = trim((string)($details[$key] ?? ''));
            $details[$key] = (string)preg_replace('/\s+/', ' ', $value);
        }

        $data['details'] = $details;

        $version = $this->getVersion();
        if ($version === null) {
            throw new \Exception($this->cms->snippet('proc.ffmpegimage.error_binary_missing'));
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
     *      'filename'  => $fileOriginName,  // original file name, sanitized against injections
     *      'field_id'  => $fieldId,         // for file path / `data_fil` entry at check-in
     * @param string $destPath  path to the target file, relative to media_dir
     * @param array  $details   configuration array
     * @param bool $forceBgproc true: forces execution as a background process
     * @return array            data of the target file, or ['size'=>0, 'errors'=>'...']
     *
     * In background operation the target file is NOT finished yet on return.
     * The return value then contains 'size' => 0 and additionally
     *     'pending' => true, 'bgproc_id' => int, 'pid' => int, 'token' => string
     * In this case the caller should mark the record as "in progress"
     * and add the final values once the process has ended.
     */
    public function convert(string $srcPath, array $srcData, string $destPath, array $details, bool $forceBgproc): array
    {
        $mediaDir = $this->config->get('paths.media_dir');
        $srcFile  = $mediaDir . DIRECTORY_SEPARATOR . ltrim($srcPath, '/\\');
        $destFile = $mediaDir . DIRECTORY_SEPARATOR . ltrim($destPath, '/\\');
        $ext      = strtolower(pathinfo($destPath, PATHINFO_EXTENSION));

        $fileData = [
            'width'     => 0,
            'height'    => 0,
            'mime_type' => '',
            'size'      => 0,
            'filename'  => '',
            'extension' => '',
        ];

        // ffmpeg must be available
        $ffmpeg = (string)($this->config->get('paths.ffmpeg') ?? '');
        if ($ffmpeg === '' || !is_executable($ffmpeg)) {
            return $fileData + ['errors' => 'proc.ffmpegimage.error_binary_missing'];
        }

        $ffprobe = $this->ffprobePath($ffmpeg);
        if ($ffprobe === '') {
            return $fileData + ['errors' => 'proc.ffmpegimage.error_probe_missing'];
        }

        $background = !empty($details['background_process']) || $forceBgproc;

        // Check whether the source file exists and is readable.
        // Even in background operation it must already be there: a
        // converter whose source is itself still being rendered is only started by
        // trigger_ff.php after that process has ended.
        if (!file_exists($srcFile) || !is_readable($srcFile)) {
            return $fileData + ['errors' => 'error_file_not_found'];
        }

        // Determine properties of the source. They are needed for the target dimensions
        // and for the position from which the image is taken.
        $srcInfo = $this->probe($ffprobe, $srcFile);
        if ($srcInfo === null || $srcInfo['width'] <= 0 || $srcInfo['height'] <= 0) {
            // (no readable format: writeThumbnails() tries the next converter)
            return $fileData + ['errors' => 'proc.ffmpegimage.error_file_dimensions', 'unsupported' => true];
        }

        // Expert mode: split and evaluate free parameters
        $params1 = $this->execute->tokenizeParameters((string)($details['parameter1'] ?? ''));
        $params2 = $this->execute->tokenizeParameters((string)($details['parameter2'] ?? ''));
        $tokens  = array_merge($params1, $params2);
        $claimed = $this->claimedGroups($tokens);
        // Not only WHETHER the encoder was set, but also WHICH one:
        // the quality setting and the color model depend on it.
        $claimedCodec = $this->claimedCodecValue($tokens, self::OPTION_GROUPS['video_codec']);

        $format  = $this->pick((string)($details['format'] ?? ''), array_keys(self::FORMATS), 'jpg');
        $timeout = (int)($details['max_time_out'] ?? 0);
        if ($timeout <= 0) $timeout = self::DEFAULT_TIMEOUT;

        // Position in the film from which the image is taken
        $seek = $this->seekTime($details, $srcInfo);

        // Parameters generated from the form values
        $inputArgs  = $this->buildInputArguments($details, $claimed, $seek);
        $outputArgs = $this->buildOutputArguments($details, $srcInfo, $format, $claimed, $claimedCodec);

        // Create target directory (ffmpeg does not do this by itself)
        $destDir = dirname($destFile);
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);

        // Progress file for the background process.
        $progressArgs = [];
        if ($background) {
            // Token is passed on into the background process!
            $token  = $this->crypt->randomKey(16);
            $tmpDir = (string)($this->config->get('paths.tmp_dir') ?? '');
            if ($tmpDir === '' || !is_dir($tmpDir) || !is_writable($tmpDir)) {
                return $fileData + ['errors' => 'proc.ffmpegimage.error_tmp_dir'];
            }
            $progressArgs = [
                '-progress',
                $tmpDir . DIRECTORY_SEPARATOR . self::PROGRESS_PREFIX . $token . self::PROGRESS_EXTENSION,
                '-nostats',
            ];
        }

        // ffmpeg [generated input options] [parameter1] -i SOURCE
        //        [generated output options] [parameter2] TARGET
        $command = array_merge(
            [$ffmpeg, '-hide_banner', '-loglevel', 'error'],
            $progressArgs,
            $inputArgs,
            $params1,
            ['-i', $srcFile],
            $outputArgs,
            $params2,
            [$destFile]
        );

        if ($background) {
            // Data for trigger_ff.php, same structure as in FFmpegVideo.
            // 'frames'  : a still image is exactly one image
            // 'dur'     : runtime of the source
            // 'timeout' : entry from details.max_time_out
            // 'dest'    : path to the file from the media directory
            // ('proc' is added by startBackgroundProcess() itself)
            $message = [
                'frames'  => '1',
                'dur'     => (string)round((float)$srcInfo['duration'], 2),
                'timeout' => (string)$timeout,
                'dest'    => $destPath,
            ];
            $error   = '';
            $started = $this->startBackgroundProcess($command, $token, $message, error:$error);
            if ($started === null) {
                return $fileData + [
                    'errors' => 'proc.ffmpegimage.error_background_start',
                    'error_details' => $error,
                ];
            }

            return array_merge($fileData, [
                'width' => 0,
                'height' => 0,
                'size' => 0,
                'mime_type'  => self::FORMATS[$format][2] ?? '',
                'filename'   => $srcData['filename'] ?? 'file',
                'extension'  => $ext,
                'data' => '{}',
                // Info on the source file, as far as it is already known
                'source_width'  => $srcInfo['width'],
                'source_height' => $srcInfo['height'],
                'source_data' => json_encode($srcData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?: '{}',
                // Info on the background process
                'token'      => (string)$started['token'],
                'bgproc_id'  => (int)$started['id'],
                'pid'        => (int)$started['pid']
            ]);
        }

        $exitCode = $this->execute->execute($command, $stdout, $stderr, $timeout);

        // Check whether saving was successful
        if ($exitCode !== 0 || !is_file($destFile) || filesize($destFile) === 0) {
            // Do not leave a started or empty target file behind
            if (is_file($destFile)) @unlink($destFile);

            // If ffmpeg runs through without error but writes no file, then
            // the chosen position was behind the last image, or the source
            // contains no decodable image at that position.
            if ($exitCode === $this->execute::EXEC_TIMEOUT) {
                $error = 'proc.ffmpegimage.error_timeout';
            } elseif ($exitCode === 0) {
                $error = 'proc.ffmpegimage.error_no_frame';
            } else {
                $error = 'error_file_conversion';
            }

            return $fileData + [
                'errors' => $error,
                // Plain-text message from ffmpeg, helpful for troubleshooting
                'error_details' => trim((string)$stderr),
            ];
        }

        // Read the finished target file and build the return structure.
        // The dimensions are not calculated, because the free parameters
        // can change the result arbitrarily.
        $destInfo = $this->probe($ffprobe, $destFile);

        // same file name
        $destFilename = $srcData['filename'] ?? 'file';
        return [
            'width'         => $destInfo['width'] ?? 0,
            'height'        => $destInfo['height'] ?? 0,
            'size'          => filesize($destFile),
            'mime_type'     => self::FORMATS[$format][2] ?? ($destInfo['mime_type'] ?? ''),
            'filename'      => $destFilename,
            'extension'     => $ext,
            // Record the position in the film from which the image was taken.
            // If the administrator set the position themselves in expert mode,
            // the processor does not know it and therefore records nothing.
            'data'          => json_encode(
                                    ['frame_at' => $claimed['seek'] ? '' : ($seek === '' ? '0' : $seek)]
                                        + (array)$destInfo,
                                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                               ) ?: '{}',
            // Additional info on the source file was determined:
            'source_width'  => $srcInfo['width'],
            'source_height' => $srcInfo['height'],
            'source_data'   => json_encode($srcInfo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?: '{}',
        ];
    }

    // Parameter
    // ====================

    /**
     * Creates the options that must come BEFORE "-i" (input options).
     *
     * @param array  $details configuration array
     * @param array  $claimed result of claimedGroups()
     * @param string $seek    position in the film in seconds, '' = from the start
     * @return array          argument list
     */
    protected function buildInputArguments(array $details, array $claimed, string $seek): array
    {
        $args = [];

        // Overwrite an existing target file, otherwise ffmpeg waits for input
        if (!$claimed['overwrite']) $args[] = '-y';

        // Apply rotation from the metadata. ffmpeg does this by itself,
        // it can only be switched off explicitly.
        if (!$claimed['orient'] && empty($details['auto_rotation'])) {
            $args[] = '-noautorotate';
        }

        // Start time as input option: ffmpeg then jumps directly to the position,
        // instead of decoding the material up to that point. For a single image
        // from a long film this makes the difference between seconds
        // and minutes.
        if (!$claimed['seek'] && $seek !== '') {
            $args[] = '-ss';
            $args[] = $seek;
        }

        return $args;
    }

    /**
     * Creates the options that come AFTER "-i" and before the target file (output options).
     * Option groups already set by the administrator are skipped.
     *
     * @param array  $details      configuration array
     * @param array  $srcInfo      result of probe() for the source file
     * @param string $format       target format, key from FORMATS
     * @param array  $claimed      result of claimedGroups()
     * @param string $claimedCodec result of claimedCodecValue(), '' if the
     *                             administrator did not set the encoder themselves
     * @return array               argument list
     */
    protected function buildOutputArguments(array $details, array $srcInfo, string $format, array $claimed, string $claimedCodec = ''): array
    {
        $args = [];

        [$encoder, $muxer, , $pixFmt] = self::FORMATS[$format];

        // If the administrator set the encoder themselves in expert mode,
        // theirs applies. Quality setting and color model must match THIS
        // encoder: "-compression_level" is unknown to mjpeg, and a
        // forced yuvj420p turns a png target into a grayscale puzzle.
        // For an unknown encoder the processor therefore prefers to
        // give no setting at all rather than one that ffmpeg rejects.
        $knownEncoder = true;
        if ($claimedCodec !== '') {
            $encoder      = $claimedCodec;
            $knownEncoder = $this->encoderPixFmt($encoder) !== '';
            if ($knownEncoder) $pixFmt = $this->encoderPixFmt($encoder);
        }

        // ---- Encoder ----
        if (!$claimed['video_codec']) {
            $args[] = '-c:v';
            $args[] = $encoder;
        }

        // ---- Video filter (image selection, deinterlace, scaling, cropping, sharpening) ----
        if (!$claimed['video_filter']) {
            // Even edge lengths are only needed with halved color channels
            $even    = in_array($pixFmt, self::SUBSAMPLED, true);
            $filters = $this->buildFilterChain($details, $srcInfo, $claimed, $even);
            if ($filters !== []) {
                $args[] = '-vf';
                $args[] = implode(',', $filters);
            }
        }

        // ---- Write exactly one image ----
        // Without "-update 1" the image2 muxer complains that there is no counter
        // in the file name as soon as more than one image could arrive.
        if (!$claimed['frames']) {
            $args[] = '-frames:v';
            $args[] = '1';
            if ($muxer === 'image2') {
                $args[] = '-update';
                $args[] = '1';
            }
        }

        // ---- Quality ----
        if ($knownEncoder && !$claimed['video_quality']) {
            $args = array_merge($args, $this->qualityArguments($encoder, (int)($details['quality'] ?? 80)));
        }

        // ---- Color model ----
        if ($knownEncoder && !$claimed['pix_fmt']) {
            $args[] = '-pix_fmt';
            $args[] = $pixFmt;
        }

        // ---- Remove metadata. Videos from mobile phones regularly contain
        //      geodata that does not belong on the web. ----
        if (!$claimed['metadata'] && !empty($details['strip_meta'])) {
            $args[] = '-map_metadata';
            $args[] = '-1';
        }

        // ---- Omit audio: a still image has no audio track ----
        if (!$claimed['audio']) {
            $args[] = '-an';
        }

        // ---- Threads ----
        if (!$claimed['threads']) {
            $threads = (int)($details['threads'] ?? 0);
            if ($threads > 0) {
                $args[] = '-threads';
                $args[] = (string)$threads;
            }
        }

        // ---- Set the target format explicitly so that the extension is not
        //      misinterpreted ----
        if (!$claimed['format']) {
            $args[] = '-f';
            $args[] = $muxer;
        }

        return $args;
    }

    /**
     * Builds the video filter chain.
     *
     * Order: first the selection of the image, then deinterlacing, then the
     * dimensions, finally sharpening. Sharpening is therefore applied after
     * downscaling, otherwise the scaling would cancel the effect again.
     *
     * The target dimensions are calculated here in PHP and not left to ffmpeg:
     * the dimensions of the source are known through probe(), and
     * calculated values are considerably easier to follow than
     * nested scale expressions.
     *
     * @param bool $even force even edge lengths (yuv color models)
     * @return array list of individual filters, e.g. ['thumbnail=100', 'scale=480:270']
     */
    protected function buildFilterChain(array $details, array $srcInfo, array $claimed, bool $even): array
    {
        $filters = [];

        // ---- Image selection ----
        // The filter "thumbnail" buffers a number of images and returns the
        // most meaningful one. It stands at the very front of the chain, so that only
        // the selected image has to be scaled and sharpened.
        $mode = $this->pick((string)($details['frame_mode'] ?? ''), self::FRAME_MODES, 'percent');
        if ($mode === 'smart' && !$claimed['frames']) {
            $scan = (int)($details['scan_frames'] ?? 100);
            $scan = max(self::SCAN_MIN, min(self::SCAN_MAX, $scan > 0 ? $scan : 100));
            $filters[] = 'thumbnail=' . $scan;
        }

        // ---- Deinterlacing ----
        $deinterlace = $this->pick((string)($details['deinterlace'] ?? ''), array_keys(self::DEINTERLACE), 'auto');
        if (self::DEINTERLACE[$deinterlace] !== '') $filters[] = self::DEINTERLACE[$deinterlace];

        // ---- Dimensions ----
        // With a 90/270 degree rotation, auto-rotation swaps width and height;
        // the filter chain sees the rotated image.
        $srcW = (int)$srcInfo['width'];
        $srcH = (int)$srcInfo['height'];
        if (!empty($details['auto_rotation']) && in_array(abs((int)$srcInfo['rotation']), [90, 270], true)) {
            [$srcW, $srcH] = [$srcH, $srcW];
        }

        $reqW = !empty($details['width'])  ? (int)$details['width']  : 0;
        $reqH = !empty($details['height']) ? (int)$details['height'] : 0;

        // Cropping only makes sense if both dimensions are specified.
        // If one is missing, it results from the aspect ratio of the source,
        // then fitting and filling are identical.
        $isCrop = !empty($details['crop']) && $reqW > 0 && $reqH > 0;

        $target = $this->targetSize($srcW, $srcH, $reqW, $reqH, $isCrop, !empty($details['prevent_upscaling']), $even);

        if ($target !== null) {
            if ($isCrop) {
                // CROP: first scale so that the box is completely covered,
                // then crop centered to the target format
                $filters[] = 'scale=' . $target['scale_w'] . ':' . $target['scale_h'];
                $filters[] = 'crop=' . $target['width'] . ':' . $target['height'];
            } else {
                // FIT: scale so that the image fits into the box
                $filters[] = 'scale=' . $target['width'] . ':' . $target['height'];
            }
        }

        // ---- Sharpen ----
        if (!empty($details['sharpen'])) $filters[] = self::SHARPEN_FILTER;

        return $filters;
    }

    /**
     * Determines the position in the film from which the image is taken.
     *
     * 'fixed'   fixed time value
     * 'percent' share of the runtime, e.g. 10% of a two-minute film = 12 s
     * 'smart'   the time value is the start of the examined section,
     *           the image is then selected by the filter "thumbnail"
     *
     * The value is always limited to the runtime: behind the last image
     * otherwise ffmpeg silently returns empty output.
     *
     * @return string seconds as string, '' = from the start
     */
    protected function seekTime(array $details, array $srcInfo): string
    {
        $mode     = $this->pick((string)($details['frame_mode'] ?? ''), self::FRAME_MODES, 'percent');
        $duration = (float)($srcInfo['duration'] ?? 0);
        $seconds  = 0.0;

        if ($mode === 'percent') {
            // Without a known runtime no share can be calculated,
            // then it stays at the start of the film.
            if ($duration <= 0) return '';
            $percent = max(0, min(100, (int)($details['frame_percent'] ?? 10)));
            $seconds = $duration * $percent / 100;
        } else {
            // 'fixed' and 'smart' use the same time value
            $time = $this->normalizeTime((string)($details['frame_time'] ?? ''));
            if ($time === '') return '';
            $seconds = $this->timeToSeconds($time);
        }

        if ($seconds <= 0) return '';

        // Safety margin to the end of the file
        if ($duration > 0) {
            $seconds = min($seconds, max(0.0, $duration - self::SEEK_MARGIN));
        }

        if ($seconds <= 0) return '';

        return rtrim(rtrim(sprintf('%.3f', $seconds), '0'), '.');
    }

    /**
     * Calculates the target dimensions.
     *
     * @param  int  $srcW              width of the source
     * @param  int  $srcH              height of the source
     * @param  int  $reqW              requested width, 0 = from the aspect ratio
     * @param  int  $reqH              requested height, 0 = from the aspect ratio
     * @param  bool $isCrop            fill the box instead of fitting
     * @param  bool $preventUpscaling  do not enlarge beyond the original size
     * @param  bool $even              force even edge lengths
     * @return array|null ['width'=>int,'height'=>int,'scale_w'=>int,'scale_h'=>int] or null
     */
    protected function targetSize(int $srcW, int $srcH, int $reqW, int $reqH, bool $isCrop, bool $preventUpscaling, bool $even): ?array
    {
        if ($srcW <= 0 || $srcH <= 0) return null;
        // No specification -> keep original size
        if ($reqW <= 0 && $reqH <= 0) return null;

        if ($reqW > 0 && $reqH > 0) {
            $width  = $reqW;
            $height = $reqH;
        } elseif ($reqW > 0) {
            $width  = $reqW;
            $height = (int)round($srcH * $reqW / $srcW);
        } else {
            $height = $reqH;
            $width  = (int)round($srcW * $reqH / $srcH);
        }

        if (!$isCrop && $reqW > 0 && $reqH > 0) {
            // Fit: the smaller of the two factors wins
            $factor = min($reqW / $srcW, $reqH / $srcH);
            if ($preventUpscaling) $factor = min($factor, 1.0);
            $width  = (int)round($srcW * $factor);
            $height = (int)round($srcH * $factor);
        } elseif ($preventUpscaling && ($width > $srcW || $height > $srcH)) {
            // Only downscale: limit to the original size
            $factor = min($srcW / max(1, $width), $srcH / max(1, $height), 1.0);
            $width  = (int)round($width * $factor);
            $height = (int)round($height * $factor);
        }

        $width  = $this->roundValue($width, $even);
        $height = $this->roundValue($height, $even);

        // When cropping, scaling must be done before the crop so that the
        // box is completely covered (the larger of the two factors).
        $scaleW = $width;
        $scaleH = $height;
        if ($isCrop) {
            $factor = max($width / $srcW, $height / $srcH);
            if ($preventUpscaling) $factor = min($factor, 1.0);
            $scaleW = $this->roundValue((int)ceil($srcW * $factor), $even);
            $scaleH = $this->roundValue((int)ceil($srcH * $factor), $even);
            // After limiting, the box can be larger than the scaled image
            $width  = min($width, $scaleW);
            $height = min($height, $scaleH);
        }

        return [
            'width'   => $width,
            'height'  => $height,
            'scale_w' => $scaleW,
            'scale_h' => $scaleH,
        ];
    }

    /**
     * Limit edge length.
     *
     * With halved color channels (yuv420p and related) the edge length must
     * be even, otherwise the encoders reject the image. For rgb24 (png)
     * the requested size may, however, be kept exactly.
     */
    protected function roundValue(int $value, bool $even): int
    {
        if (!$even) return max(1, $value);

        // Next smaller even number, at least 2
        $value = (int)(floor($value / 2) * 2);

        return max(2, $value);
    }

    /**
     * Quality parameters depending on the encoder.
     *
     * Externally the quality is uniformly a percentage, like in the
     * Magick processor: 100 = best quality. Each encoder calculates internally
     * but with its own scale, sometimes even in the reverse direction.
     *
     * @param int $quality quality in percent (0-100)
     */
    protected function qualityArguments(string $encoder, int $quality): array
    {
        $quality = max(0, min(100, $quality));

        return match ($encoder) {
            // mjpeg uses 2 (best) to 31 (worst)
            'mjpeg'   => ['-q:v', (string)max(2, min(31, (int)round(31 - $quality * 29 / 100)))],
            // png is lossless, the value only controls the computing effort
            // when packing: 0 = fast and large, 9 = slow and small
            'png'     => ['-compression_level', (string)max(0, min(9, (int)round(9 - $quality * 9 / 100)))],
            // libwebp uses the same scale as the display
            'libwebp' => ['-quality', (string)$quality],
            default   => [],
        };
    }

    /**
     * Matching color model for an encoder.
     * Needed when the administrator sets their own
     * encoder in expert mode.
     *
     * @return string color model or '' for unknown encoder
     */
    protected function encoderPixFmt(string $encoder): string
    {
        foreach (self::FORMATS as $format) {
            if ($format[0] === $encoder) return $format[3];
        }

        return '';
    }

    /**
     * Determines which option groups the administrator has set themselves.
     *
     * @param array $tokens arguments from parameter1 and parameter2
     * @return array        ['video_filter' => bool, 'video_quality' => bool, ...]
     */
    protected function claimedGroups(array $tokens): array
    {
        $claimed = array_fill_keys(array_keys(self::OPTION_GROUPS), false);

        foreach ($tokens as $token) {
            // Only consider options, not values
            if ($token === '' || $token[0] !== '-') continue;

            // reduce "-c:v:0" and "-frames:v:1" to the base form
            $option = strtolower(ltrim($token, '-'));
            $option = (string)preg_replace('/:\d+$/', '', $option);

            foreach (self::OPTION_GROUPS as $group => $options) {
                if (in_array($option, $options, true)) $claimed[$group] = true;
            }
        }

        return $claimed;
    }

    /**
     * Reads the encoder that the administrator set themselves in expert mode
     * e.g. 'libwebp' from "-c:v libwebp".
     *
     * claimedGroups() only answers the question WHETHER a group is set. For
     * the quality setting and the color model, that is not enough: both are in
     * their notation depends on the encoder. Without this value the
     * processor would still build its parameters for the one chosen in the form
     * encoder.
     *
     * @param array $tokens  arguments from parameter1 and parameter2
     * @param array $options option names of the codec group, see OPTION_GROUPS
     * @return string        encoder, '' if nothing was set
     */
    protected function claimedCodecValue(array $tokens, array $options): string
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // Only consider options, not values
            if ($token === '' || $token[0] !== '-') continue;

            // reduce "-c:v:0" to the base form
            $option = strtolower(ltrim($token, '-'));
            $option = (string)preg_replace('/:\d+$/', '', $option);

            if (!in_array($option, $options, true)) continue;

            // The next token is the encoder name, unless
            // the next option already starts there
            $value = $tokens[$i + 1] ?? '';
            if ($value !== '' && $value[0] !== '-') return strtolower($value);
        }

        return '';
    }

    /**
     * Public method to add the parameters of a thumbnail created by a background process
     * to table `data_fil`
     * 
     * @param string $srcPath   relative path to the file from the media directory
     * @return array            data determined via ffprobe + file size
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

        // ffmpeg must be available
        $ffmpeg = (string)($this->config->get('paths.ffmpeg') ?? '');
        if ($ffmpeg === '' || !is_executable($ffmpeg)) {
            return [
                'error' => 'proc.ffmpegimage.error_binary_missing'
            ];
        }

        $ffprobe = $this->ffprobePath($ffmpeg);
        if ($ffprobe === '') {
            return [
                'error' => 'proc.ffmpegimage.error_probe_missing'
            ];
        }

        // Determine properties of the file
        $srcInfo = $this->probe($ffprobe, $srcFile);
        if ($srcInfo === null || $srcInfo['width'] <= 0 || $srcInfo['height'] <= 0) {
            return [
                'error' => 'proc.ffmpegimage.error_file_dimensions'
            ];
        }

        // For a still image the "runtime" is meaningless, it comes from the
        // frame rate of the one image. It is therefore not passed on.
        unset($srcInfo['duration'], $srcInfo['frames']);

        $extension = strtolower(pathinfo($srcFile, PATHINFO_EXTENSION));
        $srcInfo['size']      = filesize($srcFile);
        $srcInfo['extension'] = $extension;
        // extension -> MIME type, so that the record gets the same value as
        // in the foreground run
        foreach (self::FORMATS as $key => $format) {
            if ($key === $extension || ($extension === 'jpeg' && $key === 'jpg')) {
                $srcInfo['mime_type'] = $format[2];
                break;
            }
        }
        
        return $srcInfo;
    }

    // Hilfsfunktionen
    // ====================

    /**
     * Reads the properties of a file with ffprobe.
     *
     * The function is used for the source (video) and for the result (image)
     * a still image is a video stream with one image for ffprobe.
     *
     * @return array|null ['width'=>int, 'height'=>int, 'rotation'=>int,
     *                     'duration'=>float, 'frames'=>int] or null on error
     */
    protected function probe(string $ffprobe, string $file): ?array
    {
        $exitCode = $this->execute->execute(
            [$ffprobe, '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $file],
            $stdout,
            $stderr,
            self::TIMEOUT_PROBE
        );
        if ($exitCode !== 0) return null;

        $data = json_decode(trim((string)$stdout), true);
        if (!is_array($data) || empty($data['streams'])) return null;

        $info = [
            'width'    => 0,
            'height'   => 0,
            'rotation' => 0,
            'duration' => 0.0,
            'frames'   => 0,
        ];

        foreach ($data['streams'] as $stream) {
            $type = $stream['codec_type'] ?? '';

            // The first video stream is the one sought, audio tracks are irrelevant here
            if ($type !== 'video' || $info['width'] > 0) continue;

            $info['width']  = (int)($stream['width'] ?? 0);
            $info['height'] = (int)($stream['height'] ?? 0);
            $info['frames'] = (int)($stream['nb_frames'] ?? 0);
            if (isset($stream['duration'])) {
                $info['duration'] = (float)$stream['duration'];
            }
            $info['rotation'] = $this->parseRotation($stream);
        }

        if ($info['duration'] <= 0 && isset($data['format']['duration'])) {
            $info['duration'] = (float)$data['format']['duration'];
        }

        return $info;
    }

    /**
     * Read the rotation from the stream information.
     * Depending on the ffprobe version it is in side_data_list or in tags.rotate.
     */
    protected function parseRotation(array $stream): int
    {
        $rotation = 0;

        foreach ((array)($stream['side_data_list'] ?? []) as $sideData) {
            if (isset($sideData['rotation'])) $rotation = (int)round((float)$sideData['rotation']);
        }
        if ($rotation === 0 && isset($stream['tags']['rotate'])) {
            $rotation = (int)$stream['tags']['rotate'];
        }

        return ((int)$rotation) % 360;
    }

    /**
     * Check and normalize a time value.
     * Allowed are seconds ("12.5") and [HH:]MM:SS[.mmm] ("00:01:30.5").
     *
     * @return string normalized value or '' for invalid input
     */
    protected function normalizeTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';

        if (preg_match('/^\d+(\.\d+)?$/', $value)) return $value;
        if (preg_match('/^(\d{1,3}:)?[0-5]?\d:[0-5]?\d(\.\d{1,3})?$/', $value)) return $value;

        return '';
    }

    /**
     * Convert time value to seconds
     */
    protected function timeToSeconds(string $value): float
    {
        if ($value === '') return 0.0;
        if (!str_contains($value, ':')) return (float)$value;

        $parts   = array_reverse(explode(':', $value));
        $seconds = 0.0;
        $factor  = 1.0;

        foreach ($parts as $part) {
            $seconds += ((float)$part) * $factor;
            $factor  *= 60.0;
        }

        return $seconds;
    }

    /**
     * Check value against a list of allowed values
     */
    protected function pick(string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * Determine the path to ffprobe: either explicitly configured or
     * next to ffmpeg in the same directory.
     *
     * @return string path or '' if not found
     */
    protected function ffprobePath(string $ffmpeg): string
    {
        $ffprobe = (string)($this->config->get('paths.ffprobe') ?? '');
        if ($ffprobe !== '' && is_executable($ffprobe)) return $ffprobe;

        $suffix  = str_ends_with(strtolower($ffmpeg), '.exe') ? '.exe' : '';
        $ffprobe = dirname($ffmpeg) . DIRECTORY_SEPARATOR . 'ffprobe' . $suffix;

        return is_executable($ffprobe) ? $ffprobe : '';
    }

    /**
     * Read the version of the configured ffmpeg
     *
     * @return string|null e.g. "6.1.1" or null if ffmpeg is not running
     */
    public function getVersion(): ?string
    {
        $ffmpeg = (string)($this->config->get('paths.ffmpeg') ?? '');
        if ($ffmpeg === '' || !is_executable($ffmpeg)) return null;

        $exitCode = $this->execute->execute([$ffmpeg, '-version'], $stdout, $stderr, self::TIMEOUT_VERSION);
        if ($exitCode !== 0) return null;

        if (!preg_match('/ffmpeg version (\S+)/i', (string)$stdout, $match)) return null;

        return $match[1];
    }
}
