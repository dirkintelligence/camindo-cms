<?php
/**
 * check_template_params.php - self-test for parameters of the output commands
 *
 *     php Core/cron/check_template_params.php
 *
 * Calls the command methods with the same parameter arrays that the parser creates from
 * the template (TemplateParserTrait), and compares the output:
 *   - 'if' / 'replace': digit keys (if[1]="x") beside the short form
 *     (if="1?x", if[]="1?x") - PHP turns "1" into an int key (Cms::_pairs())
 *   - CMS:NUMBER 'decimals' and the empty check on the numeric value (0 / "0.00")
 *   - parser note for filter[depth] without filter[parent_id]
 *   - resulttemplate of CMS:SELECT: mandatory unless hits=false (parser and runtime)
 *   - wrong arguments never stop the page: a parameter of one value given several times
 *     (every command, parser), wrong template names, conditions and charsets (runtime);
 *     CMS:USER_PROTECT stays closed without a usable error template
 * Exit code 1 on deviations.
 */
use Camindo\Core\Cms;
use Camindo\Core\Database;
use Camindo\Core\Modules\Template\Template;

// Reachable only on the command line, not via the web server
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Config::env() removes secrets such as CAMINDO_DB_PASS from the environment during bootstrap;
// the child processes of the runtime cases below need them
$childEnv = getenv();

// Load bootstrap
require_once dirname(__DIR__, 2) . '/load_camindo.php';

$cms = $app->get(Cms::class);

// Child process of the runtime cases below: a query that is certain to find nothing
$filter = ['record_id' => ['0']];
switch ($argv[1] ?? '') {
    case 'select-none':
        $cms->cmsSelect(['filter' => $filter]);
        exit("no error\n");
    case 'select-empty':
        $cms->cmsSelect(['resulttemplate' => '', 'filter' => $filter]);
        exit("no error\n");
    case 'protect-unknown':
        $app->get(\Camindo\Core\Modules\Record\RecordUser::class)->cmsProtect(['errortemplate' => 'no_such_template']);
        exit("page continued\n");
    case 'select-count':
        $file = $cms->cmsSelect(['filter' => $filter, 'hits' => 'false']);
        $cms->selectCountEnd();
        exit('file=' . $file . ' count=' . $app->get(Database::class)->num_rows_unlimited . "\n");
}

// Capture the output of a command
$run = function (string $method, array $p) use ($cms): string {
    ob_start();
    $cms->$method($p);
    return (string)ob_get_clean();
};

