<?php
/**
 * upgrade [--no-components] [--no-backup] [--unlock]
 *
 * Bring database and runtime to the state of the files - after every
 * `composer update camindo/cms`, and as a repair run ("all caches and lists
 * new") at any time. Steps, each logged (developer docs, chapter 09 (Installation and update)):
 *
 *   1. Set maintenance mode (if not already set)
 *   2. Backup (only if migrations are pending; --no-backup skips it)
 *   3. from = system.core_version (table missing: 9.0.0 - the state before this
 *      mechanism), to = CAMINDO_VERSION; database newer than files = abort
 *   4. Core migrations Core/install/migrations from -> to, pointer after each version
 *   5. Compare components: update core rows, discover add-on components,
 *      set update_version, write runtime lists (ComponentManager::refresh())
 *   6. Add-on components with a pending update in dependency order
 *      (ComponentManager::updateAll(); --no-components skips it); one whose
 *      requires does not allow the new core version stays with an error
 *   7. Runtime: snippet sets of the instances in the earlier PHP format to .ini,
 *      snippet cache, HTML cache, instance/group cache, templates anew
 *      compile (the compiler may have changed), .htaccess,
 *      refresh copied backend assets (a symlink stays)
 *   8. system.core_version, upgraded_at; Versionsstempel var/config/core.php
 *   9. Remove maintenance mode - only one's own (also that of an aborted
 *      upgrade), or with --unlock everyone's
 *
 * An error aborts and leaves the lock in place; the pointer stays at the last
 * good version, the next run continues from there.
 */
namespace Camindo\Core\Cli;

use Camindo\Core\Backup;
use Camindo\Core\ComponentManager;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\HtaccessWriter;
use Camindo\Core\HtmlCache;
use Camindo\Core\InstanceCache;
use Camindo\Core\Install\InstallContext;
use Camindo\Core\Install\MigrationRunner;
use Camindo\Core\Install\SystemTable;
use Camindo\Core\SnippetCache;
use Camindo\Core\System;
use Camindo\Core\SystemState;

class UpgradeCommand extends Command {

    // State of a database without table `system`
    private const PRE_SYSTEM_VERSION = '9.0.0';

    private string $logFile = '';

