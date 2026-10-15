<?php
/**
 * Options of the extension Datepicker in the field input mask (developer docs, chapter 08 (Components and extensions)).
 *
 * Included by FieldBackend::_extensionOptionsHtml() with
 *     $backend  Camindo\Core\Backend      templates
 *     $cms      Camindo\Core\Cms          snippets
 *     $options  array                     current values (details.extension_options)
 *     $name     string                    name prefix of the inputs: data[details][extension_options]
 */
$mode = (string)($options['mode'] ?? 'auto');
$modeOptions = [];
foreach (['auto', 'datetime', 'date'] as $value) {
    $modeOptions[$value] = $cms->snippet('ext.datepicker.option_mode_' . $value);
}
$fieldId = 'c9-ext-' . bin2hex(random_bytes(4));
echo $backend->template('edit_form_block', [
    '{label}' => $backend->template('input_label', ['{id}' => $fieldId, '{label}' => $cms->snippet('ext.datepicker.option_mode'), '{class}' => '']),
    '{class}' => '',
    '{field}' => $backend->template('ext.datepicker.field_mode', ['{id}' => $fieldId, '{name}' => $name, '{options}' => $backend->options($modeOptions, $mode)]),
]);
foreach (['buttons' => 1, 'week_numbers' => 0] as $option => $default) {
    $checked = (int)($options[$option] ?? $default) === 1 ? ' checked' : '';
    $fieldId = 'c9-ext-' . bin2hex(random_bytes(4));
    echo $backend->template('edit_form_block', [
        '{label}' => $backend->template('input_label', ['{id}' => $fieldId, '{label}' => $cms->snippet('ext.datepicker.option_' . $option), '{class}' => '']),
        '{class}' => '',
        '{field}' => $backend->template('ext.datepicker.field_switch', ['{id}' => $fieldId, '{name}' => $name, '{option}' => $option, '{checked}' => $checked]),
    ]);
}
