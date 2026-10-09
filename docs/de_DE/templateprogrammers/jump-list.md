# CMS:JUMP_LIST

Erzeugt eine Blätter-Navigation (Pagination), z. B. 1 … 5 | 6 | 7 | 8 | 9 … 15.

```cmscode
(CMS:JUMP_LIST separator [parameter=wert …])
```

***Methode:*** `Pagination::cmsJumpList()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `separator`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `skip` | `Zahl >= 0` | `$_REQUEST[skip]` |  | Versatz der aktuellen Seite (Anzahl übersprungener Ergebnisse). Ohne Angabe gilt der Request-Parameter skip. |
| `maximum` | `Zahl >= 1` | `7` |  | Anzahl der gleichzeitig angezeigten (mitwandernden) Seitenverweise, ohne die Verweise auf erste und letzte Seite. |
| `boundaries` | `true / false` | `true` |  | true: Verweise auf die erste und die letzte Seite werden immer angezeigt, sobald sie außerhalb des mitwandernden Bereichs liegen. |
| `separator` ¹ | `Trennzeichen` | (Leerzeichen) |  | Text zwischen zwei Seitenverweisen. Vor und hinter der Auslassung ('ellipsis') wird er nicht eingefügt. |
| `tpl_link` | `HTML-Vorlage` | `<a href="{href}">{page}</a>` |  | Vorlage für einen Seitenverweis. {page} wird durch die Seitennummer ersetzt, {href} durch die Seiten-URL. Die URL ist HTML-maskiert (`&amp;` statt `&`), `'` und `\` sind prozentkodiert – {href} kann direkt in einem Attribut stehen. |
| `tpl_active` | `HTML-Vorlage` | `<strong>{page}</strong>` |  | Vorlage für die aktuelle Seite. {page} wird durch die Seitennummer ersetzt, {href} durch die Seiten-URL. |
| `ellipsis` | `Code` | `…` |  | Platzhalter für ausgelassene Seiten - erscheint zwischen einem Boundary-Verweis und dem mitwandernden Bereich. |
| `replace` | `Text?Text` | – |  | Einfache Textersetzung im fertigen HTML. Trenner ist das Fragezeichen: "suchen?ersetzen". |
| `prefix` | `Text` | – |  | Text vor der Navigation, nur wenn eine Navigation ausgegeben wird. |
| `suffix` | `Text` | – |  | Text nach der Navigation, nur wenn eine Navigation ausgegeben wird. |
| `ifempty` | `Text` | – |  | Ausgabe, wenn das Ergebnis auf eine einzige Seite passt oder das CMS:SELECT kein limit hat und deshalb keine Navigation nötig ist. |
| `set` | `Array-Index von $set` | – |  | Die Navigation wird nicht ausgegeben, sondern in der Variable $set gespeichert. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Blätterleiste zu einem `CMS:SELECT` mit `count=true`. Die Seitengröße ist das `limit`
der Abfrage; ***ohne*** `limit` gibt es keine Seiten und der Befehl gibt nur `ifempty`
aus. Der aktuelle Versatz kommt aus `$_REQUEST['skip']`.

Der Befehl steht im ***`resulttemplate`*** des `CMS:SELECT` (vor oder hinter der
Ergebnisschleife): Nur dort gelten Trefferzahl und `limit` dieser Abfrage – hinter dem
`CMS:SELECT` im aufrufenden Template ist wieder das äußere Ergebnis aktiv.

```cms
(CMS:SELECT liste filter[area]=artikel limit=20 skip="{$_REQUEST['skip']}" count=true)
```

Template `liste`:

```cms
(CMS:RESULT_START)
  <h2>(CMS:TEXT titel)</h2>
(CMS:RESULT_END)
<nav class="pager">
(CMS:JUMP_PREV) (CMS:JUMP_LIST separator=" | ") (CMS:JUMP_NEXT)
</nav>
```
Ausgabe: `❮❮ 1 … 5 | 6 | 7 | 8 | 9 … 15 ❯❯`

Die Links behalten alle übrigen Parameter der aktuellen URL (Suchbegriff, Filter) und
setzen nur `skip` neu. Passt das Ergebnis auf eine Seite, wird nichts ausgegeben (bzw.
`ifempty`).

Bootstrap-artige Leiste:

```cms
(CMS:JUMP_LIST
  prefix="<ul class=pagination>" suffix="</ul>"
  tpl_link="<li><a href='{href}'>{page}</a></li>"
  tpl_active="<li class=active><span>{page}</span></li>"
  ellipsis="<li class=disabled><span>…</span></li>"
  separator=""
)
```

## Parameter im Einzelnen

### `skip`

Versatz der aktuellen Seite, falls er nicht aus `$_REQUEST['skip']` kommt:

```cms
(CMS:JUMP_LIST skip="{$offset}")
```

### `maximum`

Anzahl der mitwandernden Seitenverweise (ohne erste/letzte Seite):

```cms
(CMS:JUMP_LIST maximum=5)
```
Ausgabe auf Seite 7 von 15: `1 … 5 6 7 8 9 … 15`

### `boundaries`

Verweise auf erste und letzte Seite unterdrücken:

```cms
(CMS:JUMP_LIST boundaries=false)
```
Ausgabe: `5 6 7 8 9` statt `1 … 5 6 7 8 9 … 15`

### `separator`

Text zwischen zwei Seitenverweisen; darf ohne Schlüssel stehen:

```cms
(CMS:JUMP_LIST " | ")
(CMS:JUMP_LIST separator=" · ")
```

### `tpl_link`

Vorlage je Seitenverweis; `{href}` und `{page}` werden ersetzt:

```cms
(CMS:JUMP_LIST tpl_link="<a class=page href='{href}'>{page}</a>")
```

### `tpl_active`

Vorlage für die aktuelle Seite:

```cms
(CMS:JUMP_LIST tpl_active="<span class='page current'>{page}</span>")
```

### `ellipsis`

Platzhalter für ausgelassene Seiten (zwischen erster/letzter Seite und dem mitwandernden Bereich):

```cms
(CMS:JUMP_LIST ellipsis=" ··· ")
```

### `replace`

Einfache Ersetzung im fertigen HTML, Trenner `?` (nur ein Paar):

```cms
(CMS:JUMP_LIST replace="<strong>?<strong class=current>")
```

### `prefix`

Erscheint nur, wenn eine Leiste ausgegeben wird:

```cms
(CMS:JUMP_LIST prefix="<nav class=pager>" suffix="</nav>")
```

### `suffix`

```cms
(CMS:JUMP_LIST suffix="</nav>" prefix="<nav>")
```

### `ifempty`

Ausgabe, wenn alles auf eine Seite passt (oder das `CMS:SELECT` kein `limit` hat):

```cms
(CMS:JUMP_LIST ifempty="<span class=muted>alle Einträge</span>")
```

### `set`

```cms
(CMS:JUMP_LIST set=pager)
<?php if ($set['pager'] !== '') { ?><nav><?php echo $set['pager']; ?></nav><?php } ?>
```

---

[← Übersicht](README.md)
