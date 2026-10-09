# 2 Das Konzept

Dieses Kapitel erklärt die Ideen, auf denen camindo CMS aufbaut. Wer sie kennt,
richtet eine Website schneller und sauberer ein – und nutzt Möglichkeiten, die andere
Systeme nicht bieten.

## Inhaltsbereiche statt Datenbanktabellen

In vielen Content-Management-Systemen und Webanwendungen braucht jede neue Art von
Inhalt – Produkte, Termine, Filialen, Stellenanzeigen – eine eigene
Datenbanktabelle, eigene Eingabemasken und eigenen Programmcode. Jede Änderung der
Struktur ist ein Auftrag an einen Entwickler.

camindo CMS geht einen anderen Weg. Sie legen im Backend ***Inhaltsbereiche*** an und
geben ihnen ***Eingabefelder*** – beliebig viele, in beliebiger Kombination, jederzeit
erweiterbar, auch während die Website in Betrieb ist. An der Datenbank ändert sich
dabei nichts, und es wird keine Zeile programmiert.

Jeder Inhaltsbereich bekommt dabei automatisch alles, was zur Pflege seiner Inhalte
nötig ist:

- eine ***Eingabemaske*** mit Eingabeprüfung, Pflichtfeldern und Hilfetexten,
- eine ***Suchmaske*** mit Platzhaltern, Vergleichsoperatoren und Volltextsuche,
- eine sortierbare ***Datensatzliste***,
- ***Stapelverarbeitung***, ***CSV-, DOCX- und XML-Export und -Import***,
- ***Mehrsprachigkeit*** je Feld, ***Arbeitskopien***, ***Bearbeitungssperren*** und
  ***Papierkorb***,
- ***Zugriffsrechte*** je Benutzergruppe,
- und den Zugriff aus den Templates mit Filtern, Sortierung und Blätterfunktion.

Die Felder sind dabei keine bloßen Textschnipsel: Jedes Feld hat einen ***Typ*** – Text,
Ganzzahl, Währungsbetrag, Datum mit Zeitzone, Datei, Verknüpfung und viele mehr. Der
Typ bestimmt, wie eine Eingabe geprüft, gespeichert, sortiert und durchsucht wird.
Intern liegt jeder Typ in einer eigenen, indizierten Tabelle; Filtern und Sortieren
bleiben dadurch auch bei großen Datenmengen schnell.

***Beispiel – ein Veranstaltungskalender:***

| Inhaltsbereich | Eingabefelder |
|---|---|
| Veranstaltungen | Titel, Beginn, Ende, Beschreibung, Bild, Eintritt (Währungsbetrag), Ort (Verknüpfung), Rubriken (Verknüpfung) |
| Orte | Name, Adresse, Anfahrt, Karte (Datei) |
| Rubriken | Bezeichnung, Farbe |

Drei Inhaltsbereiche, eine halbe Stunde Einrichtung – und die Redaktion kann
arbeiten.

## Ein Feldname, eine Bedeutung: über Bereiche hinweg filtern

Feldnamen gelten in camindo CMS **systemweit**: Ein Name ist immer an denselben Typ
gebunden. Legen Sie in den Bereichen „Meldungen“, „Veranstaltungen“ und „Blog“ jeweils
ein Feld `datum` vom Typ „Datum + Uhrzeit“ an, ist `datum` überall dasselbe Merkmal.

Das eröffnet eine besondere Möglichkeit: Templates können **mehrere Inhaltsbereiche
gemeinsam** abfragen, filtern und sortieren, als wären sie eine Tabelle. Eine
Zeitleiste aus Meldungen, Veranstaltungen und Blogbeiträgen ist ein einziger Befehl:

```cms
(CMS:SELECT zeitleiste_eintrag
  filter[area]=meldungen,veranstaltungen,blog
  filter[datum] >= 2026-01-01
  sort=datum
  order=desc
  limit=20
)
```

Ebenso findet eine Suche nach `filter[schlagwort]=…` die passenden Datensätze in allen
Bereichen, die ein Feld `schlagwort` haben. Zusätzlich hat jeder Datensatz ein
***Hauptfeld*** (meist Titel oder Name), über das Listen und Verknüpfungen ihn
bereichsübergreifend benennen können – ohne zu wissen, wie das Feld im einzelnen
Bereich heißt.

***Empfehlung:*** Geben Sie Feldern mit derselben Bedeutung in allen Bereichen
denselben Namen (`titel`, `datum`, `text`, `bild`, `slug`) und Feldern mit
unterschiedlicher Bedeutung unterschiedliche Namen. Diese Namenskonvention zahlt sich
aus, sobald Templates Inhalte zusammenführen.

## Reservierte Namens-Präfixe

