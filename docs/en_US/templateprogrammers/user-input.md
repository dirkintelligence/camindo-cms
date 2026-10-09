# CMS:USER_INPUT

Creates or updates a record from data that a frontend user has entered.

```cmscode
(CMS:USER_INPUT [parameter=value …])
```

***Method:*** `Modules\Record\RecordUser::cmsInput()`  
***PHP snippet:*** `include_and_stop` (after)

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `successtemplate` | `template name` | – |  | Template that is included when the entry has been saved successfully. |
| `data` | `PHP variable name` | `data` |  | Name of the PHP array that contains the data to be saved. |
| `overwrite[…]` | `key-value pairs` | – |  | Explicitly overwrites the input of the frontend user (including meta fields, except record_id, area_id, instance_id, tmp, deleted, origin_id, owner_id, created_by, changed_by). Do not pass on any input of the frontend user here! |
| `container` | `container ID or name` | – | yes | ID or name of the content area in which the new record is to be saved. |
| `message[…]` | `text` | `default text` |  | Error message depending on the index (see table). |
| `submit` | `text value` | – | yes | The command runs only if this field contains a text. |
| `fields` | `comma-separated list` | – |  | Names of the input fields that may be written. Empty = all fields defined for the container. |
| `id` | `record ID` | – |  | If given, an attempt is made to update this record (UPDATE). Otherwise a new one is created (CREATE). |
| `ignoreuser` | `true / false` | `false` |  | true: The record to be adjusted need not belong to the signed-in frontend user (caution: observe the security notes!). |
| `forceupdate` | `true / false` | `false` |  | true: Forces the update of the record given via the parameter “id”. On error, a message is output. |
| `masterid` | `record ID` | – |  | Only when creating in areas with a tree structure: parent node of the new record (parent_id). |
| `owner` | `user ID` | – |  | Only when creating: admin_id of the back-end owner (record.owner_id, created_by). If not given, user.default_owner_id applies. |
| `removefiles` | `comma-separated list` | – |  | Names of the upload fields whose content is to be deleted. |
| `currentfield` | `field name` | `current_password` |  | Only profile containers: name of the form field `data[…]` with the current password. A new password is saved only together with the current one – except directly after signing in via the reset link. Message `current`. |
| `csrf` | `true / false` | `true` |  | true: The request must contain a valid token from [CMS:USER_TOKEN](user-token.md) (protection against CSRF). false switches off the check for this command – only with good reason. |

`[…]` – the parameter accepts sub-keys, e.g. `name[key]=value`.

## Usage

Saves form input as a record – creates a new one or changes one of the frontend user's own
records (with `id`). The fields are called `data[fieldname]`, exactly as in the back-end input
mask; uploads go through the same form. After success, `$set['record_id']` is available, the
`successtemplate` is included, and the page ends:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_INPUT container=comments submit="$submit" successtemplate=u_saved)
(CMS:USER_AUTH)
<p class="error">(CMS:MESSAGE)</p>
<form method="post" enctype="multipart/form-data">
  (CMS:USER_TOKEN)
  <textarea name="data[comment]">(CMS:TEXT useval="{$_POST['data']['comment']}" specialchars=true lbrconvert=false)</textarea>
  <input type="file" name="data[photo]">
  <button name="submit" value="1">Save</button>
</form>
```

Change one's own record:

```cms
<?php $submit = $_POST['submit'] ?? ''; $id = (int)($_POST['id'] ?? 0); ?>
(CMS:USER_INPUT container=comments id="$id" forceupdate=true submit="$submit" successtemplate=u_saved)
```

Contact form without sign-in (`ignoreuser`), writing the sender's IP address into its own field
`remote_ip` of the area:

```cms
(CMS:USER_INPUT container=inquiries ignoreuser=true submit="$submit" fields="name,email,message"
  overwrite[remote_ip]="{$_SERVER['REMOTE_ADDR']}" successtemplate=inquiry_thanks)
```

Who may write: in `ugc` areas, signed-in frontend users whose profile area is enabled for the
area; in `profile` areas only one's own profile (with `id` – a new profile is created only by
`CMS:USER_REGISTER`); in ordinary record areas only with `ignoreuser=true`. All checks of the
area (required fields, unique values, file types) apply as in the back end; errors appear via
`CMS:MESSAGE`. Message indexes for `message[…]`: `container`, `access`, `notfound`, `save`,
`current`, `blocked`, `owner` (no valid owner when creating), `token`.

### Changing the password

A new ***password*** in one's own profile is saved by the command only together with the
***current password*** in the field `data[current_password]`. A stranger who gets hold of an
open session cannot take over the account this way. The exception is directly after signing in
via the link from [CMS:USER_PW](user-pw.md): then `$set['password_reset']` is 1 and the field is
omitted (for one hour, until the new password has been saved). After saving, all other
sign-ins of the profile are ended, and `$set['password_changed']` is 1 – the `successtemplate`
can notify the owner by [CMS:EMAIL](email.md).

```cms
(CMS:USER_PROTECT errortemplate=u_denied)
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_INPUT container=members id="{$set['user_id']}" forceupdate=true fields=password
  submit="$submit" successtemplate=u_pw_saved)
