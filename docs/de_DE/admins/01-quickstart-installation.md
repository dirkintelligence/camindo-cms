# 1 camindo CMS installieren

Diese Schnellstart-Anleitung fasst zusammen, was zur Inbetriebnahme von camindo CMS
nötig ist. Wenn Sie camindo CMS produktiv einsetzen, lesen Sie bitte alle Kapitel, insbesondere
das Kapitel [Betrieb und Wartung](./12-betrieb.md).

## Was Sie brauchen

Einen Server (oder Rechner) mit Shell-Zugang (SSH oder Terminal) und darauf:

| Was | Version | So prüfen Sie es |
|---|---|---|
| PHP | 8.3 oder neuer, mit den Erweiterungen `mysqli`, `intl`, `gd`, `json`, `mbstring` | `php -v` und `php -m` |
| MySQL oder MariaDB | MySQL 8 / MariaDB 10.6 oder neuer | `mysql --version` |
| Apache | 2.4 mit `mod_rewrite` und `AllowOverride All` | `apachectl -M \| grep rewrite` |
| Composer | 2.x | `composer --version` |
| Git | beliebig | `git --version` |
| optional: ImageMagick, ffmpeg, mysqldump | – | `which magick ffmpeg mysqldump` |

- ***ImageMagick*** – Bildversionen lassen sich auch mit der in PHP eingebauten
  GD-Bibliothek berechnen; ImageMagick unterstützt aber sehr viel mehr Formate
  (etwa HEIC, TIFF, PSD) und Einstellungen.
- **ffmpeg** wird für die Umwandlung von Videos und Audiodateien und für Standbilder
  aus Videos benötigt.
