# CMS:SNIPPET

Gibt ein Snippet (Textbaustein) in der Sprache des Aufrufs aus.

```cmscode
(CMS:SNIPPET key [parameter=wert …])
```

***Methode:*** `Cms::cmsSnippet()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `key`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `key` ¹ | `Snippet-Schlüssel` | – | ja | Schlüssel des Snippets, z. B. `custom.weiterlesen` (Snippet-Set der Instanz) oder ein Core-Snippet ohne Präfix. |
| `param[…]` | `Text` | – |  | Werte für Platzhalter: `param[name]=Wert` ersetzt `{name}` im Snippet und füllt ICU-Parameter (`plural`, `select`). |
| `specialchars` | `true / false` | `false` |  | true: Sonderzeichen werden in ihr HTML-Äquivalent umgewandelt (für Attribute). |
| `ifempty` | `Text` | – |  | Text, der ausgegeben wird, wenn es das Snippet nicht gibt (ohne `ifempty` erscheint der Schlüssel). |
| `prefix` | `Text` | – |  | Text, der vorangestellt wird, sofern das Snippet nicht leer ist. |
| `suffix` | `Text` | – |  | Text, der angehängt wird, sofern das Snippet nicht leer ist. |
| `set` | `Array-Index von $set` | – |  | Der Text wird nicht ausgegeben, sondern in der Variable `$set` gespeichert. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.
`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Feste Beschriftungen einer Website – „Weiterlesen“, „In den Warenkorb“, Überschriften
von Listen – pflegt die Redaktion als ***Snippets*** der Instanz in allen Sprachen
(Modul Snippets, siehe [Handbuch für Administratoren](../admins/10-module.md#snippets)).
Das Template gibt sie mit `CMS:SNIPPET` aus, immer in der Sprache des Aufrufs; fehlt eine
Übersetzung, erscheint der englische Text (en_US):

```cms
<a class="more" href="(CMS:URL)">(CMS:SNIPPET custom.weiterlesen)</a>
```

`custom.` meint das Snippet-Set der Instanz. Ohne Präfix sind die Snippets des Cores
gemeint – im Frontend nur die allgemeinen, nicht die der Backend-Oberfläche.

In einem Attribut maskieren Sie den Text mit `specialchars=true`:

```cms
<button aria-label="(CMS:SNIPPET custom.menue specialchars=true)">…</button>
```

***Platzhalter.*** `param[name]` ersetzt `{name}` im Snippet. So bleibt die Wortstellung
Sache der Übersetzung – etwa das Währungszeichen vor oder hinter dem Betrag:

```cms
Snippet custom.preis:   en "€{betrag}"   de "{betrag} €"
(CMS:NUMBER preis mul=0.01 format="%.2f" set=betrag)
(CMS:SNIPPET custom.preis param[betrag]="{$set['betrag']}")
```

Beginnt ein Snippet mit einer ICU-Nachricht (`{anzahl, plural, one {# Artikel} other {# Artikel}}`),
füllt `param` deren Werte:

```cms
(CMS:SNIPPET custom.treffer param[anzahl]="{$set['count']}")
```

***Schlüssel aus dem Datensatz.*** Der Schlüssel darf PHP-Variablen enthalten – etwa, um
einen Auswahlwert zu übersetzen:

```cms
(CMS:SNIPPET "custom.status_{$cms['status']}" ifempty="–")
```

Gibt es das Snippet nicht, steht der Schlüssel selbst auf der Seite – so fällt ein
fehlender Text beim Entwickeln auf. `ifempty` gibt stattdessen einen Ersatz aus.

Innerhalb eines Parameters eines anderen Befehls lässt sich `CMS:SNIPPET` nicht
verschachteln; dort liefert `{$this->cms->snippet('custom.weiterlesen')}` den Text:

```cms
(CMS:TEXT teaser suffix=" {$this->cms->snippet('custom.weiterlesen')}")
```

---

[← Übersicht](README.md)
