<?php
/**
 * Dataset subclass for the table `admin` - the backend users.
 *
 * In camindo, "admin" means any user with the right to log in to the backend, regardless
 * of their permissions. Not to be confused with "frontend user": those are the visitors of
 * the frontend (see Camindo\Core\Modules\Record\RecordUser).
 *
 * Besides the usual dataset tasks, the class contains the login itself -
 * loginByName() and, for two-factor login, loginByCode().
 *
 * PROTECTION OF STORED DATA
 * --------------------
 *     password              Field type 'password' -> Crypt::passwordHash(), 64 characters
 *                           with embedded salt. Not reversible; only passwordVerify()
 *                           can compare.
 *     sig                   Signature over admin_id, name, password, group_id,
 *                           status_id and second_factor (Camindo\Core\Integrity).
 *                           hookOnSaved() writes it after every save; the login and every
 *                           backend request (Session::load()) check it. A row changed
 *                           by SQL is therefore worthless.
 *     email, mobile, phone  Field type 'encrypted' -> Crypt::encrypt(), varbinary columns.
 *                           Reversible, but only searchable as an exact match (data_enc principle).
 *     uid                   Random key for the two-factor login.
 *     second_factor,        Authenticator app (TotpFactor): not in the schema, save()
 *     totp_*                never writes them, hookOnLoaded() takes the secret and time step
 *                           out of load(). The secret is encrypted and bound to the row,
 *                           second_factor is in the signature; plus the table
 *                           admin_recovery_code (hashed recovery codes).
 *     ft                    Full-text column, fed from name, displayname, primename
 *                           and sortname (see source_fields in the schema).
 *
 * PERMISSIONS
 * --------------------
 * Via group_id; group 1 is the superuser. Backend::getGroups() currently reads the
 * groups with direct SQL - the class Group is still an empty skeleton.
 *
 * PERSONAL SETTINGS (`settings`, JSON)
 * --------------------
 *     language_id    Preferred language version: the tab that the input mask of a
 *                    record opens first (area.details.tabs); '' = main language
 *     backend_code   Backend language of the snippets (Cms::backendLanguages())
 *     locale         ICU locale of the date, time and number formats (Cms::userLocale)
 *     timezone       Time zone for date and time values (Cms::userTZ)
 *     fed            Frontend editing: 0 off, 1 until the browser closes/logout, 2 permanent
 *                    (cookie 'fed', see Cms::fedActive())
 *     widgets        Widgets of the start page as a list
 *                    [['module' => 'Logfiles', 'position' => 2], …] - several may fit
 *                    into one slot; slot 1 is the module tiles
 *                    (Camindo\Core\Widgets)
 *     reports        Subscribed reports of the scheduler (scheduler_ids)
 *     message_digest Summary of the internal messages by email: 0 off,
 *                    1 daily (scheduler task messagedigest, MessageDigest)
 * Maintained via AdminBackend::settings() (top right in the backend, or as a tab in the
 * detail view - there a superuser sets them for other admins) and via the login form
 * (backend language, locale, time zone). The values are kept in the session details and
 * apply from Session::load() on for every request (Cms::applyAdminSettings());
 * hookOnSaved() writes them into running sessions.
 *
 * PROTECTION AGAINST BRUTE FORCE
 * --------------------
 * loginByName() asks LoginGuard before every check whether the entered name is currently
 * blocked, and reports every failed attempt back there. The counter is kept per entered
 * name - including one that does not exist at all: otherwise the behavior of the login
 * would reveal which accounts exist. Settings: admin.bruteforce_max,
 * admin.bruteforce_delay, admin.bruteforce_ip.
 *
 * At exactly bruteforce_max failed attempts, one last attempt is still allowed, and the
 * message says so. Only beyond that is the account blocked.
 *
 * Known device (device cookie, LoginDevices): failed attempts then count against a
 * separate key per account and device (guardKey()), not against the name. Someone who
 * deliberately locks the name from outside does not lock out the owner on their own
 * computer; a copied cookie likewise gets only bruteforce_max attempts. The throttling
 * per IP (admin.throttle.login_ip) applies to everyone.
 *
 * TWO-FACTOR LOGIN
 * --------------------
 * After the correct password, loginByName() creates a uid and starts the second factor
 * of the admin (secondFactor(), SecondFactorInterface), without creating a session yet;
 * loginByCode() checks the entered code and only then logs in.
 *
 *     TotpFactor   Authenticator app (RFC 6238) or recovery code - if
 *                  the admin has set up TOTP and security.admin_2fa is not
 *                  'email'
 *     EmailFactor  five-digit code by email - otherwise, if admin.2_factor is on
 *
 * The time window is set in admin.2_factor_expiration. Each uid is good for exactly one
 * confirmation attempt - afterwards it is re-randomized, whether or not the code was
 * correct. A wrong code also counts in LoginGuard against the name of the account, and a
 * correct password does not reset the counter - only a complete login does. Otherwise a
 * six-digit code could be guessed arbitrarily often by alternating password and code.
 *
 * With security.admin_2fa = totp_required, superusers must have TOTP. If it is missing,
 * the login does not end with a session but with the setup in the login dialog
 * (_loginComplete() -> 'totp_setup', loginByTotpSetup()).
 *
 * Independent of admin.2_factor, an admin can choose the email code for themselves
 * (admin.second_factor = 'email', set up in AdminBackend::totp()) -
 * also as a way back from the authenticator app.
 *
 * MUST CHANGE PASSWORD
 * --------------------
 * Before the session, _loginComplete() checks whether the password must be renewed:
 *
 *     password_change = 1        another admin set it (creation,
 *                                reset by the superuser) - hookOnSaved()
 *     password_changed_at old    older than admin.password_retention_days
 *
 * Then, instead of the session, there is a fresh uid and 'password_renew'; the
 * login dialog asks for the new password (loginByPasswordChange()). It must meet the
 * policy and differ from the previous one. Only after that follow - if needed - the
 * mandatory setup of TOTP and the session.
 *
 * LOGIN DEVICES
 * --------------------
 * After every complete login (with session), LoginDevices records the device;
 * an unknown device of an account that already has devices triggers an email
 * (security.device_notify, security.login_notifier). The two-factor step remains
 * in place even on known devices.
 *
 * @see Camindo\Core\Modules\Admin\AdminBackend  Backend interface
 * @see Camindo\Core\Session                     Session management
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Admin;

use Camindo\Core\Access;
use Camindo\Core\ClientIp;
use Camindo\Core\Cms;
use Camindo\Core\Dataset;
use Camindo\Core\Integrity;
use Camindo\Core\LoginDevices;
use Camindo\Core\LoginGuard;
use Camindo\Core\RateLimiter;
use Camindo\Core\Widgets;

/**
 * @property-read EmailFactor $emailFactor  Loaded on demand, see $lazyServices
 * @property-read TotpFactor $totpFactor    Loaded on demand, see $lazyServices
 * @property-read WebAuthnFactor $webAuthnFactor  Loaded on demand, see $lazyServices
 * @property-read LoginGuard $loginGuard  Loaded on demand, see $lazyServices
 * @property-read Integrity $integrity    Loaded on demand, see $lazyServices
 * @property-read Widgets $widgets        Loaded on demand, see $lazyServices
 * @property-read LoginDevices $loginDevices  Loaded on demand, see $lazyServices
 * @property-read RateLimiter $rateLimiter  Loaded on demand, see $lazyServices
 * @property-read ClientIp $clientIp      Loaded on demand, see $lazyServices
 */
