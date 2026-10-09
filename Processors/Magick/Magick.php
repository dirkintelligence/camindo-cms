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
        /** Eigene policy.xml, vor jedem Aufruf aktiv (MagickPolicy, 18-imagemagick.md 2.2.2) */
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
     * Älteste ImageMagick-Version, die als unbedenklich gilt.
     * Darunter markiert die Konverter-Infoseite die Version als veraltet
     * (siehe developer_docs/18-imagemagick.md, 2.2.7).
     */
    public const MIN_VERSION = '7.1.1';

    /** Anpassung an das Zielformat (details.crop) */
    public const FIT     = 0;   // ganz einpassen
    public const CROP    = 1;   // ausfüllen und beschneiden (^ + -extent)
    public const FILL    = 2;   // ausfüllen, überstehen lassen (^)
    public const DISTORT = 3;   // exakt auf das Format, Seitenverhältnis ignorieren (!)

    /** Bedingung (details.resize_when) -> Kennzeichen der Geometrie */
    protected const RESIZE_FLAGS = ['always' => '', 'shrink' => '>', 'enlarge' => '<'];

    /** EXIF-Ausrichtungen, bei denen -auto-orient um 90° dreht (Breite und Höhe tauschen) */
    protected const ORIENTATION_ROTATED = ['LeftTop', 'RightTop', 'RightBottom', 'LeftBottom'];

    /** Typkennung in der Tabelle `bgproc`, danach sucht trigger_magick.php */
    public const BGPROC_TYPE = 'magick';

    /** Präfix und Endung der temporären Fortschrittsdatei */
    public const PROGRESS_PREFIX    = 'magick-';
    public const PROGRESS_EXTENSION = '.progress';

    /**
     * Zuordnung Dateiendung -> MIME-Type des Zielformats.
     * Wird auch benutzt, um zu entscheiden, ob dem Zielpfad ein
     * explizites Format-Prefix (z. B. "webp:/pfad/datei.webp") vorangestellt wird.
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
     * Zulässige QUELLFORMATE: serverseitig erkannter MIME-Typ -> ImageMagick-Coder.
     *
     * ImageMagick bestimmt das Format sonst am INHALT der Datei, nicht an der Endung:
     * eine "bild.jpg", die in Wahrheit MVG- oder MSL-Text enthält, wird als Skript
     * ausgeführt (ImageTragick, CVE-2016-3714 ff.). Mit vorangestelltem Coder
     * ("jpeg:/pfad/bild.jpg") liest ImageMagick die Datei nur noch mit diesem einen
     * Coder; passt der Inhalt nicht, bricht es ab, statt in einen Skript- oder
     * Pseudo-Coder (MVG, MSL, TEXT, URL, FILE, EPHEMERAL ...) zu fallen.
     *
     * Ein MIME-Typ, der hier fehlt, wird nicht verarbeitet: convert() meldet
     * 'unsupported', writeThumbnails() nimmt den nächsten Konverter.
     *
     * SVG und PDF stehen bewusst NICHT in den Defaults von media_types_incl und
     * laufen nur, wenn security.magick_allow sie freigibt (MagickPolicy). SVG geht
     * über den internen Renderer 'msvg' - der Coder 'svg' riefe zuerst inkscape bzw.
     * rsvg-convert als Delegate auf, und Delegates sperrt die Policy.
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
     * Zwei Aufgaben: Ersatz für CODER_BY_MIME, wenn zu einer Quelldatei kein
     * (brauchbarer) MIME-Typ vorliegt - der Coder wird trotzdem erzwungen, also
     * bleibt das Sniffing aus; und Format-Präfix der ZIELdatei, damit die Ausgabe
     * nicht anhand der Endung in einem fremden Coder landet.
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
     * Options-Gruppen für den "intelligenten Merge" (Expertenmodus).
     *
     * Belegt der Administrator in parameters1 / parameters2 eine Option aus einer
     * Gruppe, dann erzeugt der Konverter die zugehörigen eigenen Parameter NICHT mehr.
     * Der handgeschriebene Parameter gewinnt also immer, aber nur für "seinen" Bereich.
     * Alle übrigen Formularwerte bleiben wirksam.
     */
    protected const OPTION_GROUPS = [
        // Zielabmessungen / Beschnitt  -> ersetzt width, height, crop
        'geometry'   => ['resize', 'thumbnail', 'sample', 'scale', 'adaptive-resize',
                         'crop', 'extent', 'geometry', 'distort', 'liquid-rescale',
                         'trim', 'shave'],
        // Ausrichtung des Beschnitts   -> ersetzt nur "-gravity center"
        'gravity'    => ['gravity'],
        // Hintergrundfarbe             -> ersetzt bgcolor
        'background' => ['background'],
        // Transparenz                  -> ersetzt alpha
        'alpha'      => ['alpha', 'flatten', 'matte', 'transparent', 'opaque'],
        // Farbreduktion                -> ersetzt indexed_colors
        'colors'     => ['colors', 'remap', 'map', 'monochrome', 'type', 'dither', 'posterize'],
        // Qualität                     -> ersetzt quality
        'quality'    => ['quality'],
        // Metadaten                    -> ersetzt "-strip"
        'strip'      => ['strip', 'profile', 'thumbnail'],
        // Drehung                      -> ersetzt "-auto-orient"
        'orient'     => ['auto-orient', 'orient', 'rotate', 'flip', 'flop',
                         'transpose', 'transverse'],
        // Einzelbilder / Animation     -> unterdrückt die Frame-Auswahl "[0]" an der Quelle
        'frames'     => ['coalesce', 'layers', 'delete', 'scene', 'adjoin',
                         'deconstruct', 'flatten', 'append', 'duplicate'],
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
        // Defaultwerte für Eingabefelder des Konverters
        // ImageMagick verarbeitet deutlich mehr Formate als GD.
        // Welche davon tatsächlich zur Verfügung stehen, hängt von den
        // installierten Delegates ab ("magick -list format").
        //
        // NICHT vorbelegt sind "image/svg+xml" und "application/pdf": beide werden
        // nicht von ImageMagick selbst gelesen, sondern an librsvg bzw. Ghostscript
        // weitergereicht - die gefährlichsten Delegationen (XXE, -dSAFER-Umgehungen).
        // Wer sie braucht, trägt sie bewusst ein; der Hinweis dazu steht unter den
        // Medientypen im Konverter-Formular (#proc.magick.note_media_types).
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

        // Zusätzliche Eingabefelder für den Konverter
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
            // Größe: Anpassung an das Zielformat, Bedingung, Einheit, Höchstzahl Pixel -
            // die Kennzeichen der Geometrie (^ ! > < % @), die an der Zielgröße hängen
            // und sich deshalb nicht über parameters1/2 ergänzen lassen (buildArguments())
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
        // Auswahlfelder: nur die Werte aus dem Schema (auch über die API)
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

        // Expertenmodus: Zeilenumbrüche und Mehrfach-Leerzeichen entfernen,
        // damit die gespeicherte Kommandozeile immer einzeilig bleibt.
        foreach (['parameters1', 'parameters2'] as $key) {
            $value = trim((string) ($data['details'][$key] ?? ''));
            $data['details'][$key] = (string) preg_replace('/\s+/', ' ', $value);
        }

        // Läuft unter paths.magick überhaupt ein ImageMagick? Der Konverter wäre sonst
        // von vornherein wirkungslos - wie bei den FFmpeg-Prozessoren bricht das
        // Speichern deshalb ab. Eine zu ALTE Version blockiert dagegen nicht: sie
        // arbeitet ja, sie ist nur ungepatcht. Darauf weist die Infoseite des
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
     * Erzeugt eine Dateiversion basierend auf den übergebenen Parametern
     *
     * @param string $srcPath   Pfad zur Quelldatei, relativ zu media_dir
     * @param array  $srcData   Daten der Quell-Datei
     *      'extension' => $fileExtension,
     *      'mime_type' => $fileMime,
     *      'size'      => $fileSize,        // in bytes
     *      'filename'  => $fileOriginName,  // ursprünglicher Dateiname, gegen Injections gesäubert
     *      'field_id'  => $fieldId,         // für Datei-Pfad / `data_fil`-Eintrag beim checkIn
     * @param string $destPath  Pfad zur Zieldatei, relativ zu media_dir
     * @param array  $details   Konfigurations-Array
     * @param bool $forceBgproc true: erzwingt Ausführung als Hintergrundprozess
     * @return array            Daten der Zieldatei, bzw ['size'=>0, 'errors'=>'...']
     */
    public function convert(string $srcPath, array $srcData, string $destPath, array $details, bool $forceBgproc): array
    {
        $mediaDir = $this->config->get('paths.media_dir');
        $srcFile = $mediaDir . DIRECTORY_SEPARATOR . ltrim($srcPath, '/\\');
        $destFile =  $mediaDir . DIRECTORY_SEPARATOR . ltrim($destPath, '/\\');
        // Endung der ZIELdatei: der Konverter gibt sie vor, sonst erbt sie die
        // Quelldatei (writeThumbnails() legt $details['extension'] dazu).
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

        // ImageMagick (Version 7, Kommando "magick") muss verfügbar sein.
        // Enthält der konfigurierte Wert keinen Pfadtrenner, wird er als
        // Kommando im PATH betrachtet und nicht weiter geprüft.
        $magick = $this->config->get('paths.magick') ?? '';
        if ($magick === '') {
            return $fileData + ['errors' => 'proc.magick.error_binary_missing'];
        }
        if (!is_executable($magick)) {
            return $fileData + ['errors' => 'proc.magick.error_binary_missing'];
        }

        // Prüfen, ob die Quelldatei existiert und lesbar ist
        if (!file_exists($srcFile) || !is_readable($srcFile)) {
            return $fileData + ['errors' => 'error_file_not_found'];
        }

        // Eigene policy.xml aktivieren. Lässt sie sich nicht schreiben, läuft
        // ImageMagick nicht - ohne Policy fehlen die Sperren und Grenzen.
        if (!$this->policy->apply()) {
            return $fileData + ['errors' => 'proc.magick.error_policy_file'];
        }

        // Coder der Quelldatei festlegen (siehe CODER_BY_MIME). Ohne passenden Coder
        // wird die Datei gar nicht erst geöffnet - writeThumbnails() probiert dann den
        // nächsten Konverter des Thumbnail-Feldes.
        $srcCoder = $this->coderFor((string)($srcData['mime_type'] ?? ''), $srcExt);
        if ($srcCoder === '') {
            return $fileData + [
                'errors' => 'proc.magick.error_file_not_supported',
                'unsupported' => true,
            ];
        }
        // SVG/PDF nur mit security.magick_allow - als Quelle wie als Ziel. Gesperrt
        // gilt als "nicht unterstützt": writeThumbnails() nimmt den nächsten Konverter.
        foreach ([$srcCoder, self::CODER_BY_EXTENSION[$ext] ?? ''] as $coder) {
            $format = MagickPolicy::formatOfCoder($coder);
            if ($format !== '' && !$this->policy->isAllowed($format)) {
                return $fileData + [
                    'errors' => 'proc.magick.error_format_blocked',
                    'unsupported' => true,
                ];
            }
        }

        // Expertenmodus: freie Parameter zerlegen und auswerten.
        // Die Limits stehen bewusst ganz vorne, eine eigene Angabe des
        // Administrators kommt danach und gewinnt.
        $params1 = array_merge(
            $this->limitArguments(),
            $this->execute->tokenizeParameters((string) ($details['parameters1'] ?? ''))
        );
        $params2 = $this->execute->tokenizeParameters((string) ($details['parameters2'] ?? ''));
        $claimed = $this->claimedGroups(array_merge($params1, $params2));

        // Quelle: standardmäßig nur das erste Einzelbild verarbeiten
        // (mehrseitige PDFs, animierte GIFs, ICO mit mehreren Größen ...).
        // Arbeitet der Administrator bewusst mit Frames (-coalesce, -layers ...),
        // wird die Auswahl nicht gesetzt.
        $srcArg = $this->fileArgument($srcCoder, $srcFile, !$claimed['frames']);

        // Abmessungen der Quelldatei ermitteln
        $srcInfo = $this->identify($magick, $srcFile, $srcCoder);
        if ($srcInfo === null) {
            // (kein lesbares Bildformat: writeThumbnails() probiert den nächsten Konverter)
            return $fileData + ['errors' => 'proc.magick.error_file_dimensions', 'unsupported' => true];
        }
        $srcW = $srcInfo['width'];
        $srcH = $srcInfo['height'];

        // Aus den Formularwerten erzeugte Parameter - mit den Abmessungen, wie
        // -auto-orient sie ausrichtet (EXIF-Drehung um 90°: Breite und Höhe tauschen)
        $rotated = !$claimed['orient'] && in_array($srcInfo['orientation'], self::ORIENTATION_ROTATED, true);
        $args = $this->buildArguments($details, $ext, $claimed, $rotated ? $srcH : $srcW, $rotated ? $srcW : $srcH);

        // Zieldatei: Format explizit vorgeben, damit die Endung nicht
        // fehlinterpretiert wird (z. B. ".jpg" mit PNG-Inhalt).
        $destCoder = self::CODER_BY_EXTENSION[$ext] ?? '';
        $destArg = $this->fileArgument($destCoder, $destFile, false);

        $magickExec = basename($magick);
        $magickPath = dirname($magick);
        $magickVersion = $magickExec == 'magick' ? 7 : 6;

        // magick [parameters1] {Quelldatei} [erzeugte Parameter] [parameters2] {Zieldatei}
        if ($magickVersion >= 7) $call = [$magick, 'convert'];
        else $call = [$magick];

        // Fortschrittsdatei für den Hintergrundprozess.
        $progressArgs = [];
        if ($forceBgproc) {
            // Token wird bis in den Hintergrundprozess weitergereicht!
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
            // Token wird bis in den Hintergrundprozess weitergereicht!
            // magick schreibt seinen Fortschritt (-monitor) nach stderr.
            // 'timeout' : Zeitlimit in Sekunden, danach greift TriggerMagick ein
            //             (magick kennt keine Konverter-Einstellung dafür)
            // 'dest'    : Pfad zur Datei ab media-Verzeichnis
            // ('proc' ergänzt startBackgroundProcess() selbst)
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
                // mime_type neutral setzen: Das Thumbnail soll im Backend vorerst
                // nur mit Platzhalter-Symbol angezeigt werden
                'mime_type'  => 'image/unknown',
                'filename'   => $srcData['filename'] ?? 'file',
                'extension'  => $ext,
                'data' => '{}',
                // Infos zur Quell-Datei
                'source_width'  => $srcW,
                'source_height' => $srcH,
                // 'data' => '{}' könnte JSON-String mit weiteren Infos sein (flaches assoziatives Array)
                // Infos zum Hintergrundprozess
                'token'      => (string)$started['token'],
                'bgproc_id'  => (int)$started['id'],
                'pid'        => (int)$started['pid']
            ]);
        }
        else {
            $exitCode = $this->execute->execute($command, $stdout, $stderr, self::TIMEOUT_CONVERT);
        }

        // Prüfen ob das Speichern erfolgreich war
        if ($exitCode !== 0 || !is_file($destFile) || filesize($destFile) === 0) {
            // Angefangene oder leere Zieldatei nicht liegen lassen
            if (is_file($destFile)) @unlink($destFile);

            return $fileData + [
                'errors' => $exitCode === $this->execute::EXEC_TIMEOUT
                    ? 'proc.magick.error_timeout'
                    : 'error_file_conversion',
                // Klartext-Meldung von ImageMagick, hilfreich für die Fehlersuche
                'error_details' => trim($stderr),
            ];
        }

        // Tatsächliche Abmessungen der Zieldatei auslesen.
        // Sie werden nicht berechnet, weil die freien Parameter das Ergebnis
        // beliebig verändern können.
        $destInfo = $this->identify($magick, $destFile, $destCoder);

        /**
         * Die folgende Rückgabestruktur ist bei allen Medien-Konvertern gleich.
         */
        $fileData = [
            'width'     => $destInfo['width'] ?? 0,
            'height'    => $destInfo['height'] ?? 0,
            'mime_type' => self::MIME_TYPES[$ext] ?? ($destInfo['mime_type'] ?? ''),
            'size'      => filesize($destFile),
            'filename'  => $srcData['filename'] ?? 'file',
            'extension' => $ext,
            // Zusätzliche Infos zur Quell-Datei wurden ermittelt:
            'source_width' => $srcW,
            'source_height' => $srcH,
            // 'data' => '{}' könnte JSON-String mit weiteren Infos sein (flaches assoziatives Array)
        ];
        return $fileData;
    }

    /**
     * Erzeugt die Parameter, die sich aus den Formularfeldern ergeben.
     * Bereits vom Administrator belegte Options-Gruppen werden übersprungen.
     *
     * @param array  $details  Konfigurations-Array
     * @param string $ext      Endung / Format der Zieldatei
     * @param array  $claimed  Ergebnis von claimedGroups()
     * @param int    $srcW     Breite der Quelle (ausgerichtet; 0 = unbekannt)
     * @param int    $srcH     Höhe der Quelle (ausgerichtet; 0 = unbekannt)
     * @return array           Argumentliste für die Kommandozeile
     */
    protected function buildArguments(array $details, string $ext, array $claimed, int $srcW = 0, int $srcH = 0): array
    {
        $args = [];

        // EXIF-Ausrichtung anwenden (GD kennt das nicht, das Ergebnis
        // entspricht aber dem, was der Benutzer im Bild sieht)
        if (!$claimed['orient']) {
            $args[] = '-auto-orient';
        }

        // Alpha-Channel (Transparenz) oder Hintergrundfarbe anwenden
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
            // Hintergrund mit der konfigurierten Farbe füllen.
            // "-alpha remove" vor dem Skalieren vermeidet Farbsäume an
            // transparenten Kanten.
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

        // Farbtiefe anpassen (nur wenn eine Farbanzahl vorgegeben ist)
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

        // Qualität
        if (!$claimed['quality']) {
            $quality = isset($details['quality']) ? (int) $details['quality'] : 80;
            $quality = max(0, min(100, $quality));

            if ($ext === 'png') {
                // Bei PNG bedeutet -quality etwas anderes als bei JPEG:
                // Zehnerstelle = zlib-Kompression (0-9), Einerstelle = Filtertyp.
                // Wir rechnen die Prozentangabe wie beim GD-Konverter um,
                // Filtertyp 5 = adaptiv.
                $pngCompression = (int) round(9 - ($quality * 9 / 100));
                $pngCompression = max(0, min(9, $pngCompression));
                $args[] = '-quality';
                $args[] = (string) ($pngCompression * 10 + 5);
            } elseif (in_array($ext, ['jpg', 'jpeg', 'webp', 'avif', 'heic', 'heif', 'jp2', 'tif', 'tiff'], true)) {
                $args[] = '-quality';
                $args[] = (string) $quality;
            }
            // GIF, BMP und Konsorten kennen keine verlustbehaftete Qualität
        }

        return $args;
    }

    /**
     * Die Größenänderung aus Zielbreite/-höhe und den Kennzeichen der Geometrie:
     *
     *     crop         FIT      einpassen                 -resize 440x300
     *                  CROP     ausfüllen, beschneiden    -resize 440x300^ -extent 440x300
     *                  FILL     ausfüllen, überstehen     -resize 440x300^
     *                  DISTORT  Seitenverhältnis ignor.   -resize 440x300!
     *     resize_when  shrink   nur verkleinern (>), enlarge nur vergrößern (<)
     *     unit         percent  Breite/Höhe in Prozent (%) - nur Einpassen/Verzerren
     *     max_pixels   Höchstzahl Pixel (@), danach, mit derselben Bedingung
     *
     * ">" und "<" taugen nur fürs Einpassen und Verzerren: beim Ausfüllen vergrößert
     * der Faktor, sobald eine Seite zu kurz ist. Dort entscheidet der Faktor aus den
     * Quellmaßen: ist keine Änderung erlaubt, wird bei CROP nur mittig auf das
     * Zielformat zugeschnitten, soweit das Bild reicht (ohne Rand in der Hintergrund-
     * farbe), bei FILL bleibt das Bild, wie es ist. Beschnitt und Ausfüllen brauchen
     * beide Maße; fehlt eines, wird eingepasst (das Ergebnis ist dasselbe).
     *
     * @param int $srcW  Breite der Quelle (ausgerichtet; 0 = unbekannt)
     * @param int $srcH  Höhe der Quelle (ausgerichtet; 0 = unbekannt)
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
        // Ausfüllen, Beschneiden, Verzerren brauchen beide Maße; Prozent nur Einpassen
        // oder Verzerren
        if ($reqW < 1 || $reqH < 1 || ($percent && $mode !== self::DISTORT)) $mode = self::FIT;

        if ($reqW > 0 || $reqH > 0) {
            if ($percent) {
                // 50% (beide Seiten), 50x30% (je Seite)
                $geometry = $reqW > 0 && $reqH > 0 ? $reqW . 'x' . $reqH . '%' : max($reqW, $reqH) . '%';
            }
            else {
                $geometry = ($reqW > 0 ? (string)$reqW : '') . 'x' . ($reqH > 0 ? (string)$reqH : '');
            }

            if ($mode === self::CROP || $mode === self::FILL) {
                // Faktor zum Ausfüllen - ohne Quellmaße gilt die Bedingung nicht
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
     * Hintergrundfarbe auswerten und als '#rrggbb' zurückgeben
     */
    protected function evaluateBgColor(array $details): string
    {
        $bgColorHex = isset($details['bgcolor']) ? trim((string) $details['bgcolor']) : '';
        $bgColorHex = ltrim($bgColorHex, '#'); // Führende Raute entfernen, falls vorhanden

        // Prüfung mit regulärem Ausdruck auf 3 oder 6 gültige Hex-Zeichen
        if (!preg_match('/^([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $bgColorHex)) {
            $bgColorHex = 'FFF'; // Fallback bei ungültiger oder leerer Eingabe
        }

        // Kurzschreibweise auf 6 Zeichen aufziehen
        if (strlen($bgColorHex) === 3) {
            $bgColorHex = str_repeat($bgColorHex[0], 2)
                        . str_repeat($bgColorHex[1], 2)
                        . str_repeat($bgColorHex[2], 2);
        }

        return '#' . strtolower($bgColorHex);
    }

    /**
     * Ermittelt, welche Options-Gruppen der Administrator selbst belegt hat.
     *
     * @param array $tokens Argumente aus parameters1 und parameters2
     * @return array        ['geometry' => bool, 'quality' => bool, ...]
     */
    protected function claimedGroups(array $tokens): array
    {
        $claimed = array_fill_keys(array_keys(self::OPTION_GROUPS), false);

        foreach ($tokens as $token) {
            // Nur Optionen betrachten, keine Werte
            if ($token === '' || ($token[0] !== '-' && $token[0] !== '+')) continue;

            $option = strtolower(ltrim($token, '-+'));
            foreach (self::OPTION_GROUPS as $group => $options) {
                if (in_array($option, $options, true)) $claimed[$group] = true;
            }
        }

        return $claimed;
    }

    /**
     * Coder für eine Quelldatei bestimmen: zuerst über den serverseitig erkannten
     * MIME-Typ, ersatzweise über die Dateiendung.
     *
     * Die Endung ist der schwächere, aber immer noch sichere Weg: Sie legt nur fest,
     * WELCHER Coder erzwungen wird - passt der Inhalt nicht dazu, scheitert
     * ImageMagick, statt das Format am Inhalt zu erraten. Gebraucht wird sie für
     * Dateien ohne verlässliche MIME-Angabe, etwa ein im Hintergrund erzeugtes
     * Thumbnail, das selbst wieder Quelle eines Thumbnails ist.
     *
     * @param string $mime       MIME-Typ der Quelldatei (kann leer sein)
     * @param string $extension  Endung der Quelldatei, ohne Punkt
     * @return string            Coder oder '' , wenn das Format nicht zugelassen ist
     */
    protected function coderFor(string $mime, string $extension): string
    {
        $mime = strtolower(trim($mime));
        if (isset(self::CODER_BY_MIME[$mime])) return self::CODER_BY_MIME[$mime];

        return self::CODER_BY_EXTENSION[strtolower(ltrim(trim($extension), '.'))] ?? '';
    }

    /**
     * Dateiargument für die Kommandozeile: Coder-Präfix, Pfad und Frame-Auswahl.
     *
     * @param string $coder       Coder, z. B. 'jpeg'; leer = kein Präfix
     * @param string $file        Absoluter Pfad
     * @param bool   $firstFrame  true: nur das erste Einzelbild ("[0]")
     */
    protected function fileArgument(string $coder, string $file, bool $firstFrame = true): string
    {
        return ($coder !== '' ? $coder . ':' : '') . $file . ($firstFrame ? '[0]' : '');
    }

    /**
     * Ressourcen-Limits, die für JEDEN Aufruf gelten - auch für identify().
     *
     * Eine Dekompressionsbombe (winziges PNG, Gigapixel im Speicher) oder ein
     * präpariertes Bild mit Endlosschleife belegt sonst schon beim Auslesen der
     * Abmessungen den ganzen Server. Die Werte stehen vorne in der Kommandozeile,
     * eigene Angaben im Expertenmodus kommen danach und gewinnen.
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
     * Liest Breite, Höhe und Format einer Datei mit "magick identify"
     *
     * @param string $magick  Pfad zum Programm
     * @param string $file    Absoluter Pfad zur Datei, OHNE Coder-Präfix und "[0]"
     * @param string $coder   Erzwungener Coder (siehe coderFor()); leer = kein Präfix
     * @return array|null ['width'=>int, 'height'=>int, 'orientation'=>string, 'mime_type'=>string] oder null bei Fehler
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

        // Bei mehreren Einzelbildern kommt pro Bild eine Zeile zurück
        $line  = strtok(trim($stdout), "\n");
        $parts = preg_split('/\s+/', trim((string) $line));
        if (!is_array($parts) || count($parts) < 2) return null;

        return [
            'width'       => (int) $parts[0],
            'height'      => (int) $parts[1],
            // EXIF-Ausrichtung (TopLeft, RightTop ...; ohne Angabe: Undefined)
            'orientation' => (string)($parts[2] ?? ''),
            // %[mime] ist bei manchen Formaten leer, dann fehlt der vierte Teil
            'mime_type'   => (string)($parts[3] ?? '')
        ];
    }

    /**
     * Öffentliche Methode, um die Parameter eines per Hintergrundprozess erzeugten Thumbnails
     * in Tabelle `data_fil` nachzutragen
     * 
     * @param string $srcPath   Relativer Pfad zur Datei ab Medienverzeichnis
     * @return array            mittels identify ermittelte Daten + Dateigröße
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

        // magick/convert muss verfügbar sein
        $magick = $this->config->get('paths.magick') ?? '';
        if ($magick === '') {
            return ['error' => 'proc.magick.error_binary_missing'];
        }
        if (!is_executable($magick)) {
            return ['error' => 'proc.magick.error_binary_missing'];
        }

        // Eigenschaften der Datei ermitteln.
        // Die Datei hat der Konverter selbst erzeugt, ihre Endung gibt also das
        // Format vor - der Coder wird trotzdem erzwungen (kein Sniffing).
        $extension = strtolower((string)pathinfo($srcFile, PATHINFO_EXTENSION));
        $srcInfo = $this->identify($magick, $srcFile, self::CODER_BY_EXTENSION[$extension] ?? '');
        if ($srcInfo === null || $srcInfo['width'] <= 0 || $srcInfo['height'] <= 0) {
            return [
                'error' => 'proc.magick.error_file_dimensions'
            ];
        }

        // identify liefert: width, height, mime_type
        $srcInfo['size'] = filesize($srcFile);
        $srcInfo['extension'] = $extension;
        return $srcInfo;
    }

    /**
     * Läuft ein freischaltbares Format (MagickPolicy::ALLOWABLE) auf diesem Server
     * tatsächlich? Für die Infoseite des Konverters: die camindo-Policy gibt es frei,
     * die des Systems kann es trotzdem sperren (Debian sperrt PDF), oder Ghostscript
     * fehlt. Ein kleiner Probelauf klärt das, statt die Policys auszulegen:
     *   svg  eine Mini-SVG schreiben und mit msvg: lesen
     *   pdf  eine Mini-PDF erzeugen (ImageMagick schreibt PDF selbst) und mit gs lesen
     *
     * @param string $format  'svg' | 'pdf'
     * @return array ['ok' => bool, 'error' => string] - error: erste Zeile von ImageMagick
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

    /** Die Policy des Prozessors (Infoseite) */
    public function policy(): MagickPolicy
    {
        return $this->policy;
    }

    /**
     * Version des konfigurierten ImageMagick auslesen.
     *
     * @return string|null z. B. "7.1.1-39", null wenn unter paths.magick kein
     *                     lauffähiges ImageMagick liegt
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
     * Zustand der installierten ImageMagick-Version für die Anzeige im Backend.
     *
     * ImageMagick vor MIN_VERSION ist nicht grundsätzlich unbrauchbar, aber ungepatcht:
     * die -dSAFER-Umgehungen in Ghostscript und die Fuzzing-Funde in den Binärformaten
     * sind erst in den neueren Ständen geschlossen (developer_docs/18-imagemagick.md).
     *
     * @return array ['version' => ?string, 'outdated' => bool, 'snippet' => string]
     *               'snippet' ist die Snippet-Id der Warnung, '' wenn alles in Ordnung
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
