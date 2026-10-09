<?php
// Core-Extension HTMLVisual: HTML in der Vorschau bearbeiten (eigener Editor, keine
// Fremdbibliothek) für Textareas der Typen text und chars - Quelltext über die
// Schaltfläche <>; Werkzeugleiste, Höhe und ein Stylesheet aus
// var/extensions/htmlvisual/ je Feld einstellbar. Dieselbe Oberfläche wie
// MarkdownVisual, das Feld enthält aber HTML (nur die unterstützten Elemente).
// Ausgabe im Template: (CMS:TEXT feld entitydecode=false lbrconvert=false).
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
