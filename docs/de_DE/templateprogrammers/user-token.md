# CMS:USER_TOKEN

Gibt das Sicherheits-Token (CSRF-Token) für ein Formular aus, das einen schreibenden
`CMS:USER_*`-Befehl auslöst.

```cmscode
(CMS:USER_TOKEN [parameter=wert …])
```

***Methode:*** `Modules\Record\RecordUser::cmsToken()`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `format` | `"field" / "value"` | `"field"` |  | field: verstecktes Eingabefeld `<input type="hidden" name="_token">`, der Wert mit htmlspecialchars maskiert. value: nur das Token (für Ajax), unmaskiert. |
| `set` | `Array-Index von $set` | – |  | Die Ausgabe wird nicht ausgegeben, sondern in `$set` gespeichert. Das Token steht zusätzlich immer in `$set['user_token']`. |
| `formguard` | `true / false` | `false` |  | true: zusätzlich Zeitstempel und ein unsichtbares Köderfeld ausgeben (Formularschutz gegen Robots). |

## Anwendung

Das Token verhindert, dass eine fremde Website im Namen Ihrer Besucher Formulare
abschickt (Cross-Site Request Forgery): Anmelden, Registrieren, Passwort-Reset,
Beiträge speichern oder löschen, Abmelden, Warenkorb ändern. Diese Befehle prüfen
das Token und brechen ohne gültiges Token mit einer Meldung (Index `token`) ab:

| Befehl | geprüft |
|---|---|
| `USER_LOGIN`, `USER_REGISTER`, `USER_PW`, `USER_INPUT`, `USER_2FA` | wenn `submit` gefüllt ist (die Anmeldung per `hash` nicht) |
| `USER_LOGIN_CODE` | wenn `submit` oder `resend` gesetzt ist |
| `USER_DELETE` | immer |
| `USER_LOGOUT` | immer – mit `submit` nur, wenn `submit` gefüllt ist |
| `USER_BASKET` | bei `add`, `update`, `update_addon` – `load` nicht; ohne gültiges Token wird nur gelesen |

Das Token hängt an einem Cookie. Der **erste** Aufruf auf einer Seite muss deshalb
vor jeder Ausgabe stehen – am Anfang des Templates (bzw. im Kopf-Include vor
`<!doctype>`), mit `set`, damit dort nichts ausgegeben wird. Danach schreiben Sie
`(CMS:USER_TOKEN)` in **jedes** Formular, das einen dieser Befehle auslöst:

```cms
(CMS:USER_TOKEN set=token)
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_LOGIN container=members submit="$submit" successtemplate=u_welcome)
<!doctype html>
…
<form method="post">
  (CMS:USER_TOKEN)
  <input name="data[nickname]"> <input type="password" name="data[password]">
  <button name="submit" value="1">Anmelden</button>
</form>
```

### Formularschutz gegen Robots

`(CMS:USER_TOKEN formguard=true)` gibt zusätzlich einen signierten Zeitstempel und ein
***Köderfeld*** aus, das Besucher nicht sehen. Befehle mit `formguard=true`
([CMS:USER_REGISTER](user-register.md), [CMS:USER_PW](user-pw.md),
[CMS:EMAIL](email.md)) weisen das Formular ab, wenn das Köderfeld gefüllt ist oder es
schneller als nach drei Sekunden bzw. später als nach einer Stunde abgeschickt wurde.
Beide Zeiten stellt der Systemadministrator ein (`user.formguard_min_seconds`,
`formguard_max_seconds`); für ein einzelnes Formular setzt `formguard=5` am Befehl die
Mindestzeit auf fünf Sekunden – etwa für ein langes Formular, das niemand in drei
Sekunden ausfüllt.
Das hält Robots auf, die blind jedes Formular füllen – kein Captcha, kein fremder Dienst.
Ein externes Captcha kann der Systemadministrator zusätzlich anbinden
(`security.form_challenge`); dessen Widget binden Sie dann selbst ein.

```cms
<form method="post">
  (CMS:USER_TOKEN formguard=true)
  …
</form>
```

Für Ajax-Aufrufe schicken Sie das Token als POST-Feld `_token` oder im Header
`X-Camindo-Token` mit. Ein `FormData` aus einem Formular mit `(CMS:USER_TOKEN)` enthält
es bereits:

```cms
<script>
fetch('/0,basket_api', {
  method: 'POST',
  headers: { 'X-Camindo-Token': '(CMS:USER_TOKEN format=value)' },
  body: new URLSearchParams({ product: 42 })
});
</script>
```

Steht der erste Aufruf zu spät, gibt es kein Token: an Stelle des Feldes steht ein
HTML-Kommentar mit Hinweis, und das Formular scheitert mit der Meldung `token`.

Das Cookie (`ucsrf`) gilt für alle Formulare der Website und überlebt die Anmeldung. Im Query-String
(GET) wird es nicht angenommen – schreibende Aktionen gehören in POST-Formulare.

Die Prüfung lässt sich je Befehl mit `csrf=false` abschalten, der Systemadministrator
kann sie mit `user.csrf = false` global abschalten. Beides öffnet die Befehle für
fremde Websites und ist nur für Übergangszeiten gedacht.

---

[← Übersicht](README.md)