Folgende Präfixe sind für einen Feldtyp reserviert. Die Verwendung kann
helfen, Typenkonflikte zu vermeiden, erschwert aber das nachträgliche Ändern
eines Feldtyps (Feld muss dann erst umbenannt werden):

| Präfix | Typ |
| --- | --- |
| bit_ | bit |
| chr_ | chars |
| chr_ | currency | chr_ |
| chr_ | datetime | chr_ |
| dec_ | decimal |
| enc_ | encrypted |
| fil_ | file |
| flt_ | float |
| ftx_ | fulltext |
| rid_ | id |
| ids_ | ids |
| int_ | integer |
| jsn_ | json |
| pwd_ | password |
| rel_ | pointer, relation, link |
| txt_ | text |
| tim_ | time |
| tsl_ | timestampl |

## Verknüpfen statt mehrfach pflegen

Was an mehreren Stellen vorkommt, wird einmal als eigener Datensatz angelegt und
**verknüpft**: der Autor mit seinem Foto, der Ort mit seiner Adresse.
Eine zentrale Änderung genügt – alle Seiten, mit denen der Datensatz verknüpft ist,
werden aktualisiert.

camindo CMS kennt dafür mehrere Arten von Verknüpfungen:

| Art | Beispiel |
|---|---|
| ***Verknüpfung, beidseitig (n:m)*** | Artikel ↔ Schlagwörter: der Artikel kennt seine Schlagwörter, das Schlagwort seine Artikel |
| ***Verknüpfung, beidseitig (n:1)*** | Artikel → Hauptrubrik: genau eine Rubrik je Artikel |
| ***Verweis, einseitig (n:m bzw. n:1)*** | „Verwandte Artikel“, auch aus mehreren Bereichen |
| ***Fest verknüpfte Inhalte (1:n)*** | Text-Bild-Blöcke einer Seite: gehören exklusiv zu ihrem Datensatz und werden mit ihm kopiert, verschoben und gelöscht |
| ***Eingebettete Inhalte*** | wie fest verknüpfte Inhalte, aber direkt in der Eingabemaske des Datensatzes bearbeitet – ein flexibler Seitenbaukasten |

Templates können Datensätze über ihre Verknüpfungen abfragen und nach Feldern
verknüpfter Datensätze sortieren – etwa Artikel nach dem Namen ihres Autors.

## Hierarchien

Bekommt ein Inhaltsbereich das Metafeld „Eltern-Datensatz“, werden seine Datensätze
zu einem ***Baum***: Seiten mit Unterseiten, Kategorien mit Unterkategorien. Templates
können ganze Teilbäume oder einzelne Ebenen abfragen – die Grundlage für Navigationen
und Brotkrumenpfade. Unabhängig davon ordnen ***Ordner*** die Inhaltsbereiche selbst im
Backend übersichtlich an.

## Mehrere Websites und Sprachen

Eine Installation kann beliebig viele Websites betreiben, jede als eigene ***Instanz***
mit Domains, Sprachen und Startseite. Jeder Inhaltsbereich gehört einer Instanz.

Instanzen können Lesezugriff auf andere Instanzen erhalten und dadurch gemeinsame Inhalte
auf unterschiedlichen Websites nutzen, während andere Inhalte streng an eine Domain
gebunden bleiben – hilfreich etwa für Projekte mit mehreren Subdomains
oder Filialen eines Unternehmens.

Jede Instanz hat eine Hauptsprache und beliebig viele weitere Sprachen. Mehrsprachig
wird nur, was übersetzt werden muss: Sie entscheiden über Mehrsprachigkeit _je Feld_.
Fehlt eine Übersetzung, springt die Hauptsprache ein – Feld für Feld. Die Website ist so in
jeder Sprache vollständig, während die Übersetzung noch läuft.

## Templates: gekapselte Funktionalität

***Templates*** geben die Inhalte aus. Sie bestehen aus der Gestaltung (HTML, CSS,
JavaScript) und aus ***CMS-Befehlen***, die Inhalte einsetzen und Aufgaben übernehmen:
Datensätze abfragen und filtern, Blätterfunktionen generieren, Bildversionen erstellen,
Formulare auswerten und Datensätze daraus speichern, Besucher registrieren und anmelden,
Warenkörbe verwalten oder PDF-Dokumente erzeugen.  
Beim Speichern übersetzt camindo CMS jedes Template in PHP. Zur Laufzeit gibt es keinen Interpretationsaufwand. CMS-Befehle und PHP lassen sich optimal kombinieren.

Ein Template kann andere Templates einbinden. So entstehen in sich geschlossene
Bausteine mit einer klaren Aufgabe: eine Artikelliste mit Blätterfunktion, ein
Formular, das Einsendungen als Datensätze speichert, ein Mitgliederbereich mit
Registrierung, Anmeldung und Passwort-Reset.

