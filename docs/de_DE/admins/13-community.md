# 13 Community-Funktionen

Mit den Community-Funktionen können sich Frontend-User registrieren, anmelden,
ihr Profil pflegen und selbst Inhalte anlegen – Kommentare, Bewertungen, Anzeigen,
Bilder. Geschützte Bereiche und Dateien stehen nur angemeldeten Frontend-Usern offen,
ein Warenkorb merkt sich ausgewählte Artikel.

Registrierte Besucher heißen in camindo CMS ***Frontend-User*** – im Unterschied zu den
***Benutzern*** im Backend. Ein Frontend-User hat kein Benutzerkonto im Backend: Sein
digitales Abbild im CMS ist ein ***Profil-Datensatz***, ein gewöhnlicher Datensatz wie
seine Beiträge auch. Dadurch gelten für sie alle
Werkzeuge der Redaktion – Suche, Stapelverarbeitung, Export, Rechte.

## Profil-Bereiche

Ein Bereich vom Typ ***PROFILE*** enthält einen Datensatz je Frontend-User. Der
Bauplan „Mitglieder“ legt einen solchen Bereich mit den Feldern Name, E-Mail und
Passwort an. Weitere Profilfelder – Avatar, Adresse, Interessen – fügen Sie wie
gewohnt hinzu.

Unter ***„Anmeldung an der Website“*** legen Sie fest:

