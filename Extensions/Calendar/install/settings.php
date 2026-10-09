<?php
// Core-Extension Calendar: native Datums-/Zeitauswahl (<input type="date|datetime-local|time">)
// neben einem Textfeld der Typen datetime, timestampl und time
// (docs/developers/13-component-management.md, 3.7)
return [
    'version'       => '9.0.0',
    'type'          => 'picker',
    'label'         => '#ext.calendar.label',
    'description'   => '#ext.calendar.description',
    'display_types' => ['text'],
    'field_types'   => ['datetime', 'timestampl', 'time'],
    'assets' => [
        'js'  => ['js/calendar.ext.js'],
        'css' => ['css/calendar.ext.css'],
    ],
    'requires' => ['core' => '^9.0'],
];
