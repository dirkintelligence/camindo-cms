<?php
/**
 * install [--no-interaction] [--fixtures] [--create-db] [--symlink-assets] [--force --yes]
 *         [--step=NAME] [--db-host=… --db-port=… --db-name=… --db-user=… --db-pass-file=…]
 *         [--admin-name=… --admin-email=… --admin-pass-file=… --admin-lang=…]
 *         [--timezone=… --locale=…] [--https=y|n] [--backend-domain=…] [--dev]
 *
 * The installer (Install\Installer) - asks what it does not know, and runs through the
 * steps 1-12 (developer docs, chapter 09 (Installation and update)). If var/install.lock exists, it ends immediately with
 * exit code 0 (the first Docker start may call it at every start); --force resets
 * the database and requires confirmation of the database name (--yes).
 *
 * Every question has a parameter and an environment variable (parameter wins):
 *
 *     --db-host      CAMINDO_DB_HOST         --admin-name       CAMINDO_ADMIN_NAME
 *     --db-port      CAMINDO_DB_PORT         --admin-email      CAMINDO_ADMIN_EMAIL
 *     --db-name      CAMINDO_DB_NAME         --admin-pass-file  CAMINDO_ADMIN_PASS_FILE
 *     --db-user      CAMINDO_DB_USER         --admin-lang       CAMINDO_ADMIN_LANG
 *     --db-pass-file CAMINDO_DB_PASS_FILE    --timezone         CAMINDO_TIMEZONE
 *                                            --locale           CAMINDO_LOCALE
 *                                            --https            CAMINDO_HTTPS (y/n)
 *                                            --backend-domain   CAMINDO_BACKEND_DOMAIN
 *
 * Passwords come from files (--*-pass-file, e.g. /run/secrets/…) or the
 * environment variables CAMINDO_DB_PASS / CAMINDO_ADMIN_PASS, never as an argument.
 * CAMINDO_DB_PASS also applies in operation (Config::ENV_SECRETS): if the password comes from
 * there, it stays out of .camindo.php, and .sqlpwd is not written. If
 * CAMINDO_KEY is set, no .camindo.key is created (Crypt then takes the variable). With
 * --no-interaction a missing required value aborts with exit code 2, before
 * anything is written. If .camindo.php already exists (development stack),
 * database, time zone and locale are read from it and not asked.
 *
 * --fixtures imports dev.sql (development data including admins) - the admin step
 * is then omitted. --step=NAME runs one step individually (requirements, key,
 * config, sqlpwd, dirs, database, admin, core, components, runtime, finish), to repair a
 * broken installation; the database steps database/admin only with
 * --force.
 */
namespace Camindo\Core\Cli;

use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\PasswordPolicy;
use Camindo\Core\Install\Installer;

class InstallCommand extends Command {

    private const STEPS = ['requirements', 'key', 'config', 'sqlpwd', 'dirs', 'database', 'admin', 'core',
        'components', 'runtime', 'tasks', 'finish'];

    private const BANNER = [
        '==============================================',
        ' ▗▛▀ ▄▀▚▖▐▙ ▗█ █ █▖ █ █▀▚▖▗▛▀▙  ▗▛▀ █▖ ▟▌▗▛▀▘',
        ' ▐▌  █▄▟▌▐▌▜▘█ █ █▝▙█ █ ▐▌▐▌ █  ▐▌  █▝▛▐▌ ▀▙▖',
        ' ▝▙▄ █ ▐▌▐▌  █ █ █  █ █▄▞▘▝▙▄▛  ▝▙▄ █  ▐▌ ▄▄▛',
        '==============================================',
    ];

    private array $options = [];
    private bool $interactive = true;

