# CMS:INCLUDE

Bindet ein Template ein.

```cms
(CMS:INCLUDE template)
(CMS:INCLUDE template caching=5m cacheparams=$params)
```

***Methode:*** `Cms::cmsInclude()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `template`  
***PHP-Schnipsel:*** `include_cached` (danach)

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `template` ¹ | `Templatename` | – | ja | Detailtemplate, das eingebunden wird. |
| `set` | `Array-Index von $set` | – |  | Die gesamte Ausgabe (samt eingebundenem Template) wird nicht ausgegeben, sondern in der Variable $set gespeichert. |
| `caching` | Sekunden, `30s`, `5m`, `1h`, `1d` oder `infinite` | – |  | Die Ausgabe des Templates wird so lange zwischengespeichert und danach ohne erneute Abarbeitung ausgeliefert. Ohne Angabe, leer oder `0`: kein Cache. |
| `cacheparams` | `null`, `bool`, `int`, `string` oder Array | – |  | Alles, wovon die Ausgabe abhängt (Datensatz, Seite, Kunde …): je Wert entsteht eine eigene Cache-Datei. Instanz und Sprache zählen von selbst mit. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Bindet ein anderes Template an dieser Stelle ein - für Kopf- und Fußbereiche,
Navigationen, wiederkehrende Blöcke:

```cms
(CMS:INCLUDE header)
<main>
  <h1>(CMS:TEXT title)</h1>
  (CMS:INCLUDE text_image_block)
</main>
(CMS:INCLUDE footer)
```

Das eingebundene Template sieht denselben Datensatz (`$cms`), dieselben `$set`-Werte
und, innerhalb einer Ergebnisschleife, dieselbe Zeile. Ein Name ohne Bereich muss im
Zugriffsbereich der Instanz eindeutig sein, sonst `bereich.name`:

```cms
(CMS:INCLUDE templates.header)
```

Die Verschachtelungstiefe ist durch `admin.max_include_depth` (Standard 20) begrenzt -
ein Template, das sich selbst einbindet, bricht mit einer Fehlermeldung ab.

## Parameter im Einzelnen

### `template`

Name oder `bereich.name` (keine Id); darf ohne Schlüssel stehen. Im Backend-Editor ist der Wert ein Link auf das Template:

```cms
(CMS:INCLUDE template=header)
(CMS:INCLUDE header)
(CMS:INCLUDE templates.header)
(CMS:INCLUDE "{$set['layout']}")        dynamisch
```

### `set`

Die Ausgabe des eingebundenen Templates landet in `$set` statt auf der Seite – etwa um
einen Baustein zweimal zu verwenden oder an einen anderen Befehl weiterzugeben:

```cms
(CMS:INCLUDE preis set=preis)
<p class="preis"><?php echo $set['preis']; ?></p>
<meta property="product:price" content="(CMS:TEXT useval="{$set['preis']}" striptags=true specialchars=true)">
```

Mit `caching` kommt auch der abgefangene Text aus dem Cache.

### `caching`

Schaltet den ***Ausgabe-Cache*** für diesen Aufruf ein: Beim ersten Aufruf läuft das
Template wie gewohnt, seine Ausgabe wird unter `var/cache/include/` abgelegt. Jeder
weitere Aufruf mit denselben Angaben liefert innerhalb der Frist diese Datei aus – das
Template, seine Abfragen und eingebundenen Templates laufen dann nicht.

| Wert | Frist |
|---|---|
| `300` oder `300s` | 300 Sekunden |
| `5m` | 5 Minuten |
| `1h` | 1 Stunde |
| `1d` | 1 Tag |
| `infinite` | unbegrenzt – bis das Template geändert wird |
| leer oder `0` | kein Cache (etwa um ihn per Variable abzuschalten) |

```cms
(CMS:INCLUDE navigation caching=1h)
(CMS:INCLUDE footer caching=1d)
(CMS:INCLUDE teaser caching="{$set['cache_time']}")
```

Ein ungültiger Wert (`caching=5x`) erscheint als Fehlermeldung; das Template läuft dann
ohne Cache.

### `cacheparams`

Der Cache unterscheidet von selbst nach ***Website*** und ***Sprache***. Hängt die
Ausgabe von weiteren Dingen ab – dem Datensatz, der Seite einer Liste, einem Filter, dem
angemeldeten Kunden –, gehören sie in `cacheparams`. Für jeden anderen Wert entsteht
eine eigene Cache-Datei.

Erlaubt sind `null`, `true`/`false`, Zahlen, Texte und Arrays. Eine Variable ohne
Anführungszeichen wird unverändert übergeben, auch ein Array:

```cms
<?php $params = ['id' => $cms['record_id'], 'seite' => (int)($_GET['skip'] ?? 0)]; ?>
(CMS:INCLUDE produktliste caching=10m cacheparams=$params)
(CMS:INCLUDE produkt_box caching=1h cacheparams=$cms['record_id'])
(CMS:INCLUDE produkt_box caching=1h cacheparams="produkt-{$cms['record_id']}")
```

Arrays werden als Ganzes verglichen – dieselben Schlüssel in anderer Reihenfolge ergeben
eine andere Cache-Datei.

### Was der Cache beachtet

* Wird das eingebundene Template ***geändert***, gelten seine Cache-Dateien sofort nicht
  mehr. Ändern sich dagegen ***Inhalte*** im Backend, sieht der Besucher sie erst nach
  Ablauf der Frist. Wählen Sie die Frist danach, wie schnell Änderungen sichtbar werden
  müssen.
* Ein Template, das selbst Templates einbindet, legt deren Ausgabe mit ab. Ändert sich
  ein solches inneres Template, bleibt die gecachte Ausgabe bis zum Ablauf der Frist.
* Gespeichert wird nur die ***Ausgabe***. Was das Template sonst tut – Werte in `$set`
  setzen, `CMS:HEADER`, `CMS:CSP`, Cookies, E-Mails –, geschieht bei einem Treffer nicht.
  Solche Befehle gehören ins aufrufende Template.
* Die Nonce der Script-Policy (`CMS:NONCE`) ist in jeder Auslieferung die aktuelle.
* Formulare mit Sicherheits-Token (`CMS:USER_TOKEN`, `CMS:USER_INPUT`) und persönliche
  Inhalte angemeldeter Kunden nicht cachen – oder den Kunden in `cacheparams`
  aufnehmen.
* Meldet ein Befehl beim Erzeugen einen Fehler, wird die Ausgabe nicht abgelegt.
* In der ***Vorschau*** aus dem Backend und bei eingeschaltetem ***Frontend-Editing***
  gilt kein Cache.
* Rufen viele Besucher gleichzeitig ein abgelaufenes Template auf, erzeugt es nur einer;
  die anderen warten kurz und bekommen dann das Ergebnis.

Aufgeräumt werden abgelaufene Dateien von der Zeitplaner-Aufgabe
***„Frontend-Cache aufräumen“***; ganz geleert wird der Cache mit
`php Core/cron/camindo.php cache include`. Beides richtet der Systemadministrator ein.

---

[← Übersicht](README.md)
