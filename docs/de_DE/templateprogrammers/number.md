# CMS:NUMBER

Gibt einen Zahlenwert formatiert aus.

```cmscode
(CMS:NUMBER field [parameter=wert …])
```

***Methode:*** `Cms::cmsNumber()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `field`  
***Mindestens einer von:*** `field`, `useval`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `id` | `Datensatz-Id oder Slug` | `(aktueller Datensatz)` |  | Datensatz, aus dem gelesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL. |
| `field` ¹ | `Feld` | – | ² | Name eines Datensatzfeldes, dessen Inhalt als Quelle genutzt wird. |
| `useval` | `Zahl` | – | ² | Wert in Textform, der als Quelle genutzt wird. |
| `ifempty` | `Text` | – |  | Text, der ausgegeben wird, wenn die Quelle leer ist oder der Zahlenwert (nach mul/add) 0 ergibt. |
| `instead` | `Zahl` | – |  | Zahl, die zur Quelle wird, sofern die ursprüngliche Quelle nicht leer ist. |
| `mul` | `Zahl` | `1` |  | Zahl, mit der die Quelle multipliziert wird. |
| `add` | `Zahl` | `0` |  | Zahl, die zur Quelle addiert wird. |
| `format` | `Formatierung` | – |  | Formatierung analog zum PHP-Befehl sprintf(). |
| `decimals` | `Zahl 0-10` | – |  | Feste Anzahl Nachkommastellen (gerundet); `decimal` und `thousands_separator` wirken weiter. Ohne Angabe so viele, wie die Zahl hat. |
| `decimal` | `Zeichen` | `.` |  | Zeichen, durch das der Dezimalpunkt ersetzt wird. |
| `thousands_separator` | `Zeichen` | – |  | Zeichen, das als Tausendertrennzeichen (alle drei Stellen von rechts nach links) eingefügt wird. |
| `nullcent` | `Text` | – |  | Text, durch den ein fehlender Nachkommawert ersetzt wird. |
| `replace[…]` | `Text?Text` | – |  | Kommt der Text vor dem Fragezeichen im formatierten Wert vor, wird er durch den Text nach dem Fragezeichen ersetzt (z. B. ".00?" entfernt leere Nachkommastellen). |
| `prefix` | `Text` | – |  | Text, der dem formatierten Wert vorangestellt wird, sofern dieser nicht "0" oder leer ist. |
| `suffix` | `Text` | – |  | Text, der dem formatierten Wert angehängt wird, sofern dieser nicht "0" oder leer ist. |
| `set` | `Array-Index von $set` | – |  | Der formatierte Wert wird nicht ausgegeben, sondern stattdessen in der Variable $set gespeichert. |
| `sizefactor` | `Anzahl Bytes eines Kilobytes` | – |  | 1000 oder 1024. Wenn > 0, wird eine lesbare Dateigröße berechnet (z. B. 15.2 GB). |

¹ kann ohne Schlüssel als erster Wert angegeben werden.  
² mindestens einer der mit ² markierten Parameter muss angegeben werden.

`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Gibt einen Zahlenwert formatiert aus. Ohne weitere Parameter erscheint die Zahl so, wie
sie gespeichert ist - mit Punkt als Dezimaltrenner und ohne Tausendertrennzeichen:

```cms
(CMS:NUMBER price)                                        1234.5
(CMS:NUMBER price decimal="," thousands_separator=".")    1.234,5
(CMS:NUMBER price decimals=2 decimal="," thousands_separator="." suffix=" €")   1.234,50 €
```

Rechnen vor der Ausgabe (`mul`, `add`), Dateigrößen lesbar machen (`sizefactor`):

```cms
(CMS:NUMBER price mul=1.19 decimals=2 decimal="," suffix=" € inkl. MwSt.")
(CMS:NUMBER filesize sizefactor=1024)                     15.2 MB
```

Ergibt die Rechnung `0`, erscheint `0` (formatiert, z. B. `0,00` mit `decimals=2`):

```cms
(CMS:NUMBER useval="{$a}" add=-1)                          0  (bei $a = 1)
```

Sobald `prefix`, `suffix` oder `ifempty` angegeben ist, gilt der Wert `0` dagegen als
leer - eine Preisangabe mit `0` bleibt also ohne ` €`. Maßgeblich ist der Zahlenwert nach
`mul`/`add`, nicht der formatierte Text: auch `0.00` (mit `decimals` oder `format`) gilt
dann als leer. Eine leere Quelle (Feld ohne Inhalt, leeres `useval`) ergibt immer eine
leere Ausgabe - auch mit `mul`/`add`.

## Parameter im Einzelnen

### `id`

`id` liest aus einem anderen Datensatz statt aus dem laufenden - record_id oder Slug,
genau wie in der URL:

```cms
(CMS:NUMBER price id=1977)
(CMS:NUMBER price id=impressum)
```

### `field`

`field` ist der Hauptparameter und darf ohne Schlüssel stehen - beide Zeilen sind gleich:

