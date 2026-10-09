# 10 Weitere Module

Neben den Modulen für Inhalte, Struktur, Templates, Instanzen, Sprachen, Benutzer und
Medien (Kapitel 3 bis 8) bringt camindo CMS weitere Module mit. Welche davon eine
Benutzergruppe sieht, legen Sie in der [Benutzergruppe](./07-benutzer-und-rechte.md#1-module-und-zusatzrechte)
fest – [Papierkorb](#papierkorb) und [Nachrichten](#nachrichten) hat jeder Benutzer.

## Status

Im Modul „Status“ legen Sie die Bearbeitungszustände fest, die Datensätze haben
können. Jeder Status hat ein Label, ein Icon, eine Farbe und eine Position in der
Auswahlliste. Vorbereitet sind:

| Status | Bedeutung |
|---|---|
| Freigeschaltet | erscheint auf der Website |
| Deaktiviert | erscheint nicht auf der Website |
| Unbestätigt | für Registrierungen von Frontend-Usern, bis der Bestätigungslink angeklickt wurde |

Auf der Website erscheinen standardmäßig nur Datensätze mit Status „Freigeschaltet“.
Standard für gesperrte Datensätze ist der Status „Deaktiviert“. Alle weiteren Status entsprechen
diesem – sie dienen lediglich dazu, redaktionelle Abläufe abzubilden, z. B. „Entwurf“, „Gegenzulesen“,
„Bereit zum Freischalten“, „Nachzubessern“ …

[CMS:SELECT](../templateprogrammers/select.md#state) findet nur freigeschaltete Datensätze.
Ausschließlich für interne Ansichten ist es möglich, dem Befehl den Parameter `filter[state]`
mitzugeben, um Datensätze eines bestimmten oder beliebigen Status zu listen.

In der Eingabemaske eines Status legen Sie unter ***„Setzen dürfen“*** fest, welche
Benutzergruppen Datensätzen diesen Status geben dürfen (Mehrfachauswahl; Superuser dürfen
immer alle Status setzen). Ein neuer Status ist zunächst für alle Benutzergruppen setzbar.
Dieselbe Einstellung finden Sie im Formular jeder Benutzergruppe – siehe
[Benutzer und Rechte](./07-benutzer-und-rechte.md#4-status-setzen).

Beim Löschen eines Status wählen Sie einen ***Ersatz-Status***; alle Datensätze mit dem
bisherigen Status werden umgestellt. Die vorbereiteten Status „Freigeschaltet“ und „Deaktiviert“
können nicht gelöscht werden.


## Snippets

camindo CMS kennt ***zwei Snippet-Systeme*** – zwei Wege, feste Texte getrennt von den
Templates zu pflegen. Sie unterscheiden sich darin, wo die Texte stehen und in welchen
Sprachen es sie gibt:

| | Frontend-Snippets | Backend-Snippets |
|---|---|---|
| Sprachen | die Sprachversionen der Instanz (z. B. `de_DE`, `sv_SE`) | die Backend-Sprachen (z. B. `de_DE`, `en_US`) |
| Von wem gepflegt wird | Redakteure, wie jeder Inhalt – auch per Frontend-Editing | Entwickler (Sprachdateien) bzw. Benutzer (Modul „Snippets“) |
| Wo gespeichert wird | Datensätze eines Inhaltsbereichs mit mehrsprachigem Textfeld | Sprachdateien `lang/{sprache}.ini` und eigene Snippets im Modul „Snippets“ |
| Wie eingebunden wird | im Template `(CMS:TEXT id=slug)` oder `(CMS:TEXT id=record_id)` | `#präfix.schlüssel` in den Feldern „Label“, „Anmerkung“ und „Tooltip“ |

Für Texte, die auf der Website erscheinen, sind die ***Frontend-Snippets*** der
vorgesehene Weg: Sie folgen genau den Sprachen der Website.

### Frontend-Snippets

Ein ***Frontend-Snippet*** ist ein gewöhnlicher Datensatz – etwa in einem
Inhaltsbereich „Textschnipsel“ mit einem Feld `slug` und einem mehrsprachigen
Textfeld `text`. Das Template holt den Text über den Slug oder die record_id:

```cms
<p class="hinweis">(CMS:TEXT id=versandhinweis)</p>
<p class="kontakt">(CMS:TEXT id=3109) (CMS:FED id=3109)</p>
```

Ohne `field` liest `CMS:TEXT` das Feld `text` des angegebenen Datensatzes, in der
Sprache der aufgerufenen Seite; ist ein Text nicht übersetzt, erscheint der Text der
Hauptsprache. Mit `CMS:FED id=…` erhält jeder Textbaustein sein eigenes Stift-Symbol
für das Frontend-Editing. Die Redaktion pflegt diese Texte wie alle Inhalte – mit
Status, Erscheinungsdatum und Rechten des Inhaltsbereichs. Ein Standard-Template
braucht der Bereich nicht.

### Backend-Snippets

***Backend-Snippets*** sind die Texte der Benutzeroberfläche. Sie stammen aus drei
Quellen und werden über einen Präfix angesprochen:

| Quelle | Präfix | Beispiel |
|---|---|---|
| Eigene Snippets der Instanz (Modul „Snippets“, siehe unten) | `custom.{instance_id}` | `#custom.12.label_preis` |
| Core: `Core/lang/{sprache}.ini` | keiner | `#button_save` |
| Module: `lang/` und `backend/lang/` im Verzeichnis des Moduls | Name des Moduls in Kleinbuchstaben | `#area.field_area_slug_prefix` |

In den Eingabefeldern „Label“, „Anmerkung“ und „Tooltip“ (Inhaltsbereiche und ihre Felder)
wird statt eines einsprachigen Textes der Verweis auf ein mehrsprachiges Snippet eingebunden – Form:
`#präfix.schlüssel` (siehe [Kapitel 5](./05-eingabefelder.md#name-label-und-hilfetexte)). Das Backend setzt
beim Anzeigen den Text der Backend-Sprache des angemeldeten Benutzers ein
(intern über `Cms::textOrSnippet()`); die Extension ![translate](../_/translate.svg) [Snippets](./extensions/snippets.md)
bietet dort eine Suche an.

Backend-Snippets sind auch ***im Frontend*** verfügbar: der Core verwendet sie
etwa für die Meldungen der CMS:USER_*-Befehle oder das Auslassungszeichen von
`CMS:TEXT limit=…`. Weil es Backend-Snippets nur in den Backend-Sprachen gibt, ist
jeder Sprachversion eine ***Backend-Sprache*** zugeordnet (Einstellung
„Backend-Sprache“ im Modul „[Sprachen](./03-instanzen-und-sprachen.md#sprachen)“):
`de_DE` und `de_AT` lesen beide die Snippets von `de_DE`. Eine Sprachversion, für die es
keine Backend-Übersetzung gibt, zeigt diese in `en_US`.
Templates können Backend-Snippets mittels `$this->cms->snippet('custom.schlüssel')` einbinden.

#### Zusatzsprachen nachinstallieren

Weitere Backend-Sprachen kommen als ***Übersetzungspakete*** in Form von `.ini`-Dateien.
Die Sprache steht am Anfang des Dateinamens: `sv_SE.ini`, `sv_SE_core.ini`, `sv_SE_ext_tinymce.ini`.
Abgelegt werden diese in `var/translation/translated/`.
Wirksam wird ein Paket nach dem Neuaufbau des Snippet-Caches (Modul „Werkzeuge“, Reiter
„Entwickler“, oder auf der Kommandozeile mit `php Core/cron/camindo.php refresh snippets`).

#### Eigene Zusatzsprache entwickeln

Um das Backend von camindo CMS selbst in eine weitere Sprache zu übersetzen, kopieren Sie die Übersetzungs-Vorlagen
aus dem Verzeichnis `var/translation/blueprint/`.

`en_US_core.ini` wird zu `{sprache}_core.ini`, `en_US_en_US.ini` zu `en_US_{sprache}.ini`.
`{sprache}_core.ini` enthält alle Übersetzungen, `en_US_{sprache}.ini` registriert die neue
Sprache in der englischen Sprachversion und damit für alle anderen Sprachen.

Beispiel für eine `en_US_no_NO.ini`:

```ini
[backend]
backend_language_no_NO = "Norsk"
```

Beispiel für eine `no_NO_core.ini`:

```ini
[frontend]
core.yes = "Ja"

[backend]
core.button_save = "Lagre"
ext.datepicker.option_mode = "Modus"
```

Was ein Paket nicht übersetzt, erscheint auf Englisch.

### Modul Snippets

Im Modul „Snippets“ pflegen Sie eigene Backend-Snippets – Beschriftungen wie
„Weiterlesen“, „Suchen“ oder ein Hinweis im Seitenfuß – ohne Zugriff auf Server oder
Templates, in allen Backend-Sprachen.

***Jede Instanz hat ihr eigenes Snippet-Set.*** Das Modul listet die Instanzen, auf die
Ihre Benutzergruppe Zugriff hat, mit dem ***Stand der Übersetzungen***: welche Sprachen
vollständig sind, welche teilweise übersetzt (mit Anzahl) und welche noch keine Texte
haben. Ein Klick öffnet das Set als Tabelle: eine Zeile je
***Schlüssel***, eine Spalte je angezeigter Sprache. Welche Sprachen als Spalten
erscheinen, wählen Sie über „Sprachen“ oberhalb der Tabelle (Häkchen setzen,
„Übernehmen“); ohne Auswahl sehen Sie nur Englisch. Die Auswahl gilt für alle Sets und
bleibt erhalten, bis Sie sich abmelden.

Ein Klick auf eine Zeile öffnet die Maske mit allen Backend-Sprachen untereinander.
Ein Rechtsklick öffnet das ***Kontextmenü***: „Bearbeiten“ wie der Klick,
„Kopieren“ öffnet eine neue Maske mit den Texten des Snippets – nur den Schlüssel
geben Sie neu ein –, „Löschen“ zeigt das Snippet mit allen Texten und entfernt es
nach Bestätigung in allen Sprachen. „Snippet hinzufügen“ legt ein neues an. Fehlende Übersetzungen
erhalten den englischen Text. Schlüssel bestehen aus Kleinbuchstaben, Ziffern und
Unterstrichen und lassen sich nach dem Anlegen nicht mehr ändern – Templates und
Beschriftungsfelder verweisen über sie auf das Snippet.

| Verwendung | Schreibweise |
|---|---|
| in den Templates der eigenen Instanz | `custom.{schlüssel}` |
| in Templates anderer Instanzen | `custom.{instance_id}.{schlüssel}` |
| in Beschriftungsfeldern des Backends (Label, Anmerkung, Tooltip) | `#custom.{instance_id}.{schlüssel}` – die Extension „Snippets“ bietet dort eine Suche an |

Welche Snippet-Sprache im Frontend gilt, bestimmt die Einstellung „Backend-Sprache“
der jeweiligen [Sprachversion](./03-instanzen-und-sprachen.md#sprachen).

Die Sets liegen unter `var/custom/snippets/{instance_id}/lang/{sprache}.ini`; wird die
Instanz gelöscht, wird auch ihr Set gelöscht. Sets im früheren PHP-Format
(`{sprache}.php`, noch ältere `de.php`) wandelt der Neuaufbau des Snippet-Caches in
`.ini`-Dateien um – im Modul „Werkzeuge“ (Reiter „Entwickler“, „Snippets“), mit
`php Core/cron/camindo.php refresh snippets` und bei jedem `upgrade`. Gibt es zu einer
Sprache schon eine `.ini`-Datei, werden beide zusammengeführt; bei gleichem Schlüssel
gilt die `.ini`-Datei. Die alte Datei bleibt als `{sprache}.php.bak` liegen und kann
gelöscht werden, sobald die Snippets wieder stimmen.

## Zeitplaner

Der ***Zeitplaner*** führt Aufgaben zeitgesteuert aus. Er setzt den minütlichen
[Cronjob](./12-betrieb.md#cronjobs) voraus. Die Wartungsaufgaben – Backup, Papierkorb,
temporäre Dateien, Fristen, Logs, Prüfungen – legt die Installation an (siehe
[Betrieb](./12-betrieb.md#scheduler)). Den Ausführungszeitpunkt können Sie anpassen.

| Aufgabe | Wirkung |
|---|---|
| ***URL-Aufruf*** | ruft eine Adresse auf (wahlweise mit Benutzername und Passwort) und speichert die Antwort unter `var/custom/html/…` oder verwirft sie – etwa um einen Export einer anderen Anwendung abzuholen oder einen Cache anzuwärmen. Dateinamen mit PHP-Endung sind nicht erlaubt |
| ***PHP-Aufruf*** | führt ein PHP-Script aus `var/custom/php/` auf der Kommandozeile aus – für projekteigene Automatisierungen. Die Ausgabe wird wie beim URL-Aufruf unter `var/custom/html/…` gespeichert oder verworfen |
| ***Datenbank-Backup*** | schreibt eine vollständige Sicherung der Datenbank nach `var/dbbackup/`. `backup-1.sql` ist immer die neueste; ältere rücken nach (`backup-2.sql` …), über die eingestellte Anzahl hinaus werden sie gelöscht. Läuft höchstens einmal am Tag. Jede neue Sicherung wird geprüft: Ist sie kleiner als 10 KB oder fehlt die Schlusszeile `-- Dump completed`, wird sie verworfen und der Lauf gilt als fehlgeschlagen. Die Detailansicht der Aufgabe markiert unbrauchbare Dateien |
| ***Logs aufräumen*** | löscht Logdateien unter `var/log/`, die älter sind als die eingestellte Frist (Vorgabe 90 Tage). Sicherheitsprotokolle (`security`, `csp`) haben eine eigene, meist längere Frist (Vorgabe 365 Tage). Außerdem werden fehlgeschlagene Anmeldeversuche mit Name und IP-Adresse aus der Datenbank gelöscht (Vorgabe nach 30 Tagen). Empfohlen: täglich nachts. Das [Audit-Log](#audit-log) hat eigene Fristen |
| ***Frontend-Cache aufräumen*** | löscht abgelaufene Dateien des Ausgabe-Caches unter `var/cache/include/`, den Templates mit `CMS:INCLUDE … caching=…` anlegen, dazu Dateien zu gelöschten oder seither geänderten Templates. Dateien mit unbegrenzter Frist bleiben, bis ihr Template geändert wird. Keine Einstellungen. Empfohlen: stündlich, etwa Minute `15`, alle anderen `*` |
| ***Volltext neu aufbauen*** | baut den [Volltext](./05-eingabefelder.md#volltext-neu-aufbauen) der gewählten Bereiche (ohne Auswahl: aller Bereiche) aus den Quellfeldern neu auf – nach einer Änderung der Quellfelder eines Volltextfelds. Die Datensätze selbst bleiben unverändert, das Audit-Log erhält keine Einträge. Reicht die maximale Ausführungszeit nicht, bricht der Lauf ab und gilt als fehlgeschlagen; erhöhen Sie sie dann |
| ***Papierkorb leeren*** | löscht Datensätze und Inhaltsbereiche endgültig, die länger als `admin.trash_retention_days` (Vorgabe 7 Tage) im Papierkorb liegen. Keine Einstellungen |
| ***Temporäre Dateien löschen*** | räumt `var/tmp/` auf (`admin.tmp_retention_hours`) und löscht abgelaufene Exportdateien (`admin.export_retention_hours`). Keine Einstellungen. Empfohlen: stündlich |
| ***Lösch-Policies anwenden*** | führt die fälligen Aufträge der [Lösch-Policies](./15-datenschutz.md#löschen-nach-lösch-policy) aus. Keine Einstellungen |
| ***Aufbewahrungsfristen anwenden*** | kürzt IP-Adressen, löscht abgelaufene Einträge des Audit-Logs, alte interne Nachrichten und nie bestätigte Registrierungen – nach den Fristen der Konfiguration. Keine Einstellungen |
| ***Audit-Log prüfen*** | prüft die Dateien des [Audit-Logs](#audit-log) auf Manipulation; Befunde erscheinen auf der Startseite. Keine Einstellungen |
| ***Nach Updates suchen*** | sucht neuere Versionen und bekannte Sicherheitslücken (siehe [Updates](./12-betrieb.md#updates)). Keine Einstellungen |
| ***Nachrichten per E-Mail zusammenfassen*** | schickt Benutzern, die es in ihren persönlichen Einstellungen gewählt haben, eine Zusammenfassung ihrer neuen internen Nachrichten (siehe [Nachrichten](#nachrichten)). Neu sind die Nachrichten seit dem vorigen Lauf – einmal täglich ausführen. Keine Einstellungen |
| ***Template*** | führt ein Frontend-Template mit der Verwendung „Zeitgesteuert“ aus, in einer Sprache seiner Website – etwa für E-Mails zum Ablauf einer Sperrfrist, Erinnerungen oder einen Feed. Das Template erfährt, welcher Zeitraum seit dem letzten erfolgreichen Lauf vergangen ist; schlägt ein Lauf fehl, holt der nächste diesen Zeitraum nach. Die Ausgabe wird wie beim URL-Aufruf gespeichert oder verworfen. Meldet das Template einen Fehler (etwa eine E-Mail ohne gültigen Empfänger), gilt der Lauf als fehlgeschlagen |

Die ***Zeitsteuerung*** folgt der Cron-Schreibweise mit sechs Angaben: Minute, Stunde,
Tag, Monat, Jahr, Wochentag (0 = Sonntag). Je Angabe: `*` für jeden Wert, eine Zahl,
einen Bereich `1-5`, einen Schritt `*/10` oder eine Liste `1,15,30`. Die Aufgabe läuft,
wenn alle sechs Angaben zutreffen. Beispiel für täglich um 3:30 Uhr: Minute `30`,
Stunde `3`, alle anderen `*`. Die Zeiten gelten in der Zeitzone des Systems.

***Ausgaben und Scripte getrennt:*** Abgeholte oder erzeugte Inhalte landen nur unter
`var/custom/html/`, ausführbare Scripte liegen nur unter `var/custom/php/`. So kann
eine Antwort, die ein URL-Aufruf abholt, nie als PHP-Script ausgeführt werden.
Pfadangaben dürfen nicht aus dem jeweiligen Verzeichnis herausführen (kein `..`, keine
Verknüpfungen nach außen). Bestehende Scripte verschieben Sie beim Update nach
`var/custom/php/`.

***Ausführen als:*** Jede Aufgabe läuft unter einem Benutzer und mit dessen
Rechten; im [Audit-Log](#audit-log) erscheint er mit der Herkunft „scheduler“. Neue
Aufgaben laufen unter dem Benutzer `scheduler`, den die Installation anlegt
(Benutzergruppe Superuser, ohne Anmeldung im Backend). Zur Auswahl stehen Benutzer, deren
Benutzergruppe die [Oberfläche](./07-benutzer-und-rechte.md#oberflächen) „Kommandozeile
(CLI)“ benutzen darf. Wird der Benutzer gelöscht oder gesperrt oder verliert seine
Benutzergruppe dieses Recht, schlägt die Aufgabe mit einem Hinweis fehl, bis Sie ihr einen
anderen zuweisen. Ein PHP-Script arbeitet über die API unter diesem Benutzer
(siehe Entwicklerdokumentation).

Weitere Einstellungen: aktiv/inaktiv, maximale Ausführungszeit und ob jeder Lauf ins
Systemprotokoll geschrieben wird (Fehler immer). Die Liste zeigt nächsten und letzten
Lauf mit Ergebnis; „Jetzt ausführen“ startet eine Aufgabe sofort als
Hintergrundprozess.

## Werkzeuge

Das Modul „Werkzeuge“ hat fünf Reiter; für jeden gibt es ein eigenes Zusatzrecht.

| Reiter | Werkzeuge |
|---|---|
| „Server“ | Versionen von camindo CMS, Betriebssystem, Webserver, Datenbank und PHP; Prüfung und Erneuerung der `.htaccess`-Datei; MySQL-Konfiguration; Verfügbarkeit von ImageMagick und ffmpeg; PHP-Info |
| „Datenbank“ | „Papierkorb leeren“ – endgültiges Löschen von Datensätzen und Bereichen, die älter als *n* Tage im Papierkorb liegen (0 = alles); „Arbeitskopien verwerfen“ – liegen gebliebene Arbeitskopien älter als *n* Stunden entfernen und die Originale wieder freigeben |
| „Daten“ | „Thumbnails neu erzeugen“ für alle Datensätze eines Bereichs (siehe [Kapitel 8](./08-medien.md#thumbnails-neu-erzeugen)); „Baum normalisieren“ – prüft und repariert die Reihenfolge in Bereichen mit Baumstruktur, falls sie etwa durch direkte Datenbankeingriffe durcheinandergeraten ist; die bestehende Reihenfolge bleibt erhalten |
| „Papierkorb eines Users“ | Profil und Beiträge eines Website-Users, die im Papierkorb liegen, sofort endgültig löschen – etwa auf Verlangen des Users (siehe [Kapitel 15](./15-datenschutz.md#papierkorb-eines-website-users-sofort-leeren)) |
| „Entwickler“ | Caches erneuern (Snippets, Backend-Vorlagen, Instanzen); Template-Dateien neu übersetzen und mit `var/templates` abgleichen (samt Parser-Meldungen – auf der Kommandozeile `php Core/cron/camindo.php cache templates`, bei `upgrade` automatisch); Zugriffsregeln des Medienverzeichnisses erneuern; Sitzungsdaten anzeigen |

Die Caches erneuert camindo CMS nach Änderungen selbst. Die Werkzeuge auf dem Reiter
„Entwickler“ brauchen Sie nur nach Eingriffen am Dateisystem oder zur Fehlersuche.

## Template-Meldungen (Widget)

Das Modul „Templates“ bringt ein ***Widget*** für die Startseite mit (persönliche
Einstellungen, „Widgets der Startseite“): Es listet jedes Template mit Parser-Meldungen – Fehler rot, Hinweise gelb – und
öffnet es per Klick. Das hilft vor allem, wenn Templates im Dateisystem
(`var/templates`) bearbeitet werden und niemand den Editor sieht. Dazu steht immer der
Hinweis, dass jedes Script im Template `<script(CMS:NONCE)>` braucht, damit es unter der
Script-Policy der Website läuft ([Kapitel 3](./03-instanzen-und-sprachen.md#script-policy)).
Sichtbar ist es für Benutzergruppen mit Zugriff auf das Modul „Templates“; gezeigt werden nur
Template-Bereiche, die die Benutzergruppe lesen darf.

## Logdateien

Das Modul „Logdateien“ (nur Superuser) macht die Protokolle unter `var/log/` im
Backend recherchierbar. Filtern lässt sich nach Protokoll, Tag, Art und Suchtext.
Die Trefferliste zeigt die neuesten Einträge zuerst, je Seite 100; ***„Ältere Einträge“***
und ***„Neuere Einträge“*** blättern weiter, die Filter bleiben dabei erhalten.
Die Einträge sind nach ***Arten*** eingeordnet, in der Reihenfolge ihrer Dringlichkeit:

| Art | Beispiele |
|---|---|
| Gesperrte Anmeldungen | zu viele Fehlversuche bei der Anmeldung |
| Sicherheit | Anzeichen für Manipulationen |
| Nicht versendete E-Mails | Fehler beim E-Mail-Versand |
| PHP-Fehler | Programmabbrüche |
| Datenbankfehler | fehlerhafte Datenbankabfragen |
| Hintergrundprozesse | Fehler bei Import, Export, Stapelverarbeitung, Thumbnails |
| PHP-Warnungen, Sonstiges | Hinweise |

Das ***Widget*** „Logdateien“ fasst die wichtigsten Meldungen der letzten Tage auf der
Startseite zusammen.

## Audit-Log

Das ***Audit-Log*** hält fest, wer wann was geändert hat – mit dem Stand vorher und
nachher. Protokolliert werden:

- jedes Anlegen, Ändern, Löschen (Papierkorb), Wiederherstellen und endgültige
  Löschen von Datensätzen, Bereichen, Feldern, Templates, Benutzern, Benutzergruppen,
  Instanzen, Sprachen, Status, Konvertern, Zeitplaner-Aufgaben und Komponenten,
  gleich ob über die Eingabemaske, das Frontend (Beiträge von Website-Usern), einen
  Import, die Stapelverarbeitung oder die Zeitplaner-Aufgabe „Papierkorb leeren“
- fehlgeschlagene Anmeldungen am Backend; erfolgreiche An- und Abmeldungen nur mit
  `audit.log_logins = true` (Vorgabe: nicht aufzeichnen)
- jeder [Datenexport personenbezogener Daten](./15-datenschutz.md#datenexport-personenbezogener-daten)
- jede Änderung durch eine [Lösch-Policy](./15-datenschutz.md#löschen-nach-lösch-policy) (Aktion „Datenschutz“)

Bei Änderungen werden ***alle Felder*** verglichen, auch Metafelder wie Status,
Veröffentlichungsdatum oder Owner, bei mehrsprachigen Feldern jede Sprache einzeln.
Gespeichert wird nur, was sich tatsächlich geändert hat; ein Speichern ohne Änderung
erzeugt keinen Eintrag. Passwörter erscheinen nie im Klartext, nur als „geändert“.
Verschlüsselte Felder bleiben im Protokoll verschlüsselt und werden erst bei der
Anzeige entschlüsselt. Private Nachrichten zwischen Benutzern werden nicht
protokolliert, ebenso wenig der [Volltext](./05-eingabefelder.md#volltext): Er ist aus
den Textfeldern abgeleitet, und die Zeitplaner-Aufgabe „Volltext neu aufbauen“
hinterlässt keine Einträge.

***Reiter „Änderungen“:*** In der Detailansicht jedes Datensatzes zeigt der Reiter
„Änderungen“ die letzten zehn Einträge seines Verlaufs, neueste Änderung zuerst;
den vollständigen Verlauf zeigt das Modul „Audit-Log“. In Auswahlfenstern
(Verknüpfungsfelder) gibt es den Reiter nicht. Je Feld stehen Vorher und
Nachher nebeneinander. Texte werden absatzweise verglichen: Unveränderte Absätze
sind grau, entfernte rot, neue grün; in geänderten Absätzen sind die einzelnen
Wörter markiert. Den Reiter sieht jeder, der den Bereich lesen darf; die IP-Adresse
nur Superuser.

Denselben Reiter ***„Änderungen“*** haben die Detailansichten von Bereichen,
Eingabefeldern, Templates, Status, Instanzen, Sprachen, Konvertern,
Zeitplaner-Aufgaben, Komponenten und Benutzergruppen – jeweils für alle, die die
Detailansicht öffnen dürfen. Bei Benutzern sehen ihn nur Superuser; er zeigt,
was am Konto geändert wurde (auch Anmeldungen), der Reiter „Aktivitäten“ daneben,
was der Benutzer selbst getan hat. Bei Templates wird der Quelltext verglichen;
die eingefärbte Ansicht, die daraus entsteht, erscheint nicht im Protokoll.

***Modul „Audit-Log“*** (nur Superuser): Durchsucht das gesamte Protokoll nach Zeitraum,
Benutzer, Aktion, Objektart, Id, Bezeichnung, Name oder IP-Adresse und nach
geänderten Feldern (etwa `status_id`), seitenweise wie die übrigen Listen. Ein Klick auf einen Eintrag öffnet den
Vergleich; „Gesamter Verlauf“ zeigt alle Einträge zu diesem Objekt.

***Recherche über die API und den KI-Assistenten:*** Dieselben Einträge lassen sich über
die Programmierschnittstelle suchen und lesen – so kann auch der
[KI-Assistent](#zusatzmodul-ki-assistent) Fragen beantworten wie „Wer hat gestern den
Artikel X gelöscht?“ oder „Wie hat sich dieser Datensatz seit seiner Erstellung
verändert?“, auch wenn der Datensatz im Papierkorb liegt oder endgültig gelöscht ist.
Superuser sehen dabei das ganze Protokoll; alle anderen – wie im Reiter „Änderungen“ –
nur die Einträge zu Datensätzen in Bereichen, die sie lesen dürfen, und keine
IP-Adressen.

***Reiter „Aktivitäten“:*** In der Detailansicht eines Benutzers (Modul
„Benutzer“) zeigt der Reiter „Aktivitäten“ nur Superusern, was dieser
Benutzer im Audit-Log hinterlassen hat: oben die ***zuletzt bearbeiteten
Datensätze*** – je Datensatz die letzte Aktion mit Zeitpunkt und die Zahl seiner
Einträge, ein Klick darauf öffnet den Verlauf –, darunter ***alle Aktionen***
seitenweise, einschließlich Anmeldungen und Änderungen an Bereichen oder Feldern.
„Im Audit-Log öffnen“ führt ins Modul, vorgefiltert auf diesen Benutzer.

***Aufbewahrung:*** Einstellungen in `.camindo.php` (siehe
[Konfiguration](./12-betrieb.md#konfiguration)):

| Schlüssel | Vorgabe | Bedeutung |
|---|---|---|
| `audit.retention_days_changes` | `0` | Einträge zu bestehenden Objekten, in Tagen. `0` = unbegrenzt: Jeder Datensatz bleibt bis zu seiner Entstehung nachvollziehbar |
| `audit.log_logins` | `false` | Erfolgreiche An- und Abmeldungen am Backend aufzeichnen. Sie sagen nichts über Änderungen, wohl aber über Arbeitszeiten – deshalb aus |
| `audit.retention_days_logins` | `90` | An- und Abmeldungen sowie fehlgeschlagene Anmeldungen, in Tagen. `0` = wie `audit.retention_days_changes` |
| `audit.retention_days_deleted` | `365` | Verlauf endgültig gelöschter Objekte, gezählt ab dem endgültigen Löschen (nach dem Papierkorb). `0` = unbegrenzt. Gilt nicht für Datensätze: Deren Verlauf überschreibt oder löscht die [Lösch-Policy](./15-datenschutz.md#löschen-nach-lösch-policy) des Bereichs |
| `audit.enabled` | `true` | Audit-Log ein- oder ausschalten |
| `audit.page_bytes` | `1048576` | Größe in Bytes, ab der die Datei eines Objekts unter `var/audit/` eine neue Seite beginnt |

Abgelaufene Einträge entfernt die Zeitplaner-Aufgabe „Aufbewahrungsfristen anwenden“ (Vorgabe: jede Nacht).

## Datenschutz

Das Modul „Datenschutz“ hat diese Reiter:

| Reiter | Inhalt | Zusatzrecht |
|---|---|---|
| „Lösch-Policies“ | legen fest, was beim Löschen eines Datensatzes geschieht – in den Papierkorb legen, sofort oder zeitversetzt endgültig löschen, einzelne Felder überschreiben, den Verlauf im Audit-Log überschreiben oder löschen, eigene Regeln im Trigger-Template ausführen – auch für Unterdatensätze, die Beiträge eines gelöschten Profils und die Datensätze eines gelöschten Bereichs. Jeder Inhaltsbereich hat eine Policy | „Lösch-Policies anlegen und ändern“ – für die Instanzen, auf die die Benutzergruppe Zugriff hat |
| „Vorlagen“ | Vorlagen der Datenauskunft je Instanz, Art (Website-User oder Benutzer) und Sprache – siehe [Kapitel 15](./15-datenschutz.md#die-vorlagen-der-datenauskunft) | „Vorlagen der Datenauskunft pflegen“ – für die Instanzen, auf die die Benutzergruppe Zugriff hat |
| „Datenexport“ | Datenexport personenbezogener Daten eines Website-Users oder eines Benutzers als Zip | „Personenbezogene Daten exportieren“ – für Profile der lesbaren Bereiche und Benutzer der sichtbaren Benutzergruppen |
| „Gelöschte Benutzer“ | Liste der gelöschten Benutzer mit Benutzergruppe und Löschdatum – nur aus den Benutzergruppen, deren Benutzer Sie im Modul „Benutzer“ sehen dürfen. Ein Klick führt auf die Infoseite des Benutzers im Modul „Benutzer“; ist er noch nicht anonymisiert, steht dort die Schaltfläche „Anonymisieren“. Siehe [Kapitel 15](./15-datenschutz.md#gelöschte-benutzer-anonymisieren) | Reiter: Zugriff auf das Modul „Benutzer“. Anonymisieren: „Gelöschte Benutzer anonymisieren“ – für Benutzer der sichtbaren Benutzergruppen |

Lesen dürfen die Policies alle Benutzer mit Zugriff auf das Modul. Die drei
Standard-Policies und Policies für alle Instanzen ändert nur ein Superuser. Ausführlich:
[Kapitel 15](./15-datenschutz.md#löschen-nach-lösch-policy).

## Komponenten

Das Modul „Komponenten“ zeigt alle Module, Medien-Prozessoren und Extensions mit
Herkunft (Core oder Zusatz), Version und Zustand:

| Zustand | Bedeutung |
|---|---|
| Core | Bestandteil des Core, immer aktiv |
| entdeckt – nicht installiert | die Dateien sind vorhanden, die Komponente ist aber noch nicht eingerichtet |
| installiert, inaktiv | eingerichtet, aber abgeschaltet |
| aktiv | in Backend und Frontend verfügbar |
| Update wartet | neuere Dateien liegen vor; „Aktualisieren“ zieht die Datenbank nach |
| verwaist – Dateien fehlen | die Dateien wurden entfernt, ohne die Komponente zu deinstallieren |

Die Aktionen „Installieren“, „Aktivieren“, „Deaktivieren“, „Aktualisieren“ und
„Deinstallieren“ erklären in ihrer Maske, was sie tun. Beim Deinstallieren bleiben
Tabellen und Daten erhalten, sofern Sie nicht ausdrücklich „Tabellen und Daten
löschen“ wählen; auf Wunsch wird vorher die Datenbank gesichert. „Abgleich“
vergleicht die vorhandenen Dateien mit der Datenbank.

Die ***Dateien*** einer Komponente holt und entfernt immer Composer auf der
Kommandozeile (`composer require …` / `composer remove …`, siehe
[Installation](./01-quickstart-installation.md#zusatzkomponenten-installieren)); das
Modul richtet sie in der Datenbank ein.

## Papierkorb

Das Modul „Papierkorb“ hat jeder Benutzer, unabhängig von seiner Benutzergruppe; es
erscheint als Schaltfläche auf der Startseite und als Widget, das die Anzahl der Einträge
zeigt. Wie Redakteure damit arbeiten, beschreibt das
[Handbuch für Anwender](../editors/05-datensaetze-bearbeiten.md#papierkorb).

Jeder Eintrag steht für ***einen Löschvorgang***: Wird ein Datensatz mit seinen
untergeordneten Datensätzen gelöscht, erscheint nur der oberste; fest verbundene
Inhalte und die Datensätze eines gelöschten Bereichs erscheinen gar nicht einzeln.
„Wiederherstellen“ holt immer alles zurück, was im selben Vorgang gelöscht wurde –
erkannt am identischen Löschzeitpunkt. Was vorher schon in einem eigenen Vorgang
gelöscht worden war, bleibt im Papierkorb.

| Wer | sieht |
|---|---|
| jeder Benutzer | „Von mir gelöschte Datensätze“ – aus Bereichen, auf die er Zugriff hat, und nicht aus gelöschten Bereichen |
| Superuser | zusätzlich „Gelöschte Bereiche“ (mit Instanz, Umfang und wer gelöscht hat) und über ![group](../_/group.svg) „Alle Benutzer“ die gelöschten Datensätze aller Benutzer |

Wiederherstellen setzt das Löschrecht im Bereich voraus (siehe
[Bereichsrechte](./07-benutzer-und-rechte.md#5-bereichsrechte)); fehlt es, steht bei dem Eintrag
„keine Berechtigung“. Wie lange Einträge im Papierkorb bleiben, bestimmt die
[Aufbewahrungsfrist](./12-betrieb.md#papierkorb). Große Vorgänge (mehr Datensätze als
`admin.mass_delete_threshold`) laufen wie beim Löschen als Hintergrundprozess.

## Nachrichten

Die internen ***Nachrichten*** zwischen Benutzern erreichen Sie über das
Brief-Symbol in der Seitenleiste. Sie sind im
[Handbuch für Anwender](../editors/03-arbeitsoberflaeche.md#nachrichten) beschrieben.
Nachrichten werden `privacy.message_days` Tage (Vorgabe 60) nach dem Versand
automatisch gelöscht; alle Nachrichten eines Benutzers löscht ein Superuser in dessen
Einstellungen (siehe [Kapitel 15](./15-datenschutz.md#benutzer-mitarbeiter)).

Jeder Benutzer kann sich in seinen persönlichen Einstellungen eine tägliche
***Zusammenfassung per E-Mail*** schicken lassen: ungelesene Nachrichten je Absender
und die neuen mit Datum, Absender und Betreff – ohne Nachrichtentexte, ohne Inhalte
aus Datensätzen und ohne Link ins Backend. Verschickt wird sie von der
Zeitplaner-Aufgabe „Nachrichten per E-Mail zusammenfassen“ (Vorgabe täglich 7:00),
nur wenn seit dem vorigen Lauf neue Nachrichten eingegangen sind. Voraussetzung sind
der [E-Mail-Versand](./12-betrieb.md#e-mail-versand) und eine E-Mail-Adresse im Konto
des Benutzers. Bedenken Sie, dass Absender und Betreff damit das Haus verlassen.

## Zusatzmodule

***Zusatzmodule*** sind eigene Pakete, die Sie per Composer holen und als Komponente
installieren (siehe [Zusatzkomponenten installieren](./01-quickstart-installation.md#zusatzkomponenten-installieren)).
Jedes bringt sein eigenes Handbuch mit: Nach der Installation erscheint es in der
Übersicht der Handbücher unter „Handbücher der Zusatzkomponenten“ – mit Einrichtung,
Einstellungen und allen Einzelheiten.

### Zusatzmodul PDF

Das Zusatzmodul ***PDF*** (`camindo/module-pdf`) erzeugt PDF-Dokumente direkt aus
Templates – Rechnungen, Tickets, Datenblätter, Urkunden –, auf Wunsch auf einer
PDF-Vorlage wie einem Briefbogen, mit Texten, HTML, Bildern, Linien und Tabellen,
in RGB oder CMYK.

```bash
composer require camindo/module-pdf
php Core/cron/camindo.php component install Pdf --activate
```

### Zusatzmodul KI-Assistent

Das Zusatzmodul ***KI-Assistent*** (`camindo/module-ai`) bringt ein Sprachmodell ins
Backend: Benutzer schreiben im Seitenpanel, was sie brauchen, und der Assistent sucht,
liest, legt an und ändert – mit den Rechten des Benutzers und erst nach Bestätigung.
Wie Redakteure damit arbeiten, beschreibt das
[Handbuch für Anwender](../editors/03-arbeitsoberflaeche.md#ki-assistent-zusatzmodul).

```bash
composer require camindo/module-ai
php Core/cron/camindo.php component install Ai --activate
```

***Wer ihn nutzen darf***, legen Sie über das Modul „KI-Assistent“ in der
[Benutzergruppe](./07-benutzer-und-rechte.md#1-module-und-zusatzrechte) fest.
Anbieter und Zugangsschlüssel stehen in der [Konfiguration](./12-betrieb.md#konfiguration)
(`ai.*`).
