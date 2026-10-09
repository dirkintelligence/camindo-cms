<?php
/**
 * Collect language snippets and write them as a PHP cache - var/cache/lang.
 *
 * The builder behind Tools/tool_snippets.php (ToolSnippets) and Core/cron/cache.php.
 * It depends only on Config and System, so that the CLI, installer and a later
 * component management can call it without a backend context. Must run after EVERY change
 * to a lang/ file - Cms::snippet() reads exclusively from the cache.
 *
 * WHAT IS COLLECTED
 * --------------------
 *     {core}/lang                        prefix core,        backend AND frontend
 *     {core}/backend/lang                prefix core,        backend only
 *     {dir}/Modules/{Name}/lang          prefix {name}       backend AND frontend
 *     {dir}/Modules/{Name}/backend/lang  prefix {name}       backend only
 *     {dir}/Processors/{Name}/lang       prefix proc.{name}  backend AND frontend
 *     {dir}/Processors/{Name}/backend/lang                   backend only
 *     {dir}/Extensions/{Name}/lang       prefix ext.{name}   backend AND frontend (developer docs, chapter 08 (Components and extensions))
 *     {dir}/Extensions/{Name}/backend/lang                   backend only
 *     ... in each case for {dir} = camindo_dir and core_dir
 *     {var}/translation/translated/*.ini translation packages (see below), prefix per key
 *     {var}/custom/snippets/{instance_id}/lang   prefix custom.{instance_id}
 *                                        (snippets of an instance, Snippets module;
 *                                         backend AND frontend)
 *
 * Later collected entries overwrite earlier ones with the same key and scope.
 * Since {camindo_dir} is traversed before {core_dir}, the core components win in the end -
 * an add-on component of the same name cannot replace a core snippet. A key that
 * appears in lang/ AND backend/lang/ of the same component is reported
 * (at runtime the frontend file wins).
 *
 * The language files are called lang/{code}.ini (format: SnippetIni, without sections,
 * key = "text"), {code} in the format Cms::LANG_PATTERN (de_DE.ini). Other files
 * in the lang directory are skipped; nothing is executed.
 *
 * ADDITIONAL LANGUAGES: var/translation/translated/*.ini
 * --------------------
 * One file per language for any number of components; the language is in the name:
 * {code}.ini or {code}_{anything}.ini (sv_SE.ini, sv_SE_core.ini, sv_SE_ext_tinymce.ini).
 * Every language for which a file exists here becomes a backend language (languages.php).
 *
 *     [frontend]                          like lang/         (backend AND frontend)
 *     core.yes = "…"
 *     [backend]                           like backend/lang/ (backend only)
 *     ext.datepicker.option_mode = "…"
 *
 * Key: {prefix}.{key} - split at the LAST dot (snippet keys do not contain one);
 * without a dot the prefix core applies. Entries before the first section
 * count as [backend]. Prefixes of components that do not exist are skipped. The
 * packages overwrite the files of the components, the snippet sets of the instances
 * overwrite the packages.
 *
 * Every language comes with en_US_{code}.ini containing only the name of the language in its
 * own language - by its name it counts as en_US and thus lands in the en fallback
 * of all languages:  backend_language_sv_SE = "Svenska"
 * The keys backend_language_* therefore only appear in en_US.ini in the core files
 * and are not reported as missing in the other languages.
 *
 * Templates for translation are written by every complete build to var/translation/blueprint:
 *     en_US_core.ini   all en_US snippets of the core components ({core_dir}), already
 *                      with [frontend]/[backend] and prefix - without backend_language_*
 *     en_US_en_US.ini  template for en_US_{code}.ini
 *
 *
 * ONE FILE PER LANGUAGE, PREFIX AND SCOPE
 * --------------------
 *     var/cache/lang/{lang}/{prefix}.php           backend and frontend
 *     var/cache/lang/{lang}/{prefix}.backend.php   backend only
 *
 * Each file returns an array (return [...]). Cms::snippet() includes it on the
 * first access to a snippet of the prefix - first the frontend file, and only
 * if the key is missing there, the backend file as well. A frontend request thus loads
 * only the few files whose texts it really needs (error messages from
 * Dataset, Cms::select(), RecordUser, processors).
 *
 * build() without an argument rebuilds everything and empties the directory beforehand;
 * build('record') writes only the files of ONE component (all languages) and
 * remove('record') deletes them - for installing/uninstalling a component.
 *
 * THE en FALLBACK IS FROZEN WHEN BUILDING
 * --------------------
 * Every file is complete: for every language all keys that exist in
 * Cms::FALLBACK_LANG are created; if the translation is missing (key absent or empty),
 * the English text is inserted. At runtime there is no language fallback any more,
 * only the fall back to the key itself. Which languages exist is decided by
 * Core/lang and var/translation/translated (Cms::backendLanguages()); a component
 * that only brings en_US.ini gets a file with the English texts for every other
 * language. Missing translations appear in the result of build().
 *
 * ICU SNIPPETS ARE COMPILED TO CLOSURES
 * --------------------
 * If a snippet contains a select or plural construct, it is not stored as text,
 * but as an anonymous function that runs a match() over the passed arguments at runtime.
 * Cms::snippet() recognizes this ($text instanceof \Closure) and calls it. The evaluation
 * therefore costs almost nothing at runtime - the price is that a change to the snippet
 * only takes effect after a cache rebuild.
 *
 * The compilation consists of four methods:
 *     compileMixedTextToPHP()   splits text into static parts and ICU blocks
 *     compileICUBlockToPHP()    turns ONE block into a match()
 *     extractICUChoices()       splits a block into its branches
 *     formatStaticText()        handles the '#' inside a plural branch
 * compileMixedTextToPHP() and compileICUBlockToPHP() call each other -
 * hence the arbitrary nesting possible.
 *
 * @see Camindo\Core\Cms::snippet()
 * @see Camindo\Core\Modules\Tools\ToolHtmlTemplates  the same for the HTML templates
 * @see developer docs, chapter 05 (Backend development)
 *
 * ---
 *
 * Implementation of selected core parts of the ICU Message Format.

1. SUPPORTED FUNCTIONS
--------------------------
* Type: `select`
- Allows exact matching of passed string values.
- A fallback keyword is required: `other`.

* Type: `plural`
- Allows exact matching of values via the prefix `=` (e.g. `=0`, `=1`).
- Placeholder replacement: the character `#` in the output text is automatically replaced by the passed numeric value (cast to string).
- A fallback keyword is required: `other`.

* Nesting
- Recursive nesting is supported natively. It is easily possible, e.g., to define a `plural` block inside a `select` branch.

2. KNOWN LIMITATIONS (NOT SUPPORTED)
-----------------------------------------------
To avoid wrong expectations, here are the ICU features this library does *not* cover:

* Pure variable interpolation: simple placeholders without a type definition (e.g. `Hallo {name}`) are not caught by the regex `^\{[a-zA-Z0-9_]+,\s*(select|plural)\s*,` and are returned as a static string. (These may have to be processed elsewhere in the system).
* Locale-based plural rules: grammatical plural categories such as `zero`, `one`, `two`, `few`, `many` are not evaluated against language rule sets. The parser merely compares the input exactly (without `=`) with the key or falls back to `other`.
* Data formatting: ICU types for formatting such as `number`, `date`, `time` or `currency` are not implemented.
* Plural offsets: the extended plural syntax for shifting values (e.g. `offset:1`) is not supported.
* Escaping: masking special characters (e.g. `'#'`, when the hash sign should be output as normal text and not as a placeholder) is not respected by the compiler; every `#` in a plural block is replaced.

3. SYNTAX REQUIREMENTS
-------------------------
- The pattern must begin exactly with: `{variableName, select, ` or `{variableName, plural, ` (whitespace around the keywords is allowed, but the first opening character must be a `{`).

// Example of the ICU Message Format (select|plural)
'debug' => '{typ, select,
m {
    {count, plural,
    =0 {kein Mann}
    =1 {ein Mann}
    other {# Männer}
    }
}
f {
    {count, plural,
    =0 {keine Frau}
    =1 {eine Frau}
    other {# Frauen}
    }
}
other {
    {count, plural,
    =0 {keine Personen}
    =1 {eine Person}
    other {# Personen}
    }
}
}',
Output via
Camindo\Core\Cms ->snippet('debug',['typ' => 'f', 'count' => '3']);

*/
namespace Camindo\Core;

