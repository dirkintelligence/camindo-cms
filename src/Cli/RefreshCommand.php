<?php
/**
 * refresh [components|snippets|html]
 *
 * components (Vorgabe): Verzeichnisse mit der Tabelle `component` abgleichen und die
 * Laufzeitlisten var/config/components/*.php neu schreiben (ComponentManager::refresh()).
 * Installiert und migriert nichts; Teil von `upgrade`, hier einzeln.
 *
 * snippets: Snippet-Cache neu bauen - wie Tools > Developer > Snippets
 * (Modules/Tools/backend/tool_snippets.php): lang/-Dateien, Übersetzungspakete
 * var/translation/translated, languages.php, Vorlagen var/translation/blueprint;
 * vorher Snippet-Sets der Instanzen im früheren PHP-Format nach .ini umwandeln.
 * html: HTML-Vorlagen-Cache neu bauen - wie Tools > Developer > HTML (tool_html.php).
 * Beide wie `cache snippets|html` (CacheCommand), auch mit --prefix=NAME.
 */
namespace Camindo\Core\Cli;

use Camindo\Core\ComponentManager;

class RefreshCommand extends Command {

    public function run(array $args, array $options): int
    {
        $what = $args[0] ?? 'components';
        if ($what === 'snippets' || $what === 'html') {
            return (new CacheCommand($this->container, $this->cli))->run([$what], $options);
        }
        if ($what !== 'components') {
            return $this->usage('usage: refresh [components|snippets|html] [--prefix=NAME]');
        }

        $status = $this->get(ComponentManager::class)->refresh(fn(string $line) => $this->out($line));
        $counts = [];
        foreach ($status as $entry) $counts[$entry['state']] = ($counts[$entry['state']] ?? 0) + 1;
        ksort($counts);
        $this->out(implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($counts), $counts)));
        return Cli::OK;
    }
}
