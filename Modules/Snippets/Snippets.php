<?php
/**
 * Snippet-Sets der Instanzen: var/custom/snippets/{instance_id}/lang/{code}.ini.
 *
 * Jede Instanz hat genau ein Set (Unterverzeichnis = instance_id), je Backend-Sprache
 * (Cms::backendLanguages()) eine Datei schluessel = "Text" - dasselbe Format wie die
 * lang/-Dateien der Komponenten (SnippetIni). SnippetCache sammelt die Sets
 * als Präfix custom.{instance_id} ein. In Templates heißen die Snippets
 * `custom.{schlüssel}` (Cms::snippet() ergänzt die aktuelle Instanz) oder ausdrücklich
 * `custom.{instance_id}.{schlüssel}`; in Beschriftungsfeldern des Backends steht die
 * ausdrückliche Form mit '#' davor (Extension Snippets).
 *
 * ZUGRIFF
 * --------------------
 * Ein Set darf sehen und ändern, wer Zugriff auf die Instanz hat
 * (Access::instance()). instances() liefert nur diese; SnippetsBackend und der
 * Suchendpunkt der Extension prüfen jede Instanz damit. Das Set entsteht beim ersten
 * Speichern; wird die Instanz gelöscht, räumt Instance::hookOnDeleted() es ab
 * (delete()).
 *
 * KEINE DATENBANK, KEIN DATASET
 * --------------------
 * Die Dateien sind die einzige Ablage. Diese Klasse liest sie (SnippetIni) und
 * schreibt sie neu; das Backend zeigt ein Set als
 * Tabelle Schlüssel × Sprache. Nach jedem Schreiben wird der Cache des Präfixes neu
 * gebaut (SnippetCache::build('custom.{instance_id}')).
 *
 * KEINE INJECTION
 * --------------------
 * Verzeichnisnamen sind Instanz-Ids (SnippetCache::CUSTOM_SET_PATTERN), Schlüssel
 * müssen KEY_PATTERN genügen (Kleinbuchstaben, Ziffern, Unterstrich - kein Punkt, weil
 * Cms::snippet() am letzten Punkt trennt). Die Texte landen ausschließlich über
 * SnippetIni::quote() in der Datei - was auch immer ein Admin eingibt, es bleibt ein
 * Wert in Anführungszeichen; ausgeführt wird nichts.
 *
 * @see Camindo\Core\SnippetCache                       Einsammeln, Präfix custom.{instance_id}
 * @see Camindo\Core\Modules\Snippets\SnippetsBackend   Oberfläche
 * @see Camindo\Core\Modules\Instance\Instance          löscht das Set mit der Instanz
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

    // Schlüssel eines Snippets
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    // Verweis auf ein Snippet eines Sets in Beschriftungsfeldern: #custom.{instance_id}.{key}
    // (Treffer 1: instance_id, 2: Schlüssel) - Export/Import der Bereiche
    public const REFERENCE_PATTERN = '/#custom\.([1-9][0-9]{0,9})\.([a-z][a-z0-9_]{0,63})(?![a-z0-9_])/';

    // Verweis in Template-Quelltext: custom.{key} (Kurzform, Set der Instanz der Seite)
    // oder custom.{instance_id}.{key} - (CMS:SNIPPET …), $this->cms->snippet('…')
    // (Treffer 1: instance_id oder leer, 2: Schlüssel bzw. dessen fester Anfang)
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
    // Sets = Instanzen

    /**
     * Wurzelverzeichnis der Sets (var/custom/snippets).
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
     * Darf der angemeldete Admin das Set dieser Instanz sehen und ändern?
     * Die Instanz muss existieren (Instanz-Cache) und die Gruppe sie dürfen.
     */
    public function allowed(int $instanceId): bool
    {
        return $instanceId > 0
            && isset($this->instanceCache->instanceLabels()[$instanceId])
            && $this->access->instance($instanceId);
    }

    /**
     * Instanzen, deren Set der Admin sehen darf, mit Kennzahlen - in der Reihenfolge
     * des Instanz-Caches.
     *
     * @return array  instance_id => ['label' => Instanz-Beschriftung,
     *                                'keys' => Anzahl Schlüssel (alle Sprachen),
     *                                'languages' => ['de' => Anzahl Texte, ...]]
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
     * Schlüssel eines Sets, die ein Template-Quelltext anspricht (Export/Import von
     * Template-Bereichen). Ein Treffer, den es im Set nicht gibt, gilt als fester
     * Anfang eines zusammengesetzten Schlüssels ("custom.status_{$cms['status']}",
     * 'custom.motif_' . $m) - dann zählen alle Schlüssel mit diesem Anfang.
     *
     * @param string $code        Template-Quelltext
     * @param array  $set         Set der Instanz (load()): schlüssel => [lang => text]
     * @param int    $instanceId  Instanz des Sets - Verweise mit anderer Id zählen nicht
     * @return string[]           Schlüssel aus $set
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
     * Präfixe der erlaubten Instanzen - für die Suche der Extension.
     *
     * @return string[]  z. B. ['custom.1', 'custom.31']
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
    // Früheres PHP-Format

    /**
     * Sets im früheren PHP-Format (lang/{code}.php, noch ältere lang/de.php) in
     * .ini-Dateien umwandeln - Teil von Werkzeuge > Entwickler > Snippets,
     * `camindo.php refresh snippets` und `upgrade`. Seit dem Umstieg auf .ini liest
     * der Cache nur noch lang/{code}.ini; ohne Umwandlung fehlen die Snippets.
     *
     * Die PHP-Dateien werden NICHT ausgeführt, sondern als Quelltext gelesen
     * (parseLegacy() - nur `return [ 'schlüssel' => 'text', ... ];` aus
     * String-Literalen, so wie compileFile() sie früher mit var_export() schrieb).
     * Gibt es zu einer Sprache schon eine .ini-Datei (im Modul gespeichert, nachdem
     * die alten Snippets verschwunden waren), wird zusammengeführt; bei gleichem
     * Schlüssel gilt die .ini-Datei, eine {code}.php vor der zweistelligen de.php.
     * Die PHP-Datei wird danach in {name}.php.bak umbenannt und bleibt als Sicherung
     * liegen. Den Cache baut der Aufrufer danach neu (SnippetCache::build()).
     *
     * @return array  'converted' => ["{set}/lang/de_DE.php -> de_DE.ini (12 snippets)", ...],
     *                'errors'    => ["{pfad}: ...", ...] (Datei bleibt unverändert)
     */
    public function convertLegacy(): array
    {
        $result = ['converted' => [], 'errors' => []];
        $langs = $this->languages();
        foreach ((array)glob($this->dir() . '/*', GLOB_ONLYDIR) as $setDir) {
            $set = basename((string)$setDir);
            if (!preg_match(SnippetCache::CUSTOM_SET_PATTERN, $set)) continue;
            // Sprache => [Dateien], kurze Namen (de.php) zuerst - die {code}.php gewinnt
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
     * Quelltext einer Sprachdatei im früheren PHP-Format lesen, ohne ihn auszuführen:
     * `<?php // … return [ 'a' => 'Text', … ];` (auch array( … )), Schlüssel und
     * Texte als String-Literale, Texte auch verkettet ('a' . "\0" . 'b', so schreibt
     * var_export() Nullbytes).
     *
     * @return array|null  schlüssel => text; null, wenn die Datei anders aufgebaut ist
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
        // Ein Literal oder eine Verkettung von Literalen
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
     * Präfix eines Sets, wie Cms::snippet() ihn erwartet: custom.{instance_id}
     */
    public function prefix(int $instanceId): string
    {
        return SnippetCache::CUSTOM_PREFIX . '.' . $instanceId;
    }

    /**
     * Set einer Instanz löschen: Sprachdateien, Verzeichnis, Cachedateien - beim
     * Löschen der Instanz. Kein Set: nichts zu tun.
     */
    public function delete(int $instanceId): bool
    {
        if ($instanceId < 1) return false;
        $dir = $this->setDir($instanceId);
        if (!is_dir($dir)) return true;
        foreach ((array)glob($dir . '/lang/*.ini') as $file) unlink($file);
        @rmdir($dir . '/lang');
        foreach ((array)glob($dir . '/{,.}*', GLOB_BRACE) as $entry) {
            if (is_file($entry)) unlink($entry);   // z. B. .DS_Store
        }
        $this->snippetCache->remove($this->prefix($instanceId));
        return @rmdir($dir);
    }

    // ====================
    // Inhalte

    /**
     * Backend-Sprachen = Spalten der Tabelle und Dateinamen (Cms::backendLanguages()).
     * FALLBACK_LANG steht vorn, weil der Cache fehlende Übersetzungen von dort holt.
     *
     * @return string[]  z. B. ['en', 'de']
     */
    public function languages(): array
    {
        $langs = $this->cms->backendLanguages();
        usort($langs, fn(string $a, string $b) => ($a === Cms::FALLBACK_LANG ? 0 : 1) <=> ($b === Cms::FALLBACK_LANG ? 0 : 1) ?: strcmp($a, $b));
        return $langs;
    }

    /**
     * Alle Snippets eines Sets: schlüssel => [lang => text] in der Reihenfolge der
     * Dateien (Schlüssel, die nur in einer Sprache vorkommen, hängen hinten an).
     * Fehlende Texte sind ''. Kein Set: leer.
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
     * Snippets eines Sets prüfen und schreiben - ersetzt den gesamten Inhalt; das
     * Verzeichnis entsteht beim ersten Mal.
     *
     * @param int   $instanceId  erlaubte Instanz (allowed())
     * @param array $rows        Liste von ['key' => '...', 'text' => [lang => text]] in
     *                           der gewünschten Reihenfolge; Zeilen mit leerem Schlüssel
     *                           werden übergangen (gelöscht)
     * @return bool  false mit Fehlern in Cms::errors ('row.{i}' für die Zeile)
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
                // Kein trim(): führende/folgende Leerzeichen können gewollt sein
                // HTML ist erlaubt, Script und Event-Handler nicht (Cms::_sanitize_html())
                $text = $this->cms->_sanitize_html((string)($texts[$lang] ?? ''));
                // Leere Texte nicht schreiben - der Cache zieht dann den en-Fallback
                if ($text === '') continue;
                $files[$lang][$key] = $text;
            }
        }
        if (!$ok) return false;

        // von var/ aus, damit auch var/custom/snippets selbst entsteht
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
     * Snippets in ein Set übernehmen, ohne Vorhandenes zu ändern (Import von
     * Bereichen, RecordImport): fehlende Schlüssel kommen hinten dazu, bei vorhandenen
     * nur Texte in Sprachen, die dort noch leer sind. Schlüssel ohne einen einzigen
     * Text werden übergangen.
     *
     * @param int   $instanceId  erlaubte Instanz (allowed())
     * @param array $snippets    schlüssel => [lang => text]
     * @return array|null  neu angelegte Schlüssel; null = Fehler (Meldung in Cms::errors)
     */
    public function merge(int $instanceId, array $snippets): ?array
    {
        $current = $this->load($instanceId);
        $added = [];
        $changed = false;
        foreach ($snippets as $key => $texts) {
            $key = (string)$key;
            if (!$this->validKey($key) || !is_array($texts)) continue;
            // Exporte älterer Versionen führen zweistellige Kürzel (de, en)
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
     * Ein Snippet anlegen oder ändern (Eingabemaske je Snippet). Die übrigen
     * Snippets des Sets bleiben unverändert und in ihrer Reihenfolge; ein neuer
     * Schlüssel kommt ans Ende.
     *
     * @param int    $instanceId  erlaubte Instanz (allowed())
     * @param string $key         Schlüssel (KEY_PATTERN)
     * @param array  $texts       [lang => text]; fehlende Sprachen gelten als ''
     * @param bool   $isNew       true: der Schlüssel darf noch nicht vorkommen,
     *                            false: er muss vorkommen
     * @return bool  false mit Fehler in Cms::errors (Feld 'key' bzw. allgemein)
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
     * Ein Snippet aus dem Set entfernen (in allen Sprachen).
     *
     * @return bool  false mit Fehler in Cms::errors
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
     * load()-Ergebnis in die Zeilenform von save().
     */
    private function rows(array $snippets): array
    {
        $rows = [];
        foreach ($snippets as $key => $texts) $rows[] = ['key' => (string)$key, 'text' => $texts];
        return $rows;
    }

    /**
     * Beschriftung einer Zeile in Fehlermeldungen ("Zeile 3").
     */
    private function rowLabel(int $i): string
    {
        return str_replace('{n}', (string)($i + 1), $this->cms->snippet('snippets.label_row'));
    }

    /**
     * Inhalt einer Sprachdatei (SnippetIni, ohne Abschnitte).
     */
    private function compileFile(int $instanceId, string $lang, array $snippets): string
    {
        $header = "Snippets der Instanz " . $instanceId . " (" . $lang . ") - geschrieben vom Modul Snippets,\n"
            . "Camindo\\Core\\Modules\\Snippets\\Snippets. Nicht von Hand ändern.";
        return SnippetIni::build($snippets ? ['' => $snippets] : [], $header);
    }
}
