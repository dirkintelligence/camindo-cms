<?php
/**
 * Markdown -> HTML für Frontend-Templates: Parsedown im Safe Mode (HTML im Markdown
 * wird maskiert, Link-Ziele wie javascript: entschärft) plus die Erweiterungen, die
 * die Extension MarkdownVisual schreibt.
 *
 *     $html = (new \Camindo\Core\Markdown())->toHtml($text);
 *     (CMS:TEXT text markdown=true)                        dasselbe als Template-Befehl
 *
 * ERWEITERUNGEN GEGENÜBER PARSEDOWN
 * --------------------
 *     ++Text++              <u>Text</u> (Unterstrich)
 *     ::: center            Ausrichtung der eingeschlossenen Blöcke - left, center,
 *     …                     right; ergibt <div style="text-align: center;">, wie
 *     :::                   Parsedown die Spaltenausrichtung der Tabellen schreibt.
 *                           Verschachtelbar, ein unbekanntes Wort bleibt ohne Stil.
 *     <br> / <br />         Zeilenumbruch auch im Safe Mode - der einzige Weg zu einem
 *                           Umbruch in einer Tabellenzelle (GFM)
 *     &shy; &nbsp; &#8239;  Entities bleiben Entities (Parsedown maskiert sie im Safe
 *                           Mode zu &amp;shy;); Ergebnis ist nur ein einzelnes Zeichen
 *     \: \& \= \<           zusätzliche Escape-Zeichen (Zeile, die mit ":::" beginnt;
 *                           Text, der wie ein Entity oder ein Tag aussieht; Zeile nur
 *                           aus "=")
 *
 * Harte Zeilenumbrüche: zwei Leerzeichen am Zeilenende. Den Rückstrich am Zeilenende
 * (CommonMark) versteht Parsedown 1.8 nicht zuverlässig, MarkdownVisual schreibt ihn
 * deshalb nicht.
 *
 * Die Syntax ist dieselbe wie in Extensions/MarkdownVisual/public/js/markdownvisual.ext.js
 * (Parser und Serializer im Editor) - wer sie ändert, ändert beide.
 */
namespace Camindo\Core;

class Markdown extends \Parsedown
{
    /** Werte von ::: {wort}, die eine Ausrichtung ergeben */
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

    /** Markdown -> HTML; leerer Text ergibt '' */
    public function toHtml(string $markdown): string
    {
        return trim($markdown) === '' ? '' : (string)$this->text($markdown);
    }

    // ---- ::: ausrichtung … ::: ----

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
        // Schließen nur ganz links - eingerückte ":::" gehören zu Code oder Listen
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

    // ---- ++unterstrichen++ ----

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
     * Wie Parsedown, aber auch im Safe Mode als Entity ausgegeben: das Muster lässt
     * nur &name; &#123; &#x1F; durch - daraus wird im Browser höchstens ein Zeichen.
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
