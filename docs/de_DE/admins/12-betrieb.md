# 12 Betrieb und Wartung

## Cronjobs

camindo CMS braucht ***einen*** Cronjob des Webserver-Benutzers (Einrichtung siehe
[Installation, Schritt 5](./01-quickstart-installation.md#schritt-5--cronjobs)):

| Cronjob | Takt | Aufgaben |
|---|---|---|
| `Core/cron/cron.php` | jede Minute | Hintergrundumwandlung von Videos und Bildern, Abgleich der Template-Dateien und die fälligen Aufgaben des [Zeitplaners](#scheduler) – darunter alle Wartungsaufgaben |

Läuft `cron.php` nicht, liegen zeitgesteuerte Aufgaben still, Template-Dateien, die im Dateisystem
geändert wurden, kommen nicht im Backend an, Videos bleiben unumgewandelt. Superuser sehen das auf
der Startseite: Unter den Modulkacheln erscheint dann ein Hinweis
(siehe [Betriebshinweise](#betriebshinweise-auf-der-startseite)).

## Scheduler

Der ***Zeitplaner*** (Scheduler) erledigt wiederkehrende und aufwendige Arbeiten im
Hintergrund, ohne die Redaktion aufzuhalten. Er hängt am minütlichen Cronjob
`cron.php`: Jede Minute sucht dieser die fälligen Aufgaben und startet jede als eigenen
Hintergrundprozess – eine lange Aufgabe hält die anderen nicht auf. Eingerichtet
werden die Aufgaben im Modul „Zeitplaner“. Welche Arten es gibt und wie die
Zeitsteuerung geschrieben wird, beschreibt [Kapitel 10](./10-module.md#zeitplaner).

***Wartungsaufgaben:*** Die Installation legt diese Aufgaben an (Benutzer
`scheduler`, mit Protokoll). Die Ausführung kann – wie bei jeder anderen Aufgabe – terminiert,
ausgeschaltet oder gelöscht werden. Fehlt eine der Wartungsaufgaben oder ist sie
ausgeschaltet, erscheint ein [Betriebshinweis](#betriebshinweise-auf-der-startseite).

| Aufgabe | Vorgabe | Was sie tut |
|---|---|---|
| ***Datenbank-Backup*** | täglich 2:00 | Sicherung nach `var/dbbackup/`, 7 rotierende. Empfehlung: zusätzlich außerhalb des Servers ablegen (siehe [Datensicherung](#datensicherung)) |
| ***Temporäre Dateien löschen*** | stündlich :10 | `var/tmp/` und abgelaufene Exportdateien (auch Datenauskünfte) aufräumen |
| ***Papierkorb leeren*** | täglich 2:20 | Einträge endgültig löschen, die länger als `admin.trash_retention_days` im Papierkorb liegen |
| ***Lösch-Policies anwenden*** | täglich 2:30 | fällige Aufträge der [Lösch-Policies](./15-datenschutz.md#löschen-nach-lösch-policy) |
| ***Aufbewahrungsfristen anwenden*** | täglich 2:45 | IP-Adressen kürzen, abgelaufene Einträge des Audit-Logs, alte interne Nachrichten und nie bestätigte Registrierungen löschen ([Kapitel 15](./15-datenschutz.md)), dazu die Fristen installierter Zusatzmodule – etwa gelöschte Chats des KI-Assistenten |
| ***Logs aufräumen*** | täglich 3:00 | alte Logdateien und Anmeldeversuche löschen (siehe [Protokolle](#protokolle)) |
| ***Frontend-Cache aufräumen*** | täglich 3:10 | abgelaufene Dateien des Ausgabe-Caches. Nutzen Templates `caching` mit kurzen Fristen, sollte diese Aufgabe stündlich eingerichtet werden. |
| ***Volltext neu aufbauen*** | täglich 3:20, nur veraltete Bereiche | baut die Bereiche neu auf, deren Volltext nach einer Strukturänderung als nicht mehr aktuell markiert ist. Nutzen Volltextfelder Quellen hinter einer „Verknüpfung“, ist für diese Bereiche eine zusätzliche Aufgabe sinnvoll. Ohne diese Option reicht wöchentlich oder manuell (siehe [Volltext neu aufbauen](./05-eingabefelder.md#volltext-neu-aufbauen)) |
| ***Audit-Log prüfen*** | täglich 3:40 | Dateien des Audit-Logs auf Manipulation prüfen. Befunde zeigt die Startseite |
| ***Nach Updates suchen*** | täglich 3:55 | neuere Versionen und bekannte Sicherheitslücken (siehe [Updates](#updates)) |
| ***Nachrichten per E-Mail zusammenfassen*** | täglich 7:00 | E-Mail an die Benutzer, die die Zusammenfassung ihrer neuen internen Nachrichten eingeschaltet haben (siehe [Nachrichten](./10-module.md#nachrichten)) |

Fehlende Wartungsaufgaben – etwa nach versehentlichem Löschen – legt der Befehl
`php Core/cron/camindo.php scheduler defaults` wieder an, vorhandene bleiben unverändert.
`scheduler list` zeigt alle Aufgaben, `scheduler run trash` (oder eine Id) führt eine
sofort aus.

***Terminieren oder sofort ausführen:*** Eine Aufgabe läuft zu den Zeitpunkten ihrer
Zeitsteuerung, solange sie aktiv ist. „Jetzt ausführen“ in ihrer Detailansicht
startet sie sofort als Hintergrundprozess – auch eine inaktive. So richten Sie eine
Aufgabe, die nur gelegentlich gebraucht wird (etwa „Volltext neu aufbauen“), einmal
ein, lassen sie inaktiv und starten sie bei Bedarf von Hand.

***Vorübergehend aussetzen:*** „Deaktivieren“ im Kontextmenü der Liste oder in der
Detailansicht setzt eine Aufgabe aus, ohne ihre Einstellungen anzutasten – die Liste
zeigt sie als „vorübergehend ausgesetzt“, ein nächster Lauf steht dann nicht an.
„Reaktivieren“ lässt sie wieder nach ihrer Zeitsteuerung laufen.

***Berichte:*** Eine Aufgabe vom Typ „URL-Aufruf“ oder „PHP-Aufruf“ lässt sich als
***wählbarer Bericht*** markieren; dazu gehört mindestens eine Benutzergruppe. Benutzer
dieser Gruppen finden den Bericht in ihren persönlichen Einstellungen unter „Bericht
abonnieren“. Nach jedem erfolgreichen Lauf erhalten die Abonnenten die Ausgabe als
interne Nachricht im HTML-Format (aktive Inhalte wie Scripts werden entfernt, bei
einem ganzen HTML-Dokument nur der Inhalt von `<body>`, Ausgaben über 2 MB nur als
Hinweis). Die Detailansicht der Aufgabe zeigt die Zahl der Abonnenten.

***Ergebnis und Fehler:*** Liste und Detailansicht zeigen nächsten und letzten Lauf mit
Ergebnis und Meldung. Fehler landen immer im Protokoll `var/log/{Datum}_scheduler.log`,
erfolgreiche Läufe nur mit der Einstellung „System-Logfile schreiben“. Fehlgeschlagene Aufgaben
meldet die Startseite den Superusern (siehe [Betriebshinweise](#betriebshinweise-auf-der-startseite)).

***Maximale Ausführungszeit:*** Jede Aufgabe hat eine Obergrenze in Minuten. Wird sie
überschritten, bricht der Lauf ab und gilt als fehlgeschlagen. Eine Aufgabe, die
länger als diese Grenze plus fünf Minuten als „läuft“ markiert ist, gilt als
abgebrochen und startet beim nächsten Termin neu.

***Rechte:*** Jede Aufgabe läuft unter einem Benutzer und mit dessen Rechten – neue
unter dem Benutzer `scheduler`, den die Installation anlegt. Näheres in
[Kapitel 10](./10-module.md#zeitplaner).

## Papierkorb

Gelöschte Datensätze und Bereiche werden zunächst nur als gelöscht markiert; ihre
Dateien wandern nach `var/deleted/`. Ob ein Datensatz überhaupt in den Papierkorb
kommt, bestimmt die [Lösch-Policy](./15-datenschutz.md#löschen-nach-lösch-policy)
seines Bereichs. Endgültig gelöscht wird nach der
***Aufbewahrungsfrist*** (`admin.trash_retention_days`, Vorgabe 7 Tage) durch die
Zeitplaner-Aufgabe „Papierkorb leeren“ (jede Nacht) – oder sofort über „Werkzeuge“ → „Datenbank“ → „Papierkorb leeren“.
Gelöschte Benutzer bleiben immer erhalten.

Innerhalb der Frist wird wiederhergestellt – samt untergeordneten Datensätzen, fest
verbundenen Inhalten und Dateien:

- ***Datensätze*** holt jeder Benutzer selbst aus seinem
  [Papierkorb](./10-module.md#papierkorb) zurück.
- ***Bereiche*** stellt ein Superuser im Papierkorb wieder her. Mit dem Bereich kommen
  seine Unterbereiche und alle Datensätze zurück, die mit ihm gelöscht wurden.

Ohne Backend geht es auch auf der Kommandozeile:

```bash
php Core/cron/mass_delete.php --table=record --id=1758 --mode=restore --withchilds=1 --admin=1
```

`--id` ist die Nummer des Datensatzes, `--admin` die Nummer eines Benutzers mit
den nötigen Rechten (etwa eines Superusers), unter dessen Namen die Wiederherstellung
läuft. `--withchilds=1` holt die untergeordneten Datensätze zurück, die mit demselben
Vorgang gelöscht wurden. Für einen gelöschten Bereich samt
Datensätzen verwenden Sie `--table=area`.

## Arbeitskopien

Bricht ein Redakteur die Bearbeitung ab, ohne „Abbrechen“ zu klicken – etwa weil er
den Browser schließt –, bleibt eine ***Arbeitskopie*** zurück. Sie sperrt den Datensatz
nur, bis sie 90 Minuten unverändert war (`admin.edit_lock_sec`); danach kann ein anderer den Datensatz
wieder öffnen. Liegen gebliebene Arbeitskopien entfernt „Werkzeuge“ → „Datenbank“ →
„Arbeitskopien verwerfen“ (Vorgabe: älter als 24 Stunden).

## Datensicherung

Zu einer vollständigen Sicherung gehören:

| Was | Wo |
|---|---|
| Datenbank | per [Zeitplaner](./10-module.md#zeitplaner) (Aufgabe „Datenbank-Backup“) nach `var/dbbackup/` oder mit eigenen Werkzeugen |
| Konfiguration | `.camindo.php` |
| ***Schlüsseldatei*** | `.camindo.key` (oder der Inhalt der Umgebungsvariablen `CAMINDO_KEY`, siehe [Zugangsdaten aus Umgebungsvariablen](#zugangsdaten-aus-umgebungsvariablen)) – ohne sie sind verschlüsselte Inhalte und Passwörter unbrauchbar. An einem sicheren Ort **getrennt** von den Datenbanksicherungen aufbewahren |
| Mediendateien | `public/media/` |
| Projektdaten | `var/templates/` (Template-Quelltexte),<br>`var/custom/` (Snippets, Scripte und Ausgaben des Zeitplaners),<br>`var/extensions/` (Stylesheets),<br>`var/modules/` (z. B. PDF-Vorlagen und Schriften),<br>`var/area_blueprints/` (eigene Baupläne),<br>`var/deleted/` (Papierkorb),<br>`var/translation/translated` sofern eine eigene Backend-Übersetzung angelegt wurde. |
| Composer-Stand | `composer.json` und `composer.lock` – damit sich genau dieselben Versionen wieder installieren lassen |
| Änderungsprotokolle | `var/audit/` – die Inhalte des Audit-Logs; nach dem Einspielen einer Datenbanksicherung gleicht `audit restore` sie mit der Datenbank ab |


Vor jedem Update und vor Änderungen an Komponenten legt camindo CMS automatisch eine
Datenbanksicherung in `var/dbbackup/` an. Manuell: `php Core/cron/camindo.php backup`;
alte Sicherungen räumt `php Core/cron/camindo.php backup prune --keep=5` ab.

Einzelne Bereiche lassen sich zusätzlich per [XML-Export](./09-struktur-export-import.md)
sichern und gezielt wiederherstellen.

## Updates

```bash
cd /var/www/mysite
php Core/cron/camindo.php maintenance on
composer update
php Core/cron/camindo.php upgrade
php Core/cron/camindo.php maintenance off
```

Composer tauscht die Dateien von Core und Zusatzkomponenten aus; `upgrade` sichert
die Datenbank, zieht sie auf den neuen Stand und baut alle Caches neu.

***Wartungsmodus:*** Während `maintenance on` gilt, antwortet die Website mit einer
Wartungsseite (HTTP 503), Hintergrundprozesse pausieren, und das Backend ist nur für
Superuser erreichbar. Eine eigene Wartungsseite legen Sie als
`var/custom/maintenance.html` ab. Passen Dateien und Datenbank nach einem
`composer update` nicht zusammen, schaltet camindo CMS von selbst in den
Wartungsmodus, bis `upgrade` gelaufen ist.

***Zurück auf den vorherigen Stand:*** Wartungsmodus an, Datenbanksicherung aus
`var/dbbackup/` einspielen, die vorherige `composer.lock` wiederherstellen und
`composer install` ausführen, dann `upgrade` und Wartungsmodus aus.

### Update-Prüfung

Jede Nacht prüft die Zeitplaner-Aufgabe „Nach Updates suchen“ mit Composer, ob es neuere Versionen von camindo CMS
und der installierten Pakete gibt (`composer outdated`, direkte Pakete und ihre
Abhängigkeiten) und ob für installierte Pakete ***Sicherheitslücken*** bekannt sind
(`composer audit`). Das Ergebnis zeigt das Hauptmenü unter ***„Info“*** – nur für
Superuser, mit Tabelle der Pakete und der Schaltfläche ***„Jetzt prüfen“***.

Gemeldet werden nur Versionen, die `composer update` innerhalb der Versionsgrenzen auch
installiert. Eine neue ***Hauptversion*** eines Pakets erscheint nicht – sie erfordert eine
Änderung der `composer.json`. Auf der Startseite erscheint ein Betriebshinweis nur bei einer
neuen Version von camindo CMS und bei bekannten Sicherheitslücken; neuere Versionen anderer
Pakete stehen nur unter „Info“.

Die Prüfung braucht Netzzugang zu Packagist bzw. zu den Paketquellen und das Programm
`composer` auf dem Server (`paths.composer`, leer = Prüfung aus). Webserver und Cronjob starten
Composer ohne Shell – ein Alias aus der `.bashrc` gilt dort nicht. Tragen Sie deshalb den
absoluten Pfad oder die ganze Befehlszeile ein, zum Beispiel für ein `composer.phar` mit
eigener PHP-Version:

```php
'composer' => '/usr/bin/php8.4 -d memory_limit=-1 /home/www/bin/composer/composer.phar',
```

Wörter mit Leerzeichen setzen Sie in doppelte Anführungszeichen. JavaScript-Bibliotheken,
die camindo CMS als Dateien mitbringt (jQuery, Bootstrap, Editoren), kommen mit dem
Update von camindo CMS.

## Die Kommandozeile

Alle Verwaltungsbefehle laufen über ein Programm:

```
php Core/cron/camindo.php <befehl> [argumente] [--optionen]
```

**Befehle**:

```
status
```
Zustand des Systems: Versionen, Komponenten, wartende Aktionen, Wartungsmodus

```
install
```
Erstinstallation; mit `--step=…` einzelne Schritte wiederholen

```
upgrade
```
Datenbank nach einem Update nachziehen

```
component list
component install NAME [--activate]
component activate NAME
component deactivate NAME
component update NAME
component uninstall NAME [--purge]
```
Zusatzkomponenten verwalten

```
maintenance on [--reason=…]
maintenance off
maintenance status
```
Wartungsmodus

```
backup
backup prune --keep=N
```
Datenbanksicherung

```
cache all
cache docs
```
Caches für Snippets und Backend-Vorlagen und den Suchindex der Handbücher neu bauen (`docs`: nur den Index); `all` leert auch den Ausgabe-Cache der Templates und schreibt den Zwischenspeicher der Websites und Gruppenrechte neu

```
cache include
```
Ausgabe-Cache der Templates (`CMS:INCLUDE … caching=…`) leeren – etwa damit eine Inhaltsänderung sofort sichtbar wird

```
cache records [--instance=ID] [--area=ID,…]
refresh records [--instance=ID] [--area=ID,…]
```
***Datensatz-Cache*** leeren – alle Websites, eine Website (`--instance`) oder einzelne Bereiche (`--area`, mehrere durch Kommas getrennt). Nötig etwa nach Änderungen direkt in der Datenbank. Die Einträge entstehen beim nächsten Abruf neu, offene Arbeitskopien bleiben unberührt. Nicht Teil von `cache all`. Dasselbe im Backend unter „Werkzeuge“ → „Server“ → „Datensatz-Cache leeren“

```
refresh
```
Komponenten mit der Datenbank abgleichen

```
refresh snippets
refresh html
```
Snippet-Cache bzw. Cache der Backend-Vorlagen neu bauen; `snippets` wandelt vorher Snippet-Sets im früheren PHP-Format in `.ini`-Dateien um (siehe [Kapitel 10](./10-module.md#modul-snippets))

```
admin password NAME
```
Passwort eines Benutzers setzen – etwa wenn der einzige Superuser seines vergessen hat

```
key status
key rotate [--invalidate-passwords]
key retire KID [--force]
```
Schlüssel `.camindo.key` wechseln, siehe [Schlüssel wechseln](#schlüssel-wechseln)

```
key rehash
```
Suchwerte verschlüsselter Felder neu berechnen – einmal für Daten, die vor der Suche ohne Groß-/Kleinschreibung gespeichert wurden

```
audit verify [TYP ID]
```
Audit-Log prüfen: Prüfsummenkette der Dateien unter `var/audit/` und Abgleich mit der Datenbank

```
audit reseal TYP ID [--reason=…] [--dry-run]
```
eine gebrochene Prüfsummenkette nach Prüfung neu verketten, siehe [Sicherheit und Compliance](./14-sicherheit-und-compliance.md)

```
audit anonymize-ip [--dry-run]
```
einmalig nach dem Update: IP-Adressen in den Dateien unter `var/audit/` kürzen, die noch vollständig gespeichert sind, siehe [Kapitel 15](./15-datenschutz.md#ip-adressen)

```
audit restore [--dry-run]
```
nach dem Zurückspielen einer Datenbanksicherung: Einträge unter `var/audit/`, die neuer sind als die Datenbank, entfernen (Kopie unter `var/audit-restore-…/`)

```
audit delete TYP ID [--dry-run]
audit delete --area=ID [--force] [--dry-run]
```
den gesamten Verlauf eines Objekts löschen (z. B. `audit delete record 4237`, `audit delete admin 34`) – in der Datenbank und unter `var/audit/`, bei Datensätzen auch ihre Aufträge der [Lösch-Policies](./15-datenschutz.md#löschen-nach-lösch-policy). Mit `--area=ID` den Verlauf aller Datensätze eines Bereichs, auch bereits gelöschter; ohne `--force` nennt der Befehl nur die Anzahl. `--dry-run` zeigt, was gelöscht würde. Nur für Ausnahmefälle – regulär löschen die Aufbewahrungsfristen und die Lösch-Policies

```
audit strip-names
```
einmalig nach dem Update: Namen der Handelnden aus älteren Einträgen entfernen – sie stehen danach nur noch als Id (wiederholbar)

```
api login --admin=NAME [--lifetime=SEK]
api call METHODE '{…}'
api whoami
api methods
api describe METHODE
api logout
```
Die API auf der Kommandozeile: `login` legt eine Sitzung für den Benutzer an und gibt ihre Kennung aus, die folgenden Befehle brauchen sie als `--session=…` oder in der Umgebungsvariablen `CAMINDO_API_SESSION`. Die Benutzergruppe des Benutzers braucht die Oberfläche „Kommandozeile (CLI)“

```
cache instances
```
den Zwischenspeicher für Websites, Sprachen, Bereiche und Gruppenrechte neu schreiben (auch Teil von `cache all`)

```
htaccess
htaccess renew
```
***.htaccess*** prüfen bzw. erneuern – wie die Schaltfläche „Erneuern“ unter „Werkzeuge“ → „Server“. Ohne Zusatz meldet der Befehl, ob die Datei zur Verzeichnisstruktur passt (Exit-Code 1, wenn nicht); `renew` schreibt den von camindo CMS verwalteten Abschnitt neu, Zeilen außerhalb bleiben erhalten. `upgrade` erneuert die Datei ohnehin

```
template commands --lang=de_DE
template commands --all --out=VERZEICHNIS
```
die ***CMS-Befehle*** (Kern und aktive Module) mit ihren Parametern und Beschreibungen als JSON ausgeben – für die Editor-Unterstützung, etwa die Erweiterung für Visual Studio Code. Ohne `--all` in einer Sprache auf die Standardausgabe, mit `--all` je Backend-Sprache eine Datei `commands.{sprache}.json` im Verzeichnis


### Schlüssel wechseln

Die Datei `.camindo.key` verschlüsselt vertrauliche Felder (E-Mail-Adressen, Telefonnummern,
verschlüsselte Eingabefelder, Nachrichten, Secrets der Authenticator-App), würzt die
Passwort-Prüfwerte und ***signiert*** Konten, Benutzergruppen und Rechte: Zu jedem Konto und jedem
Recht speichert camindo CMS eine Prüfsumme, die nur mit dem Schlüssel berechnet werden
kann. Wer nur die Datenbank verändert, erzeugt damit eine ungültige Prüfsumme – das
Konto oder Recht gilt als manipuliert und wird ignoriert. Nach einem Schlüsselwechsel
müssen deshalb alle Prüfsummen neu berechnet werden („neu signieren“).

Einen neuen Schlüssel führen Sie ein mit

```
php Core/cron/camindo.php key rotate
```

Der Befehl sichert die Datenbank, schaltet den Wartungsmodus ein, verschlüsselt alles
mit dem neuen Schlüssel neu, signiert neu (auch die Prüfsummenkette des Audit-Logs,
nachdem die alte geprüft wurde) und meldet alle Benutzer ab. Er lässt sich
höchstens einmal in 24 Stunden ausführen – ein zweiter Aufruf so kurz danach wäre ein
Versehen; `key status` nennt den frühestmöglichen Zeitpunkt. Der alte
Schlüssel bleibt als **abgelöster Schlüssel** in `.camindo.key.retired`:
Passwörter lassen sich nicht umrechnen, weil camindo CMS sie nicht kennt – sie gelten
weiter und werden bei der nächsten Anmeldung automatisch auf den neuen Schlüssel
umgestellt. Ebenso gelten Wiederherstellungscodes und bekannte Geräte weiter.

***Dateirechte des Schlüssels:*** Die neue `.camindo.key` und `.camindo.key.retired`
erhalten dieselben Rechte wie die bisherige `.camindo.key` – auch wenn Sie bewusst
andere als `0600` gesetzt haben, etwa weil der Webserver die Datei auf Ihrem System nur
über die Gruppe lesen kann. Gibt es keine `.camindo.key`, weil der Schlüssel aus der
Umgebungsvariablen `CAMINDO_KEY` kommt, gilt die Einstellung `permissions.key_file` in
`.camindo.php` (Vorgabe `0600`). `key status` warnt, wenn die Rechte der Datei davon
abweichen. Rufen Sie den Wechsel als `root` auf, behalten beide Dateien außerdem den
Eigentümer der bisherigen `.camindo.key` – sonst könnte der Webserver sie danach nicht
mehr lesen.

`key status` zeigt den aktuellen Schlüssel, abgelöste Schlüssel mit der Frist
`security.key_password_grace_days` (Vorgabe 90 Tage) und wie viele Passwörter noch am
alten Schlüssel hängen. Ist die Frist um, entfernen Sie den alten Schlüssel mit
`key retire KID --force`: Wer sich bis dahin nicht angemeldet hat, braucht ein neues
Passwort (Benutzer: `admin password NAME`, Frontend-User: „Passwort vergessen“), und
Wiederherstellungscodes von vor dem Wechsel gelten nicht mehr.

**Passwörter öffentlich aufgetaucht?** Dann

```
php Core/cron/camindo.php key rotate --invalidate-passwords
```

Alle Passwörter werden sofort ungültig, alle Sitzungen, bekannten Geräte und
Wiederherstellungscodes gelöscht, und der alte Schlüssel wird gleich entfernt.
Anschließend setzen Sie die Passwörter der Benutzer mit
`php Core/cron/camindo.php admin password NAME`; Frontend-User setzen ihres über „Passwort
vergessen“. Liegt der Verdacht nahe, dass auch der Schlüssel selbst in fremde Hände
geraten ist, setzen Sie zusätzlich die Authenticator-Apps der Benutzer zurück.

> Sichern Sie `.camindo.key` nach jedem Wechsel erneut – getrennt von den Datenbanksicherungen.

**Schlüssel in der Umgebungsvariablen `CAMINDO_KEY`?** `key rotate` schreibt den neuen
Schlüssel trotzdem in die Datei `.camindo.key` – sie hat Vorrang vor der Variablen, das
System arbeitet also sofort mit dem neuen Schlüssel. Tragen Sie anschließend den Inhalt
von `.camindo.key` in die Variable ein; erst danach dürfen Sie die Datei wieder
entfernen. Bis dahin erscheint auf der Startseite ein [Betriebshinweis](#betriebshinweise-auf-der-startseite).
Die Datei `.camindo.key.retired` mit den abgelösten Schlüsseln bleibt in jedem Fall
liegen.

## Konfiguration

Die Konfiguration steht in `.camindo.php` im Projektverzeichnis. Der Installer
schreibt die wichtigsten Angaben; alles andere hat Vorgaben, die Sie dort überschreiben
können, siehe nachfolgende Tabelle. Die vollständige, kommentierte Liste steht in `Core/src/Config.php`.

Die Datei enthält Zugangsdaten: Rechte `0600`, niemals in eine Versionsverwaltung!

| Schlüssel | Vorgabe | Bedeutung |
|---|---|---|
| `system.locale` | – | Gebietsschema der Installation, z. B. `de_DE` |
| `system.timezone` | – | Zeitzone des Systems und der Cronjobs |
| `system.development_version` | `false` | Entwicklungsmodus – nie auf einem Live-System |
| `system.debugsql` | `false` | alle SQL-Befehle mit Laufzeit protokollieren – nur zusammen mit `system.development_version`, reine Entwicklerfunktion |
| `system.link_length` | `120` | maximale Länge automatisch erzeugter Slugs |
| `system.html_allowed_tags` | `[]` | optionale Whitelist erlaubter HTML-Tags in Inhalten, z. B. `['p','br','strong','a','h1','img']`; andere Tags werden beim Speichern und Anzeigen entfernt. Script, iframe, object, embed, base, meta und Event-Handler entfernt camindo CMS immer |
| `admin.2_factor` | `true` | Zwei-Faktor-Anmeldung per E-Mail-Code (Benutzer mit Authenticator-App bekommen immer deren Abfrage) |
| `admin.2_factor_expiration` | `600` | Gültigkeit des Anmelde-Codes in Sekunden |
| `admin.max_inactive_sec` | `1800` | automatische Abmeldung nach Untätigkeit (Sekunden) |
| `admin.edit_lock_sec` | `5400` | Verfallszeit von Bearbeitungssperren (Sekunden): so lange unverändert, sperrt eine Arbeitskopie den Datensatz nicht mehr. Länger als die automatische Abmeldung, damit sich eine offene Maske nach einer Pause und erneuter Anmeldung noch speichern lässt |
| `admin.bruteforce_max`,<br>`admin.bruteforce_delay` | `3` / `600` | erlaubte Fehlversuche je Anmeldename und Sperrdauer in Sekunden (0 = Schutz aus). An einem Gerät, an dem sich der Benutzer schon angemeldet hat, zählen seine Versuche getrennt – ein gesperrter Name sperrt ihn dort nicht aus |
| `admin.bruteforce_ip` | `true` | bei zu vielen Fehlversuchen die Besucher-Adresse im Log `security` vermerken |
| `admin.throttle.login_ip` | `[20, 600]` | höchstens so viele Fehlanmeldungen je Besucher-Adresse im Zeitfenster (Sekunden), über alle Anmeldenamen – danach ist die Anmeldung von dieser Adresse aus gesperrt (0 = aus). Hinter einem Proxy `security.trusted_proxies` setzen |
| `admin.backend_domain` | `''` | feste Adresse des Backends, z. B. `'cms-k7x2.example.com'` (siehe [Feste Backend-Domain](#feste-backend-domain)). Leer: Backend unter `/backend/` jeder Domain |
| `admin.cookie_secure`,<br>`admin.cookie_samesite` | `true` / `'Lax'` | Sitzungs-Cookie nur über HTTPS senden. `false` nur für einen lokalen Test ohne SSL ([Schnellstart](./01-quickstart-installation.md#lokal-ohne-https-testen)); SameSite-Attribut des Cookies |
| `admin.password_retention_days` | `0` | Gültigkeit eines Passworts in Tagen; danach muss der Benutzer bei der Anmeldung ein neues, anderes setzen (0 = unbegrenzt). Ein von einem anderen Benutzer gesetztes Passwort muss immer bei der ersten Anmeldung geändert werden |
| `admin.allowed_origins` | `[]` | weitere Adressen, von denen aus Formulare an das Backend geschickt werden dürfen, z. B. `['https://cms.example.com']` – nur nötig, wenn ein Proxy den Hostnamen umschreibt (siehe [Sicherheits-Header](#sicherheits-header)) |
| `admin.skiplist_default` | `20` | Datensätze je Seite in Listen |
| `admin.list_max_datasets` | `200` | maximale Einträge in Auswahllisten |
| `admin.max_include_depth` | `20` | maximale Verschachtelungstiefe von Templates (`CMS:INCLUDE`, `CMS:SELECT`) |
| `admin.trash_retention_days` | `7` | Aufbewahrungsfrist im Papierkorb |
| `admin.mass_delete_threshold` | `200` | ab dieser Anzahl laufen Massenlöschung und Stapelverarbeitung zwingend im Hintergrund (im Backend nicht abwählbar; über die API mit `background: no` übergehbar); `0` = Schwelle aus |
| `admin.import_background_threshold` | `200` | ab dieser Anzahl Datensätze läuft ein Import im Hintergrund |
| `admin.export_background_threshold` | `1000` | ab dieser Anzahl Datensätze läuft ein Export im Hintergrund |
| `admin.export_retention_hours` | `24` | Verweildauer nicht abgeholter Exportdateien |
| `admin.tmp_retention_hours` | `24` | Aufbewahrung temporärer Dateien in `var/tmp` |
| `admin.include_cache` | `true` | Ausgabe-Cache der Templates (`CMS:INCLUDE … caching=…`); `false` schaltet ihn ab, alle Templates laufen wieder bei jedem Aufruf |
| `admin.include_cache_lock_wait` | `30` | so viele Sekunden wartet ein Seitenaufruf höchstens, während ein anderer dieselbe Cache-Datei erzeugt; danach erzeugt er die Ausgabe selbst |
| `api.max_inactive_sec`,<br>`api.max_lifetime` | `3600` / `86400` | Sitzungen der Kommandozeile und der PHP-API: automatische Abmeldung nach Untätigkeit und höchste Laufzeit in Sekunden (auch Obergrenze einer beim Anmelden angegebenen Laufzeit) |
| `api.max_limit` | `200` | höchstens so viele Datensätze je Seite einer API-Liste |
| `api.base64_max_bytes` | `20971520` | Höchstgröße einer als Base64 übergebenen Datei in Bytes (0 = ohne Grenze) |
| `security.hsts_max_age` | `0` | HSTS: Browser lassen die Domain nur noch per HTTPS zu, für diese Anzahl Sekunden (0 = aus), siehe [Sicherheits-Header](#sicherheits-header) |
| `security.hsts_include_subdomains` | `false` | HSTS auch für alle Subdomains |
| `security.trusted_proxies` | `[]` | Proxies bzw. Load-Balancer vor der Website (IP-Adressen oder Bereiche wie `10.0.0.0/8`). Nur von ihnen übernimmt camindo CMS die Besucher-Adresse aus `X-Forwarded-For` – wichtig für Sperren und Drosselung je Besucher |
| `security.frontend_csp` | `'off'` | Script-Policy der Websites ohne eigene Einstellung: `'off'`, `'report'`, `'enforce'` – siehe [Kapitel 3](./03-instanzen-und-sprachen.md#script-policy) |
| `security.form_challenge` | `''` | Klasse eines externen Captchas für geschützte Formulare (Entwickler) |
| `security.magick_allow` | `[]` | Formate, die ImageMagick zusätzlich verarbeiten darf: `'svg'`, `'pdf'` – siehe [ImageMagick absichern](#imagemagick-absichern) |
| `security.password_policy` | 12 Zeichen, 1 Sonderzeichen, 1 Groß- (nicht am Anfang), 1 Kleinbuchstabe | Passwort-Richtlinie, siehe [Passwörter und Anmeldegeräte](#passwörter-und-anmeldegeräte) |
| `security.password_hash` | Argon2id, sonst bcrypt | Verfahren und Aufwand für gespeicherte Passwörter |
| `security.device_notify`,<br>`security.device_days`,<br>`security.device_cookie` | `true` / `180` / `'c9dev'` | Hinweis bei Anmeldung von einem neuen Gerät; wie lange ein unbenutztes Gerät bekannt bleibt (zugleich Laufzeit des Cookies); Name des Geräte-Cookies |
| `security.login_notifier` | `''` | eigene Klasse für die Hinweise (z. B. SMS statt E-Mail) |
| `security.admin_2fa` | `'totp'` | Authenticator-App (TOTP) für Benutzer: `'totp'` wählbar, `'totp_required'` Pflicht für Superuser, `'email'` abgeschaltet – siehe [Zwei-Faktor-Anmeldung](#zwei-faktor-anmeldung) |
| `security.totp_issuer` | `''` | Name, unter dem das Konto in der App erscheint (leer: `camindo`); dazu Anmeldename@Domain |
| `security.webauthn` | `true` | Passkeys und Sicherheitsschlüssel als zweiter Faktor im Backend – nur mit fester Backend-Domain (`admin.backend_domain`). `false` schaltet Anlegen und Anmeldung mit Passkey ab |
| `security.key_password_grace_days` | `90` | Frist nach einem [Schlüsselwechsel](#schlüssel-wechseln), die `key status` für Passwörter am alten Schlüssel nennt |
| `security.backend_csp` | `'enforce'` | Script-Schutz des Backends: `'enforce'` lässt nur Scripts von camindo CMS zu. `'report'` lässt alles laufen und protokolliert Verstöße (Modul „Logdateien“, Log `csp`), falls ein Zusatzmodul nicht mehr funktioniert. `'off'` schaltet ihn ab |
| `audit.enabled` | `true` | Audit-Log: wer hat wann was geändert, siehe [Audit-Log](./10-module.md#audit-log) |
| `audit.retention_days_changes` | `0` | Aufbewahrung der Einträge zu bestehenden Objekten in Tagen (0 = unbegrenzt) |
| `audit.log_logins` | `false` | Erfolgreiche An- und Abmeldungen am Backend im Audit-Log aufzeichnen (fehlgeschlagene immer), siehe [Kapitel 15](./15-datenschutz.md#benutzer-mitarbeiter) |
| `audit.retention_days_logins` | `90` | Aufbewahrung der An-/Abmeldungen und Fehlversuche in Tagen (0 = wie `audit.retention_days_changes`) |
| `audit.retention_days_deleted` | `365` | Aufbewahrung des Verlaufs endgültig gelöschter Objekte in Tagen, ab dem Löschen (0 = unbegrenzt) – nicht für Datensätze, deren Verlauf regelt die [Lösch-Policy](./15-datenschutz.md#löschen-nach-lösch-policy) |
| `audit.page_bytes` | `1048576` | Inhalte des Audit-Logs liegen unter `var/audit/`, je Objekt eine Datei; ab dieser Größe in Bytes beginnt eine neue Seite |
| `privacy.ip_anonymize_days` | `7` | Nach so vielen Tagen werden IP-Adressen im Audit-Log, bei Anmeldeversuchen und in den Logdateien gekürzt (0 = beim nächsten Lauf der Aufgabe „Aufbewahrungsfristen anwenden“), siehe [Kapitel 15](./15-datenschutz.md#ip-adressen) |
| `privacy.message_days` | `60` | Interne Nachrichten werden so viele Tage nach dem Versand gelöscht – für Absender und Empfänger (0 = nie) |
| `privacy.ip_v4_octets` | `1` | Wie viele Stellen am Ende einer IPv4-Adresse genullt werden: `1` (203.0.113.**0**) oder `2` (203.0.**0.0**). IPv6 verliert immer die letzten 80 Bit |
| `ai.*` | – | Zusatzmodul KI-Assistent: Anbieter, Zugangsschlüssel, Modell, siehe [KI-Assistent](./10-module.md#zusatzmodul-ki-assistent) |
| `email.*` | – | E-Mail-Versand, siehe unten |
| `user.*` | – | Frontend-Community, siehe [Kapitel 13](./13-community.md#konfiguration) |
| `paths.php`,<br>`paths.mysqldump`,<br>`paths.magick`,<br>`paths.ffmpeg`,<br>`paths.ffprobe`,<br>`paths.composer` | – | Pfade zu externen Programmen; leer lassen, was es nicht gibt (`composer`: [Update-Prüfung](#update-prüfung)) |
| `paths.document_root`,<br>`paths.media`,<br>`paths.backend` | `public/`,<br>`'/media'`,<br>`'/backend'` | Verzeichnis, auf das der Webserver zeigt; Adresspfade der Mediendateien und des Backends relativ dazu |
| `paths.key_file`,<br>`paths.sqlpwd_file` | `.camindo.key`,<br>`.sqlpwd` | Schlüsseldatei und Zugangsdatei für `mysqldump` (absolute Pfade, Vorgabe im Projektverzeichnis) |
| `paths.var_dir`,<br>`paths.tmp_dir`,<br>`paths.upload_dir` | `var/`,<br>`var/tmp/`,<br>`var/upload/` | beschreibbares Hauptverzeichnis, temporäre Dateien und Verzeichnis für per FTP übertragene große Dateien (Auswahl über das Datei-Icon neben Upload-Feldern) |
| `permissions.dir`,<br>`permissions.file` | `0775` / `0664` | Unix-Rechte für Verzeichnisse und Dateien, die camindo CMS anlegt |
| `permissions.key_file` | `0600` | Rechte von `.camindo.key` und `.camindo.key.retired` beim [Schlüsselwechsel](#schlüssel-wechseln), wenn es noch keine `.camindo.key` gibt (Schlüssel aus `CAMINDO_KEY`) – sonst behält der Wechsel die Rechte der bisherigen Datei |
| `db.*` | – | Zugangsdaten der Datenbank |


### Zugangsdaten aus Umgebungsvariablen

Passwörter und der Schlüssel müssen nicht in Dateien im Projektverzeichnis stehen. Lässt
Ihr Hoster oder Ihre Server-Umgebung ***Umgebungsvariablen*** setzen (Docker, PHP-FPM,
Webserver-Konfiguration, Secret-Verwaltung der Plattform), kann camindo CMS diese
Werte von dort übernehmen. Der Rest der Konfiguration – Datenbank-Host, Postfach,
Anbieter – bleibt in `.camindo.php`.

| Variable | ersetzt | Vorrang |
|---|---|---|
| `CAMINDO_DB_PASS` | `db.pass` | Variable vor `.camindo.php` |
| `CAMINDO_SMTP_PASS` | `email.smtp.pass` | Variable vor `.camindo.php` |
| `CAMINDO_AI_API_KEY` | `ai.api_key` (Zusatzmodul KI-Assistent) | Variable vor `.camindo.php` |
| `CAMINDO_KEY` | die Schlüsseldatei `.camindo.key` (genau ihr Inhalt) | **Datei vor Variable** – damit nach einem [Schlüsselwechsel](#schlüssel-wechseln) der neue Schlüssel gilt, bis Sie die Variable nachgezogen haben. Ist die Datei vorhanden, aber nicht lesbar, bricht camindo CMS ab, statt die Variable zu nehmen |

Eine leere Variable zählt als nicht gesetzt. Eine `.env`-Datei liest camindo CMS
**nicht** – die Variablen müssen von außen in die Umgebung von PHP gelangen, und zwar
sowohl für den Webserver als auch für die Cronjobs und die Kommandozeile.

Kommt das Datenbank-Passwort aus `CAMINDO_DB_PASS`, braucht die Datensicherung die
Datei `.sqlpwd` nicht: Sie schreibt für jeden Lauf eine eigene Optionsdatei und löscht
sie danach. Der Installer übernimmt `CAMINDO_DB_PASS` ebenfalls, lässt das Passwort dann
aus `.camindo.php` heraus und legt keine `.sqlpwd` an; ist `CAMINDO_KEY` gesetzt, legt er
keine `.camindo.key` an.

`php Core/cron/camindo.php status` zeigt, welche Werte aus der Umgebung kommen
(„from environment“). Nach dem Einlesen entfernt camindo CMS die Variablen aus der
Umgebung von PHP: Programme, die es aufruft (ImageMagick, ffmpeg, mysqldump), sehen sie
nicht, ebenso wenig die PHP-Info im Modul „Werkzeuge“ – außer unter „Apache Environment“, wenn
der Webserver sie mit `SetEnv` setzt. Bevorzugen Sie deshalb die Umgebung von PHP-FPM
oder des Containers.

### E-Mail-Versand

camindo CMS versendet E-Mails unter anderem für den Anmelde-Code der
Zwei-Faktor-Anmeldung.

| Schlüssel | Bedeutung |
|---|---|
| `email.noreply` | Absenderadresse der Systemmails – Voraussetzung für die Zwei-Faktor-Anmeldung |
| `email.reply` | Kontaktadresse, die in Systemmails genannt wird (siehe unten) |
| `email.smtp.host`, `user`, `pass`, `enc`, `port` | SMTP-Postfach für einen zuverlässigen Versand (`enc`: `starttls` oder `smtps`). Leer: PHP-Funktion `mail()` |

Mit einem SMTP-Postfach der Absenderdomain landen Systemmails deutlich seltener im
Spam. Nicht versendete E-Mails erscheinen im Modul „[Logdateien](./10-module.md#logdateien)“.

Die Kontaktadresse (`email.reply`) wird nicht als Absender (`From`) eingesetzt – sonst
würden automatische Antworten (Autoresponder) Ihr Postfach füllen. Sie steht nur im
Text der E-Mail.

### Feste Backend-Domain

Ohne weitere Einstellung erreichen Sie das Backend unter `/backend/` jeder Domain Ihrer
Installation – und damit auch jeder, der Ihre Website kennt. Besser ist eine ***feste
Backend-Domain***, am besten eine Subdomain, die niemand errät:

```php
'admin' => [
    'backend_domain' => 'website-bearbeiten.example.com',
],
```

Voraussetzungen: Die Subdomain zeigt per DNS auf denselben Server und im Webserver auf
dasselbe Verzeichnis `public/` wie die Websites, und sie hat ein eigenes
HTTPS-Zertifikat (Let's Encrypt genügt).

Danach gilt:

- Unter allen anderen Domains antwortet `/backend/` mit „nicht gefunden“ – ohne Hinweis
  auf die richtige Adresse.
- Die ***Vorschau*** öffnet sich unter der Domain der Website. Der Link darin gilt nur,
  solange Sie im Backend angemeldet sind, und höchstens acht Stunden.
- ***Frontend-Editing*** überträgt das Speichern der persönlichen Einstellungen auf
  alle Websites.
- Die Adresse Ihres Backends taucht in keiner Seite der Website auf.

Eine feste Backend-Domain ist außerdem Voraussetzung für die Anmeldung mit Passkeys.

### Sicherheits-Header

camindo CMS sendet mit jeder Seite Header, die der Browser als Schutz umsetzt:

- ***Kein fremder Rahmen:*** Das Backend lässt sich in keinem `<iframe>` anzeigen, die
  Website nur innerhalb der eigenen Domain. Das verhindert, dass eine fremde Seite
  Klicks in Backend, Login oder Warenkorb unterschiebt (Clickjacking).
- ***Nur eigene Scripts im Backend:*** Das Backend führt nur JavaScript von camindo CMS
  selbst aus. Gelangt trotz aller Filter ein Script in Inhalte, läuft es nicht.
  Funktioniert ein Zusatzmodul danach nicht mehr, stellen Sie vorübergehend
  `security.backend_csp` auf `'report'` und geben dem Hersteller die Einträge des
  Logs `csp` weiter.
- ***Keine Umdeutung von Dateitypen*** (`nosniff`) und ***sparsame Weitergabe der Adresse***
  an externe Seiten (`Referrer-Policy`).
- ***Herkunftsprüfung:*** Formulare und Aktionen, die im Backend etwas ändern, nimmt
  camindo CMS nur an, wenn der Browser sie von der Adresse des Backends aus schickt
  (Header `Origin` bzw. `Referer`) – zusätzlich zum Sicherheitstoken jeder Sitzung.
  Läuft das Backend hinter einem Proxy, der den Hostnamen umschreibt, tragen Sie die
  öffentliche Adresse in `admin.allowed_origins` ein.

Das müssen Sie nicht einrichten. Soll eine Seite von Partnern eingebettet werden, stellt
der Template-Programmierer das im Template um ([CMS:HEADER](../templateprogrammers/header.md)).

***HSTS*** weist den Browser an, die Domain nur noch per HTTPS aufzurufen. Schalten Sie es
erst ein, wenn die Seite dauerhaft und vollständig über `https://` läuft – der Browser
merkt sich die Regel für die eingestellte Zeit, auch wenn Sie sie wieder abschalten.
Am besten setzen Sie HSTS im Webserver oder beim Hoster; geht das nicht, in `.camindo.php`:

```php
'security' => [
    'hsts_max_age' => 300,        // zum Test fünf Minuten, danach 31536000 (ein Jahr)
],
```

`hsts_include_subdomains` nur, wenn wirklich jede Subdomain HTTPS kann.

### Passwörter und Anmeldegeräte

***Gespeichert*** werden Passwörter mit ***Argon2id*** – einem absichtlich langsamen und
speicherintensiven Verfahren, das das Durchprobieren gestohlener Daten teuer macht.
Bietet das PHP des Servers Argon2id nicht an, nimmt camindo CMS **bcrypt**, das jedes
PHP beherrscht. Vorgabe ist das OWASP-Minimum (19 MB Speicher, zwei Durchläufe, rund
30 Millisekunden je Anmeldung). Ältere Passwörter werden bei der nächsten Anmeldung
automatisch umgestellt; niemand muss sein Passwort neu setzen.

```php
'security' => [
    'password_hash' => ['algo' => 'auto', 'memory_cost' => 19456, 'time_cost' => 2, 'cost' => 11],
],
```

`algo`: `auto`, `argon2id` oder `bcrypt`; `memory_cost` in KiB und `time_cost` für
Argon2id, `cost` für bcrypt.

Die ***Passwort-Richtlinie*** gilt für die Passwörter der Benutzer immer und für
Passwort-Felder im Frontend, wenn das Feld die Extension
[Passwort-Richtlinie](./extensions/password.md) trägt. Jede Regel lässt sich mit `0`
abschalten:

```php
'security' => [
    'password_policy' => [
        'min_length'      => 12,    // Mindestlänge
        'min_special'     => 1,     // Sonderzeichen: alles außer Buchstaben und Ziffern
        'min_upper'       => 1,     // Großbuchstaben …
        'upper_not_first' => true,  // … ein Großbuchstabe am Anfang zählt nicht
        'min_lower'       => 1,     // Kleinbuchstaben
        'min_digit'       => 0,     // Ziffern
    ],
],
```

Länge schützt mehr als Sonderzeichen: ein langes Passwort aus mehreren Wörtern ist
stärker als ein kurzes mit Satzzeichen. Wer die Regeln lockert, sollte eher
`min_special` senken als `min_length`.

***Anmeldegeräte:*** camindo CMS erkennt den Browser wieder, mit dem sich jemand anmeldet.
Meldet sich ein Konto von einem ***neuen Gerät*** an, bekommt der Inhaber einen Hinweis –
Benutzer per E-Mail an ihre hinterlegte Adresse (setzt den
[E-Mail-Versand](#e-mail-versand) voraus), Frontend-User über die Templates der Website
(siehe [Kapitel 13](./13-community.md#anmeldegeräte-und-passwörter)). Die erste Anmeldung
eines Kontos löst keinen Hinweis aus. Ein Gerät, das `device_days` Tage nicht benutzt
wurde, gilt danach wieder als neu; ebenso ein Browser, dessen Cookies gelöscht wurden.
Die Zwei-Faktor-Anmeldung bleibt auch auf bekannten Geräten bestehen.

### Zwei-Faktor-Anmeldung

Nach dem Passwort verlangt camindo CMS einen zweiten Faktor – je Benutzer einen
von dreien:

| Faktor | Wann | Stärke |
|---|---|---|
| ***Passkey*** (WebAuthn) | der Benutzer hat einen angelegt – setzt eine [feste Backend-Domain](#feste-backend-domain) voraus | am höchsten: an die Adresse des Backends gebunden, nicht abfangbar, keine Eingabe auf gefälschten Seiten möglich; dazu Wiederherstellungscodes |
| ***Authenticator-App*** (TOTP nach RFC 6238) | der Benutzer hat sie in seinen persönlichen Einstellungen eingerichtet und `security.admin_2fa` ist nicht `'email'` | hoch: der Code entsteht auf dem Telefon; dazu zehn einmal verwendbare Wiederherstellungscodes |
| ***Code per E-Mail*** | sonst, wenn `admin.2_factor` an ist | so sicher wie das Postfach |

`security.admin_2fa` steuert die App:

```php
'security' => [
    'admin_2fa' => 'totp_required',  // 'email' | 'totp' (Vorgabe) | 'totp_required'
],
```

Mit **`'totp_required'`** müssen alle Superuser die App benutzen. Wer sie noch nicht
hat, richtet sie bei der nächsten Anmeldung direkt im Anmeldedialog ein; abschalten
kann ein Superuser sie dann nicht mehr. Für die übrigen Benutzergruppen bleibt die App freiwillig.
Mit **`'email'`** ist die App nicht einrichtbar, und bereits eingerichtete ruhen – es
gilt wieder der E-Mail-Code.

***Passkeys*** stehen jedem Benutzer offen, sobald `admin.backend_domain`
eingetragen ist, unabhängig von `security.admin_2fa`. Ein Passkey erfüllt auch die
Pflicht aus `'totp_required'`. Hat ein Benutzer Passkeys **und** die App, nimmt der
Anmeldedialog beides. Abschalten lassen sich Passkeys mit
`'security' => ['webauthn' => false]` – Konten, die nur Passkeys hatten, melden sich
dann mit einem Wiederherstellungscode an. Die Bindung an die Domain bedeutet: Ändert
sich die Backend-Domain, müssen alle Passkeys neu angelegt werden.

Ein falscher Code zählt wie ein falsches Passwort (`admin.bruteforce_max`), und ein
richtiges Passwort setzt den Zähler nicht zurück – erst die vollständige Anmeldung.
So lässt sich der sechsstellige Code nicht durchprobieren. Jeder App-Code gilt nur
einmal.

Richtet ein Benutzer die App ein, schaltet sie ab oder meldet sich mit einem
Wiederherstellungscode an, bekommt er eine E-Mail (bzw. eine Nachricht über den
`login_notifier`), und der Vorgang steht im Audit-Log. Verlorene Telefone setzt ein
Superuser zurück ([Kapitel 7](./07-benutzer-und-rechte.md#anmeldeschutz)).

Statt einer E-Mail kann eine ***SMS*** oder eine andere Nachricht gehen: Ein Entwickler
schreibt dafür eine kleine PHP-Klasse, die die Schnittstelle
`Camindo\Core\LoginNotifierInterface` erfüllt, und Sie tragen sie ein:

```php
'security' => [
    'login_notifier' => 'App\SmsNotifier',
],
```

Die Klasse bekommt die Ereignisse „Anmeldung von neuem Gerät“ (Backend und Frontend)
und „Passwort geändert“ (Frontend) mit Konto, Gerät, IP-Adresse und Uhrzeit. Kann sie
nicht senden, steht das im Log `security`.

### ImageMagick absichern

Bilddateien sind ein beliebter Angriffsweg: Eine präparierte Datei nutzt eine Lücke in
ImageMagick oder in einem Programm, das ImageMagick aufruft. camindo CMS schützt sich
auf drei Ebenen, ohne dass Sie etwas einrichten müssen:

- ***Eigene Sicherheits-Policy:*** Vor jedem Aufruf gilt eine Policy, die camindo CMS in
  `var/config/magick/policy.xml` erzeugt. Sie sperrt Skript- und Sonderformate, fremde
  Hilfsprogramme (Ghostscript, curl, LibreOffice …) und PostScript und begrenzt Speicher,
  Laufzeit und Bildgröße. Sie ergänzt die Policy Ihres Servers, sie kann nichts
  freigeben, was der Server sperrt. Das funktioniert auch auf Shared Hosting.
- ***Format aus dem Inhalt:*** ImageMagick liest jede Datei nur mit dem Format, das
  camindo CMS beim Hochladen erkannt hat. Passen Endung und Inhalt nicht zusammen, wird
  der Upload abgelehnt.
- ***Zeitlimits:*** Jede Umwandlung wird nach spätestens zwei Minuten abgebrochen.

SVG und PDF geben Sie mit `security.magick_allow` frei (siehe
[Medien](./08-medien.md#konverter-einrichten)). Die Detailansicht eines Konverters zeigt
die installierte ImageMagick-Version, ob sie veraltet ist, und ob SVG und PDF
tatsächlich laufen.

***Wenn Sie den Server selbst betreiben***, können Sie ImageMagick weiter abschotten:

- Halten Sie ImageMagick aktuell (ab Version 7.1.1). Ältere Versionen funktionieren,
  enthalten aber bekannte Lücken.
- Installieren Sie Ghostscript nicht, wenn Sie keine PDFs umwandeln.
- Lassen Sie ImageMagick unter einem eigenen Benutzer oder in einem eigenen Container
  laufen, der nur das Medienverzeichnis und `var/tmp` lesen und schreiben darf. Tragen
  Sie dann unter `paths.magick` ein kleines Script ein, das den Aufruf weiterreicht und
  Grenzen setzt. Es muss `magick` heißen und in einem eigenen Verzeichnis liegen, daran
  erkennt camindo CMS ImageMagick 7:

  ```sh
  #!/bin/sh
  # /opt/camindo-magick/magick  -> paths.magick
  ulimit -t 150 -v 1048576          # CPU-Sekunden, Speicher in KB
  exec sudo --preserve-env=MAGICK_CONFIGURE_PATH -u imagick /usr/bin/magick "$@"
  ```

  `sudo` braucht dafür einen Eintrag in den sudoers für den Webserver-Benutzer. Wichtig
  ist `--preserve-env`: ohne die Variable `MAGICK_CONFIGURE_PATH` fehlt die
  camindo-Policy. Der Benutzer `imagick` muss `var/config/magick/` lesen können.

  Für ImageMagick 6 legen Sie zwei solche Scripte `convert` und `identify` in das
  Verzeichnis und tragen den Pfad zu `convert` ein.
- Wenn Sie ausschließlich JPEG, PNG und GIF verarbeiten, genügt der GD-Prozessor. Er
  braucht kein ImageMagick.

## Protokolle

Alle Protokolle liegen in `var/log/`, je Tag und Art eine Datei (etwa
`2026-09-22_php_errors.log`, `…_scheduler.log`, `…_security.log`). Superuser sehen sie
im Modul „[Logdateien](./10-module.md#logdateien)“.  
`php Core/cron/camindo.php status` fasst den Zustand des Systems zusammen.

Die Logdateien wachsen, bis sie gelöscht werden. Die Zeitplaner-Aufgabe
***„Logs aufräumen“*** (von der Installation angelegt, siehe [Scheduler](#scheduler)) löscht
alte Logdateien und gespeicherte Anmeldeversuche mit IP-Adresse; die Fristen stellen
Sie in der Aufgabe ein. Unabhängig davon kürzt camindo CMS die IP-Adressen in den
Logdateien nach `privacy.ip_anonymize_days` Tagen (siehe
[Kapitel 15](./15-datenschutz.md#ip-adressen)).

Wer was im Backend geändert hat, steht nicht in den Logdateien, sondern im
[Audit-Log](./10-module.md#audit-log).

## Betriebshinweise auf der Startseite

Superuser sehen auf der Startseite unter den Modulkacheln einen Block
***„Betriebshinweise“***, sobald im Betrieb etwas nicht stimmt. Ist alles in Ordnung,
erscheint er nicht.

| Hinweis | Bedeutung |
|---|---|
| Cronjob läuft nicht | `cron.php` hat sich seit über 15 Minuten nicht gemeldet. Zeitplaner (auch alle Wartungsaufgaben) und Thumbnails stehen still |
| Temporäre Dateien zu alt | In `var/tmp/` liegen Einträge, die älter sind als `admin.tmp_retention_hours` (plus zwei Stunden). Die Aufgabe „Temporäre Dateien löschen“ greift nicht |
| Wartungsaufgaben fehlen | Für eine der Wartungsaufgaben (Papierkorb, temporäre Dateien, Lösch-Policies, Aufbewahrungsfristen, Audit-Log prüfen, Updates) gibt es keine eingeschaltete Aufgabe im Zeitplaner |
| Keine Datenbanksicherung | Es gibt keine eingeschaltete Aufgabe „Datenbank-Backup“ |
| Datenbanksicherung fehlgeschlagen / zu alt / unbrauchbar | Der letzte Lauf meldete einen Fehler, `backup-1.sql` ist älter als zwei Tage oder unbrauchbar: kleiner als 10 KB (dann steht darin meist nur eine Fehlermeldung) oder ohne die Schlusszeile `-- Dump completed` (Dump abgebrochen) |
| Aufgaben mit Fehler | Andere Zeitplaner-Aufgaben, deren letzter Lauf fehlschlug |
| Logs werden nicht aufgeräumt | Es gibt keine Aufgabe „Logs aufräumen“ |
| Frontend-Cache wird nicht aufgeräumt | Templates nutzen den Ausgabe-Cache, aber es gibt keine eingeschaltete Aufgabe „Frontend-Cache aufräumen“ |
| Wenig Speicherplatz | Unter 2 GB frei für `var/` (unter 512 MB: Alarm) |
| Nicht beschreibbar | `var/tmp`, `var/log` oder das Medienverzeichnis |
| E-Mail fehlt | Zwei-Faktor-Anmeldung ist an, aber keine Absendeadresse eingerichtet |
| Entwicklungsmodus | `system.development_version` ist eingeschaltet |
| Cookie ohne HTTPS | `admin.cookie_secure` ist `false` |
| Schlüssel in CAMINDO_KEY veraltet | Die Umgebungsvariable `CAMINDO_KEY` enthält nicht den aktuellen Schlüssel – meist nach einem [Schlüsselwechsel](#schlüssel-wechseln). Verwendet wird `.camindo.key`; tragen Sie deren Inhalt in die Variable ein |
| security.txt fehlt | Es gibt keinen Meldeweg für Sicherheitslücken (siehe [Kapitel 14](./14-sicherheit-und-compliance.md#sicherheitslücken-melden)) |
| Audit-Log beschädigt | Die tägliche Prüfung hat in `var/audit/` eine gebrochene Prüfsummenkette, fehlende Einträge oder Einträge der Datenbank, die nicht zu den Dateien passen, gefunden; Details mit `php Core/cron/camindo.php audit verify` |
| Sicherheitslücken / neue Version / Updates | Ergebnis der täglichen [Update-Prüfung](#update-prüfung): bekannte Lücken in installierten Paketen (Alarm), eine neue Version von camindo CMS (Warnung); „Öffnen“ führt zu „Info“ |

Ein Klick auf „Öffnen“ führt, wo möglich, direkt an die Stelle, an der sich der Hinweis
beheben lässt.

## Checkliste für den Live-Betrieb

- [ ] HTTPS eingerichtet, `admin.cookie_secure` und `user.cookie_secure` auf `true` (Vorgabe – in `.camindo.php` darf kein `false` mehr stehen)
- [ ] `system.development_version` ist `false`
- [ ] ImageMagick aktuell und in der Detailansicht der Konverter keine Warnung zu Version und Sicherheits-Policy
- [ ] HSTS eingeschaltet (Webserver oder `security.hsts_max_age`), sobald HTTPS dauerhaft läuft
- [ ] E-Mail-Versand eingerichtet, Zwei-Faktor-Anmeldung eingeschaltet, alle Benutzer mit E-Mail-Adresse (Anmelde-Code und Hinweis auf neue Geräte)
- [ ] Superuser mit Authenticator-App – auf Live-Systemen `security.admin_2fa = 'totp_required'`
- [ ] Passwort-Felder im Frontend mit der Extension „Passwort-Richtlinie“
- [ ] Shops und Mitgliederbereiche: zweiter Faktor im Profil-Bereich mindestens „Code bei neuem Gerät“ ([Kapitel 13](./13-community.md#zweiter-faktor))
- [ ] Script-Policy der Website erst „Nur melden“, nach sauberem Protokoll „Durchsetzen“ ([Kapitel 3](./03-instanzen-und-sprachen.md#script-policy))
- [ ] feste Backend-Domain eingetragen (`admin.backend_domain`), eigene Subdomain mit HTTPS
- [ ] hinter Proxy oder Load-Balancer: `security.trusted_proxies` gesetzt
- [ ] der Cronjob `cron.php` läuft (`php Core/cron/camindo.php status`)
- [ ] Wartungsaufgaben im Zeitplaner vorhanden und eingeschaltet (`php Core/cron/camindo.php scheduler list`), Zeiten passend
- [ ] Datenbanksicherungen zusätzlich außerhalb des Servers
- [ ] nutzen die Templates `caching`: Aufgabe „Frontend-Cache aufräumen“ im Zeitplaner eingerichtet
- [ ] Fristen des Audit-Logs (`audit.*`) mit dem Datenschutzkonzept abgestimmt
- [ ] `public/.well-known/security.txt` angelegt
- [ ] keine Betriebshinweise auf der Startseite
- [ ] `.camindo.key` an einem sicheren Ort gesichert (bzw. der Inhalt von `CAMINDO_KEY`)
- [ ] Schreibrechte des Webservers nur auf `var/` und `public/media/`
- [ ] Instanzen mit „Kanonische Aufrufe“ für Suchmaschinen

## Lizenz

camindo CMS ist freie Software unter der ***GNU AGPL*** Version 3 oder später. Den
Lizenztext finden Sie in `Core/LICENSE`, die Lizenzen der mitgelieferten Bibliotheken in
`Core/THIRD-PARTY-NOTICES.md`.

Für den Betrieb bedeutet das:

* Eine **unveränderte** Installation dürfen Sie ohne weitere Pflichten betreiben, auch
  für Kunden und gegen Entgelt.
* Ihre ***Templates*** und alle ***Inhalte*** gehören Ihnen und dürfen unter beliebiger
  Lizenz stehen – eine Zusatzerlaubnis in `Core/LICENSING.md` nimmt sie ausdrücklich aus.
* Haben Sie den **Core oder eine AGPL-Komponente verändert** und ist die Website öffentlich
  erreichbar, müssen Sie den Besuchern den Quellcode Ihrer geänderten Fassung anbieten,
  etwa mit einem Link auf ein öffentliches Repository.
