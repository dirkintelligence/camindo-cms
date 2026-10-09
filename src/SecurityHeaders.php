<?php
/**
 * Sicherheits-Header für jede Antwort über HTTP (developer_docs/16-sicherheit.md §10,
 * Script-Policy des Backends: developer_docs/19-csp.md).
 *
 * Aufgerufen einmal im Bootstrap (Core.php), vor jeder Ausgabe und vor jedem Cookie.
 * Der Kontext ergibt sich aus den Konstanten des Einstiegs:
 *
 *   Backend   CAMINDO_BACKEND ohne CAMINDO_PREVIEW (index.php, module.php, file.php)
 *             Das Backend läuft nie in einem Rahmen; Formulare gehen nur an sich selbst.
 *             Scripts nur aus eigenen Dateien oder mit der Nonce der Seite (script-src,
 *             je nach security.backend_csp erzwungen, nur gemeldet oder aus).
 *   Frontend  public/index.php, public/file.php und die Vorschau aus dem Backend
 *             (backend/preview.php definiert CAMINDO_PREVIEW) - dort gibt das Template
 *             das HTML vor, der Core setzt nur Voreinstellungen. Jedes Template kann sie
 *             mit (CMS:HEADER …) ersetzen oder mit einem leeren Wert entfernen
 *             (Cms::cmsHeader()), der spätere header()-Aufruf gewinnt.
 *
 * NONCE
 * --------------------
 * Eine je Seite: backend/index.php erzeugt sie (hier), gibt sie als cConfig.nonce aus,
 * und das Javascript schickt sie mit jedem Ajax-Request als X-Camindo-Nonce zurück. Ein
 * nachgeladenes HTML-Stück trägt so die Nonce der Seite, in die es eingesetzt wird - die
 * Policy im Header des Stücks selbst gilt nicht, nur die des Dokuments. Den Wert aus dem
 * Request zu übernehmen ist unbedenklich: einen eigenen Header kann eine fremde Seite
 * nicht mitschicken (CORS), und eine Navigation schickt keinen.
 *
 * SCRIPT-POLICY DES FRONTENDS (zuschaltbar, 19-csp.md §9)
 * --------------------
 * Je Instanz (instance.details.csp, sonst security.frontend_csp): 'off' (Vorgabe),
 * 'report' oder 'enforce'. Page::resolve() sendet sie, sobald die Instanz feststeht
 * (sendFrontendCsp()); sie ersetzt die Voreinstellung frame-ancestors 'self' und
 * enthält sie weiter. Die Nonce der Seite setzt der Template-Programmierer mit
 * <script(CMS:NONCE)> selbst in jedes Script; der Template-Editor weist auf Scripts
 * ohne hin (TemplateParserTrait::inlineHandlerWarnings()). Was zur Laufzeit aus
 * Inhalten ins HTML kommt, trägt keine Nonce und läuft nicht.
 * CMS:CSP ändert die Policy einer Seite (weitere Quellen, anderer Modus) und sendet
 * sie neu, solange noch nichts ausgegeben ist.
 *
 * HSTS nur auf Wunsch (security.hsts_max_age > 0) und nur über HTTPS: der Browser
 * merkt sich die Regel bis zu max-age, eine Installation ohne durchgehendes HTTPS
 * sperrt sich damit aus. Über http:// ignoriert der Browser den Header ohnehin.
 */
namespace Camindo\Core;

class SecurityHeaders
{
    /** Request-Header, in dem das Javascript die Nonce der Seite zurückschickt */
    public const NONCE_HEADER = 'HTTP_X_CAMINDO_NONCE';

    /** Modi der Frontend-Policy (instance.details.csp, security.frontend_csp) */
    public const FRONTEND_MODES = ['off', 'report', 'enforce'];

    /** Endpunkt für Verstoßmeldungen des Frontends (public/csp_report.php) */
    public const FRONTEND_REPORT_PATH = '/csp_report.php';

    private ?string $nonce = null;

    /** Instanz der laufenden Frontend-Seite (sendFrontendCsp()) */
    private ?array $frontendInstance = null;

    /** Änderungen der Seite über CMS:CSP: mode, script[], form[] */
    private array $pageCsp = ['mode' => '', 'script' => [], 'form' => []];

    public function __construct(private Config $config) {}

    /**
     * Header des Kontexts senden. Nichts auf der Kommandozeile oder wenn schon
     * ausgegeben wurde.
     */
    public function send(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) return;

