<?php
/**
 * Medien-Prozessor FFmpegAudio
 *
 * Erzeugt Audio-Versionen mit dem Kommandozeilen-Werkzeug ffmpeg.
 *
 * Anders als bei Video dauert eine Tonumwandlung meist nur Sekunden. Deshalb
 * läuft dieser Prozessor standardmäßig im VORDERGRUND (siehe Feld
 * details.background_process). Für sehr lange Aufnahmen (Predigten, Konzerte,
 * Hörbücher) kann der Hintergrundbetrieb eingeschaltet werden; er arbeitet dann
 * genau wie bei FFmpegVideo mit "-progress" und einer temporären Datei unter
 * paths.tmp_dir.
 *
 * Der Cronjob trigger_ff.php liest diese Datei aus. Weil eine Tonspur keine
 * Frames hat, wird in `bgproc`.`message` bewusst 'frames' => 0 eingetragen; der
 * Fortschritt ergibt sich dann aus 'dur' (Laufzeit der Zieldatei) und
 * out_time_us aus der Fortschrittsdatei.
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
 */
namespace Camindo\Core\Processors\FFmpegAudio;

use Camindo\Core\MediaProcessor;
use Camindo\Core\BackgroundProcess;

class FFmpegAudio extends MediaProcessor {

    /** Zeitlimits in Sekunden */
    protected const TIMEOUT_PROBE   = 30;
    protected const TIMEOUT_VERSION = 10;

    /** Vorgabe für details.max_time_out, falls das Feld leer ist */
    protected const DEFAULT_TIMEOUT = 600;

    /**
     * Typkennung in der Tabelle `bgproc`, danach sucht trigger_ff.php.
     * Bewusst dieselbe wie bei FFmpegVideo: derselbe Cronjob überwacht beide
     * Prozessoren, die Fortschrittsdateien haben dasselbe Format.
     */
    public const BGPROC_TYPE = 'ffmpeg';

    /** Präfix und Endung der temporären Fortschrittsdatei */
    public const PROGRESS_PREFIX    = 'ffmpeg-';
    public const PROGRESS_EXTENSION = '.progress';

    /**
     * Zielcontainer: Auswahlwert => [ffmpeg-Formatname, MIME-Type]
     * Der Formatname wird per "-f" explizit gesetzt, damit die Dateiendung
     * nicht fehlinterpretiert wird. "ipod" ist der Muxer, der ein sauberes
     * .m4a schreibt (mp4-Container ohne Videospur).
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
     * Audio-Codec: Auswahlwert => ffmpeg-Encoder
     * 'auto'  wählt den Standard-Codec des gewählten Containers,
     * 'copy'  übernimmt den Tonstrom unverändert (sehr schnell, kein Filter).
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
     * Welcher Codec passt in welchen Container?
     * Der erste Eintrag ist zugleich die Vorgabe für 'auto'. Wählt der
     * Administrator eine unmögliche Kombination (z. B. AAC in einer .ogg),
     * fällt der Prozessor auf diesen ersten Eintrag zurück.
     * 'copy' ist überall erlaubt, weil nur der Muxer entscheiden kann,
     * ob der vorhandene Strom hineinpasst.
     */
    public const CONTAINER_CODECS = [
        'mp3'  => ['mp3'],
        'm4a'  => ['aac'],
        'ogg'  => ['vorbis', 'opus', 'flac'],
        'opus' => ['opus'],
        'flac' => ['flac'],
        'wav'  => ['pcm_s16'],
    ];

    /** Container, die ein eingebettetes Titelbild tragen können */
    protected const COVER_CONTAINERS = ['mp3', 'm4a', 'flac'];

    /** Verlustfreie Encoder: Datenrate und Qualitätsstufe sind hier ohne Wirkung */
    protected const LOSSLESS = ['flac', 'pcm_s16le'];

