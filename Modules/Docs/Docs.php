<?php
/**
 * Die Handbücher: Markdown-Dateien unter Core/docs/{Sprache}/, gerendert mit
 * Parsedown (erusev/parsedown), dazu Volltext- und Stichwortindex in der Datenbank.
 * Oberfläche: DocsBackend (Hauptmenü → Handbücher).
 *
 * ABLAGE
 * --------------------
 * Die Handbücher gehören zum Core: wer den Core ändert, ändert im selben Commit die
 * Doku (zu den Handbüchern der Komponenten siehe unten). Quelle ist Core/docs/{Sprache}/:
 *
 *     README.md                               Übersicht
 *     {Handbuch}/README.md, *.md              Kapitel (auch in Unterverzeichnissen,
 *                                             z. B. admins/extensions/)
 *     _/*.svg                                 benutzte Icons (Material Symbols) - für
 *                                             die Ansicht auf GitHub; im Backend wird
 *                                             daraus die Icon-Schrift
 * Handbücher: editors, admins, templateprogrammers (BOOKS). Die
 * Entwicklerdokumentation gehört nicht dazu. Gezeigt wird die Sprache des Backends,
 * wenn es sie gibt, sonst en_US (language()).
 *
 * HANDBÜCHER DER KOMPONENTEN
 * --------------------
 * Jede aktive Komponente (Modul, Extension, Prozessor - Laufzeitlisten des
 * ComponentManager) darf ein eigenes Handbuch mitbringen:
 *
 *     {Komponente}/docs/{Sprache}/01-{art}-{name}.md     Startseite, z. B.
 *                                                         Modules/Ai/docs/de_DE/01-module-ai.md
 *     {Komponente}/docs/{Sprache}/*.md                    weitere Kapitel (auch in
 *                                                         Unterverzeichnissen)
 *
 * {art} ist module, extension oder processor, {name} der Komponentenname in
 * Kleinbuchstaben. Ohne Startseite in der Sprache gibt es das Handbuch in dieser Sprache
 * nicht. Seitenpfade lauten wie bei den Core-Handbüchern "{Handbuch}/{datei}.md" mit
 * dem Handbuch "{art}-{name}" (books()); file() findet die Datei dazu. Den Titel des
 * Handbuchs liefert die erste Überschrift der Startseite. ComponentManager baut den
 * Index nach jeder Änderung der Laufzeitlisten neu auf - mit der Komponente kommt und
 * geht ihr Handbuch.
 *
 * STICHWÖRTER
 * --------------------
 * ***Fett und kursiv*** markiert ein Stichwort (Parsedown: <strong><em>…</em></strong>);
 * einfaches **Fett** ist nur Hervorhebung. Dazu kommen die Überschriften ab h2.
 * isTerm() sortiert Offensichtliches aus (nummerierte Schritte, "Achtung" …).
 *
 * INDEX
 * --------------------
 * rebuildIndex() parst jede Datei EINMAL und schreibt:
 *
 *     doc_section   ein Eintrag je Abschnitt (Überschrift bis zur nächsten): Seite,
 *                   Handbuch, Titel, Slug, Überschrift, Ebene, Klartext ohne jede
 *                   Formatierung; FULLTEXT über heading + content
 *     doc_keyword   Stichwort -> Abschnitt
 *
 * Aufgerufen von Tools → Entwickler ("Handbücher indexieren"), `camindo.php cache
 * docs|all`, `upgrade` und dem Installer - nach einem Core-Update passt der Index also
 * von selbst. Gerendert wird die angezeigte Seite dagegen bei jedem Aufruf (Parsedown
 * braucht dafür nur Millisekunden).
 *
 * LINKS
 * --------------------
 * Relative Links auf .md-Dateien werden zu Aufrufen von Docs/index.php (page=…,
 * anchor=…); Anker (#abschnitt) zu Sprungzielen. Überschriften bekommen Ids wie auf
 * GitHub (slug()) mit dem Präfix "doc-". Links außerhalb der Handbücher bleiben reiner
 * Text, externe öffnen in einem neuen Fenster.
 *
 * CODEBEISPIELE MIT CMS-BEFEHLEN
 * --------------------
 * Codeblöcke mit der Sprache cms (```cms) färbt render() wie den Template-Editor ein:
 * Befehle lila, PHP-Code und Variablen orange (TemplateParserTrait::styleCode(),
 * Klassen c9-command/c9-php aus backend.css). ```cmscode färbt den ganzen Block
 * lila, ohne zu parsen - für Syntaxbeschreibungen, die kein gültiger Befehl sind:
 * (CMS:MESSAGE useval [parameter=wert …]). Andere Blöcke bleiben unverändert.
 *
 * DRUCKANSICHT
 * --------------------
 * bookPages() reiht alle Seiten eines Handbuchs zu einem Dokument: README.md, dann die
 * Seiten in der Reihenfolge ihrer Links aus der README, Unterseiten (z. B. extensions/)
 * hinter der Seite, die sie verlinkt. render() mit $idPrefix hält die Anker der
 * Seiten dabei auseinander (DocsBackend::printBook()).
 */
