<?php
/**
 * class Email
 *
 * E-Mail-Versand vom CMS. Nutzt als Klasse selbst PHPMailer
 * Ist unter email.smtp ein Postfach konfiguriert, wird darüber gemailt;
 * Fallback ist mail() - das aber nur, wenn auch ein MTA vorhanden ist.
 * Logged Fehler mit System::log('mail',...)
 * 
 * GEPLANT:
 * Logging von E-Mails, wenn log.emails aktiviert ist, beispielsweise für Debugging-Zwecke
 * 
 * VERWENDUNG
 * --------------------
 * Die Klasse wird nicht im Konstruktor eingesammelt, sondern erst beim ersten Zugriff
 * nachgeladen (siehe LazyServicesTrait, Beispiel: Modules/Admin/Admin.php):
 *
 * $mail = new PHPMailer(true);
 * $mail->setFrom($emailNoreply ?: $emailReply, 'camindo CMS');
 * $mail->addAddress($data['email'], $data['displayname'] ?? '');
 * (...)
 * $this->email->send($mail);
 *
 * CMS:EMAIL
 * --------------------
 * cmsEmail() ist die Methode des Template-Befehls (Modules/Template/commands.php,
 * Eintrag 'email'): Pflichtfelder prüfen, Mail aus Parametern bzw. Templates bauen,
 * über send() verschicken. Die Klasse wird dafür im Frontend über Page::$lazyServices
 * nachgeladen; Cms und Page holt sie erst beim Aufruf aus dem Container, weil
 * send() allein (2FA-Code im Backend) beides nicht braucht.
 */

namespace Camindo\Core;
use PHPMailer\PHPMailer\PHPMailer;

class Email {
    /**
     * CMS:EMAIL attachment: nur Dateien aus diesen Verzeichnissen unter var/ -
     * config, log, dbbackup & Co. sollen nicht per Template-Parameter an eine
     * beliebige Adresse gehen können.
     */
    private const ATTACHMENT_DIRS = ['download', 'upload', 'modules'];

    /** CMS:EMAIL: Obergrenze der Empfänger je Befehl */
    private const MAX_RECIPIENTS = 20;

    public function __construct(
        private Config $config,
        private Database $db,
        private Crypt $crypt,
        private Session $session,
        private System $system,
        private ContainerInterface $container,
    )
    {
    }

    public function send($mail)
    {
        if (!class_exists(PHPMailer::class)) {
            throw new \Exception('PHPMailer is not installed. Use composer to install.');
        }
        // Mindestens eine der zwei Absendeadressen muss konfiguriert sein
        $emailNoreply = $this->config->get('email.noreply');
        $emailReply = $this->config->get('email.reply');
        if (empty($emailNoreply) && empty($emailReply)) {
            throw new \Exception('Config incomplete: email address is not configured.');
        }

        $smtp = $this->config->get('email.smtp');
        if (!empty($smtp['host'])) {
            // SMTP-Postfach konfiguriert
            try {
                $this->configureSmtp($mail, $smtp);
                $mail->send();
                return;

            } catch (\Throwable $e) {
                // SMTP-Versand fehlgeschlagen: erst protokollieren, dann mail() versuchen.
                // Ohne diese Zeile bliebe der eigentliche Grund (falsches Passwort,
                // Zertifikatsfehler, gesperrter Port) unsichtbar, weil gleich darauf
                // der Fallback mit einer ganz anderen Meldung scheitert.
                $this->system->log('mail', 'SMTP send failed, falling back to mail(). To: '
                    . $this->recipients($mail) . ', Subject: ' . $mail->Subject
                    . ', Error: ' . ($mail->ErrorInfo ?: $e->getMessage()));
            }
        }

        // Kein SMTP-Postfach konfiguriert oder der SMTP-Versand ist gescheitert
        $this->sendByMailFunction($mail);
    }

