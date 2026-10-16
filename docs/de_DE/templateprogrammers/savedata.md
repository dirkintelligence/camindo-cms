# CMS:SAVEDATA

Speichert die Daten des aktuellen Datensatzes in der angegebenen Variable.

```cmscode
(CMS:SAVEDATA phpvar [parameter=wert …])
```

***Methode:*** `Cms::cmsSaveData()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `phpvar`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `phpvar` ¹ | `Index innerhalb $GLOBALS` | – | ja | Variable, in der die Daten des aktuellen Datensatzes abgelegt werden sollen. |
| `id` | `Datensatz-Id oder Slug` | – |  | Datensatz, dessen Daten geladen werden sollen. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL. Ohne Angabe der aktuell geladene Datensatz. |
| `lang` | `Sprachversions-Kürzel` | `$lang` |  | Gibt an, in welcher Sprache die Inhalte mehrsprachiger Felder geladen werden sollen. Noch nicht umgesetzt - derzeit gilt immer die Sprache des Aufrufs. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Legt einen kompletten Datensatz als Array in einer globalen PHP-Variable ab - für
Logik, die mit den Ausgabebefehlen allein nicht zu schreiben ist:

```cms
(CMS:SAVEDATA article)
<?php if ($article['price'] > 100 && $article['stock'] > 0) { ?>
  <span class="badge">Premium</span>
<?php } ?>
```

Mit `id` einen **anderen** Datensatz laden, etwa den Elternknoten, um mit seinen Werten
zu rechnen:

```cms
(CMS:SAVEDATA parent id="{$cms['parent_id']}")
<?php if ($parent['price'] > $cms['price']) { ?> … <?php } ?>
```

Geht es nur um die ***Ausgabe*** von Feldern eines anderen Datensatzes, brauchen Sie
`CMS:SAVEDATA` nicht – die Ausgabebefehle lesen mit `id` selbst:

```cms
<a href="(CMS:URL "{$cms['parent_id']}")">(CMS:TEXT title id="{$cms['parent_id']}")</a>
<footer>(CMS:TEXT footer_text id=konfiguration)</footer>
```

Das Array enthält die Metafelder (`record_id`, `area_id`, `online_at` …) und die
Felder des Bereichs in der aktuellen Sprache; Upload-Felder als Datei-Array (`filename`,
`mime_type`, `size` …). ***Verweisfelder fehlen*** – ihre Ziele liefert
`CMS:SELECT relation=…`, ihre Anzahl `(CMS:SELECT x relation=topics hits=false)` –
danach steht sie in `$this->db->num_rows_unlimited`.
Gibt es den Datensatz nicht, ist das Array leer.

## Parameter im Einzelnen

### `phpvar`

Name der Variable; darf ohne Schlüssel stehen. Nur Buchstaben, Ziffern und `_`.
Das Array steht danach direkt als `$article` im Template – auch in mit
`CMS:INCLUDE` eingebundenen Templates – und zusätzlich in `$GLOBALS['article']`,
etwa für eigene PHP-Funktionen:

```cms
(CMS:SAVEDATA phpvar=article)
(CMS:SAVEDATA article)
<?php echo $article['record_id']; ?>
```

Namen, die mit `_` beginnen, sowie `this` und `GLOBALS` sind nur über
`$GLOBALS[…]` erreichbar.

### `id`

Anderer Datensatz - record_id oder Slug:

```cms
(CMS:SAVEDATA parent id="{$cms['parent_id']}")
(CMS:SAVEDATA site id=konfiguration)
```

### `lang`

> Noch nicht umgesetzt - es gilt die Sprache des Aufrufs.

---

[← Übersicht](README.md)
