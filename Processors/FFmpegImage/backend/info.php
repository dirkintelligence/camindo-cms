<?php
/** 
 * We are inside the file Core/Modules/Converter/converter_edit.php
 * @var Camindo\Core\Modules\Converter\Converter $this->converter
 * @var Camindo\Core\Config $this->config
 * @var Camindo\Core\Backend $this->backend
 * @var int $id
 * @var array $data
 */

$details = (array) ($data['details'] ?? []);

/**
 * Output selection values as plain text: the key ('jpg') is stored,
 * the snippet for it ('JPEG') is shown.
 *
 * If infoAdd() does not resolve references with a leading '#' itself, switch here to
 * $this->cms->snippet('proc.ffmpegimage.option_' . $value) .
 */
$optionLabel = static function (string $value): string {
    if ($value === '') return '';
    return '#proc.ffmpegimage.option_' . str_replace('-', '_', $value);
};

// Ziel
$this->backend->infoAdd($this->converter, 'details.format', $optionLabel((string) ($details['format'] ?? 'jpg')));
$this->backend->infoAdd($this->converter, 'details.quality', (int) ($details['quality'] ?? 80) . ' %');

// Image selection: only show the value that is also effective in the chosen mode
$frameMode = (string) ($details['frame_mode'] ?? 'percent');
$this->backend->infoAdd($this->converter, 'details.frame_mode', $optionLabel($frameMode));
if ($frameMode === 'percent') {
    $this->backend->infoAdd($this->converter, 'details.frame_percent', (int) ($details['frame_percent'] ?? 10) . ' %');
} else {
    $this->backend->infoAdd($this->converter, 'details.frame_time', htmlspecialchars((string) ($details['frame_time'] ?? '')), ['notempty', 'monospace']);
    if ($frameMode === 'smart') {
        $this->backend->infoAdd($this->converter, 'details.scan_frames', (int) ($details['scan_frames'] ?? 100));
    }
}

// Abmessungen
$this->backend->infoAdd($this->converter, 'details.width', $details['width'] ?? 0);
$this->backend->infoAdd($this->converter, 'details.height', $details['height'] ?? 0);
$this->backend->infoAdd($this->converter, 'details.crop', ($details['crop'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.prevent_upscaling', ($details['prevent_upscaling'] ?? 0) ? 'yes' : 'no');

// Image editing
$this->backend->infoAdd($this->converter, 'details.deinterlace', $optionLabel((string) ($details['deinterlace'] ?? 'auto')));
$this->backend->infoAdd($this->converter, 'details.auto_rotation', ($details['auto_rotation'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.sharpen', ($details['sharpen'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.strip_meta', ($details['strip_meta'] ?? 0) ? 'yes' : 'no');

// Execution
$this->backend->infoAdd($this->converter, 'details.threads', $details['threads'] ?? 0, ['notempty']);
$this->backend->infoAdd($this->converter, 'details.background_process', ($details['background_process'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.max_time_out', (int) ($details['max_time_out'] ?? 120) . ' s');

// Expertenmodus
$this->backend->infoAdd($this->converter, 'details.parameter1', htmlspecialchars((string) ($details['parameter1'] ?? '')), ['notempty', 'monospace']);
$this->backend->infoAdd($this->converter, 'details.parameter2', htmlspecialchars((string) ($details['parameter2'] ?? '')), ['notempty', 'monospace']);
