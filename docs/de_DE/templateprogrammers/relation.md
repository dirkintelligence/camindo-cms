# CMS:RELATION

> ***Wichtig:*** CMS:RELATION ist nur für Templates des Backends gedacht (Listen-,
> Trigger-Templates). Es prüft keine Zugriffsrechte: Es liefert die verknüpften
> Datensätze unabhängig von Instanz- und Bereichsrechten. Wie bei `CMS:SELECT` zählen
> aber nur ***veröffentlichte*** Ziele (freigegeben, Veröffentlichungsdatum erreicht,
> Ablaufdatum nicht erreicht) – auch im Backend und in der Vorschau; `future`,
> `archive` und `state` lockern das. Datensätze im Papierkorb und Arbeitskopien
> erscheinen nie.

Gibt das Hauptfeld (Titel) aller Datensätze aus, die über ein Verknüpfungsfeld mit dem
aktuellen Datensatz verbunden sind – in der bevorzugten Sprachversion des Backends
(je Instanz; führt eine Instanz sie nicht, deren Hauptsprache). Datensätze mit leerem
Hauptfeld erscheinen nicht.  
Für Frontend-Templates nutzen Sie `(CMS:SELECT relation=…)`

```cmscode
(CMS:RELATION field [parameter=wert …])
```

***Methode:*** `Cms::cmsRelation()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `field`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `id` | `Datensatz-Id oder Slug` | `(aktueller Datensatz)` |  | Datensatz, dessen Verweise ausgegeben werden. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL. |
| `field` ¹ | `Feld` | – |  | Name des Verweisfeldes (Feldtyp relation, pointer oder link), dessen Ziele ausgegeben werden. Ein anderer Feldtyp ergibt eine Fehlermeldung. |
| `limit` | `Zahl >0` | `1000` |  | Maximale Anzahl der einbezogenen Datensätze in der Ergebnismenge. |
| `future` | `true / false` | `false` |  | true: Auch vordatierte Ziele (online_at noch nicht erreicht) werden ausgegeben (wie bei CMS:SELECT). |
| `archive` | `true / false` | `false` |  | true: Auch abgelaufene Ziele (offline_at erreicht) werden ausgegeben (wie bei CMS:SELECT). |
| `state` | `Status-Id oder any` | `0` |  | Ohne Angabe nur freigegebene Ziele (status_id 0). Eine Status-Id > 0 nur Ziele mit diesem Status, "any" Ziele jeden Status (wie bei CMS:SELECT). |
| `lang` | `Sprachkürzel` | `(aktuelle Sprache)` |  | Kürzel der Sprachversion, deren Inhalte angefordert werden (wirkt sich auf mehrsprachige Felder aus). Noch nicht umgesetzt - derzeit gilt immer die Sprache des Aufrufs. |
| `separator` | `Trennzeichen` | `\|` |  | Text, der zwischen den Ergebnissen stehen soll. |
| `prefix` | `Text` | – |  | Text, der der Quelle vorangestellt wird, sofern diese nicht leer ist. |
| `suffix` | `Text` | – |  | Text, der an die Quelle angehängt wird, sofern diese nicht leer ist. |
| `ifempty` | `Text` | – |  | Text, der zur Quelle wird, wenn die ursprüngliche Quelle leer ist (weitere Parameter, außer 'set', werden ignoriert). |
| `instead` | `Text` | – |  | Überschreibt die Ausgabe, falls diese nicht leer ist. |
| `set` | `Array-Index von $set` | – |  | Die Ausgabe der Quelle wird umgeleitet und in $set eingetragen. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Gibt das ***Hauptfeld*** (Titel) aller Datensätze aus, auf die ein Verweisfeld des
laufenden Datensatzes zeigt - in der im Backend festgelegten Reihenfolge:

```cms
Themen: (CMS:RELATION topics separator=", ")
```
Ausgabe: `Themen: Reisen, Fotografie, Island`

Der Befehl liefert nur die Titel. Sollen die verknüpften Datensätze selbst ausgegeben
werden (mit Bild, Link, Datum …), ist `CMS:SELECT relation=topics` der richtige Weg:

```cms
(CMS:SELECT relation=topics resulttemplate=topic_badge)
```

Rückwärts-Verknüpfungen (welche Artikel zeigen auf dieses Thema?) gehen über
`CMS:SELECT filter[topics]="{$cms['record_id']}"`.

## Parameter im Einzelnen

### `field`

Name des Verweisfeldes; darf ohne Schlüssel stehen:

```cms
(CMS:RELATION field=topics)
(CMS:RELATION topics)
```

### `id`

Die Verweise eines anderen Datensatzes - per `record_id` oder Slug, wie in der URL. Der
Datensatz muss wie in der URL veröffentlicht sein, sonst bleibt die Ausgabe leer:

```cms
(CMS:RELATION topics id=1977)
(CMS:RELATION topics id=island-reise)
```

### `limit`

```cms
(CMS:RELATION topics limit=3 separator=", ")
```

Es werden höchstens drei Titel ausgegeben - die ersten drei in der Reihenfolge des Verweisfeldes.

### `future`

Auch Ziele, deren Veröffentlichungsdatum noch nicht erreicht ist:

```cms
(CMS:RELATION termine future=true separator=", ")
```

### `archive`

Auch abgelaufene Ziele:

```cms
(CMS:RELATION topics archive=true)
```

### `state`

Alle Ziele unabhängig vom Status – etwa in einem Listen-Template des Backends:

```cms
(CMS:RELATION topics state=any future=true archive=true)
```

### `lang`

> Noch nicht umgesetzt - es gilt die Sprache des Aufrufs.

```cms
(CMS:RELATION topics lang=en)
```

### `separator`

```cms
(CMS:RELATION topics separator=", ")
(CMS:RELATION topics separator="</li><li>" prefix="<ul><li>" suffix="</li></ul>")
```

Die zweite Zeile ergibt eine Liste: `<ul><li>Reisen</li><li>Fotografie</li></ul>`.

### `prefix`

`prefix` / `suffix` umschließen den Wert und erscheinen nur, wenn er nicht leer ist -
so entsteht kein leeres `Themen: `:

```cms
(CMS:RELATION topics prefix="Themen: " suffix="")
```

### `suffix`

```cms
(CMS:RELATION topics suffix=".")
```

Siehe `prefix`.

### `ifempty`

`ifempty` ersetzt die **gesamte** Ausgabe, wenn die Quelle leer ist; `prefix` und
`suffix` entfallen dann:

```cms
(CMS:RELATION topics prefix="<p>" suffix="</p>" ifempty="keine Themen zugeordnet")
```

### `instead`

`instead` tauscht den Wert aus, sobald die Quelle **nicht** leer ist - nützlich, wenn
nur das Vorhandensein zählt:

```cms
(CMS:RELATION topics instead="Themen vorhanden" ifempty="-")
```

### `set`

`set` gibt nichts aus, sondern legt das Ergebnis unter `$set['topics']` für das
umgebende PHP oder einen späteren Befehl ab:

```cms
(CMS:RELATION topics set=topics)
<?php if ($set['topics'] !== '') { ?> … <?php echo $set['topics']; ?> … <?php } ?>
```

---

[← Übersicht](README.md)
