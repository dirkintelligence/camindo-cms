<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews, z. B. der
// Assistent des Moduls Ai mit ui__open): Script => Beschreibung (englisch, für das
// Sprachmodell) und erlaubte Parameter ('int', 'string' oder Liste erlaubter Werte).
// Nur Scripte, die beim Öffnen nichts ändern - keine Aktionen, keine ajax-Scripte.
return [
    'converter_list.php' => [
        'description' => 'Media converters: rules that create thumbnails and other variants of uploaded images and videos.',
    ],
    'converter_search.php' => [
        'description' => 'Search form for converters.',
    ],
    'converter_create.php' => [
        'description' => 'First step of a new converter: choice of the processor (e.g. ImageMagick, GD, ffmpeg).',
    ],
    'converter_info.php' => [
        'description' => 'Detail view of a converter; tab=usage: which fields use it.',
        'params'      => ['id' => 'int', 'tab' => ['usage']],
    ],
    'converter_edit.php' => [
        'description' => 'Edit form of a converter. function=copy / delete with id.',
        'params'      => ['id' => 'int', 'function' => ['copy', 'delete']],
    ],
];
