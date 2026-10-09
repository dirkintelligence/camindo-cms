# CMS:DATETIME

Outputs date and time of a date value, formatted.

```cmscode
(CMS:DATETIME field [parameter=value …])
```

***Method:*** `Cms::cmsDatetime()`  
***Without key:*** the first value without `=` is the `field`  
***At least one of:*** `field`, `useval`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `id` | `record ID or slug` | `(current record)` |  | Record to read from. Pure digit sequence = record_id, anything else = slug – the same rule as in the URL. |
| `field` ¹ | `field` | – | ² | Name of a record field whose content is used as the source. |
| `useval` | `date value` | – | ² | Value in text form that is used as the source. |
| `format` | `format specification` | – |  | ICU pattern, e.g. "dd.MM.y HH:mm". Without specification, the language of the page determines the notation. |
| `autotime` | `true / false` | `false` |  | true: If no time is present (00:00), it is not displayed. |
| `timezone` | `true / false` | `false` |  | true: The name of the time zone is appended (e.g. "Europe/Berlin"). |
| `replace[…]` | `text?text` | – |  | If the text before the question mark occurs in the formatted value, it is replaced by the text after the question mark (e.g. "Jan?January"). |
| `ifempty` | `text` | – |  | Text that is output if the source ('field' or 'useval') is empty. |
| `instead` | `text` | – |  | Text that is output instead of the source, if it is not empty. |
| `prefix` | `text` | – |  | Text that is placed before the formatted value, if it is not empty. |
| `suffix` | `text` | – |  | Text that is appended if the formatted value is not empty. |
| `set` | `array index of $set` | – |  | The formatted value is not output, but stored in the variable $set. |

¹ can be given without a key as the first value.  
² at least one of the parameters marked with ² must be given.

`[…]` – the parameter accepts subkeys, for example `name[key]=value`.

## Usage

Outputs date **and** time. Like `CMS:DATE`, but with the time part:

```cms
(CMS:DATETIME changed_at)                               07.03.2024, 14:30
(CMS:DATETIME changed_at format="dd.MM.y HH:mm")        07.03.2024 14:30
(CMS:DATETIME changed_at format="dd.MM.y HH:mm" autotime=true)
```

With `autotime=true`, the time disappears if none was set in the value
(`00:00:00`) – for appointments that last “all day”.

## Parameters in detail

### `id`

`id` reads from another record instead of the current one – record_id or slug,
exactly as in the URL:

```cms
(CMS:DATETIME changed_at id=1977)
(CMS:DATETIME changed_at id=imprint)
```

### `field`

`field` is the main parameter and may be given without a key – both lines are the same:

```cms
(CMS:DATETIME field=changed_at)
(CMS:DATETIME changed_at)
```

### `useval`

Instead of a field, `useval` takes a value directly from the template – usually a
PHP variable or a value stored earlier with `set`:

```cms
(CMS:DATETIME useval="2024-03-07 14:30:00")
(CMS:DATETIME useval="{$set['value']}")
```

### `format`

`format` is an ICU pattern (`d` day, `MM` month with two digits, `MMMM` month name, `y` year,
`HH:mm` time, `EEEE` weekday). Without `format`, the language of the page decides (in the back end, the locale of the user):

```cms
(CMS:DATETIME online_at format="dd.MM.y HH:mm")
```
Output: `07.03.2024 14:30`

### `autotime`

Only together with `format`: if the value contains no time (`00:00:00`), the
time part is removed from the output:

```cms
(CMS:DATETIME event_at format="dd.MM.y HH:mm" autotime=true)
```
Output for `2024-03-07 00:00:00`: `07.03.2024` – for `2024-03-07 14:30:00`: `07.03.2024 14:30`

### `timezone`

`timezone=true` appends the name of the time zone in which the value is displayed:

```cms
(CMS:DATETIME online_at timezone=true)
```
Output: `07.03.2024, 14:30 Europe/Berlin`

### `replace`

`replace` replaces parts of text in the **already formatted** value – short form with `?` as
separator, or one entry per search text:

```cms
(CMS:DATETIME changed_at replace=", ? at ")
(CMS:DATETIME changed_at replace[, ]=" at " replace[x]="y")
```

Output: `07.03.2024 at 14:30`

### `ifempty`

`ifempty` replaces the **entire** output if the source is empty; `prefix` and
`suffix` are then omitted:

```cms
(CMS:DATETIME event_at prefix="<p>" suffix="</p>" ifempty="Date to follow")
```

The ***placeholders*** for “no date” and “no end” that camindo CMS stores for an unlimited
`offline_at` (before 2 January 1000 or from 31 December 8999) also count as empty – with and
without `format`.

### `instead`

`instead` replaces the value as soon as the source is **not** empty – useful if
only the presence matters:

```cms
(CMS:DATETIME event_at instead="Date confirmed" ifempty="-")
```

### `prefix`

`prefix` / `suffix` surround the value and appear only if it is not empty –
so no empty `Updated: `:

```cms
(CMS:DATETIME changed_at prefix="Updated: " suffix="")
```

### `suffix`

```cms
(CMS:DATETIME changed_at suffix=" o'clock")
```

See `prefix`.

### `set`

`set` outputs nothing, but stores the result under `$set['changed']` for the
surrounding PHP or a later command:

```cms
(CMS:DATETIME changed_at set=changed)
<?php if ($set['changed'] !== '') { ?> … <?php echo $set['changed']; ?> … <?php } ?>
```

---

[← Overview](README.md)
