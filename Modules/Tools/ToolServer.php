<?php
/**
 * Inhalt des Reiters "Server" im Werkzeugkasten - und die Pflege der .htaccess.
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     index.php / index_server.php  -> list()       Serverübersicht
 *     tool_db_info.php              -> mysqlInfo()  MySQL-Konfiguration
 *
 * Die Übersicht zeigt camindo-Version, Betriebssystem, Webserver, Datenbank, PHP und
 * ob ImageMagick und FFmpeg tatsächlich laufen (die Versionen holt ToolVersions durch
 * einen echten Aufruf der Programme).
 *
 * DIE .HTACCESS WIRD TEILWEISE GENERIERT
 * --------------------
 * Im Frontend läuft jede Anfrage über index.php - AUSSER sie zeigt auf eine echte
 * Datei oder in eines der Unterverzeichnisse des Docroots (media/, backend/, …).
 * Diese Ausnahmen stehen als RewriteCond-Zeilen in einem verwalteten Block der
 * public/.htaccess; eine Prüfsumme über die Verzeichnisliste verrät, ob die Datei
 * veraltet ist. Erzeugen und Prüfen erledigt Camindo\Core\HtaccessWriter - dieselbe
 * Klasse benutzen camindo.php upgrade und der Installer. Hier: der Knopf und die
 * Anzeige des Zustands.
 *
 * @see Camindo\Core\Modules\Tools\ToolVersions  Versionen der externen Programme
 * @see Camindo\Core\Modules\Tools\ToolsBackend  Rahmen mit den Reitern
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
     * Serverübersicht ausgeben.
     *
     * Drei Abschnitte: Serverkomponenten als Infotabelle, der Zustand der beiden
     * Kommandozeilenwerkzeuge, und der Zustand der .htaccess mit dem Knopf zum
     * Neuaufbau.
     *
     * Der Knopf schickt function=renew_htaccess an dieselbe Seite zurück - die
     * Auswertung steht deshalb mitten in der Methode, vor der Zustandsprüfung, damit
     * die Meldung anschließend den NEUEN Zustand zeigt.
     *
     * Request: function = renew_htaccess (optional)
     */
    public function list() {
        // Zusatzrecht "server" (Backend-Benutzergruppen; ohne Session - Cache-Bootstrap, CLI - gilt alles)
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

        // commandline tools
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

        // Schreibt public/.htaccess - nur per POST (17-csrf.md §2.7)
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

        // Prüfe aktuellen Zustand der .htaccess-Datei
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
        // Der Knopf steht bewusst immer da - die auskommentierte Bedingung würde ihn
        // nur bei Änderungsbedarf zeigen. Ein Neuaufbau schadet aber nicht, und
        // außerdem stimmt die Bedingung nicht: verglichen wird gegen 'renew', der
        // Parameter heisst 'renew_htaccess'.
        // if ($function !== 'renew' && $hasChanges) {
            $labelHtml = $this->cms->snippet('button_renew');
            $icon = $this->backend->template('tools.icon_renew');
            $this->backend->buttonAdd('button_secondary', "cApp.layers.replacePost('Tools/index_server.php?function=renew_htaccess');", $icon . ' ' . $labelHtml);        
        // }

        echo $this->backend->buttonBar('button_bar_vertical');

    }

    /**
     * MySQL-Konfiguration anzeigen (backend/tool_db_info.php).
     *
     * Zwei Tabellen: zuerst alles, was 'version' im Namen trägt, danach der Rest.
     * Dazwischen setzt infoReset() die Sammlung zurück - infoTable() gibt sonst beim
     * zweiten Aufruf auch die Zeilen des ersten noch einmal aus.
     *
     * Schlüssel und Werte kommen aus der Serverkonfiguration und werden vor der
     * Ausgabe maskiert.
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

        // Neue Infotabelle beginnen
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
    // Die Arbeit macht Camindo\Core\HtaccessWriter (auch camindo.php upgrade und der
    // Installer schreiben die Datei damit); hier bleiben nur die beiden Aufrufe, die
    // list() braucht.

    /**
     * Schreibt den verwalteten Block der public/.htaccess neu.
     *
     * @return bool  false, wenn die Datei nicht geschrieben werden konnte
     */
    public function updateHtaccess(): bool
    {
        return $this->htaccess->update();
    }

    /**
     * true, wenn die .htaccess nicht mehr zur Verzeichnisstruktur passt.
     */
    public function hasChanges(): bool
    {
        return $this->htaccess->hasChanges();
    }

    /**
     * Zusatzrecht des Moduls Tools prüfen - Meldung und Abbruch, wenn es fehlt.
     * Ohne geladene Rechte (kein Backend-Request) verweigert Access nichts.
     */
    private function accessGuard(string $right): void
    {
        $access = $GLOBALS['app']->get(\Camindo\Core\Access::class);
        if (!$access->moduleRight('Tools', $right)) $access->denied();
    }
}
