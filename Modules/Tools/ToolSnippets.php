<?php
/**
 * Backend access to the snippet cache: Tools/tool_snippets.php -> SnippetCache::build().
 * Beforehand, snippet sets of the instances in the earlier PHP format are converted
 * into .ini files (Snippets::convertLegacy()).
 *
 * Collecting, compiling (ICU -> Closure) and writing is done by
 * Camindo\Core\SnippetCache; here only the permission check and the output of the
 * report into the aside area remain - including translation packages (var/translation/translated)
 * and translation templates (var/translation/blueprint). Command line:
 * php Core/cron/camindo.php refresh snippets.
 *
 * @see Camindo\Core\SnippetCache
 * @see Camindo\Core\Cms::snippet()
 * @see Camindo\Core\Modules\Tools\ToolHtmlTemplates  the same for the HTML templates
 * @see developer docs, chapter 05 (Backend development)
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
     * Rebuild snippet cache files; progress and report (missing
     * translations, duplicate keys, errors in .ini files, written
     * templates) go directly as HTML into the aside area.
     *
     * @param ?string $prefix  only this component (see SnippetCache::build())
     */
    public function buildSnippetCache(?string $prefix = null): void {
        // Additional right "developer" (backend user groups)
        $this->accessGuard('developer');

        // Snippet sets of the instances in the earlier PHP format -> .ini
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
     * Check the additional right of the module Tools - message and abort if it is missing.
     * Without loaded rights (no backend request) Access denies nothing.
     */
    private function accessGuard(string $right): void
    {
        $access = $GLOBALS['app']->get(\Camindo\Core\Access::class);
        if (!$access->moduleRight('Tools', $right)) $access->denied();
    }
}
