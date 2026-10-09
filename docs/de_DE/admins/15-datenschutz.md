# 15 Datenschutz

Dieses Kapitel beschreibt im Einzelnen, welche personenbezogenen Daten camindo CMS
speichert, wo sie liegen und wie lange sie bleiben – getrennt nach ***Benutzern***
(Ihren Mitarbeitern im Backend), ***Website-Usern*** (Frontend-Community) und
***Besuchern*** ohne Konto. Es ist die Grundlage für Ihr Verzeichnis der
Verarbeitungstätigkeiten und Ihre Datenschutzerklärung. Die Übersicht für
Entscheider steht in [Kapitel 14](./14-sicherheit-und-compliance.md#datenschutz-dsgvo).

- [Benutzer (Mitarbeiter)](#benutzer-mitarbeiter)
- [Website-User (Community)](#website-user-community)
- [Besucher ohne Konto](#besucher-ohne-konto)
- [IP-Adressen](#ip-adressen)
- [Löschen nach Lösch-Policy](#löschen-nach-lösch-policy)
- [Datenexport personenbezogener Daten](#datenexport-personenbezogener-daten)
- [Was außerhalb von camindo CMS liegt](#was-außerhalb-von-camindo-cms-liegt)
- [Checkliste](#checkliste)

Alle Fristen sind Vorgaben; die Schlüssel stehen in `.camindo.php` (siehe
[Kapitel 12](./12-betrieb.md#konfiguration)). Gelöschte Daten leben in
***Datenbanksicherungen*** weiter, bis die Sicherung selbst ausläuft – das gehört in
Ihr Löschkonzept.

## Benutzer (Mitarbeiter)

| | Daten | Wo | Wie lange |
|---|---|---|---|
| Unverschlüsselte Benutzerdaten | Anmeldename, Namen (Vor-, Anzeige-, Sortiername), Benutzergruppe, Status (freigeschaltet/gesperrt/gelöscht) | Tabelle `admin` | solange das Konto besteht; gelöschte Benutzer bleiben erhalten, damit „erstellt/geändert von“ in Inhalten und im Audit-Log gültig bleibt – bis sie [anonymisiert](#gelöschte-benutzer-anonymisieren) werden |
| Verschlüsselte Benutzerdaten | E-Mail, Mobil, Telefon | `admin`, verschlüsselt | wie oben |
| Passwort | Hash (nicht umkehrbar)<br>Tag der letzten Änderung (ohne Uhrzeit) | `admin` | wie oben |
| 2FA-Daten | Authenticator-App, Passkeys (Bezeichnung, Zeitpunkt), Wiederherstellungscodes | `admin` (Schlüssel verschlüsselt), `admin_passkey`, `admin_recovery_code` (nur Hashes) | bis zum Entfernen; Passkeys und Wiederherstellungscodes mit dem Löschen des Kontos; der Schlüssel der App beim Anonymisieren |
| Persönliche Einstellungen | Sprache, Zeitzone, Widgets, Berichte … | `admin.settings` | solange das Konto besteht |
| Sitzungsdaten | Oberfläche, letzter Zugriff | `admin_session` | bis zur Abmeldung bzw. `admin.max_inactive_sec` (30 Minuten) Untätigkeit; mit dem Löschen des Kontos sofort |
| Bekannte Geräte | Browser/Betriebssystem, ***gekürzte*** IP-Adresse, Zeitpunkt der ersten und letzten Anmeldung | `login_device` | `security.device_days` (180 Tage) nach der letzten Nutzung; mit dem Löschen des Kontos sofort |
| Letzte Anmeldung | Tag (ohne Uhrzeit) | `admin.login_date` | wird bei jeder Anmeldung überschrieben |
| Laufende Anmeldung | Zeitpunkt, zu dem die Zwei-Faktor-Anmeldung begann (Code-Versand) | `admin.uid_date` | nur bis zum Abschluss der Anmeldung, danach geleert |
| Aktivitäten | was der Benutzer wann geändert hat, mit IP-Adresse | Audit-Log: `audit_log` (Metadaten), `var/audit/` (Inhalte) | `audit.retention_days_changes` (unbegrenzt); Einträge zu Datensätzen nach der Lösch-Policy ihres Bereichs; die IP-Adresse wird nach `privacy.ip_anonymize_days` (7 Tage) gekürzt |
| Fehlgeschlagene Anmeldungen (Audit-Log) | Nummer bzw. eingegebener Name, IP-Adresse, Zeitpunkt, Oberfläche (Backend, PHP-API, Kommandozeile)<br>(bei erkanntem Konto unter dessen Nummer, sonst mit dem eingegebenen Namen) | Audit-Log | `audit.retention_days_logins` (90 Tage) |
| „Erstellt von“,<br>„Geändert von“,<br>Owner | Zeitpunkte | Tabelle `record` | solange der Datensatz besteht |
| Historie | Zuletzt geöffnete Datensätze und Templates, Zeitpunkt | `history` | nur die jeweils jüngsten Einträge, ältere fallen automatisch weg; mit dem Löschen des Kontos alle |
| Interne Nachrichten | Absender, Empfänger, Betreff, Text verschlüsselt, Zeitpunkt | `message` | `privacy.message_days` (60 Tage) nach dem Versand – für beide Seiten, auch ungelesen; vorher, sobald Absender und Empfänger sie gelöscht haben |
| Erfolgreiche An- und Abmeldungen | Zeitpunkt, IP-Adresse, Oberfläche | Audit-Log – nur mit `audit.log_logins = true` | Vorgabe: nicht aufgezeichnet; eingeschaltet `audit.retention_days_logins` (90 Tage) |
| Fehlgeschlagene Anmeldungen (Sperre) | Zeitpunkt, eingegebener Name, IP-Adresse | `admin_login_attempt`; beim Sperren eine Zeile im Log `security` | in der Tabelle nur für die Sperrdauer `admin.bruteforce_delay` (10 Minuten), im Log nach der Aufgabe „Logs aufräumen“; IP-Adresse gekürzt nach `privacy.ip_anonymize_days` |
| Hinweis-E-Mails | Neues Gerät, Passwort geändert – mit voller IP-Adresse | Postfach des Benutzers | nicht in camindo CMS gespeichert |

Benutzernamen, die mit `admin_` beginnen, sind für anonymisierte Konten reserviert und
lassen sich nicht neu vergeben. Den Datenexport eines Benutzers erstellt, wer im Modul
„Datenschutz“ das Recht dazu hat (siehe [Datenexport](#datenexport-personenbezogener-daten)).

Fehlgeschlagene Anmeldungen stehen immer im Audit-Log, nach mehreren Fehlversuchen
zusätzlich im Log `security`. Beides wird nach `audit.retention_days_logins` gelöscht;
im Datenexport steht bei jedem Eintrag der Tag seiner Löschung.

***Anmeldezeiten:*** Reine Anmeldungen ohne Tätigkeit sagen nichts über Änderungen,
wohl aber über die Arbeitszeiten Ihrer Mitarbeiter. camindo CMS zeichnet erfolgreiche
An- und Abmeldungen deshalb nur auf, wenn Sie `audit.log_logins` einschalten;
die Standardeinstellung ist `false`.

***Was Sie als Arbeitgeber beachten sollten:*** Das Audit-Log zeigt, wer wann was
geändert hat. Das ist für Nachvollziehbarkeit und Sicherheit gedacht, kann aber zur
Leistungs- und Verhaltenskontrolle genutzt werden. Regeln Sie Zweck und Zugriff
(Recherche nur für Superuser) in einer Betriebsvereinbarung bzw. internen
Richtlinie und informieren Sie Ihre Mitarbeiter.

### Ausgeschiedener Mitarbeiter

Ein Benutzerkonto besteht nach dem Löschen weiter, taucht aber nicht mehr in der Benutzerliste auf.
Anmeldung ist nicht mehr möglich. Zweck: Nachvollziehbarkeit – Datensätze, Templates und das
Audit-Log verweisen auf ihn.

Ein Superuser kann die Nachrichten eines „gelöschten“ Benutzers löschen: Benutzer
öffnen → Reiter „Einstellungen“ → ***Interne Nachrichten*** → „Alle Nachrichten
löschen“. Empfangene Nachrichten werden endgültig gelöscht; gesendete verschwinden
aus seinem Postausgang, die Empfänger behalten ihre Fassung. Der Vorgang steht im
Log `security`. Geräte und Sitzungen entfernen Sie im Reiter „Geräte und Sitzungen“.

#### Gelöschte Benutzer anonymisieren

Sobald diese Nachvollziehbarkeit nicht mehr gebraucht wird (oder der ehemalige Mitarbeiter
die Löschung verlangt), entfernen Sie die Person mit ***Anonymisieren***:
Modul „Datenschutz“ → Reiter „Gelöschte Benutzer“ → Benutzer anklicken →
auf seiner Infoseite „Anonymisieren“. Die Schaltfläche sehen Superuser und
Benutzergruppen mit dem Zusatzrecht „Gelöschte Benutzer anonymisieren“ (für Benutzer der
Gruppen, die sie im Modul „Benutzer“ sehen).

| Daten | Anonymisierte Form |
|---|---|
| Benutzername | `admin_{Id}`, etwa `admin_42` – überall, wo der Name erscheint, auch bei „erstellt/geändert von“ |
| Vor-, Anzeige- und Sortiername, E-Mail, Telefon, Mobil, persönliche Einstellungen | gelöscht |
| Passwort, Authenticator-App, Passkeys, Wiederherstellungscodes, Sitzungen, Geräte | gelöscht – das Konto kann sich nicht mehr anmelden |
| Audit-Log: Einträge, in denen er gehandelt hat | erscheinen mit `admin_{Id}` – das Audit-Log speichert Handelnde nur als Nummer und zeigt den heutigen Namen; die Änderungen selbst bleiben |
| Audit-Log seines Kontos | Bezeichnung `admin_{Id}`, die Werte der persönlichen Felder in Vorher/Nachher gelöscht |
| Fehlgeschlagene Anmeldungen unter seinem Namen | `admin_{Id}` statt des Namens |

Weil das Audit-Log Handelnde nur als Nummer speichert, dauert das Anonymisieren auch bei
Benutzern mit langer Vorgeschichte nur einen Augenblick. Bricht es dennoch ab, starten
Sie es einfach erneut – erst der Name `admin_{Id}` zeigt, dass es abgeschlossen ist.

Das lässt sich nicht rückgängig machen. Der Vorgang steht im Audit-Log des Kontos
(„Datenschutz“) und im Log `security`. Die Liste zeigt anonymisierte Benutzer mit dem
Hinweis „anonymisiert“.

## Website-User (Community)

| | Daten | Wo | Wie lange |
|---|---|---|---|
| Persönliche Daten verschlüsselt | Alle Felder des Profils, die als verschlüsselte Felder eingerichtet wurden (i.d.R. alle mit persönlichen Daten, inkl. Benutzername, E-Mail-Adresse) | Profil-Bereich (Tabellen `record`, `data_enc`) | bis zum Löschen des Profils, danach nach seiner [Lösch-Policy](#löschen-nach-lösch-policy) – mit der Standard-Policy: `admin.trash_retention_days` (7 Tage) im Papierkorb, danach endgültig samt Dateien |
| Persönliche Daten unverschlüsselt | Alle Felder des Profils, die als unverschlüsselte Felder eingerichtet wurden, Zeitpunkte der Erstellung und letzten Änderung | Profil-Bereich (Tabellen `record`, `data_*`), `media/` | wie oben |
| Passwort | Hash | Profil-Bereich (Tabellen `record`, `data_pwd`) | wie oben | 
| Beiträge | Kommentare, Bewertungen … und hochgeladene Dateien, Zeitpunkte der Erstellung und letzten Änderung | Beitrags-Bereiche (Tabellen `record`, `data_*`), `media/` | nach der Lösch-Policy des Beitrags-Bereichs; mit den Standard-Policies wie das Profil, sobald es gelöscht wird |
| Datensätze, die auf das Profil verweisen | Verknüpfungen | jeweiliger Bereich | nach Ihren eigenen Regeln (etwa handels- und steuerrechtliche Fristen) |
| Bekannte Geräte | Gerät, ***gekürzte*** IP-Adresse, Zeitpunkt der ersten und letzten Anmeldung | `login_device` | `security.device_days` (180 Tage) nach letzter Nutzung; mit dem Löschen des Profils sofort, gleich nach welcher Policy |
| Sitzungsdaten | Session-ID, letzter Zugriff | `user_session` | `user.max_inactive_sec` (24 Stunden) Untätigkeit; mit dem Löschen des Profils sofort |
| 2FA-Daten | Art, Zeitpunkt der ersten Verwendung | `user_second_factor` | bis zum Entfernen bzw. Löschen des Profils |
| Offene Bestätigungs- und Passwort-Links | Token, Zeitpunkt | `user_token` | bis zur Nutzung bzw. zum Ablauf (`user.token_expiration`, `user.password_token_expiration`) |
| Nie bestätigte Registrierungen | Angaben aus dem Registrierungsprozess, Zeitpunkt | Profil-Bereich | `user.unconfirmed_days` (7 Tage) |
| Warenkorb | Datensatz-Ids (Produkte, Abos, …), Zeitpunkt der letzten Änderung | `user_basket` | angemeldet: mit der Sitzung; anonym: `user.basket_days` (30 Tage) |
| Änderungsprotokoll | Audit-Log zum Profildatensatz und Beiträgen, mit IP-Adresse, Zeitpunkt | Audit-Log | nach der Lösch-Policy; mit den Standard-Policies beim Profil nach 7 Tagen alle Felder geleert, nach 1 Jahr gelöscht. Der User steht nur mit der Nummer seines Profils darin ([anonym](#anonym-durch-das-profil), sobald das Profil gelöscht ist). IP-Adresse gekürzt nach `privacy.ip_anonymize_days` |
| Fehlgeschlagene Anmeldungen | Anmeldename, Zeitpunkt, IP nur mit `user.bruteforce_ip` | `user_login_attempt` | für die Sperrdauer `user.bruteforce_delay` |

Anmeldenamen, die mit `user_` beginnen, sind für anonymisierte Profile reserviert:
bei der Registrierung und im Backend abgelehnt – erlaubt ist nur `user_{record_id}`
des Profils selbst, wie es eine Lösch-Policy einsetzt.

Den Datenexport eines Website-Users erstellt, wer im Modul „Datenschutz“ das Recht dazu
hat (siehe [Datenexport](#datenexport-personenbezogener-daten) und das
[Handbuch für Anwender](../editors/10-community.md#datenexport-personenbezogener-daten)).

## Besucher ohne Konto

camindo CMS setzt im Frontend nur technisch notwendige Cookies (Sitzung, Frontend-Editing,
Gerätewiedererkennung bei der Anmeldung) und bindet keine Dienste Dritter ein.
Was Ihre Templates zusätzlich laden (Schriften, Karten, Statistik), liegt in Ihrer
Verantwortung.

| | Daten | Wo | Wie lange |
|---|---|---|---|
| Drosselung<br>(Formulare,<br>Anmeldung,<br>CSP-Berichte) | IP-Adressen nur als ***Hash***, nie im Klartext | Tabelle des RateLimiters | wenige Minuten bis Stunden (Zeitfenster der Drosselung) |
| Gesperrte Anmeldungen,<br>abgewiesene Formulare | Anmeldename, IP-Adresse, Zeitpunkt | Log `security` in `var/log/` | Aufgabe „Logs aufräumen“ (Vorgabe 365 Tage); IP-Adressen gekürzt nach `privacy.ip_anonymize_days` |
| Script-Verstöße (CSP-Berichte) | Script-URL, Zeitpunkt, (keine IP-Adresse) | Log `csp` | wie oben |
| Formulareingaben | Teils persönliche Daten | je nach Template: als Datensatz oder E-Mail, je nach Bereich verschlüsselt / unverschlüsselt | nach den Lösch-Regeln des Bereichs, bzw. Postfachs |

## IP-Adressen

IP-Adressen sind personenbezogene Daten. Das berechtigte Interesse an der
***vollständigen*** Adresse – Angriffe erkennen und abwehren – trägt nach verbreiteter
Auffassung nur wenige Tage. camindo CMS hält es deshalb so:

| | Wo | Was gespeichert wird |
|---|---|---|
| Bekannte Geräte | `login_device` | von Anfang an nur die gekürzte Adresse |
| Inhalte des Audit-Logs | `var/audit/` | von Anfang an nur die gekürzte Adresse – die Dateien sind fälschungsgeschützt und werden nur ergänzt, nachträglich kürzen ginge nicht |
| Metadaten des Audit-Logs | `audit_log` | die volle Adresse, gekürzt nach `privacy.ip_anonymize_days` (7 Tage) |
| Fehlgeschlagene Anmeldungen | `admin_login_attempt`, `user_login_attempt` | die volle Adresse für die Sperrdauer; gekürzt nach derselben Frist, falls sie länger stehen |
| Logdateien | `var/log/` | die volle Adresse; jede Logdatei, deren Tag älter ist als die Frist, wird gekürzt |
| Drosselung | RateLimiter-Tabelle | nur ein Hash, nie die Adresse |

Welche Adresse „die des Besuchers“ ist, bestimmt an allen Stellen dieselbe Regel:
`REMOTE_ADDR`, hinter einem eingetragenen Proxy (`security.trusted_proxies`) die
Adresse aus `X-Forwarded-For`. Ohne diesen Eintrag steht hinter einem Proxy überall die
Adresse des Proxys.

***Wie gekürzt wird:***

- ***IPv4:*** das letzte Oktett wird genullt (`203.0.113.45` → `203.0.113.0`), mit
  `privacy.ip_v4_octets = 2` die letzten beiden (`203.0.0.0`).
- ***IPv6:*** die letzten 80 Bit werden genullt, es bleiben die ersten 48 Bit – das
  Netz des Anbieters, nicht der einzelne Anschluss
  (`2001:db8:abcd:12::1` → `2001:db8:abcd::`).

***Einstellung später ändern:*** Erhöhen Sie `privacy.ip_v4_octets` von 1 auf 2, kürzt
der nächste Lauf der Aufgabe „Aufbewahrungsfristen anwenden“ alle bereits gekürzten
Adressen weiter – in der Datenbank, den Logdateien und den Inhalten des Audit-Logs
(`203.0.113.0` → `203.0.0.0`, angezeigt als `203.0.xxx.xxx`). Senken bringt nichts
zurück: Was einmal genullt ist, bleibt es; die Anzeige markiert weiterhin beide Stellen.

***Angezeigt*** werden die gekürzten Stellen als `xxx` – in der Datenauskunft, in der
Recherche und im Reiter „Änderungen“ des Audit-Logs und in der Geräteliste
(`203.0.113.xxx`, `2001:db8:abcd:xxxx:xxxx:xxxx:xxxx:xxxx`). So hält niemand die Null
für einen Teil der Adresse. Gespeichert bleibt die Adresse mit Nullen: nur so ist sie
weiterhin eine gültige IP-Adresse, die sich vergleichen und – nach einer Änderung von
`privacy.ip_v4_octets` – weiter kürzen lässt.

Das Kürzen erledigt die Zeitplaner-Aufgabe ***„Aufbewahrungsfristen anwenden“***
(von der Installation eingerichtet, jede Nacht). Jeder Lauf nimmt nur die Einträge
seit dem vorigen Lauf und nutzt die Indizes der Zeitspalten. Logdateien werden
zeilenweise umgeschrieben; das hält es auch bei großen Datenbeständen schnell – unter
Linux wie unter Windows. Der erste Lauf nach dem Update geht einmal über den ganzen
Bestand, ebenso nach einer Änderung von `privacy.ip_v4_octets`.

***Einmalig nach dem Update:*** Einträge in `var/audit/`, die vor diesem Update
geschrieben wurden, enthalten noch die volle Adresse. Kürzen Sie sie, am besten im
Wartungsmodus, mit

```
php Core/cron/camindo.php audit anonymize-ip --dry-run   # nur zählen
php Core/cron/camindo.php audit anonymize-ip
```

Der Befehl schreibt die Dateien mit neu berechneter Prüfsummenkette und vermerkt das
als Kontrollpunkt „anonymize“. Dateien, deren Kette schon vorher gebrochen ist, lässt er
unverändert und nennt sie; prüfen Sie diese mit `audit verify` (siehe
[Kapitel 14](./14-sicherheit-und-compliance.md#nachvollziehbarkeit-das-audit-log)).

***Bei einem konkreten Angriff:*** Werden die vollen Adressen zur weiteren Verfolgung
eines bereits erkannten Angriffs zwingend gebraucht, setzen Sie
`privacy.ip_anonymize_days` vorübergehend hoch. Das hält nur das Kürzen an – bereits
Gekürztes kommt nicht zurück. Sichern Sie die nötigen Einträge (etwa Export aus dem
Audit-Log, Kopie der Logdatei) an einem geschützten Ort, dokumentieren Sie Anlass und
Dauer und stellen Sie die Frist danach wieder zurück.

Die Frist von 7 Tagen ist eine Vorgabe, keine Rechtsberatung. Stimmen Sie sie mit
Ihrem Datenschutzbeauftragten ab.

## Löschen nach Lösch-Policy

Was „löschen“ bei einem Datensatz heißt, bestimmt die ***Lösch-Policy*** seines
Inhaltsbereichs. Jeder Inhaltsbereich (Datensätze, Profile, Beiträge) hat genau eine;
Sie wählen sie in den Einstellungen des Bereichs (siehe
[Kapitel 4](./04-struktur.md)) und verwalten sie im Modul „Datenschutz“
![shield](../_/shield.svg) → „Lösch-Policies“.

Die Liste zeigt je Policy ***Instanz***, ***Label*** und die ***Kategorien*** ihrer
Blöcke. Ein Klick öffnet die ***Infoseite*** (mit den Bereichen, die die Policy
benutzen, und dem Reiter „Änderungen“), ein Rechtsklick das Kontextmenü mit
„Info“, „Bearbeiten“ und „Löschen“.

### Aufbau einer Policy

Eine Policy hat ein ***Label*** (Text oder Snippet), gilt für eine Instanz (oder für
alle) und besteht aus beliebig vielen ***Blöcken***. Jeder Block sagt: bei welchem
***Auslöser***, zu welchem ***Zeitpunkt***, welche ***Aktion***. Dazu wählen Sie eine
***Kategorie***, die die Aktion einordnet – sie dient nur der Übersicht und hat keine
Wirkung:

| Kategorie | Typisch für |
|---|---|
| In den Papierkorb legen | In den Papierkorb |
| Nicht mehr zwingend benötigte Daten entfernen | Einzelne Felder überschreiben (Datenminimierung) |
| Eintrag vollständig anonymisieren | Felder im Audit-Log überschreiben |
| Endgültig löschen | Endgültig löschen, samt Audit-Log; Audit-Log endgültig löschen |

| Auslöser | Wann |
|---|---|
| Datensatz wird gelöscht | gleich auf welchem Weg: Löschdialog der Eingabemaske, Stapelverarbeitung, API `record.delete` oder [CMS:USER_DELETE](../templateprogrammers/user-delete.md) im Frontend – auch für die [Unterdatensätze](#unterdatensätze), die mit ihm gelöscht werden |
| UGC: Profil wurde gelöscht | ein Profil wurde gelöscht – einzeln, per Stapelverarbeitung oder mit seinem ganzen Profil-Bereich. Das löst ***immer*** und sofort die Policies aller Datensätze dieses Users aus (Beiträge, Bewertungen …), jeweils die Policy ihres eigenen Bereichs. Hat sie keinen Block mit diesem Auslöser, bleiben die Datensätze stehen. Das lässt sich nicht rückgängig machen: Wird das Profil (oder sein Bereich) wiederhergestellt, holen Sie Beiträge im Papierkorb von Hand zurück |
| Bereich wurde gelöscht | der ganze Inhaltsbereich wurde gelöscht; seine Datensätze liegen dann schon mit ihm im Papierkorb. Darum nicht für „In den Papierkorb“ und „Einzelne Felder überschreiben“. Auch sofort fällige Blöcke laufen erst mit der Zeitplaner-Aufgabe „Lösch-Policies anwenden“; wird der Bereich wiederhergestellt, verfallen sie |

***Zeitpunkt:*** sofort (0) oder nach einer Anzahl Tage bzw. Jahre, gerechnet ab dem
Auslösen.

Die Aktionen sind in der Auswahl nach dem gruppiert, was sie betreffen:

| Gruppe | Aktion | Was geschieht |
|---|---|---|
| Datensatz | In den Papierkorb (wird nach … Tagen endgültig gelöscht) | der Datensatz kommt in den Papierkorb dessen, der löscht, und wird nach `admin.trash_retention_days` (Vorgabe 7 Tage) endgültig gelöscht – samt Mediendateien. Die Auswahl nennt die eingestellte Frist |
| Datensatz | Einzelne Felder überschreiben | Felder des Datensatzes bekommen neue Werte, z. B. `status_id = "4"`, `username = "user_{record_id}"`, `kontonummer = ""`. Mehrsprachige Felder bekommen den Wert in allen Sprachen. Ein Upload-Feld nimmt nur `""` an: Die Datei wird samt ihrer Thumbnails sofort vom Server entfernt (auch bei mehrsprachigen Uploads in allen Sprachen) |
| Datensatz | Trigger-Template auf den Datensatz anwenden | die Methode `onPrivacyAction()` im [Trigger-Template](../templateprogrammers/03-trigger-templates.md#onprivacyaction-lösch-policies) des Bereichs bekommt den Datensatz – mehrsprachige Felder je Sprache – und kann ihn ändern; Änderungen werden gespeichert. Für alles, was „Einzelne Felder überschreiben“ nicht ausdrücken kann: verknüpfte Datensätze, mehrsprachige Texte je Sprache, eigene Regeln |
| Audit-Log | Felder im Audit-Log überschreiben | im Verlauf des Datensatzes (Datenbank und `var/audit/`) werden Vorher und Nachher der genannten Felder ersetzt, bei mehrsprachigen Feldern in allen Sprachen. Zusätzlich: `_label` (Bezeichnung des Eintrags) und `*` (alle übrigen Felder – gleich, an welcher Stelle die Zeile steht). Auch spätere Einträge – etwa der letzte Stand beim endgültigen Löschen – werden so geschrieben |
| Audit-Log | Trigger-Template auf das Audit-Log anwenden | `onPrivacyAction()` im Trigger-Template bekommt jeden Eintrag im Verlauf des Datensatzes und kann Bezeichnung und die Werte vorher/nachher ändern; Geändertes wird zurückgeschrieben |
| Audit-Log | Audit-Log endgültig löschen | der ganze Verlauf des Datensatzes |
| Alles | Endgültig löschen, samt Audit-Log | ohne Papierkorb: der Datensatz, seine Unterdatensätze, ihre Dateien und ihr ganzer Verlauf im Audit-Log sind weg |

Die Felder schreiben Sie je Zeile als `feld = "wert"`. Platzhalter im Wert:
`{record_id}` (der Datensatz) und `{user_id}` (der User, dem der Datensatz gehört).
Ein Feld, das es im Bereich nicht gibt, wird übergangen; ein Thumbnail-Feld allein
lässt sich nicht leeren – es verschwindet mit seinem Upload-Feld.

Die beiden Trigger-Aktionen brauchen eine ***Kennung*** (Kleinbuchstaben, Ziffern,
`_`), die das Template als `$action` erhält – so unterscheidet es mehrere Blöcke. Hat
der Bereich mehrere Trigger-Templates, läuft `onPrivacyAction()` in jedem, das die
Methode enthält, nacheinander. Hat keines die Methode, scheitert der Auftrag und
steht im Log `privacy`.
Ob Blöcke einander widersprechen oder doppelt sind, prüft camindo CMS nicht – das ist
Sache dessen, der die Policy einrichtet.

Unabhängig von jeder Policy gilt: Beim Löschen eines ***Profils*** – auch mit seinem
Bereich – werden seine bekannten Geräte und Sitzungen sofort gelöscht, und Mediendateien verschwinden immer
mit dem endgültigen Löschen eines Datensatzes.

### Unterdatensätze

Mit einem Datensatz werden auch seine ***Unterdatensätze*** gelöscht: die
***Kinder*** im Baum, wenn Sie „mit Unterdatensätzen“ löschen (sonst rücken sie eine
Ebene nach oben und bleiben unberührt), und immer die ***fest verknüpften Inhalte***
(Felder vom Typ „link“), auch die der Kinder. Jeder Unterdatensatz bekommt die Blöcke
der Policy ***seines*** Bereichs für „Datensatz wird gelöscht“ – mit einer Ausnahme:
„In den Papierkorb“ und „Endgültig löschen, samt Audit-Log“ erledigt der
Hauptdatensatz für alle mit. Ein Unterdatensatz, für den schon eine Löschung läuft,
behält seine Aufträge.

### Anonym durch das Profil

Das Audit-Log speichert, wer gehandelt hat, nur als ***Nummer*** – bei einem
Website-User die Nummer seines Profil-Datensatzes, nie seinen Namen. Diese
Verknüpfung bleibt ***immer*** bestehen; keine Aktion entfernt sie. Anonym werden die
Einträge über das ***Profil***: Ist es endgültig gelöscht oder sind seine
personenbezogenen Felder mit „Einzelne Felder überschreiben“ geleert, führt die Nummer
zu niemandem mehr – auch nicht in den Einträgen zu Beiträgen und anderen Datensätzen,
an denen der User gearbeitet hat. Was in den Einträgen selbst personenbezogen ist
(Werte vorher/nachher, Bezeichnung), überschreiben Sie mit „Felder im Audit-Log
überschreiben“.

### Die Standard-Policies

Bei der Installation entstehen drei Policies für alle
Instanzen. Sie lassen sich ändern – als Policies für alle Instanzen nur von einem
Superuser –, aber nicht löschen; ein neuer Bereich bekommt die seines Typs
vorbelegt.

| Policy | Bereichstyp | Blöcke |
|---|---|---|
| ***Datensatz löschen*** | Datensätze | sofort in den Papierkorb (nach 7 Tagen endgültig), auch wenn das Profil des Users gelöscht wird; Audit-Log nach 1 Jahr endgültig löschen |
| ***Profil löschen*** | Profile | sofort in den Papierkorb (nach 7 Tagen endgültig); im Audit-Log nach 7 Tagen `_label` durch `user_{record_id}` ersetzen und alle übrigen Felder leeren; Audit-Log nach 1 Jahr endgültig löschen |
| ***User-Generated-Content löschen*** | Beiträge | sofort in den Papierkorb (nach 7 Tagen endgültig), auch wenn das Profil des Users gelöscht wird; Audit-Log nach 1 Jahr endgültig löschen |

Die Blöcke im Audit-Log gelten in allen drei Standard-Policies auch für den Auslöser
„Bereich wurde gelöscht“.

Mit den Standard-Policies ist ein gelöschter Website-User nach 7 Tagen anonym: Das
Profil ist endgültig gelöscht, sein eigener Verlauf geleert, und die Einträge zu seinen
Beiträgen nennen nur noch eine Nummer, die zu keinem Profil mehr gehört.

### Beispiel: Profil anonymisieren statt löschen

Soll ein Profil (etwa wegen Bestellungen) stehen bleiben, aber keine Person mehr
erkennen lassen:

| Auslöser | Zeitpunkt | Aktion | Felder |
|---|---|---|---|
| Datensatz wird gelöscht | sofort | Einzelne Felder überschreiben | `status_id = "4"` |
| Datensatz wird gelöscht | 7 Tage | Einzelne Felder überschreiben | `username = "user_{record_id}"`, `name = ""`, `kontonummer = ""` |
| Datensatz wird gelöscht | 7 Tage | Felder im Audit-Log überschreiben | `_label = "user_{record_id}"`, `* = ""` |
| Datensatz wird gelöscht | 1 Jahr | Endgültig löschen, samt Audit-Log | |

Die Beiträge des Users werden dabei nach der Policy ihres Bereichs mit dem Auslöser
„UGC: Profil wurde gelöscht“ gelöscht. Sollen sie bleiben, nehmen Sie diesen Auslöser
aus deren Policy heraus.

### Hinweis im Löschdialog

Wer im Backend einen Datensatz löscht, sieht vorher, was die Policy mit der
***Datenbank*** tut – sofort und nach Fristen, bei einem Profil auch mit seinen
Beiträgen:

> Der Profil-Datensatz bleibt bestehen, wird aber wie folgt angepasst:
> – Status: "Gelöscht"
>
> Nach 7 Tagen:
> – Benutzername: "user_{record_id}" · – Kontonummer: ""
>
> Nach 1 Jahr: – Endgültige Löschung
>
> 72 Beiträge des Users („Kommentare“) bleiben bestehen.

Die Stapelverarbeitung zeigt dasselbe je Bereich. Nach dem Löschen heißt die Meldung
je nach Policy „gelöscht“, „angepasst“ oder „geplant“.

### Wie es ausgeführt wird

Jeder Block wird beim Löschen ein ***Auftrag*** (Tabelle `privacy_job`) mit
Fälligkeit; die sofort fälligen laufen gleich, die übrigen mit der Zeitplaner-Aufgabe
***„Lösch-Policies anwenden“*** (Vorgabe: jede Nacht; für Fristen in Stunden öfter
terminieren). Ein Auftrag nimmt die Werte seines Blocks mit – eine spätere Änderung der
Policy wirkt nur auf künftige Löschungen. Scheitert ein Auftrag (etwa weil jemand den
Datensatz gerade bearbeitet), gibt es bis zu 20 Versuche; alles steht im Log
`privacy`. Aktionen einer Policy gelten als System: Pflichtfelder und Gruppenrechte
werden dabei nicht geprüft, und im Audit-Log steht die Änderung als „nach
Lösch-Policy angepasst“ ohne die bisherigen Werte.

Wird ein Datensatz aus dem Papierkorb ***wiederhergestellt***, verfallen seine noch
offenen Aufträge. Ein Datensatz, für den schon eine Löschung läuft, lässt sich nicht
erneut löschen.

Beim Löschen eines ganzen ***Bereichs*** gehen seine Datensätze mit dem Bereich in
den Papierkorb, und camindo CMS plant für jeden die Blöcke der Bereichs-Policy mit dem
Auslöser „Bereich wurde gelöscht“ – mit den Standard-Policies: Audit-Log nach 1 Jahr
endgültig löschen. Hat die Policy keinen Block für diesen Auslöser, bleibt der Verlauf
stehen. Datensätze, für die es nie Aufträge gab (etwa vor Einführung der
Lösch-Policies gelöscht), bekommen beim endgültigen Löschen wenigstens die
Audit-Log-Blöcke dieses Auslösers, die Fristen gerechnet ab dem Löschen. Der Verlauf
endgültig gelöschter Datensätze im Audit-Log richtet sich nur nach den Policies, nicht
nach `audit.retention_days_deleted` (das gilt weiter für Bereiche, Felder, Templates
und andere Objekte).

### Papierkorb eines Website-Users sofort leeren

Verlangt ein Website-User die sofortige Löschung, leert ein Benutzer mit dem Recht
***„Papierkorb eines Website-Users endgültig leeren“*** (Modul „Werkzeuge“) dessen
Papierkorb: „Werkzeuge“ → „Papierkorb eines Users“. Das Profil wählen Sie über die
Suche in allen Profil-Bereichen, die Sie lesen dürfen, oder – wenn es selbst schon
gelöscht ist – aus der Liste der Profile im Papierkorb. Profil und Beiträge, die im
Papierkorb liegen, werden sofort endgültig gelöscht. Benutzer leeren
ihren eigenen Papierkorb wie bisher selbst.

### Audit-Log von Hand löschen

Das endgültige Löschen – auch über „Werkzeuge“ → „Datenbank“ → „Papierkorb leeren“ – lässt den
Verlauf eines Datensatzes im Audit-Log stehen; ihn räumen die Audit-Log-Blöcke der
Lösch-Policy nach ihrer Frist ab. Muss ein Verlauf früher verschwinden – etwa weil ein
Website-User darauf besteht oder Testdaten im Spiel waren –, löschen Sie ihn auf der
Kommandozeile:

```bash
php Core/cron/camindo.php audit delete record 4237 --dry-run   # nur zählen
php Core/cron/camindo.php audit delete record 4237
php Core/cron/camindo.php audit delete --area=141 --force      # alle Datensätze eines Bereichs
```

Gelöscht wird der ganze Verlauf: die Einträge der Datenbank, die Dateien unter
`var/audit/` und die Aufträge der Lösch-Policy zu diesem Datensatz. Noch offene Aufträge
bleiben, solange es den Datensatz gibt – sonst würde er nie zu Ende gelöscht; sie räumen
später auch den Eintrag des endgültigen Löschens ab. `--area` erfasst jeden Datensatz,
der in dem Bereich liegt oder laut Audit-Log dort lag, auch gelöschte; ohne `--force`
nennt der Befehl nur die Anzahl. Statt `record` geht jeder andere Objekttyp des
Audit-Logs, etwa `admin 34` für den Verlauf eines Benutzerkontos. Der Vorgang steht mit
Anzahl, aber ohne Inhalte im Log `security`. Einträge, in denen ein Website-User an
anderen Datensätzen gehandelt hat, erfasst `audit delete` nicht; sie nennen ihn nur mit
der Nummer seines Profils (siehe [Anonym durch das Profil](#anonym-durch-das-profil)).

## Datenexport personenbezogener Daten

Für ein Auskunftsersuchen (Art. 15 DSGVO) oder die Datenübertragbarkeit (Art. 20)
erstellt camindo CMS einen ***Datenexport***: ein Zip mit `auskunft.html` zum Lesen
und `daten.json` maschinenlesbar, bei Website-Usern zusätzlich `dateien/` mit ihren
hochgeladenen Dateien. Er ist ein ***Teilauszug*** – vor der Weitergabe an
die betroffene Person ergänzen Sie ihn um alles, was camindo CMS nicht kennt:
Rechtsgrundlagen, Server-Logfiles außerhalb des CMS, Daten in Buchhaltung, E-Mail und
anderen Systemen.

Den Export erstellt, wer im Modul „Datenschutz“ das Zusatzrecht
***„Personenbezogene Daten exportieren“*** hat (Superuser immer), unter
„Datenschutz“ → „Datenexport“:

| Person | Wer darf | Wie |
|---|---|---|
| ***Website-User*** | wer den Profil-Bereich lesen darf | Profil suchen (alle lesbaren Profil-Bereiche) oder aus den Profilen im Papierkorb wählen – oder am Profil-Datensatz im Menü ⋮ „Datenexport personenbezogener Daten“ |
| ***Benutzer*** | Benutzer der Gruppen, deren Benutzer die Gruppe im Modul „Benutzer“ sehen darf (eigene Gruppe mit „Benutzerdetails lesen: eigene Benutzergruppe“, andere mit „Benutzerdetails lesen: andere Benutzergruppen“) | Benutzer aus der Liste wählen |

Benutzer können ihren Export nicht selbst herunterladen.

***Vor dem Erstellen*** prüft camindo CMS die [Vorlagen](#die-vorlagen-der-datenauskunft):

- Gibt es für die Instanz des Profils ***keine Vorlage für Website-User*** – weder
  für seinen Profil-Bereich noch für alle Bereiche – (bei einem Benutzer: weder für
  seine Gruppe noch für alle Gruppen), erscheint ein Hinweis und
  keine Schaltfläche zum Erstellen – mit dem Recht dazu legen Sie über
  ***„Vorlage anlegen“*** gleich eine an.
- Gibt es ***genau eine***, wird sie verwendet.
- Gibt es Vorlagen in ***mehreren Sprachen***, wählen Sie die ***Sprache der
  Auskunft***. Bei Benutzern wählen Sie unter den Benutzer-Vorlagen aller Ihrer
  Instanzen.

Darunter steht ein Warnhinweis: Eine Datenauskunft darf nur eine dazu berechtigte
Person erstellen – in der Regel der Datenschutzbeauftragte. Und: Jede Datenauskunft
wird protokolliert; die betroffene Person erfährt in jeder späteren Auskunft, dass
heute eine zu ihr erstellt wurde. Erst wenn Sie ***„Ich bin berechtigt, die
Datenauskunft zu erstellen.“*** ankreuzen, entsteht der Export mit
***„Datenauskunft erstellen“***.

Der Export eines ***Website-Users*** enthält:

- alle Felder des Profils in allen Sprachen (verschlüsselte im Klartext, Passwörter
  nur als Hinweis),
- seine ***Beiträge, nach Bereich gegliedert***, samt Dateien – Beiträge im
  Papierkorb sind als „gelöscht, noch im Papierkorb“ gekennzeichnet,
- ***endgültig gelöschte Beiträge***, die das Audit-Log noch mit ihm verbindet, und
  was die Lösch-Policy damit noch vorhat (etwa „Audit-Log endgültig löschen: 2027-10-08“),
- Datensätze mit Bezug zum Profil (etwa Bestellungen),
- bekannte Geräte und Sitzungen – ***direkt darunter*** ihre Speicherdauer –,
  zweiter Faktor, offene Links, Warenkorb,
- ***fehlgeschlagene Anmeldeversuche*** unter seinem Anmeldenamen bzw. seinem Konto,
  je mit dem Tag der Löschung,
- ***Einträge in Protokolldateien***, die eindeutig zu ihm gehören: Sperren nach zu
  vielen Fehlversuchen (Log `security`) und Zeilen des Mail-Logs mit seiner
  E-Mail-Adresse, etwa nicht zustellbare E-Mails,
- das Änderungsprotokoll, die Speicherfristen und die Lösch-Policy seines Profil-Bereichs.

***Namen anderer Personen*** stehen nicht in der Auskunft eines Website-Users: Wer
gehandelt hat, erscheint nur als ***„Sie selbst“***, ***„Admin“***, ***„User“***
(ein anderer Website-User) oder „System“. ***Gekürzte IP-Adressen*** erscheinen mit
`xxx` statt der genullten Stellen (`203.0.113.xxx`), damit niemand die Null für einen
Teil der Adresse hält.

Der Export eines ***Benutzers*** enthält: Kontodaten (Kontaktdaten im Klartext, vom
Passwort nur der Tag der letzten Änderung), Geräte und Sitzungen mit ihrer
Speicherdauer, zweiter Faktor (ohne Schlüssel oder Codes), ***Anmeldungen*** (nur mit
`audit.log_logins`) und fehlgeschlagene Anmeldungen unter seinem Namen – je mit dem Tag
ihrer Löschung –, seine Aktivitäten im Audit-Log mit IP-Adresse (die neuesten 1000),
Änderungen an seinem Konto, interne Nachrichten an ihn (Datum, Absender, Betreff), die
Zahl der Datensätze mit seinem Namen und die Speicherfristen.

Jeder Export steht im Audit-Log. Das Zip liegt bis zum Herunterladen in
`var/export/`, wird dabei gelöscht und spätestens nach `admin.export_retention_hours`
(24 Stunden) entfernt.

### Die Vorlagen der Datenauskunft

Wie `auskunft.html` aussieht und welcher Text um die Daten herum steht, bestimmt eine
HTML-Vorlage, die Sie selbst gestalten – etwa mit Ihrem Logo und dem Abschnitt
„Speicherdauer“ Ihrer Datenschutzerklärung. Für ***Website-User*** und für
***Benutzer*** gibt es getrennte Vorlagen, jeweils ***je Instanz und je Sprache*** –
und auf Wunsch ***je Profil-Bereich*** bzw. ***je Benutzergruppe***, etwa:

| Art | Instanz | Gilt für | Sprache |
|---|---|---|---|
| Website-User | Shop | Kunden | Englisch |
| Website-User | Shop | Community | Englisch |
| Website-User | Shop | Alle Profil-Bereiche | Deutsch |
| Benutzer | Shop | Redaktion | Englisch |
| Benutzer | Shop | Alle Benutzergruppen | Englisch |

Beim Export ***gewinnt die spezifische Vorlage***: Für ein Profil im Bereich „Kunden“
gelten die Vorlagen für „Kunden“; gibt es keine, die für alle Profil-Bereiche. Bei
einem Benutzer gelten die Vorlagen für seine Benutzergruppe (aus allen Ihren
Instanzen), sonst die für alle Gruppen. Sie pflegen die Vorlagen unter
„Datenschutz“ → „Vorlagen“; anlegen, ändern und löschen darf, wer das
Zusatzrecht ***„Vorlagen der Datenauskunft pflegen“*** hat und die Instanz bearbeiten
darf (Superuser immer).

Mit ***„Neu“*** wählen Sie zuerst ***Art (Website-User oder Benutzer) und Instanz***,
im Formular dann ***„Gilt für“*** (Profil-Bereich bzw. Benutzergruppe oder alle) und
die ***Sprache***. Eine zweite Vorlage für dieselbe Kombination lässt sich nicht
speichern. Die neue Vorlage ist mit
der Vorlage des Core für diese Art vorbelegt (`Core/install/privacy/privacy_user_en_US.html`
bzw. `privacy_admin_en_US.html`, englisch; sie listen am Anfang alle Platzhalter).
Ohne Vorlage für Website-User gibt es für die Website-User einer Instanz keinen
Datenexport; für einen Benutzer wählen Sie unter den Benutzer-Vorlagen aller Ihrer
Instanzen.

Die Auskunft entsteht in der ***Sprache der Vorlage***: Snippets und die
Beschriftungen der Tabellen erscheinen in dieser Sprache, unabhängig von Ihrer
Backend-Sprache. Weil Arten, Profil-Bereiche und Benutzergruppen getrennte
Vorlagen haben, braucht es keine Unterscheidung innerhalb einer Vorlage.

| Schreibweise | Wirkung |
|---|---|
| `{name}` | Platzhalter – Werte werden maskiert eingesetzt, Blöcke als fertige HTML-Tabellen |
| `<!-- if:name -->…<!-- endif:name -->` | Abschnitt nur, wenn die Bedingung gilt; verschachtelbar |
| `{#modul.schluessel}` | Sprach-Snippet in der Sprache der Vorlage, etwa `{#record.gdpr_col_device}` |
| `<!-- Kommentar -->` | Erläuterung für Sie – fällt im Export weg |

***Bedingungen:*** `audit`, `audit_changes_limited` / `audit_changes_unlimited`,
`audit_deleted_limited`, `logcleanup` / `no_logcleanup` (Aufgabe „Logs aufräumen“
eingerichtet), `dbbackup` / `no_dbbackup` (Aufgabe „Datenbank-Backup“),
`messages_limited`, `ip_v4_one` / `ip_v4_two`.

***Fristen*** (in beiden Arten): `{trash_days}`, `{device_days}`, `{ip_days}`,
`{ip_v4_octets}`, `{message_days}`, `{audit_changes_days}`, `{audit_deleted_days}`,
`{session_hours}` (Website-User), `{session_minutes}` (Benutzer), `{basket_days}`,
`{unconfirmed_days}`, `{export_hours}`, `{backups}` (aufbewahrte Datenbanksicherungen),
`{log_days}`, `{security_days}`, `{attempt_days}` (aus der Aufgabe „Logs aufräumen“).
So stimmt der Text immer mit den Einstellungen überein – ändern Sie eine Frist, ändert
sich der Export mit.

***Kopf:*** `{subject}`, `{subject_id}`, `{domain}` (Haupt-Domain der Instanz),
`{instance}` (Bezeichnung der Instanz), `{created}`, `{lang}`, `{dir}`.

***Daten in der Vorlage für Website-User:*** `{profile}`, `{contents}` (nach Bereich gegliedert),
`{purged}` (endgültig gelöschte Beiträge, die das Audit-Log noch kennt),
`{references}`, `{devices}`, `{sessions}` (je mit der Speicherdauer darunter),
`{second_factor}`, `{tokens}`, `{basket}`, `{login_attempts}`, `{log_lines}`,
`{files}`, `{audit}`, `{retention}` (die Fristen als fertige Liste), `{delete_policy}`
(die Lösch-Policy des Profil-Bereichs, je Block ein Satz).

***Daten in der Vorlage für Benutzer:*** `{account}`, `{devices}`, `{sessions}`,
`{second_factor}`, `{logins}`, `{activity}`, `{activity_note}`, `{account_changes}`,
`{messages}`, `{content_counts}`, `{retention}`.

Lassen Sie einen Datenblock weg, fehlt er im Export – die Auskunft muss nach Art. 15
DSGVO aber vollständig sein. Prüfen Sie eine geänderte Vorlage deshalb mit einem
Export zu einem Testkonto. `daten.json` enthält unabhängig von der Vorlage immer alle
Daten.

## Was außerhalb von camindo CMS liegt

camindo CMS kann nur kürzen und löschen, was es selbst speichert. Auf Ihrer Liste
stehen außerdem:

- ***Zugriffsprotokolle des Webservers*** (Apache, nginx, IIS) – sie enthalten die
  volle IP-Adresse jedes Aufrufs. Kürzen Sie sie im Webserver (etwa mit einem
  anonymisierenden Log-Format) oder löschen Sie sie nach wenigen Tagen per
  Logrotation.
- ***PHP- und Datenbank-Protokolle*** außerhalb von `var/log/`.
- ***Datenbanksicherungen*** und Server-Backups – sie enthalten den Stand zum
  Zeitpunkt der Sicherung, also auch noch ungekürzte Adressen der letzten Tage.
- ***E-Mails***, die camindo CMS verschickt hat (Hinweise auf neue Geräte, Formulare).
- ***Proxies, Load-Balancer, CDN und Firewalls*** vor der Website.

## Checkliste

- [ ] Fristen (`privacy.ip_anonymize_days`, `security.device_days`, `audit.*`,
      `admin.trash_retention_days`, Aufgabe „Logs aufräumen“) mit dem
      Datenschutzbeauftragten abgestimmt und im Verzeichnis der
      Verarbeitungstätigkeiten eingetragen
- [ ] Zeitplaner-Aufgabe ***„Logs aufräumen“*** eingerichtet
- [ ] nach dem Update einmal `audit anonymize-ip` ausgeführt
- [ ] Lösch-Policies geprüft: jeder Inhaltsbereich hat die passende; aufzubewahrende
      Daten (Bestellungen, Rechnungen) nicht im Papierkorb, sondern anonymisiert
- [ ] Vorlagen der Datenauskunft angelegt („Datenschutz“ → „Vorlagen“): für Website-User
      je Instanz mit Community (bei Bedarf je Profil-Bereich), für Benutzer mindestens
      eine (bei Bedarf je Benutzergruppe) – angepasst und mit einem Testkonto geprüft
- [ ] Rechte vergeben: „Personenbezogene Daten exportieren“, „Lösch-Policies anlegen und
      ändern“, „Vorlagen der Datenauskunft pflegen“ (Modul „Datenschutz“), „Papierkorb
      eines Website-Users endgültig leeren“ (Modul „Werkzeuge“)
- [ ] entschieden, ob erfolgreiche Anmeldungen aufgezeichnet werden (`audit.log_logins`,
      Vorgabe nein) und wie lange Anmeldungen bleiben (`audit.retention_days_logins`)
- [ ] Frist der internen Nachrichten (`privacy.message_days`) festgelegt; bei
      ausgeschiedenen Mitarbeitern Nachrichten gelöscht
- [ ] Zugriffsprotokolle des Webservers gekürzt oder kurzfristig gelöscht
- [ ] hinter einem Proxy: `security.trusted_proxies` gesetzt – sonst steht überall die
      Adresse des Proxys
- [ ] Mitarbeiter über das Audit-Log informiert, Betriebsvereinbarung bzw. Richtlinie
- [ ] Datenschutzerklärung der Website nennt Cookies, Formulare, Community-Funktionen
- [ ] Ablauf für Auskunfts- und Löschersuchen festgelegt (wer, Frist ein Monat)
