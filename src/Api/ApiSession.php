<?php
/**
 * Anmelden, Abmelden und Mitarbeiten über die API.
 *
 * Die API ist eine weitere Oberfläche zum Backend: dieselben Admins, dieselben
 * Rechte ihrer Benutzergruppe. Welche Oberflächen eine Gruppe benutzen darf, steht
 * in der Gruppe (Access::interface(), `group`.`details`.`interfaces`).
 *
 * VIER WEGE ZU EINER SESSION
 * --------------------
 *     login()     PHP-API: Name + Passwort (+ zweiter Faktor über loginCode()),
 *                 eigene Session mit Höchstlaufzeit (Oberfläche php)
 *     runAs()     Kommandozeile und Zeitplaner: ohne Passwort - wer die
 *                 Kommandozeile des Servers hat, ist ohnehin vertrauenswürdig
 *                 (wie Session::setCliAdmin()). Nur mit PHP_SAPI = cli, eigene
 *                 Session (Oberfläche cli)
 *     attach()    ein LLM-Chat oder Custom Tool im Backend: arbeitet in der
 *                 Backend-Session des angemeldeten Admins mit, sieht genau, was er
 *                 sieht, und handelt als er (der Kanal steht im Audit-Log)
 *     resume()    eine bestehende cli-/php-Session wieder aufnehmen (jeder weitere
 *                 Prozess bzw. Request)
 *
 * Eine eigene Session (login, runAs) endet mit logout(): ihre Arbeitskopien werden
 * verworfen, die Zeile gelöscht. Nach attach() beendet logout() nur die Mitarbeit -
 * die Backend-Session gehört dem Admin.
 *
 * Fehler werfen ApiException (NOT_LOGGED_IN, SECOND_FACTOR …).
 *
 * @see Camindo\Core\Api\ApiContext   Oberfläche, Kanal, Prüfung je Aufruf
 * @see Camindo\Core\Session          die Session-Zeilen (`interface`, `expires_at`)
 * @see Camindo\Core\Modules\Admin\Admin::loginByName()
 */
namespace Camindo\Core\Api;

use Camindo\Core\Access;
use Camindo\Core\AuditLog;
use Camindo\Core\Cms;
use Camindo\Core\ContainerInterface;
use Camindo\Core\InstanceCache;
use Camindo\Core\Integrity;
use Camindo\Core\Session;
use Camindo\Core\Modules\Admin\Admin;
use Camindo\Core\Modules\Record\Record;

class ApiSession
{
    /** Oberflächen mit Anmeldung per Passwort (login()) */
    public const LOGIN_INTERFACES = ['php'];

    public function __construct(
        private Cms $cms,
        private Session $session,
        private Access $access,
        private Integrity $integrity,
        private AuditLog $auditLog,
        private InstanceCache $instanceCache,
        private ApiContext $context,
        private ContainerInterface $container,
    )
    {
    }

    // ====================
    // Anmelden

    /**
     * Mit Name und Passwort anmelden.
     *
     * Hat der Admin einen zweiten Faktor, kommt noch keine Session zurück, sondern
     * ApiException SECOND_FACTOR mit ['factor' => email|totp|webauthn, 'uid' => …]
     * in errors[0] - weiter mit loginCode(). Passkeys lassen sich über die API
     * nicht bestätigen.
     *
     * @param string   $name         Admin-Name
     * @param string   $password     Passwort
     * @param int|null $maxLifetime  Höchstlaufzeit der Session in Sekunden (begrenzt
     *                               auf api.max_lifetime; null = api.max_lifetime)
     * @param string   $interface    LOGIN_INTERFACES
     * @return array                 whoami() der neuen Session, dazu session_id
     */
    public function login(string $name, string $password, ?int $maxLifetime = null, string $interface = 'php'): array
    {
        $this->assertLoginInterface($interface);
        $this->cms->errorsReset();
        $admin = $this->container->get(Admin::class);
        $data = $admin->loginByName($name, $password, $interface, $maxLifetime);
        if ($data === null) {
            $this->auditLog->login('login_failed', 0, trim($name), $interface);
            throw $this->context->exceptionFromCms(ApiException::NOT_LOGGED_IN);
        }
        if (empty($data['sessid'])) {
            throw new ApiException(ApiException::SECOND_FACTOR, $this->cms->snippet(
                ($data['factor'] ?? '') === 'totp' ? 'start.text_login_code_totp' : 'start.text_login_code'
            ), [['factor' => (string)($data['factor'] ?? ''), 'uid' => (string)($data['uid'] ?? '')]]);
        }
        return $this->started((string)$data['sessid'], $interface, (int)$data['admin_id']);
    }

