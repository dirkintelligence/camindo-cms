<?php
/**
 * Verstöße gegen die Script-Policy des Backends protokollieren (developer_docs/19-csp.md).
 *
 * Einstiegspunkt (Script in backend/):
 *
 *     csp_report.php  -> report()
 *
 * backend-handlers.js meldet jedes 'securitypolicyviolation'-Ereignis hierher, je Seite
 * jede Stelle einmal. Im Modus security.backend_csp = 'report' sind das die Stellen, die
 * noch Handler-Attribute oder Scripts ohne Nonce schreiben; im Modus 'enforce' Scripts,
 * die der Browser abgewiesen hat - eigene Reste oder eingeschleuster Code.
 *
 * Absichtlich nicht über report-uri: die Meldung des Browsers kommt ohne CSRF-Token und
 * ohne Bezug zur Sitzung. So gilt dieselbe Tür wie für jede Backend-Aktion (Sitzung,
 * POST, Token), und im Log steht, wer die Seite offen hatte.
 *
 * Log: var/log/{Y-m-d}_csp.log, eine Zeile je Meldung (Modul Logfiles: Kategorie warning)
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
            // Steuerzeichen raus (eine Zeile je Eintrag), Länge begrenzen
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
