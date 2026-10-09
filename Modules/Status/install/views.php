<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews, z. B. der
// Assistent des Moduls Ai mit ui__open): Script => Beschreibung (englisch, für das
// Sprachmodell) und erlaubte Parameter ('int', 'string' oder Liste erlaubter Werte).
// Nur Scripte, die beim Öffnen nichts ändern - keine Aktionen, keine ajax-Scripte.
return [
    'status_list.php' => [
        'description' => 'Record status values (e.g. online, draft) with their meaning for the websites.',
    ],
    'status_info.php' => [
        'description' => 'Detail view of a status.',
        'params'      => ['id' => 'int'],
    ],
    'status_edit.php' => [
        'description' => 'Edit form of a status. function=create: new status; copy / delete with id.',
        'params'      => ['id' => 'int', 'function' => ['create', 'copy', 'delete']],
    ],
];
