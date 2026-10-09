<?php
// Core extension MarkdownVisual: edit Markdown in the preview (own editor,
// no third-party library) for textareas of the types text and chars - source via the
// <> button; toolbar, height and a stylesheet from
// var/extensions/markdownvisual/ can be set per field. Output in the template:
// (CMS:TEXT feld markdown=true) or Camindo\Core\Markdown.
return [
    'version'          => '9.0.0',
    'type'             => 'editor',
    'label'            => '#ext.markdownvisual.label',
    'description'      => '#ext.markdownvisual.description',
    'display_types'    => ['textarea'],
    'field_types'      => ['text', 'chars'],
    'options_provider' => 'Camindo\\Core\\MarkdownVisualOptions',
    'assets' => [
        'js'  => ['js/markdownvisual.ext.js'],
        'css' => ['css/markdownvisual.ext.css'],
    ],
    'requires' => ['core' => '^9.0'],
];
