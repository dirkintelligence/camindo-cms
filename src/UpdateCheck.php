<?php
/**
 * Update-Prüfung: gibt es neuere Versionen von camindo CMS und den installierten
 * Paketen, und sind Sicherheitslücken bekannt?
 *
 * Grundlage ist Composer, das die Installation ohnehin verwaltet
 * (docs/developers/14-installation-und-update.md):
 *
 *     composer outdated --format=json   neuere Versionen aller installierten Pakete
 *                                       (camindo/cms, Zusatzmodule, Bibliotheken;
 *                                       direkte und abhängige)
 *     composer audit --format=json      bekannte Sicherheitslücken (Advisory-Datenbank
 *                                       von Packagist/GitHub) und aufgegebene Pakete
 *
 * Beides braucht Netz und dauert einige Sekunden. Deshalb läuft die Prüfung als
 * Aufgabe des Zeitplaners (Typ updatecheck, Vorgabe täglich nachts) und
 * auf Knopfdruck in der Info-Seite (StartBackend::info()); angezeigt wird das
 * gespeicherte Ergebnis (var/cache/update-check.json). Die Startseite warnt
 * Superuser über SystemHealth (Prüfung 'updates').
 *
 * Nicht erfasst: Javascript-Bibliotheken, die als Dateien im Core liegen (jQuery,
 * Bootstrap, qrcode-generator, vanillajs-datepicker) - sie kommen mit dem
 * Update von camindo/cms.
 *
 * Composer bekommt ein eigenes COMPOSER_HOME unter var/tmp: der Webserver-Benutzer
 * hat meist kein beschreibbares Home-Verzeichnis. paths.composer = '' schaltet die
 * Prüfung ab.
 */
namespace Camindo\Core;

class UpdateCheck {

    /** Ergebnisdatei unter var/cache */
    public const CACHE_FILE = 'update-check.json';

    /** Höchstdauer je Composer-Aufruf in Sekunden */
    public const TIMEOUT = 120;

    public function __construct(
        private Config $config,
        private Execute $execute,
        private System $system,
    )
    {
    }

    /** Ist die Prüfung eingerichtet (paths.composer)? */
    public function enabled(): bool
    {
        return trim((string)$this->config->get('paths.composer')) !== '';
    }

    /**
     * Gespeichertes Ergebnis der letzten Prüfung, null = noch nie geprüft.
     *
     * @return array|null  checked_at (UTC), core (installed, latest|null),
     *                     outdated (Liste: name, version, latest, direct, status),
     *                     advisories (Liste: package, title, cve, link, severity,
     *                     affected), abandoned (Liste: package, replacement), error
     */
    public function result(): ?array
    {
        $file = $this->cacheFile();
        if (!is_file($file)) return null;
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Prüfung ausführen und das Ergebnis speichern.
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

        // composer outdated: Exit-Code 0, JSON auf stdout
        $outdated = $this->composer(['outdated', '--format=json'], $error);
        if ($outdated === null) {
            $result['error'] = $error;
        }
        foreach ((array)($outdated['installed'] ?? []) as $package) {
            $name = (string)($package['name'] ?? '');
            $latest = (string)($package['latest'] ?? '');
            if ($name === '' || $latest === '' || $latest === (string)($package['version'] ?? '')) continue;
            $entry = [
                'name' => $name,
                'version' => (string)($package['version'] ?? ''),
                'latest' => $latest,
                'direct' => !empty($package['direct-dependency']),
                // semver-safe-update | update-possible (neue Hauptversion)
                'status' => (string)($package['latest-status'] ?? ''),
            ];
            if ($name === 'camindo/cms') $result['core']['latest'] = $latest;
            $result['outdated'][] = $entry;
        }

        // composer audit: Exit-Code > 0, wenn es Befunde gibt - das JSON zählt
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
     * Composer im Projektverzeichnis aufrufen und die JSON-Ausgabe lesen.
     *
     * @param bool $acceptExitCodes  Exit-Code ungleich 0 ist kein Fehler (audit)
     * @return array|null            null: Fehler in $error
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
            [(string)$this->config->get('paths.composer')],
            $args,
            ['--no-interaction', '--no-ansi', '--working-dir=' . $root]
        );
        $exit = $this->execute->execute($command, $stdout, $stderr, self::TIMEOUT);
        $json = json_decode((string)$stdout, true);
        if (is_array($json) && ($exit === 0 || $acceptExitCodes)) return $json;
        $error = 'composer ' . $args[0] . ': exit ' . $exit . ' ' . mb_substr(trim((string)$stderr ?: (string)$stdout), 0, 300);
        return null;
    }

    private function cacheFile(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/') . '/cache/' . self::CACHE_FILE;
    }
}
