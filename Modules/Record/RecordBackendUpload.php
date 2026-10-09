<?php
/**
 * Remove and select files in the input mask (extension for RecordBackend).
 *
 * Entry points (scripts in backend/):
 *
 *     ajax_file_remove.php  -> fileRemove()        x button next to the preview
 *     ajax_file_select.php  -> fileServerSelect()  choose a file from the server directory
 *
 * WHY THIS IS MORE INVOLVED THAN IT LOOKS
 * --------------------
 * Work is never done on the productive record, but on a working copy (tmp=1,
 * see TemporaryTrait). Removing a file therefore means three things:
 *
 *  1. delete the physical file of the working copy
 *  2. delete its `data_fil` entry - if it stayed, promoteTmpFiles() would
 *     rewrite it to the productive record on save, and the preview link
 *     would point to a file that no longer exists
 *  3. set a deletion marker in the working state (record_cache) of the working copy.
 *     Only this lets promoteTmpFiles() recognize on save that the file attached to the
 *     PRODUCTIVE record must also be dropped. A mere absence of the entry
 *     could not be told apart from "nothing changed".
 *
 * The entry keeps the format ['file', [...]] - promoteTmpFiles() checks the
 * type at position 0 and would overlook a marker of a different shape.
 *
 * THUMBNAILS DEPEND ON IT
 * --------------------
 * Thumbnail fields derived from an upload field (type 'file', display_type
 * 'thumbnail', parent_field_id points to the upload field) lose their basis
 * together with their source. _removeFiles() therefore removes them recursively as well.
 * The list $handled prevents an endless loop in case two fields reference each other
 * as parent_field_id.
 *
 * PERMISSION CHECK
 * --------------------
 * _markFileDeleted() checks three things before it deletes anything: the record must be a
 * working copy, the field must be an upload field, and it must belong to the area
 * of this working copy. Without the third check, a foreign file could be deleted
 * via a smuggled field_id.
 *
 * @see Camindo\Core\TemporaryTrait                    working copies, promoteTmpFiles()
 * @see Camindo\Core\VirtualFieldsTrait                file storage and thumbnails
 * @see Camindo\Core\Modules\Record\RecordBackendEdit  the input mask
 * @see Camindo\Core\Modules\Record\RecordBackendServerFile  the file browser
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\DirectoryHelpers;

class RecordBackendUpload extends RecordBackend {

    /**
     * Removes an uploaded file from a temporary working copy
     * (x button next to the preview link in the input mask).
     *
     * The work is done by VirtualFieldsTrait::markFileDeleted() - the same routine
     * is used by CMS:USER_INPUT for the parameter 'removefiles' (RecordUser).
     *
     * @param int $tmpId            Id of the temporary working copy
     * @param int $fieldId          Id of the upload field
     * @param int|null $languageId  Language of the file for multilingual upload fields
     * @return bool                 true on success
     */
    private function _markFileDeleted(int $tmpId, int $fieldId, ?int $languageId = null): bool
    {
        return $this->record->markFileDeleted($tmpId, $fieldId, $languageId);
    }

    /**
     * Ajax: remove a file from an upload field (x button next to the preview).
     *
     * The work is done by _markFileDeleted(), including the checks. The removal only
     * takes effect when the record is saved - until then it can be reverted by
     * cancelling the edit.
     *
     * Request: id (working copy), field_id
     */
    public function fileRemove():void
    {
        // Backend action "remove file" (x button) - writes, so POST only
        // (developer docs, chapter 14 (Security: web))
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }
        $tmpId    = (int)$_REQUEST['id'];           // Id of the working copy
        $fieldId  = (int)$_REQUEST['field_id'];     // field_id of the upload field
        $lang     = $this->_langParam();             // language (multilingual field)

        if (!$this->_markFileDeleted($tmpId, $fieldId, $lang)) {
            $this->errors();
        }
    }

    /**
     * Ajax: take over a file from the server directory for an upload field.
     *
     * The counterpart to the file browser (RecordBackendServerFile). Unlike an
     * upload, the file is NOT copied here - only its path is taken over, carried by a
     * hidden field in the form; it is processed only on save.
     *
     * A file already in the field is first marked as deleted, otherwise
     * two files would remain for one field.
     *
     * The path goes into the template escaped; only the sanitized
     * file name without the directory part is displayed.
     *
     * In addition, the switch "delete after successful takeover"
     * (data[{name}][serverfile_delete]): the file in var/upload is only deleted
     * once the record is saved (VirtualFieldsTrait::promoteTmpFiles()) -
     * if the edit is cancelled, it remains.
     *
     * The hidden field is named data[{name}]... - for embedded content
     * (working copy on a 'link' field of another working copy, LinkTrait::
     * isEmbedded()) embed[{id}][{name}]..., where RecordBackendEdit::_embedSave() expects
     * the input of the content.
     *
     * Request: id (working copy), field_id, filename (relative to paths.upload_dir)
     */
    public function fileServerSelect():void
    {
        // Marks the previous file as deleted - POST only (developer docs, chapter 14 (Security: web))
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }
        $tmpId    = (int)$_REQUEST['id'];           // Id of the working copy
        $fieldId  = (int)$_REQUEST['field_id'];     // field_id of the upload field
        $lang     = $this->_langParam();             // language (multilingual field)
        $filename = (string)$_REQUEST['filename'];  // file name incl. relative path from paths.upoad_dir

        // Mark any stored file as deleted
        $this->_markFileDeleted($tmpId, $fieldId, $lang);

        // Create hidden field with path: data[{name}][serverfile], or for
        // multilingual fields data[{name}][{language_id}][serverfile]
        $fieldData = $this->record->fieldData($fieldId);
        $name = $fieldData['name'];
        $langKey = ($lang !== null && $this->cms->isMultilingualField($fieldData)) ? '[' . $lang . ']' : '';
        
        $basename = DirectoryHelpers::sanitizeFilename($filename);
        echo $this->backend->template('record.file_server_preview_container', [
            '{file}' => htmlspecialchars($filename),
            '{text}' => htmlspecialchars($basename),
            '{name}' => $name,
            '{lang_key}' => $langKey,
            '{var}' => $this->record->isEmbedded($tmpId) ? 'embed[' . $tmpId . ']' : 'data',
            '{switch_id}' => 'c9-serverfile-delete-' . $tmpId . '-' . $fieldId . ($lang !== null ? '-' . $lang : ''),
        ]);
    }

    /**
     * Request parameter lang: language of the file for multilingual upload fields,
     * null if empty (single-language field).
     */
    private function _langParam(): ?int
    {
        $lang = trim((string)($_REQUEST['lang'] ?? ''));
        return ($lang === '' || !ctype_digit($lang)) ? null : (int)$lang;
    }
}