    /**
     * PHPMailer auf SMTP-Versand umstellen.
     *
     * 'enc' entscheidet über den Transportweg:
     *     'starttls'  unverschlüsselt verbinden, dann per STARTTLS hochstufen (Port 587)
     *     'smtps'     von Anfang an TLS (Port 465)
     * Ohne 'user' wird nicht authentifiziert - das deckt Relays ab, die ohne
     * Anmeldung annehmen.
     *
     * @param array $smtp  Teilarray email.smtp aus .camindo.php
     */
    private function configureSmtp($mail, array $smtp): void
    {
        $mail->isSMTP();
        $mail->Host       = $smtp['host'];
        $mail->Username   = $smtp['user'] ?? '';
        $mail->Password   = $smtp['pass'] ?? '';
        $mail->SMTPAuth   = !empty($mail->Username);
        $mail->SMTPSecure = ($smtp['enc'] ?? '') == 'starttls'
            ? PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = (int)($smtp['port'] ?? 587);

        // Zeitlimit je Verbindungsschritt. PHPMailers Standard von 300 s würde einen
        // Web-Request bei einem nicht erreichbaren Postfach minutenlang blockieren.
        $mail->Timeout    = 15;

        // Den SMTP-Dialog ins Log schreiben, solange system.development_version gesetzt
        // ist - sonst ist bei Anmeldeproblemen (falsches Passwort, Zertifikat, Port)
        // nichts zu sehen.
        //
        // Die Nachricht selbst bleibt dabei draussen: zwischen dem DATA-Kommando und
        // der abschliessenden Zeile "." laeuft der komplette Body ueber die Leitung,
        // bei der Zwei-Faktor-Anmeldung also der Login-Code im Klartext. Der hat in
        // einer Logdatei nichts verloren, die Kopfzeilen des Dialogs genuegen zur
        // Fehlersuche. Zugangsdaten maskiert PHPMailer selbst ([credentials hidden]).
        if ($this->config->get('system.development_version')) {
            $mail->SMTPDebug   = 2;   // Client- und Server-Zeilen
            $inData = false;
            $mail->Debugoutput = function ($str, $level) use (&$inData) {
                $line = rtrim($str);
                if ($inData) {
                    // Ende des Bodys ist die Zeile mit dem einzelnen Punkt
                    if ($line === 'CLIENT -> SERVER: .') {
                        $inData = false;
                        $this->system->log('mail', 'SMTP: CLIENT -> SERVER: [message body omitted]');
                    }
                    return;
                }
                if ($line === 'CLIENT -> SERVER: DATA') {
                    $inData = true;
                }
                $this->system->log('mail', 'SMTP: ' . $line);
            };
        }
    }

    /**
     * Fallback-Versand über die PHP-Funktion mail().
     *
     * @throws \Exception wenn auch das fehlschlägt (z. B. Server ohne Sendmail/MTA)
     */
    private function sendByMailFunction($mail): void
    {
        // In Containern ohne MTA existiert mail() zwar, hat aber kein sendmail dahinter.
        // Dann gar nicht erst senden, sondern mit einer Meldung abbrechen, die den
        // wirklichen Grund nennt.
        if (!function_exists('mail') || !$this->hasLocalMta()) {
            $this->system->log('mail', 'No local MTA available and SMTP not usable. To: '
                . $this->recipients($mail) . ', Subject: ' . $mail->Subject);
            throw new \Exception('The email could not be sent: no working SMTP mailbox and no local mail transport.');
        }

        try {
            $mail->isMail();
            $mail->send();

        } catch (\Throwable $e) {
            $this->system->log('mail', 'Email could not be sent. To: ' . $this->recipients($mail) . ', Subject: ' . $mail->Subject . ', Error: ' . ($mail->ErrorInfo ?: $e->getMessage()));
            throw new \Exception('The email could not be sent.');
        }
    }

