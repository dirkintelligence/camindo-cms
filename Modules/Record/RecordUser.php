<?php
/**
 * Community features in the frontend: sign-in, registration, contributions, shopping basket.
 * Name in the code: $recordUser.
 *
 * "User" in camindo means the website visitor, not the backend user -
 * the latter is called "administrator" and is managed by Camindo\Core\Modules\Admin\Admin.
 *
 * WHY RecordUser AND NOT User
 * --------------------
 * A user in camindo is not a special case with its own table, but a completely
 * normal record: the profile is a `record` in an area of type
 * 'profile', and what a user contributes in the frontend (comments, ads, images)
 * are `record` entries in areas of type 'ugc'. Sign-in data, profile fields
 * and contributions thus run through the same paths as all other contents -
 * Cms::select() for reading, Record for writing (working copy, validation, uploads,
 * thumbnails, relations - all as in the backend, only without administrator).
 *
 * That is why the class lives in the module Record and carries its name prefix. The
 * module User remains the backend user interface for it (RecordUser has none).
 *
 * THE BUILDING BLOCKS
 * --------------------
 *     area 'profile'      one record per user. In `area`.`details` are the
 *                         two sign-in fields: login_name_field_id (chars or
 *                         encrypted, e.g. an e-mail address) and
 *                         login_password_field_id (password). Without both nobody can
 *                         sign in to this area.
 *     area 'ugc'          contributions. details.profile_area_ids names the profile
 *                         areas whose users may write here.
 *     record.user_id      owner of a record (profile record). The ownership check of
 *                         USER_INPUT id=… and USER_DELETE depends on it.
 *     record.status_id    2 = unconfirmed (after USER_REGISTER), 0 = enabled
 *                         (USER_REGISTER_CONFIRM), 1 = disabled
 *     record.owner_id     owner in the backend (an admin): parameter 'owner' of
 *                         USER_REGISTER / USER_INPUT, otherwise user.default_owner_id
 *                         (ownerId()); also becomes created_by
 *     record.needs_review 1 for everything a user creates or changes - the
 *                         review list of the module User
 *     user_session        frontend session (Camindo\Core\UserSession), cookie
 *                         'usess' or parameter 'cookiename'
 *     user_token          one-time tokens for activation and password reset
 *     user_basket         shopping basket per session
 *     user_login_attempt  brute-force protection (LoginGuard::forUsers())
 *
 * WHO MAY WRITE WHAT (USER_INPUT / USER_DELETE)
 * --------------------
 *     area 'ugc'          signed in, the user's profile area is in
 *                         details.profile_area_ids; change/delete only one's own
 *                         records (user_id)
 *     area 'profile'      only the own profile (id = signed-in user), no
 *                         creation - that is what USER_REGISTER is for
 *     area 'record'       only with ignoreuser=true
 *     ignoreuser=true     lifts ALL checks - the template then bears the
 *                         responsibility (e.g. a contact form without sign-in)
 *
 * WHAT THE COMMANDS LEAVE BEHIND
 * --------------------
 * The results are in $set (CMS:TEXT useval="{$set['user_id']}") - documented per
 * command. After a successful USER_LOGIN / USER_REGISTER /
 * USER_REGISTER_CONFIRM / USER_PW the profile record is additionally the
 * current record ($cms), after USER_INPUT the saved record - the
 * success template can output it with CMS:TEXT & Co. Errors end up in
 * Cms::error() and can be shown in the form template with CMS:MESSAGE.
 *
 * PROTECTION (developer docs, chapter 14 (Security: web))
 * --------------------
 *     throttling        USER_PW, USER_REGISTER, failed sign-ins per origin
 *                       (RateLimiter, user.throttle); message 'throttled'
 *     form protection   formguard=true (FormGuard: timestamp, decoy field)
 *     second factor     area.details.second_factor (UserSecondFactor):
 *                       USER_LOGIN codetemplate=… -> USER_LOGIN_CODE; management
 *                       by the user with USER_2FA
 *
 * The commands with php_suffix 'include_and_stop' (USER_DELETE, USER_INPUT, USER_LOGIN,
 * USER_LOGIN_CODE, USER_PROTECT, USER_PW, USER_REGISTER, USER_REGISTER_CONFIRM) return a
 * template name: that of the success or error template. The compiler includes
 * it and then ends the page - unless Cms::$returnAfterSuccess is set
 * (command CMS:CONTINUE). An empty return value lets the template continue.
 *
 * FORM DATA
 * --------------------
 * The parameter 'data' (default "data") names the request array in
 * which the inputs are: the form fields are called data[fieldname], uploads
 * data[fieldname] in $_FILES - the same form that the backend input mask
 * also sends, so that Record::save() understands uploads and multilingual fields
 * (data[fieldname][language_id]) without detour.
 *
 * LOADED LATER INSTEAD OF THE CONSTRUCTOR
 * --------------------
 * The class is not built with every request, but via LazyServicesTrait
 * only when a template actually contains a (CMS:USER_...) command -
 * see $lazyServices in Camindo\Core\Page.
 *
 * CALL CONVENTION
 * --------------------
 * All cms* methods are called exclusively from compiled templates and
 * receive exactly one parameter: the array $_a with the command parameters
 * given in the template. Cf. the cms* methods in Camindo\Core\Cms.
 *
 * @see Camindo\Core\Modules\Record\Record         read and write records
 * @see Camindo\Core\UserSession                    frontend session
 * @see Camindo\Core\MediaDelivery                  protected files (user_auth)
 * @see Camindo\Core\Modules\User\UserBackend      backend user interface for it
 * @see Camindo\Core\Modules\Template\commands.php definition of the CMS:USER_* commands
 * @see Camindo\Core\LazyServicesTrait             loading later
 * @see developer docs, chapter 06 (Template compiler)
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\ClientIp;
use Camindo\Core\Database;
use Camindo\Core\ContainerInterface;
use Camindo\Core\FormGuard;
use Camindo\Core\RateLimiter;
use Camindo\Core\LoginDevices;
use Camindo\Core\PasswordPolicy;
use Camindo\Core\LoginGuard;
use Camindo\Core\System;
use Camindo\Core\UserSession;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Privacy\PrivacyJobs;

class RecordUser {

    /** Column width of `user_login_attempt`.`name` (see LoginGuard) */
    private const NAME_MAX = 64;

    /** Wrong codes per pending sign-in (USER_LOGIN_CODE), then back to the password */
    private const MAX_CODE_ATTEMPTS = 3;

    /** Has USER_LOGIN_CODE already evaluated submit/resend in this request? */
    private bool $loginCodeHandled = false;

    /** Areas per request: area_id => row from `area` (details already decoded) */
    private array $areaCache = [];

    /** Did the last saveRecord() set a password? */
    private bool $passwordChanged = false;

    public function __construct(
        private Config $config,
        private Database $db,
        private Crypt $crypt,
        private Cms $cms,
        private System $system,
        private UserSession $userSession,
        private LoginGuard $loginGuard,
        private Record $record,
        private LoginDevices $loginDevices,
        private ContainerInterface $container,
    )
    {
    }

    // ====================
    // CMS:USER_AUTH / CMS:USER_PROTECT
    // ====================

    /**
     * CMS:USER_AUTH - checks the session for validity.
     *
     * Takes the session from 'sessionid' or the cookie 'cookiename' and checks
     * the profile record with 'filter' (syntax as CMS:SELECT). Result in $set:
     *
     *     $set['user_id']          record_id of the profile, 0 = not signed in
     *     $set['user']             the profile record (array) or []
     *     $set['user_session_id']  session id, '' = none
     *
     * Outputs nothing. For access protection with an error template see USER_PROTECT.
     */
    public function cmsAuth($p): void
    {
        $this->authenticate($p);
    }

    /**
     * CMS:USER_PROTECT - protects a template from access by visitors who are not signed in.
     *
     * Like USER_AUTH; if nobody is signed in (or the filter does not match), the
     * 'errortemplate' is included and the page ended. Otherwise the template
     * continues, $set is filled as with USER_AUTH.
     */
    public function cmsProtect($p): string
    {
        if ($this->authenticate($p)) return '';
        return $this->_template((string)($p['errortemplate'] ?? ''));
    }

    /**
     * Shared runtime of USER_AUTH and USER_PROTECT.
     *
     * @return bool  true if a user is signed in and the filter matches
     */
    private function authenticate(array $p): bool
    {
        $this->setUserVars(0, []);

        $sessionId  = trim((string)($p['sessionid'] ?? ''));
        $cookieName = (string)($p['cookiename'] ?? '');
        if (!$this->userSession->load($sessionId, $cookieName) || !$this->userSession->isLoggedIn()) {
            return false;
        }
        $profile = $this->loadProfile($this->userSession->recordId(), (array)($p['filter'] ?? []), (array)($p['mode'] ?? []));
        if ($profile === null) return false;

        $this->setUserVars((int)$profile['record_id'], $profile);
        return true;
    }

    // ====================
    // CMS:USER_LOGIN / CMS:USER_LOGOUT
    // ====================

    /**
     * CMS:USER_LOGIN - checks the sign-in data and creates a new session.
     *
     * Two ways:
     *   1. Name + password from data[{namefield}] / data[{passwordfield}] - the two
     *      fields are defined by the profile area (details.login_*_field_id). Only with
     *      'submit'.
     *   2. 'hash': a password reset token from USER_PW - signs the user in without
     *      password (once), so that afterwards they can set a new one via
     *      USER_INPUT. 'submit' is then not needed.
     *
     * The brute-force protection counts failed attempts per name (LoginGuard::forUsers()).
     * A profile with status_id 2 (unconfirmed) or 1 (disabled) is rejected.
     *
     * Messages ('message'): blocked, failed, disabled, unconfirmed, hash, container.
     * Result in $set as with USER_AUTH; the profile record becomes the
     * current record of the success template.
     *
     * @return string  successtemplate or '' (error in Cms::error())
     */
    public function cmsLogin($p): string
    {
        $this->setUserVars(0, []);
        $hash = trim((string)($p['hash'] ?? ''));
        if ($hash === '' && !$this->submitted($p)) return '';

        $area = $this->loadArea($p['container'] ?? '', ['profile']);
        if ($area === null) {
            $this->fail($p, 'container', 'user.error_container');
            return '';
        }
        $areaId = (int)$area['area_id'];
        $cookie = $this->_bool($p['cookie'] ?? true);
        $cookieName = (string)($p['cookiename'] ?? '');

        // 2. sign-in with password reset token
        // --------------------
        if ($hash !== '') {
            $recordId = $this->consumeToken($hash, 'password');
            $profile = $recordId > 0 ? $this->loadProfile($recordId) : null;
            if ($profile === null || (int)$profile['area_id'] !== $areaId) {
                $this->fail($p, 'hash', 'user.error_hash_invalid');
                return '';
            }
            if ((int)$profile['status_id'] !== 0) {
                $this->fail($p, (int)$profile['status_id'] === 2 ? 'unconfirmed' : 'disabled',
                    (int)$profile['status_id'] === 2 ? 'user.error_unconfirmed' : 'user.error_disabled');
                return '';
            }
            // With an authenticator app: the reset link also needs the code - otherwise
            // anyone with access to the mailbox would take over the account. A code by
            // e-mail is dropped: the link came through the same mailbox.
            if ($this->secondFactor()->policy($area) !== 'none' && $this->secondFactor()->method((int)$profile['record_id']) === 'totp') {
                return $this->startSecondFactor($profile, $area, 'totp', '', $cookie, $cookieName, $p, true);
            }
            return $this->loginProfile($profile, $cookie, $cookieName, $p, true);
        }

        // 1. name + password
        // --------------------
        $nameField = $this->fieldName((int)($area['login_name_field_id'] ?? 0), $areaId);
        $pwField   = $this->fieldName((int)($area['login_password_field_id'] ?? 0), $areaId);
        if ($nameField === '' || $pwField === '') {
            $this->fail($p, 'container', 'user.error_login_fields');
            return '';
        }
        $input = $this->requestData($p);
        $name = (string)($input[$nameField] ?? '');
        $password = (string)($input[$pwField] ?? '');
        if ($this->_bool($p['trim'] ?? false)) {
            $name = trim($name);
            $password = trim($password);
        }
        if ($name === '' || $password === '') {
            $this->fail($p, 'failed', 'user.error_login_failed');
            return '';
        }

        $guard = $this->loginGuard->forUsers();
        $guardName = mb_substr($areaId . ':' . $name, 0, self::NAME_MAX);
        if ($blockedSeconds = $guard->blockedFor($guardName)) {
            $this->fail($p, 'blocked', 'user.error_login_blocked', ['minutes' => (int)ceil($blockedSeconds / 60)]);
            return '';
        }
        // failed attempts per origin across all names (credential stuffing: many
        // accounts, each with one password from a foreign leak)
        $limiter = $this->container->get(RateLimiter::class);
        $origin = $this->container->get(ClientIp::class)->rateKey();
        if (!$limiter->allows('login_ip', $origin)) {
            $this->fail($p, 'blocked', 'user.error_login_blocked', ['minutes' => (int)ceil($limiter->limits('login_ip')[1] / 60)]);
            return '';
        }

        // profile for the name - exactly one
        $profile = $this->findProfile($areaId, $nameField, $name);
        // check even without a hit: an invented name should not respond faster
        // than an existing one (see Admin::loginByName())
        $profileOk = $profile !== null && $this->passwordOk((int)$profile['record_id'], $pwField, $password);
        if ($profile === null) $this->crypt->passwordVerify($password, '');
        if (!$profileOk) {
            $guard->recordFailure($guardName);
            $limiter->record('login_ip', $origin);
            $this->fail($p, 'failed', 'user.error_login_failed');
            return '';
        }
        if ((int)$profile['status_id'] === 2) {
            $guard->clear($guardName);
            $this->fail($p, 'unconfirmed', 'user.error_unconfirmed');
            return '';
        }
        if ((int)$profile['status_id'] !== 0) {
            $guard->clear($guardName);
            $this->fail($p, 'disabled', 'user.error_disabled');
            return '';
        }
        // second factor (area.details.second_factor, UserSecondFactor::needed()):
        // the LoginGuard counter stays until the code is right too - otherwise
        // password + code could be guessed alternately any number of times
        $method = $this->secondFactor()->needed($profile, $area);
        if ($method !== '') {
            return $this->startSecondFactor($profile, $area, $method, $guardName, $cookie, $cookieName, $p);
        }
        $guard->clear($guardName);
        return $this->loginProfile($profile, $cookie, $cookieName, $p);
    }

    /**
     * Password (or reset link) is correct, a second factor is still missing: an
     * ANONYMOUS session (new id, a basket moves along) records the
     * pending sign-in in details.mfa_pending, the 'codetemplate' is included.
     * Nobody is signed in afterwards - CMS:USER_LOGIN_CODE handles that with the right
     * code.
     *
     * For a code by e-mail the code is created here and stands in $set['login_code'];
     * the codetemplate sends it (CMS:EMAIL to the address of the profile, which
     * is the current record). The command itself sends nothing - like all
     * CMS:USER_* commands.
     *
     * $set: second_factor ('totp' | 'email'), login_pending = 1, pending_user
     * (profile), login_code, user_session_id (pending session, for calls without
     * cookie).
     */
    private function startSecondFactor(array $profile, array $area, string $method, string $guardName, bool $cookie, string $cookieName, array $p, bool $reset = false): string
    {
        $template = $this->_template((string)($p['codetemplate'] ?? ''));
        if ($template === '') {
            $this->system->log('user', 'CMS:USER_LOGIN: profile area ' . (int)$area['area_id'] . ' requires a second factor, but codetemplate is missing');
            $this->fail($p, 'failed', 'user.error_login_failed');
            return '';
        }
        $recordId = (int)$profile['record_id'];
        $pending = [
            'rid' => $recordId,
            'aid' => (int)$area['area_id'],
            'm'   => $method,
            'g'   => $guardName !== '' ? $guardName : mb_substr((int)$area['area_id'] . ':#' . $recordId, 0, self::NAME_MAX),
            'exp' => gmdate('Y-m-d H:i:s', time() + $this->loginCodeExpiration()),
            'n'   => 0,
            'rs'  => $reset ? 1 : 0,
            'ck'  => $cookie ? 1 : 0,
        ];
        $code = '';
        if ($method === 'email') {
            [$code, $pending['ch']] = $this->secondFactor()->newCode();
        }
        $sessionId = $this->userSession->create(0, (int)$area['area_id'],
            (int)($this->cms->instance['instance_id'] ?? $profile['instance_id'] ?? 0),
            ['mfa_pending' => $pending, 'password_reset_until' => null],
            $cookie, $cookieName
        );
        $this->setUserVars(0, []);
        $GLOBALS['set']['user_session_id'] = $sessionId;
        $GLOBALS['set']['second_factor'] = $method;
        $GLOBALS['set']['login_pending'] = 1;
        $GLOBALS['set']['pending_user'] = $profile;
        $GLOBALS['set']['login_code'] = $code;
        $GLOBALS['cms'] = $profile;
        return $template;
    }

    /** user.login_code_expiration, at least 60 seconds */
    private function loginCodeExpiration(): int
    {
        return max(60, (int)($this->config->get('user.login_code_expiration') ?: 600));
    }

    /**
     * CMS:USER_LOGIN_CODE - second step of the sign-in: code from the app, by
     * e-mail/SMS or a recovery code.
     *
     * Reads the pending sign-in from the session (details.mfa_pending, see
     * startSecondFactor()). With 'submit': check the code from data[{codefield}] (default
     * data[code]). Correct -> sign-in as with USER_LOGIN (new session id,
     * register the device) and 'successtemplate'. Wrong -> message 'code'; each
     * failed attempt counts in the LoginGuard against the same account as the password; after
     * MAX_CODE_ATTEMPTS wrong codes the pending sign-in is discarded
     * (message 'expired', back to the password).
     *
     * 'resend' (only for the code by e-mail): new code, throttled via
     * user.throttle.login_code. As the first time it stands in $set['login_code'],
     * 'codetemplate' is included - the same template that sends the code
     * with CMS:EMAIL after USER_LOGIN ($set['code_resent'] = 1).
     *
     * Without submit and resend: only fill $set (second_factor, login_pending) - so
     * the form template can also be included from the codetemplate. Per
     * request submit/resend is evaluated only on the first call; a further call
     * (the included form sees the same POST) only fills $set.
     *
     * Messages: token, code, expired, blocked, throttled.
     * $set after success as USER_LOGIN, plus second_factor_used ('totp' | 'email' |
     * 'recovery') and recovery_remaining.
     *
     * @return string  successtemplate or ''
     */
    public function cmsLoginCode($p): string
    {
        $this->setUserVars(0, []);
        $GLOBALS['set']['login_pending'] = 0;
        $firstCall = !$this->loginCodeHandled;
        $this->loginCodeHandled = true;
        $sessionId = trim((string)($p['sessionid'] ?? ''));
        $cookieName = (string)($p['cookiename'] ?? '');
        $pending = null;
        if ($this->userSession->load($sessionId, $cookieName) && !$this->userSession->isLoggedIn()) {
            $pending = $this->userSession->get('mfa_pending');
        }
        $pending = is_array($pending) && (string)($pending['exp'] ?? '') >= gmdate('Y-m-d H:i:s') ? $pending : null;
        $resend = $firstCall && $this->_bool($p['resend'] ?? false);
        $submit = $firstCall && trim((string)($p['submit'] ?? '')) !== '';
        if ($pending === null) {
            if ($submit || $resend) $this->fail($p, 'expired', 'user.error_code_expired');
            return '';
        }
        $area = $this->areaById((int)$pending['aid']);
        $profile = $this->loadProfile((int)$pending['rid']);
        if ($area === null || $profile === null || (int)$profile['status_id'] !== 0) {
            $this->userSession->setDetail('mfa_pending', null);
            if ($submit || $resend) $this->fail($p, 'expired', 'user.error_code_expired');
            return '';
        }
        $GLOBALS['set']['second_factor'] = (string)$pending['m'];
        $GLOBALS['set']['login_pending'] = 1;
        $GLOBALS['set']['pending_user'] = $profile;
        $GLOBALS['set']['user_session_id'] = $this->userSession->id();
        if (!$submit && !$resend) return '';
        if (!$this->csrfOk($p)) return '';

        // new code
        // --------------------
        if ($resend && !$submit) {
            if ((string)$pending['m'] !== 'email') return '';
            $template = $this->_template((string)($p['codetemplate'] ?? ''));
            if ($template === '') {
                $this->system->log('user', 'CMS:USER_LOGIN_CODE: resend without codetemplate');
                return '';
            }
            if (!$this->throttleHit([['login_code', (string)(int)$pending['rid']]])) {
                $GLOBALS['set']['throttled'] = 1;
                $this->fail($p, 'throttled', 'user.error_throttled');
                return '';
            }
            [$code, $pending['ch']] = $this->secondFactor()->newCode();
            $pending['n'] = 0;
            $pending['exp'] = gmdate('Y-m-d H:i:s', time() + $this->loginCodeExpiration());
            $this->userSession->setDetail('mfa_pending', $pending);
            $GLOBALS['set']['login_code'] = $code;
            $GLOBALS['set']['code_resent'] = 1;
            $GLOBALS['cms'] = $profile;
            return $template;
        }

        // check code
        // --------------------
        $guard = $this->loginGuard->forUsers();
        $guardName = (string)$pending['g'];
        if ($blockedSeconds = $guard->blockedFor($guardName)) {
            $this->fail($p, 'blocked', 'user.error_login_blocked', ['minutes' => (int)ceil($blockedSeconds / 60)]);
            return '';
        }
        $input = $this->requestData($p);
        $codeField = $this->_name((string)($p['codefield'] ?? '')) ?: 'code';
        $code = trim((string)($input[$codeField] ?? ''));
        // No code in the request (e.g. the sign-in form that redirects here):
        // no failed attempt, no message
        if ($code === '') return '';
        $used = '';
        if ((string)$pending['m'] === 'totp') {
            $used = $this->secondFactor()->verifyTotpOrRecovery($profile, $code);
        }
        elseif ($this->secondFactor()->codeMatches((string)($pending['ch'] ?? ''), $code)) {
            $used = 'email';
        }
        if ($used === '') {
            $guard->recordFailure($guardName);
            $pending['n'] = (int)$pending['n'] + 1;
            if ($pending['n'] >= self::MAX_CODE_ATTEMPTS) {
                $this->userSession->setDetail('mfa_pending', null);
                $GLOBALS['set']['login_pending'] = 0;
                $this->fail($p, 'expired', 'user.error_code_expired');
            }
            else {
                $this->userSession->setDetail('mfa_pending', $pending);
                $this->fail($p, 'code', 'user.error_code_invalid');
            }
            return '';
        }
        $guard->clear($guardName);
        $this->userSession->setDetail('mfa_pending', null);
        $GLOBALS['set']['login_pending'] = 0;
        $result = $this->loginProfile($profile, (bool)($pending['ck'] ?? true), $cookieName, $p, !empty($pending['rs']));
        $GLOBALS['set']['second_factor_used'] = $used;
        $GLOBALS['set']['recovery_remaining'] = $used === 'recovery' ? $this->secondFactor()->recoveryCount((int)$profile['record_id']) : null;
        return $result;
    }

    /**
     * CMS:USER_2FA - manage the second factor of the signed-in user
     * (developer docs, chapter 13 (Security: authentication)). The profile area must
     * have second_factor ≠ 'none'.
     *
     *     function=status        (default) read only
     *     function=totp_setup    create a new key, store nothing:
     *                            $set['totp_setup'] = token, secret (to type in),
     *                            qr (HTML: QR code for the app, withQrCode()),
     *                            uri (otpauth://, on the phone the app opens)
     *     function=totp_enable   data[token] + data[code] from the app -> save,
     *                            $set['recovery_codes'] (only visible now)
     *     function=code_enable   choose the code by e-mail/SMS as factor, confirmed
     *                            with the password (data[current_password])
     *     function=disable       switch off - with TOTP via app or
     *                            recovery code (data[code]), with the code via
     *                            the password. With second_factor 'required' TOTP becomes
     *                            the code; switching off the code is then not possible
     *     function=recovery_renew  new recovery codes, with app code
     *
     * Writing functions only with 'submit' (POST) and CSRF token.
     * $set after each function: second_factor ('' | 'totp' | 'email'),
     * second_factor_policy, second_factor_since (UTC), recovery_count; after a
     * change second_factor_changed = event (totp_enabled, totp_disabled,
     * code_enabled, code_disabled, recovery_renewed).
     *
     * Messages: auth, policy, token, code, current, setup, blocked.
     */
    public function cmsTwoFactor($p): void
    {
        $GLOBALS['set']['second_factor_changed'] = '';
        $GLOBALS['set']['recovery_codes'] = [];
        if (!$this->authenticate($p)) {
            $this->fail($p, 'auth', 'user.error_access_denied');
            return;
        }
        $profile = (array)$GLOBALS['set']['user'];
        $recordId = (int)$profile['record_id'];
        $area = $this->areaById((int)$profile['area_id']);
        $sf = $this->secondFactor();
        $policy = $area !== null ? $sf->policy($area) : 'none';
        $this->twoFactorVars($recordId, $policy);
        if ($policy === 'none') {
            $this->fail($p, 'policy', 'user.error_2fa_policy');
            return;
        }
        $function = strtolower(trim((string)($p['function'] ?? 'status')));
        $account = (string)($profile[$this->fieldName((int)($area['login_name_field_id'] ?? 0), (int)$area['area_id'])] ?? '');
        $issuer = (string)($this->cms->instance['primary_domain'] ?? '');
        $input = $this->requestData($p);

        if ($function === 'totp_setup') {
            $GLOBALS['set']['totp_setup'] = $this->withQrCode($sf->newSetup($profile, $account, $issuer));
            return;
        }
        if ($function === 'status' || !in_array($function, ['totp_enable', 'code_enable', 'disable', 'recovery_renew'], true)) return;
        if (!$this->submitted($p)) {
            // form not yet submitted: when setting up, show the key again
            if ($function === 'totp_enable' && ($setup = $sf->setupFromToken($profile, (string)($input['token'] ?? ''), $account, $issuer))) {
                $GLOBALS['set']['totp_setup'] = $this->withQrCode($setup);
            }
            return;
        }
        $method = $sf->method($recordId);
        $code = (string)($input[$this->_name((string)($p['codefield'] ?? '')) ?: 'code'] ?? '');

        switch ($function) {
            case 'totp_enable':
                $token = (string)($input['token'] ?? '');
                $codes = $sf->enableTotp($profile, $token, $code);
                if ($codes === null) {
                    $setup = $sf->setupFromToken($profile, $token, $account, $issuer);
                    if ($setup === null) {
                        $this->fail($p, 'setup', 'user.error_2fa_setup');
                    }
                    else {
                        $GLOBALS['set']['totp_setup'] = $this->withQrCode($setup);
                        $this->codeFailure($area, $recordId, $p);
                    }
                    break;
                }
                $GLOBALS['set']['recovery_codes'] = $codes;
                $GLOBALS['set']['second_factor_changed'] = 'totp_enabled';
                break;

            case 'code_enable':
                if ($method === 'totp') {
                    // switch from the app to the code: like switching off with the app code
                    if (!$this->totpConfirmed($profile, $area, $code, $p)) break;
                }
                elseif (!$this->currentPasswordOk($area, $recordId, $input, $p, false)) {
                    break;
                }
                $sf->enableCode($profile);
                $GLOBALS['set']['second_factor_changed'] = 'code_enabled';
                break;

            case 'disable':
                if ($method === '') break;
                if ($method === 'totp') {
                    if (!$this->totpConfirmed($profile, $area, $code, $p)) break;
                    if ($policy === 'required') {
                        $sf->enableCode($profile);
                        $GLOBALS['set']['second_factor_changed'] = 'totp_disabled';
                        break;
                    }
                }
                else {
                    if ($policy === 'required') {
                        $this->fail($p, 'policy', 'user.error_2fa_policy');
                        break;
                    }
                    if (!$this->currentPasswordOk($area, $recordId, $input, $p, false)) break;
                }
                $sf->disable($profile);
                $GLOBALS['set']['second_factor_changed'] = $method === 'totp' ? 'totp_disabled' : 'code_disabled';
                break;

            case 'recovery_renew':
                if ($method !== 'totp') break;
                if (!$this->totpConfirmed($profile, $area, $code, $p, false)) break;
                $GLOBALS['set']['recovery_codes'] = $sf->regenerateRecoveryCodes($profile);
                $GLOBALS['set']['second_factor_changed'] = 'recovery_renewed';
                break;
        }
        $this->twoFactorVars($recordId, $policy);
    }

    /**
     * QR code for setting up the authenticator app: $setup['qr'] is finished HTML
     * - an element with data-c9-qr (the otpauth:// URI), the library
     * qrcode-generator (kazuhikoarase, MIT) from the backend assets and a small
     * script that turns it into an SVG; both scripts with the nonce of the page, so that
     * they also run under an enforced script policy ('self'). The same
     * library and the same attribute as in the backend (cApp.ui.qrCodes()).
     * $setup['qr_script'] = URL of the library for own inclusions.
     */
    private function withQrCode(array $setup): array
    {
        $script = rtrim((string)$this->config->get('paths.backend'), '/') . '/assets/qrcode/qrcode.min.js';
        $nonce = htmlspecialchars($this->cms->cspNonce(), ENT_QUOTES);
        $setup['qr_script'] = $script;
        $setup['qr'] = '<div class="c9-qr" data-c9-qr="' . htmlspecialchars((string)$setup['uri'], ENT_QUOTES) . '"></div>'
            . '<script src="' . htmlspecialchars($script, ENT_QUOTES) . '" nonce="' . $nonce . '"></script>'
            . '<script nonce="' . $nonce . '">document.querySelectorAll("[data-c9-qr]:not([data-c9-qr-ready])").forEach(function (el) {'
            . 'if (!window.qrcode) return; var qr = window.qrcode(0, "M"); qr.addData(el.getAttribute("data-c9-qr")); qr.make();'
            . 'el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true }); el.setAttribute("data-c9-qr-ready", "1"); });</script>';
        return $setup;
    }

    /** $set values for the state (USER_2FA) */
    private function twoFactorVars(int $recordId, string $policy): void
    {
        $state = $this->secondFactor()->state($recordId);
        $GLOBALS['set']['second_factor'] = $policy === 'none' ? '' : $state['method'];
        $GLOBALS['set']['second_factor_policy'] = $policy;
        $GLOBALS['set']['second_factor_since'] = $state['enabled_at'];
        $GLOBALS['set']['recovery_count'] = $state['recovery_left'];
    }

    /**
     * App code or (with $allowRecovery) recovery code confirms a change;
     * failed attempts count in the LoginGuard like a wrong password.
     */
    private function totpConfirmed(array $profile, array $area, string $code, array $p, bool $allowRecovery = true): bool
    {
        $recordId = (int)$profile['record_id'];
        $guard = $this->loginGuard->forUsers();
        $guardName = mb_substr((int)$area['area_id'] . ':#' . $recordId, 0, self::NAME_MAX);
        if ($blockedSeconds = $guard->blockedFor($guardName)) {
            $this->fail($p, 'blocked', 'user.error_login_blocked', ['minutes' => (int)ceil($blockedSeconds / 60)]);
            return false;
        }
        $ok = $allowRecovery
            ? $this->secondFactor()->verifyTotpOrRecovery($profile, $code) !== ''
            : $this->secondFactor()->verifyTotp($recordId, $code);
        if (!$ok) {
            $guard->recordFailure($guardName);
            $this->fail($p, 'code', 'user.error_code_invalid');
            return false;
        }
        $guard->clear($guardName);
        return true;
    }

    /** Wrong app code when setting up: message and failed attempt */
    private function codeFailure(array $area, int $recordId, array $p): void
    {
        $this->loginGuard->forUsers()->recordFailure(mb_substr((int)$area['area_id'] . ':#' . $recordId, 0, self::NAME_MAX));
        $this->fail($p, 'code', 'user.error_code_invalid');
    }

    private function secondFactor(): UserSecondFactor
    {
        return $this->container->get(UserSecondFactor::class);
    }

    /**
     * Create a session for a verified profile, record the device, fill $set and $cms.
     *
     * $reset (sign-in via the reset link): the session may set a new password
     * without the old one until details.password_reset_until
     * (USER_INPUT, currentPasswordOk()); $set['password_reset'] = 1. A normal
     * sign-in deletes such a release (otherwise create() takes over the details
     * of a running session of the same profile).
     *
     * $set['new_device'] = 1 if the profile signs in from an unknown device
     * and already has other devices (LoginDevices) - the success template can
     * then send a notification e-mail; $set['device'] = label, ip, time (UTC).
     */
    private function loginProfile(array $profile, bool $cookie, string $cookieName, array $p, bool $reset = false): string
    {
        $recordId = (int)$profile['record_id'];
        $sessionId = $this->userSession->create(
            $recordId,
            (int)$profile['area_id'],
            (int)($this->cms->instance['instance_id'] ?? $profile['instance_id'] ?? 0),
            [
                'login_at' => gmdate('Y-m-d H:i:s'),
                'password_reset_until' => $reset ? gmdate('Y-m-d H:i:s', time() + $this->passwordTokenExpiration()) : null,
                'mfa_pending' => null,
            ],
            $cookie,
            $cookieName
        );
        $this->setUserVars($recordId, $profile, $sessionId);
        $device = $cookie
            ? $this->loginDevices->register('user', $recordId, $profile)
            : ['new' => false, 'label' => '', 'ip' => '', 'time' => gmdate('Y-m-d H:i:s')];
        $GLOBALS['set']['new_device'] = $device['new'] ? 1 : 0;
        $GLOBALS['set']['device'] = ['label' => $device['label'], 'ip' => $device['ip'], 'time' => $device['time']];
        $GLOBALS['cms'] = $profile;
        return $this->_template((string)($p['successtemplate'] ?? ''));
    }

    /**
     * CMS:USER_LOGOUT - ends and deletes the session of a signed-in user.
     * 'deletecookie' = false leaves the cookie in place (end only server-side).
     * 'submit' is optional: given but empty (page simply called) -
     * do nothing, also no message 'token'; without the parameter always sign out.
     */
    public function cmsLogout($p): void
    {
        if (array_key_exists('submit', $p) && trim((string)$p['submit']) === '') return;
        if (!$this->csrfOk($p)) return;
        $cookieName = (string)($p['cookiename'] ?? '');
        $this->userSession->load('', $cookieName);
        $this->userSession->close($this->_bool($p['deletecookie'] ?? true), $cookieName);
        $this->setUserVars(0, []);
    }

    // ====================
    // CMS:USER_REGISTER / CMS:USER_REGISTER_CONFIRM / CMS:USER_PW
    // ====================

    /**
     * CMS:USER_REGISTER - creates a user profile from the inputs, status = 2 (unconfirmed).
     *
     * Writes a record into the profile area 'container' (fields from
     * data[...], restricted by 'fields', overwritten by 'overwrite'),
     * with status_id 2 and needs_review 1. 'emailfield' must contain a valid
     * e-mail address; sign-in name and e-mail must not yet occur in the area.
     * All field checks of the area apply as in the backend.
     *
     * An activation token (table user_token, purpose 'register') ends up in
     * $set['hash'] - the success template builds from it the link to a page with
     * CMS:USER_REGISTER_CONFIRM and sends it (sending the e-mail is up to the
     * template). Additionally $set['user_id'], $set['user'] (the profile), $set['email'];
     * the profile is the current record of the success template.
     *
     * Messages ('message'): container, email, exists, save (plus field errors of the
     * validation).
     *
     * @return string  successtemplate or ''
     */
    public function cmsRegister($p): string
    {
        $this->setUserVars(0, []);
        if (!$this->submitted($p)) return '';

        $area = $this->loadArea($p['container'] ?? '', ['profile']);
        if ($area === null) {
            $this->fail($p, 'container', 'user.error_container');
            return '';
        }
        $areaId = (int)$area['area_id'];
        $emailField = $this->_name((string)($p['emailfield'] ?? ''));
        $input = $this->requestData($p);

        // e-mail address
        $email = trim((string)($input[$emailField] ?? ''));
        if ($emailField === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail($p, 'email', 'user.error_email_invalid');
            return '';
        }
        $input[$emailField] = $email;

        // throttling and form protection: checked in advance, only what triggers an
        // e-mail or could reveal an account is counted (success and 'exists') - a
        // typo in the password policy should not lock anyone out
        $buckets = [
            ['register_ip', $this->container->get(ClientIp::class)->rateKey()],
            ['register_value', $areaId . '|' . mb_strtolower($email)],
            ['mail_instance', (string)(int)$area['instance_id']],
        ];
        if (!$this->humanOk($p) || !$this->throttleAllows($buckets)) {
            $GLOBALS['set']['throttled'] = 1;
            $this->fail($p, 'throttled', 'user.error_throttled');
            return '';
        }

        // sign-in name and e-mail must be unique in the area - regardless of
        // whether the editor has set the flag 'unique'
        $nameField = $this->fieldName((int)($area['login_name_field_id'] ?? 0), $areaId);
        foreach (array_unique(array_filter([$emailField, $nameField])) as $field) {
            $value = trim((string)($input[$field] ?? ''));
            if ($value === '') continue;
            if ($this->findProfile($areaId, $field, $value, true) !== null) {
                $this->throttleRecord($buckets);
                $this->fail($p, 'exists', 'user.error_exists');
                return '';
            }
        }

        $ownerId = $this->ownerId($p);
        if ($ownerId < 1) return '';

        $overwrite = (array)($p['overwrite'] ?? []);
        $overwrite += ['status_id' => 2, 'needs_review' => 1];
        $recordId = $this->saveRecord($areaId, 0, $input, $this->_list($p['fields'] ?? []), $overwrite, [], 0, $p, 0, $ownerId);
        if ($recordId < 1) return '';
        $this->throttleRecord($buckets);

        $profile = $this->loadProfile($recordId) ?? [];
        $hash = $this->createToken($recordId, 'register');
        $this->setUserVars($recordId, $profile);
        $GLOBALS['set']['hash']  = $hash;
        $GLOBALS['set']['email'] = $email;
        $GLOBALS['cms'] = $profile;
        return $this->_template((string)($p['successtemplate'] ?? ''));
    }

    /**
     * CMS:USER_REGISTER_CONFIRM - enables a user profile, status = 0.
     *
     * 'hash' is the token from USER_REGISTER. An unknown or expired
     * token (user.token_expiration) gives the message 'hash'. The token is
     * consumed; the user is then NOT signed in (that is USER_LOGIN), but
     * $set['user_id'] / $set['user'] are filled and the profile is the current
     * record of the success template.
     *
     * @return string  successtemplate or ''
     */
    public function cmsRegisterConfirm($p): string
    {
        $this->setUserVars(0, []);
        $hash = trim((string)($p['hash'] ?? ''));
        $recordId = $hash !== '' ? $this->consumeToken($hash, 'register') : 0;
        $profile = $recordId > 0 ? $this->loadProfile($recordId) : null;
        if ($profile === null) {
            $this->fail($p, 'hash', 'user.error_hash_invalid');
            return '';
        }
        if ((int)$profile['status_id'] === 2) {
            $this->db->query(
                "UPDATE `record` SET `status_id` = 0, `changed_at` = UTC_TIMESTAMP() WHERE `record_id` = ?",
                'i',
                [$recordId]
            );
            $profile['status_id'] = 0;
        }
        $this->setUserVars($recordId, $profile);
        $GLOBALS['cms'] = $profile;
        return $this->_template((string)($p['successtemplate'] ?? ''));
    }

    /**
     * CMS:USER_PW - prepares the reset of a password.
     *
     * Searches in the profile area 'container' for the profile whose field 'uniquefield' carries
     * the value 'uniquevalue' (e.g. the e-mail address). Found: a token
     * (purpose 'password') ends up in $set['hash']; the success template sends
     * from it the link to a page with (CMS:USER_LOGIN hash=…). Not found:
     * message 'notfound' and the 'errortemplate'.
     *
     * Without 'uniquevalue' (form not yet submitted) nothing happens.
     *
     * @return string  successtemplate, errortemplate or ''
     */
    public function cmsPw($p): string
    {
        $this->setUserVars(0, []);
        if (!$this->submitted($p)) return '';
        $value = trim((string)($p['uniquevalue'] ?? ''));
        if ($value === '') return '';

        $area = $this->loadArea($p['container'] ?? '', ['profile']);
        if ($area === null) {
            $this->fail($p, 'container', 'user.error_container');
            return '';
        }
        $field = $this->_name((string)($p['uniquefield'] ?? ''));

        // throttling and form protection BEFORE the search: the entered value is counted,
        // not the account found - otherwise only an existing address would at some point
        // be locked, and one could tell which addresses have an account. The answer is
        // the errortemplate, as with 'notfound'.
        $buckets = [
            ['pw_ip', $this->container->get(ClientIp::class)->rateKey()],
            ['pw_value', (int)$area['area_id'] . '|' . mb_strtolower($value)],
            ['mail_instance', (string)(int)$area['instance_id']],
        ];
        if (!$this->humanOk($p) || !$this->throttleHit($buckets)) {
            $GLOBALS['set']['throttled'] = 1;
            $this->fail($p, 'throttled', 'user.error_throttled');
            return $this->_template((string)($p['errortemplate'] ?? ''));
        }

        $profile = $field !== '' ? $this->findProfile((int)$area['area_id'], $field, $value) : null;
        if ($profile === null) {
            $this->fail($p, 'notfound', 'user.error_profile_not_found');
            return $this->_template((string)($p['errortemplate'] ?? ''));
        }
        $recordId = (int)$profile['record_id'];
        $this->setUserVars($recordId, $profile);
        $GLOBALS['set']['hash'] = $this->createToken($recordId, 'password');
        $GLOBALS['cms'] = $profile;
        return $this->_template((string)($p['successtemplate'] ?? ''));
    }

    // ====================
    // CMS:USER_INPUT / CMS:USER_DELETE
    // ====================

    /**
     * CMS:USER_INPUT - creates or updates a record from frontend inputs.
     *
     * Sequence:
     *   1. 'submit' must be filled, 'container' a record area of the instance
     *   2. authorization (see class comment "Who may write what")
     *   3. 'id': update an existing record - if it does not belong to the user
     *      or does not exist, a new one is created without 'forceupdate', with
     *      'forceupdate' aborted (message 'notfound')
     *   4. inputs from data[...]: only the fields from 'fields' (empty = all
     *      user-defined fields of the area); meta fields exclusively via
     *      'overwrite'. 'removefiles' empties upload fields. Uploads come from
     *      $_FILES[data].
     *   5. save with all checks of the area (field errors -> CMS:MESSAGE);
     *      new records get user_id = signed-in user and needs_review 1,
     *      changed ones needs_review 1
     *   6. success: $set['record_id'], the record is the current record of the
     *      'successtemplate' (without successtemplate the template continues)
     *
     * 'masterid' attaches the new record as a child under the given one
     * (parent_id) - only in areas with a tree (input field parent_id), otherwise without
     * effect.
     *
     * Messages ('message'): container, access, notfound, save.
     *
     * @return string  successtemplate or ''
     */
    public function cmsInput($p): string
    {
        if (!$this->submitted($p)) return '';

        $area = $this->loadArea($p['container'] ?? '', Area::CONTENT_TYPES);
        if ($area === null) {
            $this->fail($p, 'container', 'user.error_container');
            return '';
        }
        $areaId = (int)$area['area_id'];
        $type = (string)$area['type'];
        $ignoreUser = $this->_bool($p['ignoreuser'] ?? false);
        $id = (int)($p['id'] ?? 0);

        // Berechtigung
        // --------------------
        $userId = 0;
        if (!$ignoreUser) {
            if (!$this->userSession->load() || !$this->userSession->isLoggedIn()) {
                $this->fail($p, 'access', 'user.error_access_denied');
                return '';
            }
            $userId = $this->userSession->recordId();
            $allowed = match ($type) {
                'ugc'     => in_array($this->userSession->areaId(), array_map('intval', (array)($area['details']['profile_area_ids'] ?? [])), true),
                'profile' => ($id > 0 && $id === $userId && (int)$this->userSession->areaId() === $areaId),
                default   => false,
            };
            if (!$allowed) {
                $this->fail($p, 'access', 'user.error_access_denied');
                return '';
            }
        }

        // existing record?
        // --------------------
        $previous = null;
        if ($id > 0) {
            $previous = $this->loadOwned($id, $areaId, $userId, $ignoreUser);
            if ($previous === null) {
                if ($this->_bool($p['forceupdate'] ?? false) || $type === 'profile') {
                    $this->fail($p, 'notfound', 'user.error_record_not_found');
                    return '';
                }
                $id = 0; // neu anlegen
            }
        }

        // owner (backend admin) of a new record
        $ownerId = 0;
        if ($id < 1) {
            $ownerId = $this->ownerId($p);
            if ($ownerId < 1) return '';
        }

        // new password in one's own profile: only with the previous one or directly after
        // the reset link (currentPasswordOk())
        $input = $this->requestData($p);
        if ($type === 'profile' && !$ignoreUser
            && $this->passwordInput($areaId, $input, $this->_list($p['fields'] ?? []))
            && !$this->currentPasswordOk($area, $userId, $input, $p)
        ) {
            return '';
        }

        $overwrite = (array)($p['overwrite'] ?? []);
        $overwrite += ['needs_review' => 1];
        $recordId = $this->saveRecord(
            $areaId, $id, $input, $this->_list($p['fields'] ?? []), $overwrite,
            $this->_list($p['removefiles'] ?? ''), $userId, $p, (int)($p['masterid'] ?? 0), $ownerId
        );
        if ($recordId < 1) return '';

        // new password: end all other sessions of the profile - whoever knew the old one
        // (or hijacked a session) is shut out. The running session
        // stays if it belongs to this profile.
        $GLOBALS['set']['password_changed'] = 0;
        if ($type === 'profile' && $this->passwordChanged) {
            $keep = $this->userSession->recordId() === $recordId ? $this->userSession->id() : '';
            $this->userSession->closeAllOf($recordId, $keep);
            // reset release used up
            if ($keep !== '' && $this->userSession->get('password_reset_until')) {
                $this->userSession->setDetail('password_reset_until', null);
            }
            // note: the success template can send an e-mail, a custom
            // notifier (security.login_notifier) e.g. an SMS
            $GLOBALS['set']['password_changed'] = 1;
            $profile = $this->loadRecord($recordId) ?? [];
            $this->loginDevices->notify('password_changed', 'user', $profile, $this->loginDevices->context());
        }

        $GLOBALS['set']['record_id'] = $recordId;
        $saved = $this->loadRecord($recordId);
        if ($saved !== null) $GLOBALS['cms'] = $saved;
        return $this->_template((string)($p['successtemplate'] ?? ''));
    }

    /**
     * CMS:USER_DELETE - deletes a record that belongs to the signed-in user.
     *
     * Soft delete as in the backend (trash). A profile can delete itself
     * (id = signed-in user) - its sessions end with it. 'ignoreuser' lifts the
     * ownership check. If authorization is missing: 'message_error'.
     *
     * @return string  successtemplate or ''
     */
    public function cmsDelete($p): string
    {
        if (!$this->csrfOk($p)) return '';
        $id = (int)($p['id'] ?? 0);
        $ignoreUser = $this->_bool($p['ignoreuser'] ?? false);
        $userId = 0;
        if (!$ignoreUser) {
            if (!$this->userSession->load() || !$this->userSession->isLoggedIn()) {
                $this->failMessage((string)($p['message_error'] ?? ''), 'user.error_access_denied');
                return '';
            }
            $userId = $this->userSession->recordId();
        }
        $record = $id > 0 ? $this->loadOwned($id, 0, $userId, $ignoreUser) : null;
        if ($record === null) {
            $this->failMessage((string)($p['message_error'] ?? ''), 'user.error_access_denied');
            return '';
        }
        // what "delete" means is stated by the deletion policy of the area (module Privacy):
        // trash, overwrite fields, delete permanently …
        if ($this->container->get(PrivacyJobs::class)->delete($id) === null) {
            return '';
        }
        if ($id === $userId) {
            $this->userSession->closeAllOf($id);
            $this->setUserVars(0, []);
        }
        return $this->_template((string)($p['successtemplate'] ?? ''));
    }

    // ====================
    // CMS:USER_TOKEN
    // ====================

    /**
     * CMS:USER_TOKEN - CSRF token for a form with a writing CMS:USER_* command.
     *
     * Outputs a hidden field <input type="hidden" name="_token" value="…">,
     * with format=value only the value (for Ajax: header X-Camindo-Token or POST field
     * _token). The token is also in $set['user_token']. It is checked by
     * USER_LOGIN, USER_REGISTER, USER_PW, USER_INPUT (if 'submit' is filled),
     * USER_DELETE, USER_LOGOUT and USER_BASKET (except function=load).
     *
     * The token hangs on a cookie: the FIRST call per request must come before the
     * first output, usually (CMS:USER_TOKEN set=…) at the
     * start of the template; further calls in the form then output the same token.
     * If it comes too late, there is no token - an HTML comment is output
     * with the note; the forms fail with the message 'token'.
     * See UserSession::csrfToken().
     */
    public function cmsToken($p): void
    {
        $token = $this->userSession->csrfToken();
        $GLOBALS['set']['user_token'] = $token;
        if ($token === '') {
            $html = '<!-- CMS:USER_TOKEN: headers already sent, no token - place (CMS:USER_TOKEN set=…) at the start of the template -->';
        }
        else {
            $html = strtolower((string)($p['format'] ?? '')) === 'value'
                ? $token
                : '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
            // formguard=true: timestamp and decoy field added (FormGuard) - only in the
            // form, not with the early call set=… at the start of the template
            if (FormGuard::parameter($p['formguard'] ?? '') !== false && strtolower((string)($p['format'] ?? '')) !== 'value') {
                $html .= $this->container->get(FormGuard::class)->fields();
            }
        }
        if (!empty($p['set'])) $GLOBALS['set'][$p['set']] = $html;
        else echo $html;
    }

    /**
     * CMS:USER_PASSWORD_RULES - rules and texts of the password policy as JSON for
     * data-c9-password (extension Password, included inline in the template):
     *
     *     <input type="password" name="data[passwort]"
     *            data-c9-password='(CMS:USER_PASSWORD_RULES container=kunden)'>
     *
     * Output HTML-escaped (for an attribute in single or double quotation marks).
     * Texts in the language of the page. If the field carries the
     * extension, these are exactly the rules the server checks on saving
     * (PasswordPolicy::rulesFor()); otherwise security.password_policy - then
     * nothing is checked.
     */
    public function cmsPasswordRules($p): void
    {
        $policy = $this->container->get(PasswordPolicy::class);
        $options = [];
        $area = $this->loadArea($p['container'] ?? '', ['profile']);
        if ($area !== null) {
            $field = $this->_name((string)($p['field'] ?? ''))
                ?: $this->fieldName((int)($area['login_password_field_id'] ?? 0), (int)$area['area_id']);
            $colDef = $this->record->virtualSchema((int)$area['area_id'], false)[$field] ?? [];
            $details = is_array($colDef['details'] ?? null) ? $colDef['details'] : (json_decode((string)($colDef['details'] ?? ''), true) ?: []);
            if (strcasecmp((string)($details['extension'] ?? ''), PasswordPolicy::EXTENSION) === 0) {
                $options = (array)($details['extension_options'] ?? []);
            }
        }
        $html = htmlspecialchars(json_encode($policy->clientOptions($options), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
        if (!empty($p['set'])) $GLOBALS['set'][$p['set']] = $html;
        else echo $html;
    }

    // ====================
    // CMS:USER_BASKET
    // ====================

    /**
     * CMS:USER_BASKET - edit a shopping basket.
     *
     * The basket hangs on the frontend session - also on an anonymous one: if there is
     * no session, the command creates one (cookie). It remains 'duration' days from
     * the last change (default user.basket_days) and survives a sign-in.
     *
     *     function=load           read the basket
     *     function=add            add articles from $_REQUEST['variable'] -
     *                             a list of record_ids (quantity 1) or
     *                             record_id => quantity. 'overwrite' = true sets the
     *                             quantity instead of adding to it.
     *     function=update         set quantities (record_id => quantity, 0 removes)
     *     function=update_addon   save 'basket_addon' (text, e.g. serialized) for the
     *                             category
     *
     * 'category' separates several baskets per session (default 0).
     *
     * What may go into the basket (basketAllowedIds()): only PUBLISHED
     * records (Cms::publishedSql() as CMS:SELECT) that the request may see.
     * Without 'container' from areas of type 'record' or 'ugc' - never profiles;
     * with 'container' (area names or ids, comma-separated) only from these
     * areas (also 'profile', if explicitly named). The same check applies
     * when reading: an article that is now offline remains in `user_basket`
     * but appears neither in $set['basket'] nor in the result set.
     *
     * Result in $set - after EVERY function:
     *     $set['basket']        record_id => quantity (category)
     *     $set['basket_count']  sum of the quantities
     *     $set['basket_addon']  stored additional text of the category
     * With function=load the article records are additionally available as a result set
     * (Cms::select() via the record_ids) - a following CMS:RESULT_START
     * runs through them; the quantity per row is given by $set['basket'][$cms['record_id']].
     */
    public function cmsBasket($p): void
    {
        $function = strtolower(trim((string)($p['function'] ?? 'load')));
        $category = (int)($p['category'] ?? 0);
        $cookieName = (string)($p['cookiename'] ?? '');
        $instanceId = (int)($this->cms->instance['instance_id'] ?? 0);

        $sessionId = $this->userSession->ensure($instanceId, $cookieName);
        $containerIds = $this->basketContainerIds($p);
        $days = (int)($p['duration'] ?? 0);
        if ($days < 1) $days = (int)$this->config->get('user.basket_days') ?: 30;

        $changed = false;
        // writing functions only with a token; without it, it stays read-only
        if ($function !== 'load' && !$this->csrfOk($p)) $function = 'load_only';
        if ($function === 'add' || $function === 'update') {
            $variable = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($p['variable'] ?? ''));
            $items = $variable !== '' ? (array)($_REQUEST[$variable] ?? []) : [];
            $quantities = [];
            if (array_is_list($items)) {
                // Liste von Ids (variable[]=5&variable[]=7): je Nennung Menge 1
                foreach ($items as $value) {
                    if (is_array($value)) continue;
                    $recordId = (int)$value;
                    $quantities[$recordId] = ($quantities[$recordId] ?? 0) + 1;
                }
            }
            else {
                // record_id => Menge (variable[5]=2)
                foreach ($items as $key => $value) {
                    if (is_array($value)) continue;
                    $quantities[(int)$key] = (int)$value;
                }
            }
            unset($quantities[0]);
            // only records that may go into the basket; removing (update with
            // quantity 0) always works - otherwise an article that went offline would remain stuck
            $allowedIds = $this->basketAllowedIds(array_keys($quantities), $containerIds);
            $quantities = array_filter(
                $quantities,
                fn($quantity, $recordId) => in_array($recordId, $allowedIds, true) || ($quantity <= 0 && $function === 'update'),
                ARRAY_FILTER_USE_BOTH
            );
            $overwrite = ($function === 'update') || $this->_bool($p['overwrite'] ?? false);
            foreach ($quantities as $recordId => $quantity) {
                $this->basketSet($sessionId, $category, $recordId, $quantity, $overwrite, $function === 'update');
                $changed = true;
            }
        }
        elseif ($function === 'update_addon') {
            $this->userSession->setDetail('basket_addon_' . $category, (string)($p['basket_addon'] ?? ''));
            $changed = true;
        }
        if ($changed || $this->userSession->get('basket_expires') === null) {
            $this->userSession->setDetail('basket_expires', gmdate('Y-m-d H:i:s', time() + $days * 86400));
        }

        // Inhalt lesen
        // --------------------
        $this->db->pushResult();
        $this->db->query(
            "SELECT `record_id`, `quantity` FROM `user_basket` WHERE `session_id` = ? AND `category` = ? ORDER BY `basket_id` ASC",
            'si',
            [$sessionId, $category]
        );
        $basket = [];
        foreach ($this->db->fetchAll() as $row) {
            $basket[(int)$row['record_id']] = (int)$row['quantity'];
        }
        $this->db->popResult();
        // what is (no longer) published or lies outside 'container' does not count
        // (the row remains, see method comment)
        $basket = array_intersect_key($basket, array_flip($this->basketAllowedIds(array_keys($basket), $containerIds)));
        $GLOBALS['set']['basket'] = $basket;
        $GLOBALS['set']['basket_count'] = array_sum($basket);
        $GLOBALS['set']['basket_addon'] = (string)($this->userSession->get('basket_addon_' . $category) ?? '');

        if ($function === 'load') {
            // Artikel als Ergebnismenge (leere Liste -> leeres Ergebnis)
            if (empty($basket)) {
                $this->db->setArrayResult([]);
            }
            else {
                $this->cms->select(['filter' => ['record_id' => array_keys($basket)], 'limit' => -1]);
            }
        }
    }

    /**
     * Set / add / remove an article in the basket. Whether the record may go into the
     * basket has been checked by the caller (basketAllowedIds()).
     */
    private function basketSet(string $sessionId, int $category, int $recordId, int $quantity, bool $overwrite, bool $removeZero): void
    {
        if ($recordId < 1) return;
        $this->db->pushResult();
        try {
            if ($quantity <= 0 && ($removeZero || $overwrite)) {
                $this->db->query(
                    "DELETE FROM `user_basket` WHERE `session_id` = ? AND `category` = ? AND `record_id` = ?",
                    'sii',
                    [$sessionId, $category, $recordId]
                );
                return;
            }
            if ($quantity <= 0) return;
            $sql = "INSERT INTO `user_basket` (`session_id`, `category`, `record_id`, `quantity`, `addon`, `changed_at`)"
                 . " VALUES (?, ?, ?, ?, '', UTC_TIMESTAMP())"
                 . " ON DUPLICATE KEY UPDATE `quantity` = "
                 . ($overwrite ? "VALUES(`quantity`)" : "`quantity` + VALUES(`quantity`)")
                 . ", `changed_at` = UTC_TIMESTAMP()";
            $this->db->query($sql, 'siii', [$sessionId, $category, $recordId, $quantity]);
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Areas from 'container' (names or ids, comma-separated) - only
     * record areas that the request may see.
     *
     * @return int[]|null  null = no 'container' given; [] = given, but
     *                     none of them found (then nothing may go into the basket)
     */
    private function basketContainerIds(array $p): ?array
    {
        $names = $this->_list($p['container'] ?? []);
        if (empty($names)) return null;
        $ids = [];
        foreach ($names as $name) {
            $area = $this->loadArea($name, Area::CONTENT_TYPES);
            if ($area !== null) $ids[] = (int)$area['area_id'];
        }
        return array_values(array_unique($ids));
    }

    /**
     * Which of the ids may go into the basket? Published (Cms::publishedSql(),
     * as CMS:SELECT), visible to the request (Cms::$sqlAccess), and from the
     * areas $containerIds - without these from areas of type 'record' or
     * 'ugc' (never profiles of other users).
     *
     * @param int[]      $ids
     * @param int[]|null $containerIds  see basketContainerIds()
     * @return int[]
     */
    private function basketAllowedIds(array $ids, ?array $containerIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
        if (empty($ids) || $containerIds === []) return [];
        $where = "`r`.`record_id` IN (" . implode(',', $ids) . ")"
               . " AND `r`.`tmp` = 0 AND `r`.`deleted` = 0 AND `a`.`deleted` = 0"
               . " AND " . $this->cms->publishedSql([], 'r');
        if ($containerIds !== null) {
            $where .= " AND `r`.`area_id` IN (" . implode(',', array_map('intval', $containerIds)) . ")";
        }
        else {
            $where .= " AND `a`.`type` IN ('record', 'ugc')";
        }
        if ($this->cms->sqlAccess !== '') $where .= ' AND `r`.' . $this->cms->sqlAccess;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `r`.`record_id`, `r`.`area_id` FROM `record` `r`"
                . " INNER JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id` WHERE " . $where
            );
            return array_map(fn($row) => (int)$row['record_id'], $this->db->fetchAll());
        }
        finally {
            $this->db->popResult();
        }
    }

    // ====================
    // Write records
    // ====================

    /**
     * Create or update a record from frontend inputs - via the same working copy
     * as the backend (tmpInit()/checkOut() -> save()).
     *
     * @param int    $areaId       area
     * @param int    $id           existing record, 0 = new
     * @param array  $input        inputs (data[...])
     * @param array  $fields       allowed field names, empty = all user-defined
     * @param array  $overwrite    values that overwrite the inputs (also meta fields)
     * @param array  $removeFiles  upload fields whose file is removed
     * @param int    $userId       owner (only set for new records)
     * @param array  $p            command parameters (messages)
     * @param int    $masterId     parent_id for new records in tree areas
     * @param int    $ownerId      owner (admin_id) of new records, see ownerId()
     * @return int                 record_id, 0 on error (messages in Cms::error())
     */
    private function saveRecord(int $areaId, int $id, array $input, array $fields, array $overwrite, array $removeFiles, int $userId, array $p, int $masterId = 0, int $ownerId = 0): int
    {
        $schemaVirtual = $this->record->virtualSchema($areaId, false);
        $allowed = array_map(fn($f) => $this->_name((string)$f), $this->_list($fields));
        $allowed = array_values(array_filter($allowed));

        // uploads: Record::save() reads $_FILES['data'] - another array name
        // is mirrored there
        $dataName = $this->dataName($p);
        if ($dataName !== 'data' && isset($_FILES[$dataName]) && !isset($_FILES['data'])) {
            $_FILES['data'] = $_FILES[$dataName];
        }

        // Arbeitskopie
        // --------------------
        if ($id > 0) {
            $data = $this->record->load($id);
            if ($data === null || !$tmpId = $this->record->checkOut($data)) {
                $this->fail($p, 'save', 'user.error_save_failed');
                return 0;
            }
        }
        else {
            $data = $this->record->tmpInit(['area_id' => $areaId]);
            if ($data === null) {
                $this->fail($p, 'save', 'user.error_save_failed');
                return 0;
            }
            $tmpId = (int)$data['record_id'];
        }

        // take over inputs: only user-defined fields that 'fields' allows
        // --------------------
        $written = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if (!array_key_exists($name, $input)) continue;
            if (!empty($allowed) && !in_array($name, $allowed, true)) continue;
            if (!empty($colDef['meta'])) continue;
            $type = (string)($colDef['type'] ?? 'text');
            if ($type === 'file' || $type === 'fulltext') continue; // files via $_FILES
            $data[$name] = $input[$name];
            $written[] = $name;
        }
        // upload fields: the file comes from $_FILES, the field counts as transmitted
        foreach ($schemaVirtual as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') !== 'file') continue;
            if ((string)($colDef['display_type'] ?? '') !== 'upload') continue;
            if (!empty($allowed) && !in_array($name, $allowed, true)) continue;
            if (isset($_FILES['data']['name'][$name]) || in_array($name, $removeFiles, true)) $written[] = $name;
        }

        // remove files
        foreach ($removeFiles as $name) {
            $fieldId = (int)($schemaVirtual[$name]['field_id'] ?? 0);
            if ($fieldId > 0) $this->record->markFileDeleted($tmpId, $fieldId);
        }

        // overwrites (meta fields allowed), owner, tree
        // --------------------
        foreach ($overwrite as $name => $value) {
            $name = $this->_name((string)$name);
            if ($name === '' || in_array($name, ['record_id', 'area_id', 'instance_id', 'tmp', 'deleted', 'origin_id', 'owner_id', 'created_by', 'changed_by'], true)) continue;
            $data[$name] = $value;
            $written[] = $name;
        }
        if ($id < 1) {
            $data['user_id'] = $userId;
            $written[] = 'user_id';
            // owner in the backend: owner_id and created_by - without a backend session
            // Dataset::save() takes the passed value (see ownerId())
            if ($ownerId > 0) {
                $data['owner_id'] = $ownerId;
                $data['created_by'] = $ownerId;
                $written[] = 'owner_id';
            }
            if ($masterId > 0 && isset($schemaVirtual['parent_id'])) {
                $data['parent_id'] = $masterId;
                $written[] = 'parent_id';
            }
        }
        $data['record_id'] = $tmpId;
        $written = array_values(array_unique($written));

        // is a password being set? (empty = keep the previous one, see
        // VirtualFieldsTrait::saveVirtualFields()) - cmsInput() then ends the
        // other sessions of the profile
        $this->passwordChanged = false;
        foreach ($written as $name) {
            $colDef = $schemaVirtual[$name] ?? [];
            if (($colDef['type'] ?? '') !== 'password' && ($colDef['display_type'] ?? '') !== 'password') continue;
            $values = is_array($data[$name] ?? null) ? $data[$name] : [$data[$name] ?? ''];
            foreach ($values as $value) {
                if (is_string($value) && trim($value) !== '') $this->passwordChanged = true;
            }
        }

        // save - all checks of the area; uploads from the frontend without
        // active content (VirtualFieldsTrait::setUntrustedUploads())
        // --------------------
        $this->record->setUntrustedUploads(true);
        try {
            $recordId = $this->record->save($written, $data);
        }
        finally {
            $this->record->setUntrustedUploads(false);
        }
        if (!$recordId) {
            // discard the working copy together with the uploaded files: unlike the backend
            // mask, the frontend cannot display them again
            $this->record->cancel($tmpId);
            if (!$this->cms->hasErrors) $this->fail($p, 'save', 'user.error_save_failed');
            return 0;
        }
        return (int)$recordId;
    }

    /**
     * Owner (backend admin) for the records that a user creates: parameter
     * 'owner' (admin_id), otherwise the configuration user.default_owner_id. The admin
     * must exist - otherwise message 'owner' (user.error_owner) and 0.
     *
     * In the backend every record belongs to an admin (record.owner_id, plus
     * created_by); their group decides which other admins may edit it
     * (developer docs, chapter 11 (Permissions)).
     */
    private function ownerId(array $p): int
    {
        $ownerId = (int)($p['owner'] ?? 0);
        if ($ownerId < 1) $ownerId = (int)$this->config->get('user.default_owner_id');
        if ($ownerId > 0) {
            $this->db->pushResult();
            $this->db->query("SELECT 1 FROM `admin` WHERE `admin_id` = ? AND `deleted` = 0", 'i', [$ownerId]);
            $exists = $this->db->num_rows > 0;
            $this->db->popResult();
            if (!$exists) $ownerId = 0;
        }
        if ($ownerId < 1) $this->fail($p, 'owner', 'user.error_owner');
        return $ownerId;
    }

    // ====================
    // Read profiles and records
    // ====================

    /**
     * Load a profile record - via Cms::select(), so that instance access and
     * optional filter criteria (USER_AUTH / USER_PROTECT) apply.
     *
     * @param int   $recordId
     * @param array $filter  additional criteria (syntax as CMS:SELECT 'filter')
     * @param array $mode    filtermode per field
     * @return array|null    record or null (unknown, no access, filter does not match)
     */
    private function loadProfile(int $recordId, array $filter = [], array $mode = []): ?array
    {
        if ($recordId < 1) return null;
        $filter['record_id'] = $recordId;
        $this->db->pushResult();
        try {
            $this->cms->select(['filter' => $filter, 'filtermode' => $mode, 'limit' => 1]);
            if ($this->db->num_rows < 1) return null;
            $data = $this->cms->fetch();
        }
        finally {
            $this->db->popResult();
        }
        if ($data === null) return null;
        // only records from profile areas
        $area = $this->areaById((int)$data['area_id']);
        if ($area === null || (string)$area['type'] !== 'profile') return null;
        return $data;
    }

    /**
     * Profile for a field value (sign-in name, e-mail) - exactly one hit.
     *
     * Directly on the data_* table instead of via Cms::select(): its filter syntax
     * interprets operators, wildcards and " OR " in the input - but a sign-in name
     * must be compared literally. For 'chars' the database compares
     * without regard to upper/lower case (collation), for 'encrypted' via the hash of the
     * input normalized as on saving (Cms::encryptedHash()). Only what the request
     * may see is found (Cms::$sqlAccess).
     *
     * @param bool $any  true: any hit suffices (duplicate check), even
     *                   several; false: exactly one, otherwise null
     */
    private function findProfile(int $areaId, string $field, string $value, bool $any = false): ?array
    {
        $field = $this->_name($field);
        if ($field === '' || $value === '') return null;
        $fn = $this->cms->resolveVirtualField($field);
        if ($fn === null) return null;
        $type = (string)($fn['type'] ?? '');
        if ($type === 'chars' || $type === 'text') {
            $table = 'data_' . $this->cms->dataSuffix[$type];
            $col   = 'data';
            $needle = $value;
        }
        elseif ($type === 'encrypted') {
            // hash the input normalized as on saving (Cms::encryptedHash():
            // lowercase, without whitespace and punctuation) - "Anna@…" finds "anna@…"
            $needle = $this->cms->encryptedHash($value);
            if ($needle === '') return null;
            $table = 'data_enc';
            $col   = 'hash';
        }
        else {
            return null;
        }
        $access = $this->cms->sqlAccess !== '' ? ' AND `r`.' . $this->cms->sqlAccess : '';
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT DISTINCT `r`.`record_id` FROM `{$table}` `d`"
                . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
                . " WHERE `d`.`fieldname_id` = ? AND `d`.`{$col}` = ?"
                . " AND `r`.`area_id` = ? AND `r`.`tmp` = 0 AND `r`.`deleted` = 0" . $access
                . " LIMIT 2",
                'isi',
                [(int)$fn['fieldname_id'], $needle, $areaId]
            );
            $ids = array_map('intval', $this->db->fetchColumn());
        }
        finally {
            $this->db->popResult();
        }
        if (empty($ids)) return null;
        if (!$any && count($ids) > 1) return null;
        return $this->loadRecord($ids[0]);
    }

    /**
     * Compare the password of a profile with the input (`data_pwd`, hash with
     * embedded salt - Crypt::passwordVerify()).
     *
     * @param int    $recordId
     * @param string $pwField   name of the password field
     * @param string $password  input (plain text)
     */
    private function passwordOk(int $recordId, string $pwField, string $password): bool
    {
        $fn = $this->cms->resolveVirtualField($pwField);
        if ($fn === null || (string)($fn['type'] ?? '') !== 'password') return false;
        $this->db->pushResult();
        $this->db->query(
            "SELECT `data` FROM `data_pwd` WHERE `record_id` = ? AND `fieldname_id` = ? LIMIT 1",
            'ii',
            [$recordId, (int)$fn['fieldname_id']]
        );
        $stored = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->popResult();
        if (!$this->crypt->passwordVerify($password, $stored)) return false;

        // older format or old parameters: now that the password is known, rehash
        // (Crypt::passwordNeedsRehash()) - all language rows of the field
        if ($this->crypt->passwordNeedsRehash($stored)) {
            $this->db->pushResult();
            $this->db->query(
                "UPDATE `data_pwd` SET `data` = ? WHERE `record_id` = ? AND `fieldname_id` = ?",
                'sii',
                [$this->crypt->passwordHash($password), $recordId, (int)$fn['fieldname_id']]
            );
            $this->db->popResult();
        }
        return true;
    }

    /**
     * Does the input set a password field of the area to a non-empty value?
     * (empty = keep the previous one; only fields that 'fields' allows)
     */
    private function passwordInput(int $areaId, array $input, array $fields): bool
    {
        $allowed = array_values(array_filter(array_map(fn($f) => $this->_name((string)$f), $fields)));
        foreach ($this->record->virtualSchema($areaId, false) as $name => $colDef) {
            if (($colDef['type'] ?? '') !== 'password' && ($colDef['display_type'] ?? '') !== 'password') continue;
            if ($allowed && !in_array($name, $allowed, true)) continue;
            $values = is_array($input[$name] ?? null) ? $input[$name] : [$input[$name] ?? ''];
            foreach ($values as $value) {
                if (is_string($value) && trim($value) !== '') return true;
            }
        }
        return false;
    }

    /**
     * May the running session change the password of its profile?
     *
     * Yes, if
     *   - it was signed in via the reset link and the release still applies
     *     (details.password_reset_until, see loginProfile()), or
     *   - the previous password (sign-in password field of the area) is in the request:
     *     data[{currentfield}], default data[current_password].
     * A profile without a stored password needs none. Failed attempts count in the
     * brute-force protection like a wrong sign-in (same name) - otherwise the
     * password could be tried out via this form.
     *
     * Messages: 'current' (user.error_password_current), 'blocked'.
     */
    private function currentPasswordOk(array $area, int $userId, array $input, array $p, bool $allowReset = true): bool
    {
        $until = (string)($this->userSession->get('password_reset_until') ?? '');
        if ($allowReset && $until !== '' && $until >= gmdate('Y-m-d H:i:s')) return true;

        $areaId = (int)$area['area_id'];
        $pwField = $this->fieldName((int)($area['login_password_field_id'] ?? 0), $areaId);
        if ($pwField === '') return true; // no sign-in field - nothing to check against
        $fn = $this->cms->resolveVirtualField($pwField);
        $this->db->pushResult();
        $this->db->query(
            "SELECT `data` FROM `data_pwd` WHERE `record_id` = ? AND `fieldname_id` = ? LIMIT 1",
            'ii',
            [$userId, (int)($fn['fieldname_id'] ?? 0)]
        );
        $stored = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->popResult();
        if ($stored === '') return true;

        $guard = $this->loginGuard->forUsers();
        $guardName = mb_substr($areaId . ':#' . $userId, 0, self::NAME_MAX);
        if ($blockedSeconds = $guard->blockedFor($guardName)) {
            $this->fail($p, 'blocked', 'user.error_login_blocked', ['minutes' => (int)ceil($blockedSeconds / 60)]);
            return false;
        }
        $currentField = $this->_name((string)($p['currentfield'] ?? '')) ?: 'current_password';
        $current = (string)($input[$currentField] ?? '');
        if ($current === '' || !$this->passwordOk($userId, $pwField, $current)) {
            $guard->recordFailure($guardName);
            $this->fail($p, 'current', 'user.error_password_current');
            return false;
        }
        $guard->clear($guardName);
        return true;
    }

    /**
     * Load a productive record that this request may see (instance access).
     */
    private function loadRecord(int $recordId): ?array
    {
        if ($recordId < 1) return null;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT * FROM `record` WHERE `record_id` = ? AND `tmp` = 0 AND `deleted` = 0",
                'i',
                [$recordId]
            );
            if ($this->db->num_rows < 1) return null;
            $data = $this->cms->fetch();
        }
        finally {
            $this->db->popResult();
        }
        if ($data === null || !$this->cms->instanceAllowed((int)($data['instance_id'] ?? 0))) return null;
        return $data;
    }

    /**
     * Load a record that belongs to the user (record.user_id) - or their own
     * profile. With $ignoreUser it suffices that it exists.
     *
     * @param int  $areaId  area in which it must lie (0 = any)
     */
    private function loadOwned(int $recordId, int $areaId, int $userId, bool $ignoreUser): ?array
    {
        $data = $this->loadRecord($recordId);
        if ($data === null) return null;
        if ($areaId > 0 && (int)$data['area_id'] !== $areaId) return null;
        if ($ignoreUser) return $data;
        if ($userId < 1) return null;
        if ($recordId === $userId) return $data;                    // own profile
        if ((int)($data['user_id'] ?? 0) === $userId) return $data; // own contribution
        return null;
    }

    // ====================
    // Areas and fields
    // ====================

    /**
     * Area by id or name ('container'), checked for allowed types and the
     * instance access of the request. `details` is decoded.
     */
    private function loadArea(mixed $container, array $types): ?array
    {
        $container = trim((string)$container);
        if ($container === '') return null;
        if (preg_match('/^\d+$/', $container)) {
            $area = $this->areaById((int)$container);
        }
        else {
            // name -> id from the instance file cache (only areas of the instances of the
            // request, Cms::areaMap()); the first hit in access order
            $area = null;
            foreach ($this->cms->areaIds($container) as $areaId) {
                $area = $this->areaById((int)$areaId);
                if ($area !== null) break;
            }
        }
        if ($area === null) return null;
        if (!in_array((string)$area['type'], $types, true)) return null;
        if (!$this->cms->instanceAllowed((int)$area['instance_id'])) return null;
        return $area;
    }

    private function areaById(int $areaId): ?array
    {
        if ($areaId < 1) return null;
        if (array_key_exists($areaId, $this->areaCache)) return $this->areaCache[$areaId];
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `area` WHERE `area_id` = ? AND `deleted` = 0", 'i', [$areaId]);
        $area = $this->db->fetch();
        $this->db->popResult();
        if ($area !== null) {
            $area['details'] = json_decode((string)($area['details'] ?? ''), true) ?: [];
        }
        return $this->areaCache[$areaId] = $area;
    }

    /**
     * Name of a field of the area, '' if the field does not exist there.
     */
    private function fieldName(int $fieldId, int $areaId): string
    {
        if ($fieldId < 1) return '';
        foreach ($this->record->virtualSchema($areaId, false) as $name => $colDef) {
            if ((int)($colDef['field_id'] ?? 0) === $fieldId) return (string)$name;
        }
        return '';
    }

    // ====================
    // Cleanup (cron)
    // ====================

    /**
     * Permanently delete unconfirmed profiles (status_id 2 after USER_REGISTER) that are older than
     * user.unconfirmed_days. Anyone who registers a foreign address and never clicks
     * the link blocks it only for this period -
     * afterwards the owner can register themselves. Called by the scheduler
     * task "Apply retention periods" (type retention), at most
     * PURGE_UNCONFIRMED_LIMIT per call - the runner repeats as long as there are full rounds.
     *
     * @return int  number of deleted profiles
     */
    /** Unconfirmed profiles per call of purgeUnconfirmed() */
    public const PURGE_UNCONFIRMED_LIMIT = 200;

    public function purgeUnconfirmed(): int
    {
        $days = (int)$this->config->get('user.unconfirmed_days');
        if ($days < 1) return 0;
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `r`.`record_id`, `r`.`area_id` FROM `record` `r` INNER JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id`"
                . " WHERE `a`.`type` = 'profile' AND `r`.`status_id` = 2 AND `r`.`tmp` = 0"
                . " AND `r`.`created_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY) LIMIT " . self::PURGE_UNCONFIRMED_LIMIT,
                'i', [$days]
            );
            $rows = $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
        $count = 0;
        foreach ($rows as $row) {
            try {
                if ($this->record->purge((int)$row['record_id'], false)) $count++;
            }
            catch (\Throwable $e) {
                $this->system->log('user', 'Unconfirmed profile ' . (int)$row['record_id'] . ' not purged: ' . $e->getMessage());
            }
        }
        if ($count > 0) {
            $this->system->log('user', sprintf('%d unconfirmed profile(s) older than %d days purged.', $count, $days));
        }
        return $count;
    }

    // ====================
    // Drosselung, Formularschutz
    // ====================

    /**
     * formguard=true (or =N seconds minimum time): does the form come from a
     * human? (FormGuard)
     */
    private function humanOk(array $p): bool
    {
        $guard = FormGuard::parameter($p['formguard'] ?? '');
        if ($guard === false) return true;
        return $this->container->get(FormGuard::class)->verify($guard);
    }

    /**
     * Do all buckets allow the request? Then count it (RateLimiter). A
     * rejected request counts in none.
     *
     * @param array $buckets  list of [bucket, key]
     */
    private function throttleHit(array $buckets): bool
    {
        if (!$this->throttleAllows($buckets)) return false;
        $this->throttleRecord($buckets);
        return true;
    }

    private function throttleAllows(array $buckets): bool
    {
        $limiter = $this->container->get(RateLimiter::class);
        foreach ($buckets as [$bucket, $key]) {
            if (!$limiter->allows($bucket, (string)$key)) return false;
        }
        return true;
    }

    private function throttleRecord(array $buckets): void
    {
        $limiter = $this->container->get(RateLimiter::class);
        foreach ($buckets as [$bucket, $key]) {
            $limiter->record($bucket, (string)$key);
        }
    }


    // ====================
    // Tokens
    // ====================

    /**
     * Create a one-time token (activation, password reset). Older tokens of the same
     * purpose for the profile expire.
     */
    private function createToken(int $recordId, string $purpose): string
    {
        $token = $this->crypt->randomKey(32);
        $this->db->pushResult();
        $this->db->query(
            "DELETE FROM `user_token` WHERE `record_id` = ? AND `purpose` = ?",
            'is',
            [$recordId, $purpose]
        );
        $this->db->query(
            "INSERT INTO `user_token` (`token`, `record_id`, `purpose`, `created_at`) VALUES (?, ?, ?, UTC_TIMESTAMP())",
            'sis',
            [$token, $recordId, $purpose]
        );
        $this->db->popResult();
        return $token;
    }

    /**
     * Redeem a token: valid -> record_id, the token is deleted; otherwise 0.
     * Expired tokens (user.token_expiration) are removed in the process.
     */
    private function consumeToken(string $token, string $purpose): int
    {
        if (strlen($token) !== 43 || preg_match('/^[A-Za-z0-9_\-]+$/', $token) !== 1) return 0;
        $expiration = (int)$this->config->get('user.token_expiration');
        if ($expiration < 1) $expiration = 86400;
        $valid = $purpose === 'password' ? $this->passwordTokenExpiration() : $expiration;
        $this->db->pushResult();
        try {
            $this->db->query(
                "DELETE FROM `user_token` WHERE `created_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)",
                'i',
                [max($expiration, $valid)]
            );
            $this->db->query(
                "SELECT `record_id` FROM `user_token` WHERE `token` = ? AND `purpose` = ?"
                . " AND `created_at` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)",
                'ssi',
                [$token, $purpose, $valid]
            );
            $recordId = (int)($this->db->fetchNum()[0] ?? 0);
            if ($recordId > 0) {
                $this->db->query("DELETE FROM `user_token` WHERE `token` = ?", 's', [$token]);
            }
            return $recordId;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Validity of the reset token and of the release afterwards (user.password_token_expiration).
     */
    private function passwordTokenExpiration(): int
    {
        $seconds = (int)$this->config->get('user.password_token_expiration');
        return $seconds > 0 ? $seconds : 3600;
    }

    // ====================
    // Hilfsfunktionen
    // ====================

    /**
     * Set $set['user_id'], $set['user'], $set['user_session_id'].
     */
    private function setUserVars(int $userId, array $profile, string $sessionId = ''): void
    {
        $GLOBALS['set']['user_id'] = $userId;
        $GLOBALS['set']['user'] = $profile;
        $GLOBALS['set']['user_session_id'] = $sessionId !== '' ? $sessionId : ($userId > 0 ? $this->userSession->id() : '');
        // may this session set a new password without the old one? (reset link,
        // loginProfile()) - the form then leaves out the field "previous password"
        $until = $userId > 0 ? (string)($this->userSession->get('password_reset_until') ?? '') : '';
        $GLOBALS['set']['password_reset'] = ($until !== '' && $until >= gmdate('Y-m-d H:i:s')) ? 1 : 0;
    }

    /**
     * 'submit': the command only runs if the value is not empty.
     */
    private function submitted(array $p): bool
    {
        if (trim((string)($p['submit'] ?? '')) === '') return false;
        return $this->csrfOk($p);
    }

    /**
     * CSRF check of a writing command (developer docs, chapter 14 (Security: web)): valid token from
     * CMS:USER_TOKEN in the request, otherwise message 'token' and false. Can be switched off per
     * command with csrf=false or globally with user.csrf = false.
     */
    private function csrfOk(array $p): bool
    {
        if (!$this->_bool($p['csrf'] ?? true) || !$this->config->get('user.csrf')) return true;
        if ($this->userSession->verifyCsrfRequest()) return true;
        $this->fail($p, 'token', 'user.error_token');
        return false;
    }

    /**
     * Name of the request array with the inputs ('data', default "data").
     */
    private function dataName(array $p): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($p['data'] ?? 'data'));
        return $name === '' ? 'data' : $name;
    }

    /**
     * Inputs from the request ($_POST takes precedence over $_REQUEST).
     */
    private function requestData(array $p): array
    {
        $name = $this->dataName($p);
        $data = $_POST[$name] ?? $_REQUEST[$name] ?? [];
        return is_array($data) ? $data : [];
    }

    /**
     * Store an error message: text from 'message[index]' of the command, otherwise the
     * snippet.
     */
    private function fail(array $p, string $index, string $snippet, array $params = []): void
    {
        $messages = (array)($p['message'] ?? []);
        $this->failMessage((string)($messages[$index] ?? ''), $snippet, $params);
    }

    private function failMessage(string $custom, string $snippet, array $params = []): void
    {
        $text = trim($custom) !== '' ? $custom : $this->cms->snippet($snippet, $params);
        $this->cms->error($text);
    }

    /**
     * Comma list or array -> cleaned list.
     */
    private function _list(mixed $value): array
    {
        if (is_array($value)) $items = $value;
        else $items = explode(',', (string)$value);
        $items = array_map('trim', array_map('strval', $items));
        return array_values(array_filter($items, fn($v) => $v !== ''));
    }

    /**
     * Read a template parameter as a truth value ("true"/"false" from the template).
     */
    private function _bool(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        return filter_var((string)$value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Restrict field names to [a-z0-9_-] (as Cms::_name()).
     */
    private function _name(string $s): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim($s)));
    }

    /**
     * successtemplate / errortemplate: resolve the reference "[area.]name" to the compiled
     * file (Cms::templateFile()); '' stays '' (no template).
     */
    private function _template(string $s): string
    {
        return $this->cms->templateFile($s);
    }
}
