<?php
/**
 * class Email
 *
 * Sending email from the CMS. The class itself uses PHPMailer.
 * If a mailbox is configured under email.smtp, mail is sent through it;
 * the fallback is mail() - but only if an MTA is present.
 * Logs errors with System::log('mail',...)
 *
 * PLANNED:
 * Logging of emails if log.emails is enabled, for example for debugging purposes
 *
 * USAGE
 * --------------------
 * The class is not collected in the constructor, but only loaded on first access
 * (see LazyServicesTrait, example: Modules/Admin/Admin.php):
 *
 * $mail = new PHPMailer(true);
 * $mail->setFrom($emailNoreply ?: $emailReply, 'camindo CMS');
 * $mail->addAddress($data['email'], $data['displayname'] ?? '');
 * (...)
 * $this->email->send($mail);
 *
 * CMS:EMAIL
 * --------------------
 * cmsEmail() is the method of the template command (Modules/Template/commands.php,
 * entry 'email'): check required fields, build the mail from parameters or templates,
 * send it via send(). For this the class is loaded in the frontend via Page::$lazyServices;
 * Cms and Page only fetch it from the container when called, because
 * send() alone (2FA code in the backend) needs neither.
 */

namespace Camindo\Core;
use PHPMailer\PHPMailer\PHPMailer;

class Email {
    /**
     * CMS:EMAIL attachment: only files from these directories under var/ -
     * config, log, dbbackup & Co. must not be sendable to an arbitrary
     * address via a template parameter.
     */
    private const ATTACHMENT_DIRS = ['download', 'upload', 'modules'];

    /** CMS:EMAIL: upper limit of recipients per command */
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
        // At least one of the two sender addresses must be configured
        $emailNoreply = $this->config->get('email.noreply');
        $emailReply = $this->config->get('email.reply');
        if (empty($emailNoreply) && empty($emailReply)) {
            throw new \Exception('Config incomplete: email address is not configured.');
        }

        $smtp = $this->config->get('email.smtp');
        if (!empty($smtp['host'])) {
            // SMTP mailbox configured
            try {
                $this->configureSmtp($mail, $smtp);
                $mail->send();
                return;

            } catch (\Throwable $e) {
                // SMTP send failed: log first, then try mail().
                // Without this line the actual reason (wrong password,
                // certificate error, blocked port) would stay invisible, because
                // the fallback right after fails with a completely different message.
                $this->system->log('mail', 'SMTP send failed, falling back to mail(). To: '
                    . $this->recipients($mail) . ', Subject: ' . $mail->Subject
                    . ', Error: ' . ($mail->ErrorInfo ?: $e->getMessage()));
            }
        }