    /**
     * Zweiter Schritt der Anmeldung: Code aus E-Mail bzw. Authenticator-App (auch
     * ein Wiederherstellungscode). Die uid ist mit dem Versuch verbraucht.
     *
     * @param string   $uid          aus login() (SECOND_FACTOR)
     * @param string   $code         der Code
     * @param int|null $maxLifetime  wie login()
     * @param string   $interface    wie login()
     */
    public function loginCode(string $uid, string $code, ?int $maxLifetime = null, string $interface = 'php'): array
    {
        $this->assertLoginInterface($interface);
        $this->cms->errorsReset();
        $data = $this->container->get(Admin::class)->loginByCode($uid, $code, $interface, $maxLifetime);
        if ($data === null || empty($data['sessid'])) {
            $this->auditLog->login('login_failed', 0, '', $interface);
            throw $this->context->exceptionFromCms(ApiException::NOT_LOGGED_IN);
        }
        return $this->started((string)$data['sessid'], $interface, (int)$data['admin_id']);
    }

    /**
     * Als Admin anmelden, ohne Passwort - nur auf der Kommandozeile (siehe
     * Klassenkommentar). Der Admin muss aktiv sein und seine Gruppe die Oberfläche
     * benutzen dürfen.
     *
     * @param int|string $admin        admin_id oder Name
     * @param string     $channel      cli | scheduler (ApiContext::CHANNELS)
     * @param int|null   $maxLifetime  Höchstlaufzeit (null = api.max_lifetime)
     * @param string     $interface    Oberfläche der Session (cli)
     */
    public function runAs(int|string $admin, string $channel = 'cli', ?int $maxLifetime = null, string $interface = 'cli'): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new ApiException(ApiException::ACCESS_DENIED, 'runAs() is only available on the command line');
        }
        $adminId = $this->adminIdOf($admin);
        $row = $adminId > 0 ? $this->integrity->verifyAdmin($adminId) : null;
        if ($row === null || $row['status_id'] !== 0 || $row['deleted'] !== 0) {
            throw new ApiException(ApiException::NOT_LOGGED_IN, $this->cms->snippet('error_access_denied'));
        }
        if (!$this->access->groupInterface((int)$row['group_id'], $interface)) {
            throw new ApiException(ApiException::NOT_LOGGED_IN, $this->cms->snippet('admin.error_login_interface'));
        }
        $sessionId = $this->session->create($adminId, $interface, $maxLifetime);
        return $this->started($sessionId, $channel, $adminId);
    }

    /**
     * In der Backend-Session des angemeldeten Admins mitarbeiten (LLM-Chat, Custom
     * Tool) - siehe Klassenkommentar. Setzt eine geladene Backend-Session voraus.
     *
     * @param string $channel  llm | tool
     */
    public function attach(string $channel = 'llm'): array
    {
        if ((string)$this->session->get('session_id') === '' || $this->context->interface() !== 'backend') {
            throw new ApiException(ApiException::NOT_LOGGED_IN, $this->cms->snippet('error_access_denied'));
        }
        $this->context->begin($channel, attached: true);
        $this->context->require();
        return $this->whoami();
    }

    /**
     * Eine cli-/php-Session wieder aufnehmen.
     *
     * @param string $sessionId  aus login() / runAs()
     * @param string $channel    '' = die Oberfläche der Session; scheduler für den
     *                           Zeitplaner
     */
    public function resume(string $sessionId, string $channel = ''): array
    {
        if (!$this->session->load($sessionId, ['cli', 'php'])) {
            throw new ApiException(ApiException::NOT_LOGGED_IN, $this->cms->snippet('error_access_denied'));
        }
        $this->context->begin($channel !== '' ? $channel : $this->context->interface());
        $this->context->require();
        return $this->whoami();
    }

    // ====================
    // Abmelden

    /**
     * Abmelden - siehe Klassenkommentar.
     *
     * @return array  ['closed' => bool] - false nach attach() (die Backend-Session bleibt)
     */
    public function logout(): array
    {
        if (!$this->context->active()) return ['closed' => false];
        if ($this->context->attached()) {
            $this->context->end();
            return ['closed' => false];
        }
        $adminId = (int)$this->session->get('admin_id');
        $sessionId = (string)$this->session->get('session_id');
        // Arbeitskopien dieser Session verwerfen (Bearbeitungssperren freigeben)
        $this->container->get(Record::class)->cancelSession($sessionId);
        $this->auditLog->login('logout', $adminId);
        $this->session->close($sessionId);
        $this->context->end();
        return ['closed' => true];
    }

    // ====================
    // Auskunft

    /**
     * Wer ist angemeldet, über welche Oberfläche, und was darf er?
     *
     * @return array  admin_id, name, group {id, label, superuser}, interface, channel,
     *                attached, can_preview, expires_at (ISO mit Offset oder null), modules
     *                (Name => Zusatzrechte), instances (Liste aus instance_id, label,
     *                languages [{code, language_id, primary, read, write}])
     */
    public function whoami(): array
    {
        $this->context->require();
        $adminId = (int)$this->session->get('admin_id');
        $details = (array)($this->session->get('details') ?? []);
        $superuser = $this->access->isSuperuser();

        $modules = [];
        foreach ($this->cms->modules as $name => $settings) {
            // nur die der Rechteverwaltung - Start, Messaging … stehen jedem offen
            if (!$this->access->isManagedModule((string)$name) || !$this->access->module((string)$name)) continue;
            $modules[(string)$name] = $superuser
                ? array_values(array_map('strval', array_keys((array)($settings['access'] ?? []))))
                : array_values((array)($this->access->modules()[$name] ?? []));
        }

        $instances = [];
        $labels = $this->instanceCache->instanceLabels();
        foreach ($this->access->instanceIds() as $instanceId) {
            $languages = [];
            $primary = true;
            foreach ($this->instanceCache->languages($instanceId) as $languageId => $language) {
                $languages[] = [
                    'code'        => (string)$language['code'],
                    'language_id' => (int)$languageId,
                    'primary'     => $primary,
                    'read'        => $this->access->languageRead((int)$languageId),
                    'write'       => $this->access->languageWrite((int)$languageId),
                ];
                $primary = false;
            }
            $instances[] = [
                'instance_id' => $instanceId,
                'label'       => $this->context->plain($this->cms->textOrSnippet((string)($labels[$instanceId] ?? ''))),
                'languages'   => $languages,
            ];
        }

        return [
            'admin_id'    => $adminId,
            'name'        => (string)($details['name'] ?? ''),
            'group'       => [
                'group_id'  => $this->access->groupId(),
                'label'     => $this->context->plain($this->cms->textOrSnippet((string)($details['label'] ?? ''))),
                'superuser' => $superuser,
            ],
            'interface'   => $this->context->interface(),
            'channel'     => $this->context->channel(),
            'attached'    => $this->context->attached(),
            'can_preview' => $this->context->attached(),
            'expires_at'  => $this->context->isoTime($this->session->get('expires_at')),
            'modules'     => $modules,
            'instances'   => $instances,
        ];
    }

    // ====================

    /**
     * Neue eigene Session: Kanal setzen, Anmeldung ins Audit-Log, Auskunft.
     */
    private function started(string $sessionId, string $channel, int $adminId): array
    {
        $this->context->begin($channel);
        $this->auditLog->login('login', $adminId);
        return ['session_id' => $sessionId] + $this->whoami();
    }

    private function assertLoginInterface(string $interface): void
    {
        if (!in_array($interface, self::LOGIN_INTERFACES, true)) {
            throw new ApiException(ApiException::INVALID_PARAM, "login is not available for interface \"{$interface}\"");
        }
    }

    /** admin_id zu Id oder Name (0 = unbekannt) */
    private function adminIdOf(int|string $admin): int
    {
        if (is_int($admin) || ctype_digit($admin)) return (int)$admin;
        $data = $this->container->get(Admin::class)->load(trim($admin), 'name');
        $this->cms->errorsReset();
        return (int)($data['admin_id'] ?? 0);
    }
}
