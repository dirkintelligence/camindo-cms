<?php

namespace Camindo\Core\Modules\Template;

/**
 * Translates CMS commands of the form
 *
 *     (cms:befehls_name parameter1 key=value key['sub'][] = "value" key[sub] >= 5 ...)
 *
 * into PHP code.
 *
 * STRUCTURE
 * --------------------
 * A template is normal text (HTML, gladly also raw PHP code), in which CMS commands
 * stand. The parser is therefore not a compiler in the strict sense: it leaves everything
 * unchanged that is not a command, and only replaces the commands.
 *
 * The flow in parse() is accordingly simple - search for the next "(cms:",
 * take over the text before it, read the command, create PHP for it, continue after the command.
 * Everything else are helper methods for this, in four groups:
 *
 *     Parsing       parseCmsCommand(), readParam(), readToken(), readSub()
 *                   and the scanner helpers skipWs(), consumeQuoted(), consumeBraces()
 *     Validation    validateCmsCommand() - against the definition from commands.php
 *     Generation    generateCmsCommand(), paramPath(), normToken(), phpValueToken(),
 *                   emitFragments()
 *     Display       renderStyledCode(), styleRawPhp(), styleCommandInner();
 *                   styleCode() only provides the display (code examples of the manuals)
 *
 * THE COMMAND DEFINITIONS ARE ELSEWHERE
 * --------------------
 * commands.php provides for each command which parameters are allowed and which PHP code
 * results from it. The parser itself knows not a single command - a new command
 * means an entry there, no change here. Add-on modules bring their own
 * commands in a separate file (settings.php['commands'], see loadCommands()).
 *
 * LINE NUMBERS ARE KEPT
 * --------------------
 * This runs through the whole file and explains some clumsiness. The generated
 * PHP code has the same line number as the CMS source code at every point - a
 * multi-line command thus becomes as many lines of PHP. Only this way does a
 * PHP error message from var/php/{area_id}/{name}.php point to the right line in the editor.
 * Therefore the scanner keeps a relative line count in $s['line'], and therefore
 * emitFragments() assembles the code line by line instead of simply concatenating it.
 *
 * ERRORS DO NOT ABORT
 * --------------------
 * Unknown commands and invalid parameters are reported and skipped resp. taken over as
 * raw text; parse() still returns a result. A half-finished template
 * can thus be saved intermediately (see Template::hookOnSave()).
 *
 * THREE RETURN VALUES
 * --------------------
 *     phpCode     the compiled template
 *     styledCode  the same source code as HTML with syntax coloring for the editor
 *     errors      messages with line numbers
 *
 * @see Camindo\Core\Modules\Template\Template          uses the trait in hookOnSave()
 * @see Camindo\Core\Modules\Template\TemplateBackend   editor and error display
 * @see developer docs, chapter 06 (Template compiler)  command reference
 */
trait TemplateParserTrait
{
    // Command definitions from commands.php, loaded at the start of parse().
    protected array $commands = [];
    // Area of the template currently being translated (for the references in the styledCode)
    protected int $parseAreaId = 0;