// [description, method, parameters (as created by the parser), expected output]
$cases = [
    // if: digit keys, short form, list form, text key, else
    ['if[0]=online (Wert 0)',          'cmsText', ['useval' => '0', 'if' => [0 => 'online', 1 => 'gesperrt']], 'online'],
    ['if[1]=gesperrt (Wert 1)',        'cmsText', ['useval' => '1', 'if' => [0 => 'online', 1 => 'gesperrt']], 'gesperrt'],
    ['if[1] allein',                   'cmsText', ['useval' => '1', 'if' => [1 => 'on'], 'else' => 'off'], 'on'],
    ['if[1] allein, anderer Wert',     'cmsText', ['useval' => '0', 'if' => [1 => 'on'], 'else' => 'off'], 'off'],
    ['if="1?on" (Kurzform)',           'cmsText', ['useval' => '1', 'if' => '1?on', 'else' => 'off'], 'on'],
    ['if[]="1?on" if[]="2?zwei"',      'cmsText', ['useval' => '2', 'if' => [0 => '1?on', 1 => '2?zwei']], 'zwei'],
    ['if[de_DE]=de (Text-Schlüssel)',  'cmsText', ['useval' => 'de_DE', 'if' => ['en_US' => 'us', 'de_DE' => 'de']], 'de'],
    ['if ohne Treffer, ohne else',     'cmsText', ['useval' => '7', 'if' => [1 => 'on']], '7'],
    // entitydecode: only from U+0100 up, escaped text stays escaped
    ['entitydecode Latin-1 bleibt',    'cmsText', ['useval' => '&lt;b&gt; &amp; &quot; &#39; &#60; &#x3C; &nbsp; &auml;', 'lbrconvert' => 'false'],
        '&lt;b&gt; &amp; &quot; &#39; &#60; &#x3C; &nbsp; &auml;'],
    ['entitydecode ab U+0100',         'cmsText', ['useval' => '&euro; &#8211; &#x2013; &Scaron; &#256;', 'lbrconvert' => 'false'], "€ – – Š \u{0100}"],
    ['entitydecode Code-Block',        'cmsText', ['useval' => '<pre><code>&lt;div&gt;x&lt;/div&gt;</code></pre>', 'lbrconvert' => 'false'],
        '<pre><code>&lt;div&gt;x&lt;/div&gt;</code></pre>'],
    ['entitydecode=false',             'cmsText', ['useval' => '&euro; &lt;', 'entitydecode' => 'false', 'lbrconvert' => 'false'], '&euro; &lt;'],
    ['specialchars einfach maskiert',  'cmsText', ['useval' => '&lt;b&gt; <i> A &amp; B', 'specialchars' => 'true', 'lbrconvert' => 'false'],
        '&lt;b&gt; &lt;i&gt; A &amp; B'],
    ['einzelnes & wird maskiert',      'cmsText', ['useval' => 'A & B &foo;', 'lbrconvert' => 'false'], 'A &amp; B &foo;'],
    // replace: digit keys and short form
    ['replace[0]=null',               'cmsText', ['useval' => 'A0B', 'replace' => [0 => 'null']], 'AnullB'],
    ['replace=".?,"',                  'cmsText', ['useval' => '3.5', 'replace' => '.?,'], '3,5'],
    // markdown: syntax of the extension MarkdownVisual (Camindo\Core\Markdown)
    ['markdown Inline',                'cmsText', ['useval' => '**f** *k* ++u++ `c` [L](https://x.de) <b>', 'markdown' => 'true'],
        '<p><strong>f</strong> <em>k</em> <u>u</u> <code>c</code> <a href="https://x.de">L</a> &lt;b&gt;</p>'],
    ['markdown Ausrichtung',           'cmsText', ['useval' => "::: center\nMitte\n:::", 'markdown' => 'true'],
        "<div style=\"text-align: center;\">\n<p>Mitte</p>\n</div>"],
    ['markdown Zelle mit <br>',        'cmsText', ['useval' => "| a |\n| --- |\n| x<br>y |", 'markdown' => 'true'],
        "<table>\n<thead>\n<tr>\n<th>a</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td>x<br />y</td>\n</tr>\n</tbody>\n</table>"],
    ['markdown Escapes, Entity',       'cmsText', ['useval' => "\\::: center \\<b> \\&shy; a\u{00AD}b", 'markdown' => 'true'],
        "<p>::: center &lt;b&gt; &amp;shy; a\u{00AD}b</p>"],
    // Writing direction: Unicode isolates RLI/LRI … PDI remain as characters
    ['markdown Schreibrichtung',       'cmsText', ['useval' => "a \u{2067}**b**\u{2069} \u{2066}c\u{2069}", 'markdown' => 'true'],
        "<p>a \u{2067}<strong>b</strong>\u{2069} \u{2066}c\u{2069}</p>"],
    ['markdown javascript:-Link',      'cmsText', ['useval' => '[x](javascript:alert(1))', 'markdown' => 'true'],
        '<p><a href="javascript%3Aalert(1)">x</a></p>'],
    ['markdown leer mit ifempty',      'cmsText', ['useval' => '', 'markdown' => 'true', 'ifempty' => '–'], '–'],
    // CMS:NUMBER decimals
    ['decimals=2',                     'cmsNumber', ['useval' => '19.9', 'decimals' => '2', 'decimal' => ','], '19,90'],
    ['decimals=2 + Tausender',         'cmsNumber', ['useval' => '1234567.891', 'decimals' => '2', 'decimal' => ',', 'thousands_separator' => '.'], '1.234.567,89'],
    ['decimals=0 rundet',              'cmsNumber', ['useval' => '2.5', 'decimals' => '0'], '3'],
    ['ohne decimals wie bisher',       'cmsNumber', ['useval' => '19.9', 'decimal' => ','], '19,9'],
    ['decimals + mul',                 'cmsNumber', ['useval' => '1990', 'mul' => '0.01', 'decimals' => '2', 'decimal' => ','], '19,90'],
    // Empty check on the numeric value
    ['0 ist leer (prefix entfällt)',   'cmsNumber', ['useval' => '0', 'prefix' => '<del>', 'suffix' => '</del>'], ''],
    ['0 mit decimals ist leer',        'cmsNumber', ['useval' => '0', 'decimals' => '2', 'ifempty' => '–'], '–'],
    ['0 mit format ist leer',          'cmsNumber', ['useval' => '0', 'format' => '%.2f', 'ifempty' => 'frei'], 'frei'],
    ['0 ohne prefix/suffix/ifempty',   'cmsNumber', ['useval' => '1', 'add' => '-1'], '0'],
    ['0 mit decimals ohne ifempty',    'cmsNumber', ['useval' => '0', 'decimals' => '2', 'decimal' => ','], '0,00'],
    ['kein -0',                        'cmsNumber', ['useval' => '0', 'mul' => '-1'], '0'],
    ['leere Quelle bleibt leer',       'cmsNumber', ['useval' => '', 'add' => '1'], ''],
    ['0.004 * 1 mit decimals=2',       'cmsNumber', ['useval' => '0.004', 'decimals' => '2'], '0.00'],
    ['Wert mit prefix',                'cmsNumber', ['useval' => '5', 'decimals' => '2', 'prefix' => 'ab '], 'ab 5.00'],
];

