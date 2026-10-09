# 4 Inhalte finden

Alle Datensätze pflegen Sie im Modul „Inhalte“.

## Der Bereichsbaum

Die Startseite des Moduls zeigt links den Baum der ***Ordner*** und
***Inhaltsbereiche***, auf die Sie Zugriff haben. Ein Klick auf einen Ordner klappt ihn
auf oder zu; ein Klick auf einen Inhaltsbereich öffnet dessen Datensatzliste.
Betreibt Ihre Installation mehrere Websites (Instanzen), steht jede als eigener Hauptordner im
Baum.

Über dem Baum stehen die Schaltflächen ![search](../_/search.svg) „Suche“ (für die Suche
über mehrere Bereiche, siehe [unten](#bereichsübergreifende-suche)) und
![refresh](../_/refresh.svg) „Aktualisieren“.

## Zuletzt geändert

Rechts neben dem Bereichsbaum listet ***„Zuletzt geändert“*** die zehn Datensätze, die
Sie selbst zuletzt in der Eingabemaske gespeichert haben – der jüngste steht oben.
Jeder Eintrag zeigt die Felder, die auch in der Datensatzliste seines Bereichs
erscheinen, untereinander (das Hauptfeld fett), darunter den Zeitpunkt Ihres letzten
Speicherns. Ein Klick öffnet die Detailansicht.

Die Liste ist persönlich: Änderungen anderer Benutzer erscheinen hier nicht. Ebenso
wenig Datensätze, die über Import oder Stapelverarbeitung geändert wurden.
Datensätze im Papierkorb und solche, auf die Sie keinen Zugriff mehr haben, werden
ausgeblendet. Haben Sie noch nichts gespeichert, bleibt der Bereich leer.

Dieselbe Liste gibt es im Modul „Templates“ für die zuletzt gespeicherten
Templates: Name, Beschriftung und Template-Bereich.

## Die Datensatzliste

Die Datensatzliste zeigt die Datensätze eines Inhaltsbereichs, 20 je Seite; darunter
blättern Sie weiter. Welche Spalten sie zeigt, legt der Administrator je
Inhaltsbereich fest. Über der Liste stehen ein Hinweis zum Bereich (falls
hinterlegt) und die Schaltflächen:

| Schaltfläche | Wirkung |
|---|---|
| ![add](../_/add.svg) „Neu“ | neuen Datensatz anlegen |
| ![search](../_/search.svg) „Suche“ | Suchmaske des Bereichs öffnen |
| ![refresh](../_/refresh.svg) „Aktualisieren“ | Liste neu laden |
| ![more_vert](../_/more_vert.svg) Menü | [Export](./08-export-import.md), [Import](./08-export-import.md#importieren), [Medienimport](./08-export-import.md#medien-import), [Stapelverarbeitung](./07-stapelverarbeitung.md), Einstellungen des Bereichs (je nach Rechten) |

- ***Sortieren:*** Klick auf eine Spaltenüberschrift. Ohne Auswahl gilt die
  Standard-Sortierung des Bereichs.
- ***Nach Status filtern:*** Die Überschrift der Status-Spalte hat ein eigenes Menü.
  Damit werden nur Datensätze mit einem bestimmten Zustand angezeigt,
  zum Beispiel nur deaktivierte Datensätze oder nur Beiträge von
  Website-Besuchern, die noch geprüft werden müssen („Ungeprüft“).
- ***Hierarchie:*** In Bereichen mit Baumstruktur (etwa Seiten mit Unterseiten)
  zeigt die Liste die Datensätze in ihrer Rangfolge und eingerückt.
- Klick auf eine Zeile öffnet die ***Detailansicht***, ein Rechtsklick das
  Kontextmenü („Bearbeiten“, „Kopieren“, „Löschen“).

Ist eine Suche aktiv, steht über der Liste, wonach gesucht wurde. Die Liste merkt
sich Suche, Sortierung und Blätterposition, solange Sie in dieser Ebene arbeiten.
Ein Klick auf das X rechts außen setzt die Filter zurück auf die Standardeinstellungen
des Bereichs.

## Die Suchmaske

Die Suchmaske eines Inhaltsbereichs bietet alle Felder an, die der Administrator für
die Suche freigegeben hat, dazu immer die Felder „Id, Slug oder URL“ und „Volltext“,
das Veröffentlichungs- und Ablaufdatum, Erstellungs- und Änderungsdatum sowie die
Sortierung des Ergebnisses. In Bereichen mit Baumstruktur kommt zum Vaterdatensatz
die [Tiefe](#tiefe-im-baum) hinzu, in Bereichen für Benutzerbeiträge der
[Frontend-User](#frontend-user); weitere Felder lassen sich
[vorübergehend hinzufügen](#weitere-felder).

Die Grundregeln:

- ***Suchbegriffe in unterschiedlichen Feldern***: alle ausgefüllten Felder müssen zutreffen (UND-Verknüpfung).
- ***Suchbegriffe im selben Feld***: es genügt, wenn einer davon zutrifft (ODER-Verknüpfung).
  Unterschiedliche Begriffe können mittels Komma oder `OR` getrennt werden, z. B. `Hamburg, Bad Homburg`.
- Groß- und Kleinschreibung und Akzente spielen keine Rolle, `ß` und `ss` sind gleich.
- Es gelten die ***Regeln der Sprache***, in der Sie suchen: im Deutschen ist `ä` gleich
  `ae` (`Mueller` findet „Müller“), im Schwedischen ist `ö` ein eigener Buchstabe (`o`
  findet kein `ö`).

### Platzhalter

Der Stern `*` steht für eine beliebige Zeichenfolge:

| Eingabe | findet |
|---|---|
| `Schmi*` | Schmidt, Schmitt, Schmied |
| `*mann` | Hoffmann, Kaufmann |
| `*berg*` | Heidelberg, Bergmann, Goldberger |

Ohne Stern sucht camindo CMS nach genau diesem Wert.

### Vergleichsoperatoren

Ein Zeichen vor dem Suchwert ändert den Vergleich:

| Eingabe | Bedeutung |
|---|---|
| `Berlin` oder `=Berlin` | gleich |
| `!Berlin` | ungleich – alles außer Berlin |
| `!*mann` | alles, was **nicht** auf „mann“ endet |
| `==Stern*` | exakt gleich – der Stern gilt hier als normales Zeichen |
| `>100`, `>=100` | größer als, größer oder gleich |
| `<100`, `<=100` | kleiner als, kleiner oder gleich |
| `=` | Feld ist leer |

Ein Suchwert, der mit einem HTML-Tag beginnt, ist kein Vergleich: `<section>` sucht
genau diesen Wert. Um Texte oder Template-Code zu finden, die ein Tag ***enthalten***,
setzen Sie Sterne: `*<section>*` – oder `*<section*`, wenn das Tag Attribute haben kann.

### Mehrere Bedingungen in einem Feld

Mit `AND` und `OR` (umgeben von Leerzeichen) verknüpfen Sie Bedingungen im selben
Feld:

| Eingabe | findet |
|---|---|
| `Hen* OR Her*` | beginnt mit „Hen“ oder mit „Her“ |
| `>2 AND <4` | Werte zwischen 2 und 4 |
| `!2 AND !3` | weder 2 noch 3 |

Bitte beachten: Komma und `AND`/`OR` lassen sich nicht im selben Feld mischen.

### Datum und Uhrzeit

Datumsangaben dürfen Sie in Ihrer gewohnten Schreibweise eingeben (`15.06.2023`)
oder im internationalen Format (`2023-06-15`). Unvollständige Angaben ergänzt
camindo CMS sinnvoll:

| Eingabe | findet |
|---|---|
| `2023` | alles im Jahr 2023 |
| `>=2023-06` | ab dem 1. Juni 2023 |
| `<2023-06-15` | vor dem 15. Juni 2023 |
| `>2012 AND <2014` | alles im Jahr 2013 |
| `today` | alles von heute |
| `>=today-7d` | ab dem Tag vor einer Woche |
| `<now-2h` | älter als zwei Stunden |
| `utc_now`, `utc_today` | wie `now`/`today`, aber in Weltzeit |

`now` (jetzt) und `today` (heute, 0 Uhr) gelten in Ihrer Zeitzone; dahinter darf ein
Versatz in Sekunden (`s`), Minuten (`m`), Stunden (`h`) oder Tagen (`d`) stehen.

### Noch nicht übersetzt: `(empty)`

Der Suchwert `(empty)` findet Datensätze, die in einem Feld ***keinen eigenen Inhalt***
haben – entweder weil das Feld leer ist oder weil für diese Sprache automatisch der
Inhalt aus der Hauptsprache eingesetzt wurde (unvollständige Übersetzung). Bei einem ***Datei-Feld***
findet `(empty)` Datensätze ohne hochgeladene Datei. `!(empty)` findet das Gegenteil.  
Mehr dazu in [Kapitel 6](./06-mehrsprachigkeit.md#fehlende-übersetzungen-finden).

### Id, Slug oder URL

In „Id, Slug oder URL“ können Sie einfügen, was Sie gerade zur Hand haben: die
Datensatznummer (`4902`), den Slug (`gt`, mit Slug-Präfix `modelle/gt`) oder eine
ganze Adresse der Website (`https://www.example.com/de_DE/modelle/gt`). Die Sprache in
der Adresse spielt keine Rolle; camindo CMS zeigt den Datensatz in Ihrer „[bevorzugten Sprachversion](./03-arbeitsoberflaeche.md#persönliche-einstellungen)“.

### Volltextsuche

Jeder Inhaltsbereich hat das Suchfeld ***„Volltext“***: Es durchsucht alle Textfelder
des Bereichs und die Dateinamen hochgeladener Dateien auf einmal – samt der Texte fest
verknüpfter Inhalte (etwa der Bildtitel eines Produkts) – in der Sprache, die Sie gerade
bearbeiten. Verschlüsselte Felder und Passwörter gehören nicht dazu. Weitere
Volltextfelder kann der Systemadministrator über ausgewählte Felder einrichten – auch
aus verknüpften Datensätzen, etwa die Schlagwörter eines Produkts. Für alle Volltextfelder gelten eigene Regeln:

| Eingabe | findet Datensätze, die … |
|---|---|
| `müller meier` | „müller“ oder „meier“ enthalten |
| `+müller +meier` | beide Wörter enthalten |
| `+müller -gesperrt` | „müller“ enthalten, aber nicht „gesperrt“ |
| `sofa-weiss` oder `sofa_weiss` | die Wörter „sofa weiss“ direkt hintereinander |

Mit Binde- oder Unterstrich verbundene Wörter findet die Suche getrennt und
zusammengeschrieben: „sofa-weiss“ finden Sie mit `sofa-weiss`, `sofaweiss` und `weiss`,
„E-Mail“ auch mit `EMail`. Der Punkt trennt Wörter nur – auch in Dateinamen:
`sofa-weiss_vorn.jpg` wird mit `vorn`, `sofa-weiss`, `sofaweissvorn` oder `jpg` gefunden.

Auch die Volltextsuche folgt den ***Regeln der Sprache***: im Deutschen findet `Mueller`
„Müller“ und umgekehrt, `Muller` dagegen nicht; im Schwedischen findet `forvaring` kein
„förvaring“.

Platzhalter gibt es in der Volltextsuche nicht. Sehr kurze Wörter (unter drei
Buchstaben) werden nicht gefunden, HTML-Auszeichnungen ebenso wenig: Wer nach
`<section>` sucht, findet in der Volltextsuche nichts – dafür gibt es die Suche mit
Sternen in einem Textfeld (siehe oben).

### Verschlüsselte Felder

Felder, die verschlüsselt gespeichert werden (etwa Telefonnummern oder
E-Mail-Adressen), lassen sich nur nach dem **vollständigen** Wert durchsuchen –
ohne Platzhalter und ohne Größer/Kleiner. Groß- und Kleinschreibung, Leerzeichen
und Satzzeichen spielen dabei keine Rolle:

| Eingabe | findet den gespeicherten Wert |
|---|---|
| `anna.muster@example.com` | „Anna.Muster@Example.com“ |
| `+49 171 123456` | „0171/12 34 56“ nicht, aber „49 171-123-456“ |

Teile eines Werts (etwa nur `muster` oder nur die Domain) findet die Suche nicht.
Die Ziffern selbst müssen übereinstimmen – `0171…` und `+49 171…` sind für die
Suche verschiedene Nummern.

### Verknüpfte Datensätze

Um Verknüpfungen herzustellen, gibt es mehrere Eingabemöglichkeiten, die Ihr
Administrator einrichten kann. Können sehr viele Datensätze ausgewählt werden,
ist eine Direktauswahl unübersichtlich. Hier dient ein Lookup-Feld für die Auswahl.
Es funktioniert analog zum [Lookup-Feld](./05-datensaetze-bearbeiten.md#verknüpfungen)
in der Eingabemaske – lediglich die Schaltfläche ![add](../_/add.svg) fehlt.
Eine Mehrfachauswahl wird mit ODER kombiniert.

### Dateien

Das Suchfeld eines Upload-Felds durchsucht den ***gespeicherten Dateinamen*** – mit
Platzhaltern wie jedes Textfeld (`*urlaub*`, `*.pdf`). Darunter klappen Sie
***„Datei-Eigenschaften“*** auf und suchen nach weiteren Angaben der Datei:

| Feld | Inhalt | Beispiele |
|---|---|---|
| Breite, Höhe | Abmessungen eines Bildes oder Videos in Pixel | `>=1920`, `<800`, `>1000 AND <2000` |
| Dateigröße | Größe in Byte; mit Multiplikator `k` (×1024), `M` (×1024²), `G` (×1024³), ein angehängtes `B` ist erlaubt | `>500k`, `<2.5M`, `>1M AND <5MB`, `>=1G` |
| Mime-Type | Dateityp, wie ihn der Server erkannt hat | `image/jpeg`, `image/*`, `video/*`, `application/pdf` |
| Dateiendung | Endung, mit oder ohne Punkt | `pdf`, `.jpg, png`, `!webp` |

Ausgefüllte Eigenschaften müssen ***zusammen*** auf dieselbe Datei zutreffen (UND):
`Breite >=1920` und `Dateigröße <500k` findet große Bilder mit wenig Speicherbedarf.
Mehrere Werte in einem Feld (`jpg, png`) gelten wie überall als ODER. Bei der
Dateigröße ist der Punkt das Dezimalzeichen (`2.5M`) – das Komma trennt Suchwerte.
Haben die Eigenschaften einen Wert, ist der Bereich beim nächsten Öffnen der
Suchmaske schon aufgeklappt.

### Tiefe im Baum

In Bereichen mit Baumstruktur (etwa Seiten mit Unterseiten) steht direkt unter dem
Feld für den ***Vaterdatensatz*** das Feld ***„Tiefe“***. Es bezieht sich auf den dort
gewählten Datensatz – wie `filter[depth]` bei
[CMS:SELECT](../templateprogrammers/select.md):

| Tiefe | findet unter dem gewählten Vaterdatensatz |
|---|---|
| leer | alle Nachkommen |
| `1` | nur die direkten Kinder |
| `2` | Kinder und Enkel |

Ohne gewählten Vaterdatensatz hat die Tiefe keine Wirkung.

### Frontend-User

Dürfen Besucher der Website in einem Bereich Inhalte anlegen (Bereich für
Benutzerbeiträge), zeigt die Suchmaske das Lookup-Feld ***„Frontend-User“***: Wählen
Sie ein oder mehrere Benutzerprofile aus, um nur deren Beiträge zu finden.

### Weitere Felder

Unter der Schaltfläche „Absenden“ steht die Auswahl ***„Weitere Felder“***. Sie enthält die
Felder des Bereichs, die der Administrator nicht für die Suchmaske freigegeben hat.
„Hinzufügen“ lädt die Suchmaske mit dem gewählten Feld neu – bereits eingegebene
Suchbegriffe bleiben stehen. So lassen sich nacheinander mehrere Felder hinzufügen;
sie sind gelb markiert und gelten nur für diese Suche (auch beim erneuten Öffnen der
Suchmaske aus der Trefferliste heraus). Die freigegebenen Felder des Bereichs ändern
sich dadurch nicht.

## Bereichsübergreifende Suche

Die Schaltfläche „Suche“ auf der Startseite des Moduls durchsucht mehrere Inhaltsbereiche
auf einmal. Die Maske zeigt nur Felder, die in allen Bereichen durchsuchbar sind.
Hilfreich ist v. a. das Volltext-Feld, in dem Inhalte aus allen Textfeldern eines Datensatzes
gespeichert sind (außer verschlüsselten Werten und Passwörtern).

Über ***„Weitere Felder“*** (siehe [oben](#weitere-felder)) fügen Sie hier
Eigenschaften hinzu, die alle Datensätze haben:

| Feld | sucht nach |
|---|---|
| Status | Zustand des Datensatzes |
| Frontend-User | Benutzerprofil, das den Beitrag auf der Website angelegt hat |
| Owner | Benutzer, dem der Datensatz im Backend gehört |
| Instanz | Website, zu der der Datensatz gehört (nur bei mehreren Websites) |
| Prüfung nötig | Beiträge von Website-Besuchern, die noch geprüft werden müssen |
| Template | Template, mit dem der Datensatz ausgegeben wird |