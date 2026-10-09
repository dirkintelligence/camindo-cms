# CMS:JUMP_LIST

Creates a page navigation (pagination), for example 1 … 5 | 6 | 7 | 8 | 9 … 15.

```cmscode
(CMS:JUMP_LIST separator [parameter=value …])
```

***Method:*** `Pagination::cmsJumpList()`  
***Without key:*** the first value without `=` is the `separator`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `skip` | `number >= 0` | `$_REQUEST[skip]` |  | Offset of the current page (number of skipped results). Without a value, the request parameter skip applies. |
| `maximum` | `number >= 1` | `7` |  | Number of page links shown at the same time (moving along), not counting the links to the first and last page. |
| `boundaries` | `true / false` | `true` |  | true: Links to the first and the last page are always shown as soon as they lie outside the moving range. |
| `separator` ¹ | `separator` | (space) |  | Text between two page links. It is not inserted before and after the omission ('ellipsis'). |
| `tpl_link` | `HTML template` | `<a href="{href}">{page}</a>` |  | Template for a page link. {page} is replaced by the page number, {href} by the page URL. The URL is HTML-escaped (`&amp;` instead of `&`), `'` and `\` are percent-encoded – {href} can stand directly in an attribute. |
| `tpl_active` | `HTML template` | `<strong>{page}</strong>` |  | Template for the current page. {page} is replaced by the page number, {href} by the page URL. |
| `ellipsis` | `code` | `…` |  | Placeholder for omitted pages – appears between a boundary link and the moving range. |
| `replace` | `text?text` | – |  | Simple text replacement in the finished HTML. The separator is the question mark: "search?replace". |
| `prefix` | `text` | – |  | Text before the navigation, only if a navigation is output. |
| `suffix` | `text` | – |  | Text after the navigation, only if a navigation is output. |
| `ifempty` | `text` | – |  | Output if the result fits on a single page, or if the CMS:SELECT has no limit and therefore no navigation is needed. |
| `set` | `array index of $set` | – |  | The navigation is not output, but stored in the variable $set. |

¹ can be given without a key as the first value.

## Usage

Page navigation for a `CMS:SELECT` with `count=true`. The page size is the `limit` of the query;
***without*** `limit` there are no pages and the command outputs only `ifempty`. The current offset
comes from `$_REQUEST['skip']`.

The command stands in the ***`resulttemplate`*** of the `CMS:SELECT` (before or after the result
loop): only there the hit count and `limit` of this query apply – after the `CMS:SELECT` in the
calling template, the outer result is active again.

```cms
(CMS:SELECT list filter[area]=articles limit=20 skip="{$_REQUEST['skip']}" count=true)
```

Template `list`:

```cms
(CMS:RESULT_START)
  <h2>(CMS:TEXT title)</h2>
(CMS:RESULT_END)
<nav class="pager">
(CMS:JUMP_PREV) (CMS:JUMP_LIST separator=" | ") (CMS:JUMP_NEXT)
</nav>
```
Output: `❮❮ 1 … 5 | 6 | 7 | 8 | 9 … 15 ❯❯`

The links keep all other parameters of the current URL (search term, filters) and only set `skip`
anew. If the result fits on one page, nothing is output (or `ifempty`).

Bootstrap-style bar:

```cms
(CMS:JUMP_LIST
  prefix="<ul class=pagination>" suffix="</ul>"
  tpl_link="<li><a href='{href}'>{page}</a></li>"
  tpl_active="<li class=active><span>{page}</span></li>"
  ellipsis="<li class=disabled><span>…</span></li>"
  separator=""
)
```

## Parameters in detail

### `skip`

Offset of the current page, if it does not come from `$_REQUEST['skip']`:

```cms
(CMS:JUMP_LIST skip="{$offset}")
```

### `maximum`

Number of moving page links (not counting the first and last page):

```cms
(CMS:JUMP_LIST maximum=5)
```
Output on page 7 of 15: `1 … 5 6 7 8 9 … 15`

### `boundaries`

Suppress links to the first and last page:

```cms
(CMS:JUMP_LIST boundaries=false)
```
Output: `5 6 7 8 9` instead of `1 … 5 6 7 8 9 … 15`

### `separator`

Text between two page links; may stand without a key:

```cms
(CMS:JUMP_LIST " | ")
(CMS:JUMP_LIST separator=" · ")
```

### `tpl_link`

Template for each page link; `{href}` and `{page}` are replaced:

```cms
(CMS:JUMP_LIST tpl_link="<a class=page href='{href}'>{page}</a>")
```

### `tpl_active`

Template for the current page:

```cms
(CMS:JUMP_LIST tpl_active="<span class='page current'>{page}</span>")
```

### `ellipsis`

Placeholder for omitted pages (between the first/last page and the moving range):

```cms
(CMS:JUMP_LIST ellipsis=" ··· ")
```

### `replace`

Simple replacement in the finished HTML, separator `?` (only one pair):

```cms
(CMS:JUMP_LIST replace="<strong>?<strong class=current>")
```

### `prefix`

Appears only if a navigation is output:

```cms
(CMS:JUMP_LIST prefix="<nav class=pager>" suffix="</nav>")
```

### `suffix`

```cms
(CMS:JUMP_LIST suffix="</nav>" prefix="<nav>")
```

### `ifempty`

Output if everything fits on one page (or the `CMS:SELECT` has no `limit`):

```cms
(CMS:JUMP_LIST ifempty="<span class=muted>all entries</span>")
```

### `set`

```cms
(CMS:JUMP_LIST set=pager)
<?php if ($set['pager'] !== '') { ?><nav><?php echo $set['pager']; ?></nav><?php } ?>
```

---

[← Overview](README.md)
