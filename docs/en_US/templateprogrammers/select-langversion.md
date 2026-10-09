# CMS:SELECT_LANGVERSION

Returns the available languages as records.

```cms
(CMS:SELECT_LANGVERSION resulttemplate)
```

***Method:*** `Cms::cmsSelectLanguages()`  
***Without key:*** the first value without `=` is the `resulttemplate`  
***PHP snippet:*** `push_result` (before) · `include`, `pop_result` (after)

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `resulttemplate` ¹ | `template name` | – | yes | List template that is included to process the results. |
| `set` | `array index of $set` | – |  | The entire output (including the included template) is not output, but stored in the variable $set. |

¹ can be given without a key as the first value.

## Usage

Provides the languages of the website as a result set – main language first –
and includes the `resulttemplate`. Each row contains `language_id`, `code`, `label`,
`endonym` and `current` (1 = language of the current call). This creates a
language switcher:

```cms
<ul class="lang">
(CMS:SELECT_LANGVERSION lang_item)
</ul>
```

List template `lang_item`:

```cms
(CMS:RESULT_START)
<li<?php if ($cms['current']) echo ' class="active"'; ?>>
  <a href="(CMS:URL lang="{$cms['code']}")" hreflang="(CMS:TEXT code)">(CMS:TEXT endonym)</a>
</li>
(CMS:RESULT_END)
```

The rows are not records; `CMS:URL` without `id` therefore points to the **outer** record –
the record of the page or the row of the surrounding result loop –
only in the respective other language. If the field `slug` is multilingual, `CMS:URL`
inserts the slug of the target language (`/de/esstisch-nord-180` → `/en/dining-table-nord-180`),
see [CMS:URL](url.md#lang). With only one language, the result set contains exactly
one row.

On a page without a record (stand-alone template, `/de/0,suche`) there is
no outer record; the template must then be specified explicitly in the link:

```cms
<a href="(CMS:URL lang="{$cms['code']}" template=suche)">(CMS:TEXT endonym)</a>
```

## Parameters in detail

### `resulttemplate`

List template; may be given without a key:

```cms
(CMS:SELECT_LANGVERSION resulttemplate=lang_item)
(CMS:SELECT_LANGVERSION lang_item)
```

---

[← Overview](README.md)