    /**
     * Abtastrate: Auswahlwert => Argument für "-ar", '' = beibehalten.
     * Die Schlüssel sind bewusst keine reinen Zahlen, damit PHP sie nicht in
     * Integer verwandelt und der Vergleich in pick() strikt bleiben kann.
     */
    public const SAMPLE_RATES = [
        'keep' => '',
        'r48'  => '48000',
        'r44'  => '44100',
        'r32'  => '32000',
        'r22'  => '22050',
        'r16'  => '16000',
    ];

    /** Kanalzahl: Auswahlwert => Argument für "-ac", '' = beibehalten */
    public const AUDIO_CHANNELS = [
        'keep'   => '',
        'mono'   => '1',
        'stereo' => '2',
    ];

    /**
     * Lautheitsangleichung: Auswahlwert => Filter.
     * Einmaliger Durchlauf (single pass): ffmpeg misst und korrigiert
     * gleichzeitig. Das ist etwas ungenauer als der Zwei-Pass-Betrieb,
     * kostet aber keinen zweiten Lauf.
     */
    public const NORMALISATION = [
        'no'        => '',
        'broadcast' => 'loudnorm=I=-23:LRA=7:TP=-2',
        'web'       => 'loudnorm=I=-16:LRA=11:TP=-1',
    ];

    /** Qualitätssteuerung */
    public const QUALITY_MODES = ['vbr', 'bitrate'];

    /**
     * Umrechnung der Qualitätsstufe (0 = beste) in eine Ziel-Datenrate.
     * Gebraucht für Encoder ohne eigene Qualitätsskala (AAC, Opus).
     */
    protected const QUALITY_BITRATE = [
        0 => 320, 1 => 256, 2 => 224, 3 => 192, 4 => 160,
        5 => 128, 6 => 112, 7 => 96,  8 => 80,  9 => 64,
    ];

