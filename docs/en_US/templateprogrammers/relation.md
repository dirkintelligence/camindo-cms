# CMS:RELATION

> ***Important:*** CMS:RELATION is intended only for back-end templates (list and
> trigger templates). It does not check access rights: it returns the linked
> records regardless of instance and area rights. As with `CMS:SELECT`, however, only
> ***published*** targets count (released, publication date reached,
> expiry date not reached) – also in the back end and in the preview; `future`,
> `archive` and `state` relax this. Records in the trash and working copies
> never appear.

Outputs the main field (title) of all records that are linked to the current record via a
relation field – in the preferred language version of the back end
(per instance; if an instance does not offer it, its main language). Records with an
empty main field do not appear.  
For front-end templates, use `(CMS:SELECT relation=…)`

```cmscode
(CMS:RELATION field [parameter=value …])
```

***Method:*** `Cms::cmsRelation()`  
***Without key:*** the first value without `=` is the `field`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `id` | `record ID or slug` | `(current record)` |  | Record whose relations are output. Pure digit sequence = record_id, anything else = slug – the same rule as in the URL. |
| `field` ¹ | `field` | – |  | Name of the relation field (field type relation, pointer or link) whose targets are output. Another field type results in an error message. |
| `limit` | `number >0` | `1000` |  | Maximum number of records included in the result set. |
| `future` | `true / false` | `false` |  | true: Targets not yet published (online_at not yet reached) are also output (as with CMS:SELECT). |
| `archive` | `true / false` | `false` |  | true: Expired targets (offline_at reached) are also output (as with CMS:SELECT). |
| `state` | `state ID or any` | `0` |  | Without specification, only released targets (status_id 0). A status ID > 0 only targets with this status, "any" targets of any status (as with CMS:SELECT). |
| `lang` | `language code` | `(current language)` |  | Code of the language version whose contents are requested (affects multilingual fields). Not yet implemented – currently the language of the call always applies. |
| `separator` | `separator` | `\|` |  | Text that is to appear between the results. |
| `prefix` | `text` | – |  | Text that is placed before the source, if it is not empty. |
| `suffix` | `text` | – |  | Text that is appended to the source, if it is not empty. |
| `ifempty` | `text` | – |  | Text that becomes the source if the original source is empty (further parameters, except 'set', are ignored). |
| `instead` | `text` | – |  | Overrides the output, if it is not empty. |
| `set` | `array index of $set` | – |  | The output of the source is redirected and stored in $set. |

¹ can be given without a key as the first value.

## Usage

Outputs the ***main field*** (title) of all records to which a relation field of the
current record points – in the order defined in the back end:

```cms
Topics: (CMS:RELATION topics separator=", ")
```
Output: `Topics: Travel, Photography, Iceland`

The command returns only the titles. If the linked records themselves are to be output
(with image, link, date …), `CMS:SELECT relation=topics` is the right way:

```cms
(CMS:SELECT relation=topics resulttemplate=topic_badge)
```

Reverse relations (which articles point to this topic?) work via
`CMS:SELECT filter[topics]="{$cms['record_id']}"`.

## Parameters in detail

### `field`

Name of the relation field; may be given without a key:

```cms
(CMS:RELATION field=topics)
(CMS:RELATION topics)
```

### `id`

The relations of another record – by `record_id` or slug, as in the URL. The
record must be published as in the URL, otherwise the output remains empty:

```cms
(CMS:RELATION topics id=1977)
(CMS:RELATION topics id=island-trip)
```

### `limit`

```cms
(CMS:RELATION topics limit=3 separator=", ")
```

At most three titles are output – the first three in the order of the relation field.

### `future`

Also targets whose publication date has not yet been reached:

```cms
(CMS:RELATION dates future=true separator=", ")
```

### `archive`

Also expired targets:

```cms
(CMS:RELATION topics archive=true)
```

### `state`

All targets regardless of status – for example in a list template of the back end:

```cms
(CMS:RELATION topics state=any future=true archive=true)
```

### `lang`

> Not yet implemented – the language of the call applies.

```cms
(CMS:RELATION topics lang=en)
```

### `separator`

```cms
(CMS:RELATION topics separator=", ")
(CMS:RELATION topics separator="</li><li>" prefix="<ul><li>" suffix="</li></ul>")
```

The second line produces a list: `<ul><li>Travel</li><li>Photography</li></ul>`.

### `prefix`

`prefix` / `suffix` surround the value and appear only if it is not empty –
so no empty `Topics: `:

```cms
(CMS:RELATION topics prefix="Topics: " suffix="")
```

### `suffix`

```cms
(CMS:RELATION topics suffix=".")
```

See `prefix`.

### `ifempty`

`ifempty` replaces the **entire** output if the source is empty; `prefix` and
`suffix` are then omitted:

```cms
(CMS:RELATION topics prefix="<p>" suffix="</p>" ifempty="no topics assigned")
```

### `instead`

`instead` replaces the value as soon as the source is **not** empty – useful if
only the presence matters:

```cms
(CMS:RELATION topics instead="Topics present" ifempty="-")
```

### `set`

`set` outputs nothing, but stores the result under `$set['topics']` for the
surrounding PHP or a later command:

```cms
(CMS:RELATION topics set=topics)
<?php if ($set['topics'] !== '') { ?> … <?php echo $set['topics']; ?> … <?php } ?>
```

---

[← Overview](README.md)
