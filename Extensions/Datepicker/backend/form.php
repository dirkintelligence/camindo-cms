<?php
/**
 * Optionen der Extension Datepicker in der Feld-Eingabemaske (13 §3.3).
 *
 * Eingebunden von FieldBackend::_extensionOptionsHtml() mit
 *     $backend  Camindo\Core\Backend      Vorlagen
 *     $cms      Camindo\Core\Cms          Snippets
 *     $options  array                     bisherige Werte (details.extension_options)
 *     $name     string                    Namenspräfix der Eingaben: data[details][extension_options]
 */
$mode = (string)($options['mode'] ?? 'auto');
$modeOptions = [];
foreach (['auto', 'datetime', 'date'] as $value) {
    $modeOptions[$value] = $cms->snippet('ext.datepicker.option_mode_' . $value);
}
echo $backend->template('edit_form_block', [
    '{label}' => $cms->snippet('ext.datepicker.option_mode'),
    '{class}' => '',
    '{field}' => $backend->template('ext.datepicker.field_mode', ['{name}' => $name, '{options}' => $backend->options($modeOptions, $mode)]),
]);
foreach (['buttons' => 1, 'week_numbers' => 0] as $option => $default) {
    $checked = (int)($options[$option] ?? $default) === 1 ? ' checked' : '';
    echo $backend->template('edit_form_block', [
        '{label}' => $cms->snippet('ext.datepicker.option_' . $option),
        '{class}' => '',
        '{field}' => $backend->template('ext.datepicker.field_switch', ['{name}' => $name, '{option}' => $option, '{checked}' => $checked]),
    ]);
}
