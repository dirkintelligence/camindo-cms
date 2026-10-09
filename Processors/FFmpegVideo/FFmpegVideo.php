<?php
/**
 * Medien-Prozessor FFmpegVideo
 *
 * Erzeugt Video-Versionen mit dem Kommandozeilen-Werkzeug ffmpeg.
 *
 * Im Unterschied zu Magick laufen Video-Konvertierungen typischerweise Minuten bis
 * Stunden. Deshalb wird ffmpeg standardmäßig als HINTERGRUNDPROZESS gestartet
 * (siehe Feld details.background_process) und meldet seinen Fortschritt über
 * "-progress" in eine temporäre Datei unter paths.tmp_dir.
 *
 * Der Cronjob trigger_ff.php liest diese Datei aus, vergleicht den aktuellen Frame
 * mit der Gesamtzahl der Frames (steht in `bgproc`.`message`) und trägt den
 * Fortschritt in Prozent in `bgproc`.`progress` ein.
 *
 * Kommandozeile (Expertenmodus):
 *     ffmpeg [parameter1] -i QUELLE [erzeugte Parameter] [parameter2] ZIEL
 * parameter1 sind also Eingabe-Optionen, parameter2 Ausgabe-Optionen.
 *
 * Voraussetzungen:
 *   - Konfiguration paths.ffmpeg  (Pfad zur ffmpeg-Binary)
 *   - Konfiguration paths.ffprobe (optional, sonst wird sie neben ffmpeg gesucht)
 *   - Konfiguration paths.tmp_dir (beschreibbares Verzeichnis für die Fortschrittsdateien)
 *   - MediaProcessor stellt $this->bgproc (Camindo\Core\BackgroundProcess) bereit.
 *     Fehlt die Eigenschaft, fällt der Prozessor automatisch auf den Vordergrund zurück.
 */
namespace Camindo\Core\Processors\FFmpegVideo;

use Camindo\Core\MediaProcessor;
use Camindo\Core\BackgroundProcess;

class FFmpegVideo extends MediaProcessor {

    /** Zeitlimits in Sekunden */
    protected const TIMEOUT_PROBE   = 30;
    protected const TIMEOUT_VERSION = 10;

    /** Vorgabe für details.max_time_out, falls das Feld leer ist */
    protected const DEFAULT_TIMEOUT = 3600;

    /** Typkennung in der Tabelle `bgproc`, danach sucht trigger_ff.php */
    public const BGPROC_TYPE = 'ffmpeg';

    /** Präfix und Endung der temporären Fortschrittsdatei */
    public const PROGRESS_PREFIX    = 'ffmpeg-';
    public const PROGRESS_EXTENSION = '.progress';

    /**
     * Zielcontainer: Auswahlwert => [ffmpeg-Formatname, MIME-Type]
     * Der Formatname wird per "-f" explizit gesetzt, damit die Dateiendung
     * nicht fehlinterpretiert wird.
     */
    public const CONTAINERS = [
        'mp4'  => ['mp4',      'video/mp4'],
        'webm' => ['webm',     'video/webm'],
        'mkv'  => ['matroska', 'video/x-matroska'],
    ];

    /**
     * Video-Codec: Auswahlwert => ffmpeg-Encoder
     * 'copy' kopiert den Videostream unverändert, '' bedeutet "kein Video" (-vn).
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
     * Obergrenze des CRF-Werts je Encoder.
     * x264/x265 arbeiten mit 0-51, VP9 und SVT-AV1 mit 0-63.
     */
    protected const CRF_MAX = [
        'libx264'    => 51,
        'libx265'    => 51,
        'libvpx-vp9' => 63,
        'libsvtav1'  => 63,
    ];

    /**
     * Geschwindigkeits-/Qualitäts-Voreinstellungen.
     * x264 und x265 kennen diese Namen direkt, VP9 und SVT-AV1 brauchen Zahlen
     * (siehe presetArguments()). Reihenfolge = Reihenfolge im Auswahlfeld.
     */
    public const PRESETS = [
        'ultrafast', 'superfast', 'veryfast', 'faster', 'fast',
        'medium', 'slow', 'slower', 'veryslow',
    ];

    /** Umrechnung der Presets auf "-cpu-used" von libvpx-vp9 (0 = langsam/gut) */
    protected const PRESET_VP9 = [
        'ultrafast' => 8, 'superfast' => 7, 'veryfast' => 6, 'faster' => 5,
        'fast' => 4, 'medium' => 3, 'slow' => 2, 'slower' => 1, 'veryslow' => 0,
    ];

    /** Umrechnung der Presets auf "-preset" von libsvtav1 (0 = langsam/gut, 13 = schnell) */
    protected const PRESET_AV1 = [
        'ultrafast' => 12, 'superfast' => 11, 'veryfast' => 10, 'faster' => 9,
        'fast' => 8, 'medium' => 6, 'slow' => 5, 'slower' => 4, 'veryslow' => 2,
    ];

