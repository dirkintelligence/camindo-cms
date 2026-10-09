<?php
/**
 * Medien-Prozessor FFmpegVideo
 *
 * Creates video versions with the command-line tool ffmpeg.
 *
 * In contrast to Magick, video conversions typically take minutes to
 * hours. Therefore ffmpeg is started as a BACKGROUND PROCESS by default
 * (see field details.background_process) and reports its progress via
 * "-progress" into a temporary file under paths.tmp_dir.
 *
 * The cron job trigger_ff.php reads this file, compares the current frame
 * with the total number of frames (stored in `bgproc`.`message`) and enters the
 * progress in percent in `bgproc`.`progress`.
 *
 * Kommandozeile (Expertenmodus):
 *     ffmpeg [parameter1] -i SOURCE [generated parameters] [parameter2] TARGET
 * parameter1 are therefore input options, parameter2 output options.
 *
 * Voraussetzungen:
 *   - configuration paths.ffmpeg  (path to the ffmpeg binary)
 *   - configuration paths.ffprobe (optional, otherwise it is searched for next to ffmpeg)
 *   - configuration paths.tmp_dir (writable directory for the progress files)
 *   - MediaProcessor stellt $this->bgproc (Camindo\Core\BackgroundProcess) bereit.
 *     If the property is missing, the processor automatically falls back to the foreground.
 */
namespace Camindo\Core\Processors\FFmpegVideo;

use Camindo\Core\MediaProcessor;
use Camindo\Core\BackgroundProcess;

class FFmpegVideo extends MediaProcessor {

    /** Time limits in seconds */
    protected const TIMEOUT_PROBE   = 30;
    protected const TIMEOUT_VERSION = 10;

    /** Default for details.max_time_out if the field is empty */
    protected const DEFAULT_TIMEOUT = 3600;

    /** Type identifier in the table `bgproc`, which trigger_ff.php searches for */
    public const BGPROC_TYPE = 'ffmpeg';

    /** Prefix and extension of the temporary progress file */
    public const PROGRESS_PREFIX    = 'ffmpeg-';
    public const PROGRESS_EXTENSION = '.progress';

    /**
     * Target container: option value => [ffmpeg format name, MIME type]
     * The format name is set explicitly via "-f" so that the file extension
     * is not misinterpreted.
     */
    public const CONTAINERS = [
        'mp4'  => ['mp4',      'video/mp4'],
        'webm' => ['webm',     'video/webm'],
        'mkv'  => ['matroska', 'video/x-matroska'],
    ];

    /**
     * Video codec: option value => ffmpeg encoder
     * 'copy' copies the video stream unchanged, '' means "no video" (-vn).
     */
    public const VIDEO_CODECS = [
        'h264' => 'libx264',
        'h265' => 'libx265',
        'vp9'  => 'libvpx-vp9',
        'av1'  => 'libsvtav1',
        'copy' => 'copy',
        'none' => '',
    ];

    /**
     * Upper limit of the CRF value per encoder.
     * x264/x265 work with 0-51, VP9 and SVT-AV1 with 0-63.
     */
    protected const CRF_MAX = [
        'libx264'    => 51,
        'libx265'    => 51,
        'libvpx-vp9' => 63,
        'libsvtav1'  => 63,
    ];

    /**
     * Speed/quality presets.
     * x264 and x265 know these names directly, VP9 and SVT-AV1 need numbers
     * (see presetArguments()). Order = order in the select field.
     */
    public const PRESETS = [
        'ultrafast', 'superfast', 'veryfast', 'faster', 'fast',
        'medium', 'slow', 'slower', 'veryslow',
    ];

    /** Conversion of the presets to "-cpu-used" of libvpx-vp9 (0 = slow/good) */
    protected const PRESET_VP9 = [
        'ultrafast' => 8, 'superfast' => 7, 'veryfast' => 6, 'faster' => 5,
        'fast' => 4, 'medium' => 3, 'slow' => 2, 'slower' => 1, 'veryslow' => 0,
    ];

    /** Conversion of the presets to "-preset" of libsvtav1 (0 = slow/good, 13 = fast) */
    protected const PRESET_AV1 = [
        'ultrafast' => 12, 'superfast' => 11, 'veryfast' => 10, 'faster' => 9,
        'fast' => 8, 'medium' => 6, 'slow' => 5, 'slower' => 4, 'veryslow' => 2,
    ];

    /**
     * Audio codec: option value => ffmpeg encoder
     * 'copy' copies the audio stream, '' means "mute" (-an).
     */
    public const AUDIO_CODECS = [
        'aac'  => 'aac',
        'opus' => 'libopus',
        'mp3'  => 'libmp3lame',
        'copy' => 'copy',
        'mute' => '',
    ];

    /** Channel count: option value => argument for "-ac", '' = keep */
    public const AUDIO_CHANNELS = [
        'keep'   => '',
        'mono'   => '1',
        'stereo' => '2',
    ];

    /** Deinterlacing: option value => filter, '' = do not deinterlace */
    public const DEINTERLACE = [
        'no'     => '',
        'auto'   => 'yadif=deint=interlaced',
        'always' => 'yadif',
    ];

