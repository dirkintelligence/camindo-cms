<?php
/**
 * Access to the CMS configuration from .camindo.php.
 *
 * The only class the container CANNOT build itself: its constructor expects
 * an array instead of a class. It is therefore registered in Core.php via a factory:
 *
 *     $container->set(Config::class, fn() => new Config($_cfg));
 *
 * Directly afterwards $_cfg is discarded in Core.php - from then on this class is the only
 * source for configuration values. It is read-only, there is deliberately no setter.
 *
 * SIDE EFFECTS OF THE CONSTRUCTOR
 * --------------------
 * It does not only read, it sets up the runtime environment:
 *   - setlocale() + \Locale::setDefault()   (system.locale)
 *   - date_default_timezone_set()           (system.timezone; Cms later overrides it
 *                                            again with the user's time zone)
 *   - ini_set('error_log', ...)             PHP errors to var/log/{Y-m-d}_php_errors.log
 * Config must therefore be instantiated as early as possible.
 *
 * SECRETS FROM THE ENVIRONMENT
 * --------------------
 * Passwords do not have to be in .camindo.php: if one of the environment variables from
 * ENV_SECRETS is set (not empty), its value applies before the one from .camindo.php - the rest
 * of the configuration (host, user, mailbox …) stays in the file. Intended for
 * systems that set variables outside PHP (Docker, PHP-FPM env[…], Apache
 * SetEnv, systemd); an .env file is deliberately NOT read.
 *
 *     CAMINDO_DB_PASS     db.pass
 *     CAMINDO_SMTP_PASS   email.smtp.pass
 *     CAMINDO_KEY         key (Crypt) - conversely: the file paths.key_file
 *                         takes precedence, the variable applies only without a file
 *     CAMINDO_AI_API_KEY  ai.api_key (add-on module Ai, AiSettings)
 *
 * Read exclusively via env(): the value is cached per process
 * and then removed from the process environment, $_ENV and $_SERVER - child processes
 * (ImageMagick, ffmpeg, mysqldump …) do not inherit it, phpinfo() does not show it
 * (except under "Apache Environment" with SetEnv).
 */
namespace Camindo\Core;

class Config {
    /** Configuration values that an environment variable overrides before .camindo.php */
    public const ENV_SECRETS = [
        'db.pass'         => 'CAMINDO_DB_PASS',
        'email.smtp.pass' => 'CAMINDO_SMTP_PASS',
    ];

    /** Environment variable for the key (Crypt; the file paths.key_file takes precedence) */
    public const ENV_KEY = 'CAMINDO_KEY';

    /** Merged configuration: defaults, overridden by .camindo.php */
    private array $settings;

    /** Keys from ENV_SECRETS whose value comes from the environment */
    private array $fromEnv = [];

    /** Environment variables already read (env()): name => value, null = not set */
    private static array $env = [];