    public function run(array $args, array $options): int
    {
        $this->options = $options;
        $this->interactive = empty($options['no-interaction']) && PHP_SAPI === 'cli';
        $installer = new Installer(fn(string $line) => $this->out('  ' . $line));
        $force = !empty($options['force']);
        $step = is_string($options['step'] ?? null) ? $options['step'] : '';
        if ($step !== '' && !in_array($step, self::STEPS, true)) {
            return $this->usage('unknown step - one of: ' . implode(', ', self::STEPS));
        }

        // Lock
        if ($installer->isInstalled() && !$force && $step === '') {
            $this->out('already installed (var/install.lock) - nothing to do; --force reinstalls the database');
            return Cli::OK;
        }
        if ($force && in_array($step, ['', 'database', 'admin'], true) && !$this->confirmForce($installer)) {
            return Cli::USAGE;
        }
        if (!$force && in_array($step, ['database', 'admin'], true) && $installer->isInstalled()) {
            return $this->usage("step $step on an installed system needs --force");
        }

        $this->out('');
        foreach (self::BANNER as $line) $this->out(' ' . $line);
        $this->out('');

        $steps = $step === '' ? self::STEPS : [$step];
        // Backend assets: copy (default) or symlink for development on the core
        $symlinkAssets = !empty($options['symlink-assets']);
        $fixtures = !empty($options['fixtures']);

        // 1. Voraussetzungen
        if (in_array('requirements', $steps, true)) {
            $this->out('requirements');
            $fatal = false;
            foreach ($installer->checkRequirements($symlinkAssets) as $check) {
                $this->out('  ' . ($check['ok'] ? '[ok] ' : ($check['fatal'] ? '[!!] ' : '[--] ')) . $check['text']);
                if (!$check['ok'] && $check['fatal']) $fatal = true;
            }
            if ($fatal) {
                $this->err('requirements not met');
                return Cli::ERROR;
            }
            if ($step !== '') return Cli::OK;
        }

        // 2. Questions - only what is needed for the selected steps
        $needConfig = in_array('config', $steps, true) && !is_file($installer->configFile());
        $needAdmin = in_array('admin', $steps, true) && !$fixtures;
        $answers = [];
        try {
            if ($needConfig) $answers = $this->askConfig($installer);
            if ($needAdmin) $answers['admin'] = $this->askAdmin($answers);
        }
        catch (\RuntimeException $e) {
            $this->err($e->getMessage());
            return Cli::USAGE;
        }

        // 3-5. Dot files
        if (in_array('key', $steps, true)) {
            $this->out('key file');
            if (Config::env(Config::ENV_KEY) !== null && !is_file($installer->keyFile())) {
                $this->out('  key from environment variable ' . Config::ENV_KEY . ' - no .camindo.key written');
            }
            else $installer->writeKeyFile();
        }
        if (in_array('config', $steps, true)) {
            $this->out('config file');
            if ($needConfig) $installer->writeConfigFile($answers);
            else $this->out('  .camindo.php exists - kept');
        }
        if (in_array('sqlpwd', $steps, true)) {
            $this->out('sqlpwd file');
            if (Config::env('CAMINDO_DB_PASS') !== null) {
                $this->out('  database password from environment variable CAMINDO_DB_PASS - no .sqlpwd needed');
            }
            else {
                $pass = $answers['db']['pass'] ?? (string)($installer->boot()->get(Config::class)->get('db.pass') ?? '');
                $installer->writeSqlpwdFile($pass);
            }
        }
        foreach ($installer->checkSecretPermissions() as $warning) $this->out('  WARNING: ' . $warning);

        // 6. Directories
        if (in_array('dirs', $steps, true)) {
            $this->out('directories');
            $installer->createDirectories($symlinkAssets);
        }

        // 7. Database
        if (in_array('database', $steps, true)) {
            $this->out('database' . ($fixtures ? ' (with fixtures)' : ''));
            $installer->createDatabase($fixtures, $force);
            $installer->createDefaultInstance();
        }

        // 8. First admin
        if (in_array('admin', $steps, true)) {
            $this->out('admin');
            if ($fixtures || ($step === '' && $installer->hasAdmin())) {
                $this->out('  admin exists (fixtures) - skipped');
            }
            else {
                $installer->createAdmin($answers['admin']);
            }
            $installer->createSchedulerAdmin();
        }

        // 9-12.
        if (in_array('core', $steps, true)) {
            $this->out('core');
            $installer->registerCore();
        }
        if (in_array('components', $steps, true)) {
            $this->out('components');
            $installer->refreshComponents();
        }
        if (in_array('runtime', $steps, true)) {
            $this->out('runtime');
            $installer->buildRuntime();
        }
        // Default scheduler tasks - after the group files (runtime): the
        // admin 'scheduler' needs the interface Command line (Scheduler::adminError())
        if (in_array('tasks', $steps, true)) {
            $this->out('tasks');
            $installer->createDefaultTasks();
        }
        if (in_array('finish', $steps, true)) {
            $this->out('finish');
            $installer->finish();
            $this->summary($installer);
        }
        return Cli::OK;
    }