| Einstellung | Bedeutung |
|---|---|
| Name | das Feld mit dem Anmeldenamen, Typ „Kurzer Text“ (auch verschlüsselt), z. B. die E-Mail-Adresse |
| Passwort | das Feld vom Typ „Passwort“, gegen das die Anmeldung prüft |
| Zweiter Faktor | ob die Anmeldung zusätzlich einen Code braucht – siehe [unten](#zweiter-faktor) |

Beide Felder sind erst wählbar, nachdem sie angelegt wurden.

Weisen Sie dem Passwort-Feld die Extension „Passwort-Richtlinie“ zu (Abschnitt
„Erweiterung“ der Felddefinition). Erst dann weist camindo CMS zu schwache Passwörter
bei Registrierung und Passwortwechsel zurück. Die Regeln sind die aus
[Kapitel 12](./12-betrieb.md#passwörter-und-anmeldegeräte), einzelne Werte können
Sie am Feld abweichend einstellen. Der Template-Programmierer zeigt dieselben Regeln
im Formular an ([CMS:USER_PASSWORD_RULES](../templateprogrammers/user-password-rules.md)).

Ohne die Extension lässt sich die Passwortstärke nur über Mindestlänge (Minimum) und
Muster (RegEx) in den Einstellungen des Eingabefeldes steuern.

## Beitrags-Bereiche

Ein Bereich vom Typ ***UGC*** („user-generated content“) nimmt Beiträge angemeldeter
Frontend-User auf. Der Bauplan „Kommentare“ legt einen solchen Bereich mit Name und
Kommentartext an. Unter ***„Authentifizierung“*** wählen Sie die ***Profil-Container***,
deren Frontend-User hier Beiträge anlegen dürfen. Jeder Beitrag ist mit dem Profil seines
Verfassers verbunden. Ändern und Löschen kann ein Frontend-User nur seine eigenen Beiträge.

## Die Templates

Registrierung, Anmeldung, Passwort-Reset und das Speichern von Beiträgen übernehmen
Templates mit den Befehlen `CMS:USER_*` – siehe
[Handbuch für Template-Programmierer](../templateprogrammers/README.md#community).

Der Ablauf einer Registrierung:

1. Der Besucher füllt das Registrierungsformular aus. camindo CMS legt sein Profil
   mit dem Status ***„Unbestätigt“*** an und erzeugt einen Einmal-Code; das Template
   verschickt ihn als Bestätigungslink per E-Mail (Befehl
   [CMS:EMAIL](../templateprogrammers/email.md)).
2. Mit dem Klick auf den Link wird das Profil freigeschaltet; der Frontend-User kann sich
   anmelden.
3. Vergisst er sein Passwort, fordert er auf dieselbe Weise einen Link zum
   Zurücksetzen an.

Die Links sind zeitlich begrenzt gültig (Vorgabe: 24 Stunden für die Bestätigung,
eine Stunde für das Zurücksetzen des Passworts) und nur einmal verwendbar.

### Anmeldegeräte und Passwörter

- ***Neues Passwort nur mit dem bisherigen.*** Im Kundenkonto ändert ein Frontend-User sein
  Passwort nur, wenn er das bisherige eingibt. Wer an eine offene Sitzung gelangt,
  kann das Konto so nicht übernehmen. Ausnahme ist der Link aus „Passwort vergessen“:
  er gilt als Nachweis, das bisherige Passwort entfällt.
- ***„Passwort vergessen“ funktioniert auf jedem Gerät*** – üblich und sinnvoll, denn
  man vergisst sein Passwort oft gerade am neuen Rechner oder Telefon. Die Sicherheit
  liegt im Link: er geht nur an die hinterlegte E-Mail-Adresse, gilt eine Stunde und
  nur einmal.
- ***Nach einem neuen Passwort*** werden alle anderen Anmeldungen des Users beendet.
  Soll der User über die Passwort-Änderung informiert werden (falls er es nicht selbst
  war), muss sich das Template, das nach dem Speichern des Profils angezeigt wird, um
  die Benachrichtigung kümmern, beispielsweise mittels CMS:EMAIL.
- ***Anmeldung von einem neuen Gerät:*** Die Website kann dem Frontend-User einen Hinweis
  schicken, wenn sich sein Konto von einem unbekannten Browser aus anmeldet. Die
  E-Mail verschickt das Template; alternativ ein eigener Benachrichtigungsweg, etwa
  per SMS ([Kapitel 12](./12-betrieb.md#passwörter-und-anmeldegeräte)).

### Zweiter Faktor

Ein Kundenkonto soll nicht allein am Passwort hängen. Mit der Einstellung ***„Zweiter Faktor“***
im Profil-Bereich braucht die Anmeldung zusätzlich einen sechsstelligen Code:

| Einstellung | Wirkung |
|---|---|
| Aus | wie bisher, nur das Passwort |
| Freiwillig | Frontend-User können im Kundenkonto selbst eine Authenticator-App oder den Code per E-Mail einschalten |
| Code bei neuem Gerät | wie „Freiwillig“. Zusätzlich schickt die Website einen Code per E-Mail, wenn sich jemand von einem ***unbekannten Gerät*** (Browser) anmeldet. Auf den gewohnten Geräten bleibt es beim Passwort. Empfohlen für Shops |
| Immer | jede Anmeldung braucht einen Code: per E-Mail oder – wenn eingerichtet – aus der App |

Der Frontend-User richtet eine ***Authenticator-App*** (Google Authenticator, Microsoft
Authenticator, 1Password …) im Kundenkonto ein, indem er einen QR-Code scannt, den
angezeigten Schlüssel abtippt oder auf dem Telefon einen Link öffnet. Er bekommt zehn
***Wiederherstellungscodes*** für den Fall, dass er sein Telefon verliert. Hat er auch
diese nicht mehr, setzen Sie den zweiten Faktor im Backend zurück: Profil-Datensatz
öffnen, Menü rechts ***„Zweiter Faktor“*** → „Zweiten Faktor zurücksetzen“ (Schreibrecht
auf den Bereich nötig; der Vorgang steht im Audit-Log). Prüfen Sie vorher, ob die
Anfrage wirklich vom Inhaber kommt.

Ist eine App eingerichtet, verlangt auch der Link aus „Passwort vergessen“ den Code –
sonst genügte der Zugriff auf das E-Mail-Postfach, um das Konto zu übernehmen.

Den Code per E-Mail verschickt – wie alle E-Mails der Community-Funktionen – das
Template. Soll er per ***SMS*** gehen, ruft der Template-Programmierer dort statt der
E-Mail einen SMS-Dienst auf.

Die Templates dafür ([CMS:USER_LOGIN_CODE](../templateprogrammers/user-login-code.md),
[CMS:USER_2FA](../templateprogrammers/user-2fa.md)) müssen vorhanden sein, bevor Sie die
Einstellung einschalten – sonst scheitert jede Anmeldung, die einen Code bräuchte.

### Schutz der Formulare

„Passwort vergessen“ und die Registrierung schicken E-Mails an Adressen, die jemand
eingetippt hat. Damit niemand über Ihre Website fremde Postfächer flutet, begrenzt
camindo CMS die Anfragen (***Drosselung***) – je eingegebener Adresse, je Besucher
(IP-Adresse) und je Website. Dazu kommt ein ***Formularschutz*** gegen Robots mit einer
Sperre je IP-Adresse gegen das massenhafte Durchprobieren von Passwörtern.
Die Grenzen stehen in der Konfiguration (unten). Die Vorgaben passen für die meisten Websites.

***Nie bestätigte Registrierungen*** werden nach sieben Tagen gelöscht – wer die Adresse
eines anderen einträgt, blockiert sie so nur kurz.

> Läuft die Website hinter einem ***Proxy oder Load-Balancer***, tragen Sie ihn unter
> `security.trusted_proxies` ein ([Kapitel 12](./12-betrieb.md#konfiguration)) – sonst
> sieht camindo CMS alle Besucher mit der Adresse des Proxys und sperrt bei der
> Drosselung alle zugleich.

## Prüfen und Owner

Alles, was ein Frontend-User anlegt oder ändert, wird mit ***„Prüfung nötig“*** markiert und
erscheint im Widget „Ungeprüfte Inhalte von Frontend-Usern“ der Redaktion (siehe
[Handbuch für Anwender](../editors/10-community.md)). Legen Sie das Metafeld „Prüfung
nötig“ in den Profil- und Beitrags-Bereichen an, damit die Redaktion die Markierung
nach der Prüfung entfernen kann.

Jeder Datensatz braucht einen ***Owner*** im Backend (siehe
[Kapitel 7](./07-benutzer-und-rechte.md#das-owner-prinzip)). Für Datensätze von Frontend-Usern
nennt ihn das Template (Parameter `owner`) oder die Konfiguration
(`user.default_owner_id`). Fehlt beides, kann der Befehl nicht speichern. Über die Benutzergruppe
dieses Owners steuern Sie, welche Redaktion für die Inhalte der Frontend-User zuständig ist.

## Geschützte Dateien

Upload- und Thumbnail-Felder haben einen Abschnitt ***„Authentifizierung“***. Mit
***„Nur für angemeldete Frontend-User“*** und der Auswahl der berechtigten Profil-Container
wird das Verzeichnis des Feldes auf dem Server gesperrt. Die Dateien werden dann nur
an angemeldete Frontend-User aus diesen Profil-Bereichen ausgeliefert – etwa Mitgliederbilder,
Unterlagen für Kursteilnehmer oder Downloads für Kunden. Alle anderen erhalten eine
„nicht gefunden“-Antwort.

## Datenexport und Löschen

Verlangt ein Website-User Auskunft über seine gespeicherten Daten (Art. 15 DSGVO)
oder eine Kopie zur Mitnahme (Art. 20 DSGVO), erstellen Sie einen
***Datenexport personenbezogener Daten***: am Profil-Datensatz im Menü rechts
![more_vert](../_/more_vert.svg) oder im Modul „Datenschutz“ (siehe
[Kapitel 15](./15-datenschutz.md#datenexport-personenbezogener-daten)). Der Export ist
ein Teilauszug, den Sie vor der Weitergabe ergänzen.  
Website-User können ihre Daten nicht selbst herunterladen.

***Löschen (Art. 17 DSGVO):*** Was beim Löschen eines Profils und seiner Beiträge
geschieht, legt die ***Lösch-Policy*** des Bereichs fest – in den Papierkorb legen,
endgültig löschen, einzelne Felder überschreiben, auch zeitversetzt
(siehe [Kapitel 15](./15-datenschutz.md#löschen-nach-lösch-policy)). Die _Beiträge_ des
Users werden dabei immer nach der Policy ihres eigenen Bereichs gelöscht (Auslöser
„UGC: Profil wurde gelöscht“). Der
Löschdialog zeigt, was die Policy mit dem Datensatz macht. Muss der Papierkorb eines
Users sofort geleert werden: „Werkzeuge“ → „Papierkorb eines Users“
([Kapitel 15](./15-datenschutz.md#papierkorb-eines-website-users-sofort-leeren)).
Müssen alle Daten inkl. Änderungshistorie (Audit-Log) entfernt werden:
[`camindo.php audit delete`](./12-betrieb.md#die-kommandozeile).

Was camindo CMS insgesamt über Website-User speichert, steht in
[Kapitel 15](./15-datenschutz.md#website-user-community).

## Konfiguration

Die Community-Einstellungen stehen in `.camindo.php` unter `user` (siehe
[Kapitel 12](./12-betrieb.md#konfiguration)):

| Schlüssel | Vorgabe | Bedeutung |
|---|---|---|
| `user.max_inactive_sec` | `86400` | automatische Abmeldung eines Users nach Untätigkeit (Sekunden) |
| `user.basket_days` | `30` | Aufbewahrung des Warenkorbs nicht angemeldeter Besucher (Tage) |
| `user.token_expiration` | `86400` | Gültigkeit des Bestätigungslinks nach der Registrierung (Sekunden) |
| `user.password_token_expiration` | `3600` | Gültigkeit des Links „Passwort vergessen“ und der Frist danach, in der das neue Passwort ohne das bisherige gesetzt werden kann (Sekunden) |
| `user.bruteforce_max` / `bruteforce_delay` | `5` / `600` | erlaubte Fehlversuche bei der Anmeldung und Sperrdauer (Sekunden) |
| `user.cookie_secure` | `true` | Sitzungs-Cookie nur über HTTPS. `false` nur für einen lokalen Test ohne SSL |
| `user.default_owner_id` | `0` | Benutzer, dem Datensätze von Frontend-Usern gehören, wenn das Template keinen Owner nennt |
| `user.throttle` | siehe unten | Drosselung: je Regel `[höchstens, Sekunden]`, höchstens `0` schaltet sie ab |
| `user.formguard_min_seconds` / `formguard_max_seconds` | `3` / `3600` | Formularschutz: frühestens bzw. spätestens so viele Sekunden nach dem Aufruf darf ein geschütztes Formular abgeschickt werden. Ein Template kann die Mindestzeit je Formular abweichend setzen |
| `user.unconfirmed_days` | `7` | nie bestätigte Registrierungen nach so vielen Tagen löschen (`0` = nie) |
| `user.login_code_expiration` | `600` | Gültigkeit des Anmeldecodes (Sekunden) |

Vorgaben der Drosselung (`user.throttle`):

| Regel | Vorgabe | zählt |
|---|---|---|
| `pw_value` | 3 je Stunde | „Passwort vergessen“ je eingegebener Adresse |
| `pw_ip` | 10 je Stunde | „Passwort vergessen“ je Besucher (IP) |
| `register_ip` | 5 je Stunde | Registrierungen je Besucher |
| `register_value` | 3 je Tag | Registrierungen je E-Mail-Adresse |
| `mail_instance` | 200 je Stunde | beides zusammen je Website |
| `login_ip` | 30 je 10 Minuten | falsche Passwörter je Besucher, über alle Konten |
| `login_code` | 3 je 10 Minuten | „Neuen Code senden“ je Konto |
| `email_ip` | 5 je Stunde | Kontaktformulare mit Drosselung ([CMS:EMAIL](../templateprogrammers/email.md) `throttle=true`) |
| `csp_report` | 60 je 10 Minuten | Meldungen der Script-Policy je Besucher |
| `zip_ip` | 30 je Stunde | neu erstellte ZIP-Downloads ([CMS:ZIP](../templateprogrammers/zip.md)) je Besucher |

Eine Regel ändern Sie einzeln, z. B. `'throttle' => ['pw_ip' => [20, 3600]]`.
