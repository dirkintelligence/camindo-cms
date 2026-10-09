<?php
/**
 * API methods of the manuals: search and read - for tools like the assistant
 * of the module Ai, which uses them to answer questions about operation.
 *
 * The manuals are open to every admin (module Docs without rights management). Without
 * a language the backend's language applies; if the search finds nothing there, it searches
 * the other languages with an index - so the German manuals help as long as there are no
 * others yet. Every hit names its language.
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
     * Sections of the manuals by relevance.
     *
     * @param string $query     search words
     * @param string $book      editors | admins | templateprogrammers or the manual of a
     *                          component, e.g. module-ai ('' = all)
     * @param string $language  language code of the manuals ('' = language of the backend)
     * @param int    $limit     maximum number of hits (1-30)
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
     * Read a page or a section as Markdown.
     *
     * @param string $page      page from docs.search, e.g. admins/10-module.md
     * @param string $anchor    section (anchor from docs.search; '' = whole page)
     * @param string $language  language code (language from docs.search; '' = language of the backend)
     * @return array  language, page, anchor, title, heading, markdown, truncated
     */
    #[ApiMethod('docs.read', '#docs.api_read')]
    public function read(string $page, string $anchor = '', string $language = ''): array
    {
        $this->context->require();
        // as search(): the requested language, otherwise the others
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
