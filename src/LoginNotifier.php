<?php
/**
 * Vorgabe für security.login_notifier (16-sicherheit.md §11, §16): E-Mail an den
 * Admin, wenn er sich von einem unbekannten Gerät anmeldet oder jemand seinen
 * zweiten Faktor ändert:
 *
 *     new_device          Anmeldung von einem unbekannten Gerät
 *     totp_enabled        Authenticator-App eingerichtet
 *     totp_disabled       Authenticator-App abgeschaltet - vom Admin selbst oder,
 *                         mit $context['reset'], von einem Superuser zurückgesetzt
 *     recovery_code_used  Anmeldung mit einem Wiederherstellungscode
 *                         ($context['remaining'] = noch übrige)
 *     email_factor_on     Code per E-Mail als eigener zweiter Faktor eingeschaltet
 *     email_factor_off    … wieder abgeschaltet
 *     password_changed    eigenes Passwort geändert (AdminBackend::password())
 *     passkey_added       Passkey hinzugefügt ($context['passkey'] = Bezeichnung)
 *     passkey_removed     Passkey entfernt - mit $context['reset'] alle, durch
 *                         einen Superuser
 *
 * Texte: admin.subject_{Ereignis} und admin.message_{Ereignis} (beim Zurücksetzen
 * admin.message_totp_reset).
 *
 * Frontend-User bekommen von hier nichts - dort versendet das Template
 * (USER_LOGIN setzt $set['new_device'], USER_INPUT $set['password_changed'];
 * E-Mail mit CMS:EMAIL wie beim Reset-Link). Ein eigener Notifier kann beides
 * übernehmen (LoginNotifierInterface).
 */
namespace Camindo\Core;

use PHPMailer\PHPMailer\PHPMailer;

class LoginNotifier implements LoginNotifierInterface {

    public function __construct(
        private Config $config,
        private Cms $cms,
        private Email $email,
    )
    {
    }

    /** Ereignisse, zu denen ein Admin eine E-Mail bekommt */
    public const ADMIN_EVENTS = ['new_device', 'totp_enabled', 'totp_disabled', 'recovery_code_used',
        'email_factor_on', 'email_factor_off', 'password_changed', 'passkey_added', 'passkey_removed'];

    public function notify(string $event, string $kind, array $account, array $context): void
    {
        if ($kind !== 'admin' || !in_array($event, self::ADMIN_EVENTS, true)) return;
        $to = trim((string)($account['email'] ?? ''));
        $from = (string)($this->config->get('email.noreply') ?: $this->config->get('email.reply'));
        if ($to === '' || $from === '') return;

        // Sprache des Admins (persönliche Einstellungen), sonst die laufende
        $lang = (string)($account['settings']['backend_code'] ?? '');
        $lang = $lang !== '' ? (Cms::matchLanguage($lang, $this->cms->backendLanguages()) ?? '') : '';
        $snippet = fn(string $key, array $vars = []) => $this->cms->snippet('admin.' . $key, $vars, $lang);
        $messageKey = match (true) {
            $event === 'totp_disabled' && !empty($context['reset'])   => 'message_totp_reset',
            $event === 'passkey_removed' && !empty($context['reset']) => 'message_passkey_reset',
            default => 'message_' . $event,
        };
        $text = strtr($snippet($messageKey, ['remaining' => (int)($context['remaining'] ?? 0)]), [
            '{name}'   => htmlspecialchars((string)($account['name'] ?? '')),
            '{device}' => htmlspecialchars((string)($context['label'] ?? '')),
            '{ip}'     => htmlspecialchars((string)($context['ip'] ?? '')),
            '{time}'   => htmlspecialchars((string)($context['time'] ?? '') . ' UTC'),
            '{passkey}' => htmlspecialchars((string)($context['passkey'] ?? '')),
        ]);
        $reply = (string)$this->config->get('email.reply');
        if ($reply !== '') {
            $text .= '</p><p>' . strtr($snippet('message_new_device_feedback'), [
                '{email_reply}' => '<a href="mailto:' . htmlspecialchars($reply) . '">' . htmlspecialchars($reply) . '</a>',
            ]);
        }
        $subject = $snippet('subject_' . $event);
        $body = strtr((string)file_get_contents($this->config->get('paths.core_dir') . '/Modules/Admin/backend/html/email_new_device.html'), [
            '{subject}' => htmlspecialchars($subject),
            '{text}'    => $text,
            '{lang}'    => htmlspecialchars(str_replace('_', '-', $lang !== '' ? $lang : $this->cms->getLang()), ENT_QUOTES),
            '{dir}'     => $this->cms->textDirection($lang),
        ]);

        $mail = new PHPMailer(true);
        $mail->setFrom($from, 'camindo CMS');
        $mail->addAddress($to, (string)($account['displayname'] ?? ''));
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $this->email->send($mail);
    }
}
