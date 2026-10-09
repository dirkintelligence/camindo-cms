<?php
/**
 * Medien-Prozessor FFmpegImage
 *
 * Erzeugt ein Standbild (Thumbnail) aus einem Video mit dem Kommandozeilen-Werkzeug
 * ffmpeg. Die Quelle ist also ein Video, das Ergebnis ist ein Bild.
 *
 * Im Unterschied zu FFmpegVideo wird hier nur EIN einziges Bild dekodiert, das
 * dauert auch bei langen Filmen nur Sekunden.
 *
 * Trotzdem gibt es den HINTERGRUNDPROZESS auch hier (siehe Feld
 * details.background_process): stammt die Quelle selbst aus einem
 * Hintergrundprozess, dann stößt trigger_ff.php diesen Konverter an, sobald das
 * gerenderte Video vorliegt. Das Vorschaubild folgt demselben Weg, damit der
 * Cronjob beide Schritte gleich behandeln kann.
 *
 * Der Prozess meldet sich mit derselben Typkennung wie FFmpegVideo in der
 * Tabelle `bgproc` an und schreibt seinen Fortschritt über "-progress" in eine
 * temporäre Datei unter paths.tmp_dir. Bei einem Standbild steht in
 * `bgproc`.`message` schlicht ein einziges zu berechnendes Bild.
 *
 * Die Quelldatei muss beim Aufruf vorhanden sein, im Vordergrund wie im
 * Hintergrund: die Zielabmessungen werden aus ihren Maßen ausgerechnet.
 *
 * Kommandozeile (Expertenmodus):
 *     ffmpeg [erzeugte Eingabe-Optionen] [parameter1] -i QUELLE
 *            [erzeugte Ausgabe-Optionen] [parameter2] ZIEL
 * parameter1 sind also Eingabe-Optionen, parameter2 Ausgabe-Optionen.
 *
 * Voraussetzungen:
 *   - Konfiguration paths.ffmpeg  (Pfad zur ffmpeg-Binary)
 *   - Konfiguration paths.ffprobe (optional, sonst wird sie neben ffmpeg gesucht)
 *   - Konfiguration paths.tmp_dir (beschreibbares Verzeichnis für die Fortschrittsdateien)
 *   - MediaProcessor stellt $this->bgproc (Camindo\Core\BackgroundProcess) bereit.
 */
namespace Camindo\Core\Processors\FFmpegImage;

use Camindo\Core\MediaProcessor;
use Camindo\Core\BackgroundProcess;

class FFmpegImage extends MediaProcessor {

    /** Zeitlimits in Sekunden */
    protected const TIMEOUT_PROBE   = 30;
    protected const TIMEOUT_VERSION = 10;

    /** Vorgabe für details.max_time_out, falls das Feld leer ist */
    protected const DEFAULT_TIMEOUT = 120;

    /**
     * Typkennung in der Tabelle `bgproc`, danach sucht trigger_ff.php.
     * Bewusst dieselbe wie bei FFmpegVideo: es ist derselbe Cronjob, der die
     * Fortschrittsdatei ausliest und den Datensatz nachträgt.
     */
    public const BGPROC_TYPE = 'ffmpeg';

    /** Präfix und Endung der temporären Fortschrittsdatei */
    public const PROGRESS_PREFIX    = 'ffmpeg-';
    public const PROGRESS_EXTENSION = '.progress';

    /**
     * Zielformat: Auswahlwert => [Encoder, Muxer für "-f", MIME-Type, Farbmodell]
     *
     * Der Muxer wird per "-f" explizit gesetzt, damit die Dateiendung nicht
     * fehlinterpretiert wird. Das Farbmodell gehört zum jeweiligen Encoder:
     * mjpeg erwartet die volle Aussteuerung (yuvj420p), png arbeitet mit
     * echten RGB-Werten, libwebp mit yuv420p.
     */
    public const FORMATS = [
        'jpg'  => ['mjpeg',   'image2', 'image/jpeg', 'yuvj420p'],
        'png'  => ['png',     'image2', 'image/png',  'rgb24'],
        'webp' => ['libwebp', 'webp',   'image/webp', 'yuv420p'],
    ];

    /**
     * Verfahren zur Auswahl des Bildes:
     *   'fixed'   feste Stelle im Film (details.frame_time)
     *   'percent' Anteil der Laufzeit (details.frame_percent)
     *   'smart'   ffmpeg sucht sich aus mehreren Bildern das aussagekräftigste
     *             heraus (Filter "thumbnail", siehe details.scan_frames)
     */
    public const FRAME_MODES = ['fixed', 'percent', 'smart'];

