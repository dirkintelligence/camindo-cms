# CMS:EMAIL

Versendet eine E-Mail. Prüft auf Wunsch vorab die Pflichtfelder eines Formulars.

```cmscode
(CMS:EMAIL [parameter=wert …])
```

***Methode:*** `Email::cmsEmail()`  
***Mindestens einer von:*** `subject`, `subjecttemplate`, `body`, `bodytemplate`  
***PHP-Schnipsel:*** `include_and_stop` (danach)

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `to[…]` | `E-Mail-Adresse` | – | ja | Empfänger-E-Mail-Adresse. Mehrere Empfänger als `to[]=…` oder kommasepariert (höchstens 20). |
| `toname` | `Text` | – |  | Empfängername (nur bei genau einem Empfänger). |
| `from` | `E-Mail-Adresse` | `email.noreply` bzw. `email.reply` |  | Absender-E-Mail-Adresse. |
| `fromname` | `Text` | – |  | Absendername. |
| `contenttype` | `text/plain oder text/html` | `text/plain` |  | MIME-Type der E-Mail. |
| `subject` | `Text` | `(no subject)` | ² | Betreff der E-Mail. |
| `subjecttemplate` | `Template-Name` | – | ² | Template für den Betreff (Tags und Zeilenumbrüche fallen weg). Hat Vorrang vor `subject`. |
| `body` | `Text` | – | ² | Nachrichtentext (Text oder HTML). |
| `bodytemplate` | `Template-Name` | – | ² | Template für den E-Mail-Body. Hat Vorrang vor `body`. |
| `paths_absolute` | `true / false` | `false` |  | true: Alle serverrelativen Pfade im E-Mail-Body werden in absolute Pfade umgewandelt (nur bei `contenttype="text/html"`). |
| `sender_url` | `(http://)Domain` | Protokoll und Hauptdomain der Instanz |  | (Optional mit Protokoll) + Domain, um serverrelative Pfade in absolute Pfade wandeln zu können. |
| `debug` | `true / false` | `false` |  | true: E-Mail wird in das Logfile `var/log/{Datum}_mail-debug.log` geschrieben, aber nicht versendet. |
| `requiredfields` | `Feldnamen, kommasepariert` | – |  | Angabe der Pflichtfelder. Feldnamen (Index-Namen) im Request-Array `data[…]` (siehe Parameter `data`). |
| `data` | `PHP-Variablenname` | `data` |  | Name des Request-Arrays, das die von `requiredfields` erwarteten Daten enthält (Standard `data`, also `$_POST['data']`). |
| `submit` | `Textwert` | – | ja | Der Befehl läuft nur, wenn `submit` einen Wert enthält. Ansonsten wird der CMS:EMAIL-Befehl ignoriert. |
| `successtemplate` | `Template-Name` | – |  | Template, das nach erfolgreichem Absenden eingebunden wird; danach endet die Seite (außer mit `CMS:CONTINUE`). |
| `message[…]` | `Text` | – |  | Fehlermeldung. Im Index: Anwendungsfall (siehe [Meldungen](#meldungen)); im Wert: Meldungstext. |
| `attachment[…]` | `Pfad` | – |  | Dateien, die angehängt werden sollen. Pfad relativ zum `var`-Verzeichnis, nur aus `download/`, `upload/` und `modules/`. |
| `throttle` | `true / Bucket-Name` | – |  | Drosselung je Herkunft (IP): true = höchstens fünf E-Mails je Stunde (`user.throttle.email_ip`), sonst eine eigene Grenze aus `user.throttle`. Gedrosselt: Meldung `throttled`, keine E-Mail. |
| `formguard` | `true / false / Sekunden` | `false` |  | true: Formularschutz gegen Robots – das Formular muss die Felder aus `(CMS:USER_TOKEN formguard=true)` enthalten und frühestens nach 3 Sekunden (`user.formguard_min_seconds`) abgeschickt werden. Eine Zahl setzt die Mindestzeit für dieses Formular (`formguard=5`), `0` schaltet ab. Sonst Meldung `throttled`. |

² mindestens einer der mit ² markierten Parameter muss angegeben werden.

`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Kontaktformular mit Pflichtfeldprüfung und Bestätigungsseite:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:EMAIL
  submit="$submit"
  to="info@example.com"
  fromname="Kontaktformular"
  subject="Kontaktanfrage"
  bodytemplate=contact_mail
  requiredfields="name,email,message"
  successtemplate=contact_thanks
)
(CMS:MESSAGE prefix="<div class=alert>" suffix="</div>")
<form method="post">
  <input name="data[name]"> <input name="data[email]" type="email">
  <textarea name="data[message]"></textarea>
  <button name="submit" value="1">Senden</button>
</form>
```

Der Ablauf:

1. Beim ersten Aufruf ist `submit` leer – der Befehl tut nichts, das Formular erscheint.
2. Nach dem Absenden prüft er die Pflichtfelder. Fehlt eines, wird nicht gesendet;
   `CMS:MESSAGE` zeigt die Meldung, und das Template läuft weiter – das Formular
   erscheint erneut.
3. Sonst baut er die E-Mail, versendet sie und bindet `successtemplate` ein. Die
   Seite endet danach. Ohne `successtemplate` läuft das Template einfach weiter.

Versendet wird über das in der Konfiguration eingetragene SMTP-Postfach
(`email.smtp`), ersatzweise über die PHP-Funktion `mail()` – wie alle E-Mails des CMS.
Scheitert der Versand, steht der Grund in `var/log/{Datum}_mail.log` (Modul
Logdateien, Art „Nicht versendete E-Mails“).

### Was der Befehl hinterlässt

| Variable | Inhalt |
|---|---|
| `$set['email_sent']` | `true` nach erfolgreichem Versand (bzw. mit `debug=true` nach dem Protokollieren), sonst `false` |
| `$set['email_missing']` | Namen der leeren Pflichtfelder, z. B. um sie im Formular hervorzuheben |

```
<input name="data[email]" class="<?php echo in_array('email', $set['email_missing'] ?? []) ? 'error' : ''; ?>">
```

### Meldungen

Fehler landen bei [CMS:MESSAGE](message.md). Den Text bestimmt `message[index]`,
sonst gilt der Standardtext:

| Index | Fall | Standardtext |
|---|---|---|
| `required` | ein Pflichtfeld ist leer | Bitte füllen Sie alle Pflichtfelder aus. |
| `to` | kein oder ein ungültiger Empfänger | Die E-Mail hat keinen gültigen Empfänger. |
| `from` | ungültiger Absender (auch: keiner konfiguriert) | Die E-Mail hat keinen gültigen Absender. |
| `content` | `subjecttemplate`/`bodytemplate` fehlt oder bricht ab | Der Inhalt der E-Mail konnte nicht erzeugt werden. |
| `attachment` | ein Anhang fehlt oder liegt außerhalb der erlaubten Verzeichnisse | Ein Anhang der E-Mail wurde nicht gefunden. |
| `send` | der Versand ist gescheitert | Die E-Mail konnte nicht versendet werden. |
| `throttled` | Drosselung (`throttle`) oder Formularschutz (`formguard`) | Zu viele Anfragen. Bitte versuchen Sie es später erneut. |

Die Standardtexte erscheinen in der Sprache der Seite.

### Sicherheit

- ***Empfänger nie aus dem Formular übernehmen.*** Steht in `to` eine Eingabe des
  Besuchers, kann jeder über Ihr Formular E-Mails an beliebige Adressen schicken.
  Ist eine der Adressen in `to` ungültig, sendet der Befehl gar nicht.
- ***Kontaktformulare drosseln:*** `throttle=true` begrenzt die E-Mails je Besucher,
  `formguard=true` (mit `(CMS:USER_TOKEN formguard=true)` im Formular) hält Robots auf:
  `(CMS:EMAIL submit="$submit" to="info@example.com" … throttle=true formguard=true)`.
  Bestellbestätigungen und andere E-Mails, deren Empfänger Sie selbst bestimmen,
  brauchen das nicht.
- ***Absender:*** Absender ist immer der Websitebetreiber, nie der Besucher: Viele
  Mailserver nehmen nur E-Mails an, deren Absender zur eigenen Domain passt. Setzen Sie die Adresse des Besuchers deshalb besser in den Text als
  in `from`; ohne `from` gilt die Absenderadresse aus der Konfiguration.
- Zeilenumbrüche in Betreff, Namen und Adressen werden entfernt – eingeschleuste
  Kopfzeilen (etwa ein zusätzliches `Bcc:`) sind damit ausgeschlossen.
- ***HTML-E-Mails:*** Eingaben des Besuchers im Body-Template mit
  `(CMS:TEXT useval="{$_POST['data']['…']}" specialchars=true)` ausgeben.
- ***Anhänge*** nur aus `var/download/`, `var/upload/` und `var/modules/` – Konfiguration,
  Protokolle und Sicherungen lassen sich nicht anhängen.

## Parameter im Einzelnen

### `to`

```cms
(CMS:EMAIL to="info@example.com" …)
(CMS:EMAIL to[]="info@example.com" to[]="vertrieb@example.com" …)
```

### `toname`

Gilt nur, wenn genau ein Empfänger angegeben ist:

```cms
(CMS:EMAIL to="info@example.com" toname="Beispiel GmbH" …)
```

### `from`

```cms
(CMS:EMAIL from="shop@example.com" …)
```

Ohne Angabe die Absenderadresse aus der Konfiguration (`email.noreply`, ersatzweise
`email.reply`). Ist keine davon eingetragen, kann ohne `from` nicht gesendet werden.

### `fromname`

```cms
(CMS:EMAIL fromname="Kontaktformular Beispiel GmbH" …)
```

### `contenttype`

```cms
(CMS:EMAIL contenttype="text/html" bodytemplate=newsletter_html …)
```

Bei `text/html` erzeugt das CMS zusätzlich eine Textfassung für E-Mail-Programme ohne
HTML-Darstellung.

### `subject`

```cms
(CMS:EMAIL subject="Kontaktanfrage" …)
```

### `subjecttemplate`

Betreff aus einem Template erzeugen (z. B. mit Feldern des laufenden Datensatzes).
Tags, Entities und Zeilenumbrüche fallen weg:

```cms
(CMS:EMAIL subjecttemplate=order_subject …)
```

### `body`

```cms
(CMS:EMAIL body="Neue Anfrage von {$_POST['data']['name']}" …)
```

### `bodytemplate`

Nachrichtentext aus einem Template – dort stehen alle CMS-Befehle zur Verfügung, der
laufende Datensatz, `$set` und die Formulardaten in `$_POST`. Variablen, die Sie im
aufrufenden Template mit PHP gesetzt haben, sieht das Body-Template nicht; legen Sie
sie bei Bedarf in `$set` ab.

```cms
(CMS:EMAIL contenttype="text/html" bodytemplate=contact_mail …)
```

`contact_mail`:

```cms
<p>Neue Anfrage von (CMS:TEXT useval="{$_POST['data']['name']}" specialchars=true)
(CMS:TEXT useval="{$_POST['data']['email']}" specialchars=true):</p>
<p>(CMS:TEXT useval="{$_POST['data']['message']}" specialchars=true)</p>
```

`specialchars=true` gehört zu HTML-Mails. In einer Textmail (`text/plain`, Standard)
kann Besucher-Text nichts ausführen; `specialchars` würde dort nur Entities wie `&amp;`
sichtbar machen.

Template-Namen lassen sich wie bei [CMS:INCLUDE](include.md) mit dem Bereich
angeben (`bereich.name`).

### `paths_absolute`

Serverrelative Pfade (`/media/…`) in `src`, `href`, `action`, `background`, `poster`
und `url(…)` des HTML-Bodys absolut machen:

```cms
(CMS:EMAIL contenttype="text/html" bodytemplate=newsletter_html paths_absolute=true …)
```

### `sender_url`

Domain für `paths_absolute`, wenn nicht Protokoll und Hauptdomain der Instanz gelten
sollen; ohne Protokoll gilt das der Instanz:

```cms
(CMS:EMAIL paths_absolute=true sender_url="https://www.example.com" …)
```

### `debug`

E-Mail nur ins Logfile `var/log/{Datum}_mail-debug.log` schreiben, nicht senden – zum
Testen eines Formulars. Der Befehl verhält sich sonst wie beim echten Versand
(Pflichtfelder, `successtemplate`):

```cms
(CMS:EMAIL debug=true …)
```

### `requiredfields`

Pflichtfelder im Request-Array `data[…]` (bzw. dem über `data` benannten Array); fehlt
eines, wird nicht gesendet und `CMS:MESSAGE` zeigt die Meldung. Bei Feldern mit
mehreren Werten (Checkbox-Gruppe `data[themen][]`) genügt ein ausgewählter Wert:

```cms
(CMS:EMAIL requiredfields="name,email,message" …)
```

### `data`

Name des Request-Arrays mit den Formulardaten (Standard `data`, also `$_POST['data']`):

```cms
<input name="form[name]">
(CMS:EMAIL data=form requiredfields="name" …)
```

### `submit`

Der Befehl läuft nur, wenn `submit` einen Wert hat – beim ersten Aufruf des Formulars also nicht:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:EMAIL submit="$submit" …)
<button name="submit" value="1">Senden</button>
```

### `successtemplate`

Nach erfolgreichem Versand wird dieses Template eingebunden und die Seite beendet (ohne `CMS:CONTINUE`):

```cms
(CMS:EMAIL successtemplate=contact_thanks …)
```

### `message`

Eigene Meldungstexte je Fehlerfall (Indizes siehe [Meldungen](#meldungen)):

```cms
(CMS:EMAIL message[required]="Bitte alle Pflichtfelder ausfüllen." …)
```

### `attachment`

Dateien relativ zum `var`-Verzeichnis anhängen – nur aus `download/`, `upload/` und
`modules/`:

```cms
(CMS:EMAIL attachment[]="download/agb.pdf" …)
```

### `throttle`

```cms
(CMS:EMAIL throttle=true …)
(CMS:EMAIL throttle=newsletter …)
```

Ein eigener Name braucht eine Grenze in der Konfiguration (`user.throttle.newsletter`,
Systemadministrator); fehlt sie, gilt die von `true`.

### `formguard`

```cms
(CMS:EMAIL formguard=true …)
<form method="post">(CMS:USER_TOKEN formguard=true) …</form>
```

---

[← Übersicht](README.md)
