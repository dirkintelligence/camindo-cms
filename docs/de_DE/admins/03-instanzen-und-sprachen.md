# 3 Instanzen und Sprachen

## Instanzen

Eine ***Instanz*** ist eine Website: ein Frontend mit eigenen Domains, Sprachen und
Inhalten. Eine camindo-Installation kann beliebig viele Instanzen betreiben – etwa
verschiedene Filialen einer Hauptstelle, mehrere Websites eines Konzerns.
Inhalte und Templates gehören zu einer Instanz und sind dadurch an ihre Domains gebunden.
Darüber hinaus können ***gemeinsame Bereiche*** definiert werden, mit Inhalten, die für
mehrere Instanzen zugänglich sind und unter allen ihren Domains erscheinen dürfen.

## Keine Mandantenfähigkeit

Die Abgrenzung von Inhalten hat Grenzen: camindo CMS bietet keine echte Mandantenfähigkeit.
Eine sichere Trennung von Mandanten (etwa konkurrierende Firmen-Websites) wird nur mittels
separater camindo-Installationen erreicht, mit eigener Datenbank und eigenem `public/`- und `var/`-Verzeichnis.

### Die Standard-Instanz

Nach der Installation gibt es eine ***Standard-Instanz*** im Modul „Instanzen“. Sie fängt
alle Aufrufe auf, deren Domain keiner anderen Instanz zugeordnet ist. Sie lässt sich ändern,
aber nicht löschen. Wenn Sie nur eine Website mit camindo CMS verwalten, können Sie diese
Instanz dafür verwenden. Wenn Sie mehrere Websites verwalten, legen Sie für jede Website
eine eigene Instanz an.

Weil sie alle übrigen Domains auffängt, steht die Standard-Instanz in allen Listen und
Auswahlen des Backends ***immer an letzter Stelle*** – unabhängig von ihrer Position.

### Einstellungen einer Instanz

| Einstellung | Bedeutung |
|---|---|
| Label | Name der Instanz im Backend |
| Position nach... | Die Reihenfolge im Backend wird geändert durch die Auswahl der vorangehenden Instanz. Die Standard-Instanz steht immer am Ende und ist als Vorgänger nicht wählbar |
| ***Frontend-Sprachen*** | |
| Haupt-Sprache | Sprache, die bei fehlender Übersetzung einspringt |
| Weitere Sprachen | zusätzliche Sprachversionen; die Reihenfolge wird im Backend beibehalten und kann auch im Frontend verwendet werden |
| ***Frontend-Domains*** | |
| Haupt-Domain | die eigentliche Adresse der Website, ohne Protokoll, z. B. `www.example.com` |
| Frontend-Protokoll | `https` oder `http` |
| Kanonische Aufrufe | leitet jeden Aufruf, der nicht über die Haupt-Domain und die gültige Adresse eines Datensatzes kommt, dauerhaft (301) dorthin um. Für Suchmaschinen empfohlen |
| Weitere Domains | Aufrufe über diese Domains landen ebenfalls in dieser Instanz, z. B. `example.com` ohne `www`. Mit dem Schalter „RegEx“ gilt ein Eintrag als regulärer Ausdruck, etwa um alle Subdomains abzufangen: `*\.camindo\.de` |
| ***Zugriff*** | |
| Lesbare andere Instanzen | Inhalte der ausgewählten Instanzen sind im Frontend dieser Instanz abrufbar – etwa ein gemeinsamer Produktkatalog für mehrere Websites |
| ***Startseite*** | |
| Start-Datensatz | Datensatz, dessen Inhalte auf der Startseite zur Verfügung stehen |
| Start-Template | Template, mit dem die Startseite ausgegeben wird |
| ***Script-Policy (CSP)*** | |
| Script-Policy | „Vorgabe“ (Einstellung `security.frontend_csp`, sonst aus), „Aus“, „Nur melden“ oder „Durchsetzen“. Details siehe unten |
| Weitere Script-Quellen | Adressen fremder Scripts, die laufen dürfen, je Zeile eine, z. B. `https://www.googletagmanager.com` oder `*.example.com` |
| Nachgeladene Scripts erlauben | Scripts, die ein erlaubtes Script nachlädt (Tag-Manager, Widgets), laufen ebenfalls |
| Formularziele beschränken / Weitere Formularziele | Formulare dürfen nur an die eigene Website und die genannten Adressen gehen (z. B. den Zahlungsanbieter) |

