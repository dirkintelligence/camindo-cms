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
$note = fn(string $key, array $params = []) => $backend->template('form-text', [
    '{text}' => $backend->escape(str_replace(array_keys($params), array_values($params), $cms->snippet('ext.markdownvisual.' . $key))),
]);

// Toolbar
$toolbar = (string)($options['toolbar'] ?? 'full');
$toolbarOptions = [];
foreach (['full', 'basic', 'custom'] as $value) {
    $toolbarOptions[$value] = $cms->snippet('ext.markdownvisual.option_toolbar_' . $value);
}
echo $backend->template('edit_form_block', [
    '{label}' => $cms->snippet('ext.markdownvisual.option_toolbar'),
    '{class}' => '',
    '{field}' => $backend->template('ext.markdownvisual.field_toolbar', [
        '{name}' => $name,
        '{options}' => $backend->options($toolbarOptions, $toolbar),
    ]),
]);
echo $backend->template('edit_form_block', [
    '{label}' => $cms->snippet('ext.markdownvisual.option_toolbar_custom_list'),
    '{class}' => '',
    '{field}' => $backend->template('ext.markdownvisual.field_toolbar_custom', [
        '{name}' => $name,
        '{value}' => $backend->escape((string)($options['toolbar_custom'] ?? '')),
        '{note}' => $note('note_toolbar_custom'),
    ]),
]);

// Height
echo $backend->template('edit_form_block', [
    '{label}' => $cms->snippet('ext.markdownvisual.option_min_height'),
    '{class}' => '',
    '{field}' => $backend->template('ext.markdownvisual.field_min_height', [
        '{name}' => $name,
        '{value}' => (string)max(50, (int)($options['min_height'] ?? 200)),
    ]),
]);
$maxHeight = (int)($options['max_height'] ?? 0);
echo $backend->template('edit_form_block', [
    '{label}' => $cms->snippet('ext.markdownvisual.option_max_height'),
    '{class}' => '',
    '{field}' => $backend->template('ext.markdownvisual.field_max_height', [
        '{name}' => $name,
        '{value}' => $maxHeight > 0 ? (string)$maxHeight : '',
        '{note}' => $note('note_max_height'),
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
echo $backend->template('edit_form_block', [
    '{label}' => $cms->snippet('ext.markdownvisual.option_stylesheet'),
    '{class}' => '',
    '{field}' => $backend->template('ext.markdownvisual.field_stylesheet', [
        '{name}' => $name,
        '{options}' => $backend->options($fileOptions, $stylesheet),
        '{note}' => $files
            ? $note('note_stylesheet', ['{dir}' => $dirShort, '{class}' => 'c9-markdownvisual-feldname'])
            : $note('note_stylesheet_empty', ['{dir}' => $dirShort]),
    ]),
]);

// CSS class
echo $backend->template('edit_form_block', [
    '{label}' => $cms->snippet('ext.markdownvisual.option_css_class'),
    '{class}' => '',
    '{field}' => $backend->template('ext.markdownvisual.field_css_class', [
        '{name}' => $name,
        '{value}' => $backend->escape((string)($options['css_class'] ?? '')),
        '{note}' => $note('note_css_class'),
    ]),
]);
