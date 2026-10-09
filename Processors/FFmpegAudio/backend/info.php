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
 * Output selection values as plain text: the key ('mp3') is stored,
 * the snippet for it ('MP3') is shown.
 *
 * If infoAdd() does not resolve references with a leading '#' itself, switch here to
 * $this->cms->snippet('proc.ffmpegaudio.option_' . $value).
 */
$optionLabel = static function (string $value): string {
    if ($value === '') return '';
    return '#proc.ffmpegaudio.option_' . str_replace('-', '_', $value);
};

// Target
$this->backend->infoAdd($this->converter, 'details.container', $optionLabel((string) ($details['container'] ?? 'mp3')));
$this->backend->infoAdd($this->converter, 'details.codec', $optionLabel((string) ($details['codec'] ?? 'auto')));

// Without re-encoding everything else is ineffective: then only
// show the execution, so that the overview does not mislead.
$codec    = (string) ($details['codec'] ?? 'auto');
$reencode = ($codec !== 'copy');

if ($reencode) {
    // Quality: only show the value that is also effective in the chosen mode
    $qualityMode = (string) ($details['quality_mode'] ?? 'vbr');
    $this->backend->infoAdd($this->converter, 'details.quality_mode', $optionLabel($qualityMode));
    if ($qualityMode === 'bitrate') {
        $this->backend->infoAdd($this->converter, 'details.bitrate', (int) ($details['bitrate'] ?? 0) . ' kbit/s', ['notempty']);
    } else {
        $this->backend->infoAdd($this->converter, 'details.quality', 'VBR ' . (int) ($details['quality'] ?? 4));
    }

    // Audio signal
    $this->backend->infoAdd($this->converter, 'details.sample_rate', $optionLabel((string) ($details['sample_rate'] ?? 'keep')));
    $this->backend->infoAdd($this->converter, 'details.audio_channels', $optionLabel((string) ($details['audio_channels'] ?? 'keep')));

    // Loudness
    $this->backend->infoAdd($this->converter, 'details.normalisation', $optionLabel((string) ($details['normalisation'] ?? 'no')));
    $gain = (int) ($details['gain'] ?? 0);
    if ($gain !== 0) {
        $this->backend->infoAdd($this->converter, 'details.gain', ($gain > 0 ? '+' : '') . $gain . ' dB');
    }
}

// Time
$this->backend->infoAdd($this->converter, 'details.start_time', htmlspecialchars((string) ($details['start_time'] ?? '')), ['notempty', 'monospace']);
$this->backend->infoAdd($this->converter, 'details.duration', htmlspecialchars((string) ($details['duration'] ?? '')), ['notempty', 'monospace']);
if ($reencode) {
    $this->backend->infoAdd($this->converter, 'details.fade_in', htmlspecialchars((string) ($details['fade_in'] ?? '')), ['notempty']);
    $this->backend->infoAdd($this->converter, 'details.fade_out', htmlspecialchars((string) ($details['fade_out'] ?? '')), ['notempty']);
}

// Metadata
$this->backend->infoAdd($this->converter, 'details.strip_meta', ($details['strip_meta'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.keep_cover', ($details['keep_cover'] ?? 0) ? 'yes' : 'no');

// Execution
$this->backend->infoAdd($this->converter, 'details.threads', $details['threads'] ?? 0, ['notempty']);
$this->backend->infoAdd($this->converter, 'details.background_process', ($details['background_process'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.max_time_out', (int) ($details['max_time_out'] ?? 600) . ' s');

// Expert mode
$this->backend->infoAdd($this->converter, 'details.parameter1', htmlspecialchars((string) ($details['parameter1'] ?? '')), ['notempty', 'monospace']);
$this->backend->infoAdd($this->converter, 'details.parameter2', htmlspecialchars((string) ($details['parameter2'] ?? '')), ['notempty', 'monospace']);