    /** Deinterlacing: Auswahlwert => Filter, '' = nicht deinterlacen */
    public const DEINTERLACE = [
        'no'     => '',
        'auto'   => 'yadif=deint=interlaced',
        'always' => 'yadif',
    ];

    /**
     * Farbmodelle mit halbierten Farbkanälen. Bei ihnen müssen die Kantenlängen
     * gerade sein, sonst weisen die Encoder das Bild zurück. Bei rgb24 (png)
     * darf die gewünschte Größe dagegen exakt eingehalten werden.
     */
    protected const SUBSAMPLED = ['yuvj420p', 'yuv420p', 'yuvj422p', 'yuv422p', 'nv12'];

    /**
     * Nachschärfen nach dem Verkleinern. Beim starken Herunterrechnen eines
     * Videobildes gehen Kanten verloren, die Werte entsprechen einer moderaten,
     * nicht sichtbar überzeichneten Schärfung.
     */
    protected const SHARPEN_FILTER = 'unsharp=5:5:0.8:3:3:0.4';

    /** Grenzen für details.scan_frames im Modus 'smart' */
    protected const SCAN_MIN =   2;
    protected const SCAN_MAX = 500;

    /**
     * Sicherheitsabstand zum Dateiende in Sekunden.
     * Ein "-ss" hinter dem letzten Bild liefert eine leere Ausgabe.
     */
    protected const SEEK_MARGIN = 0.2;

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
     * deshalb bewusst sowohl bei video_codec als auch bei audio.
     */
    protected const OPTION_GROUPS = [
        // Zielformat                   -> ersetzt "-f"
        'format'        => ['f'],
        // Encoder                      -> ersetzt format (Encoder-Teil)
        'video_codec'   => ['c', 'c:v', 'codec', 'codec:v', 'vcodec'],
        // Videofilterkette             -> ersetzt width, height, crop,
        //                                 prevent_upscaling, deinterlace,
        //                                 sharpen und die Auswahl im Modus 'smart'
        'video_filter'  => ['vf', 'filter', 'filter:v', 'filter_complex', 'lavfi'],
        // Bildqualität                 -> ersetzt quality
        'video_quality' => ['q:v', 'qscale:v', 'qscale', 'q', 'crf', 'cq',
                            'quality', 'compression_level', 'lossless', 'b:v'],
        // Farbmodell                   -> ersetzt das Farbmodell des Zielformats
        'pix_fmt'       => ['pix_fmt'],
        // Stelle im Film               -> ersetzt frame_mode, frame_time, frame_percent
        'seek'          => ['ss', 'sseof', 't', 'to', 'itsoffset'],
        // Anzahl der Bilder            -> ersetzt "-frames:v 1"
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
        // Überschreiben                -> ersetzt "-y"
        'overwrite'     => ['y', 'n'],
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
        // Die Quelle ist ein Video, auch wenn das Ergebnis ein Bild ist.
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

        $this->defaults['extension'] = 'jpg';

        // Zusätzliche Eingabefelder für den Konverter
        $this->schema = [
            // Ausgabeformat
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
            // Bildauswahl
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
                // Sekunden (12.5) oder [HH:]MM:SS[.mmm]
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
            // Abmessungen
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
            // Bildbearbeitung
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
            // Ausführung
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
            // Expertenmodus
            // --------------------
            // ffmpeg [parameter1] -i {Quelldatei} [parameter2] {Zieldatei}
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

        // Auswahlfelder auf gültige Werte zurechtbiegen, damit später in
        // convert() keine unbekannten Schlüssel ankommen.
        $details['format']      = $this->pick($details['format']      ?? '', array_keys(self::FORMATS),      'jpg');
        $details['frame_mode']  = $this->pick($details['frame_mode']  ?? '', self::FRAME_MODES,              'percent');
        $details['deinterlace'] = $this->pick($details['deinterlace'] ?? '', array_keys(self::DEINTERLACE),  'auto');

        // Zeitangabe normalisieren, ungültige Eingaben werden verworfen
        $details['frame_time'] = $this->normalizeTime((string)($details['frame_time'] ?? ''));

        // Wertebereiche begrenzen
        $details['quality']       = max(0, min(100, (int)($details['quality'] ?? 80)));
        $details['frame_percent'] = max(0, min(100, (int)($details['frame_percent'] ?? 10)));

        $scan = (int)($details['scan_frames'] ?? 0);
        $details['scan_frames'] = $scan > 0
            ? max(self::SCAN_MIN, min(self::SCAN_MAX, $scan))
            : 100;

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
            return $fileData + ['errors' => 'proc.ffmpegimage.error_binary_missing'];
        }

        $ffprobe = $this->ffprobePath($ffmpeg);
        if ($ffprobe === '') {
            return $fileData + ['errors' => 'proc.ffmpegimage.error_probe_missing'];
        }

        $background = !empty($details['background_process']) || $forceBgproc;

        // Prüfen, ob die Quelldatei existiert und lesbar ist.
        // Auch im Hintergrundbetrieb muss sie jetzt schon da sein: einen
        // Konverter, dessen Quelle selbst noch gerendert wird, stößt
        // trigger_ff.php erst nach dem Ende jenes Prozesses an.
        if (!file_exists($srcFile) || !is_readable($srcFile)) {
            return $fileData + ['errors' => 'error_file_not_found'];
        }

        // Eigenschaften der Quelle ermitteln. Sie werden für die Zielabmessungen
        // und für die Stelle gebraucht, an der das Bild entnommen wird.
        $srcInfo = $this->probe($ffprobe, $srcFile);
        if ($srcInfo === null || $srcInfo['width'] <= 0 || $srcInfo['height'] <= 0) {
            // (kein lesbares Format: writeThumbnails() probiert den nächsten Konverter)
            return $fileData + ['errors' => 'proc.ffmpegimage.error_file_dimensions', 'unsupported' => true];
        }

        // Expertenmodus: freie Parameter zerlegen und auswerten
        $params1 = $this->execute->tokenizeParameters((string)($details['parameter1'] ?? ''));
        $params2 = $this->execute->tokenizeParameters((string)($details['parameter2'] ?? ''));
        $tokens  = array_merge($params1, $params2);
        $claimed = $this->claimedGroups($tokens);
        // Nicht nur OB der Encoder gesetzt wurde, sondern auch WELCHER:
        // davon hängen die Qualitätsangabe und das Farbmodell ab.
        $claimedCodec = $this->claimedCodecValue($tokens, self::OPTION_GROUPS['video_codec']);

        $format  = $this->pick((string)($details['format'] ?? ''), array_keys(self::FORMATS), 'jpg');
        $timeout = (int)($details['max_time_out'] ?? 0);
        if ($timeout <= 0) $timeout = self::DEFAULT_TIMEOUT;

        // Stelle im Film, an der das Bild entnommen wird
        $seek = $this->seekTime($details, $srcInfo);

        // Aus den Formularwerten erzeugte Parameter
        $inputArgs  = $this->buildInputArguments($details, $claimed, $seek);
        $outputArgs = $this->buildOutputArguments($details, $srcInfo, $format, $claimed, $claimedCodec);

        // Zielverzeichnis anlegen (ffmpeg tut das nicht von sich aus)
        $destDir = dirname($destFile);
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);

