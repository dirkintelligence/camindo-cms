<?php
/**
 * Zusammenfassung der internen Nachrichten per E-Mail (Zeitplaner-Aufgabe
 * messagedigest, Vorgabe täglich 7:00).
 *
 * Wer in seinen persönlichen Einstellungen `settings.message_digest` = 1 gewählt hat,
 * bekommt an seine E-Mail-Adresse eine Übersicht - aber nur, wenn seit dem letzten
 * Lauf neue, ungelesene Nachrichten eingegangen sind:
 *
 *     ungelesen nach Absender   Anzahl ALLER ungelesenen Nachrichten je Absender
 *     neue Nachrichten          Datum, Absender und Betreff der seit dem letzten Lauf
 *                               eingegangenen (höchstens MAX_LISTED)
 *
 * Nie in der E-Mail: Nachrichtentext (body, html), Bezug auf einen Datensatz
 * (record_id) und ein Link ins Backend. Gelesen werden die Nachrichten nur nach der
 * Anmeldung im Backend; eine E-Mail ohne Link lässt sich auch nicht für Phishing
 * nachahmen, ohne aufzufallen.
 *
 * "Neu" heißt: created_at nach $since - der Zeitpunkt des vorigen Laufs der Aufgabe
 * (SchedulerRunner). Ein Gelesen-Kennzeichen oder Zustand je Benutzer wird nicht
 * gespeichert; die admin-Zeile bleibt unberührt (sie ist signiert und erschiene
 * sonst täglich im Audit-Log).
 *
 * Empfänger: aktive, nicht gelöschte Benutzer mit E-Mail-Adresse. Das Modul
 * Messaging steht jeder Gruppe offen (Access::module()), eine Rechteprüfung entfällt.
 * Sprache, Locale und Zeitzone der E-Mail stammen aus den Einstellungen des
 * Empfängers.
 *
 * @see Camindo\Core\Modules\Messaging\Message
 * @see Camindo\Core\Modules\Scheduler\SchedulerRunner
 */
namespace Camindo\Core\Modules\Messaging;

use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Database;
use Camindo\Core\Email;
use Camindo\Core\System;
use Camindo\Core\Modules\Admin\Admin;
use PHPMailer\PHPMailer\PHPMailer;

class MessageDigest {

    /** Höchstzahl der einzeln aufgeführten neuen Nachrichten */
    public const MAX_LISTED = 50;

    public function __construct(
        private Database $db,
        private Config $config,
        private Cms $cms,
        private Email $email,
        private Admin $admin,
        private System $system,
    )
    {
    }

