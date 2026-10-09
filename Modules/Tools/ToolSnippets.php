<?php
/**
 * Backend-Zugang zum Snippet-Cache: Tools/tool_snippets.php -> SnippetCache::build().
 * Vorher werden Snippet-Sets der Instanzen im früheren PHP-Format in .ini-Dateien
 * umgewandelt (Snippets::convertLegacy()).
 *
 * Das Einsammeln, Kompilieren (ICU -> Closure) und Schreiben erledigt
 * Camindo\Core\SnippetCache; hier bleiben nur Rechteprüfung und die Ausgabe des
 * Berichts in den Aside-Bereich - samt Übersetzungspaketen (var/translation/translated)
 * und Übersetzungsvorlagen (var/translation/blueprint). Kommandozeile:
 * php Core/cron/camindo.php refresh snippets.
 *
 * @see Camindo\Core\SnippetCache
 * @see Camindo\Core\Cms::snippet()
 * @see Camindo\Core\Modules\Tools\ToolHtmlTemplates  dasselbe für die HTML-Vorlagen
 * @see docs/developers/03-backend-entwicklung.md
 */
namespace Camindo\Core\Modules\Tools;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\SnippetCache;
use Camindo\Core\Modules\Snippets\Snippets;

class ToolSnippets {

    public function __construct(
        private SnippetCache $snippetCache,
        private Snippets $snippets,
        private Backend $backend,
        private Cms $cms
    )
    {
    }

    /**
     * Snippet-Cache-Files neu aufbauen; Fortschritt und Bericht (fehlende
     * Übersetzungen, doppelte Schlüssel, Fehler in .ini-Dateien, geschriebene
     * Vorlagen) gehen als HTML direkt in den Aside-Bereich.
     *
     * @param ?string $prefix  nur diese Komponente (siehe SnippetCache::build())
     */
    public function buildSnippetCache(?string $prefix = null): void {
        // Zusatzrecht "developer" (Backend-Benutzergruppen)
        $this->accessGuard('developer');

        // Snippet-Sets der Instanzen im früheren PHP-Format -> .ini
        if ($prefix === null || str_starts_with($prefix, SnippetCache::CUSTOM_PREFIX . '.')) {
            $legacy = $this->snippets->convertLegacy();
            if ($legacy['converted'] || $legacy['errors']) {
                echo '<strong>Snippet sets converted from .php to .ini:</strong><br>' . "\n";
                foreach ($legacy['converted'] as $line) echo htmlspecialchars($line) . "<br>\n";
                foreach ($legacy['errors'] as $line) echo 'Error: ' . htmlspecialchars($line) . "<br>\n";
                echo "<br>\n";
            }
        }

        $result = $this->snippetCache->build($prefix);

        foreach ($result['dirs'] as $dirPrefix => $dirs) {
            echo '<strong>' . htmlspecialchars($dirPrefix) . ".</strong><br>\n";
            foreach ($dirs as $dir) echo htmlspecialchars($dir) . "<br>\n";
            echo "<br>\n";
        }
        echo $result['files'] . ' files written<br>' . "\n";
        foreach ($result['blueprints'] as $file) {
            echo 'Blueprint: ' . htmlspecialchars($file) . "<br>\n";
        }
        if ($result['errors']) {
            echo '<br><strong>Errors in .ini files (lines skipped):</strong><br>' . "\n";
            foreach ($result['errors'] as $error) echo htmlspecialchars($error) . "<br>\n";
        }
        if ($result['duplicates']) {
            echo '<br><strong>Keys in both lang/ and backend/lang/ (frontend file wins):</strong><br>' . "\n";
            echo htmlspecialchars(implode(', ', $result['duplicates'])) . "<br>\n";
        }
        foreach ($result['missing'] as $lang => $keys) {
            echo '<br><strong>' . htmlspecialchars($lang) . ': ' . count($keys) . " missing translations (en text used):</strong><br>\n";
            echo htmlspecialchars(implode(', ', $keys)) . "<br>\n";
        }

        echo '<br><br>';
        echo $this->cms->snippet('completed');

        // Scroll down
        $this->backend->script("$('#aside-content').scrollTop(9999);");

        // Balloon-Message
        $this->backend->balloon('success', $this->cms->snippet('completed'));
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
