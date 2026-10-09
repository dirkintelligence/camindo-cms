# CMS:TIME

Gibt die Uhrzeit eines Datums- oder Zeitwertes formatiert aus.

```cmscode
(CMS:TIME field [parameter=wert …])
```

***Methode:*** `Cms::cmsTime()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `field`  
***Mindestens einer von:*** `field`, `useval`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `id` | `Datensatz-Id oder Slug` | `(aktueller Datensatz)` |  | Datensatz, aus dem gelesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL. |
| `field` ¹ | `Feld` | – | ² | Name eines Datensatzfeldes, dessen Inhalt als Quelle genutzt wird. |
| `useval` | `Zeitwert` | – | ² | Wert in Textform, der als Quelle genutzt wird. |
| `format` | `Formatangaben` | – |  | ICU-Muster, z. B. "HH:mm". Ohne Angabe entscheidet die Sprache der Seite über die Schreibweise; volle Minuten werden ohne Sekunden gezeigt. |
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

Gibt nur die ***Uhrzeit*** eines Datums-/Zeitwertes aus:

```cms
(CMS:TIME event_at)                    14:30
(CMS:TIME event_at format="HH:mm:ss")  14:30:00
(CMS:TIME event_at suffix=" Uhr")      14:30 Uhr
```

Ohne `format` werden volle Minuten ohne Sekunden gezeigt.

## Parameter im Einzelnen

### `id`

`id` liest aus einem anderen Datensatz statt aus dem laufenden - record_id oder Slug,
genau wie in der URL:

```cms
(CMS:TIME event_at id=1977)
(CMS:TIME event_at id=impressum)
```

### `field`

`field` ist der Hauptparameter und darf ohne Schlüssel stehen - beide Zeilen sind gleich:

```cms
(CMS:TIME field=event_at)
(CMS:TIME event_at)
```

### `useval`

`useval` nimmt statt eines Feldes einen Wert direkt aus dem Template - meist eine
PHP-Variable oder ein zuvor mit `set` abgelegter Wert:

```cms
(CMS:TIME useval="2024-03-07 14:30:00")
(CMS:TIME useval="{$set['wert']}")
```

### `format`

`format` ist ein ICU-Muster (`d` Tag, `MM` Monat zweistellig, `MMMM` Monatsname, `y` Jahr,
`HH:mm` Uhrzeit, `EEEE` Wochentag). Ohne `format` entscheidet die Sprache der Seite (im Backend die Locale des Benutzers):

```cms
(CMS:TIME online_at format="HH:mm")
```
Ausgabe: `14:30`

`h:mm a` ergibt die 12-Stunden-Schreibweise (`2:30 PM`).

### `timezone`

```cms
(CMS:TIME event_at timezone=true)
```
Ausgabe: `14:30 Europe/Berlin`

### `replace`

`replace` ersetzt Teiltexte im **fertig formatierten** Wert - Kurzform mit `?` als
Trenner oder ein Eintrag je Suchtext:

```cms
(CMS:TIME event_at replace=":?.")
(CMS:TIME event_at replace[:]="." replace[x]="y")
```

Ausgabe: `14.30`

### `ifempty`

`ifempty` ersetzt die **gesamte** Ausgabe, wenn die Quelle leer ist; `prefix` und
`suffix` entfallen dann:

```cms
(CMS:TIME event_at prefix="<p>" suffix="</p>" ifempty="ganztägig")
```

### `instead`

`instead` tauscht den Wert aus, sobald die Quelle **nicht** leer ist - nützlich, wenn
nur das Vorhandensein zählt:

```cms
(CMS:TIME event_at instead="mit Uhrzeit" ifempty="-")
```

### `prefix`

`prefix` / `suffix` umschließen den Wert und erscheinen nur, wenn er nicht leer ist -
so entsteht kein leeres `ab  Uhr`:

```cms
(CMS:TIME event_at prefix="ab " suffix=" Uhr")
```

### `suffix`

```cms
(CMS:TIME event_at suffix=" Uhr")
```

Siehe `prefix`.

### `set`

`set` gibt nichts aus, sondern legt das Ergebnis unter `$set['time']` für das
umgebende PHP oder einen späteren Befehl ab:

```cms
(CMS:TIME event_at set=time)
<?php if ($set['time'] !== '') { ?> … <?php echo $set['time']; ?> … <?php } ?>
```

---

[← Übersicht](README.md)
