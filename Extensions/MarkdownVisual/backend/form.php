<?php
/**
 * Options of the extension MarkdownVisual in the field input mask (developer docs, chapter 08 (Components and extensions)).
 *
 * Included by FieldBackend::_extensionOptionsHtml() with
 *     $backend  Camindo\Core\Backend      templates
 *     $cms      Camindo\Core\Cms          snippets
 *     $options  array                     current values (details.extension_options)
 *     $name     string                    name prefix of the inputs: data[details][extension_options]
 *
 * Options: toolbar (full|basic|custom), toolbar_custom (buttons for custom),
 * min_height, max_height (px), stylesheet (file from var/extensions/markdownvisual/),
 * css_class (empty = c9-markdownvisual-{feldname}).
 */
// Notes appear as tooltip on the label (input_label_tt with data-bs-html): escaped twice,
// so that "<span>" in the snippet stays visible text
$note = fn(string $key, array $params = []) => htmlspecialchars($backend->escape(
    str_replace(array_keys($params), array_values($params), $cms->snippet('ext.markdownvisual.' . $key))
), ENT_QUOTES);
$label = fn(string $id, string $key, string $tooltip = '') => $backend->template($tooltip === '' ? 'input_label' : 'input_label_tt', [
    '{id}' => $id,
    '{label}' => $cms->snippet('ext.markdownvisual.' . $key),
    '{tooltip}' => $tooltip,
    '{class}' => '',
]);

// Toolbar
$toolbar = (string)($options['toolbar'] ?? 'full');
$toolbarOptions = [];
foreach (['full', 'basic', 'custom'] as $value) {
    $toolbarOptions[$value] = $cms->snippet('ext.markdownvisual.option_toolbar_' . $value);
}
$fieldId = 'c9-ext-' . bin2hex(random_bytes(4));
echo $backend->template('edit_form_block', [
    '{label}' => $label($fieldId, 'option_toolbar'),
    '{class}' => '',
    '{field}' => $backend->template('ext.markdownvisual.field_toolbar', [
        '{id}' => $fieldId,
        '{name}' => $name,
        '{options}' => $backend->options($toolbarOptions, $toolbar),
    ]),
]);
$fieldId = 'c9-ext-' . bin2hex(random_bytes(4));
echo $backend->template('edit_form_block', [
    '{label}' => $label($fieldId, 'option_toolbar_custom_list', $note('note_toolbar_custom')),
    '{class}' => '',
    '{field}' => $backend->template('ext.markdownvisual.field_toolbar_custom', [
        '{id}' => $fieldId,
        '{name}' => $name,
        '{value}' => $backend->escape((string)($options['toolbar_custom'] ?? '')),
    ]),
]);

// Height
$fieldId = 'c9-ext-' . bin2hex(random_bytes(4));
echo $backend->template('edit_form_block', [
    '{label}' => $label($fieldId, 'option_min_height'),
    '{class}' => '',
    '{field}' => $backend->template('ext.markdownvisual.field_min_height', [
        '{id}' => $fieldId,
        '{name}' => $name,
        '{value}' => (string)max(50, (int)($options['min_height'] ?? 200)),
    ]),
]);
$maxHeight = (int)($options['max_height'] ?? 0);
$fieldId = 'c9-ext-' . bin2hex(random_bytes(4));
echo $backend->template('edit_form_block', [
    '{label}' => $label($fieldId, 'option_max_height', $note('note_max_height')),
    '{class}' => '',
    '{field}' => $backend->template('ext.markdownvisual.field_max_height', [
        '{id}' => $fieldId,
        '{name}' => $name,
        '{value}' => $maxHeight > 0 ? (string)$maxHeight : '',
    ]),
]);

// Stylesheet from var/extensions/markdownvisual/
$dir = $backend->extensionVarDir('MarkdownVisual');
$dirShort = 'var/extensions/' . basename($dir) . '/';
$files = $backend->extensionVarFiles('MarkdownVisual');
$stylesheet = (string)($options['stylesheet'] ?? '');
$fileOptions = ['' => $cms->snippet('ext.markdownvisual.option_stylesheet_none')];
foreach ($files as $file) {
    $fileOptions[$file] = $file;
}
$fieldId = 'c9-ext-' . bin2hex(random_bytes(4));
echo $backend->template('edit_form_block', [
    '{label}' => $label($fieldId, 'option_stylesheet', $files
        ? $note('note_stylesheet', ['{dir}' => $dirShort, '{class}' => 'c9-markdownvisual-feldname'])
        : $note('note_stylesheet_empty', ['{dir}' => $dirShort])
    ),
    '{class}' => '',
    '{field}' => $backend->template('ext.markdownvisual.field_stylesheet', [
        '{id}' => $fieldId,
        '{name}' => $name,
        '{options}' => $backend->options($fileOptions, $stylesheet),
    ]),
]);

// CSS class
$fieldId = 'c9-ext-' . bin2hex(random_bytes(4));
echo $backend->template('edit_form_block', [
    '{label}' => $label($fieldId, 'option_css_class', $note('note_css_class')),
    '{class}' => '',
    '{field}' => $backend->template('ext.markdownvisual.field_css_class', [
        '{id}' => $fieldId,
        '{name}' => $name,
        '{value}' => $backend->escape((string)($options['css_class'] ?? '')),
    ]),
]);
