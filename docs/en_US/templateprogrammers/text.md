# CMS:TEXT

Outputs a text with formatting applied.

```cmscode
(CMS:TEXT field [parameter=value …])
```

***Method:*** `Cms::cmsText()`  
***Without key:*** the first value without `=` is the `field`  
***At least one of:*** `field`, `useval`, `id`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `id` | `record ID or slug` | – | ² | Record to read from. Pure digit sequence = record_id, anything else = slug – the same rule as in the URL. Without 'field', the field "text" is read. |
| `field` ¹ | `field` | – | ² | Name of a record field whose content is used as the source. |
| `useval` | `text` | – | ² | Value in text form that is used as the source. |
| `ifempty` | `text` | – |  | Text that becomes the source if the original source is empty (further parameters, except 'set', are then ignored). |
| `instead` | `text` | – |  | Text that becomes the source if the original source is not empty. |
| `entitydecode` | `true / false` | `true` |  | true: Entities in the format &...; are converted back to Unicode characters. |
| `trim` | `true / false` | `false` |  | true: Spaces at the beginning and end of the text are removed. |
| `striptags` | `true / false` | `false` |  | true: HTML and script tags are removed. |
| `if[…]` | `text?text` | – |  | If the source corresponds to the text before the question mark, the text after the question mark becomes the new source. |
| `else` | `text` | – |  | If none of the previous IF queries applies, this value becomes the source. |
| `replace[…]` | `text?text` | – |  | If the text before the question mark occurs in the source, it is replaced by the text after the question mark. |
| `limit` | `number >=0` | – |  | Shortens the text to the given number of characters and appends "…"; with an appended s (e.g. 200s) the text is shortened at the last word end. |
| `lbrconvert` | `true / false` | `true` |  | true: Line breaks are converted into HTML line breaks (&lt;br&gt; tags). |
| `specialchars` | `true / false` | `false` |  | true: Special characters are converted into their HTML equivalent. |
| `urlconvert` | `true / false` | `false` |  | true: Internet addresses are automatically converted into clickable links. |
| `emailconvert` | `true / false / encrypt` | `false` |  | true: Email addresses are linked. encrypt: The links are lightly encrypted by means of JavaScript. |
| `emaillimit` | `>= 0` | – |  | Automatically shortens linked email addresses to x visible characters. |
| `emailtext` | `text` | – |  | Linked text that is shown instead of an email address (only if "emailconvert" is enabled). |
| `target` | `window / frame` | `_self` |  | Sets the link target for automatically generated links (urlconvert, emailconvert). |
| `markdown` | `true / false` | `false` |  | true: The text is Markdown and is converted into HTML (HTML in the text is escaped); the other conversions do not apply. |
| `urlencode` | `true / false` | `false` |  | true: The text is encoded according to RFC 3986 (rawurlencode) – for URL components, also in attributes without further escaping. |
| `urllimit` | `number of characters` | `80` |  | Maximum length of the visible text for automatically generated links (only suitable for external links!). |
| `ampconvert` | `true / false` | `true` |  | true: A standalone & is automatically converted into the HTML entity &amp;. |
| `prefix` | `text` | – |  | Text that is placed before the source, if the source is not empty. |
| `suffix` | `text` | – |  | Text that is appended to the source, if the source is not empty. |
| `iso` | `true / false` | `false` |  | true: The output is converted into the character set ISO-8859-1. |
| `charset` | `text` | `utf-8` |  | Character set into which the output should be converted. |
| `set` | `array index of $set` | – |  | The formatted value is not output, but stored in the variable $set. |

¹ can be given without a key as the first value.  
² at least one of the parameters marked with ² must be given.

`[…]` – the parameter accepts subkeys, for example `name[key]=value`.

## Usage

The standard command for field contents. It reads a field of the current record, converts line
breaks into `<br>`, escapes standalone `&` and outputs the result:

```cms
<h1>(CMS:TEXT title)</h1>
<p>(CMS:TEXT text)</p>
```

Output a field only if it is filled – `prefix`/`suffix` appear only for a non-empty value:

```cms
(CMS:TEXT teaser prefix="<p class=teaser>" suffix="</p>")
```

Cache a value for PHP instead of outputting it:

```cms
(CMS:TEXT title set=title)
<title><?php echo $set['title']; ?> – Example Ltd.</title>
```

