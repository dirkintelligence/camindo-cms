# CMS:JUMP_PREV

Creates a link to the previous page.

```cmscode
(CMS:JUMP_PREV tpl_link [parameter=value …])
```

***Method:*** `Pagination::cmsJumpPrev()`  
***Without key:*** the first value without `=` is the `tpl_link`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `skip` | `number >= 0` | `$_REQUEST[skip]` |  | Offset of the current page (number of skipped results). Without a value, the request parameter skip applies. |
| `tpl_link` ¹ | `HTML template` | `<a href="{href}"><strong>❮❮</strong></a>` |  | Template for the link, as long as there is a previous page. {href} is replaced by the target URL. The URL is HTML-escaped (`&amp;` instead of `&`), `'` and `\` are percent-encoded – {href} can stand directly in an attribute. |
| `tpl_inactive` | `HTML template` | `<span>❮❮</span>` |  | Template for the link that cannot be selected, if there is no previous page. {href} is replaced here as well. |
| `replace` | `text?text` | – |  | Simple text replacement in the finished HTML. The separator is the question mark: "search?replace". |
| `prefix` | `text` | – |  | Text before the link, only if a link is output. |
| `suffix` | `text` | – |  | Text after the link, only if a link is output. |
| `ifempty` | `text` | – |  | Output if the CMS:SELECT has no limit (then there are no pages). If everything fits on one page, `tpl_inactive` appears. |
| `set` | `array index of $set` | – |  | The link is not output, but stored in the variable $set. |

¹ can be given without a key as the first value.

## Usage

Link to the previous page – the counterpart to `CMS:JUMP_NEXT`, which, like it, stands in the
`resulttemplate` of a `CMS:SELECT` with `limit` (the `limit` is the page size):

```cms
<nav>(CMS:JUMP_PREV "<a href='{href}'>‹ previous</a>" tpl_inactive="") (CMS:JUMP_NEXT "<a href='{href}'>next ›</a>" tpl_inactive="")</nav>
```

On the first page, `tpl_inactive` appears (here empty), otherwise the link – also when everything
fits on one page.

## Parameters in detail

### `skip`

```cms
(CMS:JUMP_PREV skip="{$offset}")
```

### `tpl_link`

```cms
(CMS:JUMP_PREV "<a class=prev href='{href}'>‹ previous</a>")
```

### `tpl_inactive`

```cms
(CMS:JUMP_PREV tpl_inactive="<span class=disabled>‹ previous</span>")
```

### `replace`

```cms
(CMS:JUMP_PREV replace="❮❮?←")
```

### `prefix`

```cms
(CMS:JUMP_PREV prefix="<li class=prev>" suffix="</li>")
```

### `suffix`

```cms
(CMS:JUMP_PREV suffix="</li>" prefix="<li>")
```

### `ifempty`

```cms
(CMS:JUMP_PREV ifempty="")
```

Applies only if the `CMS:SELECT` has no `limit`; the default is empty.

### `set`

```cms
(CMS:JUMP_PREV set=prev)
```

---

[← Overview](README.md)
