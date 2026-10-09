<?php
/**
 * Blätterleisten (Pagination). Instanzname im Code: $pagination.
 *
 * Zwei Aufgaben, dieselbe Rechnung:
 *
 *   1. BACKEND    Backend::paginationTop() / paginationBottom() rufen jumpPrev(),
 *                 jumpNext() und jumpList() direkt auf und übergeben die
 *                 HTML-Vorlagen aus Core/backend/html.
 *   2. FRONTEND   Die Template-Befehle (CMS:JUMP_LIST), (CMS:JUMP_PREV) und
 *                 (CMS:JUMP_NEXT). Die cms*-Methoden übersetzen nur die
 *                 Befehlsparameter in die tpl_*-Vorlagen der drei Kernmethoden
 *                 und geben das Ergebnis aus.
 *
 * WARUM EINE EIGENE KLASSE
 * --------------------
 * Die drei Methoden hingen bis C9 in Cms und wurden damit bei jedem Request
 * mitgeladen, obwohl die wenigsten Seiten blättern. Als eigene Klasse wird sie
 * über LazyServicesTrait erst beim ersten Zugriff aus dem Container geholt -
 * ein Template ohne (CMS:JUMP_...) liest die Datei gar nicht erst ein.
 *
 *     Camindo\Core\Page      $lazyServices['pagination']   Frontend
 *     Camindo\Core\Backend   $lazyServices['pagination']   Backend
 *
 * WORAUF SIE RECHNET
 * --------------------
 *     Cms::$list_rows                Datensätze je Seite (Backend)
 *     Database::$num_rows_limit      Frontend: limit des CMS:SELECT, dessen Ergebnis
 *                                    gerade steht - die cms*-Methoden reichen es als
 *                                    $pageSize durch; ohne limit keine Blätterleiste
 *     Database::$num_rows_unlimited  Treffer insgesamt (ohne LIMIT)
 *     $_REQUEST['skip']              aktueller Versatz, sofern nicht als Parameter
 *
 * Die Seiten-URLs entstehen aus der laufenden REQUEST_URI, aus der
 * Cms::sys_reduce_uri() den Parameter 'skip' entfernt; angehängt wird
 * 'skip={offset}'. {href} ist HTML-maskiert (htmlspecialchars, &amp; statt &) - die
 * Vorlagen setzen es in ein Attribut.
 *
 * @see Camindo\Core\Cms                              list_rows, sys_reduce_uri()
 * @see Camindo\Core\Backend                          paginationTop/Bottom
 * @see Camindo\Core\Modules\Template\commands.php    jump_list, jump_prev, jump_next
 * @see docs/developers/06-templates.md               Befehlsreferenz
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
     * Seiten-URL ohne 'skip', bereit zum Anhängen von 'skip={offset}' - für {href}.
     *
     * REQUEST_URI kommt ungeprüft vom Client. {href} steht in den Vorlagen in einem
     * HTML-Attribut (htmlspecialchars), im Backend zusätzlich in einem JS-String in
     * einfachen Anführungszeichen (cApp.layers.replace('{href}') - der Browser
     * dekodiert das Attribut vor dem Ausführen). Deshalb ' und \ vorher
     * prozentkodieren; für $_GET ist das gleichwertig.
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
     *   (int)$pageSize / $this->cms->list_rows  Maximale Anzahl Ergebnisse pro Seite
     *   (int)$this->db->num_rows           Anzahl Ergebnisse auf der aktuellen Seite
     *   (int)$this->db->num_rows_unlimited Anzahl aller Ergebnisse ohne SQL-Limit-Statement
     * array @param
     *   (int)skip                          Anzahl der Ergebnisse, die übersprungen werden, default: $_REQUEST['skip']
     *   (int)maximum                       Maximale Anzahl (mitwandernder) Seiten-Links (zzgl. Boundary-Links), default: 7
     *   (boolean) boundaries               true (default): Links auf erste und letzte Seite werden immer angezeigt (Boundary-Links) *
     *   (string) separator                 HTML-Code, der zwischen Seitenl-Links eingefügt wird,
     *                                        aber nicht vor- oder hinter Ellipsis (default: ' ')
     *   (string) replace                    Einfache Text-Ersetzen-Funktion, Trenner "?", z. B. "aa?bb" *
     *   (string) prefix                     HTML-Code, der hinter der Paginierung eingefügt wird *
     *   (string) suffix                     HTML-Code, der vor der Paginierung eingefügt wird *
     *   (string) ifempty                    HTML-Code, der angezeigt wird, wenn das Suchergebnis nicht auf
     *                                         mehrere Seiten aufgeteilt ist (Anzahl Ergebnisse <= $cms->list_rows)
     *   Hinweis: 'set' wertet nicht diese Methode aus, sondern cmsJumpList().
     *   (string) tpl_link                   HTML-Vorlage (innen) *
     *                                         {page} wird durch Seitennummer ersetzt
     *                                         {href} wird durch Seiten-URL ersetzt
     *                                         default: ' <a href="{href}">{page}</a> '
     *   (string) tpl_active                 HTML-Vorlage (innen) *
     *                                         {page} wird durch Nummer der aktuellen Seite ersetzt
     *                                         {href} wird durch URL der aktuellen Seite ersetzt
     *                                         default: ' <strong>{page}</strong> '
     *   (string) ellipsis                   HTML-Code, der als Platzhalter eingefügt wird, wenn Seiten
     *                                         aufgrund von max_jumps ausgelassen werden *
     *                                         default: ' … '
     *
     *                                     *) nur wenn das Suchergebnis auf mehrere Teilen aufgeteilt ist
     */
    public function jumpList(array $param, int $pageSize = 0): string {
        $html = '';
        $count = $this->db->num_rows_unlimited;
        $limit = $pageSize > 0 ? $pageSize : $this->cms->list_rows;

        // Wenn mehr Ergebnisse als auf eine Seite passen (ohne Seitengröße: nie)
        if ($limit > 0 && $count > $limit) {

            $tplLink = (string) ($param['tpl_link'] ?? ' <a href="{href}">{page}</a> ');
            $tplActive = (string) ($param['tpl_active'] ?? ' <strong>{page}</strong> ');
            $ellipsis = (string) ($param['ellipsis'] ?? ' … ');
            $maxLinks = (int)($param['maximum'] ?? 7);
            $boundaries = isset($param['boundaries']) ? filter_var($param['boundaries'], FILTER_VALIDATE_BOOLEAN) : true;
            $separator = (string) ($param['separator'] ?? ' ');
            $skip = (int)($param['skip'] ?? $_REQUEST['skip'] ?? 0);
            $skip = abs((int)$skip);

            // Korrigiere skip: skip zeit immer auf erstes Ergebnis einer Seite
            $skip = ((int)($skip / $limit)) * $limit;

            $link = $this->baseLink();

            $start = 0;

            // Ellipsis erstellen wenn aktuelle Seite die Hälfte der angezeigten Seitennummern überschreitet
            $currentPage = (int)($skip / $limit) +1;
            $totalPages = (int)ceil($count / $limit);
            $half = (int)ceil($maxLinks / 2);

            if ($currentPage > $half) {
                $start = $currentPage - $half;
                // Nicht über das Ende hinausgehen
                $start = min($start, $totalPages - $maxLinks);
                $start = max($start, 0);
            }

            $end = min($start + $maxLinks, $totalPages);

            $start *= $limit;
            $end *= $limit;

            // Link zur ersten Seite
            if ($boundaries) {
                if ($start > 0) {
                    $html .= strtr($tplLink, [
                        '{page}' => 1,
                        '{href}' => $link . 'skip=0',
                    ]);
                    $html .= $ellipsis;
                }
            }

            // Links zu Sibling-Seiten
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

            // Link zur letzten Seite
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
     *                                       {href} wird durch URL der aktuellen Seite ersetzt
	 *   (string) tpl_inactive          default: ' <span>❮❮</span> ' *
     *                                       {href} wird durch URL der aktuellen Seite ersetzt
 	 *   (string) replace               simple string replace, separator: '?', eg. "aa?bb"  *
	 *   (string) prefix                if pagination is displayed *
	 *   (string) suffix                if pagination is displayed *
	 *   (string) ifempty               if no pagination is displayed ($count <= $limit) ''
	 *   Hinweis: 'set' wertet nicht diese Methode aus, sondern cmsJumpNext()/cmsJumpPrev().
     * 
     *                                  *) nur wenn das Suchergebnis auf mehrere Teilen aufgeteilt ist
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
     *                                       {href} wird durch URL der aktuellen Seite ersetzt
	 *   (string) tpl_inactive          default: ' <span>❯❯</span> ' *
     *                                       {href} wird durch URL der aktuellen Seite ersetzt
 	 *   (string) replace               simple string replace, separator: '?', eg. "aa?bb"  *
	 *   (string) prefix                if pagination is displayed *
	 *   (string) suffix                if pagination is displayed *
	 *   (string) ifempty               if no pagination is displayed ($count <= $limit) ''
	 *   Hinweis: 'set' wertet nicht diese Methode aus, sondern cmsJumpNext()/cmsJumpPrev().
     * 
     *                                  *) nur wenn das Suchergebnis auf mehrere Teilen aufgeteilt ist
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
    // CMS-Commands

    /**
     * CMS:JUMP_LIST - Blätter-Navigation ausgeben.
     *
     * Die Befehlsparameter entsprechen eins zu eins denen von jumpList();
     * hier bleibt nur die Ausgabe bzw. die Umleitung nach $set. Seitengröße ist
     * allein das limit des CMS:SELECT, in dessen Ergebnis der Befehl steht
     * (Database::$num_rows_limit) - ohne limit gibt es keine Blätterleiste, nur
     * 'ifempty'. admin.skiplist_default (Cms::$list_rows) gilt nur im Backend.
     */
    public function cmsJumpList($p): void
    {
        $limit = $this->db->num_rows_limit;
        $this->output($limit > 0 ? $this->jumpList($p, $limit) : (string)($p['ifempty'] ?? ''), $p);
    }

    /** CMS:JUMP_NEXT - Weiterblättern-Verweis ausgeben (Seitengröße wie cmsJumpList()). */
    public function cmsJumpNext($p): void
    {
        $limit = $this->db->num_rows_limit;
        $this->output($limit > 0 ? $this->jumpNext($p, $limit) : (string)($p['ifempty'] ?? ''), $p);
    }

    /** CMS:JUMP_PREV - Zurückblättern-Verweis ausgeben (Seitengröße wie cmsJumpList()). */
    public function cmsJumpPrev($p): void
    {
        $limit = $this->db->num_rows_limit;
        $this->output($limit > 0 ? $this->jumpPrev($p, $limit) : (string)($p['ifempty'] ?? ''), $p);
    }

    /**
     * Ergebnis eines Befehls ausgeben - oder mit 'set' in $set ablegen.
     *
     * @param string $html  fertiges HTML
     * @param array  $p     Befehlsparameter
     */
    private function output(string $html, array $p): void
    {
        if (!empty($p['set'])) $GLOBALS['set'][$p['set']] = $html;
        else echo $html;
    }
}
