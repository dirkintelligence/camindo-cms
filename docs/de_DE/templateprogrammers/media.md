# CMS:MEDIA

Erzeugt ein Image-Tag oder gibt den Inhalt einer Mediendatei in Textform aus.

```cmscode
(CMS:MEDIA field [parameter=wert …])
```

***Methode:*** `Cms::cmsMedia()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `field`  
***Mindestens einer von:*** `field`, `useval`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `id` | `Datensatz-Id oder Slug` | `(aktueller Datensatz)` |  | Datensatz, aus dem gelesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL. |
| `field` ¹ | – | – | ² | Name eines Upload- oder Thumbnail-Feldes. |
| `subfield` | `path / file / filename / mime_type / extension / size / width / height / version` | – |  | Einzelwert statt IMG-Tag: path (Pfad ab DOCUMENT_ROOT für src/href, mit sprechendem Dateinamen; bei auth/download/user_auth über file.php), file (absoluter Pfad im Dateisystem), filename (ursprünglicher Dateiname), mime_type, extension (ohne Punkt), size (Bytes; mit autoformat lesbar), width / height (Pixel, 0 wenn unbekannt), version (Versionszähler der Datei). Der Einzelwert wird nicht maskiert. |
| `auth` | `true / false` | `false` |  | true: Der Verweis zeigt auf /file.php statt ins Medienverzeichnis. Für Felder mit "Nur für angemeldete Frontend-User" (user_auth) gilt das automatisch - file.php prüft dort die Frontend-Session. |
| `download` | `Text` | – |  | Datei wird über /file.php unter dem angegebenen Dateinamen zum Download angeboten (Dateiendung wird automatisch ergänzt). |
| `width` | `Text` | – |  | Breite des IMG-Tags, mit oder ohne CSS-Einheit (Standard: px). |
| `height` | `Text` | – |  | Höhe des IMG-Tags, mit oder ohne CSS-Einheit (Standard: px). |
| `attr[…]` | `Array` | – |  | Zusätzliche Attribute für das IMG-Tag (Schlüssel: Attributname, Wert: Attributwert). Die Werte werden automatisch mit htmlspecialchars maskiert. |
| `autoformat` | `true / false` | `true` |  | true: Der via 'subfield' gewählte Inhalt wird je nach Typ formatiert (z. B. Dateigrößen in Bytes/KB/MB dynamisch nach Wert). |
| `instead` | `Text` | – |  | Überschreibt den formatierten Wert, sofern dieser nicht leer ist. |
| `ifempty` | `Text` | – |  | Alternativtext, falls der formatierte Wert leer (oder "0") ist. |
| `prefix` | `Text` | – |  | Text, der vorangestellt wird, sofern der formatierte Wert nicht leer (oder "0") ist. |
| `suffix` | `Text` | – |  | Text, der angehängt wird, sofern der formatierte Wert nicht leer (oder "0") ist. |
| `useval` | `Array` | – | ² | Datei-Array (etwa aus CMS:SAVEDATA) samt field_id und record_id - nur per PHP-Aufruf, ohne field (siehe Beispiel). |
| `set` | `Array-Index von $set` | – |  | Der formatierte Wert wird nicht ausgegeben, sondern in der Variable $set gespeichert. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.  
² mindestens einer der mit ² markierten Parameter muss angegeben werden.

`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Quelle ist ein ***Upload-Feld***. Ohne `subfield` entsteht ein `<img>`-Tag mit den
gespeicherten Maßen, `alt` ist der ursprüngliche Dateiname:

```cms
(CMS:MEDIA image)
```
Ausgabe: `<img src="/media/1/12/1977,strand.jpg?v=3" alt="strand.jpg" width="1200" height="800">`

Der Pfad trägt den ursprünglichen Dateinamen hinter einem Komma (sprechende URL für
Browser und Suchmaschinen, als Slug ohne Endung); die Datei selbst heißt `1977.jpg` –
eine Rewrite-Regel im Medienverzeichnis wirft den Namensteil ungeprüft weg. `?v=` trägt
die Versionsnummer der Datei: nach einem neuen Upload ändert sich die Adresse, der
Browser lädt die Datei neu.

Mit `subfield` wird ein Einzelwert der Datei ausgegeben - so lassen sich beliebige
Tags bauen oder Downloads verlinken:

