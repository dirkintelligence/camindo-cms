<?php
/**
 * Pagination bars (page navigation). Instance name in the code: $pagination.
 *
 * Two tasks, the same calculation:
 *
 *   1. BACKEND    Backend::paginationTop() / paginationBottom() call jumpPrev(),
 *                 jumpNext() and jumpList() directly and pass the
 *                 HTML templates from Core/backend/html.
 *   2. FRONTEND   The template commands (CMS:JUMP_LIST), (CMS:JUMP_PREV) and
 *                 (CMS:JUMP_NEXT). The cms* methods only translate the
 *                 command parameters into the tpl_* templates of the three core methods
 *                 and output the result.
 *
 * WHY A SEPARATE CLASS
 * --------------------
 * Up to C9 the three methods were part of Cms and were therefore loaded on every request,
 * although only few pages paginate. As a separate class it is fetched from the
 * container via LazyServicesTrait only on first access -
 * a template without (CMS:JUMP_...) does not even read the file.
 *
 *     Camindo\Core\Page      $lazyServices['pagination']   frontend
 *     Camindo\Core\Backend   $lazyServices['pagination']   backend
 *
 * WHAT IT CALCULATES WITH
 * --------------------
 *     Cms::$list_rows                records per page (backend)
 *     Database::$num_rows_limit      frontend: limit of the CMS:SELECT whose result
 *                                    is currently shown - the cms* methods pass it on as
 *                                    $pageSize; without limit no pagination bar
 *     Database::$num_rows_unlimited  total hits (without LIMIT)
 *     $_REQUEST['skip']              current offset, unless passed as a parameter
 *
 * The page URLs are built from the running REQUEST_URI, from which
 * Cms::sys_reduce_uri() removes the parameter 'skip'; 'skip={offset}' is appended.
 * {href} is HTML-escaped (htmlspecialchars, &amp; instead of &) - the
 * templates put it into an attribute.
 *
 * @see Camindo\Core\Cms                              list_rows, sys_reduce_uri()
 * @see Camindo\Core\Backend                          paginationTop/Bottom
 * @see Camindo\Core\Modules\Template\commands.php    jump_list, jump_prev, jump_next
 * @see developer docs, chapter 06 (Template compiler)  command reference
 */
namespace Camindo\Core;

class Pagination {

    public function __construct(
        private Database $db,
        private Cms $cms,
    )
    {
    }

    /**
     * Page URL without 'skip', ready to append 'skip={offset}' - for {href}.
     *
     * REQUEST_URI comes unchecked from the client. {href} is in the templates inside an
     * HTML attribute (htmlspecialchars), in the backend additionally inside a JS string in
     * single quotes (cApp.layers.replace('{href}') - the browser decodes the
     * attribute before running it). Therefore percent-encode ' and \ beforehand;
     * for $_GET this is equivalent.
     */
    private function baseLink(): string
    {
        $uri = $this->cms->sys_reduce_uri(['skip'], (string)($_SERVER['REQUEST_URI'] ?? ''));
        $uri = strtr($uri, ["'" => '%27', '\\' => '%5C']);
        return htmlspecialchars($uri, ENT_QUOTES, 'UTF-8');
    }

