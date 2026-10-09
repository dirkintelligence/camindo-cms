# CMS:XML

Creates an XML declaration.

```cmscode
(CMS:XML encoding [parameter=value …])
```

***Method:*** `Cms::cmsXml()`  
***Without key:*** the first value without `=` is the `encoding`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `encoding` ¹ | `encoding` | `utf-8` |  | Character encoding to be used. |
| `version` | `version` | `1.0` |  | XML version. |

¹ can be given without a key as the first value.

`encoding` and `version` are output escaped with htmlspecialchars.

## Usage

Creates the XML declaration `<?xml version="1.0" encoding="utf-8"?>`. It must be the
***first character*** of the output – the command therefore stands in the first line of a
feed template, without a blank line before it:

```cms
(CMS:XML)
<rss version="2.0">
  <channel>
    <title>(CMS:TEXT title specialchars=true lbrconvert=false)</title>
    (CMS:SELECT filter[area]=articles limit=20 resulttemplate=rss_item)
  </channel>
</rss>
```

Matching this, `(CMS:HEADER "Content-Type: application/rss+xml; charset=utf-8")` – it
outputs nothing itself and therefore stands ***before*** `(CMS:XML)` on the same line, or
without a blank line in between:

```cms
(CMS:HEADER "Content-Type: application/rss+xml; charset=utf-8")(CMS:XML)
<rss version="2.0">
```
Typing the declaration into a template directly would not work: `<?xml` would be read by PHP
as an opening tag.

## Parameters in detail

### `encoding`

```cms
(CMS:XML encoding=ISO-8859-1)
```
Output: `<?xml version="1.0" encoding="ISO-8859-1"?>` – then output the content with `charset=ISO-8859-1`.

### `version`

```cms
(CMS:XML version=1.1)
```

---

[← Overview](README.md)
