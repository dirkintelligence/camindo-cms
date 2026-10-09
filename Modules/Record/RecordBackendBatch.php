<?php
/**
 * Stapelverarbeitung - die Masken (Erweiterung für RecordBackend).
 *
 * Einstiegspunkt (Script in backend/):
 *
 *     record_batch.php  -> batch()
 *
 * Erreichbar über das Menü (more_vert) rechts in der Datensatzliste; der Aufruf
 * öffnet einen eigenen Layer über der Liste (cApp.layers.add) und bringt die
 * Seitenparameter der Liste mit (cApp.layers.queryString(): area_id, filter, sort,
 * ...). Die Arbeit selbst macht RecordBatch - im Request oder als
 * Hintergrundprozess.
 *
 * NAVIGATION: Zur Liste geht es aus dem Layer nur mit cApp.layers.close() zurück
 * (nach dem Ausführen mit anschließendem refresh); innerhalb der Schritte wird der
 * Layer mit cApp.layers.replace() ersetzt.
 *
 * DREI SCHRITTE, EIN SCRIPT (function)
 * --------------------
 *     ''        Umfang und Funktion wählen. Umfang: das letzte Suchergebnis (wenn
 *               die Liste gefiltert war, vorgewählt) oder alle Datensätze des
 *               Bereichs. Funktion: Bearbeiten, Verschieben, Löschen. (Den
 *               Volltext baut die Zeitplaner-Aufgabe "Volltext neu aufbauen" neu
 *               auf, siehe SchedulerRunner::runFulltext().)
 *     select    die Maske zur Funktion:
 *                 edit    die Eingabemaske des Bereichs mit einer Checkbox vor jedem
 *                         Feld - nur angehakte Felder werden geändert (in ALLEN
 *                         Datensätzen). Ohne Baumfelder und Felder mit unique-Flag
 *                         (RecordBatch::batchFields()). Verknüpfungen mit
 *                         Suchfenster und Upload-Felder hängen an einer VORLAGE -
 *                         einer Arbeitskopie ohne Original (Record::tmpInit()), die
 *                         das Hiddenfeld batch_tmp_id durch die Schritte trägt und
 *                         wie beim Anlegen eines Datensatzes von den Ajax-Aufrufen
 *                         der Lookup- und Upload-Felder bedient wird (siehe
 *                         RecordBatch, DIE VORLAGE). Abbrechen verwirft sie
 *                         (record_cancel.php, schließt den Layer).
 *                 move    Zielbereich wählen, dann die Feldzuordnung wie beim
 *                         Verschieben eines einzelnen Datensatzes (RecordBackendMove)
 *                 delete  Rückfrage
 *               Dazu die Optionen Simulation und Hintergrund (ab
 *               admin.mass_delete_threshold Datensätzen fest, siehe RecordBatch).
 *     run       ausführen. Im Request: Ergebnis mit den Fehlermeldungen je Datensatz
 *               untereinander. Im Hintergrund: Prozess starten, Layer schließen -
 *               das Ergebnis kommt als interne Nachricht (Posteingang).
 *
 * Die Filterparameter der Liste reisen als Query-String (Hiddenfeld 'query') durch
 * die Schritte, die Ids werden erst bei 'run' ermittelt (RecordBatch::collectIds()).
 *
 * @see Camindo\Core\Modules\Record\RecordBatch        die Arbeit
 * @see Camindo\Core\Modules\Record\RecordBackendEdit  Auswahllisten der Eingabemaske
 * @see Camindo\Core\Modules\Record\RecordBackendMove  Vorbild der Feldzuordnung
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

class RecordBackendBatch extends RecordBackendEdit {

    /** Filterparameter der Liste (RecordBatch::filterParams()) */
    private array $filterParams = [];
    /** dieselben als Query-String für die Hiddenfelder */
    private string $query = '';
    /** Query-String der Liste (Hiddenfeld, für einen Rücksprung in Schritt 1) */
    private string $listQuery = '';
    /** 'filter' (letztes Suchergebnis) | 'all' */
    private string $scope = 'all';
    private string $mode = '';
    private int $count = 0;

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
    )
    {
        parent::__construct($config, $backend, $crypt, $session, $db, $system, $cms, $massDeletion, $area, $record, $instanceCache, $service, $presenter);
    }

    /**
     * Stapelverarbeitung (backend/record_batch.php).
     *
     * Request: area_id, filter/filterop/filtermode (aus der Liste) bzw. query (ab
     *          Schritt 2), list_query, function ('' | select | run), scope, mode,
     *          batch_fields[] (name bzw. name:language_id), batch_tmp_id, data[...] (Uploads in $_FILES),
     *          target_area_id, mapping[...], simulate, background
     */
    public function batch(): void
    {
        echo $this->backend->template('container_header');
        $this->function = 'list';
        $this->_resolveParams();
        $function = (string)($_REQUEST['function'] ?? '');

        if ($this->function !== 'list' || $this->areaId < 1) {
            if ($this->areaId < 1) $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }
        // Zusatzrecht batch und Leserecht auf den Bereich
        if (!$this->batchAllowed() || !$this->backend->access()->areaRead($this->areaId)) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        // Filterparameter: beim ersten Aufruf aus der Liste, danach aus 'query'
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
        $hasFilter = $this->batch->hasFilter($this->filterParams);

        $this->scope = ($hasFilter && (string)($_REQUEST['scope'] ?? 'filter') === 'filter') ? 'filter' : 'all';
        $this->mode = (string)($_REQUEST['mode'] ?? '');
        if (!in_array($this->mode, RecordBatch::MODES, true)) $this->mode = '';
        $this->count = $this->batch->count($this->areaId, $this->scope === 'filter' ? $this->filterParams : []);

        // Bread crum navi, Bereich, H1
        $this->backend->setNaviLabel('#record.headline_batch');
        echo $this->backend->template('record.prefix_content_area', [
            '{text}' => str_replace('{area_label}', $this->cms->textOrSnippet((string)$this->areaData['label']), $this->cms->snippet('area.headline_area_label'))
        ]);
        echo $this->backend->headline('#record.headline_batch');

        // Schritt 3: ausführen
        if ($function === 'run' && $this->mode !== '') {
            $this->_batchRun();
            echo $this->backend->template('container_footer');
            return;
        }

        // Schritt 2: Maske zur Funktion
        if ($function === 'select' && $this->mode !== '') {
            match ($this->mode) {
                'edit'   => $this->_batchEditForm(),
                'move'   => $this->_batchMoveForm(),
                'delete' => $this->_batchDeleteForm(),
            };
            echo $this->backend->template('container_footer');
            return;
        }

        // Schritt 1: Umfang und Funktion
        $this->_batchScopeForm($hasFilter);
        echo $this->backend->template('container_footer');
    }

    // ====================
    // Schritt 1

    /**
     * Umfang (Suchergebnis / alle) und Funktion wählen.
     */
    private function _batchScopeForm(bool $hasFilter): void
    {
        if ($hasFilter) {
            $countFilter = $this->count;
            $countAll = $this->batch->count($this->areaId, []);
            $scopeHtml = $this->backend->template('record.batch_scope_choice', [
                '{count_filter}' => $this->cms->snippet('record.batch_scope_filter', ['count' => $countFilter]),
                '{count_all}' => $this->cms->snippet('record.batch_scope_all', ['count' => $countAll]),
            ]);
        }
        else {
            $scopeHtml = $this->backend->template('record.batch_scope_note', [
                '{count}' => $this->cms->snippet('record.batch_scope_all', ['count' => $this->count]),
            ]);
        }

        echo '<form action="Record/record_batch.php" method="post">';
        $this->backend->formInit('record.record_batch_scope_form', [
            '{scope}' => $scopeHtml,
        ]);
        $this->_batchHidden('select');
        echo $this->backend->form('record.form_batch_next_close');
        echo '</form>';
    }

    /**
     * Die Hiddenfelder, die jeden Schritt begleiten.
     */
    private function _batchHidden(string $function): void
    {
        $this->backend->formHidden('function', $function);
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('query', $this->query);
        $this->backend->formHidden('list_query', $this->listQuery);
        if ($function !== 'select') {
            $this->backend->formHidden('scope', $this->scope);
            $this->backend->formHidden('mode', $this->mode);
        }
    }

    // ====================
    // Schritt 2

    /**
     * Optionen unter jeder Maske: Simulation, Hintergrund
     * (Checkbox oder - ab dem Schwellwert - Hinweis mit festem Hiddenfeld).
     */
    private function _batchOptions(): string
    {
        if ($this->batch->needsBackground($this->count)) {
            $background = $this->backend->template('record.batch_option_background_fixed', [
                '{threshold}' => (int)($this->config->get('admin.mass_delete_threshold') ?? 200),
            ]);
        }
        else {
            $background = $this->backend->template('record.batch_option_background');
        }
        return $this->backend->template('record.batch_options', [
            '{count}' => $this->cms->snippet('record.batch_scope_all', ['count' => $this->count]),
            '{simulate}' => $this->backend->template('record.batch_option_simulate'),
            '{background}' => $background,
        ]);
    }

    /**
     * edit: die Eingabemaske des Bereichs mit einer Checkbox vor jedem Feld.
     *
     * Kein Feld ist Pflicht (kein `required`) - was nicht angehakt ist, bleibt
     * unberührt; geprüft werden beim Speichern nur die angehakten Felder, nach den
     * Felddefinitionen des Bereichs. Mehrsprachige Felder stehen mit allen Sprachen
     * untereinander, jede Sprache mit eigener Checkbox ("name:language_id") - nur die
     * angehakten Sprachen werden geschrieben.
     *
     * Lookup- und Upload-Felder arbeiten wie in RecordBackendEdit::_editForm() an
     * einer Arbeitskopie - hier der Vorlage (Klassenkommentar): $this->id zeigt auf
     * sie, damit die Ajax-URLs und Vorschaublöcke der Felder sie ansprechen. Eine
     * vorhandene Vorlage (batch_tmp_id, nach einem Fehler in Schritt 3) wird
     * weiterverwendet, sonst entsteht eine neue.
     */
    private function _batchEditForm(): void
    {
        $schemaVirtual = $this->record->virtualSchema($this->areaId, false);
        $fields = $this->batch->batchFields($schemaVirtual);
        $this->data = (array)($_REQUEST['data'] ?? []);
        $checked = array_map('strval', (array)($_REQUEST['batch_fields'] ?? []));

        // Die Vorlage
        $templateId = (int)($_REQUEST['batch_tmp_id'] ?? 0);
        if (!$this->batch->templateValid($templateId, $this->areaId)) {
            $templateData = $this->record->tmpInit(['area_id' => $this->areaId]);
            $templateId = (int)($templateData['record_id'] ?? 0);
        }
        $this->id = $templateId;
        $templateData = ($templateId > 0) ? $this->record->loadTmpData($templateId) : [];

        $languages = $this->instanceCache->languages((int)($this->areaData['instance_id'] ?? 0));
        $languageIds = array_map('intval', array_keys($languages));
        $isMultilingual = count($languageIds) > 1;

        echo '<form action="Record/record_batch.php" method="post">';
        $this->backend->formInit('record.record_batch_edit_form', [
            '{count}' => $this->cms->snippet('record.batch_scope_all', ['count' => $this->count]),
        ]);

        foreach ($fields as $name => $colDef) {
            $type = (string)($colDef['type'] ?? 'text');
            $displayType = (string)($colDef['display_type'] ?? 'text');
            $fieldId = (int)$colDef['field_id'];
            $isLookup = in_array($type, $this->cms->relationTypes, true) && $displayType === 'lookup';

            // Auswahlmöglichkeiten wie in der Eingabemaske
            $options = null;
            if (in_array($type, $this->cms->relationTypes, true) && !$isLookup) {
                $relAreaIds = str_starts_with($type, 'relation')
                    ? [(int)$colDef['rel_area_id']]
                    : $this->record->getRelAreaIds($fieldId);
                $options = ($displayType === 'select')
                    ? $this->_relatedDatasetOptions($relAreaIds, addEmptyOption: true)
                    : $this->_relatedDatasetOptions($relAreaIds);
            }
            elseif ($name === 'template_id') $options = $this->_templateOptions();
            elseif ($name === 'status_id')   $options = $this->_statusOptions(settableOnly: true);

            // Kein required: nur angehakte Felder zählen
            $colDef['flags'] = array_values(array_diff((array)($colDef['flags'] ?? []), ['not_empty']));

            // Checkbox: Wert "name", bei mehrsprachigen Feldern je Sprache "name:language_id"
            $checkbox = fn(?int $languageId) => $this->backend->template('record.batch_field_checkbox', [
                '{name}' => htmlspecialchars($name),
                '{value}' => htmlspecialchars($languageId === null ? $name : "{$name}:{$languageId}"),
                '{field_id}' => $fieldId,
                '{lang}' => $languageId ?? '',
                '{checked}' => in_array($languageId === null ? $name : "{$name}:{$languageId}", $checked, true) ? ' checked' : '',
            ]);
            $fieldParams = [
                'name' => $name,
                'colDef' => $colDef,
                'value' => $this->data[$name] ?? '',
                'id' => $templateId,
                'append' => true,
                'options' => $options,
                'labelPrefix' => $checkbox(null),
            ];

            if ($isMultilingual && $this->cms->isMultilingualField($colDef)) {
                $values = (array)($this->data[$name] ?? []);
                // Upload-Feld: je Sprache die eigene Datei der Vorlage
                $files = ($type === 'file' && isset($templateData[$name]))
                    ? $this->record->fileEntries((array)$templateData[$name]) : [];
                foreach ($languageIds as $languageId) {
                    $langParams = $fieldParams;
                    $langParams['value'] = $values[$languageId] ?? '';
                    $langParams['langId'] = $languageId;
                    $langParams['langSuffix'] = (string)($languages[$languageId]['code'] ?? $languageId);
                    $langParams['placeholder'] = '{data_' . $name . '__' . $languageId . '}';
                    $langParams['labelPrefix'] = $checkbox($languageId);
                    $this->backend->formField($this->record, $langParams);
                    if ($type === 'file') {
                        $this->_editFileReplacements($name, $fieldId, $this->_batchTemplateFile((array)($files[$languageId] ?? [])), $languageId);
                    }
                }
                continue;
            }
            $this->backend->formField($this->record, $fieldParams);

            if ($isLookup) {
                $this->_editLookupReplacements($fieldId, (int)($colDef['max'] ?? 0));
            }
            elseif ($type === 'file') {
                $fileData = isset($templateData[$name]) ? $this->record->fileEntry((array)$templateData[$name], null) : [];
                $this->_editFileReplacements($name, $fieldId, $this->_batchTemplateFile($fileData), null);
            }
        }

        $this->backend->formHidden('batch_tmp_id', $templateId);
        $this->_batchHidden('run');
        echo $this->backend->form('record.form_batch_run', [
            '{options}' => $this->_batchOptions(),
            '{cancel}' => "cApp.layers.replacePost('Record/record_cancel.php?id={$templateId}')",
            '{icon}' => 'save',
            '{label}' => '{#button_save}',
        ]);
        echo '</form>';
    }

    /**
     * Datei-Eintrag der Vorlage für die Vorschau: entfernte (x-Button) und leere
     * Einträge zeigen nichts - wie RecordBackendEdit::_editRestoreSecuredFiles().
     */
    private function _batchTemplateFile(array $fileData): array
    {
        if (!empty($fileData['deleted']) || (int)($fileData['size'] ?? 0) < 1) return [];
        return $fileData;
    }

    /**
     * delete: Rückfrage.
     */
    private function _batchDeleteForm(): void
    {
        echo '<form action="Record/record_batch.php" method="post">';
        $this->backend->formInit('record.record_batch_delete_form', [
            '{text}' => $this->cms->snippet('record.alert_batch_delete', ['count' => $this->count]),
            // was die Lösch-Policy des Bereichs mit jedem Datensatz tut (Modul Privacy)
            '{policy}' => $GLOBALS['app']->get(\Camindo\Core\Modules\Privacy\PrivacyBackend::class)->areaHintHtml($this->areaId),
        ]);
        $this->_batchHidden('run');
        echo $this->backend->form('record.form_batch_run', [
            '{options}' => $this->_batchOptions(),
            '{cancel}' => 'cApp.layers.close()',
            '{icon}' => 'delete',
            '{label}' => '{#button_delete}',
        ]);
        echo '</form>';
    }

    /**
     * move: Zielbereich wählen (2a), dann die Feldzuordnung (2b) - wie
     * RecordBackendMove, nur ohne Datensatz und mit den Optionen darunter.
     */
    private function _batchMoveForm(): void
    {
        $targetAreaId = (int)($_REQUEST['data']['target_area_id'] ?? $_REQUEST['target_area_id'] ?? 0);
        $mapping = (array)($_REQUEST['mapping'] ?? []);
        $targetData = ($targetAreaId > 0 && $targetAreaId !== $this->areaId)
            ? $this->backend->areaData($targetAreaId)
            : null;

        echo '<form action="Record/record_batch.php" method="post">';
        if ($targetData && in_array((string)$targetData['type'], Area::CONTENT_TYPES, true)) {
            // 2b: Feldzuordnung
            $schemaSource = $this->record->virtualSchema($this->areaId, false);
            $schemaTarget = $this->record->virtualSchema($targetAreaId, false);
            $sourceFields = $this->record->moveFields($schemaSource);
            $targetFields = $this->record->moveFields($schemaTarget);
            $fromRequest = !empty($mapping);

            $tplRow = $this->backend->template('record.record_move_row');
            $tableRows = '';
            foreach ($sourceFields as $name => $colDef) {
                $targets = $this->record->moveTargets($colDef, $targetFields);
                $selected = $fromRequest ? (string)($mapping[$name] ?? '') : (isset($targets[$name]) ? $name : '');
                $options = '<option value="">' . htmlspecialchars($this->cms->snippet('record.option_move_delete')) . "</option>\n";
                foreach ($targets as $targetName => $targetDef) {
                    $optionLabel = $this->cms->textOrSnippet((string)($targetDef['label'] ?? $targetName)) . ' (' . $targetName . ')';
                    $isSelected = ($selected === (string)$targetName) ? ' selected' : '';
                    $options .= '<option value="' . htmlspecialchars((string)$targetName) . '"' . $isSelected . '>'
                        . htmlspecialchars($optionLabel) . "</option>\n";
                }
                $multilingual = $this->cms->isMultilingualField($colDef)
                    ? ' &middot; ' . $this->cms->snippet('record.label_move_multilingual') : '';
                $tableRows .= strtr($tplRow, [
                    '{label}' => htmlspecialchars($this->cms->textOrSnippet((string)($colDef['label'] ?? $name))),
                    '{name}' => htmlspecialchars((string)$name),
                    '{type}' => htmlspecialchars((string)($colDef['type'] ?? '')),
                    '{multilingual}' => $multilingual,
                    '{options}' => $options,
                    '{class}' => '',
                    '{error}' => '',
                ]) . "\n";
            }
            $instanceWarning = '';
            if ((int)($targetData['instance_id'] ?? 0) !== (int)($this->areaData['instance_id'] ?? 0)) {
                $instanceWarning = $this->backend->template('alert_warning', ['{text}' => '{#record.warning_move_instance}']);
            }
            $this->backend->formInit('record.record_batch_move_form', [
                '{count}' => $this->cms->snippet('record.batch_scope_all', ['count' => $this->count]),
                '{instance_warning}' => $instanceWarning,
                '{source_area}' => $this->backend->teaser('area', $this->areaId, false),
                '{target_area}' => $this->backend->teaser('area', $targetAreaId, false),
                '{table_rows}' => $tableRows,
            ]);
            $this->backend->formHidden('target_area_id', $targetAreaId);
            $this->_batchHidden('run');
            echo $this->backend->form('record.form_batch_run', [
                '{options}' => $this->_batchOptions(),
                '{cancel}' => 'cApp.layers.close()',
                '{icon}' => 'drive_file_move',
                '{label}' => '{#button_move}',
            ]);
        }
        else {
            // 2a: Zielbereich wählen - die Auswahl schickt das Formular ab
            $this->backend->formInit('record.record_move_area_form');
            $this->backend->formField($this->record, [
                'name' => 'target_area_id',
                'value' => 0,
                'id' => 0,
                'colDef' => [
                    'type' => 'integer',
                    'display_type' => 'select',
                    'label' => '#record.headline_move_target'
                ],
                'options' => $this->_batchTargetAreas(),
                'template' => 'record.record_select_area_id',
                'selectPlease' => true
            ]);
            $this->_batchHidden('select');
            $this->backend->formHidden('scope', $this->scope);
            $this->backend->formHidden('mode', $this->mode);
            echo $this->backend->form('record.form_batch_cancel');
        }
        echo '</form>';
    }

    /**
     * Zielbereiche: alle Datensatz-Bereiche außer dem eigenen (siehe
     * RecordBackendMove::_moveAreaForm()).
     */
    private function _batchTargetAreas(): array
    {
        $sourceInstanceId = (int)($this->areaData['instance_id'] ?? 0);
        $placeholders = implode(',', array_fill(0, count(Area::CONTENT_TYPES), '?'));
        $this->db->query(
            "SELECT `area_id`, `instance_id`, `depth`, `label` FROM `area`"
            . " WHERE `type` IN ($placeholders) AND `deleted` = 0 AND `area_id` != ?"
            . $this->backend->areaAccessSql(writable:true)
            . " ORDER BY `lft` ASC",
            str_repeat('s', count(Area::CONTENT_TYPES)) . 'i',
            array_merge(Area::CONTENT_TYPES, [$this->areaId])
        );
        $areaOptions = [];
        while ($areaData = $this->db->fetch()) {
            $label = str_repeat('&nbsp; ', (int)$areaData['depth'] * 2)
                . $this->cms->textOrSnippet((string)$areaData['label']);
            $instanceId = (int)$areaData['instance_id'];
            if ($instanceId !== $sourceInstanceId) {
                $instanceLabel = (string)($this->instanceCache->instance($instanceId)['label'] ?? $instanceId);
                $label .= ' (' . $this->cms->textOrSnippet($instanceLabel) . ')';
            }
            $areaOptions[(string)$areaData['area_id']] = $label;
        }
        return $areaOptions;
    }

    // ====================
    // Schritt 3

    /**
     * Auftrag zusammenstellen und ausführen bzw. in den Hintergrund geben.
     */
    private function _batchRun(): void
    {
        $simulate = !empty($_REQUEST['simulate']);
        $background = !empty($_REQUEST['background']) || $this->batch->needsBackground($this->count);

        $job = [
            'mode'     => $this->mode,
            'area_id'  => $this->areaId,
            'simulate' => $simulate,
        ];
        if ($this->mode === 'edit') {
            $data = (array)($_REQUEST['data'] ?? []);
            $allowed = $this->batch->batchFields($this->record->virtualSchema($this->areaId, false));
            [$fields, $languages] = $this->batch->checkedFields((array)($_REQUEST['batch_fields'] ?? []), $allowed);
            // Die Vorlage: Uploads und Passwörter des Requests an sie binden - VOR
            // jeder Fehlermeldung, damit sie in der erneut angezeigten Maske stehen
            $templateId = (int)($_REQUEST['batch_tmp_id'] ?? 0);
            if (!$this->batch->templateValid($templateId, $this->areaId)) {
                $this->cms->error($this->cms->snippet('record.error_batch_template'));
                unset($_REQUEST['batch_tmp_id']);
            }
            elseif (!$this->record->secureInputs($templateId, $data)) {
                // Datei abgelehnt - Meldung steht in Cms::errors
            }
            elseif (empty($fields)) {
                $this->cms->error($this->cms->snippet('record.error_batch_no_fields'));
            }
            if (!empty($this->cms->errors)) {
                $this->errors();
                $this->_batchEditForm();
                return;
            }
            $job['fields'] = $fields;
            try {
                $job += $this->batch->editJob($templateId, $fields, $data, $allowed, $languages);
            } catch (\Throwable $e) {
                $this->cms->error($e->getMessage());
                $this->errors();
                $this->_batchEditForm();
                return;
            }
            // Der Auftrag ist in sich geschlossen - die Vorlage hat ausgedient
            $this->record->cancel($templateId);
            // Die Uploads des Requests stecken als 'importfile' im Auftrag. Ein
            // stehengebliebener $_FILES-Eintrag hätte beim Speichern jedes Datensatzes
            // Vorrang (_saveUploadField()) und würde - längst verschoben - übersprungen
            $_FILES = [];
        }
        elseif ($this->mode === 'move') {
            $job['target_area_id'] = (int)($_REQUEST['target_area_id'] ?? 0);
            $job['mapping'] = (array)($_REQUEST['mapping'] ?? []);
            // Abbildung einmal vorab prüfen - der Fehler gilt sonst für jeden Datensatz
            $sourceFields = $this->record->moveFields($this->record->virtualSchema($this->areaId, false));
            $targetFields = $this->record->moveFields($this->record->virtualSchema($job['target_area_id'], false));
            if ($job['target_area_id'] < 1 || $this->record->moveMapping($sourceFields, $targetFields, $job['mapping']) === null) {
                if ($job['target_area_id'] < 1) $this->cms->error($this->cms->snippet('area.error_area_not_found'));
                $this->errors();
                $this->_batchMoveForm();
                return;
            }
        }

        $job['ids'] = $this->batch->collectIds($this->areaId, $this->scope === 'filter' ? $this->filterParams : []);

        // Hintergrund
        // --------------------
        if ($background) {
            $error = '';
            if ($this->batch->start($job, $error) === null) {
                $this->batch->cleanupJob($job);
                $this->cms->error($this->cms->snippet('record.error_batch_start') . ' ' . $error);
                $this->errors();
                echo $this->backend->template('record.form_batch_cancel');
                return;
            }
            $this->backend->balloon('success', $this->cms->snippet('record.message_batch_started'));
            $this->backend->script('cApp.layers.invalidate(); cApp.layers.close();');
            return;
        }

        // Im Request
        // --------------------
        $result = $this->batch->run($job);
        // die Liste darunter hat sich geändert: beim Wiederanzeigen neu laden
        // (Schließen-Knopf, Breadcrumb-Navi, "Zurück" des Browsers)
        $this->backend->script('cApp.layers.invalidate();');
        $items = '';
        $tplRow = $this->backend->template('record.batch_result_row');
        foreach ($result['errors'] as $id => $entry) {
            $items .= strtr($tplRow, [
                '{teaser}' => $id > 0 ? ($this->backend->teaser('record', (int)$id) ?: "[{$id}]") : '-',
                '{messages}' => htmlspecialchars(implode(' | ', $entry['messages'])),
            ]) . "\n";
        }
        echo $this->backend->template('record.batch_result', [
            '{class}' => $result['failed'] > 0 ? 'warning' : 'success',
            '{summary}' => htmlspecialchars($this->batch->summaryText($job, $result)),
            '{items}' => $items,
        ]);
    }
}
