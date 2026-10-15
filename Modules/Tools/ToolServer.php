<?php
/**
 * Content of the "Server" tab in the toolbox - the maintenance of the .htaccess and
 * clearing the content cache record_cache.
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
 * class is used by camindo.php upgrade and the installer (and camindo.php htaccess).
 * Here: the button and the display of the state.
 *
 * RECORD CACHE
 * --------------------
 * Discards `record_cache` of all instances, of one instance or of one area
 * (Camindo\Core\RecordCache, also camindo.php cache records); the rows are rebuilt on
 * access. One selection without script: "i:{id}" = instance, "a:{id}" = area, "" = all.
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
use Camindo\Core\InstanceCache;
use Camindo\Core\RecordCache;

class ToolServer {

    public function __construct(
        private Config $config,
        private Database $db,
        private System $system,
        private Backend $backend,
        private Cms $cms,
        private ToolVersions $toolVersions,
        private HtaccessWriter $htaccess,
        private RecordCache $recordCache,
        private InstanceCache $instanceCache,
    )
    {
    }

    /**
     * Output server overview.
     *
     * Four sections: server components as an info table, the state of the two
     * command-line tools, the state of the .htaccess with the button for the
     * rebuild, and the form for clearing the record cache.
     *
     * The button sends function=renew_htaccess back to the same page - the
     * evaluation therefore sits in the middle of the method, before the state check, so that
     * the message afterwards shows the NEW state.
     *
     * Request: function = renew_htaccess | clear_record_cache (optional),
     *          scope = '' | 'i:{instance_id}' | 'a:{area_id}' (with clear_record_cache)
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

        // Record cache
        // --------------------
        $scope = (string)($_REQUEST['scope'] ?? '');
        if ($function === 'clear_record_cache') {
            // Deletes - only via POST, like renew_htaccess
            if ($this->backend->requirePost()) {
                $this->clearRecordCache($scope);
            }
            else {
                echo $this->backend->access()->deniedHtml();
            }
        }
        $this->recordCacheForm($scope);
    }

    /**
     * Clear the record cache for the selection and report the number of rows.
     *
     * @param string $scope  '' all, 'i:{id}' instance, 'a:{id}' area
     */
    private function clearRecordCache(string $scope): void
    {
        $instanceId = 0;
        $areaIds = [];
        if (preg_match('/^([ia]):(\d+)$/', $scope, $m)) {
            if ($m[1] === 'i') $instanceId = (int)$m[2];
            else $areaIds = [(int)$m[2]];
        }
        try {
            $rows = $this->recordCache->clear($instanceId, $areaIds);
        }
        catch (\InvalidArgumentException) {
            echo $this->backend->template('alert_danger', [
                '{text}' => htmlspecialchars($this->cms->snippet('tools.error_record_cache_scope'))
            ]);
            return;
        }
        echo $this->backend->template('alert_success', [
            '{text}' => htmlspecialchars($this->cms->snippet('tools.record_cache_summary', ['rows' => $rows]))
        ]);
    }

    /**
     * The form: one selection "all / instance / area" (areas with records, grouped by
     * instance; with only one instance without the instance entries).
     */
    private function recordCacheForm(string $scope): void
    {
        $option = fn(string $value, string $label) => '<option value="' . $value . '"'
            . ($value === $scope ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>' . "\n";

        $multi = $this->instanceCache->hasMultipleInstances();
        $instances = $this->instanceCache->instanceLabels();
        $areas = $this->recordCache->areaOptions($this->cms);

        $options = $option('', $this->cms->snippet($multi ? 'tools.option_record_cache_all' : 'tools.option_record_cache_all_areas'));
        foreach ($instances as $instanceId => $label) {
            $label = $this->cms->_strip_tags($this->cms->textOrSnippet($label));
            if ($multi) {
                $options .= '<optgroup label="' . htmlspecialchars($label) . '">' . "\n"
                    . $option('i:' . $instanceId, str_replace('{instance}', $label, $this->cms->snippet('tools.option_record_cache_instance')));
            }
            foreach ($areas[$instanceId] ?? [] as $areaId => $areaLabel) {
                $options .= $option('a:' . $areaId, $areaLabel);
            }
            if ($multi) $options .= '</optgroup>' . "\n";
        }

        echo $this->backend->template('tools.record_cache_form', ['{options}' => $options]);
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
