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
 * Hard line breaks: two spaces at the end of the line. Parsedown 1.8 does not reliably
 * understand the backslash at the end of the line (CommonMark), so MarkdownVisual does
 * not write it.
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
        return trim($markdown) === '' ? '' : (string)$this->text($markdown);
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
