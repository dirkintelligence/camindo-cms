<?php
/** 
 * We are inside the file Core/Modules/Converter/converter_edit.php
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

// The allowed values are constants in the processor class. This keeps
// form and engine necessarily together, even if a codec is added
// or removed later.
$proc = \Camindo\Core\Processors\FFmpegVideo\FFmpegVideo::class;

/**
 * Builds the options for a select field from a list of values.
 * The label comes from the snippet proc.ffmpegvideo.option_{value} in each case.
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
    'value' => $details['container'] ?? 'mp4',
    'id' => $this->id,
    'options' => $options(array_keys($proc::CONTAINERS))
]);

// codec
$this->backend->formField($this->converter, [
    'name' => 'details.codec',
    'value' => $details['codec'] ?? 'h264',
    'id' => $this->id,
    'options' => $options(array_keys($proc::VIDEO_CODECS))
]);

// width
$this->backend->formField($this->converter, ['name' => 'details.width', 'value' => $details['width'] ?? '', 'id' => $this->id]);

// height
$this->backend->formField($this->converter, ['name' => 'details.height', 'value' => $details['height'] ?? '', 'id' => $this->id]);

// crop
$colDef = $this->converter->getSchema()['details.crop'];
$colDef['display_type'] = 'radio';
$this->backend->formField($this->converter, [
    'name' => 'details.crop',
    'value' => $details['crop'] ?? '',
    'id' => $this->id,
    'colDef' => $colDef,
    'options' => [
        0 => $this->cms->snippet("proc.{$prefix}.label_crop_no"),
        1 => $this->cms->snippet("proc.{$prefix}.label_crop_yes"),
    ]
]);

// prevent_upscaling
$this->backend->formField($this->converter, [
    'name' => 'details.prevent_upscaling',
    'value' => $details['prevent_upscaling'] ?? '',
    'id' => $this->id,
    'options' => [1 => $this->cms->snippet("proc.{$prefix}.label_prevent_upscaling")]
]);

// quality_mode
$this->backend->formField($this->converter, [
    'name' => 'details.quality_mode',
    'value' => $details['quality_mode'] ?? 'crf',
    'id' => $this->id,
    'options' => $options(['crf', 'bitrate'])
]);

// quality (CRF)
$this->backend->formField($this->converter, ['name' => 'details.quality', 'value' => $details['quality'] ?? '', 'id' => $this->id]);

// bitrate
$this->backend->formField($this->converter, ['name' => 'details.bitrate', 'value' => $details['bitrate'] ?? '', 'id' => $this->id]);

// preset
$this->backend->formField($this->converter, [
    'name' => 'details.preset',
    'value' => $details['preset'] ?? 'medium',
    'id' => $this->id,
    'options' => $options($proc::PRESETS)
]);

// max_fps
$this->backend->formField($this->converter, ['name' => 'details.max_fps', 'value' => $details['max_fps'] ?? '', 'id' => $this->id]);

// start_time
$this->backend->formField($this->converter, ['name' => 'details.start_time', 'value' => $details['start_time'] ?? '', 'id' => $this->id]);

// duration
$this->backend->formField($this->converter, ['name' => 'details.duration', 'value' => $details['duration'] ?? '', 'id' => $this->id]);

// faststart
$this->backend->formField($this->converter, [
    'name' => 'details.faststart',
    'value' => $details['faststart'] ?? '',
    'id' => $this->id,
    'options' => [1 => $this->cms->snippet("proc.{$prefix}.label_faststart")]
]);

// compatible_pix_mode
$this->backend->formField($this->converter, [
    'name' => 'details.compatible_pix_mode',
    'value' => $details['compatible_pix_mode'] ?? '',
    'id' => $this->id,
    'options' => [1 => $this->cms->snippet("proc.{$prefix}.label_compatible_pix_mode")]
]);

// deinterlace
$this->backend->formField($this->converter, [
    'name' => 'details.deinterlace',
    'value' => $details['deinterlace'] ?? 'auto',
    'id' => $this->id,
    'options' => $options(array_keys($proc::DEINTERLACE))
]);

// auto_rotation
$this->backend->formField($this->converter, [
    'name' => 'details.auto_rotation',
    'value' => $details['auto_rotation'] ?? '',
    'id' => $this->id,
    'options' => [1 => $this->cms->snippet("proc.{$prefix}.label_auto_rotation")]
]);

// strip_meta
$this->backend->formField($this->converter, [
    'name' => 'details.strip_meta',
    'value' => $details['strip_meta'] ?? '',
    'id' => $this->id,
    'options' => [1 => $this->cms->snippet("proc.{$prefix}.label_strip_meta")]
]);

// audio_codec
$this->backend->formField($this->converter, [
    'name' => 'details.audio_codec',
    'value' => $details['audio_codec'] ?? 'aac',
    'id' => $this->id,
    'options' => $options(array_keys($proc::AUDIO_CODECS))
]);

// audio_bitrate
$this->backend->formField($this->converter, ['name' => 'details.audio_bitrate', 'value' => $details['audio_bitrate'] ?? '', 'id' => $this->id]);

// audio_channels
$this->backend->formField($this->converter, [
    'name' => 'details.audio_channels',
    'value' => $details['audio_channels'] ?? 'keep',
    'id' => $this->id,
    'options' => $options(array_keys($proc::AUDIO_CHANNELS))
]);

// audio_normalisation
$this->backend->formField($this->converter, [
    'name' => 'details.audio_normalisation',
    'value' => $details['audio_normalisation'] ?? '',
    'id' => $this->id,
    'options' => [1 => $this->cms->snippet("proc.{$prefix}.label_audio_normalisation")]
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
