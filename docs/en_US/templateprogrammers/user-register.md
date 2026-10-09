# CMS:USER_REGISTER

Creates a profile record from the data supplied and sets the status to status=2.

```cmscode
(CMS:USER_REGISTER [parameter=value …])
```

***Method:*** `Modules\Record\RecordUser::cmsRegister()`  
***PHP snippet:*** `include_and_stop` (after)

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `successtemplate` | – | – | yes | Detail template that is included after a successful registration. |
| `container` | `container ID or name` | – | yes | ID or name of the profile container in which the data of the frontend users is to be saved. |
| `emailfield` | `field name` | – | yes | Name of the field in which the email address of the frontend user is saved (the confirmation link is sent there). |
| `fields` | `comma-separated list` | – |  | Names of the input fields that may be written. Empty = all fields defined for the container. |
| `overwrite[…]` | `key-value pairs` | – |  | Explicitly overwrites the input of the frontend user (including meta fields, except record_id, area_id, instance_id, tmp, deleted, origin_id, owner_id, created_by, changed_by). Do not pass on any input of the frontend user here! |
| `message[…]` | `message` | – |  | Error message. In the index: use case; in the value: message text (see the table at CMS:USER_INPUT). |
| `submit` | `text value` | – | yes | The command runs only if this field contains a text. |
| `owner` | `user ID` | – |  | admin_id of the back-end owner of the new profile (record.owner_id, created_by). If not given, user.default_owner_id applies. |
| `data` | `PHP variable name` | `data` |  | Name of the PHP array that contains the data to be saved. |
| `csrf` | `true / false` | `true` |  | true: The request must contain a valid token from [CMS:USER_TOKEN](user-token.md) (protection against CSRF). false switches off the check for this command – only with good reason. |
| `formguard` | `true / false / seconds` | `false` |  | true: form protection against robots – the form must contain the fields from `(CMS:USER_TOKEN formguard=true)` and may not be submitted earlier than 3 seconds (`user.formguard_min_seconds`). A number sets the minimum time for this form (`formguard=5`), `0` switches it off. Otherwise the message `throttled`. |

`[…]` – the parameter accepts sub-keys, e.g. `name[key]=value`.

## Usage

Creates a profile (status 2 = unconfirmed) and generates a confirmation token in
`$set['hash']`; the `successtemplate` sends the confirmation link, whose target page calls
`CMS:USER_REGISTER_CONFIRM`. The sign-in name and email address must still be free in the
area, and `emailfield` must contain a valid address. Sign-in names that begin with `user_`
are reserved – that is how anonymized profiles are named (`user_{record_id}`, see
[deletion policies](../admins/15-privacy.md#deleting-according-to-deletion-policy));
registration rejects them:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_REGISTER container=members emailfield=contact_email fields="nickname,contact_email,password"
  submit="$submit" successtemplate=u_registered)
<h1>Register</h1>
<p class="error">(CMS:MESSAGE)</p>
<form method="post">
  (CMS:USER_TOKEN)
  <input name="data[nickname]"> <input name="data[contact_email]" type="email">
  <input name="data[password]" type="password">
  <button name="submit" value="1">Create account</button>
</form>
```

`u_registered` sends the link with [CMS:EMAIL](email.md). The address is there in
`$set['email']` – ***always under this name***, regardless of what the field from
`emailfield` is called (here `contact_email`). Under the field name it is found only in the
profile, `$set['user']['contact_email']`:

```cms
(CMS:URL id=0 template=u_confirm absolute=true set=link)
(CMS:EMAIL submit="1" to="{$set['email']}" subject="Please confirm"
  body="Activate your account: {$set['link']}?hash={$set['hash']}")
(CMS:MESSAGE)
<p>Please confirm your email address.</p>
```

Message indexes: `container`, `email`, `exists`, `save`, `throttled`, `owner` (no valid owner,
see `owner`), `token`, and field errors of the area.

### Throttling and form protection

Each registration sends an email to the address entered. camindo limits this
(***throttling***): five registrations per origin (IP address) and hour, three per email
address and day, 200 per website and hour. Registrations and attempts with a name that is
already taken are counted – a typo in the password policy does not count. The message is
then `throttled`, and `$set['throttled']` = 1. As with
[CMS:USER_PW](user-pw.md#throttling-and-form-protection), `formguard=true` (with
`(CMS:USER_TOKEN formguard=true)` in the form) protects against robots.

The message `exists` reveals that a name or an address already exists; the throttling limits
how often this can be tried.

Registrations that are never confirmed are deleted by camindo after seven days
(`user.unconfirmed_days`): anyone who enters the address of someone else only blocks it for a
short time.

## Parameters in detail

### `successtemplate`

Included after the profile has been created. It contains:

| `$set[…]` | Content |
|---|---|
| `hash` | confirmation token for the confirmation link |
| `email` | the checked email address – independent of the name of the field from `emailfield` |
| `user_id` | record_id of the new profile |
| `user` | the profile as an array (field names as keys); it is also the current record |

```cms
(CMS:USER_REGISTER container=members emailfield=email submit="$submit" successtemplate=u_registered)
```

### `container`

```cms
(CMS:USER_REGISTER container=members …)
```

### `emailfield`

Field with the email address – it is checked and stored in `$set['email']` (under this fixed
name, not under the field name):

```cms
(CMS:USER_REGISTER container=members emailfield=contact_email …)
```

### `fields`

Take only these fields from the form:

```cms
(CMS:USER_REGISTER container=members emailfield=email fields="nickname,email,password" …)
```

### `overwrite`

Fixed values, including meta fields – no user input. Excluded are `record_id`, `area_id`,
`instance_id`, `tmp`, `deleted`, `origin_id`, `owner_id`, `created_by` and `changed_by`. The
status (`status_id` 2) can be overwritten here – then the confirmation by link is skipped:

```cms
(CMS:USER_REGISTER container=members emailfield=email overwrite[role]=member overwrite[source]=web …)
```

### `message`

```cms
(CMS:USER_REGISTER container=members emailfield=email submit="$submit" successtemplate=u_registered
  message[exists]="Name or email address is already taken."
  message[email]="Please enter a valid email address."
)
```

### `submit`

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_REGISTER … submit="$submit")
```

### `data`

```cms
<input name="reg[nickname]"> …
(CMS:USER_REGISTER container=members emailfield=email data=reg submit="$submit" successtemplate=u_registered)
```

---

[← Overview](README.md)
