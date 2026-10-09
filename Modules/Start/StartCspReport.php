<?php
/**
 * Log violations of the backend script policy (developer docs, chapter 14 (Security: web)).
 *
 * Entry point (script in backend/):
 *
 *     csp_report.php  -> report()
 *
 * backend-handlers.js reports every 'securitypolicyviolation' event here, per page
 * every place once. In mode security.backend_csp = 'report' these are the places that
 * still write handler attributes or scripts without a nonce; in mode 'enforce' scripts
 * that the browser rejected - own leftovers or injected code.
 *
 * Deliberately not via report-uri: the browser's message comes without a CSRF token and
 * without reference to the session. This way the same door applies as for every backend
 * action (session, POST, token), and the log shows who had the page open.
 *
 * Log: var/log/{Y-m-d}_csp.log, one line per report (module Logfiles: category warning)
 */
namespace Camindo\Core\Modules\Start;

use Camindo\Core\Backend;
use Camindo\Core\Session;
use Camindo\Core\System;

class StartCspReport {
    public function __construct(
        private Backend $backend,
        private Session $session,
        private System $system,
    )
    {}

    /**
     * Request (POST): disposition, directive, blocked, source, sample, page
     */
    public function report(): void
    {
        if (!$this->backend->requirePost()) return;

        $field = function (string $name, int $max): string {
            // Remove control characters (one line per entry), limit length
            $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)($_POST[$name] ?? ''));
            return mb_substr(trim((string)$value), 0, $max);
        };
        $this->system->log('csp', sprintf(
            'admin %d %s %s blocked=%s source=%s page=%s sample=%s',
            (int)$this->session->get('admin_id'),
            preg_replace('/[^a-z-]/', '', $field('disposition', 20)) ?: '-',
            preg_replace('/[^a-z-]/', '', $field('directive', 40)) ?: '-',
            $field('blocked', 300),
            $field('source', 300),
            $field('page', 300),
            $field('sample', 200)
        ));
    }
}
