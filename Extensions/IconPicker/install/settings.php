<?php
// Core extension IconPicker: picker for a Material icon next to a text field -
// preview of the icon in the field suffix, selection window with categories and
// search via the tags of the Google search index (developer docs, chapter 08 (Components and extensions)).
// Default for area.icon (Modules/Area/Area.php).
return [
    'version'       => '9.0.0',
    'type'          => 'picker',
    'label'         => '#ext.iconpicker.label',
    'description'   => '#ext.iconpicker.description',
    'display_types' => ['text'],
    'assets' => [
        'js'  => ['js/iconpicker.ext.js'],
        'css' => ['css/iconpicker.ext.css'],
    ],
    'requires' => ['core' => '^9.0'],
];