    /**
     * @param array $cfg  The array $_cfg from .camindo.php. By reference only, to save
     *                    copying - the content is not changed.
     * @throws \Exception if system.locale or system.timezone are empty (without them
     *                    date and number formats are undefined). If the keys are missing
     *                    altogether - there is no .camindo.php yet -, en_US and UTC apply,
     *                    so that the installer can run.
     */
    public function __construct(array &$cfg)
    {
        // Language settings. If the key is missing altogether (no .camindo.php - before the
        // installer), the default applies; an empty value is a configuration error.
        $locale = $cfg['system']['locale'] ?? 'en_US';
        if (empty($locale)) throw new \Exception("Configuration error: \$_cfg['system']['locale'] is empty.");
        setlocale(LC_TIME, $locale . ".utf8");
        \Locale::setDefault($locale);

        // Time settings
        $timezone = $cfg['system']['timezone'] ?? 'UTC';
        if (empty($timezone)) throw new \Exception("Configuration error: \$_cfg['system']['timezone'] is empty.");
        date_default_timezone_set($timezone);
        $corDir = dirname(__DIR__, 2);   // .../camindo (two levels up from Core/src)

        // Config default values
        // Everything .camindo.php does not set comes from here. The paths are chosen
        // so that a standard installation runs without path entries in .camindo.php.
        $defaults = [
            'system' => [
                /*
                    Default language
                */
                'locale' => 'en_US',
                /*
                    Default time zone
                */
                'timezone' => 'Europe/Berlin',
                /*
                    Development mode - enables additional debug options.
                    Do not enable in a production environment!
                */
                'development_version' => false,
                /*
                    LOG ALL SQL commands and measure time.
                    Pure developer feature, requires system.development_version = true.
                */
                'debugsql' => false,
                /*
                    Maximum length of an automatically generated slug (characters).
                    Applies when a record is saved with an empty field 'slug' -
                    then the content of the default field is used and shortened.
                    See Cms::textToUri()
                */
                'link_length' => 120,
                /*
                    Whitelist for HTML in content: list of allowed tag names, e.g.
                    ['p','br','b','strong','i','em','u','a','ul','ol','li','h2','h3',
                    'img','table','thead','tbody','tr','th','td','blockquote','span','div'].
                    Other tags are removed by Cms::_sanitize_html() when saving and when
                    reading (the text in between stays). Empty = no whitelist; script,
                    iframe, object, embed, base, meta … are always removed
                    (developer docs, chapter 14 (Security: web)). Does not apply to templates.
                */
                'html_allowed_tags' => [],
            ],
            'email' => [
                /*
                    Sender address for emails sent by the CMS,
                    e.g. for the two-factor login
                    Default sender for CMS:EMAIL
                */
                'noreply' => '',
                /*
                    Recipient address for feedback
                    Mentioned in the email text of the two-factor login with the noreply sender
                */
                'reply' => '',
                /*
                    SMTP mailbox of the sender address for clean sending
                    Fallback: PHP mail()
                */
                'smtp' => [
                    'host' => '',
                    'user' => '',
                    'pass' => '',
                    'enc'  => 'starttls',  // 'starttls' or 'smtps'
                    'port' => 587
                ],
            ],
            'admin' => [
                /*
                    Enable two-factor login by email.
                    After an accepted password, a login code is sent by email.
                    Requires email.noreply / email.reply.
                    Admins with an authenticator app (TOTP, security.admin_2fa) are
                    always asked for the app code regardless of this.
                */
                '2_factor' => true,
                /*
                    Validity of the 2-factor code in seconds
                    (with value 0 no login can succeed)
                */
                '2_factor_expiration' => 600,
                /*
                    Time window of inactivity in the backend before automatic logout
                    in seconds. Unsaved content remains in the input mask even after re-login.
                */
                'max_inactive_sec' => 1800,
                /*
                    Edit lock in seconds: a working copy that has not been changed for this long
                    no longer locks the record (other users can open it again). Deliberately
                    longer than max_inactive_sec - after a break and a new login the
                    open mask can still be saved.
                */
                'edit_lock_sec' => 5400,
                /*
                    Brute-force protection of the login (Camindo\Core\LoginGuard)
                    Allowed failed attempts per name, 0 = protection off
                */
                'bruteforce_max' => 3,
                /*
                    Time window and block duration in seconds
                */
                'bruteforce_delay' => 600,
                /*
                    With too many failed attempts:
                    record the IP and include it in the security log
                */
                'bruteforce_ip' => true,
                /*
                    Throttling of the login per origin (Camindo\Core\RateLimiter,
                    bucket admin_login_ip) - [at most, time window in seconds],
                    at most 0 = off.
                        login_ip   failed logins per IP across all names
                                   (password spraying: many names, one password each)
                    Behind a reverse proxy set security.trusted_proxies, otherwise
                    all visitors share one address (ClientIp).
                */
                'throttle' => [
                    'login_ip' => [20, 600],
                ],
                /*
                    Session cookie 'sessid' of the backend: send 'secure' only over HTTPS
                    (set to false for http in development), SameSite attribute.
                    HttpOnly is always set (Session::setCookie()).
                */
                'cookie_secure' => true,
                'cookie_samesite' => 'Lax',
                /*
                    Backend domain (Camindo\Core\BackendDomain, developer docs,
                    chapter 13 Security: authentication), e.g. 'cms-k7x2.example.com'.
                    Empty: backend under every domain of the installation ({domain}/backend/).
                    Set: backend only there (otherwise 404), preview and
                    frontend editing run via the domain of the website
                    (FrontendHandover). Prerequisite for WebAuthn.
                */
                'backend_domain' => '',
                /*
                    Origin check (Backend::originAllowed(), developer docs,
                    chapter 14 Security: web): modifying backend requests must - provided the browser
                    sends Origin or Referer - come from the host of the backend. Further
                    allowed origins as a list, e.g. ['https://cms.example.com'],
                    in case a proxy rewrites the host.
                */
                'allowed_origins' => [],
                /*
                    Password validity in days: after that the admin must set a new password
                    at login that differs from the previous one (developer docs, chapter 13 (Security: authentication))
                    Security: authentication). 0 = unlimited.
                    Independently of this: a password that another admin set
                    (creating, resetting) must be changed at the first login.
                */
                'password_retention_days' => 0,
                /*
                    Maximum number of records in direct selection (select list)
                */
                'list_max_datasets' => 200,
                /*
                    Number of records on one page, for a list with pagination
                */
                'skiplist_default' => 20,
                /*
                    Maximum nesting depth of templates
                    See CMS:SELECT
                */
                'max_include_depth' => 20,
                /*
                    Output cache of CMS:INCLUDE (caching=…, IncludeCache) - false
                    switches it off: all templates run again on every call
                */
                'include_cache' => true,
                /*
                    A call waits at most this many seconds while another process
                    creates the same cache file; after that it renders itself
                    (without storing)
                */
                'include_cache_lock_wait' => 30,
                /*
                    Retention period of deleted records in the trash in days -
                    after that they are deleted for good (scheduler task "empty trash"). The action "To the
                    trash" of a deletion policy (Privacy module) relies on this.
                */
                'trash_retention_days' => 7,
                /*
                    Maximum number of records that are processed immediately in a mass deletion / restore.
                    With more records a background process starts
                    (0 = always process in the request)
                */
                'mass_delete_threshold' => 200,
                /*
                    Export of records (RecordExport): from this number of records on
                    the export always runs as a background process (0 = never automatically)
                */
                'export_background_threshold' => 1000,
                /*
                    Maximum time an export file stays in var/export in hours -
                    after that the scheduler task "delete temporary files" removes it
                    (default hourly); a downloaded zip is deleted immediately
                */
                'export_retention_hours' => 24,
                /*
                    Import of records (RecordImport): from this number of records on
                    the import always runs as a background process (0 = never automatically)
                */
                'import_background_threshold' => 200,
                /*
                    Cleanup of var/tmp (scheduler task tmpcleanup, default hourly): files and
                    directories older than this many hours are removed
                    (lock files *.lock, stamp files and cron.log.txt remain)
                */
                'tmp_retention_hours' => 24,
            ],
            'api' => [
                /*
                    Sessions of the command line and of the PHP API (Camindo\Core\Api\ApiSession):
                    time window of inactivity in seconds
                */
                'max_inactive_sec' => 3600,
                /*
                    Maximum lifetime of such a session in seconds, regardless of
                    activity - also the upper limit for a lifetime given at login
                    (maxLifetime)
                */
                'max_lifetime' => 86400,
                /*
                    Maximum number of records per page of an API list (record.search …)
                */
                'max_limit' => 200,
                /*
                    Maximum size of a file passed as Base64 (bytes, 0 = no
                    limit) - record.update/create {"content": …}
                */
                'base64_max_bytes' => 20971520,
            ],
            'user' => [
                /*
                    Frontend community (Camindo\Core\UserSession, Modules\Record\RecordUser)
                    Time window of inactivity before a user session expires
                    in seconds (cookie and table `user_session`)
                */
                'max_inactive_sec' => 86400,
                /*
                    Retention of anonymous sessions (basket only) in days, if
                    CMS:USER_BASKET does not specify a parameter 'duration'
                */
                'basket_days' => 30,
                /*
                    Validity of the one-time tokens (activation after registration,
                    password reset) in seconds
                */
                'token_expiration' => 86400,
                /*
                    Validity of the password reset token (CMS:USER_PW) in seconds -
                    shorter than the activation: the link replaces the password.
                    After redemption (USER_LOGIN hash=…) the session may set a new
                    password without the old one for this long (USER_INPUT).
                */
                'password_token_expiration' => 3600,
                /*
                    Brute-force protection of the frontend login (table
                    `user_login_attempt`), meaning as admin.bruteforce_*
                */
                'bruteforce_max' => 5,
                'bruteforce_delay' => 600,
                'bruteforce_ip' => false,
                /*
                    Session cookie: send 'secure' only over HTTPS (for http instances
                    in development set to false), SameSite attribute
                */
                'cookie_secure' => true,
                'cookie_samesite' => 'Lax',
                /*
                    CSRF protection of the writing CMS:USER_* commands: require the token from
                    CMS:USER_TOKEN in the request (developer docs, chapter 14 (Security: web)).
                    false = off (only for transition periods; per command: csrf=false)
                */
                'csrf' => true,
                /*
                    Owner (admin_id) of the records that users create via CMS:USER_REGISTER /
                    CMS:USER_INPUT if the command does not name a parameter 'owner'.
                    0 = no fallback, the command then fails without 'owner'.
                */
                'default_owner_id' => 0,
                /*
                    Throttling of the commands that trigger emails to freely entered addresses
                    or let logins be tried out (Camindo\Core\
                    RateLimiter, developer docs, chapter 14 (Security: web). Per bucket
                    [at most, time window in seconds]; at most 0 = off.
                        pw_value        CMS:USER_PW per entered value
                        pw_ip           CMS:USER_PW per origin (IP)
                        register_ip     CMS:USER_REGISTER per origin
                        register_value  CMS:USER_REGISTER per email address
                        mail_instance   USER_PW + USER_REGISTER together per instance
                        login_ip        failed logins per origin, across all names
                        login_code      new email code of the login per profile
                        email_ip        CMS:EMAIL throttle=true per origin
                        csp_report      reports to /csp_report.php per origin
                        zip_ip          newly created ZIP archives (CMS:ZIP) per origin
                    Own buckets for CMS:EMAIL throttle=name are added here.
                */
                'throttle' => [
                    'pw_value'       => [3, 3600],
                    'pw_ip'          => [10, 3600],
                    'register_ip'    => [5, 3600],
                    'register_value' => [3, 86400],
                    'mail_instance'  => [200, 3600],
                    'login_ip'       => [30, 600],
                    'login_code'     => [3, 600],
                    'email_ip'       => [5, 3600],
                    'csp_report'     => [60, 600],
                    'zip_ip'         => [30, 3600],
                ],
                /*
                    Form protection without an external service (CMS:USER_TOKEN formguard=true):
                    a form submitted faster than min_seconds or later than
                    max_seconds after delivery, or whose decoy field is filled,
                    counts as a robot.
                */
                /*
                    CMS:ZIP (Camindo\Core\CmsZip): at most this many files and MB per
                    archive (the command parameters maxfiles/maxsize may only be smaller);
                    created archives stay this many hours in var/tmp/zip
                    and are reused for the same request.
                */
                'zip' => [
                    'max_files'       => 100,
                    'max_mb'          => 500,
                    'retention_hours' => 24,
                ],
                'formguard_min_seconds' => 3,
                'formguard_max_seconds' => 3600,
                /*
                    Unconfirmed profiles (after CMS:USER_REGISTER, status_id 2) are
                    deleted for good after this many days - otherwise a foreign
                    registration blocks the address of the owner. 0 = never.
                */
                'unconfirmed_days' => 7,
                /*
                    Validity of the login code (second factor by email/SMS) in
                    seconds
                */
                'login_code_expiration' => 600,
            ],
            'security' => [
                /*
                    HSTS (Strict-Transport-Security, Camindo\Core\SecurityHeaders):
                    validity in seconds, 0 = off. Switch on only when the domain
                    runs permanently and completely over HTTPS - the browser remembers
                    the rule up to max-age. Recommended: 31536000 (one year), for a
                    test beforehand 300. Sent only over HTTPS.
                */
                'hsts_max_age' => 0,
                /*
                    HSTS also for all subdomains - only if every subdomain
                    really can do HTTPS.
                */
                'hsts_include_subdomains' => false,
                /*
                    Script policy of the backend (Camindo\Core\SecurityHeaders,
                    developer docs, chapter 14 (Security: web): 'enforce' - only scripts from own
                    files or with the nonce of the page run; 'report' - everything runs,
                    violations end up in var/log/{date}_csp.log; 'off'.
                    'report' for modules/extensions that still write onclick="…".
                */
                'backend_csp' => 'enforce',
                /*
                    ImageMagick (processor Magick): formats that the own policy
                    releases (Camindo\Core\Processors\Magick\MagickPolicy,
                    developer docs, chapter 14 (Security: web). Possible: 'svg', 'pdf'.
                    The policy itself is always active: script formats, delegates,
                    PostScript blocked, limits for memory and image size.
                    PDF runs via Ghostscript - release only if needed.
                    The server's policy may still block a format; the
                    info page of the converter shows whether it really runs.
                */
                'magick_allow' => [],
                /*
                    Password hashing (Crypt::passwordHash(), developer docs, chapter 13 (Security: authentication))
                    Security: authentication):
                    'algo' 'auto' = Argon2id if PHP ships it, otherwise bcrypt;
                    'argon2id' / 'bcrypt' force it. memory_cost (KiB) and time_cost
                    for Argon2id (default = OWASP minimum 19 MiB, 2 passes), cost
                    for bcrypt. Changed values apply to existing hashes at the
                    next login (Crypt::passwordNeedsRehash()).
                */
                'password_hash' => [
                    'algo' => 'auto',
                    'memory_cost' => 19456,
                    'time_cost' => 2,
                    'cost' => 11,
                ],
                /*
                    Password policy (Camindo\Core\PasswordPolicy): always applies to
                    admin passwords, to password fields of the areas if the
                    field carries the extension "Password" (whose options override
                    individual values). 0 = no requirement. Special characters = everything except
                    letters and digits (character class [^\p{L}\p{N}]).
                    upper_not_first: an uppercase letter at the start does not count.
                */
                'password_policy' => PasswordPolicy::DEFAULTS, // 12 characters, 1 special character, 1 uppercase (not at the start), 1 lowercase
                /*
                    Login devices (Camindo\Core\LoginDevices, developer docs, chapter 13 (Security: authentication))
                    Security: authentication): a cookie with a random value recognizes the browser again;
                    if an account logs in from an unknown device, a notice is sent.
                    device_notify: send notice (admins by email, frontend users
                    via the template, $set['new_device']).
                    device_days: devices not used for this long count as
                    unknown and are deleted; also the lifetime of the cookie.
                    login_notifier: class that notifies instead of email (e.g.
                    via an SMS API) - implements Camindo\Core\LoginNotifierInterface;
                    '' = Camindo\Core\LoginNotifier (email to admins).
                */
                'device_notify' => true,
                'device_days' => 180,
                'device_cookie' => 'c9dev',
                'login_notifier' => '',
                /*
                    Second factor of the backend login (developer docs, chapter 13 (Security: authentication))
                    Security: authentication; Camindo\Core\Modules\Admin\TotpFactor):
                    'email'          only the code by email (admin.2_factor);
                                     TOTP cannot be set up, an existing setup is paused
                    'totp'           every admin can set up an authenticator app in
                                     their settings (TOTP, RFC 6238);
                                     whoever does not, gets the email code
                    'totp_required'  like 'totp', superusers MUST have TOTP - whoever
                                     does not have it yet sets it up at the next
                                     login in the login dialog
                    totp_issuer: name under which the account appears in the app
                    (plus login name@host); '' = 'camindo'.
                */
                'admin_2fa' => 'totp',
                'totp_issuer' => '',
                /*
                    Passkeys / security keys (WebAuthn, developer docs, chapter 13 (Security: authentication))
                    Security: authentication) as a second factor in the backend - only with a fixed backend domain
                    (admin.backend_domain). false switches off creating and logging in
                    with a passkey; accounts with passkeys only then log in with a
                    recovery code.
                */
                'webauthn' => true,
                /*
                    Key rotation (`camindo.php key rotate`, Camindo\Core\KeyRotation):
                    period in days during which passwords with the retired key still
                    apply - whoever logs in with them automatically gets a new
                    hash. Afterwards remove the old key with `key retire KID --force`;
                    remaining passwords become invalid. Display only
                    (`key status`), nothing is removed automatically.
                */
                'key_password_grace_days' => 90,
                /*
                    Proxies whose X-Forwarded-For applies (Camindo\Core\ClientIp):
                    list of IP addresses or CIDR ranges, e.g. ['10.0.0.0/8'].
                    Empty = REMOTE_ADDR is the visitor. Behind a proxy it MUST be listed
                    here, otherwise all visitors share the same origin and the
                    throttling per IP hits everyone at once.
                */
                'trusted_proxies' => [],
                /*
                    Script policy of the frontend (SecurityHeaders::frontendPolicy(),
                    developer docs, chapter 14 (Security: web): default for instances without their own
                    setting - 'off', 'report' or 'enforce'.
                */
                'frontend_csp' => 'off',
                /*
                    External captcha for formguard=true (Turnstile, Friendly Captcha …):
                    class with Camindo\Core\FormChallengeInterface; '' = none.
                */
                'form_challenge' => '',
            ],
            'audit' => [
                /*
                    Audit log (Camindo\Core\AuditLog): every change to records,
                    areas, fields, templates, admins, groups … with before/after,
                    plus the logins to the backend. Table `audit_log`.
                */
                'enabled' => true,
                /*
                    Retention of the entries for objects that still exist, in days.
                    0 = unlimited - every record remains traceable back to its creation.
                */
                'retention_days_changes' => 0,
                /*
                    Write successful logins and logouts to the backend into the audit log.
                    Default false: logins without activity say nothing
                    about changes, but they do say something about working hours of employees.
                    Failed logins are always included (and after
                    several failed attempts in the security log).
                */
                'log_logins' => false,
                /*
                    Retention of the logins/logouts and failed attempts in days.
                    0 = as retention_days_changes.
                */
                'retention_days_logins' => 90,
                /*
                    Retention of the history of objects deleted for good, in days,
                    counted from the final deletion (after the trash).
                    0 = unlimited.
                */
                'retention_days_deleted' => 365,
                /*
                    The contents of the audit log are under var/audit/ (AuditStore), one
                    file per object; from this size in bytes a new page begins.
                */
                'page_bytes' => 1048576,
            ],
            'privacy' => [
                /*
                    Truncate IP addresses (Camindo\Core\IpAnonymizer, scheduler task
                    retention): entries older than this many days keep
                    only the network - in the audit log, in the failed login attempts and in
                    the log files under var/log. Known devices (login_device) and
                    the contents of the audit log under var/audit store the IP truncated
                    from the start. 0 = truncate immediately at the next run.
                    To trace a specific attack, raise temporarily
                    (what is already truncated does not come back).
                */
                'ip_anonymize_days' => 7,
                /*
                    How many octets at the end of an IPv4 address are zeroed:
                    1 (203.0.113.45 -> 203.0.113.0) or 2 (-> 203.0.0.0).
                    IPv6 always keeps the first 48 bits (the last 80 are
                    zeroed: 2001:db8:abcd:12::1 -> 2001:db8:abcd::).
                */
                'ip_v4_octets' => 1,
                /*
                    Internal messages (Messaging module) are deleted this many days after
                    sending - for sender and recipient, also unread ones
                    (Message::purgeExpired(), scheduler task retention).
                    0 = never delete automatically.
                */
                'message_days' => 60,
            ],
            'paths' => [
                /*
                    (DEPRECATED - always '')
                    Path to the CMS frontend
                    relative to DOCUMENT_ROOT
                */
                'cms' => '',
                /*
                    Frontend path to the media root directory
                    relative to DOCUMENT_ROOT
                */
                'media' => '/media',
                /*
                    Frontend path to the backend
                    relative to DOCUMENT_ROOT
                */
                'backend' => '/backend',
                /*
                    Absolute path to the CMS frontend (DOCUMENT_ROOT of the web server)
                    Default: camindo/public - stubs, .htaccess, media/, backend/assets symlink;
                    the code lives in Core/public_master (developer docs, chapter 09 (Installation and update))
                */
                'document_root' => $corDir . '/public',
                /*
                    Absolute path to the key file for encryption
                */
                'key_file' => $corDir . '/.camindo.key',
                /*
                    Absolute path to the main directory with write permissions,
                    provided the following directories are contained in it.
                    (The only other directory to which write access happens is 'media')
                */
                'var_dir' => $corDir . '/var',
                /*
                    Absolute path to the directory for temporary files
                */
                'tmp_dir' => $corDir . '/var/tmp',
                /*
                    Absolute path to the upload directory
                    Its contents are shown when clicking the file icon next to each
                    upload field in the record input masks
                    (intended for very large files that are transferred via FTP)
                */
                'upload_dir' => $corDir . '/var/upload',
                /*
                    Absolute path to the .sqlpwd file with the MySQL credentials
                    for mysqldump (prevents entering the password as a shell parameter)
                */
                'sqlpwd_file' => $corDir . '/.sqlpwd',
                /*
                    PHP executable (absolute path)
                */
                'php' => 'php',
                /*
                    mysqldump executable (absolute path) - database backup
                    in the Scheduler module
                */
                'mysqldump' => 'mysqldump',
                /*
                    ImageMagick executable (absolute path)
                    Version < 7 'convert' (identify is called with the same path)
                    Version >= 7 'magick'
                */
                'magick' => 'magick',
                /*
                    ffmpeg executable (absolute path)
                */
                'ffmpeg' => 'ffmpeg',
                /*
                    ffprobe executable (absolute path)
                */
                'ffprobe' => 'ffprobe',
                /*
                    Composer executable (absolute path) - update check
                    (Camindo\Core\UpdateCheck: composer outdated / composer audit).
                    '' = no check
                */
                'composer' => 'composer',
            ],
            'permissions' => [
                /*
                    Unix rights for directories and files created by the CMS
                */
                'dir'  => 0775,
                'file' => 0664,
                /*
                    Rights of .camindo.key and .camindo.key.retired on `key rotate`.
                    Applies only if there is no .camindo.key yet (key from
                    CAMINDO_KEY) - otherwise the rotation takes over the rights of the
                    previous file. 0600 is optimal; where the web server can read the file
                    only via the group, e.g. 0640
                */
                'key_file' => 0600,
            ],
            'db' => [
                /*
                    MySQL credentials
                    Required fields:
                        'host' => '...',
                        'name' => '...',
                        'user' => '...',
                        'pass' => '...',
                */
                'port' => 3306,
                // SQL mode of every connection (SET SESSION, before init_querys). The
                // schema relies on the non-strict mode: NOT NULL columns
                // without DEFAULT that an INSERT omits, and null date values.
                // With MySQL 8's default (STRICT_TRANS_TABLES …) such
                // INSERTs fail. null = leave the server's mode unchanged
                'sql_mode' => 'NO_ENGINE_SUBSTITUTION',
                'init_querys'   => [/*
                    CAUTION with lists: elements are replaced by key, not appended.
                    Use unique keys!
                */],
            ],
        ];

        // Merge with values from .camindo.php
        // array_replace_recursive: .camindo.php wins, missing keys stay at the default.
        $this->settings = array_replace_recursive($defaults, $cfg);

        // Secrets from the environment take precedence
        foreach (self::ENV_SECRETS as $key => $name) {
            $value = self::env($name);
            if ($value === null) continue;
            [$group, $rest] = explode('.', $key, 2);
            $target = &$this->settings[$group];
            foreach (explode('.', $rest) as $k) $target = &$target[$k];
            $target = $value;
            unset($target);
            $this->fromEnv[] = $key;
        }

        // Referenz auf paths-Array
        $paths = &$this->settings['paths'];

        // Extend paths
        // Derived values that cannot be configured - they follow necessarily from the
        // location of this file or from the values above.
        $paths['core_dir'] = dirname(__DIR__);            // .../camindo/Core
        $paths['camindo_dir'] = dirname($paths['core_dir']); // .../camindo
        $paths['media_dir'] = $paths['document_root'] . $paths['media'];
        $paths['backend_dir'] = $paths['document_root'] . $paths['backend'];

        // Redirect the PHP error log
        // Daily files, so that the log does not grow without limit.
        ini_set('error_log', $paths['var_dir'] . '/log/' . date('Y-m-d') . '_php_errors.log');
    }

