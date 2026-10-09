<?php
/**
 * HTML-Vorlagen-Cache neu bauen - die Backend-Oberfläche zu HtmlCache
 * (backend/tool_html.php, Reiter Developer im Modul Tools).
 *
 * Das Gegenstück zu ToolSnippets: der Builder ist HtmlCache (Core/src), damit
 * `camindo.php cache html`, `upgrade` und der ComponentManager ihn ohne Backend
 * rufen können. Hier nur der Aufruf und die Ausgabe in den Aside-Bereich.
 *
 * @see Camindo\Core\HtmlCache
 * @see Camindo\Core\Backend::template()
 */
namespace Camindo\Core\Modules\Tools;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\HtmlCache;

class ToolHtmlTemplates {

    public function __construct(
        private HtmlCache $htmlCache,
        private Backend $backend,
        private Cms $cms
    )
    {
    }

    /**
     * Cache-Files neu aufbauen; Fortschritt als HTML in den Aside-Bereich.
     *
     * @param ?string $prefix  nur diese Komponente (siehe HtmlCache::build())
     */
    public function buildTemplateCache(?string $prefix = null): void {
        // Zusatzrecht "developer" (Backend-Benutzergruppen)
        $this->accessGuard('developer');

        $result = $this->htmlCache->build($prefix);
        foreach ($result['dirs'] as $dirPrefix => $dir) {
            echo '<strong>' . htmlspecialchars($dirPrefix === '' ? 'core' : $dirPrefix) . ".</strong> "
                . htmlspecialchars($dir) . "<br>\n";
        }
        echo '<br>' . $result['files'] . " files written<br>\n";

        echo '<br><br>';
        echo $this->cms->snippet('completed');

        // Scroll down
        $this->backend->script("$('#aside-content').scrollTop(9999);");

        // Balloon-Message
        $this->backend->balloon('success', $this->cms->snippet('completed'));
    }

    /**
     * Zusatzrecht des Moduls Tools prüfen - Meldung und Abbruch, wenn es fehlt.
     * Ohne geladene Rechte (CLI) verweigert Access nichts.
     */
    private function accessGuard(string $right): void
    {
        $access = $GLOBALS['app']->get(\Camindo\Core\Access::class);
        if (!$access->moduleRight('Tools', $right)) $access->denied();
    }
}
