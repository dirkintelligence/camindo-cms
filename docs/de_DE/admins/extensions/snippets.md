# Extension Snippets (Snippet-Auswahl)

Core-Extension für einzeilige Felder aller Feldtypen. Sie setzt statt eines festen
Textes einen ***Verweis auf ein Snippet*** ins Feld – `#präfix.schlüssel` –, der überall
im Backend in der Sprache des jeweiligen Benutzers erscheint. Standard für die
Beschriftungsfelder („Label“) der Module, etwa die Bezeichnung eines Bereichs oder
Feldes. Immer verfügbar, keine Installation nötig, keine Einstellungen.

## Bedienung

- Beginnt der Wert mit `#`, zeigt rechts neben dem Feld eine ***Vorschau*** den
  aufgelösten Text in der eigenen Backend-Sprache – oder „Snippet nicht gefunden“.
- Die Schaltfläche ![translate](../../_/translate.svg) öffnet das Suchfenster. Steht schon ein
  Verweis im Feld, ist er als Suchbegriff vorbelegt.
- Gesucht wird in ***Id und Text*** aller Snippets, ohne Groß-/Kleinschreibung; mehrere
  Begriffe müssen alle vorkommen. Gezeigt werden höchstens 50 Treffer – bei mehr
  grenzen Sie die Suche ein.
- Ein Klick auf einen Treffer schreibt `#` + Id ins Feld. ![block](../../_/block.svg)
  „Nur Text (kein Snippet)“ entfernt das `#`; der Rest bleibt als normaler Text stehen.
- ![close](../../_/close.svg), die Esc-Taste oder ein Klick außerhalb schließen das
  Fenster.

Markierungen an den Treffern: ***nur Backend*** – das Snippet gibt es nur in den
Backend-Texten; ***ICU*** – ein Text mit Platzhaltern (Mehrzahl, Zahlen), in der Vorschau
mit leeren Werten.

## Welche Snippets erscheinen

Alle Snippets in der Backend-Sprache des Benutzers: die des Core (Id ohne Präfix), der
Module, Extensions und Prozessoren sowie die eigenen Snippets einer Website aus dem
Modul „Snippets“ (`custom.{instance_id}.…`, siehe [Kapitel 10](../10-module.md#modul-snippets)) –
diese nur für Websites, auf die die Benutzergruppe Zugriff hat.

Für eigene Beschriftungen legen Sie zuerst das Snippet im Modul „Snippets“ an (fehlende
Übersetzungen erhalten den englischen Text) und wählen es dann hier aus. Ein Verweis auf
ein Snippet, das es nicht gibt, erscheint im Backend als Id.
