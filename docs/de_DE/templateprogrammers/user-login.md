# CMS:USER_LOGIN

Überprüft die Anmeldedaten eines Frontend-Users und erzeugt eine neue Session.

```cmscode
(CMS:USER_LOGIN [parameter=wert …])
```

***Methode:*** `Modules\Record\RecordUser::cmsLogin()`  
***PHP-Schnipsel:*** `include_and_stop` (danach)

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `successtemplate` | – | – | ja | Detailtemplate, das nach einer erfolgreichen Anmeldung eingebunden wird. |
| `container` | `Container-ID oder -Name` | – | ja | ID oder Name des Profil-Containers, in dem die Daten der Frontend-User gespeichert sind. |
| `data` | `PHP-Variablenname` | `data` |  | Name des PHP-Arrays, in dem die Anmeldedaten enthalten sind. |
| `message[…]` | `Standardmeldung` | – |  | Fehlermeldungen. Im Index: Anwendungsfall; im Wert: Meldungstext (vgl. Tabelle). |
| `hash` | `Hash` | – |  | Ein Login kann auch mittels Hash durchgeführt werden (Token aus CMS:USER_PW, Passwort vergessen). |
| `trim` | `true / false` | `false` |  | true: Leerzeichen am Anfang und Ende der Eingaben entfernen. |
| `cookie` | `true / false` | `true` |  | true: Es wird standardmäßig ein Cookie gesetzt, um die Anmeldung über mehrere Seiten hinweg aufrechtzuerhalten. |
| `submit` | `Textwert` | – |  | Der Befehl wird nur ausgeführt, wenn in diesem Feld ein Text enthalten ist. Entfällt bei der Anmeldung per "hash". |
| `cookiename` | `Cookiename` | `usess` |  | Name des Session-Cookies [a-z0-9]. |
| `csrf` | `true / false` | `true` |  | true: Der Request muss ein gültiges Token aus [CMS:USER_TOKEN](user-token.md) enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab – nur mit gutem Grund. |
| `codetemplate` | – | – |  | Template für den zweiten Schritt der Anmeldung, wenn der Profil-Bereich einen zweiten Faktor verlangt. Pflicht, sobald der Bereich einen einsetzt. |

`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Prüft Anmeldename und Passwort gegen den Profil-Bereich und legt eine Session an
(Cookie). Die Felder sind die im Bereich als Anmeldename und Passwort festgelegten
(`data[nickname]`, `data[password]`); nach Erfolg wird `successtemplate` eingebunden,
darin ist das Profil der laufende Datensatz und `$set['user_id']` / `$set['user']`
sind gefüllt:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=u_welcome)
<h1>Anmeldung</h1>
<p class="error">(CMS:MESSAGE)</p>
<form method="post">
  (CMS:USER_TOKEN)
  <input name="data[nickname]" value="(CMS:TEXT useval="{$_POST['data']['nickname']}" specialchars=true)">
  <input type="password" name="data[password]">
  <button name="submit" value="1">Anmelden</button>
</form>
```

Anmeldung über den Link aus `CMS:USER_PW` (Passwort vergessen):

```cms
(CMS:USER_LOGIN container=members hash="{$_GET['hash']}" successtemplate=u_newpw)
```

Der Befehl gehört an den Anfang des Templates (Cookie vor der Ausgabe). Fehlversuche
werden gezählt (Brute-Force-Schutz). Meldungs-Indizes: `failed`, `blocked`, `disabled`
(Status 1), `unconfirmed` (Status 2), `hash`, `container`, `token`. Gezählt wird je Anmeldename und
zusätzlich je Herkunft (IP-Adresse): wer von einem Anschluss aus viele Konten mit je einem
Passwort durchprobiert, wird ebenfalls gesperrt (`blocked`).

### Zweiter Faktor

Im Profil-Bereich legt der Administrator fest, ob eine ***Anmeldung in zwei Schritten***
nötig ist (Einstellung „Zweiter Faktor“: aus, freiwillig, Code bei neuem Gerät, immer).
Braucht eine Anmeldung einen Code, meldet `USER_LOGIN` nach dem richtigen Passwort noch
niemanden an, sondern bindet `codetemplate` ein. Dort stehen:

| `$set[…]` | Inhalt |
|---|---|
| `second_factor` | `totp` (Code aus der Authenticator-App) oder `email` (Code per E-Mail) |
| `login_pending` | 1 |
| `pending_user` | das Profil (auch der laufende Datensatz) – etwa für die E-Mail-Adresse |
| `login_code` | beim Code per E-Mail: der sechsstellige Code, den Sie verschicken |

