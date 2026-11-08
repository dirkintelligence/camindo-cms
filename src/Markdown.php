<?php
/**
 * Markdown -> HTML for frontend templates: Parsedown in safe mode (HTML in the Markdown
 * is masked, link targets such as javascript: are neutralized) plus the extensions that
 * the MarkdownVisual extension writes.
 *
 *     $html = (new \Camindo\Core\Markdown())->toHtml($text);
 *     (CMS:TEXT text markdown=true)                        the same as a template command
 *
 * EXTENSIONS TO PARSEDOWN
 * --------------------
 *     ++Text++              <u>Text</u> (underline)
 *     ::: center            Alignment of the enclosed blocks - left, center,
 *     …                     right; yields <div style="text-align: center;">, the way
 *     :::                   Parsedown writes the column alignment of tables.
 *                           Nestable, an unknown word stays without style.
 *     <br> / <br />         Line break also in safe mode - the only way to a
 *                           break within a table cell (GFM)
 *     &shy; &nbsp; &#8239;  Entities stay entities (Parsedown masks them in safe
 *                           mode to &amp;shy;); result is only a single character
 *     \: \& \= \<           additional escape characters (line starting with ":::";
 *                           text that looks like an entity or a tag; line consisting
 *                           only of "=")
 *
 * LINE BREAKS AND EMPTY LINES (unlike CommonMark, like a plain text field)
 * --------------------
 *     1 line break          a line break (<br>) - also without two spaces at the end
 *     1 blank line          a new paragraph
 *     n blank lines         a new paragraph, starting with n - 1 empty lines; before a
 *                           heading, list, table … a paragraph of its own; at the very start
 *                           of the text every blank line is an empty line
 *     &nbsp;                a line that is only "&nbsp;" is an empty line - the way
 *                           MarkdownVisual writes empty lines (unlike <br>, it survives
 *                           strip_tags)
 * Parsedown 1.8 does not reliably understand the backslash at the end of the line
 * (CommonMark), so MarkdownVisual does not write it. MarkdownVisual itself writes empty
 * lines as "&nbsp;", the indentation of a line as non-breaking spaces and < > in the text
 * as &lt; &gt;.
 *
 * The syntax is the same as in Extensions/MarkdownVisual/public/js/markdownvisual.ext.js
 * (parser and serializer in the editor) - whoever changes it changes both.
 */
namespace Camindo\Core;

class Markdown extends \Parsedown
{
    /** Values of ::: {word} that yield an alignment */
    public const ALIGNMENTS = ['left', 'center', 'right'];

    public function __construct()
    {
        $this->setSafeMode(true);
        $this->setBreaksEnabled(true);
        $this->BlockTypes[':'][] = 'Align';
        $this->InlineTypes['+'] = ['Underline'];
        array_unshift($this->InlineTypes['<'], 'BreakTag');
        $this->inlineMarkerList .= '+';
        $this->specialCharacters[] = ':';
        $this->specialCharacters[] = '&';
        $this->specialCharacters[] = '=';
        $this->specialCharacters[] = '<';
    }

    /** Markdown -> HTML; empty text yields '' */
    public function toHtml(string $markdown): string
    {
        return trim($markdown) === '' ? '' : (string)$this->text($this->emptyLines($markdown));
    }

    // ---- Empty lines ----

