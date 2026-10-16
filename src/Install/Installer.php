<?php
/**
 * The installer - the steps behind `php Core/cron/camindo.php install`.
 *
 * Runs exactly once per installation (var/install.lock) and turns fresh
 * files (composer create-project or Git checkout + composer install) into a
 * running CMS. Each step is a method with its own error message; the
 * file steps are repeatable (they only create what is missing), the
 * database steps run only without lock or with --force. The
 * installer does not ask questions itself - InstallCommand does that and passes the answers in.
 *
 * Steps (developer docs, chapter 09 (Installation and update)):
 *
 *     1  checkRequirements()   PHP, extensions, write permissions, programs (symlinks only with --symlink-assets)
 *     2  (Fragen - InstallCommand)
 *     3  writeKeyFile()        .camindo.key, 0600
 *     4  writeConfigFile()     .camindo.php from templates/camindo.php.dist, 0600
 *     5  writeSqlpwdFile()     .sqlpwd for mysqldump, 0600
 *                              as root: owner = web server user (SecretFile, setWebUser())
 *     6  createDirectories()   var/*, public/media/default, backend assets (copy or symlink)
 *     7  createDatabase()      schema.sql + seed.sql, with --fixtures schema.sql + dev.sql (--force: empty beforehand);
 *                              createDefaultInstance() - instance 1, the catch-all for every domain
 *     8  createAdmin()         first admin, group 1; createSchedulerAdmin() - the
 *                              admin under which the scheduler tasks run
 *     9  registerCore()        system.core_version, installed_at
 *    10  refreshComponents()   ComponentManager::refresh() - discover, not install
 *    11  buildRuntime()        Snippet-/HTML-Cache, InstanceCache, .htaccess, Versionsstempel
 *    12  createDefaultTasks()  default tasks of the scheduler (Scheduler::DEFAULT_TASKS)
 *                              createDefaultConverter() - media converter "internal preview (GD)"
 *    13  finish()              var/install.lock
 *
 * From step 7 the installer needs a container whose config knows the just
 * written .camindo.php - boot() builds it (the container of the CLI call
 * may have been started with an empty configuration).
 */
namespace Camindo\Core\Install;

use Camindo\Core\Cms;
use Camindo\Core\Collation;
use Camindo\Core\ComponentManager;
use Camindo\Core\Config;
use Camindo\Core\Container;
use Camindo\Core\ContainerInterface;
use Camindo\Core\Database;
use Camindo\Core\HtaccessWriter;
use Camindo\Core\HtmlCache;
use Camindo\Core\InstanceCache;
use Camindo\Core\MediaAccess;
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\Modules\Instance\Instance;
use Camindo\Core\SecretFile;
use Camindo\Core\SnippetCache;
use Camindo\Core\System;
use Camindo\Core\SystemState;
use Closure;

class Installer {

    public const REQUIRED_EXTENSIONS = ['mysqli', 'intl', 'json', 'gd', 'mbstring'];
    public const VAR_DIRS = ['cache', 'config', 'custom', 'dbbackup', 'deleted', 'download', 'export', 'extensions',
        'import', 'log', 'php', 'templates', 'tmp', 'upload'];
    // programs the installer searches for: config key => candidates
    public const PROGRAMS = [
        'php'       => ['php'],
        'mysqldump' => ['mysqldump', 'mariadb-dump'],
        'magick'    => ['magick', 'convert'],
        'ffmpeg'    => ['ffmpeg'],
        'ffprobe'   => ['ffprobe'],
    ];

    private string $camindoDir;
    private string $coreDir;
    private ?ContainerInterface $app = null;

    /** Web server user given explicitly (--web-user), SecretFile::user() format */
    private ?array $webUser = null;

    /**
     * @param Closure $log  function(string $line): void
     */
    public function __construct(private Closure $log)
    {
        $this->coreDir = dirname(__DIR__, 2);
        $this->camindoDir = dirname($this->coreDir);
    }

    public function camindoDir(): string
    {
        return $this->camindoDir;
    }

    public function configFile(): string
    {
        return $this->camindoDir . '/.camindo.php';
    }

    public function keyFile(): string
    {
        return $this->camindoDir . '/.camindo.key';
    }

    public function sqlpwdFile(): string
    {
        return $this->camindoDir . '/.sqlpwd';
    }

