<?php
/**
 * status [--after-composer] [--json]
 *
 * Files against database: core version of the files (CAMINDO_VERSION), state of the
 * database (system.core_version), version stamp (var/config/core.php),
 * maintenance mode, pending core migrations, components with a pending update,
 * .htaccess and assets symlink.
 *
 * --after-composer: the Composer hook. Without a reachable database (build server,
 * CI, create-project before the installer) only a note, exit code 0. Never writes.
 *
 * @see developer docs, chapter 09 (Installation and update)
 */
namespace Camindo\Core\Cli;

use Camindo\Core\ComponentManager;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\HtaccessWriter;
use Camindo\Core\Install\MigrationRunner;
use Camindo\Core\Install\SystemTable;
use Camindo\Core\SystemState;

class StatusCommand extends Command {

    public function run(array $args, array $options): int
    {
        $config = $this->get(Config::class);
        $state = $this->get(SystemState::class);
        $afterComposer = !empty($options['after-composer']);

        $info = [
            'files_version'   => CAMINDO_VERSION,
            'stamp_version'   => $state->installedVersion(),
            'upgraded_at'     => $state->upgradedAt(),
            'installed'       => $state->isInstalled(),
            'maintenance'     => $state->maintenanceInfo(),
            'block'           => $state->blockReason(),
            'database'        => null,
            'db_version'      => null,
            'pending_core'    => [],
            'pending_components' => [],
            'htaccess_stale'  => $this->get(HtaccessWriter::class)->hasChanges(),
            'assets_link'     => is_dir((string)$config->get('paths.backend_dir') . '/assets'),
            'actions'         => [],
        ];

        // Never installed: only the note about the installer
        if ($state->isNotInstalled()) {
            $info['actions'][] = 'not installed -> php Core/cron/camindo.php install';
            if (!empty($options['json'])) {
                $this->out(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Cli::OK;
            }
            $this->out('camindo ' . CAMINDO_VERSION . ' - not installed');
            $this->out('  * php Core/cron/camindo.php install');
            return Cli::OK;
        }

        // Database - may be missing
        try {
            $db = $this->get(Database::class);
            $info['database'] = 'ok';
            $systemTable = new SystemTable($db);
            $info['db_version'] = $systemTable->exists() ? $systemTable->get('core_version') : null;
            $from = $info['db_version'] ?? '9.0.0';
            $info['pending_core'] = (new MigrationRunner())->pending(
                (string)$config->get('paths.core_dir') . '/install/migrations', $from, CAMINDO_VERSION
            );
            foreach ($this->get(ComponentManager::class)->status() as $entry) {
                if (in_array($entry['state'], ['update', 'discovered', 'orphaned', 'error'], true)) {
                    $info['pending_components'][] = [
                        'kind' => $entry['kind'], 'name' => $entry['name'], 'state' => $entry['state'],
                        'version' => $entry['version'], 'installed_version' => $entry['installed_version'],
                        'errors' => $entry['errors'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            $info['database'] = 'unavailable: ' . $e->getMessage();
        }

        // Secrets from the environment (Config::ENV_SECRETS, CAMINDO_KEY)
        $info['secrets_from_env'] = array_map(fn($key) => Config::ENV_SECRETS[$key], $config->fromEnv());
        $info['key_env'] = '';
        try {
            $crypt = $this->get(\Camindo\Core\Crypt::class);
            if ($crypt->keySource() === 'env') $info['secrets_from_env'][] = Config::ENV_KEY;
            $info['key_env'] = $crypt->envKeyState();
        } catch (\Throwable $e) {
            $info['actions'][] = $e->getMessage();
        }

        // Empfehlungen
        if (in_array($info['key_env'], ['retired', 'other'], true)) {
            $info['actions'][] = Config::ENV_KEY . ' does not hold the current key (.camindo.key is used) -> set it to the content of .camindo.key';
        }
        if ($info['database'] !== 'ok') {
            $info['actions'][] = $afterComposer
                ? 'database not reachable - nothing checked (run `camindo.php status` on the target system)'
                : 'database not reachable';
        }
        else {
            if ($info['db_version'] === null) {
                $info['actions'][] = 'no version in database (table system missing) -> php Core/cron/camindo.php upgrade';
            }
            elseif (version_compare($info['db_version'], CAMINDO_VERSION, '<')) {
                $info['actions'][] = "core update pending (database: {$info['db_version']}) -> php Core/cron/camindo.php upgrade";
            }
            elseif (version_compare($info['db_version'], CAMINDO_VERSION, '>')) {
                $info['actions'][] = "database ({$info['db_version']}) is NEWER than the files - composer update camindo/cms?";
            }
            if ($info['stamp_version'] !== $info['db_version'] && $info['db_version'] !== null && !$info['pending_core']) {
                $info['actions'][] = 'version stamp var/config/core.php differs from database -> php Core/cron/camindo.php upgrade';
            }
            foreach ($info['pending_components'] as $c) {
                $label = ucfirst($c['kind']) . '/' . $c['name'];
                $info['actions'][] = match ($c['state']) {
                    'update'     => "$label {$c['version']} - update pending (installed: {$c['installed_version']}) -> php Core/cron/camindo.php component update {$c['name']}",
                    'discovered' => "$label {$c['version']} - discovered, not installed -> php Core/cron/camindo.php component install {$c['name']}",
                    'orphaned'   => "$label - orphaned (directory missing) -> php Core/cron/camindo.php component uninstall {$c['name']}",
                    default      => "$label - error: " . implode('; ', $c['errors']),
                };
            }
        }
        if ($info['maintenance'] !== null) {
            $info['actions'][] = 'maintenance mode on -> php Core/cron/camindo.php maintenance off';
        }
        if ($info['htaccess_stale']) $info['actions'][] = '.htaccess outdated -> php Core/cron/camindo.php upgrade';
        if (!$info['assets_link']) $info['actions'][] = 'public/backend/assets missing (symlink to Core/public_master/backend/assets)';

        if (!empty($options['json'])) {
            $this->out(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Cli::OK;
        }

        $this->out('camindo ' . CAMINDO_VERSION
            . ' - database: ' . ($info['db_version'] ?? '-')
            . ', stamp: ' . ($info['stamp_version'] ?? '-')
            . ($info['upgraded_at'] ? ' (' . $info['upgraded_at'] . ')' : ''));
        if ($info['secrets_from_env']) $this->out('from environment: ' . implode(', ', $info['secrets_from_env']));
        if ($info['pending_core']) $this->out('pending core migrations: ' . implode(', ', $info['pending_core']));
        if ($info['block'] !== null) $this->out('BLOCKED: ' . $state->blockMessage($info['block']));
        foreach ($info['actions'] as $action) $this->out('  * ' . $action);
        if (!$info['actions']) $this->out('  everything up to date');

        return Cli::OK;
    }
}
