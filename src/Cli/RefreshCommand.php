<?php
/**
 * refresh [components|snippets|html|records]
 *
 * components (default): compare directories with the table `component` and the
 * rewrite the runtime lists var/config/components/*.php (ComponentManager::refresh()).
 * Installs and migrates nothing; part of `upgrade`, here individually.
 *
 * snippets: rebuild the snippet cache - like Tools > Developer > Snippets
 * (Modules/Tools/backend/tool_snippets.php): lang/ files, translation packages
 * var/translation/translated, languages.php, templates var/translation/blueprint;
 * beforehand convert snippet sets of the instances in the earlier PHP format to .ini.
 * html: rebuild the HTML template cache - like Tools > Developer > HTML (tool_html.php).
 * Both like `cache snippets|html` (CacheCommand), also with --prefix=NAME.
 * records: clear the content cache record_cache - like `cache records`, with
 * --instance=ID and --area=ID[,ID…].
 */
namespace Camindo\Core\Cli;

use Camindo\Core\ComponentManager;

class RefreshCommand extends Command {

    public function run(array $args, array $options): int
    {
        $what = $args[0] ?? 'components';
        if ($what === 'snippets' || $what === 'html' || $what === 'records') {
            return (new CacheCommand($this->container, $this->cli))->run([$what], $options);
        }
        if ($what !== 'components') {
            return $this->usage('usage: refresh [components|snippets|html] [--prefix=NAME] | records [--instance=ID] [--area=ID,…]');
        }

        $status = $this->get(ComponentManager::class)->refresh(fn(string $line) => $this->out($line));
        $counts = [];
        foreach ($status as $entry) $counts[$entry['state']] = ($counts[$entry['state']] ?? 0) + 1;
        ksort($counts);
        $this->out(implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($counts), $counts)));
        return Cli::OK;
    }
}
