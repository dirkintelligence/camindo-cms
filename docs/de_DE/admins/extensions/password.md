# Extension Password (Passwort-Richtlinie)

Core-Extension für Felder vom Typ „Passwort“. Unter dem Feld zeigt ein Balken die
***Stärke*** des eingegebenen Passworts (rot = schwach, orange = mittel, grün = stark);
beim Tippen erscheint eine ***Sprechblase*** mit den Anforderungen, erfüllte werden
abgehakt. Solange eine Anforderung fehlt, bleibt der Balken rot.

Immer verfügbar, keine Installation nötig. Die Passwörter der Benutzer nutzen
sie automatisch.

## Prüfung auf dem Server

Die Anzeige ist nur eine Hilfe. Entscheidend ist, dass camindo CMS beim Speichern
dieselben Regeln prüft – und das tut es für ein Feld genau dann, wenn es diese Extension
trägt. Ein zu schwaches Passwort wird mit einer Meldung abgewiesen, die die fehlenden
Anforderungen nennt („mindestens 2 Sonderzeichen, …“).

## Einstellungen je Feld

Jede Regel lässt sich am Feld abweichend einstellen. Leere Felder übernehmen die Vorgabe
der Installation (`security.password_policy`, siehe
[Betrieb](../12-betrieb.md#passwörter-und-anmeldegeräte)) – sie steht grau im Feld.

| Einstellung | Vorgabe | Bedeutung |
|---|---|---|
| Mindestlänge | 12 | Anzahl Zeichen |
| Sonderzeichen (mindestens) | 1 | alles, was kein Buchstabe und keine Ziffer ist – auch Leerzeichen, Bindestrich, Punkt |
| Großbuchstaben (mindestens) | 1 | auch Umlaute und Buchstaben anderer Schriften |
| Kleinbuchstaben (mindestens) | 1 | |
| Ziffern (mindestens) | 0 | |
| Großbuchstabe am Anfang zählt nicht | ja | „Sommer2026!“ erfüllt die Regel nicht allein durch das große S |

`0` bedeutet: keine Anforderung.

## Im Frontend

Die Registrierung und das Kundenkonto einer Website nutzen dasselbe Script. Der
Template-Programmierer kopiert es in das Template und markiert das Feld – siehe
[CMS:USER_PASSWORD_RULES](../../templateprogrammers/user-password-rules.md).
