<?php
// Core extension Calendar: native date/time picker (<input type="date|datetime-local|time">)
// next to a text field of the types datetime, timestampl and time
// (developer docs, chapter 08 (Components and extensions))
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