class SnippetCache {

    // Prefix (= name of the cache file) of the snippets without component: Core/lang, Core/backend/lang
    public const CORE_PREFIX = Cms::CORE_PREFIX;

    // Snippet sets of the instances under var/custom/snippets/{instance_id}/lang -
    // prefix custom.{instance_id} (Snippets module); Cms::snippet() resolves the short form
    // custom.{key} to the current instance
    public const CUSTOM_PREFIX = 'custom';
    public const CUSTOM_SET_PATTERN = '/^[1-9][0-9]{0,9}$/';

    // List of the backend languages (Core/lang and translation packages), written when building - Cms::backendLanguages()
    // reads it instead of searching the directory on every request
    public const LANGUAGES_FILE = 'languages.php';

    // Translation packages and templates, relative to paths.var_dir (see class comment)
    public const TRANSLATED_DIR = '/translation/translated';
    public const BLUEPRINT_DIR = '/translation/blueprint';
    // Language of a translation package from the file name: {code}.ini, {code}_….ini
    private const TRANSLATED_PATTERN = '/^([a-z]{2,3}_[A-Z]{2})(?:_[^\/]*)?\.ini$/';
    // Names of the backend languages (Backend::backendLanguageOptions()): only in en_US,
    // for the other languages the fallback applies - not reported as missing
    public const LANGUAGE_NAME_PREFIX = 'backend_language_';

