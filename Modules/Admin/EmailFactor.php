<?php
/**
 * Zweiter Faktor per E-Mail: fünfstelliger Code an die Adresse des Admins
 * (admin.2_factor). Die bisherige Logik aus Admin::loginByName(), unverändert.
 *
 * Der Code steht im Klartext in `admin`.`code` - er lebt nur bis zum nächsten
 * Versuch (Admin::invalidateLoginCode()) bzw. admin.2_factor_expiration.
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

        // Ist vom Empfänger-Admin überhaupt eine E-Mail gespeichert?
        if (empty($admin['email'])) {
            $this->cms->error($this->cms->snippet('admin.error_mail_to'));
            return false;
        }
        // Ist eine Absende-Adresse in der Konfiguration eingetragen?
        $emailNoreply = $this->config->get('email.noreply');
        $emailReply = $this->config->get('email.reply');
        if (empty($emailNoreply) && empty($emailReply)) {
            $this->cms->error($this->cms->snippet('admin.error_mail_from'));
            return false;
        }

        // random_int() statt rand(): der Code ist ein Anmeldegeheimnis, und der
        // Mersenne-Twister hinter rand() laesst sich aus wenigen Ausgaben vorhersagen.
        // str_pad, damit der Code immer fuenfstellig ist - sonst waere an seiner
        // Laenge ablesbar, wie klein die gezogene Zahl war.
        $code = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);

        // Erst speichern, dann verschicken: schlaegt das Speichern fehl, waere der
        // Code in der Mail wertlos, weil verify() ihn nicht wiederfindet.
        $this->db->pushResult();
        try {
            $this->db->query("UPDATE `admin` SET `code` = ? WHERE `admin_id` = ?", 'si', [$code, $adminId]);
        }
        finally {
            $this->db->popResult();
        }

        // Text: "Hier ist Ihr Code..."
        $text = $this->cms->snippet('admin.message_login_2factor');
        if (!empty($emailNoreply) && !empty($emailReply)) {
            // Beide Adressen konfiguriert? Dann wird die E-Mail von $emailNoreply versendet
            // und der Text erhält eine Passage für Feedback bei ungewolltem Empfang
            $feedbackText = $this->cms->snippet('admin.message_login_2factor_feedback');
            $emailHTML = '<a href="mailto:' . $emailReply . '">' . $emailReply . '</a>';
            $text .= str_replace('{email_reply}', $emailHTML, $feedbackText);
        }

        // Text und Code in E-Mail-Vorlage einfügen
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
     * Verglichen wird mit $admin['code'] - dem Stand, den Admin::loginByCode() vor
     * dem Entwerten der uid gelesen hat (in der Tabelle ist der Code dann schon leer).
     */
    public function verify(array $admin, string $code): bool
    {
        $code = trim($code);
        $stored = (string)($admin['code'] ?? '');
        // Ein leerer gespeicherter Code (Anmeldung schon abgeschlossen) passt nie
        return $code !== '' && $stored !== '' && hash_equals($stored, $code);
    }
}
