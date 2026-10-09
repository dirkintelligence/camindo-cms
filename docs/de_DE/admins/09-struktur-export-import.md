# 9 Strukturen exportieren und importieren

Mit dem ***XML-Export*** schreiben Sie ganze Strukturbäume in eine Datei: Ordner,
Inhaltsbereiche mit allen Eingabefeldern und Einstellungen, Template-Bereiche mit
ihren Templates und auf Wunsch alle Datensätze samt Dateien. Der ***XML-Import*** baut
diese Struktur in derselben oder einer anderen camindo-Installation wieder auf.

Typische Anwendungen:

| Aufgabe | Vorgehen |
|---|---|
| ***Website-Struktur für eine neue Instanz vervielfältigen*** | Struktur und Templates exportieren, in eine neue Instanz importieren, Bereiche umbenennen |
| ***Entwicklung auf die Live-Website übertragen*** | Auf der Testumgebung entwickelte Bereiche, Felder und Templates exportieren und auf dem Live-System importieren – vorhandene Bereiche werden aktualisiert |
| ***Template-Sätze weitergeben*** | eine Funktion mit ihren Inhaltsbereichen und Templates als Paket bereitstellen (siehe [unten](#template-sätze-weitergeben)) |
| ***Bereiche sichern*** | Struktur und Inhalte vor einem größeren Umbau sichern |

Export und Import ganzer Strukturen sind Superusern vorbehalten. Den Export und
Import der Datensätze *eines* Bereichs beschreibt das
[Handbuch für Anwender](../editors/08-export-import.md).

## Exportieren

Öffnen Sie das Modul ![draft](../_/draft.svg) „Inhalte“ und wählen Sie auf der Startseite im Menü
![more_vert](../_/more_vert.svg) „Export“. Die Maske zeigt den Baum aller Ordner und
Bereiche aller Typen mit Checkboxen, gruppiert nach ***Instanzen***: Jede Instanz
erscheint wie ein Ordner mit ihrem Namen, darunter ihre Ordner und Bereiche. Ein Ordner
oder eine Instanz wählt die enthaltenen Elemente mit an; einzelne lassen sich wieder
abwählen.

| Option | Wirkung |
|---|---|
| Struktur exportieren | Ordner, Inhaltsbereiche und Eingabefelder mit allen Einstellungen (auch dem Slug-Prefix und der Lösch-Policy), Template-Zuweisungen (auch die Trigger-Templates in ihrer Reihenfolge), den Konvertern der Thumbnail-Felder und den Snippets der Instanz, auf die Beschriftungen verweisen (`#custom.…`) |
| Inhalte exportieren | die Datensätze der angehakten Inhaltsbereiche (alle Felder, alle Sprachen) und die Templates der angehakten Template-Bereiche – samt den Snippets der Instanz, die diese Templates verwenden (`custom.…`) |
| Upload-Dateien mit exportieren | die Dateien der Datensätze kommen mit in die Zip-Datei |
| Templates für Import in Fremddatenbank optimieren | markiert Verweise in den Templates, damit sie beim Import in eine andere Installation angepasst werden (siehe [unten](#template-sätze-weitergeben)) |
| Als Hintergrundprozess ausführen | der Download-Link kommt als interne Nachricht |

Das Ergebnis ist eine Zip-Datei mit der XML-Datei und ggf. den Dateien. Der
Download-Link gilt einmal; nicht abgeholte Dateien werden nach 24 Stunden gelöscht.

Die Datei enthält verschlüsselte Inhalte verschlüsselt und Passwörter nur als
Prüfwert. Beides hängt an der Schlüsseldatei `.camindo.key`: In einer Installation mit
anderem Schlüssel sind verschlüsselte Inhalte nicht lesbar, und importierte
Passwörter (etwa von Mitgliedern) passen nicht mehr – die Mitglieder müssen dann ein
neues Passwort anfordern.

## Importieren

Im Modul „Inhalte“ wählen Sie auf der Startseite im Menü „Import“, laden die Zip- oder
XML-Datei hoch (oder wählen sie aus dem Upload-Verzeichnis). Die Maske zeigt dann:

| Einstellung | Bedeutung |
|---|---|
| Instanz | Instanz für neu angelegte Bereiche. Aus derselben Installation kann auch die Instanz aus der Datei gelten |
| Zielebene für neue Ordner und Bereiche | Ordner, unter dem neu angelegte Bereiche landen. Bereiche, deren Ordner ebenfalls in der Datei ist, landen unter diesem. Angeboten werden nur die Ordner der gewählten Instanz (bei „aus der Importdatei“ alle) |
| Konverter der Thumbnail-Felder | je Konverter der Datei: einen vorhandenen verwenden, neu anlegen oder ignorieren (siehe unten) |
| je Bereich der Datei | Name (änderbar), Modus und Zuordnungskriterium – getrennt für Struktur, Datensätze und Templates |

Die Bereiche der Datei stehen eingerückt nach ihrer Hierarchie. Eine Auswahl in der
Spaltenüberschrift setzt den Modus für eine ganze Spalte.

Verweisen Beschriftungen von Bereichen und Feldern auf Snippets der Instanz (Modul
„Snippets“, `#custom.31.title`), übernimmt der Import diese Snippets in das Set der
***Zielinstanz*** und schreibt die Verweise darauf um (`#custom.32.title`). Vorhandene
Snippets der Zielinstanz bleiben unverändert; das Protokoll nennt die übernommenen und
die beibehaltenen Schlüssel.

Dasselbe gilt für die Snippets, die importierte ***Templates*** ausgeben – etwa
`(CMS:SNIPPET custom.weiterlesen)`. Sie landen im Set der Instanz des Template-Bereichs,
die Templates selbst bleiben unverändert (die Kurzform `custom.…` meint immer das Set der
Instanz, in der die Seite läuft). Zusammengesetzte Schlüssel wie
`"custom.status_{$cms['status']}"` bringen alle Snippets mit diesem Anfang mit.

Das Slug-Prefix eines Bereichs wird über die Sprachkürzel den Sprachen der Zielinstanz
zugeordnet. Ist es dort nicht zulässig – etwa weil ein anderer Bereich dasselbe
Präfix führt –, entfällt es mit einem Hinweis im Protokoll.

### Modus und Zuordnung

Für jeden Bereich wählen Sie getrennt für „Struktur“, „Datensätze“ und
„Templates“ einen Modus – INSERT, UPDATE, UPDATE_OR_INSERT, REPLACE,
REPLACE_OR_INSERT oder IGNORE (Bedeutung siehe [Handbuch für Anwender](../editors/08-export-import.md#modus))
– und woran ein vorhandenes Element erkannt wird:

| Element | Zuordnung über |
|---|---|
| Bereich (Struktur) | Name oder Bereichsnummer |
| Datensätze | Slug oder Datensatznummer |
| Templates | Name oder Templatenummer |

Stammt die Datei aus ***derselben Installation***, ist UPDATE_OR_INSERT vorgewählt:
Vorhandenes wird aktualisiert, Fehlendes angelegt. Stammt sie aus einer **anderen
Installation**, ist INSERT vorgewählt, und Nummern zählen nie als Kriterium – dort
gehören sie zu ganz anderen Elementen. Zugeordnet wird dann nur über Namen und Slugs,
neue Datensätze erhalten neue Nummern. Ein Bereich, den es im Ziel noch nicht gibt,
kennt nur INSERT und IGNORE.

Bei REPLACE werden Eingabefelder, die die Datei nicht nennt, aus dem Bereich
entfernt.

### Was beim Import geschieht

1. Bereiche werden in der Reihenfolge der Datei angelegt bzw. aktualisiert, Eltern
   vor Kindern.
2. Die ***Konverter*** der Thumbnail-Felder werden zugeordnet: aus derselben
   Installation über ihre Nummer, sonst über ihre Einstellungen. Gibt es keinen
   passenden, wird er neu angelegt – sofern sein Prozessor installiert ist. Ein
   Thumbnail-Feld ohne Konverter wird übergangen.
3. Eingabefelder, dann Templates (sie werden dabei neu übersetzt), dann die
   Einstellungen der Bereiche: Standard- und Sortierfeld, Standard-Template,
   Template-Zuweisungen, Trigger-Templates, Anmeldefelder.
   Die ***Lösch-Policy*** übernimmt der Import nur aus derselben Installation.
   Stammt die Datei aus einer anderen, erhält ein neu angelegter Bereich die
   Standard-Policy seines Typs („Datensatz löschen“, „Profil löschen“ bzw.
   „User-Generated-Content löschen“), und ein vorhandener behält seine.
4. Die Datensätze in zwei Durchläufen: erst die Inhalte, dann die Verknüpfungen – mit
   den tatsächlichen Nummern der Zieldatenbank. Verknüpfungen zwischen importierten
   Datensätzen bleiben so erhalten, auch wenn sich alle Nummern ändern.
5. Thumbnails und Volltexte werden neu erzeugt.

Wie beim Import eines einzelnen Bereichs zeigt eine ***Analyse*** vorher, was geschehen
wird, und ein ***Protokoll*** danach, was geschehen ist – je Bereich, Feld, Template,
Konverter und auf Wunsch je Datensatz.

Das Erstellungsdatum der Datensätze bleibt erhalten. Den Ersteller übernimmt der
Import nur aus derselben Installation; sonst gilt der importierende Benutzer.

## Eine Struktur vervielfältigen

Beispiel: Sie betreiben die Website eines Vereins und wollen dieselbe Struktur für
einen zweiten Verein nutzen.

1. Legen Sie die neue Instanz an (Modul „[Instanzen](./03-instanzen-und-sprachen.md)“).
2. Exportieren Sie den Ordner der ersten Website mit „Struktur exportieren“ und
   „Inhalte exportieren“ – so kommen die Templates mit. Datensätze, die nicht
   übernommen werden sollen, schließen Sie aus, indem Sie deren Bereiche beim Import
   für die Datensätze auf IGNORE stellen.
3. Importieren Sie die Datei: Instanz = die neue Instanz, Modus INSERT.
   Bereichsnamen müssen nur innerhalb einer Instanz und der Instanzen eindeutig sein,
   die sie lesen darf bzw. von denen sie gelesen wird. Sind die beiden Websites
   voneinander unabhängig, behalten die Bereiche ihre Namen, und die Templates
   funktionieren unverändert. Andernfalls vergeben Sie in der Import-Maske neue
   Namen, etwa `verein2_termine` statt `termine`.
4. Tragen Sie in der neuen Instanz Start-Datensatz und Start-Template ein.

## Template-Sätze weitergeben

Ein ***Template-Satz*** besteht aus den Templates einer Funktion und den
Inhaltsbereichen, die sie braucht (siehe [Kapitel 2](./02-konzept.md#template-sätze)).
Templates verweisen in ihren CMS-Befehlen auf andere Templates, auf Bereiche und
manchmal auf einzelne Datensätze:

```cms
(CMS:INCLUDE header)
(CMS:SELECT news_liste filter[area]=meldungen src_id=1758 …)
```

In einer anderen Installation – oder nach dem Umbenennen beim Import – stimmen diese
Namen und Nummern nicht mehr. Die Export-Option „Templates für Import in
Fremddatenbank optimieren“ markiert solche Verweise in der Exportdatei. Der Import
ersetzt sie durch die Namen und Nummern der importierten Elemente:

- Ein Bereich, der beim Import umbenannt wird, heißt auch in den Templates so.
- Ein Verweis auf einen Datensatz zeigt auf den importierten Datensatz; wurde er nicht
  mit importiert, auf den Datensatz mit derselben Adresse (Slug-Prefix und Slug) in
  der Instanz des Templates.
- Was sich nicht zuordnen lässt, behält den Wert aus der Datei; das Protokoll zählt
  diese Fälle.

So stellen Sie einen Template-Satz bereit:

1. Legen Sie die Inhaltsbereiche und den Template-Bereich des Satzes in einem eigenen
   Ordner an. Halten Sie sich an die [Namenskonventionen](./02-konzept.md#ein-feldname-eine-bedeutung-über-bereiche-hinweg-filtern).
2. Legen Sie bei Bedarf einige Beispiel-Datensätze an – sie zeigen nach dem Import
   sofort, wie der Satz funktioniert.
3. Exportieren Sie den Ordner mit Struktur, Inhalten, Dateien und der Option
   „Templates für Import in Fremddatenbank optimieren“.
4. In der Zielinstallation: importieren, Standard-Templates prüfen, die Gestaltung
   (HTML, CSS) an die Website anpassen.

Verweise, die ein Template erst zur Laufzeit berechnet (etwa aus PHP-Variablen), kann
der Import nicht erkennen. Testen Sie einen importierten Template-Satz deshalb immer.
