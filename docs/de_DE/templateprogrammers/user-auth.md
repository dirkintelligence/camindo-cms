# CMS:USER_AUTH

Prüft eine Session-ID auf Gültigkeit.

```cmscode
(CMS:USER_AUTH [parameter=wert …])
```

***Methode:*** `Modules\Record\RecordUser::cmsAuth()`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `sessionid` | `Session-ID` | – |  | Session des Frontend-Users anhand der angegebenen sessionid wieder aufnehmen anstatt über ein Cookie. |
| `filter[…][…]` | `vgl. Parameter "filter[]" von CMS:SELECT` | – |  | Filterkriterien für den Profil-Datensatz, die beim angemeldeten Frontend-User erfüllt sein müssen. |
| `mode[…]` | `AND / OR` | `OR` |  | Modus, mit dem Werte verknüpft werden, die dasselbe Feld betreffen. |
| `cookiename` | `Cookiename` | `usess` |  | Name des Session-Cookies [a-z0-9]. |

`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Nimmt die Frontend-Session auf und stellt den angemeldeten Frontend-User bereit, **ohne** die
Seite zu sperren - für Seiten, die angemeldeten und anonymen Besuchern
unterschiedliche Inhalte zeigen. Ergebnis in `$set`:

| `$set[…]` | Inhalt |
|---|---|
| `user_id` | record_id des Profils, `0` = nicht angemeldet |
| `user` | der Profil-Datensatz als Array (`[]` wenn nicht angemeldet) |
| `user_session_id` | Session-Id, `''` = keine Session |
| `password_reset` | `1` nach einer Anmeldung über den Link aus [CMS:USER_PW](user-pw.md), solange ein neues Passwort ohne das bisherige gesetzt werden darf, sonst `0` |

```cms
(CMS:USER_AUTH)
<?php if ($set['user_id']) { ?>
  Hallo <?php echo htmlspecialchars($set['user']['nickname']); ?> – <a href="/0,u_logout">abmelden</a>
<?php } else { ?>
  <a href="/0,u_login">anmelden</a>
<?php } ?>
```

Der Befehl gehört an den ***Anfang*** des Templates (vor jeder Ausgabe), damit das
Session-Cookie gesetzt werden kann. Für Seiten, die nur angemeldete Frontend-User sehen dürfen,
ist `CMS:USER_PROTECT` der passende Befehl.

## Parameter im Einzelnen

### `sessionid`

Session per Parameter statt Cookie aufnehmen - für Aufrufe aus Apps oder von anderen Domains:

```cms
(CMS:USER_AUTH sessionid="{$_GET['sid']}")
```

### `filter`

Zusätzliche Bedingung an das Profil, Syntax wie `CMS:SELECT`. Trifft sie nicht zu, gilt der Frontend-User als nicht angemeldet:

```cms
(CMS:USER_AUTH filter[role]=editor)
(CMS:USER_AUTH filter[status_id]=0 filter[newsletter]=1)
```

### `mode`

Verknüpfung mehrerer Werte desselben Feldes in `filter` (Standard OR):

```cms
(CMS:USER_AUTH filter[role]=editor filter[role]=admin mode[role]=OR)
```

### `cookiename`

Name des Session-Cookies (Standard `usess`), etwa für zwei getrennte Anmeldungen auf einer Domain:

```cms
(CMS:USER_AUTH cookiename=shop)
```
Muss bei allen `USER_*`-Befehlen derselbe sein.

---

[← Übersicht](README.md)
