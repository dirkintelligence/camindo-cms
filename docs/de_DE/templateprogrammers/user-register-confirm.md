# CMS:USER_REGISTER_CONFIRM

Schaltet einen Profil-Datensatz frei und setzt status=0.

```cmscode
(CMS:USER_REGISTER_CONFIRM [parameter=wert …])
```

***Methode:*** `Modules\Record\RecordUser::cmsRegisterConfirm()`  
***PHP-Schnipsel:*** `include_and_stop` (danach)

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `successtemplate` | – | – | ja | Detailtemplate, das eingebunden wird, wenn die Registrierung erfolgreich abgeschlossen werden konnte. |
| `hash` | `Hash-ID` | – | ja | Hash-ID, die mittels CMS:USER_REGISTER erzeugt und per E-Mail versendet wurde. |
| `message[…]` | `Meldung` | – |  | Fehlermeldung. Im Index: Anwendungsfall; im Wert: Meldungstext (vgl. Tabelle). |

`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Löst den Token aus `CMS:USER_REGISTER` ein und schaltet das Profil frei (Status 0).
Steht auf der Seite, auf die der Bestätigungslink zeigt:

```cms
(CMS:USER_REGISTER_CONFIRM hash="{$_GET['hash']}" successtemplate=u_confirmed
  message[hash]="Der Link ist ungültig oder abgelaufen.")
(CMS:INCLUDE header)
<p class="error">(CMS:MESSAGE)</p>
```

`u_confirmed`:

```
<p>Ihr Konto ist freigeschaltet – <a href="/0,u_login">jetzt anmelden</a>.</p>
```

Tokens verfallen nach 24 Stunden (`user.token_expiration`). Nach Erfolg sind
`$set['user_id']` und `$set['user']` gefüllt; angemeldet ist der Frontend-User damit noch nicht.

## Parameter im Einzelnen

### `successtemplate`

```cms
(CMS:USER_REGISTER_CONFIRM hash="{$_GET['hash']}" successtemplate=u_confirmed)
```

### `hash`

Token aus dem Link. Ist er leer, unbekannt, abgelaufen oder schon eingelöst, erscheint
die Meldung `hash` – der Befehl kennt kein `submit`:

```cms
(CMS:USER_REGISTER_CONFIRM hash="{$_GET['hash']}" successtemplate=u_confirmed)
```

### `message`

```cms
(CMS:USER_REGISTER_CONFIRM hash="{$_GET['hash']}" successtemplate=u_confirmed message[hash]="Dieser Link ist nicht mehr gültig.")
```

---

[← Übersicht](README.md)
