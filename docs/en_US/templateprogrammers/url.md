# CMS:URL

Creates the URL of a record.

```cmscode
(CMS:URL id [parameter=value …])
```

***Method:*** `Cms::cmsUrl()`  
***Without key:*** the first value without `=` is the `id`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `id` ¹ | `record ID or slug` | `(current record)` |  | Record to link to. Pure digit sequence = record_id, anything else = slug – the same rule as in the URL. |
| `area` | `area name or ID` | – |  | Link to the overview of the area: the address made of its slug prefix (`/en/events`), which its overview template shows. |
| `template` | `template name` | – |  | Template with which the record is to be shown (second part of the URL after the comma): name or area.name. Without specification, the template that the area assigns. |
| `lang` | `language code` | `(current language)` |  | Language version to link to (by default that of the current call). If the instance runs more than one language, the code always appears in the URL; with only one language, never. A multilingual slug is inserted in the target language. A code that the website does not run is ignored. |
| `protocol` | `transfer protocol` | `(protocol of the instance)` |  | http or https, only with absolute or host. Without specification, the protocol of the instance, otherwise that of the call. |
| `host` | `host name` | – |  | e.g. example.com to change the domain through which the website is called. |
| `absolute` | `true / false` | `false` |  | true: The path is generated including protocol and domain. false: The path is server-relative and begins with a slash. |
| `set` | `array index of $set` | – |  | The URL is not output, but stored in $set. |

¹ can be given without a key as the first value.

## Usage

Creates the front-end URL of a record – with the slug if it has one, otherwise
with the record_id; for multilingual websites always with a language segment:

```cms
<a href="(CMS:URL)">(CMS:TEXT title)</a>
```
Output: `<a href="/en/hello-world">Hello world</a>`

Link to another view of the same record (`template`), to another record (`id`)
or into another language (`lang`):

```cms
(CMS:URL template=print)                /en/hello-world,print
(CMS:URL 1977)                          /en/1977   (record without slug)
(CMS:URL imprint lang=de)               /de/impressum   (slug of the target language)
(CMS:URL colors/blue)                   /en/colors/blue   (area with slug prefix)
```

If the content area of the record has a ***slug prefix*** (setting of the
area, e.g. `colors`), it precedes the slug: `/en/colors/blue`. The prefix applies
per language – with `lang`, therefore, the prefix of the target language. An `id` with a slug means
the path as it appears in the address (`id=colors/blue`); `id=blue` finds only a
record of an area ***without*** a prefix.

Language switcher – in a `CMS:SELECT_LANGVERSION` list template. The rows there
are languages, not records; `CMS:URL` without `id` therefore points to the
record of the page:

```cms
<a href="(CMS:URL lang="{$cms['code']}")">(CMS:TEXT endonym)</a>
```

Absolute URL for feeds, emails or `og:url`:

```cms
<link rel="canonical" href="(CMS:URL absolute=true)">
```

## Parameters in detail

### `id`

Record to link to; without specification, the current one. May be given without a key:

```cms
(CMS:URL 1977)
(CMS:URL id=imprint)
(CMS:URL "{$cms['parent_id']}")       link to the parent node
```

### `area`

Links to the ***overview*** of an area: the address that consists only of its
slug prefix, in the language of the call or from `lang`. It shows the overview template of the
area (setting of the area, “Template assignment”):

```cms
<a href="(CMS:URL area=events)">All events</a>        /en/events
(CMS:URL area=events lang=de)                         /de/termine
```

If the area has no slug prefix, the output remains empty. In the overview template
itself there is no current record; `CMS:URL` without `id` therefore points there to
the same overview – a language switcher with `CMS:SELECT_LANGVERSION` therefore works
as on every record page.

### `template`

Second part of the URL (after the comma): the template with which the record is to be
shown – name or `area.name`:

```cms
(CMS:URL template=print)
(CMS:URL template=articletemplates.print)
```
Output: `/en/hello-world,print`

### `lang`

Language version to which the link points (code from language management); without specification,
the language of the current call:

```cms
(CMS:URL lang=de)
```
If the website runs only one language, a language segment never appears in the URL. A code
that the website does not run is ignored – the URL keeps the language of the call.

If the field `slug` of the area is **multilingual**, each language version has its own
slug – and the target URL must carry the slug of the target language, because it is resolved in
the language of the URL. `CMS:URL` therefore inserts it itself with `lang`:

```cms
Page /en/dining-table-nord-180

(CMS:URL lang=de)        /de/esstisch-nord-180
(CMS:URL lang=sv)        /sv/matbord-nord-180
```

If the slug is not translated into the target language, the slug of the main language appears there.
If the instance of the record does not run the target language, its main language applies. If
no slug is found, the record_id appears in the URL (with `canonical = 1`, the page
redirects from there to the slug). This costs one database query per record and language,
which is remembered for the call; without `lang`, or with a single-language slug field,
there is no query.

### `protocol`

Only together with `absolute` or `host`:

```cms
(CMS:URL absolute=true protocol=https)
```
Output: `https://www.example.com/en/hello-world`

### `host`

Another domain; implies `absolute=true`:

```cms
(CMS:URL host=shop.example.com)
```
Output: `https://shop.example.com/en/hello-world` (protocol of the instance)

### `absolute`

Complete URL with protocol and main domain of the instance:

```cms
<link rel="canonical" href="(CMS:URL absolute=true)">
```
Output: `<link rel="canonical" href="https://www.example.com/en/hello-world">`

### `set`

`set` outputs nothing, but stores the result under `$set['url']` for the
surrounding PHP or a later command:

```cms
(CMS:URL  set=url)
<?php if ($set['url'] !== '') { ?> … <?php echo $set['url']; ?> … <?php } ?>
```

---

[← Overview](README.md)
