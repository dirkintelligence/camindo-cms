<?php
/**
 * Inhalt des Reiters "Entwickler" im Werkzeugkasten.
 *
 * Drei Knöpfe, alle öffnen ihr Werkzeug im Aside-Bereich (der schmalen Spalte rechts),
 * damit die Ausgabe live mitläuft:
 *
 *     Tools/tool_snippets.php   -> ToolSnippets::buildSnippetCache()
 *     Tools/tool_html.php       -> ToolHtmlTemplates::buildTemplateCache()
 *     Tools/tool_instances.php  -> InstanceCache::rebuild()
 *     Tools/tool_templates.php  -> Template::rebuildFiles()
 *     Tools/tool_media.php      -> MediaAccess::rebuildAll() + rebuildFieldProtection()
 *     Tools/tool_docs.php       -> docsIndex(): Such- und Stichwortindex der Handbücher
 *     Tools/tool_info.php       -> ToolInfo::debugSessionData()
 *
 * Die beiden Cache-Werkzeuge muss man nach JEDER Änderung an einer lang/- oder
 * html/-Datei aufrufen - Snippets und Vorlagen kommen zur Laufzeit ausschließlich aus
 * dem Cache (docs/developers/03-backend-entwicklung.md).
 *
 * Die Beschriftungen stehen hier als englischer Klartext in den snippet()-Aufrufen.
 * Ein nicht gefundener Schlüssel gibt sich selbst aus - deshalb erscheint der Text
 * trotzdem lesbar, übersetzt wird er aber nicht.
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
     * Knopfleiste des Entwickler-Reiters ausgeben.
     */
    public function list() {
        // Zusatzrecht "developer" (Backend-Benutzergruppen)
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

        // Instanz-Cache refresh (var/cache/instances.php, instance-{id}.php)
        $labelHtml = $this->cms->snippet('Renew instance cache');
        $this->backend->buttonAdd('button_secondary', "cApp.aside.open('Tools/tool_instances.php', 500);", $iconCached . ' ' . $labelHtml);        

        // Kompilierte Frontend-Templates (var/php/{area_id}/{name}.php)
        $labelHtml = $this->cms->snippet('Rebuild template files');
        $this->backend->buttonAdd('button_secondary', "cApp.aside.open('Tools/tool_templates.php', 500);", $iconCached . ' ' . $labelHtml);

        // Medien-Sperren (.htaccess je Instanz-Verzeichnis und je Feld mit user_auth)
        $labelHtml = $this->cms->snippet('Renew media htaccess');
        $this->backend->buttonAdd('button_secondary', "cApp.aside.open('Tools/tool_media.php', 500);", $iconCached . ' ' . $labelHtml);

        // Handbücher: Such- und Stichwortindex aus Core/docs neu aufbauen
        $labelHtml = $this->cms->snippet('Index manuals');
        $this->backend->buttonAdd('button_secondary', "cApp.aside.open('Tools/tool_docs.php', 500);", $iconCached . ' ' . $labelHtml);

        // Debug Session data
        $labelHtml = $this->cms->snippet('Debug session data');
        $this->backend->buttonAdd('button_secondary', "cApp.aside.open('Tools/tool_info.php', 500);", $iconInfo . ' ' . $labelHtml);        

        echo $this->backend->buttonBar('button_bar_vertical');

    }
    /**
     * Such- und Stichwortindex der Handbücher neu aufbauen (Tools/tool_docs.php,
     * Modules\Docs\Docs::rebuildIndex()) - Ausgabe im Aside-Bereich.
     */
    public function docsIndex(): void
    {
        $this->accessGuard('developer');
        foreach ($GLOBALS['app']->get(\Camindo\Core\Modules\Docs\Docs::class)->rebuildIndex() as $lang => $r) {
            echo 'docs ' . htmlspecialchars($lang) . ": {$r['pages']} pages, {$r['sections']} sections, {$r['keywords']} keywords<br>\n";
        }
    }

    /**
     * Zusatzrecht des Moduls Tools prüfen - Meldung und Abbruch, wenn es fehlt.
     * Ohne geladene Rechte (kein Backend-Request) verweigert Access nichts.
     */
    private function accessGuard(string $right): void
    {
        $access = $GLOBALS['app']->get(\Camindo\Core\Access::class);
        if (!$access->moduleRight('Tools', $right)) $access->denied();
    }
}
