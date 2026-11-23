<?php
/**
 * Runs an ImageMagick command line with the PHP extension Imagick instead of the program
 * "magick" (processor Magick, engine 'imagick').
 *
 * The processor builds the same argument list for both engines (limits, parameters1, the
 * arguments generated from the form fields, parameters2). This class translates it option
 * by option into Imagick calls, in the order of the command line:
 *
 *     -resize 440x300^ -gravity center -extent 440x300
 *     -> resizeImage(), extentImage() with the offset that -gravity center gives
 *
 * WHITELIST
 * --------------------
 * Only the options in OPTIONS / PLUS_OPTIONS are translated, each with a checked value. An
 * option that is missing there is not passed on, parse() rejects it - when saving the
 * converter as well as before each conversion.
 *
 * NO POLICY
 * --------------------
 * The extension reads policy.xml once when it is loaded; the camindo policy
 * (MagickPolicy, MAGICK_CONFIGURE_PATH) therefore does not reach it. What the policy does
 * for the command line is done here: the coder of source and target is always enforced
 * ("jpeg:/path"), the limits are set with Imagick::setResourceLimit() before each call,
 * file names, coder prefixes and @-lists never get into an option (whitelist,
 * Execute::unsafeParameter()), and the processor does not process SVG and PDF with this
 * engine at all.
 *
 * The time limit is only set in a process of its own (constructor argument $timeLimit,
 * cron/imagick_convert.php): ImageMagick measures it from the first image of the PHP
 * process, a long-running web server or background process would exceed it with every
 * call. Magick therefore runs each conversion in that worker process, which the caller ends
 * after Magick::TIMEOUT_CONVERT seconds (Execute) - see "WORKER PROCESS" in Magick.
 *
 * @see developer docs, chapter 14 (Security: web)
 */
namespace Camindo\Core\Processors\Magick;

class ImagickCommand
{
    /**
     * Options with a leading "-": name => number of values.
     * Options that only set a value for later steps are marked in SETTINGS.
     */
    public const OPTIONS = [
        // settings
        'background'          => 1,
        'bordercolor'         => 1,
        'define'              => 1,
        'density'             => 1,
        'depth'               => 1,
        'dither'              => 1,
        'filter'              => 1,
        'fuzz'                => 1,
        'gravity'             => 1,
        'interlace'           => 1,
        'limit'               => 2,
        'monitor'             => 0,
        'quality'             => 1,
        'quiet'               => 0,
        'sampling-factor'     => 1,
        'units'               => 1,
        // operators on each single image
        'adaptive-resize'     => 1,
        'alpha'               => 1,
        'auto-gamma'          => 0,
        'auto-level'          => 0,
        'auto-orient'         => 0,
        'blur'                => 1,
        'border'              => 1,
        'brightness-contrast' => 1,
        'colors'              => 1,
        'colorspace'          => 1,
        'contrast'            => 0,
        'crop'                => 1,
        'despeckle'           => 0,
        'enhance'             => 0,
        'equalize'            => 0,
        'extent'              => 1,
        'flip'                => 0,
        'flop'                => 0,
        'gamma'               => 1,
        'gaussian-blur'       => 1,
        'modulate'            => 1,
        'monochrome'          => 0,
        'negate'              => 0,
        'normalize'           => 0,
        'posterize'           => 1,
        'resize'              => 1,
        'rotate'              => 1,
        'sample'              => 1,
        'scale'               => 1,
        'sharpen'             => 1,
        'shave'               => 1,
        'strip'               => 0,
        'thumbnail'           => 1,
        'transparent'         => 1,
        'transpose'           => 0,
        'transverse'          => 0,
        'trim'                => 0,
        'type'                => 1,
        'unsharp'             => 1,
        // operators on the whole image sequence
        'coalesce'            => 0,
        'deconstruct'         => 0,
        'flatten'             => 0,
        'layers'              => 1,
    ];

    /** Options with a leading "+": name => number of values */
    public const PLUS_OPTIONS = [
        'repage'  => 0,
        'gravity' => 0,
        'dither'  => 0,
        'profile' => 1,
    ];

    /** Options that only set a value - in parameters1 they apply before reading */
    protected const SETTINGS = [
        'background', 'bordercolor', 'define', 'density', 'depth', 'dither', 'filter', 'fuzz',
        'gravity', 'interlace', 'limit', 'monitor', 'quality', 'quiet', 'sampling-factor', 'units',
    ];

    /** Operators that work on the whole sequence (frames) instead of each single image */
    protected const SEQUENCE_OPERATORS = ['coalesce', 'deconstruct', 'flatten', 'layers'];

    /** Values of -gravity */
    protected const GRAVITIES = ['northwest', 'north', 'northeast', 'west', 'center', 'east',
        'southwest', 'south', 'southeast', 'none', 'forget'];