    /**
     * Option groups for the "intelligent merge" (expert mode).
     *
     * If the administrator sets an option from a group in parameter1 / parameter2, the
     * converter no longer generates the corresponding own parameters.
     * The hand-written parameter therefore always wins, but only for "its" area.
     * All other form values remain effective.
     *
     * An appended stream index is removed beforehand ("-c:v:0" -> "c:v"),
     * the entries are exact matches afterwards. "-c" (applies to all streams) is
     * deliberately listed for both video_codec and audio_codec.
     */
    protected const OPTION_GROUPS = [
        // Zielformat / Container       -> ersetzt "-f"
        'format'         => ['f'],
        // Video-Codec                  -> ersetzt codec
        'video_codec'    => ['c', 'c:v', 'codec', 'codec:v', 'vcodec', 'vn'],
        // Videofilterkette             -> ersetzt width, height, crop,
        //                                 prevent_upscaling, deinterlace, max_fps
        'video_filter'   => ['vf', 'filter', 'filter:v', 'filter_complex', 'lavfi'],
        // Image quality                -> replaces quality_mode, quality, bitrate
        'video_quality'  => ['crf', 'cq', 'q:v', 'qscale:v', 'qp',
                             'b:v', 'vb', 'maxrate', 'minrate', 'bufsize'],
        // Encoder-Geschwindigkeit      -> ersetzt preset
        'preset'         => ['preset', 'cpu-used', 'deadline', 'speed'],
        // Farbmodell                   -> ersetzt compatible_pix_mode
        'pix_fmt'        => ['pix_fmt'],
        // Frame rate                   -> replaces max_fps
        'framerate'      => ['r', 'fpsmax', 'framerate', 'vsync', 'fps_mode'],
        // Container-Feinheiten         -> ersetzt faststart
        'movflags'       => ['movflags'],
        // Drehung                      -> ersetzt auto_rotation
        'orient'         => ['autorotate', 'noautorotate', 'display_rotation'],
        // Metadaten                    -> ersetzt strip_meta
        'metadata'       => ['map_metadata', 'map_chapters', 'metadata'],
        // Zeitausschnitt               -> ersetzt start_time, duration
        'trim'           => ['ss', 'sseof', 't', 'to'],
        // Audio-Codec                  -> ersetzt audio_codec
        'audio_codec'    => ['c', 'c:a', 'codec', 'codec:a', 'acodec', 'an'],
        // Audio-Datenrate              -> ersetzt audio_bitrate
        'audio_bitrate'  => ['b:a', 'ab', 'q:a', 'qscale:a'],
        // Kanalzahl                    -> ersetzt audio_channels
        'audio_channels' => ['ac', 'channel_layout'],
        // Audiofilterkette             -> ersetzt audio_normalisation
        'audio_filter'   => ['af', 'filter:a'],
        // Threads                      -> ersetzt threads
        'threads'        => ['threads', 'filter_threads', 'filter_complex_threads'],
        // Overwrite                    -> replaces "-y"
        'overwrite'      => ['y', 'n'],
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
        // Default values for input fields of the converter.
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

        $this->defaults['extension'] = 'mp4';

        // Additional input fields for the converter
        $this->schema = [
            // Container and codec
            // --------------------
            'details.container' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegvideo.field_container',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_container',
                'display_type' => 'select',
                'default' => 'mp4',
                'max' => 10
            ],
            'details.codec' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegvideo.field_codec',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_codec',
                'display_type' => 'select',
                'default' => 'h264',
                'max' => 10
            ],
            // Abmessungen
            // --------------------
            'details.width' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegvideo.field_width',
                'default' => '1280',
                'min' => 0,
                'suffix' => 'px'
            ],
            'details.height' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegvideo.field_height',
                'default' => '',
                'min' => 0,
                'suffix' => 'px'
            ],
            'details.crop' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegvideo.field_crop',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_crop',
                'display_type' => 'switch',
                'default' => 0
            ],
            'details.prevent_upscaling' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegvideo.field_prevent_upscaling',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_prevent_upscaling',
                'display_type' => 'switch',
                'default' => 1
            ],
            // Quality
            // --------------------
            'details.quality_mode' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegvideo.field_quality_mode',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_quality_mode',
                'display_type' => 'select',
                'default' => 'crf',
                'max' => 10
            ],
            'details.quality' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegvideo.field_quality',
                'note' => '#proc.ffmpegvideo.note_quality',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_quality',
                'default' => '23',
                'min' => 0,
                'max' => 51
            ],
            'details.bitrate' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegvideo.field_bitrate',
                'note' => '#proc.ffmpegvideo.note_bitrate',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_bitrate',
                'default' => '',
                'min' => 0,
                'suffix' => 'kbit/s'
            ],
            'details.preset' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegvideo.field_preset',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_preset',
                'display_type' => 'select',
                'default' => 'medium',
                'max' => 20
            ],
            // Time and frame rate
            // --------------------
            'details.max_fps' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegvideo.field_max_fps',
                'note' => '#proc.ffmpegvideo.note_max_fps',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_max_fps',
                'default' => '',
                'min' => 0,
                'max' => 240,
                'suffix' => 'fps'
            ],
            'details.start_time' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegvideo.field_start_time',
                'note' => '#proc.ffmpegvideo.note_start_time',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_start_time',
                'default' => '',
                // seconds (12.5) or [HH:]MM:SS[.mmm]
                'pattern' => '(\d+(\.\d+)?|(\d+:)?[0-5]?\d:[0-5]?\d(\.\d+)?)',
                'max' => 20
            ],
            'details.duration' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegvideo.field_duration',
                'note' => '#proc.ffmpegvideo.note_duration',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_duration',
                'default' => '',
                'pattern' => '(\d+(\.\d+)?|(\d+:)?[0-5]?\d:[0-5]?\d(\.\d+)?)',
                'max' => 20
            ],
            // Compatibility
            // --------------------
            'details.faststart' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegvideo.field_faststart',
                'note' => '#proc.ffmpegvideo.note_faststart',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_faststart',
                'display_type' => 'switch',
                'default' => 1
            ],
            'details.compatible_pix_mode' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegvideo.field_compatible_pix_mode',
                'note' => '#proc.ffmpegvideo.note_compatible_pix_mode',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_compatible_pix_mode',
                'display_type' => 'switch',
                'default' => 1
            ],
            'details.deinterlace' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegvideo.field_deinterlace',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_deinterlace',
                'display_type' => 'select',
                'default' => 'auto',
                'max' => 10
            ],
            'details.auto_rotation' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegvideo.field_auto_rotation',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_auto_rotation',
                'display_type' => 'switch',
                'default' => 1
            ],
            'details.strip_meta' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegvideo.field_strip_meta',
                'note' => '#proc.ffmpegvideo.note_strip_meta',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_strip_meta',
                'display_type' => 'switch',
                'default' => 1
            ],
            // Ton
            // --------------------
            'details.audio_codec' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegvideo.field_audio_codec',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_audio_codec',
                'display_type' => 'select',
                'default' => 'aac',
                'max' => 10
            ],
            'details.audio_bitrate' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegvideo.field_audio_bitrate',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_audio_bitrate',
                'default' => '128',
                'min' => 0,
                'max' => 1024,
                'suffix' => 'kbit/s'
            ],
            'details.audio_channels' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegvideo.field_audio_channels',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_audio_channels',
                'display_type' => 'select',
                'default' => 'keep',
                'max' => 10
            ],
            'details.audio_normalisation' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegvideo.field_audio_normalisation',
                'note' => '#proc.ffmpegvideo.note_audio_normalisation',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_audio_normalisation',
                'display_type' => 'switch',
                'default' => 0
            ],
            // Execution
            // --------------------
            'details.threads' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegvideo.field_threads',
                'note' => '#proc.ffmpegvideo.note_threads',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_threads',
                'default' => '',
                'min' => 0,
                'max' => 64
            ],
            'details.background_process' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegvideo.field_background_process',
                'note' => '#proc.ffmpegvideo.note_background_process',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_background_process',
                'display_type' => 'switch',
                'default' => 1
            ],
            'details.max_time_out' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegvideo.field_max_time_out',
                'note' => '#proc.ffmpegvideo.note_max_time_out',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_max_time_out',
                'default' => (string)self::DEFAULT_TIMEOUT,
                'min' => 0,
                'suffix' => 's'
            ],
            // Expertenmodus
            // --------------------
            // ffmpeg [parameter1] -i {source file} [parameter2] {target file}
            'details.parameter1' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegvideo.field_parameter1',
                'note' => '#proc.ffmpegvideo.note_parameter1',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_parameter1',
                'default' => '',
                'max' => 255
            ],
            'details.parameter2' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegvideo.field_parameter2',
                'note' => '#proc.ffmpegvideo.note_parameter2',
                'tooltip' => '#proc.ffmpegvideo.tooltip_field_parameter2',
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
        $details['container']      = $this->pick($details['container']      ?? '', array_keys(self::CONTAINERS),      'mp4');
        $details['codec']          = $this->pick($details['codec']          ?? '', array_keys(self::VIDEO_CODECS),    'h264');
        $details['quality_mode']   = $this->pick($details['quality_mode']   ?? '', ['crf', 'bitrate'],                'crf');
        $details['preset']         = $this->pick($details['preset']         ?? '', self::PRESETS,                     'medium');
        $details['deinterlace']    = $this->pick($details['deinterlace']    ?? '', array_keys(self::DEINTERLACE),     'auto');
        $details['audio_codec']    = $this->pick($details['audio_codec']    ?? '', array_keys(self::AUDIO_CODECS),    'aac');
        $details['audio_channels'] = $this->pick($details['audio_channels'] ?? '', array_keys(self::AUDIO_CHANNELS),  'keep');

        // Normalize time values, invalid input is discarded
        $details['start_time'] = $this->normalizeTime((string)($details['start_time'] ?? ''));
        $details['duration']   = $this->normalizeTime((string)($details['duration'] ?? ''));

        // CRF and bit rate depend on the mode: the other value remains set,
        // but is not used. Only the CRF range is limited.
        $quality = (int)($details['quality'] ?? 23);
        $details['quality'] = max(0, min(63, $quality));

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
            throw new \Exception($this->cms->snippet('proc.ffmpegvideo.error_binary_missing'));
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
     * @param array  $details   Konfigurations-Array
     * @param bool $forceBgproc true: forces execution as a background process
     * @return array            data of the target file, or ['size'=>0, 'errors'=>'...']
     *
     * In background operation the target file is NOT finished yet on return.
     * The return value then contains 'size' => 0 and additionally
     *     'pending' => true, 'bgproc_id' => int, 'pid' => int, 'token' => string
     * In this case the caller should mark the record as "in progress"
     * and add the final values once the process has ended.
     */
    public function convert(string $srcPath, array $srcData, string $destPath, array $details, bool $forceBgproc = false): array
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
            return $fileData + ['errors' => 'proc.ffmpegvideo.error_binary_missing'];
        }
        
        $ffprobe = $this->ffprobePath($ffmpeg);
        if ($ffprobe === '') {
            return $fileData + ['errors' => 'proc.ffmpegvideo.error_probe_missing'];
        }

        // Check whether the source file exists and is readable
        if (!file_exists($srcFile) || !is_readable($srcFile)) {
            return $fileData + ['errors' => 'error_file_not_found'];
        }

        // Determine properties of the source. They are needed for the target dimensions,
        // the frame rate and the total number of frames.
        $srcInfo = $this->probe($ffprobe, $srcFile);
        if ($srcInfo === null || $srcInfo['width'] <= 0 || $srcInfo['height'] <= 0) {
            // (no readable format: writeThumbnails() tries the next converter)
            return $fileData + ['errors' => 'proc.ffmpegvideo.error_file_dimensions', 'unsupported' => true];
        }

        // Expert mode: split and evaluate free parameters
        $params1 = $this->execute->tokenizeParameters((string)($details['parameter1'] ?? ''));
        $params2 = $this->execute->tokenizeParameters((string)($details['parameter2'] ?? ''));
        $claimed = $this->claimedGroups(array_merge($params1, $params2));
        // Not only WHETHER the codec itself was set, but also WHICH one:
        // the quality level, preset and filter chain depend on it.
        $claimedCodec = $this->claimedCodecValue(array_merge($params1, $params2), self::OPTION_GROUPS['video_codec']);
        $claimedAudio = $this->claimedCodecValue(array_merge($params1, $params2), self::OPTION_GROUPS['audio_codec']);

        $container = $this->pick((string)($details['container'] ?? ''), array_keys(self::CONTAINERS), 'mp4');
        $timeout   = (int)($details['max_time_out'] ?? 0);
        if ($timeout <= 0) $timeout = self::DEFAULT_TIMEOUT;

        // Parameters generated from the form values
        $inputArgs  = $this->buildInputArguments($details, $claimed);
        $outputArgs = $this->buildOutputArguments($details, $srcInfo, $container, $claimed, $claimedCodec, $claimedAudio);

        // Create target directory (ffmpeg does not do this by itself)
        $destDir = dirname($destFile);
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);

        $background = !empty($details['background_process']) || $forceBgproc;

        // Progress file for the background process.
        $progressArgs = [];
        if ($background) {
            // Token is passed on into the background process!
            $token = $this->crypt->randomKey(16);
            $tmpDir = (string)($this->config->get('paths.tmp_dir') ?? '');
            if ($tmpDir === '' || !is_dir($tmpDir) || !is_writable($tmpDir)) {
                return $fileData + ['errors' => 'proc.ffmpegvideo.error_tmp_dir'];
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
            // Estimate the total number of frames and store it together with the time limit.
            // trigger_ff.php reads these values back from `message`.
            // 'frames'  : number of frames to be calculated
            // 'dur'     : runtime of the target file as fallback, if the frame count
            //             could not be determined (streams without nb_frames and without frame rate)
            // 'timeout' : entry from details.max_time_out
            // 'dest'    : path to the file from the media directory
            // ('proc' is added by startBackgroundProcess() itself)
            $message = [
                'frames'  => (string)$this->estimateFrames($details, $srcInfo),
                'dur'     => (string)round($this->estimateDuration($details, $srcInfo), 2),
                'timeout' => (string)$timeout,
                'dest'    => $destPath,
            ];
            $error   = '';
            $started = $this->startBackgroundProcess($command, $token, $message, error:$error);
            if ($started === null) {
                return $fileData + [
                    'errors' => 'proc.ffmpegvideo.error_background_start',
                    'error_details' => $error,
                ];
            }
            return array_merge($fileData, [
                'width' => 0,
                'height' => 0,
                'size' => 0,
                'mime_type'  => self::CONTAINERS[$container][1] ?? '',
                'filename'   => $srcData['filename'] ?? 'file',
                'extension'  => $ext,
                'data' => '{}',
                // Info on the source file
                'source_width'  => $srcInfo['width'],
                'source_height' => $srcInfo['height'],
                'source_data' => json_encode($srcData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?? '{}',
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

            return $fileData + [
                'errors' => $exitCode === $this->execute::EXEC_TIMEOUT
                    ? 'proc.ffmpegvideo.error_timeout'
                    : 'error_file_conversion',
                // Plain-text message from ffmpeg, helpful for troubleshooting
                'error_details' => trim((string)$stderr),
            ];
        }

        // Read the finished target file and build the return structure
        $destInfo = $this->probe($ffprobe, $destFile);
        
        // gleicher Dateiname
        $destFilename = $srcData['filename'] ?? 'file';
        return [
            'width'         => $destInfo['width'] ?? 0,
            'height'        => $destInfo['height'] ?? 0,
            'size'          => filesize($destFile),
            'mime_type'     => self::CONTAINERS[$container][1] ?? ($destInfo['mime_type'] ?? ''),
            'filename'      => $destFilename,
            'extension'     => $ext,
            'data'          => json_encode($destInfo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?? '{}',
            // Additional info on the source file was determined:
            'source_width'  => $srcInfo['width'],
            'source_height' => $srcInfo['height'],
            'source_data'   => json_encode($srcInfo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?? '{}',
        ];
    }

    // Parameter
    // ====================

    /**
     * Creates the options that must come BEFORE "-i" (input options).
     *
     * @param array $details Konfigurations-Array
     * @param array $claimed result of claimedGroups()
     * @return array         Argumentliste
     */
    protected function buildInputArguments(array $details, array $claimed): array
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
        // instead of decoding the material up to that point.
        if (!$claimed['trim']) {
            $start = $this->normalizeTime((string)($details['start_time'] ?? ''));
            if ($start !== '') {
                $args[] = '-ss';
                $args[] = $start;
            }
        }

        return $args;
    }

    /**
     * Creates the options that come AFTER "-i" and before the target file (output options).
     * Option groups already set by the administrator are skipped.
     *
     * @param array  $details   Konfigurations-Array
     * @param array  $srcInfo   result of probe() for the source file
     * @param string $container Zielcontainer
     * @param array  $claimed   result of claimedGroups()
     * @param string $claimedCodec result of claimedCodecValue(), '' if the
     *                             administrator did not set the video codec themselves
     * @param string $claimedAudio the same for the audio codec
     * @return array            Argumentliste
     */
    protected function buildOutputArguments(array $details, array $srcInfo, string $container, array $claimed, string $claimedCodec = '', string $claimedAudio = ''): array
    {
        $args = [];

        $codecKey = $this->pick((string)($details['codec'] ?? ''), array_keys(self::VIDEO_CODECS), 'h264');
        $encoder  = self::VIDEO_CODECS[$codecKey];

        // If the administrator set the codec themselves in expert mode, then
        // theirs applies. Filter chain, quality level and preset must match THIS
        // encoder, otherwise ffmpeg aborts ("-preset medium" does not know VP9
        // either) or the setting stays ineffective ("-crf" without "-b:v 0"
        // ignoriert VP9 stillschweigend).
        if ($claimedCodec !== '') $encoder = $claimedCodec;

        // 'copy' and 'none' rule out any processing of the image:
        // without re-encoding there are neither filters nor quality levels.
        $reencode = !in_array($encoder, ['', 'copy', 'none'], true);

        // ---- Video-Codec ----
        if (!$claimed['video_codec']) {
            if ($codecKey === 'none') {
                $args[] = '-vn';
            } elseif ($codecKey === 'copy') {
                $args[] = '-c:v';
                $args[] = 'copy';
            } else {
                $args[] = '-c:v';
                $args[] = $encoder;
            }
        }

        // ---- Video filter (deinterlace, scaling, cropping, frame rate) ----
        if ($reencode && !$claimed['video_filter']) {
            $filters = $this->buildFilterChain($details, $srcInfo, $claimed);
            if ($filters !== []) {
                $args[] = '-vf';
                $args[] = implode(',', $filters);
            }
        }

        // ---- Quality ----
        if ($reencode && !$claimed['video_quality']) {
            $args = array_merge($args, $this->qualityArguments($details, $encoder));
        }

        // ---- Encoder-Geschwindigkeit ----
        if ($reencode && !$claimed['preset']) {
            $args = array_merge($args, $this->presetArguments($details, $encoder));
        }

        // ---- Color model: yuv420p is what every browser and every
        //      device can display safely ----
        if ($reencode && !$claimed['pix_fmt'] && !empty($details['compatible_pix_mode'])) {
            $args[] = '-pix_fmt';
            $args[] = 'yuv420p';
        }

        // ---- Limit runtime (e.g. for short previews) ----
        if (!$claimed['trim']) {
            $duration = $this->normalizeTime((string)($details['duration'] ?? ''));
            if ($duration !== '') {
                $args[] = '-t';
                $args[] = $duration;
            }
        }

        // ---- Remove metadata. Videos from mobile phones regularly contain
        //      geodata that does not belong on the web. ----
        if (!$claimed['metadata'] && !empty($details['strip_meta'])) {
            $args[] = '-map_metadata';
            $args[] = '-1';
            $args[] = '-map_chapters';
            $args[] = '-1';
        }

        // ---- Ton ----
        $args = array_merge($args, $this->audioArguments($details, $claimed, $claimedAudio));

        // ---- Threads ----
        if (!$claimed['threads']) {
            $threads = (int)($details['threads'] ?? 0);
            if ($threads > 0) {
                $args[] = '-threads';
                $args[] = (string)$threads;
            }
        }

        // ---- Index at the start of the file: the video can then be played
        //      before it is fully loaded (MP4/MOV only) ----
        if (!$claimed['movflags'] && !empty($details['faststart']) && $container === 'mp4') {
            $args[] = '-movflags';
            $args[] = '+faststart';
        }

        // ---- Set the target format explicitly so that the extension is not
        //      misinterpreted ----
        if (!$claimed['format']) {
            $args[] = '-f';
            $args[] = self::CONTAINERS[$container][0];
        }

        return $args;
    }

    /**
     * Builds the video filter chain.
     *
     * The target dimensions are calculated here in PHP and not left to ffmpeg:
     * the dimensions of the source are known through probe(), and
     * calculated values are considerably easier to follow than
     * nested scale expressions.
     *
     * @return array list of individual filters, e.g. ['yadif', 'scale=1280:720', 'fps=30']
     */
    protected function buildFilterChain(array $details, array $srcInfo, array $claimed): array
    {
        $filters = [];

        // ---- Deinterlacing ----
        $mode = $this->pick((string)($details['deinterlace'] ?? ''), array_keys(self::DEINTERLACE), 'auto');
        if (self::DEINTERLACE[$mode] !== '') $filters[] = self::DEINTERLACE[$mode];

        // ---- Abmessungen ----
        // With 90/270 degree rotation, auto-rotation swaps width and height;
        // the filter chain sees the rotated image.
        $srcW = $srcInfo['width'];
        $srcH = $srcInfo['height'];
        if (!empty($details['auto_rotation']) && in_array(abs($srcInfo['rotation']), [90, 270], true)) {
            [$srcW, $srcH] = [$srcH, $srcW];
        }

        $reqW = !empty($details['width'])  ? (int)$details['width']  : 0;
        $reqH = !empty($details['height']) ? (int)$details['height'] : 0;

        // Cropping only makes sense if both dimensions are specified.
        // If one is missing, it results from the aspect ratio of the source,
        // then fitting and filling are identical.
        $isCrop = !empty($details['crop']) && $reqW > 0 && $reqH > 0;

        $target = $this->targetSize($srcW, $srcH, $reqW, $reqH, $isCrop, !empty($details['prevent_upscaling']));

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

        // ---- Limit frame rate ----
        // "max" means: only scale down, never invent frames.
        if (!$claimed['framerate']) {
            $maxFps = (int)($details['max_fps'] ?? 0);
            if ($maxFps > 0 && ($srcInfo['fps'] <= 0 || $srcInfo['fps'] > $maxFps)) {
                $filters[] = 'fps=' . $maxFps;
            }
        }

        return $filters;
    }

    /**
     * Calculates the target dimensions.
     *
     * All values are brought to even numbers, because yuv420p halves the color channels
     * and odd edge lengths are therefore rejected by many encoders.
     *
     * @param  int  $srcW              width of the source
     * @param  int  $srcH              height of the source
     * @param  int  $reqW              requested width, 0 = from the aspect ratio
     * @param  int  $reqH              requested height, 0 = from the aspect ratio
     * @param  bool $isCrop            fill the box instead of fitting
     * @param  bool $preventUpscaling  do not enlarge beyond the original size
     * @return array|null ['width'=>int,'height'=>int,'scale_w'=>int,'scale_h'=>int] or null
     */
    protected function targetSize(int $srcW, int $srcH, int $reqW, int $reqH, bool $isCrop, bool $preventUpscaling): ?array
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

        $width  = $this->evenValue($width);
        $height = $this->evenValue($height);

        // When cropping, scaling must be done before the crop so that the
        // box is completely covered (the larger of the two factors).
        $scaleW = $width;
        $scaleH = $height;
        if ($isCrop) {
            $factor = max($width / $srcW, $height / $srcH);
            if ($preventUpscaling) $factor = min($factor, 1.0);
            $scaleW = $this->evenValue((int)ceil($srcW * $factor));
            $scaleH = $this->evenValue((int)ceil($srcH * $factor));
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
     * Next smaller even number, at least 2
     */
    protected function evenValue(int $value): int
    {
        $value = (int)(floor($value / 2) * 2);

        return max(2, $value);
    }

    /**
     * Quality parameters depending on mode and encoder.
     *
     * CRF is a quality target, the file size results from it.
     * Bit rate is a size target, the quality results from it.
     */
    protected function qualityArguments(array $details, string $encoder): array
    {
        $mode = $this->pick((string)($details['quality_mode'] ?? ''), ['crf', 'bitrate'], 'crf');

        if ($mode === 'bitrate') {
            $bitrate = (int)($details['bitrate'] ?? 0);
            if ($bitrate <= 0) return [];

            return ['-b:v', $bitrate . 'k'];
        }

        // An encoder that the processor does not know (e.g. a hardware-
        // accelerated one such as h264_nvenc from expert mode) is not
        // necessarily controlled via "-crf". Then it is preferable to produce no quality setting at all
        // rather than one that ffmpeg rejects. The bit rate above is
        // not affected by this, "-b:v" is understood by every encoder.
        if (!isset(self::CRF_MAX[$encoder])) return [];

        $max = self::CRF_MAX[$encoder] ?? 51;
        $crf = (int)($details['quality'] ?? 23);
        $crf = max(0, min($max, $crf));

        // VP9 only evaluates -crf if the target bit rate is explicitly set to 0
        if ($encoder === 'libvpx-vp9') {
            return ['-crf', (string)$crf, '-b:v', '0'];
        }

        return ['-crf', (string)$crf];
    }

    /**
     * Translate the speed preset into the notation of the respective encoder
     */
    protected function presetArguments(array $details, string $encoder): array
    {
        $preset = $this->pick((string)($details['preset'] ?? ''), self::PRESETS, 'medium');

        return match ($encoder) {
            'libx264', 'libx265' => ['-preset', $preset],
            'libvpx-vp9'         => ['-deadline', 'good', '-cpu-used', (string)(self::PRESET_VP9[$preset] ?? 3)],
            'libsvtav1'          => ['-preset', (string)(self::PRESET_AV1[$preset] ?? 6)],
            default              => [],
        };
    }

    /**
     * Parameters for the audio track
     *
     * @param string $claimedCodec result of claimedCodecValue() for the
     *                             group audio_codec, '' if not set
     */
    protected function audioArguments(array $details, array $claimed, string $claimedCodec = ''): array
    {
        $args     = [];
        $codecKey = $this->pick((string)($details['audio_codec'] ?? ''), array_keys(self::AUDIO_CODECS), 'aac');
        $encoder  = self::AUDIO_CODECS[$codecKey];

        // The codec set by the administrator applies here too. Otherwise, for example with
        // "-c:a copy", a "-af loudnorm" still ends up in the command line,
        // and ffmpeg aborts on it: only what is also
        // re-encoded can be filtered.
        if ($claimedCodec !== '') $encoder = $claimedCodec;

        $reencode = !in_array($encoder, ['', 'copy', 'none'], true);

        if (!$claimed['audio_codec']) {
            if ($codecKey === 'mute') {
                $args[] = '-an';
            } elseif ($codecKey === 'copy') {
                $args[] = '-c:a';
                $args[] = 'copy';
            } else {
                $args[] = '-c:a';
                $args[] = $encoder;
            }
        }

        // Without re-encoding, bit rate, channel count and loudness cannot be influenced
        if (!$reencode) return $args;

        if ($reencode && !$claimed['audio_bitrate']) {
            $bitrate = (int)($details['audio_bitrate'] ?? 0);
            if ($bitrate > 0) {
                $args[] = '-b:a';
                $args[] = $bitrate . 'k';
            }
        }

        if ($reencode && !$claimed['audio_channels']) {
            $channels = $this->pick((string)($details['audio_channels'] ?? ''), array_keys(self::AUDIO_CHANNELS), 'keep');
            if (self::AUDIO_CHANNELS[$channels] !== '') {
                $args[] = '-ac';
                $args[] = self::AUDIO_CHANNELS[$channels];
            }
        }

        // Loudness normalization according to EBU R128.
        // Single pass: ffmpeg measures and corrects at the same time. This is
        // slightly less accurate than two-pass operation, but does not cost a second run.
        if ($reencode && !$claimed['audio_filter'] && !empty($details['audio_normalisation'])) {
            $args[] = '-af';
            $args[] = 'loudnorm=I=-23:LRA=7:TP=-2';
        }

        return $args;
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

            // reduce "-c:v:0" and "-b:a:1" to the base form
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
     * e.g. 'libvpx-vp9' from "-c:v libvpx-vp9" or 'copy' from
     * "-c copy".
     *
     * claimedGroups() only answers the question WHETHER a group is set. For
     * the quality level and the preset, that is not enough: both are in their
     * notation depending on the encoder. Without this value the processor
     * would still build its parameters for the encoder chosen in the form.
     *
     * @param array $tokens  arguments from parameter1 and parameter2
     * @param array $options option names of the codec group, see OPTION_GROUPS
     * @return string        encoder, 'none' for "-vn"/"-an", '' if nothing was set
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

            // "-vn" and "-an" stand alone, they have no value
            if ($option === 'vn' || $option === 'an') return 'none';

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
     *                          width, height, extension, size
     *                          (NO mime_type - ffprobe does not know it; it is
     *                           already in the `data_fil` entry of the field)
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
                'error' => 'proc.ffmpegvideo.error_binary_missing'
            ];
        }
        
        $ffprobe = $this->ffprobePath($ffmpeg);
        if ($ffprobe === '') {
            return [
                'error' => 'proc.ffmpegvideo.error_probe_missing'
            ];
        }

        // Determine properties of the file
        $srcInfo = $this->probe($ffprobe, $srcFile);
        if ($srcInfo === null || $srcInfo['width'] <= 0 || $srcInfo['height'] <= 0) {
            return [
                'error' => 'proc.ffmpegvideo.error_file_dimensions'
            ];
        }

        $srcInfo['size'] = filesize($srcFile);
        $srcInfo['extension'] = pathinfo($srcFile, PATHINFO_EXTENSION);
        return $srcInfo;
    }

    // Hilfsfunktionen
    // ====================

    /**
     * Reads the properties of a file with ffprobe.
     *
     * @return array|null ['width'=>int, 'height'=>int, 'rotation'=>int, 'fps'=>float,
     *                     'duration'=>float, 'frames'=>int, 'has_audio'=>bool] or null on error
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
            'width'     => 0,
            'height'    => 0,
            'rotation'  => 0,
            'fps'       => 0.0,
            'duration'  => 0.0,
            'frames'    => 0,
            'has_audio' => false,
            // NO 'mime_type': ffprobe returns no MIME type, and an empty
            // preset key would, when merged with other
            // data sources, displace the value present there (cf. TriggerMedia)
        ];

        foreach ($data['streams'] as $stream) {
            $type = $stream['codec_type'] ?? '';

            if ($type === 'audio') {
                $info['has_audio'] = true;
                continue;
            }
            if ($type !== 'video' || $info['width'] > 0) continue;

            $info['width']  = (int)($stream['width'] ?? 0);
            $info['height'] = (int)($stream['height'] ?? 0);
            $info['frames'] = (int)($stream['nb_frames'] ?? 0);
            $info['fps']    = $this->parseRational((string)($stream['avg_frame_rate'] ?? ''));
            if ($info['fps'] <= 0) {
                $info['fps'] = $this->parseRational((string)($stream['r_frame_rate'] ?? ''));
            }
            if (isset($stream['duration'])) {
                $info['duration'] = (float) $stream['duration'];
            }
            $info['rotation'] = $this->parseRotation($stream);
        }

        if ($info['duration'] <= 0 && isset($data['format']['duration'])) {
            $info['duration'] = (float) $data['format']['duration'];
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
            if (isset($sideData['rotation'])) $rotation = (int)round((float) $sideData['rotation']);
        }
        if ($rotation === 0 && isset($stream['tags']['rotate'])) {
            $rotation = (int)$stream['tags']['rotate'];
        }

        return ((int)$rotation) % 360;
    }

    /**
     * Evaluate the fraction notation of ffprobe, e.g. "30000/1001" -> 29.97
     */
    protected function parseRational(string $value): float
    {
        if ($value === '' || $value === '0/0') return 0.0;

        if (str_contains($value, '/')) {
            [$numerator, $denominator] = array_pad(explode('/', $value, 2), 2, '1');
            $denominator = (float) $denominator;
            if ($denominator == 0.0) return 0.0;

            return (float) $numerator / $denominator;
        }

        return (float) $value;
    }

    /**
     * Estimates the total number of frames of the TARGET file.
     *
     * Based on the runtime, limited by start_time and duration,
     * multiplied by the frame rate of the output. The value ends up in
     * `bgproc`.`message` and is the denominator for the percentage display.
     *
     * @return int number of frames, 0 if not determinable
     */
    protected function estimateFrames(array $details, array $srcInfo): int
    {
        $fps = (float) $srcInfo['fps'];

        $maxFps = (int)($details['max_fps'] ?? 0);
        if ($maxFps > 0 && ($fps <= 0 || $fps > $maxFps)) $fps = (float) $maxFps;
        if ($fps <= 0) return 0;

        $duration = $this->estimateDuration($details, $srcInfo);

        if ($duration <= 0) {
            // No runtime known: fall back to the reported frame count of the source
            return max(0, (int)$srcInfo['frames']);
        }

        return (int)round($duration * $fps);
    }

    /**
     * Estimates the runtime of the TARGET file in seconds:
     * runtime of the source, shortened by start_time and limited by duration.
     */
    protected function estimateDuration(array $details, array $srcInfo): float
    {
        $duration = (float) $srcInfo['duration'];

        $start = $this->timeToSeconds($this->normalizeTime((string)($details['start_time'] ?? '')));
        if ($start > 0) $duration -= $start;

        $limit = $this->timeToSeconds($this->normalizeTime((string)($details['duration'] ?? '')));
        if ($limit > 0) $duration = min($duration, $limit);

        return max(0.0, $duration);
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
        if (!str_contains($value, ':')) return (float) $value;

        $parts   = array_reverse(explode(':', $value));
        $seconds = 0.0;
        $factor  = 1.0;

        foreach ($parts as $part) {
            $seconds += ((float) $part) * $factor;
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