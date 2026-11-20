<?php
/**
 * We are inside the file Core/Modules/Converter/converter_edit.php
 * @var Camindo\Core\Modules\Converter\Converter $this->converter
 * @var Camindo\Core\Config $this->config
 * @var Camindo\Core\Cms $this->cms
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

// Note below the media types: only PDF, target formats PNG, JPEG, TIFF
$this->backend->formReplace('{processor_note}', $this->backend->template('alert_warning', [
    '{text}' => htmlspecialchars($this->cms->snippet('proc.poppler.note_media_types'))
]));

$details = (array)($this->data['details'] ?? []);
// labels, options and defaults from the schema (Poppler::hookDefinition())
$this->backend->formField($this->converter, ['name' => 'details.page', 'value' => $details['page'] ?? 1, 'id' => $this->id]);
$this->backend->formField($this->converter, ['name' => 'details.width', 'value' => $details['width'] ?? '', 'id' => $this->id]);
$this->backend->formField($this->converter, ['name' => 'details.height', 'value' => $details['height'] ?? '', 'id' => $this->id]);
$this->backend->formField($this->converter, ['name' => 'details.box', 'value' => $details['box'] ?? 'crop', 'id' => $this->id]);
$this->backend->formField($this->converter, [
    'name' => 'details.annotations',
    'value' => $details['annotations'] ?? 1,
    'id' => $this->id,
    'options' => [1 => $this->cms->snippet('proc.poppler.label_annotations_yes')]
]);
$this->backend->formField($this->converter, ['name' => 'details.quality', 'value' => $details['quality'] ?? '', 'id' => $this->id]);
