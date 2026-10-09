<?php
/**
 * Collect HTML templates and write them as a PHP cache - var/cache/html.
 *
 * The counterpart of SnippetCache for the .html files. Backend::template() reads
 * exclusively from the cache - after EVERY change to a template it must be rebuilt
 * (Tools > Developer > HTML templates, `camindo.php cache html`,
 * `upgrade`; install/update of a component builds its prefix).
 *
 * WHAT IS COLLECTED
 * --------------------
 *     {core}/backend/html                        without prefix  -> cache.php
 *     {dir}/Modules/{Name}/backend/html          prefix {name}   -> cache.{name}.php
 *     {dir}/Processors/{Name}/backend/html       prefix proc.{name}
 *     {dir}/Extensions/{Name}/backend/html       prefix ext.{name}
 *     ... each for {dir} = camindo_dir and core_dir
 *
 * Each prefix yields ONE file; {camindo_dir} is read first, {core_dir}
 * afterwards - an add-on component with the same name cannot replace a core
 * template file (same rule as for the snippets).
 *
 * The cache file contains assignments of the form
 *     $this->htmlTemplates['admin'] = ['admin_table_row' => '…', …];
 * and is included by Backend - hence assignments instead of return.
 *
 * @see Camindo\Core\Backend::template()
 * @see Camindo\Core\SnippetCache
 * @see Camindo\Core\Modules\Tools\ToolHtmlTemplates  backend UI for this
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
     * Build the cache - everything or only one component.
     *
     * @param ?string $prefix  null: everything (old files are removed beforehand);
     *                         otherwise the prefix of ONE component ('record',
     *                         'proc.magick', '' for the core)
     * @return array  'dirs'  searched directories (prefix => path)
     *                'files' number of written cache files
     * @throws \Exception  if the cache directory cannot be created
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

        // The file written last wins - sourceDirs() returns {core_dir} last
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
     * Remove the cache file of ONE component - when uninstalling.
     *
     * @return int  number of deleted files (0 or 1)
     */
    public function remove(string $prefix): int
    {
        $file = $this->file($prefix);
        return is_file($file) && unlink($file) ? 1 : 0;
    }

    /**
     * Prefixes of all existing components (from the source directories), '' = core.
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
     * Store all .html files of a directory as one cache file.
     * Template name = file name without extension, content unchanged as a PHP literal.
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
     * Source directories: core first, then the components under {camindo_dir},
     * last the ones under {core_dir}.
     *
     * @return array  list of [directory, prefix]
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
