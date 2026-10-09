<?php
/**
 * Backend-Oberfläche der Handbücher (Hauptmenü → Handbücher).
 *
 * Einstiegspunkt (Script in backend/):
 *
 *     index.php  -> page()        Seite, Trefferliste der Suche oder Stichwortverzeichnis
 *     print.php  -> printBook()   ein ganzes Handbuch als eigenständige HTML-Seite
 *                                 (neues Fenster, zum Ausdrucken oder als PDF)
 *
 * Ebenen: Die Übersicht (README.md, Suche und Stichwortverzeichnis über alle
 * Handbücher) ist eine Ebene, ein Handbuch eine eigene Ebene darüber - die Pfadleiste
 * zeigt also Home > Handbücher > Handbuch für Anwender. Innerhalb eines Handbuchs
 * ersetzt jede Seite die Ebene; zurück zur Übersicht schließt die Ebene (_js()).
 *
 * Kopf: Suchfeld, Auswahl des Handbuchs, Link auf das Stichwortverzeichnis (Vorlage
 * docs.docs_header). Suche und Stichwörter kommen aus dem Index in der Datenbank
 * (Docs::rebuildIndex()), die Seite selbst wird aus dem Markdown gerendert.
 *
 * Unter einer Handbuchseite stehen Links auf die vorige und die nächste Seite desselben
 * Handbuchs (Lesereihenfolge wie die Druckansicht, Docs::bookPages()); die Übersicht
 * (README.md) listet zusätzlich die Handbücher der Komponenten.
 *
 * Parameter (GET, die Suche auch per POST aus dem Formular):
 *     page     Seite, z. B. "admins/07-benutzer-und-rechte.md" (Vorgabe: Startseite von
 *              book, sonst README.md)
 *     anchor   Abschnitt der Seite (Slug einer Überschrift) - wird angesprungen
 *     book     Handbuch für Suche und Stichwortverzeichnis ('' = alle; Docs::books())
 *     q        Suchbegriff(e) -> Trefferliste
 *     view     'index' -> Stichwortverzeichnis
 *     lang     Sprache der Handbücher; Vorgabe: Backend-Sprache, sonst en_US
 *
 * Nur lesend - deshalb ohne POST-Bindung. Das Modul ist nicht 'listed': jeder
 * angemeldete Benutzer darf es öffnen, eine Kachel hat es nicht.
 *
 * @see Camindo\Core\Modules\Docs\Docs
 */
namespace Camindo\Core\Modules\Docs;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;

class DocsBackend {

    private string $lang = '';
    private string $langParam = '';
    /** Diese Ebene zeigt ein Handbuch ('' = die Übersicht) */
    private string $book = '';

    public function __construct(
        private Backend $backend,
        private Cms $cms,
        private Docs $docs,
        private Config $config,
    )
    {
    }

    /**
     * Die Doku-Seite (backend/index.php), siehe Klassenkommentar.
     */
    public function page(): void
    {
        $this->lang = $this->docs->language((string)($_REQUEST['lang'] ?? $this->cms->getLang()));
        // Eine andere als die Backend-Sprache reist in allen Links mit
        $this->langParam = $this->lang !== $this->docs->language($this->cms->getLang()) ? '&lang=' . rawurlencode($this->lang) : '';
        $books = $this->docs->books($this->lang);
        $book = isset($books[(string)($_REQUEST['book'] ?? '')]) ? (string)$_REQUEST['book'] : '';
        $query = trim((string)($_REQUEST['q'] ?? ''));
        $view = (string)($_REQUEST['view'] ?? '');
        $anchor = Docs::slug((string)($_REQUEST['anchor'] ?? ''));
        $requested = (string)($_REQUEST['page'] ?? '');
        if ($requested === '' && $book !== '') $requested = (string)$this->docs->startPage($this->lang, $book);
        $page = $this->docs->page($this->lang, $requested) ?? 'README.md';
        if ($book === '' && $query === '' && $view === '') $book = Docs::bookOf($page);
        $this->book = $book;

        // Eine Seite: vorab rendern - ihr Titel steht in der Navigation
        $rendered = ($query === '' && $view !== 'index')
            ? $this->docs->render($this->lang, $page, fn(string $target, string $a) => ' href="#"' . $this->_link($target, $a))
            : null;
        $this->backend->setNaviLabel($book !== '' ? htmlspecialchars($this->bookLabel($book)) : '#docs.module_label');
        echo $this->backend->template('container_header');
        echo $this->_header($book, $query);

        echo $this->backend->template('docs.content_header');
        if (($query !== '' || $view === 'index') && !$this->docs->indexed($this->lang)) {
            echo $this->backend->template('alert_warning', ['{text}' => htmlspecialchars($this->cms->snippet('docs.text_index_missing'))]);
        }
        if ($query !== '') {
            echo $this->_searchResults($query, $book);
        }
        elseif ($view === 'index') {
            echo $this->_keywordIndex($book);
        }
        else {
            // Anfang eines Handbuchs (bzw. der Übersicht): Hinweis zur Gewähr
            // $start = $page === 'README.md' || $page === $this->docs->startPage($this->lang, Docs::bookOf($page));
            echo $rendered['html'];
            echo $page === 'README.md' ? $this->_componentBooks() : $this->_pager($page);
            if ($anchor !== '') {
                $this->backend->script("document.getElementById(" . json_encode('doc-' . $anchor) . ")?.scrollIntoView();");
            }
        }
        echo $this->backend->template('docs.content_footer');
        echo $this->backend->template('container_footer');
    }