        // No SMTP mailbox configured or the SMTP send has failed
        $this->sendByMailFunction($mail);
    }

    /**
     * Switch PHPMailer to SMTP sending.
     *
     * 'enc' decides the transport route:
     *     'starttls'  connect unencrypted, then upgrade via STARTTLS (port 587)
     *     'smtps'     TLS from the start (port 465)
     * Without 'user' no authentication is done - this covers relays that
     * accept without sign-in.
     *
     * @param array $smtp  sub-array email.smtp from .camindo.php
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

        // Time limit per connection step. PHPMailer's default of 300 s would block a
        // web request for minutes if the mailbox is unreachable.
        $mail->Timeout    = 15;

        // Write the SMTP dialog to the log while system.development_version is set
        // - otherwise nothing can be seen when there are sign-in problems (wrong password,
        // certificate, port).
        //
        // The message itself stays out: between the DATA command and the closing line
        // "." the complete body runs over the line, so with two-factor sign-in that is the
        // login code in plain text. That has no place in a log file; the header lines of the
        // dialog are enough for troubleshooting. PHPMailer itself masks credentials ([credentials hidden]).
        if ($this->config->get('system.development_version')) {
            $mail->SMTPDebug   = 2;   // client and server lines
            $inData = false;
            $mail->Debugoutput = function ($str, $level) use (&$inData) {
                $line = rtrim($str);
                if ($inData) {
                    // The end of the body is the line with the single dot
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
     * Fallback sending via the PHP function mail().
     *
     * @throws \Exception if this fails too (e.g. server without sendmail/MTA)
     */
    private function sendByMailFunction($mail): void
    {
        // In containers without an MTA, mail() exists but has no sendmail behind it.
        // Then do not even try to send, but abort with a message that names
        // the real reason.
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
     * Is there any sendmail binary that mail() could call?
     *
     * PHP reports sending via mail() as successful even if no MTA
     * is installed at all - the message then vanishes without a trace. A look at
     * sendmail_path avoids this case.
     */
    private function hasLocalMta(): bool
    {
        $sendmailPath = (string)ini_get('sendmail_path');
        if ($sendmailPath === '') {
            return false;
        }
        // sendmail_path contains the binary and arguments, e.g. "/usr/sbin/sendmail -t -i"
        $binary = strtok(trim($sendmailPath), ' ');
        return $binary !== false && is_executable($binary);
    }

    /**
     * Recipient addresses of the mail as a comma-separated list - only for the log line.
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
     * CMS:EMAIL - send an email from a form.
     *
     * Process:
     *   1. 'submit' empty -> the command does nothing (first call of the form)
     *   2. 'requiredfields': every named field from the request array 'data'
     *      (default data[...]) must have a value
     *   3. recipient 'to' (a value, comma list or to[]=…), sender 'from' -
     *      if not given, email.noreply or email.reply from the configuration
     *   4. subject and text: 'subjecttemplate'/'bodytemplate' before 'subject'/'body';
     *      templates run in the current request (Page::renderTemplateFile()) and
     *      see $cms, $set and $_POST
     *   5. contenttype="text/html": a plain-text alternative is generated,
     *      paths_absolute=true makes server-relative paths absolute ('sender_url',
     *      otherwise protocol and domain of the instance)
     *   6. 'attachment': files relative to var/, only from ATTACHMENT_DIRS
     *   7. debug=true: only to the log (var/log/{Y-m-d}_mail-debug.log), no sending
     *
     * Errors end up in Cms::error() (CMS:MESSAGE); the text comes from
     * message[required|throttled|to|from|content|attachment|send], otherwise from the snippet
     * error_email_{index}. The template then continues (show the form again).
     *
     * Leaves $set['email_sent'] (bool) and $set['email_missing'] (names of the
     * missing required fields).
     *
     * Calling convention as with the cms* methods in Cms: one parameter, the array $_a
     * with the command parameters.
     *
     * @return string  successtemplate after successful sending, otherwise '' -
     *                 php_suffix include_and_stop includes it and ends the page
     * @see Camindo\Core\Modules\Template\commands.php  Eintrag 'email'
     */
    public function cmsEmail($p): string
    {
        $p = (array)$p;
        $GLOBALS['set']['email_sent'] = false;
        $GLOBALS['set']['email_missing'] = [];
        if (trim((string)($p['submit'] ?? '')) === '') return '';

        $cms = $this->container->get(Cms::class);

        // Required fields
        // --------------------
        $missing = $this->missingFields($p);
        if ($missing) {
            $GLOBALS['set']['email_missing'] = $missing;
            $this->fail($cms, $p, 'required');
            return '';
        }

        // Form protection and throttling (developer docs, chapter 14 (Security: web)):
        // formguard=true requires a timestamp + empty decoy field
        // (CMS:USER_TOKEN formguard=true); throttle=true or throttle=bucket counts
        // per origin in user.throttle.email_ip or user.throttle.{bucket}.
        // Both answer the same way ('throttled') - a robot should not learn
        // what the reason was.
        if (!$this->throttleOk($p)) {
            $GLOBALS['set']['throttled'] = 1;
            $this->fail($cms, $p, 'throttled');
            return '';
        }

        // Recipient and sender
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

        // Content
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

        // Build the mail
        // --------------------
        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($from, $this->headerText((string)($p['fromname'] ?? '')));
            // 'toname' only with exactly one recipient - one name for several addresses
            // would be wrong for all but one
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

        // Sending (or only log)
        // --------------------
        if ($this->isTrue($p['debug'] ?? false)) {
            // Own log: not an error, must not appear under "unsent emails"
            // (Logfiles module, category mail)
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
                // send() has already logged the cause
                $this->fail($cms, $p, 'send');
                return '';
            }
        }

        $GLOBALS['set']['email_sent'] = true;
        return $cms->templateFile((string)($p['successtemplate'] ?? ''));
    }

    /**
     * Names of the required fields ('requiredfields') that are empty in the request array
     * 'data'. A field with sub-values (checkbox group) counts as filled if
     * at least one value is not empty.
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
     * 'to' as a list of valid addresses: one value, a comma/semicolon list or
     * an array (to[]=…). If one address is invalid, the whole parameter counts as
     * invalid - better not to send at all than to only some of the recipients.
     *
     * @return string[]  empty if the entry is missing or invalid
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
     * Subject: 'subjecttemplate' before 'subject', default "(no subject)". A
     * subject is one line of text - tags, entities and line breaks from the
     * template are removed.
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
     * Message text: 'bodytemplate' before 'body'.
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
     * Run a template (name or "area.name") in the current request.
     *
     * @throws \RuntimeException  template unknown/ambiguous, runtime error
     */
    private function renderTemplate(Cms $cms, string $ref): string
    {
        $file = $cms->templateFile($ref);
        return $this->container->get(Page::class)->renderTemplateFile($file);
    }

    /**
     * Make server-relative paths in src/href/action/background as well as url(...) in an
     * HTML text absolute: "/media/1.jpg" -> "https://www.example.com/media/1.jpg".
     * Protocol-relative addresses ("//cdn…") and everything absolute remain unchanged.
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
     * Base for absolutePaths(): 'sender_url' (with or without protocol), otherwise
     * protocol and main domain of the instance (as Cms::recordUrl()), otherwise the host
     * of the request. Without a trailing slash.
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
     * Map attachments ('attachment', one value or attachment[]=…) to files under
     * var/{ATTACHMENT_DIRS}. Paths with "..", hidden files and
     * symlinks leading out of the area are rejected via realpath().
     *
     * @return ?array  absolute paths; null if a file is missing or not allowed
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
     * Store an error for CMS:MESSAGE: message[$index] of the command, otherwise the
     * snippet error_email_{$index}.
     */
    /**
     * formguard / throttle (see cmsEmail()). Without both parameters always true -
     * CMS:EMAIL also runs in order confirmations, where nobody enters
     * a foreign address.
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
     * Text for a header line: line breaks (header injection) and double
     * spaces removed.
     */
    private function headerText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * Template parameter as a truth value ("true"/"false" from the template).
     */
    private function isTrue(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        return filter_var((string)$value, FILTER_VALIDATE_BOOLEAN);
    }
}
