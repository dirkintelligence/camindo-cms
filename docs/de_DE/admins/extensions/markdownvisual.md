# Extension Markdown (visuell)

Core-Extension – Markdown-Editor für mehrzeilige Textfelder (Feldtypen „Kurzer Text“
und „Langer Text“): Redakteure bearbeiten die ***formatierte Ansicht*** wie in
einem Textprogramm, der ***Markdown-Quelltext*** erscheint erst über die Schaltfläche
**<>** und lässt sich dort auch bearbeiten. Das Feld enthält weiterhin Markdown.

Immer verfügbar, keine Installation nötig.

## Was der Editor kann

Fett, kursiv, unterstrichen, Code im Text, Absatzformat (Absatz, Überschrift 1–6),
Aufzählung und nummerierte Liste mit Unterpunkten, Zitat, Codeblock, Link, horizontale
Linie, Zeilenumbruch, Sonderzeichen (Währungen, ®, ™, Striche, geschützte und schmale
Leerzeichen, weiches Trennzeichen …), Ausrichtung links, zentriert, rechts,
***Schreibrichtung*** für markierte Abschnitte und Tabellen mit Kopfzeile. Bilder unterstützt er nicht.

Beim ***Einfügen*** übernimmt der Editor nur, was er darstellen kann: Formatierter Text
aus Word, Google Docs oder Webseiten wird in Markdown übersetzt, eingefügtes Markdown
auf die unterstützten Formate reduziert. Bilder, Farben, Schriften und Durchstreichen
entfallen, der Text bleibt.

Wie Redakteure damit arbeiten, beschreibt das [Handbuch für Anwender](../../editors/05-datensaetze-bearbeiten.md#text)
(Datensätze bearbeiten → Eingabefelder → Text).

## Einstellungen je Feld

| Einstellung | Bedeutung |
|---|---|
| Werkzeugleiste | *alle Schaltflächen*, *einfach* (Fett, Kursiv, Absatzformat, Listen, Link), *eigene Auswahl* |
| Eigene Schaltflächen | nur bei *eigene Auswahl*: Namen durch Komma oder Leerzeichen getrennt, `\|` als Trennstrich – `bold italic underline code heading ul ol outdent indent quote codeblock link hr br special align_left align_center align_right dir table undo redo` |
| Mindesthöhe / Maximale Höhe | in Pixeln; ohne Maximum wächst der Editor mit dem Inhalt |
| Stylesheet | eine `.css`-Datei aus `var/extensions/markdownvisual/` |
| CSS-Klasse | Klasse am Editor-Container; leer = `c9-markdownvisual-{feldname}` |

Die Schaltfläche **<>** (Quelltext) ist immer da. Tastenkürzel (etwa Strg+B) und das
Einfügen wirken unabhängig davon, welche Schaltflächen Sie auswählen.

## Markdown im Feld

Der Editor schreibt übliches Markdown und vier Erweiterungen:

| Markdown | Bedeutung |
|---|---|
| `++Text++` | unterstrichen |
| `::: center` … `:::` | Ausrichtung der eingeschlossenen Absätze (`left`, `center`, `right`) |
| `<br>` | Zeilenumbruch in einer Tabellenzelle |
| `U+2067` … `U+2069` bzw. `U+2066` … `U+2069` | Abschnitt rechts nach links bzw. links nach rechts (Unicode-Isolate, unsichtbare Zeichen; auch als `&#x2067;` … `&#x2069;` geschrieben) |

Unsichtbare Sonderzeichen (geschütztes Leerzeichen, weiches Trennzeichen …) stehen als
Zeichen im Text.

***Schreibrichtung:*** Das Feld schreibt in der Richtung seiner Sprache (Arabisch,
Hebräisch, Persisch, Urdu … von rechts nach links), einsprachige Felder in der Richtung
des Backends. Die Schaltfläche `dir` zeigt die Richtung an der Einfügemarke und stellt
einen markierten Abschnitt auf die Gegenrichtung um – im Markdown umschlossen von den
Unicode-Zeichen RLI (`U+2067`, rechts nach links) bzw. LRI (`U+2066`, links nach
rechts) und PDI (`U+2069`, Ende). Browser werten diese Zeichen selbst aus; die Ausgabe
im Template braucht nichts Besonderes. Im Editor ist ein solcher Abschnitt gestrichelt
umrandet. Ein harter Zeilenumbruch sind zwei Leerzeichen am Zeilenende.

Im Template geben Sie das Feld mit `(CMS:TEXT feldname markdown=true)` aus – der
Parameter kennt die Erweiterungen (siehe Handbuch für Template-Programmierer, CMS:TEXT).
Andere Markdown-Umwandler zeigen `++` und `:::` als Text.

## Stylesheet

Der Editor steht direkt in der Backend-Seite. Schränken Sie Regeln deshalb immer mit
der CSS-Klasse des Feldes ein – sonst treffen sie das ganze Backend. Die
Bearbeitungsfläche ist `.c9-mdv-content`:

```css
.c9-markdownvisual-text .c9-mdv-content h1 { color: #036; }
.c9-markdownvisual-text .c9-mdv-content { font-family: Georgia, serif; }
```
