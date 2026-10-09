# CMS:JUMP_NEXT

Creates a link to the next page.

```cmscode
(CMS:JUMP_NEXT tpl_link [parameter=value …])
```

***Method:*** `Pagination::cmsJumpNext()`  
***Without key:*** the first value without `=` is the `tpl_link`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `skip` | `number >= 0` | `$_REQUEST[skip]` |  | Offset of the current page (number of skipped results). Without a value, the request parameter skip applies. |
| `tpl_link` ¹ | `HTML template` | `<a href="{href}"><strong>❯❯</strong></a>` |  | Template for the link, as long as there is a next page. {href} is replaced by the target URL. The URL is HTML-escaped (`&amp;` instead of `&`), `'` and `\` are percent-encoded – {href} can stand directly in an attribute. |
| `tpl_inactive` | `HTML template` | `<span>❯❯</span>` |  | Template for the link that cannot be selected, if there is no next page. {href} is replaced here as well. |
| `replace` | `text?text` | – |  | Simple text replacement in the finished HTML. The separator is the question mark: "search?replace". |
| `prefix` | `text` | – |  | Text before the link, only if a link is output. |
| `suffix` | `text` | – |  | Text after the link, only if a link is output. |
| `ifempty` | `text` | – |  | Output if the CMS:SELECT has no limit (then there are no pages). If everything fits on one page, `tpl_inactive` appears. |
| `set` | `array index of $set` | – |  | The link is not output, but stored in the variable $set. |

¹ can be given without a key as the first value.

## Usage

Link to the next page of a pageable list (`CMS:SELECT` with `count=true` and `limit`). Like
[CMS:JUMP_LIST](jump-list.md), the command stands in the `resulttemplate` of the query, here with
`limit=20`:

```cms
(CMS:JUMP_PREV) Page <?php echo intdiv((int)($_REQUEST['skip'] ?? 0), 20) + 1; ?> (CMS:JUMP_NEXT)
```
Output: `❮❮ Page 3 ❯❯`

If there is no next page – also when everything fits on one page –, `tpl_inactive` appears (default:
the symbol without a link). The page size is the `limit` of the `CMS:SELECT`; without `limit`, only
`ifempty` appears.

## Parameters in detail

### `skip`

```cms
(CMS:JUMP_NEXT skip="{$offset}")
```

### `tpl_link`

Template with link; `{href}` is replaced. May stand without a key:

```cms
(CMS:JUMP_NEXT "<a class=next href='{href}'>next ›</a>")
```

### `tpl_inactive`

Template without a next page (`{href}` is replaced here too – with the address the next page would
have):

```cms
(CMS:JUMP_NEXT tpl_link="<a href='{href}'>next ›</a>" tpl_inactive="<span class=disabled>next ›</span>")
```

### `replace`

```cms
(CMS:JUMP_NEXT replace="❯❯?→")
```

### `prefix`

```cms
(CMS:JUMP_NEXT prefix="<li class=next>" suffix="</li>")
```

### `suffix`

```cms
(CMS:JUMP_NEXT suffix="</li>" prefix="<li>")
```

### `ifempty`

```cms
(CMS:JUMP_NEXT ifempty="")
```
Applies only if the `CMS:SELECT` has no `limit`; the default is empty.

### `set`

```cms
(CMS:JUMP_NEXT set=next)
```

---

[← Overview](README.md)