    /**
     * Gibt es überhaupt ein sendmail-Binary, das mail() aufrufen könnte?
     *
     * PHP meldet den Versand über mail() auch dann als erfolgreich, wenn gar kein MTA
     * installiert ist - die Nachricht verschwindet dann spurlos. Der Blick auf
     * sendmail_path erspart diesen Fall.
     */
    private function hasLocalMta(): bool
    {
        $sendmailPath = (string)ini_get('sendmail_path');
        if ($sendmailPath === '') {
            return false;
        }
        // sendmail_path enthält Binary und Argumente, z. B. "/usr/sbin/sendmail -t -i"
        $binary = strtok(trim($sendmailPath), ' ');
        return $binary !== false && is_executable($binary);
    }

    /**
     * Empfängeradressen des Mails als kommaseparierte Liste - nur für die Logzeile.
     */
    private function recipients($mail): string
    {
        $addresses = [];
        foreach ($mail->getToAddresses() as $to) {
            $addresses[] = $to[0];
        }
        return implode(', ', $addresses);
    }

    // ====================
    // CMS:EMAIL

    /**
     * CMS:EMAIL - E-Mail aus einem Formular versenden.
     *
     * Ablauf:
     *   1. 'submit' leer -> der Befehl tut nichts (erster Aufruf des Formulars)
     *   2. 'requiredfields': jedes genannte Feld aus dem Request-Array 'data'
     *      (Standard data[...]) muss einen Wert haben
     *   3. Empfänger 'to' (ein Wert, Kommaliste oder to[]=…), Absender 'from' -
     *      ohne Angabe email.noreply bzw. email.reply aus der Konfiguration
     *   4. Betreff und Text: 'subjecttemplate'/'bodytemplate' vor 'subject'/'body';
     *      Templates laufen im laufenden Request (Page::renderTemplateFile()) und
     *      sehen $cms, $set und $_POST
     *   5. contenttype="text/html": Klartext-Alternative wird erzeugt,
     *      paths_absolute=true macht serverrelative Pfade absolut ('sender_url',
     *      sonst Protokoll und Domain der Instanz)
     *   6. 'attachment': Dateien relativ zu var/, nur aus ATTACHMENT_DIRS
     *   7. debug=true: nur ins Log (var/log/{Y-m-d}_mail-debug.log), kein Versand
     *
     * Fehler landen in Cms::error() (CMS:MESSAGE); der Text kommt aus
     * message[required|throttled|to|from|content|attachment|send], sonst aus dem Snippet
     * error_email_{index}. Das Template läuft dann weiter (Formular erneut zeigen).
     *
     * Hinterlässt $set['email_sent'] (bool) und $set['email_missing'] (Namen der
     * fehlenden Pflichtfelder).
     *
     * Aufrufkonvention wie bei den cms*-Methoden in Cms: ein Parameter, das Array $_a
     * mit den Befehlsparametern.
     *
     * @return string  successtemplate nach erfolgreichem Versand, sonst '' -
     *                 php_suffix include_and_stop bindet es ein und beendet die Seite
     * @see Camindo\Core\Modules\Template\commands.php  Eintrag 'email'
     */
    public function cmsEmail($p): string
    {
        $p = (array)$p;
        $GLOBALS['set']['email_sent'] = false;
        $GLOBALS['set']['email_missing'] = [];
        if (trim((string)($p['submit'] ?? '')) === '') return '';

        $cms = $this->container->get(Cms::class);

        // Pflichtfelder
        // --------------------
        $missing = $this->missingFields($p);
        if ($missing) {
            $GLOBALS['set']['email_missing'] = $missing;
            $this->fail($cms, $p, 'required');
            return '';
        }

        // Formularschutz und Drosselung (developer_docs/20-frontend-schutz.md A):
        // formguard=true verlangt Zeitstempel + leeres Köderfeld aus
        // (CMS:USER_TOKEN formguard=true); throttle=true bzw. throttle=bucket zählt
        // je Herkunft in user.throttle.email_ip bzw. user.throttle.{bucket}.
        // Beides antwortet gleich ('throttled') - ein Robot soll nicht lernen,
        // woran es lag.
        if (!$this->throttleOk($p)) {
            $GLOBALS['set']['throttled'] = 1;
            $this->fail($cms, $p, 'throttled');
            return '';
        }

        // Empfänger und Absender
        // --------------------
        $to = $this->addressList($p['to'] ?? '');
        if (!$to) {
            $this->fail($cms, $p, 'to');
            return '';
        }
        $from = $this->headerText((string)($p['from'] ?? ''));
        if ($from === '') {
            $from = (string)($this->config->get('email.noreply') ?: $this->config->get('email.reply'));
        }
        if (!PHPMailer::validateAddress($from)) {
            $this->fail($cms, $p, 'from');
            return '';
        }

        // Inhalt
        // --------------------
        $isHtml = strtolower(trim((string)($p['contenttype'] ?? ''))) === 'text/html';
        try {
            $subject = $this->emailSubject($cms, $p);
            $body = $this->emailBody($cms, $p);
        }
        catch (\Throwable $e) {
            $this->system->log('mail', 'CMS:EMAIL template error: ' . $e->getMessage());
            $this->fail($cms, $p, 'content');
            return '';
        }
        if ($isHtml && $this->isTrue($p['paths_absolute'] ?? false)) {
            $body = $this->absolutePaths($body, $this->senderUrl($cms, (string)($p['sender_url'] ?? '')));
        }

        $attachments = $this->attachmentFiles($p['attachment'] ?? []);
        if ($attachments === null) {
            $this->fail($cms, $p, 'attachment');
            return '';
        }

        // Mail bauen
        // --------------------
        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($from, $this->headerText((string)($p['fromname'] ?? '')));
            // 'toname' nur bei genau einem Empfänger - ein Name für mehrere Adressen
            // wäre bei allen außer einem falsch
            $toName = count($to) === 1 ? $this->headerText((string)($p['toname'] ?? '')) : '';
            foreach ($to as $address) {
                $mail->addAddress($address, $toName);
            }
            $mail->Subject = $subject;
            if ($isHtml) {
                $mail->isHTML(true);
                $mail->Body = $body;
                $mail->AltBody = $mail->html2text($body);
            }
            else {
                $mail->Body = $body;
            }
            foreach ($attachments as $file) {
                $mail->addAttachment($file);
            }
        }
        catch (\Throwable $e) {
            $this->system->log('mail', 'CMS:EMAIL invalid message: ' . $e->getMessage());
            $this->fail($cms, $p, 'send');
            return '';
        }

