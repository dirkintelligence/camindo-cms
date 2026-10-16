# 6 Templates verwalten

Wie Templates geschrieben werden, erklärt das [Handbuch für Template-Programmierer](../templateprogrammers/README.md).
Dieses Kapitel beschreibt, wie Templates organisiert und den Inhalten zugeordnet
werden.

## Template-Bereiche

Templates liegen in ***Template-Bereichen*** (Bereichstyp TEMPLATE, angelegt im Modul
„[Struktur](./04-struktur.md)“). Wie Inhaltsbereiche gehören sie zu einer Instanz und
lassen sich in Ordnern gruppieren. Bewährt hat sich ein Template-Bereich je Funktion
oder Template-Satz – etwa „Layout“ für Seitenrahmen, Kopf und Fuß, „News“ für die
Templates des Nachrichtenbereichs.

Im Modul „Templates“ öffnen Sie einen Template-Bereich und bearbeiten dort seine
Templates.

| Einstellung | Bedeutung |
|---|---|
| Name (intern) | Name, unter dem Templates und Adressen das Template ansprechen. Muss nur innerhalb des Template-Bereichs eindeutig sein; gibt es ihn mehrfach, spricht man es als `bereichsname.name` an |
| Label | Beschriftung, die im Datensatz bei der Auswahl eines Templates erscheint (optional) |
| Standalone | Das Template darf ohne Datensatz aufgerufen werden, über die Adresse `/0,name` – etwa für eine Suchseite, eine Sitemap oder ein Kontaktformular |
| Verwendung | „Zeitgesteuert (Zeitplaner)“: Das Template läuft nur als Aufgabe des [Zeitplaners](./10-module.md#zeitplaner) und ist nie über eine Adresse aufrufbar (auch nicht als Standalone-Template). Einstellung im Reiter „Zugriff“ |
| Code | HTML mit CMS-Befehlen und PHP |

Die Detailansicht zeigt den Code farbig hervorgehoben. ***Fehler*** im Code – ein
unbekannter Befehl, ein falscher Parameter, eine fehlende Klammer – verhindern das
Speichern nicht, sondern werden mit Zeilennummer über dem Code angezeigt. Verweise auf
andere Templates sind anklickbar. Existiert das verwiesene Template noch nicht, bietet
camindo CMS an, es gleich anzulegen; ein ***Listentemplate*** (Parameter `resulttemplate`
von `CMS:SELECT` und `CMS:SELECT_LANGVERSION`) erhält dabei schon die Schleife
`(CMS:RESULT_START)` … `(CMS:RESULT_END)` als Code.

***CMS-Befehl einfügen:*** Rechts über dem Code-Feld der Eingabemaske steht eine Auswahl
aller CMS-Befehle (alphabetisch, samt den Befehlen der Zusatzmodule). Wählen Sie einen aus,
öffnet sich eine Maske mit allen Parametern des Befehls: je Parameter ein Eingabefeld für den
Wert, davor bei Bedarf Felder für die ***Schlüssel*** (`filter[preis]`) und – bei `filter` –
eine Auswahl des ***Vergleichsoperators*** (`=`, `==`, `!=`, `<`, `>=` …). Parameter mit
Schlüsseln dürfen mehrfach vorkommen; mit dem kleinen „+“ darunter fügen Sie eine weitere
Zeile hinzu. Das Fragezeichen neben dem Parameternamen zeigt seine Beschreibung,
Pflichtparameter sind mit * markiert. „Einfügen“ setzt den Befehl an der Cursorposition in
den Code ein – Parameter ohne Wert bleiben weg, ab drei Parametern steht jeder in einer
eigenen Zeile. Gespeichert wird erst mit dem Template.

Ein Template lässt sich in einen anderen Template-Bereich **verschieben**; seine
Zuweisungen bleiben erhalten.

## Templates den Inhaltsbereichen zuweisen

Welches Template ein Datensatz im Frontend bekommt, entscheidet camindo CMS in dieser
Reihenfolge:

1. ein ***URL-Override***-Template in der Adresse (`/betriebsfeier,druck`) – nur wenn es für
   den Inhaltsbereich freigegeben ist,
2. das im Datensatz gewählte Template (Metafeld „Gestaltungsvorlage“) – nur aus den
   **wählbaren** Templates,
3. das ***Standard-Template*** des Inhaltsbereichs.

Die Zuweisung pflegen Sie an zwei gleichwertigen Stellen: in den
[Einstellungen des Bereichs](./04-struktur.md#einstellungen-eines-inhaltsbereichs)
oder auf dem Reiter „Zugriff“ des Templates („Contentbereich
hinzufügen“, dann je Bereich „Standard“, „Auswählbar“ und „URL-Override“).

Ein Template, das über die Adresse angefordert wird, ohne dafür freigegeben zu sein,
wird nicht ausgeliefert. So lassen sich keine internen Templates von außen aufrufen.

## Templates im Dateisystem

Jedes Template liegt zusätzlich als Datei auf dem Server:

```
var/templates/{instance_id}/{bereichsname}/{templatename}.cms.php
```

Das ermöglicht die Arbeit mit einem gewohnten Code-Editor, einer Versionsverwaltung
(Git) oder einem Deployment-Werkzeug. camindo CMS gleicht die Dateien minütlich mit
dem Backend ab (und sofort über die Schaltfläche „Aktualisieren“ der Template-Listen):

| Im Dateisystem | Wirkung im Backend |
|---|---|
| neue Datei | neues Template wird erstellt |
| Datei geändert | der Template-Inhalt wird neu eingelesen |
| Datei umbenannt | das alte Template wird gelöscht, ein neues erstellt |
| Datei gelöscht | Template wird gelöscht – samt Zuweisungen |
| neues Verzeichnis | neuer Template-Bereich wird erstellt |

Umgekehrt schreibt das Backend jede Änderung in die Datei zurück.  
![warning](../_/warning.svg) Benennen Sie Templates besser im Backend um: Im Dateisystem ist
Umbenennen „Löschen und neu anlegen“, und die Zuweisungen gehen verloren.

## Übersichts-Templates

Führt ein Inhaltsbereich ein Slug-Prefix (etwa `termine`), hat jeder Datensatz eine
Adresse wie `/de/termine/betriebsfeier`. Die Adresse aus dem Präfix allein – `/de/termine`
– zeigt das ***Übersichts-Template*** des Bereichs: typischerweise die Liste seiner
Datensätze. Es läuft ohne Datensatz und wird in den Einstellungen des Bereichs unter
„Template-Zuweisung“ gewählt. Weil das Präfix je Sprache übersetzt ist, hat auch die
Übersicht je Sprache ihre Adresse (`/en/events`). Ohne Übersichts-Template ergibt die
Adresse „Seite nicht gefunden“.

Statt eines Datensatzes stellt das Übersichts-Template die Angaben des Bereichs bereit –
die Id (`area_id`), den Namen, die Beschriftung, die Instanz und das Slug-Prefix in der
aufgerufenen Sprache. Damit fragt das Template die Datensätze seines Bereichs ab, ohne
den Bereich fest einzutragen (siehe
[Handbuch für Template-Programmierer](../templateprogrammers/01-grundlagen.md#übersichtsseite-eines-bereichs)).

Haben Sie einem Bereich ein Übersichts-Template ***und*** ein Slug-Prefix zugewiesen,
erscheint in den Einstellungen des Bereichs und über seiner Datensatzliste die
Schaltfläche „Vorschau“. Sie zeigt die Übersicht so, wie sie im Frontend erscheint –
bei mehreren Sprachen als Auswahl mit einem Eintrag je Sprache, jeweils unter dem
Slug-Prefix dieser Sprache.

## Listen-Templates für das Backend

Ein Inhaltsbereich kann ein ***Listen-Template*** erhalten. Es wird nicht im Frontend
verwendet, sondern im Backend: Es gestaltet je Datensatz eine zusätzliche Spalte der
Datensatzliste und die ausführliche Vorschau in Lookup-Feldern – etwa ein Farbfeld statt
des eingegebenen RGB-Farbwerts oder einen ganzen Text-Bild-Block im finalen Layout.

## Template-Sätze

Wie Sie Templates zusammen mit ihren Inhaltsbereichen als Template-Satz weitergeben
und in anderen Installationen einsetzen, beschreibt
[Kapitel 9](./09-struktur-export-import.md#template-sätze-weitergeben).