    /**
     * Ein ganzes Handbuch auf einer Seite (backend/print.php) - eigenständiges HTML
     * ohne Backend-Rahmen, geöffnet in einem neuen Fenster: README, dann alle Kapitel
     * in Lesereihenfolge (Docs::bookPages()), jedes Kapitel beim Drucken auf neuer
     * Seite. Links innerhalb des Handbuchs springen an die Stelle im Dokument, Links
     * in andere Handbücher bleiben Text.
     *
     * Parameter (GET): book (Pflicht), lang wie bei page().
     */
    public function printBook(): void
    {
        $this->lang = $this->docs->language((string)($_REQUEST['lang'] ?? $this->cms->getLang()));
        $book = (string)($_REQUEST['book'] ?? '');
        $pages = $this->docs->bookPages($this->lang, $book);

        $body = '';
        foreach ($pages as $page) {
            $key = Docs::pageKey($page);
            $rendered = $this->docs->render($this->lang, $page,
                fn(string $target, string $anchor) => $this->_printLink($pages, $page, $target, $anchor), $key . '--');
            $body .= $this->backend->template('docs.print_chapter', [
                '{key}' => $this->backend->escape($key),
                '{html}' => $this->backend->protect($rendered['html']),
            ]);
        }
        $title = $pages ? $this->bookLabel($book) : $this->cms->snippet('docs.module_label');
        if ($body === '') {
            $body = $this->backend->template('alert_warning', ['{text}' => $this->backend->escape($this->cms->snippet('docs.print_missing'))]);
        }
        $date = \IntlDateFormatter::formatObject(new \DateTime(), [\IntlDateFormatter::LONG, \IntlDateFormatter::NONE], $this->lang);
        $meta = strtr($this->cms->snippet('docs.print_meta'), [
            '{book}' => $title,
            '{version}' => defined('CAMINDO_VERSION') ? CAMINDO_VERSION : '',
            '{date}' => is_string($date) ? $date : date('Y-m-d'),
        ]);

        // Wie backend/index.php: bei rtl die gespiegelte Fassung von Bootstrap
        $dir = $this->cms->textDirection($this->lang);
        $assetDir = rtrim((string)$this->config->get('paths.backend_dir'), '/') . '/assets/';
        $bootstrap = ($dir === 'rtl' && is_file($assetDir . 'bootstrap/bootstrap.rtl.min.css'))
            ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css';
        // Stand der Datei an der Adresse: ein geändertes Stylesheet lädt der Browser neu
        $asset = fn(string $file) => rtrim((string)$this->config->get('paths.backend'), '/') . '/assets/' . $file
            . '?v=' . (@filemtime($assetDir . $file) ?: 0);

        echo $this->backend->template('docs.print_page', [
            '{lang}' => $this->backend->escape(str_replace('_', '-', $this->lang)),
            '{dir}' => $dir,
            '{title}' => $this->backend->escape($title),
            '{bootstrap_css}' => $this->backend->escape($asset('bootstrap/' . $bootstrap)),
            '{backend_css}' => $this->backend->escape($asset('css/backend.css')),
            '{disabled}' => $pages ? '' : ' disabled',
            '{meta}' => $this->backend->escape($meta),
            '{body}' => $body,
        ]);
    }

    /**
     * Link der Druckansicht: auf eine Seite des Handbuchs (bzw. einen Abschnitt) ein
     * Sprung im Dokument, sonst null (nur Text).
     */
    private function _printLink(array $pages, string $current, string $target, string $anchor): ?string
    {
        if (str_starts_with($target, '?')) return null;
        if ($target === '') $target = $current;
        if (!in_array($target, $pages, true)) return null;
        $id = 'doc-' . Docs::pageKey($target) . ($anchor !== '' ? '--' . Docs::slug($anchor) : '');
        return ' href="#' . htmlspecialchars(rawurlencode($id)) . '"';
    }

