<?php
/**
 * Snippet sets of the instances: var/custom/snippets/{instance_id}/lang/{code}.ini.
 *
 * Each instance has exactly one set (subdirectory = instance_id), one file per backend language
 * (Cms::backendLanguages()) with key = "text" - the same format as the
 * lang/ files of the components (SnippetIni). SnippetCache collects the sets
 * as prefix custom.{instance_id}. In templates the snippets are called
 * `custom.{key}` (Cms::snippet() adds the current instance) or explicitly
 * `custom.{instance_id}.{key}`; in label fields of the backend the explicit
 * form with '#' in front is used (extension Snippets).
 *
 * ACCESS
 * --------------------
 * Whoever has access to the instance may see and change a set
 * (Access::instance()). instances() returns only these; SnippetsBackend and the
 * search endpoint of the extension check each instance with it. The set is created on the first
 * save; if the instance is deleted, Instance::hookOnDeleted() removes it
 * (delete()).
 *
 * NO DATABASE, NO DATASET
 * --------------------
 * The files are the only storage. This class reads them (SnippetIni) and
 * writes them again; the backend shows a set as a
 * table key × language. After each write, the cache of the prefix is rebuilt
 * (SnippetCache::build('custom.{instance_id}')).
 *
 * NO INJECTION
 * --------------------
 * Directory names are instance ids (SnippetCache::CUSTOM_SET_PATTERN), keys
 * must match KEY_PATTERN (lowercase letters, digits, underscore - no dot, because
 * Cms::snippet() splits at the last dot). The texts end up in the file exclusively via
 * SnippetIni::quote() - whatever an administrator enters, it remains a
 * value in quotation marks; nothing is executed.
 *
 * @see Camindo\Core\SnippetCache                       collecting, prefix custom.{instance_id}
 * @see Camindo\Core\Modules\Snippets\SnippetsBackend   UI
 * @see Camindo\Core\Modules\Instance\Instance          deletes the set with the instance
 */
namespace Camindo\Core\Modules\Snippets;

use Camindo\Core\Access;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\InstanceCache;
use Camindo\Core\SnippetCache;
use Camindo\Core\SnippetIni;
use Camindo\Core\System;

class Snippets {

    // Key of a snippet
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    // Reference to a snippet of a set in label fields: #custom.{instance_id}.{key}
    // (match 1: instance_id, 2: key) - export/import of the areas
    public const REFERENCE_PATTERN = '/#custom\.([1-9][0-9]{0,9})\.([a-z][a-z0-9_]{0,63})(?![a-z0-9_])/';

    // Reference in template source: custom.{key} (short form, set of the page's instance)
    // or custom.{instance_id}.{key} - (CMS:SNIPPET …), $this->cms->snippet('…')
    // (match 1: instance_id or empty, 2: key or its fixed start)
    public const TEMPLATE_PATTERN = '/(?<![A-Za-z0-9_.])#?custom\.(?:([1-9][0-9]{0,9})\.)?([a-z][a-z0-9_]{0,63})/';

    public function __construct(
        private Config $config,
        private System $system,
        private Cms $cms,
        private SnippetCache $snippetCache,
        private InstanceCache $instanceCache,
        private Access $access,
    )
    {}

    // ====================
    // Sets = instances

    /**
     * Root directory of the sets (var/custom/snippets).
     */
    public function dir(): string
    {
        return SnippetCache::customDir($this->config);
    }

    private function setDir(int $instanceId): string
    {
        return $this->dir() . '/' . $instanceId;
    }

    /**
     * May the logged-in administrator see and change the set of this instance?
     * The instance must exist (instance cache) and the group must be allowed to use it.
     */
    public function allowed(int $instanceId): bool
    {
        return $instanceId > 0
            && isset($this->instanceCache->instanceLabels()[$instanceId])
            && $this->access->instance($instanceId);
    }

    /**
     * Instances whose set the administrator may see, with key figures - in the order
     * of the instance cache.
     *
     * @return array  instance_id => ['label' => instance label,
     *                                'keys' => number of keys (all languages),
     *                                'languages' => ['de' => number of texts, ...]]
     */
    public function instances(): array
    {
        $instances = [];
        foreach ($this->instanceCache->instanceLabels() as $instanceId => $label) {
            if (!$this->access->instance($instanceId)) continue;
            $data = $this->load($instanceId);
            $languages = [];
            foreach ($this->languages() as $lang) {
                $languages[$lang] = count(array_filter($data, fn(array $texts) => ($texts[$lang] ?? '') !== ''));
            }
            $instances[$instanceId] = ['label' => $this->cms->textOrSnippet($label), 'keys' => count($data), 'languages' => $languages];
        }
        return $instances;
    }

