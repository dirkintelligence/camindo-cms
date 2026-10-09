# CMS:USER_REGISTER

Erzeugt einen Profil-Datensatz aus den angegebenen Daten und setzt den Status auf status=2.

```cmscode
(CMS:USER_REGISTER [parameter=wert …])
```

***Methode:*** `Modules\Record\RecordUser::cmsRegister()`  
***PHP-Schnipsel:*** `include_and_stop` (danach)

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `successtemplate` | – | – | ja | Detailtemplate, das nach erfolgreicher Registrierung eingebunden wird. |
| `container` | `Container-ID oder -Name` | – | ja | ID oder Name des Profil-Containers, in dem die Daten der Frontend-User gespeichert werden sollen. |
| `emailfield` | `Feldname` | – | ja | Feldname, in dem die E-Mail des Frontend-Users gespeichert ist (dorthin wird der Freischalt-Link gesendet). |
| `fields` | `kommaseparierte Liste` | – |  | Namen der Eingabefelder, die geschrieben werden dürfen. Leer = alle definierten Felder des Containers. |
| `overwrite[…]` | `Schlüssel-Wert-Paare` | – |  | Explizites Überschreiben der Eingaben des Frontend-Users (auch Metafelder, außer record_id, area_id, instance_id, tmp, deleted, origin_id, owner_id, created_by, changed_by). Hier keine Eingaben des Frontend-Users weiterreichen! |
| `message[…]` | `Meldung` | – |  | Fehlermeldung. Im Index: Anwendungsfall; im Wert: Meldungstext (vgl. Tabelle bei CMS:USER_INPUT). |
| `submit` | `Textwert` | – | ja | Der Befehl wird nur ausgeführt, wenn in diesem Feld ein Text enthalten ist. |
| `owner` | `Benutzer-ID` | – |  | admin_id des Backend-Owners des neuen Profils (record.owner_id, created_by). Ohne Angabe gilt user.default_owner_id. |
| `data` | `PHP-Variablenname` | `data` |  | Name des PHP-Arrays, das die zu speichernden Daten enthält. |
| `csrf` | `true / false` | `true` |  | true: Der Request muss ein gültiges Token aus [CMS:USER_TOKEN](user-token.md) enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab – nur mit gutem Grund. |
| `formguard` | `true / false / Sekunden` | `false` |  | true: Formularschutz gegen Robots – das Formular muss die Felder aus `(CMS:USER_TOKEN formguard=true)` enthalten und frühestens nach 3 Sekunden (`user.formguard_min_seconds`) abgeschickt werden. Eine Zahl setzt die Mindestzeit für dieses Formular (`formguard=5`), `0` schaltet ab. Sonst Meldung `throttled`. |

`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Legt ein Profil an (Status 2 = unbestätigt) und erzeugt einen Freischalt-Token in
`$set['hash']`; das `successtemplate` verschickt den Bestätigungslink, dessen Zielseite
`CMS:USER_REGISTER_CONFIRM` aufruft. Anmeldename und E-Mail müssen im Bereich noch frei
sein, `emailfield` muss eine gültige Adresse enthalten. Anmeldenamen, die mit `user_`
beginnen, sind reserviert – so heißen anonymisierte Profile (`user_{record_id}`, siehe
[Lösch-Policies](../admins/15-datenschutz.md#löschen-nach-lösch-policy)); die Registrierung
lehnt sie ab:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_REGISTER container=members emailfield=kontakt_email fields="nickname,kontakt_email,password"
  submit="$submit" successtemplate=u_registered)
<h1>Registrieren</h1>
<p class="error">(CMS:MESSAGE)</p>
<form method="post">
  (CMS:USER_TOKEN)
  <input name="data[nickname]"> <input name="data[kontakt_email]" type="email">
  <input name="data[password]" type="password">
  <button name="submit" value="1">Konto anlegen</button>
</form>
```

`u_registered` verschickt den Link mit [CMS:EMAIL](email.md). Die Adresse steht dort
in `$set['email']` – ***immer unter diesem Namen***, gleich wie das Feld aus `emailfield`
heißt (hier `kontakt_email`). Unter dem Feldnamen findet sie sich nur im Profil,
`$set['user']['kontakt_email']`:

```cms
(CMS:URL id=0 template=u_confirm absolute=true set=link)
(CMS:EMAIL submit="1" to="{$set['email']}" subject="Bitte bestätigen"
  body="Konto freischalten: {$set['link']}?hash={$set['hash']}")
(CMS:MESSAGE)
<p>Bitte bestätigen Sie Ihre E-Mail-Adresse.</p>
```

Meldungs-Indizes: `container`, `email`, `exists`, `save`, `throttled`, `owner` (kein gültiger Owner, siehe `owner`), `token` sowie Feldfehler des Bereichs.

### Drosselung und Formularschutz

Jede Registrierung schickt eine E-Mail an die eingegebene Adresse. camindo begrenzt das
(***Drosselung***): fünf Registrierungen je Herkunft (IP-Adresse) und Stunde, drei je
E-Mail-Adresse und Tag, 200 je Website und Stunde. Gezählt werden Registrierungen und
Versuche mit einem schon vergebenen Namen – ein Tippfehler bei der Passwort-Richtlinie
zählt nicht. Meldung dann: `throttled`, `$set['throttled']` = 1. Wie bei
[CMS:USER_PW](user-pw.md#drosselung-und-formularschutz) schützt `formguard=true` (mit
`(CMS:USER_TOKEN formguard=true)` im Formular) vor Robots.

Die Meldung `exists` verrät, dass es einen Namen oder eine Adresse schon gibt; die
Drosselung begrenzt, wie oft sich das ausprobieren lässt.

Nie bestätigte Registrierungen löscht camindo nach sieben Tagen
(`user.unconfirmed_days`): wer die Adresse eines anderen einträgt, blockiert sie so nur
kurz.

## Parameter im Einzelnen

### `successtemplate`

Nach dem Anlegen eingebunden. Darin stehen:

| `$set[…]` | Inhalt |
|---|---|
| `hash` | Freischalt-Token für den Bestätigungslink |
| `email` | die geprüfte E-Mail-Adresse – unabhängig vom Namen des Feldes aus `emailfield` |
| `user_id` | record_id des neuen Profils |
| `user` | das Profil als Array (Feldnamen als Schlüssel); es ist auch der laufende Datensatz |


```cms
(CMS:USER_REGISTER container=members emailfield=email submit="$submit" successtemplate=u_registered)
```

### `container`

```cms
(CMS:USER_REGISTER container=members …)
```

### `emailfield`

Feld mit der E-Mail-Adresse - wird geprüft und in `$set['email']` abgelegt (unter
diesem festen Namen, nicht unter dem Feldnamen):

```cms
(CMS:USER_REGISTER container=members emailfield=kontakt_email …)
```

### `fields`

Nur diese Felder aus dem Formular übernehmen:

```cms
(CMS:USER_REGISTER container=members emailfield=email fields="nickname,email,password" …)
```

### `overwrite`

Feste Werte, auch Metafelder - keine Usereingaben. Ausgenommen sind `record_id`,
`area_id`, `instance_id`, `tmp`, `deleted`, `origin_id`, `owner_id`, `created_by` und
`changed_by`; der Status (`status_id` 2) lässt sich hier überschreiben – dann entfällt die
Bestätigung per Link:

```cms
(CMS:USER_REGISTER container=members emailfield=email overwrite[role]=member overwrite[source]=web …)
```

### `message`

```cms
(CMS:USER_REGISTER container=members emailfield=email submit="$submit" successtemplate=u_registered
  message[exists]="Name oder E-Mail sind bereits vergeben."
  message[email]="Bitte eine gültige E-Mail-Adresse angeben."
)
```

### `submit`

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_REGISTER … submit="$submit")
```

### `data`

```cms
<input name="reg[nickname]"> …
(CMS:USER_REGISTER container=members emailfield=email data=reg submit="$submit" successtemplate=u_registered)
```

---

[← Übersicht](README.md)