$failed = 0;
foreach ($cases as [$label, $method, $p, $expected]) {
    $got = $run($method, $p);
    $ok = ($got === $expected);
    if (!$ok) $failed++;
    printf("%s  %-32s %s\n", $ok ? 'ok  ' : 'FAIL', $label, $ok ? '' : "erwartet '" . $expected . "', erhalten '" . $got . "'");
}

// Parser note: filter[depth] without filter[parent_id] (TemplateParserTrait::depthWithoutParentNote())
// [description, source, note expected]
$template = $app->get(Template::class);
$parserCases = [
    ['depth ohne parent_id',           '(CMS:SELECT nav filter[area_id]=1 filter[depth]=0)', true],
    ['depth mit parent_id=null',       '(CMS:SELECT nav filter[parent_id]=null filter[depth]=1)', false],
    ['depth, parent_id per request',   '(CMS:SELECT nav filter[depth]=1 request="filter[parent_id]")', false],
    ['depth, dynamischer Schlüssel',   '(CMS:SELECT nav filter[depth]=1 filter[{$f}]=3)', false],
    ['ohne depth',                     '(CMS:SELECT nav filter[area_id]=1)', false],
];
foreach ($parserCases as [$label, $source, $expected]) {
    $notes = preg_grep('/filter\[depth\]/', $template->parse($source, 0)['errors']);
    $ok = ((bool)$notes === $expected);
    if (!$ok) $failed++;
    printf("%s  %-32s %s\n", $ok ? 'ok  ' : 'FAIL', $label, $ok ? '' : ($expected ? 'Hinweis fehlt' : 'unerwarteter Hinweis'));
}

