<?php
/**
 * Zweiter Faktor der Backend-Anmeldung (16-sicherheit.md §16).
 *
 * Der Ablauf bleibt für jeden Faktor derselbe - er gehört Admin:
 *
 *     loginByName()   Passwort stimmt -> uid + uid_date setzen -> start()
 *     loginByCode()   uid im Zeitfenster gefunden -> LoginGuard -> verify()
 *
 * Ein Faktor entscheidet nur, wie der Code entsteht und wie er geprüft wird. Die uid
 * (ein Versuch je uid), das Zeitfenster admin.2_factor_expiration, das Zählen der
 * Fehlversuche (LoginGuard, admin.bruteforce_max) und die Session macht Admin.
 *
 * Welcher Faktor gilt, entscheidet Admin::secondFactor() je Admin: TOTP, wenn der
 * Admin es eingerichtet hat und security.admin_2fa es zulässt, sonst E-Mail, wenn
 * admin.2_factor an ist.
 *
 * @see Camindo\Core\Modules\Admin\EmailFactor  Code per E-Mail
 * @see Camindo\Core\Modules\Admin\TotpFactor   Authenticator-App (RFC 6238)
 */
namespace Camindo\Core\Modules\Admin;

interface SecondFactorInterface {

    /** Kennung: 'email' | 'totp' - wählt u. a. den Hinweistext im Code-Formular */
    public function name(): string;

    /**
     * Zweite Stufe vorbereiten, nachdem das Passwort gestimmt hat und die uid steht.
     *
     * @param array $admin  Admin::load() (E-Mail entschlüsselt), mit uid
     * @return bool         false: Fehler in Cms::errors, die Anmeldung endet hier
     * @throws \Throwable   wenn der Versand scheitert (StartBackend fängt das ab)
     */
    public function start(array $admin): bool;

    /**
     * Eingegebenen Code prüfen. Das Zählen von Fehlversuchen übernimmt Admin.
     *
     * @param array  $admin  Admin::load()
     * @param string $code   Eingabe, ungetrimmt
     */
    public function verify(array $admin, string $code): bool;
}
