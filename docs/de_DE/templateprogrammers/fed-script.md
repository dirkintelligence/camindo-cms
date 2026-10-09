# CMS:FED_SCRIPT

Stellt das Script für die Bearbeiten-Verweise aus [CMS:FED](fed.md) bereit – einmal je
Seite.

```cmscode
(CMS:FED_SCRIPT [set])
```

***Methode:*** `Cms::cmsFedScript()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `set`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `set` ¹ | `Array-Index von $set` | – |  | Der Script-Block wird nicht ausgegeben, sondern in der Variable $set gespeichert. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Die Verweise aus `CMS:FED` enthalten kein Ziel und keinen Javascript-Code. Den Klick
wertet erst das Script aus `CMS:FED_SCRIPT` aus und öffnet die Eingabemaske (bzw. mit
`info=true` die Detailansicht) im Backend in einem neuen Tab – bei fester
Backend-Domain unter deren Adresse. Ohne `CMS:FED_SCRIPT` auf der Seite bleibt ein
Klick auf den Verweis wirkungslos.

Schreiben Sie den Befehl einmal in ein Template, das jede Seite einbindet, üblicherweise
in den Footer vor `</body>`:

```cms
(CMS:FED_SCRIPT)
</body>
</html>
```

Die Reihenfolge zu den `CMS:FED`-Verweisen spielt keine Rolle. Wie `CMS:FED` gibt der
Befehl nur etwas aus, wenn der Betrachter das ***Frontend-Editing*** eingeschaltet hat;
für Besucher bleibt die Seite unverändert. Steht der Befehl mehrfach auf einer Seite
(etwa über mehrere `CMS:INCLUDE`), erscheint das Script trotzdem nur einmal.

Das `<script>`-Tag trägt die ***Nonce*** der Seite und läuft deshalb auch unter einer
durchgesetzten Script-Policy (siehe [CMS:CSP](csp.md)).

## Parameter im Einzelnen

### `set`

`set` gibt nichts aus, sondern legt den Script-Block unter `$set['fed']` ab, etwa um
ihn an einer anderen Stelle auszugeben:

```cms
(CMS:FED_SCRIPT set=fed)
<?php echo $set['fed']; ?>
```

---

[← Übersicht](README.md)
