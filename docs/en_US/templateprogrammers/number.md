# CMS:NUMBER

Outputs a numeric value, formatted.

```cmscode
(CMS:NUMBER field [parameter=value …])
```

***Method:*** `Cms::cmsNumber()`  
***Without key:*** the first value without `=` is the `field`  
***At least one of:*** `field`, `useval`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `id` | `record ID or slug` | `(current record)` |  | Record to read from. Pure digit sequence = record_id, anything else = slug – the same rule as in the URL. |
| `field` ¹ | `field` | – | ² | Name of a record field whose content is used as the source. |
| `useval` | `number` | – | ² | Value in text form that is used as the source. |
| `ifempty` | `text` | – |  | Text that is output if the source is empty or the numeric value (after mul/add) is 0. |
| `instead` | `number` | – |  | Number that becomes the source, if the original source is not empty. |
| `mul` | `number` | `1` |  | Number by which the source is multiplied. |
| `add` | `number` | `0` |  | Number that is added to the source. |
| `format` | `format` | – |  | Formatting analogous to the PHP command sprintf(). |
| `decimals` | `number 0-10` | – |  | Fixed number of decimal places (rounded); `decimal` and `thousands_separator` still apply. Without specification, as many as the number has. |
| `decimal` | `character` | `.` |  | Character that replaces the decimal point. |
| `thousands_separator` | `character` | – |  | Character inserted as thousands separator (every three digits from right to left). |
| `nullcent` | `text` | – |  | Text that replaces a missing decimal value. |
| `replace[…]` | `text?text` | – |  | If the text before the question mark occurs in the formatted value, it is replaced by the text after the question mark (e.g. ".00?" removes empty decimal places). |
| `prefix` | `text` | – |  | Text that is placed before the formatted value, if it is not "0" or empty. |
| `suffix` | `text` | – |  | Text that is appended to the formatted value, if it is not "0" or empty. |
| `set` | `array index of $set` | – |  | The formatted value is not output, but stored in the variable $set instead. |
| `sizefactor` | `number of bytes in a kilobyte` | – |  | 1000 or 1024. If > 0, a readable file size is calculated (e.g. 15.2 GB). |

¹ can be given without a key as the first value.  
² at least one of the parameters marked with ² must be given.

`[…]` – the parameter accepts subkeys, for example `name[key]=value`.

## Usage

Outputs a numeric value, formatted. Without further parameters, the number appears as
it is stored – with a point as decimal separator and without thousands separator:

```cms
(CMS:NUMBER price)                                        1234.5
(CMS:NUMBER price decimal="," thousands_separator=".")    1.234,5
(CMS:NUMBER price decimals=2 decimal="," thousands_separator="." suffix=" €")   1.234,50 €
```

Calculating before output (`mul`, `add`), making file sizes readable (`sizefactor`):

```cms
(CMS:NUMBER price mul=1.19 decimals=2 decimal="," suffix=" € incl. VAT")
(CMS:NUMBER filesize sizefactor=1024)                     15.2 MB
```

If the calculation results in `0`, `0` is output (formatted, e.g. `0.00` with `decimals=2`):

```cms
(CMS:NUMBER useval="{$a}" add=-1)                          0  (with $a = 1)
```

As soon as `prefix`, `suffix` or `ifempty` is given, however, the value `0` counts as
empty – a price of `0` therefore stays without ` €`. What counts is the numeric value after
`mul`/`add`, not the formatted text: even `0.00` (with `decimals` or `format`) then counts as
empty. An empty source (field without content, empty `useval`) always results in empty
output – also with `mul`/`add`.

## Parameters in detail

### `id`

`id` reads from another record instead of the current one – record_id or slug,
exactly as in the URL:

```cms
(CMS:NUMBER price id=1977)
(CMS:NUMBER price id=imprint)
```

### `field`

`field` is the main parameter and may be given without a key – both lines are the same:

```cms
(CMS:NUMBER field=price)
(CMS:NUMBER price)
```

### `useval`

