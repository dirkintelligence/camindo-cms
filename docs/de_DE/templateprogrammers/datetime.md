# CMS:DATETIME

Gibt Datum und Uhrzeit eines Datumswertes formatiert aus.

```cmscode
(CMS:DATETIME field [parameter=wert …])
```

***Methode:*** `Cms::cmsDatetime()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `field`  
***Mindestens einer von:*** `field`, `useval`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `id` | `Datensatz-Id oder Slug` | `(aktueller Datensatz)` |  | Datensatz, aus dem gelesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL. |
| `field` ¹ | `Feld` | – | ² | Name eines Datensatzfeldes, dessen Inhalt als Quelle genutzt wird. |
| `useval` | `Datumswert` | – | ² | Wert in Textform, der als Quelle genutzt wird. |
| `format` | `Formatangaben` | – |  | ICU-Muster, z. B. "dd.MM.y HH:mm". Ohne Angabe entscheidet die Sprache der Seite über die Schreibweise. |
| `autotime` | `true / false` | `false` |  | true: Wenn keine Uhrzeit vorhanden ist (00:00), wird diese nicht angezeigt. |
| `timezone` | `true / false` | `false` |  | true: Der Name der Zeitzone wird angehängt (z. B. "Europe/Berlin"). |
| `replace[…]` | `Text?Text` | – |  | Kommt der Text vor dem Fragezeichen im formatierten Wert vor, wird er durch den Text nach dem Fragezeichen ersetzt (z. B. "Jan?Januar"). |
| `ifempty` | `Text` | – |  | Text, der ausgegeben wird, falls die Quelle ('field' oder 'useval') leer ist. |
| `instead` | `Text` | – |  | Text, der anstelle der Quelle ausgegeben wird, sofern diese nicht leer ist. |
| `prefix` | `Text` | – |  | Text, der vorangestellt wird, sofern der formatierte Wert nicht leer ist. |
| `suffix` | `Text` | – |  | Text, der angehängt wird, sofern der formatierte Wert nicht leer ist. |
| `set` | `Array-Index von $set` | – |  | Der formatierte Wert wird nicht ausgegeben, sondern in der Variable $set gespeichert. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.  
² mindestens einer der mit ² markierten Parameter muss angegeben werden.

`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Gibt Datum **und** Uhrzeit aus. Wie `CMS:DATE`, nur mit Zeitanteil:

```cms
(CMS:DATETIME changed_at)                               07.03.2024, 14:30
(CMS:DATETIME changed_at format="dd.MM.y HH:mm")        07.03.2024 14:30
(CMS:DATETIME changed_at format="dd.MM.y HH:mm" autotime=true)
```

Mit `autotime=true` verschwindet die Uhrzeit, wenn im Wert keine gesetzt war
(`00:00:00`) - für Termine „ganztägig“.

## Parameter im Einzelnen

### `id`

`id` liest aus einem anderen Datensatz statt aus dem laufenden - record_id oder Slug,
genau wie in der URL:

```cms
(CMS:DATETIME changed_at id=1977)
(CMS:DATETIME changed_at id=impressum)
```

### `field`

`field` ist der Hauptparameter und darf ohne Schlüssel stehen - beide Zeilen sind gleich:

```cms
(CMS:DATETIME field=changed_at)
(CMS:DATETIME changed_at)
```

### `useval`

`useval` nimmt statt eines Feldes einen Wert direkt aus dem Template - meist eine
PHP-Variable oder ein zuvor mit `set` abgelegter Wert:

```cms
(CMS:DATETIME useval="2024-03-07 14:30:00")
(CMS:DATETIME useval="{$set['wert']}")
```

### `format`

`format` ist ein ICU-Muster (`d` Tag, `MM` Monat zweistellig, `MMMM` Monatsname, `y` Jahr,
`HH:mm` Uhrzeit, `EEEE` Wochentag). Ohne `format` entscheidet die Sprache der Seite (im Backend die Locale des Benutzers):

```cms
(CMS:DATETIME online_at format="dd.MM.y HH:mm")
```
Ausgabe: `07.03.2024 14:30`

### `autotime`

Nur zusammen mit `format`: Enthält der Wert keine Uhrzeit (`00:00:00`), wird der
Zeitanteil aus der Ausgabe entfernt:

```cms
(CMS:DATETIME event_at format="dd.MM.y HH:mm" autotime=true)
```
Ausgabe bei `2024-03-07 00:00:00`: `07.03.2024` - bei `2024-03-07 14:30:00`: `07.03.2024 14:30`

### `timezone`

`timezone=true` hängt den Namen der Zeitzone an, in der der Wert angezeigt wird:

```cms
(CMS:DATETIME online_at timezone=true)
```
Ausgabe: `07.03.2024, 14:30 Europe/Berlin`

### `replace`

`replace` ersetzt Teiltexte im **fertig formatierten** Wert - Kurzform mit `?` als
Trenner oder ein Eintrag je Suchtext:

```cms
(CMS:DATETIME changed_at replace=", ? um ")
(CMS:DATETIME changed_at replace[, ]=" um " replace[x]="y")
```

Ausgabe: `07.03.2024 um 14:30`

### `ifempty`

`ifempty` ersetzt die **gesamte** Ausgabe, wenn die Quelle leer ist; `prefix` und
`suffix` entfallen dann:

```cms
(CMS:DATETIME event_at prefix="<p>" suffix="</p>" ifempty="Termin folgt")
```

Als leer gelten auch die ***Platzhalter*** für „kein Datum“ und „kein Ende“, mit denen
camindo CMS etwa ein unbegrenztes `offline_at` speichert (vor dem 2. Januar 1000 bzw. ab
dem 31. Dezember 8999) – mit und ohne `format`.

### `instead`

`instead` tauscht den Wert aus, sobald die Quelle **nicht** leer ist - nützlich, wenn
nur das Vorhandensein zählt:

```cms
(CMS:DATETIME event_at instead="Termin steht fest" ifempty="-")
```

### `prefix`

`prefix` / `suffix` umschließen den Wert und erscheinen nur, wenn er nicht leer ist -
so entsteht kein leeres `Stand: `:

```cms
(CMS:DATETIME changed_at prefix="Stand: " suffix="")
```

### `suffix`

```cms
(CMS:DATETIME changed_at suffix=" Uhr")
```

Siehe `prefix`.

### `set`

`set` gibt nichts aus, sondern legt das Ergebnis unter `$set['changed']` für das
umgebende PHP oder einen späteren Befehl ab:

```cms
(CMS:DATETIME changed_at set=changed)
<?php if ($set['changed'] !== '') { ?> … <?php echo $set['changed']; ?> … <?php } ?>
```

---

[← Übersicht](README.md)
