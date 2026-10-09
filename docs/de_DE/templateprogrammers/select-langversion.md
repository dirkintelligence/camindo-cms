# CMS:SELECT_LANGVERSION

Liefert die verfügbaren Sprachen als Datensätze zurück.

```cms
(CMS:SELECT_LANGVERSION resulttemplate)
```

***Methode:*** `Cms::cmsSelectLanguages()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `resulttemplate`  
***PHP-Schnipsel:*** `push_result` (davor) · `include`, `pop_result` (danach)

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `resulttemplate` ¹ | `Templatename` | – | ja | Listentemplate, das zur Verarbeitung der Ergebnisse eingebunden wird. |
| `set` | `Array-Index von $set` | – |  | Die gesamte Ausgabe (samt eingebundenem Template) wird nicht ausgegeben, sondern in der Variable $set gespeichert. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Stellt die Sprachen des Auftritts als Ergebnismenge bereit - Hauptsprache zuerst -
und bindet das `resulttemplate` ein. Jede Zeile trägt `language_id`, `code`, `label`,
`endonym` und `current` (1 = Sprache des laufenden Aufrufs). Damit entsteht ein
Sprachumschalter:

```cms
<ul class="lang">
(CMS:SELECT_LANGVERSION lang_item)
</ul>
```

Listentemplate `lang_item`:

```cms
(CMS:RESULT_START)
<li<?php if ($cms['current']) echo ' class="active"'; ?>>
  <a href="(CMS:URL lang="{$cms['code']}")" hreflang="(CMS:TEXT code)">(CMS:TEXT endonym)</a>
</li>
(CMS:RESULT_END)
```

Die Zeilen sind keine Datensätze; `CMS:URL` ohne `id` verweist deshalb auf den
**äußeren** Datensatz - den der Seite bzw. die Zeile der umgebenden Ergebnisschleife -,
nur in der jeweils anderen Sprache. Ist das Feld `slug` mehrsprachig, setzt `CMS:URL`
den Slug der Zielsprache ein (`/de/esstisch-nord-180` → `/en/dining-table-nord-180`),
siehe [CMS:URL](url.md#lang). Bei nur einer Sprache enthält die Ergebnismenge genau
eine Zeile.

Auf einer Seite ohne Datensatz (eigenständiges Template, `/de/0,suche`) gibt es
keinen äußeren Datensatz; das Template gehört dann ausdrücklich in den Verweis:

```cms
<a href="(CMS:URL lang="{$cms['code']}" template=suche)">(CMS:TEXT endonym)</a>
```

## Parameter im Einzelnen

### `resulttemplate`

Listentemplate; darf ohne Schlüssel stehen:

```cms
(CMS:SELECT_LANGVERSION resulttemplate=lang_item)
(CMS:SELECT_LANGVERSION lang_item)
```

---

[← Übersicht](README.md)
