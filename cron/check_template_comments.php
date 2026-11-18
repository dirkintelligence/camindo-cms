<?php
/**
 * check_template_comments.php - self-test for CMS commands in PHP parts of a template
 *
 *     php Core/cron/check_template_comments.php
 *
 * Runs the parser (TemplateParserTrait) on small templates and checks:
 *   - a command in a PHP comment (//, #, block and doc comment) stays unchanged text,
 *     the compiled code is valid PHP and outputs nothing of the comment
 *   - a "//" comment ends at the closing PHP tag - a command behind it counts again
 *   - a command in PHP code or a PHP string gets a note
 *   - commands in the HTML part (also in HTML comments) are compiled as before
 *   - syntax check of the compiled code: "PHP syntax error" with the source line
 * Exit code 1 on deviations.
 */
use Camindo\Core\Modules\Template\Template;

// Reachable only on the command line, not via the web server
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

require_once dirname(__DIR__, 2) . '/load_camindo.php';

$template = $app->get(Template::class);

// Marker of a compiled command (TemplateParserTrait::generateCmsCommand())
$compiled = fn(string $php, string $name): int => substr_count($php, '/* --- CMS:' . $name . ' --- */');
$messages = fn(array $errors, string $pattern): array => array_values(preg_grep($pattern, $errors));

// [description, source, expected compiled TEXT commands, expected notes "inside PHP code",
//  syntax error expected (line or 0)]
$cases = [
    ['// Kommentar',
        "<?php\n  \$a = 1;   // Datensatz (CMS:TEXT title)\n  \$b = 2;\n?>\n<p>ok</p>", 0, 0, 0],
    ['# Kommentar',
        "<?php\n  # (CMS:TEXT title)\n  \$b = 2;\n?>", 0, 0, 0],
    ['Block-Kommentar mehrzeilig',
        "<?php\n  /* Menü\n     (CMS:TEXT title)\n     (CMS:SAVEDATA page) */\n  \$b = 2;\n?>", 0, 0, 0],
    ['Doc-Kommentar',
        "<?php\n/**\n * (CMS:TEXT title)\n */\nfunction f() {}\n?>", 0, 0, 0],
    ['// endet am schließenden Tag',
        "<?php // x ?>(CMS:TEXT title)", 1, 0, 0],
    ['Kommentar und Befehl im HTML',
        "<?php // (CMS:TEXT title) ?>\n<h1>(CMS:TEXT title)</h1>\n<!-- (CMS:TEXT title) -->", 2, 0, 0],
    ['Befehl im PHP-String',
        "<?php \$x = '(CMS:TEXT title)'; ?>", 1, 1, 0],
    ['Befehl im PHP-Code',
        "<?php\n\$x = 1;\n\$y = (CMS:TEXT title);\n?>", 1, 1, 3],
    ['mehrzeiliger Befehl ab //',
        "<?php\n  // (CMS:SELECT nav\n  filter[area]=pages\n  )\n?>", 0, 0, 4],   // PHP notices the ")"
    ['PHP-Syntaxfehler ohne Befehl',
        "<p>x</p>\n<?php\n\$x = 1 +;\n?>", 0, 0, 3],   // unexpected ";" - same line in PHP 8.3 and 8.4
    ['nur HTML',
        "<ul>\n(CMS:RESULT_START)<li>(CMS:TEXT title)</li>(CMS:RESULT_END)\n</ul>", 1, 0, 0],
];

$failed = 0;
foreach ($cases as [$label, $source, $expectCompiled, $expectNotes, $expectSyntaxLine]) {
    $result = $template->parse($source, 0);
    $php    = $result['phpCode'];
    $errors = (array)$result['errors'];

    $problems = [];
    if (($n = $compiled($php, 'TEXT')) !== $expectCompiled) {
        $problems[] = "kompiliert $n statt $expectCompiled";
    }
    if (($n = count($messages($errors, '/stands inside PHP code/'))) !== $expectNotes) {
        $problems[] = "$n statt $expectNotes Hinweis(e)";
    }
    $syntax = $messages($errors, '/PHP syntax error/');
    if ($expectSyntaxLine === 0 && $syntax) {
        $problems[] = 'unerwartet: ' . $syntax[0];
    }
    if ($expectSyntaxLine > 0 && (!$syntax || !str_starts_with($syntax[0], "Line $expectSyntaxLine:"))) {
        $problems[] = 'Syntaxfehler in Zeile ' . $expectSyntaxLine . ' erwartet, gemeldet: ' . ($syntax[0] ?? '-');
    }
    // Without a syntax error the compiled code must also run through PHP's parser itself
    if ($expectSyntaxLine === 0) {
        try {
            token_get_all($php, TOKEN_PARSE);
        } catch (\ParseError $e) {
            $problems[] = 'kompilierter Code: ' . $e->getMessage();
        }
    }
    // In a comment the source code stays unchanged
    if ($expectCompiled === 0 && $expectSyntaxLine === 0 && $php !== $source) {
        $problems[] = 'Kommentar verändert';
    }

    if ($problems) $failed++;
    printf("%s  %-32s %s\n", $problems ? 'FAIL' : 'ok  ', $label, implode('; ', $problems));
}

// The comment of a template must not reach the page (the trap: closing tag in a line comment)
$source = "<?php\n  \$page = \$page ?? [];   // (CMS:SAVEDATA page)\n  \$x = 'geheim';\n?><p>ok</p>";
ob_start();
eval('?>' . $template->parse($source, 0)['phpCode']);
$output = (string)ob_get_clean();
$ok = ($output === '<p>ok</p>');
if (!$ok) $failed++;
printf("%s  %-32s %s\n", $ok ? 'ok  ' : 'FAIL', 'Ausgabe ohne Kommentarreste', $ok ? '' : "Ausgabe '" . trim($output) . "'");

$total = count($cases) + 1;
echo $failed ? "\n$failed Abweichung(en)\n" : "\nalle $total Fälle ok\n";
exit($failed ? 1 : 0);
