<?php
/**
 * Sprach-Snippets einsammeln und als PHP-Cache schreiben - var/cache/lang.
 *
 * Der Builder hinter Tools/tool_snippets.php (ToolSnippets) und Core/cron/cache.php.
 * Er hängt nur an Config und System, damit CLI, Installer und eine spätere
 * Komponentenverwaltung ihn ohne Backend-Kontext rufen können. Muss nach JEDER Änderung
 * an einer lang/-Datei laufen - Cms::snippet() liest ausschließlich aus dem Cache.
 *
 * WAS EINGESAMMELT WIRD
 * --------------------
 *     {core}/lang                        Präfix core,        Backend UND Frontend
 *     {core}/backend/lang                Präfix core,        nur Backend
 *     {dir}/Modules/{Name}/lang          Präfix {name}       Backend UND Frontend
 *     {dir}/Modules/{Name}/backend/lang  Präfix {name}       nur Backend
 *     {dir}/Processors/{Name}/lang       Präfix proc.{name}  Backend UND Frontend
 *     {dir}/Processors/{Name}/backend/lang                   nur Backend
 *     {dir}/Extensions/{Name}/lang       Präfix ext.{name}   Backend UND Frontend (13 §3)
 *     {dir}/Extensions/{Name}/backend/lang                   nur Backend
 *     ... jeweils für {dir} = camindo_dir und core_dir
 *     {var}/translation/translated/*.ini Übersetzungspakete (s. u.), Präfix je Schlüssel
 *     {var}/custom/snippets/{instance_id}/lang   Präfix custom.{instance_id}
 *                                        (Snippets einer Instanz, Modul Snippets;
 *                                         Backend UND Frontend)
 *
 * Später Eingesammeltes überschreibt Früheres bei gleichem Schlüssel und Geltungs-
 * bereich. Da {camindo_dir} vor {core_dir} durchlaufen wird, gewinnen am Ende die
 * Core-Komponenten - eine gleichnamige Zusatzkomponente kann ein Core-Snippet also
 * nicht ersetzen. Ein Schlüssel, der in lang/ UND backend/lang/ derselben Komponente
 * steht, wird gemeldet (zur Laufzeit gewinnt die Frontend-Datei).
 *
 * Die Sprachdateien heißen lang/{code}.ini (Format: SnippetIni, ohne Abschnitte,
 * schluessel = "Text"), {code} im Format Cms::LANG_PATTERN (de_DE.ini). Andere Dateien
 * im lang-Verzeichnis werden übergangen; ausgeführt wird nichts.
 *
 * ZUSATZSPRACHEN: var/translation/translated/*.ini
 * --------------------
 * Eine Datei je Sprache für beliebig viele Komponenten; die Sprache steht im Namen:
 * {code}.ini oder {code}_{beliebig}.ini (sv_SE.ini, sv_SE_core.ini, sv_SE_ext_tinymce.ini).
 * Jede Sprache, für die hier eine Datei liegt, wird Backend-Sprache (languages.php).
 *
 *     [frontend]                          wie lang/          (Backend UND Frontend)
 *     core.yes = "…"
 *     [backend]                           wie backend/lang/  (nur Backend)
 *     ext.datepicker.option_mode = "…"
 *
 * Schlüssel: {präfix}.{schlüssel} - geteilt wird am LETZTEN Punkt (Snippet-Schlüssel
 * enthalten keinen); ohne Punkt gilt der Präfix core. Einträge vor dem ersten Abschnitt
 * zählen wie [backend]. Präfixe nicht vorhandener Komponenten werden übergangen. Die
 * Pakete überschreiben die Dateien der Komponenten, die Snippet-Sets der Instanzen
 * überschreiben die Pakete.
 *
 * Zu jeder Sprache gehört en_US_{code}.ini mit nur der Bezeichnung der Sprache in ihrer
 * eigenen Sprache - über den Namen zählt sie zu en_US und landet damit im en-Fallback
 * aller Sprachen:  backend_language_sv_SE = "Svenska"
 * Die Schlüssel backend_language_* stehen deshalb in den Core-Dateien nur in en_US.ini
 * und werden in den anderen Sprachen nicht als fehlend gemeldet.
 *
 * Vorlagen zum Übersetzen schreibt jeder vollständige Bau nach var/translation/blueprint:
 *     en_US_core.ini   alle en_US-Snippets der Core-Komponenten ({core_dir}), schon
 *                      mit [frontend]/[backend] und Präfix - ohne backend_language_*
 *     en_US_en_US.ini  Vorlage für en_US_{code}.ini
 *
 *
 * EINE DATEI JE SPRACHE, PRÄFIX UND GELTUNGSBEREICH
 * --------------------
 *     var/cache/lang/{lang}/{prefix}.php           Backend und Frontend
 *     var/cache/lang/{lang}/{prefix}.backend.php   nur Backend
 *
 * Jede Datei gibt ein Array zurück (return [...]). Cms::snippet() bindet sie beim
 * ersten Zugriff auf ein Snippet des Präfixes ein - erst die Frontend-Datei, und nur
 * wenn der Schlüssel dort fehlt, auch die Backend-Datei. Ein Frontend-Request lädt so
 * nur die wenigen Dateien, deren Texte er wirklich braucht (Fehlermeldungen von
 * Dataset, Cms::select(), RecordUser, Prozessoren).
 *
 * build() ohne Argument baut alles neu und räumt das Verzeichnis vorher leer;
 * build('record') schreibt nur die Dateien EINER Komponente (alle Sprachen) und
 * remove('record') entfernt sie - für das (De)Installieren einer Komponente.
 *
 * DER en-FALLBACK WIRD BEIM BAUEN EINGEFROREN
 * --------------------
 * Jede Datei ist vollständig: Für jede Sprache werden alle Schlüssel angelegt, die es in
 * Cms::FALLBACK_LANG gibt; fehlt die Übersetzung (Schlüssel nicht vorhanden oder leer),
 * steht der englische Text drin. Zur Laufzeit gibt es keinen Sprach-Fallback mehr, nur
 * noch den Rückfall auf den Schlüssel selbst. Welche Sprachen es gibt, entscheidet
 * Core/lang und var/translation/translated (Cms::backendLanguages()); eine Komponente,
 * die nur en_US.ini mitbringt, bekommt für jede andere Sprache eine Datei mit den
 * englischen Texten. Fehlende Übersetzungen stehen im Ergebnis von build().
 *
 * ICU-SNIPPETS WERDEN ZU CLOSURES KOMPILIERT
 * --------------------
 * Enthält ein Snippet ein select- oder plural-Konstrukt, wird es nicht als Text
 * abgelegt, sondern als anonyme Funktion, die zur Laufzeit ein match() über die
 * übergebenen Argumente ausführt. Cms::snippet() erkennt das ($text instanceof
 * \Closure) und ruft sie auf. Die Auswertung kostet damit zur Laufzeit fast nichts -
 * der Preis ist, dass eine Änderung am Snippet erst nach einem Cache-Neubau wirkt.
 *
 * Die Kompilierung besteht aus vier Methoden:
 *     compileMixedTextToPHP()   zerlegt Text in statische Teile und ICU-Blöcke
 *     compileICUBlockToPHP()    macht aus EINEM Block ein match()
 *     extractICUChoices()       zerlegt einen Block in seine Zweige
 *     formatStaticText()        behandelt das '#' innerhalb eines plural-Zweigs
 * compileMixedTextToPHP() und compileICUBlockToPHP() rufen sich gegenseitig auf -
 * daher die beliebige Verschachtelbarkeit.
 *
 * @see Camindo\Core\Cms::snippet()
 * @see Camindo\Core\Modules\Tools\ToolHtmlTemplates  dasselbe für die HTML-Vorlagen
 * @see docs/developers/03-backend-entwicklung.md
 *
 * ---
 *
 * Implementierung für ausgewählter Kernbestandteile des ICU Message Formats. 

1. UNTERSTÜTZTE FUNKTIONEN
--------------------------
* Typ: `select`
- Ermöglicht das exakte Matching von übergebenen String-Werten.
- Ein Fallback-Schlüsselwort ist erforderlich: `other`.

* Typ: `plural`
- Ermöglicht das exakte Matching von Werten durch das Präfix `=` (z. B. `=0`, `=1`).
- Platzhalter-Ersetzung: Das Zeichen `#` im Ausgabetext wird automatisch durch den übergebenen Zahlenwert (als String gecastet) ersetzt.
- Ein Fallback-Schlüsselwort ist erforderlich: `other`.

* Verschachtelung (Nesting)
- Rekursive Verschachtelungen werden nativ unterstützt. Es ist problemlos möglich, z. B. einen `plural`-Block innerhalb eines `select`-Zweiges zu definieren.

2. BEKANNTE EINSCHRÄNKUNGEN (NICHT UNTERSTÜTZT)
-----------------------------------------------
Um falsche Erwartungen zu vermeiden, hier die ICU-Features, die diese Library *nicht* abdeckt:

* Reine Variablen-Interpolation: Einfache Platzhalter ohne Typ-Definition (z. B. `Hallo {name}`) werden vom Regex `^\{[a-zA-Z0-9_]+,\s*(select|plural)\s*,` nicht erfasst und als statischer String zurückgegeben. (Diese müssen ggf. an anderer Stelle im System verarbeitet werden).
* Lokalisierungsbasierte Plural-Regeln: Grammatikalische Plural-Kategorien wie `zero`, `one`, `two`, `few`, `many` werden nicht anhand von Sprach-Regelwerken evaluiert. Der Parser vergleicht lediglich exakt die Eingabe (ohne `=`) mit dem Key oder fällt auf `other` zurück.
* Datenformatierung: ICU-Typen für Formatierungen wie `number`, `date`, `time` oder `currency` sind nicht implementiert.
* Plural-Offsets: Die erweiterte Plural-Syntax zur Verschiebung von Werten (z. B. `offset:1`) wird nicht unterstützt.
* Escaping: Das Maskieren von Sonderzeichen (z. B. `'#'`, wenn das Raute-Zeichen als normaler Text und nicht als Platzhalter ausgegeben werden soll) wird vom Compiler nicht beachtet; jedes `#` in einem Plural-Block wird ersetzt.

3. SYNTAX-VORAUSSETZUNGEN
-------------------------
- Das Muster muss exakt beginnen mit: `{variablenName, select, ` oder `{variablenName, plural, ` (Whitespace um die Schlüsselwörter ist erlaubt, das erste öffnende Zeichen muss aber ein `{` sein).

// Beispiel für das ICU Message Format (select|plural)
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
Ausgabe mittels 
Camindo\Core\Cms ->snippet('debug',['typ' => 'f', 'count' => '3']);

*/
namespace Camindo\Core;