    /**
     * Kopfzeile: Suchfeld, Handbuch, Stichwortverzeichnis, Druckansicht.
     */
    private function _header(string $book, string $query): string
    {
        $options = '';
        foreach (array_keys($this->docs->books($this->lang)) as $name) {
            $options .= $this->backend->template('docs.book_option', [
                '{value}' => $this->backend->escape($name),
                '{selected}' => $name === $book ? ' selected' : '',
                '{label}' => $this->backend->escape($this->bookLabel($name)),
            ]);
        }
        $printLink = '';
        if ($book !== '') {
            $printLink = $this->backend->template('docs.print_link', [
                '{href}' => $this->backend->escape(rtrim((string)$this->config->get('paths.backend'), '/')
                    . '/module.php?_path=Docs/print.php&book=' . rawurlencode($book) . '&lang=' . rawurlencode($this->lang)),
            ]);
        }
        $lang = $this->langParam !== '' ? " + '" . $this->langParam . "'" : '';
        return $this->backend->template('docs.docs_header', [
            '{query}' => $this->backend->escape($query),
            '{book_options}' => $options,
            '{lang}' => $this->backend->escape($this->lang),
            '{onchange_book}' => $this->backend->escape($this->book !== ''
                ? "if (this.value === '') { " . $this->_overview() . " } else cApp.layers.replace('Docs/index.php?book=' + this.value{$lang});"
                : "if (this.value !== '') cApp.layers.add('Docs/index.php?book=' + this.value{$lang});"),
            '{onclick_index}' => $this->backend->escape("cApp.layers.replace('Docs/index.php?view=index&book=' + document.getElementById('c9-docs-book').value{$lang});"),
            '{print_link}' => $printLink,
        ]);
    }

    /** Trefferliste der Suche */
    private function _searchResults(string $query, string $book): string
    {
        $hits = $this->docs->search($this->lang, $query, $book);
        $html = $this->backend->template('headline1', [
            '{text}' => $this->backend->escape(strtr($this->cms->snippet('docs.search_headline'), ['{query}' => $query])),
        ]);
        if (!$hits) {
            return $html . $this->backend->template('docs.search_none');
        }
        $items = '';
        foreach ($hits as $hit) {
            $label = $hit['title'] . ($hit['heading'] !== '' && $hit['heading'] !== $hit['title'] ? ' – ' . $hit['heading'] : '');
            $items .= $this->backend->template('docs.search_hit', [
                '{js}' => $this->backend->escape($this->_js($hit['page'], $hit['slug'])),
                '{label}' => $this->backend->escape($label),
                '{book}' => $this->backend->escape($this->bookLabel(Docs::bookOf($hit['page']))),
                '{excerpt}' => $this->backend->escape($hit['excerpt']),
            ]);
        }
        return $html . $this->backend->template('docs.search_hits', ['{hits}' => $items]);
    }

    /** Stichwortverzeichnis: Buchstabenleiste, darunter Begriffe mit ihren Stellen */
    private function _keywordIndex(string $book): string
    {
        // Begriffe nach Anfangsbuchstaben gruppieren
        $groups = [];
        foreach ($this->docs->keywords($this->lang, $book) as $term => $refs) {
            // Umlaute unter dem Grundbuchstaben (Ä -> A)
            $first = mb_strtoupper(mb_substr(\Normalizer::normalize((string)$term, \Normalizer::FORM_D) ?: (string)$term, 0, 1));
            if (!preg_match('/\p{L}/u', $first)) $first = '#';

            $links = [];
            foreach ($refs as $ref) {
                $label = $ref['title'] . ($ref['heading'] !== '' && $ref['heading'] !== $ref['title'] && $ref['heading'] !== $term ? ' – ' . $ref['heading'] : '');
                // Mehrere Stellen desselben Abschnitts: ein Link genügt
                if (isset($links[$label])) continue;
                $links[$label] = $this->backend->template('docs.keyword_link', [
                    '{js}' => $this->backend->escape($this->_js($ref['page'], $ref['slug'])),
                    '{label}' => $this->backend->escape($label),
                ]);
            }
            $groups[$first] = ($groups[$first] ?? '') . $this->backend->template('docs.keyword_entry', [
                '{term}' => $this->backend->escape((string)$term),
                '{links}' => implode('', $links),
            ]);
        }

        // Buchstabenleiste und Gruppen
        $letters = '';
        $body = '';
        foreach ($groups as $letter => $entries) {
            $id = 'doc-letter-' . ($letter === '#' ? 'sym' : bin2hex((string)$letter));
            $letters .= $this->backend->template('docs.keyword_letter', [
                '{js}' => $this->backend->escape($this->_scrollJs($id)),
                '{letter}' => $this->backend->escape((string)$letter),
            ]);
            $body .= $this->backend->template('docs.keyword_group', [
                '{id}' => $id,
                '{letter}' => $this->backend->escape((string)$letter),
                '{entries}' => $entries,
            ]);
        }
        return $this->backend->template('docs.keyword_index', [
            '{book}' => $book !== '' ? $this->backend->escape(' – ' . $this->bookLabel($book)) : '',
            '{letters}' => $letters,
            '{groups}' => $body,
        ]);
    }

