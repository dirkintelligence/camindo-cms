<?php
/** 
 * Wir befinden uns innerhalb der Datei Core/Modules/Converter/converter_edit.php
 * @var Camindo\Core\Modules\Converter\Converter $this->converter
 * @var Camindo\Core\Config $this->config
 * @var Camindo\Core\Cms $this->cms
 * @var Camindo\Core\Backend $this->backend
 * @var int $id
 * @var array $data
 */

// Installiertes ImageMagick: veraltete Stände sind ungepatcht gegen die bekannten
// Lücken in den Delegates und Binärformaten - deshalb hier sichtbar markieren.
// Siehe developer_docs/18-imagemagick.md
$versionState = $this->converter->getProcessor()->versionState();
$versionHtml = htmlspecialchars(
    $versionState['version'] ?? $this->cms->snippet('proc.magick.label_version_unknown')
);
if ($versionState['snippet'] !== '') {
    $versionHtml .= ' ' . $this->backend->template('note_warning', [
        '{text}' => htmlspecialchars(str_replace(
            '{value}',
            \Camindo\Core\Processors\Magick\Magick::MIN_VERSION,
            $this->cms->snippet($versionState['snippet'])
        ))
    ]);
}
$this->backend->infoStatic('#proc.magick.label_version', $versionHtml, ['monospace']);

// Eigene Sicherheits-Policy (MagickPolicy): was security.magick_allow freigibt, und für
// SVG/PDF in den Medientypen dieses Konverters, ob sie wirklich laufen - gesperrt, von
// der Policy des Servers verhindert (z. B. PDF unter Debian) oder in Ordnung.
// Siehe developer_docs/18-imagemagick.md, 2.2.2
$processor = $this->converter->getProcessor();
$allowed = $processor->policy()->allowed();
$policyHtml = htmlspecialchars(str_replace('{value}',
    $allowed ? strtoupper(implode(', ', $allowed)) : $this->cms->snippet('proc.magick.policy_none'),
    $this->cms->snippet('proc.magick.policy_summary')));
// Die Datei entsteht beim ersten Aufruf - hier auch, damit die Anzeige stimmt und ein
// nicht beschreibbares var/config/ auffällt, bevor die erste Umwandlung scheitert
if (!$processor->policy()->apply()) {
    $policyHtml .= '<br>' . $this->backend->template('note_warning', [
        '{text}' => htmlspecialchars($this->cms->snippet('proc.magick.error_policy_file'))
    ]);
}
foreach (\Camindo\Core\Processors\Magick\MagickPolicy::FORMAT_MIME as $format => $mime) {
    if (!\Camindo\Core\Processors\Magick\MagickPolicy::mediaTypesCover(
        $mime, $format, (string)($data['media_types_incl'] ?? ''), (string)($data['media_types_excl'] ?? ''))) continue;
    $label = strtoupper($format);
    if (!in_array($format, $allowed, true)) {
        $text = str_replace('{value}', $label, $this->cms->snippet('proc.magick.policy_blocked'));
        $tpl = 'note_warning';
    }
    else {
        $probe = $processor->probeFormat($format);
        $text = $probe['ok']
            ? str_replace('{value}', $label, $this->cms->snippet('proc.magick.policy_ok'))
            : str_replace(['{value}', '{error}'], [$label, $probe['error']], $this->cms->snippet('proc.magick.policy_failed'));
        $tpl = $probe['ok'] ? '' : 'note_warning';
    }
    $policyHtml .= '<br>' . ($tpl === '' ? htmlspecialchars($text)
        : $this->backend->template($tpl, ['{text}' => htmlspecialchars($text)]));
}
$this->backend->infoStatic('#proc.magick.label_policy', $policyHtml);

$this->backend->infoAdd($this->converter, 'details.width', $data['details']['width'] ?? 0);
$this->backend->infoAdd($this->converter, 'details.height', $data['details']['height'] ?? 0);
$this->backend->infoAdd($this->converter, 'details.crop', ($data['details']['crop'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.quality', (int) ($data['details']['quality'] ?? 0) . '%');
$this->backend->infoAdd($this->converter, 'details.indexed_colors', $data['details']['indexed_colors'] ?? 0, ['notempty']);
$this->backend->infoAdd($this->converter, 'details.alpha', ($data['details']['alpha'] ?? 0) ? 'yes' : 'no');
$this->backend->infoAdd($this->converter, 'details.bgcolor', htmlspecialchars($data['details']['bgcolor'] ?? 'ffffff'), ['monospace']);

// Expertenmodus
$this->backend->infoAdd($this->converter, 'details.parameters1', htmlspecialchars($data['details']['parameters1'] ?? ''), ['notempty', 'monospace']);
$this->backend->infoAdd($this->converter, 'details.parameters2', htmlspecialchars($data['details']['parameters2'] ?? ''), ['notempty', 'monospace']);
