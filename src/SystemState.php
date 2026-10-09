<?php
/**
 * Installation and maintenance state - without database.
 *
 * Three files under var/ tell every request whether it may run
 * (developer docs, chapter 09 (Installation and update), sections 2.2 and 7):
 *
 *     var/install.lock        installation completed (installer, step 12); if it is
 *                             missing AND the stamp too, camindo was never installed:
 *                             notice page pointing to the installer (isNotInstalled())
 *     var/config/core.php     version stamp: the state the database has been
 *                             migrated to - written by the installer and `upgrade`
 *     var/maintenance.lock    maintenance mode: time and reason
 *
 * If the stamp differs from CAMINDO_VERSION, new code runs on the old schema
 * (composer update ran, `upgrade` not yet) - the frontend then answers
 * with 503, just as in maintenance mode. The check costs one include and one
 * is_file() per request and needs no database connection; that is why it sits in
 * the entry points BEFORE the first $app->get() of a class with Database.
 *
 * Callers: Core/public_master/index.php, file.php (frontend: guardFrontend()),
 * Core/public_master/backend/index.php, module.php (backend: blockReason(), superusers
 * may continue), Core/cron/cron.php, scheduler.php (background jobs
 * terminate), Core/cron/camindo.php (writes lock and stamp).
 */
namespace Camindo\Core;

class SystemState {

    public const BLOCK_NOT_INSTALLED = 'not_installed';
    public const BLOCK_MAINTENANCE   = 'maintenance';
    public const BLOCK_VERSION       = 'version';

    // Stempel, einmal gelesen
    private ?array $stamp = null;
    private bool $stampLoaded = false;

    public function __construct(private Config $config)
    {
    }

    // ==== Dateien ====

    public function varDir(): string
    {
        return rtrim((string)$this->config->get('paths.var_dir'), '/\\');
    }

    public function stampFile(): string
    {
        return $this->varDir() . '/config/core.php';
    }

    public function maintenanceFile(): string
    {
        return $this->varDir() . '/maintenance.lock';
    }

    public function installFile(): string
    {
        return $this->varDir() . '/install.lock';
    }

    // ==== Installation ====

    public function isInstalled(): bool
    {
        return is_file($this->installFile());
    }

    public function markInstalled(): void
    {
        $this->write($this->installFile(), date('c') . "\n");
    }

    // ==== Versionsstempel ====

    /**
     * Database version according to var/config/core.php, null if the file is missing.
     */
    public function installedVersion(): ?string
    {
        return $this->stamp()['version'] ?? null;
    }

    public function upgradedAt(): ?string
    {
        return $this->stamp()['upgraded_at'] ?? null;
    }

    /**
     * true if the files (CAMINDO_VERSION) and the database (stamp) do not match -
     * also if the stamp is missing.
     */
    public function versionMismatch(): bool
    {
        return $this->installedVersion() !== CAMINDO_VERSION;
    }

    public function writeVersionStamp(string $version): void
    {
        $data = ['version' => $version, 'upgraded_at' => gmdate('Y-m-d H:i:s') . ' UTC'];
        $this->write(
            $this->stampFile(),
            "<?php\n// Generiert von Camindo\\Core\\SystemState - nicht von Hand ändern.\n"
            . "// Stand der Datenbank; abweichend von CAMINDO_VERSION = `upgrade` steht aus.\n"
            . 'return ' . var_export($data, true) . ";\n"
        );
        $this->stamp = $data;
        $this->stampLoaded = true;
    }

    private function stamp(): array
    {
        if (!$this->stampLoaded) {
            $this->stampLoaded = true;
            $file = $this->stampFile();
            $data = is_file($file) ? include $file : null;
            $this->stamp = is_array($data) ? $data : [];
        }
        return $this->stamp;
    }

    // ==== Wartungsmodus ====

    public function isMaintenance(): bool
    {
        return is_file($this->maintenanceFile());
    }

