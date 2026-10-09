# CMS:INCLUDE

Includes a template.

```cms
(CMS:INCLUDE template)
(CMS:INCLUDE template caching=5m cacheparams=$params)
```

***Method:*** `Cms::cmsInclude()`  
***Without key:*** the first value without `=` is the `template`  
***PHP snippet:*** `include_cached` (after)

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `template` ¹ | `template name` | – | yes | Detail template that is included. |
| `set` | `array index of $set` | – |  | The entire output (including the included template) is not output, but stored in the variable $set. |
| `caching` | seconds, `30s`, `5m`, `1h`, `1d` or `infinite` | – |  | The output of the template is cached for this long and then delivered without processing again. Without specification, empty or `0`: no cache. |
| `cacheparams` | `null`, `bool`, `int`, `string` or array | – |  | Everything the output depends on (record, page, customer …): each value creates its own cache file. Instance and language are taken into account automatically. |

¹ can be given without a key as the first value.

## Usage

Includes another template at this point – for header and footer areas,
navigation, recurring blocks:

```cms
(CMS:INCLUDE header)
<main>
  <h1>(CMS:TEXT title)</h1>
  (CMS:INCLUDE text_image_block)
</main>
(CMS:INCLUDE footer)
```

The included template sees the same record (`$cms`), the same `$set` values
and, within a result loop, the same row. A name without an area must be unique within the
access area of the instance, otherwise use `area.name`:

```cms
(CMS:INCLUDE templates.header)
```

The nesting depth is limited by `admin.max_include_depth` (default 20) –
a template that includes itself aborts with an error message.

## Parameters in detail

### `template`

Name or `area.name` (no id); may be given without a key. In the back-end editor, the value is a link to the template:

```cms
(CMS:INCLUDE template=header)
(CMS:INCLUDE header)
(CMS:INCLUDE templates.header)
(CMS:INCLUDE "{$set['layout']}")        dynamic
```

### `set`

The output of the included template ends up in `$set` instead of on the page – for example to
use a block twice or to pass it on to another command:

```cms
(CMS:INCLUDE price set=price)
<p class="price"><?php echo $set['price']; ?></p>
<meta property="product:price" content="(CMS:TEXT useval="{$set['price']}" striptags=true specialchars=true)">
```

With `caching`, the intercepted text also comes from the cache.

### `caching`

Switches on the ***output cache*** for this call: the first time, the template runs as usual,
and its output is stored under `var/cache/include/`. Every further call with the same
parameters delivers this file within the time limit – the template, its queries and included
templates then do not run.

| Value | Time limit |
|---|---|
| `300` or `300s` | 300 seconds |
| `5m` | 5 minutes |
| `1h` | 1 hour |
| `1d` | 1 day |
| `infinite` | unlimited – until the template is changed |
| empty or `0` | no cache (for example to switch it off via a variable) |

```cms
(CMS:INCLUDE navigation caching=1h)
(CMS:INCLUDE footer caching=1d)
(CMS:INCLUDE teaser caching="{$set['cache_time']}")
```

An invalid value (`caching=5x`) appears as an error message; the template then runs
without a cache.

### `cacheparams`

The cache distinguishes by ***website*** and ***language*** on its own. If the
output depends on further things – the record, the page of a list, a filter, the
signed-in customer –, they belong in `cacheparams`. Each other value creates
its own cache file.

Permitted are `null`, `true`/`false`, numbers, texts and arrays. A variable without
quotation marks is passed unchanged, including an array:

```cms
<?php $params = ['id' => $cms['record_id'], 'page' => (int)($_GET['skip'] ?? 0)]; ?>
(CMS:INCLUDE product_list caching=10m cacheparams=$params)
(CMS:INCLUDE product_box caching=1h cacheparams=$cms['record_id'])
(CMS:INCLUDE product_box caching=1h cacheparams="product-{$cms['record_id']}")
```

Arrays are compared as a whole – the same keys in a different order produce
a different cache file.

### What the cache takes into account

* If the included template is ***changed***, its cache files immediately no longer apply.
  If ***content*** changes in the back end, however, visitors see it only after
  the time limit has expired. Choose the time limit according to how quickly changes
  must become visible.
* A template that itself includes templates stores their output with it. If such an
  inner template changes, the cached output remains until the time limit expires.
* Only the ***output*** is stored. What the template otherwise does – setting values in `$set`,
  `CMS:HEADER`, `CMS:CSP`, cookies, emails – does not happen on a cache hit.
  Such commands belong in the calling template.
* The nonce of the script policy (`CMS:NONCE`) is the current one in every delivery.
* Do not cache forms with security tokens (`CMS:USER_TOKEN`, `CMS:USER_INPUT`) and personal
  content of signed-in customers – or include the customer in `cacheparams`.
* If a command reports an error while generating the output, the output is not stored.
* In the ***preview*** from the back end and when ***frontend editing*** is switched on,
  no cache applies.
* If many visitors call an expired template at the same time, only one of them generates it;
  the others wait briefly and then get the result.

Expired files are cleaned up by the scheduler task
***“Clean up frontend cache”***; the cache is emptied completely with
`php Core/cron/camindo.php cache include`. The system administrator sets up both.

---

[← Overview](README.md)
