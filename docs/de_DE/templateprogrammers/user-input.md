# CMS:USER_INPUT

Erzeugt oder aktualisiert einen Datensatz aus Daten, die ein Frontend-User angegeben hat.

```cmscode
(CMS:USER_INPUT [parameter=wert …])
```

***Methode:*** `Modules\Record\RecordUser::cmsInput()`  
***PHP-Schnipsel:*** `include_and_stop` (danach)

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `successtemplate` | `Templatename` | – |  | Template, das eingebunden wird, wenn der Eintrag erfolgreich gespeichert wurde. |
| `data` | `PHP-Variablenname` | `data` |  | Name des PHP-Arrays, das die zu speichernden Daten enthält. |
| `overwrite[…]` | `Schlüssel-Wert-Paare` | – |  | Explizites Überschreiben der Eingaben des Frontend-Users (auch Metafelder, außer record_id, area_id, instance_id, tmp, deleted, origin_id, owner_id, created_by, changed_by). Hier keine Eingaben des Frontend-Users weiterreichen! |
| `container` | `Container-ID oder -Name` | – | ja | ID oder Name des Content-Containers, in dem der neue Datensatz gespeichert werden soll. |
| `message[…]` | `Text` | `Standardtext` |  | Fehlermeldung abhängig vom Index (siehe Tabelle). |
| `submit` | `Textwert` | – | ja | Der Befehl wird nur ausgeführt, wenn in diesem Feld ein Text enthalten ist. |
| `fields` | `kommaseparierte Liste` | – |  | Namen der Eingabefelder, die geschrieben werden dürfen. Leer = alle definierten Felder des Containers. |
| `id` | `Datensatz-ID` | – |  | Falls angegeben, wird versucht diesen Datensatz zu aktualisieren (UPDATE). Andernfalls wird ein neuer erstellt (CREATE). |
| `ignoreuser` | `true / false` | `false` |  | true: Der anzupassende Datensatz muss nicht dem angemeldeten Frontend-User gehören (Achtung: Sicherheitshinweise beachten!). |
| `forceupdate` | `true / false` | `false` |  | true: Es wird erzwungen, den über Parameter "id" angegebenen Datensatz zu aktualisieren. Wirft im Fehlerfall eine Meldung. |
| `masterid` | `Datensatz-ID` | – |  | Nur beim Anlegen in Bereichen mit Baumstruktur: Elternknoten des neuen Datensatzes (parent_id). |
| `owner` | `Benutzer-ID` | – |  | Nur beim Anlegen: admin_id des Backend-Owners (record.owner_id, created_by). Ohne Angabe gilt user.default_owner_id. |
| `removefiles` | `kommaseparierte Liste` | – |  | Namen der Upload-Felder, deren Inhalt gelöscht werden soll. |
| `currentfield` | `Feldname` | `current_password` |  | Nur Profil-Container: Name des Formularfeldes `data[…]` mit dem bisherigen Passwort. Ein neues Passwort wird nur mit dem bisherigen gespeichert – außer direkt nach der Anmeldung über den Reset-Link. Meldung `current`. |
| `csrf` | `true / false` | `true` |  | true: Der Request muss ein gültiges Token aus [CMS:USER_TOKEN](user-token.md) enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab – nur mit gutem Grund. |

`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Speichert Formulareingaben als Datensatz - neu anlegen oder (mit `id`) einen eigenen
Datensatz ändern. Die Felder heißen `data[feldname]`, genau wie in der
Backend-Eingabemaske; Uploads laufen über dasselbe Formular. Nach Erfolg steht
`$set['record_id']`, das `successtemplate` wird eingebunden und die Seite beendet:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_INPUT container=comments submit="$submit" successtemplate=u_saved)
(CMS:USER_AUTH)
<p class="error">(CMS:MESSAGE)</p>
<form method="post" enctype="multipart/form-data">
  (CMS:USER_TOKEN)
  <textarea name="data[comment]">(CMS:TEXT useval="{$_POST['data']['comment']}" specialchars=true lbrconvert=false)</textarea>
  <input type="file" name="data[photo]">
  <button name="submit" value="1">Speichern</button>
</form>
```

Eigenen Datensatz ändern:

```cms
<?php $submit = $_POST['submit'] ?? ''; $id = (int)($_POST['id'] ?? 0); ?>
(CMS:USER_INPUT container=comments id="$id" forceupdate=true submit="$submit" successtemplate=u_saved)
```

Kontaktformular ohne Anmeldung (`ignoreuser`), Absender-IP in ein eigenes Feld
`remote_ip` des Bereichs schreiben:

```cms
(CMS:USER_INPUT container=anfragen ignoreuser=true submit="$submit" fields="name,email,message"
  overwrite[remote_ip]="{$_SERVER['REMOTE_ADDR']}" successtemplate=anfrage_danke)
```

Wer schreiben darf: In `ugc`-Bereichen angemeldete Frontend-User, deren Profil-Bereich beim
Bereich freigeschaltet ist; in `profile`-Bereichen nur das eigene Profil (mit `id`, ein
neues Profil legt nur `CMS:USER_REGISTER` an); in gewöhnliche Datensatzbereiche nur mit
`ignoreuser=true`. Alle Prüfungen
des Bereichs (Pflichtfelder, eindeutige Werte, Dateitypen) gelten wie im Backend;
Fehler erscheinen über `CMS:MESSAGE`. Meldungs-Indizes für `message[…]`: `container`,
`access`, `notfound`, `save`, `current`, `blocked`, `owner` (kein gültiger Owner beim
Anlegen), `token`.

### Passwort ändern

