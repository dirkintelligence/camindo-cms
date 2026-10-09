# 6 Mehrsprachige Inhalte

## Sprachversionen

Jede Website (Instanz) hat eine ***Hauptsprache*** und kann weitere Sprachen führen –
zum Beispiel Deutsch als Hauptsprache, dazu Englisch und Französisch. Jede Sprache
ist eine ***Sprachversion*** der Website mit eigener Adresse, etwa
`www.example.com/en/…`.

Nicht jedes Feld ist mehrsprachig.

- ***Mehrsprachige Felder*** (Titel, Text, Bildunterschrift …) haben je Sprache einen
  eigenen Inhalt.
- ***Einsprachige Felder*** (Preis, Datum, Artikelnummer …) gelten für alle Sprachen
  gleich.

Auch Dateien können mehrsprachig sein – etwa ein PDF-Prospekt je Sprache oder ein
Bild mit eingebettetem Text.

Ein mehrsprachiges Feld, das als Pflichtfeld gekennzeichnet ist, muss nicht in jeder
Sprache ausgefüllt werden. Pflicht ist dann nur eine Eingabe in der Hauptsprache (i. d. R. Englisch).  
Siehe auch Abschnitt „[Fehlende Übersetzungen](#fehlende-übersetzungen-die-hauptsprache-springt-ein)“.

## Die Eingabemaske

Wie die Eingabemaske mehrsprachige Felder zeigt, ist je Inhaltsbereich eingestellt:

- ***Alle Sprachen auf einer Seite:*** Jedes mehrsprachige Feld steht einmal je
  Sprache untereinander; die Beschriftung nennt den Sprachcode, etwa „en_US“.
- ***Ein Reiter je Sprache:*** Die Maske hat einen Reiter je Sprache und einen Reiter
  „Alle Sprachen“. Auf einem Sprach-Reiter stehen die mehrsprachigen Felder in dieser
  Sprache und die einsprachigen Felder. Beim Wechsel des Reiters bleiben Ihre
  Eingaben erhalten; gespeichert wird alles zusammen mit „Speichern“.

In der Detailansicht eines Datensatzes gibt es auch bei „Ein Reiter je Sprache“ nur
den Reiter „Inhalte“. Die Sprache wählen Sie dort im Auswahlmenü ![flag](../_/flag.svg)
unter den Reitern. Die Auswahl „Alle Sprachen“ zeigt mehrsprachige Felder in allen Sprachen
direkt untereinander.

Mit welcher Sprache die Maske startet, legen Sie in den [persönlichen
Einstellungen](./03-arbeitsoberflaeche.md#persönliche-einstellungen) unter
„Bevorzugte Sprachversion“ fest. Dieselbe Einstellung bestimmt, in welcher Sprache
Datensatzliste, Suche und Detailansicht die Inhalte zeigen. Als Übersetzer wählen Sie
hier Ihre Zielsprache.

Welche Sprachen Sie sehen und bearbeiten dürfen, hängt von Ihrer Benutzergruppe ab.
Sprachen, die Sie nur lesen dürfen, erscheinen als reiner Text ohne Eingabefeld.

***Schreibrichtung:*** Texte eines mehrsprachigen Feldes stehen in der Richtung ihrer
Sprache – arabische, hebräische, persische oder Urdu-Texte von rechts nach links,
deutsche oder englische von links nach rechts, gleich in welcher Sprache Sie das
Backend benutzen. Einsprachige Felder folgen der Sprache des Backends. Technische
Angaben wie E-Mail-Adressen, Telefonnummern und Passwörter stehen immer von links nach
rechts.

## Fehlende Übersetzungen: die Hauptsprache springt ein

Fehlt in einem mehrsprachigen Feld die Übersetzung, zeigt die Website an dieser
Stelle den Inhalt der Hauptsprache. Das gilt Feld für Feld: Ein
Artikel mit deutschem Titel, aber noch englischem Text zeigt in der deutschen
Sprachversion den deutschen Titel, aber den englischen Text.

Die Website bleibt so auch während der Übersetzung vollständig. Sobald Sie eine
Übersetzung eintragen, erscheint sie. Leeren Sie das Feld wieder, springt die
Hauptsprache erneut ein.

Um tatsächlich keinen Text anzuzeigen, reicht ein Leerzeichen als Eingabe.
Allerdings werden solche Inhalte im Backend bei der Suche nach `(empty)` nicht gefunden.

## Fehlende Übersetzungen finden

Tatsächlich wird der Inhalt aus der Hauptsprache im mehrsprachigen Feld mehrfach gespeichert,
wenn er nicht in allen Sprachen ausgefüllt wird. Die Suche nach dem deutschen Titel „History“
findet also auch den Datensatz, dessen Titel „History“ auf der Website angezeigt wird, obwohl
dieser nur im Reiter „en_US“ eingetragen wurde.

Um alle Datensätze zu finden, deren mehrsprachiger Inhalt in der gesuchten Sprache
***keinen eigenen Inhalt*** hat, verwenden Sie den Platzhalter `(empty)`. Kurz gesagt:  
`(empty)` findet Datensätze ohne eigene Übersetzung.  
`!(empty)` findet umgekehrt alle Datensätze mit eigener Übersetzung.

1. Stellen Sie in den persönlichen Einstellungen in der Auswahl
  „[bevorzugte Sprachversion](./03-arbeitsoberflaeche.md#persönliche-einstellungen)“ die Sprache ein,
  die Sie prüfen wollen.
2. Öffnen Sie die Suchmaske des Bereichs und tragen Sie in ein Feld, etwa den Titel, `(empty)` ein.
3. Die Liste zeigt alle Datensätze, deren Titel noch nicht übersetzt ist.


## Übersetzen

Für größere Übersetzungen müssen Übersetzer nicht im CMS arbeiten:

- Der ***DOCX-Export*** erzeugt eine Word-Datei mit allen zu übersetzenden Texten. Der
  Übersetzer überschreibt sie in Word, der ***DOCX-Import*** spielt die Übersetzung
  zurück ins CMS. Mehr dazu finden Sie im Abschnitt [Übersetzen mit Word](./08-export-import.md#übersetzen-mit-word-docx).
- Der ***CSV-Export*** liefert mehrsprachige Felder in einer Spalte je Sprache, etwa
  `titel.de_DE` und `titel.en_US`. Das ist praktisch, um Übersetzungen in einer
  Tabellenkalkulation für viele Datensätze gleichzeitig durchzuführen
  (siehe [Kapitel 8](./08-export-import.md#inhalte-in-excel-nachbearbeiten)).
- Mit der [Stapelverarbeitung](./07-stapelverarbeitung.md) schreiben Sie einen Inhalt
  in viele Datensätze gleichzeitig – auch in mehrsprachige Felder.
