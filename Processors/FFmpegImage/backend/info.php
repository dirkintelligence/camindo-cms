<?php
/** 
 * Wir befinden uns innerhalb der Datei Core/Modules/Converter/converter_edit.php
 * @var Camindo\Core\Modules\Converter\Converter $this->converter
 * @var Camindo\Core\Config $this->config
 * @var Camindo\Core\Backend $this->backend
 * @var int $id
 * @var array $data
 */

$details = (array) ($data['details'] ?? []);

/**
 * Auswahlwerte als Klartext ausgeben: gespeichert ist der Schlüssel ('jpg'),
 * angezeigt wird das Snippet dazu ('JPEG').
 *
 * Sollte infoAdd() Referenzen mit führendem '#' nicht selbst auflösen, hier auf
 * $this->cms->snippet('proc.ffmpegimage.option_' . $value) umstellen.
 */
$optionLabel = static function (string $value): string {
    if ($value === '') return '';
    return '#proc.ffmpegimage.option_' . str_replace('-', '_', $value);
};

// Ziel
$this->backend->infoAdd($this->converter, 'details.format', $optionLabel((string) ($details['format'] ?? 'jpg')));
$this->backend->infoAdd($this->converter, 'details.quality', (int) ($details['quality'] ?? 80) . ' %');

// Bildauswahl: nur den Wert zeigen, der im gewählten Modus auch wirkt
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

// Bildbearbeitung
$this->backend->infoAdd($this->converter, 'details.deinterlace', $optionLabel((string) ($details['deinterlace'] ?? 'auto')));
$this->backend->infoAdd($this->converter, 'details.auto_rotation', ($details['auto_rotation'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.sharpen', ($details['sharpen'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.strip_meta', ($details['strip_meta'] ?? 0) ? 'yes' : 'no');

// Ausführung
$this->backend->infoAdd($this->converter, 'details.threads', $details['threads'] ?? 0, ['notempty']);
$this->backend->infoAdd($this->converter, 'details.background_process', ($details['background_process'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.max_time_out', (int) ($details['max_time_out'] ?? 120) . ' s');

// Expertenmodus
$this->backend->infoAdd($this->converter, 'details.parameter1', htmlspecialchars((string) ($details['parameter1'] ?? '')), ['notempty', 'monospace']);
$this->backend->infoAdd($this->converter, 'details.parameter2', htmlspecialchars((string) ($details['parameter2'] ?? '')), ['notempty', 'monospace']);
