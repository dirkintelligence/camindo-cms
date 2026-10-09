<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews, z. B. der
// Assistent des Moduls Ai mit ui__open): Script => Beschreibung (englisch, für das
// Sprachmodell) und erlaubte Parameter ('int', 'string' oder Liste erlaubter Werte).
// Nur Scripte, die beim Öffnen nichts ändern - keine Aktionen, keine ajax-Scripte.
return [
    'index.php' => [
        'description' => 'Snippets: the text sets of the instances (UI texts of the websites in all languages).',
    ],
    'set_table.php' => [
        'description' => 'All snippets of one instance as a table with a language selection; search, add, edit.',
        'params'      => ['instance_id' => 'int'],
    ],
    'snippet_edit.php' => [
        'description' => 'Edit one snippet of an instance in all its languages (key; without key: new snippet).',
        'params'      => ['instance_id' => 'int', 'key' => 'string'],
    ],
];
