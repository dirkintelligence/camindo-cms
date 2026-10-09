# CMS:USER_PROTECT

Schützt ein Template vor dem Zugriff nicht angemeldeter Website-Besucher.

```cmscode
(CMS:USER_PROTECT [parameter=wert …])
```

***Methode:*** `Modules\Record\RecordUser::cmsProtect()`  
***PHP-Schnipsel:*** `include_and_stop` (danach)

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `errortemplate` | `Templatename` | – | ja | Detailtemplate, das eingebunden wird, falls der Frontend-User keinen Zugriff auf den Container hat oder nicht angemeldet ist. |
| `filter[…][…]` | `vgl. Parameter "filter[]" von CMS:SELECT` | – |  | Filterkriterien für den Profil-Datensatz, die beim angemeldeten Frontend-User erfüllt sein müssen. |
| `sessionid` | `Session-ID` | – |  | Session des Frontend-Users anhand der angegebenen sessionid wieder aufnehmen anstatt über ein Cookie. |
| `cookiename` | `Cookiename` | `usess` |  | Name des Session-Cookies [a-z0-9]. |

`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Sperrt ein Template für nicht angemeldete Besucher: Ist niemand angemeldet (oder trifft
`filter` nicht zu), wird `errortemplate` eingebunden und die Seite beendet. Sonst läuft
das Template weiter, `$set['user_id']` und `$set['user']` sind gefüllt:

```cms
(CMS:USER_PROTECT errortemplate=u_login)
(CMS:INCLUDE header)
<h1>Mein Konto</h1>
<p>Angemeldet als <?php echo htmlspecialchars($set['user']['nickname']); ?></p>
```

Nur für eine Gruppe von Frontend-Usern (Profil-Feld `role`):

```cms
(CMS:USER_PROTECT errortemplate=u_denied filter[role]=editor)
```

Das `errortemplate` ist typischerweise das Anmeldeformular selbst - so landet der
Besucher nach der Anmeldung wieder auf der geschützten Seite.

## Parameter im Einzelnen

### `errortemplate`

Template für nicht angemeldete Besucher (oder wenn `filter` nicht zutrifft):

```cms
(CMS:USER_PROTECT errortemplate=u_login)
(CMS:USER_PROTECT errortemplate=templates.u_denied filter[role]=editor)
```

### `filter`

Zusätzliche Bedingung an das Profil, Syntax wie `CMS:SELECT`:

```cms
(CMS:USER_PROTECT errortemplate=u_denied filter[role]=editor)
(CMS:USER_PROTECT errortemplate=u_denied filter[paid_until] >= "{$today}")
```

### `sessionid`

```cms
(CMS:USER_PROTECT errortemplate=api_denied sessionid="{$_GET['sid']}")
```

### `cookiename`

```cms
(CMS:USER_PROTECT errortemplate=u_login cookiename=shop)
```

---

[← Übersicht](README.md)