class Admin extends Dataset {

    // Only needed at login and on save - so not fetched in the constructor,
    // but on first access from the container.
    // @see Camindo\Core\LazyServicesTrait
    protected array $lazyServices = [
        'emailFactor' => EmailFactor::class,
        'totpFactor' => TotpFactor::class,
        'webAuthnFactor' => WebAuthnFactor::class,
        'loginGuard' => LoginGuard::class,
        'integrity' => Integrity::class,
        'widgets' => Widgets::class,
        'loginDevices' => LoginDevices::class,
        'rateLimiter' => RateLimiter::class,
        'clientIp' => ClientIp::class,
    ];

    // Audit log: login code and time, signature and personal interface
    // settings do not belong in the log (password: only "changed")
    protected array $auditIgnore = ['uid', 'uid_date', 'code', 'login_date', 'settings'];

    // changePassword(): the admin sets their own password (also in the login dialog,
    // where there is no session yet) - no renewed obligation to change
    private bool $passwordChangedBySelf = false;

    protected function hookDefinition(): void
    {
        $this->tableName  = 'admin';
        $this->moduleName = 'Admin';
        $this->defaultSortOrder = 'name ASC';
        $this->schema = [
            'admin_id' => [
                'type'  => 'integer',
                'label' => 'admin_id',
                'min' => 1
            ],
            'group_id' => [
                'type'  => 'integer',
                'label' => '#admin.field_admin_group_id',
                'display_type' => 'select',
                'min' => 1
            ],
            'name' => [
                'type'  => 'chars',
                'label' => '#admin.field_admin_name',
                'max'   => 64,
                'flags' => ['trim', 'not_empty', 'name', 'unique']
            ],
            'password' => [
                'type'  => 'password',
                'label' => '#admin.field_admin_password',
                'display_type' => 'password',
                // Length of the input; the actual requirements are set by the
                // Password extension (security.password_policy, also server-side)
                'min'   => 8,
                'max'   => 128,
                'flags' => ['not_empty'],
                'extension' => 'Password',
            ],
            'uid' => [
                'type'  => 'chars',
                'label' => '#admin.field_admin_uid',
                'max'   => 43
            ],
            'uid_date'  => [
                'type'  => 'timestampl',
                'label' => '#admin.field_admin_uid_date',
            ],
            'code' => [
                'type'  => 'chars',
                'label' => '#admin.field_admin_code',
                'max'   => 5
            ],
            'primename' => [
                'type'  => 'text',
                'label' => '#admin.field_admin_primename',
                'flags' => ['trim']
            ],
            'displayname' => [
                'type'  => 'text',
                'label' => '#admin.field_admin_displayname',
                'flags' => ['trim']
            ],
            'sortname' => [
                'type'  => 'text',
                'label' => '#admin.field_admin_sortname',
                'flags' => ['trim']
            ],
            'email' => [
                'type'  => 'encrypted',
                'label' => '#admin.field_admin_email',
                'display_type' => 'email',
                'max'   => 255,
                'flags' => ['trim']
            ],
            'mobile' => [
                'type'  => 'encrypted',
                'label' => '#admin.field_admin_mobile',
                'max'   => 255,
                'flags' => ['trim']
            ],
            'phone' => [
                'type'  => 'encrypted',
                'label' => '#admin.field_admin_phone',
                'max'   => 255,
                'flags' => ['trim']
            ],
            'status_id' => [
                'type'  => 'integer',
                'label' => '#admin.field_admin_status_id',
                'display_type' => 'select',
            ],
            'ft' => [
                'type'  => 'fulltext',
                'label' => '#admin.field_admin_ft',
                'source_fields' => ['name', 'display_name', 'primename', 'sortname']
            ],
            // Personal settings - see class comment. The subfields are only checked
            // if 'settings' is in $fields (AdminBackend::settings()).
            'settings' => [
                'type'  => 'json',
                'label' => '#admin.field_admin_settings',
            ],
            'settings.language_id' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#admin.field_admin_settings_language_id',
                'tooltip' => '#admin.tooltip_settings_language_id',
                'max'   => 11,
                'flags' => ['trim'],
            ],
            'settings.backend_code' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#admin.field_admin_settings_backend_code',
                'tooltip' => '#admin.tooltip_settings_backend_code',
                'max'   => 8,
                'flags' => ['trim', 'not_empty'],
                'default' => Cms::FALLBACK_LANG,
            ],
            'settings.locale' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#admin.field_admin_settings_locale',
                'tooltip' => '#admin.tooltip_settings_locale',
                'max'   => 16,
                'flags' => ['trim', 'not_empty'],
                'default' => (string)$this->config->get('system.locale'),
            ],
            'settings.timezone' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#admin.field_admin_settings_timezone',
                'tooltip' => '#admin.tooltip_settings_timezone',
                'max'   => 64,
                'flags' => ['trim', 'not_empty'],
                'default' => (string)$this->config->get('system.timezone'),
            ],
            'settings.fed' => [
                'type'  => 'integer',
                'display_type' => 'radio',
                'label' => '#admin.field_admin_settings_fed',
                'tooltip' => '#admin.tooltip_settings_fed',
                'min'   => 0,
                'max'   => 2,
                'default' => '0',
            ],
            'settings.widgets' => [
                'type'  => 'json',
                'label' => '#admin.field_admin_settings_widgets',
            ],
            // subscribed reports of the scheduler (scheduler_ids)
            'settings.reports' => [
                'type'  => 'json',
                'display_type' => 'checkbox',
                'label' => '#admin.field_admin_settings_reports',
                'tooltip' => '#admin.tooltip_settings_reports',
            ],
            // Summary of the internal messages by email (MessageDigest)
            'settings.message_digest' => [
                'type'  => 'integer',
                'display_type' => 'radio',
                'label' => '#admin.field_admin_settings_message_digest',
                'tooltip' => '#admin.tooltip_settings_message_digest',
                'min'   => 0,
                'max'   => 1,
                'default' => '0',
            ],
            'deleted' => [
                'type'  => 'bit',
                'label' => '#admin.field_admin_deleted',
            ],
        ];
    }

    /**
     * Login Admin
     * 
     * @param string   $name         Column `name`
     * @param string   $password     Column `password` unhashed
     * @param string   $interface    Session interface: backend | php
     *                               (Access::INTERFACES) - the group must be
     *                               allowed to use it
     * @param int|null $maxLifetime  php: maximum session lifetime (Session::create())
     * @return array|null            Return values on success: admin data, otherwise null.
     *                               With two-factor login active, NO session is
     *                               created yet - then 'sessid' is missing from the result and
     *                               loginByCode() must follow.
     *
     * Error messages end up in Cms::$errors; the caller retrieves them with errorMsg().
     * Whether the user name or the password was wrong is deliberately not distinguished.
     * Every failure goes into LoginGuard; if the name is blocked there, no further check is made.
     */
    public function loginByName(string $name, string $password, string $interface = 'backend', ?int $maxLifetime = null): ?array
    {
        // Strip control characters: no name contains them, and guardKey() thereby separates
        // the device counter from the name - an entered name cannot hit it this way
        $trimmedName = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
        $guardKey = $this->guardKey($trimmedName);

        // Blocked? Then reject without further checks - a block should not
        // depend on whether the credentials are correct this time.
        if ($blockedSeconds = $this->loginGuard->blockedFor($guardKey)) {
            $this->cms->errorsReset();
            $this->cms->error($this->cms->snippet('admin.error_login_blocked', [
                'minutes' => (int)ceil($blockedSeconds / 60)
            ]));
            return null;
        }
        // Failed attempts per origin across all names (password spraying: many names
        // with one password each - the per-name block then never triggers).
        // admin.throttle.login_ip, counted in _loginFailedMessage()
        if (!$this->rateLimiter->allows('admin_login_ip', $this->clientIp->rateKey())) {
            $this->cms->errorsReset();
            $this->cms->error($this->cms->snippet('admin.error_login_blocked', [
                'minutes' => (int)ceil($this->rateLimiter->limits('admin_login_ip')[1] / 60)
            ]));
            return null;
        }

        if (!$data = $this->load($trimmedName, 'name')) {
            // Admin not found
            // Security: keep the message generic (leave it unclear whether the username or password was wrong)
            //
            // The check also runs without a match. Otherwise a made-up name responds
            // measurably faster than an existing one, and the same error message
            // would be useless - the running time would reveal the account.
            $this->crypt->passwordVerify($password, '');

            $this->cms->errorsReset();
            $this->cms->error($this->_loginFailedMessage($guardKey));
            return null;
        };

        // Admin found
        $adminId = (int)$data['admin_id'];

        // Check the password (the salt is part of the stored value, comparison in
        // constant time - see Crypt)
        if (!$this->crypt->passwordVerify($password, (string)$data['password'])) {
            // Password does not match
            $this->cms->error($this->_loginFailedMessage($guardKey));
            return null;
        }

        // Row signature: a group changed by SQL, or a swapped name
        // or hash, is detected here (Integrity). The message stays the generic one.
        if ($this->integrity->verifyAdmin($adminId) === null) {
            $this->cms->error($this->_loginFailedMessage($guardKey));
            return null;
        }

        // Hash in the earlier format or with old parameters: now that the password is
        // known, recompute it (Crypt::passwordNeedsRehash()) - directly, without
        // save(): the policy applies to new passwords, not to existing ones
        if ($this->crypt->passwordNeedsRehash((string)$data['password'])) {
            $this->db->query(
                "UPDATE `admin` SET `password` = ? WHERE `admin_id` = ?",
                'si', [$this->crypt->passwordHash($password), $adminId]
            );
            $this->integrity->signAdmin($adminId);
        }

        // Status blocked
        if ($data['status_id'] > 0) {
            // Benutzer gesperrt.
            // Not a failed attempt: the credentials were correct, and the counter should not
            // count against the legitimate owner of a deactivated account.
            $errorMsg = $this->cms->snippet('admin.error_account_disabled');
            $this->cms->error($errorMsg);
            return null;
        }

        // Interface: may the group use it? (Access, "Interfaces") - before
        // the second factor, so that no code is sent for an impermissible login.
        // Not a failed attempt, as with a blocked account.
        if (!$this->_interfaceAllowed($data, $interface)) return null;

        // Second factor of this admin (email, TOTP or none)
        $factor = $this->secondFactor($data);

        if ($factor === null) {
            // Direct login (without second factor) - the password was everything
            $this->loginGuard->clear($guardKey);
            return $this->_loginComplete($data, $interface, $maxLifetime);
        }

        // Zwei-Faktor-Anmeldung
        // --------------------
        // The LoginGuard counter stays as it is here: failed attempts at entering the
        // code count against the same name (loginByCode()), and a correct
        // password must not clear them - otherwise a six-digit TOTP code could be
        // guessed arbitrarily often by alternating password and code.
        //
        // Create uid; the code (email) comes from the factor. Save first, then
        // send: if saving fails, the code in the email would be worthless.
        $updateData = [
            'admin_id' => $adminId,
            'uid' => $this->crypt->randomKey(32),
            'code' => '',
            'uid_date' => 'UTC_TIMESTAMP()'
        ];
        if (!$this->save(array_keys($updateData), $updateData)) {
            return null;
        }
        $data = array_replace($data, $updateData, ['uid_date' => '']);
        if (!$factor->start($data)) {
            return null;
        }
        $data['factor'] = $factor->name();
        // Passkey: options for navigator.credentials.get() (challenge from the uid)
        if ($factor instanceof WebAuthnFactor) {
            $data['webauthn'] = $factor->loginOptions($data);
            $data['totp_active'] = $factor->totpActive($adminId);
        }

        // Return admin data - without 'sessid': loginByCode() follows
        return $data;
    }

    /**
     * The second factor of an admin (SecondFactorInterface).
     *
     *     Passkey  if the admin has passkeys, or second_factor = 'webauthn'
     *              (WebAuthnFactor::applies())
     *     TOTP     if the admin has set it up and security.admin_2fa is not
     *              'email' - independent of admin.2_factor: the admin's own choice
     *              is the stronger factor and is not switched off
     *     Email    otherwise, if admin.2_factor is on
     *     none     otherwise (null)
     *
     * @param array $data  Admin::load()
     */
    public function secondFactor(array $data): ?SecondFactorInterface
    {
        $adminId = (int)($data['admin_id'] ?? 0);
        // Passkeys first: only passkeys (second_factor 'webauthn') or passkeys alongside
        // TOTP - the form then accepts both (WebAuthnFactor)
        if ($this->webAuthnFactor->applies($data)) {
            return $this->webAuthnFactor;
        }
        if ($this->totpFactor->available() && $this->totpFactor->isActive($adminId)) {
            return $this->totpFactor;
        }
        // Email code: for everyone (admin.2_factor) or as the admin's own choice
        if ($this->config->get('admin.2_factor') || $this->emailFactorChosen($adminId)) {
            return $this->emailFactor;
        }
        return null;
    }

    /**
     * Has the admin chosen the email code themselves (admin.second_factor = 'email')?
     * The signed row is read - a choice set by SQL would not count,
     * but neither would a 'totp' removed by SQL (Integrity::verifyAdmin()).
     */
    public function emailFactorChosen(int $adminId): bool
    {
        $row = $this->integrity->verifyAdmin($adminId);
        return $row !== null && ($row['second_factor'] ?? '') === 'email';
    }

    /**
     * Enable or disable the email code as its own second factor
     * (AdminBackend::totp(); TOTP switches off via TotpFactor::disable()).
     * second_factor is part of the signature - re-sign afterwards.
     */
    public function setEmailFactor(int $adminId, bool $on): void
    {
        $this->db->query(
            "UPDATE `admin` SET `second_factor` = ? WHERE `admin_id` = ? AND `second_factor` IN ('', 'email')",
            'si', [$on ? 'email' : '', $adminId]
        );
        $this->integrity->signAdmin($adminId);
    }

    /**
     * Must the admin renew their password before getting a session?
     * (see class comment "Must change password")
     *
     * @return string  '' no, 'initial' password set by another admin,
     *                 'expired' older than admin.password_retention_days
     */
    public function passwordChangeReason(int $adminId): string
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `password`, `password_change`, `password_changed_at` FROM `admin` WHERE `admin_id` = ?",
                'i', [$adminId]
            );
            $row = $this->db->num_rows > 0 ? $this->db->fetch() : null;
        }
        finally {
            $this->db->popResult();
        }
        // Without a password (created only via CLI/SQL) there is nothing to renew
        if ($row === null || (string)$row['password'] === '') return '';
        if ((int)$row['password_change'] === 1) return 'initial';
        $days = (int)$this->config->get('admin.password_retention_days');
        if ($days > 0) {
            $changed = (string)($row['password_changed_at'] ?? '');
            if ($changed === '' || strtotime($changed . ' UTC') < time() - $days * 86400) return 'expired';
        }
        return '';
    }

    /**
     * Must this admin still set up TOTP before getting a session?
     * (security.admin_2fa = totp_required, superuser without TOTP)
     */
    public function totpSetupRequired(array $data): bool
    {
        $adminId = (int)($data['admin_id'] ?? 0);
        // A passkey fulfills the requirement as well (the stronger factor)
        return $this->totpFactor->requiredFor((int)($data['group_id'] ?? 0))
            && !$this->totpFactor->isActive($adminId)
            && !$this->webAuthnFactor->hasPasskeys($adminId);
    }

    /**
     * Last step of every successful login: create a session - or, if
     * TOTP is required and still missing, a fresh uid instead of the session for the
     * setup in the login dialog (loginByTotpSetup()).
     *
     * @param array $data  Admin::load()
     * @return array|null  $data with 'sessid', or with 'uid' + 'totp_setup' (token,
     *                     otpauth URI and secret, see TotpFactor::newSetup())
     */
    private function _loginComplete(array $data, string $interface = 'backend', ?int $maxLifetime = null): ?array
    {
        $adminId = (int)$data['admin_id'];
        if (!$this->_interfaceAllowed($data, $interface)) return null;

        // Renewing the password or setting up TOTP is only possible in the backend login dialog
        if ($interface !== 'backend'
            && ($this->passwordChangeReason($adminId) !== '' || $this->totpSetupRequired($data))) {
            $this->cms->error($this->cms->snippet('admin.error_login_backend_first'));
            return null;
        }

        // Renew password (initial password, expired): before anything else
        $reason = $this->passwordChangeReason($adminId);
        if ($reason !== '') {
            $data['uid'] = $this->_newUid($adminId);
            $data['password_renew'] = $reason;
            return $data;
        }

        if ($this->totpSetupRequired($data)) {
            $data['uid'] = $this->_newUid($adminId);
            $data['totp_setup'] = $this->totpFactor->newSetup($data);
            return $data;
        }

        // Last login only as a day (privacy: no working hours); close the
        // time window of the two-factor login - otherwise uid_date would show when
        // the login began. Directly via SQL: login_date is not in the schema.
        $this->db->query("UPDATE `admin` SET `login_date` = UTC_DATE(), `uid_date` = '1000-01-01 00:00:00' WHERE `admin_id` = ?",
            'i', [$adminId]);

        // Start session. The caller thereby sets the cookie.
        $data['sessid'] = $this->session->create($adminId, $interface, $maxLifetime);
        // Recognize the device, with a new email notice on a new device (LoginDevices),
        // and attach the session to the device - removing the device ends it
        // (AdminBackend::devices(), foreign key admin_session.device_id).
        // Backend only: a program has no device cookie and would be a "new device"
        // at every login.
        if ($interface === 'backend') {
            $device = $this->loginDevices->register('admin', $adminId, $data);
            if (!empty($device['device_id'])) {
                $this->session->attachDevice((string)$data['sessid'], (int)$device['device_id']);
            }
        }
        return $data;
    }

    /**
     * May the admin's group use the interface? Otherwise a message in Cms::errors.
     *
     * @param array  $data       Admin::load()
     * @param string $interface  Access::INTERFACES
     */
    private function _interfaceAllowed(array $data, string $interface): bool
    {
        if ($this->container->get(Access::class)->groupInterface((int)($data['group_id'] ?? 0), $interface)) return true;
        $this->cms->error($this->cms->snippet('admin.error_login_interface'));
        return false;
    }

    /**
     * Fresh uid for a further step in the login dialog (renew password,
     * set up TOTP) - valid for admin.2_factor_expiration seconds, one attempt.
     */
    private function _newUid(int $adminId): string
    {
        $uid = $this->crypt->randomKey(32);
        $this->db->query(
            "UPDATE `admin` SET `uid` = ?, `code` = '', `uid_date` = UTC_TIMESTAMP() WHERE `admin_id` = ?",
            'si', [$uid, $adminId]
        );
        return $uid;
    }

    /**
     * Complete the login with a new password (see class comment "Must change
     * password"): redeem the uid, check the new password (policy, repetition,
     * not the previous one) and save it, then continue like every login
     * (_loginComplete() - there, if needed, setting up TOTP).
     *
     * A rejected password is not a failed attempt in the LoginGuard: name, password
     * and second factor were already correct.
     *
     * @return array|null  as _loginComplete(); for a rejected password
     *                     ['retry' => true, 'uid' => …, 'password_renew' => …]
     *                     (errors in Cms::errors); null = uid invalid/expired
     */
    public function loginByPasswordChange(string $uid, string $password, string $repeat): ?array
    {
        if (!($row = $this->_claimUid($uid))) {
            $this->cms->error($this->cms->snippet('admin.error_login_code_failed'));
            return null;
        }
        $data = $this->_checkClaimed($row);
        if ($data === null) return null;
        $adminId = (int)$data['admin_id'];
        $reason = $this->passwordChangeReason($adminId);
        if ($reason === '') {
            // No longer needed (another window) - just continue
            return $this->_loginComplete($data);
        }

        if ($this->changePassword($adminId, $password, $repeat)) {
            return $this->_loginComplete($data);
        }
        return ['retry' => true, 'uid' => $this->_newUid($adminId), 'password_renew' => $reason];
    }

    /**
     * Renew own password (login dialog and AdminBackend::password()): repeated
     * identically, not the previous one, policy (Dataset::validateValue()).
     * The caller checks the previous password.
     *
     * @return bool  false: error in Cms::errors
     */
    public function changePassword(int $adminId, string $password, string $repeat): bool
    {
        $label = $this->cms->snippet('admin.field_admin_password');
        if ($password === '' || $password !== $repeat) {
            $this->cms->error($this->cms->snippet('admin.error_password_repeat'), 'password', $label);
            return false;
        }
        $this->db->pushResult();
        $this->db->query("SELECT `password` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
        $stored = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->popResult();
        if ($stored !== '' && $this->crypt->passwordVerify($password, $stored)) {
            $this->cms->error($this->cms->snippet('admin.error_password_unchanged'), 'password', $label);
            return false;
        }
        $data = ['admin_id' => $adminId, 'password' => $password];
        // hookOnSaved() sets password_changed_at and resets password_change
        $this->passwordChangedBySelf = true;
        try {
            return $this->save(['password'], $data) !== null;
        }
        finally {
            $this->passwordChangedBySelf = false;
        }
    }

    /**
     * Key in the LoginGuard for a login to this account: the name - or,
     * if the browser is a known device of the account (LoginDevices), a
     * separate counter per account and device. Thus an attacker who tries
     * the name cannot lock out the owner on the owner's own device (see
     * class comment "Protection against brute force").
     *
     * The device counter starts with a control character that loginByName() removes from
     * every input - an entered name never matches it.
     *
     * @param string $name     Login name (entered, or from the row)
     * @param int    $adminId  0 = search by name
     */
    public function guardKey(string $name, int $adminId = 0): string
    {
        if ($adminId < 1 && $name !== '') {
            $this->db->pushResult();
            try {
                $this->db->query(
                    "SELECT `admin_id` FROM `admin` WHERE `name` = ? AND `deleted` = 0 LIMIT 1",
                    's', [$name]
                );
                $adminId = (int)($this->db->fetchNum()[0] ?? 0);
            }
            finally {
                $this->db->popResult();
            }
        }
        $deviceId = $adminId > 0 ? $this->loginDevices->knownDeviceId('admin', $adminId) : 0;
        return $deviceId > 0 ? "\x1Fdevice " . $deviceId . ': ' . $name : $name;
    }

    /** guardKey() for a row from _claimUid() (admin_id, name) */
    private function _rowGuardKey(array $row): string
    {
        return $this->guardKey((string)$row['name'], (int)$row['admin_id']);
    }

    /**
     * Record a failed attempt and return the matching message.
     *
     * The message always stays the same generic one - only supplemented by the
     * note that the next attempt is the last one. This note appears at
     * exactly bruteforce_max failed attempts; from the next one on, the block in
     * blockedFor() applies. It reveals nothing about the account: the entered
     * name is counted, whether or not it exists.
     *
     * @param string $name  Key in the LoginGuard (guardKey())
     * @return string       Finished message text
     */
    private function _loginFailedMessage(string $name): string
    {
        $this->rateLimiter->record('admin_login_ip', $this->clientIp->rateKey());
        $attempts = $this->loginGuard->recordFailure($name);
        $max = (int)$this->config->get('admin.bruteforce_max');
        $minutes = (int)ceil((int)$this->config->get('admin.bruteforce_delay') / 60);

        // This failed attempt was one too many: from now on the block applies, and the
        // next call does not even reach the password check. Then the
        // hint about the details is moot - only the waiting time counts.
        if ($max > 0 && $attempts > $max) {
            return $this->cms->snippet('admin.error_login_blocked', ['minutes' => $minutes]);
        }

        $message = $this->cms->snippet('admin.error_login_failed');

        // Exactly bruteforce_max: one attempt is still free, and this is said as well.
        if ($max > 0 && $attempts == $max) {
            $message .= ' ' . $this->cms->snippet('admin.error_login_last_try', [
                'minutes' => $minutes
            ]);
        }

        return $message;
    }

    /**
     * Log in admin - 2-factor auth step 2
     *
     * The factor (email code, TOTP code or recovery code) is checked by
     * SecondFactorInterface::verify(); what is the same for all lives here: uid within the
     * time window, one attempt per uid, LoginGuard and signature.
     *
     * @param string $uid    Value in column `uid`, from the hidden field in the form, step 1
     * @param string $code   Input (code from email or app, recovery code)
     * @return array|null    Return values on success: admin data, otherwise null.
     *                       With 'totp_setup' instead of 'sessid': TOTP is required and still
     *                       has to be set up (loginByTotpSetup()).
     */
    public function loginByCode(string $uid, string $code, string $interface = 'backend', ?int $maxLifetime = null): ?array
    {
        $trimmedCode = trim($code);

        // Redeem uid: valid, within the time window and exactly once (see _claimUid())
        if ($trimmedCode === '' || !($row = $this->_claimUid($uid))) {
            $this->cms->error($this->cms->snippet('admin.error_login_code_failed'));
            return null;
        }
        $data = $this->_checkClaimed($row);
        if ($data === null) return null;

        $factor = $this->secondFactor($data);
        // No factor anymore (configuration has changed in the meantime) or wrong code
        // uid: challenge of the passkey login (WebAuthnFactor::challengeFor())
        if ($factor === null || !$factor->verify(array_replace($data, ['code' => (string)$row['code'], 'uid' => trim($uid)]), $trimmedCode)) {
            $this->cms->error($this->_codeFailedMessage($this->_rowGuardKey($row)));
            return null;
        }
        $this->loginGuard->clear($this->_rowGuardKey($row));

        return $this->_loginComplete($data, $interface, $maxLifetime);
    }

    /**
     * Complete the login with the mandatory TOTP setup (security.admin_2fa =
     * totp_required, superuser without TOTP): check the code from the app against the secret in the
     * setup token, enable TOTP, create the session.
     *
     * Unlike loginByCode(), a wrong code does not lead back to the start - it
     * counts in the LoginGuard, and the result carries a new uid and the same
     * setup token, so the QR code does not have to be scanned again.
     *
     * @return array|null  on success $data with 'sessid' and 'recovery_codes';
     *                     on a wrong code ['retry' => true, 'uid' => …,
     *                     'totp_setup' => …] (errors in Cms::errors); otherwise null
     */
    public function loginByTotpSetup(string $uid, string $token, string $code): ?array
    {
        if (!($row = $this->_claimUid($uid))) {
            $this->cms->error($this->cms->snippet('admin.error_login_code_failed'));
            return null;
        }
        $data = $this->_checkClaimed($row);
        if ($data === null) return null;
        $adminId = (int)$data['admin_id'];
        $setup = $this->totpFactor->setupFromToken($data, $token);
        if ($setup === null || !$this->totpSetupRequired($data)) {
            $this->cms->error($this->cms->snippet('admin.error_login_code_failed'));
            return null;
        }

        $codes = $this->totpFactor->enable($data, $token, $code);
        if ($codes === null) {
            // "Wrong code" without "please log in again" - it continues in the same form
            $this->cms->error($this->_codeFailedMessage($this->_rowGuardKey($row), 'admin.error_totp_code'));
            if ($this->loginGuard->blockedFor($this->_rowGuardKey($row))) return null;
            $newUid = $this->crypt->randomKey(32);
            $this->db->query(
                "UPDATE `admin` SET `uid` = ?, `uid_date` = UTC_TIMESTAMP() WHERE `admin_id` = ?",
                'si', [$newUid, $adminId]
            );
            return ['retry' => true, 'uid' => $newUid, 'totp_setup' => $setup];
        }
        $this->loginGuard->clear($this->_rowGuardKey($row));

        $data = $this->_loginComplete($data);
        if ($data !== null) $data['recovery_codes'] = $codes;
        return $data;
    }

    /**
     * Redeem uid: find the row within the time window and re-randomize the uid immediately.
     * Only whoever wins the UPDATE gets the row - two simultaneous attempts
     * with the same uid yield exactly one.
     *
     * @return array|null  admin_id, name, code (state before invalidation)
     */
    private function _claimUid(string $uid): ?array
    {
        $uid = trim($uid);
        // Do not search at all without a uid: an empty value matches nothing sensible
        if ($uid === '') return null;

        // Seconds that may pass at most between login and confirmation of the code.
        // The default is set in Config; without it the time window would be 0 seconds
        // and no login could ever succeed.
        $seconds = (int)$this->config->get('admin.2_factor_expiration');
        $this->db->query(
            "SELECT `admin_id`, `name`, `code` FROM `admin` WHERE `uid` = ?"
            . " AND `uid_date` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)"
            . " AND `status_id` = 0 AND `deleted` = 0",
            'si',
            [$uid, $seconds]
        );
        $row = $this->db->num_rows > 0 ? $this->db->fetch() : null;

        // Re-randomize the uid in any case, also on a failed attempt. This way every uid
        // is good for exactly one confirmation attempt.
        $this->invalidateLoginCode($uid);
        if ($row === null || $this->db->affected_rows !== 1) return null;
        return $row;
    }

    /**
     * After redeeming the uid: check the LoginGuard block and the signature, load the
     * record. Errors in Cms::errors.
     */
    private function _checkClaimed(array $row): ?array
    {
        // Blocked? Then do not check at all - as in loginByName()
        if ($blockedSeconds = $this->loginGuard->blockedFor($this->_rowGuardKey($row))) {
            $this->cms->error($this->cms->snippet('admin.error_login_blocked', [
                'minutes' => (int)ceil($blockedSeconds / 60)
            ]));
            return null;
        }
        $adminId = (int)$row['admin_id'];
        // Row signature (see loginByName())
        if ($this->integrity->verifyAdmin($adminId) === null) {
            $this->cms->error($this->cms->snippet('admin.error_login_code_failed'));
            return null;
        }
        // Fetch the complete record via load() instead of passing on the raw row:
        // load() decrypts email/mobile/phone and runs the hooks, so that
        // the same array results here as with loginByName().
        return $this->load($adminId);
    }

    /**
     * Wrong code: record a failed attempt in the LoginGuard (same counter as the
     * password, admin.bruteforce_max) and return the message for it.
     */
    private function _codeFailedMessage(string $name, string $snippet = 'admin.error_login_code_failed'): string
    {
        $attempts = $this->loginGuard->recordFailure($name);
        $max = (int)$this->config->get('admin.bruteforce_max');
        $minutes = (int)ceil((int)$this->config->get('admin.bruteforce_delay') / 60);
        if ($max > 0 && $attempts > $max) {
            return $this->cms->snippet('admin.error_login_blocked', ['minutes' => $minutes]);
        }
        $message = $this->cms->snippet($snippet);
        if ($max > 0 && $attempts == $max) {
            $message .= ' ' . $this->cms->snippet('admin.error_login_last_try', ['minutes' => $minutes]);
        }
        return $message;
    }

    /**
     * Re-randomize the uid and delete the code - the previous link is thereby used up.
     *
     * Called after every confirmation attempt, including a failed one.
     * The uid stays a random value and is not cleared: an empty field
     * would match all accounts without a running login at the next query.
     *
     * Deliberately goes directly via SQL instead of save(): the match is found via the
     * old uid, not via the admin_id, so that even a failed attempt invalidates the
     * affected uid without us needing to know to whom it belongs.
     */
    private function invalidateLoginCode(string $uid): void
    {
        $this->db->query(
            "UPDATE `admin` SET `uid` = ?, `code` = '' WHERE `uid` = ?",
            'ss',
            [$this->crypt->randomKey(32), $uid]
        );
    }

    /**
     * Before creating: pre-fill the fields of the two-factor login.
     *
     * The uid is filled with a random value right away instead of staying empty - an empty
     * value would be guessable for anyone and would match all accounts not yet
     * configured at the comparison.
     */
    protected function hookOnCreate(array &$data): void
    {
        // Initialize 2_factor auth fields
        $data['uid'] = $this->crypt->randomKey(32);
        $data['uid_date'] = '1000-01-01';
    }

    /**
     * Before saving: check the personal settings against the actual
     * options. A selection list does not protect against a manipulated
     * form, and time zone as well as language codes later go unchecked to
     * date_default_timezone_set() and to the snippet cache.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        // User names admin_* are reserved for anonymized accounts (module
        // Privacy) - an existing name remains allowed
        if ((empty($fields) || in_array('name', $fields, true)) && array_key_exists('name', $data)) {
            $name = (string)$data['name'];
            if (preg_match('/^admin_/i', $name) && $name !== (string)($previousData['name'] ?? '')) {
                $this->createFieldError('admin.error_name_reserved', 'name', $this->schema['name']['label'] ?? 'name');
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }

        // User name scheduler cannot be changed
        $previousName = (string)($previousData['name'] ?? '');
        if ($previousName === 'scheduler') {
            $data['name'] = 'scheduler';
        }

        if (!empty($fields) && !in_array('settings', $fields, true)) return;
        $settings = (array)($data['settings'] ?? []);
        $schema = $this->schema;

        $code = trim((string)($settings['backend_code'] ?? ''));
        // Upgrade earlier two-letter codes ("de") to today's ("de_DE")
        if (preg_match('/^[a-z]{2,3}$/', $code)) {
            $code = Cms::matchLanguage($code, $this->cms->backendLanguages()) ?? $code;
            $data['settings']['backend_code'] = $code;
        }
        if ($code !== '' && !in_array($code, $this->cms->backendLanguages(), true)) {
            $this->createFieldError('error_invalid_format', 'settings.backend_code', $schema['settings.backend_code']['label']);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        $locale = trim((string)($settings['locale'] ?? ''));
        if ($locale !== '' && !$this->cms->isValidLocale($locale)) {
            $this->createFieldError('error_invalid_format', 'settings.locale', $schema['settings.locale']['label']);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        $timezone = trim((string)($settings['timezone'] ?? ''));
        if ($timezone !== '' && !$this->cms->isValidTimezone($timezone)) {
            $this->createFieldError('error_invalid_format', 'settings.timezone', $schema['settings.timezone']['label']);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        // Preferred language version: '' or an existing language
        $languageId = trim((string)($settings['language_id'] ?? ''));
        if ($languageId !== '') {
            $this->db->query("SELECT 1 FROM `language` WHERE `language_id` = ?", 'i', [(int)$languageId]);
            if ($this->db->num_rows < 1 || !preg_match('/^\d+$/', $languageId)) {
                $this->createFieldError('error_invalid_format', 'settings.language_id', $schema['settings.language_id']['label']);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }
        // Start page widgets: a list of module name + slot, each module
        // at most once. Only the FORM is checked - whether the module still exists
        // and whether the admin concerned may use it is decided by Widgets::render() when
        // displayed (an uninstalled module must not block saving the other
        // settings).
        // Reports: list of scheduler_ids (which the group may subscribe to, checked by
        // AdminBackend::settings(); the dispatch checks the group again anyway)
        if (array_key_exists('reports', $settings)) {
            $data['settings']['reports'] = $this->system->sanitizeIds((array)$settings['reports'], true);
        }
        $widgets = (array)($settings[Widgets::SETTINGS_KEY] ?? []);
        if (count($this->widgets->normalize($widgets, requireExists: false)) !== count($widgets)) {
            $this->createFieldError('error_invalid_format', 'settings.widgets', $schema['settings.widgets']['label']);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
    }

    /**
     * After saving: write changed settings into the admin's running sessions -
     * for the admin themselves they apply immediately; for an editor
     * whom a superuser sets them for, with their next request.
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $adminId = (int)($data['admin_id'] ?? 0);
        // New password: remember the time; if another admin set it
        // (creation, reset), it must be changed at the first login.
        // The CLI (admin password, no session admin) and the admin themselves set
        // a final password. Other sessions of the account end.
        if ((empty($fields) || in_array('password', $fields, true)) && (string)($data['password'] ?? '') !== '') {
            $actorId = (int)$this->session->get('admin_id');
            $bySomeoneElse = !$this->passwordChangedBySelf && $actorId > 0 && $actorId !== $adminId;
            $this->db->query(
                // only the day (data minimization) - it is enough for the admin.password_retention_days period
                "UPDATE `admin` SET `password_changed_at` = UTC_DATE(), `password_change` = ? WHERE `admin_id` = ?",
                'ii', [$bySomeoneElse ? 1 : 0, $adminId]
            );
            $this->session->closeAllOf($adminId, keepCurrent: true);
        }
        // Update the signature as soon as a signed column was involved (empty
        // $fields = all columns). Runs in the transaction of save().
        if (empty($fields) || array_intersect($fields, ['name', 'password', 'group_id', 'status_id'])) {
            $this->integrity->signAdmin($adminId);
        }
        if (!empty($fields) && !in_array('settings', $fields, true)) return;
        $this->session->refreshDetails($adminId);
    }

    /**
     * Change individual settings of an admin, the others remain - for the
     * login form (backend language, locale, time zone) and AdminBackend::settings().
     *
     * @param int   $adminId
     * @param array $settings  keys to change (language_id, backend_code,
     *                         locale, timezone, fed)
     * @return bool            false: error in Cms::errors
     */
    public function saveSettings(int $adminId, array $settings): bool
    {
        if ($adminId < 1) return false;
        if (!$data = $this->load($adminId)) return false;
        $current = (array)($data['settings'] ?? []);
        // Fill missing values with the schema defaults (an admin from the time before
        // the settings does not have any yet)
        $defaults = (array)($this->init()['settings'] ?? []);
        $merged = array_replace($defaults, $current, $settings);
        $data = [
            'admin_id' => $adminId,
            'settings' => $merged,
        ];
        return $this->save(['settings'], $data) !== null;
    }

    /**
     * After loading: remove the secret and time step of the authenticator app. load()
     * reads the whole row; the secret (binary, encrypted) does not belong in any
     * array that is passed on - only TotpFactor reads it itself.
     */
    protected function hookOnLoaded(array &$data): void
    {
        unset($data['totp_secret'], $data['totp_step']);
    }

    protected function hookOnDelete(array $data): void
    {
    }

    /**
     * On deletion (soft delete): remove everything by which the account could still log in
     * or be recognized - sessions, stored devices, passkeys,
     * recovery codes - and the "last edited" history (history). The
     * audit log stays; it is only anonymized on request (module Privacy,
     * AdminAnonymizer). One's own account cannot be deleted.
     */
    protected function hookOnSoftDelete(array $data): void
    {
        $adminId = (int)($data['admin_id'] ?? 0);
        if ($adminId > 0 && $adminId === (int)$this->session->get('admin_id')) {
            $errorMsg = $this->cms->snippet('admin.error_delete_self');
            $this->cms->error($errorMsg);
            throw new \Exception('#' . $errorMsg);
        }
        $this->removeCredentials($adminId);
    }

    /**
     * Remove sessions, devices, passkeys, recovery codes and history of an account
     * (delete, anonymize).
     */
    public function removeCredentials(int $adminId): void
    {
        if ($adminId < 1) return;
        $this->session->closeAllOf($adminId);
        $this->loginDevices->removeAll('admin', $adminId);
        $this->container->get(Passkeys::class)->removeAll($adminId);
        $this->db->pushResult();
        try {
            $this->db->query("DELETE FROM `admin_recovery_code` WHERE `admin_id` = ?", 'i', [$adminId]);
            $this->db->query("DELETE FROM `history` WHERE `admin_id` = ?", 'i', [$adminId]);
        }
        finally {
            $this->db->popResult();
        }
    }
}