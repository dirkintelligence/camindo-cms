<?php
/**
 * Image editing on the info page of a record (extension for RecordBackend).
 *
 * Entry points (scripts in backend/):
 *
 *     record_image_edit.php  -> open()  layer "Image editing" (upload field) or
 *                                       "Define section" (thumbnail field)
 *     ajax_image_edit.php    -> ajax()  function = preview | save | cancel (POST),
 *                                       image (GET: the temporary preview)
 *
 * Only with ImageMagick (processor Magick), on the productive record. While the layer is
 * open, the record is locked like by the input mask - by a row without working copy
 * (Record::lockOnly(), _acquireLock()); "Save" and "Cancel" release it, sign-out
 * too, otherwise it runs out after admin.edit_lock_sec without activity. Every
 * preview renews it; if someone has lifted it in the input mask, saving is refused.
 *
 * UPLOAD FIELD: IMAGE EDITING
 * --------------------
 * Rotate clockwise, midtones (gamma) and a freely chosen section with an optional
 * target size. Every step is calculated from the ORIGINAL (Magick::renderEdit()) into a
 * temporary preview under paths.tmp_dir/image_edit - outside the document root,
 * delivered only by ajax() to the logged-in user with read right, the name derived
 * from the session. Only "Save" replaces the file of the field:
 *
 *     - the unchanged original is kept under {var_dir}/media_source/{field_id}/
 *       {record_id}[_{language_id}].{ext} (outside the document root) - the next
 *       editing starts from it again
 *     - the settings are stored in `data_fil`.data as "edit"
 *       ({rotate, gamma, crop, width, height}, Magick::normalizeEdit())
 *     - size, dimensions, version (new address) change; the thumbnails are generated
 *       anew, sections chosen for them by hand are applied again
 *
 * A new upload into the field writes `data_fil`.data anew ({}): the settings are gone,
 * the kept original is discarded with the next editing.
 *
 * THUMBNAIL FIELD: DEFINE SECTION
 * --------------------
 * Only if the converter that generates the thumbnail is an ImageMagick converter with
 * target width AND height (VirtualFieldsTrait::effectiveConverter()). The box has their
 * aspect ratio and lies on the source file of the thumbnail; it is stored as
 * fractions in `data_fil`.data of the thumbnail as "crop" and applied by
 * writeThumbnails() (details.manual_crop) - also with every later regeneration, until
 * a new upload.
 *
 * @see Camindo\Core\Processors\Magick\Magick::renderEdit()
 * @see Camindo\Core\VirtualFieldsTrait::regenerateThumbnails()
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Processors\Magick\Magick;

class RecordBackendImageEdit extends RecordBackend {

    /** Longest side of the preview in pixels */
    public const PREVIEW_MAX = 1600;

    /** Temporary previews older than this are removed when a layer opens */
    protected const PREVIEW_MAX_AGE = 86400;

    // ====================
    // Info page

    /**
     * Links below the file name in the folded details of a file field (info page,
     * Backend::$fileActionHook): "Image editing" for upload fields, "Define section"
     * for thumbnail fields - each only if ImageMagick can do it.
     *
     * @param array    $value           file metadata (`data_fil` / cache entry)
     * @param int      $id              record id
     * @param array    $colDef          field definition (virtual schema)
     * @param int|null $fileLanguageId  language of the file (null: single-language)
     */
    public function fileActions(array $value, int $id, array $colDef, ?int $fileLanguageId): string
    {
        if (empty($value['size']) || !empty($value['token']) || !empty($value['temporary'])) return '';
        $fieldId = (int)($colDef['field_id'] ?? 0);
        if ($fieldId < 1) return '';
        $mode = $this->_mode($colDef, (array)$value, $id, $fileLanguageId);
        if ($mode === null) return '';
        $url = 'Record/record_image_edit.php?id=' . $id . '&field_id=' . $fieldId
            . '&lang=' . ($fileLanguageId === null ? '' : $fileLanguageId);
        return $this->backend->template('record.file_image_action', [
            '{icon}' => $mode === 'edit' ? 'tune' : 'crop',
            '{label}' => $mode === 'edit' ? '{#record.image_edit_link}' : '{#record.image_crop_link}',
            '{script}' => 'cApp.layers.add(' . $this->backend->jsString($url) . ')',
        ]);
    }

    /**
     * Which editing does a file allow: 'edit' (upload field), 'crop' (thumbnail field
     * of an ImageMagick converter with target width and height), null = none.
     */
    private function _mode(array $colDef, array $value, int $id, ?int $fileLanguageId): ?string
    {
        if (($colDef['type'] ?? '') !== 'file') return null;
        $magick = $this->record->magickProcessor();
        if ($magick === null) return null;
        $mime = (string)($value['mime_type'] ?? '');
        $extension = (string)($value['extension'] ?? '');
        if (($colDef['display_type'] ?? '') !== 'thumbnail') {
            return $magick->canEdit($mime, $extension) ? 'edit' : null;
        }
        return $this->_cropTarget($colDef, $id, $fileLanguageId) !== null ? 'crop' : null;
    }

    /**
     * Thumbnail field: source file and target size, if a section can be chosen.
     *
     * @return array|null  ['parent' => `data_fil` row of the source, 'parent_field_id',
     *                      'width', 'height' (target size of the converter)]
     */
    private function _cropTarget(array $colDef, int $id, ?int $fileLanguageId): ?array
    {
        $parentFieldId = (int)($colDef['parent_field_id'] ?? 0);
        if ($parentFieldId < 1) return null;
        $parent = $this->record->fileRow($id, $parentFieldId, $fileLanguageId);
        if (!$parent || empty($parent['size']) || (string)($parent['token'] ?? '') !== '') return null;
        $mime = (string)$parent['mime_type'];
        $extension = (string)$parent['extension'];
        $converter = $this->record->effectiveConverter((int)$colDef['field_id'], $mime, $extension);
        if ($converter === null || !($converter['instance'] instanceof Magick)) return null;
        $details = (array)$converter['details'];
        $width = (int)($details['width'] ?? 0);
        $height = (int)($details['height'] ?? 0);
        if ($width < 1 || $height < 1 || ($details['unit'] ?? 'px') === 'percent') return null;
        if (!$converter['instance']->canCrop($mime, $extension)) return null;
        return ['parent' => $parent, 'parent_field_id' => $parentFieldId, 'width' => $width, 'height' => $height];
    }

    // ====================
    // Layer

    /**
     * Layer "Image editing" / "Define section" (record_image_edit.php).
     *
     * Request: id (productive record), field_id, lang ('' = single-language file)
     */
    public function open(): void
    {
        echo $this->backend->template('container_header');
        $target = $this->_load(true);
        if ($target === null) {
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }
        $isEdit = $target['mode'] === 'edit';
        $this->backend->setNaviLabel($isEdit ? '#record.image_edit_title' : '#record.image_crop_title');

        // the lock is a change: only via POST (like the check-out of the input mask) -
        // a GET (bookmark, F5, back button) shows a placeholder with a button
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST' && PHP_SAPI !== 'cli') {
            $elementId = 'c9-open-' . bin2hex(random_bytes(6));
            echo $this->backend->template('record.image_edit_open_stub', [
                '{element_id}' => $elementId,
                '{button}' => $this->backend->escape($this->cms->snippet($isEdit ? 'record.image_edit_title' : 'record.image_crop_title')),
                '{onclick}' => "cApp.layers.openPost('{$elementId}', true)",
            ]);
            $this->backend->script("cApp.layers.openPost('{$elementId}');");
            echo $this->backend->template('container_footer');
            return;
        }
        $lockId = $this->_acquireLock($target);
        if ($lockId < 1) {
            if ($lockId < 0) $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->_cleanupPreviews();

        // first preview: the current settings (edit) resp. the whole source (crop)
        $edit = $isEdit ? $target['edit'] : Magick::normalizeEdit([]);
        $preview = $this->_renderPreview($target, $edit);
        if ($preview === null) {
            $this->record->cancel($lockId);
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }

        $colDef = $target['colDef'];
        $label = $this->cms->textOrSnippet((string)(($colDef['label'] ?? '') ?: $colDef['name'] ?? ''));
        echo $this->backend->template('headline1', [
            '{text}' => $this->backend->template('icon', ['{name}' => $isEdit ? 'tune' : 'crop'])
                . ' ' . $this->backend->escape($this->cms->snippet($isEdit ? 'record.image_edit_title' : 'record.image_crop_title')
                . ': ' . $label),
        ]);

        $crop = null;
        if (!$isEdit) {
            $data = json_decode((string)($target['row']['data'] ?? ''), true);
            $crop = is_array($data['crop'] ?? null) ? Magick::normalizeCrop($data['crop']) : null;
        }
        $toolbar = $isEdit
            ? $this->backend->template('record.image_edit_toolbar', [
                '{gamma}' => (string)$edit['gamma'],
                '{width}' => $edit['width'] > 0 ? (string)$edit['width'] : '',
                '{height}' => $edit['height'] > 0 ? (string)$edit['height'] : '',
            ])
            : $this->backend->template('record.image_crop_toolbar', [
                '{width}' => (string)$target['crop']['width'],
                '{height}' => (string)$target['crop']['height'],
            ]);
        echo $this->backend->template('record.image_edit', [
            '{mode}' => $target['mode'],
            '{lock}' => (string)$lockId,
            '{id}' => (string)$target['id'],
            '{field_id}' => (string)$target['fieldId'],
            '{lang}' => $target['lang'] === null ? '' : (string)$target['lang'],
            '{state}' => $this->backend->escape(json_encode($isEdit ? $edit : ['crop' => $crop], JSON_THROW_ON_ERROR)),
            '{ratio}' => $isEdit ? '' : (string)($target['crop']['width'] / $target['crop']['height']),
            '{src}' => $this->backend->escape($preview['url']),
            '{result_width}' => (string)$preview['width'],
            '{result_height}' => (string)$preview['height'],
            '{toolbar}' => $toolbar,
            '{hint}' => $isEdit ? '{#record.image_edit_hint}' : '{#record.image_crop_hint}',
        ]);
        echo $this->backend->template('container_footer');
    }

    /**
     * Ajax entry point (ajax_image_edit.php).
     *
     *     image    GET   the temporary preview (image data)
     *     preview  POST  edit (JSON) -> new preview, response JSON
     *     save     POST  edit (JSON) resp. crop (JSON) -> replace the file / store the
     *                    section, generate the thumbnails, response JSON
     *     cancel   POST  remove the temporary preview
     */
    public function ajax(): void
    {
        $function = (string)($_REQUEST['function'] ?? '');
        if ($function === 'image') {
            $this->_sendPreview();
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->backend->requirePost()) {
            echo json_encode(['ok' => false, 'error' => $this->cms->snippet('error_access_denied')]);
            return;
        }
        $lockId = (int)($_POST['lock'] ?? 0);
        if ($function === 'cancel') {
            // release the lock in any case, also if the file has gone meanwhile
            if ($target = $this->_load(false)) @unlink($this->_previewFile($target));
            $this->_releaseLock($lockId, (int)($_REQUEST['id'] ?? 0));
            echo json_encode(['ok' => true]);
            return;
        }
        $target = $this->_load(true);
        if ($target === null) {
            echo json_encode(['ok' => false, 'error' => strip_tags($this->cms->errorMsg())]);
            return;
        }
        // still our lock? (someone else may have lifted it in the input mask, or it is
        // outdated) - every step renews it
        if (!$this->record->lockHeld($lockId, $target['id'])) {
            echo json_encode(['ok' => false, 'error' => $this->cms->snippet('record.error_image_edit_lock_lost')]);
            return;
        }
        $input = json_decode((string)($_POST['edit'] ?? ''), true);
        $input = is_array($input) ? $input : [];

        switch ($function) {
            case 'preview':
                if ($target['mode'] !== 'edit') break;
                $preview = $this->_renderPreview($target, Magick::normalizeEdit($input));
                if ($preview === null) {
                    echo json_encode(['ok' => false, 'error' => strip_tags($this->cms->errorMsg())]);
                    return;
                }
                echo json_encode(['ok' => true] + $preview);
                return;

            case 'save':
                $ok = $target['mode'] === 'edit'
                    ? $this->_saveEdit($target, Magick::normalizeEdit($input))
                    : $this->_saveCrop($target, is_array($input['crop'] ?? null) ? Magick::normalizeCrop($input['crop']) : null);
                @unlink($this->_previewFile($target));
                if (!$ok) {
                    echo json_encode(['ok' => false, 'error' => strip_tags($this->cms->errorMsg())]);
                    return;
                }
                $this->_releaseLock($lockId, $target['id']);
                echo json_encode(['ok' => true, 'message' => $this->cms->snippet('record.message_image_saved')]);
                return;
        }
        echo json_encode(['ok' => false, 'error' => 'unknown function']);
    }

    // ====================
    // Loading and checks

    /**
     * Record, field and file of the request with all checks: read right (write right
     * with $write), file field of the area, editing possible. The edit lock is checked
     * by the callers (open(): _acquireLock(), ajax(): lockHeld()).
     *
     * @return array|null  ['id', 'fieldId', 'lang', 'mode', 'colDef', 'row', 'magick',
     *                      'source' (absolute path to calculate from), 'mime', 'extension',
     *                      'edit' (stored settings), 'crop' (_cropTarget())]
     *                     null on error (cms->error)
     */
    private function _load(bool $write): ?array
    {
        $id = (int)($_REQUEST['id'] ?? 0);
        $fieldId = (int)($_REQUEST['field_id'] ?? 0);
        $lang = trim((string)($_REQUEST['lang'] ?? ''));
        $lang = ($lang === '' || !ctype_digit($lang)) ? null : (int)$lang;
        if ($id < 1 || $fieldId < 1 || !$data = $this->record->load($id)) {
            $this->cms->error($this->cms->snippet('record.error_image_edit_unsupported'));
            return null;
        }
        $areaId = (int)$data['area_id'];
        $access = $this->backend->access();
        $ownerId = isset($data['owner_id']) ? (int)$data['owner_id'] : null;
        if (!empty($data['tmp']) || !$access->areaRead($areaId) || ($write && !$access->areaWrite($areaId, $ownerId))) {
            $this->cms->error($this->cms->snippet('error_access_denied'));
            return null;
        }
        $colDef = null;
        foreach ($this->record->virtualSchema($areaId, false) as $name => $def) {
            if ((int)($def['field_id'] ?? 0) === $fieldId && ($def['type'] ?? '') === 'file') {
                $colDef = $def + ['name' => (string)$name];
                break;
            }
        }
        $row = $colDef ? $this->record->fileRow($id, $fieldId, $lang) : null;
        $mode = ($colDef && $row) ? $this->_mode($colDef, $row, $id, $lang) : null;
        $magick = $this->record->magickProcessor();
        if ($mode === null || $magick === null || empty($row['size'])) {
            $this->cms->error($this->cms->snippet('record.error_image_edit_unsupported'));
            return null;
        }

        $mediaDir = (string)$this->config->get('paths.media_dir');
        $target = [
            'id' => $id, 'fieldId' => $fieldId, 'lang' => $lang, 'mode' => $mode, 'data' => $data,
            'colDef' => $colDef, 'row' => $row, 'magick' => $magick,
            'edit' => Magick::normalizeEdit([]), 'crop' => null,
        ];
        if ($mode === 'edit') {
            // calculate from the kept original, if the stored settings belong to it
            $target['mime'] = (string)$row['mime_type'];
            $target['extension'] = (string)$row['extension'];
            $target['source'] = $mediaDir . '/' . $this->cms->mediaPath($fieldId, $id, $lang, $target['extension']);
            $fileData = json_decode((string)($row['data'] ?? ''), true);
            $original = $this->_originalFile($fieldId, $id, $lang, $target['extension']);
            if (is_array($fileData['edit'] ?? null) && is_file($original)) {
                $target['source'] = $original;
                $target['edit'] = Magick::normalizeEdit($fileData['edit']);
            }
        }
        else {
            $target['crop'] = $this->_cropTarget($colDef, $id, $lang);
            $parent = $target['crop']['parent'];
            $target['mime'] = (string)$parent['mime_type'];
            $target['extension'] = (string)$parent['extension'];
            $target['source'] = $mediaDir . '/' . $this->cms->mediaPath(
                (int)$target['crop']['parent_field_id'], $id, $lang, $target['extension']);
        }
        if (!is_file($target['source'])) {
            $this->cms->error($this->cms->snippet('error_file_not_found'));
            return null;
        }
        return $target;
    }

    // ====================
    // Edit lock

    /**
     * Lock the record for the time of the editing - like the input mask, but
     * without a working copy (Record::lockOnly()). Someone else's lock: notice with
     * the button "Lift lock" (request unlock=1, the other person can then no longer
     * save, as in the input mask). An input mask of one's own still open: error.
     *
     * @return int  id of the lock; 0 = notice shown, -1 = error (cms->error)
     */
    private function _acquireLock(array $target): int
    {
        $data = $target['data'];
        if (empty($_REQUEST['unlock']) && ($lock = $this->service->lockInfo($data))) {
            $text = strtr($this->cms->snippet('record.alert_record_locked'), [
                '{admin_name}' => $this->backend->teaser('admin', $lock['admin_id']),
                '{changed_at}' => $this->cms->_formatDate($this->cms->utcToUserTZ(substr($lock['changed_at'], 0, 19))),
            ]);
            echo $this->backend->template('record.image_edit_locked', [
                '{text}' => $text,
                '{script}' => 'cApp.layers.replacePost(' . $this->backend->jsString('Record/record_image_edit.php?id='
                    . $target['id'] . '&field_id=' . $target['fieldId'] . '&lang=' . ($target['lang'] ?? '') . '&unlock=1') . ')',
            ]);
            return 0;
        }
        // an input mask of one's own (working copy with working state) is not taken away
        $outdatedAt = gmdate('Y-m-d H:i:s', time() - (int)$this->config->get('admin.edit_lock_sec'));
        $this->db->query(
            'SELECT 1 FROM `record` `r` WHERE `r`.`tmp` = 1 AND `r`.`deleted` = 0 AND `r`.`origin_id` = ?'
            . ' AND `r`.`changed_by` = ? AND `r`.`changed_at` >= ?'
            . ' AND EXISTS (SELECT 1 FROM `record_cache` `c` WHERE `c`.`record_id` = `r`.`record_id`) LIMIT 1',
            'iis', [$target['id'], (int)$this->session->get('admin_id'), $outdatedAt]
        );
        if ($this->db->num_rows > 0) {
            $this->cms->error($this->cms->snippet('record.error_image_edit_locked'));
            return -1;
        }
        // older locks of one's own image editing become invalid
        $lockId = (int)$this->record->lockOnly($data);
        if ($lockId < 1) return -1;
        // sign-out releases it (Record::cancelSession())
        $this->session->registerWorkingCopy($lockId);
        return $lockId;
    }

    /**
     * Release the lock (Save, Cancel) - only a lock row of this record; Record::cancel()
     * deletes only rows of the session admin.
     */
    private function _releaseLock(int $lockId, int $recordId): void
    {
        if ($lockId < 1 || $recordId < 1) return;
        $this->db->query('SELECT 1 FROM `record` WHERE `record_id` = ? AND `tmp` = 1 AND `origin_id` = ?',
            'ii', [$lockId, $recordId]);
        if ($this->db->num_rows > 0) $this->record->cancel($lockId);
    }

    // ====================
    // Preview

    /**
     * Calculate the preview from the source into the temporary file.
     *
     * @return array|null  ['url', 'width', 'height' (size of the result in full
     *                      resolution), 'preview_width', 'preview_height']
     */
    private function _renderPreview(array $target, array $edit): ?array
    {
        $file = $this->_previewFile($target);
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, (int)($this->config->get('permissions.dir') ?? 0775), true);
        /** @var Magick $magick */
        $magick = $target['magick'];
        $info = $magick->imageInfo($target['source'], $target['mime'], $target['extension']);
        $preview = $info === null ? null : $magick->renderEdit(
            $target['source'], $target['mime'], $target['extension'], $file,
            pathinfo($file, PATHINFO_EXTENSION), $edit, self::PREVIEW_MAX, 85, $error
        );
        if ($preview === null) {
            $this->cms->error($this->cms->snippet('record.error_image_edit_failed'));
            if (!empty($error)) $this->system->log('error', 'Image editing: ' . $error);
            return null;
        }
        [$width, $height] = $this->_resultSize($info, $edit);
        return [
            'url' => $this->config->get('paths.backend') . '/module.php?_path=Record/ajax_image_edit.php&function=image'
                . '&id=' . $target['id'] . '&field_id=' . $target['fieldId']
                . '&lang=' . ($target['lang'] === null ? '' : $target['lang']) . '&t=' . time() . mt_rand(100, 999),
            'width' => $width,
            'height' => $height,
            'preview_width' => $preview['width'],
            'preview_height' => $preview['height'],
        ];
    }

    /**
     * Size of the result in full resolution - like Magick::renderEdit() calculates it.
     *
     * @param array $info  Magick::imageInfo() of the source (aligned)
     * @return array       [width, height]
     */
    private function _resultSize(array $info, array $edit): array
    {
        $w = (int)$info['width'];
        $h = (int)$info['height'];
        if ($edit['rotate'] === 90 || $edit['rotate'] === 270) [$w, $h] = [$h, $w];
        if ($edit['crop'] !== null && ($box = Magick::cropBox($edit['crop'], $w, $h)) !== null) {
            [$w, $h] = [$box[2], $box[3]];
            if ($edit['width'] > 0 && $edit['height'] > 0) {
                [$w, $h] = [$edit['width'], $edit['height']];
            }
            elseif ($edit['width'] > 0) {
                [$w, $h] = [$edit['width'], max(1, (int)round($h * $edit['width'] / $w))];
            }
            elseif ($edit['height'] > 0) {
                [$w, $h] = [max(1, (int)round($w * $edit['height'] / $h)), $edit['height']];
            }
        }
        return [$w, $h];
    }

    /**
     * Temporary preview of this user for this file - under paths.tmp_dir (not
     * reachable from the web), the name derived from the session: another session
     * neither finds nor overwrites it.
     */
    private function _previewFile(array $target): string
    {
        $key = hash_hmac('sha256', $target['id'] . '|' . $target['fieldId'] . '|' . ($target['lang'] ?? ''),
            $this->session->csrfToken() . '|' . (int)$this->session->get('admin_id'));
        $extension = in_array(strtolower((string)$target['extension']), ['jpg', 'jpeg'], true) ? 'jpg' : 'png';
        return $this->config->get('paths.tmp_dir') . '/image_edit/' . substr($key, 0, 40) . '.' . $extension;
    }

    /**
     * Deliver the temporary preview (GET, read right on the record).
     */
    private function _sendPreview(): void
    {
        $target = $this->_load(false);
        $file = $target ? $this->_previewFile($target) : '';
        if ($file === '' || !is_file($file)) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . (str_ends_with($file, '.jpg') ? 'image/jpeg' : 'image/png'));
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
    }

    /** Remove forgotten previews (layer closed without "Cancel") */
    private function _cleanupPreviews(): void
    {
        $dir = $this->config->get('paths.tmp_dir') . '/image_edit';
        foreach (glob($dir . '/*.{jpg,png}', GLOB_BRACE) ?: [] as $file) {
            if (filemtime($file) < time() - self::PREVIEW_MAX_AGE) @unlink($file);
        }
    }

    // ====================
    // Saving

    /**
     * Kept original of an edited upload file (outside the document root).
     */
    private function _originalFile(int $fieldId, int $recordId, ?int $lang, string $extension): string
    {
        return $this->record->imageOriginalPath($fieldId, $recordId, $lang, $extension);
    }

    /**
     * Upload field: calculate the file anew from the original with $edit, store the
     * settings, generate the thumbnails.
     */
    private function _saveEdit(array $target, array $edit): bool
    {
        $id = $target['id'];
        $fieldId = $target['fieldId'];
        $lang = $target['lang'];
        $extension = $target['extension'];
        $mediaFile = $this->config->get('paths.media_dir') . '/' . $this->cms->mediaPath($fieldId, $id, $lang, $extension);
        $original = $this->_originalFile($fieldId, $id, $lang, $extension);
        $fileData = json_decode((string)($target['row']['data'] ?? ''), true);
        $fileData = is_array($fileData) ? $fileData : [];
        $edited = is_array($fileData['edit'] ?? null) && $target['source'] === $original;
        $unchanged = $edit === Magick::normalizeEdit([]);

        if ($unchanged && !$edited) return true;   // nothing to do
        /** @var Magick $magick */
        $magick = $target['magick'];

        if (!$edited) {
            // first editing (or after a new upload): keep the current file as original
            if (!is_dir(dirname($original))) @mkdir(dirname($original), (int)($this->config->get('permissions.dir') ?? 0775), true);
            if (!@copy($mediaFile, $original)) {
                $this->cms->error($this->cms->snippet('record.error_image_edit_failed'));
                return false;
            }
        }

        if ($unchanged) {
            // all settings undone: the original comes back
            $tmpFile = $mediaFile . '.' . $this->crypt->randomKey(8) . '.tmp';
            if (!@copy($original, $tmpFile)) {
                $this->cms->error($this->cms->snippet('record.error_image_edit_failed'));
                return false;
            }
            unset($fileData['edit']);
        }
        else {
            $info = $magick->imageInfo($original, $target['mime'], $extension);
            $tmpFile = dirname($mediaFile) . '/.edit-' . $this->crypt->randomKey(8) . '.' . $extension;
            $result = $info === null ? null : $magick->renderEdit($original, $target['mime'], $extension, $tmpFile,
                $extension, $edit, 0, (int)($info['quality'] ?? 0), $error);
            if ($result === null) {
                $this->cms->error($this->cms->snippet('record.error_image_edit_failed'));
                if (!empty($error)) $this->system->log('error', 'Image editing: ' . $error);
                return false;
            }
            $fileData['edit'] = $edit;
        }
        @chmod($tmpFile, (int)($this->config->get('permissions.file') ?? 0664));
        if (!@rename($tmpFile, $mediaFile)) {
            @unlink($tmpFile);
            $this->cms->error($this->cms->snippet('record.error_image_edit_failed'));
            return false;
        }
        clearstatcache(true, $mediaFile);
        if ($unchanged) @unlink($original);

        $info = $magick->imageInfo($mediaFile, $target['mime'], $extension);
        $this->db->query(
            'UPDATE `data_fil` SET `size` = ?, `width` = ?, `height` = ?, `data` = ?, `version` = `version` + 1'
            . ' WHERE `record_id` = ? AND `field_id` = ? AND `file_language_id` <=> ?',
            'iiisiii', [
                (int)filesize($mediaFile), (int)($info['width'] ?? 0), (int)($info['height'] ?? 0),
                json_encode((object)$fileData, JSON_UNESCAPED_UNICODE), $id, $fieldId, $lang,
            ]
        );
        return $this->_regenerate($target, $fieldId);
    }

    /**
     * Thumbnail field: store the section (null = automatic again) in all rows of the
     * thumbnail and generate it anew.
     */
    private function _saveCrop(array $target, ?array $crop): bool
    {
        $fileData = json_decode((string)($target['row']['data'] ?? ''), true);
        $fileData = is_array($fileData) ? $fileData : [];
        if ($crop === null) unset($fileData['crop']);
        else $fileData['crop'] = $crop;
        $this->db->query(
            'UPDATE `data_fil` SET `data` = ? WHERE `record_id` = ? AND `field_id` = ? AND `file_language_id` <=> ?',
            'siii', [json_encode((object)$fileData, JSON_UNESCAPED_UNICODE), $target['id'], $target['fieldId'], $target['lang']]
        );
        // the thumbnail chain starts at the upload field
        $rootId = (int)$target['crop']['parent_field_id'];
        $schemaVirtual = $this->record->virtualSchema((int)$this->record->load($target['id'])['area_id'], false);
        for ($i = 0; $i < 20; $i++) {
            $parent = null;
            foreach ($schemaVirtual as $def) {
                if ((int)($def['field_id'] ?? 0) === $rootId) $parent = $def;
            }
            if ($parent === null || ($parent['display_type'] ?? '') !== 'thumbnail') break;
            $rootId = (int)($parent['parent_field_id'] ?? 0);
        }
        return $this->_regenerate($target, $rootId);
    }

    /**
     * Generate the thumbnails of a source field anew (with the stored sections) and
     * discard the content cache.
     */
    private function _regenerate(array $target, int $sourceFieldId): bool
    {
        $data = $this->record->load($target['id']);
        $schemaVirtual = $this->record->virtualSchema((int)$data['area_id'], false);
        try {
            $this->record->regenerateThumbnails($target['id'], $schemaVirtual, $sourceFieldId, $target['lang']);
        }
        catch (\Throwable $e) {
            $this->cms->error($this->cms->snippet('record.error_image_edit_failed') . ' ' . strip_tags($e->getMessage()));
            return false;
        }
        $this->db->query('DELETE FROM `record_cache` WHERE `record_id` = ?', 'i', [$target['id']]);
        return true;
    }
}
