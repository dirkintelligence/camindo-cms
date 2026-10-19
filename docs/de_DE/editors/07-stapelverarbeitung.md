# 7 Stapelverarbeitung

Mit der ***Stapelverarbeitung*** ändern, verschieben oder löschen Sie viele Datensätze
in einem Arbeitsgang – zum Beispiel:

- allen Produkten einer Serie eine neue Kategorie zuweisen,
- alle Meldungen des Vorjahres deaktivieren oder ins Archiv verschieben,
- ein Ablaufdatum für alle Stellenanzeigen einer Abteilung setzen,
- alle Artikel eines bestimmten Autors sperren.

Die Stapelverarbeitung erreichen Sie in der Datensatzliste über das Menü
![more_vert](../_/more_vert.svg) → „Stapelverarbeitung“. Die Funktion erfordert das
entsprechende Recht Ihrer Benutzergruppe.

## Schritt 1: Umfang und Funktion

***Umfang*** – welche Datensätze sollen bearbeitet werden?

- „aus dem letzten Suchergebnis“: Nur die Datensätze, die Ihre aktuelle Suche
  listet, werden bearbeitet. Grenzen Sie die Auswahl also vorher mit der [Suchmaske](./04-inhalte-finden.md#die-suchmaske) ein – das ist der übliche Weg.
- „Alle“: alle Datensätze des Inhaltsbereichs.

Die Maske nennt jeweils die Anzahl der betroffenen Datensätze.

## Schritt 2: Die Maske der gewählten Funktion

### Bearbeiten

Die Maske sieht aus wie die Eingabemaske des Bereichs, aber mit einer **Checkbox vor jedem
Feld**. Haken Sie die Felder an, die geändert werden sollen, und tragen Sie die neuen
Werte ein. Nur angehakte Felder werden geschrieben. Sobald Sie etwas in ein Feld eingeben, hakt
camindo CMS es automatisch an.

- ***Ein Feld leeren:*** Haken Sie das Feld an und lassen Sie es leer.
- ***Mehrsprachige Felder:*** Jede Sprache hat eine eigene Checkbox. Geändert werden nur
  die angehakten Sprachen – so leeren oder ersetzen Sie etwa nur die englischen Titel und
  lassen die deutschen unberührt.
- ***Verknüpfungen:*** Die hier gewählten Verknüpfungen _ersetzen_ in jedem Datensatz die
  bisherigen des Feldes.
- ***Dateien:*** Eine hier hochgeladene Datei wird in jeden Datensatz übernommen. Ist
  ein Upload-Feld angehakt, aber leer, wird die Datei in jedem Datensatz entfernt.
- ***Eltern-Datensatz:*** Die Datensätze werden unter den gewählten Datensatz gehängt –
  als dessen letzte untergeordnete Datensätze, in ihrer bisherigen Reihenfolge.
- ***Position:*** Wählen Sie den vorangehenden Datensatz. Die Datensätze werden in ihrer
  bisherigen Reihenfolge hinter ihn gestellt; „(an den Anfang)“ stellt sie an den Anfang.
  Die Auswahl zeigt die Datensätze unter dem gewählten Eltern-Datensatz – in Bereichen
  mit Baumstruktur landen die Datensätze also auch unter diesem Eltern-Datensatz. Ist der
  vorangehende Datensatz selbst im Umfang, bleibt er an seinem Platz.

Mit ***bisherige Reihenfolge*** ist die Reihenfolge in der Baumstruktur bzw. die manuelle
Sortierung gemeint, nicht die Sortierung der Liste. Untergeordnete Datensätze, die selbst
nicht im Umfang sind, wandern mit ihrem Eltern-Datensatz mit. Ein Datensatz lässt sich nicht
unter sich selbst oder einen seiner untergeordneten Datensätze hängen – er erscheint dann im
Ergebnis als fehlgeschlagen.

Nicht angeboten werden Felder, deren Wert eindeutig sein muss (derselbe Wert in vielen
Datensätzen wäre nie eindeutig) und Felder, deren Wert automatisch berechnet wird: Volltextfelder und Thumbnails. Auch 1:1-Verknüpfungsfelder („eingebettete Inhalte“) werden hier nicht angeboten.

### Verschieben

Wählen Sie den Zielbereich und ordnen Sie die Felder zu – wie beim
[Verschieben eines einzelnen Datensatzes](./05-datensaetze-bearbeiten.md#verschieben).
Datensätze, die die Eingabeprüfung des Zielbereichs nicht bestehen, bleiben, wo sie
sind, und werden im Ergebnis aufgeführt.

### Löschen

Nach einer Rückfrage werden alle Datensätze des Umfangs gelöscht – wie beim
einzelnen Löschen zunächst in den [Papierkorb](./05-datensaetze-bearbeiten.md#papierkorb).
Dort erscheint jeder Datensatz einzeln und lässt sich einzeln wiederherstellen.

## Optionen

| Option | Wirkung |
|---|---|
| „Simulation“ | Nur prüfen: Jeder Datensatz durchläuft die Eingabeprüfung, geändert wird nichts. So sehen Sie vorab, welche Datensätze scheitern würden – etwa an einem Pflichtfeld |
| „Als Hintergrundprozess ausführen“ | Sie arbeiten sofort weiter; das Ergebnis kommt als interne Nachricht in Ihren Posteingang. Ab 200 Datensätzen (Voreinstellung) läuft die Stapelverarbeitung immer im Hintergrund – die Option lässt sich dann nicht abwählen |

## Das Ergebnis

Jeder Datensatz wird genauso geprüft und gespeichert, als hätten Sie ihn von Hand
bearbeitet: mit Eingabeprüfung, Bearbeitungssperre und allen automatischen Schritten.
Das Ergebnis nennt, wie viele Datensätze erledigt wurden, und listet jeden
fehlgeschlagenen Datensatz mit dem Grund – etwa „Gesperrt: wird seit … von …
bearbeitet“ oder eine Meldung der Eingabeprüfung. Diese Datensätze bearbeiten Sie
anschließend einzeln oder starten die Stapelverarbeitung erneut.

## Massenverarbeitung mittels CSV-Export und Re-Import

Für Änderungen, bei denen jeder Datensatz einen *anderen* Wert bekommt –
etwa neue Preise für hundert Produkte –, ist der
[CSV-Export mit Re-Import](./08-export-import.md#inhalte-in-excel-nachbearbeiten)
das richtige Werkzeug.
