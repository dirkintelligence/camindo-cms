# 11 Extensions

Eine ***Extension*** erweitert ein ***Eingabefeld*** im Backend um zusätzliches
Verhalten – einen Texteditor, einen Kalender, eine Symbolauswahl. Sie wird in
der Felddefinition zugewiesen (Modul „Struktur“ → Bereich → Eingabefeld bearbeiten → Abschnitt
„Erweiterung“). Die Einstellungen der Extension stehen darunter im Abschnitt
„Einstellungen“. Ein Feld hat höchstens eine Extension, und angeboten werden nur
die, die zum Feld- und Darstellungstyp passen.

Jede Core-Extension hat eine eigene Seite (Links in der Tabelle). Sie folgen als
Unterseiten auf dieses Kapitel – „Weiter“ führt erst durch sie und dann zu Kapitel 12.

## Core-Extensions

Sie sind mit dem Core installiert und immer aktiv.

| Extension | Feldtypen / Darstellung | Was sie tut |
|---|---|---|
| [Calendar](./extensions/calendar.md) („Kalender“) | Datum/Uhrzeit, Uhrzeit / einzeilig | die Datums- und Zeitauswahl des Browsers neben dem Feld; bei neuen Datumsfeldern (auch vom KI-Assistenten angelegten) vorausgewählt – „nach Feldtyp“, Minutenschritt 1 |
| [Datepicker](./extensions/datepicker.md) | Datum/Uhrzeit / einzeilig | Kalender in der Sprache und im Datumsformat des Benutzers |
| [IconPicker](./extensions/iconpicker.md) („Icon-Auswahl“) | alle / einzeilig | Auswahl eines Symbols mit Vorschau, blätterbaren Kategorien und Suche über Name und Schlagwörter; Standard für das Icon eines Bereichs |
| [Markdown (visuell)](./extensions/markdownvisual.md) („MarkdownVisual“) | Kurzer/Langer Text / mehrzeilig | Markdown-Editor, der in der formatierten Ansicht bearbeitet; Quelltext über die Schaltfläche <> |
| [HTML (visuell)](./extensions/htmlvisual.md) („HTMLVisual“) | Kurzer/Langer Text / mehrzeilig | HTML-Editor, der in der formatierten Ansicht bearbeitet; Quelltext über die Schaltfläche <>. Wie Markdown (visuell), speichert aber HTML |
| [Password](./extensions/password.md) („Passwort-Richtlinie“) | Passwort / Passwort | Anforderungen als Sprechblase, Stärke als Balken; der Server prüft dieselben Regeln. Standard für die Passwörter der Benutzer |
| [Snippets](./extensions/snippets.md) („Snippet-Auswahl“) | alle / einzeilig | Auswahl eines Textbausteins (Snippet) über ein Suchfenster; schreibt einen Verweis `#präfix.schlüssel` ins Feld, der in der jeweiligen Backend-Sprache erscheint. Wird auch im Backend für „Label“-Felder verwendet. |

## Zusatz-Extensions

Zusatz-Extensions sind eigene Pakete. Sie werden per Composer geholt und dann als
Komponente installiert (siehe [Zusatzkomponenten installieren](./01-quickstart-installation.md#zusatzkomponenten-installieren)):

```bash
composer require camindo/extension-{name}
php Core/cron/camindo.php component install {Name} --activate
```

Jede Zusatzkomponente bringt ihr eigenes Handbuch mit. Nach der Installation finden Sie
es in der Übersicht der Handbücher unter „Handbücher der Zusatzkomponenten“.

## Eigene Stylesheets für Editoren

Editor-Extensions bieten in ihren Einstellungen ein ***Stylesheet*** zur Auswahl an, mit
dem Sie die Darstellung im Editor an die Website angleichen. Die Dateien legen Sie
selbst unter `var/extensions/{name}/` ab (Name kleingeschrieben, also
`var/extensions/markdownvisual/` bzw. `var/extensions/htmlvisual/`) – alle
`.css`-Dateien dort erscheinen in der Auswahl.

Damit ein Stylesheet mehrere Felder unterschiedlich gestalten kann, trägt jeder
Editor eine CSS-Klasse, die Sie je Feld festlegen können (Vorgabe:
`c9-{name}-{feldname}`, z. B. `c9-htmlvisual-teaser`). Beispiele stehen in der
Beschreibung der jeweiligen Extension.
