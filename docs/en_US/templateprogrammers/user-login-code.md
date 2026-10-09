# CMS:USER_LOGIN_CODE

Second step of the sign-in: checks the code from the authenticator app, the code by email or a
recovery code, and signs in the frontend user.

```cmscode
(CMS:USER_LOGIN_CODE [parameter=value …])
```

***Method:*** `Modules\Record\RecordUser::cmsLoginCode()`  
***PHP snippet:*** `include_and_stop` (after)

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `successtemplate` | – | – | yes | Detail template that is included after the successful sign-in. |
| `submit` | `text value` | – |  | The code is checked only if this field contains a text. |
| `resend` | `true / false` | `false` |  | true: creates a new code by email (throttled) and includes `codetemplate`. |
| `codetemplate` | – | – |  | Template that sends the new code with CMS:EMAIL after `resend` – the same as with `CMS:USER_LOGIN`. |
| `data` | `PHP variable name` | `data` |  | Name of the PHP array with the inputs. |
| `codefield` | `field name` | `code` |  | Input field for the code in `data[…]`. |
| `message[…]` | `default message` | – |  | Error messages. In the index: use case; in the value: message text. |
| `sessionid` | `session ID` | – |  | Waiting sign-in without a cookie (apps) – `$set['user_session_id']` after `CMS:USER_LOGIN`. |
| `cookiename` | `cookie name` | `usess` |  | Name of the session cookie [a-z0-9]. |
| `csrf` | `true / false` | `true` |  | true: The request must contain a valid token from [CMS:USER_TOKEN](user-token.md). |

## Usage

If the profile area requires a ***second factor***, [CMS:USER_LOGIN](user-login.md) includes the
`codetemplate` after the correct password. The sign-in then waits in a session without a
frontend user; `USER_LOGIN_CODE` completes it.

Divide the work between two templates:

* ***`u_code_mail`*** sends the code (`$set['login_code']`) with [CMS:EMAIL](email.md) and then
  includes the form. It is the `codetemplate` of `CMS:USER_LOGIN` and of `CMS:USER_LOGIN_CODE`
  (for “Send new code”).
* ***`u_code`*** contains `CMS:USER_LOGIN_CODE` and the form; it is the target of the form.

Without `submit` and `resend`, the command only fills `$set`, and the template continues. Within
one call, it evaluates `submit` and `resend` only the first time – if `u_code_mail` includes
`u_code` again after a resend, nothing happens twice. Give the buttons their own names: the
sign-in form has already sent `submit=1`.

`u_code_mail`:

```cms
<?php
$to = (string)($set['pending_user']['email'] ?? '');
$send = (!empty($set['login_code']) && $to !== '') ? '1' : '';
$body = "Your sign-in code: {$set['login_code']} (valid for 10 minutes)";
?>
(CMS:EMAIL submit="$send" to="$to" subject="Your sign-in code" body="$body")
(CMS:INCLUDE u_code)
```

`u_code`:

```cms
<?php
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$submit = ($isPost && !empty($_POST['code_submit'])) ? '1' : '';
$resend = ($isPost && !empty($_POST['code_resend'])) ? 'true' : 'false';
?>
(CMS:USER_LOGIN_CODE submit="$submit" resend="$resend" successtemplate=u_welcome codetemplate=u_code_mail)
<h1>Confirm sign-in</h1>
<p class="error">(CMS:MESSAGE)</p>
<?php if (!empty($set['code_resent'])) { ?><p>A new code is on its way.</p><?php } ?>
<?php if (!empty($set['login_pending'])) { ?>
<form method="post" action="/0,u_code">
  (CMS:USER_TOKEN)
  <input name="data[code]" autocomplete="one-time-code" required>
  <button name="code_submit" value="1">Confirm</button>
</form>
<?php if ($set['second_factor'] === 'email') { ?>
<form method="post" action="/0,u_code">
  (CMS:USER_TOKEN)
  <button name="code_resend" value="1">Send new code</button>
</form>
<?php } ?>
<?php } else { ?>
<p><a href="/0,u_login">To sign-in</a></p>
<?php } ?>
```

For a code by SMS, write the call to your SMS service in `u_code_mail` instead of `CMS:EMAIL` –
the core is not affected.

The command belongs at the start of the template (the cookie must be set before any output).

| `$set[…]` | Content |
|---|---|
| `login_pending` | 1 as long as a sign-in is waiting for the code |
| `second_factor` | `totp` or `email` |
| `pending_user` | the profile of the waiting sign-in |
| `login_code` | after `resend`: the new code (in the `codetemplate`) |
| `code_resent` | 1 after `resend` |
| after success | as with `USER_LOGIN` (`user_id`, `user`, `new_device` …), plus `second_factor_used` (`totp`, `email`, `recovery`) and `recovery_remaining` |

***Security:*** A code by email is valid for 10 minutes (`user.login_code_expiration`). Every
wrong code counts like a wrong password. After three wrong codes the waiting sign-in expires
(message `expired`), and the frontend user starts again with the password. A recovery code works
only once. “Send new code” is throttled (three times per 10 minutes).

Message indexes: `code` (wrong code), `expired` (expired or too many failed attempts), `blocked`,
`throttled` (new code requested too often), `token`.

## Parameters in detail

### `successtemplate`

As with `USER_LOGIN` – the profile is the current record there:

```cms
(CMS:USER_LOGIN_CODE submit="$submit" successtemplate=u_welcome)
```

### `submit`

```cms
<?php $submit = !empty($_POST['code_submit']) ? '1' : ''; ?>
(CMS:USER_LOGIN_CODE submit="$submit" successtemplate=u_welcome)
```

### `resend`, `codetemplate`

```cms
<?php $resend = !empty($_POST['code_resend']) ? 'true' : 'false'; ?>
(CMS:USER_LOGIN_CODE resend="$resend" submit="$submit" successtemplate=u_welcome codetemplate=u_code_mail)
```

### `data`, `codefield`

```cms
<input name="login[otp]">
(CMS:USER_LOGIN_CODE data=login codefield=otp submit="$submit" successtemplate=u_welcome)
```

### `message`

```cms
(CMS:USER_LOGIN_CODE submit="$submit" successtemplate=u_welcome
  message[code]="The code is not correct."
  message[expired]="Please sign in again."
)
```

### `sessionid`, `cookiename`

As with [CMS:USER_AUTH](user-auth.md).

---

[← Overview](README.md)
