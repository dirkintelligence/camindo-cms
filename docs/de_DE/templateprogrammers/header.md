# CMS:HEADER

Sendet einen HTTP-Header.

```cmscode
(CMS:HEADER text [parameter=wert …])
```

***Methode:*** `Cms::cmsHeader()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `text`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `text` ¹ | `Text` | – |  | Textzeile, die als Header an den Browser gesendet werden soll. Ohne Wert (`Name:`) wird der Header entfernt. |
| `overwrite` | `true / false` | `true` |  | false: Ein vorausgegangener Header desselben Typs wird nicht überschrieben; es wird ein neuer Header hinzugefügt. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Sendet einen HTTP-Header. Muss vor der ersten Ausgabe stehen - also am Anfang des
Templates, vor jedem HTML und ohne Leerzeile davor:

```cms
(CMS:HEADER "Content-Type: application/json; charset=utf-8")
{"title": (CMS:TEXT title specialchars=true lbrconvert=false prefix='"' suffix='"')}
```

Weitere typische Fälle:

```cms
(CMS:HEADER "Content-Type: application/rss+xml; charset=utf-8")
(CMS:HEADER "Cache-Control: no-store")
(CMS:HEADER "HTTP/1.1 404 Not Found")
```

Weiterleitung – CMS-Befehle lassen sich nicht ineinander schachteln; die Adresse kommt
deshalb erst per `set` in `$set` und dann in den Header:

```cms
(CMS:URL id=startseite set=ziel)
(CMS:HEADER "Location: {$set['ziel']}")
```

Liefert ein Template CSS, Javascript oder JSON aus, gehört der passende `Content-Type`
an den Anfang – camindo CMS sendet `X-Content-Type-Options: nosniff`, der Browser führt
ein Script mit dem Standardtyp `text/html` deshalb nicht aus:

```cms
(CMS:HEADER "Content-Type: text/javascript; charset=utf-8")
```

Ist schon etwas ausgegeben, wird eine Fehlermeldung abgelegt (`CMS:MESSAGE`) und der
Header nicht gesendet.

## Parameter im Einzelnen

### `text`

Die Header-Zeile; darf ohne Schlüssel stehen:

```cms
(CMS:HEADER text="X-Robots-Tag: noindex")
(CMS:HEADER "X-Robots-Tag: noindex")
```

### `overwrite`

Standardmäßig ersetzt ein Header einen vorherigen desselben Namens; `overwrite=false` hängt einen weiteren an:

```cms
(CMS:HEADER "Link: </style.css>; rel=preload; as=style")
(CMS:HEADER "Link: </app.js>; rel=preload; as=script" overwrite=false)
```

### Voreinstellungen ersetzen oder entfernen

camindo CMS sendet vor dem Template einige Sicherheits-Header:

```
X-Frame-Options: SAMEORIGIN
Content-Security-Policy: frame-ancestors 'self'
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
```

Die Seite lässt sich damit nur innerhalb der eigenen Website in einen Rahmen legen
(Hintergrund und Fehlerbilder: [Grundlagen](01-grundlagen.md#sicherheits-header-css-javascript-json-und-einbetten)). Jeder
dieser Header lässt sich ersetzen; ohne Wert wird er entfernt. Eine Seite, die ein Partner
einbetten darf:

```cms
(CMS:HEADER "X-Frame-Options:")
(CMS:HEADER "Content-Security-Policy: frame-ancestors 'self' https://partner.example")
```

Ist für die Website eine ***Script-Policy*** eingeschaltet (Backend, Instanz), sendet
camindo stattdessen `script-src 'self' 'nonce-…'` samt `frame-ancestors 'self'`; für
einzelne Seiten passen Sie sie mit [CMS:CSP](csp.md) an.

Eine eigene Content-Security-Policy ersetzt die Voreinstellung – nehmen Sie
`frame-ancestors` dann mit auf (die Nonce der Seite liefert [CMS:NONCE](nonce.md)):

```cms
(CMS:HEADER "Content-Security-Policy: frame-ancestors 'self'; script-src 'self' https://cdn.example")
```

---

[← Übersicht](README.md)