    /**
     * Audio-Codec: Auswahlwert => ffmpeg-Encoder
     * 'copy' kopiert den Audiostream, '' bedeutet "stumm" (-an).
     */
    public const AUDIO_CODECS = [
        'aac'  => 'aac',
        'opus' => 'libopus',
        'mp3'  => 'libmp3lame',
        'copy' => 'copy',
        'mute' => '',
    ];

    /** Kanalzahl: Auswahlwert => Argument für "-ac", '' = beibehalten */
    public const AUDIO_CHANNELS = [
        'keep'   => '',
        'mono'   => '1',
        'stereo' => '2',
    ];

    /** Deinterlacing: Auswahlwert => Filter, '' = nicht deinterlacen */
    public const DEINTERLACE = [
        'no'     => '',
        'auto'   => 'yadif=deint=interlaced',
        'always' => 'yadif',
    ];

    /**
     * Options-Gruppen für den "intelligenten Merge" (Expertenmodus).
     *
     * Belegt der Administrator in parameter1 / parameter2 eine Option aus einer
     * Gruppe, dann erzeugt der Konverter die zugehörigen eigenen Parameter NICHT mehr.
     * Der handgeschriebene Parameter gewinnt also immer, aber nur für "seinen" Bereich.
     * Alle übrigen Formularwerte bleiben wirksam.
     *
     * Ein angehängter Stream-Index wird vorher entfernt ("-c:v:0" -> "c:v"),
     * die Einträge sind danach exakte Treffer. "-c" (gilt für alle Streams) steht
     * deshalb bewusst sowohl bei video_codec als auch bei audio_codec.
     */
    protected const OPTION_GROUPS = [
        // Zielformat / Container       -> ersetzt "-f"
        'format'         => ['f'],
        // Video-Codec                  -> ersetzt codec
        'video_codec'    => ['c', 'c:v', 'codec', 'codec:v', 'vcodec', 'vn'],
        // Videofilterkette             -> ersetzt width, height, crop,
        //                                 prevent_upscaling, deinterlace, max_fps
        'video_filter'   => ['vf', 'filter', 'filter:v', 'filter_complex', 'lavfi'],
        // Bildqualität                 -> ersetzt quality_mode, quality, bitrate
        'video_quality'  => ['crf', 'cq', 'q:v', 'qscale:v', 'qp',
                             'b:v', 'vb', 'maxrate', 'minrate', 'bufsize'],
        // Encoder-Geschwindigkeit      -> ersetzt preset
        'preset'         => ['preset', 'cpu-used', 'deadline', 'speed'],
        // Farbmodell                   -> ersetzt compatible_pix_mode
        'pix_fmt'        => ['pix_fmt'],
        // Bildrate                     -> ersetzt max_fps
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
        // Überschreiben                -> ersetzt "-y"
        'overwrite'      => ['y', 'n'],
    ];

    // Backend
    // ====================

