<?php
/**
 * Base class of all media processors.
 *
 * A processor is the implementation of a conversion method (ImageGd, Magick,
 * FFmpegVideo, FFmpegAudio, FFmpegImage). On its own it does nothing - only a
 * CONVERTER makes it usable: a record in the table `converter` that defines
 * which media types the processor applies to and with which settings.
 *
 *     Processor   = class (this method, implemented once)
 *     Converter   = configuration (this method, set up this way)
 *
 * Processors run only when saving records to which a new file has been assigned -
 * triggered by VirtualFieldsTrait::writeThumbnails().
 *
 * CREATING YOUR OWN PROCESSOR
 * --------------------
 *     Core/Processors/{Name}/{Name}.php     class, extends MediaProcessor
 *     Core/Processors/{Name}/install/settings.php   version, type, label, description
 *     Core/Processors/{Name}/lang/{lang}.ini        snippets, prefix proc.{name}
 *     Core/Processors/{Name}/backend/form.php|info.php|html/|lang/
 *
 * Directory, namespace segment and class name must match - ProcessorFactory
 * builds the class name from them. Additional processors live under /Processors
 * (namespace Camindo\Processors) and override core processors of the same name.
 * Then run the refresh in the "Component" module.
 *
 * TWO TASKS PER PROCESSOR
 * --------------------
 * 1. convert() - the actual conversion.
 * 2. hookDefinition() - describes which additional INPUT FIELDS the converter
 *    gets ($schema) and with which media types it is pre-filled ($defaults).
 *    The backend builds the input mask from $schema; the entered values end up as
 *    JSON in converter.details and later come back to convert() as $details.
 *
 * The other hook* methods are passed on by the dataset subclass Converter: the
 * processor can thus intervene in the life cycle of its converter record
 * (e.g. check values or set dependent fields). All are abstract and must
 * be implemented - if necessary as an empty body.
 *
 * LONG CONVERSIONS
 * --------------------
 * Video and audio conversions can take minutes. For this, each processor
 * is injected with Execute (process start) and BackgroundProcess (state in the table `bgproc`).
 * Whether work happens in the background is decided by the converter setting
 * background_process - or by $forceBgproc, if the source file itself is only
 * being created in the background and therefore does not exist yet.
 *
 * @see Camindo\Core\ProcessorFactory                    resolution of name -> class
 * @see Camindo\Core\VirtualFieldsTrait::writeThumbnails Aufrufer von convert()
 * @see Camindo\Core\Modules\Converter\Converter        dataset for the table `converter`
 */
namespace Camindo\Core;

abstract class MediaProcessor {

    /**
     * Type identifier of the background processes of this processor (column `bgproc`.`type`).
     * It determines WHICH trigger accompanies the processes - i.e. which format the
     * progress file has, not which processor created it: all three
     * FFmpeg processors share 'ffmpeg'. Empty = no background operation.
     */
    public const BGPROC_TYPE = '';

    /**
     * Additional input fields of the converter, set in hookDefinition().
     * Structure like a dataset schema; the names begin with 'details.' - so the value ends
     * up in the JSON field converter.details. Example:
     *     'details.width' => ['type' => 'integer', 'label' => '#proc.imagegd.field_width', ...]
     */
    protected array $schema = [];

    /**
     * Preset of the media type fields in the converter input mask, set in
     * hookDefinition(). Pure suggested values - the administrator can overwrite them.
     * Expected are line-by-line patterns: 'image/jpeg', 'image/*' or '.pdf'
     * (evaluated in VirtualFieldsTrait::_matchMediaType()) - as text, one line per
     * pattern (an array would fail the input check, e.g. with converter.create without
     * media_types_excl).
     */
    public array $defaults = [
        'media_types_incl' => '',
        'media_types_excl' => ''
    ];

    // Converter passes its hooks on
    // All abstract: every processor must implement them, if necessary as an empty body.
    // hookDefinition() runs in the constructor, the others are called by the dataset
    // subclass Converter in the life cycle of its record.
    abstract protected function hookDefinition(): void;
    abstract public function hookOnCreate(array &$data): void;
    abstract public function hookOnLoaded(array &$data): void;
    abstract public function hookOnSave(array &$fields, array &$data, array &$previousData): void;
    abstract public function hookOnSaved(array &$fields, array &$data, array &$previousData): void;
    abstract public function hookOnDelete(array $data): void;
    