    /** The secret files: key, retired keys, configuration, mysqldump password */
    public function secretFiles(): array
    {
        return [$this->keyFile(), $this->keyFile() . '.retired', $this->configFile(), $this->sqlpwdFile()];
    }

    /**
     * Web server user for the secret files (--web-user / CAMINDO_WEB_USER).
     *
     * @throws \RuntimeException  unknown user
     */
    public function setWebUser(string $name): void
    {
        $user = SecretFile::user($name);
        if ($user === null) throw new \RuntimeException("web user: unknown user '$name'");
        $this->webUser = $user;
    }

    public function hasExplicitWebUser(): bool
    {
        return $this->webUser !== null;
    }

    /** Web server user: given explicitly, otherwise derived (SecretFile::guessWebUser()) */
    public function webUser(): ?array
    {
        return $this->webUser ?? SecretFile::guessWebUser($this->camindoDir);
    }

    /**
     * As root: hand a secret file to the web server user. Without root nothing to do
     * (the file belongs to the user running the CLI); without a known user
     * secretOwnershipWarnings() reports it. Never aborts.
     */
    public function adoptOwner(string $file): void
    {
        if (!is_file($file) || !SecretFile::isRoot()) return;
        $user = $this->webUser();
        if ($user === null || $user['uid'] === 0) return;
        if (SecretFile::chown($file, $user)) $this->log(basename($file) . ": owner {$user['name']}");
        else $this->log('WARNING: ' . basename($file) . ": chown {$user['name']} failed");
    }

