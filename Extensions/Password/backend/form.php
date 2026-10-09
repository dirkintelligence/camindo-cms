<?php
/**
 * Optionen der Extension Password in der Feld-Eingabemaske (13 §3.3).
 *
 * Eingebunden von FieldBackend::_extensionOptionsHtml() mit
 *     $backend  Camindo\Core\Backend      Vorlagen
 *     $cms      Camindo\Core\Cms          Snippets
 *     $options  array                     bisherige Werte (details.extension_options)
 *     $name     string                    Namenspräfix der Eingaben: data[details][extension_options]
 *
 * Jede Regel einzeln überschreibbar; leer = Vorgabe aus security.password_policy
 * (als Platzhalter angezeigt). Dieselben Werte prüft der Server beim Speichern
 * (Camindo\Core\PasswordPolicy::rulesFor()).
 */
use Camindo\Core\PasswordPolicy;

$defaults = $GLOBALS['app']->get(PasswordPolicy::class)->rules();
foreach (['min_length', 'min_special', 'min_upper', 'min_lower', 'min_digit'] as $rule) {
    $value = (string)($options[$rule] ?? '');
    echo $backend->template('edit_form_block', [
        '{label}' => $cms->snippet('ext.password.option_' . $rule),
        '{class}' => '',
        '{field}' => $backend->template('ext.password.field_rule', [
            '{name}' => $name,
            '{rule}' => $rule,
            '{value}' => $backend->escape($value),
            '{default}' => (string)(int)$defaults[$rule],
        ]),
    ]);
}

$value = (string)($options['upper_not_first'] ?? '');
$choices = [
    ''  => $cms->snippet('ext.password.option_default') . ' (' . $cms->snippet($defaults['upper_not_first'] ? 'ext.password.option_yes' : 'ext.password.option_no') . ')',
    '1' => $cms->snippet('ext.password.option_yes'),
    '0' => $cms->snippet('ext.password.option_no'),
];
echo $backend->template('edit_form_block', [
    '{label}' => $cms->snippet('ext.password.option_upper_not_first'),
    '{class}' => '',
    '{field}' => $backend->template('ext.password.field_upper_not_first', [
        '{name}' => $name,
        '{options}' => $backend->options($choices, $value),
    ]),
]);
