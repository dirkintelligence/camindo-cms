# CMS:MEDIA

Creates an image tag or outputs the content of a media file in text form.

```cmscode
(CMS:MEDIA field [parameter=value …])
```

***Method:*** `Cms::cmsMedia()`  
***Without key:*** the first value without `=` is the `field`  
***At least one of:*** `field`, `useval`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `id` | `record ID or slug` | `(current record)` |  | Record to read from. Pure digit sequence = record_id, anything else = slug – the same rule as in the URL. |
| `field` ¹ | – | – | ² | Name of an upload or thumbnail field. |
| `subfield` | `path / file / filename / mime_type / extension / size / width / height / version` | – |  | Single value instead of an IMG tag: path (path from DOCUMENT_ROOT for src/href, with a readable file name; with auth/download/user_auth via file.php), file (absolute path in the file system), filename (original file name), mime_type, extension (without dot), size (bytes; readable with autoformat), width / height (pixels, 0 if unknown), version (version counter of the file). The single value is not escaped. |
| `auth` | `true / false` | `false` |  | true: The link points to /file.php instead of the media directory. For fields with "Only for signed-in frontend users" (user_auth) this applies automatically – file.php checks the frontend session there. |
| `download` | `text` | – |  | The file is offered for download via /file.php under the given file name (the file extension is added automatically). |
| `width` | `text` | – |  | Width of the IMG tag, with or without CSS unit (default: px). |
| `height` | `text` | – |  | Height of the IMG tag, with or without CSS unit (default: px). |
| `attr[…]` | `array` | – |  | Additional attributes for the IMG tag (key: attribute name, value: attribute value). The values are automatically escaped with htmlspecialchars. |
| `autoformat` | `true / false` | `true` |  | true: The content selected via 'subfield' is formatted depending on its type (e.g. file sizes in bytes/KB/MB dynamically according to the value). |
| `instead` | `text` | – |  | Overrides the formatted value, if it is not empty. |
| `ifempty` | `text` | – |  | Alternative text if the formatted value is empty (or "0"). |
| `prefix` | `text` | – |  | Text that is placed before the formatted value, if it is not empty (or "0"). |
| `suffix` | `text` | – |  | Text that is appended if the formatted value is not empty (or "0"). |
| `useval` | `array` | – | ² | File array (e.g. from CMS:SAVEDATA) together with field_id and record_id – only via a direct PHP call, without field (see example). |
| `set` | `array index of $set` | – |  | The formatted value is not output, but stored in the variable $set. |

¹ can be given without a key as the first value.  
² at least one of the parameters marked with ² must be given.

`[…]` – the parameter accepts subkeys, for example `name[key]=value`.

## Usage

The source is an ***upload field***. Without `subfield`, an `<img>` tag is created with the
stored dimensions; `alt` is the original file name:

```cms
(CMS:MEDIA image)
```
Output: `<img src="/media/1/12/1977,beach.jpg?v=3" alt="beach.jpg" width="1200" height="800">`

The path carries the original file name after a comma (a readable URL for
browsers and search engines, as a slug without extension); the file itself is called `1977.jpg` –
a rewrite rule in the media directory discards the name part without checking it. `?v=` carries
the version number of the file: after a new upload, the address changes and the
browser reloads the file.

With `subfield`, a single value of the file is output – this way, arbitrary
tags can be built or downloads linked:

```cms
<a href="(CMS:MEDIA document subfield=path)">
  (CMS:MEDIA document subfield=filename) ((CMS:MEDIA document subfield=size))
</a>
```
Output: `<a href="/media/1/13/1977,terms.pdf?v=3">terms.pdf (123.5 KB)</a>`

No image uploaded? `ifempty` provides the replacement – `prefix`/`suffix` are then omitted:

```cms
(CMS:MEDIA image width=300 height="" prefix="<figure>" suffix="</figure>" ifempty="<img src='/static/placeholder.png'>")
```

**Available `subfield` values**

| subfield | Content |
|---|---|
| `path` | Path from DOCUMENT_ROOT for `src`/`href`: `/media/{instance}/{field}/{record},{name}.{extension}` – for protected fields, `auth=true` and `download`, the `/file.php` call instead |
| `file` | absolute path in the server's file system (`…/public/media/1/12/1977.jpg`) – for file operations in PHP, never for links |
| `filename` | original file name (before the upload) |
| `mime_type` | e.g. `image/jpeg` |
| `extension` | file extension without dot, e.g. `jpg` |
| `size` | file size; readable with `autoformat` (default) (`123.5 KB`), in bytes with `autoformat=false` |
| `width` / `height` | image dimensions in pixels, `0` if not determinable |
| `version` | version counter of the file – increases with every new upload into the same field |

`url` is an alias for `path` (spelling from C9 preview versions). For multilingual
upload fields, the command delivers the file of the current language, or that of the
main language as a fallback (`1977_2,beach.jpg`).

***Protected files*** (field option “Only for signed-in frontend users”): the link points
automatically to `/file.php`, which checks the frontend session. `auth=true` forces this
route also for unprotected fields, `download="name"` offers the file for download.

## Parameters in detail

### `id`

`id` reads from another record instead of the current one – record_id or slug,
exactly as in the URL:

```cms
(CMS:MEDIA image id=1977)
(CMS:MEDIA image id=imprint)
```