    public function __construct(
        private Config $config,
        private System $system,
    )
    {
    }

    /**
     * Build the cache - everything or only one component.
     *
     * @param ?string $prefix  null: everything (directory is emptied beforehand);
     *                         otherwise the prefix of ONE component ('record',
     *                         'proc.magick', CORE_PREFIX) - only its files are
     *                         replaced
     * @return array  'dirs'       searched directories (prefix => [path, ...])
     *                'files'      number of files written
     *                'duplicates' keys in lang/ AND backend/lang/ ("de: record.x")
     *                'missing'    missing translations: language => ['record.x', ...]
     *                'errors'     faulty lines of the .ini files ("file:line: ...")
     *                'blueprints' translation templates written (paths)
     * @throws \Exception  if the cache directory cannot be created
     */
    public function build(?string $prefix = null): array
    {
        $varDir = $this->config->get('paths.var_dir');
        $cachePath = '/cache/lang';
        $cacheDir = $varDir . $cachePath;
        if (!$this->system->createDir($varDir, $cachePath)) {
            throw new \Exception("Error: Creation of directory \"{$cacheDir}\" failed.");
        }

        // Collection: $array[{language}][{prefix}][{scope}][{key}] = text
        // scope: '' = backend and frontend (lang/), 'backend' = backend only (backend/lang/)
        $array = [];
        $duplicates = [];
        $dirs = [];
        $errors = [];
        $add = function (string $lang, string $p, string $scope, array $snippets) use (&$array, &$duplicates): void {
            $other = $scope === '' ? 'backend' : '';
            foreach ($snippets as $key => $val) {
                if (isset($array[$lang][$p][$other][$key])) {
                    $duplicates[] = "$lang: $p.$key";
                }
                $array[$lang][$p][$scope][$key] = (string)$val;
            }
        };

        // Template en_US_core.ini: en_US of the core components, only for the complete build
        $coreDir = $this->config->get('paths.core_dir');
        $blueprint = [];

        $sourceDirs = $this->sourceDirs();
        $customDirs = array_filter($sourceDirs, fn(array $d) => str_starts_with($d[1], self::CUSTOM_PREFIX . '.'));
        $componentDirs = array_diff_key($sourceDirs, $customDirs);

        foreach ($componentDirs as [$dir, $dirPrefix, $scope]) {
            if ($prefix !== null && $dirPrefix !== $prefix) continue;
            $dirs[$dirPrefix][] = $dir;
            foreach ($this->readDir($dir, $errors) as $lang => $snippets) {
                $add($lang, $dirPrefix, $scope, $snippets);
                if ($prefix === null && $lang === Cms::FALLBACK_LANG && str_starts_with($dir, $coreDir . '/')) {
                    foreach ($snippets as $key => $val) {
                        $blueprint[$scope === '' ? 'frontend' : 'backend'][$dirPrefix][$key] = (string)$val;
                    }
                }
            }
        }

        // Translation packages (additional languages) - only prefixes of existing components
        $known = array_flip($this->prefixes());
        foreach ($this->translatedFiles() as $file => $lang) {
            $dirs['translation'][] = $file;
            [$sections, $fileErrors] = SnippetIni::readFile($file);
            array_push($errors, ...$fileErrors);
            foreach ($sections as $section => $entries) {
                $scope = match ($section) { 'frontend' => '', '', 'backend' => 'backend', default => null };
                if ($scope === null) {
                    $errors[] = "$file: unknown section [$section] (only [frontend] and [backend])";
                    continue;
                }
                $grouped = [];
                foreach ($entries as $fullKey => $val) {
                    $dot = strrpos($fullKey, '.');
                    $p = $dot === false ? self::CORE_PREFIX : substr($fullKey, 0, $dot);
                    $key = $dot === false ? $fullKey : substr($fullKey, $dot + 1);
                    if ($key === '' || !isset($known[$p]) || ($prefix !== null && $p !== $prefix)) continue;
                    $grouped[$p][$key] = $val;
                }
                foreach ($grouped as $p => $snippets) $add($lang, $p, $scope, $snippets);
            }
        }

        // Snippet sets of the instances last - they overwrite everything
        foreach ($customDirs as [$dir, $dirPrefix, $scope]) {
            if ($prefix !== null && $dirPrefix !== $prefix) continue;
            $dirs[$dirPrefix][] = $dir;
            foreach ($this->readDir($dir, $errors) as $lang => $snippets) {
                $add($lang, $dirPrefix, $scope, $snippets);
            }
        }

        // Remove old files: everything (also prefixes
        // of removed components) or only those of the component
        if ($prefix === null) {
            foreach ((array)glob($cacheDir . '/*') as $entry) {
                if (is_dir($entry)) {
                    foreach ((array)glob($entry . '/*.php') as $file) unlink($file);
                }
                elseif (str_ends_with($entry, '.php')) {
                    unlink($entry);
                }
            }
        }
        else {
            $this->remove($prefix);
        }

        // Freeze the en fallback and write files. Languages: those of the backend
        // (Core/lang and translation packages) plus all that a component
        // additionally brings along.
        $fallback = Cms::FALLBACK_LANG;
        $langs = array_unique(array_merge($this->backendLanguages(), array_keys($array)));
        $prefixes = [];
        foreach ($array as $langSnippets) {
            $prefixes = array_merge($prefixes, array_keys($langSnippets));
        }
        $prefixes = array_unique($prefixes);

        $missing = [];
        $fileCount = 0;
        foreach ($langs as $lang) {
            foreach ($prefixes as $p) {
                // Custom sets (Snippets module) are deliberately often maintained only in some
                // languages - the en fallback applies, it is not reported
                $reportMissing = !str_starts_with($p, self::CUSTOM_PREFIX . '.');
                foreach (['', 'backend'] as $scope) {
                    $base = $array[$fallback][$p][$scope] ?? [];
                    $own  = $array[$lang][$p][$scope] ?? [];
                    if (!$base && !$own) continue;

                    // Keep the order of the language file, missing keys from en
                    $snippets = [];
                    foreach ($own as $key => $val) {
                        if ($val === '' && isset($base[$key])) {
                            if ($reportMissing) $missing[$lang][] = "$p.$key";
                            $val = $base[$key];
                        }
                        $snippets[$key] = $val;
                    }
                    foreach ($base as $key => $val) {
                        if (!isset($snippets[$key])) {
                            if ($lang !== $fallback && $reportMissing
                                && !($p === self::CORE_PREFIX && str_starts_with($key, self::LANGUAGE_NAME_PREFIX))) {
                                $missing[$lang][] = "$p.$key";
                            }
                            $snippets[$key] = $val;
                        }
                    }

                    $file = '/' . $lang . '/' . $p . ($scope === '' ? '' : '.backend') . '.php';
                    $this->system->filePutContents($cacheDir, $file, $this->compileFile($snippets), lock:true);
                    // Cms::snippet() reads the file via include - otherwise OPcache keeps the old state
                    if (function_exists('opcache_invalidate')) {
                        @opcache_invalidate($cacheDir . $file, true);
                    }
                    $fileCount++;
                }
            }
        }

        // Record the backend languages once (after the emptying above, always anew)
        $this->system->filePutContents($cacheDir, '/' . self::LANGUAGES_FILE, $this->compileFile($this->backendLanguages()), lock:true);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($cacheDir . '/' . self::LANGUAGES_FILE, true);
        }

