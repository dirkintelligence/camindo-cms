# CMS:USER_LOGIN

Checks the sign-in data of a frontend user and creates a new session.

```cmscode
(CMS:USER_LOGIN [parameter=value …])
```

***Method:*** `Modules\Record\RecordUser::cmsLogin()`  
***PHP snippet:*** `include_and_stop` (after)

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `successtemplate` | – | – | yes | Detail template that is included after a successful sign-in. |
| `container` | `container ID or name` | – | yes | ID or name of the profile container in which the data of the frontend users is stored. |
| `data` | `PHP variable name` | `data` |  | Name of the PHP array that contains the sign-in data. |
| `message[…]` | `default message` | – |  | Error messages. In the index: use case; in the value: message text (see the table). |
| `hash` | `hash` | – |  | A sign-in can also be carried out by means of a hash (token from CMS:USER_PW, forgotten password). |
| `trim` | `true / false` | `false` |  | true: remove spaces at the beginning and end of the input. |
| `cookie` | `true / false` | `true` |  | true: a cookie is set by default to keep the sign-in across several pages. |
| `submit` | `text value` | – |  | The command runs only if this field contains a text. Not applicable to sign-in by “hash”. |
| `cookiename` | `cookie name` | `usess` |  | Name of the session cookie [a-z0-9]. |
| `csrf` | `true / false` | `true` |  | true: The request must contain a valid token from [CMS:USER_TOKEN](user-token.md) (protection against CSRF). false switches off the check for this command – only with good reason. |
| `codetemplate` | – | – |  | Template for the second step of the sign-in if the profile area requires a second factor. Required as soon as the area uses one. |

`[…]` – the parameter accepts sub-keys, e.g. `name[key]=value`.

## Usage

Checks sign-in name and password against the profile area and creates a session (cookie).
The fields are those defined in the area as sign-in name and password (`data[nickname]`,
`data[password]`). After success, `successtemplate` is included; in it the profile is the
current record, and `$set['user_id']` and `$set['user']` are filled:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=u_welcome)
<h1>Sign in</h1>
<p class="error">(CMS:MESSAGE)</p>
<form method="post">
  (CMS:USER_TOKEN)
  <input name="data[nickname]" value="(CMS:TEXT useval="{$_POST['data']['nickname']}" specialchars=true)">
  <input type="password" name="data[password]">
  <button name="submit" value="1">Sign in</button>
</form>
```

Sign-in via the link from `CMS:USER_PW` (forgotten password):

```cms
(CMS:USER_LOGIN container=members hash="{$_GET['hash']}" successtemplate=u_newpw)
```

The command belongs at the start of the template (the cookie must be set before any output).
Failed attempts are counted (brute-force protection). Message indexes: `failed`, `blocked`,
`disabled` (status 1), `unconfirmed` (status 2), `hash`, `container`, `token`. Counting is
done per sign-in name and additionally per origin (IP address): anyone who tries many accounts
with one password each from the same connection is blocked as well (`blocked`).

### Second factor

In the profile area the administrator determines whether a ***two-step sign-in*** is required
(setting “Second factor”: off, optional, code for new device, always). If a sign-in needs a
code, `USER_LOGIN` does not sign anyone in after the correct password. Instead, it includes
`codetemplate`. There you find:

| `$set[…]` | Content |
|---|---|
| `second_factor` | `totp` (code from the authenticator app) or `email` (code by email) |
| `login_pending` | 1 |
| `pending_user` | the profile (also the current record) – for example for the email address |
| `login_code` | with a code by email: the six-digit code that you send |

The `codetemplate` sends the code with [CMS:EMAIL](email.md) and shows the form for
[CMS:USER_LOGIN_CODE](user-login-code.md) (example there). As with all `CMS:USER_*` commands,
`USER_LOGIN` does not send any email itself – the template always does that. The sign-in via
the link from “Forgot password” (`hash`) also requires the code if the frontend user has set
up an authenticator app. The setup itself is handled by [CMS:USER_2FA](user-2fa.md).

```cms
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=u_welcome codetemplate=u_code_mail)
```

### Sign-in from a new device

camindo records the devices (browsers) with which a profile signs in. If a sign-in comes from
an ***unknown device*** and the profile already has other devices, `$set['new_device']` is 1
in the `successtemplate`. `$set['device']` contains `label` (e.g. “Firefox / macOS”), `ip` and
`time` (UTC). You send the notice to the owner yourself – before the redirect, with
[CMS:EMAIL](email.md):

```cms
<?php
$to = (string)($set['user']['email'] ?? '');
$send = (!empty($set['new_device']) && $to !== '') ? '1' : '';
$body = "New sign-in: {$set['device']['label']}, IP {$set['device']['ip']}, {$set['device']['time']} UTC."
      . " If this was not you, please set a new password.";
?>
(CMS:EMAIL submit="$send" to="$to" subject="New sign-in" body="$body")
```

The very first sign-in of a profile does not trigger a notice. Whether an SMS is sent instead is
determined by the system administrator (see the manual for administrators, Operation).

## Parameters in detail

### `successtemplate`

```cms
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=u_welcome)
```

In `u_welcome`: `Hello (CMS:TEXT nickname)` – the profile is the current record there.

### `container`

Profile area (name or ID):

```cms
(CMS:USER_LOGIN container=members …)
```

### `data`

Name of the request array with the sign-in data (default `data`):

```cms
<input name="login[nickname]"> <input type="password" name="login[password]">
(CMS:USER_LOGIN container=members data=login submit="$submit" successtemplate=u_welcome)
```

### `message`

```cms
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=u_welcome
  message[failed]="Name or password is incorrect."
  message[unconfirmed]="Please confirm your email address first."
  message[blocked]="Too many failed attempts – please try again later."
)
```

### `hash`

Sign-in with the one-time token from `CMS:USER_PW` instead of a password; `submit` does not
apply:

```cms
(CMS:USER_LOGIN container=members hash="{$_GET['hash']}" successtemplate=u_newpw)
```

In `u_newpw` the frontend user sets a new password with
`CMS:USER_INPUT container=members id="{$set['user_id']}" fields=password`. After this sign-in,
`$set['password_reset']` is 1: for one hour (setting `user.password_token_expiration`) the user
does not need the previous password for this – see
[CMS:USER_INPUT, changing the password](user-input.md#changing-the-password).

### `codetemplate`

Template for the second step (see above):

```cms
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=u_welcome codetemplate=u_code_mail)
(CMS:USER_LOGIN container=members hash="{$_GET['hash']}" successtemplate=u_newpw codetemplate=u_code_mail)
```

If it is missing although the area requires a second factor, the sign-in fails with `failed`
and an entry in the `user` log.

### `trim`

Remove spaces around name and password (copy errors on mobile devices):

```cms
(CMS:USER_LOGIN container=members trim=true submit="$submit" successtemplate=u_welcome)
```

### `cookie`

Do not set a cookie – the session ID is then only in `$set['user_session_id']` (for apps that
pass it on themselves):

```cms
(CMS:USER_LOGIN container=members cookie=false submit="$submit" successtemplate=api_token)
```

### `submit`

Run only when the form has been submitted:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=u_welcome)
```

### `cookiename`

```cms
(CMS:USER_LOGIN container=members cookiename=shop submit="$submit" successtemplate=u_welcome)
```

Must be the same for all `USER_*` commands.

---

[← Overview](README.md)