Translate a status value into text:

```cms
(CMS:TEXT status_id if[0]="online" if[1]="blocked" if[2]="unconfirmed" else="unknown")
```

Order of processing: source → `if`/`else` → `replace` → `trim` → `striptags`
→ `limit` → (`markdown` or `urlencode` end here) → `entitydecode` → `specialchars` or
`ampconvert` → `urlconvert` / `emailconvert` → `lbrconvert` → `iso`/`charset` →
`ifempty` / `instead` / `prefix` / `suffix` → output or `set`.

## Parameters in detail

### `id`

`id` reads from another record – record_id or slug, as in the URL. Only with `CMS:TEXT` may `id`
stand alone: then the field `text` is read. As in the URL, the record must be ***published***
(released, publication date reached; an expired one remains readable), otherwise the value is
empty – also in the preview of the back end.

```cms
(CMS:TEXT title id=1977)          Title of record 1977
(CMS:TEXT id=imprint)             Field "text" of the record with the slug "imprint"
```

### `field`

`field` is the main parameter and may stand without a key – both lines are the same:

```cms
(CMS:TEXT field=title)
(CMS:TEXT title)
```

### `useval`

`useval` takes a value directly from the template instead of a field – usually a PHP variable or
a value previously stored with `set`:

```cms
(CMS:TEXT useval="{$_GET['q']}" specialchars=true)
(CMS:TEXT useval="{$set['value']}")
```

Always output values from the request (`$_GET`, `$_POST`) with `specialchars=true` – otherwise
a manipulated link can place arbitrary HTML and script into the page.

This also allows the text filters (`striptags`, `specialchars`, `limit` …) to be applied to
foreign values.

### `ifempty`

`ifempty` replaces the **entire** output if the source is empty; `prefix` and `suffix` are then
omitted:

```cms
(CMS:TEXT teaser prefix="<p>" suffix="</p>" ifempty="No teaser available.")
```

Note: if the source is empty, all conversions are skipped as well – the `ifempty` text is output
unchanged.

### `instead`

`instead` replaces the value as soon as the source is **not** empty – useful when only the presence
counts:

```cms
(CMS:TEXT image instead="✔ image available" ifempty="-")
```

### `entitydecode`

By default, entities (`&amp;`, `&auml;`, `&#8211;`) are converted back into characters. For content
that should keep the entities (for example for XML feeds):

```cms
(CMS:TEXT text entitydecode=false)
```

### `trim`

```cms
(CMS:TEXT title trim=true prefix="[" suffix="]")
```
Output: `[Hello world]` instead of `[ Hello world ]`

### `striptags`

Removes HTML and script tags, for example for teasers from an HTML field or for meta tags. Each
tag is replaced by a space (from `<p>a</p><p>b</p>` comes `a b`); the text between the tags remains:

```cms
<meta name="description" content="(CMS:TEXT text striptags=true limit=160 lbrconvert=false)">
```

### `if`

Value table: if the value matches a key, the entry becomes the new source. Two notations – subkey
or short form with a question mark:

```cms
(CMS:TEXT status_id if[0]="online" if[1]="blocked")
(CMS:TEXT status_id if="0?online" if[]="1?blocked")
```

Values that do not match remain unchanged – unless `else` is given.

If the replacement text of a ***numeric*** key itself contains a question mark, use the short form:
`if[]="1?Really?"`. With `if[1]="Really?"` it would not be clear whether `1` is the search value or
whether the short form `Really` → empty was meant (in PHP both notations give the key `1`). For text
keys (`if[active]="Yes?"`) this does not apply.

### `else`

```cms
(CMS:TEXT status_id if[0]="online" else="not online")
```
Output for `status_id` = 2: `not online`

### `replace`

Replace partial texts in the source – short form with `?` or one entry per search text:

```cms
(CMS:TEXT text replace="\n?<br><br>" lbrconvert=false)
(CMS:TEXT title replace[Ltd.]="Limited" replace[&]="and")
```

As with `if`, write replacement texts with a question mark for a numeric search text in the short
form (`replace[]="0?zero?"`).

### `limit`

Shortens a text to a number of characters; an appended `s` shortens at the last word end (“soft”).
The snippet `ellipsis` (`…`) is appended:

