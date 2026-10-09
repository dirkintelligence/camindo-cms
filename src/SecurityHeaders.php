<?php
/**
 * Security headers for every response over HTTP (developer docs, chapter 14 (Security: web));
 * script policy of the backend: same chapter).
 *
 * Called once in the bootstrap (Core.php), before any output and before any cookie.
 * The context follows from the constants of the entry point:
 *
 *   Backend   CAMINDO_BACKEND without CAMINDO_PREVIEW (index.php, module.php, file.php)
 *             The backend never runs in a frame; forms only post to themselves.
 *             Scripts only from own files or with the page's nonce (script-src,
 *             enforced, only reported or off, depending on security.backend_csp).
 *   Frontend  public/index.php, public/file.php and the preview from the backend
 *             (backend/preview.php defines CAMINDO_PREVIEW) - there the template
 *             dictates the HTML, the core only sets defaults. Every template can replace
 *             them with (CMS:HEADER …) or remove them with an empty value
 *             (Cms::cmsHeader()); the later header() call wins.
 *
 * NONCE
 * --------------------
 * One per page: backend/index.php creates it (here), outputs it as cConfig.nonce,
 * and the JavaScript sends it back with every Ajax request as X-Camindo-Nonce. An
 * HTML fragment loaded later thus carries the nonce of the page it is inserted into - the
 * policy in the header of the fragment itself does not apply, only that of the document. Taking
 * the value from the request is harmless: a foreign page cannot send its own header
 * (CORS), and a navigation sends none.
 *
 * FRONTEND SCRIPT POLICY (switchable, developer docs, chapter 14 (Security: web))
 * --------------------
 * Per instance (instance.details.csp, otherwise security.frontend_csp): 'off' (default),
 * 'report' or 'enforce'. Page::resolve() sends it as soon as the instance is known
 * (sendFrontendCsp()); it replaces the default frame-ancestors 'self' and
 * keeps it. The template programmer sets the page's nonce himself in every script
 * with <script(CMS:NONCE)>; the template editor warns about scripts
 * without it (TemplateParserTrait::inlineHandlerWarnings()). What comes into the HTML
 * from content at runtime carries no nonce and does not run.
 * CMS:CSP changes a page's policy (more sources, other mode) and sends it
 * again as long as nothing has been output yet.
 *
 * HSTS only on request (security.hsts_max_age > 0) and only over HTTPS: the browser
 * remembers the rule up to max-age, so an installation without consistent HTTPS
 * would lock itself out. Over http:// the browser ignores the header anyway.
 */
namespace Camindo\Core;

class SecurityHeaders
{
    /** Request header in which the JavaScript sends back the page's nonce */
    public const NONCE_HEADER = 'HTTP_X_CAMINDO_NONCE';

    /** Modes of the frontend policy (instance.details.csp, security.frontend_csp) */
    public const FRONTEND_MODES = ['off', 'report', 'enforce'];

    /** Endpoint for violation reports of the frontend (public/csp_report.php) */
    public const FRONTEND_REPORT_PATH = '/csp_report.php';

    private ?string $nonce = null;

    /** Instance of the running frontend page (sendFrontendCsp()) */
    private ?array $frontendInstance = null;

    /** Changes to the page via CMS:CSP: mode, script[], form[] */
    private array $pageCsp = ['mode' => '', 'script' => [], 'form' => []];

    public function __construct(private Config $config) {}

    /**
     * Send the headers of the context. Nothing on the command line or if output
     * has already been sent.
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
     * Nonce of this page: the one from the request (Ajax reload), otherwise a new one.
     * 32 hex characters - thus also usable as part of an attribute name (Backend::template()).
     */
    public function nonce(): string
    {
        if ($this->nonce === null) {
            // Only the backend loads HTML fragments via Ajax into an existing page;
            // in the frontend every response has its own nonce
            $sent = $this->isBackend() ? (string)($_SERVER[self::NONCE_HEADER] ?? '') : '';
            $this->nonce = preg_match('/^[0-9a-f]{32}$/', $sent) ? $sent : bin2hex(random_bytes(16));
        }
        return $this->nonce;
    }

