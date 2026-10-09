# CMS:USER_BASKET

Edits a shopping basket.

```cmscode
(CMS:USER_BASKET function [parameter=value …])
```

***Method:*** `Modules\Record\RecordUser::cmsBasket()`  
***Without a key:*** the first value without `=` counts as `function`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `function` ¹ | `"load" / "add" / "update" / "update_addon"` | `"load"` | yes | Function to be executed. |
| `overwrite` | `false / true` | `false` |  | function=add + overwrite=true: The number of items already saved is updated when adding. |
| `category` | `category ID` | `0` |  | Shopping basket category. |
| `container` | `container IDs or names, comma-separated` | – |  | Only records from these containers get into the basket. Without specification: published records from containers of type “record” or “ugc” (never profiles). |
| `variable` | `variable name` | – |  | Name of the variable in which the item IDs are stored as an array. |
| `duration` | `number of days` | `30` |  | Number of days the basket stays saved, provided no changes are made. |
| `basket_addon` | `text` | – |  | Text (serialized data) to be saved. |
| `csrf` | `true / false` | `true` |  | true: The request must contain a valid token from [CMS:USER_TOKEN](user-token.md) (protection against CSRF). false switches off the check for this command – only with good reason. |

¹ can be given without a key as the first value.

## Usage

Shopping basket per frontend session – it also works without signing in (anonymous session).
Result in `$set`:

| `$set[…]` | Content |
|---|---|
| `basket` | `record_id => quantity` |
| `basket_count` | total of the quantities |
| `basket_addon` | text of the category saved with `update_addon` |

Add items (form with `items[]`), display the shopping basket:

```cms
<?php $f = $_POST['f'] ?? 'load'; ?>
(CMS:USER_BASKET function="$f" variable=items)
(CMS:USER_BASKET load)
<h2>Shopping basket (<?php echo (int)$set['basket_count']; ?> pieces)</h2>
<form method="post"><input type="hidden" name="f" value="update">
  (CMS:USER_TOKEN)
(CMS:RESULT_START)
  <p>(CMS:TEXT title): <input name="items[(CMS:NUMBER record_id)]" value="<?php echo (int)$set['basket'][$cms['record_id']]; ?>" size="3"></p>
(CMS:RESULT_END)
<button>Update quantities (0 = remove)</button>
</form>
```

Only after `load` are the items available as a result set – `CMS:RESULT_START` runs over them just
as after a `CMS:SELECT`. After `add`, `update` and `update_addon`, `$set` is up to date, so the
list needs a second call with `load` (example above). Without a valid token from
`CMS:USER_TOKEN`, a writing function changes nothing. An “Add to basket” form in a list of items:

```cms
<form method="post" action="/0,u_basket">
  (CMS:USER_TOKEN)
  <input type="hidden" name="f" value="add">
  <button name="items[]" value="(CMS:TEXT record_id)">Add to basket</button>
</form>
```

## Parameters in detail

### `function`

May stand without a key:

```cms
(CMS:USER_BASKET load)                       read the content, items as result set
(CMS:USER_BASKET add variable=items)         add the IDs from $_REQUEST['items']
(CMS:USER_BASKET update variable=items)      set quantities (0 removes)
(CMS:USER_BASKET update_addon basket_addon="{$note}")
```

### `overwrite`

With `add`: **set** the quantity instead of adding to it:

```cms
(CMS:USER_BASKET add variable=items overwrite=true)
```

With `items[5]=2`, item 5 is then in the basket exactly twice, even if it was already in it.

### `category`

Several baskets per session, e.g. a wish list next to the shopping basket:

```cms
(CMS:USER_BASKET add variable=items category=1)
(CMS:USER_BASKET load category=1)
```

### `container`

Restricts the basket to certain content areas (names or IDs, comma-separated). Without a
specification, published records from areas of type “record” or “ugc” are allowed, never
profiles. The check also applies when reading: items outside these areas appear neither in
`$set['basket']` nor in the result set.

```cms
(CMS:USER_BASKET add variable=items container=products,accessories)
(CMS:USER_BASKET load container=products,accessories)
```

### `variable`

Name of the request parameter with the item IDs – as a list (`items[]=5`, quantity 1 per
mention) or with quantities (`items[5]=2`):

```cms
<input type="hidden" name="cart[<?php echo $id; ?>]" value="1">
(CMS:USER_BASKET add variable=cart)
```

### `duration`

Days the basket is kept without changes (default 30):

```cms
(CMS:USER_BASKET add variable=items duration=7)
```

### `basket_addon`

Store free text for the category (delivery note, voucher code, serialized data):

```cms
(CMS:USER_BASKET update_addon basket_addon="{$_POST['note']}")
<?php echo htmlspecialchars($set['basket_addon']); ?>
```

---

[← Overview](README.md)