### `field`

Name of the upload field; may be given without a key:

```cms
(CMS:MEDIA field=image)
(CMS:MEDIA image)
```

### `subfield`

Single value instead of an `<img>` tag (list see above):

```cms
<img src="(CMS:MEDIA image subfield=path)" alt="(CMS:TEXT title specialchars=true)" loading="lazy">
File type: (CMS:MEDIA document subfield=mime_type)
```

Single values via `subfield` are ***not escaped***. `path` contains only
URL-safe characters and can stand directly in `src` or `href`. The original
file name (`filename`) is chosen by the uploader – in an attribute, escape it
via `CMS:TEXT`:

```cms
(CMS:MEDIA document subfield=filename set=filename)
<a href="(CMS:MEDIA document subfield=path)" title="(CMS:TEXT useval="{$set['filename']}" specialchars=true lbrconvert=false)">…</a>
```

`path` and `file` differ in purpose – a link for the browser versus access by PHP:

```cms
(CMS:MEDIA document subfield=path)      /media/1/13/1977,terms.pdf?v=3
(CMS:MEDIA document subfield=file)      /var/www/example/public/media/1/13/1977.pdf
```

```cms
(CMS:MEDIA document subfield=file set=file)
<?php echo filesize($set['file']) > 5e6 ? 'large file' : ''; ?>
```

### `auth`

Force the link via `/file.php` – for example, so that a preview takes the same access
route as the protected original:

```cms
<p>Preview: (CMS:MEDIA thumb)</p>
<p>Original (signed-in only): (CMS:MEDIA image auth=true)</p>
```
For fields with the option “Only for signed-in frontend users”, this applies automatically.

### `download`

Offer the file for download; `download` is the file name when saving,
the extension is added:

```cms
<a href="(CMS:MEDIA document subfield=path download="GTC-2026")">Download terms</a>
```
Output: `<a href="/file.php?file=13/1977&v=2&download=GTC-2026">Download terms</a>`

### `width`

Width of the `<img>` tag; without specification, the stored image width. An empty value
omits the attribute, and the browser then calculates proportionally:

```cms
(CMS:MEDIA image width=300 height="")
```
Output: `<img src="/media/1/12/1977,beach.jpg?v=3" alt="beach.jpg" width="300">`

### `height`

```cms
(CMS:MEDIA image width="" height=200)
(CMS:MEDIA image width="100%" height="")
```

### `attr`

Further attributes; key = attribute name. Existing ones (`src`, `alt`, `width`,
`height`) can be overridden this way:

```cms
(CMS:TEXT title striptags=true ampconvert=false lbrconvert=false set=title)
(CMS:MEDIA image attr[alt]="{$set['title']}" attr[class]="hero" attr[loading]="lazy")
```

`CMS:MEDIA` ***escapes all attribute values*** of the `<img>` tag itself with
`htmlspecialchars` – including those from `attr`. Therefore pass unescaped text,
without `specialchars=true`; otherwise escaping happens twice and `&` becomes visible as
`&amp;`. From the attribute name, the command removes all characters except letters,
digits, `_` and `-`.

### `autoformat`

Affects only `subfield=size`: readable by default (`123.5 KB`), in bytes with `false`:

```cms
(CMS:MEDIA document subfield=size)                   123.5 KB
(CMS:MEDIA document subfield=size autoformat=false)  126464
```

### `instead`

Fixed text if a file is present (the tag or the single value itself is not output):

```cms
Image: (CMS:MEDIA image instead="present" ifempty="missing")
```

### `ifempty`

Replacement if no file is present (or the single value is empty or `0`):

```cms
(CMS:MEDIA image ifempty="<img src='/static/placeholder.png' alt=''>")
(CMS:MEDIA image subfield=width ifempty="unknown")
```

### `prefix`

`prefix` / `suffix` surround the value and appear only if it is not empty –
so no empty `<figure></figure>`:

```cms
(CMS:MEDIA image prefix="<figure>" suffix="</figure>")
```

### `suffix`

```cms
(CMS:MEDIA image suffix="</figure>")
```

See `prefix`.

### `useval`

Passes a file array instead of a field – e.g. from a record saved with `CMS:SAVEDATA`.
Parameter values in the template are always strings; an array can therefore only be passed
via a direct PHP call of the method. `field` must **not** be specified in this case –
it would take precedence and read the field of the current record.
Because the file array does not contain the field id, `field_id` and `record_id`
belong in it explicitly:

```cms
(CMS:SAVEDATA author id="{$cms['author_id']}")
<?php $this->cms->cmsMedia([
    'useval' => $GLOBALS['author']['portrait'] + ['record_id' => $GLOBALS['author']['record_id'], 'field_id' => 17],
    'width'  => 80, 'height' => '',
]); ?>
```

`17` stands here for the id of the upload field `portrait`. Almost always simpler is
`(CMS:MEDIA portrait id="{$cms['author_id']}")` – `id` loads the other record
directly and finds the field by its name.

### `set`

`set` outputs nothing, but stores the result under `$set['img']` for the
surrounding PHP or a later command:

```cms
(CMS:MEDIA image subfield=path set=img)
<?php if ($set['img'] !== '') { ?> … <?php echo $set['img']; ?> … <?php } ?>
```

---

[← Overview](README.md)
