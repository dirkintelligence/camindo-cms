<?php
/**
 * Options of the extension Calendar in the field input mask (developer docs, chapter 08 (Components and extensions)).
 *
 * Included by FieldBackend::_extensionOptionsHtml() with
 *     $backend  Camindo\Core\Backend      templates
 *     $cms      Camindo\Core\Cms          snippets
 *     $options  array                     current values (details.extension_options)
 *     $name     string                    name prefix of the inputs: data[details][extension_options]
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