- **mysqldump** wird für Datenbanksicherungen benötigt – vor Updates und durch den
  [Zeitplaner](./10-module.md#zeitplaner).

## Die Schritte im Überblick

1. Datenbank anlegen
2. Dateien holen – der Installer startet automatisch
3. Installer-Fragen beantworten
4. Webserver einrichten und Dateirechte setzen
5. Cronjobs einrichten
6. Im Backend anmelden

---

## Schritt 1 – Datenbank anlegen

Halten Sie die Zugangsdaten für eine ***leere Datenbank*** bereit. Beim Hoster legen Sie
sie im Kundenmenü an; auf einem eigenen Server zum Beispiel so:

```bash
mysql -u root -p
```

```sql
CREATE DATABASE camindo CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'camindo'@'localhost' IDENTIFIED BY 'EIN-SICHERES-PASSWORT';
GRANT ALL PRIVILEGES ON camindo.* TO 'camindo'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Unter ***MariaDB*** heißt die Sortierung `utf8mb4_uca1400_ai_ci` (ab 10.10), bei älteren
Versionen `utf8mb4_unicode_ci`. Die Installation passt die Tabellen selbst an den
Server an; einen Index für die Suche in Listenfeldern, den nur MySQL kennt, lässt sie unter
MariaDB weg.

## Schritt 2 – Dateien holen

Wechseln Sie in das Verzeichnis, in dem das CMS installiert werden soll (hier
`/var/www`), und lassen Sie Composer das Projekt anlegen:

```bash
cd /var/www
composer create-project camindo/project mysite
```

`mysite` ist der Name des neuen Projektverzeichnisses und frei wählbar.

Composer lädt das Grundgerüst (`composer.json`, `public/`, leere Verzeichnisse), den
Core nach `Core/` und die Bibliotheken nach `vendor/`. Anschließend startet
automatisch der Installer. Falls er nicht startet oder Sie ihn abgebrochen haben:

```bash
cd /var/www/mysite
php Core/cron/camindo.php install
```

## Schritt 3 – Der Installer

Der Installer prüft zuerst die Voraussetzungen und markiert jede Zeile mit `[ok]`,
`[--]` (fehlt, ist aber optional) oder `[!!]` (fehlt – der Installer bricht ab):

```
requirements
  [ok] PHP >= 8.3 (8.4.22)
  [ok] PHP extension mysqli
  [ok] PHP extension intl
  [ok] writable: var
  [--] magick: not found
  ...
```

Dann stellt er Fragen. In eckigen Klammern steht die Vorgabe – `Enter` übernimmt sie.

| Frage | Ihre Eingabe |
|---|---|
| Database host `[localhost]` | meist `Enter`; beim Hoster steht der Host in den Zugangsdaten |
| Database port `[3306]` | `Enter` |
| Database name `[camindo]` | Name Ihrer Datenbank |
| Database user `[camindo]` | Name Ihres Datenbank-Benutzers |
| Database password | das Passwort (wird nicht angezeigt). Danach prüft der Installer sofort die Verbindung; schlägt sie fehl, nennt er den Grund und fragt die Datenbank-Angaben erneut ab |
| Time zone `[Europe/Berlin]` | Ihre Zeitzone |
| Locale `[de_DE]` | Land/Sprache für Datums- und Zahlenformate |
| Will the site be served over HTTPS? `[y]` | `Enter`, wenn die Seite über `https://` läuft. **`n` nur für einen lokalen Test ohne SSL** – siehe [Lokal ohne HTTPS testen](#lokal-ohne-https-testen) |
| Admin name `[admin]` | Ihr Anmeldename im Backend (Kleinbuchstaben, Ziffern, `_`, `-`) |
| Admin password | nach der Passwort-Richtlinie (standardmäßig mindestens 12 Zeichen mit Groß- und Kleinbuchstaben und einem Sonderzeichen), zweimal eingeben |
| Admin e-mail | Ihre E-Mail-Adresse (darf leer bleiben, wird aber für den Anmelde-Code benötigt) |
| Backend language (ar_SA\|bn_BD\|de_DE\|en_US\|…) `[en_US]` | `de_DE` für ein deutsches Backend. Zur Wahl stehen `ar_SA`, `bn_BD`, `de_DE`, `en_US`, `es_ES`, `fr_FR`, `hi_IN`, `ja_JP`, `ko_KR`, `pt_BR`, `ur_PK`, `zh_CN`; die Sprache lässt sich später in den persönlichen Einstellungen ändern |

Danach läuft alles automatisch: Schlüsseldatei, Konfiguration, Verzeichnisse,
Datenbanktabellen, Ihr Benutzerkonto (Superuser), Caches.  
Am Ende steht:

```
finish
  var/install.lock written
```

Der Installer läuft nur einmal. Ein erneuter Aufruf meldet „already installed“ und
tut nichts.

***Drei Dateien im Projektverzeichnis sind jetzt wichtig:***

| Datei | Inhalt | Wenn sie verloren geht |
|---|---|---|
| `.camindo.php` | Konfiguration (Datenbank, Pfade, E-Mail) | neu anlegen mit `install --step=config` |
| `.camindo.key` | Schlüssel für verschlüsselte Felder und Passwörter | **nicht ersetzbar** – ohne ihn sind verschlüsselte Inhalte verloren. Bitte Kopie an einem sicheren Ort aufbewahren! |
| `.sqlpwd` | Passwort für `mysqldump` | neu anlegen mit `install --step=sqlpwd` |

Alle drei Dateien erhalten die Rechte `0600`, nur ihr Eigentümer kann sie lesen. Das passt,
wenn Kommandozeile und Webserver unter demselben Benutzer laufen – bei den meisten
Hosting-Angeboten mit eigenem PHP-Benutzer je Kunde ist das so. Führen Sie den Installer
dagegen als `root` aus (etwa in einem Docker-Container oder mit `sudo`), übergibt er die
Dateien an den ***Webserver-Benutzer***: an den mit `--web-user=NAME` genannten (oder in
der Umgebungsvariablen `CAMINDO_WEB_USER`), sonst an den Eigentümer des
Projektverzeichnisses. Ist keiner von beiden bekannt, gehören die Dateien `root`, und der
Installer warnt – holen Sie die Übergabe dann mit
`php Core/cron/camindo.php install --step=key --web-user=NAME` nach.

Passwörter und Schlüssel aus diesen Dateien können alternativ auch in ***Umgebungsvariablen***
stehen, wenn Ihr Server das anbietet; mehr dazu siehe
[Zugangsdaten aus Umgebungsvariablen](./12-betrieb.md#zugangsdaten-aus-umgebungsvariablen).

## Schritt 4 – Webserver einrichten

Die Website liegt **nicht** direkt im Projektverzeichnis, sondern in dessen
Unterverzeichnis **`public/`**. Das ist Absicht: Alles, was der Browser nicht sehen
darf (Konfiguration, Schlüssel, Code), liegt eine Ebene höher.

Apache-VirtualHost (z. B. `/etc/apache2/sites-available/mysite.conf`):

```apache
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /var/www/mysite/public
    <Directory /var/www/mysite/public>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog  ${APACHE_LOG_DIR}/mysite-error.log
    CustomLog ${APACHE_LOG_DIR}/mysite-access.log combined
</VirtualHost>
```

```bash
sudo a2enmod rewrite
sudo a2ensite mysite
sudo systemctl reload apache2
```

Beim Hoster stellen Sie im Kundenmenü das Zielverzeichnis der Domain (oft „Document
Root“ oder „Webroot“) auf `mysite/public`. HTTPS richten Sie wie gewohnt ein (Let's
Encrypt, Zertifikat des Hosters) – camindo CMS braucht dafür nichts Besonderes.

### Lokal ohne HTTPS testen

camindo CMS ist ab Werk auf HTTPS eingestellt: Die Anmelde-Cookies für das Backend
und für Frontend-User werden nur über verschlüsselte Verbindungen gesendet
(`cookie_secure`). Über eine reine `http://`-Adresse verwirft der Browser diese
Cookies – ***die Anmeldung scheitert dann***, und der Anmeldedialog weist darauf hin.

Wenn Sie camindo CMS nur auf Ihrem Rechner ausprobieren und kein Zertifikat
einrichten wollen:

1. Beantworten Sie im Installer die Frage *Will the site be served over HTTPS?* mit
   **`n`** (oder rufen Sie ihn mit `--https=n` auf). Der Installer schreibt dann in
   `.camindo.php`:

   ```php
   'admin' => [
       'cookie_secure' => false,
   ],
   'user' => [
       'cookie_secure' => false,
   ],
   ```

   Ist der Installer schon mit `y` gelaufen, tragen Sie diese beiden Werte selbst in
   `.camindo.php` ein.

2. ***Bevor die Installation erreichbar wird*** – auf einem Server, im Firmennetz, für
   Kollegen – richten Sie HTTPS ein und setzen beide Werte auf **`true`** (oder
   löschen die beiden Zeilen, dann gilt die Vorgabe `true`). Ohne diese Einstellung
   gehen Sitzungs-Cookies unverschlüsselt über das Netz und können mitgelesen werden.

> Chrome und Firefox behandeln `http://localhost` (auch `http://mysite.localhost`)
> wie eine sichere Verbindung. Wer lokal unter diesen Adressen testet, kommt deshalb
> meist auch mit `cookie_secure = true` zurecht – Safari und andere Adressen
> (`http://mysite.test`, IP-Adressen im Netz) nicht.

***Dateirechte:*** Der Webserver (meist Benutzer `www-data`) muss in `var/` und
`public/media/` schreiben dürfen, sonst nirgends:

```bash
sudo chown -R www-data:www-data /var/www/mysite/var /var/www/mysite/public/media
```

Haben Sie den Installer als anderer Benutzer ausgeführt, gehören diesem auch die
Cache-Dateien unter `var/cache/` – der Befehl oben korrigiert das.

## Schritt 5 – Cronjobs

Öffnen Sie die Crontab des Webserver-Benutzers

```bash
sudo crontab -u www-data -e
```

und tragen Sie ***eine*** Zeile ein:

```
* * * * *   /usr/bin/php /var/www/mysite/Core/cron/cron.php  >/dev/null 2>&1
```

`cron.php` ist der einzige Cronjob. Er prüft jede Minute, was zu tun ist: Manches läuft
minütlich (Template-Änderungen übernehmen, Bilder und Videos umwandeln), alles andere
sind Aufgaben des [Zeitplaners](./10-module.md#zeitplaner). Die Installation richtet
dort die ***Wartungsaufgaben*** ein – nachts zwischen 2 und 4 Uhr verteilt: Datenbank-
Backup, Papierkorb leeren, Lösch-Policies und Aufbewahrungsfristen anwenden, Logs und
Frontend-Cache aufräumen, Volltext aktualisieren, Audit-Log prüfen, nach Updates suchen;
temporäre Dateien stündlich. Zeiten und Fristen passen Sie im Zeitplaner an (siehe
[Betrieb](./12-betrieb.md#cronjobs)). Ohne den Cronjob findet keine Wartung statt.

## Schritt 6 – Anmelden

- Backend: `https://example.com/backend/` – mit dem Namen und Passwort aus Schritt 3
  (lokal ohne SSL: `http://…/backend/`, siehe [Lokal ohne HTTPS testen](#lokal-ohne-https-testen)).
- Frontend: `https://example.com/` – meldet zunächst, dass noch keine Inhalte
  eingerichtet sind.

Wie es im Backend weitergeht, beschreibt das Kapitel [Das Konzept](./02-konzept.md) mit einem
Leitfaden für die ersten Schritte.

---

## Zusatzkomponenten installieren

Zusatzmodule, Medien-Prozessoren und Extensions sind Composer-Pakete. Es sind immer
zwei Schritte:  

* Composer legt die Dateien ab,  
* camindo CMS richtet die Datenbank ein:


```bash
cd /var/www/mysite
composer require camindo/module-pdf
php Core/cron/camindo.php component install Pdf --activate
```

Was installiert und aktiv ist, zeigt `php Core/cron/camindo.php component list` – oder
das Modul „[Komponenten](./10-module.md#komponenten)“ im Backend.

## Wenn etwas schiefgeht

| Symptom | Ursache und Abhilfe |
|---|---|
| Browser zeigt „camindo is not installed yet“ | Der Installer ist nicht durchgelaufen → `php Core/cron/camindo.php install` |
| `requirements not met` | Fehlende PHP-Erweiterung oder fehlendes Schreibrecht → die `[!!]`-Zeile nennt es |
| `database connection failed` | Host, Name, Benutzer und Passwort prüfen → beim Hoster ist der Host oft nicht `localhost` |
| Backend lädt ohne Gestaltung (CSS/JS fehlt) | `public/backend/assets` fehlt oder ist veraltet → `php Core/cron/camindo.php upgrade` kopiert die Dateien neu |
| Fehler 500 im Frontend | `.htaccess` fehlt oder `AllowOverride` ist nicht `All` → ist `mod_rewrite` aktiv? |
| „503 – Wartung“ nach einem Update | → `php Core/cron/camindo.php upgrade` ausführen (Datenbank nachziehen) |
| Anmeldung scheitert ohne Fehlermeldung,<br>im Anmeldedialog steht ein Hinweis zu HTTPS | Sie rufen das Backend über `http://` auf, `cookie_secure` ist aber `true` → siehe [Lokal ohne HTTPS testen](#lokal-ohne-https-testen) |
| Anmelde-Code kommt nicht an | → E-Mail-Postfach in `.camindo.php` einrichten (siehe [Betrieb](./12-betrieb.md#e-mail-versand)) |
| Fehler 500 im Frontend und im Backend,<br>auf der Kommandozeile:<br>`CONFIGURATION ERROR:`<br>`.camindo.key exists but is not readable` | Der Benutzer, unter dem Webserver bzw. Cronjob laufen, darf die Schlüsseldatei nicht lesen – meist, weil der Installer oder ein Schlüsselwechsel als `root` lief → `php Core/cron/camindo.php status` nennt die betroffenen Dateien; als `root` übergibt `install --step=key --web-user=NAME` sie an den Webserver-Benutzer (sonst `chown`; kann der Webserver die Datei auf Ihrem System nur über die Gruppe lesen, `chmod 640`). camindo CMS weicht in diesem Fall bewusst **nicht** auf die Umgebungsvariable `CAMINDO_KEY` aus. Dasselbe gilt für `.camindo.key.retired` |
| Fehler 500 im Frontend und im Backend,<br>auf der Kommandozeile:<br>`CONFIGURATION ERROR:`<br>`paths.key_file -`<br>`file not found` | Die Schlüsseldatei `.camindo.key` fehlt. Holen Sie sie aus Ihrer [Datensicherung](./12-betrieb.md#datensicherung) zurück – **nicht neu erzeugen**: `install --step=key` schriebe einen neuen Schlüssel, verschlüsselte Inhalte und Passwörter wären dann unbrauchbar. Kommt der Schlüssel aus der Umgebungsvariablen `CAMINDO_KEY`, prüfen Sie, ob sie für Webserver **und** Cronjob gesetzt ist |
| Etwas anderes | → `php Core/cron/camindo.php status` zeigt den Zustand des Systems; `var/log/` enthält die Details |

Einzelne Schritte des Installers lassen sich wiederholen, ohne die Datenbank
anzufassen: `php Core/cron/camindo.php install --step=dirs` (ebenso `key`, `config`,
`sqlpwd`, `runtime`).

Updates beschreibt das Kapitel [Betrieb und Wartung](./12-betrieb.md#updates).
