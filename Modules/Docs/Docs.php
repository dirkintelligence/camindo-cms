<?php
/**
 * The manuals: Markdown files under Core/docs/{language}/, rendered with
 * Parsedown (erusev/parsedown), plus a full-text and keyword index in the database.
 * UI: DocsBackend (main menu → manuals).
 *
 * LOCATION
 * --------------------
 * The manuals belong to the core: whoever changes the core changes the docs in the same commit
 * (for the manuals of the components see below). The source is Core/docs/{language}/:
 *
 *     README.md                               overview
 *     {manual}/README.md, *.md                chapters (also in subdirectories,
 *                                             e.g. admins/extensions/)
 *     _/*.svg                                 icons used (Material Symbols) - for
 *                                             viewing on GitHub; in the backend this
 *                                             becomes the icon font
 * Manuals: editors, admins, templateprogrammers (BOOKS). The
 * developer documentation is not part of this. The language of the backend is shown
 * if available, otherwise en_US (language()).
 *
 * MANUALS OF THE COMPONENTS
 * --------------------
 * Each active component (module, extension, processor - runtime lists of the
 * ComponentManager) may bring its own manual:
 *
 *     {component}/docs/{language}/01-{kind}-{name}.md     start page, e.g.
 *                                                         Modules/Ai/docs/de_DE/01-module-ai.md
 *     {component}/docs/{language}/*.md                    further chapters (also in
 *                                                         subdirectories)
 *
 * {kind} is module, extension or processor, {name} the component name in
 * lowercase. Without a start page in the language there is no manual in that language.
 * Page paths are like the ones of the core manuals, "{manual}/{file}.md", with
 * the manual "{kind}-{name}" (books()); file() finds the file for it. The title of the
 * manual comes from the first heading of the start page. ComponentManager rebuilds the
 * index after each change to the runtime lists - with the component its manual comes and
 * goes.
 *
 * KEYWORDS
 * --------------------
 * ***Bold and italic*** marks a keyword (Parsedown: <strong><em>…</em></strong>);
 * plain **bold** is only emphasis. In addition, the headings from h2 on.
 * isTerm() filters out the obvious (numbered steps, "Attention" …).
 *
 * INDEX
 * --------------------
 * rebuildIndex() parses each file ONCE and writes:
 *
 *     doc_section   one entry per section (heading up to the next): page,
 *                   manual, title, slug, heading, level, plain text without any
 *                   formatting; FULLTEXT over heading + content
 *     doc_keyword   keyword -> section
 *
 * Called from Tools → Developer ("Index manuals"), `camindo.php cache
 * docs|all`, `upgrade` and the installer - so after a core update the index fits
 * automatically. In contrast, the displayed page is rendered on every request (Parsedown
 * needs only milliseconds for that).
 *
 * LINKS
 * --------------------
 * Relative links to .md files become calls to Docs/index.php (page=…,
 * anchor=…); anchors (#section) become jump targets. Headings get IDs like on
 * GitHub (slug()) with the prefix "doc-". Links outside the manuals stay plain
 * text, external ones open in a new window.
 *
 * CODE EXAMPLES WITH CMS COMMANDS
 * --------------------
 * Code blocks with the language cms (```cms) are colored by render() like the template editor:
 * commands purple, PHP code and variables orange (TemplateParserTrait::styleCode(),
 * classes c9-command/c9-php from backend.css). ```cmscode colors the whole block
 * purple without parsing - for syntax descriptions that are not a valid command:
 * (CMS:MESSAGE useval [parameter=value …]). Other blocks stay unchanged.
 *
 * PRINT VIEW
 * --------------------
 * bookPages() puts all pages of a manual together into one document: README.md, then the
 * pages in the order of their links from the README, subpages (e.g. extensions/)
 * after the page that links them. render() with $idPrefix keeps the anchors of the
 * pages apart (DocsBackend::printBook()).
 */
namespace Camindo\Core\Modules\Docs;

use Camindo\Core\Config;
use Camindo\Core\ContainerInterface;
use Camindo\Core\Database;
use Camindo\Core\Modules\Template\Template;

class Docs {

    /** The manuals, in the order of selection */
    public const BOOKS = ['editors', 'admins', 'templateprogrammers'];

    /** Language if the backend's language does not exist */
    public const FALLBACK_LANG = 'en_US';

