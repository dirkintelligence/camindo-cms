<?php
/**
 * Content of the "Server" tab in the toolbox - and the maintenance of the .htaccess.
 *
 * Entry points (scripts in backend/):
 *
 *     index.php / index_server.php  -> list()       server overview
 *     tool_db_info.php              -> mysqlInfo()  MySQL configuration
 *
 * The overview shows the camindo version, operating system, web server, database, PHP and
 * whether ImageMagick and FFmpeg actually run (ToolVersions fetches the versions by
 * a real call of the programs).
 *
 * THE .HTACCESS IS PARTLY GENERATED
 * --------------------
 * In the frontend every request runs through index.php - EXCEPT when it points to a real
 * file or to one of the subdirectories of the docroot (media/, backend/, …).
 * These exceptions appear as RewriteCond lines in a managed block of the
 * public/.htaccess; a checksum over the directory list reveals whether the file is
 * outdated. Creating and checking is done by Camindo\Core\HtaccessWriter - the same
 * class is used by camindo.php upgrade and the installer. Here: the button and the
 * display of the state.
 *
 * @see Camindo\Core\Modules\Tools\ToolVersions  versions of the external programs
 * @see Camindo\Core\Modules\Tools\ToolsBackend  frame with the tabs
 */
namespace Camindo\Core\Modules\Tools;

use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\System;
use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\HtaccessWriter;

class ToolServer {

    public function __construct(
        private Config $config,
        private Database $db,
        private System $system,
        private Backend $backend,
        private Cms $cms,
        private ToolVersions $toolVersions,
        private HtaccessWriter $htaccess,
    )
    {
    }

    /**
     * Output server overview.
     *
     * Three sections: server components as an info table, the state of the two
     * command-line tools, and the state of the .htaccess with the button for the
     * rebuild.
     *
     * The button sends function=renew_htaccess back to the same page - the
     * evaluation therefore sits in the middle of the method, before the state check, so that
     * the message afterwards shows the NEW state.
     *
     * Request: function = renew_htaccess (optional)
     */
    public function list() {
        // Additional right "server" (backend user groups; without session - cache bootstrap, CLI - everything applies)
        $this->accessGuard('server');

        // server components
        // --------------------
        $this->backend->infoStatic('#tools.camindo_version', CAMINDO_VERSION);

        $operatingSystem = PHP_OS;
	    $operatingSystem .= (PHP_INT_MAX > 100000000000) ? ' 64 bit' : ' 32 bit';
        $this->backend->infoStatic('#tools.operating_system', $operatingSystem);

        $serverSoftware = preg_split('![ /]!',$_SERVER['SERVER_SOFTWARE'] ?? '')[0];
        preg_match('![0-9]+\.[0-9]+\.[0-9]+!', $_SERVER['SERVER_SOFTWARE'], $matches);
        $serverSoftware .= ' ' . $matches[0];
        if (preg_match('/cgi/i',PHP_SAPI)) $serverSoftware .= ' ' . PHP_SAPI;
        $this->backend->infoStatic('#tools.server_software', $serverSoftware);

        $databaseSoftware = 'MySQL ' . $this->toolVersions->getMySQLVersion();
        $databaseHtml = $this->backend->template('link_replace', [
            '{href}' => 'Tools/tool_db_info.php',
            '{text}' => $databaseSoftware
        ]);
        $this->backend->infoStatic('#tools.database_software', $databaseHtml);

        $scriptLanguage = 'PHP ' . PHP_VERSION;
        $scriptHtml = $this->backend->template('link_replace', [
            '{href}' => 'Tools/tool_phpinfo.php',
            '{text}' => $scriptLanguage
        ]);
        $this->backend->infoStatic('#tools.script_language', $scriptHtml);


        // render Table
        echo $this->backend->infoTable();

        // command-line tools
        // --------------------
        $magickVersion = $this->toolVersions->getMagickVersion();
        if ($magickVersion) {
            $magickStatus = str_replace('{version}', $magickVersion, $this->backend->template('tools.status_installed'));
        } else {
            $magickStatus = $this->backend->template('tools.status_not_installed');
        }

        $ffmpegVersion = $this->toolVersions->getFFmpegVersion();
        if ($ffmpegVersion) {
            $ffmpegStatus = str_replace('{version}', $ffmpegVersion, $this->backend->template('tools.status_installed'));
        } else {
            $ffmpegStatus = $this->backend->template('tools.status_not_installed');
        }

        echo $this->backend->template('tools.cli_tools', [
            '{magick_status}' => $magickStatus,
            '{ffmpeg_status}' => $ffmpegStatus,
        ]);

        // .htaccess file
        // --------------------
        echo $this->backend->template('headline4_mt', [
            '{text}' => '{#tools.headline_htaccess_file}'
        ]);

        // Writes public/.htaccess - only via POST (developer docs, chapter 14 (Security: web))
        $function = (string)($_REQUEST['function'] ?? '');
        if ($function === 'renew_htaccess' && !$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            $function = '';
        }
        if ($function === 'renew_htaccess') {
            if (!$this->updateHtaccess()) {
                echo $this->backend->template('alert_danger', [
                    '{text}' => $this->cms->_nl2br($this->cms->snippet('tools.error_file_writing'))
                ]);
            }
            else {
                echo $this->cms->snippet('tools.message_file_written');
            }
            echo $this->backend->template('spacer');
        }

        // Check current state of the .htaccess file
        if ($hasChanges = $this->hasChanges()) {
            echo $this->backend->template('alert_danger', [
                '{text}' => $this->cms->_nl2br($this->cms->snippet('tools.htaccess_obsolete'))
            ]);
        }
        else {
            echo $this->backend->template('alert_success', [
                '{text}' => $this->cms->_nl2br($this->cms->snippet('tools.htaccess_up_to_date'))
            ]);
        }

        // Refresh .htaccess
        // The button is deliberately always shown - the commented-out condition would show it
        // only when changes are needed. A rebuild does no harm, and besides, the condition is
        // wrong anyway: it is compared against 'renew', the parameter is called 'renew_htaccess'.
        // Parameter heisst 'renew_htaccess'.
        // if ($function !== 'renew' && $hasChanges) {
            $labelHtml = $this->cms->snippet('button_renew');
            $icon = $this->backend->template('tools.icon_renew');
            $this->backend->buttonAdd('button_secondary', "cApp.layers.replacePost('Tools/index_server.php?function=renew_htaccess');", $icon . ' ' . $labelHtml);        
        // }

        echo $this->backend->buttonBar('button_bar_vertical');

    }

