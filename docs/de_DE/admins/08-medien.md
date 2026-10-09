# 8 Medien: Uploads, Konverter und Thumbnails

camindo CMS nimmt Dateien über ***Upload-Felder*** entgegen und erzeugt daraus
automatisch die Versionen, die die Website braucht: verkleinerte Bilder in mehreren
Größen, Vorschaubilder, web-taugliche Videos, Standbilder aus Videos, komprimierte
Audiodateien. Drei Begriffe sind dabei wichtig:

| Begriff | Bedeutung |
|---|---|
| ***Prozessor*** | ein Programmbaustein, der Dateien umwandeln kann – etwa „GD Bibliothek“ oder „FFmpeg (Video)“ |
| ***Konverter*** | ein eingestellter Prozessor: *welche* Dateien er *wie* umwandelt – etwa „Bild 800 px breit, JPEG, Qualität 80“ |
| ***Thumbnail-Feld*** | ein Feld, das mit einem oder mehreren Konvertern aus der Datei eines anderen Felds eine Version erzeugt |

## Prozessoren

Mit dem Core werden ausgeliefert:

| Prozessor | Aufgabe | Voraussetzung |
|---|---|---|
| GD Bibliothek | Bilder skalieren, zuschneiden, umwandeln – Basisfunktionen ohne Zusatzsoftware | PHP-Erweiterung `gd` |
| ImageMagick | Bilder in sehr vielen Formaten (auch HEIC, TIFF, PSD), Expertenmodus mit eigenen Parametern, auch im Hintergrund | ImageMagick auf dem Server |
| FFmpeg (Video) | Videos umwandeln, skalieren, komprimieren | ffmpeg |
| FFmpeg (Audio) | Audiodateien umwandeln, Lautheit angleichen, Ausschnitte | ffmpeg |
| FFmpeg (Vorschaubild) | Standbild aus einem Video | ffmpeg |

