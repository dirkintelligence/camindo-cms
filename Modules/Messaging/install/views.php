<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews, z. B. der
// Assistent des Moduls Ai mit ui__open): Script => Beschreibung (englisch, für das
// Sprachmodell) und erlaubte Parameter ('int', 'string' oder Liste erlaubter Werte).
// Nur Scripte, die beim Öffnen nichts ändern - keine Aktionen, keine ajax-Scripte.
return [
    'message_list.php' => [
        'description' => 'Internal messages of the administrator: inbox, or tab=outbox for sent messages.',
        'params'      => ['tab' => ['outbox']],
    ],
    'message_info.php' => [
        'description' => 'One internal message.',
        'params'      => ['id' => 'int'],
    ],
    'message_edit.php' => [
        'description' => 'Write an internal message: function=create (optional record_id: message about a record), forward with id.',
        'params'      => ['id' => 'int', 'record_id' => 'int', 'function' => ['create', 'forward']],
    ],
];
