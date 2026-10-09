<?php
// Core-Extension IconPicker: Auswahl eines Material-Icons neben einem Textfeld -
// Vorschau des Icons im Suffix des Feldes, Auswahlfenster mit Kategorien und
// Suche über die Tags des Google-Suchindex (docs/developers/13-component-management.md, 3.7).
// Standard für area.icon (Modules/Area/Area.php).
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
