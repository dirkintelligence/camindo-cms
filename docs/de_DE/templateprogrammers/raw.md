# CMS:RAW

Gibt einen Inhalt unformatiert aus.

```cmscode
(CMS:RAW field [parameter=wert …])
```

***Methode:*** `Cms::cmsRaw()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `field`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `id` | `Datensatz-Id oder Slug` | `(aktueller Datensatz)` |  | Datensatz, aus dem gelesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL. |
| `field` ¹ | `Feld` | – | ja | Name des Feldes, dessen Inhalt ausgegeben wird. |
| `ifempty` | `Text` | – |  | Text, der ausgegeben wird, falls das Feld leer ist. |
| `instead` | `Text` | – |  | Text, der anstelle des Feldinhalts ausgegeben wird, sofern dieser nicht leer ist. |
| `prefix` | `Text` | – |  | Text, der vorangestellt wird, sofern der Feldinhalt nicht leer ist. |
| `suffix` | `Text` | – |  | Text, der angehängt wird, sofern der Feldinhalt nicht leer ist. |
| `set` | `Array-Index von $set` | – |  | Der Inhalt wird nicht ausgegeben, sondern in der Variable $set gespeichert. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Gibt den Feldinhalt ***ohne jede Aufbereitung*** aus - kein Maskieren, keine Umwandlung
von Zeilenumbrüchen, keine Entities. Gedacht für Felder, die fertiges HTML enthalten
(Editor-Felder) oder für Werte, die unverändert in PHP/JavaScript landen sollen:

```cms
<div class="content">(CMS:RAW html_text)</div>
```

Dem Inhalt muss man vertrauen: Was ein Redakteur ins Feld schreibt, landet unverändert
auf der Seite – bis auf aktive Inhalte (`<script>`, `<iframe>`, Event-Handler,
`javascript:`-Links), die camindo CMS schon beim Speichern und beim Lesen aus der
Datenbank entfernt. Für Texte aus Benutzereingaben (`ugc`-Bereiche) `CMS:TEXT` mit
`specialchars=true` verwenden.

Die Ausgabeparameter `ifempty`, `instead`, `prefix`, `suffix` und `set` wirken wie bei
allen Ausgabebefehlen.

## Parameter im Einzelnen

### `id`

`id` liest aus einem anderen Datensatz statt aus dem laufenden - record_id oder Slug,
genau wie in der URL:

```cms
(CMS:RAW html_text id=1977)
(CMS:RAW html_text id=impressum)
```

### `field`

`field` ist der Hauptparameter und darf ohne Schlüssel stehen - beide Zeilen sind gleich:

```cms
(CMS:RAW field=html_text)
(CMS:RAW html_text)
```

### `ifempty`

`ifempty` ersetzt die **gesamte** Ausgabe, wenn die Quelle leer ist; `prefix` und
`suffix` entfallen dann:

```cms
(CMS:RAW html_text prefix="<p>" suffix="</p>" ifempty="<p>Kein Inhalt.</p>")
```

### `instead`

`instead` tauscht den Wert aus, sobald die Quelle **nicht** leer ist - nützlich, wenn
nur das Vorhandensein zählt:

```cms
(CMS:RAW html_text instead="vorhanden" ifempty="-")
```

### `prefix`

`prefix` / `suffix` umschließen den Wert und erscheinen nur, wenn er nicht leer ist -
so entsteht kein leeres `<section></section>`:

```cms
(CMS:RAW html_text prefix="<section>" suffix="</section>")
```

### `suffix`

```cms
(CMS:RAW html_text suffix="</section>")
```

Siehe `prefix`.

### `set`

`set` gibt nichts aus, sondern legt das Ergebnis unter `$set['html']` für das
umgebende PHP oder einen späteren Befehl ab:

```cms
(CMS:RAW html_text set=html)
<?php if ($set['html'] !== '') { ?> … <?php echo $set['html']; ?> … <?php } ?>
```

---

[← Übersicht](README.md)