    /**
     * Tabellenstruktur für den Konverter
     * Darauf basierend kann mit $this->backend->formField() ein Eingabefeld erzeugt werden
     *
     * type:
     *     integer -> BIGINT
     *     chars -> VARCHAR(255)
     *     bit -> BIT(1)
     * display_type:
     *     text:   einfaches Eingabefeld
     *     select: Auswahl, Optionen werden am besten dynamisch erzeugt
     *     label:  mehrsprachiges Snippet #proc.{processorname}.{snippetname}
     *     prefix,
     *     suffix: erscheint vor / nach dem Eingabefeld
     *     pattern: Javascript Pattern für Eingabevalidierung
     *     min, max: Mindest- / Maximalwert, bei Text die Zeichenanzahl
     */
    protected function hookDefinition(): void
    {
        // Defaultwerte für Eingabefelder des Konverters.
        // Welche Formate tatsächlich zur Verfügung stehen, hängt davon ab, mit
        // welchen Bibliotheken ffmpeg übersetzt wurde ("ffmpeg -formats").
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

        // Zusätzliche Eingabefelder für den Konverter
        $this->schema = [
            // Container und Codec
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
            // Qualität
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
            // Zeit und Bildrate
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
                // Sekunden (12.5) oder [HH:]MM:SS[.mmm]
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
            // Kompatibilität
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
            // Ausführung
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
            // ffmpeg [parameter1] -i {Quelldatei} [parameter2] {Zieldatei}
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

        // Auswahlfelder auf gültige Werte zurechtbiegen, damit später in
        // convert() keine unbekannten Schlüssel ankommen.
        $details['container']      = $this->pick($details['container']      ?? '', array_keys(self::CONTAINERS),      'mp4');
        $details['codec']          = $this->pick($details['codec']          ?? '', array_keys(self::VIDEO_CODECS),    'h264');
        $details['quality_mode']   = $this->pick($details['quality_mode']   ?? '', ['crf', 'bitrate'],                'crf');
        $details['preset']         = $this->pick($details['preset']         ?? '', self::PRESETS,                     'medium');
        $details['deinterlace']    = $this->pick($details['deinterlace']    ?? '', array_keys(self::DEINTERLACE),     'auto');
        $details['audio_codec']    = $this->pick($details['audio_codec']    ?? '', array_keys(self::AUDIO_CODECS),    'aac');
        $details['audio_channels'] = $this->pick($details['audio_channels'] ?? '', array_keys(self::AUDIO_CHANNELS),  'keep');

        // Zeitangaben normalisieren, ungültige Eingaben werden verworfen
        $details['start_time'] = $this->normalizeTime((string)($details['start_time'] ?? ''));
        $details['duration']   = $this->normalizeTime((string)($details['duration'] ?? ''));

        // CRF und Bitrate hängen am Modus: der jeweils andere Wert bleibt stehen,
        // wird aber nicht verwendet. Nur der CRF-Bereich wird begrenzt.
        $quality = (int)($details['quality'] ?? 23);
        $details['quality'] = max(0, min(63, $quality));

        // Ohne Zeitlimit könnte ein hängender ffmpeg unbegrenzt Rechenzeit belegen
        $timeout = (int)($details['max_time_out'] ?? 0);
        $details['max_time_out'] = $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;

        // Expertenmodus: Zeilenumbrüche und Mehrfach-Leerzeichen entfernen,
        // damit die gespeicherte Kommandozeile immer einzeilig bleibt.
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
     * Erzeugt eine Dateiversion basierend auf den übergebenen Parametern
     *
     * @param string $srcPath   Pfad zur Quelldatei, relativ zu media_dir
     * @param array  $srcData   Daten der Quell-Datei
     *      'extension' => $fileExtension,
     *      'mime_type' => $fileMime,
     *      'filename'  => $fileOriginName,  // ursprünglicher Dateiname, gegen Injections gesäubert
     *      'field_id'  => $fieldId,         // für Datei-Pfad / `data_fil`-Eintrag beim checkIn
     * @param string $destPath  Pfad zur Zieldatei, relativ zu media_dir
     * @param array  $details   Konfigurations-Array
     * @param bool $forceBgproc true: erzwingt Ausführung als Hintergrundprozess
     * @return array            Daten der Zieldatei, bzw ['size'=>0, 'errors'=>'...']
     *
     * Im Hintergrundbetrieb ist die Zieldatei beim Rücksprung noch NICHT fertig.
     * Die Rückgabe enthält dann 'size' => 0 und zusätzlich
     *     'pending' => true, 'bgproc_id' => int, 'pid' => int, 'token' => string
     * Der Aufrufer sollte den Datensatz in diesem Fall als "in Arbeit" markieren
     * und die endgültigen Werte nachtragen, sobald der Prozess beendet ist.
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

        // ffmpeg muss verfügbar sein
        $ffmpeg = (string)($this->config->get('paths.ffmpeg') ?? '');
        if ($ffmpeg === '' || !is_executable($ffmpeg)) {
            return $fileData + ['errors' => 'proc.ffmpegvideo.error_binary_missing'];
        }
        
        $ffprobe = $this->ffprobePath($ffmpeg);
        if ($ffprobe === '') {
            return $fileData + ['errors' => 'proc.ffmpegvideo.error_probe_missing'];
        }

        // Prüfen, ob die Quelldatei existiert und lesbar ist
        if (!file_exists($srcFile) || !is_readable($srcFile)) {
            return $fileData + ['errors' => 'error_file_not_found'];
        }

        // Eigenschaften der Quelle ermitteln. Sie werden für die Zielabmessungen,
        // die Bildrate und die Gesamtzahl der Frames gebraucht.
        $srcInfo = $this->probe($ffprobe, $srcFile);
        if ($srcInfo === null || $srcInfo['width'] <= 0 || $srcInfo['height'] <= 0) {
            // (kein lesbares Format: writeThumbnails() probiert den nächsten Konverter)
            return $fileData + ['errors' => 'proc.ffmpegvideo.error_file_dimensions', 'unsupported' => true];
        }

        // Expertenmodus: freie Parameter zerlegen und auswerten
        $params1 = $this->execute->tokenizeParameters((string)($details['parameter1'] ?? ''));
        $params2 = $this->execute->tokenizeParameters((string)($details['parameter2'] ?? ''));
        $claimed = $this->claimedGroups(array_merge($params1, $params2));
        // Nicht nur OB der Codec selbst gesetzt wurde, sondern auch WELCHER:
        // davon hängen Qualitätsstufe, Preset und Filterkette ab.
        $claimedCodec = $this->claimedCodecValue(array_merge($params1, $params2), self::OPTION_GROUPS['video_codec']);
        $claimedAudio = $this->claimedCodecValue(array_merge($params1, $params2), self::OPTION_GROUPS['audio_codec']);

        $container = $this->pick((string)($details['container'] ?? ''), array_keys(self::CONTAINERS), 'mp4');
        $timeout   = (int)($details['max_time_out'] ?? 0);
        if ($timeout <= 0) $timeout = self::DEFAULT_TIMEOUT;

        // Aus den Formularwerten erzeugte Parameter
        $inputArgs  = $this->buildInputArguments($details, $claimed);
        $outputArgs = $this->buildOutputArguments($details, $srcInfo, $container, $claimed, $claimedCodec, $claimedAudio);

        // Zielverzeichnis anlegen (ffmpeg tut das nicht von sich aus)
        $destDir = dirname($destFile);
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);

        $background = !empty($details['background_process']) || $forceBgproc;

        // Fortschrittsdatei für den Hintergrundprozess.
        $progressArgs = [];
        if ($background) {
            // Token wird bis in den Hintergrundprozess weitergereicht!
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

        // ffmpeg [erzeugte Eingabe-Optionen] [parameter1] -i QUELLE
        //        [erzeugte Ausgabe-Optionen] [parameter2] ZIEL
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
            // Gesamtzahl der Frames abschätzen und zusammen mit dem Zeitlimit ablegen.
            // trigger_ff.php liest diese Werte aus `message` wieder aus.
            // 'frames'  : Anzahl Frames die zu berechnen sind
            // 'dur'     : Laufzeit der Zieldatei als Rückfallebene, falls die Framezahl
            //             nicht ermittelbar war (Streams ohne nb_frames und ohne Bildrate)
            // 'timeout' : Eintrag aus details.max_time_out
            // 'dest'    : Pfad zur Date ab media-Verzeichnis
            // ('proc' ergänzt startBackgroundProcess() selbst)
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
                // Infos zur Quell-Datei
                'source_width'  => $srcInfo['width'],
                'source_height' => $srcInfo['height'],
                'source_data' => json_encode($srcData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?? '{}',
                // Infos zum Hintergrundprozess
                'token'      => (string)$started['token'],
                'bgproc_id'  => (int)$started['id'],
                'pid'        => (int)$started['pid']
            ]);
        }

