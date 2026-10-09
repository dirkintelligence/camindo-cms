<?php
/**
 * Installations- und Wartungszustand - ohne Datenbank.
 *
 * Drei Dateien unter var/ sagen jedem Request, ob er laufen darf
 * (docs/developers/14-installation-und-update.md, Abschnitte 2.2 und 7):
 *
 *     var/install.lock        Installation abgeschlossen (Installer, Schritt 12); fehlt
 *                             er UND der Stempel, ist camindo nie installiert worden:
 *                             Hinweisseite auf den Installer (isNotInstalled())
 *     var/config/core.php     Versionsstempel: auf welchen Stand die Datenbank
 *                             migriert ist - geschrieben von Installer und `upgrade`
 *     var/maintenance.lock    Wartungsmodus: Zeitpunkt und Grund
 *
 * Weicht der Stempel von CAMINDO_VERSION ab, liegt neuer Code auf altem Schema
 * (composer update gelaufen, `upgrade` noch nicht) - das Frontend antwortet dann
 * mit 503, genau wie im Wartungsmodus. Die Prüfung kostet ein include und ein
 * is_file() je Request und braucht keine Datenbankverbindung; deshalb steht sie in
 * den Einstiegspunkten VOR dem ersten $app->get() einer Klasse mit Database.
 *
 * Aufrufer: Core/public_master/index.php, file.php (Frontend: guardFrontend()),
 * Core/public_master/backend/index.php, module.php (Backend: blockReason(), Superuser
 * dürfen weiter), Core/cron/cron.php, scheduler.php (Hintergrundjobs
 * beenden sich), Core/cron/camindo.php (schreibt Lock und Stempel).
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
     * Stand der Datenbank laut var/config/core.php, null wenn die Datei fehlt.
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
     * true, wenn die Dateien (CAMINDO_VERSION) und die Datenbank (Stempel) nicht
     * zusammenpassen - auch, wenn der Stempel fehlt.
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
     * ['since' => 'ISO-Zeit', 'reason' => '…', 'age' => Sekunden] oder null
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
     * Warum der Request nicht laufen darf - oder null.
     */
    public function blockReason(): ?string
    {
        if ($this->isMaintenance()) return self::BLOCK_MAINTENANCE;
        if ($this->installedVersion() === null) return self::BLOCK_NOT_INSTALLED;
        if ($this->versionMismatch()) return self::BLOCK_VERSION;
        return null;
    }

    /**
     * Lesbare Meldung zu blockReason() - für Superuser im Backend und die CLI.
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
     * Noch nie installiert: weder Installer-Lock noch Versionsstempel. Dann gibt es
     * auch keine .camindo.php und keine Datenbank - Frontend und Backend zeigen den
     * Hinweis auf den Installer, ohne irgendetwas zu laden.
     */
    public function isNotInstalled(): bool
    {
        return !$this->isInstalled() && $this->installedVersion() === null;
    }

    /**
     * Frontend: bei Sperre 503 senden und beenden. Kein Datenbankzugriff.
     */
    public function guardFrontend(): void
    {
        if ($this->isNotInstalled()) $this->sendUnavailable('not_installed.html');
        if ($this->blockReason() === null) return;
        $this->sendUnavailable();
    }

    /**
     * Backend, vor dem Laden der Session: ohne Installation die Installer-Seite.
     */
    public function guardNotInstalled(): void
    {
        if ($this->isNotInstalled()) $this->sendUnavailable('not_installed.html');
    }

    /**
     * 503 mit Wartungsseite: var/custom/{template}, sonst
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