        // Fortschrittsdatei für den Hintergrundprozess.
        $progressArgs = [];
        if ($background) {
            // Token wird bis in den Hintergrundprozess weitergereicht!
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
            // Angaben für trigger_ff.php, dieselbe Struktur wie bei FFmpegVideo.
            // 'frames'  : ein Standbild ist genau ein Bild
            // 'dur'     : Laufzeit der Quelle
            // 'timeout' : Eintrag aus details.max_time_out
            // 'dest'    : Pfad zur Datei ab media-Verzeichnis
            // ('proc' ergänzt startBackgroundProcess() selbst)
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
                // Infos zur Quell-Datei, soweit sie schon bekannt sind
                'source_width'  => $srcInfo['width'],
                'source_height' => $srcInfo['height'],
                'source_data' => json_encode($srcData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?: '{}',
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

            // Läuft ffmpeg ohne Fehler durch, ohne eine Datei zu schreiben, dann
            // lag die gewählte Stelle hinter dem letzten Bild oder die Quelle
            // enthält an dieser Stelle kein dekodierbares Bild.
            if ($exitCode === $this->execute::EXEC_TIMEOUT) {
                $error = 'proc.ffmpegimage.error_timeout';
            } elseif ($exitCode === 0) {
                $error = 'proc.ffmpegimage.error_no_frame';
            } else {
                $error = 'error_file_conversion';
            }

            return $fileData + [
                'errors' => $error,
                // Klartext-Meldung von ffmpeg, hilfreich für die Fehlersuche
                'error_details' => trim((string)$stderr),
            ];
        }

        // Fertige Zieldatei auslesen und Rückgabestruktur bauen.
        // Die Abmessungen werden nicht berechnet, weil die freien Parameter
        // das Ergebnis beliebig verändern können.
        $destInfo = $this->probe($ffprobe, $destFile);

        // gleicher Dateiname
        $destFilename = $srcData['filename'] ?? 'file';
        return [
            'width'         => $destInfo['width'] ?? 0,
            'height'        => $destInfo['height'] ?? 0,
            'size'          => filesize($destFile),
            'mime_type'     => self::FORMATS[$format][2] ?? ($destInfo['mime_type'] ?? ''),
            'filename'      => $destFilename,
            'extension'     => $ext,
            // Stelle im Film festhalten, an der das Bild entnommen wurde.
            // Hat der Administrator die Stelle im Expertenmodus selbst gesetzt,
            // kennt der Prozessor sie nicht und trägt deshalb nichts ein.
            'data'          => json_encode(
                                    ['frame_at' => $claimed['seek'] ? '' : ($seek === '' ? '0' : $seek)]
                                        + (array)$destInfo,
                                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                               ) ?: '{}',
            // Zusätzliche Infos zur Quell-Datei wurden ermittelt:
            'source_width'  => $srcInfo['width'],
            'source_height' => $srcInfo['height'],
            'source_data'   => json_encode($srcInfo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?: '{}',
        ];
    }

    // Parameter
    // ====================

    /**
     * Erzeugt die Optionen, die VOR "-i" stehen müssen (Eingabe-Optionen).
     *
     * @param array  $details Konfigurations-Array
     * @param array  $claimed Ergebnis von claimedGroups()
     * @param string $seek    Stelle im Film in Sekunden, '' = von vorne
     * @return array          Argumentliste
     */
    protected function buildInputArguments(array $details, array $claimed, string $seek): array
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
        // statt das Material bis dahin zu dekodieren. Bei einem einzelnen Bild
        // aus einem langen Film macht das den Unterschied zwischen Sekunden
        // und Minuten aus.
        if (!$claimed['seek'] && $seek !== '') {
            $args[] = '-ss';
            $args[] = $seek;
        }

        return $args;
    }