        $exitCode = $this->execute->execute($command, $stdout, $stderr, $timeout);

        // Prüfen ob das Speichern erfolgreich war
        if ($exitCode !== 0 || !is_file($destFile) || filesize($destFile) === 0) {
            // Angefangene oder leere Zieldatei nicht liegen lassen
            if (is_file($destFile)) @unlink($destFile);

            return $fileData + [
                'errors' => $exitCode === $this->execute::EXEC_TIMEOUT
                    ? 'proc.ffmpegvideo.error_timeout'
                    : 'error_file_conversion',
                // Klartext-Meldung von ffmpeg, hilfreich für die Fehlersuche
                'error_details' => trim((string)$stderr),
            ];
        }

        // Fertige Zieldatei auslesen und Rückgabestruktur bauen
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
            // Zusätzliche Infos zur Quell-Datei wurden ermittelt:
            'source_width'  => $srcInfo['width'],
            'source_height' => $srcInfo['height'],
            'source_data'   => json_encode($srcInfo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?? '{}',
        ];
    }

    // Parameter
    // ====================

    /**
     * Erzeugt die Optionen, die VOR "-i" stehen müssen (Eingabe-Optionen).
     *
     * @param array $details Konfigurations-Array
     * @param array $claimed Ergebnis von claimedGroups()
     * @return array         Argumentliste
     */
    protected function buildInputArguments(array $details, array $claimed): array
    {
        $args = [];

        // Vorhandene Zieldatei überschreiben, sonst wartet ffmpeg auf eine Eingabe
        if (!$claimed['overwrite']) $args[] = '-y';

        // Drehung aus den Metadaten anwenden. ffmpeg tut das von sich aus,
        // abschalten lässt es sich nur ausdrücklich.
        if (!$claimed['orient'] && empty($details['auto_rotation'])) {
            $args[] = '-noautorotate';
        }

        // Startzeit als Eingabe-Option: ffmpeg springt dann direkt an die Stelle,
        // statt das Material bis dahin zu dekodieren.
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
     * Erzeugt die Optionen, die NACH "-i" und vor der Zieldatei stehen (Ausgabe-Optionen).
     * Bereits vom Administrator belegte Options-Gruppen werden übersprungen.
     *
     * @param array  $details   Konfigurations-Array
     * @param array  $srcInfo   Ergebnis von probe() für die Quelldatei
     * @param string $container Zielcontainer
     * @param array  $claimed   Ergebnis von claimedGroups()
     * @param string $claimedCodec Ergebnis von claimedCodecValue(), '' wenn der
     *                             Administrator den Video-Codec nicht selbst gesetzt hat
     * @param string $claimedAudio dasselbe für den Audio-Codec
     * @return array            Argumentliste
     */
    protected function buildOutputArguments(array $details, array $srcInfo, string $container, array $claimed, string $claimedCodec = '', string $claimedAudio = ''): array
    {
        $args = [];

        $codecKey = $this->pick((string)($details['codec'] ?? ''), array_keys(self::VIDEO_CODECS), 'h264');
        $encoder  = self::VIDEO_CODECS[$codecKey];

        // Hat der Administrator den Codec im Expertenmodus selbst gesetzt, dann
        // gilt seiner. Filterkette, Qualitätsstufe und Preset müssen zu DIESEM
        // Encoder passen, sonst bricht ffmpeg ab ("-preset medium" kennt VP9
        // nicht) oder die Angabe bleibt wirkungslos ("-crf" ohne "-b:v 0"
        // ignoriert VP9 stillschweigend).
        if ($claimedCodec !== '') $encoder = $claimedCodec;

        // 'copy' und 'none' schließen jede Bearbeitung des Bildes aus:
        // ohne Neukodierung gibt es weder Filter noch Qualitätsstufen.
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

        // ---- Videofilter (Deinterlace, Skalierung, Beschnitt, Bildrate) ----
        if ($reencode && !$claimed['video_filter']) {
            $filters = $this->buildFilterChain($details, $srcInfo, $claimed);
            if ($filters !== []) {
                $args[] = '-vf';
                $args[] = implode(',', $filters);
            }
        }

        // ---- Qualität ----
        if ($reencode && !$claimed['video_quality']) {
            $args = array_merge($args, $this->qualityArguments($details, $encoder));
        }

        // ---- Encoder-Geschwindigkeit ----
        if ($reencode && !$claimed['preset']) {
            $args = array_merge($args, $this->presetArguments($details, $encoder));
        }

        // ---- Farbmodell: yuv420p ist das, was jeder Browser und jedes
        //      Endgerät sicher darstellen kann ----
        if ($reencode && !$claimed['pix_fmt'] && !empty($details['compatible_pix_mode'])) {
            $args[] = '-pix_fmt';
            $args[] = 'yuv420p';
        }

        // ---- Laufzeit begrenzen (z. B. für Kurz-Previews) ----
        if (!$claimed['trim']) {
            $duration = $this->normalizeTime((string)($details['duration'] ?? ''));
            if ($duration !== '') {
                $args[] = '-t';
                $args[] = $duration;
            }
        }

        // ---- Metadaten entfernen. Videos aus Mobiltelefonen enthalten
        //      regelmäßig Geodaten, die nicht ins Web gehören. ----
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

        // ---- Index an den Anfang der Datei: das Video kann dann abgespielt
        //      werden, bevor es vollständig geladen ist (nur MP4/MOV) ----
        if (!$claimed['movflags'] && !empty($details['faststart']) && $container === 'mp4') {
            $args[] = '-movflags';
            $args[] = '+faststart';
        }

        // ---- Zielformat explizit vorgeben, damit die Endung nicht
        //      fehlinterpretiert wird ----
        if (!$claimed['format']) {
            $args[] = '-f';
            $args[] = self::CONTAINERS[$container][0];
        }

        return $args;
    }

    /**
     * Baut die Videofilterkette auf.
     *
     * Die Zielabmessungen werden hier in PHP ausgerechnet und nicht ffmpeg
     * überlassen: die Abmessungen der Quelle sind durch probe() bekannt, und
     * ausgerechnete Werte sind erheblich leichter nachzuvollziehen als
     * verschachtelte scale-Ausdrücke.
     *
     * @return array Liste einzelner Filter, z. B. ['yadif', 'scale=1280:720', 'fps=30']
     */
    protected function buildFilterChain(array $details, array $srcInfo, array $claimed): array
    {
        $filters = [];

        // ---- Deinterlacing ----
        $mode = $this->pick((string)($details['deinterlace'] ?? ''), array_keys(self::DEINTERLACE), 'auto');
        if (self::DEINTERLACE[$mode] !== '') $filters[] = self::DEINTERLACE[$mode];

        // ---- Abmessungen ----
        // Bei 90/270 Grad Drehung tauscht die Autorotation Breite und Höhe,
        // die Filterkette sieht das gedrehte Bild.
        $srcW = $srcInfo['width'];
        $srcH = $srcInfo['height'];
        if (!empty($details['auto_rotation']) && in_array(abs($srcInfo['rotation']), [90, 270], true)) {
            [$srcW, $srcH] = [$srcH, $srcW];
        }

        $reqW = !empty($details['width'])  ? (int)$details['width']  : 0;
        $reqH = !empty($details['height']) ? (int)$details['height'] : 0;

        // Beschneiden ist nur sinnvoll, wenn beide Maße vorgegeben sind.
        // Fehlt eines, ergibt es sich aus dem Seitenverhältnis der Quelle,
        // dann sind Einpassen und Ausfüllen identisch.
        $isCrop = !empty($details['crop']) && $reqW > 0 && $reqH > 0;

        $target = $this->targetSize($srcW, $srcH, $reqW, $reqH, $isCrop, !empty($details['prevent_upscaling']));

        if ($target !== null) {
            if ($isCrop) {
                // CROP: erst so skalieren, dass die Box vollständig bedeckt ist,
                // dann mittig auf das Zielformat beschneiden
                $filters[] = 'scale=' . $target['scale_w'] . ':' . $target['scale_h'];
                $filters[] = 'crop=' . $target['width'] . ':' . $target['height'];
            } else {
                // FIT: skalieren, so dass das Bild in die Box passt
                $filters[] = 'scale=' . $target['width'] . ':' . $target['height'];
            }
        }

        // ---- Bildrate begrenzen ----
        // "max" heißt: nur herunterrechnen, niemals Frames erfinden.
        if (!$claimed['framerate']) {
            $maxFps = (int)($details['max_fps'] ?? 0);
            if ($maxFps > 0 && ($srcInfo['fps'] <= 0 || $srcInfo['fps'] > $maxFps)) {
                $filters[] = 'fps=' . $maxFps;
            }
        }

        return $filters;
    }

    /**
     * Rechnet die Zielabmessungen aus.
     *
     * Alle Werte werden auf gerade Zahlen gebracht, weil yuv420p die Farbkanäle
     * halbiert und ungerade Kantenlängen deshalb von vielen Encodern abgelehnt werden.
     *
     * @param  int  $srcW              Breite der Quelle
     * @param  int  $srcH              Höhe der Quelle
     * @param  int  $reqW              gewünschte Breite, 0 = aus dem Seitenverhältnis
     * @param  int  $reqH              gewünschte Höhe, 0 = aus dem Seitenverhältnis
     * @param  bool $isCrop            Box ausfüllen statt einpassen
     * @param  bool $preventUpscaling  nicht über die Originalgröße hinaus vergrößern
     * @return array|null ['width'=>int,'height'=>int,'scale_w'=>int,'scale_h'=>int] oder null
     */
    protected function targetSize(int $srcW, int $srcH, int $reqW, int $reqH, bool $isCrop, bool $preventUpscaling): ?array
    {
        if ($srcW <= 0 || $srcH <= 0) return null;
        // Keine Vorgabe -> Originalgröße beibehalten
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
            // Einpassen: der kleinere der beiden Faktoren gewinnt
            $factor = min($reqW / $srcW, $reqH / $srcH);
            if ($preventUpscaling) $factor = min($factor, 1.0);
            $width  = (int)round($srcW * $factor);
            $height = (int)round($srcH * $factor);
        } elseif ($preventUpscaling && ($width > $srcW || $height > $srcH)) {
            // Nur verkleinern: auf die Originalgröße begrenzen
            $factor = min($srcW / max(1, $width), $srcH / max(1, $height), 1.0);
            $width  = (int)round($width * $factor);
            $height = (int)round($height * $factor);
        }

        $width  = $this->evenValue($width);
        $height = $this->evenValue($height);

        // Beim Beschneiden muss vor dem Schnitt so skaliert werden, dass die
        // Box vollständig bedeckt ist (der größere der beiden Faktoren).
        $scaleW = $width;
        $scaleH = $height;
        if ($isCrop) {
            $factor = max($width / $srcW, $height / $srcH);
            if ($preventUpscaling) $factor = min($factor, 1.0);
            $scaleW = $this->evenValue((int)ceil($srcW * $factor));
            $scaleH = $this->evenValue((int)ceil($srcH * $factor));
            // Die Box kann nach dem Begrenzen größer sein als das skalierte Bild
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
     * Nächstkleinere gerade Zahl, mindestens 2
     */
    protected function evenValue(int $value): int
    {
        $value = (int)(floor($value / 2) * 2);

        return max(2, $value);
    }

    /**
     * Qualitäts-Parameter je nach Modus und Encoder.
     *
     * CRF ist ein Qualitätsziel, die Dateigröße ergibt sich daraus.
     * Bitrate ist ein Größenziel, die Qualität ergibt sich daraus.
     */
    protected function qualityArguments(array $details, string $encoder): array
    {
        $mode = $this->pick((string)($details['quality_mode'] ?? ''), ['crf', 'bitrate'], 'crf');

        if ($mode === 'bitrate') {
            $bitrate = (int)($details['bitrate'] ?? 0);
            if ($bitrate <= 0) return [];

            return ['-b:v', $bitrate . 'k'];
        }

        // Einen Encoder, den der Prozessor nicht kennt (z. B. ein hardware-
        // beschleunigter wie h264_nvenc aus dem Expertenmodus), steuert man
        // nicht zwingend über "-crf". Dann lieber gar keine Qualitätsangabe
        // erzeugen als eine, die ffmpeg zurückweist. Die Datenrate oben ist
        // davon nicht betroffen, "-b:v" versteht jeder Encoder.
        if (!isset(self::CRF_MAX[$encoder])) return [];

        $max = self::CRF_MAX[$encoder] ?? 51;
        $crf = (int)($details['quality'] ?? 23);
        $crf = max(0, min($max, $crf));

        // VP9 wertet -crf nur aus, wenn die Zielbitrate ausdrücklich auf 0 steht
        if ($encoder === 'libvpx-vp9') {
            return ['-crf', (string)$crf, '-b:v', '0'];
        }

        return ['-crf', (string)$crf];
    }

    /**
     * Geschwindigkeits-Voreinstellung in die Schreibweise des jeweiligen Encoders übersetzen
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
     * Parameter für die Tonspur
     *
     * @param string $claimedCodec Ergebnis von claimedCodecValue() für die
     *                             Gruppe audio_codec, '' wenn nicht gesetzt
     */
    protected function audioArguments(array $details, array $claimed, string $claimedCodec = ''): array
    {
        $args     = [];
        $codecKey = $this->pick((string)($details['audio_codec'] ?? ''), array_keys(self::AUDIO_CODECS), 'aac');
        $encoder  = self::AUDIO_CODECS[$codecKey];

        // Auch hier gilt der selbst gesetzte Codec. Sonst landet z. B. bei
        // "-c:a copy" trotzdem ein "-af loudnorm" in der Kommandozeile,
        // und daran bricht ffmpeg ab: filtern lässt sich nur, was auch
        // neu kodiert wird.
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

        // Ohne Neukodierung sind Datenrate, Kanalzahl und Lautheit nicht beeinflussbar
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

        // Lautheitsnormalisierung nach EBU R128.
        // Einmaliger Durchlauf: ffmpeg misst und korrigiert gleichzeitig. Das ist
        // etwas ungenauer als der Zwei-Pass-Betrieb, kostet aber keinen zweiten Lauf.
        if ($reencode && !$claimed['audio_filter'] && !empty($details['audio_normalisation'])) {
            $args[] = '-af';
            $args[] = 'loudnorm=I=-23:LRA=7:TP=-2';
        }

        return $args;
    }

    /**
     * Ermittelt, welche Options-Gruppen der Administrator selbst belegt hat.
     *
     * @param array $tokens Argumente aus parameter1 und parameter2
     * @return array        ['video_filter' => bool, 'video_quality' => bool, ...]
     */
    protected function claimedGroups(array $tokens): array
    {
        $claimed = array_fill_keys(array_keys(self::OPTION_GROUPS), false);

        foreach ($tokens as $token) {
            // Nur Optionen betrachten, keine Werte
            if ($token === '' || $token[0] !== '-') continue;

            // "-c:v:0" und "-b:a:1" auf die Grundform zurückführen
            $option = strtolower(ltrim($token, '-'));
            $option = (string)preg_replace('/:\d+$/', '', $option);

            foreach (self::OPTION_GROUPS as $group => $options) {
                if (in_array($option, $options, true)) $claimed[$group] = true;
            }
        }

        return $claimed;
    }

    /**
     * Liest den Encoder aus, den der Administrator im Expertenmodus selbst
     * gesetzt hat, z. B. 'libvpx-vp9' aus "-c:v libvpx-vp9" oder 'copy' aus
     * "-c copy".
     *
     * claimedGroups() beantwortet nur die Frage OB eine Gruppe belegt ist. Für
     * die Qualitätsstufe und das Preset reicht das nicht: beide sind in ihrer
     * Schreibweise vom Encoder abhängig. Ohne diesen Wert würde der Prozessor
     * seine Parameter weiterhin für den im Formular gewählten Encoder bauen.
     *
     * @param array $tokens  Argumente aus parameter1 und parameter2
     * @param array $options Optionsnamen der Codec-Gruppe, siehe OPTION_GROUPS
     * @return string        Encoder, 'none' bei "-vn"/"-an", '' wenn nichts gesetzt wurde
     */
    protected function claimedCodecValue(array $tokens, array $options): string
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // Nur Optionen betrachten, keine Werte
            if ($token === '' || $token[0] !== '-') continue;

            // "-c:v:0" auf die Grundform zurückführen
            $option = strtolower(ltrim($token, '-'));
            $option = (string)preg_replace('/:\d+$/', '', $option);

            if (!in_array($option, $options, true)) continue;

            // "-vn" und "-an" stehen für sich, sie haben keinen Wert
            if ($option === 'vn' || $option === 'an') return 'none';

            // Der nächste Token ist der Encodername, sofern dort nicht schon
            // die nächste Option beginnt
            $value = $tokens[$i + 1] ?? '';
            if ($value !== '' && $value[0] !== '-') return strtolower($value);
        }

        return '';
    }

    /**
     * Öffentliche Methode, um die Parameter eines per Hintergrundprozess erzeugten Thumbnails
     * in Tabelle `data_fil` nachzutragen
     * 
     * @param string $srcPath   Relativer Pfad zur Datei ab Medienverzeichnis
     * @return array            mittels ffprobe ermittelte Daten + Dateigröße
     *                          width, height, extension, size
     *                          (KEIN mime_type - den kennt ffprobe nicht; er steht
     *                           bereits im `data_fil`-Eintrag des Feldes)
     */
    public function rectifyParams(string $srcPath): array
    {
        // Absoluter Pfad zur Datei
        $srcFile = $this->config->get('paths.media_dir') . '/' . $srcPath;

        // Prüfen, ob die Quelldatei existiert und lesbar ist
        if (!file_exists($srcFile) || !is_readable($srcFile)) {
            return [
                'error' => '#error_file_not_found'
            ];
        }

        // ffmpeg muss verfügbar sein
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

        // Eigenschaften der Datei ermitteln
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
     * Liest die Eigenschaften einer Datei mit ffprobe.
     *
     * @return array|null ['width'=>int, 'height'=>int, 'rotation'=>int, 'fps'=>float,
     *                     'duration'=>float, 'frames'=>int, 'has_audio'=>bool] oder null bei Fehler
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
            // KEIN 'mime_type': ffprobe liefert keinen Mime-Typ, und ein leer
            // vorbelegter Schlüssel würde beim Zusammenführen mit anderen
            // Datenquellen den dort vorhandenen Wert verdrängen (vgl. TriggerMedia)
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
     * Drehung aus den Stream-Informationen lesen.
     * Je nach ffprobe-Version steht sie in side_data_list oder in tags.rotate.
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
     * Bruchschreibweise von ffprobe auswerten, z. B. "30000/1001" -> 29.97
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
     * Schätzt die Gesamtzahl der Frames der ZIELdatei ab.
     *
     * Grundlage ist die Laufzeit, begrenzt durch start_time und duration,
     * multipliziert mit der Bildrate der Ausgabe. Der Wert landet in
     * `bgproc`.`message` und ist der Nenner für die Prozentanzeige.
     *
     * @return int Anzahl Frames, 0 wenn nicht ermittelbar
     */
    protected function estimateFrames(array $details, array $srcInfo): int
    {
        $fps = (float) $srcInfo['fps'];

        $maxFps = (int)($details['max_fps'] ?? 0);
        if ($maxFps > 0 && ($fps <= 0 || $fps > $maxFps)) $fps = (float) $maxFps;
        if ($fps <= 0) return 0;

        $duration = $this->estimateDuration($details, $srcInfo);

        if ($duration <= 0) {
            // Keine Laufzeit bekannt: auf die gemeldete Framezahl der Quelle ausweichen
            return max(0, (int)$srcInfo['frames']);
        }

        return (int)round($duration * $fps);
    }

    /**
     * Schätzt die Laufzeit der ZIELdatei in Sekunden ab:
     * Laufzeit der Quelle, gekürzt um start_time und begrenzt durch duration.
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
     * Zeitangabe prüfen und normalisieren.
     * Erlaubt sind Sekunden ("12.5") und [HH:]MM:SS[.mmm] ("00:01:30.5").
     *
     * @return string normalisierte Angabe oder '' bei ungültiger Eingabe
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
     * Zeitangabe in Sekunden umrechnen
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
     * Wert gegen eine Liste erlaubter Werte prüfen
     */
    protected function pick(string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * Pfad zu ffprobe ermitteln: entweder ausdrücklich konfiguriert oder
     * neben ffmpeg im selben Verzeichnis.
     *
     * @return string Pfad oder '' wenn nicht auffindbar
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
     * Version des konfigurierten ffmpeg auslesen
     *
     * @return string|null z. B. "6.1.1" oder null, wenn ffmpeg nicht läuft
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