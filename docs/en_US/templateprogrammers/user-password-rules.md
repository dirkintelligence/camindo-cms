# CMS:USER_PASSWORD_RULES

Outputs the rules of the ***password policy*** of a profile container, including their texts – for
display in the form (strength bar and speech bubble with the requirements).

```cmscode
(CMS:USER_PASSWORD_RULES container=… [parameter=value …])
```

***Method:*** `Modules\Record\RecordUser::cmsPasswordRules()`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `container` | `container ID or name` | – | yes | Profile container whose password field is meant. |
| `field` | `field name` | sign-in password field |  | Password field of the container. If it carries the extension “Password policy”, its rules apply; otherwise the settings of the administrator apply (the server then does not check). |
| `set` | `$set array index` | – |  | The output is not printed, but stored in `$set`. |

The output is already masked with `htmlspecialchars` for an HTML attribute – do not mask it again.

## Usage

When registering and when changing the password, the server checks the **password policy**
(minimum length, special characters, upper- and lowercase letters) if the administrator has
assigned the extension “Password policy” to the password field. So that your visitors see the
requirements while typing, there is a small script for this – the same one that runs in the back
end. It shows a bar under the field (red = weak, orange = medium, green = strong) and a speech
bubble in which the fulfilled requirements are ticked off.

**1. Include the script and CSS.** Copy the two files of the extension unchanged into your
template – as `<style>` and `<script(CMS:NONCE)>`. The nonce is needed by the script under a script
policy (see [CMS:NONCE](nonce.md)). It is best placed in a separate include that you add at the end
of the page:

```
Core/Extensions/Password/public/css/password.ext.css   →  <style> … </style>
Core/Extensions/Password/public/js/password.ext.js     →  <script(CMS:NONCE)> … </script>
```

The files need nothing from the back end and no library. After an update of camindo CMS, copy them
again if the rules have changed.

**2. Mark the field.** The attribute `data-c9-password` takes the rules from this command – the texts
are in the language of the page:

```cms
<input type="password" name="data[password]" autocomplete="new-password"
  data-c9-password="(CMS:USER_PASSWORD_RULES container=members)">
(CMS:INCLUDE password_assets)
```

When the page loads, the script equips every field with `data-c9-password`. For fields that are only
created later by JavaScript, use `C9Password.attach(field, options)`.

**Important:** the display is an aid, not a check. The server is decisive – without the extension on
the field, it accepts any password, even if the form shows the rules. The error message for a
password that is too weak appears like other field errors, via [CMS:MESSAGE](message.md).

A complete form for changing the password is shown at
[CMS:USER_INPUT](user-input.md#changing-the-password).

## Parameters in detail

### `container`

Name or ID of the profile container:

```cms
(CMS:USER_PASSWORD_RULES container=members)
```

### `field`

A password field other than the one set for sign-in:

```cms
(CMS:USER_PASSWORD_RULES container=members field=pin)
```

### `set`

Does not output the rules, but stores them in `$set['pwrules']` (already masked for an HTML
attribute):

```cms
(CMS:USER_PASSWORD_RULES container=members set=pwrules)
```

---

[← Overview](README.md)
