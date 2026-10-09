# CMS:FED

Creates a link for editing a record when frontend editing is enabled.

```cmscode
(CMS:FED id [parameter=value …])
```

***Method:*** `Cms::cmsFed()`  
***Without key:*** the first value without `=` is the `id`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `label` | `text` | `[»]` |  | Text that is linked. |
| `id` ¹ | `record ID or slug` | `(current record)` |  | Record the link points to. Pure digit sequence = record_id, anything else = slug – the same rule as in the URL. |
| `info` | `true / false` | `false` |  | true: The link opens the detail view of the record instead of the input mask. |
| `prefix` | `text` | – |  | Text that is output before the link (not linked). |
| `suffix` | `text` | – |  | Text that is output after the link (not linked). |
| `set` | `array index of $set` | – |  | The link is not output, but stored in the variable $set. |

¹ can be given without a key as the first value.

## Usage

Edit link into the back end – only visible if the visitor has switched on the
frontend editing in their personal back-end settings (cookie `fed`). For visitors without
the cookie, the command outputs nothing, and the page remains unchanged:

```cms
<h1>(CMS:TEXT title) (CMS:FED)</h1>
```
Output (with cookie): `<h1>Hello world <a href="#" data-c9-fed="1977" class="c9-fed"><span style="color:#f00">[&raquo;]</span></a></h1>` – the click is handled by [`(CMS:FED_SCRIPT)`](fed-script.md) (once per page), which opens the back end in a new tab, or at its address if a fixed back-end domain is set.

In a list, one link per row, with its own text:

```cms
(CMS:RESULT_START)
<li>(CMS:TEXT title) (CMS:FED label="✎" prefix=" " )</li>
(CMS:RESULT_END)
```

The back end still requires sign-in – if necessary, the link opens the sign-in
dialog above the input mask.

## Parameters in detail

### `label`

Link text (HTML allowed); the default is a red `[»]` (`<span style="color:#f00">[&raquo;]</span>`):

```cms
(CMS:FED label="edit")
(CMS:FED label="<img src='/static/edit.svg' alt='edit'>")
```

### `id`

Another record; may be given without a key:

```cms
(CMS:FED 1977)
(CMS:FED id="{$cms['parent_id']}" label="Edit parent")
```

### `info`

Open the detail view instead of the input mask:

```cms
(CMS:FED info=true label="Details")
```

### `prefix`

Unlinked text before the link – only output if the link is output:

```cms
(CMS:FED prefix="<span class=edit>" suffix="</span>")
```

### `suffix`

```cms
(CMS:FED suffix="</span>" prefix="<span class=edit>")
```

### `set`

```cms
(CMS:FED set=edit)
<?php if ($set['edit'] !== '') { ?><div class="toolbar"><?php echo $set['edit']; ?></div><?php } ?>
```

---

[← Overview](README.md)
