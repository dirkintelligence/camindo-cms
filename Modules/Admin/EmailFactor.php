<?php
/**
 * Second factor by email: five-digit code sent to the admin's address
 * (admin.2_factor). The existing logic from Admin::loginByName(), unchanged.
 *
 * The code is stored in plain text in `admin`.`code` - it lives only until the next
 * attempt (Admin::invalidateLoginCode()) or admin.2_factor_expiration.
 *
 * @see Camindo\Core\Modules\Admin\SecondFactorInterface
 */
namespace Camindo\Core\Modules\Admin;

use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\Email;
use PHPMailer\PHPMailer\PHPMailer;

class EmailFactor implements SecondFactorInterface {

    public function __construct(
        private Config $config,
        private Cms $cms,
        private Database $db,
        private Email $email,
    )
    {
    }

    public function name(): string
    {
        return 'email';
    }

    public function start(array $admin): bool
    {
        $adminId = (int)($admin['admin_id'] ?? 0);

        // Is an email address stored for the recipient admin at all?
        if (empty($admin['email'])) {
            $this->cms->error($this->cms->snippet('admin.error_mail_to'));
            return false;
        }
        // Is a sender address configured?
        $emailNoreply = $this->config->get('email.noreply');
        $emailReply = $this->config->get('email.reply');
        if (empty($emailNoreply) && empty($emailReply)) {
            $this->cms->error($this->cms->snippet('admin.error_mail_from'));
            return false;
        }

        // random_int() instead of rand(): the code is a login secret, and the
        // Mersenne Twister behind rand() can be predicted from a few outputs.
        // str_pad so the code is always five digits - otherwise its
        // length would reveal how small the drawn number was.
        $code = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);

        // Save first, then send: if saving fails, the code in the email would be
        // worthless, because verify() cannot find it.
        $this->db->pushResult();
        try {
            $this->db->query("UPDATE `admin` SET `code` = ? WHERE `admin_id` = ?", 'si', [$code, $adminId]);
        }
        finally {
            $this->db->popResult();
        }

        // Text: "Here is your code..."
        $text = $this->cms->snippet('admin.message_login_2factor');
        if (!empty($emailNoreply) && !empty($emailReply)) {
            // Both addresses configured? Then the email is sent from $emailNoreply
            // and the text gets a passage for feedback on unwanted receipt
            $feedbackText = $this->cms->snippet('admin.message_login_2factor_feedback');
            $emailHTML = '<a href="mailto:' . $emailReply . '">' . $emailReply . '</a>';
            $text .= str_replace('{email_reply}', $emailHTML, $feedbackText);
        }

        // Insert text and code into the email template
        $coreDir = $this->config->get('paths.core_dir');
        $body = strtr(file_get_contents($coreDir . '/Modules/Admin/backend/html/email_login_2factor.html'), [
            '{text}' => $text,
            '{code}' => $code,
            '{lang}' => htmlspecialchars(str_replace('_', '-', $this->cms->getLang()), ENT_QUOTES),
            '{dir}' => $this->cms->textDirection(),
        ]);

        $mail = new PHPMailer(true);
        $mail->setFrom($emailNoreply ?: $emailReply, 'camindo CMS');
        $mail->addAddress($admin['email'], $admin['displayname'] ?? '');
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Login PIN';
        $mail->Body    = $body;
        // Versendefunktion, Fallback, Logging, ... zusammengefasst in class Email
        $this->email->send($mail);
        return true;
    }

    /**
     * Compared with $admin['code'] - the state Admin::loginByCode() read before
     * invalidating the uid (the code is already empty in the table by then).
     */
    public function verify(array $admin, string $code): bool
    {
        $code = trim($code);
        $stored = (string)($admin['code'] ?? '');
        // An empty stored code (login already completed) never matches
        return $code !== '' && $stored !== '' && hash_equals($stored, $code);
    }
}
