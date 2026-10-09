# CMS:MESSAGE

Erzeugt eine Textausgabe aus aktuellen Fehlermeldungen.

```cmscode
(CMS:MESSAGE useval [parameter=wert …])
```

***Methode:*** `Cms::cmsMessage()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `useval`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `useval` ¹ | `Text` | – |  | Wert in Textform, der als Quelle genutzt wird. |
| `prefix` | `Text` | – |  | Text, der vor den Meldungen ausgegeben wird, sofern es welche gibt. |
| `suffix` | `Text` | – |  | Text, der nach den Meldungen ausgegeben wird, sofern es welche gibt. |
| `ifempty` | `Text` | – |  | Text, falls keine Meldung vorhanden ist. |
| `instead` | `Text` | – |  | Text, der anstelle der Meldungen ausgegeben wird. |
| `set` | `Array-Index von $set` | – |  | Die Meldung wird nicht angezeigt, sondern in die Variable $set geschrieben. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Gibt die Meldungen aus, die Befehle dieser Seite abgelegt haben - typischerweise
Feldfehler von `CMS:USER_INPUT`, `CMS:USER_LOGIN` oder `CMS:USER_REGISTER`. Ohne Meldung
bleibt der Befehl still:

```cms
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=welcome)
<p class="error">(CMS:MESSAGE)</p>
```

Mehrere Meldungen werden mit `<br>` getrennt. Eine Umrahmung gibt es nur über
`prefix`/`suffix` – sie erscheint nur, wenn es Meldungen gibt:

```cms
(CMS:MESSAGE prefix="<div class=alert>" suffix="</div>")
```

## Parameter im Einzelnen

### `useval`

Eigenen Text statt der Systemmeldungen ausgeben (die Meldungen werden dann ignoriert;
ist `useval` leer, erscheinen wieder die Meldungen):

```cms
(CMS:MESSAGE useval="{$set['hinweis']}" prefix="<p class=hint>" suffix="</p>")
```

Der Text wird unverändert ausgegeben. Setzen Sie hier keine Werte aus dem Request
(`$_GET`, `$_POST`) ein – dafür gibt es `(CMS:TEXT useval="…" specialchars=true)`.

### `prefix`

```cms
(CMS:MESSAGE prefix="<div class=alert>" suffix="</div>")
```

Ohne Angabe werden die Meldungen ohne Umrahmung ausgegeben.

### `suffix`

```cms
(CMS:MESSAGE prefix="<ul class=errors><li>" suffix="</li></ul>")
```

### `ifempty`

Text, wenn **keine** Meldung vorliegt:

```cms
(CMS:MESSAGE ifempty="Alles in Ordnung.")
```

### `instead`

Fester Text, sobald eine Meldung vorliegt - wenn der Wortlaut nicht angezeigt werden soll:

```cms
(CMS:MESSAGE instead="Bitte prüfen Sie Ihre Eingaben.")
```

### `set`

Meldungen für PHP bereitstellen, etwa um sie neben dem jeweiligen Feld zu zeigen:

```cms
(CMS:MESSAGE set=errors)
<?php if ($set['errors'] !== '') { ?><div class="alert"><?php echo $set['errors']; ?></div><?php } ?>
```

---

[← Übersicht](README.md)