    /**
     * Erzeugt die Optionen, die NACH "-i" und vor der Zieldatei stehen (Ausgabe-Optionen).
     * Bereits vom Administrator belegte Options-Gruppen werden übersprungen.
     *
     * @param array  $details      Konfigurations-Array
     * @param array  $srcInfo      Ergebnis von probe() für die Quelldatei
     * @param string $format       Zielformat, Schlüssel aus FORMATS
     * @param array  $claimed      Ergebnis von claimedGroups()
     * @param string $claimedCodec Ergebnis von claimedCodecValue(), '' wenn der
     *                             Administrator den Encoder nicht selbst gesetzt hat
     * @return array               Argumentliste
     */
    protected function buildOutputArguments(array $details, array $srcInfo, string $format, array $claimed, string $claimedCodec = ''): array
    {
        $args = [];

        [$encoder, $muxer, , $pixFmt] = self::FORMATS[$format];

        // Hat der Administrator den Encoder im Expertenmodus selbst gesetzt,
        // dann gilt seiner. Qualitätsangabe und Farbmodell müssen zu DIESEM
        // Encoder passen: "-compression_level" kennt mjpeg nicht, und ein
        // erzwungenes yuvj420p macht aus einem png-Ziel ein Graustufen-Rätsel.
        // Bei einem unbekannten Encoder erzeugt der Prozessor deshalb lieber
        // gar keine Angabe als eine, die ffmpeg zurückweist.
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

        // ---- Videofilter (Bildauswahl, Deinterlace, Skalierung, Beschnitt, Schärfen) ----
        if (!$claimed['video_filter']) {
            // Gerade Kantenlängen sind nur bei halbierten Farbkanälen nötig
            $even    = in_array($pixFmt, self::SUBSAMPLED, true);
            $filters = $this->buildFilterChain($details, $srcInfo, $claimed, $even);
            if ($filters !== []) {
                $args[] = '-vf';
                $args[] = implode(',', $filters);
            }
        }

        // ---- Genau ein Bild schreiben ----
        // Ohne "-update 1" beschwert sich der image2-Muxer, dass im Dateinamen
        // kein Zähler steht, sobald mehr als ein Bild ankommen könnte.
        if (!$claimed['frames']) {
            $args[] = '-frames:v';
            $args[] = '1';
            if ($muxer === 'image2') {
                $args[] = '-update';
                $args[] = '1';
            }
        }

        // ---- Qualität ----
        if ($knownEncoder && !$claimed['video_quality']) {
            $args = array_merge($args, $this->qualityArguments($encoder, (int)($details['quality'] ?? 80)));
        }

        // ---- Farbmodell ----
        if ($knownEncoder && !$claimed['pix_fmt']) {
            $args[] = '-pix_fmt';
            $args[] = $pixFmt;
        }

        // ---- Metadaten entfernen. Videos aus Mobiltelefonen enthalten
        //      regelmäßig Geodaten, die nicht ins Web gehören. ----
        if (!$claimed['metadata'] && !empty($details['strip_meta'])) {
            $args[] = '-map_metadata';
            $args[] = '-1';
        }

        // ---- Ton weglassen: ein Standbild hat keine Tonspur ----
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

        // ---- Zielformat explizit vorgeben, damit die Endung nicht
        //      fehlinterpretiert wird ----
        if (!$claimed['format']) {
            $args[] = '-f';
            $args[] = $muxer;
        }

        return $args;
    }

