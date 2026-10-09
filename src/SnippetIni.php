<?php
/**
 * Read and write language files in INI format - lang/{code}.ini of the components,
 * the snippet sets of the instances (Snippets module), var/translation/translated/*.ini
 * and the templates under var/translation/blueprint.
 *
 * Deliberately NOT parse_ini_file(): there yes/no/true/false/on/off/none/null as
 * keys are syntax errors (the core snippet 'yes' would not be loadable), ${NAME} in a
 * value substitutes an environment variable, and constants in values without quotes
 * are resolved. A translation package from outside must not be able to trigger any of
 * this; nothing is executed.
 *
 * FORMAT
 * --------------------
 *     ; comment (also # at the start of a line)
 *     [backend]                       section (only in translation packages, see below)
 *     key = "value"                   values consistently in double quotes
 *     multiline = "line 1
 *     line 2"                         a value may span several lines
 *
 * Inside the quotes only two escapes apply: \" for a quote character and \\ for a
 * backslash; any other backslash stays as it is (\n is therefore the text \n, not a
 * line break). A value without quotes is tolerated (rest of the line, trimmed, without
 * ; comment), but it is always written with quotes.
 *
 * @see Camindo\Core\SnippetCache
 */
namespace Camindo\Core;

class SnippetIni {

    // Keys: like snippet keys, in translation packages with prefix
    // (ext.datepicker.option_mode) - letters, digits, _ . -
    private const KEY_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.\-]*$/';

    /**
     * Read a file.
     *
     * @return array{0: array<string, array<string, string>>, 1: string[]}
     *         [section => [key => text]] (entries before the first
     *         section under '') and the list of errors ("file:line: ...");
     *         faulty lines are skipped, the rest applies
     */
    public static function readFile(string $file): array
    {
        $content = @file_get_contents($file);
        if ($content === false) return [[], [$file . ': not readable']];
        return self::parse($content, $file);
    }

    /**
     * @return array{0: array<string, array<string, string>>, 1: string[]}  as readFile()
     */
    public static function parse(string $content, string $name = 'ini'): array
    {
        // BOM and Windows line endings
        if (str_starts_with($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $sections = [];
        $errors = [];
        $section = '';
        $length = strlen($content);
        $pos = 0;
        $line = 1;

        while ($pos < $length) {
            $end = strpos($content, "\n", $pos);
            if ($end === false) $end = $length;
            $row = substr($content, $pos, $end - $pos);
            $rowLine = $line;
            $trimmed = trim($row);

            if ($trimmed === '' || $trimmed[0] === ';' || $trimmed[0] === '#') {
                $pos = $end + 1;
                $line++;
                continue;
            }

            if ($trimmed[0] === '[') {
                if (preg_match('/^\[\s*([A-Za-z0-9_.\-]*)\s*\]\s*(?:[;#].*)?$/', $trimmed, $m)) {
                    $section = $m[1];
                }
                else {
                    $errors[] = "$name:$rowLine: invalid section";
                }
                $pos = $end + 1;
                $line++;
                continue;
            }

            $eq = strpos($row, '=');
            $key = $eq === false ? '' : trim(substr($row, 0, $eq));
            if ($eq === false || !preg_match(self::KEY_PATTERN, $key)) {
                $errors[] = "$name:$rowLine: invalid line";
                $pos = $end + 1;
                $line++;
                continue;
            }

            // Value: from the equals sign on, leading spaces removed
            $i = $pos + $eq + 1;
            while ($i < $length && ($content[$i] === ' ' || $content[$i] === "\t")) $i++;

            if ($i < $length && $content[$i] === '"') {
                // In quotes - may span lines
                $value = '';
                $i++;
                $closed = false;
                while ($i < $length) {
                    $char = $content[$i];
                    if ($char === '\\' && $i + 1 < $length && ($content[$i + 1] === '"' || $content[$i + 1] === '\\')) {
                        $value .= $content[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($char === '"') {
                        $closed = true;
                        $i++;
                        break;
                    }
                    if ($char === "\n") $line++;
                    $value .= $char;
                    $i++;
                }
                if (!$closed) {
                    $errors[] = "$name:$rowLine: missing closing quote for '$key'";
                    break;
                }
                // After the value only spaces or a comment may follow
                $end = strpos($content, "\n", $i);
                if ($end === false) $end = $length;
                $rest = trim(substr($content, $i, $end - $i));
                if ($rest !== '' && $rest[0] !== ';' && $rest[0] !== '#') {
                    $errors[] = "$name:$rowLine: unexpected text after the value of '$key'";
                    $pos = $end + 1;
                    $line++;
                    continue;
                }
            }
            else {
                $value = substr($content, $i, $end - $i);
                $comment = strpos($value, ';');
                if ($comment !== false) $value = substr($value, 0, $comment);
                $value = trim($value);
            }

            $sections[$section][$key] = $value;
            $pos = $end + 1;
            $line++;
        }

        return [$sections, $errors];
    }

    /**
     * Value as "…" with the escapes of parse().
     */
    public static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * One entry as a line: key = "value"
     */
    public static function line(string $key, string $value): string
    {
        return $key . ' = ' . self::quote($value) . "\n";
    }

    /**
     * Comment lines (; …) from a multi-line text.
     */
    public static function comment(string $text): string
    {
        $out = '';
        foreach (explode("\n", rtrim($text)) as $row) {
            $out .= rtrim('; ' . $row) . "\n";
        }
        return $out;
    }

    /**
     * Sections to INI text.
     *
     * @param array<string, array<string, string>> $sections  section => [key => text];
     *                                                       '' = without section header (first)
     * @param string $header  comment at the start of the file
     * @param array<string, array<string, string>> $groups    optional: section =>
     *                         [key => subheading] - output as a comment before the key
     *                         (e.g. the component)
     */
    public static function build(array $sections, string $header = '', array $groups = []): string
    {
        $out = $header !== '' ? self::comment($header) . "\n" : '';
        if (isset($sections[''])) {
            $sections = ['' => $sections['']] + $sections;
        }
        foreach ($sections as $section => $entries) {
            if ($section !== '') $out .= '[' . $section . "]\n";
            foreach ($entries as $key => $value) {
                if (isset($groups[$section][$key])) {
                    $out .= "\n" . self::comment($groups[$section][$key]);
                }
                $out .= self::line((string)$key, (string)$value);
            }
            $out .= "\n";
        }
        return rtrim($out) . "\n";
    }
}
