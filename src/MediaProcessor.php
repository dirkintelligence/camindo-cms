<?php
/**
 * Basisklasse aller Medien-Prozessoren.
 *
 * Ein Prozessor ist die Implementierung eines Umwandlungsverfahrens (ImageGd, Magick,
 * FFmpegVideo, FFmpegAudio, FFmpegImage). Er tut von sich aus nichts - erst ein
 * KONVERTER macht ihn nutzbar: ein Datensatz in der Tabelle `converter`, der festlegt,
 * auf welche Medientypen der Prozessor anspringt und mit welchen Einstellungen.
 *
 *     Prozessor   = Klasse (dieses Verfahren, einmal implementiert)
 *     Konverter   = Konfiguration (dieses Verfahren, so eingestellt)
 *
 * Prozessoren laufen ausschließlich beim Speichern von Datensätzen, denen eine neue
 * Datei zugewiesen wurde - angestoßen von VirtualFieldsTrait::writeThumbnails().
 *
 * EIGENEN PROZESSOR ANLEGEN
 * --------------------
 *     Core/Processors/{Name}/{Name}.php     Klasse, extends MediaProcessor
 *     Core/Processors/{Name}/install/settings.php   version, type, label, description
 *     Core/Processors/{Name}/lang/{lang}.ini        Snippets, Prefix proc.{name}
 *     Core/Processors/{Name}/backend/form.php|info.php|html/|lang/
 *
 * Verzeichnis, Namespace-Segment und Klassenname müssen übereinstimmen - ProcessorFactory
 * setzt den Klassennamen daraus zusammen. Zusatz-Prozessoren liegen unter /Processors
 * (Namespace Camindo\Processors) und überschreiben gleichnamige Core-Prozessoren.
 * Danach im Modul "Component" den Refresh laufen lassen.
 *
 * ZWEI AUFGABEN JE PROZESSOR
 * --------------------
 * 1. convert() - die eigentliche Umwandlung.
 * 2. hookDefinition() - beschreibt, welche zusätzlichen EINGABEFELDER der Konverter
 *    bekommt ($schema) und mit welchen Medientypen er vorbelegt wird ($defaults).
 *    Aus $schema baut das Backend die Eingabemaske; die eingegebenen Werte landen als
 *    JSON in converter.details und kommen später als $details wieder bei convert() an.
 *
 * Die übrigen hook*-Methoden reicht die Dataset-Kindklasse Converter durch: Der
 * Prozessor kann damit in den Lebenszyklus seines Konverter-Datensatzes eingreifen
 * (z. B. Werte prüfen oder abhängige Felder setzen). Alle sind abstrakt und müssen
 * implementiert werden - notfalls als leerer Rumpf.
 *
 * LANGE UMWANDLUNGEN
 * --------------------
 * Video- und Audiokonvertierungen können Minuten dauern. Dafür bekommt jeder Prozessor
 * Execute (Prozessstart) und BackgroundProcess (Zustand in der Tabelle `bgproc`)
 * injiziert. Ob im Hintergrund gearbeitet wird, entscheidet die Konverter-Einstellung
 * background_process - oder $forceBgproc, wenn schon die Quelldatei im Hintergrund
 * entsteht und deshalb noch gar nicht vorliegt.
 *
 * @see Camindo\Core\ProcessorFactory                    Auflösung Name -> Klasse
 * @see Camindo\Core\VirtualFieldsTrait::writeThumbnails Aufrufer von convert()
 * @see Camindo\Core\Modules\Converter\Converter        Dataset zur Tabelle `converter`
 */
namespace Camindo\Core;

abstract class MediaProcessor {

    /**
     * Typkennung der Hintergrundprozesse dieses Prozessors (Spalte `bgproc`.`type`).
     * Sie bestimmt, WELCHER Trigger die Prozesse begleitet - also welches Format die
     * Fortschrittsdatei hat, nicht welcher Prozessor sie erzeugt hat: alle drei
     * FFmpeg-Prozessoren teilen sich 'ffmpeg'. Leer = kein Hintergrundbetrieb.
     */
    public const BGPROC_TYPE = '';

    /**
     * Zusätzliche Eingabefelder des Konverters, gesetzt in hookDefinition().
     * Aufbau wie ein Dataset-Schema, die Namen beginnen mit 'details.' - der Wert landet
     * also im JSON-Feld converter.details. Beispiel:
     *     'details.width' => ['type' => 'integer', 'label' => '#proc.imagegd.field_width', ...]
     */
    protected array $schema = [];

