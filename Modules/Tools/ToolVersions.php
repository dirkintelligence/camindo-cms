<?php
/**
 * Versionsabfrage der externen Werkzeuge.
 *
 * Liefert die installierten Versionen von ImageMagick, FFmpeg und MySQL für die
 * Serverübersicht (ToolServer::list()). Die beiden externen Programme werden dafür
 * tatsächlich aufgerufen - die Angabe sagt also nicht nur, was in .camindo.php
 * konfiguriert ist, sondern ob es an diesem Pfad auch wirklich läuft.
 *
 * Alle drei Methoden sind gutmütig: fehlender Pfad, Fehlerabbruch oder unerwartete
 * Ausgabe führen zu null bzw. '?' und nicht zu einer Exception - die Übersicht soll
 * auch dann noch anzeigbar sein, wenn ein Werkzeug fehlt.
 *
 * Die Aufrufe laufen über Execute mit Zeitbegrenzung und als Argument-Array, nicht
 * über die Shell.
 *
 * @see Camindo\Core\Execute
 * @see Camindo\Core\Modules\Tools\ToolServer
 */
namespace Camindo\Core\Modules\Tools;

use Camindo\Core\Config;
use Camindo\Core\Backend;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\Execute;

class ToolVersions {

public function __construct(
        private Config $config,
        private Backend $backend,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Execute $execute
    )
    {}
    
    /**
     * Ermittelt die installierte ImageMagick-Version
     * 
     * @return string|null z. B. "7.1.1-39", null wenn nicht ermittelbar
     */
    public function getMagickVersion(): ?string
    {
        $magick = $this->config->get('paths.magick') ?? '';
        if ($magick === '') return null;

        $exitCode = $this->execute->execute([$magick, '-version'], $stdout, $stderr, timeout:10);
        if ($exitCode !== 0) return null;
        if (!preg_match('/ImageMagick\s+([0-9]+\.[0-9]+\.[0-9\-]+)/i', $stdout, $matches)) return null;

        return $matches[1];
    }

    /**
     * Ermittelt die installierte FFmpeg-Version
     *
     * @return string|null z. B. "6.1.1" oder null, wenn ffmpeg nicht läuft
     */
    public function getFFmpegVersion(): ?string
    {
        $ffmpeg = (string) ($this->config->get('paths.ffmpeg') ?? '');
        if ($ffmpeg === '' || !is_executable($ffmpeg)) return null;

        $exitCode = $this->execute->execute([$ffmpeg, '-version'], $stdout, $stderr, timeout:10);
        if ($exitCode !== 0) return null;

        if (!preg_match('/ffmpeg version\s+([0-9]+\.[0-9]+\.[0-9\-]+)/i', (string) $stdout, $match)) return null;

        return $match[1];
    }

    /**
     * Ermittelt die MySQL-Version über die bestehende Verbindung.
     *
     * @return string  Versionsnummer oder '?', wenn die Abfrage nichts liefert
     */
    public function getMySQLVersion(): string
    {
        $this->db->query("SHOW VARIABLES LIKE 'version'");
        if ($this->db->num_rows > 0) return $this->db->fetchNum()[0];
        else return '?';
    }
}