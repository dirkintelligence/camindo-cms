<?php
/**
 * Removes active content from HTML - when writing and when reading
 * database content (developer docs, chapter 14 (Security: web)). Instance name: $htmlSanitizer.
 *
 * Callers: Cms::_sanitize_html() / Cms::_sanitizeValue() (Dataset::validateValue(),
 * Snippets::save(), Cms::fetch(), VirtualFieldsTrait::virtualSchema()) and
 * InstanceCache (instance and language labels) - there without Cms, because Cms
 * itself needs InstanceCache.
 */
namespace Camindo\Core;

class HtmlSanitizer {

    // Tags that clean() ALWAYS removes. With content: the content is code
    // or a document of its own. Tag only: empty or without meaningful content.
    // SVG animations (set, animate…) can set event handlers and
    // href via attributeName without an on… attribute being present.
    private const DROP_WITH_CONTENT = ['script', 'iframe', 'object', 'applet', 'frameset', 'noembed', 'noframes'];
    private const DROP_TAG = ['embed', 'base', 'meta', 'frame', 'set', 'animate', 'animatetransform', 'animatemotion', 'handler', 'listener'];
    // These attributes are always dropped (srcdoc: a document in the same origin)
    private const DROP_ATTRIBUTES = ['srcdoc', 'formaction', 'xlink:href'];
    /** Cache of the whitelist system.html_allowed_tags (null = not read yet) */
    private ?array $allowedTags = null;

    public function __construct(private Config $config)
    {
    }

    /**
     * Removes active content from HTML, leaves formatting in place.
     *
     * When WRITING: Dataset::validateValue() for every text value (exception: module
     * Template, see Dataset::$sanitizeHtml), Snippets::save().
     * When READING: Cms::fetch() for every row (except `template`) - against content that
     * reached the database past the application (developer docs, chapter 14 (Security: web)).
     *
     * Removed - not masked:
     *  - script, iframe, object, applet, frameset, noembed, noframes including content
     *  - embed, base, meta, frame and the SVG animations set/animate… (tag only)
     *  - event handlers (on…), srcdoc, formaction, xlink:href
     *  - javascript:/vbscript: URLs in every attribute value, data: URLs except
     *    data:image/(png|gif|jpeg|webp|avif|bmp) - also with entities/control characters
     *  - with system.html_allowed_tags (list of tag names): every other tag (the
     *    content stays)
     *
     * The browser also separates attributes with "/" (<img/onerror=…>), and a ">" in
     * an attribute value does not end the tag - the tag detection respects both.
     * Runs until nothing changes anymore (<scr<script>ipt> would otherwise yield <script>).
     * Without "<" the function returns immediately; for clean HTML it returns the
     * text unchanged (no reformatting), so it can be applied any number of times.
     *
     * @param string $text  text with HTML
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
     * Quick pre-check: nothing that round() would change? No whitelist,
     * none of the signal words (tags of the block list, on…=, srcdoc, formaction,
     * xlink:href, script:, data:, entities in numeric form) and every "<"+letter
     * starts a complete tag. If that holds, the text stays verbatim.
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
        // Elements including content; without a closing tag, script runs to the end
        $withContent = implode('|', self::DROP_WITH_CONTENT);
        $text = preg_replace('!<(' . $withContent . ')\b[^>]*>.*?</\1\s*>!is', '', $text);
        $text = preg_replace('!<script\b.*$!is', '', $text);
        // Remaining opening/closing tags of these elements and the tag list
        $tags = implode('|', array_merge(self::DROP_WITH_CONTENT, self::DROP_TAG));
        $text = preg_replace('!</?(' . $tags . ')\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>?!i', '', $text);

        // Incomplete tag (no ">" or an unpaired quotation mark): in the
        // browser it would close at the next ">" of the surrounding page - including all
        // attributes in it. The "<" is escaped, the text remains ("a<b then" ->
        // "a&lt;b then").
        $text = preg_replace_callback('~<(/?[a-z][a-z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)(>)?~i',
            fn(array $m) => isset($m[3]) ? $m[0] : '&lt;' . substr($m[0], 1), $text);

        // Allowlist
        $allowed = $this->allowedTags();
        // Each tag: first quote-aware (">" in an attribute value), then simply up to
        // the first ">" (catches tags with unpaired quotation marks)
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
     * Check the attributes of a tag. null = all fine (tag stays verbatim),
     * otherwise the newly assembled attribute part.
     */
    private function attributes(string $attrText): ?string
    {
        // Separators are whitespace AND "/" (as in the browser)
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
     * Does an attribute value contain a dangerous URL? Entities (also without ";") and
     * control/whitespace characters are resolved or removed beforehand ("java&#9;script:").
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

    /** Whitelist from system.html_allowed_tags (array or comma list), [] = off */
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
     * clean() on a value of any depth (strings in arrays, e.g. json,
     * file data, whole rows). Other types remain unchanged.
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
