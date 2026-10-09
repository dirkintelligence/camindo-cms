# CMS:TIME

Outputs the time of a date or time value, formatted.

```cmscode
(CMS:TIME field [parameter=value …])
```

***Method:*** `Cms::cmsTime()`  
***Without key:*** the first value without `=` is the `field`  
***At least one of:*** `field`, `useval`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `id` | `record ID or slug` | `(current record)` |  | Record to read from. Pure digit sequence = record_id, anything else = slug – the same rule as in the URL. |
| `field` ¹ | `field` | – | ² | Name of a record field whose content is used as the source. |
| `useval` | `time value` | – | ² | Value in text form that is used as the source. |
| `format` | `format specification` | – |  | ICU pattern, e.g. "HH:mm". Without specification, the language of the page determines the notation; full minutes are shown without seconds. |
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

Outputs only the ***time*** of a date/time value:

```cms
(CMS:TIME event_at)                    14:30
(CMS:TIME event_at format="HH:mm:ss")  14:30:00
(CMS:TIME event_at suffix=" o'clock")  14:30 o'clock
```

Without `format`, full minutes are shown without seconds.

## Parameters in detail

### `id`

`id` reads from another record instead of the current one – record_id or slug,
exactly as in the URL:

```cms
(CMS:TIME event_at id=1977)
(CMS:TIME event_at id=imprint)
```

### `field`

`field` is the main parameter and may be given without a key – both lines are the same:

```cms
(CMS:TIME field=event_at)
(CMS:TIME event_at)
```

### `useval`

Instead of a field, `useval` takes a value directly from the template – usually a
PHP variable or a value stored earlier with `set`:

```cms
(CMS:TIME useval="2024-03-07 14:30:00")
(CMS:TIME useval="{$set['value']}")
```

### `format`

`format` is an ICU pattern (`d` day, `MM` month with two digits, `MMMM` month name, `y` year,
`HH:mm` time, `EEEE` weekday). Without `format`, the language of the page decides (in the back end, the locale of the user):

```cms
(CMS:TIME online_at format="HH:mm")
```
Output: `14:30`

`h:mm a` gives the 12-hour notation (`2:30 PM`).

### `timezone`

```cms
(CMS:TIME event_at timezone=true)
```
Output: `14:30 Europe/Berlin`

### `replace`

`replace` replaces parts of text in the **already formatted** value – short form with `?` as
separator, or one entry per search text:

```cms
(CMS:TIME event_at replace=":?.")
(CMS:TIME event_at replace[:]="." replace[x]="y")
```

Output: `14.30`

### `ifempty`

`ifempty` replaces the **entire** output if the source is empty; `prefix` and
`suffix` are then omitted:

```cms
(CMS:TIME event_at prefix="<p>" suffix="</p>" ifempty="all day")
```

### `instead`

`instead` replaces the value as soon as the source is **not** empty – useful if
only the presence matters:

```cms
(CMS:TIME event_at instead="with time" ifempty="-")
```

### `prefix`

`prefix` / `suffix` surround the value and appear only if it is not empty –
so no empty `from  o'clock`:

```cms
(CMS:TIME event_at prefix="from " suffix=" o'clock")
```

### `suffix`

```cms
(CMS:TIME event_at suffix=" o'clock")
```

See `prefix`.

### `set`

`set` outputs nothing, but stores the result under `$set['time']` for the
surrounding PHP or a later command:

```cms
(CMS:TIME event_at set=time)
<?php if ($set['time'] !== '') { ?> … <?php echo $set['time']; ?> … <?php } ?>
```

---

[← Overview](README.md)
