<?php
/**
 * Options of the extension Password in the field input mask (developer docs, chapter 08 (Components and extensions)).
 *
 * Included by FieldBackend::_extensionOptionsHtml() with
 *     $backend  Camindo\Core\Backend      templates
 *     $cms      Camindo\Core\Cms          snippets
 *     $options  array                     current values (details.extension_options)
 *     $name     string                    name prefix of the inputs: data[details][extension_options]
 *
 * Each rule can be overridden individually; empty = default from security.password_policy
 * (shown as placeholder). The server checks the same values when saving
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
