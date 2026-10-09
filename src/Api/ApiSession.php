<?php
/**
 * Signing in, signing out and working via the API.
 *
 * The API is another interface to the backend: the same admins, the same
 * rights of their user group. Which interfaces a group may use is set in the
 * group (Access::interface(), `group`.`details`.`interfaces`).
 *
 * FOUR WAYS TO A SESSION
 * --------------------
 *     login()     PHP API: name + password (+ second factor via loginCode()),
 *                 own session with maximum lifetime (interface php)
 *     runAs()     command line and scheduler: without password - whoever has the
 *                 command line of the server is trusted anyway
 *                 (as Session::setCliAdmin()). Only with PHP_SAPI = cli, own
 *                 session (interface cli)
 *     attach()    an LLM chat or custom tool in the backend: works along in the
 *                 backend session of the logged-in admin, sees exactly what they see, and
 *                 acts as them (the channel is recorded in the audit log)
 *     resume()    resume an existing cli/php session (each further
 *                 process or request)
 *
 * An own session (login, runAs) ends with logout(): its working copies are discarded,
 * the row is deleted. After attach(), logout() ends only the collaboration -
 * the backend session belongs to the admin.
 *
 * Errors throw ApiException (NOT_LOGGED_IN, SECOND_FACTOR …).
 *
 * @see Camindo\Core\Api\ApiContext   interface, channel, check per call
 * @see Camindo\Core\Session          the session rows (`interface`, `expires_at`)
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
    /** Interfaces with password login (login()) */
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
    // Sign in

    /**
     * Sign in with name and password.
     *
     * If the admin has a second factor, no session comes back yet, but
     * ApiException SECOND_FACTOR with ['factor' => email|totp|webauthn, 'uid' => …]
     * in errors[0] - continue with loginCode(). Passkeys cannot be confirmed
     * via the API.
     *
     * @param string   $name         admin name
     * @param string   $password     password
     * @param int|null $maxLifetime  maximum lifetime of the session in seconds (limited
     *                               to api.max_lifetime; null = api.max_lifetime)
     * @param string   $interface    LOGIN_INTERFACES
     * @return array                 whoami() of the new session, plus session_id
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
     * Second step of the sign-in: code from e-mail resp. authenticator app (also
     * a recovery code). The uid is used up with the attempt.
     *
     * @param string   $uid          from login() (SECOND_FACTOR)
     * @param string   $code         the code
     * @param int|null $maxLifetime  as login()
     * @param string   $interface    as login()
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
     * Sign in as admin without password - only on the command line (see class
     * comment). The admin must be active and their group must be allowed to use the
     * interface.
     *
     * @param int|string $admin        admin_id or name
     * @param string     $channel      cli | scheduler (ApiContext::CHANNELS)
     * @param int|null   $maxLifetime  maximum lifetime (null = api.max_lifetime)
     * @param string     $interface    interface of the session (cli)
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
     * Work along in the backend session of the logged-in admin (LLM chat, custom
     * tool) - see class comment. Requires a loaded backend session.
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
     * Resume a cli/php session.
     *
     * @param string $sessionId  from login() / runAs()
     * @param string $channel    '' = the interface of the session; scheduler for the
     *                           scheduler
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
    // Sign out

    /**
     * Sign out - see class comment.
     *
     * @return array  ['closed' => bool] - false after attach() (the backend session remains)
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
        // Discard working copies of this session (release edit locks)
        $this->container->get(Record::class)->cancelSession($sessionId);
        $this->auditLog->login('logout', $adminId);
        $this->session->close($sessionId);
        $this->context->end();
        return ['closed' => true];
    }

    // ====================
    // Information

    /**
     * Who is signed in, via which interface, and what may they do?
     *
     * @return array  admin_id, name, group {id, label, superuser}, interface, channel,
     *                attached, can_preview, expires_at (ISO with offset or null), modules
     *                (name => extra rights), instances (list of instance_id, label,
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
            // only the rights management ones - start, messaging … are open to everyone
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
     * New own session: set channel, sign-in into the audit log, information.
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

    /** admin_id for an Id or name (0 = unknown) */
    private function adminIdOf(int|string $admin): int
    {
        if (is_int($admin) || ctype_digit($admin)) return (int)$admin;
        $data = $this->container->get(Admin::class)->load(trim($admin), 'name');
        $this->cms->errorsReset();
        return (int)($data['admin_id'] ?? 0);
    }
}
