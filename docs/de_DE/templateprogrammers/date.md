# CMS:DATE

Gibt den Datumsteil eines Datumswertes formatiert aus.

```cmscode
(CMS:DATE field [parameter=wert …])
```

***Methode:*** `Cms::cmsDate()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `field`  
***Mindestens einer von:*** `field`, `useval`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `id` | `Datensatz-Id oder Slug` | `(aktueller Datensatz)` |  | Datensatz, aus dem gelesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL. |
| `field` ¹ | `Feld` | – | ² | Name eines Datensatzfeldes, dessen Inhalt als Quelle genutzt wird. |
| `useval` | `Datumswert` | – | ² | Wert in Textform, der als Quelle genutzt wird. |
| `format` | `Formatangaben` | – |  | ICU-Muster, z. B. "dd.MM.y". Ohne Angabe entscheidet die Sprache der Seite über die Schreibweise. |
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

Gibt den ***Datumsteil*** eines Datums-/Zeitwertes aus. Quelle ist ein Datums- oder
Datum-Zeit-Feld (Metafelder `online_at`, `created_at`, `changed_at` oder eigene Felder).
Ohne `format` entscheidet die Sprache der Seite über die Schreibweise:

```cms
(CMS:DATE online_at)                            07.03.2024   (de)
(CMS:DATE online_at format="EEEE, d. MMMM y")   Donnerstag, 7. März 2024
(CMS:DATE online_at format="y-MM-dd")           2024-03-07
```

Typisch in Listen:

```cms
<time datetime="(CMS:DATE online_at format='y-MM-dd')">(CMS:DATE online_at)</time>
```

`CMS:DATE`, `CMS:DATETIME` und `CMS:TIME` sind derselbe Befehl mit unterschiedlichem
Ausschnitt; alle drei kennen dieselben Parameter (`CMS:DATETIME` zusätzlich `autotime`).

## Parameter im Einzelnen

### `id`

`id` liest aus einem anderen Datensatz statt aus dem laufenden - record_id oder Slug,
genau wie in der URL:

```cms
(CMS:DATE online_at id=1977)
(CMS:DATE online_at id=impressum)
```

### `field`

`field` ist der Hauptparameter und darf ohne Schlüssel stehen - beide Zeilen sind gleich:

```cms
(CMS:DATE field=online_at)
(CMS:DATE online_at)
```

### `useval`

`useval` nimmt statt eines Feldes einen Wert direkt aus dem Template - meist eine
PHP-Variable oder ein zuvor mit `set` abgelegter Wert:

```cms
(CMS:DATE useval="2024-12-24")
(CMS:DATE useval="{$set['wert']}")
```

```cms
(CMS:DATE useval="{$set['user']['created_at']}")
```

### `format`

`format` ist ein ICU-Muster (`d` Tag, `MM` Monat zweistellig, `MMMM` Monatsname, `y` Jahr,
`HH:mm` Uhrzeit, `EEEE` Wochentag). Ohne `format` entscheidet die Sprache der Seite (im Backend die Locale des Benutzers):

```cms
(CMS:DATE online_at format="d. MMMM y")
```
Ausgabe: `7. März 2024`

### `timezone`

```cms
(CMS:DATE online_at timezone=true)
```
Ausgabe: `07.03.2024 Europe/Berlin`

### `replace`

`replace` ersetzt Teiltexte im **fertig formatierten** Wert - Kurzform mit `?` als
Trenner oder ein Eintrag je Suchtext:

```cms
(CMS:DATE online_at format="MMM y" replace="Jan.?Januar")
(CMS:DATE online_at format="MMM y" replace[Jan.]="Januar" replace[x]="y")
```

Ausgabe: `Januar 2026` statt `Jan. 2026`

### `ifempty`

`ifempty` ersetzt die **gesamte** Ausgabe, wenn die Quelle leer ist; `prefix` und
`suffix` entfallen dann:

```cms
(CMS:DATE offline_at prefix="<p>" suffix="</p>" ifempty="unbegrenzt")
```

Als leer gelten auch die ***Platzhalter*** für „kein Datum“ und „kein Ende“, mit denen
camindo CMS etwa ein unbegrenztes `offline_at` speichert (vor dem 2. Januar 1000 bzw. ab
dem 31. Dezember 8999) – mit und ohne `format`.

### `instead`

`instead` tauscht den Wert aus, sobald die Quelle **nicht** leer ist - nützlich, wenn
nur das Vorhandensein zählt:

```cms
(CMS:DATE offline_at instead="befristet" ifempty="-")
```

### `prefix`

`prefix` / `suffix` umschließen den Wert und erscheinen nur, wenn er nicht leer ist -
so entsteht kein leeres `veröffentlicht am `:

```cms
(CMS:DATE online_at prefix="veröffentlicht am " suffix="")
```

### `suffix`

```cms
(CMS:DATE online_at suffix=" (Erstveröffentlichung)")
```

Siehe `prefix`.

### `set`

`set` gibt nichts aus, sondern legt das Ergebnis unter `$set['date']` für das
umgebende PHP oder einen späteren Befehl ab:

```cms
(CMS:DATE online_at set=date)
<?php if ($set['date'] !== '') { ?> … <?php echo $set['date']; ?> … <?php } ?>
```

---

[← Übersicht](README.md)
