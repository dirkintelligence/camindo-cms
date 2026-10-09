<?php
/**
 * Übergabe vom Backend an die Domain einer Website - für Vorschau und
 * Frontend-Editing, wenn das Backend unter einer anderen Domain läuft (feste
 * Backend-Domain oder Domain einer anderen Instanz; BackendDomain,
 * developer_docs/21-backend-domain.md).
 *
 * Das Session-Cookie des Backends gilt nur unter der Backend-Domain. Damit die
 * Website einen unveröffentlichten Datensatz zeigt bzw. die Stifte des
 * Frontend-Editings, trägt der Aufruf ein signiertes Token.
 *
 * VORSCHAU
 * --------------------
 * backend/preview.php (mit Admin-Session) leitet weiter auf
 *
 *     {protocol}://{primary_domain}/index.php?c9preview={token}
 *
 * Das Token (previewToken()) enthält Instanz, Pfad, Sprache, Admin, ein Kennzeichen
 * seiner Session und den Ablauf, dazu einen HMAC mit .camindo.key. Page::
 * resolveRequest() prüft es (readPreview()) und rendert im Vorschau-Modus. Gültig:
 *
 *     - höchstens PREVIEW_SECONDS nach dem Klick auf "Vorschau" (Reload im Tab geht)
 *     - nur solange die Backend-Session lebt (admin.max_inactive_sec, Abmelden,
 *       "auf allen Geräten abmelden" - danach ist jeder Vorschau-Link wertlos)
 *     - nur für diesen Pfad und diese Instanz, nur unter deren Domain
 *
 * Die Session-Id selbst steht nicht im Token, nur ein HMAC davon.
 *
 * FRONTEND-EDITING
 * --------------------
 * Das Cookie `fed` trägt statt 1/2 einen signierten Wert (fedCookieValue()):
 * "{Modus}.{admin_id}.{Ablauf}.{HMAC}". Cms::fedActive() prüft ihn (fedMode()).
 * Ein von Hand gesetztes "fed=1" zeigt keine Stifte mehr - sonst verriete cFED()
 * jedem die Backend-Domain. Unter der Website-Domain setzt es die Vorschau
 * (Modus aus den Einstellungen des Admins, "f" im Token); unter der Backend-Domain
 * wie bisher Backend::setFedCookie().
 *
 * ABGLEICH MIT ALLEN WEBSITES
 * --------------------
 * Ein Cookie gilt nur für seine Domain; das Backend kann es für eine andere Domain
 * weder per Ajax noch per iframe zuverlässig setzen (Drittanbieter-Cookies sperren
 * bzw. partitionieren die Browser). Nach dem Speichern der Einstellung läuft der
 * Browser deshalb als normale Navigation durch alle Websites und zurück ins Backend:
 *
 *     backend -> site1/index.php?c9fed={token}&c9hops=0-2 -> site3/... -> backend
 *
 * Das Token (fedSyncData()) enthält die Liste der Domains, den Modus (auch 0 =
 * ausschalten) und die Rücksprung-Adresse, signiert - c9hops wählt nur daraus.
 * Vorher prüft cApp.ui.fedSync() je Domain per Bild (c9probe=1), ob sie
 * erreichbar ist und zu dieser Installation gehört; nur diese kommen in die Kette.
 *
 * @see Camindo\Core\BackendDomain
 * @see Camindo\Core\Page::resolveRequest()
 */
namespace Camindo\Core;