    /** Kind of the component (runtime list) => prefix of its manual */
    public const COMPONENT_KINDS = ['modules' => 'module', 'extensions' => 'extension', 'processors' => 'processor'];

    /** Maximum number of search hits */
    public const MAX_HITS = 60;

    /**
     * Filler words of questions (de, en) - rank() omits them, so that "Wie lege ich einen
     * Bereich an?" (example) searches for "lege Bereich"
     */
    private const QUESTION_WORDS = ['aber', 'alle', 'als', 'auch', 'auf', 'aus', 'bei', 'bitte', 'dann', 'das', 'dass', 'dem',
        'den', 'der', 'des', 'die', 'dies', 'diese', 'dieser', 'eine', 'einem', 'einen', 'einer', 'eines', 'gibt', 'habe', 'hat',
        'ich', 'ihr', 'kann', 'man', 'meine', 'mich', 'mir', 'mit', 'nach', 'nicht', 'noch', 'nur', 'oder', 'sich', 'sie',
        'sind', 'und', 'unter', 'vom', 'von', 'was', 'welche', 'wenn', 'werden', 'wie', 'wir', 'wird', 'wo', 'zum', 'zur',
        'über', 'can', 'does', 'what', 'where', 'which', 'with', 'you', 'your'];

    /** Words that are set in bold without being a keyword (emphasis) */
    private const NOT_TERMS = ['achtung', 'hinweis', 'tipp', 'wichtig', 'beispiel', 'note', 'warning', 'tip', 'example', 'important'];

