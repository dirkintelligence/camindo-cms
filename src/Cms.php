<?php
/**
 * Kernklasse des CMS. Instanzname im Code: $cms.
 *
 * Mit rund 3.400 Zeilen die größte Klasse des Projekts. Sie vereint vier Aufgaben, die
 * historisch zusammengewachsen sind:
 *
 *   1. TYPSYSTEM        Welche Feldtypen es gibt, in welcher data_*-Tabelle sie liegen
 *                       und wie sie zu behandeln sind ($dataSuffix, $textTypes,
 *                       $numericTypes, $dateTypes, $arrayTypes, $relationTypes).
 *                       Außerdem das feste Schema der Tabelle `record` ($schema).
 *   2. ABFRAGE          select() / filter() - der zentrale Lesezugriff samt Aufbau der
 *                       JOINs auf die data_*-Tabellen. Derselbe Code bedient
 *                       Backend-Listen, Backend-Suche und den Template-Befehl CMS:SELECT.
 *   3. AUSGABE          fetch() bereitet eine Ergebniszeile auf; die _*-Methoden
 *                       formatieren einzelne Werte (Datum, Zahl, Text).
 *   4. TEMPLATE-BEFEHLE Die cms*-Methoden am Ende der Klasse sind die Laufzeit hinter
 *                       den (CMS:...)-Befehlen der Frontend-Templates.
 *
 * Dazu kommen die mehrsprachigen Textbausteine (snippet()). Die Blätterlisten sind
 * seit C9 in Camindo\Core\Pagination ausgelagert und werden dort nachgeladen.
 *
 * VIRTUELLE FELDER - DER KERNGEDANKE
 * --------------------
 * Ein Datensatz (`record`) hält nur Metadaten. Die eigentlichen Inhalte liegen
 * typgetrennt in data_txt, data_int, data_fil, data_rel, ... - je Feldtyp eine Tabelle.
 * Welches Feld wohin gehört, steht in `field` (je Inhaltsbereich) und in `fieldname`
 * (systemweit, Name -> Id + Typ).
 *
 * select() setzt daraus zur Laufzeit per JOIN wieder eine Zeile zusammen. Für den
 * Aufrufer fühlt es sich an wie eine normale Tabelle:
 *
 *     $cms->select(['filter' => ['titel' => 'Hen*'], 'limit' => 20]);
 *     while ($row = $cms->fetch()) { echo $row['titel']; }
 *
 * Der Preis dafür ist der JOIN-Aufbau in filter() und den register*Join()-Methoden -
 * der aufwendigste Teil der Klasse.
 *
 * META-FELDER VS. VIRTUELLE FELDER
 * --------------------
 *     Meta-Feld        fest in $schema definiert, echte Spalte in `record`
 *                      (record_id, area_id, status_id, online_at, lft/rgt, ...)
 *     Virtuelles Feld  vom Redakteur angelegt, Inhalt in einer data_*-Tabelle
 *
 * Alle Einträge in $schema werden im Konstruktor mit 'meta' => 1 markiert; daran
 * unterscheidet filter() die beiden Welten.
 *
 * Dazu kommt das Pseudo-Feld 'area' (Tabellen mit `area_id`, also record und
 * template): der NAME eines Inhaltsbereichs statt seiner Id, für Templates -
 * filter[area]=artikel bzw. sort=area. select() übersetzt ihn über den Instanz-
 * Dateicache in `area_id` (siehe translateAreaFilter(), areaMap()); eine Zahl gilt
 * dabei als area_id. Die Datenbank sieht davon nichts.
 *
 * WICHTIG: die data_*-Tabellen müssen für JEDES Feld JEDES Datensatzes befüllt werden,
 * ansonsten funktioniert die Select-Abfrage nicht fallübergreifend. Ein virtuelles Feld
 * ohne Zeile in seiner data_*-Tabelle ist für den Filter nicht existent - und zwar auch
 * für die Verneinung, weil filter() die Bedingung INNERHALB des EXISTS bzw. des JOINs
 * auswertet. Beispiel data_fil (buildFileExists()):
 *
 *     EXISTS (SELECT 1 FROM `data_fil`
 *             WHERE `record_id` = `record`.`record_id` AND `fieldname_id` = ?
 *               AND <Bedingung>)
 *
 * Fehlt die Zeile, liefert das für jede Bedingung false, auch für "!= x". Ein leerer
 * Eintrag ist deshalb kein Ballast, sondern Voraussetzung: erst er macht den Datensatz
 * auffindbar - eine fehlende Datei findet man als `feld.size = 0`, ein leeres Textfeld
 * als `feld = ''`. Wer Einträge anlegt (promoteTmpFiles(), TriggerMedia, Importe),
 * legt sie also auch dann an, wenn noch kein Inhalt vorliegt.
 *
 * ZWEI ARTEN VON TABELLEN
 * --------------------
 * select() arbeitet ohne $tableObj auf `record` - nur dort gibt es virtuelle Felder.
 * Wird eine Dataset-Kindklasse übergeben (Admin, Area, Template, ...), gilt deren
 * Schema, und es werden ausschließlich echte Spalten gefiltert.
 *
 * DAS "AKTUELLE ERGEBNIS"
 * --------------------
 * select() gibt keine Daten zurück, sondern nur Metainformationen (verwendete Sortierung,
 * lesbarer Filtertext). Die Zeilen holt man danach mit fetch() aus dem Datenbank-Ergebnis.
 * Wer währenddessen eine Unterabfrage startet, muss sie mit Database::pushResult() /
 * popResult() klammern.
 *
 * FEHLERBEHANDLUNG
 * --------------------
 * Die Klasse wirft kaum. Fehler werden mit error() gesammelt und stehen danach in
 * $errors / $hasErrors bereit; errorMsg() setzt daraus eine Meldung zusammen und
 * getErrorFields() nennt die betroffenen Felder, damit das Backend sie hervorheben kann.
 * Aufrufer müssen also aktiv nachsehen - ein select() mit unbekanntem Filterfeld
 * liefert stillschweigend ein Ergebnis und legt nur eine Meldung ab.
 *
 * SPRACHE UND ZEITZONE
 * --------------------
 * setLang() stellt die Snippet-Sprache ein; snippet() bindet die Cachedateien
 * var/cache/lang/{lang}/{prefix}.php beim ersten Zugriff auf ein Snippet des Präfixes
 * ein (Frontend-Datei, bei Fehlschlag {prefix}.backend.php - siehe loadSnippets()) und
 * fällt zuletzt auf den Schlüssel selbst zurück; der Sprach-Fallback auf 'en_US' ist beim
 * Bauen des Caches eingefroren (ToolSnippets). Welche Sprachen es gibt, sagt
 * backendLanguages() (Core/lang/*.ini und var/translation/translated). Der Konstruktor
 * stellt 'en_US' ein und die Zeitzone aus system.timezone; danach übernimmt der Kontext:
 *
 *     Backend    Session::load()/create() rufen applyAdminSettings() mit den
 *                persönlichen Einstellungen des Admins (`admin`.`settings`:
 *                backend_code, locale, timezone - AdminBackend::settings(),
 *                Anmeldeformular)
 *     Frontend   setLanguage() stellt Snippet-Sprache, Zeitzone und Locale der
 *                Inhaltssprache ein (`language`.`backend_code`, `language`.`timezone`,
 *                `language`.`code`) - Meldungen und Datumsformate der CMS-Befehle
 *                kommen so in der Sprache der Seite
 *
 * Die LOCALE (ICU, z. B. de_AT) ist von der Backend-Sprache getrennt: sie bestimmt
 * Datums-, Zeit- und Zahlenformate (_formatDate(), _formatFloat(), die Eingabe-
 * erkennung in Dataset) über \Locale::getDefault(). Config stellt system.locale ein,
 * setUserLocale() die des Admins (applyAdminSettings()) bzw. im Frontend die der
 * Inhaltssprache (setLanguage()); $userLocale hält den Wert
 * für das Backend (cConfig.locale).
 *
 * Davon zu unterscheiden ist die INHALTSSPRACHE (Tabelle `language`): die Sprache, in
 * der die Datensätze ausgeliefert werden. Jeder Datensatz führt seine Feldinhalte je
 * Sprache seiner Instanz - in den data_*-Tabellen (eine Zeile je Sprache, mit dem
 * Fallback-Wert der Hauptsprache, wo keine Übersetzung vorliegt) und in `record_cache`
 * (das JSON-Abbild je Sprache). select() hängt an jeden JOIN auf diese Tabellen die
 * Sprachbedingung an, fetch() liest den Cache der Sprache:
 *
 *     Frontend   Page::resolveRequest() ruft setLanguage() mit der Sprache aus URL bzw.
 *                Accept-Language; für lesbare Fremdinstanzen ohne diese Sprache gilt
 *                deren Hauptsprache (languageMap)
 *     Backend    applyAdminSettings() füllt $languageMap mit der bevorzugten
 *                Sprachversion des Admins (je Instanz, die sie führt); sonst gilt
 *                die Hauptsprache der Instanz jedes Datensatzes (languageExpr()
 *                baut daraus einen CASE-Ausdruck über `instance_id`)
 *
 * Die Benutzer-Zeitzone (setUserTZ()) gilt für PHP und für die Datenbankverbindung und
 * überschreibt die in Config gesetzte Serverzeitzone. Datums-/Zeitwerte werden in UTC
 * gespeichert und für die Anzeige über utcToUserTZ() zurückgerechnet.
 *
 * FRONTEND-EDITING
 * --------------------
 * CMS:FED / CMS:FED_CODE geben nur etwas aus, wenn das Cookie 'fed' (FED_COOKIE) auf 1
 * oder 2 steht - gesetzt über die persönlichen Einstellungen des Admins
 * (AdminBackend::settings()) bzw. bei der Anmeldung, siehe fedActive().
 *
 * @see Camindo\Core\Dataset          Schreibzugriff, Validierung, Hooks
 * @see Camindo\Core\VirtualFieldsTrait  Schreiben der virtuellen Felder
 * @see Camindo\Core\Backend          Backend-Oberfläche
 * @see Camindo\Core\Pagination       Blätterlisten (CMS:JUMP_*)
 * @see docs/developers/05-select-und-filter.md   Filter-Syntax und Aufbau der Abfrage
 */
namespace Camindo\Core;

use Camindo\Core\Dataset;

class Cms {

    // Datumsgrenzen
    // --------------------
    // Die datetime-Spalten sind NOT NULL - "kein Datum" und "kein Ende" brauchen
    // deshalb je einen Vertreter. Beide sind zugleich die Grenzen, innerhalb derer
    // ein Datum ueberhaupt zulaessig ist; Dataset::validateFieldValue() weist alles
    // darunter beim Speichern ab.
    //
    //     DATE_MIN   kein Datum gesetzt          (UTC, exakt)
    //     DATE_MAX   kein Ende / unbegrenzt      (Schema-Default von offline_at)
    public const DATE_MIN = '1000-01-01 00:00:00';
    public const DATE_MAX = '9000-01-01 00:00:00';

    // Anzeigefenster (_formatDate)
    // --------------------
    // Beim Lesen rechnet utcToUserTZ() in die Zeitzone des Users um - ein
    // gespeichertes DATE_MIN kommt als '1000-01-01 00:53:28' zurueck (Berliner
    // Ortszeit vor 1893), in anderen Zonen mit bis zu 14 Stunden Versatz in beide
    // Richtungen. Die Anzeige braucht deshalb einen Tag Spielraum, sonst erschiene
    // der Platzhalter als echtes Datum. Fuer das SPEICHERN gilt das nicht: dort
    // liegt der Wert bereits in UTC vor und DATE_MIN gilt exakt.
    private const DATE_MIN_DISPLAY = '1000-01-02 00:00:00'; // DATE_MIN + 1 Tag
    private const DATE_MAX_DISPLAY = '8999-12-31 00:00:00'; // DATE_MAX - 1 Tag

    public string $lang;                // aktuell eingestellte Sprache
    public array  $errors = [];         // Sammlung von Fehlermeldungen
    public bool   $hasErrors = false;   // true: Fehler aufgetreten
    public array  $modules = [];        // aktive Module      - var/config/components/modules.php (ComponentManager)
    public array  $processors = [];     // aktive Prozessoren - var/config/components/processors.php
    public array  $extensions = [];     // aktive Extensions  - var/config/components/extensions.php
    private array $snippetCache = [];   // Snippets: [lang][prefix][key] => text|Closure, siehe loadSnippets()
    private array $snippetLoaded = [];  // [lang][prefix] => true: Cachedateien eingebunden, siehe loadSnippets()
    private ?bool $backendSnippets = null; // auch {prefix}.backend.php laden? siehe withBackendSnippets()
    private array $fieldnameCache = []; // Zuordnung Name - Type
    private array $recordRefCache = [];  // (CMS:...) id=... -> Datensatz, siehe _record()
    private array $slugCache = [];       // "{record_id}:{language_id}" -> Slug, siehe languageSlug()
    private array $virtualSchemaCache = [];
    private ?array $backendLanguages = null; // siehe backendLanguages()

    // SELCT und FILTER
    public array $dataSuffix = [
        'bit'        => 'bit',
        'chars'      => 'chr',
        'currency'   => 'cur',
        'datetime'   => 'dat',
        'decimal'    => 'dec',
        'encrypted'  => 'enc',
        'file'       => 'fil',
        'float'      => 'flt',
        'fulltext'   => 'ftx',
        'id'         => 'rid',
        'ids'        => 'ids',
        'integer'    => 'int',
        'json'       => 'jsn',
        'password'   => 'pwd',
        'pointer'    => 'rel',
        'pointer1'   => 'rel',
        'link'       => 'rel',
        'relation'   => 'rel',
        'relation1'  => 'rel',
        'text'       => 'txt',
        'time'       => 'tim',
        'timestampl' => 'tsl',
        // 'reltable': Der Inhalt liegt NICHT in der eigenen Spalte, sondern in einer
        // eigenen Relation-Tabelle (z. B. `rel_instance_language`). Das Feld steht nur
        // im Schema, damit Dataset::save() seine Eingabeprüfung darauf anwenden kann;
        // geschrieben und gelesen wird es von hookOnSaved()/hookOnLoaded() der
        // jeweiligen Dataset-Kindklasse.
        // Behandlung: überall wie 'json' (Array-Typ, Backend::formField, backendValue),
        // NUR Cms::select() übergeht den Typ - es gibt keine Spalte zum Durchsuchen
        // und keine data_*-Tabelle. Daher der Platzhalter-Suffix '_none_'.
        // Beispiele: Field 'rel_area_ids', Instance 'additional_languages',
        //            Instance 'readable_instances'
        'reltable'   => '_none_',
        // 'dft' ist Sonderfall und hat keine fieldname_id
        // Kopie der 32 ersten Zeichen des Default-Feldes
        // kann mit Cms:select() durchsucht werden
    ];
    public array $types; // all keys from dataSuffix
    // Feldtypen, die keine eigene Tabellenspalte haben und deshalb weder gefiltert
    // noch sortiert werden können (siehe select() / filter()).
    public array $noColumnTypes = ['reltable'];

    // string-values
    public array $textTypes =     ['text', 'chars', 'encrypted', 'password'];
    // numeric-values
    public array $numericTypes =  ['integer', 'currency', 'float', 'decimal', 'bit', 'id'];
    // date-values
    public array $dateTypes =    ['timestampl', 'datetime'];
    // array-values (auch die n:1-Relationen: ihr Wert ist eine Id-Liste mit
    // höchstens einem Eintrag, not_empty/min/max zählen Einträge)
    public array $arrayTypes =    ['file', 'json', 'relation', 'pointer', 'relation1', 'pointer1', 'link', 'reltable'];
    // relations
    public array $relationTypes = ['relation', 'pointer', 'relation1', 'pointer1', 'link'];
    public array $singleRelationTypes = ['relation1', 'pointer1'];
    // Verweise mit MEHREREN Zielbereichen (`rel_field_area` statt `field.rel_area_id`):
    // pointer/pointer1 und 'link' - die fest verknüpften Inhalte (1:n, gerichtet,
    // je Zieldatensatz höchstens ein Quelldatensatz; Zielbereiche mit
    // `area.dependent` = 1, siehe LinkTrait)
    public array $multiAreaTypes = ['pointer', 'pointer1', 'link'];
    // Feldtypen, deren Zieldatensätze vom Quelldatensatz ABHÄNGEN (Löschen, Kopieren,
    // Verschieben wirken auf sie mit, siehe LinkTrait) - bislang nur 'link'
    public array $dependentTypes = ['link'];
    // Feldtypen, die mehrsprachig geführt werden können (`field`.`multilingual`):
    // alle, deren data_*-Tabelle eine Spalte `language_id` hat - außer 'file'
    // (Dateien und Thumbnails folgen in einem eigenen Schritt) und 'fulltext'
    // (wird je Sprache aus den Quellfeldern erzeugt, nicht eingegeben).
    // Nicht dabei: relation/pointer (data_rel), id (data_rid), password (data_pwd).
    public array $multilingualTypes = [
        'bit', 'chars', 'currency', 'datetime', 'decimal', 'encrypted',
        'float', 'integer', 'json', 'text', 'time', 'timestampl'
    ];
    // Typen, deren data_*-Tabelle die Spalte `empty` führt: 1 = die Zeile dieser
    // Sprache ist ein Fallback aus der Hauptsprache (mehrsprachiges Feld ohne eigene
    // Eingabe). Das sind die mehrsprachigkeitsfähigen Typen; Datei-Felder halten
    // dasselbe in `file_language_id`, alle anderen Typen sind nie ein Fallback.
    // Geschrieben von VirtualFieldsTrait::saveVirtualFields() und
    // LanguageData::addLanguage(), gelesen von createRecordCache() und dem
    // Filterwert "(empty)" in select() (siehe emptyCondition()).
    public const EMPTY_FILTER = '(empty)';
    public function hasEmptyFlag(string $type): bool
    {
        return in_array($type, $this->multilingualTypes, true);
    }

    // Spalten von `data_fil`, die in den Inhalts-Cache eines Datei-Feldes wandern
    // ($fileSortCols plus die Sprache der physischen Datei, siehe mediaFileName())
    public array $fileCacheCols = ['filename', 'mime_type', 'extension', 'size', 'width', 'height', 'file_language_id', 'version'];
    
    // erlaubte Funktionsaufrufe (Datums-Zeit-Funktionen)
    public array $funcWhitelist = [
        'NOW()', // Synonym für CURRENT_TIMESTAMP()
        'CURRENT_TIMESTAMP()',
        'CURDATE()',
        'CURTIME()',
        'UTC_TIMESTAMP()',
        'UTC_DATE()',
        'UTC_TIME()',
    ];
    public string $serverTZ = '';
    public string $userTZ = '';
    // ICU-Locale der Formate (siehe Klassenkommentar "Sprache und Zeitzone")
    public string $userLocale = '';
    // Cookie des Frontend-Editings: 0/fehlt = aus, 1 = bis Browser-Ende bzw. Logout,
    // 2 = dauerhaft (siehe fedActive(), AdminBackend::settings())
    public const FED_COOKIE = 'fed';
    // CMS:FED_SCRIPT gibt den Script-Block nur einmal je Request aus (cmsFedScript())
    private bool $fedScriptOut = false;
    // Backend-Sprache, in der jedes Snippet vorhanden sein muss (Rückfall beim Bauen
    // des Caches, siehe ToolSnippets)
    public const FALLBACK_LANG = 'en_US';
    // Format der Backend-Sprachen = Dateinamen der Sprachdateien (lang/{code}.ini):
    // Sprache und Region wie die ICU-Locale, z. B. de_DE, pt_BR
    public const LANG_PATTERN = '/^[a-z]{2,3}_[A-Z]{2}$/';
    // Präfix (= Name der Cachedatei) der Snippets ohne Komponente: Core/lang, Core/backend/lang
    public const CORE_PREFIX = 'core';
    // Backend-Sprachen mit Schreibrichtung von rechts nach links (<html dir="rtl">,
    // siehe isRtl()). Auch Sprachen ohne Übersetzung stehen hier - legt jemand
    // lang/{code}.ini an, stimmt die Richtung sofort.
    public const RTL_LANGUAGES = [
        'ar_SA',    // Arabisch
        'fa_IR',    // Persisch
        'ur_PK',    // Urdu
        'he_IL',    // Hebräisch
        'ps_AF',    // Paschtu
        'ckb_IQ',   // Zentralkurdisch (Sorani)
        'ug_CN',    // Uigurisch
        'syr_SY',   // Syrisch
        'nqo_GN',   // N'Ko
    ];

    // ZUGRIFFSBESCHRÄNKUNG AUF INSTANZEN
    // --------------------
    // Ein SQL-Ausdruck, den select() jeder Abfrage auf `area`, `record` und
    // `template` als zusätzliche AND-Bedingung anhängt. Leer = keine Einschränkung.
    //
    //     ''                          alles sichtbar (Standard)
    //     '`instance_id` = 5'         nur diese Website
    //     '`instance_id` IN (5,7)'    diese Website und die, die sie lesen darf
    //
    // Gesetzt wird der Ausdruck von außen:
    //     Frontend  Page::resolveInstance() - aus der aufgerufenen Domain und den
    //               `readable_instances` der Instanz
    //     Backend   bei der Anmeldung (Session::applyAccess()), wenn die Gruppe des
    //               Admins nicht Superuser ist: ihre zugewiesenen Instanzen
    // Beides über setInstanceAccess(), damit der Ausdruck aus Zahlen entsteht und
    // nicht von Hand zusammengesetzt wird.
    //
    // WICHTIG: Der Ausdruck geht LITERAL in den Query (keine Bind-Parameter) und ist
    // bewusst NICHT tabellenqualifiziert - `area`, `record` und `template` haben die
    // Spalte, die von select() hinzugezogenen data_*-Tabellen nicht, der Name bleibt
    // also eindeutig. Abfragen, die direkt über Database::query() laufen, müssen ihn
    // selbst anhängen.
    public string $sqlAccess = '';
    // dieselben Ids als Liste (leer = keine Einschränkung), siehe accessInstanceIds()
    private array $accessInstanceIds = [];
    // Tabellen, für die $sqlAccess gilt - alle drei führen eine `instance_id`
    public const ACCESS_TABLES = ['area', 'record', 'template'];
    // Bereichsebene (Backend, Session::applyAccess()): "`area_id` IN (...)" der
    // lesbaren Bereiche der Benutzergruppe, für `record` und `template`; '' = keine
    // Einschränkung. Wie $sqlAccess literal und nicht tabellenqualifiziert.
    public string $sqlAreaAccess = '';
    public const AREA_ACCESS_TABLES = ['record', 'template'];

    // INHALTSSPRACHE
    // --------------------
    // Siehe Klassenkommentar "Sprache und Zeitzone". Gesetzt über setLanguage().
    //
    //     $languageId    angeforderte Sprache (Frontend); null = kein Sprachkontext
    //                    (Backend: null, die Sprache je Instanz steht in $languageMap)
    //     $languageCode  ihr Kürzel, für CMS:URL
    //     $languageMap   instance_id => language_id: welche Sprache je Instanz
    //                    gelesen wird. Im Frontend die angeforderte Sprache für die
    //                    eigene Instanz und die lesbaren Instanzen, die sie führen,
    //                    sonst deren Hauptsprache. Im Backend die bevorzugte
    //                    Sprachversion des Admins (applyAdminSettings()). Leer = wird
    //                    bei Bedarf aus dem Instanz-Cache mit den Hauptsprachen aller
    //                    Instanzen gefüllt.
    //     $instance      Angaben der laufenden Instanz (Frontend, aus InstanceCache)
    //     $languages     Sprachen des Auftritts: eigene Instanz + lesbare Instanzen,
    //                    language_id => Zeile (code, label, endonym); Hauptsprache
    //                    der eigenen Instanz zuerst. Grundlage von CMS:URL und
    //                    CMS:SELECT_LANGVERSION.
    public ?int   $languageId = null;
    public string $languageCode = '';
    public array  $languageMap = [];
    public array  $instance = [];
    public array  $languages = [];

    // select()
    public int $list_rows;
    // Kollation der Textvergleiche des laufenden filter() (textCollation(); '' = die
    // der Spalten)
    private string $filterCollation = '';
    // Sprachen, in denen der laufende filter() sucht (searchLanguageIds(); leer = kein
    // Inhalt, Volltext ungefaltet)
    private array $filterLanguageIds = [];
    private array $selectParams = ['filter','filterop','filtermode','skip','limit','sort','order'];
    private bool $hasVirtualFields;
    public $fileSortCols = ['filename', 'mime_type', 'extension', 'size', 'width', 'height'];

    // siehe Template-Parser
    public bool $returnAfterSuccess = false;
    public string $templatePath = '';
    public int $includeDepth = 0;
    // Ausgabe-Cache von CMS:INCLUDE aus (Vorschau - Page::resolve())
    public bool $includeCacheOff = false;
    // je laufendem CMS:INCLUDE: Fehlerzähler bei Beginn einer Aufzeichnung, sonst null
    private array $includeCacheStack = [];
    // Anzahl der Aufrufe von error() - ob während einer Aufzeichnung Fehler kamen
    private int $errorCount = 0;
    public int $row_no = 0;
    // Zeilenzähler und Template-Variable $cms der umgebenden Ergebnisschleife,
    // beiseitegelegt von php/push_result.php (siehe pushRow())
    private array $rowStack = [];
    // aufgelöste Template-Referenzen je Request: "[bereich.]name" => "{area_id}/{name}"
    private array $templateFiles = [];

    // SCHEMA der Tabelle record
    public array $schema = [
        'record_id' => [
            'type'  => 'integer',
            'label' => '#record.field_record_id',
            'tooltip' => '#area.tooltip_field_name',
            'flags' => ['not_empty'],
        ],
        'area_id' => [
            'type'  => 'integer', // kein foreign key
            'label' => '#record.field_area_id',
            'min'   => 1
        ],
        // Website, zu der der Datensatz gehört. Kein Eingabefeld: der Wert kommt aus
        // dem Inhaltsbereich (`area`.`instance_id`) und wird von Record::hookOnSave()
        // bei jedem Speichern von dort geholt. Die Kopie in `record` trägt den
        // Fremdschlüssel auf `instance` - `record` hat keinen auf `area`, der Bereich
        // könnte die Zuordnung also nicht für seine Datensätze mitverwalten.
        'instance_id' => [
            'type'  => 'integer', // FK auf `instance`, NOT NULL -> nicht 'id'
            'label' => '#record.field_instance_id',
            'min'   => 0
        ],
        'online_at' => [
            'type'  => 'timestampl',
            'label' => '#record.field_online_at',
            'default' => 'now'
        ],
        'offline_at' => [
            'type'  => 'timestampl',
            'label' => '#record.field_offline_at',
            'default' => self::DATE_MAX
        ],
        'status_id' => [
            'type'  => 'integer',
            'label' => '#record.field_status_id',
            'display_type' => 'select',
            'min' => 0
        ],
        'parent_id' => [
            'type'  => 'id',
            'label' => '#record.field_parent_id',
            'display_type' => 'lookup',
        ],
        'parent_ids' => [
            'type'  => 'json',
            'label' => '#record.field_parent_ids',
        ],
        'position' => [
            'type'  => 'integer',
            'label' => '#record.field_position',
            'display_type' => 'lookup'
        ],
        'depth' => [
            'type'  => 'integer',
            'label' => '#record.field_depth',
            'min' => 0,
            'max' => 255
        ],
        'lft' => [
            'type'  => 'integer',
            'label' => '#record.field_lft',
            'min' => 0
        ],
        'rgt' => [
            'type'  => 'integer',
            'label' => '#record.field_rgt',
            'min' => 0
        ],
        'template_id' => [
            'type'  => 'id',
            'display_type' => 'select',
            'label' => '#record.field_template_id',
            'table' => 'template'
        ],
        'created_by' => [
            'type'  => 'id',
            'label' => '#record.field_created_by',
            'table' => 'admin'
        ],
        'changed_by' => [
            'type'  => 'id',
            'label' => '#record.field_changed_by',
            'table' => 'admin'
        ],
        // Besitzer im Backend: der Admin, dem der Datensatz "gehört" - seine Gruppe
        // entscheidet, ob ein anderer Admin schreiben/löschen darf (Rechte "eigene
        // Gruppe" / "fremde Gruppe", siehe Camindo\Core\Access). Beim Anlegen der
        // Session-Admin (Dataset::save()), im Frontend der 'owner' des CMS-Befehls;
        // änderbar nur mit dem Zusatzrecht "Owner anpassen". FK auf `admin`
        // (ON DELETE SET NULL), deshalb Typ 'id'.
        'owner_id' => [
            'type'  => 'id',
            'label' => '#record.field_owner_id',
            'display_type' => 'lookup',
            'table' => 'admin',
            'max'   => 1
        ],
        'created_at' => [
            'type'  => 'timestampl',
            'label' => '#record.field_created_at',
            'default' => 'now'
        ],
        'changed_at' => [
            'type'  => 'timestampl',
            'label' => '#record.field_changed_at',
            'default' => 'now'
        ],
        // Besitzer im Frontend: der Profil-Datensatz (Bereichstyp 'profile') des
        // Users, der den Datensatz über CMS:USER_INPUT angelegt hat. Kein
        // Eingabefeld - RecordUser setzt ihn aus der Frontend-Session; daran
        // hängt die Besitzprüfung von CMS:USER_INPUT / USER_DELETE mit 'id'.
        // FK auf `record` (ON DELETE SET NULL), deshalb Typ 'id' (0 -> NULL).
        'user_id' => [
            'type'  => 'id',
            'label' => '#record.field_user_id',
            'table' => 'record'
        ],
        // spezielles Feld für neuen User generated content
        // (CMS:USER_INPUT bzw. CMS:USER_REGISTER setzen es auf 1, siehe RecordUser)
        'needs_review' => [
            'type'  => 'bit',
            'label' => '#record.field_needs_review',
        ],
        'tmp' => [
            'type'  => 'bit',
        ],
        'deleted' => [
            'type'  => 'bit',
        ]
        // gesondert behandelte Felder:
        // --------------------
        // (müssen in Cms->fetch() übergangen werden, sonst würden sie als
        //  virtuelle Felder behandelt)
        // 'cache'     von select() aus `record_cache` gejointer Inhalts-Cache
        // 'origin_id'
    ];


    public function __construct(
        private Config $config,
        private Database $db,
        private Crypt $crypt,
        private InstanceCache $instanceCache,
        private HtmlSanitizer $htmlSanitizer,
        private SecurityHeaders $securityHeaders,
        private IncludeCache $includeCache,
    )
    {

        // Vorgaben, bis Session (Backend) bzw. Page (Frontend) etwas anderes
        // einstellen - siehe Klassenkommentar "Sprache und Zeitzone"
        $this->setLang(self::FALLBACK_LANG);
        $this->types = array_keys($this->dataSuffix);
        $this->serverTZ = (string)$this->config->get('system.timezone');
        $this->setUserTZ($this->serverTZ);
        $this->userLocale = (string)$this->config->get('system.locale');

        // Schema - Felder als Metafelder markieren
        foreach ($this->schema as &$val) {
            $val['meta'] = 1;
        }
        unset($val);

        // Aktive Komponenten - Laufzeitlisten des ComponentManagers. Jede Datei
        // enthält Zuweisungen $this->{modules|processors|extensions}['Name'] = [...];
        // eine Komponente, die dort fehlt, gibt es zur Laufzeit nicht (inaktiv,
        // wartendes Update, nicht installiert - docs/developers/14-installation-und-update.md, 5.1)
        $collDir = $this->config->get('paths.var_dir') . '/config/components';
        foreach (['modules', 'processors', 'extensions'] as $kind) {
            if (is_file("$collDir/$kind.php")) include "$collDir/$kind.php";
        }

        $this->list_rows = $this->config->get('admin.skiplist_default') ?? 20;

        $this->templatePath = $this->config->get('paths.var_dir') . '/php';
    }

    /**
     * Registriert eine Fehlermeldung, die innerhalb einem CMS-Befehl auftritt
     *
     * @param string $msg    Meldung
     * @param string $name   Spaltenname auf den sich die Fehlermeldung bezieht, z. B. 'title'
     * @param string $label  Spalten-Label auf den sich die Fehlermeldung bezieht, z. B. 'Title'
     */
    public function error(string $msg, string $name = '', string $label = ''): void
    {
        if ($name == '') {
            $this->errors[] = ['text' => $msg];
        }
        else {
            if ($label == '') $label = $name;
            $this->errors[$name] = [
                'label' => $label,
                'text' => $msg
            ];
        }
        $this->hasErrors = true;
        $this->errorCount++;
    }

    /**
     * Setzt doe Fehlemeldungen zurück (neue Sammlung beginnt)
     */
    public function errorsReset(): void
    {
        $this->errors = [];
        $this->hasErrors = false;
    }

    /**
     * Erzeugt aus den registrierten Fehlern eine Meldung
     *
     * @param string $separator  Trennzeichen
     */
    public function errorMsg(string $separator = '<br>'): string
    {
        $arr = array();
        foreach ($this->errors as $key => $val) {
            if (is_int($key)) {
                $arr[] = $val['text'];
            }
            else {
                $arr[] = $val['label'] . $this->snippet('colon') . $val['text'];
            }
        }
        return implode($separator, $arr);
    }

    /**
     * Liefert die letzte Fehlermeldung als Text
     */
    public function errorMsgLast(): string
    {
        $key = array_key_last($this->errors);
        if ($key === null) return '';

        $val = $this->errors[$key];
        if (is_int($key)) {
            return $val['text'];
        }
        // else
        return $val['label'] . $this->snippet('colon') . $val['text'];
    }

    /**
     * Liefert die Namen der von Fehlern betroffenen Felder
     */
    public function getErrorFields(): array
    {
        return array_filter(
            array_keys($this->errors), 
            fn($key) => !is_int($key)
        );
    }

    /**
     * Stellt auf angegebene Sprache um. Geladen wird nichts - snippet() holt die
     * Cachedateien der Sprache bei Bedarf (loadSnippets()).
     * @param string $lang  Sprach-Code
     */
    public function setLang($lang): void
    {
        $this->lang = $lang;
        $cacheDir = $this->config->get('paths.var_dir') . '/cache/lang/' . $this->lang;
        if (!is_dir($cacheDir)) {
            echo 'BACKEND ERROR: Snippet cache ' . htmlspecialchars($cacheDir) . ' not found!';
        }
    }

    /**
     * Cachedateien eines Präfixes einbinden - siehe SnippetCache für den Aufbau.
     *
     * Immer var/cache/lang/{lang}/{prefix}.php (Texte aus lang/, Backend und Frontend),
     * außerhalb des Frontends zusätzlich {prefix}.backend.php (Texte aus backend/lang/).
     * Ein Frontend-Request braucht keine Backend-Texte: alles, was im Frontend
     * ausgegeben wird (auch Fehlermeldungen von Dataset/RecordUser), steht in lang/.
     * Eine fehlende Datei zählt als geladen (leer).
     *
     * @param string $lang    Sprach-Code
     * @param string $prefix  Präfix, z. B. 'record', 'proc.ffmpegvideo' oder CORE_PREFIX
     */
    private function loadSnippets(string $lang, string $prefix): void
    {
        $this->snippetLoaded[$lang][$prefix] = true;
        $base = $this->config->get('paths.var_dir') . '/cache/lang/' . $lang . '/' . $prefix;
        $files = [$base . '.php'];
        if ($this->withBackendSnippets()) $files[] = $base . '.backend.php';
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            $snippets = (include $file);
            if (!is_array($snippets)) continue;
            $this->snippetCache[$lang][$prefix] = ($this->snippetCache[$lang][$prefix] ?? []) + $snippets;
        }
    }

    /**
     * Werden die Backend-Texte gebraucht? Im Backend (CAMINDO_BACKEND, auch die
     * Vorschau) und auf der Kommandozeile (Cron, Hintergrundprozesse des Backends) -
     * nur ein Frontend-Request kommt ohne sie aus.
     */
    private function withBackendSnippets(): bool
    {
        return $this->backendSnippets ??= (defined('CAMINDO_BACKEND') && CAMINDO_BACKEND === true)
            || PHP_SAPI === 'cli';
    }

    /**
     * Backend-Sprachen: die Kürzel, für die es Snippets gibt (Core/lang/{code}.ini und
     * die Übersetzungspakete var/translation/translated/{code}….ini, SnippetCache).
     * Grundlage der Auswahllisten in Language::backend_code und in den persönlichen
     * Einstellungen. Die Liste schreibt SnippetCache::build() einmalig nach
     * var/cache/lang/languages.php; nur wenn die Datei fehlt (Cache noch nicht
     * gebaut), wird das Verzeichnis durchsucht. Je Request gemerkt.
     *
     * @return string[]  z. B. ['de_DE', 'en_US'] - FALLBACK_LANG ist immer dabei
     */
    public function backendLanguages(): array
    {
        if ($this->backendLanguages !== null) return $this->backendLanguages;
        $file = $this->config->get('paths.var_dir') . '/cache/lang/' . SnippetCache::LANGUAGES_FILE;
        $langs = is_file($file) ? (include $file) : null;
        if (!is_array($langs) || !$langs) {
            $langs = self::scanLanguages($this->config->get('paths.core_dir') . '/lang', SnippetCache::translatedDir($this->config));
        }
        return $this->backendLanguages = $langs;
    }

    /**
     * Sprachkürzel eines lang-Verzeichnisses (Dateinamen {code}.ini im Format
     * LANG_PATTERN), optional dazu die eines Verzeichnisses mit
     * Übersetzungspaketen ({code}.ini, {code}_….ini - SnippetCache::translatedLanguage()),
     * sortiert, FALLBACK_LANG immer dabei. Auch für SnippetCache, das ohne Cms
     * auskommen soll.
     *
     * @return string[]
     */
    public static function scanLanguages(string $dir, ?string $translatedDir = null): array
    {
        $codes = [];
        foreach ((array)glob($dir . '/*.ini') as $file) {
            $code = basename($file, '.ini');
            if (preg_match(self::LANG_PATTERN, $code)) $codes[$code] = true;
        }
        if ($translatedDir !== null) {
            foreach ((array)glob($translatedDir . '/*.ini') as $file) {
                $code = SnippetCache::translatedLanguage($file);
                if ($code !== null) $codes[$code] = true;
            }
        }
        $codes[self::FALLBACK_LANG] = true;
        $codes = array_keys($codes);
        sort($codes);
        return $codes;
    }

    /**
     * Backend-Sprache aus einem Kürzel ableiten - mit Rückfall auf FALLBACK_LANG.
     *
     * Nimmt Backend-Kürzel ("de_DE"), Locale-Angaben ("de_AT", "fr-FR") und die
     * früheren zweistelligen Kürzel ("de" in `language`.`backend_code` und
     * `admin`.`settings`): ohne exakten Treffer zählt der Sprachteil - es gilt
     * die erste Backend-Sprache mit diesem Sprachteil ("de_AT" -> "de_DE").
     * Gibt es dafür keine Snippets, gilt FALLBACK_LANG.
     *
     * @param string $code  Backend-Kürzel oder Locale
     */
    public function resolveBackendLang(string $code): string
    {
        return self::matchLanguage($code, $this->backendLanguages()) ?? self::FALLBACK_LANG;
    }

    /**
     * Kürzel oder Locale einer der verfügbaren Sprachen zuordnen (siehe
     * resolveBackendLang()): exakt, sonst über den Sprachteil; null ohne Treffer.
     *
     * @param string   $code       z. B. "de_DE", "de-at", "de"
     * @param string[] $available  Kürzel im Format LANG_PATTERN, sortiert
     */
    public static function matchLanguage(string $code, array $available): ?string
    {
        $parts = preg_split('/[-_]/', trim($code));
        $base = strtolower($parts[0] ?? '');
        if ($base === '') return null;
        $region = strtoupper((string)end($parts));
        if (count($parts) > 1 && in_array($base . '_' . $region, $available, true)) return $base . '_' . $region;
        foreach ($available as $lang) {
            if (str_starts_with($lang, $base . '_')) return $lang;
        }
        return null;
    }

    /**
     * Ist die Angabe eine gültige Zeitzone (PHP-Bezeichner wie "Europe/Berlin")?
     */
    public function isValidTimezone(string $timezone): bool
    {
        return $timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Benutzer-Zeitzone festlegen - für PHP und die Datenbankverbindung.
     *
     * Quelle je Kontext:
     *     Backend    persönliche Einstellung des Admins (applyAdminSettings())
     *     Frontend   Zeitzone der Inhaltssprache des Requests (setLanguage())
     *     sonst      system.timezone aus der Konfiguration (Konstruktor)
     *
     * Eine leere oder unbekannte Angabe wird übergangen - die bisherige bleibt.
     *
     * @param string $timezone  PHP-Bezeichner, z. B. 'Europe/Berlin'
     * @return bool             true: übernommen (oder bereits eingestellt)
     */
    public function setUserTZ(string $timezone): bool
    {
        $timezone = trim($timezone);
        if (!$this->isValidTimezone($timezone)) return false;
        if ($timezone === $this->userTZ) return true;
        $this->userTZ = $timezone;
        date_default_timezone_set($timezone);
        // Der Bezeichner ist gegen listIdentifiers() geprüft und darf literal in den Query
        $this->db->query("SET time_zone = '{$timezone}'");
        return true;
    }

    /**
     * Ist die Angabe eine ICU-Locale mit Region (z. B. "de_AT", "zh_Hans_CN")? Nur
     * solche stehen zur Wahl - erst die Region legt das Datumsformat fest.
     */
    public function isValidLocale(string $locale): bool
    {
        return $locale !== '' && in_array($locale, self::localeIdentifiers(), true);
    }

    /**
     * ICU-Locales mit Region, die diese PHP-Installation kennt - Grundlage der
     * Auswahllisten (Backend::localeOptions()) und der Prüfung gespeicherter Werte.
     *
     * @return string[]
     */
    public static function localeIdentifiers(): array
    {
        static $list = null;
        if ($list !== null) return $list;
        $list = [];
        if (class_exists(\ResourceBundle::class)) {
            foreach ((array)\ResourceBundle::getLocales('') as $locale) {
                if (\Locale::getRegion($locale) !== '') $list[] = $locale;
            }
        }
        return $list;
    }

    /**
     * Locale der Formate festlegen (\Locale::getDefault() für IntlDateFormatter und
     * NumberFormatter). Eine leere oder unbekannte Angabe wird übergangen - es bleibt
     * system.locale aus der Konfiguration (Config).
     *
     * @param string $locale  ICU-Locale, z. B. 'de_AT'
     * @return bool           true: übernommen (oder bereits eingestellt)
     */
    public function setUserLocale(string $locale): bool
    {
        $locale = trim($locale);
        if (!$this->isValidLocale($locale)) return false;
        if ($locale === $this->userLocale) return true;
        $this->userLocale = $locale;
        \Locale::setDefault($locale);
        setlocale(LC_TIME, $locale . '.utf8');
        return true;
    }

    /**
     * Persönliche Einstellungen eines Admins übernehmen (`admin`.`settings`):
     * Backend-Sprache, Locale und Zeitzone. Aufgerufen von Session, sobald die
     * Session eines Admins geladen oder erzeugt wurde - damit gilt alles für den
     * ganzen Request.
     *
     * @param array $settings  ['backend_code' => 'de', 'locale' => 'de_AT',
     *                          'timezone' => 'Europe/Berlin', ...]
     */
    public function applyAdminSettings(array $settings): void
    {
        $code = (string)($settings['backend_code'] ?? '');
        if ($code !== '') $this->setLang($this->resolveBackendLang($code));
        $this->setUserLocale((string)($settings['locale'] ?? ''));
        $this->setUserTZ((string)($settings['timezone'] ?? ''));

        // Bevorzugte Sprachversion: Inhaltssprache des Backends. Wie im Frontend
        // (Page::resolveLanguage()) je Instanz die bevorzugte Sprache, wenn die
        // Instanz sie führt, sonst deren Hauptsprache - ab da liefern select() und
        // fetch() Listen, Suche und Detailansicht in dieser Sprache. $languageId
        // bleibt null: das Backend hat keine Sprache "des Aufrufs" (CMS:URL & Co.).
        $preferred = (string)($settings['language_id'] ?? '');
        if ($preferred !== '' && ctype_digit($preferred)) {
            $this->languageMap = [];
            foreach ((array)($this->instanceCache->all()['instance_ids'] ?? []) as $instanceId) {
                $ids = $this->instanceCache->languageIds((int)$instanceId);
                $this->languageMap[(int)$instanceId] = in_array((int)$preferred, $ids, true)
                    ? (int)$preferred
                    : (int)($ids[0] ?? $this->instanceCache->primaryLanguageId((int)$instanceId));
            }
        }
    }

    /**
     * Inhaltssprache des Backends auf die lesbaren Sprachen der Benutzergruppe
     * beschränken (Session::applyAccess(), 12-group-rights.md A8): führt eine
     * Instanz die bevorzugte Sprache nicht lesbar, gilt dort die erste lesbare
     * Sprache in der Reihenfolge der Instanz; ist keine lesbar, bleibt die
     * bisherige (die Listen zeigen dann die Hauptsprache - Inhalte dieser
     * Instanz sind für die Gruppe ohnehin nicht bearbeitbar).
     *
     * @param int[] $readableLanguageIds  Access::readableLanguageIds()
     */
    public function restrictLanguageMap(array $readableLanguageIds): void
    {
        foreach ((array)($this->instanceCache->all()['instance_ids'] ?? []) as $instanceId) {
            $instanceId = (int)$instanceId;
            $ids = $this->instanceCache->languageIds($instanceId);
            $current = (int)($this->languageMap[$instanceId] ?? ($ids[0] ?? $this->instanceCache->primaryLanguageId($instanceId)));
            if (in_array($current, $readableLanguageIds, true)) {
                $this->languageMap[$instanceId] = $current;
                continue;
            }
            foreach ($ids as $languageId) {
                if (in_array($languageId, $readableLanguageIds, true)) {
                    $current = $languageId;
                    break;
                }
            }
            $this->languageMap[$instanceId] = $current;
        }
    }

    /**
     * Frontend-Editing eingeschaltet? Entscheidet das Cookie FED_COOKIE:
     *     1  eingeschaltet bis zum Schließen des Browsers bzw. bis zum Logout
     *     2  eingeschaltet auch darüber hinaus
     * Alles andere heißt aus. Das Cookie schaltet nur die Ausgabe der Verweise frei -
     * das Backend selbst verlangt weiterhin die Anmeldung.
     */
    public function fedActive(): bool
    {
        // signierter Wert (FrontendHandover::fedCookieValue()) - ein von Hand
        // gesetztes "fed=1" zeigt nichts, sonst verriete cFED() die Backend-Domain
        return FrontendHandover::fedModeOf($this->crypt, (string)($_COOKIE[self::FED_COOKIE] ?? '')) > 0;
    }

    /**
     * Verzeichnis einer aktiven Komponente (aus der Laufzeitliste), null wenn sie
     * zur Laufzeit nicht existiert.
     *
     * @param string $kind  'modules', 'processors', 'extensions'
     */
    public function componentPath(string $kind, string $name): ?string
    {
        $path = $this->{$kind}[$name]['path'] ?? null;
        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Liefert aktuell eingestellte Sprache
     * @return string   Language Code
     */
    public function getLang(): string
    {
        // Never returns an empty string - see __construct()
        return $this->lang;
    }

    /**
     * Schreibrichtung einer Sprache von rechts nach links? Zählt der Sprachteil
     * (RTL_LANGUAGES): "ar_EG" oder "ar-EG" gilt wie "ar_SA".
     *
     * @param string $lang  Backend-Kürzel oder Locale, leer = aktuelle Sprache (getLang())
     */
    public function isRtl(string $lang = ''): bool
    {
        if ($lang === '') $lang = $this->lang;
        $base = strtolower((string)preg_split('/[-_]/', trim($lang))[0]);
        if ($base === '') return false;
        foreach (self::RTL_LANGUAGES as $code) {
            if (str_starts_with($code, $base . '_')) return true;
        }
        return false;
    }

    /**
     * Wert für das dir-Attribut: 'rtl' oder 'ltr' (siehe isRtl())
     */
    public function textDirection(string $lang = ''): string
    {
        return $this->isRtl($lang) ? 'rtl' : 'ltr';
    }

    /**
     * Liefert eine Übersetzung (Sprach-Schnipsel)
     *
     * @param string $index  Index im Format {module-name.}snippet-index
     * @param array $params  Parameter für ICU Message-Format
     * @param string $lang   Sprachkürzel z. B. 'en_US'
     * @return string
     */
    public function snippet(string $index, array $params = [], string $lang = ''): string
    {
        if ($index === '') return '';
        if ($lang === '') $lang = $this->lang;

        // Präfix = alles vor dem letzten Punkt (Schlüssel enthalten keine Punkte),
        // ohne Punkt: Core-Snippet
        $pos = strrpos($index, '.');
        if ($pos === false) {
            $prefix = self::CORE_PREFIX;
            $key = $index;
        }
        else {
            $prefix = substr($index, 0, $pos);
            $key = substr($index, $pos + 1);
            // Kurzform custom.{key}: Snippet-Set der aktuellen Instanz (Modul
            // Snippets, Präfix custom.{instance_id}) - im Frontend nach
            // setLanguage(); ohne Instanzkontext bleibt es beim Rückfall auf $index
            if ($prefix === SnippetCache::CUSTOM_PREFIX && !empty($this->instance['instance_id'])) {
                $prefix .= '.' . (int)$this->instance['instance_id'];
            }
        }

        // Cachedateien des Präfixes beim ersten Zugriff einbinden
        if (!isset($this->snippetLoaded[$lang][$prefix])) {
            $this->loadSnippets($lang, $prefix);
        }
        $text = $this->snippetCache[$lang][$prefix][$key] ?? '';

        // Fallback $index
        if ($text === '') return $index;

        // ICU Message Format
        if ($text instanceof \Closure) {
            $text = $text($params);
        }
        return $text;
    }

    /**
     * Die API aus einem Template, Trigger-Template oder Custom Tool aufrufen
     * (Camindo\Core\Api\Api::call()).
     *
     * Läuft schon eine API-Session (Zeitplaner, CLI, PHP-API), gilt sie. Sonst arbeitet
     * der Aufruf in der geladenen Backend-Session mit (ApiSession::attach(), Kanal
     * 'tool') - etwa ein Trigger-Template, das beim Speichern im Backend läuft: es
     * handelt als der Admin, der speichert. Ohne Admin-Session (Frontend) antwortet
     * die API mit not_logged_in.
     *
     * @param string $method  z. B. 'record.search'
     * @param array  $params  Parameter (snake_case)
     * @return array          ['ok' => bool, 'data' => …] bzw. ['ok' => false, 'error' => …, 'message' => …]
     */
    public function api(string $method, array $params = []): array
    {
        $app = $GLOBALS['app'];
        $context = $app->get(\Camindo\Core\Api\ApiContext::class);
        $apiSession = $app->get(\Camindo\Core\Api\ApiSession::class);
        $attached = false;
        if (!$context->active() && $context->interface() === 'backend') {
            try {
                $apiSession->attach('tool');
                $attached = true;
            }
            catch (\Camindo\Core\Api\ApiException) {
                // Api::call() meldet not_logged_in
            }
        }
        try {
            return $app->get(\Camindo\Core\Api\Api::class)->call($method, $params);
        }
        finally {
            if ($attached) $apiSession->logout();
        }
    }

    /**
     * Convert Label, starting with '#' into snippetText
     * @param string $text  Text, oder '#'+Snippet-Id
     */
    public function textOrSnippet(string $text): string
    {
        if (empty($text)) return '';
        if (substr($text,0,1) == '#') {
            return $this->snippet(substr($text,1));
        }
        else return $text;
    }

    /**
     * Entfernt angegebene Parameter aus der URL und bereitet sie für neue Parameter vor.
     * 
     * Parameter-Format: 'formvars' erfasst auch 'formvars[myfield]'
     *
     * @param string|array $params  Zu entfernende Parameter
     * @param string       $uri     URL (Standard: aktuelle REQUEST_URI)
     * @return string               Bereinigte URL, endet mit '?' oder '&'
     */
    public function sys_reduce_uri(string|array $params = [], string $uri = ''): string
    {
        if (!is_array($params)) {
            $params = [$params];
        }

        if (empty($uri)) {
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
        }

        if (defined('CAMINDO_BACKEND') && CAMINDO_BACKEND === true) {
            // im Backend zusätzlich Parameter cache entfernen
            $params[] = 'cache';
            // und Pfad zu modules entfernen - siehe Javascript cLoadContent()
            $prefix = $this->config->get('paths.cms') . '/Modules';
            if (str_starts_with($uri, $prefix)) {
                $uri = substr($uri, strlen($prefix));
            }
        }

        /* ganze Arrays $a[...] entfernen */
        foreach ($params as $param) {
            $param = preg_quote($param, '!');
            $uri = preg_replace(
                "!([?&])&*{$param}(\[[^\]]*\])*=[^?&=]*!",
                '$1',
                $uri
            );
        }

        // Mehrfache ?, & und Kombinationen bereinigen sowie abschließende Trennzeichen entfernen
        $uri = preg_replace(['!&+!', '!&?\\?+!', '!\\?&!', '![?&]+$!'], ['&', '?', '?', ''], $uri);

        // URL für Anhängen neuer Parameter vorbereiten
        return str_contains($uri, '?') ? $uri . '&' : $uri . '?';
    }

    /**
     * Setzt ersten Buchstaben von $string in Großbuchstaben um
     * @param string $string
     */
    public function mb_ucfirst(string $string): string {
        $firstChar = mb_substr($string, 0, 1, 'utf-8');
        $rest = mb_substr($string, 1, null, 'utf-8');
        return mb_strtoupper($firstChar, 'utf-8') . $rest;
    }

    /**
     * Bereitet Text für die Volltextsuche vor - beim Speichern (data_ftx) und für die
     * Suchbegriffe (buildFulltextCondition()).
     *
     * Bindestrich und Unterstrich trennen Wörter; beim Speichern kommt zusätzlich die
     * zusammengeschriebene Variante in den Index ("sofa-weiss" -> "sofa weiss
     * sofaweiss"). So findet "sofaweiss", "sofa-weiss" (Wortfolge) und "weiss"
     * denselben Text, auch "EMail" ein gespeichertes "E-Mail". Suchbegriffe
     * ($forSearch) werden nicht verdoppelt - dort macht buildFulltextCondition() aus
     * mehreren Wörtern eine Wortfolge.
     *
     * Mit $languageId wird der Text nach den Regeln der Sprache gefaltet
     * (Collation::fold(): im Deutschen "Müller" -> "mueller", neutral "muller") -
     * `data_ftx` und die Suchbegriffe darauf; Text und Suchbegriff brauchen dieselbe
     * Sprache.
     *
     * @param string   $text        Der zu konvertierende Text
     * @param bool     $forSearch   true: Suchbegriff (ohne zusammengeschriebene Variante)
     * @param int|null $languageId  Sprache, nach deren Regeln gefaltet wird; null = nicht falten
     */
    public function indexText(string $text, bool $forSearch = false, ?int $languageId = null): string {
        // HTML-Tags (Rich-Text-Felder) sind keine Wörter: durch Leerzeichen ersetzen,
        // damit "Wort</p><p>Wort" zwei Wörter bleiben
        $text = preg_replace('/<[^>]*>/', ' ', $text) ?? $text;

        // HTML-Entities korrekt decodieren (WICHTIG: | statt & verwenden)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Weiche Trennzeichen (&shy;, decodiert \u{00AD}) verbinden - sie stehen nur
        // für die Silbentrennung im Wort
        $text = str_replace("\u{00AD}", '', $text);

        // Mit Binde- oder Unterstrich verbundene Wörter: getrennt UND zusammengeschrieben
        // (Bindestrich, typografischer Bindestrich U+2010/U+2011, Unterstrich; der Punkt
        // nicht - "foto.jpg" soll kein "fotojpg" ergeben)
        if (!$forSearch) {
            $text = preg_replace_callback(
                '/[\p{L}\p{N}]+(?:[-_\x{2010}\x{2011}]+[\p{L}\p{N}]+)+/u',
                fn(array $m) => $m[0] . ' ' . preg_replace('/[-_\x{2010}\x{2011}]+/u', '', $m[0]),
                $text
            ) ?? $text;
        }

        // Alles außer Buchstaben, Zahlen, und den Zeichen @$[]{} (Coding) durch EIN
        // Leerzeichen ersetzen (\p{L} = Buchstaben, \p{N} = Zahlen). Das + fasst
        // mehrere Zeichen zusammen - auch die Trenner der getrennten Variante oben.
        $text = preg_replace('/[^\p{L}\p{N}@$\[\]\{\}]+/u', ' ', $text) ?? $text;
        $text = trim($text);

        if ($languageId !== null) $text = Collation::fold($text, $this->instanceCache->languageCode($languageId));
        return $text;
    }

    /**
     * Suchwert eines verschlüsselten Feldes (data_enc.hash) normalisieren - beim
     * Speichern (VirtualFieldsTrait::saveVirtualFields()) und für den Suchbegriff
     * (buildEncryptedCondition(), RecordUser): indexText() als Suchbegriff (ohne
     * Sprache), klein geschrieben und ohne Leerzeichen. Satzzeichen und Leerraum
     * zählen damit nicht: "Anna.Muster@Example.com" findet "anna.muster@example.com",
     * "+49 171 123-456" findet "49171123456" (nicht aber "0049171123456" - die
     * Ziffern selbst bleiben, wie sie sind).
     *
     * Bleibt nichts übrig (nur Satzzeichen), ist das Ergebnis '' - wie ein leerer Wert.
     */
    public function encryptedSearchText(string $value): string
    {
        return str_replace(' ', '', mb_strtolower($this->indexText($value, true), 'UTF-8'));
    }

    /**
     * HMAC des normalisierten Werts (encryptedSearchText()) für data_enc.hash - binär,
     * 32 Bytes; '' bei leerem Ergebnis.
     */
    public function encryptedHash(string $value): string
    {
        return $this->crypt->hash($this->encryptedSearchText($value), true);
    }

    /**
     * Beliebigen Text in einen Slug umwandeln (URL-Pfadteil).
     *
     * Der Slash bleibt ausdrücklich stehen: ein Slug darf mehrstufig sein
     * ("produkte/wanderschuhe"), die Rewrite-Regel reicht den ganzen Pfad als
     * `request` durch. Umlaute und andere Buchstaben bleiben ebenfalls erhalten -
     * \w erfasst unter dem u-Modifier auch sie.
     *
     * Alles andere wird zu einem Bindestrich; doppelte Bindestriche, solche direkt
     * am Slash und am Anfang/Ende fallen wieder weg.
     *
     *     "Über uns / Team & Co."   ->  "über-uns/team-co"
     *
     * @param string $text   Quelltext, üblicherweise der Inhalt des Default-Feldes
     * @param int    $limit  Maximale Länge; 0 = Vorgabe aus system.link_length
     * @return string        Slug in Kleinbuchstaben
     * @see Camindo\Core\Modules\Record\Record::hookOnSave()  einziger Aufrufer
     */
    public function textToUri(string $text, int $limit = 0): string
    {
        if ($limit < 1) $limit = (int)$this->config->get('system.link_length');
        if ($limit < 1) $limit = 120;

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Tags entfernen, Zeilenumbrüche werden zu Trennern
        $text = $this->_nl2br($this->_strip_tags($text), '-');
        // Alles außer Wortzeichen und Slash zu '-' (\w deckt Ziffern und _ mit ab)
        $text = preg_replace('![^\w/]!u', '-', $text);
        // Bindestriche am Slash entfernen
        $text = preg_replace('!-?/-?!', '/', $text);
        // Mehrfache Bindestriche bzw. Slashes zusammenfassen - ein leeres
        // Pfadsegment ("a//b") wäre eine andere URL als "a/b"
        $text = preg_replace('!--+!', '-', $text);
        $text = preg_replace('!//+!', '/', $text);
        // Kürzen
        if (mb_strlen($text, 'UTF-8') > $limit) $text = mb_substr($text, 0, $limit, 'UTF-8');
        // Trennzeichen am Anfang und Ende entfernen (das Kürzen kann neue erzeugen)
        $text = trim($text, '-/');

        return mb_strtolower($text, 'UTF-8');
    }

    // ====================

    /**
     * Zugriff auf Instanzen festlegen - Gegenstueck zu $sqlAccess.
     *
     * Baut den SQL-Ausdruck aus einer Id-Liste, damit er nicht von Hand
     * zusammengesetzt werden muss (und keine Fremdeingabe hineingerät):
     *
     *     []        -> ''                       keine Einschraenkung
     *     [5]       -> '`instance_id` = 5'
     *     [5,7]     -> '`instance_id` IN (5,7)'
     *
     * @param array $instanceIds  instance_ids, auf die zugegriffen werden darf.
     *                            Leeres Array hebt die Beschraenkung auf.
     */
    public function setInstanceAccess(array $instanceIds): void
    {
        $ids = [];
        foreach ($instanceIds as $id) {
            $id = (int)$id;
            if ($id < 1) continue;
            if (in_array($id, $ids, true)) continue;
            $ids[] = $id;
        }
        $this->accessInstanceIds = $ids;
        if (empty($ids)) {
            $this->sqlAccess = '';
        }
        elseif (count($ids) === 1) {
            $this->sqlAccess = '`instance_id` = ' . $ids[0];
        }
        else {
            $this->sqlAccess = '`instance_id` IN (' . implode(',', $ids) . ')';
        }
    }

    /**
     * Zugriff auf KEINE Instanz - für eine Benutzergruppe ohne zugewiesene Instanz
     * (Session::applyAccess()). setInstanceAccess([]) hieße "keine Einschränkung",
     * deshalb ein eigener Ausdruck, den keine Zeile erfüllt; areaMap() ist leer.
     */
    public function setInstanceAccessNone(): void
    {
        $this->accessInstanceIds = [0];
        $this->sqlAccess = '`instance_id` = 0';
    }

    /**
     * Zugriff auf Bereiche festlegen - Gegenstück zu $sqlAreaAccess (Backend: die
     * lesbaren Bereiche der Benutzergruppe, Session::applyAccess()).
     *
     *     null  -> ''                        keine Einschränkung
     *     []    -> '`area_id` = 0'           kein Bereich
     *     [5,7] -> '`area_id` IN (5,7)'
     *
     * @param int[]|null $areaIds
     */
    public function setAreaAccess(?array $areaIds): void
    {
        if ($areaIds === null) {
            $this->sqlAreaAccess = '';
            return;
        }
        $ids = [];
        foreach ($areaIds as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
        }
        $this->sqlAreaAccess = empty($ids) ? '`area_id` = 0' : '`area_id` IN (' . implode(',', $ids) . ')';
    }

    /**
     * Instanzen, auf die dieser Request zugreifen darf (setInstanceAccess()) -
     * die eigene zuerst. Leer = keine Einschränkung (Backend), [0] = keine Instanz
     * (setInstanceAccessNone()).
     *
     * @return int[]
     */
    public function accessInstanceIds(): array
    {
        return $this->accessInstanceIds;
    }

    /**
     * Darf dieser Request Inhalte der angegebenen Instanz sehen?
     * Ohne Einschränkung (Backend) immer ja.
     */
    public function instanceAllowed(int $instanceId): bool
    {
        return empty($this->accessInstanceIds) || in_array($instanceId, $this->accessInstanceIds, true);
    }

    /**
     * VERÖFFENTLICHT - die Bedingung, unter der das Frontend einen Datensatz zeigt.
     *
     * Ein Datensatz ist veröffentlicht, wenn er freigegeben ist (`status_id` = 0),
     * sein Erscheinungsdatum erreicht ist (`online_at` <= jetzt) und sein Ende noch
     * nicht (`offline_at` > jetzt; DATE_MAX = kein Ende). Verglichen wird in UTC -
     * so sind die Spalten gespeichert, und UTC_TIMESTAMP() ist unabhängig von der
     * Zeitzone der Verbindung (setUserTZ()).
     *
     * Drei Parameter lockern die Bedingung - die Namen sind die von CMS:SELECT:
     *
     *     future    'true'/true  ->  vordatierte Datensätze auch (keine online_at-Bedingung)
     *     archive   'true'/true  ->  abgelaufene Datensätze auch (keine offline_at-Bedingung)
     *     state     (int) > 0    ->  statt freigegeben: Datensätze mit genau diesem Status
     *               'any'        ->  jeder Status
     *
     * Verwendet von cmsSelect() (jede Abfrage eines Templates) und von Page (der über
     * die URL aufgerufene Datensatz, dort mit archive: ein abgelaufener Datensatz
     * bleibt unter seiner Adresse erreichbar, er erscheint nur in keiner Liste
     * mehr). Das Backend ruft select() direkt auf und sieht alles.
     *
     * Der Ausdruck geht literal in die Abfrage (kein Bind-Parameter) - er enthält
     * nur Spaltennamen, Funktionen und eine Ganzzahl.
     *
     * @param array  $p      Parameter (future, archive, state) - andere Schlüssel
     *                       werden ignoriert, es kann also das ganze Parameter-Array
     *                       eines Befehls übergeben werden
     * @param string $table  Tabellenalias, mit dem die Spalten qualifiziert werden
     *                       (select() joint `record` für Sortierungen ein zweites
     *                       Mal, unqualifiziert wären die Namen mehrdeutig)
     * @return string        SQL-Bedingung ohne führendes AND; '' wenn alle drei
     *                       Teile abgeschaltet sind (future, archive, state=any)
     */
    public function publishedSql(array $p = [], string $table = 'record'): string
    {
        $parts = [];
        if (!$this->_bool($p['future'] ?? false)) {
            $parts[] = "`$table`.`online_at` <= UTC_TIMESTAMP()";
        }
        if (!$this->_bool($p['archive'] ?? false)) {
            $parts[] = "`$table`.`offline_at` > UTC_TIMESTAMP()";
        }
        $state = $p['state'] ?? '';
        if (!is_scalar($state) || strtolower(trim((string)$state)) !== 'any') {
            // '' und alles <= 0 bedeutet: freigegeben (Status 0)
            $parts[] = "`$table`.`status_id` = " . max(0, (int)$state);
        }
        return implode(' AND ', $parts);
    }

    /**
     * Inhaltssprache des laufenden Requests festlegen (Frontend).
     *
     * @param int    $languageId    angeforderte Sprache
     * @param string $languageCode  ihr Kürzel (für CMS:URL)
     * @param array  $languageMap   instance_id => language_id für alle lesbaren
     *                              Instanzen; leer = nur die eigene Instanz
     * @param array  $instance      Angaben der laufenden Instanz (InstanceCache)
     * @param array  $languages     Sprachen des Auftritts (InstanceCache::languagesOf());
     *                              leer = die der Instanz
     */
    public function setLanguage(int $languageId, string $languageCode, array $languageMap = [], array $instance = [], array $languages = []): void
    {
        $this->languageId   = $languageId;
        $this->languageCode = $languageCode;
        $this->languageMap  = [];
        foreach ($languageMap as $instanceId => $langId) {
            $this->languageMap[(int)$instanceId] = (int)$langId;
        }
        $this->instance  = $instance;
        $this->languages = $languages ?: (array)($instance['languages'] ?? []);

        // Zeitzone und Snippet-Sprache der Inhaltssprache (`language`.`timezone`,
        // `language`.`backend_code`, beides im Instanz-Cache). Ohne Zeitzone an der
        // Sprache gilt system.timezone; ohne Backend-Kürzel greift der Rückfall FALLBACK_LANG.
        $language = (array)($this->languages[$languageId] ?? []);
        $timezone = (string)($language['timezone'] ?? '');
        $this->setUserTZ($timezone !== '' ? $timezone : $this->serverTZ);
        $this->setLang($this->resolveBackendLang((string)($language['backend_code'] ?? '')));
        // Datums- und Zeitformate (CMS:DATE, CMS:DATETIME …) in der Inhaltssprache: das
        // Sprachkürzel, sonst das Backend-Kürzel. Es genügt, dass ICU die Sprache kennt -
        // ICU listet etwa zh_Hans_CN, formatiert mit zh_CN aber genauso; ein Kürzel wie en_GB wie en.
        // Kennt ICU keine von beiden, bleibt system.locale.
        foreach ([$languageCode, (string)($language['backend_code'] ?? '')] as $locale) {
            if ($this->isKnownLanguage($locale)) {
                $this->userLocale = $locale;
                \Locale::setDefault($locale);
                setlocale(LC_TIME, $locale . '.utf8');
                break;
            }
        }
    }

    /** Kennt ICU die Sprache des Kürzels (de_AT -> de, zh_CN -> zh)? */
    private function isKnownLanguage(string $locale): bool
    {
        static $languages = null;
        $language = strtolower((string)\Locale::getPrimaryLanguage(trim($locale)));
        if ($language === '' || !preg_match('/^[a-z]{2,3}$/', $language)) return false;
        if ($languages === null) {
            $languages = [];
            foreach (self::localeIdentifiers() as $known) $languages[(string)\Locale::getPrimaryLanguage($known)] = true;
        }
        return isset($languages[$language]);
    }

    /**
     * Sprache, in der ein Datensatz gelesen wird.
     *
     * Mit Sprachkontext (Frontend) die Sprache aus $languageMap für die Instanz des
     * Datensatzes, sonst die angeforderte Sprache. Ohne Sprachkontext (Backend) die
     * Hauptsprache der Instanz des Datensatzes.
     *
     * @param int $instanceId  `record`.`instance_id`
     * @return int             language_id
     */
    public function recordLanguageId(int $instanceId): int
    {
        if (isset($this->languageMap[$instanceId])) return $this->languageMap[$instanceId];
        if ($this->languageId !== null) return $this->languageId;
        return $this->instanceCache->primaryLanguageId($instanceId);
    }

    /**
     * SQL-Ausdruck für die `language_id`, mit der die data_*-Tabellen und
     * `record_cache` eines Datensatzes gejoint werden.
     *
     * Ergibt eine Zahl, wenn für alle Instanzen dieselbe Sprache gilt, sonst einen
     * CASE-Ausdruck über die `instance_id` des Datensatzes:
     *
     *     3
     *     CASE `record`.`instance_id` WHEN 1 THEN 3 WHEN 23 THEN 0 ELSE 3 END
     *
     * Der Ausdruck geht literal in den Query (nur Zahlen, keine Bind-Parameter) und
     * lässt sich damit auch in ON-Bedingungen und EXISTS-Unterabfragen einsetzen.
     *
     * @param string $recordAlias  Alias der `record`-Tabelle, deren instance_id zählt
     */
    public function languageExpr(string $recordAlias = 'record'): string
    {
        // Sprachkontext ohne Zuordnung je Instanz: die angeforderte Sprache überall
        if ($this->languageId !== null && empty($this->languageMap)) {
            return (string)$this->languageId;
        }
        $map = $this->languageMap;
        if (empty($map)) {
            // Kein Sprachkontext: Hauptsprache jeder Instanz
            $map = $this->instanceCache->primaryLanguages();
        }
        $default = $this->languageId ?? (int)(reset($map) ?: 0);

        $distinct = array_unique(array_values($map));
        if (count($distinct) <= 1 && (empty($distinct) || (int)reset($distinct) === $default)) {
            return (string)$default;
        }
        $sql = "CASE `{$recordAlias}`.`instance_id`";
        foreach ($map as $instanceId => $languageId) {
            $sql .= ' WHEN ' . (int)$instanceId . ' THEN ' . (int)$languageId;
        }
        return $sql . ' ELSE ' . $default . ' END';
    }

    /**
     * Kollation für Textvergleiche und -sortierung einer Abfrage auf `record`: die der
     * Sprache, in der gesucht wird (Camindo\Core\Collation, im Deutschen etwa
     * ä = ae und ß = ss).
     *
     * Mit Sprachkontext (Frontend) ist das die angeforderte Sprache. Ohne (Backend)
     * gilt je Instanz eine eigene Sprache ($languageMap); dann zählen die Instanzen
     * der Bereiche aus filter[area_id], ohne Bereichsfilter alle. Führen sie auf
     * verschiedene Kollationen, bleibt es bei der der Spalten - ein COLLATE gilt für
     * die ganze Abfrage.
     *
     * @param int[] $languageIds  searchLanguageIds()
     * @return string            Kollation; '' = die der Textspalten, kein COLLATE nötig
     */
    private function textCollation(array $languageIds): string
    {
        $collations = [];
        foreach ($languageIds as $languageId) $collations[$this->instanceCache->collation($languageId)] = true;
        return count($collations) === 1 ? (string)array_key_first($collations) : '';
    }

    /**
     * Sprachen, in denen eine Abfrage auf `record` sucht: mit Sprachkontext
     * (Frontend) die angeforderte, sonst (Backend) die Sprache jeder Instanz
     * ($languageMap) - der Instanzen der Bereiche aus filter[area_id], ohne
     * Bereichsfilter aller.
     *
     * @param array $p  Parameter von select() (ausgewertet: filter[area_id])
     * @return int[]    language_ids, ohne Doppelte
     */
    private function searchLanguageIds(array $p): array
    {
        if ($this->languageId !== null) return [$this->languageId];

        $instanceIds = [];
        foreach ($this->normalizeValues($p['filter']['area_id'] ?? []) as $value) {
            $value = ltrim($value, '=');
            if (ctype_digit($value)) $instanceIds[] = $this->instanceCache->areaInstanceId((int)$value);
        }
        $instanceIds = array_filter(array_unique($instanceIds));
        if (!$instanceIds) {
            $instanceIds = array_keys($this->languageMap ?: $this->instanceCache->primaryLanguages());
        }
        $languageIds = [];
        foreach ($instanceIds as $instanceId) $languageIds[$this->recordLanguageId((int)$instanceId)] = true;
        return array_keys($languageIds);
    }

    /** Spaltenausdruck mit der Kollation des laufenden filter() */
    private function collate(string $colExpr): string
    {
        return $this->filterCollation === '' ? $colExpr : "$colExpr COLLATE {$this->filterCollation}";
    }

    /**
     * Wird ein Feld mehrsprachig geführt?
     *
     * `field`.`multilingual` = 1 und ein Typ, der es kann: die Typen aus
     * $multilingualTypes sowie 'file' (Upload-Felder und ihre Thumbnails - Thumbnails
     * erben das Kennzeichen vom Upload-Feld, siehe Field::hookOnSave()).
     *
     * @param array $colDef  Felddefinition (virtualSchema())
     */
    public function isMultilingualField(array $colDef): bool
    {
        if (empty($colDef['multilingual'])) return false;
        $type = (string)($colDef['type'] ?? '');
        return $type === 'file' || in_array($type, $this->multilingualTypes, true);
    }

    /**
     * Dateiname einer Mediendatei ohne Endung, so wie sie unter media/{field_id}/ liegt.
     *
     *     {record_id}                  einsprachiges Datei-Feld
     *     {record_id}_{language_id}    mehrsprachiges Datei-Feld: die Datei DIESER Sprache
     *
     * `data_fil`.`file_language_id` sagt je Zeile, welche Datei gemeint ist: NULL bei
     * einsprachigen Feldern, sonst die Sprache der physischen Datei - bei einer
     * Sprache ohne eigene Datei die der Hauptsprache (Fallback).
     *
     * @param int      $recordId        Datensatz-Id (bzw. Id der Arbeitskopie)
     * @param int|null $fileLanguageId  null = einsprachig
     */
    public function mediaBaseName(int $recordId, ?int $fileLanguageId): string
    {
        return $fileLanguageId === null ? (string)$recordId : $recordId . '_' . $fileLanguageId;
    }

    /**
     * Dateiname einer Mediendatei mit Endung, siehe mediaBaseName().
     */
    public function mediaFileName(int $recordId, ?int $fileLanguageId, string $extension): string
    {
        return $this->mediaBaseName($recordId, $fileLanguageId) . '.' . $extension;
    }

    // MEDIEN-ABLAGE
    // --------------------
    // Die Dateien eines Upload-/Thumbnail-Feldes liegen unter
    //
    //     {paths.media_dir}/{instance_id}/{field_id}/{dateiname}
    //
    // Die instance_id ist die des Inhaltsbereichs, zu dem das Feld gehört
    // (`area`.`instance_id`) - dieselbe, die auch jeder Datensatz des Bereichs in
    // `record`.`instance_id` trägt. Das Instanz-Verzeichnis trägt eine .htaccess,
    // die den Zugriff auf die Domains der Instanz und ihrer lesenden Instanzen
    // beschränkt (Camindo\Core\MediaAccess); ein Feld mit 'user_auth' bekommt
    // darunter ein zweites .htaccess, das jeden direkten Zugriff sperrt - solche
    // Dateien liefert ausschließlich public/file.php aus.
    //
    // Wer einen Pfad zusammensetzt, geht IMMER über mediaFolder() / mediaPath() -
    // nur so wandert die Ablage bei einem Instanzwechsel des Bereichs
    // (Area::hookOnSaved()) an einer Stelle mit.

    /** instance_id je field_id, einmal je Request gelesen (siehe fieldInstanceId()) */
    private array $fieldInstanceCache = [];

    /**
     * Instanz, zu der ein Eingabefeld gehört (`area`.`instance_id` seines Bereichs).
     *
     * @param int $fieldId
     * @return int  instance_id; 0, wenn es das Feld nicht gibt
     */
    public function fieldInstanceId(int $fieldId): int
    {
        if ($fieldId < 1) return 0;
        if (isset($this->fieldInstanceCache[$fieldId])) return $this->fieldInstanceCache[$fieldId];
        $this->db->pushResult();
        $this->db->query(
            "SELECT `a`.`instance_id` FROM `field` `f`"
            . " INNER JOIN `area` `a` ON `a`.`area_id` = `f`.`area_id`"
            . " WHERE `f`.`field_id` = ?",
            'i',
            [$fieldId]
        );
        $instanceId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        $this->fieldInstanceCache[$fieldId] = $instanceId;
        return $instanceId;
    }

    /**
     * Zuordnung field_id -> instance_id vergessen - nach einem Instanzwechsel
     * eines Bereichs im selben Request (Area::hookOnSaved()).
     */
    public function clearFieldInstanceCache(): void
    {
        $this->fieldInstanceCache = [];
    }

    /** details.user_auth je field_id, einmal je Request gelesen */
    private array $fieldUserAuthCache = [];

    /**
     * Ist ein Datei-Feld als "nur für angemeldete User" markiert
     * (field.details.user_auth)? Dann verlinkt CMS:MEDIA auf file.php statt ins
     * Medienverzeichnis, und MediaAccess hält das Feld-Verzeichnis gesperrt.
     */
    public function isUserAuthField(int $fieldId): bool
    {
        if ($fieldId < 1) return false;
        if (isset($this->fieldUserAuthCache[$fieldId])) return $this->fieldUserAuthCache[$fieldId];
        $this->db->pushResult();
        $this->db->query("SELECT `details` FROM `field` WHERE `field_id` = ?", 'i', [$fieldId]);
        $details = json_decode((string)($this->db->fetchNum()[0] ?? ''), true) ?: [];
        $this->db->popResult();
        return $this->fieldUserAuthCache[$fieldId] = !empty($details['user_auth']);
    }

    /**
     * Verzeichnis eines Feldes ab paths.media_dir bzw. paths.media, ohne Slashes
     * an den Enden: "{instance_id}/{field_id}".
     *
     * @param int $fieldId
     * @param int $instanceId  Instanz, wenn bereits bekannt (spart die Abfrage);
     *                         0 = aus dem Bereich des Feldes ermitteln
     */
    public function mediaFolder(int $fieldId, int $instanceId = 0): string
    {
        if ($instanceId < 1) $instanceId = $this->fieldInstanceId($fieldId);
        return $instanceId . '/' . $fieldId;
    }

    /**
     * Pfad einer Mediendatei ab paths.media_dir bzw. paths.media:
     * "{instance_id}/{field_id}/{record_id}[_{language_id}].{ext}" (siehe
     * mediaFileName()).
     *
     * @param int      $fieldId
     * @param int      $recordId        Datensatz-Id (bzw. Id der Arbeitskopie)
     * @param int|null $fileLanguageId  null = einsprachig
     * @param string   $extension
     * @param int      $instanceId      Instanz, wenn bereits bekannt
     */
    public function mediaPath(int $fieldId, int $recordId, ?int $fileLanguageId, string $extension, int $instanceId = 0): string
    {
        return $this->mediaFolder($fieldId, $instanceId) . '/' . $this->mediaFileName($recordId, $fileLanguageId, $extension);
    }

    /**
     * Medienpfad mit sprechendem Namen für die URL: der ursprüngliche Dateiname
     * (ohne Endung, als Slug) wird mit Komma vor die Endung gesetzt -
     *
     *     1/12/1977.jpg  +  "Strand Nord.jpg"   ->  1/12/1977,strand-nord.jpg
     *
     * Die RewriteRule "CAMINDO MEDIA" in public/.htaccess entfernt ",{name}" wieder,
     * ohne ihn zu prüfen; die Datei selbst heißt weiterhin 1977.jpg. Ohne Namen
     * bleibt der Pfad unverändert.
     *
     * @param string $mediaPath  Pfad aus mediaPath()
     * @param string $filename   ursprünglicher Dateiname (data_fil.filename)
     * @param string $extension  Endung der Datei
     */
    public function mediaUriName(string $mediaPath, string $filename, string $extension): string
    {
        $name = trim($filename);
        if ($extension !== '' && strtolower(substr($name, -strlen($extension) - 1)) === '.' . strtolower($extension)) {
            $name = substr($name, 0, -strlen($extension) - 1);
        }
        $name = str_replace('/', '-', $this->textToUri($name, 100));
        if ($name === '') return $mediaPath;
        $dot = strrpos($mediaPath, '.');
        if ($dot === false) return $mediaPath;
        return substr($mediaPath, 0, $dot) . ',' . $name . substr($mediaPath, $dot);
    }

    /**
     * file_language_id aus einem Datei-Eintrag lesen (Cache / Arbeitsstand / data_fil-Zeile).
     * Fehlt der Schlüssel oder ist er leer, ist es eine einsprachige Datei.
     */
    public function fileLanguageId(array $fileData): ?int
    {
        $value = $fileData['file_language_id'] ?? null;
        return ($value === null || $value === '') ? null : (int)$value;
    }

    /**
     * SELECT
     * Holt Datensaetze entsprechend der Filter-Parameter, inkl. Trefferzaehlung
     * und Sortierung.
     *
     * Hinweis zu Unterabfragen:
     *      wird mit select() eine Unterabfrage gestartet - waehrend Ergebnisse
     *      abgearbeitet werden, koennen mit Database::pushResult() die vorherigen
     *      Ergebnisse gesichert und mit Database::popResult() wiederhergestellt
     *      werden.
     *
     * @param array|null $param        Array mit Filter- und Sortierkriterien
     *                                   'filter','filterop','filtermode','skip','limit','sort','order'
     *                                   'sql','src_id','dest_id','relation','nextof'
     *                                   (relation: Feldname oder 'any')
     * @param ?Dataset $tableObj       Dataset-Kindklasse, deren Tabelle durchsucht werden soll.
     *                                   Wenn nicht angegeben wird 'record' durchsucht.
     * @param array &$completedSchema  Schema des Bereichs angereichert um virtuelle Felder
     *                                   (nur Backend, um Label in den Filtertext einsetzen zu koennen)
     * @return array                   Rueckgabe Array mit Default-Sortierung, verwendeter
     *                                   Sortierung, Filterangaben und Sortierung in lesbarer Form
     */
    public function select(array $param = [], ?Dataset $tableObj = null, array &$completedSchema = []): array
    {
        $param = $param ?? [];
        if (isset($tableObj)) {
            // Schema aus uebergebener Dataset-Klasse ziehen
            $schema           = $tableObj->getSchema();
            $tableName        = $tableObj->getTableName();
            $defaultSortOrder = $tableObj->getDefaultSortOrder();
            // Fremd-Tabellen haben keine virtuellen Felder
            $this->hasVirtualFields = false;
        }
        else {
            $this->hasVirtualFields = true;
            // default: Schema von Tabelle 'record'
            $schema           = $completedSchema ?: $this->schema;
            $tableName        = 'record';
            $defaultSortOrder = 'changed_at DESC';
            // Backend: Parameter completedSchema enthaelt virtuelle Felder + Metafelder
        }
        $useVirtual = $this->hasVirtualFields;

        // $_REQUEST : Nur bekannte Schluessel auswerten,
        // 'sql' kann nicht per Request hinzugefuegt werden
        $requestKeys = (string)($param['request'] ?? '');
        if ($requestKeys === 'all') {
            $keys = $this->selectParams;
        }
        else {
            $keys = explode(',', $requestKeys);
        }
        // Parameter final in $p sammeln
        $p = [];
        foreach ($keys as $key) {
            $mainKey = '';
            if (preg_match('/^([a-z]+)(?:\[([a-z]+)\])?(\[\])?$/', trim($key), $matches)) {
                $mainKey = $matches[1] ?? '';
            }
            if (!in_array($mainKey, $this->selectParams)) {
                // ignore
                continue;
            }
            if (!isset($_REQUEST[$mainKey])) {
                continue;
            }
            $p[$mainKey] = $_REQUEST[$mainKey];
        }
        // Werte aus $param ueberschreiben die _REQUEST-Werte
        // (src_id/relation/sql kommen ausschliesslich aus $param)
        $p = array_replace_recursive($p, $param);
        // Typ-Absicherung gegen manipulierte Request-Parameter.
        // filter/filterop/filtermode muessen Arrays sein (z. B. ?filter=xyz wuerde
        // sonst spaeter zu foreach-/String-Offset-Fehlern fuehren), skip/limit
        // skalar, sort/order skalar oder Array. Fehlerhafte Typen werden
        // verworfen und registriert.
        foreach (['filter', 'filterop', 'filtermode'] as $k) {
            if (isset($p[$k]) && !is_array($p[$k])) {
                $this->error($this->snippet('error_param_invalid'), $k);
                unset($p[$k]);
            }
        }
        foreach (['skip', 'limit'] as $k) {
            if (isset($p[$k]) && !is_scalar($p[$k])) {
                $this->error($this->snippet('error_param_invalid'), $k);
                unset($p[$k]);
            }
        }
        foreach (['sort', 'order'] as $k) {
            if (isset($p[$k]) && !is_scalar($p[$k]) && !is_array($p[$k])) {
                $this->error($this->snippet('error_param_invalid'), $k);
                unset($p[$k]);
            }
        }
        // '_default'-Feldangabe bei Tabellen außer 'record': in Feldname übersetzen
        if ($tableName != 'record' && !empty($p['filter']['_default'])) {
            $defaultFilter = $p['filter']['_default'];
            if ($defaultFilter != '*') {
                $defaultFieldName = $tableObj->getDefaultFieldName();
                $p['filter'][$defaultFieldName] = $defaultFilter;
            }
        }

        // Pseudo-Feld 'area': Bereichsname -> area_id (Tabellen mit `area_id`,
        // nicht `area` selbst - dort wäre 'area' schlicht eine unbekannte Spalte)
        $hasAreaColumn = isset($schema['area_id']) && $tableName !== 'area';
        if ($hasAreaColumn && isset($p['filter']['area'])) {
            $this->translateAreaFilter($p);
        }

        // Filtertext nur erzeugen, wenn ausdruecklich angefordert
        $wantFilterText = ((string)($param['filtertext'] ?? '') === 'true');  // default: 'false'

        // 'filter', 'filterop', 'filtermode', 'src_id', 'dest_id', 'relation', 'sql'
        // --------------------
        // filter() liefert jetzt zusaetzlich den gemeinsamen JOIN-Kontext.
        $f       = $this->filter($p, $tableObj, $wantFilterText, $completedSchema);
        $joinCtx = $f['joinCtx'];

        // 'skip', 'limit'
        // --------------------
        $skip  = abs((int)($p['skip']  ?? 0));
        $limit = (int)($p['limit'] ?? 0); // keine abs() - alles < 1 bedeutet "unendlich"
        // skip ohne limit wird ignoriert (ein OFFSET ohne LIMIT wäre ein Syntaxfehler)
        if ($limit < 1) $skip = 0;

        $where = $f['where'];

        // Tabellen-qualifiziert (JOIN-sicher)
        if (isset($schema['tmp']))     $where .= " AND `$tableName`.`tmp`=0";
        if (isset($schema['deleted'])) $where .= " AND `$tableName`.`deleted`=0";

        // Zugriffsbeschränkung auf Instanzen (siehe $sqlAccess)
        // Gilt für JEDE Abfrage auf die drei Inhalts-Tabellen - auch für die des
        // Backends. Wer keinen Zugriff auf eine Website hat, findet ihre Inhalte
        // damit nirgends, ohne dass die einzelne Abfrage etwas dafür tun müsste.
        if ($this->sqlAccess !== '' && in_array($tableName, self::ACCESS_TABLES, true)) {
            $where .= " AND (" . $this->sqlAccess . ")";
        }
        // Zugriffsbeschränkung auf Bereiche (siehe $sqlAreaAccess) - Backend: die
        // lesbaren Bereiche der Benutzergruppe. Tabellenqualifiziert: `record` wird
        // mit data_*-Tabellen gejoint, die ebenfalls keine area_id führen, aber
        // `template` mit `area`.
        if ($this->sqlAreaAccess !== '' && in_array($tableName, self::AREA_ACCESS_TABLES, true)) {
            $where .= " AND (`$tableName`." . $this->sqlAreaAccess . ")";
        }

        $hasCount  = ((string)($param['count']  ?? '') === 'true');   // default: 'false'
        $hasResult = ((string)($param['result'] ?? '') !== 'false');  // default: 'true'
        $hasDebug  = (
               (string)($param['debug'] ?? '') === 'true'
            || (bool)($param['debug'] ?? false) === true
        );   // default: 'false'

        $this->db->num_rows_unlimited = 0;
        $this->db->num_rows = 0;
        // Seitengröße für CMS:JUMP_LIST/PREV/NEXT (Pagination; 0 = keine Blätterleiste) -
        // wandert mit push/popResult, gehört also zu genau diesem Ergebnis
        $this->db->num_rows_limit = max(0, $limit);

        if ($hasCount) {
            // Zaehlung durchfuehren
            // Hinweis: COUNT-Query enthaelt KEIN ORDER BY -> nur Filter-JOINs noetig.
            // DISTINCT-PK nur, wenn JOINs Mehrfachzeilen erzeugen koennten.
            $fj  = $this->emitJoins($joinCtx, ['filter']);
            $cnt = $joinCtx['distinct']
                 ? "COUNT(DISTINCT `$tableName`.`{$tableName}_id`)"
                 : "COUNT(*)";
            $sql = "SELECT $cnt FROM `$tableName`{$fj['sql']} WHERE 1 $where";
            if ($hasDebug) {
                echo $sql . "<br>";
                var_dump(array_merge($fj['values'], $f['values']));
            }
            // JOIN-Parameter zuerst (stehen im SQL vor WHERE), dann WHERE-Parameter
            $this->db->query(
                $sql,
                $fj['types'] . $f['types'],
                array_merge($fj['values'], $f['values'])
            );
            $this->db->num_rows_unlimited = $this->db->fetchNum()[0];
        }

        if (!$hasResult) {
            // keine Ergebnis-Zeilen liefern - num_rows stammt sonst noch von der
            // COUNT-Query (eine Zeile) und ein folgendes resulttemplate /
            // CMS:RESULT_START hielte das für einen Treffer
            $this->db->num_rows = 0;
            return [
                'filter_text'  => '',
                'sort_text'    => '',
                'default_sort' => '',
                'used_sort'    => '',
                'sort'         => '',
                'order'        => ''
            ];
        }

        // 'sort', 'order'
        // --------------------
        $sort  = $p['sort']  ?? '';
        $order = $p['order'] ?? '';

        $orderClauses = [];
        $usedClauses  = [];
        $returnSort   = [];
        $returnOrder  = [];
        $counter      = 0;

        // 'nextof' (siehe filter()): die Nachbarn sind nur in der Reihenfolge der
        // Verweisliste sinnvoll - Sortierung nach data_rel.position ('_custom') in
        // der angeforderten Richtung, eine andere Sortierangabe wird verworfen.
        // Die Gueltigkeit von src_id/relation hat filter() bereits gemeldet; hier
        // nur bei gueltiger Verweisliste eingreifen, sonst gaebe '_custom' einen
        // zweiten Fehler zum selben Anlass.
        if ((int)($p['nextof'] ?? 0) > 0 && $useVirtual
            && (int)($p['src_id'] ?? 0) > 0 && trim((string)($p['relation'] ?? '')) !== '') {
            $relField = $this->resolveVirtualField(trim((string)$p['relation']));
            if ($relField !== null && in_array($relField['type'], $this->relationTypes, true)) {
                $order = $this->nextSortDirection($p);
                $sort  = '_custom';
            }
        }

        // Wenn keine Sortierung vorhanden ist, dann Standardsortierung nutzen
        if (empty($sort)) $sort = $defaultSortOrder;

        $sortArray  = !is_array($sort)  ? explode(',', (string)$sort)  : $sort;
        $orderArray = !is_array($order) ? explode(',', (string)$order) : $order;

        $orderTextParts = [];

        foreach ($sortArray as $index => $sortItem) {
            // Verschachtelte Request-Arrays (z. B. sort[0][]=x) ignorieren -
            // (string)-Cast auf Arrays haette "Array" als Spaltenname erzeugt.
            if (!is_scalar($sortItem)) {
                $this->error($this->snippet('error_param_invalid'), 'sort');
                continue;
            }
            $sortItem = trim((string) $sortItem);
            if ($sortItem === '') {
                continue;
            }
            $sortItem = preg_replace('/\s+/', ' ', $sortItem);
            $parts    = explode(' ', $sortItem);

            $field     = $parts[0];
            $direction = 'ASC';
            // merken, ob die Richtung explizit angegeben wurde
            // (Relations-Sortierung ohne Subfeld nutzt sonst Default DESC)
            $hasExplicitDir = false;
            if (count($parts) > 1) {
                $direction = strtoupper(trim($parts[1]));
                $hasExplicitDir = true;
            } elseif (isset($orderArray[$index]) && is_scalar($orderArray[$index])
                && trim((string) $orderArray[$index]) !== '') {
                $direction = strtoupper(trim((string) $orderArray[$index]));
                $hasExplicitDir = true;
            }
            $direction  = ($direction === 'DESC') ? 'DESC' : 'ASC';

            $fieldClean = preg_replace('/[^a-zA-Z0-9_.-]/', '', $field);
            if (empty($fieldClean)) {
                continue;
            }

            // '_custom' Sortierung nach data_rel.position
            if ($fieldClean === '_custom') {
                $srcId   = (int)($p['src_id'] ?? 0);
                $relName = trim((string)($p['relation'] ?? ''));

                if ($srcId > 0 && $relName !== '' && $useVirtual) {
                    $relField = $this->resolveVirtualField($relName);
                    
                    if ($relField !== null && in_array($relField['type'], $this->relationTypes, true)) {
                        $cacheKey = 'rel_sort_custom';
                        
                        if (isset($joinCtx['map'][$cacheKey])) {
                            $alias = $joinCtx['map'][$cacheKey];
                        } else {
                            $alias = '_rel_sort';
                            // LEFT JOIN explizit für die Sortierphase registrieren
                            // (bewusst KEIN INNER JOIN: das Ergebnis darf nicht auf
                            // verknuepfte Datensaetze reduziert werden, falls der
                            // src_id/relation-Filter nicht gesetzt ist)
                            $joinCtx['joins'][$alias] = [
                                'sql'    => "LEFT JOIN `data_rel` AS `$alias`"
                                          . " ON `$alias`.`dest_id` = `$tableName`.`{$tableName}_id`"
                                          . " AND `$alias`.`src_id` = ?"
                                          . " AND `$alias`.`fieldname_id` = ?",
                                'types'  => 'ii',
                                'values' => [$srcId, $relField['fieldname_id']],
                                'phase'  => 'sort',
                            ];
                            $joinCtx['map'][$cacheKey] = $alias;
                        }
                        
                        $sortExpr = "`$alias`.`position`";
                    } else {
                        // Relation unbekannt -> Sortierung ignorieren
                        $this->error($this->snippet('error_sort_field_invalid'), $relName !== '' ? $relName : $fieldClean);
                        continue;
                    }
                } else {
                    // Benötigte Parameter fehlen -> Sortierung ignorieren
                    $this->error($this->snippet('error_sort_field_invalid'), $fieldClean);
                    continue;
                }
            } elseif ($fieldClean === 'area' && $hasAreaColumn) {
                // Pseudo-Feld 'area': nach dem NAMEN des Bereichs sortieren, ohne
                // JOIN auf `area` - die Ids kommen nach Namen sortiert aus dem
                // Instanz-Dateicache, FIELD() bildet daraus die Rangfolge. Ids, die
                // dort nicht stehen, liefern 0 und landen bei ASC vorn.
                $areaIds = $this->areaIdsByName();
                $sortExpr = empty($areaIds)
                    ? "`$tableName`.`area_id`"
                    : "FIELD(`$tableName`.`area_id`, " . implode(',', $areaIds) . ")";
            } elseif ($fieldClean === 'instance' && !$useVirtual && isset($schema['instance_id'])) {
                // Pseudo-Feld 'instance' (nur Fremdtabellen mit instance_id: area,
                // template - in `record` könnte ein virtuelles Feld so heißen): in der
                // Reihenfolge der Instanzen im Backend (`instance`.`position`,
                // Standard-Instance zuletzt) - aus dem Dateicache, wie bei 'area'.
                // Damit gruppiert die Bereichsliste ihre Zeilen nach Mandant
                // (AreaBackend::list()).
                $instanceIds = array_keys($this->instanceCache->instanceLabels());
                $sortExpr = empty($instanceIds)
                    ? "`$tableName`.`instance_id`"
                    : "FIELD(`$tableName`.`instance_id`, " . implode(',', $instanceIds) . ")";
            } elseif ($fieldClean === '_default') {
                if ($useVirtual) {
                // Spezialbehandlung für '_default' (direkter JOIN auf data_dft),
                // sortiert nach den Regeln der Sprache wie der Filter
                $sortExpr = $this->collate($this->registerDefaultJoin($tableName, $joinCtx, 'sort'));
                } else {
                    // Fremdtabelle - '_default' in konfiguriertes Default-Feld
                    // uebersetzen (analog zur Filter-Uebersetzung weiter oben).
                    $fieldClean = $tableObj->getDefaultFieldName();
                    if (!isset($schema[$fieldClean])) {
                        $this->error($this->snippet('error_sort_field_unknown'), $fieldClean);
                        continue;
                    }
                    $sortExpr = "`$tableName`.`$fieldClean`";
                }
            } else {
                if (!$useVirtual) {
                    // Fremdtabellen - nur Spalten aus dem Schema zulassen.
                    if (!isset($schema[$fieldClean])) {
                        $this->error($this->snippet('error_sort_field_unknown'), $fieldClean);
                        continue;
                    }
                    // 'reltable' steht zwar im Schema, hat aber keine Spalte
                    // (Inhalt liegt in einer eigenen Relation-Tabelle)
                    if (in_array($schema[$fieldClean]['type'] ?? '', $this->noColumnTypes, true)) {
                        $this->error($this->snippet('error_sort_field_invalid'), $fieldClean);
                        continue;
                    }
                    $sortExpr = "`$tableName`.`$fieldClean`";
            } else {
                // Standard: Spalten-/Metafeldname mit Tabellenqualifier (JOIN-sicher)
                $sortExpr = "`$tableName`.`$fieldClean`";
                    // Virtuelles Feld -> JOIN bzw. Subquery (gemeinsamer Kontext)
                $isMeta = isset($this->schema[$fieldClean]);
                    if (!$isMeta) {
                        // Punkt-Notation zulassen (hauptfeld.subfeld, max. EINE Ebene)
                        $mainF = $fieldClean;
                        $subF  = '';
                        if (($dotPos = strpos($fieldClean, '.')) !== false) {
                            $mainF = substr($fieldClean, 0, $dotPos);
                            $subF  = substr($fieldClean, $dotPos + 1);
                            // mehr als eine Ebene (relation.relation.feld) nicht erlaubt
                            if ($subF === '' || strpos($subF, '.') !== false) {
                                $this->error($this->snippet('error_sort_field_invalid'), $fieldClean);
                        continue;
                    }
                        }
                        $vf = $this->resolveVirtualField($mainF);
                    if ($vf === null) {
                            // unbekanntes Sortierfeld -> ignorieren (kein harter Abbruch),
                            $this->error($this->snippet('error_sort_field_unknown'), $mainF);
                        continue;
                    }
                        if (in_array($vf['type'], $this->relationTypes, true)) {
                            // Sortierung nach Feldern des VERKNUEPFTEN Datensatzes
                            // (Meta- oder virtuelles Skalarfeld). Ohne Subfeld wird
                            // nach dessen Metafeld 'changed_at' sortiert - Default-
                            // Richtung dann DESC, sofern nicht explizit angegeben.
                            // Nur n:1-Typen ('relation1'/'pointer1') sind sortierbar,
                            // da der LEFT JOIN bei n:m Mehrfachzeilen erzeugen wuerde.
                            // Die Typ-Pruefung ersetzt die fruehere Datenbankabfrage
                            // auf `field`.`max` (Typ kommt aus resolveVirtualField()).
                            if (!in_array($vf['type'], $this->singleRelationTypes, true)) {
                                // n:m -> Sortierung ignorieren
                                $this->error($this->snippet('error_sort_field_invalid'), $fieldClean);
                                continue;
                            }
                            // Default-Subfeld VOR dem JOIN aufloesen, damit
                            // used_sort/sort die TATSAECHLICH verwendete Sortierung
                            // widerspiegeln ("myrelation" -> "myrelation.changed_at DESC").
                            if ($subF === '') {
                                $subF = 'changed_at';
                                if (!$hasExplicitDir) $direction = 'DESC';
                            }
                            $sortExpr = $this->registerRelationJoin(
                                $tableName, $vf['fieldname_id'], $joinCtx, $subF, $fieldClean, 'sort'
                            );
                            if ($sortExpr === null) continue; // Fehler bereits registriert
                            // Feldnamen auf aufgeloeste Form normalisieren
                            // (fliesst in used_sort, sort[] und den Roundtrip zurueck)
                            $fieldClean = $mainF . '.' . $subF;
                        }
                        elseif ($vf['type'] === 'file') {
                            // Sortierung nach Datei-Metadaten (data_fil).
                            // Ohne Subfeld wird nach 'filename' sortiert.
                            // Default-Subfeld vor dem JOIN aufloesen (used_sort)
                            if ($subF === '') $subF = 'filename';
                            $sortExpr = $this->registerFileSortJoin(
                                $tableName, $vf['fieldname_id'], $joinCtx, $subF, $fieldClean
                            );
                            if ($sortExpr === null) continue; // Fehler bereits registriert
                            $fieldClean = $mainF . '.' . $subF;
                        }
                        elseif ($subF !== '') {
                            // Subfeld-Notation ist nur fuer Relation/Datei sinnvoll
                            $this->error($this->snippet('error_sort_field_invalid'), $fieldClean);
                            continue;
                        }
                        else {
                            // Skalarfeld - inkl. json (Sortierung nach rohem String)
                            // und fulltext. Wiederverwendung eines evtl. bereits vom
                            // Filter gebauten JOINs, sonst neuer JOIN mit Phase 'sort'
                            // (LEFT JOIN, siehe registerScalarJoin).
                    $sortExpr = $this->registerScalarJoin($tableName, $vf, $joinCtx, $fieldClean, 'sort');
                    // Textfelder nach den Regeln der Sprache (Kollation aus filter())
                    if ($vf['type'] === 'text' || $vf['type'] === 'chars') $sortExpr = $this->collate($sortExpr);
                }
            }
                }
            }
            $orderClauses[] = "$sortExpr $direction";
            $usedClauses[]  = "$fieldClean $direction";
            $returnSort[$counter]  = $fieldClean;
            $returnOrder[$counter] = $direction;
            // Sortiertext (Labels) nur bei Bedarf - im Frontend sonst unnötige
            // Snippet-Zugriffe (record.field_*, record.file_*)
            if (!$wantFilterText) {
                $counter++;
                continue;
            }
            if (($lblDot = strpos($fieldClean, '.')) !== false) {
                $lblMain = substr($fieldClean, 0, $lblDot);
                $lblSub  = substr($fieldClean, $lblDot + 1);
                
                // Label des Hauptfeldes ermitteln ("Meine Datei")
                $mainLabel = $this->textOrSnippet($schema[$lblMain]['label'] ?? $lblMain);
                
                // Typ des Hauptfeldes aus dem Cache abfragen, um das Subfeld korrekt zu übersetzen
                $vf = $useVirtual ? $this->resolveVirtualField($lblMain) : null;
                
                if ($vf !== null && $vf['type'] === 'file') {
                    // File-Feld: Subfeld-Label aus record.file_<subfeld> holen (z. B. "record.file_width" -> "Breite")
                    $subLabel = $this->snippet("record.file_{$lblSub}");
                } elseif (isset($this->schema[$lblSub]['label'])) {
                    // Relations-Feld mit Ziel-Metafeld (z. B. category.changed_at)
                    $subLabel = $this->textOrSnippet($this->schema[$lblSub]['label']);
                } else {
                    // Fallback für sonstige/unbekannte Subfelder
                    $subLabel = $this->textOrSnippet($lblSub);
                }
                
                $lblText = $mainLabel . '.' . $subLabel;
            } else {
                // (Pseudo-Feld 'area' trägt das Label von area_id, 'instance' das von instance_id)
                $lblKey  = ($fieldClean === 'area' && $hasAreaColumn) ? 'area_id' : $fieldClean;
                if ($fieldClean === 'instance' && !$useVirtual && isset($schema['instance_id'])) $lblKey = 'instance_id';
                $lblText = $this->textOrSnippet($schema[$lblKey]['label'] ?? $fieldClean);
            }
            $orderTextParts[] = $lblText
                . ' ' . $this->snippet('label_sort_' . strtolower($direction));

            $counter++;
        }

        $sortText    = '';
        $orderClause = implode(', ', $orderClauses);
        $usedClause  = implode(', ', $usedClauses);

        if (!empty($orderClauses)) {
            $where .= " ORDER BY " . $orderClause;
            // Sortiertext ebenfalls nur bei Bedarf
            if ($wantFilterText) {
                $sortText = implode(', ', $orderTextParts);
            }
        }

        if ($limit > 0) $where .= " LIMIT {$limit}";
        if ($skip  > 0) $where .= " OFFSET {$skip}";

        // Eigentliches Select; JOINs (Filter + Sort) stehen vor WHERE.
        $aj   = $this->emitJoins($joinCtx, ['filter', 'sort']);
        $dist = $joinCtx['distinct'] ? 'DISTINCT ' : '';
        $cols = "`$tableName`.*";
        $cacheJoin = '';
        if ($tableName === 'record') {
            // Inhalts-Cache der Sprache als Spalte `cache` dazunehmen - fetch() liest
            // daraus die virtuellen Felder (siehe Klassenkommentar, Inhaltssprache).
            // LEFT JOIN: fehlt die Zeile, baut fetch() sie neu auf.
            $cols .= ", `_rc`.`cache` AS `cache`";
            $cacheJoin = " LEFT JOIN `record_cache` AS `_rc`"
                       . " ON `_rc`.`record_id` = `record`.`record_id`"
                       . " AND `_rc`.`language_id` = " . $this->languageExpr('record');
        }
        $sql  = "SELECT {$dist}{$cols}"
              . " FROM `$tableName`{$aj['sql']}{$cacheJoin}"
              . " WHERE 1 $where";
        if ($hasDebug) {
            echo $sql . "<br>";
            var_dump(array_merge($aj['values'], $f['values']));
        }
        // JOIN-Parameter zuerst, dann WHERE-Parameter
        $this->db->query(
            $sql,
            $aj['types'] . $f['types'],
            array_merge($aj['values'], $f['values'])
        );

        return [
            'filter_text'  => $f['text'] ?? '',
            'sort_text'    => $sortText,
            'default_sort' => $defaultSortOrder,
            'used_sort'    => $usedClause,
            'sort'         => $returnSort,
            'order'        => $returnOrder
        ];
    }

    /**
     * Inhaltsbereiche, die dieser Request sehen darf: area_id => name.
     *
     * Aus dem Instanz-Dateicache (InstanceCache::areasOf()) - die eigene Instanz und
     * ihre lesbaren Instanzen (setInstanceAccess()), ohne Beschränkung (Backend) die
     * Bereiche aller Instanzen. Innerhalb dieses Zugriffsbereichs ist ein Name
     * eindeutig (Flag 'unique' auf `area`.`name`, InstanceCache::uniqueScope()); nur
     * ohne Beschränkung kann derselbe Name in mehreren Instanzen vorkommen.
     *
     * @return array  area_id => name, Instanzen in Zugriffsreihenfolge, darin
     *                Baumreihenfolge
     */
    public function areaMap(): array
    {
        return $this->instanceCache->areasOf($this->accessInstanceIds);
    }

    /**
     * Ids der Inhaltsbereiche zu einem Namen (siehe areaMap()).
     *
     * @param string $name  Bereichsname; eine Zahl gilt als area_id
     * @return int[]        leer, wenn kein Bereich so heißt
     */
    public function areaIds(string $name): array
    {
        $name = trim($name);
        if ($name === '') return [];
        if (ctype_digit($name)) return [(int)$name];
        // Namen sind Kleinbuchstaben (Flag 'name'), die Spalte vergleicht ohne
        // Rücksicht auf Groß-/Kleinschreibung - der Cache soll sich gleich verhalten
        return array_keys($this->areaMap(), mb_strtolower($name), true);
    }

    /**
     * Templates zu einer Referenz "[bereichsname.]templatename" suchen.
     *
     * Templatenamen sind nur innerhalb ihres Bereichs eindeutig (Flag
     * 'unique_in_area'). Ohne Bereichsanteil kommen deshalb alle Templates dieses
     * Namens zurück, die der Request sehen darf ($sqlAccess) - der Aufrufer
     * entscheidet, was er mit mehreren Treffern macht (templateFile(): Exception;
     * TemplateBackend::info(): Bereich des verweisenden Templates bevorzugen).
     *
     * Das Datenbank-Ergebnis des Aufrufers bleibt erhalten (pushResult) - CMS:SELECT
     * fragt das resulttemplate ab, nachdem die Treffer bereits im Ergebnis stehen.
     *
     * @param string $ref  "[bereich.]name"; wird auf [a-z0-9_] je Teil bereinigt
     * @return array       Zeilen template_id, area_id, name, standalone, type, instance_id
     *                     (in Baumreihenfolge der Bereiche); leer = nichts gefunden
     */
    public function findTemplates(string $ref): array
    {
        [$areaName, $name] = $this->splitTemplateRef($ref);
        if ($name === '') return [];

        $sql = "SELECT `t`.`template_id`, `t`.`area_id`, `t`.`name`, `t`.`standalone`, `t`.`type`, `t`.`instance_id`"
            . " FROM `template` `t`"
            . " INNER JOIN `area` `a` ON `a`.`area_id` = `t`.`area_id` AND `a`.`deleted` = 0"
            . " WHERE `t`.`name` = ?";
        $types = 's';
        $values = [$name];
        if ($areaName !== '') {
            $sql .= " AND `a`.`name` = ?";
            $types .= 's';
            $values[] = $areaName;
        }
        if ($this->sqlAccess !== '') {
            $sql .= " AND (`t`." . $this->sqlAccess . ")";
        }
        $sql .= " ORDER BY `a`.`lft` ASC";

        $this->db->pushResult();
        $this->db->query($sql, $types, $values);
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        return $rows;
    }

    /**
     * Pfad der kompilierten Datei zu einer Template-Referenz, relativ zu
     * $templatePath und ohne ".php": "{area_id}/{name}" (Template::fileName()).
     *
     * Die Auflösung über die Datenbank läuft je Referenz nur einmal pro Request.
     * Ein Name, der ohne Bereichsangabe in mehreren Bereichen vorkommt, ist ein
     * Fehler des Templates - die Abarbeitung bricht ab, statt zufällig eines davon
     * einzubinden.
     *
     * @param string $ref  "[bereich.]name"; '' ergibt '' (kein Template)
     * @return string
     * @throws \RuntimeException  Template nicht gefunden oder nicht eindeutig
     */
    public function templateFile(string $ref): string
    {
        [$areaName, $name] = $this->splitTemplateRef($ref);
        if ($name === '') return '';
        $key = ($areaName !== '') ? $areaName . '.' . $name : $name;
        if (isset($this->templateFiles[$key])) return $this->templateFiles[$key];

        $rows = $this->findTemplates($key);
        if (count($rows) > 1) {
            throw new \RuntimeException("Error: template name \"$key\" is ambiguous.");
        }
        if (count($rows) < 1) {
            throw new \RuntimeException("Error: template \"$key\" not found.");
        }
        $file = (int)$rows[0]['area_id'] . '/' . $rows[0]['name'];
        $this->templateFiles[$key] = $file;
        return $file;
    }

    /**
     * Template-Referenz in Bereichs- und Templatenamen zerlegen, beide auf
     * [a-z0-9_] bereinigt (wie _name()).
     *
     * @return array  [bereichsname, templatename] - Bereichsname '' ohne Angabe
     */
    private function splitTemplateRef(string $ref): array
    {
        $parts = explode('.', trim($ref), 2);
        $clean = fn(string $s): string => preg_replace('/[^a-z0-9_]/i', '', $s);
        if (count($parts) === 2) {
            return [mb_strtolower($clean($parts[0])), mb_strtolower($clean($parts[1]))];
        }
        return ['', mb_strtolower($clean($parts[0]))];
    }

    /**
     * Ids der sichtbaren Inhaltsbereiche, nach Namen sortiert - für ORDER BY FIELD()
     * in select() (sort=area).
     *
     * @return int[]
     */
    private function areaIdsByName(): array
    {
        $map = $this->areaMap();
        asort($map, SORT_STRING);
        return array_keys($map);
    }

    /**
     * Pseudo-Filterfeld 'area' in 'area_id' übersetzen (select()).
     *
     * filter[area] nimmt Bereichsnamen und/oder Ids, kommasepariert oder als Array,
     * mit den Operatoren =, ==, ! bzw. != und !! (Inline-Ausdrücke "a OR b" und
     * Vergleichsoperatoren gibt es für Namen nicht). filterop[area] und
     * filtermode[area] wandern mit. Die übersetzten Werte werden an einen evtl.
     * vorhandenen filter[area_id] angehängt, danach läuft alles den normalen Weg
     * eines Metafeldes (buildMetaGroup()).
     *
     *     filter[area]=artikel            ->  filter[area_id]=50
     *     filter[area]=artikel,topic      ->  filter[area_id]=50,56    (OR)
     *     filter[area]=!artikel           ->  filter[area_id]=!50
     *     filter[area]=57                 ->  filter[area_id]=57       (Zahl = Id)
     *
     * Ein unbekannter Name wird als Fehler registriert; positiv gesucht liefert er
     * NICHTS (area_id 0 gibt es nicht) statt alles, verneint schränkt er nichts ein.
     * Kommt ein Name ohne Instanzbeschränkung in mehreren Instanzen vor, zählen alle
     * seine Ids; lauter verneinte Werte werden dann mit AND verknüpft, sonst wäre
     * "!a OR !b" immer wahr.
     *
     * @param array &$p  Parameter von select(), 'filter' ist ein Array
     */
    private function translateAreaFilter(array &$p): void
    {
        $raw = $p['filter']['area'];
        unset($p['filter']['area']);

        $optList = $this->parseOptList((array)($p['filterop'] ?? []), 'area');
        if (isset($p['filterop']['area'])) unset($p['filterop']['area']);
        if (isset($p['filtermode']['area'])) {
            $p['filtermode']['area_id'] ??= $p['filtermode']['area'];
            unset($p['filtermode']['area']);
        }

        $label = $this->textOrSnippet($this->schema['area_id']['label'] ?? 'area');
        static $knownOps = ['=', '==', '!', '!=', '!!'];

        $values      = [];
        $allNegative = true;
        foreach ($this->normalizeValues($raw) as $idx => $val) {
            if (preg_match('/\s+(?:OR|AND)\s+/i', $val)) {
                $this->error($this->snippet('error_filter_value_invalid'), 'area', $label);
                continue;
            }
            if (isset($optList[$idx]) && $optList[$idx] !== '') {
                if (in_array($optList[$idx], $knownOps, true)) {
                    $val = $optList[$idx] . $val;
                } else {
                    $this->error($this->snippet('error_filter_op_invalid'), 'area', $label);
                }
            }
            [$op, $name] = $this->extractOperator($val);
            if (!in_array($op, ['=', '==', '!', '!!'], true)) {
                $this->error($this->snippet('error_filter_op_invalid'), 'area', $label);
                continue;
            }
            $negative = ($op === '!' || $op === '!!');
            $ids = $this->areaIds($name);
            if (empty($ids)) {
                $this->error($this->snippet('error_filter_value_invalid'), 'area', $label);
                if ($negative) continue;
                $ids = [0];
            }
            $prefix = ($op === '=') ? '' : $op;
            foreach ($ids as $id) $values[] = $prefix . $id;
            if (!$negative) $allNegative = false;
        }
        if (empty($values)) return;

        $existing = isset($p['filter']['area_id']) ? $this->normalizeValues($p['filter']['area_id']) : [];
        if (empty($existing) && $allNegative && count($values) > 1 && !isset($p['filtermode']['area_id'])) {
            $p['filtermode']['area_id'] = 'AND';
        }
        $p['filter']['area_id'] = array_merge($existing, $values);
    }

    /**
     * FILTER
     * Bereitet das WHERE-Statement vor und sammelt im JOIN-Kontext die
     * JOINs der virtuellen Skalarfelder. Wird ausschliesslich von
     * select() aufgerufen.
     *
     * @param array|null $param   Filter-/Sortierkriterien
     *                            Ausgewertete keys: filter, filterop, filtermode,
     *                            src_id, relation, nextof, sql (String oder Liste),
     *                            sort/order (nur fuer die Richtung von nextof)
     * @param ?Dataset $tableObj  Dataset-Kindklasse
     * @param bool $wantFilterText true: lesbaren Filtertext erzeugen
     * @return array [
     *      'where'   Ganze Where-Klausel, exklusiv vorangehend erwartetem "WHERE 1"
     *      'types'   mysqli stmt Bind-Typen (NUR WHERE-Parameter)
     *      'values'  mysqli stmt Werte      (NUR WHERE-Parameter)
     *      'text'    Filterkriterien in lesbarer Form (leer, wenn !$wantFilterText)
     *      'joinCtx' gemeinsamer JOIN-Kontext (siehe newJoinCtx())
     *      ]
     */
    protected function filter(?array $param = null, ?Dataset $tableObj = null, bool $wantFilterText = true, &$completedSchema = []): array
    {
        $param   = $param ?? [];
        $req     = $param['filter']     ?? [];
        $optReq  = $param['filterop']   ?? [];   // SQL-Vergleichsoperatoren je Feld (kommasepariert, index-synchron)
        $modeReq = $param['filtermode'] ?? [];   // Verknuepfung gleichnamiger Feldwerte: 'AND'/'OR' (Standard: 'OR')
        // Defense-in-depth - select() prueft die Typen bereits, aber
        // filter() soll auch bei direkter Nutzung nicht auf Strings iterieren.
        if (!is_array($req))     $req     = [];
        if (!is_array($optReq))  $optReq  = [];
        if (!is_array($modeReq)) $modeReq = [];
        if (isset($tableObj)) {
            $schema    = $tableObj->getSchema();
            $tableName = $tableObj->getTableName();
        } else {
            $schema    = empty($completedSchema) ? $this->schema : $completedSchema;
            $tableName = 'record';
        }
        $useVirtual = $this->hasVirtualFields;
        // Textvergleiche und Volltext nach den Regeln der Sprache - nur für Inhalte (`record`)
        $this->filterLanguageIds = $useVirtual ? $this->searchLanguageIds($param) : [];
        $this->filterCollation = $this->textCollation($this->filterLanguageIds);

        // Gemeinsamer JOIN-Kontext (von Filter UND Sortierung genutzt)
        $joinCtx = $this->newJoinCtx();

        $returnEmpty = [
            'where'   => '',
            'types'   => '',
            'values'  => [],
            'text'    => '',
            'joinCtx' => $joinCtx,
        ];

        $whereParts      = [];
        $bindTypes       = '';
        $values          = [];
        $filterTextParts = [];

        // Text-Snippets vorladen
        $strAnd = $this->snippet('filter_and');
        $strOr  = $this->snippet('filter_or');
        $strNot = $this->snippet('filter_not');

        $hasParentId = isset($schema['parent_id']);
        // Baeume sind bereichsgebunden (siehe TreeManagerTrait): lft/rgt beginnen in
        // jedem Inhaltsbereich wieder bei 1, ein Teilbaum-Filter muss deshalb den
        // Bereich mitfiltern.
        $hasAreaId   = isset($schema['area_id']) && $tableName !== 'area';

        // Relative Tiefenbeschraenkung fuer parent_id-Filter vorab auslesen.
        // < 0 (oder nicht gesetzt) → alle Nachkommen; 0 → keine Kinder; 1 → direkte Kinder; n → bis Tiefe n
        // (int)-Cast auf Arrays vermeiden (depth[]=1)
        $depthLimit = (isset($req['depth']) && is_scalar($req['depth'])) ? (int)$req['depth'] : -1;
        // depth=0 bedeutet: keine Kinder gewuenscht → leeres Ergebnis erzwingen
        if ($depthLimit === 0 && isset($req['parent_id'])) {
            return [
                'where'   => 'AND 1=0',
                'types'   => '',
                'values'  => [],
                'text'    => '',
                'joinCtx' => $joinCtx,
            ];
        }

        // Felder nach HAUPT-Feld gruppieren (siehe Original).
        $groups = [];
        foreach ($req as $fieldKey => $rawValue) {
            $fieldKey = (string)$fieldKey;
            // depth ist kein eigenstaendiges Filterfeld, sondern Modifier fuer parent_id
            if ($fieldKey === 'depth') continue;

            // relation_any: irgendein Verweisfeld zeigt auf einen der Datensätze
            // (wie relation=any + dest_id, aber als Filterwert, auch mehrere Ids)
            if ($fieldKey === 'relation_any' && $useVirtual && !isset($schema['relation_any'])) {
                $ids = array_values(array_filter(array_map('intval', $this->normalizeValues($rawValue)), fn($id) => $id > 0));
                if (!$ids) {
                    $this->error($this->snippet('error_filter_value_invalid'), $fieldKey);
                    $whereParts[] = '0 = 1';
                    continue;
                }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $whereParts[] = "EXISTS (SELECT 1 FROM `data_rel` `_rany`"
                              . " WHERE `_rany`.`src_id` = `$tableName`.`{$tableName}_id`"
                              . " AND `_rany`.`dest_id` IN ($placeholders))";
                $bindTypes .= str_repeat('i', count($ids));
                array_push($values, ...$ids);
                $filterTextParts[] = $this->snippet('filter_refers_to') . ' #' . implode(', #', $ids);
                continue;
            }

            // nested set mit adjacency list
            if ($fieldKey === 'parent_id' && $hasParentId) {
                $normalizedValues = $this->normalizeValues($rawValue);
                $parentCondition  = $this->buildParentCondition($tableName, $normalizedValues, $depthLimit, $hasAreaId);
                if ($parentCondition !== null) {
                    $whereParts[]      = $parentCondition['sql'];
                    $bindTypes        .= $parentCondition['types'];
                    array_push($values, ...$parentCondition['values']);
                    $filterTextParts[] = $parentCondition['text'];
                }
                continue; // normale Feldverarbeitung ueberspringen
            }

            // In Hauptfeld + Subfeld zerlegen (max. eine Ebene) - NUR bei virtuellen Feldern.
            if ($useVirtual && ($dotPos = strpos($fieldKey, '.')) !== false) {
                $main = substr($fieldKey, 0, $dotPos);
                $sub  = substr($fieldKey, $dotPos + 1);
            } else {
                $main = $fieldKey;
                $sub  = '';
            }

            $groups[$main][] = [
                'key' => $fieldKey,
                'sub' => $sub,
                'raw' => $rawValue,
            ];
        }

        // ----------------------------------------------------------------
        // Gruppen verarbeiten
        // ----------------------------------------------------------------
        foreach ($groups as $main => $entries) {
            $main = (string)$main;
            // 'reltable': Feld ohne eigene Spalte - der Inhalt liegt in einer eigenen
            // Relation-Tabelle, die select() nicht kennt. Nicht durchsuchbar.
            if (in_array($schema[$main]['type'] ?? '', $this->noColumnTypes, true)) {
                $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$main]), $main);
                continue;
            }
            // Metafeld (im Schema, ohne Subfeld)
            $isMeta = (bool)($schema[$main]['meta'] ?? false);
            if ($isMeta && count($entries) === 1 && $entries[0]['sub'] === '') {
                $res = $this->buildMetaGroup(
                    $tableName, $main, $entries[0]['raw'], $schema[$main],
                    $modeReq, $optReq, $strAnd, $strOr, $strNot
                );
                if ($res !== null) {
                    $whereParts[]      = $res[0];
                    $bindTypes        .= $res[1];
                    array_push($values, ...$res[2]);
                    $filterTextParts[] = $res[3];
                }
                continue;
            }

            // Tabelle != 'record'? keine virtuellen Felder
            if (!$useVirtual) {
                // unbekanntes Filterfeld bei Fremdtabellen registrieren
                $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$main]), $main);
                continue;
            }

            // _default
            if ($main === '_default') {
                $fieldLabel = $this->snippet('record.field_default');
                
                // filtermode: per HAUPT-Feld (Default OR)
                $mode = $this->resolveFilterMode($modeReq, $main);
                // Ein Skalarfeld darf KEIN Subfeld haben.
                if (count($entries) !== 1 || $entries[0]['sub'] !== '') {
                    $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$entries[0]['key']]), $entries[0]['key']);
                    continue;
                }
                // JOIN über die Default-Methode holen; Filter-Phase erzeugt
                // jetzt einen INNER JOIN. Merken, ob der JOIN neu angelegt wurde, um
                // ihn bei leerer Bedingungsgruppe wieder zu entfernen (sonst wuerde
                // der INNER JOIN das Ergebnis ohne Bedingung einschraenken).
                $wasKnown = isset($joinCtx['map']['dft_join']);
                $colExpr = $this->registerDefaultJoin($tableName, $joinCtx, 'filter');
                $nv      = $this->normalizeValues($entries[0]['raw']);
                $optList = $this->parseOptList($optReq, $entries[0]['key']);

                // Gruppe als Typ 'text' bauen
                $group = $this->buildScalarGroup(
                    $colExpr, $nv, $optList, $mode, 'text', $fieldLabel, $strAnd, $strOr, $strNot
                );
                if ($group[0] === '') {
                    if (!$wasKnown) {
                        unset($joinCtx['joins'][$joinCtx['map']['dft_join']], $joinCtx['map']['dft_join']);
                    }
                    $res = null;
            } else {
                    $res = $group;
                }
            } else {
                // Reguläres virtuelles Feld aufloesen
                $field = $this->resolveVirtualField($main);
                if ($field === null) {
                    $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$main]), $main);
                    continue;
                }

                // Label fuer das virtuelle Feld aus dem Schema ermitteln (Fallback: Feldname)
                $fieldLabel = $main;
                if (!empty($schema[$main]['label'])) {
                    $fieldLabel = $this->textOrSnippet($schema[$main]['label']);
                }

                $type = $field['type'];
                $fid  = $field['fieldname_id'];

                // filtermode: per HAUPT-Feld (Default OR)
                // zentrale Auswertung inkl. Fehlerregistrierung
                $mode = $this->resolveFilterMode($modeReq, $main);
                if (in_array($type, $this->relationTypes, true)) {
                    if (in_array($type, $this->singleRelationTypes, true)) {
                        // n:1 ('relation1'/'pointer1') -> JOIN statt EXISTS.
                        // data_rel liefert bei diesen Typen hoechstens EINE Zeile
                        // je (src_id, fieldname_id) -> keine Mehrfachzeilen, kein
                        // DISTINCT, semantisch aequivalent, aber besser optimierbar
                        // (kein korreliertes Subselect je Kandidaten-Zeile).
                        $res = $this->buildRelationJoinGroup(
                            $tableName, $fieldLabel, $fid, $entries, $mode, $optReq,
                            $joinCtx, $strAnd, $strOr, $strNot
                        );
                    } else {
                        // pointer / relation (n:m) -> EXISTS
                        // (ein JOIN wuerde hier Mehrfachzeilen erzeugen)
                        $res = $this->buildRelationExists(
                            $tableName, $fieldLabel, $fid, $entries, $mode, $optReq, $strAnd, $strOr, $strNot
                        );
                    }
                }
                elseif ($type === 'file') {
                    // file -> EXISTS
                    $res = $this->buildFileExists(
                        $tableName, $fieldLabel, $fid, $entries, $mode, $optReq, $strAnd, $strOr, $strNot
                    );
                }
                else {
                    // Skalar (text/chars/integer/.../date/time/json/fulltext)
                    // Ein Skalarfeld darf KEIN Subfeld haben.
                    if (count($entries) !== 1 || $entries[0]['sub'] !== '') {
                        $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$entries[0]['key']]), $entries[0]['key']);
                        continue;
                    }
                    // Punkt 1+2+3: JOIN statt EXISTS.
                    // Merken, ob der JOIN neu angelegt wurde, um ihn bei leerer
                    // Bedingungsgruppe (z. B. nur ungueltige Werte) wieder zu entfernen.
                    $cacheKey = 'vf:' . $field['fieldname_id'];
                    $wasKnown = isset($joinCtx['map'][$cacheKey]);
                    $colExpr = $this->registerScalarJoin($tableName, $field, $joinCtx, $main, 'filter');
                    $nv      = $this->normalizeValues($entries[0]['raw']);
                    $optList = $this->parseOptList($optReq, $entries[0]['key']);

                    $group = $this->buildScalarGroup(
                        $colExpr, $nv, $optList, $mode, $type, $fieldLabel, $strAnd, $strOr, $strNot
                    );
                    if ($group[0] === '') {
                        if (!$wasKnown) {
                            unset($joinCtx['joins'][$joinCtx['map'][$cacheKey]], $joinCtx['map'][$cacheKey]);
                }
                        $res = null;
                    } else {
                        $res = $group;
            }
                }
            }
            if ($res !== null && $res[0] !== '') {
                $whereParts[]      = $res[0];
                $bindTypes        .= $res[1];
                array_push($values, ...$res[2]);
                $filterTextParts[] = $res[3];
            }
        }

        // ----------------------------------------------------------------
        // src_id + relation
        // Datensaetze, die ZIEL (dest_id) einer Relation des angegebenen
        // src_id-Datensatzes ueber das angegebene Relation-Feld sind.
        // (relation/pointer gleichbehandelt). Reine Existenzpruefung -> EXISTS.
        //
        // dest_id + relation (Rueckrichtung)
        // Datensaetze, die ueber das Relation-Feld AUF dest_id zeigen - "wer
        // verweist auf diesen Datensatz?". Mit src_id zusammen gilt beides.
        //
        // relation: ein Feldname, mehrere kommasepariert (irgendeines davon) oder
        // 'any' - irgendein Verweisfeld (jede Zeile in data_rel, auch 'link'-Felder).
        // Mit dest_id z. B. alle Verweise auf einen Datensatz.
        //
        // nextof (nur zusammen mit src_id + genau einem Feld)
        // Nachbarn des Datensatzes nextof in derselben Verweisliste: die Eintraege
        // HINTER ihm (data_rel.position groesser) bei aufsteigender, die VOR ihm
        // bei absteigender Richtung - die Richtung kommt aus sort/order des
        // Aufrufs (nextSortDirection()). select() erzwingt dazu die Sortierung
        // '_custom' in dieser Richtung; mit limit=1 ist das Ergebnis genau der
        // naechste bzw. vorige Datensatz. Steht nextof selbst nicht in der Liste,
        // liefert die Unterabfrage NULL und der Vergleich findet nichts.
        // ----------------------------------------------------------------
        $srcId   = isset($param['src_id']) ? (int)$param['src_id'] : 0;
        $destId  = isset($param['dest_id']) && is_scalar($param['dest_id']) ? (int)$param['dest_id'] : 0;
        $relName = trim((string)($param['relation'] ?? ''));
        $nextOf  = (int)($param['nextof'] ?? 0);
        $relNames = array_values(array_filter(array_map('trim', explode(',', $relName)), 'strlen'));
        $isAny   = in_array('any', array_map('strtolower', $relNames), true);
        if ($relName !== '' && $srcId < 1 && $destId < 1) {
            // Verknüpfung ohne Quelle bzw. Ziel (src_id/dest_id leer/0, etwa aus
            // einem fehlenden GET-Parameter): keine Treffer - nicht den Filter
            // weglassen und damit ALLE Datensätze liefern
            $whereParts[] = '0 = 1';
        }
        elseif ($relName !== '' && $useVirtual) {
            $fieldnameIds = [];
            $unknown = '';
            if (!$isAny) {
                foreach ($relNames as $name) {
                    $relField = $this->resolveVirtualField($name);
                    if ($relField === null || !in_array($relField['type'], $this->relationTypes, true)) {
                        $unknown = $name;
                        break;
                    }
                    $fieldnameIds[] = (int)$relField['fieldname_id'];
                }
            }
            if ($unknown !== '' || (!$isAny && !$fieldnameIds)) {
                $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$unknown !== '' ? $unknown : $relName]), $unknown !== '' ? $unknown : $relName);
                // unbekanntes Feld: nichts finden statt den Filter wegzulassen
                $whereParts[] = '0 = 1';
            }
            else {
                $fieldnameIds = array_values(array_unique($fieldnameIds));
                $fieldCond = $isAny ? '' : (count($fieldnameIds) === 1
                    ? " AND `_src`.`fieldname_id` = ?"
                    : " AND `_src`.`fieldname_id` IN (" . implode(',', array_fill(0, count($fieldnameIds), '?')) . ")");
                if ($srcId > 0) {
                    $nextCond = '';
                    if ($nextOf > 0 && count($fieldnameIds) === 1) {
                        $cmp = ($this->nextSortDirection($param) === 'DESC') ? '<' : '>';
                        $nextCond = " AND `_src`.`position` $cmp (SELECT `_nx`.`position` FROM `data_rel` `_nx`"
                                  . " WHERE `_nx`.`src_id` = ? AND `_nx`.`fieldname_id` = ?"
                                  . " AND `_nx`.`dest_id` = ? LIMIT 1)";
                    }
                    elseif ($nextOf > 0) {
                        $this->error($this->snippet('error_param_invalid'), 'nextof');
                    }
                    $whereParts[] = "EXISTS (SELECT 1 FROM `data_rel` `_src`"
                                  . " WHERE `_src`.`dest_id` = `$tableName`.`{$tableName}_id`"
                                  . " AND `_src`.`src_id` = ?" . $fieldCond . $nextCond . ")";
                    $bindTypes   .= 'i' . str_repeat('i', count($fieldnameIds));
                    $values[]     = $srcId;
                    array_push($values, ...$fieldnameIds);
                    if ($nextCond !== '') {
                        $bindTypes .= 'iii';
                        $values[]   = $srcId;
                        $values[]   = $fieldnameIds[0];
                        $values[]   = $nextOf;
                    }
                    if ($wantFilterText) {
                        $filterTextParts[] = $relName . ' ← ' . $srcId
                            . ($nextCond !== '' ? ' (nextof ' . $nextOf . ')' : '');
                    }
                }
                if ($destId > 0) {
                    $whereParts[] = "EXISTS (SELECT 1 FROM `data_rel` `_src`"
                                  . " WHERE `_src`.`src_id` = `$tableName`.`{$tableName}_id`"
                                  . " AND `_src`.`dest_id` = ?" . $fieldCond . ")";
                    $bindTypes   .= 'i' . str_repeat('i', count($fieldnameIds));
                    $values[]     = $destId;
                    array_push($values, ...$fieldnameIds);
                    if ($wantFilterText) {
                        $filterTextParts[] = trim(($isAny ? '' : $relName) . ' '
                            . $this->snippet('filter_refers_to') . ' #' . $destId);
                    }
                }
            }
        }
        elseif ($nextOf > 0) {
            // nextof ohne Verweisliste ist nicht definiert
            $this->error($this->snippet('error_param_invalid'), 'nextof');
        }

        // hardcodiert im Parameter sql mitgegeben (muss literal sein, keine Bindings).
        // Ein String oder eine Liste von Bedingungen - cmsSelect() haengt so seine
        // Veroeffentlichungsbedingung an das sql des Templates. Jede Bedingung wird
        // geklammert (ein OR darin bleibt damit ein OR) und ein fuehrendes "AND"
        // toleriert.
        foreach ((array)($param['sql'] ?? []) as $sqlPart) {
            $sqlPart = trim((string)$sqlPart);
            $sqlPart = preg_replace('/^AND\s+/i', '', $sqlPart);
            if ($sqlPart === '') continue;
            $whereParts[] = '(' . $sqlPart . ')';
        }

        if (empty($whereParts)) return $returnEmpty;

        return [
            'where'   => 'AND ' . implode(' AND ', $whereParts),
            'types'   => $bindTypes,
            'values'  => $values,
            'text'    => $wantFilterText ? implode(" $strAnd ", $filterTextParts) : '',
            'joinCtx' => $joinCtx,
        ];
    }

    /**
     * Richtung, in der 'nextof' die Verweisliste durchläuft (siehe filter()):
     * die Richtung des ersten Sortierfeldes - "feld DESC" im Wert von sort oder
     * der erste Eintrag von order. Ohne Angabe ASC (der nächste Datensatz).
     *
     * @return string  'ASC' | 'DESC'
     */
    private function nextSortDirection(array $p): string
    {
        $sort  = $p['sort']  ?? '';
        $order = $p['order'] ?? '';
        $sort  = is_array($sort)  ? (string)(reset($sort)  ?: '') : (string)$sort;
        $order = is_array($order) ? (string)(reset($order) ?: '') : (string)$order;
        // nur das erste Sortierfeld zählt
        $sort  = trim((string)strtok($sort, ','));
        $order = trim((string)strtok($order, ','));
        $parts = preg_split('/\s+/', $sort);
        $direction = (count($parts) > 1) ? $parts[1] : $order;
        return (strtoupper(trim($direction)) === 'DESC') ? 'DESC' : 'ASC';
    }

    /**
     * Erzeugt einen leeren JOIN-Kontext, der von filter() und der Sortierung
     * in select() gemeinsam genutzt wird.
     *
     * 'joins'    : alias => ['sql','types','values','phase']  (phase: 'filter'|'sort')
     * 'map'      : Wiederverwendung   cacheKey => alias
     * 'seq'      : fortlaufender Alias-Zaehler
     * 'distinct' : true, sobald ein JOIN Mehrfachzeilen erzeugen kann
     *              (in dieser Version bleiben Relationen/Dateien EXISTS,
     *               daher i.d.R. false; das Flag ist fuer kuenftige Erweiterung
     *               vorhanden)
     */
    private function newJoinCtx(): array
    {
        return [
            'joins'    => [],
            'map'      => [],
            'seq'      => 0,
            'distinct' => false,
        ];
    }

    /**
     * Liefert den qualifizierten Spaltenausdruck eines virtuellen Feldes.
     *
     * KEIN COALESCE: data_*-Tabellen sind beim Schreiben garantiert vollstaendig.
     * Sollte (entgegen der Garantie) ein Wert fehlen, liefert NULL keinen Treffer.
     *
     * @param string $alias  JOIN-Alias (beginnt mit "_")
     * @param string $type   Langer Feldtyp
     * @param string $col    Spalte in der data_*-Tabelle (default 'data')
     */
    private function virtualColumnExpr(string $alias, string $type, string $col = 'data'): string
    {
        // Verschlüsselte Felder: nicht der Geheimtext, sondern der HMAC daneben.
        // encrypt() zieht je Aufruf einen neuen IV - `data` ist deshalb für keinen
        // Vergleich und keine Sortierung zu gebrauchen, `hash` dagegen ist
        // deterministisch. Siehe buildEncryptedCondition().
        if ($type === 'encrypted' && $col === 'data') $col = 'hash';

        return "`$alias`.`$col`";
    }

    /**
     * Registriert (oder findet) einen JOIN auf die data_*-Tabelle eines
     * virtuellen Skalarfeldes. Alias beginnt mit "_".
     * Wird von Filter UND Sortierung genutzt -> Wiederverwendung ueber 'map'.
     *
     * Annahme: pro (record_id, fieldname_id) existiert hoechstens EINE data_*-Zeile
     * (Skalarfeld) -> der JOIN erzeugt keine Mehrfachzeilen, kein DISTINCT noetig.
     *
     * JOIN-Typ nach Phase:
     *   'filter' -> INNER JOIN. Alle erzeugten WHERE-Bedingungen auf `alias`.`data`
     *               sind NULL-verwerfend (auch != und NOT LIKE), d.h. LEFT und INNER
     *               liefern identische Ergebnisse. INNER macht die Absicht explizit
     *               und gibt dem Optimizer volle Freiheit bei der Join-Reihenfolge
     *               (z. B. Zugriff ueber idx_filter(fieldname_id, data) als Treiber).
     *   'sort'   -> LEFT JOIN. Datensaetze OHNE Feldwert duerfen beim reinen
     *               Sortieren nicht aus dem Ergebnis fallen.
     *
     * @param string $tableName  Basistabelle (z. B. 'record')
     * @param array  $vf         resolveVirtualField()-Ergebnis (fieldname_id, type)
     * @param array  &$ctx       JOIN-Kontext
     * @param string $fieldName  Feldname (nur fuer evtl. Debug/Erweiterung)
     * @param string $phase      'filter' | 'sort'
     * @return string            Spaltenausdruck, z. B. `_vf_0`.`data`
     */
    private function registerScalarJoin(
        string $tableName,
        array  $vf,
        array  &$ctx,
        string $fieldName,
        string $phase = 'filter'
    ): string {
        $cacheKey = 'vf:' . $vf['fieldname_id'];
        if (isset($ctx['map'][$cacheKey])) {
            $alias = $ctx['map'][$cacheKey];
        } else {
            $dataTable = $this->virtualDataTable($vf['type']);
            $alias     = '_vf_' . $ctx['seq']++;
            // INNER in der Filter-Phase, LEFT in der Sort-Phase
            $joinType  = ($phase === 'filter') ? 'INNER JOIN' : 'LEFT JOIN';
            $ctx['joins'][$alias] = [
                'sql'    => "$joinType `$dataTable` AS `$alias`"
                          . " ON `$alias`.`record_id` = `$tableName`.`{$tableName}_id`"
                          . " AND `$alias`.`fieldname_id` = ?"
                          . " AND `$alias`.`language_id` = " . $this->languageExpr($tableName),
                'types'  => 'i',
                'values' => [$vf['fieldname_id']],
                'phase'  => $phase,
            ];
            $ctx['map'][$cacheKey] = $alias;
        }
        return $this->virtualColumnExpr($alias, $vf['type']);
    }

    /**
     * Registriert (oder findet) einen JOIN auf die Tabelle data_dft
     * für das _default-Feld. Alias beginnt mit "_".
     * Wird von Filter UND Sortierung genutzt -> Wiederverwendung ueber 'map'.
     *
     * Filter-Phase -> INNER JOIN (data_dft haelt garantiert eine Zeile
     * pro Datensatz vor; die Filter-Bedingung ist zudem NULL-verwerfend).
     * Sort-Phase -> LEFT JOIN (defensiv, falls eine dft-Zeile fehlt).
     *
     * @param string $tableName  Basistabelle (z. B. 'record')
     * @param array  &$ctx       JOIN-Kontext
     * @param string $phase      'filter' | 'sort'
     * @return string            Spaltenausdruck, z. B. `_dft_0`.`data`
     */
    private function registerDefaultJoin(
        string $tableName,
        array  &$ctx,
        string $phase = 'filter'
    ): string {
        $cacheKey = 'dft_join';
        if (isset($ctx['map'][$cacheKey])) {
            $alias = $ctx['map'][$cacheKey];
        } else {
            $alias    = '_dft_' . $ctx['seq']++;
            $joinType = ($phase === 'filter') ? 'INNER JOIN' : 'LEFT JOIN';
            $ctx['joins'][$alias] = [
                // KEINE fieldname_id in der ON-Bedingung nötig
                'sql'    => "$joinType `data_dft` AS `$alias`"
                          . " ON `$alias`.`record_id` = `$tableName`.`{$tableName}_id`"
                          . " AND `$alias`.`language_id` = " . $this->languageExpr($tableName),
                'types'  => '',
                'values' => [],
                'phase'  => $phase,
            ];
            $ctx['map'][$cacheKey] = $alias;
        }
        return $this->virtualColumnExpr($alias, 'text');
    }

    /**
     * Zentrale Auswertung des filtermode-Parameters fuer ein Hauptfeld.
     * Ungueltige Werte (weder AND noch OR) werden als Fehler registriert
     * (Snippet 'error_filter_mode_invalid') und auf den Default 'OR' gesetzt.
     */
    private function resolveFilterMode(array $modeReq, string $key): string
    {
        $raw = $modeReq[$key] ?? '';
        // verschachtelte Arrays (filtermode[title][]=..) abfangen
        if (!is_scalar($raw)) {
            $this->error($this->snippet('error_filter_mode_invalid'), $key);
            return 'OR';
        }
        $mode = strtoupper(trim((string)$raw));
        if ($mode === '') return 'OR';
        if (!in_array($mode, ['AND', 'OR'], true)) {
            $this->error($this->snippet('error_filter_mode_invalid'), $key);
            return 'OR';
        }
        return $mode;
    }

    /**
     * Emittiert JOIN-SQL + Bind-Parameter fuer die gewuenschten Phasen.
     * COUNT nutzt nur ['filter'], das Haupt-SELECT ['filter','sort'].
     * Reihenfolge bleibt Einfuegereihenfolge -> Filter-Joins zuerst (Bind-sicher).
     *
     * @return array{sql:string,types:string,values:array}
     */
    private function emitJoins(array $ctx, array $phases): array
    {
        $sql    = '';
        $types  = '';
        $values = [];
        foreach ($ctx['joins'] as $j) {
            if (!in_array($j['phase'], $phases, true)) continue;
            $sql   .= ' ' . $j['sql'];
            $types .= $j['types'];
            array_push($values, ...$j['values']);
        }
        return ['sql' => $sql, 'types' => $types, 'values' => $values];
    }
    
    /**
     * Baut die WHERE-Teilbedingung fuer ein METAFELD.
     * Duenne Huelle: ermittelt Spaltenausdruck + Modus und delegiert an
     * buildScalarGroup() (dieselbe Logik wie fuer virtuelle Felder).
     *
     * @return array{0:string,1:string,2:array,3:string}|null  [sql, types, values, text] oder null
     */
    private function buildMetaGroup(
        string $tableName,
        string $fieldKey,
        mixed  $rawValue,
        array  $fieldDef,
        array  $modeReq,
        array  $optReq,
        string $strAnd,
        string $strOr,
        string $strNot
    ): ?array {
        $type = $fieldDef['type'] ?? 'text';
        $nv   = $this->normalizeValues($rawValue);

        // filtermode: Verknuepfung mehrerer Werte desselben Feldes (Standard: 'OR')
        // zentrale Auswertung inkl. Fehlerregistrierung
        $mode = $this->resolveFilterMode($modeReq, $fieldKey);
        // filterop: Operatoren je Wert als kommaseparierte Liste (index-synchron)
        $optList = $this->parseOptList($optReq, $fieldKey);

        // Label ermitteln
        $fieldLabel = empty($fieldDef['label']) ? $fieldKey : $this->textOrSnippet($fieldDef['label']);

        // Metafeld -> tabellen-qualifizierter Spaltenausdruck (JOIN-sicher)
        $colExpr = "`$tableName`.`$fieldKey`";

        $group = $this->buildScalarGroup(
            $colExpr, $nv, $optList, $mode, $type, $fieldLabel, $strAnd, $strOr, $strNot
        );
        return ($group[0] === '') ? null : $group;
    }

    /**
     * Baut die verknuepfte Wert-Gruppe eines Skalarfeldes auf einen FERTIGEN
     * Spaltenausdruck $colExpr. Mehrere Werte werden gemaess $mode (AND/OR)
     * verknuepft. Unterstuetzt Inline-Ausdruecke "A OR B" je Wert –
     * AUSNAHME: == und !! erzwingen exakte Suche, kein Inline-AND/OR.
     *
     * $colExpr ist bereits qualifiziert:
     *   Metafeld   ->  `record`.`title`
     *   virtuell   ->  `_vf_0`.`data`
     *   Subquery   ->  `data` / `size` / `rec`.`changed_at`
     *
     * @return array{0:string,1:string,2:array,3:string}  [sqlGruppe, bindTypes, values, textGruppe]
     */
    private function buildScalarGroup(
        string $colExpr,
        array  $normalizedValues,
        array  $optList,
        string $mode,
        string $type,
        string $fieldLabel,
        string $strAnd,
        string $strOr,
        string $strNot
    ): array {
        $conds = [];
        $types = '';
        $vals  = [];
        $texts = [];
        static $knownOps = ['=', '==', '!', '!=', '!!', '>', '>=', '<', '<=', '>>', '<<'];
        foreach ($normalizedValues as $valIdx => $val) {
            // Operator aus filterop voranstellen
            if (isset($optList[$valIdx]) && $optList[$valIdx] !== '') {
                if (in_array($optList[$valIdx], $knownOps, true)) {
                    $val = $optList[$valIdx] . $val;
                    } else {
                        $this->error($this->snippet('error_filter_op_invalid'), $fieldLabel);
                }
            }
            // == und !! erzwingen exakte Suche: keine Inline-AND/OR-Ausdruecke
            $isExact = in_array(substr($val, 0, 2), ['==', '!!'], true);

            // Inline-Ausdruck "v1 OR v2 AND v3"
            if (!$isExact && preg_match('/\s+(?:OR|AND)\s+/i', $val)) {
                $expr = $this->buildScalarExpression(
                    $colExpr, $val, $type, $fieldLabel, $strAnd, $strOr, $strNot
                );
                if ($expr[0] === '') continue;
                $conds[] = $expr[0];
                $types  .= $expr[1];
                array_push($vals, ...$expr[2]);
                $texts[] = $expr[3];
                continue;
            }

            [$op, $value] = $this->extractOperator($val);
            $opForText = match ($op) {
                '!', '!!' => $strNot,
                '=='      => '=',
                default   => $op,
            };

            $res = $this->buildScalarCondition($colExpr, $value, $op, $type, $fieldLabel);
            if ($res[0] === '') {
                $this->error($this->snippet('error_filter_value_invalid'), $fieldLabel);
                continue;
            }
            $conds[] = $res[0];
            $types  .= $res[1];
            array_push($vals, ...$res[2]);
            $texts[] = trim("$fieldLabel $opForText $value");
        }

        if (empty($conds)) return ['', '', [], ''];

        $glue     = ($mode === 'AND') ? ' AND ' : ' OR ';
        $textGlue = ($mode === 'AND') ? " $strAnd " : " $strOr ";

        $sql  = count($conds) === 1 ? $conds[0] : '(' . implode($glue, $conds) . ')';
        $text = count($texts) === 1 ? $texts[0] : '(' . implode($textGlue, $texts) . ')';

        return [$sql, $types, $vals, $text];
    }

    /**
     * INLINE-AUSDRUCK ("A OR B AND C") fuer einen Spaltenausdruck $colExpr.
     *
     * @return array{0:string,1:string,2:array,3:string}
     */
    private function buildScalarExpression(
        string $colExpr,
        string $expression,
        string $type,
        string $fieldLabel,
        string $strAnd,
        string $strOr,
        string $strNot
    ): array {
        $tokens    = preg_split('/\s+(OR|AND)\s+/i', $expression, -1, PREG_SPLIT_DELIM_CAPTURE);
        $parts     = [];
        $bindTypes = '';
        $values    = [];
        $textParts = [];
        $glue      = '';
        $textGlue  = '';

        $i = 0;
        while ($i < count($tokens)) {
            $token = trim($tokens[$i]);
            if (strtoupper($token) === 'OR' || strtoupper($token) === 'AND') {
                $glue     = strtoupper($token);
                $textGlue = ($glue === 'OR') ? $strOr : $strAnd;
                $i++;
                continue;
            }

            [$op, $value] = $this->extractOperator($token);
            $opForText = match ($op) {
                '!', '!!' => $strNot,
                '=='      => '=',
                default   => $op,
            };

            $res = $this->buildScalarCondition($colExpr, $value, $op, $type, $fieldLabel);
            if ($res[0] !== '') {
                if (!empty($parts)) {
                    $parts[]     = $glue;
                    $textParts[] = $textGlue;
                }
                $parts[]     = $res[0];
                $textParts[] = trim("$fieldLabel $opForText $value");
                $bindTypes  .= $res[1];
                array_push($values, ...$res[2]);
            } else {
                $this->error($this->snippet('error_filter_value_invalid'), $fieldLabel);
            }
            $i++;
        }

        if (empty($parts)) return ['', '', [], ''];

        return [
            '(' . implode(' ', $parts) . ')',
            $bindTypes,
            $values,
            '(' . implode(' ', $textParts) . ')',
        ];
    }

    /**
     * Baut fuer EINEN Wert die SQL-Bedingung auf einen FERTIGEN Spaltenausdruck.
     * Zentraler Dispatcher nach Feldtyp (genutzt von Meta- UND virtuellem Pfad).
     *
     * @return array{0:string,1:string,2:array}  [sql, bindTypes, values]
     */
    private function buildScalarCondition(string $colExpr, string $value, string $op, string $type, string $fieldLabel = ''): array
    {
        // "(empty)" - kein eigener Wert: leer ODER Fallback aus der Hauptsprache
        // (Spalte `empty` = 1). So findet das Backend Datensätze, die in dieser
        // Sprache noch nicht übersetzt sind. Erlaubt mit '=', '==' und negiert.
        if (strcasecmp($value, self::EMPTY_FILTER) === 0) {
            if (!in_array($op, ['=', '==', '!', '!!'], true)) return ['', '', []];
            $sql = $this->emptyCondition($colExpr, $type);
            return [in_array($op, ['!', '!!'], true) ? "NOT $sql" : $sql, '', []];
        }
        if ($type === 'fulltext') {
            return $this->buildFulltextCondition($colExpr, $value);
        }
        if ($type === 'json') {
            return $this->buildJsonCondition($colExpr, $value, $op);
        }
        if (in_array($type, $this->dateTypes, true)) {
            // aufgefuellt auf 19 Zeichen
            return $this->buildDateConditionUnified($colExpr, $value, $op, $type);
        }
        if ($type === 'time') {
            // aufgefuellt auf 8 Zeichen
            return $this->buildTimeConditionUnified($colExpr, $value, $op);
        }
        if (in_array($type, $this->numericTypes, true)) {
            return $this->buildNumericCondition($colExpr, $value, $op, $type);
        }
        if ($type === 'encrypted') {
            return $this->buildEncryptedCondition($colExpr, $value, $op, $fieldLabel);
        }
        // Textfelder (data_txt, data_chr, data_dft) nach den Regeln der Sprache
        if ($type === 'text' || $type === 'chars') $colExpr = $this->collate($colExpr);
        return $this->buildTextCondition($colExpr, $value, $op);
    }

    /**
     * Bedingung fuer ein verschluesseltes Feld (data_enc).
     *
     * Verglichen wird der HMAC in `hash`, nicht der Geheimtext in `data`: encrypt()
     * zieht je Aufruf einen neuen IV, derselbe Klartext ergibt also nie denselben
     * Geheimtext. Den Suchwert normalisieren und hashen wir hier genauso, wie ihn
     * VirtualFieldsTrait::saveVirtualFields() beim Speichern behandelt hat
     * (encryptedHash(): Groß-/Kleinschreibung, Leerraum und Satzzeichen zählen nicht).
     *
     * Damit ist NUR Gleichheit des normalisierten Werts moeglich. Groesser/kleiner und Platzhalter (*)
     * ergeben auf einem Hash keinen Sinn. Beides meldet die Methode selbst und liefert
     * dann eine Bedingung, die nichts findet (1=0) - haette sie wie die anderen
     * build*Condition() einfach nichts geliefert, fiele die Bedingung ersatzlos weg und
     * die Abfrage gaebe ALLE Datensaetze zurueck. Bei einem verschluesselten Feld ist
     * das der gefaehrlichste denkbare Ausgang.
     *
     * (Ein leerer Suchwert kommt hier gar nicht erst an - normalizeValues() wirft leere
     * Filterwerte fuer alle Typen vorher weg.)
     *
     * @param string $fieldLabel  fuer die Fehlermeldung
     * @return array              [sql, bindTypes, values]
     */
    private function buildEncryptedCondition(string $col, string $value, string $op, string $fieldLabel = ''): array
    {
        $noMatch = ['1=0', '', []];

        if (str_contains($value, '*')) {
            // Platzhalter auf einem Hash: es gibt keine Teiltreffer
            $this->error($this->snippet('error_filter_value_invalid'), $fieldLabel);
            return $noMatch;
        }

        if (!in_array($op, ['=', '==', '!', '!!', '!='], true)) {
            // Groesser/kleiner: auf einem Hash ohne Aussage
            $this->error($this->snippet('error_filter_op_invalid'), $fieldLabel);
            return $noMatch;
        }

        // normalisiert wie beim Speichern (encryptedHash()); bleibt nichts übrig
        // (nur Satzzeichen), gibt es auch nichts zu finden
        $needle = $this->encryptedHash($value); // binary, 32 Bytes
        if ($needle === '') return $noMatch;

        return in_array($op, ['=', '=='], true)
            ? ["$col = ?",  's', [$needle]]
            : ["$col != ?", 's', [$needle]];
    }

    /**
     * Bedingung fuer den Filterwert "(empty)" bzw. "!(empty)" auf einem
     * RELATION-/POINTER-Feld: kein Verweis bzw. mindestens ein Verweis auf einen
     * PRODUKTIVEN Datensatz. Kanten auf Arbeitskopien (Check-out legt auch die
     * eingehenden an) und auf geloeschte Ziele (bleiben fuer restore() stehen)
     * zaehlen nicht - wie bei der Ausgabe mit relation=.
     *
     * @return array{0:string,1:string,2:array,3:string}
     */
    private function relationEmptyPredicate(string $tableName, string $fieldLabel, int $fid, bool $negate, string $strNot): array
    {
        $exists = "EXISTS (SELECT 1 FROM `data_rel` `_re`"
                . " INNER JOIN `record` `_rd` ON `_rd`.`record_id` = `_re`.`dest_id` AND `_rd`.`tmp` = 0 AND `_rd`.`deleted` = 0"
                . " WHERE `_re`.`fieldname_id` = ? AND `_re`.`src_id` = `$tableName`.`{$tableName}_id`)";
        return [
            $negate ? $exists : "NOT $exists",
            'i',
            [$fid],
            $fieldLabel . ' ' . ($negate ? $strNot : '=') . ' ' . self::EMPTY_FILTER,
        ];
    }

    /**
     * EXISTS-Bedingung fuer ein RELATION-/POINTER-Feld (data_rel).
     *
     * Direkte Ziel-IDs (sub = '' | 'record_id'):
     *   OR  -> EIN EXISTS mit  r.dest_id IN (...)
     *   AND -> je Ziel-ID ein eigenes EXISTS (... = id)  (Verknuepfung mit MEHREREN Zielen)
     *   Negation (!id) -> NOT EXISTS (... = id)
     *
     * Subfeld:
     *   - METAFELD des Ziel-record (z. B. category.changed_at) -> EXISTS mit JOIN auf `record`
     *   - virtuelles Skalarfeld (z. B. category.title)         -> verschachteltes EXISTS auf data_*
     *   relation/pointer als Subfeld -> error_relation_relation
     *   file als Subfeld             -> error_field_unknown_type
     *
     * filtermode (AND/OR) verknuepft die Praedikate; AND erlaubt UNTERSCHIEDLICHE
     * Ziel-Datensaetze je Teilbedingung (Mengenreduktion ohne DISTINCT, da EXISTS).
     *
     * @return array{0:string,1:string,2:array,3:string}|null
     */
    private function buildRelationExists(
        string $tableName,
        string $fieldLabel,
        int    $fid,
        array  $entries,
        string $mode,
        array  $optReq,
        string $strAnd,
        string $strOr,
        string $strNot
    ): ?array {
        $predicates = [];
        $relWhere   = "r.`fieldname_id` = ? AND r.`src_id` = `$tableName`.`{$tableName}_id`";

        foreach ($entries as $e) {
            $sub = $e['sub'];
            $nv  = $this->normalizeValues($e['raw']);

            // Ziel-IDs direkt (main bzw. main.record_id)
            if ($sub === '' || $sub === 'record_id') {
                $pos = [];
                $neg = [];
                foreach ($nv as $v) {
                    [$op, $val] = $this->extractOperator($v);
                    if (strcasecmp($val, self::EMPTY_FILTER) === 0) {
                        $predicates[] = $this->relationEmptyPredicate($tableName, $fieldLabel, $fid, $op === '!' || $op === '!!', $strNot);
                        continue;
                    }
                    if (!is_numeric($val)) {
                        $this->error($this->snippet('error_filter_value_invalid'), $e['key']);
                        continue;
                    }
                    if (in_array($op, ['!', '!!'], true)) $neg[] = (int)$val;
                    else                                  $pos[] = (int)$val;
                }
                if ($pos) {
                    if ($mode === 'AND') {
                        // jeder Ziel-Datensatz muss separat verknuepft sein
                        foreach ($pos as $pid) {
                            $predicates[] = [
                                "EXISTS (SELECT 1 FROM `data_rel` r WHERE $relWhere AND r.`dest_id` = ?)",
                                'ii',
                                [$fid, $pid],
                                $fieldLabel . ' = ' . $pid,
                            ];
                        }
                    } else {
                        // OR -> ein EXISTS mit IN
                        $ph = implode(',', array_fill(0, count($pos), '?'));
                        $predicates[] = [
                            "EXISTS (SELECT 1 FROM `data_rel` r WHERE $relWhere AND r.`dest_id` IN ($ph))",
                            'i' . str_repeat('i', count($pos)),
                            array_merge([$fid], $pos),
                            $fieldLabel . ' = ' . implode(', ', $pos),
                        ];
                    }
                }
                foreach ($neg as $nid) {
                    $predicates[] = [
                        "NOT EXISTS (SELECT 1 FROM `data_rel` r WHERE $relWhere AND r.`dest_id` = ?)",
                        'ii',
                        [$fid, $nid],
                        $fieldLabel . ' ' . $strNot . ' ' . $nid,
                    ];
                }
                continue;
            }

            $subLabel = $fieldLabel . '.' . $sub;
            $optList  = $this->parseOptList($optReq, $e['key']);

            // Subfeld = METAFELD des Ziel-record
            if (isset($this->schema[$sub])) {
                $metaType = $this->schema[$sub]['type'] ?? 'text';
                // Spalte des Ziel-records, qualifiziert ueber Alias `rec` im inneren EXISTS
                $colExpr  = "`rec`.`$sub`";
                $group = $this->buildScalarGroup(
                    $colExpr, $nv, $optList, $mode, $metaType, $subLabel, $strAnd, $strOr, $strNot
                );
                if ($group[0] === '') continue;
                $predicates[] = [
                    "EXISTS (SELECT 1 FROM `data_rel` r"
                        . " JOIN `record` `rec` ON `rec`.`record_id` = r.`dest_id`"
                        . " WHERE $relWhere AND " . $group[0] . ")",
                    'i' . $group[1],
                    array_merge([$fid], $group[2]),
                    $group[3],
                ];
                continue;
            }

            // Subfeld = virtuelles Feld des Ziel-record
            $subField = $this->resolveVirtualField($sub);
            if ($subField === null) {
                $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$e['key']]), $e['key']);
                continue;
            }
            if (in_array($subField['type'], $this->relationTypes, true)) {
                // Relation.Relation nicht erlaubt
                $this->error($this->snippet('error_relation_relation'), $e['key']);
                continue;
            }
            if ($subField['type'] === 'file') {
                // zu tief verschachtelt -> nicht unterstuetzt
                $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$e['key']]), $e['key']);
                continue;
            }

            // Skalar-Subfeld -> verschachteltes EXISTS auf data_* des Ziel-records
            $dataTable = $this->virtualDataTable($subField['type']);
            $colExpr   = "`data`"; // im inneren data_*-EXISTS
            $group = $this->buildScalarGroup(
                $colExpr, $nv, $optList, $mode, $subField['type'], $subLabel, $strAnd, $strOr, $strNot
            );
            if ($group[0] === '') continue;
            $predicates[] = [
                "EXISTS (SELECT 1 FROM `data_rel` r WHERE $relWhere"
                    . " AND EXISTS (SELECT 1 FROM `$dataTable`"
                        . " WHERE `record_id` = r.`dest_id`"
                        . " AND `fieldname_id` = ?"
                        . " AND " . $group[0] . "))",
                'ii' . $group[1],
                array_merge([$fid, $subField['fieldname_id']], $group[2]),
                $group[3],
            ];
        }

        if (empty($predicates)) return null;

        $glue     = ($mode === 'AND') ? ' AND ' : ' OR ';
        $textGlue = ($mode === 'AND') ? " $strAnd " : " $strOr ";

        $sqlParts = [];
        $types    = '';
        $vals     = [];
        $texts    = [];
        foreach ($predicates as $pr) {
            $sqlParts[] = $pr[0];
            $types     .= $pr[1];
            array_push($vals, ...$pr[2]);
            $texts[]    = $pr[3];
        }
        $sql  = count($sqlParts) === 1 ? $sqlParts[0] : '(' . implode($glue, $sqlParts) . ')';
        $text = count($texts) === 1    ? $texts[0]    : '(' . implode($textGlue, $texts) . ')';

        return [$sql, $types, $vals, $text];
    }

    /**
     * Zahlen mit Einheit in einem Suchwert der Dateigröße in Bytes umrechnen:
     * k/K = 1024, M = 1024², G = 1024³, ein angehängtes B ist erlaubt und Groß-/
     * Kleinschreibung egal - ">1M AND <2.5M" wird zu ">1048576 AND <2621440".
     * Dezimaltrenner ist der Punkt (das Komma trennt Suchwerte).
     */
    public function expandSizeUnits(string $value): string
    {
        return (string)preg_replace_callback(
            '/(?<![\w.])(\d+(?:\.\d+)?)\s*([kmg])b?(?!\w)/i',
            function (array $m): string {
                $factor = match (strtolower($m[2])) { 'k' => 1024, 'm' => 1024 ** 2, default => 1024 ** 3 };
                return (string)(int)round((float)$m[1] * $factor);
            },
            $value
        );
    }

    /**
     * EXISTS-Bedingung fuer ein FILE-Feld (data_fil).
     *
     * Subfelder desselben Hauptfeldes werden gemaess $mode verknuepft und
     * beziehen sich auf DIESELBE Datei-Zeile.
     *
     * Erlaubte Subfelder (Spalte/Typ):
     *   size, width, height  (integer)
     *   filename             (chars)
     *   ocr_text             (text)
     * Bare File-Feld ohne Subfeld -> match auf `filename`.
     *
     * @return array{0:string,1:string,2:array,3:string}|null
     */
    private function buildFileExists(
        string $tableName,
        string $fieldLabel,
        int    $fid,
        array  $entries,
        string $mode,
        array  $optReq,
        string $strAnd,
        string $strOr,
        string $strNot
    ): ?array {
        // Whitelist: Subfeld -> [Spalte, Skalartyp]
        $fileCols = [
            'size'     => ['col' => 'size',     'kind' => 'integer'],
            'width'    => ['col' => 'width',    'kind' => 'integer'],
            'height'   => ['col' => 'height',   'kind' => 'integer'],
            'filename' => ['col' => 'filename', 'kind' => 'chars'],
            'mime_type' => ['col' => 'mime_type', 'kind' => 'chars'],
            'extension' => ['col' => 'extension', 'kind' => 'chars'],
            'ocr_text' => ['col' => 'ocr_text', 'kind' => 'text'],
        ];

        $inner = [];
        $types = '';
        $vals  = [];
        $texts = [];
        // Bedingungen ausserhalb des EXISTS ("(empty)", je eine Bindung $fid)
        $outer      = [];
        $outerTexts = [];

        foreach ($entries as $e) {
            // Bare File-Feld -> filename
            $sub = $e['sub'] !== '' ? $e['sub'] : 'filename';
            if (!isset($fileCols[$sub])) {
                $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$e['key']]), $e['key']);
                continue;
            }
            $colInfo = $fileCols[$sub];
            $nv      = $this->normalizeValues($e['raw']);
            $optList = $this->parseOptList($optReq, $e['key']);

            // "(empty)" - keine Datei: Ohne Datei gibt es in data_fil gar keine Zeile,
            // ein EXISTS auf "filename = ''" fände also nie etwas. Stattdessen außerhalb
            // des EXISTS: NOT EXISTS einer Zeile mit Dateiname (negiert: EXISTS).
            // Gilt für filename bzw. das bare Feld; bei Zahlen-Subfeldern wie width
            // gibt es kein "leer".
            foreach ($nv as $valIdx => $val) {
                $opt = (string)($optList[$valIdx] ?? '');
                [$op, $value] = $this->extractOperator($opt . $val);
                if (strcasecmp($value, self::EMPTY_FILTER) !== 0) continue;
                unset($nv[$valIdx], $optList[$valIdx]);
                if ($sub !== 'filename' || !in_array($op, ['=', '==', '!', '!!'], true)) {
                    $this->error($this->snippet('error_filter_value_invalid'), $e['key']);
                    continue;
                }
                $negate  = in_array($op, ['!', '!!'], true);
                $outer[] = ($negate ? '' : 'NOT ') . "EXISTS (SELECT 1 FROM `data_fil`"
                    . " WHERE `record_id` = `$tableName`.`{$tableName}_id`"
                    . " AND `fieldname_id` = ?"
                    . " AND `language_id` = " . $this->languageExpr($tableName)
                    . " AND `filename` != '')";
                $outerTexts[] = trim("$fieldLabel.$sub " . ($negate ? $strNot : '=') . ' ' . self::EMPTY_FILTER);
            }
            if (empty($nv)) continue;
            $nv      = array_values($nv);
            $optList = array_values($optList);
            // Dateigröße mit Multiplikator: 500k, 1.5M, 2G (Basis 1024)
            if ($sub === 'size') $nv = array_map([$this, 'expandSizeUnits'], $nv);
            // Endung ohne führenden Punkt (".pdf" wie "pdf")
            if ($sub === 'extension') $nv = array_map(fn($v) => (string)preg_replace('/(^|[=!<>\s])\.(?=\w)/', '$1', (string)$v), $nv);

            // Spalte der data_fil-Zeile (im inneren EXISTS unqualifiziert)
            $colExpr = "`" . $colInfo['col'] . "`";
            $group = $this->buildScalarGroup(
                $colExpr, $nv, $optList, $mode, $colInfo['kind'],
                $fieldLabel . '.' . $sub, $strAnd, $strOr, $strNot
            );
            if ($group[0] === '') continue;

            $inner[] = $group[0];
            $types  .= $group[1];
            array_push($vals, ...$group[2]);
            $texts[] = $group[3];
        }

        if (empty($inner) && empty($outer)) return null;

        // Verschiedene Subfelder derselben Datei (bild.width, bild.height …) gelten
        // zusammen - wie verschiedene Felder der Suchmaske (UND); filtermode wirkt
        // nur auf die Werte EINES Subfelds (buildScalarGroup())
        $glue     = ' AND ';
        $textGlue = " $strAnd ";

        $parts     = [];
        $partTypes = '';
        $partVals  = [];
        if (!empty($inner)) {
            $innerSql = count($inner) === 1 ? $inner[0] : '(' . implode($glue, $inner) . ')';
            $parts[]  = "EXISTS (SELECT 1 FROM `data_fil`"
                      . " WHERE `record_id` = `$tableName`.`{$tableName}_id`"
                      . " AND `fieldname_id` = ?"
                      . " AND `language_id` = " . $this->languageExpr($tableName)
                      . " AND $innerSql)";
            $partTypes = 'i' . $types;
            $partVals  = array_merge([$fid], $vals);
        }
        foreach ($outer as $o) {
            $parts[]    = $o;
            $partTypes .= 'i';
            $partVals[] = $fid;
        }
        $texts = array_merge($texts, $outerTexts);

        return [
            count($parts) === 1 ? $parts[0] : '(' . implode($glue, $parts) . ')',
            $partTypes,
            $partVals,
            count($texts) === 1 ? $texts[0] : '(' . implode($textGlue, $texts) . ')',
        ];
    }

    /**
     * TEXTFELD
     * == und !! erzwingen exakte Suche; kein LIKE-Wildcard.
     * $col trägt ggf. schon die Kollation der Sprache (collate()).
     */
    private function buildTextCondition(string $col, string $value, string $op): array
    {
        if ($op === '==') return ["$col = ?", 's', [$value]];
        if ($op === '!!') return ["$col != ?", 's', [$value]];

        if (str_contains($value, '*')) {
            [$sql, $types, $values] = $this->buildWildcardCondition($col, $value);
            return ($op === '!')
                ? ["NOT $sql", $types, $values]
                : [$sql, $types, $values];
        }
        if ($op === '!') return ["$col != ?", 's', [$value]];
        return ["$col $op ?", 's', [$value]];
    }

    /**
     * Platzhalter-Suche (Hen*, *mann, *berg*) nach den Regeln der Kollation.
     *
     * LIKE vergleicht Zeichen für Zeichen und findet deshalb `weiß` nicht mit
     * 'weiss%', im Deutschen (ä = ae) auch `Müller` nicht mit 'Mue%'. Gesucht wird
     * darum mit allen Schreibvarianten, die die Kollation gleichsetzt
     * (Collation::likeVariants()), verknüpft mit OR. LOCATE() wäre keine Hilfe: MySQL
     * vergleicht dort nur Abschnitte gleicher Byte-Länge, `é` und `e` nie.
     *
     * @param string $col    Spaltenausdruck, ggf. mit COLLATE
     * @param string $value  Suchwert mit mindestens einem *
     * @return array         [sql (geklammert), bindTypes, values]
     */
    private function buildWildcardCondition(string $col, string $value): array
    {
        $values = array_map(
            fn(string $variant) => $this->toLikePattern($variant),
            Collation::likeVariants($value, $this->filterCollation)
        );
        $sql = '(' . implode(' OR ', array_fill(0, count($values), "$col LIKE ?")) . ')';
        return [$sql, str_repeat('s', count($values)), $values];
    }

    /**
     * NUMERISCHES FELD
     */
    private function buildNumericCondition(string $col, string $value, string $op, string $type): array
    {
        $numVal   = is_numeric($value) ? $value + 0 : (int)$value;
        // Nicht endliche Gleitkommawerte abfangen (z. B. '1e400' aus
        // $_REQUEST ergibt INF). mysqli/MySQL akzeptieren INF/NAN nicht als
        // DOUBLE -> wuerde einen SQL-Fehler ausloesen. Fehlerregistrierung
        // erfolgt zentral in buildScalarGroup()/buildScalarExpression().
        if (is_float($numVal) && !is_finite($numVal)) {
            return ['', '', []];
        }
        $bindType = ($type === 'float' || is_float($numVal)) ? 'd' : 'i';
        $op = match($op) {
            '=='     => '=',
            '!', '!!'=> '!=',
            default  => $op,
        };
        return ["$col $op ?", $bindType, [$numVal]];
    }

    /**
     * DATUMSFELD (einheitlich fuer Meta- UND virtuelle Felder).
     *
     * select()/filter() verarbeiten ausschliesslich ISO-Format (zero-padded):
     * YYYY | YYYY-MM | YYYY-MM-DD | YYYY-MM-DD HH:[MM[:SS]]  ('T' als Trenner erlaubt)
     *
     * Wenn Feldtyp 'timestampl' dann Umrechnung des Wertes von PHP-Zeitzone in UTC.
     * Ungueltige/nicht-ISO Eingaben werden ignoriert (leere Bedingung).
     *
     * @return array{0:string,1:string,2:array}  [sql, bindTypes, values] oder ['', '', []]
     */
    private function buildDateConditionUnified(string $col, string $value, string $op, string $type): array
    {
        $value = trim($value);

        // Relative Angaben: now / today in der Zeitzone des Aufrufs (User bzw. Sprache,
        // wie getippte Daten), utc_now / utc_today in UTC - je mit Versatz (+3d, -2h,
        // +30m, -10s). "today" ohne Stunden-/Minuten-/Sekunden-Versatz ist ein ganzer
        // Tag (wie "2026-09-27"), "now" eine Sekunde. UTC-Werte werden unten nicht noch
        // einmal umgerechnet.
        $isUtc = false;
        if (preg_match('/^(utc_now|utc_today|now|today)(?:([+\-]\d+)([smhd]))?$/i', $value, $rel)) {
            $base = strtolower($rel[1]);
            $isUtc = str_starts_with($base, 'utc_');
            $isDay = str_ends_with($base, 'today');
            $dateTime = new \DateTime($isDay ? 'today' : 'now', $isUtc ? new \DateTimeZone('UTC') : null);
            $unit = strtolower($rel[3] ?? '');
            if ($unit !== '') {
                $dateTime->modify($rel[2] . ' ' . ['s' => 'seconds', 'm' => 'minutes', 'h' => 'hours', 'd' => 'days'][$unit]);
            }
            $value = $dateTime->format(($isDay && in_array($unit, ['', 'd'], true)) ? 'Y-m-d' : 'Y-m-d H:i:s');
        }

        // ISO-Teilangabe (zero-padded) pruefen
        $pattern = '/^(\d{4})(?:-(\d{2})(?:-(\d{2})(?:[ T](\d{2})(?::(\d{2})(?::(\d{2}))?)?)?)?)?$/';
        if (!preg_match($pattern, $value, $m)) {
            return ['', '', []];
        }

        $hasMonth = isset($m[2]) && $m[2] !== '';
        $hasDay   = isset($m[3]) && $m[3] !== '';
        $hasHour  = isset($m[4]) && $m[4] !== '';
        $hasMin   = isset($m[5]) && $m[5] !== '';
        $hasSec   = isset($m[6]) && $m[6] !== '';

        $year  = (int)$m[1];
        $month = $hasMonth ? (int)$m[2] : 1;
        $day   = $hasDay   ? (int)$m[3] : 1;
        $hour  = $hasHour  ? (int)$m[4] : 0;
        $min   = $hasMin   ? (int)$m[5] : 0;
        $sec   = $hasSec   ? (int)$m[6] : 0;

        // Gueltigkeit pruefen (sonst ungueltige Eingabe -> ignorieren)
        if (!checkdate($month, $day, $year)) return ['', '', []];
        if ($year < 1000 || $year > 9999)    return ['', '', []];
        if ($hour > 23 || $min > 59 || $sec > 59) return ['', '', []];

        // Periodenanfang
        $lower = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec);

        // Periodenende abhaengig von der feinsten angegebenen Komponente
        $month_end = $hasMonth ? $month : 12;
        if ($hasDay) {
            $day_end = $day;
        } else {
            // Letzten Tag des Monats ermitteln
            $dateString = sprintf('%04d-%02d-01', $year, $month_end);
            $date       = new \DateTimeImmutable($dateString);
            $day_end    = (int)$date->format('t');
        }
        $hour_end = $hasHour ? $hour : 23;
        $min_end  = $hasMin  ? $min : 59;
        $sec_end  = $hasSec  ? $sec : 59;

        $upper = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month_end, $day_end, $hour_end, $min_end, $sec_end);

        if ($type == 'timestampl' && !$isUtc) {
            // Typ 'timestampl' manuelle Umrechnung in UTC (utc_now/utc_today sind es schon)
            $pp = 'CONVERT_TZ(?, @@session.time_zone, \'UTC\')';
        } else {
            $pp = '?';
        }

        // Operator -> SQL (Wertebereiche, optimale Indexnutzung)
        switch ($op) {
            case '=':   return ["($col >= $pp AND $col <= $pp)", 'ss', [$lower, $upper]];
            case '!':   return ["NOT ($col >= $pp AND $col <= $pp)", 'ss', [$lower, $upper]];
            case '>':   return ["$col > $pp",  's', [$upper]];
            case '>=':  return ["$col >= $pp", 's', [$lower]];
            case '<':   return ["$col < $pp",  's', [$lower]];
            case '<=':  return ["$col <= $pp", 's', [$upper]];
            case '==':  return ["$col = $pp",  's', [$lower]];
            case '!!':  return ["$col != $pp", 's', [$lower]];
            default:    return ['', '', []];
        }
    }

    /**
     * ZEITFELD (Typ 'time', data_tim).
     *
     * ISO-Format, z. B. "19:00". Auffuellung ueber String-Maske.
     * Zeitzonen: KEINE Umrechnung.
     *
     * @return array{0:string,1:string,2:array}  [sql, 's', [string]] oder ['', '', []]
     */
    private function buildTimeConditionUnified(string $col, string $value, string $op): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['', '', []];
        }

        $parts      = explode(':', $value);
        $hasMinutes = isset($parts[1]) && $parts[1] !== '';
        $hasSeconds = isset($parts[2]) && $parts[2] !== '';

        $hours   = abs((int)($parts[0] ?? 0));
        $minutes = abs((int)($parts[1] ?? 0));
        $seconds = abs((int)($parts[2] ?? 0));

        if ($hours > 23 || $minutes > 59 || $seconds > 59) {
            return ['', '', []];
        }

        // Periodenanfang
        $lower = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);

        // Periodenende
        $minutesEnd = $hasMinutes ? $minutes : 59;
        $secondsEnd = $hasSeconds ? $seconds : 59;
        $upper      = sprintf('%02d:%02d:%02d', $hours, $minutesEnd, $secondsEnd);

        // Operator -> SQL (Wertebereiche, optimale Indexnutzung)
        switch ($op) {
            case '=':
                if ($hasSeconds) return ["$col = ?", 's', [$lower]];
                else             return ["($col >= ? AND $col <= ?)", 'ss', [$lower, $upper]];
            case '!':
                if ($hasSeconds) return ["$col != ?", 's', [$lower]];
                else             return ["NOT ($col >= ? AND $col <= ?)", 'ss', [$lower, $upper]];
            case '>':   return ["$col > ?",  's', [$upper]];
            case '>=':  return ["$col >= ?", 's', [$lower]];
            case '<':   return ["$col < ?",  's', [$lower]];
            case '<=':  return ["$col <= ?", 's', [$upper]];
            case '==':  return ["$col = ?",  's', [$lower]];
            case '!!':  return ["$col != ?", 's', [$lower]];
            default:    return ['', '', []];
        }
    }

    /**
     * FULLTEXT (MATCH ... AGAINST ... IN BOOLEAN MODE).
     * $col muss eine echte (indizierte) Spalte sein – bei virtuellen
     * Fulltext-Feldern ist ein FULLTEXT-Index auf data_ftx.data noetig.
     *
     * Der Suchbegriff wird wie der Index nach den Regeln der Suchsprache gefaltet
     * (indexText(), Collation::fold()). Sucht eine Abfrage in mehreren Sprachen
     * (Backend, Instanzen verschiedener Sprachen) und ergibt das verschiedene
     * Suchbegriffe, wird mit jedem gesucht (OR).
     *
     * @return array{0:string,1:string,2:array} [sql, bindTypes, values]
     */
    private function buildFulltextCondition(string $col, string $value): array
    {
        $searches = [];
        foreach ($this->filterLanguageIds ?: [null] as $languageId) {
            $search = $this->fulltextSearchString($value, $languageId);
            if ($search !== '') $searches[$search] = true;
        }
        $searches = array_keys($searches);
        if (!$searches) {
            // Nach dem Bereinigen bleibt kein Wort (nur Tags, Satzzeichen): nichts
            // finden - eine weggelassene Bedingung lieferte ALLE Datensätze
            return ['1=0', '', []];
        }
        $sql = implode(' OR ', array_fill(0, count($searches), "MATCH($col) AGAINST(? IN BOOLEAN MODE)"));
        return [count($searches) > 1 ? "($sql)" : $sql, str_repeat('s', count($searches)), $searches];
    }

    /**
     * Suchbegriff für MATCH ... AGAINST im Boolean Mode, gefaltet für eine Sprache.
     *
     * @param int|null $languageId  null = nicht falten (andere Tabellen als `record`)
     * @return string               '' = kein Wort übrig
     */
    private function fulltextSearchString(string $value, ?int $languageId): string
    {
        $processedTokens = [];

        foreach (explode(' ', $value) as $token) {
            $token = trim($token);
            if ($token === '') continue;

            // Fuehrende MySQL-Operatoren (+, -, <, >, ~) und nachfolgenden Platzhalter (*)
            if (preg_match('/^([+\-~<>]*)(.*?)(\*?)$/u', $token, $matches)) {
                // Nur EINEN fuehrenden Boolean-Operator zulassen.
                // Kombinationen wie '+-wort' erzeugen in MySQL einen Syntaxfehler
                // im Boolean-Parser.
                $prefix   = $matches[1] === '' ? '' : $matches[1][0];
                $coreWord = $matches[2];
                $suffix   = $matches[3];

                if ($coreWord !== '') {
                    $cleanedWord = $this->indexText($coreWord, true, $languageId);
                    // '@' ist im Boolean Mode ein Distanz-Operator
                    // ("phrase" @n) und fuehrt frei stehend zu einem MySQL-
                    // Syntaxfehler -> aus dem Suchwort entfernen.
                    $cleanedWord = str_replace('@', '', $cleanedWord);
                    $cleanedWord = trim($cleanedWord);
                    if ($cleanedWord === '') continue;
                    if (!str_contains($cleanedWord, ' ')) {
                        $processedTokens[] = $prefix . $cleanedWord . $suffix;
                    }
                    elseif ($suffix === '') {
                        // "sofa-weiss" -> Wortfolge "sofa weiss" (wie indexText() speichert)
                        $processedTokens[] = $prefix . '"' . $cleanedWord . '"';
                    }
                    else {
                        // mit Platzhalter keine Phrase möglich: jedes Wort mit dem
                        // Operator, der Platzhalter am letzten
                        $words = explode(' ', $cleanedWord);
                        $last = array_pop($words);
                        foreach ($words as $word) $processedTokens[] = $prefix . $word;
                        $processedTokens[] = $prefix . $last . $suffix;
                    }
                }
            }
        }
        return implode(' ', $processedTokens);
    }

    /**
     * JSON-FELD (Typ 'json').
     *
     * Flaches JSON-Array, Suche per JSON_CONTAINS; * ist KEIN Platzhalter.
     *   =  / ==  ->     JSON_CONTAINS(col, JSON_QUOTE(?))
     *   !  / !!  -> NOT JSON_CONTAINS(col, JSON_QUOTE(?))
     *
     * @return array{0:string,1:string,2:array}  [sql, 's', [value]]
     */
    private function buildJsonCondition(string $col, string $value, string $op): array
    {
        if (in_array($op, ['!', '!!'], true)) {
            return ["NOT JSON_CONTAINS($col, JSON_QUOTE(?))", 's', [$value]];
        }
        return ["JSON_CONTAINS($col, JSON_QUOTE(?))", 's', [$value]];
    }

    /**
     * Normalisiert den Rohwert (String oder Array) zu einem flachen String-Array.
     * OR/AND-Ausdruecke im String werden als ganzer Wert weitergereicht.
     */
    private function normalizeValues(mixed $rawValue): array
    {
        $items  = is_array($rawValue) ? $rawValue : [$rawValue];
        $result = [];
        foreach ($items as $item) {
            // Nur skalare Werte akzeptieren. Verschachtelte Request-
            // Arrays (filter[title][0][]=x) haetten per (string)-Cast den
            // Literalwert "Array" erzeugt.
            if (!is_scalar($item)) continue;
            $item = (string)$item;
            $item = preg_replace('/\s+/', ' ', $item);
            $item = trim($item);
            if ($item === '') continue;

            // OR/AND -> nicht per Komma splitten, gesamte Logik steckt im Ausdruck
            if (preg_match('/\s+(?:OR|AND)\s+/i', $item)) {
                $result[] = $item;
                continue;
            }

            $parts = array_map('trim', explode(',', $item));
            foreach ($parts as $part) {
                if ($part !== '') {
                    $result[] = $part;
                }
            }
        }
        return $result;
    }

    
    /**
     * filterop-Liste fuer einen Feldschluessel parsen
     * (kommaseparierte Operatorenliste, index-synchron zu den Werten).
     */
    private function parseOptList(array $optReq, string $key): array
    {
        if (!isset($optReq[$key])) return [];
        $raw = $optReq[$key];
        // filterop kann per Request auch als Array (filterop[preis][]=..)
        // ankommen -> Elemente skalar uebernehmen statt (string)-Cast auf Array
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $r) {
                if (is_scalar($r)) $out[] = trim((string)$r);
            }
            return $out;
        }
        if ((string)$raw === '') return [];
        return array_map('trim', explode(',', (string)$raw));
    }

    /**
     * Bedingung für den Filterwert "(empty)": der Wert der Sprache ist leer oder ein
     * Fallback aus der Hauptsprache (`empty` = 1, siehe hasEmptyFlag()).
     *
     * "Leer" je Typ: Text/Verschlüsselt/JSON ein Leerstring, Datum die Untergrenze
     * DATE_MIN (kein Datum gesetzt), Zeit NULL bzw. '' - Zahlen kennen kein Leer,
     * dort zählt allein das Fallback-Kennzeichen. Bei Spalten ohne `empty`
     * (Metafelder, data_dft, data_fil) bleibt nur der Leer-Vergleich.
     *
     * @param string $colExpr  Spaltenausdruck, z. B. `_vf_0`.`data` oder `data`
     * @param string $type     Feldtyp
     */
    private function emptyCondition(string $colExpr, string $type): string
    {
        $parts = [];
        if (in_array($type, $this->dateTypes, true)) {
            $parts[] = "$colExpr <= '" . self::DATE_MIN . "'";
        }
        elseif ($type === 'time') {
            $parts[] = "($colExpr IS NULL OR $colExpr = '')";
        }
        elseif (!in_array($type, $this->numericTypes, true)) {
            // text, chars, encrypted (hash von '' ist ''), json
            $parts[] = "$colExpr = ''";
        }
        if ($this->hasEmptyFlag($type) && ($emptyExpr = $this->emptyFlagExpr($colExpr)) !== null) {
            $parts[] = "$emptyExpr = 1";
        }
        if (empty($parts)) return '1=0';
        return count($parts) === 1 ? $parts[0] : '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * Spaltenausdruck der `empty`-Spalte zu einem data_*-Spaltenausdruck - null,
     * wenn die Spalte nicht zu einer data_*-Tabelle mit `empty` gehört.
     *
     *     `_vf_0`.`data`   -> `_vf_0`.`empty`     (Skalar-Join, registerScalarJoin())
     *     `_rsd_3`.`data`  -> `_rsd_3`.`empty`    (Feld des Relations-Ziels)
     *     `data`           -> `empty`             (inneres EXISTS auf data_*)
     *     `record`.`title`, `_dft_1`.`data` ...   -> null
     */
    private function emptyFlagExpr(string $colExpr): ?string
    {
        if ($colExpr === '`data`') return '`empty`';
        if (preg_match('/^`(_vf_\d+|_rsd_\d+)`\.`[a-z_]+`$/', $colExpr, $m)) {
            return '`' . $m[1] . '`.`empty`';
        }
        return null;
    }

    /**
     * OPERATOR-PRAEFIX extrahieren.
     *
     * Ein Wert, der mit einem HTML-Tag beginnt (`<section>`, `</p>`, `<!--`), ist
     * kein Vergleich "kleiner als" - sonst fände die Suche nach Code in Templates mit
     * `<section>` alles, was alphabetisch vor "section>" liegt. Er gilt als Wert;
     * enthalten statt gleich: `*<section>*`.
     */
    private function extractOperator(string $value): array
    {
        if (preg_match('~^</?[a-z!][^<>]*>~i', $value)) {
            return ['=', $value];
        }
        $twoChar    = substr($value, 0, 2);
        $oneChar    = substr($value, 0, 1);
        $twoCharOps = ['==', '>=', '<=', '>>', '<<', '!!', '!='];
        $oneCharOps = ['=', '>', '<', '!'];

        if (in_array($twoChar, $twoCharOps, true)) {
            $op = match($twoChar) {
                '>>'    => '>',
                '<<'    => '<',
                '!='    => '!',
                default => $twoChar,
            };
            return [$op, substr($value, 2)];
        }
        if (in_array($oneChar, $oneCharOps, true)) {
            return [$oneChar, substr($value, 1)];
        }
        return ['=', $value];
    }

    /**
     * Wandelt einen Such-String mit * in ein LIKE-Pattern um.
     *   * -> %      . -> \.      % und _ im Original maskieren
     */
    private function toLikePattern(string $value): string
    {
        $value = str_replace(['%', '_'], ['\%', '\_'], $value);
        $value = str_replace('.', '\.', $value);
        $value = str_replace('*', '%', $value);
        return $value;
    }

    /**
     * Liefert die zugehoerige data_*-Tabelle fuer einen Feldtyp.
     */
    private function virtualDataTable(string $type): string
    {
        return 'data_' . ($this->dataSuffix[$type] ?? 'txt');
    }

    /**
     * Loest einen virtuellen Feldnamen ueber die Tabelle `fieldname` auf.
     * Ergebnis wird gecacht.
     *
     * @return array{fieldname_id:int,type:string}|null  null = Feld nicht gefunden
     */
    public function resolveVirtualField(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') return null;

        if (isset($this->fieldnameCache[$name])) {
            return $this->fieldnameCache[$name] ?: null;
        }

        // Laufendes Result sichern (Unterabfrage-Sicherheit)
        $this->db->pushResult();
        $this->db->query(
            "SELECT `fieldname_id`, `type` FROM `fieldname` WHERE `name` = ? LIMIT 1",
            's',
            [$name]
        );
        $row = $this->db->fetch();
        $this->db->popResult();

        if (!$row) {
            $this->fieldnameCache[$name] = false;
            return null;
        }
        $resolved = [
            'fieldname_id' => (int)$row['fieldname_id'],
            'type'         => (string)$row['type']
        ];
        $this->fieldnameCache[$name] = $resolved;
        return $resolved;
    }

    /**
     * Zuordnung Feldname -> Typ und die vereinfachten Bereichsschemata vergessen -
     * nach einer Typänderung eines Feldes im selben Request (Field::convert()).
     */
    public function clearFieldCaches(): void
    {
        $this->fieldnameCache = [];
        $this->virtualSchemaCache = [];
    }

    /**
     * Nested-Set-Filter fuer parent_id.
     *
     * Baut eine WHERE-Bedingung, die alle Nachkommen der angegebenen Eltern-Knoten
     * findet. Die Tiefenbeschraenkung ist relativ zum jeweiligen Eltern-Knoten.
     *
     * $depthLimit:  < 0 → alle Nachkommen; 1 → nur direkte Kinder; n → bis relative Tiefe n
     *
     * BEREICHSGRENZE
     * Jeder Inhaltsbereich fuehrt seinen eigenen Baum, lft/rgt beginnen dort jeweils
     * wieder bei 1 (siehe TreeManagerTrait). Der Bereich des Eltern-Knotens wird
     * deshalb mitgefiltert - ohne ihn wuerden Datensaetze fremder Bereiche in denselben
     * Zahlenbereich fallen. Ein Datensatz aus einem Bereich OHNE Baum hat
     * lft = rgt = 0 und damit keine Nachkommen.
     *
     * WURZELEBENE
     * `parent_id` = NULL meint die Nachkommen der (gedachten) Wurzel, also alle
     * Datensaetze; `depth` ist 0-basiert, die Wurzelknoten selbst haben depth = 0.
     * Datensaetze aus Bereichen ohne Baum haben ebenfalls depth = 0 und gelten damit
     * als Wurzelknoten - `parent_id=&depth=1` liefert dort wie erwartet alles.
     *
     * @param bool $hasAreaId  Tabelle hat eine `area_id` (also: `record`)
     * @return array{sql:string, types:string, values:array, text:string}|null
     */
    private function buildParentCondition(
        string $tableName,
        array  $normalizedValues,
        int    $depthLimit,
        bool   $hasAreaId = false
    ): ?array {
        // IDs sammeln; leerer String / 'null' → NULL-Wurzel-Eintrag
        $ids     = [];
        $hasNull = false;
        foreach ($normalizedValues as $val) {
            $val = trim((string)$val);
            if ($val === '' || strtolower($val) === 'null') {
                $hasNull = true;
            } elseif (is_numeric($val) && (int)$val > 0) {
                $ids[] = (int)$val;
            } else {
                $this->error($this->snippet('error_filter_value_invalid'), 'parent_id');
            }
        }
        if (empty($ids) && !$hasNull) return null;

        $tbl       = "`$tableName`";
        $fieldName = $tableName . '_id';
        $parts     = [];
        $bindTypes = '';
        $values    = [];

        // Kinder von Knoten mit bekannter ID (Sub-Queries auf PRIMARY KEY)
        foreach ($ids as $id) {
            $part       = "(`lft` > (SELECT `lft` FROM $tbl WHERE `$fieldName` = ?)"
                        . " AND `rgt` < (SELECT `rgt` FROM $tbl WHERE `$fieldName` = ?)";
            $bindTypes .= 'ii';
            $values[]   = $id;
            $values[]   = $id;
            if ($depthLimit > 0) {
                $part      .= " AND `depth` <= (SELECT `depth` FROM $tbl WHERE `$fieldName` = ?) + $depthLimit";
                $bindTypes .= 'i';
                $values[]   = $id;
            }
            if ($hasAreaId) {
                // Bereichsgrenze: lft/rgt zaehlen je Inhaltsbereich
                $part      .= " AND `area_id` = (SELECT `area_id` FROM $tbl WHERE `$fieldName` = ?)";
                $bindTypes .= 'i';
                $values[]   = $id;
            }
            $parts[] = $part . ')';
        }

        // Kinder der Wurzel (parent_id IS NULL) -> Filterung ueber depth (0-basiert:
        // Wurzelknoten haben depth = 0, 'nur direkte Kinder' ist also depth <= 0)
        if ($hasNull) {
            if ($depthLimit > 0) {
                $parts[] = '`depth` <= ' . ($depthLimit - 1);
            } else {
                $parts[] = '`depth` >= 0';   // alle Nachkommen der Wurzel = alle Datensaetze
            }
        }

        if (empty($parts)) return null;

        $sql = count($parts) === 1
            ? $parts[0]
            : '(' . implode(' OR ', $parts) . ')';

        // Lesbarer Text fuer Filteranzeige
        $idList    = implode(', ', $ids);
        $nullText  = $hasNull ? ($ids ? ', NULL' : 'NULL') : '';
        $depthText = $depthLimit > 0
            ? ' (' . $this->snippet('filter_depth_limit') . ' ' . $depthLimit . ')'
            : '';
        $text = $this->snippet('filter_parent_id') . ' = ' . $idList . $nullText . $depthText;

        return [
            'sql'    => $sql,
            'types'  => $bindTypes,
            'values' => $values,
            'text'   => $text,
        ];
    }

    /**
     * JOIN-Bedingung fuer ein n:1-RELATION-/POINTER-Feld
     * (Typ 'relation1'/'pointer1', wird von filter() vorab geprueft).
     *
     * Ersetzt fuer diese Typen das korrelierte EXISTS aus buildRelationExists():
     * data_rel liefert hoechstens EINE Zeile je (src_id, fieldname_id) ->
     * ein LEFT JOIN erzeugt keine Mehrfachzeilen und ist semantisch
     * aequivalent, gibt dem Optimizer aber volle Freiheit (Index-Zugriff
     * ueber data_rel statt korrelierter Subquery je Zeile).
     *
     * Aequivalenzen (bezogen auf die eine data_rel-Zeile):
     *   EXISTS(dest_id = X)      ->  `rel`.`dest_id` = X        (NULL -> kein Treffer)
     *   NOT EXISTS(dest_id = X)  ->  (`rel`.`dest_id` IS NULL OR `rel`.`dest_id` != X)
     *   EXISTS(... Subfeld ...)  ->  Bedingung auf gejointe Ziel-Spalte
     *                                (NULL-verwerfend -> wie EXISTS)
     *
     * LEFT (nicht INNER) JOIN: noetig fuer die NOT-EXISTS-Semantik der
     * Negation (Datensaetze OHNE Verknuepfung erfuellen '!id'). Positive
     * Bedingungen verwerfen NULL -> der Optimizer wandelt den LEFT JOIN
     * dann selbst in einen INNER JOIN um.
     * Ein ggf. registrierter, aber ungenutzt gebliebener JOIN (z. B. alle
     * Werte ungueltig) ist unschaedlich: LEFT JOIN mit max. einer Zeile
     * schraenkt nichts ein und erzeugt keine Dubletten.
     * ACHTUNG: gleiche Bestands-Garantie wie bei der Sortierung - enthaelt
     * data_rel entgegen der n:1-Garantie mehrere Zeilen je src_id, entstehen
     * Dubletten (siehe registerRelationJoin()).
     *
     * fieldname_id wird NICHT in den WHERE-Parametern gebunden, sondern
     * steckt im JOIN (joinCtx) - Bind-Reihenfolge: JOINs vor WHERE.
     *
     * @return array{0:string,1:string,2:array,3:string}|null
     */
    private function buildRelationJoinGroup(
        string $tableName,
        string $fieldLabel,
        int    $fid,
        array  $entries,
        string $mode,
        array  $optReq,
        array  &$joinCtx,
        string $strAnd,
        string $strOr,
        string $strNot
    ): ?array {
        $predicates = [];
        foreach ($entries as $e) {
            $sub = $e['sub'];
            $nv  = $this->normalizeValues($e['raw']);
            // Ziel-IDs direkt (main bzw. main.record_id)
            if ($sub === '' || $sub === 'record_id') {
                $pos = [];
                $neg = [];
                foreach ($nv as $v) {
                    [$op, $val] = $this->extractOperator($v);
                    if (strcasecmp($val, self::EMPTY_FILTER) === 0) {
                        $predicates[] = $this->relationEmptyPredicate($tableName, $fieldLabel, $fid, $op === '!' || $op === '!!', $strNot);
                        continue;
                    }
                    if (!is_numeric($val)) {
                        $this->error($this->snippet('error_filter_value_invalid'), $e['key']);
                        continue;
                    }
                    if (in_array($op, ['!', '!!'], true)) $neg[] = (int)$val;
                    else                                  $pos[] = (int)$val;
                }
                // JOIN erst registrieren, wenn Ziel-IDs ihn brauchen - ein
                // ungenutzter JOIN ist nur harmlos, solange data_rel die n:1-Garantie hält
                if ($pos || $neg) {
                    $relAlias = $this->registerRelationBaseJoin($tableName, $fid, $joinCtx, 'filter');
                    $destCol  = "`$relAlias`.`dest_id`";
                }
                if ($pos) {
                    if ($mode === 'AND') {
                        // wie im EXISTS-Pfad: je Ziel-ID eine eigene Bedingung
                        // (bei n:1 mit mehreren IDs zwangslaeufig leer -
                        // identisch zur bisherigen EXISTS-Variante)
                        foreach ($pos as $pid) {
                            $predicates[] = [
                                "$destCol = ?",
                                'i',
                                [$pid],
                                $fieldLabel . ' = ' . $pid,
                            ];
                        }
                    } else {
                        // OR -> IN-Liste auf der gejointen Zeile
                        $ph = implode(',', array_fill(0, count($pos), '?'));
                        $predicates[] = [
                            "$destCol IN ($ph)",
                            str_repeat('i', count($pos)),
                            $pos,
                            $fieldLabel . ' = ' . implode(', ', $pos),
                        ];
                    }
                }
                foreach ($neg as $nid) {
                    // NOT-EXISTS-Semantik: auch Datensaetze OHNE Verknuepfung treffen
                    $predicates[] = [
                        "($destCol IS NULL OR $destCol != ?)",
                        'i',
                        [$nid],
                        $fieldLabel . ' ' . $strNot . ' ' . $nid,
                    ];
                }
                continue;
            }
            $subLabel = $fieldLabel . '.' . $sub;
            $optList  = $this->parseOptList($optReq, $e['key']);
            // JOIN-Kette (data_rel + record bzw. data_*) registrieren.
            // Validiert das Subfeld (Metafeld / virtuelles Skalarfeld,
            // relation.relation / file verboten) und registriert Fehler selbst.
            $colExpr = $this->registerRelationJoin(
                $tableName, $fid, $joinCtx, $sub, $e['key'], 'filter'
            );
            if ($colExpr === null) continue;
            // Typ des Subfeldes fuer den Bedingungsaufbau ermitteln
            // (resolveVirtualField ist gecacht -> kein zweiter DB-Zugriff)
            // '_default' ist weder Meta- noch fieldname-Feld -> Typ 'text'
            if ($sub === '_default') {
                $subType = 'text';
            } elseif (isset($this->schema[$sub])) {
                $subType = $this->schema[$sub]['type'] ?? 'text';
            } else {
                $subType = $this->resolveVirtualField($sub)['type'];
            }
            $group = $this->buildScalarGroup(
                $colExpr, $nv, $optList, $mode, $subType, $subLabel, $strAnd, $strOr, $strNot
            );
            if ($group[0] === '') continue;
            $predicates[] = $group;
        }
        if (empty($predicates)) return null;
        $glue     = ($mode === 'AND') ? ' AND ' : ' OR ';
        $textGlue = ($mode === 'AND') ? " $strAnd " : " $strOr ";
        $sqlParts = [];
        $types    = '';
        $vals     = [];
        $texts    = [];
        foreach ($predicates as $pr) {
            $sqlParts[] = $pr[0];
            $types     .= $pr[1];
            array_push($vals, ...$pr[2]);
            $texts[]    = $pr[3];
        }
        $sql  = count($sqlParts) === 1 ? $sqlParts[0] : '(' . implode($glue, $sqlParts) . ')';
        $text = count($texts) === 1    ? $texts[0]    : '(' . implode($textGlue, $texts) . ')';
        return [$sql, $types, $vals, $text];
    }

    /**
     * Registriert (oder findet) den Basis-LEFT-JOIN auf data_rel fuer ein
     * n:1-Relation-Feld. Gemeinsame Grundlage fuer die Filterung
     * (buildRelationJoinGroup()) und die JOIN-Kette in registerRelationJoin().
     * Wiederverwendung ueber 'map' (Key 'relj:<fieldname_id>') - Filter und
     * Sortierung teilen sich denselben Alias. Da filter() vor der
     * Sortier-Verarbeitung laeuft, traegt ein gemeinsam genutzter JOIN dann
     * Phase 'filter' und erscheint auch in der COUNT-Query (harmlos:
     * LEFT JOIN mit max. einer Zeile veraendert die Zaehlung nicht).
     */
    private function registerRelationBaseJoin(
        string $tableName,
        int    $relFid,
        array  &$ctx,
        string $phase
    ): string {
        $baseKey = 'relj:' . $relFid;
        if (isset($ctx['map'][$baseKey])) {
            return $ctx['map'][$baseKey];
        }
        $relAlias = '_rs_' . $ctx['seq']++;
        $ctx['joins'][$relAlias] = [
            'sql'    => "LEFT JOIN `data_rel` AS `$relAlias`"
                      . " ON `$relAlias`.`src_id` = `$tableName`.`{$tableName}_id`"
                      . " AND `$relAlias`.`fieldname_id` = ?"
                      // Kanten auf Arbeitskopien auslassen: Check-out legt auch die
                      // eingehenden Kanten auf die Kopie - das Feld haette dann zwei
                      // Zeilen, der JOIN lieferte Dubletten
                      . " AND NOT EXISTS (SELECT 1 FROM `record` `{$relAlias}_t`"
                      . " WHERE `{$relAlias}_t`.`record_id` = `$relAlias`.`dest_id` AND `{$relAlias}_t`.`tmp` = 1)",
            'types'  => 'i',
            'values' => [$relFid],
            'phase'  => $phase,
        ];
        $ctx['map'][$baseKey] = $relAlias;
        return $relAlias;
    }

    /**
     * Registriert die LEFT-JOIN-Kette fuer ein n:1-RELATION-/POINTER-
     * Feld (Typ 'relation1'/'pointer1', wird vom Aufrufer ueber
     * $this->singleRelationTypes vorab geprueft!). Genutzt von der
     * SORTIERUNG (Phase 'sort') und auch von der FILTERUNG
     * (Phase 'filter', siehe buildRelationJoinGroup()).
     * Beide teilen sich ueber 'map' dieselbe JOIN-Kette, d.h.
     * filter[myrelation.title] und sort=myrelation.title erzeugen nur
     * EINEN data_rel-Zugriff.
     *
     *   sort=myrelation           -> Metafeld 'changed_at' des Ziels (Default)
     *   sort=myrelation.title     -> virtuelles Skalarfeld des Ziels
     *   sort=myrelation.status_id -> Metafeld des Ziels
     *   relation.relation(.x)     -> nicht erlaubt (error_relation_relation)
     *   relation.file             -> nicht erlaubt
     *
     * JOIN-Kette (Phase 'sort' -> nur im Haupt-SELECT, nicht in der COUNT-Query;
     * Phase 'filter' -> auch in der COUNT-Query):
     *   LEFT JOIN data_rel _rs_n   (src_id = record_id AND fieldname_id = ?)
     *   LEFT JOIN record   _rsr_n  bei Metafeld:        record_id = _rs_n.dest_id
     *   LEFT JOIN data_*   _rsd_n  bei virtuellem Feld: record_id = _rs_n.dest_id
     *                                                   AND fieldname_id = ?
     *
     * LEFT (nicht INNER): Datensaetze ohne Verknuepfung duerfen beim reinen
     * Sortieren nicht aus dem Ergebnis fallen (NULL sortiert ans Ende/den Anfang);
     * beim Filtern wird LEFT fuer die NOT-EXISTS-Semantik der Negation benoetigt.
     * Positive Filter-Bedingungen sind NULL-verwerfend -> der Optimizer
     * wandelt den LEFT JOIN dann selbst in einen INNER JOIN um.
     * Bei den Typen 'relation1'/'pointer1' liefert data_rel hoechstens EINE
     * Zeile je src_id -> keine Zeilen-Inflation, kein DISTINCT noetig.
     * ACHTUNG: Enthaelt der Bestand entgegen der Typ-Garantie mehrere Zeilen
     * je src_id (z. B. nach nachtraeglicher Typ-Umstellung von 'relation' auf
     * 'relation1' ohne Datenbereinigung), entstehen Dubletten im Ergebnis.
     * Wiederverwendung der Aliase ueber 'map': Mehrfachsortierung wie
     * "myrelation.title, myrelation.status_id" nutzt denselben data_rel-JOIN.
     *
     * @param string $tableName  Basistabelle (z. B. 'record')
     * @param int    $relFid     fieldname_id des Relation-Feldes
     * @param array  &$ctx       JOIN-Kontext
     * @param string $sub        Subfeld ('' = Default 'changed_at')
     * @param string $fullName   kompletter Feld-/Sortierschluessel (fuer Fehlermeldungen)
     * @param string $phase      'filter' | 'sort'
     * @return ?string           Spaltenausdruck oder null (Fehler registriert)
     */
    private function registerRelationJoin(
        string $tableName,
        int    $relFid,
        array  &$ctx,
        string $sub,
        string $fullName,
        string $phase = 'sort'
    ): ?string {
        // Fehler-Snippets je Phase, damit die Meldungen den bisherigen
        // Pfaden entsprechen (Filter: error_field_unknown_type, Sortierung:
        // error_sort_field_unknown / error_sort_field_invalid).
        $errUnknown = ($phase === 'filter') ? 'error_field_unknown_type' : 'error_sort_field_unknown';
        $errInvalid = ($phase === 'filter') ? 'error_field_unknown_type' : 'error_sort_field_invalid';
        // Ohne Subfeld: Metafeld 'changed_at' des verknuepften Datensatzes
        if ($sub === '') $sub = 'changed_at';
        // '_default' des ZIEL-Datensatzes (data_dft, ohne fieldname_id).
        // Kein Metafeld und nicht ueber `fieldname` aufloesbar -> eigener Zweig
        // VOR der Subfeld-Validierung (analog registerDefaultJoin(), aber
        // gejoint auf dest_id der Relation).
        if ($sub === '_default') {
            // Basis-JOIN auf data_rel (je Relation-Feld nur einmal)
            $relAlias = $this->registerRelationBaseJoin($tableName, $relFid, $ctx, $phase);
            $dftKey = 'relj:' . $relFid . ':dft';
            if (isset($ctx['map'][$dftKey])) {
                $dftAlias = $ctx['map'][$dftKey];
            } else {
                $dftAlias = '_rsdft_' . $ctx['seq']++;
                // Sprache des ZIEL-Datensatzes: dafür dessen record-Zeile dazunehmen
                $recAlias = $this->registerRelationRecordJoin($relFid, $relAlias, $ctx, $phase);
                $ctx['joins'][$dftAlias] = [
                    // KEINE fieldname_id in der ON-Bedingung nötig
                    'sql'    => "LEFT JOIN `data_dft` AS `$dftAlias`"
                              . " ON `$dftAlias`.`record_id` = `$relAlias`.`dest_id`"
                              . " AND `$dftAlias`.`language_id` = " . $this->languageExpr($recAlias),
                    'types'  => '',
                    'values' => [],
                    'phase'  => $phase,
                ];
                $ctx['map'][$dftKey] = $dftAlias;
            }
            return "`$dftAlias`.`data`";
        }
        // Subfeld VOR der JOIN-Registrierung validieren, damit bei Fehlern
        // kein ungenutzter data_rel-JOIN im Kontext zurueckbleibt.
        $isMetaSub = isset($this->schema[$sub]);
        $subField  = null;
        if (!$isMetaSub) {
            $subField = $this->resolveVirtualField($sub);
            if ($subField === null) {
                // unbekanntes Subfeld registrieren
                $this->error($this->snippet($errUnknown), $fullName);
                return null;
            }
            if (in_array($subField['type'], $this->relationTypes, true)) {
                // Relation.Relation nicht erlaubt (analog Filter)
                $this->error($this->snippet('error_relation_relation'), $fullName);
                return null;
            }
            if ($subField['type'] === 'file') {
                // zu tief verschachtelt -> nicht unterstuetzt
                $this->error($this->snippet($errInvalid), $fullName);
                return null;
            }
        }
        // Basis-JOIN auf data_rel (je Relation-Feld nur einmal)
        $relAlias = $this->registerRelationBaseJoin($tableName, $relFid, $ctx, $phase);
        // Subfeld = METAFELD des Ziel-record
        if ($isMetaSub) {
            $recAlias = $this->registerRelationRecordJoin($relFid, $relAlias, $ctx, $phase);
            return "`$recAlias`.`$sub`";
        }
        // Subfeld = virtuelles Skalarfeld des Ziel-record
        // Die Sprachbedingung richtet sich nach der Instanz des ZIEL-Datensatzes -
        // dafür wird dessen record-Zeile ebenfalls gejoint (PK-Zugriff, günstig).
        $recAlias  = $this->registerRelationRecordJoin($relFid, $relAlias, $ctx, $phase);
        $dataTable = $this->virtualDataTable($subField['type']);
        $subFid    = (int)$subField['fieldname_id'];
        $subKey    = 'relj:' . $relFid . ':vf:' . $subFid;
        if (isset($ctx['map'][$subKey])) {
            $dAlias = $ctx['map'][$subKey];
        } else {
            $dAlias = '_rsd_' . $ctx['seq']++;
            $ctx['joins'][$dAlias] = [
                'sql'    => "LEFT JOIN `$dataTable` AS `$dAlias`"
                          . " ON `$dAlias`.`record_id` = `$relAlias`.`dest_id`"
                          . " AND `$dAlias`.`fieldname_id` = ?"
                          . " AND `$dAlias`.`language_id` = " . $this->languageExpr($recAlias),
                'types'  => 'i',
                'values' => [$subFid],
                'phase'  => $phase,
            ];
            $ctx['map'][$subKey] = $dAlias;
        }
        return "`$dAlias`.`data`";
    }

    /**
     * Registriert (oder findet) den LEFT JOIN auf die `record`-Zeile des
     * VERKNUEPFTEN Datensatzes eines n:1-Relation-Feldes. Gebraucht fuer dessen
     * Metafelder und fuer die Sprachbedingung seiner data_*-Zeilen
     * (languageExpr() richtet sich nach `instance_id`).
     *
     * @param int    $relFid    fieldname_id des Relation-Feldes
     * @param string $relAlias  Alias des data_rel-JOINs (registerRelationBaseJoin())
     * @param array  &$ctx      JOIN-Kontext
     * @param string $phase     'filter' | 'sort'
     * @return string           Alias der record-Zeile
     */
    private function registerRelationRecordJoin(int $relFid, string $relAlias, array &$ctx, string $phase): string
    {
        $recKey = 'relj:' . $relFid . ':rec';
        if (isset($ctx['map'][$recKey])) {
            return $ctx['map'][$recKey];
        }
        $recAlias = '_rsr_' . $ctx['seq']++;
        $ctx['joins'][$recAlias] = [
            'sql'    => "LEFT JOIN `record` AS `$recAlias`"
                      . " ON `$recAlias`.`record_id` = `$relAlias`.`dest_id`",
            'types'  => '',
            'values' => [],
            'phase'  => $phase,
        ];
        $ctx['map'][$recKey] = $recAlias;
        return $recAlias;
    }
    
    /**
     * Registriert (oder findet) einen LEFT JOIN auf data_fil fuer die
     * Sortierung nach Datei-Metadaten.
     *
     *   sort=myfile        -> `filename` (Default)
     *   sort=myfile.size   -> `size`   (analog width/height/filename)
     *
     * data_fil hat pro (record_id, fieldname_id) hoechstens EINE Zeile
     * (PRIMARY KEY) -> der LEFT JOIN erzeugt keine Mehrfachzeilen, kein
     * DISTINCT noetig. LEFT (nicht INNER): Datensaetze ohne Datei duerfen
     * beim reinen Sortieren nicht aus dem Ergebnis fallen.
     * Mehrfachsortierung ueber dasselbe Feld (z. B. myfile.width, myfile.height)
     * nutzt denselben Alias (Wiederverwendung ueber 'map').
     *
     * @param string $tableName  Basistabelle (z. B. 'record')
     * @param int    $fid        fieldname_id des File-Feldes
     * @param array  &$ctx       JOIN-Kontext
     * @param string $sub        Subfeld ('' = Default 'filename')
     * @param string $fullName   kompletter Sortierschluessel (fuer Fehlermeldungen)
     * @return ?string           Spaltenausdruck oder null (Fehler registriert)
     */
    private function registerFileSortJoin(
        string $tableName,
        int    $fid,
        array  &$ctx,
        string $sub,
        string $fullName
    ): ?string {
        // Whitelist sortierbarer data_fil-Spalten.
        if ($sub === '') $sub = 'filename';   // Default-Sortierspalte
        if (!in_array($sub, $this->fileSortCols, true)) {
            // nicht sortierbares Datei-Subfeld (z. B. ocr_text) registrieren
            $this->error($this->snippet('error_sort_field_invalid'), $fullName);
            return null;
        }
        $cacheKey = 'fil:' . $fid;
        if (isset($ctx['map'][$cacheKey])) {
            $alias = $ctx['map'][$cacheKey];
        } else {
            $alias = '_fil_' . $ctx['seq']++;
            $ctx['joins'][$alias] = [
                'sql'    => "LEFT JOIN `data_fil` AS `$alias`"
                          . " ON `$alias`.`record_id` = `$tableName`.`{$tableName}_id`"
                          . " AND `$alias`.`fieldname_id` = ?"
                          . " AND `$alias`.`language_id` = " . $this->languageExpr($tableName),
                'types'  => 'i',
                'values' => [$fid],
                'phase'  => 'sort',
            ];
            $ctx['map'][$cacheKey] = $alias;
        }
        return "`$alias`.`$sub`";
    }

    // ====================

    /**
     * Vereinfachtes Schema eines Bereichs in $this->virtualSchemaCache($areaId) bereitstellen
     * 
     * @param int $areaId  area_id
     */
    protected function loadSimpleSchema(int $areaId): void
    {
        if (empty($this->virtualSchemaCache[$areaId])) {
            $this->db->pushResult();
            $this->db->query("SELECT `field_id`, `name`, `type`, `multilingual` FROM `field` WHERE `area_id`=?", 'i', [$areaId]);
            $this->virtualSchemaCache[$areaId] = $this->db->fetchAll();
            $this->db->popResult();
        }
    }

    /**
     * Inhalts-Cache eines Datensatzes erneuern - für ALLE Sprachen seiner Instanz.
     *
     * Aus den data_*-Zeilen entsteht je Sprache ein JSON-Abbild der virtuellen Felder
     * (ohne Relationen) und wird in `record_cache` abgelegt:
     *
     *     {"feldname": ["typ", wert], "text": ["text", "English text", 1], ...}
     *
     * Das dritte Element 1 markiert bei einem MEHRSPRACHIGEN Feld, dass der Wert nicht
     * in dieser Sprache eingegeben wurde, sondern der Fallback aus der Hauptsprache
     * ist. TemporaryTrait::checkOut() macht daraus wieder eine leere Eingabe. Die
     * Herkunft steht in der Spalte `empty` der data_*-Zeile (hasEmptyFlag()), die
     * saveVirtualFields() beim Schreiben setzt - der Neuaufbau ist damit so exakt
     * wie das reguläre Speichern; Datei-Felder tragen dasselbe in file_language_id.
     *
     * Einsprachige Felder haben in allen Sprachen denselben Wert (ohne Kennzeichen).
     * Verschlüsselte Inhalte stehen base64-kodiert im Cache - der Geheimtext ist
     * binär und ließe sich nicht als JSON ablegen.
     *
     * @param int  $id          Datensatz-Id
     * @param int  $areaId      Id des zugehörigen Inhaltsbereichs
     * @param int  $instanceId  Instanz des Datensatzes (0 = nachschlagen)
     * @param ?int $languageId  Sprache, deren Cache zurückgegeben wird
     *                          (null = Sprache laut recordLanguageId())
     * @return array            Inhalte virtueller Felder dieser Sprache
     */
    public function createRecordCache(int $id, int $areaId, int $instanceId = 0, ?int $languageId = null): array
    {
        if ($id < 1 || $areaId < 1) {
            echo "createRecordCache() internal error : id or areaId is empty";
            return [];
        }
        $this->db->pushResult();

        if ($instanceId < 1) {
            $this->db->query("SELECT `instance_id` FROM `record` WHERE `record_id` = ?", 'i', [$id]);
            $instanceId = (int)($this->db->fetchNum()[0] ?? 0);
        }
        $languageIds = $this->instanceCache->languageIds($instanceId);
        if (empty($languageIds)) $languageIds = [$this->instanceCache->primaryLanguageId($instanceId)];
        $primaryId = $languageIds[0];
        if ($languageId === null) $languageId = $this->recordLanguageId($instanceId);

        // vereinfachtes Schema der virtuellen Felder bereitstellen
        $this->loadSimpleSchema($areaId);
        $schemaVirtual = &$this->virtualSchemaCache[$areaId];

        $caches = array_fill_keys($languageIds, []);
        foreach ($schemaVirtual as $field) {
            $fieldId = (int)$field['field_id'];
            $name = (string)$field['name'];
            $type = (string)$field['type'];
            $multilingual = !empty($field['multilingual']);
            if (in_array($type, $this->relationTypes, true)) continue;
            $dataTable = 'data_' . $this->dataSuffix[$type];

            // Zeilen aller Sprachen holen: language_id => Wert
            $rows = [];
            if ($type === 'file') {
                $colExpr = '`language_id`, `' . implode('`,`', $this->fileCacheCols) . '`';
                $this->db->query("SELECT $colExpr FROM `{$dataTable}` WHERE `record_id` = ? AND `field_id` = ?", 'ii', [$id, $fieldId]);
                while ($row = $this->db->fetch()) {
                    $lang = (int)$row['language_id'];
                    unset($row['language_id']);
                    $row['file_language_id'] = $this->fileLanguageId($row);
                    $rows[$lang] = $row;
                }
                // Datei-Felder: die Zeile jeder Sprache steht schon fertig da (mit
                // Fallback); ein Fallback ist daran erkennbar, dass die Datei einer
                // anderen Sprache gehört
                foreach ($languageIds as $lang) {
                    $row = $rows[$lang] ?? ($rows[$primaryId] ?? []);
                    $fileLang = $this->fileLanguageId($row);
                    $caches[$lang][$name] = ($fileLang !== null && $fileLang !== $lang)
                        ? [$type, $row, 1]
                        : [$type, $row];
                }
                continue;
            }
            elseif ($type === 'password') {
                // Passwort-Hashes gehören nicht in den Inhalts-Cache: der Cache wird
                // an Templates und Backend-Listen ausgeliefert. Der Vergleich beim
                // Anmelden liest `data_pwd` direkt (RecordUser::passwordOk()).
                foreach ($languageIds as $lang) {
                    $caches[$lang][$name] = [$type, ''];
                }
                continue;
            }
            else {
                // `empty` sagt, ob die Zeile ein Fallback aus der Hauptsprache ist
                $hasEmpty = $this->hasEmptyFlag($type);
                $emptyCol = $hasEmpty ? ', `empty`' : '';
                $fallbacks = [];
                $this->db->query("SELECT `language_id`, `data`{$emptyCol} FROM `{$dataTable}` WHERE `record_id` = ? AND `field_id` = ?", 'ii', [$id, $fieldId]);
                while ($row = $this->db->fetch()) {
                    $value = $row['data'];
                    if ($type === 'encrypted') $value = base64_encode((string)$value);
                    $rows[(int)$row['language_id']] = $value;
                    if ($hasEmpty && !empty($row['empty'])) $fallbacks[(int)$row['language_id']] = true;
                }
            }
            $primaryValue = $rows[$primaryId] ?? ($rows ? reset($rows) : ($type === 'file' ? [] : ''));

            foreach ($languageIds as $lang) {
                if ($multilingual && $lang !== $primaryId) {
                    $value = $rows[$lang] ?? $primaryValue;
                    if (!isset($rows[$lang]) || !empty($fallbacks[$lang])) {
                        // Fallback aus der Hauptsprache - kennzeichnen
                        $caches[$lang][$name] = [$type, $primaryValue, 1];
                        continue;
                    }
                    $caches[$lang][$name] = [$type, $value];
                    continue;
                }
                $caches[$lang][$name] = [$type, $rows[$lang] ?? $primaryValue];
            }
        }

        // Daten serialisiert in `record_cache` speichern - eine Zeile je Sprache
        $this->db->query("DELETE FROM `record_cache` WHERE `record_id` = ?", 'i', [$id]);
        foreach ($caches as $lang => $cache) {
            $jsonData = json_encode($cache, JSON_UNESCAPED_UNICODE);
            $this->db->query(
                "INSERT INTO `record_cache` (`record_id`, `language_id`, `cache`) VALUES (?, ?, ?)",
                'iis', [$id, (int)$lang, (string)$jsonData]
            );
        }
        $this->db->popResult();
        // nicht serialisiertes Array der angeforderten Sprache zurückgeben
        return $caches[$languageId] ?? ($caches[$primaryId] ?? []);
    }

    /**
     * Ist der Wert eines Datei-Eintrags nach Sprachen indiziert
     * (language_id => Metadaten, mehrsprachiges Datei-Feld im Arbeitsstand) oder sind es
     * die Metadaten selbst (extension, size, ... - einsprachig)?
     */
    public function isLanguageKeyedFiles(array $value): bool
    {
        if (empty($value)) return false;
        foreach ($value as $key => $item) {
            if (!is_int($key) && !ctype_digit((string)$key)) return false;
            if (!is_array($item)) return false;
        }
        return true;
    }

    /**
     * Inhalts-Cache eines Datensatzes in EINER Sprache aus `record_cache` lesen.
     *
     * @param int $id          Datensatz-Id
     * @param int $languageId  Sprache
     * @return array|null      dekodierter Cache oder null, wenn keine Zeile vorliegt
     */
    public function loadRecordCache(int $id, int $languageId): ?array
    {
        $this->db->pushResult();
        $this->db->query(
            "SELECT `cache` FROM `record_cache` WHERE `record_id` = ? AND `language_id` = ?",
            'ii', [$id, $languageId]
        );
        $json = ($this->db->num_rows > 0) ? (string)($this->db->fetchNum()[0] ?? '') : '';
        $this->db->popResult();
        if ($json === '') return null;
        $cache = json_decode($json, true);
        return is_array($cache) ? $cache : null;
    }

    /**
     * Den Stand der laufenden Ergebnisschleife beiseitelegen - Gegenstück zu
     * Database::pushResult() für das, was die Schleife außerhalb der Datenbank hält.
     *
     * Die Ergebnisschleife (php/loop_start.php) zählt mit dem gemeinsamen Zähler
     * $row_no und legt die aktuelle Zeile in der Template-Variablen $cms ab. Ein
     * CMS:SELECT (oder CMS:SELECT_LANGVERSION) im Schleifenrumpf, dessen resulttemplate
     * wieder eine Schleife enthält, setzt beides neu - ohne Sicherung übersprünge die
     * äußere Schleife danach Zeilen oder liefe endlos, und CMS:TEXT & Co. läsen die
     * letzte Zeile der inneren Abfrage statt des äußeren Datensatzes. Deshalb ruft
     * php/push_result.php pushRow(), php/pop_result.php popRow().
     *
     * @param mixed $cms  Template-Variable $cms; null, wo es sie nicht gibt
     *                    (Trigger-Templates, siehe TriggerTemplates)
     */
    public function pushRow(mixed $cms): void
    {
        $this->rowStack[] = [$this->row_no, $cms];
    }

    /**
     * Den mit pushRow() gesicherten Stand zurückholen: stellt $row_no wieder her und
     * gibt den gesicherten Wert von $cms zurück (php/pop_result.php weist ihn zu).
     *
     * @return mixed  gesichertes $cms; null ohne passendes pushRow()
     */
    public function popRow(): mixed
    {
        if (!$this->rowStack) return null;
        [$this->row_no, $cms] = array_pop($this->rowStack);
        return $cms;
    }

    /**
     * Zeile aus Datenbank-Ergebnis laden und Werte aufbereiten
     * 
     * Bei `record` kommen die virtuellen Felder aus dem Inhalts-Cache der Sprache
     * (Spalte `cache`, von select() aus `record_cache` gejoint). Fehlt die Spalte -
     * direkte Abfragen "SELECT * FROM record" - oder die Zeile, wird der Cache aus
     * `record_cache` nachgeladen bzw. neu aufgebaut. Arbeitskopien (tmp = 1) halten
     * ihren Arbeitsstand in denselben `record_cache`-Zeilen (unter ihrer eigenen Id,
     * siehe TemporaryTrait) und werden genauso gelesen - nur neu aufgebaut wird dort
     * nichts.
     *
     * @param string $tableName
     * @param array &$schema
     * @return ?array     Daten
     */
    public function fetch(string $tableName = 'record', array &$schema = []): ?array
    {
        if (empty($schema)) $schema = &$this->schema;
        $data = $this->db->fetch();
        if ($data === null) return null;

        // Inhalte virtueller Felder
        // Die Abfrage von CMS:SELECT_LANGVERSION liefert Zeilen aus `language` statt aus
        // `record`, wird aber von derselben Ergebnisschleife (php/loop_start.php) gelesen.
        // Ohne record_id gibt es keine virtuellen Felder - und nichts nachzuladen.
        if ($tableName == 'record' && array_key_exists('record_id', $data)) {
            $id = (int)($data['record_id'] ?? 0);
            $areaId = (int)($data['area_id'] ?? 0);
            $cache = null;
            if (!empty($data['cache'])) {
                // aus dem gejointen Cache laden
                $cache = json_decode((string)$data['cache'], true);
                if (!is_array($cache)) $cache = null;
            }
            elseif (!array_key_exists('cache', $data)) {
                // direkte Abfrage ohne JOIN -> Zeile der Sprache nachladen
                $languageId = $this->recordLanguageId((int)($data['instance_id'] ?? 0));
                $cache = $this->loadRecordCache($id, $languageId);
            }
            if ($cache === null) {
                // Cache leer -> neu erzeugen. Nicht bei einer Arbeitskopie (tmp = 1):
                // ihr Arbeitsstand IST der Cache, data_*-Zeilen hat sie keine - eine
                // neue Arbeitskopie (tmpInit) hat schlicht noch keinen Inhalt
                $cache = empty($data['tmp'])
                    ? $this->createRecordCache($id, $areaId, (int)($data['instance_id'] ?? 0))
                    : [];
            }
            $data = $data + $cache;
        }

        // Nachbereitung der Daten
        foreach ($data as $name => $value) {
            // Felder die nicht in Cms::schema angegeben sind müssen übergangen werden.
            // Ansonsten werden sie als virtuelle Felder behandelt und verlieren Ihre Daten.
            // (Weil der Inhalt aus virtuellen Felden im Cache im Array-Format vorliegt.)
            if ($name == 'cache') continue;
            if ($name == 'origin_id') continue;
            
            $type = $schema[$name]['type'] ?? null;
            $fromCache = false;
            if (empty($type)) {
                // Inhalt aus virtuellem Feld, Format: [type, data] bzw. [type, data, 1].
                // Spalten, die weder im Schema stehen noch aus dem Cache kommen
                // (Zeilen anderer Herkunft, z. B. CMS:SELECT_LANGVERSION), bleiben
                // unverändert.
                if (!is_array($value)) {
                    if ($tableName !== 'template') $data[$name] = $this->_sanitizeValue($value);
                    continue;
                }
                $type = $value[0] ?? 'text';
                $data[$name] = $value[1] ?? '';
                $fromCache = true;
            }
            if ($type === 'integer') {
                // NULL in 0 wandeln, z. B. parent_id
                $data[$name] = (int)($data[$name] ?? 0);
            }
            elseif ($type === 'timestampl') {
                // Datum liegt in UTC-Zeit vor -> wandle es in PHP-Zeitzone um
                $data[$name] = $this->utcToUserTZ($data[$name]);
            }
            elseif ($type === 'json') {
                $data[$name] = json_decode((string)$data[$name], true) ?? [];
            }
            elseif ($type === 'encrypted') {
                // Im Cache base64-kodiert (siehe createRecordCache()), in den
                // Spalten von `record` selbst binär
                $data[$name] = $this->crypt->decrypt((string)$data[$name], '', !$fromCache);
            }

            // Lesefilter (16-sicherheit.md §6): kein aktiver Inhalt aus der Datenbank,
            // auch wenn er an der Anwendung vorbei hineinkam. Nicht bei Templates
            // (Script ist dort Inhalt) und Passwort-Hashes.
            if ($tableName !== 'template' && $type !== 'password') {
                $data[$name] = $this->_sanitizeValue($data[$name]);
            }
        }

        return $data;
    }

    /**
     * _sanitize_html() auf einen Wert beliebiger Tiefe (HtmlSanitizer::cleanValue()).
     */
    public function _sanitizeValue(mixed $value): mixed
    {
        return $this->htmlSanitizer->cleanValue($value);
    }

    /**
     * Datum von UTC-Zeit in PHP-Zeitzone umwandeln
     */
    public function utcToUserTZ(string $dateString): string {
        $date = new \DateTime($dateString, new \DateTimeZone('UTC'));
        $date->setTimezone(new \DateTimeZone($this->userTZ));
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Wandelt ein Datum + Uhrzeit aus der Datenbank (ISO-Format) in lesbares Format (Kurzform)
     * entsprechend der locale des Users um.
     * Wenn Uhrzeit 00:00:00 ist, dann lasse Uhrzeit weg
     * Wenn Sekunden 00 sind, dann lasse Sekunden weg
     * Keine Umwandlung der Zeitzone
     */
    public function _formatDate(string $isoDate, bool $displayTimezone = false): string {
        if (empty(trim($isoDate))) return '';
        // Platzhalter (kein Datum / kein Ende) nicht als Datum ausgeben
        if ($isoDate < self::DATE_MIN_DISPLAY || $isoDate >= self::DATE_MAX_DISPLAY) return '';
        try {
            // DateTimeImmutable ist Best Practice, um unerwünschte Zustandsänderungen zu vermeiden
            $date = new \DateTimeImmutable($isoDate);
        } catch (\Exception $e) {
            return $isoDate; // Fallback: Unveränderte Rückgabe bei fehlerhaftem ISO-String
        }

        $timeString = $date->format('H:i:s');
        $hasNoTime = $timeString === '00:00:00';
        $hasNoSeconds = $date->format('s') === '00';

        $dateType = \IntlDateFormatter::MEDIUM;
        
        if ($hasNoTime) {
            $timeType = \IntlDateFormatter::NONE;
        } elseif ($hasNoSeconds) {
            // SHORT lässt bei den meisten Locales die Sekunden automatisch weg
            $timeType = \IntlDateFormatter::SHORT;
        } else {
            // MEDIUM schließt die Sekunden mit ein
            $timeType = \IntlDateFormatter::MEDIUM;
        }

        // Fallback auf System-Default, falls nicht anderweitig in der Klasse gesetzt
        $locale = \Locale::getDefault(); 
        
        // Zeitzone aus dem Datum übernehmen, um Umwandlungen zu verhindern
        $timezone = $date->getTimezone();

        if ($formatter = $this->_intlFormatter($dateType, $timeType, $timezone, $locale)) {
            $formatted = $formatter->format($date);
            if ($formatted !== false) {
                return $formatted . ($displayTimezone ? ' ' . $timezone->getName() : '');
            }
        }

        // Manueller Fallback, falls die Intl-Klasse fehlt oder fehlschlägt
        $fallbackFormat = 'd.m.Y';
        if (!$hasNoTime) {
            $fallbackFormat .= $hasNoSeconds ? ' H:i' : ' H:i:s';
        }
        
        return $date->format($fallbackFormat) . ($displayTimezone ? ' ' . $timezone->getName() : '');
    }

    /**
     * Wandelt eine Uhrzeit aus der Datenbank (ISO-Format) in lesbares Format (Kurzform)
     * entsprechend der locale des Users um.
     * Wenn Sekunden 00 sind, dann lasse Sekunden weg
     * Keine Umwandlung der Zeitzone
     */
    public function _formatTime(string $isoTime, bool $displayTimezone = false): string {
        if (empty(trim($isoTime))) return '';

        try {
            $time = new \DateTimeImmutable($isoTime);
        } catch (\Exception $e) {
            return $isoTime; // Fallback
        }

        $hasNoSeconds = $time->format('s') === '00';
        
        $dateType = \IntlDateFormatter::NONE;
        $timeType = $hasNoSeconds ? \IntlDateFormatter::SHORT : \IntlDateFormatter::MEDIUM;

        $locale = \Locale::getDefault();
        $timezone = $time->getTimezone();

        if ($formatter = $this->_intlFormatter($dateType, $timeType, $timezone, $locale)) {
            $formatted = $formatter->format($time);
            if ($formatted !== false) {
                return $formatted . ($displayTimezone ? ' ' . $timezone->getName() : '');
            }
        }

        // Manueller Fallback
        $fallbackFormat = $hasNoSeconds ? 'H:i' : 'H:i:s';
        return $time->format($fallbackFormat) . ($displayTimezone ? ' ' . $timezone->getName() : '');
    }

    /**
     * IntlDateFormatter mit PROLEPTISCH gregorianischem Kalender bauen.
     *
     * ICU rechnet standardmäßig vor dem 15.10.1582 im julianischen Kalender - aus
     * '1000-01-02' würde in der Anzeige der 28.12.999. Für gespeicherte ISO-Daten ist
     * das falsch: die stehen in der Datenbank und im ganzen CMS proleptisch
     * gregorianisch, also nach denselben Regeln wie heutige Daten. setGregorianChange()
     * verlegt den Umstellungszeitpunkt beliebig weit in die Vergangenheit und schaltet
     * die julianische Rechnung damit ab. Für Daten ab 1582 ändert sich nichts.
     *
     * @param int           $dateType  IntlDateFormatter-Konstante
     * @param int           $timeType  dito
     * @param \DateTimeZone $timezone  Zeitzone des zu formatierenden Wertes
     * @param string        $locale    Vorgabe: Locale des Users
     * @return ?\IntlDateFormatter     null, wenn die intl-Erweiterung fehlt
     */
    private function _intlFormatter(
        int $dateType,
        int $timeType,
        \DateTimeZone $timezone,
        string $locale = ''
    ): ?\IntlDateFormatter {
        if (!class_exists(\IntlDateFormatter::class)) return null;
        if ($locale === '') $locale = \Locale::getDefault();

        $formatter = new \IntlDateFormatter($locale, $dateType, $timeType, $timezone);

        if (class_exists(\IntlGregorianCalendar::class)) {
            $calendar = new \IntlGregorianCalendar($timezone, $locale);
            // beliebig früh = nie umgestellt = durchgehend gregorianisch
            $calendar->setGregorianChange(-PHP_FLOAT_MAX);
            $formatter->setCalendar($calendar);
        }

        return $formatter;
    }

    /**
     * Wandelt Zeilenumbrüche um
     *
     * @param string $text  Text
     * @param string $br      Umwandlung in: "<br>\n"
     * @return string
     */
    public function _nl2br(string $text, string $br = "<br>\n"): string
    {
        # ascii(13) = 015 = \r
        # ascii(10) = 012 = \n
        return (preg_replace("/(\r\n|\r|\n)/", $br, $text));
    }

    public function _strlimit(string $text, int|string $limit): string
    {
        $isSoft = false;
        $cleanLimit = $limit;
        if (is_string($limit) && str_ends_with(strtolower($limit), 's')) {
            $isSoft = true;
            $cleanLimit = (int) substr($limit, 0, -1);
        } else {
            $cleanLimit = (int) $cleanLimit;
        }
        if (mb_strlen($text) <= $cleanLimit) {
            return $text;
        }
        $ellipsis = $this->snippet('ellipsis');
        $truncated = mb_substr($text, 0, $cleanLimit);
        if ($isSoft) {
            if (preg_match('/\s\S*$/u', $truncated, $matches, PREG_OFFSET_CAPTURE)) {
                $lastSpaceOffset = $matches[0][1];
                $truncated = mb_substr($truncated, 0, $lastSpaceOffset);
            }
        }
        return $truncated . $ellipsis;
    }

    /**
     * Entfernt aktiven Inhalt aus HTML, lässt Formatierung stehen - siehe
     * HtmlSanitizer::clean() (script, iframe, object, embed, base, meta …,
     * Event-Handler, srcdoc, javascript:/vbscript:/data:-URLs, Whitelist
     * system.html_allowed_tags).
     *
     * Beim SCHREIBEN: Dataset::validateValue() für jeden Textwert (Ausnahme: Modul
     * Template, siehe Dataset::$sanitizeHtml), Snippets::save().
     * Beim LESEN: Cms::fetch() für jede Zeile (außer `template`) - gegen Inhalte, die
     * an der Anwendung vorbei in die Datenbank kamen (16-sicherheit.md §6).
     *
     * @param string $text  Text mit HTML
     */
    public function _sanitize_html(string $text): string
    {
        return $this->htmlSanitizer->clean($text);
    }

    /**
     * Ersetzt HTML-Tags durch Leerzeichen, kein trim
     *
     * @param string $text  Text
     */
    public function _strip_tags(string $text): string
    {
        if ($text === '') return '';
        $cleaned = preg_replace('/<[^>]+>/', ' ', $text);
        $cleaned = preg_replace('/ +/', ' ', $cleaned);
        return $cleaned;
    }

    /**
     * Formatiert einen Fließkommawert in das Userformat
     * 
     * @param float $floatValue    Eingabewert
     * @param int $fractionDigits  Anzahl Nachkommastellen
     */
    public function _formatFloat($floatValue, $fractionDigits = 0): string
    {
        $fmt = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::DECIMAL);
        if ($fractionDigits > 0) {
            $fmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $fractionDigits);
            $fmt->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $fractionDigits);
        }
        return $fmt->format($floatValue);
    }

    /**
     * Alle &...; Zeichen-Angaben dekodieren
     */
    public function _decodeSpecialchars(string $input): string {
        $decoded = html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $decoded;
    }


    // ====================
    // CMS-Commands
    //
    // Laufzeit der (CMS:...)-Befehle aus den Frontend-Templates. Aufgerufen werden sie
    // ausschließlich aus dem kompilierten Template-Code, immer mit genau einem
    // Parameter: dem Array $_a mit den im Befehl angegebenen Werten.
    //
    // DREI KONVENTIONEN GELTEN FÜR ALLE
    // --------------------
    //   1. Die Quelle steht in $GLOBALS['cms'] - dem Datensatz, den Page::resolveRequest()
    //      geladen hat bzw. der Zeile, die die laufende Ergebnisschleife gerade liefert.
    //   2. Ausgegeben wird per echo, es sei denn der Parameter 'set' ist gesetzt; dann
    //      landet das Ergebnis in $GLOBALS['set'][...] und die Seite bleibt still.
    //      Beides erledigt _out().
    //   3. ifempty / instead / prefix / suffix wirken bei fast allen Ausgabebefehlen
    //      gleich - das übernimmt _decorate():
    //          leer          -> 'ifempty' ersetzt alles Weitere
    //          nicht leer    -> 'instead' ersetzt den Wert, dann prefix + Wert + suffix
    //      'replace' (Teiltexte ersetzen, _replace()) kennen CMS:TEXT, CMS:DATE,
    //      CMS:DATETIME, CMS:TIME und CMS:NUMBER - bei den letzten vier auf dem
    //      fertig formatierten Wert, vor _decorate().
    //
    // Befehle mit einem php_suffix 'include'/'include_and_stop' geben statt dessen
    // einen TEMPLATENAMEN zurück (string); den bindet der erzeugte Code danach ein.
    // @see Camindo\Core\Modules\Template\commands.php


    // CMS:CONTINUE
    // Nach einem Befehl mit eigenem Erfolgstemplate (CMS:USER_LOGIN & Co.) beendet der
    // Schnipsel include_and_stop die Seite. Dieses Flag verhindert das genau einmal -
    // der Schnipsel setzt es selbst wieder zurück.
    public function cmsContinue($p): void
    {
        $this->returnAfterSuccess = true;
    }

    // CMS:DATE - nur der Datumsteil
    public function cmsDate($p): void
    {
        $this->_out($this->_decorate($this->_replace($this->_dateValue($p, 'date'), $p), $p), $p);
    }

    // CMS:DATETIME - Datum und Uhrzeit
    public function cmsDatetime($p): void
    {
        $this->_out($this->_decorate($this->_replace($this->_dateValue($p, 'datetime'), $p), $p), $p);
    }

    // CMS:TIME - nur die Uhrzeit
    public function cmsTime($p): void
    {
        $this->_out($this->_decorate($this->_replace($this->_dateValue($p, 'time'), $p), $p), $p);
    }

    // CMS:FED - Bearbeiten-Knopf ins Backend
    //
    // Nur bei eingeschaltetem Frontend-Editing (fedActive(): Cookie 'fed' = 1 oder 2,
    // gesetzt über die persönlichen Einstellungen des Admins). Ohne 'id' ist der
    // laufende Datensatz gemeint, sonst gilt die Regel von _record() (Id oder Slug).
    //
    // KEIN VERWEIS, SONDERN EIN JAVASCRIPT-AUFRUF
    // --------------------
    // Ausgegeben wird <a href="#" data-c9-fed="{id}" class="c9-fed"> (mit info=true
    // zusätzlich data-c9-fed-view="info"). Den Klick fängt CMS:FED_SCRIPT
    // (cmsFedScript()) ab und ruft cFED() - sie öffnet das Backend in einem neuen
    // Tab und steuert die Eingabemaske als Layer an. Kein onclick-Attribut: das
    // blockierte eine Script-Policy des Frontends (19-csp.md §9).
    //
    // Warum kein href: die Zieladresse (#Record/record_edit.php?id=…) stand damit in
    // jeder ausgelieferten Seite. Das Backend war dadurch an dieses Hash-Routing
    // gebunden - ein Deep-Link aus dem Frontend, den man nicht ändern kann, ohne
    // fremde Templates zu brechen. Jetzt steht das Ziel an genau EINER Stelle
    // (cmsFedScript()), und das Öffnen der Maske lässt sich umbauen, ohne dass ein
    // Template etwas davon merkt (17-csrf.md §2.8).
    //
    // Ohne CMS:FED_SCRIPT auf der Seite fängt niemand den Klick ab - er bleibt
    // wirkungslos. Den Knopf sieht ohnehin nur der angemeldete Admin.
    public function cmsFed($p): void
    {
        if (!$this->fedActive()) return;
        $data = $this->_record($p);
        $id = (int)($data['record_id'] ?? 0);
        if ($id < 1) return;

        $label = (string)($p['label'] ?? '');
        if ($label === '') $label = '<span style="color:#f00">[&raquo;]</span>';

        // 'info': Detailansicht statt Eingabemaske - zweites Argument von cFED()
        $view = $this->_bool($p['info'] ?? false) ? ' data-c9-fed-view="info"' : '';
        $html = '<a href="#" data-c9-fed="' . $id . '"' . $view . ' class="c9-fed">'
              . $label . '</a>';
        $this->_out((string)($p['prefix'] ?? '') . $html . (string)($p['suffix'] ?? ''), $p);
    }

    // CMS:FED_SCRIPT - die Funktion cFED() für die Knöpfe aus CMS:FED
    //
    // Gehört einmal je Seite ins Template, üblicherweise in den Footer-Include vor
    // </body>; die Reihenfolge zu den CMS:FED-Knöpfen spielt keine Rolle, gerufen
    // wird erst beim Klick. Gibt wie CMS:FED nur bei eingeschaltetem
    // Frontend-Editing etwas aus - und höchstens einmal je Request, auch wenn der
    // Befehl mehrfach im Template steht (über mehrere CMS:INCLUDE hinweg).
    //
    // cFED(id[, 'info']) öffnet {paths.backend}/index.php in einem neuen Tab und
    // gibt die Backend-Seite als Hash mit. Ist der Admin dort nicht angemeldet, legt
    // das Backend den Anmeldedialog über den Layer. Der Zeitstempel im Query-String
    // verhindert eine Fassung aus dem Browser-Cache. Ohne feste Backend-Domain liegt
    // das Backend unter paths.backend derselben Domain, sonst unter
    // admin.backend_domain (BackendDomain::url()).
    public function cmsFedScript($p): void
    {
        if (!$this->fedActive()) return;
        if ($this->fedScriptOut) return;
        $this->fedScriptOut = true;

        // Mit fester Backend-Domain absolut (BackendDomain), sonst relativ wie bisher
        $base = json_encode((new BackendDomain($this->config))->url('/index.php?'), JSON_UNESCAPED_SLASHES);
        $html = '<script nonce="' . $this->cspNonce() . '">' . "\n"
              . 'window.cFED = window.cFED || function (id, view) {' . "\n"
              . '    var script = (view === "info") ? "Record/record_info.php" : "Record/record_edit.php";' . "\n"
              . '    window.open(' . $base . ' + Date.now() + "#" + script' . "\n"
              . '        + "?id=" + encodeURIComponent(id), "_blank");' . "\n"
              . '};' . "\n"
              . 'document.addEventListener("click", function (ev) {' . "\n"
              . '    var a = ev.target.closest ? ev.target.closest("[data-c9-fed]") : null;' . "\n"
              . '    if (!a) return;' . "\n"
              . '    ev.preventDefault();' . "\n"
              . '    window.cFED(a.getAttribute("data-c9-fed"), a.getAttribute("data-c9-fed-view") || "");' . "\n"
              . '});' . "\n"
              . '</script>' . "\n";
        $this->_out($html, $p);
    }

    // ====================
    // Script-Policy des Frontends (19-csp.md §9, SecurityHeaders)
    // ====================

    /**
     * Nonce der laufenden Seite - für CMS:NONCE und Core-Ausgaben mit Script
     * (CMS:FED_SCRIPT).
     */
    public function cspNonce(): string
    {
        return $this->securityHeaders->nonce();
    }

    // CMS:NONCE - die Nonce der Seite für jedes Script im Template:
    // <script(CMS:NONCE)>. format=attribute (Vorgabe) gibt ' nonce="…"' aus,
    // format=value nur den Wert (eigene Policy per CMS:HEADER, echo in PHP).
    public function cmsNonce($p): void
    {
        $nonce = $this->cspNonce();
        $html = strtolower((string)($p['format'] ?? '')) === 'value' ? $nonce : ' nonce="' . $nonce . '"';
        $this->_out($html, $p);
    }

    // CMS:CSP - die Script-Policy dieser Seite ändern, solange noch nichts
    // ausgegeben wurde: script="https://js.example.com …" (weitere Quellen),
    // form="https://pay.example.com" (Formularziele, schaltet form-action ein),
    // mode=off|report|enforce. Mehrere Aufrufe sammeln Quellen; die Policy wird
    // jedes Mal neu gesendet.
    public function cmsCsp($p): void
    {
        if (headers_sent()) {
            $this->error('CMS:CSP: headers already sent.');
            return;
        }
        $mode = strtolower(trim((string)($p['mode'] ?? '')));
        $script = SecurityHeaders::cleanSources((string)($p['script'] ?? ''));
        $form = SecurityHeaders::cleanSources((string)($p['form'] ?? ''));
        if ($mode !== '' && !in_array($mode, SecurityHeaders::FRONTEND_MODES, true)) {
            $this->error('CMS:CSP: unknown mode "' . $mode . '".');
            $mode = '';
        }
        $this->securityHeaders->changeFrontendCsp($mode, $script, $form);
    }

    // CMS:FED_CODE - HTML nur bei eingeschaltetem Frontend-Editing, sonst 'instead'
    public function cmsFedCode($p): void
    {
        $html = $this->fedActive() ? (string)($p['code'] ?? '') : (string)($p['instead'] ?? '');
        $this->_out($html, $p);
    }

    // CMS:HEADER
    public function cmsHeader($p): void
    {
        $text = (string)($p['text'] ?? '');
        if ($text === '') return;
        if (headers_sent()) {
            $this->error('CMS:HEADER: headers already sent.');
            return;
        }
        // "Name:" ohne Wert entfernt den Header - etwa eine Voreinstellung aus
        // SecurityHeaders (X-Frame-Options, Content-Security-Policy …)
        if (preg_match('/^([A-Za-z0-9-]+):\s*$/', $text, $m)) {
            header_remove($m[1]);
            return;
        }
        // 'overwrite' - false hängt einen weiteren Header desselben Typs an
        header($text, $this->_bool($p['overwrite'] ?? true));
    }

    // CMS:INCLUDE - returns template file ("{area_id}/{name}", siehe templateFile())
    // Der Schnipsel php/include_cached.php bindet das zurückgegebene Template ein und
    // ruft danach includeEnd().
    //
    // 'caching' / 'cacheparams' - Ausgabe-Cache (IncludeCache): bei einem Treffer
    // gibt cmsInclude() die abgelegte Ausgabe selbst aus und liefert '' (nichts
    // einzubinden); sonst zeichnet IncludeCache die Ausgabe des Templates auf, und
    // includeEnd() legt sie ab - nur wenn dabei kein Befehl einen Fehler meldete.
    // Ohne Cache bleiben Vorschau, Frontend-Editing (Knöpfe nur für den Admin) und
    // admin.include_cache = false. Ein ungültiger Wert meldet einen Fehler, das
    // Template läuft dann ungecacht.
    public function cmsInclude($p): string
    {
        if ($this->includeDepth > (int)$this->config->get('admin.max_include_depth')) {
            $this->_cmsError('CMS:INCLUDE error: maximum inclusion depth exceeded.');
        }
        $file = $this->templateFile((string)($p['template'] ?? ''));

        $recording = null;
        if ($file !== '' && isset($p['caching']) && !$this->includeCacheOff
            && $this->includeCache->enabled() && !$this->fedActive()) {
            try {
                $ttl = IncludeCache::ttl($p['caching']);
                if ($ttl !== null) {
                    $key = IncludeCache::key(
                        (int)($this->instance['instance_id'] ?? 0),
                        (int)$this->languageId,
                        IncludeCache::paramString($p['cacheparams'] ?? null)
                    );
                    $source = $this->templatePath . "/$file.php";
                    $cached = $this->includeCache->start($file, $key, $ttl, $source, $this->cspNonce());
                    if ($cached !== null) {
                        echo $cached;
                        $file = '';
                    }
                    else {
                        $recording = $this->errorCount;
                    }
                }
            }
            catch (\InvalidArgumentException $e) {
                $this->error('CMS:INCLUDE: ' . $e->getMessage());
            }
        }
        $this->includeCacheStack[] = $recording;
        return $file;
    }

    /**
     * Ende eines CMS:INCLUDE (php/include_cached.php): eine laufende Aufzeichnung
     * abschließen und die Ausgabe weitergeben.
     */
    public function includeEnd(): void
    {
        $errorCount = array_pop($this->includeCacheStack);
        if ($errorCount === null) return;
        echo $this->includeCache->finish($this->errorCount === $errorCount, $this->cspNonce());
    }

    // CMS:MEDIA
    //
    // Quelle ist ein Upload-Feld: im Datensatz liegt dazu die Zeile aus data_fil
    // (filename, mime_type, extension, size, width, height). Die Datei selbst steht
    // unter {paths.media}/{instance_id}/{field_id}/{record_id}.{extension}
    // (mediaPath()).
    //
    // Ohne 'subfield' entsteht ein IMG-Tag, mit 'subfield' der jeweilige Einzelwert.
    //
    // 'auth' / 'download' / user_auth: Statt direkt ins Medienverzeichnis zeigt der
    // Verweis auf /file.php (Camindo\Core\MediaDelivery), wenn
    //   - das Feld im Backend als "nur für angemeldete User" markiert ist
    //     (field.details.user_auth - sein Verzeichnis ist per .htaccess gesperrt),
    //   - auth=true angegeben ist (erzwingt file.php, etwa für Vorschauen), oder
    //   - download="name" gesetzt ist (Content-Disposition mit Wunschnamen).
    // Bei mehrsprachigen Feldern trägt der Verweis die Sprache der Datei mit.
    public function cmsMedia($p): void
    {
        $file = $this->_source($p);
        if (!is_array($file)) $file = [];

        // Feld-Id für den Pfad - sie steht nicht im Datensatz-Cache, sondern
        // in der Felddefinition des Inhaltsbereichs. Ein per 'useval' übergebenes
        // Datei-Array darf beides selbst mitbringen.
        // Datensatz: mit 'id' der dort angegebene, sonst der laufende (siehe _record()).
        $record   = $this->_record($p);
        $recordId = (int)($file['record_id'] ?? $record['record_id'] ?? 0);
        $areaId   = (int)($file['area_id']   ?? $record['area_id']   ?? 0);
        $fieldId  = (int)($file['field_id']  ?? 0);
        $fieldName = (string)($p['field'] ?? '');
        if ($fieldId < 1 && $fieldName !== '' && $areaId > 0) {
            $this->loadSimpleSchema($areaId);
            foreach ($this->virtualSchemaCache[$areaId] as $f) {
                if ($f['name'] === $fieldName) {
                    $fieldId = (int)$f['field_id'];
                    break;
                }
            }
        }

        // Zwei Pfade zur Datei:
        //   path  serverrelativ ab DOCUMENT_ROOT, für href/src. Der ursprüngliche
        //         Dateiname steht mit Komma abgetrennt im Aufruf -
        //         /media/{instance}/{field}/{record}[_{lang}],{name}.{ext} -
        //         damit Browser und Suchmaschinen einen sprechenden Namen sehen. Die
        //         RewriteRule "CAMINDO MEDIA" in public/.htaccess wirft den Teil
        //         ",{name}" ungeprüft weg (siehe backend/html/htaccess.html).
        //         Bei Auslieferung über file.php (auth, download, user_auth) entfällt
        //         das - dort steht der Name im Content-Disposition-Header.
        //   file  absoluter Pfad im Dateisystem, für Dateioperationen in PHP.
        $extension = (string)($file['extension'] ?? '');
        $path = '';
        $filePath = '';
        if ($fieldId > 0 && $recordId > 0 && $extension !== '') {
            $fileLanguageId = $this->fileLanguageId($file);
            $instanceId = (int)($file['instance_id'] ?? $record['instance_id'] ?? 0);
            // Bei mehrsprachigen Datei-Feldern die Datei der Sprache (bzw. der
            // Hauptsprache als Fallback) - siehe mediaPath()
            $mediaPath = $this->mediaPath($fieldId, $recordId, $fileLanguageId, $extension, $instanceId);
            $filePath = $this->config->get('paths.media_dir') . '/' . $mediaPath;

            $download = trim((string)($p['download'] ?? ''));
            $viaScript = $download !== ''
                || $this->_bool($p['auth'] ?? false)
                || $this->isUserAuthField($fieldId);
            if ($viaScript) {
                // Auslieferung über file.php (siehe Kommentar oben)
                $path = "/file.php?file={$fieldId}/{$recordId}"
                     . ($fileLanguageId === null ? '' : '/' . $fileLanguageId)
                     . '&v=' . (int)($file['version'] ?? 0);
                if ($download !== '') {
                    $path .= '&download=' . rawurlencode(preg_replace('~[\\\\/:*?"<>|]~', '', $download));
                }
            }
            else {
                $path = $this->config->get('paths.media') . '/'
                      . $this->mediaUriName($mediaPath, (string)($file['filename'] ?? ''), $extension);
                // Versionsnummer gegen Browser-Caching (wie im Backend)
                $version = (int)($file['version'] ?? 0);
                if ($version > 0) $path .= '?v=' . $version;
            }
        }

        // 'subfield' - Einzelwert statt IMG-Tag
        $subfield = (string)($p['subfield'] ?? '');
        if ($subfield !== '') {
            $value = match ($subfield) {
                'path', 'url' => $path,   // 'url': Schreibweise aus C9-Vorabversionen
                'file'        => $filePath,
                default       => (string)($file[$subfield] ?? ''),
            };
            // 'autoformat' - Dateigröße lesbar machen (Standard: an)
            if ($subfield === 'size' && $this->_bool($p['autoformat'] ?? true)) {
                $value = $this->_formatBytes((int)$value);
            }
            $this->_out($this->_decorate($value, $p, true), $p);
            return;
        }

        // IMG-Tag
        $html = '';
        if ($path !== '') {
            $attr = [
                'src' => $path,
                'alt' => (string)($file['filename'] ?? ''),
            ];
            // 'width' / 'height' - ohne Angabe die Maße aus data_fil
            $width  = (string)($p['width']  ?? ($file['width']  ?? ''));
            $height = (string)($p['height'] ?? ($file['height'] ?? ''));
            if ($width  !== '' && $width  !== '0') $attr['width']  = $width;
            if ($height !== '' && $height !== '0') $attr['height'] = $height;
            // 'attr' - zusätzliche Attribute, überschreiben die obigen
            foreach ((array)($p['attr'] ?? []) as $name => $value) {
                $attr[preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$name)] = (string)$value;
            }

            $html = '<img';
            foreach ($attr as $name => $value) {
                $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
            }
            $html .= '>';
        }

        $this->_out($this->_decorate($html, $p, true), $p);
    }

    // CMS:MESSAGE
    // Gibt die Meldungen aus, die die Befehle dieser Seite mit error() abgelegt haben -
    // typischerweise die Feldfehler eines CMS:USER_INPUT-Formulars.
    public function cmsMessage($p): void
    {
        $value = (string)($p['useval'] ?? '');
        if ($value === '' && $this->hasErrors) {
            $value = $this->errorMsg();
        }
        $this->_out($this->_decorate($value, $p), $p);
    }

    // Bereich der laufenden Übersichtsseite (Präfix-Adresse ohne Datensatz,
    // Page::resolveRequest()) - 0 = keine
    public int $overviewAreaId = 0;

    /** Bereich nach Name oder Id (in den Instanzen dieses Auftritts); 0 = unbekannt. */
    private function areaIdByRef(string $ref): int
    {
        $instanceIds = array_map('intval', $this->accessInstanceIds ?: (array)($this->instanceCache->all()['instance_ids'] ?? []));
        foreach ($this->instanceCache->areasOfType([], $instanceIds) as $areaId => $info) {
            if ((string)$areaId === $ref || $info['name'] === $ref) return (int)$areaId;
        }
        return 0;
    }

    // Stapel der set-Namen für Befehle mit 'buffer_set' (commands.php) - ein Stapel,
    // weil eingebundene Templates $_a überschreiben und Befehle mit set verschachtelt
    // sein dürfen
    private array $bufferSetStack = [];

    // Gesamtzahlen von CMS:SELECT hits=false bis select_count (siehe selectCountEnd())
    private array $selectCountStack = [];

    /**
     * Beginn eines Befehls mit 'buffer_set' (CMS:SELECT, CMS:INCLUDE …): mit set=…
     * wird die Ausgabe bis setBufferEnd() abgefangen. Endet die Seite vorher (exit nach
     * einem Success-Template, Exception), gibt PHP den offenen Puffer am Ende ganz
     * normal aus - Fehlermeldungen gehen nicht verloren.
     */
    public function setBufferStart(string $name): void
    {
        $this->bufferSetStack[] = $name;
        if ($name !== '') ob_start();
    }

    /** Ende eines Befehls mit 'buffer_set': abgefangene Ausgabe nach $set[name]. */
    public function setBufferEnd(): void
    {
        $name = (string)array_pop($this->bufferSetStack);
        if ($name !== '') $GLOBALS['set'][$name] = (string)ob_get_clean();
    }

    // CMS:SNIPPET
    //
    // Snippet in der Sprache des Aufrufs - dieselbe Auflösung wie snippet(): mit
    // Präfix (custom.key = Snippet-Set der Instanz), ohne Präfix ein Core-Snippet.
    // param[name]=wert füllt ICU-Parameter und ersetzt {name} in einfachen Texten
    // (snippet() selbst interpoliert nur ICU-Nachrichten). Fehlt das Snippet, liefert
    // snippet() den Schlüssel - er bleibt sichtbar, außer 'ifempty' ist angegeben.
    public function cmsSnippet(array $p): void
    {
        $key = trim((string)($p['key'] ?? ''));
        $params = array_map('strval', array_filter((array)($p['param'] ?? []), 'is_scalar'));
        $text = $key === '' ? '' : $this->snippet($key, $params);
        if ($text === $key && isset($p['ifempty'])) $text = '';
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }
        if ($this->_bool($p['specialchars'] ?? false)) {
            $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
        $this->_out($this->_decorate($text, $p), $p);
    }

    // CMS:NUMBER
    public function cmsNumber($p): void
    {
        $raw = $this->_source($p);
        $isEmpty = (trim((string)$raw) === '');

        // 'instead' greift schon vor der Rechnung: es ersetzt die Quelle, nicht das Ergebnis
        if (!$isEmpty && isset($p['instead']) && (string)$p['instead'] !== '') {
            $raw = $p['instead'];
        }

        $number = (float)str_replace(',', '.', (string)$raw);
        // 'mul' / 'add'
        $number = $number * (float)($p['mul'] ?? 1) + (float)($p['add'] ?? 0);

        // 'sizefactor' - lesbare Dateigröße (1000 oder 1024)
        $sizefactor = (int)($p['sizefactor'] ?? 0);
        if ($sizefactor > 0) {
            $value = $this->_formatBytes($number, $sizefactor);
        }
        else {
            // 'format' - sprintf-Muster. Es bestimmt die Ausgabe allein; 'decimal',
            // 'thousands_separator', 'decimals' und 'nullcent' bleiben dann außen vor.
            $format = (string)($p['format'] ?? '');
            if ($format !== '') {
                $value = sprintf($format, $number);
            }
            else {
                // Bewusst OHNE Locale-Formatierung: die Trennzeichen gibt der Befehl
                // selbst an ('decimal' Standard '.', 'thousands_separator' Standard
                // keines). Eine Locale würde sie überschreiben und beim Zerlegen
                // unten als Dezimalpunkt missverstanden.
                // 'decimals' - feste Anzahl Nachkommastellen (gerundet); ohne Angabe so
                // viele, wie die Zahl hat
                $digits = (isset($p['decimals']) && (string)$p['decimals'] !== '')
                    ? max(0, min(10, (int)$p['decimals']))
                    : $this->_fractionDigits($number);
                $value = number_format($number, $digits, '.', '');

                $decimal = (string)($p['decimal'] ?? '.');
                $thousands = (string)($p['thousands_separator'] ?? '');
                [$intPart, $fracPart] = array_pad(explode('.', $value, 2), 2, null);
                if ($thousands !== '') {
                    // alle drei Stellen von rechts, Vorzeichen nicht mitzählen
                    $intPart = preg_replace('/\B(?=(\d{3})+(?!\d))/', $thousands, $intPart);
                }
                // 'nullcent' - Ersatz für einen fehlenden Nachkommawert
                if ($fracPart === null && isset($p['nullcent']) && (string)$p['nullcent'] !== '') {
                    $fracPart = (string)$p['nullcent'];
                }
                $value = ($fracPart === null) ? $intPart : $intPart . $decimal . $fracPart;
            }
        }

        // 'replace' wirkt auf den fertig formatierten Wert (z. B. ".00?")
        $value = $this->_replace((string)$value, $p);

        // 'ifempty' gilt hier auch für den Wert 0 - geprüft am Zahlenwert, nicht am
        // formatierten Text ("0.00" mit format/decimals ist ebenfalls 0) - 'instead'
        // ist oben schon verbraucht
        unset($p['instead']);
        $this->_out($this->_decorate((float)$number == 0.0 ? '' : $value, $p), $p);
    }

    // CMS:RAW
    // Ohne jede Aufbereitung - der Inhalt muss vertrauenswürdig sein (HTML-Felder).
    // Nur die Ausgabeparameter (ifempty, instead, prefix, suffix, set) gelten wie überall.
    public function cmsRaw($p): void
    {
        $this->_out($this->_decorate((string)$this->_source($p), $p), $p);
    }

    // CMS:RELATION
    //
    // Gibt das Haut-Feld (data_dft) aller Datensätze aus, auf die das angegebene
    // Verweisfeld des aktuellen Datensatzes zeigt - in der im Backend festgelegten
    // Reihenfolge (data_rel.position). Mit 'id' die Verweise eines anderen
    // Datensatzes (record_id oder Slug, Regel wie in der URL - siehe _record(),
    // auch die Veröffentlichung). Ziele nur veröffentlicht wie bei CMS:SELECT
    // (publishedSql(), lockerbar mit future/archive/state) - auch in der Vorschau.
    public function cmsRelation($p): void
    {
        $values = [];
        // ohne 'id' der laufende Datensatz, sonst Id/Slug (für den Request gemerkt)
        $srcId = (int)($this->_record($p)['record_id'] ?? 0);
        $field = (string)($p['field'] ?? '');
        $relField = ($field !== '') ? $this->resolveVirtualField($field) : null;

        if ($relField === null || !in_array($relField['type'], $this->relationTypes, true)) {
            if ($field !== '') $this->error(strtr($this->snippet('error_field_unknown_type'), ['{name}' => (string)$field]), $field);
        }
        elseif ($srcId > 0) {
            // (ohne Quelle - kein laufender Datensatz, unbekannter Verweis - bleibt
            //  die Ausgabe schlicht leer)

            $limit = (int)($p['limit'] ?? 1000);
            if ($limit < 1) $limit = 1000;
            $published = $this->publishedSql($p, 't');

            // Der Befehl steht regelmäßig INNERHALB einer Ergebnisschleife -
            // das laufende Ergebnis muss deshalb beiseite.
            // data_dft führt je Sprache eine Zeile - die der Inhaltssprache des
            // Zieldatensatzes (languageExpr(), wie in select()).
            $this->db->pushResult();
            $sql = "SELECT `d`.`data` FROM `data_rel` `r`"
                 . " INNER JOIN `record` `t` ON `t`.`record_id` = `r`.`dest_id`"
                 . " AND `t`.`tmp`=0 AND `t`.`deleted`=0"
                 . ($published !== '' ? " AND " . $published : '')
                 . " LEFT JOIN `data_dft` `d` ON `d`.`record_id` = `r`.`dest_id`"
                 . " AND `d`.`language_id` = " . $this->languageExpr('t')
                 . " WHERE `r`.`src_id` = ? AND `r`.`fieldname_id` = ?"
                 . " ORDER BY `r`.`position` ASC LIMIT " . $limit;
            $this->db->query($sql, 'ii', [$srcId, $relField['fieldname_id']]);
            foreach ($this->db->fetchColumn() as $value) {
                $value = (string)$value;
                if ($value !== '') $values[] = $value;
            }
            $this->db->popResult();
        }

        // 'separator'
        $result = implode((string)($p['separator'] ?? '|'), $values);
        $this->_out($this->_decorate($result, $p), $p);
    }

    // CMS:RESULT_START / CMS:RESULT_END
    // 'no_action' => true: der Compiler erzeugt für diese beiden gar keinen Aufruf.
    // Ihre Wirkung steckt vollständig in php/loop_start.php und php/loop_end.php.
    // Die Methoden bleiben nur als Anlaufstelle stehen, falls die Klammern später
    // doch einmal Laufzeitarbeit brauchen.
    public function cmsResultStart($p): void
    {
    }

    public function cmsResultEnd($p): void
    {
    }

    // CMS:SAVEDATA
    //
    // Legt einen kompletten Datensatz unter $GLOBALS[{phpvar}] ab, damit das umgebende
    // PHP damit arbeiten kann. Ohne 'id' ist das schlicht der gerade aktuelle Datensatz.
    public function cmsSaveData($p): void
    {
        $name = $this->_name((string)($p['phpvar'] ?? ''));
        if ($name === '') {
            $this->error('CMS:SAVEDATA: parameter "phpvar" required.');
            return;
        }

        // 'id' - record_id oder slug; ohne Angabe der laufende Datensatz (_record())
        $GLOBALS[$name] = $this->_record($p);
    }

    // CMS:SELECT - returns template file (siehe templateFile())
    //
    // Ein Template sieht nur VERÖFFENTLICHTE Datensätze (publishedSql()): frei-
    // gegeben, Erscheinungsdatum erreicht, Ende nicht erreicht. Die Parameter
    // future, archive und state lockern das je Abfrage - auch in der Vorschau des
    // Backends (Page im Backend-Modus) bleibt es dabei; dort darf nur der über die
    // URL aufgerufene Datensatz selbst unveröffentlicht sein.
    public function cmsSelect($p): string
    {
        if ($this->includeDepth > (int)$this->config->get('admin.max_include_depth')) {
            $this->_cmsError('CMS:SELECT error: maximum inclusion depth exceeded.');
        }
        if (!$this->_oneOf(['filter','relation'],$p)) {
            $this->_cmsError('CMS:SELECT error: parameter "filter" or "relation" required.');
        }

        // Parameternamen des Befehls auf die von select() bringen: das Template
        // schreibt filter_op / filter_mode / hits (Namen aus commands.php), select()
        // kennt filterop / filtermode / result. Was select() nicht kennt (lang),
        // läuft ohne Wirkung mit.
        foreach (['filter_op' => 'filterop', 'filter_mode' => 'filtermode', 'hits' => 'result'] as $from => $to) {
            if (isset($p[$from]) && !isset($p[$to])) $p[$to] = $p[$from];
        }

        // 'relation' ohne 'src_id': die Verweise des laufenden Datensatzes
        // (Default laut commands.php). Ohne Quelle fiele der Relationsfilter in
        // select() stillschweigend weg und die Abfrage fände ALLE Datensätze.
        // Mit dest_id ist die Rückrichtung gemeint (wer verweist auf dest_id?) - dann
        // keine Quelle einsetzen.
        if (trim((string)($p['relation'] ?? '')) !== '' && (int)($p['src_id'] ?? 0) < 1
            && (int)($p['dest_id'] ?? 0) < 1) {
            $current = $GLOBALS['cms'] ?? null;
            $p['src_id'] = is_array($current) ? (int)($current['record_id'] ?? 0) : 0;
            // Kein laufender Datensatz (Trigger-Template, Zeitplaner ...): kein
            // Treffer statt aller Datensätze
            if ($p['src_id'] < 1) $p['sql'] = array_merge((array)($p['sql'] ?? []), ['0 = 1']);
        }

        // Veröffentlichungsbedingung - hinter ein evtl. vorhandenes 'sql' des
        // Templates (select() nimmt eine Liste von Bedingungen entgegen)
        $published = $this->publishedSql($p);
        if ($published !== '') {
            $p['sql'] = array_merge((array)($p['sql'] ?? []), [$published]);
        }
        // hits=false: nur zählen - ohne count=true gäbe es nichts zu zählen
        $countOnly = ((string)($p['result'] ?? '') === 'false');
        if ($countOnly) $p['count'] = 'true';

        $this->select($p);

        // Gesamtzahl für select_count.php merken (null = nichts übernehmen): Ohne
        // Treffer-Zeilen bindet hits=false kein resulttemplate ein, und popResult()
        // stellt danach die Zahlen des äußeren Ergebnisses wieder her - die Zählung
        // wäre sonst nirgends zu sehen. Ein Stapel, weil ein noresulttemplate selbst
        // wieder CMS:SELECT enthalten darf.
        $this->selectCountStack[] = $countOnly ? (int)$this->db->num_rows_unlimited : null;
        if ($countOnly && $this->db->num_rows_unlimited > 0) {
            // gezählt und gefunden: keine Ausgabe, auch kein noresult
            return '';
        }

        if ($this->db->num_rows > 0) {
            // 'resulttemplate'
            return $this->templateFile((string)($p['resulttemplate'] ?? ''));
        }
        if (!empty($p['noresult'])) {
            // 'noresult'
            echo (string)$p['noresult'];
            return '';
        }
        // 'noresulttemplate'
        return $this->templateFile((string)($p['noresulttemplate'] ?? ''));
    }

    // Gegenstück zu cmsSelect() (PHP-Schnipsel select_count hinter pop_result): mit
    // hits=false die Gesamtzahl der Zählung ins aufrufende Template übernehmen. Sie
    // ersetzt dort num_rows_unlimited des umgebenden Ergebnisses - num_rows und die
    // laufende Ergebnisschleife bleiben unberührt.
    public function selectCountEnd(): void
    {
        $count = array_pop($this->selectCountStack);
        if ($count !== null) $this->db->num_rows_unlimited = $count;
    }

    // CMS:SELECT_LANGVERSION - returns template file (siehe templateFile())
    //
    // Liefert die Sprachen des laufenden Auftritts (eigene Instanz + lesbare Instanzen,
    // Cms::$languages) als Ergebnismenge, damit ein Template daraus einen
    // Sprachumschalter bauen kann - Hauptsprache zuerst, dann die weiteren in der im
    // Backend festgelegten Reihenfolge. Aufbau wie CMS:SELECT: das Ergebnis bleibt im
    // Datenbank-Ergebnis stehen und wird im 'resulttemplate' mit CMS:RESULT_START
    // durchlaufen. Die Zeilen sind keine Datensätze - fetch() erkennt das am
    // fehlenden record_id und überspringt die virtuellen Felder.
    public function cmsSelectLanguages(array $p): string
    {
        // Die Sprachen kommen aus dem Dateicache (Page -> setLanguage()), nicht aus
        // der Datenbank; Database::setArrayResult() stellt sie der Ergebnisschleife
        // wie ein Query-Ergebnis bereit. Jede Zeile trägt zusätzlich 'current'
        // (1 = Sprache des laufenden Aufrufs).
        $rows = [];
        foreach ($this->languages as $languageId => $language) {
            $rows[] = [
                'language_id' => (int)$languageId,
                'code'        => (string)($language['code'] ?? ''),
                'label'       => (string)($language['label'] ?? ''),
                'endonym'     => (string)($language['endonym'] ?? ''),
                'current'     => ((int)$languageId === (int)$this->languageId) ? 1 : 0,
            ];
        }
        if (empty($rows)) {
            // Ohne Sprachkontext (Aufruf außerhalb des Frontends): alle Sprachen
            $this->db->query("SELECT *, 0 AS `current` FROM `language` ORDER BY `position` ASC");
        }
        else {
            $this->db->setArrayResult($rows);
        }
        if ($this->db->num_rows < 1) return '';
        return $this->templateFile((string)($p['resulttemplate'] ?? ''));
    }

    // CMS:TEXT
    //
    // Der Arbeitspferd-Befehl: Quelle holen, umschreiben, formatieren, ausgeben.
    // Die Reihenfolge der Schritte ist bewusst festgelegt und steht unten im Code.
    public function cmsText(array $p): void
    {
        $value = (string)$this->_source($p);

        // 'if' / 'else' - Wertetabelle. Zwei Schreibweisen:
        //     if[alt]="neu"       ein Eintrag je Vergleichswert
        //     if="alt?neu"        Kurzform mit Fragezeichen als Trenner
        if (isset($p['if'])) {
            $matched = false;
            foreach ($this->_pairs($p['if']) as $search => $replace) {
                if ($value === (string)$search) {
                    $value = (string)$replace;
                    $matched = true;
                    break;
                }
            }
            // 'else' - keine der IF-Abfragen traf zu
            if (!$matched && isset($p['else'])) {
                $value = (string)$p['else'];
            }
        }

        // 'replace' - Teiltexte ersetzen, gleiche zwei Schreibweisen wie 'if'
        $value = $this->_replace($value, $p);

        // 'trim' / 'striptags' / 'limit'
        if ($this->_bool($p['trim'] ?? false))      $value = trim($value);
        if ($this->_bool($p['striptags'] ?? false)) $value = trim($this->_strip_tags($value));
        if (isset($p['limit']) && (string)$p['limit'] !== '') {
            $value = $this->_strlimit($value, $p['limit']);
        }

        // 'markdown' - der Text ist Markdown (Extensions Markdown, MarkdownVisual) und
        // wird zu HTML (Camindo\Core\Markdown, HTML im Text maskiert). Die übrigen
        // Umwandlungen (Entities, Zeilenumbrüche, Verlinkung …) entfallen.
        if ($this->_bool($p['markdown'] ?? false)) {
            $this->_out($this->_decorate((new Markdown())->toHtml($value), $p), $p);
            return;
        }

        // 'urlencode' - der Text wird Teil einer URL. Alles Weitere (HTML-Umwandlung,
        // Verlinkung) wäre dort sinnlos und würde die Kodierung wieder zerstören.
        if ($this->_bool($p['urlencode'] ?? false)) {
            $this->_out($this->_decorate(rawurlencode($value), $p), $p);
            return;
        }

        // 'entitydecode' (Standard an) - &...; zurück in echte Zeichen
        if ($this->_bool($p['entitydecode'] ?? true)) {
            $value = $this->_decodeSpecialchars($value);
        }
        // 'specialchars' - und wieder zurück, diesmal vollständig maskiert
        if ($this->_bool($p['specialchars'] ?? false)) {
            $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
        // 'ampconvert' (Standard an) - einzelnes & maskieren, vorhandene Entities nicht
        elseif ($this->_bool($p['ampconvert'] ?? true)) {
            $value = preg_replace('/&(?![a-zA-Z][a-zA-Z0-9]{0,30};|#[0-9]{1,7};|#x[0-9a-fA-F]{1,6};)/', '&amp;', $value);
        }

        // 'urlconvert' / 'emailconvert' - Adressen klickbar machen
        $target = (string)($p['target'] ?? '_self');
        if ($this->_bool($p['urlconvert'] ?? false)) {
            $value = $this->_linkUrls($value, $target, (int)($p['urllimit'] ?? 80));
        }
        if (!empty($p['emailconvert']) && (string)$p['emailconvert'] !== 'false') {
            $value = $this->_linkEmails($value, $p);
        }

        // 'lbrconvert' (Standard an) - Zeilenumbrüche in <br>
        if ($this->_bool($p['lbrconvert'] ?? true)) {
            $value = $this->_nl2br($value);
        }

        // 'iso' / 'charset' - Ausgabe umkodieren
        $charset = $this->_bool($p['iso'] ?? false) ? 'ISO-8859-1' : (string)($p['charset'] ?? '');
        if ($charset !== '' && strtolower($charset) !== 'utf-8') {
            $value = mb_convert_encoding($value, $charset, 'UTF-8');
        }

        $this->_out($this->_decorate($value, $p), $p);
    }

    // CMS:URL
    //
    // Erzeugt die Frontend-URL eines Datensatzes im Format, das Page::resolveRequest()
    // wieder auseinandernimmt:  [/{lang}]/{slug|id}[,{template}] - siehe recordUrl().
    //
    // 'lang' zeigt auf eine andere Sprachversion (Sprachumschalter); ohne 'lang'
    // bleibt die Sprache des laufenden Requests erhalten.
    // 'id' nimmt - wie bei allen Befehlen - eine record_id ODER einen Slug, siehe
    // _record().
    //
    // Ohne 'id' in einer Ergebnisschleife von CMS:SELECT_LANGVERSION: die Zeilen
    // dort sind Sprachen, keine Datensätze - gemeint ist der äußere Datensatz, den
    // php/push_result.php vor der Schleife gesichert hat (Sprachumschalter).
    public function cmsUrl(array $p): void
    {
        $data = $this->_record($p);
        if ($this->_ref($p) === '' && !array_key_exists('record_id', $data)
            && array_key_exists('endonym', $data) && $this->rowStack) {
            $data = (array)(end($this->rowStack)[1] ?? []);
        }

        // 'lang' - Sprachversion, auf die der Link zeigt (Kürzel aus `language`)
        $lang = (string)($p['lang'] ?? '');
        // 'template' - zweiter URL-Teil, durch Komma getrennt; wahlweise mit
        // Bereichsanteil "bereich.name" (siehe templateFile())
        $template = $this->_templateRef((string)($p['template'] ?? ''));

        // 'absolute' / 'protocol' / 'host' - vollstaendige URL statt serverrelativem Pfad
        $host = (string)($p['host'] ?? '');
        $absolute = $this->_bool($p['absolute'] ?? false) || $host !== '';
        $protocol = (string)($p['protocol'] ?? '');

        // 'area' - Übersicht eines Bereichs (Präfix-Adresse, area.overview_template_id);
        // ohne 'id' und Datensatz auf einer Übersichtsseite: dieselbe Übersicht (etwa
        // im Sprachumschalter)
        $areaRef = trim((string)($p['area'] ?? ''));
        $areaId = $areaRef !== '' ? $this->areaIdByRef($areaRef)
            : (($this->_ref($p) === '' && $template === '' && empty($data['record_id'])) ? $this->overviewAreaId : 0);
        if ($areaId > 0) {
            $this->_out($this->areaUrl($areaId, $lang, $absolute, $host, $protocol), $p);
            return;
        }

        $this->_out($this->recordUrl($data, $lang, $template, $absolute, $host, $protocol), $p);
    }

    /**
     * URL eines Datensatzes - die eine Schreibweise, die CMS:URL erzeugt und die
     * Page::resolveRequest() bei canonical = 1 erzwingt.
     *
     *     /{lang}/{slug|id}[,{template}]     Instanz mit mehreren Sprachen
     *     /{slug|id}[,{template}]            Instanz mit einer Sprache
     *
     * Das Sprachsegment ist das Kürzel aus `language`; es steht IMMER in der URL,
     * sobald der Auftritt (eigene Instanz + lesbare Instanzen, Cms::$languages) mehr
     * als eine Sprache führt - so bleibt die Sprachversion über alle Links einer Seite
     * hinweg erhalten. Ohne 'lang' ist es die Sprache des laufenden Requests; ein
     * 'lang', das weder die Instanz noch ihre lesbaren Instanzen führen, wird
     * übergangen. Alles aus dem Dateicache, keine Datenbankabfrage.
     *
     * Hat der Datensatz einen Slug, gewinnt er - sonst zeigten zwei URLs auf dieselbe
     * Seite und die kanonische waere die seltener verlinkte. Record::hookOnSave() fuellt
     * das Feld beim Speichern aus dem Default-Feld des Bereichs; bleibt es leer, steht
     * die record_id in der URL.
     *
     * Zeigt 'lang' auf eine andere Sprache als die, in der $data gelesen wurde, und
     * ist das Slug-Feld des Bereichs mehrsprachig, gilt der Slug der Zielsprache
     * (languageSlug()) - Page::resolveRequest() sucht den Slug in der Sprache der URL.
     * Findet sich keiner, steht die record_id in der URL (bei canonical = 1 leitet
     * Page von dort auf den Slug um).
     *
     * Führt der Bereich einen Slug-Präfix (InstanceCache::slugPrefix()), steht er in
     * derselben Sprache vor dem Slug: /{lang}/{präfix}/{slug}. Die record_id bekommt
     * keinen - eine reine Ziffernfolge ist immer die Id (recordIdsBySlugPath()).
     *
     * @param array  $data      Datensatzinhalte (record_id, slug)
     * @param string $lang      Sprachkürzel oder '' (laufende Sprache)
     * @param string $template  Templatename oder ''
     * @param bool   $absolute  true: mit Protokoll und Host
     * @param string $host      Host für die absolute URL ('' = Haupt-Domain der
     *                          Instanz, sonst der aufgerufene Host)
     * @param string $protocol  'http' | 'https' | '' (Instanz bzw. Request)
     * @return string
     */
    public function recordUrl(array $data, string $lang = '', string $template = '', bool $absolute = false, string $host = '', string $protocol = ''): string
    {
        $slug = trim((string)($data['slug'] ?? ''));
        $id   = (int)($data['record_id'] ?? 0);

        $targetId = null;   // language_id aus 'lang', null = laufende Sprache
        $path = $this->languagePath($lang, $targetId);

        // Slug der Zielsprache - nur mit Datensatz-Id und einer 'lang', die von der
        // Sprache abweicht, in der $data gelesen wurde
        if ($id > 0 && $targetId !== null) {
            $instanceId = (int)($data['instance_id'] ?? ($this->instance['instance_id'] ?? 0));
            $slug = $this->languageSlug($data, $instanceId, $targetId) ?? $slug;
        }
        // Slug-Präfix des Bereichs in derselben Sprache
        if ($slug !== '') $slug = $this->slugWithPrefix($slug, $data, $targetId);

        $path .= ($slug !== '') ? $slug : $id;
        if ($template !== '') $path .= ',' . $template;

        return $absolute ? $this->absoluteUrl($path, $host, $protocol) : $path;
    }

    /**
     * URL der Übersicht eines Bereichs: die Adresse, die nur aus seinem Slug-Präfix
     * besteht - /{lang}/{präfix} (area.overview_template_id, Page::resolveRequest()).
     * Präfix in der Zielsprache ('lang') bzw. der laufenden. Ohne Präfix '' - der
     * Bereich hat keine eigene Adresse.
     *
     * @param int    $areaId
     * @param string $lang      Sprachkürzel oder '' (laufende Sprache)
     * @param bool   $absolute  true: mit Protokoll und Host (wie recordUrl())
     */
    public function areaUrl(int $areaId, string $lang = '', bool $absolute = false, string $host = '', string $protocol = ''): string
    {
        if ($areaId < 1) return '';
        $targetId = null;
        $path = $this->languagePath($lang, $targetId);
        $languageId = $targetId ?? $this->recordLanguageId($this->instanceCache->areaInstanceId($areaId));
        $prefix = $this->instanceCache->slugPrefix($areaId, $languageId);
        if ($prefix === '') return '';
        $path .= $prefix;
        return $absolute ? $this->absoluteUrl($path, $host, $protocol) : $path;
    }

    /**
     * Bereich, dessen Übersicht die Adresse $path meint: sein Slug-Präfix in der
     * laufenden Sprache ist genau $path, und er hat ein Übersichts-Template. Nur
     * Bereiche der Instanzen dieses Auftritts. 0 = keiner.
     */
    public function overviewAreaIdByPath(string $path): int
    {
        $path = mb_strtolower(trim($path, '/'), 'UTF-8');
        if ($path === '' || str_contains($path, '/')) return 0;
        $instanceIds = $this->accessInstanceIds ?: (array)($this->instanceCache->all()['instance_ids'] ?? []);
        foreach ($this->instanceCache->slugPrefixAreas(array_map('intval', $instanceIds)) as $areaId => $prefixes) {
            if (empty($this->instanceCache->areaInfo($areaId)['overview_template_id'])) continue;
            $languageId = $this->recordLanguageId($this->instanceCache->areaInstanceId($areaId));
            if (mb_strtolower($this->instanceCache->slugPrefix($areaId, $languageId), 'UTF-8') === $path) return (int)$areaId;
        }
        return 0;
    }

    /**
     * Sprachsegment einer URL: '/{code}/' bei mehr als einer Sprache im Auftritt
     * (eigene Instanz + lesbare Instanzen, siehe $languages), sonst '/'. Ein 'lang',
     * das keine davon führt, wird übergangen. $targetId: language_id aus 'lang',
     * null = laufende Sprache.
     */
    private function languagePath(string $lang, ?int &$targetId): string
    {
        $path = '/';
        $targetId = null;
        $languages = $this->languages;
        if (count($languages) > 1) {
            $code = '';
            $lang = preg_replace('/[^a-zA-Z0-9\-_]/', '', $lang);
            if ($lang !== '') {
                $language = $this->instanceCache->findByCode($languages, $lang);
                if ($language !== null) {
                    $code = (string)$language['code'];
                    $targetId = (int)$language['language_id'];
                }
            }
            if ($code === '') $code = $this->languageCode;
            if ($code === '') {
                // Sprache des Requests unbekannt (kein Frontend-Kontext) -> Hauptsprache
                $first = reset($languages);
                $code = (string)($first['code'] ?? '');
            }
            if ($code !== '') $path .= $code . '/';
        }
        return $path;
    }

    /** Serverrelativen Pfad zur absoluten URL machen (Protokoll, Host der Instanz). */
    private function absoluteUrl(string $path, string $host = '', string $protocol = ''): string
    {
        if ($protocol === '') {
            $protocol = (string)($this->instance['protocol'] ?? '');
        }
        if ($protocol === '') {
            $protocol = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        }
        if ($host === '') $host = (string)($this->instance['primary_domain'] ?? '');
        if ($host === '') $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        return $protocol . '://' . $host . $path;
    }

    /**
     * Slug eines Datensatzes in einer anderen Sprachversion (CMS:URL mit 'lang').
     *
     * Gelesen wird die `data_*`-Zeile, die Page::resolveRequest() beim Aufruf der
     * Ziel-URL per Slug-Filter trifft: die Sprache, die der Request in der Zielsprache
     * für die Instanz des Datensatzes liest (die Zielsprache, wenn die Instanz sie
     * führt, sonst ihre Hauptsprache - wie Page::resolveLanguage()). Die Zeile ist
     * fallback-gefüllt: ohne Übersetzung steht dort der Slug der Hauptsprache.
     * Eine Abfrage über den Primärschlüssel je Datensatz und Sprache, für den Request
     * gemerkt; das Schema des Bereichs (mehrsprachig?) kommt aus loadSimpleSchema().
     *
     * @param array $data        Datensatzinhalte (record_id, area_id, slug)
     * @param int   $instanceId  Instanz des Datensatzes
     * @param int   $targetId    language_id der Zielsprache
     * @return string|null       Slug der Zielsprache, '' = keiner (record_id verwenden),
     *                           null = derselbe wie in $data (gleiche Sprache bzw. Feld
     *                           nicht mehrsprachig)
     */
    private function languageSlug(array $data, int $instanceId, int $targetId): ?string
    {
        $id = (int)($data['record_id'] ?? 0);

        // Sprache, die ein Request in der Zielsprache für diese Instanz liest
        $ids = $instanceId > 0 ? $this->instanceCache->languageIds($instanceId) : [];
        if (!empty($ids) && !in_array($targetId, $ids, true)) $targetId = (int)$ids[0];
        if ($targetId === $this->recordLanguageId($instanceId)) return null;

        $vf = $this->resolveVirtualField('slug');
        if ($vf === null) return null;

        // Kein oder ein einsprachiges Slug-Feld im Bereich: nichts nachzuschlagen,
        // die Zeilen aller Sprachen tragen denselben Wert
        $areaId = (int)($data['area_id'] ?? 0);
        if ($areaId > 0) {
            $this->loadSimpleSchema($areaId);
            $field = null;
            foreach ($this->virtualSchemaCache[$areaId] as $f) {
                if ((string)$f['name'] === 'slug') { $field = $f; break; }
            }
            if ($field === null || !$this->isMultilingualField($field)) return null;
        }

        $key = $id . ':' . $targetId;
        if (!array_key_exists($key, $this->slugCache)) {
            $this->db->pushResult();
            $this->db->query(
                'SELECT `data` FROM `' . $this->virtualDataTable($vf['type']) . '`'
                . ' WHERE `language_id` = ? AND `record_id` = ? AND `fieldname_id` = ?',
                'iii', [$targetId, $id, (int)$vf['fieldname_id']]
            );
            $this->slugCache[$key] = ($this->db->num_rows > 0)
                ? trim((string)($this->db->fetchNum()[0] ?? ''))
                : '';
            $this->db->popResult();
        }
        return $this->slugCache[$key];
    }

    /**
     * Slug mit dem Slug-Präfix seines Bereichs: "farben/blau" (URL-Pfad ohne
     * Sprachsegment). Ohne Präfix oder ohne area_id in $data bleibt der Slug, wie er ist.
     *
     * Sprache des Präfixes: $targetId (CMS:URL mit 'lang'; eine Sprache, die die
     * Instanz des Bereichs nicht führt, liest deren Hauptsprache), sonst die, in der
     * der Request die Instanz des Bereichs liest (recordLanguageId()) - also dieselbe
     * Sprache wie der Slug.
     *
     * @param string   $slug      Slug des Datensatzes ('' = keiner)
     * @param array    $data      Datensatzinhalte (area_id)
     * @param int|null $targetId  language_id der Zielsprache, null = laufende Sprache
     */
    public function slugWithPrefix(string $slug, array $data, ?int $targetId = null): string
    {
        $areaId = (int)($data['area_id'] ?? 0);
        if ($slug === '' || $areaId < 1) return $slug;
        $instanceId = $this->instanceCache->areaInstanceId($areaId);
        $languageId = $targetId ?? $this->recordLanguageId($instanceId);
        $prefix = $this->instanceCache->slugPrefix($areaId, $languageId);
        return ($prefix !== '') ? $prefix . '/' . $slug : $slug;
    }

    /**
     * URL-Pfad (ohne Sprachsegment) -> record_ids: die Auflösung, die Page und alle
     * Befehle mit 'id' teilen.
     *
     *     farben/blau          Gibt es einen Bereich mit dem Slug-Präfix "farben"?
     *                          Ja: darin den Datensatz mit dem Slug "blau".
     *                          Nein: den Datensatz mit dem Slug "farben/blau" - nur in
     *                          Bereichen OHNE Präfix (sonst hätte derselbe Datensatz
     *                          zwei URLs).
     *
     * Gesucht wird in den Instanzen des Requests (accessInstanceIds(); keine
     * Einschränkung = alle) je Instanz in der Sprache, die der Request dort liest
     * (recordLanguageId()) - Präfix und Slug in derselben Sprache. $languageMap
     * gibt sie ausdrücklich vor (Import: Hauptsprache der Zielinstanz).
     *
     * Eindeutig ist ein Pfad, solange Record und Area ihre Prüfungen machen konnten
     * (Record::slugPathOk(), Area::validateSlugPrefix()); mehr als eine Id ist eine
     * Inkonsistenz der Datenbank - der Aufrufer meldet "Ambiguous path".
     *
     * @param string $path         URL-Pfad ohne Sprachsegment und Template
     * @param string $published    zusätzliche Bedingung auf `record` (publishedSql())
     * @param array  $languageMap  instance_id => language_id (Vorgabe statt Request)
     * @param int[]  $instanceIds  Instanzen statt accessInstanceIds()
     * @return int[]  0, 1 oder (mehrdeutig) 2 record_ids
     */
    public function recordIdsBySlugPath(string $path, string $published = '', array $languageMap = [], array $instanceIds = []): array
    {
        $path = trim($path);
        // eine reine Ziffernfolge ist die record_id, nie ein Slug (Record::hookOnSave())
        if ($path === '' || preg_match('/^\d+$/', $path)) return [];
        $vf = $this->resolveVirtualField('slug');
        if ($vf === null) return [];

        if (empty($instanceIds)) $instanceIds = $this->accessInstanceIds;
        if (empty($instanceIds)) $instanceIds = (array)($this->instanceCache->all()['instance_ids'] ?? []);
        $instanceIds = array_values(array_filter(array_map('intval', $instanceIds)));
        if (empty($instanceIds)) return [];

        // Sprache je Instanz
        $languages = [];
        foreach ($instanceIds as $instanceId) {
            $languages[$instanceId] = (int)($languageMap[$instanceId] ?? $this->recordLanguageId($instanceId));
        }

        // Bereiche mit Präfix in der Sprache ihrer Instanz (klein geschrieben - die
        // Spalte `data` vergleicht ohne Rücksicht auf Groß-/Kleinschreibung)
        $prefixed = [];
        foreach ($this->instanceCache->slugPrefixAreas($instanceIds) as $areaId => $prefixes) {
            $instanceId = $this->instanceCache->areaInstanceId($areaId);
            $prefix = $this->instanceCache->slugPrefix($areaId, $languages[$instanceId] ?? 0);
            if ($prefix !== '') $prefixed[$areaId] = mb_strtolower($prefix, 'UTF-8');
        }

        $slash = strpos($path, '/');
        if ($slash !== false && !empty($prefixed)) {
            $areaIds = array_keys($prefixed, mb_strtolower(substr($path, 0, $slash), 'UTF-8'), true);
            if (!empty($areaIds)) {
                $rest = trim(substr($path, $slash + 1));
                if ($rest === '') return [];
                return $this->slugRecordIds($vf, $rest, $languages, $areaIds, [], $published);
            }
        }
        return $this->slugRecordIds($vf, $path, $languages, [], array_keys($prefixed), $published);
    }

    /**
     * Abfrage zu recordIdsBySlugPath(): produktive Datensätze mit diesem Slug.
     *
     * @param array $vf          Slug-Feld (resolveVirtualField())
     * @param array $languages   instance_id => language_id (zugleich die Instanzen)
     * @param int[] $inAreas     nur diese Bereiche (leer = alle)
     * @param int[] $notInAreas  diese Bereiche nicht
     * @return int[]  höchstens 2 record_ids
     */
    private function slugRecordIds(array $vf, string $slug, array $languages, array $inAreas, array $notInAreas, string $published): array
    {
        // Sprache je Instanz: literal (nur Zahlen). Das IN vorab hält den Index
        // (language_id, fieldname_id, data) nutzbar, der CASE ordnet genau zu.
        $languageIds = array_values(array_unique($languages));
        if (count($languageIds) === 1) {
            $languageSql = '`d`.`language_id` = ' . (int)$languageIds[0];
        }
        else {
            $cases = '';
            foreach ($languages as $instanceId => $languageId) {
                $cases .= ' WHEN ' . (int)$instanceId . ' THEN ' . (int)$languageId;
            }
            $languageSql = '`d`.`language_id` IN (' . implode(',', array_map('intval', $languageIds)) . ')'
                . ' AND `d`.`language_id` = CASE `record`.`instance_id`' . $cases . ' END';
        }
        $sql = 'SELECT DISTINCT `record`.`record_id` FROM `' . $this->virtualDataTable($vf['type']) . '` `d`'
             . ' INNER JOIN `record` ON `record`.`record_id` = `d`.`record_id` AND `record`.`tmp` = 0 AND `record`.`deleted` = 0'
             . ' WHERE ' . $languageSql . ' AND `d`.`fieldname_id` = ? AND `d`.`data` = ?'
             . ' AND `record`.`instance_id` IN (' . implode(',', array_map('intval', array_keys($languages))) . ')';
        if (!empty($inAreas)) {
            $sql .= ' AND `record`.`area_id` IN (' . implode(',', array_map('intval', $inAreas)) . ')';
        }
        if (!empty($notInAreas)) {
            $sql .= ' AND `record`.`area_id` NOT IN (' . implode(',', array_map('intval', $notInAreas)) . ')';
        }
        if ($published !== '') $sql .= ' AND ' . $published;
        $sql .= ' LIMIT 2';

        $this->db->pushResult();
        $this->db->query($sql, 'is', [(int)$vf['fieldname_id'], $slug]);
        $ids = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
        return $ids;
    }

    // CMS:XML
    // Muss als erstes im Template stehen - davor darf kein Zeichen ausgegeben werden.
    public function cmsXml($p): void
    {
        $version  = (string)($p['version']  ?? '1.0');
        $encoding = (string)($p['encoding'] ?? 'utf-8');
        echo '<?xml version="' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8')
           . '" encoding="' . htmlspecialchars($encoding, ENT_QUOTES, 'UTF-8') . '"?>';
    }


    // Hilfsfunctionen für die CMS-Befehle
    // ====================


    /**
     * Quelle eines Ausgabebefehls ermitteln: 'id', 'field' oder 'useval'.
     *
     * 'id' lädt einen fremden Datensatz und liest daraus 'field' (Standard: 'text').
     * Ohne 'id' kommt der Wert aus dem aktuellen Datensatz $GLOBALS['cms'].
     *
     * Rückgabe ist nicht zwingend ein String - Datei- und JSON-Felder liefern Arrays
     * (siehe cmsMedia()).
     *
     * @param array $p  Befehlsparameter
     * @return mixed    Rohwert, '' wenn nichts zu holen ist
     */
    private function _source(array $p): mixed
    {
        $field = (string)($p['field'] ?? '');

        // 'id' verweist auf einen anderen Datensatz. Ohne 'field' ist dann das Feld
        // 'text' gemeint - so war es in C8, und nur CMS:TEXT laesst 'id' allein zu
        // (bei allen anderen Befehlen verlangt 'oneof' ohnehin 'field' oder 'useval').
        if ($this->_ref($p) !== '' && $field === '') $field = 'text';

        if ($field !== '') {
            return $this->_record($p)[$field] ?? '';
        }

        // 'useval' - Wert steht woertlich im Template
        return $p['useval'] ?? '';
    }

    /**
     * Wert des Parameters 'id' als Verweis lesen.
     *
     * @return string  '' wenn kein Verweis angegeben ist
     */
    private function _ref(array $p): string
    {
        return is_scalar($p['id'] ?? null) ? trim((string)$p['id']) : '';
    }

    /**
     * Den Datensatz ermitteln, auf den sich ein Befehl bezieht.
     *
     * Ohne 'id' ist das der laufende Datensatz - der, den Page::resolveRequest()
     * geladen hat, bzw. die Zeile, bei der die Ergebnisschleife gerade steht.
     *
     * Mit 'id' wird der angegebene Datensatz geladen, und zwar nach derselben Regel,
     * nach der auch Page::resolveRequest() eine URL aufloest:
     *
     *     reine Ziffernfolge   -> record_id, direkt per SQL
     *     alles andere         -> URL-Pfad [präfix/]slug, recordIdsBySlugPath()
     *                             (mehrdeutig: wie nicht gefunden)
     *
     * Damit ist 'id' im Template dasselbe wie das, was in der URL steht - der Nachfolger
     * des C8-'idname'. Ein Slug ist nie eine reine Ziffernfolge, die Unterscheidung ist
     * also eindeutig (siehe Record::hookOnSave()).
     *
     * Auch die Veröffentlichung prüft 'id' wie die URL im Frontend: freigegeben und
     * Erscheinungsdatum erreicht, abgelaufene (offline_at) bleiben lesbar
     * (publishedSql(['archive' => true])). Das gilt auch in der Vorschau des
     * Backends - wie bei CMS:SELECT darf dort nur der über die URL aufgerufene
     * Datensatz selbst unveröffentlicht sein (der kommt als laufender Datensatz).
     *
     * Geladene Datensaetze werden fuer den Request gemerkt: derselbe Verweis in einer
     * Ergebnisschleife kostet nur einmal eine Abfrage.
     *
     * @param array $p  Befehlsparameter
     * @return array    Datensatzinhalte, leer wenn es den Verweis nicht gibt
     */
    private function _record(array $p): array
    {
        $ref = $this->_ref($p);
        $current = $GLOBALS['cms'] ?? [];

        // Kein Verweis, oder einer auf den laufenden Datensatz -> der steht schon da
        if ($ref === '') return $current;
        if ($ref === (string)($current['record_id'] ?? '')) return $current;
        if ($ref === $this->slugWithPrefix(trim((string)($current['slug'] ?? '')), $current)) return $current;

        if (array_key_exists($ref, $this->recordRefCache)) {
            return $this->recordRefCache[$ref];
        }

        $data = [];
        $published = $this->publishedSql(['archive' => true]);
        // Der Befehl kann innerhalb einer Ergebnisschleife stehen - das laufende
        // Ergebnis muss deshalb beiseite.
        $this->db->pushResult();
        // record_id: ueber die Id sind weder virtuelle Felder noch Filterlogik noetig;
        // sonst URL-Pfad [präfix/]slug - dieselbe Auflösung wie im Aufruf der Seite
        $ids = preg_match('/^\d+$/', $ref) ? [(int)$ref] : $this->recordIdsBySlugPath($ref, $published);
        if (count($ids) === 1) {
            $this->db->query(
                "SELECT * FROM `record` WHERE `record_id` = ? AND `tmp`=0 AND `deleted`=0 AND {$published}",
                'i',
                [(int)$ids[0]]
            );
            if ($this->db->num_rows > 0) $data = $this->fetch() ?? [];
        }
        $this->db->popResult();

        $this->recordRefCache[$ref] = $data;
        return $data;
    }

    /**
     * ifempty / instead / prefix / suffix anwenden - siehe Konventionen oben.
     *
     * @param string $value        aufbereiteter Wert
     * @param array  $p            Befehlsparameter
     * @param bool   $zeroIsEmpty  true: auch "0" gilt als leer (CMS:NUMBER, CMS:MEDIA)
     */
    private function _decorate(string $value, array $p, bool $zeroIsEmpty = false): string
    {
        $isEmpty = ($value === '') || ($zeroIsEmpty && $value === '0');

        if ($isEmpty) {
            // 'ifempty' ersetzt die gesamte Ausgabe, prefix/suffix entfallen
            return (string)($p['ifempty'] ?? '');
        }
        // 'instead' - Wert austauschen, Formatierung war trotzdem nötig (leer/nicht leer)
        if (isset($p['instead']) && (string)$p['instead'] !== '') {
            $value = (string)$p['instead'];
        }
        return (string)($p['prefix'] ?? '') . $value . (string)($p['suffix'] ?? '');
    }

    /**
     * Ergebnis ausgeben - oder mit 'set' nach $GLOBALS['set'] umleiten.
     */
    private function _out(string $html, array $p): void
    {
        if (!empty($p['set'])) $GLOBALS['set'][$p['set']] = $html;
        else echo $html;
    }

    /**
     * Parameter, die Paare beschreiben ('if', 'replace'), auf eine Form bringen.
     *
     *     if[alt]="neu"     ->  ['alt' => 'neu']
     *     if="alt?neu"      ->  ['alt' => 'neu']
     *     if[]="a?b" …      ->  ['a' => 'b', …]
     *
     * @return array  Suchwert => Ersatzwert
     */
    private function _pairs(mixed $param): array
    {
        $pairs = [];
        foreach ((array)$param as $key => $value) {
            // PHP macht aus einem Ziffern-Schlüssel (if[1]="x") einen int-Schlüssel -
            // er ist dann nicht von der Listenform (if[]="1?x", if="1?x") zu
            // unterscheiden. Regel: int-Schlüssel + Fragezeichen im Wert = Kurzform
            // "suchen?ersetzen", sonst ist der Schlüssel selbst der Suchwert. Nicht
            // eindeutig bleibt nur if[1]="Text mit ?" - dafür if[]="1?Text mit ?".
            if (is_int($key) && str_contains((string)$value, '?')) {
                $parts = explode('?', (string)$value, 2);
                $pairs[$parts[0]] = $parts[1];
            }
            else {
                $pairs[$key] = $value;
            }
        }
        return $pairs;
    }

    /**
     * Parameter 'replace' anwenden - Teiltexte im Wert ersetzen.
     *
     *     replace="alt?neu"          Kurzform, Fragezeichen als Trenner
     *     replace[alt]="neu"         ein Eintrag je Suchtext
     *     replace[]="a?b" …          mehrere Kurzformen
     *
     * Bei CMS:TEXT wirkt es auf die Quelle, bei CMS:DATE/DATETIME/TIME und CMS:NUMBER
     * auf den bereits formatierten Wert (z. B. replace=".?," oder replace=".00?").
     *
     * @param string $value  Wert, in dem ersetzt wird
     * @param array  $p      Befehlsparameter
     */
    private function _replace(string $value, array $p): string
    {
        if (!isset($p['replace'])) return $value;
        foreach ($this->_pairs($p['replace']) as $search => $replace) {
            if ((string)$search !== '') {
                $value = str_replace((string)$search, (string)$replace, $value);
            }
        }
        return $value;
    }

    /**
     * Template-Parameter als Wahrheitswert lesen.
     * Werte kommen aus dem Template immer als String an ("true"/"false").
     */
    private function _bool(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        return filter_var((string)$value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Gemeinsame Laufzeit von CMS:DATE, CMS:DATETIME und CMS:TIME.
     *
     * Die drei Befehle unterscheiden sich nur darin, welchen Teil des Wertes sie
     * zeigen; Quelle, Formatierung und Zeitzonen-Angabe sind identisch.
     *
     *     'format'    ICU-Muster (dd.MM.y HH:mm). Ohne Angabe entscheiden
     *                 _formatDate() bzw. _formatTime() anhand der Locale des Users.
     *     'timezone'  true: Name der Zeitzone anhängen
     *     'autotime'  nur CMS:DATETIME und nur zusammen mit 'format': war im Wert
     *                 keine Uhrzeit gesetzt (00:00:00), wird sie auch nicht angezeigt.
     *                 Ohne 'format' lässt _formatDate() sie ohnehin weg.
     *
     * @param array  $p     Befehlsparameter
     * @param string $mode  'date' | 'datetime' | 'time'
     */
    private function _dateValue(array $p, string $mode): string
    {
        $value = trim((string)$this->_source($p));
        if ($value === '') return '';
        // Platzhalter (kein Datum / kein Ende, z. B. ein unbegrenztes offline_at)
        // gelten immer als leer - mit und ohne 'format' ('ifempty' greift)
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)
            && ($value < self::DATE_MIN_DISPLAY || $value >= self::DATE_MAX_DISPLAY)) return '';

        // 'timezone' - siehe _formatDate() / _formatTime()
        $displayTimezone = $this->_bool($p['timezone'] ?? false);

        // War im Rohwert überhaupt eine Uhrzeit gesetzt?
        $hasTime = (strpos($value, ' ') !== false) && (substr($value, -8) !== '00:00:00');

        // CMS:DATE: Uhrzeit abschneiden - _formatDate() lässt 00:00 von selbst weg
        if ($mode === 'date' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m)) {
            $value = $m[1] . ' 00:00:00';
            $hasTime = false;
        }

        $format = (string)($p['format'] ?? '');
        if ($format !== '') {
            $value = $this->_formatPattern($value, $format, $displayTimezone);
            // 'autotime'
            if ($mode !== 'time' && !$hasTime && $this->_bool($p['autotime'] ?? false)) {
                $value = trim(preg_replace('/\s*\d{1,2}:\d{2}(:\d{2})?\s*/u', ' ', $value));
            }
            return $value;
        }

        return ($mode === 'time')
            ? $this->_formatTime($value, $displayTimezone)
            : $this->_formatDate($value, $displayTimezone);
    }

    /**
     * Datum nach ICU-Muster formatieren (dd.MM.y HH:mm).
     * Ohne intl-Erweiterung bleibt der Rohwert stehen.
     *
     * @param bool $displayTimezone  Name der Zeitzone anhängen (wie _formatDate())
     */
    private function _formatPattern(string $isoDate, string $pattern, bool $displayTimezone = false): string
    {
        try {
            $date = new \DateTimeImmutable($isoDate);
        } catch (\Exception $e) {
            return $isoDate;
        }
        $timezone = $date->getTimezone();
        $formatter = $this->_intlFormatter(
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            $timezone
        );
        if ($formatter === null) return $isoDate;
        $formatter->setPattern($pattern);
        $formatted = $formatter->format($date);
        if ($formatted === false) return $isoDate;
        return $formatted . ($displayTimezone ? ' ' . $timezone->getName() : '');
    }

    /**
     * Nachkommastellen einer Zahl bestimmen, gedeckelt auf 6 -
     * damit CMS:NUMBER ohne 'format' weder rundet noch Nullen anhängt.
     */
    private function _fractionDigits(float $number): int
    {
        $text = rtrim(rtrim(sprintf('%.6F', $number), '0'), '.');
        $dot = strpos($text, '.');
        return ($dot === false) ? 0 : strlen($text) - $dot - 1;
    }

    /**
     * Bytes lesbar machen: 15728640 -> "15 MB"
     *
     * @param float $bytes
     * @param int   $factor  1000 (SI) oder 1024 (binär)
     */
    private function _formatBytes(float $bytes, int $factor = 1024): string
    {
        if ($factor < 2) $factor = 1024;
        $units = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $step = 0;
        while ($bytes >= $factor && $step < count($units) - 1) {
            $bytes /= $factor;
            $step++;
        }
        // ohne Locale - der Punkt steht so auch in der Befehlsbeschreibung ("15.2 GB")
        return number_format($bytes, $step > 0 ? 1 : 0, '.', '') . ' ' . $units[$step];
    }

    /**
     * http(s)- und www-Adressen im Text verlinken.
     *
     * @param string $text
     * @param string $target  Link-Target
     * @param int    $limit   sichtbare Länge der Adresse (0 = ungekürzt)
     */
    private function _linkUrls(string $text, string $target, int $limit): string
    {
        $pattern = '~(?<![\w/@."\'])((?:https?://|www\.)[^\s<>"\']+[^\s<>"\'.,;:!?)])~i';
        return preg_replace_callback($pattern, function ($m) use ($target, $limit) {
            $url = $m[1];
            $href = (stripos($url, 'http') === 0) ? $url : 'http://' . $url;
            $label = ($limit > 0) ? $this->_strlimit($url, $limit) : $url;
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
                 . ' target="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '">'
                 . $label . '</a>';
        }, $text) ?? $text;
    }

    /**
     * E-Mail-Adressen im Text verlinken.
     *
     * 'emailconvert' = 'encrypt' schreibt den Link per JavaScript zusammen; im
     * Quelltext der Seite steht dann keine vollständige Adresse mehr - genug gegen
     * einfache Adress-Sammler, kein Schutz gegen gezieltes Auslesen. Das Script
     * trägt die Nonce der Seite (cspNonce()), läuft also auch unter CSP enforce.
     *
     * @param string $text
     * @param array  $p  Befehlsparameter ('emailconvert', 'emailtext', 'emaillimit')
     */
    private function _linkEmails(string $text, array $p): string
    {
        $mode  = (string)$p['emailconvert'];
        $limit = (int)($p['emaillimit'] ?? 0);
        $label = (string)($p['emailtext'] ?? '');

        $pattern = '~(?<![\w.@])([\w.+-]+)@([\w-]+(?:\.[\w-]+)+)~';
        $nonce = ($mode === 'encrypt') ? $this->cspNonce() : '';
        return preg_replace_callback($pattern, function ($m) use ($mode, $limit, $label, $nonce) {
            $address = $m[0];
            $visible = $label !== '' ? $label : ($limit > 0 ? $this->_strlimit($address, $limit) : $address);

            if ($mode === 'encrypt') {
                // Adresse in Teilen an document.write übergeben - jeder Teil als
                // JS-String mit < > & ' " als \u00.. (kein </script> im Text), das @
                // auch im sichtbaren Text getrennt
                $js = static fn(string $v): string => (string)json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS
                    | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $code = "document.write('<a href=\"mai'+'lto:'+" . $js($m[1]) . "+'@'+" . $js($m[2])
                      . "+'\">'+" . implode("+'@'+", array_map($js, explode('@', $visible))) . "+'</a>');";
                return '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '">' . $code . '</script>';
            }
            return '<a href="mailto:' . htmlspecialchars($address, ENT_QUOTES, 'UTF-8') . '">'
                 . $visible . '</a>';
        }, $text) ?? $text;
    }

    /**
     * Ist mindestens einer der Schlüssel in $p vorhanden?
     *
     * @param array $a  gesuchte Schlüssel
     * @param array $p  Befehlsparameter
     */
    private function _oneOf($a, $p): bool
    {
        return !empty(array_intersect($a, array_keys($p)));
    }

    // Typisierung als Name
    private function _name(string $s): string
    {
        return preg_replace('/[^a-z0-9_]/i', '', $s);
    }

    // Typisierung als Template-Referenz "[bereich.]name"
    private function _templateRef(string $s): string
    {
        [$areaName, $name] = $this->splitTemplateRef($s);
        if ($name === '') return '';
        return ($areaName !== '') ? $areaName . '.' . $name : $name;
    }

    // Fehlermeldung ausgeben
    private function _cmsError($t): void
    {
        die($t);
    }
}