namespace Camindo\Core\Modules\Docs;

use Camindo\Core\Config;
use Camindo\Core\ContainerInterface;
use Camindo\Core\Database;
use Camindo\Core\Modules\Template\Template;

class Docs {

    /** Die Handbücher, in der Reihenfolge der Auswahl */
    public const BOOKS = ['editors', 'admins', 'templateprogrammers'];

    /** Sprache, wenn es die des Backends nicht gibt */
    public const FALLBACK_LANG = 'en_US';

    /** Art der Komponente (Laufzeitliste) => Präfix ihres Handbuchs */
    public const COMPONENT_KINDS = ['modules' => 'module', 'extensions' => 'extension', 'processors' => 'processor'];

    /** Höchstzahl Treffer der Suche */
    public const MAX_HITS = 60;

    /**
     * Füllwörter von Fragen (de, en) - rank() lässt sie weg, damit "Wie lege ich einen
     * Bereich an?" nach "lege Bereich" sucht
     */
    private const QUESTION_WORDS = ['aber', 'alle', 'als', 'auch', 'auf', 'aus', 'bei', 'bitte', 'dann', 'das', 'dass', 'dem',
        'den', 'der', 'des', 'die', 'dies', 'diese', 'dieser', 'eine', 'einem', 'einen', 'einer', 'eines', 'gibt', 'habe', 'hat',
        'ich', 'ihr', 'kann', 'man', 'meine', 'mich', 'mir', 'mit', 'nach', 'nicht', 'noch', 'nur', 'oder', 'sich', 'sie',
        'sind', 'und', 'unter', 'vom', 'von', 'was', 'welche', 'wenn', 'werden', 'wie', 'wir', 'wird', 'wo', 'zum', 'zur',
        'über', 'can', 'does', 'what', 'where', 'which', 'with', 'you', 'your'];

    /** Wörter, die fett gesetzt sind, ohne Stichwort zu sein (Hervorhebungen) */
    private const NOT_TERMS = ['achtung', 'hinweis', 'tipp', 'wichtig', 'beispiel', 'note', 'warning', 'tip', 'example', 'important'];

