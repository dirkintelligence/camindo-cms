# CMS:FED

Erzeugt einen Verweis zum Bearbeiten eines Datensatzes, wenn das Frontend-Editing aktiviert ist.

```cmscode
(CMS:FED id [parameter=wert …])
```

***Methode:*** `Cms::cmsFed()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `id`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `label` | `Text` | `[»]` |  | Text, der verlinkt wird. |
| `id` ¹ | `Datensatz-Id oder Slug` | `(aktueller Datensatz)` |  | Datensatz, auf den verwiesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL. |
| `info` | `true / false` | `false` |  | true: Der Verweis öffnet die Detailansicht des Datensatzes statt der Eingabemaske. |
| `prefix` | `Text` | – |  | Text, der (unverlinkt) vor dem Verweis ausgegeben wird. |
| `suffix` | `Text` | – |  | Text, der (unverlinkt) nach dem Verweis ausgegeben wird. |
| `set` | `Array-Index von $set` | – |  | Der Verweis wird nicht ausgegeben, sondern in der Variable $set gespeichert. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Bearbeiten-Verweis ins Backend - nur sichtbar, wenn der Betrachter das
Frontend-Editing in seinen persönlichen Backend-Einstellungen eingeschaltet hat
(Cookie `fed`). Für Besucher ohne Cookie gibt der Befehl nichts aus, die Seite bleibt
unverändert:

```cms
<h1>(CMS:TEXT title) (CMS:FED)</h1>
```
Ausgabe (mit Cookie): `<h1>Hallo Welt <a href="#" data-c9-fed="1977" class="c9-fed"><span style="color:#f00">[&raquo;]</span></a></h1>` – den Klick wertet [`(CMS:FED_SCRIPT)`](fed-script.md) aus (einmal je Seite) und öffnet das Backend in einem neuen Tab, bei fester Backend-Domain unter deren Adresse.

In einer Liste je Zeile, mit eigenem Text:

```cms
(CMS:RESULT_START)
<li>(CMS:TEXT title) (CMS:FED label="✎" prefix=" " )</li>
(CMS:RESULT_END)
```

Das Backend verlangt weiterhin die Anmeldung - der Verweis öffnet bei Bedarf den
Anmeldedialog über der Eingabemaske.

## Parameter im Einzelnen

### `label`

Linktext (HTML erlaubt); Standard ist ein rotes `[»]` (`<span style="color:#f00">[&raquo;]</span>`):

```cms
(CMS:FED label="bearbeiten")
(CMS:FED label="<img src='/static/edit.svg' alt='bearbeiten'>")
```

### `id`

Anderer Datensatz; darf ohne Schlüssel stehen:

```cms
(CMS:FED 1977)
(CMS:FED id="{$cms['parent_id']}" label="Eltern bearbeiten")
```

### `info`

Detailansicht statt Eingabemaske öffnen:

```cms
(CMS:FED info=true label="Details")
```

### `prefix`

Unverlinkter Text vor dem Verweis - nur, wenn der Verweis ausgegeben wird:

```cms
(CMS:FED prefix="<span class=edit>" suffix="</span>")
```

### `suffix`

```cms
(CMS:FED suffix="</span>" prefix="<span class=edit>")
```

### `set`

```cms
(CMS:FED set=edit)
<?php if ($set['edit'] !== '') { ?><div class="toolbar"><?php echo $set['edit']; ?></div><?php } ?>
```

---

[← Übersicht](README.md)
