# CMS:RESULT_END

Markiert das Ende einer Schleife zur Verarbeitung von CMS:SELECT-Ergebnissen.

```cms
(CMS:RESULT_END)
```

***Kein Methodenaufruf*** – die Wirkung steckt allein im PHP-Schnipsel  
***PHP-Schnipsel:*** `loop_end` (danach)

## Parameter

Dieser Befehl hat keine Parameter.

## Anwendung

Schließt die mit `CMS:RESULT_START` geöffnete Schleife:

```cms
(CMS:RESULT_START)
  <li>(CMS:TEXT title)</li>
(CMS:RESULT_END)
```

Jedes `CMS:RESULT_START` braucht genau ein `CMS:RESULT_END` im selben Template - der
Compiler erzeugt daraus `{` und `}`; ein fehlendes Ende führt zu einem PHP-Syntaxfehler
beim Aufruf der Seite.

---

[← Übersicht](README.md)
