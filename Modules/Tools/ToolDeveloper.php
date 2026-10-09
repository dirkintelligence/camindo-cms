<?php
/**
 * Content of the "Developer" tab in the toolbox.
 *
 * Three buttons; all of them open their tool in the aside area (the narrow column on the right),
 * so that the output is shown live:
 *
 *     Tools/tool_snippets.php   -> ToolSnippets::buildSnippetCache()
 *     Tools/tool_html.php       -> ToolHtmlTemplates::buildTemplateCache()
 *     Tools/tool_instances.php  -> InstanceCache::rebuild()
 *     Tools/tool_templates.php  -> Template::rebuildFiles()
 *     Tools/tool_media.php      -> MediaAccess::rebuildAll() + rebuildFieldProtection()
 *     Tools/tool_docs.php       -> docsIndex(): search and keyword index of the manuals
 *     Tools/tool_info.php       -> ToolInfo::debugSessionData()
 *
 * The two cache tools must be run after EVERY change to a lang/- or
 * html/ file - snippets and templates come at runtime exclusively from
 * the cache (developer docs, chapter 05 (Backend development)).
 *
 * The labels here are English plain text in the snippet() calls.
 * A key that is not found outputs itself - therefore the text appears readable,
 * but it is not translated.
 *
 * @see Camindo\Core\Modules\Tools\ToolsBackend
 */
namespace Camindo\Core\Modules\Tools;

use Camindo\Core\System;
use Camindo\Core\Backend;
use Camindo\Core\Cms;

class ToolDeveloper {

    public function __construct(
        protected System $system,
        private Backend $backend,
        private Cms $cms,
    )
    {
    }

    /**
     * Output the button bar of the Developer tab.
     */
    public function list() {
        // Additional right "developer" (backend user groups)
        $this->accessGuard('developer');

        // icon
        $iconCached = $this->backend->template('tools.icon_renew');
        $iconInfo = $this->backend->template('tools.icon_info');

        // Buttons
        // --------------------
        $this->backend->buttonBarReset();

        // Snippets refresh
        $labelSnippets = $this->cms->snippet('Renew snippet cache');
        $this->backend->buttonAdd('button_secondary', "cApp.aside.open('Tools/tool_snippets.php', 500);", $iconCached . ' ' . $labelSnippets);

        // Backend-Templates refresh
        $labelHtml = $this->cms->snippet('Renew backend-template cache');
        $this->backend->buttonAdd('button_secondary', "cApp.aside.open('Tools/tool_html.php', 500);", $iconCached . ' ' . $labelHtml);        

        // Instance cache refresh (var/cache/instances.php, instance-{id}.php)
        $labelHtml = $this->cms->snippet('Renew instance cache');
        $this->backend->buttonAdd('button_secondary', "cApp.aside.open('Tools/tool_instances.php', 500);", $iconCached . ' ' . $labelHtml);        

        // Compiled frontend templates (var/php/{area_id}/{name}.php)
        $labelHtml = $this->cms->snippet('Rebuild template files');
        $this->backend->buttonAdd('button_secondary', "cApp.aside.open('Tools/tool_templates.php', 500);", $iconCached . ' ' . $labelHtml);

        // Media locks (.htaccess per instance directory and per field with user_auth)
        $labelHtml = $this->cms->snippet('Renew media htaccess');
        $this->backend->buttonAdd('button_secondary', "cApp.aside.open('Tools/tool_media.php', 500);", $iconCached . ' ' . $labelHtml);

        // Manuals: rebuild search and keyword index from Core/docs
        $labelHtml = $this->cms->snippet('Index manuals');
        $this->backend->buttonAdd('button_secondary', "cApp.aside.open('Tools/tool_docs.php', 500);", $iconCached . ' ' . $labelHtml);

        // Debug Session data
        $labelHtml = $this->cms->snippet('Debug session data');
        $this->backend->buttonAdd('button_secondary', "cApp.aside.open('Tools/tool_info.php', 500);", $iconInfo . ' ' . $labelHtml);        

        echo $this->backend->buttonBar('button_bar_vertical');

    }
    /**
     * Rebuild the search and keyword index of the manuals (Tools/tool_docs.php,
     * Modules\Docs\Docs::rebuildIndex()) - output in the aside area.
     */
    public function docsIndex(): void
    {
        $this->accessGuard('developer');
        foreach ($GLOBALS['app']->get(\Camindo\Core\Modules\Docs\Docs::class)->rebuildIndex() as $lang => $r) {
            echo 'docs ' . htmlspecialchars($lang) . ": {$r['pages']} pages, {$r['sections']} sections, {$r['keywords']} keywords<br>\n";
        }
    }

    /**
     * Check the additional right of the module Tools - message and abort if it is missing.
     * Without loaded rights (no backend request) Access denies nothing.
     */
    private function accessGuard(string $right): void
    {
        $access = $GLOBALS['app']->get(\Camindo\Core\Access::class);
        if (!$access->moduleRight('Tools', $right)) $access->denied();
    }
}
