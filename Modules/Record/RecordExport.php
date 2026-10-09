<?php
/**
 * Export von Datensätzen eines Inhaltsbereichs: XML (Backup), CSV, DOCX (Übersetzung).
 *
 * Das ist die ARBEIT; die Maske dazu ist RecordBackendExport (record_export.php),
 * erreichbar über das Menü rechts in der Datensatzliste. Der Export läuft im Request.
 *
 * DER AUFTRAG (JOB)
 * --------------------
 *     type        'xml' | 'csv' | 'docx'
 *     area_id     Bereich der Datensätze
 *     ids         record_ids (produktiv), von RecordBatch::collectIds() ermittelt
 *     files       xml: Upload-Dateien mit exportieren (bool)
 *     portable    xml (global): Templates für den Import in eine fremde Datenbank
 *                 markieren - Verweise auf Templates, Bereiche und Datensätze in den
 *                 Parametern der CMS-Befehle werden zu <!-- CMS:REPLACE ... -->
 *                 (TemplateParserTrait::mapReferences(), Typen aus commands.php);
 *                 RecordImport löst sie mit den tatsächlichen Ids/Namen auf
 *     csv         csv: ['header' => bool, 'bom' => bool, 'separator' => ';',
 *                 'enclosure' => '"', 'eol' => "\r\n"]
 *     languages   csv: language_ids der Instanz, die exportiert werden
 *     fields      csv: Schlüssel der angehakten Spalten (siehe csvFields()),
 *                 docx: Namen der angehakten mehrsprachigen Felder
 *     from / to   docx: Quell- und Zielsprache (language_id)
 *     token       Hintergrund: Token des bgproc-Eintrags (start())
 *     admin_id    Hintergrund: auslösender Admin (Empfänger der Nachricht)
 *
 * HINTERGRUND
 * --------------------
 * Ab admin.export_background_threshold Datensätzen (Standard 1000, 0 = aus) läuft
 * der Export immer als Hintergrundprozess (Core/cron/record_export.php - EXAKT
 * derselbe Code wie im Request), darunter auf Wunsch des Redakteurs. Der
 * Download-Link kommt dann als interne Nachricht (report()).
 *
 * VERWEILDAUER
 * --------------------
 * Eine Zip-Datei wird beim Download sofort gelöscht (RecordBackendExport::download())
 * und sonst nach admin.export_retention_hours (Standard 24) von cleanup() entfernt -
 * von der Zeitplaner-Aufgabe "Temporäre Dateien löschen" (Typ tmpcleanup, Vorgabe stündlich).
 *
 * ABLAGE
 * --------------------
 * Jeder Export legt var/export/{Zeitpunkt}/ an, darin die Exportdatei (export.xml,
 * export.csv, export.docx) und - bei Dateien - media/{record_id}_{field_id}_{language_id}-{filename}
 * (bewusst eine andere Reihenfolge der Ids als in public/media: die Datei soll sich
 * ohne Nachschlagen dem Datensatz zuordnen lassen). Am Ende wird das Verzeichnis zu
 * var/export/{Zeitpunkt}_{bereich}_{typ}.zip gepackt und gelöscht; die Zip-Datei liefert
 * RecordBackendExport::download() aus (record_download.php).
 *
 * DREI FORMATE
 * --------------------
 *     xml   Backup-Format, fest: alle Felder aller Sprachen so, wie sie in den
 *           data_*-Tabellen stehen - verschlüsselte Inhalte als Geheimtext (base64),
 *           Passwörter als Hash, das Fallback-Kennzeichen `empty` je Sprache.
 *           Thumbnails und Volltext werden nicht exportiert (beim Re-Import werden sie
 *           aus den Upload-Dateien bzw. Quellfeldern neu erzeugt). Der Header nennt
 *           Datenbank, CMS-Version, Admin, Zeitpunkt, Bereich, Sprachen und die
 *           Felddefinitionen. Mit der Struktur reisen die Konverter der
 *           Thumbnail-Felder mit (<converters>, siehe _xmlConverters()): ihre
 *           converter_id gilt nur in derselben Datenbank, deshalb trägt jeder einen
 *           Hash seiner Einstellungen (Converter::settingsHash()), über den der
 *           Import in einer fremden Datenbank den passenden Konverter findet oder
 *           ihn neu anlegt.
 *     csv   eine Zeile je Datensatz, Werte aus dem Inhalts-Cache der gewählten
 *           Sprachen (aufgelöst, d. h. mit Fallback aus der Hauptsprache). Mehrsprachige
 *           Felder erhalten bei mehr als einer Sprache eine Spalte je Sprache
 *           ("{name}.{code}"). Verschlüsselte Inhalte im Klartext, Passwörter
 *           als Hash, Datums-/Zeitwerte wie gespeichert (UTC). Mehrwertige Zellen
 *           (Verknüpfungen) mit " | " getrennt.
 *     docx  für die Übersetzung: je Datensatz eine Tabelle, grau hinterlegte Zeilen
 *           tragen die Metadaten für den Re-Import (record_id, language_id, field),
 *           die weißen Zeilen den Inhalt der Quellsprache zum Überschreiben. Alle
 *           Datensätze in export.docx oder (split) je Datensatz eine Datei
 *           "{record_id}-{Default-Feld}.docx" - etwa um sie auf mehrere Übersetzer
 *           zu verteilen; der Re-Import nimmt jede Datei für sich.
 *
 * EINGEBETTETE INHALTE (Felder vom Typ link)
 * --------------------
 * Die Zieldatensätze eines link-Feldes gehören dem Datensatz (LinkTrait) - im CSV
 * und DOCX erscheinen sie wie Felder des Datensatzes selbst:
 *
 *     csv   "{link}.record_id", "{link}.area_id" und JEDES Feld der Zielbereiche
 *           (linkTargetFields(): auch Uploads und Thumbnails - als Pfad der
 *           exportierten Datei; mehrsprachige mit einer Spalte je Sprache). Je
 *           eingebettetem Inhalt ein Wert, in der Reihenfolge des Feldes mit " | "
 *           getrennt. RecordImport liest die Spalten zurück (außer Thumbnails).
 *     docx  die mehrsprachigen Felder der Zielbereiche ("{link}.{feld}" in
 *           docxFields()); je Inhalt und Feld eine Metazeile
 *           "field={link}.{feld} | record_id={id}" unter den Feldern des Datensatzes.
 *
 * @see Camindo\Core\Modules\Record\RecordBackendExport  die Masken
 * @see Camindo\Core\Modules\Record\RecordBatch          Umfang (filterParams, collectIds)
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\BackgroundProcess;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Converter\Converter;
use Camindo\Core\Modules\Messaging\Message;
use Camindo\Core\Modules\Snippets\Snippets;
use Camindo\Core\Modules\Template\Template;

class RecordExport {

    public const TYPES = ['xml', 'csv', 'docx'];

    /** Typkennung des Hintergrundprozesses (Tabelle `bgproc`) */
    public const BGPROC_TYPE = 'export';

    /** Format-Kennung im XML-Wurzelelement - bei Änderungen am Aufbau erhöhen
     *  (1: ein Bereich, nur Datensätze; 2: mehrere Bereiche, Struktur, Templates;
     *  seit 2026-09-15 dazu der optionale Block <converters> - Dateien ohne ihn
     *  bleiben lesbar, deshalb keine neue Kennung) */
    public const XML_FORMAT = 2;

    /** Spalten von `field`, die als Kind-Elemente exportiert werden (lange Texte) */
    private const FIELD_TEXT_COLUMNS = ['label', 'note', 'tooltip', 'pattern', 'default', 'details'];

    /** Spalten von `field`, die nicht exportiert werden (Schlüssel bzw. abgeleitet) */
    private const FIELD_SKIP_COLUMNS = ['field_id', 'area_id', 'rel_field_id', 'rel_area_id', 'parent_field_id'];

    /** Spalten aus `data_fil`, die sich im CSV einzeln wählen lassen */
    public const FILE_COLUMNS = ['file_language_id', 'extension', 'mime_type', 'filename', 'size', 'width', 'height', 'token', 'version', 'data'];

    /**
     * Meta-Felder, die im CSV nicht angeboten werden: interne Kennzeichen, die
     * abgeleiteten Baumspalten und die Position - die Reihenfolge steckt in der
     * Sortierung der Datei (exportSort()), der Import fügt in dieser Reihenfolge ein.
     */
    private const META_HIDDEN = ['tmp', 'deleted', 'instance_id', 'parent_ids', 'position', 'lft', 'rgt'];

    /** Trennzeichen für mehrwertige CSV-Zellen (Verknüpfungen) */
    private const MULTI_SEPARATOR = ' | ';

    /** Inhalts-Caches fremder Datensätze (Verknüpfungsziele): "{id}.{lang}" => cache */
    private array $cacheMemo = [];

    /** Bereiche (Backend::areaData-Format ohne Backend): area_id => Zeile */
    private array $areaMemo = [];

    /** Schemata fremder Bereiche: area_id => virtuelles Schema */
    private array $schemaMemo = [];

    /** exportierte Dateien: Quellpfad => Zielname (jede Datei nur einmal) */
    private array $copiedFiles = [];

    /** exportierte Datensätze des laufenden Exports */
    private int $exportedRecords = 0;

    /** converter_ids der exportierten Thumbnail-Felder (_xmlArea() sammelt, _xmlConverters() schreibt) */
    private array $converterIds = [];

    /** Custom-Snippets der Beschriftungen: instance_id => [schlüssel => true] (_xmlArea() sammelt, _xmlSnippets() schreibt) */
    private array $snippetRefs = [];

    public function __construct(
        private Config $config,
        private Database $db,
        private Cms $cms,
        private Crypt $crypt,
        private Session $session,
        private System $system,
        private InstanceCache $instanceCache,
        private Record $record,
        private BackgroundProcess $bgproc,
        private Message $message,
        private Template $template,
        private Snippets $snippets,
        private \Camindo\Core\Access $access,
    )
    {
    }

    // ====================
    // Auftrag

    /**
     * Den Auftrag eines Exports zusammenstellen und prüfen - für die Maske
     * (RecordBackendExport) und die API (record.export). Nur lesbare Sprachen; bei
     * DOCX müssen beide Sprachen lesbar sein. Die Rechte am Format prüft der Aufrufer
     * (RecordService::transferTypes()).
     *
     * @param string $type     xml | csv | docx
     * @param int    $areaId   Bereich
     * @param int[]  $ids      record_ids (produktiv), in Exportreihenfolge
     * @param array  $options  xml: files (bool); csv: header, bom (bool), separator,
     *                         enclosure, eol (Text, '\r\n' …), languages (language_ids),
     *                         fields (Feldschlüssel aus csvFields()); docx: from, to
     *                         (language_id), fields (docxFields()), split (bool:
     *                         ein Datensatz je Datei)
     * @return array|null      Auftrag für run()/start(), null bei Fehlern (Cms::errors)
     */
    public function job(string $type, int $areaId, array $ids, array $options = []): ?array
    {
        if (!in_array($type, self::TYPES, true)) {
            $this->cms->error($this->cms->snippet('error_param_invalid'), 'type');
            return null;
        }
        $job = ['type' => $type, 'area_id' => $areaId, 'ids' => array_values($this->system->sanitizeIds($ids, true))];
        if ($type === 'xml') {
            $job['files'] = !empty($options['files']);
        }
        elseif ($type === 'csv') {
            $job['csv'] = [
                'header' => !empty($options['header']),
                'bom' => !empty($options['bom']),
                'separator' => (string)($options['separator'] ?? ';'),
                'enclosure' => (string)($options['enclosure'] ?? '"'),
                'eol' => (string)($options['eol'] ?? '\r\n'),
            ];
            // (nur lesbare Sprachen - ein manipuliertes Formular zählt nicht)
            $areaData = $this->_areaOf($areaId) ?? [];
            $readable = $this->record->readableLanguages($this->instanceCache->languages((int)($areaData['instance_id'] ?? 0)));
            $job['languages'] = array_values(array_filter(
                $this->system->sanitizeIds((array)($options['languages'] ?? [])),
                fn($id) => isset($readable[$id])
            ));
            $job['fields'] = array_values(array_filter(array_map('strval', (array)($options['fields'] ?? []))));
        }
        else {
            $job['from'] = (int)($options['from'] ?? 0);
            $job['to'] = (int)($options['to'] ?? 0);
            // ein Datensatz je Datei statt aller Datensätze in einer
            $job['split'] = !empty($options['split']);
            if (!$this->access->languageRead($job['from']) || !$this->access->languageRead($job['to'])) {
                $this->cms->error($this->cms->snippet('error_access_denied'));
                return null;
            }
            $job['fields'] = array_values(array_filter(array_map('strval', (array)($options['fields'] ?? []))));
        }
        if (empty($job['ids'])) {
            $this->cms->error($this->cms->snippet('record.error_export_no_records'));
            return null;
        }
        return $job;
    }

    // ====================
    // Schwellwerte

    /**
     * Soll der Export in den Hintergrund? Ab admin.export_background_threshold
     * Datensätzen (0 = Auslagerung aus).
     */
    public function needsBackground(int $count): bool
    {
        $threshold = $this->backgroundThreshold();
        if ($threshold < 1) return false;
        return $count >= $threshold;
    }

    public function backgroundThreshold(): int
    {
        return (int)($this->config->get('admin.export_background_threshold') ?? 1000);
    }

    /**
     * Verweildauer einer Exportdatei in Stunden (admin.export_retention_hours).
     */
    public function retentionHours(): int
    {
        return max(1, (int)($this->config->get('admin.export_retention_hours') ?? 24));
    }

    /**
     * Zeitpunkt (UTC, ISO), bis zu dem eine jetzt erzeugte Datei abrufbar bleibt.
     */
    public function validUntil(int $time = 0): string
    {
        if ($time < 1) $time = time();
        return gmdate('Y-m-d H:i:s', $time + $this->retentionHours() * 3600);
    }

    // ====================
    // Feldlisten für die Masken

    /**
     * Felder eines Bereichs für den CSV-Export: die Eingabefelder in ihrer
     * Reihenfolge, danach die restlichen Meta-Felder (ohne META_HIDDEN - auch wenn
     * `position` als Eingabefeld des Bereichs definiert ist).
     *
     * Je Eintrag: name, label, type, field_id, meta, checked (Vorgabe) und - bei
     * Verknüpfungs- und Datei-Feldern - 'sub': die aufklappbaren Unterfelder
     * (key => [label, checked]). Der Schlüssel eines Unterfeldes ist "{name}.{sub}";
     * bei Verknüpfungen ist 'record_id' die Vorgabe, dahinter die virtuellen Felder
     * des Zielbereichs (ohne Verknüpfungs- und Datei-Felder), bei Datei-Feldern die
     * Spalten aus `data_fil`. Bei Datei-Feldern bedeutet der Hauptschlüssel "{name}"
     * "Datei exportieren" - vorgewählt nur bei Upload-Feldern, nicht bei Thumbnails.
     *
     * @return array  name => Eintrag
     */
    public function csvFields(int $areaId): array
    {
        $schemaVirtual = $this->record->virtualSchema($areaId, false);
        $fields = [];
        foreach ($schemaVirtual as $name => $colDef) {
            if (in_array($name, self::META_HIDDEN, true)) continue;
            $type = (string)($colDef['type'] ?? 'text');
            $entry = [
                'name' => $name,
                'label' => (string)($colDef['label'] ?? $name),
                'type' => $type,
                'field_id' => (int)($colDef['field_id'] ?? 0),
                'meta' => !empty($colDef['meta']),
                'multilingual' => $this->cms->isMultilingualField($colDef),
                'checked' => true,
                'sub' => [],
            ];
            if (in_array($type, $this->cms->dependentTypes, true)) {
                // Feld vom Typ link: die eingebetteten Inhalte gehören zum Datensatz -
                // alle Felder der Zielbereiche, auch Uploads und Thumbnails
                // (EINGEBETTETE INHALTE)
                $targetAreaIds = $this->record->linkTargetAreaIds($colDef);
                $entry['sub']['record_id'] = ['label' => 'record_id', 'checked' => true];
                $entry['sub']['area_id'] = ['label' => 'area_id', 'checked' => count($targetAreaIds) > 1];
                foreach ($this->record->linkTargetFields($colDef) as $targetName => $targetDef) {
                    $isFile = (string)($targetDef['type'] ?? '') === 'file';
                    $entry['sub'][$targetName] = [
                        'label' => (string)($targetDef['label'] ?? $targetName),
                        'type' => (string)($targetDef['type'] ?? ''),
                        'checked' => false,
                        'multilingual' => $this->cms->isMultilingualField($targetDef),
                        'file' => $isFile,
                    ];
                }
                $entry['checked'] = false;
                $entry['relation'] = true;
                $entry['link'] = true;
            }
            elseif (in_array($type, $this->cms->relationTypes, true)) {
                $entry['sub']['record_id'] = ['label' => 'record_id', 'checked' => true];
                foreach ($this->_relationTargetFields($colDef) as $targetName => $targetDef) {
                    $entry['sub'][$targetName] = [
                        'label' => (string)($targetDef['label'] ?? $targetName),
                        'checked' => false,
                        'multilingual' => $this->cms->isMultilingualField($targetDef),
                    ];
                }
                // der Hauptschlüssel einer Verknüpfung ist kein eigener Wert
                $entry['checked'] = false;
                $entry['relation'] = true;
            }
            elseif ($type === 'file') {
                $entry['checked'] = empty($colDef['parent_field_id']);
                $entry['file'] = true;
                foreach (self::FILE_COLUMNS as $col) {
                    $entry['sub'][$col] = ['label' => $col, 'checked' => false];
                }
            }
            $fields[$name] = $entry;
        }
        foreach ($this->cms->schema as $name => $colDef) {
            if (isset($fields[$name]) || in_array($name, self::META_HIDDEN, true)) continue;
            $fields[$name] = [
                'name' => $name,
                'label' => (string)($colDef['label'] ?? $name),
                'type' => (string)($colDef['type'] ?? 'text'),
                'field_id' => 0,
                'meta' => true,
                'multilingual' => false,
                'checked' => in_array($name, ['record_id', 'area_id'], true),
                'sub' => [],
            ];
        }
        return $fields;
    }

    /**
     * Sortierung der Datensätze in der Exportdatei: Bereiche mit Baum in der
     * Reihenfolge der Baumstruktur (`lft`), Bereiche mit Handsortierung nach
     * `position`, sonst nach record_id. Der Import fügt in der Reihenfolge der Datei
     * ein (RecordImport, EINFÜGEPOSITION) - so überlebt die Reihenfolge den Weg
     * durch die Datei.
     *
     * @return array  [sort, order] für Cms::select() / RecordBatch::collectIds()
     */
    public function exportSort(int $areaId): array
    {
        $schemaVirtual = $this->record->virtualSchema($areaId, false);
        if (isset($schemaVirtual['parent_id'])) return ['lft', 'ASC'];
        if (isset($schemaVirtual['position'])) return ['position', 'ASC'];
        return ['record_id', 'ASC'];
    }

    /**
     * Mehrsprachige Felder eines Bereichs für den DOCX-Export (ohne Datei-Felder) -
     * dazu die mehrsprachigen Felder der eingebetteten Inhalte (Felder vom Typ link)
     * als "{link}.{feld}"; deren colDef trägt 'link' (Name des link-Feldes), 'sub'
     * (Feldname im Zielbereich) und die zusammengesetzte Beschriftung.
     *
     * @return array  name => colDef
     */
    public function docxFields(int $areaId): array
    {
        $fields = [];
        $links = [];
        foreach ($this->record->virtualSchema($areaId, false) as $name => $colDef) {
            if (in_array((string)($colDef['type'] ?? ''), $this->cms->dependentTypes, true)) {
                $links[$name] = $colDef;
                continue;
            }
            if ((string)($colDef['type'] ?? '') === 'file') continue;
            if (!$this->cms->isMultilingualField($colDef)) continue;
            $fields[$name] = $colDef;
        }
        foreach ($links as $name => $colDef) {
            foreach ($this->record->linkTargetFields($colDef) as $sub => $targetDef) {
                if ((string)($targetDef['type'] ?? '') === 'file' || !$this->cms->isMultilingualField($targetDef)) continue;
                $targetDef['link'] = $name;
                $targetDef['sub'] = $sub;
                $targetDef['label'] = $this->cms->textOrSnippet((string)($colDef['label'] ?? $name))
                    . ' › ' . $this->cms->textOrSnippet((string)($targetDef['label'] ?? $sub));
                $fields["$name.$sub"] = $targetDef;
            }
        }
        return $fields;
    }

    /**
     * Die eingebetteten Inhalte eines Datensatzes in einem link-Feld, in ihrer
     * Reihenfolge - nur produktive (solange ein Inhalt ausgecheckt ist, hält der
     * Datensatz auch eine Kante auf dessen Arbeitskopie).
     *
     * @return array[]  `record`-Zeilen
     */
    private function _linkDestRows(int $recordId, int $fieldId): array
    {
        $rows = [];
        foreach ($this->_relationRows($recordId, $fieldId) as $rel) {
            $destRow = $this->_recordRow((int)$rel['dest_id']);
            if ($destRow) $rows[] = $destRow;
        }
        return $rows;
    }

    /**
     * Virtuelle Felder der Zielbereiche eines Verknüpfungsfeldes - bei einem
     * Pointer-Feld die Vereinigung über alle Zielbereiche (nach Name). Ohne
     * Verknüpfungs- und Datei-Felder: keine Verknüpfungen von Verknüpfungen.
     */
    private function _relationTargetFields(array $colDef): array
    {
        $type = (string)($colDef['type'] ?? '');
        $areaIds = str_starts_with($type, 'relation')
            ? [(int)($colDef['rel_area_id'] ?? 0)]
            : $this->record->getRelAreaIds((int)$colDef['field_id']);
        $fields = [];
        foreach ($this->system->sanitizeIds($areaIds, true) as $areaId) {
            foreach ($this->_schemaOf((int)$areaId) as $name => $targetDef) {
                $targetType = (string)($targetDef['type'] ?? '');
                if ($targetType === 'file' || in_array($targetType, $this->cms->relationTypes, true)) continue;
                $fields[$name] ??= $targetDef;
            }
        }
        return $fields;
    }

    // ====================
    // Ausführen

    /**
     * Export ausführen: Verzeichnis anlegen, Datei(en) schreiben, packen, aufräumen.
     *
     * @return array  ['zip' => Dateiname in var/export, 'records' => n, 'files' => n,
     *                 'size' => Bytes der Zip-Datei] bzw. ['error' => Meldung]
     */
    public function run(array $job): array
    {
        $type = (string)($job['type'] ?? '');
        $areaId = (int)($job['area_id'] ?? 0);
        $areaIds = $this->system->sanitizeIds((array)($job['area_ids'] ?? []), true);
        $isGlobal = !empty($areaIds);
        $ids = $this->system->sanitizeIds((array)($job['ids'] ?? []), true);
        if (!in_array($type, self::TYPES, true) || ($areaId < 1 && !$isGlobal) || ($isGlobal && $type !== 'xml')) {
            return ['error' => 'invalid job'];
        }
        $areaData = $isGlobal ? ['name' => 'global'] : $this->_areaOf($areaId);
        if (!$areaData) return ['error' => $this->cms->snippet('area.error_area_not_found')];

        $varDir = (string)$this->config->get('paths.var_dir');
        $stamp = gmdate('Y-m-d_His');
        $dirName = $stamp;
        $n = 1;
        while (is_dir("$varDir/export/$dirName")) {
            $dirName = $stamp . '_' . (++$n);
        }
        if (!$this->system->createDir($varDir, "export/$dirName")) {
            return ['error' => 'var/export not writable'];
        }
        $dir = "$varDir/export/$dirName";
        $this->copiedFiles = [];

        $job['ids'] = $ids;
        $job['area'] = $areaData;
        $job['area_ids'] = $areaIds;
        $job['dir'] = $dir;
        $job['stamp'] = $stamp;
        $this->exportedRecords = 0;

        try {
            match ($type) {
                'xml'  => $this->_exportXml($job),
                'csv'  => $this->_exportCsv($job),
                'docx' => $this->_exportDocx($job),
            };
            $areaName = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($areaData['name'] ?? 'area')));
            $zipName = $dirName . '_' . ($areaName !== '' ? $areaName : 'area') . '_' . $type . '.zip';
            $this->_zipDir($dir, "$varDir/export/$zipName");
        }
        catch (\Throwable $e) {
            $this->_removeDir($dir);
            return ['error' => $e->getMessage()];
        }
        $this->_removeDir($dir);

        return [
            'zip' => $zipName,
            'records' => $this->exportedRecords,
            'files' => count($this->copiedFiles),
            'size' => (int)filesize("$varDir/export/$zipName"),
        ];
    }

    /**
     * Zip-Dateien in var/export, neueste zuerst.
     *
     * @return array  Dateiname => ['size' => Bytes, 'time' => Unix-Zeit]
     */
    public function zipFiles(): array
    {
        $dir = (string)$this->config->get('paths.var_dir') . '/export';
        $files = [];
        foreach (glob("$dir/*.zip") ?: [] as $path) {
            $files[basename($path)] = ['size' => (int)filesize($path), 'time' => (int)filemtime($path)];
        }
        uasort($files, fn($a, $b) => $b['time'] <=> $a['time']);
        return $files;
    }

    // ====================
    // XML

    /**
     * Backup-Format (XML_FORMAT 2). Aufbau:
     *
     *     <camindo-export format="2">
     *       <header> database, cms-version, admin, exported-at, structure, content,
     *                instances, languages, count, files </header>
     *       <areas>                                        (structure)
     *         <area area_id parent_id instance_id type name icon position sort_order
     *               default_field_id sort_field_id default_template_id
     *               login_name_field_id login_password_field_id
     *               privacy_policy_id (Lösch-Policy; zählt nur in derselben Datenbank)>
     *           <label/> <note/> <details/>
     *           <access template_id selectable override/>   (rel_area_template)
     *           <triggers> <trigger template_id/> </triggers> (rel_area_trigger, in Reihenfolge)
     *           <fields> <field field_id name type ...Spalten...> <label/> <default/>
     *                    <details/> <rel_area_ids><area_id/></rel_area_ids>
     *                    <source_fields><field_id/></source_fields>
     *                    <converter_ids><converter_id/></converter_ids> </field> </fields>
     *         </area>
     *       </areas>
     *       <converters>                                   (structure, Thumbnail-Felder)
     *         <converter converter_id hash processor type extension>
     *           <label/> <media_types_incl/> <media_types_excl/> <details/>
     *         </converter>
     *       </converters>
     *       <snippets>                                     (structure, Custom-Snippets der
     *         <snippet instance_id key>                     Beschriftungen #custom.{id}.{key})
     *           <text lang=".."/>
     *         </snippet>
     *       </snippets>
     *       <records>                                      (content, Inhaltsbereiche)
     *         <record record_id=".." area_id=".." ...meta...>
     *           <field name=".." type=".." field_id="..">
     *             <value language_id=".." [empty="1"] [encoding="base64"]>..</value>   (Skalare)
     *             <file language_id=".." file_language_id=".." extension=".." ... [path="media/.."]><data>{json}</data></file>
     *             <relation dest_id=".." position=".."/>
     *           </field>
     *         </record>
     *       </records>
     *       <templates>                                    (content, Template-Bereiche)
     *         <template template_id area_id name type standalone> <label/> <code/> </template>
     *       </templates>
     *     </camindo-export>
     *
     * Der Export aus einer Datensatzliste (area_id + ids) schreibt seinen Bereich
     * mit Feldern und die gewählten Datensätze; der globale Export (area_ids) die
     * gewählten Bereiche aller Typen - Struktur und/oder Inhalte (alle produktiven
     * Datensätze bzw. Templates).
     *
     * Skalare Werte stehen je Sprache so, wie sie in der data_*-Tabelle liegen;
     * `empty="1"` kennzeichnet den Fallback aus der Hauptsprache. Verschlüsselte
     * Inhalte bleiben Geheimtext (base64, encoding="base64"), Passwörter sind der
     * Hash. Thumbnail- und Volltextfelder fehlen bei den Datensätzen (siehe
     * Klassenkommentar) - in der Struktur sind sie enthalten.
     */
    private function _exportXml(array $job): void
    {
        $withFiles = !empty($job['files']);
        $isGlobal = !empty($job['area_ids']);
        $withStructure = $isGlobal ? !empty($job['structure']) : true;
        $withContent = $isGlobal ? !empty($job['content']) : true;
        $areaIds = $isGlobal ? $job['area_ids'] : [(int)$job['area_id']];

        // Bereiche in Baumreihenfolge (Eltern vor Kindern - der Import legt sie so an)
        $areas = [];
        $placeholders = implode(',', array_fill(0, count($areaIds), '?'));
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `area` WHERE `area_id` IN ($placeholders) AND `deleted` = 0 ORDER BY `lft` ASC", str_repeat('i', count($areaIds)), $areaIds);
        foreach ($this->db->fetchAll() as $row) $areas[(int)$row['area_id']] = $row;
        $this->db->popResult();
        $instanceIds = array_values(array_unique(array_map(fn($a) => (int)$a['instance_id'], $areas)));

        $xml = new \XMLWriter();
        $xml->openUri($job['dir'] . '/export.xml');
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('camindo-export');
        $xml->writeAttribute('format', (string)self::XML_FORMAT);

        // Header
        // --------------------
        $xml->startElement('header');
        $xml->writeElement('database', (string)($this->config->get('db.name') ?? ''));
        $xml->writeElement('cms-version', defined('CAMINDO_VERSION') ? CAMINDO_VERSION : '');
        $xml->startElement('admin');
        $xml->writeAttribute('admin_id', (string)$this->_adminId());
        $xml->text($this->_adminName());
        $xml->endElement();
        $xml->writeElement('exported-at', gmdate('c'));
        $xml->writeElement('structure', $withStructure ? '1' : '0');
        $xml->writeElement('content', $withContent ? '1' : '0');
        $xml->startElement('instances');
        foreach ($instanceIds as $instanceId) {
            $instance = $this->instanceCache->instance($instanceId) ?? [];
            $xml->startElement('instance');
            $xml->writeAttribute('instance_id', (string)$instanceId);
            $xml->writeAttribute('primary_language_id', (string)$this->instanceCache->primaryLanguageId($instanceId));
            $xml->text($this->cms->textOrSnippet((string)($instance['label'] ?? '')));
            $xml->endElement();
        }
        $xml->endElement();
        $xml->startElement('languages');
        $written = [];
        foreach ($instanceIds as $instanceId) {
            $primaryId = $this->instanceCache->primaryLanguageId($instanceId);
            foreach ($this->instanceCache->languages($instanceId) as $languageId => $language) {
                $xml->startElement('language');
                $xml->writeAttribute('language_id', (string)$languageId);
                $xml->writeAttribute('code', (string)($language['code'] ?? ''));
                $xml->writeAttribute('instance_id', (string)$instanceId);
                if ((int)$languageId === $primaryId) $xml->writeAttribute('primary', '1');
                $xml->text((string)($language['label'] ?? ''));
                $xml->endElement();
                $written[(int)$languageId] = true;
            }
        }
        $xml->endElement();
        // Zählung vorab (für die Analyse beim Import)
        $recordCount = 0;
        $recordsByArea = [];
        if ($withContent) {
            foreach ($areas as $areaRow) {
                if (!in_array((string)$areaRow['type'], ['record', 'profile', 'ugc'], true)) continue;
                $recordsByArea[(int)$areaRow['area_id']] = $isGlobal
                    ? $this->_productiveIds((int)$areaRow['area_id'])
                    : $job['ids'];
                $recordCount += count($recordsByArea[(int)$areaRow['area_id']]);
            }
        }
        $xml->writeElement('count', (string)$recordCount);
        $xml->writeElement('files', $withFiles ? '1' : '0');
        $xml->endElement(); // header

        // Bereiche (Struktur)
        // --------------------
        $this->converterIds = [];
        $this->snippetRefs = [];
        $xml->startElement('areas');
        foreach ($areas as $areaRow) {
            $this->_xmlArea($xml, $areaRow, $withStructure);
        }
        $xml->endElement();

        // Konverter der Thumbnail-Felder
        // --------------------
        $this->_xmlConverters($xml);

        // Custom-Snippets der Beschriftungen und der exportierten Templates
        // --------------------
        if ($withContent) $this->_collectTemplateSnippetRefs($areas);
        $this->_xmlSnippets($xml);

        // Datensätze
        // --------------------
        $xml->startElement('records');
        foreach ($recordsByArea as $areaId => $ids) {
            $schemaVirtual = $this->_schemaOf((int)$areaId);
            foreach ($ids as $recordId) {
                $row = $this->_recordRow((int)$recordId);
                if (!$row || (int)$row['area_id'] !== (int)$areaId) continue;
                $this->_xmlRecord($xml, $row, $schemaVirtual, $withFiles, $job['dir']);
                $this->exportedRecords++;
                $xml->flush();
            }
        }
        $xml->endElement();

        // Templates
        // --------------------
        $xml->startElement('templates');
        if ($withContent) {
            foreach ($areas as $areaRow) {
                if ((string)$areaRow['type'] !== 'template') continue;
                $this->db->pushResult();
                $this->db->query("SELECT * FROM `template` WHERE `area_id` = ? ORDER BY `name` ASC", 'i', [(int)$areaRow['area_id']]);
                $templates = $this->db->fetchAll();
                $this->db->popResult();
                foreach ($templates as $template) {
                    $xml->startElement('template');
                    foreach (['template_id', 'area_id', 'name', 'type', 'standalone', 'created_by', 'created_at', 'changed_at'] as $attr) {
                        $xml->writeAttribute($attr, (string)($template[$attr] ?? ''));
                    }
                    $xml->writeElement('label', $this->_xmlText((string)$template['label']));
                    $code = (string)$template['code'];
                    if (!empty($job['portable'])) $code = $this->_markTemplateReferences($code);
                    $xml->writeElement('code', $this->_xmlText($code));
                    $xml->endElement();
                }
                $xml->flush();
            }
        }
        $xml->endElement();

        $xml->endElement(); // camindo-export
        $xml->endDocument();
        $xml->flush();
    }

    /**
     * Verweise im Template-Code markieren (Option "portable"):
     *
     *     <!-- CMS:REPLACE template_id=22 -->      <!-- CMS:REPLACE template_name=header -->
     *     <!-- CMS:REPLACE area_id=50 -->          <!-- CMS:REPLACE area_name=artikel -->
     *     <!-- CMS:REPLACE record_id=1758 slug="impressum" -->   (slug, wenn der Datensatz eins hat -
     *                                                            als URL-Pfad mit Slug-Prefix, "seiten/impressum")
     *
     * Die Markierung ersetzt den Wert an Ort und Stelle; welche Parameter Verweise
     * tragen, sagt commands.php ('ref'). Ein so markiertes Template ist nur in der
     * Exportdatei markiert - der Import setzt die aufgelösten Werte wieder ein.
     */
    private function _markTemplateReferences(string $code): string
    {
        return $this->template->mapReferences($code, function (string $ref, string $value): ?string {
            $value = trim($value);
            if ($value === '') return null;
            $isNumeric = ctype_digit($value);
            if ($ref === 'record') {
                if (!$isNumeric) return null;
                $slug = $this->_recordPath((int)$value);
                return '<!-- CMS:REPLACE record_id=' . (int)$value . ($slug !== '' ? ' slug="' . $slug . '"' : '') . ' -->';
            }
            if ($ref === 'template' || $ref === 'area') {
                if (preg_match('/[^a-z0-9_\-.]/i', $value)) return null; // kein einfacher Name
                return '<!-- CMS:REPLACE ' . $ref . '_' . ($isNumeric ? 'id' : 'name') . '=' . $value . ' -->';
            }
            return null;
        });
    }

    /**
     * URL-Pfad eines Datensatzes in der Hauptsprache: Slug-Prefix des Bereichs +
     * Feld `slug` ("seiten/impressum", Cms::recordUrl()) - '' ohne Feld oder Wert.
     * Der Import löst ihn wie ein Aufruf der Seite auf (Cms::recordIdsBySlugPath()).
     */
    private function _recordPath(int $recordId): string
    {
        $row = $this->_recordRow($recordId);
        if (!$row) return '';
        $schema = $this->_schemaOf((int)$row['area_id']);
        if (!isset($schema['slug']) || (string)($schema['slug']['type'] ?? '') !== 'chars') return '';
        $primaryId = $this->instanceCache->primaryLanguageId((int)$row['instance_id']);
        $cache = $this->_cacheOf($recordId, $primaryId, $row);
        $slug = trim((string)($cache['slug'][1] ?? ''));
        if (!preg_match('/^[a-z0-9_\-.\/]+$/i', $slug)) return '';
        $prefix = $this->instanceCache->slugPrefix((int)$row['area_id'], $primaryId);
        return ($prefix !== '') ? $prefix . '/' . $slug : $slug;
    }

    /**
     * Ids aller produktiven Datensätze eines Bereichs (globaler Export).
     */
    private function _productiveIds(int $areaId): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT `record_id` FROM `record` WHERE `area_id` = ? AND `tmp` = 0 AND `deleted` = 0 ORDER BY `lft` ASC, `position` ASC, `record_id` ASC", 'i', [$areaId]);
        $ids = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
        return $ids;
    }

    /**
     * Einen Bereich als Bauplan-Datei schreiben: dasselbe Format wie die Dateien in
     * Core/Modules/Area/blueprints/ (ein <area> mit Einstellungen, Zuweisungen und
     * Feldern, keine Datensätze) - für das Duplizieren eines Bereichs
     * (AreaBackend::_duplicateSave() -> RecordImport::importBlueprint()).
     *
     * @return string  Pfad der Datei in var/tmp (der Aufrufer löscht sie), '' wenn
     *                 es den Bereich nicht gibt
     */
    public function areaBlueprintFile(int $areaId): string
    {
        $areaRow = $this->_areaOf($areaId);
        if ($areaRow === null) return '';
        $path = (string)$this->config->get('paths.tmp_dir') . '/blueprint-' . $areaId . '-' . $this->crypt->randomKey(8) . '.xml';
        $xml = new \XMLWriter();
        if (!$xml->openUri($path)) return '';
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('camindo-export');
        $xml->writeAttribute('format', (string)self::XML_FORMAT);
        $this->converterIds = [];
        $this->snippetRefs = [];
        $xml->startElement('areas');
        $this->_xmlArea($xml, $areaRow, true);
        $xml->endElement();
        $this->_xmlConverters($xml);
        $this->_xmlSnippets($xml);
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
        return $path;
    }

    /**
     * Ein Bereich mit Template-Zuweisungen und - bei Inhaltsbereichen mit
     * Struktur - seinen Feldern.
     */
    private function _xmlArea(\XMLWriter $xml, array $areaRow, bool $withFields): void
    {
        $areaId = (int)$areaRow['area_id'];
        $xml->startElement('area');
        foreach (['area_id', 'parent_id', 'instance_id', 'type', 'name', 'icon', 'position', 'sort_order', 'dependent', 'default_field_id', 'sort_field_id', 'default_template_id', 'list_template_id', 'overview_template_id', 'login_name_field_id', 'login_password_field_id', 'privacy_policy_id'] as $attr) {
            $value = $areaRow[$attr] ?? null;
            if ($value === null || $value === '') continue;
            $xml->writeAttribute($attr, (string)$value);
        }
        $xml->writeElement('label', $this->_xmlText((string)$areaRow['label']));
        if ((string)$areaRow['note'] !== '') $xml->writeElement('note', $this->_xmlText((string)$areaRow['note']));
        $this->_collectSnippetRefs((string)$areaRow['label'] . ' ' . (string)$areaRow['note']);
        $details = (string)($areaRow['details'] ?? '');
        if ($details !== '' && $details !== '{}' && $details !== '[]') $xml->writeElement('details', $details);
        $this->db->pushResult();
        $this->db->query("SELECT `template_id`, `selectable`, `override` FROM `rel_area_template` WHERE `area_id` = ?", 'i', [$areaId]);
        foreach ($this->db->fetchAll() as $access) {
            $xml->startElement('access');
            $xml->writeAttribute('template_id', (string)$access['template_id']);
            $xml->writeAttribute('selectable', (string)(int)$access['selectable']);
            $xml->writeAttribute('override', (string)(int)$access['override']);
            $xml->endElement();
        }
        // Trigger-Templates in ihrer Reihenfolge - der Block steht immer da, auch
        // leer: der Import setzt damit "keins" (ohne Block bliebe der Zielstand)
        $this->db->query("SELECT `template_id` FROM `rel_area_trigger` WHERE `area_id` = ? ORDER BY `position`, `template_id`", 'i', [$areaId]);
        $xml->startElement('triggers');
        foreach ($this->db->fetchAll() as $templateId) {
            $xml->startElement('trigger');
            $xml->writeAttribute('template_id', (string)(int)$templateId);
            $xml->endElement();
        }
        $xml->endElement();
        $this->db->popResult();

        if ($withFields && in_array((string)$areaRow['type'], ['record', 'profile', 'ugc'], true)) {
            $xml->startElement('fields');
            $this->db->pushResult();
            $this->db->query("SELECT * FROM `field` WHERE `area_id` = ? ORDER BY `position` ASC", 'i', [$areaId]);
            $fields = $this->db->fetchAll();
            $this->db->popResult();
            foreach ($fields as $field) {
                $xml->startElement('field');
                $xml->writeAttribute('field_id', (string)$field['field_id']);
                foreach (['rel_field_id', 'rel_area_id', 'parent_field_id'] as $attr) {
                    if ((int)($field[$attr] ?? 0) > 0) $xml->writeAttribute($attr, (string)$field[$attr]);
                }
                foreach ($field as $col => $value) {
                    if (in_array($col, self::FIELD_SKIP_COLUMNS, true) || in_array($col, self::FIELD_TEXT_COLUMNS, true)) continue;
                    if ($value === null) continue;
                    $xml->writeAttribute($col, (string)$value);
                }
                foreach (self::FIELD_TEXT_COLUMNS as $col) {
                    $value = (string)($field[$col] ?? '');
                    if ($value === '' || $value === '{}' || $value === '[]') continue;
                    $xml->writeElement($col, $this->_xmlText($value));
                    if (in_array($col, ['label', 'note', 'tooltip'], true)) $this->_collectSnippetRefs($value);
                }
                $fieldId = (int)$field['field_id'];
                $type = (string)$field['type'];
                if ($type === 'file' && (string)$field['display_type'] === 'thumbnail') {
                    // Konverter in ihrer Reihenfolge (`rel_field_converter`)
                    $xml->startElement('converter_ids');
                    foreach ($this->record->getConverterIds($fieldId) as $converterId) {
                        $xml->writeElement('converter_id', (string)$converterId);
                        $this->converterIds[(int)$converterId] = true;
                    }
                    $xml->endElement();
                }
                if (in_array($type, $this->cms->multiAreaTypes, true)) {
                    $xml->startElement('rel_area_ids');
                    foreach ($this->record->getRelAreaIds($fieldId) as $relAreaId) {
                        $xml->writeElement('area_id', (string)$relAreaId);
                    }
                    $xml->endElement();
                }
                if ($type === 'fulltext') {
                    $this->db->pushResult();
                    $this->db->query("SELECT `text_field_id`, `rel_field_id` FROM `rel_fulltext_text` WHERE `fulltext_field_id` = ?", 'i', [$fieldId]);
                    $sources = $this->db->fetchAll();
                    $this->db->popResult();
                    $xml->startElement('source_fields');
                    foreach ($sources as $source) {
                        $xml->startElement('field_id');
                        if ((int)($source['rel_field_id'] ?? 0) > 0) $xml->writeAttribute('rel_field_id', (string)$source['rel_field_id']);
                        $xml->text((string)$source['text_field_id']);
                        $xml->endElement();
                    }
                    $xml->endElement();
                }
                $xml->endElement(); // field
            }
            $xml->endElement(); // fields
        }
        $xml->endElement(); // area
    }

    /**
     * Verweise auf Custom-Snippets (#custom.{instance_id}.{key}, Snippets::
     * REFERENCE_PATTERN) in einer Beschriftung vormerken.
     */
    private function _collectSnippetRefs(string $text): void
    {
        if (!str_contains($text, '#custom.')) return;
        if (!preg_match_all(Snippets::REFERENCE_PATTERN, $text, $matches, PREG_SET_ORDER)) return;
        foreach ($matches as $m) $this->snippetRefs[(int)$m[1]][$m[2]] = true;
    }

    /**
     * Custom-Snippets, die die Templates der exportierten Template-Bereiche ansprechen
     * (Snippets::templateRefs(): custom.{key} und zusammengesetzte Schlüssel) - sonst
     * stünden nach dem Import in einer anderen Instanz nur die Schlüssel auf der Seite.
     */
    private function _collectTemplateSnippetRefs(array $areas): void
    {
        foreach ($areas as $areaRow) {
            if ((string)$areaRow['type'] !== 'template') continue;
            $instanceId = (int)($areaRow['instance_id'] ?? 0);
            if ($instanceId < 1) continue;
            $set = $this->snippets->load($instanceId);
            if (!$set) continue;
            $this->db->pushResult();
            $this->db->query("SELECT `code` FROM `template` WHERE `area_id` = ?", 'i', [(int)$areaRow['area_id']]);
            $codes = $this->db->fetchAll();
            $this->db->popResult();
            foreach ($codes as $code) {
                foreach (Snippets::templateRefs((string)$code, $set, $instanceId) as $key) {
                    $this->snippetRefs[$instanceId][$key] = true;
                }
            }
        }
    }

    /**
     * Die Custom-Snippets, auf die die Beschriftungen der exportierten Bereiche und
     * Felder verweisen (<snippets>, siehe _exportXml()): je Snippet die Texte aller
     * Backend-Sprachen aus dem Set der Instanz (Snippets::load()). Der Import legt
     * fehlende Schlüssel im Set der Zielinstanz an und schreibt die Verweise auf sie
     * um (RecordImport::_mapSnippetRefs()). Snippets, die es im Set nicht (mehr)
     * gibt, fehlen - der Verweis bleibt dann, wie er ist. Nichts zu schreiben: ein
     * leeres Element.
     */
    private function _xmlSnippets(\XMLWriter $xml): void
    {
        $xml->startElement('snippets');
        ksort($this->snippetRefs);
        foreach ($this->snippetRefs as $instanceId => $keys) {
            $set = $this->snippets->load((int)$instanceId);
            foreach (array_keys($keys) as $key) {
                if (!isset($set[$key])) continue;
                $xml->startElement('snippet');
                $xml->writeAttribute('instance_id', (string)$instanceId);
                $xml->writeAttribute('key', (string)$key);
                foreach ($set[$key] as $lang => $text) {
                    if ((string)$text === '') continue;
                    $xml->startElement('text');
                    $xml->writeAttribute('lang', (string)$lang);
                    $xml->text((string)$text);
                    $xml->endElement();
                }
                $xml->endElement();
            }
        }
        $xml->endElement();
    }

    /**
     * Die Konverter, auf die die exportierten Thumbnail-Felder verweisen
     * (<converters>, siehe _exportXml()). Nichts zu schreiben: ein leeres Element.
     *
     * Jeder Konverter trägt neben seiner converter_id den Hash seiner Einstellungen
     * (Converter::settingsHash()) - der Schlüssel für den Import in eine fremde
     * Datenbank, in der die Id nichts bedeutet. Die Bezeichnung und die Einstellungen
     * stehen vollständig dabei, damit der Import den Konverter neu anlegen kann.
     */
    private function _xmlConverters(\XMLWriter $xml): void
    {
        $xml->startElement('converters');
        $ids = array_keys($this->converterIds);
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $this->db->pushResult();
            $this->db->query("SELECT * FROM `converter` WHERE `converter_id` IN ($placeholders) ORDER BY `converter_id` ASC", str_repeat('i', count($ids)), $ids);
            $rows = $this->db->fetchAll();
            $this->db->popResult();
            foreach ($rows as $row) {
                $xml->startElement('converter');
                $xml->writeAttribute('converter_id', (string)$row['converter_id']);
                $xml->writeAttribute('hash', Converter::settingsHash($row));
                $xml->writeAttribute('processor', (string)$row['processor']);
                $xml->writeAttribute('type', (string)$row['type']);
                $xml->writeAttribute('extension', (string)$row['extension']);
                $xml->writeElement('label', $this->_xmlText((string)$row['label']));
                foreach (['media_types_incl', 'media_types_excl', 'details'] as $col) {
                    $value = (string)($row[$col] ?? '');
                    if ($value === '' || $value === '{}' || $value === '[]') continue;
                    $xml->writeElement($col, $this->_xmlText($value));
                }
                $xml->endElement();
            }
        }
        $xml->endElement();
        $this->converterIds = [];
    }

    /**
     * Ein Datensatz mit allen Feldern (siehe _exportXml()).
     */
    private function _xmlRecord(\XMLWriter $xml, array $row, array $schemaVirtual, bool $withFiles, string $dir): void
    {
        $recordId = (int)$row['record_id'];
        $xml->startElement('record');
        foreach ($this->_metaColumns($row) as $col => $value) {
            if ($value === null) continue;
            $xml->writeAttribute($col, (string)$value);
        }
        foreach ($schemaVirtual as $name => $colDef) {
            if ($this->_xmlSkipField($colDef)) continue;
            $type = (string)$colDef['type'];
            $fieldId = (int)$colDef['field_id'];
            if (!empty($colDef['meta'])) {
                // Eingabefeld auf einer Spalte von `record`: der Wert steht
                // bereits im Attribut
                continue;
            }
            $xml->startElement('field');
            $xml->writeAttribute('name', (string)$name);
            $xml->writeAttribute('type', $type);
            $xml->writeAttribute('field_id', (string)$fieldId);

            if (in_array($type, $this->cms->relationTypes, true)) {
                foreach ($this->_relationRows($recordId, $fieldId) as $rel) {
                    $xml->startElement('relation');
                    $xml->writeAttribute('dest_id', (string)$rel['dest_id']);
                    $xml->writeAttribute('position', (string)$rel['position']);
                    $xml->endElement();
                }
            }
            elseif ($type === 'file') {
                foreach ($this->_fileRows($recordId, $fieldId) as $fileRow) {
                    $xml->startElement('file');
                    $xml->writeAttribute('language_id', (string)$fileRow['language_id']);
                    foreach (self::FILE_COLUMNS as $col) {
                        if ($col === 'data') continue;
                        $value = $fileRow[$col];
                        if ($value === null) continue;
                        $xml->writeAttribute($col, (string)$value);
                    }
                    if ($withFiles && (string)$fileRow['extension'] !== '') {
                        $path = $this->_copyFile($row, $fieldId, $fileRow, $dir);
                        if ($path !== '') $xml->writeAttribute('path', $path);
                    }
                    $data = (string)($fileRow['data'] ?? '');
                    if ($data !== '' && $data !== '{}') $xml->writeElement('data', $data);
                    $xml->endElement();
                }
            }
            else {
                foreach ($this->_dataRows($recordId, $fieldId, $type) as $dataRow) {
                    $xml->startElement('value');
                    if ($dataRow['language_id'] !== null) {
                        $xml->writeAttribute('language_id', (string)$dataRow['language_id']);
                    }
                    if (!empty($dataRow['empty'])) $xml->writeAttribute('empty', '1');
                    $value = (string)($dataRow['data'] ?? '');
                    if ($type === 'encrypted') {
                        $xml->writeAttribute('encoding', 'base64');
                        $value = base64_encode($value);
                    }
                    $xml->text($this->_xmlText($value));
                    $xml->endElement();
                }
            }
            $xml->endElement(); // field
        }
        $xml->endElement(); // record
    }

    /**
     * Felder, die nicht ins Backup gehören: Thumbnails (Kind eines Upload-Feldes)
     * und Volltext - beides wird beim Re-Import neu erzeugt.
     */
    private function _xmlSkipField(array $colDef): bool
    {
        if ((string)($colDef['type'] ?? '') === 'fulltext') return true;
        if ((string)($colDef['type'] ?? '') === 'file' && !empty($colDef['parent_field_id'])) return true;
        return false;
    }

    // ====================
    // CSV

    /**
     * Eine Zeile je Datensatz. Die Spalten ergeben sich aus den angehakten
     * Schlüsseln (csvFields()) und den gewählten Sprachen: ein mehrsprachiges Feld
     * bekommt bei mehr als einer Sprache eine Spalte je Sprache ("{key}.{code}"),
     * sonst eine. Auf Wunsch beginnt die Datei mit der UTF-8-BOM (Excel). Werte kommen aus dem Inhalts-Cache der jeweiligen Sprache
     * (aufgelöst); Meta-Felder aus der `record`-Zeile.
     */
    private function _exportCsv(array $job): void
    {
        $areaId = (int)$job['area_id'];
        $instanceId = (int)($job['area']['instance_id'] ?? 0);
        $settings = (array)($job['csv'] ?? []);
        $separator = $this->csvChar((string)($settings['separator'] ?? ';'), ';');
        $enclosure = $this->csvChar((string)($settings['enclosure'] ?? '"'), '"');
        $eol = $this->csvEol((string)($settings['eol'] ?? '\r\n'));
        $withHeader = !empty($settings['header']);
        $withBom = !empty($settings['bom']);

        $languageIds = $this->system->sanitizeIds((array)($job['languages'] ?? []));
        $instanceLanguages = $this->instanceCache->languages($instanceId);
        $languageIds = array_values(array_intersect(array_map('intval', array_keys($instanceLanguages)), $languageIds));
        if (empty($languageIds)) $languageIds = [$this->instanceCache->primaryLanguageId($instanceId)];
        $multiLanguage = count($languageIds) > 1;
        // Spaltenzusatz je Sprache: das Kürzel (language.code)
        $codes = [];
        foreach ($languageIds as $languageId) {
            $codes[$languageId] = (string)($instanceLanguages[$languageId]['code'] ?? $languageId);
        }

        $fields = $this->csvFields($areaId);
        $selected = array_map('strval', (array)($job['fields'] ?? []));

        // Spalten bestimmen: [key, name, sub, multilingual, language_id|null]
        $columns = [];
        foreach ($fields as $name => $entry) {
            $keys = [];
            if (in_array($name, $selected, true)) $keys[''] = $entry['multilingual'];
            foreach ($entry['sub'] as $sub => $subEntry) {
                if (in_array("$name.$sub", $selected, true)) {
                    // Unterfelder einer Datei folgen der Sprache des Datei-Feldes,
                    // Zielfelder einer Verknüpfung ihrer eigenen Mehrsprachigkeit
                    $keys[$sub] = !empty($entry['file']) ? $entry['multilingual'] : !empty($subEntry['multilingual']);
                }
            }
            foreach ($keys as $sub => $multilingual) {
                $key = $sub === '' ? $name : "$name.$sub";
                if ($multilingual && $multiLanguage) {
                    foreach ($languageIds as $languageId) {
                        $columns[] = ['key' => $key . '.' . $codes[$languageId], 'name' => $name, 'sub' => $sub, 'language_id' => $languageId];
                    }
                }
                else {
                    $columns[] = ['key' => $key, 'name' => $name, 'sub' => $sub, 'language_id' => $languageIds[0]];
                }
            }
        }
        if (empty($columns)) {
            throw new \RuntimeException($this->cms->snippet('record.error_export_no_fields'));
        }
        $fileExport = [];
        foreach ($fields as $name => $entry) {
            if (!empty($entry['file']) && in_array($name, $selected, true)) $fileExport[$name] = true;
        }

        $fh = fopen($job['dir'] . '/export.csv', 'w');
        if ($fh === false) throw new \RuntimeException('export.csv not writable');
        if ($withBom) fwrite($fh, "\xEF\xBB\xBF");
        if ($withHeader) {
            fwrite($fh, $this->_csvLine(array_column($columns, 'key'), $separator, $enclosure, $eol));
        }
        foreach ($job['ids'] as $recordId) {
            $row = $this->_recordRow((int)$recordId);
            if (!$row) continue;
            $caches = [];
            foreach ($languageIds as $languageId) {
                $caches[$languageId] = $this->_cacheOf((int)$recordId, $languageId, $row);
            }
            $values = [];
            foreach ($columns as $column) {
                $values[] = $this->_csvValue($row, $fields[$column['name']], $column['sub'], $column['language_id'], $caches[$column['language_id']], $fileExport, $job['dir']);
            }
            fwrite($fh, $this->_csvLine($values, $separator, $enclosure, $eol));
            $this->exportedRecords++;
        }
        fclose($fh);
    }

    /**
     * Ein Zellenwert des CSV.
     *
     * @param array  $row        `record`-Zeile
     * @param array  $entry      Feldeintrag aus csvFields()
     * @param string $sub        Unterfeld ('' = das Feld selbst)
     * @param int    $languageId Sprache der Spalte
     * @param array  $cache      Inhalts-Cache des Datensatzes in dieser Sprache
     * @param array  $fileExport Datei-Felder, deren Datei exportiert wird
     */
    private function _csvValue(array $row, array $entry, string $sub, int $languageId, array $cache, array $fileExport, string $dir): string
    {
        $name = (string)$entry['name'];
        $type = (string)$entry['type'];

        // Eingebettete Inhalte (link): je Inhalt ein Wert, mit " | " getrennt -
        // Id, Bereich, Feldwert bzw. Pfad der exportierten Datei
        if (!empty($entry['link'])) {
            $values = [];
            foreach ($this->_linkDestRows((int)$row['record_id'], (int)$entry['field_id']) as $destRow) {
                $destId = (int)$destRow['record_id'];
                if ($sub === 'record_id' || $sub === '') { $values[] = (string)$destId; continue; }
                if ($sub === 'area_id') { $values[] = (string)$destRow['area_id']; continue; }
                $destDef = $this->_schemaOf((int)$destRow['area_id'])[$sub] ?? null;
                if ($destDef === null) { $values[] = ''; continue; }
                if ((string)($destDef['type'] ?? '') === 'file') {
                    $fileRow = $this->_fileRow($destId, (int)$destDef['field_id'], $languageId);
                    $values[] = ($fileRow && (string)$fileRow['extension'] !== '')
                        ? $this->_copyFile($destRow, (int)$destDef['field_id'], $fileRow, $dir) : '';
                    continue;
                }
                $destCache = $this->_cacheOf($destId, $languageId, $destRow);
                $values[] = $this->_scalarValue($sub, $destCache, $destRow, $destId, $languageId);
            }
            return implode(self::MULTI_SEPARATOR, $values);
        }

        // Verknüpfungen: Ids bzw. Feld der Zieldatensätze
        if (!empty($entry['relation'])) {
            $rels = $this->_relationRows((int)$row['record_id'], (int)$entry['field_id']);
            if ($sub === 'record_id' || $sub === '') {
                return implode(self::MULTI_SEPARATOR, array_column($rels, 'dest_id'));
            }
            $values = [];
            foreach ($rels as $rel) {
                $destId = (int)$rel['dest_id'];
                $destRow = $this->_recordRow($destId);
                if (!$destRow) continue;
                $destCache = $this->_cacheOf($destId, $languageId, $destRow);
                $values[] = $this->_scalarValue($sub, $destCache, $destRow, $destId, $languageId);
            }
            return implode(self::MULTI_SEPARATOR, $values);
        }

        // Datei-Felder: Pfad der exportierten Datei bzw. Spalte aus data_fil
        if (!empty($entry['file'])) {
            $fileRow = $this->_fileRow((int)$row['record_id'], (int)$entry['field_id'], $languageId);
            if (!$fileRow || (string)$fileRow['extension'] === '') return '';
            if ($sub === '') {
                return isset($fileExport[$name]) ? $this->_copyFile($row, (int)$entry['field_id'], $fileRow, $dir) : '';
            }
            return (string)($fileRow[$sub] ?? '');
        }

        // Meta-Feld ohne Eingabefeld
        if (!empty($entry['meta']) && (int)$entry['field_id'] < 1) {
            return $this->_metaValue($row, $name);
        }
        return $this->_scalarValue($name, $cache, $row, (int)$row['record_id'], $languageId);
    }

    /**
     * Skalarer Feldwert aus dem Inhalts-Cache (Klartext, Passwort als Hash); Felder
     * auf einer Spalte von `record` aus der Zeile.
     */
    private function _scalarValue(string $name, array $cache, array $row, int $recordId, int $languageId): string
    {
        if (!isset($cache[$name]) && array_key_exists($name, $row)) {
            return $this->_metaValue($row, $name);
        }
        $entry = (array)($cache[$name] ?? []);
        $type = (string)($entry[0] ?? 'text');
        $value = $entry[1] ?? '';
        if ($type === 'file') return (string)($value['filename'] ?? '');
        if ($type === 'password') {
            return $this->_passwordHash($recordId, $name, $languageId);
        }
        if ($type === 'encrypted') {
            return $this->crypt->decrypt((string)$value);
        }
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE);
        return (string)$value;
    }

    /**
     * Wert einer Spalte von `record` als Text.
     */
    private function _metaValue(array $row, string $name): string
    {
        $value = $row[$name] ?? null;
        if ($value === null) return '';
        return (string)$value;
    }

    /**
     * Passwort-Hash aus data_pwd - im Inhalts-Cache steht er nie.
     */
    private function _passwordHash(int $recordId, string $name, int $languageId): string
    {
        $fieldId = 0;
        // field_id über den Bereich des Datensatzes
        $row = $this->_recordRow($recordId);
        foreach ($this->_schemaOf((int)($row['area_id'] ?? 0)) as $fieldName => $colDef) {
            if ($fieldName === $name) $fieldId = (int)$colDef['field_id'];
        }
        if ($fieldId < 1) return '';
        $this->db->pushResult();
        $this->db->query("SELECT `data` FROM `data_pwd` WHERE `record_id` = ? AND `field_id` = ? AND `language_id` = ?", 'iii', [$recordId, $fieldId, $languageId]);
        $hash = (string)($this->db->fetchNum()[0] ?? '');
        if ($hash === '') {
            $this->db->query("SELECT `data` FROM `data_pwd` WHERE `record_id` = ? AND `field_id` = ? LIMIT 1", 'ii', [$recordId, $fieldId]);
            $hash = (string)($this->db->fetchNum()[0] ?? '');
        }
        $this->db->popResult();
        return $hash;
    }

    /**
     * Eine CSV-Zeile: eingeschlossen wird, was Trennzeichen, Einfassungszeichen oder
     * Zeilenumbrüche enthält; das Einfassungszeichen wird verdoppelt.
     */
    private function _csvLine(array $values, string $separator, string $enclosure, string $eol): string
    {
        $cells = [];
        foreach ($values as $value) {
            $value = (string)$value;
            if ($value !== '' && ($enclosure !== '' && (strpbrk($value, $separator . $enclosure . "\r\n") !== false))) {
                $value = $enclosure . str_replace($enclosure, $enclosure . $enclosure, $value) . $enclosure;
            }
            $cells[] = $value;
        }
        return implode($separator, $cells) . $eol;
    }

    /**
     * Eingabe "Trennzeichen"/"Einfassungszeichen" auf ein Zeichen bringen:
     * "\t" ist der Tabulator, leer = Vorgabe.
     */
    public function csvChar(string $input, string $default): string
    {
        $input = strtr($input, ['\t' => "\t", '\\t' => "\t"]);
        if ($input === '') return $default;
        return mb_substr($input, 0, 1);
    }

    /**
     * Eingabe "Zeilenumbruch": \r\n, \n, \r als Escape-Folgen.
     */
    public function csvEol(string $input): string
    {
        $eol = strtr($input, ['\r' => "\r", '\n' => "\n", '\\r' => "\r", '\\n' => "\n"]);
        return trim($eol, " ") === '' ? "\r\n" : $eol;
    }

    // ====================
    // DOCX

    /**
     * Word-Dokument für die Übersetzung: je Datensatz eine Tabelle. Grau
     * hinterlegte Zeilen tragen die Metadaten ("record_id=.. | language_id=.."
     * bzw. "field=.."), weiße Zeilen den Inhalt der Quellsprache - je Zeile des
     * Inhalts ein Absatz. Die Hinweistexte sind immer englisch (das Dokument geht an
     * Übersetzer, nicht an den Redakteur).
     *
     * Die Datei ist ein minimales OOXML-Paket ([Content_Types].xml, _rels/.rels,
     * word/document.xml) - ohne Bibliothek, Word und LibreOffice öffnen es.
     */
    private function _exportDocx(array $job): void
    {
        $areaId = (int)$job['area_id'];
        $instanceId = (int)($job['area']['instance_id'] ?? 0);
        $languages = $this->instanceCache->languages($instanceId);
        $from = (int)($job['from'] ?? 0);
        $to = (int)($job['to'] ?? 0);
        if (!isset($languages[$from]) || !isset($languages[$to]) || $from === $to) {
            throw new \RuntimeException($this->cms->snippet('record.error_export_languages'));
        }
        $fields = $this->docxFields($areaId);
        $selected = array_map('strval', (array)($job['fields'] ?? []));
        $fields = array_intersect_key($fields, array_flip($selected));
        if (empty($fields)) {
            throw new \RuntimeException($this->cms->snippet('record.error_export_no_fields'));
        }

        $fromLabel = $this->_languageLabel($languages[$from]);
        $toLabel = $this->_languageLabel($languages[$to]);
        $areaLabel = $this->cms->textOrSnippet((string)($job['area']['label'] ?? ''));

        $head = $this->_docxParagraph(str_replace(
            ['{area}', '{from}', '{to}'],
            [$areaLabel, $fromLabel, $toLabel],
            $this->cms->snippet('record.docx_title', [], Cms::FALLBACK_LANG)
        ), bold: true, size: 28);
        $head .= $this->_docxParagraph($this->cms->snippet('record.docx_note', [], Cms::FALLBACK_LANG), size: 18, color: '666666');
        $head .= $this->_docxParagraph('');
        $title = "camindo export: $areaLabel ($fromLabel -> $toLabel)";
        $split = !empty($job['split']);
        // Dateiname je Datensatz: Default-Feld des Bereichs (Quellsprache)
        $defaultName = '';
        foreach ($this->_schemaOf($areaId) as $name => $colDef) {
            if ((int)($colDef['field_id'] ?? 0) > 0 && (int)$colDef['field_id'] === (int)($job['area']['default_field_id'] ?? 0)) $defaultName = (string)$name;
        }

        $body = $head;
        foreach ($job['ids'] as $recordId) {
            $row = $this->_recordRow((int)$recordId);
            if (!$row) continue;
            $sourceCache = $this->_cacheOf((int)$recordId, $from, $row);
            $targetCache = $this->_cacheOf((int)$recordId, $to, $row);
            $this->exportedRecords++;
            $rows = '';
            $meta = "record_id={$recordId} | language_id={$to} | source_language_id={$from} | " . $toLabel;
            $rows .= $this->_docxRow([$meta], shaded: true, bold: true);
            $linkSubs = [];
            foreach ($fields as $name => $colDef) {
                if (isset($colDef['link'])) {
                    $linkSubs[(string)$colDef['link']][(string)$colDef['sub']] = $colDef;
                    continue;
                }
                $label = $this->cms->textOrSnippet((string)($colDef['label'] ?? $name));
                $fieldMeta = "field={$name} | " . $label;
                $targetEntry = (array)($targetCache[$name] ?? []);
                if (isset($targetEntry[1]) && empty($targetEntry[2]) && (string)$targetEntry[1] !== '') {
                    $fieldMeta .= ' | ' . $this->cms->snippet('record.docx_translated', [], Cms::FALLBACK_LANG);
                }
                $rows .= $this->_docxRow([$fieldMeta], shaded: true);
                $value = $this->_scalarValue($name, $sourceCache, $row, (int)$recordId, $from);
                $rows .= $this->_docxRow(preg_split('/\r\n|\r|\n/', $value) ?: [''], shaded: false);
            }
            // eingebettete Inhalte (link): je Inhalt seine Felder, die Metazeile nennt
            // ihn ("field={link}.{feld} | record_id={id}") - der Import schreibt ihn
            // zusammen mit dem Datensatz (RecordImport, EINGEBETTETE INHALTE)
            $schema = $this->_schemaOf($areaId);
            foreach ($linkSubs as $linkName => $subs) {
                $number = 0;
                foreach ($this->_linkDestRows((int)$recordId, (int)($schema[$linkName]['field_id'] ?? 0)) as $destRow) {
                    $number++;
                    $destId = (int)$destRow['record_id'];
                    $destSchema = $this->_schemaOf((int)$destRow['area_id']);
                    $destSource = $this->_cacheOf($destId, $from, $destRow);
                    $destTarget = $this->_cacheOf($destId, $to, $destRow);
                    foreach ($subs as $sub => $colDef) {
                        if (!isset($destSchema[$sub]) || !$this->cms->isMultilingualField($destSchema[$sub])) continue;
                        $fieldMeta = "field={$linkName}.{$sub} | record_id={$destId} | " . $colDef['label'] . ' #' . $number;
                        $targetEntry = (array)($destTarget[$sub] ?? []);
                        if (isset($targetEntry[1]) && empty($targetEntry[2]) && (string)$targetEntry[1] !== '') {
                            $fieldMeta .= ' | ' . $this->cms->snippet('record.docx_translated', [], Cms::FALLBACK_LANG);
                        }
                        $rows .= $this->_docxRow([$fieldMeta], shaded: true);
                        $value = $this->_scalarValue($sub, $destSource, $destRow, $destId, $from);
                        $rows .= $this->_docxRow(preg_split('/\r\n|\r|\n/', $value) ?: [''], shaded: false);
                    }
                }
            }
            $table = $this->_docxTable($rows) . $this->_docxParagraph('');
            if (!$split) {
                $body .= $table;
                continue;
            }
            $label = $defaultName !== '' ? $this->_scalarValue($defaultName, $sourceCache, $row, (int)$recordId, $from) : '';
            $this->_docxWrite($job['dir'] . '/' . $this->_docxFileName((int)$recordId, $label), $head . $table, $title);
        }
        if (!$split) $this->_docxWrite($job['dir'] . '/export.docx', $body, $title);
    }

    /**
     * Dateiname eines Datensatzes beim Export "ein Datensatz je Datei":
     * "{record_id}-{Bezeichnung}.docx", die Bezeichnung auf Buchstaben, Ziffern
     * und Bindestriche gekürzt (höchstens 40 Zeichen).
     */
    private function _docxFileName(int $recordId, string $label): string
    {
        $label = strtolower(trim((string)preg_replace('/[^\p{L}\p{N}]+/u', '-', $this->cms->_strip_tags($label)), '-'));
        $label = trim(mb_substr($label, 0, 40), '-');
        return $recordId . ($label !== '' ? '-' . $label : '') . '.docx';
    }

    /**
     * Minimales OOXML-Paket schreiben (siehe _exportDocx()).
     */
    private function _docxWrite(string $path, string $body, string $title): void
    {
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
            . '</w:body></w:document>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>';
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $this->_xmlEscape($title) . '</dc:title>'
            . '<dc:creator>' . $this->_xmlEscape($this->_adminName()) . '</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException(basename($path) . ' not writable');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('docProps/core.xml', $core);
        $zip->addFromString('word/document.xml', $document);
        $zip->close();
    }

    private function _languageLabel(array $language): string
    {
        return (string)($language['label'] ?? '') . ' (' . (string)($language['code'] ?? '') . ')';
    }

    private function _docxParagraph(string $text, bool $bold = false, int $size = 0, string $color = ''): string
    {
        $rPr = '';
        if ($bold) $rPr .= '<w:b/>';
        if ($color !== '') $rPr .= '<w:color w:val="' . $color . '"/>';
        if ($size > 0) $rPr .= '<w:sz w:val="' . $size . '"/>';
        $run = $text === '' ? '' : '<w:r>' . ($rPr !== '' ? "<w:rPr>$rPr</w:rPr>" : '')
            . '<w:t xml:space="preserve">' . $this->_xmlEscape($this->_xmlText($text)) . '</w:t></w:r>';
        return '<w:p>' . $run . '</w:p>';
    }

    /**
     * Tabellenzeile mit einer Zelle; je Eintrag in $lines ein Absatz.
     */
    private function _docxRow(array $lines, bool $shaded, bool $bold = false): string
    {
        $tcPr = '<w:tcPr><w:tcW w:w="9638" w:type="dxa"/>'
            . ($shaded ? '<w:shd w:val="clear" w:color="auto" w:fill="D9D9D9"/>' : '')
            . '</w:tcPr>';
        $paragraphs = '';
        foreach ($lines as $line) {
            $paragraphs .= $this->_docxParagraph((string)$line, bold: $bold, size: $shaded ? 16 : 0, color: $shaded ? '404040' : '');
        }
        if ($paragraphs === '') $paragraphs = '<w:p/>';
        return '<w:tr><w:tc>' . $tcPr . $paragraphs . '</w:tc></w:tr>';
    }

    private function _docxTable(string $rows): string
    {
        $borders = '';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $side) {
            $borders .= '<w:' . $side . ' w:val="single" w:sz="4" w:space="0" w:color="999999"/>';
        }
        return '<w:tbl><w:tblPr><w:tblW w:w="9638" w:type="dxa"/><w:tblBorders>' . $borders . '</w:tblBorders>'
            . '<w:tblCellMar><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="9638"/></w:tblGrid>' . $rows . '</w:tbl>';
    }

    private function _xmlEscape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Zeichen entfernen, die in XML 1.0 nicht erlaubt sind (Steuerzeichen außer
     * Tab, LF, CR) - sie kämen aus fehlerhaften Eingaben und machten die Datei
     * unlesbar.
     */
    private function _xmlText(string $text): string
    {
        return preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? $text;
    }

    // ====================
    // Datenzugriff

    /**
     * `record`-Zeile eines produktiven, nicht gelöschten Datensatzes (gemerkt).
     */
    private function _recordRow(int $recordId): ?array
    {
        static $rows = [];
        if (array_key_exists($recordId, $rows)) return $rows[$recordId];
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `record` WHERE `record_id` = ? AND `tmp` = 0 AND `deleted` = 0", 'i', [$recordId]);
        $row = $this->db->fetch();
        $this->db->popResult();
        // nur die Zeilen des laufenden Exports merken - fremde Datensätze
        // (Verknüpfungsziele) können viele sein
        if (count($rows) > 5000) $rows = [];
        $rows[$recordId] = $row;
        return $row;
    }

    /**
     * Die Meta-Spalten eines Datensatzes für das XML-Attribut-Set: alles außer den
     * abgeleiteten Baumspalten und den Arbeitskopie-Kennzeichen.
     */
    private function _metaColumns(array $row): array
    {
        $skip = ['tmp', 'origin_id', 'locked', 'deleted', 'lft', 'rgt', 'depth', 'parent_ids', 'cache'];
        return array_diff_key($row, array_flip($skip));
    }

    /**
     * Inhalts-Cache eines Datensatzes in einer Sprache; fehlt die Zeile, wird der
     * Cache neu aufgebaut. Führt die Instanz des Datensatzes die Sprache nicht
     * (Verknüpfungsziel in einer anderen Instanz), gilt dessen Hauptsprache.
     */
    private function _cacheOf(int $recordId, int $languageId, array $row): array
    {
        $instanceId = (int)($row['instance_id'] ?? 0);
        if (!in_array($languageId, $this->instanceCache->languageIds($instanceId), true)) {
            $languageId = $this->instanceCache->primaryLanguageId($instanceId);
        }
        $key = "$recordId.$languageId";
        if (isset($this->cacheMemo[$key])) return $this->cacheMemo[$key];
        $cache = $this->cms->loadRecordCache($recordId, $languageId);
        if ($cache === null) {
            $cache = $this->cms->createRecordCache($recordId, (int)$row['area_id'], $instanceId, $languageId);
        }
        if (count($this->cacheMemo) > 2000) $this->cacheMemo = [];
        $this->cacheMemo[$key] = $cache;
        return $cache;
    }

    /**
     * Zeilen einer data_*-Tabelle zu einem Feld: language_id, data, empty.
     */
    private function _dataRows(int $recordId, int $fieldId, string $type): array
    {
        $suffix = $this->cms->dataSuffix[$type] ?? '';
        if ($suffix === '' || $suffix === '_none_' || $suffix === 'ids') return [];
        $table = 'data_' . $suffix;
        $emptyCol = $this->cms->hasEmptyFlag($type) ? ', `empty`' : '';
        $this->db->pushResult();
        $this->db->query("SELECT `language_id`, `data`{$emptyCol} FROM `{$table}` WHERE `record_id` = ? AND `field_id` = ? ORDER BY `language_id` ASC", 'ii', [$recordId, $fieldId]);
        $rows = [];
        while ($row = $this->db->fetch()) {
            $rows[] = [
                'language_id' => $row['language_id'] ?? null,
                'data' => $row['data'],
                'empty' => (int)($row['empty'] ?? 0),
            ];
        }
        $this->db->popResult();
        return $rows;
    }

    /**
     * Verknüpfungen eines Feldes: dest_id, position (in Reihenfolge).
     */
    private function _relationRows(int $recordId, int $fieldId): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT `dest_id`, `position` FROM `data_rel` WHERE `src_id` = ? AND `field_id` = ? ORDER BY `position` ASC, `dest_id` ASC", 'ii', [$recordId, $fieldId]);
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        return $rows;
    }

    /**
     * data_fil-Zeilen eines Datei-Feldes (alle Sprachen).
     */
    private function _fileRows(int $recordId, int $fieldId): array
    {
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `data_fil` WHERE `record_id` = ? AND `field_id` = ? ORDER BY `language_id` ASC", 'ii', [$recordId, $fieldId]);
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        return $rows;
    }

    /**
     * data_fil-Zeile einer Sprache; ohne eigene Zeile die erste (einsprachige
     * Felder führen dieselben Metadaten in allen Sprachen).
     */
    private function _fileRow(int $recordId, int $fieldId, int $languageId): ?array
    {
        $rows = $this->_fileRows($recordId, $fieldId);
        foreach ($rows as $row) {
            if ((int)$row['language_id'] === $languageId) return $row;
        }
        return $rows[0] ?? null;
    }

    /**
     * Mediendatei ins Exportverzeichnis kopieren:
     * media/{record_id}_{field_id}_{language_id}-{filename}. Jede physische Datei
     * nur einmal (Fallback-Zeilen anderer Sprachen zeigen auf dieselbe Datei).
     *
     * @return string  relativer Pfad in der Zip-Datei; '' wenn die Datei fehlt
     */
    private function _copyFile(array $row, int $fieldId, array $fileRow, string $dir): string
    {
        $recordId = (int)$row['record_id'];
        $fileLanguageId = $this->cms->fileLanguageId($fileRow);
        $extension = (string)$fileRow['extension'];
        $source = (string)$this->config->get('paths.media_dir') . '/'
            . $this->cms->mediaPath($fieldId, $recordId, $fileLanguageId, $extension, (int)($row['instance_id'] ?? 0));
        if (isset($this->copiedFiles[$source])) return $this->copiedFiles[$source];
        if (!is_file($source)) return '';

        $filename = basename(str_replace('\\', '/', (string)($fileRow['filename'] ?? '')));
        $filename = preg_replace('/[\x00-\x1f\/:*?"<>|]/', '_', $filename) ?? '';
        if ($filename === '') $filename = $recordId . '.' . $extension;
        $target = 'media/' . $recordId . '_' . $fieldId . '_' . (int)$fileLanguageId . '-' . $filename;
        if (!is_dir("$dir/media") && !$this->system->createDir($dir, 'media')) return '';
        if (!copy($source, "$dir/$target")) return '';
        $this->copiedFiles[$source] = $target;
        return $target;
    }

    private function _areaOf(int $areaId): ?array
    {
        if (array_key_exists($areaId, $this->areaMemo)) return $this->areaMemo[$areaId];
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `area` WHERE `area_id` = ? AND `deleted` = 0", 'i', [$areaId]);
        $row = $this->db->fetch();
        $this->db->popResult();
        return $this->areaMemo[$areaId] = $row;
    }

    private function _schemaOf(int $areaId): array
    {
        if ($areaId < 1) return [];
        return $this->schemaMemo[$areaId] ??= $this->record->virtualSchema($areaId, false);
    }

    private function _adminId(): int
    {
        return (int)($this->session->get('admin_id') ?? 0);
    }

    private function _adminName(): string
    {
        $details = $this->session->get('details');
        if (is_array($details) && !empty($details['name'])) return (string)$details['name'];
        $adminId = $this->_adminId();
        if ($adminId < 1) return '';
        $this->db->pushResult();
        $this->db->query("SELECT `name` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
        $name = (string)($this->db->fetchNum()[0] ?? '');
        $this->db->popResult();
        return $name;
    }

    // ====================
    // Hintergrund

    /**
     * Auftrag als Hintergrundprozess starten: JSON-Datei nach var/tmp, dann
     * Core/cron/record_export.php (wie RecordBatch::start()).
     *
     * @param array       $job    siehe Klassenkommentar
     * @param string|null $error  Grund eines Fehlschlags (per Referenz)
     * @return array|null         ['pid' => int, 'token' => string, 'id' => int] oder null
     */
    public function start(array $job, ?string &$error = null): ?array
    {
        $error = '';
        $phpExec = (string)$this->config->get('paths.php');
        if ($phpExec === '' || !is_executable($phpExec)) {
            $error = "Error: 'php' is not executable (paths.php)";
            $this->system->log('export-errors', $error);
            return null;
        }

        $token = $this->crypt->randomKey(16);
        $tmpDir = (string)$this->config->get('paths.tmp_dir');
        $jobFile = $tmpDir . '/export-' . $token . '.json';
        $job['token'] = $token;
        $job['admin_id'] = $this->_adminId();
        if (file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            $error = "Error: could not write job file {$jobFile}";
            $this->system->log('export-errors', $error);
            return null;
        }

        $command = [
            $phpExec,
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'record_export.php',
            '--job=' . $jobFile,
            '--token=' . $token,
        ];
        $result = $this->bgproc->start($command, self::BGPROC_TYPE, $token, null, null, $error);
        if ($result === null) {
            @unlink($jobFile);
            $this->system->log('export-errors', "start failed: {$error}");
        }
        return $result;
    }

    /**
     * Auftragsdatei des Hintergrundprozesses lesen (und entfernen).
     */
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
     * Ergebnis des Hintergrundprozesses als interne Nachricht an den auslösenden
     * Admin: Zusammenfassung, Download-Link (HTML-Teil) und wie lange er gilt.
     * Bei einem Fehler die Meldung.
     *
     * @return string  Betreff der Nachricht
     */
    public function report(array $job, array $result): string
    {
        $adminId = (int)($job['admin_id'] ?? 0);
        $areaLabel = !empty($job['area_ids'])
            ? $this->cms->snippet('record.label_export_global')
            : $this->cms->_strip_tags($this->cms->textOrSnippet((string)($this->_areaOf((int)($job['area_id'] ?? 0))['label'] ?? '')));
        $type = strtoupper((string)($job['type'] ?? ''));
        $subject = str_replace(['{area}', '{type}'], [$areaLabel, $type], $this->cms->snippet('record.export_report_subject'));
        if ($adminId < 1) return $subject;

        if (!empty($result['error'])) {
            $body = $this->cms->snippet('record.error_export_failed') . ' ' . (string)$result['error'];
            $this->message->sendSystem($adminId, $subject, $body);
            return $subject;
        }
        $summary = $this->summaryText($result);
        $validity = $this->validityText((string)$result['zip']);
        $url = $this->downloadUrl((string)$result['zip']);
        $body = $summary . "\n" . (string)$result['zip'] . ' (' . $this->system->convertSize((float)$result['size']) . ")\n"
            . $validity . "\n" . $url;
        $html = '<p><a href="' . htmlspecialchars($url) . '" target="_blank"><span class="icon" aria-hidden="true">download</span> '
            . htmlspecialchars($this->cms->snippet('record.button_export_download')) . '</a></p>';
        $this->message->sendSystem($adminId, $subject, $body, $html);
        return $subject;
    }

    /**
     * "Export abgeschlossen: n Datensätze, m Dateien."
     */
    public function summaryText(array $result): string
    {
        return str_replace(
            ['{records}', '{files}'],
            [
                $this->cms->snippet('record.x_records', ['count' => (int)($result['records'] ?? 0)]),
                $this->cms->snippet('record.export_x_files', ['count' => (int)($result['files'] ?? 0)]),
            ],
            $this->cms->snippet('record.export_summary')
        );
    }

    /**
     * "Der Link gilt bis {Datum} (n Stunden) - die Datei wird nach dem Download gelöscht."
     */
    public function validityText(string $zip): string
    {
        $path = (string)$this->config->get('paths.var_dir') . '/export/' . basename($zip);
        $time = is_file($path) ? (int)filemtime($path) : time();
        $until = $this->cms->_formatDate($this->cms->utcToUserTZ($this->validUntil($time)));
        return str_replace(
            ['{until}', '{hours}'],
            [$until, (string)$this->retentionHours()],
            $this->cms->snippet('record.note_export_validity')
        );
    }

    public function downloadUrl(string $zip): string
    {
        return (string)$this->config->get('paths.cms') . '/backendModules/Record/record_download.php?file=' . rawurlencode(basename($zip));
    }

    // ====================
    // Aufräumen

    /**
     * Exportdateien löschen, die älter sind als admin.export_retention_hours -
     * Zip-Dateien und liegen gebliebene Verzeichnisse (abgebrochene Exporte).
     * Aufgerufen von der Zeitplaner-Aufgabe tmpcleanup (SchedulerRunner).
     *
     * @return int  Anzahl der entfernten Einträge
     */
    public function cleanup(): int
    {
        $dir = (string)$this->config->get('paths.var_dir') . '/export';
        if (!is_dir($dir)) return 0;
        $limit = time() - $this->retentionHours() * 3600;
        $removed = 0;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = "$dir/$entry";
            if ((int)filemtime($path) > $limit) continue;
            if (is_dir($path)) {
                $this->_removeDir($path);
                $removed++;
            }
            elseif (str_ends_with($entry, '.zip') && @unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }

    // ====================
    // Packen und Aufräumen

    private function _zipDir(string $dir, string $zipPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('zip not writable: ' . basename($zipPath));
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = substr((string)$item->getPathname(), strlen($dir) + 1);
            if ($item->isDir()) $zip->addEmptyDir($relative);
            else $zip->addFile((string)$item->getPathname(), $relative);
        }
        $zip->close();
    }

    private function _removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir((string)$item->getPathname()) : unlink((string)$item->getPathname());
        }
        rmdir($dir);
    }
}