    /** ' nonce="…"' for a <script> tag in the backend */
    public function nonceAttr(): string
    {
        return ' nonce="' . $this->nonce() . '"';
    }

    /**
     * security.backend_csp: 'enforce' (scripts without nonce do not run), 'report' (they
     * run, the browser reports them - csp_report.php writes var/log/…_csp.log),
     * 'off'. Unknown values count as 'enforce'.
     */
    public function backendCspMode(): string
    {
        $mode = (string)($this->config->get('security.backend_csp') ?? 'enforce');
        return in_array($mode, ['enforce', 'report', 'off'], true) ? $mode : 'enforce';
    }

    /** The script rules of the backend */
    public function scriptPolicy(): string
    {
        return "script-src 'self' 'nonce-" . $this->nonce() . "' 'report-sample'";
    }

    /**
     * @param bool $withScripts  include script-src in the enforced policy
     * @return array<string,string>
     */
    public function backendHeaders(bool $withScripts = false): array
    {
        return [
            // Clickjacking: never in a frame (X-Frame-Options for older browsers)
            'X-Frame-Options'         => 'DENY',
            // base-uri / form-action: an injected <base> or <form action="https://…">
            // redirects nothing; object-src: no plugins
            'Content-Security-Policy' => "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'"
                . ($withScripts ? '; ' . $this->scriptPolicy() : ''),
            'X-Content-Type-Options'  => 'nosniff',
            // Do not pass backend addresses (with IDs) on to external sites
            'Referrer-Policy'         => 'same-origin',
        ];
    }

    /** @return array<string,string> */
    public function frontendHeaders(): array
    {
        return [
            // Clickjacking on login, profile, basket: only the own website may frame
            'X-Frame-Options'         => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
            // Templates that deliver CSS/JS/JSON set their Content-Type via CMS:HEADER
            'X-Content-Type-Options'  => 'nosniff',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
        ];
    }

    // ====================
    // Frontend: script policy

    /**
     * Mode of the frontend policy of an instance: instance.details.csp, otherwise
     * security.frontend_csp, otherwise 'off'. CMS:CSP mode=… takes precedence.
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
     * The policy of the frontend page (without header name).
     *
     *     script-src 'self' 'nonce-…' [Quellen] ['strict-dynamic'];
     *     object-src 'none'; base-uri 'self'; frame-ancestors 'self'
     *     [; form-action 'self' [Quellen]] ; report-uri /csp_report.php
     *
     * Deliberately only scripts (plus object-src/base-uri, which belong to it): style-src,
     * img-src, connect-src would break fonts, maps, payment providers and protect
     * little. Whoever wants more writes the whole policy with CMS:HEADER.
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
     * Send the frontend policy (Page::resolve(), as soon as the instance is known; again
     * after CMS:CSP). 'off': only the default frame-ancestors from send().
     *
     * @return bool  false = already output, nothing sent
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
     * CMS:CSP - change the policy of this page and send it again.
     *
     * @param string   $mode    '' = unchanged, otherwise off|report|enforce
     * @param string[] $script  additional script sources
     * @param string[] $form    additional form targets (enables form-action)
     * @return bool             false = already output
     */
    public function changeFrontendCsp(string $mode, array $script, array $form): bool
    {
        if (in_array($mode, self::FRONTEND_MODES, true)) $this->pageCsp['mode'] = $mode;
        $this->pageCsp['script'] = array_values(array_unique(array_merge($this->pageCsp['script'], $script)));
        $this->pageCsp['form'] = array_values(array_unique(array_merge($this->pageCsp['form'], $form)));
        return $this->sendFrontendCsp($this->frontendInstance ?? []);
    }

    /**
     * Clean the source list: one host entry per item (https://js.example.com,
     * *.example.com, with port or path) - no keywords such as 'unsafe-inline',
     * no bare schemes (https: would allow every website), no separators that
     * smuggle in a directive of their own.
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
     * Value for Strict-Transport-Security or null (off, or no HTTPS).
     * X-Forwarded-Proto counts too (TLS ends at the proxy); a forged 'https' over
     * http:// has no effect, because the browser only accepts HSTS over HTTPS.
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
