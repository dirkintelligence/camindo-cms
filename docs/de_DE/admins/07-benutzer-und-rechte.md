# 7 Benutzer und Rechte

Wer im Backend arbeitet, ist ein ***Benutzer*** – gleich in welcher ***Rolle***: als
Redakteur, Übersetzer, Administrator, Programmierer. Benutzer und ihre
***Benutzergruppen*** verwalten Sie im Modul „Benutzer“. Jeder Benutzer gehört genau
einer Benutzergruppe; sie bestimmt, was er sehen und tun darf. Benutzer der Gruppe ***Superuser***
haben volle Rechte für alles.

Die Startseite des Moduls ![person](../_/person.svg) „Benutzer“ listet alle ***Benutzer*** (die Sie mit Ihren Gruppenrechten
sehen dürfen). Superuser sind mit einem sechseckigen Symbol ![identity_platform](../_/identity_platform.svg) markiert.
Klicken Sie auf die Schaltfläche „Neu“ oder wählen Sie einen Eintrag aus der Liste. Es erscheint die Eingabe-/Änderungsmaske für diesen Benutzer.

## Eingabefelder

| Angabe | Bedeutung |
|---|---|
| Benutzername | Anmeldename: beginnt mit einem Kleinbuchstaben, danach Kleinbuchstaben, Ziffern und `_`. Neue Namen dürfen nicht mit `admin_` beginnen – reserviert für anonymisierte Konten |
| Passwort | wird nur als Prüfwert (Hash) gespeichert und ist nicht lesbar; leer lassen = unverändert. Es muss die [Passwort-Richtlinie](./extensions/password.md) erfüllen – die Anforderungen erscheinen beim Tippen. Beim Anlegen vergeben Sie ein ***Startpasswort***. Das Passwort eines bestehenden anderen Benutzers ändert nur ein Superuser |
| Bisheriges Passwort,<br>Ihr Passwort | nur für Superuser beim Ändern eines bestehenden Kontos: Wer sein ***eigenes*** Passwort ändert, gibt das bisherige ein; wer das Passwort eines ***anderen*** Benutzers ändert, bestätigt das mit seinem eigenen Passwort – eine offen stehende Sitzung reicht also nicht, um ein Konto zu übernehmen. Beim Anlegen entfällt das Feld. Alle anderen ändern ihr Passwort in den persönlichen Einstellungen |
| Benutzergruppe | Benutzergruppe mit ihren Rechten |
| Status | „Freigeschaltet“ oder „Deaktiviert“ – ein deaktivierter Benutzer kann sich nicht anmelden |
| Haupt- (Nach-) Name,<br>Angezeigter Name,<br>Sortierwert | Namen für Listen und Nachrichten |
| E-Mail,<br>Mobilfunk-,<br>Festnetznummer | Kontaktdaten, verschlüsselt gespeichert. Die E-Mail-Adresse wird für den Anmelde-Code der Zwei-Faktor-Anmeldung benötigt |

Die persönlichen Einstellungen eines Benutzers (Sprache, Datumsformat,
Zeitzone, Widgets, Passwort, Geräte …) pflegt jeder selbst; ein Superuser kann sie
auch für andere ändern.