    /**
     * Show MySQL configuration (backend/tool_db_info.php).
     *
     * Two tables: first everything that carries 'version' in its name, then the rest.
     * In between infoReset() resets the collection - otherwise infoTable() would also
     * output the rows of the first table again on the second call.
     *
     * Keys and values come from the server configuration and are masked before
     * output.
     */
    public function mysqlInfo(): void
    {
        // Bread crum navi
        $this->backend->setNaviLabel('#tools.database_software');
        
        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#tools.database_software');

        echo $this->backend->template('headline4_mt', [
            '{text}' => '{#tools.headline_mysql_config}'
        ]);

        $this->db->query("SHOW VARIABLES LIKE 'version%'");
        while (list ($key, $val) = $this->db->fetchNum()) {
            $this->backend->infoStatic(htmlspecialchars($key), htmlspecialchars($val));
        }
        echo $this->backend->infoTable();

        // Start a new info table
        $this->backend->infoReset();

        echo $this->backend->template('headline4_mt', [
            '{text}' => '{#tools.headline_mysql_config_more}'
        ]);

        $this->db->query("SHOW VARIABLES");
        while (list ($key, $val) = $this->db->fetchNum()) {
            if (preg_match('/version/i', $key)) continue;
            $this->backend->infoStatic(htmlspecialchars($key), htmlspecialchars($val));
        }
        echo $this->backend->infoTable();

        // Container footer
        echo $this->backend->template('container_footer');
    }


    // ==== .htaccess ====
    //
    // The work is done by Camindo\Core\HtaccessWriter (camindo.php upgrade and the
    // installer also write the file that way); only the two calls that
    // list() needs remain here.

    /**
     * Rewrite the managed block of public/.htaccess.
     *
     * @return bool  false, if the file could not be written
     */
    public function updateHtaccess(): bool
    {
        return $this->htaccess->update();
    }

    /**
     * true, if the .htaccess no longer matches the directory structure.
     */
    public function hasChanges(): bool
    {
        return $this->htaccess->hasChanges();
    }

    /**
     * Check the additional right of the module Tools - message and abort if it is missing.
     * Without loaded rights (no backend request) Access denies nothing.
     */
    private function accessGuard(string $right): void
    {
        $access = $GLOBALS['app']->get(\Camindo\Core\Access::class);
        if (!$access->moduleRight('Tools', $right)) $access->denied();
    }
}
