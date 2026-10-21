# CMS:SELECT

Durchsucht die Datenbank anhand der angegebenen (Filter-)Kriterien und liefert die gefundenen Datensätze zurück.

```cmscode
(CMS:SELECT resulttemplate [parameter=wert …])
```

***Methode:*** `Cms::cmsSelect()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `resulttemplate`  
***Mindestens einer von:*** `filter`, `relation`  
***PHP-Schnipsel:*** `push_result` (davor) · `include`, `pop_result` (danach)

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `resulttemplate` ¹ | `Templatename` | – | ja | Listentemplate, das zur Verarbeitung der Ergebnisse eingebunden wird. |
| `noresulttemplate` | `Templatename` | – |  | Detailtemplate, das eingebunden wird, falls die Abfrage keine Ergebnisse liefert. |
| `noresult` | `Text` | – |  | Text, der unmittelbar ausgegeben wird, falls die Abfrage keine Ergebnisse liefert. |
| `filter[…][…]` | `Wert oder EMPTY` | – | ² | Im Index: Name des zu durchsuchenden Feldes (relation_any für ein beliebiges Verweisfeld; area für den Namen des Inhaltsbereichs, eine Zahl gilt dort als area_id). Im Wert: der zu suchende Wert. |
| `filter_mode[…]` | `AND / OR` | `OR` |  | Modus, mit dem mehrere Werte desselben Feldes verknüpft werden (filter_mode[feld]=AND). |
| `filter_op[…][…]` | `=, ==, !=, !!, >, <, >>, <<, >=, <=` | `=` |  | Vergleichsoperator für das angegebene Feld (filter_op[feld]=">"). Alternativ steht der Operator zwischen Schlüssel und Wert (filter[preis] > 100) oder direkt vor dem Wert (filter[preis]=">100"). |
| `skip` | `Zahl >=0` | `0` |  | Anzahl der übersprungenen Datensätze in der Ergebnismenge. Wirkt nur zusammen mit limit. |
| `limit` | `Zahl >0` | – |  | Maximale Anzahl der einbezogenen Datensätze in der Ergebnismenge - zugleich die Seitengröße für CMS:JUMP_LIST, CMS:JUMP_PREV und CMS:JUMP_NEXT. Ohne Angabe oder 0: unbegrenzt, keine Blätterleiste. |
| `sort[…]` | `Feldname(n), kommasepariert` | `changed_at` |  | Feld, nach dem sortiert wird (vgl. order); mehrere kommasepariert oder als sort[]=…; area sortiert nach dem Namen des Inhaltsbereichs, verweisfeld.feld nach einem Feld des verknüpften Datensatzes. |
| `order[…]` | `desc / asc` | `asc` |  | Reihenfolge, in der sortiert wird (vgl. sort). Ohne sort gilt changed_at desc. |
| `lang` | `Sprachkürzel` | `(aktuelle Sprache)` |  | Kürzel der Sprachversion, deren Inhalte angefordert werden (wirkt sich auf mehrsprachige Felder aus). Noch nicht umgesetzt - derzeit gilt immer die Sprache des Aufrufs. |
| `relation` | `Feldname(n) kommasepariert oder 'ANY'` | – | ² | Feldnamen von Beziehungs-/Verweisfeldern, in denen die gesuchten Datensätze gespeichert sind (mit src_id); mit dest_id die Felder, über die die gesuchten Datensätze auf dest_id verweisen. ANY: irgendein Verweisfeld. |
| `src_id` | `Datensatz-ID(s)` | `aktueller Datensatz` |  | Quell-Datensatz, dessen Verknüpfungen ausgewertet werden (ohne Angabe: aktueller Datensatz). |
| `dest_id` | `Datensatz-ID` | – |  | Rückrichtung zu relation: die Datensätze, die über relation auf diesen Datensatz verweisen (relation=ANY: über irgendein Verweisfeld). Ohne src_id wird dann kein aktueller Datensatz als Quelle eingesetzt. |
| `nextof` | `Datensatz-ID` | – |  | Nur mit relation: Nachbarn des angegebenen Datensatzes in der Verweisliste - die Einträge hinter ihm (order=asc) bzw. vor ihm (order=desc), in der Reihenfolge der Liste; mit limit=1 der nächste bzw. vorige Datensatz. |
| `count` | `true / false` | `false` |  | true: Die Anzahl der Datensätze in der Ergebnismenge wird ermittelt, unabhängig von "limit" und "skip" (im resulttemplate in $this->db->num_rows_unlimited; Grundlage der JUMP-Befehle). |
| `hits` | `true / false` | `true` |  | false: Es wird nur gezählt (schließt count=true ein), es werden keine Datensätze geladen und kein resulttemplate eingebunden. Die Gesamtzahl steht danach im aufrufenden Template in $this->db->num_rows_unlimited; noresult/noresulttemplate gelten, wenn sie 0 ist. |
| `archive` | `true / false` | `false` |  | true: Auch abgelaufene Datensätze (offline_at erreicht) werden in die Abfrage einbezogen. |
| `future` | `true / false` | `false` |  | true: Auch vordatierte Datensätze (online_at noch nicht erreicht) werden in die Abfrage einbezogen. |
| `debug` | `true / false` | `false` |  | true: Der erzeugte Datenbank-Query wird für Support-Analysen ausgegeben. |
| `sql` | – | – |  | Raw SQL-Zusatz in der WHERE-Klausel (Achtung: nur für den Support!). |
| `state` | `Status-Id oder any` | `0` |  | Ohne Angabe nur freigegebene Datensätze (status_id 0). Eine Status-Id > 0 findet nur Datensätze mit diesem Status, "any" Datensätze jeden Status. |
| `request` | `Kommaliste` | – |  | Übernimmt Filter- und Sortierwerte aus der URL – Kommaliste oder Array der erlaubten Schlüssel: filter[feld], filter_op[feld], filter_mode[feld], skip, limit, sort, order (z. B. "filter[titel],sort,order,skip"). filter nur mit Feldname. Im Befehl gesetzte Werte haben immer Vorrang; für ein im Befehl gefiltertes Feld fügt die URL nichts hinzu. |
| `set` | `Array-Index von $set` | – |  | Die gesamte Ausgabe (samt eingebundenem Template) wird nicht ausgegeben, sondern in der Variable $set gespeichert. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.  
² mindestens einer der mit ² markierten Parameter muss angegeben werden.

`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Fragt Datensätze ab und bindet für die Treffer das `resulttemplate` ein; darin läuft
`CMS:RESULT_START` … `CMS:RESULT_END` über die Zeilen. Der Befehl ist der Kern jeder
Liste.

