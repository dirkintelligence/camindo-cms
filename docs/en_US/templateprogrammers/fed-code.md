# CMS:FED_CODE

Outputs HTML code when frontend editing is enabled.

```cmscode
(CMS:FED_CODE code [parameter=value …])
```

***Method:*** `Cms::cmsFedCode()`  
***Without key:*** the first value without `=` is the `code`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `code` ¹ | `HTML code` | – |  | HTML code that is output when frontend editing is enabled. |
| `instead` | `HTML code` | – |  | Alternative output when frontend editing is disabled. |
| `set` | `array index of $set` | – |  | The code is not output, but stored in the variable $set. |

¹ can be given without a key as the first value.

## Usage

Any HTML that appears only when frontend editing is enabled – for example a
toolbar or a hint about the editing mode:

```cms
(CMS:FED_CODE "<div class=fed-bar>Editing mode – <a href='/backend/'>Open back end</a></div>")
```

With `instead`, an alternative for visitors without frontend editing:

```cms
<body class="(CMS:FED_CODE code="editing" instead="public")">
```

## Parameters in detail

### `code`

HTML when frontend editing is enabled; may be given without a key:

```cms
(CMS:FED_CODE code="<link rel=stylesheet href='/static/fed.css'>")
(CMS:FED_CODE "<link rel=stylesheet href='/static/fed.css'>")
```

### `instead`

Output for visitors **without** frontend editing:

```cms
(CMS:FED_CODE code="<p>You see the editorial view.</p>" instead="")
```

### `set`

```cms
(CMS:FED_CODE code=1 instead=0 set=fed)
<?php if ($set['fed']) { ?> … <?php } ?>
```

---

[← Overview](README.md)