    /**
     * Keys of a set that a template source refers to (export/import of
     * template areas). A match that does not exist in the set counts as the fixed
     * start of a composed key ("custom.status_{$cms['status']}",
     * 'custom.motif_' . $m) - then all keys with this start count.
     *
     * @param string $code        template source
     * @param array  $set         set of the instance (load()): key => [lang => text]
     * @param int    $instanceId  instance of the set - references with another id do not count
     * @return string[]           keys from $set
     */
    public static function templateRefs(string $code, array $set, int $instanceId): array
    {
        if (!str_contains($code, 'custom.') || !$set) return [];
        if (!preg_match_all(self::TEMPLATE_PATTERN, $code, $matches, PREG_SET_ORDER)) return [];
        $keys = [];
        foreach ($matches as $m) {
            if ($m[1] !== '' && (int)$m[1] !== $instanceId) continue;
            if (isset($set[$m[2]])) {
                $keys[$m[2]] = true;
                continue;
            }
            foreach (array_keys($set) as $key) {
                if (str_starts_with((string)$key, $m[2])) $keys[(string)$key] = true;
            }
        }
        return array_keys($keys);
    }

    /**
     * Prefixes of the allowed instances - for the search of the extension.
     *
     * @return string[]  e.g. ['custom.1', 'custom.31']
     */
    public function allowedPrefixes(): array
    {
        $prefixes = [];
        foreach (array_keys($this->instanceCache->instanceLabels()) as $instanceId) {
            if ($this->access->instance($instanceId)) $prefixes[] = $this->prefix($instanceId);
        }
        return $prefixes;
    }

    public function validKey(string $key): bool
    {
        return (bool)preg_match(self::KEY_PATTERN, $key);
    }

    // ====================
    // Earlier PHP format

    /**
     * Convert sets in the earlier PHP format (lang/{code}.php, even older lang/de.php) into
     * .ini files - part of Tools > Developer > Snippets,
     * `camindo.php refresh snippets` and `upgrade`. Since the switch to .ini the
     * cache only reads lang/{code}.ini; without conversion the snippets are missing.
     *
     * The PHP files are NOT executed, but read as source text
     * (parseLegacy() - only `return [ 'key' => 'text', ... ];` made of
     * string literals, as compileFile() used to write them with var_export()).
     * If there is already an .ini file for a language (saved in the module after
     * the old snippets had disappeared), the two are merged; for the same
     * key the .ini file applies, a {code}.php before the two-letter de.php.
     * The PHP file is then renamed to {name}.php.bak and remains as a backup.
     * The caller then rebuilds the cache (SnippetCache::build()).
     *
     * @return array  'converted' => ["{set}/lang/de_DE.php -> de_DE.ini (12 snippets)", ...],
     *                'errors'    => ["{pfad}: ...", ...] (file stays unchanged)
     */
    public function convertLegacy(): array
    {
        $result = ['converted' => [], 'errors' => []];
        $langs = $this->languages();
        foreach ((array)glob($this->dir() . '/*', GLOB_ONLYDIR) as $setDir) {
            $set = basename((string)$setDir);
            if (!preg_match(SnippetCache::CUSTOM_SET_PATTERN, $set)) continue;
            // language => [files], short names (de.php) first - the {code}.php wins
            $byLang = [];
            $legacy = (array)glob($setDir . '/lang/*.php');
            usort($legacy, fn($a, $b) => strlen(basename((string)$a)) <=> strlen(basename((string)$b)) ?: strcmp((string)$a, (string)$b));
            foreach ($legacy as $file) {
                $name = basename((string)$file, '.php');
                $lang = preg_match(Cms::LANG_PATTERN, $name) ? $name
                    : (preg_match('/^[a-z]{2,3}$/', $name) ? Cms::matchLanguage($name, $langs) : null);
                if ($lang === null) {
                    $result['errors'][] = "$file: unknown language";
                    continue;
                }
                $byLang[$lang][] = (string)$file;
            }
            foreach ($byLang as $lang => $files) {
                $snippets = [];
                $sources = [];
                foreach ($files as $file) {
                    $parsed = self::parseLegacy((string)file_get_contents($file));
                    if ($parsed === null) {
                        $result['errors'][] = "$file: not a snippet file (return [...] of strings expected)";
                        continue;
                    }
                    foreach ($parsed as $key => $text) {
                        if ($this->validKey((string)$key) && $text !== '') $snippets[(string)$key] = $text;
                    }
                    $sources[] = $file;
                }
                if (!$sources) continue;
                $iniFile = $setDir . '/lang/' . $lang . '.ini';
                if (is_file($iniFile)) {
                    [$sections] = SnippetIni::readFile($iniFile);
                    foreach ($sections[''] ?? [] as $key => $text) $snippets[(string)$key] = (string)$text;
                }
                if (!$this->system->filePutContents((string)$setDir, 'lang/' . $lang . '.ini',
                        $this->compileFile((int)$set, $lang, $snippets), lock:true)) {
                    $result['errors'][] = "$iniFile: could not be written";
                    continue;
                }
                foreach ($sources as $file) {
                    $backup = $file . '.bak';
                    if (file_exists($backup)) $backup = $file . '.' . date('YmdHis') . '.bak';
                    if (!@rename($file, $backup)) $result['errors'][] = "$file: converted, but could not be renamed to $backup";
                    $result['converted'][] = "$set/lang/" . basename($file) . " -> $lang.ini (" . count($snippets) . ' snippets)';
                }
            }
        }
        return $result;
    }

