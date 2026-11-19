<?php
/**
 * Invisible watermark in images (external program camindo-watermark).
 *
 * The program hides a code of one to six characters (A-Z and "-") in the frequency domain
 * of an image. The code survives scaling, JPEG compression, turning by 90°
 * and mirroring - not cropping. Every instance can have its own code
 * (instance.details.watermark_code); upload and thumbnail fields with the
 * switch details.watermark mark their images with it:
 *
 *     upload field      VirtualFieldsTrait::_saveUploadField() after the EXIF turn
 *     thumbnail field   VirtualFieldsTrait::writeThumbnails() after the converter,
 *                       background thumbnails in TriggerMedia::finalize()
 *     image editing     RecordBackendImageEdit (the result is marked again)
 *
 * Configuration (.camindo.php):
 *
 *     paths.watermark            the program ('' = function off: files stay unmarked;
 *                                configured but not usable: saving such files fails)
 *     paths.watermark_key_file   secret key, created by "camindo.php watermark key"
 *
 * The key decides whether a watermark can be read at all: with another key
 * the detection finds nothing. Never replace it while marked images are in
 * circulation - keep the old one for checking.
 *
 * Formats: JPEG and PNG directly; WebP over a lossless intermediate step
 * with ImageMagick (if installed). Other formats (GIF, SVG, AVIF, TIFF …) stay
 * unmarked.
 *
 * @see developer docs, chapter 07 (Media and background processes)
 */
namespace Camindo\Core;

use Camindo\Core\Processors\Magick\Magick;

class Watermark {

    /** Formats the program reads and writes itself */
    public const DIRECT_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    /** Formats the program reads but cannot write: ImageMagick writes them from a PNG */
    public const MAGICK_EXTENSIONS = ['webp'];

    /** JPEG and WebP quality if neither the converter nor the file gives one
        (ImageMagick estimates JPEG quality, but not that of WebP) */
    public const DEFAULT_QUALITY = 92;
    public const DEFAULT_QUALITY_WEBP = 80;

    protected const TIMEOUT = 180;

    /** codeForField() per field id within one request */
    private array $fieldCodes = [];

    public function __construct(
        protected Config $config,
        protected Database $db,
        protected Execute $execute,
        protected Crypt $crypt,
        protected ProcessorFactory $factory,
    ) {}

    /** Path of the program from paths.watermark ('' = not configured) */
    public function binary(): string
    {
        return trim((string)($this->config->get('paths.watermark') ?? ''));
    }

    /** Path of the key file */
    public function keyFile(): string
    {
        return (string)($this->config->get('paths.watermark_key_file') ?? '');
    }

    /**
     * Is the function ready? Program executable, key file readable.
     *
     * @param ?string $problem  'binary' | 'key' if not
     */
    public function available(?string &$problem = null): bool
    {
        $problem = null;
        $binary = $this->binary();
        if ($binary === '' || !is_file($binary) || !is_executable($binary)) {
            $problem = 'binary';
            return false;
        }
        $key = $this->keyFile();
        if ($key === '' || !is_readable($key) || strlen(trim((string)@file_get_contents($key))) < 8) {
            $problem = 'key';
            return false;
        }
        return true;
    }

    /**
     * Create the key file if it does not exist (32 random bytes, base64, mode 0600).
     *
     * @return bool  true = created, false = existed already or could not be written
     */
    public function createKey(?string &$error = null): bool
    {
        $error = '';
        $file = $this->keyFile();
        if ($file === '') {
            $error = 'paths.watermark_key_file is empty';
            return false;
        }
        if (file_exists($file)) {
            $error = 'exists';
            return false;
        }
        $handle = @fopen($file, 'x');
        if ($handle === false) {
            $error = 'cannot write ' . $file;
            return false;
        }
        @chmod($file, 0600);
        fwrite($handle, base64_encode(random_bytes(32)) . "\n");
        fclose($handle);
        // CLI as root: the web server must be able to read the file
        if (SecretFile::isRoot() && ($user = SecretFile::guessWebUser(dirname($file))) !== null) {
            SecretFile::chown($file, $user);
        }
        return true;
    }

    /** Longest code: 28^6 combinations fit into the 32 bits of the program, 28^7 not */
    public const CODE_MAX_LENGTH = 6;