    /**
     * Baut die Videofilterkette auf.
     *
     * Reihenfolge: erst die Auswahl des Bildes, dann Deinterlacing, dann die
     * Abmessungen, zuletzt das Schärfen. Geschärft wird also nach dem
     * Verkleinern, sonst hebt die Skalierung den Effekt wieder auf.
     *
     * Die Zielabmessungen werden hier in PHP ausgerechnet und nicht ffmpeg
     * überlassen: die Abmessungen der Quelle sind durch probe() bekannt, und
     * ausgerechnete Werte sind erheblich leichter nachzuvollziehen als
     * verschachtelte scale-Ausdrücke.
     *
     * @param bool $even gerade Kantenlängen erzwingen (yuv-Farbmodelle)
     * @return array Liste einzelner Filter, z. B. ['thumbnail=100', 'scale=480:270']
     */
    protected function buildFilterChain(array $details, array $srcInfo, array $claimed, bool $even): array
    {
        $filters = [];

        // ---- Bildauswahl ----
        // Der Filter "thumbnail" puffert eine Anzahl Bilder und gibt daraus das
        // aussagekräftigste zurück. Er steht ganz vorne in der Kette, damit nur
        // das ausgewählte Bild skaliert und geschärft werden muss.
        $mode = $this->pick((string)($details['frame_mode'] ?? ''), self::FRAME_MODES, 'percent');
        if ($mode === 'smart' && !$claimed['frames']) {
            $scan = (int)($details['scan_frames'] ?? 100);
            $scan = max(self::SCAN_MIN, min(self::SCAN_MAX, $scan > 0 ? $scan : 100));
            $filters[] = 'thumbnail=' . $scan;
        }

        // ---- Deinterlacing ----
        $deinterlace = $this->pick((string)($details['deinterlace'] ?? ''), array_keys(self::DEINTERLACE), 'auto');
        if (self::DEINTERLACE[$deinterlace] !== '') $filters[] = self::DEINTERLACE[$deinterlace];

        // ---- Abmessungen ----
        // Bei 90/270 Grad Drehung tauscht die Autorotation Breite und Höhe,
        // die Filterkette sieht das gedrehte Bild.
        $srcW = (int)$srcInfo['width'];
        $srcH = (int)$srcInfo['height'];
        if (!empty($details['auto_rotation']) && in_array(abs((int)$srcInfo['rotation']), [90, 270], true)) {
            [$srcW, $srcH] = [$srcH, $srcW];
        }

        $reqW = !empty($details['width'])  ? (int)$details['width']  : 0;
        $reqH = !empty($details['height']) ? (int)$details['height'] : 0;

        // Beschneiden ist nur sinnvoll, wenn beide Maße vorgegeben sind.
        // Fehlt eines, ergibt es sich aus dem Seitenverhältnis der Quelle,
        // dann sind Einpassen und Ausfüllen identisch.
        $isCrop = !empty($details['crop']) && $reqW > 0 && $reqH > 0;

        $target = $this->targetSize($srcW, $srcH, $reqW, $reqH, $isCrop, !empty($details['prevent_upscaling']), $even);

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

        // ---- Nachschärfen ----
        if (!empty($details['sharpen'])) $filters[] = self::SHARPEN_FILTER;

        return $filters;
    }

