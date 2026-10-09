# 5 Datensätze bearbeiten

## Die Detailansicht

Ein Klick auf eine Zeile der Datensatzliste öffnet die ***Detailansicht*** des
Datensatzes. Sie zeigt die Reiter „Inhalte“, „Metadaten“ und „Änderungen“.

***Reiter „Inhalte“***: Hier stehen die eingegebenen Werte und hochgeladene Dateien
inkl. der automatisch erzeugten Dateiversionen (beispielsweise Vorschaubilder).
Verknüpfte Datensätze stehen untereinander, jeweils mit Symbol und als Link zu
ihrer Detailansicht. Bei mehr als 20 Verknüpfungen öffnet ein Klick auf 
„mehr…“ die Liste aller verknüpften Datensätze.  
Lange Texte erscheinen gekürzt; ***„mehr…“*** dahinter zeigt den ganzen Text. Wird das Feld
mit einem Editor für Markdown oder HTML bearbeitet (Extensions MarkdownVisual, HTMLVisual),
erscheint der ganze Text formatiert – mit Überschriften, Hervorhebungen, Listen und Links.  
Mehrsprachige Inhalte werden in Ihrer „[bevorzugten Sprachversion](./03-arbeitsoberflaeche.md#persönliche-einstellungen)“ angezeigt. Über die Auswahl ![flag](../_/flag.svg)
unter dem Reiter stellen Sie die Sprache um.  
Die Schaltfläche ***„Volltext anzeigen“*** zeigt, was die Volltextsuche für diesen Datensatz durchsucht –
je Sprache, aus seinen Textfeldern und Dateinamen. Bei mehrsprachigen Inhalten gibt es
einen Reiter je Sprache.

***Reiter „Metadaten“***: Hier stehen die Hintergrundinformationen, wie Datensatznummer,
Bereich, Instanz, Status, Veröffentlichungszeitraum, Template, Owner und wer den Datensatz
wann angelegt und zuletzt geändert hat. Im Abschnitt ***„Verwendet von“***
listet die Schaltfläche „Alle Verweise auf diesen Datensatz“ alle Datensätze, die über ein
Verknüpfungsfeld auf ihn zeigen (uni- oder bidirektional).

***Reiter „Änderungen“*** zeigt den Verlauf des Datensatzes, die neueste Änderung
zuerst: wann, von wem und woher (Backend, Website, Import …) er angelegt, geändert,
gelöscht oder wiederhergestellt wurde. Zu jeder Änderung stehen die geänderten Felder
mit ihrem Stand **vorher** und **nachher** nebeneinander. Bei Texten sind unveränderte
Absätze grau, entfernte rot und neue grün hinterlegt. In einem geänderten Absatz sind
die geänderten Wörter markiert. So lässt sich auch ein versehentlich überschriebener
Text wiederfinden und von Hand zurückholen.


| Schaltfläche | Wirkung |
|---|---|
| ![edit](../_/edit.svg) „Bearbeiten“ | Eingabemaske öffnen |
| ![content_copy](../_/content_copy.svg) „Kopieren“ | Eingabemaske mit einer Kopie des Datensatzes öffnen |
| ![delete](../_/delete.svg) „Löschen“ | Datensatz löschen (nach Rückfrage) |
| ![visibility](../_/visibility.svg) „Vorschau“ | den Datensatz so zeigen, wie ihn die Website darstellt – auch wenn er noch nicht veröffentlicht ist (siehe [Kapitel 9](./09-frontend-editing.md#vorschau)) |
| ![more_vert](../_/more_vert.svg) Menü | ![drive_file_move](../_/drive_file_move.svg) „Verschieben“ in einen anderen Inhaltsbereich, ![mail](../_/mail.svg) „Nachricht“ zu diesem Datensatz an einen Kollegen; bei Benutzerprofilen der Website ![shield](../_/shield.svg) „Datenexport personenbezogener Daten“ (siehe [Handbuch für Administratoren](../admins/15-datenschutz.md#datenexport-personenbezogener-daten)) |

## Anlegen, Bearbeiten, Kopieren

- ***Anlegen:*** In der Datensatzliste auf ![add](../_/add.svg) „Neu“ klicken.
- ***Bearbeiten:*** In der Detailansicht auf ![edit](../_/edit.svg) „Bearbeiten“ klicken
  oder in der Liste per Rechtsklick „Bearbeiten“ wählen.
- ***Kopieren:*** ![content_copy](../_/content_copy.svg) „Kopieren“ öffnet die Eingabemaske
  mit allen Inhalten des Originals, einschließlich Dateien und Verknüpfungen.
  Die Kopie wird direkt hinter dem Original eingeordnet. Fest verbundene Inhalte (etwa
  Text-Bild-Blöcke) werden mitkopiert. In handsortierten Listen steht die Kopie am Ende.
  Wichtig: Andere Datensätze, die auf das Original verweisen, verweisen danach auch auf
  die Kopie. Ausgenommen sind Verknüpfungen, die nur eine Verknüpfung erlauben.

In der Eingabemaske sind ***Pflichtfelder*** mit `*` gekennzeichnet. Ein Fragezeichen
oder ein Hinweis unter der Feldbeschriftung erklärt, was einzugeben ist. „Speichern“
übernimmt die Eingaben, „Abbrechen“ verwirft alle Änderungen. Bitte immer eine dieser
beiden Schaltflächen verwenden, um die Eingabemaske zu schließen – andernfalls bleibt die
Bearbeitungssperre unbeabsichtigt bestehen; andere Benutzer können den Datensatz nicht
bearbeiten; siehe [Bearbeitungssperre](#bearbeitungssperre).

Findet die Eingabeprüfung Fehler, erscheint die Maske erneut; die betroffenen Felder
sind markiert und nennen den Fehler. Ihre Eingaben bleiben erhalten – auch bereits
hochgeladene Dateien.

## Arbeitskopie

Solange Sie einen Datensatz bearbeiten, arbeiten Sie an einer ***Arbeitskopie***. Die
Website zeigt unverändert den bisherigen Stand. Erst „Speichern“ übernimmt Ihre
Änderungen – vollständig und in einem Schritt: Texte, Dateien, Verknüpfungen und alle
Sprachversionen. „Abbrechen“ verwirft die Arbeitskopie; der Datensatz bleibt, wie er
war.

Auch ein Wechsel zwischen den Sprach-Reitern der Maske sichert Ihre Eingaben nur in
der Arbeitskopie.

## Bearbeitungssperre

Öffnet ein Kollege einen Datensatz, den Sie gerade bearbeiten, erhält er einen
Hinweis: „Dieser Datensatz wird aktuell von … bearbeitet“. Er kann die Sperre
aufheben – Sie können Ihre Änderungen dann aber nicht mehr speichern. Heben Sie eine
Sperre deshalb nur auf, wenn Sie sicher sind, dass der Kollege nicht mehr daran
arbeitet (etwa weil er die Maske offen gelassen hat und nicht erreichbar ist).

Eine Sperre verfällt von selbst, wenn an der Arbeitskopie längere Zeit (standardmäßig
90 Minuten) nichts mehr geändert wurde. Ihre eigenen Sperren behindern Sie nicht –
etwa wenn Sie denselben Datensatz in einem zweiten Browserfenster öffnen.

## Eingabefelder

Welche Felder eine Eingabemaske hat, legt der Administrator fest. Die meisten erklären
sich selbst; hier die Besonderheiten.

### Text

Einzeilige und mehrzeilige Textfelder. Manche Textfelder bieten einen ***Editor*** (WYSIWYG-Editor)
mit Werkzeugleiste für Überschriften, Hervorhebungen, Listen und Links. Sie arbeiten wie in
einem Textprogramm.

#### Editor mit formatierter Ansicht

| Symbol | Taste | Wirkung |
|---|---|---|
| ![format_list_bulleted](../_/format_list_bulleted.svg) | | Liste |
| ![format_list_numbered](../_/format_list_numbered.svg) | | Nummerierte Liste |
| ![format_align_left](../_/format_align_left.svg) ![format_align_center](../_/format_align_center.svg) ![format_align_right](../_/format_align_right.svg) | | Textausrichtung linksbündig, zentriert, rechtsbündig |
| ![format_indent_increase](../_/format_indent_increase.svg) ![format_indent_decrease](../_/format_indent_decrease.svg) | Tab / Umschalt+Tab | im Listenpunkt: Einzug vergrößern bzw. verkleinern |
| ![format_bold](../_/format_bold.svg) | Strg+B (Mac: ⌘B) | fett |
| ![format_italic](../_/format_italic.svg) | Strg+I (Mac: ⌘I) | kursiv |
| ![format_underlined](../_/format_underlined.svg) | Strg+U (Mac: ⌘U) | unterstrichen |
| ![link](../_/link.svg) | Strg+K (Mac: ⌘K) | Link |
| ![title](../_/title.svg) | Strg+Alt+1 … 6, Strg+Alt+0 | Überschrift 1–6 bzw. Absatz |
| ![undo](../_/undo.svg) ![redo](../_/redo.svg) | Strg+Z, Strg+Y (Mac: ⌘Z, ⌘⇧Z) | rückgängig, wiederholen |
| ![horizontal_rule](../_/horizontal_rule.svg) | — | Horizontale Linie |
| ![special_character](../_/special_character.svg) | — | Sonderzeichen |
| ![table](../_/table.svg) | — | Tabelle erzeugen oder verändern |
| ![format_textdirection_l_to_r](../_/format_textdirection_l_to_r.svg) ![format_textdirection_r_to_l](../_/format_textdirection_r_to_l.svg) | — | Das Symbol zeigt die aktuelle ***Schreibrichtung***; ein Klick darauf kehrt die Richtung um |
| ![code](../_/code.svg) | — | Quellcode ansehen – abhängig vom Editor Markdown oder HTML |
|  | ⇧⏎ | `Umschalt`+`Return` erzeugt Zeilenumbruch innerhalb des Absatzes, Listenpunktes oder einer Tabellenzelle |
|  | ⏎ | `Return` erzeugt neuen Absatz, Listenpunkt oder Tabellenzeile |
|  | ⏎ ⏎ | 2 × `Return` beendet Textausrichtung, Liste, Tabelle |


***Schreibrichtung:*** Ein Feld schreibt in der Richtung seiner Sprache – ein arabischer
Text von rechts nach links, ein deutscher von links nach rechts. Steht in einem
arabischen Text ein deutsches Wort oder eine Formel (oder umgekehrt), markieren Sie
diesen Teil und klicken auf die Schaltfläche für die Schreibrichtung. Der Teil erscheint dann
gestrichelt umrandet und wird auch auf der Website in seiner Richtung angezeigt.

***Tabellen*** fügen Sie über die Tabellen-Schaltfläche ein; dabei geben Sie die Zahl
der Spalten und Zeilen an. Die erste Zeile ist immer die Kopfzeile. Die Darstellung auf
der Website hängt vom Stylesheet ab, das Ihr Template-Programmierer einbindet.
Befindet sich der Cursor in einer Zelle, bietet die Auswahl ![table](../_/table.svg) zusätzliche Optionen an:
Zeile einfügen oder entfernen, Spalte einfügen oder entfernen. Änderung der Textausrichtung
betrifft immer die ganze Spalte.  
`Umschalt+Return` erzeugt einen Zeilenumbruch innerhalb der Zelle. `Return` beginnt eine neue Zeile.

***Sonderzeichen*** wie geschützte oder schmale Leerzeichen und das weiche
Trennzeichen sind im Editor grau hinterlegt, damit Sie sie sehen.

Beim ***Einfügen*** übernimmt der Editor nur, was er darstellen kann: Formatierungen
aus Word, Google Docs oder Webseiten werden in unterstützte Formatierungen umgewandelt;
Bilder, Farben und Schriften werden entfernt.

### Auswahl

Dropdown-Listen, Radiobuttons, Checkboxen und Schalter (Switches). Bei manchen
Checkbox-Listen lässt sich die Reihenfolge der gewählten Einträge mit Pfeil-Schaltflächen festlegen.

### Zahlen und Beträge

Zahlen geben Sie in der Schreibweise Ihres Gebietsschemas ein (`1.234,50` bzw.
`1,234.50`). Währungsbeträge verlangen genau zwei Nachkommastellen.

### Datum und Uhrzeit

Datumsfelder bieten einen Kalender zur Auswahl. Sie können das Datum aber auch
eintippen – in Ihrer gewohnten Schreibweise. Zeitpunkte werden in Ihrer Zeitzone
eingegeben und angezeigt (siehe [Kapitel 2](./02-anmeldung.md#zeitzone)).

Das Backend zeigt Datum, Uhrzeit und Zahlen in der Schreibweise Ihres
Gebietsschemas an (Beispiel: 26. September 2030, 14:30 Uhr, 1234,5 mit zwei
Nachkommastellen):

| Gebietsschema | Datum | Uhrzeit | Zahl |
|---|---|---|---|
| `ar_SA` (Arabisch) | `٢٦ سبتمبر ٢٠٣٠ م` | `٢:٣٠ م` | `١٬٢٣٤٫٥٠` |
| `bn_BD` (Bengalisch) | `২৬ সেপ, ২০৩০` | `২:৩০ PM` | `১,২৩৪.৫০` |
| `de_DE` (Deutsch) | `26.09.2030` | `14:30` | `1.234,50` |
| `en_US` (Englisch) | `Sep 26, 2030` | `2:30 PM` | `1,234.50` |
| `es_ES` (Spanisch) | `26 sept 2030` | `14:30` | `1.234,50` |
| `fr_FR` (Französisch) | `26 sept. 2030` | `14:30` | `1 234,50` |
| `hi_IN` (Hindi) | `26 सित॰ 2030` | `2:30 pm` | `1,234.50` |
| `ja_JP` (Japanisch) | `2030/09/26` | `14:30` | `1,234.50` |
| `ko_KR` (Koreanisch) | `2030. 9. 26.` | `오후 2:30` | `1,234.50` |
| `pt_BR` (Portugiesisch, Brasilien) | `26 de set. de 2030` | `14:30` | `1.234,50` |
| `ur_PK` (Urdu) | `26 ستمبر، 2030` | `2:30 PM` | `1,234.50` |
| `zh_CN` (Chinesisch, Festland) | `2030年9月26日` | `14:30` | `1,234.50` |

Datum und Uhrzeit zusammen stehen hintereinander, meist durch Komma getrennt
(`26.09.2030, 14:30`). Sekunden erscheinen nur, wenn sie nicht 0 sind (`14:30:15`).

Bei der Eingabe ist camindo CMS großzügiger. Ein Datum dürfen Sie außer in der
angezeigten Form auch kurz schreiben (`26.09.30`, `9/26/30`) oder lang mit
ausgeschriebenem Monat (`26. September 2030`); die internationale Schreibweise
`2030-09-26` (auch `2030-09-26 14:30`) gilt in jedem Gebietsschema. Uhrzeiten
verstehen sich als `14:30`, `14.30`, `14h30`, `14` oder `2:30 pm` – auch hinter einem
Datum (`9/26/2030 14:30`). In Datums-, Zeit- und Zahlenfeldern gelten die Ziffern der
arabischen und bengalischen Schrift ebenso wie 0–9: Bei `ar_SA` dürfen Sie also
`26/09/2030` oder `1234.50` tippen, auch wenn das Backend `٢٦ سبتمبر ٢٠٣٠ م` bzw.
`١٬٢٣٤٫٥٠` anzeigt.

### Passwort

Ein Passwortfeld zeigt den gespeicherten Wert nie an. Lassen Sie es leer, bleibt das
bisherige Passwort erhalten.

### Dateien

In ein ***Upload-Feld*** laden Sie eine Datei von Ihrem Rechner hoch. Welche Dateitypen
erlaubt sind, legt der Administrator fest; eine Datei, deren Inhalt nicht zu ihrer
Endung passt, wird abgewiesen. Die Maske zeigt eine Vorschau bzw. ein Symbol für den
Dateityp und die Eigenschaften der Datei (Name, Größe, Abmessungen).

Sehr große Dateien, etwa Videos, sind für einen Upload über den Browser oft zu groß.
Lassen Sie sie per FTP in das ***Upload-Verzeichnis*** des Servers übertragen und
wählen Sie sie über „![folder_open](../_/folder_open.svg) Datei auf dem Server auswählen“.
Mit dem Schalter ***„nach erfolgreichem Übernehmen löschen“*** unter der gewählten
Datei verschwindet sie aus dem Upload-Verzeichnis, sobald der Datensatz gespeichert
ist; brechen Sie die Bearbeitung ab, bleibt sie liegen.

Aus einer hochgeladenen Datei erzeugt camindo CMS automatisch weitere Versionen – etwa
verkleinerte Bilder, Vorschaubilder oder web-taugliche Videos (***Thumbnails***). Das
geschieht beim Speichern; aufwändige Umwandlungen wie Videos laufen im Hintergrund.
Bis sie fertig sind, zeigt die Maske ein Platzhalterbild.

Bitte beachten: Der ***ursprüngliche Dateiname*** erscheint auf der Website in der Adresse der Datei
(etwa `/media/…/1977,strand-nord.jpg`). Sie ändern ihn in der Detailansicht über den
Stift hinter „Original filename“ – ohne die Datei neu hochzuladen. Die Endung bleibt
dabei unverändert. Solange der Datensatz in Bearbeitung ist, ist das nicht möglich.

### Verknüpfungen

Verknüpfungsfelder verbinden den Datensatz mit Datensätzen aus anderen Bereichen –
einen Artikel mit seinem Autor, seinen Schlagwörtern oder seinen Bildern. Je nach
Einrichtung erscheint ein Verknüpfungsfeld als Auswahlliste oder als ***Lookup-Feld***
mit Suchfenster:

- Der Suchknopf ![Lupe](../_/search.svg) öffnet die Datensatzliste des Zielbereichs mit Checkboxen. Suchen,
  Sortieren und Blättern funktionieren dort wie gewohnt.
  Haben Sie im Suchfeld einen Text eingegeben, werden nur Datensätze mit diesem Text in ihrem Hauptfeld
  gelistet. Auch hier gelten die Filterregeln aus dem Abschnitt [„Die Suchmaske“](./04-inhalte-finden.md#die-suchmaske),
  beispielsweise `*sofa*` für alle Datensätze, die im Hauptfeld „sofa“ oder „Sofa“ enthalten.
  Wird _genau ein_ Datensatz gefunden, erscheint dieser sofort verknüpft in der Vorschauliste unter dem
  Lookup-Feld.
- ![check](../_/check.svg) Haken Sie die gewünschten Datensätze an. Fehlt ein Datensatz noch, legen Sie ihn
   mit „Neu“ direkt an – er wird sofort verknüpft.
- „Fertig“ schließt das Suchfenster. Die verknüpften Datensätze erscheinen in der Vorschauliste unter dem
  Lookup-Feld.
- Die Schaltfläche ![add](../_/add.svg) „Neu“ öffnet unmittelbar die Eingabemaske für einen neuen Datensatz im Zielbereich.
- Die Schaltfläche ![refresh](../_/refresh.svg) „Aktualisieren“ lädt die Vorschauliste mit verknüpften Einträgen unterhalb des
  Suchfelds neu, falls mal ein Eintrag nicht von alleine erscheint.
- Die Schaltfläche ![link_off](../_/link_off.svg) „Verknüpfung aufheben“ neben jedem Eintrag in der Vorschauliste entfernt eine
  einzelne Verknüpfung.
- Die Schaltfläche ![delete](../_/delete.svg) „Leeren“ entfernt alle Verknüpfungen in diesem Feld.
- Mit den Pfeil-Schaltflächen ![arrow_upward](../_/arrow_upward.svg) ![arrow_downward](../_/arrow_downward.svg) ändern Sie die Reihenfolge
  von Verknüpfungen in diesem Feld, sofern das Feld ***manuelle Sortierung*** zulässt.

Verknüpfungen werden an der Arbeitskopie durchgeführt. Erst mit dem Speichern werden die Änderungen
auf den Originaldatensatz übertragen.

Manche Verknüpfungen sind **gegenseitig** (bidirektional): Verknüpfen Sie einen Artikel mit einer
Rubrik, erscheint der Artikel auch auf der Infoseite der Rubrik.
Manche erlauben nur **einen** verknüpften Datensatz.

### Eingebettete Inhalte

Manche Datensätze sind aus Bausteinen zusammengesetzt – etwa eine Seite mit
Text-Bild-Blöcken. Diese Bausteine sind zwar eigenständige Datensätze, gehören aber fest zu
ihrem Hauptdatensatz und werden direkt in seiner Eingabemaske bearbeitet:

![add](../_/add.svg) „Inhalt hinzufügen“ (bzw. „… hinzufügen“ mit dem Namen des Bausteins) legt einen
neuen Baustein an. Die Eingabefelder des Bausteins erscheinen in der Eingabemaske.

Das Menü ![more_vert](../_/more_vert.svg) „Inhalt bearbeiten“ in der linken oberen Ecke jedes Bausteins
bietet folgende Optionen:

- ![vertical_align_top](../_/vertical_align_top.svg) „Einfügen vor“
- ![vertical_align_bottom](../_/vertical_align_bottom.svg) „Einfügen nach“
- ![arrow_upward](../_/arrow_upward.svg) „Nach oben“
- ![arrow_downward](../_/arrow_downward.svg) „Nach unten“
- ![delete](../_/delete.svg) „Löschen“

Alle Änderungen an den Bausteinen werden zusammen erst mit dem Speichern des Datensatzes wirksam.
Kopieren, Verschieben und Löschen des Datensatzes bezieht seine Bausteine mit ein.

## Veröffentlichung und weitere Metadaten

Je nach Einrichtung enthält die Eingabemaske einige der folgenden Felder:

| Feld | Bedeutung |
|---|---|
| Status | Bearbeitungszustand. Auf der Website erscheinen nur **freigeschaltete** Datensätze |
| Veröffentlichungsdatum | Der Datensatz erscheint erst ab diesem Zeitpunkt auf der Website |
| Ablaufdatum | Ab diesem Zeitpunkt erscheint der Datensatz nicht mehr in Listen der Website; über seine Adresse bleibt er erreichbar |
| Gestaltungsvorlage | Template für diesen Datensatz, falls er anders dargestellt werden soll als üblich. „(Standard)“ verwendet das Standard-Template des Bereichs |
| Eltern-Datensatz | übergeordneter Datensatz in Bereichen mit Baumstruktur, etwa die Seite, unter der eine Unterseite steht |
| Position nach … | erlaubt manuelle Sortierung: Der vorangehende Datensatz wird ausgewählt |
| Prüfung nötig | markiert Inhalte, die noch geprüft werden müssen |
| Owner | der Benutzer, dem der Datensatz gehört (nur mit dem Recht „Owner anpassen“) |

Hat der Bereich ein Feld für die ***Webadresse*** (Slug) und bleibt es leer, füllt
camindo CMS es beim Speichern aus dem Hauptfeld (z. B. dem Titel). Aus „Unsere Betriebsfeier
2026“ wird `unsere-betriebsfeier-2026`. Ändern Sie später den Titel, bleibt die Webadresse bewusst
gleich – sonst würden bereits geteilte Links ungültig. Soll der Slug neu erzeugt werden,
leeren Sie das Feld vor dem Speichern. Sie können auch bewusst einen vom Hauptfeld abweichenden
Text eintragen; er wird beim Speichern genauso vereinfacht wie ein aus dem Hauptfeld erzeugter
(Kleinbuchstaben, Leer- und Sonderzeichen werden zu Bindestrichen): aus „Mein Artikel!“ wird
`mein-artikel`. Eine reine Ziffernfolge kann nicht als Slug verwendet werden, da diese mit der Id in Konflikt
geraten würde. Beim Speichern wird ein Minus vorangestellt.

## Verschieben

Ein Datensatz kann in einen anderen Inhaltsbereich umziehen – etwa eine Meldung aus
„Aktuelles“ ins „Archiv“. Wählen Sie in der Detailansicht im Menü ![more_vert](../_/more_vert.svg)
den Punkt ![drive_file_move](../_/drive_file_move.svg) „Verschieben“ und dann den Zielbereich.  
Weil jeder Bereich eigene Felder hat, ordnen Sie anschließend jedem Feld des bisherigen
Bereichs das Feld des Zielbereichs zu, das seinen Inhalt übernehmen soll. Angeboten werden
nur passende Felder. „Löschen“ verwirft den Inhalt eines Feldes.

Beim Verschieben gilt die Eingabeprüfung des Zielbereichs. Fehlen dort Pflichtangaben,
öffnet sich die Eingabemaske des Zielbereichs, und Sie ergänzen die fehlenden Felder.
„Speichern“ schließt das Verschieben ab; „Abbrechen“ lässt den Datensatz unverändert im
bisherigen Bereich.

Nicht verschieben lassen sich Datensätze in einer Baumstruktur mit untergeordneten
Datensätzen und Datensätze, die gerade jemand anderes bearbeitet. Viele Datensätze auf einmal
verschieben Sie mit der [Stapelverarbeitung](./07-stapelverarbeitung.md).

## Löschen

![delete](../_/delete.svg) „Löschen“ entfernt einen Datensatz nach einer Rückfrage.
Hat er untergeordnete Datensätze, wählen Sie, ob diese mitgelöscht oder eine Ebene nach oben gerückt
werden. Fest verbundene Inhalte (eingebettete Bausteine) werden immer mitgelöscht.

Ist der Datensatz mit anderen Datensätzen verknüpft, nennt die Rückfrage deren
Anzahl (***verknüpfte Datensätze***, etwa „Dieser Datensatz ist mit 3 anderen
Datensätzen verknüpft“). Mitgezählt werden alle Verknüpfungen – auch solche, die Sie in
der Eingabemaske nicht sehen, etwa die Artikel einer Rubrik. „Details“ zeigt diese
Datensätze bereichsübergreifend in einer Trefferliste; prüfen Sie dort vor dem Löschen,
ob die Verknüpfungen noch gebraucht werden.

Was beim Löschen genau geschieht, legt die ***Lösch-Policy*** des Inhaltsbereichs
fest; die Rückfrage zeigt es Ihnen vorher, etwa:

> Lösch-Policy „Datensatz löschen“ – Der Datensatz wird in Ihren Papierkorb verschoben.
> Nach 7 Tagen: Endgültige Löschung

Meist kommen gelöschte Datensätze zunächst in den ***Papierkorb*** und werden erst
nach der Aufbewahrungsfrist (standardmäßig 7 Tage) endgültig entfernt. Bis dahin holen
Sie einen versehentlich gelöschten Datensatz selbst zurück (siehe
[Papierkorb](#papierkorb)). Eine Policy kann einen Datensatz aber auch stehen lassen
und nur Felder überschreiben (etwa ein Kundenprofil anonymisieren) oder sofort
endgültig löschen – die Rückfrage sagt es. Beim Löschen eines Profils nennt sie auch,
was mit den Beiträgen des Users geschieht.

## Papierkorb

Jeder Benutzer hat seinen eigenen ***Papierkorb***. Sie öffnen ihn über die Schaltfläche
![delete](../_/delete.svg) „Papierkorb“ auf der Startseite oder über das gleichnamige
[Widget](./03-arbeitsoberflaeche.md#widgets-der-startseite), das die Anzahl der
gelöschten Einträge anzeigt.

Die Liste „Von mir gelöschte Datensätze“ enthält alles, was ***Sie*** gelöscht haben –
der zuletzt gelöschte Datensatz steht oben. Zu jedem Eintrag sehen Sie:

| Spalte | Inhalt |
|---|---|
| Gelöscht am | Zeitpunkt des Löschens |
| Datensatz | Titel und Nummer (ID) des Datensatzes; „mit n Unterdatensätzen“, wenn untergeordnete Datensätze mitgelöscht wurden |
| Bereich | Inhaltsbereich, aus dem der Datensatz stammt |
| Endgültig gelöscht ab | Ab diesem Zeitpunkt entfernt der nächtliche Aufräumlauf den Eintrag unwiderruflich |

![restore_from_trash](../_/restore_from_trash.svg) „Wiederherstellen“ holt den
Datensatz nach einer Rückfrage zurück – mit seinen Dateien, fest verbundenen Inhalten
und Verknüpfungen, an seinen alten Platz.

***Zweige werden als Ganzes wiederhergestellt.*** Haben Sie einen Datensatz mitsamt
seinen untergeordneten Datensätzen gelöscht, steht nur der oberste Datensatz des
Zweigs in der Liste. Beim Wiederherstellen kommen alle Unterdatensätze mit, die mit
ihm zusammen gelöscht wurden. Ein Unterdatensatz, den Sie schon **vorher** einzeln
gelöscht hatten, bleibt im Papierkorb; er erscheint in der Liste, sobald sein
übergeordneter Datensatz wiederhergestellt ist.

Nicht in Ihrem Papierkorb erscheinen:

- Datensätze aus einem Inhaltsbereich, der selbst gelöscht wurde; den stellt nur ein
  Superuser wieder her, und mit ihm seine Datensätze
- Datensätze aus Bereichen, auf die Sie keinen Zugriff (mehr) haben
- Datensätze, die ein anderer Benutzer gelöscht hat

Steht bei einem Eintrag „keine Berechtigung“, fehlt Ihnen inzwischen das Recht, in
diesem Bereich zu löschen und damit auch wiederherzustellen. Wenden Sie
sich dann an Ihren Administrator.