        if ($this->isBackend()) {
            $mode = $this->backendCspMode();
            foreach ($this->backendHeaders($mode === 'enforce') as $name => $value) {
                header($name . ': ' . $value);
            }
            if ($mode === 'report') {
                header('Content-Security-Policy-Report-Only: ' . $this->scriptPolicy());
            }
        }
        else {
            foreach ($this->frontendHeaders() as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        $hsts = $this->hstsHeader();
        if ($hsts !== null) header('Strict-Transport-Security: ' . $hsts);
    }

    /**
     * Nonce dieser Seite: die aus dem Request (Ajax-Nachladen), sonst eine neue.
     * 32 Hex-Zeichen - taugt so auch als Teil eines Attributnamens (Backend::template()).
     */
    public function nonce(): string
    {
        if ($this->nonce === null) {
            // Nur das Backend lädt HTML-Stücke per Ajax in eine bestehende Seite nach;
            // im Frontend hat jede Antwort ihre eigene Nonce
            $sent = $this->isBackend() ? (string)($_SERVER[self::NONCE_HEADER] ?? '') : '';
            $this->nonce = preg_match('/^[0-9a-f]{32}$/', $sent) ? $sent : bin2hex(random_bytes(16));
        }
        return $this->nonce;
    }

    /** ' nonce="…"' für ein <script>-Tag im Backend */
    public function nonceAttr(): string
    {
        return ' nonce="' . $this->nonce() . '"';
    }

    /**
     * security.backend_csp: 'enforce' (Scripts ohne Nonce laufen nicht), 'report' (sie
     * laufen, der Browser meldet sie - Start/csp_report.php schreibt var/log/…_csp.log),
     * 'off'. Unbekannte Werte gelten als 'enforce'.
     */
    public function backendCspMode(): string
    {
        $mode = (string)($this->config->get('security.backend_csp') ?? 'enforce');
        return in_array($mode, ['enforce', 'report', 'off'], true) ? $mode : 'enforce';
    }

    /** Die Script-Regeln des Backends */
    public function scriptPolicy(): string
    {
        return "script-src 'self' 'nonce-" . $this->nonce() . "' 'report-sample'";
    }

    /**
     * @param bool $withScripts  script-src in die erzwungene Policy aufnehmen
     * @return array<string,string>
     */
    public function backendHeaders(bool $withScripts = false): array
    {
        return [
            // Clickjacking: nie in einem Rahmen (X-Frame-Options für ältere Browser)
            'X-Frame-Options'         => 'DENY',
            // base-uri / form-action: ein eingeschleustes <base> oder <form action="https://…">
            // lenkt nichts um; object-src: keine Plugins
            'Content-Security-Policy' => "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'"
                . ($withScripts ? '; ' . $this->scriptPolicy() : ''),
            'X-Content-Type-Options'  => 'nosniff',
            // Backend-Adressen (mit IDs) nicht an externe Seiten weitergeben
            'Referrer-Policy'         => 'same-origin',
        ];
    }

    /** @return array<string,string> */
    public function frontendHeaders(): array
    {
        return [
            // Clickjacking auf Login, Profil, Warenkorb: nur die eigene Website darf rahmen
            'X-Frame-Options'         => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
            // Templates, die CSS/JS/JSON ausliefern, setzen ihren Content-Type per CMS:HEADER
            'X-Content-Type-Options'  => 'nosniff',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
        ];
    }

    // ====================
    // Frontend: Script-Policy

    /**
     * Modus der Frontend-Policy einer Instanz: instance.details.csp, sonst
     * security.frontend_csp, sonst 'off'. CMS:CSP mode=… hat Vorrang.
     */
    public function frontendCspMode(array $instance): string
    {
        if ($this->pageCsp['mode'] !== '') return $this->pageCsp['mode'];
        $mode = (string)($this->instanceDetails($instance)['csp'] ?? '');
        if (!in_array($mode, self::FRONTEND_MODES, true)) {
            $mode = (string)($this->config->get('security.frontend_csp') ?? 'off');
        }
        return in_array($mode, self::FRONTEND_MODES, true) ? $mode : 'off';
    }

    /**
     * Die Policy der Frontend-Seite (ohne Header-Namen).
     *
     *     script-src 'self' 'nonce-…' [Quellen] ['strict-dynamic'];
     *     object-src 'none'; base-uri 'self'; frame-ancestors 'self'
     *     [; form-action 'self' [Quellen]] ; report-uri /csp_report.php
     *
     * Bewusst nur Scripts (plus object-src/base-uri, die dazugehören): style-src,
     * img-src, connect-src brächen Schriften, Karten, Zahlungsanbieter und schützen
     * wenig. Wer mehr will, schreibt die ganze Policy mit CMS:HEADER.
     */
    public function frontendPolicy(array $instance): string
    {
        $details = $this->instanceDetails($instance);
        $script = array_merge(
            ["'self'", "'nonce-" . $this->nonce() . "'"],
            self::cleanSources((string)($details['csp_script_sources'] ?? '')),
            $this->pageCsp['script']
        );
        if (!empty($details['csp_strict_dynamic'])) $script[] = "'strict-dynamic'";
        $parts = [
            'script-src ' . implode(' ', array_unique($script)),
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
        ];
        if (!empty($details['csp_form_action']) || $this->pageCsp['form']) {
            $form = array_merge(["'self'"], self::cleanSources((string)($details['csp_form_sources'] ?? '')), $this->pageCsp['form']);
            $parts[] = 'form-action ' . implode(' ', array_unique($form));
        }
        $parts[] = 'report-uri ' . self::FRONTEND_REPORT_PATH;
        return implode('; ', $parts);
    }

    /**
     * Frontend-Policy senden (Page::resolve(), sobald die Instanz feststeht; erneut
     * nach CMS:CSP). 'off': nur die Voreinstellung frame-ancestors aus send().
     *
     * @return bool  false = schon ausgegeben, nichts gesendet
     */
    public function sendFrontendCsp(array $instance): bool
    {
        $this->frontendInstance = $instance;
        if (PHP_SAPI === 'cli' || headers_sent()) return false;
        $mode = $this->frontendCspMode($instance);
        header_remove('Content-Security-Policy-Report-Only');
        if ($mode === 'off') {
            header("Content-Security-Policy: frame-ancestors 'self'");
            return true;
        }
        $policy = $this->frontendPolicy($instance);
        if ($mode === 'enforce') {
            header('Content-Security-Policy: ' . $policy);
        }
        else {
            header("Content-Security-Policy: frame-ancestors 'self'");
            header('Content-Security-Policy-Report-Only: ' . $policy);
        }
        return true;
    }

    /**
     * CMS:CSP - Policy dieser Seite ändern und neu senden.
     *
     * @param string   $mode    '' = unverändert, sonst off|report|enforce
     * @param string[] $script  weitere Script-Quellen
     * @param string[] $form    weitere Formularziele (schaltet form-action ein)
     * @return bool             false = schon ausgegeben
     */
    public function changeFrontendCsp(string $mode, array $script, array $form): bool
    {
        if (in_array($mode, self::FRONTEND_MODES, true)) $this->pageCsp['mode'] = $mode;
        $this->pageCsp['script'] = array_values(array_unique(array_merge($this->pageCsp['script'], $script)));
        $this->pageCsp['form'] = array_values(array_unique(array_merge($this->pageCsp['form'], $form)));
        return $this->sendFrontendCsp($this->frontendInstance ?? []);
    }

    /**
     * Quellenliste bereinigen: je Eintrag eine Host-Angabe (https://js.example.com,
     * *.example.com, mit Port oder Pfad) - keine Schlüsselwörter wie 'unsafe-inline',
     * keine reinen Schemata (https: erlaubte jede Website), keine Trennzeichen, die
     * eine eigene Direktive einschmuggeln.
     *
     * @return string[]
     */
    public static function cleanSources(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $text) ?: [] as $source) {
            $source = trim($source);
            if ($source === '') continue;
            if (preg_match('#^((https?|wss?)://)?(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:(\d{1,5}|\*))?(/[a-z0-9._~%/+-]*)?$#i', $source)) {
                $out[] = $source;
            }
        }
        return array_values(array_unique($out));
    }

    private function instanceDetails(array $instance): array
    {
        $details = $instance['details'] ?? null;
        return is_array($details) ? $details : (json_decode((string)$details, true) ?: []);
    }

    /**
     * Wert für Strict-Transport-Security oder null (aus bzw. kein HTTPS).
     * X-Forwarded-Proto zählt mit (TLS endet am Proxy); ein gefälschtes 'https' über
     * http:// bleibt folgenlos, weil der Browser HSTS nur über HTTPS annimmt.
     */
    public function hstsHeader(): ?string
    {
        $maxAge = (int)($this->config->get('security.hsts_max_age') ?? 0);
        if ($maxAge <= 0) return null;
        $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        $https = $https || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        if (!$https) return null;
        $value = 'max-age=' . $maxAge;
        if (!empty($this->config->get('security.hsts_include_subdomains'))) $value .= '; includeSubDomains';
        return $value;
    }

    private function isBackend(): bool
    {
        return defined('CAMINDO_BACKEND') && CAMINDO_BACKEND === true
            && !(defined('CAMINDO_PREVIEW') && CAMINDO_PREVIEW === true);
    }
}
