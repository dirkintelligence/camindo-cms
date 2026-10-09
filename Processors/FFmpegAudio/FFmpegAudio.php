<?php
/**
 * Media processor FFmpegAudio
 *
 * Creates audio versions with the command-line tool ffmpeg.
 *
 * Unlike video, an audio conversion usually takes only seconds. Therefore
 * this processor runs in the FOREGROUND by default (see field
 * details.background_process). For very long recordings (sermons, concerts,
 * audiobooks) background operation can be switched on; it then works
 * exactly as in FFmpegVideo with "-progress" and a temporary file under
 * paths.tmp_dir.
 *
 * The cron job trigger_ff.php reads this file. Because an audio track has no
 * frames, 'frames' => 0 is deliberately entered in `bgproc`.`message`; the
 * progress then results from 'dur' (runtime of the target file) and
 * out_time_us from the progress file.
 *
 * Command line (expert mode):
 *     ffmpeg [parameter1] -i SOURCE [generated parameters] [parameter2] TARGET
 * parameter1 are therefore input options, parameter2 output options.
 *
 * Requirements:
 *   - configuration paths.ffmpeg  (path to the ffmpeg binary)
 *   - configuration paths.ffprobe (optional, otherwise it is searched for next to ffmpeg)
 *   - configuration paths.tmp_dir (writable directory for the progress files)
 *   - MediaProcessor provides $this->bgproc (Camindo\Core\BackgroundProcess).
 */
namespace Camindo\Core\Processors\FFmpegAudio;

use Camindo\Core\MediaProcessor;
use Camindo\Core\BackgroundProcess;

class FFmpegAudio extends MediaProcessor {

    /** Time limits in seconds */
    protected const TIMEOUT_PROBE   = 30;
    protected const TIMEOUT_VERSION = 10;

    /** Default for details.max_time_out if the field is empty */
    protected const DEFAULT_TIMEOUT = 600;

    /**
     * Type identifier in the table `bgproc`, which trigger_ff.php searches for.
     * Deliberately the same as in FFmpegVideo: the same cron job monitors both
     * processors, the progress files have the same format.
     */
    public const BGPROC_TYPE = 'ffmpeg';

    /** Prefix and extension of the temporary progress file */
    public const PROGRESS_PREFIX    = 'ffmpeg-';
    public const PROGRESS_EXTENSION = '.progress';

    /**
     * Target container: option value => [ffmpeg format name, MIME type]
     * The format name is set explicitly via "-f" so that the file extension
     * is not misinterpreted. "ipod" is the muxer that writes a clean
     * .m4a (mp4 container without video track).
     */
    public const CONTAINERS = [
        'mp3'  => ['mp3',  'audio/mpeg'],
        'm4a'  => ['ipod', 'audio/mp4'],
        'ogg'  => ['ogg',  'audio/ogg'],
        'opus' => ['opus', 'audio/opus'],
        'flac' => ['flac', 'audio/flac'],
        'wav'  => ['wav',  'audio/wav'],
    ];

    /**
     * Audio codec: option value => ffmpeg encoder
     * 'auto'  selects the default codec of the chosen container,
     * 'copy'  takes over the audio stream unchanged (very fast, no filter).
     */
    public const AUDIO_CODECS = [
        'auto'    => '',
        'mp3'     => 'libmp3lame',
        'aac'     => 'aac',
        'opus'    => 'libopus',
        'vorbis'  => 'libvorbis',
        'flac'    => 'flac',
        'pcm_s16' => 'pcm_s16le',
        'copy'    => 'copy',
    ];

    /**
     * Which codec fits which container?
     * The first entry is at the same time the default for 'auto'. If the
     * administrator chooses an impossible combination (e.g. AAC in an .ogg),
     * the processor falls back to this first entry.
     * 'copy' is allowed everywhere, because only the muxer can decide
     * whether the existing stream fits into it.
     */
    public const CONTAINER_CODECS = [
        'mp3'  => ['mp3'],
        'm4a'  => ['aac'],
        'ogg'  => ['vorbis', 'opus', 'flac'],
        'opus' => ['opus'],
        'flac' => ['flac'],
        'wav'  => ['pcm_s16'],
    ];

    /** Containers that can carry an embedded cover image */
    protected const COVER_CONTAINERS = ['mp3', 'm4a', 'flac'];

    /** Lossless encoders: bit rate and quality level have no effect here */
    protected const LOSSLESS = ['flac', 'pcm_s16le'];

    /**
     * Sample rate: option value => argument for "-ar", '' = keep.
     * The keys deliberately are not pure numbers, so that PHP does not turn them into
     * integers and the comparison in pick() can remain strict.
     */
    public const SAMPLE_RATES = [
        'keep' => '',
        'r48'  => '48000',
        'r44'  => '44100',
        'r32'  => '32000',
        'r22'  => '22050',
        'r16'  => '16000',
    ];

