# CMS:TEXT

Gibt einen Text formatiert aus.

```cmscode
(CMS:TEXT field [parameter=wert …])
```

***Methode:*** `Cms::cmsText()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `field`  
***Mindestens einer von:*** `field`, `useval`, `id`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `id` | `Datensatz-Id oder Slug` | – | ² | Datensatz, aus dem gelesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL. Ohne 'field' wird das Feld "text" gelesen. |
| `field` ¹ | `Feld` | – | ² | Name eines Datensatzfeldes, dessen Inhalt als Quelle genutzt wird. |
| `useval` | `Text` | – | ² | Wert in Textform, der als Quelle genutzt wird. |
| `ifempty` | `Text` | – |  | Text, der zur Quelle wird, wenn die ursprüngliche Quelle leer ist (weitere Parameter, außer 'set', werden dann ignoriert). |
| `instead` | `Text` | – |  | Text, der zur Quelle wird, wenn die ursprüngliche Quelle nicht leer ist. |
| `entitydecode` | `true / false` | `true` |  | true: Entities im Format &...; werden zurück in Unicode-Zeichen gewandelt. |
| `trim` | `true / false` | `false` |  | true: Leerzeichen am Textanfang und -ende werden entfernt. |
| `striptags` | `true / false` | `false` |  | true: HTML- und Script-Tags werden entfernt. |
| `if[…]` | `Text?Text` | – |  | Wenn die Quelle dem Text vor dem Fragezeichen entspricht, wird der Text nach dem Fragezeichen zur neuen Quelle. |
| `else` | `Text` | – |  | Trifft keine der vorherigen IF-Abfragen zu, wird dieser Wert zur Quelle. |
| `replace[…]` | `Text?Text` | – |  | Kommt der Text vor dem Fragezeichen in der Quelle vor, wird er durch den Text nach dem Fragezeichen ersetzt. |
| `limit` | `Zahl >=0` | – |  | Kürzt den Text auf die angegebene Anzahl an Zeichen und hängt "…" an; mit angehängtem s (z. B. 200s) wird am letzten Wortende gekürzt. |
| `lbrconvert` | `true / false` | `true` |  | true: Zeilenumbrüche werden in HTML-Zeilenumbrüche (&lt;br&gt;-Tags) umgewandelt. |
| `specialchars` | `true / false` | `false` |  | true: Sonderzeichen werden in ihr HTML-Äquivalent umgewandelt. |
| `urlconvert` | `true / false` | `false` |  | true: Internet-Adressen werden automatisch in klickbare Links umgewandelt. |
| `emailconvert` | `true / false / encrypt` | `false` |  | true: E-Mail-Adressen werden verlinkt. encrypt: Die Links werden mittels JavaScript leicht verschlüsselt. |
| `emaillimit` | `>= 0` | – |  | Kürzt automatisch verlinkte E-Mail-Adressen auf x sichtbare Zeichen. |
| `emailtext` | `Text` | – |  | Verlinkter Text, der anstelle einer E-Mail-Adresse angezeigt wird (nur bei aktiviertem "emailconvert"). |
| `target` | `Fenster / Frame` | `_self` |  | Setzt das Link-Target für automatisch erzeugte Links (urlconvert, emailconvert). |
| `markdown` | `true / false` | `false` |  | true: Der Text ist Markdown und wird in HTML umgewandelt (HTML im Text wird maskiert); die übrigen Umwandlungen entfallen. |
| `urlencode` | `true / false` | `false` |  | true: Der Text wird nach RFC 3986 kodiert (rawurlencode) – für URL-Bestandteile, auch in Attributen ohne weitere Maskierung. |
| `urllimit` | `Anzahl Zeichen` | `80` |  | Maximale Länge des sichtbaren Textes für automatisch erzeugte Links (nur für externe Links geeignet!). |
| `ampconvert` | `true / false` | `true` |  | true: Ein alleinstehendes & wird automatisch in das HTML-Entity &amp; umgewandelt. |
| `prefix` | `Text` | – |  | Text, der der Quelle vorangestellt wird, sofern diese nicht leer ist. |
| `suffix` | `Text` | – |  | Text, der an die Quelle angehängt wird, sofern diese nicht leer ist. |
| `iso` | `true / false` | `false` |  | true: Die Ausgabe wird in den Zeichensatz ISO-8859-1 konvertiert. |
| `charset` | `Text` | `utf-8` |  | Zeichensatz, in den die Ausgabe konvertiert werden soll. |
| `set` | `Array-Index von $set` | – |  | Der formatierte Wert wird nicht ausgegeben, sondern in der Variable $set gespeichert. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.  
² mindestens einer der mit ² markierten Parameter muss angegeben werden.

`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Der Standardbefehl für Feldinhalte. Er liest ein Feld des laufenden Datensatzes,
wandelt Zeilenumbrüche in `<br>`, maskiert alleinstehende `&` und gibt das Ergebnis aus:

```cms
<h1>(CMS:TEXT title)</h1>
<p>(CMS:TEXT text)</p>
```

Ein Feld nur ausgeben, wenn es gefüllt ist - `prefix`/`suffix` erscheinen nur bei
nicht leerem Wert:

```cms
(CMS:TEXT teaser prefix="<p class=teaser>" suffix="</p>")
```

Wert für PHP zwischenspeichern statt ausgeben:

```cms
(CMS:TEXT title set=title)
<title><?php echo $set['title']; ?> – Beispiel GmbH</title>
```

Statuswert in Text übersetzen:

```cms
(CMS:TEXT status_id if[0]="online" if[1]="gesperrt" if[2]="unbestätigt" else="unbekannt")
```

Reihenfolge der Verarbeitung: Quelle → `if`/`else` → `replace` → `trim` → `striptags`
→ `limit` → (`markdown` bzw. `urlencode` beenden hier) → `entitydecode` → `specialchars` bzw.
`ampconvert` → `urlconvert` / `emailconvert` → `lbrconvert` → `iso`/`charset` →
`ifempty` / `instead` / `prefix` / `suffix` → Ausgabe oder `set`.

## Parameter im Einzelnen

### `id`

`id` liest aus einem anderen Datensatz - record_id oder Slug, wie in der URL. Nur bei
`CMS:TEXT` darf `id` allein stehen: dann wird das Feld `text` gelesen. Wie in der URL
muss der Datensatz ***veröffentlicht*** sein (freigegeben, Veröffentlichungsdatum
erreicht; ein abgelaufener bleibt lesbar), sonst ist der Wert leer – auch in der
Vorschau des Backends.

```cms
(CMS:TEXT title id=1977)          Titel des Datensatzes 1977
(CMS:TEXT id=impressum)           Feld "text" des Datensatzes mit dem Slug "impressum"
```

### `field`

`field` ist der Hauptparameter und darf ohne Schlüssel stehen - beide Zeilen sind gleich:

```cms
(CMS:TEXT field=title)
(CMS:TEXT title)
```

### `useval`

`useval` nimmt statt eines Feldes einen Wert direkt aus dem Template - meist eine
PHP-Variable oder ein zuvor mit `set` abgelegter Wert:

```cms
(CMS:TEXT useval="{$_GET['q']}" specialchars=true)
(CMS:TEXT useval="{$set['wert']}")
```

Werte aus dem Request (`$_GET`, `$_POST`) immer mit `specialchars=true` ausgeben – sonst
kann ein präparierter Link beliebiges HTML und Script in die Seite setzen.

Damit lassen sich die Text-Filter (`striptags`, `specialchars`, `limit` …) auch auf
fremde Werte anwenden.

### `ifempty`

`ifempty` ersetzt die **gesamte** Ausgabe, wenn die Quelle leer ist; `prefix` und
`suffix` entfallen dann:

```cms
(CMS:TEXT teaser prefix="<p>" suffix="</p>" ifempty="Kein Teaser vorhanden.")
```

Achtung: Ist die Quelle leer, werden auch alle Umwandlungen übersprungen - der
`ifempty`-Text wird unverändert ausgegeben.

### `instead`