        // Translation templates (only for the complete build, otherwise components would be missing)
        $blueprints = [];
        if ($prefix === null) {
            $this->system->createDir($varDir, self::TRANSLATED_DIR);
            $blueprints = $this->writeBlueprints($blueprint);
        }

        return [
            'dirs'       => $dirs,
            'files'      => $fileCount,
            'duplicates' => array_values(array_unique($duplicates)),
            'missing'    => $missing,
            'errors'     => $errors,
            'blueprints' => $blueprints,
        ];
    }

    /**
     * Remove the cache files of ONE component (all languages) - when uninstalling.
     *
     * @return int  number of deleted files
     */
    public function remove(string $prefix): int
    {
        $cacheDir = $this->config->get('paths.var_dir') . '/cache/lang';
        $count = 0;
        foreach ((array)glob($cacheDir . '/*', GLOB_ONLYDIR) as $langDir) {
            foreach ([$prefix . '.php', $prefix . '.backend.php'] as $name) {
                $file = $langDir . '/' . $name;
                if (is_file($file) && unlink($file)) $count++;
            }
        }
        return $count;
    }

    /**
     * Prefixes of all existing components (from the source directories).
     *
     * @return string[]  e.g. ['core', 'admin', ..., 'proc.magick']
     */
    public function prefixes(): array
    {
        $prefixes = [];
        foreach ($this->sourceDirs() as [$dir, $prefix]) {
            $prefixes[$prefix] = true;
        }
        return array_keys($prefixes);
    }

    /**
     * Source directories in collection order (see class comment).
     *
     * @return array  list of [directory, prefix, scope ('' | 'backend')]
     */
    private function sourceDirs(): array
    {
        $camindoDir = $this->config->get('paths.camindo_dir');
        $coreDir = $this->config->get('paths.core_dir');

        $dirs = [
            [$coreDir . '/lang', self::CORE_PREFIX, ''],
            [$coreDir . '/backend/lang', self::CORE_PREFIX, 'backend'],
        ];
        $componentsDirs = ['' => '/Modules', 'proc.' => '/Processors', 'ext.' => '/Extensions'];
        foreach ([$camindoDir, $coreDir] as $mainDir) {
            foreach ($componentsDirs as $key => $componentsDir) {
                foreach ((array)glob($mainDir . $componentsDir . '/*', GLOB_ONLYDIR) as $subDir) {
                    $prefix = $key . preg_replace('/[^a-z0-9_]/', '', strtolower(basename($subDir)));
                    $dirs[] = [$subDir . '/lang', $prefix, ''];
                    $dirs[] = [$subDir . '/backend/lang', $prefix, 'backend'];
                }
            }
        }
        // Snippets of the instances (Snippets module): one set per subdirectory
        // (= instance_id), prefix custom.{instance_id}. Other directory names
        // are skipped.
        foreach ((array)glob(self::customDir($this->config) . '/*', GLOB_ONLYDIR) as $subDir) {
            $set = basename($subDir);
            if (!preg_match(self::CUSTOM_SET_PATTERN, $set)) continue;
            $dirs[] = [$subDir . '/lang', self::CUSTOM_PREFIX . '.' . $set, ''];
        }
        return $dirs;
    }

    /**
     * Root of the snippet sets of the instances: var/custom/snippets.
     */
    public static function customDir(Config $config): string
    {
        return $config->get('paths.var_dir') . '/custom/snippets';
    }

    /**
     * Backend languages: the codes for which there is Core/lang/{code}.ini, and those of the
     * translation packages - like Cms::backendLanguages(), only without Cms (the builder should
     * manage without Cms).
     */
    private function backendLanguages(): array
    {
        return Cms::scanLanguages($this->config->get('paths.core_dir') . '/lang', self::translatedDir($this->config));
    }

    /**
     * Read the language files of a lang directory: {code}.ini.
     *
     * @param string[] $errors  errors of the .ini files are appended
     * @return array<string, array<string, string>>  language => [key => text]
     */
    private function readDir(string $dir, array &$errors): array
    {
        if (!is_dir($dir)) return [];
        $result = [];
        foreach ((array)glob($dir . '/*.ini') as $file) {
            $lang = self::fileLanguage($file);
            if ($lang === null) continue;
            [$sections, $fileErrors] = SnippetIni::readFile($file);
            array_push($errors, ...$fileErrors);
            foreach (array_keys($sections) as $section) {
                if ($section !== '') $errors[] = "$file: section [$section] ignored (lang/ files have no sections)";
            }
            $result[$lang] = $sections[''] ?? [];
        }
        return $result;
    }

    /**
     * Root of the translation packages: var/translation/translated.
     */
    public static function translatedDir(Config $config): string
    {
        return $config->get('paths.var_dir') . self::TRANSLATED_DIR;
    }

    /**
     * Translation packages with their language, sorted by name.
     *
     * @return array<string, string>  path => language
     */
    private function translatedFiles(): array
    {
        $files = [];
        foreach ((array)glob(self::translatedDir($this->config) . '/*.ini') as $file) {
            $lang = self::translatedLanguage($file);
            if ($lang !== null) $files[$file] = $lang;
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /**
     * Language of a translation package from the file name: sv_SE.ini, sv_SE_core.ini,
     * en_US_sv_SE.ini (-> en_US). Other names: null.
     */
    public static function translatedLanguage(string $file): ?string
    {
        return preg_match(self::TRANSLATED_PATTERN, basename($file), $m) ? $m[1] : null;
    }

    /**
     * Write the templates for translation to var/translation/blueprint.
     *
     * @param array $blueprint  'frontend'|'backend' => [prefix => [key => text]] (en_US, Core)
     * @return string[]  files written
     */
    private function writeBlueprints(array $blueprint): array
    {
        $varDir = $this->config->get('paths.var_dir');
        $fallback = Cms::FALLBACK_LANG;
        $written = [];

        $sections = [];
        $groups = [];
        foreach (['frontend', 'backend'] as $section) {
            foreach ($blueprint[$section] ?? [] as $p => $snippets) {
                $first = true;
                foreach ($snippets as $key => $val) {
                    // Language names come per language from en_US_{code}.ini
                    if ($p === self::CORE_PREFIX && str_starts_with($key, self::LANGUAGE_NAME_PREFIX)) continue;
                    if ($first) {
                        $groups[$section][$p . '.' . $key] = $p;
                        $first = false;
                    }
                    $sections[$section][$p . '.' . $key] = $val;
                }
            }
        }
        $header = "Übersetzungsvorlage: alle $fallback-Snippets der Core-Komponenten.\n"
            . "Automatisch geschrieben von Camindo\\Core\\SnippetCache - Änderungen hier gehen verloren.\n"
            . "\n"
            . "Für eine Zusatzsprache kopieren nach var/translation/translated/{code}_core.ini\n"
            . "(z. B. sv_SE_core.ini) und die Texte übersetzen. Nicht übersetzte Einträge\n"
            . "dürfen fehlen - dafür gilt der $fallback-Text. Dazu gehört\n"
            . "var/translation/translated/{$fallback}_{code}.ini (Vorlage: {$fallback}_{$fallback}.ini).\n"
            . "\n"
            . "[frontend] Backend UND Frontend (lang/), [backend] nur Backend (backend/lang/).\n"
            . "Schlüssel: {präfix}.{schlüssel}. Werte in doppelten Anführungszeichen,\n"
            . "darin \\\" für \" und \\\\ für \\.";
        $file = self::BLUEPRINT_DIR . '/' . $fallback . '_' . self::CORE_PREFIX . '.ini';
        if ($this->system->filePutContents($varDir, $file, SnippetIni::build($sections, $header, $groups), lock:true)) {
            $written[] = $varDir . $file;
        }

        // Vorlage für die Bezeichnung einer Sprache in ihrer eigenen Sprache
        $key = self::LANGUAGE_NAME_PREFIX . $fallback;
        $header = "Vorlage für var/translation/translated/{$fallback}_{code}.ini (z. B. {$fallback}_sv_SE.ini):\n"
            . "die Bezeichnung der Sprache in ihrer eigenen Sprache, z. B.\n"
            . "    " . self::LANGUAGE_NAME_PREFIX . "sv_SE = \"Svenska\"\n"
            . "Über den Namen {$fallback}_… zählt sie zu $fallback und gilt damit als Fallback für alle Sprachen.";
        $content = SnippetIni::build(['backend' => [$key => $blueprint['backend'][self::CORE_PREFIX][$key] ?? 'English']], $header);
        $file = self::BLUEPRINT_DIR . '/' . $fallback . '_' . $fallback . '.ini';
        if ($this->system->filePutContents($varDir, $file, $content, lock:true)) {
            $written[] = $varDir . $file;
        }
        return $written;
    }

    /**
     * Language of a language file from its name: {code}.ini in the format
     * Cms::LANG_PATTERN (de_DE.ini). Anything else: null.
     */
    public static function fileLanguage(string $file): ?string
    {
        if (!str_ends_with($file, '.ini')) return null;
        $name = basename($file, '.ini');
        return preg_match(Cms::LANG_PATTERN, $name) ? $name : null;
    }

    /**
     * Content of a cache file: return [...] with the snippets of a prefix and
     * scope; ICU snippets as closures.
     *
     * @param array $snippets  key => text
     * @return string          PHP source code
     */
    private function compileFile(array $snippets): string
    {
        $phpContent = "<?php\n\n";
        $phpContent .= "// Automatisch generierter Snippet-Cache - siehe Camindo\\Core\\SnippetCache\n";
        $phpContent .= "return [\n";

        foreach ($snippets as $key => $value) {
            // no trim()! Leading and trailing spaces in a snippet
            // may be intended (e.g. as a gap before a unit).

            // Does the snippet contain an ICU construct? Then store it as a closure,
            // otherwise as a plain string. The fast str_contains() check
            // before it spares the regex for the large majority of snippets.
            if (str_contains($value, '{') && preg_match('/\{[a-zA-Z0-9_]+,\s*(select|plural)\s*,/s', $value)) {
                $compiledCode = $this->compileMixedTextToPHP($value);
                $phpContent .= "    " . var_export($key, true) . " => function(array \$args = []) {\n        return $compiledCode;\n    },\n";
            } else {
                $phpContent .= "    " . var_export($key, true) . " => " . var_export($value, true) . ",\n";
            }
        }

        $phpContent .= "];\n";
        return $phpContent;
    }

    /**
     * Splits mixed text into static parts and ICU blocks and builds from them
     * a PHP expression.
     *
     * A snippet is rarely ONLY an ICU block - usually there is text before and after it.
     * The method therefore runs character by character: everything up to the next valid
     * ICU signature goes into the buffer, the block itself is isolated by counting the
     * curly braces (not by regex - that would not cope with nesting) and handed to
     * compileICUBlockToPHP().
     *
     * The parts are output concatenated with '.', i.e. a single
     * PHP expression that the caller can put into a closure.
     *
     * @param string  $text        snippet text
     * @param ?string $pluralVar   name of the count variable if we are inside a
     *                             plural branch - only then is '#' replaced
     * @return string              PHP source code (expression)
     */
    private function compileMixedTextToPHP(string $text, ?string $pluralVar = null): string {
        $length = strlen($text);
        $buffer = '';
        $parts = [];
        $i = 0;

        while ($i < $length) {
            $char = $text[$i];

            if ($char === '{') {
                $rest = substr($text, $i);
                // Prüfen, ob eine gültige ICU-Signatur beginnt
                if (preg_match('/^\{([a-zA-Z0-9_]+),\s*(select|plural)\s*,/s', $rest, $matches)) {
                    
                    // Store the buffer so far as static text
                    if ($buffer !== '') {
                        $parts[] = $this->formatStaticText($buffer, $pluralVar);
                        $buffer = '';
                    }

                    // Extract the entire block enclosed in curly braces
                    $braceLevel = 0;
                    $block = '';
                    for ($j = $i; $j < $length; $j++) {
                        if ($text[$j] === '{') $braceLevel++;
                        elseif ($text[$j] === '}') $braceLevel--;
                        
                        $block .= $text[$j];
                        
                        if ($braceLevel === 0) {
                            break;
                        }
                    }
                    
                    // Compile the isolated block
                    $parts[] = $this->compileICUBlockToPHP($block);
                    
                    // Advance the index, since the block has been processed
                    $i = $j + 1;
                    continue;
                }
            }
            
            $buffer .= $char;
            $i++;
        }

        // Store the remaining text in the buffer
        if ($buffer !== '') {
            $parts[] = $this->formatStaticText($buffer, $pluralVar);
        }

        // If nothing was extracted, return an empty string, otherwise concatenate the parts with '.'
        return empty($parts) ? "''" : implode(" . ", $parts);
    }

    /**
     * Output static text as a PHP literal, with '#' replacement in the plural branch.
     *
     * Inside a plural block '#' stands for the numeric value. Instead of inserting it here
     * (the value is not known at compile time), a str_replace() call
     * is written into the generated code.
     *
     * There is no escaping of '#' - every '#' in the branch is replaced.
     */
    private function formatStaticText(string $text, ?string $pluralVar): string {
        if ($pluralVar !== null && str_contains($text, '#')) {
            return "str_replace('#', (string)(\$args['$pluralVar'] ?? ''), " . var_export($text, true) . ")";
        }
        return var_export($text, true);
    }

    /**
     * Translate a single ICU block into a PHP match().
     *
     * Aus  {count, plural, =0 {nichts} other {# Stück}}  wird
     *     match((string)($args['count'] ?? '')) { '0' => 'nichts', default => str_replace(…) }
     *
     * The branch 'other' becomes 'default'. For plural the leading '=' is removed from the
     * key - so the comparison is exact and not based on the grammatical plural rules
     * of the language (one/few/many are only matched literally).
     *
     * The content of each branch goes back through compileMixedTextToPHP() - this way
     * blocks can be nested arbitrarily. In a plural branch the variable name is passed on,
     * so that '#' can be replaced there.
     *
     * If the block does not match the expected pattern, it is output unchanged as a string -
     * better the raw text than a syntax error in the cache file.
     */
    private function compileICUBlockToPHP(string $icu): string {
        if (!preg_match('/^\{([a-zA-Z0-9_]+),\s*(select|plural)\s*,(.*)\}$/is', $icu, $matches)) {
            return var_export($icu, true);
        }

        $varName = $matches[1];
        $type = $matches[2];
        $body = trim($matches[3]);

        $choices = $this->extractICUChoices($body);

        $php = "match((string)(\$args['$varName'] ?? '')) {\n";
        
        foreach ($choices as $key => $value) {
            $isOther = ($key === 'other');
            if ($isOther) {
                $matchKey = 'default';
            } else {
                $cleanKey = ($type === 'plural') ? ltrim($key, '=') : $key;
                $matchKey = var_export($cleanKey, true);
            }

            // The return value of a branch (choice) can again contain mixed text.
            // Therefore we call compileMixedTextToPHP recursively.
            $passPluralVar = ($type === 'plural') ? $varName : null;
            $compiledValue = $this->compileMixedTextToPHP(trim($value), $passPluralVar);

            $php .= "            $matchKey => $compiledValue,\n";
        }
        
        $php .= "        }";
        return $php;
    }

    /**
     * Split the branches of an ICU block into key => content.
     *
     * Counts the curly braces: text read at level 0 is the key ('=0', 'other', 'm'),
     * what stands between the opening and the matching closing brace is the content. Nested braces end up
     * unchanged in the content and are only evaluated in the recursive call.
     *
     * Therefore no regex: the nesting would not be handled that way.
     */
    private function extractICUChoices(string $body): array {
        $choices = [];
        $length = strlen($body);
        $buffer = '';
        $currentKey = '';
        $braceLevel = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($char === '{') {
                if ($braceLevel === 0) {
                    $currentKey = trim($buffer);
                    $buffer = '';
                } else {
                    $buffer .= $char;
                }
                $braceLevel++;
            } elseif ($char === '}') {
                $braceLevel--;
                if ($braceLevel === 0) {
                    $choices[$currentKey] = trim($buffer);
                    $buffer = '';
                    $currentKey = '';
                } else {
                    $buffer .= $char;
                }
            } else {
                $buffer .= $char;
            }
        }
        return $choices;
    }
}
