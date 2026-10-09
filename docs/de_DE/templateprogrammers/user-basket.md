# CMS:USER_BASKET

Bearbeiten eines Warenkorbs.

```cmscode
(CMS:USER_BASKET function [parameter=wert …])
```

***Methode:*** `Modules\Record\RecordUser::cmsBasket()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `function`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `function` ¹ | `"load" / "add" / "update" / "update_addon"` | `"load"` | ja | Funktion, die ausgeführt werden soll. |
| `overwrite` | `false / true` | `false` |  | function=add + overwrite=true: Die Anzahl der bereits gespeicherten Artikel wird beim Hinzufügen aktualisiert. |
| `category` | `Kategorie-ID` | `0` |  | Warenkorb-Kategorie. |
| `container` | `Container-IDs oder -Namen, kommasepariert` | – |  | Nur Datensätze aus diesen Containern kommen in den Warenkorb. Ohne Angabe: veröffentlichte Datensätze aus Containern vom Typ "record" oder "ugc" (nie Profile). |
| `variable` | `Variablenname` | – |  | Name der Variable, in der Artikel-IDs als Array gespeichert sind. |
| `duration` | `Anzahl Tage` | `30` |  | Anzahl der Tage, die der Warenkorb gespeichert bleibt, sofern keine Änderungen durchgeführt werden. |
| `basket_addon` | `Text` | – |  | Text (serialisierte Daten), die gespeichert werden sollen. |
| `csrf` | `true / false` | `true` |  | true: Der Request muss ein gültiges Token aus [CMS:USER_TOKEN](user-token.md) enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab – nur mit gutem Grund. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Warenkorb je Frontend-Session - funktioniert auch ohne Anmeldung (anonyme Session).
Ergebnis in `$set`:

| `$set[…]` | Inhalt |
|---|---|
| `basket` | `record_id => Menge` |
| `basket_count` | Summe der Mengen |
| `basket_addon` | mit `update_addon` gespeicherter Text der Kategorie |

Artikel hinzufügen (Formular mit `items[]`), Warenkorb anzeigen:

```cms
<?php $f = $_POST['f'] ?? 'load'; ?>
(CMS:USER_BASKET function="$f" variable=items)
(CMS:USER_BASKET load)
<h2>Warenkorb (<?php echo (int)$set['basket_count']; ?> Stück)</h2>
<form method="post"><input type="hidden" name="f" value="update">
  (CMS:USER_TOKEN)
(CMS:RESULT_START)
  <p>(CMS:TEXT title): <input name="items[(CMS:NUMBER record_id)]" value="<?php echo (int)$set['basket'][$cms['record_id']]; ?>" size="3"></p>
(CMS:RESULT_END)
<button>Mengen aktualisieren (0 = entfernen)</button>
</form>
```

Nur nach `load` stehen die Artikel als Ergebnismenge bereit - `CMS:RESULT_START` läuft
über sie wie nach einem `CMS:SELECT`. Nach `add`, `update` und `update_addon` ist `$set`
aktuell, für die Liste folgt deshalb ein zweiter Aufruf mit `load` (Beispiel oben).
Ohne gültiges Token aus `CMS:USER_TOKEN` ändert eine schreibende Funktion nichts. Ein „In den Warenkorb“-Formular in einer
Artikelliste:

```cms
<form method="post" action="/0,u_basket">
  (CMS:USER_TOKEN)
  <input type="hidden" name="f" value="add">
  <button name="items[]" value="(CMS:TEXT record_id)">In den Warenkorb</button>
</form>
```

## Parameter im Einzelnen

### `function`

Darf ohne Schlüssel stehen:

```cms
(CMS:USER_BASKET load)                       Inhalt lesen, Artikel als Ergebnismenge
(CMS:USER_BASKET add variable=items)         Ids aus $_REQUEST['items'] hinzufügen
(CMS:USER_BASKET update variable=items)      Mengen setzen (0 entfernt)
(CMS:USER_BASKET update_addon basket_addon="{$note}")
```

### `overwrite`

Bei `add`: Menge **setzen** statt addieren:

```cms
(CMS:USER_BASKET add variable=items overwrite=true)
```
Mit `items[5]=2` liegt Artikel 5 danach genau 2-mal im Korb, auch wenn er schon drin war.

### `category`

Mehrere Warenkörbe je Session, z. B. Merkliste neben dem Einkaufskorb:

```cms
(CMS:USER_BASKET add variable=items category=1)
(CMS:USER_BASKET load category=1)
```

### `container`

Beschränkt den Warenkorb auf bestimmte Inhaltsbereiche (Namen oder Ids,
kommasepariert). Ohne Angabe sind veröffentlichte Datensätze aus Bereichen vom Typ
„record“ oder „ugc“ erlaubt, nie Profile. Die Prüfung gilt auch beim Lesen: Artikel
außerhalb der Bereiche erscheinen weder in `$set['basket']` noch in der Ergebnismenge.

```cms
(CMS:USER_BASKET add variable=items container=produkte,zubehoer)
(CMS:USER_BASKET load container=produkte,zubehoer)
```

### `variable`

Name des Request-Parameters mit den Artikel-Ids - als Liste (`items[]=5`, Menge 1 je Nennung) oder mit Mengen (`items[5]=2`):

```cms
<input type="hidden" name="cart[<?php echo $id; ?>]" value="1">
(CMS:USER_BASKET add variable=cart)
```

### `duration`

Tage, die der Warenkorb ohne Änderung erhalten bleibt (Standard 30):

```cms
(CMS:USER_BASKET add variable=items duration=7)
```

### `basket_addon`

Freitext zur Kategorie ablegen (Lieferhinweis, Gutscheincode, serialisierte Daten):

```cms
(CMS:USER_BASKET update_addon basket_addon="{$_POST['note']}")
<?php echo htmlspecialchars($set['basket_addon']); ?>
```

---

[← Übersicht](README.md)
