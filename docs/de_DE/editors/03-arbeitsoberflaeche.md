# 3 Die Arbeitsoberfläche

## Startseite

Nach der Anmeldung landen Sie auf der ***Startseite***. Sie ist in vier Plätze
aufgeteilt: Links oben stehen die Schaltflächen der ***Module***, die übrigen drei Plätze
können Sie mit ***Widgets*** belegen (siehe [unten](#widgets-der-startseite)).

Sortiert sind die Modulkacheln alphabetisch, in der eingestellten Backend-Sprache.
Welche Sie sehen, hängt von den Rechten Ihrer Benutzergruppe ab.

## Die Module

### Für Anwender
| Modul | Aufgabe | Handbuch |
|---|---|---|
| ![menu](../_/draft.svg)Inhalte | Datensätze aller Inhaltsbereiche suchen, anlegen, bearbeiten | Kapitel [4](./04-inhalte-finden.md) bis [8](./08-export-import.md) |
| ![menu](../_/person.svg)Benutzer | Benutzer und Benutzergruppen verwalten | [Administratoren](../admins/07-benutzer-und-rechte.md) |
| ![menu](../_/delete.svg)Papierkorb | Vom angemeldeten Benutzer zuvor gelöschte Datensätze | [Administratoren](../admins/10-module.md#papierkorb) |
| ![menu](../_/smart_toy.svg)KI-Assistent | Unterstützung durch KI erhalten | Zusatzmodul |

### Für Website-Administratoren
| Modul | Aufgabe | Handbuch |
|---|---|---|
| ![menu](../_/language.svg)Instanzen | Websites mit ihren Domains und Sprachen einrichten | [Administratoren](../admins/03-instanzen-und-sprachen.md) |
| ![menu](../_/flag.svg)Sprachen | Sprachen der Websites einrichten | [Administratoren](../admins/03-instanzen-und-sprachen.md#sprachen) |
| ![menu](../_/translate.svg)Snippets | Textbausteine der Website in allen Backend-Sprachen pflegen | [Administratoren](../admins/10-module.md#snippets) |
| ![menu](../_/transition_dissolve.svg)Struktur | Inhaltsbereiche und ihre Eingabefelder einrichten | [Administratoren](../admins/04-struktur.md) |
| ![menu](../_/check.svg)Status | Bearbeitungszustände für Datensätze festlegen | [Administratoren](../admins/10-module.md#status) |
| ![menu](../_/manufacturing.svg)Medienkonverter | Umwandlung von Bildern, Videos und Audiodateien einstellen | [Administratoren](../admins/08-medien.md) |

### Für Programmierer
| Modul | Aufgabe | Handbuch |
|---|---|---|
| ![menu](../_/extension.svg)Templates | Templates der Website bearbeiten | [Template-Programmierer](../templateprogrammers/README.md) |

### Für Systemadministratoren
| Modul | Aufgabe | Handbuch |
|---|---|---|
| ![menu](../_/schedule.svg)Zeitplaner | Zeitgesteuerte Aufgaben, z. B. Datenbanksicherungen | [Administratoren](../admins/10-module.md#zeitplaner) |
| ![menu](../_/build.svg)Werkzeuge | Wartung von Datenbank, Daten und Server | [Administratoren](../admins/10-module.md#werkzeuge) |
| ![menu](../_/box.svg)Komponenten | Zusatzmodule installieren und aktualisieren | [Administratoren](../admins/10-module.md#komponenten) |
| ![menu](../_/receipt_long.svg)Logdateien | Protokolle des Systems durchsehen | [Administratoren](../admins/10-module.md#logdateien) |
| ![menu](../_/manage_history.svg)Audit-Log | Änderungen aller Datensätze durchsehen | [Administratoren](../admins/10-module.md#audit-log) |

## Seitenleiste

Die Seitenleiste ist von jeder Seite aus erreichbar.

| Symbol | Bedeutung |
|---|---|
| ![menu](../_/menu.svg) | ***Hauptmenü*** – oben „![menu_book](../_/info.svg)Info“ (Version von camindo CMS) und „![menu_book](../_/menu_book.svg)Handbücher“, darunter dieselben Module wie auf der Startseite; so wechseln Sie von jeder Seite aus in ein anderes Modul |
| Logo | zurück zur Startseite |
| ![hourglass](../_/hourglass.svg) | ***Hintergrundprozesse*** – siehe unten |
| ![mail](../_/mail.svg) | ***Nachrichten*** – die Zahl am Symbol nennt die ungelesenen Nachrichten |
| ![settings](../_/settings.svg) | ***persönliche Einstellungen*** |
| ![logout](../_/logout.svg) | ***Abmelden*** |

## Handbücher

Im Hauptmenü ![menu](../_/menu.svg) öffnet ***„Handbücher“***
diese Handbücher direkt im Backend – in der Sprache der Oberfläche, solange es sie darin gibt,
ansonsten auf Englisch.  

Ein Handbuch öffnet sich als eigene [Ebene](#ebenen) über der Übersicht; die Pfadleiste
zeigt dann etwa „Handbücher › Handbuch für Anwender“. Ein Klick auf „Handbücher“ schließt
das Handbuch und führt zurück zur Übersicht.

Über dem Text stehen:

- ein ***Suchfeld*** – gefunden werden Abschnitte, in denen alle eingegebenen Wörter vorkommen,
- die Auswahl des ***Handbuchs*** (Anwender, Administratoren, Template-Programmierer und
  die Handbücher installierter Zusatzkomponenten) – sie begrenzt auch Suche und
  Stichwortverzeichnis,
- das ***Stichwortverzeichnis*** – alle Überschriften und die fett-kursiv gesetzten Begriffe von
  A bis Z, jeweils mit Link auf die Stelle,
- die ***Druckansicht*** ![print](../_/print.svg) – sobald ein Handbuch ausgewählt ist.

Am Ende jeder Seite führen ***„Zurück“*** und ***„Weiter“*** zum vorigen und zum nächsten
Kapitel desselben Handbuchs. Zusatzkomponenten (etwa der KI-Assistent) bringen eigene
Handbücher mit; die Übersicht der Handbücher listet sie unter „Handbücher der
Zusatzkomponenten“.

Die ***„Druckansicht“*** öffnet das Handbuch als Ganzes auf einer langen Seite in einem
neuen Fenster: zuerst seine Übersicht, dann alle Kapitel in der Reihenfolge des
Inhaltsverzeichnisses. Links innerhalb des Handbuchs springen an die Stelle im
Dokument, Links in andere Handbücher bleiben reiner Text. „Drucken“ öffnet den
Druckdialog des Browsers – dort können Sie das Handbuch auch ***als PDF speichern***.
Auf Papier beginnt jedes Kapitel auf einer neuen Seite.

## Ebenen

Jede Seite, die Sie öffnen, sei es eine Datensatzliste, eine Detailansicht, eine
Eingabemaske, legt sich als neue ***Ebene*** über die vorherige. Über der Seite
zeigt eine Pfadleiste („Brotkrümel-Navigation“), welche Ebenen geöffnet sind.
Ein Klick auf einen Eintrag darin schließt alle Ebenen bis zur gewünschten.

Die Vor- und Zurück-Schaltflächen des Browsers funktionieren zwar innerhalb des Backends,
können aber zu Missverständnissen führen und die Nutzung kann Datenverlust zur Folge haben
(geöffnete Eingabemaske schließt unbeabsichtigt). Besser ist es, ganz auf sie zu verzichten.

## Listen und Kontextmenüs

In allen Listen des Backends gilt:

- ***Klick auf eine Zeile*** öffnet die Detailansicht.
- ***Rechtsklick auf eine Zeile*** öffnet ein Kontextmenü mit den häufigsten
  Aktionen, etwa ![edit](../_/edit.svg) „Bearbeiten“,
  ![content_copy](../_/content_copy.svg) „Kopieren“ und
  ![delete](../_/delete.svg) „Löschen“. Aktionen, für die Ihnen das Recht fehlt,
  erscheinen nicht.
- ***Klick auf eine Spaltenüberschrift*** sortiert nach dieser Spalte, ein zweiter
  Klick kehrt die Richtung um.  
  Eine Sortierung, die von der vorgegebenen Sortierung im Bereich abweicht, wird
  gelb hinterlegt benannt, z. B. „Titel aufsteigend“. Ein Klick auf das X rechts außen
  setzt die Sortierung zurück.
- Das Menü ![more_vert](../_/more_vert.svg) rechts über einer Liste enthält
  weitere Funktionen – in der Datensatzliste etwa „Export“, „Import“ und
  „Stapelverarbeitung“.

## Bedienung mit der Tastatur

Das Backend lässt sich vollständig ohne Maus bedienen und mit einem Screenreader
nutzen. Mit der ***Tabulatortaste*** springen Sie von Element zu Element, mit
Umschalt+Tab zurück; das gerade gewählte Element ist umrandet.

- ***Zum Inhalt springen:*** Beim ersten Druck auf die Tabulatortaste erscheint oben
  links „Zum Inhalt springen“ und – beim zweiten – „Zur Pfadnavigation springen“.
  Mit der Eingabetaste überspringen Sie die Kopfleiste mit Hauptmenü und Symbolen.
- ***Hauptmenü:*** Die Schaltfläche ![menu](../_/menu.svg) öffnet es, der erste
  Eintrag ist sofort gewählt. Die Esc-Taste schließt es wieder; das gilt auch für die
  Liste der Hintergrundprozesse.
- ***Listen:*** Jede Zeile lässt sich mit der Tabulatortaste ansteuern. Die
  Eingabetaste öffnet die Detailansicht, Umschalt+F10 oder die Kontextmenütaste
  öffnet das Kontextmenü. Darin wählen Sie mit den Pfeiltasten, die Eingabetaste
  führt die Aktion aus, Esc schließt das Menü.
- ***Spaltenüberschriften*** sortieren auch mit der Eingabetaste.
- ***Ebenen:*** Öffnen Sie eine neue Ebene, steht die Auswahl auf ihrer Überschrift.
  Schließen Sie sie, kehrt die Auswahl dorthin zurück, von wo aus Sie die Ebene
  geöffnet haben – etwa zur Zeile der Liste.
- ***Formulare:*** Meldet das Speichern einen Fehler, steht die Auswahl auf dem
  ersten fehlerhaften Feld; die Fehlermeldung gehört zum Feld und wird von
  Screenreadern mit vorgelesen.
- ***Hinweise*** zu einem Feld (das Fragezeichen neben der Beschriftung) erscheinen
  auch, wenn Sie sie mit der Tabulatortaste ansteuern; Esc blendet sie aus.

Kurze Meldungen wie „Gespeichert“ bleiben einige Sekunden stehen, Fehlermeldungen
länger. Solange der Mauszeiger oder die Auswahl auf einer Meldung steht, bleibt sie
sichtbar. Hat Ihr Betriebssystem „Bewegung reduzieren“ eingeschaltet, verzichtet das
Backend auf Animationen; die Einblend-Animation der Lookup-Felder schalten Sie außerdem
in den [persönlichen Einstellungen](#persönliche-einstellungen) unter ***„Animation“*** aus.

## Hintergrundprozesse

Funktionen mit langer Laufzeit – Stapelverarbeitung, Import, Export, das Löschen
vieler Datensätze, die Umwandlung von Videos – laufen als ***Hintergrundprozess***.
Bei vielen dieser Funktionen können Sie selbst wählen:

- ***Im Vordergrund*** warten Sie, bis die Aufgabe erledigt ist, und sehen das
  Ergebnis sofort. Das ist für kleinere Aufgaben bequem.
- ***Im Hintergrund*** arbeiten Sie sofort weiter. Das Ergebnis – ein Protokoll oder
  ein Download-Link – kommt als interne Nachricht in Ihren Posteingang.

Ab einer bestimmten Anzahl von Datensätzen läuft eine Aufgabe immer im Hintergrund.

Solange ein Prozess läuft, dreht sich die Sanduhr ![hourglass](../_/hourglass.svg).
Ist der Prozess zu Ende gelaufen, erscheint kurz eine Meldung im Vordergrund.  
Ein Klick auf die Sanduhr öffnet die Liste Ihrer laufenden Prozesse mit Fortschrittsanzeige.
Dort können Sie einen Prozess auch abbrechen. Fehlgeschlagene Prozesse bleiben in der Liste stehen,
bis Sie sie entfernen.

## Nachrichten

Über das Brief-Symbol ![mail](../_/mail.svg) erreichen Sie die **internen Nachrichten**.
Sie können damit anderen Benutzern schreiben. Diese erhalten eine Nachricht nur
im Backend, nicht an ihre E-Mail-Adresse. Auf Wunsch schickt Ihnen camindo CMS
aber eine tägliche [Zusammenfassung per E-Mail](#nachrichten-per-e-mail-zusammenfassen).

- „Posteingang“ und „Postausgang“ stehen auf eigenen Reitern.
- Nachrichten lassen sich als gelesen/ungelesen und als wichtig markieren,
  beantworten und weiterleiten.
- ***Nachricht zu einem Datensatz:*** In der Detailansicht eines Datensatzes finden
  Sie im Menü ![more_vert](../_/more_vert.svg) den Eintrag „Nachricht“. Die Nachricht
  trägt dann einen Verweis auf den Datensatz – praktisch für Rückfragen oder
  Korrekturhinweise an Kollegen.
- Auch camindo CMS selbst schickt Ihnen Nachrichten: die Ergebnisse von
  Hintergrundprozessen, Import-Protokolle und Download-Links für Exporte.

### Nachrichten per E-Mail zusammenfassen

Wer nicht täglich ins Backend schaut, verpasst leicht eine Nachricht. In Ihren
persönlichen Einstellungen wählen Sie dafür ***„Nachrichten per E-Mail
zusammenfassen“*** – „täglich“. Sind seit der letzten Zusammenfassung neue Nachrichten
für Sie eingegangen, erhalten Sie morgens eine E-Mail an Ihre hinterlegte Adresse mit

- der Anzahl aller ungelesenen Nachrichten, nach Absender gruppiert, und
- den neuen Nachrichten mit Datum, Absender und Betreff.

Die E-Mail enthält ***keine Nachrichtentexte*** und keine Inhalte aus Datensätzen –
diese bleiben im System. Lesen können Sie die Nachrichten nur nach der Anmeldung im
Backend; die E-Mail enthält bewusst keinen Link dorthin. Ohne neue Nachrichten kommt
keine E-Mail. Voraussetzung ist eine E-Mail-Adresse in Ihrem Konto.

Nachrichten werden nach einer festen Frist automatisch gelöscht – üblich sind
60 Tage nach dem Versand, Ihr Administrator kann sie anders einstellen. Was Sie länger
brauchen, gehört nicht in eine Nachricht, sondern in einen Datensatz.

Löschen Sie eine Nachricht, verschwindet sie nur aus Ihrer Liste; der Empfänger bzw.
Absender behält die versendete Fassung.

## Persönliche Einstellungen

Das Zahnrad ![settings](../_/settings.svg) öffnet Ihre persönlichen Einstellungen.

| Einstellung | Wirkung |
|---|---|
| Backend-Sprache | Sprache der Oberfläche (wie bei der [Anmeldung](./02-anmeldung.md#sprache)) |
| Datums- und Zahlenformat | Gebietsschema für Datums-, Zeit- und Zahlenangaben |
| Zeitzone | Zeitzone, in der Sie Zeitpunkte sehen und eingeben |
| Bevorzugte Sprachversion | Bei mehrsprachigen Inhalten öffnet die Eingabemaske zuerst diese Sprache (siehe [Kapitel 6](./06-mehrsprachigkeit.md)) |
| Frontend-Editing | Bearbeiten-Verweise direkt auf der Website einblenden (siehe [Kapitel 9](./09-frontend-editing.md)) |
| Animation | „ein“ (Vorgabe): Öffnet ein Lookup-Feld die Liste des verknüpften Bereichs, gleitet sie von der Seite herein. „aus“: Die Liste erscheint sofort |
| Widgets der Startseite | siehe unten |
| Bericht abonnieren | Berichte, die der Administrator für Ihre Benutzergruppe freigegeben hat – nach jedem Lauf kommt das Ergebnis als Nachricht in Ihren Posteingang. Der Punkt erscheint nur, wenn es solche Berichte gibt |
| Nachrichten per E-Mail zusammenfassen | „täglich“: eine E-Mail mit den ungelesenen Nachrichten je Absender und den neuen mit Betreff, nur wenn neue eingegangen sind (siehe [Nachrichten per E-Mail zusammenfassen](#nachrichten-per-e-mail-zusammenfassen)) |
| Zwei-Faktor-Anmeldung | zeigt, womit Sie sich anmelden (Code per E-Mail, Authenticator-App oder Passkey); die Schaltfläche daneben richtet App und Passkeys ein (siehe [Kapitel 2](./02-anmeldung.md#passkey-anlegen)) |

### Widgets der Startseite

Die Startseite hat vier Plätze. Links oben stehen immer die Modulkacheln; auf die
übrigen drei Plätze („rechts oben“, „links unten“, „rechts unten“) können Sie
***Widgets*** legen – kleine Übersichten, die Module bereitstellen. Auf einem Platz
dürfen mehrere Widgets liegen, sie erscheinen dann untereinander.

Wählen Sie in den Einstellungen ein Widget und einen Platz und klicken Sie auf
„Hinzufügen“. „Entfernen“ nimmt es wieder heraus. Bitte beachten: Übernommen wird beides erst mit
„Speichern“. Angeboten werden nur Widgets der Module, auf die Sie Zugriff haben.

### Für Anwender

| Widget | Inhalt |
|---|---|
| Ungeprüfte Inhalte von Frontend-Usern | Beiträge und Profil-Datensätze von Frontend-Usern, die noch geprüft werden müssen (siehe [Kapitel 10](./10-community.md)) |
| Papierkorb | Anzahl der Einträge in Ihrem [Papierkorb](./05-datensaetze-bearbeiten.md#papierkorb); ein Klick öffnet ihn |

### Für Administratoren

| Widget | Inhalt |
|---|---|
| Logdateien | die wichtigsten Meldungen der Systemprotokolle der letzten Tage (nur Superuser) |

Für Superuser sind zusätzlich unter den Modulkacheln wichtige Systemmeldungen sichtbar, ohne dafür ein
Widget zu benötigen. 

### Für Template-Programmierer

| Widget | Inhalt |
|---|---|
| Template-Meldungen | Liste von Templates mit Fehlern (Parsingfehler) |

Zusatzmodule können weitere Widgets mitbringen.

## KI-Assistent (Zusatzmodul)

Ist das Zusatzmodul ***KI-Assistent*** ![smart_toy](../_/smart_toy.svg) installiert und hat Ihre
Benutzergruppe Zugriff auf das Modul, öffnet ***Strg+K*** den Assistenten im Seitenpanel – von jeder
Seite des Backends aus. Auf dem Mac ist es ***⌘+K*** (Befehlstaste). Ein zweites Strg+K
bzw. ⌘+K im Eingabefeld schließt das Panel wieder. Nur in einem Texteditor, der die Tasten
selbst belegt (etwa für „Link einfügen“), gilt dort dessen Funktion.

Geben Sie dem Assistenten eine Anweisung oder stellen Sie eine Frage. Zeilenumbrüche
erzeugen Sie mit `Umschalt+Enter`. Ein reines `Enter` sendet ab.  
Der Assistent sucht, liest, legt an und ändert Datensätze.  
Während er arbeitet, zeigt der Chat ***jeden Arbeitsschritt*** als kurze
Textantwort in Kursivschrift. Die endgültige Antwort ist ausführlicher.

- ***Anhalten:*** Neben der Anzeige „Der Assistent arbeitet …“ beendet „Anhalten“ die
  Arbeit nach dem laufenden Schritt. Versenden Sie eine neue Nachricht, geht es weiter.
- ***Bestätigen:*** Bevor der Assistent etwas ändert, löscht, verschiebt oder viele
  Datensätze auf einmal bearbeitet, fragt er. Die Rückfrage zeigt den Datensatz, bei
  Änderungen ***Vorher*** und ***Nachher*** je Feld und das Ergebnis eines
  ***Probelaufs***. Sie erlauben ***nur diese Aktion*** oder ***diese und ähnliche
  Aktionen*** derselben Art bis zum Ende des Chats – oder lehnen ab. Lesen und Anlegen
  führt er ohne Rückfrage aus.
- ***Anzeigen:*** Bitten Sie den Assistenten, Ihnen etwas zu zeigen – einen Datensatz,
  eine gefilterte Liste, eine Eingabemaske oder eine andere Ansicht, die Sie öffnen
  dürfen –, öffnet er es als Ebene neben dem Chat. Was er nicht selbst erledigen kann,
  öffnet er ebenso und sagt Ihnen Schritt für Schritt, was zu tun ist.
- ***Was Sie gerade geöffnet haben:*** Mit jeder Nachricht erfährt der Assistent, welche
  Ebenen Sie geöffnet haben – etwa die Liste eines Bereichs und darüber einen Datensatz –
  und bei der obersten Ebene auch Ihre Suchkriterien. Sie können deshalb einfach „Hilf
  mir bei diesem Artikel“ oder „Warum finde ich hier den Artikel X nicht?“ schreiben.
  Übermittelt werden Bezeichnungen und Kennungen, keine Inhalte; Suchwerte in
  verschlüsselten Feldern erscheinen nur als `[encrypted]`. Was Sie in einer offenen
  Eingabemaske eingegeben, aber noch nicht gespeichert haben, sieht er nicht.
- ***Fragen zur Bedienung:*** „Wie ändere ich effizient ein Wort in 100 Datensätzen?“ –
  der Assistent schlägt in den Handbüchern nach und öffnet Ihnen auf Wunsch die passende Seite.
- ***Offene Masken:*** Einen Datensatz, den Sie gerade in einer Eingabemaske geöffnet
  haben, ändert der Assistent nicht; er bittet Sie, die Maske erst zu speichern oder zu
  schließen.
- ***Neuer Chat:*** Einen neuen Chat beginnen Sie über das Symbol ![add_comment](../_/add_comment.svg) „Neuer Chat“.
- ***Bisherige Chats:*** Frühere Chats finden Sie über das Symbol ![view_headline](../_/view_headline.svg)
  „Bisherige Chats“ und auf der Startseite des Moduls im Reiter „Bisherige Chats“. Ein Klick auf eine
  Zeile setzt den Chat im Seitenpanel fort; über das Kontextmenü (rechte Maustaste) löschen Sie ihn.
  Je nach Einstellung bleibt ein gelöschter Chat noch einige Tage für Superuser lesbar.
- ***Seitenpanel schließen:*** Das X-Symbol rechts außen schließt das Seitenpanel.

Unter dem Eingabefeld stehen das Modell und die bisher verbrauchten ***Tokens*** des
Chats, getrennt nach Eingabe, davon aus dem Cache geholte Eingabe und Ausgabe.  
Wurde der Assistent mitten in der Arbeit unterbrochen (etwa weil der Browser vorzeitig geschlossen wurde),
bietet der Chat beim nächsten Öffnen „Fortsetzen“ oder „Anhalten“ an.

![warning](../_/warning.svg) Der Assistent ist ein Sprachmodell eines externen Anbieters.
Seine Antworten können falsch oder unvollständig sein – prüfen Sie Ergebnisse, und bestätigen Sie nur,
was Sie verstanden haben. Der Assistent arbeitet mit Ihren Benutzerrechten, darüber hinaus
hat er keinen Zugriff. Verändert er Inhalte, steht im Audit-Log Ihr Benutzername – allerdings mit der
Herkunft „llm“ (für Large Language Model). Ihre eigenen Aktivitäten tragen die Herkunft „backend“.