    /**
     * Read a configuration value, levels separated by a dot.
     *
     *     $config->get('db');                // whole sub-array
     *     $config->get('paths.media_dir');   // single value
     *     $config->get('gibts.nicht');       // null
     *
     * A missing key is not an error but returns null - callers usually set
     * their default afterwards via ??.
     *
     * @param string $key  key in dot syntax
     * @return mixed       value, sub-array or null
     */
    // Getter optimized for speed
    public function get(string $key)
    {
        // 1. Quick check: if there is no dot, access directly
        if (strpos($key, '.') === false) {
            return $this->settings[$key] ?? null;
        }

        // 2. Dot present
        // Use a reference to prevent copying arrays on reassignment
        $value = &$this->settings;

        foreach (explode('.', $key) as $k) {
            if (is_array($value) && isset($value[$k])) {
                $value = &$value[$k];
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Keys from ENV_SECRETS whose value comes from the environment
     * (e.g. ['db.pass']) - for status displays, backup and installer.
     *
     * @return string[]
     */
    public function fromEnv(): array
    {
        return $this->fromEnv;
    }

    /**
     * Read an environment variable - the only way for secrets (see class comment).
     * The value is remembered per process and removed from the process environment, $_ENV and $_SERVER;
     * a second call returns it from the cache.
     *
     * @return string|null  value, null = not set or empty
     */
    public static function env(string $name): ?string
    {
        if (array_key_exists($name, self::$env)) return self::$env[$name];
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }
        $value = is_string($value) && $value !== '' ? $value : null;
        if ($value !== null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        return self::$env[$name] = $value;
    }
}
