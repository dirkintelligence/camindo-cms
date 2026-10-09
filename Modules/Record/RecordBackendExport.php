<?php
/**
 * Export von Datensätzen - die Masken (Erweiterung für RecordBackend).
 *
 * Einstiegspunkte (Scripts in backend/):
 *
 *     record_export.php    -> export()    Maske, Ausführen, Ergebnis
 *     record_download.php  -> download()  Zip-Datei aus var/export ausliefern
 *
 * Erreichbar über das Menü (more_vert) rechts in der Datensatzliste (global: in der
 * Ordnerliste); der Aufruf öffnet einen eigenen Layer über der Liste
 * (cApp.layers.add) und bringt die Seitenparameter der Liste mit
 * (cApp.layers.queryString(): area_id, filter, sort, ...). Die Arbeit macht
 * RecordExport.
 *
 * NAVIGATION: Zur Liste geht es aus dem Layer nur mit cApp.layers.close() zurück;
 * der Reiterwechsel ersetzt den Layer mit cApp.layers.replace().
 *
 * DREI REITER, EIN SCRIPT (type)
 * --------------------
 *     xml    Backup-Format, einzige Einstellung: Upload-Dateien mit exportieren
 *     csv    CSV-Einstellungen (Kopfzeile, Trenn-, Einfassungszeichen, Zeilenumbruch),
 *            Sprachen der Instanz, Felder mit aufklappbaren Unterfeldern
 *            (RecordExport::csvFields())
 *     docx   Übersetzung: Quell- und Zielsprache, alle Datensätze in einer Datei
 *            oder ein Datensatz je Datei, mehrsprachige Felder
 *
 * Über allen Reitern der Umfang: das letzte Suchergebnis (wenn die Liste gefiltert
 * war, vorgewählt) oder alle Datensätze des Bereichs - wie bei der
 * Stapelverarbeitung, die Filterparameter reisen als Query-String (Hiddenfeld
 * 'query') mit, die Ids ermittelt RecordBatch::collectIds() erst beim Ausführen.
 *
 * Unter jeder Maske die Option Hintergrundprozess (ab
 * admin.export_background_threshold Datensätzen fest): der Download-Link kommt dann
 * als interne Nachricht. Sonst führt function = 'run' im Request aus und zeigt das
 * Ergebnis mit dem Download-Link (öffnet record_download.php in einem neuen
 * Fenster - eine Zip-Datei lässt sich nicht in den Layer laden) und dem Hinweis,
 * wie lange er gilt. Die Datei wird beim Download gelöscht, sonst nach Ablauf der
 * Verweildauer (RecordExport::cleanup()).
 *
 * @see Camindo\Core\Modules\Record\RecordExport        die Arbeit
 * @see Camindo\Core\Modules\Record\RecordBackendBatch  gleiches Muster für den Umfang
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\MassDeletion;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Area\Area;

class RecordBackendExport extends RecordBackend {

    /** Filterparameter der Liste (RecordBatch::filterParams()) */
    private array $filterParams = [];
    /** dieselben als Query-String für die Hiddenfelder */
    private string $query = '';
    /** Query-String der Liste (Hiddenfeld, für den Reiterwechsel) */
    private string $listQuery = '';
    /** 'filter' (letztes Suchergebnis) | 'all' */
    private string $scope = 'all';
    private string $type = 'xml';
    /** Formate, die die Gruppe exportieren darf (allowedTypes()) */
    private array $allowedTypes = [];
    private int $count = 0;
    private bool $hasFilter = false;

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
        private RecordBatch $batch,
        private RecordExport $export,
    )
    {
        parent::__construct($config, $backend, $crypt, $session, $db, $system, $cms, $massDeletion, $area, $record, $instanceCache, $service, $presenter);
    }

    /**
     * Export (backend/record_export.php).
     *
     * Request: area_id, filter/filterop/filtermode (aus der Liste) bzw. query (beim
     *          Ausführen), list_query, type (xml|csv|docx), function ('' | run),
     *          scope, files, data[csv][...], languages[], fields[], data[docx][from|to|split]
     */
    public function export(): void
    {
        echo $this->backend->template('container_header');
        $this->function = 'list';
        $this->_resolveParams();
        $function = (string)($_REQUEST['function'] ?? '');

        // Zusatzrechte: erlaubte Formate (XML nur Superuser)
        $allowedTypes = $this->allowedTypes('export');
        if (empty($allowedTypes)) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        // Global (Startseite des Moduls): nur XML, Auswahl der Bereiche
        if (!empty($_REQUEST['global']) && $this->areaId < 1 && $this->function === 'list') {
            if (!in_array('xml', $allowedTypes, true)) {
                echo $this->backend->access()->deniedHtml();
                echo $this->backend->template('container_footer');
                return;
            }
            $this->_exportGlobal($function);
            echo $this->backend->template('container_footer');
            return;
        }

        if ($this->function !== 'list' || $this->areaId < 1) {
            if ($this->areaId < 1) $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }
        // Leserecht der Benutzergruppe auf den Bereich
        if (!$this->backend->access()->areaRead($this->areaId)) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        // Filterparameter: beim Aufruf aus der Liste bzw. Reiterwechsel aus dem
        // Request, beim Ausführen aus 'query'
        if ($function === '') {
            $this->filterParams = $this->batch->filterParams($_REQUEST);
            $listKeys = array_intersect_key($_REQUEST, array_flip(['area_id', 'filter', 'filterop', 'filtermode', 'sort', 'order', 'skip']));
            $this->listQuery = http_build_query($listKeys);
        }
        else {
            parse_str((string)($_REQUEST['query'] ?? ''), $parsed);
            $this->filterParams = $this->batch->filterParams($parsed);
            $this->listQuery = (string)($_REQUEST['list_query'] ?? ('area_id=' . $this->areaId));
        }
        $this->query = http_build_query($this->filterParams);
        $this->hasFilter = $this->batch->hasFilter($this->filterParams);

        $this->type = (string)($_REQUEST['type'] ?? 'xml');
        if (!in_array($this->type, $allowedTypes, true)) $this->type = $allowedTypes[0];
        $this->allowedTypes = $allowedTypes;
        $this->scope = ($this->hasFilter && (string)($_REQUEST['scope'] ?? 'filter') === 'filter') ? 'filter' : 'all';
        $this->count = $this->batch->count($this->areaId, $this->scope === 'filter' ? $this->filterParams : []);

        // Bread crum navi, Bereich, H1
        $this->backend->setNaviLabel('#record.headline_export');
        echo $this->backend->template('record.prefix_content_area', [
            '{text}' => str_replace('{area_label}', $this->cms->textOrSnippet((string)$this->areaData['label']), $this->cms->snippet('area.headline_area_label'))
        ]);
        echo $this->backend->headline('#record.headline_export');

        if ($function === 'run') {
            $this->_exportRun();
            echo $this->backend->template('container_footer');
            return;
        }

        // Reiter (nur die erlaubten Formate)
        $this->backend->tabBarReset();
        foreach ($this->allowedTypes as $type) {
            $link = "cApp.layers.replace('Record/record_export.php?{$this->listQuery}&type={$type}');";
            $this->backend->tabAdd('#record.tab_export_' . $type, $type === $this->type ? '' : $link, $type === $this->type);
        }
        echo $this->backend->tabBar();

        match ($this->type) {
            'xml'  => $this->_exportFormXml(),
            'csv'  => $this->_exportFormCsv(),
            'docx' => $this->_exportFormDocx(),
        };
        echo $this->backend->template('container_footer');
    }

    // ====================
    // Masken

    /**
     * Umfang: Auswahl (wenn die Liste gefiltert war) oder Hinweis.
     */
    private function _scopeHtml(): string
    {
        if ($this->hasFilter) {
            $countFilter = $this->batch->count($this->areaId, $this->filterParams);
            $countAll = $this->batch->count($this->areaId, []);
            return $this->backend->template('record.batch_scope_choice', [
                '{count_filter}' => $this->cms->snippet('record.batch_scope_filter', ['count' => $countFilter]),
                '{count_all}' => $this->cms->snippet('record.batch_scope_all', ['count' => $countAll]),
            ]);
        }
        return $this->backend->template('record.batch_scope_note', [
            '{count}' => $this->cms->snippet('record.batch_scope_all', ['count' => $this->count]),
        ]);
    }

    /**
     * Die Hiddenfelder, die jedes Formular begleiten.
     */
    private function _exportHidden(): void
    {
        $this->backend->formHidden('function', 'run');
        $this->backend->formHidden('type', $this->type);
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('query', $this->query);
        $this->backend->formHidden('list_query', $this->listQuery);
        if (!$this->hasFilter) $this->backend->formHidden('scope', 'all');
    }

    /**
     * Optionen unter jeder Maske: Hintergrund (Checkbox oder - ab dem Schwellwert -
     * Hinweis mit festem Hiddenfeld), dazu die Verweildauer der Datei.
     */
    private function _exportOptions(): string
    {
        if ($this->export->needsBackground($this->count)) {
            $background = $this->backend->template('record.batch_option_background_fixed', [
                '{threshold}' => $this->export->backgroundThreshold(),
            ]);
        }
        else {
            $background = $this->backend->template('record.export_option_background', [
                '{checked}' => !empty($_REQUEST['background']) ? ' checked' : '',
            ]);
        }
        return $this->backend->template('record.export_options', [
            '{count}' => $this->cms->snippet('record.batch_scope_all', ['count' => $this->count]),
            '{background}' => $background,
            '{hours}' => $this->export->retentionHours(),
        ]);
    }

    /**
     * xml: Umfang und die Option "Upload-Dateien mit exportieren".
     */
    private function _exportFormXml(): void
    {
        echo '<form action="Record/record_export.php" method="post">';
        $this->backend->formInit('record.record_export_form_xml', [
            '{scope}' => $this->_scopeHtml(),
            '{files_checked}' => (isset($_REQUEST['files']) || (string)($_REQUEST['function'] ?? '') === '') ? ' checked' : '',
        ]);
        $this->_exportHidden();
        echo $this->backend->form('record.form_export_run', ['{options}' => $this->_exportOptions()]);
        echo '</form>';
    }

    /**
     * csv: CSV-Einstellungen, Sprachen, Felder.
     */
    private function _exportFormCsv(): void
    {
        $csv = (array)($_REQUEST['data']['csv'] ?? []);
        $fromRequest = !empty($csv);
        $csv += ['header' => 1, 'bom' => 1, 'separator' => ';', 'enclosure' => '"', 'eol' => '\r\n'];

        echo '<form action="Record/record_export.php" method="post">';
        $this->backend->formInit('record.record_export_form_csv', [
            '{scope}' => $this->_scopeHtml(),
            '{languages}' => $this->_languageCheckboxes(),
            '{fields}' => $this->_csvFieldList(),
        ]);
        foreach (['header', 'bom'] as $key) {
            $this->backend->formField($this->record, [
                'name' => 'csv.' . $key,
                'value' => $fromRequest ? (int)!empty($csv[$key]) : 1,
                'id' => 0,
                'colDef' => ['type' => 'bit', 'display_type' => 'switch', 'label' => '#record.label_csv_' . $key],
            ]);
        }
        foreach (['separator' => ', ; \t', 'enclosure' => '" \'', 'eol' => '\r\n \n'] as $key => $suffix) {
            $this->backend->formField($this->record, [
                'name' => 'csv.' . $key,
                'value' => (string)$csv[$key],
                'id' => 0,
                'colDef' => ['type' => 'chars', 'display_type' => 'text', 'label' => '#record.label_csv_' . $key, 'suffix' => $suffix],
            ]);
        }
        $this->_exportHidden();
        echo $this->backend->form('record.form_export_run', ['{options}' => $this->_exportOptions()]);
        echo '</form>';
    }

    /**
     * Sprachen der Instanz als Checkboxen; vorgewählt die Hauptsprache bzw. die
     * Auswahl des Requests.
     */
    private function _languageCheckboxes(): string
    {
        $instanceId = (int)($this->areaData['instance_id'] ?? 0);
        // nur lesbare Sprachen der Benutzergruppe
        $languages = $this->record->readableLanguages($this->instanceCache->languages($instanceId));
        $primaryId = $this->instanceCache->primaryLanguageId($instanceId);
        if (!isset($languages[$primaryId])) $primaryId = (int)(array_key_first($languages) ?? 0);
        $requested = isset($_REQUEST['languages']) ? $this->system->sanitizeIds((array)$_REQUEST['languages']) : null;
        $html = '';
        foreach ($languages as $languageId => $language) {
            $checked = ($requested === null) ? ((int)$languageId === $primaryId) : in_array((int)$languageId, $requested, true);
            $html .= $this->backend->template('record.export_checkbox', [
                '{name}' => 'languages[]',
                '{value}' => (string)$languageId,
                '{id}' => 'c9-export-lang-' . (int)$languageId,
                '{label}' => htmlspecialchars((string)($language['label'] ?? '') . ' (' . (string)($language['code'] ?? '') . ')'),
                '{checked}' => $checked ? ' checked' : '',
                '{class}' => '',
            ]);
        }
        return $html;
    }

    /**
     * Felder für den CSV-Export als Tabelle: Checkbox | Name | Beschriftung | Typ.
     * Verknüpfungs- und Datei-Felder haben darunter einen Mehr-Link, der die
     * Unterfelder aufklappt (Bootstrap collapse auf einem eigenen tbody).
     */
    private function _csvFieldList(): string
    {
        $fields = $this->export->csvFields($this->areaId);
        $requested = isset($_REQUEST['fields']) ? array_map('strval', (array)$_REQUEST['fields']) : null;
        $isChecked = fn(string $key, bool $default) => ($requested === null) ? $default : in_array($key, $requested, true);

        $rows = '';
        foreach ($fields as $name => $entry) {
            $label = $this->cms->textOrSnippet((string)$entry['label']);
            $type = (string)$entry['type'];
            if (!empty($entry['meta'])) $type .= ' &middot; ' . $this->cms->snippet('record.label_export_meta');
            if (!empty($entry['multilingual'])) $type .= ' &middot; ' . $this->cms->snippet('record.label_move_multilingual');

            if (!empty($entry['relation'])) {
                // Hauptzeile: die record_id der Zieldatensätze, darunter deren Felder
                $rows .= $this->_fieldRow("$name.record_id", $isChecked("$name.record_id", true), $name . '.record_id', $label, $type, $name);
                $rows .= $this->_subRows($name, $entry, $isChecked, ['record_id'], $label . '.');
            }
            elseif (!empty($entry['file'])) {
                $rows .= $this->_fieldRow($name, $isChecked($name, !empty($entry['checked'])), $name, $label . ' &middot; ' . $this->cms->snippet('record.label_export_file'), $type, $name);
                $rows .= $this->_subRows($name, $entry, $isChecked, [], 'data_fil.');
            }
            else {
                $rows .= $this->_fieldRow($name, $isChecked($name, !empty($entry['checked'])), $name, $label, $type, '');
            }
        }
        return $this->_fieldTable($rows);
    }

    /**
     * Die Feldtabelle mit der Checkbox "alle" darüber (cApp.ui.checkAll()) - gesetzt,
     * wenn alle Hauptzeilen gewählt sind (die zugeklappten Unterfelder zählen nicht).
     */
    private function _fieldTable(string $rows): string
    {
        $main = (string)preg_replace('~<tbody class="collapse".*?</tbody>~s', '', $rows);
        $boxes = preg_match_all('~<input[^>]*name="fields\[\]"[^>]*>~', $main, $matches);
        $checked = count(array_filter($matches[0], fn($input) => str_contains($input, ' checked')));
        return $this->backend->template('record.export_field_table', [
            '{table_rows}'  => $rows,
            '{all_checked}' => $boxes > 0 && $checked === $boxes ? ' checked' : '',
        ]);
    }

    /**
     * Eine Zeile der Feldtabelle; mit $moreName bekommt sie den Mehr-Link auf den
     * zugehörigen tbody der Unterfelder. $sub = Zeile eines Unterfeldes.
     */
    private function _fieldRow(string $key, bool $checked, string $name, string $label, string $type, string $moreName, bool $sub = false): string
    {
        $id = 'c9-export-field-' . preg_replace('/[^a-z0-9_]/i', '-', $key);
        $more = '';
        if ($moreName !== '') {
            $more = $this->backend->template('record.export_field_more', [
                '{more_id}' => 'c9-export-more-' . preg_replace('/[^a-z0-9_]/i', '-', $moreName),
            ]);
        }
        $row = $this->backend->template('record.export_field_row', [
            '{value}' => htmlspecialchars($key),
            '{id}' => $id,
            '{checked}' => $checked ? ' checked' : '',
            '{name}' => htmlspecialchars($name),
            '{label}' => $label,
            '{type}' => $type,
            '{more}' => $more,
            '{class}' => $sub ? 'c9-export-sub' : '',
        ]);
        // Hauptzeilen in einem eigenen tbody - die Unterfelder folgen als
        // eingeklappter tbody (export_field_subrows), Zeilen direkt in <table>
        // wären kein gültiges HTML
        return $sub ? $row : "<tbody>\n" . $row . "</tbody>\n";
    }

    /**
     * Die Unterfelder eines Feldes als eingeklappter tbody (ohne die in $skip).
     */
    private function _subRows(string $name, array $entry, callable $isChecked, array $skip, string $labelPrefix): string
    {
        $rows = '';
        foreach ($entry['sub'] as $sub => $subEntry) {
            if (in_array($sub, $skip, true)) continue;
            $type = !empty($entry['file']) ? 'data_fil' : (string)($subEntry['type'] ?? '');
            if (!empty($subEntry['multilingual'])) $type .= ' &middot; ' . $this->cms->snippet('record.label_move_multilingual');
            $rows .= $this->_fieldRow(
                "$name.$sub",
                $isChecked("$name.$sub", !empty($subEntry['checked'])),
                "$name.$sub",
                $labelPrefix . htmlspecialchars($this->cms->textOrSnippet((string)$subEntry['label'])),
                $type,
                '',
                sub: true
            );
        }
        if ($rows === '') return '';
        return $this->backend->template('record.export_field_subrows', [
            '{more_id}' => 'c9-export-more-' . preg_replace('/[^a-z0-9_]/i', '-', $name),
            '{table_rows}' => $rows,
        ]);
    }

    /**
     * docx: Quell- und Zielsprache, mehrsprachige Felder.
     */
    private function _exportFormDocx(): void
    {
        $instanceId = (int)($this->areaData['instance_id'] ?? 0);
        $languages = $this->record->readableLanguages($this->instanceCache->languages($instanceId));
        $languageIds = array_map('intval', array_keys($languages));
        $options = [];
        foreach ($languages as $languageId => $language) {
            $options[(string)$languageId] = (string)($language['label'] ?? '') . ' (' . (string)($language['code'] ?? '') . ')';
        }
        $docx = (array)($_REQUEST['data']['docx'] ?? []);
        $from = isset($docx['from']) ? (int)$docx['from'] : ($languageIds[0] ?? 0);
        $to = isset($docx['to']) ? (int)$docx['to'] : ($languageIds[1] ?? $languageIds[0] ?? 0);
        $split = !empty($docx['split']);

        $fields = $this->export->docxFields($this->areaId);
        $requested = isset($_REQUEST['fields']) ? array_map('strval', (array)$_REQUEST['fields']) : null;
        $rows = '';
        foreach ($fields as $name => $colDef) {
            $rows .= $this->_fieldRow(
                (string)$name,
                $requested === null || in_array((string)$name, $requested, true),
                (string)$name,
                htmlspecialchars($this->cms->textOrSnippet((string)($colDef['label'] ?? $name))),
                (string)($colDef['type'] ?? ''),
                ''
            );
        }
        $fieldHtml = $rows === '' ? '' : $this->_fieldTable($rows);
        if ($fieldHtml === '') {
            $fieldHtml = $this->backend->template('alert_warning', ['{text}' => '{#record.note_export_no_multilingual}']);
        }
        if (count($languageIds) < 2) {
            echo $this->backend->template('alert_warning', ['{text}' => '{#record.note_export_one_language}']);
        }

        echo '<form action="Record/record_export.php" method="post">';
        $this->backend->formInit('record.record_export_form_docx', [
            '{scope}' => $this->_scopeHtml(),
            '{fields}' => $fieldHtml,
            '{single_checked}' => $split ? '' : ' checked',
            '{split_checked}' => $split ? ' checked' : '',
        ]);
        foreach (['from' => $from, 'to' => $to] as $key => $value) {
            $this->backend->formField($this->record, [
                'name' => 'docx.' . $key,
                'value' => $value,
                'id' => 0,
                'colDef' => ['type' => 'integer', 'display_type' => 'select', 'label' => '#record.label_docx_' . $key],
                'options' => $options,
            ]);
        }
        $this->_exportHidden();
        echo $this->backend->form('record.form_export_run', ['{options}' => $this->_exportOptions()]);
        echo '</form>';
    }

    // ====================
    // Global (Startseite)

    /**
     * Globaler Export: nur der XML-Reiter. Auswahl der Bereiche aller Typen als
     * Baum mit Checkboxen (ein Ordner wählt seine Elemente mit, siehe
     * export_area_tree.html), Optionen Struktur / Inhalte / Dateien.
     *
     * Request: global=1, function ('' | run), areas[], structure, content, files, background
     */
    private function _exportGlobal(string $function): void
    {
        $this->type = 'xml';
        $this->listQuery = 'global=1';
        $this->backend->setNaviLabel('#record.headline_export_global');
        echo $this->backend->headline('#record.headline_export_global');

        if ($function === 'run') {
            $areaIds = $this->system->sanitizeIds((array)($_REQUEST['areas'] ?? []), true);
            if (empty($areaIds)) {
                $this->cms->error($this->cms->snippet('record.error_export_no_areas'));
                $this->errors();
                $this->_exportGlobalForm();
                return;
            }
            $job = [
                'type' => 'xml',
                'area_ids' => $areaIds,
                'structure' => !empty($_REQUEST['structure']),
                'content' => !empty($_REQUEST['content']),
                'files' => !empty($_REQUEST['files']),
                'portable' => !empty($_REQUEST['portable']),
            ];
            // Umfang für den Schwellwert: Datensätze der gewählten Inhaltsbereiche
            $count = 0;
            if ($job['content']) {
                $placeholders = implode(',', array_fill(0, count($areaIds), '?'));
                $this->db->query("SELECT COUNT(*) FROM `record` WHERE `area_id` IN ($placeholders) AND `tmp` = 0 AND `deleted` = 0", str_repeat('i', count($areaIds)), $areaIds);
                $count = (int)($this->db->fetchNum()[0] ?? 0);
            }
            if (!empty($_REQUEST['background']) || $this->export->needsBackground($count)) {
                $error = '';
                if ($this->export->start($job, $error) === null) {
                    $this->cms->error($this->cms->snippet('record.error_batch_start') . ' ' . $error);
                    $this->errors();
                    $this->_exportGlobalForm();
                    return;
                }
                $this->backend->balloon('success', $this->cms->snippet('record.message_export_started'));
                $this->backend->script('cApp.layers.close();');
                return;
            }
            $result = $this->export->run($job);
            if (!empty($result['error'])) {
                $this->cms->error((string)$result['error']);
                $this->errors();
                $this->_exportGlobalForm();
                return;
            }
            echo $this->backend->template('record.export_result_global', [
                '{summary}' => htmlspecialchars($this->export->summaryText($result)),
                '{download_url}' => htmlspecialchars($this->export->downloadUrl((string)$result['zip'])),
                '{file}' => htmlspecialchars((string)$result['zip']),
                '{size}' => htmlspecialchars($this->system->convertSize((float)$result['size'])),
                '{validity}' => htmlspecialchars($this->export->validityText((string)$result['zip'])),
            ]);
            return;
        }

        $this->backend->tabBarReset();
        $this->backend->tabAdd('#record.tab_export_xml', '', true);
        echo $this->backend->tabBar();
        $this->_exportGlobalForm();
    }

    /**
     * Die Maske des globalen Exports: Bereichsbaum, Optionen.
     *
     * Der Baum steht je Instanz unter einer eigenen Zeile mit der Bezeichnung der
     * Instanz - wie ein Ordner: ein Klick wählt alle Bereiche der Instanz. Die Zeile
     * ist kein Bereich (Checkbox ohne name, data-parent der Wurzelelemente
     * "i{instance_id}"). Auf der Wurzelebene liegen die Bereiche aller Instanzen in
     * einer gemeinsamen Reihenfolge (lft) - je Instanz bleibt sie erhalten.
     */
    private function _exportGlobalForm(): void
    {
        $checked = isset($_REQUEST['areas']) ? $this->system->sanitizeIds((array)$_REQUEST['areas'], true) : [];
        $fromRequest = (string)($_REQUEST['function'] ?? '') !== '';
        $this->db->query("SELECT `area_id`, `parent_id`, `depth`, `type`, `name`, `label`, `icon`, `instance_id` FROM `area` WHERE `deleted` = 0 ORDER BY `lft` ASC");
        $byInstance = [];
        foreach ($this->db->fetchAll() as $areaRow) {
            $byInstance[(int)$areaRow['instance_id']][] = $areaRow;
        }
        $rows = '';
        // Reihenfolge der Instanzen wie überall im Backend (Standard-Instance zuletzt)
        foreach (array_keys($this->instanceCache->instanceLabels()) as $instanceId) {
            $areaRows = $byInstance[$instanceId] ?? [];
            if (!$areaRows) continue;
            $instanceLabel = htmlspecialchars($this->cms->textOrSnippet((string)($this->instanceCache->instance($instanceId)['label'] ?? '')));
            $rows .= $this->backend->template('record.export_instance_row', [
                '{instance_id}' => $instanceId,
                '{label}' => $instanceLabel !== '' ? $instanceLabel : (string)$instanceId,
                '{instance}' => $instanceLabel,
            ]);
            foreach ($areaRows as $areaRow) {
                $areaId = (int)$areaRow['area_id'];
                $parentId = (int)$areaRow['parent_id'];
                $rows .= $this->backend->template('record.export_area_row', [
                    '{area_id}' => $areaId,
                    '{parent_id}' => $parentId > 0 ? (string)$parentId : 'i' . $instanceId,
                    '{depth}' => ((int)$areaRow['depth'] + 1) * 24,
                    '{icon}' => htmlspecialchars((string)$areaRow['icon']),
                    '{label}' => htmlspecialchars($this->cms->textOrSnippet((string)$areaRow['label'])),
                    '{name}' => htmlspecialchars((string)$areaRow['name']),
                    '{type}' => htmlspecialchars((string)$areaRow['type']),
                    '{instance}' => $instanceLabel,
                    '{checked}' => in_array($areaId, $checked, true) ? ' checked' : '',
                    '{folder}' => (string)$areaRow['type'] === 'folder' ? ' c9-export-folder' : '',
                ]);
            }
        }
        echo '<form action="Record/record_export.php" method="post">';
        $this->backend->formInit('record.record_export_form_global', [
            '{rows}' => $rows,
            '{checked_structure}' => (!$fromRequest || !empty($_REQUEST['structure'])) ? ' checked' : '',
            '{checked_content}' => (!$fromRequest || !empty($_REQUEST['content'])) ? ' checked' : '',
            '{checked_files}' => (!$fromRequest || !empty($_REQUEST['files'])) ? ' checked' : '',
            '{checked_portable}' => !empty($_REQUEST['portable']) ? ' checked' : '',
            '{checked_background}' => !empty($_REQUEST['background']) ? ' checked' : '',
            '{hours}' => $this->export->retentionHours(),
        ]);
        $this->backend->formHidden('function', 'run');
        $this->backend->formHidden('global', 1);
        $this->backend->formHidden('type', 'xml');
        echo $this->backend->form('record.form_export_run_global');
        echo '</form>';
    }

    // ====================
    // Ausführen

    /**
     * Auftrag zusammenstellen, ausführen, Ergebnis mit Download-Link zeigen. Bei
     * einem Fehler erscheint die Maske erneut mit der Meldung.
     */
    private function _exportRun(): void
    {
        // in der Reihenfolge der Baumstruktur bzw. der Handsortierung (exportSort())
        [$sort, $order] = $this->export->exportSort($this->areaId);
        $ids = $this->batch->collectIds($this->areaId, $this->scope === 'filter' ? $this->filterParams : [], $sort, $order);
        $csv = (array)($_REQUEST['data']['csv'] ?? []);
        $docx = (array)($_REQUEST['data']['docx'] ?? []);
        $job = $this->export->job($this->type, $this->areaId, $ids, [
            'files'     => !empty($_REQUEST['files']),
            'header'    => !empty($csv['header']),
            'bom'       => !empty($csv['bom']),
            'separator' => (string)($csv['separator'] ?? ';'),
            'enclosure' => (string)($csv['enclosure'] ?? '"'),
            'eol'       => (string)($csv['eol'] ?? '\r\n'),
            'languages' => (array)($_REQUEST['languages'] ?? []),
            'fields'    => (array)($_REQUEST['fields'] ?? []),
            'from'      => (int)($docx['from'] ?? 0),
            'to'        => (int)($docx['to'] ?? 0),
            'split'     => !empty($docx['split']),
        ]);
        if ($job === null) {
            $this->_exportRunError($this->cms->errorMsgLast());
            $this->cms->errorsReset();
            return;
        }

        // Hintergrund
        // --------------------
        $background = !empty($_REQUEST['background']) || $this->export->needsBackground(count($job['ids']));
        if ($background) {
            $error = '';
            if ($this->export->start($job, $error) === null) {
                $this->_exportRunError($this->cms->snippet('record.error_batch_start') . ' ' . $error);
                return;
            }
            $this->backend->balloon('success', $this->cms->snippet('record.message_export_started'));
            $this->backend->script('cApp.layers.close();');
            return;
        }

        // Im Request
        // --------------------
        $result = $this->export->run($job);
        if (!empty($result['error'])) {
            $this->_exportRunError((string)$result['error']);
            return;
        }
        echo $this->backend->template('record.export_result', [
            '{summary}' => htmlspecialchars($this->export->summaryText($result)),
            '{download_url}' => htmlspecialchars($this->export->downloadUrl((string)$result['zip'])),
            '{file}' => htmlspecialchars((string)$result['zip']),
            '{size}' => htmlspecialchars($this->system->convertSize((float)$result['size'])),
            '{validity}' => htmlspecialchars($this->export->validityText((string)$result['zip'])),
        ]);
    }

    /**
     * Fehler beim Ausführen: Meldung, dann die Maske erneut.
     */
    private function _exportRunError(string $error): void
    {
        $this->cms->error($error);
        $this->errors();
        $this->backend->tabBarReset();
        foreach ($this->allowedTypes as $type) {
            $link = "cApp.layers.replace('Record/record_export.php?{$this->listQuery}&type={$type}');";
            $this->backend->tabAdd('#record.tab_export_' . $type, $type === $this->type ? '' : $link, $type === $this->type);
        }
        echo $this->backend->tabBar();
        match ($this->type) {
            'xml'  => $this->_exportFormXml(),
            'csv'  => $this->_exportFormCsv(),
            'docx' => $this->_exportFormDocx(),
        };
    }

    // ====================
    // Download

    /**
     * Zip-Datei aus var/export ausliefern (backend/record_download.php).
     *
     * Die Session hat Backend::backendPath() bereits geprüft. Erlaubt ist nur ein
     * Dateiname (kein Pfad) mit der Endung .zip, der in var/export liegt. Nach dem
     * Senden wird die Datei gelöscht - der Link gilt genau einmal.
     *
     * Request: file
     */
    public function download(): void
    {
        $file = basename((string)($_REQUEST['file'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_\-]+\.zip$/', $file)) {
            $this->system->error404();
            return;
        }
        $path = (string)$this->config->get('paths.var_dir') . '/export/' . $file;
        if (!is_file($path)) {
            $this->system->error404();
            return;
        }
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Length: ' . (int)filesize($path));
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Cache-Control: private, no-store');
        readfile($path);
        @unlink($path);
        exit;
    }
}