Die Startseite lässt sich erst nach dem ersten Speichern wählen – vorher hat die
Instanz noch keine Inhalte.

Medien-Dateien einer Instanz werden nur über ihre eigenen Domains und die Domains der
Instanzen ausgeliefert, die sie lesen dürfen. So lassen sich Bilder einer Website
nicht über die Adresse einer anderen abrufen.

### Script-Policy

Die ***Script-Policy*** (Content Security Policy) ist eine Schranke im Browser: Es laufen
nur die Scripts von der eigenen Domain und aus den eingetragenen externen Quellen.
Gelangt über einen Inhalt – einen Kommentar, einen importierten Text – Script-Code in
eine Seite, führt der Browser ihn nicht aus.

Gehen Sie in zwei Schritten vor:

1. „Nur melden“: zum Testen – alle Scripts werden ausgeführt, aber der Browser meldet,
   was er blockieren würde. Die Meldungen stehen im Modul „Logdateien“ unter `csp`.
   Rufen Sie die wichtigsten Seiten auf (Startseite, Produkt, Warenkorb, Kasse, Kundenkonto)
   und prüfen Sie das Protokoll.
2. „Durchsetzen“: für den produktiven Betrieb, wenn alle Meldungen beseitigt sind.
   Meldet das Protokoll fremde Scripts, die Sie brauchen (Zahlungsanbieter, Karten, Statistik), 
   tragen Sie deren Adresse als Script-Quelle ein. Jedes Script im Template braucht die Nonce
   der Seite (`<script(CMS:NONCE)>`), Scripts direkt im HTML (`onclick="…"`) baut der
   Template-Programmierer um. Der Template-Editor und das Widget „Template-Meldungen“
   auf der Startseite weisen auf Scripts ohne Nonce hin.

Neue Instanzen stehen auf „Vorgabe“: Sie übernehmen die Einstellung
`security.frontend_csp` der Konfiguration, ohne diese gilt „aus“. Die Konfiguration ist
nur diese Vorgabe – eine Instanz, bei der Sie „Aus“, „Nur melden“ oder „Durchsetzen“
gewählt haben, behält ihren Modus. Für eine Staging-Umgebung bietet sich
`security.frontend_csp = 'report'` an, wenn die Instanzen dort auf „Vorgabe“ stehen.

### Adressen im Frontend

Die Adresse eines Datensatzes besteht aus der Domain, bei mehrsprachigen Websites dem
Sprachkürzel und dem ***Slug*** oder der Nummer des Datensatzes (record_id):

```
https://www.example.com/betriebsfeier-2026
https://www.example.com/en/summer-party-2026
https://www.example.com/1758
```