    /**
     * Name eines Handbuchs: Snippet docs.book_{Handbuch} bei denen des Core, sonst der
     * Titel der Startseite ('' = Übersicht).
     */
    public function bookLabel(string $book): string
    {
        $title = $this->docs->books($this->lang)[$book]['title'] ?? '';
        return $title !== '' ? $title : $this->cms->snippet('docs.book_' . ($book === '' ? 'overview' : $book));
    }

    /**
     * Unter einer Handbuchseite: Links auf die vorige und die nächste Seite des Handbuchs
     * (nur hier - die Druckansicht kommt ohne aus).
     */
    private function _pager(string $page): string
    {
        $pages = $this->docs->bookPages($this->lang, Docs::bookOf($page));
        $index = array_search($page, $pages, true);
        if ($index === false) return '';
        $link = function (?string $target, string $direction) {
            if ($target === null) return $this->backend->template('docs.pager_none');
            return $this->backend->template('docs.pager_' . $direction, [
                '{js}' => $this->backend->escape($this->_js($target, '')),
                '{title}' => $this->backend->escape($this->docs->pageTitle($this->lang, $target) ?? basename($target, '.md')),
            ]);
        };
        return $this->backend->template('docs.pager', [
            '{prev}' => $link($pages[$index - 1] ?? null, 'prev'),
            '{next}' => $link($pages[$index + 1] ?? null, 'next'),
        ]);
    }

    /** Unter der Übersicht: die Handbücher der Komponenten (Docs::books()) */
    private function _componentBooks(): string
    {
        $items = '';
        foreach ($this->docs->books($this->lang) as $book => $info) {
            if ($info['component'] === '') continue;
            $items .= $this->backend->template('docs.components_item', [
                '{js}' => $this->backend->escape($this->_js($book . '/' . $info['start'], '')),
                '{title}' => $this->backend->escape($info['title']),
                '{component}' => $this->backend->escape($info['component']),
            ]);
        }
        return $items === '' ? '' : $this->backend->template('docs.components', ['{items}' => $items]);
    }

    /**
     * Javascript: Doku-Seite mit anderen Parametern laden - in dieser Ebene oder
     * ($add) als neue Ebene darüber
     */
    private function _open(string $params, bool $add = false): string
    {
        return "cApp.layers." . ($add ? 'add' : 'replace') . "('Docs/index.php?" . $params . $this->langParam . "');";
    }

    /**
     * Javascript: aus einem Handbuch zurück zur Übersicht - liegt sie als Ebene
     * darunter, diese Ebene schließen, sonst die Übersicht hier laden
     */
    private function _overview(): string
    {
        return "if (String($('article .c9-layer').eq(-2).attr('data-url') || '').startsWith('Docs/index.php')) cApp.layers.close(); else "
            . $this->_open('page=README.md');
    }

    /**
     * Javascript eines Links - auf eine Handbuchseite, einen Anker derselben Seite
     * ($target = '') oder Parameter der Doku-Seite ($target = '?…'). Aus der Übersicht
     * öffnet eine Handbuchseite eine neue Ebene, aus einem Handbuch führt die Übersicht
     * zurück (_overview()), alles andere ersetzt die Ebene. In Vorlagen als
     * href="javascript:{js}" (Backend::markHandlers()).
     */
    private function _js(string $target, string $anchor): string
    {
        if (str_starts_with($target, '?')) {
            return "cApp.layers.replace('Docs/index.php" . preg_replace('/[^A-Za-z0-9_=&?.\/-]/', '', $target) . "');";
        }
        if ($target === '') {
            return $this->_scrollJs('doc-' . Docs::slug($anchor));
        }
        $params = 'page=' . str_replace('%2F', '/', rawurlencode($target))
            . ($anchor !== '' ? '&anchor=' . rawurlencode(Docs::slug($anchor)) : '');
        $targetBook = Docs::bookOf($target);
        if ($this->book !== '' && $targetBook === '' && $anchor === '') return $this->_overview();
        return $this->_open($params, $this->book === '' && $targetBook !== '');
    }

    /** Javascript: sanft zu einem Element der Seite blättern */
    private function _scrollJs(string $id): string
    {
        return "document.getElementById(" . json_encode($id) . ")?.scrollIntoView({behavior:'smooth'});";
    }

    /**
     * Link-Attribut für die Seiten aus Docs::render() - dort entsteht das <a> im
     * Markdown-Renderer, nicht in einer Vorlage (Backend::on()).
     */
    private function _link(string $target, string $anchor): string
    {
        return $this->backend->on('js', $this->_js($target, $anchor));
    }
}