Ein neues ***Passwort*** im eigenen Profil speichert der Befehl nur zusammen mit dem
***bisherigen Passwort*** im Feld `data[current_password]` – eine fremde Person, die an
eine offene Sitzung gelangt, kann das Konto so nicht übernehmen. Ausnahme: direkt nach
der Anmeldung über den Link aus [CMS:USER_PW](user-pw.md); dann steht
`$set['password_reset']` auf 1, und das Feld entfällt (eine Stunde lang, bis das neue
Passwort gespeichert ist). Nach dem Speichern werden alle anderen Anmeldungen des
Profils beendet, und `$set['password_changed']` ist 1 – das `successtemplate` kann den
Inhaber per [CMS:EMAIL](email.md) benachrichtigen.

```cms
(CMS:USER_PROTECT errortemplate=u_denied)
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_INPUT container=members id="{$set['user_id']}" forceupdate=true fields=password
  submit="$submit" successtemplate=u_pw_saved)
<p class="error">(CMS:MESSAGE)</p>
<form method="post">
  (CMS:USER_TOKEN)
  <?php if (empty($set['password_reset'])) { ?>
  <input type="password" name="data[current_password]" autocomplete="current-password">
  <?php } ?>
  <input type="password" name="data[password]" autocomplete="new-password"
    data-c9-password="(CMS:USER_PASSWORD_RULES container=members)">
  <button name="submit" value="1">Speichern</button>
</form>
```

Trägt das Passwort-Feld im Bereich die Extension ***Passwort-Richtlinie***, prüft der
Server Mindestlänge und Zeichenklassen; mit
[CMS:USER_PASSWORD_RULES](user-password-rules.md) zeigt das Formular dieselben Regeln
schon beim Tippen an.

## Parameter im Einzelnen

### `successtemplate`

Nach dem Speichern eingebunden; darin ist der gespeicherte Datensatz der laufende (`$cms`), `$set['record_id']` seine Id:

```cms
(CMS:USER_INPUT container=comments submit="$submit" successtemplate=u_saved)
```

Ohne `successtemplate` läuft das Template nach dem Speichern normal weiter.

### `data`

Name des Request-Arrays (Standard `data`):

```cms
<input name="form[comment]">
(CMS:USER_INPUT container=comments data=form submit="$submit")
```

### `overwrite`

Werte fest vorgeben - auch Metafelder - unabhängig davon, was der Frontend-User schickt. ***Keine*** Eingaben des Frontend-Users hier einsetzen. Ausgenommen sind `record_id`, `area_id`, `instance_id`, `tmp`, `deleted`, `origin_id`, `owner_id`, `created_by` und `changed_by`; ohne Angabe setzt der Befehl `needs_review` auf 1:

```cms
(CMS:USER_INPUT container=comments submit="$submit"
  overwrite[article_id]="{$cms['record_id']}"
  overwrite[status_id]=2
)
```

### `container`

Bereich (Name oder Id), in dem gespeichert wird - Typ `ugc` oder `profile`, mit
`ignoreuser=true` auch ein gewöhnlicher Datensatzbereich:

```cms
(CMS:USER_INPUT container=comments …)
(CMS:USER_INPUT container=58 …)
```

### `message`

Eigene Meldungstexte:

```cms
(CMS:USER_INPUT container=comments submit="$submit"
  message[access]="Bitte melden Sie sich an, um zu kommentieren."
  message[save]="Der Kommentar konnte nicht gespeichert werden."
)
```

### `submit`

Der Befehl läuft nur, wenn `submit` einen Wert hat - beim ersten Aufruf des Formulars also nicht:

```cms
<?php $submit = $_POST['submit'] ?? ''; ?>
(CMS:USER_INPUT container=comments submit="$submit" …)
<button name="submit" value="1">Speichern</button>
```

### `fields`

Nur diese Felder aus den Eingaben übernehmen - alles andere im Formular wird ignoriert:

```cms
(CMS:USER_INPUT container=members id="{$set['user_id']}" fields="nickname,avatar" submit="$submit")
```

### `id`

Vorhandenen Datensatz ändern; ohne `forceupdate` wird bei unbekannter/fremder Id ein neuer angelegt:

```cms
(CMS:USER_INPUT container=comments id="{$_POST['id']}" submit="$submit")
```

### `ignoreuser`

Keine Anmeldung und keine Besitzprüfung - für Kontakt- und Gästebuchformulare. Das Template trägt die Verantwortung (Spam-Schutz, Feldliste per `fields`):

```cms
(CMS:USER_INPUT container=gaestebuch ignoreuser=true fields="name,message" submit="$submit" successtemplate=gb_danke)
```

### `forceupdate`

Mit `id`: Statt bei fremder/unbekannter Id einen neuen Datensatz anzulegen, Meldung `notfound` ausgeben:

```cms
(CMS:USER_INPUT container=comments id="$id" forceupdate=true submit="$submit")
```

### `masterid`

Elternknoten in einem Bereich mit Baumstruktur (wird zu `parent_id`):

```cms
(CMS:USER_INPUT container=forum masterid="{$cms['record_id']}" submit="$submit")     Antwort unter dem aktuellen Beitrag
```

### `currentfield`

Anderer Name für das Feld mit dem bisherigen Passwort (siehe
[Passwort ändern](#passwort-ändern)):

```cms
(CMS:USER_INPUT container=members id="{$set['user_id']}" fields=password currentfield=old_pw …)
<input type="password" name="data[old_pw]">
```

### `removefiles`

Upload-Felder leeren - z. B. über eine Checkbox „Foto entfernen“:

```cms
<label><input type="checkbox" name="remove_photo" value="photo"> Foto entfernen</label>
(CMS:USER_INPUT container=comments id="$id" removefiles="{$_POST['remove_photo']}" submit="$submit")
```

---

[← Übersicht](README.md)
