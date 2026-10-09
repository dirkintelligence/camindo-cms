# CMS:FED_CODE

Blendet HTML-Code ein, wenn das Frontend-Editing aktiviert ist.

```cmscode
(CMS:FED_CODE code [parameter=wert …])
```

***Methode:*** `Cms::cmsFedCode()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `code`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `code` ¹ | `HTML-Code` | – |  | HTML-Code, der ausgegeben werden soll, wenn das Frontend-Editing eingeschaltet ist. |
| `instead` | `HTML-Code` | – |  | Alternative Ausgabe, wenn das Frontend-Editing ausgeschaltet ist. |
| `set` | `Array-Index von $set` | – |  | Der Code wird nicht ausgegeben, sondern in der Variable $set gespeichert. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Beliebiges HTML, das nur bei eingeschaltetem Frontend-Editing erscheint - etwa eine
Werkzeugleiste oder ein Hinweis auf den Bearbeitungsmodus:

```cms
(CMS:FED_CODE "<div class=fed-bar>Bearbeitungsmodus – <a href='/backend/'>Backend öffnen</a></div>")
```

Mit `instead` eine Alternative für Besucher ohne Frontend-Editing:

```cms
<body class="(CMS:FED_CODE code="editing" instead="public")">
```

## Parameter im Einzelnen

### `code`

HTML bei eingeschaltetem Frontend-Editing; darf ohne Schlüssel stehen:

```cms
(CMS:FED_CODE code="<link rel=stylesheet href='/static/fed.css'>")
(CMS:FED_CODE "<link rel=stylesheet href='/static/fed.css'>")
```

### `instead`

Ausgabe für Besucher **ohne** Frontend-Editing:

```cms
(CMS:FED_CODE code="<p>Sie sehen die Redaktionsansicht.</p>" instead="")
```

### `set`

```cms
(CMS:FED_CODE code=1 instead=0 set=fed)
<?php if ($set['fed']) { ?> … <?php } ?>
```

---

[← Übersicht](README.md)
