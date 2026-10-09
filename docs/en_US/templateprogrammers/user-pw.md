# CMS:USER_PW

Prepares the resetting of a password.

```cmscode
(CMS:USER_PW [parameter=value …])
```

***Method:*** `Modules\Record\RecordUser::cmsPw()`  
***PHP snippet:*** `include_and_stop` (after)

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `successtemplate` | – | – | yes | Detail template that is included if “uniquevalue” could be assigned to a profile in the field “uniquefield”. |
| `errortemplate` | – | – | yes | Detail template that is included if “uniquevalue” was given but could not be assigned to a profile. |
| `container` | `container ID or name` | – | yes | ID or name of the profile container in which the profiles to be matched are stored. |
| `uniquefield` | `field name` | – | yes | Name of the field to search by (field type chars, text or encrypted, e.g. “email”). The value must match exactly one profile. |
| `uniquevalue` | `text` | – |  | Value sought in the field “uniquefield”, e.g. `$_POST[email]`. |
| `submit` | `text value` | – | yes | The command runs only if this field contains a text. |
| `message[…]` | `text` | – |  | Error message. In the index: use case; in the value: message text (see table). |
| `csrf` | `true / false` | `true` |  | true: The request must contain a valid token from [CMS:USER_TOKEN](user-token.md) (protection against CSRF). false switches off the check for this command – only with good reason. |
| `formguard` | `true / false / seconds` | `false` |  | true: form protection against robots – the form must contain the fields from `(CMS:USER_TOKEN formguard=true)` and may not be submitted earlier than 3 seconds (`user.formguard_min_seconds`). A number sets the minimum time for this form (`formguard=5`), `0` switches it off. Otherwise the message `throttled`. |

`[…]` – the parameter accepts sub-keys, e.g. `name[key]=value`.

## Usage

First step of “Forgot password”: searches for the profile via a unique field (usually the email
address) and creates a one-time token in `$set['hash']`. The `successtemplate` sends the link. The
target page signs the frontend user in with `(CMS:USER_LOGIN hash=…)`; afterwards, the user sets a
new password with `CMS:USER_INPUT`:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_PW container=members uniquefield=email uniquevalue="{$_POST['data']['email']}"
  submit="$submit" successtemplate=u_pwsent errortemplate=u_pwfail)
<h1>Forgot password</h1>
<form method="post">
  (CMS:USER_TOKEN)
  <input name="data[email]" type="email">
  <button name="submit" value="1">Request link</button>
</form>
```

`u_pwsent` sends the link with [CMS:EMAIL](email.md):

```cms
<?php $link = 'https://www.example.com/0,u_hashlogin?hash=' . rawurlencode($set['hash']); ?>
(CMS:EMAIL submit="1" to="{$set['user']['email']}" subject="New password"
  body="Link (valid for one hour): $link")
(CMS:MESSAGE)
<p>We have sent you a link.</p>
```

Message indexes: `notfound`, `container`, `throttled`, `token`.

### Throttling and form protection

Each request sends an email to an address that someone has typed in. So that nobody can flood
foreign mailboxes through your form, camindo counts the requests (***throttling***): at most three
per entered address and hour, ten per origin (IP address) and 200 per website (system administrator:
`user.throttle`). What is counted is the entered value – whether an account exists does not matter.
Once a limit is reached, no link is created, and the command includes the ***`errortemplate`***, with
`$set['throttled']` = 1 and the message `throttled`. If you show the same text there as in the success
case, the page reveals neither whether an account exists nor that throttling took place.

Against robots that fill in every form blindly, the ***form protection*** helps additionally:
`formguard=true` on the command and `(CMS:USER_TOKEN formguard=true)` in the form. Anyone who submits
the form faster than three seconds, or fills the invisible honeypot field, receives the same response as
with throttling:

```cms
(CMS:USER_PW container=members formguard=true uniquefield=email uniquevalue="{$_POST['data']['email']}"
  submit="$submit" successtemplate=u_pwsent errortemplate=u_pwfail)
…
<form method="post">
  (CMS:USER_TOKEN formguard=true)
  …
</form>
```

The link is valid for ***one hour*** and only once (setting `user.password_token_expiration`). It works
on any device – someone who has forgotten their password is often sitting at a new device. If the
sign-in via the link comes from an unknown device, [CMS:USER_LOGIN](user-login.md#sign-in-from-a-new-device)
reports it like any other sign-in.

Show ***the same text*** in the `errortemplate` as in the `successtemplate` (“If an account exists for
this address, we have sent a link.”). Otherwise anyone can try out which email addresses have an
account with you.

## Parameters in detail

### `successtemplate`

Included if a profile was found. In it, `$set['hash']` and `$set['user']` are available, and the profile
is the current record:

```cms
(CMS:USER_PW container=members uniquefield=email uniquevalue="$email" submit="$submit" successtemplate=u_pwsent errortemplate=u_pwfail)
```

### `errortemplate`

Included if `uniquevalue` was given but no profile matches:

```cms
(CMS:USER_PW … errortemplate=u_pwfail)
```

### `container`

```cms
(CMS:USER_PW container=members …)
```

### `uniquefield`

Field to search by (field type `chars`, `text` or `encrypted`). The comparison is literal and not
case-sensitive. If the value matches more than one profile, that counts as “not found”. Therefore,
mark the field as unique in the area:

```cms
(CMS:USER_PW container=members uniquefield=email …)
(CMS:USER_PW container=members uniquefield=nickname …)
```

### `uniquevalue`

Value sought, usually from the form. If empty, the command does nothing:

```cms
(CMS:USER_PW container=members uniquefield=email uniquevalue="{$_POST['data']['email']}" …)
```

### `submit`

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_PW … submit="$submit")
```

### `message`

```cms
(CMS:USER_PW … message[notfound]="There is no account for this address.")
```

---

[← Overview](README.md)
