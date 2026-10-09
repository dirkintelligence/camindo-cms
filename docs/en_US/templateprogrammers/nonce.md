# CMS:NONCE

Outputs the nonce of the page – for every script in the template.

```cmscode
(CMS:NONCE [parameter=value …])
```

***Method:*** `Cms::cmsNonce()`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `format` | `"attribute" / "value"` | `"attribute"` |  | attribute: ` nonce="…"` (with a leading space, for a `<script>` tag). value: only the value. |
| `set` | `array index of $set` | – |  | The output is not output, but stored in `$set`. |

## Usage

If the ***script policy*** of the website is enforced (see [CMS:CSP](csp.md)), only scripts
with the nonce of the page run – a random value that changes with every request.
Therefore, write ***every*** script in the template like this:

```cms
<script(CMS:NONCE)>
document.querySelectorAll('[data-confirm]').forEach(function (form) {
  form.addEventListener('submit', function (ev) {
    if (!confirm(form.dataset.confirm)) ev.preventDefault();
  });
});
</script>
```

Included files get it as well if they do not come from your own website:

```cms
<script src="https://cdn.example.com/lib.js"(CMS:NONCE)></script>
```

camindo CMS does ***not*** add the nonce by itself. If it is missing, the template editor
reports a note when you save (“script without (CMS:NONCE)”), as does the widget
“Template messages” on the start page. As long as the script policy is off, the
attribute does no harm – nevertheless, add it right away, so the policy can be switched on
later without changing the templates.

`onclick="…"`, `onsubmit="…"` and `href="javascript:…"` do not run under the policy at all,
not even with a nonce: move the code into a `<script(CMS:NONCE)>` with
`addEventListener` (example above).

If PHP generates the tag, get the nonce with `set`:

```cms
(CMS:NONCE set=nonce)
<?php echo '<script' . $set['nonce'] . '>var shop = ' . json_encode($config) . ';</script>'; ?>
```

For a custom policy via [CMS:HEADER](header.md):

```cms
(CMS:NONCE format=value set=nonce)
(CMS:HEADER "Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$set['nonce']}'")
```

A page that a proxy or cache delivers with a fixed nonce no longer offers protection –
do not cache pages with a script policy as a whole.

## Parameters in detail

### `format`

```cms
<script(CMS:NONCE)>…</script>
(CMS:NONCE format=value)
```

### `set`

```cms
(CMS:NONCE set=nonce)
```

---

[← Overview](README.md)
