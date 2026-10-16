# 1 Grundlagen der Template-Programmierung

## Was ein Template ist

Ein ***Template*** ist ein Textdokument – meist HTML –, in dem ***CMS-Befehle*** stehen.
Die Befehle fragen Datensätze ab, setzen Inhalte ein, binden andere Templates ein,
werten Formulare aus und steuern den Ablauf. Dazwischen darf PHP-Code stehen.

```cms
(CMS:INCLUDE header)
<h1>(CMS:TEXT title)</h1>
<div>(CMS:TEXT text)</div>
<div>(CMS:MEDIA field=bild_klein attr[id]=bild1)</div>
<div>erstellt: (CMS:DATE created_at format="dd.MM.y")</div>
(CMS:INCLUDE footer)
```

Beim Speichern übersetzt camindo CMS das Template in PHP. Zur Laufzeit wird nur noch
dieses PHP ausgeführt – es braucht keine Template-Engine, die den Text bei jedem Aufruf
neu auswertet.

## Wie eine Seite entsteht

Ruft ein Besucher eine Adresse auf, ermittelt camindo CMS:

1. die ***Instanz*** – aus der Domain,
2. die ***Sprache*** – aus dem Sprachkürzel der Adresse, sonst aus der
   Spracheinstellung des Browsers, sonst die Hauptsprache,
3. den ***Datensatz*** – aus dem Slug oder der Nummer (`record_id`) in der Adresse; ohne Angabe den
   Start-Datensatz der Instanz,
4. das ***Template*** – aus der Adresse, aus dem Datensatz oder
   das Standard-Template seines Inhaltsbereichs.