    /**
     * Vorbelegung der Medientyp-Felder in der Konverter-Eingabemaske, gesetzt in
     * hookDefinition(). Reine Vorschlagswerte - der Administrator kann sie überschreiben.
     * Erwartet werden zeilenweise Muster: 'image/jpeg', 'image/*' oder '.pdf'
     * (Auswertung in VirtualFieldsTrait::_matchMediaType()) - als Text, eine Zeile je
     * Muster (ein Array wiese die Eingabeprüfung ab, etwa bei converter.create ohne
     * media_types_excl).
     */
    public array $defaults = [
        'media_types_incl' => '',
        'media_types_excl' => ''
    ];

    // Converter reicht seine Hooks weiter
    // Alle abstrakt: jeder Prozessor muss sie implementieren, notfalls als leerer Rumpf.
    // hookDefinition() läuft im Konstruktor, die übrigen ruft die Dataset-Kindklasse
    // Converter im Lebenszyklus ihres Datensatzes auf.
    abstract protected function hookDefinition(): void;
    abstract public function hookOnCreate(array &$data): void;
    abstract public function hookOnLoaded(array &$data): void;
    abstract public function hookOnSave(array &$fields, array &$data, array &$previousData): void;
    abstract public function hookOnSaved(array &$fields, array &$data, array &$previousData): void;
    abstract public function hookOnDelete(array $data): void;
    
    /**
     * Erzeugt eine Dateiversion basierend auf den übergebenen Parametern
     *
     * @param string $srcPath    Pfad zur Quelldatei, relativ zu media_dir
     * @param array  $srcData    Daten der Quell-Datei
     * @param string $destPath   Pfad zur Zieldatei, relativ zu media_dir
     * @param array  $details    Konfigurationsarray (width, height, extension, crop, quality, reduced_bit, alpha, bgcolor)
     * @param bool $forceBgproc  true: erzwingt ausführung als Hintergrundprozess
     *                           Wird gesetzt, wenn die Quelldatei selbst noch im
     *                           Hintergrund erzeugt wird und daher noch nicht existiert.
     * @return array             Daten der Zieldatei, bzw ['size'=>0, 'errors'=>'...']
     *                           Kann optional zusätzliche Infos zur Quell-Datei liefern (width, height, data)
     *
     * Beide Pfade sind relativ zu paths.media_dir, nicht absolut - die Umsetzung in einen
     * absoluten Pfad ist Sache des Prozessors. Fehler werden NICHT geworfen, sondern über
     * 'size' => 0 plus 'errors' zurückgemeldet; ein abgebrochener Thumbnail-Lauf soll das
     * Speichern des Datensatzes nicht scheitern lassen.
     *
     * Stellt sich erst beim Lesen der Quelldatei heraus, dass der Prozessor ihr Format
     * nicht verarbeiten kann (unbekannter Typ, fehlende Bibliothek), gehört zusätzlich
     * 'unsupported' => true in die Rückgabe: writeThumbnails() probiert dann den nächsten
     * Konverter des Thumbnail-Feldes, statt das Speichern abzubrechen. Siehe supports().
     */
    abstract public function convert(string $srcPath, array $srcData, string $destPath, array $details, bool $forceBgproc): array;

    /**
     * Kann dieser Prozessor eine Datei dieses Typs in die Ziel-Extension umwandeln?
     *
     * Vorprüfung VOR convert(): ein Thumbnail-Feld kann mehrere Konverter in Reihenfolge
     * tragen (z. B. GD, dann ImageMagick); writeThumbnails() übergeht einen Konverter,
     * dessen Medientypen zwar passen, dessen Prozessor den Typ aber nicht beherrscht,
     * und nimmt den nächsten. Standard: alles, was die Medientypen des Konverters
     * zulassen - Prozessoren mit bekannten Grenzen (ImageGd: je nach GD-Build kein
     * WebP/AVIF) überschreiben die Methode.
     *
     * @param string $mime           Mime-Typ der Quelldatei
     * @param string $srcExtension   Extension der Quelldatei (ohne Punkt)
     * @param string $destExtension  Extension der zu erzeugenden Datei (ohne Punkt)
     */
    public function supports(string $mime, string $srcExtension, string $destExtension): bool
    {
        return true;
    }


