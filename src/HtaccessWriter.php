<?php
/**
 * Der generierte Block in public/.htaccess (Docroot).
 *
 * Im Frontend läuft jede Anfrage über index.php - AUSSER sie zeigt auf eine echte
 * Datei oder in eines der Unterverzeichnisse des Docroots (media/, backend/, …).
 * Diese Ausnahmen stehen als RewriteCond-Zeilen im verwalteten Block zwischen
 * '## CAMINDO SYNTAX START' und '## CAMINDO SYNTAX END'; alles davor und dahinter
 * bleibt unangetastet (mergeManagedBlock()). Eine CRC32-Prüfsumme über die sortierte
 * Verzeichnisliste steht mit im Block - hasChanges() vergleicht sie mit dem
 * Dateisystem und sagt, ob die Datei veraltet ist.
 *
 * Vorlage: Core/backend/html/htaccess.html (Platzhalter {exclusions}, {checksum}) -
 * hier direkt gelesen, nicht über Backend::template(), damit die Klasse ohne
 * Backend läuft: camindo.php upgrade und der Installer schreiben die Datei genauso
 * wie der Reiter Server im Modul Tools (ToolServer).
 *
 * @see Camindo\Core\Modules\Tools\ToolServer  Backend-Oberfläche dazu
 * @see docs/developers/14-installation-und-update.md, 6.2 Schritt 8
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
     * Schreibt den verwalteten Block neu (überschreibt immer).
     * Manuell gepflegte Zeilen außerhalb des Blocks bleiben erhalten.
     *
     * @return bool  false, wenn die Datei nicht geschrieben werden konnte
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
     * true, wenn sich die Verzeichnisstruktur gegenüber der in der .htaccess
     * gespeicherten Prüfsumme geändert hat (oder noch keine .htaccess existiert).
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
     * Sortierte Liste der Unterverzeichnisse im Docroot. Punkt-Einträge werden
     * übersprungen - echte Dateien darin liefert die !-f-Bedingung direkt aus.
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
     * RewriteCond-Zeilen, die reale Dateien (!-f) und die vorhandenen Verzeichnisse
     * von der Umleitung auf index.php ausschließen. Endet immer mit Newline.
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
     * 8-stellige CRC32-Prüfsumme über die sortierte Verzeichnisliste - kein
     * Manipulationsschutz, nur "hat sich die Liste seit dem Schreiben geändert?".
     */
    private function directoryChecksum(array $dirs): string
    {
        return sprintf('%08x', crc32(implode("\n", $dirs)));
    }

    /**
     * Ersetzt den CAMINDO-Block in $current durch $block und behält alles außerhalb
     * der Markierungen. Fehlt der Block, wird ein Grundgerüst (RewriteEngine/
     * RewriteBase) erzeugt.
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
