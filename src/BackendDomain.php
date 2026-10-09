<?php
/**
 * Feste Backend-Domain (admin.backend_domain) - developer_docs/21-backend-domain.md.
 *
 * Ohne Eintrag läuft das Backend wie bisher unter jeder Domain der Installation
 * ({domain}/backend/). Mit Eintrag nur noch unter dieser einen:
 *
 *     Backend-Einstiege (index.php, module.php, file.php, preview.php) unter einer
 *     anderen Domain                          -> 404, ohne Hinweis auf die richtige
 *     Frontend unter der Backend-Domain       -> 302 auf das Backend
 *
 * Wozu: eine Subdomain, die niemand errät, hält Anmeldeversuche fern - die Websites
 * verraten sie nicht mehr. Und WebAuthn (Passkeys) bindet jeden Schlüssel an genau
 * eine Domain; das geht nur mit einer festen.
 *
 * Statische Dateien unter /backend/assets und /backend/ext liefert der Webserver
 * weiter unter jeder Domain aus (z. B. die QR-Bibliothek für CMS:USER_2FA).
 *
 * Vorschau und Frontend-Editing laufen dann über die Domain der Website: siehe
 * FrontendHandover.
 *
 * HINTER EINEM PROXY
 * --------------------
 * Verglichen wird HTTP_HOST; steht REMOTE_ADDR in security.trusted_proxies, der
 * erste Wert von X-Forwarded-Host.
 *
 * @see Camindo\Core\FrontendHandover
 */
namespace Camindo\Core;

class BackendDomain
{
    public function __construct(private Config $config) {}

    /** Eingetragene Backend-Domain (klein, ggf. mit Port), '' = keine */
    public function configured(): string
    {
        return self::normalize((string)$this->config->get('admin.backend_domain'));
    }

    /** Ist eine feste Backend-Domain eingetragen? */
    public function isFixed(): bool
    {
        return $this->configured() !== '';
    }

    /**
     * Läuft dieser Request unter der Backend-Domain? Ohne Eintrag immer true
     * (jede Domain ist dann Backend-Domain).
     */
    public function isBackendHost(): bool
    {
        $domain = $this->configured();
        if ($domain === '') return true;
        $host = $this->requestHost();
        // Ohne Port eingetragen: Port des Requests ignorieren
        if (!str_contains($domain, ':') && ($colon = strrpos($host, ':')) !== false && !str_contains($host, ']')) {
            $host = substr($host, 0, $colon);
        }
        return $host === $domain;
    }

    /**
     * Backend-Einstieg unter einer fremden Domain: mit 404 beenden. Aufgerufen aus
     * Core/Core.php für CAMINDO_BACKEND, nicht auf der Kommandozeile.
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
     * Absolute Adresse im Backend, z. B. url('/index.php'). Ohne feste Domain die
     * relative wie bisher (paths.backend + $path).
     */
    public function url(string $path = '/'): string
    {
        $relative = rtrim((string)$this->config->get('paths.backend'), '/') . $path;
        $domain = $this->configured();
        if ($domain === '') return $relative;
        // Backend über http nur, wenn das Session-Cookie ausdrücklich ohne Secure läuft
        // (lokaler Test ohne SSL, admin.cookie_secure = false)
        $scheme = $this->config->get('admin.cookie_secure') === false ? 'http' : 'https';
        return $scheme . '://' . $domain . $relative;
    }

    /** Host des Requests, klein, mit Port wie gesendet */
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