    /** Stoppwörter der InnoDB-Volltextsuche - in der Suche wertlos (MATCH fände nichts) */
    private const FT_STOPWORDS = ['about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this', 'was',
        'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];

    /** books() je Sprache */
    private array $books = [];

    public function __construct(
        private Config $config,
        private Database $db,
        private ContainerInterface $container,
    )
    {
    }

    // ====================
    // Ablage

    /** Core/docs */
    public function baseDir(): string
    {
        return rtrim((string)$this->config->get('paths.core_dir'), '/') . '/docs';
    }

    /** Vorhandene Sprachen (Verzeichnisse mit README.md) */
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
     * Die Handbücher einer Sprache: erst die des Core (BOOKS), dann die der aktiven
     * Komponenten (siehe Klassenkommentar), nach Titel sortiert.
     *
     * @return array  Handbuch => ['dir' => Verzeichnis, 'start' => Startseite relativ dazu,
     *                'component' => '' bzw. "Modules/Ai", 'title' => '' bzw. Titel der Startseite]
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
     * Aktive Komponenten aus den Laufzeitlisten (var/config/components/) - frisch
     * gelesen, nicht aus Cms: nach einer Änderung durch den ComponentManager gilt
     * sofort der neue Stand.
     *
     * @return array  modules|extensions|processors => Name => Eintrag (u. a. 'path')
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

    /** Startseite eines Handbuchs als Seitenpfad, z. B. "module-ai/01-module-ai.md"; null: unbekannt */
    public function startPage(string $lang, string $book): ?string
    {
        $books = $this->books($lang);
        return isset($books[$book]) ? $book . '/' . $books[$book]['start'] : null;
    }

    /** Datei einer (mit page() geprüften) Seite */
    public function file(string $lang, string $page): string
    {
        $book = self::bookOf($page);
        if ($book === '') return $this->baseDir() . '/' . $lang . '/' . $page;
        return ($this->books($lang)[$book]['dir'] ?? '/nonexistent') . '/' . substr($page, strlen($book) + 1);
    }

    /** Titel einer Seite (erste Überschrift) ohne sie ganz zu rendern; null: keine Überschrift */
    public function pageTitle(string $lang, string $page): ?string
    {
        return $this->fileTitle($this->file($lang, $page));
    }

    /** Erste Überschrift einer Markdown-Datei als Klartext (Codeblöcke zählen nicht) */
    private function fileTitle(string $file): ?string
    {
        $fence = false;
        foreach (preg_split('/\R/', (string)@file_get_contents($file)) ?: [] as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) $fence = !$fence;
            if (!$fence && preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/', $line, $m)) return self::plain($this->markdown($m[1]));
        }
        return null;
    }

    /** Sprache der Handbücher zur Backend-Sprache $lang (sonst FALLBACK_LANG) */
    public function language(string $lang): string
    {
        $lang = preg_replace('/[^A-Za-z_]/', '', $lang);
        return ($lang !== '' && is_file($this->baseDir() . '/' . $lang . '/README.md')) ? $lang : self::FALLBACK_LANG;
    }

    /**
     * Seitenpfad prüfen und normalisieren: "README.md" oder "{Handbuch}/[…/]{datei}.md"
     * (Handbuch aus books()).
     *
     * @return string|null  null: ungültig oder nicht vorhanden
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

    /** Handbuch einer Seite ('' = Übersicht) */
    public static function bookOf(string $page): string
    {
        return str_contains($page, '/') ? explode('/', $page, 2)[0] : '';
    }

    // ====================
    // Rendern

    /**
     * Seite als HTML.
     *
     * @param callable $link      fn(string $page, string $anchor): ?string - Attribute
     *                            des <a>-Tags samt href für einen Link auf eine andere
     *                            Handbuchseite (bzw. $page = '' für einen Anker derselben
     *                            Seite, $page mit '?' für Parameter der Doku-Seite, z. B.
     *                            ?lang=de_DE); null: nur der Linktext bleibt
     * @param string   $idPrefix  vor dem Slug der Überschriften-Ids (doc-{Präfix}{slug}),
     *                            wenn mehrere Seiten in einem Dokument stehen
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

    /** Markdown -> HTML (Core\Markdown: Safe Mode, HTML wird maskiert, nur <br> bleibt - z. B. in Tabellenzellen) */
    public function markdown(string $markdown): string
    {
        return (new \Camindo\Core\Markdown())->text($markdown);
    }

    /**
     * ```cms-Blöcke einfärben wie im Template-Editor (TemplateParserTrait::styleCode()).
     * Parsedown hat den Inhalt bereits maskiert - zurückwandeln, styleCode() maskiert
     * selbst wieder. ```cmscode-Blöcke werden nicht geparst, sondern als Ganzes lila
     * (c9-command) - der Inhalt bleibt so maskiert, wie Parsedown ihn liefert.
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
     * Anker wie GitHub: Kleinbuchstaben, Satzzeichen weg (Buchstaben aller Schriften,
     * Ziffern, - und _ bleiben), Leerzeichen zu "-".
     */
    public static function slug(string $text): string
    {
        $text = mb_strtolower(trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $text = preg_replace('/[^\p{L}\p{N}\p{M} _-]/u', '', $text) ?? '';
        return str_replace(' ', '-', $text);
    }

    /** Überschriften mit id="doc-{Präfix}{slug}" versehen, doppelte mit -1, -2 … */
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

    /** Bilder …/_/{name}.svg -> Symbol der Icon-Schrift; andere Bilder -> Alt-Text */
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

    /** Links umschreiben (siehe Klassenkommentar) */
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
     * Die Seiten eines Handbuchs in Lesereihenfolge (siehe Klassenkommentar): die
     * Startseite (README.md, bei Komponenten 01-{art}-{name}.md), die Links aus Listen
     * und Tabellen der Startseite (Verweise im Fließtext wie "lesen Sie zuerst Kapitel 2"
     * zählen nicht), dann jede übrige Seite hinter ihren Geschwistern im selben
     * Unterverzeichnis, sonst hinter der ersten Seite, die sie verlinkt. Nie verlinkte
     * Seiten am Ende (nach Dateinamen - nummerierte Kapitel stehen so in ihrer Folge).
     * Grundlage der Druckansicht und der Links "Zurück"/"Weiter".
     *
     * @return string[]  Seitenpfade, z. B. "admins/README.md"; leer: kein Handbuch
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
        // Übrige Seiten einhängen, solange eine neu eingereihte Seite weitere verlinkt
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
     * Ziele der Links einer Seite auf andere Handbuchseiten, in Reihenfolge, ohne Anker.
     *
     * @param bool $listsOnly  nur Links in Listen und Tabellen (samt eingerückten
     *                         Folgezeilen), nicht im Fließtext
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

    /** Kennung einer Seite für Ids: "admins/extensions/tinymce.md" -> "admins-extensions-tinymce" */
    public static function pageKey(string $page): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(preg_replace('/\.md$/i', '', $page) ?? '')) ?? '', '-');
    }

    /**
     * Relativen Pfad einer .md-Datei auflösen - innerhalb der Handbücher, sonst null.
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

    /** Titel: die erste Überschrift, sonst der Dateiname */
    private function title(string $html, string $page): string
    {
        if (preg_match('~<h[1-6][^>]*>(.*?)</h[1-6]>~s', $html, $m)) {
            return self::plain($m[1]);
        }
        return basename($page, '.md');
    }

    /** HTML -> Klartext in einer Zeile */
    private static function plain(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    // ====================
    // Index (Datenbank)

    /**
     * Index neu aufbauen (siehe Klassenkommentar) - für eine oder alle Sprachen.
     *
     * @param string $lang  '' = alle Sprachen unter Core/docs
     * @return array        je Sprache ['pages' => n, 'sections' => n, 'keywords' => n]
     */
    public function rebuildIndex(string $lang = ''): array
    {
        // Komponenten können seit dem letzten Aufruf gekommen oder gegangen sein
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
     * Eine Seite in Abschnitte zerlegen und eintragen.
     *
     * @return array  [Abschnitte, Stichwörter]
     */
    private function indexPage(string $lang, string $page): array
    {
        $html = $this->headingIds($this->markdown((string)file_get_contents($this->file($lang, $page))));
        $title = $this->title($html, $page);
        $book = self::bookOf($page);
        $sections = 0;
        $keywords = 0;
        // An den Überschriften zerlegen; was davor steht, gehört zur ersten
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

            // Stichwörter: ***fett+kursiv*** und Überschriften ab h2 (nur in den Handbüchern)
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

    /** Gibt es einen Index für diese Sprache? */
    public function indexed(string $lang): bool
    {
        $this->db->query("SELECT 1 FROM `doc_section` WHERE `lang` = ? LIMIT 1", 's', [$lang]);
        return $this->db->num_rows > 0;
    }

    /**
     * Taugt ein Begriff oder eine Überschrift als Stichwort? Nicht: leer oder sehr lang,
     * nummerierte Schritte ("1. …"), reine Hervorhebungen ("Achtung"), Werte und Code
     * ohne Großbuchstaben.
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
     * Volltextsuche im Index: alle Wörter (ab drei Zeichen, als Wortanfang) müssen im
     * Abschnitt vorkommen. Treffer in Überschriften zählen mehr. Enthält die Suche nur
     * kurze Wörter, sucht LIKE im Klartext.
     *
     * @param string $book  '' = alle Handbücher
     * @return array        Liste aus page, title, slug, heading, excerpt (Klartext)
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
            // Relevanz der Volltextsuche, dazu ein Bonus, wenn das erste Wort in der
            // Überschrift steht
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
     * Suche nach Relevanz - für die API und Sprachmodelle: anders als search() muss
     * nicht jedes Wort vorkommen; Abschnitte mit mehr und selteneren Treffern stehen
     * vorn, ein Wort in der Überschrift zählt extra. Nur kurze Wörter: wie search().
     *
     * @param string $book   '' = alle Handbücher
     * @param int    $limit  Höchstzahl Treffer
     * @return array         Liste aus page, title, slug, heading, excerpt (Klartext)
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
     * Markdown-Quelltext einer Seite oder eines Abschnitts (samt Unterabschnitten) -
     * für Sprachmodelle besser lesbar als der Klartext des Index (Tabellen, Listen).
     * Die Anker entstehen wie in headingIds() (Codeblöcke zählen nicht).
     *
     * @param string $anchor  Slug der Überschrift ('' = ganze Seite)
     * @return ?array         title, heading, markdown - null: Seite oder Anker unbekannt
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
     * Stichwortverzeichnis aus dem Index, alphabetisch.
     *
     * @param string $book  '' = alle Handbücher
     * @return array        Begriff => Liste aus page, title, slug, heading
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

    /** Textausschnitt um das erste Vorkommen von $word */
    private function excerpt(string $text, string $word, int $length = 200): string
    {
        $pos = mb_stripos($text, $word);
        $start = $pos === false ? 0 : max(0, $pos - 60);
        $excerpt = mb_substr($text, $start, $length);
        return ($start > 0 ? '…' : '') . $excerpt . (mb_strlen($text) > $start + $length ? '…' : '');
    }

    /** Alle Seiten einer Sprache: README.md und die .md-Dateien der Handbücher (books()) */
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