    /**
     * Assemble command definitions: those of the core (commands.php) plus those of the
     * active add-on modules.
     *
     * A module names in install/settings.php['commands'] a file relative to
     * its directory (e.g. 'commands.php') that returns an array with the same structure as
     * the core file. The command names sensibly carry the
     * module name as prefix ('pdf_page' -> CMS:PDF_PAGE); a name that the core
     * already assigns stays with the core. The objects named in 'method'
     * ('pdf.cmsPdfPage' -> $this->pdf) are provided by the module via settings.php['services'],
     * see Page::__construct().
     *
     * The module list comes from Cms::$modules (runtime list of the ComponentManager).
     * Without a Cms instance - the trait also runs on its own, for example in a test script -
     * there are only the core commands.
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
     * Translate CMS source code.
     *
     * Runs once linearly through the text (see trait comment). What is not a valid
     * command passes unchanged into the output - also a "(cms:" without a
     * closing parenthesis, though then with an error message. The run itself
     * is in scan().
     *
     * @param string $code     source code in CMS syntax
     * @param int    $area_id  content area of the template - some commands resolve
     *                         field names with it
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

        // Script policy of the frontend (developer docs, chapter 14 (Security: web)): notes on scripts without
        // (CMS:NONCE) and inline handlers that it would block
        array_push($errors, ...$this->inlineHandlerWarnings($code));

        return [
            'phpCode'    => $php,     // string
            'styledCode' => $styled,  // string
            'errors'     => $errors,  // array
        ];
    }

    /**
     * Only the colored view, without compiling - for code examples outside
     * the template editor (manuals: ```cms blocks, Docs::render()).
     *
     * The same HTML as 'styledCode' from parse() (escaped text, <span class="c9-command">,
     * <span class="c9-php">), but without template links - an example refers to
     * no existing template. Unknown or incomplete commands remain text,
     * messages are omitted.
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
     * The actual run of parse() and styleCode(): separate text and commands,
     * and with $compile = true also create the PHP code.
     *
     * On the side the $segments are created: a list of text and command sections,
     * from which renderStyledCode() builds the colored view at the end. It is
     * carried along here, because only at this point is it known which characters belong to a
     * command and which do not.
     *
     * @return array  [string $php, array $segments]
     */
    private function scan(string $code, array &$errors, bool $compile): array
    {
        $php      = '';
        $segments = []; // segments for styledCode: 'text' | 'command'

        $len = strlen($code);
        $pos = 0;

        while ($pos < $len) {
            $hit = stripos($code, '(cms:', $pos);

            if ($hit === false) {
                // Rest is plain text
                $this->addTextSegment($segments, $pos, $len);
                $php .= substr($code, $pos);
                break;
            }

            // Take over the text before the command unchanged
            if ($hit > $pos) {
                $this->addTextSegment($segments, $pos, $hit);
                $php .= substr($code, $pos, $hit - $pos);
            }

            $cmd = $this->parseCmsCommand($code, $hit, $errors);

            if ($cmd === null) {
                // structurally not a valid command: take over '(', keep searching after it
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
                // unknown command: leave the source code unchanged (error has been reported)
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
     * Notes for the script policy of the frontend (developer docs, chapter 14 (Security: web)) in the HTML part of the
     * source code (token T_INLINE_HTML - PHP code stays out):
     *
     *   - <script> without nonce: a script in the template needs <script(CMS:NONCE)>,
     *     otherwise it is blocked by an enforced policy. Exempt are scripts with a
     *     relative src (own files, 'self') and data blocks (type="application/
     *     ld+json", "application/json", "text/template" …)
     *   - inline event handlers (onclick="…") and javascript: links: the policy
     *     always blocks these, even with a nonce
     *
     * The template programmer sets the nonce themselves (CMS:NONCE); the compiler
     * changes nothing. Only notes (error_text), saving is not prevented by them;
     * at most ten.
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
     * References (export/import of template sets)
     * ===================================================================== */

    /**
     * Rewrite referencing parameter values - without compiling.
     *
     * Runs with the same scanner as parse() over all known commands and calls
     * for each parameter that carries a reference according to commands.php ('ref':
     * template | area | record), the mapping:
     *
     *     $map(string $ref, string $value): ?string    null = leave unchanged
     *
     * For 'record' each number in the value is passed individually (comma lists,
     * expressions like "123 OR >1000"), for 'template' and 'area' the whole value.
     * Quotation marks remain, the call sees only the content. Values with a
     * PHP part ({$...}, $var) are skipped - they are only fixed at runtime.
     * Unknown commands and structurally unreadable places remain as they are.
     *
     * The export thus marks ids and names (<!-- CMS:REPLACE ... -->,
     * RecordExport), the import resolves the markers again (RecordImport).
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
                // Value without key: applies to the 'do' parameter
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
                    // Type depends on the first sub-key (filter[area_id] ...)
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
        // Replace from the back so that the offsets stay valid
        usort($spans, fn($a, $b) => $b[0] <=> $a[0]);
        foreach ($spans as [$start, $end, $replacement]) {
            $code = substr($code, 0, $start) . $replacement . substr($code, $end);
        }
        return $code;
    }

    /* =====================================================================
     * Parsing a single command
     * ===================================================================== */

    /**
     * Reads a command starting at position $start ("(cms:...").
     * Returns null if the command is structurally unreadable
     * (e.g. missing closing parenthesis).
     *
     * Reading uses a scanner state $s (source code, length, position and the
     * relative line within the command), which is passed by reference through all read* and consume* helpers -
     * each of them advances the position. The carried line count is needed so that error messages point to the right line and
     * line and the generated code keeps the line count.
     *
     * An unknown command name is not a structural error: it is reported, the
     * result gets 'known' => false, and the caller then takes over the source code
     * unchanged.
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

        // Scanner state: position + relative line within the command
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
            'lines'    => $s['line'],   // relative line of the ')'
            'params'   => $params,
            'known'    => $known,
            'def'      => $this->commands[$name] ?? [],
        ];
    }

    /**
     * Reads a parameter: key[sub][...] = value  |  key = value  |  standalone
     *
     * Instead of '=' a comparison operator may stand between key and value
     * (filter[preis] >= 100, filter[titel] != "x"), see readOperator(). It ends up
     * in 'op'; generateCmsCommand() puts it in front of the value.
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

        // Sub-keys: [..][..][] directly after the key
        $subs = [];
        while ($s['pos'] < $s['len'] && $s['code'][$s['pos']] === '[') {
            $sub = $this->readSub($s, $baseLine, $cmdName, $errors);
            if ($sub === null) {
                return null;
            }
            $subs[] = $sub;
        }

        // optional '=' or comparison operator (also across line breaks)
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
     * Reads the operator between key and value: '=' (assignment) or one of the
     * comparison operators ==, !=, !!, <=, >=, <<, >>, <, >, ! - the longest match.
     * What follows belongs to the value: key=>5 is '=' with the value ">5" (as
     * before), key==x on the other hand is the operator '=='.
     *
     * @return ?string  null = no operator (parameter without value)
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
     * Reads a token (key, sub-key or value).
     * Returns: ['text','quote','start','line','php'] or null.
     * 'php' contains absolute offsets of recognized PHP parts ({$...} resp. $var).
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

        // Fully quoted token
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
                // inside single quotes PHP does not interpolate
                'php'   => $c === '"' ? $this->findPhpSpans($text, $start) : [],
            ];
        }

        // Bare token: ends at whitespace resp. mode-dependent characters,
        // but may contain {..} blocks and embedded strings
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
            // A key also ends before a comparison operator (key<5, key!=x) -
            // not at the start of the token (value without key like !x) and not in
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
     * Reads a sub-key from '[' to ']' (also empty: []).
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
     * Skips whitespace and counts line breaks along the way.
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
     * Consumes a quoted string including quotation marks ("..." or '...'),
     * including backslash escapes and line breaks.
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
     * Consumes a balanced {..} block (quote-safe).
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
     * Finds PHP parts ({$...} blocks and $variables) within a token text.
     * Returns absolute offsets [start, end) in the overall code.
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
     * Check the parameters of a command against its definition from commands.php.
     *
     * Checked is whether the key exists at all, whether it may stand here, whether it
     * occurs only once and whether it accepts a comparison operator instead of '='
     * ('operator' in commands.php). "do" is the special case: the parameter without key,
     * which some commands allow as the first argument - at most one per command.
     *
     * Faulty parameters are not removed but marked with 'drop' => true
     * marked; generateCmsCommand() then skips them. This keeps indices and
     * line counting unchanged.
     *
     * $cmd comes in by reference, because exactly this marking is set.
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

            // Determine the static key name (only checkable without PHP parts)
            $keyTok = $p['key'];
            $static = null;
            if (empty($keyTok['php'])) {
                $static = $keyTok['quote'] !== null
                    ? substr($keyTok['text'], 1, -1)
                    : $keyTok['text'];
            }
            $cmd['params'][$i]['staticKey'] = $static;

            if ($static === null) {
                continue; // dynamic key: not validatable
            }

            if (!isset($def['params'][$static])) {
                $errors[] = "Line $line: Unknown parameter '$static' for command '$upper'.";
                continue;
            }

            $seen[$static] = true;

            // comparison operator instead of '=' only for parameters with 'operator' (filter)
            if ($p['op'] !== '=' && empty($def['params'][$static]['operator'])) {
                $errors[] = "Line $line: Parameter '$static' of command '$upper' does not accept "
                    . "the comparison operator '{$p['op']}' - use '='.";
                $cmd['params'][$i]['drop'] = true;
                continue;
            }

            // Check sub-parameter depth (empty [] do not count as a hierarchy level)
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

        // oneof: at least one of these parameters must be given
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
     * Creates the PHP code of a command. The number of line breaks
     * matches the original command exactly (line numbers are kept).
     */
    private function generateCmsCommand(array $cmd, array &$errors): string
    {
        $def  = $cmd['def'];
        $name = $cmd['name'];

        // Grouping: identical key path used several times -> automatic []
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
            // repeatedly used paths or explicit [] -> array append
            if ($hasEmpty || $paths[$pathOf[$i]] > 1) {
                $left .= '[]';
            }

            if ($p['standalone']) {
                // Value is the token itself
                $frags[] = [
                    'line' => $p['key']['line'],
                    'text' => $left . ' = ' . $this->normToken($p['key']) . ';',
                ];
            } else {
                // Comparison operator: prefix the value in two-character form
                // (see operatorPrefix()), otherwise assignment as before
                $prefix = $p['op'] === '=' ? null : $this->operatorPrefix($p['op']);
                // 'php_value': a single variable passes through with its type (array, int …)
                $static = $p['staticKey'] ?? null;
                $value = ($prefix === null && $static !== null && !empty($def['params'][$static]['php_value']))
                    ? $this->phpValueToken($p['value'])
                    : null;
                $frags[] = ['line' => $p['key']['line'],   'text' => $left];
                $frags[] = ['line' => $p['opLine'],        'text' => $prefix === null ? '=' : "= '$prefix' ."];
                $frags[] = ['line' => $p['value']['line'], 'text' => ($value ?? $this->normToken($p['value'])) . ';'];
            }
        }

        // Ending: php_prefix + command call + php_suffix + closing PHP tag (last line)
        // 'buffer_set': the entire output of the command (including the included template)
        // ends up with set=… in $set instead of on the page - Cms::setBufferStart()/End()
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
     * Comparison operator between key and value -> prefix before the value.
     *
     * Immer zwei Zeichen ('<' -> '<<', '>' -> '>>', '!' -> '!='; laut
     * Cms::extractOperator() equivalent): extractOperator() takes exactly this
     * prefix off, the rest is the pure value. key < ">2" thus compares with ">2",
     * and key < "=2" does not become "<=2".
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
     * Normalized key path for detecting repeatedly used parameters.
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
     * Token -> PHP expression: already quoted tokens remain unchanged,
     * everything else is enclosed in double quotation marks
     * (PHP interpolation of {$...} and $var is thereby preserved).
     */
    private function normToken(array $tok): string
    {
        if ($tok['quote'] !== null) {
            return $tok['text'];
        }
        return '"' . str_replace('"', '\"', $tok['text']) . '"';
    }

    /**
     * Value of a parameter with 'php_value' (commands.php): an unquoted token
     * that consists only of a variable - $params, $cms['id'], $set["a"]["b"],
     * $obj->name or the same in {…} -, is taken over as a PHP expression instead of being
     * interpolated into a string. Anything else: null (usual handling, normToken()).
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
     * Assembles fragments line-faithfully: fragments of the same line are
     * joined with spaces, line jumps padded exactly with line breaks.
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
     * Loads PHP snippets from ./php/<name>.php (php_prefix / php_suffix).
     * $spec may be a string, an array of strings or null.
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
            // remove the opening/closing PHP tag, since it is injected inline
            $src = preg_replace('/^\s*<\?(php)?\s*/', '', $src);
            $src = preg_replace('/\s*\?>\s*$/', '', $src);
            // convert line breaks into spaces
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
     * Note a text section for the editor view.
     *
     * If the new section directly adjoins the previous text section, both are
     * merged - otherwise every individually taken-over character
     * (such as a parenthesis that was not a command) would get its own segment.
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
     * Build the colored editor view from the collected segments.
     *
     * Text sections are escaped (raw PHP blocks in them get their own
     * color), commands are wrapped in a <span class="c9-command"> and further
     * marked up inside. The result ends up in template.styled_code and is shown in the backend
     * after the input field - the editor is therefore not a JavaScript highlighter,
     * the coloring is created on the server when saving.
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
     * Text segment: htmlspecialchars, coloring raw <?php ... ?> blocks along the way.
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
     * Command interior: color PHP parts, link template references.
     *
     * Every parameter that carries 'ref' => 'template' according to commands.php is linked
     * - with key (template=header, resulttemplate="liste") as well as
     * the keyless 'do' parameter ((CMS:INCLUDE header), (CMS:SELECT liste …)).
     * The value may be an id, name or "area.name"; it is resolved only on
     * click in TemplateBackend::info(); parameters marked 'list' add kind=list
     * (a missing template is then created as a list template). Values with a PHP part remain unlinked;
     * $links = false (styleCode()) verlinkt gar nichts.
     */
    private function styleCommandInner(string $code, array $cmd, bool $links = true): string
    {
        $ranges = [];
        $paramDefs = (array)($cmd['def']['params'] ?? []);
        $doParam = (string)($cmd['def']['do'] ?? '');

        foreach ($cmd['params'] as $p) {
            // Collect PHP parts from all tokens
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

            // Linking: parameter with 'ref' => 'template', value without PHP code.
            // For a value without key the token itself is the value (do parameter).
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
            // Reference "[area.]name" or id - the area of the referencing
            // template is passed along as area_id, so that TemplateBackend::info() can
            // look up a bare name that exists in several areas first there
            $ranges[] = [
                'type' => 'link',
                's'    => $token['start'],
                'e'    => $token['start'] + strlen($token['text']),
                'id'   => $id,
                'list' => !empty($paramDefs[$pname]['list']),
            ];
        }

        usort($ranges, fn($a, $b) => $a['s'] <=> $b['s']);

        $out = '';
        $pos = $cmd['start'];

        foreach ($ranges as $r) {
            if ($r['s'] < $pos) {
                continue; // Ignore overlaps to be safe
            }
            $out  .= htmlspecialchars(substr($code, $pos, $r['s'] - $pos));
            $chunk = htmlspecialchars(substr($code, $r['s'], $r['e'] - $r['s']));
            if ($r['type'] === 'php') {
                $out .= '<span class="c9-php">' . $chunk . '</span>';
            } else {
                // id is sanitized above to [0-9a-zA-Z_.]: id or "[area.]name" -
                // not (int), otherwise a name would become 0 (= start page of the module)
                $out .= '<a href="#"' . $this->container->get(\Camindo\Core\Backend::class)->on('js', "cApp.layers.replace('Template/template_info.php?id="
                    . $r['id'] . '&area_id=' . (int)$this->parseAreaId . ($r['list'] ? '&kind=list' : '') . "')") . '>' . $chunk . '</a>';
            }
            $pos = $r['e'];
        }

        $out .= htmlspecialchars(substr($code, $pos, $cmd['end'] - $pos));
        return $out;
    }
}
