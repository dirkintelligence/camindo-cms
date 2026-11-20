<?php
/**
 * We are inside the file Core/Modules/Converter/converter_edit.php
 * @var Camindo\Core\Modules\Converter\Converter $this->converter
 * @var Camindo\Core\Config $this->config
 * @var Camindo\Core\Cms $this->cms
 * @var Camindo\Core\Backend $this->backend
 * @var int $id
 * @var array $data
 */

/** @var \Camindo\Core\Processors\Poppler\Poppler $processor */
$processor = $this->converter->getProcessor();

// pdftoppm and pdfinfo (pdfinfo is optional: page count and exact fitting)
$version = $processor->getVersion();
$versionHtml = $version !== null ? htmlspecialchars($version) : $this->backend->template('note_warning', [
    '{text}' => htmlspecialchars($this->cms->snippet('proc.poppler.error_binary_missing'))
]);
$this->backend->infoStatic('#proc.poppler.label_version', $versionHtml, ['monospace']);
if ($version !== null) {
    $this->backend->infoStatic('#proc.poppler.label_pdfinfo', $processor->infoBinary() !== null
        ? htmlspecialchars($this->cms->snippet('proc.poppler.pdfinfo_found'))
        : $this->backend->template('note_warning', ['{text}' => htmlspecialchars($this->cms->snippet('proc.poppler.pdfinfo_missing'))]));
}

$details = (array)($data['details'] ?? []);
$this->backend->infoAdd($this->converter, 'details.page', (int)($details['page'] ?? 1));
$this->backend->infoAdd($this->converter, 'details.width', $details['width'] ?? 0, ['notempty']);
$this->backend->infoAdd($this->converter, 'details.height', $details['height'] ?? 0, ['notempty']);
$this->backend->infoAdd($this->converter, 'details.box', htmlspecialchars($this->cms->snippet(
    ($details['box'] ?? 'crop') === 'media' ? 'proc.poppler.option_box_media' : 'proc.poppler.option_box_crop')));
$this->backend->infoAdd($this->converter, 'details.annotations', ($details['annotations'] ?? 1) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.quality', (int)($details['quality'] ?? 85) . '%');
