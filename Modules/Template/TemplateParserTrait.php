<?php

namespace Camindo\Core\Modules\Template;

/**
 * Übersetzt CMS-Befehle der Form
 *
 *     (cms:befehls_name parameter1 key=value key['sub'][] = "value" key[sub] >= 5 ...)
 *
 * in PHP-Code.
 *
 * ZUM AUFBAU
 * --------------------
 * Ein Template ist normaler Text (HTML, gern auch roher PHP-Code), in dem CMS-Befehle
 * stehen. Der Parser ist deshalb kein Compiler im engeren Sinn: er lässt alles
 * unverändert stehen, was kein Befehl ist, und ersetzt nur die Befehle.
 *
 * Der Ablauf in parse() ist entsprechend schlicht - suche das nächste "(cms:",
 * übernimm den Text davor, lies den Befehl, erzeuge dafür PHP, mach hinter dem Befehl
 * weiter. Alles andere sind Hilfsmethoden dazu, in vier Gruppen:
 *
 *     Parsen        parseCmsCommand(), readParam(), readToken(), readSub()
 *                   und die Scanner-Helfer skipWs(), consumeQuoted(), consumeBraces()
 *     Validierung   validateCmsCommand() - gegen die Definition aus commands.php
 *     Erzeugung     generateCmsCommand(), paramPath(), normToken(), phpValueToken(),
 *                   emitFragments()
 *     Darstellung   renderStyledCode(), styleRawPhp(), styleCommandInner();
 *                   styleCode() liefert nur die Darstellung (Codebeispiele der Handbücher)
 *
 * DIE BEFEHLSDEFINITIONEN STEHEN WOANDERS
 * --------------------
 * commands.php liefert je Befehl, welche Parameter erlaubt sind und welcher PHP-Code
 * daraus entsteht. Der Parser selbst kennt keinen einzigen Befehl - ein neuer Befehl
 * bedeutet einen Eintrag dort, keine Änderung hier. Zusatzmodule bringen eigene
 * Befehle in einer eigenen Datei mit (settings.php['commands'], siehe loadCommands()).
 *
 * ZEILENNUMMERN BLEIBEN ERHALTEN
 * --------------------
 * Das durchzieht die ganze Datei und erklärt manche Umständlichkeit. Der erzeugte
 * PHP-Code hat an jeder Stelle dieselbe Zeilennummer wie der CMS-Quelltext - ein
 * mehrzeiliger Befehl wird also zu ebenso vielen Zeilen PHP. Nur dadurch zeigt eine
 * PHP-Fehlermeldung aus var/php/{area_id}/{name}.php auf die richtige Zeile im Editor.
 * Deshalb führt der Scanner in $s['line'] eine relative Zeilenzählung mit, und deshalb
 * setzt emitFragments() den Code zeilenweise zusammen statt einfach zu verketten.
 *
 * FEHLER BRECHEN NICHT AB
 * --------------------
 * Unbekannte Befehle und ungültige Parameter werden gemeldet und übersprungen bzw. als
 * Rohtext übernommen; parse() liefert trotzdem ein Ergebnis. Ein halbfertiges Template
 * lässt sich damit zwischenspeichern (siehe Template::hookOnSave()).
 *
 * DREI RÜCKGABEWERTE
 * --------------------
 *     phpCode     das kompilierte Template
 *     styledCode  derselbe Quelltext als HTML mit Syntax-Einfärbung für den Editor
 *     errors      Meldungen mit Zeilennummern
 *
 * @see Camindo\Core\Modules\Template\Template          benutzt den Trait in hookOnSave()
 * @see Camindo\Core\Modules\Template\TemplateBackend   Editor und Fehleranzeige
 * @see docs/developers/06-templates.md                               Befehlsreferenz
 */
trait TemplateParserTrait
{
    // Befehlsdefinitionen aus commands.php, geladen zu Beginn von parse().
    protected array $commands = [];
    // Bereich des Templates, das gerade übersetzt wird (für die Verweise im styledCode)
    protected int $parseAreaId = 0;

    /**
     * Befehlsdefinitionen zusammenstellen: die des Cores (commands.php) plus die der
     * aktiven Zusatzmodule.
     *
     * Ein Modul nennt in install/settings.php['commands'] eine Datei relativ zu
     * seinem Verzeichnis (z. B. 'commands.php'), die ein Array im selben Aufbau wie
     * die Core-Datei zurückgibt. Die Befehlsnamen tragen sinnvollerweise den
     * Modulnamen als Präfix ('pdf_page' -> CMS:PDF_PAGE); ein Name, den der Core
     * schon vergibt, bleibt beim Core. Die in 'method' genannten Objekte
     * ('pdf.cmsPdfPage' -> $this->pdf) stellt das Modul über settings.php['services']
     * bereit, siehe Page::__construct().
     *
     * Die Modulliste kommt aus Cms::$modules (Laufzeitliste des ComponentManagers).
     * Ohne Cms-Instanz - der Trait läuft auch allein, etwa in einem Testscript -
     * gibt es nur die Core-Befehle.
     */
    protected function loadCommands(): array
    {
        $commands = include __DIR__ . '/commands.php';
        if (!isset($this->cms) || !is_array($this->cms->modules ?? null)) {
            return $commands;
        }
        foreach ($this->cms->modules as $module) {
            $file = (string)($module['commands'] ?? '');
            if ($file === '' || empty($module['path'])) continue;
            $file = $module['path'] . '/' . ltrim($file, '/');
            if (!is_file($file)) continue;
            $moduleCommands = include $file;
            if (!is_array($moduleCommands)) continue;
            foreach ($moduleCommands as $name => $def) {
                $name = strtolower((string)$name);
                if (isset($commands[$name]) || !is_array($def)) continue;
                $commands[$name] = $def;
            }
        }
        return $commands;
    }