***Benutzer löschen:*** Gelöschte Benutzer bleiben als „gelöscht“ erhalten, damit
Datensätze und das Audit-Log weiterhin zeigen, wer sie angelegt und bearbeitet hat.
Beim Löschen entfernt camindo CMS alles, womit sich das Konto noch anmelden könnte:
seine Sitzungen (er wird sofort abgemeldet), die gespeicherten Geräte, Passkeys und
Wiederherstellungscodes sowie die Liste der zuletzt bearbeiteten Datensätze und
Templates. Die Einträge im Audit-Log bleiben bestehen. Das eigene Konto lässt sich nicht
löschen. Name und Kontaktdaten eines gelöschten Benutzers entfernen Sie mit
***Anonymisieren*** (siehe [Modul „Datenschutz“, Reiter „Gelöschte Benutzer“](./10-module.md#datenschutz)).

***Passwort ändern müssen:*** Ein Passwort, das ein anderer Benutzer gesetzt hat, muss bei der ersten
Anmeldung geändert werden – etwa das Startpasswort beim Anlegen oder ein vom Superuser zurückgesetztes.
Die Konfigurationseinstellung `admin.password_retention_days` (0 = unbegrenzt) 
erzwingt ebenfalls eine Passwortänderung nach der eingestellten Anzahl Tage. Ein neues muss
sich dann vom bisherigen unterscheiden. Die Detailansicht zeigt unter „Passwort“, wann
es zuletzt geändert wurde und ob eine Änderung ansteht.

## Benutzergruppen

Nur Superuser sehen den Reiter ***„Benutzergruppen“***. Die Liste zeigt alle bestehenden ![group](../_/group.svg) Benutzergruppen. Nach der Installation gibt es nur die eine Gruppe „Superuser“.

***Benutzergruppe erstellen***: Für die tägliche Arbeit sollte eine separate Gruppe
mit eingeschränkten Rechten erstellt werden, etwa die Gruppe „Übersetzer“, „Redakteure“
oder „Praktikanten“. Klicken Sie dazu auf die Schaltfläche „Neu“.

***Benutzergruppe löschen***: Sind der Benutzergruppe noch Benutzer zugeordnet, wählen Sie
beim Löschen eine _Ersatzgruppe_, der diese Benutzer zugewiesen werden. Gelöschte
Benutzer der Gruppe verlieren dabei ihre Gruppenzuordnung – ohne Rückfrage; hat die
Gruppe nur gelöschte Benutzer, erscheint keine Auswahl. Die Gruppe „Superuser“ steht dabei nicht zur
Wahl.

### Oberflächen

Unter ***„Oberflächen“*** legen Sie fest, worüber sich die Benutzer der Benutzergruppe
anmelden dürfen:

| Oberfläche | Wofür |
|---|---|
| Backend | die gewohnte Arbeitsoberfläche im Browser |
| Kommandozeile (CLI) | Programme auf dem Server, etwa `php Core/cron/camindo.php api …` (siehe [Betrieb](./12-betrieb.md#die-kommandozeile)) – ohne Passwort, denn wer die Kommandozeile des Servers bedienen kann, hat ohnehin vollen Zugriff |
| PHP-API | Programme, die sich mit Name und Passwort anmelden |

Auf jeder Oberfläche gelten dieselben Rechte der Benutzergruppe; im Audit-Log erscheint der
Benutzer selbst, zusammen mit dem Weg (etwa `cli`). Eine neue Benutzergruppe hat nur das
Backend. Entziehen Sie einer Benutzergruppe eine Oberfläche, enden laufende Sitzungen dort beim
nächsten Zugriff. Für Programme empfiehlt sich ein eigener Benutzer in einer eigenen
Benutzergruppe, die nur die nötigen Oberflächen und Rechte hat.

Die Rechte einer Benutzergruppe haben vier Ebenen:

### 1. Module und Zusatzrechte

Welche Module die Benutzergruppe öffnen darf. Einige Module haben darunter eingerückt
***Zusatzrechte*** für einzelne Teile – ohne Zusatzrecht bleibt der Teil gesperrt:

| Modul | Zusatzrechte |
|---|---|
| Benutzer | „Benutzerdetails lesen: eigene Benutzergruppe“ · „Benutzerdetails lesen: andere Benutzergruppen“ · „Benutzer anlegen: eigene Benutzergruppe (mit Startpasswort)“ · „Benutzer ändern und sperren: eigene Benutzergruppe (ohne Passwort)“ |
| Datenschutz | „Lösch-Policies anlegen und ändern“ (nur für zugängliche Instanzen) · „Personenbezogene Daten exportieren“ (Profile aus lesbaren Bereichen, Benutzer aus sichtbaren Benutzergruppen) · „Vorlagen der Datenauskunft pflegen“ (zugängliche Instanzen) · „Gelöschte Benutzer anonymisieren“ (zugängliche Benutzergruppen) – siehe [Kapitel 15](./15-datenschutz.md) |
| Inhalte | „CSV-Import“, „Medien-Import (Zip-Datei)“, „CSV-Export“, „DOCX-Import (Übersetzung)“, „DOCX-Export (Übersetzung)“, „Stapelverarbeitung“ |
| Werkzeuge | je ein Recht für die Reiter „Server“, „Datenbank“, „Daten“, „Entwickler“ und „Papierkorb eines Users“ („Papierkorb eines Website-Users endgültig leeren“) |

Immer Superusern vorbehalten sind: die Verwaltung der Benutzergruppen, die Bereichsrechte, der
XML-Export und -Import (Sicherung und Strukturübertragung) und das Modul
„Logdateien“.

Ohne Eintrag in der Benutzergruppe stehen jedem Benutzer die internen Nachrichten, die
persönlichen Einstellungen und der [Papierkorb](./10-module.md#papierkorb) zur
Verfügung. Wiederherstellen darf er dort nur, was er in dem Bereich auch löschen
dürfte.

### 2. Instanzen

Welche Instanzen (Websites) die Benutzergruppe sieht. Weist man einer Benutzergruppe eine
Instanz zu, erhält sie zunächst alle Bereiche dieser Instanz mit allen Rechten (außer „Owner anpassen“). Einschränken lässt sich das anschließend im Reiter „[Bereichsrechte](#5-bereichsrechte)“.

### 3. Sprachen

Je Sprache zwei Häkchen: ***lesen*** und ***schreiben***. Wer schreiben darf, darf auch
lesen – ein Häkchen bei „schreiben“ setzt „lesen“ mit. Ein Klick auf die Spaltenüberschrift „lesen“ bzw.
„schreiben“ hakt die ganze Spalte an – oder ab, wenn dort schon alle Häkchen gesetzt
sind. Ohne Häkchen hat die Benutzergruppe keinen Zugriff auf die Sprache.

Die Einstellung gilt für alle Instanzen und für die Inhalte im Modul „Inhalte“: Nur
lesbare Sprachen erscheinen in der Eingabemaske, nur schreibbare lassen sich dort ändern.
So kann ein Übersetzer für Französisch alle Sprachen lesen, aber nur Französisch
bearbeiten.

### 4. Status setzen

Je Status eine Checkbox: Angehakte Status dürfen Benutzer der Gruppe einem Datensatz
geben. ***Lesen*** dürfen sie alle Status – eingeschränkt wird nur das ***Setzen***. So
bilden Sie einen ***flachen Workflow*** ab, etwa: Die Redaktion darf Beiträge anlegen und
auf „Gegenzulesen“ setzen, aber nicht freischalten – das übernimmt die Chefredaktion.
Weitergereicht wird über interne Nachrichten. Sie haben zwei Wege, Zugriffsrechte zu setzen:  
(Hier) in der Benutzergruppe wählen Sie alle Status für eine Gruppe; im Modul „Status“
wählen Sie alle Benutzergruppen für einen Status.

Status in der Datensatz-Eingabemaske:

- Das Eingabefeld „Status“ zeigt nur Status, die vom Benutzer setzbar sind.
- Ein Datensatz, dessen aktueller Status vom Benutzer nicht setzbar ist, lässt sich
  von ihm ***nicht bearbeiten***: Die Schaltfläche „Bearbeiten“ ist ausgegraut, im Kontextmenü
  der Liste fehlt der Eintrag. Lesen, Kopieren und – je nach Bereichsrecht – Löschen
  bleiben möglich.

### 5. Bereichsrechte

Nachdem eine Benutzergruppe erstellt wurde, zeigt ihre Infoseite den Reiter „Bereichsrechte“.
Hier ist gelistet, was die Benutzer dieser Gruppe mit den Datensätzen unterschiedlicher Bereiche
machen dürfen. Klicken Sie auf die Schaltfläche „Bearbeiten“, um Anpassungen durchzuführen.

| Recht | Bedeutung |
|---|---|
| Lesen | Der Bereich ist für die Benutzergruppe sichtbar – ohne dieses Recht existiert er für sie nicht |
| Eigene Benutzergruppe: Schreiben / Löschen | Datensätze bearbeiten bzw. löschen, deren [Owner](#das-owner-prinzip) zur eigenen Benutzergruppe gehört |
| Andere Benutzergruppe: Schreiben / Löschen | dasselbe für Datensätze, deren Owner einer anderen Benutzergruppe angehört |
| Erweitert (Owner anpassen) | den Owner eines Datensatzes ändern (Auswahl am Ende der Eingabemaske) |

Template-Bereiche kennen nur Lesen und Schreiben. Ordner haben keine eigenen Rechte.

Die Bereichsrechte pflegen Sie an zwei gleichwertigen Stellen:

- (hier) im Modul „Benutzer“, Reiter „Bereichsrechte“: alle Bereiche für eine Benutzergruppe,
- im Modul „Struktur“, Reiter „Zugriff“: alle Benutzergruppen für einen Bereich.

## Das Owner-Prinzip

Jeder Datensatz gehört einem Benutzer, seinem ***Owner*** – anfangs dem, der ihn
angelegt hat. Über die Benutzergruppe des Owners unterscheiden die Bereichsrechte zwischen
eigenen und fremden Inhalten. Beispiele:

- ***Zwei Redaktionen, ein Bereich:*** Die Benutzergruppen „Redaktion Nord“ und „Redaktion Süd“
  dürfen alle Meldungen lesen, aber nur die der eigenen Benutzergruppe schreiben und löschen.
- ***Freigabe durch Chefredaktion:*** Die Benutzergruppe „Autoren“ darf eigene Artikel
  schreiben, aber nicht löschen; die „Chefredaktion“ darf alle Artikel schreiben und
  löschen.
- ***Übergabe:*** Verlässt ein Mitarbeiter die Redaktion, ändert ein Benutzer mit
  dem Recht „Owner anpassen“ den Owner seiner Datensätze.

Datensätze, die Frontend-User anlegen, erhalten den Owner, den das Template bzw.
die Konfiguration vorgibt (siehe [Kapitel 13](./13-community.md)).

## Was die Rechte bewirken

Die Oberfläche zeigt nur, was für die Benutzergruppe freigegeben ist: Module, Bereiche, Schaltflächen,
Menüeinträge, Sprachen. Unabhängig davon prüft camindo CMS jede Aktion auch beim
Speichern und Löschen – ein Recht lässt sich also nicht durch eine manipulierte
Anfrage umgehen. Hintergrundprozesse (Import, Export, Stapelverarbeitung) laufen mit
den Rechten des Benutzers, der sie gestartet hat.

Verknüpfungsfelder, deren Zielbereiche die Benutzergruppe nicht lesen darf, erscheinen nicht
in der Maske; ihre Verknüpfungen bleiben beim Speichern unangetastet.
Ebenso mehrsprachige Inhalte, die die Benutzergruppe nur in ausgewählten Sprachen bearbeiten darf: die
übrigen Inhalte bleiben beim Speichern erhalten.

## Anmeldeschutz

- ***Zwei-Faktor-Anmeldung:*** Nach dem Passwort wird ein Code an die E-Mail-Adresse des
  Benutzers geschickt. Einschalten in der Konfiguration (siehe
  [Kapitel 12](./12-betrieb.md#konfiguration)).
- ***Authenticator-App (TOTP):*** Jeder Benutzer kann in seinen persönlichen
  Einstellungen eine Authenticator-App einrichten; dann fragt die Anmeldung nach deren
  Code statt nach dem E-Mail-Code, dazu gibt es zehn Wiederherstellungscodes (Anleitung
  im [Handbuch für Anwender](../editors/02-anmeldung.md#authenticator-app-einrichten)).  
  Mit `security.admin_2fa = 'totp_required'` ist sie für ***Superuser Pflicht*** – wer sie
  noch nicht hat, muss sie bei der nächsten Anmeldung im Anmeldedialog einrichten. Mit
  `'email'` ist sie abgeschaltet (siehe [Kapitel 12](./12-betrieb.md#zwei-faktor-anmeldung)).
- ***Passkeys:*** Mit fester Backend-Domain kann jeder Benutzer Passkeys anlegen
  (Fingerabdruck, Gesicht, Geräte-PIN, Sicherheitsschlüssel) – die sicherste Form des
  zweiten Faktors ([Handbuch für Anwender](../editors/02-anmeldung.md#passkey-anlegen)).
  Das Zurücksetzen unten entfernt auch sie.
- ***Zweiten Faktor zurücksetzen:*** Hat ein Benutzer sein Telefon bzw. seine Passkeys und seine
  Wiederherstellungscodes verloren, öffnet ein Superuser dessen Detailansicht, Reiter
  ***„Zwei-Faktor-Anmeldung“***, und klickt auf „Zweiten Faktor zurücksetzen“
  (entfernt App und Passkeys). Der Benutzer meldet sich danach wieder mit dem E-Mail-Code an
  (bzw. richtet die App bei Pflicht neu ein) und bekommt eine E-Mail darüber. Das Zurücksetzen
  steht mit dem Namen des Superusers im Audit-Log. Vergewissern Sie sich vorher – etwa per
  Telefon –, dass die Bitte wirklich vom Kontoinhaber kommt.
- ***Zurück zum E-Mail-Code:*** Wer die Authenticator-App nicht mehr nutzen möchte,
  wechselt in seinen Einstellungen mit „Zum Code per E-Mail wechseln“ zurück (bestätigt
  mit einem Code aus der App). Ist der E-Mail-Code nicht für alle eingeschaltet, kann
  jeder Benutzer ihn für sich selbst einschalten.  
  (Ist mittels Konfiguration die App für alle Benutzer vorgeschrieben, kann nicht
  zum Code per E-Mail zurückgewechselt werden.)
- ***Hinweis bei neuem Gerät:*** Meldet sich ein Benutzer von einem Browser an, den
  camindo CMS noch nicht kennt, geht eine E-Mail an seine Adresse – samt Gerät,
  IP-Adresse und Uhrzeit (siehe [Kapitel 12](./12-betrieb.md#passwörter-und-anmeldegeräte)).
- ***Geräte und Sitzungen:*** Jeder Benutzer sieht in seinen persönlichen
  Einstellungen seine Anmeldegeräte mit den laufenden Sitzungen, kann einzelne Geräte
  entfernen (ihre Sitzungen enden sofort) und alle anderen Sitzungen beenden. Ein
  Superuser kann in der Detailansicht eines anderen Benutzers, Reiter „Geräte und
  Sitzungen“, mit „Alle Geräte entfernen“ alle dessen Geräte und Sitzungen beenden –
  etwa nach einem verlorenen Laptop. Beides steht im Audit-Log. „Abmelden“ beendet nur
  die Sitzung im eigenen Browser.
- ***Aktivitäten:*** Was ein Benutzer geändert hat, sieht ein Superuser in dessen
  Detailansicht im Reiter „Aktivitäten“ – die zuletzt bearbeiteten Datensätze und alle
  seine Einträge im [Audit-Log](./10-module.md#audit-log).
- ***Neues Passwort beendet andere Sitzungen:*** Wird ein Passwort geändert (vom
  Benutzer selbst oder vom Superuser), enden alle anderen Sitzungen des Kontos.
- ***Schutz vor Durchprobieren:*** Nach mehreren Fehlversuchen (Vorgabe: 3 in 10
  Minuten) wird die Anmeldung für den Benutzernamen gesperrt. Sperren werden im
  Sicherheitsprotokoll festgehalten.
- ***Automatische Abmeldung*** nach 30 Minuten ohne Aktivität (einstellbar).
- Rechte und Sitzungen sind gegen Manipulation direkt in der Datenbank signiert: Wer
  nur Zugriff auf die Datenbank hat, kann sich damit keine Rechte verschaffen.