    /**
     * Ermittelt die Stelle im Film, an der das Bild entnommen wird.
     *
     * 'fixed'   feste Zeitangabe
     * 'percent' Anteil der Laufzeit, z. B. 10% eines Zwei-Minuten-Films = 12 s
     * 'smart'   die Zeitangabe ist der Beginn des untersuchten Abschnitts,
     *           ausgewählt wird danach vom Filter "thumbnail"
     *
     * Der Wert wird immer auf die Laufzeit begrenzt: hinter dem letzten Bild
     * liefert ffmpeg sonst kommentarlos eine leere Ausgabe.
     *
     * @return string Sekunden als Zeichenkette, '' = von vorne
     */
    protected function seekTime(array $details, array $srcInfo): string
    {
        $mode     = $this->pick((string)($details['frame_mode'] ?? ''), self::FRAME_MODES, 'percent');
        $duration = (float)($srcInfo['duration'] ?? 0);
        $seconds  = 0.0;

        if ($mode === 'percent') {
            // Ohne bekannte Laufzeit lässt sich kein Anteil berechnen,
            // dann bleibt es beim Anfang des Films.
            if ($duration <= 0) return '';
            $percent = max(0, min(100, (int)($details['frame_percent'] ?? 10)));
            $seconds = $duration * $percent / 100;
        } else {
            // 'fixed' und 'smart' benutzen dieselbe Zeitangabe
            $time = $this->normalizeTime((string)($details['frame_time'] ?? ''));
            if ($time === '') return '';
            $seconds = $this->timeToSeconds($time);
        }

        if ($seconds <= 0) return '';

        // Sicherheitsabstand zum Dateiende
        if ($duration > 0) {
            $seconds = min($seconds, max(0.0, $duration - self::SEEK_MARGIN));
        }

        if ($seconds <= 0) return '';

        return rtrim(rtrim(sprintf('%.3f', $seconds), '0'), '.');
    }

    /**
     * Rechnet die Zielabmessungen aus.
     *
     * @param  int  $srcW              Breite der Quelle
     * @param  int  $srcH              Höhe der Quelle
     * @param  int  $reqW              gewünschte Breite, 0 = aus dem Seitenverhältnis
     * @param  int  $reqH              gewünschte Höhe, 0 = aus dem Seitenverhältnis
     * @param  bool $isCrop            Box ausfüllen statt einpassen
     * @param  bool $preventUpscaling  nicht über die Originalgröße hinaus vergrößern
     * @param  bool $even              gerade Kantenlängen erzwingen
     * @return array|null ['width'=>int,'height'=>int,'scale_w'=>int,'scale_h'=>int] oder null
     */
    protected function targetSize(int $srcW, int $srcH, int $reqW, int $reqH, bool $isCrop, bool $preventUpscaling, bool $even): ?array
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

        $width  = $this->roundValue($width, $even);
        $height = $this->roundValue($height, $even);

