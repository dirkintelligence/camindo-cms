<?php
/**
 * Import von Datensätzen - die Masken (Erweiterung für RecordBackend).
 *
 * Einstiegspunkt (Script in backend/):
 *
 *     record_import.php  -> import()
 *
 * Erreichbar über das Menü (more_vert) rechts in der Datensatzliste (global: in der
 * Ordnerliste); der Aufruf öffnet einen eigenen Layer über der Liste
 * (cApp.layers.add). Die Arbeit macht RecordImport - im Request oder als
 * Hintergrundprozess.
 *
 * NAVIGATION: Zur Liste geht es aus dem Layer nur mit cApp.layers.close() zurück;
 * innerhalb der Schritte wird der Layer mit cApp.layers.replace() ersetzt. Abbrechen
 * mit Importverzeichnis läuft über function=cancel (replace), das aufräumt und dann
 * schließt.
 *
 * VIER SCHRITTE, EIN SCRIPT (function)
 * --------------------
 *     ''        Importdatei wählen: Upload (Zip, XML, CSV, DOCX) oder eine Datei aus
 *               var/upload (wie beim Upload-Feld der Eingabemaske)
 *     prepare   Datei nach var/tmp/import-{token}/ bringen, Zip entpacken
 *               (RecordImport::prepare()). Genau eine Importdatei: weiter zur
 *               Analyse; mehrere: Auswahl
 *     analyze   die gewählte Datei prüfen und die Einstellungen zeigen -
 *               je nach Typ (XML: Herkunft, Bereiche; CSV: Spaltenzuordnung, die
 *               CSV-Einstellungen lesen die Datei neu ein; DOCX: Übersetzungs-
 *               optionen), dazu Modus, Einfügeposition neuer Datensätze (Bereich
 *               mit Baum bzw. Handsortierung: Vater und Vorgänger, vorgewählt der
 *               letzte vorhandene - siehe RecordImport, EINFÜGEPOSITION), Umfang
 *               (nur CSV: Zeilen), Schreiben erzwingen, Hintergrund (ab
 *               admin.import_background_threshold Datensätzen fest), erweiterte
 *               Analyse
 *     preview   Analyse (RecordImport::analyze()): was der Import tun würde, ohne
 *               zu schreiben - je Bereich Struktur, Felder, Templates, Datensätze
 *               (gezählt; erweitert: einzeln). Eigener Layer über den Einstellungen
 *     run       importieren; Protokoll auf der Seite bzw. als interne Nachricht.
 *               Das Importverzeichnis ist danach weg - die Einstellungsmaske
 *               darunter wird sofort entfernt (cApp.layers.removeReferer()),
 *               "Schließen" schließt nur noch das Protokoll
 *     cancel    Importverzeichnis entfernen, Layer schließen
 *
 * Das Importverzeichnis (Hiddenfeld 'dir') reist durch die Schritte und wird nach
 * dem Import bzw. beim Abbrechen entfernt.
 *
 * GLOBAL (global=1, ohne area_id - Startseite des Moduls)
 * --------------------
 * XML: Zielebene (Ordner für neue Bereiche) und je Bereich der Datei Modus und
 * Update-Kriterium für Struktur, Datensätze und Templates (RecordImport, Job
 * 'areas'). CSV und DOCX brauchen einen Zielbereich: die Maske bietet die
 * Inhaltsbereiche an und springt mit der bereitgestellten Datei in die Maske des
 * gewählten Bereichs.
 *
 * @see Camindo\Core\Modules\Record\RecordImport         die Arbeit
 * @see Camindo\Core\Modules\Record\RecordBackendExport  das Gegenstück
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\DirectoryHelpers;
use Camindo\Core\InstanceCache;
use Camindo\Core\MassDeletion;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Area\Area;

class RecordBackendImport extends RecordBackend {

    private string $listQuery = '';
    /** Aufruf von der Startseite (ohne Bereich) */
    private bool $isGlobal = false;
    private string $dir = '';
    private string $file = '';
    private string $type = '';

    public function __construct(
        Config $config,
        Backend $backend,
        Crypt $crypt,
        Session $session,
        Database $db,
        System $system,
        Cms $cms,
        MassDeletion $massDeletion,
        Area $area,
        Record $record,
        InstanceCache $instanceCache,
        RecordService $service,
        RecordPresenter $presenter,
        private RecordImport $import,
    )
    {
        parent::__construct($config, $backend, $crypt, $session, $db, $system, $cms, $massDeletion, $area, $record, $instanceCache, $service, $presenter);
    }

    /**
     * Import (backend/record_import.php).
     *
     * Request: area_id, function ('' | prepare | analyze | preview | run | cancel), dir,
     *          file, importfile ($_FILES) | serverfile, mode, count, skip (csv), force,
     *          background, extended, source_area_id, data[csv][...], mapping[],
     *          data[docx][...], data[parent_id], data[previous_id]
     *
     * Schritte: Datei wählen -> Einstellungen (analyze) -> Analyse (preview, eigener
     * Layer über den Einstellungen: was der Import tun würde, ohne zu schreiben) ->
     * Import (run, ersetzt den Analyse-Layer; "Schließen" schließt beide).
     */
    public function import(): void
    {
        echo $this->backend->template('container_header');
        $this->function = 'list';
        $this->_resolveParams();
        $function = (string)($_REQUEST['function'] ?? '');
        $this->isGlobal = !empty($_REQUEST['global']) && $this->areaId < 1;
        $this->listQuery = $this->isGlobal ? 'global=1' : 'area_id=' . $this->areaId;

        if ($this->function !== 'list' || ($this->areaId < 1 && !$this->isGlobal)) {
            if ($this->areaId < 1) $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }

        // Zusatzrechte: ohne ein erlaubtes Format kein Import; global (XML) nur
        // Superuser; im Bereich muss die Gruppe anlegen dürfen (Änderungen an
        // einzelnen Datensätzen prüft Record::save() je Datensatz)
        if (empty($this->allowedTypes('import'))
            || ($this->isGlobal && !$this->backend->isSuperuser())
            || (!$this->isGlobal && !$this->backend->access()->areaCreate($this->areaId))
        ) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        $this->backend->setNaviLabel('#record.headline_import');
        if (!$this->isGlobal) {
            echo $this->backend->template('record.prefix_content_area', [
                '{text}' => str_replace('{area_label}', $this->cms->textOrSnippet((string)$this->areaData['label']), $this->cms->snippet('area.headline_area_label'))
            ]);
        }
        echo $this->backend->headline($this->isGlobal ? '#record.headline_import_global' : '#record.headline_import');

        $this->dir = (string)($_REQUEST['dir'] ?? '');
        $this->file = str_replace('\\', '/', (string)($_REQUEST['file'] ?? ''));
        if ($this->dir !== '' && $this->import->importDir($this->dir) === null) {
            $this->dir = '';
        }

        switch ($function) {
            case 'cancel':
                // Löscht das Importverzeichnis - nur per POST (17-csrf.md §2.7).
                // Geschlossen wird der Layer in jedem Fall; ein übrig gebliebenes
                // Verzeichnis räumt spätestens das Modul Tools ab.
                if ($this->dir !== '' && $this->backend->requirePost()) {
                    $this->import->removeDir($this->dir);
                }
                $this->backend->script('cApp.layers.close();');
                break;
            case 'prepare':
                $this->_importPrepare();
                break;
            case 'analyze':
                $this->_importAnalyze();
                break;
            case 'preview':
                $this->_importPreview();
                break;
            case 'run':
                $this->_importRun();
                break;
            default:
                $this->_importUploadForm();
        }
        echo $this->backend->template('container_footer');
    }

    // ====================
    // Schritt 0: Datei wählen

    private function _importUploadForm(): void
    {
        // wie das Upload-Feld der Eingabemaske: Datei-Input plus Knopf für eine Datei
        // aus var/upload (Record/server_file.php -> ajax_import_file.php)
        $preview = '';
        $serverFile = trim((string)($_REQUEST['serverfile'] ?? ''));
        if ($serverFile !== '') $preview = $this->_serverFilePreviewHtml($serverFile);
        echo '<form action="Record/record_import.php" method="post" enctype="multipart/form-data">';
        $this->backend->formInit('record.record_import_form_upload', [
            '{upload_field}' => $this->backend->template('record.import_upload_field', ['{preview}' => $preview]),
        ]);
        $this->backend->formHidden('function', 'prepare');
        $this->backend->formHidden('area_id', $this->areaId);
        if ($this->isGlobal) $this->backend->formHidden('global', 1);
        echo $this->backend->form('record.form_import_next_close', ['{list_query}' => $this->listQuery]);
        echo '</form>';
    }

    /**
     * Ajax (backend/ajax_import_file.php): gewählte Server-Datei als Vorschau mit
     * Hiddenfeld 'serverfile' in die Import-Maske setzen (cApp.ui.selectUploadFile()
     * mit data-select-url).
     *
     * Request: filename (relativ zu paths.upload_dir)
     */
    public function serverFilePreview(): void
    {
        echo $this->_serverFilePreviewHtml((string)($_REQUEST['filename'] ?? ''));
    }

    private function _serverFilePreviewHtml(string $filename): string
    {
        try {
            $relative = DirectoryHelpers::checkPath($filename);
        }
        catch (\Throwable $e) {
            return '';
        }
        $path = (string)$this->config->get('paths.upload_dir') . '/' . $relative;
        $size = is_file($path) ? str_replace('{text}', $this->system->convertSize((float)filesize($path)), $this->cms->snippet('parenthetical_note')) : '';
        return $this->backend->template('record.import_server_file_preview', [
            '{file}' => htmlspecialchars($relative),
            '{text}' => htmlspecialchars(basename($relative)) . htmlspecialchars($size),
        ]);
    }

    // ====================
    // Schritt 1a: bereitstellen

    private function _importPrepare(): void
    {
        $upload = $_FILES['importfile'] ?? null;
        $serverFile = trim((string)($_REQUEST['serverfile'] ?? ''));
        if ($upload && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int)$upload['error'] !== UPLOAD_ERR_OK || empty($upload['size'])) {
                $this->cms->error($this->cms->snippet('error_file_upload'));
                $this->errors();
                $this->_importUploadForm();
                return;
            }
            $result = $this->import->prepare((string)$upload['tmp_name'], (string)$upload['name'], true);
        }
        elseif ($serverFile !== '') {
            try {
                $relative = DirectoryHelpers::checkPath($serverFile);
            }
            catch (\Throwable $e) {
                $relative = '';
            }
            $path = (string)$this->config->get('paths.upload_dir') . '/' . $relative;
            $result = ($relative === '' || !is_file($path))
                ? ['error' => $this->cms->snippet('error_file_move_uploaded')]
                : $this->import->prepare($path, basename($relative), false);
        }
        else {
            $result = ['error' => $this->cms->snippet('record.error_import_no_upload')];
        }
        if (!empty($result['error'])) {
            $this->cms->error((string)$result['error']);
            $this->errors();
            $this->_importUploadForm();
            return;
        }
        $this->dir = (string)$result['dir'];
        $files = (array)$result['files'];
        if (count($files) === 1) {
            $this->file = (string)$files[0];
            $this->_importAnalyze();
            return;
        }
        // mehrere Importdateien: wählen
        $radios = '';
        foreach ($files as $index => $file) {
            $radios .= $this->backend->template('record.import_file_radio', [
                '{value}' => htmlspecialchars($file),
                '{label}' => htmlspecialchars($file) . ' ' . $this->_badge('secondary', strtoupper($this->import->typeOf($file))),
                '{id}' => 'c9-import-file-' . (int)$index,
                '{checked}' => $index === 0 ? ' checked' : '',
            ]);
        }
        echo '<form action="Record/record_import.php" method="post">';
        $this->backend->formInit('record.record_import_form_files', ['{files}' => $radios]);
        $this->backend->formHidden('function', 'analyze');
        $this->backend->formHidden('area_id', $this->areaId);
        if ($this->isGlobal) $this->backend->formHidden('global', 1);
        $this->backend->formHidden('dir', $this->dir);
        echo $this->backend->form('record.form_import_next', ['{list_query}' => $this->_cancelQuery()]);
        echo '</form>';
    }

    /**
     * Query-String des Abbrechen-Knopfs: entfernt das Importverzeichnis.
     */
    private function _cancelQuery(): string
    {
        return $this->listQuery . '&function=cancel&dir=' . rawurlencode($this->dir);
    }

    // ====================
    // Schritt 1b: analysieren, Einstellungen

    private function _importAnalyze(): void
    {
        $path = $this->dir !== '' ? $this->import->filePath($this->dir, $this->file) : null;
        $this->type = $this->import->typeOf($this->file);
        if ($path === null || $this->type === '') {
            $this->cms->error($this->cms->snippet('record.error_import_no_file'));
            $this->errors();
            $this->_importUploadForm();
            return;
        }
        if (!$this->_typeAllowed()) return;
        if ($this->isGlobal) {
            if ($this->type === 'xml') $this->_analyzeXmlGlobal($path);
            else $this->_chooseAreaForm();
            return;
        }
        match ($this->type) {
            'xml'  => $this->_analyzeXml($path),
            'csv'  => $this->_analyzeCsv($path),
            'docx' => $this->_analyzeDocx($path),
        };
    }

    /**
     * CSV-Ziele auf schreibbare Sprachen der Benutzergruppe beschränken: Ziele
     * "{feld}.{code}" nach ihrem Sprachkürzel, mehrsprachige Felder ohne Kürzel
     * ("{feld}") nach der Hauptsprache der Instanz (12-group-rights.md).
     *
     * @param array $targets  RecordImport::csvTargets()
     * @return array          Teilmenge in derselben Reihenfolge
     */
    private function _writableTargets(array $targets): array
    {
        $access = $this->backend->access();
        if (!$access->isLoaded() || $access->isSuperuser()) return $targets;
        $instanceId = (int)($this->areaData['instance_id'] ?? 0);
        $languages = $this->instanceCache->languages($instanceId);
        $primaryId = $this->instanceCache->primaryLanguageId($instanceId);
        $byCode = [];
        foreach ($languages as $languageId => $language) $byCode[(string)$language['code']] = (int)$languageId;
        $schema = $this->record->virtualSchema($this->areaId, false);
        $result = [];
        foreach ($targets as $key => $label) {
            $languageId = null;
            if (isset($schema[$key])) {
                if ($this->cms->isMultilingualField($schema[$key])) $languageId = $primaryId;
            }
            elseif (($dot = strrpos((string)$key, '.')) !== false) {
                $suffix = substr((string)$key, $dot + 1);
                if (isset($byCode[$suffix])) $languageId = $byCode[$suffix];
            }
            if ($languageId !== null && !$access->languageWrite($languageId)) continue;
            $result[$key] = $label;
        }
        return $result;
    }

    /**
     * Darf die Gruppe Dateien dieses Formats importieren (allowedTypes())? Sonst
     * Meldung, Importverzeichnis entfernen und zurück zur Dateiauswahl.
     */
    private function _typeAllowed(): bool
    {
        if (in_array($this->type, $this->allowedTypes('import'), true)) return true;
        if ($this->dir !== '') $this->import->removeDir($this->dir);
        $this->dir = '';
        $this->cms->error(str_replace('{type}', strtoupper($this->type), $this->cms->snippet('record.error_import_type_denied')));
        $this->errors();
        $this->_importUploadForm();
        return false;
    }

    /**
     * Global mit CSV/DOCX: einen Zielbereich wählen - weiter geht es in der Maske
     * dieses Bereichs mit der bereits bereitgestellten Datei.
     */
    private function _chooseAreaForm(): void
    {
        $areaOptions = [];
        $this->db->query(
            "SELECT `area_id`, `depth`, `label` FROM `area` WHERE `type` IN ('record','profile','ugc') AND `deleted` = 0"
            . $this->backend->areaAccessSql(writable:true) . " ORDER BY `lft` ASC"
        );
        foreach ($this->db->fetchAll() as $areaRow) {
            $areaOptions[(int)$areaRow['area_id']] = $this->_indent((int)$areaRow['depth']) . $this->cms->textOrSnippet((string)$areaRow['label']);
        }
        $options = $this->backend->options($areaOptions);
        echo '<form action="Record/record_import.php" method="post">';
        $this->backend->formInit('record.record_import_form_area', [
            '{info}' => $this->_fileInfoHtml(),
            '{options}' => $options,
        ]);
        $this->backend->formHidden('function', 'analyze');
        $this->backend->formHidden('dir', $this->dir);
        $this->backend->formHidden('file', $this->file);
        echo $this->backend->form('record.form_import_next', ['{list_query}' => $this->_cancelQuery()]);
        echo '</form>';
    }

    /**
     * Kopf der Einstellungsmaske: Datei, Meldungen.
     */
    private function _analysisHead(array $errors, array $warnings): string
    {
        $html = '';
        foreach ($errors as $error) {
            $html .= $this->backend->template('alert_danger', ['{text}' => htmlspecialchars($error)]);
        }
        foreach ($warnings as $warning) {
            $html .= $this->backend->template('alert_warning', ['{text}' => htmlspecialchars($warning)]);
        }
        return $html;
    }

    private function _infoRow(string $label, string $valueHtml): string
    {
        return $this->backend->template('record.import_info_row', ['{label}' => $label, '{value}' => $valueHtml]);
    }

    /**
     * Die Hiddenfelder der Einstellungsmaske.
     */
    private function _analysisHidden(): void
    {
        $this->backend->formHidden('function', 'preview');
        $this->backend->formHidden('area_id', $this->areaId);
        if ($this->isGlobal) $this->backend->formHidden('global', 1);
        $this->backend->formHidden('dir', $this->dir);
        $this->backend->formHidden('file', $this->file);
        $this->backend->formHidden('type', $this->type);
    }

    /**
     * Modus-Auswahl (RecordImport::MODES) als Radio-Gruppe.
     */
    private function _modeHtml(string $default): string
    {
        $mode = (string)($_REQUEST['mode'] ?? $default);
        if (!in_array($mode, RecordImport::MODES, true)) $mode = $default;
        $replace = [];
        foreach (RecordImport::MODES as $option) {
            $replace['{checked_' . $option . '}'] = $mode === $option ? ' checked' : '';
        }
        return $this->backend->template('record.import_mode', $replace);
    }

    /**
     * Modus-Auswahl der globalen Maske als Select (mit 'ignore').
     */
    private function _modeSelect(string $name, string $selected, ?array $allowed = null): string
    {
        $options = [];
        foreach ($allowed ?? RecordImport::GLOBAL_MODES as $option) {
            $options[$option] = $this->cms->snippet('record.import_mode_' . $option);
        }
        return $this->backend->template('select_sm', [
            '{name}' => htmlspecialchars($name),
            '{options}' => $this->backend->options($options, $selected),
        ]);
    }

    private function _bySelect(string $name, array $options, string $selected): string
    {
        return $this->backend->template('select_sm', [
            '{name}' => htmlspecialchars($name),
            '{options}' => $this->backend->options($options ?: ['' => ''], $selected),
        ]);
    }

    /**
     * Global, XML: Zielebene, je Bereich der Datei Modus und Kriterium für
     * Struktur, Datensätze und Templates.
     */
    private function _analyzeXmlGlobal(string $path): void
    {
        $analysis = $this->import->analyzeXml($path, 0);
        echo $this->_analysisHead($analysis['errors'], $analysis['warnings']);
        if (!$analysis['ok']) {
            echo $this->backend->template('record.form_import_cancel', ['{list_query}' => $this->_cancelQuery()]);
            return;
        }
        $header = $analysis['header'];
        $info = $this->_fileInfoHtml()
            . $this->_infoRow($this->cms->snippet('record.label_import_database'), htmlspecialchars($header['database']))
            . $this->_infoRow($this->cms->snippet('record.label_import_version'), htmlspecialchars($header['cms_version']))
            . $this->_infoRow($this->cms->snippet('record.label_import_exported'), htmlspecialchars($header['exported_at'] . ($header['admin'] !== '' ? ' (' . $header['admin'] . ')' : '')))
            . $this->_infoRow($this->cms->snippet('record.label_import_records'), htmlspecialchars($this->cms->snippet('record.x_records', ['count' => (int)$analysis['count']])))
            . $this->_infoRow($this->cms->snippet('record.label_import_templates'), (int)$analysis['templates'])
            . $this->_infoRow($this->cms->snippet('record.label_import_files'), $this->cms->snippet(!empty($header['files']) ? 'yes' : 'no'));

        // Mandant neuer Bereiche: aus der Datei (nur aus derselben Datenbank) oder gewählt
        $sameDb = $this->import->isSameDatabase($path);
        $instanceId = isset($_REQUEST['instance_id']) ? (int)$_REQUEST['instance_id'] : 0;
        $instances = [];
        if ($sameDb) {
            $instances[0] = $this->cms->snippet('record.option_import_instance_file');
        }
        $instanceIds = array_map('intval', (array)($this->instanceCache->all()['instance_ids'] ?? []));
        if (!$sameDb && $instanceId < 1) $instanceId = (int)($this->instanceCache->all()['default_id'] ?? ($instanceIds[0] ?? 0));
        foreach ($instanceIds as $id) {
            $instance = $this->instanceCache->instance($id) ?? [];
            $instances[$id] = $this->cms->textOrSnippet((string)($instance['label'] ?? $id));
        }
        $instanceOptions = $this->backend->options($instances, $instanceId);
        $instanceOptionsLabel = $this->backend->template('input_label_tt', [
            '{id}' => 'c9-import-instance',
            '{label}' => $this->cms->snippet('record.label_import_instance'),
            '{tooltip}' => $this->cms->snippet('record.note_import_instance')
        ]);
        // Zielebene: Ordner, je mit seiner Instanz (data-instance) - die Maske zeigt
        // nur die Ordner der gewählten Instanz (record_import_form_global)
        $folderId = (int)($_REQUEST['folder_id'] ?? 0);
        $folderOptions = $this->backend->template('record.import_folder_option', [
            '{value}' => 0,
            '{instance}' => 0,
            '{selected}' => $folderId === 0 ? ' selected' : '',
            '{label}' => $this->backend->escape($this->cms->snippet('record.option_import_root')),
        ]);
        $folderOptionsLabel = $this->backend->template('input_label_tt', [
            '{id}' => 'c9-import-folder',
            '{label}' => $this->cms->snippet('record.label_import_folder'),
            '{tooltip}' => $this->cms->snippet('record.note_import_folder')
        ]);
        $this->db->query("SELECT `area_id`, `instance_id`, `depth`, `label` FROM `area` WHERE `type` = 'folder' AND `deleted` = 0 ORDER BY `lft` ASC");
        foreach ($this->db->fetchAll() as $folder) {
            $id = (int)$folder['area_id'];
            $folderInstanceId = (int)$folder['instance_id'];
            // ein Ordner eines anderen Mandanten ist nicht wählbar
            $selected = $id === $folderId && ($instanceId < 1 || $folderInstanceId === $instanceId);
            $folderOptions .= $this->backend->template('record.import_folder_option', [
                '{value}' => $id,
                '{instance}' => $folderInstanceId,
                '{selected}' => $selected ? ' selected' : '',
                '{label}' => $this->backend->escape($this->_indent((int)$folder['depth']) . $this->cms->textOrSnippet((string)$folder['label'])),
            ]);
        }
        $defaultMode = $sameDb ? 'update_or_insert' : 'insert';

        // Je Bereich der Datei: Einrückung nach der Hierarchie der Datei
        $requested = (array)($_REQUEST['areas'] ?? []);
        $icons = ['folder' => 'folder', 'record' => 'draft', 'template' => 'extension', 'profile' => 'face', 'ugc' => 'chat_bubble'];
        $depths = [];
        $rows = '';
        foreach ($analysis['structure'] as $fileAreaId => $area) {
            $set = (array)($requested[$fileAreaId] ?? []);
            $parentId = (int)$area['parent_id'];
            $depths[$fileAreaId] = ($parentId > 0 && isset($depths[$parentId])) ? $depths[$parentId] + 1 : 0;
            $isContent = in_array((string)$area['type'], RecordImport::CONTENT_TYPES, true);
            $isTemplate = (string)$area['type'] === 'template';
            // Ziel: gleicher Name; die gleiche Id zählt nur bei einer Datei aus dieser
            // Datenbank (fremde Ids sind Zufall)
            $targetByName = (int)$area['target_by_name'];
            $targetById = $sameDb ? (int)$area['target_by_id'] : 0;
            $isNew = $targetByName < 1 && $targetById < 1;
            $target = $targetByName > 0
                ? $this->backend->teaser('area', $targetByName, linked: false)
                : ($targetById > 0
                    ? $this->backend->teaser('area', $targetById, linked: false) . ' ' . $this->_muted('(area_id)', small: true)
                    : $this->_muted($this->cms->snippet('record.note_import_area_new')));
            $prefix = "areas[{$fileAreaId}]";
            // Neuer Bereich: nur INSERT oder IGNORE, kein Update-Kriterium; Ids als
            // Kriterium nur aus derselben Datenbank
            $modes = $isNew ? ['insert', 'ignore'] : null;
            $byArea = $isNew ? [] : ($sameDb ? ['name' => 'name', 'area_id' => 'area_id'] : ['name' => 'name']);
            $byRecord = $isNew ? [] : ($sameDb ? ['slug' => 'slug / record_id', 'record_id' => 'record_id'] : ['slug' => 'slug / record_id']);
            $byTemplate = $isNew ? [] : ($sameDb ? ['name' => 'name', 'template_id' => 'template_id'] : ['name' => 'name']);
            $structureHtml = $this->_modeSelect("{$prefix}[structure]", (string)($set['structure'] ?? ($isNew ? 'insert' : $defaultMode)), $modes)
                . $this->_bySelect("{$prefix}[structure_by]", $byArea, (string)($set['structure_by'] ?? 'name'));
            $recordsHtml = '';
            if ($isContent && (int)$area['records'] > 0) {
                $recordsHtml = $this->_modeSelect("{$prefix}[records]", (string)($set['records'] ?? ($isNew ? 'insert' : $defaultMode)), $modes)
                    . $this->_bySelect("{$prefix}[records_by]", $byRecord, (string)($set['records_by'] ?? 'slug'));
            }
            $templatesHtml = '';
            if ($isTemplate && (int)$area['templates'] > 0) {
                $templatesHtml = $this->_modeSelect("{$prefix}[templates]", (string)($set['templates'] ?? ($isNew ? 'insert' : $defaultMode)), $modes)
                    . $this->_bySelect("{$prefix}[templates_by]", $byTemplate, (string)($set['templates_by'] ?? 'name'));
            }
            $rows .= $this->backend->template('record.import_scope_row', [
                '{indent}' => $depths[$fileAreaId] * 24,
                '{icon}' => $icons[(string)$area['type']] ?? 'draft',
                '{label}' => htmlspecialchars($this->cms->textOrSnippet((string)$area['label'])),
                '{counts}' => htmlspecialchars($this->cms->snippet('record.import_scope_counts', ['fields' => (int)$area['fields']]))
                    . ($isContent ? ', ' . htmlspecialchars($this->cms->snippet('record.x_records', ['count' => (int)$area['records']])) : '')
                    . ($isTemplate ? ', ' . (int)$area['templates'] . ' ' . htmlspecialchars($this->cms->snippet('record.label_import_templates')) : ''),
                '{type}' => htmlspecialchars((string)$area['type']),
                '{name_field}' => "{$prefix}[name]",
                '{name}' => htmlspecialchars((string)($set['name'] ?? $area['name'])),
                '{target}' => $target,
                '{structure}' => $structureHtml,
                '{records}' => $recordsHtml !== '' ? $recordsHtml : $this->_muted('-'),
                '{templates}' => $templatesHtml !== '' ? $templatesHtml : $this->_muted('-'),
            ]);
        }
        // Konverter der Thumbnail-Felder: Treffer und Entscheidung je Konverter der Datei
        $convertersHtml = $this->_convertersHtml((array)$analysis['converters'], $sameDb);
        // Pulldown je Spalte: ganze Spalte auf einen Modus setzen
        $columnItems = '';
        foreach (RecordImport::GLOBAL_MODES as $option) {
            $columnItems .= $this->backend->template('record.import_column_mode_item', [
                '{mode}' => $option,
                '{label}' => htmlspecialchars($this->cms->snippet('record.import_mode_' . $option)),
            ]);
        }

        echo '<form action="Record/record_import.php" method="post" data-layer="new">';
        $this->backend->formInit('record.record_import_form_global', [
            '{info}' => $info,
            '{folder_options_label}' => $folderOptionsLabel,
            '{folder_options}' => $folderOptions,
            '{instance_options_label}' => $instanceOptionsLabel,
            '{instance_options}' => $instanceOptions,
            '{rows}' => $rows,
            '{column_items}' => $columnItems,
            '{converters}' => $convertersHtml,
            '{options}' => $this->_optionsHtml((int)$analysis['count'], 0),
        ]);
        $this->_analysisHidden();
        echo $this->backend->form('record.form_import_run', ['{list_query}' => $this->_cancelQuery()]);
        echo '</form>';
    }

    /**
     * Global, XML: die Konverter der Thumbnail-Felder der Datei (RecordImport,
     * KONVERTER). Je Konverter der Treffer in der Zieldatenbank und die Wahl:
     * vorhandenen verwenden (Treffer bzw. ein beliebiger vorhandener Konverter),
     * neu anlegen (nur mit installiertem Prozessor), ignorieren. Vorgewählt ist,
     * was der Import automatisch täte. Ohne Konverter in der Datei: nichts.
     *
     * @param array $converters  RecordImport::converterMatches() (aus analyzeXml())
     */
    private function _convertersHtml(array $converters, bool $sameDb): string
    {
        if (empty($converters)) return '';
        $requested = (array)($_REQUEST['converters'] ?? []);
        // vorhandene Konverter als Alternativen
        $this->db->query("SELECT `converter_id`, `processor`, `label` FROM `converter` ORDER BY `type` ASC, `processor` ASC, `label` ASC");
        $existing = [];
        foreach ($this->db->fetchAll() as $row) {
            $existing[(int)$row['converter_id']] = $this->cms->textOrSnippet((string)$row['label']) . ' (' . $row['processor'] . ')';
        }
        $rows = '';
        foreach ($converters as $fileId => $converter) {
            $matchId = (int)$converter['match_id'];
            $installed = !empty($converter['installed']);
            $default = $matchId > 0 ? (string)$matchId : ($installed ? 'new' : 'ignore');
            $choice = (string)($requested[$fileId] ?? $default);
            if ($choice !== 'new' && $choice !== 'ignore' && !isset($existing[(int)$choice])) $choice = $default;
            if ($choice === 'new' && !$installed) $choice = 'ignore';

            $target = $matchId > 0
                ? $this->backend->teaser('converter', $matchId, linked: false) . ' '
                    . $this->_muted('(' . $this->cms->snippet('record.note_import_converter_match_' . $converter['match']) . ')', small: true)
                : ($installed
                    ? $this->_muted($this->cms->snippet('record.note_import_area_new'))
                    : $this->backend->template('text-danger', ['{text}' => $this->backend->escape(
                        str_replace('{processor}', (string)$converter['processor'], $this->cms->snippet('record.note_import_converter_not_installed')))]));

            $choices = [];
            if ($installed) {
                $choices['new'] = $this->cms->snippet('record.option_import_converter_new');
            }
            $choices['ignore'] = $this->cms->snippet('record.option_import_converter_ignore');
            $options = $this->backend->options($choices, $choice)
                . $this->backend->template('optgroup', [
                    '{label}' => $this->backend->escape($this->cms->snippet('record.option_import_converter_existing')),
                    '{options}' => $this->backend->options($existing, $choice),
                ]);

            $settings = [];
            if ((string)$converter['extension'] !== '') $settings[] = '.' . $converter['extension'];
            foreach ((array)$converter['details'] as $key => $value) {
                if (is_array($value) || (string)$value === '') continue;
                $settings[] = $key . '=' . $value;
            }
            $rows .= $this->backend->template('record.import_converter_row', [
                '{label}' => htmlspecialchars($this->cms->textOrSnippet((string)$converter['label'])),
                '{settings}' => htmlspecialchars(implode(', ', $settings)),
                '{processor}' => htmlspecialchars((string)$converter['processor']),
                '{target}' => $target,
                '{choice_field}' => "converters[{$fileId}]",
                '{options}' => $options,
            ]);
        }
        return $this->backend->template('record.import_converters', ['{rows}' => $rows]);
    }

    /**
     * Optionen aller Importer: Schreiben erzwingen, Hintergrund, erweiterte Analyse -
     * beim CSV-Import dazu Anzahl und Überspringen (in Zeilen der Datei).
     *
     * @param int  $total        Datensätze bzw. Zeilen in der Datei
     * @param int  $skipDefault  Vorgabe für Überspringen (CSV mit Kopfzeile: 1)
     * @param bool $rows         CSV: Anzahl/Überspringen anbieten, Zahlen sind Zeilen
     */
    private function _optionsHtml(int $total, int $skipDefault, bool $rows = false): string
    {
        $rowsHtml = '';
        if ($rows) {
            $rowsHtml = $this->backend->template('record.import_options_rows', [
                '{count}' => isset($_REQUEST['count']) ? (int)$_REQUEST['count'] : $total,
                '{skip}' => isset($_REQUEST['skip']) ? (int)$_REQUEST['skip'] : $skipDefault,
            ]);
        }
        if ($this->import->needsBackground($total)) {
            $background = $this->backend->template('record.batch_option_background_fixed', [
                '{threshold}' => $this->import->backgroundThreshold(),
            ]);
        }
        else {
            $background = $this->backend->template('record.import_option_background', [
                '{checked}' => !empty($_REQUEST['background']) ? ' checked' : '',
            ]);
        }
        return $this->backend->template('record.import_options', [
            '{rows}' => $rowsHtml,
            '{total}' => $this->cms->snippet($rows ? 'record.x_rows' : 'record.x_records', ['count' => $total]),
            '{checked_extended}' => !empty($_REQUEST['extended']) ? ' checked' : '',
            '{checked_force}' => !empty($_REQUEST['force']) ? ' checked' : '',
            '{background}' => $background,
        ]);
    }

    /**
     * Einfügeposition neuer Datensätze (RecordImport, EINFÜGEPOSITION) - nur in
     * Bereichen mit Baum (Eingabefeld `parent_id`) oder Handsortierung (`position`):
     * Vaterdatensatz (Baum) und Vorgänger, wie in der Eingabemaske. Vorgewählt ist
     * der letzte vorhandene Datensatz der Ebene - die neuen kommen ans Ende. Ein
     * Wechsel des Vaters lädt die Vorgängerliste per Ajax neu (record_previous.php).
     *
     * Die Felder selbst setzt _placementFields() nach formInit() ein.
     *
     * @return string  Fieldset mit den Platzhaltern der Felder, '' ohne Baum/Sortierung
     */
    private function _placementHtml(): string
    {
        $schemaVirtual = $this->record->virtualSchema($this->areaId, false);
        $tree = isset($schemaVirtual['parent_id']);
        if (!$tree && !isset($schemaVirtual['position'])) return '';
        return $this->backend->template('record.import_placement', [
            '{parent}' => $tree ? '{data_parent_id}' : '',
            '{previous}' => '{data_previous_id}',
        ]);
    }

    /**
     * Die Felder der Einfügeposition: data[parent_id] (Baum) und data[previous_id].
     */
    private function _placementFields(): void
    {
        $schemaVirtual = $this->record->virtualSchema($this->areaId, false);
        $tree = isset($schemaVirtual['parent_id']);
        if (!$tree && !isset($schemaVirtual['position'])) return;
        $this->data = (array)($_REQUEST['data'] ?? []);
        $this->data['origin_id'] = 0; // keine Arbeitskopie (_parentOptions / _previousOptions)
        $parentId = $tree ? (int)($this->data['parent_id'] ?? 0) : 0;
        if ($tree) {
            $this->backend->formField($this->record, [
                'name' => 'parent_id',
                'value' => $parentId > 0 ? $parentId : '',
                'id' => 0,
                'colDef' => ['type' => 'integer', 'display_type' => 'select', 'label' => '#record.label_import_parent'],
                'options' => $this->_parentOptions(),
                'template' => 'record.record_form_parent_select',
            ]);
        }
        $options = $this->_previousOptions($parentId);
        $selectable = $options;
        unset($selectable['disabled']);
        $previousId = array_key_exists('previous_id', $this->data) ? (int)$this->data['previous_id'] : (int)array_key_last($selectable);
        $this->backend->formField($this->record, [
            'name' => 'previous_id',
            'value' => $previousId > 0 ? $previousId : '',
            'id' => 0,
            'colDef' => ['type' => 'integer', 'display_type' => 'select', 'label' => '#record.label_import_previous'],
            'options' => $options,
        ]);
    }

    private function _fileInfoHtml(): string
    {
        return $this->_infoRow($this->cms->snippet('record.label_import_file'), htmlspecialchars($this->file)
            . ' ' . $this->_badge('secondary', strtoupper($this->type)));
    }

    /**
     * XML: Herkunft, Warnhinweise, Bereiche, Modus.
     */
    private function _analyzeXml(string $path): void
    {
        $analysis = $this->import->analyzeXml($path, $this->areaId);
        echo $this->_analysisHead($analysis['errors'], $analysis['warnings']);
        if (!$analysis['ok']) {
            echo $this->backend->template('record.form_import_cancel', ['{list_query}' => $this->_cancelQuery()]);
            return;
        }
        $header = $analysis['header'];
        $info = $this->_fileInfoHtml()
            . $this->_infoRow($this->cms->snippet('record.label_import_database'), htmlspecialchars($header['database']))
            . $this->_infoRow($this->cms->snippet('record.label_import_version'), htmlspecialchars($header['cms_version']))
            . $this->_infoRow($this->cms->snippet('record.label_import_exported'), htmlspecialchars($header['exported_at'] . ($header['admin'] !== '' ? ' (' . $header['admin'] . ')' : '')))
            . $this->_infoRow($this->cms->snippet('record.label_import_records'), htmlspecialchars($this->cms->snippet('record.x_records', ['count' => (int)$analysis['count']])))
            . $this->_infoRow($this->cms->snippet('record.label_import_files'), $this->cms->snippet(!empty($header['files']) ? 'yes' : 'no'));

        // Quellbereich: welche Datensätze der Datei kommen HIERHER?
        // --------------------
        // Aus derselben Datenbank: sind Datensätze dieses Bereichs enthalten, nur die;
        // sonst (und bei einer fremden Datei) einen Bereich der Datei wählen - bei
        // einer fremden Datei mit gleichem Namen vorgewählt, ein einzelner steht fest.
        $areas = $analysis['areas'];
        $sameDb = $this->import->isSameDatabase($path);
        $requestedSource = (int)($_REQUEST['source_area_id'] ?? 0);
        $sourceId = 0;
        $fixed = false;
        if ($sameDb && isset($areas[$this->areaId])) {
            $sourceId = $this->areaId;
            $fixed = true;
        }
        elseif (count($areas) === 1) {
            $sourceId = (int)array_key_first($areas);
            $fixed = true;
        }
        elseif ($requestedSource > 0 && isset($areas[$requestedSource])) {
            $sourceId = $requestedSource;
        }
        else {
            foreach ($areas as $fileAreaId => $areaInfo) {
                if ((string)$areaInfo['name'] === (string)($this->areaData['name'] ?? '')) { $sourceId = (int)$fileAreaId; break; }
            }
        }
        $areaLabel = fn(int $id, array $areaInfo) => $this->cms->textOrSnippet((string)$areaInfo['label']) . ' (' . $areaInfo['name'] . ', ' . $id . ') - '
            . $this->cms->snippet('record.x_records', ['count' => (int)$areaInfo['count']]);
        if ($fixed) {
            $others = count($areas) - 1;
            $areasHtml = $this->backend->template('record.import_area_same', [
                '{area}' => htmlspecialchars($areaLabel($sourceId, $areas[$sourceId])),
                '{others}' => $others > 0 ? htmlspecialchars(str_replace('{count}', (string)$others, $this->cms->snippet('record.note_import_areas_ignored'))) : '',
            ]);
            $areasHtml .= $this->backend->template('input_hidden', ['{name}' => 'source_area_id', '{value}' => (string)$sourceId]);
        }
        else {
            $sources = [];
            foreach ($areas as $fileAreaId => $areaInfo) {
                $sources[(int)$fileAreaId] = $areaLabel((int)$fileAreaId, $areaInfo);
            }
            $options = $this->backend->options($sources, $sourceId);
            $areasHtml = $this->backend->template('record.import_area_choice', [
                '{current}' => htmlspecialchars($this->cms->textOrSnippet((string)$this->areaData['label'])),
                '{options}' => $options,
            ]);
        }
        $count = (int)($areas[$sourceId]['count'] ?? $analysis['count']);

        echo '<form action="Record/record_import.php" method="post" data-layer="new">';
        $this->backend->formInit('record.record_import_form_xml', [
            '{info}' => $info,
            '{areas}' => $areasHtml,
            '{mode}' => $this->_modeHtml('replace_or_insert'),
            '{placement}' => $this->_placementHtml(),
            '{options}' => $this->_optionsHtml($count, 0),
        ]);
        $this->_placementFields();
        $this->_analysisHidden();
        echo $this->backend->form('record.form_import_run', ['{list_query}' => $this->_cancelQuery()]);
        echo '</form>';
    }

    /**
     * CSV: Einstellungen (lesen die Datei neu ein), Spaltenzuordnung, Modus.
     */
    private function _analyzeCsv(string $path): void
    {
        $csv = (array)($_REQUEST['data']['csv'] ?? []);
        $csv += ['separator' => ';', 'enclosure' => '"', 'eol' => '\r\n'];
        $analysis = $this->import->analyzeCsv($path, $this->areaId, $csv);
        echo $this->_analysisHead($analysis['errors'], []);
        if (!empty($analysis['errors'])) {
            echo $this->backend->template('record.form_import_cancel', ['{list_query}' => $this->_cancelQuery()]);
            return;
        }
        $targets = $this->_writableTargets($this->import->csvTargets($this->areaId));
        $requestedMapping = isset($_REQUEST['mapping']) ? (array)$_REQUEST['mapping'] : null;
        $stored = isset($_REQUEST['data']['csv']) ? !empty($csv['stored']) : $analysis['stored'];

        // Zuordnungstabelle: Spalte | Beispiel (2. Zeile) | Zielfeld
        $example = (array)($analysis['example'] ?? []);
        $tableRows = '';
        foreach ($analysis['columns'] as $index => $column) {
            $selected = $requestedMapping !== null ? (string)($requestedMapping[$index] ?? '') : (string)($analysis['suggest'][$index] ?? '');
            $options = $this->backend->options(['' => $this->cms->snippet('record.option_import_ignore')] + $targets, $selected);
            $tableRows .= $this->backend->template('record.import_mapping_row', [
                '{index}' => (int)$index,
                '{column}' => htmlspecialchars((string)$column),
                '{example}' => htmlspecialchars($this->cms->_strlimit((string)($example[$index] ?? ''), 60)),
                '{options}' => $options,
            ]);
        }

        echo '<form action="Record/record_import.php" method="post" data-layer="new">';
        $this->backend->formInit('record.record_import_form_csv', [
            '{info}' => $this->_fileInfoHtml()
                . $this->_infoRow($this->cms->snippet('record.label_import_rows'), (int)$analysis['rows'])
                . $this->_infoRow($this->cms->snippet('record.label_import_header'), $this->cms->snippet($analysis['header'] ? 'yes' : 'no')),
            '{checked_stored}' => $stored ? ' checked' : '',
            '{mapping}' => $this->backend->template('record.import_mapping_table', ['{rows}' => $tableRows]),
            '{mode}' => $this->_modeHtml('insert'),
            '{placement}' => $this->_placementHtml(),
            '{options}' => $this->_optionsHtml((int)$analysis['rows'], $analysis['header'] ? 1 : 0, rows: true),
        ]);
        $this->_placementFields();
        foreach (['separator' => ', ; \t', 'enclosure' => '" \'', 'eol' => '\r\n \n'] as $key => $suffix) {
            $this->backend->formField($this->record, [
                'name' => 'csv.' . $key,
                'value' => (string)$csv[$key],
                'id' => 0,
                'colDef' => ['type' => 'chars', 'display_type' => 'text', 'label' => '#record.label_csv_' . $key, 'suffix' => $suffix],
            ]);
        }
        $this->_analysisHidden();
        echo $this->backend->form('record.form_import_run', ['{list_query}' => $this->_cancelQuery()]);
        echo '</form>';
    }

    /**
     * DOCX: Prüfung, Zielsprache, Optionen.
     */
    private function _analyzeDocx(string $path): void
    {
        $analysis = $this->import->analyzeDocx($path, $this->areaId);
        if ($analysis['ok'] && !$this->backend->access()->languageWrite((int)$analysis['language_id'])) {
            $analysis['ok'] = false;
            $analysis['errors'][] = $this->cms->snippet('record.error_import_language_denied');
        }
        echo $this->_analysisHead($analysis['errors'], $analysis['warnings']);
        if (!$analysis['ok']) {
            echo $this->backend->template('record.form_import_cancel', ['{list_query}' => $this->_cancelQuery()]);
            return;
        }
        $instanceId = (int)($this->areaData['instance_id'] ?? 0);
        $languages = $this->instanceCache->languages($instanceId);
        $languageId = (int)$analysis['language_id'];
        $language = $languages[$languageId] ?? [];
        $languageLabel = (string)($language['label'] ?? '') . ' (' . (string)($language['code'] ?? $languageId) . ')';
        $docx = (array)($_REQUEST['data']['docx'] ?? []);
        $fromRequest = isset($_REQUEST['data']['docx']);
        $ideographic = $this->import->isIdeographicLanguage($languageId, $instanceId);
        $rtl = (string)($docx['rtl'] ?? 'unicode');
        if (!in_array($rtl, ['unicode', 'html', 'none'], true)) $rtl = 'unicode';

        echo '<form action="Record/record_import.php" method="post" data-layer="new">';
        $this->backend->formInit('record.record_import_form_docx', [
            '{info}' => $this->_fileInfoHtml()
                . $this->_infoRow($this->cms->snippet('record.label_import_records'), htmlspecialchars($this->cms->snippet('record.x_records', ['count' => (int)$analysis['records']])))
                . $this->_infoRow($this->cms->snippet('record.label_docx_to'), htmlspecialchars($languageLabel))
                . $this->_infoRow($this->cms->snippet('record.label_export_fields'), htmlspecialchars(implode(', ', $analysis['fields']))),
            '{checked_punct}' => ($fromRequest ? !empty($docx['punct']) : $ideographic) ? ' checked' : '',
            '{checked_space}' => ($fromRequest ? !empty($docx['space']) : $ideographic) ? ' checked' : '',
            '{checked_rtl_unicode}' => $rtl === 'unicode' ? ' checked' : '',
            '{checked_rtl_html}' => $rtl === 'html' ? ' checked' : '',
            '{checked_rtl_none}' => $rtl === 'none' ? ' checked' : '',
            '{options}' => $this->_optionsHtml((int)$analysis['records'], 0),
        ]);
        $this->_analysisHidden();
        $this->backend->formHidden('mode', 'update');
        echo $this->backend->form('record.form_import_run', ['{list_query}' => $this->_cancelQuery()]);
        echo '</form>';
    }

    // ====================
    // Schritt 2: ausführen

    /**
     * Auftrag (RecordImport::run() / analyze()) aus den Einstellungen des Requests.
     * Bei Fehlern wird die passende Maske erneut gezeigt und null geliefert.
     */
    private function _jobFromRequest(): ?array
    {
        $this->type = (string)($_REQUEST['type'] ?? $this->import->typeOf($this->file));
        $path = $this->dir !== '' ? $this->import->filePath($this->dir, $this->file) : null;
        if ($path === null || !in_array($this->type, RecordImport::TYPES, true)) {
            $this->cms->error($this->cms->snippet('record.error_import_no_file'));
            $this->errors();
            $this->_importUploadForm();
            return null;
        }
        if (!$this->_typeAllowed()) return null;
        $job = [
            'type' => $this->type,
            'area_id' => $this->areaId,
            'dir' => $this->dir,
            'file' => $this->file,
            'mode' => (string)($_REQUEST['mode'] ?? 'insert'),
            // Anzahl / Überspringen (Zeilen) gibt es nur beim CSV-Import
            'skip' => $this->type === 'csv' ? max(0, (int)($_REQUEST['skip'] ?? 0)) : 0,
            'limit' => $this->type === 'csv' ? max(0, (int)($_REQUEST['count'] ?? 0)) : 0,
            'force' => !empty($_REQUEST['force']),
        ];
        // Einfügeposition neuer Datensätze (RecordImport, EINFÜGEPOSITION)
        $placement = (array)($_REQUEST['data'] ?? []);
        if (array_key_exists('parent_id', $placement)) $job['parent_id'] = (int)$placement['parent_id'];
        if (array_key_exists('previous_id', $placement)) $job['previous_id'] = (int)$placement['previous_id'];
        if ($this->isGlobal && $this->type === 'xml') {
            $job['global'] = true;
            $job['folder_id'] = (int)($_REQUEST['folder_id'] ?? 0);
            $job['instance_id'] = (int)($_REQUEST['instance_id'] ?? 0);
            $job['areas'] = [];
            foreach ((array)($_REQUEST['areas'] ?? []) as $fileAreaId => $set) {
                $set = (array)$set;
                $clean = ['name' => trim((string)($set['name'] ?? ''))];
                foreach (['structure', 'records', 'templates'] as $key) {
                    $mode = (string)($set[$key] ?? 'ignore');
                    $clean[$key] = in_array($mode, RecordImport::GLOBAL_MODES, true) ? $mode : 'ignore';
                }
                $clean['structure_by'] = (string)($set['structure_by'] ?? 'name') === 'area_id' ? 'area_id' : 'name';
                $clean['records_by'] = (string)($set['records_by'] ?? 'slug') === 'record_id' ? 'record_id' : 'slug';
                $clean['templates_by'] = (string)($set['templates_by'] ?? 'name') === 'template_id' ? 'template_id' : 'name';
                $job['areas'][(int)$fileAreaId] = $clean;
            }
            // Konverter der Thumbnail-Felder: 'new', 'ignore' oder converter_id (RecordImport, KONVERTER)
            $job['converters'] = [];
            foreach ((array)($_REQUEST['converters'] ?? []) as $fileConverterId => $choice) {
                $choice = (string)$choice;
                if (ctype_digit($choice) && (int)$choice > 0) $choice = (int)$choice;
                elseif ($choice !== 'new') $choice = 'ignore';
                $job['converters'][(int)$fileConverterId] = $choice;
            }
            $job['count'] = (int)$this->import->analyzeXml($path, 0)['count'];
        }
        elseif ($this->isGlobal) {
            $this->_importAnalyze();
            return null;
        }
        elseif ($this->type === 'xml') {
            $analysis = $this->import->analyzeXml($path, $this->areaId);
            $job['source_area_id'] = (int)($_REQUEST['source_area_id'] ?? 0);
            if (!isset($analysis['areas'][$job['source_area_id']])) {
                $this->cms->error($this->cms->snippet('area.error_area_not_found'));
                $this->errors();
                $this->_importAnalyze();
                return null;
            }
            $job['count'] = (int)$analysis['areas'][$job['source_area_id']]['count'];
        }
        elseif ($this->type === 'csv') {
            $csv = (array)($_REQUEST['data']['csv'] ?? []);
            $job['csv'] = [
                'separator' => (string)($csv['separator'] ?? ';'),
                'enclosure' => (string)($csv['enclosure'] ?? '"'),
                'eol' => (string)($csv['eol'] ?? '\r\n'),
                'stored' => !empty($csv['stored']),
            ];
            $job['mapping'] = array_map('strval', (array)($_REQUEST['mapping'] ?? []));
            // Ziele nur schreibbarer Sprachen (ein manipuliertes Formular zählt nicht)
            $writable = $this->_writableTargets($this->import->csvTargets($this->areaId));
            $job['mapping'] = array_map(fn($key) => isset($writable[$key]) ? $key : '', $job['mapping']);
            if (empty(array_filter($job['mapping'], fn($v) => $v !== ''))) {
                $this->cms->error($this->cms->snippet('record.error_import_no_mapping'));
                $this->errors();
                $this->_importAnalyze();
                return null;
            }
            $job['count'] = (int)$this->import->analyzeCsv($path, $this->areaId, $job['csv'])['rows'];
        }
        else {
            $docx = (array)($_REQUEST['data']['docx'] ?? []);
            $job['mode'] = 'update';
            $job['docx'] = [
                'punct' => !empty($docx['punct']),
                'space' => !empty($docx['space']),
                'rtl' => in_array((string)($docx['rtl'] ?? ''), ['unicode', 'html', 'none'], true) ? (string)$docx['rtl'] : 'unicode',
            ];
            $docxAnalysis = $this->import->analyzeDocx($path, $this->areaId);
            if (!$this->backend->access()->languageWrite((int)($docxAnalysis['language_id'] ?? 0))) {
                $this->cms->error($this->cms->snippet('record.error_import_language_denied'));
                $this->errors();
                return null;
            }
            $job['count'] = (int)$docxAnalysis['records'];
        }
        return $job;
    }

    // ====================
    // Schritt 2: Analyse

    /**
     * Was der Import tun würde (RecordImport::analyze()), in einem eigenen Layer
     * über den Einstellungen. Die Einstellungen reisen als Hiddenfelder weiter zum
     * Import; "Zurück" schließt den Layer, die Einstellungen bleiben.
     */
    private function _importPreview(): void
    {
        $job = $this->_jobFromRequest();
        if ($job === null) return;
        $extended = !empty($_REQUEST['extended']);
        $analysis = $this->import->analyze($job, $extended);
        echo $this->backend->headline('#record.headline_import_analysis');
        if (!empty($analysis['error'])) {
            echo $this->backend->template('alert_danger', ['{text}' => $analysis['error']]);
            echo $this->backend->template('record.form_import_result_back');
            return;
        }
        echo $this->_analysisHtml($analysis, $extended);

        // Weiter zum Import: alle Einstellungen erneut senden
        echo '<form action="Record/record_import.php" method="post">';
        $this->backend->formInit('record.form_import_analysis_run');
        $this->_hiddenAll($_POST, '', ['function', 'extended']);
        $this->backend->formHidden('function', 'run');
        echo $this->backend->form();
        echo '</form>';
    }

    /**
     * Die Analyse als HTML: Hinweis, je Bereich Struktur, Felder, Templates,
     * Datensätze - erweitert mit der Liste der Datensätze.
     */
    private function _analysisHtml(array $analysis, bool $extended): string
    {
        $icons = ['folder' => 'folder', 'record' => 'draft', 'template' => 'extension', 'profile' => 'face', 'ugc' => 'chat_bubble'];
        $classes = ['inserted' => 'success', 'updated' => 'info', 'replaced' => 'warning', 'failed' => 'danger', 'skipped' => 'secondary', 'ignore' => 'secondary'];
        $tplRow = $this->backend->template('record.import_protocol_row');
        $areas = '';
        foreach ($analysis['areas'] as $area) {
            $action = (string)$area['action'];
            $isContent = in_array((string)$area['type'], RecordImport::CONTENT_TYPES, true);
            $isTemplate = (string)$area['type'] === 'template';
            $lines = '';

            // Struktur
            $structure = $action === 'ignore'
                ? ((int)$area['target_id'] > 0 ? $this->cms->snippet('record.analysis_area_unchanged') : $this->cms->snippet('record.analysis_area_missing'))
                : $this->cms->snippet('record.analysis_area_' . $action);
            $lines .= $this->backend->template('record.import_analysis_line', [
                '{label}' => $this->cms->snippet('record.label_import_structure'),
                '{class}' => $classes[$action] ?? 'secondary',
                '{text}' => htmlspecialchars($structure),
            ]);
            // Felder
            if ($isContent && $action !== 'ignore' && $action !== 'skipped') {
                $parts = [];
                foreach (['added' => 'success', 'updated' => 'info', 'removed' => 'danger', 'failed' => 'danger', 'skipped' => 'secondary'] as $key => $class) {
                    $names = (array)($area['fields'][$key] ?? []);
                    if (empty($names)) continue;
                    $parts[] = $this->_badge($class, (string)count($names)) . ' '
                        . htmlspecialchars($this->cms->snippet('record.analysis_fields_' . $key))
                        . ' ' . $this->_muted('(' . implode(', ', $names) . ')');
                }
                $lines .= $this->backend->template('record.import_analysis_line', [
                    '{label}' => $this->cms->snippet('record.analysis_fields'),
                    '{class}' => 'secondary',
                    '{text}' => $parts ? implode($this->backend->template('br'), $parts) : htmlspecialchars($this->cms->snippet('record.analysis_none')),
                ]);
            }
            // Templates
            if ($isTemplate) {
                $lines .= $this->backend->template('record.import_analysis_line', [
                    '{label}' => $this->cms->snippet('record.label_import_templates'),
                    '{class}' => 'secondary',
                    '{text}' => $this->_analysisCounts((array)$area['templates']),
                ]);
            }
            // Datensätze
            if ($isContent) {
                $lines .= $this->backend->template('record.import_analysis_line', [
                    '{label}' => $this->cms->snippet('record.label_import_records'),
                    '{class}' => 'secondary',
                    '{text}' => $this->_analysisCounts((array)$area['records']),
                ]);
            }
            // Erweitert: jeder Datensatz
            $items = '';
            if ($extended && !empty($area['items'])) {
                $rows = '';
                foreach ($area['items'] as $item) {
                    $status = (string)$item['status'];
                    $teaser = '';
                    if ((int)$item['record_id'] > 0 && $status !== 'failed') {
                        $teaser = $this->backend->teaser('record', (int)$item['record_id'], linked: true, icon: false);
                    }
                    if ($teaser === '') $teaser = htmlspecialchars(trim(((int)$item['id'] > 0 ? "[{$item['id']}] " : '') . (string)$item['label']));
                    $rows .= strtr($tplRow, [
                        '{class}' => $classes[$status] ?? 'secondary',
                        '{kind}' => htmlspecialchars($this->cms->snippet('record.import_kind_record')),
                        '{record}' => $teaser,
                        '{status}' => htmlspecialchars($this->cms->snippet('record.import_status_' . $status)),
                        '{messages}' => htmlspecialchars(implode(' | ', (array)$item['messages'])),
                    ]) . "\n";
                }
                $items = $this->backend->template('record.import_analysis_items', ['{rows}' => $rows]);
            }
            $target = (int)$area['target_id'] > 0
                ? $this->backend->teaser('area', (int)$area['target_id'], linked: false)
                : $this->_muted($this->cms->snippet('record.note_import_area_new'));
            $areas .= $this->backend->template('record.import_analysis_area', [
                '{icon}' => $icons[(string)$area['type']] ?? 'draft',
                '{label}' => htmlspecialchars((string)$area['label']),
                '{name}' => htmlspecialchars((string)$area['name']),
                '{target}' => $target,
                '{lines}' => $lines,
                '{items}' => $items,
            ]);
        }
        // Konverter der Thumbnail-Felder (global): was mit jedem geschieht
        if (!empty($analysis['converters'])) {
            $lines = '';
            $classes['create'] = 'success';
            $classes['link'] = 'info';
            foreach ((array)$analysis['converters'] as $converter) {
                $action = (string)$converter['action'];
                $text = $this->cms->snippet('record.analysis_converter_' . $action);
                if ($action === 'link') $text .= $this->cms->snippet('colon') . ' ' . $this->cms->_strip_tags($this->backend->teaser('converter', (int)$converter['converter_id'], linked: false, icon: false));
                elseif ($action === 'ignore' && empty($converter['installed'])) $text .= ' - ' . str_replace('{processor}', (string)$converter['processor'], $this->cms->snippet('record.note_import_converter_not_installed'));
                $lines .= $this->backend->template('record.import_analysis_line', [
                    '{label}' => htmlspecialchars($this->cms->textOrSnippet((string)$converter['label']) . ' (' . $converter['processor'] . ')'),
                    '{class}' => $classes[$action] ?? 'secondary',
                    '{text}' => htmlspecialchars($text),
                ]);
            }
            $areas .= $this->backend->template('record.import_analysis_area', [
                '{icon}' => 'photo_size_select_small',
                '{label}' => htmlspecialchars($this->cms->snippet('record.label_import_converters')),
                '{name}' => 'converter',
                '{target}' => $this->backend->template('icon', ['{name}' => 'manufacturing']),
                '{lines}' => $lines,
                '{items}' => '',
            ]);
        }
        $skipped = (int)$analysis['skipped'];
        return $this->backend->template('record.import_analysis', [
            '{total}' => htmlspecialchars($this->cms->snippet('record.x_records', ['count' => (int)$analysis['total']])
                . ($skipped > 0 ? ' - ' . str_replace('{count}', (string)$skipped, $this->cms->snippet('record.note_import_skipped_head')) : '')),
            '{areas}' => $areas,
        ]);
    }

    /**
     * "n hinzugefügt, n geändert, …" mit Badges - nur die Zähler > 0.
     */
    private function _analysisCounts(array $counts): string
    {
        $classes = ['inserted' => 'success', 'updated' => 'info', 'replaced' => 'warning', 'failed' => 'danger', 'skipped' => 'secondary'];
        $parts = [];
        foreach ($classes as $status => $class) {
            $n = (int)($counts[$status] ?? 0);
            if ($n < 1) continue;
            $parts[] = $this->_badge($class, (string)$n) . ' ' . htmlspecialchars($this->cms->snippet('record.import_status_' . $status));
        }
        return $parts ? implode(', ', $parts) : htmlspecialchars($this->cms->snippet('record.analysis_none'));
    }

    /** Badge (Vorlage badge) */
    private function _badge(string $color, string $text): string
    {
        return $this->backend->template('badge', ['{color}' => $color, '{text}' => $this->backend->escape($text)]);
    }

    /** Grauer Hinweis (Vorlagen text-muted / small-muted) */
    private function _muted(string $text, bool $small = false): string
    {
        return $this->backend->template($small ? 'small-muted' : 'text-muted', ['{text}' => $this->backend->escape($text)]);
    }

    /** Einrückung nach der Tiefe im Bereichsbaum (geschützte Leerzeichen, Text für options()) */
    private function _indent(int $depth): string
    {
        return str_repeat("\u{00A0} ", $depth * 2);
    }

    /**
     * Alle Request-Werte als Hiddenfelder (verschachtelte Arrays als name[key]).
     */
    private function _hiddenAll(array $data, string $prefix, array $except = []): void
    {
        foreach ($data as $key => $value) {
            if ($prefix === '' && in_array((string)$key, $except, true)) continue;
            $name = $prefix === '' ? (string)$key : $prefix . '[' . $key . ']';
            if (is_array($value)) $this->_hiddenAll($value, $name);
            else $this->backend->formHidden($name, (string)$value);
        }
    }

    // ====================
    // Schritt 3: importieren

    /**
     * Im Request oder im Hintergrund. Das Protokoll ersetzt den Analyse-Layer; die
     * Einstellungsmaske darunter wird sofort entfernt - ihre Importdatei gibt es
     * nicht mehr. Die Liste darunter ist als veraltet markiert (cApp.layers.invalidate())
     * und lädt neu, sobald sie wieder oben liegt - über "Schließen", die
     * Breadcrumb-Navi oder "Zurück" des Browsers.
     */
    private function _importRun(): void
    {
        $job = $this->_jobFromRequest();
        if ($job === null) return;
        $effective = $job['limit'] > 0 ? min($job['limit'], max(0, $job['count'] - $job['skip'])) : max(0, $job['count'] - $job['skip']);

        // Hintergrund
        // --------------------
        if (!empty($_REQUEST['background']) || $this->import->needsBackground($effective)) {
            $error = '';
            if ($this->import->start($job, $error) === null) {
                $this->cms->error($this->cms->snippet('record.error_batch_start') . ' ' . $error);
                $this->errors();
                $this->_importAnalyze();
                return;
            }
            $this->backend->balloon('success', $this->cms->snippet('record.message_import_started'));
            // Einstellungsmaske entfernen, Liste als veraltet markieren, Analyse schließen
            $this->backend->script("cApp.layers.removeReferer(); cApp.layers.invalidate(); cApp.layers.close();");
            return;
        }

        $result = $this->import->run($job);
        $this->import->removeDir($this->dir);
        // die Liste darunter hat sich geändert: beim Wiederanzeigen neu laden
        $this->backend->script('cApp.layers.removeReferer(); cApp.layers.invalidate();');
        echo $this->import->protocolHtml($job, $result);
        echo $this->backend->template('record.form_import_result_close');
    }
}

