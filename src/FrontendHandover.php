<?php
/**
 * Handover from the backend to the domain of a website - for preview and
 * frontend editing, when the backend runs under another domain (fixed
 * backend domain or domain of another instance; BackendDomain,
 * developer docs, chapter 13 (Security: authentication).
 *
 * The backend's session cookie only applies under the backend domain. So that the
 * website shows an unpublished record or the pens of
 * frontend editing, the call carries a signed token.
 *
 * PREVIEW
 * --------------------
 * backend/preview.php (with admin session) redirects to
 *
 *     {protocol}://{primary_domain}/index.php?c9preview={token}
 *
 * The token (previewToken()) contains instance, path, language, admin, a marker of
 * its session and the expiry, plus an HMAC with .camindo.key. Page::
 * resolveRequest() checks it (readPreview()) and renders in preview mode. Valid:
 *
 *     - at most PREVIEW_SECONDS after clicking "Preview" (reload in the tab works)
 *     - only as long as the backend session lives (admin.max_inactive_sec, logout,
 *       "log out on all devices" - after that every preview link is worthless)
 *     - only for this path and this instance, only under its domain
 *
 * The session id itself is not in the token, only an HMAC of it.
 *
 * FRONTEND EDITING
 * --------------------
 * The cookie `fed` carries a signed value instead of 1/2 (fedCookieValue()):
 * "{mode}.{admin_id}.{expiry}.{HMAC}". Cms::fedActive() checks it (fedMode()).
 * A manually set "fed=1" no longer shows pens - otherwise cFED() would reveal
 * the backend domain to everyone. Under the website domain the preview sets it
 * (mode from the admin's settings, "f" in the token); under the backend domain
 * as before via Backend::setFedCookie().
 *
 * SYNCHRONIZATION WITH ALL WEBSITES
 * --------------------
 * A cookie only applies to its own domain; the backend cannot set it reliably for
 * another domain either via Ajax or via iframe (third-party cookies are blocked or
 * partitioned by the browsers). After saving the setting, the browser therefore
 * runs through all websites and back into the backend as a normal navigation:
 *
 *     backend -> site1/index.php?c9fed={token}&c9hops=0-2 -> site3/... -> backend
 *
 * The token (fedSyncData()) contains the list of domains, the mode (also 0 =
 * switch off) and the return address, signed - c9hops only selects from it.
 * Before that, cApp.ui.fedSync() checks each domain per image (c9probe=1) to see
 * whether it is reachable and belongs to this installation; only those enter the chain.
 *
 * @see Camindo\Core\BackendDomain
 * @see Camindo\Core\Page::resolveRequest()
 */
namespace Camindo\Core;

class FrontendHandover
{
    /** Parameter of the preview in the frontend */
    public const PARAM = 'c9preview';
    /** Parameter "enable frontend editing on this website" */
    public const FED_PARAM = 'c9fed';
    /** Validity of a FED link in seconds (the settings page creates it anew) */
    public const FED_LINK_SECONDS = 3600;
    /** Order of the websites during synchronization (fedSyncData()): indices, separated by "-" */
    public const HOPS_PARAM = 'c9hops';
    /** Probe before synchronization: answers with a 1x1 GIF if domain and token match */
    public const PROBE_PARAM = 'c9probe';
    /** Maximum age of a preview link in seconds */
    public const PREVIEW_SECONDS = 8 * 3600;
    /** Lifetime of the cookie `fed` in mode 1 ("for this session") */
    private const FED_SESSION_SECONDS = 12 * 3600;
    /** ... in mode 2 ("permanent") */
    private const FED_PERMANENT_SECONDS = 365 * 86400;

    public function __construct(
        private Config $config,
        private Crypt $crypt,
        private Session $session,
        private InstanceCache $instanceCache,
        private BackendDomain $backendDomain,
        private Integrity $integrity,
    ) {}