Das `codetemplate` verschickt den Code mit [CMS:EMAIL](email.md) und zeigt das Formular
für [CMS:USER_LOGIN_CODE](user-login-code.md) (Beispiel dort). Wie alle `CMS:USER_*`-Befehle
verschickt `USER_LOGIN` selbst keine E-Mail – das tut immer das Template. Auch die Anmeldung über den Link aus
„Passwort vergessen“ (`hash`) verlangt den Code, wenn der Frontend-User eine Authenticator-App
eingerichtet hat; die Einrichtung selbst übernimmt [CMS:USER_2FA](user-2fa.md).

```cms
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=u_welcome codetemplate=u_code_mail)
```

### Anmeldung von einem neuen Gerät

camindo merkt sich die Geräte (Browser), mit denen sich ein Profil anmeldet. Kommt die
Anmeldung von einem ***unbekannten Gerät*** und hat das Profil schon andere, steht im
`successtemplate` `$set['new_device']` auf 1; `$set['device']` enthält `label`
(z. B. „Firefox / macOS“), `ip` und `time` (UTC). Den Hinweis an den Inhaber schicken
Sie selbst – vor der Weiterleitung, mit [CMS:EMAIL](email.md):

```cms
<?php
$to = (string)($set['user']['email'] ?? '');
$send = (!empty($set['new_device']) && $to !== '') ? '1' : '';
$body = "Neue Anmeldung: {$set['device']['label']}, IP {$set['device']['ip']}, {$set['device']['time']} UTC."
      . " Waren Sie das nicht, setzen Sie bitte ein neues Passwort.";
?>
(CMS:EMAIL submit="$send" to="$to" subject="Neue Anmeldung" body="$body")
```

Die allererste Anmeldung eines Profils löst keinen Hinweis aus. Ob stattdessen eine SMS
geht, legt der Systemadministrator fest (siehe Handbuch für Administratoren, Betrieb).

## Parameter im Einzelnen

### `successtemplate`

```cms
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=u_welcome)
```

In `u_welcome`: `Hallo (CMS:TEXT nickname)` - das Profil ist dort der laufende Datensatz.

### `container`

Profil-Bereich (Name oder Id):

```cms
(CMS:USER_LOGIN container=members …)
```

### `data`

Name des Request-Arrays mit den Anmeldedaten (Standard `data`):

```cms
<input name="login[nickname]"> <input type="password" name="login[password]">
(CMS:USER_LOGIN container=members data=login submit="$submit" successtemplate=u_welcome)
```

### `message`

```cms
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=u_welcome
  message[failed]="Name oder Passwort falsch."
  message[unconfirmed]="Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse."
  message[blocked]="Zu viele Fehlversuche – bitte später erneut versuchen."
)
```

### `hash`

Anmeldung per Einmal-Token aus `CMS:USER_PW` statt mit Passwort; `submit` entfällt:

```cms
(CMS:USER_LOGIN container=members hash="{$_GET['hash']}" successtemplate=u_newpw)
```

In `u_newpw` setzt der Frontend-User per `CMS:USER_INPUT container=members id="{$set['user_id']}" fields=password` ein neues Passwort.
Nach dieser Anmeldung ist `$set['password_reset']` 1: eine Stunde lang (Einstellung
`user.password_token_expiration`) braucht er dafür das bisherige Passwort nicht – siehe
[CMS:USER_INPUT, Passwort ändern](user-input.md#passwort-ändern).

### `codetemplate`

Template für den zweiten Schritt (siehe oben):

```cms
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=u_welcome codetemplate=u_code_mail)
(CMS:USER_LOGIN container=members hash="{$_GET['hash']}" successtemplate=u_newpw codetemplate=u_code_mail)
```

Fehlt es, obwohl der Bereich einen zweiten Faktor verlangt, scheitert die Anmeldung mit
`failed` und einem Eintrag im Protokoll `user`.

### `trim`

Leerzeichen um Name und Passwort entfernen (Kopierfehler auf Mobilgeräten):

```cms
(CMS:USER_LOGIN container=members trim=true submit="$submit" successtemplate=u_welcome)
```

### `cookie`

Kein Cookie setzen - die Session-Id steht dann nur in `$set['user_session_id']` (für Apps, die sie selbst weiterreichen):

```cms
(CMS:USER_LOGIN container=members cookie=false submit="$submit" successtemplate=api_token)
```

### `submit`

Nur ausführen, wenn das Formular abgeschickt wurde:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=u_welcome)
```

### `cookiename`

```cms
(CMS:USER_LOGIN container=members cookiename=shop submit="$submit" successtemplate=u_welcome)
```
Muss bei allen `USER_*`-Befehlen gleich sein.

---

[← Übersicht](README.md)
