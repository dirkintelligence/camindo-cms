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
// Formular und Engine zwangsläufig beieinander, auch wenn später ein Format
// dazukommt oder wegfällt.
$proc = \Camindo\Core\Processors\FFmpegImage\FFmpegImage::class;

/**
 * Baut aus einer Werteliste die Optionen für ein Auswahlfeld.
 * Die Beschriftung kommt jeweils aus dem Snippet proc.ffmpegimage.option_{wert}.
 */
$options = function (array $values) use ($prefix): array {
    $result = [];
    foreach ($values as $value) {
        $result[$value] = $this->cms->snippet("proc.{$prefix}.option_" . str_replace('-', '_', $value));
    }
    return $result;
};

// format
$this->backend->formField($this->converter, [
    'name' => 'details.format',
    'value' => $details['format'] ?? 'jpg',
    'id' => $this->id,
    'options' => $options(array_keys($proc::FORMATS))
]);

// quality
$this->backend->formField($this->converter, ['name' => 'details.quality', 'value' => $details['quality'] ?? '', 'id' => $this->id]);

// frame_mode
$this->backend->formField($this->converter, [
    'name' => 'details.frame_mode',
    'value' => $details['frame_mode'] ?? 'percent',
    'id' => $this->id,
    'options' => $options($proc::FRAME_MODES)
]);

// frame_time
$this->backend->formField($this->converter, ['name' => 'details.frame_time', 'value' => $details['frame_time'] ?? '', 'id' => $this->id]);

// frame_percent
$this->backend->formField($this->converter, ['name' => 'details.frame_percent', 'value' => $details['frame_percent'] ?? '', 'id' => $this->id]);

// scan_frames
$this->backend->formField($this->converter, ['name' => 'details.scan_frames', 'value' => $details['scan_frames'] ?? '', 'id' => $this->id]);

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

// sharpen
$this->backend->formField($this->converter, [
    'name' => 'details.sharpen',
    'value' => $details['sharpen'] ?? '',
    'id' => $this->id,
    'options' => [1 => $this->cms->snippet("proc.{$prefix}.label_sharpen")]
]);

// strip_meta
$this->backend->formField($this->converter, [
    'name' => 'details.strip_meta',
    'value' => $details['strip_meta'] ?? '',
    'id' => $this->id,
    'options' => [1 => $this->cms->snippet("proc.{$prefix}.label_strip_meta")]
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
