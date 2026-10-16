<?php
/**
 * Update check: are there newer versions of camindo CMS and the installed
 * packages, and are security vulnerabilities known?
 *
 * The basis is Composer, which manages the installation anyway
 * (developer docs, chapter 09 (Installation and update)):
 *
 *     composer outdated --minor-only    newer versions of the installed packages
 *                                       (camindo/cms, add-on modules, libraries;
 *                                       direct and dependent) - only SemVer-compatible
 *                                       ones, i.e. what `composer update` installs
 *                                       within the usual ^ constraints; a new major
 *                                       version would be a change of composer.json
 *                                       and is not reported
 *     composer audit --format=json      known security vulnerabilities (advisory database
 *                                       of Packagist/GitHub) and abandoned packages
 *
 * Both need a network connection and take a few seconds. That is why the check runs as
 * a task of the scheduler (type updatecheck, default daily at night) and
 * on demand in the info page (StartBackend::info()); the stored result
 * (var/cache/update-check.json) is displayed. The start page warns
 * superusers via SystemHealth (check 'updates') - only about camindo/cms and
 * security vulnerabilities; other packages are listed on the info page only.
 *
 * Not covered: JavaScript libraries that are files in the core (jQuery,
 * Bootstrap, qrcode-generator, vanillajs-datepicker) - they come with the
 * update of camindo/cms.
 *
 * Composer gets its own COMPOSER_HOME under var/tmp: the web server user
 * usually has no writable home directory. paths.composer may be a whole command
 * line (PHP binary, options, composer.phar - command()); '' switches the check off.
 */
namespace Camindo\Core;

class UpdateCheck {

    /** Ergebnisdatei unter var/cache */
    public const CACHE_FILE = 'update-check.json';

    /** Maximum duration per Composer call in seconds */
    public const TIMEOUT = 120;

    public function __construct(
        private Config $config,
        private Execute $execute,
        private System $system,
    )
    {
    }

    /** Is the check set up (paths.composer)? */
    public function enabled(): bool
    {
        return trim((string)$this->config->get('paths.composer')) !== '';
    }

    /**
     * Stored result of the last check, null = never checked.
     *
     * @return array|null  checked_at (UTC), core (installed, latest|null),
     *                     outdated (list: name, version, latest, direct),
     *                     advisories (list: package, title, cve, link, severity,
     *                     affected), abandoned (list: package, replacement), error
     */
    public function result(): ?array
    {
        $file = $this->cacheFile();
        if (!is_file($file)) return null;
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Run the check and save the result.
     */
    public function run(): array
    {
        $result = [
            'checked_at' => gmdate('Y-m-d H:i:s'),
            'core' => ['installed' => defined('CAMINDO_VERSION') ? CAMINDO_VERSION : '', 'latest' => null],
            'outdated' => [],
            'advisories' => [],
            'abandoned' => [],
            'error' => '',
        ];
        if (!$this->enabled()) {
            $result['error'] = 'paths.composer is empty';
            return $result;
        }

        // composer outdated: exit code 0, JSON on stdout
        $outdated = $this->composer(['outdated', '--minor-only', '--format=json'], $error);
        if ($outdated === null) {
            $result['error'] = $error;
        }
        foreach ((array)($outdated['installed'] ?? []) as $package) {
            $name = (string)($package['name'] ?? '');
            $latest = (string)($package['latest'] ?? '');
            if ($name === '' || $latest === '' || $latest === (string)($package['version'] ?? '')) continue;
            // Only a major version left (e.g. 0.x -> 0.y): composer update would not install it
            if (($package['latest-status'] ?? '') === 'update-possible') continue;
            $entry = [
                'name' => $name,
                'version' => (string)($package['version'] ?? ''),
                'latest' => $latest,
                'direct' => !empty($package['direct-dependency']),
            ];
            if ($name === 'camindo/cms') $result['core']['latest'] = $latest;
            $result['outdated'][] = $entry;
        }

        // composer audit: exit code > 0 if there are findings - the JSON counts
        $audit = $this->composer(['audit', '--format=json', '--locked'], $error, acceptExitCodes: true);
        if ($audit === null) {
            $result['error'] = trim($result['error'] . ' ' . $error);
        }
        foreach ((array)($audit['advisories'] ?? []) as $package => $list) {
            foreach ((array)$list as $advisory) {
                $result['advisories'][] = [
                    'package' => (string)($advisory['packageName'] ?? $package),
                    'title' => (string)($advisory['title'] ?? ''),
                    'cve' => (string)($advisory['cve'] ?? ''),
                    'link' => (string)($advisory['link'] ?? ''),
                    'severity' => (string)($advisory['severity'] ?? ''),
                    'affected' => (string)($advisory['affectedVersions'] ?? ''),
                ];
            }
        }
        foreach ((array)($audit['abandoned'] ?? []) as $package => $replacement) {
            $result['abandoned'][] = ['package' => (string)$package, 'replacement' => (string)$replacement];
        }

        $file = $this->cacheFile();
        @mkdir(dirname($file), 0775, true);
        @file_put_contents($file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($result['error'] !== '') {
            $this->system->log('update-check', $result['error']);
        }
        return $result;
    }

    /**
     * Call Composer in the project directory and read the JSON output.
     *
     * @param bool $acceptExitCodes  Exit code other than 0 is not an error (audit)
     * @return array|null            null: error in $error
     */
    private function composer(array $args, ?string &$error, bool $acceptExitCodes = false): ?array
    {
        $error = '';
        $root = (string)$this->config->get('paths.camindo_dir');
        $home = rtrim((string)$this->config->get('paths.tmp_dir'), '/') . '/composer';
        @mkdir($home, 0775, true);
        putenv('COMPOSER_HOME=' . $home);
        putenv('COMPOSER_NO_INTERACTION=1');

        $command = array_merge(
            self::command((string)$this->config->get('paths.composer')),
            $args,
            ['--no-interaction', '--no-ansi', '--working-dir=' . $root]
        );
        $exit = $this->execute->execute($command, $stdout, $stderr, self::TIMEOUT);
        $json = json_decode((string)$stdout, true);
        if (is_array($json) && ($exit === 0 || $acceptExitCodes)) return $json;
        $error = 'composer ' . $args[0] . ': exit ' . $exit . ' ' . mb_substr(trim((string)$stderr ?: (string)$stdout), 0, 300);
        return null;
    }

    /**
     * paths.composer as argument list: words separated by whitespace, a word
     * containing spaces in double quotes.
     *
     *     '/usr/bin/php8.4 -d memory_limit=-1 /home/www/composer.phar'
     *     -> ['/usr/bin/php8.4', '-d', 'memory_limit=-1', '/home/www/composer.phar']
     */
    public static function command(string $setting): array
    {
        preg_match_all('/"([^"]*)"|(\S+)/', trim($setting), $matches, PREG_SET_ORDER);
        return array_map(fn(array $m) => ($m[2] ?? '') !== '' ? $m[2] : $m[1], $matches);
    }

    private function cacheFile(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/') . '/cache/' . self::CACHE_FILE;
    }
}
