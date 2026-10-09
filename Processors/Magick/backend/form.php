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

// Note below the media types: ImageMagick does not read SVG and PDF itself,
// but via librsvg or Ghostscript - therefore they are not preset.
// Siehe developer_docs/18-imagemagick.md
$this->backend->formReplace('{processor_note}', $this->backend->template('alert_warning', [
    '{text}' => htmlspecialchars($this->cms->snippet('proc.magick.note_media_types'))
]));

$details = (array)($this->data['details'] ?? []);
// width
$this->backend->formField($this->converter, ['name' => 'details.width', 'value' => $details['width'] ?? '', 'id' => $this->id]);

// height
$this->backend->formField($this->converter, ['name' => 'details.height', 'value' => $details['height'] ?? '', 'id' => $this->id]);

// Size: adjustment (crop), condition, unit, maximum pixels - options and
// labels from the schema (Magick::hookDefinition())
$this->backend->formField($this->converter, ['name' => 'details.crop', 'value' => $details['crop'] ?? 0, 'id' => $this->id]);
$this->backend->formField($this->converter, ['name' => 'details.resize_when', 'value' => $details['resize_when'] ?? 'always', 'id' => $this->id]);
$this->backend->formField($this->converter, ['name' => 'details.unit', 'value' => $details['unit'] ?? 'px', 'id' => $this->id]);
$this->backend->formField($this->converter, ['name' => 'details.max_pixels', 'value' => $details['max_pixels'] ?? '', 'id' => $this->id]);

// quality
$this->backend->formField($this->converter, ['name' => 'details.quality', 'value' => $details['quality'] ?? '', 'id' => $this->id]);

// indexed_colors
$this->backend->formField($this->converter, ['name' => 'details.indexed_colors', 'value' => $details['indexed_colors'] ?: 'TrueColor', 'id' => $this->id]);

// alpha
$this->backend->formField($this->converter, [
    'name' => 'details.alpha',
    'value' => $details['alpha'] ?? '',
    'id' => $this->id,
    'options' => [1 => $this->cms->snippet('proc.imagegd.label_alpha_yes')]
]);

// bgcolor
$this->backend->formField($this->converter, ['name' => 'details.bgcolor', 'value' => $details['bgcolor'] ?? '', 'id' => $this->id]);


// Expertenmodus
$this->backend->formField($this->converter, ['name' => 'details.parameters1', 'value' => $details['parameters1'] ?? '', 'id' => $this->id]);
$this->backend->formField($this->converter, ['name' => 'details.parameters2', 'value' => $details['parameters2'] ?? '', 'id' => $this->id]);