        // Beim Beschneiden muss vor dem Schnitt so skaliert werden, dass die
        // Box vollständig bedeckt ist (der größere der beiden Faktoren).
        $scaleW = $width;
        $scaleH = $height;
        if ($isCrop) {
            $factor = max($width / $srcW, $height / $srcH);
            if ($preventUpscaling) $factor = min($factor, 1.0);
            $scaleW = $this->roundValue((int)ceil($srcW * $factor), $even);
            $scaleH = $this->roundValue((int)ceil($srcH * $factor), $even);
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
     * Kantenlänge begrenzen.
     *
     * Bei halbierten Farbkanälen (yuv420p und Verwandte) muss die Kantenlänge
     * gerade sein, sonst weisen die Encoder das Bild zurück. Bei rgb24 (png)
     * darf die gewünschte Größe dagegen exakt eingehalten werden.
     */
    protected function roundValue(int $value, bool $even): int
    {
        if (!$even) return max(1, $value);

        // Nächstkleinere gerade Zahl, mindestens 2
        $value = (int)(floor($value / 2) * 2);

        return max(2, $value);
    }

    /**
     * Qualitäts-Parameter je nach Encoder.
     *
     * Nach außen ist die Qualität einheitlich eine Prozentangabe wie beim
     * Magick-Prozessor: 100 = beste Qualität. Jeder Encoder rechnet intern
     * aber mit einer eigenen Skala, teilweise sogar in umgekehrter Richtung.
     *
     * @param int $quality Qualität in Prozent (0-100)
     */
    protected function qualityArguments(string $encoder, int $quality): array
    {
        $quality = max(0, min(100, $quality));

        return match ($encoder) {
            // mjpeg rechnet mit 2 (beste) bis 31 (schlechteste)
            'mjpeg'   => ['-q:v', (string)max(2, min(31, (int)round(31 - $quality * 29 / 100)))],
            // png ist verlustfrei, die Angabe steuert nur den Rechenaufwand
            // beim Packen: 0 = schnell und groß, 9 = langsam und klein
            'png'     => ['-compression_level', (string)max(0, min(9, (int)round(9 - $quality * 9 / 100)))],
            // libwebp benutzt dieselbe Skala wie die Anzeige
            'libwebp' => ['-quality', (string)$quality],
            default   => [],
        };
    }

    /**
     * Passendes Farbmodell zu einem Encoder.
     * Wird gebraucht, wenn der Administrator im Expertenmodus einen eigenen
     * Encoder gesetzt hat.
     *
     * @return string Farbmodell oder '' bei unbekanntem Encoder
     */
    protected function encoderPixFmt(string $encoder): string
    {
        foreach (self::FORMATS as $format) {
            if ($format[0] === $encoder) return $format[3];
        }

        return '';
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

            // "-c:v:0" und "-frames:v:1" auf die Grundform zurückführen
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
     * gesetzt hat, z. B. 'libwebp' aus "-c:v libwebp".
     *
     * claimedGroups() beantwortet nur die Frage OB eine Gruppe belegt ist. Für
     * die Qualitätsangabe und das Farbmodell reicht das nicht: beide sind in
     * ihrer Schreibweise vom Encoder abhängig. Ohne diesen Wert würde der
     * Prozessor seine Parameter weiterhin für den im Formular gewählten
     * Encoder bauen.
     *
     * @param array $tokens  Argumente aus parameter1 und parameter2
     * @param array $options Optionsnamen der Codec-Gruppe, siehe OPTION_GROUPS
     * @return string        Encoder, '' wenn nichts gesetzt wurde
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
                'error' => 'proc.ffmpegimage.error_binary_missing'
            ];
        }

        $ffprobe = $this->ffprobePath($ffmpeg);
        if ($ffprobe === '') {
            return [
                'error' => 'proc.ffmpegimage.error_probe_missing'
            ];
        }

        // Eigenschaften der Datei ermitteln
        $srcInfo = $this->probe($ffprobe, $srcFile);
        if ($srcInfo === null || $srcInfo['width'] <= 0 || $srcInfo['height'] <= 0) {
            return [
                'error' => 'proc.ffmpegimage.error_file_dimensions'
            ];
        }

        // Bei einem Standbild ist die "Laufzeit" ohne Aussage, sie stammt aus der
        // Bildrate des einen Bildes. Sie wird deshalb nicht mitgegeben.
        unset($srcInfo['duration'], $srcInfo['frames']);

        $extension = strtolower(pathinfo($srcFile, PATHINFO_EXTENSION));
        $srcInfo['size']      = filesize($srcFile);
        $srcInfo['extension'] = $extension;
        // Endung -> MIME-Type, damit der Datensatz denselben Wert bekommt wie
        // beim Lauf im Vordergrund
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
     * Liest die Eigenschaften einer Datei mit ffprobe.
     *
     * Die Funktion wird für die Quelle (Video) und für das Ergebnis (Bild)
     * benutzt: ein Standbild ist für ffprobe ein Videostream mit einem Bild.
     *
     * @return array|null ['width'=>int, 'height'=>int, 'rotation'=>int,
     *                     'duration'=>float, 'frames'=>int] oder null bei Fehler
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

            // Der erste Videostream ist der gesuchte, Tonspuren sind hier ohne Belang
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
     * Drehung aus den Stream-Informationen lesen.
     * Je nach ffprobe-Version steht sie in side_data_list oder in tags.rotate.
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
