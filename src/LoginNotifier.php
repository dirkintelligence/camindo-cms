<?php
/**
 * Default for security.login_notifier (developer docs, chapter 13 (Security: authentication)): Security:
 * authentication): email to the admin when they sign in from an unknown device or
 * someone changes their second factor:
 *
 *     new_device          Sign-in from an unknown device
 *     totp_enabled        Authenticator app set up
 *     totp_disabled       Authenticator app switched off - by the admin themselves or,
 *                         with $context['reset'], reset by a superuser
 *     recovery_code_used  Sign-in with a recovery code
 *                         ($context['remaining'] = number still left)
 *     email_factor_on     Code by email switched on as a separate second factor
 *     email_factor_off    … switched off again
 *     password_changed    Own password changed (AdminBackend::password())
 *     passkey_added       Passkey added ($context['passkey'] = name)
 *     passkey_removed     Passkey removed - with $context['reset'] all of them, by
 *                         a superuser
 *
 * Texts: admin.subject_{event} and admin.message_{event} (on reset
 * admin.message_totp_reset).
 *
 * Frontend users get nothing from here - there the template sends it
 * (USER_LOGIN sets $set['new_device'], USER_INPUT $set['password_changed'];
 * email with CMS:EMAIL as with the reset link). A custom notifier can take over
 * both (LoginNotifierInterface).
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

    /** Events for which an admin gets an email */
    public const ADMIN_EVENTS = ['new_device', 'totp_enabled', 'totp_disabled', 'recovery_code_used',
        'email_factor_on', 'email_factor_off', 'password_changed', 'passkey_added', 'passkey_removed'];

    public function notify(string $event, string $kind, array $account, array $context): void
    {
        if ($kind !== 'admin' || !in_array($event, self::ADMIN_EVENTS, true)) return;
        $to = trim((string)($account['email'] ?? ''));
        $from = (string)($this->config->get('email.noreply') ?: $this->config->get('email.reply'));
        if ($to === '' || $from === '') return;

        // Language of the admin (personal settings), otherwise the current one
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
