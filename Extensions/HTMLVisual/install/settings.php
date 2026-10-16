<?php
// Core extension HTMLVisual: edit HTML in the preview (own editor, no
// third-party library) for textareas of the types text and chars - source via the
// <> button; toolbar, height and a stylesheet from
// var/extensions/htmlvisual/ can be set per field. Same UI as
// MarkdownVisual, but the field contains HTML (only the supported elements; from the
// source also class/style/id/title and <img>).
// Output in the template: (CMS:TEXT feld entitydecode=false lbrconvert=false).
return [
    'version'          => '9.0.0',
    'type'             => 'editor',
    'label'            => '#ext.htmlvisual.label',
    'description'      => '#ext.htmlvisual.description',
    'display_types'    => ['textarea'],
    'field_types'      => ['text', 'chars'],
    'options_provider' => 'Camindo\\Core\\HtmlVisualOptions',
    'assets' => [
        'js'  => ['js/htmlvisual.ext.js'],
        'css' => ['css/htmlvisual.ext.css'],
    ],
    'requires' => ['core' => '^9.0'],
];