    /** Channel count: option value => argument for "-ac", '' = keep */
    public const AUDIO_CHANNELS = [
        'keep'   => '',
        'mono'   => '1',
        'stereo' => '2',
    ];

    /**
     * Loudness normalization: option value => filter.
     * Single pass: ffmpeg measures and corrects
     * at the same time. This is slightly less accurate than two-pass operation,
     * but does not cost a second run.
     */
    public const NORMALISATION = [
        'no'        => '',
        'broadcast' => 'loudnorm=I=-23:LRA=7:TP=-2',
        'web'       => 'loudnorm=I=-16:LRA=11:TP=-1',
    ];

    /** Quality control */
    public const QUALITY_MODES = ['vbr', 'bitrate'];

    /**
     * Conversion of the quality level (0 = best) into a target bit rate.
     * Needed for encoders without their own quality scale (AAC, Opus).
     */
    protected const QUALITY_BITRATE = [
        0 => 320, 1 => 256, 2 => 224, 3 => 192, 4 => 160,
        5 => 128, 6 => 112, 7 => 96,  8 => 80,  9 => 64,
    ];

    /**
     * Option groups for the "intelligent merge" (expert mode).
     *
     * If the administrator sets an option from a group in parameter1 / parameter2, the converter
     * no longer generates the corresponding own parameters. The hand-written parameter therefore
     * always wins, but only for "its" area. All other form values remain effective.
     *
     * An appended stream index is removed beforehand ("-c:a:0" -> "c:a"),
     * the entries are exact matches afterwards. "-c" (applies to all streams) is
     * deliberately listed for both audio_codec and cover.
     */
    protected const OPTION_GROUPS = [
        // Target format / container -> replaces "-f"
        'format'         => ['f'],
        // Audio codec              -> replaces codec
        'audio_codec'    => ['c', 'c:a', 'codec', 'codec:a', 'acodec', 'an'],
        // Bit rate / quality       -> replaces quality_mode, quality, bitrate
        'audio_quality'  => ['b:a', 'ab', 'q:a', 'qscale:a', 'qscale', 'aq',
                             'vbr', 'compression_level', 'minrate', 'maxrate', 'bufsize'],
        // Sample rate              -> replaces sample_rate
        'sample_rate'    => ['ar', 'sample_rate'],
        // Channel count            -> replaces audio_channels
        'audio_channels' => ['ac', 'channel_layout', 'ach'],
        // Audio filter chain       -> replaces normalisation, gain, fade_in, fade_out
        'audio_filter'   => ['af', 'filter', 'filter:a', 'filter_complex', 'lavfi'],
        // Time section             -> replaces start_time, duration
        'trim'           => ['ss', 'sseof', 't', 'to'],
        // Metadata                 -> replaces strip_meta
        'metadata'       => ['map_metadata', 'map_chapters', 'metadata',
                             'id3v2_version', 'write_id3v1'],
        // Cover image / stream selection -> replaces keep_cover
        'cover'          => ['c', 'c:v', 'codec:v', 'vcodec', 'vn', 'map', 'disposition:v'],
        // Container details        -> replaces the automatic +faststart
        'movflags'       => ['movflags'],
        // Threads                  -> replaces threads
        'threads'        => ['threads', 'filter_threads', 'filter_complex_threads'],
        // Overwrite                -> replaces "-y"
        'overwrite'      => ['y', 'n'],
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
        // Which formats are actually available depends on which
        // libraries ffmpeg was built with ("ffmpeg -formats").
        //
        // If audio tracks are to be extracted from video files (e.g. sermon video
        // -> podcast MP3), video/* types can additionally be entered here.
        // The video track is discarded anyway.
        $this->defaults['media_types_incl'] = implode("\n", [
            "audio/mpeg",
            "audio/mp4",
            "audio/x-m4a",
            "audio/aac",
            "audio/ogg",
            "audio/opus",
            "audio/flac",
            "audio/x-flac",
            "audio/wav",
            "audio/x-wav",
            "audio/aiff",
            "audio/x-aiff",
            "audio/x-ms-wma",
            "audio/webm",
        ]);

        $this->defaults['extension'] = 'mp3';

        // Additional input fields for the converter
        $this->schema = [
            // Container and codec
            // --------------------
            'details.container' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegaudio.field_container',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_container',
                'display_type' => 'select',
                'default' => 'mp3',
                'max' => 10
            ],
            'details.codec' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegaudio.field_codec',
                'note' => '#proc.ffmpegaudio.note_codec',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_codec',
                'display_type' => 'select',
                'default' => 'auto',
                'max' => 10
            ],
            // Quality
            // --------------------
            'details.quality_mode' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegaudio.field_quality_mode',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_quality_mode',
                'display_type' => 'select',
                'default' => 'vbr',
                'max' => 10
            ],
            'details.quality' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegaudio.field_quality',
                'note' => '#proc.ffmpegaudio.note_quality',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_quality',
                'default' => '4',
                'min' => 0,
                'max' => 9
            ],
            'details.bitrate' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegaudio.field_bitrate',
                'note' => '#proc.ffmpegaudio.note_bitrate',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_bitrate',
                'default' => '128',
                'min' => 0,
                'max' => 1024,
                'suffix' => 'kbit/s'
            ],
            // Audio signal
            // --------------------
            'details.sample_rate' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegaudio.field_sample_rate',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_sample_rate',
                'display_type' => 'select',
                'default' => 'keep',
                'max' => 10
            ],
            'details.audio_channels' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegaudio.field_audio_channels',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_audio_channels',
                'display_type' => 'select',
                'default' => 'keep',
                'max' => 10
            ],
            // Loudness
            // --------------------
            'details.normalisation' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegaudio.field_normalisation',
                'note' => '#proc.ffmpegaudio.note_normalisation',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_normalisation',
                'display_type' => 'select',
                'default' => 'no',
                'max' => 20
            ],
            'details.gain' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegaudio.field_gain',
                'note' => '#proc.ffmpegaudio.note_gain',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_gain',
                'default' => '0',
                'min' => -30,
                'max' => 30,
                'suffix' => 'dB'
            ],
            // Time
            // --------------------
            'details.start_time' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegaudio.field_start_time',
                'note' => '#proc.ffmpegaudio.note_start_time',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_start_time',
                'default' => '',
                // seconds (12.5) or [HH:]MM:SS[.mmm]
                'pattern' => '(\d+(\.\d+)?|(\d+:)?[0-5]?\d:[0-5]?\d(\.\d+)?)',
                'max' => 20
            ],
            'details.duration' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegaudio.field_duration',
                'note' => '#proc.ffmpegaudio.note_duration',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_duration',
                'default' => '',
                'pattern' => '(\d+(\.\d+)?|(\d+:)?[0-5]?\d:[0-5]?\d(\.\d+)?)',
                'max' => 20
            ],
            'details.fade_in' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegaudio.field_fade_in',
                'note' => '#proc.ffmpegaudio.note_fade_in',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_fade_in',
                'default' => '',
                'pattern' => '\d+(\.\d+)?',
                'max' => 6,
                'suffix' => 's'
            ],
            'details.fade_out' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegaudio.field_fade_out',
                'note' => '#proc.ffmpegaudio.note_fade_out',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_fade_out',
                'default' => '',
                'pattern' => '\d+(\.\d+)?',
                'max' => 6,
                'suffix' => 's'
            ],
            // Metadata
            // --------------------
            'details.strip_meta' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegaudio.field_strip_meta',
                'note' => '#proc.ffmpegaudio.note_strip_meta',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_strip_meta',
                'display_type' => 'switch',
                'default' => 0
            ],
            'details.keep_cover' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegaudio.field_keep_cover',
                'note' => '#proc.ffmpegaudio.note_keep_cover',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_keep_cover',
                'display_type' => 'switch',
                'default' => 1
            ],
            // Execution
            // --------------------
            'details.threads' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegaudio.field_threads',
                'note' => '#proc.ffmpegaudio.note_threads',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_threads',
                'default' => '',
                'min' => 0,
                'max' => 64
            ],
            'details.background_process' => [
                'type'  => 'bit',
                'label' => '#proc.ffmpegaudio.field_background_process',
                'note' => '#proc.ffmpegaudio.note_background_process',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_background_process',
                'display_type' => 'switch',
                'default' => 0
            ],
            'details.max_time_out' => [
                'type'  => 'integer',
                'label' => '#proc.ffmpegaudio.field_max_time_out',
                'note' => '#proc.ffmpegaudio.note_max_time_out',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_max_time_out',
                'default' => (string)self::DEFAULT_TIMEOUT,
                'min' => 0,
                'suffix' => 's'
            ],
            // Expert mode
            // --------------------
            // ffmpeg [parameter1] -i {source file} [parameter2] {target file}
            'details.parameter1' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegaudio.field_parameter1',
                'note' => '#proc.ffmpegaudio.note_parameter1',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_parameter1',
                'default' => '',
                'max' => 255
            ],
            'details.parameter2' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegaudio.field_parameter2',
                'note' => '#proc.ffmpegaudio.note_parameter2',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_parameter2',
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
        $details['container']      = $this->pick($details['container']      ?? '', array_keys(self::CONTAINERS),     'mp3');
        $details['codec']          = $this->pick($details['codec']          ?? '', array_keys(self::AUDIO_CODECS),   'auto');
        $details['quality_mode']   = $this->pick($details['quality_mode']   ?? '', self::QUALITY_MODES,              'vbr');
        $details['sample_rate']    = $this->pick($details['sample_rate']    ?? '', array_keys(self::SAMPLE_RATES),   'keep');
        $details['audio_channels'] = $this->pick($details['audio_channels'] ?? '', array_keys(self::AUDIO_CHANNELS), 'keep');
        $details['normalisation']  = $this->pick($details['normalisation']  ?? '', array_keys(self::NORMALISATION),  'no');

        // Codec and container must match. If the selection does not fit,
        // it is reset to the default codec of the container, so that
        // the form shows what is actually used.
        $details['codec'] = $this->resolveCodecKey($details['codec'], $details['container']);

        // Normalize time values, invalid input is discarded
        $details['start_time'] = $this->normalizeTime((string)($details['start_time'] ?? ''));
        $details['duration']   = $this->normalizeTime((string)($details['duration'] ?? ''));
        $details['fade_in']    = $this->normalizeSeconds((string)($details['fade_in'] ?? ''));
        $details['fade_out']   = $this->normalizeSeconds((string)($details['fade_out'] ?? ''));

        // Limit quality level and level correction
        $details['quality'] = max(0, min(9, (int)($details['quality'] ?? 4)));
        $details['gain']    = max(-30, min(30, (int)($details['gain'] ?? 0)));

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
            throw new \Exception($this->cms->snippet('proc.ffmpegaudio.error_binary_missing'));
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
     * width and height are always 0 for audio files.
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
            return $fileData + ['errors' => 'proc.ffmpegaudio.error_binary_missing'];
        }

        $ffprobe = $this->ffprobePath($ffmpeg);
        if ($ffprobe === '') {
            return $fileData + ['errors' => 'proc.ffmpegaudio.error_probe_missing'];
        }

        // Check whether the source file exists and is readable
        if (!file_exists($srcFile) || !is_readable($srcFile)) {
            return $fileData + ['errors' => 'error_file_not_found'];
        }

        // Determine properties of the source. They are needed for the sample rate,
        // the fade-out at the end and the runtime in background operation.
        $srcInfo = $this->probe($ffprobe, $srcFile);
        // (no readable format / no audio track: writeThumbnails() tries the next converter)
        if ($srcInfo === null) {
            return $fileData + ['errors' => 'proc.ffmpegaudio.error_file_not_supported', 'unsupported' => true];
        }
        if (!$srcInfo['has_audio']) {
            return $fileData + ['errors' => 'proc.ffmpegaudio.error_no_audio', 'unsupported' => true];
        }

        // Expert mode: split and evaluate free parameters
        $params1 = $this->execute->tokenizeParameters((string)($details['parameter1'] ?? ''));
        $params2 = $this->execute->tokenizeParameters((string)($details['parameter2'] ?? ''));
        $claimed = $this->claimedGroups(array_merge($params1, $params2));
        // Not only WHETHER the codec itself was set, but also WHICH one:
        // the quality level and filter chain depend on it.
        $claimedCodec = $this->claimedCodecValue(array_merge($params1, $params2), self::OPTION_GROUPS['audio_codec']);

        $container = $this->pick((string)($details['container'] ?? ''), array_keys(self::CONTAINERS), 'mp3');
        $timeout   = (int)($details['max_time_out'] ?? 0);
        if ($timeout <= 0) $timeout = self::DEFAULT_TIMEOUT;

        // Parameters generated from the form values
        $inputArgs  = $this->buildInputArguments($details, $claimed);
        $outputArgs = $this->buildOutputArguments($details, $srcInfo, $container, $claimed, $claimedCodec);

        // Create target directory (ffmpeg, for example, does not do this by itself)
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
                return $fileData + ['errors' => 'proc.ffmpegaudio.error_tmp_dir'];
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
            // trigger_ff.php reads these values back from `message`.
            // 'frames'  : always 0 for audio - an audio track has no frames,
            //             so the progress runs via the runtime
            // 'dur'     : runtime of the target file in seconds
            // 'timeout' : entry from details.max_time_out
            // 'dest'    : path to the file from the media directory
            // ('proc' is added by startBackgroundProcess() itself)
            $message = [
                'frames'  => '0',
                'dur'     => (string)round($this->estimateDuration($details, $srcInfo), 2),
                'timeout' => (string)$timeout,
                'dest'    => $destPath,
            ];
            $error   = '';
            $started = $this->startBackgroundProcess($command, $token, $message, error:$error);
            if ($started === null) {
                return $fileData + [
                    'errors' => 'proc.ffmpegaudio.error_background_start',
                    'error_details' => $error,
                ];
            }
            return array_merge($fileData, [
                'mime_type'  => self::CONTAINERS[$container][1] ?? '',
                'filename'   => $srcData['filename'] ?? 'file',
                'extension'  => $ext,
                'data' => '{}',
                // Info on the source file
                'source_width'  => 0,
                'source_height' => 0,
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
                    ? 'proc.ffmpegaudio.error_timeout'
                    : 'error_file_conversion',
                // Plain-text message from ffmpeg, helpful for troubleshooting
                'error_details' => trim((string)$stderr),
            ];
        }

        // Read the finished target file and build the return structure
        $destInfo = $this->probe($ffprobe, $destFile);

        // same file name
        $destFilename = $srcData['filename'] ?? 'file';
        return [
            // Audio files have no dimensions
            'width'         => 0,
            'height'        => 0,
            'size'          => filesize($destFile),
            'mime_type'     => self::CONTAINERS[$container][1] ?? ($destInfo['mime_type'] ?? ''),
            'filename'      => $destFilename,
            'extension'     => $ext,
            'data'          => json_encode($destInfo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?? '{}',
            // Additional info on the source file was determined:
            'source_width'  => 0,
            'source_height' => 0,
            'source_data'   => json_encode($srcInfo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?? '{}',
        ];
    }

    // Parameter
    // ====================

    /**
     * Creates the options that must come BEFORE "-i" (input options).
     *
     * @param array $details configuration array
     * @param array $claimed result of claimedGroups()
     * @return array         argument list
     */
    protected function buildInputArguments(array $details, array $claimed): array
    {
        $args = [];

        // Overwrite an existing target file, otherwise ffmpeg waits for input
        if (!$claimed['overwrite']) $args[] = '-y';

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
     * @param array  $details   configuration array
     * @param array  $srcInfo   result of probe() for the source file
     * @param string $container target container
     * @param array  $claimed   result of claimedGroups()
     * @param string $claimedCodec result of claimedCodecValue(), '' if the
     *                             administrator did not set the codec themselves
     * @return array            argument list
     */
    protected function buildOutputArguments(array $details, array $srcInfo, string $container, array $claimed, string $claimedCodec = ''): array
    {
        $args = [];

        $codecKey = $this->resolveCodecKey((string)($details['codec'] ?? ''), $container);
        $encoder  = self::AUDIO_CODECS[$codecKey] ?? 'libmp3lame';

        // If the administrator set the codec themselves in expert mode, theirs
        // applies. Otherwise, for example with "-c:a copy", a "-af loudnorm" still
        // ends up in the command line, and ffmpeg aborts on it:
        // only what is also re-encoded can be filtered.
        if ($claimedCodec !== '') $encoder = $claimedCodec;

        // 'copy' rules out any processing of the audio: without re-encoding there are
        // neither filters nor bit rates nor another channel count.
        $reencode = !in_array($encoder, ['', 'copy', 'none'], true);

        // ---- Audio-Codec ----
        if (!$claimed['audio_codec']) {
            $args[] = '-c:a';
            $args[] = $codecKey === 'copy' ? 'copy' : (self::AUDIO_CODECS[$codecKey] ?? 'libmp3lame');
        }

        // ---- Bit rate / quality level ----
        if ($reencode && !$claimed['audio_quality']) {
            $args = array_merge($args, $this->qualityArguments($details, $encoder));
        }

        // ---- Sample rate ----
        if ($reencode && !$claimed['sample_rate']) {
            $rate = $this->sampleRateValue($details);
            if ($rate !== '') {
                $args[] = '-ar';
                $args[] = $rate;
            }
        }

        // ---- Channel count ----
        if ($reencode && !$claimed['audio_channels']) {
            $channels = $this->pick((string)($details['audio_channels'] ?? ''), array_keys(self::AUDIO_CHANNELS), 'keep');
            if (self::AUDIO_CHANNELS[$channels] !== '') {
                $args[] = '-ac';
                $args[] = self::AUDIO_CHANNELS[$channels];
            }
        }

        // ---- Filter chain (loudness, level, fade in and out) ----
        if ($reencode && !$claimed['audio_filter']) {
            $filters = $this->buildFilterChain($details, $srcInfo, $claimed);
            if ($filters !== []) {
                $args[] = '-af';
                $args[] = implode(',', $filters);
            }
        }

        // ---- Limit runtime (e.g. for audio samples) ----
        if (!$claimed['trim']) {
            $duration = $this->normalizeTime((string)($details['duration'] ?? ''));
            if ($duration !== '') {
                $args[] = '-t';
                $args[] = $duration;
            }
        }

        // ---- Cover image ----
        $args = array_merge($args, $this->coverArguments($details, $srcInfo, $container, $claimed));

        // ---- Remove metadata. For audio these are the tags (title, artist,
        //      album), which one usually wants to keep - therefore off by default. ----
        if (!$claimed['metadata'] && !empty($details['strip_meta'])) {
            $args[] = '-map_metadata';
            $args[] = '-1';
            $args[] = '-map_chapters';
            $args[] = '-1';
        }

        // ---- Threads ----
        if (!$claimed['threads']) {
            $threads = (int)($details['threads'] ?? 0);
            if ($threads > 0) {
                $args[] = '-threads';
                $args[] = (string)$threads;
            }
        }

        // ---- Index at the start of the file: the file can then be played
        //      before it is fully loaded. Only the mp4 container
        //      (m4a) knows this rearrangement; mp3 and ogg do not need it. ----
        if (!$claimed['movflags'] && $container === 'm4a') {
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
     * Builds the audio filter chain.
     *
     * The order is deliberately chosen:
     *   1. loudnorm  - measures and corrects the loudness of the original
     *   2. volume    - deliberate offset from the target of the normalization
     *   3. afade in  - at the start
     *   4. afade out - at the end, therefore only calculable after trimming
     *
     * @return array list of individual filters, e.g. ['loudnorm=I=-16:LRA=11:TP=-1', 'afade=t=in:st=0:d=2']
     */
    protected function buildFilterChain(array $details, array $srcInfo, array $claimed): array
    {
        $filters = [];

        // ---- Normalize loudness ----
        $mode = $this->pick((string)($details['normalisation'] ?? ''), array_keys(self::NORMALISATION), 'no');
        if (self::NORMALISATION[$mode] !== '') {
            $filters[] = self::NORMALISATION[$mode];

            // loudnorm calculates internally at 192 kHz and also outputs it that way.
            // If no sample rate is specified, the target file would, without this
            // step, be unnecessarily large and unusable for some encoders.
            if ($this->sampleRateValue($details) === '') {
                $rate = (int)($srcInfo['sample_rate'] ?? 0);
                $filters[] = 'aresample=' . ($rate > 0 ? $rate : 48000);
            }
        }

        // ---- Raise or lower level ----
        $gain = (int)($details['gain'] ?? 0);
        if ($gain !== 0) {
            $filters[] = 'volume=' . $gain . 'dB';
        }

        // ---- Fade in ----
        $fadeIn = (float)$this->normalizeSeconds((string)($details['fade_in'] ?? ''));
        if ($fadeIn > 0) {
            $filters[] = 'afade=t=in:st=0:d=' . $this->trimFloat($fadeIn);
        }

        // ---- Fade out ----
        // The starting point is at the end of the TARGET file, i.e. after start time and
        // length limit. If the runtime is unknown (streams without specification),
        // no fade-out is applied: an incorrectly set starting point would be
        // worse than no fade-out at all.
        $fadeOut = (float)$this->normalizeSeconds((string)($details['fade_out'] ?? ''));
        if ($fadeOut > 0) {
            $duration = $this->estimateDuration($details, $srcInfo);
            if ($duration > $fadeOut) {
                $filters[] = 'afade=t=out:st=' . $this->trimFloat($duration - $fadeOut) . ':d=' . $this->trimFloat($fadeOut);
            }
        }

        return $filters;
    }

    /**
     * Quality parameters depending on mode and encoder.
     *
     * VBR is a quality target, the file size results from it.
     * Bit rate is a size target, the quality results from it.
     *
     * Each encoder has its own scale, so the level 0-9 from the
     * form is translated here:
     *   libmp3lame  -q:a 0-9 (0 = best), so unchanged
     *   libvorbis   -q:a 0-10 (10 = best), so reversed
     *   libopus     has no levels, but by default works with VBR:
     *               the level is translated into a target bit rate
     *   aac         the built-in encoder has no usable VBR level,
     *               therefore also via the target bit rate
     *   flac        lossless, the level controls the computing time when packing
     *   pcm         uncompressed, there is nothing to set here
     *
     */
    protected function qualityArguments(array $details, string $encoder): array
    {
        // Lossless formats: bit rate and quality are meaningless
        if ($encoder === 'pcm_s16le') return [];

        $mode    = $this->pick((string)($details['quality_mode'] ?? ''), self::QUALITY_MODES, 'vbr');
        $quality = max(0, min(9, (int)($details['quality'] ?? 4)));

        // An encoder that the processor does not know (e.g. libfdk_aac from the
        // expert mode) is not necessarily controlled via "-q:a" or
        // "-compression_level": both are encoder-specific and would make ffmpeg
        // abort. The quality is then expressed via the generally
        // valid target bit rate that every encoder understands.
        if (!in_array($encoder, ['libmp3lame', 'libvorbis', 'libopus', 'aac', 'flac'], true)) {
            $bitrate = $mode === 'bitrate'
                ? (int)($details['bitrate'] ?? 0)
                : (int)(self::QUALITY_BITRATE[$quality] ?? 128);

            return $bitrate > 0 ? ['-b:a', $bitrate . 'k'] : [];
        }

        if ($encoder === 'flac') {
            // 0 (best level in the form) = strongest packing, but slower
            $level = max(0, min(12, 12 - $quality));

            return ['-compression_level', (string)$level];
        }

        if ($mode === 'bitrate') {
            $bitrate = (int)($details['bitrate'] ?? 0);
            if ($bitrate <= 0) return [];

            // Without this switch, Opus also works variably in bit-rate mode
            if ($encoder === 'libopus') {
                return ['-b:a', $bitrate . 'k', '-vbr', 'off'];
            }

            return ['-b:a', $bitrate . 'k'];
        }

        return match ($encoder) {
            'libmp3lame' => ['-q:a', (string)$quality],
            // convert 0-9 (0 = best) to 10-0 (10 = best)
            'libvorbis'  => ['-q:a', (string)(int)round((9 - $quality) * 10 / 9)],
            'libopus'    => ['-b:a', (self::QUALITY_BITRATE[$quality] ?? 128) . 'k', '-vbr', 'on'],
            'aac'        => ['-b:a', (self::QUALITY_BITRATE[$quality] ?? 128) . 'k'],
            default      => [],
        };
    }

    /**
     * Parameters for an embedded cover image.
     *
     * Without intervention, ffmpeg would treat an existing cover image as a video stream
     * and abort depending on the container. Therefore the image track is
     * always discarded and only passed through if
     *   - the administrator wants it,
     *   - the target container knows cover images and
     *   - the source actually carries a cover image (and not, say, a
     *     complete video from which only the audio is extracted here).
     */
    protected function coverArguments(array $details, array $srcInfo, string $container, array $claimed): array
    {
        if ($claimed['cover']) return [];

        $keep = !empty($details['keep_cover'])
            && !empty($srcInfo['has_cover'])
            && in_array($container, self::COVER_CONTAINERS, true);

        // No image track in the target file
        if (!$keep) return ['-vn'];

        $args = ['-c:v', 'copy', '-disposition:v', 'attached_pic'];

        // Cover images in MP3 are stored in an ID3v2 frame. Version 3 is the one
        // read by Windows, iTunes and most players.
        if ($container === 'mp3') {
            $args[] = '-id3v2_version';
            $args[] = '3';
        }

        return $args;
    }

    /**
     * Determines which option groups the administrator has set themselves.
     *
     * @param array $tokens arguments from parameter1 and parameter2
     * @return array        ['audio_filter' => bool, 'audio_quality' => bool, ...]
     */
    protected function claimedGroups(array $tokens): array
    {
        $claimed = array_fill_keys(array_keys(self::OPTION_GROUPS), false);

        foreach ($tokens as $token) {
            // Only consider options, not values
            if ($token === '' || $token[0] !== '-') continue;

            // reduce "-c:a:0" and "-b:a:1" to the base form
            $option = strtolower(ltrim($token, '-'));
            $option = (string)preg_replace('/:\d+$/', '', $option);

            foreach (self::OPTION_GROUPS as $group => $options) {
                if (in_array($option, $options, true)) $claimed[$group] = true;
            }
        }

        return $claimed;
    }

    /**
     * Reads the encoder that the administrator set themselves in expert mode,
     * e.g. 'libopus' from "-c:a libopus" or 'copy' from "-c copy".
     *
     * claimedGroups() only answers the question WHETHER a group is set. For
     * the quality level that is not enough: its notation depends on the
     * encoder. Without this value the processor would still build its parameters
     * for the encoder chosen in the form.
     *
     * @param array $tokens  arguments from parameter1 and parameter2
     * @param array $options option names of the codec group, see OPTION_GROUPS
     * @return string        encoder, 'none' for "-an"/"-vn", '' if nothing was set
     */
    protected function claimedCodecValue(array $tokens, array $options): string
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // Only consider options, not values
            if ($token === '' || $token[0] !== '-') continue;

            // reduce "-c:a:0" to the base form
            $option = strtolower(ltrim($token, '-'));
            $option = (string)preg_replace('/:\d+$/', '', $option);

            if (!in_array($option, $options, true)) continue;

            // "-an" and "-vn" stand alone, they have no value
            if ($option === 'an' || $option === 'vn') return 'none';

            // The next token is the encoder name, unless
            // the next option already starts there
            $value = $tokens[$i + 1] ?? '';
            if ($value !== '' && $value[0] !== '-') return strtolower($value);
        }

        return '';
    }

    /**
     * Public method to add the parameters of a file created by a background process
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
                'error' => 'proc.ffmpegaudio.error_binary_missing'
            ];
        }

        $ffprobe = $this->ffprobePath($ffmpeg);
        if ($ffprobe === '') {
            return [
                'error' => 'proc.ffmpegaudio.error_probe_missing'
            ];
        }

        // Determine properties of the file
        $srcInfo = $this->probe($ffprobe, $srcFile);
        if ($srcInfo === null || !$srcInfo['has_audio']) {
            return [
                'error' => 'proc.ffmpegaudio.error_no_audio'
            ];
        }

        $srcInfo['size'] = filesize($srcFile);
        $srcInfo['extension'] = pathinfo($srcFile, PATHINFO_EXTENSION);
        return $srcInfo;
    }

    // Helper functions
    // ====================

    /**
     * Reads the properties of a file with ffprobe.
     *
     * width and height stay 0: they are in the return value only because the
     * caller expects them. An audio file has no dimensions.
     *
     * @return array|null ['duration'=>float, 'sample_rate'=>int, 'channels'=>int,
     *                     'bitrate'=>int, 'codec'=>string, 'has_audio'=>bool,
     *                     'has_cover'=>bool, 'has_video'=>bool] or null on error
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
            'width'       => 0,
            'height'      => 0,
            'duration'    => 0.0,
            'sample_rate' => 0,
            'channels'    => 0,
            'bitrate'     => 0,
            'codec'       => '',
            'has_audio'   => false,
            'has_cover'   => false,
            'has_video'   => false,
            'mime_type'   => '',
        ];

        foreach ($data['streams'] as $stream) {
            $type = $stream['codec_type'] ?? '';

            if ($type === 'video') {
                // An image with the flag "attached_pic" is a
                // cover image, everything else is a real video track.
                if (!empty($stream['disposition']['attached_pic'])) {
                    $info['has_cover'] = true;
                } else {
                    $info['has_video'] = true;
                }
                continue;
            }
            // Only evaluate the first audio stream
            if ($type !== 'audio' || $info['has_audio']) continue;

            $info['has_audio']   = true;
            $info['sample_rate'] = (int)($stream['sample_rate'] ?? 0);
            $info['channels']    = (int)($stream['channels'] ?? 0);
            $info['bitrate']     = (int)round(((float)($stream['bit_rate'] ?? 0)) / 1000);
            $info['codec']       = (string)($stream['codec_name'] ?? '');
            if (isset($stream['duration'])) {
                $info['duration'] = (float)$stream['duration'];
            }
        }

        if ($info['duration'] <= 0 && isset($data['format']['duration'])) {
            $info['duration'] = (float)$data['format']['duration'];
        }
        if ($info['bitrate'] <= 0 && isset($data['format']['bit_rate'])) {
            $info['bitrate'] = (int)round(((float)$data['format']['bit_rate']) / 1000);
        }

        return $info;
    }

    /**
     * Match the codec selection with the container.
     *
     * 'auto' becomes the default codec of the container, an impossible
     * combination too. 'copy' always remains: whether the existing
     * audio stream fits into the container can only be decided by the muxer.
     */
    protected function resolveCodecKey(string $codecKey, string $container): string
    {
        $container = $this->pick($container, array_keys(self::CONTAINERS), 'mp3');
        $codecKey  = $this->pick($codecKey, array_keys(self::AUDIO_CODECS), 'auto');
        $allowed   = self::CONTAINER_CODECS[$container] ?? ['mp3'];

        if ($codecKey === 'copy') return 'copy';
        if (in_array($codecKey, $allowed, true)) return $codecKey;

        return (string)reset($allowed);
    }

    /**
     * Sample rate from the form as argument for "-ar", '' = keep
     */
    protected function sampleRateValue(array $details): string
    {
        $key = $this->pick((string)($details['sample_rate'] ?? ''), array_keys(self::SAMPLE_RATES), 'keep');

        return self::SAMPLE_RATES[$key];
    }

    /**
     * Estimates the runtime of the TARGET file in seconds:
     * runtime of the source, shortened by start_time and limited by duration.
     */
    protected function estimateDuration(array $details, array $srcInfo): float
    {
        $duration = (float)($srcInfo['duration'] ?? 0);

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
     * Check the seconds value for the fades ("2" or "1.5").
     *
     * @return string normalized value or '' for invalid input
     */
    protected function normalizeSeconds(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if ($value === '') return '';

        if (!preg_match('/^\d+(\.\d{1,3})?$/', $value)) return '';
        if ((float)$value <= 0) return '';

        return $value;
    }

    /**
     * Convert a time value to seconds
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
     * Floating-point number for the command line: at most three decimal places,
     * always with a point, without superfluous zeros.
     */
    protected function trimFloat(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    /**
     * Check a value against a list of allowed values
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
