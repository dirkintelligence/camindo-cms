# CMS:XML

Erzeugt eine XML-Deklaration.

```cmscode
(CMS:XML encoding [parameter=wert …])
```

***Methode:*** `Cms::cmsXml()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `encoding`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `encoding` ¹ | `encoding` | `utf-8` |  | Zeichenkodierung, die verwendet werden soll. |
| `version` | `version` | `1.0` |  | XML-Version. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

`encoding` und `version` werden mit htmlspecialchars maskiert ausgegeben.

## Anwendung

Erzeugt die XML-Deklaration `<?xml version="1.0" encoding="utf-8"?>`. Sie muss das
***erste Zeichen*** der Ausgabe sein - der Befehl steht deshalb in der ersten Zeile eines
Feed-Templates, ohne Leerzeile davor:

```cms
(CMS:XML)
<rss version="2.0">
  <channel>
    <title>(CMS:TEXT title specialchars=true lbrconvert=false)</title>
    (CMS:SELECT filter[area]=artikel limit=20 resulttemplate=rss_item)
  </channel>
</rss>
```

Dazu passend `(CMS:HEADER "Content-Type: application/rss+xml; charset=utf-8")` – er
gibt selbst nichts aus und steht deshalb ***vor*** `(CMS:XML)` in derselben Zeile bzw.
ohne Leerzeile dazwischen:

```cms
(CMS:HEADER "Content-Type: application/rss+xml; charset=utf-8")(CMS:XML)
<rss version="2.0">
```
Die Deklaration in ein Template zu tippen ginge nicht: `<?xml` würde PHP als
öffnendes Tag lesen.

## Parameter im Einzelnen

### `encoding`

```cms
(CMS:XML encoding=ISO-8859-1)
```
Ausgabe: `<?xml version="1.0" encoding="ISO-8859-1"?>` - die Inhalte dann mit `charset=ISO-8859-1` ausgeben.

### `version`

```cms
(CMS:XML version=1.1)
```

---

[← Übersicht](README.md)
