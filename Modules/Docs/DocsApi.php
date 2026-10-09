<?php
/**
 * API-Methoden der Handbücher: suchen und lesen - für Werkzeuge wie den Assistenten
 * des Moduls Ai, der damit Fragen zur Bedienung beantwortet.
 *
 * Die Handbücher stehen jedem Admin offen (Modul Docs ohne Rechteverwaltung). Ohne
 * Sprache gilt die des Backends; findet die Suche dort nichts, sucht sie in den
 * übrigen Sprachen mit Index - so helfen die deutschen Handbücher, solange es andere
 * noch nicht gibt. Jeder Treffer nennt seine Sprache.
 *
 * @see Docs
 */
namespace Camindo\Core\Modules\Docs;

use Camindo\Core\Cms;
use Camindo\Core\Api\ApiContext;
use Camindo\Core\Api\ApiException;
use Camindo\Core\Api\ApiMethod;

class DocsApi
{
    public function __construct(
        private Docs $docs,
        private Cms $cms,
        private ApiContext $context,
    )
    {
    }

    /**
     * Abschnitte der Handbücher nach Relevanz.
     *
     * @param string $query     Suchwörter
     * @param string $book      editors | admins | templateprogrammers oder das Handbuch einer
     *                          Komponente, z. B. module-ai ('' = alle)
     * @param string $language  Sprachcode der Handbücher ('' = Sprache des Backends)
     * @param int    $limit     Höchstzahl Treffer (1-30)
     * @return array  language, hits (page, anchor, title, heading, excerpt)
     */
    #[ApiMethod('docs.search', '#docs.api_search')]
    public function search(string $query, string $book = '', string $language = '', int $limit = 10): array
    {
        $this->context->require();
        $limit = max(1, min(30, $limit));
        $first = $this->docs->language($language !== '' ? $language : $this->cms->getLang());
        $books = array_keys($this->docs->books($first));
        if ($book !== '' && !in_array($book, $books, true)) {
            throw new ApiException(ApiException::INVALID_PARAM, 'book must be one of ' . implode(', ', $books));
        }
        $languages = array_values(array_unique(array_merge([$first], $this->docs->languages())));
        foreach ($languages as $lang) {
            if (!$this->docs->indexed($lang)) continue;
            $hits = $this->docs->rank($lang, $query, $book, $limit);
            if (!$hits) continue;
            return ['language' => $lang, 'hits' => array_map(fn($hit) => [
                'page'    => $hit['page'],
                'anchor'  => $hit['slug'],
                'title'   => $hit['title'],
                'heading' => $hit['heading'],
                'excerpt' => $hit['excerpt'],
            ], $hits)];
        }
        return ['language' => $first, 'hits' => []];
    }

    /**
     * Eine Seite oder einen Abschnitt als Markdown lesen.
     *
     * @param string $page      Seite aus docs.search, z. B. admins/10-module.md
     * @param string $anchor    Abschnitt (anchor aus docs.search; '' = ganze Seite)
     * @param string $language  Sprachcode (language aus docs.search; '' = Sprache des Backends)
     * @return array  language, page, anchor, title, heading, markdown, truncated
     */
    #[ApiMethod('docs.read', '#docs.api_read')]
    public function read(string $page, string $anchor = '', string $language = ''): array
    {
        $this->context->require();
        // wie search(): die gewünschte Sprache, sonst die übrigen
        $first = $this->docs->language($language !== '' ? $language : $this->cms->getLang());
        $source = null;
        foreach (array_values(array_unique(array_merge([$first], $this->docs->languages()))) as $lang) {
            $source = $this->docs->source($lang, $page, $anchor);
            if ($source !== null) break;
        }
        if ($source === null) throw new ApiException(ApiException::NOT_FOUND, 'unknown page or anchor');
        $markdown = $source['markdown'];
        $truncated = mb_strlen($markdown) > 30000;
        if ($truncated) $markdown = mb_substr($markdown, 0, 30000);
        return ['language' => $lang, 'page' => $page, 'anchor' => Docs::slug($anchor), 'title' => $source['title'],
            'heading' => $source['heading'], 'markdown' => $markdown, 'truncated' => $truncated];
    }
}
