# CMS:JUMP_PREV

Erzeugt einen Zurückblättern-Verweis.

```cmscode
(CMS:JUMP_PREV tpl_link [parameter=wert …])
```

***Methode:*** `Pagination::cmsJumpPrev()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `tpl_link`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `skip` | `Zahl >= 0` | `$_REQUEST[skip]` |  | Versatz der aktuellen Seite (Anzahl übersprungener Ergebnisse). Ohne Angabe gilt der Request-Parameter skip. |
| `tpl_link` ¹ | `HTML-Vorlage` | `<a href="{href}"><strong>❮❮</strong></a>` |  | Vorlage für den Verweis, solange es eine vorherige Seite gibt. {href} wird durch die Ziel-URL ersetzt. Die URL ist HTML-maskiert (`&amp;` statt `&`), `'` und `\` sind prozentkodiert – {href} kann direkt in einem Attribut stehen. |
| `tpl_inactive` | `HTML-Vorlage` | `<span>❮❮</span>` |  | Vorlage für den nicht anwählbaren Verweis, wenn es keine vorherige Seite gibt. {href} wird ebenfalls ersetzt. |
| `replace` | `Text?Text` | – |  | Einfache Textersetzung im fertigen HTML. Trenner ist das Fragezeichen: "suchen?ersetzen". |
| `prefix` | `Text` | – |  | Text vor dem Verweis, nur wenn ein Verweis ausgegeben wird. |
| `suffix` | `Text` | – |  | Text nach dem Verweis, nur wenn ein Verweis ausgegeben wird. |
| `ifempty` | `Text` | – |  | Ausgabe, wenn das CMS:SELECT kein limit hat (dann gibt es keine Seiten). Passt alles auf eine Seite, erscheint `tpl_inactive`. |
| `set` | `Array-Index von $set` | – |  | Der Verweis wird nicht ausgegeben, sondern in der Variable $set gespeichert. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Verweis auf die vorherige Seite - Gegenstück zu `CMS:JUMP_NEXT`, wie dieses im
`resulttemplate` eines `CMS:SELECT` mit `limit` (das `limit` ist die Seitengröße):

```cms
<nav>(CMS:JUMP_PREV "<a href='{href}'>‹ zurück</a>" tpl_inactive="") (CMS:JUMP_NEXT "<a href='{href}'>weiter ›</a>" tpl_inactive="")</nav>
```

Auf der ersten Seite erscheint `tpl_inactive` (hier leer), sonst der Link – auch wenn alles
auf eine Seite passt.

## Parameter im Einzelnen

### `skip`

```cms
(CMS:JUMP_PREV skip="{$offset}")
```

### `tpl_link`

```cms
(CMS:JUMP_PREV "<a class=prev href='{href}'>‹ zurück</a>")
```

### `tpl_inactive`

```cms
(CMS:JUMP_PREV tpl_inactive="<span class=disabled>‹ zurück</span>")
```

### `replace`

```cms
(CMS:JUMP_PREV replace="❮❮?←")
```

### `prefix`

```cms
(CMS:JUMP_PREV prefix="<li class=prev>" suffix="</li>")
```

### `suffix`

```cms
(CMS:JUMP_PREV suffix="</li>" prefix="<li>")
```

### `ifempty`

```cms
(CMS:JUMP_PREV ifempty="")
```

Greift nur, wenn das `CMS:SELECT` kein `limit` hat; Standard ist leer.

### `set`

```cms
(CMS:JUMP_PREV set=prev)
```

---

[← Übersicht](README.md)
