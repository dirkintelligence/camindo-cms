<?php
/**
 * Entfernt aktiven Inhalt aus HTML - beim Schreiben und beim Lesen von
 * Datenbankinhalten (16-sicherheit.md §6, 17-csrf.md §3). Instanzname: $htmlSanitizer.
 *
 * Aufrufer: Cms::_sanitize_html() / Cms::_sanitizeValue() (Dataset::validateValue(),
 * Snippets::save(), Cms::fetch(), VirtualFieldsTrait::virtualSchema()) und
 * InstanceCache (Instanz- und Sprachbezeichnungen) - dort ohne Cms, weil Cms
 * selbst InstanceCache braucht.
 */
namespace Camindo\Core;

class HtmlSanitizer {

    // Tags, die clean() IMMER entfernt. Mit Inhalt: der Inhalt ist Code
    // bzw. ein eigenes Dokument. Nur das Tag: leer oder ohne sinnvollen Inhalt.
    // SVG-Animationen (set, animate…) können über attributeName Event-Handler und
    // href setzen, ohne dass ein on…-Attribut dasteht.
    private const DROP_WITH_CONTENT = ['script', 'iframe', 'object', 'applet', 'frameset', 'noembed', 'noframes'];
    private const DROP_TAG = ['embed', 'base', 'meta', 'frame', 'set', 'animate', 'animatetransform', 'animatemotion', 'handler', 'listener'];
    // Diese Attribute fallen immer weg (srcdoc: ein Dokument im selben Ursprung)
    private const DROP_ATTRIBUTES = ['srcdoc', 'formaction', 'xlink:href'];
    /** Cache der Whitelist system.html_allowed_tags (null = noch nicht gelesen) */
    private ?array $allowedTags = null;

    public function __construct(private Config $config)
    {
    }

    /**
     * Entfernt aktiven Inhalt aus HTML, lässt Formatierung stehen.
     *
     * Beim SCHREIBEN: Dataset::validateValue() für jeden Textwert (Ausnahme: Modul
     * Template, siehe Dataset::$sanitizeHtml), Snippets::save().
     * Beim LESEN: Cms::fetch() für jede Zeile (außer `template`) - gegen Inhalte, die
     * an der Anwendung vorbei in die Datenbank kamen (16-sicherheit.md §6).
     *
     * Gelöscht wird - nicht maskiert:
     *  - script, iframe, object, applet, frameset, noembed, noframes samt Inhalt
     *  - embed, base, meta, frame und die SVG-Animationen set/animate… (nur das Tag)
     *  - Event-Handler (on…), srcdoc, formaction, xlink:href
     *  - javascript:/vbscript:-URLs in jedem Attributwert, data:-URLs außer
     *    data:image/(png|gif|jpeg|webp|avif|bmp) - auch mit Entities/Steuerzeichen
     *  - mit system.html_allowed_tags (Liste von Tag-Namen): jedes andere Tag (der
     *    Inhalt bleibt)
     *
     * Attribute trennt der Browser auch mit "/" (<img/onerror=…>), und ein ">" in
     * einem Attributwert beendet das Tag nicht - beides beachtet die Tag-Erkennung.
     * Läuft, bis sich nichts mehr ändert (<scr<script>ipt> ergäbe sonst <script>).
     * Ohne "<" kehrt die Funktion sofort zurück; für sauberes HTML liefert sie den
     * Text unverändert (kein Umformatieren), sie ist also beliebig oft anwendbar.
     *
     * @param string $text  Text mit HTML
     */
    public function clean(string $text): string
    {
        if ($text === '' || !str_contains($text, '<')) return $text;
        if ($this->harmless($text)) return $text;
        for ($round = 0; $round < 5; $round++) {
            $before = $text;
            $text = $this->round($text);
            if ($text === $before || !str_contains($text, '<')) break;
        }
        return $text;
    }

    /**
     * Schneller Vorab-Check: nichts, was round() ändern würde? Keine Whitelist,
     * keines der Signalwörter (Tags der Sperrliste, on…=, srcdoc, formaction,
     * xlink:href, script:, data:, Entities in Zahlform) und jedes "<"+Buchstabe
     * beginnt ein vollständiges Tag. Trifft das zu, bleibt der Text wörtlich.
     */
    private function harmless(string $text): bool
    {
        if ($this->allowedTags() !== []) return false;
        static $signal = null;
        $signal ??= '~<\s*/?\s*(?:' . implode('|', array_merge(self::DROP_WITH_CONTENT, self::DROP_TAG)) . ')\b'
            . '|[\s/"\']on[a-z]+\s*=|srcdoc|formaction|xlink:href|script\s*:|script&|data\s*:|&#~i';
        if (preg_match($signal, $text)) return false;
        $starts = preg_match_all('~</?[a-z]~i', $text);
        $complete = preg_match_all('~</?[a-z][a-z0-9:-]*(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i', $text);
        return $starts === $complete;
    }