    public function lockFile(): string
    {
        return $this->camindoDir . '/var/install.lock';
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockFile());
    }

    private function log(string $line): void
    {
        ($this->log)($line);
    }

    // ==== 1 Voraussetzungen ====

    /**
     * @return array  list of ['ok' => bool, 'text' => string, 'fatal' => bool]
     */
    public function checkRequirements(bool $symlinkAssets = false): array
    {
        $checks = [];
        $checks[] = ['ok' => version_compare(PHP_VERSION, '8.3.0', '>='), 'fatal' => true,
            'text' => 'PHP >= 8.3 (' . PHP_VERSION . ')'];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $checks[] = ['ok' => extension_loaded($ext), 'fatal' => true, 'text' => "PHP extension $ext"];
        }
        foreach (['', '/var', '/public', '/public/backend'] as $rel) {
            $dir = $this->camindoDir . $rel;
            $ok = is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir));
            $checks[] = ['ok' => $ok, 'fatal' => true, 'text' => 'writable: ' . ($rel === '' ? '.' : ltrim($rel, '/'))];
        }
        $checks[] = ['ok' => is_dir($this->camindoDir . '/vendor') && is_file($this->camindoDir . '/vendor/autoload.php'),
            'fatal' => true, 'text' => 'vendor/autoload.php (composer install)'];
        $checks[] = ['ok' => is_file($this->coreDir . '/install/database/schema.sql'), 'fatal' => true,
            'text' => 'Core/install/database/schema.sql'];
        if ($symlinkAssets) {
            $checks[] = ['ok' => $this->canSymlink(), 'fatal' => false,
                'text' => 'symlinks possible (otherwise the assets are copied)'];
        }
        foreach ($this->findPrograms() as $key => $path) {
            $checks[] = ['ok' => $path !== '', 'fatal' => false, 'text' => "$key: " . ($path ?: 'not found')];
        }
        return $checks;
    }

    /**
     * Search programs via `which`: config key => absolute path or ''.
     */
    public function findPrograms(): array
    {
        $found = [];
        foreach (self::PROGRAMS as $key => $candidates) {
            $found[$key] = '';
            foreach ($candidates as $name) {
                $path = $this->which($name);
                if ($path !== '') { $found[$key] = $path; break; }
            }
        }
        if ($found['php'] === '' && PHP_BINARY !== '') $found['php'] = PHP_BINARY;
        return $found;
    }

    private function which(string $name): string
    {
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
            $file = rtrim($dir, '/') . '/' . $name;
            if ($dir !== '' && is_file($file) && is_executable($file)) return $file;
        }
        return '';
    }

    private function canSymlink(): bool
    {
        $target = $this->camindoDir . '/var';
        $link = $this->camindoDir . '/var/.symlink_test_' . getmypid();
        if (!is_dir($target)) return function_exists('symlink');
        $ok = function_exists('symlink') && @symlink($target, $link) && is_link($link);
        @unlink($link);
        return $ok;
    }

    // ==== 2 Database connection ====

    /**
     * Check the connection with the answers; if the database is missing, it is created with
     * $createDb angelegt.
     *
     * @param array $db  host, port, name, user, pass
     * @throws \RuntimeException  with the mysqli error
     */
    public function testDatabase(array $db, bool $createDb): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $mysqli = new \mysqli($db['host'], $db['user'], $db['pass'], '', (int)$db['port']);
        }
        catch (\mysqli_sql_exception $e) {
            throw new \RuntimeException('database connection failed: ' . $e->getMessage());
        }
        try {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $db['name'])) {
                throw new \RuntimeException("invalid database name '{$db['name']}'");
            }
            $result = $mysqli->query("SHOW DATABASES LIKE '" . $mysqli->real_escape_string($db['name']) . "'");
            if ($result === false || $result->num_rows === 0) {
                if (!$createDb) {
                    throw new \RuntimeException("database '{$db['name']}' does not exist (--create-db creates it)");
                }
                // neutral collation of the server (MySQL 8: utf8mb4_0900_ai_ci, see Collation)
                $available = array_column($mysqli->query("SHOW COLLATION WHERE `Charset` = 'utf8mb4'")->fetch_all(), 0);
                $collation = Collation::neutral($available) ?: 'utf8mb4_unicode_ci';
                $mysqli->query("CREATE DATABASE `{$db['name']}` CHARACTER SET utf8mb4 COLLATE $collation");
                $this->log("database {$db['name']} created");
            }
            $mysqli->select_db($db['name']);
        }
        catch (\mysqli_sql_exception $e) {
            throw new \RuntimeException('database: ' . $e->getMessage());
        }
        finally {
            $mysqli->close();
        }
    }

    // ==== 3-5 Dot files ====

    /**
     * .camindo.key - 32 random bytes, base64. Only if the file is missing: loss =
     * encrypted fields (email, phone) unreadable.
     */
    public function writeKeyFile(): bool
    {
        $file = $this->keyFile();
        if (is_file($file)) {
            $this->log('.camindo.key exists - kept');
            return false;
        }
        $this->writeSecret($file, base64_encode(random_bytes(32)));
        $this->log('.camindo.key written');
        return true;
    }

    /**
     * .camindo.php from Core/install/templates/camindo.php.dist.
     *
     * @param array $values  locale, timezone, development_version (bool), db (host,
     *                       port, name, user, pass), paths (php, mysqldump, magick,
     *                       ffmpeg, ffprobe)
     */
    public function writeConfigFile(array $values): bool
    {
        $file = $this->configFile();
        if (is_file($file)) {
            $this->log('.camindo.php exists - kept');
            return false;
        }
        $template = $this->coreDir . '/install/templates/camindo.php.dist';
        if (!is_file($template)) throw new \RuntimeException("template missing: $template");
        $dev = !empty($values['development_version']);
        $replace = [
            '{display_errors}'      => $dev ? 'true' : 'false',
            // Without specification safe (https); false only if the installer hears "no HTTPS"
            '{cookie_secure}'       => ($values['https'] ?? true) ? 'true' : 'false',
            '{backend_domain}'      => var_export((string)($values['backend_domain'] ?? ''), true),
            '{development_version}' => $dev ? 'true' : 'false',
            '{locale}'   => var_export((string)$values['locale'], true),
            '{timezone}' => var_export((string)$values['timezone'], true),
            '{db_host}'  => var_export((string)$values['db']['host'], true),
            '{db_port}'  => (string)(int)($values['db']['port'] ?: 3306),
            '{db_name}'  => var_export((string)$values['db']['name'], true),
            '{db_user}'  => var_export((string)$values['db']['user'], true),
            // password from CAMINDO_DB_PASS: stays in the environment (Config::ENV_SECRETS)
            '{db_pass}'  => var_export(!empty($values['db']['pass_from_env']) ? '' : (string)$values['db']['pass'], true),
        ];
        foreach (self::PROGRAMS as $key => $x) {
            $replace['{path_' . $key . '}'] = var_export((string)($values['paths'][$key] ?? ''), true);
        }
        $this->writeSecret($file, strtr((string)file_get_contents($template), $replace));
        $this->log('.camindo.php written');
        return true;
    }

    /**
     * .sqlpwd - [client] block for mysqldump (--defaults-extra-file).
     */
    public function writeSqlpwdFile(string $password): bool
    {
        $file = $this->sqlpwdFile();
        if (is_file($file)) {
            $this->log('.sqlpwd exists - kept');
            return false;
        }
        $this->writeSecret($file, "[client]\npassword=" . $password . "\n");
        $this->log('.sqlpwd written');
        return true;
    }

    private function writeSecret(string $file, string $content): void
    {
        if (@file_put_contents($file, $content, LOCK_EX) === false) {
            throw new \RuntimeException("cannot write $file");
        }
        @chmod($file, 0600);
        $this->adoptOwner($file);
    }

    /**
     * Warnings on the dot files: readable by others, or probably not readable by the
     * web server (SecretFile::ownershipWarnings()).
     *
     * @return string[]
     */
    public function checkSecretPermissions(): array
    {
        $warnings = [];
        foreach ([$this->configFile(), $this->keyFile(), $this->sqlpwdFile()] as $file) {
            if (is_file($file) && (fileperms($file) & 0077)) {
                $warnings[] = basename($file) . ' is readable by others - chmod 600 ' . basename($file);
            }
        }
        return array_merge($warnings, SecretFile::ownershipWarnings($this->secretFiles(), $this->webUser()));
    }

    // ==== 6 Directories ====

    /**
     * var/*, public/media/default (from Core/install/media_default), public/backend/assets.
     */
    public function createDirectories(bool $symlinkAssets = false): void
    {
        $app = $this->boot();
        $system = $app->get(System::class);
        $config = $app->get(Config::class);
        $varDir = (string)$config->get('paths.var_dir');
        if (!is_dir($varDir) && !@mkdir($varDir, 0775, true)) throw new \RuntimeException("cannot create $varDir");
        foreach (self::VAR_DIRS as $dir) {
            if (!$system->createDir($varDir, $dir)) throw new \RuntimeException("cannot create var/$dir");
        }
        $this->log('var/ directories ok');

        $mediaDir = (string)$config->get('paths.media_dir');
        if (!is_dir($mediaDir . '/default')) {
            $docroot = (string)$config->get('paths.document_root');
            if (!$system->createDir($docroot, substr($mediaDir, strlen($docroot)) . '/default')) {
                throw new \RuntimeException("cannot create $mediaDir/default");
            }
            $n = 0;
            foreach ((array)glob($this->coreDir . '/install/media_default/*') as $file) {
                if (copy($file, $mediaDir . '/default/' . basename($file))) $n++;
            }
            $this->log("media/default: $n files");
        }
        $this->createAssets($config, $symlinkAssets);
    }

    /**
     * public/backend/assets from Core/public_master/backend/assets: by default a
     * copy (runs on every web server, even without FollowSymLinks; upgrade refreshes it
     * - refreshAssets()), with $symlink a relative symlink for development
     * on the core (if it fails, it is copied). Existing content stays.
     */
    public function createAssets(Config $config, bool $symlink = false): void
    {
        $backendDir = (string)$config->get('paths.backend_dir');
        $link = $backendDir . '/assets';
        $target = $this->coreDir . '/public_master/backend/assets';
        if (is_dir($link)) {
            $this->log('backend/assets exists - kept');
            return;
        }
        if (is_link($link)) {
            // link without target (directory moved, old core path): create anew
            @unlink($link);
            $this->log('backend/assets: dangling symlink removed');
        }
        if (!is_dir($backendDir) && !@mkdir($backendDir, 0775, true)) throw new \RuntimeException("cannot create $backendDir");
        if ($symlink) {
            $relative = $this->relativePath($backendDir, $target);
            if (@symlink($relative, $link)) {
                $this->log("backend/assets -> $relative (symlink)");
                return;
            }
            $this->log('symlink failed - copying assets instead');
        }
        $this->copyDir($target, $link);
        $this->log('backend/assets copied (refreshed by every upgrade)');
    }

    /**
     * Bring copied backend assets to the state of the core (upgrade): copy anew
     * and replace - so files that the core no longer has also disappear.
     * A symlink stays as it is.
     *
     * @return string  'symlink' | 'refreshed' | 'created'
     */
    public function refreshAssets(Config $config): string
    {
        $link = (string)$config->get('paths.backend_dir') . '/assets';
        if (is_link($link) && is_dir($link)) return 'symlink';
        if (!is_dir($link)) {
            $this->createAssets($config);
            return 'created';
        }
        $target = $this->coreDir . '/public_master/backend/assets';
        $new = $link . '.new-' . getmypid();
        $old = $link . '.old-' . getmypid();
        $this->copyDir($target, $new);
        if (!@rename($link, $old)) {
            $this->removeDir($new);
            throw new \RuntimeException("cannot replace $link");
        }
        if (!@rename($new, $link)) {
            @rename($old, $link);
            throw new \RuntimeException("cannot replace $link");
        }
        $this->removeDir($old);
        return 'refreshed';
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = "$dir/$entry";
            if (is_dir($path) && !is_link($path)) $this->removeDir($path);
            else @unlink($path);
        }
        @rmdir($dir);
    }

    private function relativePath(string $from, string $to): string
    {
        $from = explode('/', trim($from, '/'));
        $to = explode('/', trim($to, '/'));
        while ($from && $to && $from[0] === $to[0]) { array_shift($from); array_shift($to); }
        return str_repeat('../', count($from)) . implode('/', $to);
    }

    private function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst) && !@mkdir($dst, 0775, true)) throw new \RuntimeException("cannot create $dst");
        foreach (scandir($src) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $s = "$src/$entry";
            $d = "$dst/$entry";
            if (is_dir($s)) $this->copyDir($s, $d);
            elseif (!copy($s, $d)) throw new \RuntimeException("cannot copy $s");
        }
    }

    // ==== 7 Database ====

    /**
     * schema.sql, seed.sql and with $fixtures dev.sql. If the database is not empty,
     * the step aborts - except with $force (then all tables are deleted).
     */
    public function createDatabase(bool $fixtures, bool $force): void
    {
        $app = $this->boot();
        $db = $app->get(Database::class);
        $db->query('SHOW TABLES');
        $tables = array_map('strval', $db->fetchColumn());
        if ($tables) {
            if (!$force) {
                throw new \RuntimeException('database is not empty (' . count($tables) . ' tables) - --force drops them');
            }
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $table) {
                if (preg_match('/^[A-Za-z0-9_]+$/', $table)) $db->query("DROP TABLE IF EXISTS `$table`");
            }
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
            $this->log(count($tables) . ' tables dropped');
        }
        $sqlFile = new SqlFile($db);
        $dir = $this->coreDir . '/install/database';
        // dev.sql is a complete data set (contains the master data) - with
        // fixtures, seed.sql is omitted
        $files = ['schema.sql', $fixtures ? 'dev.sql' : 'seed.sql'];
        foreach ($files as $name) {
            $file = "$dir/$name";
            if (!is_file($file)) throw new \RuntimeException("missing: $file");
            $n = $sqlFile->run($file, fn(string $l) => null);
            $this->log("$name: $n statements");
        }
    }

    /**
     * The default instance (Instance::DEFAULT_ID): catch-all for every domain that
     * no other instance claims - without it the frontend has nothing to serve.
     * seed.sql carries no instance; the development data (dev.sql) has its own.
     * Main language = Cms::FALLBACK_LANG (en_US). No domain, no protocol: both
     * come from the request. Repeatable: an existing instance 1 stays untouched.
     *
     * Plain SQL instead of Instance::save() - there is no admin yet; the
     * instance cache and the media lock follow in buildRuntime().
     */
    public function createDefaultInstance(): void
    {
        $app = $this->boot();
        $db = $app->get(Database::class);
        $db->query('SELECT COUNT(*) FROM `instance` WHERE `instance_id` = ?', 'i', [Instance::DEFAULT_ID]);
        if ((int)($db->fetchNum()[0] ?? 0) > 0) {
            $this->log('default instance exists');
            return;
        }
        $db->query('SELECT `language_id` FROM `language` WHERE `code` = ?', 's', [Cms::FALLBACK_LANG]);
        $languageId = (int)($db->fetchNum()[0] ?? 0);
        if ($languageId === 0) throw new \RuntimeException('default instance: no language ' . Cms::FALLBACK_LANG);
        $db->query(
            "INSERT INTO `instance` (`instance_id`, `label`, `position`, `primary_language_id`, `primary_domain`, `protocol`, `canonical`, `details`)"
            . " VALUES (?, 'Default', 1, ?, '', '', 0, '{}')",
            'ii', [Instance::DEFAULT_ID, $languageId]
        );
        $this->log('default instance created (language id ' . $languageId . ')');
    }

    /**
     * Does the database already contain an admin? (with --fixtures: yes, `claude` and `dirk`)
     */
    public function hasAdmin(): bool
    {
        $db = $this->boot()->get(Database::class);
        $db->query('SELECT COUNT(*) FROM `admin`');
        return (int)($db->fetchNum()[0] ?? 0) > 0;
    }

    // ==== 8 First admin ====

    /**
     * superuser in group 1 via the dataset class Admin (password hash,
     * encrypted email, settings).
     *
     * @param array $admin  name, password, email, backend_code, timezone
     * @return int  admin_id
     */
    public function createAdmin(array $admin): int
    {
        $app = $this->boot();
        // The input check reports via snippets - the cache must be there for this
        $varDir = (string)$app->get(Config::class)->get('paths.var_dir');
        if (!is_dir($varDir . '/cache/lang/' . Cms::FALLBACK_LANG)) $app->get(SnippetCache::class)->build();
        $dataset = $app->get(Admin::class);
        $data = $dataset->init([
            'group_id'    => 1,
            'name'        => (string)$admin['name'],
            'password'    => (string)$admin['password'],
            'email'       => (string)($admin['email'] ?? ''),
            'displayname' => (string)$admin['name'],
            'settings'    => [
                'language_id'  => '',
                'backend_code' => (string)($admin['backend_code'] ?? Cms::FALLBACK_LANG),
                'timezone'     => (string)($admin['timezone'] ?? 'UTC'),
                'fed'          => '0',
            ],
        ]);
        // Field list as in AdminBackend::_editSave(): uid/uid_date is set by hookOnCreate()
        $id = $dataset->save(['name', 'password', 'group_id', 'displayname', 'email', 'settings', 'ft'], $data);
        if ($id === null) {
            throw new \RuntimeException('admin could not be created: ' . $app->get(\Camindo\Core\Cms::class)->errorMsg());
        }
        $this->log("admin '{$admin['name']}' created (id $id)");
        return (int)$id;
    }

    /**
     * administrator of the scheduler ('scheduler', Scheduler::DEFAULT_ADMIN): every task runs
     * under one admin (SchedulerRunner, ApiSession::runAs()); new tasks
     * get this one. Superuser group, a random password that
     * nobody knows - there is no login to the backend for it. If it
     * already exists (development data), it stays that way.
     *
     * @return int  admin_id
     */
    public function createSchedulerAdmin(): int
    {
        $app = $this->boot();
        $dataset = $app->get(Admin::class);
        $name = \Camindo\Core\Modules\Scheduler\Scheduler::DEFAULT_ADMIN;
        $existing = $dataset->load($name, 'name');
        $app->get(Cms::class)->errorsReset();
        if ($existing) {
            $this->log("admin '{$name}' exists (id {$existing['admin_id']})");
            return (int)$existing['admin_id'];
        }
        $crypt = $app->get(\Camindo\Core\Crypt::class);
        $data = $dataset->init([
            'group_id'    => 1,
            'name'        => $name,
            'password'    => 'x' . $crypt->randomKey(40) . 'A9!z',
            'email'       => '',
            'displayname' => 'Scheduler',
            'settings'    => [
                'language_id'  => '',
                'backend_code' => Cms::FALLBACK_LANG,
                'timezone'     => (string)($app->get(Config::class)->get('system.timezone') ?: 'UTC'),
                'fed'          => '0',
            ],
        ]);
        $id = $dataset->save(['name', 'password', 'group_id', 'displayname', 'email', 'settings', 'ft'], $data);
        if ($id === null) {
            throw new \RuntimeException("admin '{$name}' could not be created: " . $app->get(Cms::class)->errorMsg());
        }
        $this->log("admin '{$name}' created (id $id)");
        return (int)$id;
    }

    /**
     * Create the default tasks of the scheduler (Scheduler::createDefaults()): maintenance
     * like trash, var/tmp, deletion policies, deadlines, logs, backup - distributed at night.
     * Repeatable: existing types remain untouched.
     */
    public function createDefaultTasks(): void
    {
        $app = $this->boot();
        $created = $app->get(\Camindo\Core\Modules\Scheduler\Scheduler::class)->createDefaults();
        $errors = $app->get(Cms::class)->errorMsg(' | ');
        if ($errors !== '') {
            throw new \RuntimeException('scheduler tasks could not be created: ' . $errors);
        }
        $this->log($created ? 'scheduler tasks created: ' . implode(', ', array_keys($created)) : 'scheduler tasks exist');
    }

    /**
     * Create the default media converter "internal preview (GD)": every image format
     * GD reads becomes a .jpg of at most 200 x 300 px, otherwise the processor defaults.
     * GD is a required extension, so the converter works on every installation.
     * Repeatable: only when there is no converter yet (with --fixtures there are some).
     */
    public function createDefaultConverter(): void
    {
        // fresh container: Cms reads the processor list (var/config/components/processors.php)
        // in its constructor - the one booted before refreshComponents() knows none
        $this->app = null;
        $app = $this->boot();
        $db = $app->get(Database::class);
        $db->query('SELECT COUNT(*) FROM `converter`');
        if ((int)($db->fetchNum()[0] ?? 0) > 0) {
            $this->log('converters exist');
            return;
        }
        $converter = $app->get(\Camindo\Core\Modules\Converter\Converter::class);
        $data = $converter->init(['processor' => 'ImageGd']);
        $data['processor'] = 'ImageGd';
        $data['label'] = '#converter.image_preview_gd';
        $data['extension'] = 'jpg';
        $data['media_types_incl'] = implode("\n", ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/avif']);
        $data['details']['width'] = 200;
        $data['details']['height'] = 300;
        $id = $converter->save([], $data);
        if ($id === null) {
            throw new \RuntimeException('converter could not be created: ' . $app->get(Cms::class)->errorMsg(' | '));
        }
        $this->log("converter 'internal preview (GD)' created (id $id)");
    }

    // ==== 9-12 Register, components, runtime, finish ====

    public function registerCore(): void
    {
        $system = new SystemTable($this->boot()->get(Database::class));
        $system->set('core_version', CAMINDO_VERSION);
        $system->set('installed_at', gmdate('Y-m-d H:i:s') . ' UTC');
        $this->log('core ' . CAMINDO_VERSION . ' registered');
    }

    /**
     * @return array  Zustandsliste (ComponentManager::status())
     */
    public function refreshComponents(): array
    {
        $status = $this->boot()->get(ComponentManager::class)->refresh(fn(string $l) => $this->log('  ' . $l));
        $discovered = array_keys(array_filter($status, fn($e) => $e['state'] === 'discovered'));
        if ($discovered) {
            $this->log('discovered, not installed: ' . implode(', ', $discovered)
                . ' - php Core/cron/camindo.php component install --all [--activate]');
        }
        return $status;
    }

    public function buildRuntime(): void
    {
        $app = $this->boot();
        $r = $app->get(SnippetCache::class)->build();
        $this->log("snippet cache: {$r['files']} files");
        $r = $app->get(HtmlCache::class)->build();
        $this->log("html cache: {$r['files']} files");
        $app->get(InstanceCache::class)->rebuild();
        $this->log('instance cache: built');
        $app->get(MediaAccess::class)->rebuildAll();
        $this->log('media locks: written');
        foreach ($app->get(\Camindo\Core\Modules\Docs\Docs::class)->rebuildIndex() as $lang => $r) {
            $this->log("docs index $lang: {$r['sections']} sections");
        }
        if (!$app->get(HtaccessWriter::class)->update()) throw new \RuntimeException('could not write public/.htaccess');
        $this->log('.htaccess: written');
        $app->get(SystemState::class)->writeVersionStamp(CAMINDO_VERSION);
        $this->log('version stamp ' . CAMINDO_VERSION . ' written');
    }

    public function finish(): void
    {
        $this->boot()->get(SystemState::class)->markInstalled();
        $this->log('var/install.lock written');
    }

    // ==== Container ====

    /**
     * container with the configuration from .camindo.php - independent of the container
     * of the CLI call, which may have been started before the file was written.
     */
    public function boot(): ContainerInterface
    {
        if ($this->app !== null) return $this->app;
        $file = $this->configFile();
        if (!is_file($file)) throw new \RuntimeException('.camindo.php missing');
        $cfg = (static function (string $file): array {
            $_cfg = [];
            require $file;
            return is_array($_cfg) ? $_cfg : [];
        })($file);
        $app = new Container();
        $app->set(Config::class, function () use ($cfg) { return new Config($cfg); });
        // Classes that fetch the container globally (InstanceCache, Area, …) should
        // see the same one - the CLI call's container does not know the database
        $GLOBALS['app'] = $app;
        return $this->app = $app;
    }
}
