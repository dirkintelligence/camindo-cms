# CMS:DATE

Outputs the date part of a date value, formatted.

```cmscode
(CMS:DATE field [parameter=value …])
```

***Method:*** `Cms::cmsDate()`  
***Without key:*** the first value without `=` is the `field`  
***At least one of:*** `field`, `useval`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `id` | `record ID or slug` | `(current record)` |  | Record to read from. Pure digit sequence = record_id, anything else = slug – the same rule as in the URL. |
| `field` ¹ | `field` | – | ² | Name of a record field whose content is used as the source. |
| `useval` | `date value` | – | ² | Value in text form that is used as the source. |
| `format` | `format specification` | – |  | ICU pattern, e.g. "dd.MM.y". Without specification, the language of the page determines the notation. |
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

Outputs the ***date part*** of a date/time value. The source is a date or date-time field
(meta fields `online_at`, `created_at`, `changed_at` or your own fields).
Without `format`, the language of the page determines the notation:

```cms
(CMS:DATE online_at)                            07.03.2024   (de)
(CMS:DATE online_at format="EEEE, d MMMM y")    Thursday, 7 March 2024
(CMS:DATE online_at format="y-MM-dd")           2024-03-07
```

Typical in lists:

```cms
<time datetime="(CMS:DATE online_at format='y-MM-dd')">(CMS:DATE online_at)</time>
```

`CMS:DATE`, `CMS:DATETIME` and `CMS:TIME` are the same command with a different
section; all three know the same parameters (`CMS:DATETIME` additionally `autotime`).

## Parameters in detail

### `id`

`id` reads from another record instead of the current one – record_id or slug,
exactly as in the URL:

```cms
(CMS:DATE online_at id=1977)
(CMS:DATE online_at id=imprint)
```

### `field`

`field` is the main parameter and may be given without a key – both lines are the same:

```cms
(CMS:DATE field=online_at)
(CMS:DATE online_at)
```

### `useval`

Instead of a field, `useval` takes a value directly from the template – usually a
PHP variable or a value stored earlier with `set`:

```cms
(CMS:DATE useval="2024-12-24")
(CMS:DATE useval="{$set['value']}")
```

```cms
(CMS:DATE useval="{$set['user']['created_at']}")
```

### `format`

`format` is an ICU pattern (`d` day, `MM` month with two digits, `MMMM` month name, `y` year,
`HH:mm` time, `EEEE` weekday). Without `format`, the language of the page decides (in the back end, the locale of the user):

```cms
(CMS:DATE online_at format="d MMMM y")
```
Output: `7 March 2024`

### `timezone`

```cms
(CMS:DATE online_at timezone=true)
```
Output: `07.03.2024 Europe/Berlin`

### `replace`

`replace` replaces parts of text in the **already formatted** value – short form with `?` as
separator, or one entry per search text:

```cms
(CMS:DATE online_at format="MMM y" replace="Jan.?January")
(CMS:DATE online_at format="MMM y" replace[Jan.]="January" replace[x]="y")
```

Output: `January 2026` instead of `Jan. 2026`

### `ifempty`

`ifempty` replaces the **entire** output if the source is empty; `prefix` and
`suffix` are then omitted:

```cms
(CMS:DATE offline_at prefix="<p>" suffix="</p>" ifempty="unlimited")
```

The ***placeholders*** for “no date” and “no end” that camindo CMS stores for an unlimited
`offline_at` (before 2 January 1000 or from 31 December 8999) also count as empty – with and
without `format`.

### `instead`

`instead` replaces the value as soon as the source is **not** empty – useful if
only the presence matters:

```cms
(CMS:DATE offline_at instead="limited" ifempty="-")
```

### `prefix`

`prefix` / `suffix` surround the value and appear only if it is not empty –
so no empty `published on `:

```cms
(CMS:DATE online_at prefix="published on " suffix="")
```

### `suffix`

```cms
(CMS:DATE online_at suffix=" (first publication)")
```

See `prefix`.

### `set`

`set` outputs nothing, but stores the result under `$set['date']` for the
surrounding PHP or a later command:

```cms
(CMS:DATE online_at set=date)
<?php if ($set['date'] !== '') { ?> … <?php echo $set['date']; ?> … <?php } ?>
```

---

[← Overview](README.md)
