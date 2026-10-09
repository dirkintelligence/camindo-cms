<?php
/**
 * Rebuild the HTML template cache - the backend UI for HtmlCache
 * (backend/tool_html.php, Developer tab in the module Tools).
 *
 * The counterpart to ToolSnippets: the builder is HtmlCache (Core/src), so that
 * `camindo.php cache html`, `upgrade` and the ComponentManager can call it without a backend.
 * Here only the call and the output into the aside area.
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
     * Rebuild cache files; progress as HTML in the aside area.
     *
     * @param ?string $prefix  only this component (see HtmlCache::build())
     */
    public function buildTemplateCache(?string $prefix = null): void {
        // Additional right "developer" (backend user groups)
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
     * Check the additional right of the module Tools - message and abort if it is missing.
     * Without loaded rights (CLI) Access denies nothing.
     */
    private function accessGuard(string $right): void
    {
        $access = $GLOBALS['app']->get(\Camindo\Core\Access::class);
        if (!$access->moduleRight('Tools', $right)) $access->denied();
    }
}
