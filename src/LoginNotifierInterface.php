<?php
/**
 * Benachrichtigung bei sicherheitsrelevanten Anmelde-Ereignissen
 * (16-sicherheit.md §11). Die Vorgabe Camindo\Core\LoginNotifier schreibt Admins
 * eine E-Mail; ein Projekt kann per security.login_notifier eine eigene Klasse
 * nennen - etwa für eine SMS-API. Sie wird aus dem Container geholt (Autowiring),
 * kann also Config, Database usw. im Konstruktor verlangen.
 *
 * Beispiel (Projektcode, per Composer-Autoload erreichbar):
 *
 *     class SmsNotifier implements \Camindo\Core\LoginNotifierInterface {
 *         public function __construct(private \Camindo\Core\LoginNotifier $mail) {}
 *         public function notify(string $event, string $kind, array $account, array $context): void {
 *             $mobile = (string)($account['mobile'] ?? ''); // Profil: Feldname des Bereichs
 *             if ($mobile === '') { $this->mail->notify($event, $kind, $account, $context); return; }
 *             // … SMS-API aufrufen: "Neue Anmeldung: {$context['label']}, {$context['ip']}"
 *         }
 *     }
 *
 * Fehler wirft die Klasse nicht nach außen: die Anmeldung ist in dem Moment schon
 * erfolgt. LoginDevices fängt Ausnahmen trotzdem ab und schreibt sie ins Log.
 */
namespace Camindo\Core;

interface LoginNotifierInterface {

    /**
     * @param string $event    'new_device' (Anmeldung von einem unbekannten Gerät),
     *                         'password_changed' (Frontend: neues Passwort gesetzt),
     *                         'totp_enabled' / 'totp_disabled' (Backend: Authenticator-
     *                         App ein- bzw. ausgeschaltet; $context['reset'] = true,
     *                         wenn ein Superuser zurückgesetzt hat) oder
     *                         'recovery_code_used' (Backend: Anmeldung mit
     *                         Wiederherstellungscode; $context['remaining'])
     * @param string $kind     'admin' (Backend) oder 'user' (Frontend-Profil)
     * @param array  $account  Admin-Datensatz (Admin::load(), E-Mail entschlüsselt) bzw.
     *                         Profil-Datensatz (Cms-Zeile des Profils)
     * @param array  $context  label (Browser/Betriebssystem), ip, time (UTC, Y-m-d H:i:s)
     *                         und je Ereignis reset bzw. remaining (siehe oben)
     */
    public function notify(string $event, string $kind, array $account, array $context): void;
}
