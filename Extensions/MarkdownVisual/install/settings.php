<?php
// Core-Extension MarkdownVisual: Markdown in der Vorschau bearbeiten (eigener Editor,
// keine Fremdbibliothek) für Textareas der Typen text und chars - Quelltext über die
// Schaltfläche <>; Werkzeugleiste, Höhe und ein Stylesheet aus
// var/extensions/markdownvisual/ je Feld einstellbar. Ausgabe im Template:
// (CMS:TEXT feld markdown=true) bzw. Camindo\Core\Markdown.
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
