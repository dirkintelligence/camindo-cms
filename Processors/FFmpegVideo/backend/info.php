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
 * Auswahlwerte als Klartext ausgeben: gespeichert ist der Schlüssel ('h264'),
 * angezeigt wird das Snippet dazu ('H.264 / AVC').
 *
 * Sollte infoAdd() Referenzen mit führendem '#' nicht selbst auflösen, hier auf
 * $this->cms->snippet('proc.ffmpegvideo.option_' . $value) umstellen.
 */
$optionLabel = static function (string $value): string {
    if ($value === '') return '';
    return '#proc.ffmpegvideo.option_' . str_replace('-', '_', $value);
};

// Ziel
$this->backend->infoAdd($this->converter, 'details.container', $optionLabel((string) ($details['container'] ?? 'mp4')));
$this->backend->infoAdd($this->converter, 'details.codec', $optionLabel((string) ($details['codec'] ?? 'h264')));

// Abmessungen
$this->backend->infoAdd($this->converter, 'details.width', $details['width'] ?? 0);
$this->backend->infoAdd($this->converter, 'details.height', $details['height'] ?? 0);
$this->backend->infoAdd($this->converter, 'details.crop', ($details['crop'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.prevent_upscaling', ($details['prevent_upscaling'] ?? 0) ? 'yes' : 'no');

// Qualität: nur den Wert zeigen, der im gewählten Modus auch wirkt
$qualityMode = (string) ($details['quality_mode'] ?? 'crf');
$this->backend->infoAdd($this->converter, 'details.quality_mode', $optionLabel($qualityMode));
if ($qualityMode === 'bitrate') {
    $this->backend->infoAdd($this->converter, 'details.bitrate', (int) ($details['bitrate'] ?? 0) . ' kbit/s', ['notempty']);
} else {
    $this->backend->infoAdd($this->converter, 'details.quality', 'CRF ' . (int) ($details['quality'] ?? 23));
}
$this->backend->infoAdd($this->converter, 'details.preset', $optionLabel((string) ($details['preset'] ?? 'medium')));

// Zeit und Bildrate
$this->backend->infoAdd($this->converter, 'details.max_fps', $details['max_fps'] ?? 0, ['notempty']);
$this->backend->infoAdd($this->converter, 'details.start_time', htmlspecialchars((string) ($details['start_time'] ?? '')), ['notempty', 'monospace']);
$this->backend->infoAdd($this->converter, 'details.duration', htmlspecialchars((string) ($details['duration'] ?? '')), ['notempty', 'monospace']);

// Kompatibilität
$this->backend->infoAdd($this->converter, 'details.faststart', ($details['faststart'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.compatible_pix_mode', ($details['compatible_pix_mode'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.deinterlace', $optionLabel((string) ($details['deinterlace'] ?? 'auto')));
$this->backend->infoAdd($this->converter, 'details.auto_rotation', ($details['auto_rotation'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.strip_meta', ($details['strip_meta'] ?? 0) ? 'yes' : 'no');

// Ton
$audioCodec = (string) ($details['audio_codec'] ?? 'aac');
$this->backend->infoAdd($this->converter, 'details.audio_codec', $optionLabel($audioCodec));
if ($audioCodec !== 'mute' && $audioCodec !== 'copy') {
    $this->backend->infoAdd($this->converter, 'details.audio_bitrate', (int) ($details['audio_bitrate'] ?? 0) . ' kbit/s', ['notempty']);
    $this->backend->infoAdd($this->converter, 'details.audio_channels', $optionLabel((string) ($details['audio_channels'] ?? 'keep')));
    $this->backend->infoAdd($this->converter, 'details.audio_normalisation', ($details['audio_normalisation'] ?? 0) ? 'yes' : 'no');
}

// Ausführung
$this->backend->infoAdd($this->converter, 'details.threads', $details['threads'] ?? 0, ['notempty']);
$this->backend->infoAdd($this->converter, 'details.background_process', ($details['background_process'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.max_time_out', (int) ($details['max_time_out'] ?? 3600) . ' s');

// Expertenmodus
$this->backend->infoAdd($this->converter, 'details.parameter1', htmlspecialchars((string) ($details['parameter1'] ?? '')), ['notempty', 'monospace']);
$this->backend->infoAdd($this->converter, 'details.parameter2', htmlspecialchars((string) ($details['parameter2'] ?? '')), ['notempty', 'monospace']);
