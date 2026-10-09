<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews, z. B. der
// Assistent des Moduls Ai mit ui__open): Script => Beschreibung (englisch, für das
// Sprachmodell) und erlaubte Parameter ('int', 'string' oder Liste erlaubter Werte).
// Nur Scripte, die beim Öffnen nichts ändern - keine Aktionen, keine ajax-Scripte.
return [
    'language_list.php' => [
        'description' => 'Content languages of the instances (code, label, backend language, fallback).',
    ],
    'language_info.php' => [
        'description' => 'Detail view of a content language.',
        'params'      => ['id' => 'int'],
    ],
    'language_edit.php' => [
        'description' => 'Edit form of a content language. function=create: new language; copy / delete with id.',
        'params'      => ['id' => 'int', 'function' => ['create', 'copy', 'delete']],
    ],
];
