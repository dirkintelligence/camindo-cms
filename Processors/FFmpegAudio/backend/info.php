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
 * Auswahlwerte als Klartext ausgeben: gespeichert ist der Schlüssel ('mp3'),
 * angezeigt wird das Snippet dazu ('MP3').
 *
 * Sollte infoAdd() Referenzen mit führendem '#' nicht selbst auflösen, hier auf
 * $this->cms->snippet('proc.ffmpegaudio.option_' . $value) umstellen.
 */
$optionLabel = static function (string $value): string {
    if ($value === '') return '';
    return '#proc.ffmpegaudio.option_' . str_replace('-', '_', $value);
};

// Ziel
$this->backend->infoAdd($this->converter, 'details.container', $optionLabel((string) ($details['container'] ?? 'mp3')));
$this->backend->infoAdd($this->converter, 'details.codec', $optionLabel((string) ($details['codec'] ?? 'auto')));

// Ohne Neukodierung ist alles Weitere wirkungslos: dann nur noch die
// Ausführung anzeigen, damit die Übersicht nicht in die Irre führt.
$codec    = (string) ($details['codec'] ?? 'auto');
$reencode = ($codec !== 'copy');

if ($reencode) {
    // Qualität: nur den Wert zeigen, der im gewählten Modus auch wirkt
    $qualityMode = (string) ($details['quality_mode'] ?? 'vbr');
    $this->backend->infoAdd($this->converter, 'details.quality_mode', $optionLabel($qualityMode));
    if ($qualityMode === 'bitrate') {
        $this->backend->infoAdd($this->converter, 'details.bitrate', (int) ($details['bitrate'] ?? 0) . ' kbit/s', ['notempty']);
    } else {
        $this->backend->infoAdd($this->converter, 'details.quality', 'VBR ' . (int) ($details['quality'] ?? 4));
    }

    // Tonsignal
    $this->backend->infoAdd($this->converter, 'details.sample_rate', $optionLabel((string) ($details['sample_rate'] ?? 'keep')));
    $this->backend->infoAdd($this->converter, 'details.audio_channels', $optionLabel((string) ($details['audio_channels'] ?? 'keep')));

    // Lautheit
    $this->backend->infoAdd($this->converter, 'details.normalisation', $optionLabel((string) ($details['normalisation'] ?? 'no')));
    $gain = (int) ($details['gain'] ?? 0);
    if ($gain !== 0) {
        $this->backend->infoAdd($this->converter, 'details.gain', ($gain > 0 ? '+' : '') . $gain . ' dB');
    }
}

// Zeit
$this->backend->infoAdd($this->converter, 'details.start_time', htmlspecialchars((string) ($details['start_time'] ?? '')), ['notempty', 'monospace']);
$this->backend->infoAdd($this->converter, 'details.duration', htmlspecialchars((string) ($details['duration'] ?? '')), ['notempty', 'monospace']);
if ($reencode) {
    $this->backend->infoAdd($this->converter, 'details.fade_in', htmlspecialchars((string) ($details['fade_in'] ?? '')), ['notempty']);
    $this->backend->infoAdd($this->converter, 'details.fade_out', htmlspecialchars((string) ($details['fade_out'] ?? '')), ['notempty']);
}

// Metadaten
$this->backend->infoAdd($this->converter, 'details.strip_meta', ($details['strip_meta'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.keep_cover', ($details['keep_cover'] ?? 0) ? 'yes' : 'no');

// Ausführung
$this->backend->infoAdd($this->converter, 'details.threads', $details['threads'] ?? 0, ['notempty']);
$this->backend->infoAdd($this->converter, 'details.background_process', ($details['background_process'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.max_time_out', (int) ($details['max_time_out'] ?? 600) . ' s');

// Expertenmodus
$this->backend->infoAdd($this->converter, 'details.parameter1', htmlspecialchars((string) ($details['parameter1'] ?? '')), ['notempty', 'monospace']);
$this->backend->infoAdd($this->converter, 'details.parameter2', htmlspecialchars((string) ($details['parameter2'] ?? '')), ['notempty', 'monospace']);
