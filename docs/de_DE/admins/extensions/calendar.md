# Extension Calendar (Kalender)

Core-Extension für Datums- und Zeitfelder (Feldtypen „Datum + Uhrzeit“,
„Datum + Uhrzeit, zeitzonenabhängig“ und „Uhrzeit“, Darstellung einzeilig). Neben das
Textfeld tritt die ***Datums- bzw. Zeitauswahl des Browsers***; Aussehen und Sprache
bestimmt daher der Browser, nicht camindo CMS.

Immer verfügbar, keine Installation nötig. Ein ***neues*** Datums- oder Zeitfeld bekommt
sie automatisch („nach Feldtyp“, Minutenschritt 1) – auch wenn der KI-Assistent das Feld
anlegt. Wer keine Extension will, wählt beim Anlegen „keine“.

## Einstellungen je Feld

| Einstellung | Vorgabe | Bedeutung |
|---|---|---|
| Auswahl | nach Feldtyp | „nach Feldtyp“: Uhrzeit-Felder bekommen eine Zeitauswahl, alle anderen Datum und Uhrzeit. Fest wählbar: „Datum und Uhrzeit“, „nur Datum“, „nur Uhrzeit“ |
| Minutenschritt | 1 | Raster der Zeitauswahl in Minuten (1–60), z. B. 15 für Viertelstunden |

## Verhalten

- Eine Auswahl schreibt den Wert in der internationalen Schreibweise ins Textfeld:
  `2030-09-26 14:30`, bei „nur Datum“ `2030-09-26`, bei „nur Uhrzeit“ `14:30`. Das
  Backend versteht diese Form in jedem Gebietsschema und zeigt den Wert nach dem
  Speichern im Format des Benutzers an.
- Ein Wert in internationaler Schreibweise im Textfeld erscheint auch in der Auswahl.
  Werte im Landesformat (`26.09.2030`) übernimmt die Auswahl nicht – das Textfeld
  selbst nimmt sie aber an.
- Das Textfeld bleibt immer frei beschreibbar.

Soll der Kalender in der Sprache und im Datumsformat des Benutzers erscheinen, ist
[Datepicker](./datepicker.md) die Alternative.