    /**
     * Runs of blank lines -> empty lines: the first blank line between two blocks separates
     * them, every further one (and every blank line at the start of the text) becomes a line
     * "&nbsp;" at the start of the next paragraph, or a paragraph of its own before another
     * block. Fenced
     * code stays as it is; before a line indented by four or more columns (code, deeper list
     * content) the blank lines stay as well.
     */
    private function emptyLines(string $markdown): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $out = [];
        $blanks = 0;
        $started = false;
        $fence = null;
        foreach ($lines as $i => $line) {
            if ($fence !== null) {
                $out[] = $line;
                if (preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}[ \t]*$/', $line)) $fence = null;
                continue;
            }
            if (trim($line, " \t") === '') { $blanks++; continue; }
            preg_match('/^[ \t]*/', $line, $m);
            $indent = $m[0];
            $width = strlen(str_replace("\t", '    ', $indent));
            $extra = $started ? $blanks - 1 : $blanks;
            if ($width >= 4 && $started) $extra = 0;
            if ($started && $blanks > 0) array_push($out, ...array_fill(0, $extra > 0 ? 1 : $blanks, ''));
            if ($extra > 0) {
                $empty = array_fill(0, $extra, $indent . '&nbsp;');
                if ($this->startsOtherBlock($line, $lines[$i + 1] ?? '')) array_push($out, ...$empty, ...['']);
                else array_push($out, ...$empty);
            }
            $out[] = $line;
            $blanks = 0;
            $started = true;
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) $fence = $m[1];
        }
        return implode("\n", $out);
    }

    /** Does the line start a block other than a paragraph (as the editor reads it)? */
    private function startsOtherBlock(string $line, string $next): bool
    {
        if (preg_match('/^ {0,3}(#{1,6}([ \t]|$)|>|[-*+][ \t]+\S|\d{1,9}[.)][ \t]+\S|`{3,}|~{3,})/', $line)) return true;
        if (preg_match('/^:::[ \t]*[A-Za-z-]+[ \t]*$/', $line)) return true;
        if (preg_match('/^ {0,3}([-*_])([ \t]*\1){2,}[ \t]*$/', $line)) return true;
        // setext heading, table
        if (preg_match('/^ {0,3}(=+|-+)[ \t]*$/', $next)) return true;
        return str_contains($line, '|') && str_contains($next, '-')
            && preg_match('/^[ \t]*\|?[ \t]*:?-+:?[ \t]*(\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/', $next) === 1;
    }

    // ---- ::: alignment … ::: ----

    protected function blockAlign($Line)
    {
        if (!preg_match('/^:::[ ]*([A-Za-z-]*)[ ]*$/', $Line['text'], $m) || $m[1] === '') return null;
        $align = strtolower($m[1]);
        return [
            'depth'   => 0,
            'lines'   => [],
            'element' => [
                'name'       => 'div',
                'attributes' => in_array($align, self::ALIGNMENTS, true)
                    ? ['style' => 'text-align: ' . $align . ';']
                    : [],
                'handler'    => [
                    'function'    => 'linesElements',
                    'argument'    => [],
                    'destination' => 'elements',
                ],
            ],
        ];
    }

    protected function blockAlignContinue($Line, $Block)
    {
        if (isset($Block['complete'])) return null;
        if (isset($Block['interrupted'])) {
            for ($i = 0; $i < $Block['interrupted']; $i++) $Block['lines'][] = '';
            unset($Block['interrupted']);
        }
        // Close only at the far left - indented ":::" belong to code or lists
        if ($Line['indent'] === 0 && preg_match('/^:::[ ]*([A-Za-z-]*)[ ]*$/', $Line['text'], $m)) {
            if ($m[1] !== '') {
                $Block['depth']++;
            }
            elseif ($Block['depth'] > 0) {
                $Block['depth']--;
            }
            else {
                $Block['complete'] = true;
                return $Block;
            }
        }
        $Block['lines'][] = $Line['body'];
        return $Block;
    }

    protected function blockAlignComplete($Block)
    {
        $Block['element']['handler']['argument'] = $Block['lines'];
        return $Block;
    }

    // ---- ++underlined++ ----

    protected function inlineUnderline($Excerpt)
    {
        if (!isset($Excerpt['text'][1]) || $Excerpt['text'][1] !== '+') return null;
        if (!preg_match('/^\+\+(?=\S)(.+?)(?<=\S)\+\+(?!\+)/s', $Excerpt['text'], $m)) return null;
        return [
            'extent'  => strlen($m[0]),
            'element' => [
                'name'    => 'u',
                'handler' => [
                    'function'    => 'lineElements',
                    'argument'    => $m[1],
                    'destination' => 'elements',
                ],
            ],
        ];
    }

    // ---- <br> ----

    protected function inlineBreakTag($Excerpt)
    {
        if (!preg_match('/^<br[ ]*\/?>/i', $Excerpt['text'], $m)) return null;
        return ['extent' => strlen($m[0]), 'element' => ['name' => 'br']];
    }

    // ---- Entities ----

    /**
     * Like Parsedown, but also output as an entity in safe mode: the pattern only lets
     * &name; &#123; &#x1F; through - in the browser this becomes at most one character.
     */
    protected function inlineSpecialCharacter($Excerpt)
    {
        if (preg_match('/^&(#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[a-zA-Z][a-zA-Z0-9]{1,31});/', $Excerpt['text'], $m)) {
            return [
                'element' => ['rawHtml' => '&' . $m[1] . ';', 'allowRawHtmlInSafeMode' => true],
                'extent'  => strlen($m[0]),
            ];
        }
        return null;
    }
}