    /**
     * Options-Gruppen für den "intelligenten Merge" (Expertenmodus).
     *
     * Belegt der Administrator in parameter1 / parameter2 eine Option aus einer
     * Gruppe, dann erzeugt der Konverter die zugehörigen eigenen Parameter NICHT mehr.
     * Der handgeschriebene Parameter gewinnt also immer, aber nur für "seinen" Bereich.
     * Alle übrigen Formularwerte bleiben wirksam.
     *
     * Ein angehängter Stream-Index wird vorher entfernt ("-c:a:0" -> "c:a"),
     * die Einträge sind danach exakte Treffer. "-c" (gilt für alle Streams) steht
     * deshalb bewusst sowohl bei audio_codec als auch bei cover.
     */
    protected const OPTION_GROUPS = [
        // Zielformat / Container   -> ersetzt "-f"
        'format'         => ['f'],
        // Audio-Codec              -> ersetzt codec
        'audio_codec'    => ['c', 'c:a', 'codec', 'codec:a', 'acodec', 'an'],
        // Datenrate / Qualität     -> ersetzt quality_mode, quality, bitrate
        'audio_quality'  => ['b:a', 'ab', 'q:a', 'qscale:a', 'qscale', 'aq',
                             'vbr', 'compression_level', 'minrate', 'maxrate', 'bufsize'],
        // Abtastrate               -> ersetzt sample_rate
        'sample_rate'    => ['ar', 'sample_rate'],
        // Kanalzahl                -> ersetzt audio_channels
        'audio_channels' => ['ac', 'channel_layout', 'ach'],
        // Audiofilterkette         -> ersetzt normalisation, gain, fade_in, fade_out
        'audio_filter'   => ['af', 'filter', 'filter:a', 'filter_complex', 'lavfi'],
        // Zeitausschnitt           -> ersetzt start_time, duration
        'trim'           => ['ss', 'sseof', 't', 'to'],
        // Metadaten                -> ersetzt strip_meta
        'metadata'       => ['map_metadata', 'map_chapters', 'metadata',
                             'id3v2_version', 'write_id3v1'],
        // Titelbild / Streamauswahl-> ersetzt keep_cover
        'cover'          => ['c', 'c:v', 'codec:v', 'vcodec', 'vn', 'map', 'disposition:v'],
        // Container-Feinheiten     -> ersetzt das automatische +faststart
        'movflags'       => ['movflags'],
        // Threads                  -> ersetzt threads
        'threads'        => ['threads', 'filter_threads', 'filter_complex_threads'],
        // Überschreiben            -> ersetzt "-y"
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
        //
        // Sollen aus Videodateien Tonspuren gezogen werden (z. B. Predigt-Video
        // -> Podcast-MP3), können hier zusätzlich video/* Typen eingetragen
        // werden. Die Videospur wird ohnehin verworfen.
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

        // Zusätzliche Eingabefelder für den Konverter
        $this->schema = [
            // Container und Codec
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
            // Qualität
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
            // Tonsignal
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
            // Lautheit
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
            // Zeit
            // --------------------
            'details.start_time' => [
                'type'  => 'chars',
                'label' => '#proc.ffmpegaudio.field_start_time',
                'note' => '#proc.ffmpegaudio.note_start_time',
                'tooltip' => '#proc.ffmpegaudio.tooltip_field_start_time',
                'default' => '',
                // Sekunden (12.5) oder [HH:]MM:SS[.mmm]
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
            // Metadaten
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
            // Ausführung
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
            // Expertenmodus
            // --------------------
            // ffmpeg [parameter1] -i {Quelldatei} [parameter2] {Zieldatei}
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

        // Auswahlfelder auf gültige Werte zurechtbiegen, damit später in
        // convert() keine unbekannten Schlüssel ankommen.
        $details['container']      = $this->pick($details['container']      ?? '', array_keys(self::CONTAINERS),     'mp3');
        $details['codec']          = $this->pick($details['codec']          ?? '', array_keys(self::AUDIO_CODECS),   'auto');
        $details['quality_mode']   = $this->pick($details['quality_mode']   ?? '', self::QUALITY_MODES,              'vbr');
        $details['sample_rate']    = $this->pick($details['sample_rate']    ?? '', array_keys(self::SAMPLE_RATES),   'keep');
        $details['audio_channels'] = $this->pick($details['audio_channels'] ?? '', array_keys(self::AUDIO_CHANNELS), 'keep');
        $details['normalisation']  = $this->pick($details['normalisation']  ?? '', array_keys(self::NORMALISATION),  'no');

        // Codec und Container müssen zusammenpassen. Passt die Auswahl nicht,
        // wird sie auf den Standard-Codec des Containers zurückgesetzt, damit
        // im Formular sichtbar wird, was tatsächlich verwendet wird.
        $details['codec'] = $this->resolveCodecKey($details['codec'], $details['container']);

        // Zeitangaben normalisieren, ungültige Eingaben werden verworfen
        $details['start_time'] = $this->normalizeTime((string)($details['start_time'] ?? ''));
        $details['duration']   = $this->normalizeTime((string)($details['duration'] ?? ''));
        $details['fade_in']    = $this->normalizeSeconds((string)($details['fade_in'] ?? ''));
        $details['fade_out']   = $this->normalizeSeconds((string)($details['fade_out'] ?? ''));

        // Qualitätsstufe und Pegelkorrektur begrenzen
        $details['quality'] = max(0, min(9, (int)($details['quality'] ?? 4)));
        $details['gain']    = max(-30, min(30, (int)($details['gain'] ?? 0)));

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
     * width und height sind bei Tondateien immer 0.
     *
     * Im Hintergrundbetrieb ist die Zieldatei beim Rücksprung noch NICHT fertig.
     * Die Rückgabe enthält dann 'size' => 0 und zusätzlich
     *     'pending' => true, 'bgproc_id' => int, 'pid' => int, 'token' => string
     * Der Aufrufer sollte den Datensatz in diesem Fall als "in Arbeit" markieren
     * und die endgültigen Werte nachtragen, sobald der Prozess beendet ist.
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

        // ffmpeg muss verfügbar sein
        $ffmpeg = (string)($this->config->get('paths.ffmpeg') ?? '');
        if ($ffmpeg === '' || !is_executable($ffmpeg)) {
            return $fileData + ['errors' => 'proc.ffmpegaudio.error_binary_missing'];
        }

        $ffprobe = $this->ffprobePath($ffmpeg);
        if ($ffprobe === '') {
            return $fileData + ['errors' => 'proc.ffmpegaudio.error_probe_missing'];
        }

        // Prüfen, ob die Quelldatei existiert und lesbar ist
        if (!file_exists($srcFile) || !is_readable($srcFile)) {
            return $fileData + ['errors' => 'error_file_not_found'];
        }

        // Eigenschaften der Quelle ermitteln. Sie werden für die Abtastrate,
        // die Ausblendung am Ende und die Laufzeit im Hintergrundbetrieb gebraucht.
        $srcInfo = $this->probe($ffprobe, $srcFile);
        // (kein lesbares Format / keine Tonspur: writeThumbnails() probiert den nächsten Konverter)
        if ($srcInfo === null) {
            return $fileData + ['errors' => 'proc.ffmpegaudio.error_file_not_supported', 'unsupported' => true];
        }
        if (!$srcInfo['has_audio']) {
            return $fileData + ['errors' => 'proc.ffmpegaudio.error_no_audio', 'unsupported' => true];
        }

        // Expertenmodus: freie Parameter zerlegen und auswerten
        $params1 = $this->execute->tokenizeParameters((string)($details['parameter1'] ?? ''));
        $params2 = $this->execute->tokenizeParameters((string)($details['parameter2'] ?? ''));
        $claimed = $this->claimedGroups(array_merge($params1, $params2));
        // Nicht nur OB der Codec selbst gesetzt wurde, sondern auch WELCHER:
        // davon hängen Qualitätsstufe und Filterkette ab.
        $claimedCodec = $this->claimedCodecValue(array_merge($params1, $params2), self::OPTION_GROUPS['audio_codec']);

        $container = $this->pick((string)($details['container'] ?? ''), array_keys(self::CONTAINERS), 'mp3');
        $timeout   = (int)($details['max_time_out'] ?? 0);
        if ($timeout <= 0) $timeout = self::DEFAULT_TIMEOUT;

        // Aus den Formularwerten erzeugte Parameter
        $inputArgs  = $this->buildInputArguments($details, $claimed);
        $outputArgs = $this->buildOutputArguments($details, $srcInfo, $container, $claimed, $claimedCodec);

        // Zielverzeichnis anlegen (ffmpeg z. B. tut das nicht von sich aus)
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
                return $fileData + ['errors' => 'proc.ffmpegaudio.error_tmp_dir'];
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
            // trigger_ff.php liest diese Werte aus `message` wieder aus.
            // 'frames'  : bei Ton immer 0 - eine Tonspur hat keine Frames,
            //             der Fortschritt läuft deshalb über die Laufzeit
            // 'dur'     : Laufzeit der Zieldatei in Sekunden
            // 'timeout' : Eintrag aus details.max_time_out
            // 'dest'    : Pfad zur Datei ab media-Verzeichnis
            // ('proc' ergänzt startBackgroundProcess() selbst)
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
                // Infos zur Quell-Datei
                'source_width'  => 0,
                'source_height' => 0,
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
                    ? 'proc.ffmpegaudio.error_timeout'
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
            // Tondateien haben keine Abmessungen
            'width'         => 0,
            'height'        => 0,
            'size'          => filesize($destFile),
            'mime_type'     => self::CONTAINERS[$container][1] ?? ($destInfo['mime_type'] ?? ''),
            'filename'      => $destFilename,
            'extension'     => $ext,
            'data'          => json_encode($destInfo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?? '{}',
            // Zusätzliche Infos zur Quell-Datei wurden ermittelt:
            'source_width'  => 0,
            'source_height' => 0,
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
     *                             Administrator den Codec nicht selbst gesetzt hat
     * @return array            Argumentliste
     */
    protected function buildOutputArguments(array $details, array $srcInfo, string $container, array $claimed, string $claimedCodec = ''): array
    {
        $args = [];

        $codecKey = $this->resolveCodecKey((string)($details['codec'] ?? ''), $container);
        $encoder  = self::AUDIO_CODECS[$codecKey] ?? 'libmp3lame';

        // Hat der Administrator den Codec im Expertenmodus selbst gesetzt, dann
        // gilt seiner. Sonst landet z. B. bei "-c:a copy" trotzdem ein
        // "-af loudnorm" in der Kommandozeile, und daran bricht ffmpeg ab:
        // filtern lässt sich nur, was auch neu kodiert wird.
        if ($claimedCodec !== '') $encoder = $claimedCodec;

        // 'copy' schließt jede Bearbeitung des Tons aus: ohne Neukodierung gibt
        // es weder Filter noch Datenraten noch eine andere Kanalzahl.
        $reencode = !in_array($encoder, ['', 'copy', 'none'], true);

        // ---- Audio-Codec ----
        if (!$claimed['audio_codec']) {
            $args[] = '-c:a';
            $args[] = $codecKey === 'copy' ? 'copy' : (self::AUDIO_CODECS[$codecKey] ?? 'libmp3lame');
        }

        // ---- Datenrate / Qualitätsstufe ----
        if ($reencode && !$claimed['audio_quality']) {
            $args = array_merge($args, $this->qualityArguments($details, $encoder));
        }

        // ---- Abtastrate ----
        if ($reencode && !$claimed['sample_rate']) {
            $rate = $this->sampleRateValue($details);
            if ($rate !== '') {
                $args[] = '-ar';
                $args[] = $rate;
            }
        }

        // ---- Kanalzahl ----
        if ($reencode && !$claimed['audio_channels']) {
            $channels = $this->pick((string)($details['audio_channels'] ?? ''), array_keys(self::AUDIO_CHANNELS), 'keep');
            if (self::AUDIO_CHANNELS[$channels] !== '') {
                $args[] = '-ac';
                $args[] = self::AUDIO_CHANNELS[$channels];
            }
        }

        // ---- Filterkette (Lautheit, Pegel, Ein- und Ausblenden) ----
        if ($reencode && !$claimed['audio_filter']) {
            $filters = $this->buildFilterChain($details, $srcInfo, $claimed);
            if ($filters !== []) {
                $args[] = '-af';
                $args[] = implode(',', $filters);
            }
        }

        // ---- Laufzeit begrenzen (z. B. für Hörproben) ----
        if (!$claimed['trim']) {
            $duration = $this->normalizeTime((string)($details['duration'] ?? ''));
            if ($duration !== '') {
                $args[] = '-t';
                $args[] = $duration;
            }
        }

        // ---- Titelbild ----
        $args = array_merge($args, $this->coverArguments($details, $srcInfo, $container, $claimed));

        // ---- Metadaten entfernen. Bei Ton sind das die Tags (Titel, Interpret,
        //      Album), die man meistens behalten will - deshalb standardmäßig aus. ----
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

        // ---- Index an den Anfang der Datei: die Datei kann dann abgespielt
        //      werden, bevor sie vollständig geladen ist. Nur der mp4-Container
        //      (m4a) kennt diese Umsortierung, mp3 und ogg brauchen sie nicht. ----
        if (!$claimed['movflags'] && $container === 'm4a') {
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
     * Baut die Audiofilterkette auf.
     *
     * Die Reihenfolge ist bewusst gewählt:
     *   1. loudnorm  - misst und korrigiert die Lautheit des Originals
     *   2. volume    - bewusster Versatz gegenüber dem Ziel der Normalisierung
     *   3. afade in  - am Anfang
     *   4. afade out - am Ende, deshalb erst nach dem Beschnitt berechenbar
     *
     * @return array Liste einzelner Filter, z. B. ['loudnorm=I=-16:LRA=11:TP=-1', 'afade=t=in:st=0:d=2']
     */
    protected function buildFilterChain(array $details, array $srcInfo, array $claimed): array
    {
        $filters = [];

        // ---- Lautheit angleichen ----
        $mode = $this->pick((string)($details['normalisation'] ?? ''), array_keys(self::NORMALISATION), 'no');
        if (self::NORMALISATION[$mode] !== '') {
            $filters[] = self::NORMALISATION[$mode];

            // loudnorm rechnet intern mit 192 kHz und gibt das auch so aus.
            // Ist keine Abtastrate vorgegeben, würde die Zieldatei ohne diesen
            // Schritt also unnötig groß und für manche Encoder unbrauchbar.
            if ($this->sampleRateValue($details) === '') {
                $rate = (int)($srcInfo['sample_rate'] ?? 0);
                $filters[] = 'aresample=' . ($rate > 0 ? $rate : 48000);
            }
        }

        // ---- Pegel anheben oder absenken ----
        $gain = (int)($details['gain'] ?? 0);
        if ($gain !== 0) {
            $filters[] = 'volume=' . $gain . 'dB';
        }

        // ---- Einblenden ----
        $fadeIn = (float)$this->normalizeSeconds((string)($details['fade_in'] ?? ''));
        if ($fadeIn > 0) {
            $filters[] = 'afade=t=in:st=0:d=' . $this->trimFloat($fadeIn);
        }

        // ---- Ausblenden ----
        // Der Einsatzpunkt liegt am Ende der ZIELdatei, also nach Startzeit und
        // Längenbegrenzung. Ist die Laufzeit unbekannt (Streams ohne Angabe),
        // wird nicht ausgeblendet: ein falsch gesetzter Einsatzpunkt wäre
        // schlimmer als gar keine Ausblendung.
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
     * Qualitäts-Parameter je nach Modus und Encoder.
     *
     * VBR ist ein Qualitätsziel, die Dateigröße ergibt sich daraus.
     * Datenrate ist ein Größenziel, die Qualität ergibt sich daraus.
     *
     * Jeder Encoder hat seine eigene Skala, deshalb wird die Stufe 0-9 aus dem
     * Formular hier übersetzt:
     *   libmp3lame  -q:a 0-9 (0 = beste), also unverändert
     *   libvorbis   -q:a 0-10 (10 = beste), also umgekehrt
     *   libopus     kennt keine Stufen, arbeitet aber von Haus aus mit VBR:
     *               die Stufe wird in eine Zieldatenrate übersetzt
     *   aac         der eingebaute Encoder hat keine brauchbare VBR-Stufe,
     *               deshalb ebenfalls über die Zieldatenrate
     *   flac        verlustfrei, die Stufe steuert die Rechenzeit beim Packen
     *   pcm         unkomprimiert, hier gibt es nichts einzustellen
     *
     */
    protected function qualityArguments(array $details, string $encoder): array
    {
        // Verlustfreie Formate: Datenrate und Qualität sind bedeutungslos
        if ($encoder === 'pcm_s16le') return [];

        $mode    = $this->pick((string)($details['quality_mode'] ?? ''), self::QUALITY_MODES, 'vbr');
        $quality = max(0, min(9, (int)($details['quality'] ?? 4)));

        // Einen Encoder, den der Prozessor nicht kennt (z. B. libfdk_aac aus dem
        // Expertenmodus), steuert man nicht zwingend über "-q:a" oder
        // "-compression_level": beide sind encoder-spezifisch und würden ffmpeg
        // zum Abbruch bringen. Die Qualität wird dann über die allgemein
        // gültige Ziel-Datenrate ausgedrückt, die jeder Encoder versteht.
        if (!in_array($encoder, ['libmp3lame', 'libvorbis', 'libopus', 'aac', 'flac'], true)) {
            $bitrate = $mode === 'bitrate'
                ? (int)($details['bitrate'] ?? 0)
                : (int)(self::QUALITY_BITRATE[$quality] ?? 128);

            return $bitrate > 0 ? ['-b:a', $bitrate . 'k'] : [];
        }

        if ($encoder === 'flac') {
            // 0 (beste Stufe im Formular) = stärkste Packung, dafür langsamer
            $level = max(0, min(12, 12 - $quality));

            return ['-compression_level', (string)$level];
        }

        if ($mode === 'bitrate') {
            $bitrate = (int)($details['bitrate'] ?? 0);
            if ($bitrate <= 0) return [];

            // Opus arbeitet ohne diesen Schalter auch im Datenraten-Modus variabel
            if ($encoder === 'libopus') {
                return ['-b:a', $bitrate . 'k', '-vbr', 'off'];
            }

            return ['-b:a', $bitrate . 'k'];
        }

        return match ($encoder) {
            'libmp3lame' => ['-q:a', (string)$quality],
            // 0-9 (0 = beste) auf 10-0 (10 = beste) umrechnen
            'libvorbis'  => ['-q:a', (string)(int)round((9 - $quality) * 10 / 9)],
            'libopus'    => ['-b:a', (self::QUALITY_BITRATE[$quality] ?? 128) . 'k', '-vbr', 'on'],
            'aac'        => ['-b:a', (self::QUALITY_BITRATE[$quality] ?? 128) . 'k'],
            default      => [],
        };
    }

    /**
     * Parameter für ein eingebettetes Titelbild.
     *
     * Ohne Zutun würde ffmpeg ein vorhandenes Titelbild als Videostrom
     * behandeln und je nach Container abbrechen. Deshalb wird die Bildspur
     * grundsätzlich verworfen und nur dann durchgereicht, wenn
     *   - der Administrator es wünscht,
     *   - der Zielcontainer Titelbilder kennt und
     *   - die Quelle tatsächlich ein Titelbild trägt (und nicht etwa ein
     *     komplettes Video, aus dem hier nur der Ton gezogen wird).
     */
    protected function coverArguments(array $details, array $srcInfo, string $container, array $claimed): array
    {
        if ($claimed['cover']) return [];

        $keep = !empty($details['keep_cover'])
            && !empty($srcInfo['has_cover'])
            && in_array($container, self::COVER_CONTAINERS, true);

        // Keine Bildspur in die Zieldatei
        if (!$keep) return ['-vn'];

        $args = ['-c:v', 'copy', '-disposition:v', 'attached_pic'];

        // Titelbilder in MP3 stehen in einem ID3v2-Rahmen. Version 3 ist die,
        // die von Windows, iTunes und den meisten Playern gelesen wird.
        if ($container === 'mp3') {
            $args[] = '-id3v2_version';
            $args[] = '3';
        }

        return $args;
    }

    /**
     * Ermittelt, welche Options-Gruppen der Administrator selbst belegt hat.
     *
     * @param array $tokens Argumente aus parameter1 und parameter2
     * @return array        ['audio_filter' => bool, 'audio_quality' => bool, ...]
     */
    protected function claimedGroups(array $tokens): array
    {
        $claimed = array_fill_keys(array_keys(self::OPTION_GROUPS), false);

        foreach ($tokens as $token) {
            // Nur Optionen betrachten, keine Werte
            if ($token === '' || $token[0] !== '-') continue;

            // "-c:a:0" und "-b:a:1" auf die Grundform zurückführen
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
     * gesetzt hat, z. B. 'libopus' aus "-c:a libopus" oder 'copy' aus "-c copy".
     *
     * claimedGroups() beantwortet nur die Frage OB eine Gruppe belegt ist. Für
     * die Qualitätsstufe reicht das nicht: sie ist in ihrer Schreibweise vom
     * Encoder abhängig. Ohne diesen Wert würde der Prozessor seine Parameter
     * weiterhin für den im Formular gewählten Encoder bauen.
     *
     * @param array $tokens  Argumente aus parameter1 und parameter2
     * @param array $options Optionsnamen der Codec-Gruppe, siehe OPTION_GROUPS
     * @return string        Encoder, 'none' bei "-an"/"-vn", '' wenn nichts gesetzt wurde
     */
    protected function claimedCodecValue(array $tokens, array $options): string
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // Nur Optionen betrachten, keine Werte
            if ($token === '' || $token[0] !== '-') continue;

            // "-c:a:0" auf die Grundform zurückführen
            $option = strtolower(ltrim($token, '-'));
            $option = (string)preg_replace('/:\d+$/', '', $option);

            if (!in_array($option, $options, true)) continue;

            // "-an" und "-vn" stehen für sich, sie haben keinen Wert
            if ($option === 'an' || $option === 'vn') return 'none';

            // Der nächste Token ist der Encodername, sofern dort nicht schon
            // die nächste Option beginnt
            $value = $tokens[$i + 1] ?? '';
            if ($value !== '' && $value[0] !== '-') return strtolower($value);
        }

        return '';
    }

    /**
     * Öffentliche Methode, um die Parameter einer per Hintergrundprozess erzeugten
     * Datei in Tabelle `data_fil` nachzutragen
     *
     * @param string $srcPath   Relativer Pfad zur Datei ab Medienverzeichnis
     * @return array            mittels ffprobe ermittelte Daten + Dateigröße
     *                          width, height, mime_type, extension, size
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
                'error' => 'proc.ffmpegaudio.error_binary_missing'
            ];
        }

        $ffprobe = $this->ffprobePath($ffmpeg);
        if ($ffprobe === '') {
            return [
                'error' => 'proc.ffmpegaudio.error_probe_missing'
            ];
        }

        // Eigenschaften der Datei ermitteln
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

    // Hilfsfunktionen
    // ====================

    /**
     * Liest die Eigenschaften einer Datei mit ffprobe.
     *
     * width und height bleiben 0: sie stehen in der Rückgabe nur, weil der
     * Aufrufer sie erwartet. Eine Tondatei hat keine Abmessungen.
     *
     * @return array|null ['duration'=>float, 'sample_rate'=>int, 'channels'=>int,
     *                     'bitrate'=>int, 'codec'=>string, 'has_audio'=>bool,
     *                     'has_cover'=>bool, 'has_video'=>bool] oder null bei Fehler
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
                // Ein Bild mit der Kennzeichnung "attached_pic" ist ein
                // Titelbild, alles andere ist eine echte Videospur.
                if (!empty($stream['disposition']['attached_pic'])) {
                    $info['has_cover'] = true;
                } else {
                    $info['has_video'] = true;
                }
                continue;
            }
            // Nur den ersten Tonstrom auswerten
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
     * Codec-Auswahl mit dem Container abgleichen.
     *
     * 'auto' wird zum Standard-Codec des Containers, eine unmögliche
     * Kombination ebenfalls. 'copy' bleibt immer stehen: ob der vorhandene
     * Tonstrom in den Container passt, kann erst der Muxer entscheiden.
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
     * Abtastrate aus dem Formular als Argument für "-ar", '' = beibehalten
     */
    protected function sampleRateValue(array $details): string
    {
        $key = $this->pick((string)($details['sample_rate'] ?? ''), array_keys(self::SAMPLE_RATES), 'keep');

        return self::SAMPLE_RATES[$key];
    }

    /**
     * Schätzt die Laufzeit der ZIELdatei in Sekunden ab:
     * Laufzeit der Quelle, gekürzt um start_time und begrenzt durch duration.
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
     * Sekundenangabe für die Blenden prüfen ("2" oder "1.5").
     *
     * @return string normalisierte Angabe oder '' bei ungültiger Eingabe
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
     * Zeitangabe in Sekunden umrechnen
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
     * Fließkommazahl für die Kommandozeile: höchstens drei Nachkommastellen,
     * immer mit Punkt, ohne überflüssige Nullen.
     */
    protected function trimFloat(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
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