    /**
     * Creates a file version based on the passed parameters
     *
     * @param string $srcPath    path to the source file, relative to media_dir
     * @param array  $srcData    data of the source file
     * @param string $destPath   path to the target file, relative to media_dir
     * @param array  $details    configuration array (width, height, extension, crop, quality, reduced_bit, alpha, bgcolor)
     * @param bool $forceBgproc  true: forces execution as a background process
     *                           Set when the source file itself is still being created in the
     *                           background and therefore does not exist yet.
     * @return array             data of the target file, or ['size'=>0, 'errors'=>'...']
     *                           May optionally return additional info about the source file (width, height, data)
     *
     * Both paths are relative to paths.media_dir, not absolute - converting them into an
     * absolute path is the processor's job. Errors are NOT thrown but reported back via
     * 'size' => 0 plus 'errors'; an aborted thumbnail run must not make
     * saving the record fail.
     *
     * If it only turns out when reading the source file that the processor cannot handle its format
     * (unknown type, missing library), 'unsupported' => true must also be included in the return:
     * writeThumbnails() then tries the next converter of the thumbnail field instead of
     * aborting the save. See supports().
     */
    abstract public function convert(string $srcPath, array $srcData, string $destPath, array $details, bool $forceBgproc): array;

    /**
     * Can this processor convert a file of this type into the target extension?
     *
     * Pre-check BEFORE convert(): a thumbnail field can carry several converters in order
     * (e.g. GD, then ImageMagick); writeThumbnails() skips a converter
     * whose media types match but whose processor does not handle the type,
     * and takes the next one. Default: everything the media types of the converter
     * allow - processors with known limits (ImageGd: depending on the GD build no
     * WebP/AVIF) override the method.
     *
     * @param string $mime           mime type of the source file
     * @param string $srcExtension   extension of the source file (without dot)
     * @param string $destExtension  extension of the file to be created (without dot)
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
     * Return additional input fields for converters
     *
     * Used by ConverterBackend to extend the input mask of the converter with the
     * processor-specific fields.
     *
     * @return array  schema excerpt, see $schema
     */
    public function getSchema()
    {
        return $this->schema;
    }

    // BACKGROUND PROCESSES
    // ====================

    /**
     * Short name of the processor, as `converter`.`processor` stores it and
     * ProcessorFactory::create() expects it ('FFmpegVideo', 'Magick', ...).
     */
    public function processorName(): string
    {
        $parts = explode('\\', static::class);
        return (string)end($parts);
    }

    /**
     * Start a command in the background and register it in `bgproc`.
     *
     * Why not directly BackgroundProcess::start()? Because the PROCESSOR NAME also goes
     * into `message` here. Several processors share the same
     * BGPROC_TYPE (all three FFmpeg processors write 'ffmpeg'), so the trigger cannot
     * derive from the type alone whose rectifyParams() has to read the finished file.
     * TriggerMedia reads the name back and gets the instance via the
     * ProcessorFactory.
     *
     * @param array   $command     command including all arguments
     * @param string  $token       token created beforehand (already part of the command,
     *                             e.g. in the name of the progress file)
     * @param array   $message     processor-specific data for the trigger
     *                             ('frames', 'dur', 'timeout', 'dest'), 'proc' is added
     *                             and it is stored as JSON in `message`
     * @param ?string $stdoutFile  log file for the output, null = discard
     * @param ?string $stderrFile  log file for the error output, null = discard
     *                             (Magick writes its progress here)
     * @param ?string $error       reason for a failure
     * @return array|null          ['id' => int, 'pid' => int, 'token' => string] or null
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

        // 'proc' wins: the processor knows itself better than the caller does
        $message['proc'] = $this->processorName();
        $messageEncoded = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->bgproc->setProgressByToken($started['token'], 0, $messageEncoded);

        return $started;
    }

    /**
     * Read the properties of a FINISHED file so that TriggerMedia can add them
     * to `data_fil`.
     *
     * Counterpart to convert() in background operation: convert() creates the row with
     * size/width/height = 0 and a token, because the file is still being created; as soon as
     * the process has finished, this method reads the final values.
     *
     * Processors WITHOUT background support (BGPROC_TYPE = '') do not need it -
     * for them this fallback level remains.
     *
     * @param string $srcPath  relative path to the file from paths.media_dir
     * @return array           at least 'size', plus depending on the medium 'width', 'height',
     *                         'extension' and whatever else the processor can determine.
     *                         On error only ['error' => snippet id];
     *                         the caller recognizes that by the missing 'size'.
     */
    public function rectifyParams(string $srcPath): array
    {
        return ['error' => 'error_file_conversion'];
    }
}