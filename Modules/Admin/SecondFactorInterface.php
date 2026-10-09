<?php
/**
 * Second factor of the backend login (developer docs, chapter 13 (Security: authentication)).
 *
 * The process stays the same for every factor - it belongs to Admin:
 *
 *     loginByName()   Passwort stimmt -> uid + uid_date setzen -> start()
 *     loginByCode()   uid found within the time window -> LoginGuard -> verify()
 *
 * A factor only decides how the code is created and how it is checked. The uid
 * (one attempt per uid), the time window admin.2_factor_expiration, the counting of
 * failed attempts (LoginGuard, admin.bruteforce_max) and the session are handled by Admin.
 *
 * Which factor applies is decided per admin by Admin::secondFactor(): TOTP, if the
 * admin has set it up and security.admin_2fa allows it, otherwise email, if
 * admin.2_factor is on.
 *
 * @see Camindo\Core\Modules\Admin\EmailFactor  Code per E-Mail
 * @see Camindo\Core\Modules\Admin\TotpFactor   Authenticator-App (RFC 6238)
 */
namespace Camindo\Core\Modules\Admin;

interface SecondFactorInterface {

    /** Identifier: 'email' | 'totp' - selects, among other things, the hint text in the code form */
    public function name(): string;

    /**
     * Prepare the second step, after the password matched and the uid exists.
     *
     * @param array $admin  Admin::load() (email decrypted), with uid
     * @return bool         false: errors in Cms::errors, the login ends here
     * @throws \Throwable   if sending fails (StartBackend catches this)
     */
    public function start(array $admin): bool;

    /**
     * Check the entered code. Admin handles the counting of failed attempts.
     *
     * @param array  $admin  Admin::load()
     * @param string $code   Eingabe, ungetrimmt
     */
    public function verify(array $admin, string $code): bool;
}
