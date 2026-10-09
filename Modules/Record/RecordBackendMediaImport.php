<?php
/**
 * Media import - the mask (extension for RecordBackend).
 *
 * Entry point (script in backend/):
 *
 *     record_media_import.php  -> import()
 *
 * Reachable via the menu (more_vert) on the right of the record list of an area,
 * under "Import" - only with an upload field in the area, the additional right import_media
 * and the right to create records in the area; not in dependent areas (area.dependent: their
 * records are only created via a field of type link). Opens its own layer
 * above the list; the work is done by RecordMediaImport.
 *
 * STEPS (function)
 * --------------------
 *     ''        Choose the zip file: upload or a file from var/upload
 *     prepare   unpack (RecordMediaImport::prepare()), continue with analyze
 *     analyze   files grouped by MIME type, dangerous ones grayed out; selection of the
 *               types, the upload field (required, the first one preselected) and the
 *               field for the file name (optional, the default field preselected).
 *               A change of the upload field shows the mask anew - which types the
 *               field accepts depends on its settings
 *     run       import (always INSERT), protocol; the list below reloads
 *               as soon as it is visible again (cApp.layers.invalidate())
 *     cancel    remove the import directory, close the layer
 *
 * @see Camindo\Core\Modules\Record\RecordMediaImport  the work
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

class RecordBackendMediaImport extends RecordBackend {

    private string $dir = '';

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
        private RecordMediaImport $import,
    )
    {
        parent::__construct($config, $backend, $crypt, $session, $db, $system, $cms, $massDeletion, $area, $record, $instanceCache, $service, $presenter);
    }

    /**
     * Media import (backend/record_media_import.php).
     *
     * Request: area_id, function ('' | prepare | analyze | run | cancel), dir,
     *          importfile ($_FILES) | serverfile, types[], file_field, name_field
     */
    public function import(): void
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
        if (!$this->mediaImportAllowed()) {
            echo $this->backend->access()->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        $this->backend->setNaviLabel('#record.headline_media_import');
        echo $this->backend->template('record.prefix_content_area', [
            '{text}' => str_replace('{area_label}', $this->cms->textOrSnippet((string)$this->areaData['label']), $this->cms->snippet('area.headline_area_label'))
        ]);
        echo $this->backend->headline('#record.headline_media_import');

        $this->dir = (string)($_REQUEST['dir'] ?? '');
        if ($this->dir !== '' && $this->import->importDir($this->dir) === null) {
            $this->dir = '';
        }

        switch ($function) {
            case 'cancel':
                // Deletes the import directory - POST only (developer docs, chapter 14 (Security: web))
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
            case 'run':
                $this->_importRun();
                break;
            default:
                $this->_importUploadForm();
        }
        echo $this->backend->template('container_footer');
    }

    // ====================
    // Step 0: choose the zip file

    private function _importUploadForm(): void
    {
        // like the upload field of the input mask: file input plus button for a file
        // from var/upload (Record/server_file.php -> ajax_import_file.php)
        $preview = '';
        $serverFile = trim((string)($_REQUEST['serverfile'] ?? ''));
        if ($serverFile !== '') {
            try {
                $relative = DirectoryHelpers::checkPath($serverFile);
                $preview = $this->backend->template('record.import_server_file_preview', [
                    '{file}' => htmlspecialchars($relative),
                    '{text}' => htmlspecialchars(basename($relative)),
                ]);
            }
            catch (\Throwable $e) {
            }
        }
        echo '<form action="Record/record_media_import.php" method="post" enctype="multipart/form-data">';
        $this->backend->formInit('record.record_media_import_form_upload', [
            '{upload_field}' => $this->backend->template('record.media_import_upload_field', ['{preview}' => $preview]),
        ]);
        $this->backend->formHidden('function', 'prepare');
        $this->backend->formHidden('area_id', $this->areaId);
        echo $this->backend->form('record.form_import_next_close');
        echo '</form>';
    }

    // ====================
    // Step 1a: unpack

    private function _importPrepare(): void
    {
        $upload = $_FILES['importfile'] ?? null;
        $serverFile = trim((string)($_REQUEST['serverfile'] ?? ''));
        if ($upload && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int)$upload['error'] !== UPLOAD_ERR_OK || empty($upload['size']) || !is_uploaded_file((string)$upload['tmp_name'])) {
                $result = ['error' => $this->cms->snippet('error_file_upload')];
            }
            else {
                $result = $this->import->prepare((string)$upload['tmp_name'], (string)$upload['name']);
            }
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
                : $this->import->prepare($path, basename($relative));
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
        $this->_importAnalyze();
    }

    // ====================
    // Step 1b: settings

    /**
     * Query string of the cancel button: removes the import directory.
     */
    private function _cancelQuery(): string
    {
        return 'area_id=' . $this->areaId . '&function=cancel&dir=' . rawurlencode($this->dir);
    }

    private function _importAnalyze(): void
    {
        $files = $this->dir !== '' ? $this->import->files($this->dir) : null;
        if ($files === null) {
            $this->cms->error($this->cms->snippet('record.error_import_no_file'));
            $this->errors();
            $this->_importUploadForm();
            return;
        }
        $analysis = $this->import->analyze($files);
        $fileFields = $this->import->fileFields($this->areaId);
        $textFields = $this->import->textFields($this->areaId);

        // Upload field (required): selected, or the first one; name field: selected, or the
        // default field of the area (if it is a text field)
        $fileField = (string)($_REQUEST['file_field'] ?? '');
        if (!isset($fileFields[$fileField])) $fileField = (string)array_key_first($fileFields);
        if (isset($_REQUEST['file_field'])) {
            $nameField = (string)($_REQUEST['name_field'] ?? '');
        }
        else {
            $nameField = (string)($this->backend->getDefaultField($this->areaId)['name'] ?? '');
        }
        if (!isset($textFields[$nameField])) $nameField = '';

        // MIME types: per group, how many files the upload field accepts. After a
        // change of the field the selection remains; types that the previous field did
        // not accept (not in 'offered') are preselected
        $requested = isset($_REQUEST['file_field']) ? array_map('strval', (array)($_REQUEST['types'] ?? [])) : null;
        $offered = array_map('strval', (array)($_REQUEST['offered'] ?? []));
        $rows = '';
        $index = 0;
        $offeredHidden = [];
        foreach ($analysis['groups'] as $mime => $group) {
            $accepted = 0;
            foreach ($group['files'] as $path) {
                $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
                if ($this->record->fileTypeAllowed($fileFields[$fileField], $mime, $extension)) $accepted++;
            }
            $enabled = $accepted > 0;
            $checked = $enabled && ($requested === null || in_array($mime, $requested, true) || !in_array($mime, $offered, true));
            if ($enabled) $offeredHidden[] = $mime;
            $note = '';
            if (!$enabled) $note = $this->cms->snippet('record.note_media_import_not_accepted');
            elseif ($accepted < (int)$group['count']) $note = str_replace(['{accepted}', '{count}'], [$accepted, (int)$group['count']], $this->cms->snippet('record.note_media_import_partly_accepted'));
            $rows .= $this->backend->template('record.media_import_type_row', [
                '{id}' => 'c9-media-type-' . (++$index),
                '{mime}' => htmlspecialchars($mime),
                '{count}' => (int)$group['count'],
                '{size}' => htmlspecialchars($this->system->convertSize((float)$group['size'])),
                '{files}' => htmlspecialchars($this->_fileList($group['files'])),
                '{note}' => $note !== '' ? $this->backend->template('record.media_import_note', ['{text}' => $this->backend->escape($note)]) : '',
                '{checked}' => $checked ? ' checked' : '',
                '{disabled}' => $enabled ? '' : ' disabled',
                '{class}' => $enabled ? '' : 'opacity-50',
            ]);
        }
        foreach ($analysis['blocked'] as $file) {
            $rows .= $this->backend->template('record.media_import_blocked_row', [
                '{mime}' => htmlspecialchars((string)$file['mime']),
                '{file}' => htmlspecialchars((string)$file['path']),
                '{reason}' => htmlspecialchars($this->cms->snippet('record.media_import_reason_' . $file['reason'])),
            ]);
        }
        $ignored = '';
        if (!empty($analysis['ignored'])) {
            $ignored = $this->backend->template('record.media_import_ignored', [
                '{text}' => htmlspecialchars(str_replace('{count}', (string)count($analysis['ignored']), $this->cms->snippet('record.note_media_import_ignored'))),
                '{files}' => htmlspecialchars($this->_fileList(array_column($analysis['ignored'], 'path'))),
            ]);
        }

        // Auswahllisten
        $fileOptions = '';
        foreach ($fileFields as $name => $colDef) {
            $fileOptions .= '<option value="' . htmlspecialchars($name) . '"' . ($name === $fileField ? ' selected' : '') . '>'
                . htmlspecialchars($this->cms->textOrSnippet((string)($colDef['label'] ?? $name)) . " ($name)") . "</option>\n";
        }
        $nameOptions = '<option value="">' . htmlspecialchars($this->cms->snippet('record.option_media_import_no_name')) . "</option>\n";
        foreach ($textFields as $name => $colDef) {
            $nameOptions .= '<option value="' . htmlspecialchars($name) . '"' . ($name === $nameField ? ' selected' : '') . '>'
                . htmlspecialchars($this->cms->textOrSnippet((string)($colDef['label'] ?? $name)) . " ($name)") . "</option>\n";
        }

        $importable = count($analysis['groups']);
        echo '<form action="Record/record_media_import.php" method="post">';
        $this->backend->formInit('record.record_media_import_form', [
            '{summary}' => htmlspecialchars(str_replace(
                ['{files}', '{types}'],
                [array_sum(array_column($analysis['groups'], 'count')), $importable],
                $this->cms->snippet('record.note_media_import_summary')
            )),
            '{rows}' => $rows !== '' ? $rows : $this->backend->template('record.media_import_none_row'),
            '{ignored}' => $ignored,
            '{file_options}' => $fileOptions,
            '{name_options}' => $nameOptions,
        ]);
        $this->backend->formHidden('function', 'run');
        $this->backend->formHidden('area_id', $this->areaId);
        $this->backend->formHidden('dir', $this->dir);
        foreach ($offeredHidden as $i => $mime) $this->backend->formHidden("offered[$i]", $mime);
        echo $this->backend->form('record.form_media_import_run', ['{cancel_query}' => $this->_cancelQuery()]);
        echo '</form>';
    }

    /**
     * File names for display, shortened if there are many.
     */
    private function _fileList(array $paths, int $max = 8): string
    {
        $list = implode(', ', array_slice($paths, 0, $max));
        if (count($paths) > $max) $list .= ', …';
        return $list;
    }

    // ====================
    // Step 2: import

    private function _importRun(): void
    {
        if ($this->dir === '' || $this->import->files($this->dir) === null) {
            $this->cms->error($this->cms->snippet('record.error_import_no_file'));
            $this->errors();
            $this->_importUploadForm();
            return;
        }
        $types = array_map('strval', (array)($_REQUEST['types'] ?? []));
        $fileField = (string)($_REQUEST['file_field'] ?? '');
        if (empty($types) || !isset($this->import->fileFields($this->areaId)[$fileField])) {
            $this->cms->error($this->cms->snippet(empty($types) ? 'record.error_media_import_no_types' : 'record.error_media_import_no_field'));
            $this->errors();
            $this->_importAnalyze();
            return;
        }
        // multilingual fields: the primary language is written
        $instanceId = (int)($this->areaData['instance_id'] ?? 0);
        if (!$this->backend->access()->languageWrite($this->instanceCache->primaryLanguageId($instanceId))) {
            $this->cms->error($this->cms->snippet('record.error_import_language_denied'));
            $this->errors();
            $this->_importAnalyze();
            return;
        }
        $job = [
            'area_id' => $this->areaId,
            'dir' => $this->dir,
            'types' => $types,
            'file_field' => $fileField,
            'name_field' => (string)($_REQUEST['name_field'] ?? ''),
        ];
        $result = $this->import->run($job);
        $this->import->removeDir($this->dir);
        // the list below has changed: reload when it is shown again
        $this->backend->script('cApp.layers.invalidate();');
        echo $this->_protocolHtml($result);
        echo $this->backend->template('record.form_import_result_close');
    }

    private function _protocolHtml(array $result): string
    {
        $classes = ['inserted' => 'success', 'failed' => 'danger', 'skipped' => 'secondary'];
        $rows = '';
        $tplRow = $this->backend->template('record.import_protocol_row');
        foreach ($result['items'] as $item) {
            $status = (string)$item['status'];
            $id = (int)$item['id'];
            $record = $id > 0 ? $this->backend->teaser('record', $id, linked: true, icon: false) : '';
            if ($record === '') $record = htmlspecialchars((string)$item['label']);
            else $record .= ' ' . $this->backend->template('small-muted', ['{text}' => $this->backend->escape((string)$item['label'])]);
            $rows .= strtr($tplRow, [
                '{class}' => $classes[$status] ?? 'secondary',
                '{kind}' => htmlspecialchars($this->cms->snippet('record.import_kind_record')),
                '{record}' => $record,
                '{status}' => htmlspecialchars($this->cms->snippet('record.import_status_' . $status)),
                '{messages}' => htmlspecialchars(implode(' | ', (array)$item['messages'])),
            ]) . "\n";
        }
        return $this->backend->template('record.import_protocol', [
            '{class}' => ((int)$result['failed'] > 0 || !empty($result['error'])) ? 'warning' : 'success',
            '{summary}' => htmlspecialchars($this->import->summaryText($result) . (!empty($result['error']) ? ' ' . $result['error'] : '')),
            '{rows}' => $rows,
        ]);
    }
}