    /**
     * -------------------------
     * CMS:JUMP_LIST
     * -------------------------
     * Create Pagination e.g.  1 ... 5 | 6 | 7 | 8 | 9 ... 15
     * uses:
     *   (int)$pageSize / $this->cms->list_rows  maximum number of results per page
     *   (int)$this->db->num_rows           number of results on the current page
     *   (int)$this->db->num_rows_unlimited number of all results without SQL limit statement
     * array @param
     *   (int)skip                          number of results to skip, default: $_REQUEST['skip']
     *   (int)maximum                       maximum number of (moving) page links (plus boundary links), default: 7
     *   (boolean) boundaries               true (default): links to the first and last page are always shown (boundary links) *
     *   (string) separator                 HTML code inserted between page links,
     *                                        but not before or after ellipsis (default: ' ')
     *   (string) replace                   simple text replace function, separator "?", e.g. "aa?bb" *
     *   (string) prefix                    HTML code inserted after the pagination *
     *   (string) suffix                    HTML code inserted before the pagination *
     *   (string) ifempty                   HTML code shown if the search result is not split
     *                                         into several pages (number of results <= $cms->list_rows)
     *   Note: 'set' does not evaluate this method but cmsJumpList().
     *   (string) tpl_link                  HTML template (inner) *
     *                                         {page} is replaced by the page number
     *                                         {href} is replaced by the page URL
     *                                         default: ' <a href="{href}">{page}</a> '
     *   (string) tpl_active                HTML template (inner) *
     *                                         {page} is replaced by the number of the current page
     *                                         {href} is replaced by the URL of the current page
     *                                         default: ' <strong>{page}</strong> '
     *   (string) ellipsis                  HTML code inserted as a placeholder when pages
     *                                         are left out due to max_jumps *
     *                                         default: ' … '
     *
     *                                     *) only if the search result is split into several parts
     */
    public function jumpList(array $param, int $pageSize = 0): string {
        $html = '';
        $count = $this->db->num_rows_unlimited;
        $limit = $pageSize > 0 ? $pageSize : $this->cms->list_rows;

        // If there are more results than fit on one page (without page size: never)
        if ($limit > 0 && $count > $limit) {

            $tplLink = (string) ($param['tpl_link'] ?? ' <a href="{href}">{page}</a> ');
            $tplActive = (string) ($param['tpl_active'] ?? ' <strong>{page}</strong> ');
            $ellipsis = (string) ($param['ellipsis'] ?? ' … ');
            $maxLinks = (int)($param['maximum'] ?? 7);
            $boundaries = isset($param['boundaries']) ? filter_var($param['boundaries'], FILTER_VALIDATE_BOOLEAN) : true;
            $separator = (string) ($param['separator'] ?? ' ');
            $skip = (int)($param['skip'] ?? $_REQUEST['skip'] ?? 0);
            $skip = abs((int)$skip);

            // Correct skip: always snap skip to the first result of a page
            $skip = ((int)($skip / $limit)) * $limit;

            $link = $this->baseLink();

            $start = 0;

            // Create ellipsis when the current page exceeds half of the displayed page numbers
            $currentPage = (int)($skip / $limit) +1;
            $totalPages = (int)ceil($count / $limit);
            $half = (int)ceil($maxLinks / 2);

            if ($currentPage > $half) {
                $start = $currentPage - $half;
                // Do not go beyond the end
                $start = min($start, $totalPages - $maxLinks);
                $start = max($start, 0);
            }

            $end = min($start + $maxLinks, $totalPages);

            $start *= $limit;
            $end *= $limit;

            // Link to the first page
            if ($boundaries) {
                if ($start > 0) {
                    $html .= strtr($tplLink, [
                        '{page}' => 1,
                        '{href}' => $link . 'skip=0',
                    ]);
                    $html .= $ellipsis;
                }
            }

            // Links to sibling pages
            for ($i = $start; $i < $end; $i += $limit) {
                $pageNo = (int)($i / $limit) +1;
                if ($i > $start) $html .= $separator;
                if ($i == $skip) {
                    $tpl = $tplActive;
                } else {
                    $tpl = $tplLink;
                }
                $html .= strtr($tpl, [
                    '{page}' => $pageNo,
                    '{href}' => $link . 'skip=' . $i,
                ]);
            }

            // Link to the last page
            if ($boundaries) {
                $lastPage = (int)(($count-1) / $limit);
                $lastSkip = $lastPage * $limit;
                $endPage = $end / $limit;
                if ($endPage < $totalPages) {
                    $html .= $ellipsis;
                    $html .= strtr($tplLink, [
                        '{page}' => $lastPage + 1,
                        '{href}' => $link . 'skip=' . $lastSkip,
                    ]);
                }
            }

            if (isset($param['replace'])) {
                $parts = explode('?', (string) $param['replace']);
                $html = str_replace($parts[0], $parts[1], $html);
            }

            // Wrap complete Pagination
            $prefix = (string) ($param['prefix'] ?? '');
            $suffix = (string) ($param['suffix'] ?? '');
            $html = $prefix . $html . $suffix;
        }
        else {
            $html = (string) ($param['ifempty'] ?? '');
        }


        return $html;
    }

	/*
	 * -------------------------
	 * CMS:JUMP_PREV
	 * -------------------------
	 * link to Previous Page in a Pagination Bar
	 *   uses:
	 *   (int)$pageSize / $this->cms->list_rows  Number of datasets per page
	 *   (int)$this->db->num_rows_unlimited Number of datasets without limit statement
	 * array @param
	 *   (int)skip                      Offset, overwrite $GLOBALS[skip]
	 *   (string) tpl_link              default: ' <a href="{href}">❮❮</a> ' *
     *                                       {href} is replaced by the URL of the current page
	 *   (string) tpl_inactive          default: ' <span>❮❮</span> ' *
     *                                       {href} is replaced by the URL of the current page
 	 *   (string) replace               simple string replace, separator: '?', eg. "aa?bb"  *
	 *   (string) prefix                if pagination is displayed *
	 *   (string) suffix                if pagination is displayed *
	 *   (string) ifempty               if no pagination is displayed ($count <= $limit) ''
	 *   Note: 'set' does not evaluate this method but cmsJumpNext()/cmsJumpPrev().
     * 
     *                                  *) only if the search result is split into several parts
	 */
	public function jumpPrev(array $param, int $pageSize = 0): string {
        // Turn to next Page
        $html = '';
		$limit = $pageSize > 0 ? $pageSize : $this->cms->list_rows;
        $count = $this->db->num_rows_unlimited;
        $skip = (int)($param['skip'] ?? $_REQUEST['skip'] ?? 0);
        $skip = abs((int)$skip);

		// if Limit has been defined before
		if (!empty($limit)) {

            $link = $this->baseLink();

            $skipPrev = max($skip - $limit,0);

            if ($skip > 0) {
                $tplLink = (string) ($param['tpl_link'] ?? ' <a href="{href}"><strong>❮❮</strong></a> ');
                $html .= strtr($tplLink, [
                    '{href}' => $link . 'skip=' . $skipPrev,
                ]);
            }
            else {
                $tplInactive = (string) ($param['tpl_inactive'] ?? ' <span>❮❮</span> ');
                $html .= strtr($tplInactive, [
                    '{href}' => $link . 'skip=' . $skipPrev,
                ]);
            }

            if (isset($param['replace'])) {
                $parts = explode('?', (string) $param['replace']);
                $html = str_replace($parts[0], $parts[1], $html);
            }

            // Wrap complete Pagination
            $prefix = (string) ($param['prefix'] ?? '');
            $suffix = (string) ($param['suffix'] ?? '');
            $html = $prefix . $html . $suffix;
		}
        else {
            $html = (string) ($param['ifempty'] ?? '');
        }


		return $html;
	}