class SnippetCache {

    // Präfix (= Name der Cachedatei) der Snippets ohne Komponente: Core/lang, Core/backend/lang
    public const CORE_PREFIX = Cms::CORE_PREFIX;

    // Snippet-Sets der Instanzen unter var/custom/snippets/{instance_id}/lang -
    // Präfix custom.{instance_id} (Modul Snippets); Cms::snippet() löst die Kurzform
    // custom.{key} auf die aktuelle Instanz auf
    public const CUSTOM_PREFIX = 'custom';
    public const CUSTOM_SET_PATTERN = '/^[1-9][0-9]{0,9}$/';

    // Liste der Backend-Sprachen (Core/lang und Übersetzungspakete), beim Bauen geschrieben - Cms::backendLanguages()
    // liest sie, statt je Request das Verzeichnis zu durchsuchen
    public const LANGUAGES_FILE = 'languages.php';

    // Übersetzungspakete und Vorlagen, relativ zu paths.var_dir (siehe Klassenkommentar)
    public const TRANSLATED_DIR = '/translation/translated';
    public const BLUEPRINT_DIR = '/translation/blueprint';
    // Sprache eines Übersetzungspakets aus dem Dateinamen: {code}.ini, {code}_….ini
    private const TRANSLATED_PATTERN = '/^([a-z]{2,3}_[A-Z]{2})(?:_[^\/]*)?\.ini$/';
    // Bezeichnungen der Backend-Sprachen (Backend::backendLanguageOptions()): nur in en_US,
    // für die anderen Sprachen gilt der Fallback - wird nicht als fehlend gemeldet
    public const LANGUAGE_NAME_PREFIX = 'backend_language_';

