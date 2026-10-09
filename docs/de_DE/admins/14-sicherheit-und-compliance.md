# 14 Sicherheit, Datenschutz und Betrieb – für Entscheider

Dieses Kapitel richtet sich an alle, die über den Einsatz von camindo CMS entscheiden
oder dafür verantwortlich sind: IT-Leitung, Datenschutzbeauftragte,
Informationssicherheit und Projektverantwortliche. Es beschreibt, **was** das System
leistet und ***welche organisatorischen Aufgaben*** beim Betreiber bleiben. Die
technischen Einzelheiten stehen in den verlinkten Kapiteln.

- [Sicherheit im Überblick](#sicherheit-im-überblick)
- [Datensicherung und Wiederherstellung](#datensicherung-und-wiederherstellung)
- [Updates und Sicherheitsupdates](#updates-und-sicherheitsupdates)
- [Sicherheitslücken melden](#sicherheitslücken-melden)
- [Datenschutz (DSGVO)](#datenschutz-dsgvo)
- [Nachvollziehbarkeit: das Audit-Log](#nachvollziehbarkeit-das-audit-log)
- [Betriebsüberwachung](#betriebsüberwachung)
- [Aufgaben des Betreibers](#aufgaben-des-betreibers)

## Sicherheit im Überblick

| Bereich | Was camindo CMS mitbringt | Mehr dazu |
|---|---|---|
| Anmeldung am Backend | Passwort-Richtlinie (Vorgabe: mindestens 12 Zeichen), Speicherung mit Argon2id, Zwei-Faktor-Anmeldung per Authenticator-App (für Superuser erzwingbar) oder E-Mail-Code, Sperre nach wiederholten Fehlversuchen, Hinweis per E-Mail bei Anmeldung von einem neuen Gerät, automatische Abmeldung nach Untätigkeit | [Kapitel 12](./12-betrieb.md#passwörter-und-anmeldegeräte) |
| Rechte | Benutzergruppen mit Rechten je Modul, Website (Instanz), Sprache und Inhaltsbereich; Datensätze mit Owner; Superuser-Module (Logdateien, Audit-Log) für niemanden sonst | [Kapitel 7](./07-benutzer-und-rechte.md) |
| Manipulationsschutz | Rechte und Benutzerkonten sind signiert; verändert jemand sie direkt in der Datenbank, greifen sie nicht | [Kapitel 12](./12-betrieb.md#protokolle) |
| Anmeldung an der Website | Passwort-Richtlinie, Sperre nach Fehlversuchen je Konto und je Besucher, zweiter Faktor je Profil-Bereich (Authenticator-App oder Code per E-Mail, auch nur auf neuen Geräten), Drosselung und Formularschutz für „Passwort vergessen“ und Registrierung | [Kapitel 13](./13-community.md#zweiter-faktor) |
| Angriffe über den Browser | Schutz gegen Cross-Site-Request-Forgery (Token) für Backend und Frontend-Formulare; Script-Richtlinie (Content Security Policy) im Backend, im Frontend je Website zuschaltbar; Bereinigung von HTML in Inhalten; Sicherheits-Header | [Kapitel 3](./03-instanzen-und-sprachen.md#script-policy), [Kapitel 12](./12-betrieb.md#sicherheits-header) |
| Verschlüsselung | Felder vom Typ „verschlüsselt“ liegen in der Datenbank verschlüsselt (AES-256-GCM), ebenso E-Mail-Adresse und Telefonnummern der Benutzer. Der Schlüssel liegt außerhalb der Datenbank (`.camindo.key`) | [Kapitel 5](./05-eingabefelder.md) |
| Dateien | Geschützte Dateien („Nur für angemeldete Frontend-User“) sind auf dem Server gesperrt und werden erst nach Prüfung der Sitzung ausgeliefert; Bildverarbeitung mit eingeschränkter ImageMagick-Richtlinie | [Kapitel 8](./08-medien.md#ablage-und-auslieferung) |
| Nachvollziehbarkeit | Audit-Log aller Änderungen mit Vorher/Nachher | [unten](#nachvollziehbarkeit-das-audit-log) |

***Grenzen, die Sie kennen sollten:***

- Die ***Zwei-Faktor-Anmeldung per E-Mail-Code*** ist nur so sicher wie das
  E-Mail-Postfach der Benutzer. Stärker ist die Authenticator-App; für Superuser
  lässt sie sich vorschreiben (`security.admin_2fa = 'totp_required'`,
  [Kapitel 12](./12-betrieb.md#zwei-faktor-anmeldung)).
- Die ***Domain-Sperre für Medien*** verhindert, dass Dateien über fremde Websites
  eingebunden werden. Ein Zugriffsschutz ist sie nicht. Vertrauliche Dateien schützt
  nur die Feldoption „Nur für angemeldete Frontend-User“. Mandanten, die gegenseitig nicht an
  ihre Dateien kommen dürfen, brauchen ***getrennte Installationen***
  ([Kapitel 8](./08-medien.md#ablage-und-auslieferung)).
- Superuser haben uneingeschränkten Zugriff. Vergeben Sie diese Rolle sparsam und
  nur an namentlich bekannte Personen, und teilen Sie Zugänge nie.

## Datensicherung und Wiederherstellung

### Was gesichert werden muss

| Bestandteil | Ort | Häufigkeit (Empfehlung) |
|---|---|---|
| Datenbank | per Zeitplaner nach `var/dbbackup/` | täglich |
| Mediendateien | `public/media/` | täglich (inkrementell) |
| Projektdaten | `var/templates/`, `var/custom/`, `var/extensions/`, `var/modules/`, `var/deleted/` | täglich |
| Audit-Log (Inhalte) | `var/audit/` – gehört zur Datenbanksicherung desselben Zeitpunkts | täglich |
| Konfiguration | `.camindo.php`, `composer.json`, `composer.lock` | bei jeder Änderung |
| ***Schlüsseldatei*** | `.camindo.key` | einmalig, **getrennt** von allen anderen Sicherungen |

Nicht gesichert werden müssen `var/cache/`, `var/php/`, `var/tmp/`, `var/export/`
und `vendor/` – sie entstehen neu.

Ohne die Schlüsseldatei sind verschlüsselte Inhalte und alle Signaturen nach einer
Wiederherstellung unbrauchbar. Wer die Schlüsseldatei **zusammen** mit einer
Datenbanksicherung erbeutet, kann die verschlüsselten Felder lesen. Deshalb getrennt
aufbewahren, etwa im Passwort-Tresor der IT.

### Wie gesichert wird

camindo CMS erzeugt die ***Datenbanksicherung*** selbst: eine Aufgabe
„Datenbank-Backup“ im [Zeitplaner](./10-module.md#zeitplaner) schreibt täglich einen
vollständigen Abzug nach `var/dbbackup/` und hält die eingestellte Anzahl vor.
Zusätzlich sichert camindo CMS die Datenbank vor jedem Update automatisch.

Diese Sicherungen liegen auf ***demselben Server***. Sie schützen vor Bedienfehlern und
missglückten Updates, nicht vor dem Verlust des Servers. Für den Betrieb gilt deshalb
die **3-2-1-Regel**: drei Kopien, auf zwei verschiedenen Medien, eine davon außerhalb
des Rechenzentrums. Das übernimmt die Datensicherung Ihres Hosters oder Ihrer IT, die
`var/dbbackup/` und die übrigen Verzeichnisse regelmäßig vom Server abholt.

Jede Sicherung wird nach dem Schreiben geprüft: Ist sie kleiner als 10 KB (dann
enthält sie meist nur eine Fehlermeldung) oder fehlt die Schlusszeile, mit der jeder
vollständige Dump endet, wird sie verworfen und der Lauf gilt als fehlgeschlagen.
Fällt eine Sicherung aus, sehen Superuser das auf der Startseite
([Betriebshinweise](./12-betrieb.md#betriebshinweise-auf-der-startseite)): keine
Sicherungsaufgabe, fehlgeschlagener Lauf, Sicherung älter als zwei Tage oder
unbrauchbar.

### Wiederherstellung

***Einzelne Inhalte*** holen Redakteure innerhalb der Aufbewahrungsfrist
(`admin.trash_retention_days`, Vorgabe 7 Tage) selbst aus dem
[Papierkorb](./10-module.md#papierkorb) zurück – samt Dateien. Wie ein Datensatz
früher aussah, zeigt der Reiter „Änderungen“ (Audit-Log); daraus lassen sich einzelne
Werte von Hand zurückschreiben.

***Ganze Installation*** (etwa nach einem Serverausfall):

1. Server bereitstellen, Code per `composer install` aus der gesicherten
   `composer.lock` installieren.
2. `.camindo.php` und `.camindo.key` zurückspielen.
3. Wartungsmodus einschalten: `php Core/cron/camindo.php maintenance on`.
4. Datenbank anlegen und die Sicherung einspielen, etwa
   `mysql camindo < var/dbbackup/backup-1.sql`.
5. `public/media/` und die Projektverzeichnisse unter `var/` zurückspielen.
6. `php Core/cron/camindo.php upgrade` – gleicht Komponenten ab und baut alle Caches
   neu.
7. Cronjobs einrichten ([Kapitel 1](./01-quickstart-installation.md)), Wartungsmodus
   ausschalten, Startseite des Backends auf Betriebshinweise prüfen.

***Einzelne Bereiche*** lassen sich zusätzlich per
[XML-Export](./09-struktur-export-import.md) sichern und gezielt zurückholen.

### Wiederherstellung üben

Eine Sicherung, deren Wiederherstellung nie geprobt wurde, ist ein Versprechen. Legen
Sie fest,

- wie viel Datenverlust höchstens hinnehmbar ist (Recovery Point Objective – bei
  täglicher Sicherung bis zu 24 Stunden) und
- wie lange die Website höchstens ausfallen darf (Recovery Time Objective),

und spielen Sie mindestens einmal im Jahr eine Sicherung auf einem Testsystem nach
der Anleitung oben ein.

## Updates und Sicherheitsupdates

### Versionen

camindo CMS folgt der semantischen Versionierung `MAJOR.MINOR.PATCH`:

| Stufe | Bedeutung | Aufwand |
|---|---|---|
| ***PATCH*** (9.0.**1**) | nur Fehlerbehebungen, darunter Sicherheitskorrekturen; keine Änderung an der Datenbank | gefahrlos, zeitnah einspielen |
| ***MINOR*** (9.**1**.0) | neue Funktionen; Bestehendes läuft weiter; kann die Datenbank erweitern | einspielen nach kurzer Prüfung |
| ***MAJOR*** (**10**.0.0) | Änderungen, die Zusatzkomponenten oder Templates anpassen lassen können | planen, auf einem Testsystem prüfen |

Sicherheitskorrekturen erscheinen als ***Patch-Version*** für die aktuelle
Hauptversion. Die Freigabehinweise jeder Version nennen, ob sie
sicherheitsrelevante Korrekturen enthält.

### Einspielen

```bash
php Core/cron/camindo.php maintenance on
composer update
php Core/cron/camindo.php upgrade
php Core/cron/camindo.php maintenance off
```

`upgrade` sichert die Datenbank vorher automatisch. Der Rückweg ist in
[Kapitel 12](./12-betrieb.md#updates) beschrieben.

### Organisatorisch

- Legen Sie fest, wer Freigabehinweise verfolgt und wer Updates einspielt
  (Vertretung inklusive).
- Sicherheitskorrekturen innerhalb weniger Tage einspielen, übrige Updates im
  regulären Wartungsfenster.
- Server-Software (PHP, Datenbank, Webserver, ImageMagick, ffmpeg) gehört ebenso dazu.
  Sie wird über das Betriebssystem bzw. den Hoster aktualisiert, nicht über camindo CMS.
- Zusatzkomponenten und Composer-Pakete kommen mit `composer update` mit. Ob es neuere
  Versionen gibt und ob für installierte Pakete Sicherheitsmeldungen vorliegen, prüft
  camindo CMS täglich selbst (`composer outdated` / `composer audit`); das Ergebnis steht
  im Hauptmenü unter „Info“ und als Betriebshinweis auf der Startseite
  ([Kapitel 12](./12-betrieb.md#update-prüfung)).

## Sicherheitslücken melden

### Lücken in camindo CMS

Den Ablauf – Meldeweg, Fristen, unterstützte Versionen – beschreibt die Datei
`Core/SECURITY.md`. Kurz: Sicherheitslücken in camindo CMS melden Sie bitte **vertraulich** an
[camindo@camindo.de](mailto:camindo@camindo.de), nicht über öffentliche Kanäle wie Foren oder
Fehlerverfolgung. Beschreiben Sie, welche Version betroffen ist und wie sich die
Lücke nachvollziehen lässt.

### Ein Meldeweg für Ihre Website: security.txt

Für die Websites, die Sie mit camindo CMS betreiben, sind Sie selbst Ansprechpartner.
Der Standard [RFC 9116](https://www.rfc-editor.org/rfc/rfc9116) sieht dafür eine
Datei `/.well-known/security.txt` vor. Legen Sie sie unter
`public/.well-known/security.txt` an, etwa so:

```
Contact: mailto:security@ihre-firma.de
Expires: 2027-09-30T23:00:00.000Z
Preferred-Languages: de, en
Policy: https://www.ihre-firma.de/sicherheit
```

- `Contact` – eine Adresse, die regelmäßig gelesen wird (kein persönliches Postfach).
- `Expires` – höchstens ein Jahr in der Zukunft; rechtzeitig verlängern.
- `Policy` – optional: wie Sie mit Meldungen umgehen, ob Sie Meldende nennen.

Solange die Datei fehlt, erinnert die Startseite des Backends Superuser daran.

## Datenschutz (DSGVO)

### Welche personenbezogenen Daten camindo CMS speichert

Die vollständige Aufstellung – was, wo, wie lange – steht in
[Kapitel 15 – Datenschutz](./15-datenschutz.md); hier der Überblick.

| Personen | Daten | Wo |
|---|---|---|
| Benutzer | Name, Anmeldename, E-Mail, Telefon (verschlüsselt), Passwort (als Hash), persönliche Einstellungen, Sitzungen, bekannte Geräte mit gekürzter IP-Adresse, Aktivitäten (und, falls eingeschaltet, Anmeldungen) im Audit-Log | Tabellen `admin`, `admin_session`, `login_device`, `audit_log` |
| Website-User (Community) | alle Felder des Profil-Bereichs, die Sie festlegen; ihre Beiträge; Sitzungen, Geräte mit gekürzter IP-Adresse, Warenkorb | Profil- und Beitragsbereiche, `user_session`, `login_device`, `user_basket` |
| Personen in Inhalten | was Redakteure in Inhaltsbereiche eintragen (etwa Ansprechpartner, Teams) | Inhaltsbereiche |
| Alle Besucher | IP-Adresse bei fehlgeschlagenen Anmeldungen; technische Protokolle | `admin_login_attempt`, `user_login_attempt`, `var/log/` |

camindo CMS setzt im Frontend nur technisch notwendige Cookies (Sitzung,
Frontend-Editing, Gerätewiedererkennung bei Anmeldung) und bindet keine Dienste
Dritter ein. Was Ihre Templates zusätzlich laden (Schriften, Karten, Statistik),
liegt in Ihrer Verantwortung.

### Speicherfristen

| Daten | Frist | Einstellung |
|---|---|---|
| Gelöschte Inhalte im Papierkorb | 7 Tage, dann endgültig samt Dateien | `admin.trash_retention_days` |
| Gelöschte Datensätze, Profile und Beiträge | wie die Lösch-Policy des Bereichs es festlegt (Standard: Papierkorb, nach 7 Tagen endgültig; beim Profil nach 7 Tagen alle Felder im Audit-Log geleert; nach 1 Jahr Verlauf gelöscht) | Modul „Datenschutz“ ([Kapitel 15](./15-datenschutz.md#löschen-nach-lösch-policy)) |
| Audit-Log zu bestehenden Inhalten | unbegrenzt (nachvollziehbar bis zur Entstehung) | `audit.retention_days_changes` |
| Audit-Log zu endgültig gelöschten Objekten (außer Datensätzen) | 365 Tage nach dem Löschen | `audit.retention_days_deleted` |
| Bekannte Geräte | 180 Tage nach letzter Nutzung | `security.device_days` |
| Interne Nachrichten zwischen Benutzern | 60 Tage nach dem Versand | `privacy.message_days` |
| Volle IP-Adressen (Audit-Log, Anmeldeversuche, Logdateien) | 7 Tage, danach gekürzt; Geräte und Audit-Dateien speichern sie von Anfang an gekürzt | `privacy.ip_anonymize_days`, `privacy.ip_v4_octets` ([Kapitel 15](./15-datenschutz.md#ip-adressen)) |
| Sitzungen von Website-Usern | 24 Stunden Untätigkeit | `user.max_inactive_sec` |
| Anonyme Warenkörbe | 30 Tage | `user.basket_days` |
| Logdateien, Sicherheitsprotokolle, fehlgeschlagene Anmeldungen | nach Einstellung der Aufgabe „Logs aufräumen“ (Vorgabe 90 / 365 / 30 Tage) | [Zeitplaner](./10-module.md#zeitplaner) |
| Datenbanksicherungen | nach eingestellter Anzahl | Zeitplaner, Aufgabe „Datenbank-Backup“ |

Stimmen Sie diese Fristen mit Ihrem Verzeichnis der Verarbeitungstätigkeiten ab. Denken
Sie daran, dass gelöschte Daten in ***Datenbanksicherungen*** weiterleben, bis die
Sicherung selbst ausläuft. Das ist zulässig, gehört aber in Ihr Löschkonzept.

### Rechte der Betroffenen

| Recht | Umsetzung in camindo CMS |
|---|---|
| ***Auskunft*** (Art. 15) und ***Datenübertragbarkeit*** (Art. 20) | „Datenexport personenbezogener Daten“ im Modul „Datenschutz“ oder am Profil-Datensatz: ein Zip mit lesbarer Auskunft, maschinenlesbarem JSON und den Dateien, einschließlich Beiträgen, Geräten, Sitzungen, Änderungsprotokoll und Speicherfristen. Ein Teilauszug, den Sie um Daten außerhalb des CMS ergänzen ([Kapitel 15](./15-datenschutz.md#datenexport-personenbezogener-daten)) |
| ***Berichtigung*** (Art. 16) | Bearbeiten des Profils im Backend oder durch den Frontend-User selbst, wenn das Template es anbietet |
| ***Löschung*** (Art. 17) | Profil löschen – was dann mit Profil, Beiträgen, Dateien und Audit-Log geschieht, legt die Lösch-Policy fest; der Papierkorb eines Users lässt sich sofort leeren (Modul „Werkzeuge“). Den Löschweg für Frontend-User selbst bietet `CMS:USER_DELETE` |
| ***Einschränkung*** (Art. 18) | Status des Profils auf „Deaktiviert“ setzen: die Anmeldung ist gesperrt, die Daten bleiben |

Für Benutzer gilt dasselbe sinngemäß: Ihre Daten stehen in der
Benutzerverwaltung; gelöschte Benutzer bleiben als Datensatz erhalten, damit
die Angabe „geändert von“ in Inhalten und im Audit-Log gültig bleibt. Ihren
***Datenexport*** erstellt, wer das Recht dazu im Modul „Datenschutz“ hat
([Kapitel 15](./15-datenschutz.md#datenexport-personenbezogener-daten)).

### Technische und organisatorische Maßnahmen

Für Ihre TOM-Dokumentation (Art. 32) können Sie auf die Übersicht
[Sicherheit im Überblick](#sicherheit-im-überblick) zurückgreifen. Beim Betreiber
bleiben: Hosting mit Auftragsverarbeitungsvertrag, HTTPS, Zugangsregelung zum Server,
Datensicherung außerhalb des Servers, Update-Prozess, Rollen- und Rechtekonzept.

## Nachvollziehbarkeit: das Audit-Log

Das [Audit-Log](./10-module.md#audit-log) beantwortet die Frage „Wer hat wann was
geändert?“ – für Inhalte ebenso wie für Struktur, Templates, Benutzer und Rechte:

- Jede Änderung mit Zeitpunkt, handelnder Person (Benutzer, Website-User oder
  System), Herkunft (Backend, Frontend, API, KI-Assistent, Import, Stapelverarbeitung, Zeitplaner) und
  IP-Adresse.
- Vorher und Nachher **aller** geänderten Felder, bei Texten absatzweise
  gegenübergestellt.
- Fehlgeschlagene Anmeldungen und Datenauskünfte; erfolgreiche An- und Abmeldungen
  nur, wenn Sie es einschalten (`audit.log_logins`).

Einträge lassen sich über die Oberfläche weder ändern noch löschen; sie verschwinden
nur nach den eingestellten Fristen.

***Ablage und Fälschungsschutz:*** Die Datenbank enthält nur, wer wann was an welchem
Objekt getan hat und welche Felder betroffen waren. Die Inhalte – Vorher und Nachher –
stehen im Dateisystem unter `var/audit/`, je Objekt eine Datei, an die nur angehängt
wird (bei mehr als 1 MB beginnt eine weitere Seite). Jede Zeile trägt eine Prüfsumme,
die die vorige einschließt (***HMAC-Kette***): Wird eine Zeile geändert, entfernt oder
eingeschoben, fällt es auf – fälschen kann die Kette nur, wer den Schlüssel
`.camindo.key` hat. Wer nur Zugriff auf die Datenbank hat, kann die Inhalte weder
lesen noch ändern; gelöschte, hinzugefügte oder veränderte Einträge der Datenbank
erkennt der Abgleich mit den Dateien – auch wenn dort nur Zeitpunkt, Aktion,
Handelnder oder Bezeichnung umgeschrieben wurden. Geprüft wird jede Nacht von der Zeitplaner-Aufgabe „Audit-Log prüfen“ (Betriebshinweis auf der Startseite bei Befunden), in der
Detailansicht jedes Eintrags und jederzeit mit `php Core/cron/camindo.php audit verify`.
Dessen Ergebnis ersetzt das der nächtlichen Prüfung; nach `audit reseal` oder der Prüfung
eines einzelnen Objekts wird der Hinweis auf der Startseite für dieses Objekt nachgeführt.

***Datenbanksicherung zurückspielen:*** Die Dateien unter `var/audit/` gehören nicht zum
Datenbank-Dump. Nach dem Zurückspielen enthalten sie Einträge, die es in der Datenbank
nicht mehr gibt, und die Datenbank vergibt deren Nummern neu. Führen Sie deshalb gleich
danach – bevor jemand wieder arbeitet – `php Core/cron/camindo.php audit restore` aus
(mit `--dry-run` nur zählen). Der Befehl entfernt alle Einträge, die neuer sind als der
Stand der Datenbank, vermerkt das in der Datei als Kontrollpunkt und legt die
betroffenen Dateien vorher unverändert unter `var/audit-restore-{Zeitpunkt}/` ab.

***Gebrochene Kette neu verketten:*** Meldet `audit verify` bei einem Objekt nur
„chain“ – die Einträge selbst sind echt, nur ihre Verkettung stimmt nicht, etwa weil
eine Datei ersetzt oder aus einer Sicherung zurückgeholt wurde –, prüfen Sie den
Vorgang und verketten danach neu:
`php Core/cron/camindo.php audit reseal template 433 --reason="…"` (mit `--dry-run`
nur anzeigen). Die Einträge bis zur Bruchstelle bleiben unverändert, der Rest wird hinter
einem vermerkten Kontrollpunkt neu verkettet; die alten Dateien liegen danach unter
`var/audit-reseal-{Zeitpunkt}/`. Ist ein Eintrag selbst verändert („hmac“), verweigert
der Befehl das – mit `--force` geht es trotzdem, der Inhalt dieses Eintrags gilt dann
aber als nicht belegt.
Das Audit-Log ist damit manipulationserkennend – „revisionssicher“ im Sinne der GoBD
wäre es erst mit einem zusätzlichen, nicht veränderbaren Speicher. Redakteure sehen die letzten Änderungen der
Datensätze, die sie lesen dürfen, im Reiter „Änderungen“; die gesamte Recherche ist
Superusern vorbehalten. Datenexporte erstellen nur Benutzer mit dem
Zusatzrecht „Personenbezogene Daten exportieren“ (Modul „Datenschutz“).

Das Audit-Log enthält selbst personenbezogene Daten (Nummern der Handelnden,
IP-Adressen, frühere Inhalte). Nennen Sie es in Ihrem Verzeichnis der Verarbeitungstätigkeiten, mit dem
Zweck „Nachvollziehbarkeit und Sicherheit“ und den gewählten Fristen.

## Betriebsüberwachung

- ***Betriebshinweise auf der Startseite:*** Superuser sehen unter den Modulkacheln,
  wenn der Cronjob steht, temporäre Dateien nicht gelöscht werden, die
  Datenbanksicherung fehlt oder fehlschlug, der Speicherplatz knapp wird,
  Verzeichnisse nicht beschreibbar sind, die Konfiguration für den Live-Betrieb
  nicht passt oder die Update-Prüfung Sicherheitslücken bzw. neue Versionen meldet
  ([Kapitel 12](./12-betrieb.md#betriebshinweise-auf-der-startseite)).
- ***Logdateien:*** Fehler, gesperrte Anmeldungen, nicht zugestellte E-Mails und
  Sicherheitsereignisse im Modul „[Logdateien](./10-module.md#logdateien)“, mit Widget
  für die Startseite.
- ***Kommandozeile:*** `php Core/cron/camindo.php status` für die externe Überwachung.

Die Hinweise erscheinen nur, wenn jemand die Startseite öffnet. Für eine
Benachrichtigung ohne Anmeldung binden Sie den Server in Ihre bestehende
Überwachung ein (Erreichbarkeit der Website, Alter von `var/dbbackup/backup-1.sql`,
freier Speicher).

## Aufgaben des Betreibers

- ![checkbox](../_/check_box_outline_blank.svg) Verantwortliche benannt: Betrieb, Updates, Datenschutz, Sicherheitsmeldungen
- ![checkbox](../_/check_box_outline_blank.svg) Hosting mit Auftragsverarbeitungsvertrag, HTTPS, aktuelle Server-Software
- ![checkbox](../_/check_box_outline_blank.svg) Tägliche Datenbanksicherung im Zeitplaner, Abholung aller Sicherungen außerhalb
      des Servers, `.camindo.key` getrennt verwahrt
- ![checkbox](../_/check_box_outline_blank.svg) Wiederherstellung einmal jährlich geprobt
- ![checkbox](../_/check_box_outline_blank.svg) Update-Prozess festgelegt; Sicherheitskorrekturen innerhalb weniger Tage
- ![checkbox](../_/check_box_outline_blank.svg) `public/.well-known/security.txt` angelegt und jährlich verlängert
- ![checkbox](../_/check_box_outline_blank.svg) Aufgabe „Logs aufräumen“ eingerichtet, Fristen im Löschkonzept dokumentiert
- ![checkbox](../_/check_box_outline_blank.svg) Fristen des Audit-Logs festgelegt und im Verzeichnis der
      Verarbeitungstätigkeiten genannt
- ![checkbox](../_/check_box_outline_blank.svg) Ablauf für Auskunfts- und Löschersuchen festgelegt (wer, wie schnell, auf
      welchem Weg die Auskunft übermittelt wird)
- ![checkbox](../_/check_box_outline_blank.svg) Rechtekonzept: Superuser nur wenige, namentlich; Gruppenrechte nach dem
      Prinzip der geringsten Rechte; Zugänge ausgeschiedener Mitarbeiter sofort sperren
- ![checkbox](../_/check_box_outline_blank.svg) Checkliste [Live-Betrieb](./12-betrieb.md#checkliste-für-den-live-betrieb)
      abgearbeitet
