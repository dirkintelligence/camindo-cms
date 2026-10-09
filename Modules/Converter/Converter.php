<?php
/**
 * Dataset-Kindklasse für die Tabelle `converter` - die konfigurierten Medienkonverter.
 *
 * Ein Konverter ist eine benannte Einstellung EINES Prozessors: "ImageGd, 800x600, JPEG,
 * Qualität 85". Der Prozessor ist der Programmcode (Core/Processors/ImageGd, Magick,
 * FFmpegVideo, …), der Konverter die Konfiguration dazu. Zu einem Prozessor kann es
 * beliebig viele Konverter geben; ein Upload-Feld vom display_type 'thumbnail' verweist
 * über converter_ids auf die, die es benutzen soll.
 * converter_ids selbst speichert (sortierbar) in `rel_field_converter`
 *
 * DAS SCHEMA STEHT ERST NACH DEM LADEN DES PROZESSORS FEST
 * --------------------
 * Das ist die Besonderheit dieser Klasse. hookDefinition() beschreibt nur die
 * gemeinsamen Spalten (processor, label, extension, Mime-Filter, details). Welche
 * Einstellungen darüber hinaus möglich sind, weiß nur der Prozessor - er liefert sie
 * über MediaProcessor::getSchema(), und loadProcessor() hängt sie an:
 *
 *     $this->schema = $this->schema + $this->processor->getSchema();
 *
 * Diese zusätzlichen Felder landen in der JSON-Spalte `details`. Deshalb überschreiben
 * init() und save() die Basisklasse: sie müssen den Prozessor kennen, BEVOR das Schema
 * gebraucht wird - erst danach rufen sie parent::init() bzw. parent::save() auf. Beim
 * Laden erledigt das hookOnLoaded(), das den Prozessornamen aus der geladenen Zeile
 * nimmt.
 *
 * Aus demselben Grund reichen alle Hooks an den Prozessor weiter: der Prozessor bringt
 * eigene Feldnamen mit und muss sie selbst prüfen und aufbereiten.
 *
 * KENNUNG FÜR EXPORT UND IMPORT (settingsHash)
 * --------------------
 * Ein Thumbnail-Feld verweist per converter_id auf seine Konverter. In einer
 * Exportdatei (RecordExport, <converters>) ist die Id nur innerhalb derselben Datenbank
 * etwas wert - in einer fremden Datenbank gehört sie einem beliebigen anderen
 * Konverter. Deshalb bekommt jeder exportierte Konverter einen Hash seiner
 * EINSTELLUNGEN (Prozessor, Erweiterung, Medientypen, details - nicht die
 * Bezeichnung): settingsHash(). Der Import (RecordImport::_importConverters())
 * gleicht aus derselben Datenbank nach Id ab, sonst nach diesem Hash
 * (findByHash()), legt den Konverter neu an oder lässt den Redakteur einen
 * vorhandenen wählen. Der Hash wird nicht gespeichert, sondern bei Bedarf aus
 * den Zeilen berechnet - die Tabelle ist klein.
 *
 * WELCHE PROZESSOREN ES GIBT
 * --------------------
 * Nicht aus der Tabelle `component`, sondern aus der von ComponentBackend generierten
 * Datei var/config/components/processors.php. hookDefinition() bindet sie per include
 * ein - die Zuweisungen darin füllen $this->processors. Die Datei muss also nach jeder
 * Änderung an den Prozessor-Verzeichnissen neu erzeugt werden.
 *
 * @see Camindo\Core\MediaProcessor                       Basisklasse aller Prozessoren
 * @see Camindo\Core\ProcessorFactory                     erzeugt die Prozessor-Instanz
 * @see Camindo\Core\Modules\Converter\ConverterBackend  Backend-Oberfläche
 * @see Camindo\Core\Modules\Component\ComponentBackend  erzeugt processors.php
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Converter;

use Camindo\Core\Dataset;
use Camindo\Core\MediaProcessor;

class Converter extends Dataset {

    // Grobeinteilung der Prozessoren; der tatsächliche Wert steht in der settings.php
    // des Prozessors und landet beim Speichern in der Spalte `type`.
    public array $processorTypes = ['image', 'audio', 'video', 'document', 'other'];

    // Aktive Prozessoren - Cms::$processors aus var/config/components/processors.php
    // (ComponentManager). Schlüssel ist der Verzeichnis-/Klassenname, Wert die
    // settings.php dazu plus 'path', 'namespace', 'core'.
    public array $processors = [];

    /** Schema ohne Prozessor-Felder (loadProcessor()) */
    private ?array $baseSchema = null;

    // Prozessor des gerade bearbeiteten Konverters. Muss gesetzt sein, bevor
    // loadProcessor() das Schema erweitern kann.
    public string $processorName = '';

    /**
     * Tabellenschema bereitstellen.
     *
     * Nur der gemeinsame Teil - die prozessorspezifischen Felder kommen später in
     * loadProcessor() dazu (siehe Klassenkommentar).
     *
     * Am Ende werden die aktiven Prozessoren aus Cms übernommen (Laufzeitliste des
     * ComponentManagers). Ist die Liste leer, scheitert jeder Zugriff an der Prüfung
     * "nicht installiert" - `camindo.php refresh` bzw. der Refresh im Modul Component
     * muss dann laufen.
     */
    protected function hookDefinition(): void
    {
        $this->tableName  = 'converter';
        $this->moduleName = 'Converter';
        $this->defaultSortOrder = 'processor ASC, label ASC';
        $this->schema = [
            'converter_id' => [
                'type'  => 'integer',
                'label' => 'converter_id'
            ],
            'processor' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#converter.field_processor',
                'max'   =>  32,
                'pattern' => '[a-zA-Z0-9_\\-]+',
                'flags' => ['trim', 'not_empty']
            ],
            'type' => [
                'type'  => 'chars',
                'label' => '#converter.field_type',
                'default' => 'other',
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#converter.field_label',
                'max'   => 255,
                'flags' => ['trim', 'not_empty'],
                'extension' => 'Snippets',   // Core-Extension: Snippet auswählen (#prefix.key)
            ],
            'media_types_incl' => [
                'type'  => 'text',
                'display_type' => 'textarea',
                'label' => '#converter.field_media_types_incl',
                'note' => '#converter.note_field_media_types',
                'tooltip' => '#converter.tooltip_field_media_types',
                'flags' => ['trim']
            ],
            'media_types_excl' => [
                'type'  => 'text',
                'display_type' => 'textarea',
                'label' => '#converter.field_media_types_excl',
                'note' => '#converter.note_field_media_types',
                'tooltip' => '#converter.tooltip_field_media_types',
                'flags' => ['trim']
            ],
            'extension' => [
                'type'  => 'chars',
                'max' => 16,
                'label' => '#converter.field_extension',
                'note' => '#converter.note_field_extension',
                'tooltip' => '#converter.tooltip_field_extension',
                'pattern' => '[^\\.]+',
                'prefix' => '.'
            ],
            'details' => [
                'type'  => 'json',
                'label' => '#converter.field_details',
            ]
        ];

        // Aktive Prozessoren, auch für Frontend-Funktionen
        $this->processors = $this->cms->processors;
    }

    /**
     * Überschreibe init() der Dataset Parent-Klasse:
     * Zuerst Prozessor laden dann eigentliche parent::init() aufrufen.
     *
     * Ohne den Prozessor kennt das Schema seine Felder nicht, und init() würde für die
     * prozessorspezifischen Einstellungen keine Defaults liefern. Deshalb ist
     * $defaults['processor'] hier Pflicht - anders als bei allen anderen Datasets kann
     * init() nicht ohne Argument aufgerufen werden.
     */
    public function init(array $defaults = []): ?array
    {
        $this->processorName = $this->cleanProcessorName($defaults['processor']);
        if (empty($this->processorName)) {
            throw new \Exception("Error: Converter init() has to be called with defaults[processor]!");
        }

        if (!isset($this->processors[$this->processorName])) {
            throw new \Exception(
                str_replace('{value}', $this->processorName, $this->cms->snippet('converter.error_processor_not_installed'))
            );
        }

        // load processor
        $this->loadProcessor();

        // jetzt ist das Schema vollständig, lade die eigentliche init() function
        return parent::init($defaults);
    }

    /**
     * Vor dem Erstellen: Vorbelegungen des Prozessors übernehmen.
     *
     * Zuerst darf der Prozessor seinen eigenen Hook ausführen, danach überschreiben
     * seine deklarierten $defaults die Werte aus dem Schema - der Prozessor hat also
     * das letzte Wort über die Vorbelegung seiner Felder.
     */
    protected function hookOnCreate(array &$data): void
    {
        // erweitere Hook durch den Prozessor
        $this->processor->hookOnCreate($data);

        // Überschreibe Default-Werte der Konverter-Eingabefelder
        foreach (($this->processor->defaults ?? []) as $key => $val) {
            $data[$key] = $val;
        }
    }

    /**
     * INSERT / UPDATE abhängig von $data["{$tableName}_id"]
     *
     * Überschreibt Dataset::save() aus demselben Grund wie init(): der Prozessor muss
     * geladen sein, bevor die Validierung über das Schema läuft, sonst wären seine
     * Felder unbekannt und würden verworfen.
     *
     * Der Prozessorname wird vorher gesäubert und gegen die Liste der installierten
     * Prozessoren geprüft - aus ihm wird ein Klassenname, er darf also nicht ungeprüft
     * aus dem Formular durchgereicht werden.
     *
     * $stayTemporary wird entgegengenommen, aber nicht weitergegeben (parent::save()
     * bekommt fest false): Konverter kennen keine Arbeitskopien.
     *
     * @param array $fields         Namen der Felder, die geschrieben werden
     *                              (leeres Array: alle Felder sind gemeint)
     * @param array $data           Inhalte
     * @return int|null             Id des erstellten/geändeten Datensatzes
     */
    public function save(array $fields, array &$data, bool $stayTemporary = false): ?int
    {
        // processor
        $data['processor'] = $this->cleanProcessorName($data['processor'] ?? '');
        $this->processorName = $data['processor'];
        if (!isset($this->processors[$this->processorName])) {
            throw new \Exception(
                str_replace('{value}', $this->processorName, $this->cms->snippet('converter.error_processor_not_installed'))
            );
        }
        // extension
        $data['extension'] = strtolower((string)($data['extension'] ?? ''));
        
        // load processor
        $this->loadProcessor();

        // jetzt ist das Schema vollständig, lade die eigentliche save() function
        return parent::save($fields, $data, false);
    }

    /**
     * Vor dem Speichern.
     *
     * `type` ist kein Eingabefeld - es folgt immer dem Prozessor und wird deshalb hier
     * aus dessen settings.php gesetzt. So bleibt die Spalte auch dann richtig, wenn ein
     * Prozessor später einer anderen Art zugeordnet wird.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        // Ziehe 'type' aus der settings.php des verwendeten Prozessors
        $data['type'] = $this->processors[$data['processor']]['type'] ?? 'other';
        
        // erweitere Hook durch den Prozessor
        $this->processor->hookOnSave($fields, $data, $previousData);
    }

    /**
     * Nach dem Speichern...
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        // Dataset::hookOnSave() has been called before
        // -> processor is loaded

        // erweitere Hook durch den Prozessor
        $this->processor->hookOnSaved($fields, $data, $previousData);
    }

    /**
     * Nach dem Laden: Prozessor nachziehen.
     *
     * Hier - und nicht schon in load() - weil der Prozessorname erst mit der geladenen
     * Zeile bekannt ist. Ab diesem Punkt ist das Schema vollständig, weshalb die
     * folgenden Hooks (hookOnDelete) den Prozessor bereits vorfinden.
     */
    protected function hookOnLoaded(array &$data): void
    {
        $this->processorName = $data['processor'];

        // load processor
        $this->loadProcessor();

        // erweitere Hook durch den Prozessor
        $this->processor->hookOnLoaded($data);
    }

    /**
     * Vor dem Löschen...
     */
    protected function hookOnDelete(array $data): void
    {
        // Dataset::load() has been called before
        // -> processor is loaded

        // erweitere Hook durch den Prozessor
        $this->processor->hookOnDelete($data);
    }

    /**
     * Medien-Prozessor bereitstellen und das Schema um seine Felder erweitern.
     *
     * Der Zusammenbau per '+' (Array-Union) statt array_merge(): bei gleichen
     * Schlüsseln gewinnt die linke Seite, die Grunddefinition des Konverters lässt sich
     * von einem Prozessor also nicht überschreiben.
     *
     * Die Prüfungen davor sind kein Luxus - aus $processorName wird in der Factory ein
     * Klassenname.
     */
    protected function loadProcessor(): void
    {
        if (empty($this->processorName)) {
            throw new \Exception("Error: loadProcessor() processorName is empty.");
        }
        if (!isset($this->processors[$this->processorName])) {
            throw new \Exception("Error: loadProcessor() \"{$this->processorName}\" is not installed.");
        }
        
        $this->processor = $this->factory->create($this->processorName);

        // Schema des Konverters um Eingabefelder des Prozessors erweitern - immer vom
        // Grundschema aus: lädt dieselbe Instanz nacheinander verschiedene Konverter
        // (API, Import), darf kein Feld des vorigen Prozessors stehen bleiben
        $this->baseSchema ??= $this->schema;
        $this->schema = $this->baseSchema + $this->processor->getSchema();
    }

    /**
     * Hash der Einstellungen eines Konverters (siehe Klassenkommentar).
     *
     * Bezeichnung und Id gehen nicht ein - zwei Konverter mit denselben Einstellungen
     * sind für den Import derselbe. Die Eingaben werden vor dem Hashen vereinheitlicht,
     * damit ein aus dem Formular (Strings) und ein aus der Datenbank (JSON mit Zahlen)
     * stammender Konverter denselben Hash bekommen: Medientypen zeilenweise getrimmt,
     * kleingeschrieben und sortiert; details rekursiv nach Schlüssel sortiert, Skalare
     * als String.
     *
     * @param array $row  Zeile der Tabelle `converter` in GESPEICHERTER Form (details als
     *                    JSON-String oder Array) - nicht das Ergebnis von load():
     *                    hookOnLoaded() der Prozessoren verändert Werte (ImageGd/Magick
     *                    stellen bgcolor ein '#' voran), der Hash wäre ein anderer
     * @return string     40 Hex-Zeichen (sha1)
     */
    public static function settingsHash(array $row): string
    {
        $mediaTypes = function ($value): array {
            $lines = preg_split('/(\r\n|\r|\n)/', (string)$value) ?: [];
            $lines = array_values(array_unique(array_filter(array_map(fn($l) => strtolower(trim($l)), $lines), fn($l) => $l !== '')));
            sort($lines, SORT_STRING);
            return $lines;
        };
        $details = $row['details'] ?? [];
        if (!is_array($details)) $details = json_decode((string)$details, true) ?: [];
        $normalize = function ($value) use (&$normalize) {
            if (!is_array($value)) return is_bool($value) ? ($value ? '1' : '0') : (string)$value;
            ksort($value, SORT_STRING);
            foreach ($value as $k => $v) $value[$k] = $normalize($v);
            return $value;
        };
        $subject = [
            'processor' => (string)($row['processor'] ?? ''),
            'extension' => strtolower(trim((string)($row['extension'] ?? ''))),
            'media_types_incl' => $mediaTypes($row['media_types_incl'] ?? ''),
            'media_types_excl' => $mediaTypes($row['media_types_excl'] ?? ''),
            'details' => $normalize($details),
        ];
        return sha1(json_encode($subject, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Vorhandenen Konverter mit diesen Einstellungen suchen.
     *
     * @return int  converter_id, 0 wenn keiner passt
     */
    public function findByHash(string $hash): int
    {
        if ($hash === '') return 0;
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `converter` ORDER BY `converter_id` ASC");
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        foreach ($rows as $row) {
            if (self::settingsHash($row) === $hash) return (int)$row['converter_id'];
        }
        return 0;
    }

    /**
     * Ist der Prozessor installiert? Für den Import: ein Konverter eines fehlenden
     * Prozessors lässt sich nicht anlegen.
     */
    public function isProcessorInstalled(string $name): bool
    {
        $name = $this->cleanProcessorName($name);
        return $name !== '' && isset($this->processors[$name]);
    }

    /**
     * Verzeichnis des Prozessors liefern ('path' aus der Laufzeitliste:
     * Core/Processors/{Name} oder Processors/{Name}).
     *
     * @return string|null  absoluter Pfad
     */
    /**
     * Prozessor-Instanz des geladenen Konverters.
     *
     * Für die eingebundenen Prozessor-Dateien (backend/form.php, backend/info.php):
     * sie laufen im Gültigkeitsbereich von ConverterBackend und kommen an das
     * geschützte Property der Dataset-Basisklasse sonst nicht heran.
     */
    public function getProcessor(): ?MediaProcessor {
        return $this->processor;
    }

    public function getProcessorDir():?string {
        if (empty($this->processorName)) return null;
        return $this->cms->componentPath('processors', $this->processorName);
    }

    /**
     * Prozessorname säubern.
     *
     * Der Name wird zu einem Verzeichnis- und Klassennamen; alles außerhalb von
     * [a-zA-Z0-9_-] fliegt heraus, damit aus einer Formulareingabe kein Pfad werden
     * kann. Der so gesäuberte Name wird zusätzlich gegen $processors geprüft.
     *
     * @param string $name  Usereingabe
     * @return string       Name in erlaubten Zeichen
     */
    public function cleanProcessorName($name)
    {
        return preg_replace('/[^a-zA-Z0-9_\\-]/','', $name);
    }

    /**
     * Installierte Prozessoren einer Art liefern.
     *
     * Für die Auswahllisten im Backend: beim Anlegen eines Konverters stehen nur die
     * Prozessoren zur Wahl, die zur gewünschten Medienart passen. Ohne Angabe in der
     * settings.php gilt 'other'.
     *
     * @param string $type  'image', 'audio', ...
     */
    public function getProcessorsByType($type)
    {
        $processors = [];
        foreach ($this->processors as $name => $settings) {
            $t = $settings['type'] ?? 'other';
            if ($t == $type) $processors[$name] = $settings;
        }
        return $processors;
    }
}