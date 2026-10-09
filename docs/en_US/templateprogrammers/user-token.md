# CMS:USER_TOKEN

Outputs the security token (CSRF token) for a form that triggers a writing `CMS:USER_*` command.

```cmscode
(CMS:USER_TOKEN [parameter=value …])
```

***Method:*** `Modules\Record\RecordUser::cmsToken()`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `format` | `"field" / "value"` | `"field"` |  | field: hidden input field `<input type="hidden" name="_token">`, with the value masked by `htmlspecialchars`. value: only the token (for Ajax), unmasked. |
| `set` | `$set array index` | – |  | The output is not printed, but stored in `$set`. The token is additionally always in `$set['user_token']`. |
| `formguard` | `true / false` | `false` |  | true: additionally outputs a timestamp and an invisible honeypot field (form protection against robots). |

## Usage

The token prevents a foreign website from submitting forms in the name of your visitors (cross-site
request forgery): signing in, registering, resetting a password, saving or deleting contributions,
signing out, changing the shopping basket. These commands check the token. Without a valid token, they
abort with a message (index `token`):

| Command | Checked |
|---|---|
| `USER_LOGIN`, `USER_REGISTER`, `USER_PW`, `USER_INPUT`, `USER_2FA` | when `submit` is filled (not for sign-in by `hash`) |
| `USER_LOGIN_CODE` | when `submit` or `resend` is set |
| `USER_DELETE` | always |
| `USER_LOGOUT` | always – with `submit` only if `submit` is filled |
| `USER_BASKET` | for `add`, `update` and `update_addon` – not for `load`; without a valid token, only reading takes place |

The token is held in a cookie. The **first** call on a page must therefore stand before any output –
at the start of the template (or in the head include before `<!doctype>`), with `set`, so that nothing
is output there. After that, write `(CMS:USER_TOKEN)` into **every** form that triggers one of these
commands:

```cms
(CMS:USER_TOKEN set=token)
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=u_welcome)
<!doctype html>
…
<form method="post">
  (CMS:USER_TOKEN)
  <input name="data[nickname]"> <input type="password" name="data[password]">
  <button name="submit" value="1">Sign in</button>
</form>
```

### Form protection against robots

`(CMS:USER_TOKEN formguard=true)` additionally outputs a signed timestamp and a ***honeypot field*** that
visitors do not see. Commands with `formguard=true` ([CMS:USER_REGISTER](user-register.md),
[CMS:USER_PW](user-pw.md), [CMS:EMAIL](email.md)) reject the form if the honeypot field is filled, or if
the form was submitted faster than three seconds or later than one hour after it was served. The system
administrator sets both times (`user.formguard_min_seconds`, `formguard_max_seconds`). For an individual
form, `formguard=5` on the command sets the minimum time to five seconds – for example for a long form
that nobody completes within three seconds.
This stops robots that fill in every form blindly – no captcha, no third-party service. The system
administrator can additionally connect an external captcha (`security.form_challenge`); you then embed
its widget yourself.

```cms
<form method="post">
  (CMS:USER_TOKEN formguard=true)
  …
</form>
```

For Ajax calls, send the token as the POST field `_token` or in the header `X-Camindo-Token`. A
`FormData` object from a form with `(CMS:USER_TOKEN)` already contains it:

```cms
<script>
fetch('/0,basket_api', {
  method: 'POST',
  headers: { 'X-Camindo-Token': '(CMS:USER_TOKEN format=value)' },
  body: new URLSearchParams({ product: 42 })
});
</script>
```

If the first call comes too late, there is no token: in place of the field there is an HTML comment
with a note, and the form fails with the message `token`.

The cookie (`ucsrf`) applies to all forms of the website and survives sign-in. It is not accepted in
the query string (GET). Writing actions belong in POST forms.

The check can be switched off per command with `csrf=false`. The system administrator can switch it off
globally with `user.csrf = false`. Both open the commands to foreign websites and are meant only for
transitional periods.

---

[← Overview](README.md)
