# CMS:CSP

Changes the script policy (Content Security Policy) of this page: additional script sources,
form targets or a different mode.

```cmscode
(CMS:CSP [parameter=value …])
```

***Method:*** `Cms::cmsCsp()`

## Parameters

| Parameter | Value | Default | Required | Description |
|---|---|---|:-:|---|
| `script` | `sources` | – |  | Additional script sources, separated by spaces or commas. |
| `form` | `sources` | – |  | Additional form targets (enables `form-action`). |
| `mode` | `off / report / enforce` | – |  | Mode for this page; empty: setting of the website. |

## Usage

For each website, a ***script policy*** can be switched on in the back end (instance,
section “Script policy (CSP)”). Then only scripts run that

* carry the ***nonce*** of the page (a random value for each request) – in the template,
  write `<script(CMS:NONCE)>` for this, see [CMS:NONCE](nonce.md), or
* are loaded from your own website or from the listed sources
  (`<script src="/js/app.js">`).

Code that gets into the page through content (a comment with `<script>`, an image with
`onerror`) carries no nonce and does not run. The default is “off”; “Report only”
lets everything run and writes violations to the log `csp` – so you can see what would
be blocked before you enforce the policy.

`CMS:CSP` adapts the policy for ***one*** page, for example the checkout with the script
of the payment provider:

```cms
(CMS:CSP script="https://js.stripe.com" form="https://checkout.stripe.com")
```

The command must come before the first output, ideally at the very top of the template; if it
comes later or `mode` is unknown, it stores an error message ([CMS:MESSAGE](message.md)).
Several calls collect sources, separated by spaces or commas. Host specifications are allowed
(`https://js.example.com`,
`*.example.com`, with port or path); keywords such as `'unsafe-inline'` and bare
schemes such as `https:` are discarded – they would override the policy.

***What the policy blocks and how to do it correctly:***

| blocked | instead |
|---|---|
| `<button onclick="…">`, `<form onsubmit="…">` | `data-…` attribute and `addEventListener` in a `<script>` of the template |
| `<a href="javascript:…">` | `<a href="#" data-…>` + listener |
| `<script>` without nonce | `<script(CMS:NONCE)>` |
| `echo '<script>…</script>'` in PHP | `(CMS:NONCE set=nonce)` and `echo '<script' . $set['nonce'] . '>'` – see [CMS:NONCE](nonce.md) |
| external scripts (tag managers, widgets) | enter the source in the instance or use `CMS:CSP script=…`; if a script loads further scripts, switch on “Allow scripts loaded by trusted scripts” |

When you save a template, the editor reports every `<script>` without `(CMS:NONCE)`,
every `on…=` attribute and every `javascript:` as a note; on the start page, the
widget “Template messages” collects them – also for templates that are edited in the
file system.

If you want a completely custom policy (also for styles, images, connections), send it with
[CMS:HEADER](header.md) and get the nonce with `(CMS:NONCE format=value)`.

## Parameters in detail

### `script`

```cms
(CMS:CSP script="https://maps.example.com https://cdn.example.com")
```

### `form`

```cms
(CMS:CSP form="https://www.paypal.com")
```

### `mode`

Temporarily exempt a page with an old widget or let it only report:

```cms
(CMS:CSP mode=report)
(CMS:CSP mode=off)
```

---

[← Overview](README.md)