    // ==== Fragen ====

    /**
     * Database, time zone, locale, programs. The connection is tested right after
     * the database questions; if it fails, the database questions are asked again
     * (interactive, with the previous answers as defaults).
     *
     * @return array  values for Installer::writeConfigFile()
     */
    private function askConfig(Installer $installer): array
    {
        $this->out('configuration');
        $db = [
            'host' => $this->value('db-host', 'CAMINDO_DB_HOST', 'Database host', 'localhost'),
            'port' => $this->value('db-port', 'CAMINDO_DB_PORT', 'Database port', '3306'),
            'name' => $this->value('db-name', 'CAMINDO_DB_NAME', 'Database name', 'camindo'),
            'user' => $this->value('db-user', 'CAMINDO_DB_USER', 'Database user', 'camindo'),
            'pass' => $this->secret('db-pass-file', 'CAMINDO_DB_PASS', 'Database password'),
        ];
        // From the environment (and not via --db-pass-file): stays there, .camindo.php stays empty
        $db['pass_from_env'] = !is_string($this->options['db-pass-file'] ?? null) && Config::env('CAMINDO_DB_PASS') !== null;
        while (true) {
            try {
                $installer->testDatabase($db, !empty($this->options['create-db']));
                $this->out('  database connection ok');
                break;
            }
            catch (\RuntimeException $e) {
                if (!$this->interactive) throw $e;
                $this->out('  ' . $e->getMessage() . ' - again');
            }
            // Ask directly: a wrong option or environment variable would otherwise repeat itself
            $db = [
                'host' => $this->ask('Database host', $db['host']),
                'port' => $this->ask('Database port', $db['port']),
                'name' => $this->ask('Database name', $db['name']),
                'user' => $this->ask('Database user', $db['user']),
                'pass' => $this->askHidden('Database password'),
                'pass_from_env' => false,
            ];
            // Config::ENV_SECRETS: in operation the variable wins over .camindo.php
            if (Config::env('CAMINDO_DB_PASS') !== null) {
                $this->out('  WARNING: environment variable CAMINDO_DB_PASS is set - in operation it takes');
                $this->out('  precedence over the password entered here; correct or remove it');
            }
        }
        $timezone = $this->value('timezone', 'CAMINDO_TIMEZONE', 'Time zone', 'Europe/Berlin');
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new \RuntimeException("unknown time zone '$timezone'");
        }
        $locale = $this->value('locale', 'CAMINDO_LOCALE', 'Locale', 'de_DE');
        if (!preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale)) {
            throw new \RuntimeException("locale must look like de_DE ('$locale')");
        }
        // HTTPS? Determines admin./user.cookie_secure. Default yes - only for a
        // local test without SSL "n" (development stack --dev: default n)
        $dev = !empty($this->options['dev']) || !empty($this->options['fixtures']);
        $https = strtolower($this->value('https', 'CAMINDO_HTTPS',
            'Will the site be served over HTTPS? (n = local test via http:// only)', $dev ? 'n' : 'y'));
        if (!in_array($https, ['y', 'yes', 'n', 'no'], true)) {
            throw new \RuntimeException("https: y or n ('$https')");
        }
        $https = $https[0] === 'y';
        if (!$https) {
            $this->out('  cookie_secure = false: login cookies also over http:// - for local testing only;');
            $this->out('  switch admin.cookie_secure and user.cookie_secure in .camindo.php to true once https:// works');
        }
        // Feste Backend-Domain (admin.backend_domain, BackendDomain) - leer: Backend
        // under every domain. Not asked in the development stack.
        $backendDomain = $dev && !isset($this->options['backend-domain']) ? '' : $this->value('backend-domain', 'CAMINDO_BACKEND_DOMAIN',
            'Backend domain, e.g. a subdomain nobody guesses (empty = /backend/ under every domain)', '', false);
        if ($backendDomain !== '' && \Camindo\Core\BackendDomain::normalize($backendDomain) === '') {
            throw new \RuntimeException("backend-domain: not a host name ('$backendDomain')");
        }
        return [
            'https'               => $https,
            'backend_domain'      => \Camindo\Core\BackendDomain::normalize($backendDomain),
            'db'                  => $db,
            'timezone'            => $timezone,
            'locale'              => $locale,
            'development_version' => !empty($this->options['dev']) || !empty($this->options['fixtures']),
            'paths'               => $installer->findPrograms(),
        ];
    }

    /**
     * First admin: name, password (hidden, twice), email, backend language.
     */
    private function askAdmin(array $answers): array
    {
        $this->out('first admin');
        $name = $this->value('admin-name', 'CAMINDO_ADMIN_NAME', 'Admin name', 'admin');
        if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
            throw new \RuntimeException("admin name: lowercase letters, digits, _ and - only ('$name')");
        }
        // Password policy as when saving (security.password_policy; the
        // .camindo.php of the new installation names none of its own, the defaults apply)
        $rules = PasswordPolicy::DEFAULTS;
        $hint = 'at least ' . implode(', ', array_filter([
            $rules['min_length'] ? $rules['min_length'] . ' characters' : '',
            $rules['min_special'] ? $rules['min_special'] . ' special characters' : '',
            $rules['min_upper'] ? $rules['min_upper'] . ' uppercase letters' . ($rules['upper_not_first'] ? ' (not counting the first character)' : '') : '',
            $rules['min_lower'] ? $rules['min_lower'] . ' lowercase letters' : '',
            $rules['min_digit'] ? $rules['min_digit'] . ' digits' : '',
        ]));
        while (true) {
            $pass = $this->secret('admin-pass-file', 'CAMINDO_ADMIN_PASS', 'Admin password (' . $hint . ')', true);
            if (mb_strlen($pass) >= 8 && !PasswordPolicy::failed($pass, $rules)) break;
            if (!$this->interactive || Config::env('CAMINDO_ADMIN_PASS') !== null
                || is_string($this->options['admin-pass-file'] ?? null)
            ) {
                throw new \RuntimeException('admin password: ' . $hint);
            }
            $this->out('  admin password: ' . $hint . ' - again');
        }
        $email = $this->value('admin-email', 'CAMINDO_ADMIN_EMAIL', 'Admin e-mail', '', false);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException("invalid e-mail '$email'");
        }
        // Backend languages = files in Core/backend/lang (en_US, de_DE, ar_SA …)
        $available = Cms::scanLanguages(dirname(__DIR__, 2) . '/backend/lang');
        $input = $this->value('admin-lang', 'CAMINDO_ADMIN_LANG', 'Backend language (' . implode('|', $available) . ')', Cms::FALLBACK_LANG);
        // Also the earlier two-letter codes (de, en) and locales (de-AT)
        $lang = Cms::matchLanguage($input, $available);
        if ($lang === null) throw new \RuntimeException("backend language: unknown ('$input') - one of " . implode(', ', $available));
        return [
            'name' => $name, 'email' => $email, 'password' => $pass, 'backend_code' => $lang,
            'timezone' => (string)($answers['timezone'] ?? 'Europe/Berlin'),
        ];
    }

    /**
     * Value from option, environment variable or question.
     *
     * @throws \RuntimeException  required value missing (--no-interaction)
     */
    private function value(string $option, string $env, string $question, string $default, bool $required = true): string
    {
        $value = is_string($this->options[$option] ?? null) ? $this->options[$option] : (string)getenv($env);
        if ($value === '' && $this->interactive) {
            $value = $this->ask($question, $default);
        }
        if ($value === '' && $required && $default !== '' && !$this->interactive) $value = $default;
        if ($value === '' && $required) throw new \RuntimeException("missing --$option (or $env)");
        return $value;
    }

    /**
     * Password from file (--*-pass-file), environment variable or hidden input.
     */
    private function secret(string $fileOption, string $env, string $question, bool $twice = false): string
    {
        $file = is_string($this->options[$fileOption] ?? null) ? $this->options[$fileOption] : '';
        if ($file !== '') {
            if (!is_readable($file)) throw new \RuntimeException("cannot read $file");
            return rtrim((string)file_get_contents($file), "\r\n");
        }
        $value = (string)Config::env($env);
        if ($value !== '' || !$this->interactive) {
            if ($value === '') throw new \RuntimeException("missing --$fileOption (or $env)");
            return $value;
        }
        while (true) {
            $value = $this->askHidden($question);
            if (!$twice) return $value;
            if ($value === $this->askHidden($question . ' (repeat)')) return $value;
            $this->out('  passwords differ - again');
        }
    }

    private function ask(string $question, string $default = ''): string
    {
        fwrite(STDOUT, '  ' . $question . ($default !== '' ? " [$default]" : '') . ': ');
        $line = fgets(STDIN);
        if ($line === false) throw new \RuntimeException('input closed');
        $line = trim($line);
        return $line === '' ? $default : $line;
    }

    private function askHidden(string $question): string
    {
        $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
        fwrite(STDOUT, '  ' . $question . ': ');
        if ($tty) shell_exec('stty -echo');
        $line = fgets(STDIN);
        if ($tty) { shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
        if ($line === false) throw new \RuntimeException('input closed');
        return rtrim($line, "\r\n");
    }

    /**
     * --force: confirm the database name (interactive) or --yes.
     */
    private function confirmForce(Installer $installer): bool
    {
        if (!empty($this->options['yes'])) return true;
        if (!$this->interactive) {
            $this->err('--force drops all tables - add --yes');
            return false;
        }
        $name = is_file($installer->configFile())
            ? (string)($installer->boot()->get(\Camindo\Core\Config::class)->get('db.name') ?? '')
            : $this->value('db-name', 'CAMINDO_DB_NAME', 'Database name', '');
        $this->out("--force drops ALL tables of database '$name'.");
        if ($this->ask("type the database name to confirm") !== $name) {
            $this->err('not confirmed');
            return false;
        }
        return true;
    }

    private function summary(Installer $installer): void
    {
        $dir = $installer->camindoDir();
        $this->out('');
        $this->out('camindo ' . CAMINDO_VERSION . ' installed.');
        $this->out("  document root:  $dir/public");
        $backendDomain = (string)$installer->boot()->get(\Camindo\Core\Config::class)->get('admin.backend_domain');
        $this->out('  backend:        https://' . ($backendDomain !== '' ? $backendDomain : '{host}') . '/backend/');
        $this->out('  cron job:       * * * * *  php ' . $dir . '/Core/cron/cron.php   (the only one)');
        $this->out('  next:           php Core/cron/camindo.php status');
    }
}
