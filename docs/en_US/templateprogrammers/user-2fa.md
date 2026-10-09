# CMS:USER_2FA

Shows the second factor of the signed-in frontend user, sets it up (authenticator app or code by
email) and switches it off.

```cmscode
(CMS:USER_2FA [parameter=value …])
```

***Method:*** `Modules\Record\RecordUser::cmsTwoFactor()`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `function` | `status / totp_setup / totp_enable / code_enable / disable / recovery_renew` | `status` |  | What the command does (see below). |
| `submit` | `text value` | – |  | Writing functions run only if this field contains a text. |
| `data` | `PHP variable name` | `data` |  | Name of the PHP array with the inputs. |
| `codefield` | `field name` | `code` |  | Input field for the app code in `data[…]`. |
| `currentfield` | `field name` | `current_password` |  | Input field for the password in `data[…]`. |
| `message[…]` | `default message` | – |  | Error messages. In the index: use case; in the value: message text. |
| `cookiename` | `cookie name` | `usess` |  | Name of the session cookie [a-z0-9]. |
| `csrf` | `true / false` | `true` |  | true: Writing functions require a token from [CMS:USER_TOKEN](user-token.md). |

## Usage

The command belongs on a protected page of the customer account (after
[CMS:USER_PROTECT](user-protect.md)). It takes effect only if the profile area allows a second
factor (setting “Second factor” is not “off”; otherwise the message `policy`).

| `function` | Effect | Confirmed with |
|---|---|---|
| `status` | read only | – |
| `totp_setup` | creates a new key for the authenticator app – nothing is saved yet. `$set['totp_setup']` contains `token`, `qr` (ready-made HTML with the QR code), `secret` (to type in, in groups of four), `uri` (`otpauth://…`, which opens the app directly on the phone) and `qr_script` (address of the QR library) | – |
| `totp_enable` | saves the key and creates ten recovery codes (`$set['recovery_codes']`, shown in plain text only at this moment) | `data[token]` and the first code from the app (`data[code]`) |
| `code_enable` | code by email at every sign-in | password (`data[current_password]`); if an app is set up, the app code |
| `disable` | switches off. If the area always requires a second factor, the code is then sent by email instead of from the app | app code or recovery code, or password |
| `recovery_renew` | new recovery codes; the old ones expire | app code |

After each function, `$set` contains: `second_factor` (`''`, `totp`, `email`),
`second_factor_policy` (setting of the area: `none`, `optional`, `new_device`, `required`),
`second_factor_since` (UTC) and `recovery_count`. After a change, `second_factor_changed` is set
(`totp_enabled`, `totp_disabled`, `code_enabled`, `code_disabled`, `recovery_renewed`) – for
example for a notice email to the frontend user.

To set up the app, the frontend user scans the ***QR code*** with the app. It contains the key,
not the codes – the app generates those itself afterwards, six digits each. Alternatively, the
user types in the key or opens the link on the phone. `$set['totp_setup']['qr']` brings
everything: an element with the QR code, the qrcode-generator library (the same as in the back
end, taken from its assets) and a small script. Both use the nonce of the page, so the setup also
runs under an enforced script policy. Output it only with `echo`, and control the size with CSS
(`.c9-qr { width: 200px }`).

```cms
<?php
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$action = $isPost ? (string)($_POST['action'] ?? '') : (isset($_GET['setup']) ? 'totp_setup' : 'status');
$submit = $isPost ? '1' : '';
?>
(CMS:USER_PROTECT errortemplate=u_login)
(CMS:USER_2FA function="$action" submit="$submit")
<p class="error">(CMS:MESSAGE)</p>
<?php if (!empty($set['recovery_codes'])) { ?>
  <p>Your recovery codes (please keep them safe):</p>
  <ul><?php foreach ($set['recovery_codes'] as $c) echo '<li>' . htmlspecialchars($c) . '</li>'; ?></ul>
<?php } ?>
<?php if (!empty($set['totp_setup'])) { $s = $set['totp_setup']; ?>
  <?php echo $s['qr']; ?>
  <p>Key for your authenticator app: <code><?php echo htmlspecialchars($s['secret']); ?></code>
     – or <a href="<?php echo htmlspecialchars($s['uri']); ?>">open it in the app</a>.</p>
  <form method="post">
    (CMS:USER_TOKEN)
    <input type="hidden" name="action" value="totp_enable">
    <input type="hidden" name="data[token]" value="<?php echo htmlspecialchars($s['token']); ?>">
    <input name="data[code]" autocomplete="one-time-code" required>
    <button>Switch on</button>
  </form>
<?php } elseif ($set['second_factor'] === '') { ?>
  <p><a href="?setup=1">Set up authenticator app</a></p>
<?php } ?>
```

Message indexes: `auth` (not signed in), `policy`, `code` (app code wrong), `current` (password
wrong), `setup` (setup expired – 15 minutes), `blocked` (too many failed attempts), `token`.
Wrong codes and passwords count in the same way as at sign-in.

If a frontend user has lost their phone and their recovery codes, an administrator resets the
second factor in the back end (profile record, menu “Second factor”).

## Parameters in detail

### `function`

```cms
(CMS:USER_2FA function=status)
(CMS:USER_2FA function="$action" submit="$submit")
```

### `submit`

Only writing functions need it – they belong in a POST form:

```
<?php $submit = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? '1' : ''; ?>
```

### `data`, `codefield`, `currentfield`

```cms
(CMS:USER_2FA function=disable submit="$submit" data=sec codefield=otp currentfield=pw)
```

### `message`

```cms
(CMS:USER_2FA function="$action" submit="$submit" message[code]="The code is not correct.")
```

---

[← Overview](README.md)