// resulttemplate of CMS:SELECT: mandatory unless hits=false (commands.php: mandatory_unless)
// [description, source, error expected]
$resultTemplateCases = [
    ['hits=false ohne Template',       '(CMS:SELECT filter[area_id]=1 hits=false)', false],
    ['hits="false" ohne Template',     '(CMS:SELECT filter[area_id]=1 hits="false")', false],
    ['hits=false mit Template',        '(CMS:SELECT liste filter[area_id]=1 hits=false)', false],
    ['hits dynamisch ohne Template',   '(CMS:SELECT filter[area_id]=1 hits="{$h}")', false],
    ['dynamischer Schlüssel',          '(CMS:SELECT filter[area_id]=1 {$k}=false)', false],
    ['Template ohne Schlüssel',        '(CMS:SELECT liste filter[area_id]=1)', false],
    ['Template mit Schlüssel',         '(CMS:SELECT resulttemplate=liste filter[area_id]=1)', false],
    ['ohne Template',                  '(CMS:SELECT filter[area_id]=1)', true],
    ['count=true ohne Template',       '(CMS:SELECT filter[area_id]=1 count=true)', true],
    ['hits=true ohne Template',        '(CMS:SELECT filter[area_id]=1 hits=true)', true],
];
foreach ($resultTemplateCases as [$label, $source, $expected]) {
    $notes = preg_grep("/parameter 'resulttemplate'/", $template->parse($source, 0)['errors']);
    $ok = ((bool)$notes === $expected);
    if (!$ok) $failed++;
    printf("%s  %-32s %s\n", $ok ? 'ok  ' : 'FAIL', $label, $ok ? '' : ($expected ? 'Fehler fehlt' : 'unerwarteter Fehler'));
}

// The same at runtime, in a process of its own per case: nothing may end it early.
$runtimeCases = [
    ['Laufzeit: ohne Template',        'select-none',  true],
    ['Laufzeit: leeres Template',      'select-empty', true],
    ['Laufzeit: hits=false',           'select-count', false],
];
foreach ($runtimeCases as [$label, $case, $expected]) {
    $process = proc_open([PHP_BINARY, __FILE__, $case], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $childEnv);
    $output = (string)stream_get_contents($pipes[1]);
    proc_close($process);
    // a missing template is logged, the page goes on
    $ok = str_contains($output, $expected ? 'no error' : 'count=0') && !str_contains($output, 'Fatal');
    if (!$ok) $failed++;
    printf("%s  %-32s %s\n", $ok ? 'ok  ' : 'FAIL', $label, $ok ? '' : "Ausgabe '" . trim($output) . "'");
}

// Wrong arguments, parser: every parameter of one value of every command, given twice,
// reaches the method as a single value - the last one
$singleValue = 0;
foreach (include dirname(__DIR__) . '/Modules/Template/commands.php' as $name => $def) {
    foreach (($def['params'] ?? []) as $param => $pdef) {
        if ((int)($pdef['subparam'] ?? 0) > 0 || !empty($pdef['multiple'])) continue;
        $singleValue++;
        foreach (["$param=erster $param=letzter", "$param=erster {$param}[]=letzter"] as $arguments) {
            $parsed = $template->parse('(CMS:' . strtoupper($name) . " $arguments)", 0);
            $notes = preg_grep("/Note: Parameter '$param'/", $parsed['errors']);
            $ok = $notes && !str_contains($parsed['phpCode'], "\$_a[\"$param\"][]")
                && str_contains($parsed['phpCode'], "\$_a[\"$param\"] = \"letzter\"")
                && !str_contains($parsed['phpCode'], 'erster');
            if (!$ok) {
                $failed++;
                printf("FAIL  einfacher Wert: CMS:%s %s\n", strtoupper($name), $arguments);
            }
        }
    }
}
$parsed = $template->parse('(CMS:SELECT erster filter[area_id]=1 resulttemplate=letzter)', 0);
$ok = (bool)preg_grep("/Note: Parameter 'resulttemplate'/", $parsed['errors'])
    && str_contains($parsed['phpCode'], '$_a["resulttemplate"] = "letzter"') && !str_contains($parsed['phpCode'], 'erster');
