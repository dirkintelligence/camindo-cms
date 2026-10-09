# 2 Template-Sätze

## Gekapselte Funktionalität

Ein ***Template-Satz*** bündelt eine Funktion der Website: Templates, Inhaltsbereiche,
ggf. Datensätze und Snippets.
Beispiele:

| Template-Satz | Inhaltsbereiche | Templates |
|---|---|---|
| News | Meldungen, Rubriken | Übersicht mit Blätterfunktion, Detailseite, Rubrikenseite, Archiv, Teaser für die Startseite, RSS-Feed |
| Veranstaltungen | Termine, Orte | Kalender, Detailseite mit Anfahrt, Terminvorschau |
| Mitgliederbereich | Mitglieder (Profil), Beiträge (UGC) | Registrierung, Bestätigung, Anmeldung, Passwort vergessen, Profil bearbeiten, geschützte Seiten |
| Seitenbaukasten | Seiten, Textblock, Bildblock, Galerie (abhängige Bereiche) | Seite, je Blocktyp ein Baustein |

Ein guter Template-Satz ist **direkt betriebsbereit**: Importieren, Inhalte eingeben,
fertig. Und er ist **anpassbar** an den individuellen Aufbau einer Website, ohne dass die
Funktion neu entwickelt werden muss. Weitergegeben wird er per XML-Export mit der
Option „Templates für Import in Fremddatenbank optimieren“ (siehe
[Handbuch für Administratoren](../admins/09-struktur-export-import.md#template-sätze-weitergeben)).

## Regeln für portable Template-Sätze

### Ein Ordner, ein Template-Bereich

Legen Sie die Inhaltsbereiche und den Template-Bereich eines Satzes gemeinsam in einen
Ordner. So exportiert ein Klick den ganzen Satz, und beim Import ist klar, was
zusammengehört.

### Namen und Ids direkt im Befehlsparameter

Templates, Inhaltsbereiche, Datensätze werden in den Templates mittels ihrer ID oder ihres
Namens (bzw. Slugs) angesprochen. In einer fremden Datenbank sind diese unter Umständen
schon belegt; dann vergibt der Importer neue und passt die Templates daran an.
Die Anpassung gelingt aber nur, wenn IDs, Namen, Slugs direkt in den Befehlsparametern stehen.
Steht im Parameter eine PHP-Variable, die erst zur Laufzeit ihren Wert enthält, schlägt die Anpassung fehl.

### Einheitliche Feldnamen

Für Ihre eigenen Template-Sätze: Verwenden Sie dieselben Feldnamen wie im Rest der Website
(`titel`, `datum`, `text`, `bild`, `slug`). Dann funktionieren bereichsübergreifende Listen – etwa eine
Zeitleiste aus News und Terminen – ohne Anpassung, und ein Template-Satz lässt sich
leicht mit bestehenden Bereichen kombinieren.

Für Template-Sätze in anderen Datenbanken: Nutzen Sie die Namens-Präfixe, damit es beim Import
nicht zu Typkonflikten kommt (siehe [Reservierte Namens-Präfixe](../admins/05-eingabefelder.md#reservierte-namens-präfixe)).

### Logik und Gestaltung trennen

Halten Sie die Logik (Abfragen, Filter, Blätterfunktion, Formularauswertung) in
wenigen Templates zusammen und die Gestaltung in eigenen, kleinen Bausteinen:

```cms
news_uebersicht     (CMS:SELECT news_liste filter[area]=meldungen … count=true)
news_liste          (CMS:RESULT_START) (CMS:INCLUDE news_teaser) (CMS:RESULT_END)
                    (CMS:JUMP_LIST …)
news_teaser         <article class="teaser"> … (CMS:TEXT titel) … </article>
```

Wer den Satz an ein anderes Design anpasst, ändert nur `news_teaser` und die übrigen
Gestaltungsbausteine; die Funktion bleibt unberührt.

### Das Layout der Website einbinden

Verwenden Sie feste Kopf- und Fuß-Templates; diese können einfach durch vorhandene ersetzt werden.

### Texte in einem eigenen Inhaltsbereich

Feste Beschriftungen („Weiterlesen“, „Zurück zur Übersicht“, Fehlermeldungen von
Formularen) gehören in einen eigenen Inhaltsbereich (etwa „Beschriftungen“).
Eingebunden werden sie mittels CMS:TEXT, z. B. `(CMS:TEXT id=beschriftungen.weiterlesen)`.
So können sie einfach für die spätere Website angepasst / übersetzt werden.

### Label als Custom-Snippets

Um auch Bereichsnamen und Eingabefelder mehrsprachig zu halten, können Custom-Snippets angelegt
werden (siehe [Modul Snippets](../admins/10-module.md#modul-snippets)).
Die im Template-Satz verwendeten Snippets werden automatisch in die Export-Datei integriert.

### Einstellungen als Datensatz

Soll die Redaktion das Verhalten eines Satzes steuern – Anzahl der Einträge je Seite,
Titel der Übersichtsseite, ein Einleitungstext –, legen Sie dafür einen kleinen
Inhaltsbereich „Einstellungen“ mit einem einzigen Datensatz an und lesen ihn über
seinen Slug:

```cms
(CMS:TEXT einleitung id=news-einstellungen)
(CMS:NUMBER eintraege_je_seite id=news-einstellungen set=limit)
(CMS:SELECT news_liste filter[area]=meldungen limit="{$set['limit']}" count=true)
```

### Beispielinhalte mitliefern

Ein paar Beispiel-Datensätze zeigen nach dem Import sofort, wie der Satz aussieht und
funktioniert. Exportieren Sie sie mit, samt Bildern.

### Dokumentieren

Legen Sie dem Satz eine kurze Beschreibung bei – als Hinweis des Ordners oder als
Datensatz: welche Templates als Standard-Template zuzuweisen sind, welche
Layout-Templates erwartet werden, welche Snippets und Konverter der Satz nutzt.

### CSS als Template

Binden Sie das benötigte CSS als Template ein – so erscheint der Template-Satz sofort
im gewollten Design. Der Nutzer kann das CSS jederzeit in eine Datei auslagern.
Beachten Sie jedoch, dass dieses Template gesonderte Header-Anweisungen benötigt, siehe [Templates, die CSS, Javascript oder JSON ausliefern](./01-grundlagen.md#templates-die-css-javascript-oder-json-ausliefern).

## Einen Template-Satz einsetzen

1. Importieren (Modul Inhalte → Startseite → Menü → Import), vorzugsweise zuerst in einer Testumgebung.
2. Mit der Vorschau testen – insbesondere Verweise, die das Importprotokoll
   als „nicht zugeordnet“ meldet.
3. Header und Footer anpassen oder durch eigene ersetzen.
4. Falls es sich beim Template-Satz um eine ganze Website handelt:
   Startseiten-Id oder Startseiten-Template in der Instanz eintragen.
5. Rechtliche Aspekte klären, ggf. Texte und Lösch-Policies anpassen.