Gefunden werden nur **veröffentlichte** Datensätze: freigegeben (`status_id` 0),
Erscheinungsdatum (`online_at`) erreicht, Ende (`offline_at`) nicht erreicht. Die
Parameter `future`, `archive` und `state` lockern das je Abfrage - auch in der
Vorschau aus dem Backend, die zwar einen unveröffentlichten Datensatz zeigt, aber
darin die Listen so, wie die Website sie zeigt.

```cms
<h2>Neueste Artikel</h2>
(CMS:SELECT artikel_teaser
  filter[area]=artikel
  sort=online_at order=desc
  limit=10
  noresult="<p>Noch keine Artikel.</p>"
)
```

Listentemplate `artikel_teaser`:

```cms
<ul>
(CMS:RESULT_START)
  <li><a href="(CMS:URL)">(CMS:TEXT title)</a> – (CMS:DATE online_at)</li>
(CMS:RESULT_END)
</ul>
```

Häufige Muster:

```cms
Ein Datensatz nach Slug:          (CMS:SELECT detail filter[slug]=impressum limit=1)
Nur ein Thema:                    (CMS:SELECT liste filter[area]=artikel filter[topics]=42)
Suche im Titel:                   (CMS:SELECT liste filter[area]=artikel filter[title]="*{$_GET['q']}*")
Zeitraum:                         (CMS:SELECT liste filter[area]=artikel filter[online_at] >= 2026-01-01)
Verknüpfte Datensätze:            (CMS:SELECT topic_badge relation=topics)
Kinder im Baum:                   (CMS:SELECT nav filter[parent_id]="{$cms['record_id']}" filter[depth]=1 sort=position order=asc)
Blätterbare Liste:                (CMS:SELECT liste filter[area]=artikel limit=20 skip="{$_REQUEST['skip']}" count=true)
Suchformular mit Sortierung:      (CMS:SELECT liste filter[area]=artikel limit=20 count=true request="filter[title],sort,order,skip")
```