```cms
<p>(CMS:TEXT text striptags=true limit=200s)</p>
<td>(CMS:TEXT title limit=30)</td>
```

### `lbrconvert`

By default, line breaks become `<br>`. Switch this off for content in `<pre>`, in attributes or in
JavaScript:

```cms
<pre>(CMS:TEXT code lbrconvert=false)</pre>
```

### `specialchars`

Escapes all special characters (`<` → `&lt;`, `"` → `&quot;` …) – for attributes and for content
that must not contain HTML:

```cms
<input value="(CMS:TEXT title specialchars=true lbrconvert=false)">
```

### `urlconvert`

Makes addresses (`http://…`, `https://…`, `www.…`) in the text clickable:

```cms
(CMS:TEXT text urlconvert=true target=_blank urllimit=24)
```
Output: `<a href="https://example.com/a/very/long/address" target="_blank">https://example.com/a/very…</a>`

### `emailconvert`

Links email addresses; `encrypt` assembles the link by means of JavaScript, so that the address
does not appear in the source code. The `<script>` for this carries the nonce of the page and
therefore also runs under an enforced script policy ([CMS:CSP](csp.md)):

```cms
(CMS:TEXT contact emailconvert=true)
(CMS:TEXT contact emailconvert=encrypt emailtext="Write an email")
```

### `emaillimit`

Shortens the visible part of a linked address:

```cms
(CMS:TEXT contact emailconvert=true emaillimit=12)
```
Output: `<a href="mailto:editorial@example.com">editorial@ex…</a>`

### `emailtext`

Fixed link text instead of the address (only with `emailconvert`):

```cms
(CMS:TEXT contact emailconvert=true emailtext="Get in touch")
```

### `target`

Link target for the links generated by `urlconvert`:

```cms
(CMS:TEXT text urlconvert=true target=_blank)
```

### `markdown`

Outputs a field that contains ***Markdown*** – for example one with the extension Markdown
(visual) – and converts it into HTML:

```cms
<div class="text">(CMS:TEXT text markdown=true)</div>
```

Conversion is done with Parsedown; HTML in the text is escaped, and links to `javascript:` and
similar targets are neutralized. In addition to the usual Markdown, the parameter understands the
formats of the extension Markdown (visual):

| Markdown | Result |
|---|---|
| `++Text++` | underlined (`<u>`) |
| `::: center` … `:::` | the enclosed paragraphs centered (`left`, `center`, `right`; as `<div style="text-align: …">`) |
| `<br>` | line break, also in table cells |
| `&shy;`, `&nbsp;` … | remain special characters |

`markdown` ends the processing: `entitydecode`, `specialchars`, `lbrconvert`, `urlconvert` and so on
do not apply. `prefix`, `suffix`, `ifempty` and `set` work as usual.

### `urlencode`

Encodes the value as a URL component. All further conversions do not apply then:

```cms
<a href="/search?q=(CMS:TEXT title urlencode=true)">similar articles</a>
```
Output: `/search?q=Hello%20world`

### `urllimit`

Visible length of automatically linked addresses (default 80 characters, 0 = not shortened):

```cms
(CMS:TEXT text urlconvert=true urllimit=30)
```

### `ampconvert`

By default a standalone `&` becomes `&amp;`; existing entities remain. Switch off if the content is
already fully escaped:

```cms
(CMS:TEXT html_snippet ampconvert=false)
```

### `prefix`

`prefix` / `suffix` surround the value and appear only if it is not empty – so no empty
`<p class=teaser></p>` is created:

```cms
(CMS:TEXT teaser prefix="<p class=teaser>" suffix="</p>")
```

### `suffix`

```cms
(CMS:TEXT author_name suffix=" (author)")
```

See `prefix`.

### `iso`

Re-encodes the output as ISO-8859-1 (short form for `charset=ISO-8859-1`), for example for older
interfaces:

```cms
(CMS:TEXT title iso=true)
```

### `charset`

```cms
(CMS:TEXT title charset=windows-1252)
```

### `set`

`set` outputs nothing, but stores the result under `$set['title']` for the surrounding PHP or a later
command:

```cms
(CMS:TEXT title set=title)
<?php if ($set['title'] !== '') { ?> … <?php echo $set['title']; ?> … <?php } ?>
```

---

[← Overview](README.md)