Die Inhalte eines aufgerufenen Datensatzes stehen im Template zur Verfügung. Standardmäßig
beziehen sich alle Ausgabe-Befehle auf diesen Datensatz – vgl. oben `(CMS:TEXT title)`. Führt
das Template eine Abfrage mittels `CMS:SELECT` durch, stehen die Inhalte der ermittelten
Datensätze in gleicher Weise im Ergebnis-Template zur Verfügung; siehe unten [Listen, Select, Ergebnisse](#listen-select-und-die-ergebnisschleife).

### Adressen

```
[/{sprache}]/{slug oder nummer}[,{template}]
```

| Adresse | Bedeutung |
|---|---|
| `/` | Startseite der Instanz |
| `/betriebsfeier-2026` | Datensatz mit diesem ***Slug***, Standard-Template |
| `/termine/betriebsfeier-2026` | Datensatz mit dem Slug `betriebsfeier-2026` im Bereich mit dem ***Slug-Prefix*** `termine` |
| `/termine` | Übersicht des Bereichs mit dem Slug-Prefix `termine` – sein Übersichts-Template, ohne Datensatz |
| `/1758` | Datensatz Nummer 1758 |
| `/en/summer-party-2026` | englische Sprachversion |
| `/betriebsfeier-2026,druck` | Datensatz mit dem Template `druck` – nur wenn es für den Bereich als URL-Override freigegeben ist |
| `/0,suche` | Template `suche` ohne Datensatz – nur wenn es als „Standalone“ markiert ist |

Reine Ziffernfolgen sind immer Datensatznummern, alles andere ist ein Slug. Ist der
erste Teil der Adresse der Slug-Prefix eines Inhaltsbereichs, wird der Rest nur in
diesem Bereich gesucht; sonst der ganze Pfad in den Bereichen ohne Präfix. Das
Sprachkürzel steht in der Adresse, sobald die Instanz mehr als eine Sprache führt.
Adressen erzeugen Sie mit [CMS:URL](url.md) – nie von Hand, dann stimmen Sprache,
Slug und Domain immer.

Besteht die Adresse nur aus dem Sprachkürzel (`/en`, ohne Schrägstrich am Ende),
leitet camindo CMS auf die ***Startseite der Sprache*** um (`/en/` oder `/en_US/` – je
nachdem, wie diese eingerichtet ist). Führt der Auftritt nur eine Sprache und hat ein
Datensatz genau diesen Slug, wird stattdessen der Datensatz gezeigt. Adressen, unter
denen es nichts gibt – unbekannter Datensatz, unbekannte Sprache, nicht freigegebenes
Template –, antworten mit dem Status ***404***.

## Syntax der CMS-Befehle

```cmscode
(CMS:BEFEHL wert parameter=wert parameter[schlüssel]="wert" …)
```

- Befehlsnamen und Parameternamen sind unabhängig von Groß- und Kleinschreibung.
- Parameter sind durch Leerzeichen getrennt; ein Befehl darf über mehrere Zeilen
  gehen.
- Die Reihenfolge, in der Parameter verarbeitet werden, bestimmt jeder Befehl selbst,
  nicht die Reihenfolge, in der die Parameter geschrieben werden.
- Der erste Wert ohne `=` gilt als ***Hauptparameter*** des Befehls:
  `(CMS:TEXT titel)` ist dasselbe wie `(CMS:TEXT field=titel)`.
- Werte, die Leerzeichen enthalten, stehen in Anführungszeichen, einfache oder doppelte:
  `(CMS:TEXT field="title")` entspricht `(CMS:TEXT field='title')`.
- ***Unterschlüssel*** in eckigen Klammern: `filter[area]=artikel`. Leere Klammern
  hängen an eine Liste an: `sort[]=datum sort[]=titel`. Wird derselbe Unterschlüssel
  mehrfach angegeben, entsteht ebenfalls eine Liste (`[]` ist im Gegensatz zur PHP-Syntax
  für Arrays optional).
- Statt `=` darf ein ***Vergleichsoperator*** zwischen Parameter und Wert stehen –
  `==`, `!=`, `!`, `!!`, `<`, `>`, `<=`, `>=`, `<<`, `>>`:
  `filter[preis] >= 100` ist dasselbe wie `filter[preis]=">=100"`. Der Wert gilt dann
  unverändert, ein Operator an seinem Anfang wird nicht mehr ausgewertet:
  `filter[code] < ">2"` vergleicht mit dem Text „>2“. Bei `=` bleibt es wie gehabt –
  `filter[code] = ">2"` bedeutet „größer als 2“ (siehe [Filter-Syntax](#filter-syntax)).
- Der Zeilenumbruch hinter einem CMS-Befehl wird geschluckt, analog zu einem `<?php … ?>`-Block.
- Befehlsparameter in doppelten Anführungszeichen dürfen PHP-Variablen enthalten: `attr[alt]="{$myvar}"`
  (Parameter ohne Anführungszeichen werden intern wie Parameter mit doppelten Anführungszeichen behandelt).

Fehler – ein unbekannter Befehl, ein unbekannter Parameter, eine fehlende Klammer –
verhindern das Speichern nicht. Die Detailansicht des Templates zeigt sie mit
Zeilennummer an. Sie erscheinen auch auf der Startseite im Widget „Template-Meldungen“ – für den
Fall, dass Templates über das Dateisystem bearbeitet werden.

## CMS-Befehl und PHP-Code kombinieren

***$set*** `<?php … ?>`-Blöcke dürfen frei im Template stehen, CMS-Befehle innerhalb von PHP-Blöcken
führen allerdings zu Fehlern! Um Inhalte aus CMS-Befehlen in PHP-Blöcken zu verwenden,
nutzen Sie den Parameter `set` (etwa `set = myvar`) und greifen den Inhalt im PHP-Block aus `$set`
(`$set['myvar']`) auf.

***$cms*** Inhalte aus dem aufgerufenen Datensatz stehen in `$cms`; ebenso Inhalte aus Datensätzen, die mittels
`CMS:SELECT` ermittelt werden.

> Für die ***Ausgabe*** sollten immer die CMS-Ausgabebefehle (etwa `CMS:TEXT`) verwendet werden.
> Diese Befehle kümmern sich um korrekte Formatierung, ein `<?php echo $cms[…] ?>` um nichts davon.
> Verwenden Sie `$cms` nur dort, wo ein Befehl einen Wert als Parameter braucht, etwa
> `filter[kategorie]="{$cms['record_id']}"`.


### Gemeinsame Parameter der Ausgabebefehle

| Parameter | Wirkung |
|---|---|
| `ifempty` | Ersatztext, wenn der Wert leer ist |
| `instead` | Ersatztext, wenn der Wert *nicht* leer ist |
| `prefix`, `suffix` | Text davor bzw. dahinter – nur wenn der Wert nicht leer ist |
| `set` | den Wert nicht ausgeben, sondern in `$set[…]` ablegen |
| `id` | aus einem anderen Datensatz lesen (Nummer oder Slug), z. B. `(CMS:TEXT titel id=impressum)` – nur veröffentlichte Datensätze, wie in der URL |
| `replace` | Teiltexte ersetzen (`replace="alt?neu"`) – bei TEXT, NUMBER, DATE, DATETIME, TIME |

```cms
(CMS:TEXT untertitel prefix="<h2>" suffix="</h2>")
(CMS:NUMBER preis format="%.2f" decimal="," suffix=" €" ifempty="auf Anfrage")
```

### Werte in Attributen und Javascript

camindo CMS entfernt aus allen Datensatz-Inhalten Script, Event-Handler (`onclick` …),
`iframe`, `object`, `embed` und `javascript:`-Links – beim Speichern und noch einmal bei
der Ausgabe. HTML-Formatierung bleibt, deshalb gibt `CMS:TEXT` Inhalte **unmaskiert** aus.

Setzen Sie einen Wert in ein ***HTML-Attribut*** ein, maskieren Sie ihn mit `specialchars=true`
– ein Anführungszeichen im Inhalt beendete sonst das Attribut:

```cms
<img src="…" alt="(CMS:TEXT title specialchars=true)">
<a href="(CMS:URL)" title="(CMS:TEXT teaser specialchars=true striptags=true lbrconvert=false)">…</a>
```

Dasselbe gilt für Formulareingaben, die Sie nach dem Absenden wieder ins Feld setzen:

```cms
<input name="data[nickname]" value="(CMS:TEXT useval="{$_POST['data']['nickname']}" specialchars=true)">
```

***Befehle, die selbst maskieren:*** Einige Befehle maskieren ihre Ausgabe bereits mit
`htmlspecialchars`. Dort entfällt `specialchars` – ein bereits maskierter Wert würde
doppelt maskiert, aus `&` würde sichtbar `&amp;`:

| Befehl | maskiert |
|---|---|
| [CMS:MEDIA](media.md) | alle Attribute des `<img>`-Tags: `src`, `alt`, `width`, `height` und `attr[…]` – nicht die Einzelwerte über `subfield` |
| [CMS:USER_TOKEN](user-token.md) | den Wert des versteckten Feldes |
| [CMS:USER_PASSWORD_RULES](user-password-rules.md) | die gesamte Ausgabe (JSON für ein Attribut) |
| [CMS:XML](xml.md) | `version` und `encoding` |
| [CMS:JUMP_LIST](jump-list.md), [CMS:JUMP_PREV](jump-prev.md), [CMS:JUMP_NEXT](jump-next.md) | die URL in `{href}` |

Was Sie einem dieser Befehle übergeben, muss deshalb ***unmaskierter Text*** sein –
ohne HTML-Tags und Entities:

```cms
(CMS:TEXT title striptags=true ampconvert=false lbrconvert=false set=title)
(CMS:MEDIA bild attr[alt]="{$set['title']}")
```

***Nie maskiert*** werden `prefix`, `suffix`, `ifempty` und `instead` – sie stammen aus
dem Template und dürfen HTML enthalten. `specialchars` kennen nur
[CMS:TEXT](text.md) und [CMS:SNIPPET](snippet.md). Werte anderer Befehle, etwa
`CMS:MEDIA … subfield=filename`, [CMS:RELATION](relation.md) oder [CMS:RAW](raw.md),
legen Sie für ein Attribut mit `set` ab und geben sie über `CMS:TEXT` maskiert aus:

```cms
(CMS:MEDIA dokument subfield=filename set=dateiname)
<a href="(CMS:MEDIA dokument subfield=path)" title="(CMS:TEXT useval="{$set['dateiname']}" specialchars=true lbrconvert=false)">…</a>
```

`CMS:URL` und `CMS:MEDIA … subfield=path` erzeugen nur URL-taugliche Zeichen, ebenso
`CMS:TEXT … urlencode=true` – sie können ohne Maskierung in `href` und `src` stehen.

Braucht ***Javascript*** einen Wert, geben Sie ihn als `data-`-Attribut aus und lesen ihn
dort – so steht nie Inhalt im Script-Code selbst:

```cms
<div id="produkt" data-title="(CMS:TEXT title specialchars=true)"></div>
<script>var title = document.getElementById('produkt').dataset.title;</script>
```

## Listen: SELECT und die Ergebnisschleife

Listen entstehen immer aus zwei Templates: Das aufrufende Template fragt mit
[CMS:SELECT](select.md) ab und nennt ein ***Listentemplate***. Das Listentemplate
durchläuft die Treffer zwischen [CMS:RESULT_START](result-start.md) und
[CMS:RESULT_END](result-end.md).

Aufrufendes Template:

```cms
<h2>Aktuelle Meldungen</h2>
(CMS:SELECT meldungen_liste
  filter[area]=meldungen
  sort=datum
  order=desc
  limit=10
  noresult="<p>Keine Meldungen.</p>"
)
```

Listentemplate `meldungen_liste`:

```cms
<ul>
(CMS:RESULT_START)
  <li><a href="(CMS:URL)">(CMS:TEXT titel)</a> – (CMS:DATE datum)</li>
(CMS:RESULT_END)
</ul>
```

Innerhalb der Schleife ist die jeweilige Zeile der laufende Datensatz. Ein
Listentemplate darf selbst wieder `CMS:SELECT` enthalten – etwa die Schlagwörter
jedes Artikels.

***Blätterfunktion:*** `count=true` zählt alle Treffer; [CMS:JUMP_LIST](jump-list.md),
[CMS:JUMP_PREV](jump-prev.md) und [CMS:JUMP_NEXT](jump-next.md) erzeugen daraus die
Navigation.

***Veröffentlichung:*** Templates finden nur **veröffentlichte** Datensätze –
freigeschaltet, Veröffentlichungsdatum erreicht, Ablaufdatum nicht erreicht. Die
Parameter `future`, `archive` lockern das je Abfrage, etwa für ein Archiv
oder eine Terminvorschau.

> Der Parameter `state` ist ausschließlich für Backend-interne Ansichten vorgesehen.

### Bereiche ansprechen

Inhaltsbereiche werden über ihren ***Namen*** oder über ihre Nummer angesprochen (`area_id`):
`filter[area]=meldungen`, `filter[area]=12` oder `filter[area_id]=12`. Mehrere Bereiche
trennen Sie durch Komma. Weil Feldnamen systemweit einheitlich sind, lassen sich mehrere
Bereiche gemeinsam filtern und sortieren:

```cms
(CMS:SELECT zeitleiste
  filter[area]=meldungen,termine,blog
  filter[datum] >= 2026-01-01
  sort=datum
  order=desc
)
```

`sort=area` sortiert nach Bereich; `_default` steht in Filtern und Sortierung für das
Hauptfeld jedes Bereichs, egal wie es dort heißt.

### Übersichtsseite eines Bereichs

Die Adresse aus dem Slug-Prefix allein (`/termine`) ruft das ***Übersichts-Template***
des Bereichs auf – ohne Datensatz. An dessen Stelle stehen die Angaben des Bereichs:

| Wert | Inhalt |
|---|---|
| `area_id` | Nummer des Bereichs |
| `area` | Name des Bereichs |
| `area_label` | Beschriftung des Bereichs (Snippets in der Sprache des Aufrufs) |
| `area_type`, `area_icon` | Typ und Icon des Bereichs |
| `instance_id`, `language_id` | Instanz des Bereichs und Sprache des Aufrufs |
| `slug_prefix` | Slug-Prefix in der Sprache des Aufrufs |
| `record_id` | immer `0` – es gibt keinen laufenden Datensatz |
| `overview` | `true` |

So listet ein Übersichts-Template die Datensätze seines Bereichs, ohne dessen Namen
fest einzutragen – und lässt sich für mehrere Bereiche verwenden:

```cms
<h1>(CMS:TEXT area_label specialchars=true)</h1>
(CMS:SELECT termin_liste
  filter[area]="{$cms['area_id']}"
  sort=datum order=asc
)
```

### Verknüpfte Datensätze

Mit `relation` fragt `CMS:SELECT` die Datensätze ab, die mit dem laufenden (oder mit
`src_id` genannten) Datensatz über ein Verknüpfungsfeld verbunden sind.

```cms
(CMS:SELECT galerie_liste
  relation=see_also
  sort="created_at desc"
)
```

Mit `sort="_custom asc"` erscheinen verknüpfte Datensätze in der Reihenfolge, die die Redaktion
im Lookup-Feld (mittels der Pfeil-Schaltflächen) festgelegt hat:

```cms
(CMS:SELECT galerie_liste
  relation=bilder
  sort="_custom asc"
)
```

Nach Feldern verknüpfter Datensätze sortieren und filtern Sie mit der
Punkt-Schreibweise: `sort=autor.nachname`. 

Steht kein laufender Datensatz zur Verfügung und ist `src_id` nicht angegeben oder ungültig,
findet die Suche mit dem Parameter `relation` nichts.


## Das Konzept: Jeder Datensatz hat eine Seite

In camindo CMS hat ***jeder Datensatz eine eigene Seite*** im Frontend – nicht nur
Artikel und Produkte, sondern auch jede Kategorie, jedes Schlagwort, jedes Material.
Dafür braucht eine Website ***keine Parameter*** wie `?cat=12` oder `?tag=eiche`:

1. ***Verlinken:*** Listen verlinken jeden Datensatz mit [CMS:URL](url.md) – die
   Kategorie ebenso wie das Produkt.
2. ***Standard-Template je Bereich:*** Jeder Inhaltsbereich bekommt ein
   Standard-Template – eine Kategorie-Seite, eine Schlagwort-Seite, eine
   Produkt-Seite. Mit einem Slug-Prefix erhält jede Art von Seite ihren eigenen
   Adressraum (`/de/kategorie/wohnen`, `/de/schlagwort/eiche`).
3. ***Verknüpfte Inhalte listen:*** Auf der Seite steht der Datensatz in `$cms`; seine
   verknüpften Datensätze fragt das Template direkt über ihn ab.

Kategorie-Seite (Standard-Template des Bereichs `kategorien`):

```cms
<h1>(CMS:TEXT titel)</h1>
(CMS:TEXT text prefix="<p>" suffix="</p>")
(CMS:SELECT produkt_liste
  relation=rel_produkte
  sort=position
  order=asc
)
```

Listentemplate `produkt_liste` – die Produkte verlinken wieder auf ihre eigene Seite:

```cms
<ul>
(CMS:RESULT_START)
  <li><a href="(CMS:URL)">(CMS:TEXT titel)</a></li>
(CMS:RESULT_END)
</ul>
```

Die Navigation ist ebenso einfach – eine Liste aller Kategorien mit Links:

```cms
(CMS:RESULT_START)
<a href="(CMS:URL)">(CMS:TEXT titel)</a>
(CMS:RESULT_END)
```

Welche Abfrage die verknüpften Datensätze liefert, hängt davon ab, **von welcher Seite
die Verknüpfung ausgeht**:

| Verknüpfung | Abfrage auf der Seite des Datensatzes |
|---|---|
| geht vom Datensatz aus (sein eigenes Verknüpfungsfeld) | `(CMS:SELECT liste relation=feldname)` |
| Beziehungsfeld (`relation`) eines anderen Bereichs zeigt auf ihn | `(CMS:SELECT liste relation=rel_bereichsname)` – über das Gegenfeld |
| Verweisfeld (`pointer`) eines anderen Bereichs zeigt auf ihn | `(CMS:SELECT liste filter[area]=bereichsname filter[feldname]="{$cms['record_id']}")` |

***Beziehungsfelder*** (`relation`, `relation1`) sind in beide Richtungen verknüpft:
Beim Anlegen erzeugt camindo CMS im Zielbereich ein ***Gegenfeld*** namens
`rel_{bereichsname}` (etwa `rel_produkte`), das die Verknüpfung von der anderen Seite
führt. Die Kategorie-Seite fragt ihre Produkte deshalb mit
`relation=rel_produkte` ab, die Produktseite ihre Kategorien mit
`relation=kategorien`.

***Verweisfelder*** (`pointer`, `pointer1`) sind gerichtet – sie können dafür auf
mehrere Bereiche zeigen. Der Zieldatensatz kennt seine Verweise nicht; das Gegenstück
ist ein Filter auf das Verweisfeld mit der Nummer des laufenden Datensatzes:

```cms
(CMS:SELECT produkt_liste
  filter[area]=produkte
  filter[kategorie]="{$cms['record_id']}"
)
```

> **Achtung:** Die Nummer des laufenden Datensatzes steht in `$cms['record_id']` –
> einen Schlüssel `id` gibt es nicht. Ein ***leerer Filterwert hebt den Filter auf***:
> `filter[kategorie]="{$cms['id']}"` findet deshalb nicht keine, sondern **alle**
> Produkte.

Suchen und freie Filterkombinationen bleiben Parameter (`?q=…`), denn sie sind keine
Datensätze. Alles, was ein Datensatz ist, hat dagegen seine eigene Adresse – und damit
auch Seitentitel, Beschreibung und Frontend-Editing wie jede andere Seite.

## Filter-Syntax

Die Werte in `filter[…]` folgen derselben Syntax wie die Suchmaske im Backend:

| Wert | Bedeutung |
|---|---|
| `Berlin` | gleich |
| `Berlin,Hamburg` | eines von beiden (mit `filter_mode[feld]=AND`: beide) |
| `Hen*`, `*mann`, `*berg*` | Platzhalter |
| `!Berlin`, `!*mann` | ungleich |
| `==Stern*` | exakt gleich, `*` ohne Platzhalterwirkung |
| `>100`, `>=100`, `<100`, `<=100` | Vergleiche – auch für Datumsangaben |
| `>2 AND <4`, `Hen* OR Her*` | Bedingungen im selben Feld verknüpfen |
| `2026`, `2026-06` | Datum: das ganze Jahr bzw. der ganze Monat |
| `now`, `today`, `now-2h`, `today+7d` | Datum relativ zum Aufruf, in der Zeitzone des Benutzers bzw. der Sprache wie ein getipptes Datum; `today` ohne Stunden-Versatz ist der ganze Tag. Versatz in `s`, `m`, `h`, `d` |
| `utc_now`, `utc_today`, `utc_now-15m` | dasselbe in UTC – für Vergleiche mit Zeitpunkten, die unabhängig vom Besucher gelten (Sperrfristen, Zeitfenster eines Scheduler-Laufs) |
| `(empty)`, `!(empty)` | ohne eigenen Wert (leer oder nicht übersetzt) bzw. mit |

Der Operator darf auch zwischen Schlüssel und Wert stehen – praktisch, wenn der Wert
aus einer Variablen kommt:

```cms
(CMS:SELECT sperrfrist_mail
  filter[area] = pressemeldungen
  filter[online_at] > {$set['last_run']}
  filter[online_at] <= {$set['this_run']}
  filter_mode[online_at] = AND
  future = true
)
```

entspricht `filter[online_at]=">{$set['last_run']}"` und
`filter[online_at]="<={$set['this_run']}"`. Mit einem solchen Operator ist der Wert
selbst frei von Operatoren: Beginnt der Inhalt der Variablen mit `<`, `>` oder `!`,
wird das als Text verglichen.

Ein Datumswert, der sich nicht auswerten lässt (etwa ein Unix-Zeitstempel wie
`$_SERVER['REQUEST_TIME']`), wird ***ohne Meldung übergangen*** – die Liste zeigt dann
alle Datensätze, als stünde der Filter nicht da. Für Analysen in Ihrer Entwicklungsumgebung
hilft `debug=true`: Die erzeugte SQL-Abfrage wird angezeigt.

***Textvergleiche nach Sprache:*** Text- und Zeichenfelder (auch `_default`) werden
nach den Regeln der Sprachversion verglichen und sortiert, in der gesucht wird –
abgeleitet aus dem Sprachkürzel, einzustellen ist nichts. Immer gilt: keine
Unterscheidung von Groß-/Kleinschreibung und Akzenten (`cafe` findet „Café“), `ß`
gleich `ss`. Dazu kommen die Regeln einzelner Sprachen:

| Sprache | Regel | Beispiel |
|---|---|---|
| Deutsch | `ä` = `ae`, `ö` = `oe`, `ü` = `ue` (Telefonbuch-Regel) | `Mue*` findet „Müller“, `Mul*` nicht |
| Schwedisch | `å`, `ä`, `ö` sind eigene Buchstaben | `o` findet kein `ö`; `ö` sortiert hinter `z` |
| Dänisch, Norwegisch | `æ`, `ø`, `å` sind eigene Buchstaben, `aa` = `å` | `Aa*` findet „Ålesund“ |
| Türkisch | `i`/`İ` und `ı`/`I` sind verschieden | |

Eigene Regeln haben außerdem Bulgarisch, Esperanto, Estnisch, Galicisch, Isländisch,
Kroatisch und Bosnisch, Lettisch, Litauisch, Polnisch, Rumänisch, Russisch, Serbisch
(lateinisch), Slowakisch, Slowenisch, Spanisch, Tschechisch, Ungarisch und
Vietnamesisch. Alle übrigen Sprachen – auch Englisch – folgen den allgemeinen.

Das gilt für `=`, Platzhalter und Vergleiche wie `>` gleichermaßen, ebenso für
Volltextfelder (`Mueller` findet „Müller“, `Muller` nicht). Im Backend ist es die
Sprache, die für die Instanz des durchsuchten Bereichs eingestellt ist; führen die
Instanzen einer Suche auf verschiedene Regeln, gelten nur die allgemeinen.

Verschiedene Filter werden immer mit UND verknüpft. Volltextfelder verstehen
`+muss -darfnicht`; verschlüsselte Felder nur den exakten Wert.

In Bereichen mit Baumstruktur liefert `filter[parent_id]=42` alle Nachkommen des
Datensatzes 42, zusammen mit `filter[depth]=1` nur die direkten Kinder – die
Grundlage für Navigationen.

## Templates einbinden und organisieren

[CMS:INCLUDE](include.md) bindet ein anderes Template ein – etwa Kopf und Fuß der
Seite:

```cms
(CMS:INCLUDE layout.kopf)
<main>…</main>
(CMS:INCLUDE layout.fuss)
```

Template-Namen müssen nur innerhalb ihres Template-Bereichs eindeutig sein. Gibt es
einen Namen in mehreren Bereichen, sprechen Sie ihn als `bereichsname.templatename`
an. Einen Namen, der nicht eindeutig ist, meldet die Seite als Fehler.

Übliche Aufteilung:

| Art | Aufgabe |
|---|---|
| ***Seiten-Templates*** | Standard-Templates der Inhaltsbereiche: geben einen Datensatz als ganze Seite aus |
| ***Listentemplates*** | durchlaufen die Treffer eines `CMS:SELECT` |
| ***Bausteine*** | Kopf, Fuß, Navigation, Teaser – per `CMS:INCLUDE` eingebunden |
| ***Standalone-Templates*** | Seiten ohne Datensatz: Suche, Sitemap, Formulare |
| ***Zeitgesteuerte Templates*** | laufen als Aufgabe des Zeitplaners, ohne Besucher (siehe unten) |
| ***Listen-Templates (Backend)*** | gestalten die Datensatzliste der Redaktion (siehe unten) |

## Caching

Aufwendige Bausteine – eine Navigation aus vielen Abfragen, eine Produktliste – kann
`CMS:INCLUDE` mit ***`caching`*** zwischenspeichern: `(CMS:INCLUDE layout.navigation
caching=1h)` erzeugt die Navigation höchstens einmal in der Stunde je Website und
Sprache. Wovon die Ausgabe sonst noch abhängt, gehört in `cacheparams` – siehe
[CMS:INCLUDE](include.md#caching).

## Mehrsprachigkeit

Ein Template gibt automatisch die Sprachversion des Aufrufs aus – bei fehlender
Übersetzung die Hauptsprache. Links mit `CMS:URL` behalten die Sprache bei;
mittels Parameter `lang` wird explizit auf eine andere Sprachversion verlinkt. Einen
Sprachumschalter bauen Sie mit [CMS:SELECT_LANGVERSION](select-langversion.md).

Datums- und Zahlenformate richten sich nach der Sprachversion; die Zeitzone ebenso.

Feste Beschriftungen („Weiterlesen“, „Suchen“) pflegt die Redaktion als eigenständige Datensätze (etwa
in einem Bereich namens „Sonstige Texte“) in allen Frontend-Sprachen. In diesem Bereich sollte
es ein _einsprachiges_ `slug`-Feld und ein _mehrsprachiges_ `text`-Feld geben. Dann arbeitet CMS:TEXT
als Snippet-Lieferant, vgl. [CMS:TEXT mit id-Parameter](./text.md#id).

```cms
<a href="(CMS:URL)">(CMS:TEXT id=mysnippets.readmore)</a>
```

## Frontend-Editing

[CMS:FED](fed.md) setzt an einen Inhalt eine Bearbeiten-Markierung, die nur
Benutzer mit eingeschaltetem Frontend-Editing sehen. Ein Klick öffnet die
Eingabemaske des Datensatzes im Backend. [CMS:FED_CODE](fed-code.md) gibt HTML nur in
diesem Fall aus – etwa ein Stylesheet für die Markierungen.

## Sicherheits-Header: CSS, Javascript, JSON und Einbetten

camindo CMS schickt vor jedem Template einige HTTP-Header mit, die der Browser als
Schutz umsetzt:

```
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Content-Security-Policy: frame-ancestors 'self'
Referrer-Policy: strict-origin-when-cross-origin
```

Für gewöhnliche HTML-Seiten müssen Sie nichts tun. Zwei Fälle wirken sich aber aus –
und die Ursache ist nicht auf den ersten Blick zu sehen:

### Templates, die CSS, Javascript oder JSON ausliefern

Ein Template wird als `text/html` ausgeliefert, wenn es nichts anderes sagt. Wegen
`nosniff` nimmt der Browser dann den Inhalt nicht als Stylesheet oder Script an, auch
wenn er richtig ist. Die Seite sieht ungestaltet aus, das Script läuft nicht – und die
Konsole des Browsers meldet etwas wie *„refused to execute script … MIME type
('text/html') is not executable“* bzw. *„… not a supported stylesheet MIME type“*.

Setzen Sie deshalb am Anfang jedes solchen Templates den passenden Typ – vor jeder
Ausgabe, ohne Leerzeile davor:

```cms
(CMS:HEADER "Content-Type: text/css; charset=utf-8")
(CMS:HEADER "Content-Type: text/javascript; charset=utf-8")
(CMS:HEADER "Content-Type: application/json; charset=utf-8")
```

### Ausgaben in anderen Websites einbetten

Eine Seite der Website lässt sich nur ***innerhalb derselben Website*** in einem
`<iframe>` zeigen. Das schützt Anmeldung, Profil und Warenkorb davor, dass eine fremde
Seite sie unsichtbar überlagert und Besuchern Klicks unterschiebt (Clickjacking). Bindet
eine Partnerwebsite eine Seite per `<iframe>` ein, bleibt der Rahmen deshalb leer oder
zeigt eine Fehlerseite des Browsers; die Konsole meldet *„Refused to frame … because an
ancestor violates … frame-ancestors“* bzw. *„… X-Frame-Options to 'sameorigin'“*.

Soll ein Template eingebettet werden dürfen – ein Widget, ein Terminkalender für
Partner –, geben Sie das ***nur in diesem Template*** frei: `X-Frame-Options` ohne Wert
entfernen und die erlaubten Websites in `frame-ancestors` nennen:

```cms
(CMS:HEADER "X-Frame-Options:")
(CMS:HEADER "Content-Security-Policy: frame-ancestors 'self' https://www.partner.example https://*.partner.example")
```

Beide Zeilen gehören zusammen: Ein Browser, der `X-Frame-Options` noch sieht, verweigert
den Rahmen trotz `frame-ancestors`. Nennen Sie die Partner einzeln – `frame-ancestors *`
erlaubt jeder Website das Einbetten – auch das der Seiten mit Formularen.
Andere Einbindungen sind nicht betroffen: Bilder, Downloads, per Javascript geladene
Daten (JSON) und Links auf Ihre Website funktionieren ohne Freigabe.

Mehr zu den Header-Zeilen: [CMS:HEADER](header.md).

## Templates im Dateisystem

Jedes Template liegt als Datei unter
`var/templates/{instance_id}/{bereichsname}/{templatename}.cms.php`. Sie können es
dort mit einem Code-Editor bearbeiten und mit Git verwalten. camindo CMS übernimmt
Änderungen minütlich (oder sofort über „Aktualisieren“ im Modul Templates) und
übersetzt das Template neu. Umbenennen sollten Sie Templates im Backend – im
Dateisystem gilt Umbenennen als Löschen und Neuanlegen, und die Zuweisungen gehen
verloren. Fehlermeldungen erscheinen im Widget „Template-Meldungen“.

### Editor-Unterstützung

Für ***Visual Studio Code*** gibt es die Erweiterung „camindo CMS Templates“
([github.com/dirkintelligence/camindo-vscode](https://github.com/dirkintelligence/camindo-vscode),
auch im Visual Studio Marketplace und bei Open VSX). Sie färbt in `*.cms.php`-Dateien die
CMS-Befehle lila und die PHP-Ausdrücke darin orange – wie die Ansicht im Backend –, ergänzt
nach `(CMS:` die Befehlsnamen und danach die Parameter des Befehls und zeigt beim Überfahren
deren Beschreibung, in derselben Sprache wie das Backend.

Die Erweiterung kennt die Befehle des Kerns und der Standardmodule. Bringen Ihre Module eigene
Befehle mit, schreiben Sie die Befehlsliste Ihres Projekts mit

```
php Core/cron/camindo.php template commands --all --out=var/vscode
```

und tragen das Verzeichnis in der VS-Code-Einstellung `camindo.commandsFile` ein.

## Zeitgesteuerte Templates

Ein Template mit der ***Verwendung „Zeitgesteuert“*** (Reiter „Zugriff“ des Templates)
läuft nicht als Seite, sondern als Aufgabe des ***Zeitplaners*** (Aufgabentyp
„Template“, siehe [Handbuch für Administratoren](../admins/10-module.md#zeitplaner)).
Über eine Adresse ist es nie erreichbar.

Es läuft wie ein Seitenaufruf ohne Datensatz: in der Website seines Template-Bereichs,
in der Sprache, die die Aufgabe nennt – mit deren Texten, Snippets, Datumsformaten und
Zeitzone. Es gibt keinen Besucher, also keine Anmeldung und keinen Warenkorb. Die
Aufgabe übergibt das ***Zeitfenster*** seit dem letzten erfolgreichen Lauf:

| Variable | Inhalt |
|---|---|
| `$set['last_run']` | Beginn des Zeitfensters (ausschließlich), Zeitzone der Sprache |
| `$set['this_run']` | Ende des Zeitfensters (einschließlich), Zeitzone der Sprache |
| `$set['last_run_utc']`, `$set['this_run_utc']` | dasselbe in UTC |

Beim ersten Lauf ist das Zeitfenster leer. Schlägt ein Lauf fehl, holt der nächste den
ganzen Zeitraum nach – nichts geht verloren und nichts wird doppelt verarbeitet. Das
Zeitfenster passt direkt in einen Filter; zwei Bedingungen für dasselbe Feld verknüpft
`filter_mode` mit AND:

```cms
(CMS:SELECT sperrfrist_mail
  filter[area]=pressemeldungen
  filter[online_at] > {$set['last_run']}
  filter[online_at] <= {$set['this_run']}
  filter_mode[online_at]=AND
  future=true
)
```

Das Ergebnis-Template verschickt dann etwa mit [CMS:EMAIL](email.md) (`submit=1`) je
Treffer eine Nachricht; [CMS:URL](url.md) mit `absolute=true` liefert die vollständige
Adresse für Links. Meldet ein Befehl einen Fehler, gilt der Lauf als fehlgeschlagen.
Was das Template ausgibt, speichert die Aufgabe auf Wunsch unter `var/custom/` – so
entsteht etwa eine Sitemap oder ein Feed als Datei.

## Vorschau

Die Vorschau aus dem Backend zeigt auch unveröffentlichte Datensätze mit ihrem
Template. Listen darin zeigen weiterhin nur veröffentlichte Datensätze, ebenso
`id=…` und [CMS:RELATION](relation.md). In der
Vorschau liefert `$this->isPreview()` `true`.

## Listen-Templates für das Backend

Ein Inhaltsbereich kann ein ***Listen-Template*** haben, das nur im Backend verwendet
wird: für eine zusätzliche Spalte der Datensatzliste und die ausführliche Vorschau in
Lookup-Feldern. Im Template steht der Datensatz der Zeile als `$cms` bereit;
die Ausgabebefehle `CMS:TEXT`, `CMS:MEDIA`… funktionieren wie im Frontend:

```cms
(CMS:MEDIA bild_klein width=60)
<small>(CMS:NUMBER preis format="%.2f" decimal="," suffix=" €")</small>
```

`CMS:RELATION` ist hier hilfreich, um das Hauptfeld verknüpfter Datensätze zu listen.