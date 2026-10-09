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
        0 => $this->cms->snippet('proc.imagegd.label_crop_no'),
        1 => $this->cms->snippet('proc.imagegd.label_crop_yes'),
    ]
]);

// Size: condition, unit, maximum pixels - options and labels from
// the schema (ImageGd::hookDefinition())
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