```cms
(CMS:NUMBER field=price)
(CMS:NUMBER price)
```

### `useval`

`useval` nimmt statt eines Feldes einen Wert direkt aus dem Template - meist eine
PHP-Variable oder ein zuvor mit `set` abgelegter Wert:

```cms
(CMS:NUMBER useval="{$this->db->num_rows_unlimited}")
(CMS:NUMBER useval="{$set['wert']}")
```

Komma als Dezimaltrenner wird verstanden: `useval="3,5"` ergibt `3.5`.

### `ifempty`

Mit `ifempty` gilt auch der Zahlenwert `0` als leer - egal, wie er formatiert würde:

```cms
(CMS:NUMBER stock ifempty="ausverkauft" suffix=" Stück")
```
Ausgabe bei `stock` = 0: `ausverkauft` - bei 12: `12 Stück`

### `instead`

`instead` ersetzt die ***Quelle*** (nicht das Ergebnis), wenn sie nicht leer ist -
Rechnung und Formatierung laufen danach auf dem neuen Wert:

```cms
(CMS:NUMBER price instead=99 decimal=",")
```

### `mul`

```cms
(CMS:NUMBER price mul=1.19 decimals=2 decimal="," suffix=" €")   Bruttopreis
(CMS:NUMBER share mul=100 suffix=" %")                           0.25 → 25 %
```

### `add`

```cms
(CMS:NUMBER price add=4.90 decimals=2 decimal="," suffix=" € inkl. Versand")
```
`mul` wird vor `add` angewendet: Ergebnis = Quelle × mul + add.

### `format`

`sprintf()`-Muster; es bestimmt die Ausgabe allein, `decimal`, `thousands_separator`,
`decimals` und `nullcent` bleiben dann außen vor. Für Preise mit Dezimalkomma und
Tausenderpunkt ist deshalb `decimals` der richtige Parameter, `format` für Sonderfälle
wie führende Nullen:

```cms
(CMS:NUMBER price format="%.2f")        1234.50
(CMS:NUMBER position format="%03d")     007
```

### `decimals`

Feste Anzahl Nachkommastellen, kaufmännisch gerundet - die Trennzeichen aus `decimal`
und `thousands_separator` gelten weiter:

```cms
(CMS:NUMBER price decimals=2 decimal="," thousands_separator=".")
```
Ausgabe bei `1234.5`: `1.234,50` - bei `19.999`: `20,00`

Ein Betrag in Cent (Feldtyp Währung) wird mit `mul=0.01` zu Euro:

```cms
(CMS:NUMBER cur_preis mul=0.01 decimals=2 decimal="," suffix=" €")
```

### `decimal`

```cms
(CMS:NUMBER price decimal=",")
```
Ausgabe: `1234,5`

### `thousands_separator`

```cms
(CMS:NUMBER views thousands_separator=".")
(CMS:NUMBER views thousands_separator=" ")
```
Ausgabe: `1.234.567` bzw. `1 234 567`

### `nullcent`

Ersatz für fehlende Nachkommastellen (ohne Angabe: keiner) - für Preise, die immer zwei Stellen zeigen sollen:

```cms
(CMS:NUMBER price decimal="," nullcent="00" suffix=" €")
```
Ausgabe bei `1234`: `1234,00 €` - bei `1234.5` bleibt `1234,5 €` (nur **fehlende**
Nachkommastellen werden ersetzt; für festes Runden `decimals=2` nutzen).

### `replace`

`replace` ersetzt Teiltexte im **fertig formatierten** Wert - Kurzform mit `?` als
Trenner oder ein Eintrag je Suchtext:

```cms
(CMS:NUMBER price replace=".00?")
(CMS:NUMBER price replace[.00]="" replace[x]="y")
```

Ausgabe bei `12.00`: `12`

### `prefix`

Erscheint nur, wenn der formatierte Wert weder leer noch `0` ist:

```cms
(CMS:NUMBER discount prefix="- " suffix=" %")
```

### `suffix`

```cms
(CMS:NUMBER price suffix=" €")
```

Siehe `prefix`.

### `set`

`set` gibt nichts aus, sondern legt das Ergebnis unter `$set['price']` für das
umgebende PHP oder einen späteren Befehl ab:

```cms
(CMS:NUMBER price set=price)
<?php if ($set['price'] !== '') { ?> … <?php echo $set['price']; ?> … <?php } ?>
```

Der gespeicherte Wert ist ein String in der formatierten Schreibweise.

### `sizefactor`

Dateigröße lesbar machen (Bytes → KB / MB / GB); `1024` für binäre, `1000` für
dezimale Einheiten:

```cms
(CMS:NUMBER filesize sizefactor=1024)      15.2 MB
(CMS:NUMBER filesize sizefactor=1000)      15.9 MB
```
Für Upload-Felder geht das auch direkt mit `(CMS:MEDIA image subfield=size)`.

---

[← Übersicht](README.md)
