<?php
// Core-Extension Datepicker: Kalender-Auswahl (vanillajs-datepicker) neben einem
// Textfeld der Typen datetime und timestampl - Monatsnamen, Wochenbeginn und
// Datumsformat nach der Locale des Admins (docs/developers/13-component-management.md, 3.7)
return [
    'version'       => '9.0.0',
    'type'          => 'picker',
    'label'         => '#ext.datepicker.label',
    'description'   => '#ext.datepicker.description',
    'display_types' => ['text'],
    'field_types'   => ['datetime', 'timestampl'],
    'assets' => [
        'js'  => ['js/datepicker-full.min.js', 'js/datepicker.locales.js', 'js/datepicker.ext.js'],
        'css' => ['css/datepicker-bs5.min.css', 'css/datepicker.ext.css'],
    ],
    'requires' => ['core' => '^9.0'],
];
