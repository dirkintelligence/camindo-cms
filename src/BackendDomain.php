<?php
/**
 * Fixed backend domain (admin.backend_domain) - developer docs, chapter 13 (Security: authentication).
 *
 * Without an entry the backend runs as before under every domain of the installation
 * ({domain}/backend/). With an entry only under this one:
 *
 *     Backend entry points (index.php, module.php, file.php, preview.php) under another
 *     domain                                  -> 404, without a hint to the right one
 *     Frontend under the backend domain       -> 302 to the backend
 *
 * Why: a subdomain nobody guesses keeps login attempts away - the websites no longer
 * reveal it. And WebAuthn (passkeys) binds each key to exactly one domain; that only
 * works with a fixed one.
 *
 * Static files under /backend/assets and /backend/ext are still served by the web
 * server under every domain (e.g. the QR library for CMS:USER_2FA).
 *
 * Preview and frontend editing then run via the domain of the website: see
 * FrontendHandover.
 *
 * BEHIND A PROXY
 * --------------------
 * HTTP_HOST is compared; if REMOTE_ADDR is in security.trusted_proxies, the
 * first value of X-Forwarded-Host.
 *
 * @see Camindo\Core\FrontendHandover
 */
namespace Camindo\Core;

class BackendDomain
{
    public function __construct(private Config $config) {}

    /** Configured backend domain (lowercase, with port if any), '' = none */
    public function configured(): string
    {
        return self::normalize((string)$this->config->get('admin.backend_domain'));
    }

    /** Is a fixed backend domain configured? */
    public function isFixed(): bool
    {
        return $this->configured() !== '';
    }

    /**
     * Does this request run under the backend domain? Without an entry always true
     * (every domain is then the backend domain).
     */
    public function isBackendHost(): bool
    {
        $domain = $this->configured();
        if ($domain === '') return true;
        $host = $this->requestHost();
        // Configured without port: ignore the port of the request
        if (!str_contains($domain, ':') && ($colon = strrpos($host, ':')) !== false && !str_contains($host, ']')) {
            $host = substr($host, 0, $colon);
        }
        return $host === $domain;
    }

    /**
     * Backend entry point under a foreign domain: terminate with 404. Called from
     * Core/Core.php for CAMINDO_BACKEND, not on the command line.
     */
    public function enforce(): void
    {
        if (PHP_SAPI === 'cli' || $this->isBackendHost()) return;
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo "Not found\n";
        exit;
    }

    /**
     * Absolute address in the backend, e.g. url('/index.php'). Without a fixed domain the
     * relative one as before (paths.backend + $path).
     */
    public function url(string $path = '/'): string
    {
        $relative = rtrim((string)$this->config->get('paths.backend'), '/') . $path;
        $domain = $this->configured();
        if ($domain === '') return $relative;
        // Backend over http only if the session cookie explicitly runs without Secure
        // (local test without SSL, admin.cookie_secure = false)
        $scheme = $this->config->get('admin.cookie_secure') === false ? 'http' : 'https';
        return $scheme . '://' . $domain . $relative;
    }

    /** Host of the request, lowercase, with port as sent */
    private function requestHost(): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $forwarded = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
        if ($forwarded !== '' && $remote !== '') {
            foreach ((array)$this->config->get('security.trusted_proxies') as $range) {
                if (ClientIp::inRange($remote, (string)$range)) {
                    $host = $forwarded;
                    break;
                }
            }
        }
        return self::normalize($host);
    }

    /** "https://CMS.Example.com/" -> "cms.example.com" */
    public static function normalize(string $domain): string
    {
        $domain = mb_strtolower(trim($domain));
        $domain = (string)preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain);
        $domain = rtrim(explode('/', $domain, 2)[0], '.');
        return preg_match('/^[a-z0-9.\-\[\]:]+$/', $domain) ? $domain : '';
    }
}
