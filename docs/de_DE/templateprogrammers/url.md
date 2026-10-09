# CMS:URL

Erzeugt die URL eines Datensatzes.

```cmscode
(CMS:URL id [parameter=wert …])
```

***Methode:*** `Cms::cmsUrl()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `id`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `id` ¹ | `Datensatz-Id oder Slug` | `(aktueller Datensatz)` |  | Datensatz, auf den verwiesen werden soll. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL. |
| `area` | `Bereichsname oder -Id` | – |  | Verweis auf die Übersicht des Bereichs: die Adresse aus seinem Slug-Präfix (`/de/termine`), die sein Übersichts-Template zeigt. |
| `template` | `Templatename` | – |  | Template, mit dem der Datensatz gezeigt werden soll (zweiter URL-Teil nach dem Komma): Name oder bereich.name. Ohne Angabe das Template, das der Bereich zuweist. |
| `lang` | `Sprachkürzel` | `(aktuelle Sprache)` |  | Sprachversion, auf die verwiesen werden soll (standardmäßig die des laufenden Aufrufs). Führt die Instanz mehr als eine Sprache, steht das Kürzel immer in der URL; bei nur einer Sprache nie. Ein mehrsprachiger Slug wird in der Zielsprache eingesetzt. Ein Kürzel, das die Website nicht führt, wird übergangen. |
| `protocol` | `Übertragungsprotokoll` | `(Protokoll der Instanz)` |  | http oder https, nur mit absolute bzw. host. Ohne Angabe das Protokoll der Instanz, sonst das des Aufrufs. |
| `host` | `Hostname` | – |  | z. B. example.com für den Wechsel der Domain, über die die Website aufgerufen wird. |
| `absolute` | `true / false` | `false` |  | true: Der Pfad wird inkl. Protokoll und Domain erzeugt. false: Der Pfad ist serverrelativ und beginnt mit einem Slash. |
| `set` | `Array-Index von $set` | – |  | Die URL wird nicht ausgegeben, sondern in $set eingetragen. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Erzeugt die Frontend-URL eines Datensatzes - mit Slug, wenn er einen hat, sonst mit
der record_id; bei mehrsprachigen Auftritten immer mit Sprachsegment:

```cms
<a href="(CMS:URL)">(CMS:TEXT title)</a>
```
Ausgabe: `<a href="/de/hallo-welt">Hallo Welt</a>`

Auf eine andere Ansicht desselben Datensatzes verweisen (`template`), auf einen anderen
Datensatz (`id`) oder in eine andere Sprache (`lang`):

```cms
(CMS:URL template=print)                /de/hallo-welt,print
(CMS:URL 1977)                          /de/1977   (Datensatz ohne Slug)
(CMS:URL impressum lang=en)             /en/legal-notice   (Slug der Zielsprache)
(CMS:URL farben/blau)                   /de/farben/blau   (Bereich mit Slug-Prefix)
```

Führt der Inhaltsbereich des Datensatzes einen ***Slug-Prefix*** (Einstellung des
Bereichs, z. B. `farben`), steht er vor dem Slug: `/de/farben/blau`. Der Präfix gilt
je Sprache – mit `lang` also der Präfix der Zielsprache. Ein `id` mit Slug meint den
Pfad, wie er in der Adresse steht (`id=farben/blau`); `id=blau` findet nur einen
Datensatz eines Bereichs **ohne** Präfix.

Sprachumschalter - in einem `CMS:SELECT_LANGVERSION`-Listentemplate. Die Zeilen dort
sind Sprachen, keine Datensätze; `CMS:URL` ohne `id` verweist deshalb auf den
Datensatz der Seite:

```cms
<a href="(CMS:URL lang="{$cms['code']}")">(CMS:TEXT endonym)</a>
```

Absolute URL für Feeds, E-Mails oder `og:url`:

```cms
<link rel="canonical" href="(CMS:URL absolute=true)">
```

## Parameter im Einzelnen

### `id`

Datensatz, auf den verwiesen wird; ohne Angabe der laufende. Darf ohne Schlüssel stehen:

```cms
(CMS:URL 1977)
(CMS:URL id=impressum)
(CMS:URL "{$cms['parent_id']}")       Verweis auf den Elternknoten
```

### `area`

Verweist auf die ***Übersicht*** eines Bereichs: die Adresse, die nur aus seinem
Slug-Präfix besteht, in der Sprache des Aufrufs bzw. aus `lang`. Sie zeigt das
Übersichts-Template des Bereichs (Einstellung des Bereichs, „Template-Zuweisung“):

```cms
<a href="(CMS:URL area=termine)">Alle Termine</a>        /de/termine
(CMS:URL area=termine lang=en)                         /en/events
```

Hat der Bereich keinen Slug-Präfix, bleibt die Ausgabe leer. Im Übersichts-Template
selbst gibt es keinen laufenden Datensatz; `CMS:URL` ohne `id` verweist dort auf
dieselbe Übersicht – ein Sprachumschalter mit `CMS:SELECT_LANGVERSION` funktioniert
deshalb wie auf jeder Datensatzseite.

### `template`

Zweiter URL-Teil (nach dem Komma): das Template, mit dem der Datensatz gezeigt werden
soll - Name oder `bereich.name`:

```cms
(CMS:URL template=print)
(CMS:URL template=artikeltemplates.print)
```
Ausgabe: `/de/hallo-welt,print`

### `lang`

Sprachversion, auf die der Link zeigt (Kürzel aus der Sprachverwaltung); ohne Angabe
die Sprache des laufenden Aufrufs:

```cms
(CMS:URL lang=en)
```
Führt der Auftritt nur eine Sprache, steht nie ein Sprachsegment in der URL. Ein Kürzel,
das die Website nicht führt, wird übergangen – die URL behält die Sprache des Aufrufs.

Ist das Feld `slug` des Bereichs **mehrsprachig**, hat jede Sprachversion ihren eigenen
Slug - und die Ziel-URL muss den Slug der Zielsprache tragen, denn aufgelöst wird er in
der Sprache der URL. `CMS:URL` setzt ihn deshalb bei `lang` selbst ein:

```cms
Seite /de/esstisch-nord-180