class FrontendHandover
{
    /** Parameter der Vorschau im Frontend */
    public const PARAM = 'c9preview';
    /** Parameter "Frontend-Editing auf dieser Website einschalten" */
    public const FED_PARAM = 'c9fed';
    /** Gültigkeit eines FED-Links in Sekunden (die Einstellungsseite erzeugt ihn neu) */
    public const FED_LINK_SECONDS = 3600;
    /** Reihenfolge der Websites beim Abgleich (fedSyncData()): Indizes, mit "-" getrennt */
    public const HOPS_PARAM = 'c9hops';
    /** Probe vor dem Abgleich: antwortet mit einem 1x1-GIF, wenn Domain und Token passen */
    public const PROBE_PARAM = 'c9probe';
    /** Höchstalter eines Vorschau-Links in Sekunden */
    public const PREVIEW_SECONDS = 8 * 3600;
    /** Laufzeit des Cookies `fed` im Modus 1 ("für diese Sitzung") */
    private const FED_SESSION_SECONDS = 12 * 3600;
    /** ... im Modus 2 ("dauerhaft") */
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
     * Vorschau-Adresse unter der Domain der Website - '' wenn das Backend schon unter
     * einer Domain der Instanz läuft oder die Instanz keine primary_domain hat (dann
     * rendert preview.php selbst). Unter einer fremden Domain - feste Backend-Domain
     * oder die Domain einer anderen Instanz - fehlten sonst die Bilder: die .htaccess
     * im Medienverzeichnis lässt nur die Domains der Instanz zu (MediaAccess).
     * Setzt eine geladene Admin-Session voraus.
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

    /** Token für previewUrl() - '' ohne Session */
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
     * Adresse, die unter der Domain der Website das Cookie des Frontend-Editings
     * setzt und auf die Startseite weiterleitet - '' ohne primary_domain oder bei
     * Modus 0. Setzt eine geladene Admin-Session voraus.
     *
     * @param int $mode  1 | 2 (gespeicherte Einstellung des Admins)
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
     * Daten für den Abgleich des Cookies mit allen Websites (cApp.ui.fedSync()) -
     * null ohne Session oder ohne Website.
     *
     * @param array<string,int> $sites      Domain => instance_id (Zugriff geprüft,
     *                                      ohne die Domain des Backends)
     * @param int               $mode       0 | 1 | 2
     * @param string            $returnUrl  Rücksprung ins Backend (absolut)
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
     * Abgleich-Token prüfen (Ablauf, lebende Session).
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
     * Absolute Adresse von backend/index.php für den Rücksprung nach dem Abgleich:
     * feste Backend-Domain, sonst die Domain dieses Requests.
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
     * FED-Token prüfen (wie readPreview(): Ablauf, lebende Session).
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
     * Vorschau-Token aus dem Request prüfen.
     *
     * @return array|null  instance, request, lang, admin_id, fed - null: kein,
     *                     ungültiges oder abgelaufenes Token, Session vorbei
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
     * Wert des Cookies `fed` für $mode (1 = diese Sitzung, 2 = dauerhaft);
     * '' für 0.
     *
     * @return array{0:string,1:int}  [Wert, Ablauf als Unix-Zeit; 0 = Browser-Sitzung]
     */
    public function fedCookieValue(int $adminId, int $mode): array
    {
        if (!in_array($mode, [1, 2], true) || $adminId < 1) return ['', 0];
        $expires = time() + ($mode === 2 ? self::FED_PERMANENT_SECONDS : self::FED_SESSION_SECONDS);
        $payload = $mode . '.' . $adminId . '.' . $expires;
        return [$payload . '.' . $this->crypt->hash('fed|' . $payload), $mode === 2 ? $expires : 0];
    }

    /** Modus aus dem Cookie `fed` (0 = aus oder ungültig) */
    public function fedMode(string $value): int
    {
        return self::fedModeOf($this->crypt, $value);
    }

    /**
     * Wie fedMode(), ohne Instanz - für Cms::fedActive() (Cms kennt die Session
     * nicht und soll sie für jede Frontend-Seite auch nicht laden).
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

    /** Cookie `fed` unter der aktuellen Domain setzen bzw. löschen */
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

    /** Kennzeichen einer Session im Token - die Id selbst verlässt das Backend nicht */
    private function sessionTag(string $sessionId): string
    {
        return substr($this->crypt->hash('preview-session|' . $sessionId), 0, 22);
    }

    /** Lebt eine Session des Admins mit diesem Kennzeichen, und gilt das Konto noch? */
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
