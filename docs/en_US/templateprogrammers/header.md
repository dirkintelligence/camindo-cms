# CMS:HEADER

Sends an HTTP header.

```cmscode
(CMS:HEADER text [parameter=value …])
```

***Method:*** `Cms::cmsHeader()`  
***Without key:*** the first value without `=` is the `text`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `text` ¹ | `text` | – |  | Line of text that is sent as a header to the browser. Without a value (`Name:`), the header is removed. |
| `overwrite` | `true / false` | `true` |  | false: A previous header of the same type is not overwritten; a new header is added. |

¹ can be given without a key as the first value.

## Usage

Sends an HTTP header. It must come before the first output – so at the beginning of the
template, before any HTML and without a blank line in front:

```cms
(CMS:HEADER "Content-Type: application/json; charset=utf-8")
{"title": (CMS:TEXT title specialchars=true lbrconvert=false prefix='"' suffix='"')}
```

Further typical cases:

```cms
(CMS:HEADER "Content-Type: application/rss+xml; charset=utf-8")
(CMS:HEADER "Cache-Control: no-store")
(CMS:HEADER "HTTP/1.1 404 Not Found")
```

Redirect – CMS commands cannot be nested within each other, so the address is first
passed to `$set` with `set` and then placed into the header:

```cms
(CMS:URL id=home set=target)
(CMS:HEADER "Location: {$set['target']}")
```

If a template delivers CSS, JavaScript or JSON, the matching `Content-Type` belongs at
the beginning – camindo CMS sends `X-Content-Type-Options: nosniff`, so the browser does not
execute a script with the default type `text/html`:

```cms
(CMS:HEADER "Content-Type: text/javascript; charset=utf-8")
```

If something has already been output, an error message is stored (`CMS:MESSAGE`) and the
header is not sent.

## Parameters in detail

### `text`

The header line; may be given without a key:

```cms
(CMS:HEADER text="X-Robots-Tag: noindex")
(CMS:HEADER "X-Robots-Tag: noindex")
```

### `overwrite`

By default, a header replaces a previous header of the same name; `overwrite=false` adds another one:

```cms
(CMS:HEADER "Link: </style.css>; rel=preload; as=style")
(CMS:HEADER "Link: </app.js>; rel=preload; as=script" overwrite=false)
```

### Replacing or removing the defaults

camindo CMS sends some security headers before the template:

```
X-Frame-Options: SAMEORIGIN
Content-Security-Policy: frame-ancestors 'self'
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Cache-Control: private, no-cache
```

This means the page may only be placed in a frame within the website itself
(background information: [Basics](01-basics.md#security-headers-css-javascript-json-and-embedding)). Each of
these headers can be replaced; without a value, it is removed. A page that a partner
may embed:

```cms
(CMS:HEADER "X-Frame-Options:")
(CMS:HEADER "Content-Security-Policy: frame-ancestors 'self' https://partner.example")
```

If a ***script policy*** is switched on for the website (back end, instance), camindo
sends `script-src 'self' 'nonce-…'` together with `frame-ancestors 'self'` instead; for
individual pages, adapt it with [CMS:CSP](csp.md).

A custom Content-Security-Policy replaces the default – then include
`frame-ancestors` as well (the nonce of the page is provided by [CMS:NONCE](nonce.md)):

```cms
(CMS:HEADER "Content-Security-Policy: frame-ancestors 'self'; script-src 'self' https://cdn.example")
```

### Caching

Pages are generated anew on every request. With the default
***`Cache-Control: private, no-cache`***, no proxy and no CDN stores the page, and the
browser asks the server again before every display – changed content appears
immediately. The browser's back button stays fast all the same.

If a page belongs to a single visitor – a session of a frontend user is running (also a
basket without sign-in, see [CMS:USER_BASKET](user-basket.md)) or frontend editing is
switched on – camindo sends ***`Cache-Control: private, no-store`*** instead: the page
is not stored anywhere.

A page that rarely changes may be cached longer:

```cms
(CMS:HEADER "Cache-Control: public, max-age=3600")
```

Put the header at the top of the template. If a `CMS:USER_*` command with a session runs
afterwards, `private, no-store` applies again – so personal pages never end up in a cache.

---

[← Overview](README.md)