    /**
     * Read the source of a language file in the earlier PHP format without executing it:
     * `<?php // … return [ 'a' => 'Text', … ];` (also array( … )), keys and
     * texts as string literals, texts also concatenated ('a' . "\0" . 'b', as
     * var_export() writes null bytes).
     *
     * @return array|null  key => text; null if the file is structured differently
     */
    public static function parseLegacy(string $code): ?array
    {
        $tokens = [];
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            $tokens[] = $token;
        }
        $pos = 0;
        $is = function ($expected) use (&$pos, $tokens): bool {
            $token = $tokens[$pos] ?? null;
            return $token !== null && (is_array($token) ? $token[0] === $expected : $token === $expected);
        };
        // A literal or a concatenation of literals
        $string = function () use (&$pos, $tokens, $is): ?string {
            $value = '';
            do {
                $token = $tokens[$pos] ?? null;
                if (!is_array($token)) return null;
                if ($token[0] === T_LNUMBER) {
                    $value .= $token[1];
                }
                elseif ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $inner = substr($token[1], 1, -1);
                    $value .= $token[1][0] === "'" ? strtr($inner, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($inner);
                }
                else {
                    return null;
                }
                $pos++;
                if (!$is('.')) return $value;
                $pos++;
            } while (true);
        };

        if (!$is(T_RETURN)) return null;
        $pos++;
        if ($is('[')) {
            $close = ']';
        }
        elseif ($is(T_ARRAY) && ($tokens[$pos + 1] ?? null) === '(') {
            $close = ')';
            $pos++;
        }
        else {
            return null;
        }
        $pos++;
        $snippets = [];
        while (!$is($close)) {
            $key = $string();
            if ($key === null || !$is(T_DOUBLE_ARROW)) return null;
            $pos++;
            $text = $string();
            if ($text === null) return null;
            $snippets[$key] = $text;
            if ($is(',')) {
                $pos++;
                continue;
            }
            if (!$is($close)) return null;
        }
        return $snippets;
    }

    /**
     * Prefix of a set, as Cms::snippet() expects it: custom.{instance_id}
     */
    public function prefix(int $instanceId): string
    {
        return SnippetCache::CUSTOM_PREFIX . '.' . $instanceId;
    }

    /**
     * Delete the set of an instance: language files, directory, cache files - when
     * the instance is deleted. No set: nothing to do.
     */
    public function delete(int $instanceId): bool
    {
        if ($instanceId < 1) return false;
        $dir = $this->setDir($instanceId);
        if (!is_dir($dir)) return true;
        foreach ((array)glob($dir . '/lang/*.ini') as $file) unlink($file);
        @rmdir($dir . '/lang');
        foreach ((array)glob($dir . '/{,.}*', GLOB_BRACE) as $entry) {
            if (is_file($entry)) unlink($entry);   // e.g. .DS_Store
        }
        $this->snippetCache->remove($this->prefix($instanceId));
        return @rmdir($dir);
    }

    // ====================
    // Contents

    /**
     * Backend languages = columns of the table and file names (Cms::backendLanguages()).
     * FALLBACK_LANG comes first, because the cache fetches missing translations from there.
     *
     * @return string[]  e.g. ['en', 'de']
     */
    public function languages(): array
    {
        $langs = $this->cms->backendLanguages();
        usort($langs, fn(string $a, string $b) => ($a === Cms::FALLBACK_LANG ? 0 : 1) <=> ($b === Cms::FALLBACK_LANG ? 0 : 1) ?: strcmp($a, $b));
        return $langs;
    }

    /**
     * All snippets of a set: key => [lang => text] in the order of the
     * files (keys that occur in only one language are appended at the end).
     * Missing texts are ''. No set: empty.
     */
    public function load(int $instanceId): array
    {
        $data = [];
        if ($instanceId < 1) return $data;
        $langs = $this->languages();
        foreach ($langs as $lang) {
            $file = $this->setDir($instanceId) . '/lang/' . $lang . '.ini';
            if (!is_file($file)) continue;
            [$sections] = SnippetIni::readFile($file);
            foreach ($sections[''] ?? [] as $key => $text) {
                $key = (string)$key;
                if (!$this->validKey($key)) continue;
                $data[$key][$lang] = $text;
            }
        }
        foreach ($data as $key => $texts) {
            foreach ($langs as $lang) $data[$key][$lang] ??= '';
        }
        return $data;
    }

    /**
     * Check and write snippets of a set - replaces the entire content; the
     * directory is created the first time.
     *
     * @param int   $instanceId  allowed instance (allowed())
     * @param array $rows        list of ['key' => '...', 'text' => [lang => text]] in
     *                           the desired order; rows with an empty key
     *                           are skipped (deleted)
     * @return bool  false with errors in Cms::errors ('row.{i}' for the row)
     */
    public function save(int $instanceId, array $rows): bool
    {
        if (!$this->allowed($instanceId)) {
            $this->cms->error($this->cms->snippet('snippets.error_instance_not_found'));
            return false;
        }
        $langs = $this->languages();
        $files = array_fill_keys($langs, []);
        $seen = [];
        $ok = true;
        foreach (array_values($rows) as $i => $row) {
            $key = trim((string)($row['key'] ?? ''));
            if ($key === '') continue;
            if (!$this->validKey($key)) {
                $this->cms->error(str_replace('{key}', $key, $this->cms->snippet('snippets.error_key_invalid')), "row.$i", $this->rowLabel($i));
                $ok = false;
                continue;
            }
            if (isset($seen[$key])) {
                $this->cms->error(str_replace('{key}', $key, $this->cms->snippet('snippets.error_key_duplicate')), "row.$i", $this->rowLabel($i));
                $ok = false;
                continue;
            }
            $seen[$key] = true;
            $texts = (array)($row['text'] ?? []);
            foreach ($langs as $lang) {
                // No trim(): leading/trailing spaces can be intended
                // HTML is allowed, script and event handlers are not (Cms::_sanitize_html())
                $text = $this->cms->_sanitize_html((string)($texts[$lang] ?? ''));
                // Do not write empty texts - the cache then uses the en fallback
                if ($text === '') continue;
                $files[$lang][$key] = $text;
            }
        }
        if (!$ok) return false;

        // from var/, so that var/custom/snippets itself is created too
        if (!$this->system->createDir($this->config->get('paths.var_dir'), 'custom/snippets/' . $instanceId . '/lang')) {
            $this->cms->error($this->cms->snippet('snippets.error_set_dir'));
            return false;
        }
        foreach ($langs as $lang) {
            $file = $this->compileFile($instanceId, $lang, $files[$lang]);
            if (!$this->system->filePutContents($this->setDir($instanceId), 'lang/' . $lang . '.ini', $file, lock:true)) {
                $this->cms->error($this->cms->snippet('snippets.error_set_dir'));
                return false;
            }
        }
        $this->snippetCache->build($this->prefix($instanceId));
        return true;
    }

    /**
     * Take over snippets into a set without changing existing ones (import of
     * areas, RecordImport): missing keys are appended at the end; for existing ones
     * only texts in languages that are still empty there. Keys without a single
     * text are skipped.
     *
     * @param int   $instanceId  allowed instance (allowed())
     * @param array $snippets    key => [lang => text]
     * @return array|null  newly created keys; null = error (message in Cms::errors)
     */
    public function merge(int $instanceId, array $snippets): ?array
    {
        $current = $this->load($instanceId);
        $added = [];
        $changed = false;
        foreach ($snippets as $key => $texts) {
            $key = (string)$key;
            if (!$this->validKey($key) || !is_array($texts)) continue;
            // Exports of older versions use two-letter codes (de, en)
            $normalized = [];
            foreach ($texts as $code => $text) {
                $lang = Cms::matchLanguage((string)$code, $this->languages());
                if ($lang !== null && (string)($normalized[$lang] ?? '') === '') $normalized[$lang] = $text;
            }
            $texts = $normalized;
            foreach ($this->languages() as $lang) {
                $text = (string)($texts[$lang] ?? '');
                if ($text === '' || (string)($current[$key][$lang] ?? '') !== '') continue;
                if (!isset($current[$key])) $added[] = $key;
                $current[$key][$lang] = $text;
                $changed = true;
            }
        }
        if (!$changed) return $added;
        return $this->save($instanceId, $this->rows($current)) ? $added : null;
    }

    /**
     * Create or change a snippet (input mask per snippet). The other
     * snippets of the set remain unchanged and in their order; a new
     * key goes at the end.
     *
     * @param int    $instanceId  allowed instance (allowed())
     * @param string $key         key (KEY_PATTERN)
     * @param array  $texts       [lang => text]; missing languages count as ''
     * @param bool   $isNew       true: the key may not yet occur,
     *                            false: it must occur
     * @return bool  false with error in Cms::errors (field 'key' or general)
     */
    public function saveSnippet(int $instanceId, string $key, array $texts, bool $isNew): bool
    {
        if (!$this->allowed($instanceId)) {
            $this->cms->error($this->cms->snippet('snippets.error_instance_not_found'));
            return false;
        }
        if (!$this->validKey($key)) {
            $this->cms->error(str_replace('{key}', $key, $this->cms->snippet('snippets.error_key_invalid')), 'key', $this->cms->snippet('snippets.column_key'));
            return false;
        }
        $current = $this->load($instanceId);
        if ($isNew && isset($current[$key])) {
            $this->cms->error(str_replace('{key}', $key, $this->cms->snippet('snippets.error_key_exists')), 'key', $this->cms->snippet('snippets.column_key'));
            return false;
        }
        if (!$isNew && !isset($current[$key])) {
            $this->cms->error(str_replace('{key}', $key, $this->cms->snippet('snippets.error_key_not_found')));
            return false;
        }
        $current[$key] = [];
        foreach ($this->languages() as $lang) {
            $current[$key][$lang] = (string)($texts[$lang] ?? '');
        }
        return $this->save($instanceId, $this->rows($current));
    }

    /**
     * Remove a snippet from the set (in all languages).
     *
     * @return bool  false with error in Cms::errors
     */
    public function deleteSnippet(int $instanceId, string $key): bool
    {
        if (!$this->allowed($instanceId)) {
            $this->cms->error($this->cms->snippet('snippets.error_instance_not_found'));
            return false;
        }
        $current = $this->load($instanceId);
        if (!isset($current[$key])) {
            $this->cms->error(str_replace('{key}', $key, $this->cms->snippet('snippets.error_key_not_found')));
            return false;
        }
        unset($current[$key]);
        return $this->save($instanceId, $this->rows($current));
    }

    /**
     * Convert the load() result into the row form of save().
     */
    private function rows(array $snippets): array
    {
        $rows = [];
        foreach ($snippets as $key => $texts) $rows[] = ['key' => (string)$key, 'text' => $texts];
        return $rows;
    }

    /**
     * Label of a row in error messages ("row 3").
     */
    private function rowLabel(int $i): string
    {
        return str_replace('{n}', (string)($i + 1), $this->cms->snippet('snippets.label_row'));
    }

    /**
     * Content of a language file (SnippetIni, without sections).
     */
    private function compileFile(int $instanceId, string $lang, array $snippets): string
    {
        $header = "Snippets der Instanz " . $instanceId . " (" . $lang . ") - geschrieben vom Modul Snippets,\n"
            . "Camindo\\Core\\Modules\\Snippets\\Snippets. Nicht von Hand ändern.";
        return SnippetIni::build($snippets ? ['' => $snippets] : [], $header);
    }
}