    /** Stop words of the InnoDB full-text search - worthless in search (MATCH would find nothing) */
    private const FT_STOPWORDS = ['about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this', 'was',
        'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];

    /** books() per language */
    private array $books = [];

    public function __construct(
        private Config $config,
        private Database $db,
        private ContainerInterface $container,
    )
    {
    }

    // ====================
    // Location

    /** Core/docs */
    public function baseDir(): string
    {
        return rtrim((string)$this->config->get('paths.core_dir'), '/') . '/docs';
    }

    /** Available languages (directories with README.md) */
    public function languages(): array
    {
        $langs = [];
        foreach (glob($this->baseDir() . '/*/README.md') ?: [] as $file) {
            $langs[] = basename(dirname($file));
        }
        sort($langs);
        return $langs;
    }

    /**
     * The manuals of a language: first those of the core (BOOKS), then those of the active
     * components (see class comment), sorted by title.
     *
     * @return array  manual => ['dir' => directory, 'start' => start page relative to it,
     *                'component' => '' or "Modules/Ai", 'title' => '' or title of the start page]
     */
    public function books(string $lang): array
    {
        if (isset($this->books[$lang])) return $this->books[$lang];
        $books = [];
        foreach (self::BOOKS as $book) {
            $dir = $this->baseDir() . '/' . $lang . '/' . $book;
            if (is_file($dir . '/README.md')) {
                $books[$book] = ['dir' => $dir, 'start' => 'README.md', 'component' => '', 'title' => ''];
            }
        }
        $components = [];
        foreach ($this->components() as $kind => $list) {
            foreach ($list as $name => $entry) {
                $book = self::COMPONENT_KINDS[$kind] . '-' . strtolower((string)$name);
                $dir = rtrim((string)($entry['path'] ?? ''), '/') . '/docs/' . $lang;
                $start = '01-' . $book . '.md';
                if (!preg_match('/^[a-z]+-[a-z0-9]+$/', $book) || !is_file($dir . '/' . $start)) continue;
                $components[$book] = ['dir' => $dir, 'start' => $start,
                    'component' => \Camindo\Core\ComponentManager::KINDS[$kind] . '/' . $name,
                    'title' => $this->fileTitle($dir . '/' . $start) ?? (string)$name];
            }
        }
        $collator = class_exists(\Collator::class) ? new \Collator($lang) : null;
        uasort($components, fn($a, $b) => $collator ? $collator->compare($a['title'], $b['title']) : strcmp($a['title'], $b['title']));
        return $this->books[$lang] = $books + $components;
    }

    /**
     * Active components from the runtime lists (var/config/components/) - freshly
     * read, not from Cms: after a change by the ComponentManager the new state applies
     * immediately.
     *
     * @return array  modules|extensions|processors => name => entry (incl. 'path')
     */
    private function components(): array
    {
        $lists = new class {
            public array $modules = [];
            public array $extensions = [];
            public array $processors = [];
            public function load(string $file): void { include $file; }
        };
        $dir = rtrim((string)$this->config->get('paths.var_dir'), '/') . '/config/components/';
        foreach (array_keys(self::COMPONENT_KINDS) as $kind) {
            if (is_file($dir . $kind . '.php')) $lists->load($dir . $kind . '.php');
        }
        return ['modules' => $lists->modules, 'extensions' => $lists->extensions, 'processors' => $lists->processors];
    }

    /** Start page of a manual as page path, e.g. "module-ai/01-module-ai.md"; null: unknown */
    public function startPage(string $lang, string $book): ?string
    {
        $books = $this->books($lang);
        return isset($books[$book]) ? $book . '/' . $books[$book]['start'] : null;
    }

    /** File of a page (checked with page()) */
    public function file(string $lang, string $page): string
    {
        $book = self::bookOf($page);
        if ($book === '') return $this->baseDir() . '/' . $lang . '/' . $page;
        return ($this->books($lang)[$book]['dir'] ?? '/nonexistent') . '/' . substr($page, strlen($book) + 1);
    }

    /** Title of a page (first heading) without rendering it completely; null: no heading */
    public function pageTitle(string $lang, string $page): ?string
    {
        return $this->fileTitle($this->file($lang, $page));
    }

    /** First heading of a Markdown file as plain text (code blocks do not count) */
    private function fileTitle(string $file): ?string
    {
        $fence = false;
        foreach (preg_split('/\R/', (string)@file_get_contents($file)) ?: [] as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) $fence = !$fence;
            if (!$fence && preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/', $line, $m)) return self::plain($this->markdown($m[1]));
        }
        return null;
    }

    /** Language of the manuals for the backend language $lang (otherwise FALLBACK_LANG) */
    public function language(string $lang): string
    {
        $lang = preg_replace('/[^A-Za-z_]/', '', $lang);
        return ($lang !== '' && is_file($this->baseDir() . '/' . $lang . '/README.md')) ? $lang : self::FALLBACK_LANG;
    }

    /**
     * Check and normalize a page path: "README.md" or "{manual}/[…/]{file}.md"
     * (manual from books()).
     *
     * @return string|null  null: invalid or not present
     */
    public function page(string $lang, string $page): ?string
    {
        $page = ltrim(str_replace('\\', '/', $page), '/');
        if ($page === '') $page = 'README.md';
        if (!preg_match('~^(?:([a-z][a-z0-9-]*)/)?((?:[a-z0-9_-]+/)*[A-Za-z0-9_-][A-Za-z0-9._-]*\.md)$~', $page, $m)) return null;
        if ($m[1] !== '' && !isset($this->books($lang)[$m[1]])) return null;
        if ($m[1] === '' && str_contains($m[2], '/')) return null;
        return is_file($this->file($lang, $page)) ? $page : null;
    }

    /** Manual of a page ('' = overview) */
    public static function bookOf(string $page): string
    {
        return str_contains($page, '/') ? explode('/', $page, 2)[0] : '';
    }

    // ====================
    // Rendering

    /**
     * Page as HTML.
     *
     * @param callable $link      fn(string $page, string $anchor): ?string - attributes
     *                            of the <a> tag including href for a link to another
     *                            manual page (or $page = '' for an anchor of the same
     *                            page, $page with '?' for parameters of the doc page, e.g.
     *                            ?lang=de_DE); null: only the link text remains
     * @param string   $idPrefix  before the slug of the heading IDs (doc-{prefix}{slug}),
     *                            if several pages are in one document
     * @return array              title, html
     */
    public function render(string $lang, string $page, callable $link, string $idPrefix = ''): array
    {
        $html = $this->markdown((string)file_get_contents($this->file($lang, $page)));
        $html = $this->cmsCode($html);
        $html = $this->headingIds($html, $idPrefix);
        $html = $this->icons($html);
        $html = $this->links($html, $lang, $page, $link);
        return ['title' => $this->title($html, $page), 'html' => $html];
    }

    /** Markdown -> HTML (Core\Markdown: safe mode, HTML is masked, only <br> remains - e.g. in table cells) */
    public function markdown(string $markdown): string
    {
        return (new \Camindo\Core\Markdown())->text($markdown);
    }

    /**
     * Color ```cms blocks like in the template editor (TemplateParserTrait::styleCode()).
     * Parsedown has already masked the content - convert it back; styleCode() masks
     * it again itself. ```cmscode blocks are not parsed, but colored purple as a whole
     * (c9-command) - the content stays masked as Parsedown delivers it.
     */
    private function cmsCode(string $html): string
    {
        if (!str_contains($html, '<code class="language-cms')) return $html;
        $template = null;
        return preg_replace_callback('~<pre><code class="language-(cms|cmscode)">(.*?)</code></pre>~s', function ($m) use (&$template) {
            if ($m[1] === 'cmscode') {
                return '<pre><code><span class="c9-command">' . $m[2] . '</span></code></pre>';
            }
            $template ??= $this->container->get(Template::class);
            $code = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return '<pre><code>' . $template->styleCode($code) . '</code></pre>';
        }, $html) ?? $html;
    }

    /**
     * Anchors like GitHub: lowercase, punctuation removed (letters of all scripts,
     * digits, - and _ stay), spaces become "-".
     */
    public static function slug(string $text): string
    {
        $text = mb_strtolower(trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $text = preg_replace('/[^\p{L}\p{N}\p{M} _-]/u', '', $text) ?? '';
        return str_replace(' ', '-', $text);
    }

    /** Give headings id="doc-{prefix}{slug}", duplicates with -1, -2 … */
    private function headingIds(string $html, string $prefix = ''): string
    {
        $seen = [];
        return preg_replace_callback('~<h([1-6])>(.*?)</h\1>~s', function ($m) use (&$seen, $prefix) {
            $slug = self::slug($m[2]);
            $count = $seen[$slug] ?? 0;
            $seen[$slug] = $count + 1;
            if ($count > 0) $slug .= '-' . $count;
            return '<h' . $m[1] . ' id="doc-' . htmlspecialchars($prefix . $slug) . '">' . $m[2] . '</h' . $m[1] . '>';
        }, $html) ?? $html;
    }

    /** Images …/_/{name}.svg -> symbol of the icon font; other images -> alt text */
    private function icons(string $html): string
    {
        return preg_replace_callback('~<img\s[^>]*>~', function ($m) {
            $src = preg_match('~\ssrc="([^"]*)"~', $m[0], $s) ? html_entity_decode($s[1]) : '';
            $alt = preg_match('~\salt="([^"]*)"~', $m[0], $a) ? $a[1] : '';
            if (preg_match('~(?:^|/)_/([a-z0-9_]+)\.svg$~', $src, $icon)) {
                return '<span class="icon c9-docs-icon" aria-hidden="true">' . $icon[1] . '</span>';
            }
            return $alt;
        }, $html) ?? $html;
    }

    /** Rewrite links (see class comment) */
    private function links(string $html, string $lang, string $page, callable $link): string
    {
        $dir = str_contains($page, '/') ? dirname($page) : '';
        return preg_replace_callback('~<a href="([^"]*)"([^>]*)>(.*?)</a>~s', function ($m) use ($lang, $dir, $link) {
            $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = $m[3];
            if (preg_match('~^(https?:|mailto:)~i', $href)) {
                $target = str_starts_with(strtolower($href), 'mailto:') ? '' : ' target="_blank" rel="noopener noreferrer"';
                return '<a href="' . htmlspecialchars($href) . '"' . $target . '>' . $text . '</a>';
            }
            [$path, $anchor] = array_pad(explode('#', $href, 2), 2, '');
            if (str_starts_with($href, '?')) {
                [$target, $anchor] = [$href, ''];
            }
            else {
                $target = $path === '' ? '' : $this->resolve($lang, $dir, $path);
                if ($target === null) return $text;
            }
            $attributes = $link($target, $anchor);
            return $attributes === null ? $text : '<a' . $attributes . '>' . $text . '</a>';
        }, $html) ?? $html;
    }

    /**
     * The pages of a manual in reading order (see class comment): the
     * start page (README.md, for components 01-{kind}-{name}.md), the links from lists
     * and tables of the start page (references in running text such as "read chapter 2 first"
     * do not count), then every other page after its siblings in the same
     * subdirectory, otherwise after the first page that links it. Never linked
     * pages at the end (by file name - numbered chapters stay in their sequence).
     * Basis of the print view and the links "Back"/"Next".
     *
     * @return string[]  page paths, e.g. "admins/README.md"; empty: no manual
     */
    public function bookPages(string $lang, string $book): array
    {
        $readme = $this->startPage($lang, $book);
        if ($readme === null) return [];
        $all = array_values(array_filter($this->files($lang), fn($page) => self::bookOf($page) === $book));
        if (!in_array($readme, $all, true)) return [];
        $links = [];
        foreach ($all as $page) {
            $links[$page] = array_values(array_intersect($this->pageLinks($lang, $page), $all));
        }
        $contents = array_intersect($this->pageLinks($lang, $readme, true), $all);
        $order = array_values(array_unique(array_merge([$readme], $contents)));
        $rest = array_diff($all, $order);
        // Attach remaining pages, as long as a newly placed page links further ones
        do {
            $added = false;
            foreach ($rest as $index => $page) {
                $dir = dirname($page);
                $position = null;
                if ($dir !== $book) {
                    foreach ($order as $candidatePosition => $candidate) {
                        if (dirname($candidate) === $dir) $position = $candidatePosition;
                    }
                }
                if ($position === null) {
                    foreach ($order as $candidatePosition => $candidate) {
                        if (!in_array($page, $links[$candidate], true)) continue;
                        $position = $candidatePosition;
                        break;
                    }
                }
                if ($position === null) continue;
                array_splice($order, $position + 1, 0, [$page]);
                unset($rest[$index]);
                $added = true;
            }
        } while ($added && $rest);
        return array_merge($order, array_values($rest));
    }

    /**
     * Targets of a page's links to other manual pages, in order, without anchors.
     *
     * @param bool $listsOnly  only links in lists and tables (including indented
     *                         continuation lines), not in running text
     */
    private function pageLinks(string $lang, string $page, bool $listsOnly = false): array
    {
        $source = (string)file_get_contents($this->file($lang, $page));
        $source = preg_replace('/^\s*(```|~~~).*?^\s*\1[^\n]*$/ms', '', $source) ?? $source;
        if ($listsOnly) {
            $source = implode("\n", preg_grep('/^(?:\s*(?:[-*+]|\d+[.)]|\|)\s|\s{2,}\S)/', preg_split('/\R/', $source) ?: []));
        }
        preg_match_all('/\]\(\s*<?([^)\s>#]+\.md)(?:#[^)\s>]*)?>?(?:\s+"[^"]*")?\s*\)/', $source, $m);
        $dir = str_contains($page, '/') ? dirname($page) : '';
        $targets = [];
        foreach ($m[1] as $path) {
            $target = $this->resolve($lang, $dir, rawurldecode($path));
            if ($target !== null && $target !== $page && !in_array($target, $targets, true)) $targets[] = $target;
        }
        return $targets;
    }

    /** Identifier of a page for IDs: "admins/extensions/password.md" -> "admins-extensions-password" */
    public static function pageKey(string $page): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(preg_replace('/\.md$/i', '', $page) ?? '')) ?? '', '-');
    }

    /**
     * Resolve the relative path of a .md file - within the manuals, otherwise null.
     */
    private function resolve(string $lang, string $dir, string $path): ?string
    {
        if (!str_ends_with(strtolower($path), '.md')) return null;
        $parts = $dir === '' ? [] : explode('/', $dir);
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                if (!$parts) return null;
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        $target = implode('/', $parts);
        if (count($parts) === 1) return $target === 'README.md' ? $target : null;
        return isset($this->books($lang)[$parts[0]]) ? $target : null;
    }

    /** Title: the first heading, otherwise the file name */
    private function title(string $html, string $page): string
    {
        if (preg_match('~<h[1-6][^>]*>(.*?)</h[1-6]>~s', $html, $m)) {
            return self::plain($m[1]);
        }
        return basename($page, '.md');
    }

    /** HTML -> plain text in one line */
    private static function plain(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    // ====================
    // Index (database)

    /**
     * Rebuild the index (see class comment) - for one or all languages.
     *
     * @param string $lang  '' = all languages under Core/docs
     * @return array        per language ['pages' => n, 'sections' => n, 'keywords' => n]
     */
    public function rebuildIndex(string $lang = ''): array
    {
        // Components may have come or gone since the last call
        $this->books = [];
        $result = [];
        foreach ($lang === '' ? $this->languages() : [$lang] as $language) {
            $pages = $this->files($language);
            $sections = 0;
            $keywords = 0;
            $this->db->beginTransaction();
            try {
                $this->db->query("DELETE FROM `doc_section` WHERE `lang` = ?", 's', [$language]);
                foreach ($pages as $page) {
                    [$s, $k] = $this->indexPage($language, $page);
                    $sections += $s;
                    $keywords += $k;
                }
                $this->db->commit();
            }
            catch (\Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
            $result[$language] = ['pages' => count($pages), 'sections' => $sections, 'keywords' => $keywords];
        }
        return $result;
    }

    /**
     * Split a page into sections and enter them.
     *
     * @return array  [sections, keywords]
     */
    private function indexPage(string $lang, string $page): array
    {
        $html = $this->headingIds($this->markdown((string)file_get_contents($this->file($lang, $page))));
        $title = $this->title($html, $page);
        $book = self::bookOf($page);
        $sections = 0;
        $keywords = 0;
        // Split at the headings; what comes before belongs to the first
        foreach (preg_split('~(?=<h[1-6] id=")~', $html) ?: [$html] as $position => $chunk) {
            $slug = '';
            $heading = '';
            $level = 0;
            if (preg_match('~^<h([1-6]) id="doc-([^"]*)">(.*?)</h\1>~s', $chunk, $h)) {
                $level = (int)$h[1];
                $slug = html_entity_decode($h[2]);
                $heading = self::plain($h[3]);
            }
            $content = self::plain($chunk);
            if ($content === '') continue;
            $this->db->query(
                "INSERT INTO `doc_section` (`lang`, `page`, `book`, `position`, `title`, `slug`, `heading`, `level`, `content`)"
                . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                'sssisssis', [$lang, $page, $book, $position, mb_substr($title, 0, 255), mb_substr($slug, 0, 255),
                    mb_substr($heading, 0, 255), $level, $content]
            );
            $sectionId = (int)$this->db->insert_id;
            $sections++;

            // Keywords: ***bold+italic*** and headings from h2 (only in the manuals)
            if ($book === '') continue;
            $terms = [];
            if (preg_match_all('~<strong><em>(.*?)</em></strong>|<em><strong>(.*?)</strong></em>~s', $chunk, $b)) {
                foreach (array_merge($b[1], $b[2]) as $term) {
                    $term = trim(self::plain($term), " .,:;!?–-\"'`„“");
                    if (self::isTerm($term)) $terms[mb_strtolower($term)] = $term;
                }
            }
            if ($level > 1 && self::isTerm($heading)) $terms[mb_strtolower($heading)] = $heading;
            foreach ($terms as $term) {
                $this->db->query("INSERT INTO `doc_keyword` (`lang`, `term`, `section_id`) VALUES (?, ?, ?)",
                    'ssi', [$lang, mb_substr($term, 0, 190), $sectionId]);
                $keywords++;
            }
        }
        return [$sections, $keywords];
    }

    /** Is there an index for this language? */
    public function indexed(string $lang): bool
    {
        $this->db->query("SELECT 1 FROM `doc_section` WHERE `lang` = ? LIMIT 1", 's', [$lang]);
        return $this->db->num_rows > 0;
    }

    /**
     * Is a term or heading suitable as a keyword? Not: empty or very long,
     * numbered steps ("1. …"), pure emphasis ("Attention"), values and code
     * without uppercase letters.
     */
    public static function isTerm(string $term): bool
    {
        $length = mb_strlen($term);
        if ($length < 3 || $length > 60) return false;
        if (preg_match('/^\d/u', $term)) return false;
        if (!preg_match('/\p{Lu}/u', $term)) return false;
        return !in_array(mb_strtolower($term), self::NOT_TERMS, true);
    }

    /**
     * Full-text search in the index: all words (from three characters, as word start) must
     * occur in the section. Hits in headings count more. If the search contains only
     * short words, LIKE searches the plain text.
     *
     * @param string $book  '' = all manuals
     * @return array        list of page, title, slug, heading, excerpt (plain text)
     */
    public function search(string $lang, string $query, string $book = ''): array
    {
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower(trim($query))) ?: [],
            fn($w) => $w !== ''
        ));
        if (!$words) return [];
        $ftWords = array_values(array_filter($words, fn($w) => mb_strlen($w) >= 3 && !in_array($w, self::FT_STOPWORDS, true)));

        $where = "`lang` = ?";
        $types = 's';
        $values = [$lang];
        if ($book !== '') {
            $where .= " AND `book` = ?";
            $types .= 's';
            $values[] = $book;
        }
        if ($ftWords) {
            $against = implode(' ', array_map(fn($w) => '+' . $w . '*', $ftWords));
            // Relevance of the full-text search, plus a bonus if the first word is in the
            // heading
            $this->db->query("SELECT `page`, `title`, `slug`, `heading`, `content`,"
                . " MATCH(`heading`, `content`) AGAINST (? IN BOOLEAN MODE) + 5 * (`heading` LIKE ?) AS `score`"
                . " FROM `doc_section` WHERE $where AND MATCH(`heading`, `content`) AGAINST (? IN BOOLEAN MODE)"
                . " ORDER BY `score` DESC, `page`, `position` LIMIT " . self::MAX_HITS,
                'ss' . $types . 's',
                array_merge([$against, '%' . addcslashes($ftWords[0], '%_\\') . '%'], $values, [$against]));
        }
        else {
            $like = [];
            foreach ($words as $word) {
                $like[] = "(`heading` LIKE ? OR `content` LIKE ?)";
                $types .= 'ss';
                $pattern = '%' . addcslashes($word, '%_\\') . '%';
                array_push($values, $pattern, $pattern);
            }
            $this->db->query("SELECT `page`, `title`, `slug`, `heading`, `content` FROM `doc_section`"
                . " WHERE $where AND " . implode(' AND ', $like) . " ORDER BY `page`, `position` LIMIT " . self::MAX_HITS,
                $types, $values);
        }
        $hits = [];
        while ($row = $this->db->fetch()) {
            $hits[] = [
                'page' => (string)$row['page'],
                'title' => (string)$row['title'],
                'slug' => (string)$row['slug'],
                'heading' => (string)$row['heading'],
                'excerpt' => $this->excerpt((string)$row['content'], $words[0]),
            ];
        }
        return $hits;
    }

    /**
     * Search by relevance - for the API and language models: unlike search(), not every
     * word has to occur; sections with more and rarer hits come first, a word in the
     * heading counts extra. Only short words: as search().
     *
     * @param string $book   '' = all manuals
     * @param int    $limit  maximum number of hits
     * @return array         list of page, title, slug, heading, excerpt (plain text)
     */
    public function rank(string $lang, string $query, string $book = '', int $limit = 10): array
    {
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower(trim($query))) ?: [],
            fn($w) => mb_strlen($w) >= 3 && !in_array($w, self::FT_STOPWORDS, true) && !in_array($w, self::QUESTION_WORDS, true)
        ));
        if (!$words) return array_slice($this->search($lang, $query, $book), 0, $limit);
        $words = array_slice(array_values(array_unique($words)), 0, 12);
        $against = implode(' ', array_map(fn($w) => $w . '*', $words));
        $bonus = implode(' + ', array_fill(0, count($words), '(`heading` LIKE ?)'));
        $where = "`lang` = ?";
        $types = 's' . str_repeat('s', count($words)) . 's';
        $values = array_merge([$against], array_map(fn($w) => '%' . addcslashes($w, '%_\\') . '%', $words), [$lang]);
        if ($book !== '') {
            $where .= " AND `book` = ?";
            $types .= 's';
            $values[] = $book;
        }
        $types .= 's';
        $values[] = $against;
        $this->db->query("SELECT `page`, `title`, `slug`, `heading`, `content`,"
            . " MATCH(`heading`, `content`) AGAINST (? IN BOOLEAN MODE) + 3 * ($bonus) AS `score`"
            . " FROM `doc_section` WHERE $where AND MATCH(`heading`, `content`) AGAINST (? IN BOOLEAN MODE)"
            . " ORDER BY `score` DESC, `page`, `position` LIMIT " . max(1, min(self::MAX_HITS, $limit)),
            $types, $values);
        $hits = [];
        while ($row = $this->db->fetch()) {
            $hits[] = [
                'page' => (string)$row['page'],
                'title' => (string)$row['title'],
                'slug' => (string)$row['slug'],
                'heading' => (string)$row['heading'],
                'excerpt' => $this->excerpt((string)$row['content'], $words[0]),
            ];
        }
        return $hits;
    }

    /**
     * Markdown source of a page or section (including subsections) -
     * more readable for language models than the plain text of the index (tables, lists).
     * The anchors are created as in headingIds() (code blocks do not count).
     *
     * @param string $anchor  slug of the heading ('' = whole page)
     * @return ?array         title, heading, markdown - null: page or anchor unknown
     */
    public function source(string $lang, string $page, string $anchor = ''): ?array
    {
        $page = $this->page($lang, $page);
        if ($page === null) return null;
        $lines = preg_split('/\R/', (string)file_get_contents($this->file($lang, $page))) ?: [];
        $headings = [];
        $seen = [];
        $fence = false;
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) $fence = !$fence;
            if ($fence || !preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) continue;
            $slug = self::slug($this->markdown($m[2]));
            $count = $seen[$slug] ?? 0;
            $seen[$slug] = $count + 1;
            if ($count > 0) $slug .= '-' . $count;
            $headings[] = ['line' => $index, 'level' => strlen($m[1]), 'slug' => $slug, 'text' => self::plain($this->markdown($m[2]))];
        }
        $title = $headings[0]['text'] ?? basename($page, '.md');
        $anchor = self::slug($anchor);
        if ($anchor === '') return ['title' => $title, 'heading' => '', 'markdown' => trim(implode("\n", $lines))];
        foreach ($headings as $position => $heading) {
            if ($heading['slug'] !== $anchor) continue;
            $end = count($lines);
            foreach (array_slice($headings, $position + 1) as $next) {
                if ($next['level'] <= $heading['level']) {
                    $end = $next['line'];
                    break;
                }
            }
            return ['title' => $title, 'heading' => $heading['text'],
                'markdown' => trim(implode("\n", array_slice($lines, $heading['line'], $end - $heading['line'])))];
        }
        return null;
    }

    /**
     * Keyword index from the index, alphabetically.
     *
     * @param string $book  '' = all manuals
     * @return array        term => list of page, title, slug, heading
     */
    public function keywords(string $lang, string $book = ''): array
    {
        $sql = "SELECT `k`.`term`, `s`.`page`, `s`.`title`, `s`.`slug`, `s`.`heading` FROM `doc_keyword` `k`"
            . " JOIN `doc_section` `s` ON `s`.`section_id` = `k`.`section_id` WHERE `k`.`lang` = ?";
        $types = 's';
        $values = [$lang];
        if ($book !== '') {
            $sql .= " AND `s`.`book` = ?";
            $types .= 's';
            $values[] = $book;
        }
        $this->db->query($sql . " ORDER BY `s`.`page`, `s`.`position`", $types, $values);
        $keywords = [];
        while ($row = $this->db->fetch()) {
            $key = mb_strtolower((string)$row['term']);
            $keywords[$key]['term'] ??= (string)$row['term'];
            $keywords[$key]['refs'][] = [
                'page' => (string)$row['page'],
                'title' => (string)$row['title'],
                'slug' => (string)$row['slug'],
                'heading' => (string)$row['heading'],
            ];
        }
        $collator = class_exists(\Collator::class) ? new \Collator($lang) : null;
        uksort($keywords, fn($a, $b) => $collator ? $collator->compare($a, $b) : strcmp($a, $b));
        $result = [];
        foreach ($keywords as $entry) {
            $result[$entry['term']] = $entry['refs'];
        }
        return $result;
    }

    /** Text excerpt around the first occurrence of $word */
    private function excerpt(string $text, string $word, int $length = 200): string
    {
        $pos = mb_stripos($text, $word);
        $start = $pos === false ? 0 : max(0, $pos - 60);
        $excerpt = mb_substr($text, $start, $length);
        return ($start > 0 ? '…' : '') . $excerpt . (mb_strlen($text) > $start + $length ? '…' : '');
    }

    /** All pages of a language: README.md and the .md files of the manuals (books()) */
    private function files(string $lang): array
    {
        $files = is_file($this->baseDir() . '/' . $lang . '/README.md') ? ['README.md'] : [];
        foreach ($this->books($lang) as $book => $info) {
            if (!is_dir($info['dir'])) continue;
            $found = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($info['dir'], \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                    $found[] = $book . '/' . substr($file->getPathname(), strlen($info['dir']) + 1);
                }
            }
            sort($found);
            array_push($files, ...$found);
        }
        return $files;
    }
}
