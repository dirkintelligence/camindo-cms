# CMS:EMAIL

Sends an email. On request, checks the required fields of a form in advance.

```cmscode
(CMS:EMAIL [parameter=value …])
```

***Method:*** `Email::cmsEmail()`  
***At least one of:*** `subject`, `subjecttemplate`, `body`, `bodytemplate`  
***PHP snippet:*** `include_and_stop` (after)

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `to[…]` | `email address` | – | yes | Recipient email address. Several recipients as `to[]=…` or comma-separated (at most 20). |
| `toname` | `text` | – |  | Recipient name (only with exactly one recipient). |
| `from` | `email address` | `email.noreply` or `email.reply` |  | Sender email address. |
| `fromname` | `text` | – |  | Sender name. |
| `contenttype` | `text/plain or text/html` | `text/plain` |  | MIME type of the email. |
| `subject` | `text` | `(no subject)` | ² | Subject of the email. |
| `subjecttemplate` | `template name` | – | ² | Template for the subject (tags and line breaks are removed). Takes precedence over `subject`. |
| `body` | `text` | – | ² | Message text (text or HTML). |
| `bodytemplate` | `template name` | – | ² | Template for the email body. Takes precedence over `body`. |
| `paths_absolute` | `true / false` | `false` |  | true: All server-relative paths in the email body are converted into absolute paths (only with `contenttype="text/html"`). |
| `sender_url` | `(http://)domain` | protocol and main domain of the instance |  | (Optionally with protocol) + domain, so that server-relative paths can be converted into absolute paths. |
| `debug` | `true / false` | `false` |  | true: The email is written to the log file `var/log/{date}_mail-debug.log`, but not sent. |
| `requiredfields` | `field names, comma-separated` | – |  | Specification of the required fields. Field names (index names) in the request array `data[…]` (see parameter `data`). |
| `data` | `PHP variable name` | `data` |  | Name of the request array that contains the data expected by `requiredfields` (default `data`, i.e. `$_POST['data']`). |
| `submit` | `text value` | – | yes | The command runs only if `submit` contains a value. Otherwise the CMS:EMAIL command is ignored. |
| `successtemplate` | `template name` | – |  | Template that is included after successful sending; the page then ends (unless with `CMS:CONTINUE`). |
| `message[…]` | `text` | – |  | Error message. In the index: use case (see [Messages](#messages)); in the value: message text. |
| `attachment[…]` | `path` | – |  | Files to be attached. Path relative to the `var` directory, only from `download/`, `upload/` and `modules/`. |
| `throttle` | `true / bucket name` | – |  | Throttling per origin (IP): true = at most five emails per hour (`user.throttle.email_ip`), otherwise a separate limit from `user.throttle`. Throttled: message `throttled`, no email. |
| `formguard` | `true / false / seconds` | `false` |  | true: Form protection against robots – the form must contain the fields from `(CMS:USER_TOKEN formguard=true)` and may be sent no earlier than after 3 seconds (`user.formguard_min_seconds`). A number sets the minimum time for this form (`formguard=5`), `0` switches it off. Otherwise message `throttled`. |

² at least one of the parameters marked with ² must be given.

`[…]` – the parameter accepts subkeys, for example `name[key]=value`.

## Usage

Contact form with a required-field check and confirmation page:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:EMAIL
  submit="$submit"
  to="info@example.com"
  fromname="Contact form"
  subject="Contact request"
  bodytemplate=contact_mail
  requiredfields="name,email,message"
  successtemplate=contact_thanks
)
(CMS:MESSAGE prefix="<div class=alert>" suffix="</div>")
<form method="post">
  <input name="data[name]"> <input name="data[email]" type="email">
  <textarea name="data[message]"></textarea>
  <button name="submit" value="1">Send</button>
</form>
```

The process:

1. On the first call, `submit` is empty – the command does nothing, the form appears.
2. After submission, it checks the required fields. If one is missing, nothing is sent;
   `CMS:MESSAGE` shows the message, and the template continues – the form
   appears again.
3. Otherwise it builds the email, sends it and includes `successtemplate`. The
   page ends afterwards. Without `successtemplate`, the template simply continues to run.

Sending is done via the SMTP mailbox entered in the configuration (`email.smtp`), or
alternatively via the PHP function `mail()` – like all emails of the CMS.
If sending fails, the reason is stored in `var/log/{date}_mail.log` (module
Log files, type “Undelivered emails”).

### What the command leaves behind

| Variable | Content |
|---|---|
| `$set['email_sent']` | `true` after successful sending (or with `debug=true` after logging), otherwise `false` |
| `$set['email_missing']` | Names of the empty required fields, e.g. to highlight them in the form |

```
<input name="data[email]" class="<?php echo in_array('email', $set['email_missing'] ?? []) ? 'error' : ''; ?>">
```

### Messages

Errors end up in [CMS:MESSAGE](message.md). `message[index]` determines the text,
otherwise the default text applies:

| Index | Case | Default text |
|---|---|---|
| `required` | a required field is empty | Please fill in all required fields. |
| `to` | no or an invalid recipient | The email has no valid recipient. |
| `from` | invalid sender (also: none configured) | The email has no valid sender. |
| `content` | `subjecttemplate`/`bodytemplate` missing or aborts | The content of the email could not be generated. |
| `attachment` | an attachment is missing or lies outside the permitted directories | An attachment of the email was not found. |
| `send` | sending has failed | The email could not be sent. |
| `throttled` | throttling (`throttle`) or form protection (`formguard`) | Too many requests. Please try again later. |

The default texts appear in the language of the page.

### Security

- ***Never take the recipient from the form.*** If an address in `to` is an input of the
  visitor, anyone can send emails to arbitrary addresses through your form.
  If one of the addresses in `to` is invalid, the command does not send at all.
- ***Throttle contact forms:*** `throttle=true` limits the emails per visitor,
  `formguard=true` (with `(CMS:USER_TOKEN formguard=true)` in the form) keeps robots at bay:
  `(CMS:EMAIL submit="$submit" to="info@example.com" … throttle=true formguard=true)`.
  Order confirmations and other emails whose recipient you determine yourself
  do not need this.
- ***Sender:*** The sender is always the website operator, never the visitor: many
  mail servers accept only emails whose sender matches their own domain. It is therefore better to put the visitor's address into the text than
  into `from`; without `from`, the sender address from the configuration applies.
- Line breaks in subject, names and addresses are removed – injected
  headers (for example an additional `Bcc:`) are thereby excluded.
- ***HTML emails:*** output the visitor's input in the body template with
  `(CMS:TEXT useval="{$_POST['data']['…']}" specialchars=true)`.
- ***Attachments*** only from `var/download/`, `var/upload/` and `var/modules/` – configuration,
  logs and backups cannot be attached.

## Parameters in detail

### `to`

```cms
(CMS:EMAIL to="info@example.com" …)
(CMS:EMAIL to[]="info@example.com" to[]="sales@example.com" …)
```

### `toname`

Applies only if exactly one recipient is specified:

```cms
(CMS:EMAIL to="info@example.com" toname="Example Ltd." …)
```

### `from`

```cms
(CMS:EMAIL from="shop@example.com" …)
```

Without specification, the sender address from the configuration (`email.noreply`, alternatively
`email.reply`). If none of these is entered, nothing can be sent without `from`.

### `fromname`

```cms
(CMS:EMAIL fromname="Contact form Example Ltd." …)
```

### `contenttype`

```cms
(CMS:EMAIL contenttype="text/html" bodytemplate=newsletter_html …)
```

With `text/html`, the CMS additionally creates a plain-text version for email programs without
HTML display.

### `subject`

```cms
(CMS:EMAIL subject="Contact request" …)
```

### `subjecttemplate`

Creates the subject from a template (e.g. with fields of the current record).
Tags, entities and line breaks are removed:

```cms
(CMS:EMAIL subjecttemplate=order_subject …)
```

### `body`

```cms
(CMS:EMAIL body="New request from {$_POST['data']['name']}" …)
```

### `bodytemplate`

Message text from a template – there all CMS commands are available, the current
record, `$set` and the form data in `$_POST`. Variables that you have set with PHP in the
calling template are not visible to the body template; store them in `$set` if necessary.

```cms
(CMS:EMAIL contenttype="text/html" bodytemplate=contact_mail …)
```

`contact_mail`:

```cms
<p>New request from (CMS:TEXT useval="{$_POST['data']['name']}" specialchars=true)
(CMS:TEXT useval="{$_POST['data']['email']}" specialchars=true):</p>
<p>(CMS:TEXT useval="{$_POST['data']['message']}" specialchars=true)</p>
```

`specialchars=true` belongs with HTML emails. In a plain-text email (`text/plain`, default),
visitor text cannot execute anything; `specialchars` would only make entities such as `&amp;`
visible there.

Template names can be specified with the area as with [CMS:INCLUDE](include.md)
(`area.name`).

### `paths_absolute`

Makes server-relative paths (`/media/…`) in `src`, `href`, `action`, `background`, `poster`
and `url(…)` of the HTML body absolute:

```cms
(CMS:EMAIL contenttype="text/html" bodytemplate=newsletter_html paths_absolute=true …)
```

### `sender_url`

Domain for `paths_absolute`, if the protocol and main domain of the instance should not apply;
without a protocol, that of the instance applies:

```cms
(CMS:EMAIL paths_absolute=true sender_url="https://www.example.com" …)
```

### `debug`

Writes the email only to the log file `var/log/{date}_mail-debug.log`, without sending it – for
testing a form. Otherwise the command behaves as with real sending
(required fields, `successtemplate`):

```cms
(CMS:EMAIL debug=true …)
```

### `requiredfields`

Required fields in the request array `data[…]` (or the array named via `data`); if one
is missing, nothing is sent and `CMS:MESSAGE` shows the message. For fields with several
values (checkbox group `data[topics][]`), one selected value is sufficient:

```cms
(CMS:EMAIL requiredfields="name,email,message" …)
```

### `data`

Name of the request array with the form data (default `data`, i.e. `$_POST['data']`):

```cms
<input name="form[name]">
(CMS:EMAIL data=form requiredfields="name" …)
```

### `submit`

The command runs only if `submit` has a value – so not on the first call of the form:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:EMAIL submit="$submit" …)
<button name="submit" value="1">Send</button>
```

### `successtemplate`

After successful sending, this template is included and the page ends (without `CMS:CONTINUE`):

```cms
(CMS:EMAIL successtemplate=contact_thanks …)
```

### `message`

Own message texts per error case (indexes see [Messages](#messages)):

```cms
(CMS:EMAIL message[required]="Please fill in all required fields." …)
```

### `attachment`

Attaches files relative to the `var` directory – only from `download/`, `upload/` and
`modules/`:

```cms
(CMS:EMAIL attachment[]="download/terms.pdf" …)
```

### `throttle`

```cms
(CMS:EMAIL throttle=true …)
(CMS:EMAIL throttle=newsletter …)
```

A separate name needs a limit in the configuration (`user.throttle.newsletter`,
system administrator); if it is missing, the limit of `true` applies.

### `formguard`

```cms
(CMS:EMAIL formguard=true …)
<form method="post">(CMS:USER_TOKEN formguard=true) …</form>
```

---

[← Overview](README.md)