    /** Values of -alpha -> constant ALPHACHANNEL_* */
    protected const ALPHA = [
        'on'          => 'ACTIVATE',
        'activate'    => 'ACTIVATE',
        'off'         => 'DEACTIVATE',
        'deactivate'  => 'DEACTIVATE',
        'set'         => 'SET',
        'opaque'      => 'OPAQUE',
        'transparent' => 'TRANSPARENT',
        'extract'     => 'EXTRACT',
        'background'  => 'BACKGROUND',
        'remove'      => 'REMOVE',
    ];

    /** Values of -layers -> how they are carried out */
    protected const LAYERS = ['coalesce', 'optimize', 'optimizeplus', 'optimizeframe', 'flatten', 'merge', 'mosaic'];

    /** Values of -sampling-factor that are spelled as chroma subsampling */
    protected const SAMPLING = ['4:4:4' => '1x1', '4:2:2' => '2x1', '4:2:0' => '2x2', '4:1:1' => '4x1'];

    /** -define: only options of these coders / areas (no delegate:, registry: ...) */
    protected const DEFINE_PREFIXES = ['jpeg', 'png', 'webp', 'tiff', 'gif', 'avif', 'heic', 'jp2',
        'bmp', 'ico', 'psd', 'filter', 'quantum'];

    /** Limits (-limit, Imagick::setResourceLimit()): name => constant RESOURCETYPE_* */
    protected const RESOURCES = [
        'memory'      => 'MEMORY',
        'map'         => 'MAP',
        'disk'        => 'DISK',
        'width'       => 'WIDTH',
        'height'      => 'HEIGHT',
        'area'        => 'AREA',
        'list-length' => 'LISTLENGTH',
    ];

    /** EXIF orientation (Imagick::getImageOrientation()) -> name as "magick identify" prints it */
    public const ORIENTATION_NAMES = [
        0 => 'Undefined', 1 => 'TopLeft', 2 => 'TopRight', 3 => 'BottomRight', 4 => 'BottomLeft',
        5 => 'LeftTop', 6 => 'RightTop', 7 => 'RightBottom', 8 => 'LeftBottom',
    ];

    /** State while a command runs (settings of the command line) */
    protected array $state = [];

    /** Progress callback fn(int $percent): void, see monitor() */
    protected $monitor = null;

    /** Last percentage passed to the progress callback */
    protected int $lastPercent = -1;

    /**
     * @param bool $timeLimit  true: also set the time limit ('time' of the limits, -limit time) -
     *                         only in a process that runs exactly one conversion (see class comment)
     */
    public function __construct(protected bool $timeLimit = false)
    {
    }

    /**
     * Report the progress while reading, processing and writing, like "-monitor" of the
     * command line: the callback gets the percentage of the current operation (0-99, every
     * operation starts again at 0); 100 is left to the caller after a successful write.
     */
    public function monitor(?callable $callback): static
    {
        $this->monitor = $callback;
        return $this;
    }

    /** Is the extension loaded? */
    public static function available(): bool
    {
        return extension_loaded('imagick') && class_exists(\Imagick::class);
    }

    /**
     * Version of the ImageMagick library the extension is built with, e.g. "6.9.11-60".
     */
    public static function version(): ?string
    {
        if (!self::available()) return null;
        $version = \Imagick::getVersion();
        if (!preg_match('/ImageMagick\s+([0-9]+\.[0-9]+\.[0-9\-]+)/i', (string)($version['versionString'] ?? ''), $match)) return null;
        return $match[1];
    }

    /**
     * Does the library round the sides of an area geometry (@)? ImageMagick 7 does,
     * ImageMagick 6 truncates them. Without a known version: like ImageMagick 7.
     */
    public static function roundsArea(): bool
    {
        static $rounds = null;
        return $rounds ??= (int)(self::version() ?? '7') >= 7;
    }