	/*
	 * -------------------------
	 * CMS:JUMP_NEXT
	 * -------------------------
	 * link to Next Page in a Pagination Bar
	 *   uses:
	 *   (int)$pageSize / $this->cms->list_rows  Number of datasets per page
	 *   (int)$this->db->num_rows_unlimited Number of datasets without limit statement
	 * array @param
	 *   (int)skip                      Offset, overwrite $GLOBALS[skip]
	 *   (string) tpl_link              default: ' <a href="{href}">❯❯</a> ' *
     *                                       {href} is replaced by the URL of the current page
	 *   (string) tpl_inactive          default: ' <span>❯❯</span> ' *
     *                                       {href} is replaced by the URL of the current page
 	 *   (string) replace               simple string replace, separator: '?', eg. "aa?bb"  *
	 *   (string) prefix                if pagination is displayed *
	 *   (string) suffix                if pagination is displayed *
	 *   (string) ifempty               if no pagination is displayed ($count <= $limit) ''
	 *   Note: 'set' does not evaluate this method but cmsJumpNext()/cmsJumpPrev().
     * 
     *                                  *) only if the search result is split into several parts
	 */
	public function jumpNext(array $param, int $pageSize = 0): string {
		// Turn to next Page
        $html = '';
		$limit = $pageSize > 0 ? $pageSize : $this->cms->list_rows;
        $count = $this->db->num_rows_unlimited;
        $skip = (int)($param['skip'] ?? $_REQUEST['skip'] ?? 0);
        $skip = abs((int)$skip);
        $skipNext = $skip + $limit;

		// if Limit has been defined before
		if (!empty($limit)) {

            $link = $this->baseLink();

            if ($skipNext < $count) {
                $tplLink = (string) ($param['tpl_link'] ?? ' <a href="{href}"><strong>❯❯</strong></a> ');
                $html .= strtr($tplLink, [
                    '{href}' => $link . 'skip=' . $skipNext,
                ]);
            }
            else {
                $tplInactive = (string) ($param['tpl_inactive'] ?? ' <span>❯❯</span> ');
                $html .= strtr($tplInactive, [
                    '{href}' => $link . 'skip=' . $skipNext,
                ]);
            }

            if (isset($param['replace'])) {
                $parts = explode('?', (string) $param['replace']);
                $html = str_replace($parts[0], $parts[1], $html);
            }

            // Wrap complete Pagination
            $prefix = (string) ($param['prefix'] ?? '');
            $suffix = (string) ($param['suffix'] ?? '');
            $html = $prefix . $html . $suffix;
		}
        else {
            $html = (string) ($param['ifempty'] ?? '');
        }


		return $html;
	}

    // ====================
    // CMS commands

    /**
     * CMS:JUMP_LIST - output the page navigation.
     *
     * The command parameters correspond one to one to those of jumpList();
     * only the output or the redirect to $set remains here. The page size is
     * solely the limit of the CMS:SELECT whose result the command is in
     * (Database::$num_rows_limit) - without limit there is no pagination bar, only
     * 'ifempty'. admin.skiplist_default (Cms::$list_rows) applies only in the backend.
     */
    public function cmsJumpList($p): void
    {
        $limit = $this->db->num_rows_limit;
        $this->output($limit > 0 ? $this->jumpList($p, $limit) : (string)($p['ifempty'] ?? ''), $p);
    }

    /** CMS:JUMP_NEXT - output the link to the next page (page size as cmsJumpList()). */
    public function cmsJumpNext($p): void
    {
        $limit = $this->db->num_rows_limit;
        $this->output($limit > 0 ? $this->jumpNext($p, $limit) : (string)($p['ifempty'] ?? ''), $p);
    }

    /** CMS:JUMP_PREV - output the link to the previous page (page size as cmsJumpList()). */
    public function cmsJumpPrev($p): void
    {
        $limit = $this->db->num_rows_limit;
        $this->output($limit > 0 ? $this->jumpPrev($p, $limit) : (string)($p['ifempty'] ?? ''), $p);
    }

    /**
     * Output the result of a command - or store it in $set with 'set'.
     *
     * @param string $html  finished HTML
     * @param array  $p     command parameters
     */
    private function output(string $html, array $p): void
    {
        if (!empty($p['set'])) $GLOBALS['set'][$p['set']] = $html;
        else echo $html;
    }
}
