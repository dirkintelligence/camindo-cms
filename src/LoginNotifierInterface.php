<?php
/**
 * Notification for security-relevant sign-in events (developer docs, chapter 13 (Security: authentication)):
 * Security: authentication). The default Camindo\Core\LoginNotifier sends admins
 * an email; a project can name its own class via security.login_notifier - for
 * example for an SMS API. It is fetched from the container (autowiring), so it can
 * require Config, Database etc. in its constructor.
 *
 * Example (project code, reachable via Composer autoload):
 *
 *     class SmsNotifier implements \Camindo\Core\LoginNotifierInterface {
 *         public function __construct(private \Camindo\Core\LoginNotifier $mail) {}
 *         public function notify(string $event, string $kind, array $account, array $context): void {
 *             $mobile = (string)($account['mobile'] ?? ''); // Profil: Feldname des Bereichs
 *             if ($mobile === '') { $this->mail->notify($event, $kind, $account, $context); return; }
 *             // … call the SMS API: "New sign-in: {$context['label']}, {$context['ip']}"
 *         }
 *     }
 *
 * The class does not pass errors on: the sign-in has already happened at that point.
 * LoginDevices catches exceptions anyway and writes them to the log.
 */
namespace Camindo\Core;

interface LoginNotifierInterface {

    /**
     * @param string $event    'new_device' (sign-in from an unknown device),
     *                         'password_changed' (frontend: new password set),
     *                         'totp_enabled' / 'totp_disabled' (backend: authenticator
     *                         app switched on or off; $context['reset'] = true
     *                         if a superuser reset it) or
     *                         'recovery_code_used' (backend: sign-in with a
     *                         recovery code; $context['remaining'])
     * @param string $kind     'admin' (backend) or 'user' (frontend profile)
     * @param array  $account  Admin record (Admin::load(), email decrypted) or
     *                         profile record (Cms row of the profile)
     * @param array  $context  label (browser/operating system), ip, time (UTC, Y-m-d H:i:s)
     *                         and per event reset or remaining (see above)
     */
    public function notify(string $event, string $kind, array $account, array $context): void;
}
