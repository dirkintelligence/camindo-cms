# CMS:USER_PASSWORD_RULES

Gibt die Regeln der ***Passwort-Richtlinie*** eines Profil-Containers samt Texten aus – für
die Anzeige im Formular (Stärke-Balken und Sprechblase mit den Anforderungen).

```cmscode
(CMS:USER_PASSWORD_RULES container=… [parameter=wert …])
```

***Methode:*** `Modules\Record\RecordUser::cmsPasswordRules()`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `container` | `Container-ID oder -Name` | – | ja | Profil-Container, dessen Passwort-Feld gemeint ist. |
| `field` | `Feldname` | Anmelde-Passwortfeld |  | Passwort-Feld des Containers. Trägt es die Extension Passwort-Richtlinie, gelten deren Regeln, sonst die Vorgaben des Administrators (der Server prüft dann nicht). |
| `set` | `Array-Index von $set` | – |  | Die Ausgabe wird nicht ausgegeben, sondern in `$set` gespeichert. |

Die Ausgabe ist bereits mit htmlspecialchars für ein HTML-Attribut maskiert – nicht zusätzlich maskieren.

## Anwendung

Beim Registrieren und beim Ändern des Passworts prüft der Server die **Passwort-
Richtlinie** (Mindestlänge, Sonderzeichen, Groß- und Kleinbuchstaben), wenn der
Administrator dem Passwort-Feld die Extension „Passwort-Richtlinie“ zugewiesen hat.
Damit Ihre Besucher die Anforderungen schon beim Tippen sehen, gibt es dazu ein kleines
Script – dasselbe, das im Backend läuft. Es zeigt unter dem Feld einen Balken
(rot = schwach, orange = mittel, grün = stark) und eine Sprechblase, in der erfüllte
Anforderungen abgehakt werden.

**1. Script und CSS einbinden.** Kopieren Sie die beiden Dateien der Extension
unverändert in Ihr Template – als `<style>` und `<script(CMS:NONCE)>` (die Nonce braucht
das Script unter einer Script-Policy, siehe [CMS:NONCE](nonce.md)), am besten in ein
eigenes Include, das Sie am Ende der Seite einbinden:

```
Core/Extensions/Password/public/css/password.ext.css   →  <style> … </style>
Core/Extensions/Password/public/js/password.ext.js     →  <script(CMS:NONCE)> … </script>
```

Die Dateien brauchen nichts vom Backend und keine Bibliothek. Nach einem Update von
camindo CMS kopieren Sie sie neu, falls sich die Regeln geändert haben.

**2. Das Feld markieren.** Das Attribut `data-c9-password` bekommt die Regeln aus diesem
Befehl – die Texte stehen darin in der Sprache der Seite:

```cms
<input type="password" name="data[password]" autocomplete="new-password"
  data-c9-password="(CMS:USER_PASSWORD_RULES container=members)">
(CMS:INCLUDE password_assets)
```

Das Script stattet beim Laden der Seite jedes Feld mit `data-c9-password` aus. Für
Felder, die erst später per Javascript entstehen: `C9Password.attach(feld, optionen)`.

**Wichtig:** Die Anzeige ist eine Hilfe, keine Prüfung. Maßgeblich ist der Server – ohne
die Extension am Feld nimmt er jedes Passwort an, auch wenn das Formular die Regeln
zeigt. Die Fehlermeldung bei zu schwachem Passwort erscheint wie andere Feldfehler über
[CMS:MESSAGE](message.md).

Ein vollständiges Formular zum Ändern des Passworts steht bei
[CMS:USER_INPUT](user-input.md#passwort-ändern).

## Parameter im Einzelnen

### `container`

Name oder ID des Profil-Containers:

```cms
(CMS:USER_PASSWORD_RULES container=members)
```

### `field`

Ein anderes Passwort-Feld als das zur Anmeldung festgelegte:

```cms
(CMS:USER_PASSWORD_RULES container=members field=pin)
```

### `set`

Die Ausgabe nicht ausgeben, sondern in `$set['pwrules']` ablegen (bereits für ein
HTML-Attribut maskiert):

```cms
(CMS:USER_PASSWORD_RULES container=members set=pwrules)
```

---

[← Übersicht](README.md)
