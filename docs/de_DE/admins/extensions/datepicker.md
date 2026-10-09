# Extension Datepicker

Core-Extension für Datumsfelder (Feldtypen „Datum + Uhrzeit“ und „Datum + Uhrzeit,
zeitzonenabhängig“, Darstellung einzeilig – nicht für „Uhrzeit“). Neben das Textfeld
treten ein ***Kalender*** in der Sprache und im Datumsformat des Benutzers und – je nach
Einstellung – ein ***Zeitfeld***. Grundlage ist die Bibliothek vanillajs-datepicker.

Immer verfügbar, keine Installation nötig. Anders als [Calendar](./calendar.md) wird
sie nicht automatisch vorausgewählt.

## Einstellungen je Feld

| Einstellung | Vorgabe | Bedeutung |
|---|---|---|
| Auswahl | nach Feldtyp | „nach Feldtyp“ und „Datum und Uhrzeit“: Kalender und Zeitfeld; „nur Datum“: nur der Kalender |
| Schaltflächen „Heute“ und „Löschen“ | an | „Heute“ wählt das heutige Datum, „Löschen“ leert das Feld |
| Kalenderwochen anzeigen | aus | Spalte mit den Kalenderwochen links im Kalender |

## Bedienung

- Ein Klick in das Kalenderfeld öffnet den Kalender; ein Klick auf einen Tag übernimmt
  ihn und schließt den Kalender.
- ![chevron_left](../../_/chevron_left.svg) und ![chevron_right](../../_/chevron_right.svg)
  blättern einen Monat zurück bzw. vor; ein Klick auf Monat und Jahr in der Kopfzeile
  wechselt zur Monats-, Jahres- und Jahrzehntübersicht.
- Das Zeitfeld nimmt Stunden und Minuten.
- Monatsnamen, Wochentage und Wochenbeginn folgen dem Gebietsschema des Benutzers
  (persönliche Einstellungen, „Datums- und Zahlenformat“); fehlt eine Übersetzung,
  erscheint der Kalender auf Englisch.

## Was ins Feld geschrieben wird

Der Wert steht im Textfeld so, wie das Backend Datumswerte anzeigt – im mittleren
Datumsformat des Benutzers, z. B. `26.09.2030, 14:30` (de_DE) oder
`Sep 26, 2030, 2:30 PM` (en_US). Die Uhrzeit wird nur angehängt, wenn sie nicht 00:00
ist. Umgekehrt übernimmt der Kalender beim Öffnen einen Wert im Anzeigeformat oder in
internationaler Schreibweise (`2030-09-26 14:30`) aus dem Textfeld. Das Textfeld bleibt
frei beschreibbar; Änderungen dort erscheinen sofort in Kalender und Zeitfeld.