    /**
     * CMS-Quelltext übersetzen.
     *
     * Läuft einmal linear durch den Text (siehe Trait-Kommentar). Was kein gültiger
     * Befehl ist, wandert unverändert in die Ausgabe - auch ein "(cms:" ohne
     * schließende Klammer, dann allerdings mit Fehlermeldung. Der Durchlauf selbst
     * steht in scan().
     *
     * @param string $code     Quelltext in CMS-Syntax
     * @param int    $area_id  Inhaltsbereich des Templates - manche Befehle lösen
     *                         damit Feldnamen auf
     * @return array           ['phpCode' => string, 'styledCode' => string,
     *                          'errors' => string[]]
     */
    public function parse(string $code, int $area_id)
    {
        $this->commands = $this->loadCommands();
        $this->parseAreaId = $area_id;

        $errors = [];
        [$php, $segments] = $this->scan($code, $errors, true);
        $styled = $this->renderStyledCode($code, $segments);

        // Script-Policy des Frontends (19-csp.md §9): Hinweise auf Scripts ohne
        // (CMS:NONCE) und Inline-Handler, die sie blockieren würde
        array_push($errors, ...$this->inlineHandlerWarnings($code));

        return [
            'phpCode'    => $php,     // string
            'styledCode' => $styled,  // string
            'errors'     => $errors,  // array
        ];
    }

    /**
     * Nur die eingefärbte Ansicht, ohne zu kompilieren - für Codebeispiele außerhalb
     * des Template-Editors (Handbücher: ```cms-Blöcke, Docs::render()).
     *
     * Dasselbe HTML wie 'styledCode' aus parse() (maskierter Text, <span class="c9-command">,
     * <span class="c9-php">), aber ohne Template-Links - ein Beispiel verweist auf
     * kein vorhandenes Template. Unbekannte oder unvollständige Befehle bleiben Text,
     * Meldungen entfallen.
     */
    public function styleCode(string $code): string
    {
        $this->commands = $this->loadCommands();
        $this->parseAreaId = 0;

        $errors = [];
        [, $segments] = $this->scan($code, $errors, false);
        return $this->renderStyledCode($code, $segments, false);
    }

    /**
     * Der eigentliche Durchlauf von parse() und styleCode(): Text und Befehle
     * trennen, mit $compile = true zugleich den PHP-Code erzeugen.
     *
     * Nebenbei entstehen die $segments: eine Liste aus Text- und Befehlsabschnitten,
     * aus der renderStyledCode() am Ende die eingefärbte Ansicht baut. Sie wird
     * hier mitgeführt, weil nur an dieser Stelle bekannt ist, welche Zeichen zu einem
     * Befehl gehören und welche nicht.
     *
     * @return array  [string $php, array $segments]
     */
    private function scan(string $code, array &$errors, bool $compile): array
    {
        $php      = '';
        $segments = []; // Segmente für styledCode: 'text' | 'command'

        $len = strlen($code);
        $pos = 0;

        while ($pos < $len) {
            $hit = stripos($code, '(cms:', $pos);

            if ($hit === false) {
                // Rest ist normaler Text
                $this->addTextSegment($segments, $pos, $len);
                $php .= substr($code, $pos);
                break;
            }

            // Text vor dem Befehl unverändert übernehmen
            if ($hit > $pos) {
                $this->addTextSegment($segments, $pos, $hit);
                $php .= substr($code, $pos, $hit - $pos);
            }

            $cmd = $this->parseCmsCommand($code, $hit, $errors);

            if ($cmd === null) {
                // strukturell kein gültiger Befehl: '(' übernehmen, dahinter weitersuchen
                $this->addTextSegment($segments, $hit, $hit + 1);
                $php .= '(';
                $pos  = $hit + 1;
                continue;
            }

            if ($cmd['known']) {
                $this->validateCmsCommand($cmd, $errors);
                if ($compile) {
                    $php .= $this->generateCmsCommand($cmd, $errors);
                }
                $segments[] = ['type' => 'command', 'cmd' => $cmd];
            } else {
                // unbekannter Befehl: Quelltext unverändert lassen (Fehler wurde gemeldet)
                $this->addTextSegment($segments, $cmd['start'], $cmd['end']);
                $php .= substr($code, $cmd['start'], $cmd['end'] - $cmd['start']);
            }

            $pos = $cmd['end'];
        }

        return [$php, $segments];
    }

    /* =====================================================================
     * Script-Policy: Hinweise
     * ===================================================================== */