if (!$ok) $failed++;
printf("%s  %-32s\n", $ok ? 'ok  ' : 'FAIL', 'Hauptparameter und Schlüssel');
$parsed = $template->parse('(CMS:SELECT liste filter[area_id]=1 sql="1=1" sql="2=2" request[]=a request[]=b)', 0);
$ok = substr_count($parsed['phpCode'], '$_a["sql"][]') === 2 && substr_count($parsed['phpCode'], '$_a["request"][]') === 2;
if (!$ok) $failed++;
printf("%s  %-32s\n", $ok ? 'ok  ' : 'FAIL', 'multiple: Liste bleibt');
printf("ok    einfacher Wert: %d Parameter geprüft\n", $singleValue);

// Wrong arguments, runtime: no exception, no output, the page goes on
$db = $app->get(Database::class);
$db->query('SELECT area_id FROM record WHERE tmp = 0 AND deleted = 0 LIMIT 1');
$hits = ['filter' => ['area_id' => (string)($db->fetch()['area_id'] ?? 0)]];
$wrongCases = [
    ['Include: unbekanntes Template',  fn() => $cms->cmsInclude(['template' => 'no_such_template'])],
    ['Select: unbekanntes Template',   fn() => $cms->cmsSelect($hits + ['resulttemplate' => 'no_such_template'])],
    ['Select: unbekanntes noresult',   fn() => $cms->cmsSelect(['filter' => $filter, 'noresulttemplate' => 'no_such_template', 'resulttemplate' => 'x'])],
    ['Select: falsche Bedingung',      fn() => $cms->cmsSelect($hits + ['resulttemplate' => 'x', 'sql' => 'no_such_column = 1'])],
    ['Select: ohne filter',            fn() => $cms->cmsSelect(['resulttemplate' => 'x'])],
    ['Sprachen: unbekanntes Template', fn() => $cms->cmsSelectLanguages(['resulttemplate' => 'no_such_template'])],
];
foreach ($wrongCases as [$label, $call]) {
    try {
        ob_start();
        $file = $call();
        $output = (string)ob_get_clean();
        $cms->selectCountEnd();
        $ok = ($file === '' && $output === '');
    }
    catch (\Throwable $e) {
        ob_end_clean();
        [$ok, $output] = [false, get_class($e) . ': ' . $e->getMessage()];
    }
    if (!$ok) $failed++;
    printf("%s  %-32s %s\n", $ok ? 'ok  ' : 'FAIL', $label, $ok ? '' : "Ausgabe '" . trim($output) . "'");
}
try {
    $output = $run('cmsText', ['useval' => 'äx', 'charset' => 'no_such_charset']);
    $ok = str_contains($output, 'äx');
}
catch (\Throwable $e) {
    [$ok, $output] = [false, get_class($e) . ': ' . $e->getMessage()];
}
if (!$ok) $failed++;
printf("%s  %-32s %s\n", $ok ? 'ok  ' : 'FAIL', 'Text: unbekannter Zeichensatz', $ok ? '' : "Ausgabe '" . trim($output) . "'");

// CMS:USER_PROTECT stays closed: the rest of the page must not run
$process = proc_open([PHP_BINARY, __FILE__, 'protect-unknown'], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $childEnv);
$output = (string)stream_get_contents($pipes[1]);
proc_close($process);
$ok = !str_contains($output, 'page continued') && !str_contains($output, 'Fatal');
if (!$ok) $failed++;
printf("%s  %-32s %s\n", $ok ? 'ok  ' : 'FAIL', 'Protect ohne Fehler-Template', $ok ? '' : "Ausgabe '" . trim($output) . "'");

$total = count($cases) + count($parserCases) + count($resultTemplateCases) + count($runtimeCases) + count($wrongCases) + 4 + 2 * $singleValue;
echo $failed ? "\n$failed Abweichung(en)\n" : "\nalle $total Fälle ok\n";
exit($failed ? 1 : 0);
