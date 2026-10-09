# CMS:SAVEDATA

Stores the data of the current record in the specified variable.

```cmscode
(CMS:SAVEDATA phpvar [parameter=value …])
```

***Method:*** `Cms::cmsSaveData()`  
***Without key:*** the first value without `=` is the `phpvar`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `phpvar` ¹ | `index within $GLOBALS` | – | yes | Variable in which the data of the current record is to be stored. |
| `id` | `record ID or slug` | – |  | Record whose data is to be loaded. Pure digit sequence = record_id, anything else = slug – the same rule as in the URL. Without specification, the currently loaded record. |
| `lang` | `language version code` | `$lang` |  | Specifies in which language the contents of multilingual fields are to be loaded. Not yet implemented – currently always the language of the call applies. |

¹ can be given without a key as the first value.

## Usage

Stores a complete record as an array in a global PHP variable – for
logic that cannot be written with the output commands alone:

```cms
(CMS:SAVEDATA article)
<?php
$article = $GLOBALS['article'];
if ($article['price'] > 100 && $article['stock'] > 0) { ?>
  <span class="badge">Premium</span>
<?php } ?>
```

With `id`, load a **different** record, for example the parent node, in order to calculate
with its values:

```cms
(CMS:SAVEDATA parent id="{$cms['parent_id']}")
<?php if ($GLOBALS['parent']['price'] > $cms['price']) { ?> … <?php } ?>
```

If you only need to ***output*** fields of another record, you do not need
`CMS:SAVEDATA` – the output commands read with `id` themselves:

```cms
<a href="(CMS:URL "{$cms['parent_id']}")">(CMS:TEXT title id="{$cms['parent_id']}")</a>
<footer>(CMS:TEXT footer_text id=configuration)</footer>
```

The array contains the meta fields (`record_id`, `area_id`, `online_at` …) and the
fields of the area in the current language; upload fields as a file array (`filename`,
`mime_type`, `size` …). ***Relation fields are missing*** – their targets are provided by
`CMS:SELECT relation=…`, their count by `(CMS:SELECT x relation=topics hits=false)` –
afterwards it is available in `$this->db->num_rows_unlimited`.
If the record does not exist, the array is empty.

## Parameters in detail

### `phpvar`

Name of the variable in `$GLOBALS`; may be given without a key. Only letters, digits and `_`:

```cms
(CMS:SAVEDATA phpvar=article)
(CMS:SAVEDATA article)
<?php echo $GLOBALS['article']['record_id']; ?>
```

### `id`

Another record – record_id or slug:

```cms
(CMS:SAVEDATA parent id="{$cms['parent_id']}")
(CMS:SAVEDATA site id=configuration)
```

### `lang`

> Not yet implemented – the language of the call applies.

---

[← Overview](README.md)
