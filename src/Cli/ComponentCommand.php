<?php
/**
 * component list [--json]
 *           install NAME [--activate] [--no-backup] | install --all [--activate] [--no-backup]
 *           activate NAME | deactivate NAME [--force]
 *           update NAME [--no-backup] | update --all [--no-backup]
 *           uninstall NAME [--purge] [--no-backup]
 *
 * Lifecycle of the add-on components (ComponentManager, developer docs, chapter 09 (Installation and update)). NAME is the
 * component name ("Blog") or with kind ("modules/Blog") if the name is not
 * unique. The backend module Component calls the same methods.
 */
namespace Camindo\Core\Cli;

use Camindo\Core\ComponentManager;

class ComponentCommand extends Command {

    private const USAGE = 'usage: component list [--json] | install NAME|--all [--activate] | activate NAME'
        . ' | deactivate NAME [--force] | update NAME|--all | uninstall NAME [--purge]   [--no-backup]';

    public function run(array $args, array $options): int
    {
        $manager = $this->get(ComponentManager::class);
        $log = fn(string $line) => $this->out('  ' . $line);
        $action = $args[0] ?? 'list';
        $name = $args[1] ?? '';
        $all = !empty($options['all']);
        $backup = empty($options['no-backup']);

        if ($action !== 'list' && $name === '' && !($all && in_array($action, ['install', 'update'], true))) {
            return $this->usage(self::USAGE);
        }

        switch ($action) {
            case 'list':
                return $this->list($manager->status(), !empty($options['json']));

            case 'install':
                if ($all) {
                    $done = $manager->installAll($log, !empty($options['activate']), $backup);
                    $this->out($done ? count($done) . ' installed: ' . implode(', ', $done) : 'nothing to install');
                    return Cli::OK;
                }
                $entry = $manager->install($name, $log, !empty($options['activate']), $backup);
                $this->out("{$entry['kind']}/{$entry['name']} {$entry['installed_version']}: {$entry['state']}"
                    . ($entry['state'] === 'inactive' ? " - component activate {$entry['name']}" : ''));
                return Cli::OK;

            case 'activate':
                $entry = $manager->activate($name, $log);
                $this->out("{$entry['kind']}/{$entry['name']}: {$entry['state']}");
                return Cli::OK;

            case 'deactivate':
                $done = $manager->deactivate($name, $log, !empty($options['force']));
                $this->out($done ? 'deactivated: ' . implode(', ', $done) : 'nothing to do');
                return Cli::OK;

            case 'update':
                if ($all) {
                    $done = $manager->updateAll($log, $backup);
                    $this->out($done ? count($done) . ' updated: ' . implode(', ', $done) : 'no updates pending');
                    return Cli::OK;
                }
                $entry = $manager->update($name, $log, $backup);
                $this->out("{$entry['kind']}/{$entry['name']} {$entry['installed_version']}: {$entry['state']}");
                return Cli::OK;

            case 'uninstall':
                $dropped = $manager->uninstall($name, $log, !empty($options['purge']), $backup);
                $this->out("$name uninstalled" . ($dropped ? ', tables dropped: ' . implode(', ', $dropped)
                    : (empty($options['purge']) ? ' (tables kept - use --purge to drop them)' : '')));
                return Cli::OK;
        }
        return $this->usage(self::USAGE);
    }

    private function list(array $status, bool $json): int
    {
        if ($json) {
            $this->out(json_encode(array_map(fn($e) => array_diff_key($e, ['row' => 1, 'settings' => 1]), $status),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Cli::OK;
        }
        $this->out(sprintf('%-12s %-20s %-10s %-9s %-9s %s', 'kind', 'name', 'state', 'files', 'installed', 'notes'));
        foreach ($status as $entry) {
            $notes = $entry['errors'];
            if ($entry['overrides']) $notes[] = 'overrides core processor';
            if ($entry['state'] === 'update') $notes[] = 'component update ' . $entry['name'];
            if ($entry['state'] === 'discovered') $notes[] = 'component install ' . $entry['name'];
            if ($entry['state'] === 'inactive') $notes[] = 'component activate ' . $entry['name'];
            if ($entry['state'] === 'orphaned') $notes[] = 'component uninstall ' . $entry['name'];
            if (!$entry['core'] && $entry['path'] !== '' && $entry['installed_version'] !== ''
                && version_compare($entry['version'], $entry['installed_version'], '<')) {
                $notes[] = 'files older than database';
            }
            $this->out(sprintf('%-12s %-20s %-10s %-9s %-9s %s', $entry['kind'], $entry['name'],
                $entry['state'], $entry['version'] ?: '-', $entry['installed_version'] ?: '-', implode('; ', $notes)));
        }
        return Cli::OK;
    }
}
