<?php
/**
 * The generated block in public/.htaccess (docroot).
 *
 * In the frontend every request runs through index.php - EXCEPT when it points to a real
 * file or to one of the subdirectories of the docroot (media/, backend/, …).
 * These exceptions are RewriteCond lines in the managed block between
 * '## CAMINDO SYNTAX START' and '## CAMINDO SYNTAX END'; everything before and after
 * stays untouched (mergeManagedBlock()). A CRC32 checksum over the sorted
 * directory list is part of the block - hasChanges() compares it with the
 * file system and tells whether the file is outdated.
 *
 * Template: Core/backend/html/htaccess.html (placeholders {exclusions}, {checksum}) -
 * read directly here, not via Backend::template(), so the class runs without
 * the backend: camindo.php upgrade and the installer write the file the same way
 * as the Server tab in the Tools module (ToolServer).
 *
 * The file must keep its own 'RewriteEngine On' (mergeManagedBlock() ensures it):
 * that is what keeps the block of the project root's .htaccess (RewriteRule ^ - [F])
 * from being inherited by public/ (developer docs, chapter 01 (Architecture)).
 *
 * @see Camindo\Core\Modules\Tools\ToolServer  backend UI for this
 * @see developer docs, chapter 09 (Installation and update) (step 8)
 */
namespace Camindo\Core;

class HtaccessWriter {

    private const HT_START    = '## CAMINDO SYNTAX START';
    private const HT_END      = '## CAMINDO SYNTAX END';
    private const HT_CHECKSUM = '## CAMINDO SYNTAX CHECKSUM:';

    public function __construct(
        private Config $config,
        private System $system,
    )
    {
    }

    public function publicPath(): string
    {
        return rtrim((string)$this->config->get('paths.document_root'), '/\\');
    }

    public function file(): string
    {
        return $this->publicPath() . '/.htaccess';
    }

    /**
     * Rewrites the managed block (always overwrites).
     * Manually maintained lines outside the block are kept.
     *
     * @return bool  false if the file could not be written
     */
    public function update(): bool
    {
        $dirs = $this->publicDirectories();
        $block = strtr($this->template(), [
            '{exclusions}' => $this->buildExclusions($dirs),
            '{checksum}'   => $this->directoryChecksum($dirs),
        ]);

        $file    = $this->file();
        $current = is_file($file) ? (string)file_get_contents($file) : '';
        $merged  = $this->mergeManagedBlock($current, $block);

        return $this->system->filePutContents($this->publicPath(), '/.htaccess', $merged, lock: true);
    }

    /**
     * true if the directory structure has changed compared with the checksum
     * stored in the .htaccess (or if no .htaccess exists yet).
     */
    public function hasChanges(): bool
    {
        $file = $this->file();
        if (!is_file($file)) return true;

        $content = (string)file_get_contents($file);
        $pattern = '/' . preg_quote(self::HT_CHECKSUM, '/') . '([0-9a-f]{8})/';
        if (!preg_match($pattern, $content, $m)) return true;

        return $m[1] !== $this->directoryChecksum($this->publicDirectories());
    }

    // ==== Helfer ====

    private function template(): string
    {
        $file = (string)$this->config->get('paths.core_dir') . '/backend/html/htaccess.html';
        if (!is_file($file)) throw new \RuntimeException("template missing: $file");
        return (string)file_get_contents($file);
    }

    /**
     * Sorted list of the subdirectories in the docroot. Dot entries are
     * skipped - real files in there are handled directly by the !-f condition.
     *
     * @return string[]
     */
    private function publicDirectories(): array
    {
        $root = $this->publicPath();
        $dirs = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '' || $entry[0] === '.') continue;
            if (is_dir($root . '/' . $entry)) $dirs[] = $entry;
        }
        sort($dirs, SORT_STRING);
        return $dirs;
    }

    /**
     * RewriteCond lines that exclude real files (!-f) and the existing directories
     * from the redirect to index.php. Always ends with a newline.
     */
    private function buildExclusions(array $dirs): string
    {
        $lines = ['RewriteCond %{REQUEST_FILENAME} !-f'];
        foreach ($dirs as $dir) {
            $lines[] = sprintf('RewriteCond %%{REQUEST_URI}      !^/%s(/|$)', preg_quote($dir));
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * 8-digit CRC32 checksum over the sorted directory list - not tamper protection,
     * only "has the list changed since it was written?".
     */
    private function directoryChecksum(array $dirs): string
    {
        return sprintf('%08x', crc32(implode("\n", $dirs)));
    }

    /**
     * Replaces the CAMINDO block in $current with $block and keeps everything outside
     * the markers. If the block is missing, a basic skeleton (RewriteEngine/
     * RewriteBase) is created.
     */
    private function mergeManagedBlock(string $current, string $block): string
    {
        $block = rtrim($block, "\n");
        $start = strpos($current, self::HT_START);
        $end   = strpos($current, self::HT_END);

        if ($start !== false && $end !== false && $end > $start) {
            $before = rtrim(substr($current, 0, $start));
            $after  = rtrim(ltrim(substr($current, $end + strlen(self::HT_END)), "\n"));
            if ($before === '') $before = "RewriteEngine On\nRewriteBase /";
            return $before . "\n" . $block . "\n" . ($after === '' ? '' : $after . "\n");
        }

        $base = trim($current) !== '' ? rtrim($current) : "RewriteEngine On\nRewriteBase /";
        return $base . "\n\n" . $block . "\n";
    }
}