`instead` tauscht den Wert aus, sobald die Quelle **nicht** leer ist - nützlich, wenn
nur das Vorhandensein zählt:

```cms
(CMS:TEXT image instead="✔ Bild vorhanden" ifempty="-")
```

### `entitydecode`

Standardmäßig werden Entities (`&amp;`, `&auml;`, `&#8211;`) zurück in Zeichen gewandelt.
Für Inhalte, die die Entities behalten sollen (z. B. für XML-Feeds):

```cms
(CMS:TEXT text entitydecode=false)
```

### `trim`

```cms
(CMS:TEXT title trim=true prefix="[" suffix="]")
```
Ausgabe: `[Hallo Welt]` statt `[ Hallo Welt ]`

### `striptags`

HTML- und Script-Tags entfernen, etwa für Teaser aus einem HTML-Feld oder für
Meta-Tags. Jedes Tag wird durch ein Leerzeichen ersetzt (aus `<p>a</p><p>b</p>` wird
`a b`), der Text zwischen den Tags bleibt stehen:

```cms
<meta name="description" content="(CMS:TEXT text striptags=true limit=160 lbrconvert=false)">
```

### `if`

Wertetabelle: Trifft der Wert einen Schlüssel, wird der Eintrag zur neuen Quelle.
Zwei Schreibweisen - Unterschlüssel oder Kurzform mit Fragezeichen:

```cms
(CMS:TEXT status_id if[0]="online" if[1]="gesperrt")
(CMS:TEXT status_id if="0?online" if[]="1?gesperrt")
```

Nicht getroffene Werte bleiben unverändert - außer `else` ist angegeben.

Enthält der Ersatztext eines ***Ziffern***-Schlüssels selbst ein Fragezeichen, nehmen Sie
die Kurzform: `if[]="1?Wirklich?"`. Bei `if[1]="Wirklich?"` wäre nicht zu erkennen, ob
`1` der Suchwert ist oder die Kurzform `Wirklich` → `` gemeint war (in PHP sind beide
Schreibweisen der Schlüssel `1`). Für Text-Schlüssel (`if[aktiv]="Ja?"`) gilt das nicht.

### `else`

```cms
(CMS:TEXT status_id if[0]="online" else="nicht online")
```
Ausgabe bei `status_id` = 2: `nicht online`

### `replace`

Teiltexte in der Quelle ersetzen - Kurzform mit `?` oder ein Eintrag je Suchtext:

```cms
(CMS:TEXT text replace="\n?<br><br>" lbrconvert=false)
(CMS:TEXT title replace[GmbH]="Gesellschaft mbH" replace[&]="und")
```

Wie bei `if` gilt: Ersatztexte mit Fragezeichen für einen Ziffern-Suchtext in der
Kurzform schreiben (`replace[]="0?null?"`).

### `limit`

Text auf eine Zeichenzahl kürzen; ein angehängtes `s` kürzt am letzten Wortende
(„soft“). Angehängt wird das Snippet `ellipsis` (`…`):

```cms
<p>(CMS:TEXT text striptags=true limit=200s)</p>
<td>(CMS:TEXT title limit=30)</td>
```

### `lbrconvert`

Standardmäßig werden Zeilenumbrüche zu `<br>`. Für Inhalte in `<pre>`, in Attributen
oder in JavaScript abschalten:

```cms
<pre>(CMS:TEXT code lbrconvert=false)</pre>
```

### `specialchars`

Alle Sonderzeichen maskieren (`<` → `&lt;`, `"` → `&quot;` …) - für Attribute und
für Inhalte, die kein HTML enthalten dürfen:

```cms
<input value="(CMS:TEXT title specialchars=true lbrconvert=false)">
```

### `urlconvert`

Adressen (`http://…`, `https://…`, `www.…`) im Text klickbar machen:

```cms
(CMS:TEXT text urlconvert=true target=_blank urllimit=24)
```
Ausgabe: `<a href="https://example.com/sehr/lange/adresse" target="_blank">https://example.com/sehr…</a>`

### `emailconvert`