<p class="error">(CMS:MESSAGE)</p>
<form method="post">
  (CMS:USER_TOKEN)
  <?php if (empty($set['password_reset'])) { ?>
  <input type="password" name="data[current_password]" autocomplete="current-password">
  <?php } ?>
  <input type="password" name="data[password]" autocomplete="new-password"
    data-c9-password="(CMS:USER_PASSWORD_RULES container=members)">
  <button name="submit" value="1">Save</button>
</form>
```

If the password field in the area carries the extension “Password policy”, the server checks the
minimum length and the character classes. [CMS:USER_PASSWORD_RULES](user-password-rules.md)
shows the same rules in the form while the user types.

## Parameters in detail

### `successtemplate`

Included after saving. In it, the saved record is the current one (`$cms`), and
`$set['record_id']` is its ID:

```cms
(CMS:USER_INPUT container=comments submit="$submit" successtemplate=u_saved)
```

Without `successtemplate`, the template continues normally after saving.

### `data`

Name of the request array (default `data`):

```cms
<input name="form[comment]">
(CMS:USER_INPUT container=comments data=form submit="$submit")
```

### `overwrite`

Set values in advance – including meta fields – regardless of what the frontend user sends.
***No*** input of the frontend user belongs here. Excluded are `record_id`, `area_id`,
`instance_id`, `tmp`, `deleted`, `origin_id`, `owner_id`, `created_by` and `changed_by`. Without
a specification, the command sets `needs_review` to 1:

```cms
(CMS:USER_INPUT container=comments submit="$submit"
  overwrite[article_id]="{$cms['record_id']}"
  overwrite[status_id]=2
)
```

### `container`

Area (name or ID) in which saving takes place – type `ugc` or `profile`; with `ignoreuser=true`
also an ordinary record area:

```cms
(CMS:USER_INPUT container=comments …)
(CMS:USER_INPUT container=58 …)
```

### `message`

Your own message texts:

```cms
(CMS:USER_INPUT container=comments submit="$submit"
  message[access]="Please sign in to comment."
  message[save]="The comment could not be saved."
)
```

### `submit`

The command runs only if `submit` has a value – so not on the first call of the form:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_INPUT container=comments submit="$submit" …)
<button name="submit" value="1">Save</button>
```

### `fields`

Take only these fields from the inputs – everything else in the form is ignored:

```cms
(CMS:USER_INPUT container=members id="{$set['user_id']}" fields="nickname,avatar" submit="$submit")
```

### `id`

Change an existing record. Without `forceupdate`, a new record is created if the ID is unknown
or belongs to someone else:

```cms
(CMS:USER_INPUT container=comments id="{$_POST['id']}" submit="$submit")
```

### `ignoreuser`

No sign-in and no ownership check – for contact and guestbook forms. The template is responsible
(spam protection, field list via `fields`):

```cms
(CMS:USER_INPUT container=guestbook ignoreuser=true fields="name,message" submit="$submit" successtemplate=gb_thanks)
```

### `forceupdate`

With `id`: instead of creating a new record for an unknown or foreign ID, output the message
`notfound`:

```cms
(CMS:USER_INPUT container=comments id="$id" forceupdate=true submit="$submit")
```

### `masterid`

Parent node in an area with a tree structure (becomes `parent_id`):

```cms
(CMS:USER_INPUT container=forum masterid="{$cms['record_id']}" submit="$submit")     Reply below the current post
```

### `currentfield`

Another name for the field with the current password (see
[Changing the password](#changing-the-password)):

```cms
(CMS:USER_INPUT container=members id="{$set['user_id']}" fields=password currentfield=old_pw …)
<input type="password" name="data[old_pw]">
```

### `removefiles`

Empty upload fields – for example via a checkbox “Remove photo”:

```cms
<label><input type="checkbox" name="remove_photo" value="photo"> Remove photo</label>
(CMS:USER_INPUT container=comments id="$id" removefiles="{$_POST['remove_photo']}" submit="$submit")
```

---

[← Overview](README.md)