    public function __construct(
        private Config $config,
        private System $system,
    )
    {
    }

    /**
     * Cache bauen - alles oder nur eine Komponente.
     *
     * @param ?string $prefix  null: alles (Verzeichnis wird vorher geleert);
     *                         sonst der Präfix EINER Komponente ('record',
     *                         'proc.magick', CORE_PREFIX) - nur deren Dateien werden
     *                         ersetzt
     * @return array  'dirs'       durchsuchte Verzeichnisse (Präfix => [Pfad, ...])
     *                'files'      Anzahl geschriebener Dateien
     *                'duplicates' Schlüssel in lang/ UND backend/lang/ ("de: record.x")
     *                'missing'    fehlende Übersetzungen: Sprache => ['record.x', ...]
     *                'errors'     fehlerhafte Zeilen der .ini-Dateien ("Datei:Zeile: ...")
     *                'blueprints' geschriebene Übersetzungsvorlagen (Pfade)
     * @throws \Exception  wenn das Cacheverzeichnis nicht angelegt werden kann
     */
    public function build(?string $prefix = null): array
    {
        $varDir = $this->config->get('paths.var_dir');
        $cachePath = '/cache/lang';
        $cacheDir = $varDir . $cachePath;
        if (!$this->system->createDir($varDir, $cachePath)) {
            throw new \Exception("Error: Creation of directory \"{$cacheDir}\" failed.");
        }

        // Sammlung: $array[{sprache}][{präfix}][{scope}][{schlüssel}] = text
        // scope: '' = Backend und Frontend (lang/), 'backend' = nur Backend (backend/lang/)
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

        // Vorlage en_US_core.ini: en_US der Core-Komponenten, nur beim vollständigen Bau
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

        // Übersetzungspakete (Zusatzsprachen) - nur Präfixe vorhandener Komponenten
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

        // Snippet-Sets der Instanzen zuletzt - sie überschreiben alles
        foreach ($customDirs as [$dir, $dirPrefix, $scope]) {
            if ($prefix !== null && $dirPrefix !== $prefix) continue;
            $dirs[$dirPrefix][] = $dir;
            foreach ($this->readDir($dir, $errors) as $lang => $snippets) {
                $add($lang, $dirPrefix, $scope, $snippets);
            }
        }

        // Alte Dateien entfernen: alles (auch Präfixe
        // entfernter Komponenten) bzw. nur die der Komponente
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

        // en-Fallback einfrieren und Dateien schreiben. Sprachen: die des Backends
        // (Core/lang und Übersetzungspakete) plus alle, die eine Komponente
        // zusätzlich mitbringt.
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
                // Custom-Sets (Modul Snippets) sind bewusst oft nur in einigen Sprachen
                // gepflegt - der en-Fallback gilt, gemeldet wird er nicht
                $reportMissing = !str_starts_with($p, self::CUSTOM_PREFIX . '.');
                foreach (['', 'backend'] as $scope) {
                    $base = $array[$fallback][$p][$scope] ?? [];
                    $own  = $array[$lang][$p][$scope] ?? [];
                    if (!$base && !$own) continue;

                    // Reihenfolge der Sprachdatei beibehalten, fehlende Schlüssel aus en
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
                    // Cms::snippet() liest die Datei per include - OPcache sonst mit altem Stand
                    if (function_exists('opcache_invalidate')) {
                        @opcache_invalidate($cacheDir . $file, true);
                    }
                    $fileCount++;
                }
            }
        }

        // Backend-Sprachen einmalig festhalten (nach dem Leeren oben, immer neu)
        $this->system->filePutContents($cacheDir, '/' . self::LANGUAGES_FILE, $this->compileFile($this->backendLanguages()), lock:true);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($cacheDir . '/' . self::LANGUAGES_FILE, true);
        }

        // Übersetzungsvorlagen (nur beim vollständigen Bau, sonst fehlten Komponenten)
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
     * Cachedateien EINER Komponente entfernen (alle Sprachen) - beim Deinstallieren.
     *
     * @return int  Anzahl gelöschter Dateien
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
     * Präfixe aller vorhandenen Komponenten (aus den Quellverzeichnissen).
     *
     * @return string[]  z. B. ['core', 'admin', ..., 'proc.magick']
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
     * Quellverzeichnisse in Sammelreihenfolge (siehe Klassenkommentar).
     *
     * @return array  Liste von [Verzeichnis, Präfix, Scope ('' | 'backend')]
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
        // Snippets der Instanzen (Modul Snippets): ein Set je Unterverzeichnis
        // (= instance_id), Präfix custom.{instance_id}. Andere Verzeichnisnamen
        // werden übergangen.
        foreach ((array)glob(self::customDir($this->config) . '/*', GLOB_ONLYDIR) as $subDir) {
            $set = basename($subDir);
            if (!preg_match(self::CUSTOM_SET_PATTERN, $set)) continue;
            $dirs[] = [$subDir . '/lang', self::CUSTOM_PREFIX . '.' . $set, ''];
        }
        return $dirs;
    }

    /**
     * Wurzel der Snippet-Sets der Instanzen: var/custom/snippets.
     */
    public static function customDir(Config $config): string
    {
        return $config->get('paths.var_dir') . '/custom/snippets';
    }

    /**
     * Backend-Sprachen: die Kürzel, für die es Core/lang/{code}.ini gibt, und die der
     * Übersetzungspakete - wie Cms::backendLanguages(), nur ohne Cms (der Builder soll
     * ohne Cms auskommen).
     */
    private function backendLanguages(): array
    {
        return Cms::scanLanguages($this->config->get('paths.core_dir') . '/lang', self::translatedDir($this->config));
    }

    /**
     * Sprachdateien eines lang-Verzeichnisses einlesen: {code}.ini.
     *
     * @param string[] $errors  Fehler der .ini-Dateien werden angehängt
     * @return array<string, array<string, string>>  Sprache => [Schlüssel => Text]
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
     * Wurzel der Übersetzungspakete: var/translation/translated.
     */
    public static function translatedDir(Config $config): string
    {
        return $config->get('paths.var_dir') . self::TRANSLATED_DIR;
    }

    /**
     * Übersetzungspakete mit ihrer Sprache, nach Namen sortiert.
     *
     * @return array<string, string>  Pfad => Sprache
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
     * Sprache eines Übersetzungspakets aus dem Dateinamen: sv_SE.ini, sv_SE_core.ini,
     * en_US_sv_SE.ini (-> en_US). Andere Namen: null.
     */
    public static function translatedLanguage(string $file): ?string
    {
        return preg_match(self::TRANSLATED_PATTERN, basename($file), $m) ? $m[1] : null;
    }

    /**
     * Vorlagen zum Übersetzen nach var/translation/blueprint schreiben.
     *
     * @param array $blueprint  'frontend'|'backend' => [Präfix => [Schlüssel => Text]] (en_US, Core)
     * @return string[]  geschriebene Dateien
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
                    // Sprachbezeichnungen kommen je Sprache aus en_US_{code}.ini
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
     * Sprache einer Sprachdatei aus ihrem Namen: {code}.ini im Format
     * Cms::LANG_PATTERN (de_DE.ini). Alles andere: null.
     */
    public static function fileLanguage(string $file): ?string
    {
        if (!str_ends_with($file, '.ini')) return null;
        $name = basename($file, '.ini');
        return preg_match(Cms::LANG_PATTERN, $name) ? $name : null;
    }

    /**
     * Inhalt einer Cachedatei: return [...] mit den Snippets eines Präfixes und
     * Geltungsbereichs; ICU-Snippets als Closure.
     *
     * @param array $snippets  schlüssel => text
     * @return string          PHP-Quelltext
     */
    private function compileFile(array $snippets): string
    {
        $phpContent = "<?php\n\n";
        $phpContent .= "// Automatisch generierter Snippet-Cache - siehe Camindo\\Core\\SnippetCache\n";
        $phpContent .= "return [\n";

        foreach ($snippets as $key => $value) {
            // kein trim()! Führende und folgende Leerzeichen in einem Snippet
            // können gewollt sein (z. B. als Abstand vor einer Einheit).

            // Enthält das Snippet ein ICU-Konstrukt? Dann als Closure ablegen,
            // sonst als schlichten String. Die schnelle str_contains()-Prüfung
            // davor erspart den Regex bei der großen Mehrheit der Snippets.
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
     * Zerlegt gemischten Text in statische Teile und ICU-Blöcke und erzeugt daraus
     * einen PHP-Ausdruck.
     *
     * Ein Snippet ist selten NUR ein ICU-Block - meist steht Text davor und dahinter.
     * Die Methode läuft deshalb zeichenweise durch: alles bis zur nächsten gültigen
     * ICU-Signatur wandert in den Puffer, der Block selbst wird über die Zählung der
     * geschweiften Klammern isoliert (nicht per Regex - der käme mit Verschachtelung
     * nicht zurecht) und an compileICUBlockToPHP() gegeben.
     *
     * Herausgegeben werden die Teile mit '.' verkettet, also ein einziger
     * PHP-Ausdruck, den der Aufrufer in eine Closure setzen kann.
     *
     * @param string  $text        Snippet-Text
     * @param ?string $pluralVar   Name der Zählvariable, wenn wir uns innerhalb eines
     *                             plural-Zweigs befinden - nur dann wird '#' ersetzt
     * @return string              PHP-Quelltext (Ausdruck)
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
                    
                    // Bisherigen Puffer als statischen Text ablegen
                    if ($buffer !== '') {
                        $parts[] = $this->formatStaticText($buffer, $pluralVar);
                        $buffer = '';
                    }

                    // Den gesamten, in geschweiften Klammern eingeschlossenen Block extrahieren
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
                    
                    // Den isolierten Block kompilieren
                    $parts[] = $this->compileICUBlockToPHP($block);
                    
                    // Index verschieben, da der Block verarbeitet wurde
                    $i = $j + 1;
                    continue;
                }
            }
            
            $buffer .= $char;
            $i++;
        }

        // Restlichen Text im Puffer ablegen
        if ($buffer !== '') {
            $parts[] = $this->formatStaticText($buffer, $pluralVar);
        }

        // Wenn nichts extrahiert wurde, leeren String zurückgeben, ansonsten Teile mit '.' verketten
        return empty($parts) ? "''" : implode(" . ", $parts);
    }

    /**
     * Statischen Text als PHP-Literal ausgeben, mit '#'-Ersetzung im plural-Zweig.
     *
     * Innerhalb eines plural-Blocks steht '#' für den Zahlenwert. Statt ihn hier
     * einzusetzen (der Wert ist zur Kompilierzeit ja nicht bekannt), wird ein
     * str_replace()-Aufruf in den erzeugten Code geschrieben.
     *
     * Ein Maskieren von '#' gibt es nicht - jedes '#' im Zweig wird ersetzt.
     */
    private function formatStaticText(string $text, ?string $pluralVar): string {
        if ($pluralVar !== null && str_contains($text, '#')) {
            return "str_replace('#', (string)(\$args['$pluralVar'] ?? ''), " . var_export($text, true) . ")";
        }
        return var_export($text, true);
    }

    /**
     * Einen einzelnen ICU-Block in ein PHP-match() übersetzen.
     *
     * Aus  {count, plural, =0 {nichts} other {# Stück}}  wird
     *     match((string)($args['count'] ?? '')) { '0' => 'nichts', default => str_replace(…) }
     *
     * Der Zweig 'other' wird zu 'default'. Bei plural wird das führende '=' vom
     * Schlüssel entfernt - verglichen wird also exakt und nicht nach den
     * grammatikalischen Plural-Regeln der Sprache (one/few/many werden nur wörtlich
     * getroffen).
     *
     * Der Inhalt jedes Zweigs geht zurück durch compileMixedTextToPHP() - so lassen
     * sich Blöcke beliebig ineinander schachteln. In einem plural-Zweig wird dabei der
     * Variablenname weitergereicht, damit '#' dort ersetzt werden kann.
     *
     * Passt der Block nicht auf das erwartete Muster, wird er unverändert als String
     * ausgegeben - lieber der Rohtext als ein Syntaxfehler in der Cachedatei.
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

            // Der Rückgabewert eines Zweigs (Choice) kann wieder gemischten Text enthalten.
            // Daher rufen wir die compileMixedTextToPHP rekursiv auf.
            $passPluralVar = ($type === 'plural') ? $varName : null;
            $compiledValue = $this->compileMixedTextToPHP(trim($value), $passPluralVar);

            $php .= "            $matchKey => $compiledValue,\n";
        }
        
        $php .= "        }";
        return $php;
    }

    /**
     * Die Zweige eines ICU-Blocks in schlüssel => inhalt zerlegen.
     *
     * Zählt die geschweiften Klammern mit: auf Ebene 0 gelesener Text ist der
     * Schlüssel ('=0', 'other', 'm'), was zwischen der öffnenden und der zugehörigen
     * schließenden Klammer steht, ist der Inhalt. Verschachtelte Klammern landen
     * unverändert im Inhalt und werden erst beim rekursiven Aufruf ausgewertet.
     *
     * Deshalb keine Regex: die Verschachtelung wäre damit nicht zu greifen.
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
