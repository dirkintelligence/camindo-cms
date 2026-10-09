<?php
/**
 * Sprachdateien im INI-Format lesen und schreiben - lang/{code}.ini der Komponenten,
 * die Snippet-Sets der Instanzen (Modul Snippets), var/translation/translated/*.ini
 * und die Vorlagen unter var/translation/blueprint.
 *
 * Bewusst KEIN parse_ini_file(): Dort sind yes/no/true/false/on/off/none/null als
 * Schlüssel Syntaxfehler (das Core-Snippet 'yes' wäre nicht ladbar), ${NAME} in einem
 * Wert setzt eine Umgebungsvariable ein und Konstanten in Werten ohne Anführungszeichen
 * werden aufgelöst. Ein Übersetzungspaket von außen soll nichts davon auslösen können;
 * ausgeführt wird nichts.
 *
 * FORMAT
 * --------------------
 *     ; Kommentar (auch # am Zeilenanfang)
 *     [backend]                       Abschnitt (nur in Übersetzungspaketen, s. u.)
 *     schluessel = "Wert"             Werte konsequent in doppelten Anführungszeichen
 *     mehrzeilig = "Zeile 1
 *     Zeile 2"                        ein Wert darf über mehrere Zeilen gehen
 *
 * Innerhalb der Anführungszeichen gelten nur zwei Maskierungen: \" für ein
 * Anführungszeichen und \\ für einen Backslash; jeder andere Backslash bleibt
 * stehen (\n ist also der Text \n, kein Zeilenumbruch). Ein Wert ohne
 * Anführungszeichen wird toleriert (Rest der Zeile, getrimmt, ohne ; Kommentar),
 * geschrieben wird er immer mit.
 *
 * @see Camindo\Core\SnippetCache
 */
namespace Camindo\Core;

class SnippetIni {

    // Schlüssel: wie Snippet-Schlüssel, in Übersetzungspaketen mit Präfix
    // (ext.datepicker.option_mode) - Buchstaben, Ziffern, _ . -
    private const KEY_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.\-]*$/';

    /**
     * Datei lesen.
     *
     * @return array{0: array<string, array<string, string>>, 1: string[]}
     *         [Abschnitt => [Schlüssel => Text]] (Einträge vor dem ersten
     *         Abschnitt unter '') und die Liste der Fehler ("Datei:Zeile: ...");
     *         fehlerhafte Zeilen werden übergangen, der Rest gilt
     */
    public static function readFile(string $file): array
    {
        $content = @file_get_contents($file);
        if ($content === false) return [[], [$file . ': not readable']];
        return self::parse($content, $file);
    }

    /**
     * @return array{0: array<string, array<string, string>>, 1: string[]}  wie readFile()
     */
    public static function parse(string $content, string $name = 'ini'): array
    {
        // BOM und Windows-Zeilenenden
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

            // Wert: ab dem Gleichheitszeichen, führende Leerzeichen weg
            $i = $pos + $eq + 1;
            while ($i < $length && ($content[$i] === ' ' || $content[$i] === "\t")) $i++;

            if ($i < $length && $content[$i] === '"') {
                // In Anführungszeichen - darf über Zeilen gehen
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
                // Nach dem Wert nur noch Leerzeichen oder ein Kommentar
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
     * Wert als "…" mit den Maskierungen von parse().
     */
    public static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Einen Eintrag als Zeile: schluessel = "Wert"
     */
    public static function line(string $key, string $value): string
    {
        return $key . ' = ' . self::quote($value) . "\n";
    }

    /**
     * Kommentarzeilen (; …) aus einem mehrzeiligen Text.
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
     * Abschnitte zu INI-Text.
     *
     * @param array<string, array<string, string>> $sections  Abschnitt => [Schlüssel => Text];
     *                                                       '' = ohne Abschnittskopf (zuerst)
     * @param string $header  Kommentar am Dateianfang
     * @param array<string, array<string, string>> $groups    optional: Abschnitt =>
     *                         [Schlüssel => Zwischenüberschrift] - vor dem Schlüssel als
     *                         Kommentar ausgegeben (z. B. die Komponente)
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