Fehlt das Sprachkürzel, wählt camindo CMS die Sprache nach der Spracheinstellung des
Browsers (Accept-Language). Ist diese nicht als Frontend-Sprache eingerichtet, wird
die Hauptsprache der Instanz angezeigt.
Die Adresse aus dem Sprachkürzel (ohne Slug oder record_id) führt auf die Startseite
(beispielsweise `https://www.example.com/en`). Gibt es unter einer Adresse keinen
Datensatz, antwortet die Website mit dem Status 404. Datensätze haben nur einen Slug,
wenn das Feld `slug` im Inhaltsbereich eingerichtet ist; siehe
[Kapitel 5](./05-eingabefelder.md#besondere-feldnamen).
Jeder Inhaltsbereich kann einen zusätzlichen ***Slug-Prefix*** bereitstellen. Dieser
steht dann vor dem Slug seiner Datensätze (`/en/events/summer-party-2026`) und kann je
Sprache unterschiedlich lauten. Mehr dazu in [Kapitel 4](./04-struktur.md#slug-prefix).

### Instanz kopieren und löschen

„Kopieren“ öffnet eine Eingabemaske, in der die Angaben der zu kopierenden Instanz
bereits eingetragen sind – ohne Domains, Startseite und Inhalte.
Inhaltsbereiche, Templates und Datensätze werden nicht mitkopiert.
Dies können Sie per [XML-Export und -Import](./09-struktur-export-import.md) auf Wunsch
nachholen.

> **Achtung:** Beim Löschen einer Instanz werden **alle** ihre Inhaltsbereiche,
> Eingabefelder, Datensätze und Templates endgültig mitgelöscht. Instanzen haben
> keinen Papierkorb. Die Rückfrage nennt, wie viele Elemente betroffen sind.

## Sprachen

Im Modul „Sprachen“ legen Sie die Sprachen an, die den Instanzen zur Verfügung
stehen. Nach der Installation sind diese Sprachen bereits angelegt:

* ‫العربية‬ (ar_SA)
* বাংলা (bn_BD)
* 简体中文 (zh_CN)
* Deutsch (de_DE)
* English (en_US)
* Español (es_ES)
* Français (fr_FR)
* हिन्दी (hi_IN)
* 日本語 (ja_JP)
* 한국어 (ko_KR)
* Português (pt_BR)
* ‫اردو‬ (ur_PK)

Weitere Sprachen können nachinstalliert werden – siehe unten.

| Einstellung | Bedeutung |
|---|---|
| Sprach- oder Locale-Code | Kürzel der Sprachversion, erscheint in der Adresse, z. B. `de`, `de_DE` oder `de_AT`. Eine Variante wie `de_AT` passt auch zu Browsern, die nur `de` anfordern |
| Backend-Sprache | Sprache der Snippets (Textbausteine) dieser Sprachversion, z. B. `de_DE` – sie gilt auch für Meldungen der CMS-Befehle im Frontend. `de_DE` und `de_AT` als Frontend-Sprachen teilen sich so dieselben Textbausteine (siehe [Backend-Snippets](./10-module.md#backend-snippets)) |
| Label / Label (Landessprache) | Name der Sprache, einmal in der Sprache des Backends („German“), einmal in der eigenen Sprache („Deutsch“) – für Sprachumschalter |
| Zeitzone | Zeitzone, in der Datums- und Zeitangaben dieser Sprachversion auf der Website erscheinen. Leer: die Zeitzone der Konfiguration |
| Position nach... | Reihenfolge |

### Sprachen einer Instanz ändern

- ***Neue Sprache hinzufügen:*** Mehrsprachige Inhalte der Instanz erhalten die neue
  Sprachversion. Bis zur Übersetzung springt die Hauptsprache ein.
- ***Sprache aus einer Instanz entfernen:*** Alle Inhalte der Instanz in dieser Sprache
  werden endgültig gelöscht: Texte, Zahlen, Datumsangaben, Volltexte,
  Datenschutz-Vorlagen und die Dateien mehrsprachiger Felder. Verknüpfungen und die
  Inhalte der übrigen Sprachen bleiben erhalten. Beim Speichern erscheint die Maske zunächst noch
  einmal mit einem ***Warnhinweis***, der die wegfallenden Sprachen nennt – erst ein
  zweiter Klick auf „Speichern“ entfernt sie. Wechseln Sie gleichzeitig die
  Haupt-Sprache, zeigen nicht übersetzte Dateien danach die Datei der neuen
  Haupt-Sprache.
- ***Sprache löschen:*** Die Inhalte der Sprache werden in ***allen*** Instanzen
  gelöscht, wie beim Entfernen aus einer Instanz. Vor dem Löschen nennt camindo CMS die
  Instanzen, die die Sprache verwenden. Ist sie irgendwo ***Haupt-Sprache***, lässt sie
  sich nicht löschen – wählen Sie dort zuerst eine andere Haupt-Sprache.

Wie Felder mehrsprachig werden, beschreibt [Kapitel 5](./05-eingabefelder.md#mehrsprachige-felder).
