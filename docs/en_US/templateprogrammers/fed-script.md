# CMS:FED_SCRIPT

Provides the script for the edit links from [CMS:FED](fed.md) – once per
page.

```cmscode
(CMS:FED_SCRIPT [set])
```

***Method:*** `Cms::cmsFedScript()`  
***Without key:*** the first value without `=` is the `set`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `set` ¹ | `array index of $set` | – |  | The script block is not output, but stored in the variable $set. |

¹ can be given without a key as the first value.

## Usage

The links from `CMS:FED` contain no target and no JavaScript code. The click is
handled by the script from `CMS:FED_SCRIPT`, which opens the input mask (or the detail view
with `info=true`) in the back end in a new tab – or at its address if a fixed
back-end domain is set. Without `CMS:FED_SCRIPT` on the page, clicking the link has no effect.

Write the command once into a template that every page includes, usually
in the footer before `</body>`:

```cms
(CMS:FED_SCRIPT)
</body>
</html>
```

The position relative to the `CMS:FED` links does not matter. Like `CMS:FED`, the
command outputs something only if the visitor has switched on ***frontend editing***;
for other visitors, the page remains unchanged. If the command appears several times on a
page (for example via several `CMS:INCLUDE`), the script still appears only once.

The `<script>` tag carries the ***nonce*** of the page and therefore also runs under an
enforced script policy (see [CMS:CSP](csp.md)).

## Parameters in detail

### `set`

`set` outputs nothing, but stores the script block under `$set['fed']`, for example to
output it at a different place:

```cms
(CMS:FED_SCRIPT set=fed)
<?php echo $set['fed']; ?>
```

---

[← Overview](README.md)