    /**
     * ['since' => 'ISO time', 'reason' => '…', 'age' => seconds] or null
     */
    public function maintenanceInfo(): ?array
    {
        $file = $this->maintenanceFile();
        if (!is_file($file)) return null;
        $lines = explode("\n", trim((string)file_get_contents($file)), 2);
        $since = $lines[0] ?? '';
        $ts = strtotime($since) ?: (int)filemtime($file);
        return [
            'since'  => $since,
            'reason' => trim($lines[1] ?? ''),
            'age'    => time() - $ts,
        ];
    }

    public function lock(string $reason = 'manual'): void
    {
        $this->write($this->maintenanceFile(), date('c') . "\n" . $reason . "\n");
    }

    public function unlock(): void
    {
        $file = $this->maintenanceFile();
        if (is_file($file) && !@unlink($file)) {
            throw new \RuntimeException("cannot remove $file");
        }
    }

    // ==== Sperre ====

    /**
     * Why the request may not run - or null.
     */
    public function blockReason(): ?string
    {
        if ($this->isMaintenance()) return self::BLOCK_MAINTENANCE;
        if ($this->installedVersion() === null) return self::BLOCK_NOT_INSTALLED;
        if ($this->versionMismatch()) return self::BLOCK_VERSION;
        return null;
    }

    /**
     * Readable message for blockReason() - for superusers in the backend and the CLI.
     */
    public function blockMessage(?string $reason = null): string
    {
        $reason ??= $this->blockReason();
        switch ($reason) {
            case self::BLOCK_MAINTENANCE:
                $info = $this->maintenanceInfo();
                return 'Maintenance mode since ' . ($info['since'] ?? '?')
                    . ($info['reason'] !== '' ? ' (' . $info['reason'] . ')' : '')
                    . ' - php Core/cron/camindo.php maintenance off';
            case self::BLOCK_NOT_INSTALLED:
                return $this->isInstalled()
                    ? 'Version stamp var/config/core.php missing - php Core/cron/camindo.php upgrade'
                    : 'Not installed - php Core/cron/camindo.php install';
            case self::BLOCK_VERSION:
                return 'Core ' . CAMINDO_VERSION . ' installed, database on ' . $this->installedVersion()
                    . ' - php Core/cron/camindo.php upgrade';
        }
        return '';
    }

    /**
     * Never installed: neither installer lock nor version stamp. Then there is
     * also no .camindo.php and no database - frontend and backend show the
     * notice pointing to the installer without loading anything.
     */
    public function isNotInstalled(): bool
    {
        return !$this->isInstalled() && $this->installedVersion() === null;
    }

    /**
     * Frontend: on a block send 503 and exit. No database access.
     */
    public function guardFrontend(): void
    {
        if ($this->isNotInstalled()) $this->sendUnavailable('not_installed.html');
        if ($this->blockReason() === null) return;
        $this->sendUnavailable();
    }

    /**
     * Backend, before the session is loaded: without installation the installer page.
     */
    public function guardNotInstalled(): void
    {
        if ($this->isNotInstalled()) $this->sendUnavailable('not_installed.html');
    }

    /**
     * 503 with the maintenance page: var/custom/{template}, otherwise
     * Core/install/templates/{template}.
     */
    public function sendUnavailable(string $template = 'maintenance.html'): never
    {
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 300');
            header('Cache-Control: no-store');
            header('Content-Type: text/html; charset=utf-8');
        }
        $custom = $this->varDir() . '/custom/' . $template;
        $file = is_file($custom)
            ? $custom
            : (string)$this->config->get('paths.core_dir') . '/install/templates/' . $template;
        echo is_file($file) ? file_get_contents($file) : "<h1>503 Service Unavailable</h1>\n";
        exit;
    }

    // ==== Helfer ====

    private function write(string $file, string $content): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new \RuntimeException("cannot create $dir");
        }
        if (@file_put_contents($file, $content, LOCK_EX) === false) {
            throw new \RuntimeException("cannot write $file");
        }
        @chmod($file, (int)($this->config->get('permissions.file') ?? 0664));
    }
}