(CMS:URL lang=en)        /en/dining-table-nord-180
(CMS:URL lang=sv)        /sv/matbord-nord-180
```

Ist der Slug in der Zielsprache nicht übersetzt, steht dort der Slug der Hauptsprache.
Führt die Instanz des Datensatzes die Zielsprache nicht, gilt ihre Hauptsprache. Findet
sich kein Slug, steht die record_id in der URL (bei `canonical = 1` leitet die Seite
von dort auf den Slug um). Je Datensatz und Sprache kostet das eine Datenbankabfrage,
die für den Aufruf gemerkt wird; ohne `lang` oder bei einem einsprachigen Slug-Feld
keine.

### `protocol`

Nur zusammen mit `absolute` bzw. `host`:

```cms
(CMS:URL absolute=true protocol=https)
```
Ausgabe: `https://www.example.com/de/hallo-welt`

### `host`

Andere Domain; impliziert `absolute=true`:

```cms
(CMS:URL host=shop.example.com)
```
Ausgabe: `https://shop.example.com/de/hallo-welt` (Protokoll der Instanz)

### `absolute`

Vollständige URL mit Protokoll und Haupt-Domain der Instanz:

```cms
<link rel="canonical" href="(CMS:URL absolute=true)">
```
Ausgabe: `<link rel="canonical" href="https://www.example.com/de/hallo-welt">`

### `set`

`set` gibt nichts aus, sondern legt das Ergebnis unter `$set['url']` für das
umgebende PHP oder einen späteren Befehl ab:

```cms
(CMS:URL  set=url)
<?php if ($set['url'] !== '') { ?> … <?php echo $set['url']; ?> … <?php } ?>
```

---

[← Übersicht](README.md)