        // Versand (oder nur Log)
        // --------------------
        if ($this->isTrue($p['debug'] ?? false)) {
            // Eigenes Log: kein Fehler, darf nicht unter "nicht versendete E-Mails"
            // (Modul Logfiles, Kategorie mail) auftauchen
            $this->system->log('mail-debug', 'CMS:EMAIL debug (not sent). From: ' . $from
                . ', To: ' . implode(', ', $to) . ', Subject: ' . $subject
                . ($attachments ? ', Attachments: ' . implode(', ', array_map('basename', $attachments)) : '')
                . "\n" . $body);
        }
        else {
            try {
                $this->send($mail);
            }
            catch (\Throwable $e) {
                // send() hat die Ursache bereits geloggt
                $this->fail($cms, $p, 'send');
                return '';
            }
        }

        $GLOBALS['set']['email_sent'] = true;
        return $cms->templateFile((string)($p['successtemplate'] ?? ''));
    }

    /**
     * Namen der Pflichtfelder ('requiredfields'), die im Request-Array 'data' leer
     * sind. Ein Feld mit Unterwerten (Checkbox-Gruppe) gilt als gefüllt, wenn
     * mindestens ein Wert nicht leer ist.
     *
     * @return string[]
     */
    private function missingFields(array $p): array
    {
        $fields = is_array($p['requiredfields'] ?? null)
            ? $p['requiredfields']
            : explode(',', (string)($p['requiredfields'] ?? ''));
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($p['data'] ?? 'data')) ?: 'data';
        $data = $_POST[$name] ?? $_REQUEST[$name] ?? [];
        if (!is_array($data)) $data = [];

        $missing = [];
        foreach ($fields as $field) {
            $field = trim((string)$field);
            if ($field === '') continue;
            if (!$this->hasValue($data[$field] ?? null)) $missing[] = $field;
        }
        return $missing;
    }

    private function hasValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasValue($item)) return true;
            }
            return false;
        }
        return trim((string)$value) !== '';
    }

    /**
     * 'to' als Liste gültiger Adressen: ein Wert, eine Komma-/Semikolonliste oder
     * ein Array (to[]=…). Ist eine Adresse ungültig, gilt der ganze Parameter als
     * ungültig - lieber gar nicht senden als an einen Teil der Empfänger.
     *
     * @return string[]  leer bei fehlender oder ungültiger Angabe
     */
    private function addressList(mixed $value): array
    {
        $items = [];
        foreach ((array)$value as $item) {
            foreach (preg_split('/[,;]/', (string)$item) as $address) {
                $address = trim($address);
                if ($address !== '') $items[] = $address;
            }
        }
        $items = array_values(array_unique($items));
        if (!$items || count($items) > self::MAX_RECIPIENTS) return [];
        foreach ($items as $address) {
            if (preg_match('/[\r\n]/', $address) || !PHPMailer::validateAddress($address)) return [];
        }
        return $items;
    }

    /**
     * Betreff: 'subjecttemplate' vor 'subject', Standard "(no subject)". Ein
     * Betreff ist eine Zeile Text - Tags, Entities und Zeilenumbrüche aus dem
     * Template fallen weg.
     */
    private function emailSubject(Cms $cms, array $p): string
    {
        $template = trim((string)($p['subjecttemplate'] ?? ''));
        if ($template !== '') {
            $subject = $this->renderTemplate($cms, $template);
            $subject = html_entity_decode(strip_tags($subject), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        else {
            $subject = (string)($p['subject'] ?? '');
        }
        $subject = $this->headerText($subject);
        return $subject !== '' ? $subject : '(no subject)';
    }

    /**
     * Nachrichtentext: 'bodytemplate' vor 'body'.
     */
    private function emailBody(Cms $cms, array $p): string
    {
        $template = trim((string)($p['bodytemplate'] ?? ''));
        if ($template !== '') {
            return $this->renderTemplate($cms, $template);
        }
        return (string)($p['body'] ?? '');
    }

    /**
     * Template (Name oder "bereich.name") im laufenden Request ausführen.
     *
     * @throws \RuntimeException  Template unbekannt/mehrdeutig, Laufzeitfehler
     */
    private function renderTemplate(Cms $cms, string $ref): string
    {
        $file = $cms->templateFile($ref);
        return $this->container->get(Page::class)->renderTemplateFile($file);
    }

    /**
     * Serverrelative Pfade in src/href/action/background sowie url(...) in einem
     * HTML-Text absolut machen: "/media/1.jpg" -> "https://www.example.com/media/1.jpg".
     * Protokollrelative Adressen ("//cdn…") und alles Absolute bleiben unberührt.
     */
    private function absolutePaths(string $html, string $baseUrl): string
    {
        if ($baseUrl === '') return $html;
        $html = preg_replace(
            '~(\s(?:src|href|action|background|poster)\s*=\s*["\']?)/(?!/)~i',
            '$1' . $baseUrl . '/',
            $html
        ) ?? $html;
        return preg_replace(
            '~(url\(\s*["\']?)/(?!/)~i',
            '$1' . $baseUrl . '/',
            $html
        ) ?? $html;
    }

    /**
     * Basis für absolutePaths(): 'sender_url' (mit oder ohne Protokoll), sonst
     * Protokoll und Hauptdomain der Instanz (wie Cms::recordUrl()), sonst der Host
     * des Requests. Ohne abschließenden Schrägstrich.
     */
    private function senderUrl(Cms $cms, string $senderUrl): string
    {
        $senderUrl = trim($senderUrl);
        if ($senderUrl === '') {
            $host = (string)($cms->instance['primary_domain'] ?? '') ?: (string)($_SERVER['HTTP_HOST'] ?? '');
            if ($host === '') return '';
            $senderUrl = $host;
        }
        if (!preg_match('~^https?://~i', $senderUrl)) {
            $protocol = (string)($cms->instance['protocol'] ?? '');
            if ($protocol === '') {
                $protocol = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
            }
            $senderUrl = $protocol . '://' . $senderUrl;
        }
        return rtrim($senderUrl, '/');
    }

    /**
     * Anhänge ('attachment', ein Wert oder attachment[]=…) auf Dateien unter
     * var/{ATTACHMENT_DIRS} abbilden. Pfade mit "..", versteckte Dateien und
     * Symlinks aus dem Bereich heraus fallen über realpath() durch.
     *
     * @return ?array  absolute Pfade; null, wenn eine Datei fehlt oder nicht erlaubt ist
     */
    private function attachmentFiles(mixed $value): ?array
    {
        $varDir = realpath((string)$this->config->get('paths.var_dir'));
        if ($varDir === false) return (array)$value ? null : [];

        $files = [];
        foreach ((array)$value as $relative) {
            $relative = trim(str_replace('\\', '/', (string)$relative), '/ ');
            if ($relative === '') continue;
            $path = realpath($varDir . '/' . $relative);
            $allowed = false;
            if ($path !== false && is_file($path) && is_readable($path)
                && !str_starts_with(basename($path), '.')) {
                foreach (self::ATTACHMENT_DIRS as $dir) {
                    if (str_starts_with($path, $varDir . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR)) {
                        $allowed = true;
                        break;
                    }
                }
            }
            if (!$allowed) {
                $this->system->log('mail', 'CMS:EMAIL attachment refused: ' . $relative);
                return null;
            }
            $files[] = $path;
        }
        return array_values(array_unique($files));
    }

    /**
     * Fehler für CMS:MESSAGE ablegen: message[$index] des Befehls, sonst das
     * Snippet error_email_{$index}.
     */
    /**
     * formguard / throttle (siehe cmsEmail()). Ohne beide Parameter immer true -
     * CMS:EMAIL läuft auch in Bestellbestätigungen, dort setzt niemand eine
     * fremde Adresse ein.
     */
    private function throttleOk(array $p): bool
    {
        $guard = FormGuard::parameter($p['formguard'] ?? '');
        if ($guard !== false && !$this->container->get(FormGuard::class)->verify($guard)) {
            return false;
        }
        $throttle = trim((string)($p['throttle'] ?? ''));
        if ($throttle === '' || strtolower($throttle) === 'false') return true;
        $bucket = in_array(strtolower($throttle), ['true', '1'], true) ? 'email_ip' : preg_replace('/[^a-z0-9_]/', '', strtolower($throttle));
        $limiter = $this->container->get(RateLimiter::class);
        if (!$limiter->hasBucket($bucket)) {
            $this->system->log('mail', 'CMS:EMAIL: unknown throttle bucket "' . $bucket . '" (user.throttle) - using email_ip');
            $bucket = 'email_ip';
        }
        return $limiter->hit($bucket, $this->container->get(ClientIp::class)->rateKey());
    }

    private function fail(Cms $cms, array $p, string $index): void
    {
        $custom = trim((string)(((array)($p['message'] ?? []))[$index] ?? ''));
        $cms->error($custom !== '' ? $custom : $cms->snippet('error_email_' . $index));
    }

    /**
     * Text für eine Kopfzeile: Zeilenumbrüche (Header-Injection) und doppelte
     * Leerzeichen weg.
     */
    private function headerText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * Template-Parameter als Wahrheitswert ("true"/"false" aus dem Template).
     */
    private function isTrue(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        return filter_var((string)$value, FILTER_VALIDATE_BOOLEAN);
    }
}
