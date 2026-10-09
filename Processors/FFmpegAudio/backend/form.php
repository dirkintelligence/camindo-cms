<?php
/** 
 * Wir befinden uns innerhalb der Datei Core/Modules/Converter/converter_edit.php
 * @var Camindo\Core\Modules\Converter\Converter $this->converter
 * @var Camindo\Core\Config $this->config
 * @var Camindo\Core\cms $this->cms
 * @var Camindo\Core\Backend $this->backend
 * @var int $this->id
 * @var array $this->data
 * @var string $this->function
 * @var string $this->processorName
 */

// paste processor specific form fields into the form
$prefix = strtolower($this->processorName);
$tplProcessorForm = $this->backend->template("proc.{$prefix}.edit_form");
$this->backend->formReplace('{processor_fields}', $tplProcessorForm);

$details = (array)($this->data['details'] ?? []);

// Die erlaubten Werte stehen als Konstanten in der Prozessor-Klasse. So bleiben
// Formular und Engine zwangsläufig beieinander, auch wenn später ein Codec
// dazukommt oder wegfällt.
$proc = \Camindo\Core\Processors\FFmpegAudio\FFmpegAudio::class;

/**
 * Baut aus einer Werteliste die Optionen für ein Auswahlfeld.
 * Die Beschriftung kommt jeweils aus dem Snippet proc.ffmpegaudio.option_{wert}.
 */
$options = function (array $values) use ($prefix): array {
    $result = [];
    foreach ($values as $value) {
        $result[$value] = $this->cms->snippet("proc.{$prefix}.option_" . str_replace('-', '_', $value));
    }
    return $result;
};

// container
$this->backend->formField($this->converter, [
    'name' => 'details.container',
    'value' => $details['container'] ?? 'mp3',
    'id' => $this->id,
    'options' => $options(array_keys($proc::CONTAINERS))
]);

// codec
// Die Liste enthält bewusst alle Codecs. Passt die Auswahl nicht zum
// Container, biegt der Prozessor sie beim Speichern auf den Standard-Codec
// des Containers zurecht.
$this->backend->formField($this->converter, [
    'name' => 'details.codec',
    'value' => $details['codec'] ?? 'auto',
    'id' => $this->id,
    'options' => $options(array_keys($proc::AUDIO_CODECS))
]);

// quality_mode
$this->backend->formField($this->converter, [
    'name' => 'details.quality_mode',
    'value' => $details['quality_mode'] ?? 'vbr',
    'id' => $this->id,
    'options' => $options($proc::QUALITY_MODES)
]);

// quality (VBR-Stufe)
$this->backend->formField($this->converter, ['name' => 'details.quality', 'value' => $details['quality'] ?? '', 'id' => $this->id]);

// bitrate
$this->backend->formField($this->converter, ['name' => 'details.bitrate', 'value' => $details['bitrate'] ?? '', 'id' => $this->id]);

// sample_rate
$this->backend->formField($this->converter, [
    'name' => 'details.sample_rate',
    'value' => $details['sample_rate'] ?? 'keep',
    'id' => $this->id,
    'options' => $options(array_keys($proc::SAMPLE_RATES))
]);

// audio_channels
$this->backend->formField($this->converter, [
    'name' => 'details.audio_channels',
    'value' => $details['audio_channels'] ?? 'keep',
    'id' => $this->id,
    'options' => $options(array_keys($proc::AUDIO_CHANNELS))
]);

// normalisation
$this->backend->formField($this->converter, [
    'name' => 'details.normalisation',
    'value' => $details['normalisation'] ?? 'no',
    'id' => $this->id,
    'options' => $options(array_keys($proc::NORMALISATION))
]);

// gain
$this->backend->formField($this->converter, ['name' => 'details.gain', 'value' => $details['gain'] ?? '', 'id' => $this->id]);

// start_time
$this->backend->formField($this->converter, ['name' => 'details.start_time', 'value' => $details['start_time'] ?? '', 'id' => $this->id]);

// duration
$this->backend->formField($this->converter, ['name' => 'details.duration', 'value' => $details['duration'] ?? '', 'id' => $this->id]);

// fade_in
$this->backend->formField($this->converter, ['name' => 'details.fade_in', 'value' => $details['fade_in'] ?? '', 'id' => $this->id]);

// fade_out
$this->backend->formField($this->converter, ['name' => 'details.fade_out', 'value' => $details['fade_out'] ?? '', 'id' => $this->id]);

// strip_meta
$this->backend->formField($this->converter, [
    'name' => 'details.strip_meta',
    'value' => $details['strip_meta'] ?? '',
    'id' => $this->id,
    'options' => [1 => $this->cms->snippet("proc.{$prefix}.label_strip_meta")]
]);

// keep_cover
$this->backend->formField($this->converter, [
    'name' => 'details.keep_cover',
    'value' => $details['keep_cover'] ?? '',
    'id' => $this->id,
    'options' => [1 => $this->cms->snippet("proc.{$prefix}.label_keep_cover")]
]);

// threads
$this->backend->formField($this->converter, ['name' => 'details.threads', 'value' => $details['threads'] ?? '', 'id' => $this->id]);

// background_process
$this->backend->formField($this->converter, [
    'name' => 'details.background_process',
    'value' => $details['background_process'] ?? '',
    'id' => $this->id,
    'options' => [1 => $this->cms->snippet("proc.{$prefix}.label_background_process")]
]);

// max_time_out
$this->backend->formField($this->converter, ['name' => 'details.max_time_out', 'value' => $details['max_time_out'] ?? '', 'id' => $this->id]);


// Expertenmodus
$this->backend->formField($this->converter, ['name' => 'details.parameter1', 'value' => $details['parameter1'] ?? '', 'id' => $this->id]);
$this->backend->formField($this->converter, ['name' => 'details.parameter2', 'value' => $details['parameter2'] ?? '', 'id' => $this->id]);
