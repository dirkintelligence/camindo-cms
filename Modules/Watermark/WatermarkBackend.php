<?php
/**
 * Backend of the core module Watermark: check an image for the invisible
 * watermark of this installation (backend/index.php).
 *
 * The check runs Camindo\Core\Watermark::detect() with the key of this installation
 * (paths.watermark_key_file). A watermark embedded by another installation (another
 * key) is not found - the page says so. The uploaded image is only read from PHP's
 * temporary upload file and never stored.
 *
 * Instance names are only shown for instances the user has access to; otherwise the
 * page only says that the code belongs to another instance of this installation.
 *
 * @see developer docs, chapter 07 (Media and background processes)
 */
namespace Camindo\Core\Modules\Watermark;

use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\System;
use Camindo\Core\Watermark;

class WatermarkBackend {

    /** Largest image accepted for a check (bytes) */
    public const MAX_BYTES = 30 * 1024 * 1024;

    /** Accepted types (by content, not by name) */
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Orientation reported by the program => snippet */
    protected const ORIENTATIONS = [
        'none'                          => 'watermark.orientation_none',
        'mirrored horizontally'         => 'watermark.orientation_mirrored_h',
        'mirrored vertically'           => 'watermark.orientation_mirrored_v',
        'rotated 180°'                  => 'watermark.orientation_rotated_180',
        'transposed'                    => 'watermark.orientation_transposed',
        'rotated 90° clockwise'         => 'watermark.orientation_rotated_90_cw',
        'rotated 90° counter-clockwise' => 'watermark.orientation_rotated_90_ccw',
        'transverse'                    => 'watermark.orientation_transverse',
    ];

    public function __construct(
        private Backend $backend,
        private Cms $cms,
        private System $system,
        private Watermark $watermark,
    )
    {}

    /**
     * The check page: state of the watermark program, upload form and - after an
     * upload - the result.
     *
     * Request (POST, multipart): function = check, image ($_FILES)
     */
    public function index(): void
    {
        $this->backend->refreshable();
        $this->backend->setNaviLabel('#watermark.module_label');
        echo $this->backend->template('container_header');
        echo $this->backend->headline('#watermark.headline_check');
        echo $this->backend->template('remark_block', ['{text}' => '{#watermark.note_check}']);

        $watermark = $this->watermark;
        if (!$watermark->available($problem)) {
            $note = $watermark->binary() === '' ? 'note_not_configured' : 'note_not_usable';
            echo $this->backend->template('alert_warning', ['{text}' => '{#watermark.' . $note . '}']);
            echo $this->backend->template('container_footer');
            return;
        }

        if (($_REQUEST['function'] ?? '') === 'check' && $this->backend->requirePost()) {
            echo $this->_check($watermark);
        }
        echo $this->backend->template('watermark.check_form', [
            '{label}' => $this->backend->template('input_label_tt', [
                '{id}'      => 'c9-watermark-image',
                '{label}'   => '{#watermark.label_image}',
                '{tooltip}' => '{#watermark.note_image}',
            ]),
        ]);
        echo $this->backend->template('container_footer');
    }

    /**
     * Check the uploaded image and render the result.
     */
    private function _check(Watermark $watermark): string
    {
        $upload = $_FILES['image'] ?? null;
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if (!is_array($upload) || $error === UPLOAD_ERR_NO_FILE) {
            return $this->_error('watermark.error_no_file');
        }
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int)$upload['size'] > self::MAX_BYTES) {
            return $this->_error('watermark.error_too_large');
        }
        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            return $this->_error('error_file_upload');
        }
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, self::MIME_TYPES, true)) {
            return $this->_error('watermark.error_type');
        }

        $result = $watermark->detect($tmp, $detectError);
        if ($result === null) {
            $this->system->log('error', 'Watermark check: ' . $detectError);
            return $this->_error('watermark.error_check');
        }
        return $this->_result($result, (string)($upload['name'] ?? ''));
    }

    /**
     * Result block: status as alert, details as rows.
     *
     * @param array  $result  Watermark::detect()
     * @param string $file    original file name (only shown)
     */
    private function _result(array $result, string $file): string
    {
        $status = (string)($result['status'] ?? 'none');
        $rows = '';
        $row = function (string $label, string $value) use (&$rows) {
            $rows .= $this->backend->template('watermark.result_row', ['{label}' => '{#' . $label . '}', '{value}' => $value]);
        };

        if ($status === 'found') {
            $alert = $this->backend->template('alert_success', ['{text}' => '{#watermark.status_found}']);
            if (isset($result['code'])) {
                $row('watermark.label_code', $this->backend->template('watermark.code', ['{code}' => htmlspecialchars((string)$result['code'])]));
                $row('watermark.label_instance', $this->_instances((array)($result['instances'] ?? [])));
            }
            else {
                // number embedded with -id outside camindo (no code)
                $row('watermark.label_number', htmlspecialchars((string)($result['id'] ?? '')));
            }
            $orientation = (string)($result['orientation'] ?? 'none');
            $rotation = (float)($result['rotation_deg'] ?? 0);
            if ($rotation != 0) {
                // free angle (found via the sync pattern), clockwise positive
                $row('watermark.label_orientation', htmlspecialchars(number_format(abs($rotation), 1)) . '° – {#watermark.orientation_free_' . ($rotation > 0 ? 'cw' : 'ccw') . '}');
            }
            elseif ($orientation !== 'none') {
                $row('watermark.label_orientation', '{#' . (self::ORIENTATIONS[$orientation] ?? 'watermark.orientation_none') . '}');
            }
            if (!empty($result['synced'])) {
                $row('watermark.label_synced', '{#watermark.synced}');
            }
            if (!empty($result['corrected_bits'])) {
                $row('watermark.label_corrected', (string)(int)$result['corrected_bits']);
            }
        }
        elseif ($status === 'present_unreadable') {
            $alert = $this->backend->template('alert_warning', ['{text}' => '{#watermark.status_unreadable}']);
        }
        else {
            $alert = $this->backend->template('alert_warning', ['{text}' => '{#watermark.status_none}']);
        }
        $row('watermark.label_confidence', htmlspecialchars(number_format((float)($result['confidence'] ?? 0), 1)));
        $row('watermark.label_size', (int)($result['width'] ?? 0) . ' × ' . (int)($result['height'] ?? 0) . ' px');

        return $this->backend->template('watermark.result', [
            '{file}'  => htmlspecialchars($file),
            '{alert}' => $alert,
            '{rows}'  => $rows,
        ]);
    }

    /**
     * Instances that use the code: names of the accessible ones, otherwise only a note.
     */
    private function _instances(array $instances): string
    {
        if ($instances === []) return '{#watermark.instance_unknown}';
        $access = $this->backend->access();
        $names = [];
        $hidden = 0;
        foreach ($instances as $instance) {
            if ($access->instance((int)$instance['instance_id'])) {
                $names[] = htmlspecialchars($this->cms->textOrSnippet((string)$instance['label']));
            }
            else {
                $hidden++;
            }
        }
        if ($hidden > 0) $names[] = '{#watermark.instance_other}';
        return implode('<br>', $names);
    }

    private function _error(string $snippet): string
    {
        return $this->backend->template('alert_danger', ['{text}' => '{#' . $snippet . '}']);
    }
}
