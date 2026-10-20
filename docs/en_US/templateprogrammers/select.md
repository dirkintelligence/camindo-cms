# CMS:SELECT

Searches the database using the given (filter) criteria and returns the records found.

```cmscode
(CMS:SELECT resulttemplate [parameter=value …])
```

***Method:*** `Cms::cmsSelect()`  
***Without key:*** the first value without `=` is the `resulttemplate`  
***At least one of:*** `filter`, `relation`  
***PHP fragments:*** `push_result` (before) · `include`, `pop_result` (after)

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `resulttemplate` ¹ | `templatename` | – | yes | List template that is included to process the results. |
| `noresulttemplate` | `templatename` | – |  | Detail template that is included if the query returns no results. |
| `noresult` | `text` | – |  | Text that is output directly if the query returns no results. |
| `filter[…][…]` | `value or EMPTY` | – | ² | In the key: name of the field to search (relation_any for any link field; area for the name of the content area, a number there counts as area_id). In the value: the value to search for. |
| `filter_mode[…]` | `AND / OR` | `OR` |  | Mode in which several values of the same field are combined (filter_mode[field]=AND). |
| `filter_op[…][…]` | `=, ==, !=, !!, >, <, >>, <<, >=, <=` | `=` |  | Comparison operator for the given field (filter_op[field]=">"). Alternatively the operator stands between key and value (filter[price] > 100) or directly before the value (filter[price]=">100"). |
| `skip` | `number >=0` | `0` |  | Number of records skipped in the result set. Only works together with limit. |
| `limit` | `number >0` | – |  | Maximum number of records included in the result set – at the same time the page size for CMS:JUMP_LIST, CMS:JUMP_PREV and CMS:JUMP_NEXT. Without a value or 0: unlimited, no page navigation. |
| `sort[…]` | `field name(s), comma-separated` | `changed_at` |  | Field to sort by (see order); several comma-separated or as sort[]=…; area sorts by the name of the content area, pointerfield.field by a field of the linked record. |
| `order[…]` | `desc / asc` | `asc` |  | Order in which to sort (see sort). Without sort, changed_at desc applies. |
| `lang` | `language code` | `(current language)` |  | Code of the language version whose contents are requested (affects multilingual fields). Not yet implemented – currently always the language of the call applies. |
| `relation` | `field name(s) comma-separated or 'ANY'` | – | ² | Field names of relation/pointer fields in which the sought records are stored (with src_id); with dest_id the fields via which the sought records point to dest_id. ANY: any pointer field. |
| `src_id` | `record ID(s)` | `current record` |  | Source record whose links are evaluated (without a value: current record). |
| `dest_id` | `record ID` | – |  | Reverse direction to relation: the records that point to this record via relation (relation=ANY: via any pointer field). Without src_id, no current record is then used as the source. |
| `nextof` | `record ID` | – |  | Only with relation: neighbors of the given record in the link list – the entries after it (order=asc) or before it (order=desc), in the order of the list; with limit=1 the next or previous record. |
| `count` | `true / false` | `false` |  | true: The number of records in the result set is determined, regardless of "limit" and "skip" (in the resulttemplate in $this->db->num_rows_unlimited; basis of the JUMP commands). |
| `hits` | `true / false` | `true` |  | false: Only counts (includes count=true); no records are loaded and no resulttemplate is included. The total is then available in the calling template in $this->db->num_rows_unlimited; noresult/noresulttemplate apply if it is 0. |
| `archive` | `true / false` | `false` |  | true: Expired records (offline_at reached) are included in the query as well. |
| `future` | `true / false` | `false` |  | true: Pre-dated records (online_at not yet reached) are included in the query as well. |
| `debug` | `true / false` | `false` |  | true: The generated database query is output for support analysis. |
| `sql` | – | – |  | Raw SQL addition in the WHERE clause (caution: for support only!). |
| `state` | `status ID or any` | `0` |  | Without a value only released records (status_id 0). A status ID > 0 finds only records with this status; "any" finds records of every status. |
| `request` | `comma list` | – |  | Takes filter and sort values from the URL – comma list or array of the permitted keys: filter[field], filter_op[field], filter_mode[field], skip, limit, sort, order (e.g. "filter[title],sort,order,skip"). filter only with a field name. Values set in the command always take precedence; for a field filtered in the command, the URL adds nothing. |
| `set` | `array index of $set` | – |  | The entire output (including the included template) is not output, but stored in the variable $set. |