    /**
     * Preview address under the domain of the website - '' if the backend already runs under
     * a domain of the instance or the instance has no primary_domain (then
     * preview.php renders itself). Under a foreign domain - fixed backend domain
     * or the domain of another instance - the images would otherwise be missing: the .htaccess
     * in the media directory only allows the domains of the instance (MediaAccess).
     * Requires a loaded admin session.
     */
    public function previewUrl(int $instanceId, string $request, string $lang): string
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $host = (string)preg_replace('/:\d+$/', '', $host);
        if (!$this->backendDomain->isFixed() && $host !== ''
            && $this->instanceCache->matchDomain($host) === $instanceId) return '';
        $instance = $this->instanceCache->instance($instanceId) ?? [];
        $domain = (string)($instance['primary_domain'] ?? '');
        if ($domain === '' || !preg_match('/^[a-z0-9.\-:]+$/', $domain)) return '';
        $token = $this->previewToken($instanceId, $request, $lang);
        if ($token === '') return '';
        $protocol = (string)($instance['protocol'] ?? 'https') === 'http' ? 'http' : 'https';
        return $protocol . '://' . $domain . '/index.php?' . self::PARAM . '=' . $token;
    }

    /** Token for previewUrl() - '' without session */
    public function previewToken(int $instanceId, string $request, string $lang): string
    {
        $adminId = (int)$this->session->get('admin_id');
        $sessionId = (string)$this->session->get('session_id');
        if ($adminId < 1 || $sessionId === '') return '';
        $settings = (array)(((array)($this->session->get('details') ?? []))['settings'] ?? []);
        return $this->sign([
            'k' => 'preview',
            'i' => $instanceId,
            'r' => $request,
            'l' => $lang,
            'a' => $adminId,
            's' => $this->sessionTag($sessionId),
            'e' => time() + self::PREVIEW_SECONDS,
            'f' => (int)($settings['fed'] ?? 0),
        ]);
    }

    /**
     * Address that sets the frontend editing cookie under the domain of the website
     * and redirects to the start page - '' without primary_domain or with
     * mode 0. Requires a loaded admin session.
     *
     * @param int $mode  1 | 2 (saved setting of the admin)
     */
    public function fedUrl(int $instanceId, int $mode): string
    {
        if (!in_array($mode, [1, 2], true)) return '';
        $instance = $this->instanceCache->instance($instanceId) ?? [];
        $domain = (string)($instance['primary_domain'] ?? '');
        if ($domain === '' || !preg_match('/^[a-z0-9.\-:]+$/', $domain)) return '';
        $adminId = (int)$this->session->get('admin_id');
        $sessionId = (string)$this->session->get('session_id');
        if ($adminId < 1 || $sessionId === '') return '';
        $token = $this->sign([
            'k' => 'fed',
            'i' => $instanceId,
            'a' => $adminId,
            's' => $this->sessionTag($sessionId),
            'e' => time() + self::FED_LINK_SECONDS,
            'f' => $mode,
        ]);
        $protocol = (string)($instance['protocol'] ?? 'https') === 'http' ? 'http' : 'https';
        return $protocol . '://' . $domain . '/index.php?' . self::FED_PARAM . '=' . $token;
    }

    /**
     * Data for synchronizing the cookie with all websites (cApp.ui.fedSync()) -
     * null without session or without website.
     *
     * @param array<string,int> $sites      domain => instance_id (access checked,
     *                                      without the domain of the backend)
     * @param int               $mode       0 | 1 | 2
     * @param string            $returnUrl  return to the backend (absolute)
     * @return array{token:string, sites:list<array{i:int, origin:string}>}|null
     */
    public function fedSyncData(array $sites, int $mode, string $returnUrl): ?array
    {
        if (!in_array($mode, [0, 1, 2], true)) return null;
        $adminId = (int)$this->session->get('admin_id');
        $sessionId = (string)$this->session->get('session_id');
        if ($adminId < 1 || $sessionId === '') return null;
        $list = [];
        $origins = [];
        foreach ($sites as $domain => $instanceId) {
            $domain = (string)$domain;
            if (!preg_match('/^[a-z0-9.\-:]+$/', $domain)) continue;
            $instance = $this->instanceCache->instance((int)$instanceId) ?? [];
            $protocol = (string)($instance['protocol'] ?? 'https') === 'http' ? 'http' : 'https';
            $origins[] = ['i' => count($list), 'origin' => $protocol . '://' . $domain];
            $list[] = [$domain, (int)$instanceId, $protocol];
        }
        if (!$list) return null;
        $token = $this->sign([
            'k' => 'fedsync',
            'd' => $list,
            'a' => $adminId,
            's' => $this->sessionTag($sessionId),
            'e' => time() + self::FED_LINK_SECONDS,
            'f' => $mode,
            'r' => $returnUrl,
        ]);
        return ['token' => $token, 'sites' => $origins];
    }

    /**
     * Check the synchronization token (expiry, live session).
     *
     * @return array|null  sites (list of [domain, instance_id, protocol]), admin_id, fed, return
     */
    public function readFedSync(string $token): ?array
    {
        $data = $this->verify($token);
        if ($data === null || ($data['k'] ?? '') !== 'fedsync') return null;
        if ((int)($data['e'] ?? 0) < time()) return null;
        $adminId = (int)($data['a'] ?? 0);
        if (!$this->sessionAlive($adminId, (string)($data['s'] ?? ''))) return null;
        return [
            'sites' => array_values(array_filter((array)($data['d'] ?? []), 'is_array')),
            'admin_id' => $adminId,
            'fed' => (int)($data['f'] ?? 0),
            'return' => (string)($data['r'] ?? ''),
        ];
    }

    /**
     * Absolute address of backend/index.php for the return after synchronization:
     * fixed backend domain, otherwise the domain of this request.
     */
    public function backendReturnUrl(): string
    {
        if ($this->backendDomain->isFixed()) return $this->backendDomain->url('/index.php');
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if (!preg_match('/^[a-z0-9.\-]+(:\d+)?$/', $host)) return '';
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
        return ($https ? 'https' : 'http') . '://' . $host
            . rtrim((string)$this->config->get('paths.backend'), '/') . '/index.php';
    }

    /**
     * Check the FED token (like readPreview(): expiry, live session).
     *
     * @return array|null  instance, admin_id, fed
     */
    public function readFed(string $token): ?array
    {
        $data = $this->verify($token);
        if ($data === null || ($data['k'] ?? '') !== 'fed') return null;
        if ((int)($data['e'] ?? 0) < time()) return null;
        $adminId = (int)($data['a'] ?? 0);
        if (!$this->sessionAlive($adminId, (string)($data['s'] ?? ''))) return null;
        return ['instance' => (int)($data['i'] ?? 0), 'admin_id' => $adminId, 'fed' => (int)($data['f'] ?? 0)];
    }

    /**
     * Check the preview token from the request.
     *
     * @return array|null  instance, request, lang, admin_id, fed - null: none,
     *                     invalid or expired token, session over
     */
    public function readPreview(string $token): ?array
    {
        $data = $this->verify($token);
        if ($data === null || ($data['k'] ?? '') !== 'preview') return null;
        if ((int)($data['e'] ?? 0) < time()) return null;
        $adminId = (int)($data['a'] ?? 0);
        if (!$this->sessionAlive($adminId, (string)($data['s'] ?? ''))) return null;
        return [
            'instance' => (int)($data['i'] ?? 0),
            'request' => (string)($data['r'] ?? ''),
            'lang' => (string)($data['l'] ?? ''),
            'admin_id' => $adminId,
            'fed' => (int)($data['f'] ?? 0),
        ];
    }

    /**
     * Value of the cookie `fed` for $mode (1 = this session, 2 = permanent);
     * '' for 0.
     *
     * @return array{0:string,1:int}  [value, expiry as Unix time; 0 = browser session]
     */
    public function fedCookieValue(int $adminId, int $mode): array
    {
        if (!in_array($mode, [1, 2], true) || $adminId < 1) return ['', 0];
        $expires = time() + ($mode === 2 ? self::FED_PERMANENT_SECONDS : self::FED_SESSION_SECONDS);
        $payload = $mode . '.' . $adminId . '.' . $expires;
        return [$payload . '.' . $this->crypt->hash('fed|' . $payload), $mode === 2 ? $expires : 0];
    }

    /** Mode from the cookie `fed` (0 = off or invalid) */
    public function fedMode(string $value): int
    {
        return self::fedModeOf($this->crypt, $value);
    }

    /**
     * Like fedMode(), without instance - for Cms::fedActive() (Cms does not know the session
     * and should not load it for every frontend page either).
     */
    public static function fedModeOf(Crypt $crypt, string $value): int
    {
        $parts = explode('.', $value);
        if (count($parts) !== 4) return 0;
        [$mode, $adminId, $expires, $mac] = $parts;
        if (!in_array($mode, ['1', '2'], true) || !ctype_digit($adminId) || !ctype_digit($expires)) return 0;
        if ((int)$expires < time()) return 0;
        $payload = $mode . '.' . $adminId . '.' . $expires;
        return hash_equals($crypt->hash('fed|' . $payload), $mac) ? (int)$mode : 0;
    }

    /** Set or delete the cookie `fed` under the current domain */
    public function setFedCookie(int $adminId, int $mode): void
    {
        if (headers_sent()) return;
        $options = ['path' => '/', 'samesite' => 'Lax', 'httponly' => true];
        [$value, $expires] = $this->fedCookieValue($adminId, $mode);
        if ($value === '') {
            setcookie(Cms::FED_COOKIE, '', $options + ['expires' => time() - 3600]);
            return;
        }
        setcookie(Cms::FED_COOKIE, $value, $options + ($expires > 0 ? ['expires' => $expires] : []));
    }

    private function sign(array $data): string
    {
        $payload = self::b64(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $payload . '.' . $this->crypt->hash('handover|' . $payload);
    }

    private function verify(string $token): ?array
    {
        if (strlen($token) > 16384 || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token)) return null;
        [$payload, $mac] = explode('.', $token, 2);
        if (!hash_equals($this->crypt->hash('handover|' . $payload), $mac)) return null;
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) ? $data : null;
    }

    /** Marker of a session in the token - the id itself does not leave the backend */
    private function sessionTag(string $sessionId): string
    {
        return substr($this->crypt->hash('preview-session|' . $sessionId), 0, 22);
    }

    /** Does an admin session with this marker still live, and is the account still valid? */
    private function sessionAlive(int $adminId, string $tag): bool
    {
        if ($adminId < 1 || $tag === '') return false;
        $admin = $this->integrity->verifyAdmin($adminId);
        if ($admin === null || (int)$admin['status_id'] !== 0 || (int)$admin['deleted'] !== 0) return false;
        foreach ($this->session->activeSessionsOf($adminId) as $row) {
            if ($row['interface'] !== 'backend') continue;
            if (hash_equals($this->sessionTag((string)$row['session_id']), $tag)) return true;
        }
        return false;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
