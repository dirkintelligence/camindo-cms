<?php
// Core extension Datepicker: calendar picker (vanillajs-datepicker) next to a
// text field of the types datetime and timestampl - month names, first day of the week and
// date format per the locale of the administrator (developer docs, chapter 08 (Components and extensions))
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
