# 1 Wichtige Begriffe

Zum Verständnis der Handbücher werden hier einige Begriffe vorab erklärt. Sie
kommen in allen Kapiteln immer wieder vor.

## Frontend und Backend

camindo CMS hat zwei Gesichter:

- Das ***Frontend*** ist die im Internet sichtbare Website.
- Das ***Backend*** ist die Arbeitsumgebung, in der Inhalte gepflegt werden.  
  Auch das Backend öffnen Sie im Web-Browser, jedoch ist es nur berechtigten
  Personen Ihres Unternehmens zugänglich, den „Benutzern“.

## Benutzer und Benutzergruppe

***Benutzer*** heißt in camindo CMS jede Person, die im Backend
arbeitet – unabhängig von ihrer Rolle als Redakteur, Übersetzer, Administrator oder
Programmierer.

Jeder Benutzer gehört einer ***Benutzergruppe*** an. Die Benutzergruppe legt fest, welche
Module, Websites, Inhaltsbereiche und Sprachen ihre Mitglieder sehen und bearbeiten
dürfen. Die Benutzergruppe ***Superuser*** darf alles.

Website-Besucher, die sich im Frontend registrieren, heißen dagegen ***Frontend-User***;
ihr Abbild im CMS ist ein ***Profil-Datensatz*** (siehe [Kapitel 10](./10-community.md)).

## Instanz (Website)

Eine camindo-Installation kann mehrere Websites betreiben – jede mit eigenen
Domains, Sprachen und Inhalten. Eine solche Website heißt ***Instanz***. Arbeiten Sie
mit mehreren Instanzen, erscheint im Backend je Instanz ein eigener Hauptordner.

## Content

***Content*** bezeichnet die Gesamtheit der Inhalte, die im CMS verwaltet werden.

## Datensatz

Ein ***Datensatz*** ist eine inhaltliche Einheit – vergleichbar mit einem Artikel, der
aus „Titel“, „Text“, „Bild“ und „Autor“ besteht. Über die ***Eingabemaske*** legen Sie
Datensätze an, ändern und löschen sie.

Ein Datensatz muss kein Artikel im redaktionellen Sinn sein. Jede inhaltliche
Einheit kann ein Datensatz sein: eine Produktbeschreibung, eine Adresse, ein Termin,
ein Bild mit Bildunterschrift – oder einfach ein Schlagwort, ein Ortsname, eine
Rubrik.

Als eigener Datensatz lohnt sich ein Inhalt vor allem dann, wenn er an mehreren
Stellen der Website vorkommt.

Beispiel: Ein „Autor“ wird einmal angelegt und mit allen seinen
„Artikeln“ **verknüpft**, statt in jedem Artikel neu eingetippt zu werden. Ändert sich
seine Biografie, ändern Sie sie an einer Stelle. Ebenso ein Bild: Wird es als eigener
Datensatz abgelegt, kann es Zusatzinformationen tragen (Bildtext, Fotograf,
Copyright) und in beliebig viele Artikel eingebunden werden, ohne mehrfach
hochgeladen zu werden.

Beim Speichern ergänzt das CMS automatisch Hintergrundinformationen, die
***Metadaten***: wann und von wem der Datensatz angelegt und zuletzt geändert wurde,
wem er gehört (***Owner***) und welche Nummer er hat (**record_id**).

## Inhaltsbereich

Datensätze werden in ***Inhaltsbereichen*** gespeichert (im Backend auch
„Content-Bereich“ genannt). Jeder Inhaltsbereich hat seine eigene Feldstruktur, zum
Beispiel:

| Inhaltsbereich | Eingabefelder |
|---|---|
| Artikel | Titel, Datum, Teaser, Text, Bild, Autor |
| Adressen | Vorname, Nachname, Telefon, E-Mail |
| Bilder | Bilddatei, Bildtext, Copyright |

Jeder Inhaltsbereich stellt eine Eingabemaske, eine Suchmaske und eine
Datensatzliste bereit, die genau auf seine Felder abgestimmt sind. Ihr Administrator
kann jederzeit weitere Felder hinzufügen, auch während die Website in Betrieb ist.

Mehrere Inhaltsbereiche lassen sich in ***Ordnern*** zusammenfassen.

## Eingabefeld

