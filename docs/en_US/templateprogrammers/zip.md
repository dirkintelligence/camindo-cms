# CMS:ZIP

Offers the files of several records as a ZIP archive for download and ends the
page.

```cmscode
(CMS:ZIP field=field[,field…] filter[…]=value | relation=field | ids=… | source=basket [parameter=value …])
```

***Method:*** `CmsZip::cmsZip()` (service loaded on demand, see below)  
***Without key:*** the first value without `=` is the `field`  
***Required:*** `field` and a selection: `filter`, `relation`, `ids` or `source`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `field` ¹ | `field name(s), comma-separated` | – | yes | Upload or thumbnail field(s). For each record, the first one that the visitor may retrieve applies. |
| `filter[…]` | `value` | – | ² | Selection of the records as with [CMS:SELECT](select.md). |
| `filter_op[…]` | `operator` | `=` |  | Comparison operator as with CMS:SELECT. |
| `filter_mode[…]` | `AND / OR` | `OR` |  | Combination of several values as with CMS:SELECT. |
| `relation` | `field name` | – | ² | The linked records of a record. |
| `src_id` | `record ID` | current record |  | Source record for `relation`. Empty or `0`: no files. |
| `ids` | `record IDs, comma-separated` | – | ² | Explicitly named records. |
| `source` | `basket` | – | ² | The records in the basket of the session – for example a light box. |
| `category` | `number` | `0` |  | Basket category for `source=basket`. |
| `sort[…]` | `field name` | – |  | Order as with CMS:SELECT; without specification, that of `ids` or of the basket. |
| `order[…]` | `asc / desc` | `asc` |  | Sort direction. |
| `name` | `text` | `download` |  | Name of the archive without `.zip`. |
| `filenames` | `pattern` | `{slug}.{extension}` |  | File names in the archive (see below). |
| `credits` | `field names, comma-separated` | – |  | Adds a `CREDITS.txt`: for each file its name and the contents of these fields. |
| `maxfiles` | `number` | setting |  | Maximum number of files – only smaller than the setting `user.zip.max_files`. |
| `maxsize` | `MB` | setting |  | Maximum size in MB – only smaller than the setting `user.zip.max_mb`. |
| `future` | `true / false` | `false` |  | Also pre-dated records (as with CMS:SELECT). |
| `archive` | `true / false` | `false` |  | Also expired records (as with CMS:SELECT). |
| `cookiename` | `cookie name` | `usess` |  | Session cookie, as with [CMS:USER_LOGIN](user-login.md). |
| `message[…]` | `text` | core snippet |  | Own messages: `message[empty]`, `message[limit]`, `message[throttled]`. |

¹ can be given without a key as the first value.  
² at least one of the parameters marked with ² must be given.

`[…]` – the parameter accepts subkeys, for example `name[key]=value`.

## Usage

Press pictures of a release, all motifs of a model, the selection from a
***light box***: `CMS:ZIP` packs the files of several records into an archive and
sends it to the visitor as a download. The page ends with it – what follows in the template
appears only if no archive was created.

The command therefore belongs at the ***beginning of its own template***, before any output
(the frontend does not buffer; after the first output, the command reports an error).
A stand-alone template that is called without a record is well suited:

```cms
(CMS:NUMBER useval="{$_GET['id']}" set=zip_id)
(CMS:ZIP relation=images src_id="{$set['zip_id']}" sort="_custom asc"
         field="fil_print,fil_web" name="Press pictures" credits="title,caption,photo_credit")
(CMS:INCLUDE layout_head)
(CMS:MESSAGE prefix="<div class='error'>" suffix="</div>")
(CMS:INCLUDE layout_foot)
```

It is linked with the id of the record whose pictures are meant:

```cms
(CMS:URL id=0 template=press_pictures_zip set=zip_url)
<a href="<?php echo $set['zip_url']; ?>?id=(CMS:TEXT record_id)">All pictures as ZIP</a>
```

`CMS:NUMBER` turns the address parameter into a number. If it is missing, `src_id` is empty –
then there are no files, never “all”.

***Which records.*** The selection follows the same rules as [CMS:SELECT](select.md):
`filter`, `relation` with `src_id` or `ids`. Only published records count
from the instances of the website (`future` and `archive` as with CMS:SELECT). One
specification is required – without a selection, no archive is created.

With `source=basket`, the records come from the ***basket*** of the session
([CMS:USER_BASKET](user-basket.md)). A separate category separates the light box from
shopping:

```cms
(CMS:USER_BASKET add variable=images category=2)       in the template of the light box
(CMS:ZIP source=basket category=2 field=fil_web name=Selection)   in the download template
```

***Which file.*** `field` names one or more file fields. For each record, the
first file that the visitor may also retrieve individually goes into the archive – the same check
as for a download via `file.php`. If a field is released ***only for signed-in visitors***,
only those who are signed in with a suitable profile receive it; everyone else receives the
next field in the list. Thus `field="fil_print,fil_web"` gives the
signed-in press the print version, and everyone else the web version. Records without an
allowed file are skipped.

***File names.*** `filenames` determines the names in the archive:

| Placeholder | Content |
|---|---|
| `{slug}` | Slug of the record (without slug, its id) |
| `{id}` | Id of the record |
| `{filename}` | original file name without extension |
| `{extension}` | extension of the file |
| `{fieldname}` | content of a field of the record |

The extension is added if the pattern does not contain it; duplicate names receive
`-2`, `-3` …

***Image credits.*** `credits` adds a `CREDITS.txt` – for each file its name and below
it the contents of the named fields, for example title, caption and photographer.

***Limits.*** An archive contains at most `user.zip.max_files` files (default 100)
with a total of `user.zip.max_mb` MB (default 500). `maxfiles` and `maxsize` can only
reduce these values for a template. If the selection exceeds the size, no archive
is created.

Finished archives remain in the cache for `user.zip.retention_hours` hours (default 24);
the same selection is delivered from the cache. New archives are throttled per origin
(`user.throttle.zip_ip`, default 30 per hour).

***Messages.*** If no archive is created, the template continues to run. `CMS:MESSAGE` outputs the
message, `$set['zip_error']` names the reason:

| Reason | Meaning |
|---|---|
| `empty` | no file that the visitor may retrieve |
| `limit` | selection larger than the permitted size |
| `throttled` | too many archives in a short time |

The texts come from the core snippets `zip_error_empty`, `zip_error_limit` and
`zip_error_throttled`; `message[empty]=…` etc. replaces them in the template.

---

[← Overview](README.md)
