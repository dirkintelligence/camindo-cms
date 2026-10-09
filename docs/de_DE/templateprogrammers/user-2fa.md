# CMS:USER_2FA

Zweiten Faktor des angemeldeten Frontend-Users anzeigen, einrichten (Authenticator-App oder Code
per E-Mail) und abschalten.

```cmscode
(CMS:USER_2FA [parameter=wert …])
```

***Methode:*** `Modules\Record\RecordUser::cmsTwoFactor()`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `function` | `status / totp_setup / totp_enable / code_enable / disable / recovery_renew` | `status` |  | Was der Befehl tut (siehe unten). |
| `submit` | `Textwert` | – |  | Schreibende Funktionen laufen nur, wenn in diesem Feld ein Text enthalten ist. |
| `data` | `PHP-Variablenname` | `data` |  | Name des PHP-Arrays mit den Eingaben. |
| `codefield` | `Feldname` | `code` |  | Eingabefeld für den App-Code in `data[…]`. |
| `currentfield` | `Feldname` | `current_password` |  | Eingabefeld für das Passwort in `data[…]`. |
| `message[…]` | `Standardmeldung` | – |  | Fehlermeldungen. Im Index: Anwendungsfall; im Wert: Meldungstext. |
| `cookiename` | `Cookiename` | `usess` |  | Name des Session-Cookies [a-z0-9]. |
| `csrf` | `true / false` | `true` |  | true: Schreibende Funktionen verlangen ein Token aus [CMS:USER_TOKEN](user-token.md). |

## Anwendung

Der Befehl gehört auf eine geschützte Seite des Kundenkontos (nach
[CMS:USER_PROTECT](user-protect.md)). Er wirkt nur, wenn der Profil-Bereich einen zweiten
Faktor zulässt (Einstellung „Zweiter Faktor“ nicht „aus“, sonst Meldung `policy`).

| `function` | Wirkung | Bestätigt mit |
|---|---|---|
| `status` | nur lesen | – |
| `totp_setup` | neuen Schlüssel für die Authenticator-App erzeugen – gespeichert wird noch nichts: `$set['totp_setup']` = `token`, `qr` (fertiges HTML mit QR-Code), `secret` (zum Abtippen, in Vierergruppen), `uri` (`otpauth://…`, öffnet auf dem Telefon direkt die App), `qr_script` (Adresse der QR-Bibliothek) | – |
| `totp_enable` | Schlüssel speichern, zehn Wiederherstellungscodes erzeugen (`$set['recovery_codes']`, nur jetzt im Klartext) | `data[token]` und dem ersten Code aus der App (`data[code]`) |
| `code_enable` | Code per E-Mail bei jeder Anmeldung | Passwort (`data[current_password]`); ist eine App eingerichtet, dem App-Code |
| `disable` | abschalten – verlangt der Bereich immer einen zweiten Faktor, wird aus der App der Code per E-Mail | App-Code oder Wiederherstellungscode bzw. Passwort |
| `recovery_renew` | neue Wiederherstellungscodes, die alten verfallen | App-Code |

Nach jeder Funktion stehen in `$set`: `second_factor` (`''`, `totp`, `email`),
`second_factor_policy` (Einstellung des Bereichs: `none`, `optional`, `new_device`,
`required`), `second_factor_since` (UTC), `recovery_count`; nach einer Änderung
`second_factor_changed` (`totp_enabled`, `totp_disabled`, `code_enabled`,
`code_disabled`, `recovery_renewed`) – etwa für eine Hinweis-E-Mail an den Frontend-User.

Zum Einrichten scannt der Frontend-User den ***QR-Code*** mit der App; er enthält den Schlüssel
(nicht die Codes – die erzeugt die App danach selbst, je sechs Ziffern). Alternativ tippt
er den Schlüssel ab oder öffnet auf dem Telefon den Link. `$set['totp_setup']['qr']`
bringt alles mit: ein Element mit dem QR-Code, die Bibliothek qrcode-generator (dieselbe
wie im Backend, aus dessen Assets) und ein kleines Script, beide mit der Nonce der Seite –
es läuft also auch unter einer durchgesetzten Script-Policy. Geben Sie es nur aus
(`echo`), die Größe steuern Sie per CSS (`.c9-qr { width: 200px }`).

```cms
<?php
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$action = $isPost ? (string)($_POST['action'] ?? '') : (isset($_GET['setup']) ? 'totp_setup' : 'status');
$submit = $isPost ? '1' : '';
?>
(CMS:USER_PROTECT errortemplate=u_login)
(CMS:USER_2FA function="$action" submit="$submit")
<p class="error">(CMS:MESSAGE)</p>
<?php if (!empty($set['recovery_codes'])) { ?>
  <p>Ihre Wiederherstellungscodes (bitte sicher aufbewahren):</p>
  <ul><?php foreach ($set['recovery_codes'] as $c) echo '<li>' . htmlspecialchars($c) . '</li>'; ?></ul>
<?php } ?>
<?php if (!empty($set['totp_setup'])) { $s = $set['totp_setup']; ?>
  <?php echo $s['qr']; ?>
  <p>Schlüssel für Ihre Authenticator-App: <code><?php echo htmlspecialchars($s['secret']); ?></code>
     – oder <a href="<?php echo htmlspecialchars($s['uri']); ?>">in der App öffnen</a>.</p>
  <form method="post">
    (CMS:USER_TOKEN)
    <input type="hidden" name="action" value="totp_enable">
    <input type="hidden" name="data[token]" value="<?php echo htmlspecialchars($s['token']); ?>">
    <input name="data[code]" autocomplete="one-time-code" required>
    <button>Einschalten</button>
  </form>
<?php } elseif ($set['second_factor'] === '') { ?>
  <p><a href="?setup=1">Authenticator-App einrichten</a></p>
<?php } ?>
```

Meldungs-Indizes: `auth` (nicht angemeldet), `policy`, `code` (App-Code falsch),
`current` (Passwort falsch), `setup` (Einrichtung abgelaufen – 15 Minuten), `blocked`
(zu viele Fehlversuche), `token`. Falsche Codes und Passwörter zählen wie bei der
Anmeldung.

Hat ein Frontend-User Telefon und Wiederherstellungscodes verloren, setzt der Betreiber den
zweiten Faktor im Backend zurück (Datensatz des Profils, Menü „Zweiter Faktor“).

## Parameter im Einzelnen

### `function`

```cms
(CMS:USER_2FA function=status)
(CMS:USER_2FA function="$action" submit="$submit")
```

### `submit`

Nur schreibende Funktionen brauchen ihn – sie gehören in ein POST-Formular:

```
<?php $submit = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? '1' : ''; ?>
```

### `data`, `codefield`, `currentfield`

```cms
(CMS:USER_2FA function=disable submit="$submit" data=sec codefield=otp currentfield=pw)
```

### `message`

```cms
(CMS:USER_2FA function="$action" submit="$submit" message[code]="Der Code stimmt nicht.")
```

---

[← Übersicht](README.md)