    public function run(array $args, array $options): int
    {
        $config = $this->get(Config::class);
        $state = $this->get(SystemState::class);
        $db = $this->get(Database::class);
        $systemTable = new SystemTable($db);
        $this->logFile = rtrim((string)$config->get('paths.var_dir'), '/') . '/log/' . date('Y-m-d') . '_upgrade.log';

        $to = CAMINDO_VERSION;
        $ownLock = false;

        try {
            // 1. Lock - a lock from an aborted upgrade counts as its own
            if (!$state->isMaintenance()) {
                $state->lock("upgrade to $to");
                $ownLock = true;
                $this->log('maintenance mode on');
            }
            elseif (str_starts_with((string)($state->maintenanceInfo()['reason'] ?? ''), 'upgrade')) {
                $ownLock = true;
            }

            // 3. Versionen
            $from = $systemTable->exists() ? (string)$systemTable->get('core_version') : '';
            if ($from === '') {
                $from = self::PRE_SYSTEM_VERSION;
                $this->log("no version in database - assuming $from");
            }
            if (version_compare($from, $to, '>')) {
                $this->err("database ($from) is newer than the files ($to) - composer update camindo/cms first");
                return Cli::ERROR;
            }
            $this->log("core: database $from, files $to");

            $runner = new MigrationRunner();
            $dir = (string)$config->get('paths.core_dir') . '/install/migrations';
            $pending = $runner->pending($dir, $from, $to);

            // 2. Backup
            if ($pending && empty($options['no-backup'])) {
                $file = $this->get(Backup::class)->beforeChange("upgrade-$from-$to");
                $this->log('backup ' . basename($file));
            }

            // 4. Migrationen
            if ($pending) {
                $ctx = new InstallContext($db, $config, $this->get(System::class), '',
                    (string)$config->get('paths.core_dir'), fn(string $line) => $this->log($line));
                $runner->run($dir, $from, $to, $ctx, function (string $version) use ($systemTable): void {
                    $systemTable->set('core_version', $version);
                });
                $this->log(count($pending) . ' migration(s) done');
            }
            else {
                $this->log('no pending migrations');
            }

            // 5. Compare components, runtime lists
            $status = $this->get(ComponentManager::class)->refresh(fn(string $line) => $this->log('  ' . $line));
            $this->log('components: refreshed');

            // 6. pending component updates in dependency order
            $manager = $this->get(ComponentManager::class);
            if (empty($options['no-components'])) {
                $done = $manager->updateAll(fn(string $line) => $this->log('  ' . $line), empty($options['no-backup']));
                $this->log('components: ' . ($done ? count($done) . ' updated (' . implode(', ', $done) . ')' : 'no updates pending'));
                $status = $manager->status();
            }
            foreach ($status as $entry) {
                if ($entry['state'] === 'update') {
                    $this->log("  WARNING: {$entry['kind']}/{$entry['name']} update pending - component update {$entry['name']}");
                }
                elseif ($entry['state'] === 'error' && !$entry['core']) {
                    $this->log("  WARNING: {$entry['kind']}/{$entry['name']} - " . implode('; ', $entry['errors']));
                }
            }

            // 7. Laufzeit
            $legacy = $this->get(\Camindo\Core\Modules\Snippets\Snippets::class)->convertLegacy();
            foreach ($legacy['converted'] as $line) $this->log("snippet set converted: $line");
            foreach ($legacy['errors'] as $line) $this->log("WARNING: snippet set: $line");
            $result = $this->get(SnippetCache::class)->build();
            $this->log("snippet cache: {$result['files']} files");
            $result = $this->get(HtmlCache::class)->build();
            $this->log("html cache: {$result['files']} files");
            $this->get(InstanceCache::class)->rebuild();
            $this->log('instance cache: rebuilt');
            $this->log('templates: ' . $this->get(\Camindo\Core\Modules\Template\Template::class)->rebuildFiles() . ' compiled');
            foreach ($this->get(\Camindo\Core\Modules\Docs\Docs::class)->rebuildIndex() as $lang => $r) {
                $this->log("docs index $lang: {$r['sections']} sections, {$r['keywords']} keywords");
            }
            if (!$this->get(HtaccessWriter::class)->update()) {
                throw new \RuntimeException('could not write .htaccess');
            }
            $this->log('.htaccess: written');
            $installer = new \Camindo\Core\Install\Installer(fn(string $line) => $this->log('backend/assets: ' . $line));
            $this->log('backend/assets: ' . $installer->refreshAssets($config));

            // 8. Versionsstand
            $systemTable->set('core_version', $to);
            $systemTable->set('upgraded_at', gmdate('Y-m-d H:i:s') . ' UTC');
            $state->writeVersionStamp($to);
            $this->log("version stamp $to written");

            // 9. Lock
            if ($ownLock || !empty($options['unlock'])) {
                $state->unlock();
                $this->log('maintenance mode off');
            }
            elseif ($state->isMaintenance()) {
                $this->log('maintenance mode stays on (set before upgrade) - camindo.php maintenance off');
            }
            $this->out("upgrade complete: $to");
            return Cli::OK;
        }
        catch (\Throwable $e) {
            $this->log('ERROR ' . get_class($e) . ': ' . $e->getMessage());
            $this->err('upgrade failed: ' . $e->getMessage());
            $this->err('maintenance mode stays on; fix the cause and run upgrade again');
            return Cli::ERROR;
        }
    }

    private function log(string $line): void
    {
        $this->out($line);
        @file_put_contents($this->logFile, date('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