    private function round(string $text): string
    {
        // Elemente samt Inhalt; ohne schließendes Tag bei script bis zum Ende
        $withContent = implode('|', self::DROP_WITH_CONTENT);
        $text = preg_replace('!<(' . $withContent . ')\b[^>]*>.*?</\1\s*>!is', '', $text);
        $text = preg_replace('!<script\b.*$!is', '', $text);
        // Übrige öffnende/schließende Tags dieser Elemente und die Tag-Liste
        $tags = implode('|', array_merge(self::DROP_WITH_CONTENT, self::DROP_TAG));
        $text = preg_replace('!</?(' . $tags . ')\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>?!i', '', $text);

        // Unvollständiges Tag (kein ">" oder ein unpaariges Anführungszeichen): im
        // Browser schlösse es das nächste ">" der umgebenden Seite - samt allen
        // Attributen darin. Das "<" wird maskiert, der Text bleibt ("a<b dann" ->
        // "a&lt;b dann").
        $text = preg_replace_callback('~<(/?[a-z][a-z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)(>)?~i',
            fn(array $m) => isset($m[3]) ? $m[0] : '&lt;' . substr($m[0], 1), $text);

        // Whitelist
        $allowed = $this->allowedTags();
        // Jedes Tag: zuerst quote-bewusst (">" im Attributwert), danach einfach bis
        // zum ersten ">" (fängt Tags mit unpaarigen Anführungszeichen)
        foreach (['!<(/?)([a-z][a-z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>!i', '!<(/?)([a-z][a-z0-9:-]*)([^>]*)>!i'] as $pattern) {
            $text = preg_replace_callback($pattern, function (array $m) use ($allowed): string {
                $name = strtolower($m[2]);
                if ($allowed !== [] && !in_array($name, $allowed, true)) return '';
                if ($m[1] === '/' || trim($m[3]) === '' || trim($m[3]) === '/') return $m[0];
                $attrs = $this->attributes($m[3]);
                return $attrs === null ? $m[0] : '<' . $m[2] . $attrs . '>';
            }, $text);
        }
        return $text;
    }

    /**
     * Attribute eines Tags prüfen. null = alles in Ordnung (Tag bleibt wörtlich),
     * sonst der neu zusammengesetzte Attribut-Teil.
     */
    private function attributes(string $attrText): ?string
    {
        // Trenner sind Whitespace UND "/" (wie im Browser)
        preg_match_all('!([^\s"\'>/=]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*))?|(/\s*$)!', $attrText, $all, PREG_SET_ORDER);
        $changed = false;
        $out = '';
        foreach ($all as $a) {
            if (!empty($a[3])) { $out .= ' /'; continue; }
            $attrName = strtolower($a[1]);
            $raw = $a[2] ?? '';
            if (str_starts_with($attrName, 'on') || in_array($attrName, self::DROP_ATTRIBUTES, true)) {
                $changed = true;
                continue;
            }
            if ($raw !== '' && $this->dangerousUrl($raw)) {
                $changed = true;
                $out .= ' ' . $a[1] . '=""';
                continue;
            }
            $out .= ' ' . $a[1] . ($raw !== '' ? '=' . $raw : '');
        }
        return $changed ? $out : null;
    }

    /**
     * Enthält ein Attributwert eine gefährliche URL? Entities (auch ohne ";") und
     * Steuer-/Leerzeichen werden vorher aufgelöst bzw. entfernt ("java&#9;script:").
     */
    private function dangerousUrl(string $raw): bool
    {
        $value = trim($raw, '"\'');
        if (!str_contains($value, ':') && !str_contains($value, '&')) return false;
        $value = preg_replace(['~&#([0-9]+)(?![0-9;])~', '~&#(x[0-9a-f]+)(?![0-9a-f;])~i'], '&#$1;', $value);
        $plain = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = strtolower(preg_replace('![\x00-\x20\x7f]+!', '', $plain));
        if (str_contains($plain, 'javascript:') || str_contains($plain, 'vbscript:')) return true;
        if (str_starts_with($plain, 'data:')) {
            return !preg_match('!^data:image/(png|gif|jpe?g|webp|avif|bmp)[;,]!', $plain);
        }
        return false;
    }

    /** Whitelist aus system.html_allowed_tags (Array oder Kommaliste), [] = aus */
    private function allowedTags(): array
    {
        if ($this->allowedTags === null) {
            $list = $this->config->get('system.html_allowed_tags') ?? [];
            if (is_string($list)) $list = explode(',', $list);
            $this->allowedTags = array_values(array_filter(array_map(
                fn($t) => strtolower(trim((string)$t)), (array)$list)));
        }
        return $this->allowedTags;
    }

    /**
     * clean() auf einen Wert beliebiger Tiefe (Strings in Arrays, z. B. json,
     * Datei-Angaben, ganze Zeilen). Andere Typen bleiben unverändert.
     */
    public function cleanValue(mixed $value): mixed
    {
        if (is_string($value)) return $this->clean($value);
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_string($v) || is_array($v)) $value[$k] = $this->cleanValue($v);
            }
        }
        return $value;
    }
}
