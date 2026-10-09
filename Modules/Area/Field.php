<?php
/**
 * Dataset-Kindklasse für die Tabelle `field` - die Eingabefelder eines Inhaltsbereichs.
 *
 * Ein Feld ist die Definition, kein Inhalt: Typ, Beschriftung, Darstellungsart,
 * Validierungsregeln. Die eigentlichen Werte liegen typgetrennt in den `data_*`-Tabellen
 * (EAV, siehe docs/developers/02-datenmodell.md).
 *
 * DREI TABELLEN, DIE MIT GEPFLEGT WERDEN
 * --------------------
 *     fieldname          globale Registrierung name -> typ, area-übergreifend eindeutig.
 *                        Der Primärschlüssel der data_*-Tabellen ist
 *                        (record_id, fieldname_id) - NICHT field_id. Dadurch kann
 *                        Cms::select() über Bereichsgrenzen hinweg nach einem Feldnamen
 *                        filtern, ohne die field_id je Bereich zu kennen. Preis dafür:
 *                        ein Name ist systemweit an genau einen Typ gebunden.
 *                        -> createFieldname() / cleanupFieldname()
 *     rel_field_area     Ziel-Bereiche eines 'pointer'-Feldes (n Bereiche)
 *     rel_fulltext_text  Quell-Felder eines 'fulltext'-Feldes
 *     rel_field_converter  Konverter eines Thumbnail-Feldes, mit `position` - die
 *                        Reihenfolge, in der die Thumbnail-Erzeugung sie durchgeht
 *                        (VirtualFieldsTrait: der erste passende gewinnt)
 *
 * RESERVIERTE NAMENSPRÄFIXE
 * --------------------
 * $fieldPrefixes wird aus Cms::$dataSuffix aufgebaut (txt_, int_, fil_, …) plus vier
 * Sonderfällen. Ein Name darf nicht mit dem Kürzel eines ANDEREN Typs beginnen - `txt_x`
 * als Integer-Feld wird abgewiesen. Der Sinn: der Name allein soll den Typ verraten,
 * ohne dass man in `fieldname` nachsehen muss.
 *
 * FELDTYPEN MIT SONDERBEHANDLUNG
 * --------------------
 *     relation / relation1   Verknüpfung in einen anderen Bereich. Beim Anlegen wird im
 *                            ZIELBEREICH automatisch ein Gegenfeld erzeugt (Name
 *                            `rel_{areaname}`), damit die Verknüpfung von beiden Seiten
 *                            sichtbar ist. rel_field_id zeigt jeweils auf die Gegenseite.
 *     pointer / pointer1     Verweis auf Datensätze aus MEHREREN Bereichen. Die Liste
 *                            der Zielbereiche passt nicht in eine Spalte und liegt
 *                            deshalb in `rel_field_area`; die Schemaspalte
 *                            `rel_area_ids` bleibt leer.
 *     link                   Fest verknüpfte Inhalte (1:n): wie 'pointer' gerichtet
 *                            mit mehreren Zielbereichen (`rel_field_area`), aber die
 *                            Zieldatensätze GEHÖREN dem Quelldatensatz - je
 *                            Zieldatensatz höchstens ein Quelldatensatz, Löschen,
 *                            Kopieren und Verschieben des Quelldatensatzes wirken
 *                            auf sie mit (Camindo\Core\LinkTrait). Zielbereiche
 *                            müssen abhängige Bereiche sein (`area.dependent` = 1).
 *                            display_type 'lookup' (Suchfenster wie bisher) oder
 *                            'embed' (die Eingabemasken der Zieldatensätze stehen in
 *                            der Maske des Quelldatensatzes). Immer handsortiert
 *                            (custom_order = 1), ohne Höchstzahl; nicht umwandelbar.
 *     fulltext               Sammelfeld, das den Text mehrerer anderer Felder für die
 *                            Volltextsuche bündelt. Die Quell-Felder stehen in
 *                            `rel_fulltext_text`, im Formular unter dem Pseudo-Feld
 *                            data['source_fields'].
 *     file + display_type    Thumbnail, aus einem Upload-Feld (parent_field_id)
 *     'thumbnail'            erzeugt. Seine Konverter stehen sortiert in
 *                            `rel_field_converter`, im Schema als 'reltable'-Feld
 *                            `converter_ids` (display_type 'sortable': die Reihenfolge
 *                            der angehakten Optionen ist der Wert). Mindestens einer
 *                            ist Pflicht. Bis 2026-09-15 lagen die Ids unsortiert in
 *                            details.converter_ids.
 *     relation/pointer +     details.lookup_detailed: 1 = die Vorschau der verknüpften
 *     display_type 'lookup'  Datensätze zeigt untereinander alle Listenfelder des
 *                            Zielbereichs (Default-Feld fett) und dessen
 *                            Listen-Template, statt nur des Default-Felds
 *                            (RecordBackendEdit::_lookupPreviewCreate()).
 *     file + display_type    'thumbnail' erzeugt abgeleitete Medienversionen, verlangt
 *                            also mindestens einen Konverter und erlaubt keine Eingabe.
 *                            Upload- und Thumbnail-Felder kennen details.user_auth:
 *                            1 = Dateien nicht direkt über das Medienverzeichnis
 *                            abrufbar (Camindo\Core\MediaAccess sperrt das Feld-
 *                            Verzeichnis per .htaccess), ausgeliefert nur über
 *                            public/file.php an Frontend-User aus den Profil-
 *                            Bereichen in details.profile_area_ids.
 *
 * Die Suffixe '1' (relation1, pointer1) bedeuten "höchstens ein Ziel" - deshalb setzen
 * die Hooks für sie `max = 1`.
 *
 * DAS VOLLTEXT-STANDARDFELD `fulltext`
 * --------------------
 * Jeder Datensatzbereich hat einen Volltext `fulltext` über alle Text-, Zeichen-,
 * JSON- und Upload-Felder - ohne Zeile in dieser Tabelle; geschrieben wird er von
 * VirtualFieldsTrait::refreshStandardFulltext(). Hier nur: Der Name ist reserviert
 * (FULLTEXT), bleibt in `fieldname` stehen (cleanupFieldname()). Über ein link-Feld
 * gehen auch die Textfelder der Zielbereiche ein (VirtualFieldsTrait::
 * standardFulltextSources()). Quellen eigener Volltextfelder können zusätzlich
 * Felder hinter link- und relation-Feldern sein (fulltextSourceOptions()).
 *
 * Wird ein Quellfeld gelöscht oder umgewandelt, ändern sich die Quellen eines
 * Volltextfeldes oder die Zielbereiche eines link-Feldes, markieren hookOnSaved(),
 * hookOnDeleted() bzw. convert() den Volltext der betroffenen Bereiche als veraltet
 * (FulltextState) - neu aufgebaut wird er über "Jetzt aktualisieren" oder den
 * Zeitplaner. Nur die Umwandlung in 'encrypted' baut sofort neu auf.
 *
 * TYP UND MEHRSPRACHIGKEIT ÄNDERN SICH NUR ÜBER convert()
 * --------------------
 * Der Typ steht seit dem Anlegen fest, und das Kennzeichen `multilingual` lässt sich
 * bei einem bestehenden Feld nicht mehr über die Eingabemaske umschalten
 * (hookOnSave() nimmt es aus den zu schreibenden Feldern) - ein versehentlicher Klick
 * würde sonst die Übersetzungen aller Datensätze verwerfen. Beides ändert nur noch
 * die ausdrückliche Konvertierung: convert() kopiert den Bestand in die data_*-Tabelle
 * des Zieltyps um (conversionTargets() sagt, welche Ziele es gibt, conversionLossy(),
 * ob dabei etwas verloren gehen kann), stellt die `empty`-Kennzeichen bzw. die
 * Dateiablage um und passt die Felddefinition an.
 *
 * @see Camindo\Core\Modules\Area\Area          Inhaltsbereich, dem das Feld gehört
 * @see Camindo\Core\Modules\Area\FieldBackend  Backend-Oberfläche
 * @see Camindo\Core\Cms                          $types, $dataSuffix, $relationTypes …
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Area;

use Camindo\Core\Backend;
use Camindo\Core\Dataset;
use Camindo\Core\FulltextState;
use Camindo\Core\InstanceCache;
use Camindo\Core\MediaAccess;
use Camindo\Core\Modules\Record\Record;

class Field extends Dataset {

    // Meta-Felder auf denen Eingabefelder basieren dürfen
    // (fest im Record-Schema definierte Spalten - ein Eingabefeld kann sie
    // beschriften und darstellen, ohne dass dafür eine data_*-Zeile entsteht)
    public array $metafields = [
        'online_at',
        'offline_at',
        'status_id',
        'template_id',
        'position',
        'parent_id'
    ];

    // Reservierte Namenspräfixe, gefüllt in hookDefinition(): typ => kürzel.
    // Ein Feldname darf nicht mit dem Kürzel eines anderen Typs beginnen.
    private array $fieldPrefixes = [];

    /** Name des Volltext-Standardfelds (siehe Klassenkommentar) - reserviert */
    public const FULLTEXT = 'fulltext';

    /** Typen, deren Felder in einen Volltext eingehen können - 'file': Upload-Felder
     *  mit ihrem Dateinamen (keine Thumbnails, die haben keinen eigenen) */
    public const FULLTEXT_SOURCE_TYPES = ['text', 'chars', 'json', 'file'];

    // Bereiche, deren Volltext ein gerade gelöschtes Feld als Quelle hatte
    // (hookOnDelete() -> hookOnDeleted())
    private array $fulltextOfDeleted = [];

    // Typen, die sich ineinander umwandeln lassen (convert()): innerhalb und
    // zwischen den beiden Gruppen. Dazu relation <-> relation1 und pointer <-> pointer1
    // sowie der Wechsel ein-/mehrsprachig für alle mehrsprachigkeitsfähigen Typen.
    // Nicht dabei: id (Fremdschlüssel auf `record`), password (Hash), json, Datum/
    // Zeit, file, fulltext - für sie gibt es keinen sinnvollen Zieltyp.
    public array $convertTextTypes    = ['chars', 'text', 'encrypted'];
    public array $convertNumericTypes = ['integer', 'currency', 'float', 'decimal', 'bit'];

    // Umwandlungen OHNE möglichen Datenverlust (quelltyp => zieltypen); jede andere
    // Kombination bekommt in der Auswahl den Hinweis auf Datenverlust - z. B.
    // text -> chars (255 Zeichen), Text -> Zahl (nicht-numerische Inhalte), float ->
    // integer (Nachkommastellen), relation -> relation1 (nur eine Verknüpfung bleibt).
    // integer -> float/decimal gilt als verlustfrei: die Wertebereiche des Bestands
    // überschreiten die Genauigkeit von double bzw. decimal(20,10) praktisch nie.
    private array $losslessConversions = [
        'chars'     => ['text', 'encrypted'],
        'text'      => ['encrypted'],
        'encrypted' => ['text'],
        'bit'       => ['integer', 'currency', 'float', 'decimal', 'chars', 'text', 'encrypted'],
        'integer'   => ['currency', 'float', 'decimal', 'chars', 'text', 'encrypted'],
        'currency'  => ['float', 'decimal', 'chars', 'text', 'encrypted'],
        'float'     => ['chars', 'text', 'encrypted'],
        'decimal'   => ['chars', 'text', 'encrypted'],
        'relation1' => ['relation'],
        'pointer1'  => ['pointer'],
    ];

    // Zuordnung vordefinierter Mime-Types zu Werten im Feld 'media_types'
    // Der Redakteur kreuzt im Formular Gruppen an ('image', 'pdf', …); daraus wird die
    // tatsächliche Mime-Type-Liste für den Upload-Filter. 'image/*' ist ein Muster und
    // wird beim Prüfen als Präfix behandelt - ohne SVG (aktiver Inhalt, siehe
    // VirtualFieldsTrait::$mediaTypeGroupsExcl); SVG nur über media_types_incl.
    public array $mimeTypes = [
        'image' => [
            'mime' => ['image/*'],
            'label' => 'area.mime_type_image'
        ],
        'pdf' => [
            'mime' => ['application/pdf'],
            'label' => 'area.mime_type_pdf'
        ],
        'audio' => [
            'mime' => ['audio/*'],
            'label' => 'area.mime_type_audio'
        ],
        'video' => [
            'mime' => ['video/*'],
            'label' => 'area.mime_type_video'
        ],
        'zip' => [
            'mime' => [
                'application/zip',
                'application/x-zip-compressed',
                'application/x-compress',
                'application/x-compressed'
            ],
            'label' => 'area.mime_type_zip'
        ],
        'text' => [
            'mime' => [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/rtf',
                'text/plain',
            ],
            'label' => 'area.mime_type_text'
        ],
        'excel' => [
            'mime' => [
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/csv'
            ],
            'label' => 'area.mime_type_excel'
        ],
        'ppt' => [
            'mime' => [
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation'
            ],
            'label' => 'area.mime_type_powerpoint'
        ],
    ];

    /**
     * Tabellenstruktur `field`
     *
     * Die Einträge mit Punkt im Schlüssel ('details.media_types',
     * …) sind keine eigenen Spalten, sondern Teilfelder der JSON-Spalte `details`.
     * Dataset kennt diese Punktschreibweise und liest/schreibt sie in das JSON.
     *
     * Am Ende werden die reservierten Namenspräfixe zusammengestellt: die Kürzel der
     * data_*-Tabellen aus Cms::$dataSuffix plus vier Typen, die keine eigene
     * data_*-Tabelle haben und deshalb dort nicht auftauchen.
     */
    protected function hookDefinition(): void
    {
        $this->tableName = 'field';
        $this->moduleName = 'Area';
        $this->defaultSortOrder = 'position ASC';
        $this->schema = [
            'field_id' => [
                'type'  => 'integer',
                'label' => 'field_id',
            ],
            'area_id' => [
                'type'  => 'integer', // kein foreign key
                'label' => 'area_id',
                'min'   => 1,
            ],
            'type' => [
                'type'  => 'chars',
                'label' => '#area.field_field_type',
                'max'   => 12,
                'flags' => ['not_empty'],
            ],
            'name' => [
                'type'  => 'chars',
                'label' => '#area.field_field_name',
                'tooltip' => '#area.tooltip_field_name',
                'max'   => 32,
                'flags' => ['not_empty', 'trim', 'name', 'unique_in_area'],
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#area.field_field_label',
                'tooltip' => '#area.tooltip_field_label',
                'max'   => 64,
                'extension' => 'Snippets',   // Core-Extension: Snippet auswählen (#prefix.key)
                'flags' => ['trim', 'not_empty'],
            ],
            'display_type' => [
                'type'   => 'chars',
                'label'  => '#area.field_field_display_type',
                'display_type' => 'select',
                'flags'  => ['not_empty'],
            ],
            'note' => [
                'type'  => 'chars',
                'label' => '#area.field_field_note',
                'tooltip' => '#area.tooltip_field_note',
                 'max'  => 64,
                 'extension' => 'Snippets',
            ],
            'tooltip' => [
                'type'  => 'chars',
                'label' => '#area.field_field_tooltip',
                'tooltip' => '#area.tooltip_field_tooltip',
                 'max'  => 255,
                 'extension' => 'Snippets',
            ],
            'height' => [
                'type'  => 'integer',
                'suffix' => '#unit_rows',
                'default' => '(auto)',
                'min' => 0,
                'max' => 100,
                'label' => '#area.field_field_height',
            ],
            'prefix' => [
                'type'  => 'chars',
                'label' => '#area.field_field_prefix',
                'tooltip' => '#area.tooltip_field_prefix',
                'max'   => 32,
            ],
            'suffix' => [
                'type'  => 'chars',
                'label' => '#area.field_field_suffix',
                'tooltip' => '#area.tooltip_field_suffix',
                'max'   => 32,
            ],
            'pattern' => [
                'type'  => 'chars',
                'label' => '#area.field_field_pattern',
                'tooltip'  => '#area.note_field_pattern',
                'prefix' => '^',
                'suffix' => '$',
                'max'   => 255,
            ],
            'min' => [
                'type'  => 'chars',
                'label' => '#area.field_field_min',
                'max'   => 255,
            ],
            'min_op' => [
                'type'  => 'chars',
                'label' => '#area.field_field_min_op',
            ],
            'max' => [
                'type'  => 'chars',
                'label' => '#area.field_field_max',
                'max'   => 255,
            ],
            'max_op' => [
                'type' => 'chars',
                'label' => '#area.field_field_max_op',
            ],
            'position' => [
                'type'  => 'integer',
                'display_type' => 'select',
                'label' => '#area.field_field_position',
            ],
            'rel_field_id' => [
                'type'  => 'id',
                'label' => '#area.field_field_rel_field_id'
            ],
            'rel_area_id' => [
                'type'  => 'id',
                'display_type' => 'select',
                'label' => '#area.field_field_rel_area_id'
            ],
            // table: `rel_field_area`
            'rel_area_ids' => [
                'type'  => 'reltable',
                'display_type' => 'multiselect',
                'label' => '#area.field_field_rel_area_ids',
                'height' => '(auto)'
            ],
            'custom_order' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_custom_order',
                'default' => 1
            ],
            'flags' => [
                'type'  => 'json',
                'display_type' => 'checkbox',
                'label' => '#area.field_field_flags',
                'max' => '255'
            ],
            'parent_field_id' => [
                'type'  => 'id',
                'display_type' => 'select',
                'label' => '#area.field_field_parent_field_id',
                'table' => 'field'
            ],
            'default' => [
                'type'   => 'text',
                'display_type' => 'textarea',
                'label'  => '#area.field_field_default',
                'height' => 0 // growing
            ],
            'visibility_input' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_visibility_input',
                'tooltip' => '#area.table_col_input_note',
                'default' => 1
            ],
            'visibility_search' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_visibility_search',
                'tooltip' => '#area.table_col_search_note',
                'default' => 1
            ],
            'visibility_list' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_visibility_list',
                'tooltip' => '#area.table_col_list_note',
                'default' => 1
            ],
            'default_field' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_default_field',
                'tooltip' => '#area.tooltip_field_default_field',
            ],
            'meta' => [
                'type' => 'bit',
            ],
            // Mehrsprachiges Feld: je Sprache der Instanz ein eigener Inhalt, mit der
            // Hauptsprache als Fallback. Nur für die Typen in Cms::$multilingualTypes
            // und nicht für Felder auf Metaspalten (hookOnSave() setzt es sonst zurück).
            'multilingual' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_multilingual',
                'tooltip' => '#area.tooltip_field_multilingual',
            ],
            'details' => [
                'type' => 'json',
                'display_type' => 'none',
                'label' => '#area.field_field_details',
            ],
            // Lookup-Felder (relation/pointer): ausführliche Vorschau, siehe
            // Klassenkommentar
            'details.lookup_detailed' => [
                'type' => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_lookup_detailed',
                'tooltip' => '#area.tooltip_field_lookup_detailed',
            ],
            // Feld-Extension (13 §3.3): Name einer aktiven Extension, die zum
            // Display-Typ passt; ihre Optionen liegen in details.extension_options
            'details.extension' => [
                'type' => 'chars',
                'display_type' => 'select',
                'label' => '#area.field_field_extension',
                'tooltip' => '#area.tooltip_field_extension',
                'max' => 32,
            ],
            // Tabelle `rel_field_converter`
            'converter_ids' => [
                'type' => 'reltable',
                'display_type' => 'sortable',
                'label' => '#area.field_field_converter_ids',
            ],
            'details.media_types' => [
                'type' => 'json',
                'display_type' => 'checkbox',
                'label' => '#area.field_field_media_types',
            ],
            'details.media_types_incl' => [
                'type' => 'text',
                'display_type' => 'textarea',
                'label' => '#area.field_field_media_types_incl',
                'note' => '#converter.note_field_media_types',
                'tooltip' => '#converter.tooltip_field_media_types',
            ],
            'details.media_types_excl' => [
                'type' => 'text',
                'display_type' => 'textarea',
                'label' => '#area.field_field_media_types_excl',
                'note' => '#converter.note_field_media_types',
                'tooltip' => '#converter.tooltip_field_media_types',
            ],
            // Upload/Thumbnail: Dateien nur für angemeldete Frontend-User
            // (siehe Klassenkommentar, MediaAccess::protectField())
            'details.user_auth' => [
                'type' => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_field_user_auth',
                'tooltip' => '#area.tooltip_field_user_auth',
            ],
            // Profil-Bereiche, deren angemeldete User die Dateien sehen dürfen
            // (nur mit user_auth = 1; leer = niemand)
            'details.profile_area_ids' => [
                'type' => 'json',
                'display_type' => 'checkbox',
                'label' => '#area.field_field_profile_area_ids',
                'tooltip' => '#area.tooltip_field_profile_area_ids',
            ],
        ];

        // Reservierter Prefix am Anfang eines Feldnames abhängig vom Typ
        // ('reltable' hat keine data_*-Tabelle und ist kein wählbarer Feldtyp -
        //  sein Platzhalter-Suffix gehört nicht in die Präfix-Liste)
        $this->fieldPrefixes = array_diff_key(
            $this->cms->dataSuffix,
            array_flip($this->cms->noColumnTypes)
        );
        // Sonderfälle:
        $this->fieldPrefixes['pointer'] = 'ptr';
        $this->fieldPrefixes['pointer1'] = 'pn1';
        $this->fieldPrefixes['link'] = 'lnk';
        $this->fieldPrefixes['relation'] = 'rel';
        $this->fieldPrefixes['relation1'] = 'rn1';
    }

    /**
     * Vor dem Neu-Erstellen: neues Feld ans Ende der Feldliste seines Bereichs.
     *
     * Anders als Area::hookOnCreate() (COALESCE(..., -1) + 1, beginnt bei 0) zählt hier
     * COALESCE(..., 0) + 1, das erste Feld bekommt also position = 1. Für die Sortierung
     * ist das gleichgültig, beim Vergleich der beiden Klassen aber verwirrend.
     */
    protected function hookOnCreate(array &$data): void
    {
        // Position des neuen Feldes auf "höchste Position im Breichs + 1" setzen
        $area_id = (int)$data['area_id'];
        $sql = "SELECT COALESCE(MAX(`position`), 0) + 1 FROM `field` WHERE `area_id` =?";
        $this->db->query($sql, 'i', [$area_id]);
        $data['position'] = $this->db->fetchNum()[0] ?? 0;
        // Sichtbarkeit bei bestimmten Feldern nicht vorselektieren
        $name = $data['name'] ?? '';
        $type = $data['type'] ?? '';
        $displayType = $data['display_type'] ?? '';
        if ($displayType == 'thumbnail') {
            // Vorschaubild
            $data['visibility_search'] = 0;
            $data['visibility_input'] = 0;
        }
        if ($displayType == 'textarea') {
            // Mehrzeiliges Eingabefeld
            $data['visibility_list'] = 0;
        }
        if ($displayType == 'embed') {
            // Eingebettete Inhalte
            $data['visibility_list'] = 0;
        }
        if (in_array($name,['position','parent_id','indent'], true)) {
            // Sortierung, Vaterdatensatz, Einrücken
            // wird in der Liste ohnehin visualisiert
            $data['visibility_list'] = 0;
        }
        // Datumsfeld: Kalender vorauswählen (nicht bei Meta-Feldern)
        if (!isset($this->cms->schema[$name])) {
            // init() belegt details.extension mit '' vor - das ist hier keine Wahl
            if (is_array($data['details'] ?? null) && (string)($data['details']['extension'] ?? '') === '') {
                unset($data['details']['extension']);
            }
            $this->presetExtension($data);
        }
    }

    /**
     * Extension eines NEUEN Feldes vorbelegen: ein Datumsfeld (Cms::$dateTypes) mit
     * Display-Typ 'text' bekommt die Extension Calendar ("nach Feldtyp",
     * Minutenschritt 1) - sofern sie aktiv ist und keine Extension (auch keine
     * leere = bewusst "keine") übergeben wurde. Aufgerufen von hookOnCreate()
     * (Eingabemaske) und FieldApi::create() (KI-Assistent).
     */
    public function presetExtension(array &$data): void
    {
        $type = (string)($data['type'] ?? '');
        $displayType = (string)($data['display_type'] ?? 'text');
        if ($displayType === '') $displayType = 'text';
        if (!in_array($type, $this->cms->dateTypes, true) || $displayType !== 'text') return;
        $details = $data['details'] ?? [];
        if (!is_array($details)) {
            // als JSON-Text übergeben: nicht anfassen
            if ((string)$details !== '') return;
            $details = [];
        }
        if (array_key_exists('extension', $details)) return;
        $backend = $this->container->get(Backend::class);
        if (!isset($backend->extensionsFor($type, $displayType)['Calendar'])) return;
        $details['extension'] = 'Calendar';
        $details['extension_options'] = ['mode' => 'auto', 'step' => '1'];
        $data['details'] = $details;
    }

    /**
     * Ein Datei-Feld wechselt zwischen ein- und mehrsprachig: Bestand umstellen.
     *
     * Die physischen Dateien heißen je nach Modus {record_id}.{ext} bzw.
     * {record_id}_{language_id}.{ext} (Cms::mediaFileName()), und `data_fil` führt in
     * file_language_id, welche Datei eine Zeile meint. Beides muss zum Kennzeichen
     * des Feldes passen, sonst fänden Frontend und Backend die Dateien nicht mehr.
     *
     *     -> mehrsprachig   die Datei wird zur Datei der Hauptsprache der Instanz des
     *                       Datensatzes; die Zeilen der übrigen Sprachen verweisen
     *                       darauf (Fallback)
     *     -> einsprachig    die Datei der Hauptsprache bleibt (umbenannt), Dateien
     *                       anderer Sprachen werden gelöscht; alle Zeilen bekommen die
     *                       Metadaten der Hauptsprache und file_language_id NULL
     *
     * Läuft in der Transaktion von Dataset::save(); die Dateioperationen selbst sind
     * nicht transaktional.
     *
     * @param int  $fieldId
     * @param bool $toMultilingual
     */
    private function migrateFileLanguage(int $fieldId, bool $toMultilingual): void
    {
        $mediaDir = $this->config->get('paths.media_dir') . '/' . $this->cms->mediaFolder($fieldId);
        $instanceCache = $this->container->get(\Camindo\Core\InstanceCache::class);

        $this->db->query(
            "SELECT DISTINCT `d`.`record_id`, `r`.`instance_id` FROM `data_fil` `d`"
            . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
            . " WHERE `d`.`field_id` = ?",
            'i', [$fieldId]
        );
        $records = $this->db->fetchAll();
        foreach ($records as $rec) {
            $recordId  = (int)$rec['record_id'];
            $primaryId = $instanceCache->primaryLanguageId((int)$rec['instance_id']);

            $this->db->query("SELECT * FROM `data_fil` WHERE `record_id` = ? AND `field_id` = ?", 'ii', [$recordId, $fieldId]);
            $rows = [];
            while ($row = $this->db->fetch()) $rows[(int)$row['language_id']] = $row;
            if (empty($rows)) continue;
            $primaryRow = $rows[$primaryId] ?? reset($rows);
            $extension  = (string)$primaryRow['extension'];

            if ($toMultilingual) {
                // {id}.{ext} -> {id}_{primary}.{ext}
                if ($extension !== '' && is_file("$mediaDir/" . $this->cms->mediaFileName($recordId, null, $extension))) {
                    @rename(
                        "$mediaDir/" . $this->cms->mediaFileName($recordId, null, $extension),
                        "$mediaDir/" . $this->cms->mediaFileName($recordId, $primaryId, $extension)
                    );
                }
                $this->db->query(
                    "UPDATE `data_fil` SET `file_language_id` = ? WHERE `record_id` = ? AND `field_id` = ?",
                    'iii', [$primaryId, $recordId, $fieldId]
                );
            }
            else {
                // Dateien anderer Sprachen weg, die der Hauptsprache umbenennen
                foreach ($rows as $lang => $row) {
                    $fileLang = $this->cms->fileLanguageId($row);
                    if ($fileLang === null || $fileLang !== (int)$lang) continue; // Fallback-Zeile
                    $ext = (string)$row['extension'];
                    if ($ext === '') continue;
                    $path = "$mediaDir/" . $this->cms->mediaFileName($recordId, $fileLang, $ext);
                    if ($fileLang === $primaryId) {
                        if (is_file($path)) @rename($path, "$mediaDir/" . $this->cms->mediaFileName($recordId, null, $ext));
                    }
                    else {
                        @unlink($path);
                    }
                }
                $this->db->query(
                    "UPDATE `data_fil` SET `file_language_id` = NULL, `extension` = ?, `mime_type` = ?, `filename` = ?,"
                    . " `size` = ?, `width` = ?, `height` = ?, `data` = ?, `token` = ''"
                    . " WHERE `record_id` = ? AND `field_id` = ?",
                    'sssiiisii', [
                        $extension, (string)$primaryRow['mime_type'], (string)$primaryRow['filename'],
                        (int)$primaryRow['size'], (int)$primaryRow['width'], (int)$primaryRow['height'],
                        (string)($primaryRow['data'] ?? '{}'), $recordId, $fieldId
                    ]
                );
            }
        }
    }

    /**
     * Nach dem Laden: die Angaben nachreichen, die nicht in `field` stehen.
     *
     * Zwei Feldtypen haben mehr Ziele, als in eine Spalte passen; ihre Daten liegen in
     * Kantentabellen und werden hier als Pseudo-Felder in $data gelegt, damit das
     * Formular sie wie normale Felder behandeln kann:
     *
     *     pointer   -> data['rel_area_ids']   aus `rel_field_area`
     *     fulltext  -> data['source_fields']  aus `rel_fulltext_text`
     *     thumbnail -> data['converter_ids']  aus `rel_field_converter` (nach position)
     *
     * Database::fetchAll() liefert bei einspaltigen Abfragen eine flache Werteliste,
     * die Zuweisungen ergeben also direkt ein Id-Array.
     */
    protected function hookOnLoaded(array &$data): void
    {
        $fieldId = (int) ($data['field_id'] ?? 0);
        $type = $data['type'] ?? '';

        // pointer, link
        // --------------------
        if (in_array($type, $this->cms->multiAreaTypes, true)) {
            // Die Ids der Ziel-Bereich kommen in der Tabelle `rel_field_area`
            $sql = "SELECT `area_id` FROM `rel_field_area` WHERE `field_id` =?";
            $this->db->query($sql, 'i', [$fieldId]);
            $data['rel_area_ids'] = $this->db->fetchAll();
        }

        // fulltext Felder
        // --------------------
        if ($type == 'fulltext') {
            // Wenn es sich um ein Fulltextfeld handelt:
            // dann sind die Quell-Feld-Ids in Tabelle `rel_fulltext_text` gespeichert
            // Die Ids werden im Pseudofeld data['source_fields'] bereitgestellt
            // (über ein link-Feld: Schlüssel "{rel_field_id}:{text_field_id}", sourceKey())
            $this->db->query("SELECT `rel_field_id`, `text_field_id` FROM `rel_fulltext_text` WHERE `fulltext_field_id`=?", 'i', [$fieldId]);
            $data['source_fields'] = array_map(
                fn(array $row) => self::sourceKey((int)$row['text_field_id'], (int)($row['rel_field_id'] ?? 0)),
                $this->db->fetchAll()
            );
        }

        // thumbnail
        // --------------------
        if ($type === 'file' && (string)($data['display_type'] ?? '') === 'thumbnail') {
            $data['converter_ids'] = $this->getConverterIds($fieldId);
        }
    }

    /**
     * Konverter eines Thumbnail-Feldes in ihrer Reihenfolge (`rel_field_converter`).
     *
     * @return array  converter_ids, sortiert nach position
     */
    public function getConverterIds(int $fieldId): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT `converter_id` FROM `rel_field_converter` WHERE `field_id` = ? ORDER BY `position` ASC, `converter_id` ASC", 'i', [$fieldId]);
        $ids = array_map('intval', $this->db->fetchAll()); // flaches Array
        $this->db->popResult();
        return $ids;
    }

    /**
     * Thumbnail-Felder, die einen Konverter verwenden (`rel_field_converter`) -
     * für den Reiter "Verwendet von" der Konverter-Info.
     *
     * @return array  field_ids, nach Bereich und Position
     */
    public function getFieldIdsByConverter(int $converterId): array
    {
        $this->db->pushResult();
        $this->db->query(
            "SELECT f.`field_id` FROM `rel_field_converter` r JOIN `field` f ON f.`field_id` = r.`field_id`
             WHERE r.`converter_id` = ? ORDER BY f.`area_id` ASC, f.`position` ASC",
            'i', [$converterId]
        );
        $ids = array_map('intval', $this->db->fetchAll()); // flaches Array
        $this->db->popResult();
        return $ids;
    }

    /**
     * Vor dem Speichern: prüfen und Abhängigkeiten geraderücken.
     *
     * Läuft innerhalb der Transaktion von Dataset::save(). Jeder Fehler wird als
     * Feldfehler registriert und dann als Exception geworfen - dadurch rollt die
     * Transaktion zurück und das Backend zeigt das Formular erneut an.
     *
     * Der Reihe nach:
     *
     *  1. rel_field_id aus $fields entfernen - die Gegenfeld-Id trägt hookOnSaved()
     *     selbst per UPDATE ein. Bliebe sie in der Liste, würde das Formular (das die
     *     Spalte nicht kennt) sie beim erneuten Speichern mit 0 überschreiben.
     *  2. Typ muss in Cms::$types stehen.
     *  3. Bei Namensänderung: Konflikt mit der globalen Registrierung `fieldname`
     *     (Name schon für einen anderen Typ vergeben) und reservierte Präfixe prüfen.
     *  4. display_type 'thumbnail' erzeugt seinen Inhalt selbst -> keine Eingabe.
     *  5. default_field ist ein Zustand von genau einem Feld je Bereich. Wird es
     *     gesetzt, verlieren alle anderen Felder des Bereichs das Kennzeichen.
     *     `area.default_field_id` selbst zieht hookOnSaved() nach - dort steht auch
     *     bei einem neuen Feld die `field_id` fest (die Gegenrichtung dazu steht in
     *     Area::hookOnSaved()).
     *  6. Typabhängige Zwangswerte: max = 1 bei den '1'-Typen, custom_order nur
     *     sinnvoll bei display_type 'lookup' mit n:m-Beziehung, min/max als Anzahl
     *     bei Array-Typen, Konverterpflicht bei Thumbnails.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        $schema = $this->schema;
        $fieldId  = (int)($data['field_id'] ?? 0);
        $areaId   = (int)($data['area_id'] ?? 0);
        $name     = (string)($data['name'] ?? '');
        $type     = (string)($data['type'] ?? '');
        $displayType = (string)($data['display_type'] ?? 'text');
        $previousName = (string)($previousData['name'] ?? '');

        // dest_relation_field
        // --------------------
        // verhindere, dass beim erneuten Speichern Inhalt verloren geht
        // (Gegenfeld-Id wird in hookOnSaved manuell eingetragen)
        $fields = array_diff($fields, ['rel_field_id']);

        // 'reltable' ist kein wählbarer Feldtyp: er ist den fest im Code definierten
        // Schemata vorbehalten (Inhalt in einer eigenen Relation-Tabelle) und hat
        // keine data_*-Tabelle, in der ein virtuelles Feld landen könnte.
        $selectableTypes = array_diff($this->cms->types, $this->cms->noColumnTypes);
        if (in_array('type', $fields, true) && !in_array($type, $selectableTypes, true)) {
            // Feld 'type' wird gespeichert, ist aber nicht bekannt
            $type = preg_replace('/[^a-z0-9_]/','', $type);
            $this->createFieldError('area.error_field_unknown_type', 'type', $schema['type']['label'] ?? '', $type);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }

        // fulltext: nur Quellen des eigenen Bereichs bzw. über eines seiner link-Felder
        // (fulltextSourceOptions()) - ein Feld eines fremden Bereichs ohne Weg dorthin
        // schriebe den Volltext an fremde Datensätze
        if ($type === 'fulltext' && array_key_exists('source_fields', $data)) {
            $allowed = $this->fulltextSourceOptions($areaId ?: (int)($previousData['area_id'] ?? 0));
            $keys = [];
            foreach ((array)$data['source_fields'] as $key) {
                [$textFieldId, $relFieldId] = self::parseSourceKey($key);
                $key = (string)self::sourceKey($textFieldId, $relFieldId);
                if (!isset($allowed[$key])) {
                    $this->createFieldError('area.error_fulltext_source', 'source_fields', $this->cms->snippet('area.field_field_source_fields'), $key);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                $keys[$key] = self::sourceKey($textFieldId, $relFieldId);
            }
            $data['source_fields'] = array_values($keys);
        }

        // name - Änderung / Neueintrag
        // --------------------
        $nameChanged = in_array('name', $fields, true) && $name !== $previousName;
        // Volltext-Standardfeld: Name reserviert (siehe Klassenkommentar)
        if ($nameChanged && $name === self::FULLTEXT) {
            $this->createFieldError('area.error_field_name_reserved', 'name', $schema['name']['label'] ?? '', $name);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        if ($nameChanged) {
            // Prüfe, ob Name bereits für Feld eines anderen Typs verwendet wird
            $sql ="SELECT `type` FROM `fieldname` WHERE `name`=? AND `type` != ? ";
            $this->db->query($sql, 'ss', [$name, $type]);
            if ($this->db->num_rows > 0) {
                // Ja, Name für anderen Feldtyp reserviert -> Fehlermeldung
                $wrongType = $this->db->fetchNum()[0];
                $this->createFieldError('area.error_field_name_wrong_type', 'name', $schema['name']['label'] ?? '', $wrongType);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            // Reservierte Prefixes prüfen
            foreach ($this->fieldPrefixes as $long => $short) {
                $prefix = $short . '_';
                if (str_starts_with($name, $prefix) && $type != $long) {
                    // Prefix ist für anderen Typ reserviert
                    $this->createFieldError('error_field_reserved_prefix', 'name', $schema['name']['label'] ?? '', $prefix);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }
        }

        // visibility
        // --------------------
        // Thumbnail erlaubt keine Eingabe
        if ($data['display_type'] == 'thumbnail') {
            $data['visibility_input'] = 0;
        }

        // multilingual
        // --------------------
        // Nur für Typen mit sprachabhängiger data_*-Tabelle und nie für Felder auf
        // Metaspalten von `record` - die Spalten selbst kennen keine Sprache.
        // Bei einem BESTEHENDEN Feld wird das Kennzeichen hier nicht mehr geschrieben:
        // der Wechsel geht nur über convert() (siehe Klassenkommentar); die Maske
        // zeigt es nur an. Thumbnails bleiben ausgenommen - sie erben es unten.
        if ($fieldId > 0) {
            $fields = array_values(array_diff($fields, ['multilingual']));
        }
        if (in_array('multilingual', $fields, true)) {
            $data['multilingual'] = (int)!empty($data['multilingual']);
            if (isset($this->cms->schema[$name])
                || !($type === 'file' || in_array($type, $this->cms->multilingualTypes, true))
            ) {
                $data['multilingual'] = 0;
            }
        }
        // Thumbnails erben das Kennzeichen von ihrem Upload-Feld: sie werden aus
        // dessen Datei erzeugt, je Sprache eine (siehe VirtualFieldsTrait::
        // writeThumbnails()). hookOnSaved() zieht die Kinder nach, wenn sich das
        // Upload-Feld ändert.
        if ($type === 'file' && $displayType === 'thumbnail') {
            $parentFieldId = (int)($data['parent_field_id'] ?? 0);
            $data['multilingual'] = 0;
            if ($parentFieldId > 0) {
                $this->db->query("SELECT `multilingual` FROM `field` WHERE `field_id` = ?", 'i', [$parentFieldId]);
                $data['multilingual'] = (int)($this->db->fetchNum()[0] ?? 0);
            }
            if (!in_array('multilingual', $fields, true)) $fields[] = 'multilingual';
        }

        // default_field - Änderung / Neueintrag
        // --------------------
        $isDefaultField = (int)($data['default_field'] ?? 0);
        $wasDefaultField = (int)($previousData['default_field'] ?? 0);
        if ($isDefaultField !== $wasDefaultField        // Default-Feld Status ändert sich
            && in_array('default_field', $fields, true) // Feld wird geschrieben
        ) {
            if (!empty($isDefaultField)) {
                // neuer Status: true
                // Das Default-Feld ist immer sichtbar in der Datensatz-Liste
                $data['visibility_list'] = 1;

                // Kein anderes Feld des Bereichs kann das default Field bleiben
                $this->db->query("UPDATE `field` SET `default_field` = 0 WHERE `area_id` = ?", 'i', [$areaId]);
            }

            // `area.default_field_id` wird erst in hookOnSaved() geschrieben:
            // Bei einem neuen Feld steht die `field_id` hier noch nicht fest (der
            // INSERT folgt erst nach diesem Hook), der Fremdschlüssel
            // area.default_field_id -> field.field_id würde also scheitern.
            $data['__set_default_field'] = !empty($isDefaultField);
        }

        // height: (auto) wird zu 0
        $data['height'] = (int)$data['height'];

        // relation
        // --------------------
        if (str_starts_with($type, 'relation')) {
            // Ziel-Bereich muss ausgewählt sein
            if (empty($data['rel_area_id'])) {
                $this->createFieldError('error_value_mandatory', 'rel_area_id', $schema['rel_area_id']['label']);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            // Typ Relation erlaubt nicht den eigenen Content-Bereich als Zielbereich
            if ((int)$data['rel_area_id'] === $areaId) {
                $this->createFieldError('area.error_field_rel_area_id', 'rel_area_id', $schema['rel_area_id']['label'] ?? '', $type);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            // Prüfe, ob Zielbereich existiert
            $sql = "SELECT 1 FROM `area` WHERE `area_id`=? AND `deleted`=0";
            $this->db->query($sql, 'i', [(int)$data['rel_area_id']]);
            if ($this->db->num_rows == 0) {
                $this->createFieldError('area.error_rel_area_not_found', 'rel_area_id', $schema['rel_area_id']['label'] ?? '');
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            if ($type == 'relation1') {
                $data['max'] = 1;
            }
            // custom_order | nur bei Typ 'lookup' und 1:n Relation
            if ($displayType !== 'lookup' || str_ends_with($type, '1')) {
                $data['custom_order'] = 0;
            }
        }

        // pointer
        // --------------------
        if (str_starts_with($type, 'pointer')) {
            // In typify wurden die ausgewählten Ids der Zielbereiche bereits in einen String umgewandelt,
            // (char-Feld laut Schema)
            // Dieser String wird in hookOnSaved verarbeitet
            $relAreaIds = $this->system->sanitizeIds((array)($data['rel_area_ids'] ?? []), removeZero:true);
            $data['__rel_area_ids'] = $relAreaIds;
            // im char-Feld selbst landet nichts
            $data['rel_area_ids'] = '';

            // Validierung (nur bei Typ pointer)
            if (empty($relAreaIds)) {
                $label = $schema['rel_area_ids']['label'] ?? 'rel_area_ids';
                $this->createFieldError('error_value_mandatory', 'rel_area_ids', $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            if ($type == 'pointer1') {
                $data['max'] = 1;
            }

            // custom_order | nur bei Typ 'lookup' und 1:n Relation
            if ($displayType !== 'lookup' || str_ends_with($type, '1')) {
                $data['custom_order'] = 0;
            }
        }

        // link - fest verknüpfte Inhalte (1:n), siehe Klassenkommentar
        // --------------------
        if ($type === 'link') {
            // Zielbereiche wie beim pointer (rel_field_area, hookOnSaved()) - aber nur
            // abhängige Datensatzbereiche (area.dependent = 1)
            $relAreaIds = $this->system->sanitizeIds((array)($data['rel_area_ids'] ?? []), removeZero:true);
            $data['rel_area_ids'] = '';
            $label = $schema['rel_area_ids']['label'] ?? 'rel_area_ids';
            if (empty($relAreaIds)) {
                $this->createFieldError('error_value_mandatory', 'rel_area_ids', $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $placeholders = implode(',', array_fill(0, count($relAreaIds), '?'));
            $this->db->query(
                "SELECT `area_id` FROM `area` WHERE `deleted` = 0 AND `dependent` = 1"
                . " AND `type` IN ('record','profile','ugc') AND `area_id` IN ($placeholders)",
                str_repeat('i', count($relAreaIds)), $relAreaIds
            );
            $dependentIds = array_map('intval', $this->db->fetchColumn());
            if (count($dependentIds) !== count($relAreaIds)) {
                $this->createFieldError('area.error_field_rel_area_dependent', 'rel_area_ids', $label);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            $data['__rel_area_ids'] = $relAreaIds;

            // Darstellung: Suchfenster oder eingebettete Eingabemasken
            if (!in_array($displayType, ['lookup', 'embed'], true)) {
                $data['display_type'] = $displayType = 'lookup';
            }
            // Die Reihenfolge der Zieldatensätze ist immer Handarbeit (data_rel.position),
            // eine Höchstzahl gibt es nicht - das Feld hält beliebig viele Inhalte
            $data['custom_order'] = 1;
            $data['max'] = '';
        }

        // Array-Typen: min/max zählen Elemente, nicht Zeichen
        // --------------------
        // Deshalb hängen min und das Flag 'not_empty' zusammen und werden hier in
        // beide Richtungen synchron gehalten: not_empty erzwingt min >= 1, und ein
        // min > 0 erzwingt umgekehrt das Flag. Sonst könnte die Validierung ein
        // leeres Feld durchlassen, das laut min mindestens ein Element braucht.
        if (in_array($type, $this->cms->arrayTypes, true)) {
            // "Anzahl" wird in min / max behandelt
            // -> not_empty kann in min integriert werden
            if ($data['min'] !== '') $data['min'] = (int)$data['min'];
            if ($data['max'] !== '') $data['max'] = (int)$data['max'];

            // min kann nicht 0 bleiben wenn Flag 'not_empty' gesetzt ist
            $flags = (array)($data['flags'] ?? []);
            if (in_array('not_empty', $flags)) {
               $data['min'] = max($data['min'], 1);
            }

            // umgekehrt: wenn min > 0 muss 'not_empty' gesetzt sein
            if ($data['min'] > 0) {
                $data['flags'] = array_diff($flags, ['not_empty']);
                $data['flags'][] = 'not_empty';
            }
        }

        // Extension (13 §3.3): muss aktiv sein und zu Feld- und Display-Typ passen;
        // ohne Extension entfallen auch ihre Optionen
        if (isset($data['details']) && is_array($data['details'])) {
            $extension = trim((string)($data['details']['extension'] ?? ''));
            if ($extension !== '') {
                $backend = $this->container->get(Backend::class);
                if (!isset($backend->extensionsFor($type, $displayType)[$extension])) {
                    $this->createFieldError('area.error_extension_invalid', 'details.extension',
                        $schema['details.extension']['label'] ?? '', $extension);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                $data['details']['extension'] = $extension;
                $options = (array)($data['details']['extension_options'] ?? []);
                $data['details']['extension_options'] = array_map(
                    fn($v) => is_array($v) ? array_map('strval', $v) : (string)$v, $options);
            }
            else {
                unset($data['details']['extension'], $data['details']['extension_options']);
            }
        }

        if (in_array($type, $this->cms->relationTypes, true)) {
            // Zielbereiche nur aus der eigenen Instanz und deren lesbaren Instanzen
            // (targetInstanceIds()) - geprüft werden nur NEU hinzukommende Ziele:
            // ein bestehendes Ziel außerhalb bleibt gültig, bis es abgewählt wird
            $newTargetIds = in_array($type, $this->cms->multiAreaTypes, true)
                ? (array)($data['__rel_area_ids'] ?? [])
                : [(int)$data['rel_area_id']];
            // (bisherige Ziele: hookOnLoaded() legt rel_area_ids aus `rel_field_area` ab)
            $previousTargetIds = in_array($type, $this->cms->multiAreaTypes, true)
                ? array_map('intval', (array)($previousData['rel_area_ids'] ?? []))
                : [(int)($previousData['rel_area_id'] ?? 0)];
            $addedIds = array_values(array_diff(array_map('intval', $newTargetIds), $previousTargetIds));
            if (!empty($addedIds)) {
                $instanceIds = $this->targetInstanceIds($areaId);
                $placeholders = implode(',', array_fill(0, count($addedIds), '?'));
                $this->db->query(
                    "SELECT `area_id` FROM `area` WHERE `area_id` IN ($placeholders) AND `instance_id` IN ("
                    . implode(',', $instanceIds) . ")",
                    str_repeat('i', count($addedIds)), $addedIds
                );
                if (count($this->db->fetchColumn()) !== count($addedIds)) {
                    $name = in_array($type, $this->cms->multiAreaTypes, true) ? 'rel_area_ids' : 'rel_area_id';
                    $this->createFieldError('area.error_field_rel_area_instance', $name, $schema[$name]['label'] ?? $name);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }

            // Wenn aufgrund des gewählten DisplayTypes nur eine Verknüpfung erstellt werden kann,
            // dann stelle 'max' auf 1 - das hilft in TemporaryTrait::_copyRelationsBack()
            if (in_array($displayType, ['radio', 'select'])) {
                $data['max'] = '1';
            }
            // ausführliche Lookup-Vorschau: 0/1 (ein ausgeschalteter Switch steht
            // nicht im POST), nur bei display_type 'lookup' von Bedeutung
            if (isset($data['details']) && is_array($data['details'])) {
                $data['details']['lookup_detailed'] = (!empty($data['details']['lookup_detailed']) && $displayType === 'lookup') ? 1 : 0;
            }

            // Wenn min oder max 0 ist, dann '' speichern = Limit nicht beachten
            if (empty($data['min'])) $data['min'] = '';
            if (empty($data['max'])) $data['max'] = '';
        }

        // thumbnail: converter-selection
        if ($type === 'file') {
            if ($displayType === 'thumbnail') {
                // Reihenfolge der Eingabe (sortable) bleibt, Dubletten und Fremdes fallen weg
                $data['converter_ids'] = $this->system->sanitizeIds((array)($data['converter_ids'] ?? []), true);
                if (empty($data['converter_ids'])) {
                    $label = $this->schema['converter_ids']['label'] ?? 'converter_ids';
                    $this->createFieldError('error_value_mandatory', 'converter_ids', $label);
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                // (ältere Daten: die Ids lagen in details - dort nichts mehr führen)
                if (isset($data['details']) && is_array($data['details'])) unset($data['details']['converter_ids']);
            }
            // user_auth: Profil-Bereiche nur mit gesetztem Schalter, und nur
            // bestehende Bereiche vom Typ 'profile'
            if (isset($data['details']) && is_array($data['details'])) {
                $data['details']['user_auth'] = empty($data['details']['user_auth']) ? 0 : 1;
                $ids = $this->system->sanitizeIds((array)($data['details']['profile_area_ids'] ?? []), true);
                if (!empty($ids) && $data['details']['user_auth'] === 1) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $this->db->query(
                        "SELECT `area_id` FROM `area` WHERE `type` = 'profile' AND `deleted` = 0 AND `area_id` IN ($placeholders)",
                        str_repeat('i', count($ids)),
                        $ids
                    );
                    $ids = array_map('intval', $this->db->fetchColumn());
                }
                elseif ($data['details']['user_auth'] !== 1) {
                    $ids = [];
                }
                $data['details']['profile_area_ids'] = array_values($ids);
            }
        }

        // name == slug
        if ($name === 'slug') {
            // Der Slug ist ein spezielles Feld, das als URI der Datensätze verwendet wird
            // Daher ist 'unique' (über die Content-Bereiche hinweg) zwingend - beim Slug
            // bedeutet es: eindeutiger URL-Pfad [Slug-Prefix des Bereichs/]slug, siehe
            // Record::slugPathOk()
            $flags = (array)($data['flags'] ?? []);
            $data['flags'] = array_diff($flags, ['unique', 'unique_in_area']);
            $data['flags'][] = 'unique';
        }
    }

    /**
     * Nach dem Speichern: die Nebentabellen nachziehen.
     *
     * Erst hier steht bei einem neuen Feld die `field_id` fest - alles, was sie braucht,
     * gehört deshalb in diesen Hook und nicht in hookOnSave(). Ebenfalls innerhalb der
     * Transaktion.
     *
     *     area               `default_field_id` auf dieses Feld setzen bzw. leeren
     *     fieldname          Namenseintrag anlegen bzw. den alten aufräumen
     *     field (Gegenseite) bei 'relation' das Gegenfeld im Zielbereich erzeugen
     *     rel_field_area     bei 'pointer' die Zielbereiche neu schreiben
     *     rel_fulltext_text  bei 'fulltext' die Quellfelder neu schreiben
     *     rel_field_converter  bei Thumbnails die Konverter neu schreiben (mit position)
     *
     * Das Muster "erst DELETE, dann INSERT der ganzen Liste" ist Absicht: es spart den
     * Vergleich alt/neu und ist innerhalb der Transaktion gefahrlos.
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {   
        $fieldId = (int)($data['field_id'] ?? 0);
        $areaId  = (int)($data['area_id'] ?? $previousData['area_id'] ?? 0);
        if (empty($fieldId)) throw new \Exception('hookOnSaved(): field_id is empty');
        if (empty($areaId)) throw new \Exception('hookOnSaved(): area_id is empty');
        
        $isNew   = (bool)($data['new'] ?? false);
        $name = (string)($data['name'] ?? '');
        $previousName = $previousData['name'] ?? '';
        $type    = (string)($data['type'] ?? 'text');
        $previousType = $previousData['type'] ?? 'text';

        // default_field
        // --------------------
        // Nachtrag zu hookOnSave(): erst hier ist die `field_id` bekannt
        if (array_key_exists('__set_default_field', $data)) {
            if ($data['__set_default_field']) {
                // Default-Feld in Inhaltsbereich eintragen
                $this->db->query("UPDATE `area` SET `default_field_id` = ? WHERE `area_id` = ?", 'ii', [$fieldId, $areaId]);
            }
            else {
                // Default-Feld aus Inhaltsbereich entfernen
                $this->db->query("UPDATE `area` SET `default_field_id` = NULL WHERE `area_id` = ?", 'i', [$areaId]);
            }
        }

        // multilingual
        // --------------------
        // (Der Wechsel ein-/mehrsprachig eines bestehenden Feldes läuft nicht mehr
        //  über save(), sondern über convert() -> applyMultilingualChange();
        //  hookOnSave() nimmt das Kennzeichen aus $fields.)

        // name
        // --------------------
        $nameChanged = !$isNew && in_array('name', $fields, true) && $name !== $previousName;
        if ($isNew || $nameChanged) {
            // Eintrag in fieldname (wenn nicht bereits vorhanden)
            // Bei falschem Typ: Rückgabe ist verwendeter Typ
            if ($wrongType = $this->createFieldname($name, $type)) {
                // Name-Typ-Konflikt
                $this->createFieldError('area.error_field_name_wrong_type', 'name', $this->schema['name']['label'] ?? '', (string)$wrongType);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            // Id nachschlagen, nicht insert_id: gab es den Namen schon (ein anderes
            // Feld desselben Typs trägt ihn), hat createFieldname() nichts eingefügt -
            // insert_id wäre 0 und die Daten verlören beim Umbenennen ihren Namen
            $this->db->query("SELECT `fieldname_id` FROM `fieldname` WHERE `name` = ?", 's', [$name]);
            $fieldnameId = (int)($this->db->fetchNum()[0] ?? 0);
            if ($fieldnameId < 1) {
                throw new \Exception("Internal error: fieldname '$name' not found.");
            }

            if ($nameChanged) {
                // Daten in der Data-Tabelle haben noch alte fieldname_id gespeichert,
                // jetzt anpassen
                $previousDataSuffix = $this->cms->dataSuffix[$previousData['type']] ?? null;
                if (!$previousDataSuffix) {
                    throw new \Exception('Internal error: previousData[type] is undefined.');
                }
                $sql = "UPDATE `data_{$previousDataSuffix}` SET `fieldname_id` = ? WHERE `field_id` = ?";
                $this->db->query($sql, 'ii', [$fieldnameId, $fieldId]);

                // Prüfen ob Eintrag in `fieldname` gelöscht werden kann
                $this->cleanupFieldname($previousName);
            }
        }

        // type Änderung
        // --------------------
        // Nur über convert() - die Eingabemaske hält den Typ fest (formStatic)
        $typeChanged = !$isNew && in_array('type', $fields, true) && $type !== $previousType;
        if ($typeChanged) {
            throw new \Exception("Internal Error: type changed");
        }
        /*
            ENTWURF
            // Typenänderung wird nur begrenzt in der Eingabemaske erlaubt.
            // Gewährleistet ist in diesem Fall, dass Daten 1:1 von einer data_Tabelle in eine andere
            // kopiert werden können.
            // Restliche Typenänderungen erfolgen über spezielle Konvertierungs-Tools. (GEPLANT)
            if ($typeChanged) {
                // fieldname_id wurde bereits in der alten Tabelle angepasst
                $dataSuffix = $this->cms->dataSuffix[$data['type']] ?? null;
                $previousDataSuffix = $this->cms->dataSuffix[$previousData['type']] ?? null;
                if (!empty($dataSuffix) && !empty($previousDataSuffix)
                    && $dataSuffix != $previousDataSuffix
                ) {
                    $this->db->query("INSERT INTO `data_{$dataSuffix}` SELECT * FROM `data_{$previousDataSuffix}` WHERE `field_id` = ?", 'i', [$fieldId]);
                    $this->db->query("DELETE FROM `data_{$previousDataSuffix}` WHERE `field_id` = ?", 'i', [$fieldId]);
                }
            }
        */

        // type == relation
        // --------------------
        if (str_starts_with($type, 'relation')) {
            $relAreaId = (int)$data['rel_area_id'];
            if (empty($relAreaId)) {
                throw new \Exception("hookOnSave(): rel_area_id is empty!");
            }
            $flagCreateNewField = false;
            if (!$isNew) {
                $previousRelAreaId = (int)$previousData['rel_area_id'];

                // Wurde Zielbereich geändert?
                if ($relAreaId != $previousRelAreaId) {
                    // Lösche altes Zielfeld
                    // (löscht auch alle gespeicherten Verknüpfungen darin).
                    // VORHER den eigenen Verweis darauf lösen: fk_field_rel_field
                    // steht auf ON DELETE CASCADE - mit rel_field_id auf das alte
                    // Gegenfeld risse dessen DELETE dieses Feld selbst mit
                    $relFieldId = (int)$previousData['rel_field_id'];
                    $this->db->query("UPDATE `field` SET `rel_field_id` = NULL WHERE `field_id` = ?", 'i', [$fieldId]);
                    if ($relFieldId > 0) {
                        $sql = "DELETE FROM `field` WHERE `field_id` =?";
                        $this->db->query($sql, 'i', [$relFieldId]);
                    }
                    
                    $flagCreateNewField = true;
                }
            }

            // Neues Ziel-Relationfeld (Gegenfeld) im Zielbereich anlegen
            // --------------------
            // Das Gegenfeld macht die Verknüpfung von der anderen Seite sichtbar. Es
            // wird bewusst mit direktem INSERT angelegt und nicht über save(): das
            // liefe rekursiv in diesen Hook und würde ein Gegenfeld zum Gegenfeld
            // erzeugen. Es bleibt unsichtbar (visibility_* bleiben auf 0, weil sie
            // im INSERT gar nicht vorkommen und die Spaltendefaults greifen).
            if ($isNew || $flagCreateNewField) {
                // Bereichs-Name des Quellfeldes wird Name des Zielfeldes
                // Bereichs-Label des Quellfeldes wird Label des Zielfeldes
                $sql = "SELECT `name`, `label` FROM `area` WHERE `area_id` =? AND `deleted`=0";
                $this->db->query($sql, 'i', [$areaId]);
                if ($this->db->num_rows < 1) {
                    throw new \Exception("hookOnSave(): area with area_id=$areaId not found!");
                }
                $areaData = $this->db->fetch();
                $areaLabel = $areaData['label'];
                $areaName = $areaData['name'];
                // Freien Name mit Prefix "rel_{$areaName}" im Zielbereich ermitteln
                $relFieldName = $this->generateUniqueFieldName('rel_' . $areaName, $relAreaId);

                $sql = "SELECT COALESCE(MAX(`position`), 0) + 1 FROM `field` WHERE `area_id` =?";
                $this->db->query($sql, 'i', [$relAreaId]);
                $position = $this->db->fetchNum()[0];

                // Fieldname-Eintrag erstellen
                // (Rückgabewert spielt keine Rolle, weil Prefix 'rel_' für Typ 'relation' reserviert ist)
                $this->createFieldname($name, 'relation');

                // Erstelle Daten für das Zielfeld
                $relFieldData = [
                    'area_id' => $relAreaId,    // Zielbereich
                    'rel_area_id' => $areaId,   // Quellbereich
                    'rel_field_id' => $fieldId, // Quellfeld
                    'position' => $position,
                    'name' => $relFieldName,
                    'label' => $areaLabel,
                    'type' => 'relation',
                    'display_type' => 'lookup',
                    // visibility: 0,0,0
                ];

                // Query erstellen und absenden
                $columns = implode("`,`", array_keys($relFieldData));
                $placeholders = implode(',', array_fill(0, count($relFieldData), '?'));
                $types = 'iiiissss';
                $values = array_values($relFieldData);
                $sql = "INSERT INTO `field` (`$columns`) VALUES ($placeholders)";
                if (!$this->db->query($sql, $types, $values)) {
                    throw new \Exception("hookOnSave(): destination field could not be created!");
                }

                // Id des Zielfeldes in Quellfeld eintragen / aktualisieren
                $relFieldId = (int)$this->db->insert_id;
                $sql = "UPDATE `field` SET `rel_field_id`=? WHERE `field_id`=? ";
                $this->db->query($sql, 'ii', [$relFieldId, $fieldId]
                );

                // Tabelle fieldname aktualisieren
                $this->createFieldname($relFieldName, 'relation');
            }
        }

        // pointer, link
        // --------------------
        if (in_array($type, $this->cms->multiAreaTypes, true)) {
            $data['rel_area_ids'] = (array)$data['__rel_area_ids']; // see hookOnSave
            // link: die Textfelder der Zielbereiche gehen in den Standard-Volltext ein
            if ($type === 'link' && !$isNew) {
                $old = array_map('intval', (array)($previousData['rel_area_ids'] ?? []));
                $new = array_map('intval', $data['rel_area_ids']);
                sort($old);
                sort($new);
                if ($old !== $new) $this->fulltextState()->markStale([$areaId]);
            }
            // Tabelle `rel_field_area` aktualisieren
            $sql = "DELETE FROM `rel_field_area` WHERE `field_id`=?";
            $this->db->query($sql, 'i', [$fieldId]);
            foreach ($data['rel_area_ids'] as $destAreaId) {
                // foreign key gewährleistet, dass area_id in Tabelle area existiert
                $sql = "INSERT INTO `rel_field_area` SET `field_id`=?, `area_id`=?";
                $this->db->query($sql, 'ii', [$fieldId, $destAreaId]);
            }
        }

        // 'Maximum' verringert
        // relation, pointer, file
        // --------------------
        $max = (int)($data['max'] ?? 0);
        $previousMax = (int)($previousData['max'] ?? 0);
        if (
            !$isNew && $max < $previousMax 
            && in_array($type, $this->cms->relationTypes, true)
        ) {
            // Maximale Auswahl wird verringert
            // -> prüfe Verknüpfungen über dieses Feld
            // entferne Verknüpfungen über das neue Maximum hinaus (sortiert nach eingesteller Sortierung)
            // ...
        }

        // fulltext
        // --------------------
        if ($type == 'fulltext') {
            // Bei einem Fulltext-Feld werden die Ids der Quell-Felder in Tabelle `rel_fulltext_text` gespeichert
            // Die Ids wurden im Pseudofeld data['source_fields'] gesammelt
            // Schlüssel siehe sourceKey(): über ein link-Feld mit `rel_field_id`
            $keys = array_map('strval', (array)($data['source_fields'] ?? []));
            $this->db->query("SELECT `rel_field_id`, `text_field_id` FROM `rel_fulltext_text` WHERE `fulltext_field_id`=?", 'i', [$fieldId]);
            $previousKeys = array_map(fn(array $row) => (string)self::sourceKey((int)$row['text_field_id'], (int)($row['rel_field_id'] ?? 0)), $this->db->fetchAll());
            $this->db->query("DELETE FROM `rel_fulltext_text` WHERE `fulltext_field_id`=?", 'i', [$fieldId]);
            foreach ($keys as $key) {
                [$textFieldId, $relFieldId] = self::parseSourceKey($key);
                $this->db->query(
                    "INSERT INTO `rel_fulltext_text` (`fulltext_field_id`, `rel_field_id`, `text_field_id`) VALUES (?, ?, ?)",
                    'iii', [$fieldId, $relFieldId > 0 ? $relFieldId : null, $textFieldId]
                );
            }
            // Neues Volltextfeld oder andere Quellen: der Bestand passt nicht mehr -
            // nicht sofort neu aufbauen, nur markieren (FulltextState)
            sort($keys);
            sort($previousKeys);
            if ($isNew || $keys !== $previousKeys) {
                $this->fulltextState()->markStale([$areaId]);
            }
        }

        // thumbnail: Konverter
        // --------------------
        // Nur wenn das Feld übergeben wurde - ein save() mit Feldliste ohne
        // 'converter_ids' lässt die Zuordnung stehen. `position` ist die Reihenfolge
        // der Eingabe (display_type 'sortable'), hookOnSave() hat die Ids bereinigt.
        $displayType = (string)($data['display_type'] ?? $previousData['display_type'] ?? '');
        if ($type === 'file' && $displayType === 'thumbnail' && array_key_exists('converter_ids', $data)) {
            $ids = $this->system->sanitizeIds((array)$data['converter_ids'], true);
            $this->db->query("DELETE FROM `rel_field_converter` WHERE `field_id` = ?", 'i', [$fieldId]);
            if (!empty($ids)) {
                $placeholders = implode(', ', array_fill(0, count($ids), '(?, ?, ?)'));
                $sql = "INSERT INTO `rel_field_converter` (`field_id`, `converter_id`, `position`) VALUES $placeholders";
                $bindTypes = '';
                $values = [];
                foreach (array_values($ids) as $position => $converterId) {
                    $bindTypes .= 'iii';
                    $values[] = $fieldId;
                    $values[] = (int)$converterId;
                    $values[] = $position + 1;
                }
                $this->db->query($sql, $bindTypes, $values);
            }
        }

        // Field 'position'
        // --------------------
        // Default-Sortierung des Bereichs automatisch auf 'position ASC' umstellen
        if ($name === 'position') {
            // Default-Feld in Inhaltsbereich eintragen
            $this->db->query("UPDATE `area` SET `sort_field_id` = ?, `sort_order` = 'ASC' WHERE `area_id` = ?", 'ii', [$fieldId, $areaId]);
            // Ohne `position`-Feld pflegt der Bereich keine Reihenfolge - die
            // vorhandenen Datensätze tragen dann alle denselben Wert. Beim Anlegen
            // des Feldes werden sie deshalb einmal durchnummeriert, sonst wäre die
            // erste angezeigte Reihenfolge zufällig.
            if ($isNew || $nameChanged) {
                $this->recordDataset()->normalizePositions($areaId);
            }
        }

        // Field 'parent_id' - der Bereich bekommt (oder verliert) seinen Baum
        // --------------------
        // Ein Bereich führt genau dann einen Baum, wenn er ein Eingabefeld
        // `parent_id` hat (siehe TreeManagerTrait). Wird es angelegt, sind alle
        // vorhandenen Datensätze zunächst Wurzelknoten und bekommen erstmalig
        // lft/rgt/depth/parent_ids; wird es weg-umbenannt, ist der Bereich wieder
        // flach und die Baumfelder werden zurückgesetzt (wie beim Löschen des
        // Feldes, siehe hookOnDeleted()).
        $wasParentField = ($previousName === 'parent_id');
        $isParentField  = ($name === 'parent_id');
        if ($isParentField && ($isNew || $nameChanged)) {
            $this->recordDataset()->initTreeArea($areaId);
        }
        elseif ($wasParentField && $nameChanged) {
            $this->recordDataset()->resetTreeFields($areaId);
        }

        // Datei-Felder: Feld-Sperre im Medienverzeichnis (details.user_auth)
        // --------------------
        // Die .htaccess im Verzeichnis media/{instance_id}/{field_id} wird bei
        // jedem Speichern mit dem Schalter abgeglichen - auch bei einem neuen Feld,
        // damit das Verzeichnis samt Sperre schon vor dem ersten Upload steht.
        // ($data['details'] liegt hier bereits JSON-kodiert vor - Dataset::save()
        //  hat die Spalte für das Schreiben umgewandelt)
        if ($type === 'file' && in_array('details', $fields, true)) {
            $details = is_array($data['details'] ?? null)
                ? $data['details']
                : (json_decode((string)($data['details'] ?? ''), true) ?: []);
            $this->container->get(MediaAccess::class)->protectField(
                $fieldId,
                !empty($details['user_auth'])
            );
        }
    }

    /**
     * Vor dem Löschen.
     *
     * Fast alles erledigt die Datenbank per Fremdschlüssel: die `data_*`-Zeilen hängen
     * mit ON DELETE CASCADE an `field`, ebenso das Gegenfeld einer Relation und die
     * Einträge in `rel_field_area` / `rel_fulltext_text` / `rel_field_converter`.
     * `area.default_field_id` wird per Constraint geleert.
     *
     * Übrig bleibt der Eintrag in der globalen Namensregistrierung `fieldname`.
     */
    protected function hookOnDelete(array $data): void
    {
        $fieldId = (int)($data['field_id'] ?? 0);
        if (empty($fieldId)) {
            // (Dieser Fall kommt nie vor, weil Dataset::load() erfolgreich war)
            throw new \Exception("hookOnDelete(): data[field_id] is empty!");
        }

        // Bereiche, deren Volltext das Feld als Quelle hat - nach dem Löschen als
        // veraltet markieren (die Zeilen in `rel_fulltext_text` verschwinden per
        // Fremdschlüssel, auch die über ein gelöschtes link-Feld)
        $type = (string)($data['type'] ?? '');
        $this->fulltextOfDeleted = [];
        if (in_array($type, self::FULLTEXT_SOURCE_TYPES, true) && (string)($data['display_type'] ?? '') !== 'thumbnail') {
            $this->fulltextOfDeleted = $this->fulltextState()->areasUsingField($fieldId);
        }
        elseif (in_array($type, ['link', 'relation', 'relation1'], true)) {
            $this->fulltextOfDeleted = [(int)($data['area_id'] ?? 0)];
        }

        // (`default_field_id` in Tabelle `area` wird per Contraint entfernt)
        // (der Eintrag in `fieldname` wird in hookOnDeleted() geprüft - solange die
        //  `field`-Zeile noch steht, hielte sie ihren eigenen Namen fest)
    }

    /**
     * Nach dem Löschen.
     *
     * Erst hier ist die `field`-Zeile wirklich weg - beides unten braucht das:
     *
     *     fieldname   der Namenseintrag darf nur fallen, wenn ihn kein Feld mehr
     *                 benutzt. Solange die eigene Zeile noch stünde, wäre die Prüfung
     *                 immer negativ (das war der TODO in hookOnDelete()).
     *     parent_id   war es DAS Treefeld des Bereichs, führt der Bereich ab jetzt
     *                 keinen Baum mehr: alle seine Datensätze werden flach gemacht
     *                 (parent_id = NULL, lft/rgt/depth = 0, parent_ids = []). Sonst
     *                 blieben Verweise und Nested-Set-Grenzen stehen, die niemand
     *                 mehr pflegt, über die aber weiterhin gefiltert würde.
     *                 Die Anzeigereihenfolge des Baums bleibt als `position` erhalten.
     */
    protected function hookOnDeleted(array $data): void
    {
        $name   = (string)($data['name']    ?? '');
        $areaId = (int)   ($data['area_id'] ?? 0);

        // Prüfe ob alter Name in `fieldname` entfernt werden kann
        $this->cleanupFieldname($name);

        // Treefeld entfernt -> Bereich ist wieder flach
        if ($name === 'parent_id' && $areaId > 0) {
            $this->recordDataset()->resetTreeFields($areaId);
        }

        // Volltext enthält noch die Inhalte des gelöschten Feldes: als veraltet
        // markieren statt sofort neu aufzubauen (FulltextState)
        $this->fulltextState()->markStale($this->fulltextOfDeleted);
        $this->fulltextOfDeleted = [];
    }

    // Zielbereiche der Verweisfelder
    // ====================

    /**
     * Instanzen, aus denen ein Verweisfeld (relation, pointer, link) eines Bereichs
     * seine Zielbereiche wählen darf: die Instanz des Bereichs und deren lesbare
     * Instanzen (`readable_instances`, siehe InstanceCache) - nur deren Datensätze
     * sieht das Frontend der Instanz.
     *
     * @param int $areaId  Quellbereich (der Bereich des Feldes)
     * @return int[]       instance_ids, die eigene zuerst; [0] bei unbekanntem Bereich
     */
    public function targetInstanceIds(int $areaId): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT `instance_id` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
        $instanceId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        if ($instanceId < 1) return [0];
        $ids = [$instanceId];
        $instanceCache = $this->container->get(InstanceCache::class);
        foreach ((array)($instanceCache->instance($instanceId)['readable_instances'] ?? []) as $readableId) {
            $readableId = (int)$readableId;
            if ($readableId > 0 && !in_array($readableId, $ids, true)) $ids[] = $readableId;
        }
        return $ids;
    }

    /**
     * Verknüpfungen (`data_rel`) eines pointer-/link-Feldes, deren Zieldatensatz in
     * keinem der Bereiche $areaIds liegt - gezählt je Zielbereich, bevor die
     * Zielbereiche des Feldes geändert werden (FieldBackend::_editSave()).
     *
     * Arbeitskopien zählen mit: die Kanten eines ausgecheckten Quelldatensatzes
     * verschwinden beim Bereinigen ebenso (removeRelationsOutside()).
     *
     * @param int   $fieldId
     * @param int[] $areaIds  die künftigen Zielbereiche
     * @return array          area_id => Anzahl Kanten; leer, wenn nichts betroffen ist
     */
    public function countRelationsOutside(int $fieldId, array $areaIds): array
    {
        if ($fieldId < 1) return [];
        $areaIds = $this->system->sanitizeIds($areaIds, true);
        $notIn = empty($areaIds) ? '' : " AND `r`.`area_id` NOT IN (" . implode(',', $areaIds) . ")";
        $this->db->pushResult();
        $this->db->query(
            "SELECT `r`.`area_id`, COUNT(*) AS `cnt` FROM `data_rel` `d`"
            . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`dest_id`"
            . " WHERE `d`.`field_id` = ?" . $notIn
            . " GROUP BY `r`.`area_id`",
            'i', [$fieldId]
        );
        $counts = [];
        while ($row = $this->db->fetch()) $counts[(int)$row['area_id']] = (int)$row['cnt'];
        $this->db->popResult();
        return $counts;
    }

    /**
     * Verknüpfungen (`data_rel`) eines pointer-/link-Feldes entfernen, deren
     * Zieldatensatz in keinem Zielbereich des Feldes (`rel_field_area`) mehr liegt -
     * nach dem Speichern geänderter Zielbereiche, wenn der Admin das bestätigt hat.
     *
     * Bei 'link' gehören die Zieldatensätze dem Quelldatensatz (LinkTrait): ohne
     * Kante wären sie verwaist und in ihrem abhängigen Bereich unerreichbar - sie
     * werden deshalb wie beim Check-in soft gelöscht (produktive, nicht schon
     * gelöschte Datensätze; Arbeitskopien bleiben ihrem Vorgang überlassen).
     *
     * @return int  Anzahl entfernter Kanten
     */
    public function removeRelationsOutside(int $fieldId): int
    {
        if ($fieldId < 1) return 0;
        $this->db->pushResult();
        $this->db->query("SELECT `type` FROM `field` WHERE `field_id` = ?", 'i', [$fieldId]);
        $type = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->query(
            "SELECT `d`.`src_id`, `d`.`dest_id`, `r`.`tmp`, `r`.`deleted` FROM `data_rel` `d`"
            . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`dest_id`"
            . " WHERE `d`.`field_id` = ? AND NOT EXISTS ("
            . "   SELECT 1 FROM `rel_field_area` `a` WHERE `a`.`field_id` = `d`.`field_id` AND `a`.`area_id` = `r`.`area_id`"
            . " )",
            'i', [$fieldId]
        );
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        if (empty($rows)) return 0;

        $orphanIds = [];
        foreach ($rows as $row) {
            $this->db->query(
                "DELETE FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?",
                'iii', [$fieldId, (int)$row['src_id'], (int)$row['dest_id']]
            );
            if (in_array($type, $this->cms->dependentTypes, true) && empty($row['tmp']) && empty($row['deleted'])) {
                $orphanIds[(int)$row['dest_id']] = true;
            }
        }
        // link: verwaiste Inhalte soft löschen (Record::delete() zieht deren eigene
        // fest verknüpfte Inhalte mit, LinkTrait::cascadeDependents())
        $record = $this->recordDataset();
        foreach (array_keys($orphanIds) as $orphanId) {
            if ($record->linkOwner($orphanId) !== null) continue; // (gehört noch jemandem)
            $record->delete($orphanId, false);
        }
        return count($rows);
    }

    /**
     * Record-Instanz bereitstellen.
     *
     * Wie in Area::recordDataset(): bewusst nicht über den Konstruktor injiziert -
     * Dataset-Kindklassen erben dessen Signatur und deklarieren keinen eigenen.
     * Der Container liefert dieselbe geteilte Instanz wie überall sonst.
     */
    private function recordDataset(): Record
    {
        return $this->container->get(Record::class);
    }

    private function fulltextState(): FulltextState
    {
        return $this->container->get(FulltextState::class);
    }

    // Quellfelder eines Volltextfeldes
    // ====================

    /**
     * Schlüssel einer Volltext-Quelle (Pseudo-Feld source_fields): die field_id eines
     * Feldes des eigenen Bereichs, über ein link-Feld "{rel_field_id}:{text_field_id}".
     */
    public static function sourceKey(int $textFieldId, int $relFieldId = 0): int|string
    {
        return $relFieldId > 0 ? $relFieldId . ':' . $textFieldId : $textFieldId;
    }

    /** @return int[]  [text_field_id, rel_field_id (0 = eigener Bereich)] */
    public static function parseSourceKey(mixed $key): array
    {
        $parts = explode(':', (string)$key, 2);
        return count($parts) === 2 ? [(int)$parts[1], (int)$parts[0]] : [(int)$parts[0], 0];
    }

    /**
     * Wählbare Quellen eines Volltextfeldes: Text-, Zeichen-, JSON- und Upload-Felder
     * (keine Thumbnails) des Bereichs und - über seine Felder vom Typ link, relation
     * und relation1 - der Zielbereiche. Felder hinter einem pointer nicht: ein
     * Verweis ist eine lose Referenz auf fremde Inhalte (oft mehrere Bereiche), deren
     * Text gehört nicht zum Datensatz.
     *
     * Änderungen an einem Zieldatensatz erreichen den Volltext nur bei link (der
     * Inhalt gehört dem Datensatz, VirtualFieldsTrait::refreshAffectedFulltext());
     * bei relation erst beim nächsten Speichern des Datensatzes selbst oder per
     * Neuaufbau (Zeitplaner-Aufgabe fulltext).
     *
     * @return array  sourceKey() => ['label' => HTML, 'text_field_id', 'rel_field_id',
     *                'group' => Überschrift HTML ('' = eigener Bereich)]
     */
    public function fulltextSourceOptions(int $areaId): array
    {
        $options = [];
        $sourceSql = "`t`.`type` IN ('text', 'chars', 'json', 'file') AND NOT (`t`.`type` = 'file' AND `t`.`display_type` = 'thumbnail')";
        $label = fn(array $row) => '<strong>' . htmlspecialchars((string)$row['name']) . '</strong> '
            . htmlspecialchars($this->cms->textOrSnippet((string)$row['label']));
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `t`.`field_id`, `t`.`name`, `t`.`label` FROM `field` `t` WHERE `t`.`area_id` = ? AND {$sourceSql}"
                . " ORDER BY `t`.`position`",
                'i', [$areaId]
            );
            foreach ($this->db->fetchAll() as $row) {
                $options[(string)$row['field_id']] = [
                    'label' => $label($row), 'group' => '',
                    'text_field_id' => (int)$row['field_id'], 'rel_field_id' => 0,
                ];
            }
            // Zielbereiche: link über rel_field_area, relation/relation1 über rel_area_id
            $this->db->query(
                "SELECT `l`.`field_id` AS `rel_id`, `l`.`name` AS `rel_name`, `l`.`label` AS `rel_label`,"
                . " `a`.`label` AS `area_label`, `t`.`field_id`, `t`.`name`, `t`.`label`"
                . " FROM `field` `l`"
                . " LEFT JOIN `rel_field_area` `ra` ON `ra`.`field_id` = `l`.`field_id` AND `l`.`type` = 'link'"
                . " INNER JOIN `area` `a` ON `a`.`area_id` = IF(`l`.`type` = 'link', `ra`.`area_id`, `l`.`rel_area_id`)"
                . " INNER JOIN `field` `t` ON `t`.`area_id` = `a`.`area_id`"
                . " WHERE `l`.`area_id` = ? AND `l`.`type` IN ('link', 'relation', 'relation1') AND {$sourceSql}"
                . " ORDER BY `l`.`position`, `l`.`field_id`, `a`.`area_id`, `t`.`position`",
                'i', [$areaId]
            );
            foreach ($this->db->fetchAll() as $row) {
                $group = htmlspecialchars($this->cms->textOrSnippet((string)$row['area_label']))
                    . ' <span class="c9-grey-50 fw-normal">– ' . htmlspecialchars($this->cms->textOrSnippet((string)$row['rel_label']))
                    . ' (' . htmlspecialchars((string)$row['rel_name']) . ')</span>';
                $options[(string)self::sourceKey((int)$row['field_id'], (int)$row['rel_id'])] = [
                    'label' => $label($row), 'group' => $group,
                    'text_field_id' => (int)$row['field_id'], 'rel_field_id' => (int)$row['rel_id'],
                ];
            }
        }
        finally {
            $this->db->popResult();
        }
        return $options;
    }

    // Typ-Konvertierung
    // ====================

    /**
     * Mögliche Ziele einer Konvertierung für ein Feld (Auswahl in FieldBackend::convert()).
     *
     * Je Eintrag: 'type', 'multilingual' (0|1) und 'lossy' (true = die Umwandlung kann
     * Inhalte verlieren, siehe conversionLossy()). Ein Typwechsel behält die
     * Mehrsprachigkeit bei, der Sprachwechsel den Typ - beides zugleich gibt es nicht,
     * die Auswahl bleibt so überschaubar.
     *
     *     Text- und Zahltypen        untereinander (beide Gruppen)
     *     relation <-> relation1     pointer <-> pointer1
     *     ein- <-> mehrsprachig      alle mehrsprachigkeitsfähigen Typen
     *                                (Cms::isMultilingualField()), Thumbnails erben
     *                                das Kennzeichen vom Upload-Feld und bleiben außen vor
     *
     * Felder auf Metaspalten von `record` haben einen festen Typ und keine Sprache.
     *
     * @param array $data  Felddaten aus load()
     * @return array       [['type' => ..., 'multilingual' => ..., 'lossy' => ...], ...]
     */
    public function conversionTargets(array $data): array
    {
        $type = (string)($data['type'] ?? '');
        $name = (string)($data['name'] ?? '');
        $multilingual = (int)!empty($data['multilingual']);
        if ($type === '' || isset($this->cms->schema[$name])) return [];

        $targets = [];

        // Typwechsel
        $typeTargets = [];
        $valueTypes = array_merge($this->convertTextTypes, $this->convertNumericTypes);
        if (in_array($type, $valueTypes, true)) {
            $typeTargets = array_values(array_diff($valueTypes, [$type]));
        }
        elseif (in_array($type, $this->cms->relationTypes, true) && !in_array($type, $this->cms->dependentTypes, true)) {
            // (nicht 'link': dafür gibt es keinen Zieltyp)
            $typeTargets = [str_ends_with($type, '1') ? substr($type, 0, -1) : $type . '1'];
        }
        foreach ($typeTargets as $toType) {
            $targets[] = [
                'type' => $toType,
                'multilingual' => $multilingual,
                'lossy' => $this->conversionLossy($type, $toType, $multilingual, $multilingual),
            ];
        }

        // ein- <-> mehrsprachig
        if ($this->cms->isMultilingualField(['type' => $type, 'multilingual' => 1])
            && !($type === 'file' && ($data['display_type'] ?? '') === 'thumbnail')
        ) {
            $targets[] = [
                'type' => $type,
                'multilingual' => $multilingual ? 0 : 1,
                'lossy' => $this->conversionLossy($type, $type, $multilingual, $multilingual ? 0 : 1),
            ];
        }
        return $targets;
    }

    /**
     * Die Vorbedingungen von convert() - ohne etwas zu ändern (auch für Probeläufe):
     *
     *  1. das Ziel steht unter conversionTargets()
     *  2. bei einem Typwechsel: der Name ist systemweit an einen Typ gebunden - kein
     *     anderes Feld darf ihn tragen - und darf nicht das Präfix eines anderen Typs
     *     haben ($fieldPrefixes)
     *
     * @param array $data  Felddaten aus load()
     * @return bool        false: Meldung in Cms::errors
     */
    public function conversionAllowed(array $data, string $toType, int $toMultilingual): bool
    {
        $toMultilingual = (int)(bool)$toMultilingual;
        $allowed = false;
        foreach ($this->conversionTargets($data) as $target) {
            if ($target['type'] === $toType && (int)$target['multilingual'] === $toMultilingual) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            $this->cms->error($this->cms->snippet('area.error_field_convert_not_allowed'));
            return false;
        }
        if ($toType === (string)$data['type']) return true;
        $name = (string)$data['name'];
        $this->db->pushResult();
        try {
            $this->db->query("SELECT COUNT(*) FROM `field` WHERE `name` = ? AND `field_id` != ?", 'si', [$name, (int)$data['field_id']]);
            $inUse = (int)($this->db->fetchNum()[0] ?? 0) > 0;
        }
        finally {
            $this->db->popResult();
        }
        if ($inUse) {
            $this->cms->error($this->cms->snippet('area.error_field_convert_name_in_use'));
            return false;
        }
        foreach ($this->fieldPrefixes as $long => $short) {
            $prefix = $short . '_';
            if (str_starts_with($name, $prefix) && $toType !== $long) {
                $this->createFieldError('area.error_field_reserved_prefix', 'name', $this->schema['name']['label'] ?? '', $prefix);
                return false;
            }
        }
        return true;
    }

    /**
     * Offene Arbeitskopien eines Bereichs (Hinweis vor convert(): die Umwandlung
     * beachtet keine Bearbeitungssperre).
     *
     * @return array  Zeilen mit record_id und displayname des Bearbeiters
     */
    public function workingCopies(int $areaId): array
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `r`.`record_id`, `a`.`displayname` FROM `record` `r`"
                . " LEFT JOIN `admin` `a` ON `a`.`admin_id` = `r`.`changed_by`"
                . " WHERE `r`.`area_id` = ? AND `r`.`tmp` = 1",
                'i', [$areaId]
            );
            return $this->db->fetchAll();
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Kann eine Umwandlung Inhalte verlieren?
     *
     * Von mehrsprachig zu einsprachig immer (nur die Hauptsprache bleibt), bei einem
     * Typwechsel alles, was nicht in $losslessConversions steht.
     */
    public function conversionLossy(string $fromType, string $toType, int $fromMultilingual, int $toMultilingual): bool
    {
        if ($fromMultilingual && !$toMultilingual) return true;
        if ($fromType === $toType) return false;
        return !in_array($toType, $this->losslessConversions[$fromType] ?? [], true);
    }

    /**
     * Feld in einen anderen Typ und/oder von ein- auf mehrsprachig umwandeln (und
     * umgekehrt) - der einzige Weg dafür, siehe Klassenkommentar.
     *
     * Der Reihe nach, in einer Transaktion:
     *
     *  1. Ziel gegen conversionTargets() prüfen.
     *  2. Beim Typwechsel: der Name ist systemweit an einen Typ gebunden
     *     (`fieldname`). Benutzt ein anderes Feld denselben Namen, geht es nicht - erst
     *     umbenennen. Reservierte Präfixe gelten wie beim Anlegen.
     *  3. Bestand umkopieren: Zeile für Zeile aus der alten in die neue data_*-Tabelle
     *     (convertRows(), Werte über convertValue()), bei relation -> relation1 und
     *     pointer -> pointer1 stattdessen je Datensatz alle Verknüpfungen bis auf die
     *     erste entfernen. Der Typ des Namens in `fieldname` wird nachgezogen; ein
     *     Feld, das kein Textfeld mehr ist, fällt aus den Volltext-Quellen.
     *  4. Beim Sprachwechsel: applyMultilingualChange() - `empty`-Kennzeichen bzw.
     *     Dateiablage umstellen.
     *  5. Felddefinition anpassen (conversionUpdate()): display_type, min/max,
     *     Flags, Vorgabe - was zum neuen Typ nicht passt, wird zurückgesetzt.
     *  6. Der Inhalts-Cache der Datensätze des Bereichs wird verworfen, Cms::fetch()
     *     baut ihn beim nächsten Zugriff neu. Der Arbeitsstand offener Arbeitskopien
     *     bleibt und wird umgewandelt (Record::retypeWorkingCopies(), bei Datei-Feldern
     *     Record::migrateWorkingCopyFiles()); ist das Feld
     *     Default-Feld, zieht `data_dft` nach.
     *
     * Fehler stehen in Cms::errors.
     *
     * @param int    $fieldId
     * @param string $toType
     * @param int    $toMultilingual  0|1
     * @return bool  true, wenn umgewandelt
     */
    public function convert(int $fieldId, string $toType, int $toMultilingual): bool
    {
        if (!$data = $this->load($fieldId)) return false;
        $fromType = (string)$data['type'];
        $fromMultilingual = (int)!empty($data['multilingual']);
        $toMultilingual = (int)(bool)$toMultilingual;
        $name = (string)$data['name'];
        $areaId = (int)$data['area_id'];

        // 1. und 2.: Ziel zulässig, Name passt zum Zieltyp (conversionAllowed())
        if (!$this->conversionAllowed($data, $toType, $toMultilingual)) return false;
        $typeChanged = ($toType !== $fromType);
        $multilingualChanged = ($toMultilingual !== $fromMultilingual);

        // Volltext betroffen? Bereiche jetzt ermitteln - convert() entfernt das Feld
        // ggf. aus `rel_fulltext_text`
        $isSource = fn(string $t) => in_array($t, self::FULLTEXT_SOURCE_TYPES, true)
            && (string)($data['display_type'] ?? '') !== 'thumbnail';
        $fulltextAffected = ($typeChanged && ($isSource($fromType) || $isSource($toType)))
            || ($multilingualChanged && $isSource($fromType));
        $fulltextAreas = $fulltextAffected ? $this->fulltextState()->areasUsingField($fieldId) : [];
        $fulltextIds = [];

        $this->db->pushResult();
        try {
            $this->db->beginTransaction();

            if ($typeChanged) {
                // 3. Bestand
                $fromSuffix = (string)($this->cms->dataSuffix[$fromType] ?? '');
                $toSuffix   = (string)($this->cms->dataSuffix[$toType] ?? '');
                if ($fromSuffix === '' || $toSuffix === '') {
                    throw new \Exception("convert(): unknown data table for $fromType / $toType");
                }
                if ($fromSuffix !== $toSuffix) {
                    $this->convertRows($fieldId, $fromType, $toType);
                }
                elseif (in_array($toType, $this->cms->singleRelationTypes, true)) {
                    $this->reduceRelations($fieldId, (int)($data['rel_field_id'] ?? 0), str_starts_with($toType, 'relation'));
                }
                $this->db->query("UPDATE `fieldname` SET `type` = ? WHERE `name` = ?", 'ss', [$toType, $name]);
                $this->cms->clearFieldCaches();

                // Volltext-Quellen: nur Textfelder
                if (!in_array($toType, self::FULLTEXT_SOURCE_TYPES, true)) {
                    $this->db->query("SELECT `fulltext_field_id` FROM `rel_fulltext_text` WHERE `text_field_id` = ?", 'i', [$fieldId]);
                    $fulltextIds = array_map('intval', $this->db->fetchAll());
                    $this->db->query("DELETE FROM `rel_fulltext_text` WHERE `text_field_id` = ?", 'i', [$fieldId]);
                }
            }

            // 4. Sprachwechsel
            if ($multilingualChanged) {
                $this->applyMultilingualChange($fieldId, $toType, (bool)$toMultilingual);
            }

            // 5. Felddefinition
            $update = $this->conversionUpdate($data, $toType);
            $update['type'] = $toType;
            $update['multilingual'] = $toMultilingual;
            $set = [];
            $bindTypes = '';
            $values = [];
            foreach ($update as $column => $value) {
                $set[] = "`$column` = ?";
                $bindTypes .= is_int($value) ? 'i' : 's';
                $values[] = $value;
            }
            $bindTypes .= 'i';
            $values[] = $fieldId;
            $this->db->query("UPDATE `field` SET " . implode(', ', $set) . " WHERE `field_id` = ?", $bindTypes, $values);

            // Volltext: das Feld kommt als Quelle hinzu, fällt heraus oder liefert in
            // den übrigen Sprachen andere Werte (Sprachwechsel)
            if ($fulltextAffected) {
                if ($toType === 'encrypted') {
                    // Sofort neu aufbauen - sonst stünde der Klartext bis dahin im
                    // Suchindex (erst hier: standardFulltextSources() liest den Typ
                    // aus `field`)
                    foreach ($fulltextIds as $fulltextId) {
                        $this->recordDataset()->refreshFulltext($fulltextId);
                    }
                    foreach ($fulltextAreas as $fulltextAreaId) {
                        $this->recordDataset()->refreshStandardFulltext($fulltextAreaId);
                    }
                }
                else {
                    // sonst nur als veraltet markieren (FulltextState)
                    $this->fulltextState()->markStale($fulltextAreas);
                }
            }

            // Kopie des Default-Feldes (data_dft): Klartext eines verschlüsselten
            // Feldes gehört nicht hinein (dann wie beim Speichern "ID {record_id}"),
            // sonst der umgewandelte Wert je Sprache
            if (!empty($data['default_field']) && $toType !== 'file') {
                if (in_array($toType, ['password', 'encrypted', 'json'], true)) {
                    $this->db->query(
                        "UPDATE `data_dft` `d` INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
                        . " SET `d`.`data` = CONCAT('ID ', `r`.`record_id`) WHERE `r`.`area_id` = ?",
                        'i', [$areaId]
                    );
                }
                elseif ($typeChanged || $multilingualChanged) {
                    $toTable = 'data_' . $this->cms->dataSuffix[$toType];
                    $this->db->query(
                        "UPDATE `data_dft` `d` INNER JOIN `{$toTable}` `x`"
                        . " ON `x`.`record_id` = `d`.`record_id` AND `x`.`language_id` = `d`.`language_id` AND `x`.`field_id` = ?"
                        . " SET `d`.`data` = LEFT(CAST(`x`.`data` AS CHAR), 255)",
                        'i', [$fieldId]
                    );
                }
            }

            // 6. Inhalts-Cache des Bereichs verwerfen - Arbeitskopien ausgenommen: ihr
            // `record_cache` ist der Arbeitsstand; er bekommt den neuen Typ
            // (retypeWorkingCopies()), damit beim Speichern nichts verloren geht
            $this->db->query(
                "DELETE `c` FROM `record_cache` `c`"
                . " INNER JOIN `record` `r` ON `r`.`record_id` = `c`.`record_id`"
                . " WHERE `r`.`area_id` = ? AND `r`.`tmp` = 0",
                'i', [$areaId]
            );
            if ($toType !== 'file' && ($typeChanged || $multilingualChanged)) {
                $this->cms->clearFieldCaches();
                $this->recordDataset()->retypeWorkingCopies(
                    $areaId, $name, $toType,
                    fn(string $value) => $typeChanged ? $this->convertValue($fromType, $toType, $value) : $value
                );
            }

            $this->db->commit();
            return true;

        } catch (\mysqli_sql_exception $e) {
            $this->cms->error('MySQL error: ' . $e->getMessage() . ' (Code ' . $e->getCode() . ')');
            $this->db->rollback();
            return false;
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            if (!str_starts_with($errorMsg, '#')) $this->cms->error($errorMsg);
            $this->db->rollback();
            return false;
        } finally {
            $this->db->popResult();
        }
    }

    /**
     * Änderungen an der Felddefinition, die ein Typwechsel nach sich zieht
     * (Spalte => Wert für das UPDATE in convert()).
     *
     *     display_type   muss zum neuen Typ passen (Backend::$displayTypes), sonst
     *                    die erste zulässige Darstellung
     *     flags          nur, was der neue Typ kennt (Backend::processingFlags() und
     *                    $validationFlags)
     *     min/max        bedeuten je Gruppe etwas anderes (Zeichen vs. Wert) und
     *                    werden beim Gruppenwechsel geleert, ebenso pattern und default;
     *                    bei relation1/pointer1 gilt max = 1, umgekehrt fällt es weg
     *     custom_order   nur bei n:m-Verknüpfungen
     *
     * @param array  $data    Felddaten aus load()
     * @param string $toType
     */
    private function conversionUpdate(array $data, string $toType): array
    {
        $fromType = (string)$data['type'];
        $update = [];
        if ($toType === $fromType) return $update;

        $backend = $this->container->get(Backend::class);

        // display_type
        $displayTypes = (array)($backend->displayTypes[$toType] ?? []);
        $displayType = (string)($data['display_type'] ?? '');
        if (!in_array($displayType, $displayTypes, true)) {
            $update['display_type'] = (string)($displayTypes[0] ?? 'text');
        }

        // flags
        $allowedFlags = array_merge(
            $backend->processingFlags($toType),
            (array)($backend->validationFlags[$toType] ?? [])
        );
        $flags = array_values(array_intersect((array)($data['flags'] ?? []), $allowedFlags));
        $update['flags'] = json_encode($flags, JSON_UNESCAPED_UNICODE);

        // Gruppenwechsel Text <-> Zahl: Grenzwerte, Muster und Vorgabe passen nicht mehr
        $fromText = in_array($fromType, $this->convertTextTypes, true);
        $toText   = in_array($toType, $this->convertTextTypes, true);
        if ($fromText !== $toText || $toType === 'bit' || $fromType === 'bit') {
            $update['min'] = '';
            $update['max'] = '';
            $update['pattern'] = '';
            $update['default'] = '';
        }

        // relation / pointer
        if (in_array($toType, $this->cms->singleRelationTypes, true)) {
            $update['max'] = '1';
            $update['custom_order'] = 0;
        }
        elseif (in_array($fromType, $this->cms->singleRelationTypes, true)) {
            $update['max'] = '';
        }
        return $update;
    }

    /**
     * Bestand eines Feldes in die data_*-Tabelle des neuen Typs umkopieren.
     *
     * Zeile für Zeile in PHP statt INSERT ... SELECT: die Umwandlung der Werte
     * (convertValue()) braucht Entschlüsseln, Cent-Rechnung und Zahlenparser, und
     * MySQL würde ein unpassendes CAST im strikten Modus abbrechen. Gelesen wird in
     * Blöcken; language_id, record_id, field_id, fieldname_id und `empty` wandern
     * unverändert mit, verschlüsselte Ziele bekommen Geheimtext und Hash.
     *
     * @param int    $fieldId
     * @param string $fromType
     * @param string $toType
     */
    private function convertRows(int $fieldId, string $fromType, string $toType): void
    {
        $fromTable = 'data_' . $this->cms->dataSuffix[$fromType];
        $toTable   = 'data_' . $this->cms->dataSuffix[$toType];
        $fromEmpty = $this->cms->hasEmptyFlag($fromType);
        $toEmpty   = $this->cms->hasEmptyFlag($toType);
        $bindType  = $this->getBindType($toType);

        $emptyCol = $fromEmpty ? ', `empty`' : '';
        $sqlSelect = "SELECT `language_id`, `record_id`, `field_id`, `fieldname_id`, `data`{$emptyCol}"
            . " FROM `{$fromTable}` WHERE `field_id` = ? ORDER BY `record_id`, `language_id` LIMIT 500 OFFSET ?";

        $sqlInsert = "INSERT INTO `{$toTable}` SET `language_id` = ?, `record_id` = ?, `field_id` = ?, `fieldname_id` = ?, `data` = ?";
        $insertTypes = 'iiii' . $bindType;
        if ($toType === 'encrypted') {
            $sqlInsert .= ", `hash` = ?";
            $insertTypes .= 's';
        }
        if ($toEmpty) {
            $sqlInsert .= ", `empty` = ?";
            $insertTypes .= 'i';
        }

        $offset = 0;
        while (true) {
            $this->db->query($sqlSelect, 'ii', [$fieldId, $offset]);
            $rows = $this->db->fetchAll();
            if (empty($rows)) break;
            foreach ($rows as $row) {
                $value = (string)($row['data'] ?? '');
                if ($fromType === 'encrypted') {
                    $value = $this->crypt->decrypt($value, '', true);
                }
                $newValue = $this->convertValue($fromType, $toType, $value);

                $values = [(int)$row['language_id'], (int)$row['record_id'], (int)$row['field_id'], (int)$row['fieldname_id']];
                if ($toType === 'encrypted') {
                    $values[] = $this->crypt->encrypt((string)$newValue, '', true);
                    $values[] = $this->cms->encryptedHash((string)$newValue);
                }
                else {
                    $values[] = $newValue;
                }
                if ($toEmpty) {
                    $values[] = ($fromEmpty && !empty($row['empty'])) ? 1 : 0;
                }
                $this->db->query($sqlInsert, $insertTypes, $values, true);
            }
            $offset += count($rows);
            if (count($rows) < 500) break;
        }
        $this->db->query("DELETE FROM `{$fromTable}` WHERE `field_id` = ?", 'i', [$fieldId]);
    }

    /**
     * Einen Wert vom Quell- in den Zieltyp umrechnen (Datenbankformat beider Seiten).
     *
     *     -> Text        Zahlen als Dezimalzahl mit Punkt (Cent -> Betrag mit zwei
     *                    Nachkommastellen, decimal ohne Nachnullen), chars auf 255 Zeichen
     *     Text -> Zahl   parseNumber(): erste Zahl im Text, Komma als Dezimaltrenner
     *                    zulässig, sonst 0
     *     -> integer     Nachkommastellen abgeschnitten, Cent -> ganze Beträge
     *     -> currency    Betrag mal 100, gerundet
     *     -> decimal     10 Nachkommastellen (bcmath, ohne Genauigkeitsverlust)
     *     -> bit         1 für "true/yes/on/ja" und jede Zahl ungleich 0
     *
     * @param string $fromType
     * @param string $toType
     * @param string $value   Wert der Quelle (entschlüsselt)
     * @return int|float|string  Wert für die Spalte `data` der Zieltabelle
     */
    private function convertValue(string $fromType, string $toType, string $value): int|float|string
    {
        // Text-Ziel
        if (in_array($toType, $this->convertTextTypes, true)) {
            $text = match ($fromType) {
                'currency' => $this->centsToText((int)$value),
                'float'    => $this->trimDecimal(sprintf('%.10F', (float)$value)),
                'decimal'  => $this->trimDecimal($value),
                default    => $value,
            };
            return ($toType === 'chars') ? mb_substr($text, 0, 255) : $text;
        }

        // Zahl-Ziel: Quelle als Dezimalzahl (String mit Punkt)
        if (in_array($fromType, $this->convertTextTypes, true)) {
            $number = $this->parseNumber($value);
            if ($toType === 'bit' && $number === null) {
                $flag = filter_var(trim($value), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                return ($flag === true || in_array(mb_strtolower(trim($value)), ['ja', 'x'], true)) ? 1 : 0;
            }
            $number ??= '0';
        }
        else {
            $number = match ($fromType) {
                'currency' => $this->centsToText((int)$value),
                'float'    => sprintf('%.10F', (float)$value),
                default    => (string)$value,   // integer, bit, decimal
            };
        }

        return match ($toType) {
            'integer'  => preg_match('/^-?\d+$/', $number) ? (int)$number : (int)bcadd($number, '0', 0),
            'currency' => (int)round((float)bcmul($number, '100', 4)),
            'float'    => (float)$number,
            'decimal'  => bcadd($number, '0', 10),
            'bit'      => (bccomp($number, '0', 10) !== 0) ? 1 : 0,
            default    => $number,
        };
    }

    /**
     * Cent-Betrag (currency) als Dezimalzahl mit zwei Nachkommastellen, z. B. -1234 -> "-12.34".
     */
    private function centsToText(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . sprintf('%02d', $cents % 100);
    }

    /**
     * Nachnullen einer Dezimalzahl entfernen: "12.5000000000" -> "12.5", "3.0" -> "3".
     */
    private function trimDecimal(string $number): string
    {
        if (!str_contains($number, '.')) return $number;
        $number = rtrim(rtrim($number, '0'), '.');
        return ($number === '' || $number === '-') ? '0' : $number;
    }

    /**
     * Erste Zahl in einem Text finden - Dezimaltrenner Punkt oder Komma, Tausender-
     * trenner werden entfernt ("1.234,56" -> "1234.56", "1,234.56" -> "1234.56",
     * "12,5" -> "12.5"). null, wenn keine Zahl enthalten ist.
     */
    private function parseNumber(string $text): ?string
    {
        $text = str_replace(["\u{a0}", ' '], '', trim($text));
        if (!preg_match('/-?\d[\d.,]*/', $text, $m)) return null;
        $number = $m[0];
        $lastComma = strrpos($number, ',');
        $lastDot   = strrpos($number, '.');
        if ($lastComma !== false && $lastDot !== false) {
            // beides vorhanden: das hintere ist der Dezimaltrenner
            $decimalSep = ($lastComma > $lastDot) ? ',' : '.';
            $number = str_replace($decimalSep === ',' ? '.' : ',', '', $number);
            $number = str_replace(',', '.', $number);
        }
        elseif ($lastComma !== false) {
            $number = str_replace(',', '.', $number);
        }
        // mehrere Punkte: nur der letzte ist der Dezimaltrenner
        if (substr_count($number, '.') > 1) {
            $pos = strrpos($number, '.');
            $number = str_replace('.', '', substr($number, 0, $pos)) . substr($number, $pos);
        }
        $number = rtrim($number, '.');
        return preg_match('/^-?\d+(\.\d+)?$/', $number) ? $number : null;
    }

    /**
     * relation -> relation1 bzw. pointer -> pointer1: je Quelldatensatz bleibt nur die
     * erste Verknüpfung (kleinste position, bei Gleichstand die kleinste dest_id);
     * bei Verknüpfungen fällt die Gegenzeile des Gegenfeldes mit.
     *
     * @param int  $fieldId
     * @param int  $relFieldId     Gegenfeld (nur relation)
     * @param bool $bidirectional  true bei relation
     */
    private function reduceRelations(int $fieldId, int $relFieldId, bool $bidirectional): void
    {
        $this->db->query(
            "SELECT `src_id`, `dest_id` FROM `data_rel` WHERE `field_id` = ? ORDER BY `src_id`, `position`, `dest_id`",
            'i', [$fieldId]
        );
        $rows = $this->db->fetchAll();
        $seen = [];
        foreach ($rows as $row) {
            $srcId = (int)$row['src_id'];
            $destId = (int)$row['dest_id'];
            if (!isset($seen[$srcId])) {
                $seen[$srcId] = true;
                continue;
            }
            $this->db->query(
                "DELETE FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?",
                'iii', [$fieldId, $srcId, $destId]
            );
            if ($bidirectional && $relFieldId > 0) {
                $this->db->query(
                    "DELETE FROM `data_rel` WHERE `field_id` = ? AND `src_id` = ? AND `dest_id` = ?",
                    'iii', [$relFieldId, $destId, $srcId]
                );
            }
        }
    }

    /**
     * Bestand eines Feldes von ein- auf mehrsprachig umstellen oder umgekehrt.
     *
     * Jeder Datensatz führt je Sprache seiner Instanz eine Zeile (siehe
     * docs/developers/02-datenmodell.md); geändert wird nur deren Bedeutung:
     *
     *     -> mehrsprachig   die Zeile der Hauptsprache trägt den Inhalt, alle anderen
     *                       werden Fallback (`empty` = 1) - übersetzt ist noch nichts
     *     -> einsprachig    alle Zeilen bekommen den Inhalt der Hauptsprache
     *                       (`empty` = 0); die Übersetzungen gehen verloren
     *
     * Die Hauptsprache ist die der Instanz des Datensatzes - je Instanz ein UPDATE.
     * Datei-Felder stellen stattdessen Dateien und `data_fil`-Zeilen um
     * (migrateFileLanguage()) und ziehen ihre Thumbnail-Felder nach.
     *
     * @param int    $fieldId
     * @param string $type            Typ (nach der Umwandlung)
     * @param bool   $toMultilingual
     */
    private function applyMultilingualChange(int $fieldId, string $type, bool $toMultilingual): void
    {
        if ($type === 'file') {
            // Thumbnail-Felder (auch verschachtelte) erben das Kennzeichen, Dateien
            // samt `data_fil`-Zeilen kommen auf das neue Ablageschema
            $parentIds = [$fieldId];
            $seen = [];
            while (!empty($parentIds)) {
                $parentId = array_shift($parentIds);
                if (isset($seen[$parentId])) continue;
                $seen[$parentId] = true;
                $this->migrateFileLanguage($parentId, $toMultilingual);
                $this->recordDataset()->migrateWorkingCopyFiles($parentId, $toMultilingual);
                $this->db->query("SELECT `field_id` FROM `field` WHERE `parent_field_id` = ?", 'i', [$parentId]);
                foreach ($this->db->fetchAll() as $childId) {
                    $this->db->query("UPDATE `field` SET `multilingual` = ? WHERE `field_id` = ?", 'ii', [(int)$toMultilingual, (int)$childId]);
                    $parentIds[] = (int)$childId;
                }
            }
            return;
        }
        if (!$this->cms->hasEmptyFlag($type)) return;

        $table = 'data_' . $this->cms->dataSuffix[$type];
        $instanceCache = $this->container->get(InstanceCache::class);
        $this->db->query(
            "SELECT DISTINCT `r`.`instance_id` FROM `{$table}` `d`"
            . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id` WHERE `d`.`field_id` = ?",
            'i', [$fieldId]
        );
        foreach (array_map('intval', $this->db->fetchAll()) as $instanceId) {
            $primaryId = $instanceCache->primaryLanguageId($instanceId);
            if ($toMultilingual) {
                $this->db->query(
                    "UPDATE `{$table}` `d` INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
                    . " SET `d`.`empty` = IF(`d`.`language_id` = ?, 0, 1)"
                    . " WHERE `d`.`field_id` = ? AND `r`.`instance_id` = ?",
                    'iii', [$primaryId, $fieldId, $instanceId]
                );
            }
            else {
                $hashCol = ($type === 'encrypted') ? ", `d`.`hash` = `p`.`hash`" : '';
                $this->db->query(
                    "UPDATE `{$table}` `d`"
                    . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
                    . " INNER JOIN `{$table}` `p` ON `p`.`record_id` = `d`.`record_id`"
                    . "     AND `p`.`field_id` = `d`.`field_id` AND `p`.`language_id` = ?"
                    . " SET `d`.`data` = `p`.`data`{$hashCol}, `d`.`empty` = 0"
                    . " WHERE `d`.`field_id` = ? AND `r`.`instance_id` = ?",
                    'iii', [$primaryId, $fieldId, $instanceId]
                );
            }
        }
    }

    // Hilfsfunktionen
    // ====================

    /**
     * Eintrag in `fieldname` löschen, wenn ihn kein Feld mehr benutzt.
     *
     * Öffentlich, weil Area::hookOnDelete() die Methode beim endgültigen Löschen
     * eines Bereichs von außen ruft (dort fallen alle Felder des Bereichs per
     * fk_field_area weg, ihre Namen bleiben sonst in `fieldname` stehen).
     *
     * @param string $name  speziellen Feldname prüfen,
     *                      default '': alle Feldnamen prüfen. Wird z.B. bei Löschen eines Areas genutzt.
     */
    public function cleanupFieldname(string $name = ''): void
    {
        // Das Volltext-Standardfeld hat keine `field`-Zeile - sein Name bleibt
        if (!empty($name)) {
            $sql = "DELETE FROM `fieldname` WHERE `name` = ? AND `name` != ?"
            ." AND NOT EXISTS (SELECT 1 FROM `field` WHERE `field`.`name` = `fieldname`.`name`)";
            $this->db->query($sql, 'ss', [$name, self::FULLTEXT]);
        }
        else {
            $sql = "DELETE FROM `fieldname` WHERE `name` != ?"
            ." AND NOT EXISTS (SELECT 1 FROM `field` WHERE `field`.`name` = `fieldname`.`name`)";
            $this->db->query($sql, 's', [self::FULLTEXT]);
        }
    }

    /**
     * Legt Eintrag in fieldname mit angegebenem Name+Typ an (wenn nicht schon vorhanden)
     * Wenn schon vorhanden, aber mit anderem Typ, wird der verwendete Typ zurückgegeben
     *
     * FOR UPDATE sperrt die Zeile bis zum Ende der Transaktion. Ohne das könnten zwei
     * parallele Speichervorgänge denselben Namen für zwei verschiedene Typen anlegen -
     * `fieldname` hat UNIQUE(name), einer der beiden liefe in einen Duplikatfehler
     * statt in die verständliche Meldung error_field_name_wrong_type.
     *
     * @param string $name  Eingabefeld-Name
     * @param string $type  Eingabefeld-Typ
     * @return string       bei Typkonflikt: verwendeter Typ, ansonsten ''
     */
    private function createFieldname(string $name, string $type): string
    {
        $sql = "SELECT `type` FROM `fieldname` WHERE `name` = ? FOR UPDATE";
        $this->db->query($sql, 's', [$name]);

        if ($this->db->num_rows > 0) {
            $usedType = (string) $this->db->fetchNum()[0];

            // Rückgabe falscher Typ
            if ($type !== $usedType) return $usedType;

            // richtiger Typ - schon vorhanden -> keine Aktion nötig
            return '';

        } else {
            // noch nicht vorhanden -> jetzt anlegen
            $sql = "INSERT INTO `fieldname` (`name`, `type`) VALUES (?, ?)";
            $this->db->query($sql, 'ss', [$name, $type]);            
            return '';
        }
    }

    /**
     * Generiert einen eindeutigen Feldname in Tabelle `field` mit maximal 32 Zeichen.
     * Prüft ob der Name bereits existiert und kürzt bei Bedarf.
     * (wegen FOR UPDATE nur innerhalb Transaktion verwendbar)
     *
     * Gebraucht für die automatisch erzeugten Gegenfelder von Relationen: deren Name
     * `rel_{areaname}` kann im Zielbereich schon vergeben sein. Angehängt wird dann
     * _1, _2, … - und weil `field`.`name` nur 32 Zeichen fasst, wird der Präfix um die
     * Länge des Suffixes gekürzt statt das Ergebnis hinten abzuschneiden.
     *
     * @param string $prefix  Names-Prefix.
     * @param int $areaId     Bereichs-ID
     * @return string         Garantiert eindeutiger Name (max 32 Zeichen).
     */
    public function generateUniqueFieldName(string $prefix, int $areaId): string 
    {
        $suffixNumber = 0;
        $isUnique = false;
        $finalName = '';

        $sql = "SELECT 1 FROM `field` WHERE `area_id`=? AND `name` = ? LIMIT 1 FOR UPDATE";

        while (!$isUnique) {
            // Zähler-String bilden
            $suffixStr = ($suffixNumber === 0) ? '' : '_' . $suffixNumber;
            
            // Verfügbaren Platz berechnen und Präfix passend abschneiden
            $allowedPrefixLength = 32 - strlen($suffixStr);
            $currentPrefix = mb_substr($prefix, 0, $allowedPrefixLength);
            
            $finalName = $currentPrefix . $suffixStr;

            // Abfrage mit vorbereitetem Statement
            $this->db->query($sql, 'is', [$areaId, $finalName]);
            
            if ($this->db->num_rows === 0) {
                $isUnique = true;
            } else {
                $suffixNumber++;
            }
        }

        return $finalName;
    }
}
