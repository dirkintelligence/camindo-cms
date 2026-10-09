<?php
/**
 * Der Installer - die Schritte hinter `php Core/cron/camindo.php install`.
 *
 * Läuft genau einmal je Installation (var/install.lock) und macht aus frischen
 * Dateien (composer create-project bzw. Git-Checkout + composer install) ein
 * laufendes CMS. Jeder Schritt ist eine Methode mit eigener Fehlermeldung; die
 * Datei-Schritte sind wiederholbar (sie legen nur an, was fehlt), die
 * Datenbank-Schritte laufen nur ohne Lock oder mit --force. Fragen stellt der
 * Installer nicht selbst - das macht InstallCommand und reicht die Antworten herein.
 *
 * Schritte (docs/developers/14-installation-und-update.md, 4.1):
 *
 *     1  checkRequirements()   PHP, Extensions, Schreibrechte, Programme (Symlinks nur mit --symlink-assets)
 *     2  (Fragen - InstallCommand)
 *     3  writeKeyFile()        .camindo.key, 0600
 *     4  writeConfigFile()     .camindo.php aus templates/camindo.php.dist, 0600
 *     5  writeSqlpwdFile()     .sqlpwd für mysqldump, 0600
 *     6  createDirectories()   var/*, public/media/default, Backend-Assets (Kopie oder Symlink)
 *     7  createDatabase()      schema.sql + seed.sql, mit --fixtures schema.sql + dev.sql (--force: vorher leeren)
 *     8  createAdmin()         erster Admin, Gruppe 1; createSchedulerAdmin() - der
 *                              Admin, unter dem die Aufgaben des Zeitplaners laufen
 *     9  registerCore()        system.core_version, installed_at
 *    10  refreshComponents()   ComponentManager::refresh() - entdecken, nicht installieren
 *    11  buildRuntime()        Snippet-/HTML-Cache, InstanceCache, .htaccess, Versionsstempel
 *    12  createDefaultTasks()  Vorgabe-Aufgaben des Zeitplaners (Scheduler::DEFAULT_TASKS)
 *    13  finish()              var/install.lock
 *
 * Ab Schritt 7 braucht der Installer einen Container, dessen Config die eben
 * geschriebene .camindo.php kennt - boot() baut ihn (der Container des CLI-Aufrufs
 * kann mit einer leeren Konfiguration gestartet sein).
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
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\SnippetCache;
use Camindo\Core\System;
use Camindo\Core\SystemState;
use Closure;

class Installer {

    public const REQUIRED_EXTENSIONS = ['mysqli', 'intl', 'json', 'gd', 'mbstring'];
    public const VAR_DIRS = ['cache', 'config', 'custom', 'dbbackup', 'deleted', 'download', 'export', 'extensions',
        'import', 'log', 'php', 'templates', 'tmp', 'upload'];
    // Programme, die der Installer sucht: Config-Schlüssel => Kandidaten
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
     * @return array  Liste von ['ok' => bool, 'text' => string, 'fatal' => bool]
     */
    public function checkRequirements(bool $symlinkAssets = false): array
    {
        $checks = [];
        $checks[] = ['ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'fatal' => true,
            'text' => 'PHP >= 8.1 (' . PHP_VERSION . ')'];
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
     * Programme per `which` suchen: Config-Schlüssel => absoluter Pfad oder ''.
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

    // ==== 2 Datenbankverbindung ====

    /**
     * Verbindung mit den Antworten prüfen; fehlt die Datenbank, wird sie mit
     * $createDb angelegt.
     *
     * @param array $db  host, port, name, user, pass
     * @throws \RuntimeException  mit dem mysqli-Fehler
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
                // neutrale Kollation des Servers (MySQL 8: utf8mb4_0900_ai_ci, siehe Collation)
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

    // ==== 3-5 Punkt-Dateien ====

    /**
     * .camindo.key - 32 Zufallsbytes, base64. Nur wenn die Datei fehlt: Verlust =
     * verschlüsselte Felder (E-Mail, Telefon) unlesbar.
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
     * .camindo.php aus Core/install/templates/camindo.php.dist.
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
            // Ohne Angabe sicher (https); false nur, wenn der Installer "kein HTTPS" hört
            '{cookie_secure}'       => ($values['https'] ?? true) ? 'true' : 'false',
            '{backend_domain}'      => var_export((string)($values['backend_domain'] ?? ''), true),
            '{development_version}' => $dev ? 'true' : 'false',
            '{locale}'   => var_export((string)$values['locale'], true),
            '{timezone}' => var_export((string)$values['timezone'], true),
            '{db_host}'  => var_export((string)$values['db']['host'], true),
            '{db_port}'  => (string)(int)($values['db']['port'] ?: 3306),
            '{db_name}'  => var_export((string)$values['db']['name'], true),
            '{db_user}'  => var_export((string)$values['db']['user'], true),
            // Passwort aus CAMINDO_DB_PASS: bleibt in der Umgebung (Config::ENV_SECRETS)
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
     * .sqlpwd - [client]-Block für mysqldump (--defaults-extra-file).
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
    }

    /**
     * Warnung, wenn eine Punkt-Datei für andere lesbar ist.
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
        return $warnings;
    }

    // ==== 6 Verzeichnisse ====

    /**
     * var/*, public/media/default (aus Core/install/media_default), public/backend/assets.
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
     * public/backend/assets aus Core/public_master/backend/assets: standardmäßig eine
     * Kopie (läuft auf jedem Webserver, auch ohne FollowSymLinks; upgrade frischt sie
     * auf - refreshAssets()), mit $symlink ein relativer Symlink für die Entwicklung
     * am Core (scheitert er, wird kopiert). Vorhandenes bleibt.
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
            // Link ohne Ziel (Verzeichnis verschoben, alter Core-Pfad): neu anlegen
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
     * Kopierte Backend-Assets auf den Stand des Cores bringen (upgrade): neu kopieren
     * und austauschen - so verschwinden auch Dateien, die der Core nicht mehr hat.
     * Ein Symlink bleibt, wie er ist.
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

    // ==== 7 Datenbank ====

    /**
     * schema.sql, seed.sql und mit $fixtures dev.sql. Ist die Datenbank nicht leer,
     * bricht der Schritt ab - außer mit $force (dann werden alle Tabellen gelöscht).
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
        // dev.sql ist ein vollständiger Datenbestand (enthält die Stammdaten) - mit
        // Fixtures entfällt seed.sql
        $files = ['schema.sql', $fixtures ? 'dev.sql' : 'seed.sql'];
        foreach ($files as $name) {
            $file = "$dir/$name";
            if (!is_file($file)) throw new \RuntimeException("missing: $file");
            $n = $sqlFile->run($file, fn(string $l) => null);
            $this->log("$name: $n statements");
        }
    }

    /**
     * Enthält die Datenbank schon einen Admin? (mit --fixtures: ja, `claude` und `dirk`)
     */
    public function hasAdmin(): bool
    {
        $db = $this->boot()->get(Database::class);
        $db->query('SELECT COUNT(*) FROM `admin`');
        return (int)($db->fetchNum()[0] ?? 0) > 0;
    }

    // ==== 8 Erster Admin ====

    /**
     * Superuser in Gruppe 1 über die Dataset-Klasse Admin (Passwort-Hash,
     * verschlüsselte E-Mail, Einstellungen).
     *
     * @param array $admin  name, password, email, backend_code, timezone
     * @return int  admin_id
     */
    public function createAdmin(array $admin): int
    {
        $app = $this->boot();
        // Die Eingabeprüfung meldet über Snippets - der Cache muss dafür da sein
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
        // Feldliste wie AdminBackend::_editSave(): uid/uid_date setzt hookOnCreate()
        $id = $dataset->save(['name', 'password', 'group_id', 'displayname', 'email', 'settings', 'ft'], $data);
        if ($id === null) {
            throw new \RuntimeException('admin could not be created: ' . $app->get(\Camindo\Core\Cms::class)->errorMsg());
        }
        $this->log("admin '{$admin['name']}' created (id $id)");
        return (int)$id;
    }

    /**
     * Admin des Zeitplaners ('scheduler', Scheduler::DEFAULT_ADMIN): jede Aufgabe läuft
     * unter einem Admin (SchedulerRunner, ApiSession::runAs()); neue Aufgaben
     * bekommen diesen vorgegeben. Superuser-Gruppe, ein zufälliges Passwort, das
     * niemand kennt - eine Anmeldung im Backend gibt es für ihn nicht. Besteht er
     * schon (Entwicklungsdaten), bleibt es dabei.
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
     * Vorgabe-Aufgaben des Zeitplaners anlegen (Scheduler::createDefaults()): Wartung
     * wie Papierkorb, var/tmp, Lösch-Policies, Fristen, Logs, Backup - nachts verteilt.
     * Wiederholbar: vorhandene Typen bleiben unangetastet.
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

    // ==== 9-12 Registrieren, Komponenten, Laufzeit, Abschluss ====

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
     * Container mit der Konfiguration aus .camindo.php - unabhängig vom Container
     * des CLI-Aufrufs, der vor dem Schreiben der Datei gestartet sein kann.
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
        // Klassen, die den Container global holen (InstanceCache, Area, …), sollen
        // denselben sehen - der des CLI-Aufrufs kennt die Datenbank nicht
        $GLOBALS['app'] = $app;
        return $this->app = $app;
    }
}
