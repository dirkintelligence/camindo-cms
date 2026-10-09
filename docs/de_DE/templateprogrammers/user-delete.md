# CMS:USER_DELETE

Löscht einen Datensatz, der dem angemeldeten Frontend-User gehört.

```cmscode
(CMS:USER_DELETE id [parameter=wert …])
```

***Methode:*** `Modules\Record\RecordUser::cmsDelete()`  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `id`  
***PHP-Schnipsel:*** `include_and_stop` (danach)

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `id` ¹ | `Datensatz-ID` | – | ja | Datensatz, der gelöscht werden soll. |
| `ignoreuser` | `true / false` | `false` |  | true: Der zu löschende Datensatz muss nicht dem angemeldeten Frontend-User gehören (Achtung: Sicherheitshinweise beachten!). |
| `successtemplate` | `Templatename` | – | ja | Template, das eingebunden wird, wenn der Eintrag erfolgreich gelöscht wurde. |
| `message_error` | `Text` | `Zugriff verweigert!` |  | Fehlermeldung, falls der Benutzer keinen Zugriff auf den Datensatz hat. |
| `csrf` | `true / false` | `true` |  | true: Der Request muss ein gültiges Token aus [CMS:USER_TOKEN](user-token.md) enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab – nur mit gutem Grund. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.

## Anwendung

Löscht einen Datensatz, der dem angemeldeten Frontend-User gehört (`record.user_id`) - oder
das eigene Profil, dann werden alle Sessions und bekannten Geräte des Users entfernt.

Was „löschen“ heißt, bestimmt die ***Lösch-Policy*** des Bereichs mit ihren Blöcken für den
Auslöser „Datensatz wird gelöscht“ – derselbe wie beim Löschen im Backend: in den
Papierkorb, endgültig, einzelne Felder überschreiben (etwa Status auf „deaktiviert“ und
Anmeldename `user_{record_id}`), auch zeitversetzt. Löscht der User sein Profil, werden
zugleich alle seine Beiträge nach deren Policy gelöscht (Auslöser „UGC: Profil wurde
gelöscht“). Hat die Policy keinen Block für diesen Auslöser, gibt der Befehl eine
Fehlermeldung aus. Siehe [Lösch-Policies](../admins/15-datenschutz.md#löschen-nach-lösch-policy).

Ein schon zum Löschen vorgemerkter Datensatz kann nicht erneut gelöscht werden.

```cms
<?php $id = (int)($_POST['id'] ?? 0); if ($id > 0) { ?>
(CMS:USER_DELETE id="$id" successtemplate=u_deleted message_error="Dieser Eintrag gehört Ihnen nicht.")
<?php } ?>
(CMS:MESSAGE)
<form method="post">
  (CMS:USER_TOKEN)
  <input type="hidden" name="id" value="<?php echo (int)$_GET['id']; ?>">
  <button>Eintrag endgültig löschen</button>
</form>
```

Der Befehl kennt kein `submit`: Er läuft bei jedem Aufruf und meldet ohne gültige `id`
einen Fehler - deshalb die PHP-Bedingung um den Befehl (CMS-Befehle werden zu
PHP-Code, ein umgebendes `if` ist erlaubt). Nach Erfolg wird `successtemplate`
eingebunden und die Seite beendet (außer mit `CMS:CONTINUE`).

## Parameter im Einzelnen

### `id`

Datensatz-Id; darf ohne Schlüssel stehen. Ohne gültige Id erscheint die Meldung aus
`message_error`:

```cms
(CMS:USER_DELETE "{$_POST['id']}" successtemplate=u_deleted)
(CMS:USER_DELETE id="{$set['user_id']}" successtemplate=u_account_closed)     eigenes Profil löschen
```

### `ignoreuser`

Besitzprüfung und Anmeldung übergehen - das Template trägt dann die Verantwortung (z. B. Löschen per geheimem Link):

```cms
(CMS:USER_DELETE id="{$id}" ignoreuser=true successtemplate=u_deleted)
```

### `successtemplate`

```cms
(CMS:USER_DELETE id="$id" successtemplate=u_deleted)
```

`u_deleted` kann z. B. „Eintrag gelöscht“ ausgeben und per `CMS:INCLUDE` die Navigation zeigen.

### `message_error`

Text, wenn der Datensatz nicht existiert, nicht dem Frontend-User gehört oder niemand angemeldet ist (Ausgabe über `CMS:MESSAGE`). Fehlt das Token aus `CMS:USER_TOKEN`, erscheint stattdessen die Standardmeldung für ein abgelaufenes Formular:

```cms
(CMS:USER_DELETE id="$id" successtemplate=u_deleted message_error="Löschen nicht möglich.")
```

---

[← Übersicht](README.md)
