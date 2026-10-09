<?php
/**
 * Kleines Debug-Werkzeug: zeigt den Inhalt der aktuellen Sitzung.
 *
 * Aufgerufen über backend/tool_info.php, dargestellt im Aside-Bereich. Nützlich, wenn
 * die Anmeldung sich merkwürdig verhält - man sieht, was Session tatsächlich hält:
 * session_id, admin_id, letzter Zugriff und die Benutzerdaten unter 'details'.
 *
 * Reine Entwicklungshilfe. Die Ausgabe erfolgt ungefiltert per var_dump() und gehört
 * daher nicht in eine produktive Installation.
 *
 * @see Camindo\Core\Session
 * @see Camindo\Core\Modules\Tools\ToolDeveloper  blendet den Knopf dafür ein
 */
namespace Camindo\Core\Modules\Tools;

use Camindo\Core\Config;
use Camindo\Core\System;
use Camindo\Core\Session;
use Camindo\Core\Backend;
use Camindo\Core\Cms;

class ToolInfo {

    public function __construct(
        private Config $config,
        protected System $system,
        protected Session $session,
        private Backend $backend,
        private Cms $cms
    )
    {
    }

    /**
     * Sitzungsdaten ausgeben.
     *
     * (Der bisherige Kommentar "Snippet-Cache-Files neu aufbauen" gehörte zu einer
     * anderen Klasse.)
     */
    public function debugSessionData(): void {
        // Zusatzrecht "developer" (Backend-Benutzergruppen; ohne Session - Cache-Bootstrap, CLI - gilt alles)
        $this->accessGuard('developer');
        echo "<strong>session_id:</strong><br>";
        echo $this->session->get('session_id');
        echo "<br><br>";

        echo "<strong>admin_id:</strong><br>";
        echo $this->session->get('admin_id');
        echo "<br><br>";
        
        echo "<strong>access_date:</strong><br>";
        echo $this->session->get('access_date');
        echo "<br><br>";

        echo "<strong>details:</strong><br><pre>";
        var_dump($this->session->get('details'));
        echo "</pre>";
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
