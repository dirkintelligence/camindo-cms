# CMS:NONCE

Gibt die Nonce der Seite aus – für jedes Script im Template.

```cmscode
(CMS:NONCE [parameter=wert …])
```

***Methode:*** `Cms::cmsNonce()`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `format` | `"attribute" / "value"` | `"attribute"` |  | attribute: ` nonce="…"` (mit führendem Leerzeichen, für ein `<script>`-Tag). value: nur der Wert. |
| `set` | `Array-Index von $set` | – |  | Die Ausgabe wird nicht ausgegeben, sondern in `$set` gespeichert. |

## Anwendung

Ist die ***Script-Policy*** der Website durchgesetzt (siehe [CMS:CSP](csp.md)), laufen nur
Scripts mit der Nonce der Seite – einem Zufallswert, der mit jedem Aufruf wechselt.
Schreiben Sie deshalb ***jedes*** Script im Template so:

```cms
<script(CMS:NONCE)>
document.querySelectorAll('[data-confirm]').forEach(function (form) {
  form.addEventListener('submit', function (ev) {
    if (!confirm(form.dataset.confirm)) ev.preventDefault();
  });
});
</script>
```

Auch eingebundene Dateien bekommen sie, wenn sie nicht von der eigenen Website kommen:

```cms
<script src="https://cdn.example.com/lib.js"(CMS:NONCE)></script>
```

camindo CMS setzt die Nonce ***nicht*** selbst ein. Fehlt sie, meldet der Template-Editor
beim Speichern einen Hinweis („script without (CMS:NONCE)“), ebenso das Widget
„Template-Meldungen“ auf der Startseite. Solange die Script-Policy aus ist, schadet das
Attribut nicht – schreiben Sie es trotzdem gleich mit, dann lässt sich die Policy später
einschalten, ohne die Templates zu ändern.

`onclick="…"`, `onsubmit="…"` und `href="javascript:…"` laufen unter der Policy gar nicht,
auch nicht mit Nonce: verlegen Sie den Code in ein `<script(CMS:NONCE)>` mit
`addEventListener` (Beispiel oben).

Erzeugt PHP das Tag, holen Sie die Nonce mit `set`:

```cms
(CMS:NONCE set=nonce)
<?php echo '<script' . $set['nonce'] . '>var shop = ' . json_encode($config) . ';</script>'; ?>
```

Für eine eigene Policy per [CMS:HEADER](header.md):

```cms
(CMS:NONCE format=value set=nonce)
(CMS:HEADER "Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$set['nonce']}'")
```

Eine Seite, die ein Proxy oder Cache mit fester Nonce ausliefert, schützt nicht mehr –
Seiten mit Script-Policy nicht als Ganzes cachen.

## Parameter im Einzelnen

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

[← Übersicht](README.md)
