<?php
/**
 * Version query of the external tools.
 *
 * Returns the installed versions of ImageMagick, FFmpeg and MySQL for the
 * server overview (ToolServer::list()). The two external programs are actually
 * called for this - so the entry says not only what is configured in .camindo.php,
 * but whether it really runs at this path.
 *
 * All three methods are lenient: missing path, error abort or unexpected
 * output lead to null resp. '?' and not to an exception - the overview should
 * still be displayable even when a tool is missing.
 *
 * The calls run via Execute with a time limit and as argument array, not
 * via the shell.
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
     * Determine the installed ImageMagick version
     * 
     * @return string|null  e.g. "7.1.1-39", null if not determinable
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
     * Determine the installed FFmpeg version
     *
     * @return string|null  e.g. "6.1.1" or null, if ffmpeg does not run
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
     * Determine the MySQL version via the existing connection.
     *
     * @return string  version number or '?', if the query returns nothing
     */
    public function getMySQLVersion(): string
    {
        $this->db->query("SHOW VARIABLES LIKE 'version'");
        if ($this->db->num_rows > 0) return $this->db->fetchNum()[0];
        else return '?';
    }
}