¹ can be given without a key as the first value.  
² at least one of the parameters marked with ² must be given.

`[…]` – the parameter accepts subkeys, for example `name[key]=value`.

## Usage

Queries records and includes the `resulttemplate` for the hits; in it,
`CMS:RESULT_START` … `CMS:RESULT_END` runs over the rows. The command is the core of every
list.

Only **published** records are found: released (`status_id` 0), publication date (`online_at`)
reached, end (`offline_at`) not reached. The parameters `future`, `archive` and `state` relax
this per query – also in the preview from the back end, which shows an unpublished record but
shows the lists in it the way the website shows them.

```cms
<h2>Latest articles</h2>
(CMS:SELECT article_teaser
  filter[area]=articles
  sort=online_at order=desc
  limit=10
  noresult="<p>No articles yet.</p>"
)
```

List template `article_teaser`:

```cms
<ul>
(CMS:RESULT_START)
  <li><a href="(CMS:URL)">(CMS:TEXT title)</a> – (CMS:DATE online_at)</li>
(CMS:RESULT_END)
</ul>
```

Common patterns:

```cms
A record by slug:              (CMS:SELECT detail filter[slug]=imprint limit=1)
Only one topic:                (CMS:SELECT list filter[area]=articles filter[topics]=42)
Search in the title:           (CMS:SELECT list filter[area]=articles filter[title]="*{$_GET['q']}*")
Time period:                   (CMS:SELECT list filter[area]=articles filter[online_at] >= 2026-01-01)
Linked records:                (CMS:SELECT topic_badge relation=topics)
Children in the tree:          (CMS:SELECT nav filter[parent_id]="{$cms['record_id']}" filter[depth]=1 sort=position order=asc)
Pageable list:                 (CMS:SELECT list filter[area]=articles limit=20 skip="{$_REQUEST['skip']}" count=true)
Search form with sorting:      (CMS:SELECT list filter[area]=articles limit=20 count=true request="filter[title],sort,order,skip")
```