    /**
     * Zusammenfassungen verschicken.
     *
     * @param string $since  UTC "Y-m-d H:i:s" - Nachrichten danach gelten als neu
     * @return array  sent, skipped (keine neuen Nachrichten oder keine E-Mail-Adresse),
     *                failed (Liste aus "admin_id: Fehler")
     */
    public function run(string $since): array
    {
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => []];
        foreach ($this->subscribers() as $adminId) {
            try {
                $this->sendTo($adminId, $since) ? $result['sent']++ : $result['skipped']++;
            }
            catch (\Throwable $e) {
                $result['failed'][] = "$adminId: " . $e->getMessage();
                $this->system->log('messaging', "message digest for admin $adminId: " . $e->getMessage());
            }
        }
        return $result;
    }

    /**
     * Benutzer mit eingeschalteter Zusammenfassung (aktiv, nicht gelöscht).
     *
     * @return int[]
     */
    public function subscribers(): array
    {
        $ids = [];
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `admin_id`, `settings` FROM `admin` WHERE `status_id` = 0 AND `deleted` = 0");
            while ($row = $this->db->fetch()) {
                $settings = json_decode((string)$row['settings'], true);
                if ((int)($settings['message_digest'] ?? 0) === 1) $ids[] = (int)$row['admin_id'];
            }
        }
        finally {
            $this->db->popResult();
        }
        return $ids;
    }

    /**
     * Zusammenfassung an einen Benutzer.
     *
     * @return bool  false: nichts Neues oder keine E-Mail-Adresse
     */
    public function sendTo(int $adminId, string $since): bool
    {
        $new = $this->rows(
            "SELECT `created_at`, `created_by`, `subject` FROM `message`
             WHERE `to_admin_id` = ? AND `is_read` = 0 AND `recipient_deleted` = 0 AND `created_at` > ?
             ORDER BY `created_at` ASC, `message_id` ASC",
            'is', [$adminId, $since]
        );
        if (!$new) return false;

        $account = $this->admin->load($adminId);
        $to = trim((string)($account['email'] ?? ''));
        if (!$account || $to === '') return false;
        $from = (string)($this->config->get('email.noreply') ?: $this->config->get('email.reply'));
        if ($from === '') throw new \RuntimeException('email.noreply / email.reply not configured');

        $bySender = $this->rows(
            "SELECT `created_by`, COUNT(*) AS `count` FROM `message`
             WHERE `to_admin_id` = ? AND `is_read` = 0 AND `recipient_deleted` = 0
             GROUP BY `created_by` ORDER BY `count` DESC",
            'i', [$adminId]
        );

        $settings = (array)($account['settings'] ?? []);
        $lang = (string)($settings['backend_code'] ?? '');
        $lang = $lang !== '' ? (Cms::matchLanguage($lang, $this->cms->backendLanguages()) ?? '') : '';
        $snippet = fn(string $key, array $vars = []) => $this->cms->snippet('messaging.' . $key, $vars, $lang);
        $formatter = $this->dateFormatter($settings);

        $total = array_sum(array_map(fn($row) => (int)$row['count'], $bySender));
        $subject = $snippet('digest_subject', ['count' => count($new)]);

        $senderRows = '';
        foreach ($bySender as $row) {
            $senderRows .= '<tr><td>' . htmlspecialchars($this->senderName($row['created_by'], $snippet)) . '</td>'
                . '<td style="text-align:end">' . (int)$row['count'] . '</td></tr>';
        }
        $newRows = '';
        foreach (array_slice($new, 0, self::MAX_LISTED) as $row) {
            $date = new \DateTimeImmutable((string)$row['created_at'], new \DateTimeZone('UTC'));
            $newRows .= '<tr><td>' . htmlspecialchars((string)$formatter->format($date)) . '</td>'
                . '<td>' . htmlspecialchars($this->senderName($row['created_by'], $snippet)) . '</td>'
                . '<td>' . htmlspecialchars((string)$row['subject']) . '</td></tr>';
        }
        if (count($new) > self::MAX_LISTED) {
            $newRows .= '<tr><td colspan="3">' . htmlspecialchars($snippet('digest_more', ['count' => count($new) - self::MAX_LISTED])) . '</td></tr>';
        }

        $body = strtr((string)file_get_contents(__DIR__ . '/backend/html/email_digest.html'), [
            '{lang}'           => htmlspecialchars(str_replace('_', '-', $lang !== '' ? $lang : $this->cms->getLang()), ENT_QUOTES),
            '{dir}'            => $this->cms->textDirection($lang),
            '{subject}'        => htmlspecialchars($subject),
            '{intro}'          => htmlspecialchars($snippet('digest_intro', ['count' => count($new)]) . ' '
                . strtr($snippet('digest_total'), ['{total}' => (string)$total])),
            '{heading_sender}' => htmlspecialchars($snippet('digest_heading_sender')),
            '{label_sender}'   => htmlspecialchars($snippet('digest_label_sender')),
            '{label_count}'    => htmlspecialchars($snippet('digest_label_count')),
            '{sender_rows}'    => $senderRows,
            '{heading_new}'    => htmlspecialchars($snippet('digest_heading_new')),
            '{label_date}'     => htmlspecialchars($snippet('digest_label_date')),
            '{label_subject}'  => htmlspecialchars($snippet('digest_label_subject')),
            '{new_rows}'       => $newRows,
            '{footer}'         => htmlspecialchars($snippet('digest_footer')),
            '{unsubscribe}'    => htmlspecialchars($snippet('digest_unsubscribe')),
        ]);

        $mail = new PHPMailer(true);
        $mail->setFrom($from, 'camindo CMS');
        $mail->addAddress($to, (string)($account['displayname'] ?? ''));
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $this->email->send($mail);
        return true;
    }

    /** Anzeigename des Absenders; ohne Absender (gelöschtes Konto) "System" */
    private function senderName(mixed $adminId, callable $snippet): string
    {
        static $names = [];
        $adminId = (int)$adminId;
        if ($adminId < 1) return $snippet('digest_sender_system');
        if (!isset($names[$adminId])) {
            $rows = $this->rows("SELECT `name`, `displayname` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
            $names[$adminId] = trim((string)($rows[0]['displayname'] ?? '')) ?: (string)($rows[0]['name'] ?? '');
        }
        return $names[$adminId] !== '' ? $names[$adminId] : $snippet('digest_sender_system');
    }

    /** Datum und Uhrzeit in Locale und Zeitzone des Empfängers */
    private function dateFormatter(array $settings): \IntlDateFormatter
    {
        $locale = (string)($settings['locale'] ?? '') ?: (string)$this->config->get('system.locale');
        $timezone = (string)($settings['timezone'] ?? '') ?: (string)$this->config->get('system.timezone');
        try {
            $tz = new \DateTimeZone($timezone);
        }
        catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }
        return new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, $tz);
    }

    /** Alle Zeilen einer Abfrage (eigener Ergebnisstapel) */
    private function rows(string $sql, string $types, array $params): array
    {
        $this->db->pushResult();
        try {
            $this->db->query($sql, $types, $params);
            $rows = [];
            while ($row = $this->db->fetch()) $rows[] = $row;
            return $rows;
        }
        finally {
            $this->db->popResult();
        }
    }
}