Weitere Prozessoren lassen sich als [Komponenten](./10-module.md#komponenten)
nachinstallieren. Ob ImageMagick und ffmpeg gefunden werden, zeigt das Modul
„Werkzeuge“ auf dem Reiter „Server“.

## Konverter einrichten

Konverter legen Sie im Modul „Medienkonverter“ an:

| Einstellung | Bedeutung |
|---|---|
| Prozessor | welcher Prozessor die Arbeit macht |
| Label | Name des Konverters |
| Eingeschlossene Medientypen | auf welche Dateien der Konverter anspringt: MIME-Typ (`image/jpeg`), Gruppe (`image/*`) oder Endung (`.pdf`), eine Angabe je Zeile |
| Ausgeschlossene Medientypen | Ausnahmen, etwa `image/gif`, damit animierte GIFs unverändert bleiben |
| Dateiendung | Format der erzeugten Datei, z. B. `.jpg` oder `.mp4`; leer = Format beibehalten |
| Konvertierungsoptionen | je nach Prozessor: Breite und Höhe, Einpassen oder Zuschneiden, Größe nur verkleinern bzw. vergrößern, Qualität, Hintergrundfarbe, Codec, Datenrate, Ausschnitt, Metadaten entfernen, Umwandlung im Hintergrund … |

***Größe bei ImageMagick und der GD Bibliothek:*** Neben „Zielbreite“ und
„Zielhöhe“ legen vier Einstellungen fest, wie das Bild auf diese Größe kommt:

| Einstellung | Auswahl | Wirkung |
|---|---|---|
| Anpassung an das Format | Als Ganzes in das Format einpassen | das ganze Bild passt hinein, eine Seite kann kürzer sein |
| | Format ganz ausfüllen und beschneiden | füllt das Format, Überstehendes wird mittig abgeschnitten |
| | Format ganz ausfüllen, überstehen lassen | füllt das Format, eine Seite kann länger sein (nichts wird abgeschnitten) |
| | Genau auf das Format bringen, Seitenverhältnis ignorieren | Breite und Höhe exakt – das Bild wird dabei verzerrt |
| Größe ändern | Immer / Nur verkleinern / Nur vergrößern | „Nur verkleinern“: Kleinere Bilder behalten ihre Größe – ein Thumbnail mit 440 px Breite wird aus einem 300 px breiten Bild nicht hochgerechnet. „Nur vergrößern“: Größere Bilder behalten ihre Größe |
| Einheit | Pixel / Prozent des Originals | mit Prozent sind Zielbreite und -höhe Prozentwerte, z. B. `50` für die halbe Größe (nur mit Einpassen oder Verzerren) |
| Höchstzahl Pixel | Zahl | skaliert proportional, bis Breite × Höhe diesem Wert entspricht, z. B. `1000000` für ein Megapixel; wirkt nach Zielbreite und -höhe und folgt ebenfalls „Größe ändern“ – mit „Nur verkleinern“ werden nur größere Bilder verkleinert, mit „Immer“ auch kleinere vergrößert |

Ausfüllen, Beschneiden und Verzerren brauchen beide Maße; fehlt eines, wird eingepasst.
Beschneiden mit „Nur verkleinern“ schneidet kleinere Bilder nur mittig auf das
Zielformat zu, soweit das Bild reicht – einen Rand in der Hintergrundfarbe gibt es nicht.

***Abweichungen bei der GD Bibliothek:*** Statt „Anpassung an das Format“ gibt es dort
die Einstellung „Beschneiden“ mit nur zwei Auswahlen: „Als Ganzes in neues Format
einpassen“ und „Neues Format ganz ausfüllen“ (beschneidet mittig). „Größe ändern“,
„Einheit“ und „Höchstzahl Pixel“ wirken wie bei ImageMagick; Prozent gilt nur
ohne Beschneiden. Ein Prozentwert gilt für beide Seiten, zwei unterschiedliche
Prozentwerte skalieren Breite und Höhe getrennt.

Weitere Einstellungen:

| Einstellung | Auswahl | Wirkung |
|---|---|---|
| Qualität | Prozent | Qualität bei komprimierten Formaten wie JPEG; 100 % = beste Qualität, aber größte Datei |
| Anzahl Farben | Zahl oder `TrueColor` | Die Formate GIF und PNG erlauben eine reduzierte Farbpalette (höchstens 256 Farben). Die Anzahl kann hier angegeben werden, z. B. `2`, `8` oder `128`. `TrueColor`: Farbpalette nicht reduzieren |
| Transparenz | Alpha-Kanal erhalten | eingeschaltet: transparente Bereiche bleiben transparent; ausgeschaltet: sie werden mit der Hintergrundfarbe gefüllt |
| Hintergrundfarbe | Hexadezimalwert, z. B. `#ffffff` | ersetzt die Transparenz, wenn der Alpha-Kanal nicht erhalten bleibt |

Die Detailansicht eines Konverters zeigt auf dem Reiter „Verwendet von …“, welche
Thumbnail-Felder ihn nutzen. Wird ein verwendeter Konverter gelöscht, erzeugen diese
Felder keine Versionen mehr – die Rückfrage warnt davor.

> ***PDF und SVG mit ImageMagick:*** camindo CMS lässt ImageMagick nur mit einer eigenen
> Sicherheits-Policy arbeiten. SVG und PDF sind darin gesperrt, bis Sie sie in
> `.camindo.php` freigeben:
>
> ```php
> 'security' => [
>     'magick_allow' => ['svg'],          // oder ['svg', 'pdf']
> ],
> ```
>
> PDF liest ImageMagick über Ghostscript, das wiederholt Einfallstor für Angriffe über
> präparierte Dateien war – geben Sie es nur frei, wenn Sie es brauchen. Ob ein Format
> wirklich läuft, zeigt die Detailansicht des Konverters unter „Sicherheits-Policy“:
> Auch die Policy des Servers kann es sperren. PDF ist bei vielen Providern gesperrt,
> das können Sie in camindo CMS nicht ändern. Mehr dazu:
> [Betrieb – ImageMagick absichern](./12-betrieb.md#imagemagick-absichern).

## Thumbnail-Felder

Ein Thumbnail-Feld (Feldtyp „Dateiversion (Thumbnail)“) hat eine ***Quelle*** – ein
Upload-Feld oder ein anderes Thumbnail-Feld – und eine sortierte Liste von
***Konvertern***. Für jede neue Datei geht camindo CMS die Konverter der Reihe nach
durch; der erste, dessen Medientypen passen, erzeugt die Version. So kann ein Feld
„vorschau“ für Bilder einen Bildkonverter und für Videos einen Standbild-Konverter
verwenden.

Thumbnail-Felder lassen sich **verketten**. Beispiel für ein Video:

```
video                  Upload-Feld
 ├── video_web         FFmpeg (Video): MP4/H.264, 1920 px, im Hintergrund
 └── video_standbild   FFmpeg (Vorschaubild): Standbild bei 10 % der Laufzeit
      └── video_teaser ImageMagick: 400 × 225 px zugeschnitten, JPEG
```

Die Versionen entstehen beim Speichern des Datensatzes, einmal – nicht bei jedem
Zwischenspeichern. Bilder werden sofort umgewandelt; Videos und andere aufwändige
Umwandlungen laufen als Hintergrundprozess (dafür muss der [Cronjob](./12-betrieb.md#cronjobs)
laufen). Bis dahin zeigt das Backend ein Platzhalterbild.

> Das Platzhalterbild bleibt auch nach Abschluss des Hintergrundprozesses in der Ansicht
> stehen – die Ansicht wird erst durch Klick auf die Schaltfläche „Aktualisieren“ erneuert.
>
> Der Prozessor „GD Bibliothek“ arbeitet nicht im Hintergrund. Soll eine Version aus einer Datei
> entstehen, die selbst erst im Hintergrund erzeugt wird (etwa aus dem Standbild eines Videos),
> verwenden Sie ImageMagick.

### Thumbnails neu erzeugen

Ändern Sie die Einstellungen eines Konverters oder fügen Sie einem Bereich ein neues
Thumbnail-Feld hinzu, gilt das zunächst nur für neue Uploads. Für den Bestand erzeugt
„Werkzeuge“ → „Daten“ → „Thumbnails neu erzeugen“ die Versionen aller Datensätze eines
Bereichs neu – bei vielen Datensätzen im Hintergrund.

## Upload-Felder

| Einstellung | Bedeutung |
|---|---|
| Erlaubte Dateitypen | MIME-Typen oder Endungen, eine Angabe je Zeile; leer = alle zulässigen |
| Mehrsprachig | je Sprache eine eigene Datei, etwa ein Prospekt je Sprache |
| Nur für angemeldete Frontend-User | Datei nur an angemeldete Frontend-User ausliefern (siehe [Kapitel 13](./13-community.md#geschützte-dateien)) |

Aus Sicherheitsgründen sind ausführbare Dateien (`.php`, `.phtml`, `.cgi`, `.sh`,
`.htaccess` u. a.) immer verboten, und camindo CMS prüft, ob der Inhalt einer Datei zu
ihrer Endung passt – ein als `.jpg` getarntes anderes Format wird abgewiesen.

### Große Dateien: das Upload-Verzeichnis

Die Größe eines Uploads über den Browser begrenzt die PHP-Konfiguration des Servers
(`upload_max_filesize`, `post_max_size`). Größere Dateien – etwa Videos – übertragen
Sie per FTP oder SCP in das Verzeichnis `var/upload/`. In der Eingabemaske und beim
Import wählen Redakteure sie über ![folder_open](../_/folder_open.svg) „Datei auf dem Server auswählen“,
die Schaltfläche rechts an jedem Upload-Feld.


## Ablage und Auslieferung

Dateien liegen unter `public/media/` in der Struktur
`{instanz}/{feld}/{datensatz}.{endung}`. Die Adressen, die die Templates erzeugen,
tragen zusätzlich den ursprünglichen Dateinamen, etwa
`/media/1/12/1977,strand-nord.jpg` – gut für Suchmaschinen und für Besucher, die
eine Datei speichern.

- ***Domain-Sperre:*** Die Dateien einer Instanz werden nur über deren Domains (und die
  Domains der Instanzen, die sie lesen dürfen) ausgeliefert. Das verhindert, dass
  Dateien versehentlich über eine fremde Website verlinkt oder angezeigt werden.
  Eine Zugriffskontrolle ist es nicht (siehe Hinweis unten).
- ***Papierkorb:*** Wird ein Datensatz gelöscht, wandern seine Dateien mit in den
  Papierkorb und kommen bei einer Wiederherstellung zurück.
- ***Geschützte Felder:*** Dateien aus Feldern mit „Nur für angemeldete Frontend-User“ sind im
  Medienverzeichnis gesperrt und werden nur nach einer Prüfung ausgeliefert.

> ***Wichtig: Die Domain-Sperre ist kein Zugriffsschutz.***
> Welche Domain ein Request anspricht, entscheidet der Aufrufer: Er kann den
> Hostnamen frei setzen (Header `Host`). Wer die Adresse einer Datei kennt, kann sie
> deshalb auch dann abrufen, wenn er sich als eine andere, berechtigte Domain
> derselben Installation ausgibt. Damit sind auch Dateien einer anderen Instanz
> auslesbar.
> Einen vollständigen Schutz erreichen Sie nur mit getrennten camindo-Installationen;
> vgl. Abschnitt [Keine Mandantenfähigkeit](./03-instanzen-und-sprachen.md#keine-mandantenfähigkeit).
>
> ***Vertrauliche Dateien*** schützt ausschließlich die Feldoption „Nur für
> angemeldete Frontend-User“. Das Verzeichnis des Feldes ist dann auf dem Server
> vollständig gesperrt (`Require all denied`). Ausgeliefert wird nur über
> `file.php`, und erst nachdem eine gültige Session eines Frontend-Users geprüft wurde (siehe
> [Kapitel 13](./13-community.md#geschützte-dateien)).
