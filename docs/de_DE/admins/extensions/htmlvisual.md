# Extension HTML (visuell)

Core-Extension – HTML-Editor für mehrzeilige Textfelder (Feldtypen „Kurzer Text“ und
„Langer Text“). Sie ist eine Kopie von [Markdown (visuell)](./markdownvisual.md) und
bedient sich genauso: Redakteure bearbeiten die ***formatierte Ansicht*** wie in einem
Textprogramm, der ***HTML-Quelltext*** erscheint erst über die Schaltfläche **<>** und
lässt sich dort auch bearbeiten. Das Feld enthält ***HTML*** statt Markdown.

Immer verfügbar, keine Installation nötig.

## Was der Editor kann

Dasselbe wie Markdown (visuell): Fett, kursiv, unterstrichen, Code im Text,
Absatzformat (Absatz, Überschrift 1–6), Aufzählung und nummerierte Liste mit
Unterpunkten, Zitat, Codeblock, Link, horizontale Linie, Zeilenumbruch, Sonderzeichen,
Ausrichtung links, zentriert, rechts, ***Schreibrichtung*** für markierte Abschnitte und
Tabellen mit Kopfzeile. Bilder unterstützt er nicht.

Beim ***Einfügen*** übernimmt der Editor nur, was er darstellen kann: Formatierter Text
aus Word, Google Docs oder Webseiten wird auf die unterstützten Formate gestutzt,
eingefügter Klartext, der wie Markdown aussieht, übersetzt. Bilder, Farben, Schriften,
Klassen und Durchstreichen entfallen, der Text bleibt.

Dasselbe gilt für HTML, das im Quelltext (**<>**) geschrieben oder geändert wird: Beim
Zurückschalten in die formatierte Ansicht und beim Speichern bleibt nur, was der Editor
abbildet. Vorhandenes HTML im Feld (etwa aus TinyMCE) bleibt unverändert, bis der Text
im Editor geändert wird – dann wird es ebenso gestutzt.

Wie Redakteure damit arbeiten, beschreibt das [Handbuch für Anwender](../../editors/05-datensaetze-bearbeiten.md#text)
(Datensätze bearbeiten → Eingabefelder → Text).

## Einstellungen je Feld

| Einstellung | Bedeutung |
|---|---|
| Werkzeugleiste | *alle Schaltflächen*, *einfach* (Fett, Kursiv, Absatzformat, Listen, Link), *eigene Auswahl* |
| Eigene Schaltflächen | nur bei *eigene Auswahl*: Namen durch Komma oder Leerzeichen getrennt, `\|` als Trennstrich – `bold italic underline code heading ul ol outdent indent quote codeblock link hr br special align_left align_center align_right dir table undo redo` |
| Mindesthöhe / Maximale Höhe | in Pixeln; ohne Maximum wächst der Editor mit dem Inhalt |
| Stylesheet | eine `.css`-Datei aus `var/extensions/htmlvisual/` |
| CSS-Klasse | Klasse am Editor-Container; leer = `c9-htmlvisual-{feldname}` |

Die Schaltfläche **<>** (Quelltext) ist immer da. Tastenkürzel (etwa Strg+B) und das
Einfügen wirken unabhängig davon, welche Schaltflächen Sie auswählen.

## HTML im Feld

Der Editor schreibt nur diese Elemente – ohne Klassen und ohne Stile außer der
Ausrichtung:

| HTML | Bedeutung |
|---|---|
| `<p>`, `<h1>` … `<h6>` | Absatz, Überschrift |
| `<strong>`, `<em>`, `<u>`, `<code>` | fett, kursiv, unterstrichen, Code im Text |
| `<a href="…">` | Link (nur `http(s):`, `mailto:`, `tel:`, `ftp:` und relative Adressen) |
| `<br>` | Zeilenumbruch |
| `<ul>`, `<ol start="…">`, `<li>` | Listen, verschachtelt für Unterpunkte |
| `<blockquote>` | Zitat |
| `<pre><code class="language-…">` | Codeblock |
| `<hr>` | horizontale Linie |
| `<table>` mit `<thead>`/`<tbody>`, `<th>`, `<td>` | Tabelle, erste Zeile Kopfzeile |
| `style="text-align: left\|center\|right;"` | Ausrichtung an Absatz, Überschrift, Liste, Zitat, Tabelle; an Zellen als Spaltenausrichtung |
| `<bdi dir="rtl">` / `<bdi dir="ltr">` | Abschnitt rechts nach links bzw. links nach rechts |

Unsichtbare Sonderzeichen stehen als Entities im HTML: `&nbsp;`, `&#8239;` (schmales
geschütztes Leerzeichen), `&ensp;`, `&thinsp;`, `&shy;`, `&#8209;` (geschützter
Bindestrich). Das HTML ist zeilenweise eingerückt, damit der Quelltext lesbar bleibt.

***Schreibrichtung:*** wie bei Markdown (visuell) – das Feld schreibt in der Richtung
seiner Sprache, einsprachige Felder in der Richtung des Backends. Ein markierter
Abschnitt in der Gegenrichtung wird zu `<bdi dir="…">`; im Editor ist er gestrichelt
umrandet.

Im Template geben Sie das Feld ohne Umwandlung aus:

```cms
<div class="text">(CMS:TEXT feldname entitydecode=false lbrconvert=false)</div>
```

`entitydecode=false` lässt maskierten Text (`&lt;`) maskiert, `lbrconvert=false`
verhindert zusätzliche `<br>` an den Zeilenumbrüchen des Quelltextes.

## Stylesheet

Der Editor steht direkt in der Backend-Seite. Schränken Sie Regeln deshalb immer mit
der CSS-Klasse des Feldes ein – sonst treffen sie das ganze Backend. Die
Bearbeitungsfläche ist `.c9-htv-content`:

```css
.c9-htmlvisual-text .c9-htv-content h1 { color: #036; }
.c9-htmlvisual-text .c9-htv-content { font-family: Georgia, serif; }
```
