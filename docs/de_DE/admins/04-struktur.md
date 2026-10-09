# 4 Struktur: Ordner und Inhaltsbereiche

Im Modul „Struktur“ legen Sie fest, welche Inhalte camindo CMS verwaltet: Sie
richten Ordner und Inhaltsbereiche ein und definieren deren Eingabefelder. Das
Konzept dahinter beschreibt [Kapitel 2](./02-konzept.md).

## Die Bereichsliste

Das Modul zeigt alle Ordner und Bereiche in ihrer Baumstruktur, bei mehreren
Instanzen gruppiert nach Instanz. „Neu“ legt einen Ordner oder Bereich an, „Suche“
filtert die Liste.

Ausgegraut erscheinen ***abhängige Bereiche*** – etwa die Text-Bild-Blöcke einer
Seite. Diese Bereiche sind im Modul „Inhalte“ nicht direkt zugänglich. Ihre Datensätze werden
direkt über die Eingabemaske des Datensatzes bearbeitet, zu dem sie gehören
(siehe [Kapitel 5](../editors/05-datensaetze-bearbeiten.md#eingebettete-inhalte)).


## Bereichstypen

Beim Anlegen wählen Sie zuerst, was der Bereich enthalten soll:

| Typ | Symbol | Inhalt |
|---|---|---|
| ***RECORD*** | ![draft](../_/draft.svg) | Inhaltsbereich für Datensätze aller Art. Die Eingabefelder fügen Sie selbst hinzu |
| ***PROFILE*** | ![face](../_/face.svg) | Profile registrierter Frontend-User – siehe [Kapitel 13](./13-community.md) |
| ***UGC*** | ![chat_bubble](../_/chat_bubble.svg) | Beiträge, die angemeldete Frontend-User auf der Website anlegen – siehe [Kapitel 13](./13-community.md) |
| ***TEMPLATE*** | ![extension](../_/extension.svg) | Template-Bereich für Seitenlayouts (HTML), PHP-Code und CMS-Befehle – siehe [Kapitel 6](./06-templates.md) |
| ***FOLDER*** | ![folder](../_/folder.svg) | Ordner, der mehrere Bereiche zusammenfasst |

Für RECORD-, PROFILE- und UGC-Bereiche bietet die Auswahl zusätzlich ***Baupläne*** an:
fertige Vorlagen, die den Bereich mit passenden Eingabefeldern anlegen.

| Bauplan | Typ | Felder |
|---|---|---|
| Artikel | RECORD | Titel, Datum, Teaser, Text und Bild |
| Mitglieder | PROFILE | Name, E-Mail (Anmeldename) und Passwort |
| Kommentare | UGC | Name und Kommentartext |

Die Felder eines Bauplans sind ein Ausgangspunkt – Sie können sie danach beliebig
ändern und ergänzen.

### Eigene Baupläne

Eigene Baupläne legen Sie als XML-Dateien im Verzeichnis `var/area_blueprints/`
der Installation ab; die Auswahl beim Anlegen zeigt sie zusammen mit den
mitgelieferten an. Dafür brauchen Sie Zugriff auf das Dateisystem des Servers.

So entsteht ein Bauplan:

1. Richten Sie einen Bereich mit den gewünschten Einstellungen und Eingabefeldern ein.
2. Exportieren Sie nur diesen Bereich mit der Option „Struktur exportieren“, ohne
   Inhalte (siehe [Kapitel 9](./09-struktur-export-import.md#exportieren)).
3. Entpacken Sie die Zip-Datei und speichern Sie die XML-Datei als
   `var/area_blueprints/{name}.xml`, z. B. `var/area_blueprints/termine.xml`.

Regeln:

- Der ***Dateiname*** ohne `.xml` ist der Name des Bauplans: Buchstaben, Ziffern,
  Unterstrich und Minus. Heißt eine Datei wie ein mitgelieferter Bauplan
  (`article`, `member`, `comment`) und hat sie denselben Typ, ersetzt sie diesen.
- Unter welchem Typ der Bauplan erscheint (RECORD, PROFILE oder UGC), bestimmt der
  Bereich in der Datei. Andere Typen erscheinen nicht.
- In der Auswahl steht das ***Label*** des Bereichs als Überschrift und sein
  ***Hinweis*** darunter. Beides darf ein Text oder ein Snippet-Verweis sein
  (`#label_articles`) – ein Verweis erscheint in der Sprache des Benutzers. Die
  Beschriftungen der Felder werden beim Anlegen in der Sprache des Benutzers
  übernommen.
- Ausgewertet wird nur der erste Bereich der Datei, Datensätze darin werden
  ignoriert. Label und Hinweis des neuen Bereichs bleiben leer und werden in der
  Maske eingegeben; Name, Icon und Sortier-Richtung kommen aus der Datei.
- Felder vom Typ ***Verknüpfung***, ***Verweis*** oder ***Fest verknüpfte Inhalte***,
  deren Zielbereich der Bereich selbst ist – etwa „Verwandte Artikel“ in einem
  Artikel-Bereich –, zeigen im neuen Bereich auf den neuen Bereich. Ziele in ***anderen***
  Bereichen (etwa ein Verweis auf einen Bilder-Bereich) lassen sich nicht auflösen: Das
  Feld wird ohne diesen Zielbereich angelegt; tragen Sie ihn nach dem Anlegen in den
  Einstellungen des Feldes nach.

Sichern Sie `var/area_blueprints/` zusammen mit den übrigen Projektdaten
(siehe [Kapitel 12](./12-betrieb.md)).

## Einstellungen eines Inhaltsbereichs

| Einstellung | Bedeutung |
|---|---|
| Name | interner Name für die Templates, z. B. `artikel` – Kleinbuchstaben, Ziffern und Unterstrich, beginnend mit einem Buchstaben. Templates sprechen Bereiche über diesen Namen an, nicht über ihre Nummer; ein Bereich lässt sich so neu anlegen oder umziehen, ohne Templates zu ändern |
| Icon | Symbol im Backend, auswählbar über eine Suche |
| Label | Name des Bereichs im Backend |
| Instanz | Website, zu der der Bereich gehört. Seine Datensätze gehören zur selben Instanz |
| Eltern-Ordner, Vorangehendes Element | Platz im Bereichsbaum |
| Hinweis | erscheint über der Datensatzliste – möglichst kurz halten. Ein Snippet-Verweis (`#custom.schluessel`) erscheint wie bei der Bezeichnung in der Sprache des Benutzers |
| Haupt-Feld | Feld, das den Datensatz in Listen und Verknüpfungen vertritt (meist Titel oder Name) – erst nach dem Anlegen der Felder wählbar |
| Standard-Sortierung, Sortier-Richtung | Reihenfolge der Datensätze in der Datensatzliste und Vorgabe für Templates |
| Mehrsprachige Eingabe | „Alle Sprachen auf einer Seite“ oder „Ein Reiter je Sprache“ – wie die Eingabemaske mehrsprachige Felder zeigt |
| Slug-Prefix | optionaler erster Teil der Adresse aller Datensätze des Bereichs, je Sprache (siehe [unten](#slug-prefix)) |
| Lösch-Policy | Regel, nach der Datensätze und Audit-Logs dieses Bereichs behandelt werden; vgl. [Kapitel 15](./15-datenschutz.md#löschen-nach-lösch-policy). Die Policy gilt für jedes Löschen – im Backend, über die API und mit CMS:USER_DELETE im Frontend. Vorgewählt ist die Standard-Policy zum Bereichstyp („Datensatz löschen“, „Profil löschen“, „User-Generated-Content löschen“) |
| Abhängiger Bereich | Die Datensätze gehören exklusiv je einem Datensatz eines anderen Bereichs (siehe [Fest verknüpfte Inhalte](./05-eingabefelder.md#fest-verknüpfte-und-eingebettete-inhalte)). Der Bereich erscheint dann nicht in der Bereichsliste des Moduls „Inhalte“ |
| Standard-Template | Template, mit dem jeder Datensatz des Bereichs im Frontend ausgegeben wird, solange im Datensatz kein anderes gewählt ist |
| Wählbare Templates | Templates, die Redakteure im Datensatz alternativ wählen dürfen (Metafeld „Gestaltungsvorlage“) |
| URL-Override-Templates | Templates, die über die Adresse eines Datensatzes anstelle des zugewiesenen aufgerufen werden dürfen – etwa die Druckversion eines Inhalts oder die Ausgabe in einem anderen Format (`/meldungen,xml_rss`) |
| Übersichts-Template | Template, das aufgerufen wird, wenn die Adresse nur aus dem Slug-Prefix besteht (`/de/termine`) – eine Übersicht des Bereichs ohne Datensatz, etwa die Liste aller Termine. Ohne Übersichts-Template ergibt diese Adresse „Seite nicht gefunden“. Mit Übersichts-Template und Slug-Prefix erhält der Bereich die Schaltfläche „Vorschau“ (siehe [Übersichts-Templates](./06-templates.md#übersichts-templates)) |
| Listen-Template (Backend) | nur für das Backend: formatiert je Datensatz eine zusätzliche letzte Spalte der Datensatzliste; etwa einen direkt angezeigten RGB-Farbwert |
| Trigger-Templates | Templates, die bei Ereignissen der Datensätze aufgerufen werden und PHP-Code ausführen – beim Speichern, Löschen, Wiederherstellen und bei den Aktionen der Lösch-Policy (siehe [Handbuch für Template-Programmierer](../templateprogrammers/03-trigger-templates.md)). Sie können mehrere zuweisen; sie laufen nacheinander in der angezeigten Reihenfolge, die Sie mit den Pfeil-Schaltflächen ![arrow_upward](../_/arrow_upward.svg) ![arrow_downward](../_/arrow_downward.svg) ändern |
| Zugriff für Benutzergruppen | Erscheint nur beim Anlegen eines neuen Bereichs: welche Benutzergruppen den neuen Bereich nutzen dürfen; später im Reiter „Zugriff“ änderbar. |

PROFILE- und UGC-Bereiche haben zusätzliche Einstellungen für die Anmeldung auf der
Website (siehe [Kapitel 13](./13-community.md)).

### Slug-Prefix

Ein Inhaltsbereich kann den Adressen seiner Datensätze ein Präfix voranstellen. Damit
dürfen gleichnamige Slugs in verschiedenen Bereichen einer Instanz vorkommen:

| Bereich | Slug-Prefix | Slug | Adresse |
|---|---|---|---|
| Farben | `farben` | `blau` | `/farben/blau` |
| Farben | `farben` | `andere/rot` | `/farben/andere/rot` |
| Orte | – | `bayern/schwabing` | `/bayern/schwabing` |
| Orte | – | `rot` | `/rot` |

- Das Präfix ist ein einzelner Teil der Adresse – ohne Schrägstrich. Slugs der
  Datensätze dürfen dagegen Schrägstriche enthalten.
- Es wird je Sprache der Instanz eingegeben (alle Sprachen untereinander); eine leere
  Sprache übernimmt das Präfix der Hauptsprache.
- Ordner und Template-Bereiche haben kein Präfix. Beim Kopieren eines Bereichs wird
  es nicht übernommen.
- Die Adresse aus dem Präfix allein (`/de/termine`) zeigt das ***Übersichts-Template***
  des Bereichs, wenn eins zugewiesen ist.

Die Adresse als Ganzes muss eindeutig sein – innerhalb der Instanz und der Instanzen, die
über „Lesbare Instanzen“ mit ihr zusammengeschaltet sind:

- Zwei Bereiche können nicht dasselbe Präfix führen.
- Ein Datensatz eines Bereichs **ohne** Präfix darf nicht mit einem Präfix beginnen:
  der Slug `farben/gelb` im Bereich Orte wird beim Speichern abgelehnt
  („'farben/' ist ein reservierter Slug-Prefix von Inhaltsbereich …“).
- Ein Präfix setzen, ändern oder entfernen lehnt camindo CMS ab, wenn dadurch zwei
  Datensätze dieselbe Adresse hätten oder vorhandene Adressen mit dem neuen Präfix
  beginnen – die Meldung nennt den betroffenen Datensatz.

Wird eine Adresse aufgerufen, prüft camindo CMS zuerst, ob ihr erster Teil das Präfix
eines Bereichs ist, und sucht den Rest dann nur dort; sonst den ganzen Pfad in den
Bereichen ohne Präfix. Links erzeugen Templates mit [CMS:URL](../templateprogrammers/url.md) –
das Präfix kommt dabei automatisch hinzu.

## Die Reiter eines Bereichs

Die Detailansicht eines Bereichs hat die Reiter:

- „Einstellungen“ – die Einstellungen oben, mit „Bearbeiten“, „Kopieren“ und
  „Löschen“;
- „Eingabefelder“ – die Felder des Bereichs (siehe [Kapitel 5](./05-eingabefelder.md));
- „Zugriff“ – welche Benutzergruppen den Bereich lesen, schreiben und löschen
  dürfen (siehe [Kapitel 7](./07-benutzer-und-rechte.md#5-bereichsrechte));
- „Datensätze“ bzw. „Templates“ – die Inhalte des Bereichs.

## Bereich kopieren

„Kopieren“ legt einen neuen Bereich mit allen Einstellungen an – bei Inhaltsbereichen
samt allen Eingabefeldern, Standard- und Sortierfeld und Template-Zuweisungen.
Datensätze und Templates werden nicht kopiert, der Slug-Prefix ebenfalls nicht.
Beschriftungen, die auf Snippets der Instanz verweisen, bleiben Verweise – bei einer Kopie
in eine andere Instanz kommen die Snippets in deren Set. In der Eingabemaske vergeben Sie Label,
Name, Instanz und Platz im Baum der Kopie. Nach dem Speichern zeigt camindo CMS die Detailansicht
der Kopie.

Verweist ein Verknüpfungsfeld des Originals auf den Bereich selbst (etwa „Verwandte
Artikel“), verweist es in der Kopie auf die Kopie. Verknüpfungen zu anderen Bereichen
bleiben bestehen; beidseitige Verknüpfungen erhalten dort ein neues Gegenfeld.

Ganze Ordner mit Bereichen, Templates und Inhalten kopieren Sie per
[XML-Export und -Import](./09-struktur-export-import.md).

## Bereiche und Ordner löschen

Ein Inhaltsbereich wird ***samt seinen Datensätzen*** gelöscht, ein Template-Bereich
samt seinen Templates; die Rückfrage nennt die Anzahl. Beim Löschen eines Ordners
wählen Sie, ob die enthaltenen Bereiche mitgelöscht oder eine Ebene nach oben
gerückt werden.

Gelöschte Bereiche kommen wie Datensätze in den Papierkorb und werden erst nach der
Aufbewahrungsfrist endgültig entfernt (siehe [Kapitel 12](./12-betrieb.md#papierkorb)).
Bis dahin kann ein Superuser sie wiederherstellen – mit allen Unterbereichen und
Datensätzen, die mit ihnen gelöscht wurden.

Die Datensätze eines gelöschten Bereichs gehen mit ihm in den
[Papierkorb](./10-module.md#papierkorb) und werden nach einigen Tagen endgültig
gelöscht. Die Lösch-Policy des Bereichs wirkt dabei mit den Blöcken für den Auslöser
„Bereich wurde gelöscht“ (siehe [Kapitel 15](./15-datenschutz.md#aufbau-einer-policy)),
die Fristen gerechnet ab dem Löschen – mit den Standard-Policies wird der Verlauf im
Audit-Log nach 1 Jahr gelöscht. Ist es ein Profil-Bereich, werden die Beiträge der
User sofort nach ihren eigenen Policies gelöscht (Auslöser „UGC: Profil wurde
gelöscht“) – das macht eine Wiederherstellung des Bereichs nicht rückgängig.

## Instanzen

Betreiben Sie mehrere Instanzen, gehört jeder Teilbaum einer Instanz: Ein Bereich
kann nur in einem Ordner derselben Instanz stehen. Wechselt ein Bereich die Instanz,
ziehen seine Datensätze, Templates und Mediendateien mit.