    /**
     * Normalize a code: 1-6 characters A-Z and "-", lower case is converted,
     * surrounding blanks are dropped. null = invalid.
     */
    public static function normalizeCode(string $code): ?string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z-]{1,' . self::CODE_MAX_LENGTH . '}$/', $code)) return null;
        return $code;
    }

    /**
     * Code with which the files of this field are marked: switch details.watermark
     * of the field and code of the instance of its area. null = no watermark.
     */
    public function codeForField(int $fieldId): ?string
    {
        if ($fieldId < 1) return null;
        if (array_key_exists($fieldId, $this->fieldCodes)) return $this->fieldCodes[$fieldId];
        $this->db->pushResult();
        $this->db->query(
            'SELECT `f`.`details` AS `field_details`, `i`.`details` AS `instance_details` FROM `field` `f`'
            . ' JOIN `area` `a` ON `a`.`area_id` = `f`.`area_id`'
            . ' JOIN `instance` `i` ON `i`.`instance_id` = `a`.`instance_id`'
            . ' WHERE `f`.`field_id` = ?',
            'i', [$fieldId]
        );
        $row = $this->db->fetch() ?: [];
        $this->db->popResult();
        $fieldDetails = json_decode((string)($row['field_details'] ?? ''), true);
        $instanceDetails = json_decode((string)($row['instance_details'] ?? ''), true);
        $code = null;
        if (!empty($fieldDetails['watermark'])) {
            $code = self::normalizeCode((string)($instanceDetails['watermark_code'] ?? ''));
        }
        return $this->fieldCodes[$fieldId] = $code;
    }

    /** Forget the codes read in this request (after changes to fields/instances) */
    public function clearCache(): void
    {
        $this->fieldCodes = [];
    }

    /**
     * Instances that use a code - for the result of a check.
     *
     * @return array  [['instance_id' => int, 'label' => string], …]
     */
    public function instancesForCode(string $code): array
    {
        $this->db->pushResult();
        $this->db->query('SELECT `instance_id`, `label`, `details` FROM `instance` ORDER BY `position`, `instance_id`');
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        $found = [];
        foreach ($rows as $row) {
            $details = json_decode((string)($row['details'] ?? ''), true);
            if (self::normalizeCode((string)($details['watermark_code'] ?? '')) === $code) {
                $found[] = ['instance_id' => (int)$row['instance_id'], 'label' => (string)$row['label']];
            }
        }
        return $found;
    }

    /** ImageMagick processor, if set up (WebP/AVIF) */
    protected function magick(): ?Magick
    {
        try {
            $processor = $this->factory->create('Magick');
        }
        catch (\Throwable) {
            return null;
        }
        return ($processor instanceof Magick && $processor->available()) ? $processor : null;
    }

    /** Can a file of this type be marked? (format only, not whether the program is set up) */
    public function supports(string $mime, string $extension): bool
    {
        $extension = strtolower($extension);
        if ($mime === 'image/gif' || str_contains($mime, 'svg')) return false;
        if (in_array($extension, self::DIRECT_EXTENSIONS, true)) return true;
        return in_array($extension, self::MAGICK_EXTENSIONS, true) && $this->magick() !== null;
    }

    /**
     * Embed the code into a file (in place).
     *
     * @param string $file       absolute path
     * @param string $mime       MIME type of the file
     * @param string $extension  its extension
     * @param string $code       normalized code (normalizeCode())
     * @param int    $quality    JPEG/WebP quality of the result, 0 = that of the file
     *                           (ImageMagick) or DEFAULT_QUALITY(_WEBP)
     * @param ?string $error     reason on failure
     * @return array|null        ['verified' => bool, 'confidence' => float, 'size' => int]
     *                           (verified = false: image with too little texture, the
     *                           code may not be readable reliably); null = error
     */
    public function apply(string $file, string $mime, string $extension, string $code, int $quality = 0, ?string &$error = null): ?array
    {
        $error = '';
        $extension = strtolower($extension);
        if (!$this->available($problem)) {
            $error = 'not available (' . $problem . ')';
            return null;
        }
        if (!is_file($file)) {
            $error = 'file not found';
            return null;
        }
        $viaMagick = !in_array($extension, self::DIRECT_EXTENSIONS, true);
        $magick = $this->magick();
        // without a given quality: that of the file itself (ImageMagick estimates it
        // from the quantization tables), so the file does not grow or lose
        if ($quality <= 0 && $magick !== null && in_array($extension, ['jpg', 'jpeg', 'webp'], true)) {
            $quality = (int)($magick->imageInfo($file, $mime, $extension)['quality'] ?? 0);
        }
        if ($quality <= 0) $quality = $extension === 'webp' ? self::DEFAULT_QUALITY_WEBP : self::DEFAULT_QUALITY;
        $quality = min(100, max(50, $quality));
        if ($viaMagick && ($magick === null || !in_array($extension, self::MAGICK_EXTENSIONS, true))) {
            $error = 'format not supported: ' . $extension;
            return null;
        }

        $dir = dirname($file);
        $random = $this->crypt->randomKey(8);
        $tmpOut = $dir . '/.wm-' . $random . '.' . ($viaMagick ? 'png' : $extension);
        $result = $this->run([
            $this->binary(), 'embed', '-json', '-key-file', $this->keyFile(),
            '-code', $code, '-quality', (string)$quality, '-in', $file, '-out', $tmpOut,
        ], $error);
        if ($result === null || empty($result['ok'])) {
            @unlink($tmpOut);
            return null;
        }

        if ($viaMagick) {
            // lossless intermediate step -> original format with the quality given
            $tmpFinal = $dir . '/.wm-' . $random . '.' . $extension;
            $rendered = $magick->renderEdit($tmpOut, 'image/png', 'png', $tmpFinal, $extension, [], 0, $quality, $magickError);
            @unlink($tmpOut);
            if ($rendered === null) {
                @unlink($tmpFinal);
                $error = 'magick: ' . $magickError;
                return null;
            }
            $tmpOut = $tmpFinal;
        }

        @chmod($tmpOut, fileperms($file) & 0777);
        if (!@rename($tmpOut, $file)) {
            @unlink($tmpOut);
            $error = 'cannot replace ' . basename($file);
            return null;
        }
        clearstatcache(true, $file);
        return [
            'verified'   => !empty($result['verified']),
            'confidence' => (float)($result['confidence'] ?? 0),
            'size'       => (int)filesize($file),
        ];
    }

    /**
     * Check a file for a watermark.
     *
     * @return array|null  JSON of the program ('status' found|present_unreadable|none,
     *                     'code', 'id', 'confidence', 'width', 'height' …) plus
     *                     'instances' (instancesForCode()); null = error
     */
    public function detect(string $file, ?string &$error = null): ?array
    {
        $error = '';
        if (!$this->available($problem)) {
            $error = 'not available (' . $problem . ')';
            return null;
        }
        $result = $this->run([$this->binary(), 'detect', '-json', '-key-file', $this->keyFile(), '-in', $file], $error, [0, 1]);
        if ($result === null) return null;
        if (($result['status'] ?? '') === 'error') {
            $error = (string)($result['error'] ?? 'error');
            return null;
        }
        $result['instances'] = isset($result['code']) ? $this->instancesForCode((string)$result['code']) : [];
        return $result;
    }

    /** Version of the program, null = not executable */
    public function version(): ?string
    {
        $binary = $this->binary();
        if ($binary === '' || !is_executable($binary)) return null;
        $exit = $this->execute->execute([$binary, 'version'], $stdout, $stderr, 10);
        return $exit === 0 ? trim((string)$stdout) : null;
    }

    /**
     * Run the program and decode its JSON output.
     *
     * @param int[] $okCodes  exit codes that count as success
     */
    protected function run(array $command, ?string &$error, array $okCodes = [0]): ?array
    {
        $exit = $this->execute->execute($command, $stdout, $stderr, self::TIMEOUT);
        $json = json_decode(trim((string)$stdout), true);
        if (!in_array($exit, $okCodes, true) || !is_array($json)) {
            $error = trim((string)$stderr) ?: (is_array($json) ? (string)($json['error'] ?? '') : '') ?: 'exit code ' . $exit;
            return null;
        }
        return $json;
    }
}
