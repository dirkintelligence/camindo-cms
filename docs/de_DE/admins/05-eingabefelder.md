# 5 Eingabefelder

Die Eingabefelder eines Inhaltsbereichs pflegen Sie im Modul „Struktur“. Wählen Sie einen
Bereich aus und wechseln Sie in den Reiter „Eingabefelder“. Die Liste zeigt jedes Feld mit
Name, Label, Typ und drei Schaltern für die ***Sichtbarkeit***. Die Sichtbarkeit lässt sich direkt
in der Liste umschalten:

| Schalter | Das Feld erscheint … |
|---|---|
| Eingabe | in der Eingabemaske |
| Suche | in der Suchmaske |
| Liste | als Spalte der Datensatzliste |

Die Reihenfolge der Felder in der Liste ist auch ihre Reihenfolge in der Maske.
„Neu“ legt ein Feld an. Klick auf ein Feld öffnet seine Detailansicht mit
„Bearbeiten“, „Kopieren“, „Löschen“ und „Typ ändern“.

## Feldtypen

Beim Anlegen wählen Sie, was das Feld speichern soll:

| Gruppe | Typ | Speichert |
|---|---|---|
| ***Text*** | Kurzer Text | bis 255 Zeichen; optimal für Sortierung und Suche (Titel, Namen, Kennungen) |
| | Langer Text | Fließtext bis 16 Millionen Zeichen |
| | Verschlüsselter Text | Text bis 16 Millionen Zeichen, verschlüsselt gespeichert; Suche nur nach exakter Übereinstimmung (siehe [unten](#verschlüsselte-felder)) |
| | Text mit Mehrfachauswahl | mehrere Werte aus einer festen Liste (Checkboxen) |
| | Volltext | sammelt den Text anderer Felder für die Volltextsuche (siehe [unten](#volltext)) |
| | Passwort | nicht umkehrbar verschlüsselt (gehasht) |
| ***Datum / Zeit*** | Datum + Uhrzeit, zeitzonenabhängig | Zeitpunkt mit Umrechnung zwischen Zeitzonen (Termine, Veröffentlichungen) |
| | Datum + Uhrzeit | Datum und Uhrzeit ohne Umrechnung (Geburtstage, Stichtage) |
| | Uhrzeit | Uhrzeit ohne Umrechnung (Öffnungszeiten) |
| ***Zahl*** | Ganzzahl | ganze Zahlen |
| | Bit | zwei Zustände: 0 oder 1 (Schalter) |
| | Währungsbetrag | Betrag mit genau 2 Nachkommastellen, exakt ohne Rundungsverluste |
| | Fließkommazahl | hohe Genauigkeit, minimale Rundungsdifferenzen möglich |
| | Exakte Dezimalzahl | bis zu 10 Vor- und 10 Nachkommastellen, exakt |
| ***Verknüpfung*** | Verknüpfung (bidirektional, n:1) | genau ein Datensatz eines anderen Bereichs, z. B. die Hauptrubrik |
| | Verknüpfung (bidirektional, n:m) | mehrere Datensätze eines anderen Bereichs, z. B. Schlagwörter |
| | Verweis (unidirektional, n:1) | einseitiger Verweis auf genau einen Datensatz |
| | Verweis (unidirektional, n:m) | einseitiger Verweis auf mehrere Datensätze, z. B. „Verwandte Artikel“ |
| | Fest verknüpfte Inhalte (1:n) | exklusiv zugeordnete Inhalte, z. B. Text-Bild-Blöcke |
| | Eingebettete Inhalte (1:n) | wie oben, bearbeitet in der Maske des Datensatzes |
| ***Datei*** | Datei-Upload | Upload-Feld für Dateien |
| | Dateiversion (Thumbnail) | automatisch erzeugte Version einer hochgeladenen Datei – generiertes Vorschaubild, verkleinertes Video … (siehe [Kapitel 8](./08-medien.md)) |
| ***Meta-Information*** | Veröffentlichungsdatum, Ablaufdatum, Gestaltungsvorlage, Status, Prüfung nötig, Eltern-Datensatz, Position | Metafelder, siehe [unten](#metafelder) |

Wählen Sie den Typ mit Bedacht – er bestimmt, wie Eingaben geprüft, sortiert und durchsucht werden.
Zahlen in einem Textfeld sortieren alphabetisch (`10` vor `9`),
ein Datum im Textfeld lässt sich nicht nach Zeiträumen filtern.

Die folgende Liste der Eingabefelder variiert je nach Feldtyp:

## Name, Label und Hilfetexte

| Einstellung | Bedeutung |
|---|---|
| Name | interner Name, wird für die Templates benötigt, z. B. `titel`. Beginnt mit einem Kleinbuchstaben, danach Kleinbuchstaben, Ziffern und `_` (siehe [Namensregeln](#namensregeln)) |
| Darstellungstyp | [siehe unten](#darstellungstyp) |
| Position nach... | Auswahl des vorangehenden Feldes (Reihenfolge) |
| Label | Beschriftung in Eingabe-, Suchmaske und Liste |
| Anmerkung | erscheint unter dem Label |
| Tooltip | erscheint beim Zeigen auf das Label |
| Prefix, Suffix | erscheinen links bzw. rechts vom Eingabefeld, z. B. „€“ oder „cm“ |
| Breite, Höhe | Größe des Eingabefelds |
| Sichtbarkeit | Einstellung, ob das Feld in der Eingabemaske, Suchmaske und Datensatzliste erscheint. |
| Hauptfeld | Das Feld vertritt den Datensatz in Listen und Verknüpfungen (eines je Bereich) |
| Mehrsprachig | siehe [unten](#mehrsprachige-felder) |

Schaltfläche „Snippets“ ![translate](../_/translate.svg): In den Eingabefeldern „Label“, „Anmerkung“
und „Tooltip“ können Sie statt eines Textes den Namen eines [Snippets](./10-module.md#snippets) angeben,
angeführt von `#`, etwa `#label_file`. Der Text erscheint dann in der jeweiligen Sprache des
Backend-Benutzers. Ein Klick auf die Schaltfläche erlaubt eine Suche über alle registrierten Snippets.

Für übliche Feld- und Bereichsnamen bringt camindo CMS die Übersetzungen in allen
Backend-Sprachen schon mit – ein eigenes Snippet ist dafür nicht nötig. Beispiele:

`#label_customers`, `#label_profiles`, `#label_comments`, `#label_products`, `#label_articles`, `#label_shopitems`, `#label_categories`, `#label_tags`, `#label_pages`, `#label_news`, `#label_events`, `#label_contacts`, `#label_text_image_blocks`, `#label_documents`, `#label_downloads`, `#label_files`, `#label_images`, `#label_media`, `#label_videos`, `#label_title`, `#label_subtitle`, `#label_teaser`, `#label_text`, `#label_description`, `#label_author`, `#label_date`, `#label_price`, `#label_document`, `#label_file`, `#label_image`, `#label_link`, `#label_icon`, `#label_url`

Etwa `#label_customers` als Label des Bereichs „Kunden“: Das Gegenfeld einer
Verknüpfung übernimmt die Beschriftung des Bereichs und erscheint dann ebenfalls
übersetzt.

## Feldinhalt

| Einstellung | Bedeutung |
|---|---|
| Standardwert | Wert, der bei neuen Datensätzen in der Eingabemaske voreingetragen wird (Vorgabewert). Bei Datumsfeldern sind auch Datumsfunktionen erlaubt, [siehe unten](#datumsfunktionen) |
| Aufbereitung | Der im Datensatz eingegebene Wert wird entsprechend der angehakten Regeln aufbereitet („Whitespace trimmen“ entfernt Leerzeichen am Anfang und Ende, „HTML-Tags entfernen“ lässt nur reinen Text übrig) |

### Datumsfunktionen

Bei Datums- und Zeitfeldern darf der Standardwert eine ***Datumsfunktion*** sein. Sie
wird beim Speichern des Datensatzes ausgewertet:

| Standardwert | ergibt |
|---|---|
| `now`, `today` | jetzt bzw. heute 0:00 Uhr |
| `now+2h`, `today+7d`, `today-1d` | mit Versatz in Sekunden (`s`), Minuten (`m`), Stunden (`h`) oder Tagen (`d`) |
| `UTC_TIMESTAMP()`, `UTC_DATE()`, `UTC_TIME()` | aktueller Zeitpunkt, Tag bzw. Uhrzeit in Weltzeit |
| `NOW()`, `CURRENT_TIMESTAMP()`, `CURDATE()`, `CURTIME()` | dasselbe in der Zeitzone der Datenbank |

## Darstellungstyp

Der ***Darstellungstyp*** bestimmt, wie das Feld in der Maske erscheint:

| Darstellungstyp | für |
|---|---|
| Einzeilige / Mehrzeilige Texteingabe | Text, Zahl, Datum |
| E-Mail-, Telefon-, Passworteingabe | kurzer Text bzw. Passwort |
| Dropdown (Einfachauswahl), Radiobuttons | Verknüpfungen, Text, Zahl |
| Dropdown (Mehrfachauswahl), Checkboxen | Verknüpfungen, Texte (JSON-Array) |
| Switch (an/aus) | Bit (0 oder 1)  |
| Lookup-Feld | Verknüpfungen – sobald der Zielbereich viele Datensätze hat |
| Eingebettet | Inhalte, die exklusiv zum Datensatz gehören |
| Upload-Feld | Dateien |
| Dateiversion | Vorschau-Bilder, verkleinerte Videos |

Bei Auswahllisten (Dropdown, Radiobuttons, Checkboxen) tragen Sie die ***Optionen*** in
das Feld „Standardwert“ ein – eine Option je Zeile. Die erste Option ist der Vorgabewert.
Soll kein Vorgabewert eingetragen werden, fügen Sie am Anfang einen Zeilenumbruch ein (leere Zeile).

## Eingabeprüfung

| Einstellung | Wirkung |
|---|---|
| Pflichtfeld | Das Feld darf nicht leer bleiben |
| Nicht Null | Zahlenfelder dürfen nicht 0 sein |
| Minimum / Maximum | Bei Texten die Länge, bei Zahlen und Datumsangaben der Wert, bei Verknüpfungen und Mehrfachauswahl die Anzahl. Der Vergleichsoperator legt fest, ob die Grenze selbst erlaubt ist (`>=`) oder nicht (`>`) |
| RegEx | eigenes Prüfmuster als regulärer Ausdruck, z. B. `[A-Z]{2}-[0-9]{4}` für Artikelnummern wie „AB-1234“ |
| Wert einmalig im Inhaltsbereich | kein anderer Datensatz des Bereichs darf denselben Wert haben |
| Wert einmalig in der Datenbank | kein anderer Datensatz der Installation darf in diesem Feld denselben Wert haben |
| Inhalt verschlüsselt speichern | siehe [unten](#verschlüsselte-felder) |

## Mehrsprachige Felder

Ein **mehrsprachiges** Feld hat je Sprache der Instanz einen eigenen Inhalt. Fehlt
eine Übersetzung, gilt der Inhalt der Hauptsprache (Fallback). Einsprachige Felder
haben in jeder Sprache denselben Wert.  
Mehrsprachig sein können: Text (auch „Verschlüsselter Text“ und „Text mit
Mehrfachauswahl“), Zahl, Bit, Datum, Uhrzeit und Datei-Upload.
***Thumbnail-Felder*** übernehmen die Einstellung ihrer Quelle: Bei einer mehrsprachigen
Quelle entsteht je Sprache ein eigenes Thumbnail; in Sprachen ohne eigene Datei gilt das
Thumbnail der Hauptsprache.
Ein ***Volltext*** hat keinen Schalter „Mehrsprachig“, entsteht aber immer ***je
Sprache*** – aus den Inhalten seiner Quellfelder in dieser Sprache (bei nicht
übersetzten Feldern aus dem Fallback der Hauptsprache).
Verknüpfung, Passwort und Metafeld sind immer einsprachig.

## Verknüpfungen

| Einstellung | Bedeutung |
|---|---|
| Verknüpfter Inhaltsbereich | Zielbereich einer bidirektionalen Verknüpfung |
| Verknüpfte Inhaltsbereiche | Zielbereiche eines unidirektionalen Verweises oder fest verknüpfter Inhalte – auch mehrere |
| Gegenfeld | Bei beidseitigen Verknüpfungen legt camindo CMS im Zielbereich automatisch das Gegenfeld an: Wird ein Artikel mit einem Schlagwort verknüpft, kennt das Schlagwort den Artikel |
| Manuelle Sortierung | Die Redaktion bestimmt die Reihenfolge der verknüpften Datensätze (etwa die Bilder einer Galerie) |
| Ausführliche Liste | Im Lookup-Feld zeigt die Vorschau der verknüpften Datensätze statt nur des Hauptfeldes alle Felder, die auch in der Datensatzliste des Bereichs sichtbar sind, hier allerdings untereinander |

Zielbereiche müssen zur selben Instanz gehören oder zu einer Instanz, die diese lesen
darf. Wählen Sie einen Zielbereich ab, in dem noch Verknüpfungen bestehen, fragt
camindo CMS, ob diese Verknüpfungen gelöscht werden sollen.

Eine Verknüpfung „n:1“ ist nicht nur eine Beschränkung auf einen Datensatz: Templates
können nach Feldern des verknüpften Datensatzes besonders schnell sortieren und
filtern. Wählen Sie diesen Verknüpfungs-Typ dort, wo inhaltlich nur ein Ziel sinnvoll ist,
z. B. eine Rubrik zum Artikel.

### Fest verknüpfte und eingebettete Inhalte

Fest verknüpfte Inhalte bilden einen ***Seitenbaukasten***: Eine Seite besteht aus
Blöcken – Textblock, Bildblock, Zitat, Galerie –, jeder Blocktyp ist ein eigener
Inhaltsbereich mit eigenen Feldern.

1. Legen Sie für jeden Blocktyp einen Inhaltsbereich an und schalten Sie dort
   ***„Abhängiger Bereich“*** ein. Nur abhängige Bereiche sind als Ziel wählbar.
2. Geben Sie dem Seiten-Bereich ein Feld „Eingebettete Inhalte“ (oder „Fest
   verknüpfte Inhalte“ mit Suchfenster) und wählen Sie die Blockbereiche als Ziel.

Jeder Block gehört genau einer Seite. Er wird mit ihr gespeichert, kopiert,
verschoben und gelöscht. Beim Typ „Eingebettet“ bearbeitet die Redaktion alle Blöcke
direkt in der Eingabemaske der Seite: Blöcke können hinzugefügt, umsortiert und entfernt werden.

## Dateien

Ein ***Upload-Feld*** nimmt eine Datei auf. Unter „Erlaubte Dateitypen“ schränken Sie
die zulässigen Formate ein – als MIME-Typ (`image/jpeg`, `image/*`) oder Endung
(`.pdf`), eine Angabe je Zeile. Ausführbare Dateien wie `.php` sind immer verboten,
und camindo CMS prüft, ob der Inhalt einer Datei zu ihrer Endung passt.

Unter „Authentifizierung“ kann ein Datei-Inhalt vor dem Zugriff unautorisierter Website-Besucher
geschützt werden (siehe [Kapitel 13](./13-community.md#geschützte-dateien)).

***Thumbnail-Felder*** erzeugen aus der Datei eines Upload-Felds automatisch weitere
Versionen: verkleinerte Bilder, Vorschaubilder, web-taugliche Videos, Standbilder aus
Videos. Sie wählen die ***Quelle*** (das Upload-Feld oder ein anderes Thumbnail) und
einen oder mehrere ***Konverter***. Die Konverter werden in der eingestellten Reihenfolge
abgearbeitet; der erste Konverter, der zum Dateityp passt, erzeugt die Version.
Details in [Kapitel 8](./08-medien.md).

## Volltext

Ein ***Volltextfeld*** sammelt den Inhalt mehrerer Textfelder in einem eigenen Suchindex
(neben `fulltext`, der automatisch in jedem Inhaltsbereich verfügbar ist).
Unter „Quellfelder“ wählen Sie die Felder aus; bei Upload-Feldern zählt der ***Dateiname*** (`schraeg-vorn.jpg`
wird zu „schraeg vorn schraegvorn jpg“: Binde- und Unterstrich-Wörter stehen getrennt
und zusammengeschrieben im Index). Verschlüsselte Felder und Thumbnails stehen nicht zur
Wahl. Unter den Feldern des Bereichs folgen – je mit dem Namen des Zielbereichs und des
Verknüpfungsfelds als Überschrift – die Felder der Zielbereiche seiner Felder vom Typ:

- ***„Fest verknüpfte Inhalte“***: So findet die Suche ein Produkt etwa über die Titel
  seiner Bilder. Ändert sich ein verknüpfter Inhalt, wird der Volltext seines
  Datensatzes sofort mit aktualisiert – der Inhalt gehört ja zum Datensatz.
- ***„Verknüpfung“*** (bidirektional): So findet die Suche ein Produkt etwa über die
  Bezeichnungen seiner Schlagwörter. Ändert sich aber ein verknüpfter Datensatz – etwa
  die Bezeichnung eines Schlagworts –, kommt das erst beim nächsten Speichern des
  Produkts in dessen Volltext. Lassen Sie deshalb für solche Bereiche die Aufgabe
  [„Volltext neu aufbauen“](#volltext-neu-aufbauen) regelmäßig laufen, etwa nachts.

Felder hinter einem ***„Verweis“*** stehen nicht zur Wahl: Ein Verweis ist eine lose,
einseitige Referenz auf fremde Inhalte, oft in mehreren Bereichen – deren Texte
beschreiben nicht den Datensatz selbst. Das Volltextfeld
wird bei jedem Speichern automatisch aktualisiert und hat keine eigene Eingabe; es
erscheint nur in der Suchmaske. Der Inhalt entsteht ***je Sprache*** der Website: In
der englischen Fassung findet die Suche die englischen Texte. HTML-Auszeichnungen
aus formatierten Texten gehören nicht dazu. Die Suche unterstützt die Syntax
`+muss -darfnicht` (siehe [Anwender-Handbuch](../editors/04-inhalte-finden.md#volltextsuche)).

### Der Standard-Volltext

Jeder Datensatzbereich hat von selbst einen ***Volltext*** über ***alle*** Felder vom
Typ „Kurzer Text“, „Langer Text“ und JSON sowie die Dateinamen der Upload-Felder –
ohne dass Sie dafür ein Eingabefeld anlegen. Dazu kommen dieselben Felder der
Zielbereiche seiner Felder vom Typ „Fest verknüpfte Inhalte“ (eine Ebene tief). Neue Felder kommen von selbst hinzu,
gelöschte fallen heraus; verschlüsselte Felder und Passwörter gehören nie dazu. In
der Feldliste des Bereichs erscheint er deshalb nicht, in jeder Suchmaske aber als
Suchfeld „Volltext“ – auch in der [bereichsübergreifenden
Suche](../editors/04-inhalte-finden.md#bereichsübergreifende-suche). Intern heißt er
`fulltext`.

Was im Volltext eines Datensatzes steht, zeigt dessen Detailansicht im Reiter
„Metadaten“ unter „Volltext anzeigen“ – zusammen mit den selbst angelegten
Volltextfeldern, je Sprache. Im Reiter „Inhalte“ erscheinen Volltextfelder nicht.

### Volltext neu aufbauen

Beim Speichern eines Datensatzes wird sein Volltext automatisch aktualisiert. Nach
manchen Änderungen an der ***Struktur*** passt aber der Volltext ***aller***
vorhandenen Datensätze nicht mehr zu den Quellfeldern:

- Sie legen ein Volltextfeld an oder ändern seine Quellfelder,
- ein Quellfeld wird gelöscht, bekommt einen anderen Typ oder wechselt zwischen ein-
  und mehrsprachig,
- die Zielbereiche eines Felds vom Typ „Fest verknüpfte Inhalte“ ändern sich oder das
  Feld wird gelöscht,
- eine Instanz bekommt eine Sprache mit anderen Sortierregeln, oder das Kürzel einer
  Sprache ändert sich (das betrifft alle Bereiche der Instanz).

camindo CMS baut den Volltext dann ***nicht sofort*** neu auf – bei großen Bereichen
würde das den laufenden Betrieb aufhalten, und bei größeren Umbauten wäre es nach jedem
Schritt vergeblich. Stattdessen erscheint in der Detailansicht und in der Feldliste des
Bereichs der Hinweis ***„Der Volltext dieses Bereichs ist seit … nicht mehr
aktuell“***. Bis zur Aktualisierung findet die Suche dort teils noch alte Inhalte.

- ***„Jetzt aktualisieren“*** im Hinweis startet den Neuaufbau als Hintergrundprozess.
  Solange er läuft, steht dort ![hourglass](../_/hourglass.svg) „Hintergrundprozess
  ‚Volltext aktualisieren‘ gestartet“; ein zweiter Neuaufbau desselben Bereichs
  startet nicht.
- Oder Sie überlassen es dem [Zeitplaner](./12-betrieb.md#scheduler): Die Aufgabe
  ***„Volltext neu aufbauen“*** mit der Option ***„Nur veraltete Bereiche“*** – bei
  der Installation eingerichtet, jede Nacht – erledigt alle markierten Bereiche. Ohne die Option baut sie die gewählten
  Bereiche (ohne Auswahl: alle) in jedem Fall neu auf. Einen Bereich, den gerade ein
  anderer Prozess neu aufbaut, überspringt sie.

Nach einem vollständigen Neuaufbau verschwindet der Hinweis.

***Ausnahme:*** Wandeln Sie ein Quellfeld in „Verschlüsselter Text“ um, baut camindo
CMS den Volltext sofort neu auf – sonst stünde der Klartext bis dahin im Suchindex.

Inhaltliche Änderungen an Datensätzen hinter einer „Verknüpfung“ markieren den
Volltext nicht als veraltet. Holen Sie sie mit der Zeitplaner-Aufgabe „Volltext neu
aufbauen“ ***ohne*** „Nur veraltete Bereiche“ nach.

## Verschlüsselte Felder

Beim Typ ***„Verschlüsselter Text“*** liegt der Inhalt verschlüsselt in der Datenbank –
geeignet für personenbezogene Daten wie Telefonnummern oder E-Mail-Adressen. Selbst
wer Zugriff auf die Datenbank oder eine Datenbanksicherung hat, kann ihn ohne die
Schlüsseldatei `.camindo.key` nicht lesen.

Der Preis: Verschlüsselte Felder lassen sich nur nach dem exakten Wert durchsuchen,
nicht mit Platzhaltern, und nicht sinnvoll sortieren. Im CSV-Export erscheinen sie im
Klartext.

Sie wählen den Typ beim Anlegen des Feldes oder wandeln ein bestehendes Textfeld
nachträglich um (siehe [Typ ändern](#typ-ändern)).

## Metafelder

Einige Angaben speichert camindo CMS für jeden Datensatz, unabhängig von seinem
Bereich. Damit die Redaktion sie bearbeiten kann, fügen Sie sie der Maske als
***Metafeld*** hinzu:

| Metafeld | Bedeutung |
|---|---|
| Veröffentlichungsdatum | Der Datensatz erscheint zeitgesteuert auf der Website |
| Ablaufdatum | Der Datensatz verschwindet zum angegebenen Zeitpunkt aus den Listen der Website |
| Gestaltungsvorlage | Auswahl eines der wählbaren Templates |
| Status | Bearbeitungszustand; nur „Freigeschaltet“ erscheint auf der Website |
| Prüfung nötig | Markierung für Inhalte, die noch geprüft werden müssen |
| Eltern-Datensatz | macht den Bereich zu einem ***Baum*** (Seiten mit Unterseiten) |
| Position | Reihenfolge der Datensätze („Anordnen nach …“) |

Ohne Metafeld „Status“ sind neue Datensätze sofort freigeschaltet, ohne
Veröffentlichungs- und Ablaufdatum unbegrenzt sichtbar.

## Besondere Feldnamen

| Name | Wirkung |
|---|---|
| `slug` | Ein Feld „Kurzer Text“ mit diesem Namen liefert die ***Webadresse*** des Datensatzes (`/betriebsfeier-2026` statt `/1758`). Bleibt es leer, füllt camindo CMS es beim Speichern aus dem Hauptfeld. Ein einmal gesetzter Slug ändert sich nicht mit dem Titel – bereits geteilte Links bleiben gültig. Er darf Schrägstriche enthalten (`produkte/schuhe`) und kann mehrsprachig sein. Eindeutig sein muss die Adresse samt [Slug-Prefix](./04-struktur.md#slug-prefix) des Bereichs – in Bereichen mit verschiedenen Präfixen darf derselbe Slug vorkommen |

## Typ ändern

Typ und Mehrsprachigkeit eines bestehenden Feldes lassen sich nicht in der
Bearbeitungsmaske ändern, sondern nur über ***„Typ ändern“*** in der Detailansicht.
camindo CMS wandelt dabei alle vorhandenen Inhalte um:

- zwischen Text- und Zahltypen, etwa „Kurzer Text“ ↔ „Ganzzahl“ oder „Währungsbetrag“,
- zwischen „n:m“ und „n:1“ bei Verknüpfungen und Verweisen,
- zwischen einsprachig und mehrsprachig.

Kann eine Umwandlung Inhalte verlieren (ein Text, der keine Zahl ist; beim Wechsel
auf „n:1“ alle Verknüpfungen außer der ersten; beim Wechsel auf einsprachig alle
Übersetzungen), warnt die Maske vorher. Wird der Feldname auch in anderen Bereichen
verwendet, muss das Feld vorher umbenannt werden – der Typ ist an den Namen gebunden.

Bei der Umwandlung in ***„Verschlüsselter Text“*** fällt das Feld aus allen
Volltextfeldern und dem Standard-Volltext; camindo CMS baut diese für alle Datensätze
des Bereichs neu auf (auch für gelöschte im Papierkorb), damit kein Klartext im
Suchindex zurückbleibt. Ist das Feld das ***Hauptfeld*** des Bereichs, steht in Listen
und Verknüpfungen danach „ID …“ statt des Inhalts.

Sind Datensätze des Bereichs gerade ***zur Bearbeitung geöffnet***, nennt die
Bestätigung deren Anzahl und die Bearbeiter. Die Umwandlung beachtet die
Bearbeitungssperre nicht. Der zwischengespeicherte Arbeitsstand der geöffneten
Datensätze wird mit umgewandelt – auch neu hochgeladene, noch nicht gespeicherte
Dateien. Beim Wechsel eines Datei-Felds auf ***einsprachig*** bleibt dabei nur die
Datei der Hauptsprache. Die geöffnete Eingabemaske kennt aber noch den alten Feldtyp:
Ungespeicherte Eingaben darin lassen sich danach unter Umständen nicht mehr speichern
(etwa nach dem Wechsel auf einsprachig); dann hilft nur, die Bearbeitung abzubrechen
und den Datensatz neu zu öffnen. Bitten Sie die Bearbeiter deshalb am besten, vorher
zu speichern oder abzubrechen.

## Namensregeln

- Feldnamen beginnen mit einem Kleinbuchstaben `a-z` und bestehen danach aus
  Kleinbuchstaben, Ziffern und dem Unterstrich `_`. Namen, die mit einer Ziffer
  oder einem Unterstrich beginnen, sind nicht erlaubt (Namen mit `_` am Anfang verwendet
  camindo CMS selbst, etwa `_default`). Mindestlänge für Namen: 2 Zeichen.
- Der Name ***`fulltext`*** ist für den [Standard-Volltext](#der-standard-volltext)
  reserviert.
- Ein Feldname ist ***systemweit an einen Typ gebunden***: Wurde `titel` einmal als
  „Kurzer Text“ angelegt, kann `titel` in keinem anderen Bereich eine Zahl sein. Das
  ist die Grundlage für die [bereichsübergreifende Abfrage](./02-konzept.md#ein-feldname-eine-bedeutung-über-bereiche-hinweg-filtern).

### Reservierte Namens-Präfixe

Um Typkonflikte zu vermeiden, sind die nachfolgend gelisteten Namenspräfixe reserviert.
Sie eignen sich besonders für [Template-Sätze](./02-konzept.md#template-sätze): Werden
Inhaltsbereiche für fremde Datenbanken exportiert, stellen die Namenspräfixe sicher, dass
die Namen in der fremden Datenbank nicht schon für andere Typen vergeben sind.

| Feldtyp | Präfix |
| --- | --- |
| bit | bit_ |
| chars | chr_ |
| currency | cur_ |
| datetime | dat_ |
| decimal | dec_ |
| encrypted | enc_ |
| file | fil_ |
| float | flt_ |
| fulltext | ftx_ |
| id | rid_ |
| ids | ids_ |
| integer | int_ |
| json | jsn_ |
| password | pwd_ |
| pointer, relation, link | rel_ |
| text | txt_ |
| time | tim_ |
| timestampl | tsl_ |

## Extensions

Eine ***Extension*** erweitert ein Eingabefeld um zusätzliches Verhalten – einen
Texteditor, einen Kalender, eine Symbolauswahl. Sie wählen sie im Feld unter
„Erweiterung“; angeboten werden nur Extensions, die zum Feld- und Darstellungstyp
passen. Die verfügbaren Extensions beschreibt [Kapitel 11](./11-extensions.md).
