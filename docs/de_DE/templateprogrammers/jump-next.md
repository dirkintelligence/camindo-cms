# CMS:JUMP_NEXT

Erzeugt einen Weiterblättern-Verweis.

```cmscode
(CMS:JUMP_NEXT tpl_link [parameter=wert …])
```

***Methode:*** `Pagination::cmsJumpNext()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `tpl_link`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `skip` | `Zahl >= 0` | `$_REQUEST[skip]` |  | Versatz der aktuellen Seite (Anzahl übersprungener Ergebnisse). Ohne Angabe gilt der Request-Parameter skip. |
| `tpl_link` ¹ | `HTML-Vorlage` | `<a href="{href}"><strong>❯❯</strong></a>` |  | Vorlage für den Verweis, solange es eine nächste Seite gibt. {href} wird durch die Ziel-URL ersetzt. Die URL ist HTML-maskiert (`&amp;` statt `&`), `'` und `\` sind prozentkodiert – {href} kann direkt in einem Attribut stehen. |
| `tpl_inactive` | `HTML-Vorlage` | `<span>❯❯</span>` |  | Vorlage für den nicht anwählbaren Verweis, wenn es keine nächste Seite gibt. {href} wird ebenfalls ersetzt. |
| `replace` | `Text?Text` | – |  | Einfache Textersetzung im fertigen HTML. Trenner ist das Fragezeichen: "suchen?ersetzen". |
| `prefix` | `Text` | – |  | Text vor dem Verweis, nur wenn ein Verweis ausgegeben wird. |
| `suffix` | `Text` | – |  | Text nach dem Verweis, nur wenn ein Verweis ausgegeben wird. |
| `ifempty` | `Text` | – |  | Ausgabe, wenn das CMS:SELECT kein limit hat (dann gibt es keine Seiten). Passt alles auf eine Seite, erscheint `tpl_inactive`. |
| `set` | `Array-Index von $set` | – |  | Der Verweis wird nicht ausgegeben, sondern in der Variable $set gespeichert. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Verweis auf die nächste Seite einer blätterbaren Liste (`CMS:SELECT` mit `count=true`
und `limit`). Der Befehl steht wie [CMS:JUMP_LIST](jump-list.md) im `resulttemplate`
der Abfrage, hier bei `limit=20`:

```cms
(CMS:JUMP_PREV) Seite <?php echo intdiv((int)($_REQUEST['skip'] ?? 0), 20) + 1; ?> (CMS:JUMP_NEXT)
```
Ausgabe: `❮❮ Seite 3 ❯❯`

Gibt es keine nächste Seite – auch wenn alles auf eine Seite passt –, erscheint
`tpl_inactive` (Standard: das Symbol ohne Link). Die Seitengröße ist das `limit` des
`CMS:SELECT`; ohne `limit` erscheint nur `ifempty`.

## Parameter im Einzelnen

### `skip`

```cms
(CMS:JUMP_NEXT skip="{$offset}")
```

### `tpl_link`

Vorlage mit Link; `{href}` wird ersetzt. Darf ohne Schlüssel stehen:

```cms
(CMS:JUMP_NEXT "<a class=next href='{href}'>weiter ›</a>")
```

### `tpl_inactive`

Vorlage ohne nächste Seite (`{href}` wird auch hier ersetzt – mit der Adresse, die die
nächste Seite hätte):

```cms
(CMS:JUMP_NEXT tpl_link="<a href='{href}'>weiter ›</a>" tpl_inactive="<span class=disabled>weiter ›</span>")
```

### `replace`

```cms
(CMS:JUMP_NEXT replace="❯❯?→")
```

### `prefix`

```cms
(CMS:JUMP_NEXT prefix="<li class=next>" suffix="</li>")
```

### `suffix`

```cms
(CMS:JUMP_NEXT suffix="</li>" prefix="<li>")
```

### `ifempty`

```cms
(CMS:JUMP_NEXT ifempty="")
```
Greift nur, wenn das `CMS:SELECT` kein `limit` hat; Standard ist leer.

### `set`

```cms
(CMS:JUMP_NEXT set=next)
```

---

[← Übersicht](README.md)