    /**
     * Hinweise für die Script-Policy des Frontends (19-csp.md §9) im HTML-Teil des
     * Quelltexts (Token T_INLINE_HTML - PHP-Code bleibt außen vor):
     *
     *   - <script> ohne Nonce: ein Script im Template braucht <script(CMS:NONCE)>,
     *     sonst blockiert es eine durchgesetzte Policy. Ausgenommen sind Scripts mit
     *     relativem src (eigene Dateien, 'self') und Datenblöcke (type="application/
     *     ld+json", "application/json", "text/template" …)
     *   - Inline-Event-Handler (onclick="…") und javascript:-Links: die blockiert
     *     die Policy immer, auch mit Nonce
     *
     * Die Nonce setzt der Template-Programmierer selbst mit (CMS:NONCE); der Compiler
     * ändert nichts. Nur Hinweise (error_text), das Speichern hindern sie nicht;
     * höchstens zehn.
     *
     * @return string[]
     */
    private function inlineHandlerWarnings(string $code): array
    {
        if (!preg_match('/<script|\son[a-z]+\s*=|javascript:/i', $code)) return [];
        $warnings = [];
        foreach (token_get_all($code) as $token) {
            if (!is_array($token) || $token[0] !== T_INLINE_HTML) continue;
            $html = $token[1];
            $lineOf = fn(int $offset) => $token[2] + substr_count($html, "\n", 0, $offset);

            if (preg_match_all('/<script\b([^>]*)>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as [$attributes, $offset]) {
                    if (preg_match('/\bnonce\s*=|\(cms:nonce/i', $attributes)) continue;
                    if (preg_match('/\btype\s*=\s*["\']?(?!text\/javascript|module|application\/javascript)[a-z]/i', $attributes)) continue;
                    if (preg_match('/\bsrc\s*=\s*["\']?\/(?!\/)/i', $attributes)) continue;
                    $line = $lineOf($offset);
                    $warnings[$line . 'script'] = 'Line ' . $line . ": Note: <script> without (CMS:NONCE) is blocked when the frontend script policy (CSP) is enforced - write <script(CMS:NONCE)>.";
                    if (count($warnings) >= 10) return array_values($warnings);
                }
            }
            $patterns = [
                '/<[a-z][^>]*?\s(on[a-z]+)\s*=/i',
                '/<[a-z][^>]*?\s(?:href|action|src)\s*=\s*["\']?\s*(javascript):/i',
            ];
            foreach ($patterns as $pattern) {
                if (!preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE)) continue;
                foreach ($matches[1] as [$what, $offset]) {
                    $line = $lineOf($offset);
                    $warnings[$line . $what] = 'Line ' . $line . ": Note: '" . strtolower($what)
                        . "' is blocked when the frontend script policy (CSP) is enforced, even with a nonce - use addEventListener in a <script(CMS:NONCE)> instead.";
                    if (count($warnings) >= 10) return array_values($warnings);
                }
            }
        }
        return array_values($warnings);
    }

    /* =====================================================================
     * Referenzen (Export/Import von Template-Sätzen)
     * ===================================================================== */

    /**
     * Referenzierende Parameterwerte umschreiben - ohne zu kompilieren.
     *
     * Läuft mit demselben Scanner wie parse() über alle bekannten Befehle und ruft
     * für jeden Parameter, der laut commands.php einen Verweis trägt ('ref':
     * template | area | record), die Abbildung auf:
     *
     *     $map(string $ref, string $value): ?string    null = unverändert lassen
     *
     * Bei 'record' wird jede Zahl im Wert einzeln übergeben (Kommalisten,
     * Ausdrücke wie "123 OR >1000"), bei 'template' und 'area' der ganze Wert.
     * Anführungszeichen bleiben stehen, der Aufruf sieht nur den Inhalt. Werte mit
     * PHP-Anteil ({$...}, $var) werden übergangen - sie stehen erst zur Laufzeit
     * fest. Unbekannte Befehle und strukturell unlesbare Stellen bleiben, wie sie
     * sind.
     *
     * Der Export markiert damit Ids und Namen (<!-- CMS:REPLACE ... -->,
     * RecordExport), der Import löst die Markierungen wieder auf (RecordImport).
     */
    public function mapReferences(string $code, callable $map): string
    {
        $this->commands = $this->loadCommands();
        $errors = [];
        $spans = []; // [start, end, replacement]
        $len = strlen($code);
        $pos = 0;
        while ($pos < $len) {
            $hit = stripos($code, '(cms:', $pos);
            if ($hit === false) break;
            $cmd = $this->parseCmsCommand($code, $hit, $errors);
            if ($cmd === null) {
                $pos = $hit + 1;
                continue;
            }
            $pos = $cmd['end'];
            if (!$cmd['known']) continue;
            $paramDefs = (array)($cmd['def']['params'] ?? []);
            $doParam = (string)($cmd['def']['do'] ?? '');
            foreach ($cmd['params'] as $param) {
                $keyText = strtolower((string)$param['key']['text']);
                // Wert ohne Schlüssel: gilt für den 'do'-Parameter
                if ($param['standalone']) {
                    if ($doParam === '' || !isset($paramDefs[$doParam]['ref'])) continue;
                    $ref = $paramDefs[$doParam]['ref'];
                    $token = $param['key'];
                }
                else {
                    if (!isset($paramDefs[$keyText]['ref'])) continue;
                    $ref = $paramDefs[$keyText]['ref'];
                    $token = $param['value'];
                }
                if (is_array($ref)) {
                    // Typ hängt am ersten Unterschlüssel (filter[area_id] ...)
                    $sub = strtolower(trim((string)($param['subs'][0]['text'] ?? ''), "\"'"));
                    $ref = $ref[$sub] ?? null;
                    if ($ref === null) continue;
                }
                if ($token === null || !empty($token['php'])) continue;
                $text = (string)$token['text'];
                $quote = $token['quote'];
                $inner = $quote !== null ? substr($text, 1, -1) : $text;
                if ($inner === '' || str_contains($inner, '$') || str_contains($inner, '{')) continue;
                if ($ref === 'record') {
                    $mapped = preg_replace_callback('/[0-9]+/', fn($m) => $map('record', $m[0]) ?? $m[0], $inner);
                }
                else {
                    $mapped = $map((string)$ref, $inner) ?? $inner;
                }
                if ($mapped === $inner) continue;
                $start = (int)$token['start'] + ($quote !== null ? 1 : 0);
                $spans[] = [$start, $start + strlen($inner), $mapped];
            }
        }
        // von hinten ersetzen, damit die Offsets gültig bleiben
        usort($spans, fn($a, $b) => $b[0] <=> $a[0]);
        foreach ($spans as [$start, $end, $replacement]) {
            $code = substr($code, 0, $start) . $replacement . substr($code, $end);
        }
        return $code;
    }

    /* =====================================================================
     * Parsen eines einzelnen Befehls
     * ===================================================================== */

    /**
     * Liest einen Befehl ab Position $start ("(cms:...").
     * Liefert null, wenn der Befehl strukturell nicht lesbar ist
     * (z. B. fehlende schließende Klammer).
     *
     * Gelesen wird mit einem Scanner-Zustand $s (Quelltext, Länge, Position und die
     * relative Zeile innerhalb des Befehls), der durch alle read*- und consume*-Helfer
     * per Referenz weitergereicht wird - jeder von ihnen schiebt die Position weiter.
     * Die mitgeführte Zeilenzählung ist nötig, damit Fehlermeldungen auf die richtige
     * Zeile zeigen und der erzeugte Code die Zeilenzahl behält.
     *
     * Ein unbekannter Befehlsname ist kein struktureller Fehler: er wird gemeldet, das
     * Ergebnis bekommt 'known' => false, und der Aufrufer übernimmt den Quelltext dann
     * unverändert.
     *
     * @return ?array  start, end, name, nameRaw, baseLine, lines, params, known, def
     */
    private function parseCmsCommand(string $code, int $start, array &$errors): ?array
    {
        $baseLine = substr_count($code, "\n", 0, $start); // 0-basiert

        if (!preg_match('/\(cms:([a-zA-Z0-9_]+)/iA', $code, $m, 0, $start)) {
            return null;
        }

        $nameRaw = $m[1];
        $name    = strtolower($nameRaw);

        // Scanner-Zustand: Position + relative Zeile innerhalb des Befehls
        $s = [
            'code' => $code,
            'len'  => strlen($code),
            'pos'  => $start + strlen($m[0]),
            'line' => 0,
        ];

        $params = [];

        while (true) {
            $this->skipWs($s);

            if ($s['pos'] >= $s['len']) {
                $errors[] = 'Line ' . ($baseLine + 1) . ": Unterminated command '(CMS:"
                    . strtoupper($name) . "' - missing closing parenthesis.";
                return null;
            }

            if ($code[$s['pos']] === ')') {
                $s['pos']++;
                break;
            }

            $param = $this->readParam($s, $baseLine, $name, $errors);
            if ($param === null) {
                return null;
            }
            $params[] = $param;
        }

        $known = isset($this->commands[$name]);
        if (!$known) {
            $errors[] = 'Line ' . ($baseLine + 1) . ": Unknown command 'CMS:" . strtoupper($name) . "'.";
        }

        return [
            'start'    => $start,
            'end'      => $s['pos'],
            'name'     => $name,
            'nameRaw'  => $nameRaw,
            'baseLine' => $baseLine,
            'lines'    => $s['line'],   // relative Zeile der ')'
            'params'   => $params,
            'known'    => $known,
            'def'      => $this->commands[$name] ?? [],
        ];
    }

    /**
     * Liest einen Parameter: key[sub][...] = value  |  key = value  |  standalone
     *
     * Statt '=' darf ein Vergleichsoperator zwischen Schlüssel und Wert stehen
     * (filter[preis] >= 100, filter[titel] != "x"), siehe readOperator(). Er landet
     * in 'op'; generateCmsCommand() stellt ihn dem Wert voran.
     */
    private function readParam(array &$s, int $baseLine, string $cmdName, array &$errors): ?array
    {
        $errLine = fn() => $baseLine + $s['line'] + 1;

        $key = $this->readToken($s, 'key');
        if ($key === null) {
            $errors[] = 'Line ' . $errLine() . ": Unexpected character '"
                . ($s['code'][$s['pos']] ?? '') . "' in command 'CMS:" . strtoupper($cmdName) . "'.";
            return null;
        }

        // Sub-Schlüssel: [..][..][] direkt hinter dem Schlüssel
        $subs = [];
        while ($s['pos'] < $s['len'] && $s['code'][$s['pos']] === '[') {
            $sub = $this->readSub($s, $baseLine, $cmdName, $errors);
            if ($sub === null) {
                return null;
            }
            $subs[] = $sub;
        }

        // optionales '=' bzw. Vergleichsoperator (auch über Zeilenumbrüche hinweg)
        $this->skipWs($s);

        $opLine = null;
        $value  = null;
        $op     = $this->readOperator($s);

        if ($op !== null) {
            $opLine = $s['line'];
            $this->skipWs($s);

            $value = $this->readToken($s, 'value');
            if ($value === null) {
                $errors[] = 'Line ' . $errLine() . ": Missing value after '$op' in command 'CMS:"
                    . strtoupper($cmdName) . "'.";
                return null;
            }
        } elseif (!empty($subs)) {
            $errors[] = 'Line ' . ($baseLine + $key['line'] + 1) . ": Parameter '"
                . $key['text'] . "' of command 'CMS:" . strtoupper($cmdName)
                . "' has sub-keys but no value.";
            return null;
        }

        return [
            'standalone' => $value === null,
            'key'        => $key,
            'subs'       => $subs,
            'op'         => $op,
            'opLine'     => $opLine,
            'value'      => $value,
        ];
    }

    /**
     * Liest den Operator zwischen Schlüssel und Wert: '=' (Zuweisung) oder einer der
     * Vergleichsoperatoren ==, !=, !!, <=, >=, <<, >>, <, >, ! - der längste passende.
     * Was danach folgt, gehört zum Wert: key=>5 ist '=' mit dem Wert ">5" (wie
     * bisher), key==x dagegen der Operator '=='.
     *
     * @return ?string  null = kein Operator (Parameter ohne Wert)
     */
    private function readOperator(array &$s): ?string
    {
        $two = substr($s['code'], $s['pos'], 2);
        if (in_array($two, ['==', '!=', '!!', '<=', '>=', '<<', '>>'], true)) {
            $s['pos'] += 2;
            return $two;
        }
        $one = $s['code'][$s['pos']] ?? '';
        if (in_array($one, ['=', '<', '>', '!'], true)) {
            $s['pos']++;
            return $one;
        }
        return null;
    }

    /**
     * Liest ein Token (Schlüssel, Sub-Schlüssel oder Wert).
     * Rückgabe: ['text','quote','start','line','php'] oder null.
     * 'php' enthält absolute Offsets erkannter PHP-Anteile ({$...} bzw. $var).
     */
    private function readToken(array &$s, string $mode): ?array
    {
        $code = $s['code'];
        $len  = $s['len'];

        if ($s['pos'] >= $len) {
            return null;
        }

        $start = $s['pos'];
        $line  = $s['line'];
        $c     = $code[$s['pos']];

        // Vollständig gequotetes Token
        if ($c === '"' || $c === "'") {
            if (!$this->consumeQuoted($s)) {
                return null;
            }
            $text = substr($code, $start, $s['pos'] - $start);
            return [
                'text'  => $text,
                'quote' => $c,
                'start' => $start,
                'line'  => $line,
                // in einfachen Anführungszeichen interpoliert PHP nicht
                'php'   => $c === '"' ? $this->findPhpSpans($text, $start) : [],
            ];
        }

        // Bare-Token: endet an Whitespace bzw. modusabhängigen Zeichen,
        // darf aber {..}-Blöcke und eingebettete Strings enthalten
        $stop = match ($mode) {
            'key'   => " \t\r\n)=[",
            'sub'   => "]\n",
            default => " \t\r\n)",
        };

        while ($s['pos'] < $len) {
            $c = $code[$s['pos']];
            if (strpos($stop, $c) !== false) {
                break;
            }
            // Schlüssel endet auch vor einem Vergleichsoperator (key<5, key!=x) -
            // nicht am Token-Anfang (Wert ohne Schlüssel wie !x) und nicht im
            // Objektzugriff $obj->name
            if ($mode === 'key' && $s['pos'] > $start && ($c === '<' || $c === '!'
                || ($c === '>' && $code[$s['pos'] - 1] !== '-'))) {
                break;
            }
            if ($c === '{') {
                if (!$this->consumeBraces($s)) {
                    return null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                if (!$this->consumeQuoted($s)) {
                    return null;
                }
                continue;
            }
            $s['pos']++;
        }

        if ($s['pos'] === $start) {
            return null;
        }

        $text = substr($code, $start, $s['pos'] - $start);

        return [
            'text'  => $text,
            'quote' => null,
            'start' => $start,
            'line'  => $line,
            'php'   => $this->findPhpSpans($text, $start),
        ];
    }

    /**
     * Liest einen Sub-Schlüssel ab '[' bis ']' (auch leer: []).
     */
    private function readSub(array &$s, int $baseLine, string $cmdName, array &$errors): ?array
    {
        $s['pos']++; // '['

        if ($s['pos'] < $s['len'] && $s['code'][$s['pos']] === ']') {
            $s['pos']++;
            return ['empty' => true, 'text' => '', 'quote' => null, 'php' => [], 'line' => $s['line']];
        }

        $tok = $this->readToken($s, 'sub');
        if ($tok === null || $s['pos'] >= $s['len'] || $s['code'][$s['pos']] !== ']') {
            $errors[] = 'Line ' . ($baseLine + $s['line'] + 1)
                . ": Missing closing ']' in command 'CMS:" . strtoupper($cmdName) . "'.";
            return null;
        }

        $s['pos']++; // ']'
        $tok['empty'] = false;

        return $tok;
    }

    /**
     * Überspringt Whitespace und zählt dabei Zeilenumbrüche mit.
     */
    private function skipWs(array &$s): void
    {
        while ($s['pos'] < $s['len']) {
            $c = $s['code'][$s['pos']];
            if ($c === "\n") {
                $s['line']++;
                $s['pos']++;
            } elseif ($c === ' ' || $c === "\t" || $c === "\r") {
                $s['pos']++;
            } else {
                break;
            }
        }
    }

    /**
     * Konsumiert einen gequoteten String inkl. Anführungszeichen ("..." oder '...'),
     * Backslash-Escapes und Zeilenumbrüche eingeschlossen.
     */
    private function consumeQuoted(array &$s): bool
    {
        $code = $s['code'];
        $len  = $s['len'];
        $q    = $code[$s['pos']];
        $s['pos']++;

        while ($s['pos'] < $len) {
            $c = $code[$s['pos']];
            if ($c === '\\' && $s['pos'] + 1 < $len) {
                $s['pos'] += 2;
                continue;
            }
            if ($c === "\n") {
                $s['line']++;
            }
            $s['pos']++;
            if ($c === $q) {
                return true;
            }
        }

        return false; // unbeendeter String
    }

    /**
     * Konsumiert einen balancierten {..}-Block (quote-sicher).
     */
    private function consumeBraces(array &$s): bool
    {
        $code  = $s['code'];
        $len   = $s['len'];
        $depth = 0;

        while ($s['pos'] < $len) {
            $c = $code[$s['pos']];
            if ($c === '"' || $c === "'") {
                if (!$this->consumeQuoted($s)) {
                    return false;
                }
                continue;
            }
            if ($c === "\n") {
                $s['line']++;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $s['pos']++;
                    return true;
                }
            }
            $s['pos']++;
        }

        return false;
    }

    /**
     * Findet PHP-Anteile ({$...}-Blöcke und $variablen) innerhalb eines Token-Texts.
     * Liefert absolute Offsets [start, end) im Gesamt-Code.
     */
    private function findPhpSpans(string $text, int $abs): array
    {
        $spans = [];
        $len   = strlen($text);
        $i     = 0;

        while ($i < $len) {
            $c = $text[$i];

            if ($c === '\\') {
                $i += 2;
                continue;
            }

            if ($c === '{' && $i + 1 < $len && $text[$i + 1] === '$') {
                $depth = 0;
                $j     = $i;
                while ($j < $len) {
                    if ($text[$j] === '{') {
                        $depth++;
                    } elseif ($text[$j] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $j++;
                            break;
                        }
                    }
                    $j++;
                }
                $spans[] = [$abs + $i, $abs + $j];
                $i = $j;
                continue;
            }

            if ($c === '$' && $i + 1 < $len && (ctype_alpha($text[$i + 1]) || $text[$i + 1] === '_')) {
                $j = $i + 1;
                while ($j < $len && (ctype_alnum($text[$j]) || $text[$j] === '_')) {
                    $j++;
                }
                // einfache ->Zugriffe mitnehmen ($this->irgendwas)
                while ($j + 2 < $len && $text[$j] === '-' && $text[$j + 1] === '>'
                    && (ctype_alpha($text[$j + 2]) || $text[$j + 2] === '_')) {
                    $j += 2;
                    while ($j < $len && (ctype_alnum($text[$j]) || $text[$j] === '_')) {
                        $j++;
                    }
                }
                $spans[] = [$abs + $i, $abs + $j];
                $i = $j;
                continue;
            }

            $i++;
        }

        return $spans;
    }

    /* =====================================================================
     * Validierung
     * ===================================================================== */

    /**
     * Parameter eines Befehls gegen seine Definition aus commands.php prüfen.
     *
     * Geprüft wird, ob es den Schlüssel überhaupt gibt, ob er hier stehen darf, ob er
     * nur einmal vorkommt und ob er einen Vergleichsoperator statt '=' annimmt
     * ('operator' in commands.php). "do" ist der Sonderfall: der Parameter ohne Schlüssel,
     * den manche Befehle als erstes Argument erlauben - höchstens einer je Befehl.
     *
     * Fehlerhafte Parameter werden nicht entfernt, sondern mit 'drop' => true
     * markiert; generateCmsCommand() überspringt sie dann. So bleiben Indizes und
     * Zeilenzählung unverändert.
     *
     * $cmd kommt per Referenz herein, weil genau diese Markierung gesetzt wird.
     */
    private function validateCmsCommand(array &$cmd, array &$errors): void
    {
        $def   = $cmd['def'];
        $upper = 'CMS:' . strtoupper($cmd['name']);
        $seen  = [];
        $doUsed = false;

        foreach ($cmd['params'] as $i => $p) {
            $line = $cmd['baseLine'] + $p['key']['line'] + 1;

            if ($p['standalone']) {
                if (!isset($def['do'])) {
                    $errors[] = "Line $line: Command '$upper' does not accept a parameter without a key.";
                    $cmd['params'][$i]['drop'] = true;
                    continue;
                }
                if ($doUsed) {
                    $errors[] = "Line $line: Command '$upper' allows only one parameter without a key.";
                    $cmd['params'][$i]['drop'] = true;
                    continue;
                }
                $doUsed = true;
                $cmd['params'][$i]['mappedKey'] = $def['do'];
                $seen[$def['do']] = true;
                continue;
            }

            // Statischen Schlüsselnamen ermitteln (nur ohne PHP-Anteile prüfbar)
            $keyTok = $p['key'];
            $static = null;
            if (empty($keyTok['php'])) {
                $static = $keyTok['quote'] !== null
                    ? substr($keyTok['text'], 1, -1)
                    : $keyTok['text'];
            }
            $cmd['params'][$i]['staticKey'] = $static;

            if ($static === null) {
                continue; // dynamischer Schlüssel: nicht validierbar
            }

            if (!isset($def['params'][$static])) {
                $errors[] = "Line $line: Unknown parameter '$static' for command '$upper'.";
                continue;
            }

            $seen[$static] = true;

            // Vergleichsoperator statt '=' nur bei Parametern mit 'operator' (filter)
            if ($p['op'] !== '=' && empty($def['params'][$static]['operator'])) {
                $errors[] = "Line $line: Parameter '$static' of command '$upper' does not accept "
                    . "the comparison operator '{$p['op']}' - use '='.";
                $cmd['params'][$i]['drop'] = true;
                continue;
            }

            // Sub-Parameter-Tiefe prüfen (leere [] zählen nicht als Hierarchie-Ebene)
            $allowed = (int)($def['params'][$static]['subparam'] ?? 0);
            $depth   = count(array_filter($p['subs'], fn($sub) => empty($sub['empty'])));
            if ($depth > $allowed) {
                $errors[] = "Line $line: Parameter '$static' of command '$upper' allows a "
                    . "sub-parameter depth of $allowed, $depth given.";
            }
        }

        // Pflichtparameter
        foreach (($def['params'] ?? []) as $pname => $pdef) {
            if (!empty($pdef['mandatory']) && !isset($seen[$pname])) {
                $errors[] = 'Line ' . ($cmd['baseLine'] + 1)
                    . ": Missing mandatory parameter '$pname' for command '$upper'.";
            }
        }

        // oneof: mindestens einer dieser Parameter muss angegeben sein
        if (!empty($def['oneof'])) {
            $list  = array_map('trim', explode(',', (string)$def['oneof']));
            $found = false;
            foreach ($list as $pn) {
                if (isset($seen[$pn])) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $errors[] = 'Line ' . ($cmd['baseLine'] + 1) . ": Command '$upper' requires at "
                    . 'least one of these parameters: ' . implode(', ', $list) . '.';
            }
        }
    }

    /* =====================================================================
     * Code-Erzeugung
     * ===================================================================== */

    /**
     * Erzeugt den PHP-Code eines Befehls. Die Anzahl der Zeilenumbrüche
     * entspricht exakt dem ursprünglichen Befehl (Zeilennummern bleiben erhalten).
     */
    private function generateCmsCommand(array $cmd, array &$errors): string
    {
        $def  = $cmd['def'];
        $name = $cmd['name'];

        // Gruppierung: identischer Schlüsselpfad mehrfach verwendet -> automatisches []
        $paths = [];
        $pathOf = [];
        foreach ($cmd['params'] as $i => $p) {
            if (!empty($p['drop'])) {
                continue;
            }
            $path = $this->paramPath($p);
            $pathOf[$i] = $path;
            $paths[$path] = ($paths[$path] ?? 0) + 1;
        }

        $frags = [];
        $frags[] = [
            'line' => 0,
            'text' => '<?php /* --- CMS:' . strtoupper($name) . ' --- */ $_a = [];',
        ];

        foreach ($cmd['params'] as $i => $p) {
            if (!empty($p['drop'])) {
                continue;
            }

            // linke Seite: $_a["key"]['sub']...
            if ($p['standalone']) {
                $left = '$_a["' . $p['mappedKey'] . '"]';
            } else {
                $left = '$_a[' . $this->normToken($p['key']) . ']';
            }
            $hasEmpty = false;
            foreach ($p['subs'] as $sub) {
                if (!empty($sub['empty'])) {
                    $hasEmpty = true;
                    continue;
                }
                $left .= '[' . $this->normToken($sub) . ']';
            }
            // mehrfach verwendete Pfade oder explizites [] -> Array-Anfügung
            if ($hasEmpty || $paths[$pathOf[$i]] > 1) {
                $left .= '[]';
            }

            if ($p['standalone']) {
                // Wert ist das Token selbst
                $frags[] = [
                    'line' => $p['key']['line'],
                    'text' => $left . ' = ' . $this->normToken($p['key']) . ';',
                ];
            } else {
                // Vergleichsoperator: dem Wert in Zweizeichen-Form voranstellen
                // (siehe operatorPrefix()), sonst Zuweisung wie gehabt
                $prefix = $p['op'] === '=' ? null : $this->operatorPrefix($p['op']);
                // 'php_value': eine einzelne Variable geht typtreu durch (Array, int …)
                $static = $p['staticKey'] ?? null;
                $value = ($prefix === null && $static !== null && !empty($def['params'][$static]['php_value']))
                    ? $this->phpValueToken($p['value'])
                    : null;
                $frags[] = ['line' => $p['key']['line'],   'text' => $left];
                $frags[] = ['line' => $p['opLine'],        'text' => $prefix === null ? '=' : "= '$prefix' ."];
                $frags[] = ['line' => $p['value']['line'], 'text' => ($value ?? $this->normToken($p['value'])) . ';'];
            }
        }

        // Abschluss: php_prefix + Befehlsaufruf + php_suffix + schließendes PHP-Tag (letzte Zeile)
        // 'buffer_set': die gesamte Ausgabe des Befehls (samt eingebundenem Template)
        // landet mit set=… in $set statt auf der Seite - Cms::setBufferStart()/End()
        $bufferSet = !empty($def['buffer_set']);
        $tail = $bufferSet ? ['$this->cms->setBufferStart((string)($_a["set"] ?? ""));'] : [];
        $tail = array_merge($tail, $this->loadPhpSnippets($def['php_prefix'] ?? null, $name, $errors));

        if (empty($def['no_action'])) {
            // syntax of $def['method']: 'classname.methodname' or 'methodname'
            $classMethod = $def['method'] ?? 'cms.' . $name;
            $parts = explode('.', $classMethod, 2);
            $class  = $parts[0] === $classMethod ? 'cms' : $parts[0];
            $method = $parts[1] ?? $classMethod;

            $tail[] = '$_r = $this->' . $class . '->' . $method . '($_a);';
        }

        foreach ($this->loadPhpSnippets($def['php_suffix'] ?? null, $name, $errors) as $snip) {
            $tail[] = $snip;
        }
        if ($bufferSet) $tail[] = '$this->cms->setBufferEnd();';

        $tail[]  = '?>';
        $frags[] = ['line' => $cmd['lines'], 'text' => implode(' ', $tail)];

        return $this->emitFragments($frags);
    }

    /**
     * Vergleichsoperator zwischen Schlüssel und Wert -> Präfix vor dem Wert.
     *
     * Immer zwei Zeichen ('<' -> '<<', '>' -> '>>', '!' -> '!='; laut
     * Cms::extractOperator() gleichbedeutend): extractOperator() nimmt genau dieses
     * Präfix ab, der Rest ist der reine Wert. key < ">2" vergleicht also mit ">2",
     * und key < "=2" wird nicht zu "<=2".
     */
    private function operatorPrefix(string $op): string
    {
        return match ($op) {
            '<'     => '<<',
            '>'     => '>>',
            '!'     => '!=',
            default => $op,
        };
    }

    /**
     * Normalisierter Schlüsselpfad zur Erkennung mehrfach verwendeter Parameter.
     */
    private function paramPath(array $p): string
    {
        if ($p['standalone']) {
            return '"' . $p['mappedKey'] . '"';
        }
        $path = $this->normToken($p['key']);
        foreach ($p['subs'] as $sub) {
            if (empty($sub['empty'])) {
                $path .= '|' . $this->normToken($sub);
            }
        }
        return $path;
    }

    /**
     * Token -> PHP-Ausdruck: bereits gequotete Tokens bleiben unverändert,
     * alles andere wird in doppelte Anführungszeichen gesetzt
     * (PHP-Interpolation von {$...} und $var bleibt dadurch erhalten).
     */
    private function normToken(array $tok): string
    {
        if ($tok['quote'] !== null) {
            return $tok['text'];
        }
        return '"' . str_replace('"', '\"', $tok['text']) . '"';
    }

    /**
     * Wert eines Parameters mit 'php_value' (commands.php): ein ungequotetes Token,
     * das nur aus einer Variablen besteht - $params, $cms['id'], $set["a"]["b"],
     * $obj->name oder dasselbe in {…} -, wird als PHP-Ausdruck übernommen statt in
     * einen String interpoliert. Alles andere: null (übliche Behandlung, normToken()).
     */
    private function phpValueToken(array $tok): ?string
    {
        if ($tok['quote'] !== null) {
            return null;
        }
        $text = $tok['text'];
        if (str_starts_with($text, '{$') && str_ends_with($text, '}')) {
            $text = substr($text, 1, -1);
        }
        $index = '\[(?:\d+|\'[^\'\\\\]*\'|"[^"\\\\$]*"|\$[A-Za-z_]\w*)\]';
        $pattern = '/^\$[A-Za-z_]\w*(?:' . $index . '|->[A-Za-z_]\w*)*$/';
        return preg_match($pattern, $text) ? $text : null;
    }

    /**
     * Setzt Fragmente zeilentreu zusammen: Fragmente derselben Zeile werden
     * mit Leerzeichen verbunden, Zeilensprünge exakt mit Umbrüchen aufgefüllt.
     */
    private function emitFragments(array $frags): string
    {
        $out   = '';
        $cur   = 0;
        $first = true;

        foreach ($frags as $f) {
            if ($f['line'] > $cur) {
                $out .= str_repeat("\n", $f['line'] - $cur);
                $cur  = $f['line'];
            } elseif (!$first) {
                $out .= ' ';
            }
            $out .= $f['text'];
            $cur += substr_count($f['text'], "\n");
            $first = false;
        }

        return $out;
    }

    /**
     * Lädt PHP-Schnipsel aus ./php/<name>.php (php_prefix / php_suffix).
     * $spec darf String, Array von Strings oder null sein.
     */
    private function loadPhpSnippets($spec, string $cmdName, array &$errors): array
    {
        if ($spec === null) {
            return [];
        }

        $out = [];
        foreach ((array)$spec as $snip) {
            $file = __DIR__ . '/php/' . $snip . '.php';
            if (!is_file($file)) {
                $errors[] = "PHP snippet '$snip' for command 'CMS:" . strtoupper($cmdName)
                    . "' not found ($file).";
                continue;
            }
            $src = (string)file_get_contents($file);
            // öffnendes/schließendes PHP-Tag entfernen, da inline injiziert wird
            $src = preg_replace('/^\s*<\?(php)?\s*/', '', $src);
            $src = preg_replace('/\s*\?>\s*$/', '', $src);
            // Zeilenumbrüche in Leerzeichen wandeln
            $src = preg_replace('/[\r\n]+/', ' ', $src);
            $src = trim($src);
            if ($src !== '') {
                $out[] = $src;
            }
        }

        return $out;
    }

    /* =====================================================================
     * styledCode
     * ===================================================================== */

    /**
     * Einen Textabschnitt für die Editor-Ansicht vormerken.
     *
     * Grenzt der neue Abschnitt unmittelbar an den vorherigen Textabschnitt, werden
     * beide zusammengefasst - sonst entstünde für jedes einzeln übernommene Zeichen
     * (etwa eine Klammer, die kein Befehl war) ein eigenes Segment.
     */
    private function addTextSegment(array &$segments, int $start, int $end): void
    {
        if ($end <= $start) {
            return;
        }
        $last = count($segments) - 1;
        // benachbarte Text-Segmente zusammenfassen
        if ($last >= 0 && $segments[$last]['type'] === 'text' && $segments[$last]['end'] === $start) {
            $segments[$last]['end'] = $end;
            return;
        }
        $segments[] = ['type' => 'text', 'start' => $start, 'end' => $end];
    }

    /**
     * Die eingefärbte Editor-Ansicht aus den gesammelten Segmenten bauen.
     *
     * Textabschnitte werden maskiert (rohe PHP-Blöcke darin bekommen eine eigene
     * Farbe), Befehle in ein <span class="c9-command"> gefasst und innen weiter
     * ausgezeichnet. Das Ergebnis landet in template.styled_code und wird im Backend
     * hinter dem Eingabefeld angezeigt - der Editor ist also kein Javascript-Highlighter,
     * die Einfärbung entsteht beim Speichern auf dem Server.
     */
    private function renderStyledCode(string $code, array $segments, bool $links = true): string
    {
        $out = '';
        foreach ($segments as $seg) {
            if ($seg['type'] === 'text') {
                $out .= $this->styleRawPhp(substr($code, $seg['start'], $seg['end'] - $seg['start']));
            } else {
                $out .= '<span class="c9-command">'
                    . $this->styleCommandInner($code, $seg['cmd'], $links)
                    . '</span>';
            }
        }
        return $out;
    }

    /**
     * Text-Segment: htmlspecialchars, dabei rohe <?php ... ?>-Blöcke einfärben.
     */
    private function styleRawPhp(string $text): string
    {
        $out = '';
        $pos = 0;

        if (preg_match_all('/<\?(?:php\b|=)?.*?(?:\?>|\z)/s', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$blk, $off]) {
                $out .= htmlspecialchars(substr($text, $pos, $off - $pos));
                $out .= '<span class="c9-php">' . htmlspecialchars($blk) . '</span>';
                $pos  = $off + strlen($blk);
            }
        }

        $out .= htmlspecialchars(substr($text, $pos));
        return $out;
    }

    /**
     * Befehls-Inneres: PHP-Anteile einfärben, Template-Verweise verlinken.
     *
     * Verlinkt wird jeder Parameter, der laut commands.php 'ref' => 'template'
     * trägt - mit Schlüssel (template=header, resulttemplate="liste") ebenso wie
     * der schlüssellose 'do'-Parameter ((CMS:INCLUDE header), (CMS:SELECT liste …)).
     * Der Wert darf Id, Name oder "bereich.name" sein; aufgelöst wird er erst beim
     * Klick in TemplateBackend::info(). Werte mit PHP-Anteil bleiben unverlinkt;
     * $links = false (styleCode()) verlinkt gar nichts.
     */
    private function styleCommandInner(string $code, array $cmd, bool $links = true): string
    {
        $ranges = [];
        $paramDefs = (array)($cmd['def']['params'] ?? []);
        $doParam = (string)($cmd['def']['do'] ?? '');

        foreach ($cmd['params'] as $p) {
            // PHP-Anteile aus allen Tokens sammeln
            foreach ($p['key']['php'] as $span) {
                $ranges[] = ['type' => 'php', 's' => $span[0], 'e' => $span[1]];
            }
            foreach ($p['subs'] as $sub) {
                foreach ($sub['php'] ?? [] as $span) {
                    $ranges[] = ['type' => 'php', 's' => $span[0], 'e' => $span[1]];
                }
            }
            if ($p['value'] !== null) {
                foreach ($p['value']['php'] as $span) {
                    $ranges[] = ['type' => 'php', 's' => $span[0], 'e' => $span[1]];
                }
            }

            // Verlinkung: Parameter mit 'ref' => 'template', Wert ohne PHP-Code.
            // Beim schlüssellosen Wert ist das Token selbst der Wert (do-Parameter).
            if (!$links || !empty($p['drop'])) {
                continue;
            }
            $pname = $p['standalone'] ? $doParam : ($p['staticKey'] ?? null);
            $token = $p['standalone'] ? $p['key'] : $p['value'];
            if ($pname === null || $pname === '' || $token === null
                || ($paramDefs[$pname]['ref'] ?? null) !== 'template' || !empty($token['php'])) {
                continue;
            }
            $raw = $token['quote'] !== null ? substr($token['text'], 1, -1) : $token['text'];
            $id  = preg_replace('/[^0-9a-zA-Z_.]/', '', $raw);
            if ($id === '') {
                continue;
            }
            // Referenz "[bereich.]name" oder Id - der Bereich des verweisenden
            // Templates geht als area_id mit, damit TemplateBackend::info() einen
            // bloßen Namen, den es in mehreren Bereichen gibt, zuerst dort sucht
            $ranges[] = [
                'type' => 'link',
                's'    => $token['start'],
                'e'    => $token['start'] + strlen($token['text']),
                'id'   => $id,
            ];
        }

        usort($ranges, fn($a, $b) => $a['s'] <=> $b['s']);

        $out = '';
        $pos = $cmd['start'];

        foreach ($ranges as $r) {
            if ($r['s'] < $pos) {
                continue; // Überschneidungen sicherheitshalber ignorieren
            }
            $out  .= htmlspecialchars(substr($code, $pos, $r['s'] - $pos));
            $chunk = htmlspecialchars(substr($code, $r['s'], $r['e'] - $r['s']));
            if ($r['type'] === 'php') {
                $out .= '<span class="c9-php">' . $chunk . '</span>';
            } else {
                // id ist oben auf [0-9a-zA-Z_.] bereinigt: Id oder "[bereich.]name" -
                // kein (int), sonst würde aus einem Namen 0 (= Startseite des Moduls)
                $out .= '<a href="#"' . $this->container->get(\Camindo\Core\Backend::class)->on('js', "cApp.layers.replace('Template/template_info.php?id="
                    . $r['id'] . '&area_id=' . (int)$this->parseAreaId . "')") . '>' . $chunk . '</a>';
            }
            $pos = $r['e'];
        }

        $out .= htmlspecialchars(substr($code, $pos, $cmd['end'] - $pos));
        return $out;
    }
}