Ein ***Eingabefeld*** nimmt einen Teil des Datensatzes auf – einen Titel, ein Datum,
eine Datei, eine Verknüpfung. Jedes Feld hat einen ***Typ*** (Text, Zahl, Datum,
Datei, Verknüpfung …), der bestimmt, was eingegeben werden kann und wie es geprüft
wird. Ein Feld je Inhaltsbereich ist das ***Hauptfeld***: Sein Inhalt steht
stellvertretend für den ganzen Datensatz, etwa in Listen und Verknüpfungen – meist
ist das der Titel oder Name.

## Status

Der ***Status*** beschreibt den Bearbeitungszustand eines Datensatzes, zum Beispiel
„Freigeschaltet“, „Deaktiviert“ oder „Unvollständig“. Nur Datensätze mit Status „Freigeschaltet“
können über das Frontend aufgerufen werden.  
Die Standardinstallation wird mit den beiden Status „Freigeschaltet“ und „Deaktiviert“
ausgeliefert. Zusätzliche Status kann Ihr Administrator hinzufügen. Diese sind
dann automatisch in allen Inhaltsbereichen mit Eingabefeld „Status“ wählbar.

## Zeitgesteuerte Veröffentlichung

Für jeden Inhaltsbereich kann ein ***Veröffentlichungsdatum*** und ein ***Ablaufdatum***
eingerichtet werden. Sind diese eingerichtet, lässt sich ein Datensatz zeitgesteuert publizieren.  
Vordatierte Datensätze (Veröffentlichungsdatum in der Zukunft) sind im Frontend nicht abrufbar.  
Abgelaufene Datensätze werden nicht mehr in automatisch generierten Listen aufgeführt, können
aber noch aufgerufen werden (Archivierung alter Inhalte).

## Workflow

camindo CMS unterstützt flache Workflows. Beispiele:

- Mitglieder einer Benutzergruppe erhalten eingeschränkten Zugriff auf Status, sie
  können beispielsweise selbst nicht freischalten.
- Mitglieder haben eingeschränkten Zugriff auf Sprachversionen, können Inhalte selbst
  nur in ihre Sprache übersetzen.
Datensätze können über [interne Nachrichten](./03-arbeitsoberflaeche.md) weitergereicht werden.

## Template

***Templates*** bestimmen, wie Inhalte auf der Website erscheinen. Sie enthalten die
Gestaltung (für Webseiten: HTML) und platzieren darin die Inhalte der Datensätze.  
Darüber hinaus können Templates komplexe Aufgaben übernehmen: Listen und Blätterfunktionen
erzeugen, verknüpfte Inhalte ermitteln, Formulare auswerten, Besucher registrieren und anmelden.  
HTML ist nur ein Publikationsweg. Templates können Inhalte auch in anderen Formaten ausgeben,
z. B. XML, JSON, PDF.

Dargestellt wird ein Datensatz automatisch mit dem Standard-Template seines Inhaltsbereichs.
Hat Ihr Administrator weitere Templates freigegeben, können diese in der Eingabemaske des
Datensatzes ausgewählt werden. Die Auswahl des Templates bestimmt dann die Darstellung im Frontend.

Einen Gesamtüberblick bietet das [Handbuch für Template-Programmierer](../templateprogrammers/README.md).

## Arbeitskopie und Bearbeitungssperre

Wenn Sie einen Datensatz bearbeiten, arbeitet camindo CMS an einer ***Arbeitskopie***.
Die Website zeigt weiterhin den bisherigen Stand, bis Sie speichern. Solange Sie
einen Datensatz bearbeiten, ist er für andere Benutzer gesperrt
(***Bearbeitungssperre***, siehe [Kapitel 5](./05-datensaetze-bearbeiten.md#bearbeitungssperre)).

## Papierkorb

Gelöschte Datensätze wandern zunächst in den ***Papierkorb*** und werden erst nach
einer Aufbewahrungsfrist endgültig entfernt. Standardmäßig beträgt diese 7 Tage. Jeder
Benutzer hat seinen eigenen Papierkorb und kann daraus wiederherstellen, was er
gelöscht hat (siehe [Kapitel 5](./05-datensaetze-bearbeiten.md#papierkorb)).

## Hintergrundprozess

Aufgaben mit langer Laufzeit – etwa ein großer Import, eine Stapelverarbeitung oder
die Umwandlung eines Videos – laufen als ***Hintergrundprozess***. Sie können
währenddessen weiterarbeiten; das Ergebnis erhalten Sie als interne Nachricht
(siehe [Kapitel 3](./03-arbeitsoberflaeche.md#hintergrundprozesse)).
