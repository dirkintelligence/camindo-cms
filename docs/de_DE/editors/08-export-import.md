# 8 Export und Import

Export und Import holen Inhalte aus camindo CMS heraus und bringen sie wieder hinein.
Damit lassen sich große Datenmengen außerhalb des CMS bearbeiten, übersetzen, sichern
und zwischen Bereichen oder Installationen übertragen.

Sie erreichen beide Funktionen in der Datensatzliste über das Menü
![more_vert](../_/more_vert.svg) → „Export“ bzw. „Import“.

## Die drei Formate

| Format | Wofür | Nötige Gruppenrechte |
|---|---|---|
| ***CSV*** | Tabellen für Excel & Co.: Inhalte nachbearbeiten, auswerten, aus anderen Systemen übernehmen | Zusatzrecht „CSV-Export“ bzw. „CSV-Import“ |
| ***DOCX*** | Word-Datei für Übersetzer | Zusatzrecht „DOCX-Export“ bzw. „DOCX-Import“ |
| ***XML*** | vollständige Sicherung eines Bereichs mit allen Feldern, Sprachen und Dateien | nur Superuser |

## Exportieren

Oben in der Exportmaske wählen Sie den ***Umfang*** – wie bei der
[Stapelverarbeitung](./07-stapelverarbeitung.md#schritt-1-umfang-und-funktion)
entweder das letzte Suchergebnis oder alle Datensätze des Bereichs. Darunter
wählen Sie das Format und Einstellungen. „Exportieren“ startet den Export.

Das Ergebnis ist immer eine ***Zip-Datei***. Sie enthält die Exportdatei und, falls
gewünscht, die hochgeladenen Dateien der Datensätze im Verzeichnis `media`. Der
Download-Link gilt **einmal**: Die Zip-Datei wird beim Herunterladen vom Server
gelöscht, spätestens aber nach 24 Stunden.

Große Exporte (ab 1000 Datensätzen) laufen im Hintergrund. Der Download-Link kommt
dann als interne Nachricht.

### CSV

Eine Zeile je Datensatz, eine Spalte je Feld.

| Einstellung | Bedeutung |
|---|---|
| 1. Zeile enthält Feldnamen | Kopfzeile mit den Feldnamen – für den Re-Import unbedingt beibehalten |
| UTF-8-BOM schreiben (Excel) | sorgt dafür, dass Excel Umlaute und Sonderzeichen richtig erkennt |
| Trennzeichen, Einfassungszeichen, Zeilenumbruch | Voreinstellung `;`, `"` und Windows-Zeilenumbruch – passend für Excel im deutschsprachigen Raum |
| Sprachen | bei mehrsprachigen Websites: Welche Sprachversionen exportiert werden. Mehrsprachige Felder erhalten eine Spalte je Sprache, etwa `titel.de_DE` und `titel.en_US` |
| Felder | Welche Felder exportiert werden – als Tabelle mit Name, Beschriftung und Typ. „alle“ darüber wählt alle sichtbaren Felder aus bzw. – sind schon alle gewählt – wieder ab; zugeklappte Unterfelder („Mehr“) bleiben unberührt |
| Datei exportieren | Upload-Dateien werden in die Zip-Datei aufgenommen. Die Spalte enthält dann den relativen Pfad zur Datei |

Über „Mehr“ zu einem Feld stehen weitere Spalten zur Wahl: bei Verknüpfungen neben der
Nummer (`autor.record_id`) auch Felder der verknüpften Datensätze (`autor.name`), bei
Dateien deren Eigenschaften wie Dateiname, Größe oder Abmessungen. Mehrere
verknüpfte Datensätze stehen in einer Zelle, getrennt durch ` | `.  
![warning](../_/warning.svg) Spalten aus dem Bereich „Mehr“ können nicht re-importiert werden. Sie dienen ausschließlich informativen Zwecken in der Exportdatei.

***Eingebettete Inhalte*** (etwa die Bilder eines Produkts, die Sie direkt in der
Eingabemaske des Produkts bearbeiten) gehören zum Datensatz. Über „Mehr“ stehen
**alle** ihre Felder zur Wahl – auch Dateien und Vorschaubilder, die dann mit in die
Zip-Datei kommen. Je eingebettetem Inhalt steht ein Wert in der Zelle, getrennt durch
` | `, in der Reihenfolge der Eingabemaske (`bilder.titel` = `Vorne | Seite`).
Exportieren Sie `bilder.record_id` mit – beim Re-Import ordnet camindo CMS die Werte
darüber den vorhandenen Inhalten zu, in der angegebenen Reihenfolge.

Beachten Sie: Verschlüsselte Felder erscheinen in der CSV-Datei ***im Klartext***.
Zeitpunkte stehen so in der Datei, wie sie gespeichert sind (Weltzeit).

### DOCX und XML

Siehe [Übersetzen mit Word](#übersetzen-mit-word-docx) und
[Sicherung im XML-Format](#sicherung-im-xml-format).

## Inhalte in Excel nachbearbeiten

Der CSV-Export mit anschließendem Re-Import ist das Werkzeug der Wahl, wenn viele
Datensätze *unterschiedliche* neue Werte bekommen sollen – neue Preise, korrigierte
Adressen, ergänzte Artikelnummern, übersetzte Kurztexte.

1. ***Auswahl eingrenzen.*** Suchen Sie in der Datensatzliste die Datensätze, die Sie
   bearbeiten wollen.
2. ***Exportieren.*** Wählen Sie im Export den Umfang „aus dem letzten Suchergebnis“,
   das Format CSV und die Felder, die Sie bearbeiten wollen. Lassen Sie das Metafeld
   `record_id` unbedingt angehakt – es ordnet die Zeilen beim Re-Import den
   Datensätzen zu.
3. ***Bearbeiten.*** Entpacken Sie die Zip-Datei und öffnen Sie die CSV-Datei in Excel.
   Ändern Sie die Werte, aber nicht die Kopfzeile und nicht die Spalte `record_id`.
   Spalten, die Sie nicht ändern wollen, dürfen Sie löschen.
4. ***Speichern.*** Speichern Sie im Format „CSV UTF-8 (durch Trennzeichen getrennt)“.
5. ***Importieren.*** Wählen Sie in der Datensatzliste „Import“, laden Sie die Datei hoch
   und wählen Sie den Modus ***UPDATE*** („Felder aus der Importdatei überschreiben,
   andere bestehen lassen; nicht vorhandene übergehen“). Die Spalten ordnet
   camindo CMS anhand der Kopfzeile automatisch den Feldern zu.
6. ***Analysieren und importieren.*** Prüfen Sie in der Analyse, was geschehen wird,
   und starten Sie dann den Import. Das Protokoll zeigt das Ergebnis für jeden
   Datensatz.

Hinweise:

- ***„Werte im Datenbankformat“***: Erkennt der Import eine camindo-Kopfzeile, ist diese
  Option vorgewählt: Die Werte werden so gelesen, wie der Export sie geschrieben hat –
  Zeitpunkte in UTC, Währungsbeträge in Cent. Schalten Sie sie aus, wenn Sie Werte
  so eintragen, wie Sie sie in der Eingabemaske tippen würden (`19,90` statt `1990`).
- ***Excel verändert manche Werte.*** Führende Nullen (Postleitzahlen, Artikelnummern)
  und lange Zahlen gehen verloren, wenn Excel sie als Zahl deutet. Formatieren Sie
  solche Spalten vor dem Bearbeiten als Text.
- ***Neue Datensätze:*** Zeilen ohne `record_id` legt der
  Modus UPDATE_OR_INSERT als neue Datensätze an. So übernehmen Sie Daten aus anderen
  Systemen, etwa eine Adressliste.
- ***Verknüpfungen:*** Die Nummern (Record-IDs) der Zieldatensätze stehen
  hintereinander, getrennt durch ` | `. Die Spalte heißt `{feld}.record_id`, etwa
  `autor.record_id`; fehlt eine solche Spalte, ordnet camindo CMS auch eine Spalte
  mit dem bloßen Feldnamen (`autor`) dem Verknüpfungsfeld zu.
- Jeder Datensatz durchläuft beim Import die Eingabeprüfung – ungültige Werte werden
  nicht übernommen, sondern im Protokoll gemeldet.

## Übersetzen mit Word (DOCX)

Der DOCX-Export bereitet die Inhalte eines Bereichs für eine Übersetzung auf – ein
Übersetzer oder ein Übersetzungsbüro braucht dafür keinen Zugang zum CMS.

1. ***Exportieren.*** Wählen Sie im Export den Reiter „DOCX“, die Quellsprache
   („Übersetzung von“), die Zielsprache („Übersetzung nach“) und die mehrsprachigen
   Felder, die übersetzt werden sollen. Unter „Dateien“ legen Sie fest, ob
   „Alle Datensätze in einer Datei“ landen oder „Ein Datensatz je Datei“ – etwa
   um die Arbeit auf mehrere Übersetzer zu verteilen. Die Einzeldateien heißen nach
   Record-ID und Bezeichnung des Datensatzes (`4367-sofa-natur.docx`) und liegen
   zusammen in der Zip-Datei.
2. ***Übersetzen.*** Die Word-Datei enthält je Datensatz eine Tabelle. Die **grau
   hinterlegten Zeilen** enthalten die Zuordnung für den Re-Import und dürfen nicht
   geändert werden. Die ***weißen Zeilen*** enthalten den Text der Quellsprache; der
   Übersetzer überschreibt ihn mit der Übersetzung. Bereits übersetzte Felder sind
   gekennzeichnet.
3. ***Importieren.*** Importieren Sie die übersetzte Datei im selben Inhaltsbereich –
   bei „Ein Datensatz je Datei“ jede Datei für sich.
   Der Import überschreibt nur die Felder der Zielsprache; alle anderen Felder und
   Sprachen bleiben unverändert.

***Eingebettete Inhalte*** werden mit dem Datensatz übersetzt: Ihre mehrsprachigen Felder
stehen in der Feldauswahl als „Feld › Feld des Inhalts“ und in der Tabelle unter den
Feldern des Datensatzes, je Inhalt durchnummeriert. Felder, die im Bereich der
eingebetteten Inhalte nicht mehrsprachig sind, erscheinen nicht. Bettet ein Feld Inhalte
aus mehreren Bereichen ein, entscheidet der Bereich des jeweiligen Inhalts.

Für ostasiatische Zielsprachen kann der Import Satzzeichen und Leerzeichen in ihre
ideografischen Formen umwandeln. Abschnitte in arabischer oder hebräischer Schrift
innerhalb eines Textes lassen sich für die richtige Darstellung markieren
(Right-to-Left).

## Sicherung im XML-Format

Das XML-Format (nur Superuser) ist das ***Backup-Format*** von camindo CMS: Es enthält
den Inhaltsbereich mit allen Feldeinstellungen und die Datensätze mit allen Feldern in
allen Sprachen, genau wie sie gespeichert sind – verschlüsselte Inhalte bleiben
verschlüsselt, Passwörter liegen nur als Prüfwert vor. Auf Wunsch kommen die
Upload-Dateien mit in die Zip-Datei. Vorschaubilder und andere Thumbnails werden nicht
exportiert; sie entstehen beim Import neu.

Eine XML-Datei lässt sich in denselben Bereich zurückspielen – etwa um einen früheren
Stand wiederherzustellen –, in einen anderen Bereich oder in eine andere
camindo-Installation. Wie man ganze Strukturen mit Ordnern, Bereichen und Templates
überträgt, steht im [Handbuch für Administratoren](../admins/09-struktur-export-import.md).

## Importieren

Der Import führt in mehreren Schritten zum Ziel. Bis zum letzten Schritt wird nichts
verändert.

1. ***Datei wählen.*** Laden Sie eine Zip-Datei aus einem Export oder eine einzelne
   XML-, CSV- oder DOCX-Datei hoch. Große Dateien können Sie auch per FTP in das
   Upload-Verzeichnis laden und anschließend über das Ordner-Symbol ![folder_open](../_/folder_open.svg) rechts neben dem Datei-Feld auswählen.
2. ***Einstellungen.*** Je nach Format: Modus, Zuordnung der Spalten, Anzahl der zu
   importierenden und zu überspringenden Zeilen, Einfügeposition neuer Datensätze.
3. ***Analyse.*** camindo CMS zeigt, was der Import mit diesen Einstellungen tun
   würde: wie viele Datensätze hinzugefügt, geändert, ersetzt oder übergangen
   werden. Die ***erweiterte Analyse*** führt jeden Datensatz einzeln auf. „Zurück“
   führt zu den Einstellungen.
4. ***Import.*** Das ***Protokoll*** listet jeden Datensatz farbig mit seinem Ergebnis:
   hinzugefügt, geändert, ersetzt, übersprungen oder fehlgeschlagen – mit dem Grund.

Die Eingabeprüfung der einzelnen Felder läuft erst beim Import. Wie viele Datensätze
tatsächlich übernommen werden, zeigt deshalb erst das Protokoll.

### Modus

| Modus | Wirkung |
|---|---|
| INSERT | Inhalte aus der Datei werden immer als neue Datensätze angelegt. |
| UPDATE | Felder, die in der Importdatei angegeben sind, werden überschrieben. Felder, die nicht angegeben sind, bleiben bestehen. Nicht vorhandene Datensätze werden nicht angelegt. |
| UPDATE_OR_INSERT | Wie UPDATE, aber: nicht vorhandene Datensätze werden angelegt. |
| REPLACE | Alle Felder überschreiben vorhandene Inhalte. Felder, die in der Datei nicht genannt werden, werden **geleert**. Nicht vorhandene Datensätze werden nicht verändert. |
| REPLACE_OR_INSERT | wie REPLACE, aber: nicht vorhandene Datensätze werden angelegt. |

Wenn ein neuer Datensatz erstellt wird, versucht camindo CMS die – in der Datei genannte – record_id zu verwenden. Ist diese bereits vergeben, wird eine neue zugewiesen. Kommen in der
Datei Datensätze vor, die miteinander verknüpft sind, wird das beim Import berücksichtigt:
die Verknüpfungen werden entsprechend an die neuen record_id angepasst.  
Das funktioniert allerdings nur innerhalb eines Importvorgangs. Der Datensatz hat später
keine Kenntnis von seiner früheren record_id.

Für die Nachbearbeitung einzelner Felder ist UPDATE fast immer richtig. REPLACE
eignet sich, um einen Datensatz vollständig auf den Stand der Datei zurückzusetzen.

### Weitere Optionen

| Option | Wirkung |
|---|---|
| „Einfügeposition neuer Datensätze“ | In Bereichen mit Reihenfolge oder Baumstruktur: hinter welchem Datensatz bzw. unter welchem übergeordneten Datensatz neue Datensätze eingefügt werden |
| „Schreiben erzwingen“ | auch Datensätze überschreiben, die gerade jemand bearbeitet. Dessen Änderungen gehen dann verloren |
| „Als Hintergrundprozess ausführen“ | das Protokoll kommt als interne Nachricht. Ab 200 Datensätzen (Voreinstellung) läuft der Import immer im Hintergrund – die Option lässt sich dann nicht abwählen |

### Eingebettete Inhalte beim Import

CSV- und DOCX-Import behandeln einen Datensatz zusammen mit seinen eingebetteten
Inhalten als Einheit: Beide werden gemeinsam gespeichert. Schlägt ein Inhalt fehl
(etwa ein fehlendes Pflichtfeld), wird auch der Datensatz nicht geändert.

* ***CSV:*** Die Werte einer Zelle legen die eingebetteten Inhalte fest. Vorhandene
  Inhalte werden über `…record_id` (ohne diese Spalte: über ihre Reihenfolge)
  zugeordnet und geändert, zusätzliche Werte legen neue Inhalte an (`record_id` = `0`),
  fehlende Inhalte werden entfernt, die Reihenfolge wird übernommen.
  Dateien kommen aus der Zip-Datei, Vorschaubilder entstehen neu. Nennt die Datei zu
  einem Feld keine Spalte, bleiben die Inhalte unverändert – im Modus REPLACE
  werden sie entfernt.
* ***DOCX:*** Die übersetzten Felder der eingebetteten Inhalte werden übernommen.
  Angelegt oder entfernt wird nichts.

Enthält ein Text bei mehreren Inhalten selbst die Zeichenfolge ` | ` oder passt die
Anzahl der Werte nicht, wird der Datensatz mit einer Meldung übergangen.

## Medien-Import

Mit dem Medien-Import machen Sie aus einer Sammlung von Dateien viele Datensätze in
nur einem Schritt, etwa aus einem Ordner mit Produktfotos. Sie finden ihn im Menü
![more_vert](../_/more_vert.svg) rechts über der Datensatzliste als Punkt
![perm_media](../_/perm_media.svg) „Medien-Import“. Voraussetzungen: Der Bereich hat ein Upload-Feld und Ihre Benutzergruppe das Recht, Medien zu importieren.

1. ***Zip-Datei wählen.*** Laden Sie eine Zip-Datei hoch oder wählen Sie eine aus dem
   Upload-Verzeichnis des Servers.
2. ***Einstellungen.*** camindo CMS zeigt die enthaltenen Dateien nach Dateityp
   gruppiert. Wählen Sie die Typen, die importiert werden sollen, das ***Upload-Feld***,
   das die Datei aufnimmt, und optional ein Feld, das den ***Dateinamen als Text***
   erhält (ohne Endung). Minus und Unterstrich werden zu Leerzeichen
   (`Filiale-Graz_2024.jpg` → „Filiale Graz 2024“). Vorgewählt sind das erste
   Upload-Feld und das Hauptfeld des Bereichs.
3. ***Import.*** Aus jeder Datei entsteht ein neuer Datensatz. Das Importprotokoll listet
   das Ergebnis je Datei.

Nicht importierbar und ausgegraut sind ausführbare Dateien, aktive Inhalte (HTML, SVG,
XML, JavaScript), leere Dateien und Dateien, deren Inhalt nicht zur Endung passt.
Versteckte Dateien (Name beginnt mit einem Punkt) und Systemdateien wie `Thumbs.db`,
`desktop.ini` oder der Ordner `__MACOSX` werden ignoriert. Typen, die das gewählte
Upload-Feld nicht annimmt, lassen sich nicht auswählen.