E-Mail-Adressen verlinken; `encrypt` setzt den Link per JavaScript zusammen, sodass
die Adresse nicht im Quelltext steht. Das `<script>` dafür trägt die Nonce der Seite und
läuft deshalb auch unter einer durchgesetzten Script-Policy ([CMS:CSP](csp.md)):

```cms
(CMS:TEXT contact emailconvert=true)
(CMS:TEXT contact emailconvert=encrypt emailtext="E-Mail schreiben")
```

### `emaillimit`

Sichtbaren Teil einer verlinkten Adresse kürzen:

```cms
(CMS:TEXT contact emailconvert=true emaillimit=12)
```
Ausgabe: `<a href="mailto:redaktion@example.com">redaktion@ex…</a>`

### `emailtext`

Fester Linktext statt der Adresse (nur mit `emailconvert`):

```cms
(CMS:TEXT contact emailconvert=true emailtext="Kontakt aufnehmen")
```

### `target`

Link-Target für die von `urlconvert` erzeugten Links:

```cms
(CMS:TEXT text urlconvert=true target=_blank)
```

### `markdown`

Gibt ein Feld aus, das ***Markdown*** enthält – etwa eines mit der Extension
Markdown (visuell) – und wandelt es in HTML um:

```cms
<div class="text">(CMS:TEXT text markdown=true)</div>
```

Umgewandelt wird mit Parsedown; HTML im Text wird maskiert, Links auf `javascript:` und
ähnliche Ziele werden entschärft. Zusätzlich zum üblichen Markdown versteht der
Parameter die Formate der Extension Markdown (visuell):

| Markdown | Ergebnis |
|---|---|
| `++Text++` | unterstrichen (`<u>`) |
| `::: center` … `:::` | die eingeschlossenen Absätze zentriert (`left`, `center`, `right`; als `<div style="text-align: …">`) |
| `<br>` | Zeilenumbruch, auch in Tabellenzellen |
| `&shy;`, `&nbsp;` … | bleiben Sonderzeichen |

`markdown` beendet die Verarbeitung: `entitydecode`, `specialchars`, `lbrconvert`,
`urlconvert` usw. entfallen. `prefix`, `suffix`, `ifempty` und `set` wirken wie
gewohnt.

### `urlencode`

Den Wert als URL-Bestandteil kodieren. Alle weiteren Umwandlungen entfallen dann:

```cms
<a href="/suche?q=(CMS:TEXT title urlencode=true)">ähnliche Artikel</a>
```
Ausgabe: `/suche?q=Hallo%20Welt`

### `urllimit`

Sichtbare Länge automatisch verlinkter Adressen (Standard 80 Zeichen, 0 = ungekürzt):

```cms
(CMS:TEXT text urlconvert=true urllimit=30)
```

### `ampconvert`

Standardmäßig wird ein alleinstehendes `&` zu `&amp;`, vorhandene Entities bleiben.
Abschalten, wenn der Inhalt bereits vollständig maskiert ist:

```cms
(CMS:TEXT html_snippet ampconvert=false)
```

### `prefix`

`prefix` / `suffix` umschließen den Wert und erscheinen nur, wenn er nicht leer ist -
so entsteht kein leeres `<p class=teaser></p>`:

```cms
(CMS:TEXT teaser prefix="<p class=teaser>" suffix="</p>")
```

### `suffix`

```cms
(CMS:TEXT author_name suffix=" (Autor)")
```

Siehe `prefix`.

### `iso`

Ausgabe nach ISO-8859-1 umkodieren (Kurzform für `charset=ISO-8859-1`), etwa für
alte Schnittstellen:

```cms
(CMS:TEXT title iso=true)
```

### `charset`

```cms
(CMS:TEXT title charset=windows-1252)
```

### `set`

`set` gibt nichts aus, sondern legt das Ergebnis unter `$set['title']` für das
umgebende PHP oder einen späteren Befehl ab:

```cms
(CMS:TEXT title set=title)
<?php if ($set['title'] !== '') { ?> … <?php echo $set['title']; ?> … <?php } ?>
```

---

[← Übersicht](README.md)
