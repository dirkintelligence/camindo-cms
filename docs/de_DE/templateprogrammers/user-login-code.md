# CMS:USER_LOGIN_CODE

Zweiter Schritt der Anmeldung: prüft den Code aus der Authenticator-App, per E-Mail oder
einen Wiederherstellungscode und meldet den Frontend-User an.

```cmscode
(CMS:USER_LOGIN_CODE [parameter=wert …])
```

***Methode:*** `Modules\Record\RecordUser::cmsLoginCode()`  
***PHP-Schnipsel:*** `include_and_stop` (danach)

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `successtemplate` | – | – | ja | Detailtemplate, das nach der erfolgreichen Anmeldung eingebunden wird. |
| `submit` | `Textwert` | – |  | Der Code wird nur geprüft, wenn in diesem Feld ein Text enthalten ist. |
| `resend` | `true / false` | `false` |  | true: neuen Code per E-Mail erzeugen (gedrosselt) und `codetemplate` einbinden. |
| `codetemplate` | – | – |  | Template, das nach `resend` den neuen Code mit CMS:EMAIL verschickt – dasselbe wie bei `CMS:USER_LOGIN`. |
| `data` | `PHP-Variablenname` | `data` |  | Name des PHP-Arrays mit den Eingaben. |
| `codefield` | `Feldname` | `code` |  | Eingabefeld für den Code in `data[…]`. |
| `message[…]` | `Standardmeldung` | – |  | Fehlermeldungen. Im Index: Anwendungsfall; im Wert: Meldungstext. |
| `sessionid` | `Session-Id` | – |  | Wartende Anmeldung ohne Cookie (Apps) – `$set['user_session_id']` nach `CMS:USER_LOGIN`. |
| `cookiename` | `Cookiename` | `usess` |  | Name des Session-Cookies [a-z0-9]. |
| `csrf` | `true / false` | `true` |  | true: Der Request muss ein gültiges Token aus [CMS:USER_TOKEN](user-token.md) enthalten. |

## Anwendung

Verlangt der Profil-Bereich einen ***zweiten Faktor***, bindet
[CMS:USER_LOGIN](user-login.md) nach dem richtigen Passwort das `codetemplate` ein. Die
Anmeldung wartet dann in einer Session ohne Frontend-User; `USER_LOGIN_CODE` schließt sie ab.

Verteilen Sie die Arbeit auf zwei Templates:

* ***`u_code_mail`*** verschickt den Code (`$set['login_code']`) mit [CMS:EMAIL](email.md)
  und bindet dann das Formular ein. Es ist das `codetemplate` von `CMS:USER_LOGIN` und von
  `CMS:USER_LOGIN_CODE` (für „Neuen Code senden“).
* ***`u_code`*** enthält `CMS:USER_LOGIN_CODE` und das Formular; es ist das Ziel des
  Formulars.

Ohne `submit` und `resend` füllt der Befehl nur `$set`, und das Template läuft weiter.
Innerhalb eines Aufrufs wertet er `submit`/`resend` nur beim ersten Mal aus – wenn
`u_code_mail` nach dem Nachsenden wieder `u_code` einbindet, passiert nichts doppelt.
Geben Sie den Schaltflächen eigene Namen: das Anmeldeformular hat schon `submit=1`
mitgeschickt.

`u_code_mail`:

```cms
<?php
$to = (string)($set['pending_user']['email'] ?? '');
$send = (!empty($set['login_code']) && $to !== '') ? '1' : '';
$body = "Ihr Anmeldecode: {$set['login_code']} (gültig 10 Minuten)";
?>
(CMS:EMAIL submit="$send" to="$to" subject="Ihr Anmeldecode" body="$body")
(CMS:INCLUDE u_code)
```

`u_code`:

```cms
<?php
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$submit = ($isPost && !empty($_POST['code_submit'])) ? '1' : '';
$resend = ($isPost && !empty($_POST['code_resend'])) ? 'true' : 'false';
?>
(CMS:USER_LOGIN_CODE submit="$submit" resend="$resend" successtemplate=u_welcome codetemplate=u_code_mail)
<h1>Anmeldung bestätigen</h1>
<p class="error">(CMS:MESSAGE)</p>
<?php if (!empty($set['code_resent'])) { ?><p>Ein neuer Code ist unterwegs.</p><?php } ?>
<?php if (!empty($set['login_pending'])) { ?>
<form method="post" action="/0,u_code">
  (CMS:USER_TOKEN)
  <input name="data[code]" autocomplete="one-time-code" required>
  <button name="code_submit" value="1">Bestätigen</button>
</form>
<?php if ($set['second_factor'] === 'email') { ?>
<form method="post" action="/0,u_code">
  (CMS:USER_TOKEN)
  <button name="code_resend" value="1">Neuen Code senden</button>
</form>
<?php } ?>
<?php } else { ?>
<p><a href="/0,u_login">Zur Anmeldung</a></p>
<?php } ?>
```

Für einen Code per SMS schreiben Sie in `u_code_mail` statt `CMS:EMAIL` den Aufruf Ihres
SMS-Dienstes – der Core bleibt davon unberührt.

Der Befehl gehört an den Anfang des Templates (Cookie vor der Ausgabe).

| `$set[…]` | Inhalt |
|---|---|
| `login_pending` | 1, solange eine Anmeldung auf den Code wartet |
| `second_factor` | `totp` oder `email` |
| `pending_user` | das Profil der wartenden Anmeldung |
| `login_code` | nach `resend`: der neue Code (im `codetemplate`) |
| `code_resent` | 1 nach `resend` |
| nach Erfolg | wie `USER_LOGIN` (`user_id`, `user`, `new_device` …), dazu `second_factor_used` (`totp`, `email`, `recovery`) und `recovery_remaining` |

***Sicherheit:*** Ein Code per E-Mail gilt 10 Minuten (`user.login_code_expiration`).
Jeder falsche Code zählt wie ein falsches Passwort; nach drei falschen Codes verfällt die
wartende Anmeldung (Meldung `expired`) und der Frontend-User beginnt wieder mit dem Passwort.
Ein Wiederherstellungscode gilt nur einmal. „Neuen Code senden“ ist gedrosselt (drei je
10 Minuten).

Meldungs-Indizes: `code` (falsch), `expired` (abgelaufen oder zu viele Fehlversuche),
`blocked`, `throttled` (zu oft neuer Code), `token`.

## Parameter im Einzelnen

### `successtemplate`

Wie bei `USER_LOGIN` – das Profil ist dort der laufende Datensatz:

```cms
(CMS:USER_LOGIN_CODE submit="$submit" successtemplate=u_welcome)
```

### `submit`

```cms
<?php $submit = !empty($_POST['code_submit']) ? '1' : ''; ?>
(CMS:USER_LOGIN_CODE submit="$submit" successtemplate=u_welcome)
```

### `resend`, `codetemplate`

```cms
<?php $resend = !empty($_POST['code_resend']) ? 'true' : 'false'; ?>
(CMS:USER_LOGIN_CODE resend="$resend" submit="$submit" successtemplate=u_welcome codetemplate=u_code_mail)
```

### `data`, `codefield`

```cms
<input name="login[otp]">
(CMS:USER_LOGIN_CODE data=login codefield=otp submit="$submit" successtemplate=u_welcome)
```

### `message`

```cms
(CMS:USER_LOGIN_CODE submit="$submit" successtemplate=u_welcome
  message[code]="Der Code stimmt nicht."
  message[expired]="Bitte melden Sie sich erneut an."
)
```

### `sessionid`, `cookiename`

Wie bei [CMS:USER_AUTH](user-auth.md).

---

[← Übersicht](README.md)