Instead of a field, `useval` takes a value directly from the template – usually a
PHP variable or a value stored earlier with `set`:

```cms
(CMS:NUMBER useval="{$this->db->num_rows_unlimited}")
(CMS:NUMBER useval="{$set['value']}")
```

A decimal comma is understood: `useval="3,5"` gives `3.5`.

### `ifempty`

With `ifempty`, the numeric value `0` also counts as empty – regardless of how it would be formatted:

```cms
(CMS:NUMBER stock ifempty="sold out" suffix=" pcs")
```
Output for `stock` = 0: `sold out` – for 12: `12 pcs`

### `instead`

`instead` replaces the ***source*** (not the result) if it is not empty –
calculation and formatting then run on the new value:

```cms
(CMS:NUMBER price instead=99 decimal=",")
```

### `mul`

```cms
(CMS:NUMBER price mul=1.19 decimals=2 decimal="," suffix=" €")   gross price
(CMS:NUMBER share mul=100 suffix=" %")                           0.25 → 25 %
```

### `add`

```cms
(CMS:NUMBER price add=4.90 decimals=2 decimal="," suffix=" € incl. shipping")
```
`mul` is applied before `add`: result = source × mul + add.

### `format`

`sprintf()` pattern; it alone determines the output, so `decimal`, `thousands_separator`,
`decimals` and `nullcent` are then ignored. For prices with a decimal comma and
thousands point, `decimals` is therefore the right parameter; use `format` for special cases
such as leading zeros:

```cms
(CMS:NUMBER price format="%.2f")        1234.50
(CMS:NUMBER position format="%03d")     007
```

### `decimals`

Fixed number of decimal places, rounded commercially – the separators from `decimal`
and `thousands_separator` still apply:

```cms
(CMS:NUMBER price decimals=2 decimal="," thousands_separator=".")
```
Output for `1234.5`: `1.234,50` – for `19.999`: `20,00`

An amount in cents (field type currency amount) becomes euros with `mul=0.01`:

```cms
(CMS:NUMBER cur_price mul=0.01 decimals=2 decimal="," suffix=" €")
```

### `decimal`

```cms
(CMS:NUMBER price decimal=",")
```
Output: `1234,5`

### `thousands_separator`

```cms
(CMS:NUMBER views thousands_separator=".")
(CMS:NUMBER views thousands_separator=" ")
```
Output: `1.234.567` or `1 234 567`

### `nullcent`

Replacement for missing decimal places (without specification: none) – for prices that should always show two digits:

```cms
(CMS:NUMBER price decimal="," nullcent="00" suffix=" €")
```
Output for `1234`: `1234,00 €` – for `1234.5` it remains `1234,5 €` (only **missing** decimal places
are replaced; use `decimals=2` for fixed rounding).

### `replace`

`replace` replaces parts of text in the **already formatted** value – short form with `?` as
separator, or one entry per search text:

```cms
(CMS:NUMBER price replace=".00?")
(CMS:NUMBER price replace[.00]="" replace[x]="y")
```

Output for `12.00`: `12`

### `prefix`

Appears only if the formatted value is neither empty nor `0`:

```cms
(CMS:NUMBER discount prefix="- " suffix=" %")
```

### `suffix`

```cms
(CMS:NUMBER price suffix=" €")
```

See `prefix`.

### `set`

`set` outputs nothing, but stores the result under `$set['price']` for the
surrounding PHP or a later command:

```cms
(CMS:NUMBER price set=price)
<?php if ($set['price'] !== '') { ?> … <?php echo $set['price']; ?> … <?php } ?>
```

The stored value is a string in the formatted notation.

### `sizefactor`

Makes a file size readable (bytes → KB / MB / GB); `1024` for binary, `1000` for
decimal units:

```cms
(CMS:NUMBER filesize sizefactor=1024)      15.2 MB
(CMS:NUMBER filesize sizefactor=1000)      15.9 MB
```
For upload fields, this also works directly with `(CMS:MEDIA image subfield=size)`.

---

[← Overview](README.md)