    /**
     * Split an argument list into steps and check every value.
     *
     * @param array $tokens  arguments as on the command line (without source and target)
     * @return array         steps ['sign' => '-'|'+', 'name' => string, 'args' => array]
     * @throws \InvalidArgumentException with the first token that cannot be translated
     *         as message
     */
    public static function parse(array $tokens): array
    {
        $steps = [];
        $tokens = array_values(array_map('strval', $tokens));
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if ($token === '' || ($token[0] !== '-' && $token[0] !== '+') || is_numeric($token)) {
                throw new \InvalidArgumentException($token);
            }
            $sign = $token[0];
            $name = strtolower(ltrim($token, '-+'));
            $table = $sign === '+' ? self::PLUS_OPTIONS : self::OPTIONS;
            if (!array_key_exists($name, $table)) throw new \InvalidArgumentException($token);
            $count = $table[$name];
            // the values must follow
            if ($i + $count > $n - 1) throw new \InvalidArgumentException($token);
            $args = array_slice($tokens, $i + 1, $count);
            $i += $count;
            $step = ['sign' => $sign, 'name' => $name, 'args' => $args];
            if (!self::valid($step)) {
                throw new \InvalidArgumentException(trim($token . ' ' . implode(' ', $args)));
            }
            $steps[] = $step;
        }
        return $steps;
    }

    /**
     * First token of the list that cannot be translated, null if all of them can.
     */
    public static function unsupported(array $tokens): ?string
    {
        try {
            self::parse($tokens);
        }
        catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return null;
    }

    /** Check the values of one step (without an image) */
    protected static function valid(array $step): bool
    {
        $value = (string)($step['args'][0] ?? '');
        $lower = strtolower($value);
        if ($step['sign'] === '+') {
            return $step['name'] !== 'profile' || preg_match('/^[a-z0-9*,_-]+$/i', $value) === 1;
        }
        switch ($step['name']) {
            case 'background':
            case 'bordercolor':
            case 'transparent':
                return self::color($value) !== null;
            case 'define':
                if (!preg_match('/^([a-z0-9]+):[a-z0-9:_-]+=[a-z0-9.,:_%+-]*$/i', $value, $match)) return false;
                return in_array(strtolower($match[1]), self::DEFINE_PREFIXES, true);
            case 'density':
                return preg_match('/^\d+(\.\d+)?(x\d+(\.\d+)?)?$/', $value) === 1;
            case 'depth':
                return in_array($value, ['1', '2', '4', '8', '16', '32'], true);
            case 'dither':
                return in_array($lower, ['none', 'riemersma', 'floydsteinberg'], true);
            case 'filter':
                return self::constant('FILTER_', $value) !== null;
            case 'fuzz':
                return preg_match('/^\d+(\.\d+)?%?$/', $value) === 1;
            case 'gravity':
                return in_array($lower, self::GRAVITIES, true);
            case 'interlace':
                return self::constant('INTERLACE_', $value) !== null;
            case 'limit':
                return isset(self::RESOURCES[$lower]) && self::size((string)$step['args'][1]) !== null
                    || $lower === 'time' || $lower === 'thread' || $lower === 'throttle';
            case 'quality':
            case 'colors':
            case 'posterize':
                return ctype_digit($value) && (int)$value <= ($step['name'] === 'quality' ? 100 : 65536);
            case 'sampling-factor':
                return isset(self::SAMPLING[$value]) || preg_match('/^\d(x\d)?(,\d(x\d)?)*$/', $value) === 1;
            case 'units':
                return in_array($lower, ['undefined', 'pixelsperinch', 'pixelspercentimeter'], true);
            case 'alpha':
                return isset(self::ALPHA[$lower]) && self::constant('ALPHACHANNEL_', self::ALPHA[$lower]) !== null;
            case 'colorspace':
                return self::constant('COLORSPACE_', $value) !== null;
            case 'type':
                return self::constant('IMGTYPE_', $value) !== null;
            case 'layers':
                return in_array($lower, self::LAYERS, true);
            case 'gamma':
                return is_numeric($value) && (float)$value > 0;
            case 'rotate':
                return preg_match('/^-?\d+(\.\d+)?[<>]?$/', $value) === 1;
            case 'blur':
            case 'gaussian-blur':
            case 'sharpen':
            case 'unsharp':
                return self::floats($value) !== null;
            case 'modulate':
                return preg_match('/^\d+(\.\d+)?(,\d+(\.\d+)?){0,2}$/', $value) === 1;
            case 'brightness-contrast':
                return preg_match('/^-?\d+(\.\d+)?%?(x-?\d+(\.\d+)?%?)?$/', $value) === 1;
            case 'border':
            case 'shave':
            case 'crop':
            case 'extent':
            case 'resize':
            case 'thumbnail':
            case 'sample':
            case 'scale':
            case 'adaptive-resize':
                $geometry = self::geometry($value);
                if ($geometry === null) return false;
                // area (@) only for the size changes
                if (str_contains($geometry['flags'], '@')
                    && !in_array($step['name'], ['resize', 'thumbnail', 'sample', 'scale', 'adaptive-resize'], true)) return false;
                return true;
        }
        return true;
    }

    /**
     * Read a file, carry out the steps and write the result.
     *
     * @param string $srcCoder    enforced coder of the source ('jpeg' ...)
     * @param string $srcFile     absolute path
     * @param bool   $firstFrame  true: only the first single image ("[0]")
     * @param array  $before      arguments before the source file (limits, parameters1)
     * @param array  $after       arguments after the source file (generated, parameters2)
     * @param string $destCoder   enforced coder of the target
     * @param string $destFile    absolute path
     * @param array  $limits      upper limits: name => value as in the policy ('256MiB', '16KP')
     * @param ?string $error      message of ImageMagick or of the translation
     */
    public function convert(string $srcCoder, string $srcFile, bool $firstFrame, array $before, array $after,
        string $destCoder, string $destFile, array $limits, ?string &$error = null): bool
    {
        $error = '';
        if (!self::available()) {
            $error = 'imagick';
            return false;
        }
        $image = null;
        try {
            $stepsBefore = self::parse($before);
            $stepsAfter = self::parse($after);
            $this->state = [
                'background' => '#ffffff', 'bordercolor' => '#dfdfdf', 'gravity' => 'northwest',
                'filter' => 'UNDEFINED', 'fuzz' => 0.0, 'dither' => true, 'quality' => null, 'interlace' => null,
                'depth' => null, 'units' => null, 'density' => null, 'sampling' => null, 'defines' => [],
            ];
            $this->setLimits($limits);

            $image = new \Imagick();
            if ($this->monitor !== null) {
                $this->lastPercent = -1;
                $image->setProgressMonitor(function ($offset, $span): bool {
                    $percent = $span > 1 ? min(99, (int)floor($offset * 100 / ($span - 1))) : 0;
                    if ($percent !== $this->lastPercent) {
                        $this->lastPercent = $percent;
                        ($this->monitor)($percent);
                    }
                    return true;
                });
            }
            // Settings before the source apply when reading; operators there are carried
            // out after reading (like "magick convert" does with them)
            $pending = [];
            foreach ($stepsBefore as $step) {
                if ($step['sign'] === '-' && in_array($step['name'], self::SETTINGS, true)) $this->setting($step, $limits);
                else $pending[] = $step;
            }
            foreach ($this->state['defines'] as $key => $value) $image->setOption($key, $value);
            if ($this->state['density'] !== null) $image->setResolution(...$this->state['density']);

            $image->readImage(($srcCoder !== '' ? $srcCoder . ':' : '') . $srcFile . ($firstFrame ? '[0]' : ''));
            $this->applyReadSettings($image);

            foreach ([...$pending, ...$stepsAfter] as $step) {
                $image = $this->step($image, $step, $limits);
            }

            // Settings for writing
            foreach ($this->state['defines'] as $key => $value) $image->setOption($key, $value);
            if ($this->state['interlace'] !== null) $image->setInterlaceScheme($this->state['interlace']);
            if ($this->state['sampling'] !== null) $image->setSamplingFactors($this->state['sampling']);
            foreach ($this->frames($image) as $frame) {
                if ($this->state['quality'] !== null) $frame->setImageCompressionQuality($this->state['quality']);
                if ($this->state['depth'] !== null) $frame->setImageDepth($this->state['depth']);
                if ($this->state['interlace'] !== null) $frame->setImageInterlaceScheme($this->state['interlace']);
                if ($destCoder !== '') $frame->setImageFormat($destCoder);
            }
            if ($this->state['quality'] !== null) $image->setCompressionQuality($this->state['quality']);

            $target = ($destCoder !== '' ? $destCoder . ':' : '') . $destFile;
            $written = $image->getNumberImages() > 1 ? $image->writeImages($target, true) : $image->writeImage($target);
            if (!$written) {
                $error = 'write';
                return false;
            }
        }
        catch (\InvalidArgumentException $e) {
            $error = 'unsupported parameter: ' . $e->getMessage();
            return false;
        }
        catch (\Throwable $e) {
            $error = $e->getMessage();
            return false;
        }
        finally {
            if ($image instanceof \Imagick) $image->clear();
        }
        clearstatcache(true, $destFile);
        return is_file($destFile) && filesize($destFile) > 0;
    }

    /**
     * Read width, height, orientation, quality and MIME type without decoding the pixels
     * (counterpart to "magick identify").
     *
     * @return array|null  ['width', 'height', 'orientation', 'quality', 'mime_type'], null on error
     */
    public function identify(string $file, string $coder, array $limits): ?array
    {
        if (!self::available()) return null;
        $image = new \Imagick();
        try {
            $this->setLimits($limits);
            $image->pingImage(($coder !== '' ? $coder . ':' : '') . $file . '[0]');
            $mime = '';
            try { $mime = (string)$image->getImageMimeType(); } catch (\Throwable) {}
            return [
                'width'       => (int)$image->getImageWidth(),
                'height'      => (int)$image->getImageHeight(),
                'orientation' => self::ORIENTATION_NAMES[(int)$image->getImageOrientation()] ?? 'Undefined',
                'quality'     => (int)$image->getImageCompressionQuality(),
                'mime_type'   => $mime,
            ];
        }
        catch (\Throwable) {
            return null;
        }
        finally {
            $image->clear();
        }
    }

    // Steps
    // ====================

    /** A setting: only remember the value */
    protected function setting(array $step, array $limits): void
    {
        $value = (string)($step['args'][0] ?? '');
        $lower = strtolower($value);
        switch ($step['name']) {
            case 'background':  $this->state['background'] = $value; break;
            case 'bordercolor': $this->state['bordercolor'] = $value; break;
            case 'gravity':     $this->state['gravity'] = $lower === 'none' || $lower === 'forget' ? 'northwest' : $lower; break;
            case 'filter':      $this->state['filter'] = $value; break;
            case 'fuzz':        $this->state['fuzz'] = $this->fuzz($value); break;
            case 'dither':      $this->state['dither'] = $lower !== 'none'; break;
            case 'quality':     $this->state['quality'] = (int)$value; break;
            case 'depth':       $this->state['depth'] = (int)$value; break;
            case 'interlace':   $this->state['interlace'] = self::constant('INTERLACE_', $value); break;
            case 'units':       $this->state['units'] = self::constant('RESOLUTION_', $lower === 'pixelsperinch'
                ? 'PIXELSPERINCH' : ($lower === 'pixelspercentimeter' ? 'PIXELSPERCENTIMETER' : 'UNDEFINED')); break;
            case 'density':
                $parts = explode('x', $value);
                $this->state['density'] = [(float)$parts[0], (float)($parts[1] ?? $parts[0])];
                break;
            case 'sampling-factor':
                $factors = [];
                foreach (explode(',', self::SAMPLING[$value] ?? $value) as $pair) {
                    $xy = explode('x', $pair);
                    array_push($factors, (float)$xy[0], (float)($xy[1] ?? $xy[0]));
                }
                $this->state['sampling'] = $factors;
                break;
            case 'define':
                [$key, $val] = explode('=', $value, 2);
                $this->state['defines'][$key] = $val;
                break;
            case 'limit':
                // own values may only lower the upper limits; thread and throttle are ignored,
                // time only counts in a process of its own (see class comment)
                $name = strtolower($value);
                if ($name === 'time' && $this->timeLimit) {
                    $wanted = (int)$step['args'][1];
                    $max = (int)($limits['time'] ?? 0);
                    if ($wanted > 0) $this->setTime($max > 0 ? min($wanted, $max) : $wanted);
                    break;
                }
                if (!isset(self::RESOURCES[$name])) break;
                $wanted = self::size((string)$step['args'][1]);
                $max = self::size((string)($limits[$name] ?? ''));
                $this->setLimit($name, $max === null ? $wanted : min($wanted, $max));
                break;
            // -monitor, -quiet: nothing to do
        }
    }

    /** Settings that belong to the image itself, applied right after reading */
    protected function applyReadSettings(\Imagick $image): void
    {
        foreach ($this->frames($image) as $frame) {
            if ($this->state['density'] !== null) $frame->setImageResolution(...$this->state['density']);
            if ($this->state['units'] !== null) $frame->setImageUnits($this->state['units']);
        }
    }

    /** Carry out one step; returns the (possibly new) image sequence */
    protected function step(\Imagick $image, array $step, array $limits): \Imagick
    {
        $name = $step['name'];
        $value = (string)($step['args'][0] ?? '');
        $lower = strtolower($value);

        if ($step['sign'] === '+') {
            switch ($name) {
                case 'repage':
                    foreach ($this->frames($image) as $frame) $frame->setImagePage(0, 0, 0, 0);
                    break;
                case 'gravity':
                    $this->state['gravity'] = 'northwest';
                    break;
                case 'dither':
                    $this->state['dither'] = false;
                    break;
                case 'profile':
                    foreach ($this->frames($image) as $frame) {
                        foreach (explode(',', $value) as $pattern) $frame->profileImage($pattern, null);
                    }
                    break;
            }
            return $image;
        }

        if (in_array($name, self::SETTINGS, true)) {
            $this->setting($step, $limits);
            if (in_array($name, ['density', 'units'], true)) $this->applyReadSettings($image);
            return $image;
        }

        if (in_array($name, self::SEQUENCE_OPERATORS, true)) {
            return $this->sequence($image, $name, $lower);
        }

        foreach ($this->frames($image) as $frame) {
            $this->operator($frame, $name, $value, $lower);
        }
        return $image;
    }

    /** Operators on the whole sequence */
    protected function sequence(\Imagick $image, string $name, string $method): \Imagick
    {
        if ($name === 'coalesce' || ($name === 'layers' && $method === 'coalesce')) return $image->coalesceImages();
        if ($name === 'deconstruct') return $image->deconstructImages();
        if ($name === 'flatten' || ($name === 'layers' && in_array($method, ['flatten', 'merge', 'mosaic'], true))) {
            $layer = ['flatten' => 'FLATTEN', 'merge' => 'MERGE', 'mosaic' => 'MOSAIC'][$name === 'flatten' ? 'flatten' : $method];
            $image->setImageBackgroundColor($this->pixel($this->state['background']));
            return $image->mergeImageLayers((int)constant(\Imagick::class . '::LAYERMETHOD_' . $layer));
        }
        // optimize, optimizeplus, optimizeframe
        return $image->optimizeImageLayers();
    }

    /** One operator on one single image */
    protected function operator(\Imagick $frame, string $name, string $value, string $lower): void
    {
        $w = (int)$frame->getImageWidth();
        $h = (int)$frame->getImageHeight();
        switch ($name) {
            case 'auto-orient':
                $frame->autoOrient();
                break;
            case 'strip':
                $frame->stripImage();
                break;
            case 'flip':       $frame->flipImage(); break;
            case 'flop':       $frame->flopImage(); break;
            case 'transpose':  $frame->transposeImage(); break;
            case 'transverse': $frame->transverseImage(); break;
            case 'negate':     $frame->negateImage(false); break;
            case 'normalize':  $frame->normalizeImage(); break;
            case 'equalize':   $frame->equalizeImage(); break;
            case 'enhance':    $frame->enhanceImage(); break;
            case 'despeckle':  $frame->despeckleImage(); break;
            case 'contrast':   $frame->contrastImage(true); break;
            case 'auto-level': $frame->autoLevelImage(); break;
            case 'auto-gamma': $frame->autoGammaImage(); break;
            case 'trim':
                $frame->trimImage($this->state['fuzz']);
                break;
            case 'monochrome':
                $frame->quantizeImage(2, \Imagick::COLORSPACE_GRAY, 0, $this->state['dither'], false);
                break;
            case 'colors':
                $frame->quantizeImage(max(1, (int)$value), (int)$frame->getImageColorspace(), 0, $this->state['dither'], false);
                break;
            case 'posterize':
                $frame->posterizeImage(max(2, (int)$value), $this->state['dither']);
                break;
            case 'colorspace':
                $frame->transformImageColorspace((int)self::constant('COLORSPACE_', $value));
                break;
            case 'type':
                $frame->setImageType((int)self::constant('IMGTYPE_', $value));
                break;
            case 'gamma':
                $frame->gammaImage((float)$value);
                break;
            case 'transparent':
                $frame->transparentPaintImage($this->pixel($value), 0.0, $this->state['fuzz'], false);
                break;
            case 'alpha':
                if (self::ALPHA[$lower] === 'REMOVE' || self::ALPHA[$lower] === 'BACKGROUND') {
                    $frame->setImageBackgroundColor($this->pixel($this->state['background']));
                }
                $frame->setImageAlphaChannel((int)self::constant('ALPHACHANNEL_', self::ALPHA[$lower]));
                break;
            case 'rotate':
                $degrees = (float)rtrim($value, '<>');
                // ">" only if the image is wider than high, "<" only if it is higher than wide
                if (str_ends_with($value, '>') && $w <= $h) break;
                if (str_ends_with($value, '<') && $w >= $h) break;
                $frame->rotateImage($this->pixel($this->state['background']), $degrees);
                break;
            case 'blur':
            case 'gaussian-blur':
            case 'sharpen':
            case 'unsharp':
                [$radius, $sigma, $amount, $threshold] = self::floats($value) + [0.0, 1.0, 1.0, 0.05];
                if ($name === 'blur') $frame->blurImage($radius, $sigma);
                elseif ($name === 'gaussian-blur') $frame->gaussianBlurImage($radius, $sigma);
                elseif ($name === 'sharpen') $frame->sharpenImage($radius, $sigma);
                else $frame->unsharpMaskImage($radius, $sigma, $amount, $threshold);
                break;
            case 'modulate':
                $parts = array_map('floatval', explode(',', $value)) + [100.0, 100.0, 100.0];
                $frame->modulateImage($parts[0], $parts[1], $parts[2]);
                break;
            case 'brightness-contrast':
                $parts = explode('x', str_replace('%', '', $value));
                $frame->brightnessContrastImage((float)$parts[0], (float)($parts[1] ?? 0));
                break;
            case 'border':
                $g = self::geometry($value);
                $bw = (int)($g['w'] ?? 0);
                $bh = (int)($g['h'] ?? $bw);
                $frame->borderImage($this->pixel($this->state['bordercolor']), $bw, $bh);
                break;
            case 'shave':
                $g = self::geometry($value);
                $sw = (int)($g['w'] ?? 0);
                $frame->shaveImage($sw, (int)($g['h'] ?? $sw));
                break;
            case 'crop':
                [$cw, $ch, $cx, $cy] = $this->region(self::geometry($value), $w, $h);
                $frame->cropImage($cw, $ch, $cx, $cy);
                break;
            case 'extent':
                [$ew, $eh, $ex, $ey] = $this->region(self::geometry($value), $w, $h);
                $frame->setImageBackgroundColor($this->pixel($this->state['background']));
                $frame->extentImage($ew, $eh, $ex, $ey);
                break;
            case 'resize':
            case 'thumbnail':
            case 'sample':
            case 'scale':
            case 'adaptive-resize':
                $size = self::resizeSize($w, $h, self::geometry($value));
                if ($size === null || ($size[0] === $w && $size[1] === $h && $name !== 'thumbnail')) break;
                [$nw, $nh] = $size;
                if ($name === 'resize') $frame->resizeImage($nw, $nh, (int)self::constant('FILTER_', $this->state['filter']), 1.0);
                elseif ($name === 'thumbnail') $frame->thumbnailImage($nw, $nh, false);
                elseif ($name === 'sample') $frame->sampleImage($nw, $nh);
                elseif ($name === 'scale') $frame->scaleImage($nw, $nh);
                else $frame->adaptiveResizeImage($nw, $nh, false);
                break;
        }
    }

    // Helpers
    // ====================

    /** All single images of a sequence, each as the current image */
    protected function frames(\Imagick $image): \Generator
    {
        $count = $image->getNumberImages();
        for ($i = 0; $i < $count; $i++) {
            $image->setIteratorIndex($i);
            yield $image;
        }
        if ($count > 0) $image->setIteratorIndex(0);
    }

    /**
     * Region of -crop / -extent: size and offset after -gravity, as GravityAdjustGeometry()
     * of ImageMagick computes it. A missing width or height is that of the image.
     *
     * @return array [width, height, x, y]
     */
    protected function region(array $g, int $imgW, int $imgH): array
    {
        $percent = str_contains($g['flags'], '%');
        $w = $g['w'] === null ? $imgW : (int)round($percent ? $imgW * $g['w'] / 100 : $g['w']);
        $h = $g['h'] === null ? ($g['w'] !== null && $percent ? (int)round($imgH * $g['w'] / 100) : $imgH)
            : (int)round($percent ? $imgH * $g['h'] / 100 : $g['h']);
        $x = $g['x'];
        $y = $g['y'];
        $gravity = $this->state['gravity'];
        if (in_array($gravity, ['northeast', 'east', 'southeast'], true)) $x = $imgW - $w - $x;
        elseif (in_array($gravity, ['north', 'center', 'south'], true)) $x += intdiv($imgW, 2) - intdiv($w, 2);
        if (in_array($gravity, ['southwest', 'south', 'southeast'], true)) $y = $imgH - $h - $y;
        elseif (in_array($gravity, ['west', 'center', 'east'], true)) $y += intdiv($imgH, 2) - intdiv($h, 2);
        return [max(1, $w), max(1, $h), $x, $y];
    }

    /**
     * Parse a geometry: "440x300^", "50%", "1000000@>", "300x200+10+5".
     *
     * @return array|null ['w' => ?float, 'h' => ?float, 'x' => int, 'y' => int, 'flags' => string]
     */
    public static function geometry(string $value): ?array
    {
        $flags = '';
        foreach (['%', '!', '<', '>', '^', '@'] as $flag) {
            if (str_contains($value, $flag)) $flags .= $flag;
        }
        $core = str_replace(['%', '!', '<', '>', '^', '@'], '', $value);
        if (!preg_match('/^(\d+(?:\.\d+)?)?(?:x(\d+(?:\.\d+)?)?)?([+-]\d+)?([+-]\d+)?$/i', $core, $m)) return null;
        $w = ($m[1] ?? '') !== '' ? (float)$m[1] : null;
        $h = ($m[2] ?? '') !== '' ? (float)$m[2] : null;
        if ($w === null && $h === null && ($m[3] ?? '') === '') return null;
        return ['w' => $w, 'h' => $h, 'x' => (int)($m[3] ?? 0), 'y' => (int)($m[4] ?? 0), 'flags' => $flags];
    }

    /**
     * New size for -resize & co. - the meaning of the geometry flags of ImageMagick:
     * fit (default), fill (^), exact (!), percent (%), area (@), only shrink (>),
     * only enlarge (<).
     *
     * @param ?bool $roundArea  area (@): true rounds the sides (ImageMagick 7), false truncates
     *                          them (ImageMagick 6); null = as the library of the extension does
     * @return array|null [width, height], null: leave the size unchanged
     */
    public static function resizeSize(int $w0, int $h0, ?array $g, ?bool $roundArea = null): ?array
    {
        if ($g === null || $w0 < 1 || $h0 < 1) return null;
        $f = $g['flags'];
        $gw = $g['w'];
        $gh = $g['h'];
        if (str_contains($f, '@')) {
            // ImageMagick 6 truncates the sides, ImageMagick 7 rounds them
            // (800x1200 with 40000@: 163x244 or 163x245)
            $roundArea ??= self::roundsArea();
            $area = (float)($gw ?? 0);
            if ($area <= 0) return null;
            $scale = sqrt($area / ($w0 * $h0));
            $nw = max(1, (int)($roundArea ? floor($w0 * $scale + 0.5) : $w0 * $scale));
            $nh = max(1, (int)($roundArea ? floor($h0 * $scale + 0.5) : $h0 * $scale));
            if (str_contains($f, '>') && $scale >= 1) return null;
            if (str_contains($f, '<') && $scale <= 1) return null;
            return [$nw, $nh];
        }
        elseif (str_contains($f, '%')) {
            $px = $gw ?? $gh;
            $py = $gh ?? $gw;
            $nw = $w0 * $px / 100;
            $nh = $h0 * $py / 100;
        }
        else {
            if ($gw === null && $gh === null) return null;
            if (str_contains($f, '!')) {
                $nw = $gw ?? $w0 * $gh / $h0;
                $nh = $gh ?? $h0 * $gw / $w0;
            }
            else {
                $scales = [];
                if ($gw !== null && $gw > 0) $scales[] = $gw / $w0;
                if ($gh !== null && $gh > 0) $scales[] = $gh / $h0;
                if (!$scales) return null;
                $scale = str_contains($f, '^') ? max($scales) : min($scales);
                $nw = $w0 * $scale;
                $nh = $h0 * $scale;
            }
        }
        $nw = max(1, (int)floor($nw + 0.5));
        $nh = max(1, (int)floor($nh + 0.5));
        // only shrink: if neither side gets smaller, nothing changes; only enlarge: the reverse
        if (str_contains($f, '>') && $nw >= $w0 && $nh >= $h0) return null;
        if (str_contains($f, '<') && $nw <= $w0 && $nh <= $h0) return null;
        return [$nw, $nh];
    }

    /** "RxS+A+T" -> [R, S, A, T] (only the parts given), null if not a number list */
    protected static function floats(string $value): ?array
    {
        if (!preg_match('/^(\d+(?:\.\d+)?)(?:x(\d+(?:\.\d+)?))?(?:\+(\d+(?:\.\d+)?))?(?:\+(\d+(?:\.\d+)?))?$/', $value, $m)) return null;
        return array_map('floatval', array_values(array_filter(array_slice($m, 1), fn($v) => $v !== '')));
    }

    /** Constant of the Imagick class by name, e.g. ('COLORSPACE_', 'sRGB') -> 13 */
    protected static function constant(string $prefix, string $name): ?int
    {
        if (!self::available()) return null;
        $name = strtoupper(str_replace('-', '', $name));
        if (preg_match('/[^A-Z0-9_]/', $name)) return null;
        $constant = \Imagick::class . '::' . $prefix . $name;
        return defined($constant) ? (int)constant($constant) : null;
    }

    /** A color value: name, #hex, rgb()/rgba(), "none" */
    protected static function color(string $value): ?string
    {
        if (!preg_match('/^(#[0-9a-f]{3,8}|[a-z]+[0-9]*|(rgb|rgba|srgb|srgba|gray|hsl|hsla)\([0-9.,% ]+\))$/i', $value)) return null;
        if (!self::available()) return $value;
        try {
            new \ImagickPixel($value);
            return $value;
        }
        catch (\Throwable) {
            return null;
        }
    }

    protected function pixel(string $color): \ImagickPixel
    {
        return new \ImagickPixel(strtolower($color) === 'none' ? 'transparent' : $color);
    }

    /** -fuzz in quantum units: "10%" or an absolute value */
    protected function fuzz(string $value): float
    {
        $range = (float)(\Imagick::getQuantumRange()['quantumRangeLong'] ?? 65535);
        return str_ends_with($value, '%') ? $range * (float)rtrim($value, '%') / 100 : (float)$value;
    }

    /**
     * A size as in the policy: "256MiB", "1GiB", "16KP", "128MP", "128", "1e6"
     */
    public static function size(string $value): ?float
    {
        if (!preg_match('/^(\d+(?:\.\d+)?(?:e\d+)?)\s*([KMGT]?)(i?)(B|P)?$/i', trim($value), $m)) return null;
        $number = (float)$m[1];
        $power = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4][strtoupper($m[2])];
        $base = strtolower($m[3]) === 'i' ? 1024 : 1000;
        return $number * $base ** $power;
    }

    /** Set the upper limits (values of the policy); the time limit only with $timeLimit (see above) */
    protected function setLimits(array $limits): void
    {
        foreach (self::RESOURCES as $name => $constant) {
            $value = self::size((string)($limits[$name] ?? ''));
            if ($value !== null) $this->setLimit($name, $value);
        }
        if ($this->timeLimit && (int)($limits['time'] ?? 0) > 0) $this->setTime((int)$limits['time']);
    }

    /** Time limit in seconds (RESOURCETYPE_TIME, from ImageMagick 6.8.0) */
    protected function setTime(int $seconds): void
    {
        if (defined(\Imagick::class . '::RESOURCETYPE_TIME')) {
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_TIME, $seconds);
        }
    }

    protected function setLimit(string $name, float $value): void
    {
        $constant = \Imagick::class . '::RESOURCETYPE_' . self::RESOURCES[$name];
        if (defined($constant)) \Imagick::setResourceLimit((int)constant($constant), (int)$value);
    }
}
