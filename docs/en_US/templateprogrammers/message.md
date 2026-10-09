# CMS:MESSAGE

Produces a text output from current error messages.

```cmscode
(CMS:MESSAGE useval [parameter=value …])
```

***Method:*** `Cms::cmsMessage()`  
***Without key:*** the first value without `=` is the `useval`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `useval` ¹ | `text` | – |  | Value in text form that is used as the source. |
| `prefix` | `text` | – |  | Text that is output before the messages, if there are any. |
| `suffix` | `text` | – |  | Text that is output after the messages, if there are any. |
| `ifempty` | `text` | – |  | Text if no message is present. |
| `instead` | `text` | – |  | Text that is output instead of the messages. |
| `set` | `array index of $set` | – |  | The message is not displayed, but written to the variable $set. |

¹ can be given without a key as the first value.

## Usage

Outputs the messages that commands on this page have stored – typically
field errors from `CMS:USER_INPUT`, `CMS:USER_LOGIN` or `CMS:USER_REGISTER`. Without a message,
the command stays silent:

```cms
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=welcome)
<p class="error">(CMS:MESSAGE)</p>
```

Several messages are separated by `<br>`. A frame is only possible through
`prefix`/`suffix` – it appears only if there are messages:

```cms
(CMS:MESSAGE prefix="<div class=alert>" suffix="</div>")
```

## Parameters in detail

### `useval`

Output your own text instead of the system messages (the messages are then ignored;
if `useval` is empty, the messages appear again):

```cms
(CMS:MESSAGE useval="{$set['hint']}" prefix="<p class=hint>" suffix="</p>")
```

The text is output unchanged. Do not insert values from the request
(`$_GET`, `$_POST`) here – for that, use `(CMS:TEXT useval="…" specialchars=true)`.

### `prefix`

```cms
(CMS:MESSAGE prefix="<div class=alert>" suffix="</div>")
```

Without specification, the messages are output without a frame.

### `suffix`

```cms
(CMS:MESSAGE prefix="<ul class=errors><li>" suffix="</li></ul>")
```

### `ifempty`

Text if **no** message is present:

```cms
(CMS:MESSAGE ifempty="Everything is in order.")
```

### `instead`

Fixed text as soon as a message is present – if the wording should not be shown:

```cms
(CMS:MESSAGE instead="Please check your entries.")
```

### `set`

Provide messages for PHP, for example to show them next to the respective field:

```cms
(CMS:MESSAGE set=errors)
<?php if ($set['errors'] !== '') { ?><div class="alert"><?php echo $set['errors']; ?></div><?php } ?>
```

---

[← Overview](README.md)