```cms
<a href="(CMS:MEDIA document subfield=path)">
  (CMS:MEDIA document subfield=filename) ((CMS:MEDIA document subfield=size))
</a>
```
Ausgabe: `<a href="/media/1/13/1977,agb.pdf?v=3">agb.pdf (123.5 KB)</a>`

Kein Bild hochgeladen? `ifempty` liefert den Ersatz - `prefix`/`suffix` entfallen dann:

```cms
(CMS:MEDIA image width=300 height="" prefix="<figure>" suffix="</figure>" ifempty="<img src='/static/placeholder.png'>")
```

**Verfügbare `subfield`-Werte**

| subfield | Inhalt |
|---|---|
| `path` | Pfad ab DOCUMENT_ROOT für `src`/`href`: `/media/{instanz}/{feld}/{datensatz},{name}.{endung}` - bei geschützten Feldern, `auth=true` und `download` stattdessen der `/file.php`-Aufruf |
| `file` | absoluter Pfad im Dateisystem des Servers (`…/public/media/1/12/1977.jpg`) - für Dateioperationen in PHP, nie für Links |
| `filename` | ursprünglicher Dateiname (vor dem Upload) |
| `mime_type` | z. B. `image/jpeg` |
| `extension` | Dateiendung ohne Punkt, z. B. `jpg` |
| `size` | Dateigröße; mit `autoformat` (Standard) lesbar (`123.5 KB`), mit `autoformat=false` in Bytes |
| `width` / `height` | Bildmaße in Pixeln, `0` wenn nicht ermittelbar |
| `version` | Versionszähler der Datei - steigt bei jedem neuen Upload in dasselbe Feld |

`url` ist ein Alias für `path` (Schreibweise aus C9-Vorabversionen). Bei mehrsprachigen
Upload-Feldern liefert der Befehl die Datei der aktuellen Sprache bzw. die der
Hauptsprache als Fallback (`1977_2,strand.jpg`).

***Geschützte Dateien*** (Feldoption „nur für angemeldete Frontend-User“): Der Verweis zeigt
automatisch auf `/file.php`, das die Frontend-Session prüft. `auth=true` erzwingt diesen
Weg auch für ungeschützte Felder, `download="name"` bietet die Datei zum Herunterladen an.

## Parameter im Einzelnen

### `id`

`id` liest aus einem anderen Datensatz statt aus dem laufenden - record_id oder Slug,
genau wie in der URL:

```cms
(CMS:MEDIA image id=1977)
(CMS:MEDIA image id=impressum)
```

### `field`

Name des Upload-Feldes; darf ohne Schlüssel stehen:

```cms
(CMS:MEDIA field=image)
(CMS:MEDIA image)
```

### `subfield`

Einzelwert statt `<img>`-Tag (Liste siehe oben):

```cms
<img src="(CMS:MEDIA image subfield=path)" alt="(CMS:TEXT title specialchars=true)" loading="lazy">
Dateityp: (CMS:MEDIA document subfield=mime_type)
```

Einzelwerte über `subfield` werden ***nicht maskiert***. `path` enthält nur
URL-taugliche Zeichen und kann direkt in `src` oder `href` stehen. Den ursprünglichen
Dateinamen (`filename`) wählt der Hochladende – in einem Attribut maskieren Sie ihn
über `CMS:TEXT`:

```cms
(CMS:MEDIA document subfield=filename set=dateiname)
<a href="(CMS:MEDIA document subfield=path)" title="(CMS:TEXT useval="{$set['dateiname']}" specialchars=true lbrconvert=false)">…</a>
```

`path` und `file` unterscheiden sich im Zweck - Verweis für den Browser gegenüber
Zugriff durch PHP:

```cms
(CMS:MEDIA document subfield=path)      /media/1/13/1977,agb.pdf?v=3
(CMS:MEDIA document subfield=file)      /var/www/example/public/media/1/13/1977.pdf
```

```cms
(CMS:MEDIA document subfield=file set=file)
<?php echo filesize($set['file']) > 5e6 ? 'große Datei' : ''; ?>
```

### `auth`

Verweis über `/file.php` erzwingen - etwa, damit eine Vorschau denselben Zugriffsweg
nimmt wie das geschützte Original:

```cms
<p>Vorschau: (CMS:MEDIA thumb)</p>
<p>Original (nur angemeldet): (CMS:MEDIA image auth=true)</p>
```
Bei Feldern mit der Option „nur für angemeldete Frontend-User“ gilt das automatisch.

### `download`