    public function __construct(
        protected Config $config,
        protected Cms $cms,
        protected Crypt $crypt,
        protected Execute $execute,
        protected BackgroundProcess $bgproc,
    )
    {
        $this->hookDefinition();
    }

    /**
     * Liefere zusätzliche Eingabefelder für Konverter
     *
     * Von ConverterBackend benutzt, um die Eingabemaske des Konverters um die
     * prozessorspezifischen Felder zu erweitern.
     *
     * @return array  Schema-Ausschnitt, siehe $schema
     */
    public function getSchema()
    {
        return $this->schema;
    }

    // HINTERGRUNDPROZESSE
    // ====================

    /**
     * Kurzname des Prozessors, so wie ihn `converter`.`processor` speichert und
     * ProcessorFactory::create() ihn erwartet ('FFmpegVideo', 'Magick', ...).
     */
    public function processorName(): string
    {
        $parts = explode('\\', static::class);
        return (string)end($parts);
    }

    /**
     * Kommando im Hintergrund starten und in `bgproc` registrieren.
     *
     * Warum nicht direkt BackgroundProcess::start()? Weil hier zusätzlich der
     * PROZESSOR-NAME in `message` landet. Mehrere Prozessoren teilen sich denselben
     * BGPROC_TYPE (alle drei FFmpeg-Prozessoren schreiben 'ffmpeg'), der Trigger kann
     * aus dem Typ allein also nicht ableiten, wessen rectifyParams() die fertige Datei
     * auslesen muss. TriggerMedia liest den Namen wieder aus und holt sich die Instanz
     * über die ProcessorFactory.
     *
     * @param array   $command     Kommando inklusive aller Argumente
     * @param string  $token       Vorab erzeugtes Token (steckt bereits im Kommando,
     *                             z. B. im Namen der Fortschrittsdatei)
     * @param array   $message     Prozessorspezifische Angaben für den Trigger
     *                             ('frames', 'dur', 'timeout', 'dest'), werden um
     *                             'proc' ergänzt und als JSON in `message` gelegt
     * @param ?string $stdoutFile  Logdatei für die Ausgabe, null = verwerfen
     * @param ?string $stderrFile  Logdatei für die Fehlerausgabe, null = verwerfen
     *                             (Magick schreibt seinen Fortschritt hierhin)
     * @param ?string $error       Grund eines Fehlschlags
     * @return array|null          ['id' => int, 'pid' => int, 'token' => string] oder null
     */
    protected function startBackgroundProcess(
        array $command,
        string $token,
        array $message = [],
        ?string $stdoutFile = null,
        ?string $stderrFile = null,
        ?string &$error = null
    ): ?array
    {
        $error = '';
        if (static::BGPROC_TYPE === '') {
            $error = 'processor has no BGPROC_TYPE';
            return null;
        }

        $started = $this->bgproc->start(
            $command, static::BGPROC_TYPE,
            token: $token, stdoutFile: $stdoutFile, stderrFile: $stderrFile, error: $error
        );
        if ($started === null) return null;

        // 'proc' gewinnt: der Prozessor kennt sich selbst besser als der Aufrufer
        $message['proc'] = $this->processorName();
        $messageEncoded = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->bgproc->setProgressByToken($started['token'], 0, $messageEncoded);

        return $started;
    }

    /**
     * Eigenschaften einer FERTIGEN Datei auslesen, damit TriggerMedia sie in
     * `data_fil` nachtragen kann.
     *
     * Gegenstück zu convert() im Hintergrundbetrieb: convert() legt die Zeile mit
     * size/width/height = 0 und einem Token an, weil die Datei noch entsteht; sobald
     * der Prozess durch ist, liest diese Methode die endgültigen Werte aus.
     *
     * Prozessoren OHNE Hintergrundunterstützung (BGPROC_TYPE = '') brauchen sie nicht -
     * für die bleibt es bei dieser Rückfallebene.
     *
     * @param string $srcPath  Relativer Pfad zur Datei ab paths.media_dir
     * @return array           Mindestens 'size', dazu je nach Medium 'width', 'height',
     *                         'extension' und was der Prozessor sonst ermitteln kann.
     *                         Im Fehlerfall ausschließlich ['error' => Snippet-Id];
     *                         der Aufrufer erkennt das an fehlendem 'size'.
     */
    public function rectifyParams(string $srcPath): array
    {
        return ['error' => 'error_file_conversion'];
    }
}