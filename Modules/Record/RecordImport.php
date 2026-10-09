<?php
/**
 * Import von Datensätzen und Strukturen - das Pendant zu RecordExport: XML
 * (Backup-Format), CSV, DOCX (Übersetzung).
 *
 * Das ist die ARBEIT; die Masken sind RecordBackendImport (record_import.php),
 * erreichbar über das Menü rechts in der Datensatzliste (ein Bereich) und auf der
 * Startseite des Moduls (global). Im Request oder als Hintergrundprozess
 * (Core/cron/record_import.php) läuft EXAKT DERSELBE Code: run().
 *
 * DREI SCHRITTE
 * --------------------
 *     prepare()   Importdatei (Upload oder aus var/upload) nach var/tmp/import-{token}/
 *                 bringen, eine Zip-Datei dort entpacken, die Kandidaten (xml, csv,
 *                 docx) suchen - mehrere: der Redakteur wählt
 *     analyze*()  die gewählte Datei ansehen: XML-Header (Herkunft, Version, Bereiche,
 *                 Sprachen), CSV-Spalten mit Vorschlag der Zielfelder, DOCX-Tabellen
 *     run()       importieren, Protokoll
 *
 * DER AUFTRAG (JOB)
 * --------------------
 *     type        'xml' | 'csv' | 'docx'
 *     area_id     Bereich, aus dessen Liste der Import aufgerufen wurde (0 = global)
 *     dir         Name des Importverzeichnisses in var/tmp (import-{token})
 *     file        Pfad der Importdatei relativ zum Importverzeichnis
 *     mode        Modus der Datensätze (MODES; docx: immer update)
 *     skip        so viele Datensätze (csv: Zeilen) am Anfang überspringen
 *     limit       höchstens so viele Datensätze importieren (0 = alle)
 *     force       Bearbeitungssperren anderer Admins übergehen
 *     parent_id   Vaterdatensatz neuer Datensätze (Bereich mit Baum; 0 = Wurzel) -
 *                 gilt, wenn die Datei keinen nennt (siehe EINFÜGEPOSITION)
 *     previous_id Datensatz, HINTER dem die neuen eingefügt werden (0 = an den
 *                 Anfang); fehlt der Schlüssel: hinter dem letzten vorhandenen
 *     source_area_id  xml (ein Bereich): area_id der Datei, deren Datensätze nach
 *                 area_id importiert werden (0 = alle)
 *     csv         csv: ['separator', 'enclosure', 'eol', 'stored' => bool]
 *     mapping     csv: Spaltenindex => Zielschlüssel (siehe csvTargets())
 *     docx        docx: ['punct' => bool, 'space' => bool, 'rtl' => 'unicode'|'html'|'none']
 *     global      xml (global): true
 *     folder_id   xml (global): Zielebene - Ordner, unter dem neue Bereiche entstehen
 *     instance_id xml (global): Mandant neuer Bereiche (0 = aus der Datei bzw. Zielebene)
 *     areas       xml (global): area_id der Datei => [
 *                     'name'      => abweichender Bereichsname (Maske),
 *                     'structure' => Modus (GLOBAL_MODES), 'structure_by' => 'name'|'area_id',
 *                     'records'   => Modus, 'records_by' => 'slug'|'record_id',
 *                     'templates' => Modus, 'templates_by' => 'name'|'template_id' ]
 *     converters  xml (global): converter_id der Datei => Entscheidung der Maske:
 *                 'new' (anlegen), 'ignore' oder die converter_id eines vorhandenen
 *                 Konverters; fehlt der Schlüssel: automatisch (siehe KONVERTER)
 *     same_db     (von run() gesetzt) Datei stammt aus dieser Datenbank: created_by/owner_id
 *                 wird wiederhergestellt, sonst dem Session-Admin zugewiesen; changed_by
 *                 ist immer der Session-Admin
 *     token       Hintergrund: Token des bgproc-Eintrags (start())
 *     admin_id    Hintergrund: auslösender Admin (Empfänger der Nachricht)
 *
 * MODUS
 * --------------------
 *     insert             immer ein neuer Datensatz; bringt die Datei eine record_id mit
 *                        und ist die frei (oder von einer Arbeitskopie / einem gelöschten
 *                        Datensatz belegt - der wird entfernt), bekommt er diese Id
 *     update_or_insert   vorhanden: nur die Felder der Datei schreiben, alle anderen
 *                        bleiben; sonst wie insert
 *     update             wie update_or_insert, aber nicht vorhandene übergehen
 *     replace_or_insert  vorhanden: alle Felder - was die Datei nicht nennt, wird
 *                        geleert (Verknüpfungen entfernt, Dateien gelöscht); sonst insert
 *     replace            wie replace_or_insert, aber nicht vorhandene übergehen
 *     ignore             (nur global) nicht importieren
 *
 * ZWEI DURCHLÄUFE (XML)
 * --------------------
 * Die Ids der Datei sind nicht die Ids der Zieldatenbank. Deshalb sammelt der
 * Import Zuordnungstabellen (Id der Datei => tatsächliche Id) für Bereiche, Felder,
 * Templates und Datensätze ($this->map) und arbeitet in Stufen:
 *
 *     1. Struktur    Bereiche (Eltern vor Kindern, neue unter der Zielebene), die
 *                    Konverter der Thumbnail-Felder (KONVERTER), dann die Felder
 *                    (einfache Typen, Thumbnails, Relationen mit ihrem Gegenfeld,
 *                    Pointer, Volltext), zuletzt je Bereich Default-/Sortierfeld,
 *                    Default-Template und Template-Zuweisungen
 *     2. Templates   je Template-Bereich
 *     3. Datensätze  erster Durchlauf: Arbeitskopie anlegen bzw. auschecken, Felder,
 *                    Dateien und Passwörter prüfen und einchecken - die record_id
 *                    steht damit fest.
 *                    Zweiter Durchlauf, Datensatz für Datensatz: erneut auschecken,
 *                    Verknüpfungen, Vaterdatensatz, Template und Besitzer mit den
 *                    zugeordneten Ids setzen, einchecken. Nacheinander und nicht in
 *                    einem Rutsch: eine Arbeitskopie kopiert auch die eingehenden
 *                    Verknüpfungen ihres Originals hin und zurück - wären alle
 *                    Datensätze zugleich ausgecheckt, verwürfe der Check-in eines
 *                    Zieldatensatzes die gerade geschriebenen Verknüpfungen seiner
 *                    Quellen.
 *
 * JE DATENSATZ DERSELBE WEG WIE VON HAND
 * --------------------
 * tmpInit() (neu, ggf. mit erzwungener Id) bzw. load() -> Sperre -> checkOut(), dann
 * Record::importSave(): Eingabeprüfung nach den Felddefinitionen des Zielbereichs,
 * checkIn, Cache, Volltext, Thumbnails - alles wie in der Eingabemaske. Werte aus
 * dem XML liegen im Datenbankformat vor (storedFormatFields), Passwörter als Hash
 * (prehashedFields), verschlüsselte Inhalte werden entschlüsselt übergeben und von
 * save() neu verschlüsselt. Dateien kommen aus dem Importverzeichnis
 * ($data[feld]['importfile']). Bereiche, Felder und Templates gehen durch
 * Area::save(), Field::save(), Template::save() - mit allen Hooks (Gegenfelder,
 * fieldname, rel_field_area, Template-Kompilierung).
 *
 * EINFÜGEPOSITION
 * --------------------
 * In Bereichen mit Baum (Eingabefeld `parent_id`) oder Handsortierung (Eingabefeld
 * `position`) landen neue Datensätze in der Reihenfolge der Datei hinter dem
 * Datensatz previous_id des Auftrags - einmal gewählt, danach je hinter dem zuletzt
 * eingefügten (insertAfter). Ohne Angabe hinter dem letzten vorhandenen: ohne
 * previous_id setzte createInTree() jeden neuen Datensatz an den ANFANG seiner
 * Ebene, die Datei käme rückwärts an. Nennt die Datei einen Vaterdatensatz
 * (XML, CSV-Spalte parent_id), wird der Datensatz im zweiten Durchlauf dorthin
 * gehängt - ans Ende der dortigen Geschwister, ebenfalls in Dateireihenfolge.
 * Der Export sortiert nach `lft` bzw. `position` (RecordExport::exportSort()),
 * die Reihenfolge überlebt also den Weg durch die Datei.
 *
 * EINGEBETTETE INHALTE (Felder vom Typ link; CSV und DOCX)
 * --------------------
 * Die Zieldatensätze eines link-Feldes gehören dem Datensatz (LinkTrait) und werden
 * mit ihm als Einheit importiert - wie in der Eingabemaske: im ersten Durchlauf
 * werden sie an der Arbeitskopie des Datensatzes ausgecheckt bzw. neu angelegt
 * (_importLinks()), ihre Werte geprüft in den Arbeitsstand geschrieben, und
 * LinkTrait::checkInDependents() checkt sie in derselben Transaktion wie den
 * Datensatz ein (verwaiste löscht der Check-in). Scheitert ein Inhalt, scheitert
 * der Datensatz. Der zweite Durchlauf lässt die link-Felder dann aus.
 *
 *     csv   Spalten "{link}.record_id", "{link}.area_id", "{link}.{feld}[.{code}]"
 *           (csvTargets(); RecordExport schreibt sie) - je Inhalt ein Wert, mit
 *           " | " getrennt (_csvLinks()). Die Liste ist verbindlich: vorhandene
 *           Inhalte werden über ihre Id bzw. Stelle zugeordnet und geändert, neue
 *           angelegt, nicht mehr genannte entfernt, die Reihenfolge übernommen.
 *           Thumbnails entstehen neu. Ohne Spalten zum link-Feld bleibt es, wie es
 *           ist - bei replace werden seine Inhalte entfernt.
 *     docx  Zeilen "field={link}.{feld} | record_id={id}": nur die genannten
 *           Inhalte werden übersetzt (partial).
 *
 * Die XML-Importe verknüpfen link-Felder weiter im zweiten Durchlauf über die
 * zugeordneten Ids (die Inhalte stehen dort als eigene Datensätze in der Datei).
 *
 * KONVERTER (Thumbnail-Felder)
 * --------------------
 * Ein Thumbnail-Feld verweist über `rel_field_converter` (sortiert) auf Konverter -
 * in der Datei je Feld <converter_ids>, ältere Dateien führen sie in
 * details.converter_ids. Die Ids der Datei bedeuten in einer fremden Datenbank
 * nichts, deshalb führt der Export die
 * Konverter der Thumbnail-Felder mit (<converters>, je Konverter der Hash seiner
 * Einstellungen - Converter::settingsHash()). Der Import ordnet jeden Konverter der
 * Datei zu (converterPlan()):
 *
 *     aus derselben Datenbank   die converter_id, wenn es sie noch gibt
 *     sonst                     ein vorhandener Konverter mit demselben Hash
 *     kein Treffer              der Konverter wird neu angelegt - wenn sein Prozessor
 *                               installiert ist; sonst wird er ignoriert
 *
 * Die globale Maske zeigt das Ergebnis und lässt den Redakteur je Konverter
 * entscheiden (Job 'converters'): neu anlegen, einen vorhandenen Konverter wählen
 * oder ignorieren. _importConverters() führt das vor den Feldern aus und füllt
 * $this->map['converter']; _saveField() setzt die zugeordneten Ids ein. Bleibt für
 * ein Thumbnail-Feld kein Konverter übrig, wird das Feld übergangen (ein Thumbnail
 * ohne Konverter gibt es nicht - Field::hookOnSave()). Dateien ohne <converters>
 * (ältere Exporte): aus derselben Datenbank gelten die Ids, sonst fällt jeder
 * Konverter weg.
 *
 * CUSTOM-SNIPPETS UND SLUG-PREFIX (XML)
 * --------------------
 * Beschriftungen von Bereichen und Feldern verweisen oft auf das Snippet-Set ihrer
 * Instanz (#custom.{instance_id}.{key}, Modul Snippets). Der Export führt die Texte
 * dieser Snippets mit (<snippets>); der Import legt fehlende Schlüssel im Set der
 * ZIELinstanz an (Snippets::merge() - Vorhandenes bleibt) und schreibt die Verweise
 * auf deren Präfix um (_mapSnippetRefs(), _importSnippets()). Ebenso die Snippets, die
 * importierte Templates ansprechen (custom.{key}, _queueTemplateSnippets()). Aus derselben
 * Datenbank dient das Set der Quellinstanz als Ersatz, wenn die Datei (ältere
 * Exporte) keine <snippets> führt.
 * Der Slug-Prefix eines Bereichs (details.slug_prefix, je language_id) wird über die
 * Sprachkürzel des Headers auf die Sprachen der Zielinstanz umgesetzt; ist er dort
 * nicht zulässig (Area::slugPrefixErrors(), z. B. schon vergeben), entfällt er mit
 * einem Hinweis im Protokoll.
 *
 * ANALYSE (analyze())
 * --------------------
 * Vor dem Import zeigt die Maske, was der Auftrag tun würde - ohne etwas zu
 * schreiben: je Bereich der Datei, ob er angelegt oder geändert wird, welche Felder
 * hinzukommen, geändert oder entfernt werden, wie viele Templates und Datensätze
 * hinzugefügt, geändert, ersetzt oder übergangen würden ("erweitert": jeder Datensatz
 * einzeln). Die Eingabeprüfung der Feldwerte läuft erst beim Import - ob ein
 * Datensatz tatsächlich durchgeht, steht vorher nicht fest.
 *
 * @see Camindo\Core\Modules\Record\RecordBackendImport  die Masken
 * @see Camindo\Core\Modules\Record\RecordExport         das Gegenstück
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Backend;
use Camindo\Core\BackgroundProcess;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Area\Field;
use Camindo\Core\Modules\Converter\Converter;
use Camindo\Core\Modules\Messaging\Message;
use Camindo\Core\Modules\Snippets\Snippets;
use Camindo\Core\Modules\Template\Template;

class RecordImport {

    public const TYPES = ['xml', 'csv', 'docx'];
    public const MODES = ['insert', 'update_or_insert', 'update', 'replace_or_insert', 'replace'];
    /** Modi der globalen Maske (je Bereich): dazu 'ignore' */
    public const GLOBAL_MODES = ['insert', 'update_or_insert', 'update', 'replace_or_insert', 'replace', 'ignore'];

    /** Typkennung des Hintergrundprozesses (Tabelle `bgproc`) */
    public const BGPROC_TYPE = 'import';

    /** Präfix der Importverzeichnisse in var/tmp */
    public const DIR_PREFIX = 'import-';

    /** Meta-Spalten von `record`, die eine Importdatei setzen darf */
    public const META_FIELDS = ['status_id', 'online_at', 'offline_at', 'template_id', 'parent_id', 'needs_review', 'user_id'];

    /**
     * Herkunftsspalten: Admin-Ids, die nur für NEUE Datensätze aus der Datei
     * übernommen werden (_restoreCreated()) - aus derselben Datenbank (XML) bzw.
     * aus CSV als angegebene Admin-Id; unbekannte Ids bleiben beim Session-Admin
     */
    public const ORIGIN_FIELDS = ['created_by', 'owner_id'];

    /** Bereichstypen mit Datensätzen */
    public const CONTENT_TYPES = ['record', 'profile', 'ugc'];

    /** Sprachen mit ideografischen Satzzeichen (Vorgabe der DOCX-Optionen) */
    private const IDEOGRAPHIC_LANGUAGES = ['zh', 'ja'];

    /** Spalten von `field`, die der Strukturimport übernimmt (ohne Schlüssel) */
    private const FIELD_COLUMNS = ['type', 'name', 'label', 'display_type', 'note', 'tooltip', 'height', 'min', 'min_op', 'max', 'max_op', 'pattern', 'prefix', 'suffix', 'position', 'custom_order', 'flags', 'default', 'visibility_input', 'visibility_search', 'visibility_list', 'default_field', 'meta', 'multilingual', 'details'];

    /** Zielschema des laufenden Imports je Bereich */
    private array $schemaMemo = [];

    /** Zuordnung Id der Datei => tatsächliche Id: 'area', 'field', 'template', 'record', 'converter' (0: ignoriert) */
    private array $map = ['area' => [], 'field' => [], 'template' => [], 'record' => [], 'converter' => []];

    /** zuletzt angelegter Bereich je Vater (Reihenfolge neuer Bereiche) */
    private array $lastSibling = [];
    /** Einfügeposition neuer Datensätze: area_id => parent_id => record_id des Vorgängers (EINFÜGEPOSITION) */
    private array $insertAfter = [];

    /** Templates der Datei: template_id => ['name', 'area_id' (Datei)] - Zuordnung nach Name, wenn sie nicht importiert werden */
    private array $fileTemplates = [];

    /** geschriebene Templates: template_id => Daten der Datei (für die Id-Ersetzungen im Code) */
    private array $savedTemplates = [];

    /**
     * Trigger-Templates der Bereiche: area_id => [template_id (Datei), ...] in ihrer
     * Reihenfolge - gesetzt erst
     * nach dem Import (_applyTriggerTemplates()): die Datensätze der Datei tragen
     * die berechneten Werte schon, und der Code der Templates ist erst nach
     * _rewriteTemplateCodes() lauffähig (CMS:REPLACE-Markierungen)
     */
    private array $pendingTriggers = [];

    /** Bereiche der Datei: area_id => ['name', 'type'] (Zuordnung der Herkunftsbereiche) */
    private array $fileAreas = [];

    /** Datei stammt aus dieser Datenbank (Job 'same_db', für die Konverter-Zuordnung in den Feldern) */
    private bool $sameDb = false;

    /** Custom-Snippets der Datei (<snippets>): instance_id der Datei => [schlüssel => [lang => text]] */
    private array $fileSnippets = [];

    /** Sprachen der Datei: language_id der Datei => Kürzel (Header, für details.slug_prefix) */
    private array $fileLanguages = [];

    /** zu übernehmende Custom-Snippets: Zielinstanz => [schlüssel => [lang => text]] (_mapSnippetRefs()) */
    private array $pendingSnippets = [];

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Session $session,
        private System $system,
        private InstanceCache $instanceCache,
        private Record $record,
        private Backend $backend,
        private BackgroundProcess $bgproc,
        private Message $message,
        private Area $area,
        private Field $field,
        private Template $template,
        private Converter $converter,
        private Snippets $snippets,
        private \Camindo\Core\AuditLog $auditLog,
        private RecordService $service,
    )
    {
    }

    // ====================
    // Modi

    /** Legt der Modus nicht vorhandene Datensätze an? */
    public static function modeCreates(string $mode): bool
    {
        return in_array($mode, ['insert', 'update_or_insert', 'replace_or_insert'], true);
    }

    /** Leert der Modus, was die Datei nicht nennt? */
    public static function modeReplaces(string $mode): bool
    {
        return str_starts_with($mode, 'replace');
    }

    // ====================
    // Schwellwert

    public function needsBackground(int $count): bool
    {
        $threshold = $this->backgroundThreshold();
        return $threshold > 0 && $count >= $threshold;
    }

    public function backgroundThreshold(): int
    {
        return (int)($this->config->get('admin.import_background_threshold') ?? 200);
    }

    // ====================
    // Schritt 1a: Datei bereitstellen

    /**
     * Importdatei nach var/tmp/import-{token}/ bringen und die Kandidaten suchen.
     *
     * @param string $source        Pfad der Datei (Upload-Tmpfile oder Datei in var/upload)
     * @param string $originalName  ursprünglicher Dateiname (Endung!)
     * @param bool   $isUpload      true: $source ist ein PHP-Upload (move_uploaded_file)
     * @return array  ['dir' => 'import-…', 'files' => ['export.xml', …]] oder ['error' => …]
     */
    public function prepare(string $source, string $originalName, bool $isUpload): array
    {
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['zip', 'xml', 'csv', 'docx'], true)) {
            return ['error' => $this->cms->snippet('record.error_import_file_type')];
        }
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $dirName = self::DIR_PREFIX . $this->crypt->randomKey(12);
        if (!$this->system->createDir($tmpDir, $dirName)) {
            return ['error' => 'var/tmp not writable'];
        }
        $dir = "$tmpDir/$dirName";
        $target = $dir . '/' . ($extension === 'zip' ? 'import.zip' : 'import.' . $extension);
        $ok = $isUpload ? @move_uploaded_file($source, $target) : @copy($source, $target);
        if (!$ok) {
            $this->removeDir($dirName);
            return ['error' => $this->cms->snippet('error_file_move_uploaded')];
        }
        if ($extension === 'zip') {
            $error = $this->_extractZip($target, $dir);
            @unlink($target);
            if ($error !== '') {
                $this->removeDir($dirName);
                return ['error' => $error];
            }
        }
        $files = $this->candidateFiles($dir);
        if (empty($files)) {
            $this->removeDir($dirName);
            return ['error' => $this->cms->snippet('record.error_import_no_file')];
        }
        return ['dir' => $dirName, 'files' => $files];
    }

    /**
     * Zip-Datei entpacken - Einträge mit Pfadsprüngen (Zip-Slip) werden abgelehnt.
     * @return string  '' oder Fehlermeldung
     */
    private function _extractZip(string $zipPath, string $dir): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return $this->cms->snippet('record.error_import_zip');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', $name);
            if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('!(^|/)\.\.(/|$)!', $normalized)) {
                $zip->close();
                return $this->cms->snippet('record.error_import_zip');
            }
        }
        $ok = $zip->extractTo($dir);
        $zip->close();
        return $ok ? '' : $this->cms->snippet('record.error_import_zip');
    }

    /**
     * Absoluter Pfad eines Importverzeichnisses, null bei ungültigem Namen oder
     * fehlendem Verzeichnis.
     */
    public function importDir(string $dirName): ?string
    {
        if (!preg_match('/^' . preg_quote(self::DIR_PREFIX, '/') . '[A-Za-z0-9_\-]+$/', $dirName)) return null;
        $dir = (string)$this->config->get('paths.tmp_dir') . '/' . $dirName;
        return is_dir($dir) ? $dir : null;
    }

    /**
     * Importverzeichnis samt Inhalt entfernen.
     */
    public function removeDir(string $dirName): void
    {
        $dir = $this->importDir($dirName);
        if ($dir === null) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir((string)$item->getPathname()) : @unlink((string)$item->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Importdateien (xml, csv, docx) im Verzeichnis, relative Pfade; das
     * media-Verzeichnis eines Exports bleibt außen vor.
     *
     * @return string[]
     */
    public function candidateFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) continue;
            $relative = substr((string)$item->getPathname(), strlen($dir) + 1);
            if (str_starts_with($relative, 'media/') || str_contains($relative, '/media/')) continue;
            if (str_starts_with(basename($relative), '.')) continue;
            if ($this->typeOf($relative) === '') continue;
            $files[] = $relative;
        }
        sort($files);
        return $files;
    }

    /**
     * Importer-Typ einer Datei anhand der Endung ('' = keine Importdatei).
     */
    public function typeOf(string $file): string
    {
        $extension = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        return in_array($extension, self::TYPES, true) ? $extension : '';
    }

    /**
     * Pfad einer Importdatei innerhalb des Verzeichnisses (gegen Pfadsprünge geprüft).
     */
    public function filePath(string $dirName, string $file): ?string
    {
        $dir = $this->importDir($dirName);
        if ($dir === null) return null;
        $real = realpath($dir . '/' . $file);
        if ($real === false || !is_file($real) || !str_starts_with($real, realpath($dir) . DIRECTORY_SEPARATOR)) return null;
        return $real;
    }

    /**
     * Importfähige Dateien in var/upload (Auswahl statt Upload), relative Pfade.
     *
     * @return string[]
     */
    public function uploadDirFiles(): array
    {
        $dir = (string)$this->config->get('paths.upload_dir');
        if (!is_dir($dir)) return [];
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) continue;
            $extension = strtolower((string)$item->getExtension());
            if (!in_array($extension, ['zip', 'xml', 'csv', 'docx'], true)) continue;
            $files[] = substr((string)$item->getPathname(), strlen($dir) + 1);
        }
        sort($files);
        return $files;
    }

    // ====================
    // Schritt 1b: Analyse

    /**
     * XML: Header lesen, Bereiche, Datensätze und Templates zählen.
     *
     * @return array [
     *     'ok'        => bool      Datei ist eine camindo-Exportdatei
     *     'errors'    => string[]  harte Fehler (kein Import möglich)
     *     'warnings'  => string[]  Version / Datenbank / Format
     *     'header'    => array     database, cms_version, admin, exported_at, area, languages (id => code), structure, content
     *     'count'     => int       Datensätze
     *     'templates' => int       Templates
     *     'areas'     => array     Bereiche MIT Datensätzen: area_id (Herkunft) => ['name', 'label', 'count', 'target_area_id']
     *     'structure' => array     alle Bereiche der Datei (Format 2, Baumreihenfolge): area_id => [
     *                              'name', 'label', 'type', 'parent_id', 'fields', 'records', 'templates',
     *                              'target_by_name', 'target_by_id' (0 = nicht vorhanden)]
     * ]
     */
    public function analyzeXml(string $path, int $areaId): array
    {
        $result = ['ok' => false, 'errors' => [], 'warnings' => [], 'header' => [], 'count' => 0, 'templates' => 0, 'areas' => [], 'structure' => [], 'converters' => []];
        $reader = new \XMLReader();
        if (!@$reader->open($path)) {
            $result['errors'][] = $this->cms->snippet('record.error_import_not_camindo');
            return $result;
        }
        $header = null;
        $areas = [];
        $structure = [];
        $templates = [];
        $converters = [];
        $count = 0;
        $rootOk = false;
        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) continue;
                if ($reader->depth === 0) {
                    if ($reader->name !== 'camindo-export') break;
                    $rootOk = true;
                    $format = (int)$reader->getAttribute('format');
                    if ($format !== RecordExport::XML_FORMAT) {
                        $result['warnings'][] = str_replace('{format}', (string)$format, $this->cms->snippet('record.warning_import_format'));
                    }
                    continue;
                }
                if ($reader->depth === 1 && $reader->name === 'header') {
                    $header = $this->_xmlHeader($this->_readerNode($reader));
                    $reader->next();
                    continue;
                }
                if ($reader->depth === 2 && $reader->name === 'area') {
                    $node = $this->_readerNode($reader);
                    $fileAreaId = (int)$node['area_id'];
                    $structure[$fileAreaId] = [
                        'name' => (string)$node['name'],
                        'label' => trim((string)$node->label),
                        'type' => (string)$node['type'],
                        'parent_id' => (int)$node['parent_id'],
                        'fields' => count($node->fields->field ?? []),
                        'records' => 0,
                        'templates' => 0,
                    ];
                    $reader->next();
                    continue;
                }
                if ($reader->depth === 2 && $reader->name === 'record') {
                    $count++;
                    $recordAreaId = (int)$reader->getAttribute('area_id');
                    $areas[$recordAreaId] = ($areas[$recordAreaId] ?? 0) + 1;
                    $reader->next();
                    continue;
                }
                if ($reader->depth === 2 && $reader->name === 'template') {
                    $templateAreaId = (int)$reader->getAttribute('area_id');
                    $templates[$templateAreaId] = ($templates[$templateAreaId] ?? 0) + 1;
                    $reader->next();
                    continue;
                }
                if ($reader->depth === 2 && $reader->name === 'converter') {
                    $converter = $this->_xmlConverter($this->_readerNode($reader));
                    $converters[(int)$converter['converter_id']] = $converter;
                    $reader->next();
                    continue;
                }
            }
        }
        catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
        }
        $reader->close();

        if (!$rootOk || $header === null) {
            $result['errors'][] = $this->cms->snippet('record.error_import_not_camindo');
            return $result;
        }
        // Format 1: der eine Bereich steht nur im Header
        if (empty($structure) && (int)($header['area']['area_id'] ?? 0) > 0) {
            $structure[(int)$header['area']['area_id']] = [
                'name' => (string)$header['area']['name'], 'label' => (string)$header['area']['label'],
                'type' => (string)($header['area']['type'] ?: 'record'), 'parent_id' => 0, 'fields' => 0, 'records' => 0, 'templates' => 0,
            ];
        }
        $result['header'] = $header;
        $result['count'] = $count;
        $result['templates'] = array_sum($templates);
        if ($count < 1 && empty($structure)) $result['errors'][] = $this->cms->snippet('record.error_import_empty');

        // Herkunft
        $ownDb = (string)($this->config->get('db.name') ?? '');
        if ($header['database'] !== '' && $ownDb !== '' && $header['database'] !== $ownDb) {
            $result['warnings'][] = str_replace('{database}', $header['database'], $this->cms->snippet('record.warning_import_database'));
        }
        $ownVersion = defined('CAMINDO_VERSION') ? CAMINDO_VERSION : '';
        if ($header['cms_version'] !== '' && $ownVersion !== '' && version_compare($this->_versionNumber($header['cms_version']), $this->_versionNumber($ownVersion), '<')) {
            $result['warnings'][] = str_replace('{version}', $header['cms_version'], $this->cms->snippet('record.warning_import_version'));
        }
        if (empty($header['languages'])) {
            $result['warnings'][] = $this->cms->snippet('record.warning_import_no_languages');
        }

        // Struktur: je Bereich der Datei die Treffer in der Zieldatenbank
        foreach ($structure as $fileAreaId => &$info) {
            $info['records'] = (int)($areas[$fileAreaId] ?? 0);
            $info['templates'] = (int)($templates[$fileAreaId] ?? 0);
            $info['target_by_id'] = $this->_resolveArea($fileAreaId, '', (string)$info['type']);
            $info['target_by_name'] = $this->_resolveArea(0, (string)$info['name'], (string)$info['type']);
        }
        unset($info);
        $result['structure'] = $structure;

        // Konverter der Thumbnail-Felder: Treffer in der Zieldatenbank (KONVERTER)
        $sameDb = $header['database'] !== '' && $header['database'] === $ownDb;
        $result['converters'] = $this->converterMatches($converters, $sameDb);

        // Bereiche mit Datensätzen: Herkunft -> Ziel (gleiche Id, sonst gleicher Name)
        foreach ($areas as $originAreaId => $areaCount) {
            $info = $structure[$originAreaId] ?? ['name' => '', 'label' => ''];
            $target = $this->_resolveArea($originAreaId, (string)($info['name'] ?? ''));
            $result['areas'][$originAreaId] = [
                'name' => (string)($info['name'] ?? ''),
                'label' => (string)($info['label'] ?? ''),
                'count' => $areaCount,
                'target_area_id' => $target,
            ];
        }
        $result['ok'] = empty($result['errors']);
        return $result;
    }

    private function _xmlHeader(\SimpleXMLElement $node): array
    {
        $languages = [];
        $primary = 0;
        $languageInstances = [];
        foreach ($node->languages->language ?? [] as $language) {
            $languageId = (int)$language['language_id'];
            $languages[$languageId] = (string)$language['code'];
            if ((string)$language['primary'] === '1') {
                $primary = $languageId;
                if (isset($language['instance_id'])) $languageInstances[(int)$language['instance_id']] = $languageId;
            }
        }
        $fields = [];
        foreach ($node->fields->field ?? [] as $field) {
            $fields[(string)$field['name']] = ['type' => (string)$field['type'], 'multilingual' => (int)$field['multilingual']];
        }
        return [
            'database' => trim((string)($node->database ?? '')),
            'cms_version' => trim((string)($node->{'cms-version'} ?? '')),
            'admin' => trim((string)($node->admin ?? '')),
            'exported_at' => trim((string)($node->{'exported-at'} ?? '')),
            'structure' => (int)($node->structure ?? 0),
            'content' => (int)($node->content ?? 1),
            'area' => [
                'area_id' => (int)($node->area['area_id'] ?? 0),
                'instance_id' => (int)($node->area['instance_id'] ?? 0),
                'name' => (string)($node->area['name'] ?? ''),
                'type' => (string)($node->area['type'] ?? ''),
                'label' => trim((string)($node->area ?? '')),
            ],
            'languages' => $languages,
            'primary_language_id' => $primary,
            // Hauptsprache je Instanz der Datei (Format 2)
            'primary_by_instance' => $languageInstances,
            'fields' => $fields,
            'files' => (int)($node->files ?? 0),
        ];
    }

    /**
     * Das aktuelle Element des XMLReaders als SimpleXMLElement (expand() liefert
     * einen Knoten ohne Dokument - erst in ein DOMDocument übernommen lässt er
     * sich in SimpleXML wandeln).
     */
    private function _readerNode(\XMLReader $reader): \SimpleXMLElement
    {
        $doc = new \DOMDocument();
        $node = $doc->importNode($reader->expand(), true);
        $doc->appendChild($node);
        $element = simplexml_import_dom($node);
        if ($element === null) throw new \RuntimeException('xml node not readable');
        return $element;
    }

    private function _versionNumber(string $version): string
    {
        return preg_match('/\d+(\.\d+)*/', $version, $m) ? $m[0] : '0';
    }

    /**
     * Zielbereich zu einem Herkunftsbereich: gleiche Id (nicht gelöscht), sonst
     * gleicher Name, sonst 0. $type schränkt auf einen Bereichstyp ein ('' = die
     * Inhaltsbereiche).
     */
    private function _resolveArea(int $originAreaId, string $name, string $type = ''): int
    {
        $types = $type !== '' ? [$type] : self::CONTENT_TYPES;
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $this->db->pushResult();
        $target = 0;
        if ($originAreaId > 0) {
            $this->db->query("SELECT `area_id` FROM `area` WHERE `area_id` = ? AND `deleted` = 0 AND `type` IN ($placeholders)", 'i' . str_repeat('s', count($types)), array_merge([$originAreaId], $types));
            $target = (int)($this->db->fetchNum()[0] ?? 0);
        }
        if ($target < 1 && $name !== '') {
            $this->db->query("SELECT `area_id` FROM `area` WHERE `name` = ? AND `deleted` = 0 AND `type` IN ($placeholders) ORDER BY `area_id` ASC LIMIT 1", 's' . str_repeat('s', count($types)), array_merge([$name], $types));
            $target = (int)($this->db->fetchNum()[0] ?? 0);
        }
        $this->db->popResult();
        return $target;
    }

    /**
     * CSV: Spalten der ersten Zeile, Zeilenzahl, Vorschlag für die Zuordnung.
     *
     * @param array $settings  separator, enclosure, eol
     * @return array [
     *     'columns'  => string[]   Werte der ersten Zeile
     *     'rows'     => int        Zeilen insgesamt (inkl. Kopfzeile)
     *     'header'   => bool       erste Zeile enthält bekannte Feldnamen
     *     'suggest'  => array      Spaltenindex => Zielschlüssel ('' = ignorieren)
     *     'stored'   => bool       Spaltennamen sehen nach dem CSV-Export aus (Datenbankformat)
     *     'example'  => string[]   zweite Zeile (Beispielwerte für die Zuordnung)
     *     'errors'   => string[]
     * ]
     */
    public function analyzeCsv(string $path, int $areaId, array $settings): array
    {
        $result = ['columns' => [], 'rows' => 0, 'header' => false, 'suggest' => [], 'stored' => false, 'example' => [], 'errors' => []];
        $rows = $this->_csvRows($path, $settings);
        $result['rows'] = count($rows);
        if (empty($rows)) {
            $result['errors'][] = $this->cms->snippet('record.error_import_empty');
            return $result;
        }
        $columns = $rows[0];
        $result['columns'] = $columns;
        $result['example'] = $rows[1] ?? [];
        $targets = $this->csvTargets($areaId);
        $known = 0;
        $columnNames = array_map(fn($column) => trim((string)$column), $columns);
        foreach ($columns as $index => $column) {
            $key = $this->_csvSuggest(trim((string)$column), $targets, $areaId, $columnNames);
            $result['suggest'][$index] = $key;
            if ($key !== '') $known++;
        }
        $result['header'] = $known > 0 && $known >= (int)ceil(count($columns) / 2);
        $result['stored'] = $result['header'];
        if (!$result['header']) $result['suggest'] = array_fill_keys(array_keys($columns), '');
        return $result;
    }

    /**
     * Zielschlüssel zu einem Spaltennamen (Format des CSV-Exports):
     * "{name}", "{name}.{code}", "{name}.record_id"; Unterfelder von Datei-Feldern
     * und Thumbnails werden ignoriert.
     *
     * Verknüpfungsfeld: "{name}.record_id" hat Vorrang; fehlt diese Spalte in der
     * Datei, gilt auch die Spalte "{name}" als Liste der Ids.
     *
     * @param string[] $columnNames  alle Spaltennamen der Datei
     */
    private function _csvSuggest(string $column, array $targets, int $areaId, array $columnNames = []): string
    {
        if ($column === '') return '';
        if (isset($targets[$column])) return $column;
        if (isset($targets["$column.record_id"]) && !in_array("$column.record_id", $columnNames, true)) {
            $type = (string)($this->_schemaOf($areaId)[$column]['type'] ?? '');
            if (in_array($type, $this->cms->relationTypes, true) && !in_array($type, $this->cms->dependentTypes, true)) {
                return "$column.record_id";
            }
        }
        // "{name}.{code}" mit Sprachkürzel, "{name}.record_id" bzw. "{name}.{sub}.{code}"
        $parts = explode('.', $column);
        while (count($parts) > 1) {
            array_pop($parts);
            $candidate = implode('.', $parts);
            if (isset($targets[$candidate])) {
                // Sprachzusatz passt? (Zielschlüssel "{candidate}.{code}")
                $code = substr($column, strlen($candidate) + 1);
                if (isset($targets["$candidate.$code"])) return "$candidate.$code";
                // Datei-Unterfelder (bild.width) / Ziel-Felder einer Verknüpfung: nicht importierbar
                $schema = $this->_schemaOf($areaId);
                $type = (string)($schema[$parts[0]]['type'] ?? '');
                if ($type === 'file' || in_array($type, $this->cms->relationTypes, true)) return '';
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Zielschlüssel des CSV-Imports: Schlüssel => Beschriftung.
     *
     *     {name}            Feld (mehrsprachig: Hauptsprache), Meta-Felder, record_id
     *     {name}.{code}     mehrsprachiges Feld in dieser Sprache
     *     {name}.record_id  Verknüpfung: Ids der Zieldatensätze (mit " | " getrennt)
     *     {name}            Datei-Feld: Pfad der Datei in der Zip-Datei (media/…)
     *     {link}.area_id, {link}.{feld}[.{code}]
     *                       Feld vom Typ link: Bereich und Felder der eingebetteten
     *                       Inhalte (EINGEBETTETE INHALTE)
     *
     * Thumbnails und Volltext sind nicht dabei.
     */
    public function csvTargets(int $areaId): array
    {
        $targets = [];
        $areaData = $this->_areaOf($areaId);
        $instanceId = (int)($areaData['instance_id'] ?? 0);
        $languages = $this->instanceCache->languages($instanceId);
        $primaryId = $this->instanceCache->primaryLanguageId($instanceId);
        $targets['record_id'] = 'record_id';
        foreach ($this->_schemaOf($areaId) as $name => $colDef) {
            $type = (string)($colDef['type'] ?? '');
            if ($type === 'fulltext') continue;
            if ($type === 'file' && !empty($colDef['parent_field_id'])) continue;
            $label = $this->cms->textOrSnippet((string)($colDef['label'] ?? $name));
            if (in_array($type, $this->cms->dependentTypes, true)) {
                // eingebettete Inhalte (EINGEBETTETE INHALTE): Ids, Bereich und die
                // Felder der Zielbereiche - ohne Thumbnails (sie entstehen neu)
                $targets["$name.record_id"] = "$label.record_id ($name)";
                $targets["$name.area_id"] = "$label.area_id ($name)";
                foreach ($this->record->linkTargetFields($colDef) as $sub => $targetDef) {
                    if ((string)($targetDef['type'] ?? '') === 'file' && !empty($targetDef['parent_field_id'])) continue;
                    $subLabel = $label . '.' . $this->cms->textOrSnippet((string)($targetDef['label'] ?? $sub));
                    if ($this->cms->isMultilingualField($targetDef) && count($languages) > 1) {
                        $targets["$name.$sub"] = $subLabel . " ($name.$sub, " . (string)($languages[$primaryId]['code'] ?? $primaryId) . ')';
                        foreach ($languages as $languageId => $language) {
                            $code = (string)($language['code'] ?? $languageId);
                            $targets["$name.$sub.$code"] = $subLabel . " ($name.$sub, $code)";
                        }
                        continue;
                    }
                    $targets["$name.$sub"] = "$subLabel ($name.$sub)";
                }
                continue;
            }
            if (in_array($type, $this->cms->relationTypes, true)) {
                $targets["$name.record_id"] = "$label.record_id ($name)";
                continue;
            }
            if ($this->cms->isMultilingualField($colDef) && count($languages) > 1) {
                $targets[$name] = $label . ' (' . $name . ', ' . (string)($languages[$primaryId]['code'] ?? $primaryId) . ')';
                foreach ($languages as $languageId => $language) {
                    $code = (string)($language['code'] ?? $languageId);
                    $targets["$name.$code"] = $label . " ($name, $code)";
                }
                continue;
            }
            $targets[$name] = "$label ($name)";
        }
        foreach (array_merge(self::META_FIELDS, self::ORIGIN_FIELDS) as $name) {
            if (isset($targets[$name])) continue;
            if ($name === 'parent_id' && !isset($this->_schemaOf($areaId)['parent_id'])) continue;
            $targets[$name] = $this->cms->textOrSnippet((string)($this->cms->schema[$name]['label'] ?? $name)) . " ($name)";
        }
        return $targets;
    }

    /**
     * DOCX: Tabellen lesen, Datensätze zählen, Zielsprache prüfen.
     *
     * @return array ['ok', 'errors', 'records' => int, 'language_id' => int,
     *                'source_language_id' => int, 'fields' => string[]]
     */
    public function analyzeDocx(string $path, int $areaId): array
    {
        $result = ['ok' => false, 'errors' => [], 'warnings' => [], 'records' => 0, 'language_id' => 0, 'source_language_id' => 0, 'fields' => []];
        $records = $this->_docxRecords($path, $error);
        if ($records === null) {
            $result['errors'][] = $error !== '' ? $error : $this->cms->snippet('record.error_import_not_docx');
            return $result;
        }
        if (empty($records)) {
            $result['errors'][] = $this->cms->snippet('record.error_import_not_docx');
            return $result;
        }
        $result['records'] = count($records);
        $languageIds = [];
        $fields = [];
        foreach ($records as $entry) {
            $languageIds[(int)$entry['language_id']] = true;
            $result['source_language_id'] = (int)$entry['source_language_id'];
            foreach (array_keys($entry['fields']) as $name) $fields[$name] = true;
            foreach ((array)($entry['links'] ?? []) as $linkName => $dests) {
                foreach ($dests as $subs) {
                    foreach (array_keys($subs) as $sub) $fields["$linkName.$sub"] = true;
                }
            }
        }
        $result['fields'] = array_keys($fields);
        if (count($languageIds) !== 1) {
            $result['errors'][] = $this->cms->snippet('record.error_import_docx_languages');
            return $result;
        }
        $languageId = (int)array_key_first($languageIds);
        $result['language_id'] = $languageId;
        $instanceId = (int)($this->_areaOf($areaId)['instance_id'] ?? 0);
        if (!isset($this->instanceCache->languages($instanceId)[$languageId])) {
            $result['errors'][] = str_replace('{language_id}', (string)$languageId, $this->cms->snippet('record.error_import_docx_language'));
            return $result;
        }
        $schema = $this->_schemaOf($areaId);
        foreach ($result['fields'] as $name) {
            $colDef = $schema[$name] ?? null;
            if (str_contains($name, '.')) {
                // Feld eines eingebetteten Inhalts: {link}.{feld}
                [$linkName, $sub] = explode('.', $name, 2);
                $linkDef = $schema[$linkName] ?? null;
                $colDef = ($linkDef !== null && in_array((string)($linkDef['type'] ?? ''), $this->cms->dependentTypes, true))
                    ? ($this->record->linkTargetFields($linkDef)[$sub] ?? null) : null;
            }
            if ($colDef === null || !$this->cms->isMultilingualField($colDef)) {
                $result['warnings'][] = str_replace('{field}', $name, $this->cms->snippet('record.warning_import_docx_field'));
            }
        }
        $result['ok'] = true;
        return $result;
    }

    /**
     * Ist die Zielsprache eine mit ideografischen Satzzeichen (Vorgabe der Optionen)?
     */
    public function isIdeographicLanguage(int $languageId, int $instanceId): bool
    {
        $code = strtolower((string)($this->instanceCache->languages($instanceId)[$languageId]['code'] ?? ''));
        foreach (self::IDEOGRAPHIC_LANGUAGES as $prefix) {
            if (str_starts_with($code, $prefix)) return true;
        }
        return false;
    }

    // ====================
    // Schritt 2: Ausführen

    /**
     * Auftrag ausführen - Vordergrund wie Hintergrund.
     *
     * @param array         $job       siehe Klassenkommentar
     * @param callable|null $progress  fn(int $done, int $total)
     * @return array [
     *     'total' => int, 'done' => int, 'failed' => int, 'skipped' => int,
     *     'inserted' => int, 'updated' => int, 'replaced' => int,
     *     'items' => [['kind' => area|field|template|record, 'id' => int, 'label' => string,
     *                  'status' => string, 'messages' => string[]], …]
     *     'error' => string  (nur wenn gar nichts lief)
     * ]
     */
    public function run(array $job, ?callable $progress = null): array
    {
        // Audit-Log: Änderungen dieses Laufs als 'import' kennzeichnen (gilt für den Rest
        // des Prozesses - ein Lauf ist immer das Letzte, was ein Request tut)
        $this->auditLog->setContext('import');
        $result = ['total' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0, 'inserted' => 0, 'updated' => 0, 'replaced' => 0, 'items' => []];
        $type = (string)($job['type'] ?? '');
        $path = $this->filePath((string)($job['dir'] ?? ''), (string)($job['file'] ?? ''));
        if (!in_array($type, self::TYPES, true) || $path === null) {
            $result['error'] = $this->cms->snippet('record.error_import_no_file');
            return $result;
        }
        $mode = (string)($job['mode'] ?? 'insert');
        if (!in_array($mode, self::MODES, true)) $mode = 'insert';
        if ($type === 'docx') $mode = 'update';
        $job['mode'] = $mode;
        $job['dir_path'] = dirname($path);
        $this->map = ['area' => [], 'field' => [], 'template' => [], 'record' => [], 'converter' => []];
        $this->lastSibling = [];
        $this->insertAfter = [];
        $this->fileTemplates = [];
        $this->savedTemplates = [];
        $this->pendingTriggers = [];
        $this->fileAreas = [];
        $this->schemaMemo = [];
        $job['same_db'] = false;
        if ($type === 'xml') {
            $job['same_db'] = $this->isSameDatabase($path);
        }
        $this->sameDb = !empty($job['same_db']);

        // Struktur und Templates (nur XML)
        // --------------------
        if ($type === 'xml') {
            $job['structure_result'] = $this->_importStructure($path, $job, $result);
            if ($progress !== null) $progress(0, max(1, (int)($job['count'] ?? 0)));
        }

        // Datensätze
        // --------------------
        $skip = max(0, (int)($job['skip'] ?? 0));
        $limit = max(0, (int)($job['limit'] ?? 0));
        $entries = match ($type) {
            'xml'  => $this->_xmlEntries($path, $job),
            'csv'  => $this->_csvEntries($path, $job),
            'docx' => $this->_docxEntries($path, $job),
        };
        $total = max(0, (int)($job['count'] ?? 0));
        $prepared = [];
        $index = 0;
        $imported = 0;
        foreach ($entries as $entry) {
            $index++;
            if ($index <= $skip) {
                $result['skipped']++;
                continue;
            }
            if ($limit > 0 && $imported >= $limit) break;
            $imported++;
            $this->cms->errorsReset();
            $item = ['kind' => 'record', 'id' => (int)($entry['id'] ?? 0), 'label' => (string)($entry['label'] ?? ''), 'status' => 'failed', 'messages' => []];
            try {
                if (!empty($entry['error'])) {
                    throw new \RuntimeException((string)$entry['error']);
                }
                $item = $this->_importPrepare($entry, $job, $item);
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $item['status'] = 'failed';
            }
            if ($item['status'] === 'failed') {
                $item['messages'] = array_merge($item['messages'], $this->_errorTexts());
                $this->_addItem($result, $item);
            }
            elseif (!empty($item['prepared'])) {
                $prepared[] = $item;
            }
            else {
                $this->_addItem($result, $item);
            }
            if ($progress !== null) $progress($index, max($total, $index));
        }

        // Zweiter Durchlauf: Verknüpfungen und Bezüge mit den zugeordneten Ids
        // --------------------
        foreach ($prepared as $item) {
            $this->cms->errorsReset();
            try {
                $item = $this->_importFinish($item, $job);
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $item['status'] = 'failed';
            }
            if ($item['status'] === 'failed') $item['messages'] = array_merge($item['messages'], $this->_errorTexts());
            $this->_addItem($result, $item);
        }

        // Experten-Funktion: Ids im Template-Code auf die zugeordneten umschreiben -
        // erst jetzt stehen auch die Datensatz-Ids fest
        // --------------------
        if ($type === 'xml' && !empty($this->savedTemplates)) {
            $this->_rewriteTemplateCodes($result);
        }
        // Trigger-Templates erst jetzt (siehe $pendingTriggers)
        // --------------------
        $this->_applyTriggerTemplates($result);
        $this->cms->errorsReset();
        if (empty($result['items'])) {
            $result['error'] = $this->cms->snippet('record.error_import_empty');
        }
        return $result;
    }

    /**
     * Analyse eines Auftrags ohne zu schreiben (siehe Klassenkommentar). $job wie
     * bei run(). Struktur und Templates werden wie in _importStructure() zugeordnet,
     * die Datensätze wie in _importPrepare() - nur die Entscheidung, nicht die Tat.
     *
     * @param bool $extended  jeden Datensatz einzeln aufführen ('items')
     * @return array [
     *     'areas' => [Schlüssel (global: area_id der Datei, sonst area_id) => [
     *         'label', 'name', 'type', 'target_id' (0 = wird angelegt),
     *         'action'    => inserted | updated | replaced | ignore | skipped (Struktur),
     *         'fields'    => ['added' => [Namen], 'updated' => [], 'removed' => [], 'failed' => []],
     *         'templates' => ['inserted' => n, 'updated' => n, 'replaced' => n, 'skipped' => n, 'failed' => n],
     *         'records'   => dito,
     *         'items'     => [['id' => Datei-Id, 'record_id' => Ziel-Id, 'label', 'status', 'messages' => []], …] ]],
     *     'skipped' => n (Option "Überspringen"), 'total' => n (geprüfte Datensätze),
     *     'error' => string (nur wenn die Datei nicht lesbar ist)
     * ]
     */
    public function analyze(array $job, bool $extended = false): array
    {
        $analysis = ['areas' => [], 'skipped' => 0, 'total' => 0];
        $type = (string)($job['type'] ?? '');
        $path = $this->filePath((string)($job['dir'] ?? ''), (string)($job['file'] ?? ''));
        if (!in_array($type, self::TYPES, true) || $path === null) {
            $analysis['error'] = $this->cms->snippet('record.error_import_no_file');
            return $analysis;
        }
        $mode = (string)($job['mode'] ?? 'insert');
        if (!in_array($mode, self::MODES, true)) $mode = 'insert';
        if ($type === 'docx') $mode = 'update';
        $job['mode'] = $mode;
        $job['dir_path'] = dirname($path);
        $this->map = ['area' => [], 'field' => [], 'template' => [], 'record' => [], 'converter' => []];
        $this->fileTemplates = [];
        $this->savedTemplates = [];
        $this->pendingTriggers = [];
        $this->fileAreas = [];
        $this->schemaMemo = [];
        $job['same_db'] = $type === 'xml' && $this->isSameDatabase($path);
        $this->sameDb = !empty($job['same_db']);
        $isGlobal = !empty($job['global']) && $type === 'xml';
        $counts = fn() => ['inserted' => 0, 'updated' => 0, 'replaced' => 0, 'skipped' => 0, 'failed' => 0];
        $areaEntry = fn(string $label, string $name, string $type, int $targetId, string $action) => [
            'label' => $label, 'name' => $name, 'type' => $type, 'target_id' => $targetId, 'action' => $action,
            'fields' => ['added' => [], 'updated' => [], 'removed' => [], 'failed' => [], 'skipped' => []],
            'templates' => $counts(), 'records' => $counts(), 'items' => [],
        ];
        $analysis['converters'] = []; // converter_id der Datei => Plan (KONVERTER), nur die gebrauchten
        $newAreas = []; // Datei-area_id => Modus der Datensätze: Bereich entsteht erst beim Import

        // Struktur und Templates (global)
        // --------------------
        if ($isGlobal) {
            [$areas, $templates, $converters] = $this->_xmlStructure($path);
            $settings = (array)($job['areas'] ?? []);
            $writtenAreas = []; // Datei-area_id der Bereiche, deren Felder geschrieben würden
            foreach ($templates as $template) {
                $this->fileTemplates[(int)$template['template_id']] = ['name' => (string)$template['name'], 'area_id' => (int)$template['area_id']];
            }
            foreach ($areas as $fileAreaId => &$area) {
                $override = trim((string)($settings[$fileAreaId]['name'] ?? ''));
                if ($override !== '' && preg_match('/^[0-9a-z_\-]+$/', $override)) $area['name'] = $override;
                $this->fileAreas[$fileAreaId] = ['name' => (string)$area['name'], 'type' => (string)$area['type'], 'default_field' => $this->_fileFieldName($area, (int)($area['default_field_id'] ?? 0))];
            }
            unset($area);
            // Konverter: Plan für alle Konverter der Datei, Zuordnung wie beim Import
            // (angelegt wird hier nichts - 'create' zählt als vorhanden)
            $plan = $this->converterPlan($converters, $job);
            foreach ($plan as $fileConverterId => $converter) {
                $this->map['converter'][$fileConverterId] = $converter['action'] === 'link' ? (int)$converter['converter_id'] : ($converter['action'] === 'create' ? -1 : 0);
            }
            foreach ($areas as $fileAreaId => $area) {
                $areaSettings = (array)($settings[$fileAreaId] ?? []);
                $structureMode = (string)($areaSettings['structure'] ?? 'ignore');
                $by = (string)($areaSettings['structure_by'] ?? 'name');
                if (!in_array($structureMode, self::GLOBAL_MODES, true)) $structureMode = 'ignore';
                $areaType = (string)$area['type'];
                $entry = $areaEntry($this->cms->_strip_tags($this->cms->textOrSnippet((string)$area['label'])), (string)$area['name'], $areaType, 0, 'ignore');
                if ($structureMode === 'ignore') {
                    $target = $this->_resolveArea(0, (string)$area['name'], $areaType);
                    if ($target < 1 && !empty($job['same_db'])) $target = $this->_resolveArea($fileAreaId, '', $areaType);
                    if ($target > 0) $this->map['area'][$fileAreaId] = $target;
                    $entry['target_id'] = $target;
                }
                else {
                    $existingId = ($by === 'area_id' && !empty($job['same_db']))
                        ? $this->_resolveArea($fileAreaId, '', $areaType)
                        : $this->_resolveArea(0, (string)$area['name'], $areaType);
                    $update = $existingId > 0 && $structureMode !== 'insert';
                    if (!$update && !self::modeCreates($structureMode)) {
                        $entry['action'] = 'skipped';
                    }
                    else {
                        $entry['action'] = $update ? (self::modeReplaces($structureMode) ? 'replaced' : 'updated') : 'inserted';
                        if ($update) {
                            $this->map['area'][$fileAreaId] = $existingId;
                            $entry['target_id'] = $existingId;
                        }
                        else {
                            $newAreas[$fileAreaId] = true;
                        }
                        // Felder: nach Name gegen den Zielbereich
                        if (in_array($areaType, self::CONTENT_TYPES, true)) {
                            $writtenAreas[] = $fileAreaId;
                            $existingFields = [];
                            if ($update) {
                                $this->db->pushResult();
                                $this->db->query("SELECT `name`, `type` FROM `field` WHERE `area_id` = ?", 'i', [$existingId]);
                                foreach ($this->db->fetchAll() as $row) $existingFields[(string)$row['name']] = (string)$row['type'];
                                $this->db->popResult();
                            }
                            $fileNames = [];
                            foreach ((array)$area['fields'] as $field) {
                                $name = (string)$field['name'];
                                $fileNames[] = $name;
                                // Thumbnail ohne Konverter: wird übergangen (der Plan
                                // steht unten - $this->map['converter'] ist schon gefüllt)
                                if ($this->_isThumbnail($field) && empty($this->_mapConverterIds($field, $this->sameDb))) {
                                    $entry['fields']['skipped'][] = $name;
                                    continue;
                                }
                                if (!isset($existingFields[$name])) $entry['fields']['added'][] = $name;
                                elseif ($existingFields[$name] !== (string)$field['type']) $entry['fields']['failed'][] = $name;
                                else $entry['fields']['updated'][] = $name;
                            }
                            if (self::modeReplaces($structureMode)) {
                                $entry['fields']['removed'] = array_values(array_diff(array_keys($existingFields), $fileNames));
                            }
                        }
                    }
                }
                $analysis['areas'][$fileAreaId] = $entry;
            }
            $analysis['converters'] = array_intersect_key($plan, $this->_usedConverterIds($areas, $writtenAreas));
            foreach ($templates as $template) {
                $fileAreaId = (int)$template['area_id'];
                if (!isset($analysis['areas'][$fileAreaId])) continue;
                $areaSettings = (array)($settings[$fileAreaId] ?? []);
                $templateMode = (string)($areaSettings['templates'] ?? 'ignore');
                if ($templateMode === 'ignore' || !in_array($templateMode, self::GLOBAL_MODES, true)) continue;
                $by = (string)($areaSettings['templates_by'] ?? 'name');
                $areaId = (int)($this->map['area'][$fileAreaId] ?? 0);
                $status = 'failed';
                if ($areaId > 0 || isset($newAreas[$fileAreaId])) {
                    $existingId = $areaId > 0 ? $this->_resolveTemplate($template, $areaId, $by) : 0;
                    $update = $existingId > 0 && $templateMode !== 'insert';
                    if (!$update && !self::modeCreates($templateMode)) $status = 'skipped';
                    else $status = $update ? (self::modeReplaces($templateMode) ? 'replaced' : 'updated') : 'inserted';
                }
                $analysis['areas'][$fileAreaId]['templates'][$status]++;
            }
        }
        else {
            $areaId = (int)($job['area_id'] ?? 0);
            $areaRow = $this->_areaOf($areaId);
            if (!$areaRow) {
                $analysis['error'] = $this->cms->snippet('area.error_area_not_found');
                return $analysis;
            }
            $analysis['areas'][$areaId] = $areaEntry($this->_areaLabel($areaId), (string)$areaRow['name'], (string)$areaRow['type'], $areaId, 'ignore');
        }

        // Datensätze
        // --------------------
        $skip = max(0, (int)($job['skip'] ?? 0));
        $limit = max(0, (int)($job['limit'] ?? 0));
        $entries = match ($type) {
            'xml'  => $this->_xmlEntries($path, $job),
            'csv'  => $this->_csvEntries($path, $job),
            'docx' => $this->_docxEntries($path, $job),
        };
        $force = !empty($job['force']);
        $index = 0;
        $checked = 0;
        foreach ($entries as $entry) {
            $index++;
            if ($index <= $skip) {
                $analysis['skipped']++;
                continue;
            }
            if ($limit > 0 && $checked >= $limit) break;
            $checked++;
            $key = $isGlobal ? (int)$entry['area_id'] : (int)$job['area_id'];
            if (!isset($analysis['areas'][$key])) continue;
            $entryMode = (string)($entry['mode'] ?? $mode);
            if (!in_array($entryMode, self::MODES, true)) $entryMode = $mode;
            $item = ['id' => (int)($entry['id'] ?? 0), 'record_id' => 0, 'label' => (string)($entry['label'] ?? ''), 'status' => 'failed', 'messages' => []];
            if (!empty($entry['error'])) {
                if ($isGlobal && isset($newAreas[$key])) {
                    // der Bereich entsteht erst beim Import: dort gibt es noch nichts
                    $item['status'] = self::modeCreates($entryMode) ? 'inserted' : 'skipped';
                    if ($item['status'] === 'skipped') $item['messages'][] = $this->cms->snippet('record.note_import_not_found');
                }
                else {
                    $item['messages'][] = (string)$entry['error'];
                }
            }
            else {
                $areaId = (int)$entry['data']['area_id'];
                $lookup = $this->_lookupExisting($entry, $job, $entryMode, $areaId, $this->_languagesOf($areaId)['primary_id']);
                if ($lookup['update']) {
                    $item['record_id'] = $lookup['record_id'];
                    $item['status'] = self::modeReplaces($entryMode) ? 'replaced' : 'updated';
                    if (!$force && ($prodData = $this->record->load($lookup['record_id'])) && ($lock = $this->service->lockInfo($prodData))) {
                        $item['status'] = 'failed';
                        $item['messages'][] = $this->service->lockMessage($lock);
                    }
                }
                elseif (self::modeCreates($entryMode)) {
                    $item['status'] = 'inserted';
                    if ($lookup['force_id'] > 0 && $lookup['productive']) {
                        $item['messages'][] = str_replace('{id}', (string)$lookup['force_id'], $this->cms->snippet('record.note_import_id_taken'));
                    }
                }
                else {
                    $item['status'] = 'skipped';
                    $item['messages'][] = $this->cms->snippet('record.note_import_not_found');
                }
            }
            $analysis['areas'][$key]['records'][$item['status']]++;
            if ($extended) $analysis['areas'][$key]['items'][] = $item;
        }
        $analysis['total'] = $checked;
        $this->schemaMemo = [];
        return $analysis;
    }

    /**
     * Name eines Feldes der Datei (Bereich aus _xmlStructure()) zu seiner field_id.
     */
    private function _fileFieldName(array $area, int $fileFieldId): string
    {
        if ($fileFieldId < 1) return '';
        return (string)($area['fields'][$fileFieldId]['name'] ?? '');
    }

    /**
     * Stammt die XML-Datei aus dieser Datenbank (Header)?
     */
    public function isSameDatabase(string $path): bool
    {
        $reader = new \XMLReader();
        if (!@$reader->open($path)) return false;
        $same = false;
        while (@$reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) continue;
            if ($reader->depth === 1 && $reader->name === 'header') {
                $header = $this->_xmlHeader($this->_readerNode($reader));
                $ownDb = (string)($this->config->get('db.name') ?? '');
                $same = $header['database'] !== '' && $header['database'] === $ownDb;
                break;
            }
            if ($reader->depth === 1) break;
        }
        $reader->close();
        return $same;
    }

    /**
     * Ergebnis eines Eintrags in die Zähler und die Liste übernehmen.
     */
    private function _addItem(array &$result, array $item): void
    {
        unset($item['tmp_id'], $item['prepared']);
        $status = (string)$item['status'];
        if ($status === 'failed') $result['failed']++;
        elseif ($status === 'skipped') $result['skipped']++;
        else {
            $result['done']++;
            if (isset($result[$status])) $result[$status]++;
        }
        if ($status !== 'skipped' || ($item['kind'] ?? '') === 'record') $result['total']++;
        $result['items'][] = $item;
    }

    /**
     * Die Meldungen aus Cms::errors als Klartext-Zeilen ("Label: Text")
     * @return string[]
     */
    private function _errorTexts(): array
    {
        $texts = [];
        foreach ($this->cms->errors as $key => $error) {
            $text = $this->cms->_strip_tags((string)($error['text'] ?? ''));
            if (!is_int($key) && (string)($error['label'] ?? '') !== '') {
                $text = $this->cms->_strip_tags($this->cms->textOrSnippet((string)$error['label'])) . $this->cms->snippet('colon') . $text;
            }
            $texts[] = $text;
        }
        if (empty($texts)) $texts[] = $this->cms->snippet('record.error_batch_unknown');
        return array_values(array_unique($texts));
    }

    // ====================
    // Einen Datensatz importieren (zwei Durchläufe)

    /**
     * Erster Durchlauf: Datensatz anlegen bzw. auschecken, Feldwerte, Dateien,
     * Passwörter und die einfachen Meta-Spalten prüfen und einchecken. Verknüpfungen und Bezüge auf andere Datensätze
     * (parent_id, template_id, user_id) folgen im zweiten Durchlauf (_importFinish()),
     * wenn alle Ids feststehen. $entry['data'] ist die vom Importer aufbereitete Form:
     *
     *     values    feld => Wert (mehrsprachig: language_id => Wert, Zielsprachen)
     *     stored    Felder im Datenbankformat
     *     prehashed Passwort-Felder mit Hash
     *     relations feld => [dest_id, …] (Ids der Datei - zugeordnet im zweiten Durchlauf)
     *     files     feld => [langKey ('' oder language_id) => ['importfile' => Pfad, 'filename' => Name] | null]
     *               (null = Datei genannt, aber nicht enthalten -> Fehler)
     *     meta      Meta-Spalte => Wert (Datenbankformat; template_id, parent_id, user_id
     *               sind Ids der Datei)
     *     area_id   Zielbereich
     *
     * Der Eintrag bringt seinen Modus mit ($entry['mode'], sonst $job['mode']) und
     * sein Update-Kriterium ($entry['by']: 'record_id' | 'slug').
     *
     * @return array  $item mit 'status', 'id' und - wenn ein zweiter Durchlauf nötig
     *                ist - 'prepared' (für _importFinish())
     */
    /**
     * Vorhandener Datensatz zu einem Eintrag (nach Kriterium $entry['by']). Ids einer
     * fremden Datenbank sind Zufall: dort zählt nur der Slug, und neue Datensätze
     * bekommen neue Ids (force_id 0).
     *
     * @return array ['record_id' => gefundene bzw. Datei-Id, 'productive' => bool,
     *                'update' => bool (vorhanden, im Zielbereich, Modus schreibt),
     *                'force_id' => Datei-Id, die ein neuer Datensatz bekommen soll (0 = keine)]
     */
    private function _lookupExisting(array $entry, array $job, string $mode, int $areaId, int $primaryLanguageId): array
    {
        $fileId = (int)($entry['id'] ?? 0);
        $data = (array)($entry['data'] ?? []);
        $by = (string)($entry['by'] ?? 'record_id');
        $foreign = empty($job['same_db']) && (string)($job['type'] ?? '') === 'xml' && $by !== 'record_id';
        $recordId = $foreign ? 0 : $fileId;
        if ($by === 'slug') {
            $bySlug = $this->_recordBySlug($areaId, (array)($data['values'] ?? []), $primaryLanguageId);
            if ($bySlug > 0) $recordId = $bySlug;
        }
        $existing = null;
        if ($recordId > 0) {
            $this->db->pushResult();
            $this->db->query("SELECT `record_id`, `area_id`, `tmp`, `deleted` FROM `record` WHERE `record_id` = ?", 'i', [$recordId]);
            $existing = $this->db->fetch();
            $this->db->popResult();
        }
        $isProductive = (bool)($existing && empty($existing['tmp']) && empty($existing['deleted']));
        return [
            'record_id' => $recordId,
            'productive' => $isProductive,
            'update' => $isProductive && $mode !== 'insert' && (int)$existing['area_id'] === $areaId,
            'force_id' => $foreign ? 0 : $fileId,
        ];
    }

    private function _importPrepare(array $entry, array $job, array $item): array
    {
        $mode = (string)($entry['mode'] ?? $job['mode']);
        if (!in_array($mode, self::MODES, true)) $mode = (string)$job['mode'];
        $force = !empty($job['force']);
        $data = (array)$entry['data'];
        $areaId = (int)($data['area_id'] ?? 0);
        $areaData = $this->_areaOf($areaId);
        if (!$areaData) {
            $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            return $item;
        }
        $schema = $this->_schemaOf($areaId);
        $langCtx = $this->_languagesOf($areaId);
        $fileId = (int)($entry['id'] ?? 0);

        // Vorhandener Datensatz? (nach Kriterium)
        // --------------------
        $lookup = $this->_lookupExisting($entry, $job, $mode, $areaId, $langCtx['primary_id']);
        $recordId = $lookup['record_id'];
        $isProductive = $lookup['productive'];
        $updateExisting = $lookup['update'];
        $fileIdForce = $lookup['force_id'];
        if (!$updateExisting && !self::modeCreates($mode)) {
            // update / replace ohne vorhandenen Datensatz: übergehen
            $item['status'] = 'skipped';
            $item['messages'][] = $this->cms->snippet('record.note_import_not_found');
            return $item;
        }

        $tmpId = 0;
        $prodData = null;
        if ($updateExisting) {
            // Arbeitskopie des Originals
            if (!$prodData = $this->record->load($recordId)) return $item;
            if (!$force && ($lock = $this->service->lockInfo($prodData))) {
                $this->cms->error($this->service->lockMessage($lock));
                return $item;
            }
            if (!$tmpId = $this->record->checkOut($prodData)) return $item;
        }
        else {
            // neu - mit der Id der Datei, wenn sie frei ist oder frei gemacht werden kann
            $forceId = 0;
            if ($fileIdForce > 0) {
                if ($isProductive && $recordId === $fileId) {
                    $item['messages'][] = str_replace('{id}', (string)$fileId, $this->cms->snippet('record.note_import_id_taken'));
                }
                else {
                    $state = $this->record->freeRecordId($fileId);
                    if ($state === 'free') {
                        $forceId = $fileId;
                    }
                    else {
                        $item['messages'][] = str_replace('{id}', (string)$fileId, $this->cms->snippet('record.note_import_id_taken'));
                    }
                }
            }
            if (!$tmpData = $this->record->tmpInit(['area_id' => $areaId], $forceId)) return $item;
            $tmpId = (int)$tmpData['record_id'];
        }

        try {
            $saveData = ['record_id' => $tmpId, 'area_id' => $areaId];
            $fields = ['area_id'];
            $stored = (array)($data['stored'] ?? []);
            $prehashed = (array)($data['prehashed'] ?? []);
            $languageIds = $langCtx['language_ids'];
            $replace = self::modeReplaces($mode);

            // Feldwerte und Dateien
            // --------------------
            $this->_fieldValues($schema, $data, $languageIds, $replace, $updateExisting, $tmpId, $saveData, $fields);

            // Eingebettete Inhalte (Felder vom Typ link, nur CSV/DOCX - EINGEBETTETE
            // INHALTE): auschecken bzw. anlegen, Werte in ihren Arbeitsstand; der
            // Check-in folgt mit dem Datensatz
            // --------------------
            $withLinks = array_key_exists('links', $data) && !empty($this->record->linkFields($schema));
            if ($withLinks) {
                $this->_importLinks($tmpId, $schema, (array)$data['links'], $replace, $updateExisting, $job, $item);
            }

            // Einfache Meta-Spalten (Bezüge auf andere Datensätze im zweiten Durchlauf)
            // --------------------
            $meta = (array)($data['meta'] ?? []);
            $laterMeta = [];
            foreach ($meta as $name => $value) {
                if (!in_array($name, self::META_FIELDS, true)) continue;
                if (in_array($name, ['parent_id', 'template_id', 'user_id'], true)) {
                    if ((int)$value > 0) $laterMeta[$name] = (int)$value;
                    continue;
                }
                $saveData[$name] = $value;
                $fields[] = $name;
                if (!in_array($name, $stored, true)) $stored[] = $name;
            }

            // Einfügeposition eines neuen Datensatzes (EINFÜGEPOSITION): Vater des
            // Auftrags, hinter dem Vorgänger - nennt die Datei einen Vater, hängt ihn
            // der zweite Durchlauf um
            $placementParent = null;
            if (!$updateExisting) {
                $placement = $this->_insertPlacement($areaId, $schema, $job);
                if ($placement !== null) {
                    $placementParent = $placement['parent_id'];
                    $saveData['previous_id'] = $placement['previous_id'];
                    if (isset($schema['parent_id'])) {
                        $saveData['parent_id'] = $placementParent;
                        $fields[] = 'parent_id';
                        if (!in_array('parent_id', $stored, true)) $stored[] = 'parent_id';
                    }
                }
            }

            // Prüfen und einchecken
            // --------------------
            // Verknüpfungsfelder werden hier nicht geprüft (not_empty / min): die
            // Verknüpfungen kommen im zweiten Durchlauf, der erneut mit Prüfung speichert
            $fields = array_values(array_unique($fields));
            $hasRelationFields = false;
            foreach ($schema as $colDef) {
                if (in_array((string)($colDef['type'] ?? ''), $this->cms->relationTypes, true)) { $hasRelationFields = true; break; }
            }
            // mit eingebetteten Inhalten: deren Check-in und der des Datensatzes in
            // einer Transaktion - scheitert eines, bleibt alles, wie es war
            $this->db->beginTransaction();
            try {
                if ($withLinks && !$this->record->checkInDependents($tmpId, $updateExisting ? $recordId : 0)) {
                    throw new \RuntimeException('#dependents failed');
                }
                $newId = $this->record->importSave($fields, $saveData, $stored, $prehashed, deferRelations: $hasRelationFields);
                if (!$newId) {
                    throw new \RuntimeException('#save failed');
                }
                $this->db->commit();
            }
            catch (\Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
            $item['id'] = $updateExisting ? $recordId : (int)$newId;
            $item['status'] = $updateExisting ? ($replace ? 'replaced' : 'updated') : 'inserted';
            if ($placementParent !== null) $this->insertAfter[$areaId][$placementParent] = (int)$newId;
            if (!$updateExisting) {
                // Herkunft wiederherstellen: created_at aus der Datei; created_by und
                // owner_id nur aus derselben Datenbank bzw. aus CSV (dort ist die
                // Admin-Id eine ausdrückliche Angabe) - save() setzt sie sonst auf
                // jetzt / Session-Admin
                $restoreAdmins = !empty($job['same_db']) || (string)($job['type'] ?? '') === 'csv';
                $this->_restoreCreated('record', 'record_id', (int)$newId, $meta, $restoreAdmins);
            }
            // Zuordnung Id der Datei => tatsächliche Id (für Verknüpfungen)
            if ($fileId > 0) $this->map['record'][$fileId] = (int)$item['id'];
            // Zweiter Durchlauf nötig? Verknüpfungsfelder im Bereich (dort auch die
            // zurückgestellte Prüfung) oder Bezüge
            $relations = (array)($data['relations'] ?? []);
            if ($hasRelationFields || !empty($laterMeta)) {
                $item['prepared'] = [
                    'replace' => $replace,
                    'area_id' => $areaId,
                    'links_handled' => $withLinks,
                    'relations' => $relations,
                    'meta' => $laterMeta,
                    'stored' => $stored,
                ];
            }
            return $item;
        }
        catch (\Throwable $e) {
            $message = $e->getMessage();
            if (!str_starts_with($message, '#')) $this->cms->error($message);
            $this->_cancelKeepErrors($tmpId);
            $item['status'] = 'failed';
            return $item;
        }
    }

    /**
     * Feldwerte und Dateien eines Eintrags in $saveData/$fields übernehmen (erster
     * Durchlauf) - für den Datensatz wie für seine eingebetteten Inhalte. Ohne
     * Verknüpfungen, Volltext und Meta-Spalten. Mehrsprachige Felder nennen alle
     * Sprachen der Instanz (bei replace wird Fehlendes geleert: Fallback); nicht
     * genannte Dateien werden bei replace eines vorhandenen Datensatzes gelöscht.
     *
     * @param array $data  'values' und 'files' (siehe _importPrepare())
     */
    private function _fieldValues(array $schema, array $data, array $languageIds, bool $replace, bool $updateExisting, int $tmpId, array &$saveData, array &$fields): void
    {
        $values = (array)($data['values'] ?? []);
        foreach ($schema as $name => $colDef) {
            $type = (string)($colDef['type'] ?? '');
            if ($type === 'fulltext' || $type === 'file' || in_array($type, $this->cms->relationTypes, true)) continue;
            if (!empty($colDef['meta'])) continue; // Meta-Spalten im zweiten Durchlauf
            $given = array_key_exists($name, $values);
            if (!$given && !$replace) continue;
            $value = $given ? $values[$name] : ($type === 'json' ? [] : '');
            if ($this->cms->isMultilingualField($colDef) && count($languageIds) > 0) {
                // alle Sprachen der Instanz nennen: was fehlt, wird geleert (Fallback)
                $givenLangs = is_array($value) ? $value : [$languageIds[0] => $value];
                $value = [];
                foreach ($languageIds as $languageId) {
                    if (array_key_exists($languageId, $givenLangs)) $value[$languageId] = $givenLangs[$languageId];
                    elseif ($replace) $value[$languageId] = '';
                }
                if (empty($value)) continue;
            }
            $saveData[$name] = $value;
            $fields[] = $name;
        }

        $files = (array)($data['files'] ?? []);
        $tmpCache = null;
        foreach ($schema as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') !== 'file' || !empty($colDef['parent_field_id'])) continue;
            $fieldId = (int)$colDef['field_id'];
            $isMultilingual = $this->cms->isMultilingualField($colDef);
            if (array_key_exists($name, $files)) {
                $value = [];
                foreach ((array)$files[$name] as $langKey => $fileData) {
                    if ($fileData === null) {
                        $this->cms->error($this->cms->snippet('record.error_import_file_missing'), $name, (string)($colDef['label'] ?? $name));
                        throw new \RuntimeException('#file missing');
                    }
                    if ($isMultilingual) {
                        $languageId = ($langKey === '') ? $languageIds[0] : (int)$langKey;
                        if (!in_array($languageId, $languageIds, true)) continue;
                        $value[$languageId] = $fileData;
                    }
                    else {
                        $value = $fileData;
                        break;
                    }
                }
                if (!empty($value)) {
                    $saveData[$name] = $value;
                    $fields[] = $name;
                }
            }
            elseif ($replace && $updateExisting) {
                // nicht genannt: vorhandene Dateien löschen
                $tmpCache ??= $this->record->loadTmpData($tmpId);
                $entryCache = (array)($tmpCache[$name] ?? []);
                foreach ($this->record->fileEntries($entryCache) as $langKey => $fileData) {
                    if ((int)($fileData['size'] ?? 0) < 1 || !empty($fileData['deleted'])) continue;
                    $this->record->markFileDeleted($tmpId, $fieldId, $langKey === '' ? null : (int)$langKey);
                }
            }
        }
    }

    /**
     * Eingebettete Inhalte der link-Felder eines Eintrags (EINGEBETTETE INHALTE) an
     * der Arbeitskopie $tmpId des Datensatzes: vorhandene Inhalte auschecken (die
     * Kante zeigt danach auf ihre Arbeitskopie), neue anlegen (embedInsert()), ihre
     * Werte geprüft in den Arbeitsstand schreiben (stayTemporary), nicht mehr
     * genannte abhängen und die Reihenfolge der Datei setzen. Eingecheckt wird alles
     * mit dem Datensatz (LinkTrait::checkInDependents() in _importPrepare()) -
     * verwaiste Inhalte löscht der Check-in.
     *
     * Zuordnung eines Eintrags zu einem vorhandenen Inhalt: über seine Id (CSV-Spalte
     * {link}.record_id, DOCX), sonst über die Stelle. 'partial' (DOCX): nur die
     * genannten Inhalte ändern - nichts anlegen, entfernen oder umsortieren.
     * Ohne Angaben zu einem link-Feld: bei replace werden alle Inhalte entfernt.
     *
     * @param array $links  link-Feld => ['entries' => [...], 'partial' => bool]
     * @throws \RuntimeException  bei Fehlern (Meldungen in Cms::errors)
     */
    private function _importLinks(int $tmpId, array $schema, array $links, bool $replace, bool $updateExisting, array $job, array &$item): void
    {
        $force = !empty($job['force']);
        foreach ($this->record->linkFields($schema) as $name => $colDef) {
            $fieldId = (int)$colDef['field_id'];
            $link = $links[$name] ?? null;
            if ($link === null && !($replace && $updateExisting)) continue;
            $entries = array_values((array)($link['entries'] ?? []));
            $partial = !empty($link['partial']);
            $linkLabel = $this->cms->textOrSnippet((string)($colDef['label'] ?? $name));

            // bisherige Inhalte (produktiv, in ihrer Reihenfolge)
            $current = [];
            foreach ($this->record->linkDestIds($tmpId, $fieldId) as $destId) {
                $this->db->pushResult();
                $this->db->query("SELECT 1 FROM `record` WHERE `record_id` = ? AND `tmp` = 0 AND `deleted` = 0", 'i', [$destId]);
                $productive = $this->db->num_rows > 0;
                $this->db->popResult();
                if ($productive) $current[] = $destId;
            }
            $targetAreaIds = $this->record->linkTargetAreaIds($colDef);
            $final = [];
            $used = [];
            foreach ($entries as $index => $entry) {
                $entryId = $entry['id'] ?? null;
                $destId = 0;
                if ($entryId !== null) {
                    if (in_array((int)$entryId, $current, true) && !isset($used[(int)$entryId])) $destId = (int)$entryId;
                }
                elseif (!$partial && isset($current[$index]) && !isset($used[$current[$index]])) {
                    $destId = $current[$index];
                }
                if ($partial && $destId < 1) {
                    $item['messages'][] = str_replace(['{field}', '{id}'], [$linkLabel, (string)(int)$entryId], $this->cms->snippet('record.note_import_embedded_missing'));
                    continue;
                }
                if ($destId > 0) {
                    // vorhandener Inhalt: auschecken, Kante auf die Arbeitskopie
                    $used[$destId] = true;
                    if (!$destData = $this->record->load($destId)) throw new \RuntimeException('#load embedded');
                    if (!$force && ($lock = $this->service->lockInfo($destData))) {
                        $this->cms->error($linkLabel . ' #' . ($index + 1) . $this->cms->snippet('colon') . $this->service->lockMessage($lock));
                        throw new \RuntimeException('#embedded locked');
                    }
                    if (!$destTmpId = $this->record->checkOut($destData)) throw new \RuntimeException('#checkout embedded');
                    $this->record->linkRepoint($fieldId, $tmpId, $destId, $destTmpId);
                    $destAreaId = (int)$destData['area_id'];
                    $isNew = false;
                }
                else {
                    // neuer Inhalt: Arbeitskopie ohne Original im Zielbereich
                    $destAreaId = (int)($entry['area_id'] ?? 0);
                    if (!in_array($destAreaId, $targetAreaIds, true)) $destAreaId = (int)($targetAreaIds[0] ?? 0);
                    if (!$destTmpId = $this->record->embedInsert($colDef, $tmpId, $destAreaId, 0, false)) throw new \RuntimeException('#embed insert');
                    $isNew = true;
                }
                // Werte geprüft in den Arbeitsstand (wie die eingebettete Maske)
                $destSchema = $this->_schemaOf($destAreaId);
                $saveData = ['record_id' => $destTmpId, 'area_id' => $destAreaId];
                $fields = ['area_id'];
                $this->_fieldValues($destSchema, $entry, $this->_languagesOf($destAreaId)['language_ids'], $replace && !$partial, !$isNew, $destTmpId, $saveData, $fields);
                $errorsBefore = array_keys($this->cms->errors);
                $saved = $this->record->importSave(array_values(array_unique($fields)), $saveData, (array)($entry['stored'] ?? []), (array)($entry['prehashed'] ?? []), stayTemporary: true);
                if (!$saved) {
                    // Feldfehler des Inhalts kenntlich machen: "{link} #n › {feld}"
                    foreach (array_keys($this->cms->errors) as $key) {
                        if (is_int($key) || in_array($key, $errorsBefore, true)) continue;
                        $error = $this->cms->errors[$key];
                        $error['label'] = $linkLabel . ' #' . ($index + 1) . ' › ' . $this->cms->textOrSnippet((string)($error['label'] ?? $key));
                        unset($this->cms->errors[$key]);
                        $this->cms->errors["$name.$index.$key"] = $error;
                    }
                    throw new \RuntimeException('#embedded save failed');
                }
                $final[] = $destTmpId;
            }
            if ($partial) continue;
            // nicht mehr genannte Inhalte abhängen - der Check-in löscht sie als verwaist
            foreach ($current as $destId) {
                if (!isset($used[$destId])) $this->record->removeRelations($colDef, $tmpId, $destId);
            }
            $this->record->linkReorder($tmpId, $fieldId, $final);
        }
    }

    /**
     * Zweiter Durchlauf: den (jetzt produktiven) Datensatz erneut auschecken,
     * Verknüpfungen und Bezüge mit den zugeordneten Ids setzen, einchecken.
     */
    private function _importFinish(array $item, array $job): array
    {
        $prepared = (array)($item['prepared'] ?? []);
        $recordId = (int)($item['id'] ?? 0);
        if ($recordId < 1 || empty($prepared)) return $item;
        $areaId = (int)$prepared['area_id'];
        $schema = $this->_schemaOf($areaId);
        $replace = !empty($prepared['replace']);

        if (!$prodData = $this->record->load($recordId)) {
            $item['status'] = 'failed';
            return $item;
        }
        // Die Sperre ist die eigene (erster Durchlauf) - auschecken
        if (!$tmpId = $this->record->checkOut($prodData)) {
            $item['status'] = 'failed';
            return $item;
        }
        try {
            // Verknüpfungen
            // --------------------
            $relations = (array)$prepared['relations'];
            foreach ($schema as $name => $colDef) {
                if (!in_array((string)($colDef['type'] ?? ''), $this->cms->relationTypes, true)) continue;
                // link-Felder hat der erste Durchlauf erledigt (CSV/DOCX)
                if (!empty($prepared['links_handled']) && in_array((string)$colDef['type'], $this->cms->dependentTypes, true)) continue;
                if (array_key_exists($name, $relations)) {
                    $ids = [];
                    foreach ($this->system->sanitizeIds((array)$relations[$name], true) as $destId) {
                        $ids[] = $this->_mapId('record', (int)$destId);
                    }
                    $ids = array_values(array_unique(array_filter($ids)));
                    if (empty($ids)) $this->record->removeRelations($colDef, $tmpId);
                    else $this->record->createRelations($colDef, $tmpId, $ids, replace: true, checkTmp: false);
                }
                elseif ($replace) {
                    $this->record->removeRelations($colDef, $tmpId);
                }
            }

            // Bezüge auf andere Datensätze / Templates
            // --------------------
            $saveData = ['record_id' => $tmpId, 'area_id' => $areaId];
            $fields = ['area_id'];
            $stored = (array)$prepared['stored'];
            $movedTo = null;
            foreach ((array)$prepared['meta'] as $name => $value) {
                if ($name === 'parent_id') {
                    if (!isset($schema['parent_id'])) continue;
                    $value = $this->_mapId('record', (int)$value);
                    if ($value > 0 && !$this->_recordInArea($value, $areaId)) {
                        $item['messages'][] = str_replace('{id}', (string)$value, $this->cms->snippet('record.note_import_parent_missing'));
                        continue;
                    }
                    if ($value === (int)($prodData['parent_id'] ?? 0)) continue;
                    // unter den Vater der Datei: ans Ende seiner Geschwister, in
                    // Dateireihenfolge (EINFÜGEPOSITION)
                    $movedTo = $value;
                    $saveData['previous_id'] = $this->_insertPrevious($areaId, $value, $job);
                }
                elseif ($name === 'template_id') {
                    $value = $this->_mapId('template', (int)$value);
                    if (!$this->_templateExists($value)) continue;
                }
                elseif ($name === 'user_id') {
                    $value = $this->_mapId('record', (int)$value);
                    if ($value < 1) continue;
                }
                $saveData[$name] = $value;
                $fields[] = $name;
                if (!in_array($name, $stored, true)) $stored[] = $name;
            }

            // Check-in (die Feldwerte kommen aus dem Arbeitsstand der Arbeitskopie)
            // --------------------
            if (!$this->record->importSave(array_values(array_unique($fields)), $saveData, $stored, [])) {
                throw new \RuntimeException('#save failed');
            }
            if ($movedTo !== null) $this->insertAfter[$areaId][$movedTo] = $recordId;
            return $item;
        }
        catch (\Throwable $e) {
            $message = $e->getMessage();
            if (!str_starts_with($message, '#')) $this->cms->error($message);
            $this->_cancelKeepErrors($tmpId);
            $item['status'] = 'failed';
            $item['messages'][] = $this->cms->snippet('record.note_import_relations_failed');
            return $item;
        }
    }

    /**
     * Einfügeposition eines neuen Datensatzes (EINFÜGEPOSITION): Vater aus dem
     * Auftrag (nur Bereiche mit Baum) und der Vorgänger auf dieser Ebene.
     *
     * @return array|null  ['parent_id' => int, 'previous_id' => int]; null, wenn der
     *                     Bereich weder Baum noch Handsortierung führt
     */
    private function _insertPlacement(int $areaId, array $schema, array $job): ?array
    {
        $tree = isset($schema['parent_id']);
        if (!$tree && !isset($schema['position'])) return null;
        $parentId = $tree ? (int)($job['parent_id'] ?? 0) : 0;
        if ($parentId > 0 && !$this->_recordInArea($parentId, $areaId)) $parentId = 0;
        return ['parent_id' => $parentId, 'previous_id' => $this->_insertPrevious($areaId, $parentId, $job)];
    }

    /**
     * Vorgänger des nächsten neuen Datensatzes unter einem Vater: der zuletzt dort
     * eingefügte, davor der previous_id des Auftrags (auf der Ebene des
     * Auftrags-Vaters), sonst der letzte vorhandene Datensatz der Ebene.
     */
    private function _insertPrevious(int $areaId, int $parentId, array $job): int
    {
        if (!isset($this->insertAfter[$areaId][$parentId])) {
            if ($parentId === (int)($job['parent_id'] ?? 0) && array_key_exists('previous_id', $job)) {
                $previousId = (int)$job['previous_id'];
                if ($previousId > 0 && !$this->_recordInArea($previousId, $areaId)) $previousId = 0;
            }
            else {
                $this->db->pushResult();
                $this->db->query(
                    "SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `parent_id` <=> ? AND `tmp` = 0 AND `deleted` = 0"
                    . " ORDER BY `position` DESC, `record_id` DESC LIMIT 1",
                    'ii', [$areaId, $parentId > 0 ? $parentId : null]
                );
                $previousId = (int)($this->db->fetchNum()[0] ?? 0);
                $this->db->popResult();
            }
            $this->insertAfter[$areaId][$parentId] = $previousId;
        }
        return (int)$this->insertAfter[$areaId][$parentId];
    }

    /**
     * created_at / created_by / owner_id (ORIGIN_FIELDS) eines neu angelegten
     * Datensatzes oder Templates aus der Datei setzen. Die Admin-Ids nur mit
     * $restoreAdmins (Datei aus dieser Datenbank bzw. CSV) und nur, wenn der Admin
     * existiert - sonst bleibt der Session-Admin; changed_by bleibt er immer.
     * Templates haben keinen owner_id (nur Spalten, die $table kennt).
     */
    private function _restoreCreated(string $table, string $idField, int $id, array $source, bool $restoreAdmins): void
    {
        if ($id < 1) return;
        $set = [];
        $types = '';
        $values = [];
        $createdAt = trim((string)($source['created_at'] ?? ''));
        if ($createdAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $createdAt) && !str_starts_with($createdAt, '0000') && !str_starts_with($createdAt, '1000')) {
            $set[] = '`created_at` = ?';
            $types .= 's';
            $values[] = $createdAt;
        }
        if ($restoreAdmins) {
            foreach (self::ORIGIN_FIELDS as $name) {
                if ($name === 'owner_id' && $table !== 'record') continue;
                $adminId = (int)($source[$name] ?? 0);
                if ($adminId < 1) continue;
                $this->db->pushResult();
                $this->db->query("SELECT 1 FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
                $exists = $this->db->num_rows > 0;
                $this->db->popResult();
                if (!$exists) continue;
                $set[] = "`{$name}` = ?";
                $types .= 'i';
                $values[] = $adminId;
            }
        }
        if (empty($set)) return;
        $types .= 'i';
        $values[] = $id;
        $this->db->pushResult();
        $this->db->query("UPDATE `$table` SET " . implode(', ', $set) . " WHERE `$idField` = ?", $types, $values);
        $this->db->popResult();
    }

    /**
     * Datensatz im Bereich über das Feld `slug` finden (Update-Kriterium 'slug'):
     * der Wert der Hauptsprache in data_chr. 0 = kein Feld `slug` oder kein Treffer.
     */
    private function _recordBySlug(int $areaId, array $values, int $primaryId): int
    {
        $schema = $this->_schemaOf($areaId);
        $colDef = $schema['slug'] ?? null;
        if ($colDef === null || (string)($colDef['type'] ?? '') !== 'chars') return 0;
        $value = $values['slug'] ?? '';
        if (is_array($value)) $value = (string)($value[$primaryId] ?? reset($value) ?: '');
        $value = trim((string)$value);
        if ($value === '') return 0;
        $this->db->pushResult();
        $this->db->query(
            "SELECT `d`.`record_id` FROM `data_chr` `d` INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id`"
            . " WHERE `d`.`field_id` = ? AND `d`.`data` = ? AND `r`.`area_id` = ? AND `r`.`tmp` = 0 AND `r`.`deleted` = 0 ORDER BY `d`.`language_id` = ? DESC LIMIT 1",
            'isii', [(int)$colDef['field_id'], $value, $areaId, $primaryId]
        );
        $id = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        return $id;
    }

    /**
     * Zugeordnete Id: aus der Zuordnungstabelle, sonst unverändert (ein Datensatz,
     * Bereich oder Template, das nicht in der Datei war, gilt als vorhanden).
     */
    private function _mapId(string $kind, int $id): int
    {
        if (isset($this->map[$kind][$id])) return (int)$this->map[$kind][$id];
        // Templates, die nicht importiert wurden: über Name und zugeordneten Bereich
        if ($kind === 'template' && isset($this->fileTemplates[$id])) {
            $fileAreaId = (int)$this->fileTemplates[$id]['area_id'];
            $areaId = (int)($this->map['area'][$fileAreaId] ?? $fileAreaId);
            $found = $this->_resolveTemplate(['name' => $this->fileTemplates[$id]['name'], 'template_id' => $id], $areaId, 'name');
            if ($found > 0) {
                $this->map['template'][$id] = $found;
                return $found;
            }
        }
        return $id;
    }

    /**
     * Arbeitskopie verwerfen, die gesammelten Meldungen behalten (cancel() räumt
     * Cms::errors nicht auf, ein Fehler dort ginge aber im Protokoll unter).
     */
    private function _cancelKeepErrors(int $tmpId): void
    {
        if ($tmpId < 1) return;
        $errors = $this->cms->errors;
        try {
            $this->record->cancel($tmpId);
        }
        catch (\Throwable $e) {
        }
        $this->cms->errorsReset();
        foreach ($errors as $key => $error) {
            $this->cms->error((string)$error['text'], is_int($key) ? '' : (string)$key, (string)($error['label'] ?? ''));
        }
    }

    private function _recordInArea(int $recordId, int $areaId, bool $orTmp = false): bool
    {
        $this->db->pushResult();
        // $orTmp: im zweiten Durchlauf ist ein neuer Vaterdatensatz noch eine
        // Arbeitskopie unter seiner endgültigen Id
        $tmpCondition = $orTmp ? "AND `deleted` = 0" : "AND `tmp` = 0 AND `deleted` = 0";
        $this->db->query("SELECT 1 FROM `record` WHERE `record_id` = ? AND `area_id` = ? $tmpCondition", 'ii', [$recordId, $areaId]);
        $found = $this->db->num_rows > 0;
        $this->db->popResult();
        return $found;
    }

    // ====================
    // Blueprints (Core/Modules/Area/blueprints/{type}/*.xml)

    /**
     * Bauplan eines Bereichs lesen: eine XML-Datei im Exportformat mit genau einem
     * <area> (Einstellungen und Felder, keine Datensätze). AreaBackend belegt damit
     * die Eingabemaske eines neuen Bereichs vor (create()) und legt nach dem
     * Speichern die Felder an (importBlueprint()).
     *
     * @return array|null  Bereich wie aus _xmlStructure() (mit 'fields'), null wenn
     *                     die Datei nicht lesbar ist oder keinen Bereich enthält
     */
    public function readBlueprint(string $path): ?array
    {
        if (!is_file($path)) return null;
        [$areas] = $this->_xmlStructure($path);
        if (empty($areas)) return null;
        return reset($areas);
    }

    /**
     * Felder des Bauplans in einem (gerade angelegten) Bereich erstellen und die
     * Feldbezüge des Bereichs setzen (default_field_id, sort_field_id,
     * login_name_field_id, login_password_field_id). Relationen und Pointer auf
     * andere Bereiche des Bauplans lassen sich nicht auflösen - nur Bezüge auf den
     * Bereich selbst. Fehler landen in Cms::errors.
     *
     * @return int  Anzahl angelegter Felder, -1 wenn der Bauplan nicht lesbar ist
     */
    public function importBlueprint(string $path, int $areaId): int
    {
        if (!is_file($path) || $areaId < 1) return -1;
        [$areas, , $converters, $this->fileSnippets, $this->fileLanguages] = $this->_xmlStructure($path);
        $this->pendingSnippets = [];
        if (empty($areas)) return -1;
        $area = reset($areas);
        $fileAreaId = (int)$area['area_id'];
        $this->map = ['area' => [$fileAreaId => $areaId], 'field' => [], 'template' => [], 'record' => [], 'converter' => []];
        $this->fileTemplates = [];
        $this->savedTemplates = [];
        $this->pendingTriggers = [];
        $this->fileAreas = [$fileAreaId => ['name' => (string)$area['name'], 'type' => (string)$area['type']]];
        $this->schemaMemo = [];
        // Snippet-Verweise (#modul.key) der Felder in der Sprache des Admins auflösen -
        // außer Custom-Snippets, deren Texte die Datei mitbringt (Kopie eines
        // Bereichs, areaBlueprintFile()): die übernimmt _saveField() als Verweis
        foreach ($area['fields'] as &$field) {
            foreach (['label', 'note', 'tooltip'] as $col) {
                if (!isset($field[$col]) || !str_starts_with((string)$field[$col], '#')) continue;
                if (preg_match(Snippets::REFERENCE_PATTERN, (string)$field[$col], $m) && isset($this->fileSnippets[(int)$m[1]][$m[2]])) continue;
                $field[$col] = $this->cms->textOrSnippet((string)$field[$col]);
            }
        }
        unset($field);
        $result = ['total' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0, 'inserted' => 0, 'updated' => 0, 'replaced' => 0, 'items' => []];
        // Konverter der Thumbnail-Felder: Baupläne sind fremd - Treffer nach Hash,
        // sonst anlegen, ohne Prozessor übergehen (KONVERTER)
        $this->sameDb = false;
        $used = $this->_usedConverterIds([$fileAreaId => $area], [$fileAreaId]);
        $this->_importConverters(array_intersect_key($this->converterPlan($converters, []), $used), $result);
        $count = $this->_importFields([$fileAreaId => $area], [$fileAreaId => 'insert'], $result);
        $this->_importSnippets($result);
        $messages = [];
        foreach ($result['items'] as $item) {
            if ($item['status'] !== 'failed') continue;
            $messages[] = htmlspecialchars((string)$item['label']) . $this->cms->snippet('colon') . htmlspecialchars(implode(' ', (array)$item['messages']));
        }
        $this->cms->errorsReset();
        try {
            $this->_saveAreaReferences($area, $areaId);
            $triggerResult = ['items' => []];
            $this->_applyTriggerTemplates($triggerResult);
            foreach ($triggerResult['items'] as $item) $messages = array_merge($messages, (array)$item['messages']);
        }
        catch (\Throwable $e) {
            $message = $e->getMessage();
            if (!str_starts_with($message, '#')) $this->cms->error($message);
            $messages = array_merge($messages, $this->_errorTexts());
        }
        $this->schemaMemo = [];
        $this->cms->clearFieldCaches();
        $this->cms->errorsReset();
        foreach ($messages as $message) $this->cms->error($message);
        return $count;
    }

    // ====================
    // Struktur und Templates (XML, global)

    /**
     * Bereiche, Felder und Templates der Datei importieren (siehe Klassenkommentar,
     * "Zwei Durchläufe"). Ohne globalen Auftrag werden nur die Zuordnungen der
     * Bereiche der Datei auf vorhandene Bereiche gefüllt (für die Verknüpfungen).
     *
     * @return array  ['areas' => n, 'converters' => n, 'fields' => n, 'templates' => n]
     */
    private function _importStructure(string $path, array $job, array &$result): array
    {
        $summary = ['areas' => 0, 'converters' => 0, 'fields' => 0, 'templates' => 0];
        [$areas, $templates, $converters, $this->fileSnippets, $this->fileLanguages] = $this->_xmlStructure($path);
        $this->pendingSnippets = [];
        $isGlobal = !empty($job['global']);
        $settings = $isGlobal ? (array)($job['areas'] ?? []) : [];
        $folderId = (int)($job['folder_id'] ?? 0);
        $instanceId = (int)($job['instance_id'] ?? 0);
        // Zielebene und Mandant passen zusammen (die Maske bietet nur die Ordner des
        // gewählten Mandanten an) - ein Ordner eines anderen Mandanten gilt nicht
        if ($instanceId > 0 && $folderId > 0 && (int)($this->_areaOf($folderId)['instance_id'] ?? 0) !== $instanceId) {
            $folderId = 0;
        }
        foreach ($templates as $template) {
            $this->fileTemplates[(int)$template['template_id']] = ['name' => (string)$template['name'], 'area_id' => (int)$template['area_id']];
        }
        foreach ($areas as $fileAreaId => $area) {
            $this->fileAreas[$fileAreaId] = ['name' => (string)$area['name'], 'type' => (string)$area['type'], 'default_field' => $this->_fileFieldName($area, (int)($area['default_field_id'] ?? 0))];
        }
        // abweichende Bereichsnamen aus der Maske
        foreach ($areas as $fileAreaId => &$area) {
            $override = trim((string)($settings[$fileAreaId]['name'] ?? ''));
            if ($override !== '' && preg_match('/^[0-9a-z_\-]+$/', $override)) $area['name'] = $override;
        }
        unset($area);

        // 1. Bereiche (Eltern vor Kindern)
        // --------------------
        $imported = []; // Datei-area_id => Modus, mit dem der Bereich geschrieben wurde
        foreach ($areas as $fileAreaId => $area) {
            $areaSettings = (array)($settings[$fileAreaId] ?? []);
            $mode = $isGlobal ? (string)($areaSettings['structure'] ?? 'ignore') : 'ignore';
            $by = (string)($areaSettings['structure_by'] ?? 'name');
            if (!in_array($mode, self::GLOBAL_MODES, true)) $mode = 'ignore';

            // vorhandener Bereich nach Kriterium (die Id nur aus derselben Datenbank)
            $existingId = ($by === 'area_id' && !empty($job['same_db']))
                ? $this->_resolveArea($fileAreaId, '', (string)$area['type'])
                : $this->_resolveArea(0, (string)$area['name'], (string)$area['type']);
            if ($mode === 'ignore') {
                // nicht anfassen - nur zuordnen, damit Datensätze und Templates
                // hinein können (gleicher Name, sonst - aus derselben Datenbank - gleiche Id)
                $target = $this->_resolveArea(0, (string)$area['name'], (string)$area['type']);
                if ($target < 1 && !empty($job['same_db'])) $target = $this->_resolveArea($fileAreaId, '', (string)$area['type']);
                if ($target > 0) $this->map['area'][$fileAreaId] = $target;
                continue;
            }
            $item = ['kind' => 'area', 'id' => $fileAreaId, 'label' => (string)$area['label'] . ' (' . $area['name'] . ')', 'status' => 'failed', 'messages' => []];
            $this->cms->errorsReset();
            $update = $existingId > 0 && $mode !== 'insert';
            if (!$update && !self::modeCreates($mode)) {
                $item['status'] = 'skipped';
                $item['messages'][] = $this->cms->snippet('record.note_import_not_found');
                $this->_addItem($result, $item);
                continue;
            }
            try {
                // insert legt immer neu an - auch wenn es einen gleichnamigen Bereich gibt
                $areaId = $this->_saveArea($area, $update ? $existingId : 0, $mode, $folderId, $instanceId, $item['messages']);
                $this->map['area'][$fileAreaId] = $areaId;
                $imported[$fileAreaId] = $mode;
                $item['id'] = $areaId;
                $item['status'] = $update ? (self::modeReplaces($mode) ? 'replaced' : 'updated') : 'inserted';
                $summary['areas']++;
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $item['messages'] = array_merge($item['messages'], $this->_errorTexts());
            }
            $this->_addItem($result, $item);
        }

        // 2. Konverter der Thumbnail-Felder, dann die Felder der geschriebenen
        //    Inhaltsbereiche
        // --------------------
        if (!empty($imported)) {
            $used = $this->_usedConverterIds($areas, array_keys($imported));
            $plan = array_intersect_key($this->converterPlan($converters, $job), $used);
            $summary['converters'] = $this->_importConverters($plan, $result);
            $summary['fields'] = $this->_importFields($areas, $imported, $result);
        }
        // Custom-Snippets der Beschriftungen in die Sets der Zielinstanzen
        $this->_importSnippets($result);

        // 3. Templates
        // --------------------
        $this->savedTemplates = []; // template_id (neu) => Daten der Datei (Code-Ersetzungen am Ende)
        foreach ($templates as $template) {
            $fileAreaId = (int)$template['area_id'];
            $areaSettings = (array)($settings[$fileAreaId] ?? []);
            $mode = $isGlobal ? (string)($areaSettings['templates'] ?? 'ignore') : 'ignore';
            if ($mode === 'ignore' || !in_array($mode, self::GLOBAL_MODES, true)) continue;
            $by = (string)($areaSettings['templates_by'] ?? 'name');
            $item = ['kind' => 'template', 'id' => (int)$template['template_id'], 'label' => (string)$template['label'] . ' (' . $template['name'] . ')', 'status' => 'failed', 'messages' => []];
            $this->cms->errorsReset();
            $areaId = (int)($this->map['area'][$fileAreaId] ?? 0);
            if ($areaId < 1) {
                $item['messages'][] = $this->cms->snippet('area.error_area_not_found');
                $this->_addItem($result, $item);
                continue;
            }
            $existingId = $this->_resolveTemplate($template, $areaId, $by);
            $update = $existingId > 0 && $mode !== 'insert';
            if (!$update && !self::modeCreates($mode)) {
                $item['status'] = 'skipped';
                $item['messages'][] = $this->cms->snippet('record.note_import_not_found');
                $this->_addItem($result, $item);
                continue;
            }
            try {
                $templateId = $this->_saveTemplate($template, $areaId, $existingId);
                $this->map['template'][(int)$template['template_id']] = $templateId;
                $this->_queueTemplateSnippets((string)$template['code'], (int)($areas[$fileAreaId]['instance_id'] ?? 0), (int)($this->_areaOf($areaId)['instance_id'] ?? 0));
                if (!$update) $this->_restoreCreated('template', 'template_id', $templateId, $template, !empty($job['same_db']));
                $this->savedTemplates[$templateId] = $template;
                $item['id'] = $templateId;
                $item['status'] = $update ? (self::modeReplaces($mode) ? 'replaced' : 'updated') : 'inserted';
                $summary['templates']++;
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $item['messages'] = array_merge($item['messages'], $this->_errorTexts());
            }
            $this->_addItem($result, $item);
        }
        // Custom-Snippets, die die Templates ansprechen (Kurzform custom.{key})
        $this->_importSnippets($result);

        // 4. Bereiche, zweiter Durchlauf: Default-/Sortierfeld, Templates
        // --------------------
        foreach ($imported as $fileAreaId => $mode) {
            $this->cms->errorsReset();
            try {
                $this->_saveAreaReferences($areas[$fileAreaId], (int)$this->map['area'][$fileAreaId]);
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $this->_addItem($result, ['kind' => 'area', 'id' => (int)$this->map['area'][$fileAreaId], 'label' => (string)$areas[$fileAreaId]['label'], 'status' => 'failed', 'messages' => $this->_errorTexts()]);
            }
        }
        $this->schemaMemo = [];
        $this->cms->clearFieldCaches();
        return $summary;
    }

    /**
     * <areas>, <templates>, <converters> und <snippets> der Datei lesen, dazu die
     * Sprachen des Headers.
     *
     * @return array  [areas (area_id => Daten, Baumreihenfolge), templates (Liste),
     *                 converters (converter_id der Datei => Daten),
     *                 snippets (instance_id => [schlüssel => [lang => text]]),
     *                 languages (language_id der Datei => Kürzel)]
     */
    private function _xmlStructure(string $path): array
    {
        $areas = [];
        $templates = [];
        $converters = [];
        $snippets = [];
        $languages = [];
        $reader = new \XMLReader();
        if (!@$reader->open($path)) return [$areas, $templates, $converters, $snippets, $languages];
        while (@$reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) continue;
            // die Datensätze werden hier nicht gebraucht: den ganzen Block überspringen
            if ($reader->depth === 1 && $reader->name === 'records') {
                $reader->next();
                continue;
            }
            if ($reader->depth === 1 && $reader->name === 'header') {
                $languages = (array)($this->_xmlHeader($this->_readerNode($reader))['languages'] ?? []);
                $reader->next();
                continue;
            }
            if ($reader->depth !== 2) continue;
            if ($reader->name === 'area') {
                $node = $this->_readerNode($reader);
                $area = [];
                foreach ($node->attributes() as $key => $value) $area[(string)$key] = (string)$value;
                $area['label'] = trim((string)$node->label);
                $area['note'] = trim((string)$node->note);
                $area['details'] = json_decode((string)$node->details, true) ?: [];
                $area['access'] = [];
                foreach ($node->access as $access) {
                    $area['access'][] = ['template_id' => (int)$access['template_id'], 'selectable' => (int)$access['selectable'], 'override' => (int)$access['override']];
                }
                // Trigger-Templates in ihrer Reihenfolge (<triggers><trigger template_id/>…);
                // ohne den Block bleibt der Stand des Zielbereichs
                if (isset($node->triggers)) {
                    $area['trigger_template_ids'] = [];
                    foreach ($node->triggers->trigger ?? [] as $trigger) $area['trigger_template_ids'][] = (int)$trigger['template_id'];
                }
                $area['fields'] = [];
                foreach ($node->fields->field ?? [] as $fieldNode) {
                    $field = [];
                    foreach ($fieldNode->attributes() as $key => $value) $field[(string)$key] = (string)$value;
                    foreach (['label', 'note', 'tooltip', 'pattern', 'default', 'details'] as $col) {
                        if (isset($fieldNode->{$col})) $field[$col] = (string)$fieldNode->{$col};
                    }
                    $field['rel_area_ids'] = [];
                    foreach ($fieldNode->rel_area_ids->area_id ?? [] as $relAreaId) $field['rel_area_ids'][] = (int)$relAreaId;
                    $field['source_fields'] = [];
                    // über ein link-Feld: Attribut rel_field_id (Schlüssel wie Field::sourceKey())
                    foreach ($fieldNode->source_fields->field_id ?? [] as $sourceNode) {
                        $field['source_fields'][] = Field::sourceKey((int)$sourceNode, (int)($sourceNode['rel_field_id'] ?? 0));
                    }
                    // Konverter eines Thumbnail-Feldes in ihrer Reihenfolge; ältere
                    // Dateien (bis 2026-09-15) führen sie in details.converter_ids
                    $field['converter_ids'] = [];
                    if (isset($fieldNode->converter_ids)) {
                        foreach ($fieldNode->converter_ids->converter_id ?? [] as $converterId) $field['converter_ids'][] = (int)$converterId;
                    }
                    else {
                        $details = json_decode((string)($field['details'] ?? '{}'), true) ?: [];
                        $field['converter_ids'] = array_map('intval', (array)($details['converter_ids'] ?? []));
                    }
                    $area['fields'][(int)$field['field_id']] = $field;
                }
                $areas[(int)$area['area_id']] = $area;
                $reader->next();
                continue;
            }
            if ($reader->name === 'template') {
                $node = $this->_readerNode($reader);
                $template = [];
                foreach ($node->attributes() as $key => $value) $template[(string)$key] = (string)$value;
                $template['label'] = trim((string)$node->label);
                $template['code'] = (string)$node->code;
                $templates[] = $template;
                $reader->next();
                continue;
            }
            if ($reader->name === 'converter') {
                $converter = $this->_xmlConverter($this->_readerNode($reader));
                $converters[(int)$converter['converter_id']] = $converter;
                $reader->next();
                continue;
            }
            if ($reader->name === 'snippet') {
                $node = $this->_readerNode($reader);
                $instanceId = (int)$node['instance_id'];
                $key = (string)$node['key'];
                if ($instanceId > 0 && $this->snippets->validKey($key)) {
                    foreach ($node->text as $text) {
                        $lang = (string)$text['lang'];
                        if ($lang !== '' && (string)$text !== '') $snippets[$instanceId][$key][$lang] = (string)$text;
                    }
                }
                $reader->next();
                continue;
            }
        }
        $reader->close();
        return [$areas, $templates, $converters, $snippets, $languages];
    }

    /**
     * Ein <converter> der Datei (RecordExport::_xmlConverters()).
     *
     * @return array  converter_id, hash, processor, type, extension, label,
     *                media_types_incl, media_types_excl, details (Array)
     */
    private function _xmlConverter(\SimpleXMLElement $node): array
    {
        $converter = [];
        foreach ($node->attributes() as $key => $value) $converter[(string)$key] = (string)$value;
        $converter['converter_id'] = (int)($converter['converter_id'] ?? 0);
        $converter['hash'] = (string)($converter['hash'] ?? '');
        $converter['processor'] = $this->converter->cleanProcessorName((string)($converter['processor'] ?? ''));
        $converter['extension'] = (string)($converter['extension'] ?? '');
        $converter['label'] = trim((string)$node->label);
        $converter['media_types_incl'] = (string)$node->media_types_incl;
        $converter['media_types_excl'] = (string)$node->media_types_excl;
        $converter['details'] = json_decode((string)$node->details, true) ?: [];
        // Der Hash wird aus den Einstellungen der Datei berechnet, nicht dem Attribut
        // entnommen: eine von Hand geänderte Datei (Bauplan, Template-Satz) bleibt so
        // stimmig - das Attribut dient der Lesbarkeit
        $converter['hash'] = Converter::settingsHash($converter);
        return $converter;
    }

    // ====================
    // Konverter der Thumbnail-Felder (KONVERTER)

    /**
     * Treffer der Konverter der Datei in der Zieldatenbank - für Maske und Plan.
     *
     * @param array $converters  converter_id der Datei => Daten (_xmlConverter())
     * @param bool  $sameDb      Datei stammt aus dieser Datenbank: die Id zählt
     * @return array  converter_id der Datei => Daten + 'installed' (Prozessor),
     *                'match' ('id' | 'hash' | ''), 'match_id' (converter_id, 0)
     */
    public function converterMatches(array $converters, bool $sameDb): array
    {
        $result = [];
        foreach ($converters as $fileId => $converter) {
            $fileId = (int)$fileId;
            $converter['installed'] = $this->converter->isProcessorInstalled((string)$converter['processor']);
            $converter['match'] = '';
            $converter['match_id'] = 0;
            if ($sameDb && $fileId > 0 && $this->_converterExists($fileId)) {
                $converter['match'] = 'id';
                $converter['match_id'] = $fileId;
            }
            else {
                $found = $this->converter->findByHash((string)$converter['hash']);
                if ($found > 0) {
                    $converter['match'] = 'hash';
                    $converter['match_id'] = $found;
                }
            }
            $result[$fileId] = $converter;
        }
        return $result;
    }

    /**
     * Was mit jedem Konverter der Datei geschieht: die Entscheidung der Maske
     * (Job 'converters'), sonst automatisch - Treffer verknüpfen, sonst anlegen,
     * wenn der Prozessor installiert ist, sonst ignorieren.
     *
     * @return array  converter_id der Datei => Daten (converterMatches()) +
     *                'action' ('link' | 'create' | 'ignore'), 'converter_id' (link)
     */
    public function converterPlan(array $converters, array $job): array
    {
        $plan = [];
        $choices = (array)($job['converters'] ?? []);
        foreach ($this->converterMatches($converters, !empty($job['same_db'])) as $fileId => $converter) {
            $choice = $choices[$fileId] ?? 'auto';
            $converter['action'] = 'ignore';
            $converter['converter_id'] = 0;
            if (is_numeric($choice) && (int)$choice > 0 && $this->_converterExists((int)$choice)) {
                $converter['action'] = 'link';
                $converter['converter_id'] = (int)$choice;
            }
            elseif ($choice === 'new') {
                if ($converter['installed']) $converter['action'] = 'create';
            }
            elseif ($choice !== 'ignore') {
                // automatisch
                if ((int)$converter['match_id'] > 0) {
                    $converter['action'] = 'link';
                    $converter['converter_id'] = (int)$converter['match_id'];
                }
                elseif ($converter['installed']) $converter['action'] = 'create';
            }
            $plan[$fileId] = $converter;
        }
        return $plan;
    }

    /**
     * Konverter der Datei zuordnen bzw. anlegen (vor den Feldern) und
     * $this->map['converter'] füllen - 0 für ignorierte. Angelegte Konverter
     * gehen durch Converter::save() (Prozessor-Hooks, Prüfung der Einstellungen).
     *
     * @param array $plan  converterPlan(), ggf. auf die gebrauchten Konverter beschränkt
     * @return int  Anzahl angelegter Konverter
     */
    private function _importConverters(array $plan, array &$result): int
    {
        $count = 0;
        foreach ($plan as $fileId => $converter) {
            $fileId = (int)$fileId;
            $label = (string)$converter['label'] . ' (' . (string)$converter['processor'] . ')';
            if ($converter['action'] === 'link') {
                $this->map['converter'][$fileId] = (int)$converter['converter_id'];
                continue;
            }
            if ($converter['action'] !== 'create') {
                $this->map['converter'][$fileId] = 0;
                $this->_addItem($result, ['kind' => 'converter', 'id' => $fileId, 'label' => $label, 'status' => 'skipped', 'messages' => [
                    str_replace('{processor}', (string)$converter['processor'], $this->cms->snippet($converter['installed'] ? 'record.note_import_converter_ignored' : 'record.note_import_converter_not_installed')),
                ]]);
                continue;
            }
            $item = ['kind' => 'converter', 'id' => $fileId, 'label' => $label, 'status' => 'failed', 'messages' => []];
            $this->cms->errorsReset();
            try {
                $data = $this->converter->init(['processor' => (string)$converter['processor']]);
                if (!is_array($data)) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
                foreach (['label', 'extension', 'media_types_incl', 'media_types_excl'] as $col) {
                    $data[$col] = (string)($converter[$col] ?? '');
                }
                $data['details'] = array_replace((array)($data['details'] ?? []), (array)($converter['details'] ?? []));
                $data['converter_id'] = 0;
                $converterId = (int)$this->converter->save([], $data);
                if ($converterId < 1) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
                $this->map['converter'][$fileId] = $converterId;
                $item['id'] = $converterId;
                $item['status'] = 'inserted';
                $count++;
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $item['messages'] = array_merge($item['messages'], $this->_errorTexts());
                $this->map['converter'][$fileId] = 0;
            }
            $this->_addItem($result, $item);
        }
        return $count;
    }

    /**
     * Konverter, auf die die Thumbnail-Felder dieser Bereiche der Datei verweisen.
     *
     * @param array $areas        Bereiche der Datei (_xmlStructure())
     * @param array $fileAreaIds  area_ids der Datei, deren Felder geschrieben werden
     * @return array  converter_ids der Datei (Schlüssel)
     */
    private function _usedConverterIds(array $areas, array $fileAreaIds): array
    {
        $used = [];
        foreach ($fileAreaIds as $fileAreaId) {
            foreach ((array)($areas[$fileAreaId]['fields'] ?? []) as $field) {
                if (!$this->_isThumbnail($field)) continue;
                foreach ((array)($field['converter_ids'] ?? []) as $id) {
                    if ((int)$id > 0) $used[(int)$id] = true;
                }
            }
        }
        return $used;
    }

    /**
     * converter_ids eines Thumbnail-Feldes der Datei (in ihrer Reihenfolge) auf die
     * Zieldatenbank abbilden: über $this->map['converter']; nicht in der Datei
     * geführte Konverter (ältere Exporte) gelten nur aus derselben Datenbank und nur,
     * wenn es sie gibt.
     *
     * In der Analyse steht für einen erst anzulegenden Konverter -1 in der Zuordnung
     * (analyze()) - er zählt als vorhanden, die Ids werden dort nicht geschrieben.
     *
     * @return array  converter_ids, leer wenn keiner übrig bleibt
     */
    private function _mapConverterIds(array $field, bool $sameDb): array
    {
        $ids = [];
        foreach ((array)($field['converter_ids'] ?? []) as $id) {
            $id = (int)$id;
            if ($id < 1) continue;
            if (isset($this->map['converter'][$id])) $mapped = (int)$this->map['converter'][$id];
            else $mapped = ($sameDb && $this->_converterExists($id)) ? $id : 0;
            if ($mapped !== 0) $ids[$mapped] = $mapped;
        }
        return array_values($ids);
    }

    private function _converterExists(int $converterId): bool
    {
        if ($converterId < 1) return false;
        $this->db->pushResult();
        $this->db->query("SELECT `converter_id` FROM `converter` WHERE `converter_id` = ?", 'i', [$converterId]);
        $found = (int)($this->db->fetchNum()[0] ?? 0) > 0;
        $this->db->popResult();
        return $found;
    }

    /**
     * Einen Bereich anlegen bzw. schreiben (erster Durchlauf: ohne Feld- und
     * Template-Bezüge). Neue Bereiche entstehen unter dem zugeordneten Vater der
     * Datei, sonst unter der Zielebene; die Instanz kommt vom Vater.
     *
     * @return int  area_id
     */
    private function _saveArea(array $area, int $existingId, string $mode, int $folderId, int $chosenInstanceId = 0, array &$messages = []): int
    {
        $fileParentId = (int)($area['parent_id'] ?? 0);
        $details = (array)($area['details'] ?? []);
        // Feld- und Bereichsbezüge erst im zweiten Durchlauf (die Anmeldefelder
        // älterer Dateien stehen noch in details)
        unset($details['login_name_field_id'], $details['login_password_field_id']);
        $details['profile_area_ids'] = array_values(array_filter(array_map(
            fn($id) => $this->_mapId('area', (int)$id), (array)($details['profile_area_ids'] ?? [])
        )));
        $data = [
            'type' => (string)$area['type'],
            'name' => (string)$area['name'],
            'label' => (string)$area['label'],
            'icon' => (string)($area['icon'] ?? ''),
            'note' => (string)($area['note'] ?? ''),
            'sort_order' => (string)($area['sort_order'] ?? 'DESC') ?: 'DESC',
            'dependent' => (int)!empty($area['dependent']),
            'details' => $details,
        ];
        if ($existingId > 0) {
            $previous = $this->area->load($existingId);
            if (!$previous) throw new \RuntimeException('#' . $this->cms->snippet('area.error_area_not_found'));
            if ((string)$previous['type'] !== $data['type']) {
                $this->cms->error(str_replace('{type}', (string)$previous['type'], $this->cms->snippet('record.error_import_area_type')));
                throw new \RuntimeException('#type');
            }
            $save = $previous;
            if (!self::modeReplaces($mode)) {
                // update: nur Beschriftung, Icon, Notiz, Sortierung, Details
                unset($data['type'], $data['name']);
            }
            foreach ($data as $key => $value) $save[$key] = $value;
            $save['area_id'] = $existingId;
            $this->_mapAreaTexts($save, (int)$previous['instance_id'], $existingId, $messages);
            // Datei ohne Slug-Prefix (auch ältere Exporte): der vorhandene bleibt
            if (!array_key_exists('slug_prefix', (array)$save['details']) && !empty($previous['details']['slug_prefix'])) {
                $save['details']['slug_prefix'] = $previous['details']['slug_prefix'];
            }
            // Lösch-Policy: aus derselben Datenbank die der Datei, sonst bleibt die des
            // Zielbereichs (Policy-Ids sind je Datenbank vergeben)
            if ($this->sameDb && (int)($area['privacy_policy_id'] ?? 0) > 0) {
                $save['privacy_policy_id'] = (int)$area['privacy_policy_id'];
            }
            // Template-Zuweisungen bleiben (Area::hookOnSaved() ersetzt nur übergebene),
            // bis _saveAreaReferences() sie setzt
            // Platz im Baum behalten: ohne previous_id rückte der Bereich an den Anfang
            $save['previous_id'] = $this->_areaPreviousId($previous);
            if (!$this->area->save([], $save)) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
            return $existingId;
        }
        // neu: unter dem zugeordneten Vater der Datei, sonst der Zielebene. Mandant:
        // vom importierten Vater; sonst der gewählte, sonst der der Zielebene, sonst
        // der aus der Datei (wenn es ihn gibt), sonst der erste
        $mappedParentId = $fileParentId > 0 ? (int)($this->map['area'][$fileParentId] ?? 0) : 0;
        $parentId = $mappedParentId > 0 ? $mappedParentId : $folderId;
        $instanceId = 0;
        if ($mappedParentId > 0) {
            $instanceId = (int)($this->_areaOf($mappedParentId)['instance_id'] ?? 0);
        }
        if ($instanceId < 1 && $chosenInstanceId > 0 && $this->instanceCache->instance($chosenInstanceId) !== null) {
            $instanceId = $chosenInstanceId;
        }
        if ($instanceId < 1 && $folderId > 0) {
            $instanceId = (int)($this->_areaOf($folderId)['instance_id'] ?? 0);
        }
        if ($instanceId < 1) $instanceId = (int)($area['instance_id'] ?? 0);
        if ($instanceId < 1 || $this->instanceCache->instance($instanceId) === null) {
            $instanceId = (int)array_key_first($this->instanceCache->all()) ?: 1;
        }
        if ($parentId > 0 && !$this->_areaOf($parentId)) {
            throw new \RuntimeException('#' . $this->cms->snippet('area.error_area_not_found'));
        }
        $data['area_id'] = 0;
        $data['instance_id'] = $instanceId;
        $data['parent_id'] = $parentId > 0 ? $parentId : 0;
        $data['default_field_id'] = 0;
        $data['sort_field_id'] = 0;
        $data['default_template_id'] = 0;
        $data['list_template_id'] = 0;
        $data['overview_template_id'] = 0;
        // Lösch-Policy: aus derselben Datenbank die der Datei; aus einer fremden die
        // Standard-Policy des Bereichstyps (0 - Area::hookOnSave() setzt sie, ebenso
        // bei einer Id, die es hier nicht oder nicht für die Instanz gibt)
        $data['privacy_policy_id'] = $this->sameDb ? (int)($area['privacy_policy_id'] ?? 0) : 0;
        // Reihenfolge der Datei behalten: hinter dem zuletzt angelegten Geschwister,
        // sonst hinter dem letzten vorhandenen (ohne previous_id käme jeder neue
        // Bereich an den Anfang seiner Ebene)
        if (!isset($this->lastSibling[$parentId])) {
            $this->db->pushResult();
            $this->db->query(
                "SELECT `area_id` FROM `area` WHERE `parent_id` <=> ? AND `deleted` = 0 ORDER BY `position` DESC LIMIT 1",
                'i', [$parentId > 0 ? $parentId : null]
            );
            $this->lastSibling[$parentId] = (int)($this->db->fetchNum()[0] ?? 0);
            $this->db->popResult();
        }
        $data['previous_id'] = (int)$this->lastSibling[$parentId];
        $this->_mapAreaTexts($data, $instanceId, 0, $messages);
        $areaId = $this->area->save([], $data);
        if (!$areaId) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
        $this->lastSibling[$parentId] = (int)$areaId;
        return (int)$areaId;
    }

    /**
     * Texte und Slug-Prefix eines Bereichs für die Zielinstanz aufbereiten (siehe
     * Klassenkommentar "Custom-Snippets und Slug-Prefix"): Snippet-Verweise in
     * Beschriftung und Notiz umschreiben, details.slug_prefix auf die Sprachen der
     * Zielinstanz umsetzen und prüfen - ein unzulässiger Präfix entfällt mit Hinweis.
     *
     * @param array $data        Bereichsdaten für Area::save() (label, note, details)
     * @param int   $instanceId  Zielinstanz
     * @param int   $areaId      vorhandener Bereich (0 = neu)
     * @param array $messages    Hinweise für das Protokoll
     */
    private function _mapAreaTexts(array &$data, int $instanceId, int $areaId, array &$messages): void
    {
        foreach (['label', 'note'] as $col) {
            if (isset($data[$col])) $data[$col] = $this->_mapSnippetRefs((string)$data[$col], $instanceId);
        }
        $details = (array)($data['details'] ?? []);
        if (!array_key_exists('slug_prefix', $details)) return;
        $raw = is_array($details['slug_prefix']) ? $details['slug_prefix'] : [];
        $mapped = [];
        foreach ($raw as $fileLanguageId => $prefix) {
            $languageId = (int)$fileLanguageId;
            if (!$this->sameDb) {
                $code = (string)($this->fileLanguages[$languageId] ?? '');
                $target = ($code !== '') ? $this->instanceCache->languageByCode($instanceId, $code) : null;
                if ($target === null) continue;
                $languageId = (int)$target['language_id'];
            }
            $mapped[$languageId] ??= $prefix;
        }
        $errors = $this->area->slugPrefixErrors($mapped, $instanceId, $areaId);
        if (!empty($errors)) {
            $messages[] = $this->cms->snippet('record.note_import_slug_prefix_dropped') . ' ' . $this->cms->_strip_tags(implode(' ', array_unique($errors)));
            $mapped = [];
        }
        $data['details']['slug_prefix'] = $mapped;
    }

    /**
     * Verweise auf Custom-Snippets (#custom.{instance_id}.{key}) auf das Set der
     * Zielinstanz umschreiben und die Snippets zum Übernehmen vormerken
     * ($pendingSnippets, geschrieben von _importSnippets()). Texte aus der Datei, aus
     * derselben Datenbank ersatzweise aus dem Set der Quellinstanz; ein Snippet, das
     * es nirgends gibt, bleibt als Verweis stehen.
     */
    private function _mapSnippetRefs(string $text, int $targetInstanceId): string
    {
        if ($targetInstanceId < 1 || !str_contains($text, '#custom.')) return $text;
        return preg_replace_callback(Snippets::REFERENCE_PATTERN, function (array $m) use ($targetInstanceId): string {
            $sourceId = (int)$m[1];
            $key = $m[2];
            $texts = $this->fileSnippets[$sourceId][$key] ?? null;
            if ($texts === null && $this->sameDb) {
                $this->fileSnippets[$sourceId] ??= $this->snippets->load($sourceId);
                $texts = $this->fileSnippets[$sourceId][$key] ?? null;
            }
            if ($texts === null) return $m[0];
            $this->pendingSnippets[$targetInstanceId][$key] ??= (array)$texts;
            return '#' . $this->snippets->prefix($targetInstanceId) . '.' . $key;
        }, $text) ?? $text;
    }

    /**
     * Custom-Snippets vormerken, die ein importiertes Template anspricht
     * (Snippets::templateRefs()) - Texte aus der Datei, aus derselben Installation
     * notfalls aus dem Set. Die Kurzform custom.{key} gilt für die Instanz der Seite,
     * der Quelltext bleibt deshalb unverändert.
     */
    private function _queueTemplateSnippets(string $code, int $sourceInstanceId, int $targetInstanceId): void
    {
        if ($sourceInstanceId < 1 || $targetInstanceId < 1) return;
        $set = $this->fileSnippets[$sourceInstanceId] ?? [];
        if (!$set && $this->sameDb) $set = $this->fileSnippets[$sourceInstanceId] = $this->snippets->load($sourceInstanceId);
        foreach (Snippets::templateRefs($code, $set, $sourceInstanceId) as $key) {
            $this->pendingSnippets[$targetInstanceId][$key] ??= (array)$set[$key];
        }
    }

    /**
     * Die vorgemerkten Custom-Snippets in die Sets der Zielinstanzen schreiben
     * (Snippets::merge(): nur Fehlendes) - je Set ein Protokolleintrag.
     */
    private function _importSnippets(array &$result): void
    {
        foreach ($this->pendingSnippets as $instanceId => $snippets) {
            $prefix = $this->snippets->prefix((int)$instanceId);
            $existing = $this->snippets->load((int)$instanceId);
            $this->cms->errorsReset();
            $item = ['kind' => 'snippet', 'id' => 0, 'label' => $prefix, 'status' => 'failed', 'messages' => []];
            $added = $this->snippets->merge((int)$instanceId, $snippets);
            if ($added === null) {
                $item['messages'] = $this->_errorTexts();
                $this->_addItem($result, $item);
                continue;
            }
            // vorhandene Schlüssel mit anderem Text: das Set behält seinen
            $kept = [];
            foreach ($snippets as $key => $texts) {
                if (!isset($existing[$key])) continue;
                foreach ((array)$texts as $lang => $text) {
                    if ((string)($existing[$key][$lang] ?? '') !== '' && (string)$existing[$key][$lang] !== (string)$text) {
                        $kept[] = $key;
                        break;
                    }
                }
            }
            if (empty($added) && empty($kept)) continue;
            $item['status'] = empty($added) ? 'skipped' : 'inserted';
            if (!empty($added)) $item['messages'][] = str_replace('{keys}', implode(', ', $added), $this->cms->snippet('record.note_import_snippets_added'));
            if (!empty($kept)) $item['messages'][] = str_replace('{keys}', implode(', ', $kept), $this->cms->snippet('record.note_import_snippets_kept'));
            $this->_addItem($result, $item);
        }
        $this->pendingSnippets = [];
    }

    /**
     * Zweiter Durchlauf eines Bereichs: Default-/Sortierfeld, Default-Template,
     * Template-Zuweisungen, Anmeldefelder - alles Ids, die erst jetzt feststehen.
     */
    private function _saveAreaReferences(array $area, int $areaId): void
    {
        $save = $this->area->load($areaId);
        if (!$save) return;
        $save['area_id'] = $areaId;
        $save['default_field_id'] = (int)($area['default_field_id'] ?? 0) > 0 ? $this->_mapId('field', (int)$area['default_field_id']) : 0;
        $save['sort_field_id'] = (int)($area['sort_field_id'] ?? 0) > 0 ? $this->_mapId('field', (int)$area['sort_field_id']) : 0;
        $save['default_template_id'] = (int)($area['default_template_id'] ?? 0) > 0 ? $this->_mapId('template', (int)$area['default_template_id']) : 0;
        // nur Felder / Templates, die es wirklich gibt
        if ($save['default_field_id'] > 0 && !$this->_fieldInArea($save['default_field_id'], $areaId)) $save['default_field_id'] = 0;
        if ($save['sort_field_id'] > 0 && !$this->_fieldInArea($save['sort_field_id'], $areaId)) $save['sort_field_id'] = 0;
        if ($save['default_template_id'] > 0 && !$this->_templateExists($save['default_template_id'])) $save['default_template_id'] = 0;
        // Listen- und Trigger-Template (in der Datei seit 2026-09-23; ältere Dateien
        // nennen sie nicht - dann bleibt der Stand des Zielbereichs)
        if (array_key_exists('list_template_id', $area)) {
            $listId = (int)$area['list_template_id'] > 0 ? $this->_mapId('template', (int)$area['list_template_id']) : 0;
            $save['list_template_id'] = ($listId > 0 && $this->_templateExists($listId)) ? $listId : 0;
        }
        if (array_key_exists('trigger_template_ids', $area)) {
            $this->pendingTriggers[$areaId] = array_map('intval', (array)$area['trigger_template_ids']);
        }
        // Übersichts-Template der Präfix-Adresse (in der Datei seit 2026-09-27)
        if (array_key_exists('overview_template_id', $area)) {
            $overviewId = (int)$area['overview_template_id'] > 0 ? $this->_mapId('template', (int)$area['overview_template_id']) : 0;
            $save['overview_template_id'] = ($overviewId > 0 && $this->_templateExists($overviewId)) ? $overviewId : 0;
        }
        $selectable = [];
        $override = [];
        foreach ((array)($area['access'] ?? []) as $access) {
            $templateId = $this->_mapId('template', (int)$access['template_id']);
            if (!$this->_templateExists($templateId)) continue;
            if (!empty($access['selectable'])) $selectable[] = $templateId;
            if (!empty($access['override'])) $override[] = $templateId;
        }
        $save['selectable_templates'] = $selectable;
        $save['override_templates'] = $override;
        // Anmeldefelder (Spalten seit 2026-09-14; ältere Dateien führen sie in details)
        $fileDetails = (array)($area['details'] ?? []);
        foreach (['login_name_field_id', 'login_password_field_id'] as $key) {
            $fileFieldId = (int)($area[$key] ?? $fileDetails[$key] ?? 0);
            $mapped = $fileFieldId > 0 ? $this->_mapId('field', $fileFieldId) : 0;
            $save[$key] = ($mapped > 0 && $this->_fieldInArea($mapped, $areaId)) ? $mapped : 0;
        }
        $details = is_array($save['details'] ?? null) ? $save['details'] : (json_decode((string)($save['details'] ?? ''), true) ?: []);
        unset($details['login_name_field_id'], $details['login_password_field_id']);
        $save['details'] = $details;
        $save['previous_id'] = $this->_areaPreviousId($save);
        if (!$this->area->save([], $save)) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
    }

    /**
     * Trigger-Templates der importierten Bereiche setzen ($pendingTriggers) - nach
     * den Datensätzen und dem Auflösen der Code-Markierungen.
     */
    private function _applyTriggerTemplates(array &$result): void
    {
        foreach ($this->pendingTriggers as $areaId => $fileTemplateIds) {
            $this->cms->errorsReset();
            $templateIds = [];
            foreach ($fileTemplateIds as $fileTemplateId) {
                $templateId = $fileTemplateId > 0 ? $this->_mapId('template', $fileTemplateId) : 0;
                if ($templateId > 0 && $this->_templateExists($templateId)) $templateIds[] = $templateId;
            }
            $save = $this->area->load((int)$areaId);
            if (!$save || array_map('intval', (array)($save['trigger_template_ids'] ?? [])) === $templateIds) continue;
            $save['area_id'] = (int)$areaId;
            $save['trigger_template_ids'] = $templateIds;
            $save['previous_id'] = $this->_areaPreviousId($save);
            if (!$this->area->save([], $save)) {
                $this->_addItem($result, ['kind' => 'area', 'id' => (int)$areaId, 'label' => (string)($save['label'] ?? ''), 'status' => 'failed', 'messages' => $this->_errorTexts()]);
            }
        }
        $this->pendingTriggers = [];
    }

    /**
     * Vorgänger eines Bereichs im Baum (previous_id), damit ein Update ihn nicht
     * verschiebt (TreeManagerTrait::updateInTree() vergleicht damit).
     */
    private function _areaPreviousId(array $areaRow): int
    {
        $position = (int)($areaRow['position'] ?? 0);
        if ($position < 1) return 0;
        return $this->area->getPreviousId((int)($areaRow['parent_id'] ?? 0), $position);
    }

    private function _fieldInArea(int $fieldId, int $areaId): bool
    {
        $this->db->pushResult();
        $this->db->query("SELECT 1 FROM `field` WHERE `field_id` = ? AND `area_id` = ?", 'ii', [$fieldId, $areaId]);
        $found = $this->db->num_rows > 0;
        $this->db->popResult();
        return $found;
    }

    private function _templateExists(int $templateId): bool
    {
        if ($templateId < 1) return false;
        $this->db->pushResult();
        $this->db->query("SELECT 1 FROM `template` WHERE `template_id` = ?", 'i', [$templateId]);
        $found = $this->db->num_rows > 0;
        $this->db->popResult();
        return $found;
    }

    /**
     * Felder der geschriebenen Bereiche importieren, in Abhängigkeitsreihenfolge:
     * einfache Typen, Thumbnails (nach ihrem Upload-Feld), Relationen (ein Feld je
     * Paar - Field::hookOnSaved() legt das Gegenfeld an, das dann die Angaben des
     * Gegenfeldes der Datei bekommt), Pointer (Zielbereiche), Volltext (Quellfelder).
     * Bei replace verschwinden Felder des Bereichs, die die Datei nicht nennt.
     *
     * @param array $areas     Bereiche der Datei (_xmlStructure())
     * @param array $imported  Datei-area_id => Modus der geschriebenen Bereiche
     * @return int  Anzahl geschriebener Felder
     */
    private function _importFields(array $areas, array $imported, array &$result): int
    {
        $count = 0;
        // Alle Felder der Datei (auch der nicht geschriebenen Bereiche - Gegenfelder)
        $allFields = [];
        foreach ($areas as $fileAreaId => $area) {
            foreach ((array)$area['fields'] as $fileFieldId => $field) {
                $field['file_area_id'] = $fileAreaId;
                $allFields[(int)$fileFieldId] = $field;
            }
        }
        $rank = function (array $field): int {
            $type = (string)($field['type'] ?? '');
            if ($type === 'fulltext') return 6;
            if (in_array($type, $this->cms->multiAreaTypes, true)) return 5;
            // relation1 vor relation: das Gegenfeld, das Field::hookOnSaved() anlegt,
            // ist immer vom Typ 'relation' - die "1"-Seite muss also selbst gespeichert werden
            if ($type === 'relation1') return 3;
            if ($type === 'relation') return 4;
            if ($type === 'file' && (int)($field['parent_field_id'] ?? 0) > 0) return 2;
            return 1;
        };
        $queue = [];
        foreach ($imported as $fileAreaId => $mode) {
            foreach ((array)$areas[$fileAreaId]['fields'] as $fileFieldId => $field) {
                $queue[] = (int)$fileFieldId;
            }
        }
        usort($queue, fn($a, $b) => [$rank($allFields[$a]), (int)($allFields[$a]['position'] ?? 0), $a] <=> [$rank($allFields[$b]), (int)($allFields[$b]['position'] ?? 0), $b]);

        $handled = [];
        foreach ($queue as $fileFieldId) {
            if (isset($handled[$fileFieldId])) continue;
            $field = $allFields[$fileFieldId];
            $fileAreaId = (int)$field['file_area_id'];
            $mode = (string)$imported[$fileAreaId];
            $areaId = (int)$this->map['area'][$fileAreaId];
            $item = ['kind' => 'field', 'id' => $fileFieldId, 'label' => (string)($field['label'] ?? '') . ' (' . (string)$field['name'] . ', ' . $this->_areaLabel($areaId) . ')', 'status' => 'failed', 'messages' => []];
            // Thumbnail ohne zuordenbaren Konverter: übergehen (KONVERTER)
            if ($this->_isThumbnail($field) && empty($this->_mapConverterIds($field, $this->sameDb))) {
                $handled[$fileFieldId] = true;
                $item['status'] = 'skipped';
                $item['messages'][] = $this->cms->snippet('record.note_import_thumbnail_no_converter');
                $this->_addItem($result, $item);
                continue;
            }
            // Volltext-Standardfeld: reserviert, der Core schreibt ihn ohne Eingabefeld
            // (Dateien aus der Zeit, als es noch eines war)
            if ((string)$field['name'] === Field::FULLTEXT) {
                $handled[$fileFieldId] = true;
                $item['status'] = 'skipped';
                $this->_addItem($result, $item);
                continue;
            }
            $this->cms->errorsReset();
            try {
                $existing = $this->_fieldByName($areaId, (string)$field['name']);
                $fieldId = $this->_saveField($field, $areaId, $existing, $mode, $allFields, $handled);
                $handled[$fileFieldId] = true;
                $this->map['field'][$fileFieldId] = $fieldId;
                $item['id'] = $fieldId;
                $item['status'] = $existing ? (self::modeReplaces($mode) ? 'replaced' : 'updated') : 'inserted';
                $count++;
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $item['messages'] = array_merge($item['messages'], $this->_errorTexts());
                $handled[$fileFieldId] = true;
            }
            $this->_addItem($result, $item);
        }

        // replace: Felder, die die Datei nicht nennt, entfernen
        foreach ($imported as $fileAreaId => $mode) {
            if (!self::modeReplaces($mode)) continue;
            $areaId = (int)$this->map['area'][$fileAreaId];
            $names = array_map(fn($f) => (string)$f['name'], (array)$areas[$fileAreaId]['fields']);
            $this->db->pushResult();
            $this->db->query("SELECT `field_id`, `name`, `label` FROM `field` WHERE `area_id` = ?", 'i', [$areaId]);
            $rows = $this->db->fetchAll();
            $this->db->popResult();
            foreach ($rows as $row) {
                if (in_array((string)$row['name'], $names, true)) continue;
                if (in_array((int)$row['field_id'], $this->map['field'], true)) continue;
                $this->cms->errorsReset();
                $ok = $this->field->delete((int)$row['field_id']);
                $this->_addItem($result, ['kind' => 'field', 'id' => (int)$row['field_id'], 'label' => (string)$row['label'] . ' (' . $row['name'] . ', ' . $this->_areaLabel($areaId) . ')', 'status' => $ok ? 'deleted' : 'failed', 'messages' => $ok ? [] : $this->_errorTexts()]);
            }
        }
        return $count;
    }

    private function _fieldByName(int $areaId, string $name): ?array
    {
        $this->db->pushResult();
        $this->db->query("SELECT `field_id` FROM `field` WHERE `area_id` = ? AND `name` = ?", 'is', [$areaId, $name]);
        $fieldId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        return $fieldId > 0 ? $this->field->load($fieldId) : null;
    }

    /**
     * Ein Feld anlegen bzw. schreiben. Bei Relationen wird das Gegenfeld der Datei
     * dem von Field::hookOnSaved() angelegten (bzw. dem vorhandenen) Gegenfeld
     * zugeordnet und mit dessen Angaben nachgezogen.
     *
     * @return int  field_id
     */
    private function _saveField(array $field, int $areaId, ?array $existing, string $mode, array $allFields, array &$handled): int
    {
        $type = (string)$field['type'];
        $data = [];
        foreach (self::FIELD_COLUMNS as $col) {
            if (!array_key_exists($col, $field)) continue;
            $data[$col] = $field[$col];
        }
        $data['flags'] = json_decode((string)($field['flags'] ?? '[]'), true) ?: [];
        $data['details'] = json_decode((string)($field['details'] ?? '{}'), true) ?: [];
        if (isset($data['details']['profile_area_ids'])) {
            $data['details']['profile_area_ids'] = array_values(array_filter(array_map(fn($id) => $this->_mapId('area', (int)$id), (array)$data['details']['profile_area_ids'])));
        }
        if ($this->_isThumbnail($field)) {
            // Reihenfolge der Datei bleibt (`rel_field_converter`.position)
            $data['converter_ids'] = $this->_mapConverterIds($field, $this->sameDb);
            unset($data['details']['converter_ids']);
        }
        $data['height'] = (int)($field['height'] ?? 0);
        foreach (['custom_order', 'visibility_input', 'visibility_search', 'visibility_list', 'default_field', 'meta', 'multilingual'] as $bit) {
            $data[$bit] = (int)($field[$bit] ?? 0);
        }
        if ($type === 'file' && (int)($field['parent_field_id'] ?? 0) > 0) {
            $data['parent_field_id'] = $this->_mapId('field', (int)$field['parent_field_id']);
        }
        if (str_starts_with($type, 'relation')) {
            $data['rel_area_id'] = $this->_mapId('area', (int)($field['rel_area_id'] ?? 0));
        }
        if (in_array($type, $this->cms->multiAreaTypes, true)) {
            $data['rel_area_ids'] = array_values(array_unique(array_filter(array_map(fn($id) => $this->_mapId('area', (int)$id), (array)($field['rel_area_ids'] ?? [])))));
        }
        if ($type === 'fulltext') {
            $data['source_fields'] = [];
            foreach ((array)($field['source_fields'] ?? []) as $key) {
                [$textFieldId, $relFieldId] = Field::parseSourceKey($key);
                $textFieldId = $this->_mapId('field', $textFieldId);
                $relFieldId = $relFieldId > 0 ? $this->_mapId('field', $relFieldId) : 0;
                if ($textFieldId > 0 && ($relFieldId > 0 || Field::parseSourceKey($key)[1] === 0)) {
                    $data['source_fields'][] = Field::sourceKey($textFieldId, $relFieldId);
                }
            }
            $data['source_fields'] = array_values(array_unique($data['source_fields']));
        }
        $data['area_id'] = $areaId;
        $targetInstanceId = $this->instanceCache->areaInstanceId($areaId);
        foreach (['label', 'note', 'tooltip'] as $col) {
            if (isset($data[$col])) $data[$col] = $this->_mapSnippetRefs((string)$data[$col], $targetInstanceId);
        }

        if ($existing) {
            if ((string)$existing['type'] !== $type) {
                $this->cms->error(str_replace('{type}', (string)$existing['type'], $this->cms->snippet('record.error_import_field_type')));
                throw new \RuntimeException('#type');
            }
            $save = $existing;
            unset($data['type'], $data['multilingual'], $data['meta']);
            foreach ($data as $key => $value) $save[$key] = $value;
            $save['field_id'] = (int)$existing['field_id'];
            $fieldId = (int)$existing['field_id'];
            if (!$this->field->save([], $save)) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
        }
        else {
            $data['field_id'] = 0;
            $fieldId = (int)$this->field->save([], $data);
            if ($fieldId < 1) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
        }

        // Gegenfeld einer Relation nachziehen
        if (str_starts_with($type, 'relation')) {
            $counterId = (int)($this->_fieldRow($fieldId)['rel_field_id'] ?? 0);
            $fileCounterId = (int)($field['rel_field_id'] ?? 0);
            if ($counterId > 0 && $fileCounterId > 0 && isset($allFields[$fileCounterId])) {
                $this->map['field'][$fileCounterId] = $counterId;
                $handled[$fileCounterId] = true;
                $counter = $allFields[$fileCounterId];
                $counterRow = $this->field->load($counterId);
                if ($counterRow) {
                    foreach (['name', 'label', 'display_type', 'note', 'tooltip', 'min', 'min_op', 'max', 'max_op', 'position', 'custom_order', 'visibility_input', 'visibility_search', 'visibility_list'] as $col) {
                        if (array_key_exists($col, $counter)) $counterRow[$col] = $counter[$col];
                    }
                    $counterInstanceId = $this->instanceCache->areaInstanceId((int)$counterRow['area_id']);
                    foreach (['label', 'note', 'tooltip'] as $col) {
                        if (isset($counterRow[$col])) $counterRow[$col] = $this->_mapSnippetRefs((string)$counterRow[$col], $counterInstanceId);
                    }
                    $counterRow['flags'] = json_decode((string)($counter['flags'] ?? '[]'), true) ?: [];
                    $counterRow['field_id'] = $counterId;
                    if (!$this->field->save([], $counterRow)) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
                }
            }
        }
        return $fieldId;
    }

    /** Feld der Datei ist ein Thumbnail (Upload-Feld mit display_type 'thumbnail')? */
    private function _isThumbnail(array $field): bool
    {
        return (string)($field['type'] ?? '') === 'file' && (string)($field['display_type'] ?? '') === 'thumbnail';
    }

    private function _fieldRow(int $fieldId): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `field` WHERE `field_id` = ?", 'i', [$fieldId]);
        $row = $this->db->fetch() ?: [];
        $this->db->popResult();
        return $row;
    }

    /**
     * Markierungen im Code der geschriebenen Templates auflösen (Export-Option
     * "portable", RecordExport::_markTemplateReferences()) und die Templates erneut
     * speichern (Kompilierung), wenn sich etwas geändert hat. Läuft nach den
     * Datensätzen - erst dann stehen alle Ids fest. Ohne Markierungen passiert nichts.
     */
    private function _rewriteTemplateCodes(array &$result): void
    {
        foreach ($this->savedTemplates as $templateId => $template) {
            $code = (string)$template['code'];
            if (!str_contains($code, 'CMS:REPLACE')) continue;
            $unresolved = 0;
            $areaId = (int)($this->map['area'][(int)$template['area_id']] ?? 0);
            $rewritten = $this->_resolveTemplateMarkers($code, $unresolved, $this->instanceCache->areaInstanceId($areaId));
            $this->cms->errorsReset();
            try {
                $template['code'] = $rewritten;
                $this->_saveTemplate($template, (int)($this->map['area'][(int)$template['area_id']] ?? 0), $templateId);
                $messages = [$this->cms->snippet('record.note_import_code_rewritten')];
                if ($unresolved > 0) $messages[] = str_replace('{count}', (string)$unresolved, $this->cms->snippet('record.note_import_markers_unresolved'));
                $this->_addItem($result, ['kind' => 'template', 'id' => $templateId, 'label' => (string)$template['label'] . ' (' . $template['name'] . ')', 'status' => 'updated', 'messages' => $messages]);
            }
            catch (\Throwable $e) {
                $message = $e->getMessage();
                if (!str_starts_with($message, '#')) $this->cms->error($message);
                $this->_addItem($result, ['kind' => 'template', 'id' => $templateId, 'label' => (string)$template['label'], 'status' => 'failed', 'messages' => $this->_errorTexts()]);
            }
        }
    }

    /**
     * <!-- CMS:REPLACE typ=wert [slug="…"] --> durch den tatsächlichen Wert ersetzen:
     *
     *     template_id / area_id / record_id   zugeordnete Id ($this->map, sonst dieselbe)
     *     template_name / area_name           Name des zugeordneten Templates/Bereichs
     *                                         (Bereiche können beim Import umbenannt sein)
     *     record_id mit slug                  mit importiert: die zugeordnete Id, sonst
     *                                         der Datensatz mit diesem URL-Pfad
     *                                         ([Slug-Prefix/]slug) in der Instanz des
     *                                         Templates ($templateInstanceId)
     *
     * Ist ein Ziel weder zugeordnet noch vorhanden, bleibt der Wert der Datei stehen
     * und $unresolved zählt hoch (Protokoll).
     */
    private function _resolveTemplateMarkers(string $code, int &$unresolved, int $templateInstanceId = 0): string
    {
        return preg_replace_callback(
            '/<!--\s*CMS:REPLACE\s+(template_id|template_name|area_id|area_name|record_id)=("[^"]*"|[^\s]+)(?:\s+slug=("[^"]*"|[^\s]+))?\s*-->/',
            function ($m) use (&$unresolved, $templateInstanceId) {
                $kind = $m[1];
                $value = trim($m[2], '"');
                $slug = trim($m[3] ?? '', '"');
                switch ($kind) {
                    case 'template_id':
                        $id = $this->_mapId('template', (int)$value);
                        if (!$this->_templateExists($id)) $unresolved++;
                        return (string)$id;
                    case 'area_id':
                        $id = $this->_mapId('area', (int)$value);
                        if (!$this->_areaOf($id)) $unresolved++;
                        return (string)$id;
                    case 'record_id':
                        // mit importiert: die zugeordnete Id; sonst zuerst über den
                        // Pfad (fremde Datenbank - die Id der Datei bedeutet dort nichts)
                        if (isset($this->map['record'][(int)$value])) return (string)$this->map['record'][(int)$value];
                        if ($slug !== '') {
                            $bySlug = $this->_recordIdBySlug($slug, $templateInstanceId);
                            if ($bySlug > 0) return (string)$bySlug;
                        }
                        $id = $this->_mapId('record', (int)$value);
                        if (!$this->_recordExists($id)) $unresolved++;
                        return (string)$id;
                    case 'template_name':
                        // "bereich.template": Bereichsname getrennt zuordnen (kann umbenannt sein)
                        $areaPrefix = '';
                        $templateName = $value;
                        if (str_contains($value, '.')) {
                            [$areaPrefix, $templateName] = explode('.', $value, 2);
                            foreach ($this->fileAreas as $fileAreaId => $area) {
                                if ((string)$area['name'] !== $areaPrefix) continue;
                                $mapped = (int)($this->map['area'][$fileAreaId] ?? 0);
                                if ($mapped > 0) $areaPrefix = (string)($this->_areaOf($mapped)['name'] ?? $areaPrefix);
                                break;
                            }
                            $areaPrefix .= '.';
                        }
                        $fileId = array_search($templateName, array_map(fn($t) => $t['name'], $this->fileTemplates), true);
                        if ($fileId !== false) {
                            $id = $this->_mapId('template', (int)$fileId);
                            $name = $this->_templateName($id);
                            if ($name !== '') return $areaPrefix . $name;
                        }
                        if (!$this->_templateNameExists($templateName)) $unresolved++;
                        return $areaPrefix . $templateName;
                    case 'area_name':
                        foreach ($this->fileAreas as $fileAreaId => $area) {
                            if ((string)$area['name'] !== $value) continue;
                            $mapped = (int)($this->map['area'][$fileAreaId] ?? 0);
                            if ($mapped > 0) return (string)($this->_areaOf($mapped)['name'] ?? $value);
                        }
                        if ($this->_resolveArea(0, $value, '') < 1 && $this->_resolveArea(0, $value, 'template') < 1 && $this->_resolveArea(0, $value, 'folder') < 1) $unresolved++;
                        return $value;
                }
                return $value;
            },
            $code
        ) ?? $code;
    }

    /**
     * Datensatz über seinen URL-Pfad [Slug-Prefix/]slug finden - wie ein Aufruf der
     * Seite (Cms::recordIdsBySlugPath()) in der Instanz des Templates und ihren
     * zusammengeschalteten Instanzen, je Instanz in der Hauptsprache (der Export
     * schreibt den Pfad der Hauptsprache). 0 = kein oder kein eindeutiger Treffer.
     */
    private function _recordIdBySlug(string $path, int $instanceId): int
    {
        $scope = $instanceId > 0 ? $this->instanceCache->uniqueScope($instanceId) : [];
        $languageMap = [];
        foreach ($scope ?: (array)($this->instanceCache->all()['instance_ids'] ?? []) as $id) {
            $languageMap[(int)$id] = $this->instanceCache->primaryLanguageId((int)$id);
        }
        $ids = $this->cms->recordIdsBySlugPath($path, '', $languageMap, array_keys($languageMap));
        return count($ids) === 1 ? (int)$ids[0] : 0;
    }

    private function _recordExists(int $recordId): bool
    {
        if ($recordId < 1) return false;
        $this->db->pushResult();
        $this->db->query("SELECT 1 FROM `record` WHERE `record_id` = ? AND `deleted` = 0", 'i', [$recordId]);
        $found = $this->db->num_rows > 0;
        $this->db->popResult();
        return $found;
    }

    private function _templateName(int $templateId): string
    {
        $this->db->pushResult();
        $this->db->query("SELECT `name` FROM `template` WHERE `template_id` = ?", 'i', [$templateId]);
        $name = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->popResult();
        return $name;
    }

    private function _templateNameExists(string $name): bool
    {
        $this->db->pushResult();
        $this->db->query("SELECT 1 FROM `template` WHERE `name` = ? LIMIT 1", 's', [$name]);
        $found = $this->db->num_rows > 0;
        $this->db->popResult();
        return $found;
    }

    /**
     * Vorhandenes Template nach Kriterium: Name im Zielbereich oder template_id.
     */
    private function _resolveTemplate(array $template, int $areaId, string $by): int
    {
        $this->db->pushResult();
        if ($by === 'template_id') {
            $this->db->query("SELECT `template_id` FROM `template` WHERE `template_id` = ?", 'i', [(int)$template['template_id']]);
        }
        else {
            $this->db->query("SELECT `template_id` FROM `template` WHERE `area_id` = ? AND `name` = ?", 'is', [$areaId, (string)$template['name']]);
        }
        $id = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        return $id;
    }

    /**
     * Ein Template anlegen bzw. schreiben - Template::save() kompiliert den Code.
     *
     * @return int  template_id
     */
    private function _saveTemplate(array $template, int $areaId, int $existingId): int
    {
        $data = [
            'area_id' => $areaId,
            'name' => (string)$template['name'],
            'label' => (string)$template['label'],
            'code' => (string)$template['code'],
            'type' => (string)($template['type'] ?? ''),
            'standalone' => (int)($template['standalone'] ?? 0),
        ];
        if ($existingId > 0) {
            $save = $this->template->load($existingId) ?: [];
            foreach ($data as $key => $value) $save[$key] = $value;
            $save['template_id'] = $existingId;
            if (!$this->template->save([], $save)) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
            return $existingId;
        }
        $data['template_id'] = 0;
        $id = (int)$this->template->save([], $data);
        if ($id < 1) throw new \RuntimeException('#' . $this->cms->errorMsgLast());
        return $id;
    }

    // ====================
    // XML lesen

    /**
     * Datensätze der XML-Datei als Generator. Zielbereich, Modus und
     * Update-Kriterium je Eintrag: global aus $job['areas'] (Bereiche der Datei sind
     * über $this->map['area'] zugeordnet - _importStructure()); im Bereich nur die
     * Datensätze des gewählten Quellbereichs der Datei (source_area_id), Ziel ist
     * der aufrufende Bereich, Modus $job['mode'].
     */
    private function _xmlEntries(string $path, array $job): \Generator
    {
        $reader = new \XMLReader();
        if (!@$reader->open($path)) return;
        $header = null;
        $isGlobal = !empty($job['global']);
        $currentAreaId = (int)($job['area_id'] ?? 0);
        $sourceAreaId = (int)($job['source_area_id'] ?? 0);
        while (@$reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) continue;
            if ($reader->depth === 1 && $reader->name === 'header') {
                $header = $this->_xmlHeader($this->_readerNode($reader));
                $reader->next();
                continue;
            }
            if ($reader->depth === 2 && $reader->name === 'record') {
                $node = $this->_readerNode($reader);
                $originAreaId = (int)$node['area_id'];
                $entry = ['id' => (int)$node['record_id'], 'area_id' => $originAreaId, 'label' => '', 'data' => []];
                if ($isGlobal) {
                    $settings = (array)($job['areas'][$originAreaId] ?? []);
                    $mode = (string)($settings['records'] ?? 'ignore');
                    if ($mode === 'ignore' || !in_array($mode, self::MODES, true)) {
                        $reader->next();
                        continue;
                    }
                    $entry['mode'] = $mode;
                    $entry['by'] = (string)($settings['records_by'] ?? 'slug');
                    $targetAreaId = (int)($this->map['area'][$originAreaId] ?? 0);
                }
                else {
                    // nur der gewählte Quellbereich (ohne Angabe: alle) - hierher
                    if ($sourceAreaId > 0 && $originAreaId !== $sourceAreaId) {
                        $reader->next();
                        continue;
                    }
                    $targetAreaId = $currentAreaId;
                }
                if ($targetAreaId < 1) {
                    $entry['error'] = $this->cms->snippet('area.error_area_not_found');
                    // Beschriftung aus dem Default-Feld der Datei (Analyse: Bereich entsteht erst)
                    $defaultField = (string)($this->fileAreas[$originAreaId]['default_field'] ?? '');
                    foreach ($defaultField !== '' ? $node->field : [] as $field) {
                        if ((string)$field['name'] !== $defaultField) continue;
                        $entry['label'] = trim((string)($field->value[0] ?? ''));
                        break;
                    }
                }
                else {
                    $entry['data'] = $this->_xmlRecordData($node, $header ?? [], $targetAreaId, $job);
                    $entry['label'] = $this->_labelOf($targetAreaId, $entry['data']['values']);
                }
                yield $entry;
                $reader->next();
            }
        }
        $reader->close();
    }

    /**
     * Ein <record>-Element in die Importform (siehe _importOne()) bringen: Felder
     * nach Name auf das Zielschema, Sprachen nach Kürzel auf die Instanz.
     */
    private function _xmlRecordData(\SimpleXMLElement $node, array $header, int $areaId, array $job): array
    {
        $schema = $this->_schemaOf($areaId);
        $langCtx = $this->_languagesOf($areaId);
        $languageIds = $langCtx['language_ids'];
        $primaryId = $langCtx['primary_id'];
        // Sprache der Datei -> Sprache der Instanz (über das Kürzel)
        $xmlLanguages = (array)($header['languages'] ?? []);
        $xmlPrimary = (int)($header['primary_language_id'] ?? 0);
        // Format 2: die Hauptsprache der Instanz, aus der der Datensatz stammt
        $fileInstanceId = (int)($node['instance_id'] ?? 0);
        if ($fileInstanceId > 0 && isset($header['primary_by_instance'][$fileInstanceId])) {
            $xmlPrimary = (int)$header['primary_by_instance'][$fileInstanceId];
        }
        $languageMap = [];
        foreach ($xmlLanguages as $xmlId => $code) {
            $target = $this->instanceCache->languageByCode((int)$langCtx['instance_id'], (string)$code);
            if ($target !== null) $languageMap[(int)$xmlId] = (int)$target['language_id'];
        }
        $data = ['area_id' => $areaId, 'values' => [], 'stored' => [], 'prehashed' => [], 'relations' => [], 'files' => [], 'meta' => []];

        // Meta-Spalten aus den Attributen (created_at und ORIGIN_FIELDS nur für
        // _restoreCreated())
        foreach (array_merge(self::META_FIELDS, ['created_at'], self::ORIGIN_FIELDS) as $name) {
            if (!isset($node[$name])) continue;
            $data['meta'][$name] = (string)$node[$name];
        }

        foreach ($node->field as $field) {
            $name = (string)$field['name'];
            $type = (string)$field['type'];
            $colDef = $schema[$name] ?? null;
            if ($colDef === null) continue;
            $targetType = (string)($colDef['type'] ?? '');
            if ($type !== $targetType && !($this->_sameRelationKind($type, $targetType))) continue;

            if (in_array($targetType, $this->cms->relationTypes, true)) {
                $ids = [];
                foreach ($field->relation as $relation) $ids[] = (int)$relation['dest_id'];
                $data['relations'][$name] = $ids;
                continue;
            }
            if ($targetType === 'file') {
                $files = [];
                $anyPath = false;
                foreach ($field->file as $file) {
                    $path = (string)$file['path'];
                    $fileLang = (string)$file['file_language_id'];
                    $rowLang = (int)$file['language_id'];
                    if ((string)$file['extension'] === '') continue;
                    // nur eigene Dateien (Fallback-Zeilen zeigen auf dieselbe Datei)
                    if ($fileLang !== '' && (int)$fileLang !== $rowLang) continue;
                    $langKey = '';
                    if ($this->cms->isMultilingualField($colDef)) {
                        $targetLang = $languageMap[$rowLang] ?? ($rowLang === $xmlPrimary ? $primaryId : null);
                        if ($targetLang === null) continue;
                        $langKey = (string)$targetLang;
                    }
                    if ($path === '') continue; // Export ohne Dateien: nichts ändern
                    $anyPath = true;
                    $absolute = $this->_importFilePath($job, $path);
                    $files[$langKey] = $absolute === null ? null : ['importfile' => $absolute, 'filename' => (string)$file['filename']];
                    if (!$this->cms->isMultilingualField($colDef)) break;
                }
                if ($anyPath) $data['files'][$name] = $files;
                continue;
            }

            // Skalare Werte je Sprache
            $values = [];
            $fallback = [];
            foreach ($field->value as $value) {
                $xmlLang = isset($value['language_id']) ? (int)$value['language_id'] : $xmlPrimary;
                $text = (string)$value;
                if ((string)$value['encoding'] === 'base64') $text = base64_decode($text, true) ?: '';
                if ($targetType === 'encrypted') $text = $this->crypt->decrypt($text, '', true);
                $values[$xmlLang] = $text;
                if ((string)$value['empty'] === '1') $fallback[$xmlLang] = true;
            }
            if (empty($values)) continue;
            $primaryValue = $values[$xmlPrimary] ?? reset($values);
            if ($this->cms->isMultilingualField($colDef)) {
                $value = [];
                foreach ($languageIds as $languageId) {
                    $xmlLang = array_search($languageId, $languageMap, true);
                    if ($languageId === $primaryId) {
                        $value[$languageId] = ($xmlLang !== false && isset($values[$xmlLang])) ? $values[$xmlLang] : $primaryValue;
                    }
                    else {
                        // Fallback der Datei = keine eigene Übersetzung -> leer
                        $value[$languageId] = ($xmlLang !== false && isset($values[$xmlLang]) && empty($fallback[$xmlLang])) ? $values[$xmlLang] : '';
                    }
                }
            }
            else {
                $value = $primaryValue;
            }
            if ($targetType === 'json') {
                $value = is_array($value) ? array_map(fn($v) => $this->_jsonValue($v), $value) : $this->_jsonValue($value);
            }
            $data['values'][$name] = $value;
            if ($targetType === 'password') {
                $data['prehashed'][] = $name;
            }
            elseif ($targetType !== 'encrypted') {
                $data['stored'][] = $name;
            }
        }
        return $data;
    }

    private function _sameRelationKind(string $a, string $b): bool
    {
        return in_array($a, $this->cms->relationTypes, true) && in_array($b, $this->cms->relationTypes, true)
            && (str_starts_with($a, 'relation') === str_starts_with($b, 'relation'));
    }

    private function _jsonValue(mixed $value): mixed
    {
        if (is_array($value)) return $value;
        $value = (string)$value;
        if ($value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [$value];
    }

    /**
     * Absoluter Pfad einer Datei aus der Importdatei (media/…), null wenn sie fehlt.
     */
    private function _importFilePath(array $job, string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || preg_match('!(^|/)\.\.(/|$)!', $relative)) return null;
        $base = (string)($job['dir_path'] ?? '');
        $candidates = [$base . '/' . $relative, dirname($base . '/' . (string)$job['file']) . '/' . $relative];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) return realpath($candidate) ?: null;
        }
        return null;
    }

    // ====================
    // CSV lesen

    /**
     * Zeilen einer CSV-Datei (eigener Parser: Einfassungszeichen dürfen
     * Zeilenumbrüche und verdoppelte Einfassungszeichen enthalten, der
     * Zeilenumbruch ist frei wählbar). Eine UTF-8-BOM wird übergangen.
     *
     * @return array  Zeilen als Arrays von Zellen
     */
    private function _csvRows(string $path, array $settings): array
    {
        $content = (string)file_get_contents($path);
        if (str_starts_with($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
        $separator = $this->csvChar((string)($settings['separator'] ?? ';'), ';');
        $enclosure = $this->csvChar((string)($settings['enclosure'] ?? '"'), '"');
        $eol = $this->csvEol((string)($settings['eol'] ?? '\r\n'));
        $eolFirst = $eol[0];
        $eolLen = strlen($eol);

        $rows = [];
        $row = [];
        $cell = '';
        $inQuotes = false;
        $length = strlen($content);
        $i = 0;
        while ($i < $length) {
            $char = $content[$i];
            if ($inQuotes) {
                if ($char === $enclosure) {
                    if ($i + 1 < $length && $content[$i + 1] === $enclosure) {
                        $cell .= $enclosure;
                        $i += 2;
                        continue;
                    }
                    $inQuotes = false;
                    $i++;
                    continue;
                }
                $cell .= $char;
                $i++;
                continue;
            }
            if ($char === $enclosure && $cell === '') {
                $inQuotes = true;
                $i++;
                continue;
            }
            if ($char === $separator) {
                $row[] = $cell;
                $cell = '';
                $i++;
                continue;
            }
            if ($char === $eolFirst && substr($content, $i, $eolLen) === $eol) {
                $row[] = $cell;
                $rows[] = $row;
                $row = [];
                $cell = '';
                $i += $eolLen;
                continue;
            }
            // fremder Zeilenumbruch (\n statt \r\n): ebenfalls als Zeilenende nehmen
            if (($char === "\n" || $char === "\r") && $eol !== $char) {
                if ($char === "\r" && $i + 1 < $length && $content[$i + 1] === "\n") $i++;
                $row[] = $cell;
                $rows[] = $row;
                $row = [];
                $cell = '';
                $i++;
                continue;
            }
            $cell .= $char;
            $i++;
        }
        if ($cell !== '' || !empty($row)) {
            $row[] = $cell;
            $rows[] = $row;
        }
        // leere Zeilen (nur eine leere Zelle) übergehen
        return array_values(array_filter($rows, fn($r) => count($r) > 1 || trim((string)($r[0] ?? '')) !== ''));
    }

    public function csvChar(string $input, string $default): string
    {
        $input = strtr($input, ['\t' => "\t", '\\t' => "\t"]);
        if ($input === '') return $default;
        return mb_substr($input, 0, 1);
    }

    public function csvEol(string $input): string
    {
        $eol = strtr($input, ['\r' => "\r", '\n' => "\n", '\\r' => "\r", '\\n' => "\n"]);
        return trim($eol, ' ') === '' ? "\r\n" : $eol;
    }

    /**
     * Zeilen der CSV-Datei als Generator (die Zuordnung 'mapping' macht daraus
     * Feldwerte; skip übergeht die Kopfzeile).
     */
    private function _csvEntries(string $path, array $job): \Generator
    {
        $settings = (array)($job['csv'] ?? []);
        $mapping = (array)($job['mapping'] ?? []);
        $areaId = (int)($job['area_id'] ?? 0);
        $stored = !empty($settings['stored']);
        foreach ($this->_csvRows($path, $settings) as $row) {
            $data = $this->_csvRecordData($row, $mapping, $areaId, $stored, $job);
            $error = '';
            foreach ($data['links'] as $link) {
                if ((string)($link['error'] ?? '') !== '') { $error = (string)$link['error']; break; }
            }
            yield [
                'id' => (int)($data['meta']['record_id'] ?? 0),
                'area_id' => $areaId,
                'label' => $this->_labelOf($areaId, $data['values']),
                'data' => $data,
            ] + ($error !== '' ? ['error' => $error] : []);
        }
    }

    /**
     * Eine CSV-Zeile in die Importform bringen (Zielschlüssel siehe csvTargets()).
     */
    private function _csvRecordData(array $row, array $mapping, int $areaId, bool $stored, array $job): array
    {
        $schema = $this->_schemaOf($areaId);
        $langCtx = $this->_languagesOf($areaId);
        $primaryId = $langCtx['primary_id'];
        $data = ['area_id' => $areaId, 'values' => [], 'stored' => [], 'prehashed' => [], 'relations' => [], 'files' => [], 'meta' => [], 'links' => []];
        $linkCells = [];
        foreach ($mapping as $index => $key) {
            $key = (string)$key;
            if ($key === '' || !array_key_exists($index, $row)) continue;
            $value = (string)$row[$index];
            // Spalte eines link-Feldes: "{link}.{…}" - erst sammeln, die Inhalte
            // entstehen, wenn alle Spalten gelesen sind (_csvLinks())
            $dot = strpos($key, '.');
            if ($dot !== false && isset($schema[substr($key, 0, $dot)])
                && in_array((string)($schema[substr($key, 0, $dot)]['type'] ?? ''), $this->cms->dependentTypes, true)
            ) {
                $linkCells[substr($key, 0, $dot)][substr($key, $dot + 1)] = $value;
                continue;
            }
            if ($key === 'record_id' || in_array($key, self::META_FIELDS, true) || in_array($key, self::ORIGIN_FIELDS, true)) {
                if (isset($schema[$key]) && empty($schema[$key]['meta'])) {
                    // gleichnamiges Eingabefeld (kein Meta-Feld) - unten als Wert
                }
                else {
                    $data['meta'][$key] = trim($value);
                    continue;
                }
            }
            $name = $key;
            $languageId = null;
            if (!isset($schema[$name])) {
                $dot = strrpos($key, '.');
                if ($dot === false) continue;
                $name = substr($key, 0, $dot);
                $suffix = substr($key, $dot + 1);
                if (!isset($schema[$name])) continue;
                if ($suffix === 'record_id') {
                    $ids = array_filter(array_map('trim', explode('|', $value)), fn($v) => $v !== '');
                    $data['relations'][$name] = array_map('intval', $ids);
                    continue;
                }
                $language = $this->instanceCache->languageByCode((int)$langCtx['instance_id'], $suffix);
                if ($language === null) continue;
                $languageId = (int)$language['language_id'];
            }
            $colDef = $schema[$name];
            $type = (string)($colDef['type'] ?? '');
            if ($type === 'fulltext') continue;
            if (in_array($type, $this->cms->relationTypes, true)) {
                $ids = array_filter(array_map('trim', explode('|', $value)), fn($v) => $v !== '');
                $data['relations'][$name] = array_map('intval', $ids);
                continue;
            }
            if ($type === 'file') {
                if (!empty($colDef['parent_field_id'])) continue;
                $value = trim($value);
                $langKey = ($languageId === null) ? '' : (string)$languageId;
                if ($value === '') {
                    // leere Zelle: bei replace wird die Datei entfernt (nicht genannt)
                    continue;
                }
                $absolute = $this->_importFilePath($job, $value);
                $filename = preg_replace('/^\d+_\d+_\d+-/', '', basename(str_replace('\\', '/', $value))) ?? basename($value);
                $data['files'][$name][$langKey] = $absolute === null ? null : ['importfile' => $absolute, 'filename' => $filename];
                continue;
            }
            if ($type === 'json') $value = $this->_jsonValue($value);
            if ($this->cms->isMultilingualField($colDef)) {
                $languageId ??= $primaryId;
                if (!isset($data['values'][$name]) || !is_array($data['values'][$name])) $data['values'][$name] = [];
                $data['values'][$name][$languageId] = $value;
            }
            else {
                $data['values'][$name] = $value;
            }
            if ($type === 'password') {
                if ((string)$value !== '' && !in_array($name, $data['prehashed'], true)) $data['prehashed'][] = $name;
            }
            elseif ($stored && $type !== 'encrypted' && !in_array($name, $data['stored'], true)) {
                $data['stored'][] = $name;
            }
        }
        // Datei-Spalten je Sprache: der CSV-Export schreibt in jede Sprachspalte den
        // aufgelösten Pfad - bei einer Sprache ohne eigene Datei den der Hauptsprache.
        // Dieselbe Datei noch einmal für die Sprache anzulegen, machte aus dem
        // Fallback eine eigene Datei; deshalb: gleicher Pfad wie die Hauptsprache = weg
        foreach ($data['files'] as $name => $langFiles) {
            $primaryKey = (string)$primaryId;
            $primaryFile = (string)($langFiles[$primaryKey]['importfile'] ?? '');
            if ($primaryFile === '') continue;
            foreach ($langFiles as $langKey => $fileData) {
                if ((string)$langKey === $primaryKey) continue;
                if ((string)($fileData['importfile'] ?? '') === $primaryFile) unset($data['files'][$name][$langKey]);
            }
        }
        // eingebettete Inhalte
        foreach ($linkCells as $name => $cells) {
            $data['links'][$name] = $this->_csvLinks($schema[$name], $cells, $langCtx, $stored, $job);
        }
        // leere Passwort-Zellen: Feld nicht anfassen
        foreach ($data['prehashed'] as $name) {
            if ((string)($data['values'][$name] ?? '') === '') unset($data['values'][$name]);
        }
        foreach ($schema as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') === 'password' && isset($data['values'][$name]) && (string)$data['values'][$name] === '') {
                unset($data['values'][$name]);
            }
        }
        return $data;
    }

    /**
     * Die Spalten eines link-Feldes einer CSV-Zeile -> eingebettete Inhalte
     * (EINGEBETTETE INHALTE). Je Inhalt ein Wert, mit " | " getrennt; die Anzahl
     * der Inhalte gibt die Spalte record_id vor (sonst die Spalte mit den meisten
     * Werten). Bei genau einem Inhalt wird nicht getrennt - ein Text darf dann
     * " | " enthalten. Passt die Anzahl einer Spalte nicht, trägt das Ergebnis
     * 'error' (der Datensatz wird nicht importiert).
     *
     * @param array $cells  Unterschlüssel (record_id, area_id, {feld}, {feld}.{code}) => Zelle
     * @return array  ['entries' => [['id' => int|null, 'area_id', 'values', 'files',
     *                'stored', 'prehashed'], …], 'error' => string]
     */
    private function _csvLinks(array $colDef, array $cells, array $langCtx, bool $stored, array $job): array
    {
        $result = ['entries' => [], 'error' => ''];
        $label = $this->cms->textOrSnippet((string)($colDef['label'] ?? $colDef['name'] ?? ''));
        $targetFields = $this->record->linkTargetFields($colDef);
        $split = fn(string $value) => $value === '' ? [] : explode(' | ', $value);
        $hasIds = array_key_exists('record_id', $cells);
        if ($hasIds) {
            $ids = array_values(array_filter(array_map('trim', explode('|', (string)$cells['record_id'])), fn($v) => $v !== ''));
            $count = count($ids);
        }
        else {
            $count = 0;
            foreach ($cells as $cell) $count = max($count, count($split((string)$cell)));
        }
        // Spalten -> [feld, Sprache] und Werte je Inhalt
        $columns = [];
        foreach ($cells as $sub => $cell) {
            $sub = (string)$sub;
            if ($sub === 'record_id') continue;
            $field = $sub;
            $languageId = null;
            if ($sub !== 'area_id' && !isset($targetFields[$sub])) {
                $dot = strrpos($sub, '.');
                if ($dot === false) continue;
                $field = substr($sub, 0, $dot);
                $language = $this->instanceCache->languageByCode((int)$langCtx['instance_id'], substr($sub, $dot + 1));
                if (!isset($targetFields[$field]) || $language === null) continue;
                $languageId = (int)$language['language_id'];
            }
            $parts = $count <= 1 ? ($cell === '' ? [] : [(string)$cell]) : $split((string)$cell);
            if (empty($parts)) $parts = array_fill(0, $count, '');
            if (count($parts) !== $count) {
                $result['error'] = str_replace(['{field}', '{count}'], ["$label.$sub", $count], $this->cms->snippet('record.error_import_embedded_count'));
                return $result;
            }
            $columns[] = ['field' => $field, 'language_id' => $languageId, 'parts' => $parts];
        }
        for ($i = 0; $i < $count; $i++) {
            $entry = [
                'id' => $hasIds ? (int)$ids[$i] : null,
                'area_id' => 0,
                'values' => [], 'files' => [], 'stored' => [], 'prehashed' => [],
            ];
            foreach ($columns as $column) {
                $field = $column['field'];
                $value = (string)$column['parts'][$i];
                if ($field === 'area_id') {
                    $entry['area_id'] = (int)trim($value);
                    continue;
                }
                $targetDef = $targetFields[$field];
                $type = (string)($targetDef['type'] ?? '');
                $languageId = $column['language_id'];
                if ($type === 'file') {
                    if (!empty($targetDef['parent_field_id'])) continue; // Thumbnail
                    $value = trim($value);
                    if ($value === '') continue;
                    $langKey = ($languageId === null || !$this->cms->isMultilingualField($targetDef)) ? '' : (string)$languageId;
                    $absolute = $this->_importFilePath($job, $value);
                    $filename = preg_replace('/^\d+_\d+_\d+-/', '', basename(str_replace('\\', '/', $value))) ?? basename($value);
                    $entry['files'][$field][$langKey] = $absolute === null ? null : ['importfile' => $absolute, 'filename' => $filename];
                    continue;
                }
                if ($type === 'json') $value = $this->_jsonValue($value);
                if ($this->cms->isMultilingualField($targetDef)) {
                    $entry['values'][$field] = (array)($entry['values'][$field] ?? []);
                    $entry['values'][$field][$languageId ?? (int)$langCtx['primary_id']] = $value;
                }
                else {
                    $entry['values'][$field] = $value;
                }
                if ($type === 'password') {
                    if ((string)$value === '') unset($entry['values'][$field]);
                    elseif (!in_array($field, $entry['prehashed'], true)) $entry['prehashed'][] = $field;
                }
                elseif ($stored && $type !== 'encrypted' && !in_array($field, $entry['stored'], true)) {
                    $entry['stored'][] = $field;
                }
            }
            // Datei je Sprache mit dem Pfad der Hauptsprache: Fallback, keine eigene Datei
            foreach ($entry['files'] as $field => $langFiles) {
                $primaryFile = (string)($langFiles[(string)$langCtx['primary_id']]['importfile'] ?? '');
                if ($primaryFile === '') continue;
                foreach ($langFiles as $langKey => $fileData) {
                    if ((string)$langKey !== (string)$langCtx['primary_id'] && (string)($fileData['importfile'] ?? '') === $primaryFile) {
                        unset($entry['files'][$field][$langKey]);
                    }
                }
            }
            $result['entries'][] = $entry;
        }
        return $result;
    }

    // ====================
    // DOCX lesen

    /**
     * Tabellen des Word-Dokuments (RecordExport::_exportDocx()) -> Datensätze.
     *
     * @return array|null  [['record_id', 'language_id', 'source_language_id', 'fields' => [name => text],
     *                     'links' => [link => [record_id => [feld => text]]]], …]
     */
    private function _docxRecords(string $path, ?string &$error = null): ?array
    {
        $error = '';
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) return null;
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) return null;
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml)) return null;
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $records = [];
        foreach ($dom->getElementsByTagNameNS($ns, 'tbl') as $table) {
            $rows = [];
            foreach ($table->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE || $child->localName !== 'tr') continue;
                $cell = null;
                foreach ($child->childNodes as $tc) {
                    if ($tc->nodeType === XML_ELEMENT_NODE && $tc->localName === 'tc') { $cell = $tc; break; }
                }
                if ($cell === null) continue;
                $paragraphs = [];
                foreach ($cell->childNodes as $p) {
                    if ($p->nodeType !== XML_ELEMENT_NODE || $p->localName !== 'p') continue;
                    $paragraphs[] = $this->_docxParagraphText($p);
                }
                $rows[] = implode("\n", $paragraphs);
            }
            if (empty($rows) || !preg_match('/record_id=(\d+)/', $rows[0], $m)) continue;
            $record = ['record_id' => (int)$m[1], 'language_id' => 0, 'source_language_id' => 0, 'fields' => [], 'links' => []];
            if (preg_match('/(?<![a-z_])language_id=(\d+)/', $rows[0], $m)) $record['language_id'] = (int)$m[1];
            if (preg_match('/source_language_id=(\d+)/', $rows[0], $m)) $record['source_language_id'] = (int)$m[1];
            for ($i = 1; $i + 1 < count($rows); $i += 2) {
                if (!preg_match('/^field=([a-z0-9_\-]+)(?:\.([a-z0-9_\-]+))?/', $rows[$i], $m)) { $i--; continue; }
                if (!empty($m[2])) {
                    // Feld eines eingebetteten Inhalts: "field={link}.{feld} | record_id={id}"
                    $destId = preg_match('/record_id=(\d+)/', $rows[$i], $mm) ? (int)$mm[1] : 0;
                    $record['links'][$m[1]][$destId][$m[2]] = $rows[$i + 1];
                    continue;
                }
                $record['fields'][$m[1]] = $rows[$i + 1];
            }
            $records[] = $record;
        }
        return $records;
    }

    private function _docxParagraphText(\DOMNode $paragraph): string
    {
        $text = '';
        $walker = function (\DOMNode $node) use (&$walker, &$text) {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                switch ($child->localName) {
                    case 't': $text .= $child->textContent; break;
                    case 'tab': $text .= "\t"; break;
                    case 'br': case 'cr': $text .= "\n"; break;
                    case 'delText': break;
                    default: $walker($child);
                }
            }
        };
        $walker($paragraph);
        return $text;
    }

    /**
     * Datensätze der DOCX-Datei als Generator: Update in der Zielsprache.
     */
    private function _docxEntries(string $path, array $job): \Generator
    {
        $areaId = (int)($job['area_id'] ?? 0);
        $schema = $this->_schemaOf($areaId);
        $options = (array)($job['docx'] ?? []);
        $records = $this->_docxRecords($path) ?? [];
        foreach ($records as $record) {
            $languageId = (int)$record['language_id'];
            $data = ['area_id' => $areaId, 'values' => [], 'stored' => [], 'prehashed' => [], 'relations' => [], 'files' => [], 'meta' => [], 'links' => []];
            foreach ($record['fields'] as $name => $text) {
                $colDef = $schema[$name] ?? null;
                if ($colDef === null || !$this->cms->isMultilingualField($colDef)) continue;
                $data['values'][$name] = [$languageId => $this->docxText($text, $options)];
            }
            // eingebettete Inhalte: nur die genannten übersetzen (partial)
            foreach ((array)($record['links'] ?? []) as $linkName => $dests) {
                $colDef = $schema[$linkName] ?? null;
                if ($colDef === null || !in_array((string)($colDef['type'] ?? ''), $this->cms->dependentTypes, true)) continue;
                $targetFields = $this->record->linkTargetFields($colDef);
                $entries = [];
                foreach ($dests as $destId => $subs) {
                    $entry = ['id' => (int)$destId, 'area_id' => 0, 'values' => [], 'files' => [], 'stored' => [], 'prehashed' => []];
                    foreach ($subs as $sub => $text) {
                        $targetDef = $targetFields[$sub] ?? null;
                        if ($targetDef === null || !$this->cms->isMultilingualField($targetDef)) continue;
                        $entry['values'][$sub] = [$languageId => $this->docxText($text, $options)];
                    }
                    if (!empty($entry['values'])) $entries[] = $entry;
                }
                $data['links'][$linkName] = ['entries' => $entries, 'partial' => true];
            }
            yield [
                'id' => (int)$record['record_id'],
                'area_id' => $areaId,
                'label' => '',
                'data' => $data,
            ];
        }
    }

    /**
     * Übersetzten Text nach den DOCX-Optionen aufbereiten:
     *   punct  Satzzeichen neben CJK-Zeichen in ideografische umwandeln
     *   space  Leerzeichen zwischen CJK-Zeichen in das ideografische Leerzeichen
     *   rtl    Abschnitte in Rechts-nach-links-Schrift markieren: 'unicode'
     *          (RLI … PDI), 'html' (<span dir="rtl">), 'none'
     */
    public function docxText(string $text, array $options): string
    {
        $cjk = '\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}';
        if (!empty($options['punct'])) {
            $map = [',' => '，', '.' => '。', '!' => '！', '?' => '？', ':' => '：', ';' => '；', '(' => '（', ')' => '）'];
            $text = preg_replace_callback(
                "/(?<=[{$cjk}])[,.!?:;()]|[,.!?:;()](?=[{$cjk}])/u",
                fn($m) => $map[$m[0]] ?? $m[0],
                $text
            ) ?? $text;
        }
        if (!empty($options['space'])) {
            $text = preg_replace("/(?<=[{$cjk}]) +(?=[{$cjk}])/u", "\u{3000}", $text) ?? $text;
        }
        $rtl = (string)($options['rtl'] ?? 'none');
        if ($rtl === 'unicode' || $rtl === 'html') {
            $pattern = '/[\p{Arabic}\p{Hebrew}\p{Syriac}\p{Thaana}](?:[\p{Arabic}\p{Hebrew}\p{Syriac}\p{Thaana}\p{N}\p{P}\p{Zs}]*[\p{Arabic}\p{Hebrew}\p{Syriac}\p{Thaana}])?/u';
            if (preg_match_all($pattern, $text, $matches) > 0) {
                // ein einziger Abschnitt, der den ganzen Text ausmacht, braucht keine Markierung
                if (!(count($matches[0]) === 1 && trim($text) === trim($matches[0][0]))) {
                    $text = preg_replace_callback($pattern, fn($m) => $rtl === 'html'
                        ? '<span dir="rtl">' . $m[0] . '</span>'
                        : "\u{2067}" . $m[0] . "\u{2069}", $text) ?? $text;
                }
            }
        }
        return $text;
    }

    // ====================
    // Hintergrund

    public function start(array $job, ?string &$error = null): ?array
    {
        $error = '';
        $phpExec = (string)$this->config->get('paths.php');
        if ($phpExec === '' || !is_executable($phpExec)) {
            $error = "Error: 'php' is not executable (paths.php)";
            $this->system->log('import-errors', $error);
            return null;
        }
        $token = $this->crypt->randomKey(16);
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $jobFile = $tmpDir . '/import-job-' . $token . '.json';
        $job['token'] = $token;
        $job['admin_id'] = (int)$this->session->get('admin_id');
        if (file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            $error = "Error: could not write job file {$jobFile}";
            $this->system->log('import-errors', $error);
            return null;
        }
        $command = [
            $phpExec,
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'record_import.php',
            '--job=' . $jobFile,
            '--token=' . $token,
        ];
        $result = $this->bgproc->start($command, self::BGPROC_TYPE, $token, null, null, $error);
        if ($result === null) {
            @unlink($jobFile);
            $this->system->log('import-errors', "start failed: {$error}");
        }
        return $result;
    }

    public function loadJob(string $jobFile): ?array
    {
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $real = realpath($jobFile);
        if ($real === false || !str_starts_with($real, realpath($tmpDir) . DIRECTORY_SEPARATOR)) return null;
        $job = json_decode((string)file_get_contents($real), true);
        @unlink($real);
        return is_array($job) ? $job : null;
    }

    /**
     * Ergebnis als interne Nachricht an den auslösenden Admin: Zusammenfassung als
     * Text, das Protokoll (HTML mit Farben) im HTML-Teil.
     *
     * @return string  Betreff
     */
    public function report(array $job, array $result): string
    {
        $adminId = (int)($job['admin_id'] ?? 0);
        $subject = str_replace(
            ['{type}', '{area}'],
            [strtoupper((string)($job['type'] ?? '')), $this->_areaLabel((int)($job['area_id'] ?? 0))],
            $this->cms->snippet('record.import_report_subject')
        );
        if ($adminId < 1) return $subject;
        // Text: nur die Zusammenfassung - das Protokoll steht im HTML-Teil
        $body = $this->summaryText($job, $result);
        if (!empty($result['error'])) $body .= "\n" . (string)$result['error'];
        $this->message->sendSystem($adminId, $subject, $body, $this->protocolHtml($job, $result));
        return $subject;
    }

    /**
     * "Import {Modus}: n hinzugefügt, n geändert, n ersetzt, n fehlgeschlagen, n übersprungen"
     */
    public function summaryText(array $job, array $result): string
    {
        $text = $this->cms->snippet('record.import_summary', ['mode' => !empty($job['global']) ? 'global' : (string)($job['mode'] ?? '')])
            . $this->cms->snippet('colon')
            . str_replace(
                ['{inserted}', '{updated}', '{replaced}', '{failed}', '{skipped}'],
                [(int)($result['inserted'] ?? 0), (int)($result['updated'] ?? 0), (int)($result['replaced'] ?? 0), (int)($result['failed'] ?? 0), (int)($result['skipped'] ?? 0)],
                $this->cms->snippet('record.import_summary_counts')
            );
        return $text;
    }

    /**
     * Protokoll als HTML-Tabelle mit Farben (Bootstrap-Klassen) - für die
     * Ergebnisseite und den HTML-Teil der Nachricht.
     */
    public function protocolHtml(array $job, array $result): string
    {
        $classes = ['inserted' => 'success', 'updated' => 'info', 'replaced' => 'warning', 'deleted' => 'warning', 'failed' => 'danger', 'skipped' => 'secondary'];
        $rows = '';
        $tplRow = $this->backend->template('record.import_protocol_row');
        foreach ($result['items'] ?? [] as $item) {
            $status = (string)$item['status'];
            $kind = (string)($item['kind'] ?? 'record');
            $id = (int)($item['id'] ?? 0);
            $teaser = '';
            if ($id > 0 && $status !== 'failed' && $status !== 'skipped' && $status !== 'deleted') {
                $teaser = match ($kind) {
                    'record' => $this->backend->teaser('record', $id, linked: true, icon: false),
                    'area' => $this->backend->teaser('area', $id, linked: true, icon: false),
                    'template' => $this->backend->teaser('template', $id, linked: true, icon: false),
                    'converter' => $this->backend->teaser('converter', $id, linked: true, icon: false),
                    default => '',
                };
            }
            if ($teaser === '') $teaser = htmlspecialchars(trim(($id > 0 ? "[{$id}] " : '') . (string)($item['label'] ?? '')));
            $rows .= strtr($tplRow, [
                '{class}' => $classes[$status] ?? 'secondary',
                '{kind}' => htmlspecialchars($this->cms->snippet('record.import_kind_' . $kind)),
                '{record}' => $teaser,
                '{status}' => htmlspecialchars($this->cms->snippet('record.import_status_' . $status)),
                '{messages}' => htmlspecialchars(implode(' | ', (array)($item['messages'] ?? []))),
            ]) . "\n";
        }
        return $this->backend->template('record.import_protocol', [
            '{class}' => ($result['failed'] ?? 0) > 0 ? 'warning' : 'success',
            '{summary}' => htmlspecialchars($this->summaryText($job, $result) . (!empty($result['error']) ? ' ' . $result['error'] : '')),
            '{rows}' => $rows,
        ]);
    }

    // ====================
    // Hilfsfunktionen

    private function _schemaOf(int $areaId): array
    {
        if ($areaId < 1) return [];
        return $this->schemaMemo[$areaId] ??= $this->record->virtualSchema($areaId, false);
    }

    private function _areaOf(int $areaId): ?array
    {
        static $memo = [];
        if (array_key_exists($areaId, $memo)) return $memo[$areaId];
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `area` WHERE `area_id` = ? AND `deleted` = 0", 'i', [$areaId]);
        $row = $this->db->fetch();
        $this->db->popResult();
        return $memo[$areaId] = $row;
    }

    private function _areaLabel(int $areaId): string
    {
        return $this->cms->_strip_tags($this->cms->textOrSnippet((string)($this->_areaOf($areaId)['label'] ?? '')));
    }

    /**
     * Sprachen der Instanz eines Bereichs: ['instance_id', 'language_ids', 'primary_id']
     */
    private function _languagesOf(int $areaId): array
    {
        $instanceId = (int)($this->_areaOf($areaId)['instance_id'] ?? 0);
        $languageIds = $this->instanceCache->languageIds($instanceId);
        $primaryId = $this->instanceCache->primaryLanguageId($instanceId);
        if (empty($languageIds)) $languageIds = [$primaryId];
        return ['instance_id' => $instanceId, 'language_ids' => $languageIds, 'primary_id' => $primaryId];
    }

    /**
     * Beschriftung für das Protokoll: das Default-Feld des Bereichs aus den Werten.
     */
    private function _labelOf(int $areaId, array $values): string
    {
        $defaultField = $this->backend->getDefaultField($areaId);
        $name = (string)($defaultField['name'] ?? '');
        if ($name === '' || !isset($values[$name])) return '';
        $value = $values[$name];
        if (is_array($value)) $value = (string)(reset($value) ?: '');
        return $this->cms->_strlimit($this->cms->_strip_tags((string)$value), 60);
    }
}
