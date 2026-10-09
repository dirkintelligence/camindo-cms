<?php
/**
 * Kommandozeile: php Core/cron/camindo.php <befehl> [argumente] [--optionen]
 *
 * Ein Dispatcher, keine Bibliothek. Befehle sind Klassen in diesem Verzeichnis
 * ({Name}Command, Command::run()); registriert sind sie in COMMANDS. Optionen:
 * --key=value oder --flag (=true); alles andere sind Argumente in Reihenfolge.
 *
 * Exit-Codes: 0 ok, 1 Fehler, 2 Bedienfehler (unbekannter Befehl, fehlendes
 * Argument). --quiet unterdrückt die normale Ausgabe, Fehler gehen immer nach STDERR.
 *
 * @see docs/developers/14-installation-und-update.md, Kapitel 8
 */
namespace Camindo\Core\Cli;

use Camindo\Core\ContainerInterface;

class Cli {

    public const OK    = 0;
    public const ERROR = 1;
    public const USAGE = 2;

    private const COMMANDS = [
        'status'      => StatusCommand::class,
        'install'     => InstallCommand::class,
        'upgrade'     => UpgradeCommand::class,
        'maintenance' => MaintenanceCommand::class,
        'backup'      => BackupCommand::class,
        'schema'      => SchemaCommand::class,
        'cache'       => CacheCommand::class,
        'component'   => ComponentCommand::class,
        'refresh'     => RefreshCommand::class,
        'admin'       => AdminCommand::class,
        'key'         => KeyCommand::class,
        'audit'       => AuditCommand::class,
        'api'         => ApiCommand::class,
        'fulltext'    => FulltextCommand::class,
        'scheduler'   => SchedulerCommand::class,
    ];

    private bool $quiet = false;

    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * @param string[] $argv  wie $argv des Scripts (Index 0 = Scriptname)
     */
    public function run(array $argv): int
    {
        [$name, $args, $options] = $this->parse(array_slice($argv, 1));
        $this->quiet = !empty($options['quiet']);

        if ($name === '' || $name === 'help' || isset($options['help'])) {
            $this->usage();
            return $name === '' ? self::USAGE : self::OK;
        }
        if (!isset(self::COMMANDS[$name])) {
            $this->err("unknown command '$name'");
            $this->usage();
            return self::USAGE;
        }

        /** @var Command $command */
        $command = new (self::COMMANDS[$name])($this->container, $this);
        try {
            return $command->run($args, $options);
        } catch (\Throwable $e) {
            $this->err(get_class($e) . ': ' . $e->getMessage());
            return self::ERROR;
        }
    }

    /**
     * @return array{0: string, 1: string[], 2: array<string, string|true>}
     */
    private function parse(array $argv): array
    {
        $args = [];
        $options = [];
        foreach ($argv as $arg) {
            if (preg_match('/^--([a-z][a-z0-9-]*)(?:=(.*))?$/s', $arg, $m)) {
                $options[$m[1]] = $m[2] ?? true;
            }
            else {
                $args[] = $arg;
            }
        }
        $name = array_shift($args) ?? '';
        return [$name, $args, $options];
    }

    public function usage(): void
    {
        $this->out(<<<TXT
        usage: php Core/cron/camindo.php <command> [arguments] [--options]

          status        [--after-composer] [--json]      files vs. database, pending actions, lock
          install       [--no-interaction] [--fixtures] [--create-db] [--symlink-assets] [--force --yes]
                        [--step=…] [--db-*=… --admin-*=… --timezone=… --locale=…]   first-time setup
          upgrade       [--no-components] [--no-backup] [--unlock]
                                                         core migrations, component updates, runtime files, caches
          maintenance   on [--reason=…] | off | status
          backup        [--reason=…] | list | prune [--keep=N]
          schema        export                           schema.sql / seed.sql / dev.sql from the database
          cache         snippets|html|docs|templates|include|instances|all [--prefix=… | --remove=…] | list
          component     list [--json]                    state of modules, processors, extensions
                        install NAME|--all [--activate] | activate NAME | deactivate NAME [--force]
                        update NAME|--all | uninstall NAME [--purge]              [--no-backup]
          refresh       [components]                     directories -> table component, runtime lists
                        snippets | html [--prefix=…]     rebuild snippet cache (+ translations, blueprints)
                                                         or HTML template cache - like Tools > Developer
          admin         password NAME [--pass-file=…]    set a backend password (or CAMINDO_ADMIN_PASS)
                        sign                             re-sign admin, group and rights rows (key change, fixtures)
          key           status | rotate [--invalidate-passwords] [--no-backup] | retire KID [--force]
                                                         key ring of .camindo.key: rotate, check, remove old keys
          audit         verify [TYPE ID] | migrate       audit files: check HMAC chain vs. database
                        restore [--dry-run]              after restoring a database dump
                        reseal TYPE ID [--reason=…] [--force] [--dry-run]   relink a broken chain
                        strip-names                      remove actor names of older entries (ids only)
          api           login --admin=NAME [--lifetime=SEC]            API session (interface cli)
                        call METHOD [JSON] | whoami | methods | describe METHOD | logout
                                                         --session=ID or CAMINDO_API_SESSION
          fulltext      [--area=ID,…] [--stale]           rebuild full text (all content areas,
                                                         the given ones or the outdated ones)
          scheduler     list | defaults | run ID|TYPE    scheduler tasks: list, add missing default
                                                         tasks, run one now (e.g. "run trash")

          --quiet   no output except errors
        TXT);
    }

    // ==== Ausgabe ====

    public function out(string $line = ''): void
    {
        if (!$this->quiet) fwrite(STDOUT, $line . "\n");
    }

    public function err(string $line): void
    {
        fwrite(STDERR, $line . "\n");
    }
}
