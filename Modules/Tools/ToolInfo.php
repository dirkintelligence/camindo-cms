<?php
/**
 * Small debug tool: shows the content of the current session.
 *
 * Called via backend/tool_info.php, displayed in the aside area. Useful when
 * the login behaves strangely - one sees what the session actually holds:
 * session_id, admin_id, last access and the user data under 'details'.
 *
 * Pure development aid. The output is unfiltered via var_dump() and therefore
 * does not belong in a production installation.
 *
 * @see Camindo\Core\Session
 * @see Camindo\Core\Modules\Tools\ToolDeveloper  shows the button for it
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
     * Output session data.
     *
     * (The earlier comment "Rebuild snippet cache files" belonged to a
     * different class.)
     */
    public function debugSessionData(): void {
        // Additional right "developer" (backend user groups; without session - cache bootstrap, CLI - everything applies)
        $this->accessGuard('developer');
        // only the beginning: the full id is the session cookie (HttpOnly) - a script
        // in the browser could otherwise read it via this page
        echo "<strong>session_id:</strong><br>";
        echo htmlspecialchars(substr((string)$this->session->get('session_id'), 0, 6)) . '…';
        echo "<br><br>";

        echo "<strong>admin_id:</strong><br>";
        echo (int)$this->session->get('admin_id');
        echo "<br><br>";
        
        echo "<strong>access_date:</strong><br>";
        echo htmlspecialchars((string)$this->session->get('access_date'));
        echo "<br><br>";

        echo "<strong>details:</strong><br><pre>";
        echo htmlspecialchars(print_r($this->session->get('details'), true));
        echo "</pre>";
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
