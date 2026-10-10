# 2 Anmelden und Abmelden

Das Backend von camindo CMS erreichen Sie über den Pfad `/backend` auf Ihrer Website, z. B.
```
https://www.ihre-website.com/backend/
```

Häufig ist das Backend aber unter einer separaten Domain oder einer Subdomain Ihrer Website
erreichbar, z. B.
```
https://subdomain.ihre-website.com/backend/
```

Fragen Sie Ihren Administrator nach der richtigen Adresse.  
Der Zugang erfordert immer eine Anmeldung. Tragen Sie Ihren Benutzernamen und Ihr
Passwort ein und klicken Sie auf „Absenden“.

## Zwei-Faktor-Anmeldung

Entsprechend dem heutigen Standard sollte Ihr Administrator eine 2-Faktor-Anmeldung
einschalten. Ohne zweiten Faktor haben Sie dann keinen Zugang zum Backend, trotz korrektem
Passwort.
camindo CMS bietet insgesamt vier Wege für den zweiten Faktor:

- Code per E-Mail
- Code aus einer Authenticator-App (TOTP)
- Passkey (WebAuthn)
- Bekanntes Gerät

## Anmelde-Code per E-Mail

camindo CMS schickt Ihnen nach Ihrer Passworteingabe einen Code an die E-Mail-Adresse Ihres
Benutzerkontos. Geben Sie diesen Code im nächsten Schritt ein. Der Code gilt nur wenige
Minuten (standardmäßig zehn). Ist der Code abgelaufen, melden Sie sich einfach erneut an.

## Code aus der Authenticator-App

Haben Sie eine ***Authenticator-App*** eingerichtet (siehe unten), fragt camindo CMS
nach Ihrer Passworteingabe nach dem sechsstelligen Code, den die App gerade anzeigt.
Er wechselt alle 30 Sekunden. Jeder Code gilt nur für eine Anmeldung.

Haben Sie Ihr Telefon nicht zur Hand, geben Sie stattdessen einen Ihrer
***Wiederherstellungscodes*** ein (Form `ABCDE-FGHJK`). Jeder gilt genau einmal. Sie
bekommen anschließend eine E-Mail, wie viele noch übrig sind. Ist das Telefon verloren oder
kein Code mehr übrig, kann Ihr Administrator die App für Ihr Konto zurücksetzen. Sie melden
sich dann wieder mit dem E-Mail-Code an und richten die App neu ein.