### Template-Sätze

Ein ***Template-Satz*** ist ein solcher Baustein im Großen: die Templates einer
Funktion *zusammen mit den Inhaltsbereichen*, die sie brauchen – etwa ein News-Modul
mit den Bereichen „Meldungen“ und „Rubriken“ und den Templates für Übersicht,
Detailseite, Archiv und RSS-Feed.

Ein Template-Satz wird importiert und ist **direkt betriebsbereit**.
Individuell anpassen können Sie alles.

Template-Sätze werden über den XML-Export generiert. Die Option „Templates für
Import in Fremddatenbank optimieren“ sorgt dafür, dass Verknüpfungen zwischen
Templates, Bereichen, Datensätzen beim Import in eine andere Installation angepasst
werden (siehe [Kapitel 9](./09-struktur-export-import.md#template-sätze-weitergeben)).

## Strukturen kopieren und weitergeben

Weil Inhaltsbereiche Einstellungen sind und keine Programmierung, lassen sie sich
kopieren und übertragen:

- ***Bereich kopieren*** – eine Kopie eines Inhaltsbereichs mit allen Eingabefeldern
  und Template-Zuweisungen, aber ohne Datensätze.
- ***Baupläne*** – vorbereitete Vorlagen für neue Bereiche (etwa „Artikel“,
  „Mitglieder“, „Kommentare“) legen Bereich und Felder in einem Schritt an.
- ***XML-Export und -Import*** – ganze Strukturbäume mit Ordnern, Inhaltsbereichen,
  Eingabefeldern, Templates und auf Wunsch allen Datensätzen und Dateien. Damit
  übertragen Sie eine Entwicklung von der Testumgebung auf die Live-Website,
  vervielfältigen eine bewährte Website-Struktur für eine neue Instanz, geben
  Template-Sätze weiter oder sichern einzelne Bereiche.

Details in [Kapitel 9](./09-struktur-export-import.md).

## Sicherheit im Redaktionsalltag

- ***Arbeitskopien:*** Bearbeitet wird immer eine Kopie. Die Website sieht Änderungen
  erst nach dem Speichern, vollständig und auf einmal.
- ***Bearbeitungssperren*** verhindern, dass zwei Personen denselben Datensatz
  gleichzeitig ändern.
- ***Papierkorb:*** Gelöschtes lässt sich (i. d. R. bis 7 Tage nach Löschung)
  wiederherstellen. Später lassen sich Änderungen über das Audit-Log rekonstruieren.
- ***Rechte:*** Benutzer sehen nur die Module, Websites, Bereiche und Sprachen,
  die für ihre Benutzergruppe freigegeben sind. Schreib- und Löschrechte lassen sich
  bis hin auf einzelne Datensätze steuern.
- ***Verschlüsselte Felder:*** Passwortfelder lassen sich von niemandem einsehen,
  verschlüsselte Inhalte können nur im Backend oder, sofern freigegeben,
  in geschützten Website-Bereichen eingesehen werden.
- ***Anmeldung:*** Zwei-Faktor-Anmeldung und Verhinderung des Durchprobierens von Passwörtern
  schützen sowohl im Backend als auch auf der Website.

---

## Leitfaden: die ersten Schritte

Nach der [Installation](./01-quickstart-installation.md) richten Sie eine Website in
dieser Reihenfolge ein:

1. ***Sprachen*** prüfen und bei Bedarf anlegen – Modul „[Sprachen](./03-instanzen-und-sprachen.md#sprachen)“.
2. ***Instanz*** anlegen: Name, Haupt-Domain, Hauptsprache und weitere Sprachen – Modul
   „[Instanzen](./03-instanzen-und-sprachen.md#instanzen)“.
3. ***Inhaltsbereiche*** anlegen, am besten in Ordnern gruppiert, und ihre
   ***Eingabefelder*** definieren – Modul „[Struktur](./04-struktur.md)“.
4. ***Template-Bereich*** anlegen und die ***Templates*** schreiben oder einen
   Template-Satz importieren; den Inhaltsbereichen ihr Standard-Template zuweisen –
   siehe [Kapitel 6](./06-templates.md).
5. In der Instanz die ***Startseite*** festlegen (Start-Datensatz und Start-Template).
6. Für Bildversionen und Vorschaubilder ***Medienkonverter*** einrichten und den
   Upload-Feldern Thumbnail-Felder hinzufügen – [Kapitel 8](./08-medien.md).
7. ***Benutzergruppen*** und ***Benutzer*** für die Redaktion anlegen –
   [Kapitel 7](./07-benutzer-und-rechte.md).
8. [Betrieb](./12-betrieb.md) absichern: Cronjobs, Datenbanksicherung, E-Mail-Versand.
