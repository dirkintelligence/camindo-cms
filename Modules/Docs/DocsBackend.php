<?php
/**
 * Backend UI of the manuals (main menu → manuals).
 *
 * Entry point (script in backend/):
 *
 *     index.php  -> page()        page, search hit list or keyword index
 *     print.php  -> printBook()   a whole manual as a standalone HTML page
 *                                 (new window, for printing or as PDF)
 *
 * Levels: the overview (README.md, search and keyword index across all
 * manuals) is one level, a manual is its own level above it - the path bar
 * therefore shows Home > Manuals > Manual for users. Within a manual each page
 * replaces the level; going back to the overview closes the level (_js()).
 *
 * Header: search field, selection of the manual, link to the keyword index (template
 * docs.docs_header). Search and keywords come from the index in the database
 * (Docs::rebuildIndex()); the page itself is rendered from the Markdown.
 *
 * Below a manual page there are links to the previous and the next page of the same
 * manual (reading order as in the print view, Docs::bookPages()); the overview
 * (README.md) additionally lists the manuals of the components.
 *
 * Parameters (GET; the search also via POST from the form):
 *     page     page, e.g. "admins/07-benutzer-und-rechte.md" (default: start page of
 *              book, otherwise README.md)
 *     anchor   section of the page (slug of a heading) - is jumped to
 *     book     manual for search and keyword index ('' = all; Docs::books())
 *     q        search term(s) -> hit list
 *     view     'index' -> keyword index
 *     lang     language of the manuals; default: backend language, otherwise en_US
 *
 * Read-only - therefore without POST binding. The module is not 'listed': every
 * logged-in user may open it, it has no tile.
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
    /** This level shows a manual ('' = the overview) */
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
     * The doc page (backend/index.php), see class comment.
     */
    public function page(): void
    {
        $this->lang = $this->docs->language((string)($_REQUEST['lang'] ?? $this->cms->getLang()));
        // A language other than the backend language travels along in all links
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

        // A page: render it in advance - its title is shown in the navigation
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
            // Start of a manual (or of the overview): disclaimer note
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
     * A whole manual on one page (backend/print.php) - standalone HTML
     * without backend frame, opened in a new window: README, then all chapters
     * in reading order (Docs::bookPages()), each chapter on a new
     * page when printing. Links within the manual jump to the place in the document, links
     * to other manuals remain text.
     *
     * Parameters (GET): book (required), lang as with page().
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

        // As in backend/index.php: for rtl the mirrored version of Bootstrap
        $dir = $this->cms->textDirection($this->lang);
        $assetDir = rtrim((string)$this->config->get('paths.backend_dir'), '/') . '/assets/';
        $bootstrap = ($dir === 'rtl' && is_file($assetDir . 'bootstrap/bootstrap.rtl.min.css'))
            ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css';
        // The file's state at the address: a changed stylesheet is reloaded by the browser
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
     * Link of the print view: to a page of the manual (or a section) a jump
     * in the document, otherwise null (text only).
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
     * Header line: search field, manual, keyword index, print view.
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

    /** Hit list of the search */
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

    /** Keyword index: letter bar, below it terms with their positions */
    private function _keywordIndex(string $book): string
    {
        // Group terms by initial letter
        $groups = [];
        foreach ($this->docs->keywords($this->lang, $book) as $term => $refs) {
            // Umlauts under the base letter (Ä -> A)
            $first = mb_strtoupper(mb_substr(\Normalizer::normalize((string)$term, \Normalizer::FORM_D) ?: (string)$term, 0, 1));
            if (!preg_match('/\p{L}/u', $first)) $first = '#';

            $links = [];
            foreach ($refs as $ref) {
                $label = $ref['title'] . ($ref['heading'] !== '' && $ref['heading'] !== $ref['title'] && $ref['heading'] !== $term ? ' – ' . $ref['heading'] : '');
                // Several positions of the same section: one link is enough
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

        // Letter bar and groups
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
     * Name of a manual: snippet docs.book_{manual} for those of the core, otherwise the
     * title of the start page ('' = overview).
     */
    public function bookLabel(string $book): string
    {
        $title = $this->docs->books($this->lang)[$book]['title'] ?? '';
        return $title !== '' ? $title : $this->cms->snippet('docs.book_' . ($book === '' ? 'overview' : $book));
    }

    /**
     * Below a manual page: links to the previous and the next page of the manual
     * (only here - the print view does without).
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

    /** Below the overview: the manuals of the components (Docs::books()) */
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
     * JavaScript: load the doc page with other parameters - in this level or
     * ($add) as a new level above it
     */
    private function _open(string $params, bool $add = false): string
    {
        return "cApp.layers." . ($add ? 'add' : 'replace') . "('Docs/index.php?" . $params . $this->langParam . "');";
    }

    /**
     * JavaScript: from a manual back to the overview - if it lies as a level
     * below, close this level, otherwise load the overview here
     */
    private function _overview(): string
    {
        return "if (String($('article .c9-layer').eq(-2).attr('data-url') || '').startsWith('Docs/index.php')) cApp.layers.close(); else "
            . $this->_open('page=README.md');
    }

    /**
     * JavaScript of a link - to a manual page, an anchor of the same page
     * ($target = '') or parameters of the doc page ($target = '?…'). From the overview
     * a manual page opens a new level, from a manual the overview leads
     * back (_overview()), everything else replaces the level. In templates as
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

    /** JavaScript: smoothly scroll to an element of the page */
    private function _scrollJs(string $id): string
    {
        return "document.getElementById(" . json_encode($id) . ")?.scrollIntoView({behavior:'smooth'});";
    }

    /**
     * Link attribute for the pages from Docs::render() - there the <a> is created in the
     * Markdown renderer, not in a template (Backend::on()).
     */
    private function _link(string $target, string $anchor): string
    {
        return $this->backend->on('js', $this->_js($target, $anchor));
    }
}
