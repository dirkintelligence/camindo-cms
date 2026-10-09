<?php
/**
 * Optionen der Extension Calendar in der Feld-Eingabemaske (13 §3.3).
 *
 * Eingebunden von FieldBackend::_extensionOptionsHtml() mit
 *     $backend  Camindo\Core\Backend      Vorlagen
 *     $cms      Camindo\Core\Cms          Snippets
 *     $options  array                     bisherige Werte (details.extension_options)
 *     $name     string                    Namenspräfix der Eingaben: data[details][extension_options]
 */
$mode = (string)($options['mode'] ?? 'auto');
$step = (int)($options['step'] ?? 1);
$modeOptions = [];
foreach (['auto', 'datetime', 'date', 'time'] as $value) {
    $modeOptions[$value] = $cms->snippet('ext.calendar.option_mode_' . $value);
}
echo $backend->template('edit_form_block', [
    '{label}' => $cms->snippet('ext.calendar.option_mode'),
    '{class}' => '',
    '{field}' => $backend->template('ext.calendar.field_mode', ['{name}' => $name, '{options}' => $backend->options($modeOptions, $mode)]),
]);
echo $backend->template('edit_form_block', [
    '{label}' => $cms->snippet('ext.calendar.option_step'),
    '{class}' => '',
    '{field}' => $backend->template('ext.calendar.field_step', ['{name}' => $name, '{value}' => (string)max(1, $step)]),
]);