Several values for the same field are combined with OR (`filter_mode[field]=AND` switches
this); different fields are always combined with AND. Comparison operators stand between
key and value (`filter[price] > 100`, `filter[color] != red`) or directly before the value
(`">100"`); wildcards and `(empty)` belong in the value (`Hen*`). The complete filter syntax is
in the [Basics](01-basics.md#filter-syntax).

A `CMS:SELECT` may stand inside a list template (nested lists); the outer result is set aside
automatically and resumed afterwards.

## Parameters in detail

### `resulttemplate`

List template for the hits; may stand without a key. Name or `area.name`:

```cms
(CMS:SELECT resulttemplate=article_teaser filter[area]=articles)
(CMS:SELECT article_teaser filter[area]=articles)
(CMS:SELECT articletemplates.article_teaser filter[area]=articles)
```

### `noresulttemplate`

Template for no hits – for example with a search form or a note:

```cms
(CMS:SELECT search_result filter[area]=articles filter[title]="*{$_GET['q']}*" noresulttemplate=search_empty)
```

### `noresult`

Short text for no hits, without its own template:

```cms
(CMS:SELECT list filter[area]=articles noresult="<p>No entries.</p>")
```

### `filter`

Key = field name (own fields, meta fields such as `record_id`, `status_id`, `online_at`,
`parent_id`, `user_id`, the virtual `slug`, `_default` for the main field, `area` for the area
name); value = search value with an optional operator:

```
filter[area]=articles                    area by name (number = area_id)
filter[area]=articles,news               two areas (OR)
filter[title]="Hen*"                     begins with
filter[price] > 100                      greater than
filter[price]=">100"                     the same, operator in the value
filter[price]=">100 AND <500"            expression in one field
filter[online_at]=2026                   the whole year
filter[date] >= today                    from today on (0:00, time zone of the call)
filter[online_at] > utc_now-7d           the last seven days, calculated in UTC
filter[teaser]="(empty)"                 empty / not translated
filter[image]="(empty)"                  file field without a file
filter[topics]=42                        link field points to record 42
filter[topics]="(empty)"                 link field without a link
filter[relation_any]=42                  any link field points to 42
filter[topics.title]="Iceland"           field of the linked record
filter[tags]=a filter[tags]=b            two values, OR (see filter_mode)
filter[parent_id]=17 filter[depth]=1     direct children of 17
```

Text fields are compared and sorted according to the ***rules of the language*** in which
you search: upper and lower case and accents do not matter, `ß` equals `ss`; in German, in
addition, `ä` equals `ae` (`filter[title]="Mue*"` finds “Müller”); in Swedish, by contrast,
`ö` is a letter of its own (`o` does not find `ö`). The rules follow from the language code;
see [Basics](01-basics.md#filter-syntax).

An ***empty value*** cancels the filter – handy for optional filters from the request, risky
with typos: `filter[category]="{$cms['id']}"` (there is no key `id`; the correct key is
`record_id`) returns all records.

### `filter_mode`

Combination of several values of the **same** field (default OR):

```cms
(CMS:SELECT list filter[area]=articles filter[tags]=travel filter[tags]=iceland filter_mode[tags]=AND)
```

Finds articles that carry **both** tags.

### `filter_op`

Comparison operator separate from the value – equivalent to writing it in the value and to the
operator between key and value:

```cms
(CMS:SELECT list filter[area]=articles filter[price]=100 filter_op[price]=">")
(CMS:SELECT list filter[area]=articles filter[price]=">100")
(CMS:SELECT list filter[area]=articles filter[price] > 100)
```

### `skip`

Offset for paging; usually from the request. `skip` only works together with `limit` – without
`limit` it is ignored, and the query then returns all hits from the first one on:

```cms
(CMS:SELECT list filter[area]=articles limit=20 skip="{$_REQUEST['skip']}" count=true)
```

The page navigation ([CMS:JUMP_LIST](jump-list.md)) stands in the `resulttemplate` `list`.

### `limit`

```cms
(CMS:SELECT teaser filter[area]=articles limit=3)
```

At most three hits. Without `limit` (or with `0`) the query returns ***all*** hits – for large
areas, a `limit` therefore always belongs. `limit` is at the same time the page size of the
paging commands [CMS:JUMP_LIST](jump-list.md), [CMS:JUMP_PREV](jump-prev.md) and
[CMS:JUMP_NEXT](jump-next.md) in the `resulttemplate`; without `limit` there is no page
navigation.

### `sort`

Sort field; direction via `order` or directly in the value:

```
sort=online_at order=desc
sort="title asc"
sort=position order=asc                     order from the back end (tree/list)
sort=_default                               main field of the area
sort=area                                   by area name
sort=author.name                            field of the linked record
sort[]=status_id sort[]=title order[]=asc order[]=asc
```

### `order`

Direction for `sort`: `asc` (default as soon as `sort` is given) or `desc`. Without `sort`, the
command sorts by `changed_at desc` – the most recently changed first.

```cms
(CMS:SELECT list filter[area]=articles sort=title order=asc)
```

### `lang`

> Not yet implemented – the language of the call applies.

### `relation`

Hits are the records to which the link field of the source record points – without `src_id` those
of the current record. The order of the link field is given by `sort=_custom`; without `sort`, the
default sorting applies (`changed_at desc`):

```cms
(CMS:SELECT topic_badge relation=topics)
(CMS:SELECT gallery relation=images sort="_custom asc")
```

Several field names, comma-separated, count as OR; `ANY` means any link field.
`sort=_custom` and `nextof` require exactly one field name.

```cms
(CMS:SELECT teaser relation="topics,tags")
(CMS:SELECT teaser relation=any)
```

The reverse direction – records that point to the current one:

```cms
(CMS:SELECT product_list relation=rel_products)
(CMS:SELECT product_list filter[area]=products filter[category]="{$cms['record_id']}")
(CMS:SELECT reference_list relation=any dest_id="{$cms['record_id']}")
```

The first line applies to relation fields (`relation`): they have a counterpart field
`rel_{areaname}` in the target area. Pointer fields (`pointer`) have none – their counterpart is
the filter on the pointer field (second line). See
[Every record has a page](01-basics.md#the-concept-every-record-has-its-own-page).

### `src_id`

Source record for `relation`, if it is not the current record that is meant:

```cms
(CMS:SELECT topic_badge relation=topics src_id=1977)
(CMS:SELECT topic_badge relation=topics src_id="{$set['user_id']}")
```

An empty value or `0` also means the current record. If there is none – for example in a
standalone template without a record – the query finds nothing (and not all records of the area).

### `dest_id`

The reverse direction to `relation`: hits are the records that point to `dest_id` via the
pointer field – with `relation=any` via any of them – for example “Where is this image used?”:

```cms
(CMS:SELECT reference_list relation=any dest_id="{$cms['record_id']}")
(CMS:SELECT article_list relation=topics dest_id=1977)
```

With `dest_id`, the command does not use a current record as the source without `src_id`.
The same as a filter value, also for several targets: `filter[relation_any]="1977,1978"`.

### `nextof`

Only together with `relation`: instead of all linked records, the ***neighbors*** of the record
`nextof` in the same link list – the entries after it with `order=asc` (default), those before it
with `order=desc`, in each case in the order of the list (the sorting is thereby fixed, `sort` is
ignored). With `limit=1` this is the next or previous record – for example for “next”/“back” in an
image gallery whose images hang on article 2422:

```cms
(CMS:SELECT gallery_next relation=images src_id=2422 nextof="{$cms['record_id']}" limit=1)
(CMS:SELECT gallery_back relation=images src_id=2422 nextof="{$cms['record_id']}" order=desc limit=1)
```

If `nextof` is not in the list, there are no hits.

### `count`

Determine the total regardless of `limit`/`skip` – the basis for `CMS:JUMP_*` and for information
such as “hits 21–40 of 137”:

```cms
(CMS:SELECT list filter[area]=articles limit=20 skip="{$_REQUEST['skip']}" count=true)
```

In the `resulttemplate` `list` there are then two numbers: `$this->db->num_rows` are the hits of
***this page*** (at most `limit`), `$this->db->num_rows_unlimited` are ***all*** hits of the query:

```cms
(CMS:NUMBER useval="{$this->db->num_rows_unlimited}") hits
(CMS:RESULT_START) … (CMS:RESULT_END)
(CMS:JUMP_LIST)
```

Both numbers apply ***only in the `resulttemplate`***: after the `CMS:SELECT`, the surrounding
result is active again – the same rule that makes nested lists possible. If only the number is
needed, count in the calling template with [`hits=false`](#hits).

### `hits`

`hits=false` only counts (`count=true` is thereby included): no records are loaded and ***no***
`resulttemplate` is included – the name must nevertheless be given. The total is then available
***in the calling template*** in `$this->db->num_rows_unlimited`:

```cms
(CMS:SELECT x filter[area]=articles filter[topics]=42 hits=false)
(CMS:NUMBER useval="{$this->db->num_rows_unlimited}") articles on this topic
```

If the number is 0, `noresult` or `noresulttemplate` apply as usual:

```cms
(CMS:SELECT x filter[area]=articles filter[topics]=42 hits=false noresult="No articles on this topic yet.")
```

If the counting `CMS:SELECT` stands in a `resulttemplate`, the number there replaces the total of
the surrounding result; `$this->db->num_rows` and the running result loop remain untouched. A page
navigation ([CMS:JUMP_LIST](jump-list.md)) of the surrounding result then belongs ***before*** the
counting `CMS:SELECT`.

### `archive`

Include expired records (`offline_at` reached) – for example for an archive of past events:

```cms
(CMS:SELECT archive filter[area]=events archive=true sort=online_at order=desc)
```

An expired record remains reachable at its own address, by the way; it is only missing from lists
without `archive`.

### `future`

Include pre-dated records (`online_at` not yet reached) – for example a preview of upcoming posts:

```cms
(CMS:SELECT upcoming filter[area]=articles future=true filter[online_at] > now)
```

### `debug`

Output the SQL and bind values of the query (for development only):

```cms
(CMS:SELECT list filter[area]=articles debug=true)
```

### `sql`

Raw SQL for the WHERE clause – for support only; do not pass user input into it:

```cms
(CMS:SELECT list filter[area]=articles sql="AND `record`.`record_id` % 2 = 0")
```

### `set`

The ***entire output*** – the list template with all hits, for `noresult` its text – ends up in
`$set` instead of on the page. In this way, an address from a query can be reused in another command:

```cms
(CMS:SELECT url_only filter[area]=pages filter[identifier]=cart limit=1 set=cart_url)
<a class="cart" href="<?php echo $set['cart_url']; ?>">…</a>
(CMS:TEXT title prefix="<a href='{$set['cart_url']}'>" suffix="</a>")
```

The output of `debug=true` and PHP notices from the list template also end up in `$set` and not on
the page – to investigate, temporarily leave out `set`. If the page ends within the query (for
example through a success template), what has been generated up to that point is output normally.

### `state`

Without a value only released records (`status_id` 0). A status ID finds only records with exactly
this status; `any` removes the status filter:

```cms
(CMS:SELECT list filter[area]=articles state=2)
(CMS:SELECT list filter[area]=articles state=any)
```

### `request`

Takes filters, sorting and paging from the ***URL*** (GET or POST) – for search forms and
sortable lists, without writing every value into the command. You state what may be taken
over, as a comma list or an array:

```
request="sort,order,skip"                                  only sorting and paging
request="filter[title],filter[price],filter_op[price]"     search in title and price
request[]=sort request[]="filter[title]"                   the same as an array
```

Permitted are `skip`, `limit`, `sort`, `order` as well as `filter`, `filter_op` and
`filter_mode` ***only with a field name*** (`filter[title]`). A `filter` without a field
name and `all` are ignored and reported as errors – otherwise a visitor could search by any
field of the records found, including fields the page does not even show, such as an
internal note.

In the URL the keys are named as in the command, only `filterop` and `filtermode` without
the underscore: `?filter[title]=Hen*&sort=title&order=desc&skip=20`.

```cms
(CMS:SELECT list filter[area]=articles limit=20 count=true request="filter[title],sort,order,skip")
```

The URL can only ***narrow*** the query, never widen it:

* Values in the command take precedence. For a field the command filters on, `request`
  takes over neither values nor `filter_op`/`filter_mode` – `?filter[area]=internal` or
  `?filterop[area]=!=` change nothing about `filter[area]=articles`. Fields of a linked
  record count as the reference field (`author.name` belongs to `author`); `area` and
  `area_id` count as the same field.
* `skip`, `limit`, `sort` and `order` from the command replace the URL value completely.
  `limit` from the URL only counts as a positive number – “unlimited” cannot be requested.
* ***Encrypted fields*** only find the exact value; wildcards (`*`) and greater/less yield
  no hits.
* ***Password fields*** can never be filtered or sorted.

---

[← Overview](README.md)
