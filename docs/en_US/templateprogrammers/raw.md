# CMS:RAW

Outputs the content unformatted.

```cmscode
(CMS:RAW field [parameter=value …])
```

***Method:*** `Cms::cmsRaw()`  
***Without key:*** the first value without `=` is the `field`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `id` | `record ID or slug` | `(current record)` |  | Record to read from. Pure digit sequence = record_id, anything else = slug – the same rule as in the URL. |
| `field` ¹ | `field` | – | yes | Name of the field whose content is output. |
| `ifempty` | `text` | – |  | Text that is output if the field is empty. |
| `instead` | `text` | – |  | Text that is output instead of the field content, if it is not empty. |
| `prefix` | `text` | – |  | Text that is placed before the field content, if it is not empty. |
| `suffix` | `text` | – |  | Text that is appended if the field content is not empty. |
| `set` | `array index of $set` | – |  | The content is not output, but stored in the variable $set. |

¹ can be given without a key as the first value.

## Usage

Outputs the field content ***without any processing*** – no escaping, no conversion of
line breaks, no entities. Intended for fields that contain finished HTML
(editor fields) or for values that are to be passed unchanged to PHP/JavaScript:

```cms
<div class="content">(CMS:RAW html_text)</div>
```

You must trust the content: whatever an editor writes into the field ends up on the
page unchanged – except for active content (`<script>`, `<iframe>`, event handlers,
`javascript:` links), which camindo CMS already removes when saving and when reading
from the database. For texts from user input (`ugc` areas) use `CMS:TEXT` with
`specialchars=true`.

The output parameters `ifempty`, `instead`, `prefix`, `suffix` and `set` work as with
all output commands.

## Parameters in detail

### `id`

`id` reads from another record instead of the current one – record_id or slug,
exactly as in the URL:

```cms
(CMS:RAW html_text id=1977)
(CMS:RAW html_text id=imprint)
```

### `field`

`field` is the main parameter and may be given without a key – both lines are the same:

```cms
(CMS:RAW field=html_text)
(CMS:RAW html_text)
```

### `ifempty`

`ifempty` replaces the **entire** output if the source is empty; `prefix` and
`suffix` are then omitted:

```cms
(CMS:RAW html_text prefix="<p>" suffix="</p>" ifempty="<p>No content.</p>")
```

### `instead`

`instead` replaces the value as soon as the source is **not** empty – useful if
only the presence matters:

```cms
(CMS:RAW html_text instead="present" ifempty="-")
```

### `prefix`

`prefix` / `suffix` surround the value and appear only if it is not empty –
so no empty `<section></section>`:

```cms
(CMS:RAW html_text prefix="<section>" suffix="</section>")
```

### `suffix`

```cms
(CMS:RAW html_text suffix="</section>")
```

See `prefix`.

### `set`

`set` outputs nothing, but stores the result under `$set['html']` for the
surrounding PHP or a later command:

```cms
(CMS:RAW html_text set=html)
<?php if ($set['html'] !== '') { ?> … <?php echo $set['html']; ?> … <?php } ?>
```

---

[← Overview](README.md)
