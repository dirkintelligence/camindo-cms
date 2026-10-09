<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews, z. B. der
// Assistent des Moduls Ai mit ui__open): Script => Beschreibung (englisch, für das
// Sprachmodell) und erlaubte Parameter ('int', 'string' oder Liste erlaubter Werte).
// Nur Scripte, die beim Öffnen nichts ändern - keine Aktionen, keine ajax-Scripte.
return [
    'index.php' => [
        'description' => 'Start page of the backend: available modules and the widgets (e.g. log files, last changes, messages).',
    ],
    'info.php' => [
        'description' => 'About camindo CMS: installed version and, for the superuser, the result of the update check.',
    ],
    'bgproc_list.php' => [
        'description' => 'Own background processes (batch processing, exports, imports) with progress.',
    ],
];
