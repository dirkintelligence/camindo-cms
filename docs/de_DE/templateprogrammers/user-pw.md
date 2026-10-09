# CMS:USER_PW

Bereitet das Zurücksetzen eines Passworts vor.

```cmscode
(CMS:USER_PW [parameter=wert …])
```

***Methode:*** `Modules\Record\RecordUser::cmsPw()`  
***PHP-Schnipsel:*** `include_and_stop` (danach)

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `successtemplate` | – | – | ja | Detailtemplate, das eingebunden wird, falls "uniquevalue" im Feld "uniquefield" einem Profil zugeordnet werden konnte. |
| `errortemplate` | – | – | ja | Detailtemplate, das eingebunden wird, falls "uniquevalue" angegeben wurde, aber keinem Profil zugeordnet werden konnte. |
| `container` | `Container-ID oder -Name` | – | ja | ID oder Name des Profil-Containers, in dem die abzugleichenden Profile gespeichert sind. |
| `uniquefield` | `Feldname` | – | ja | Name des Feldes, über das gesucht wird (Feldtyp chars, text oder encrypted, z. B. "email"). Der Wert muss genau ein Profil treffen. |
| `uniquevalue` | `Text` | – |  | Gesuchter Wert im Feld "uniquefield", z. B. $_POST[email]. |
| `submit` | `Textwert` | – | ja | Der Befehl wird nur ausgeführt, wenn in diesem Feld ein Text enthalten ist. |
| `message[…]` | `Text` | – |  | Fehlermeldung. Im Index: Anwendungsfall; im Wert: Meldungstext (vgl. Tabelle). |
| `csrf` | `true / false` | `true` |  | true: Der Request muss ein gültiges Token aus [CMS:USER_TOKEN](user-token.md) enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab – nur mit gutem Grund. |
| `formguard` | `true / false / Sekunden` | `false` |  | true: Formularschutz gegen Robots – das Formular muss die Felder aus `(CMS:USER_TOKEN formguard=true)` enthalten und frühestens nach 3 Sekunden (`user.formguard_min_seconds`) abgeschickt werden. Eine Zahl setzt die Mindestzeit für dieses Formular (`formguard=5`), `0` schaltet ab. Sonst Meldung `throttled`. |

`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Erster Schritt „Passwort vergessen“: Sucht das Profil über ein eindeutiges Feld
(meist die E-Mail-Adresse) und erzeugt einen Einmal-Token in `$set['hash']`. Das
`successtemplate` verschickt den Link; die Zielseite meldet den Frontend-User mit
`(CMS:USER_LOGIN hash=…)` an, danach setzt er per `CMS:USER_INPUT` ein neues Passwort:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_PW container=members uniquefield=email uniquevalue="{$_POST['data']['email']}"
  submit="$submit" successtemplate=u_pwsent errortemplate=u_pwfail)
<h1>Passwort vergessen</h1>
<form method="post">
  (CMS:USER_TOKEN)
  <input name="data[email]" type="email">
  <button name="submit" value="1">Link anfordern</button>
</form>
```

`u_pwsent` verschickt den Link mit [CMS:EMAIL](email.md):

```cms
<?php $link = 'https://www.example.com/0,u_hashlogin?hash=' . rawurlencode($set['hash']); ?>
(CMS:EMAIL submit="1" to="{$set['user']['email']}" subject="Neues Passwort"
  body="Link (eine Stunde gültig): $link")
(CMS:MESSAGE)
<p>Wir haben Ihnen einen Link geschickt.</p>
```

Meldungs-Indizes: `notfound`, `container`, `throttled`, `token`.

### Drosselung und Formularschutz

Jede Anfrage verschickt eine E-Mail an eine Adresse, die jemand eingetippt hat. Damit
niemand über Ihr Formular fremde Postfächer flutet, zählt camindo die Anfragen
(***Drosselung***): höchstens drei je eingegebener Adresse und Stunde, zehn je
Herkunft (IP-Adresse) und 200 je Website (Systemadministrator: `user.throttle`). Gezählt wird
der eingegebene Wert – ob es das Konto gibt, spielt keine Rolle. Ist eine Grenze
erreicht, entsteht kein Link, und der Befehl bindet das ***`errortemplate`*** ein – mit
`$set['throttled']` = 1 und der Meldung `throttled`. Zeigen Sie dort denselben Text wie
im Erfolgsfall, verrät die Seite weder, ob es das Konto gibt, noch, dass gedrosselt wurde.

Gegen Robots, die jedes Formular blind füllen, hilft zusätzlich der
***Formularschutz***: `formguard=true` am Befehl und `(CMS:USER_TOKEN formguard=true)`
im Formular. Wer das Formular schneller als in drei Sekunden abschickt oder das
unsichtbare Köderfeld füllt, bekommt dieselbe Antwort wie bei der Drosselung:

```cms
(CMS:USER_PW container=members formguard=true uniquefield=email uniquevalue="{$_POST['data']['email']}"
  submit="$submit" successtemplate=u_pwsent errortemplate=u_pwfail)
…
<form method="post">
  (CMS:USER_TOKEN formguard=true)
  …
</form>
```

Der Link gilt ***eine Stunde*** und nur einmal (Einstellung
`user.password_token_expiration`). Er funktioniert auf jedem Gerät – wer sein Passwort
vergessen hat, sitzt oft gerade an einem neuen. Kommt die Anmeldung über den Link von
einem unbekannten Gerät, meldet [CMS:USER_LOGIN](user-login.md#anmeldung-von-einem-neuen-gerät) das wie
jede andere Anmeldung.

Zeigen Sie im `errortemplate` ***denselben Text*** wie im `successtemplate` („Falls zu
dieser Adresse ein Konto existiert, haben wir einen Link geschickt."). Sonst kann jeder
ausprobieren, welche E-Mail-Adressen bei Ihnen ein Konto haben.

## Parameter im Einzelnen

### `successtemplate`

Eingebunden, wenn ein Profil gefunden wurde; darin `$set['hash']`, `$set['user']`, und das Profil ist der laufende Datensatz:

```cms
(CMS:USER_PW container=members uniquefield=email uniquevalue="$email" submit="$submit" successtemplate=u_pwsent errortemplate=u_pwfail)
```

### `errortemplate`

Eingebunden, wenn `uniquevalue` angegeben war, aber kein Profil passt:

```cms
(CMS:USER_PW … errortemplate=u_pwfail)
```

### `container`

```cms
(CMS:USER_PW container=members …)
```

### `uniquefield`

Feld, über das gesucht wird (Feldtyp `chars`, `text` oder `encrypted`). Verglichen wird
wörtlich, ohne Groß-/Kleinschreibung; trifft der Wert mehr als ein Profil, gilt das wie
„nicht gefunden“ – markieren Sie das Feld deshalb im Bereich als eindeutig:

```cms
(CMS:USER_PW container=members uniquefield=email …)
(CMS:USER_PW container=members uniquefield=nickname …)
```

### `uniquevalue`

Gesuchter Wert, meist aus dem Formular. Leer = der Befehl tut nichts:

```cms
(CMS:USER_PW container=members uniquefield=email uniquevalue="{$_POST['data']['email']}" …)
```

### `submit`

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_PW … submit="$submit")
```

### `message`

```cms
(CMS:USER_PW … message[notfound]="Zu dieser Adresse gibt es kein Konto.")
```

---

[← Übersicht](README.md)
