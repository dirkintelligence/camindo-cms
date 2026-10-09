# CMS:SNIPPET

Outputs a snippet (text module) in the language of the call.

```cmscode
(CMS:SNIPPET key [parameter=value …])
```

***Method:*** `Cms::cmsSnippet()`  
***Without key:*** the first value without `=` is the `key`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `key` ¹ | `snippet key` | – | yes | Key of the snippet, e.g. `custom.read_more` (snippet set of the instance) or a core snippet without prefix. |
| `param[…]` | `text` | – |  | Values for placeholders: `param[name]=value` replaces `{name}` in the snippet and fills ICU parameters (`plural`, `select`). |
| `specialchars` | `true / false` | `false` |  | true: Special characters are converted into their HTML equivalent (for attributes). |
| `ifempty` | `text` | – |  | Text that is output if the snippet does not exist (without `ifempty`, the key appears). |
| `prefix` | `text` | – |  | Text that is placed before the snippet, if it is not empty. |
| `suffix` | `text` | – |  | Text that is appended if the snippet is not empty. |
| `set` | `array index of $set` | – |  | The text is not output, but stored in the variable `$set`. |

¹ can be given without a key as the first value.  
`[…]` – the parameter accepts subkeys, for example `name[key]=value`.

## Usage

Fixed labels of a website – “Read more”, “Add to cart”, headings
of lists – are maintained by the editorial team as ***snippets*** of the instance in all languages
(module Snippets, see [Manual for administrators](../admins/10-modules.md#snippets)).
The template outputs them with `CMS:SNIPPET`, always in the language of the call; if a
translation is missing, the English text (en_US) appears:

```cms
<a class="more" href="(CMS:URL)">(CMS:SNIPPET custom.read_more)</a>
```

`custom.` refers to the snippet set of the instance. Without a prefix, the snippets of the core
are meant – on the front end only the general ones, not those of the back-end interface.

In an attribute, mask the text with `specialchars=true`:

```cms
<button aria-label="(CMS:SNIPPET custom.menu specialchars=true)">…</button>
```

***Placeholders.*** `param[name]` replaces `{name}` in the snippet. This leaves the word order
to the translation – for example, the currency sign before or after the amount:

```cms
Snippet custom.price:   en "€{amount}"   de "{amount} €"
(CMS:NUMBER price mul=0.01 format="%.2f" set=amount)
(CMS:SNIPPET custom.price param[amount]="{$set['amount']}")
```

If a snippet begins with an ICU message (`{count, plural, one {# item} other {# items}}`),
`param` fills in its values:

```cms
(CMS:SNIPPET custom.hits param[count]="{$set['count']}")
```

***Key from the record.*** The key may contain PHP variables – for example, to
translate a selection value:

```cms
(CMS:SNIPPET "custom.status_{$cms['status']}" ifempty="–")
```

If the snippet does not exist, the key itself appears on the page – so a missing
text is noticed during development. `ifempty` outputs a replacement instead.

Inside a parameter of another command, `CMS:SNIPPET` cannot be nested; there,
`{$this->cms->snippet('custom.read_more')}` returns the text:

```cms
(CMS:TEXT teaser suffix=" {$this->cms->snippet('custom.read_more')}")
```

---

[← Overview](README.md)