Datei zum Herunterladen anbieten; `download` ist der Dateiname beim Speichern, die
Endung wird ergänzt:

```cms
<a href="(CMS:MEDIA document subfield=path download="AGB-2026")">AGB herunterladen</a>
```
Ausgabe: `<a href="/file.php?file=13/1977&v=2&download=AGB-2026">AGB herunterladen</a>`

### `width`

Breite des `<img>`-Tags; ohne Angabe die gespeicherte Bildbreite. Ein leerer Wert
lässt das Attribut weg, der Browser rechnet dann proportional:

```cms
(CMS:MEDIA image width=300 height="")
```
Ausgabe: `<img src="/media/1/12/1977,strand.jpg?v=3" alt="strand.jpg" width="300">`

### `height`

```cms
(CMS:MEDIA image width="" height=200)
(CMS:MEDIA image width="100%" height="")
```

### `attr`

Weitere Attribute; Schlüssel = Attributname. Vorhandene (`src`, `alt`, `width`,
`height`) lassen sich damit überschreiben:

```cms
(CMS:TEXT title striptags=true ampconvert=false lbrconvert=false set=title)
(CMS:MEDIA image attr[alt]="{$set['title']}" attr[class]="hero" attr[loading]="lazy")
```

`CMS:MEDIA` ***maskiert alle Attributwerte*** des `<img>`-Tags selbst mit
`htmlspecialchars` – auch die aus `attr`. Übergeben Sie deshalb unmaskierten Text,
ohne `specialchars=true`; sonst wird doppelt maskiert und aus `&` wird sichtbar
`&amp;`. Aus dem Attributnamen entfernt der Befehl alle Zeichen außer Buchstaben,
Ziffern, `_` und `-`.

### `autoformat`

Betrifft nur `subfield=size`: Standardmäßig lesbar (`123.5 KB`), mit `false` in Bytes:

```cms
(CMS:MEDIA document subfield=size)                   123.5 KB
(CMS:MEDIA document subfield=size autoformat=false)  126464
```

### `instead`

Fester Text, wenn eine Datei vorhanden ist (das Tag bzw. der Einzelwert selbst wird
nicht ausgegeben):

```cms
Bild: (CMS:MEDIA image instead="vorhanden" ifempty="fehlt")
```

### `ifempty`

Ersatz, wenn keine Datei vorhanden ist (bzw. der Einzelwert leer oder `0`):

```cms
(CMS:MEDIA image ifempty="<img src='/static/placeholder.png' alt=''>")
(CMS:MEDIA image subfield=width ifempty="unbekannt")
```

### `prefix`

`prefix` / `suffix` umschließen den Wert und erscheinen nur, wenn er nicht leer ist -
so entsteht kein leeres `<figure></figure>`:

```cms
(CMS:MEDIA image prefix="<figure>" suffix="</figure>")
```

### `suffix`

```cms
(CMS:MEDIA image suffix="</figure>")
```

Siehe `prefix`.

### `useval`

Statt eines Feldes ein Datei-Array übergeben - z. B. aus einem mit `CMS:SAVEDATA`
gesicherten Datensatz. Parameterwerte im Template sind immer Strings; ein Array lässt
sich deshalb nur per direktem PHP-Aufruf der Methode übergeben. `field` darf dabei
**nicht** angegeben sein – es hätte Vorrang und läse das Feld des laufenden Datensatzes.
Weil das Datei-Array die Feld-Id nicht enthält, gehören `field_id` und `record_id`
ausdrücklich hinein:

```cms
(CMS:SAVEDATA author id="{$cms['author_id']}")
<?php $this->cms->cmsMedia([
    'useval' => $GLOBALS['author']['portrait'] + ['record_id' => $GLOBALS['author']['record_id'], 'field_id' => 17],
    'width'  => 80, 'height' => '',
]); ?>
```

`17` steht hier für die Id des Upload-Feldes `portrait`. Einfacher ist fast immer
`(CMS:MEDIA portrait id="{$cms['author_id']}")` - `id` lädt den fremden Datensatz
direkt und findet das Feld über seinen Namen.

### `set`

`set` gibt nichts aus, sondern legt das Ergebnis unter `$set['img']` für das
umgebende PHP oder einen späteren Befehl ab:

```cms
(CMS:MEDIA image subfield=path set=img)
<?php if ($set['img'] !== '') { ?> … <?php echo $set['img']; ?> … <?php } ?>
```

---

[← Übersicht](README.md)