Ein falscher Code zählt wie ein falsches Passwort (siehe [Gesperrte Anmeldung](#gesperrte-anmeldung)).
Nach einem Fehlversuch beginnen Sie wieder beim Passwort.

## Mit Passkey bestätigen

Haben Sie einen ***Passkey*** angelegt (siehe unten), zeigt der Anmeldedialog nach dem
Passwort die Schaltfläche ***„Mit Passkey bestätigen“***. Ihr Browser bzw. Ihr Gerät fragt dann
nach Fingerabdruck, Gesicht, Geräte-PIN oder nach Ihrem Sicherheitsschlüssel – mehr ist
nicht zu tun. Ohne das Gerät geben Sie im Feld darunter einen Wiederherstellungscode ein
(oder den Code Ihrer Authenticator-App, falls Sie auch diese eingerichtet haben).

## Passkey anlegen

Ein ***Passkey*** bietet eine komfortable und sichere Form der Authentifizierung.
Da der Passkey fest an die Domain des Backends gebunden ist, ist er resistent gegen
Phishing-Angriffe und das Abfangen von Anmeldedaten. Die Verwahrung erfolgt direkt
auf dem Endgerät (z. B. via Windows Hello, Touch ID/Face ID oder Android), in einem
Passwortmanager oder auf einem Hardware-Sicherheitsschlüssel (z. B. YubiKey).

1. Öffnen Sie die persönlichen Einstellungen und klicken Sie bei
   ***„Zwei-Faktor-Anmeldung“*** auf die Schaltfläche daneben.
2. Klicken Sie unter ***„Passkeys“*** auf ***„Passkey hinzufügen“***, geben Sie ihm einen
   Namen (etwa „Büro-Rechner“) und bestätigen Sie mit Ihrem Passwort.
3. Klicken Sie auf ***„Passkey jetzt anlegen“*** und folgen Sie Ihrem Browser.
4. Ist es Ihr erster Passkey und haben Sie noch keine Wiederherstellungscodes, erscheinen
   zehn davon. Speichern Sie sie an einem sicheren Ort.

Legen Sie am besten ***zwei Passkeys*** an, etwa auf Rechner und Telefon. In derselben
Liste entfernen Sie einen Passkey wieder (mit Ihrem Passwort). Über jeden neuen und jeden
entfernten Passkey bekommen Sie eine E-Mail.
Hinweis für Administratoren: Passkeys stehen nur zur Verfügung, wenn Ihr
Backend unter einer festen eigenen Adresse läuft – sonst zeigt die Seite einen Hinweis.

## Authenticator-App einrichten

Eine Authenticator-App ist deutlich sicherer als der Code per E-Mail: Der Code entsteht
auf Ihrem Telefon und geht durch kein Postfach. Geeignet ist jede gängige App, etwa
Google Authenticator, Microsoft Authenticator, 2FAS (iOS & Android), Aegis (Android).

1. Öffnen Sie die [persönlichen Einstellungen](./03-arbeitsoberflaeche.md#persönliche-einstellungen)
   (Zahnrad ![settings](../_/settings.svg)) und klicken Sie bei
   ***„Zwei-Faktor-Anmeldung“*** auf „Authenticator-App“.
2. Klicken Sie auf ***„Authenticator-App einrichten“*** und scannen Sie den QR-Code mit
   der App. Klappt das nicht, tippen Sie den darunter stehenden Schlüssel ab.
3. Tragen Sie den sechsstelligen Code ein, den die App jetzt anzeigt, und klicken Sie
   auf ***„Aktivieren“***. Erst damit ist die App eingeschaltet.
4. Es erscheinen zehn ***Wiederherstellungscodes***. Legen Sie sie sicher ab – im
   Passwortmanager oder ausgedruckt, nicht auf dem Telefon mit der App. Sie werden
   nur dieses eine Mal angezeigt.

Auf derselben Seite erzeugen Sie später einen ***neuen Satz Wiederherstellungscodes***
(die alten verfallen), ***wechseln zurück zum Code per E-Mail*** („Zum Code per E-Mail
wechseln“) oder ***schalten die App ab***; alles bestätigen Sie mit einem Code aus der
App oder einem Wiederherstellungscode. Über jede Änderung bekommen Sie eine E-Mail –
kommt eine, die Sie nicht erwartet haben, ändern Sie sofort Ihr Passwort.

## Code per E-Mail selbst einschalten

Ist die Zwei-Faktor-Anmeldung deaktiviert (nicht zu empfehlen), können Sie den Code per
E-Mail für Ihr Konto selbst wählen: In den persönlichen Einstellungen bei „Zwei-Faktor-Anmeldung“ auf
„Authenticator-App“ und dann ***„Code per E-Mail einschalten“***. camindo CMS schickt
Ihnen einen fünfstelligen Code; erst wenn Sie ihn eingeben, gilt die Wahl.
Auf dem gleichen Weg schalten Sie ihn wieder ab.

Hat Ihr Administrator die App **vorgeschrieben**, richten Sie sie direkt bei der Anmeldung ein:
Nach Passwort (und gegebenenfalls E-Mail-Code) zeigt der Anmeldedialog den QR-Code und danach Ihre Wiederherstellungscodes. Abschalten lässt sie sich dann nicht.

## Hinweis bei einem neuen Gerät

Melden Sie sich an einem Rechner, Telefon oder Browser an, den camindo CMS noch nicht
von Ihnen kennt, bekommen Sie eine E-Mail „Neue Anmeldung“ mit Gerät, IP-Adresse und
Uhrzeit. Waren Sie das selbst, ist nichts zu tun. Wenn nicht, ändern Sie sofort Ihr
Passwort und informieren Sie Ihren Administrator.  
Übrigens: Auch nach dem Löschen der Browser-Cookies oder im privaten Fenster gilt der
Browser als neues Gerät.

## Passwort ändern

Ihr eigenes Passwort ändern Sie in den
[persönlichen Einstellungen](./03-arbeitsoberflaeche.md#persönliche-einstellungen)
(Zahnrad ![settings](../_/settings.svg)) unter ***„Passwort“*** mit „Passwort ändern“:
Geben Sie Ihr ***„Bisheriges Passwort“*** zur Bestätigung ein, dann das neue zweimal.
Das neue muss sich vom bisherigen unterscheiden. Nach dem Speichern enden Ihre Sitzungen auf anderen
Geräten, und Sie bekommen eine E-Mail. Beim Tippen zeigt eine Sprechblase, was das
Passwort braucht (standardmäßig mindestens 12 Zeichen, ein Sonderzeichen, ein
Großbuchstabe nicht am Anfang und ein Kleinbuchstabe). Ein Balken darunter zeigt die 
Passwortstärke.  
Tipp: mehrere Wörter mit Satzzeichen dazwischen sind lang, stark und gut zu merken.

### Neues Passwort bei der Anmeldung

In zwei Fällen fragt der Anmeldedialog nach Passwort + Code nach einem **neuen
Passwort**, bevor es weitergeht:

- ***Startpasswort:*** Ein anderer Benutzer hat Ihr Konto angelegt oder Ihr Passwort
  zurückgesetzt. Vergeben Sie jetzt ein eigenes. Außer Ihnen soll es niemand kennen.
- ***Abgelaufenes Passwort:*** Ihr Administrator hat eine Gültigkeitsdauer eingestellt,
  und sie ist um. Das neue Passwort muss sich vom bisherigen unterscheiden.

## Geräte und Sitzungen

In den persönlichen Einstellungen zeigt ***„Geräte und Sitzungen“***, von welchen
Geräten Sie angemeldet waren und wie viele Sitzungen dort gerade laufen. Das Gerät, an dem Sie
sitzen, ist mit „dieses Gerät“ markiert. Gespeichert sind je Gerät:
Browser und Betriebssystem, IP-Adresse, Zeitpunkt der ersten und letzten Anmeldung.

- ***„Entfernen“*** löscht ein Gerät, das Sie nicht kennen oder nicht mehr benutzen –
  seine Sitzungen enden sofort. Meldet sich später jemand von dort an, gilt es wieder als
  neu, und Sie bekommen eine E-Mail. Entfernen Sie das eigene Gerät, werden Sie
  abgemeldet.
- ***„Alle anderen Sitzungen beenden“*** meldet Sie überall sonst ab – etwa, wenn Sie
  vergessen haben, sich an einem fremden Rechner abzumelden. Dort noch geöffnete
  Eingabemasken werden wie beim Abmelden auf allen Geräten verworfen, die Datensätze
  sind sofort wieder für andere freigegeben; die Masken in diesem Fenster bleiben offen.

## Gesperrte Anmeldung

Nach mehreren fehlgeschlagenen Versuchen (standardmäßig drei innerhalb von zehn
Minuten) sperrt camindo CMS die Anmeldung für diesen Benutzernamen vorübergehend. Die
Meldung nennt die Wartezeit. Vor dem letzten erlaubten Versuch erhalten Sie einen Warnhinweis.

## Einstellungen im Anmeldeformular

Unter den Zugangsdaten stehen drei Einstellungen. Sie gelten ab sofort und lassen
sich später jederzeit in den [persönlichen Einstellungen](./03-arbeitsoberflaeche.md#persönliche-einstellungen)
ändern.

### Sprache

Die Sprache der Backend-Oberfläche ist unabhängig von den Sprachen Ihrer Website.  
Bei der Installation von camindo CMS sind folgende Backend-Sprachen zur Auswahl (weitere
können nachträglich installiert werden):

- Arabisch
- Bengalisch
- Chinesisch (Festland)
- Deutsch
- Englisch
- Französisch
- Hindi
- Japanisch
- Koreanisch
- Portugiesisch (Brasilianisch)
- Spanisch
- Urdu

### Datumsformat

Wählen Sie das ***Gebietsschema*** (Locale), dessen Schreibweisen Ihnen vertraut sind.
Danach richten sich Datums-, Zeit- und Zahlenangaben – bei der Anzeige ebenso wie bei
der Eingabe.

Beispiele für Datums-, Zeit- und Zahlenformate finden Sie im Abschnitt
[Datensätze bearbeiten](./05-datensaetze-bearbeiten.md#datum-und-uhrzeit).

### Zeitzone

camindo CMS speichert Zeitpunkte in einer einheitlichen Weltzeit (***UTC***). Wenn Sie
„18:00“ eingeben, muss das System wissen, in welcher Zeitzone diese Angabe gemeint
ist. Umgekehrt rechnet es gespeicherte Zeitpunkte für die Anzeige in Ihre Zeitzone
um. So sehen Kollegen in verschiedenen Zeitzonen denselben Zeitpunkt jeweils in
ihrer Ortszeit.

Beispiel:

|  | Datumsformat | Zeitzone | Wert |
|---|---|---|---|
| Eingabe | en_US | America/New_York (UTC−5) | `12/31/2026 9:00 PM` |
| gespeichert | | UTC | `2027-01-01 02:00` |
| angezeigt | de_DE | Europe/Berlin (UTC+1) | `01.01.2027 03:00` |

Umgerechneten Datums-Zeit-Werten wird die Zeitzone angehängt, Beispiel:  

```
27.09.2026, 15:06:59 Europe/Berlin
```

Nicht umgerechnet werden reine Uhrzeiten und Felder vom Typ „Datum + Uhrzeit“ ohne
Zeitzonenbezug – etwa Öffnungszeiten, die überall gleich gelten.


## Automatische Abmeldung

Nach einer Zeit ohne Aktivität (standardmäßig 30 Minuten) meldet camindo CMS Sie
automatisch ab. Das regelmäßige Nachsehen nach neuen Nachrichten im Hintergrund zählt
dabei nicht als Aktivität. Das Anmeldefenster erscheint dann über der
Arbeitsoberfläche. Nach der Anmeldung arbeiten Sie an derselben Stelle weiter –
***ungespeicherte Eingaben bleiben in der Eingabemaske erhalten.***

## Abmelden

Klicken Sie auf das Symbol ![logout](../_/logout.svg) in der Seitenleiste und
bestätigen Sie die Rückfrage. Abgemeldet wird nur dieser Browser.
Eingabemasken, die Sie auf einem anderen Gerät offen haben, bleiben dort bestehen.
Frontend-Editing, das Sie „bis zur Abmeldung“ eingeschaltet haben, endet damit. 

Wozu das Häkchen ***„Auf allen Geräten abmelden“*** gut ist:  
Sie stellen damit sicher, dass alle Ihre Sitzungen beendet sind und unbeabsichtigt 
gesperrte Datensätze (nicht korrekt geschlossene Eingabemaske) sofort für andere freigegeben werden.
Wenn Sie später doch noch eine offene Eingabemaske vorfinden und speichern wollen, ist das nicht mehr möglich.

Einzelne Geräte beenden Sie unter [Geräte und Sitzungen](#geräte-und-sitzungen).