Mehrere Werte für dasselbe Feld werden mit OR verknüpft (`filter_mode[feld]=AND`
schaltet um); verschiedene Felder immer mit AND. Vergleichsoperatoren stehen zwischen
Schlüssel und Wert (`filter[preis] > 100`, `filter[farbe] != rot`) oder direkt vor dem
Wert (`">100"`); Platzhalter und `(empty)` gehören in den Wert (`Hen*`) - die
vollständige Filtersyntax steht in den [Grundlagen](01-grundlagen.md#filter-syntax).

Ein `CMS:SELECT` darf innerhalb eines Listentemplates stehen (verschachtelte Listen);
das äußere Ergebnis wird automatisch beiseitegelegt und danach fortgesetzt.

## Parameter im Einzelnen

### `resulttemplate`

Listentemplate für die Treffer; darf ohne Schlüssel stehen. Name oder `bereich.name`:

```cms
(CMS:SELECT resulttemplate=artikel_teaser filter[area]=artikel)
(CMS:SELECT artikel_teaser filter[area]=artikel)
(CMS:SELECT artikeltemplates.artikel_teaser filter[area]=artikel)
```

### `noresulttemplate`

Template ohne Treffer - etwa mit einem Suchformular oder Hinweistext:

```cms
(CMS:SELECT suchergebnis filter[area]=artikel filter[title]="*{$_GET['q']}*" noresulttemplate=suche_leer)
```

### `noresult`

Kurzer Text ohne Treffer, ohne eigenes Template:

```cms
(CMS:SELECT liste filter[area]=artikel noresult="<p>Keine Einträge.</p>")
```

### `filter`

Schlüssel = Feldname (eigene Felder, Metafelder wie `record_id`, `status_id`,
`online_at`, `parent_id`, `user_id`, das virtuelle `slug`, `_default` für das
Hauptfeld, `area` für den Bereichsnamen), Wert = Suchwert mit optionalem Operator:

```
filter[area]=artikel                     Bereich nach Name (Zahl = area_id)
filter[area]=artikel,news                zwei Bereiche (OR)
filter[title]="Hen*"                     beginnt mit
filter[price] > 100                      größer als
filter[price]=">100"                     dasselbe, Operator im Wert
filter[price]=">100 AND <500"            Ausdruck in einem Feld
filter[online_at]=2026                   das ganze Jahr
filter[termin] >= today                  ab heute (0 Uhr, Zeitzone des Aufrufs)
filter[online_at] > utc_now-7d           die letzten sieben Tage, in UTC gerechnet
filter[teaser]="(empty)"                 leer / nicht übersetzt
filter[bild]="(empty)"                   Datei-Feld ohne Datei
filter[topics]=42                        Verweisfeld zeigt auf Datensatz 42
filter[topics]="(empty)"                 Verweisfeld ohne Verweis
filter[relation_any]=42                  irgendein Verweisfeld zeigt auf 42
filter[topics.title]="Island"            Feld des verknüpften Datensatzes
filter[tags]=a filter[tags]=b            zwei Werte, OR (siehe filter_mode)
filter[parent_id]=17 filter[depth]=1     direkte Kinder von 17
filter[parent_id]=null filter[depth]=1   oberste Ebene (Datensätze ohne Elterndatensatz)
```

`filter[depth]` ist ***kein Filter auf die Einrückung*** eines Datensatzes, sondern gibt
an, wie viele Ebenen unterhalb von `filter[parent_id]` durchsucht werden: `1` = nur
direkte Kinder, `2` = bis zu den Enkeln, ohne Angabe = alle Nachkommen. Ohne
`filter[parent_id]` bleibt es wirkungslos (Hinweis beim Speichern des Templates bzw. mit `debug=true`); `filter[depth]=0` liefert keine Datensätze.
Der Wert unter „Einzurückende Stellen“ in der Datensatzinfo (`0` = oberste Ebene) ist
dagegen absolut – die oberste Ebene finden Sie mit `filter[parent_id]=null
filter[depth]=1`.

Textfelder werden nach den ***Regeln der Sprache*** verglichen und sortiert, in der
gesucht wird: Groß-/Kleinschreibung und Akzente spielen keine Rolle, `ß` gleich `ss`,
im Deutschen außerdem `ä` gleich `ae` (`filter[title]="Mue*"` findet „Müller“), im
Schwedischen dagegen ist `ö` ein eigener Buchstabe (`o` findet kein `ö`). Die Regeln
ergeben sich aus dem Sprachkürzel; siehe [Grundlagen](01-grundlagen.md#filter-syntax).

Ein ***leerer Wert*** hebt den Filter auf - praktisch für optionale Filter aus dem
Request, gefährlich bei Tippfehlern: `filter[kategorie]="{$cms['id']}"` (den Schlüssel
`id` gibt es nicht, richtig ist `record_id`) liefert alle Datensätze.

### `filter_mode`

Verknüpfung mehrerer Werte **desselben** Feldes (Standard OR):

```cms
(CMS:SELECT liste filter[area]=artikel filter[tags]=reisen filter[tags]=island filter_mode[tags]=AND)
```

Findet Artikel, die **beide** Tags tragen.

### `filter_op`

Vergleichsoperator getrennt vom Wert - gleichwertig zur Schreibweise im Wert und zum
Operator zwischen Schlüssel und Wert:

```cms
(CMS:SELECT liste filter[area]=artikel filter[price]=100 filter_op[price]=">")
(CMS:SELECT liste filter[area]=artikel filter[price]=">100")
(CMS:SELECT liste filter[area]=artikel filter[price] > 100)
```

### `skip`

Offset für die Blätterfunktion; meist aus dem Request. `skip` wirkt nur zusammen mit
`limit` – ohne `limit` wird es ignoriert, die Abfrage liefert dann alle Treffer ab dem
ersten:

```cms
(CMS:SELECT liste filter[area]=artikel limit=20 skip="{$_REQUEST['skip']}" count=true)
```

Die Blätterleiste ([CMS:JUMP_LIST](jump-list.md)) steht im `resulttemplate` `liste`.

### `limit`

```cms
(CMS:SELECT teaser filter[area]=artikel limit=3)
```

Höchstens drei Treffer. Ohne `limit` (oder mit `0`) liefert die Abfrage ***alle***
Treffer – bei großen Bereichen gehört deshalb immer ein `limit` dazu. `limit` ist
zugleich die Seitengröße der Blätterbefehle [CMS:JUMP_LIST](jump-list.md),
[CMS:JUMP_PREV](jump-prev.md) und [CMS:JUMP_NEXT](jump-next.md) im `resulttemplate`;
ohne `limit` gibt es keine Blätterleiste.

### `sort`

Sortierfeld, Richtung über `order` oder direkt im Wert:

```
sort=online_at order=desc
sort="title asc"
sort=position order=asc                     Reihenfolge aus dem Backend (Baum/Liste)
sort=_default                               Hauptfeld des Bereichs
sort=area                                   nach Bereichsname
sort=author.name                            Feld des verknüpften Datensatzes
sort[]=status_id sort[]=title order[]=asc order[]=asc
```

### `order`

Richtung zu `sort`: `asc` (Standard, sobald `sort` angegeben ist) oder `desc`. Ohne
`sort` sortiert der Befehl nach `changed_at desc` – die zuletzt geänderten zuerst.

```cms
(CMS:SELECT liste filter[area]=artikel sort=title order=asc)
```

### `lang`

> Noch nicht umgesetzt - es gilt die Sprache des Aufrufs.

### `relation`

Treffer sind die Datensätze, auf die das Verweisfeld des Quell-Datensatzes zeigt -
ohne `src_id` die des laufenden Datensatzes. Die Reihenfolge des Verweisfeldes
liefert `sort=_custom`; ohne `sort` gilt die Standardsortierung (`changed_at desc`):

```cms
(CMS:SELECT topic_badge relation=topics)
(CMS:SELECT galerie relation=bilder sort="_custom asc")
```

Mehrere Feldnamen, kommagetrennt, gelten als ODER; `ANY` meint irgendein Verweisfeld.
`sort=_custom` und `nextof` setzen genau einen Feldnamen voraus.

```cms
(CMS:SELECT teaser relation="topics,tags")
(CMS:SELECT teaser relation=any)
```

Die umgekehrte Richtung - Datensätze, die auf den laufenden zeigen:

```cms
(CMS:SELECT produkt_liste relation=rel_produkte)
(CMS:SELECT produkt_liste filter[area]=produkte filter[kategorie]="{$cms['record_id']}")
(CMS:SELECT verweis_liste relation=any dest_id="{$cms['record_id']}")
```

Die erste Zeile gilt für Beziehungsfelder (`relation`): Sie haben im Zielbereich ein
Gegenfeld `rel_{bereichsname}`. Verweisfelder (`pointer`) haben keins - ihr Gegenstück
ist der Filter auf das Verweisfeld (zweite Zeile). Siehe
[Jeder Datensatz ist eine Seite](01-grundlagen.md#das-konzept-jeder-datensatz-ist-eine-seite).

### `src_id`

Quell-Datensatz für `relation`, wenn nicht der laufende gemeint ist:

```cms
(CMS:SELECT topic_badge relation=topics src_id=1977)
(CMS:SELECT topic_badge relation=topics src_id="{$set['user_id']}")
```

Ein leerer Wert oder `0` meint ebenfalls den laufenden Datensatz. Gibt es keinen – etwa
in einem eigenständigen Template ohne Datensatz –, findet die Abfrage nichts (und nicht
etwa alle Datensätze des Bereichs).

### `dest_id`

Die Rückrichtung zu `relation`: Treffer sind die Datensätze, die über das Verweisfeld
– mit `relation=any` über irgendeines – auf `dest_id` zeigen, etwa „Wo wird dieses
Bild verwendet?":

```cms
(CMS:SELECT verweis_liste relation=any dest_id="{$cms['record_id']}")
(CMS:SELECT artikel_liste relation=topics dest_id=1977)
```

Mit `dest_id` setzt der Befehl ohne `src_id` keinen laufenden Datensatz als Quelle ein.
Dasselbe als Filterwert, auch für mehrere Ziele: `filter[relation_any]="1977,1978"`.

### `nextof`

Nur zusammen mit `relation`: statt aller verknüpften Datensätze die ***Nachbarn*** des
Datensatzes `nextof` in derselben Verweisliste - die Einträge hinter ihm bei
`order=asc` (Standard), die vor ihm bei `order=desc`, jeweils in der Reihenfolge der
Liste (die Sortierung ist damit festgelegt, `sort` wird ignoriert). Mit `limit=1`
ist das der nächste bzw. vorige Datensatz - etwa für „weiter“/„zurück“ in einer
Bildergalerie, deren Bilder am Artikel 2422 hängen:

```cms
(CMS:SELECT galerie_weiter relation=bilder src_id=2422 nextof="{$cms['record_id']}" limit=1)
(CMS:SELECT galerie_zurueck relation=bilder src_id=2422 nextof="{$cms['record_id']}" order=desc limit=1)
```

Steht `nextof` nicht in der Liste, gibt es keine Treffer.

### `count`

Gesamtzahl unabhängig von `limit`/`skip` ermitteln - Grundlage für `CMS:JUMP_*` und für Angaben wie „Treffer 21–40 von 137“:

```cms
(CMS:SELECT liste filter[area]=artikel limit=20 skip="{$_REQUEST['skip']}" count=true)
```

Im `resulttemplate` `liste` stehen dann zwei Zahlen: `$this->db->num_rows` sind die
Treffer ***dieser Seite*** (höchstens `limit`), `$this->db->num_rows_unlimited` ***alle***
Treffer der Abfrage:

```cms
(CMS:NUMBER useval="{$this->db->num_rows_unlimited}") Treffer
(CMS:RESULT_START) … (CMS:RESULT_END)
(CMS:JUMP_LIST)
```

Beide Zahlen gelten ***nur im `resulttemplate`***: Nach dem `CMS:SELECT` ist wieder
das umgebende Ergebnis aktiv – dieselbe Regel, die verschachtelte Listen möglich macht.
Wird nur die Zahl gebraucht, zählt [`hits=false`](#hits) im aufrufenden Template.

### `hits`

`hits=false` zählt nur (`count=true` ist damit eingeschlossen): Es werden keine
Datensätze geladen und ***kein*** `resulttemplate` eingebunden – der Name muss trotzdem
angegeben werden. Die Gesamtzahl steht danach ***im aufrufenden Template*** in
`$this->db->num_rows_unlimited`:

```cms
(CMS:SELECT x filter[area]=artikel filter[topics]=42 hits=false)
(CMS:NUMBER useval="{$this->db->num_rows_unlimited}") Artikel zu diesem Thema
```

Ist die Zahl 0, gelten `noresult` bzw. `noresulttemplate` wie gewohnt:

```cms
(CMS:SELECT x filter[area]=artikel filter[topics]=42 hits=false noresult="Noch keine Artikel zu diesem Thema.")
```

Steht das zählende `CMS:SELECT` in einem `resulttemplate`, ersetzt die Zahl dort die
Gesamtzahl des umgebenden Ergebnisses; `$this->db->num_rows` und die laufende
Ergebnisschleife bleiben unberührt. Eine Blätterleiste
([CMS:JUMP_LIST](jump-list.md)) des umgebenden Ergebnisses gehört dann ***vor*** das
zählende `CMS:SELECT`.

### `archive`

Abgelaufene Datensätze (`offline_at` erreicht) einbeziehen - z. B. für ein Archiv
vergangener Veranstaltungen:

```cms
(CMS:SELECT archiv filter[area]=events archive=true sort=online_at order=desc)
```

Ein abgelaufener Datensatz bleibt übrigens unter seiner eigenen Adresse erreichbar;
er fehlt nur in Listen ohne `archive`.

### `future`

Vordatierte Datensätze (`online_at` noch nicht erreicht) einbeziehen - z. B. eine
Vorschau auf kommende Beiträge:

```cms
(CMS:SELECT demnaechst filter[area]=artikel future=true filter[online_at] > now)
```

### `debug`

SQL und Bind-Werte der Abfrage ausgeben (nur zur Entwicklung). Darüber stehen ***verworfene
Filter*** mit Begründung – etwa ein unbekannter Feldname oder `filter[depth]` ohne
`filter[parent_id]`:

```cms
(CMS:SELECT liste filter[area]=artikel debug=true)
```

### `sql`

Roh-SQL für die WHERE-Klausel - nur für den Support, keine Benutzereingaben hineinreichen:

```cms
(CMS:SELECT liste filter[area]=artikel sql="AND `record`.`record_id` % 2 = 0")
```

### `set`

Die ***gesamte Ausgabe*** – das Listentemplate mit allen Treffern, bei `noresult` dessen
Text – landet in `$set` statt auf der Seite. So lässt sich etwa eine Adresse aus einer
Abfrage in einem anderen Befehl weiterverwenden:

```cms
(CMS:SELECT url_only filter[area]=seiten filter[kennung]=warenkorb limit=1 set=warenkorb_url)
<a class="cart" href="<?php echo $set['warenkorb_url']; ?>">…</a>
(CMS:TEXT titel prefix="<a href='{$set['warenkorb_url']}'>" suffix="</a>")
```

Auch die Ausgabe von `debug=true` und PHP-Hinweise aus dem Listentemplate stehen dann
in `$set` und nicht auf der Seite – zum Untersuchen `set` vorübergehend weglassen.
Endet die Seite innerhalb der Abfrage (etwa durch ein Success-Template), wird das bis
dahin Erzeugte normal ausgegeben.

### `state`

Ohne Angabe nur freigegebene Datensätze (`status_id` 0). Eine Status-Id findet nur
Datensätze mit genau diesem Status, `any` hebt den Statusfilter auf:

```cms
(CMS:SELECT liste filter[area]=artikel state=2)
(CMS:SELECT liste filter[area]=artikel state=any)
```

### `request`

Übernimmt Filter, Sortierung und Blättern aus der ***URL*** (GET oder POST) – für
Suchformulare und sortierbare Listen, ohne jeden Wert einzeln in den Befehl zu
schreiben. Angegeben wird, was übernommen werden darf, als Kommaliste oder Array:

```
request="sort,order,skip"                                  nur Sortierung und Blättern
request="filter[title],filter[price],filter_op[price]"     Suche in Titel und Preis
request[]=sort request[]="filter[title]"                   dasselbe als Array
```

Erlaubt sind `skip`, `limit`, `sort`, `order` sowie `filter`, `filter_op` und
`filter_mode` ***nur mit Feldname*** (`filter[title]`). Ein `filter` ohne Feldname und
`all` werden ignoriert und als Fehler gemeldet – sonst dürfte ein Besucher nach jedem
Feld der gefundenen Datensätze suchen, auch nach Feldern, die die Seite gar nicht
anzeigt, etwa einer internen Notiz.

In der URL heißen die Schlüssel wie im Befehl, nur `filterop` und `filtermode` ohne
Unterstrich: `?filter[title]=Hen*&sort=title&order=desc&skip=20`.

```cms
(CMS:SELECT liste filter[area]=artikel limit=20 count=true request="filter[title],sort,order,skip")
```

Die URL kann die Abfrage nur ***einschränken***, nie erweitern:

* Werte im Befehl haben Vorrang. Für ein Feld, das der Befehl filtert, übernimmt
  `request` weder Werte noch `filter_op`/`filter_mode` – `?filter[area]=intern` oder
  `?filterop[area]=!=` ändern an `filter[area]=artikel` nichts. Felder eines verknüpften
  Datensatzes zählen zum Verweisfeld (`autor.name` gehört zu `autor`), `area` und
  `area_id` gelten als dasselbe Feld.
* `skip`, `limit`, `sort` und `order` aus dem Befehl ersetzen den Wert der URL
  vollständig. `limit` aus der URL gilt nur als positive Zahl – „unbegrenzt“ lässt sich
  nicht anfordern.
* ***Verschlüsselte Felder*** finden nur den exakten Wert; Platzhalter (`*`) und
  Größer/Kleiner liefern keine Treffer.
* ***Passwortfelder*** lassen sich grundsätzlich weder filtern noch sortieren.

---

[← Übersicht](README.md)
