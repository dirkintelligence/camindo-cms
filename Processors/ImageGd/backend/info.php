<?php
/** 
 * We are inside the file Core/Modules/Converter/converter_edit.php
 * @var Camindo\Core\Modules\Converter\Converter $this->converter
 * @var Camindo\Core\Config $this->config
 * @var Camindo\Core\Backend $this->backend
 * @var int $id
 * @var array $data
 */

$this->backend->infoAdd($this->converter, 'details.width', $data['details']['width'] ?? 0);
$this->backend->infoAdd($this->converter, 'details.height', $data['details']['height'] ?? 0);
$this->backend->infoAdd($this->converter, 'details.crop', ($data['details']['crop'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.quality', (int) ($data['details']['quality'] ?? 0) . '%');
$this->backend->infoAdd($this->converter, 'details.indexed_colors', $data['details']['indexed_colors'] ?? 0, ['notempty']);
$this->backend->infoAdd($this->converter, 'details.alpha', ($data['details']['alpha'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.bgcolor', htmlspecialchars($data['details']['bgcolor'] ?? 'ffffff'), ['monospace']);
