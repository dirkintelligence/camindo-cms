# 1 Basics of template programming

## What a template is

A ***template*** is a text document – usually HTML – that contains ***CMS commands***.
The commands query records, insert contents, include other templates, evaluate forms and
control the flow. PHP code may also appear in between.

```cms
(CMS:INCLUDE header)
<h1>(CMS:TEXT title)</h1>
<div>(CMS:TEXT text)</div>
<div>(CMS:MEDIA field=image_small attr[id]=image1)</div>
<div>created: (CMS:DATE created_at format="MM/dd/y")</div>
(CMS:INCLUDE footer)
```

When you save, camindo CMS translates the template into PHP. At runtime only this PHP is
executed – there is no template engine that evaluates the text again on every call.

## How a page is created

When a visitor opens an address, camindo CMS determines:

1. the ***instance*** – from the domain,
2. the ***language*** – from the language code in the address, otherwise from the
   browser's language setting, otherwise the primary language,
3. the ***record*** – from the slug or the number (`record_id`) in the address; without
   one, the start record of the instance,
4. the ***template*** – from the address, from the record, or the default template of its
   content area.

The contents of the requested record are available in the template. By default, all output
commands refer to this record – see `(CMS:TEXT title)` above. If the template runs a query
with `CMS:SELECT`, the contents of the records found are available in the same way in the
result template; see [Lists, SELECT and the result loop](#lists-select-and-the-result-loop) below.

### Addresses

```
[/{language}]/{slug or number}[,{template}]
```

| Address | Meaning |
|---|---|
| `/` | Start page of the instance |
| `/company-party-2026` | Record with this ***slug***, default template |
| `/events/company-party-2026` | Record with the slug `company-party-2026` in the area with the ***slug prefix*** `events` |
| `/events` | Overview of the area with the slug prefix `events` – its overview template, without a record |
| `/1758` | Record number 1758 |
| `/en/summer-party-2026` | English language version |
| `/company-party-2026,print` | Record with the template `print` – only if it is released as a URL override for the area |
| `/0,search` | Template `search` without a record – only if it is marked as “Standalone” |

Pure digit sequences are always record numbers; anything else is a slug. If the first part
of the address is the slug prefix of a content area, the rest is searched only in this
area; otherwise the whole path is searched in the areas without a prefix. The language code
is part of the address as soon as the instance has more than one language.
Generate addresses with [CMS:URL](url.md) – never by hand, then language, slug and domain
always match.

If the address consists only of the language code (`/en`, without a trailing slash),
camindo CMS redirects to the ***start page of the language*** (`/en/` or `/en_US/` –
depending on how it is set up). If the site has only one language and a record has exactly
this slug, the record is shown instead. Addresses for which there is nothing – unknown record,
unknown language, template not released – respond with the status ***404***.

## Syntax of CMS commands

```cmscode
(CMS:COMMAND value parameter=value parameter[key]="value" …)
```

- Command names and parameter names are not case-sensitive.
- Parameters are separated by spaces; a command may span several lines.
- The order in which a command processes its parameters is determined by the command
  itself, not by the order in which the parameters are written.
- The first value without `=` is the ***main parameter*** of the command:
  `(CMS:TEXT title)` is the same as `(CMS:TEXT field=title)`.
- Values that contain spaces are enclosed in quotation marks, single or double:
  `(CMS:TEXT field="title")` is the same as `(CMS:TEXT field='title')`.
- ***Subkeys*** in square brackets: `filter[area]=news`. Empty brackets append to a list:
  `sort[]=date sort[]=title`. If the same subkey is given several times, a list is created
  as well (in contrast to PHP array syntax, `[]` is optional).
- Instead of `=`, a ***comparison operator*** may stand between parameter and value –
  `==`, `!=`, `!`, `!!`, `<`, `>`, `<=`, `>=`, `<<`, `>>`:
  `filter[price] >= 100` is the same as `filter[price]=">=100"`. The value then applies
  unchanged; an operator at its beginning is no longer evaluated:
  `filter[code] < ">2"` compares with the text “>2”. With `=` it stays as before –
  `filter[code] = ">2"` means “greater than 2” (see [Filter syntax](#filter-syntax)).
- The line break after a CMS command is swallowed, in the same way as after a `<?php … ?>` block.
- Command parameters in double quotation marks may contain PHP variables: `attr[alt]="{$myvar}"`
  (parameters without quotation marks are handled internally like parameters in double quotation marks).

Errors – an unknown command, an unknown parameter, a missing bracket – do not prevent saving.
The detail view of the template shows them with the line number. They also appear on the start
page in the widget “Template messages” – in case templates are edited through the file system.

## Combining CMS commands and PHP code

***$set*** `<?php … ?>` blocks may appear freely in the template, but CMS commands inside
PHP blocks lead to errors! To use the contents of CMS commands in PHP blocks, use the
parameter `set` (for example `set = myvar`) and read the content in the PHP block from `$set`
(`$set['myvar']`).

***$cms*** The contents of the requested record are in `$cms`; so are the contents of records
found with `CMS:SELECT`.

> For ***output***, always use the CMS output commands (such as `CMS:TEXT`).
> These commands take care of correct formatting; a `<?php echo $cms[…] ?>` does none of this.
> Use `$cms` only where a command needs a value as a parameter, for example
> `filter[category]="{$cms['record_id']}"`.

### Common parameters of the output commands

| Parameter | Effect |
|---|---|
| `ifempty` | replacement text if the value is empty |
| `instead` | replacement text if the value is *not* empty |
| `prefix`, `suffix` | text before and after – only if the value is not empty |
| `set` | do not output the value, but store it in `$set[…]` |
| `id` | read from another record (number or slug), for example `(CMS:TEXT title id=imprint)` – only published records, as in the URL |
| `replace` | replace partial texts (`replace="old?new"`) – for TEXT, NUMBER, DATE, DATETIME, TIME |

```cms
(CMS:TEXT subtitle prefix="<h2>" suffix="</h2>")
(CMS:NUMBER price format="%.2f" suffix=" $" ifempty="on request")
```

### Values in attributes and JavaScript

camindo CMS removes script, event handlers (`onclick` …), `iframe`, `object`, `embed` and
`javascript:` links from all record contents – when saving and again when outputting.
HTML formatting remains, which is why `CMS:TEXT` outputs contents ***unescaped***.

If you insert a value into an ***HTML attribute***, escape it with `specialchars=true` –
otherwise a quotation mark in the content would end the attribute:

```cms
<img src="…" alt="(CMS:TEXT title specialchars=true)">
<a href="(CMS:URL)" title="(CMS:TEXT teaser specialchars=true striptags=true lbrconvert=false)">…</a>
```

The same applies to form input that you put back into the field after submitting:

```cms
<input name="data[nickname]" value="(CMS:TEXT useval="{$_POST['data']['nickname']}" specialchars=true)">
```

***Commands that escape themselves:*** Some commands already escape their output with
`htmlspecialchars`. For them, `specialchars` is omitted – an already escaped value would be
escaped a second time, and an `&` would appear as `&amp;`:

| Command | Escapes |
|---|---|
| [CMS:MEDIA](media.md) | all attributes of the `<img>` tag: `src`, `alt`, `width`, `height` and `attr[…]` – not the individual values via `subfield` |
| [CMS:USER_TOKEN](user-token.md) | the value of the hidden field |
| [CMS:USER_PASSWORD_RULES](user-password-rules.md) | the entire output (JSON for an attribute) |
| [CMS:XML](xml.md) | `version` and `encoding` |
| [CMS:JUMP_LIST](jump-list.md), [CMS:JUMP_PREV](jump-prev.md), [CMS:JUMP_NEXT](jump-next.md) | the URL in `{href}` |

What you pass to one of these commands must therefore be ***unescaped text*** –
without HTML tags and entities:

```cms
(CMS:TEXT title striptags=true ampconvert=false lbrconvert=false set=title)
(CMS:MEDIA image attr[alt]="{$set['title']}")
```

***Never escaped*** are `prefix`, `suffix`, `ifempty` and `instead` – they come from the
template and may contain HTML. Only [CMS:TEXT](text.md) and [CMS:SNIPPET](snippet.md) know
`specialchars`. Values of other commands, such as `CMS:MEDIA … subfield=filename`,
[CMS:RELATION](relation.md) or [CMS:RAW](raw.md), store them with `set` for an attribute
and output them escaped via `CMS:TEXT`:

```cms
(CMS:MEDIA document subfield=filename set=filename)
<a href="(CMS:MEDIA document subfield=path)" title="(CMS:TEXT useval="{$set['filename']}" specialchars=true lbrconvert=false)">…</a>
```

`CMS:URL` and `CMS:MEDIA … subfield=path` produce only URL-safe characters, and so does
`CMS:TEXT … urlencode=true` – they can stand in `href` and `src` without escaping.

If ***JavaScript*** needs a value, output it as a `data-` attribute and read it there – so
that no content ever appears in the script code itself:

```cms
<div id="product" data-title="(CMS:TEXT title specialchars=true)"></div>
<script>var title = document.getElementById('product').dataset.title;</script>
```

## Lists, SELECT and the result loop

Lists always consist of two templates: the calling template queries with
[CMS:SELECT](select.md) and names a ***list template***. The list template runs through the
hits between [CMS:RESULT_START](result-start.md) and [CMS:RESULT_END](result-end.md).

Calling template:

```cms
<h2>Latest news</h2>
(CMS:SELECT news_list
  filter[area]=news
  sort=date
  order=desc
  limit=10
  noresult="<p>No news.</p>"
)
```

List template `news_list`:

```cms
<ul>
(CMS:RESULT_START)
  <li><a href="(CMS:URL)">(CMS:TEXT title)</a> – (CMS:DATE date)</li>
(CMS:RESULT_END)
</ul>
```

Inside the loop, the current row is the running record. A list template may itself contain
`CMS:SELECT` – for example the tags of each article.

***Paging:*** `count=true` counts all hits; [CMS:JUMP_LIST](jump-list.md),
[CMS:JUMP_PREV](jump-prev.md) and [CMS:JUMP_NEXT](jump-next.md) turn this into the navigation.

***Publication:*** Templates find only **published** records – released, publication date
reached, expiry date not yet reached. The parameters `future` and `archive` relax this per
query, for example for an archive or a preview of upcoming events.

> The parameter `state` is intended exclusively for views inside the back end.

### Addressing areas

Content areas are addressed by their ***name*** or by their number (`area_id`):
`filter[area]=news`, `filter[area]=12` or `filter[area_id]=12`. Separate several areas with
commas. Because field names are uniform across the system, several areas can be filtered and
sorted together:

```cms
(CMS:SELECT timeline
  filter[area]=news,events,blog
  filter[date] >= 2026-01-01
  sort=date
  order=desc
)
```

`sort=area` sorts by area; `_default` stands in filters and sorting for the main field of each
area, whatever it is called there.

### Overview page of an area

The address consisting only of the slug prefix (`/events`) calls the ***overview template***
of the area – without a record. In its place stand the details of the area:

| Value | Contents |
|---|---|
| `area_id` | number of the area |
| `area` | name of the area |
| `area_label` | label of the area (snippets in the language of the call) |
| `area_type`, `area_icon` | type and icon of the area |
| `instance_id`, `language_id` | instance of the area and language of the call |
| `slug_prefix` | slug prefix in the language of the call |
| `record_id` | always `0` – there is no current record |
| `overview` | `true` |

In this way, an overview template lists the records of its area without having their name
written in it – and can be used for several areas:

```cms
<h1>(CMS:TEXT area_label specialchars=true)</h1>
(CMS:SELECT event_list
  filter[area]="{$cms['area_id']}"
  sort=date order=asc
)
```

### Linked records

With `relation`, `CMS:SELECT` retrieves the records that are linked with the current record
(or with the record named in `src_id`) via a link field.

```cms
(CMS:SELECT gallery_list
  relation=see_also
  sort="created_at desc"
)
```

With `sort="_custom asc"`, linked records appear in the order that the editorial team set
in the lookup field (using the arrow buttons):

```cms
(CMS:SELECT gallery_list
  relation=images
  sort="_custom asc"
)
```

To sort and filter by fields of linked records, use dot notation: `sort=author.last_name`.

If there is no current record and `src_id` is not given or invalid, the search with the
parameter `relation` finds nothing.

## The concept: every record has its own page

In camindo CMS, ***every record has its own page*** in the frontend – not only articles and
products, but also every category, every tag, every material. A website therefore needs
***no parameters*** such as `?cat=12` or `?tag=oak`:

1. ***Linking:*** Lists link every record with [CMS:URL](url.md) – the category just as the
   product.
2. ***Default template per area:*** Each content area gets a default template – a category
   page, a tag page, a product page. With a slug prefix, each kind of page gets its own address
   space (`/en/category/living`, `/en/tag/oak`).
3. ***Listing linked contents:*** On the page, the record is in `$cms`; the template queries
   its linked records directly through it.

Category page (default template of the area `categories`):

```cms
<h1>(CMS:TEXT title)</h1>
(CMS:TEXT text prefix="<p>" suffix="</p>")
(CMS:SELECT product_list
  relation=rel_products
  sort=position
  order=asc
)
```

List template `product_list` – the products link back to their own page:

```cms
<ul>
(CMS:RESULT_START)
  <li><a href="(CMS:URL)">(CMS:TEXT title)</a></li>
(CMS:RESULT_END)
</ul>
```

Navigation is just as simple – a list of all categories with links:

```cms
(CMS:RESULT_START)
<a href="(CMS:URL)">(CMS:TEXT title)</a>
(CMS:RESULT_END)
```

Which query returns the linked records depends on **from which page the link starts**:

| Link | Query on the page of the record |
|---|---|
| starts from the record (its own link field) | `(CMS:SELECT list relation=fieldname)` |
| relation field (`relation`) of another area points to it | `(CMS:SELECT list relation=rel_areaname)` – via the counterpart field |
| pointer field (`pointer`) of another area points to it | `(CMS:SELECT list filter[area]=areaname filter[fieldname]="{$cms['record_id']}")` |

***Relation fields*** (`relation`, `relation1`) are linked in both directions: when you create
them, camindo CMS creates in the target area a ***counterpart field*** named
`rel_{areaname}` (for example `rel_products`), which leads the link from the other side. The
category page therefore queries its products with `relation=rel_products`, and the product page
queries its categories with `relation=categories`.

***Pointer fields*** (`pointer`, `pointer1`) are directed – they can point to several areas.
The target record does not know its pointers; the counterpart is a filter on the pointer field
with the number of the current record:

```cms
(CMS:SELECT product_list
  filter[area]=products
  filter[category]="{$cms['record_id']}"
)
```

> **Note:** The number of the current record is in `$cms['record_id']` –
> there is no key `id`. An ***empty filter value cancels the filter***:
> `filter[category]="{$cms['id']}"` therefore does not find no products, but **all**
> products.

Searches and free filter combinations remain parameters (`?q=…`), because they are not
records. Everything that is a record, on the other hand, has its own address – and with it
its page title, description and frontend editing like any other page.

## Filter syntax

The values in `filter[…]` follow the same syntax as the search mask in the back end:

| Value | Meaning |
|---|---|
| `Berlin` | equal |
| `Berlin,Hamburg` | either of them (with `filter_mode[field]=AND`: both) |
| `Hen*`, `*man`, `*berg*` | wildcards |
| `!Berlin`, `!*man` | not equal |
| `==Star*` | exactly equal, `*` without wildcard effect |
| `>100`, `>=100`, `<100`, `<=100` | comparisons – also for dates |
| `>2 AND <4`, `Hen* OR Her*` | combine conditions within the same field |
| `2026`, `2026-06` | date: the whole year or the whole month |
| `now`, `today`, `now-2h`, `today+7d` | date relative to the call, in the time zone of the user or the language, like a typed date; `today` without an hour offset is the whole day. Offset in `s`, `m`, `h`, `d` |
| `utc_now`, `utc_today`, `utc_now-15m` | the same in UTC – for comparisons with points in time that apply regardless of the visitor (blocking periods, time window of a scheduler run) |
| `(empty)`, `!(empty)` | without its own value (empty or not translated) or with one |

The operator may also stand between key and value – handy when the value comes from a variable:

```cms
(CMS:SELECT embargo_mail
  filter[area] = press_releases
  filter[online_at] > {$set['last_run']}
  filter[online_at] <= {$set['this_run']}
  filter_mode[online_at] = AND
  future = true
)
```

is the same as `filter[online_at]=">{$set['last_run']}"` and
`filter[online_at]="<={$set['this_run']}"`. With such an operator, the value itself is free of
operators: if the content of the variable begins with `<`, `>` or `!`, it is compared as text.

A date value that cannot be evaluated (such as a Unix timestamp like
`$_SERVER['REQUEST_TIME']`) is ***skipped without a message*** – the list then shows all
records, as if the filter were not there. For analyses in your development environment,
`debug=true` helps: the generated SQL query is displayed.

***Text comparisons by language:*** Text and character fields (including `_default`) are
compared and sorted according to the rules of the language version in which you search –
derived from the language code, nothing needs to be set. Always valid: no distinction between
upper and lower case and accents (`cafe` finds “Café”), `ß` equals `ss`. In addition, the rules
of individual languages apply:

| Language | Rule | Example |
|---|---|---|
| German | `ä` = `ae`, `ö` = `oe`, `ü` = `ue` (phone-book rule) | `Mue*` finds “Müller”, `Mul*` does not |
| Swedish | `å`, `ä`, `ö` are letters of their own | `o` does not find `ö`; `ö` sorts after `z` |
| Danish, Norwegian | `æ`, `ø`, `å` are letters of their own, `aa` = `å` | `Aa*` finds “Ålesund” |
| Turkish | `i`/`İ` and `ı`/`I` are different | |

Bulgarian, Esperanto, Estonian, Galician, Icelandic, Croatian and Bosnian, Latvian, Lithuanian,
Polish, Romanian, Russian, Serbian (Latin), Slovak, Slovenian, Spanish, Czech, Hungarian and
Vietnamese have rules of their own as well. All other languages – English included – follow the
general rules.

This applies equally to `=`, wildcards and comparisons such as `>`, and to full-text fields
(`Mueller` finds “Müller”, `Muller` does not). In the back end, the language that is set for
the instance of the searched area applies; if the instances of one search follow different
rules, only the general rules apply.

Different filters are always combined with AND. Full-text fields understand `+must -mustnot`;
encrypted fields only the exact value.

In areas with a tree structure, `filter[parent_id]=42` returns all descendants of record 42;
together with `filter[depth]=1` only the direct children – the basis for navigations.

## Including and organizing templates

[CMS:INCLUDE](include.md) includes another template – for example the header and footer of
the page:

```cms
(CMS:INCLUDE layout.header)
<main>…</main>
(CMS:INCLUDE layout.footer)
```

Template names only have to be unique within their template area. If a name exists in several
areas, refer to it as `areaname.templatename`. A name that is not unique is reported by the page
as an error.

Typical division:

| Type | Task |
|---|---|
| ***Page templates*** | default templates of the content areas: output a record as a complete page |
| ***List templates*** | run through the hits of a `CMS:SELECT` |
| ***Building blocks*** | header, footer, navigation, teaser – included via `CMS:INCLUDE` |
| ***Standalone templates*** | pages without a record: search, sitemap, forms |
| ***Time-controlled templates*** | run as a task of the scheduler, without visitors (see below) |
| ***List templates (back end)*** | design the record list of the editorial team (see below) |

## Caching

Expensive building blocks – a navigation made of many queries, a product list – can be cached
by `CMS:INCLUDE` with ***`caching`***: `(CMS:INCLUDE layout.navigation caching=1h)` creates the
navigation at most once an hour per website and language. What else the output depends on
belongs in `cacheparams` – see [CMS:INCLUDE](include.md#caching).

## Multilingualism

A template automatically outputs the language version of the call – if a translation is
missing, the primary language. Links with `CMS:URL` keep the language; with the parameter `lang`,
you link explicitly to another language version. To build a language switcher, use
[CMS:SELECT_LANGVERSION](select-langversion.md).

Date and number formats follow the language version; so does the time zone.

Fixed labels (“Read more”, “Search”) are maintained by the editorial team as separate records
(for example in an area named “Other texts”) in all frontend languages. This area should have a
_single-language_ `slug` field and a _multilingual_ `text` field. Then CMS:TEXT works as a
snippet provider; see [CMS:TEXT with the id parameter](./text.md#id).

```cms
<a href="(CMS:URL)">(CMS:TEXT id=labels.readmore)</a>
```

## Frontend editing

[CMS:FED](fed.md) places an edit marker on a content that only users with frontend editing
switched on can see. A click opens the input form of the record in the back end.
[CMS:FED_CODE](fed-code.md) outputs HTML only in this case – for example a stylesheet for the
markers.

## Security headers: CSS, JavaScript, JSON and embedding

camindo CMS sends a few HTTP headers before each template, which the browser applies as
protection:

```
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Content-Security-Policy: frame-ancestors 'self'
Referrer-Policy: strict-origin-when-cross-origin
```

For ordinary HTML pages you do not need to do anything. Two cases do have an effect – and the
cause is not obvious at first glance:

### Templates that deliver CSS, JavaScript or JSON

A template is delivered as `text/html` unless it says otherwise. Because of `nosniff`, the
browser then does not accept the content as a stylesheet or script, even if it is correct. The
page looks unstyled, the script does not run – and the browser console reports something like
*“refused to execute script … MIME type ('text/html') is not executable”* or
*“… not a supported stylesheet MIME type”*.

Therefore set the appropriate type at the beginning of each such template – before any output,
without a blank line in front:

```cms
(CMS:HEADER "Content-Type: text/css; charset=utf-8")
(CMS:HEADER "Content-Type: text/javascript; charset=utf-8")
(CMS:HEADER "Content-Type: application/json; charset=utf-8")
```

### Embedding output in other websites

A page of the website can only be shown in an `<iframe>` ***within the same website***. This
protects sign-in, profile and shopping cart from a foreign page overlaying them invisibly and
slipping clicks to visitors (clickjacking). If a partner website embeds a page via `<iframe>`,
the frame therefore stays empty or shows an error page of the browser; the console reports
*“Refused to frame … because an ancestor violates … frame-ancestors”* or
*“… X-Frame-Options to 'sameorigin'”*.

If a template should be allowed to be embedded – a widget, an event calendar for partners –
release it ***only in this template***: remove `X-Frame-Options` by giving it no value, and name
the permitted websites in `frame-ancestors`:

```cms
(CMS:HEADER "X-Frame-Options:")
(CMS:HEADER "Content-Security-Policy: frame-ancestors 'self' https://www.partner.example https://*.partner.example")
```

Both lines belong together: a browser that still sees `X-Frame-Options` refuses the frame despite
`frame-ancestors`. Name the partners individually – `frame-ancestors *` allows every website to
embed the page – including the pages with forms. Other kinds of embedding are not affected:
images, downloads, data loaded by JavaScript (JSON) and links to your website work without
release.

More on the header lines: [CMS:HEADER](header.md).

## Templates in the file system

Each template is stored as a file at
`var/templates/{instance_id}/{area_name}/{template_name}.cms.php`. You can edit it there with a
code editor and manage it with Git. camindo CMS applies changes every minute (or immediately via
“Refresh” in the module Templates) and recompiles the template. Rename templates in the back end –
in the file system, a rename counts as deleting and creating anew, and the assignments are lost.
Error messages appear in the widget “Template messages”.

## Time-controlled templates

A template with the ***usage “Time-controlled (scheduler)”*** (tab “Access” of the template) does
not run as a page, but as a task of the ***scheduler*** (task type “Template”; see
[Handbook for administrators](../admins/10-modules.md#scheduler)). It can never be reached via an
address.

It runs like a page call without a record: in the website of its template area, in the language
that the task names – with its texts, snippets, date formats and time zone. There is no visitor,
hence no sign-in and no shopping cart. The task passes the ***time window*** since the last
successful run:

| Variable | Contents |
|---|---|
| `$set['last_run']` | start of the time window (exclusive), time zone of the language |
| `$set['this_run']` | end of the time window (inclusive), time zone of the language |
| `$set['last_run_utc']`, `$set['this_run_utc']` | the same in UTC |

On the first run, the time window is empty. If a run fails, the next one catches up on the whole
period – nothing is lost and nothing is processed twice. The time window fits directly into a
filter; two conditions for the same field are combined with AND via `filter_mode`:

```cms
(CMS:SELECT embargo_mail
  filter[area]=press_releases
  filter[online_at] > {$set['last_run']}
  filter[online_at] <= {$set['this_run']}
  filter_mode[online_at]=AND
  future=true
)
```

The result template then sends, for example with [CMS:EMAIL](email.md) (`submit=1`), one message
per hit; [CMS:URL](url.md) with `absolute=true` returns the complete address for links. If a command
reports an error, the run counts as failed. On request, the task saves what the template outputs
under `var/custom/` – this is how a sitemap or a feed is created as a file.

## Preview

The preview from the back end also shows unpublished records with their template. Lists in it
still show only published records, as do `id=…` and [CMS:RELATION](relation.md). In the preview,
`$this->isPreview()` returns `true`.

## List templates for the back end

A content area can have a ***list template*** that is used only in the back end: for an additional
column of the record list and for the detailed preview in lookup fields. In the template, the
record of the row is available as `$cms`; the output commands `CMS:TEXT`, `CMS:MEDIA` … work as in
the frontend:

```cms
(CMS:MEDIA image_small width=60)
<small>(CMS:NUMBER price format="%.2f" suffix=" $")</small>
```

`CMS:RELATION` is helpful here to list the main field of linked records.
