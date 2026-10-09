<?php
/**
 * HTML-Vorlagen einsammeln und als PHP-Cache schreiben - var/cache/html.
 *
 * Das Gegenstück zu SnippetCache für die .html-Dateien. Backend::template() liest
 * ausschließlich aus dem Cache - nach JEDER Änderung an einer Vorlage muss er neu
 * gebaut werden (Tools > Developer > HTML templates, `camindo.php cache html`,
 * `upgrade`; install/update einer Komponente bauen ihren Präfix).
 *
 * WAS EINGESAMMELT WIRD
 * --------------------
 *     {core}/backend/html                        ohne Präfix     -> cache.php
 *     {dir}/Modules/{Name}/backend/html          Präfix {name}   -> cache.{name}.php
 *     {dir}/Processors/{Name}/backend/html       Präfix proc.{name}
 *     {dir}/Extensions/{Name}/backend/html       Präfix ext.{name}
 *     ... jeweils für {dir} = camindo_dir und core_dir
 *
 * Je Präfix entsteht EINE Datei; {camindo_dir} wird zuerst gelesen, {core_dir}
 * danach - eine gleichnamige Zusatzkomponente kann eine Core-Vorlagendatei also
 * nicht ersetzen (gleiche Regel wie bei den Snippets).
 *
 * Die Cachedatei enthält Zuweisungen der Form
 *     $this->htmlTemplates['admin'] = ['admin_table_row' => '…', …];
 * und wird von Backend per include eingebunden - daher Zuweisungen statt return.
 *
 * @see Camindo\Core\Backend::template()
 * @see Camindo\Core\SnippetCache
 * @see Camindo\Core\Modules\Tools\ToolHtmlTemplates  Backend-Oberfläche dazu
 */
namespace Camindo\Core;

class HtmlCache {

    public function __construct(
        private Config $config,
        private System $system,
    )
    {
    }

    /**
     * Cache bauen - alles oder nur eine Komponente.
     *
     * @param ?string $prefix  null: alles (alte Dateien werden vorher entfernt);
     *                         sonst der Präfix EINER Komponente ('record',
     *                         'proc.magick', '' für den Core)
     * @return array  'dirs'  durchsuchte Verzeichnisse (Präfix => Pfad)
     *                'files' Anzahl geschriebener Cachedateien
     * @throws \Exception  wenn das Cacheverzeichnis nicht angelegt werden kann
     */
    public function build(?string $prefix = null): array
    {
        $varDir = (string)$this->config->get('paths.var_dir');
        $cachePath = '/cache/html';
        if (!$this->system->createDir($varDir, $cachePath)) {
            throw new \Exception("Error: Creation of directory \"$varDir$cachePath\" failed.");
        }

        if ($prefix === null) {
            foreach ((array)glob($varDir . $cachePath . '/cache*.php') as $file) unlink($file);
        }
        else {
            $this->remove($prefix);
        }

        // Die zuletzt geschriebene Datei gilt - sourceDirs() liefert {core_dir} zuletzt
        $dirs = [];
        $files = 0;
        foreach ($this->sourceDirs() as [$dir, $dirPrefix]) {
            if ($prefix !== null && $dirPrefix !== $prefix) continue;
            if (!is_dir($dir)) continue;
            $this->writeFile($dir, $dirPrefix);
            $dirs[$dirPrefix] = $dir;
            $files++;
        }
        return ['dirs' => $dirs, 'files' => $files];
    }

    /**
     * Cachedatei EINER Komponente entfernen - beim Deinstallieren.
     *
     * @return int  Anzahl gelöschter Dateien (0 oder 1)
     */
    public function remove(string $prefix): int
    {
        $file = $this->file($prefix);
        return is_file($file) && unlink($file) ? 1 : 0;
    }

    /**
     * Präfixe aller vorhandenen Komponenten (aus den Quellverzeichnissen), '' = Core.
     *
     * @return string[]
     */
    public function prefixes(): array
    {
        $prefixes = [];
        foreach ($this->sourceDirs() as [$dir, $prefix]) $prefixes[$prefix] = true;
        return array_keys($prefixes);
    }

    // ==== Helfer ====

    private function file(string $prefix): string
    {
        return (string)$this->config->get('paths.var_dir') . '/cache/html/cache' . ($prefix === '' ? '' : ".$prefix") . '.php';
    }

    /**
     * Alle .html-Dateien eines Verzeichnisses als eine Cachedatei ablegen.
     * Vorlagenname = Dateiname ohne Endung, Inhalt unverändert als PHP-Literal.
     */
    private function writeFile(string $dir, string $prefix): void
    {
        $php = "<?php\n// Generiert von Camindo\\Core\\HtmlCache - nicht von Hand ändern.\n"
            . "\$this->htmlTemplates[" . var_export($prefix, true) . "] = [\n";
        foreach ((array)glob($dir . '/*.html') as $file) {
            $php .= var_export(basename($file, '.html'), true) . ' => ' . var_export((string)file_get_contents($file), true) . ",\n";
        }
        $php .= "];\n";
        $this->system->filePutContents((string)$this->config->get('paths.var_dir'), '/cache/html/'
            . basename($this->file($prefix)), $php, lock: true);
    }

    /**
     * Quellverzeichnisse: Core zuerst, dann die Komponenten unter {camindo_dir},
     * zuletzt die unter {core_dir}.
     *
     * @return array  Liste von [Verzeichnis, Präfix]
     */
    private function sourceDirs(): array
    {
        $camindoDir = (string)$this->config->get('paths.camindo_dir');
        $coreDir = (string)$this->config->get('paths.core_dir');
        $dirs = [[$coreDir . '/backend/html', '']];
        $componentsDirs = ['' => '/Modules', 'proc.' => '/Processors', 'ext.' => '/Extensions'];
        foreach ([$camindoDir, $coreDir] as $mainDir) {
            foreach ($componentsDirs as $key => $componentsDir) {
                foreach ((array)glob($mainDir . $componentsDir . '/*', GLOB_ONLYDIR) as $subDir) {
                    $prefix = $key . preg_replace('/[^a-z0-9_]/', '', strtolower(basename($subDir)));
                    $dirs[] = [$subDir . '/backend/html', $prefix];
                }
            }
        }
        return $dirs;
    }
}
