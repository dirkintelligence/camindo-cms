# CMS:USER_LOGOUT

Beendet und löscht die Session eines angemeldeten Frontend-Users.

```cmscode
(CMS:USER_LOGOUT [parameter=wert …])
```

***Methode:*** `Modules\Record\RecordUser::cmsLogout()`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `submit` | `Textwert` | – |  | Ist der Parameter angegeben, meldet der Befehl nur ab, wenn er einen Text enthält (z. B. submit="$logout" aus dem Abmelde-Formular) – leer passiert nichts, auch keine Meldung `token`. Ohne den Parameter meldet der Befehl bei jedem Aufruf ab (gültiges Token vorausgesetzt). |
| `deletecookie` | `true / false` | `true` |  | false: Nur die serverseitige Session wird gelöscht. true: Auch der Browser-Cookie wird gelöscht. |
| `cookiename` | `Cookiename` | `usess` |  | Name des Session-Cookies [a-z0-9]. |
| `csrf` | `true / false` | `true` |  | true: Der Request muss ein gültiges Token aus [CMS:USER_TOKEN](user-token.md) enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab – nur mit gutem Grund. |

## Anwendung

Beendet die Session des angemeldeten Users (auch den Warenkorb der Session) und löscht
das Cookie. Danach ist `$set['user_id']` = 0:

```cms
(CMS:USER_LOGOUT)
(CMS:INCLUDE header)
<p>Sie wurden abgemeldet. <a href="/0,u_login">Erneut anmelden</a></p>
```

Die Abmeldung wird per POST-Formular mit Token ausgelöst – ein einfacher Link genügt
nicht, sonst könnte jede fremde Seite Ihre Besucher abmelden. Ohne gültiges Token meldet
der Befehl nicht ab und legt die Meldung `token` ab ([CMS:MESSAGE](message.md)):

```cms
<form method="post" action="/0,u_logout">
  (CMS:USER_TOKEN)
  <button name="logout" value="1">Abmelden</button>
</form>
```

Damit der direkte Aufruf der Abmeldeseite keine Meldung `token` erzeugt, geben Sie
`submit` an – leer (die Seite wurde nur aufgerufen) tut der Befehl dann nichts:

```cms
<?php $logout = $_POST['logout'] ?? ''; ?>
(CMS:USER_LOGOUT submit="$logout")
```

Auch dieser Befehl gehört vor die erste Ausgabe, damit das Cookie gelöscht werden kann.

## Parameter im Einzelnen

### `submit`

Nur abmelden, wenn das Abmelde-Formular abgeschickt wurde:

```cms
<?php $logout = $_POST['logout'] ?? ''; ?>
(CMS:USER_LOGOUT submit="$logout")
```

### `deletecookie`

Nur die serverseitige Session löschen, das Cookie stehen lassen:

```cms
(CMS:USER_LOGOUT deletecookie=false)
```

### `cookiename`

```cms
(CMS:USER_LOGOUT cookiename=shop)
```

---

[← Übersicht](README.md)
